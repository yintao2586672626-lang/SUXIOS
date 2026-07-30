<?php
declare(strict_types=1);

namespace Tests;

use app\service\CtripTemporalBroadcastService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class CtripTemporalBroadcastServiceTest extends TestCase
{
    public function testRealtimePreviewPreservesCapturedZeroAndUsesCtripFullInferenceOnly(): void
    {
        $result = $this->service()->build(
            [
                'system_hotel_id' => 80,
                'hotel_name' => '敦煌漠蓝新',
                'as_of_date' => '2026-07-30',
                'message_mode' => 'realtime',
                'present' => $this->present([
                    'starting_price' => 0,
                    'realtime_visitors' => 6,
                    'last_week_visitors' => 122,
                    'competitor_avg_visitor' => 13,
                    'traffic_rank' => 612,
                    'booking_orders' => 0,
                    'in_house_room_nights' => 2,
                    'list_exposure' => 296,
                    'detail_exposure' => 74,
                    'order_filling_num' => 2,
                    'order_submit_num' => 0,
                ]),
            ],
            $this->time('2026-07-30 01:30:00')
        );

        self::assertSame('partial', $result['status']);
        self::assertTrue($result['send_gate']['should_send']);
        self::assertSame(0, $result['segments']['present']['metrics']['starting_price']['value']);
        self::assertSame('captured', $result['segments']['present']['metrics']['starting_price']['status']);
        self::assertSame(
            25.0,
            $result['segments']['present']['metrics']['exposure_to_detail_rate']['value']
        );
        self::assertSame(
            'derived',
            $result['segments']['present']['metrics']['exposure_to_detail_rate']['status']
        );
        $content = $result['payload']['text']['content'];
        self::assertStringContainsString('采集时间 07-30 01:06', $content);
        self::assertStringContainsString('携程房态：疑似满房/无房可售｜实时起价 ¥0.00', $content);
        self::assertStringContainsString('预订 0｜在店间夜 2', $content);
        self::assertStringContainsString('曝光 296 → 详情 74 → 填写 2 → 提交 0', $content);
        self::assertStringContainsString('曝光→详情 25%', $content);
        self::assertStringNotContainsString('全酒店满房', $content);
        self::assertStringNotContainsString('竞争圈排名', $content);
    }

    public function testMissingMetricIsOmittedAndNeverReplacedWithZero(): void
    {
        $present = $this->present([
            'realtime_visitors' => 6,
            'booking_orders' => 0,
            'list_exposure' => 0,
            'detail_exposure' => 0,
        ]);
        $result = $this->service()->build([
            'system_hotel_id' => 80,
            'hotel_name' => '敦煌漠蓝新',
            'as_of_date' => '2026-07-30',
            'message_mode' => 'realtime',
            'present' => $present,
        ], $this->time('2026-07-30 01:30:00'));

        self::assertNull($result['segments']['present']['metrics']['starting_price']['value']);
        self::assertNull(
            $result['segments']['present']['metrics']['exposure_to_detail_rate']['value']
        );
        self::assertContains(
            'present:metric_missing:starting_price',
            $result['internal_gaps']
        );
        self::assertContains(
            'present:derived_metric_unavailable:exposure_to_detail_rate',
            $result['internal_gaps']
        );
        self::assertContains(
            'present:intraday_trend_missing',
            $result['internal_gaps']
        );
        $content = $result['payload']['text']['content'];
        self::assertStringNotContainsString('实时起价', $content);
        self::assertStringContainsString('预订 0', $content);
        self::assertStringNotContainsString('曝光→详情 0%', $content);
    }

    public function testStaleWarningAppearsOnlyAfterOneHourAndChangesFingerprintOnce(): void
    {
        $input = [
            'system_hotel_id' => 80,
            'hotel_name' => '敦煌漠蓝新',
            'as_of_date' => '2026-07-30',
            'message_mode' => 'realtime',
            'present' => $this->present([
                'realtime_visitors' => 6,
                'booking_orders' => 0,
            ]),
        ];
        $atBoundary = $this->service()->build(
            $input,
            $this->time('2026-07-30 02:06:11')
        );
        self::assertSame('fresh', $atBoundary['segments']['present']['freshness_status']);
        self::assertStringNotContainsString(
            '数据已超过1小时未更新',
            $atBoundary['payload']['text']['content']
        );

        $stale = $this->service()->build(
            $input,
            $this->time('2026-07-30 02:06:12')
        );
        self::assertSame('stale', $stale['segments']['present']['status']);
        self::assertStringContainsString(
            '数据已超过1小时未更新',
            $stale['payload']['text']['content']
        );
        self::assertNotSame($atBoundary['fingerprint'], $stale['fingerprint']);

        $duplicateInput = $input;
        $duplicateInput['previous_fingerprint'] = $stale['fingerprint'];
        $duplicate = $this->service()->build(
            $duplicateInput,
            $this->time('2026-07-30 02:30:00')
        );
        self::assertFalse($duplicate['send_gate']['should_send']);
        self::assertSame('snapshot_unchanged', $duplicate['send_gate']['reason_code']);
    }

    public function testBaselineCanBeSavedWithoutSending(): void
    {
        $result = $this->service()->build([
            'system_hotel_id' => 80,
            'hotel_name' => '敦煌漠蓝新',
            'as_of_date' => '2026-07-30',
            'message_mode' => 'realtime',
            'baseline_only' => true,
            'present' => $this->present(['realtime_visitors' => 6]),
        ], $this->time('2026-07-30 01:30:00'));

        self::assertFalse($result['send_gate']['should_send']);
        self::assertSame('baseline_only', $result['send_gate']['status']);
        self::assertSame('baseline_saved_without_alert', $result['send_gate']['reason_code']);
    }

    public function testDailyModeKeepsPastPresentFutureIndependentAndRejectsMixedFutureBatch(): void
    {
        $past = $this->envelope('2026-07-29', '2026-07-30 09:05:00', 1985);
        $past['is_final'] = true;
        $past['windows'] = [
            [
                'window' => 'yesterday',
                'metrics' => [
                    'list_exposure' => 200,
                    'detail_exposure' => 50,
                    'order_filling_num' => 3,
                    'order_submit_num' => 1,
                ],
            ],
            [
                'window' => 'last_7_days',
                'metrics' => [
                    'list_exposure' => 1400,
                    'detail_exposure' => 280,
                    'order_submit_num' => 8,
                ],
            ],
            [
                'window' => 'last_30_days',
                'metrics' => [
                    'list_exposure' => 6000,
                    'detail_exposure' => 1200,
                    'order_submit_num' => 30,
                ],
            ],
        ];
        $future = $this->envelope('2026-07-30', '2026-07-30 09:06:00', 1986);
        $future['rows'] = [
            [
                'batch_id' => '1986',
                'target_date' => '2026-08-01',
                'metrics' => [
                    'future_search_uv' => 20,
                    'future_search_pv' => 31,
                    'competitor_future_search_uv' => 8,
                ],
            ],
            [
                'batch_id' => 'old-batch',
                'target_date' => '2026-08-02',
                'metrics' => [
                    'future_search_uv' => 999,
                    'competitor_future_search_uv' => 1,
                ],
            ],
        ];

        $result = $this->service()->build([
            'system_hotel_id' => 80,
            'hotel_name' => '敦煌漠蓝新',
            'as_of_date' => '2026-07-30',
            'message_mode' => 'daily',
            'past' => $past,
            'present' => $this->present([
                'realtime_visitors' => 16,
                'competitor_avg_visitor' => 20,
                'booking_orders' => 1,
            ], '2026-07-30 09:04:00', 1984),
            'future' => $future,
        ], $this->time('2026-07-30 09:10:00'));

        self::assertSame(['past', 'present', 'future'], $result['selected_segments']);
        self::assertCount(1, $result['segments']['future']['rows']);
        self::assertContains(
            'future:future_row_batch_mismatch:1',
            $result['internal_gaps']
        );
        $content = $result['payload']['text']['content'];
        self::assertStringContainsString('过去｜流量复盘', $content);
        self::assertStringContainsString('如今｜房态与流量', $content);
        self::assertStringContainsString('未来｜需求研判', $content);
        self::assertStringContainsString('08-01：累计UV 20（竞争圈 8）', $content);
        self::assertStringNotContainsString('999', $content);
    }

    public function testUnverifiedOrCrossHotelSegmentIsBlockedWithoutPayload(): void
    {
        $present = $this->present(['realtime_visitors' => 6]);
        $present['system_hotel_id'] = 81;
        $present['readback_verified'] = false;

        $result = $this->service()->build([
            'system_hotel_id' => 80,
            'hotel_name' => '敦煌漠蓝新',
            'as_of_date' => '2026-07-30',
            'message_mode' => 'realtime',
            'present' => $present,
        ], $this->time('2026-07-30 01:30:00'));

        self::assertSame('blocked', $result['status']);
        self::assertNull($result['payload']);
        self::assertFalse($result['send_gate']['should_send']);
        self::assertContains(
            'system_hotel_id_mismatch',
            $result['segments']['present']['reason_codes']
        );
        self::assertContains(
            'readback_not_verified',
            $result['segments']['present']['reason_codes']
        );
    }

    public function testStoredRowsUseExactEndpointSemanticsAndIgnoreCrossHotelFacts(): void
    {
        $rows = [
            $this->storedRow(1, 'business_visitor_title', [
                $this->fact('visitor_count', 6, 'visitorTotal'),
                $this->fact('visitor_count_last_week', 122, 'lastVisitorTotal'),
                $this->fact('competitor_avg_visitor', 13, 'competitorAvgNumber'),
            ]),
            $this->storedRow(2, 'traffic_order_overview', [
                $this->fact('order_count', 0, 'data.orderQuantity'),
                $this->fact('order_count', 3, 'data.synchronizationOrderQuantity'),
            ]),
            $this->storedRow(3, 'business_capacity', [
                $this->fact('occupied_rooms', 2, 'occupiedRooms'),
                $this->fact('ctrip_order_count', 0, 'ctripOrderQuantity'),
            ]),
            $this->storedRow(4, 'traffic_hotel_seq', [
                $this->fact('traffic_rank', 612, 'data.rank'),
            ]),
            $this->storedRow(5, 'business_flow_transform', [
                $this->fact('list_exposure', 296, '0.listExposure'),
                $this->fact('detail_visitor', 74, '0.detailExposure'),
                $this->fact('order_page_visitor', 2, '0.orderFillingNum'),
                $this->fact('order_submit_user', 0, '0.orderSubmitNum'),
            ], ['dimension' => 'catalog:business_flow_transform:0.listExposure']),
            $this->storedFutureRow(
                6,
                '2026-08-01',
                'self',
                [
                    'future_search_pv' => 31,
                    'future_search_uv' => 20,
                    'future_search_conversion_rate' => 0,
                ]
            ),
            $this->storedFutureRow(
                7,
                '2026-08-01',
                'competitor_avg',
                [
                    'future_search_pv' => 18,
                    'future_search_uv' => 8,
                    'future_search_conversion_rate' => 4.5,
                ]
            ),
            $this->storedFutureRow(
                9,
                '2026-08-01',
                'self',
                ['future_search_pv' => 3, 'future_search_uv' => 2],
                'yesterday'
            ),
            $this->storedIntradayRow(
                11,
                '23:00',
                '2026-07-29T23:00:00+08:00',
                3,
                7
            ),
            $this->storedIntradayRow(
                12,
                '00:00',
                '2026-07-30T00:00:00+08:00',
                0,
                4
            ),
            $this->storedIntradayRow(
                13,
                '01:00',
                '2026-07-30T01:00:00+08:00',
                5,
                2
            ),
            $this->storedIntradayRow(
                14,
                '02:00',
                '2026-07-30T02:00:00+08:00',
                99,
                88,
                1,
                'pc_web'
            ),
            $this->storedRow(8, 'business_visitor_title', [
                $this->fact('visitor_count', 999, 'visitorTotal'),
            ], ['system_hotel_id' => 81]),
            $this->storedRow(10, 'business_visitor_title', [
                $this->fact('visitor_count', 888, 'visitorTotal'),
            ], ['platform' => 'meituan']),
        ];

        $result = $this->service()->buildFromStoredRows(
            $rows,
            80,
            '敦煌漠蓝新',
            '2026-07-30',
            'daily',
            '',
            false,
            $this->time('2026-07-30 01:30:00')
        );

        self::assertSame(14, $result['fact_source']['candidate_row_count']);
        self::assertSame(12, $result['fact_source']['trusted_row_count']);
        $present = $result['segments']['present']['metrics'];
        self::assertSame(6, $present['realtime_visitors']['value']);
        self::assertSame(122, $present['last_week_visitors']['value']);
        self::assertSame(13, $present['competitor_avg_visitor']['value']);
        self::assertSame(0, $present['booking_orders']['value']);
        self::assertSame(2, $present['in_house_room_nights']['value']);
        self::assertSame(612, $present['traffic_rank']['value']);
        self::assertSame(296, $present['list_exposure']['value']);
        self::assertSame(74, $present['detail_exposure']['value']);
        self::assertSame(0, $present['order_submit_num']['value']);
        self::assertCount(1, $result['segments']['future']['rows']);
        self::assertSame(
            20,
            $result['segments']['future']['rows'][0]['metrics']['future_search_uv']['value']
        );
        self::assertSame(
            8,
            $result['segments']['future']['rows'][0]['metrics']['competitor_future_search_uv']['value']
        );
        self::assertStringContainsString(
            '昨日新增UV 2',
            $result['payload']['text']['content']
        );
        self::assertStringContainsString(
            '转化 0%（竞争圈 4.5%）',
            $result['payload']['text']['content']
        );
        self::assertStringContainsString(
            '当日走势：峰值 01:00 5｜最新 01:00 5',
            $result['payload']['text']['content']
        );
        self::assertStringNotContainsString('99', $result['payload']['text']['content']);
        self::assertStringNotContainsString('999', $result['payload']['text']['content']);
        self::assertStringNotContainsString('888', $result['payload']['text']['content']);
        self::assertStringNotContainsString('实时起价', $result['payload']['text']['content']);
    }

    public function testFutureConversionIsNeverDerivedFromOrdersAndUv(): void
    {
        $future = $this->envelope('2026-07-30', '2026-07-30 09:06:00', 1986);
        $future['rows'] = [[
            'batch_id' => '1986',
            'target_date' => '2026-08-01',
            'metrics' => [
                'future_search_uv' => 20,
                'future_search_order_count' => 2,
            ],
        ]];

        $result = $this->service()->build([
            'system_hotel_id' => 80,
            'hotel_name' => '敦煌漠蓝新',
            'as_of_date' => '2026-07-30',
            'message_mode' => 'future',
            'future' => $future,
        ], $this->time('2026-07-30 09:10:00'));

        self::assertNull(
            $result['segments']['future']['rows'][0]['metrics']
                ['future_search_conversion_rate']['value']
        );
        self::assertStringContainsString(
            '累计UV 20｜搜索订单 2',
            $result['payload']['text']['content']
        );
        self::assertStringNotContainsString('转化 10%', $result['payload']['text']['content']);
    }

    public function testStoredRowsRequireLineageAndReadbackBeforePreview(): void
    {
        $row = $this->storedRow(1, 'business_visitor_title', [
            $this->fact('visitor_count', 6, 'visitorTotal'),
        ]);
        $row['readback_verified'] = 0;
        $row['source_trace_id'] = '';

        $result = $this->service()->buildFromStoredRows(
            [$row],
            80,
            '敦煌漠蓝新',
            '2026-07-30',
            'realtime',
            '',
            false,
            $this->time('2026-07-30 01:30:00')
        );

        self::assertSame('blocked', $result['status']);
        self::assertSame(0, $result['fact_source']['trusted_row_count']);
        self::assertNull($result['payload']);
        self::assertFalse($result['send_gate']['should_send']);
    }

    public function testStoredHistoricalAggregateWindowsRemainDistinctAndSendable(): void
    {
        $rows = [
            $this->storedHistoricalWindowRow(701, 'yesterday', 296, 74, 2, 0),
            $this->storedHistoricalWindowRow(702, 'last_7_days', 1800, 420, 18, 4),
            $this->storedHistoricalWindowRow(703, 'last_30_days', 7600, 1690, 66, 15),
        ];

        $result = $this->service()->buildFromStoredRows(
            $rows,
            80,
            '敦煌漠蓝新',
            '2026-07-30',
            'review',
            '',
            false,
            $this->time('2026-07-30 09:30:00')
        );

        self::assertSame('available', $result['status']);
        self::assertSame(
            ['yesterday', 'last_7_days', 'last_30_days'],
            array_keys($result['segments']['past']['windows'])
        );
        $content = (string)$result['payload']['text']['content'];
        self::assertStringContainsString('昨日：曝光 296 → 详情 74 → 填写 2 → 提交 0', $content);
        self::assertStringContainsString('近7日：曝光 1800', $content);
        self::assertStringContainsString('近30日：曝光 7600', $content);
    }

    public function testStoredPresentCanUseTrafficFlowTransformWithoutBusinessDuplicate(): void
    {
        $row = $this->storedRow(710, 'traffic_flow_transform', [
            $this->fact('list_exposure', 296, '0.listExposure'),
            $this->fact('detail_visitor', 74, '0.detailExposure'),
            $this->fact('order_page_visitor', 2, '0.orderFillingNum'),
            $this->fact('order_submit_user', 0, '0.orderSubmitNum'),
        ], ['dimension' => 'catalog:traffic_report:traffic_flow_transform:flow:0']);

        $result = $this->service()->buildFromStoredRows(
            [$row],
            80,
            '敦煌漠蓝新',
            '2026-07-30',
            'realtime',
            '',
            false,
            $this->time('2026-07-30 01:30:00')
        );

        self::assertSame('partial', $result['status']);
        self::assertStringContainsString(
            '曝光 296 → 详情 74 → 填写 2 → 提交 0',
            (string)$result['payload']['text']['content']
        );
    }

    public function testStoredStartingPriceUsesExactMinimumPriceEndpointAndPreservesZero(): void
    {
        $result = $this->service()->buildFromStoredRows(
            [
                $this->storedRow(720, 'traffic_hotel_min_price', [
                    $this->fact('min_price', 0, 'minPrice'),
                ]),
                $this->storedRow(721, 'business_realtime', [
                    $this->fact('starting_price', 999, 'unrelated.startingPrice'),
                ]),
            ],
            80,
            'Dunhuang Molan',
            '2026-07-30',
            'realtime',
            '',
            false,
            $this->time('2026-07-30 01:30:00')
        );

        self::assertSame(
            0,
            $result['segments']['present']['metrics']['starting_price']['value']
        );
        self::assertStringContainsString(
            '0.00',
            (string)$result['payload']['text']['content']
        );
        self::assertStringNotContainsString(
            '999',
            (string)$result['payload']['text']['content']
        );
    }

    public function testStoredFutureRejectsRealtimeSnapshotLookalike(): void
    {
        $lookalike = $this->storedFutureRow(
            730,
            '2026-08-01',
            'self',
            ['future_search_uv' => 999]
        );
        $lookalike['data_period'] = 'realtime_snapshot';

        $blocked = $this->service()->buildFromStoredRows(
            [$lookalike],
            80,
            'Dunhuang Molan',
            '2026-07-30',
            'future',
            '',
            false,
            $this->time('2026-07-30 01:30:00')
        );
        self::assertSame('blocked', $blocked['segments']['future']['status']);
        self::assertNull($blocked['payload']);

        $verified = $this->service()->buildFromStoredRows(
            [
                $this->storedFutureRow(
                    731,
                    '2026-08-01',
                    'self',
                    ['future_search_uv' => 20]
                ),
            ],
            80,
            'Dunhuang Molan',
            '2026-07-30',
            'future',
            '',
            false,
            $this->time('2026-07-30 01:30:00')
        );
        self::assertSame('available', $verified['segments']['future']['status']);
        self::assertSame(
            20,
            $verified['segments']['future']['rows'][0]['metrics']
                ['future_search_uv']['value']
        );
    }

    public function testFutureUsesThirtyInclusiveDatesAndIgnoresShiftedComparisonTail(): void
    {
        $result = $this->service()->buildFromStoredRows(
            [
                $this->storedFutureRow(
                    740,
                    '2026-08-28',
                    'self',
                    ['future_search_uv' => 20]
                ),
                $this->storedFutureRow(
                    741,
                    '2026-08-28',
                    'competitor_avg',
                    ['future_search_uv' => 8]
                ),
                $this->storedFutureRow(
                    742,
                    '2026-08-29',
                    'self',
                    ['future_search_uv' => 999],
                    'yesterday'
                ),
                $this->storedFutureRow(
                    743,
                    '2026-08-29',
                    'competitor_avg',
                    ['future_search_uv' => 888],
                    'yesterday'
                ),
            ],
            80,
            'Dunhuang Molan',
            '2026-07-30',
            'future',
            '',
            false,
            $this->time('2026-07-30 01:30:00')
        );

        self::assertSame('available', $result['segments']['future']['status']);
        self::assertCount(1, $result['segments']['future']['rows']);
        self::assertSame(
            '2026-08-28',
            $result['segments']['future']['rows'][0]['target_date']
        );
        self::assertNotContains(
            'future:future_metrics_missing:2026-08-29',
            $result['internal_gaps']
        );
        self::assertStringNotContainsString(
            '999',
            (string)$result['payload']['text']['content']
        );
        self::assertStringNotContainsString(
            '888',
            (string)$result['payload']['text']['content']
        );
    }

    private function service(): CtripTemporalBroadcastService
    {
        return new CtripTemporalBroadcastService();
    }

    /** @return array<string, mixed> */
    private function present(
        array $metrics,
        string $capturedAt = '2026-07-30 01:06:11',
        int $taskId = 1984
    ): array {
        $segment = $this->envelope('2026-07-30', $capturedAt, $taskId);
        $segment['metrics'] = $metrics;
        return $segment;
    }

    /** @return array<string, mixed> */
    private function envelope(string $dataDate, string $capturedAt, int $taskId): array
    {
        return [
            'system_hotel_id' => 80,
            'platform' => 'ctrip',
            'data_date' => $dataDate,
            'captured_at' => $capturedAt,
            'sync_task_id' => $taskId,
            'readback_verified' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function storedRow(
        int $id,
        string $endpointId,
        array $facts,
        array $overrides = []
    ): array {
        return array_replace([
            'id' => $id,
            'tenant_id' => 1,
            'system_hotel_id' => 80,
            'source' => 'ctrip',
            'platform' => 'ctrip',
            'data_date' => '2026-07-30',
            'data_type' => 'traffic',
            'dimension' => '',
            'data_period' => 'realtime_snapshot',
            'snapshot_time' => '2026-07-30 01:06:11',
            'is_final' => 0,
            'readback_verified' => 1,
            'validation_status' => 'normal',
            'data_source_id' => 25,
            'sync_task_id' => 1984,
            'source_trace_id' => 'trace-' . $id,
            'raw_data' => [
                'endpoint_id' => $endpointId,
                'captured_at' => '2026-07-30 01:06:11',
                'field_facts' => $facts,
            ],
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function storedFutureRow(
        int $id,
        string $targetDate,
        string $scope,
        array $metrics,
        string $window = 'cumulative'
    ): array {
        $row = $this->storedRow($id, 'traffic_search_details', []);
        $row['data_period'] = 'next_30_days';
        $row['raw_data']['dimension_values'] = [
            'target_date' => $targetDate,
            'search_window' => $window,
            'compare_scope' => $scope,
        ];
        $row['raw_data']['metrics'] = $metrics;
        return $row;
    }

    /** @return array<string, mixed> */
    private function storedIntradayRow(
        int $id,
        string $time,
        string $timestamp,
        int $visitors,
        int $lastWeekVisitors,
        int $channelCode = 0,
        string $channel = 'app'
    ): array {
        $row = $this->storedRow($id, 'traffic_realtime_visitor_trend', []);
        $row['raw_data']['dimension_values'] = [
            'intraday_channel_code' => $channelCode,
            'intraday_channel' => $channel,
            'intraday_time_point' => $time,
            'intraday_timestamp' => $timestamp,
        ];
        $row['raw_data']['metrics'] = [
            'intraday_visitor_count' => $visitors,
            'intraday_last_week_visitor_count' => $lastWeekVisitors,
        ];
        return $row;
    }

    /** @return array<string, mixed> */
    private function storedHistoricalWindowRow(
        int $id,
        string $window,
        int $exposure,
        int $detail,
        int $fill,
        int $submit
    ): array {
        $row = $this->storedRow($id, 'traffic_flow_transform', [
            $this->fact('list_exposure', $exposure, '0.listExposure'),
            $this->fact('detail_visitor', $detail, '0.detailExposure'),
            $this->fact('order_page_visitor', $fill, '0.orderFillingNum'),
            $this->fact('order_submit_user', $submit, '0.orderSubmitNum'),
        ], [
            'data_date' => '2026-07-29',
            'data_period' => 'historical_daily',
            'snapshot_time' => null,
            'is_final' => 1,
            'sync_task_id' => 2001,
            'dimension' => 'catalog:traffic_report:traffic_flow_transform:flow:0:window=' . $window,
        ]);
        $row['raw_data']['captured_at'] = '2026-07-30 09:05:00';
        $row['raw_data']['dimension_values'] = ['analysis_window' => $window];
        return $row;
    }

    /** @return array<string, mixed> */
    private function fact(string $key, int|float|null $value, string $path): array
    {
        return [
            'metric_key' => $key,
            'value' => $value,
            'source_path' => $path,
            'fact_status' => $value === null ? 'missing' : 'captured',
        ];
    }

    private function time(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('Asia/Shanghai'));
    }
}
