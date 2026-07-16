<?php
declare(strict_types=1);

namespace Tests;

use app\service\AiDailyReportService;
use PHPUnit\Framework\TestCase;

final class AiDailyReportReadinessServiceTest extends TestCase
{
    public function testSyntheticFunnelMetricsAreDisplayedAndDerivedWithoutClaimingRealEvidence(): void
    {
        $service = new AiDailyReportService();
        $method = new \ReflectionMethod($service, 'collectYesterdayResult');
        $method->setAccessible(true);

        $result = $method->invoke($service, [
            'revenue' => 2400,
            'orders' => 12,
            'room_nights' => 8,
            'adr' => 300,
        ], [
            'exposure' => 1000,
            'visitors' => 125,
            'flow_rate' => 12.5,
            'order_filling' => 20,
            'order_submit' => 10,
            'fill_submit_rate' => 50.0,
        ], '2026-07-15');

        $metrics = [];
        foreach ($result['metrics'] as $metric) {
            $metrics[$metric['key']] = $metric;
        }
        self::assertSame(12.5, $metrics['flow_rate']['value']);
        self::assertSame('%', $metrics['flow_rate']['unit']);
        self::assertSame(20.0, $metrics['order_filling']['value']);
        self::assertSame(10.0, $metrics['order_submit']['value']);
        self::assertSame(50.0, $metrics['fill_submit_rate']['value']);
        self::assertSame('operation.full_data.ota.fill_submit_rate', $metrics['fill_submit_rate']['source_ref']);
    }

    public function testCurrentRealtimeOperatingSnapshotIsNotCalledYesterdayAndMissingFunnelStaysNull(): void
    {
        $service = new AiDailyReportService();
        $collect = new \ReflectionMethod($service, 'collectYesterdayResult');
        $collect->setAccessible(true);
        $today = date('Y-m-d');

        $result = $collect->invoke($service, [
            'revenue' => 5939,
            'orders' => 11,
            'room_nights' => 7,
            'adr' => 848.43,
            'evidence_refs' => [[
                'source_ref' => 'online_daily_data#17652',
                'source' => 'ctrip',
                'data_date' => $today,
                'data_period' => 'realtime_snapshot',
                'is_final' => 0,
            ]],
        ], [
            'exposure' => null,
            'visitors' => null,
            'data_status' => 'partial',
            'missing_metrics' => ['exposure', 'visitors'],
        ], $today);

        $metrics = array_column($result['metrics'], null, 'key');
        self::assertSame('current_day_process', $result['time_scope']);
        self::assertSame('当日过程快照', $result['time_label']);
        self::assertFalse($result['is_final']);
        self::assertSame(5939.0, $metrics['revenue']['value']);
        self::assertSame(11.0, $metrics['orders']['value']);
        self::assertNull($metrics['exposure']['value']);
        self::assertSame('missing', $metrics['exposure']['data_status']);
        self::assertNull($metrics['visitors']['value']);
        self::assertSame('missing', $metrics['visitors']['data_status']);

        $buildSummary = new \ReflectionMethod($service, 'buildSummaryText');
        $buildSummary->setAccessible(true);
        $summary = $buildSummary->invoke($service, $result, [], []);
        self::assertStringContainsString('当日过程快照', $summary);
        self::assertStringNotContainsString('Yesterday result', $summary);
    }

    public function testHistoricalFinalSnapshotKeepsYesterdaySummaryCompatibility(): void
    {
        $service = new AiDailyReportService();
        $collect = new \ReflectionMethod($service, 'collectYesterdayResult');
        $collect->setAccessible(true);
        $date = date('Y-m-d', strtotime('-1 day'));
        $result = $collect->invoke($service, [
            'orders' => 6,
            'revenue' => 1888,
            'evidence_refs' => [[
                'data_date' => $date,
                'data_period' => 'historical_daily',
                'is_final' => 1,
            ]],
        ], [], $date);

        self::assertSame('historical_final', $result['time_scope']);
        self::assertTrue($result['is_final']);
        $buildSummary = new \ReflectionMethod($service, 'buildSummaryText');
        $buildSummary->setAccessible(true);
        self::assertStringStartsWith('Yesterday result:', $buildSummary->invoke($service, $result, [], []));
    }

    public function testHistoricalRealtimeSnapshotIsExplicitlyNonFinalProcessData(): void
    {
        $service = new AiDailyReportService();
        $collect = new \ReflectionMethod($service, 'collectYesterdayResult');
        $collect->setAccessible(true);
        $date = date('Y-m-d', strtotime('-2 days'));
        $result = $collect->invoke($service, [
            'orders' => 4,
            'revenue' => 900,
            'evidence_refs' => [[
                'data_date' => $date,
                'data_period' => 'realtime_snapshot',
                'is_final' => 0,
            ]],
        ], [], $date);

        self::assertSame('historical_result', $result['time_scope']);
        self::assertSame('历史过程快照（非日终）', $result['time_label']);
        self::assertFalse($result['is_final']);
        self::assertSame('verified_historical_process_snapshot', $result['time_evidence_status']);
        $buildSummary = new \ReflectionMethod($service, 'buildSummaryText');
        $buildSummary->setAccessible(true);
        $summary = $buildSummary->invoke($service, $result, [], []);
        self::assertStringStartsWith('历史过程快照（非日终）:', $summary);
        self::assertStringNotContainsString('Yesterday result', $summary);
    }

    public function testMissingOtaFunnelGapIsNarrowedToSelfCtripFunnel(): void
    {
        $service = new AiDailyReportService();
        $method = new \ReflectionMethod($service, 'collectDataGaps');
        $method->setAccessible(true);

        $gaps = $method->invoke($service, [
            'summary' => [
                'data_status' => 'ok',
                'evidence_refs' => [['source' => 'ctrip']],
            ],
            'ota' => [
                'data_status' => 'partial',
                'missing_metrics' => ['exposure', 'visitors'],
            ],
        ], [], []);

        self::assertContains('ctrip_self_funnel_missing', array_column($gaps, 'code'));
        self::assertStringContainsString('本店携程漏斗缺失', $gaps[0]['message']);
        self::assertNotContains('ota_data_pending', array_column($gaps, 'code'));
    }

    public function testMissingFunnelUsesTheActualNonCtripPlatform(): void
    {
        $service = new AiDailyReportService();
        $method = new \ReflectionMethod($service, 'collectDataGaps');
        $method->setAccessible(true);

        $gaps = $method->invoke($service, [
            'summary' => [
                'data_status' => 'ok',
                'evidence_refs' => [['source' => 'meituan']],
            ],
            'ota' => [
                'data_status' => 'partial',
                'missing_metrics' => ['exposure', 'visitors'],
            ],
        ], [], []);

        self::assertSame('meituan_self_funnel_missing', $gaps[0]['code']);
        self::assertStringContainsString('本店美团漏斗缺失', $gaps[0]['message']);
        self::assertStringNotContainsString('携程', $gaps[0]['message']);
    }

    public function testMissingFunnelUsesQunarWhenSourceIsEmptyAndPlatformIsPresent(): void
    {
        $service = new AiDailyReportService();
        $method = new \ReflectionMethod($service, 'collectDataGaps');
        $method->setAccessible(true);

        $gaps = $method->invoke($service, [
            'summary' => [
                'data_status' => 'ok',
                'evidence_refs' => [['source' => '', 'platform' => 'Qunar']],
            ],
            'ota' => [
                'data_status' => 'partial',
                'missing_metrics' => ['exposure', 'visitors'],
            ],
        ], [], []);

        self::assertSame('qunar_self_funnel_missing', $gaps[0]['code']);
        self::assertStringContainsString('本店去哪儿漏斗缺失', $gaps[0]['message']);
    }

    public function testMissingFunnelUsesGenericOtaWhenPlatformIsUnknown(): void
    {
        $service = new AiDailyReportService();
        $method = new \ReflectionMethod($service, 'collectDataGaps');
        $method->setAccessible(true);

        $gaps = $method->invoke($service, [
            'summary' => [
                'data_status' => 'ok',
                'evidence_refs' => [['source' => '', 'platform' => 'unknown']],
            ],
            'ota' => [
                'data_status' => 'partial',
                'missing_metrics' => ['exposure', 'visitors'],
            ],
        ], [], []);

        self::assertSame('ota_self_funnel_missing', $gaps[0]['code']);
        self::assertStringContainsString('本店OTA漏斗缺失', $gaps[0]['message']);
        self::assertStringNotContainsString('携程', $gaps[0]['message']);
    }

    public function testGeneratedReportWithTransferableActionRequiresExecutionIntent(): void
    {
        $service = new AiDailyReportService();

        $readiness = $service->buildReportReadiness([
            'source_refs' => [['key' => 'operation.full_data']],
            'data_gaps' => [],
            'recommended_actions' => [[
                'title' => 'Review price',
                'can_create_execution_intent' => true,
            ]],
        ]);

        self::assertSame('pending_execution_transfer', $readiness['stage']);
        self::assertFalse($readiness['closed_loop']);
        self::assertSame(1, $readiness['transferable_count']);
        self::assertContains('execution_intent', array_column($readiness['missing_evidence'], 'code'));
    }

    public function testDataGapActionIsNotPresentedAsExecutionReady(): void
    {
        $service = new AiDailyReportService();

        $readiness = $service->buildReportReadiness([
            'source_refs' => [['key' => 'operation.full_data']],
            'data_gaps' => [['code' => 'summary_data_pending', 'message' => 'summary data pending']],
            'recommended_actions' => [[
                'title' => 'Repair data',
                'can_create_execution_intent' => false,
                'blocked_reason' => 'Data repair must happen before execution',
            ]],
        ]);

        self::assertSame('data_recheck_required', $readiness['stage']);
        self::assertFalse($readiness['closed_loop']);
        self::assertSame(0, $readiness['transferable_count']);
        self::assertContains('data_gaps', array_column($readiness['missing_evidence'], 'code'));
    }

    public function testHealthyReportDoesNotManufactureFallbackActions(): void
    {
        $service = new AiDailyReportService();
        $method = new \ReflectionMethod($service, 'buildRecommendedActions');
        $method->setAccessible(true);

        $actions = $method->invoke($service, [], ['root_causes' => []], ['summary' => []], []);

        self::assertSame([], $actions);

        $readiness = $service->buildReportReadiness([
            'source_refs' => [['key' => 'operation.full_data']],
            'data_gaps' => [],
            'recommended_actions' => $actions,
        ]);

        self::assertSame(0, $readiness['transferable_count']);
        self::assertSame('no_action_required', $readiness['stage']);
        self::assertSame('无需行动，日报闭环', $readiness['status_label']);
        self::assertSame('no_action', $readiness['decision_status']);
        self::assertTrue($readiness['closed_loop']);
        self::assertSame(0, $readiness['blocked_count']);
        self::assertSame(0, $readiness['investigation_count']);
        self::assertSame(0, $readiness['execution_action_count']);
        self::assertSame([], $readiness['missing_evidence']);
        self::assertSame('真实证据未触发行动阈值，本次不创建执行单。', $readiness['notice']);
    }

    public function testPriceReviewWithoutConcreteTargetIsNotMarkedTransferable(): void
    {
        $service = new AiDailyReportService();
        $method = new \ReflectionMethod($service, 'buildRecommendedActions');
        $method->setAccessible(true);

        $actions = $method->invoke($service, [], [
            'root_causes' => [[
                'code' => 'price_gap',
                'title' => '价格竞争力不足',
                'suggestion' => '检查价格差距',
            ]],
        ], ['summary' => []], []);

        self::assertSame('price', $actions[0]['object_type']);
        self::assertSame('investigation', $actions[0]['recommendation_type']);
        self::assertTrue($actions[0]['is_investigation_only']);
        self::assertFalse($actions[0]['can_create_execution_intent']);
        self::assertStringContainsString('target price', $actions[0]['blocked_reason']);
    }

    public function testLlmCannotReplaceTrustedSummaryActionOrAbnormalityWithoutRuleEvidence(): void
    {
        $service = new AiDailyReportService();
        $method = new \ReflectionMethod($service, 'mergeLlmReport');
        $method->setAccessible(true);

        $report = $method->invoke($service, [
            'summary' => '规则未发现异常',
            'abnormal_metrics' => [],
            'competitor_changes' => [],
            'recommended_actions' => [],
            'data_gaps' => [],
            'source_refs' => [['key' => 'operation.full_data']],
        ], [
            'summary' => '模型解释文本',
            'ai_interpretation' => [
                'possible_explanations' => ['可能与渠道曝光变化有关'],
                'conflicting_evidence' => ['订单趋势未同步变化'],
                'missing_information' => ['缺少同口径历史窗口'],
                'confidence' => 'low',
            ],
            'abnormal_metrics' => [['key' => 'invented_metric']],
            'recommended_actions' => [[
                'title' => 'Invented action',
                'action' => 'Change price now',
            ]],
        ]);

        self::assertSame([], $report['abnormal_metrics']);
        self::assertSame([], $report['recommended_actions']);
        self::assertSame('规则未发现异常', $report['summary']);
        self::assertSame('low', $report['ai_interpretation']['confidence']);
        self::assertSame(['可能与渠道曝光变化有关'], $report['ai_interpretation']['possible_explanations']);
    }

    public function testLegacyFallbackManualReviewIsStillExcludedFromExecutionDenominator(): void
    {
        $service = new AiDailyReportService();

        $readiness = $service->buildReportReadiness([
            'source_refs' => [['key' => 'operation.full_data']],
            'data_gaps' => [],
            'recommended_actions' => [[
                'title' => 'Review daily operating signal 1',
                'action_type' => 'manual_review',
                'can_create_execution_intent' => false,
                'blocked_reason' => 'Fallback manual review is investigation-only until stronger evidence is selected.',
            ]],
        ]);

        self::assertSame('investigation_only', $readiness['stage']);
        self::assertSame(1, $readiness['investigation_count']);
        self::assertSame(0, $readiness['blocked_count']);
        self::assertSame(0, $readiness['execution_action_count']);
    }

    public function testInvestigationPaddingDoesNotPreventExecutableActionClosure(): void
    {
        $service = new AiDailyReportService();

        $readiness = $service->buildReportReadiness([
            'source_refs' => [['key' => 'operation.full_data']],
            'data_gaps' => [],
            'recommended_actions' => [[
                'title' => 'Review conversion',
                'execution_intent_id' => 16,
                'can_create_execution_intent' => true,
            ], [
                'title' => 'Investigate daily operating signal 1',
                'action_type' => 'manual_review',
                'recommendation_type' => 'investigation',
                'is_investigation_only' => true,
                'can_create_execution_intent' => false,
            ]],
        ], [[
            'id' => 16,
            'stage' => 'reviewed',
            'approval' => ['status' => 'approved'],
            'execution' => ['status' => 'executed', 'task_id' => 9],
            'evidence' => ['count' => 1],
            'review' => ['status' => 'success'],
            'roi' => ['status' => 'ready', 'value' => 12.5],
        ]]);

        self::assertSame('daily_loop_closed', $readiness['stage']);
        self::assertSame(2, $readiness['action_count']);
        self::assertSame(1, $readiness['execution_action_count']);
        self::assertSame(1, $readiness['investigation_count']);
        self::assertSame(1, $readiness['roi_ready_count']);
    }

    public function testReviewedReportStillRequiresRoiEvidence(): void
    {
        $service = new AiDailyReportService();

        $readiness = $service->buildReportReadiness([
            'source_refs' => [['key' => 'operation.full_data']],
            'data_gaps' => [],
            'recommended_actions' => [[
                'title' => 'Review conversion',
                'execution_intent_id' => 15,
                'can_create_execution_intent' => true,
            ]],
        ], [[
            'id' => 15,
            'stage' => 'reviewed',
            'approval' => ['status' => 'approved'],
            'execution' => ['status' => 'executed', 'task_id' => 8],
            'evidence' => ['count' => 1],
            'review' => ['status' => 'success'],
            'roi' => ['status' => 'data_gap'],
        ]]);

        self::assertSame('reviewed_no_roi', $readiness['stage']);
        self::assertFalse($readiness['closed_loop']);
        self::assertSame(1, $readiness['reviewed_count']);
        self::assertSame(0, $readiness['roi_ready_count']);
        self::assertContains('roi_evidence', array_column($readiness['missing_evidence'], 'code'));
    }

    public function testReportIsClosedOnlyWhenEveryActionHasRoiReadyEvidence(): void
    {
        $service = new AiDailyReportService();

        $readiness = $service->buildReportReadiness([
            'source_refs' => [['key' => 'operation.full_data']],
            'data_gaps' => [],
            'recommended_actions' => [[
                'title' => 'Review conversion',
                'execution_intent_id' => 16,
                'can_create_execution_intent' => true,
            ]],
        ], [[
            'id' => 16,
            'stage' => 'reviewed',
            'approval' => ['status' => 'approved'],
            'execution' => ['status' => 'executed', 'task_id' => 9],
            'evidence' => ['count' => 1],
            'review' => ['status' => 'success'],
            'roi' => ['status' => 'ready', 'value' => 12.5],
        ]]);

        self::assertSame('daily_loop_closed', $readiness['stage']);
        self::assertTrue($readiness['closed_loop']);
        self::assertSame(100, $readiness['score']);
        self::assertSame(1, $readiness['roi_ready_count']);
        self::assertSame([], $readiness['missing_evidence']);
    }

    public function testEnrichReportRowsAddsReportAndActionReadiness(): void
    {
        $service = new AiDailyReportService();

        $rows = $service->enrichReportRows([[
            'id' => 0,
            'hotel_id' => 2,
            'created_by' => 1,
            'yesterday_result_json' => '{"metrics":[{"key":"orders","value":8}]}',
            'abnormal_metrics_json' => '[]',
            'competitor_changes_json' => '[]',
            'data_gaps_json' => '[]',
            'recommended_actions_json' => '[{"title":"Review price","can_create_execution_intent":true}]',
            'source_refs_json' => '[{"key":"operation.full_data"}]',
            'snapshot_json' => '{"input_trust":{"readback_verified":true}}',
        ]]);

        self::assertSame('pending_execution_transfer', $rows[0]['report_readiness']['stage']);
        self::assertSame('pending_transfer', $rows[0]['recommended_actions'][0]['action_readiness']['stage']);
        self::assertSame(8, $rows[0]['yesterday_result']['metrics'][0]['value']);
        self::assertSame([], $rows[0]['owner_communication_brief']);
    }

    public function testLegacyUntrustedReportIsFailClosedAgainDuringReadback(): void
    {
        $service = new AiDailyReportService();
        $rows = $service->enrichReportRows([[
            'id' => 0,
            'hotel_id' => 2,
            'created_by' => 1,
            'yesterday_result_json' => '{}',
            'abnormal_metrics_json' => '[]',
            'competitor_changes_json' => '[]',
            'data_gaps_json' => '[{"code":"ota_evidence_readback_unverified","message":"readback missing"}]',
            'recommended_actions_json' => '[{"title":"Review conversion","action_type":"promotion","can_create_execution_intent":true}]',
            'source_refs_json' => '[]',
            'snapshot_json' => '{"input_trust":{"readback_verified":false}}',
        ]]);

        self::assertFalse($rows[0]['recommended_actions'][0]['can_create_execution_intent']);
        self::assertSame('blocked_by_data_gap', $rows[0]['recommended_actions'][0]['action_readiness']['stage']);
        self::assertSame(0, $rows[0]['report_readiness']['transferable_count']);
        self::assertSame('data_recheck_required', $rows[0]['report_readiness']['stage']);
    }

    public function testExecutionIntentIdempotencyIsPerActionAndFailedTerminalsCanRetry(): void
    {
        $service = new AiDailyReportService();
        $action = [
            'id' => 'action-1',
            'title' => 'Review OTA promotion',
            'action_type' => 'promotion',
            'platform' => 'ctrip',
        ];
        $keyMethod = new \ReflectionMethod($service, 'dailyReportActionIdempotencyKey');
        $keyMethod->setAccessible(true);
        $retryMethod = new \ReflectionMethod($service, 'isRetryableExecutionIntentTerminal');
        $retryMethod->setAccessible(true);

        $key = $keyMethod->invoke($service, 12, 0, $action);
        self::assertSame($key, $keyMethod->invoke($service, 12, 0, $action));
        self::assertNotSame($key, $keyMethod->invoke($service, 12, 1, $action));
        self::assertNotSame($key, $keyMethod->invoke($service, 13, 0, $action));

        foreach (['failed', 'failure', 'rejected', 'cancelled', 'canceled'] as $status) {
            self::assertTrue($retryMethod->invoke($service, $status), $status);
        }
        foreach (['pending_approval', 'approved', 'completed'] as $status) {
            self::assertFalse($retryMethod->invoke($service, $status), $status);
        }
    }

    public function testOwnerCommunicationBriefIsReturnedFromSnapshotOnly(): void
    {
        $service = new AiDailyReportService();

        $rows = $service->enrichReportRows([[
            'id' => 0,
            'hotel_id' => 2,
            'created_by' => 1,
            'yesterday_result_json' => '{"metrics":[{"key":"orders","value":8}]}',
            'abnormal_metrics_json' => '[]',
            'competitor_changes_json' => '[]',
            'data_gaps_json' => '[]',
            'recommended_actions_json' => '[{"title":"Review price","can_create_execution_intent":true}]',
            'source_refs_json' => '[{"key":"operation.full_data"}]',
            'snapshot_json' => '{"input_trust":{"readback_verified":true},"owner_communication_brief":{"status":"available","non_execution":true,"source_policy":"daily_report_operating_data_plus_owner_negotiation_playbook_reference"}}',
        ]]);

        self::assertSame('available', $rows[0]['owner_communication_brief']['status']);
        self::assertTrue($rows[0]['owner_communication_brief']['non_execution']);
        self::assertArrayNotHasKey('owner_communication_brief', $rows[0]['recommended_actions'][0]);
    }

    public function testVerifiedResultStaysUsableWithoutAiActionsOrRoi(): void
    {
        $service = new AiDailyReportService();
        $readiness = $service->buildResultReadiness([
            'model_status' => 'failed',
            'source_refs' => [['key' => 'online_daily_data#9']],
            'data_gaps' => [],
            'yesterday_result' => [
                'metrics' => [['key' => 'orders', 'value' => 8, 'result_layer' => 'source_fact']],
            ],
            'recommended_actions' => [],
            'snapshot' => ['input_trust' => ['readback_verified' => true]],
        ]);

        self::assertSame('available', $readiness['status']);
        self::assertTrue($readiness['usable']);
        self::assertFalse($readiness['ai_required']);
        self::assertTrue($readiness['workflow_independent']);
    }

    public function testDeclaredDataGapMakesResultPartialButDoesNotEraseMeasuredFacts(): void
    {
        $service = new AiDailyReportService();
        $readiness = $service->buildResultReadiness([
            'source_refs' => [['key' => 'online_daily_data#9']],
            'data_gaps' => [['code' => 'competitors_data_pending', 'message' => 'competitors missing']],
            'yesterday_result' => [
                'metrics' => [['key' => 'orders', 'value' => 8, 'result_layer' => 'source_fact']],
            ],
            'snapshot' => ['input_trust' => ['readback_verified' => true]],
        ]);

        self::assertSame('partial', $readiness['status']);
        self::assertTrue($readiness['usable']);
        self::assertSame(1, $readiness['declared_data_gap_count']);
    }

    public function testSignalWithoutComparableReferenceIsNotForcedIntoConfirmedAnomaly(): void
    {
        $service = new AiDailyReportService();
        $method = new \ReflectionMethod($service, 'collectAbnormalMetrics');
        $method->setAccessible(true);
        $signals = $method->invoke($service, ['abnormal_flags' => ['曝光变化待关注']], ['root_causes' => []]);

        self::assertCount(1, $signals);
        self::assertSame('reference_missing', $signals[0]['signal_status']);
        self::assertNull($signals[0]['is_anomaly']);
        self::assertSame('missing', $signals[0]['reference_basis']['status']);
    }

    public function testWorkflowGapDoesNotBecomeResultDataGap(): void
    {
        $service = new AiDailyReportService();
        $resultMethod = new \ReflectionMethod($service, 'collectDataGaps');
        $resultMethod->setAccessible(true);
        $workflowMethod = new \ReflectionMethod($service, 'collectWorkflowGaps');
        $workflowMethod->setAccessible(true);
        $executionFlow = [
            'data_gaps' => [['code' => 'roi_evidence_missing', 'message' => 'ROI evidence missing']],
        ];

        self::assertSame([], $resultMethod->invoke($service, [], [], $executionFlow));
        $workflowGaps = $workflowMethod->invoke($service, $executionFlow);
        self::assertCount(1, $workflowGaps);
        self::assertSame('roi_evidence_missing', $workflowGaps[0]['code']);
    }

    public function testDeterministicResultVersionIgnoresModelModeAndAiText(): void
    {
        $service = new AiDailyReportService();
        $method = new \ReflectionMethod($service, 'buildResultContract');
        $method->setAccessible(true);
        $report = [
            'report_scope' => ['hotel_id' => 2, 'report_date' => '2026-07-16'],
            'yesterday_result' => [
                'source_scope' => 'OTA and operating-report scope',
                'metrics' => [['key' => 'orders', 'value' => 8, 'result_layer' => 'source_fact']],
            ],
            'abnormal_metrics' => [],
            'competitor_changes' => [],
            'data_gaps' => [],
            'source_refs' => [['key' => 'online_daily_data#9', 'platform' => 'ctrip']],
        ];
        $first = $method->invoke($service, $report, [
            'ai_explanation' => '解释A',
            'ai_interpretation' => ['possible_explanations' => ['解释A'], 'confidence' => 'low'],
        ], 'model-a-fingerprint');
        $second = $method->invoke($service, $report, [
            'ai_explanation' => '解释B',
            'ai_interpretation' => ['possible_explanations' => ['解释B'], 'confidence' => 'high'],
        ], 'rule-only-fingerprint');

        self::assertSame($first['result_version'], $second['result_version']);
        self::assertNotSame($first['input_fingerprint'], $second['input_fingerprint']);
    }

    public function testTrialAcceptanceUsesTrustedCollectionComparableFollowUpAndUsefulnessConfirmation(): void
    {
        $service = new AiDailyReportService();
        $method = new \ReflectionMethod($service, 'buildTrialValidation');
        $method->setAccessible(true);
        $contract = [
            'metric_version' => 'metric-v1',
            'reference_version' => 'reference-v1',
            'analysis_object' => ['hotel_id' => 2, 'platforms' => ['ctrip']],
        ];
        $current = [
            'result_contract' => $contract,
            'source_trust' => ['ota' => ['readback_verified' => true]],
            'input_trust' => ['data_gaps' => []],
            'human_judgments' => [[
                'target_type' => 'report_usefulness',
                'decision' => 'accepted',
            ]],
        ];
        $result = $method->invoke($service, $current, ['result_contract' => $contract]);

        self::assertTrue($result['first_trusted_collection']['passed']);
        self::assertTrue($result['second_same_scope_comparison']['passed']);
        self::assertTrue($result['missing_items_exposed']['passed']);
        self::assertTrue($result['user_confirmed_useful']['passed']);
        self::assertTrue($result['passed']);
    }

    public function testReferenceVersionTracksDefinitionInsteadOfDailyMeasuredValue(): void
    {
        $service = new AiDailyReportService();
        $method = new \ReflectionMethod($service, 'buildResultContract');
        $method->setAccessible(true);
        $base = [
            'report_scope' => ['hotel_id' => 2, 'report_date' => '2026-07-16'],
            'yesterday_result' => ['metrics' => []],
            'competitor_changes' => [],
            'data_gaps' => [],
            'source_refs' => [],
        ];
        $basis = [
            'status' => 'available', 'type' => 'historical_average', 'metric' => 'exposure',
            'history_window' => 7, 'comparison_rule' => 'measured_value < reference_value * 0.7',
            'reference_scope' => 'same_hotel_same_platform', 'rule_version' => 'operation_root_cause.v1',
        ];
        $first = $method->invoke($service, $base + ['abnormal_metrics' => [[
            'type' => 'traffic_down', 'reference_basis' => $basis + ['measured_value' => 70, 'reference_value' => 110],
        ]]], [], '');
        $second = $method->invoke($service, $base + ['abnormal_metrics' => [[
            'type' => 'traffic_down', 'reference_basis' => $basis + ['measured_value' => 60, 'reference_value' => 100],
        ]]], [], '');

        self::assertSame($first['reference_version'], $second['reference_version']);
        self::assertNotSame($first['result_version'], $second['result_version']);
    }
}
