<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class OnlineDataPeriodSemanticsMigrationTest extends TestCase
{
    public function testForecastRowsNeverBecomeHistoricalActualFacts(): void
    {
        $sql = (string)file_get_contents(dirname(__DIR__) . '/database/migrations/20260715_repair_online_data_period_semantics.sql');

        self::assertStringContainsString("LOWER(TRIM(COALESCE(`data_type`, ''))) = 'traffic_forecast'", $sql);
        self::assertStringContainsString("`data_period` = 'next_30_days'", $sql);
        self::assertStringContainsString('`is_final` = 0', $sql);
        self::assertStringNotContainsString("`data_period` = 'historical_daily'", $sql);
        self::assertStringNotContainsString("`data_period` = 'realtime_snapshot'", $sql);
        self::assertStringNotContainsString('`data_date` = CURRENT_DATE', $sql);
    }
}
