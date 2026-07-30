<?php
declare(strict_types=1);

namespace tests;

use app\service\AiDailyReportService;
use PHPUnit\Framework\TestCase;

final class AiDailyReportOperatingDiagnosisTest extends TestCase
{
    public function testDiagnosisSeparatesFactsJudgmentsGapsAndDecisionBoundary(): void
    {
        $service = new AiDailyReportService();
        $diagnosis = $this->invoke($service, 'buildOperatingDiagnosis', [
            $this->completeSnapshot(),
            $this->completeReport(),
        ]);

        self::assertSame('operating_diagnosis.zh-CN.v1', $diagnosis['version']);
        self::assertSame('zh-CN', $diagnosis['language']);
        self::assertSame('ota_channel', $diagnosis['scope']['metric_scope']);
        self::assertArrayHasKey('traffic_conversion', $diagnosis['facts']);
        self::assertArrayHasKey('price', $diagnosis['facts']);
        self::assertArrayHasKey('sellout', $diagnosis['facts']);
        self::assertArrayHasKey('special_events', $diagnosis['facts']);
        self::assertSame(96.0, $diagnosis['facts']['sellout']['occupancy_rate']);
        self::assertSame('not_full', $diagnosis['facts']['sellout']['sellout_status']);

        $judgments = $this->byDimension($diagnosis['judgments']);
        self::assertSame('available', $judgments['comparable_period']['status']);
        self::assertSame('available', $judgments['traffic_conversion']['status']);
        self::assertSame('available', $judgments['price']['status']);
        self::assertSame('available', $judgments['sellout']['status']);
        self::assertSame('available', $judgments['special_events']['status']);
        self::assertSame('medium', $judgments['overall']['confidence']['level']);
        self::assertFalse($judgments['overall']['causal_claim_allowed']);

        self::assertSame('not_estimated', $diagnosis['expected_results'][0]['status']);
        self::assertNull($diagnosis['expected_results'][0]['range']['lower']);
        self::assertNull($diagnosis['expected_results'][0]['range']['upper']);
        self::assertSame('not_assessed', $diagnosis['expected_results'][0]['confidence']['level']);
        self::assertNotSame('', $diagnosis['expected_results'][0]['basis']['rule']);

        self::assertSame('clear', $diagnosis['conflict_gate']['status']);
        self::assertTrue($diagnosis['decision_boundary']['advisory_only']);
        self::assertTrue($diagnosis['decision_boundary']['user_confirmation_required']);
        self::assertFalse($diagnosis['decision_boundary']['may_execute']);
        self::assertFalse($diagnosis['decision_boundary']['may_speak_for_user']);
    }

    public function testUnresolvedFunnelConflictStopsAffectedJudgmentAiAndAction(): void
    {
        $service = new AiDailyReportService();
        $snapshot = $this->completeSnapshot();
        $snapshot['operation']['ota']['exposure'] = 0;
        $snapshot['operation']['ota']['visitors'] = 0;
        $snapshot['operation']['ota']['orders'] = 4;
        $report = $this->completeReport();

        $diagnosis = $this->invoke($service, 'buildOperatingDiagnosis', [$snapshot, $report]);
        $judgments = $this->byDimension($diagnosis['judgments']);

        self::assertSame('blocked', $diagnosis['conflict_gate']['status']);
        self::assertContains('traffic_conversion', $diagnosis['conflict_gate']['blocked_dimensions']);
        self::assertSame('blocked_by_data_conflict', $judgments['traffic_conversion']['status']);
        self::assertSame('blocked_by_data_conflict', $judgments['overall']['status']);
        self::assertSame('blocked_by_data_conflict', $diagnosis['ai_assistance']['status']);
        self::assertSame('unavailable', $diagnosis['ai_assistance']['confidence']);
        self::assertSame([], $diagnosis['ai_assistance']['possible_explanations']);

        $conflicts = $this->invoke($service, 'collectDataConflicts', [$snapshot]);
        $actions = $this->invoke($service, 'blockActionsForDataConflicts', [[[
            'title' => '复核转化',
            'object_type' => 'campaign',
            'action_type' => 'promotion',
            'expected_metric' => 'conversion',
            'can_create_execution_intent' => true,
        ]], $conflicts]);
        self::assertFalse($actions[0]['can_create_execution_intent']);
        self::assertSame('blocked_by_data_conflict', $actions[0]['judgment_status']);
    }

    public function testRuleReportPersistsConflictGapAndBlocksRelatedRecommendation(): void
    {
        $service = new AiDailyReportService();
        $snapshot = $this->completeSnapshot();
        $snapshot['operation']['ota']['exposure'] = 0;
        $snapshot['operation']['ota']['visitors'] = 0;
        $snapshot['operation']['ota']['orders'] = 4;
        $snapshot['operation']['abnormal_flags'] = [];
        $snapshot['root_cause'] = [
            'problem_level' => 'medium',
            'root_causes' => [[
                'code' => 'order_conversion_low',
                'title' => '订单转化差',
                'suggestion' => '复核价格、库存和转化链路',
                'evidence' => '订单转化低于同口径历史参考',
                'priority' => 4,
                'reference_basis' => [
                    'status' => 'available',
                    'type' => 'historical_average',
                    'metric' => 'order_rate',
                    'measured_value' => 4,
                    'reference_value' => 9,
                    'history_window' => 30,
                    'reference_scope' => 'same_hotel_same_platform',
                ],
            ]],
        ];
        $snapshot['execution_flow'] = ['summary' => []];
        $snapshot['source_refs'] = [];
        $snapshot['competition_circle_bundle'] = [
            'quality' => ['data_gaps' => []],
            'recommendations' => ['items' => []],
        ];

        $report = $this->invoke($service, 'buildRuleReport', [$snapshot, '2026-09-20', 9]);
        self::assertContains('ota_funnel_orders_conflict', array_column($report['data_gaps'], 'code'));
        self::assertSame('blocked_by_data_conflict', $report['recommended_actions'][0]['judgment_status']);
        self::assertFalse($report['recommended_actions'][0]['can_create_execution_intent']);
        self::assertSame('blocked', $report['operating_diagnosis']['conflict_gate']['status']);
        self::assertStringNotContainsString('Yesterday result', $report['summary']);
    }

    public function testTemporalFactMismatchBlocksAllJudgmentAndHidesUnsafeSummaryMetric(): void
    {
        $service = new AiDailyReportService();
        $snapshot = $this->completeSnapshot();
        $snapshot['operation']['summary']['orders'] = 5;
        $snapshot['temporal_facts'] = [
            'status' => 'partial',
            'report_date' => '2026-09-20',
            'metric_scope' => 'ota_channel',
            'metrics' => ['ota_orders' => 4],
        ];

        $conflicts = $this->invoke($service, 'collectDataConflicts', [$snapshot]);
        self::assertContains('operation_summary_temporal_fact_mismatch_orders', array_column($conflicts, 'code'));

        $report = $this->invoke($service, 'buildRuleReport', [$snapshot, '2026-09-20', 9]);
        self::assertContains('operation_summary_temporal_fact_mismatch_orders', array_column($report['data_gaps'], 'code'));
        self::assertSame('blocked', $report['operating_diagnosis']['conflict_gate']['status']);
        self::assertNull($this->metric($report['yesterday_result']['metrics'], 'orders')['value']);
        self::assertSame('blocked_by_data_conflict', $this->metric($report['yesterday_result']['metrics'], 'orders')['data_status']);
    }

    public function testExistingReportSurfacesExposeOccupancyAndSpecialEventContext(): void
    {
        $service = new AiDailyReportService();
        $result = $this->invoke($service, 'collectYesterdayResult', [[
            'revenue' => 3200,
            'orders' => 12,
            'room_nights' => 20,
            'adr' => 160,
            'occ' => 100,
            'source_scope' => 'whole_hotel_daily_report',
            'metric_scopes' => [
                'revenue' => ['whole_hotel_daily_report'],
                'orders' => ['whole_hotel_daily_report'],
                'room_nights' => ['whole_hotel_daily_report'],
                'occ' => ['whole_hotel_daily_report'],
            ],
        ], [
            'exposure' => 900,
            'visitors' => 90,
            'orders' => 12,
            'data_status' => 'ok',
        ], '2026-09-20']);
        $metrics = [];
        foreach ($result['metrics'] as $metric) {
            $metrics[$metric['key']] = $metric;
        }
        self::assertSame(100.0, $metrics['occ']['value']);
        self::assertSame('入住率（OCC）', $metrics['occ']['label']);
        self::assertSame('whole_hotel_daily_report', $metrics['occ']['metric_scope']);

        $signals = $this->invoke($service, 'collectAbnormalMetrics', [[
            'abnormal_flags' => [],
            'holiday' => [
                'next_holiday' => '中秋节',
                'days_left' => 5,
                'data_status' => 'ok',
            ],
        ], ['root_causes' => []]]);
        self::assertSame('special_event_context', $signals[0]['type']);
        self::assertFalse($signals[0]['is_anomaly']);
        self::assertSame('context_only', $signals[0]['signal_status']);
        self::assertStringContainsString('不自动归因', $signals[0]['reference_basis']['note']);
    }

    public function testExpectedEffectAlwaysCarriesRangeBasisAndConfidenceWithoutInventingLift(): void
    {
        $service = new AiDailyReportService();
        $policy = $this->invoke($service, 'dailyReportExpectedEffectPolicy', [[
            'expected_metric' => 'conversion',
        ]]);

        self::assertSame('not_estimated', $policy['range']['status']);
        self::assertNull($policy['range']['lower']);
        self::assertNull($policy['range']['upper']);
        self::assertSame('percentage_point', $policy['range']['unit']);
        self::assertSame('same_scope_follow_up', $policy['basis']['type']);
        self::assertSame('not_assessed', $policy['confidence']['level']);
    }

    public function testCommunicationBriefExposesFactsButDoesNotSpeakForUser(): void
    {
        $service = new AiDailyReportService();
        $brief = $this->invoke($service, 'buildOwnerCommunicationBrief', [[
            'yesterday_result' => [
                'metrics' => [[
                    'key' => 'orders',
                    'label' => '订单',
                    'value' => 8,
                    'source_ref' => 'online_daily_data#9',
                ]],
            ],
        ], [
            'scope' => ['source_scope' => 'ota_channel'],
        ], '2026-07-23']);

        self::assertSame('user_input_required', $brief['status']);
        self::assertFalse($brief['may_speak_for_user']);
        self::assertSame('', $brief['opening']);
        self::assertSame([], $brief['talking_points']);
        self::assertSame([], $brief['related_action_titles']);
        self::assertSame(8.0, $brief['evidence_points'][0]['value']);
    }

    public function testPersistedConflictSuppressesStaleAiOpinionDuringReadback(): void
    {
        $service = new AiDailyReportService();
        $snapshot = $this->completeSnapshot();
        $snapshot['operation']['ota']['exposure'] = 0;
        $snapshot['operation']['ota']['visitors'] = 0;
        $snapshot['operation']['ota']['orders'] = 4;
        $snapshot['ai_explanation'] = '这是一条冲突解决前不应继续回显的旧研判。';
        $snapshot['ai_interpretation'] = [
            'status' => 'available',
            'possible_explanations' => ['旧研判'],
            'conflicting_evidence' => [],
            'missing_information' => [],
            'confidence' => 'high',
            'boundary' => '旧边界',
        ];

        $row = $this->invoke($service, 'normalizeReportRow', [[
            'id' => 1,
            'hotel_id' => 9,
            'created_by' => 0,
            'cache_hit_count' => 0,
            'report_date' => '2026-09-20',
            'summary' => '已保存日报',
            'model_status' => 'succeeded',
            'model_message' => '',
            'yesterday_result_json' => json_encode(
                $this->completeReport()['yesterday_result'],
                JSON_UNESCAPED_UNICODE
            ),
            'abnormal_metrics_json' => '[]',
            'competitor_changes_json' => '[]',
            'data_gaps_json' => '[]',
            'recommended_actions_json' => '[]',
            'source_refs_json' => '[]',
            'snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
        ]]);

        self::assertSame('blocked_by_data_conflict', $row['ai_interpretation']['status']);
        self::assertSame('', $row['ai_explanation']);
        self::assertSame([], $row['ai_interpretation']['possible_explanations']);
        self::assertSame('blocked_by_data_conflict', $row['snapshot']['ai_interpretation']['status']);
        self::assertSame('', $row['snapshot']['ai_explanation']);
    }

    public function testChineseSummaryDoesNotFallBackToEnglishReportCopy(): void
    {
        $service = new AiDailyReportService();
        $summary = $this->invoke($service, 'buildSummaryText', [[
            'time_scope' => 'historical_final',
            'metrics' => [
                ['key' => 'orders', 'value' => 6],
                ['key' => 'revenue', 'value' => 1888],
            ],
        ], [], []]);

        self::assertStringStartsWith('历史/日终结果：', $summary);
        self::assertStringContainsString('订单6', $summary);
        self::assertStringNotContainsString('Yesterday result', $summary);
    }

    private function completeSnapshot(): array
    {
        return [
            'scope' => [
                'hotel_id' => 9,
                'report_date' => '2026-09-20',
            ],
            'operation' => [
                'summary' => [
                    'occ' => 96,
                ],
                'ota' => [
                    'exposure' => 1000,
                    'visitors' => 100,
                    'views' => 200,
                    'orders' => 12,
                    'view_rate' => 20,
                    'order_rate' => 12,
                    'order_filling' => 15,
                    'order_submit' => 12,
                    'fill_submit_rate' => 80,
                    'data_status' => 'ok',
                ],
                'competitors' => [
                    'avg_our_public_price' => 320,
                    'avg_price' => 300,
                    'price_gap' => 20,
                    'comparison_key' => 'same-rate-plan',
                    'comparability_status' => 'eligible',
                    'data_status' => 'ok',
                ],
                'holiday' => [
                    'next_holiday' => '中秋节',
                    'days_left' => 5,
                    'data_status' => 'ok',
                ],
            ],
            'input_trust' => [
                'readback_verified' => true,
                'data_gaps' => [],
            ],
        ];
    }

    private function completeReport(): array
    {
        return [
            'report_scope' => [
                'hotel_id' => 9,
                'report_date' => '2026-09-20',
            ],
            'yesterday_result' => [
                'report_date' => '2026-09-20',
                'time_scope' => 'historical_final',
                'time_label' => '历史/日终结果',
                'source_scope' => 'ota_channel',
                'metrics' => [
                    [
                        'key' => 'orders',
                        'label' => '订单',
                        'value' => 12,
                        'result_layer' => 'source_fact',
                        'metric_scope' => 'ota_channel',
                        'source_ref' => 'operation.full_data.summary.orders',
                    ],
                    [
                        'key' => 'occ',
                        'label' => '入住率（OCC）',
                        'value' => 96,
                        'result_layer' => 'derived_metric',
                        'metric_scope' => 'whole_hotel_daily_report',
                        'source_ref' => 'operation.full_data.summary.occ',
                    ],
                ],
            ],
            'abnormal_metrics' => [[
                'type' => 'traffic_down',
                'label' => '曝光下降',
                'source_ref' => 'operation.root_cause.root_causes',
                'reference_basis' => [
                    'status' => 'available',
                    'type' => 'historical_average',
                    'metric' => 'exposure',
                    'measured_value' => 1000,
                    'reference_value' => 1500,
                    'history_window' => 7,
                    'comparison_rule' => 'measured_value < reference_value * 0.7',
                    'reference_scope' => 'same_hotel_same_platform',
                ],
            ]],
            'data_gaps' => [],
            'recommended_actions' => [[
                'title' => '复核流量转化环节',
                'expected_metric' => 'conversion',
                'source_refs' => ['operation.full_data.ota'],
            ]],
            'ai_interpretation' => [
                'status' => 'available',
                'possible_explanations' => ['可能与渠道曝光变化有关'],
                'conflicting_evidence' => [],
                'missing_information' => [],
                'confidence' => 'medium',
                'boundary' => 'AI仅辅助解读，不替用户决策、执行或表达观点。',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function byDimension(array $judgments): array
    {
        $result = [];
        foreach ($judgments as $judgment) {
            $result[(string)$judgment['dimension']] = $judgment;
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function metric(array $metrics, string $key): array
    {
        foreach ($metrics as $metric) {
            if (is_array($metric) && (string)($metric['key'] ?? '') === $key) {
                return $metric;
            }
        }

        self::fail('Missing metric: ' . $key);
    }

    private function invoke(AiDailyReportService $service, string $method, array $arguments): mixed
    {
        $reflection = new \ReflectionMethod($service, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($service, $arguments);
    }
}
