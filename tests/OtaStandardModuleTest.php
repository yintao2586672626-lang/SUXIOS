<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaInsightAnalysisService;
use app\service\OtaRevenueMetricService;
use app\service\OtaStandardEtlService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class OtaStandardModuleTest extends TestCase
{
    public function testEtlBuildsStarSchemaFromOnlineDailyRows(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows($this->sampleRows());

        self::assertSame('ready', $dataset['status']);
        self::assertCount(2, $dataset['dim_hotel']);
        self::assertCount(2, $dataset['dim_platform']);
        self::assertCount(1, $dataset['fact_ota_daily']);
        self::assertCount(1, $dataset['fact_ota_traffic']);
        self::assertCount(1, $dataset['fact_ota_comment']);

        self::assertSame('system:7', $dataset['fact_ota_daily'][0]['hotel_key']);
        self::assertSame('ctrip', $dataset['fact_ota_daily'][0]['platform_key']);
        self::assertSame(1200.0, $dataset['fact_ota_daily'][0]['revenue']);
        self::assertSame(1200.0, $dataset['fact_ota_daily'][0]['room_revenue']);
        self::assertSame(
            'direct_room_revenue_field',
            $dataset['fact_ota_daily'][0]['room_revenue_basis']
        );
        self::assertSame(6.0, $dataset['fact_ota_daily'][0]['room_nights']);
        self::assertSame(4, $dataset['fact_ota_daily'][0]['order_count']);
        self::assertSame(4, $dataset['fact_ota_daily'][0]['gross_order_count']);
        self::assertSame(0, $dataset['fact_ota_daily'][0]['unknown_status_order_count']);
        self::assertSame(
            'cancelled_orders_over_gross_orders_complete_classification',
            $dataset['fact_ota_daily'][0]['cancel_rate_basis']
        );
        self::assertSame(200.0, $dataset['fact_ota_daily'][0]['adr']);
        self::assertSame('ota_channel', $dataset['fact_ota_daily'][0]['metric_scope']);
        self::assertSame(10.0, $dataset['fact_ota_daily'][0]['available_room_nights']);
        self::assertSame(6.0, $dataset['fact_ota_daily'][0]['occupied_room_nights']);
        self::assertSame(60.0, $dataset['fact_ota_daily'][0]['occ']);
        self::assertSame(120.0, $dataset['fact_ota_daily'][0]['revpar']);
        self::assertSame(180.0, $dataset['fact_ota_daily'][0]['commission_amount']);
        self::assertSame(15.0, $dataset['fact_ota_daily'][0]['commission_rate']);
        self::assertSame(1020.0, $dataset['fact_ota_daily'][0]['net_revenue']);
        self::assertSame('derived_from_commission_amount', $dataset['fact_ota_daily'][0]['net_revenue_basis']);
        self::assertSame('derived_from_commission_rate', $dataset['fact_ota_daily'][0]['commission_amount_basis']);
        self::assertSame(102.0, $dataset['fact_ota_daily'][0]['net_revpar']);
        self::assertSame('2026-05-10', $dataset['fact_ota_daily'][0]['booking_date']);
        self::assertSame('2026-05-18', $dataset['fact_ota_daily'][0]['checkin_date']);
        self::assertSame(8, $dataset['fact_ota_daily'][0]['lead_time_days']);

        self::assertSame(20.0, $dataset['fact_ota_traffic'][0]['flow_rate']);
        self::assertSame(33.33, $dataset['fact_ota_traffic'][0]['submit_rate']);
        self::assertSame('system:8', $dataset['fact_ota_comment'][0]['hotel_key']);
        self::assertSame('meituan', $dataset['fact_ota_comment'][0]['platform_key']);
        self::assertSame('review:meituan', $dataset['fact_ota_comment'][0]['dimension']);
        self::assertSame(3.0, $dataset['fact_ota_comment'][0]['comment_score']);
        self::assertSame(1.0, $dataset['fact_ota_comment'][0]['comment_count']);
        self::assertSame(1.0, $dataset['fact_ota_comment'][0]['bad_review_count']);
        self::assertArrayNotHasKey('content', $dataset['fact_ota_comment'][0]['raw_data']);
        self::assertSame([], $dataset['data_quality']['rejected_rows']);
    }

    public function testRevenueMetricsUseStandardFactsWithoutInventingMissingCancellationData(): void
    {
        $etl = new OtaStandardEtlService();
        $dataset = $etl->buildDatasetFromRows($this->sampleRows());
        $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);

        self::assertSame(1200.0, $metrics['totals']['revenue']);
        self::assertSame(1200.0, $metrics['totals']['room_revenue']);
        self::assertSame(1020.0, $metrics['totals']['net_revenue']);
        self::assertSame(180.0, $metrics['totals']['commission_amount']);
        self::assertSame(15.0, $metrics['totals']['commission_rate']);
        self::assertSame(6.0, $metrics['totals']['room_nights']);
        self::assertSame(10.0, $metrics['totals']['available_room_nights']);
        self::assertSame(6.0, $metrics['totals']['occupied_room_nights']);
        self::assertSame(4, $metrics['totals']['order_count']);
        self::assertSame(4, $metrics['totals']['gross_order_count']);
        self::assertSame(1, $metrics['totals']['cancel_order_count']);
        self::assertTrue(
            $metrics['metric_trust']['totals.gross_order_count']['saved_success']
        );
        self::assertSame(
            'cancelled_orders_over_gross_orders_complete_classification',
            $metrics['totals']['cancellation_rate_basis']
        );
        self::assertSame(200.0, $metrics['totals']['adr']);
        self::assertSame(60.0, $metrics['totals']['occ']);
        self::assertSame(120.0, $metrics['totals']['revpar']);
        self::assertSame(102.0, $metrics['totals']['net_revpar']);
        self::assertSame(8.0, $metrics['totals']['avg_lead_time_days']);
        self::assertSame(25.0, $metrics['totals']['cancellation_rate']);
        self::assertSame(16.67, $metrics['totals']['room_night_cancellation_rate']);
        self::assertSame(20.0, $metrics['traffic']['avg_flow_rate']);
        self::assertSame(33.33, $metrics['traffic']['avg_submit_rate']);
        self::assertSame(-20.0, $metrics['competitor_price']['avg_price_gap']);
        self::assertSame(-9.09, $metrics['competitor_price']['avg_price_gap_rate']);
        self::assertSame('fact_ota_daily', $metrics['fact_table']['name']);
        self::assertArrayHasKey('revpar', $metrics['metric_definitions']['metrics']);
        self::assertSame(100.0, $metrics['channel_contribution'][0]['contribution_rate']);
        self::assertSame(100.0, $metrics['channel_contribution'][0]['net_contribution_rate']);

        $missingCancel = (new OtaRevenueMetricService())->summarizeDataset($etl->buildDatasetFromRows([
            array_replace($this->sampleRows()[0], [
                'raw_data' => json_encode(['our_price' => 200, 'competitor_price' => 220], JSON_UNESCAPED_UNICODE),
            ]),
        ]));

        self::assertNull($missingCancel['totals']['cancellation_rate']);
        self::assertContains('cancellation_fields_missing', array_column($missingCancel['data_gaps'], 'code'));

        $grossCancel = (new OtaRevenueMetricService())->summarizeDataset([
            'fact_ota_daily' => [
                [
                    'platform_key' => 'ctrip',
                    'hotel_key' => 'system:7',
                    'revenue' => 100.0,
                    'room_nights' => 1.0,
                    'order_count' => 1,
                    'gross_order_count' => 2,
                    'cancel_order_num' => 1,
                    'unknown_status_order_count' => 0,
                    'cancel_rate_basis' =>
                        'cancelled_orders_over_gross_orders_complete_classification',
                ],
            ],
            'fact_ota_traffic' => [],
            'fact_ota_comment' => [],
        ]);

        self::assertSame(50.0, $grossCancel['totals']['cancellation_rate']);
        self::assertSame(2, $grossCancel['totals']['gross_order_count']);
        self::assertSame(1, $grossCancel['totals']['cancel_order_count']);

        $missingGross = (new OtaRevenueMetricService())->summarizeDataset([
            'fact_ota_daily' => [[
                'platform_key' => 'ctrip',
                'hotel_key' => 'system:7',
                'order_count' => 10,
                'cancel_order_num' => 2,
            ]],
            'fact_ota_traffic' => [],
            'fact_ota_comment' => [],
        ]);
        self::assertNull($missingGross['totals']['cancellation_rate']);
        self::assertContains(
            'cancellation_gross_order_base_missing',
            array_column($missingGross['data_gaps'], 'code')
        );

        $unknownStatuses = (new OtaRevenueMetricService())->summarizeDataset([
            'fact_ota_daily' => [[
                'platform_key' => 'ctrip',
                'hotel_key' => 'system:7',
                'order_count' => 7,
                'gross_order_count' => 10,
                'cancel_order_num' => 2,
                'unknown_status_order_count' => 1,
                'cancel_rate_basis' =>
                    'cancelled_orders_over_gross_orders_complete_classification',
            ]],
            'fact_ota_traffic' => [],
            'fact_ota_comment' => [],
        ]);
        self::assertNull($unknownStatuses['totals']['cancellation_rate']);
        self::assertContains(
            'cancellation_status_classification_incomplete',
            array_column($unknownStatuses['data_gaps'], 'code')
        );

        $classificationMismatch = (new OtaRevenueMetricService())->summarizeDataset([
            'fact_ota_daily' => [[
                'platform_key' => 'ctrip',
                'hotel_key' => 'system:7',
                'gross_order_count' => 1,
                'cancel_order_num' => 2,
                'unknown_status_order_count' => 0,
                'cancel_rate_basis' =>
                    'cancelled_orders_over_gross_orders_complete_classification',
            ]],
            'fact_ota_traffic' => [],
            'fact_ota_comment' => [],
        ]);
        self::assertNull($classificationMismatch['totals']['cancellation_rate']);
        self::assertContains(
            'cancellation_order_classification_mismatch',
            array_column($classificationMismatch['data_gaps'], 'code')
        );

        $directRate = (new OtaRevenueMetricService())->summarizeDataset([
            'fact_ota_daily' => [[
                'platform_key' => 'ctrip',
                'hotel_key' => 'system:7',
                'cancel_rate' => 12.5,
            ]],
            'fact_ota_traffic' => [],
            'fact_ota_comment' => [],
        ]);
        self::assertSame(12.5, $directRate['totals']['cancellation_rate']);
        self::assertSame(
            'platform_supplied_direct_rate',
            $directRate['totals']['cancellation_rate_basis']
        );

        $directRateWithUnknownStatus = (new OtaRevenueMetricService())
            ->summarizeDataset([
                'fact_ota_daily' => [[
                    'platform_key' => 'ctrip',
                    'hotel_key' => 'system:7',
                    'cancel_rate' => 12.5,
                    'unknown_status_order_count' => 1,
                ]],
                'fact_ota_traffic' => [],
                'fact_ota_comment' => [],
            ]);
        self::assertNull(
            $directRateWithUnknownStatus['totals']['cancellation_rate']
        );
        self::assertContains(
            'cancellation_status_classification_incomplete',
            array_column(
                $directRateWithUnknownStatus['data_gaps'],
                'code'
            )
        );

        $partialCoverage = (new OtaRevenueMetricService())->summarizeDataset([
            'fact_ota_daily' => [
                [
                    'platform_key' => 'ctrip',
                    'hotel_key' => 'system:7',
                    'order_count' => 1,
                    'gross_order_count' => 2,
                    'cancel_order_num' => 1,
                    'unknown_status_order_count' => 0,
                    'cancel_rate_basis' =>
                        'cancelled_orders_over_gross_orders_complete_classification',
                ],
                [
                    'platform_key' => 'meituan',
                    'hotel_key' => 'system:7',
                    'order_count' => 3,
                ],
            ],
            'fact_ota_traffic' => [],
            'fact_ota_comment' => [],
        ]);
        self::assertNull($partialCoverage['totals']['cancellation_rate']);
        self::assertContains(
            'cancellation_fields_partial',
            array_column($partialCoverage['data_gaps'], 'code')
        );

        $uncoveredPlatformScope = (new OtaRevenueMetricService())
            ->summarizeDataset([
                'fact_ota_daily' => [
                    [
                        'platform_key' => 'ctrip',
                        'hotel_key' => 'system:7',
                        'date_key' => '2026-08-01',
                        'gross_order_count' => 2,
                        'cancel_order_num' => 1,
                        'unknown_status_order_count' => 0,
                        'cancel_rate_basis' =>
                            'cancelled_orders_over_gross_orders_complete_classification',
                    ],
                    [
                        'platform_key' => 'meituan',
                        'hotel_key' => 'system:7',
                        'date_key' => '2026-08-01',
                        'revenue' => 300.0,
                    ],
                ],
                'fact_ota_traffic' => [],
                'fact_ota_comment' => [],
            ]);
        self::assertNull(
            $uncoveredPlatformScope['totals']['cancellation_rate']
        );
        self::assertContains(
            'cancellation_fields_partial',
            array_column($uncoveredPlatformScope['data_gaps'], 'code')
        );

        $mixedEvidence = (new OtaRevenueMetricService())->summarizeDataset([
            'fact_ota_daily' => [
                [
                    'platform_key' => 'ctrip',
                    'hotel_key' => 'system:7',
                    'gross_order_count' => 2,
                    'cancel_order_num' => 1,
                    'unknown_status_order_count' => 0,
                    'cancel_rate_basis' =>
                        'cancelled_orders_over_gross_orders_complete_classification',
                ],
                [
                    'platform_key' => 'meituan',
                    'hotel_key' => 'system:7',
                    'cancel_rate' => 10.0,
                ],
            ],
            'fact_ota_traffic' => [],
            'fact_ota_comment' => [],
        ]);
        self::assertNull($mixedEvidence['totals']['cancellation_rate']);
        self::assertContains(
            'cancellation_evidence_mixed',
            array_column($mixedEvidence['data_gaps'], 'code')
        );

        $verifiedZeroGross = (new OtaRevenueMetricService())
            ->summarizeDataset([
                'fact_ota_daily' => [[
                    'platform_key' => 'ctrip',
                    'hotel_key' => 'system:7',
                    'gross_order_count' => 0,
                    'cancel_order_num' => 0,
                    'unknown_status_order_count' => 0,
                    'cancel_rate_basis' =>
                        'cancelled_orders_over_gross_orders_complete_classification',
                    'source_trace' => $this->trace(
                        9901,
                        'ctrip',
                        'order',
                        '2026-08-01'
                    ),
                ]],
                'fact_ota_traffic' => [],
                'fact_ota_comment' => [],
            ]);
        self::assertSame(0, $verifiedZeroGross['totals']['gross_order_count']);
        self::assertNull($verifiedZeroGross['totals']['cancellation_rate']);
        self::assertTrue(
            $verifiedZeroGross['metric_trust']
                ['totals.gross_order_count']['saved_success']
        );
    }

    public function testMeituanSameRunFunnelProjectionsDoNotDoubleTrafficFacts(): void
    {
        $trace = static fn(
            int $rowId,
            string $traceId,
            int $syncTaskId = 3045,
            string $collectedAt = '2026-08-09 06:54:29'
        ): array => [
            'table' => 'online_daily_data',
            'row_id' => $rowId,
            'source_trace_id' => $traceId,
            'data_source_id' => 68,
            'sync_task_id' => $syncTaskId,
            'ingestion_method' => 'browser_profile',
            'hotel_key' => 'system:80',
            'system_hotel_id' => 80,
            'platform_hotel_id' => 'meituan-hotel-80',
            'platform' => 'meituan',
            'data_type' => 'traffic',
            'date_key' => '2026-08-08',
            'collected_at' => $collectedAt,
            'updated_at' => $collectedAt,
            'stored' => true,
            'readback_verified' => true,
            'saved_success' => true,
            'failure_reasons' => [],
        ];
        $base = [
            'date_key' => '2026-08-08',
            'hotel_key' => 'system:80',
            'platform_key' => 'meituan',
            'compare_type' => 'self',
            'list_exposure' => 1277,
            'detail_exposure' => 176,
            'flow_rate' => 4.55,
        ];
        $dataset = [
            'fact_ota_daily' => [],
            'fact_ota_traffic' => [
                array_replace($base, [
                    'list_exposure' => 999,
                    'detail_exposure' => 99,
                    'order_filling_num' => 3,
                    'order_submit_num' => 3,
                    'submit_rate' => 100.0,
                    'raw_data' => [
                        'capture_evidence' => [
                            'capture_source' => 'dom:traffic:flow_funnel',
                        ],
                    ],
                    'source_trace' => $trace(
                        81700,
                        'meituan-dom-81700',
                        3044,
                        '2026-08-09 05:00:00'
                    ),
                ]),
                array_replace($base, [
                    'order_filling_num' => 8,
                    'order_submit_num' => 8,
                    'submit_rate' => 100.0,
                    'raw_data' => [
                        'capture_evidence' => [
                            'capture_source' => 'dom:traffic:flow_funnel',
                        ],
                    ],
                    'source_trace' => $trace(81824, 'meituan-dom-81824'),
                ]),
                array_replace($base, [
                    'compare_type' => '',
                    'order_filling_num' => null,
                    'order_submit_num' => 8,
                    'submit_rate' => null,
                    'raw_data' => [
                        'capture_evidence' => [
                            'capture_source' => 'xhr:traffic:traffic',
                            'source_path' => 'data.myHotel',
                        ],
                    ],
                    'source_trace' => $trace(81866, 'meituan-xhr-81866'),
                ]),
                array_replace($base, [
                    'list_exposure' => 200,
                    'detail_exposure' => 20,
                    'flow_rate' => 10.0,
                    'order_filling_num' => null,
                    'order_submit_num' => null,
                    'submit_rate' => null,
                    'raw_data' => [
                        'capture_evidence' => [
                            'capture_source' =>
                                'xhr:traffic:source_breakdown',
                            'source_path' => 'data.sourceItems[0]',
                        ],
                    ],
                    'source_trace' => $trace(
                        81867,
                        'meituan-source-breakdown-81867'
                    ),
                ]),
            ],
            'fact_ota_comment' => [],
            'data_quality' => [],
        ];

        $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);

        self::assertSame(4, $metrics['traffic']['rows']);
        self::assertSame(1277, $metrics['traffic']['list_exposure']);
        self::assertSame(176, $metrics['traffic']['detail_exposure']);
        self::assertSame(4.55, $metrics['traffic']['avg_flow_rate']);
        self::assertSame(100.0, $metrics['traffic']['avg_submit_rate']);
        self::assertSame(
            1,
            $metrics['traffic']['canonicalized_projection_groups']
        );
        self::assertSame(
            [
                'flow_rate' => 1,
                'submit_rate' => 1,
                'list_exposure' => 1,
                'detail_exposure' => 1,
            ],
            $metrics['traffic']['metric_source_rows']
        );
        self::assertSame(
            [81866],
            $metrics['metric_trust']['traffic.list_exposure']['source']
                ['row_ids']
        );
        self::assertSame(
            [81824],
            $metrics['metric_trust']['traffic.avg_submit_rate']['source']
                ['row_ids']
        );
        self::assertSame(
            1277.0,
            $this->channelMetric(
                $metrics['channel_metrics'],
                'traffic',
                'list_exposure'
            )['value']
        );
    }

    public function testCtripHeadlineTrafficUsesOnlySelfTotalFunnel(): void
    {
        $trace = static fn(int $rowId, int $syncTaskId): array => [
            'row_id' => $rowId,
            'source_trace_id' => 'ctrip-' . $rowId,
            'data_source_id' => 25,
            'sync_task_id' => $syncTaskId,
            'ingestion_method' => 'browser_profile',
            'system_hotel_id' => 80,
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'date_key' => '2026-08-08',
            'collected_at' => $syncTaskId === 3042
                ? '2026-08-09 06:24:56'
                : '2026-08-09 06:22:03',
            'updated_at' => $syncTaskId === 3042
                ? '2026-08-09 06:24:56'
                : '2026-08-09 06:22:03',
            'stored' => true,
            'readback_verified' => true,
            'saved_success' => true,
            'failure_reasons' => [],
        ];
        $base = [
            'date_key' => '2026-08-08',
            'hotel_key' => 'system:80',
            'platform_key' => 'ctrip',
            'compare_type' => 'self',
            'raw_data' => [],
        ];
        $traffic = [
            array_replace($base, [
                'dimension' => null,
                'list_exposure' => 134,
                'detail_exposure' => 30,
                'flow_rate' => 22.39,
                'order_filling_num' => 0,
                'order_submit_num' => 0,
                'submit_rate' => null,
                'raw_data' => [
                    'row' => [
                        'dimension' => 'catalog:traffic_report:traffic_flow_transform:date+list_exposure+competitor_list_exposure:0.date',
                    ],
                ],
                'source_trace' => $trace(81815, 3042),
            ]),
            array_replace($base, [
                'dimension' => 'catalog:traffic_report:traffic_flow_transform:date+list_exposure+competitor_list_exposure:1.date',
                'compare_type' => 'competitor_avg',
                'list_exposure' => 242,
                'detail_exposure' => 43,
                'flow_rate' => 17.77,
                'order_filling_num' => 3,
                'order_submit_num' => 2,
                'submit_rate' => 66.67,
                'source_trace' => $trace(81816, 3042),
            ]),
            array_replace($base, [
                'dimension' => '',
                'list_exposure' => 2,
                'detail_exposure' => 0,
                'flow_rate' => 0.0,
                'order_filling_num' => 0,
                'order_submit_num' => 0,
                'submit_rate' => null,
                'raw_data' => [
                    'row' => [
                        '_source_path' => '$.data.flowSourceDetails[2]',
                    ],
                ],
                'source_trace' => $trace(81818, 3042),
            ]),
            array_replace($base, [
                'dimension' => '',
                'compare_type' => 'competitor_avg',
                'list_exposure' => 242,
                'detail_exposure' => 43,
                'flow_rate' => 16.99,
                'order_filling_num' => 3,
                'order_submit_num' => 2,
                'submit_rate' => 66.67,
                'source_trace' => $trace(81819, 3042),
            ]),
            array_replace($base, [
                'dimension' => null,
                'list_exposure' => null,
                'detail_exposure' => 17,
                'flow_rate' => null,
                'order_filling_num' => null,
                'order_submit_num' => null,
                'submit_rate' => null,
                'raw_data' => [
                    'row' => [
                        'dimension' => 'catalog:business_overview:business_visitor_title:visitor_count+visitor_rank+visitor_count_last_week:visitorTotal',
                    ],
                ],
                'source_trace' => $trace(81601, 3040),
            ]),
        ];

        $metrics = (new OtaRevenueMetricService())->summarizeDataset([
            'fact_ota_daily' => [[
                'date_key' => '2026-08-08',
                'hotel_key' => 'system:80',
                'platform_key' => 'ctrip',
                'data_type' => 'business',
                'dimension' => 'catalog:business_overview:business_capacity:occupied_rooms+occupied_rooms_sync+occupied_rooms_rank:occupiedRooms',
                'order_count' => 0,
                'source_trace' => array_replace($trace(81600, 3040), [
                    'data_type' => 'business',
                ]),
            ]],
            'fact_ota_traffic' => $traffic,
            'fact_ota_comment' => [],
        ]);

        self::assertSame(5, $metrics['traffic']['rows']);
        self::assertSame(134, $metrics['traffic']['list_exposure']);
        self::assertSame(30, $metrics['traffic']['detail_exposure']);
        self::assertSame(22.39, $metrics['traffic']['avg_flow_rate']);
        self::assertNull($metrics['traffic']['avg_submit_rate']);
        self::assertNull($metrics['totals']['order_count']);
        self::assertSame(
            [81815],
            $metrics['metric_trust']['traffic.list_exposure']['source']
                ['row_ids']
        );
        self::assertSame(
            [],
            $metrics['metric_trust']['traffic.avg_submit_rate']['source']
                ['row_ids']
        );
        self::assertSame(
            [],
            $metrics['metric_trust']['totals.order_count']['source']['row_ids']
        );
    }

    public function testBookingWindowAdrUsesAlignedVerifiedRoomRevenueAndKeepsMissingFieldsVisible(): void
    {
        $service = new OtaRevenueMetricService();
        $base = [
            'platform_key' => 'ctrip',
            'hotel_key' => 'system:7',
            'data_type' => 'business',
            'order_count' => 1,
        ];
        $metrics = $service->summarizeDataset([
            'fact_ota_daily' => [
                array_merge($base, ['lead_time_days' => 0, 'room_revenue' => 300.0, 'room_nights' => 1.0]),
                array_merge($base, ['lead_time_days' => 5, 'room_revenue' => 400.0, 'room_nights' => 2.0, 'order_count' => 2]),
                array_merge($base, ['lead_time_days' => 20, 'room_revenue' => 600.0, 'room_nights' => 2.0, 'order_count' => 2]),
            ],
        ]);

        self::assertSame('ready', $metrics['booking_window_adr']['status']);
        self::assertSame(3, $metrics['booking_window_adr']['aligned_row_count']);
        self::assertSame(3, $metrics['booking_window_adr']['bucket_count']);
        $buckets = array_column($metrics['booking_window_adr']['buckets'], null, 'key');
        self::assertSame(300.0, $buckets['same_day']['adr']);
        self::assertSame(200.0, $buckets['days_4_7']['adr']);
        self::assertSame(300.0, $buckets['days_15_30']['adr']);
        self::assertSame(2, $buckets['days_4_7']['order_count']);
        self::assertSame(
            'group by lead_time_days bucket; sum(fact_ota_daily.room_revenue) / sum(fact_ota_daily.room_nights)',
            $metrics['metric_trust']['booking_window_adr.buckets']['caliber']
        );

        $partial = $service->summarizeDataset([
            'fact_ota_daily' => [
                array_merge($base, ['lead_time_days' => 2, 'room_revenue' => 220.0, 'room_nights' => 1.0]),
                array_merge($base, ['lead_time_days' => 10, 'room_revenue' => null, 'room_nights' => 1.0]),
            ],
        ]);
        self::assertSame('partial', $partial['booking_window_adr']['status']);
        self::assertSame('booking_window_adr_fields_partial', $partial['booking_window_adr']['reason']);
        self::assertContains('booking_window_adr_fields_partial', array_column($partial['data_gaps'], 'code'));

        $missing = $service->summarizeDataset([
            'fact_ota_daily' => [
                array_merge($base, ['lead_time_days' => 5, 'room_revenue' => null, 'room_nights' => 1.0]),
            ],
        ]);
        self::assertSame('not_calculable', $missing['booking_window_adr']['status']);
        self::assertSame('booking_window_adr_fields_missing', $missing['booking_window_adr']['reason']);
        self::assertSame([], $missing['booking_window_adr']['buckets']);
        self::assertContains('booking_window_adr_fields_missing', array_column($missing['data_gaps'], 'code'));
    }

    public function testChannelBookingWindowByStayMonthUsesOrderCountsAndFlagsSparseCells(): void
    {
        $service = new OtaRevenueMetricService();
        $base = [
            'hotel_key' => 'system:7',
            'data_type' => 'order',
        ];
        $metrics = $service->summarizeDataset([
            'fact_ota_daily' => [
                array_merge($base, ['platform_key' => 'ctrip', 'checkin_date' => '2026-09-05', 'lead_time_days' => 0, 'order_count' => 3]),
                array_merge($base, ['platform_key' => 'ctrip', 'checkin_date' => '2026-09-18', 'lead_time_days' => 20, 'order_count' => 12]),
                array_merge($base, ['platform_key' => 'meituan', 'checkin_date' => '2026-09-20', 'lead_time_days' => 5, 'order_count' => 8]),
                array_merge($base, ['platform_key' => 'meituan', 'checkin_date' => '2026-10-03', 'lead_time_days' => 20, 'order_count' => 10]),
            ],
        ]);

        $summary = $metrics['channel_booking_window_month'];
        self::assertSame('partial', $summary['status']);
        self::assertSame('channel_booking_window_month_sparse_cells', $summary['reason']);
        self::assertSame(4, $summary['aligned_row_count']);
        self::assertSame(2, $summary['month_count']);
        self::assertSame(2, $summary['channel_count']);
        self::assertSame(4, $summary['cell_count']);
        self::assertSame(2, $summary['supported_cell_count']);
        self::assertSame(2, $summary['sparse_cell_count']);
        $cells = [];
        foreach ($summary['cells'] as $cell) {
            $cells[$cell['stay_month'] . '|' . $cell['platform_key'] . '|' . $cell['booking_window_key']] = $cell;
        }
        self::assertSame(80.0, $cells['2026-09|ctrip|days_15_30']['order_share']);
        self::assertSame('supported', $cells['2026-09|ctrip|days_15_30']['sample_status']);
        self::assertSame('sparse', $cells['2026-09|meituan|days_4_7']['sample_status']);
        self::assertSame(
            'group by checkin month, platform_key, and lead_time_days bucket; sum(order_count) / channel-month sum(order_count)',
            $metrics['metric_trust']['channel_booking_window_month.cells']['caliber']
        );

        $missing = $service->summarizeDataset([
            'fact_ota_daily' => [
                array_merge($base, ['platform_key' => 'ctrip', 'checkin_date' => null, 'lead_time_days' => 5, 'order_count' => 20]),
            ],
        ]);
        self::assertSame('not_calculable', $missing['channel_booking_window_month']['status']);
        self::assertSame('channel_booking_window_month_fields_missing', $missing['channel_booking_window_month']['reason']);
        self::assertSame([], $missing['channel_booking_window_month']['cells']);
    }

    public function testAdrUsesAllStandardRevenueAndRoomNightFactsAtTheSameScope(): void
    {
        $metrics = (new OtaRevenueMetricService())->summarizeDataset([
            'fact_ota_daily' => [
                [
                    'platform_key' => 'ctrip',
                    'hotel_key' => 'system:80',
                    'data_type' => 'business',
                    'dimension' => 'overview',
                    'revenue' => 22981.02,
                    'room_revenue' => 22981.02,
                    'room_nights' => 22.0,
                    'source_trace' => $this->trace(9201, 'ctrip', 'business', '2026-07-23'),
                ],
                [
                    'platform_key' => 'ctrip',
                    'hotel_key' => 'system:80',
                    'data_type' => 'order',
                    'dimension' => 'room_nights_adjustment',
                    'revenue' => null,
                    'room_revenue' => null,
                    'room_nights' => 2.0,
                    'source_trace' => $this->trace(9202, 'ctrip', 'order', '2026-07-23'),
                ],
            ],
            'fact_ota_traffic' => [],
            'fact_ota_advertising' => [],
            'fact_ota_quality' => [],
            'fact_ota_comment' => [],
        ]);

        self::assertSame(22981.02, $metrics['totals']['room_revenue']);
        self::assertSame(24.0, $metrics['totals']['room_nights']);
        self::assertSame(957.54, $metrics['totals']['adr']);
        self::assertSame(957.54, $metrics['by_platform'][0]['adr']);
        self::assertSame([9201, 9202], $metrics['metric_trust']['totals.adr']['source']['row_ids']);
    }

    public function testDualOtaMetricTruthPreservesHotelPlatformSourceAndBusinessDateIdentity(): void
    {
        $ctripTrace = array_replace($this->trace(9301, 'ctrip', 'business', '2026-08-01'), [
            'source_trace_id' => 'ctrip:9301',
            'data_source_id' => 25,
            'sync_task_id' => 501,
            'system_hotel_id' => 80,
            'platform_hotel_id' => 'ctrip-hotel-80',
            'hotel_name' => 'Hotel 80',
            'collected_at' => '2026-08-01 09:00:00',
            'stored' => true,
            'readback_verified' => true,
        ]);
        $meituanTrace = array_replace($this->trace(9302, 'meituan', 'business', '2026-08-01'), [
            'source_trace_id' => 'meituan:9302',
            'data_source_id' => 26,
            'sync_task_id' => 502,
            'system_hotel_id' => 80,
            'platform_hotel_id' => 'meituan-hotel-80',
            'hotel_name' => 'Hotel 80',
            'collected_at' => '2026-08-01 09:05:00',
            'stored' => true,
            'readback_verified' => true,
        ]);
        $metrics = (new OtaRevenueMetricService())->summarizeDataset([
            'fact_ota_daily' => [
                ['platform_key' => 'ctrip', 'hotel_key' => 'system:80', 'room_revenue' => 1000.0, 'room_nights' => 5.0, 'source_trace' => $ctripTrace],
                ['platform_key' => 'meituan', 'hotel_key' => 'system:80', 'room_revenue' => 600.0, 'room_nights' => 3.0, 'source_trace' => $meituanTrace],
            ],
        ]);

        $truth = $metrics['metric_trust']['totals.room_revenue']['truth'];
        self::assertSame('verified', $truth['status']);
        self::assertSame([80], array_column($truth['hotels'], 'system_hotel_id'));
        self::assertSame(['ctrip', 'meituan'], $truth['platforms']);
        self::assertSame(['2026-08-01', '2026-08-01'], array_values($truth['date_range']));
        self::assertSame([25, 26], $truth['source']['data_source_ids']);
        self::assertSame([501, 502], $truth['source']['sync_task_ids']);
        self::assertSame(['ctrip:9301', 'meituan:9302'], $truth['source']['trace_ids']);
        self::assertTrue($truth['persistence']['readback_verified']);
    }

    public function testP1RevenueClosureUsesVerifiedOtaMetricsOnly(): void
    {
        $metrics = (new OtaRevenueMetricService())->summarizeDataset([
            'status' => 'ready',
            'data_quality' => [
                'input_rows' => 2,
                'accepted_rows' => 2,
                'rejected_rows' => [],
            ],
            'fact_ota_daily' => [[
                'platform_key' => 'ctrip',
                'hotel_key' => 'system:7',
                'data_type' => 'business',
                'revenue' => 1200.0,
                'gross_revenue' => 1200.0,
                'room_revenue' => 1200.0,
                'net_revenue' => 1020.0,
                'commission_amount' => 180.0,
                'room_nights' => 6.0,
                'available_room_nights' => 10.0,
                'occupied_room_nights' => 6.0,
                'order_count' => 4,
                'gross_order_count' => 4,
                'cancel_order_num' => 0,
                'unknown_status_order_count' => 0,
                'cancel_rate_basis' =>
                    'cancelled_orders_over_gross_orders_complete_classification',
                'cancel_room_nights' => 0,
                'lead_time_days' => 2,
                'our_price' => 200.0,
                'competitor_price' => 210.0,
                'price_gap' => -10.0,
                'price_gap_rate' => -4.76,
                'source_trace' => $this->trace(9001, 'ctrip', 'business', '2026-06-25'),
            ]],
            'fact_ota_traffic' => [[
                'platform_key' => 'ctrip',
                'hotel_key' => 'system:7',
                'resource' => 'traffic',
                'flow_rate' => 20.0,
                'submit_rate' => 33.33,
                'source_trace' => $this->trace(9002, 'ctrip', 'traffic', '2026-06-25'),
            ]],
            'fact_ota_advertising' => [],
            'fact_ota_quality' => [],
            'fact_ota_search_keyword' => [],
            'fact_ota_comment' => [],
        ]);

        $closure = $metrics['p1_revenue_closure'];

        self::assertSame('ready', $closure['status']);
        self::assertSame('ota_channel', $closure['scope']);
        self::assertTrue($closure['calculation_allowed']);
        self::assertSame(1200.0, $closure['sections']['revenue']['value']);
        self::assertSame(4.0, $closure['sections']['orders']['value']);
        self::assertSame(6.0, $closure['sections']['room_nights']['value']);
        self::assertSame(200.0, $closure['sections']['adr_conversion']['metrics']['adr']['value']);
        self::assertSame(20.0, $closure['sections']['adr_conversion']['metrics']['flow_rate']['value']);
        self::assertSame(33.33, $closure['sections']['adr_conversion']['metrics']['submit_rate']['value']);
        self::assertSame('ok', $closure['missing_items']['status']);
        self::assertSame('ok', $closure['anomaly_judgment']['status']);
        self::assertFalse($closure['whole_hotel_guard']['allowed']);
        self::assertSame('whole_hotel_scope_not_proved', $closure['whole_hotel_guard']['reason']);
    }

    public function testP1RevenueClosureBlocksValuesWhenCredibilityGateBlocksDataset(): void
    {
        $metrics = (new OtaRevenueMetricService())->summarizeDataset([
            'status' => 'failed',
            'data_quality' => [
                'input_rows' => 1,
                'accepted_rows' => 1,
                'rejected_rows' => [],
            ],
            'fact_ota_daily' => [[
                'platform_key' => 'ctrip',
                'hotel_key' => 'system:7',
                'data_type' => 'business',
                'revenue' => 1200.0,
                'room_revenue' => 1200.0,
                'net_revenue' => 1020.0,
                'commission_amount' => 180.0,
                'room_nights' => 6.0,
                'available_room_nights' => 10.0,
                'occupied_room_nights' => 6.0,
                'order_count' => 4,
                'cancel_order_num' => 0,
                'cancel_room_nights' => 0,
                'lead_time_days' => 2,
                'our_price' => 200.0,
                'competitor_price' => 210.0,
                'source_trace' => $this->trace(9011, 'ctrip', 'business', '2026-06-25'),
            ]],
            'fact_ota_traffic' => [[
                'platform_key' => 'ctrip',
                'hotel_key' => 'system:7',
                'flow_rate' => 20.0,
                'submit_rate' => 33.33,
                'source_trace' => $this->trace(9012, 'ctrip', 'traffic', '2026-06-25'),
            ]],
        ]);

        $closure = $metrics['p1_revenue_closure'];

        self::assertSame('blocked', $closure['status']);
        self::assertFalse($closure['calculation_allowed']);
        self::assertNull($closure['sections']['revenue']['value']);
        self::assertSame('blocked', $closure['sections']['revenue']['status']);
        self::assertContains('blocked_by_data_credibility', $closure['sections']['revenue']['failure_reasons']);
        self::assertContains('ota_dataset_failed', array_column($closure['anomaly_judgment']['items'], 'code'));
    }

    public function testP1RevenueClosureExplainsMissingAdrAndConversionInputs(): void
    {
        $metrics = (new OtaRevenueMetricService())->summarizeDataset([
            'status' => 'ready',
            'data_quality' => [
                'input_rows' => 1,
                'accepted_rows' => 1,
                'rejected_rows' => [],
            ],
            'fact_ota_daily' => [[
                'platform_key' => 'ctrip',
                'hotel_key' => 'system:7',
                'data_type' => 'business',
                'revenue' => 1200.0,
                'room_revenue' => 1200.0,
                'net_revenue' => 1020.0,
                'commission_amount' => 180.0,
                'room_nights' => 0.0,
                'available_room_nights' => 10.0,
                'occupied_room_nights' => 0.0,
                'order_count' => 4,
                'cancel_order_num' => 0,
                'cancel_room_nights' => 0,
                'lead_time_days' => 2,
                'our_price' => 200.0,
                'competitor_price' => 210.0,
                'source_trace' => $this->trace(9021, 'ctrip', 'business', '2026-06-25'),
            ]],
            'fact_ota_traffic' => [],
        ]);

        $closure = $metrics['p1_revenue_closure'];
        $missingCodes = array_column($closure['missing_items']['items'], 'code');

        self::assertSame('blocked', $closure['status']);
        self::assertNull($closure['sections']['adr_conversion']['metrics']['adr']['value']);
        self::assertContains('totals.adr:adr_denominator_zero', $missingCodes);
        self::assertContains('traffic.avg_flow_rate:source_rows_missing', $missingCodes);
        self::assertContains('traffic.avg_submit_rate:source_rows_missing', $missingCodes);
    }

    public function testAdvertisingAndQualityFactsDoNotPolluteRevenueMetrics(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows($this->trustedRows([
            [
                'id' => 31,
                'system_hotel_id' => 7,
                'hotel_id' => 'ctrip-7',
                'hotel_name' => 'Hotel Alpha',
                'source' => 'ctrip',
                'data_type' => 'business',
                'data_date' => '2026-05-27',
                'source_trace_id' => 'trace-business-41',
                'update_time' => '2026-05-27 10:00:00',
                'amount' => 1200,
                'room_revenue' => 1200,
                'quantity' => 6,
                'book_order_num' => 4,
                'raw_data' => json_encode(['available_rooms' => 10], JSON_UNESCAPED_UNICODE),
            ],
            [
                'id' => 32,
                'system_hotel_id' => 7,
                'hotel_id' => 'ctrip-7',
                'hotel_name' => 'Hotel Alpha',
                'source' => 'ctrip',
                'data_type' => 'advertising',
                'data_date' => '2026-05-27',
                'source_trace_id' => 'trace-advertising-42',
                'update_time' => '2026-05-27 10:05:00',
                'amount' => 256.75,
                'quantity' => 23,
                'book_order_num' => 16,
                'list_exposure' => 10000,
                'detail_exposure' => 320,
                'flow_rate' => 8.5,
                'data_value' => 7.35,
                'raw_data' => json_encode(['orderAmount' => 1888, 'campaignId' => 'campaign-1'], JSON_UNESCAPED_UNICODE),
            ],
            [
                'id' => 33,
                'system_hotel_id' => 7,
                'hotel_id' => 'ctrip-7',
                'hotel_name' => 'Hotel Alpha',
                'source' => 'ctrip',
                'data_type' => 'quality',
                'data_date' => '2026-05-27',
                'source_trace_id' => 'trace-quality-43',
                'update_time' => '2026-05-27 10:10:00',
                'data_value' => 88.6,
                'raw_data' => json_encode(['serviceScore' => 92.5, 'psiScore' => 88.6], JSON_UNESCAPED_UNICODE),
            ],
        ]));

        self::assertCount(1, $dataset['fact_ota_daily']);
        self::assertCount(1, $dataset['fact_ota_advertising']);
        self::assertCount(1, $dataset['fact_ota_quality']);

        $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);

        self::assertSame(1200.0, $metrics['totals']['revenue']);
        self::assertSame(6.0, $metrics['totals']['room_nights']);
        self::assertSame(4, $metrics['totals']['order_count']);
        self::assertSame(256.75, $metrics['advertising']['spend']);
        self::assertSame(1888.0, $metrics['advertising']['order_amount']);
        self::assertSame(7.35, $metrics['advertising']['roas']);
        self::assertSame(10000, $metrics['advertising']['impressions']);
        self::assertSame(320, $metrics['advertising']['clicks']);
        self::assertSame(88.6, $metrics['quality']['avg_psi_score']);
        self::assertSame(92.5, $metrics['quality']['avg_service_score']);
    }

    public function testRevenueMetricsExposeTraceableChannelMetrics(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows($this->trustedRows([
            [
                'id' => 71,
                'system_hotel_id' => 7,
                'hotel_id' => 'ctrip-7',
                'hotel_name' => 'Hotel Alpha',
                'source' => 'ctrip',
                'data_type' => 'business',
                'data_date' => '2026-05-27',
                'update_time' => '2026-05-27 10:00:00',
                'amount' => 1200,
                'quantity' => 6,
                'book_order_num' => 4,
                'source_trace_id' => 'trace-business-71',
                'update_time' => '2026-05-27 10:00:00',
                'raw_data' => json_encode(['available_rooms' => 10], JSON_UNESCAPED_UNICODE),
            ],
            [
                'id' => 72,
                'system_hotel_id' => 7,
                'hotel_id' => 'ctrip-7',
                'hotel_name' => 'Hotel Alpha',
                'source' => 'ctrip',
                'data_type' => 'traffic',
                'data_date' => '2026-05-27',
                'list_exposure' => 1000,
                'detail_exposure' => 185,
                'flow_rate' => 18.5,
                'order_filling_num' => 40,
                'order_submit_num' => 9,
                'source_trace_id' => 'trace-traffic-72',
                'update_time' => '2026-05-27 10:05:00',
                'raw_data' => '{}',
            ],
            [
                'id' => 73,
                'system_hotel_id' => 7,
                'hotel_id' => 'ctrip-7',
                'hotel_name' => 'Hotel Alpha',
                'source' => 'ctrip',
                'data_type' => 'advertising',
                'data_date' => '2026-05-27',
                'amount' => 256.75,
                'list_exposure' => 10000,
                'detail_exposure' => 320,
                'book_order_num' => 16,
                'data_value' => 7.35,
                'source_trace_id' => 'trace-ad-73',
                'update_time' => '2026-05-27 10:10:00',
                'raw_data' => json_encode(['orderAmount' => 1888, 'campaignId' => 'campaign-1'], JSON_UNESCAPED_UNICODE),
            ],
            [
                'id' => 74,
                'system_hotel_id' => 7,
                'hotel_id' => 'ctrip-7',
                'hotel_name' => 'Hotel Alpha',
                'source' => 'ctrip',
                'data_type' => 'search_keyword',
                'dimension' => 'family hotel',
                'data_date' => '2026-05-27',
                'list_exposure' => 300,
                'detail_exposure' => 45,
                'order_submit_num' => 3,
                'data_value' => 5,
                'source_trace_id' => 'trace-keyword-74',
                'update_time' => '2026-05-27 10:15:00',
                'raw_data' => json_encode(['keyword' => 'family hotel', 'rank' => 2], JSON_UNESCAPED_UNICODE),
            ],
            [
                'id' => 75,
                'system_hotel_id' => 7,
                'hotel_id' => 'meituan-7',
                'hotel_name' => 'Hotel Alpha',
                'source' => 'meituan',
                'data_type' => 'peer_rank',
                'dimension' => 'peer_rank:P_RZ:入住间夜',
                'data_date' => '2026-05-27',
                'data_value' => 3,
                'compare_type' => 'competitor',
                'source_trace_id' => 'trace-peer-rank-75',
                'update_time' => '2026-05-27 10:20:00',
                'raw_data' => json_encode(['rankType' => 'P_RZ', 'rank' => 3, 'percent' => 0.12], JSON_UNESCAPED_UNICODE),
            ],
            [
                'id' => 76,
                'system_hotel_id' => 7,
                'hotel_id' => 'meituan-7',
                'hotel_name' => 'Hotel Alpha',
                'source' => 'meituan',
                'data_type' => 'traffic_analysis',
                'dimension' => 'traffic_analysis:flow_conversion',
                'data_date' => '2026-05-27',
                'data_value' => 18.5,
                'list_exposure' => 800,
                'detail_exposure' => 160,
                'flow_rate' => 20,
                'order_filling_num' => 40,
                'order_submit_num' => 8,
                'source_trace_id' => 'trace-traffic-analysis-76',
                'update_time' => '2026-05-27 10:25:00',
                'raw_data' => json_encode(['analysis_type' => 'conversion_funnel', 'flowRate' => 20], JSON_UNESCAPED_UNICODE),
            ],
            [
                'id' => 77,
                'system_hotel_id' => 7,
                'hotel_id' => 'meituan-7',
                'hotel_name' => 'Hotel Alpha',
                'source' => 'meituan',
                'data_type' => 'traffic_forecast',
                'dimension' => 'traffic_forecast:detail_uv',
                'data_date' => '2026-05-27',
                'data_value' => 260,
                'compare_type' => 'forecast',
                'source_trace_id' => 'trace-traffic-forecast-77',
                'update_time' => '2026-05-27 10:30:00',
                'raw_data' => json_encode(['forecastType' => 'detail_uv', 'current' => 260, 'peerAvg' => 310], JSON_UNESCAPED_UNICODE),
            ],
        ]));

        self::assertCount(1, $dataset['fact_ota_search_keyword']);
        self::assertCount(1, $dataset['fact_ota_peer_rank']);
        self::assertCount(1, $dataset['fact_ota_traffic_analysis']);
        self::assertCount(1, $dataset['fact_ota_traffic_forecast']);
        self::assertSame('peer_rank:P_RZ:入住间夜', $dataset['fact_ota_peer_rank'][0]['dimension']);
        self::assertSame(3.0, $dataset['fact_ota_peer_rank'][0]['rank']);
        self::assertSame(12.0, $dataset['fact_ota_peer_rank'][0]['rank_percent']);
        self::assertSame('traffic_analysis:flow_conversion', $dataset['fact_ota_traffic_analysis'][0]['dimension']);
        self::assertSame(20.0, $dataset['fact_ota_traffic_analysis'][0]['submit_rate']);
        self::assertSame('traffic_forecast:detail_uv', $dataset['fact_ota_traffic_forecast'][0]['dimension']);
        self::assertSame(310.0, $dataset['fact_ota_traffic_forecast'][0]['peer_avg']);

        $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);

        self::assertArrayHasKey('channel_metrics', $metrics);
        $trafficFlow = $this->channelMetric($metrics['channel_metrics'], 'traffic', 'flow_rate');
        self::assertSame(['scope', 'platform', 'resource', 'metric_key', 'value', 'denominator', 'data_status', 'source_trace_id', 'updated_at'], array_keys($trafficFlow));
        self::assertSame('ota_channel', $trafficFlow['scope']);
        self::assertSame('ctrip', $trafficFlow['platform']);
        self::assertSame(18.5, $trafficFlow['value']);
        self::assertSame(1000.0, $trafficFlow['denominator']);
        self::assertSame('ok', $trafficFlow['data_status']);
        self::assertSame('trace-traffic-72', $trafficFlow['source_trace_id']);
        self::assertSame('2026-05-27 10:05:00', $trafficFlow['updated_at']);

        $adSpend = $this->channelMetric($metrics['channel_metrics'], 'advertising', 'amount');
        self::assertSame(256.75, $adSpend['value']);
        self::assertSame('trace-ad-73', $adSpend['source_trace_id']);

        $keywordRank = $this->channelMetric($metrics['channel_metrics'], 'search_keyword:family hotel', 'rank');
        self::assertSame(2.0, $keywordRank['value']);
        self::assertSame('trace-keyword-74', $keywordRank['source_trace_id']);

        $peerRank = $this->channelMetric($metrics['channel_metrics'], 'peer_rank:P_RZ:入住间夜', 'rank');
        self::assertSame(3.0, $peerRank['value']);
        self::assertSame('trace-peer-rank-75', $peerRank['source_trace_id']);

        $trafficAnalysis = $this->channelMetric($metrics['channel_metrics'], 'traffic_analysis:flow_conversion', 'order_submit_num');
        self::assertSame(8.0, $trafficAnalysis['value']);
        self::assertSame(40.0, $trafficAnalysis['denominator']);
        self::assertSame('trace-traffic-analysis-76', $trafficAnalysis['source_trace_id']);

        $trafficForecast = $this->channelMetric($metrics['channel_metrics'], 'traffic_forecast:detail_uv', 'forecast_value');
        self::assertSame(260.0, $trafficForecast['value']);
        self::assertSame('trace-traffic-forecast-77', $trafficForecast['source_trace_id']);
        self::assertArrayHasKey('peer_rank_signal', $metrics['metric_definitions']['metrics']);
        self::assertArrayHasKey('traffic_forecast_signal', $metrics['metric_definitions']['metrics']);
    }

    public function testInsightAnalysisIncludesAdvertisingEfficiencyAndServiceQualityModules(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows($this->trustedRows([
            [
                'id' => 41,
                'system_hotel_id' => 7,
                'hotel_id' => 'ctrip-7',
                'hotel_name' => 'Hotel Alpha',
                'source' => 'ctrip',
                'data_type' => 'business',
                'data_date' => '2026-05-27',
                'source_trace_id' => 'trace-business-41-analysis',
                'update_time' => '2026-05-27 10:00:00',
                'amount' => 1200,
                'room_revenue' => 1200,
                'quantity' => 6,
                'book_order_num' => 4,
                'raw_data' => json_encode(['available_rooms' => 10], JSON_UNESCAPED_UNICODE),
            ],
            [
                'id' => 42,
                'system_hotel_id' => 7,
                'hotel_id' => 'ctrip-7',
                'hotel_name' => 'Hotel Alpha',
                'source' => 'ctrip',
                'data_type' => 'advertising',
                'data_date' => '2026-05-27',
                'source_trace_id' => 'trace-advertising-42-analysis',
                'update_time' => '2026-05-27 10:05:00',
                'amount' => 256.75,
                'quantity' => 23,
                'book_order_num' => 16,
                'list_exposure' => 10000,
                'detail_exposure' => 320,
                'flow_rate' => 8.5,
                'data_value' => 7.35,
                'raw_data' => json_encode(['orderAmount' => 1888, 'campaignId' => 'campaign-1'], JSON_UNESCAPED_UNICODE),
            ],
            [
                'id' => 43,
                'system_hotel_id' => 7,
                'hotel_id' => 'ctrip-7',
                'hotel_name' => 'Hotel Alpha',
                'source' => 'ctrip',
                'data_type' => 'quality',
                'data_date' => '2026-05-27',
                'source_trace_id' => 'trace-quality-43-analysis',
                'update_time' => '2026-05-27 10:10:00',
                'data_value' => 88.6,
                'raw_data' => json_encode(['serviceScore' => 92.5, 'psiScore' => 88.6], JSON_UNESCAPED_UNICODE),
            ],
        ]));
        $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);
        $analysis = (new OtaInsightAnalysisService())->analyzeMetrics($metrics);

        $modules = array_column($analysis['modules'], null, 'key');

        self::assertArrayHasKey('advertising_efficiency', $modules);
        self::assertSame('available', $modules['advertising_efficiency']['status']);
        self::assertSame('P2', $modules['advertising_efficiency']['priority']);
        self::assertSame(256.75, $modules['advertising_efficiency']['metrics']['spend']);
        self::assertSame(7.35, $modules['advertising_efficiency']['metrics']['roas']);

        self::assertArrayHasKey('service_quality', $modules);
        self::assertSame('available', $modules['service_quality']['status']);
        self::assertSame('P2', $modules['service_quality']['priority']);
        self::assertSame(88.6, $modules['service_quality']['metrics']['avg_psi_score']);
        self::assertSame(92.5, $modules['service_quality']['metrics']['avg_service_score']);
    }

    public function testEtlRejectsInvalidPercentAndNegativeLeadTimeWithoutInventingMetrics(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
            [
                'id' => 11,
                'system_hotel_id' => 7,
                'hotel_id' => 'ctrip-7',
                'hotel_name' => 'Hotel Alpha',
                'source' => 'ctrip',
                'data_type' => 'business',
                'data_date' => '2026-05-19',
                'amount' => 1000,
                'quantity' => 5,
                'book_order_num' => 3,
                'raw_data' => json_encode([
                    'available_rooms' => 10,
                    'commission_rate' => 120,
                    'cancel_rate' => -0.2,
                    'booking_date' => '2026-05-20',
                    'checkin_date' => '2026-05-19',
                ], JSON_UNESCAPED_UNICODE),
            ],
        ]);

        $fact = $dataset['fact_ota_daily'][0];

        self::assertNull($fact['commission_rate']);
        self::assertNull($fact['commission_amount']);
        self::assertNull($fact['net_revenue']);
        self::assertNull($fact['cancel_rate']);
        self::assertNull($fact['lead_time_days']);
    }

    public function testDirectNetRevenueDoesNotDependOnCommissionFields(): void
    {
        $metrics = (new OtaRevenueMetricService())->summarizeDataset(
            (new OtaStandardEtlService())->buildDatasetFromRows([
                [
                    'id' => 12,
                    'system_hotel_id' => 7,
                    'hotel_id' => 'ctrip-7',
                    'hotel_name' => 'Hotel Alpha',
                    'source' => 'ctrip',
                    'data_type' => 'business',
                    'data_date' => '2026-05-20',
                    'source_trace_id' => 'trace-business-12',
                    'readback_verified' => 1,
                    'update_time' => '2026-05-20 10:00:00',
                    'amount' => 1000,
                    'quantity' => 5,
                    'book_order_num' => 3,
                    'raw_data' => json_encode([
                        'net_revenue' => 880,
                        'available_rooms' => 10,
                    ], JSON_UNESCAPED_UNICODE),
                ],
            ])
        );

        self::assertSame(880.0, $metrics['totals']['net_revenue']);
        self::assertSame(88.0, $metrics['totals']['net_revpar']);
        self::assertSame([], $metrics['metric_trust']['totals.net_revenue']['failure_reasons']);
        self::assertSame([], $metrics['metric_trust']['totals.net_revpar']['failure_reasons']);
        self::assertContains('commission_fields_missing', array_column($metrics['data_gaps'], 'code'));
    }

    public function testRevparUsesOnlyRowsWithAlignedAvailableRoomNightRows(): void
    {
        $metrics = (new OtaRevenueMetricService())->summarizeDataset([
            'fact_ota_daily' => [
                [
                    'platform_key' => 'ctrip',
                    'hotel_key' => 'system:7',
                    'revenue' => 100.0,
                    'room_revenue' => 100.0,
                    'net_revenue' => 80.0,
                    'room_nights' => 1.0,
                    'available_room_nights' => 10.0,
                    'occupied_room_nights' => 5.0,
                    'order_count' => 1,
                    'source_trace' => $this->trace(101, 'ctrip', 'business', '2026-05-20'),
                ],
                [
                    'platform_key' => 'ctrip',
                    'hotel_key' => 'system:7',
                    'revenue' => 900.0,
                    'room_revenue' => 900.0,
                    'net_revenue' => 720.0,
                    'room_nights' => 9.0,
                    'available_room_nights' => null,
                    'occupied_room_nights' => null,
                    'order_count' => 9,
                    'source_trace' => $this->trace(102, 'ctrip', 'business', '2026-05-20'),
                ],
            ],
            'fact_ota_traffic' => [],
            'fact_ota_comment' => [],
        ]);

        self::assertSame(10.0, $metrics['totals']['revpar']);
        self::assertSame(8.0, $metrics['totals']['net_revpar']);
        self::assertSame(10.0, $metrics['by_platform'][0]['revpar']);
        self::assertSame(8.0, $metrics['by_platform'][0]['net_revpar']);
        self::assertContains('available_room_nights_partial', array_column($metrics['data_gaps'], 'code'));
        self::assertContains('available_room_nights_partial', $metrics['metric_trust']['totals.revpar']['failure_reasons']);
    }

    public function testCommissionRateUsesOnlyRowsWithCommissionFields(): void
    {
        $metrics = (new OtaRevenueMetricService())->summarizeDataset([
            'fact_ota_daily' => [
                [
                    'platform_key' => 'ctrip',
                    'hotel_key' => 'system:7',
                    'revenue' => 100.0,
                    'gross_revenue' => 100.0,
                    'room_revenue' => 100.0,
                    'commission_amount' => 10.0,
                    'room_nights' => 1.0,
                    'order_count' => 1,
                    'source_trace' => $this->trace(201, 'ctrip', 'business', '2026-05-21'),
                ],
                [
                    'platform_key' => 'ctrip',
                    'hotel_key' => 'system:7',
                    'revenue' => 900.0,
                    'gross_revenue' => 900.0,
                    'room_revenue' => 900.0,
                    'commission_amount' => null,
                    'room_nights' => 9.0,
                    'order_count' => 9,
                    'source_trace' => $this->trace(202, 'ctrip', 'business', '2026-05-21'),
                ],
            ],
            'fact_ota_traffic' => [],
            'fact_ota_comment' => [],
        ]);

        self::assertSame(10.0, $metrics['totals']['commission_rate']);
        self::assertContains('commission_fields_partial', array_column($metrics['data_gaps'], 'code'));
        self::assertContains('commission_fields_partial', $metrics['metric_trust']['totals.commission_rate']['failure_reasons']);
    }

    public function testRevenueMetricsExposeTraceableTrustMetadataForEachMetric(): void
    {
        $rows = $this->sampleRows();
        $rows[0]['update_time'] = '2026-05-18 12:30:00';
        $rows[0]['validation_status'] = 'normal';
        $rows[0]['validation_flags'] = '[]';
        $rows[1]['update_time'] = '2026-05-18 12:35:00';
        $rows[1]['validation_status'] = 'normal';
        $rows[1]['validation_flags'] = '[]';
        $rows[2]['update_time'] = '2026-05-18 12:40:00';
        $rows[2]['validation_status'] = 'normal';
        $rows[2]['validation_flags'] = '[]';

        $metrics = (new OtaRevenueMetricService())->summarizeDataset(
            (new OtaStandardEtlService())->buildDatasetFromRows($rows)
        );

        self::assertArrayHasKey('metric_trust', $metrics);
        foreach ([
            'totals.revenue',
            'totals.room_nights',
            'totals.order_count',
            'totals.adr',
            'totals.occ',
            'totals.revpar',
            'totals.commission_amount',
            'totals.net_revenue',
            'totals.net_revpar',
            'totals.avg_lead_time_days',
            'totals.cancellation_rate',
            'totals.room_night_cancellation_rate',
            'totals.review_count',
            'totals.avg_comment_score',
            'traffic.avg_flow_rate',
            'traffic.avg_submit_rate',
            'competitor_price.avg_price_gap',
        ] as $metricKey) {
            self::assertArrayHasKey($metricKey, $metrics['metric_trust']);
            self::assertArrayHasKey('source', $metrics['metric_trust'][$metricKey]);
            self::assertArrayHasKey('caliber', $metrics['metric_trust'][$metricKey]);
            self::assertArrayHasKey('updated_at', $metrics['metric_trust'][$metricKey]);
            self::assertArrayHasKey('failure_reasons', $metrics['metric_trust'][$metricKey]);
            self::assertArrayHasKey('saved_success', $metrics['metric_trust'][$metricKey]);
        }

        self::assertSame('online_daily_data', $metrics['metric_trust']['totals.revenue']['source']['table']);
        self::assertSame([1], $metrics['metric_trust']['totals.revenue']['source']['row_ids']);
        self::assertSame(['ctrip'], $metrics['metric_trust']['totals.revenue']['source']['platforms']);
        self::assertSame(['business'], $metrics['metric_trust']['totals.revenue']['source']['data_types']);
        self::assertSame('sum(fact_ota_daily.revenue)', $metrics['metric_trust']['totals.revenue']['caliber']);
        self::assertSame('2026-05-18 12:30:00', $metrics['metric_trust']['totals.revenue']['updated_at']);
        self::assertTrue($metrics['metric_trust']['totals.revenue']['saved_success']);
        self::assertSame([], $metrics['metric_trust']['totals.revenue']['failure_reasons']);

        self::assertSame('sum(fact_ota_daily.room_revenue) / sum(fact_ota_daily.room_nights)', $metrics['metric_trust']['totals.adr']['caliber']);
        self::assertSame('sum(fact_ota_daily.room_revenue) / sum(fact_ota_daily.available_room_nights)', $metrics['metric_trust']['totals.revpar']['caliber']);
        self::assertSame('avg(fact_ota_daily.lead_time_days)', $metrics['metric_trust']['totals.avg_lead_time_days']['caliber']);
        self::assertSame([2], $metrics['metric_trust']['traffic.avg_flow_rate']['source']['row_ids']);
        self::assertSame('2026-05-18 12:35:00', $metrics['metric_trust']['traffic.avg_flow_rate']['updated_at']);

        $missingCancel = (new OtaRevenueMetricService())->summarizeDataset(
            (new OtaStandardEtlService())->buildDatasetFromRows([
                array_replace($rows[0], [
                    'raw_data' => json_encode(['our_price' => 200, 'competitor_price' => 220], JSON_UNESCAPED_UNICODE),
                ]),
            ])
        );

        self::assertFalse($missingCancel['metric_trust']['totals.cancellation_rate']['saved_success']);
        self::assertContains('cancellation_fields_missing', $missingCancel['metric_trust']['totals.cancellation_rate']['failure_reasons']);
    }

    public function testEtlUsesPlatformFallbackAndRecursivelySanitizesRawData(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
            [
                'id' => 10,
                'system_hotel_id' => 9,
                'hotel_id' => 'poi-9',
                'hotel_name' => 'Hotel Gamma',
                'source' => '',
                'platform' => 'Meituan',
                'data_type' => 'business',
                'data_date' => '2026-05-19',
                'source_trace_id' => 'trace-business-10',
                'readback_verified' => 1,
                'amount' => 300,
                'quantity' => 2,
                'book_order_num' => 1,
                'raw_data' => json_encode([
                    'headers' => [
                        'Cookie' => 'secret-cookie',
                        'nested' => ['accessToken' => 'secret-token'],
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ],
        ]);

        self::assertSame('ready', $dataset['status']);
        self::assertSame('meituan', $dataset['fact_ota_daily'][0]['platform_key']);
        self::assertArrayNotHasKey('Cookie', $dataset['fact_ota_daily'][0]['raw_data']['headers']);
        self::assertArrayNotHasKey('accessToken', $dataset['fact_ota_daily'][0]['raw_data']['headers']['nested']);
    }

    public function testEtlPrefersExplicitPlatformOverSharedStorageSource(): void
    {
        $base = [
            'system_hotel_id' => 80,
            'hotel_id' => 'shared-hotel-80',
            'hotel_name' => 'Shared Source Hotel',
            'source' => 'ctrip',
            'data_type' => 'business',
            'data_date' => '2026-07-18',
            'dimension' => 'daily_business',
            'compare_type' => 'self',
            'quantity' => 1,
            'book_order_num' => 1,
            'readback_verified' => 1,
            'data_period' => 'historical_daily',
            'is_final' => 1,
            'raw_data' => '{}',
        ];
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
            array_merge($base, [
                'id' => 20,
                'platform' => 'ctrip',
                'amount' => 200,
                'source_trace_id' => 'trace-ctrip-20',
                'snapshot_time' => '2026-07-19 01:00:00',
            ]),
            array_merge($base, [
                'id' => 21,
                'platform' => 'qunar',
                'amount' => 300,
                'source_trace_id' => 'trace-qunar-21',
                'snapshot_time' => '2026-07-19 01:05:00',
            ]),
        ]);

        self::assertSame(['ctrip', 'qunar'], array_column($dataset['dim_platform'], 'platform_key'));
        self::assertSame(['ctrip', 'qunar'], array_column($dataset['fact_ota_daily'], 'platform_key'));
        self::assertSame([200.0, 300.0], array_column($dataset['fact_ota_daily'], 'revenue'));
        self::assertSame(0, $dataset['data_quality']['superseded_period_rows']);
    }

    public function testEtlRedactsLegacyOrderRawDataBeforeFactsExposeIt(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
            [
                'id' => 12,
                'system_hotel_id' => 9,
                'hotel_id' => 'poi-9',
                'hotel_name' => 'Hotel Gamma',
                'source' => 'meituan',
                'data_type' => 'orders',
                'data_date' => '2026-05-19',
                'amount' => 688,
                'quantity' => 2,
                'raw_data' => json_encode([
                    'orderList' => [
                        [
                            'orderId' => 'MT-ORDER-LEGACY-001',
                            'guestName' => 'Legacy Guest',
                            'phone' => '13700001111',
                            'idCardNo' => 'IDCARD-LEGACY-001',
                            'customerRemark' => 'late arrival needs call',
                            'amount' => 688,
                        ],
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ],
        ]);

        $raw = $dataset['fact_ota_daily'][0]['raw_data'];
        $encoded = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        self::assertStringNotContainsString('MT-ORDER-LEGACY-001', (string)$encoded);
        self::assertStringNotContainsString('Legacy Guest', (string)$encoded);
        self::assertStringNotContainsString('13700001111', (string)$encoded);
        self::assertStringNotContainsString('IDCARD-LEGACY-001', (string)$encoded);
        self::assertStringNotContainsString('late arrival', (string)$encoded);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string)($raw['orderList'][0]['order_id_hash'] ?? ''));
        self::assertSame('L***', $raw['orderList'][0]['guest_name_masked'] ?? null);
        self::assertSame('*******1111', $raw['orderList'][0]['phone_masked'] ?? null);
        self::assertSame(688, $raw['orderList'][0]['amount'] ?? null);
        self::assertSame('order', $dataset['fact_ota_daily'][0]['source_trace']['data_type'] ?? null);
    }

    public function testEtlRejectsInvalidDateFiltersInsteadOfWideningScope(): void
    {
        $method = new ReflectionMethod(OtaStandardEtlService::class, 'filterDateValue');
        $method->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid start_date');

        $method->invoke(new OtaStandardEtlService(), '2026/05/19', 'start_date');
    }

    public function testEtlSourceFilterValuesIncludeProjectAliases(): void
    {
        $method = new ReflectionMethod(OtaStandardEtlService::class, 'sourceFilterValues');
        $method->setAccessible(true);

        $values = $method->invoke(new OtaStandardEtlService(), 'meituan');

        self::assertContains('meituan', $values);
        self::assertContains('meituan_rank', $values);
        self::assertContains('meituan_business', $values);
        self::assertContains('meituan_browser_profile', $values);
    }

    public function testInsightAnalysisPrioritizesAdrCancellationTrafficAndCompetitorPriceWithoutLstm(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows($this->sampleRows());
        $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);
        $analysis = (new OtaInsightAnalysisService())->analyzeMetrics($metrics);

        self::assertSame('deterministic_rules', $analysis['model_policy']['model_type']);
        self::assertContains('LSTM', $analysis['model_policy']['excluded_models']);

        $keys = array_column($analysis['modules'], 'key');
        self::assertSame(['adr', 'revpar', 'net_revpar', 'cancellation_rate', 'traffic_conversion', 'competitor_price_gap'], $keys);
        self::assertSame('available', $analysis['modules'][0]['status']);
        self::assertSame('available', $analysis['modules'][1]['status']);
        self::assertSame('available', $analysis['modules'][2]['status']);
        self::assertSame('available', $analysis['modules'][3]['status']);
        self::assertSame('watch', $analysis['modules'][5]['status']);
    }

    public function testInsightOptionalModulesPreserveMissingMetricsInsteadOfInventingZero(): void
    {
        $service = new OtaInsightAnalysisService();
        $advertising = new ReflectionMethod($service, 'advertisingEfficiencyModule');
        $advertising->setAccessible(true);
        $adModule = $advertising->invoke($service, [
            'rows' => 1,
            'spend' => 100,
            'roas' => 2.5,
            'order_amount' => null,
            'bookings' => null,
            'room_nights' => null,
            'impressions' => null,
            'clicks' => null,
        ]);

        self::assertNull($adModule['metrics']['order_amount']);
        self::assertNull($adModule['metrics']['room_nights']);
        self::assertContains('advertising_order_amount_missing', $adModule['data_gaps']);

        $quality = new ReflectionMethod($service, 'serviceQualityModule');
        $quality->setAccessible(true);
        $qualityModule = $quality->invoke($service, [
            'rows' => 1,
            'avg_psi_score' => 88,
            'avg_service_score' => 90,
            'hotel_collect' => null,
        ]);
        self::assertNull($qualityModule['metrics']['hotel_collect']);
        self::assertContains('service_quality_hotel_collect_missing', $qualityModule['data_gaps']);
    }

    public function testInsightAnalysisDoesNotPromoteBlockedCredibilityGateToReady(): void
    {
        $metrics = (new OtaRevenueMetricService())->summarizeDataset([
            'status' => 'failed',
            'data_quality' => [
                'input_rows' => 1,
                'accepted_rows' => 1,
                'rejected_rows' => [],
            ],
            'fact_ota_daily' => [[
                'id' => 701,
                'platform_key' => 'ctrip',
                'hotel_key' => 'system:7',
                'revenue' => 1200.0,
                'room_revenue' => 1200.0,
                'room_nights' => 6.0,
                'available_room_nights' => 10.0,
                'order_count' => 4,
                'source_trace' => [
                    'saved_success' => true,
                    'failure_reasons' => [],
                ],
            ]],
        ]);

        $analysis = (new OtaInsightAnalysisService())->analyzeMetrics($metrics);

        self::assertSame('blocked_by_data_credibility', $analysis['status']);
        self::assertSame('blocked', $analysis['credibility_gate']['status']);
        self::assertContains('ota_dataset_failed', $analysis['credibility_gate']['reason_codes']);
        self::assertTrue($analysis['human_review_required']);
        foreach ($analysis['modules'] as $module) {
            self::assertSame('blocked_by_data_credibility', $module['status']);
            self::assertFalse($module['actionable']);
            self::assertContains('ota_dataset_failed', $module['blocking_reason_codes']);
        }
    }

    public function testMeituanAdvertisingKeepsExposureBookingsAndRoasInTheirOwnSemantics(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([[
            'id' => 8801,
            'system_hotel_id' => 80,
            'hotel_id' => '1029642156589279',
            'hotel_name' => 'Dunhuang Meituan Hotel',
            'source' => 'meituan',
            'data_type' => 'advertising',
            'data_date' => '2026-07-11',
            'dimension' => 'ads',
            'amount' => 300,
            'quantity' => 9,
            'book_order_num' => 9,
            'list_exposure' => 5000,
            'detail_exposure' => 200,
            'flow_rate' => 4.0,
            'data_value' => 5000,
            'raw_data' => json_encode([
                'spend' => 300,
                'order_amount' => 1800,
                'book_order_num' => 9,
                'roas' => 6.0,
                'exposure_count' => 5000,
                'click_count' => 200,
            ], JSON_UNESCAPED_UNICODE),
        ]]);

        self::assertCount(1, $dataset['fact_ota_advertising']);
        $fact = $dataset['fact_ota_advertising'][0];

        self::assertSame(300.0, $fact['spend']);
        self::assertSame(1800.0, $fact['order_amount']);
        self::assertSame(9, $fact['bookings']);
        self::assertNull($fact['room_nights']);
        self::assertSame(5000, $fact['impressions']);
        self::assertSame(200, $fact['clicks']);
        self::assertSame(4.0, $fact['ctr']);
        self::assertSame(4.5, $fact['cvr']);
        self::assertSame(6.0, $fact['roas']);
    }

    public function testLegacyMeituanAdvertisingRecomputesPercentScaledRoasFromAmounts(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([[
            'id' => 8802,
            'system_hotel_id' => 80,
            'hotel_id' => '1029642156589279',
            'hotel_name' => 'Dunhuang Meituan Hotel',
            'source' => 'meituan',
            'data_type' => 'advertising',
            'data_date' => '2026-07-11',
            'dimension' => 'ads',
            'amount' => 100,
            'book_order_num' => 4,
            'list_exposure' => 1000,
            'detail_exposure' => 100,
            'data_value' => 1000,
            'raw_data' => json_encode([
                'spend' => 100,
                'order_amount' => 80,
                'book_order_num' => 4,
                'roas' => 80,
                'exposure_count' => 1000,
                'click_count' => 100,
            ], JSON_UNESCAPED_UNICODE),
        ]]);

        $fact = $dataset['fact_ota_advertising'][0];
        self::assertSame(0.8, $fact['roas']);
        self::assertSame(4.0, $fact['cvr']);
        self::assertNull($fact['room_nights']);
    }

    public function testLegacyMeituanRankShapedBusinessCannotEnterDailyRevenueFacts(): void
    {
        $base = [
            'system_hotel_id' => 80,
            'hotel_id' => '1029642156589279',
            'hotel_name' => 'Dunhuang Meituan Hotel',
            'source' => 'meituan',
            'data_type' => 'business',
            'data_date' => '2026-07-11',
            'amount' => 999,
            'quantity' => 8,
            'book_order_num' => 3,
            'compare_type' => 'competitor',
        ];

        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
            $base + [
                'id' => 8810,
                'dimension' => 'peer_rank:P_XS:range=0:sales',
                'raw_data' => json_encode([
                    'rankType' => 'P_XS',
                    'rank' => 2,
                    'poiId' => 'competitor-100',
                    'url' => 'https://eb.meituan.com/api/v1/ebooking/business/peer/rank/data/detail',
                ], JSON_UNESCAPED_UNICODE),
            ],
            $base + [
                'id' => 8811,
                'dimension' => 'peer_rank:P_RZ:range=0:room_nights',
                'raw_data' => json_encode([
                    'rankType' => 'P_RZ',
                    'url' => 'https://eb.meituan.com/api/v1/ebooking/business/peer/rank/data/detail',
                ], JSON_UNESCAPED_UNICODE),
            ],
            array_merge($base, [
                'id' => 8812,
                'dimension' => '浏览榜',
                'compare_type' => null,
                'raw_data' => json_encode([
                    'poiName' => '历史同行酒店',
                    'rank' => 7,
                    'percent' => 12.34,
                    'aiMetricName' => 'P_LL_VIEW',
                ], JSON_UNESCAPED_UNICODE),
            ]),
        ]);

        self::assertCount(0, $dataset['fact_ota_daily']);
        self::assertCount(2, $dataset['fact_ota_peer_rank']);
        self::assertSame('P_XS', $dataset['fact_ota_peer_rank'][0]['rank_type']);
        self::assertSame(2.0, $dataset['fact_ota_peer_rank'][0]['rank']);
        self::assertCount(1, $dataset['data_quality']['rejected_rows']);
        self::assertSame('semantic_type_conflict', $dataset['data_quality']['rejected_rows'][0]['reason']);
    }

    public function testOrderMissingMetricsRemainNullThroughEtl(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([[
            'id' => 8803,
            'system_hotel_id' => 80,
            'hotel_id' => '1029642156589279',
            'hotel_name' => 'Dunhuang Meituan Hotel',
            'source' => 'meituan',
            'data_type' => 'order',
            'data_date' => '2026-07-11',
            'dimension' => 'order:confirmed:hash',
            'amount' => 500,
            'quantity' => null,
            'book_order_num' => null,
            'data_value' => null,
            'raw_data' => json_encode(['total_amount' => 500], JSON_UNESCAPED_UNICODE),
        ]]);

        $fact = $dataset['fact_ota_daily'][0];
        self::assertNull($fact['room_nights']);
        self::assertNull($fact['order_count']);
        self::assertNull($fact['data_value']);
        self::assertNull($fact['adr']);

        $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);
        self::assertNull($metrics['totals']['room_nights']);
        self::assertNull($metrics['totals']['order_count']);
        self::assertNull($metrics['totals']['adr']);
    }

    public function testReviewMissingCountsDoNotBecomeOneInRevenueMetrics(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([[
            'id' => 8804,
            'system_hotel_id' => 80,
            'hotel_id' => '1029642156589279',
            'hotel_name' => 'Dunhuang Meituan Hotel',
            'source' => 'meituan',
            'data_type' => 'review',
            'data_date' => '2026-07-11',
            'dimension' => 'review:meituan',
            'comment_score' => 3.8,
            'quantity' => null,
            'data_value' => null,
            'raw_data' => json_encode(['comment_score' => 3.8], JSON_UNESCAPED_UNICODE),
        ]]);

        $fact = $dataset['fact_ota_comment'][0];
        self::assertNull($fact['comment_count']);
        self::assertNull($fact['bad_review_count']);

        $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);
        self::assertNull($metrics['totals']['review_count']);
        self::assertSame(3.8, $metrics['totals']['avg_comment_score']);
    }

    public function testEtlUsesLatestRealtimeSnapshotWhenNoFinalRowExists(): void
    {
        $base = [
            'system_hotel_id' => 80,
            'hotel_id' => '1029642156589279',
            'hotel_name' => 'Dunhuang Meituan Hotel',
            'source' => 'meituan',
            'data_type' => 'traffic',
            'data_date' => '2026-07-12',
            'dimension' => 'traffic',
            'data_period' => 'realtime_snapshot',
            'is_final' => 0,
            'raw_data' => '{}',
        ];
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
            $base + ['id' => 1, 'snapshot_time' => '2026-07-12 09:00:00', 'snapshot_bucket' => '2026-07-12 09:00', 'list_exposure' => 100],
            $base + ['id' => 2, 'snapshot_time' => '2026-07-12 10:00:00', 'snapshot_bucket' => '2026-07-12 10:00', 'list_exposure' => 200],
        ]);

        self::assertCount(1, $dataset['fact_ota_traffic']);
        self::assertSame(200, $dataset['fact_ota_traffic'][0]['list_exposure']);
        self::assertSame(2, $dataset['data_quality']['source_input_rows']);
        self::assertSame(1, $dataset['data_quality']['input_rows']);
        self::assertSame(1, $dataset['data_quality']['superseded_period_rows']);
    }

    public function testEtlUsesFinalHistoricalRowInsteadOfRealtimeSnapshotsForSameGrain(): void
    {
        $base = [
            'system_hotel_id' => 80,
            'hotel_id' => '1029642156589279',
            'hotel_name' => 'Dunhuang Meituan Hotel',
            'source' => 'meituan',
            'data_type' => 'traffic',
            'data_date' => '2026-07-11',
            'dimension' => 'traffic',
            'raw_data' => '{}',
        ];
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
            $base + ['id' => 3, 'data_period' => 'realtime_snapshot', 'is_final' => 0, 'snapshot_time' => '2026-07-11 22:00:00', 'list_exposure' => 240],
            $base + ['id' => 4, 'data_period' => 'historical_daily', 'is_final' => 1, 'snapshot_time' => '2026-07-12 01:00:00', 'list_exposure' => 180],
        ]);

        self::assertCount(1, $dataset['fact_ota_traffic']);
        self::assertSame(180, $dataset['fact_ota_traffic'][0]['list_exposure']);
    }

    public function testEtlCanonicalizesCumulativeOrderAndReviewSnapshotsButKeepsStableEvents(): void
    {
        $base = [
            'system_hotel_id' => 80,
            'hotel_id' => '1029642156589279',
            'hotel_name' => 'Dunhuang Meituan Hotel',
            'source' => 'meituan',
            'data_date' => '2026-07-12',
            'data_period' => 'realtime_snapshot',
            'is_final' => 0,
        ];

        $cumulativeOrders = (new OtaStandardEtlService())->buildDatasetFromRows([
            $base + [
                'id' => 10,
                'data_type' => 'order',
                'dimension' => 'order:summary',
                'snapshot_time' => '2026-07-12 09:00:00',
                'amount' => 100,
                'book_order_num' => 1,
                'raw_data' => '{}',
            ],
            $base + [
                'id' => 11,
                'data_type' => 'order',
                'dimension' => 'order:summary',
                'snapshot_time' => '2026-07-12 10:00:00',
                'amount' => 250,
                'book_order_num' => 2,
                'raw_data' => '{}',
            ],
        ]);
        self::assertCount(1, $cumulativeOrders['fact_ota_daily']);
        self::assertSame(250.0, $cumulativeOrders['fact_ota_daily'][0]['revenue']);
        self::assertSame(1, $cumulativeOrders['data_quality']['superseded_period_rows']);

        $stableOrderEvents = (new OtaStandardEtlService())->buildDatasetFromRows([
            $base + [
                'id' => 12,
                'data_type' => 'order',
                'dimension' => 'order:confirmed',
                'snapshot_time' => '2026-07-12 10:00:00',
                'amount' => 120,
                'raw_data' => json_encode(['order_id' => 'order-a'], JSON_UNESCAPED_UNICODE),
            ],
            $base + [
                'id' => 13,
                'data_type' => 'order',
                'dimension' => 'order:confirmed',
                'snapshot_time' => '2026-07-12 10:00:00',
                'amount' => 180,
                'raw_data' => json_encode(['order_id' => 'order-b'], JSON_UNESCAPED_UNICODE),
            ],
        ]);
        self::assertCount(2, $stableOrderEvents['fact_ota_daily']);

        $cumulativeReviews = (new OtaStandardEtlService())->buildDatasetFromRows([
            $base + [
                'id' => 14,
                'data_type' => 'review',
                'dimension' => 'review:summary',
                'snapshot_time' => '2026-07-12 09:00:00',
                'quantity' => 20,
                'raw_data' => json_encode(['comment_count' => 20], JSON_UNESCAPED_UNICODE),
            ],
            $base + [
                'id' => 15,
                'data_type' => 'review',
                'dimension' => 'review:summary',
                'snapshot_time' => '2026-07-12 10:00:00',
                'quantity' => 25,
                'raw_data' => json_encode(['comment_count' => 25], JSON_UNESCAPED_UNICODE),
            ],
        ]);
        self::assertCount(1, $cumulativeReviews['fact_ota_comment']);
        self::assertSame(25.0, $cumulativeReviews['fact_ota_comment'][0]['comment_count']);
    }

    public function testEtlKeepsLatestTrustedOrderVersionAndReportsUntrustedNewerVersion(): void
    {
        $hash = str_repeat('c', 64);
        $base = [
            'system_hotel_id' => 80,
            'hotel_id' => '1029642156589279',
            'hotel_name' => 'Dunhuang Meituan Hotel',
            'platform' => 'meituan',
            'source' => 'meituan',
            'data_type' => 'order',
            'data_date' => '2026-07-12',
            'dimension' => 'order:confirmed',
            'data_period' => 'realtime_snapshot',
            'is_final' => 0,
            'validation_status' => 'verified',
            'validation_flags' => '[]',
            'status' => 'success',
            'save_status' => 'success',
            'ingestion_method' => 'browser_profile',
            'source_trace_id' => 'trace-order-c',
            'data_source_id' => 68,
            'sync_task_id' => 2001,
            'book_order_num' => 1,
            'quantity' => 1,
            'raw_data' => json_encode([
                'order_id_hash' => $hash,
                'source_trace_id' => 'trace-order-c',
            ], JSON_UNESCAPED_UNICODE),
        ];

        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
            $base + [
                'id' => 21,
                'snapshot_time' => '2026-07-12 09:00:00',
                'amount' => 120,
                'readback_verified' => 1,
            ],
            $base + [
                'id' => 22,
                'snapshot_time' => '2026-07-12 10:00:00',
                'amount' => 180,
                'readback_verified' => 1,
            ],
            $base + [
                'id' => 23,
                'snapshot_time' => '2026-07-12 11:00:00',
                'amount' => 999,
                'readback_verified' => 0,
            ],
        ]);

        self::assertCount(1, $dataset['fact_ota_daily']);
        self::assertSame(180.0, $dataset['fact_ota_daily'][0]['revenue']);
        self::assertTrue($dataset['fact_ota_daily'][0]['source_trace']['saved_success']);
        $quality = $dataset['data_quality']['order_dedup'];
        self::assertSame(3, $quality['order_identity_candidate_rows']);
        self::assertSame(2, $quality['order_identity_covered_rows']);
        self::assertSame(1, $quality['order_identity_unverifiable_rows']);
        self::assertSame(66.67, $quality['order_identity_coverage_percent']);
        self::assertSame(1, $quality['distinct_verified_order_grains']);
        self::assertSame(2, $quality['suppressed_duplicate_order_rows']);
        self::assertSame(1, $quality['suppressed_untrusted_duplicate_order_rows']);
        self::assertSame(1, $quality['newer_untrusted_duplicate_order_rows']);
    }

    public function testSnapshotCanonicalizationKeepsDistinctCampaignAndPeerIdentities(): void
    {
        $base = [
            'system_hotel_id' => 80,
            'hotel_id' => '1029642156589279',
            'hotel_name' => 'Dunhuang Meituan Hotel',
            'source' => 'meituan',
            'data_date' => '2026-07-12',
            'data_period' => 'realtime_snapshot',
            'snapshot_time' => '2026-07-12 10:00:00',
            'is_final' => 0,
        ];

        $ads = (new OtaStandardEtlService())->buildDatasetFromRows([
            $base + [
                'id' => 20,
                'data_type' => 'advertising',
                'dimension' => 'ads',
                'amount' => 10,
                'raw_data' => json_encode(['campaignId' => 'campaign-a'], JSON_UNESCAPED_UNICODE),
            ],
            $base + [
                'id' => 21,
                'data_type' => 'advertising',
                'dimension' => 'ads',
                'amount' => 20,
                'raw_data' => json_encode(['campaignId' => 'campaign-b'], JSON_UNESCAPED_UNICODE),
            ],
        ]);
        self::assertCount(2, $ads['fact_ota_advertising']);
        self::assertSame(['campaign-a', 'campaign-b'], array_column($ads['fact_ota_advertising'], 'campaign_id'));

        $peerRanks = (new OtaStandardEtlService())->buildDatasetFromRows([
            $base + [
                'id' => 22,
                'data_type' => 'peer_rank',
                'dimension' => 'peer_rank:traffic',
                'raw_data' => json_encode(['poiId' => 'peer-a', 'rank' => 1], JSON_UNESCAPED_UNICODE),
            ],
            $base + [
                'id' => 23,
                'data_type' => 'peer_rank',
                'dimension' => 'peer_rank:traffic',
                'raw_data' => json_encode(['poiId' => 'peer-b', 'rank' => 2], JSON_UNESCAPED_UNICODE),
            ],
        ]);
        self::assertCount(2, $peerRanks['fact_ota_peer_rank']);
    }

    public function testUnknownPeriodsAreNotCollapsedAsSnapshots(): void
    {
        $base = [
            'system_hotel_id' => 80,
            'hotel_id' => '1029642156589279',
            'hotel_name' => 'Dunhuang Meituan Hotel',
            'source' => 'meituan',
            'data_type' => 'traffic',
            'data_date' => '2026-07-12',
            'dimension' => 'traffic',
            'data_period' => 'manual_dom_csv',
            'raw_data' => '{}',
        ];
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
            $base + ['id' => 30, 'snapshot_time' => '2026-07-12 09:00:00', 'list_exposure' => 100],
            $base + ['id' => 31, 'snapshot_time' => '2026-07-12 10:00:00', 'list_exposure' => 200],
        ]);

        self::assertCount(2, $dataset['fact_ota_traffic']);
        self::assertSame(0, $dataset['data_quality']['superseded_period_rows']);
    }

    public function testMissingNumericFieldsStayNullInFactsAndAggregates(): void
    {
        $base = [
            'system_hotel_id' => 80,
            'hotel_id' => '1029642156589279',
            'hotel_name' => 'Dunhuang Meituan Hotel',
            'source' => 'meituan',
            'data_date' => '2026-07-12',
            'raw_data' => '{}',
        ];
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
            $base + ['id' => 40, 'data_type' => 'business', 'dimension' => 'business'],
            $base + ['id' => 41, 'data_type' => 'traffic', 'dimension' => 'traffic'],
            $base + ['id' => 42, 'data_type' => 'advertising', 'dimension' => 'ads'],
        ]);

        $daily = $dataset['fact_ota_daily'][0];
        self::assertNull($daily['revenue']);
        self::assertNull($daily['room_revenue']);
        self::assertNull($daily['room_nights']);
        self::assertNull($daily['order_count']);

        $traffic = $dataset['fact_ota_traffic'][0];
        self::assertNull($traffic['list_exposure']);
        self::assertNull($traffic['detail_exposure']);
        self::assertNull($traffic['flow_rate']);
        self::assertNull($traffic['order_filling_num']);
        self::assertNull($traffic['order_submit_num']);

        $advertising = $dataset['fact_ota_advertising'][0];
        self::assertNull($advertising['spend']);
        self::assertNull($advertising['order_amount']);
        self::assertNull($advertising['bookings']);
        self::assertNull($advertising['impressions']);
        self::assertNull($advertising['clicks']);

        $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);
        self::assertNull($metrics['totals']['revenue']);
        self::assertNull($metrics['totals']['room_revenue']);
        self::assertNull($metrics['totals']['room_nights']);
        self::assertNull($metrics['totals']['order_count']);
        self::assertNull($metrics['by_platform'][0]['revenue']);
        self::assertNull($metrics['by_platform'][0]['room_nights']);
        self::assertNull($metrics['by_platform'][0]['order_count']);
        self::assertNull($metrics['by_hotel'][0]['revenue']);
        self::assertNull($metrics['channel_contribution'][0]['revenue']);
        self::assertNull($metrics['channel_contribution'][0]['room_nights']);
        self::assertNull($metrics['channel_contribution'][0]['order_count']);
        self::assertNull($metrics['advertising']['spend']);
        self::assertNull($metrics['advertising']['order_amount']);
        self::assertNull($metrics['advertising']['bookings']);
        self::assertNull($metrics['advertising']['impressions']);
        self::assertNull($metrics['advertising']['clicks']);
    }

    public function testDerivedRevenueMetricsStayNullWhenRevenueEvidenceIsMissing(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([[
            'id' => 43,
            'system_hotel_id' => 80,
            'hotel_id' => '1029642156589279',
            'hotel_name' => 'Dunhuang Meituan Hotel',
            'source' => 'meituan',
            'data_type' => 'business',
            'data_date' => '2026-07-12',
            'quantity' => 2,
            'book_order_num' => 1,
            'available_room_nights' => 10,
            'raw_data' => '{}',
        ]]);

        $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);
        self::assertNull($metrics['totals']['revenue']);
        self::assertNull($metrics['totals']['room_revenue']);
        self::assertNull($metrics['totals']['adr']);
        self::assertNull($metrics['totals']['revpar']);
        self::assertNull($metrics['by_platform'][0]['adr']);
        self::assertNull($metrics['by_platform'][0]['revpar']);
        self::assertNull($metrics['by_hotel'][0]['adr']);
        self::assertNull($metrics['by_hotel'][0]['revpar']);
    }

    public function testGenericAmountSettlementAndRoomCountDoNotMasqueradeAsRoomMetrics(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([[
            'id' => 44,
            'system_hotel_id' => 80,
            'hotel_id' => 'ctrip-80',
            'hotel_name' => 'Hotel Scope Guard',
            'source' => 'ctrip',
            'data_type' => 'business',
            'data_date' => '2026-07-30',
            'amount' => 999,
            'quantity' => 3,
            'raw_data' => json_encode([
                'settlement_amount' => 888,
                'total_rooms_count' => 20,
            ], JSON_UNESCAPED_UNICODE),
        ]]);

        $daily = $dataset['fact_ota_daily'][0];
        self::assertSame(999.0, $daily['gross_revenue']);
        self::assertNull($daily['room_revenue']);
        self::assertNull($daily['room_revenue_basis']);
        self::assertSame(888.0, $daily['settlement_amount']);
        self::assertNull($daily['net_revenue']);
        self::assertNull($daily['available_room_nights']);
        self::assertNull($daily['adr']);
        self::assertNull($daily['revpar']);
        self::assertNull($daily['net_revpar']);
    }

    public function testMeituanBusinessSalesCardsWinAndRevenueConflictIsSurfaced(): void
    {
        $businessRaw = [
            'sales_amount' => 5093.86,
            'sales_room_nights' => 8,
            'sales_avg_price' => 636.73,
            'paid_order_count' => 8,
            'amount' => 5093.86,
            'quantity' => 8,
            'room_nights' => 8,
            'book_order_num' => 8,
            'compare_type' => 'self',
            'is_self' => true,
            'business_evidence_source' => 'page.business_period_selection.readback',
            'date_source' => 'page.business_period_selection.readback',
            'date_scope_evidence' => 'meituan_business_yesterday_tab',
            '_capture_source' => 'xhr:traffic:business_data',
            '_meituan_business_metric_sources' => [
                'sales_amount' => [
                    'source_path' => 'data.cards.7.value',
                    'source_kind' => 'card',
                ],
                'sales_room_nights' => [
                    'source_path' => 'data.cards.5.value',
                    'source_kind' => 'card',
                ],
            ],
        ];
        $capturedFacts = [
            [
                'metric_key' => 'order_amount',
                'storage_field' => 'online_daily_data.amount',
                'source_key' => 'amount',
                'source_path' => 'data.amount',
                'status' => 'captured',
                'stored_value_present' => true,
            ],
            [
                'metric_key' => 'room_nights',
                'storage_field' => 'online_daily_data.quantity',
                'source_key' => 'quantity',
                'source_path' => 'data.quantity',
                'status' => 'captured',
                'stored_value_present' => true,
            ],
        ];
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
            [
                'id' => 68706,
                'system_hotel_id' => 80,
                'hotel_id' => 'meituan-hotel-80',
                'hotel_name' => 'Hotel Meituan Business Cards',
                'source' => 'meituan',
                'data_type' => 'business',
                'data_date' => '2026-07-29',
                'amount' => 5093.86,
                'quantity' => 8,
                'book_order_num' => 8,
                'compare_type' => 'self',
                'sync_task_id' => 3041,
                'source_trace_id' => 'trace-meituan-business-cards',
                'readback_verified' => 1,
                'data_period' => 'realtime_snapshot',
                'snapshot_time' => '2026-07-30 10:05:00',
                'is_final' => 0,
                'update_time' => '2026-07-30 10:06:10',
                'raw_data' => json_encode([
                    'row' => $businessRaw,
                    'field_facts' => $capturedFacts,
                ], JSON_UNESCAPED_UNICODE),
            ],
            [
                'id' => 66381,
                'system_hotel_id' => 80,
                'hotel_id' => 'meituan-hotel-80',
                'hotel_name' => 'Hotel Meituan Order Aggregate',
                'source' => 'meituan',
                'data_type' => 'order',
                'data_date' => '2026-07-29',
                'amount' => 5604.14,
                'quantity' => 8,
                'book_order_num' => 8,
                'compare_type' => 'self',
                'sync_task_id' => 3041,
                'source_trace_id' => 'trace-meituan-order-aggregate',
                'readback_verified' => 1,
                'data_period' => 'realtime_snapshot',
                'snapshot_time' => '2026-07-30 10:05:00',
                'is_final' => 0,
                'update_time' => '2026-07-30 02:09:11',
                'raw_data' => json_encode([
                    'row' => [
                        'amount' => 5604.14,
                        'quantity' => 8,
                        'room_nights' => 8,
                        'compare_type' => 'self',
                        'is_self' => true,
                        'amount_scope' => 'meituan_sale_price_total',
                        'pagination_complete' => true,
                    ],
                    'field_facts' => $capturedFacts,
                ], JSON_UNESCAPED_UNICODE),
            ],
        ]);

        $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);
        self::assertCount(1, $dataset['fact_ota_daily']);
        self::assertSame(1, $dataset['data_quality']['superseded_meituan_revenue_rows']);
        self::assertCount(
            1,
            $dataset['data_quality']['meituan_revenue_representation_conflicts']
        );
        $conflict = $dataset['data_quality']
            ['meituan_revenue_representation_conflicts'][0];
        self::assertSame(5093.86, $conflict['winner_amount']);
        self::assertSame(5604.14, $conflict['candidate_amount']);
        self::assertSame(510.28, $conflict['amount_delta']);
        self::assertSame(10.02, $conflict['amount_delta_percent_of_winner']);
        self::assertSame(3041, $conflict['winner_sync_task_id']);
        self::assertSame(3041, $conflict['candidate_sync_task_id']);
        self::assertSame(
            '2026-07-30 10:05:00',
            $conflict['winner_snapshot_time']
        );
        self::assertSame(
            '2026-07-30 10:05:00',
            $conflict['candidate_snapshot_time']
        );
        self::assertFalse($conflict['winner_is_final']);
        self::assertFalse($conflict['candidate_is_final']);
        self::assertSame('meituan_business_sales_daily', $dataset['fact_ota_daily'][0]['metric_semantic_scope']);
        self::assertSame('verified_meituan_business_sales_cards', $dataset['fact_ota_daily'][0]['room_revenue_basis']);
        self::assertSame(5093.86, $metrics['totals']['revenue']);
        self::assertSame(5093.86, $metrics['totals']['room_revenue']);
        self::assertSame(8.0, $metrics['totals']['room_nights']);
        self::assertSame(8, $metrics['totals']['order_count']);
        self::assertSame(636.73, $metrics['totals']['adr']);
        self::assertTrue($metrics['metric_trust']['totals.adr']['saved_success']);
        self::assertSame(
            'provisional',
            $metrics['metric_trust']['totals.revenue']['source']['finality']
        );
    }

    public function testVerifiedMeituanSalePriceTotalCanServeAsRoomRevenue(): void
    {
        $rawRow = [
            'amount' => 5501.8,
            'quantity' => 6,
            'room_nights' => 6,
            'compare_type' => 'self',
            'is_self' => true,
            'amount_scope' => 'meituan_sale_price_total',
            'amount_source' => 'orderBasePriceModel.salePrice.price',
            'amount_source_unit' => 'cent',
            'amount_storage_unit' => 'yuan',
            'quantity_scope' => 'booked_room_nights',
            'quantity_source' => 'partRefundInfo.totalRoomNightCount',
            'pagination_complete' => true,
            'floor_price_used_as_revenue' => false,
            'guarantee_amount_used_as_revenue' => false,
        ];
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([[
            'id' => 45,
            'system_hotel_id' => 80,
            'hotel_id' => 'meituan-hotel-80',
            'hotel_name' => 'Hotel Meituan Sale Price',
            'source' => 'meituan',
            'data_type' => 'order',
            'data_date' => '2026-07-29',
            'amount' => 5501.8,
            'quantity' => 6,
            'book_order_num' => 3,
            'compare_type' => 'self',
            'source_trace_id' => 'trace-meituan-sale-price-45',
            'readback_verified' => 1,
            'snapshot_time' => '2026-07-30 02:10:58',
            'update_time' => '2026-07-30 02:10:58',
            'raw_data' => json_encode([
                'row' => $rawRow,
                'field_facts' => [
                    [
                        'metric_key' => 'order_amount',
                        'storage_field' => 'online_daily_data.amount',
                        'source_path' => 'data.results.amount',
                        'status' => 'captured',
                    ],
                    [
                        'metric_key' => 'room_nights',
                        'storage_field' => 'online_daily_data.quantity',
                        'source_path' => 'data.results.quantity',
                        'status' => 'captured',
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]]);

        $daily = $dataset['fact_ota_daily'][0];
        $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);

        self::assertSame(5501.8, $daily['room_revenue']);
        self::assertSame('verified_meituan_sale_price_total', $daily['room_revenue_basis']);
        self::assertSame(916.97, $daily['adr']);
        self::assertSame(916.97, $metrics['totals']['adr']);
        self::assertTrue($metrics['metric_trust']['totals.adr']['saved_success']);
        self::assertTrue($metrics['credibility_gate']['decision_use']['revenue_analysis']['allowed']);
    }

    public function testCtripCheckoutFactsAreCanonicalAndCapacityDoesNotPolluteAdr(): void
    {
        $capturedFact = static function (
            string $metricKey,
            string $storageField,
            string $sourceKey
        ): array {
            return [
                'metric_key' => $metricKey,
                'storage_field' => $storageField,
                'source_key' => $sourceKey,
                'source_path' => '$.' . $sourceKey,
                'status' => 'captured',
                'stored_value_present' => true,
            ];
        };
        $common = [
            'system_hotel_id' => 80,
            'hotel_id' => 'ctrip-hotel-80',
            'hotel_name' => 'Hotel Ctrip Checkout',
            'source' => 'ctrip',
            'data_type' => 'business',
            'data_date' => '2026-07-29',
            'data_period' => 'historical_daily',
            'is_final' => 1,
            'sync_task_id' => 2042,
            'readback_verified' => 1,
        ];
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([
            array_replace($common, [
                'id' => 68698,
                'dimension' => 'catalog:business_overview:business_market_overview:order_amount:data.amount',
                'amount' => 2168,
                'quantity' => 3,
                'source_trace_id' => 'ctrip:checkout-catalog',
                'update_time' => '2026-07-30 02:10:50',
                'raw_data' => json_encode([
                    'row' => [
                        'endpoint_id' => 'business_market_overview',
                        'amount' => 2168,
                        'quantity' => 3,
                    ],
                    'field_facts' => [
                        $capturedFact('order_amount', 'online_daily_data.amount', 'amount'),
                        $capturedFact('room_nights', 'online_daily_data.quantity', 'quantity'),
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ]),
            array_replace($common, [
                'id' => 68703,
                'amount' => 3322,
                'quantity' => 4,
                'book_order_num' => 2,
                'source_trace_id' => 'ctrip:booking-generic',
                'update_time' => '2026-07-30 02:10:58',
                'raw_data' => json_encode([
                    'row' => [
                        'endpoint_id' => 'business_market_overview',
                        'amount' => 2168,
                        'quantity' => 3,
                        'bookAmount' => 3322,
                        'bookQuantity' => 4,
                        'bookOrderNum' => 2,
                    ],
                    'field_facts' => [
                        $capturedFact('order_amount', 'online_daily_data.amount', 'bookAmount'),
                        $capturedFact('room_nights', 'online_daily_data.quantity', 'bookQuantity'),
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ]),
            array_replace($common, [
                'id' => 68699,
                'dimension' => 'catalog:business_overview:business_capacity:occupied_rooms:occupiedRooms',
                'quantity' => 2,
                'book_order_num' => 1,
                'source_trace_id' => 'ctrip:capacity-catalog',
                'update_time' => '2026-07-30 02:10:51',
                'raw_data' => json_encode([
                    'row' => [
                        'endpoint_id' => 'business_capacity',
                        'quantity' => 2,
                        'book_order_num' => 1,
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ]),
        ]);

        $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);
        $checkout = array_values(array_filter(
            $dataset['fact_ota_daily'],
            static fn(array $fact): bool => ($fact['metric_semantic_scope'] ?? '') === 'ctrip_checkout_daily'
        ))[0];
        $capacity = array_values(array_filter(
            $dataset['fact_ota_daily'],
            static fn(array $fact): bool => ($fact['metric_semantic_scope'] ?? '') === 'ctrip_capacity_daily'
        ))[0];

        self::assertSame(1, $dataset['data_quality']['superseded_ctrip_checkout_rows']);
        self::assertSame(2168.0, $checkout['room_revenue']);
        self::assertSame('verified_ctrip_checkout_sales', $checkout['room_revenue_basis']);
        self::assertSame(3.0, $checkout['room_nights']);
        self::assertSame(722.67, $checkout['adr']);
        self::assertNull($checkout['order_count']);
        self::assertNull($capacity['room_nights']);
        self::assertSame(2.0, $capacity['occupied_room_nights']);
        self::assertSame(1, $capacity['order_count']);
        self::assertSame(2168.0, $metrics['totals']['revenue']);
        self::assertSame(2168.0, $metrics['totals']['room_revenue']);
        self::assertSame(3.0, $metrics['totals']['room_nights']);
        self::assertNull($metrics['totals']['order_count']);
        self::assertSame(722.67, $metrics['totals']['adr']);
    }

    public function testCtripMarketOverviewBookingProjectionSurvivesCheckoutCanonicalizationWithoutPollutingAdr(): void
    {
        $traceId = 'ctrip:task3094:business-market-overview';
        $common = [
            'tenant_id' => 1,
            'system_hotel_id' => 80,
            'hotel_id' => 'ctrip-hotel-80',
            'hotel_name' => 'Hotel Ctrip Task 3094',
            'source' => 'ctrip',
            'platform' => 'ctrip',
            'data_type' => 'business',
            'data_date' => '2026-08-08',
            'data_period' => 'historical_daily',
            'is_final' => 1,
            'sync_task_id' => 3094,
            'readback_verified' => 1,
            'validation_status' => 'normal',
            'source_trace_id' => $traceId,
        ];
        $checkout = array_replace($common, [
            'id' => 81910,
            'amount' => 8468,
            'quantity' => 12,
            'book_order_num' => null,
            'raw_data' => json_encode([
                'source_trace_id' => $traceId,
                'row' => [
                    'endpoint_id' => 'business_market_overview',
                    'section' => 'business_overview',
                    'amount' => 8468,
                    'quantity' => 12,
                ],
                'field_facts' => [
                    [
                        'metric_key' => 'order_amount',
                        'storage_field' => 'online_daily_data.amount',
                        'source_key' => 'amount',
                        'source_path' => 'data.data.amount',
                        'status' => 'captured',
                        'stored_value_present' => true,
                    ],
                    [
                        'metric_key' => 'room_nights',
                        'storage_field' => 'online_daily_data.quantity',
                        'source_key' => 'quantity',
                        'source_path' => 'data.data.quantity',
                        'status' => 'captured',
                        'stored_value_present' => true,
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $bookingRaw = [
            'source_trace_id' => $traceId,
            'row' => [
                'endpoint_id' => 'business_market_overview',
                'section' => 'business_overview',
                'bookOrderNum' => 0,
            ],
            'field_facts' => [[
                'metric_key' => 'order_count',
                'data_type' => 'business',
                'source_key' => 'bookOrderNum',
                'source_path' => 'data.data.bookOrderNum',
                'storage_field' => 'online_daily_data.book_order_num',
                'normalized_field' => 'book_order_num',
                'status' => 'captured',
                'stored_value_present' => true,
                'semantic_contract_version' => 'ota_metric_semantic_binding.v1',
                'semantic_key' => 'ctrip_market_overview_booking_order_count',
                'unit' => 'orders',
                'value_type' => 'non_negative_integer',
                'source_endpoint_id' => 'business_market_overview',
                'capture_evidence' => ['source_trace_id' => $traceId],
            ]],
            'metric_projection' => [
                'contract_version' => 'ctrip_market_overview_metric_projection.v1',
                'metric_family' => 'booking',
                'metric_key' => 'order_count',
                'semantic_key' => 'ctrip_market_overview_booking_order_count',
                'unit' => 'orders',
                'source_endpoint_id' => 'business_market_overview',
                'source_key' => 'bookOrderNum',
                'source_path' => 'data.data.bookOrderNum',
                'business_date' => '2026-08-08',
                'separate_from_metric_family' => 'checkout',
            ],
        ];
        $booking = array_replace($common, [
            'id' => 81911,
            'dimension' => 'semantic:ctrip_business_market_overview:booking_order_count',
            'amount' => null,
            'quantity' => null,
            'book_order_num' => 0,
            'raw_data' => json_encode($bookingRaw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([$checkout, $booking]);
        $metrics = (new OtaRevenueMetricService())->summarizeDataset($dataset);
        $factsByScope = array_column($dataset['fact_ota_daily'], null, 'metric_semantic_scope');

        self::assertCount(2, $dataset['fact_ota_daily']);
        self::assertSame(0, $dataset['data_quality']['superseded_ctrip_checkout_rows']);
        self::assertSame(0, $dataset['data_quality']['superseded_period_rows']);
        $checkoutFact = $factsByScope['ctrip_checkout_daily'];
        self::assertSame(8468.0, $checkoutFact['revenue']);
        self::assertSame(8468.0, $checkoutFact['room_revenue']);
        self::assertSame(12.0, $checkoutFact['room_nights']);
        self::assertSame(705.67, $checkoutFact['adr']);
        self::assertNull($checkoutFact['order_count']);
        $bookingFact = $factsByScope['ctrip_market_overview_booking_daily'];
        self::assertSame(0, $bookingFact['order_count']);
        foreach ([
            'revenue', 'gross_revenue', 'room_revenue', 'net_revenue', 'settlement_amount',
            'commission_amount', 'commission_rate', 'room_nights', 'available_room_nights',
            'occupied_room_nights', 'adr', 'occ', 'revpar', 'net_revpar', 'lead_time_days',
            'comment_score', 'data_value', 'cancel_order_num', 'cancel_room_nights',
            'cancel_rate', 'our_price', 'competitor_price', 'price_gap', 'price_gap_rate',
        ] as $metricKey) {
            self::assertNull($bookingFact[$metricKey], $metricKey);
        }
        self::assertSame(8468.0, $metrics['totals']['revenue']);
        self::assertSame(8468.0, $metrics['totals']['room_revenue']);
        self::assertSame(12.0, $metrics['totals']['room_nights']);
        self::assertSame(705.67, $metrics['totals']['adr']);
        self::assertSame(0, $metrics['totals']['order_count']);

        $tamperedRaw = $bookingRaw;
        $tamperedRaw['metric_projection']['semantic_key'] = 'tampered_booking_semantic';
        $tamperedBooking = array_replace($booking, [
            'raw_data' => json_encode($tamperedRaw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $tamperedDataset = (new OtaStandardEtlService())->buildDatasetFromRows([
            $checkout,
            $tamperedBooking,
        ]);
        $tamperedMetrics = (new OtaRevenueMetricService())->summarizeDataset($tamperedDataset);

        self::assertCount(1, $tamperedDataset['fact_ota_daily']);
        self::assertSame(1, $tamperedDataset['data_quality']['superseded_ctrip_checkout_rows']);
        self::assertSame(
            'ctrip_checkout_daily',
            $tamperedDataset['fact_ota_daily'][0]['metric_semantic_scope']
        );
        self::assertNull($tamperedDataset['fact_ota_daily'][0]['order_count']);
        self::assertNull($tamperedMetrics['totals']['order_count']);
    }

    public function testCtripBookingFieldsAloneCannotUnlockCheckoutRevenue(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([[
            'id' => 68703,
            'system_hotel_id' => 80,
            'hotel_id' => 'ctrip-hotel-80',
            'hotel_name' => 'Hotel Ctrip Booking Only',
            'source' => 'ctrip',
            'data_type' => 'business',
            'data_date' => '2026-07-29',
            'amount' => 3322,
            'quantity' => 4,
            'book_order_num' => 2,
            'source_trace_id' => 'ctrip:booking-only',
            'readback_verified' => 1,
            'raw_data' => json_encode([
                'row' => [
                    'endpoint_id' => 'business_market_overview',
                    'bookAmount' => 3322,
                    'bookQuantity' => 4,
                    'bookOrderNum' => 2,
                ],
                'field_facts' => [
                    [
                        'metric_key' => 'order_amount',
                        'storage_field' => 'online_daily_data.amount',
                        'source_key' => 'bookAmount',
                        'source_path' => '$.data.bookAmount',
                        'status' => 'captured',
                        'stored_value_present' => true,
                    ],
                    [
                        'metric_key' => 'room_nights',
                        'storage_field' => 'online_daily_data.quantity',
                        'source_key' => 'bookQuantity',
                        'source_path' => '$.data.bookQuantity',
                        'status' => 'captured',
                        'stored_value_present' => true,
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]]);

        $daily = $dataset['fact_ota_daily'][0];
        self::assertSame('ctrip_booking_or_unverified_excluded', $daily['metric_semantic_scope']);
        self::assertNull($daily['gross_revenue']);
        self::assertNull($daily['room_revenue']);
        self::assertNull($daily['room_nights']);
        self::assertNull($daily['order_count']);
        self::assertNull($daily['adr']);
    }

    public function testMeituanOrderAmountWithoutExactSalePriceEvidenceStaysGenericRevenue(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDatasetFromRows([[
            'id' => 46,
            'system_hotel_id' => 80,
            'hotel_id' => 'meituan-hotel-80',
            'hotel_name' => 'Hotel Meituan Generic Amount',
            'source' => 'meituan',
            'data_type' => 'order',
            'data_date' => '2026-07-29',
            'amount' => 5501.8,
            'quantity' => 6,
            'book_order_num' => 3,
            'compare_type' => 'self',
            'source_trace_id' => 'trace-meituan-generic-46',
            'readback_verified' => 1,
            'update_time' => '2026-07-30 02:10:58',
            'raw_data' => json_encode([
                'row' => [
                    'amount' => 5501.8,
                    'quantity' => 6,
                    'room_nights' => 6,
                    'compare_type' => 'self',
                    'is_self' => true,
                    'pagination_complete' => true,
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]]);

        $daily = $dataset['fact_ota_daily'][0];
        self::assertSame(5501.8, $daily['gross_revenue']);
        self::assertNull($daily['room_revenue']);
        self::assertNull($daily['room_revenue_basis']);
        self::assertNull($daily['adr']);
    }

    public function testP1ClosureCannotBeReadyWhenAChildMetricIsNotCalculable(): void
    {
        $metrics = (new OtaRevenueMetricService())->summarizeDataset([
            'status' => 'ready',
            'data_quality' => [
                'input_rows' => 2,
                'accepted_rows' => 2,
                'rejected_rows' => [],
            ],
            'fact_ota_daily' => [[
                'platform_key' => 'ctrip',
                'hotel_key' => 'system:7',
                'data_type' => 'business',
                'revenue' => 1200.0,
                'gross_revenue' => 1200.0,
                'room_revenue' => 1200.0,
                'net_revenue' => 1020.0,
                'commission_amount' => 180.0,
                'room_nights' => 6.0,
                'available_room_nights' => 10.0,
                'occupied_room_nights' => 6.0,
                'order_count' => 4,
                'cancel_order_num' => 0,
                'cancel_room_nights' => 0,
                'lead_time_days' => 2,
                'our_price' => 200.0,
                'competitor_price' => 210.0,
                'price_gap' => -10.0,
                'price_gap_rate' => -4.76,
                'source_trace' => $this->trace(9051, 'ctrip', 'business', '2026-06-25'),
            ]],
            'fact_ota_traffic' => [[
                'platform_key' => 'ctrip',
                'hotel_key' => 'system:7',
                'flow_rate' => null,
                'submit_rate' => 25.0,
                'source_trace' => $this->trace(9052, 'ctrip', 'traffic', '2026-06-25'),
            ]],
        ]);

        self::assertSame('not_calculable', $metrics['p1_revenue_closure']['sections']['adr_conversion']['metrics']['flow_rate']['status']);
        self::assertSame('partial', $metrics['p1_revenue_closure']['sections']['adr_conversion']['status']);
        self::assertContains(
            'traffic.avg_flow_rate:metric_value_missing',
            array_column($metrics['p1_revenue_closure']['missing_items']['items'], 'code')
        );
        self::assertSame('warning', $metrics['p1_revenue_closure']['status']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sampleRows(): array
    {
        return [
            [
                'id' => 1,
                'system_hotel_id' => 7,
                'hotel_id' => 'ctrip-7',
                'hotel_name' => 'Hotel Alpha',
                'source' => 'ctrip',
                'data_type' => 'business',
                'data_date' => '2026-05-18',
                'source_trace_id' => 'trace-business-1',
                'readback_verified' => 1,
                'collected_at' => '2026-05-18 09:55:00',
                'update_time' => '2026-05-18 10:00:00',
                'amount' => 1200,
                'room_revenue' => 1200,
                'quantity' => 6,
                'book_order_num' => 4,
                'comment_score' => 4.8,
                'raw_data' => json_encode([
                    'cancel_order_num' => 1,
                    'gross_order_num' => 4,
                    'unknown_status_order_num' => 0,
                    'cancel_rate_basis' =>
                        'cancelled_orders_over_gross_orders_complete_classification',
                    'cancel_room_nights' => 1,
                    'our_price' => 200,
                    'competitor_price' => 220,
                    'available_rooms' => 10,
                    'occupied_rooms' => 6,
                    'commission_rate' => 0.15,
                    'booking_date' => '2026-05-10',
                    'checkin_date' => '2026-05-18',
                ], JSON_UNESCAPED_UNICODE),
            ],
            [
                'id' => 2,
                'system_hotel_id' => 7,
                'hotel_id' => 'ctrip-7',
                'hotel_name' => 'Hotel Alpha',
                'source' => 'ctrip',
                'data_type' => 'traffic',
                'data_date' => '2026-05-18',
                'source_trace_id' => 'trace-traffic-2',
                'readback_verified' => 1,
                'collected_at' => '2026-05-18 10:04:00',
                'update_time' => '2026-05-18 10:05:00',
                'list_exposure' => 1000,
                'detail_exposure' => 200,
                'flow_rate' => 20,
                'order_filling_num' => 30,
                'order_submit_num' => 10,
                'raw_data' => '{}',
            ],
            [
                'id' => 3,
                'system_hotel_id' => 8,
                'hotel_id' => 'poi-8',
                'hotel_name' => 'Hotel Beta',
                'source' => 'meituan',
                'data_type' => 'review',
                'data_date' => '2026-05-18',
                'source_trace_id' => 'trace-review-3',
                'readback_verified' => 1,
                'dimension' => 'review:meituan',
                'comment_score' => 3.0,
                'quantity' => 1,
                'data_value' => 1.0,
                'raw_data' => json_encode([
                    'channel' => 'meituan',
                    'comment_score' => 3.0,
                    'comment_count' => 1,
                    'bad_review_count' => 1,
                ], JSON_UNESCAPED_UNICODE),
            ],
        ];
    }

    /** @param array<int, array<string, mixed>> $rows @return array<int, array<string, mixed>> */
    private function trustedRows(array $rows): array
    {
        return array_map(static function (array $row): array {
            $row['readback_verified'] = 1;
            $row['collected_at'] ??= $row['snapshot_time']
                ?? $row['update_time']
                ?? $row['create_time']
                ?? null;
            return $row;
        }, $rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function trace(int $rowId, string $platform, string $dataType, string $date): array
    {
        return [
            'table' => 'online_daily_data',
            'row_id' => $rowId,
            'source_trace_id' => $platform . ':' . $rowId . ':' . $date,
            'data_source_id' => $rowId,
            'sync_task_id' => 90000 + $rowId,
            'hotel_key' => 'system:7',
            'system_hotel_id' => 7,
            'platform_hotel_id' => $platform . '-hotel-7',
            'platform' => $platform,
            'data_type' => $dataType,
            'date_key' => $date,
            'ingestion_method' => 'browser_profile',
            'collected_at' => $date . ' 09:55:00',
            'updated_at' => $date . ' 10:00:00',
            'data_period' => 'historical_daily',
            'is_final' => true,
            'stored' => true,
            'readback_verified' => true,
            'saved_success' => true,
            'failure_reasons' => [],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $metrics
     * @return array<string, mixed>
     */
    private function channelMetric(array $metrics, string $resource, string $metricKey): array
    {
        foreach ($metrics as $metric) {
            if (($metric['resource'] ?? '') === $resource && ($metric['metric_key'] ?? '') === $metricKey) {
                return $metric;
            }
        }

        self::fail("Missing channel metric {$resource}.{$metricKey}");
    }
}
