<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;
use Throwable;

final class AiReportGenerationTaskService
{
    private const TABLE = 'ai_report_generation_tasks';
    private const COMMAND_NAME = 'ai-daily-report:generate-once';
    private const TERMINAL_STATUSES = ['succeeded', 'partial', 'blocked', 'failed'];
    private const QUEUED_LEASE_SECONDS = 120;
    private const RUNNING_LEASE_SECONDS = 1800;
    private const DISPATCH_LOCK_NAME = 'suxi_ai_report_dispatch_v1';
    private const DEFAULT_MAX_CONCURRENT = 2;
    private const MAX_MAX_CONCURRENT = 8;
    private const DEFAULT_TASK_RETENTION_DAYS = 30;
    private const DEFAULT_CACHE_RETENTION_DAYS = 30;

    /** @var callable|null */
    private $launcher;

    public function __construct(?callable $launcher = null)
    {
        $this->launcher = $launcher;
    }

    public function enqueue(
        array $permittedHotelIds,
        int $hotelId,
        string $reportDate,
        int $userId,
        array $options = []
    ): array {
        $this->assertTableExists();
        $permittedHotelIds = $this->normalizeHotelIds($permittedHotelIds);
        if (!self::isHotelPermitted($permittedHotelIds, $hotelId)) {
            throw new \InvalidArgumentException('hotel_id is not permitted');
        }
        $reportDate = $this->normalizeDate($reportDate);
        $modelKey = trim((string)($options['model_key'] ?? '')) ?: 'deepseek_v4_default';
        $useLlm = !array_key_exists('use_llm', $options)
            || filter_var($options['use_llm'], FILTER_VALIDATE_BOOL);
        $promptVersion = AiDailyReportService::promptVersion();
        $trustedInputVersion = AiDailyReportService::trustedInputVersion();
        $trustedInputRevision = $this->resolveTrustedInputRevision($hotelId, $reportDate);
        $dedupeKey = self::activeDedupeKey(
            $hotelId,
            $reportDate,
            $modelKey,
            $useLlm,
            $promptVersion,
            $trustedInputVersion,
            $trustedInputRevision
        );
        $this->recoverExpiredActiveTasks($dedupeKey);

        $active = Db::name(self::TABLE)
            ->where('active_dedupe_key', $dedupeKey)
            ->whereIn('status', ['queued', 'running'])
            ->order('id', 'desc')
            ->find();
        if (is_array($active)) {
            $public = self::normalizePublicTask($active);
            $public['deduplicated'] = true;
            return $public;
        }

        $now = date('Y-m-d H:i:s');
        $taskId = 'airpt_' . date('YmdHis') . '_' . bin2hex(random_bytes(12));
        $tenantId = (int)(Db::name('hotels')->where('id', $hotelId)->value('tenant_id') ?? 0);
        try {
            $payload = [
                'task_id' => $taskId,
                'tenant_id' => $tenantId > 0 ? $tenantId : null,
                'hotel_id' => $hotelId,
                'report_date' => $reportDate,
                'requested_by' => max(0, $userId),
                'model_key' => $modelKey,
                'use_llm' => $useLlm ? 1 : 0,
                'active_dedupe_key' => $dedupeKey,
                'lease_expires_at' => null,
                'status' => 'queued',
                'stage' => 'queued',
                'progress_percent' => 5,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            foreach ([
                'prompt_version' => $promptVersion,
                'trusted_input_version' => $trustedInputVersion,
                'trusted_input_revision' => $trustedInputRevision,
            ] as $column => $value) {
                if ($this->tableHasColumn(self::TABLE, $column)) {
                    $payload[$column] = $value;
                }
            }
            Db::name(self::TABLE)->insert($payload);
        } catch (Throwable $e) {
            // The nullable unique active key closes the concurrent enqueue race.
            $active = Db::name(self::TABLE)
                ->where('active_dedupe_key', $dedupeKey)
                ->whereIn('status', ['queued', 'running'])
                ->find();
            if (!is_array($active)) {
                throw $e;
            }
            $public = self::normalizePublicTask($active);
            $public['deduplicated'] = true;
            return $public;
        }

        $this->dispatchQueuedTasks();

        $task = $this->findTask($taskId);
        if (!is_array($task)) {
            throw new \RuntimeException('AI report generation task was not persisted');
        }
        $public = self::normalizePublicTask($task);
        $public['deduplicated'] = false;
        return $public;
    }

    public function readPublicTask(string $taskId, array $permittedHotelIds): ?array
    {
        $this->assertTableExists();
        $taskId = $this->normalizeTaskId($taskId);
        $hotelIds = $this->normalizeHotelIds($permittedHotelIds);
        if ($taskId === '' || $hotelIds === []) {
            return null;
        }

        $this->recoverExpiredActiveTasks(null, $taskId);
        $this->dispatchQueuedTasks();
        $row = Db::name(self::TABLE)
            ->where('task_id', $taskId)
            ->whereIn('hotel_id', $hotelIds)
            ->find();
        return is_array($row) ? self::normalizePublicTask($row) : null;
    }

    /**
     * Launch only the number of workers that fit in the configured local
     * capacity. A MySQL advisory lock serializes capacity accounting across
     * concurrent PHP requests without introducing another queue dependency.
     */
    public function dispatchQueuedTasks(): int
    {
        $this->assertTableExists();
        $this->recoverExpiredActiveTasks();
        if (!$this->acquireDispatchLock()) {
            return 0;
        }

        try {
            $running = (int)Db::name(self::TABLE)->where('status', 'running')->count();
            $launching = (int)Db::name(self::TABLE)
                ->where('status', 'queued')
                ->where('stage', 'launching')
                ->count();
            $available = self::availableDispatchSlots($this->maxConcurrentWorkers(), $running, $launching);
            if ($available <= 0) {
                return 0;
            }

            $rows = Db::name(self::TABLE)
                ->where('status', 'queued')
                ->where('stage', 'queued')
                ->order('id', 'asc')
                ->limit($available)
                ->field('task_id')
                ->select()
                ->toArray();
            $launched = 0;
            foreach ($rows as $row) {
                $taskId = $this->normalizeTaskId((string)($row['task_id'] ?? ''));
                if ($taskId === '') {
                    continue;
                }
                $now = date('Y-m-d H:i:s');
                $reserved = (int)Db::name(self::TABLE)
                    ->where('task_id', $taskId)
                    ->where('status', 'queued')
                    ->where('stage', 'queued')
                    ->update([
                        'stage' => 'launching',
                        'progress_percent' => 10,
                        'lease_expires_at' => date('Y-m-d H:i:s', time() + self::QUEUED_LEASE_SECONDS),
                        'updated_at' => $now,
                    ]);
                if ($reserved !== 1) {
                    continue;
                }

                $started = $this->launcher !== null
                    ? (bool)call_user_func($this->launcher, $taskId)
                    : $this->launchBackgroundCommand($taskId);
                if (!$started) {
                    $this->failTask($taskId, 'AI report background process could not be started.', 'launch_failed');
                    continue;
                }
                $launched++;
            }
            return $launched;
        } finally {
            $this->releaseDispatchLock();
        }
    }

    public static function availableDispatchSlots(int $limit, int $running, int $launching): int
    {
        $limit = max(1, min(self::MAX_MAX_CONCURRENT, $limit));
        return max(0, $limit - max(0, $running) - max(0, $launching));
    }

    /** Claiming is atomic; duplicate workers cannot both execute a queued task. */
    public function claimTask(string $taskId): ?array
    {
        $this->assertTableExists();
        $taskId = $this->normalizeTaskId($taskId);
        if ($taskId === '') {
            return null;
        }
        $this->recoverExpiredActiveTasks(null, $taskId);
        $now = date('Y-m-d H:i:s');
        $changed = Db::name(self::TABLE)
            ->where('task_id', $taskId)
            ->where('status', 'queued')
            ->update([
                'status' => 'running',
                'stage' => 'generating',
                'progress_percent' => 30,
                'started_at' => $now,
                'lease_expires_at' => date('Y-m-d H:i:s', time() + self::RUNNING_LEASE_SECONDS),
                'updated_at' => $now,
            ]);
        if ((int)$changed !== 1) {
            return null;
        }
        return $this->findTask($taskId);
    }

    public function completeTask(string $taskId, array $report): array
    {
        $taskId = $this->normalizeTaskId($taskId);
        $current = $this->findTask($taskId);
        $terminalStatus = is_array($current) ? self::terminalStatusForReport($current, $report) : 'partial';
        if (!is_array($current) || !self::canTransition((string)($current['status'] ?? ''), $terminalStatus)) {
            return is_array($current) ? self::normalizePublicTask($current) : [];
        }

        $now = date('Y-m-d H:i:s');
        $modelStatus = strtolower(trim((string)($report['model_status'] ?? '')));
        if ($modelStatus === '' && (int)($current['use_llm'] ?? 1) === 0) {
            $modelStatus = 'not_requested';
        }
        $stage = match (true) {
            $terminalStatus === 'blocked' => 'completed_with_data_gap',
            $terminalStatus === 'partial' => 'completed_with_model_failure',
            in_array($modelStatus, ['failed', 'invalid_output'], true) => 'completed_result_available_ai_failed',
            $modelStatus === 'blocked_by_data_quality' => 'completed_result_available_ai_blocked',
            $modelStatus === 'not_requested' => 'completed_rule_result',
            default => 'completed',
        };
        Db::name(self::TABLE)
            ->where('task_id', $taskId)
            ->where('status', 'running')
            ->update([
                'status' => $terminalStatus,
                'stage' => $stage,
                'progress_percent' => 100,
                'result_report_id' => (int)($report['id'] ?? 0) ?: null,
                'input_fingerprint' => substr((string)($report['input_fingerprint'] ?? ''), 0, 64),
                'model_status' => substr($modelStatus, 0, 40),
                'cache_hit' => !empty($report['cache_hit']) ? 1 : 0,
                'active_dedupe_key' => null,
                'lease_expires_at' => null,
                'finished_at' => $now,
                'updated_at' => $now,
            ]);
        $row = $this->findTask($taskId);
        return is_array($row) ? self::normalizePublicTask($row) : [];
    }

    public function failTask(string $taskId, string $message, string $errorCode = 'generation_failed'): array
    {
        $taskId = $this->normalizeTaskId($taskId);
        $current = $this->findTask($taskId);
        if (!is_array($current) || !self::canTransition((string)($current['status'] ?? ''), 'failed')) {
            return is_array($current) ? self::normalizePublicTask($current) : [];
        }

        $now = date('Y-m-d H:i:s');
        Db::name(self::TABLE)
            ->where('task_id', $taskId)
            ->whereIn('status', ['queued', 'running'])
            ->update([
                'status' => 'failed',
                'stage' => substr(preg_replace('/[^a-z0-9_\-]/', '_', strtolower($errorCode)) ?: 'failed', 0, 40),
                'progress_percent' => 100,
                'error_code' => substr($errorCode, 0, 80),
                'error_message' => self::safePublicErrorMessage($errorCode),
                'active_dedupe_key' => null,
                'lease_expires_at' => null,
                'finished_at' => $now,
                'updated_at' => $now,
            ]);
        $row = $this->findTask($taskId);
        return is_array($row) ? self::normalizePublicTask($row) : [];
    }

    public static function canTransition(string $from, string $to): bool
    {
        $from = strtolower(trim($from));
        $to = strtolower(trim($to));
        if (in_array($from, self::TERMINAL_STATUSES, true)) {
            return false;
        }
        return ($from === 'queued' && in_array($to, ['running', 'failed'], true))
            || ($from === 'running' && in_array($to, self::TERMINAL_STATUSES, true));
    }

    public static function terminalStatusForReport(array $task, array $report): string
    {
        $modelStatus = strtolower(trim((string)($report['model_status'] ?? '')));
        $resultStatus = is_array($report['result_readiness'] ?? null)
            ? $report['result_readiness']
            : (is_array($report['result_status'] ?? null) ? $report['result_status'] : []);
        if (($resultStatus['usable'] ?? false) === true) {
            return 'succeeded';
        }
        if (in_array((string)($resultStatus['status'] ?? ''), ['unavailable', 'unverified'], true)
            && $modelStatus === 'blocked_by_data_quality') {
            return 'blocked';
        }
        if ($modelStatus === 'blocked_by_data_quality') {
            return 'blocked';
        }
        if (in_array($modelStatus, ['failed', 'invalid_output'], true)) {
            return 'partial';
        }
        if ((int)($task['use_llm'] ?? 1) === 0 || in_array($modelStatus, ['ok', 'not_requested'], true)) {
            return 'succeeded';
        }
        return 'partial';
    }

    public static function isLeaseExpired(?string $leaseExpiresAt, string $now): bool
    {
        $lease = $leaseExpiresAt === null ? false : strtotime($leaseExpiresAt);
        $current = strtotime($now);
        return $lease !== false && $current !== false && $lease < $current;
    }

    public static function activeDedupeKey(
        int $hotelId,
        string $reportDate,
        string $modelKey,
        bool $useLlm,
        string $promptVersion = '',
        string $trustedInputVersion = '',
        string $trustedInputRevision = ''
    ): string {
        $promptVersion = trim($promptVersion) ?: AiDailyReportService::promptVersion();
        $trustedInputVersion = trim($trustedInputVersion) ?: AiDailyReportService::trustedInputVersion();
        return hash('sha256', implode('|', [
            'ai_daily_report_task.v2',
            max(0, $hotelId),
            substr(trim($reportDate), 0, 10),
            trim($modelKey),
            $useLlm ? '1' : '0',
            $promptVersion,
            $trustedInputVersion,
            trim($trustedInputRevision),
        ]));
    }

    public static function isHotelPermitted(array $permittedHotelIds, int $hotelId): bool
    {
        return $hotelId > 0 && in_array($hotelId, array_values(array_unique(array_map('intval', $permittedHotelIds))), true);
    }

    /** Whitelist the public contract; tenant and worker internals never leak. */
    public static function normalizePublicTask(array $row): array
    {
        $status = strtolower(trim((string)($row['status'] ?? 'queued')));
        return [
            'task_id' => (string)($row['task_id'] ?? ''),
            'hotel_id' => (int)($row['hotel_id'] ?? 0),
            'report_date' => substr((string)($row['report_date'] ?? ''), 0, 10),
            'status' => $status,
            'stage' => (string)($row['stage'] ?? ''),
            'progress_percent' => max(0, min(100, (int)($row['progress_percent'] ?? 0))),
            'result_report_id' => (int)($row['result_report_id'] ?? 0) ?: null,
            'cache_hit' => (int)($row['cache_hit'] ?? 0) === 1,
            'model_status' => (string)($row['model_status'] ?? ''),
            'error_code' => (string)($row['error_code'] ?? ''),
            'error_message' => (string)($row['error_message'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'started_at' => (string)($row['started_at'] ?? ''),
            'finished_at' => (string)($row['finished_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
            'done' => in_array($status, self::TERMINAL_STATUSES, true),
        ];
    }

    private static function safePublicErrorMessage(string $errorCode): string
    {
        return match (strtolower(trim($errorCode))) {
            'launch_failed' => 'AI report background process could not be started.',
            'worker_lease_expired' => 'AI report worker stopped before completion. The task can be retried.',
            'input_invalid' => 'AI report task input is invalid.',
            default => 'AI report generation failed. Retry after checking trusted data and model configuration.',
        };
    }

    private function recoverExpiredActiveTasks(?string $dedupeKey = null, ?string $taskId = null): int
    {
        $now = date('Y-m-d H:i:s');
        $query = Db::name(self::TABLE)
            ->whereIn('status', ['queued', 'running'])
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<', $now);
        if ($dedupeKey !== null && $dedupeKey !== '') {
            $query->where('active_dedupe_key', $dedupeKey);
        }
        if ($taskId !== null && $taskId !== '') {
            $query->where('task_id', $taskId);
        }

        $terminalPayload = [
            'status' => 'failed',
            'stage' => 'worker_lease_expired',
            'progress_percent' => 100,
            'model_status' => '',
            'error_code' => 'worker_lease_expired',
            'error_message' => self::safePublicErrorMessage('worker_lease_expired'),
            'active_dedupe_key' => null,
            'lease_expires_at' => null,
            'finished_at' => $now,
            'updated_at' => $now,
        ];
        $recovered = (int)$query->update($terminalPayload);

        // Compatibility for active rows created before the lease column was
        // deployed: only sufficiently old null leases are reclaimed.
        $legacyQuery = Db::name(self::TABLE)
            ->where('status', 'running')
            ->whereNull('lease_expires_at')
            ->where('updated_at', '<', date('Y-m-d H:i:s', time() - self::RUNNING_LEASE_SECONDS));
        if ($dedupeKey !== null && $dedupeKey !== '') {
            $legacyQuery->where('active_dedupe_key', $dedupeKey);
        }
        if ($taskId !== null && $taskId !== '') {
            $legacyQuery->where('task_id', $taskId);
        }
        return $recovered + (int)$legacyQuery->update($terminalPayload);
    }

    /**
     * Remove only terminal task metadata and stale reusable model output.
     * Persisted AI reports and verified OTA source rows are never deleted.
     */
    public function cleanupExpiredRecords(
        ?int $taskRetentionDays = null,
        ?int $cacheRetentionDays = null,
        bool $dryRun = false,
        int $limit = 500
    ): array {
        $this->assertTableExists();
        // A dry run must be observational only. Lease recovery changes public
        // task state, so report the eligible rows without mutating them.
        $expiredActiveTasks = $dryRun
            ? $this->countExpiredActiveTasks()
            : $this->recoverExpiredActiveTasks();
        $taskRetentionDays = $this->retentionDays(
            $taskRetentionDays,
            'llm.report_tasks.task_retention_days',
            'SUXI_AI_REPORT_TASK_RETENTION_DAYS',
            self::DEFAULT_TASK_RETENTION_DAYS
        );
        $cacheRetentionDays = $this->retentionDays(
            $cacheRetentionDays,
            'llm.report_tasks.cache_retention_days',
            'SUXI_AI_REPORT_CACHE_RETENTION_DAYS',
            self::DEFAULT_CACHE_RETENTION_DAYS
        );
        $limit = max(1, min(5000, $limit));
        $taskCutoff = date('Y-m-d H:i:s', time() - ($taskRetentionDays * 86400));
        $cacheCutoff = date('Y-m-d H:i:s', time() - ($cacheRetentionDays * 86400));

        $taskRows = Db::name(self::TABLE)
            ->whereIn('status', self::TERMINAL_STATUSES)
            ->where('updated_at', '<', $taskCutoff)
            ->order('id', 'asc')
            ->limit($limit)
            ->field('id,task_id')
            ->select()
            ->toArray();
        $taskIds = array_values(array_filter(array_map(
            fn(array $row): string => $this->normalizeTaskId((string)($row['task_id'] ?? '')),
            $taskRows
        )));
        $taskPrimaryIds = array_values(array_filter(array_map(
            static fn(array $row): int => (int)($row['id'] ?? 0),
            $taskRows
        ), static fn(int $id): bool => $id > 0));

        $cachePrimaryIds = [];
        if ($this->tableExists('ai_report_input_cache')) {
            $cachePrimaryIds = array_values(array_filter(array_map('intval',
                Db::name('ai_report_input_cache')
                    ->where('updated_at', '<', $cacheCutoff)
                    ->order('id', 'asc')
                    ->limit($limit)
                    ->column('id')
            ), static fn(int $id): bool => $id > 0));
        }

        $taskLogPaths = $this->taskLogPaths($taskIds);
        $orphanLogPaths = $this->orphanTaskLogPaths($taskCutoff, $limit * 3);

        $removedTasks = 0;
        $removedCache = 0;
        $removedLogs = 0;
        if (!$dryRun) {
            if ($taskPrimaryIds !== []) {
                // Logs are diagnostic artifacts, not product data. Remove the
                // exact files first so a process interruption cannot strand
                // them after their task metadata has already disappeared.
                $removedLogs += $this->removeTaskLogPaths($taskLogPaths);
                $removedTasks = (int)Db::name(self::TABLE)->whereIn('id', $taskPrimaryIds)->delete();
            }
            if ($cachePrimaryIds !== []) {
                $removedCache = (int)Db::name('ai_report_input_cache')->whereIn('id', $cachePrimaryIds)->delete();
            }
            $removedLogs += $this->removeTaskLogPaths($orphanLogPaths);
        }

        return [
            'dry_run' => $dryRun,
            'task_retention_days' => $taskRetentionDays,
            'cache_retention_days' => $cacheRetentionDays,
            'eligible_expired_active_tasks' => $expiredActiveTasks,
            'eligible_tasks' => count($taskPrimaryIds),
            'eligible_cache_rows' => count($cachePrimaryIds),
            'eligible_log_files' => count($taskLogPaths) + count($orphanLogPaths),
            'removed_tasks' => $removedTasks,
            'removed_cache_rows' => $removedCache,
            'removed_log_files' => $removedLogs,
            'limited_to' => $limit,
        ];
    }

    private function countExpiredActiveTasks(): int
    {
        $now = date('Y-m-d H:i:s');
        $leased = (int)Db::name(self::TABLE)
            ->whereIn('status', ['queued', 'running'])
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<', $now)
            ->count();
        $legacy = (int)Db::name(self::TABLE)
            ->where('status', 'running')
            ->whereNull('lease_expires_at')
            ->where('updated_at', '<', date('Y-m-d H:i:s', time() - self::RUNNING_LEASE_SECONDS))
            ->count();
        return $leased + $legacy;
    }

    private function findTask(string $taskId): ?array
    {
        if ($taskId === '') {
            return null;
        }
        $row = Db::name(self::TABLE)->where('task_id', $taskId)->find();
        return is_array($row) ? $row : null;
    }

    private function launchBackgroundCommand(string $taskId): bool
    {
        $root = dirname(__DIR__, 2);
        $think = $root . DIRECTORY_SEPARATOR . 'think';
        $php = $this->resolvePhpCliBinary();
        if ($php === '' || !is_file($think)) {
            return false;
        }

        $runtime = $root . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'ai_report_tasks';
        if (!is_dir($runtime) && !mkdir($runtime, 0775, true) && !is_dir($runtime)) {
            return false;
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            $command = self::buildWindowsLauncherCommand(
                $php,
                $think,
                $root,
                $taskId,
                $runtime . DIRECTORY_SEPARATOR . $taskId . '.stdout.log',
                $runtime . DIRECTORY_SEPARATOR . $taskId . '.stderr.log'
            );
        } else {
            $log = $runtime . DIRECTORY_SEPARATOR . $taskId . '.log';
            $command = 'cd ' . escapeshellarg($root)
                . ' && ' . escapeshellarg($php) . ' ' . escapeshellarg($think)
                . ' ' . self::COMMAND_NAME . ' --task-id=' . escapeshellarg($taskId)
                . ' >> ' . escapeshellarg($log) . ' 2>&1 &';
        }

        $handle = @popen($command, 'r');
        if (!is_resource($handle)) {
            return false;
        }
        return pclose($handle) === 0;
    }

    /**
     * PowerShell -EncodedCommand consumes UTF-16LE, so Chinese project paths
     * cross the cmd.exe boundary without code-page or nested-quote loss.
     */
    public static function buildWindowsLauncherCommand(
        string $php,
        string $think,
        string $root,
        string $taskId,
        string $stdoutLog,
        string $stderrLog
    ): string {
        $literal = static fn(string $value): string => "'" . str_replace("'", "''", $value) . "'";
        $arguments = [
            $literal($think),
            $literal(self::COMMAND_NAME),
            $literal('--task-id=' . $taskId),
        ];
        $script = '$ErrorActionPreference = \'Stop\'' . "\r\n"
            . '$ProgressPreference = \'SilentlyContinue\'' . "\r\n"
            . '$arguments = @(' . implode(', ', $arguments) . ')' . "\r\n"
            . '$process = Start-Process'
            . ' -FilePath ' . $literal($php)
            . ' -ArgumentList $arguments'
            . ' -WorkingDirectory ' . $literal($root)
            . ' -RedirectStandardOutput ' . $literal($stdoutLog)
            . ' -RedirectStandardError ' . $literal($stderrLog)
            . ' -WindowStyle Hidden -PassThru' . "\r\n"
            . 'if ($null -eq $process) { throw \'AI report worker did not start.\' }' . "\r\n";

        $utf16 = function_exists('mb_convert_encoding')
            ? mb_convert_encoding($script, 'UTF-16LE', 'UTF-8')
            : iconv('UTF-8', 'UTF-16LE', $script);
        if (!is_string($utf16) || $utf16 === '') {
            throw new \RuntimeException('AI report Windows launcher encoding failed');
        }
        return 'powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand '
            . base64_encode($utf16)
            . ' > NUL 2>&1';
    }

    private function resolvePhpCliBinary(): string
    {
        $binary = trim((string)PHP_BINARY);
        if ($binary === '') {
            return '';
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            $basename = strtolower(basename($binary));
            if ($basename === 'php.exe' && is_file($binary)) {
                return $binary;
            }
            $candidates = [
                dirname($binary) . DIRECTORY_SEPARATOR . 'php.exe',
                rtrim((string)PHP_BINDIR, "\\/") . DIRECTORY_SEPARATOR . 'php.exe',
                dirname($binary, 3) . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'php.exe',
            ];
            foreach (array_values(array_unique($candidates)) as $candidate) {
                if (is_file($candidate)) {
                    return $candidate;
                }
            }
            return '';
        }
        return is_file($binary) ? $binary : '';
    }

    private function acquireDispatchLock(): bool
    {
        try {
            $rows = Db::query("SELECT GET_LOCK('" . self::DISPATCH_LOCK_NAME . "', 0) AS acquired");
            return (int)($rows[0]['acquired'] ?? 0) === 1;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function releaseDispatchLock(): void
    {
        try {
            Db::query("SELECT RELEASE_LOCK('" . self::DISPATCH_LOCK_NAME . "') AS released");
        } catch (Throwable $e) {
            // Connection teardown also releases the advisory lock.
        }
    }

    private function maxConcurrentWorkers(): int
    {
        return $this->configuredInt(
            'llm.report_tasks.max_concurrent',
            'SUXI_AI_REPORT_MAX_CONCURRENT',
            self::DEFAULT_MAX_CONCURRENT,
            1,
            self::MAX_MAX_CONCURRENT
        );
    }

    private function retentionDays(
        ?int $explicit,
        string $configKey,
        string $environmentName,
        int $default
    ): int {
        if ($explicit !== null) {
            return max(1, min(3650, $explicit));
        }
        return $this->configuredInt($configKey, $environmentName, $default, 1, 3650);
    }

    private function configuredInt(
        string $configKey,
        string $environmentName,
        int $default,
        int $minimum,
        int $maximum
    ): int {
        $configured = function_exists('config') ? config($configKey, null) : null;
        if (!is_numeric($configured)) {
            $environmentValue = trim((string)(getenv($environmentName) ?: ''));
            $configured = preg_match('/^\d+$/D', $environmentValue) === 1
                ? (int)$environmentValue
                : $default;
        }
        return max($minimum, min($maximum, (int)$configured));
    }

    private function resolveTrustedInputRevision(int $hotelId, string $reportDate): string
    {
        if ($hotelId <= 0
            || !$this->tableHasColumn('online_daily_data', 'readback_verified')
            || !$this->tableHasColumn('online_daily_data', 'readback_verified_at')
        ) {
            return hash('sha256', 'trusted_input_revision.schema_missing');
        }

        $fields = [
            'COUNT(*) AS row_count',
            'MAX(id) AS max_id',
            'MAX(readback_verified_at) AS latest_readback_verified_at',
        ];
        if ($this->tableHasColumn('online_daily_data', 'update_time')) {
            $fields[] = 'MAX(update_time) AS latest_update_time';
        }
        try {
            $row = Db::name('online_daily_data')
                ->where('system_hotel_id', $hotelId)
                ->where('data_date', $reportDate)
                ->where('readback_verified', 1)
                ->field(implode(',', $fields))
                ->find();
        } catch (Throwable $e) {
            $row = [];
        }

        return hash('sha256', implode('|', [
            'ai_daily_trusted_revision.v1',
            $hotelId,
            $reportDate,
            (int)($row['row_count'] ?? 0),
            (int)($row['max_id'] ?? 0),
            (string)($row['latest_readback_verified_at'] ?? ''),
            (string)($row['latest_update_time'] ?? ''),
        ]));
    }

    /**
     * @param array<int, string> $taskIds
     * @return array<int, string>
     */
    private function taskLogPaths(array $taskIds): array
    {
        $runtime = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'ai_report_tasks';
        if (!is_dir($runtime)) {
            return [];
        }
        $paths = [];
        foreach ($taskIds as $taskId) {
            $taskId = $this->normalizeTaskId($taskId);
            if ($taskId === '') {
                continue;
            }
            foreach (['.stdout.log', '.stderr.log', '.log'] as $suffix) {
                $path = $runtime . DIRECTORY_SEPARATOR . $taskId . $suffix;
                if (is_file($path) && !is_link($path)) {
                    $paths[] = $path;
                }
            }
        }
        return array_values(array_unique($paths));
    }

    /** @return array<int, string> */
    private function orphanTaskLogPaths(string $cutoff, int $limit): array
    {
        $runtime = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'ai_report_tasks';
        if (!is_dir($runtime)) {
            return [];
        }
        $cutoffTimestamp = strtotime($cutoff);
        if ($cutoffTimestamp === false) {
            return [];
        }

        $paths = [];
        $knownTaskIds = [];
        foreach (new \FilesystemIterator($runtime, \FilesystemIterator::SKIP_DOTS) as $file) {
            if (count($paths) >= max(1, min(15000, $limit))
                || !$file->isFile()
                || $file->isLink()
                || $file->getMTime() >= $cutoffTimestamp
                || preg_match('/^(airpt_[A-Za-z0-9_\-]{16,90})\.(?:stdout\.log|stderr\.log|log)$/D', $file->getFilename(), $matches) !== 1
            ) {
                continue;
            }
            $taskId = $this->normalizeTaskId((string)$matches[1]);
            if ($taskId === '') {
                continue;
            }
            if (!array_key_exists($taskId, $knownTaskIds)) {
                $knownTaskIds[$taskId] = (int)Db::name(self::TABLE)->where('task_id', $taskId)->count() > 0;
            }
            if ($knownTaskIds[$taskId] === false) {
                $paths[] = $file->getPathname();
            }
        }
        return $paths;
    }

    /** @param array<int, string> $paths */
    private function removeTaskLogPaths(array $paths): int
    {
        $removed = 0;
        foreach ($paths as $path) {
            if (is_file($path) && !is_link($path) && @unlink($path)) {
                $removed++;
            }
        }
        return $removed;
    }

    private function tableExists(string $table): bool
    {
        if (preg_match('/^[a-z0-9_]+$/i', $table) !== 1) {
            return false;
        }
        try {
            Db::query('SELECT 1 FROM `' . $table . '` LIMIT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        static $tableColumns = [];
        if (preg_match('/^[a-z0-9_]+$/i', $table) !== 1
            || preg_match('/^[a-z0-9_]+$/i', $column) !== 1
        ) {
            return false;
        }
        if (array_key_exists($table, $tableColumns)) {
            return isset($tableColumns[$table][$column]);
        }
        try {
            // MariaDB does not reliably bind placeholders in SHOW COLUMNS
            // ... LIKE through ThinkORM. Read the safe, validated table schema
            // once and resolve individual fields in PHP instead.
            $rows = Db::query('SHOW COLUMNS FROM `' . $table . '`');
            $tableColumns[$table] = array_fill_keys(array_values(array_filter(array_map(
                static fn(array $row): string => (string)($row['Field'] ?? $row['field'] ?? ''),
                $rows
            ))), true);
            return isset($tableColumns[$table][$column]);
        } catch (Throwable $e) {
            $tableColumns[$table] = [];
            return false;
        }
    }

    /** @return array<int, int> */
    private function normalizeHotelIds(array $hotelIds): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $hotelIds),
            static fn(int $id): bool => $id > 0
        )));
    }

    private function normalizeDate(string $date): string
    {
        $timestamp = strtotime(trim($date));
        if ($timestamp === false) {
            throw new \InvalidArgumentException('date is invalid');
        }
        return date('Y-m-d', $timestamp);
    }

    private function normalizeTaskId(string $taskId): string
    {
        $taskId = trim($taskId);
        return preg_match('/^airpt_[A-Za-z0-9_\-]{16,90}$/', $taskId) === 1 ? $taskId : '';
    }

    private function assertTableExists(): void
    {
        try {
            Db::query('SELECT 1 FROM `' . self::TABLE . '` LIMIT 1');
        } catch (Throwable $e) {
            throw new \RuntimeException('ai_report_generation_tasks table does not exist, run database migration first');
        }
    }
}
