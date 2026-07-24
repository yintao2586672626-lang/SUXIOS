<?php
declare(strict_types=1);

namespace app\service;

use app\contract\ManualOnlineFetchTaskStatusStore;
use FilesystemIterator;
use RuntimeException;
use think\facade\Db;
use Throwable;

final class DatabaseManualOnlineFetchTaskStatusStore implements ManualOnlineFetchTaskStatusStore
{
    private const TABLE = 'manual_online_fetch_task_statuses';
    private const TERMINAL_STATUSES = ['success', 'partial_success', 'failed', 'no_data', 'unverified'];
    private const FORBIDDEN_STATUS_FIELDS = [
        'authorization', 'authorization_env', 'cookie', 'cookies', 'token', 'api_url', 'input', 'status_file', 'log', 'body',
    ];

    public function __construct(private readonly string $taskRoot)
    {
        try {
            Db::name(self::TABLE)->whereRaw('1 = 0')->select();
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Database manual fetch task status store is unavailable; run database/migrations/20260717_create_manual_online_fetch_task_statuses.sql first',
                0,
                $exception
            );
        }
    }

    public function read(string $taskId): array
    {
        if (!$this->isValidTaskId($taskId)) {
            return [];
        }
        $row = Db::name(self::TABLE)->where('task_id', $taskId)->find();
        return is_array($row) ? $this->decodeStatus($row, $taskId) : [];
    }

    public function write(string $taskId, array $status): bool
    {
        if (!$this->validStatus($taskId, $status)) {
            return false;
        }
        Db::transaction(function () use ($taskId, $status): void {
            $row = Db::name(self::TABLE)->where('task_id', $taskId)->lock(true)->find();
            $payload = $this->databasePayload($status, is_array($row) ? (string)($row['created_at'] ?? '') : '');
            if (is_array($row)) {
                unset($payload['created_at']);
                Db::name(self::TABLE)->where('id', (int)$row['id'])->update($payload);
                return;
            }
            Db::name(self::TABLE)->insert($payload);
        });
        return true;
    }

    public function update(string $taskId, callable $mutator): array
    {
        if (!$this->isValidTaskId($taskId)) {
            return [];
        }
        return Db::transaction(function () use ($taskId, $mutator): array {
            $row = Db::name(self::TABLE)->where('task_id', $taskId)->lock(true)->find();
            if (!is_array($row)) {
                return [];
            }
            $current = $this->decodeStatus($row, $taskId);
            if ($current === []) {
                return [];
            }
            $next = $mutator($current);
            if (!is_array($next) || !$this->validStatus($taskId, $next)) {
                return [];
            }
            if ($next === $current) {
                return $current;
            }
            $payload = $this->databasePayload($next, (string)($row['created_at'] ?? ''));
            unset($payload['created_at']);
            Db::name(self::TABLE)->where('id', (int)$row['id'])->update($payload);
            return $next;
        });
    }

    public function delete(string $taskId): void
    {
        if ($this->isValidTaskId($taskId)) {
            Db::name(self::TABLE)->where('task_id', $taskId)->delete();
        }
    }

    public function locator(string $taskId): string
    {
        return $this->isValidTaskId($taskId) ? 'database://manual-fetch/' . $taskId : '';
    }

    public function cleanupExpired(
        int $retentionSeconds,
        int $staleSeconds,
        int $orphanSeconds,
        int $now,
        bool $dryRun = false
    ): array {
        $result = [
            'scanned' => 0,
            'timed_out' => 0,
            'orphaned' => 0,
            'expired' => 0,
            'removed' => 0,
            'kept' => 0,
            'errors' => 0,
        ];
        if ($retentionSeconds <= 0 || $staleSeconds <= 0 || $orphanSeconds <= 0 || $now <= 0) {
            return $result;
        }

        $rows = Db::name(self::TABLE)->order('id', 'asc')->select()->toArray();
        $knownTaskIds = [];
        foreach ($rows as $row) {
            $taskId = trim((string)($row['task_id'] ?? ''));
            if (!$this->isValidTaskId($taskId)) {
                $result['errors']++;
                continue;
            }
            $knownTaskIds[$taskId] = true;
            $result['scanned']++;
            $status = $this->decodeStatus($row, $taskId);
            if ($status === []) {
                $result['errors']++;
                $result['kept']++;
                continue;
            }
            $statusName = strtolower(trim((string)($status['status'] ?? 'queued')));
            if (!in_array($statusName, self::TERMINAL_STATUSES, true)) {
                $timestamp = $this->statusTimestamp($status, ['updated_at', 'started_at', 'created_at']);
                if ($this->isExpired($timestamp, $staleSeconds, $now)) {
                    if ($dryRun) {
                        $result['timed_out']++;
                    } else {
                        try {
                            if ($this->transitionStaleTaskToTimeout($taskId, $staleSeconds, $now)) {
                                $result['timed_out']++;
                            }
                        } catch (Throwable) {
                            $result['errors']++;
                        }
                    }
                }
                $result['kept']++;
                continue;
            }

            $timestamp = $this->statusTimestamp($status, ['finished_at', 'updated_at', 'created_at']);
            if (!$this->isExpired($timestamp, $retentionSeconds, $now)) {
                $result['kept']++;
                continue;
            }
            $result['expired']++;
            if ($dryRun) {
                $result['kept']++;
                continue;
            }
            if (!$this->removeTaskDirectory($taskId)) {
                $result['errors']++;
                $result['kept']++;
                continue;
            }
            Db::name(self::TABLE)->where('task_id', $taskId)->delete();
            $result['removed']++;
        }

        if (!is_dir($this->taskRoot)) {
            return $result;
        }
        foreach (new FilesystemIterator($this->taskRoot, FilesystemIterator::SKIP_DOTS) as $item) {
            $taskId = $item->getFilename();
            if (!$item->isDir() || $item->isLink() || !$this->isValidTaskId($taskId) || isset($knownTaskIds[$taskId])) {
                continue;
            }
            if (!$this->isExpired((int)$item->getMTime(), $orphanSeconds, $now)) {
                continue;
            }
            $result['scanned']++;
            $result['orphaned']++;
            $result['expired']++;
            if ($dryRun) {
                $result['kept']++;
            } elseif ($this->removeTaskDirectory($taskId)) {
                $result['removed']++;
            } else {
                $result['errors']++;
                $result['kept']++;
            }
        }
        return $result;
    }

    /**
     * Re-check the lifecycle while holding the row lock. A worker may have
     * reached a terminal state after cleanup read its initial snapshot; that
     * terminal result must never be overwritten by a timeout.
     */
    private function transitionStaleTaskToTimeout(string $taskId, int $staleSeconds, int $now): bool
    {
        $transitioned = false;
        $updated = $this->update($taskId, function (array $current) use ($staleSeconds, $now, &$transitioned): array {
            $statusName = strtolower(trim((string)($current['status'] ?? 'queued')));
            if (in_array($statusName, self::TERMINAL_STATUSES, true)) {
                return $current;
            }
            $timestamp = $this->statusTimestamp($current, ['updated_at', 'started_at', 'created_at']);
            if (!$this->isExpired($timestamp, $staleSeconds, $now)) {
                return $current;
            }

            $finishedAt = date('Y-m-d H:i:s', $now);
            $transitioned = true;
            return array_merge($current, [
                'status' => 'failed',
                'stage' => 'timeout',
                'status_text' => '已超时',
                'message' => '后台任务超过最长执行时间，请查看失败原因后重试',
                'progress_percent' => 100,
                'quality_status' => 'collection_failed',
                'finished_at' => $finishedAt,
                'updated_at' => $finishedAt,
                'done' => true,
            ]);
        });

        return $updated !== [] && $transitioned;
    }

    private function validStatus(string $taskId, array $status): bool
    {
        if (!$this->isValidTaskId($taskId) || (string)($status['task_id'] ?? '') !== $taskId) {
            return false;
        }
        foreach (self::FORBIDDEN_STATUS_FIELDS as $field) {
            if (array_key_exists($field, $status)) {
                throw new RuntimeException('Manual fetch task status cannot persist sensitive launch field: ' . $field);
            }
        }
        return true;
    }

    /** @return array<string,mixed> */
    private function databasePayload(array $status, string $existingCreatedAt): array
    {
        $now = date('Y-m-d H:i:s');
        $createdAt = trim((string)($status['created_at'] ?? $existingCreatedAt));
        if ($createdAt === '') {
            $createdAt = $now;
        }
        $updatedAt = trim((string)($status['updated_at'] ?? '')) ?: $now;
        $encoded = json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return [
            'task_id' => (string)$status['task_id'],
            'hotel_id' => max(0, (int)($status['hotel_id'] ?? 0)),
            'user_id' => max(0, (int)($status['user_id'] ?? 0)) ?: null,
            'platform' => substr(strtolower(trim((string)($status['platform'] ?? ''))), 0, 20),
            'task_kind' => substr(strtolower(trim((string)($status['task_kind'] ?? ''))), 0, 60),
            'status' => substr(strtolower(trim((string)($status['status'] ?? 'queued'))), 0, 40),
            'stage' => substr(strtolower(trim((string)($status['stage'] ?? 'created'))), 0, 60),
            'status_json' => $encoded,
            'started_at' => $this->nullableDate($status['started_at'] ?? null),
            'finished_at' => $this->nullableDate($status['finished_at'] ?? null),
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ];
    }

    private function decodeStatus(array $row, string $taskId): array
    {
        try {
            $decoded = json_decode((string)($row['status_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }
        return is_array($decoded) && (string)($decoded['task_id'] ?? '') === $taskId ? $decoded : [];
    }

    private function nullableDate(mixed $value): ?string
    {
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    /** @param list<string> $fields */
    private function statusTimestamp(array $status, array $fields): int
    {
        foreach ($fields as $field) {
            $value = trim((string)($status[$field] ?? ''));
            $timestamp = $value !== '' ? strtotime($value) : false;
            if ($timestamp !== false) {
                return (int)$timestamp;
            }
        }
        return 0;
    }

    private function isExpired(int $timestamp, int $lifetimeSeconds, int $now): bool
    {
        return $timestamp > 0 && $timestamp <= $now && ($now - $timestamp) >= $lifetimeSeconds;
    }

    private function removeTaskDirectory(string $taskId): bool
    {
        if (!$this->isValidTaskId($taskId) || !is_dir($this->taskRoot)) {
            return true;
        }
        $root = realpath($this->taskRoot);
        $path = realpath(rtrim($this->taskRoot, "\\/") . DIRECTORY_SEPARATOR . $taskId);
        if ($root === false || $path === false) {
            return true;
        }
        $normalizedRoot = rtrim($this->normalizePath($root), '/');
        $normalizedPath = $this->normalizePath($path);
        if ($normalizedPath !== $normalizedRoot . '/' . $taskId || is_link($path)) {
            return false;
        }
        return $this->removeValidatedTree($path, $normalizedRoot);
    }

    private function removeValidatedTree(string $path, string $normalizedRoot): bool
    {
        $normalizedPath = $this->normalizePath((string)realpath($path));
        if ($normalizedPath === '' || !str_starts_with($normalizedPath, $normalizedRoot . '/') || is_link($path)) {
            return false;
        }
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $item) {
            if ($item->isLink()) {
                return false;
            }
            if ($item->isDir()) {
                if (!$this->removeValidatedTree($item->getPathname(), $normalizedRoot)) {
                    return false;
                }
            } elseif (!@unlink($item->getPathname())) {
                return false;
            }
        }
        return @rmdir($path);
    }

    private function normalizePath(string $path): string
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
    }

    private function isValidTaskId(string $taskId): bool
    {
        return preg_match('/^manual_[a-z0-9_]+_fetch_\d+_\d{14}_[a-f0-9]{8}$/', $taskId) === 1;
    }
}
