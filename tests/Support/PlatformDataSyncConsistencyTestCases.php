<?php
declare(strict_types=1);

namespace Tests\Support;

use app\service\PlatformDataSyncService;

trait PlatformDataSyncConsistencyTestCases
{
    public function testSyncDiagnosticsDoNotRetainAdapterErrorText(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'buildSyncDiagnostics');
        $method->setAccessible(true);

        $diagnostics = $method->invoke($service, [], 0, [
            'id' => 84,
            'platform' => 'meituan',
            'data_type' => 'traffic',
            'ingestion_method' => 'manual',
            'system_hotel_id' => 58,
        ], [
            'data_date' => '2026-07-09',
        ], [], 'failed', 'Authorization: Bearer test-only-secret');

        self::assertSame('collection_failed', $diagnostics['operator_message']);
        self::assertArrayNotHasKey('adapter_message', $diagnostics);
        self::assertStringNotContainsString(
            'test-only-secret',
            (string)json_encode($diagnostics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    public function testSyncDiagnosticsPersistTaskCapabilityStatesFromSavedTargetDateRows(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'buildSyncDiagnostics');
        $method->setAccessible(true);

        $diagnostics = $method->invoke($service, [
            ['data_date' => '2026-07-09', 'data_type' => 'business'],
            ['data_date' => '2026-07-09', 'data_type' => 'order'],
        ], 2, [
            'id' => 85,
            'platform' => 'meituan',
            'data_type' => 'business',
            'ingestion_method' => 'browser_profile',
            'system_hotel_id' => 58,
            'config' => [
                'store_id' => 'store_001',
                'profile_binding_key' => 'store_001',
                'manual_login_state_verified' => true,
                'profile_status' => 'logged_in',
                'last_login_verified_at' => '2026-07-10 08:20:00',
            ],
        ], [
            'trigger_type' => 'daily_profile_reuse',
            'data_date' => '2026-07-09',
            'interactive_browser' => false,
        ], [], 'success', 'Platform data synchronized.');

        self::assertSame([
            'business' => 'verified',
            'orders' => 'verified',
            'reviews' => 'unverified',
        ], $diagnostics['capability_states']);
        self::assertStringNotContainsString(
            'store_001',
            (string)json_encode($diagnostics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    public function testSyncDiagnosticsKeepMatchedPayloadIdentityWhenTrafficRowsAreMissing(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'buildSyncDiagnostics');
        $method->setAccessible(true);

        $diagnostics = $method->invoke($service, [], 0, [
            'id' => 101,
            'platform' => 'meituan',
            'data_type' => 'business',
            'ingestion_method' => 'browser_profile',
            'system_hotel_id' => 80,
            'config' => ['store_id' => '68471'],
        ], [
            'data_date' => '2026-08-23',
            'capture_sections' => 'orders,traffic',
        ], [
            'platform_identity_validation' => [
                'status' => 'matched',
                'source_validation' => true,
                'validated_identifier' => '68471',
                'sensitive_values_exposed' => false,
            ],
        ], 'partial_success', 'target traffic missing');

        self::assertSame('ready', $diagnostics['platform_hotel_identifier_status']);
        self::assertNotContains('platform_hotel_identifier', $diagnostics['missing_inputs']);
        self::assertContains('target_date_traffic_rows', $diagnostics['missing_inputs']);
        self::assertSame('blocked', $diagnostics['p0_status']);
    }

    public function testMeituanRequestedPeriodMismatchAndContradictoryZeroRowsAreQuarantined(): void
    {
        $rows = (new PlatformDataSyncService())->normalizeRowsFromPayload([
            'data_date' => '2026-08-23',
            'data_period' => 'historical_daily',
            'rows' => [
                [
                    'poi_id' => '68471',
                    'data_date' => '2026-08-23',
                    'data_period' => 'historical_daily',
                    'data_type' => 'business',
                    'compare_type' => 'self',
                    'amount' => 0,
                    'quantity' => 0,
                    'book_order_num' => 0,
                ],
                [
                    'poi_id' => '68471',
                    'data_date' => '2026-08-23',
                    'data_period' => 'realtime_snapshot',
                    'data_type' => 'traffic',
                    'compare_type' => 'self',
                    'list_exposure' => 15,
                    'detail_exposure' => 2,
                    'flow_rate' => 13.33,
                ],
                [
                    'poi_id' => '68471',
                    'data_date' => '2026-08-23',
                    'data_period' => 'historical_daily',
                    'data_type' => 'traffic',
                    'compare_type' => '',
                    'list_exposure' => 0,
                    'detail_exposure' => 0,
                    'flow_rate' => 0,
                ],
                [
                    'poi_id' => '68471',
                    'data_date' => '2026-08-23',
                    'data_period' => 'historical_daily',
                    'data_type' => 'order',
                    'compare_type' => 'self',
                    'amount' => 7025.14,
                    'room_nights' => 12,
                    'orders' => 8,
                ],
            ],
        ], $this->meituanBrowserProfileSource(), 4353);

        self::assertCount(4, $rows);
        $byTypeAndPeriod = [];
        foreach ($rows as $row) {
            $byTypeAndPeriod[$row['data_type'] . ':' . $row['data_period']] = $row;
        }

        $business = $byTypeAndPeriod['business:historical_daily'];
        self::assertSame('quarantined', $business['validation_status']);
        self::assertContains(
            'same_run_zero_business_conflicts_with_nonzero_orders',
            json_decode($business['validation_flags'], true, 512, JSON_THROW_ON_ERROR)
        );

        $realtimeTraffic = $byTypeAndPeriod['traffic:realtime_snapshot'];
        self::assertSame('quarantined', $realtimeTraffic['validation_status']);
        self::assertContains(
            'requested_data_period_mismatch',
            json_decode($realtimeTraffic['validation_flags'], true, 512, JSON_THROW_ON_ERROR)
        );

        $historicalTraffic = $byTypeAndPeriod['traffic:historical_daily'];
        self::assertSame('quarantined', $historicalTraffic['validation_status']);
        self::assertContains(
            'same_run_zero_traffic_conflicts_with_nonzero_orders',
            json_decode($historicalTraffic['validation_flags'], true, 512, JSON_THROW_ON_ERROR)
        );

        self::assertNotSame(
            'quarantined',
            $byTypeAndPeriod['order:historical_daily']['validation_status']
        );
    }
    public function testTrafficForecastPayloadPreservesFutureForecastPeriod(): void
    {
        $service = new PlatformDataSyncService();

        $rows = $service->normalizeRowsFromPayload([
            'rows' => [
                [
                    'hotel_id' => 'meituan-1001',
                    'hotel_name' => 'Demo Hotel',
                    'data_date' => '2026-07-25',
                    'data_type' => 'traffic_forecast',
                    'data_period' => 'next_30_days',
                    'captured_at' => '2026-07-20 09:15:30',
                    'data_value' => 88,
                ],
            ],
        ], [
            'id' => 18,
            'platform' => 'meituan',
            'data_type' => 'traffic_forecast',
            'system_hotel_id' => 7,
            'tenant_id' => 1,
            'ingestion_method' => 'browser_profile',
        ], 37);

        self::assertCount(1, $rows);
        self::assertSame('next_30_days', $rows[0]['data_period']);
        self::assertSame('2026-07-20 09:15:30', $rows[0]['snapshot_time']);
        self::assertSame('202607200915', $rows[0]['snapshot_bucket']);
        self::assertSame(0, $rows[0]['is_final']);
        self::assertStringContainsString('"data_period":"next_30_days"', $rows[0]['raw_data']);
    }

}
