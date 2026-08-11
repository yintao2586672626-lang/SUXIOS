<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use RuntimeException;
use think\facade\Cache;

/**
 * Serializes read-modify-write updates to one hotel's auto-fetch status.
 *
 * The application currently supports single-instance deployment only. The
 * process-shared file lock therefore protects the file-cache value across web
 * requests, scheduler commands, and background workers on that instance.
 */
final class OnlineDataAutoFetchStatusStore
{
    public const TTL_SECONDS = 86400 * 30;

    /** @var Closure(int):mixed */
    private Closure $statusLoader;

    /** @var Closure(int,array<string,mixed>,int):mixed */
    private Closure $statusWriter;

    private string $lockDirectory;

    public function __construct(
        ?callable $statusLoader = null,
        ?callable $statusWriter = null,
        ?string $lockDirectory = null
    ) {
        if (($statusLoader === null) !== ($statusWriter === null)) {
            throw new \InvalidArgumentException(
                'Online auto-fetch status store requires both loader and writer callbacks.'
            );
        }

        $this->statusLoader = $statusLoader !== null
            ? Closure::fromCallable($statusLoader)
            : static fn(int $hotelId): mixed =>
                Cache::get("online_data_auto_fetch_status_{$hotelId}", []);
        $this->statusWriter = $statusWriter !== null
            ? Closure::fromCallable($statusWriter)
            : static fn(int $hotelId, array $status, int $ttl): bool =>
                Cache::set("online_data_auto_fetch_status_{$hotelId}", $status, $ttl);

        $defaultLockDirectory = LocalStatePathPolicy::scopedLockDirectory('auto-fetch-status');
        if ($defaultLockDirectory === '') {
            $defaultLockDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
                . 'suxi_auto_fetch_status_' . substr(hash('sha256', dirname(__DIR__, 2)), 0, 16);
        }
        $this->lockDirectory = rtrim($lockDirectory ?? $defaultLockDirectory, '\\/');
        if ($this->lockDirectory === '') {
            throw new \InvalidArgumentException('Online auto-fetch status lock directory is required.');
        }
    }

    /**
     * Re-reads inside the hotel lock and writes only the callback's merged
     * result, so a caller can never commit a snapshot loaded before the lock.
     *
     * @param callable(array<string,mixed>):array<string,mixed> $mutator
     * @return array<string,mixed>
     */
    public function mutate(
        int $hotelId,
        callable $mutator,
        int $ttl = self::TTL_SECONDS
    ): array {
        if ($hotelId <= 0) {
            throw new \InvalidArgumentException('Online auto-fetch status hotel id must be positive.');
        }
        if ($ttl <= 0) {
            throw new \InvalidArgumentException('Online auto-fetch status TTL must be positive.');
        }

        return $this->synchronized($hotelId, function () use ($hotelId, $mutator, $ttl): array {
            $current = ($this->statusLoader)($hotelId);
            $current = is_array($current) ? $current : [];
            $updated = $mutator($current);
            if (!is_array($updated)) {
                throw new RuntimeException('online_data_auto_fetch_status_mutation_invalid');
            }
            if ($updated === $current) {
                return $current;
            }
            if (($this->statusWriter)($hotelId, $updated, $ttl) === false) {
                throw new RuntimeException('online_data_auto_fetch_status_write_failed');
            }
            return $updated;
        });
    }

    private function synchronized(int $hotelId, callable $operation): mixed
    {
        $this->ensureLockDirectory();
        $lockPath = $this->lockDirectory . DIRECTORY_SEPARATOR . 'hotel-' . $hotelId . '.lock';
        $handle = @fopen($lockPath, 'c+b');
        if (!is_resource($handle)) {
            throw new RuntimeException('online_data_auto_fetch_status_lock_open_failed');
        }

        try {
            if (!@flock($handle, LOCK_EX)) {
                throw new RuntimeException('online_data_auto_fetch_status_lock_failed');
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
        // Another worker may create the directory between the first stat and
        // this check. Windows can otherwise retain the initial negative stat
        // result long enough for file_exists() to observe the new path while
        // is_dir() still reports false.
        clearstatcache(true, $this->lockDirectory);
        if (is_dir($this->lockDirectory)) {
            return;
        }
        if (file_exists($this->lockDirectory)) {
            throw new RuntimeException('online_data_auto_fetch_status_lock_directory_unavailable');
        }
        if (@mkdir($this->lockDirectory, 0700, true)) {
            return;
        }
        clearstatcache(true, $this->lockDirectory);
        if (is_dir($this->lockDirectory)) {
            return;
        }
        if (file_exists($this->lockDirectory)) {
            throw new RuntimeException('online_data_auto_fetch_status_lock_directory_unavailable');
        }
        throw new RuntimeException('online_data_auto_fetch_status_lock_directory_create_failed');
    }
}
