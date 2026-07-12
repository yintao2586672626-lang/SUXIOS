<?php
declare(strict_types=1);

namespace tests;

use app\service\OnlineDailyDataPersistenceService;
use PHPUnit\Framework\TestCase;

final class OnlineDailyDataPersistenceServiceTest extends TestCase
{
    public function testFutureForecastPeriodRemainsNonFinal(): void
    {
        self::assertSame('next_30_days', OnlineDailyDataPersistenceService::normalizePeriod('next-30-days'));
        self::assertSame('next_30_days', OnlineDailyDataPersistenceService::normalizePeriod('future_forecast'));

        $row = OnlineDailyDataPersistenceService::applyPeriodFields(
            ['data_period' => 'next_30_days'],
            [
                'data_period' => true,
                'snapshot_time' => true,
                'snapshot_bucket' => true,
                'is_final' => true,
            ]
        );

        self::assertSame('next_30_days', $row['data_period']);
        self::assertNull($row['snapshot_time']);
        self::assertSame('', $row['snapshot_bucket']);
        self::assertSame(0, $row['is_final']);
    }

    public function testTrafficForecastPeriodCorrectionMigrationIsNarrowAndRegistered(): void
    {
        $root = dirname(__DIR__);
        $migrationPath = $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations'
            . DIRECTORY_SEPARATOR . '20260710_fix_traffic_forecast_period.sql';

        self::assertFileExists($migrationPath);
        $migration = (string)file_get_contents($migrationPath);
        self::assertStringContainsString("LOWER(TRIM(`source`)) = 'meituan'", $migration);
        self::assertStringContainsString("LOWER(TRIM(`data_type`)) = 'traffic_forecast'", $migration);
        self::assertStringContainsString("`data_period` = 'next_30_days'", $migration);
        self::assertStringContainsString('`is_final` = 0', $migration);
        self::assertStringNotContainsString('`raw_data` =', $migration);

        $init = (string)file_get_contents($root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'init_full.sql');
        self::assertStringContainsString(
            'SOURCE ./database/migrations/20260710_fix_traffic_forecast_period.sql;',
            $init
        );
    }

    public function testMeituanTrafficRequiresTheBoundPlatformHotelIdentity(): void
    {
        $service = new OnlineDailyDataPersistenceService();
        $matching = ['data' => ['list' => [[
            'poiId' => '1029642156589279',
            'dataDate' => '2026-07-11',
            'exposure' => 100,
        ]]]];

        self::assertSame(
            ['1029642156589279'],
            $service->validateGenericTrafficBinding($matching, '1029642156589279')
        );

        $this->expectException(\InvalidArgumentException::class);
        $service->validateGenericTrafficBinding($matching, 'wrong-poi');
    }
}
