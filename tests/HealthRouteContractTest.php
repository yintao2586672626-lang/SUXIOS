<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class HealthRouteContractTest extends TestCase
{
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
        self::assertStringContainsString('], 503)', $route);
        self::assertStringContainsString("'database' => 'ok'", $route);
        self::assertStringNotContainsString("return json(['status' => 'ok'", $route);
    }
}
