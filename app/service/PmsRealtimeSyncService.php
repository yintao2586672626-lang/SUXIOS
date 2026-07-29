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

    private string $projectRoot;
    private string $phpBinary;
    private Closure $bindingResolver;
    private Closure $receiptLoader;
    private Closure $cdpProbe;
    private Closure $processRunner;
    private Closure $captureReader;
    private Closure $clock;

    public function __construct(
        ?callable $bindingResolver = null,
        ?callable $receiptLoader = null,
        ?callable $cdpProbe = null,
        ?callable $processRunner = null,
        ?callable $captureReader = null,
        ?callable $clock = null,
        ?string $projectRoot = null,
        ?string $phpBinary = null
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
            $receiptLoader ?: fn(): array => $this->loadLocalRunnerReceipt()
        );
        $this->cdpProbe = Closure::fromCallable(
            $cdpProbe ?: fn(string $url): bool => $this->probeLocalCdp($url)
        );
        $this->processRunner = Closure::fromCallable(
            $processRunner ?: fn(array $command): array => $this->runProcess($command)
        );
        $this->captureReader = Closure::fromCallable($captureReader ?: static fn(
            int $tenantId,
            int $hotelId,
            string $targetDate
        ): array => (new DingdandaoOperatingTargetCaptureService())->latest(
            $tenantId,
            $hotelId,
            $targetDate
        ));
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
        if ($targetDate !== $today) {
            return $this->blocked(
                'pms_live_today_only',
                '实时 PMS 只读取今天；历史日期只能读取已保存快照。',
                $targetDate
            );
        }

        $binding = ($this->bindingResolver)($tenantId, $hotelId, $userId, $targetDate);
        if (($binding['binding_status'] ?? '') !== 'configured') {
            $blocker = is_array($binding['blockers'][0] ?? null) ? $binding['blockers'][0] : [];
            return $this->blocked(
                (string)($blocker['code'] ?? 'hotel_pms_unconfigured'),
                (string)($blocker['message'] ?? '当前门店尚未配置唯一 PMS。'),
                $targetDate
            );
        }

        $provider = (string)($binding['selected_provider'] ?? '');
        if ($provider !== HotelPmsBindingService::PROVIDER_DINGDANDAO) {
            return $this->blocked(
                'pms_live_provider_not_supported',
                '当前 PMS 暂未接入页面实时同步，请读取已保存快照。',
                $targetDate,
                false,
                $provider
            );
        }

        $sandboxId = $this->sandboxId($hotelId, $userId);
        if ($sandboxId === '') {
            return $this->blocked(
                'pms_live_sandbox_not_configured',
                '当前门店未找到隔离的订单来了采集会话，不能连接任意浏览器代采。',
                $targetDate,
                true,
                $provider
            );
        }
        if (!(($this->cdpProbe)(self::LOCAL_CDP_URL))) {
            return $this->blocked(
                'pms_live_session_unavailable',
                '订单来了实时采集会话未连接，请重新登录后再同步。',
                $targetDate,
                true,
                $provider
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
                $provider
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
            '--require-sandbox',
        ]);
        $payload = $this->lastJsonObject(
            (string)($process['stdout'] ?? ''),
            (string)($process['stderr'] ?? '')
        );
        $exitCode = (int)($process['exit_code'] ?? 1);
        if ($exitCode !== 0 || ($payload['status'] ?? '') !== 'saved_and_readback_verified') {
            $reason = $this->reason((string)($payload['reason'] ?? 'pms_live_collection_failed'));
            return $this->blocked(
                $reason,
                $this->reasonMessage($reason),
                $targetDate,
                $this->requiresLogin($reason),
                $provider
            );
        }

        $capture = ($this->captureReader)($tenantId, $hotelId, $targetDate);
        $captureId = (int)($payload['capture_id'] ?? 0);
        if ($captureId <= 0
            || (int)($capture['id'] ?? 0) !== $captureId
            || (int)($capture['hotel_id'] ?? 0) !== $hotelId
            || (string)($capture['business_date'] ?? '') !== $targetDate
            || (string)($capture['identity_status'] ?? '') !== 'matched'
            || (string)($capture['quality_status'] ?? '') !== 'verified'
            || (string)($capture['readback_status'] ?? '') !== 'readback_verified'
        ) {
            return $this->blocked(
                'pms_live_readback_not_verified',
                'PMS 已返回数据，但保存回读未完整通过，本次不标记为实时同步成功。',
                $targetDate,
                false,
                $provider
            );
        }

        return [
            'status' => 'synced',
            'provider' => $provider,
            'target_date' => $targetDate,
            'capture_id' => $captureId,
            'captured_at' => (string)($capture['captured_at'] ?? ''),
            'identity_status' => 'matched',
            'quality_status' => 'verified',
            'readback_status' => 'readback_verified',
            'live_read' => true,
            'saved' => true,
            'readback_verified' => true,
            'message' => '已从订单来了实时读取，并完成保存与数据库回读。',
        ];
    }

    private function sandboxId(int $hotelId, int $userId): string
    {
        $configured = trim((string)(getenv('SUXIOS_DINGDANDAO_LOCAL_SANDBOX_ID') ?: ''));
        if ($configured !== '') {
            return $this->validSandboxId($configured) ? $configured : '';
        }

        $receipt = ($this->receiptLoader)();
        if (($receipt['execution_mode'] ?? '') !== 'local_shared_browser_sandbox'
            || (int)($receipt['hotel_id'] ?? 0) !== $hotelId
            || (int)($receipt['owner_user_id'] ?? 0) !== $userId
        ) {
            return '';
        }
        $value = trim((string)($receipt['sandbox_id'] ?? ''));
        return $this->validSandboxId($value) ? $value : '';
    }

    private function validSandboxId(string $value): bool
    {
        return preg_match('/^sbx_[A-Za-z0-9_-]{8,64}$/D', $value) === 1;
    }

    /** @return array<string,mixed> */
    private function loadLocalRunnerReceipt(): array
    {
        $path = $this->projectRoot . DIRECTORY_SEPARATOR
            . 'runtime' . DIRECTORY_SEPARATOR
            . 'dingdandao_local_scheduler' . DIRECTORY_SEPARATOR
            . 'latest.json';
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : [];
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
            'capture_session_expired' => '订单来了登录会话已过期，请重新登录后再同步。',
            'capture_login_required',
            'capture_session_unverified' => '订单来了尚未完成登录验证，请登录后再同步。',
            'capture_page_missing' => '实时采集会话中未找到订单来了经营数据页，请重新连接后再同步。',
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
        string $provider = HotelPmsBindingService::PROVIDER_DINGDANDAO
    ): array {
        return [
            'status' => 'blocked',
            'provider' => $provider,
            'target_date' => $targetDate,
            'live_read' => false,
            'saved' => false,
            'readback_verified' => false,
            'blocker_code' => $code,
            'requires_login' => $requiresLogin,
            'message' => $message,
        ];
    }
}
