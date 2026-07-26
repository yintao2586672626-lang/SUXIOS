<?php
declare(strict_types=1);

namespace Tests;

use app\service\SingleInstanceRuntimeReadiness;
use PHPUnit\Framework\TestCase;
use think\App;

final class HealthRouteContractTest extends TestCase
{
    private static ?App $app = null;

    public static function setUpBeforeClass(): void
    {
        self::$app = new App(dirname(__DIR__));
        self::$app->initialize();
    }

    public function testHealthRouteFailsClosedWhenDatabaseIsUnavailable(): void
    {
        $source = (string)file_get_contents(__DIR__ . '/../route/app.php');
        $start = strpos($source, "Route::get('api/health'");
        $end = strpos($source, '// ==================== AI Agent', $start ?: 0);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $route = substr($source, (int)$start, (int)$end - (int)$start);

        self::assertStringContainsString("Db::query('SELECT 1 AS ready')", $route);
        self::assertStringContainsString("'database' => 'unavailable'", $route);
        self::assertStringContainsString('SingleInstanceRuntimeReadiness', $route);
        self::assertStringContainsString("'failure_codes'", $route);
        self::assertStringContainsString("'competitor_report_idempotency'", (string)file_get_contents(
            __DIR__ . '/../app/service/SingleInstanceRuntimeReadiness.php'
        ));
        self::assertStringContainsString('], 503)', $route);
        self::assertStringContainsString("'database' => 'ok'", $route);
        self::assertStringNotContainsString("return json(['status' => 'ok'", $route);
    }

    public function testRuntimeReadinessReturnsOnlyBoundedOperationalStatus(): void
    {
        $result = (new SingleInstanceRuntimeReadiness())->check();

        self::assertIsBool($result['ready']);
        self::assertIsBool($result['persistent_required']);
        self::assertSame(
            ['local_state', 'cache', 'lock', 'database_schema', 'competitor_report_idempotency'],
            array_keys($result['checks'])
        );
        self::assertIsArray($result['failures']);

        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('SUXIOS_CACHE_PATH', $encoded);
        self::assertStringNotContainsString('SUXIOS_LOCAL_LOCK_PATH', $encoded);
        self::assertStringNotContainsString(dirname(__DIR__), $encoded);
    }
}
