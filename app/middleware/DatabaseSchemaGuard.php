<?php
declare(strict_types=1);

namespace app\middleware;

use app\service\SchemaVersionService;
use Closure;
use think\Request;
use think\Response;
use Throwable;

final class DatabaseSchemaGuard
{
    /** @var null|callable():array<string,mixed> */
    private $statusResolver;
    private float $cacheTtlSeconds;
    /** @var null|array<string,mixed> */
    private ?array $cachedStatus = null;
    private float $cachedAt = 0.0;

    public function __construct(?callable $statusResolver = null, float $cacheTtlSeconds = 2.0)
    {
        $this->statusResolver = $statusResolver;
        $this->cacheTtlSeconds = max(0.0, $cacheTtlSeconds);
    }

    public function handle(Request $request, Closure $next): Response
    {
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
        $now = microtime(true);
        if ($this->cachedStatus !== null && ($now - $this->cachedAt) <= $this->cacheTtlSeconds) {
            return $this->cachedStatus;
        }
        if (is_callable($this->statusResolver)) {
            $status = (array)($this->statusResolver)();
        } else {
            $default = (string)config('database.default', 'mysql');
            $config = (array)config("database.connections.{$default}", []);
            $status = SchemaVersionService::fromDatabaseConfig($config, app()->getRootPath())->status();
        }
        $this->cachedStatus = $status;
        $this->cachedAt = $now;
        return $status;
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
