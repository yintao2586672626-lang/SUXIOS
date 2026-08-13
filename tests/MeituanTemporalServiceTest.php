<?php
declare(strict_types=1);

namespace tests;

use app\service\MeituanTemporalService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class MeituanTemporalServiceTest extends TestCase
{
    public function testRefreshExecutionBudgetOutlivesTheBoundedBrowserCapture(): void
    {
        $method = new \ReflectionMethod(MeituanTemporalService::class, 'captureExecutionBudgetSeconds');

        self::assertSame(150, $method->invoke(null, ['timeout_seconds' => 120]));
        self::assertSame(90, $method->invoke(null, ['timeout_seconds' => 10]));
        self::assertSame(930, $method->invoke(null, ['timeout_seconds' => 1000]));
    }

    public function testCurrentOnlyRefreshPlanSkipsFutureAndYesterdaySegments(): void
    {
        $method = new \ReflectionMethod(MeituanTemporalService::class, 'refreshPlan');

        self::assertSame([
            'today_scope' => 'today',
            'include_yesterday' => false,
            'refresh_scope' => 'current_only',
        ], $method->invoke(null, true, false));
        self::assertSame([
            'today_scope' => 'today_future',
            'include_yesterday' => true,
            'refresh_scope' => 'temporal_complete',
        ], $method->invoke(null, false, false));
    }

    public function testRefreshReportsPartialWhenFutureModuleHasNotUpdated(): void
    {
        $method = new \ReflectionMethod(MeituanTemporalService::class, 'withFutureCaptureOutcome');
        $task = [
            'segment' => 'today',
            'status' => 'completed',
            'reason_code' => 'capture_saved_and_read_back',
            'saved_count' => 1,
            'readback_verified' => true,
        ];

        $partial = $method->invoke(
            null,
            $task,
            'today_future',
            false,
            new DateTimeImmutable('2026-07-31 01:30:00', new DateTimeZone('Asia/Shanghai'))
        );
        self::assertSame('partial', $partial['status']);
        self::assertSame('before_future_platform_update_window', $partial['reason_code']);

        self::assertSame($task, $method->invoke(
            null,
            $task,
            'today_future',
            true,
            new DateTimeImmutable('2026-07-31 01:30:00', new DateTimeZone('Asia/Shanghai'))
        ));
    }

    public function testTodayUsesOneSnapshotPreservesZeroAndDerivesOnlyInsideIt(): void
    {
        $rows = [
            $this->row(80, 101, 'business', '2026-07-29', [
                'amount' => 2026.78,
                'quantity' => 2,
                'book_order_num' => 1,
                'data_value' => null,
                'list_exposure' => 81,
                'detail_exposure' => 81,
                'flow_rate' => null,
            ], [
                'lead_price' => 868,
                'sales_amount' => 2026.78,
                'sales_room_nights' => 2,
                'sales_avg_price' => null,
                'exposure_users' => 81,
                'detail_visitors' => 81,
                'paid_order_count' => 1,
                'browse_to_pay_rate' => null,
            ], [
                'lead_price', 'sales_amount', 'sales_room_nights',
                'exposure_users', 'detail_visitors', 'paid_order_count',
            ]),
            $this->row(80, 101, 'traffic', '2026-07-29', [
                'list_exposure' => 81,
                'detail_exposure' => 81,
                'book_order_num' => 1,
                'flow_rate' => null,
            ], [
                'exposure_users' => 81,
                'detail_visitors' => 81,
                'paid_order_count' => 1,
                'browse_to_pay_rate' => null,
            ], [
                'exposure_users', 'detail_visitors', 'paid_order_count',
            ]),
        ];

        $summary = (new MeituanTemporalService())->buildSummaryFromRows(
            $rows,
            80,
            '2026-07-29',
            new DateTimeImmutable('2026-07-29 18:00:00', new DateTimeZone('Asia/Shanghai'))
        );

        self::assertSame('ready', $summary['today']['status']);
        self::assertSame(1013.39, $summary['today']['metrics']['sales_avg_price']['value']);
        self::assertSame('derived', $summary['today']['metrics']['sales_avg_price']['status']);
        self::assertSame(1.23, $summary['today']['metrics']['browse_to_pay_rate']['value']);
        self::assertSame('derived', $summary['today']['metrics']['browse_to_pay_rate']['status']);
    }

    public function testExplicitZeroIsVerifiedAndMissingIsNotReplaced(): void
    {
        $row = $this->row(80, 102, 'business', '2026-07-29', [
            'amount' => 0,
            'quantity' => 0,
            'book_order_num' => 0,
            'data_value' => null,
            'list_exposure' => 0,
            'detail_exposure' => 0,
            'flow_rate' => 0,
        ], [
            'lead_price' => 542.24,
            'sales_amount' => 0,
            'sales_room_nights' => 0,
            'sales_avg_price' => null,
            'exposure_users' => 0,
            'detail_visitors' => 0,
            'paid_order_count' => 0,
            'browse_to_pay_rate' => 0,
        ], [
            'lead_price', 'sales_amount', 'sales_room_nights', 'exposure_users',
            'detail_visitors', 'paid_order_count', 'browse_to_pay_rate',
        ]);
        $traffic = $this->row(80, 102, 'traffic', '2026-07-29', [
            'list_exposure' => 0,
            'detail_exposure' => 0,
            'book_order_num' => 0,
            'flow_rate' => 0,
        ], [
            'exposure_users' => 0,
            'detail_visitors' => 0,
            'paid_order_count' => 0,
            'browse_to_pay_rate' => 0,
        ], [
            'exposure_users', 'detail_visitors', 'paid_order_count', 'browse_to_pay_rate',
        ]);

        $summary = (new MeituanTemporalService())->buildSummaryFromRows(
            [$row, $traffic],
            80,
            '2026-07-29'
        );
        self::assertSame(0, $summary['today']['metrics']['sales_amount']['value']);
        self::assertSame('verified', $summary['today']['metrics']['sales_amount']['status']);
        self::assertNull($summary['today']['metrics']['sales_avg_price']['value']);
        self::assertSame('missing', $summary['today']['metrics']['sales_avg_price']['status']);
        self::assertSame(0, $summary['today']['metrics']['browse_to_pay_rate']['value']);
        self::assertSame('verified', $summary['today']['metrics']['browse_to_pay_rate']['status']);
    }

    public function testTodayTrafficFunnelWinsForTrafficMetricsInsideTheSameSnapshot(): void
    {
        $business = $this->row(80, 103, 'business', '2026-07-29', [
            'amount' => 0,
            'quantity' => 0,
            'book_order_num' => 0,
            'data_value' => 0,
            'list_exposure' => null,
            'detail_exposure' => 0,
            'flow_rate' => 0,
        ], [
            'lead_price' => null,
            'sales_amount' => 0,
            'sales_room_nights' => 0,
            'sales_avg_price' => 0,
            'exposure_users' => null,
            'detail_visitors' => 0,
            'paid_order_count' => 0,
            'browse_to_pay_rate' => 0,
        ], [
            'sales_amount', 'sales_room_nights', 'sales_avg_price',
            'detail_visitors', 'paid_order_count', 'browse_to_pay_rate',
        ]);
        $traffic = $this->row(80, 103, 'traffic', '2026-07-29', [
            'list_exposure' => 10,
            'detail_exposure' => 3,
            'book_order_num' => 0,
            'flow_rate' => 0,
        ], [
            'exposure_users' => 10,
            'detail_visitors' => 3,
            'paid_order_count' => 0,
            'browse_to_pay_rate' => 0,
        ], [
            'exposure_users', 'detail_visitors', 'paid_order_count', 'browse_to_pay_rate',
        ]);

        $summary = (new MeituanTemporalService())->buildSummaryFromRows(
            [$business, $traffic],
            80,
            '2026-07-29'
        );

        self::assertSame(10, $summary['today']['metrics']['exposure_users']['value']);
        self::assertSame(3, $summary['today']['metrics']['detail_visitors']['value']);
        self::assertSame(0, $summary['today']['metrics']['paid_order_count']['value']);
        self::assertSame(0, $summary['today']['metrics']['browse_to_pay_rate']['value']);
    }

    public function testTodayNeverFallsBackToBusinessCardsForFunnelMetrics(): void
    {
        $business = $this->row(80, 104, 'business', '2026-07-29', [
            'amount' => 1032.39,
            'quantity' => 1,
            'book_order_num' => 9,
            'data_value' => 1032.39,
            'list_exposure' => 999,
            'detail_exposure' => 888,
            'flow_rate' => 77.7,
        ], [
            'lead_price' => 1158,
            'sales_amount' => 1032.39,
            'sales_room_nights' => 1,
            'sales_avg_price' => 1032.39,
            'exposure_users' => 999,
            'detail_visitors' => 888,
            'paid_order_count' => 9,
            'browse_to_pay_rate' => 77.7,
        ], [
            'lead_price', 'sales_amount', 'sales_room_nights', 'sales_avg_price',
            'exposure_users', 'detail_visitors', 'paid_order_count', 'browse_to_pay_rate',
        ]);

        $summary = (new MeituanTemporalService())->buildSummaryFromRows(
            [$business],
            80,
            '2026-07-29'
        );

        self::assertSame(1158, $summary['today']['metrics']['lead_price']['value']);
        foreach (['exposure_users', 'detail_visitors', 'paid_order_count', 'browse_to_pay_rate'] as $key) {
            self::assertNull($summary['today']['metrics'][$key]['value']);
            self::assertSame('missing', $summary['today']['metrics'][$key]['status']);
        }
        self::assertSame('partial', $summary['today']['status']);
    }

    public function testLatestIncompleteSnapshotDoesNotBorrowOlderValues(): void
    {
        $complete = $this->completeBusinessRow(80, 201, '2026-07-29', '2026-07-29 10:00:00');
        $completeTraffic = $this->completeTrafficRow(80, 201, '2026-07-29', '2026-07-29 10:00:00');
        $latest = $this->row(80, 202, 'business', '2026-07-29', [
            'amount' => 500,
        ], [
            'sales_amount' => 500,
        ], ['sales_amount'], '2026-07-29 18:00:00');

        $summary = (new MeituanTemporalService())->buildSummaryFromRows(
            [$complete, $completeTraffic, $latest],
            80,
            '2026-07-29'
        );
        self::assertSame('partial', $summary['today']['status']);
        self::assertSame(500, $summary['today']['metrics']['sales_amount']['value']);
        self::assertNull($summary['today']['metrics']['lead_price']['value']);
        self::assertSame(201, $summary['today']['latest_verified_reference']['sync_task_id']);
    }

    public function testHotelIsolationAndYesterdayNineOClockGate(): void
    {
        $hotel80 = $this->completeBusinessRow(80, 301, '2026-07-28', '2026-07-29 08:30:00');
        $hotel81 = $this->completeBusinessRow(81, 999, '2026-07-28', '2026-07-29 08:59:00');
        $summary = (new MeituanTemporalService())->buildSummaryFromRows(
            [$hotel80, $hotel81],
            80,
            '2026-07-29',
            new DateTimeImmutable('2026-07-29 08:59:00', new DateTimeZone('Asia/Shanghai'))
        );

        self::assertSame('pending_source_update', $summary['yesterday']['status']);
        self::assertSame(301, $summary['yesterday']['metrics']['sales_amount']['sync_task_id']);
    }

    public function testPreviousDayYesterdaySnapshotIsReferenceNotCurrentEvidence(): void
    {
        $capturedAt = '2026-07-30 23:23:03';
        $rows = [$this->completeBusinessRow(80, 302, '2026-07-30', $capturedAt)];
        foreach ([
            'overall exposure' => 1567,
            'organic exposure' => 271,
            'ad exposure' => 1296,
        ] as $label => $value) {
            $rows[] = $this->row(80, 302, 'traffic_analysis', '2026-07-30', [
                'data_value' => $value,
                'dimension' => $label,
            ], [
                'name' => $label,
                'value' => $value,
            ], ['analysis_value'], $capturedAt);
        }

        $summary = (new MeituanTemporalService())->buildSummaryFromRows(
            $rows,
            80,
            '2026-07-31',
            new DateTimeImmutable('2026-07-31 01:30:00', new DateTimeZone('Asia/Shanghai'))
        );

        self::assertSame('pending_source_update', $summary['yesterday']['status']);
        self::assertNull($summary['yesterday']['captured_at']);
        self::assertNull($summary['yesterday']['metrics']['sales_amount']['value']);
        self::assertSame(302, $summary['yesterday']['latest_verified_reference']['sync_task_id']);
        self::assertSame(
            2026.78,
            $summary['yesterday']['latest_verified_reference']['metrics']['sales_amount']['value']
        );
    }

    public function testStructuredOrderFactsWinOverPlaceholderBusinessZeros(): void
    {
        $capturedAt = '2026-07-31 01:05:00';
        $rows = [
            $this->row(80, 2208, 'business', '2026-07-30', [
                'amount' => 0,
                'quantity' => 0,
                'book_order_num' => 0,
                'data_value' => 0,
            ], [
                'sales_amount' => 0,
                'sales_room_nights' => 0,
                'sales_avg_price' => 0,
            ], [
                'sales_amount', 'sales_room_nights', 'sales_avg_price',
            ], $capturedAt),
            $this->row(80, 2208, 'order', '2026-07-30', [
                'amount' => 6917.30,
                'quantity' => 9,
                'book_order_num' => 7,
                'data_value' => 768.59,
            ], [
                'amount' => 6917.30,
                'quantity' => 9,
                'book_order_num' => 7,
                'sales_avg_price' => 768.59,
            ], [
                'order_amount', 'room_nights', 'order_count',
            ], $capturedAt),
        ];
        foreach ([
            'overall exposure' => 1000,
            'organic exposure' => 600,
            'ad exposure' => 400,
        ] as $label => $value) {
            $rows[] = $this->row(80, 2208, 'traffic_analysis', '2026-07-30', [
                'data_value' => $value,
                'dimension' => $label,
            ], [
                'name' => $label,
                'value' => $value,
                '_capture_source' => 'dom:traffic:source_breakdown',
                '_source_path' => 'dom.traffic.source_breakdown.' . str_replace(' ', '_', $label),
            ], ['analysis_value'], $capturedAt);
        }

        $summary = (new MeituanTemporalService())->buildSummaryFromRows(
            $rows,
            80,
            '2026-07-31',
            new DateTimeImmutable('2026-07-31 01:30:00', new DateTimeZone('Asia/Shanghai'))
        );

        self::assertSame('pending_source_update', $summary['yesterday']['status']);
        self::assertSame(6917.30, $summary['yesterday']['metrics']['sales_amount']['value']);
        self::assertSame('verified', $summary['yesterday']['metrics']['sales_amount']['status']);
        self::assertSame(9, $summary['yesterday']['metrics']['sales_room_nights']['value']);
        self::assertSame('verified', $summary['yesterday']['metrics']['sales_room_nights']['status']);
        self::assertSame(768.59, $summary['yesterday']['metrics']['sales_avg_price']['value']);
        self::assertSame('derived', $summary['yesterday']['metrics']['sales_avg_price']['status']);
        self::assertNull($summary['yesterday']['metrics']['total_exposure']['value']);
        self::assertSame('missing', $summary['yesterday']['metrics']['total_exposure']['status']);
        self::assertSame(
            'before_platform_update_window',
            $summary['yesterday']['metrics']['total_exposure']['reason_code']
        );
    }

    public function testEmptyFutureModuleIsPendingDuringEarlyPlatformUpdateWindow(): void
    {
        $service = new MeituanTemporalService();
        $early = $service->buildSummaryFromRows(
            [],
            80,
            '2026-07-30',
            new DateTimeImmutable('2026-07-30 02:21:21', new DateTimeZone('Asia/Shanghai'))
        );
        self::assertSame('pending_source_update', $early['future']['status']);
        self::assertSame(
            'before_future_platform_update_window',
            $early['future']['reason_code']
        );
        self::assertSame([], $early['future']['rows']);

        $afterWindow = $service->buildSummaryFromRows(
            [],
            80,
            '2026-07-30',
            new DateTimeImmutable('2026-07-30 09:00:00', new DateTimeZone('Asia/Shanghai'))
        );
        self::assertSame('missing', $afterWindow['future']['status']);
        self::assertSame('current_snapshot_missing', $afterWindow['future']['reason_code']);
    }

    public function testFutureRowsRequireSemanticTypesAndKeepPeerAverages(): void
    {
        $rows = [];
        foreach (['pv' => [31, 11], 'uv' => [20, 8], 'advance_orders' => [2, 0.5]] as $type => [$current, $peer]) {
            $rows[] = $this->row(80, 401, 'traffic_forecast', '2026-07-30', [
                'data_value' => $current,
                'dimension' => 'traffic_forecast:' . $type,
            ], [
                'forecast_type' => $type,
                'current' => $current,
                'peer_avg' => $peer,
            ], ['forecast_current', 'forecast_peer_average'], '2026-07-29 18:08:00');
        }
        $rows[] = $this->row(80, 401, 'traffic_forecast', '2026-07-31', [
            'data_value' => 999,
            'dimension' => 'traffic_forecast:1',
        ], [
            'forecast_type' => '1',
            'current' => 999,
        ], ['forecast_current'], '2026-07-29 18:08:00');

        $summary = (new MeituanTemporalService())->buildSummaryFromRows($rows, 80, '2026-07-29');
        self::assertCount(1, $summary['future']['rows']);
        self::assertSame(31, $summary['future']['rows'][0]['metrics']['pv']['value']);
        self::assertSame(11, $summary['future']['rows'][0]['metrics']['pv_peer_avg']['value']);
        self::assertSame(0.5, $summary['future']['rows'][0]['metrics']['advance_orders_peer_avg']['value']);
        self::assertSame('ready', $summary['future']['rows'][0]['status']);
    }

    public function testFutureLatestSnapshotDoesNotBlendOlderTabsAndKeepsReferenceSeparate(): void
    {
        $rows = [];
        $start = new DateTimeImmutable('2026-07-29', new DateTimeZone('Asia/Shanghai'));
        for ($day = 0; $day < 30; $day++) {
            $targetDate = $start->modify('+' . $day . ' days')->format('Y-m-d');
            foreach (['pv' => [31, 11], 'uv' => [20, 8], 'advance_orders' => [2, 0.5]] as $type => [$current, $peer]) {
                $rows[] = $this->row(80, 410, 'traffic_forecast', $targetDate, [
                    'data_value' => $current,
                    'dimension' => 'traffic_forecast:' . $type,
                ], [
                    'forecast_type' => $type,
                    'current' => $current,
                    'peer_avg' => $peer,
                ], ['forecast_current', 'forecast_peer_average'], '2026-07-29 09:00:00');
            }
        }
        $rows[] = $this->row(80, 411, 'traffic_forecast', '2026-07-30', [
            'data_value' => 42,
            'dimension' => 'traffic_forecast:pv',
        ], [
            'forecast_type' => 'pv',
            'current' => 42,
            'peer_avg' => 12,
        ], ['forecast_current', 'forecast_peer_average'], '2026-07-29 18:08:00');

        $summary = (new MeituanTemporalService())->buildSummaryFromRows($rows, 80, '2026-07-29');

        self::assertSame('partial', $summary['future']['status']);
        self::assertSame(42, $summary['future']['rows'][0]['metrics']['pv']['value']);
        self::assertNull($summary['future']['rows'][0]['metrics']['uv']['value']);
        self::assertSame(410, $summary['future']['latest_verified_reference']['sync_task_id']);
        self::assertCount(30, $summary['future']['latest_verified_reference']['rows']);
        self::assertSame(
            '2026-07-29',
            $summary['future']['latest_verified_reference']['rows'][0]['target_date']
        );
        self::assertSame(
            '2026-08-27',
            $summary['future']['latest_verified_reference']['rows'][29]['target_date']
        );
    }

    public function testPreviousDayFutureSnapshotIsReferenceNotCurrentEvidence(): void
    {
        $rows = [];
        $start = new DateTimeImmutable('2026-07-30', new DateTimeZone('Asia/Shanghai'));
        for ($day = 0; $day < 30; $day++) {
            $targetDate = $start->modify('+' . $day . ' days')->format('Y-m-d');
            foreach (['pv' => [28, 12], 'uv' => [26, 10], 'advance_orders' => [2, 0]] as $type => [$current, $peer]) {
                $rows[] = $this->row(80, 412, 'traffic_forecast', $targetDate, [
                    'data_value' => $current,
                    'dimension' => 'traffic_forecast:' . $type,
                ], [
                    'forecast_type' => $type,
                    'current' => $current,
                    'peer_avg' => $peer,
                ], ['forecast_current', 'forecast_peer_average'], '2026-07-30 23:23:03');
            }
        }

        $summary = (new MeituanTemporalService())->buildSummaryFromRows(
            $rows,
            80,
            '2026-07-31',
            new DateTimeImmutable('2026-07-31 01:30:00', new DateTimeZone('Asia/Shanghai'))
        );

        self::assertSame('pending_source_update', $summary['future']['status']);
        self::assertSame('before_future_platform_update_window', $summary['future']['reason_code']);
        self::assertNull($summary['future']['captured_at']);
        self::assertSame([], $summary['future']['rows']);
        self::assertSame(412, $summary['future']['latest_verified_reference']['sync_task_id']);
        self::assertCount(30, $summary['future']['latest_verified_reference']['rows']);
        self::assertSame(
            '2026-07-30',
            $summary['future']['latest_verified_reference']['rows'][0]['target_date']
        );
        self::assertSame(
            '2026-08-28',
            $summary['future']['latest_verified_reference']['rows'][29]['target_date']
        );
    }

    public function testRefreshPrefersReusableOwningProfileOverFailedProjection(): void
    {
        $method = new \ReflectionMethod(MeituanTemporalService::class, 'preferredProfileSource');
        $selected = $method->invoke(null, [
            [
                'id' => 101,
                'status' => 'failed',
                'data_type' => 'traffic',
                'current_session_verified' => false,
                'profile_reusable' => false,
                'config' => [
                    'store_id' => 'store-80',
                    'source_projection_ids' => [68],
                ],
            ],
            [
                'id' => 68,
                'status' => 'failed',
                'data_type' => 'business',
                'current_session_verified' => false,
                'profile_reusable' => true,
                'config' => [
                    'store_id' => 'store-80',
                ],
            ],
        ]);

        self::assertSame(68, $selected['id']);
    }

    public function testFutureRefreshGateRequiresOneCompleteSemanticSnapshot(): void
    {
        $rows = [];
        $start = new DateTimeImmutable('2026-07-29', new DateTimeZone('Asia/Shanghai'));
        for ($day = 0; $day < 30; $day++) {
            $targetDate = $start->modify('+' . $day . ' days')->format('Y-m-d');
            foreach (['pv', 'uv', 'advance_orders'] as $type) {
                $rows[] = $this->row(80, 501, 'traffic_forecast', $targetDate, [
                    'data_value' => 1,
                    'dimension' => 'flow_forecast_' . $type,
                ], [
                    'forecast_type' => $type,
                    'current' => 1,
                    'peer_avg' => 1,
                ], ['forecast_current', 'forecast_peer_average'], '2026-07-29 19:00:00');
            }
        }

        $method = new \ReflectionMethod(MeituanTemporalService::class, 'hasCompleteVerifiedFutureSnapshotRows');
        self::assertTrue($method->invoke(
            new MeituanTemporalService(),
            $rows,
            '2026-07-29',
            '2026-07-29'
        ));

        array_pop($rows);
        self::assertFalse($method->invoke(
            new MeituanTemporalService(),
            $rows,
            '2026-07-29',
            '2026-07-29'
        ));

        $untyped = $this->row(80, 502, 'traffic_forecast', '2026-07-30', [
            'data_value' => 999,
            'dimension' => 'flow_forecast',
        ], [
            'forecast_type' => '',
            'current' => 999,
            'peer_avg' => 1,
        ], ['forecast_current', 'forecast_peer_average'], '2026-07-29 20:00:00');
        self::assertFalse($method->invoke(
            new MeituanTemporalService(),
            [$untyped],
            '2026-07-29',
            '2026-07-29'
        ));
    }

    public function testMissingTargetDateTrafficDoesNotMasqueradeAsLoginFailure(): void
    {
        $method = new \ReflectionMethod(MeituanTemporalService::class, 'refreshReason');
        $service = new MeituanTemporalService();

        self::assertSame('meituan_target_date_traffic_missing', $method->invoke($service, [
            'status' => 'partial_success',
            'message' => 'profile_reused_no_target_date_traffic_rows',
        ]));
        self::assertSame('meituan_profile_login_required', $method->invoke($service, [
            'status' => 'waiting_config',
            'message' => 'Profile session login required.',
        ]));
    }

    public function testRefreshOnlyCompletesAfterSaveAndExactReadback(): void
    {
        $method = new \ReflectionMethod(MeituanTemporalService::class, 'refreshTaskOutcome');
        $service = new MeituanTemporalService();

        self::assertSame([
            'status' => 'completed',
            'reason_code' => 'capture_saved_and_read_back',
        ], $method->invoke($service, [
            'status' => 'success',
            'task_id' => 1985,
            'saved_count' => 2,
            'readback_verified' => true,
            'collection_result' => [
                'claim' => ['allowed' => true],
            ],
        ]));
        self::assertSame([
            'status' => 'partial',
            'reason_code' => 'meituan_authoritative_empty_no_snapshot',
        ], $method->invoke($service, [
            'status' => 'success',
            'task_id' => 1986,
            'saved_count' => 0,
            'readback_verified' => true,
            'message' => 'platform_returned_authoritative_empty',
        ]));
        self::assertSame([
            'status' => 'blocked',
            'reason_code' => 'meituan_capture_readback_missing',
        ], $method->invoke($service, [
            'status' => 'success',
            'task_id' => 1987,
            'saved_count' => 2,
            'readback_verified' => false,
        ]));
        self::assertSame([
            'status' => 'blocked',
            'reason_code' => 'meituan_collection_claim_blocked',
        ], $method->invoke($service, [
            'status' => 'success',
            'task_id' => 1988,
            'saved_count' => 2,
            'readback_verified' => true,
            'collection_result' => [
                'claim' => [
                    'allowed' => false,
                    'reason_codes' => ['structured_response_required'],
                ],
            ],
        ]));
        self::assertSame([
            'status' => 'blocked',
            'reason_code' => 'meituan_capture_readback_missing',
        ], $method->invoke($service, [
            'status' => 'success',
            'task_id' => 0,
            'saved_count' => 2,
            'readback_verified' => true,
        ]));
        self::assertSame([
            'status' => 'blocked',
            'reason_code' => 'meituan_profile_login_required',
        ], $method->invoke($service, [
            'status' => 'waiting_config',
            'task_id' => 0,
            'saved_count' => 0,
            'readback_verified' => false,
            'message' => 'Profile session login required.',
        ]));
    }

    public function testRefreshDateMustMatchCurrentShanghaiBusinessDate(): void
    {
        $method = new \ReflectionMethod(MeituanTemporalService::class, 'sameLocalDate');
        $service = new MeituanTemporalService();

        self::assertTrue($method->invoke(
            $service,
            new DateTimeImmutable('2026-07-30 00:05:00', new DateTimeZone('Asia/Shanghai')),
            new DateTimeImmutable('2026-07-29 16:05:00', new DateTimeZone('UTC'))
        ));
        self::assertFalse($method->invoke(
            $service,
            new DateTimeImmutable('2026-07-29 23:59:59', new DateTimeZone('Asia/Shanghai')),
            new DateTimeImmutable('2026-07-30 00:00:00', new DateTimeZone('Asia/Shanghai'))
        ));
    }

    public function testYesterdayRefreshGateRequiresOneCompleteVerifiedSnapshot(): void
    {
        $capturedAt = '2026-07-30 09:05:00';
        $rows = [$this->completeBusinessRow(80, 601, '2026-07-29', $capturedAt)];
        $method = new \ReflectionMethod(
            MeituanTemporalService::class,
            'hasCompleteVerifiedYesterdaySnapshotRows'
        );
        $service = new MeituanTemporalService();

        self::assertFalse($method->invoke($service, $rows, '2026-07-29', '2026-07-30'));
        foreach ([
            'overall exposure' => 1567,
            'organic exposure' => 271,
            'ad exposure' => 1296,
        ] as $label => $value) {
            $rows[] = $this->row(80, 601, 'traffic_analysis', '2026-07-29', [
                'data_value' => $value,
                'dimension' => $label,
            ], [
                'name' => $label,
                'value' => $value,
            ], ['analysis_value'], $capturedAt);
        }
        self::assertTrue($method->invoke($service, $rows, '2026-07-29', '2026-07-30'));

        $rows[] = $this->completeBusinessRow(80, 602, '2026-07-29', '2026-07-30 10:00:00');
        self::assertTrue($method->invoke($service, $rows, '2026-07-29', '2026-07-30'));
    }

    public function testMetricsRequirePlatformHotelIdentityAndStructuredFieldPath(): void
    {
        $business = $this->completeBusinessRow(80, 701, '2026-07-29', '2026-07-29 18:00:00');
        $traffic = $this->completeTrafficRow(80, 701, '2026-07-29', '2026-07-29 18:00:00');
        $raw = json_decode((string)$traffic['raw_data'], true);
        unset($raw['platform_hotel_identifier_present']);
        $traffic['raw_data'] = json_encode($raw, JSON_UNESCAPED_UNICODE);

        $summary = (new MeituanTemporalService())->buildSummaryFromRows(
            [$business, $traffic],
            80,
            '2026-07-29'
        );
        self::assertSame('unverified', $summary['today']['metrics']['exposure_users']['status']);

        $traffic = $this->completeTrafficRow(80, 702, '2026-07-29', '2026-07-29 19:00:00');
        $raw = json_decode((string)$traffic['raw_data'], true);
        foreach ($raw['field_facts'] as &$fact) {
            if (($fact['metric_key'] ?? '') === 'exposure_users') {
                $fact['source_path'] = 'exposure_users';
            }
        }
        unset($fact);
        $traffic['raw_data'] = json_encode($raw, JSON_UNESCAPED_UNICODE);
        $summary = (new MeituanTemporalService())->buildSummaryFromRows(
            [$this->completeBusinessRow(80, 702, '2026-07-29', '2026-07-29 19:00:00'), $traffic],
            80,
            '2026-07-29'
        );
        self::assertSame('unverified', $summary['today']['metrics']['exposure_users']['status']);
    }

    public function testVerifiedCandidateWinsInsideOneSnapshotWithoutBorrowingAcrossSnapshots(): void
    {
        $unverified = $this->row(80, 703, 'traffic', '2026-07-29', [
            'list_exposure' => 999,
        ], [
            'exposure_users' => 999,
        ], ['exposure_users'], '2026-07-29 20:00:00');
        $raw = json_decode((string)$unverified['raw_data'], true);
        unset($raw['platform_hotel_identifier_present']);
        $unverified['raw_data'] = json_encode($raw, JSON_UNESCAPED_UNICODE);

        $summary = (new MeituanTemporalService())->buildSummaryFromRows([
            $this->completeBusinessRow(80, 703, '2026-07-29', '2026-07-29 20:00:00'),
            $unverified,
            $this->completeTrafficRow(80, 703, '2026-07-29', '2026-07-29 20:00:00'),
        ], 80, '2026-07-29');

        self::assertSame(81, $summary['today']['metrics']['exposure_users']['value']);
        self::assertSame('verified', $summary['today']['metrics']['exposure_users']['status']);
        self::assertSame(703, $summary['today']['metrics']['exposure_users']['sync_task_id']);
    }

    public function testFlowConversionModuleWinsOverHomeSummaryInsideTheSameTask(): void
    {
        $home = $this->row(80, 704, 'traffic', '2026-07-29', [
            'list_exposure' => 896,
            'detail_exposure' => 121,
            'book_order_num' => 3,
            'flow_rate' => 2.48,
        ], [
            '_source_path' => 'dom.traffic.home_summary',
            'exposure_users' => 896,
            'detail_visitors' => 121,
            'paid_order_count' => 3,
            'browse_to_pay_rate' => 2.48,
        ], [
            'exposure_users', 'detail_visitors', 'paid_order_count', 'browse_to_pay_rate',
        ], '2026-07-29 21:00:00');
        $flow = $this->row(80, 704, 'traffic', '2026-07-29', [
            'dimension' => 'flow_conversion',
            'list_exposure' => 102,
            'detail_exposure' => 19,
            'book_order_num' => 1,
            'flow_rate' => 5.26,
        ], [
            '_source_path' => 'data.myHotel',
            'dimension' => 'flow_conversion',
            'exposure_users' => 102,
            'detail_visitors' => 19,
            'paid_order_count' => 1,
            'browse_to_pay_rate' => 5.26,
        ], [
            'exposure_users', 'detail_visitors', 'paid_order_count', 'browse_to_pay_rate',
        ], '2026-07-29 21:00:00');

        $summary = (new MeituanTemporalService())->buildSummaryFromRows([
            $this->completeBusinessRow(80, 704, '2026-07-29', '2026-07-29 21:00:00'),
            $home,
            $flow,
        ], 80, '2026-07-29');

        self::assertSame(102, $summary['today']['metrics']['exposure_users']['value']);
        self::assertSame(19, $summary['today']['metrics']['detail_visitors']['value']);
        self::assertSame(1, $summary['today']['metrics']['paid_order_count']['value']);
        self::assertSame(5.26, $summary['today']['metrics']['browse_to_pay_rate']['value']);
        self::assertSame('ready', $summary['today']['status']);
    }

    private function completeBusinessRow(
        int $hotelId,
        int $taskId,
        string $dataDate,
        string $capturedAt
    ): array {
        return $this->row($hotelId, $taskId, 'business', $dataDate, [
            'amount' => 2026.78,
            'quantity' => 2,
            'book_order_num' => 1,
            'data_value' => 1013.39,
            'list_exposure' => 81,
            'detail_exposure' => 77,
            'flow_rate' => 1.30,
        ], [
            'lead_price' => 868,
            'sales_amount' => 2026.78,
            'sales_room_nights' => 2,
            'sales_avg_price' => 1013.39,
            'exposure_users' => 81,
            'detail_visitors' => 77,
            'paid_order_count' => 1,
            'browse_to_pay_rate' => 1.30,
        ], [
            'lead_price', 'sales_amount', 'sales_room_nights', 'sales_avg_price',
            'exposure_users', 'detail_visitors', 'paid_order_count', 'browse_to_pay_rate',
        ], $capturedAt);
    }

    private function completeTrafficRow(
        int $hotelId,
        int $taskId,
        string $dataDate,
        string $capturedAt
    ): array {
        return $this->row($hotelId, $taskId, 'traffic', $dataDate, [
            'list_exposure' => 81,
            'detail_exposure' => 77,
            'book_order_num' => 1,
            'flow_rate' => 1.30,
        ], [
            'exposure_users' => 81,
            'detail_visitors' => 77,
            'paid_order_count' => 1,
            'browse_to_pay_rate' => 1.30,
        ], [
            'exposure_users', 'detail_visitors', 'paid_order_count', 'browse_to_pay_rate',
        ], $capturedAt);
    }

    /**
     * @param array<string, mixed> $columns
     * @param array<string, mixed> $rawRow
     * @param array<int, string> $capturedFacts
     * @return array<string, mixed>
     */
    private function row(
        int $hotelId,
        int $taskId,
        string $dataType,
        string $dataDate,
        array $columns,
        array $rawRow,
        array $capturedFacts,
        string $capturedAt = '2026-07-29 18:00:00'
    ): array {
        $traceId = 'trace-' . $taskId . '-' . $dataType;
        $urlHash = hash('sha256', 'meituan-fixture:' . $traceId);
        $captureSource = strtolower(trim((string)(
            $rawRow['_capture_source'] ?? 'xhr:traffic:business_data'
        )));
        $sourcePath = trim((string)($rawRow['_source_path'] ?? 'data'));
        $facts = [];
        foreach ($capturedFacts as $metricKey) {
            $facts[] = [
                'metric_key' => $metricKey,
                'status' => 'captured',
                'stored_value_present' => true,
                'source_path' => '$.' . $metricKey,
                'capture_evidence' => [
                    'capture_source' => $captureSource,
                    'source_path' => $sourcePath,
                    'source_trace_id' => $traceId,
                    'source_url_hash' => $urlHash,
                ],
            ];
        }
        return array_merge([
            'id' => $taskId,
            'system_hotel_id' => $hotelId,
            'source' => 'meituan',
            'data_type' => $dataType,
            'data_date' => $dataDate,
            'compare_type' => $dataType === 'traffic_forecast' ? 'forecast' : 'self',
            'data_source_id' => 18,
            'sync_task_id' => $taskId,
            'ingestion_method' => 'browser_profile',
            'source_trace_id' => $traceId,
            'snapshot_time' => $capturedAt,
            'readback_verified' => 1,
            'raw_data' => json_encode([
                'row' => array_merge($rawRow, [
                    'dataDate' => $dataDate,
                    'date_source' => $dataType === 'traffic_forecast'
                        ? 'row.dateTime'
                        : 'page.business_period_selection.readback',
                    '_capture_source' => $captureSource,
                    '_source_path' => $sourcePath,
                    'capture_evidence' => [
                        'capture_source' => $captureSource,
                        'source_path' => $sourcePath,
                        'source_trace_id' => $traceId,
                        'source_url_hash' => $urlHash,
                    ],
                ]),
                'source_trace_id' => $traceId,
                'source_url_hash' => $urlHash,
                'capture_evidence' => [
                    'source_trace_id' => $traceId,
                    'source_url_hash' => $urlHash,
                ],
                'date_source' => $dataType === 'traffic_forecast'
                    ? 'row.dateTime'
                    : 'page.business_period_selection.readback',
                'captured_at' => $capturedAt,
                'platform_hotel_identifier_present' => true,
                'platform_hotel_identifier_source' => 'row.poi_id',
                'platform_hotel_identifier_proof' => 'row_field_present',
                'platform_hotel_binding_status' => 'matched',
                'platform_hotel_binding_proof' => 'source_and_response_match',
                'field_facts' => $facts,
            ], JSON_UNESCAPED_UNICODE),
        ], $columns);
    }
}
