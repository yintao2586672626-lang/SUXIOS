<?php
declare(strict_types=1);

namespace app\middleware;

use app\service\SchemaVersionService;
use app\service\SchemaVersionStatusCache;
use Closure;
use think\Request;
use think\Response;
use Throwable;

final class DatabaseSchemaGuard
{
    /** @var null|callable():array<string,mixed> */
    private $statusResolver;
    private float $cacheTtlSeconds;
    private ?SchemaVersionStatusCache $statusCache;

    public function __construct(
        ?callable $statusResolver = null,
        float $cacheTtlSeconds = SchemaVersionStatusCache::DEFAULT_TTL_SECONDS,
        ?SchemaVersionStatusCache $statusCache = null
    ) {
        $this->statusResolver = $statusResolver;
        $this->cacheTtlSeconds = max(0.0, $cacheTtlSeconds);
        $this->statusCache = $statusCache;
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (trim((string)$request->pathinfo(), '/') === 'api/health') {
            return $next($request);
        }

        try {
            $status = $this->resolveStatus();
            if (($status['ready'] ?? false) === true) {
                return $next($request);
            }

            return json([
                'code' => 503,
                'error' => 'database_schema_upgrade_required',
                'message' => '数据库结构未达到当前代码要求，应用已阻止业务请求。',
                'current_version' => $status['current_version'] ?? null,
                'required_version' => $status['required_version'] ?? null,
                'pending_count' => count($status['pending'] ?? []),
                'action' => $this->actionFor($status),
            ], 503);
        } catch (Throwable) {
            return json([
                'code' => 503,
                'error' => 'database_schema_check_failed',
                'message' => '数据库连接或版本检查失败，应用已阻止业务请求。',
                'action' => '检查数据库连接后运行：php think db:check',
            ], 503);
        }
    }

    /** @return array<string,mixed> */
    private function resolveStatus(): array
    {
        $config = null;
        $root = null;
        if ($this->statusCache === null && !is_callable($this->statusResolver)) {
            $default = (string)config('database.default', 'mysql');
            $config = (array)config("database.connections.{$default}", []);
            $root = app()->getRootPath();
            $this->statusCache = new SchemaVersionStatusCache($config, $root);
        }

        $resolver = function () use ($config, $root): array {
            if (is_callable($this->statusResolver)) {
                return (array)($this->statusResolver)();
            }

            return SchemaVersionService::fromDatabaseConfig(
                is_array($config) ? $config : [],
                is_string($root) ? $root : app()->getRootPath()
            )->status();
        };
        if ($this->statusCache !== null && $this->cacheTtlSeconds > 0) {
            return $this->statusCache->remember(
                $resolver,
                (int)max(1, ceil($this->cacheTtlSeconds))
            );
        }

        return $resolver();
    }

    /** @param array<string,mixed> $status */
    private function actionFor(array $status): string
    {
        if ((int)($status['application_table_count'] ?? 0) === 0) {
            return '运行：php scripts/init_database.php';
        }
        if (($status['registry_exists'] ?? false) !== true) {
            return '先通过旧库结构预检，再运行：php think db:migrate --baseline';
        }
        if (($status['version_mismatches'] ?? []) !== []
            || ($status['checksum_mismatches'] ?? []) !== []
            || ($status['missing_checksums'] ?? []) !== []
            || ($status['baseline_checksum_mismatches'] ?? []) !== []
            || ($status['baseline_unknown'] ?? []) !== []
            || ($status['unknown_registrations'] ?? []) !== []
        ) {
            return 'migration 证据发生漂移；请先检查 schema_versions、schema_baseline_sources 与代码 catalog';
        }
        return '运行：php think db:migrate';
    }
}
