<?php
declare(strict_types=1);

namespace app\service;

use Closure;

/**
 * Secret-free, hotel-80-only Windows Task Scheduler receipt/control bridge.
 *
 * The only write actions are disabling catch-up on and then enabling the
 * already existing, exact-scope task. It never creates, replaces or starts a
 * scheduled task and never runs OTA.
 */
final class WindowsOtaDispatcherControlService
{
    public const SCHEMA_VERSION = 'suxios_windows_ota_dispatcher.v1';
    public const PILOT_HOTEL_ID = 80;
    public const TASK_NAME = 'SUXIOS OTA Dispatcher H80';

    private const RECEIPT_PREFIX = 'SUXIOS_OTA_WINDOWS_SCHEDULER=';
    private const SOURCE_IDS = [25, 68];
    private const PLATFORMS = ['ctrip', 'meituan'];
    private const PROCESS_TIMEOUT_SECONDS = 25;

    private Closure $runner;
    private string $projectRoot;

    public function __construct(?callable $runner = null, ?string $projectRoot = null)
    {
        $this->projectRoot = rtrim(
            $projectRoot !== null && trim($projectRoot) !== '' ? $projectRoot : dirname(__DIR__, 2),
            '/\\'
        );
        $this->runner = $runner !== null
            ? Closure::fromCallable($runner)
            : fn(string $mode, int $hotelId, string $expectedDigest): array => $this->runPowerShell(
                $mode,
                $hotelId,
                $expectedDigest
            );
    }

    /** @return array<string,mixed> */
    public function inspect(int $hotelId): array
    {
        return $this->execute('inspect', $hotelId, '');
    }

    /** @return array<string,mixed> */
    public function enable(int $hotelId, string $expectedContractDigest): array
    {
        $expectedContractDigest = strtolower(trim($expectedContractDigest));
        if (!preg_match('/^[a-f0-9]{64}$/', $expectedContractDigest)) {
            return $this->blockedReceipt($hotelId, 'scheduler_contract_digest_invalid', false);
        }

        return $this->execute('enable', $hotelId, $expectedContractDigest);
    }

    /** @return array<string,mixed> */
    private function execute(string $mode, int $hotelId, string $expectedDigest): array
    {
        if ($hotelId !== self::PILOT_HOTEL_ID) {
            return $this->blockedReceipt($hotelId, 'scheduler_hotel_scope_unsupported', false);
        }

        try {
            $result = ($this->runner)($mode, $hotelId, $expectedDigest);
            $receipt = isset($result['schema_version'])
                ? $result
                : $this->receiptFromProcessResult($result);
        } catch (\Throwable) {
            return $this->blockedReceipt($hotelId, 'scheduler_control_unavailable', false);
        }

        return $this->normalizeReceipt($receipt, $mode);
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function receiptFromProcessResult(array $result): array
    {
        $stdout = (string)($result['stdout'] ?? '');
        $matches = [];
        foreach (preg_split('/\R/', $stdout) ?: [] as $line) {
            $line = trim((string)$line);
            if (!str_starts_with($line, self::RECEIPT_PREFIX)) {
                continue;
            }
            $payload = substr($line, strlen(self::RECEIPT_PREFIX));
            $decoded = json_decode($payload, true);
            if (is_array($decoded)) {
                $matches[] = $decoded;
            }
        }

        if (count($matches) !== 1) {
            $unavailable = $this->blockedReceipt(
                self::PILOT_HOTEL_ID,
                'scheduler_receipt_unavailable',
                false
            );
            $unavailable['_process_exit_code'] = is_numeric($result['exit_code'] ?? null)
                ? (int)$result['exit_code']
                : -1;
            return $unavailable;
        }

        $matches[0]['_process_exit_code'] = is_numeric($result['exit_code'] ?? null)
            ? (int)$result['exit_code']
            : -1;
        return $matches[0];
    }

    /**
     * @param array<string,mixed> $receipt
     * @return array<string,mixed>
     */
    private function normalizeReceipt(array $receipt, string $mode): array
    {
        $rawReasonCode = $this->safeCode((string)($receipt['reason_code'] ?? ''));
        $processExitCode = is_numeric($receipt['_process_exit_code'] ?? null)
            ? (int)$receipt['_process_exit_code']
            : 0;
        if (in_array($rawReasonCode, ['scheduler_receipt_unavailable', 'scheduler_control_unavailable'], true)) {
            $unavailable = $this->blockedReceipt(self::PILOT_HOTEL_ID, $rawReasonCode, false);
            $unavailable['control_process_exit_code'] = $processExitCode;
            foreach (['enable_action_performed', 'settings_action_performed'] as $mutationKey) {
                if (is_bool($receipt[$mutationKey] ?? null)) {
                    $unavailable[$mutationKey] = $receipt[$mutationKey];
                }
            }
            return $unavailable;
        }

        $scope = is_array($receipt['scope'] ?? null) ? $receipt['scope'] : [];
        $sourceIds = array_values(array_map('intval', is_array($scope['source_ids'] ?? null)
            ? $scope['source_ids']
            : []));
        $platforms = array_values(array_map(
            static fn(mixed $platform): string => strtolower(trim((string)$platform)),
            is_array($scope['platforms'] ?? null) ? $scope['platforms'] : []
        ));
        $trigger = is_array($receipt['trigger'] ?? null) ? $receipt['trigger'] : [];
        $retryInterval = $this->safeDuration((string)($trigger['retry_interval'] ?? ''));
        $retryDuration = $this->safeDuration((string)($trigger['retry_duration'] ?? ''));
        $startBoundary = $this->safeTimestamp($trigger['start_boundary'] ?? null);
        $startBoundaryExact = false;
        if ($startBoundary !== null) {
            try {
                $boundaryDate = new \DateTimeImmutable($startBoundary);
                $startBoundaryExact = $boundaryDate->format('H:i:s') === '08:30:00'
                    && $boundaryDate->format('P') === '+08:00';
            } catch (\Throwable) {
                $startBoundaryExact = false;
            }
        }
        $digest = strtolower(trim((string)($receipt['contract_digest'] ?? '')));
        $safeContract = ($receipt['schema_version'] ?? null) === self::SCHEMA_VERSION
            && (int)($receipt['hotel_id'] ?? 0) === self::PILOT_HOTEL_ID
            && (string)($receipt['task_name'] ?? '') === self::TASK_NAME
            && (int)($scope['hotel_id'] ?? 0) === self::PILOT_HOTEL_ID
            && $sourceIds === self::SOURCE_IDS
            && $platforms === self::PLATFORMS
            && (string)($scope['mode'] ?? '') === 'Daily'
            && ($receipt['local_only'] ?? null) === true
            && ($receipt['production_ready'] ?? null) === false
            && is_bool($receipt['task_started'] ?? null)
            && is_bool($receipt['starts_task_immediately'] ?? null)
            && ($receipt['sensitive_values_exposed'] ?? null) === false
            && ($receipt['control_state_verified'] ?? null) === true
            && is_bool($receipt['catch_up_disabled'] ?? null)
            && is_bool($receipt['safe_enable_transition_required'] ?? null)
            && ($receipt['safe_enable_transition_required'] ?? null) === !(bool)$receipt['catch_up_disabled']
            && is_bool($receipt['task_state_active'] ?? null)
            && is_bool($receipt['enable_action_performed'] ?? null)
            && is_bool($receipt['settings_action_performed'] ?? null)
            && is_bool($receipt['last_run_unchanged'] ?? null);
        $scopeVerified = $safeContract
            && ($receipt['scope_verified'] ?? null) === true
            && ($receipt['action_verified'] ?? null) === true
            && ($receipt['trigger_verified'] ?? null) === true
            && ($receipt['principal_verified'] ?? null) === true
            && ($receipt['settings_verified'] ?? null) === true
            && (int)($trigger['count'] ?? 0) === 1
            && $startBoundaryExact
            && $retryInterval === 'PT14M'
            && $retryDuration === 'PT1H25M'
            && preg_match('/^[a-f0-9]{64}$/', $digest) === 1;
        $enabled = ($receipt['enabled'] ?? null) === true;
        $taskExists = ($receipt['task_exists'] ?? null) === true;
        $catchUpDisabled = ($receipt['catch_up_disabled'] ?? null) === true;
        $taskState = $this->safeText((string)($receipt['task_state'] ?? ''), 40);
        $taskStateActive = ($receipt['task_state_active'] ?? null) === true
            || in_array(strtolower($taskState), ['running', 'queued'], true);
        $taskStarted = ($receipt['task_started'] ?? null) === true;
        $startsTaskImmediately = ($receipt['starts_task_immediately'] ?? null) === true;
        $processExitVerified = $processExitCode === 0;
        $enableActionPerformed = ($receipt['enable_action_performed'] ?? null) === true;
        $enableReasonCode = (string)($receipt['reason_code'] ?? '');
        $enableReadbackVerified = $mode !== 'enable' || (
            $enabled
            && $catchUpDisabled
            && !$taskStateActive
            && !$taskStarted
            && !$startsTaskImmediately
            && $processExitVerified
            && ($receipt['last_run_unchanged'] ?? null) === true
            && (($enableActionPerformed && $enableReasonCode === 'scheduler_enabled_waiting_natural_run')
                || (!$enableActionPerformed && $enableReasonCode === 'scheduler_already_enabled_waiting_natural_run'))
        );

        $reasonCode = $rawReasonCode;
        if (!$safeContract || !$scopeVerified) {
            $reasonCode = 'scheduler_scope_mismatch';
        } elseif ($taskStarted || $startsTaskImmediately) {
            $reasonCode = 'scheduler_enable_triggered_unexpected_run';
        } elseif (!$processExitVerified
            && (($receipt['status'] ?? '') === 'ready'
                || in_array($reasonCode, [
                    'scheduler_ready',
                    'scheduler_enabled_waiting_natural_run',
                    'scheduler_already_enabled_waiting_natural_run',
                ], true))
        ) {
            $reasonCode = 'scheduler_process_exit_nonzero';
        } elseif (!$enableReadbackVerified) {
            $preservedFailureReasons = [
                'scheduler_status_stale',
                'scheduler_enable_window_too_close',
                'scheduler_safe_settings_readback_failed',
                'scheduler_enable_triggered_unexpected_run',
                'scheduler_enable_readback_failed',
                'scheduler_catch_up_enabled',
                'scheduler_task_active',
                'scheduler_process_exit_nonzero',
            ];
            if (!in_array($reasonCode, $preservedFailureReasons, true)) {
                $reasonCode = 'scheduler_enable_readback_failed';
            }
        } elseif ($reasonCode === '') {
            $reasonCode = $enabled ? 'scheduler_ready' : 'scheduler_disabled';
        }

        $normalized = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $scopeVerified
                && $enableReadbackVerified
                && $enabled
                && $catchUpDisabled
                && !$taskStateActive
                && !$taskStarted
                && !$startsTaskImmediately
                && $processExitVerified
                    ? 'ready'
                    : 'blocked',
            'reason_code' => $reasonCode,
            'local_only' => true,
            'production_ready' => false,
            'hotel_id' => self::PILOT_HOTEL_ID,
            'task_name' => self::TASK_NAME,
            'task_exists' => $taskExists,
            'task_state' => $taskState,
            'enabled' => $enabled,
            'scope' => [
                'hotel_id' => self::PILOT_HOTEL_ID,
                'source_ids' => self::SOURCE_IDS,
                'platforms' => self::PLATFORMS,
                'mode' => 'Daily',
            ],
            'action_verified' => ($receipt['action_verified'] ?? null) === true,
            'trigger_verified' => ($receipt['trigger_verified'] ?? null) === true,
            'principal_verified' => ($receipt['principal_verified'] ?? null) === true,
            'settings_verified' => ($receipt['settings_verified'] ?? null) === true,
            'scope_verified' => $scopeVerified,
            'control_state_verified' => true,
            'control_process_exit_code' => $processExitCode,
            'catch_up_disabled' => $catchUpDisabled,
            'safe_enable_transition_required' => !$catchUpDisabled,
            'task_state_active' => $taskStateActive,
            'trigger' => [
                'count' => max(0, (int)($trigger['count'] ?? 0)),
                'start_boundary' => $startBoundary,
                'retry_interval' => $retryInterval,
                'retry_duration' => $retryDuration,
            ],
            'last_run_time' => $this->safeTimestamp($receipt['last_run_time'] ?? null),
            'last_task_result' => is_numeric($receipt['last_task_result'] ?? null)
                ? (int)$receipt['last_task_result']
                : null,
            'next_run_time' => $this->safeTimestamp($receipt['next_run_time'] ?? null),
            'contract_digest' => $scopeVerified ? $digest : null,
            'can_enable' => $taskExists
                && $scopeVerified
                && !$enabled
                && !$taskStateActive
                && !$taskStarted
                && !$startsTaskImmediately
                && $processExitVerified
                && ($receipt['can_enable'] ?? null) === true,
            'enable_action_performed' => ($receipt['enable_action_performed'] ?? null) === true,
            'settings_action_performed' => ($receipt['settings_action_performed'] ?? null) === true,
            'last_run_unchanged' => ($receipt['last_run_unchanged'] ?? null) === true,
            'task_started' => $taskStarted,
            'starts_task_immediately' => $startsTaskImmediately,
            'sensitive_values_exposed' => false,
        ];

        if (!$scopeVerified || !$enableReadbackVerified) {
            $normalized['can_enable'] = false;
        }

        return $normalized;
    }

    /** @return array<string,mixed> */
    private function runPowerShell(string $mode, int $hotelId, string $expectedDigest): array
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return ['exit_code' => 2, 'stdout' => '', 'stderr' => ''];
        }

        $script = $this->projectRoot . DIRECTORY_SEPARATOR . 'scripts'
            . DIRECTORY_SEPARATOR . 'manage_ota_dispatcher_task.ps1';
        $systemRoot = trim((string)getenv('SystemRoot'));
        $powershell = ($systemRoot !== '' ? $systemRoot : 'C:\\Windows')
            . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
        if (!is_file($script) || !is_file($powershell)) {
            return ['exit_code' => 2, 'stdout' => '', 'stderr' => ''];
        }

        $command = [
            $powershell,
            '-NoProfile',
            '-NonInteractive',
            '-ExecutionPolicy',
            'Bypass',
            '-File',
            $script,
            '-Mode',
            $mode,
            '-HotelId',
            (string)$hotelId,
            '-ProjectRoot',
            $this->projectRoot,
        ];
        if ($expectedDigest !== '') {
            $command[] = '-ExpectedContractDigest';
            $command[] = $expectedDigest;
        }

        $pipes = [];
        $process = @proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->projectRoot,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            return ['exit_code' => 2, 'stdout' => '', 'stderr' => ''];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $startedAt = microtime(true);
        $exitCode = -1;
        while (true) {
            $stdout .= (string)stream_get_contents($pipes[1]);
            $stderr .= (string)stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!($status['running'] ?? false)) {
                $exitCode = (int)($status['exitcode'] ?? -1);
                break;
            }
            if ((microtime(true) - $startedAt) >= self::PROCESS_TIMEOUT_SECONDS) {
                @proc_terminate($process);
                $exitCode = 2;
                break;
            }
            usleep(20000);
        }
        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closedExitCode = proc_close($process);
        if ($exitCode < 0 && $closedExitCode >= 0) {
            $exitCode = $closedExitCode;
        }

        return [
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            // stderr is intentionally retained only in-memory and never exposed.
            'stderr' => $stderr,
        ];
    }

    /** @return array<string,mixed> */
    private function blockedReceipt(int $hotelId, string $reasonCode, bool $controlStateVerified = true): array
    {
        $unknown = $controlStateVerified ? false : null;
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'blocked',
            'reason_code' => $reasonCode,
            'local_only' => true,
            'production_ready' => false,
            'hotel_id' => $hotelId,
            'task_name' => self::TASK_NAME,
            'task_exists' => $unknown,
            'task_state' => 'Unavailable',
            'enabled' => $unknown,
            'scope' => [
                'hotel_id' => self::PILOT_HOTEL_ID,
                'source_ids' => self::SOURCE_IDS,
                'platforms' => self::PLATFORMS,
                'mode' => 'Daily',
            ],
            'action_verified' => false,
            'trigger_verified' => false,
            'principal_verified' => false,
            'settings_verified' => false,
            'scope_verified' => false,
            'control_state_verified' => $controlStateVerified,
            'control_process_exit_code' => null,
            'catch_up_disabled' => $unknown,
            'safe_enable_transition_required' => $unknown,
            'task_state_active' => $unknown,
            'trigger' => [
                'count' => 0,
                'start_boundary' => null,
                'retry_interval' => '',
                'retry_duration' => '',
            ],
            'last_run_time' => null,
            'last_task_result' => null,
            'next_run_time' => null,
            'contract_digest' => null,
            'can_enable' => false,
            'enable_action_performed' => $unknown,
            'settings_action_performed' => $unknown,
            'last_run_unchanged' => $unknown,
            'task_started' => $unknown,
            'starts_task_immediately' => $unknown,
            'sensitive_values_exposed' => false,
        ];
    }

    private function safeCode(string $value): string
    {
        $value = strtolower(trim($value));
        return preg_match('/^[a-z0-9_.:-]{1,100}$/', $value) === 1 ? $value : '';
    }

    private function safeDuration(string $value): string
    {
        $value = strtoupper(trim($value));
        return preg_match('/^PT(?:\d+H)?(?:\d+M)?(?:\d+S)?$/', $value) === 1 ? $value : '';
    }

    private function safeText(string $value, int $limit): string
    {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '');
        return mb_substr($value, 0, $limit);
    }

    private function safeTimestamp(mixed $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->format(DATE_ATOM);
        } catch (\Throwable) {
            return null;
        }
    }
}
