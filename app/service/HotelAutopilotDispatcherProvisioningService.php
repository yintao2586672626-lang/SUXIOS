<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Strict bridge to the generic per-hotel Windows dispatcher provisioner.
 *
 * It accepts and returns only a secret-free execution scope. The PowerShell
 * wrapper remains responsible for exact Scheduled Task readback.
 */
final class HotelAutopilotDispatcherProvisioningService
{
    public const SCHEMA_VERSION = 'suxios_hotel_autopilot_dispatcher.v1';
    private const RECEIPT_PREFIX = 'SUXIOS_HOTEL_AUTOPILOT_DISPATCHER=';
    private const PROCESS_TIMEOUT_SECONDS = 45;

    /** @var Closure(array<int,string>):array<string,mixed> */
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
            : fn(array $command): array => $this->runProcess($command);
    }

    /** @param array<string,mixed> $scope @return array<string,mixed> */
    public function provision(array $scope): array
    {
        $hotelId = (int)($scope['hotel_id'] ?? 0);
        $sourceIds = array_values(array_map('intval', is_array($scope['source_ids'] ?? null)
            ? $scope['source_ids']
            : []));
        $platforms = array_values(array_map(
            static fn(mixed $platform): string => strtolower(trim((string)$platform)),
            is_array($scope['platforms'] ?? null) ? $scope['platforms'] : []
        ));
        $scheduleTime = trim((string)($scope['schedule_time'] ?? '08:30'));
        $startNow = ($scope['start_now'] ?? false) === true;
        $replaceExisting = ($scope['replace_existing'] ?? false) === true;
        if ($hotelId <= 0
            || count($sourceIds) !== 2
            || count(array_unique($sourceIds)) !== 2
            || min($sourceIds) <= 0
            || $platforms !== ['ctrip', 'meituan']
            || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $scheduleTime) !== 1
        ) {
            throw new InvalidArgumentException('hotel_autopilot_dispatcher_scope_invalid');
        }

        $command = $this->command(
            $hotelId,
            $sourceIds,
            $platforms,
            $scheduleTime,
            $replaceExisting,
            $startNow
        );
        $process = ($this->runner)($command);
        $receipt = isset($process['schema_version'])
            ? $process
            : $this->parseReceipt((string)($process['stdout'] ?? ''), (int)($process['exit_code'] ?? -1));
        return $this->normalizeReceipt($receipt, $hotelId, $sourceIds, $platforms, $startNow);
    }

    /** @param array<int,int> $sourceIds @param array<int,string> $platforms @return array<int,string> */
    private function command(
        int $hotelId,
        array $sourceIds,
        array $platforms,
        string $scheduleTime,
        bool $replaceExisting,
        bool $startNow
    ): array {
        if (PHP_OS_FAMILY !== 'Windows') {
            throw new RuntimeException('hotel_autopilot_dispatcher_windows_required');
        }
        $script = $this->projectRoot . DIRECTORY_SEPARATOR . 'scripts'
            . DIRECTORY_SEPARATOR . 'provision_hotel_autopilot_dispatcher.ps1';
        $systemRoot = trim((string)getenv('SystemRoot'));
        $powershell = ($systemRoot !== '' ? $systemRoot : 'C:\\Windows')
            . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
        $php = 'C:\\xampp\\php\\php.exe';
        if (!is_file($script) || !is_file($powershell) || !is_file($php)) {
            throw new RuntimeException('hotel_autopilot_dispatcher_runtime_missing');
        }
        $command = [
            $powershell,
            '-NoProfile',
            '-NonInteractive',
            '-ExecutionPolicy',
            'Bypass',
            '-File',
            $script,
            '-HotelId',
            (string)$hotelId,
            '-SourceIds',
            implode(',', $sourceIds),
            '-Platforms',
            implode(',', $platforms),
            '-DailyAt',
            $scheduleTime,
            '-ProjectRoot',
            $this->projectRoot,
            '-PhpPath',
            $php,
        ];
        if ($replaceExisting) {
            $command[] = '-ReplaceExisting';
        }
        if ($startNow) {
            $command[] = '-StartNow';
        }
        return $command;
    }

    /** @return array<string,mixed> */
    private function parseReceipt(string $stdout, int $exitCode): array
    {
        $matches = [];
        foreach (preg_split('/\R/', $stdout) ?: [] as $line) {
            $line = trim((string)$line);
            if (!str_starts_with($line, self::RECEIPT_PREFIX)) {
                continue;
            }
            $decoded = json_decode(substr($line, strlen(self::RECEIPT_PREFIX)), true);
            if (is_array($decoded)) {
                $matches[] = $decoded;
            }
        }
        if (count($matches) !== 1) {
            throw new RuntimeException('hotel_autopilot_dispatcher_receipt_missing');
        }
        $matches[0]['process_exit_code'] = $exitCode;
        return $matches[0];
    }

    /** @param array<string,mixed> $receipt @param array<int,int> $sourceIds @param array<int,string> $platforms @return array<string,mixed> */
    private function normalizeReceipt(
        array $receipt,
        int $hotelId,
        array $sourceIds,
        array $platforms,
        bool $startNow
    ): array {
        $scope = is_array($receipt['scope'] ?? null) ? $receipt['scope'] : [];
        $actualSources = array_values(array_map('intval', is_array($scope['source_ids'] ?? null)
            ? $scope['source_ids']
            : []));
        $actualPlatforms = array_values(array_map(
            static fn(mixed $platform): string => strtolower(trim((string)$platform)),
            is_array($scope['platforms'] ?? null) ? $scope['platforms'] : []
        ));
        $processExitCode = is_numeric($receipt['process_exit_code'] ?? null)
            ? (int)$receipt['process_exit_code']
            : 0;
        $taskStarted = ($receipt['task_started'] ?? false) === true;
        $strict = ($receipt['schema_version'] ?? null) === self::SCHEMA_VERSION
            && (string)($receipt['status'] ?? '') === 'ready'
            && (int)($receipt['hotel_id'] ?? 0) === $hotelId
            && (string)($receipt['task_name'] ?? '') === 'SUXIOS OTA Dispatcher H' . $hotelId
            && ($receipt['task_exists'] ?? false) === true
            && ($receipt['enabled'] ?? false) === true
            && (int)($scope['hotel_id'] ?? 0) === $hotelId
            && $actualSources === $sourceIds
            && $actualPlatforms === $platforms
            && strtolower(trim((string)($scope['mode'] ?? ''))) === 'daily'
            && ($receipt['scope_verified'] ?? false) === true
            && ($receipt['action_verified'] ?? false) === true
            && ($receipt['trigger_verified'] ?? false) === true
            && ($receipt['principal_verified'] ?? false) === true
            && ($receipt['readback_verified'] ?? false) === true
            && ($receipt['sensitive_values_exposed'] ?? true) === false
            && $processExitCode === 0
            && $taskStarted === $startNow;
        if (!$strict) {
            $reason = $this->safeCode((string)($receipt['reason_code'] ?? ''));
            throw new RuntimeException($reason !== '' ? $reason : 'hotel_autopilot_dispatcher_readback_failed');
        }
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ready',
            'reason_code' => $this->safeCode((string)($receipt['reason_code'] ?? '')),
            'hotel_id' => $hotelId,
            'task_name' => 'SUXIOS OTA Dispatcher H' . $hotelId,
            'task_exists' => true,
            'enabled' => true,
            'task_started' => $taskStarted,
            'scope_verified' => true,
            'readback_verified' => true,
            'external_action_triggered' => false,
            'auto_write_ota' => false,
            'sensitive_values_exposed' => false,
        ];
    }

    /** @param array<int,string> $command @return array<string,mixed> */
    private function runProcess(array $command): array
    {
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
            throw new RuntimeException('hotel_autopilot_dispatcher_process_unavailable');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $startedAt = microtime(true);
        $exitCode = -1;
        while (true) {
            $stdout .= (string)stream_get_contents($pipes[1]);
            // stderr is deliberately discarded and never persisted or exposed.
            stream_get_contents($pipes[2]);
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
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closedExitCode = proc_close($process);
        if ($exitCode < 0 && $closedExitCode >= 0) {
            $exitCode = $closedExitCode;
        }
        return ['exit_code' => $exitCode, 'stdout' => $stdout];
    }

    private function safeCode(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_.:-]+/', '_', $value) ?? '';
        return trim(substr($value, 0, 120), '_');
    }
}
