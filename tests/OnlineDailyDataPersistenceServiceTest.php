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

    public function testRealtimeSnapshotUsesMinuteBucketSoRepeatedChecksRemainReviewable(): void
    {
        $row = OnlineDailyDataPersistenceService::applyPeriodFields(
            [
                'data_period' => 'realtime_snapshot',
                'snapshot_time' => '2026-07-15 10:42:33',
            ],
            [
                'data_period' => true,
                'snapshot_time' => true,
                'snapshot_bucket' => true,
                'is_final' => true,
            ]
        );

        self::assertSame('2026-07-15 10:42:33', $row['snapshot_time']);
        self::assertSame('202607151042', $row['snapshot_bucket']);
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

    public function testMinuteBucketSchemaChangeLivesInANewMigration(): void
    {
        $root = dirname(__DIR__);
        $legacy = (string)file_get_contents(
            $root . '/database/migrations/20260606_add_online_daily_data_period_fields.sql'
        );
        $repair = (string)file_get_contents(
            $root . '/database/migrations/20260715_repair_online_data_period_semantics.sql'
        );

        self::assertStringContainsString('hour bucket, e.g. 2026060613', $legacy);
        self::assertStringNotContainsString('minute bucket, e.g. 202606061315', $legacy);
        self::assertStringContainsString('MODIFY COLUMN `snapshot_bucket`', $repair);
        self::assertStringContainsString('minute bucket, e.g. 202606061315', $repair);
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

    public function testMetricAwarePatchDoesNotOverwriteMissingFactsAndKeepsObservedZero(): void
    {
        $fields = ['list_exposure', 'detail_exposure', 'flow_rate'];
        $update = OnlineDailyDataPersistenceService::buildMetricAwareWriteData(
            ['source' => 'ctrip', 'detail_exposure' => 0],
            ['list_exposure' => 0],
            $fields,
            false
        );

        self::assertSame(0, $update['list_exposure']);
        self::assertArrayNotHasKey('detail_exposure', $update);
        self::assertSame(88, array_merge(['detail_exposure' => 88], $update)['detail_exposure']);

        $insert = OnlineDailyDataPersistenceService::buildMetricAwareWriteData(
            ['source' => 'ctrip'],
            ['list_exposure' => 0],
            $fields,
            true
        );
        self::assertSame(0, $insert['list_exposure']);
        self::assertNull($insert['detail_exposure']);
        self::assertNull($insert['flow_rate']);
    }

    public function testTrafficExtractionUsesSourcePresenceInsteadOfDefaultZero(): void
    {
        $method = new \ReflectionMethod(OnlineDailyDataPersistenceService::class, 'extractObservedTrafficMetrics');
        $method->setAccessible(true);
        $metrics = $method->invoke(new OnlineDailyDataPersistenceService(), [
            'poiId' => '1029642156589279',
            'listExposure' => 0,
        ], false);

        self::assertSame(['list_exposure' => 0], $metrics);
        self::assertArrayNotHasKey('detail_exposure', $metrics);
        self::assertArrayNotHasKey('flow_rate', $metrics);
    }

    public function testMetricReadbackMustMatchEveryObservedMetric(): void
    {
        $expected = [
            'source' => 'ctrip',
            'data_type' => 'traffic',
            'data_date' => '2026-07-14',
            'dimension' => 'Ctrip:self',
            'hotel_id' => '987654',
            'system_hotel_id' => 7,
            'list_exposure' => 0,
            'detail_exposure' => 12,
        ];
        $persisted = array_merge($expected, [
            'system_hotel_id' => '7',
            'list_exposure' => '0',
            'detail_exposure' => '12',
        ]);

        self::assertTrue(OnlineDailyDataPersistenceService::matchesMetricReadback(
            $persisted,
            $expected,
            ['source', 'data_type', 'data_date', 'dimension', 'hotel_id', 'system_hotel_id'],
            ['list_exposure', 'detail_exposure']
        ));

        $persisted['detail_exposure'] = '11';
        self::assertFalse(OnlineDailyDataPersistenceService::matchesMetricReadback(
            $persisted,
            $expected,
            ['source', 'data_type', 'data_date', 'dimension', 'hotel_id', 'system_hotel_id'],
            ['list_exposure', 'detail_exposure']
        ));
    }
}
