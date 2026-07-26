<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Cache;
use Throwable;

final class SchemaVersionStatusCache
{
    public const DEFAULT_TTL_SECONDS = 60;
    private const PAYLOAD_VERSION = 1;
    private const FAILURE_REPORT_INTERVAL_SECONDS = 60;

    /** @var callable(string):mixed */
    private $reader;
    /** @var callable(string,array<string,mixed>,int):bool */
    private $writer;
    /** @var callable(string):bool */
    private $deleter;
    /** @var null|callable():string */
    private $deploymentIdentityResolver;
    /** @var callable(string):void */
    private $failureReporter;
    private string $lockDirectory;
    /** @var array<string,bool> */
    private static array $reportedFailures = [];

    /**
     * @param array<string,mixed> $databaseConfig
     * @param null|callable(string):mixed $reader
     * @param null|callable(string,array<string,mixed>,int):bool $writer
     * @param null|callable(string):bool $deleter
     * @param null|callable():string $deploymentIdentityResolver
     * @param null|callable(string):void $failureReporter
     */
    public function __construct(
        private readonly array $databaseConfig,
        private readonly string $root,
        ?callable $reader = null,
        ?callable $writer = null,
        ?callable $deleter = null,
        ?callable $deploymentIdentityResolver = null,
        ?string $lockDirectory = null,
        ?callable $failureReporter = null
    ) {
        $this->reader = $reader ?? static fn(string $key): mixed => Cache::get($key, null);
        $this->writer = $writer
            ?? static fn(string $key, array $value, int $ttl): bool => Cache::set($key, $value, $ttl);
        $this->deleter = $deleter ?? static fn(string $key): bool => Cache::delete($key);
        $this->deploymentIdentityResolver = $deploymentIdentityResolver;
        $this->lockDirectory = $lockDirectory ?? $this->defaultLockDirectory();
        $this->failureReporter = $failureReporter ?? static function (string $message): void {
            error_log($message);
        };
    }

    /** @return null|array<string,mixed> */
    public function get(): ?array
    {
        try {
            $deploymentIdentity = $this->deploymentIdentity();
            $payload = ($this->reader)($this->keyFor($deploymentIdentity));
        } catch (Throwable) {
            $this->reportFailureOnce('read', 'Schema guard shared-cache read failed; using a full schema check.');
            return null;
        }

        if (!is_array($payload)
            || (int)($payload['payload_version'] ?? 0) !== self::PAYLOAD_VERSION
            || !hash_equals($deploymentIdentity, (string)($payload['deployment_identity'] ?? ''))
            || !is_array($payload['status'] ?? null)
        ) {
            return null;
        }

        return $payload['status'];
    }

    /** @param array<string,mixed> $status */
    public function put(array $status, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): bool
    {
        try {
            $deploymentIdentity = $this->deploymentIdentity();
            $stored = (bool)($this->writer)(
                $this->keyFor($deploymentIdentity),
                [
                    'payload_version' => self::PAYLOAD_VERSION,
                    'deployment_identity' => $deploymentIdentity,
                    'cached_at' => time(),
                    'status' => $status,
                ],
                max(1, $ttlSeconds)
            );
            if (!$stored) {
                $this->reportFailureOnce(
                    'write',
                    'Schema guard shared-cache write failed; runtime requests will repeat the full schema check.'
                );
            }
            return $stored;
        } catch (Throwable) {
            $this->reportFailureOnce(
                'write',
                'Schema guard shared-cache write failed; runtime requests will repeat the full schema check.'
            );
            return false;
        }
    }

    /**
     * @param callable():array<string,mixed> $resolver
     * @return array<string,mixed>
     */
    public function remember(callable $resolver, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): array
    {
        $cached = $this->get();
        if ($cached !== null) {
            return $cached;
        }

        return $this->withRefreshLock(function () use ($resolver, $ttlSeconds): array {
            $cached = $this->get();
            if ($cached !== null) {
                return $cached;
            }

            $status = (array)$resolver();
            $this->put($status, $ttlSeconds);
            return $status;
        });
    }

    public function clear(): bool
    {
        try {
            $key = $this->keyFor($this->deploymentIdentity());
            if ((bool)($this->deleter)($key)) {
                return true;
            }
            if (($this->reader)($key) === null) {
                return true;
            }
            $this->reportFailureOnce(
                'clear',
                'Schema guard shared-cache invalidation failed; database migration must not start.'
            );
            return false;
        } catch (Throwable) {
            $this->reportFailureOnce(
                'clear',
                'Schema guard shared-cache invalidation failed; database migration must not start.'
            );
            return false;
        }
    }

    private function deploymentIdentity(): string
    {
        if (is_callable($this->deploymentIdentityResolver)) {
            $identity = trim((string)($this->deploymentIdentityResolver)());
            if ($identity !== '') {
                return $identity;
            }
        }

        foreach (['SUXIOS_DEPLOY_VERSION', 'APP_DEPLOY_VERSION', 'RELEASE_VERSION', 'SOURCE_VERSION'] as $key) {
            $value = getenv($key);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        $resolvedRoot = realpath($this->root);
        $root = is_string($resolvedRoot) ? $resolvedRoot : rtrim($this->root, DIRECTORY_SEPARATOR);
        $migrationDirectory = $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
        $initFull = $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'init_full.sql';

        return implode('|', [
            $root,
            (string)(@filemtime($migrationDirectory) ?: 0),
            (string)(@filemtime($initFull) ?: 0),
        ]);
    }

    private function keyFor(string $deploymentIdentity): string
    {
        $databaseIdentity = implode('|', [
            strtolower(trim((string)($this->databaseConfig['type'] ?? 'mysql'))),
            trim((string)($this->databaseConfig['hostname'] ?? $this->databaseConfig['host'] ?? '127.0.0.1')),
            trim((string)($this->databaseConfig['hostport'] ?? $this->databaseConfig['port'] ?? '3306')),
            trim((string)($this->databaseConfig['database'] ?? 'hotelx')),
            trim((string)($this->databaseConfig['username'] ?? $this->databaseConfig['user'] ?? 'root')),
        ]);

        return 'suxios_database_schema_guard_v1_' . hash(
            'sha256',
            $deploymentIdentity . '|' . $databaseIdentity
        );
    }

    private function defaultLockDirectory(): string
    {
        try {
            $configured = LocalStatePathPolicy::scopedLockDirectory('schema-guard');
        } catch (Throwable) {
            $configured = '';
        }
        if ($configured !== '') {
            return $configured;
        }

        return rtrim($this->root, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'locks'
            . DIRECTORY_SEPARATOR . 'schema-guard';
    }

    /** @param callable():array<string,mixed> $callback @return array<string,mixed> */
    private function withRefreshLock(callable $callback): array
    {
        if (!$this->ensureLockDirectory()) {
            return $callback();
        }

        $lockPath = $this->lockDirectory
            . DIRECTORY_SEPARATOR
            . hash('sha256', $this->keyFor($this->deploymentIdentity()))
            . '.lock';
        $handle = @fopen($lockPath, 'c+b');
        if (!is_resource($handle)) {
            $this->reportFailureOnce(
                'lock_open',
                'Schema guard refresh lock could not be opened; cache stampede protection is unavailable.'
            );
            return $callback();
        }

        try {
            if (!@flock($handle, LOCK_EX)) {
                $this->reportFailureOnce(
                    'lock_acquire',
                    'Schema guard refresh lock could not be acquired; cache stampede protection is unavailable.'
                );
                return $callback();
            }
            return $callback();
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function ensureLockDirectory(): bool
    {
        if (is_dir($this->lockDirectory)) {
            return true;
        }
        if (@mkdir($this->lockDirectory, 0770, true) || is_dir($this->lockDirectory)) {
            return true;
        }

        $this->reportFailureOnce(
            'lock_directory',
            'Schema guard refresh lock directory is unavailable; cache stampede protection is disabled.'
        );
        return false;
    }

    private function reportFailureOnce(string $kind, string $message): void
    {
        if (isset(self::$reportedFailures[$kind])) {
            return;
        }
        self::$reportedFailures[$kind] = true;
        if (!$this->claimFailureReportSlot($kind)) {
            return;
        }
        try {
            ($this->failureReporter)($message);
        } catch (Throwable) {
            // Observability must not replace the full schema-check fallback.
        }
    }

    private function claimFailureReportSlot(string $kind): bool
    {
        if (!is_dir($this->lockDirectory)
            && !@mkdir($this->lockDirectory, 0770, true)
            && !is_dir($this->lockDirectory)
        ) {
            return true;
        }

        $markerPath = $this->lockDirectory
            . DIRECTORY_SEPARATOR
            . 'schema-guard-report-' . hash('sha256', $kind);
        $handle = @fopen($markerPath, 'c+b');
        if (!is_resource($handle)) {
            return true;
        }

        try {
            if (!@flock($handle, LOCK_EX)) {
                return true;
            }
            rewind($handle);
            $lastReportedAt = (int)trim((string)stream_get_contents($handle));
            $now = time();
            if ($lastReportedAt > 0
                && ($now - $lastReportedAt) < self::FAILURE_REPORT_INTERVAL_SECONDS
            ) {
                return false;
            }
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string)$now);
            fflush($handle);
            return true;
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
