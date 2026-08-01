<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;
use think\facade\Log;

final class LoginRateLimiter
{
    public const WINDOW_SECONDS = 300;
    public const IDENTITY_LIMIT = 10;
    public const USERNAME_LIMIT = 25;
    public const IP_LIMIT = 40;

    private const TABLE = 'login_rate_limit_counters';
    private const EXPIRY_GRACE_SECONDS = 5;
    private const CLEANUP_LIMIT = 200;

    /** @var array<int, string> */
    private const SCOPE_ORDER = ['ip', 'username', 'identity'];

    /** @var \Closure(string): mixed|null */
    private ?\Closure $cacheRead = null;

    /** @var \Closure(string, int, int): void|null */
    private ?\Closure $cacheWrite = null;

    /** @var \Closure(string): void|null */
    private ?\Closure $cacheDelete = null;

    /** @var \Closure(): int */
    private \Closure $clock;

    private bool $databaseBacked;

    public function __construct(
        ?callable $cacheRead = null,
        ?callable $cacheWrite = null,
        ?callable $cacheDelete = null,
        ?callable $clock = null
    ) {
        $providedStoreCallbacks = count(array_filter(
            [$cacheRead, $cacheWrite, $cacheDelete],
            static fn(mixed $callback): bool => $callback !== null
        ));
        if ($providedStoreCallbacks !== 0 && $providedStoreCallbacks !== 3) {
            throw new \InvalidArgumentException('Login rate-limit custom store requires read, write and delete callbacks.');
        }

        $this->databaseBacked = $providedStoreCallbacks === 0;
        if (!$this->databaseBacked) {
            $this->cacheRead = \Closure::fromCallable($cacheRead);
            $this->cacheWrite = \Closure::fromCallable($cacheWrite);
            $this->cacheDelete = \Closure::fromCallable($cacheDelete);
        }
        $this->clock = $clock !== null
            ? \Closure::fromCallable($clock)
            : static fn(): int => time();
    }

    /**
     * @return array{allowed: bool, scope: string, retry_after: int, ip_failures: int, username_failures: int, identity_failures: int, reservation_bucket: int|null}
     */
    public function inspect(string $ip, string $username): array
    {
        $now = ($this->clock)();
        $bucket = $this->bucket($now);
        if ($this->databaseBacked) {
            return $this->snapshotFromCounts(
                $this->databaseReadCounts($this->subjects($ip, $username), $bucket, $now),
                $now,
                $bucket
            );
        }

        return $this->synchronized(function () use ($ip, $username, $now, $bucket): array {
            return $this->snapshot($this->cacheKeys($ip, $username, $bucket), $now, $bucket);
        });
    }

    /**
     * Atomically reserves one login attempt before password verification.
     *
     * @return array{allowed: bool, scope: string, retry_after: int, ip_failures: int, username_failures: int, identity_failures: int, reservation_bucket: int|null}
     */
    public function consumeAttempt(string $ip, string $username): array
    {
        $now = ($this->clock)();
        $bucket = $this->bucket($now);
        if ($this->databaseBacked) {
            return $this->databaseConsumeAttempt($this->subjects($ip, $username), $now, $bucket);
        }

        return $this->synchronized(function () use ($ip, $username, $now, $bucket): array {
            $keys = $this->cacheKeys($ip, $username, $bucket);
            $snapshot = $this->snapshot($keys, $now, $bucket);
            if (!$snapshot['allowed']) {
                return $snapshot;
            }

            foreach ($keys as $key) {
                $this->writeCacheCount($key, $this->cacheFailureCount($key) + 1, $now, $bucket);
            }

            $reserved = $this->snapshot($keys, $now, $bucket);
            $reserved['allowed'] = true;
            $reserved['scope'] = 'none';
            $reserved['reservation_bucket'] = $bucket;
            return $reserved;
        });
    }

    public function recordFailure(string $ip, string $username): void
    {
        $now = ($this->clock)();
        $bucket = $this->bucket($now);
        if ($this->databaseBacked) {
            $this->databaseIncrement($this->subjects($ip, $username), $now, $bucket);
            return;
        }

        $this->synchronized(function () use ($ip, $username, $now, $bucket): void {
            foreach ($this->cacheKeys($ip, $username, $bucket) as $key) {
                $this->writeCacheCount($key, $this->cacheFailureCount($key) + 1, $now, $bucket);
            }
        });
    }

    public function clearIdentityFailures(string $ip, string $username): void
    {
        $now = ($this->clock)();
        $bucket = $this->bucket($now);
        if ($this->databaseBacked) {
            $subjects = $this->subjects($ip, $username);
            Db::name(self::TABLE)
                ->where('scope_type', 'identity')
                ->where('subject_hash', $subjects['identity'])
                ->where('bucket_start', $bucket)
                ->delete();
            return;
        }

        $this->synchronized(function () use ($ip, $username, $bucket): void {
            $keys = $this->cacheKeys($ip, $username, $bucket);
            $this->deleteCacheKey($keys['identity']);
        });
    }

    public function releaseSuccessfulAttempt(
        string $ip,
        string $username,
        ?int $reservationBucket = null
    ): void {
        $now = ($this->clock)();
        $bucket = $reservationBucket !== null && $reservationBucket >= 0
            ? $reservationBucket
            : $this->bucket($now);
        if ($this->databaseBacked) {
            $this->databaseRelease($this->subjects($ip, $username), $now, $bucket);
            return;
        }

        $this->synchronized(function () use ($ip, $username, $now, $bucket): void {
            foreach ($this->cacheKeys($ip, $username, $bucket) as $key) {
                $count = $this->cacheFailureCount($key);
                if ($count <= 1) {
                    $this->deleteCacheKey($key);
                    continue;
                }
                $this->writeCacheCount($key, $count - 1, $now, $bucket);
            }
        });
    }

    private function bucket(int $now): int
    {
        return intdiv($now, self::WINDOW_SECONDS);
    }

    /** @return array{ip: string, username: string, identity: string} */
    private function subjects(string $ip, string $username): array
    {
        $normalizedIp = trim($ip) !== '' ? trim($ip) : 'unknown';
        $normalizedUsername = mb_strtolower(trim($username));

        return [
            'ip' => hash('sha256', $normalizedIp),
            'username' => hash('sha256', $normalizedUsername),
            'identity' => hash('sha256', $normalizedIp . "\0" . $normalizedUsername),
        ];
    }

    /** @return array{ip: string, username: string, identity: string} */
    private function cacheKeys(string $ip, string $username, int $bucket): array
    {
        $subjects = $this->subjects($ip, $username);
        return [
            'ip' => sprintf('login_rate_ip_%s_%d', $subjects['ip'], $bucket),
            'username' => sprintf('login_rate_username_%s_%d', $subjects['username'], $bucket),
            'identity' => sprintf('login_rate_identity_%s_%d', $subjects['identity'], $bucket),
        ];
    }

    /**
     * @param array{ip: string, username: string, identity: string} $keys
     * @return array{allowed: bool, scope: string, retry_after: int, ip_failures: int, username_failures: int, identity_failures: int, reservation_bucket: int|null}
     */
    private function snapshot(array $keys, int $now, int $bucket): array
    {
        return $this->snapshotFromCounts([
            'ip' => $this->cacheFailureCount($keys['ip']),
            'username' => $this->cacheFailureCount($keys['username']),
            'identity' => $this->cacheFailureCount($keys['identity']),
        ], $now, $bucket);
    }

    /**
     * @param array{ip: int, username: int, identity: int} $counts
     * @return array{allowed: bool, scope: string, retry_after: int, ip_failures: int, username_failures: int, identity_failures: int, reservation_bucket: int|null}
     */
    private function snapshotFromCounts(array $counts, int $now, int $bucket): array
    {
        $scope = $counts['identity'] >= self::IDENTITY_LIMIT
            ? 'identity'
            : ($counts['username'] >= self::USERNAME_LIMIT
                ? 'username'
                : ($counts['ip'] >= self::IP_LIMIT ? 'ip' : 'none'));

        return [
            'allowed' => $scope === 'none',
            'scope' => $scope,
            'retry_after' => max(1, (($bucket + 1) * self::WINDOW_SECONDS) - $now),
            'ip_failures' => $counts['ip'],
            'username_failures' => $counts['username'],
            'identity_failures' => $counts['identity'],
            'reservation_bucket' => null,
        ];
    }

    /**
     * @param array{ip: string, username: string, identity: string} $subjects
     * @return array{allowed: bool, scope: string, retry_after: int, ip_failures: int, username_failures: int, identity_failures: int, reservation_bucket: int|null}
     */
    private function databaseConsumeAttempt(array $subjects, int $now, int $bucket): array
    {
        $result = Db::transaction(function () use ($subjects, $now, $bucket): array {
            $this->databaseEnsureRowsLocked($subjects, $bucket);
            $counts = $this->databaseLockedCounts($subjects, $bucket);
            $snapshot = $this->snapshotFromCounts($counts, $now, $bucket);
            if (!$snapshot['allowed']) {
                $this->databaseDeleteZeroRows($subjects, $bucket);
                return $snapshot;
            }

            foreach (self::SCOPE_ORDER as $scope) {
                Db::execute(
                    'UPDATE `' . self::TABLE . '` SET `attempt_count` = `attempt_count` + 1 '
                    . 'WHERE `scope_type` = ? AND `subject_hash` = ? AND `bucket_start` = ?',
                    [$scope, $subjects[$scope], $bucket]
                );
                $counts[$scope]++;
            }

            $reserved = $this->snapshotFromCounts($counts, $now, $bucket);
            $reserved['allowed'] = true;
            $reserved['scope'] = 'none';
            $reserved['reservation_bucket'] = $bucket;
            return $reserved;
        });

        $this->cleanupExpiredRows($now);
        return $result;
    }

    /** @param array{ip: string, username: string, identity: string} $subjects */
    private function databaseIncrement(array $subjects, int $now, int $bucket): void
    {
        Db::transaction(function () use ($subjects, $bucket): void {
            $this->databaseEnsureRowsLocked($subjects, $bucket);
            foreach (self::SCOPE_ORDER as $scope) {
                Db::execute(
                    'UPDATE `' . self::TABLE . '` SET `attempt_count` = `attempt_count` + 1 '
                    . 'WHERE `scope_type` = ? AND `subject_hash` = ? AND `bucket_start` = ?',
                    [$scope, $subjects[$scope], $bucket]
                );
            }
        });
        $this->cleanupExpiredRows($now);
    }

    /** @param array{ip: string, username: string, identity: string} $subjects */
    private function databaseRelease(array $subjects, int $now, int $bucket): void
    {
        Db::transaction(function () use ($subjects, $bucket): void {
            foreach (self::SCOPE_ORDER as $scope) {
                $rows = Db::query(
                    'SELECT `attempt_count` FROM `' . self::TABLE . '` '
                    . 'WHERE `scope_type` = ? AND `subject_hash` = ? AND `bucket_start` = ?'
                    . ($this->isSqlite() ? '' : ' FOR UPDATE'),
                    [$scope, $subjects[$scope], $bucket]
                );
                $count = max(0, (int)($rows[0]['attempt_count'] ?? 0));
                if ($count <= 1) {
                    Db::execute(
                        'DELETE FROM `' . self::TABLE . '` '
                        . 'WHERE `scope_type` = ? AND `subject_hash` = ? AND `bucket_start` = ?',
                        [$scope, $subjects[$scope], $bucket]
                    );
                    continue;
                }
                Db::execute(
                    'UPDATE `' . self::TABLE . '` SET `attempt_count` = `attempt_count` - 1 '
                    . 'WHERE `scope_type` = ? AND `subject_hash` = ? AND `bucket_start` = ?',
                    [$scope, $subjects[$scope], $bucket]
                );
            }
        });
        $this->cleanupExpiredRows($now);
    }

    /**
     * @param array{ip: string, username: string, identity: string} $subjects
     * @return array{ip: int, username: int, identity: int}
     */
    private function databaseReadCounts(array $subjects, int $bucket, int $now): array
    {
        $counts = ['ip' => 0, 'username' => 0, 'identity' => 0];
        foreach (self::SCOPE_ORDER as $scope) {
            $rows = Db::query(
                'SELECT `attempt_count` FROM `' . self::TABLE . '` '
                . 'WHERE `scope_type` = ? AND `subject_hash` = ? AND `bucket_start` = ? '
                . 'AND `expires_at` > ? LIMIT 1',
                [$scope, $subjects[$scope], $bucket, $now]
            );
            $counts[$scope] = max(0, (int)($rows[0]['attempt_count'] ?? 0));
        }
        return $counts;
    }

    /** @param array{ip: string, username: string, identity: string} $subjects */
    private function databaseEnsureRowsLocked(array $subjects, int $bucket): void
    {
        $expiresAt = (($bucket + 1) * self::WINDOW_SECONDS) + self::EXPIRY_GRACE_SECONDS;
        foreach (self::SCOPE_ORDER as $scope) {
            if ($this->isSqlite()) {
                Db::execute(
                    'INSERT INTO `' . self::TABLE . '` '
                    . '(`scope_type`, `subject_hash`, `bucket_start`, `attempt_count`, `expires_at`) '
                    . 'VALUES (?, ?, ?, 0, ?) '
                    . 'ON CONFLICT (`scope_type`, `subject_hash`, `bucket_start`) DO UPDATE '
                    . 'SET `expires_at` = MAX(`expires_at`, excluded.`expires_at`)',
                    [$scope, $subjects[$scope], $bucket, $expiresAt]
                );
                continue;
            }
            Db::execute(
                'INSERT INTO `' . self::TABLE . '` '
                . '(`scope_type`, `subject_hash`, `bucket_start`, `attempt_count`, `expires_at`) '
                . 'VALUES (?, ?, ?, 0, ?) '
                . 'ON DUPLICATE KEY UPDATE `expires_at` = GREATEST(`expires_at`, VALUES(`expires_at`))',
                [$scope, $subjects[$scope], $bucket, $expiresAt]
            );
        }
    }

    /**
     * @param array{ip: string, username: string, identity: string} $subjects
     * @return array{ip: int, username: int, identity: int}
     */
    private function databaseLockedCounts(array $subjects, int $bucket): array
    {
        $counts = ['ip' => 0, 'username' => 0, 'identity' => 0];
        foreach (self::SCOPE_ORDER as $scope) {
            $rows = Db::query(
                'SELECT `attempt_count` FROM `' . self::TABLE . '` '
                . 'WHERE `scope_type` = ? AND `subject_hash` = ? AND `bucket_start` = ?',
                [$scope, $subjects[$scope], $bucket]
            );
            $counts[$scope] = max(0, (int)($rows[0]['attempt_count'] ?? 0));
        }
        return $counts;
    }

    /** @param array{ip: string, username: string, identity: string} $subjects */
    private function databaseDeleteZeroRows(array $subjects, int $bucket): void
    {
        foreach (self::SCOPE_ORDER as $scope) {
            Db::execute(
                'DELETE FROM `' . self::TABLE . '` '
                . 'WHERE `scope_type` = ? AND `subject_hash` = ? AND `bucket_start` = ? AND `attempt_count` = 0',
                [$scope, $subjects[$scope], $bucket]
            );
        }
    }

    private function cleanupExpiredRows(int $now): void
    {
        try {
            Db::execute(
                'DELETE FROM `' . self::TABLE . '` WHERE `expires_at` <= ?'
                . ($this->isSqlite() ? '' : ' LIMIT ' . self::CLEANUP_LIMIT),
                [$now]
            );
        } catch (\Throwable $exception) {
            Log::warning('Expired login rate-limit cleanup failed.', [
                'exception_type' => get_debug_type($exception),
            ]);
        }
    }

    private function cacheFailureCount(string $key): int
    {
        if ($this->cacheRead === null) {
            throw new \RuntimeException('Login rate-limit custom store is unavailable.');
        }
        return max(0, (int)(($this->cacheRead)($key) ?? 0));
    }

    private function isSqlite(): bool
    {
        return strtolower((string)config('database.default', 'mysql')) === 'sqlite';
    }

    private function writeCacheCount(string $key, int $value, int $now, int $bucket): void
    {
        if ($this->cacheWrite === null) {
            throw new \RuntimeException('Login rate-limit custom store is unavailable.');
        }
        $ttl = max(1, ((($bucket + 1) * self::WINDOW_SECONDS) + self::EXPIRY_GRACE_SECONDS) - $now);
        ($this->cacheWrite)($key, max(0, $value), $ttl);
    }

    private function deleteCacheKey(string $key): void
    {
        if ($this->cacheDelete === null) {
            throw new \RuntimeException('Login rate-limit custom store is unavailable.');
        }
        ($this->cacheDelete)($key);
    }

    private function synchronized(callable $operation): mixed
    {
        $lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'suxi_login_rate_test_store_' . hash('sha256', dirname(__DIR__, 2)) . '.lock';
        $handle = @fopen($lockPath, 'c+');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Login rate-limit custom-store lock is unavailable.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Login rate-limit custom-store lock could not be acquired.');
            }
            return $operation();
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
