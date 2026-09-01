<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/scripts/repair_online_daily_data_health.php';

final class OnlineDailyDataHealthRepairScriptTest extends TestCase
{
    public function testFutureStayOrderClassifierRequiresExplicitUnverifiedStayDateContract(): void
    {
        $row = [
            'id' => 10,
            'platform' => 'ctrip',
            'source' => 'ctrip',
            'data_type' => 'order',
            'data_date' => '2026-09-20',
            'data_period' => 'historical_daily',
            'raw_data' => json_encode([
                'row' => [
                    'raw_data' => [
                        'business_date_basis' => 'stay_date',
                        'source_method' => 'user_provided_unverified',
                        'import_contract' => 'ctrip_order_aggregate_v2',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ];

        self::assertTrue(odh_is_future_stay_order($row, '2026-09-01'));
        $row['raw_data'] = json_encode([
            'row' => ['raw_data' => ['business_date_basis' => 'booking_date_fallback']],
        ], JSON_THROW_ON_ERROR);
        self::assertFalse(odh_is_future_stay_order($row, '2026-09-01'));
    }

    public function testForecastBucketUsesTaskOrRecordedObservationTime(): void
    {
        self::assertSame('task:4565', odh_forecast_snapshot_bucket([
            'sync_task_id' => 4565,
            'create_time' => '2026-08-31 09:15:44',
        ]));
        self::assertSame('time:202607121911', odh_forecast_snapshot_bucket([
            'sync_task_id' => null,
            'raw_data' => json_encode(['ingested_at' => '2026-07-12 19:11:06'], JSON_THROW_ON_ERROR),
        ]));
    }

    public function testBusinessKeySeparatesVersionedForecastSnapshotsButNormalizesCase(): void
    {
        $base = [
            'source' => 'CTRIP',
            'platform' => 'Ctrip',
            'data_type' => 'traffic_forecast',
            'dimension' => 'Flow_Forecast_1',
            'compare_type' => 'SELF',
            'data_date' => '2026-09-20',
            'data_period' => 'next_30_days',
            'snapshot_bucket' => 'task:1',
            'sync_task_id' => 1,
            'ingestion_method' => 'browser_profile',
            'system_hotel_id' => 80,
            'hotel_id' => 'platform-hotel',
        ];
        $same = array_replace($base, [
            'source' => 'ctrip',
            'platform' => 'ctrip',
            'dimension' => 'flow_forecast_1',
            'compare_type' => 'self',
        ]);
        $later = array_replace($same, ['snapshot_bucket' => 'task:2', 'sync_task_id' => 2]);

        self::assertSame(odh_business_key($base), odh_business_key($same));
        self::assertNotSame(odh_business_key($same), odh_business_key($later));
    }

    public function testStrictVerifiedRowWinsDuplicateSelection(): void
    {
        $normal = [
            'id' => 20,
            'validation_status' => 'normal',
            'history_status' => 'partial',
            'readback_verified' => 1,
            'update_time' => '2026-09-01 10:00:00',
        ];
        $strict = [
            'id' => 10,
            'validation_status' => 'verified',
            'history_status' => 'success',
            'readback_verified' => 1,
            'update_time' => '2026-08-31 10:00:00',
        ];

        self::assertSame(10, odh_preferred_row($normal, $strict)['id']);
        self::assertSame(10, odh_preferred_row($strict, $normal)['id']);
    }

    public function testRepairIsDryRunFirstBackedUpAndNeverDeletesFacts(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__) . '/scripts/repair_online_daily_data_health.php'
        );

        self::assertStringContainsString("\$argument === '--execute'", $source);
        self::assertStringContainsString('odh_write_backup', $source);
        self::assertStringContainsString("'facts_deleted' => 0", $source);
        self::assertStringContainsString("'business_metric_values_rewritten' => 0", $source);
        self::assertStringContainsString("'strict_fact_rows_promoted' => 0", $source);
        self::assertStringNotContainsString('DELETE FROM online_daily_data', $source);
    }
}
