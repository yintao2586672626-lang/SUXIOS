<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Runs one explicit, read-only PMS collection for the selected hotel.
 *
 * Browser session material remains in the isolated local browser sandbox.
 * This service receives only a sanitized collector receipt and then verifies
 * the saved capture through the normal database readback boundary.
 */
final class PmsRealtimeSyncService
{
    private const LOCAL_CDP_URL = 'http://127.0.0.1:9223';
    private const MAX_PROCESS_OUTPUT_BYTES = 2_000_000;
    private const SANDBOX_RECOVERY_REASONS = [
        'capture_session_expired',
        'capture_login_required',
        'capture_session_unverified',
        'capture_page_missing',
    ];

    private string $projectRoot;
    private string $phpBinary;
    private Closure $bindingResolver;
    private Closure $receiptLoader;
    private Closure $cdpProbe;
    private Closure $processRunner;
    private Closure $loginHandoffRunner;
    private Closure $captureReader;
    private Closure $captureValidator;
    private Closure $clock;

    public function __construct(
        ?callable $bindingResolver = null,
        ?callable $receiptLoader = null,
        ?callable $cdpProbe = null,
        ?callable $processRunner = null,
        ?callable $captureReader = null,
        ?callable $captureValidator = null,
        ?callable $clock = null,
        ?string $projectRoot = null,
        ?string $phpBinary = null,
        ?callable $loginHandoffRunner = null
    ) {
        $this->projectRoot = $projectRoot ?: dirname(__DIR__, 2);
        $this->phpBinary = $phpBinary ?: PHP_BINARY;
        $this->bindingResolver = Closure::fromCallable($bindingResolver ?: static fn(
            int $tenantId,
            int $hotelId,
            int $userId,
            string $targetDate
        ): array => (new HotelPmsBindingService())->status(
            $tenantId,
            $hotelId,
            $userId,
            $targetDate
        ));
        $this->receiptLoader = Closure::fromCallable(
            $receiptLoader ?: fn(int $hotelId, int $userId): array =>
                $this->loadLocalRunnerReceipt($hotelId, $userId)
        );
        $this->cdpProbe = Closure::fromCallable(
            $cdpProbe ?: fn(string $url): bool => $this->probeLocalCdp($url)
        );
        $this->processRunner = Closure::fromCallable(
            $processRunner ?: fn(array $command): array => $this->runProcess($command)
        );
        $this->loginHandoffRunner = Closure::fromCallable(
            $loginHandoffRunner ?: fn(string $sandboxId): array =>
                $this->launchLoginHandoff($sandboxId)
        );
        $this->captureReader = Closure::fromCallable($captureReader ?: static fn(
            int $tenantId,
            int $hotelId,
            string $targetDate,
            int $captureId = 0
        ): array => $captureId > 0
            ? (new DingdandaoOperatingTargetCaptureService())->read(
                $tenantId,
                $hotelId,
                $captureId
            )
            : (new DingdandaoOperatingTargetCaptureService())->latest(
                $tenantId,
                $hotelId,
                $targetDate
            ));
        $this->captureValidator = Closure::fromCallable(
            $captureValidator ?: static fn(
                array $capture,
                int $tenantId,
                int $hotelId,
                string $targetDate,
                string $expectedSourceScope
            ): bool => (
                (new CollectionResultContractService())
                    ->validateDingdandaoCaptureClaim(
                        $capture,
                        [
                            'tenant_id' => $tenantId,
                            'system_hotel_id' => $hotelId,
                            'business_date' => $targetDate,
                            'source_scope' => $expectedSourceScope,
                        ]
                    )['allowed'] ?? false
            ) === true
        );
        $this->clock = Closure::fromCallable(
            $clock ?: static fn(): DateTimeImmutable => new DateTimeImmutable(
                'now',
                new DateTimeZone('Asia/Shanghai')
            )
        );
    }

    /** @return array<string,mixed> */
    public function sync(
        int $tenantId,
        int $hotelId,
        int $userId,
        string $targetDate
    ): array {
        $today = ($this->clock)()->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
        $parsedTargetDate = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $targetDate,
            new DateTimeZone('Asia/Shanghai')
        );
        if (!$parsedTargetDate instanceof DateTimeImmutable
            || $parsedTargetDate->format('Y-m-d') !== $targetDate
        ) {
            return $this->blocked(
                'pms_target_date_invalid',
                'PMS 业务日期格式无效，本次未启动采集。',
                $targetDate,
                hotelId: $hotelId
            );
        }
        if ($targetDate > $today) {
            return $this->blocked(
                'pms_target_date_in_future',
                '未来业务日尚不能形成 PMS 经营事实，本次未启动采集。',
                $targetDate,
                hotelId: $hotelId
            );
        }
        $historicalCollection = $targetDate < $today;
        $expectedSourceScope = $historicalCollection
            ? DingdandaoOperatingTargetCaptureService::HISTORICAL_SOURCE_SCOPE
            : DingdandaoOperatingTargetCaptureService::SOURCE_SCOPE;

        $binding = ($this->bindingResolver)($tenantId, $hotelId, $userId, $targetDate);
        if (($binding['binding_status'] ?? '') !== 'configured') {
            $blocker = is_array($binding['blockers'][0] ?? null) ? $binding['blockers'][0] : [];
            return $this->blocked(
                (string)($blocker['code'] ?? 'hotel_pms_unconfigured'),
                (string)($blocker['message'] ?? '当前门店尚未配置唯一 PMS。'),
                $targetDate,
                hotelId: $hotelId
            );
        }

        $provider = (string)($binding['selected_provider'] ?? '');
        if ($provider !== HotelPmsBindingService::PROVIDER_DINGDANDAO) {
            return $this->blocked(
                'pms_live_provider_not_supported',
                '当前 PMS 暂未接入页面实时同步，请读取已保存快照。',
                $targetDate,
                false,
                $provider,
                hotelId: $hotelId
            );
        }

        $sandboxId = $this->sandboxId($hotelId, $userId);
        if ($sandboxId === '') {
            return $this->blocked(
                'pms_live_sandbox_not_configured',
                '当前门店未找到隔离的订单来了采集会话，不能连接任意浏览器代采。',
                $targetDate,
                true,
                $provider,
                hotelId: $hotelId
            );
        }
        if (!(($this->cdpProbe)(self::LOCAL_CDP_URL))) {
            $loginHandoff = $this->loginHandoff($hotelId, $targetDate, $sandboxId);
            return $this->blocked(
                'pms_live_session_unavailable',
                '订单来了专用采集会话未连接，请在本机已绑定的独立 Google Chrome 窗口登录并保持开启，然后再同步；不要复制 Cookie 或换设备代采。',
                $targetDate,
                true,
                $provider,
                $loginHandoff,
                $hotelId
            );
        }

        $runner = $this->projectRoot . DIRECTORY_SEPARATOR
            . 'scripts' . DIRECTORY_SEPARATOR . 'run_dingdandao_local_collection.php';
        if (!is_file($runner) || !is_file($this->phpBinary)) {
            return $this->blocked(
                'pms_live_runner_unavailable',
                '实时 PMS 采集程序不可用，本次未读取外部数据。',
                $targetDate,
                false,
                $provider,
                hotelId: $hotelId
            );
        }

        $process = ($this->processRunner)([
            $this->phpBinary,
            $runner,
            '--hotel-id=' . $hotelId,
            '--owner-user-id=' . $userId,
            '--target-date=' . $targetDate,
            '--cdp-url=' . self::LOCAL_CDP_URL,
            '--sandbox-id=' . $sandboxId,
            '--collection-mode=operating_indicators',
            '--require-sandbox',
        ]);
        $payload = $this->lastJsonObject(
            (string)($process['stdout'] ?? ''),
            (string)($process['stderr'] ?? '')
        );
        $exitCode = (int)($process['exit_code'] ?? 1);
        if ($exitCode !== 0 || ($payload['status'] ?? '') !== 'saved_and_readback_verified') {
            $reason = $this->reason((string)($payload['reason'] ?? 'pms_live_collection_failed'));
            $savedCaptureId = (int)($payload['capture_id'] ?? 0);
            if (($payload['collection_success'] ?? false) === true
                && ($payload['business_data_persisted'] ?? false) === true
                && $savedCaptureId > 0
            ) {
                $savedCapture = ($this->captureReader)(
                    $tenantId,
                    $hotelId,
                    $targetDate,
                    $savedCaptureId
                );
                if ($this->captureMatches(
                    $savedCapture,
                    $savedCaptureId,
                    $tenantId,
                    $hotelId,
                    $targetDate,
                    $expectedSourceScope
                )) {
                    return [
                        'status' => 'partial',
                        'system_hotel_id' => $hotelId,
                        'provider' => $provider,
                        'target_date' => $targetDate,
                        'capture_id' => $savedCaptureId,
                        'captured_at' => (string)($savedCapture['captured_at'] ?? ''),
                        'identity_status' => 'matched',
                        'quality_status' => 'verified',
                        'readback_status' => 'readback_verified',
                        'source_scope' => $expectedSourceScope,
                        'live_read' => !$historicalCollection,
                        'historical_read' => $historicalCollection,
                        'saved' => true,
                        'readback_verified' => true,
                        'downstream_blocker_code' => $reason,
                        'failure_stage' => $this->reason(
                            (string)($payload['failure_stage'] ?? 'downstream')
                        ),
                        'requires_login' => false,
                        'message' => $historicalCollection
                            ? '订单来了历史单日事实已保存并通过数据库回读，但后续同步未完成；已保留本次真实采集，不回退为旧快照。'
                            : '订单来了实时事实已保存并通过数据库回读，但后续同步未完成；已保留本次真实采集，不回退为旧快照。',
                    ];
                }
            }
            $requiresLogin = $this->requiresLogin($reason);
            $loginHandoff = $requiresLogin
                ? $this->loginHandoff($hotelId, $targetDate, $sandboxId)
                : [];
            return $this->blocked(
                $reason,
                $this->reasonMessage($reason),
                $targetDate,
                $requiresLogin,
                $provider,
                $loginHandoff,
                $hotelId
            );
        }

        $captureId = (int)($payload['capture_id'] ?? 0);
        $capture = ($this->captureReader)(
            $tenantId,
            $hotelId,
            $targetDate,
            $captureId
        );
        if (!$this->captureMatches(
            $capture,
            $captureId,
            $tenantId,
            $hotelId,
            $targetDate,
            $expectedSourceScope
        )) {
            return $this->blocked(
                'pms_live_readback_not_verified',
                'PMS 已返回数据，但保存回读未完整通过，本次不标记为实时同步成功。',
                $targetDate,
                false,
                $provider,
                hotelId: $hotelId
            );
        }

        return [
            'status' => 'synced',
            'system_hotel_id' => $hotelId,
            'provider' => $provider,
            'target_date' => $targetDate,
            'capture_id' => $captureId,
            'captured_at' => (string)($capture['captured_at'] ?? ''),
            'identity_status' => 'matched',
            'quality_status' => 'verified',
            'readback_status' => 'readback_verified',
            'source_scope' => $expectedSourceScope,
            'live_read' => !$historicalCollection,
            'historical_read' => $historicalCollection,
            'saved' => true,
            'readback_verified' => true,
            'message' => $historicalCollection
                ? '已按所选历史业务日从订单来了结构化接口补采，并完成保存与数据库回读。'
                : '已从订单来了实时读取，并完成保存与数据库回读。',
        ];
    }

    /** @param array<string,mixed> $capture */
    private function captureMatches(
        array $capture,
        int $captureId,
        int $tenantId,
        int $hotelId,
        string $targetDate,
        string $expectedSourceScope
    ): bool {
        return $captureId > 0
            && (int)($capture['id'] ?? 0) === $captureId
            && (int)($capture['hotel_id'] ?? 0) === $hotelId
            && (string)($capture['business_date'] ?? '') === $targetDate
            && (string)($capture['source_scope'] ?? '') === $expectedSourceScope
            && (string)($capture['identity_status'] ?? '') === 'matched'
            && (string)($capture['quality_status'] ?? '') === 'verified'
            && (string)($capture['readback_status'] ?? '') === 'readback_verified'
            && (($this->captureValidator)(
                $capture,
                $tenantId,
                $hotelId,
                $targetDate,
                $expectedSourceScope
            ) === true);
    }

    private function sandboxId(int $hotelId, int $userId): string
    {
        $receipt = ($this->receiptLoader)($hotelId, $userId);
        if (!$this->trustedSandboxBindingReceipt($receipt, $hotelId, $userId)) {
            return '';
        }
        $value = trim((string)($receipt['sandbox_id'] ?? ''));
        $configured = trim((string)(getenv('SUXIOS_DINGDANDAO_LOCAL_SANDBOX_ID') ?: ''));
        if ($configured !== ''
            && (!$this->validSandboxId($configured) || !hash_equals($value, $configured))
        ) {
            return '';
        }
        return $value;
    }

    private function validSandboxId(string $value): bool
    {
        return preg_match('/^sbx_[A-Za-z0-9_-]{8,64}$/D', $value) === 1;
    }

    /** @return array<string,mixed> */
    private function loadLocalRunnerReceipt(int $hotelId, int $userId): array
    {
        if ($hotelId <= 0 || $userId <= 0) {
            return [];
        }
        $root = $this->projectRoot . DIRECTORY_SEPARATOR
            . 'runtime' . DIRECTORY_SEPARATOR
            . 'dingdandao_local_scheduler';
        $paths = [
            $root . DIRECTORY_SEPARATOR . 'hotel_' . $hotelId
                . DIRECTORY_SEPARATOR . 'user_' . $userId
                . DIRECTORY_SEPARATOR . 'operating_indicators'
                . DIRECTORY_SEPARATOR . 'latest.json',
            $root . DIRECTORY_SEPARATOR . 'hotel_' . $hotelId
                . DIRECTORY_SEPARATOR . 'user_' . $userId
                . DIRECTORY_SEPARATOR . 'full_diagnostic'
                . DIRECTORY_SEPARATOR . 'latest.json',
        ];
        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }
            $raw = @file_get_contents($path);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded)
                && $this->trustedSandboxBindingReceipt($decoded, $hotelId, $userId)
            ) {
                return $decoded;
            }
        }
        return [];
    }

    /** @param array<string,mixed> $receipt */
    private function trustedSandboxBindingReceipt(
        array $receipt,
        int $hotelId,
        int $userId
    ): bool {
        if (!$this->trustedSandboxReceiptScope($receipt, $hotelId, $userId)) {
            return false;
        }
        if ($this->trustedCollectionSuccessReceipt($receipt, $hotelId, $userId)) {
            return true;
        }

        return (string)($receipt['status'] ?? '') === 'blocked'
            && in_array(
                (string)($receipt['reason'] ?? ''),
                self::SANDBOX_RECOVERY_REASONS,
                true
            )
            && ($receipt['collection_success'] ?? null) === false
            && ($receipt['business_data_persisted'] ?? null) === false
            && (int)($receipt['capture_id'] ?? -1) === 0;
    }

    /** @param array<string,mixed> $receipt */
    private function trustedCollectionSuccessReceipt(
        array $receipt,
        int $hotelId,
        int $userId
    ): bool {
        return $this->trustedSandboxReceiptScope($receipt, $hotelId, $userId)
            && in_array((string)($receipt['status'] ?? ''), ['success', 'partial'], true)
            && ($receipt['collection_success'] ?? false) === true
            && ($receipt['business_data_persisted'] ?? false) === true
            && (int)($receipt['capture_id'] ?? 0) > 0
            && (string)($receipt['identity_status'] ?? '') === 'matched'
            && (string)($receipt['reconciliation_status'] ?? '') === 'matched'
            && (string)($receipt['quality_status'] ?? '') === 'verified'
            && (string)($receipt['readback_status'] ?? '') === 'readback_verified';
    }

    /** @param array<string,mixed> $receipt */
    private function trustedSandboxReceiptScope(
        array $receipt,
        int $hotelId,
        int $userId
    ): bool {
        $scopeMismatchCodes = $receipt['scope_mismatch_codes'] ?? null;
        $targetDate = trim((string)($receipt['target_date'] ?? ''));
        $sandboxId = trim((string)($receipt['sandbox_id'] ?? ''));

        return (int)($receipt['schema_version'] ?? 0) === 1
            && trim((string)($receipt['run_id'] ?? '')) !== ''
            && (string)($receipt['source'] ?? '') === 'dingdandao'
            && (string)($receipt['execution_mode'] ?? '')
                === 'local_shared_browser_sandbox'
            && (int)($receipt['hotel_id'] ?? 0) === $hotelId
            && (int)($receipt['owner_user_id'] ?? 0) === $userId
            && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $targetDate) === 1
            && $this->validSandboxId($sandboxId)
            && (string)($receipt['sandbox_selection'] ?? '') === 'explicit_marker'
            && (string)($receipt['cdp_scope'] ?? '') === 'loopback'
            && (string)($receipt['browser_host_status'] ?? '') === 'ready'
            && (
                !array_key_exists('automatic_device_substitution', $receipt)
                || $receipt['automatic_device_substitution'] === false
            )
            && is_array($scopeMismatchCodes)
            && $scopeMismatchCodes === [];
    }

    private function probeLocalCdp(string $url): bool
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 2,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url . '/json/version', false, $context);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $webSocketUrl = is_array($decoded)
            ? trim((string)($decoded['webSocketDebuggerUrl'] ?? ''))
            : '';
        return preg_match('#^ws://(?:127\.0\.0\.1|localhost):9223/#D', $webSocketUrl) === 1;
    }

    /** @return array<string,mixed> */
    private function launchLoginHandoff(string $sandboxId): array
    {
        $launcher = $this->projectRoot . DIRECTORY_SEPARATOR
            . 'scripts' . DIRECTORY_SEPARATOR . 'open_local_browser_sandbox.ps1';
        if (!is_file($launcher)) {
            return [
                'status' => 'blocked',
                'reason' => 'pms_login_handoff_launcher_missing',
            ];
        }
        $systemRoot = rtrim(
            (string)(getenv('SystemRoot') ?: 'C:\\Windows'),
            '\\/'
        );
        $candidate = $systemRoot . DIRECTORY_SEPARATOR . 'System32'
            . DIRECTORY_SEPARATOR . 'WindowsPowerShell'
            . DIRECTORY_SEPARATOR . 'v1.0'
            . DIRECTORY_SEPARATOR . 'powershell.exe';
        $powershell = is_file($candidate) ? $candidate : 'powershell.exe';
        $process = $this->runProcess([
            $powershell,
            '-NoLogo',
            '-NoProfile',
            '-NonInteractive',
            '-ExecutionPolicy',
            'Bypass',
            '-File',
            $launcher,
            '-ProjectRoot',
            $this->projectRoot,
            '-Port',
            '9223',
            '-Platform',
            'dingdandao',
            '-SandboxId',
            $sandboxId,
            '-InteractiveLogin',
            '-SwitchMode',
        ]);
        $payload = $this->lastJsonObject(
            (string)($process['stdout'] ?? ''),
            (string)($process['stderr'] ?? '')
        );
        if ((int)($process['exit_code'] ?? 1) !== 0 || $payload === []) {
            return [
                'status' => 'blocked',
                'reason' => $this->reason(
                    (string)($payload['reason'] ?? 'pms_login_handoff_runner_failed')
                ),
            ];
        }
        return $payload;
    }

    /** @return array<string,mixed> */
    private function loginHandoff(
        int $hotelId,
        string $targetDate,
        string $sandboxId
    ): array {
        $base = [
            'system_hotel_id' => $hotelId,
            'target_date' => $targetDate,
            'sandbox_id' => $sandboxId,
            'browser_label' => '订单来了 PMS 专用 Google Chrome',
            'login_verified' => false,
            'codex_iab_is_execution_browser' => false,
            'recovery_device_policy' => 'same_bound_device_only',
            'automatic_device_substitution' => false,
            'profile_material_copied' => false,
            'session_material_exposed' => false,
        ];
        try {
            $payload = ($this->loginHandoffRunner)($sandboxId);
        } catch (\Throwable) {
            return [
                ...$base,
                'status' => 'unavailable',
                'failure_code' => 'pms_login_handoff_runner_failed',
            ];
        }
        if (!$this->trustedLoginHandoffReceipt($payload, $sandboxId)) {
            $failureCode = (string)($payload['status'] ?? '') === 'blocked'
                ? $this->reason(
                    (string)($payload['reason'] ?? 'pms_login_handoff_unavailable')
                )
                : 'pms_login_handoff_receipt_invalid';
            return [
                ...$base,
                'status' => 'unavailable',
                'failure_code' => $failureCode,
            ];
        }
        return [
            ...$base,
            'status' => 'ready',
            'dedicated_browser_status' => 'ready',
            'window_target_activated' => true,
            'window_target_reused' => (bool)$payload['window_target_reused'],
            'activated_target_scope' => (string)$payload['activated_target_scope'],
            'window_foreground_requested' =>
                (bool)$payload['window_foreground_requested'],
            'next_action' => 'complete_login_then_retry_verified_collection',
        ];
    }

    /** @param array<string,mixed> $payload */
    private function trustedLoginHandoffReceipt(
        array $payload,
        string $sandboxId
    ): bool {
        return $this->validSandboxId($sandboxId)
            && (string)($payload['status'] ?? '') === 'handoff_ready'
            && (string)($payload['cdp_status'] ?? '') === 'ready'
            && (string)($payload['cdp_scope'] ?? '') === 'loopback_only'
            && (int)($payload['cdp_port'] ?? 0) === 9223
            && is_bool($payload['browser_started'] ?? null)
            && ($payload['headless'] ?? null) === false
            && is_bool($payload['mode_switch_performed'] ?? null)
            && (string)($payload['platform'] ?? '') === 'dingdandao'
            && is_string($payload['sandbox_id'] ?? null)
            && hash_equals($sandboxId, (string)$payload['sandbox_id'])
            && (string)($payload['isolation'] ?? '') === 'process_profile'
            && (string)($payload['start_url'] ?? '')
                === 'https://www.dingdandao.com/pmsManage/report/pro/dataCenter/accommodationData'
            && (string)($payload['session_status'] ?? '') === 'login_required'
            && ($payload['login_required'] ?? null) === true
            && ($payload['window_target_activated'] ?? null) === true
            && is_bool($payload['window_target_reused'] ?? null)
            && in_array(
                (string)($payload['activated_target_scope'] ?? ''),
                ['exact_start', 'pms_manage', 'login_entry'],
                true
            )
            && is_bool($payload['window_foreground_requested'] ?? null)
            && (string)($payload['next_action'] ?? '')
                === 'complete_login_in_bound_browser_then_retry'
            && ($payload['automatic_device_substitution'] ?? null) === false
            && ($payload['profile_material_copied'] ?? null) === false
            && ($payload['browser_process_exposed'] ?? null) === false
            && ($payload['raw_response_exposed'] ?? null) === false
            && ($payload['session_material_exposed'] ?? null) === false
            && ($payload['sensitive_values_exposed'] ?? null) === false
            && !$this->containsSensitiveHandoffField($payload);
    }

    /** @param array<string,mixed> $payload */
    private function containsSensitiveHandoffField(array $payload): bool
    {
        foreach ($payload as $key => $value) {
            $normalizedKey = strtolower((string)$key);
            if (preg_match(
                '/(?:cookie|password|secret|authorization|access_token|refresh_token|profile_path|process_id|target_id|browser_context|websocket)/',
                $normalizedKey
            ) === 1) {
                return true;
            }
            if (is_array($value) && $this->containsSensitiveHandoffField($value)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<int,string> $command @return array{exit_code:int,stdout:string,stderr:string} */
    private function runProcess(array $command): array
    {
        $pipes = [];
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->projectRoot,
            null,
            ['bypass_shell' => true, 'suppress_errors' => true]
        );
        if (!is_resource($process)) {
            throw new RuntimeException('pms_live_runner_start_failed');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1], self::MAX_PROCESS_OUTPUT_BYTES + 1);
        $stderr = stream_get_contents($pipes[2], 8192);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if (!is_string($stdout)
            || !is_string($stderr)
            || strlen($stdout) > self::MAX_PROCESS_OUTPUT_BYTES
        ) {
            throw new RuntimeException('pms_live_runner_output_invalid');
        }
        return [
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    /** @return array<string,mixed> */
    private function lastJsonObject(string $stdout, string $stderr): array
    {
        foreach ([$stdout, $stderr] as $stream) {
            $lines = preg_split('/\R/', trim($stream)) ?: [];
            for ($index = count($lines) - 1; $index >= 0; $index--) {
                $decoded = json_decode(trim((string)$lines[$index]), true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }
        return [];
    }

    private function reason(string $reason): string
    {
        $normalized = preg_replace('/[^a-z0-9_-]+/', '_', strtolower(trim($reason)));
        return is_string($normalized) && $normalized !== ''
            ? substr($normalized, 0, 100)
            : 'pms_live_collection_failed';
    }

    private function requiresLogin(string $reason): bool
    {
        return in_array($reason, [
            'pms_live_session_unavailable',
            'capture_session_expired',
            'capture_login_required',
            'capture_page_missing',
            'capture_session_unverified',
        ], true);
    }

    private function reasonMessage(string $reason): string
    {
        return match ($reason) {
            'capture_session_expired' => '订单来了专用采集会话已过期，请在本机已绑定的独立 Google Chrome 窗口登录并保持开启，然后再同步；不要复制 Cookie 或换设备代采。',
            'capture_login_required',
            'capture_session_unverified' => '订单来了专用采集会话尚未完成登录验证，请在本机已绑定的独立 Google Chrome 窗口登录并保持开启，然后再同步。',
            'capture_page_missing' => '专用采集会话中未找到订单来了经营数据页，请在同一台已绑定设备的独立 Google Chrome 窗口打开订单来了，然后再同步。',
            'dingdandao_local_collection_already_running' => '订单来了实时同步正在执行，请稍后再试。',
            'dingdandao_hotel_identity_mismatch',
            'dingdandao_local_provider_identity_incomplete' => '订单来了门店身份与当前宿析OS门店不一致，已阻止写入。',
            default => '订单来了实时同步未完成，本次没有把旧快照标记为新数据。',
        };
    }

    /** @return array<string,mixed> */
    private function blocked(
        string $code,
        string $message,
        string $targetDate,
        bool $requiresLogin = false,
        string $provider = HotelPmsBindingService::PROVIDER_DINGDANDAO,
        array $loginHandoff = [],
        int $hotelId = 0
    ): array {
        $result = [
            'status' => 'blocked',
            'system_hotel_id' => $hotelId,
            'provider' => $provider,
            'target_date' => $targetDate,
            'live_read' => false,
            'saved' => false,
            'readback_verified' => false,
            'blocker_code' => $code,
            'requires_login' => $requiresLogin,
            'recovery_action' => $requiresLogin
                ? 'login_in_bound_local_sandbox'
                : null,
            'recovery_device_policy' => $requiresLogin
                ? 'same_bound_device_only'
                : null,
            'automatic_device_substitution' => false,
            'message' => $message,
        ];
        if ($requiresLogin && $loginHandoff !== []) {
            $result['login_handoff'] = $loginHandoff;
        }
        return $result;
    }
}
