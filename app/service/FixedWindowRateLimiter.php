<?php
declare(strict_types=1);

namespace app\service;

use Closure;

final class FixedWindowRateLimiter
{
    private const EXPIRY_GRACE_SECONDS = 5;
    private const LOCK_SHARDS = 64;

    /** @var Closure(string): mixed */
    private Closure $storeRead;

    /** @var Closure(string, int, int): mixed */
    private Closure $storeWrite;

    /** @var Closure(): int */
    private Closure $clock;

    private string $lockDirectory;

    public function __construct(
        ?callable $storeRead = null,
        ?callable $storeWrite = null,
        ?callable $clock = null,
        ?string $lockDirectory = null
    ) {
        if (($storeRead === null) !== ($storeWrite === null)) {
            throw new \InvalidArgumentException('Fixed-window rate-limit store requires both read and write callbacks.');
        }

        $this->storeRead = $storeRead !== null
            ? Closure::fromCallable($storeRead)
            : static fn(string $key): mixed => cache($key);
        $this->storeWrite = $storeWrite !== null
            ? Closure::fromCallable($storeWrite)
            : static fn(string $key, int $value, int $ttl): mixed => cache($key, $value, $ttl);
        $this->clock = $clock !== null
            ? Closure::fromCallable($clock)
            : static fn(): int => time();

        $defaultLockDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'suxi_fixed_window_rate_limit_' . substr(hash('sha256', dirname(__DIR__, 2)), 0, 16);
        $this->lockDirectory = rtrim($lockDirectory ?? $defaultLockDirectory, '\\/');
        if ($this->lockDirectory === '') {
            throw new \InvalidArgumentException('Fixed-window rate-limit lock directory is required.');
        }
    }

    /**
     * @return array{allowed: bool, count: int, limit: int, window: int, retry_after: int}
     */
    public function consume(string $key, int $limit, int $window): array
    {
        $key = trim($key);
        if ($key === '') {
            throw new \InvalidArgumentException('Fixed-window rate-limit key is required.');
        }
        if ($limit < 1 || $window < 1) {
            throw new \InvalidArgumentException('Fixed-window rate-limit limit and window must be positive.');
        }

        $now = ($this->clock)();
        if ($now < 0) {
            throw new \RuntimeException('Fixed-window rate-limit clock returned an invalid timestamp.');
        }

        $bucket = intdiv($now, $window);
        $retryAfter = max(1, (($bucket + 1) * $window) - $now);
        $cacheKey = $key . '_' . $bucket;

        return $this->synchronized($cacheKey, function () use (
            $cacheKey,
            $limit,
            $window,
            $retryAfter
        ): array {
            $count = $this->readCount($cacheKey);
            if ($count >= $limit) {
                return [
                    'allowed' => false,
                    'count' => $count,
                    'limit' => $limit,
                    'window' => $window,
                    'retry_after' => $retryAfter,
                ];
            }

            $count++;
            $written = ($this->storeWrite)(
                $cacheKey,
                $count,
                $retryAfter + self::EXPIRY_GRACE_SECONDS
            );
            if ($written === false) {
                throw new \RuntimeException('Fixed-window rate-limit counter write failed.');
            }

            return [
                'allowed' => true,
                'count' => $count,
                'limit' => $limit,
                'window' => $window,
                'retry_after' => $retryAfter,
            ];
        });
    }

    private function readCount(string $key): int
    {
        $value = ($this->storeRead)($key);
        if ($value === null) {
            return 0;
        }
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int)$value;
        }

        throw new \RuntimeException('Fixed-window rate-limit counter is invalid.');
    }

    private function synchronized(string $key, callable $operation): mixed
    {
        $this->ensureLockDirectory();
        $shard = hexdec(substr(hash('sha256', $key), 0, 6)) % self::LOCK_SHARDS;
        $lockPath = $this->lockDirectory . DIRECTORY_SEPARATOR . sprintf('shard-%02d.lock', $shard);
        $handle = @fopen($lockPath, 'c+b');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Fixed-window rate-limit lock is unavailable.');
        }

        try {
            if (!@flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Fixed-window rate-limit lock could not be acquired.');
            }

            return $operation();
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function ensureLockDirectory(): void
    {
        if (is_dir($this->lockDirectory)) {
            return;
        }
        if (file_exists($this->lockDirectory)) {
            if (is_dir($this->lockDirectory)) {
                return;
            }
            throw new \RuntimeException('Fixed-window rate-limit lock directory is unavailable.');
        }
        if (!@mkdir($this->lockDirectory, 0700, true) && !is_dir($this->lockDirectory)) {
            throw new \RuntimeException('Fixed-window rate-limit lock directory could not be created.');
        }
    }
}
