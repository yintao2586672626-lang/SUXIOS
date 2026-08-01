<?php
declare(strict_types=1);

namespace app\service;

use app\contract\ManualOnlineFetchTaskStatusStore;
use FilesystemIterator;

final class FileManualOnlineFetchTaskStatusStore implements ManualOnlineFetchTaskStatusStore
{
    private const STATUS_FILENAME = 'status.json';
    private const LOCK_FILENAME = '.status-store.lock';
    private const TERMINAL_STATUSES = ['success', 'partial_success', 'failed', 'no_data', 'unverified'];

    public function __construct(private readonly string $taskRoot)
    {
    }

    public function read(string $taskId): array
    {
        if (!$this->isValidTaskId($taskId)) {
            return [];
        }

        return $this->withLock(LOCK_SH, fn (): array => $this->readUnlocked($taskId), []);
    }

    public function write(string $taskId, array $status): bool
    {
        if (!$this->isValidTaskId($taskId) || (string)($status['task_id'] ?? '') !== $taskId) {
            return false;
        }

        return $this->withLock(LOCK_EX, fn (): bool => $this->writeUnlocked($taskId, $status), false);
    }

    public function update(string $taskId, callable $mutator): array
    {
        if (!$this->isValidTaskId($taskId)) {
            return [];
        }

        return $this->withLock(LOCK_EX, function () use ($taskId, $mutator): array {
            $current = $this->readUnlocked($taskId);
            if ($current === []) {
                return [];
            }
            $next = $mutator($current);
            if (!is_array($next) || (string)($next['task_id'] ?? '') !== $taskId) {
                return [];
            }
            if ($next === $current) {
                return $current;
            }
            return $this->writeUnlocked($taskId, $next) ? $next : [];
        }, []);
    }

    public function delete(string $taskId): void
    {
        if (!$this->isValidTaskId($taskId)) {
            return;
        }
        $this->withLock(LOCK_EX, function () use ($taskId): bool {
            $dir = $this->safeTaskDirectory($taskId);
            if ($dir === null) {
                return false;
            }
            $path = $dir . DIRECTORY_SEPARATOR . self::STATUS_FILENAME;
            if (!is_link($path)) {
                @unlink($path);
            }
            return true;
        }, false);
    }

    public function locator(string $taskId): string
    {
        return $this->isValidTaskId($taskId) ? $this->statusPath($taskId) : '';
    }

    public function cleanupExpired(
        int $retentionSeconds,
        int $staleSeconds,
        int $orphanSeconds,
        int $now,
        bool $dryRun = false
    ): array
    {
        $result = [
            'scanned' => 0,
            'timed_out' => 0,
            'orphaned' => 0,
            'expired' => 0,
            'removed' => 0,
            'kept' => 0,
            'errors' => 0,
        ];
        if ($retentionSeconds <= 0 || $staleSeconds <= 0 || $orphanSeconds <= 0 || $now <= 0 || !is_dir($this->taskRoot)) {
            return $result;
        }

        return $this->withLock(LOCK_EX, function () use (
            $retentionSeconds,
            $staleSeconds,
            $orphanSeconds,
            $now,
            $dryRun,
            $result
        ): array {
            foreach (new FilesystemIterator($this->taskRoot, FilesystemIterator::SKIP_DOTS) as $item) {
                if (!$item->isDir() || $item->isLink() || !$this->isValidTaskId($item->getFilename())) {
                    continue;
                }
                $result['scanned']++;
                $taskId = $item->getFilename();
                $taskDirectory = $this->safeTaskDirectory($taskId);
                if ($taskDirectory === null) {
                    $result['errors']++;
                    $result['kept']++;
                    continue;
                }
                $status = $this->readUnlocked($taskId);
                if ($status === []) {
                    $timestamp = $this->pathTimestamp($taskDirectory);
                    if (!$this->isExpired($timestamp, $orphanSeconds, $now)) {
                        $result['kept']++;
                        continue;
                    }
                    $result['orphaned']++;
                    $result['expired']++;
                    $this->removeExpiredDirectory($taskDirectory, $dryRun, $result);
                    continue;
                }

                $statusName = strtolower(trim((string)($status['status'] ?? 'queued')));
                if (!in_array($statusName, self::TERMINAL_STATUSES, true)) {
                    $timestamp = $this->statusTimestamp($status, ['updated_at', 'started_at', 'created_at']);
                    if ($this->isExpired($timestamp, $staleSeconds, $now)) {
                        $result['timed_out']++;
                        if (!$dryRun) {
                            $finishedAt = date('Y-m-d H:i:s', $now);
                            $status = array_merge($status, [
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
                            if (!$this->writeUnlocked($taskId, $status)) {
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
                $this->removeExpiredDirectory($taskDirectory, $dryRun, $result);
            }
            return $result;
        }, $result);
    }

    private function readUnlocked(string $taskId): array
    {
        $dir = $this->safeTaskDirectory($taskId);
        if ($dir === null) {
            return [];
        }
        $path = $dir . DIRECTORY_SEPARATOR . self::STATUS_FILENAME;
        if (!is_file($path) || is_link($path)) {
            return [];
        }
        $decoded = json_decode((string)file_get_contents($path), true);
        return is_array($decoded) && (string)($decoded['task_id'] ?? '') === $taskId
            ? $decoded
            : [];
    }

    private function writeUnlocked(string $taskId, array $status): bool
    {
        $requestedDir = rtrim($this->taskRoot, "\\/") . DIRECTORY_SEPARATOR . $taskId;
        if (!is_dir($requestedDir) && !mkdir($requestedDir, 0775, true) && !is_dir($requestedDir)) {
            return false;
        }
        $dir = $this->safeTaskDirectory($taskId);
        if ($dir === null) {
            return false;
        }
        $path = $dir . DIRECTORY_SEPARATOR . self::STATUS_FILENAME;
        if (is_link($path)) {
            return false;
        }
        $encoded = json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($encoded)) {
            return false;
        }
        try {
            $suffix = bin2hex(random_bytes(3));
        } catch (\Throwable) {
            $suffix = str_replace('.', '', uniqid('', true));
        }
        $temporaryPath = $path . '.tmp.' . getmypid() . '.' . $suffix;
        if (file_put_contents($temporaryPath, $encoded, LOCK_EX) === false) {
            return false;
        }
        if (!@rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            return false;
        }
        return true;
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
        $taskId = (string)($status['task_id'] ?? '');
        $dir = $this->isValidTaskId($taskId) ? $this->safeTaskDirectory($taskId) : null;
        return $dir === null
            ? 0
            : $this->pathTimestamp($dir . DIRECTORY_SEPARATOR . self::STATUS_FILENAME);
    }

    private function pathTimestamp(string $path): int
    {
        $modifiedAt = @filemtime($path);
        return $modifiedAt === false ? 0 : (int)$modifiedAt;
    }

    private function isExpired(int $timestamp, int $lifetimeSeconds, int $now): bool
    {
        return $timestamp > 0 && $timestamp <= $now && ($now - $timestamp) >= $lifetimeSeconds;
    }

    /** @param array<string, int> $result */
    private function removeExpiredDirectory(string $path, bool $dryRun, array &$result): void
    {
        if ($dryRun) {
            $result['kept']++;
            return;
        }
        if ($this->removeTree($path)) {
            $result['removed']++;
            return;
        }
        $result['errors']++;
        $result['kept']++;
    }

    private function removeTree(string $path): bool
    {
        $canonicalRoot = $this->canonicalTaskRoot();
        if ($canonicalRoot === null) {
            return false;
        }
        $expectedPath = $canonicalRoot . DIRECTORY_SEPARATOR . basename($path);
        if (!$this->treeIsSafe($path, $canonicalRoot, $expectedPath)) {
            return false;
        }
        return $this->removeValidatedTree($path, $canonicalRoot, $expectedPath);
    }

    private function treeIsSafe(string $path, string $canonicalRoot, string $expectedPath): bool
    {
        if (!$this->directoryMatchesExpectedPath($path, $canonicalRoot, $expectedPath)) {
            return false;
        }
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $item) {
            if ($item->isLink()) {
                return false;
            }
            $itemPath = $item->getPathname();
            if ($item->isDir()) {
                $expectedChild = rtrim($expectedPath, "\\/") . DIRECTORY_SEPARATOR . $item->getFilename();
                if (!$this->treeIsSafe($itemPath, $canonicalRoot, $expectedChild)) {
                    return false;
                }
            }
        }
        return true;
    }

    private function removeValidatedTree(string $path, string $canonicalRoot, string $expectedPath): bool
    {
        if (!$this->directoryMatchesExpectedPath($path, $canonicalRoot, $expectedPath)) {
            return false;
        }
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $item) {
            if ($item->isLink()) {
                return false;
            }
            $itemPath = $item->getPathname();
            if ($item->isDir()) {
                $expectedChild = rtrim($expectedPath, "\\/") . DIRECTORY_SEPARATOR . $item->getFilename();
                if (!$this->removeValidatedTree($itemPath, $canonicalRoot, $expectedChild)) {
                    return false;
                }
            } elseif (!@unlink($itemPath)) {
                return false;
            }
        }
        return @rmdir($path);
    }

    private function safeTaskDirectory(string $taskId): ?string
    {
        if (!$this->isValidTaskId($taskId)) {
            return null;
        }
        $canonicalRoot = $this->canonicalTaskRoot();
        if ($canonicalRoot === null) {
            return null;
        }
        $path = rtrim($this->taskRoot, "\\/") . DIRECTORY_SEPARATOR . $taskId;
        $expectedPath = $canonicalRoot . DIRECTORY_SEPARATOR . $taskId;
        return $this->directoryMatchesExpectedPath($path, $canonicalRoot, $expectedPath)
            ? (string)realpath($path)
            : null;
    }

    private function canonicalTaskRoot(): ?string
    {
        $root = realpath($this->taskRoot);
        return $root !== false && is_dir($root) ? rtrim($root, "\\/") : null;
    }

    private function directoryMatchesExpectedPath(string $path, string $canonicalRoot, string $expectedPath): bool
    {
        if (!is_dir($path) || is_link($path)) {
            return false;
        }
        $resolved = realpath($path);
        if ($resolved === false || !$this->pathsEqual($resolved, $expectedPath)) {
            return false;
        }
        $normalizedRoot = $this->normalizePath($canonicalRoot);
        $normalizedResolved = $this->normalizePath($resolved);
        return str_starts_with($normalizedResolved, rtrim($normalizedRoot, '/') . '/');
    }

    private function pathsEqual(string $left, string $right): bool
    {
        return $this->normalizePath($left) === $this->normalizePath($right);
    }

    private function normalizePath(string $path): string
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
    }

    private function statusPath(string $taskId): string
    {
        return rtrim($this->taskRoot, "\\/") . DIRECTORY_SEPARATOR . $taskId . DIRECTORY_SEPARATOR . self::STATUS_FILENAME;
    }

    private function isValidTaskId(string $taskId): bool
    {
        return preg_match('/^manual_[a-z0-9_]+_fetch_\d+_\d{14}_[a-f0-9]{8}$/', $taskId) === 1;
    }

    private function ensureTaskRoot(): bool
    {
        return is_dir($this->taskRoot)
            || (mkdir($this->taskRoot, 0775, true) && is_dir($this->taskRoot));
    }

    private function withLock(int $operation, callable $callback, mixed $fallback): mixed
    {
        if (!$this->ensureTaskRoot()) {
            return $fallback;
        }
        $handle = @fopen(rtrim($this->taskRoot, "\\/") . DIRECTORY_SEPARATOR . self::LOCK_FILENAME, 'c+');
        if (!is_resource($handle)) {
            return $fallback;
        }
        try {
            if (!flock($handle, $operation)) {
                return $fallback;
            }
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
