<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

final class SingleInstanceRuntimeReadiness
{
    /**
     * @return array{
     *   ready: bool,
     *   production_runtime_ready: bool,
     *   runtime_mode: string,
     *   persistent_required: bool,
     *   checks: array<string, string>,
     *   failures: list<string>
     * }
     */
    public function check(): array
    {
        $checks = [
            'local_state' => 'unavailable',
            'cache' => 'unavailable',
            'lock' => 'unavailable',
            'database_schema' => 'unavailable',
            'competitor_report_idempotency' => 'unavailable',
        ];
        $failures = [];

        try {
            $policy = LocalStatePathPolicy::resolve();
        } catch (\Throwable) {
            return [
                'ready' => false,
                'production_runtime_ready' => false,
                'runtime_mode' => 'invalid',
                'persistent_required' => true,
                'checks' => $checks,
                'failures' => ['local_state_policy_invalid'],
            ];
        }

        $persistentRequired = (bool)$policy['persistent_paths_required'];
        $schemaCacheAvailable = !$persistentRequired;
        if (!$persistentRequired) {
            $checks['local_state'] = 'development_fallback';
            $checks['cache'] = 'not_enforced';
            $checks['lock'] = 'not_enforced';
        } else {
            $pathsReady = $this->pathsReady(
                (string)$policy['cache_path'],
                (string)$policy['lock_path']
            );
            $checks['local_state'] = $pathsReady ? 'ok' : 'unavailable';
            if (!$pathsReady) {
                $failures[] = 'persistent_local_state_path_unavailable';
            }

            $cacheReady = $pathsReady && $this->cacheReady((string)$policy['cache_path']);
            $checks['cache'] = $cacheReady ? 'ok' : 'unavailable';
            if (!$cacheReady) {
                $failures[] = 'persistent_cache_unavailable';
            }

            $lockReady = $pathsReady && $this->lockReady();
            $checks['lock'] = $lockReady ? 'ok' : 'unavailable';
            if (!$lockReady) {
                $failures[] = 'persistent_lock_unavailable';
            }
            $schemaCacheAvailable = $cacheReady && $lockReady;
        }

        $databaseSchemaReady = $this->databaseSchemaReady($schemaCacheAvailable);
        $checks['database_schema'] = $databaseSchemaReady ? 'ok' : 'upgrade_required';
        if (!$databaseSchemaReady) {
            $failures[] = 'database_schema_upgrade_required';
        }

        $schemaReady = $this->competitorReportSchemaReady();
        $checks['competitor_report_idempotency'] = $schemaReady ? 'ok' : 'unavailable';
        if (!$schemaReady) {
            $failures[] = 'competitor_report_idempotency_schema_missing';
        }

        $ready = $failures === [];

        return [
            'ready' => $ready,
            'production_runtime_ready' => $ready && $persistentRequired,
            'runtime_mode' => $persistentRequired
                ? 'single_instance_persistent'
                : 'development_fallback',
            'persistent_required' => $persistentRequired,
            'checks' => $checks,
            'failures' => $failures,
        ];
    }

    private function pathsReady(string $cachePath, string $lockPath): bool
    {
        $root = realpath(root_path());
        foreach ([$cachePath, $lockPath] as $path) {
            if ($path === '' || !is_dir($path) || !is_writable($path)) {
                return false;
            }
            $resolved = realpath($path);
            if ($resolved === false) {
                return false;
            }
            if ($root !== false && $this->pathIsInside($resolved, $root)) {
                return false;
            }
        }

        return true;
    }

    private function cacheReady(string $expectedPath): bool
    {
        if ((string)config('cache.default', '') !== 'file') {
            return false;
        }
        $activePath = realpath((string)config('cache.stores.file.path', ''));
        $resolvedExpectedPath = realpath($expectedPath);
        if ($activePath === false
            || $resolvedExpectedPath === false
            || strcasecmp($activePath, $resolvedExpectedPath) !== 0) {
            return false;
        }

        $key = 'runtime_readiness_' . bin2hex(random_bytes(12));
        $value = bin2hex(random_bytes(16));
        try {
            return cache($key, $value, 30) === true && cache($key) === $value;
        } catch (\Throwable) {
            return false;
        } finally {
            try {
                cache($key, null);
            } catch (\Throwable) {
                // Readiness is already false if the active cache cannot be used.
            }
        }
    }

    private function lockReady(): bool
    {
        $directory = LocalStatePathPolicy::scopedLockDirectory('health-probe');
        $path = $directory . DIRECTORY_SEPARATOR . bin2hex(random_bytes(12)) . '.lock';
        $handle = null;
        try {
            if (!is_dir($directory)
                && !mkdir($directory, 0700, true)
                && !is_dir($directory)) {
                return false;
            }
            $handle = fopen($path, 'c+');
            return is_resource($handle) && flock($handle, LOCK_EX | LOCK_NB);
        } catch (\Throwable) {
            return false;
        } finally {
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function competitorReportSchemaReady(): bool
    {
        try {
            $columns = Db::query("SHOW COLUMNS FROM `competitor_price_log` LIKE 'report_fingerprint'");
            $indexes = Db::query("SHOW INDEX FROM `competitor_price_log` WHERE `Key_name` = 'uniq_competitor_report_fingerprint'");

            return count($columns) === 1
                && count($indexes) === 1
                && (int)($indexes[0]['Non_unique'] ?? $indexes[0]['non_unique'] ?? 1) === 0
                && (string)($indexes[0]['Column_name'] ?? $indexes[0]['column_name'] ?? '') === 'report_fingerprint';
        } catch (\Throwable) {
            return false;
        }
    }

    private function databaseSchemaReady(bool $cacheAvailable): bool
    {
        try {
            $default = (string)config('database.default', 'mysql');
            $config = (array)config("database.connections.{$default}", []);
            $root = root_path();
            if (!$cacheAvailable) {
                return SchemaVersionService::fromDatabaseConfig($config, $root)->status()['ready'] === true;
            }
            $cache = new SchemaVersionStatusCache($config, $root);
            $status = $cache->remember(
                static fn(): array => SchemaVersionService::fromDatabaseConfig($config, $root)->status(),
                SchemaVersionStatusCache::DEFAULT_TTL_SECONDS
            );

            return ($status['ready'] ?? false) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function pathIsInside(string $path, string $root): bool
    {
        $normalizedPath = strtolower(str_replace('\\', '/', rtrim($path, '\\/'))) . '/';
        $normalizedRoot = strtolower(str_replace('\\', '/', rtrim($root, '\\/'))) . '/';

        return str_starts_with($normalizedPath, $normalizedRoot);
    }
}
