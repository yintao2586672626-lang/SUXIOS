<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperationManagementService;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;

final class OperationManagementServiceTest extends TestCase
{
    public function testMissingTrustedFunnelIsNullInsteadOfARealZero(): void
    {
        $service = new OperationManagementService();
        $summary = $this->invokeNonPublic($service, 'buildOtaFromRows', [[]]);

        self::assertNull($summary['exposure']);
        self::assertNull($summary['visitors']);
        self::assertSame('missing', $summary['funnel_status']);
        self::assertSame(['exposure', 'visitors'], $summary['missing_metrics']);
        self::assertNotSame('ok', $summary['data_status']);
    }

    use ReflectionHelper;

    public function testEffectValidationSummaryCalculatesProductLevelClosedLoopMetrics(): void
    {
        $service = new OperationManagementService();

        $summary = $this->invokeNonPublic($service, 'buildEffectValidationSummary', [
            [
                [
                    'action_type' => 'price_adjust',
                    'before' => ['data_status' => 'ok', 'avg_revenue' => 1000, 'avg_conversion' => 10],
                    'after' => ['data_status' => 'ok', 'avg_revenue' => 1200, 'avg_conversion' => 12],
                    'result' => ['status' => 'success'],
                ],
                [
                    'action_type' => 'price_adjust',
                    'before' => ['data_status' => 'ok', 'avg_revenue' => 1000, 'avg_conversion' => 9],
                    'after' => ['data_status' => 'ok', 'avg_revenue' => 1050, 'avg_conversion' => 9],
                    'result' => ['status' => 'near_success'],
                ],
                [
                    'action_type' => 'promotion',
                    'before' => ['data_status' => 'ok', 'avg_revenue' => 500, 'avg_conversion' => 8],
                    'after' => ['data_status' => 'ok', 'avg_revenue' => 450, 'avg_conversion' => 7],
                    'result' => ['status' => 'failed'],
                ],
                [
                    'action_type' => 'room_inventory',
                    'before' => ['data_status' => 'ok', 'avg_revenue' => 300, 'avg_conversion' => 5],
                    'after' => ['data_status' => '待接入真实数据'],
                    'result' => ['status' => 'observing'],
                ],
            ],
            ['total' => 5, 'adopted' => 3, 'data_status' => 'ok'],
            ['reviewed' => 4, 'accurate' => 3, 'data_status' => 'ok'],
            [],
        ]);

        self::assertSame('ready', $summary['status']);
        self::assertSame(4, $summary['action_counts']['total']);
        self::assertSame(3, $summary['action_counts']['reviewed']);
        self::assertSame(1, $summary['action_counts']['observing']);

        self::assertSame(8.0, $this->metricValue($summary, 'revenue_lift_rate'));
        self::assertSame(3.7, $this->metricValue($summary, 'conversion_lift_rate'));
        self::assertSame(100.0, $this->metricValue($summary, 'pricing_hit_rate'));
        self::assertSame(60.0, $this->metricValue($summary, 'suggestion_adoption_rate'));
        self::assertSame(75.0, $this->metricValue($summary, 'alert_accuracy_rate'));
    }

    public function testEffectValidationSummaryMarksUnavailableMetricsInsteadOfInventingValues(): void
    {
        $service = new OperationManagementService();

        $summary = $this->invokeNonPublic($service, 'buildEffectValidationSummary', [
            [],
            ['total' => 0, 'adopted' => 0, 'data_status' => 'empty'],
            ['reviewed' => 0, 'accurate' => 0, 'data_status' => 'unlabeled'],
            [['code' => 'operation_alerts_accuracy_label_missing', 'message' => '预警缺少准确/误报复盘标签']],
        ]);

        self::assertSame('data_gap', $summary['status']);
        self::assertNull($this->metricValue($summary, 'revenue_lift_rate'));
        self::assertNull($this->metricValue($summary, 'alert_accuracy_rate'));
        self::assertContains('operation_alerts_accuracy_label_missing', array_column($summary['data_gaps'], 'code'));
    }

    public function testExecutionIntentKeepsOtaDiagnosisEvidenceForDataCollectionAction(): void
    {
        $service = new OperationManagementService();

        $payload = $service->buildExecutionIntentPayload([1], 1, [
            'hotel_id' => 1,
            'platform' => 'ctrip',
            'source_module' => 'ota_diagnosis',
            'source_record_id' => 0,
            'object_type' => 'data_collection',
            'action_type' => 'collect_same_period_ota_data',
            'date_start' => '2026-06-12',
            'date_end' => '2026-06-12',
            'target_value' => [
                'collection_scope' => 'same_day_ota_channel',
                'target_date' => '2026-06-12',
            ],
            'evidence_refs' => ['ota_no_data_scope'],
            'data_gaps' => [[
                'code' => 'ota_same_period_source_rows_missing',
                'message' => '选定日期范围没有可用于 OTA 经营诊断的真实入库数据。',
            ]],
            'source_policy' => 'database_only_no_synthetic_conclusion',
            'protected_boundary' => '不改变采集字段、字段映射、携程/美团手动或自动获取逻辑。',
        ], 9);

        self::assertSame('pending_approval', $payload['status']);
        self::assertSame('', $payload['blocked_reason']);
        self::assertSame('data_collection', $payload['object_type']);
        self::assertSame(['ota_no_data_scope'], $payload['evidence']['evidence_refs']);
        self::assertSame('ota_same_period_source_rows_missing', $payload['evidence']['data_gaps'][0]['code']);
        self::assertSame('database_only_no_synthetic_conclusion', $payload['evidence']['source_policy']);
        self::assertStringContainsString('不改变采集字段', $payload['evidence']['protected_boundary']);
    }

    public function testExecutionIntentBlocksDataCollectionWithoutOtaEvidence(): void
    {
        $service = new OperationManagementService();

        $payload = $service->buildExecutionIntentPayload([1], 1, [
            'hotel_id' => 1,
            'platform' => 'ctrip',
            'object_type' => 'data_collection',
            'action_type' => 'collect_same_period_ota_data',
            'target_value' => [
                'collection_scope' => 'same_day_ota_channel',
            ],
        ], 9);

        self::assertSame('blocked', $payload['status']);
        self::assertStringContainsString('evidence missing', $payload['blocked_reason']);
        self::assertStringContainsString('ota evidence refs or data_gaps missing', $payload['blocked_reason']);
    }

    public function testDailyFinancialExtractorsUseFallbackFieldsWithoutInventingValues(): void
    {
        $service = new OperationManagementService();
        $reportData = [
            'xb_revenue' => '1,200',
            'mt_revenue' => 800,
            'parking_revenue' => 50,
            'xb_rooms' => 4,
            'mt_rooms' => 3,
            'salable_rooms' => 20,
        ];

        self::assertSame(2050.0, $this->invokeNonPublic($service, 'extractRevenue', [[], $reportData]));
        self::assertSame(7.0, $this->invokeNonPublic($service, 'extractRoomNights', [[], $reportData]));
        self::assertSame(20.0, $this->invokeNonPublic($service, 'extractSalableRoomCount', [[], $reportData]));
        self::assertSame(0.0, $this->invokeNonPublic($service, 'extractRevenue', [[], ['xb_revenue' => 'bad']]));
    }

    public function testDashboardSummaryAggregatesDailyAndOnlineRowsWithoutDoubleCountingRevenue(): void
    {
        $service = new OperationManagementService();

        $summary = $this->invokeNonPublic($service, 'buildSummaryFromRows', [
            [[
                'hotel_id' => 7,
                'report_date' => '2026-05-18',
                'report_data' => json_encode([
                    'xb_revenue' => '1,200',
                    'mt_revenue' => 300,
                    'xb_rooms' => 4,
                    'mt_rooms' => 1,
                    'salable_rooms' => 10,
                ], JSON_UNESCAPED_UNICODE),
            ]],
            [[
                'system_hotel_id' => 7,
                'data_date' => '2026-05-18',
                'source' => 'ctrip',
                'platform' => 'ctrip',
                'amount' => 999,
                'quantity' => 9,
                'book_order_num' => 8,
                'raw_data' => json_encode(['bookOrderNum' => 9], JSON_UNESCAPED_UNICODE),
            ]],
            [7],
            7,
            '2026-05-18',
        ]);

        self::assertSame(1500.0, $summary['revenue']);
        self::assertSame(5.0, $summary['room_nights']);
        self::assertSame(9, $summary['orders']);
        self::assertSame(300.0, $summary['adr']);
        self::assertSame(50.0, $summary['occ']);
        self::assertSame(150.0, $summary['revpar']);
        self::assertSame('ok', $summary['data_status']);
        self::assertSame('mixed_whole_hotel_and_ota_channel', $summary['source_scope']);
        self::assertSame(['whole_hotel_daily_report'], $summary['metric_scopes']['revenue']);
        self::assertSame(['ota_channel'], $summary['metric_scopes']['orders']);
    }

    public function testDashboardSummaryKeepsMissingMetricsNullAndReportsGaps(): void
    {
        $service = new OperationManagementService();

        $summary = $this->invokeNonPublic($service, 'buildSummaryFromRows', [
            [[
                'id' => 5,
                'hotel_id' => 7,
                'report_date' => '2026-07-15',
                'revenue' => 0,
                'report_data' => '{}',
            ]],
            [],
            [7],
            7,
            '2026-07-15',
        ]);

        self::assertSame(0.0, $summary['revenue'], 'An explicitly recorded zero must remain a real zero.');
        self::assertNull($summary['orders']);
        self::assertNull($summary['room_nights']);
        self::assertNull($summary['adr']);
        self::assertSame('partial', $summary['data_status']);
        self::assertContains('operation_orders_missing', array_column($summary['data_gaps'], 'code'));
        self::assertContains('operation_room_nights_missing', array_column($summary['data_gaps'], 'code'));
    }

    public function testDashboardSummaryAcceptsExplicitZeroesOnlyWhenEveryRequiredMetricAndSourceExist(): void
    {
        $service = new OperationManagementService();

        $summary = $this->invokeNonPublic($service, 'buildSummaryFromRows', [
            [],
            [[
                'id' => 6,
                'system_hotel_id' => 7,
                'data_date' => '2026-07-15',
                'source' => 'ctrip',
                'data_type' => 'business',
                'dimension' => '',
                'amount' => 0,
                'quantity' => 0,
                'book_order_num' => 0,
                'raw_data' => '{}',
            ]],
            [7],
            7,
            '2026-07-15',
        ]);

        self::assertSame(0.0, $summary['revenue']);
        self::assertSame(0, $summary['orders']);
        self::assertSame(0.0, $summary['room_nights']);
        self::assertNull($summary['adr'], 'A zero denominator must not produce a fake ADR zero.');
        self::assertSame('ok', $summary['data_status']);
        self::assertSame([], $summary['data_gaps']);
    }

    public function testDashboardSummaryMarksUnknownOnlineSourcePartial(): void
    {
        $service = new OperationManagementService();

        $summary = $this->invokeNonPublic($service, 'buildSummaryFromRows', [
            [],
            [[
                'id' => 7,
                'system_hotel_id' => 7,
                'data_date' => '2026-07-15',
                'data_type' => 'business',
                'dimension' => '',
                'amount' => 100,
                'quantity' => 1,
                'book_order_num' => 1,
                'raw_data' => '{}',
            ]],
            [7],
            7,
            '2026-07-15',
        ]);

        self::assertSame('partial', $summary['data_status']);
        self::assertSame('partial', $summary['source_status']);
        self::assertContains('operation_source_missing', array_column($summary['data_gaps'], 'code'));
    }

    public function testDashboardSummaryExcludesCompetitorFactsAndDuplicateBusinessSnapshots(): void
    {
        $service = new OperationManagementService();

        $summary = $this->invokeNonPublic($service, 'buildSummaryFromRows', [
            [],
            [
                [
                    'id' => 17652,
                    'system_hotel_id' => 80,
                    'data_date' => '2026-07-15',
                    'source' => 'ctrip',
                    'data_type' => 'business',
                    'dimension' => '',
                    'validation_status' => 'normal',
                    'update_time' => '2026-07-15 09:15:46',
                    'amount' => 5939,
                    'quantity' => 7,
                    'book_order_num' => 11,
                    'raw_data' => '{}',
                ],
                [
                    'id' => 34952,
                    'system_hotel_id' => 80,
                    'data_date' => '2026-07-15',
                    'source' => 'ctrip',
                    'data_type' => 'business',
                    'dimension' => 'catalog:business_overview:business_flow_compete:order_count',
                    'validation_status' => 'normal',
                    'update_time' => '2026-07-15 09:13:33',
                    'amount' => 377223.9,
                    'quantity' => 0,
                    'book_order_num' => 288,
                    'raw_data' => '{}',
                ],
                [
                    'id' => 17670,
                    'system_hotel_id' => 80,
                    'data_date' => '2026-07-15',
                    'source' => 'ctrip',
                    'data_type' => 'business',
                    'dimension' => 'catalog:business_overview:business_realtime:visitor_count+order_count',
                    'validation_status' => 'normal',
                    'update_time' => '2026-07-15 09:16:00',
                    'amount' => 0,
                    'quantity' => 0,
                    'book_order_num' => 6,
                    'raw_data' => '{}',
                ],
            ],
            [80],
            80,
            '2026-07-15',
        ]);

        self::assertSame(5939.0, $summary['revenue']);
        self::assertSame(7.0, $summary['room_nights']);
        self::assertSame(11, $summary['orders']);
        self::assertSame(848.43, $summary['adr']);
        self::assertNull($summary['occ']);
        self::assertNull($summary['revpar']);
        self::assertSame('ok', $summary['data_status']);
    }

    public function testCompetitorTrafficNeverBecomesSelfOtaFunnelEvidence(): void
    {
        $service = new OperationManagementService();
        $today = date('Y-m-d');
        $selfBusiness = [
            'id' => 17652,
            'system_hotel_id' => 80,
            'hotel_id' => 130079194,
            'data_date' => $today,
            'source' => 'ctrip',
            'platform' => 'ctrip',
            'compare_type' => '',
            'data_type' => 'business',
            'dimension' => '',
            'validation_status' => 'normal',
            'ingestion_method' => 'browser_profile',
            'data_period' => 'realtime_snapshot',
            'is_final' => 0,
            'update_time' => '2026-07-15 09:15:46',
            'amount' => 5939,
            'quantity' => 7,
            'book_order_num' => 11,
            'raw_data' => '{}',
        ];
        $competitorTraffic = [
            'id' => 43491,
            'system_hotel_id' => 80,
            'hotel_id' => -1,
            'data_date' => $today,
            'source' => 'ctrip',
            'platform' => 'Qunar',
            'compare_type' => 'competitor_avg',
            'data_type' => 'traffic',
            'dimension' => 'catalog:traffic_report:traffic_flow_transform:list_exposure+competitor_list_exposure+detail_visitor:50.listExposure',
            'validation_status' => 'normal',
            'ingestion_method' => 'browser_profile',
            'data_period' => 'realtime_snapshot',
            'is_final' => 0,
            'update_time' => '2026-07-15 09:15:46',
            'list_exposure' => 268,
            'detail_exposure' => 48,
            'order_filling_num' => 3,
            'order_submit_num' => 2,
            'raw_data' => '{}',
        ];

        $ota = $this->invokeNonPublic($service, 'buildOtaFromRows', [[$selfBusiness, $competitorTraffic]]);
        self::assertNull($ota['exposure']);
        self::assertNull($ota['visitors']);
        self::assertSame('missing', $ota['funnel_status']);
        self::assertSame(['exposure', 'visitors'], $ota['missing_metrics']);
        self::assertNotContains('online_daily_data#43491', array_column($ota['evidence_refs'], 'source_ref'));

        $summary = $this->invokeNonPublic($service, 'buildSummaryFromRows', [
            [],
            [$selfBusiness, $competitorTraffic],
            [80],
            80,
            $today,
        ]);
        self::assertSame(5939.0, $summary['revenue']);
        self::assertSame(11, $summary['orders']);
        self::assertSame(7.0, $summary['room_nights']);
        self::assertSame(['online_daily_data#17652'], array_column($summary['evidence_refs'], 'source_ref'));
        self::assertSame('ctrip', $summary['evidence_refs'][0]['platform']);
        self::assertNotContains('online_daily_data#43491', array_column($summary['evidence_refs'], 'source_ref'));
    }

    public function testOperatingSnapshotChannelUsesPlatformWhenSourceIsEmpty(): void
    {
        $service = new OperationManagementService();

        $channel = $this->invokeNonPublic($service, 'operatingSnapshotChannel', [[
            'evidence_refs' => [[
                'source' => '',
                'platform' => 'Qunar',
            ]],
        ]]);

        self::assertSame('qunar', $channel);
    }

    public function testRootCauseRulesFlagDataTrafficPriceServiceQualityAndHolidayBoundaries(): void
    {
        $service = new OperationManagementService();

        $result = $this->invokeNonPublic($service, 'buildRootCauseResult', [[
            'ota' => ['orders' => 5, 'exposure' => 0, 'visitors' => 0, 'view_rate' => 2, 'order_rate' => 1, 'data_status' => 'ok'],
            'summary' => ['adr' => 330, 'data_status' => 'ok'],
            'competitors' => ['avg_price' => 250, 'avg_score' => 4.8, 'data_status' => 'ok'],
            'service_quality' => ['avg_psi_score' => 76.5, 'avg_service_score' => 79.0, 'data_status' => 'ok'],
            'holiday' => ['days_left' => 7, 'data_status' => 'ok'],
        ], ['exposure' => 100, 'data_status' => 'ok'], ['view_rate' => 20, 'order_rate' => 10, 'data_status' => 'ok'], 'conversion_low']);

        self::assertSame('high', $result['problem_level']);
        self::assertSame('data_abnormal', $result['root_causes'][0]['type']);
        self::assertContains('traffic_down', array_column($result['root_causes'], 'type'));
        self::assertContains('price_high', array_column($result['root_causes'], 'type'));
        self::assertContains('service_quality_low', array_column($result['root_causes'], 'type'));
        self::assertNotContains('score_low', array_column($result['root_causes'], 'type'));
        self::assertContains('holiday_near', array_column($result['root_causes'], 'type'));
        self::assertSame($result['candidate_factors'], $result['root_causes']);
        self::assertSame($result['candidate_factors'][0]['rule_match_weight'], $result['candidate_factors'][0]['confidence']);
        self::assertStringContainsString('不是统计置信度', $result['candidate_factors'][0]['confidence_basis']);

        $empty = $this->invokeNonPublic($service, 'buildRootCauseResult', [[
            'ota' => [],
            'summary' => [],
            'competitors' => [],
            'service_quality' => [],
            'holiday' => [],
        ], [], [], '']);

        self::assertSame('data_insufficient', $empty['problem_level']);
        self::assertSame('unknown', $empty['main_problem']);
        self::assertSame([], $empty['root_causes']);
        self::assertStringNotContainsString('点评', implode(' ', $empty['next_actions']));
    }

    public function testStrategySimulationContractNamesRuleScenarioAndTreatsUnknownRiskAsUnassessed(): void
    {
        $method = new \ReflectionMethod(OperationManagementService::class, 'strategySimulation');
        $lines = file($method->getFileName()) ?: [];
        $source = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        self::assertStringContainsString("'rule_scenario'", $source);
        self::assertStringContainsString('forecast 为兼容旧客户端保留', $source);
        self::assertStringContainsString("'level' => 'unknown'", $source);
        self::assertStringNotContainsString('规则估算风险较低', $source);
        self::assertStringContainsString('不是经营预测', $source);
    }

    public function testServiceQualitySummaryUsesCapturedQualityRows(): void
    {
        $service = new OperationManagementService();

        $summary = $this->invokeNonPublic($service, 'buildServiceQualityFromRows', [[
            [
                'data_type' => 'quality',
                'data_value' => 88.6,
                'raw_data' => json_encode(['serviceScore' => 92.5, 'psiScore' => 88.6], JSON_UNESCAPED_UNICODE),
            ],
            [
                'data_type' => 'service_quality',
                'raw_data' => json_encode(['service_score' => 86, 'psi_score' => 82.2], JSON_UNESCAPED_UNICODE),
            ],
            [
                'data_type' => 'traffic',
                'raw_data' => json_encode(['psiScore' => 10, 'serviceScore' => 10], JSON_UNESCAPED_UNICODE),
            ],
        ]]);

        self::assertSame(85.4, $summary['avg_psi_score']);
        self::assertSame(89.25, $summary['avg_service_score']);
        self::assertSame(2, $summary['sample_count']);
        self::assertSame('ok', $summary['data_status']);
    }

    public function testMeituanRankBatchChangesDetectTopSelfRankAndVipSignals(): void
    {
        $service = new OperationManagementService();
        $targetPoiId = 'self-poi';

        $currentHotels = $this->invokeNonPublic($service, 'buildMeituanRankHotels', [[
            [
                'data_date' => '2026-06-06',
                'update_time' => '2026-06-06 09:00:00',
                'raw_data' => json_encode(['poiId' => 'top-new', 'poiName' => 'New Top Hotel', 'rank' => 1, 'platformTags' => ['VIP'], 'hasVipTag' => true], JSON_UNESCAPED_UNICODE),
            ],
            [
                'data_date' => '2026-06-06',
                'update_time' => '2026-06-06 09:00:00',
                'raw_data' => json_encode(['poiId' => $targetPoiId, 'poiName' => 'Self Hotel', 'rank' => 4, 'platformTags' => ['regular']], JSON_UNESCAPED_UNICODE),
            ],
        ], $targetPoiId]);
        $previousHotels = $this->invokeNonPublic($service, 'buildMeituanRankHotels', [[
            [
                'data_date' => '2026-06-05',
                'update_time' => '2026-06-05 09:00:00',
                'raw_data' => json_encode(['poiId' => 'top-old', 'poiName' => 'Old Top Hotel', 'rank' => 1, 'platformTags' => ['regular']], JSON_UNESCAPED_UNICODE),
            ],
            [
                'data_date' => '2026-06-05',
                'update_time' => '2026-06-05 09:00:00',
                'raw_data' => json_encode(['poiId' => $targetPoiId, 'poiName' => 'Self Hotel', 'rank' => 2, 'platformTags' => ['regular']], JSON_UNESCAPED_UNICODE),
            ],
        ], $targetPoiId]);

        $current = $this->invokeNonPublic($service, 'summarizeMeituanRankBatchSnapshot', [$currentHotels, '2026-06-06', '2026-06-06 09:00:00', 2]);
        $previous = $this->invokeNonPublic($service, 'summarizeMeituanRankBatchSnapshot', [$previousHotels, '2026-06-05', '2026-06-05 09:00:00', 2]);
        $changes = $this->invokeNonPublic($service, 'summarizeMeituanRankBatchChanges', [$current, $previous]);

        self::assertSame('changed', $changes['status']);
        $types = array_column($changes['alerts'], 'type');
        self::assertContains('top1_changed', $types);
        self::assertContains('self_rank_changed', $types);
        self::assertContains('vip_count_changed', $types);
    }

    public function testMeituanRankBatchChangesKeepMissingEvidenceExplicit(): void
    {
        $service = new OperationManagementService();
        $targetPoiId = 'self-poi';

        $currentHotels = $this->invokeNonPublic($service, 'buildMeituanRankHotels', [[
            ['data_date' => '2026-06-06', 'raw_data' => json_encode(['poiId' => $targetPoiId, 'poiName' => 'Self Hotel'], JSON_UNESCAPED_UNICODE)],
        ], $targetPoiId]);
        $previousHotels = $this->invokeNonPublic($service, 'buildMeituanRankHotels', [[
            ['data_date' => '2026-06-05', 'raw_data' => json_encode(['poiId' => $targetPoiId, 'poiName' => 'Self Hotel'], JSON_UNESCAPED_UNICODE)],
        ], $targetPoiId]);

        $current = $this->invokeNonPublic($service, 'summarizeMeituanRankBatchSnapshot', [$currentHotels, '2026-06-06', '', 1]);
        $previous = $this->invokeNonPublic($service, 'summarizeMeituanRankBatchSnapshot', [$previousHotels, '2026-06-05', '', 1]);
        $changes = $this->invokeNonPublic($service, 'summarizeMeituanRankBatchChanges', [$current, $previous]);

        self::assertSame('missing', $changes['status']);
        self::assertSame([], $changes['alerts']);
        self::assertStringContainsString('no VIP inference', $changes['missing_reason']);
        self::assertStringContainsString('rank fields are not comparable', $changes['missing_reason']);
    }

    public function testExecutionIntentRejectsNestedReusableCredentialMaterial(): void
    {
        $service = new OperationManagementService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reusable credential material');

        $service->buildExecutionIntentPayload([7], 7, [
            'hotel_id' => 7,
            'platform' => 'meituan',
            'object_type' => 'data_collection',
            'action_type' => 'collect_ota_data',
            'target_value' => ['collection_scope' => 'ota_channel'],
            'evidence' => [
                'evidence_refs' => ['operator_review'],
                'nested' => ['authorization' => 'Bearer reusable-secret'],
            ],
        ], 9);
    }

    public function testExecutionIntentAllowsCurrencyAndOpaqueBusinessIds(): void
    {
        $service = new OperationManagementService();
        $businessId = '5026028568383187252';

        $payload = $service->buildExecutionIntentPayload([7], 7, [
            'hotel_id' => 7,
            'platform' => 'meituan',
            'object_type' => 'data_collection',
            'action_type' => 'collect_ota_data',
            'current_value' => [
                'currency' => 'CNY',
                'external_order_id' => $businessId,
                'cookiePricesDisplayed' => 'CNY',
            ],
            'target_value' => ['collection_scope' => 'ota_channel'],
            'evidence' => ['evidence_refs' => ['operator_review']],
        ], 9);

        self::assertSame('CNY', $payload['current_value']['currency']);
        self::assertSame($businessId, $payload['current_value']['external_order_id']);
        self::assertSame('CNY', $payload['current_value']['cookiePricesDisplayed']);
    }

    public function testExecutionTaskUpdateRejectsNestedEvidenceCredentialMaterial(): void
    {
        $service = new OperationManagementService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reusable credential material');

        $service->buildExecutionTaskUpdate(
            ['id' => 81],
            ['status' => 'approved'],
            [
                'status' => 'executed',
                'evidence' => [
                    'after' => ['auth_data' => ['token' => 'reusable-secret']],
                ],
            ],
            9
        );
    }

    public function testLegacyExecutionRowsRedactCredentialsWithoutAlteringCurrencyOrIds(): void
    {
        $service = new OperationManagementService();
        $businessId = '5026028568383187252';

        $intent = $this->invokeNonPublic($service, 'normalizeExecutionIntentRow', [[
            'id' => 1,
            'hotel_id' => 7,
            'source_record_id' => 9,
            'expected_delta' => 1,
            'current_value_json' => json_encode([
                'currency' => 'CNY',
                'external_order_id' => $businessId,
                'cookiePricesDisplayed' => 'CNY',
            ], JSON_UNESCAPED_UNICODE),
            'target_value_json' => json_encode(['nested' => ['cookies' => 'sid=legacy-secret']], JSON_UNESCAPED_UNICODE),
            'evidence_json' => json_encode(['note' => 'Authorization: Bearer legacy-auth'], JSON_UNESCAPED_UNICODE),
        ]]);
        $task = $this->invokeNonPublic($service, 'normalizeExecutionTaskRow', [[
            'id' => 2,
            'intent_id' => 1,
            'hotel_id' => 7,
            'current_value_json' => json_encode(['token' => 'legacy-token'], JSON_UNESCAPED_UNICODE),
            'target_value_json' => json_encode(['currency' => 'CNY', 'external_order_id' => $businessId], JSON_UNESCAPED_UNICODE),
        ]]);
        $evidence = $this->invokeNonPublic($service, 'normalizeExecutionEvidenceRow', [[
            'id' => 3,
            'task_id' => 2,
            'before_json' => json_encode(['password' => 'legacy-password'], JSON_UNESCAPED_UNICODE),
            'after_json' => json_encode(['currency' => 'CNY', 'external_order_id' => $businessId], JSON_UNESCAPED_UNICODE),
            'platform_response_json' => json_encode(['headers' => ['Cookie' => 'sid=legacy-cookie']], JSON_UNESCAPED_UNICODE),
            'remark' => 'mtgsig=legacy-signature',
        ]]);

        $encoded = json_encode([$intent, $task, $evidence], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach (['legacy-secret', 'legacy-auth', 'legacy-token', 'legacy-password', 'legacy-cookie', 'legacy-signature'] as $secret) {
            self::assertStringNotContainsString($secret, (string)$encoded);
        }
        self::assertSame('CNY', $intent['current_value']['currency']);
        self::assertSame('CNY', $intent['current_value']['cookiePricesDisplayed']);
        self::assertSame($businessId, $task['target_value']['external_order_id']);
        self::assertSame($businessId, $evidence['after']['external_order_id']);
    }

    public function testMeituanTargetIdentityResolverDoesNotDecodeDataSourceConfigJson(): void
    {
        $method = new \ReflectionMethod(OperationManagementService::class, 'resolveMeituanTargetPoiId');
        $lines = file($method->getFileName()) ?: [];
        $source = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        self::assertStringNotContainsString('config_json', $source);
        self::assertStringNotContainsString('json_decode', $source);
        self::assertStringContainsString('tableHasColumn', $source);
    }

    public function testExecutionTaskReviewGuardsBothInputAndDerivedSummaryBeforeWrite(): void
    {
        $method = new \ReflectionMethod(OperationManagementService::class, 'reviewExecutionTask');
        $lines = file($method->getFileName()) ?: [];
        $source = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        self::assertGreaterThanOrEqual(
            2,
            substr_count($source, 'assertExecutionPayloadHasNoCredentialMaterial'),
            'Task review must guard both request input and any summary derived from legacy action tracking.'
        );
    }

    public function testExecutionTaskReviewUsesTransactionalCompareAndSwap(): void
    {
        $method = new \ReflectionMethod(OperationManagementService::class, 'reviewExecutionTask');
        $lines = file($method->getFileName()) ?: [];
        $source = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        self::assertStringContainsString('Db::transaction', $source);
        self::assertStringContainsString("->where('status', 'executed')", $source);
        self::assertStringContainsString("->where('result_status', \$expectedResultStatus)", $source);
        self::assertStringContainsString("->where('result_summary', \$expectedResultSummary)", $source);
        self::assertStringContainsString('if ($affected !== 1)', $source);
        self::assertStringContainsString('execution task state changed; refresh before review', $source);
    }

    private function metricValue(array $summary, string $key): mixed
    {
        foreach ($summary['metrics'] as $metric) {
            if (($metric['key'] ?? '') === $key) {
                return $metric['value'];
            }
        }

        self::fail('Metric not found: ' . $key);
    }
}
