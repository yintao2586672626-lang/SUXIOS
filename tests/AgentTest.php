<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Agent;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ReflectionHelper;

final class AgentTest extends TestCase
{
    use ReflectionHelper;

    private function controller(): Agent
    {
        $reflection = new ReflectionClass(Agent::class);
        return $reflection->newInstanceWithoutConstructor();
    }

    public function testOtaDiagnosisEvidenceActionsAlwaysCarryReferences(): void
    {
        $controller = $this->controller();
        $dataSet = [
            'online_rows' => [[
                'id' => 10,
                'source' => 'ctrip',
                'data_type' => 'traffic',
                'compare_type' => 'self',
                'data_date' => '2026-05-24',
                'list_exposure' => 1000,
                'detail_exposure' => 30,
                'order_filling_num' => 2,
            ]],
            'competitor_prices' => [[
                'id' => 20,
                'platform' => 'ctrip',
                'price' => 288,
                'fetch_time' => '2026-05-24 10:00:00',
                'collected_at' => '2026-05-24 10:00:00',
                'source_method' => 'local_browser_profile',
                'source_ref' => 'https://hotels.ctrip.com/hotels/200.html',
                'validation_status' => 'verified',
                'readback_verified' => 1,
                'check_in_date' => '2026-05-25',
                'check_out_date' => '2026-05-26',
                'adults' => 2,
                'children' => 0,
                'room_type_key' => 'standard-room',
                'rate_plan_key' => 'public-flex',
                'breakfast' => 'none',
                'cancellation_policy' => 'free-before-18',
                'payment_mode' => 'pay-at-hotel',
                'tax_fee_included' => 1,
                'price_basis' => 'room_per_night',
                'currency' => 'CNY',
                'availability' => 'available',
            ]],
            'price_suggestions' => [[
                'id' => 30,
                'suggestion_date' => '2026-05-24',
                'current_price' => 260,
                'suggested_price' => 278,
            ]],
            'sync_logs' => [[
                'id' => 40,
                'action' => 'auto_fetch',
                'create_time' => '2026-05-24 10:05:00',
            ]],
        ];

        $sources = $this->invokeNonPublic($controller, 'buildOtaDiagnosisEvidenceSources', [$dataSet, [
            'detail_rate' => 3.0,
            'order_rate' => 1.5,
        ]]);
        $items = $this->invokeNonPublic($controller, 'buildOtaDiagnosisActionItems', [[
            '优化曝光到访问转化',
            '对比竞对价格后人工确认调价',
            '补齐缺失数据源后复盘',
        ], $sources]);

        self::assertSame('source_summary', $sources[0]['ref']);
        self::assertCount(3, $items);
        self::assertSame('pending_manual_review', $items[0]['status']);
        self::assertTrue($items[0]['execution_ready']);
        self::assertSame('pending', $items[0]['human_confirmation_status']);
        self::assertNotEmpty($items[0]['evidence_refs']);
        self::assertSame('pending_manual_review', $items[1]['status']);
        self::assertTrue($items[1]['execution_ready']);
        self::assertContains('competitor', $items[1]['required_evidence']);
        self::assertSame('blocked_by_data_gap', $items[2]['status']);
        self::assertFalse($items[2]['execution_ready']);
        self::assertSame('blocked', $items[2]['human_confirmation_status']);
        self::assertNotEmpty($items[2]['missing_evidence']);
    }

    public function testOtaDiagnosisActionBlocksWhenRequiredEvidenceIsMissing(): void
    {
        $controller = $this->controller();
        $sources = $this->invokeNonPublic($controller, 'buildOtaDiagnosisEvidenceSources', [[
            'online_rows' => [[
                'id' => 11,
                'source' => 'ctrip',
                'data_type' => 'traffic',
                'compare_type' => 'self',
                'data_date' => '2026-05-24',
                'list_exposure' => 1000,
                'detail_visitors' => 40,
            ]],
        ], [
            'list_exposure' => 1000,
            'detail_visitors' => 40,
        ]]);

        $items = $this->invokeNonPublic($controller, 'buildOtaDiagnosisActionItems', [[
            '复核OTA广告投放词、出价和落地房型。',
        ], $sources]);

        self::assertSame('blocked_by_insufficient_evidence', $items[0]['status']);
        self::assertFalse($items[0]['execution_ready']);
        self::assertContains('advertising', $items[0]['required_evidence']);
        self::assertSame('missing_advertising_evidence', $items[0]['missing_evidence'][0]['code']);
        self::assertSame('blocked', $items[0]['human_confirmation_status']);
    }

    public function testOtaDiagnosisDecisionGateRequiresVerifiedQualityAndReadback(): void
    {
        $controller = $this->controller();

        self::assertTrue($this->invokeNonPublic($controller, 'isOtaDiagnosisDecisionEligibleRow', [[
            'readback_verified' => 1,
            'validation_status' => 'normal',
        ]]));
        self::assertFalse($this->invokeNonPublic($controller, 'isOtaDiagnosisDecisionEligibleRow', [[
            'readback_verified' => 1,
            'validation_status' => 'stale',
        ]]));
        self::assertFalse($this->invokeNonPublic($controller, 'isOtaDiagnosisDecisionEligibleRow', [[
            'readback_verified' => 0,
            'validation_status' => 'verified',
        ]]));
    }

    public function testOtaDiagnosisEvidenceUsesLatestEligibleRowsAndCarriesTraceMetadata(): void
    {
        $controller = $this->controller();
        $sources = $this->invokeNonPublic($controller, 'buildOtaDiagnosisEvidenceSources', [[
            'decision_quality' => ['gate' => 'eligible_rows_only'],
            'decision_eligible_online_rows' => [
                [
                    'id' => 1,
                    'source' => 'ctrip',
                    'hotel_id' => '1001',
                    'data_type' => 'traffic',
                    'data_date' => '2026-05-20',
                    'validation_status' => 'normal',
                    'readback_verified' => 1,
                ],
                [
                    'id' => 2,
                    'source' => 'ctrip',
                    'hotel_id' => '1001',
                    'data_type' => 'traffic',
                    'data_date' => '2026-05-24',
                    'validation_status' => 'verified',
                    'readback_verified' => 1,
                    'readback_verified_at' => '2026-05-24 10:01:00',
                    'source_trace_id' => 'trace-safe-2',
                    'create_time' => '2026-05-24 10:00:00',
                    'raw_data' => json_encode([
                        'capture_meta' => [
                            'source_method' => 'local_browser_profile',
                            'source_url' => 'https://hotels.ctrip.com/hotels/1001.html',
                            'evidence_asset_ref' => 'local-evidence://capture-2',
                        ],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ],
            ],
            'excluded_online_rows' => [[
                'id' => 3,
                'source' => 'ctrip',
                'hotel_id' => '1001',
                'data_type' => 'traffic',
                'data_date' => '2026-05-25',
                'validation_status' => 'stale',
                'readback_verified' => 1,
            ]],
        ], []]);

        $byRef = array_column($sources, null, 'ref');
        self::assertSame('verified', $byRef['online_daily_data#2']['quality_status']);
        self::assertSame('trace-safe-2', $byRef['online_daily_data#2']['source_trace_id']);
        self::assertSame('local_browser_profile', $byRef['online_daily_data#2']['source_method']);
        self::assertSame('https://hotels.ctrip.com/hotels/1001.html', $byRef['online_daily_data#2']['source_url']);
        self::assertTrue($byRef['online_daily_data#2']['decision_eligible']);
        self::assertTrue($byRef['online_daily_data_excluded#3']['excluded_from_decision']);
        self::assertFalse($byRef['online_daily_data_excluded#3']['decision_eligible']);
        self::assertSame('stale', $byRef['online_daily_data_excluded#3']['quality_status']);
        self::assertSame([], $byRef['online_daily_data_excluded#3']['metrics']);
    }

    public function testOtaDiagnosisUsesAdvertisingAndQualityWithoutCommentDependency(): void
    {
        $controller = $this->controller();
        $dataSet = [
            'hotel' => ['id' => 7, 'name' => 'Hotel Alpha'],
            'online_rows' => [
                [
                    'id' => 41,
                    'source' => 'ctrip',
                    'data_type' => 'business',
                    'data_date' => '2026-05-27',
                    'hotel_name' => 'Hotel Alpha',
                    'amount' => 1200,
                    'quantity' => 6,
                    'book_order_num' => 4,
                    'raw_data' => '{}',
                ],
                [
                    'id' => 42,
                    'source' => 'ctrip',
                    'data_type' => 'advertising',
                    'data_date' => '2026-05-27',
                    'hotel_name' => 'Hotel Alpha',
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
                    'source' => 'ctrip',
                    'data_type' => 'quality',
                    'data_date' => '2026-05-27',
                    'hotel_name' => 'Hotel Alpha',
                    'data_value' => 88.6,
                    'raw_data' => json_encode(['serviceScore' => 92.5, 'psiScore' => 88.6], JSON_UNESCAPED_UNICODE),
                ],
            ],
            'daily_reports' => [],
            'competitor_prices' => [],
            'competitor_analyses' => [],
            'price_suggestions' => [],
            'sync_logs' => [['id' => 50, 'action' => 'sync', 'create_time' => '2026-05-27 10:00:00']],
        ];

        $result = $this->invokeNonPublic($controller, 'buildOtaDiagnosisResult', [
            $dataSet,
            7,
            '7',
            'Hotel Alpha',
            'ctrip',
            '2026-05-27',
            '2026-05-27',
            'all',
        ]);

        self::assertSame(1200.0, $result['metrics']['amount']);
        self::assertSame(6, $result['metrics']['quantity']);
        self::assertSame(4, $result['metrics']['book_order_num']);
        self::assertSame(256.75, $result['metrics']['advertising_spend']);
        self::assertSame(1888.0, $result['metrics']['advertising_order_amount']);
        self::assertSame(7.35, $result['metrics']['advertising_roas']);
        self::assertSame(88.6, $result['metrics']['avg_psi_score']);
        self::assertSame(92.5, $result['metrics']['avg_service_score']);

        self::assertTrue($result['data_summary']['has_advertising_data']);
        self::assertTrue($result['data_summary']['has_service_quality_data']);
        self::assertFalse($result['data_summary']['has_comment_data']);
        self::assertArrayHasKey('advertising_analysis', $result['diagnosis']);
        self::assertArrayHasKey('service_quality_analysis', $result['diagnosis']);
        self::assertSame('', $result['diagnosis']['comment_analysis']);
        self::assertStringNotContainsString('comment', strtolower(implode(' ', $result['diagnosis']['actions'])));

        self::assertArrayHasKey('diagnosis_sections', $result);
        $sectionKeys = array_column($result['diagnosis_sections'], 'key');
        self::assertContains('advertising_efficiency', $sectionKeys);
        self::assertContains('service_quality', $sectionKeys);
        self::assertNotContains('comment', $sectionKeys);
        self::assertNotContains('review', $sectionKeys);
    }

    public function testOtaDiagnosisKeepsMissingMetricsNullAndRealZeroObservable(): void
    {
        $controller = $this->controller();
        $baseDataSet = [
            'hotel' => ['id' => 7, 'name' => 'Hotel Alpha'],
            'daily_reports' => [],
            'competitor_prices' => [],
            'competitor_analyses' => [],
            'price_suggestions' => [],
            'sync_logs' => [],
        ];
        $arguments = [7, '7', 'Hotel Alpha', 'ctrip', '2026-07-13', '2026-07-13', 'all'];

        $missing = $this->invokeNonPublic($controller, 'buildOtaDiagnosisResult', [array_merge($baseDataSet, [
            'online_rows' => [[
                'id' => 71,
                'source' => 'ctrip',
                'data_type' => 'business',
                'data_date' => '2026-07-13',
                'hotel_name' => 'Hotel Alpha',
                'amount' => null,
                'quantity' => null,
                'book_order_num' => null,
                'data_value' => null,
                'raw_data' => '{}',
            ]],
        ]), ...$arguments]);

        self::assertNull($missing['metrics']['amount']);
        self::assertNull($missing['metrics']['quantity']);
        self::assertNull($missing['metrics']['list_exposure']);
        self::assertContains('metric_missing:amount', $missing['data_gaps']);
        self::assertStringContainsString('核心指标未返回', implode(' ', $missing['source_summary']['data_anomalies']));
        self::assertStringNotContainsString('全指标为 0', implode(' ', $missing['source_summary']['data_anomalies']));
        self::assertStringContainsString('未返回', implode(' ', $missing['diagnosis']['data_overview']));

        $zero = $this->invokeNonPublic($controller, 'buildOtaDiagnosisResult', [array_merge($baseDataSet, [
            'online_rows' => [[
                'id' => 72,
                'source' => 'ctrip',
                'data_type' => 'business',
                'data_date' => '2026-07-13',
                'hotel_name' => 'Hotel Alpha',
                'amount' => 0,
                'quantity' => 0,
                'book_order_num' => 0,
                'data_value' => 0,
                'raw_data' => json_encode([
                    'row' => [
                        'amount' => 0,
                        'quantity' => 0,
                        'book_order_num' => 0,
                    ],
                    'field_facts' => [
                        ['metric_key' => 'order_amount', 'normalized_field' => 'amount', 'status' => 'captured', 'stored_value_present' => true],
                        ['metric_key' => 'room_nights', 'normalized_field' => 'quantity', 'status' => 'captured', 'stored_value_present' => true],
                        ['metric_key' => 'order_count', 'normalized_field' => 'book_order_num', 'status' => 'captured', 'stored_value_present' => true],
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ]],
        ]), ...$arguments]);

        self::assertSame(0.0, $zero['metrics']['amount']);
        self::assertSame(0, $zero['metrics']['quantity']);
        self::assertStringContainsString('全指标为 0', implode(' ', $zero['source_summary']['data_anomalies']));
    }

    public function testOtaDiagnosisDoesNotTreatSupplementalDefaultZerosAsCoreEvidence(): void
    {
        $controller = $this->controller();
        $result = $this->invokeNonPublic($controller, 'buildOtaDiagnosisResult', [[
            'hotel' => ['id' => 80, 'name' => '敦煌漠蓝新'],
            'online_rows' => [[
                'id' => 901,
                'source' => 'meituan',
                'data_type' => 'traffic_forecast',
                'data_date' => '2026-07-14',
                'hotel_name' => '敦煌漠蓝新',
                'amount' => 0,
                'quantity' => 0,
                'book_order_num' => 0,
                'list_exposure' => 0,
                'detail_exposure' => 0,
                'order_filling_num' => 0,
                'order_submit_num' => 0,
                'data_value' => 52,
                'raw_data' => json_encode(['row' => ['forecast_type' => 'next_7_days', 'data_value' => 52]], JSON_UNESCAPED_UNICODE),
            ]],
            'daily_reports' => [],
            'competitor_prices' => [],
            'competitor_analyses' => [],
            'price_suggestions' => [],
            'sync_logs' => [],
        ], 80, '80', '敦煌漠蓝新', 'meituan', '2026-07-14', '2026-07-14', 'all']);

        self::assertNull($result['metrics']['amount']);
        self::assertNull($result['metrics']['list_exposure']);
        self::assertContains('metric_missing:amount', $result['data_gaps']);
        self::assertContains('metric_missing:list_exposure', $result['data_gaps']);
        self::assertFalse($result['data_summary']['core_metrics_complete']);
        self::assertNotEmpty($result['blocking_data_gaps']);
    }

    public function testOtaDiagnosisPromptAndParserUseAdvertisingQualitySchema(): void
    {
        $controller = $this->controller();
        $prompt = $this->invokeNonPublic($controller, 'buildOtaDiagnosisPrompt', [[
            'metrics' => [
                'advertising_spend' => 256.75,
                'advertising_roas' => 7.35,
                'avg_psi_score' => 88.6,
            ],
        ]]);

        self::assertStringContainsString('advertising_analysis', $prompt);
        self::assertStringContainsString('service_quality_analysis', $prompt);
        self::assertStringContainsString('actions 允许为空数组', $prompt);
        self::assertStringContainsString('不能补0或猜测', $prompt);
        self::assertStringNotContainsString('comment_analysis', $prompt);

        $parsed = $this->invokeNonPublic($controller, 'parseOtaDiagnosisResult', [json_encode([
            'summary' => 'ok',
            'advertising_analysis' => 'ads ok',
            'service_quality_analysis' => 'quality ok',
            'comment_analysis' => 'legacy comment text must be ignored',
            'actions' => [],
        ], JSON_UNESCAPED_UNICODE)]);

        self::assertSame('ads ok', $parsed['advertising_analysis']);
        self::assertSame('quality ok', $parsed['service_quality_analysis']);
        self::assertSame('', $parsed['comment_analysis']);
    }

    /**
     * 覆盖 normalizeRequestedModelKey：
     * 验证默认模型、Pro 模式、历史别名和未知模型透传。
     */
    public function testOtaEvidenceReportPayloadKeepsTraceableActionReferences(): void
    {
        $controller = $this->controller();
        $result = [
            'date_range' => ['start_date' => '2026-05-01', 'end_date' => '2026-05-02'],
            'data_summary' => ['source_counts' => ['online_daily_data' => 1]],
            'core_conclusion' => 'Need action',
            'main_problems' => ['traffic low'],
            'possible_reasons' => ['price high'],
            'action_items' => [[
                'title' => 'Improve traffic conversion',
                'evidence_refs' => ['online_daily_data#10'],
            ]],
            'evidence_sources' => [[
                'ref' => 'online_daily_data#10',
                'tags' => ['traffic', 'order'],
            ]],
        ];

        $report = $this->invokeNonPublic($controller, 'buildOtaEvidenceReport', [$result]);
        self::assertSame('daily_diagnosis_action_list', $report['report_type']);
        self::assertSame('database_only', $report['source_policy']);
        self::assertSame(['online_daily_data' => 1], $report['source_counts']);
        self::assertSame('Need action', $report['diagnosis']['summary']);
        self::assertSame($result['action_items'], $report['action_items']);

        $preview = $this->invokeNonPublic($controller, 'buildOtaEvidenceMetricPreview', [[
            'amount' => 1200,
            'quantity' => 5,
            'list_exposure' => 1000,
            'unsafe' => 'secret',
        ]]);
        self::assertSame(['amount' => 1200, 'quantity' => 5, 'list_exposure' => 1000], $preview);

        $refs = $this->invokeNonPublic($controller, 'selectOtaEvidenceRefsForAction', [
            'Improve traffic conversion',
            [
                ['ref' => 'online_daily_data#10', 'tags' => ['traffic'], 'decision_eligible' => true],
                ['ref' => 'competitor_price_log#2', 'tags' => ['price'], 'decision_eligible' => false],
            ],
        ]);
        self::assertSame(['online_daily_data#10'], $refs);
    }

    public function testLegacyCompetitorPriceIsReferenceOnlyAndCannotUnlockPriceAction(): void
    {
        $controller = $this->controller();
        $sources = $this->invokeNonPublic($controller, 'buildOtaDiagnosisEvidenceSources', [[
            'competitor_prices' => [[
                'id' => 90,
                'platform' => 'ctrip',
                'price' => 99,
                'fetch_time' => '2026-05-24 10:00:00',
            ]],
        ], []]);
        $byRef = array_column($sources, null, 'ref');

        self::assertFalse($byRef['competitor_price_log#90']['decision_eligible']);
        self::assertTrue($byRef['competitor_price_log#90']['excluded_from_decision']);
        self::assertSame([], $byRef['competitor_price_log#90']['metrics']);

        $items = $this->invokeNonPublic($controller, 'buildOtaDiagnosisActionItems', [[
            '对比竞对价格后调整本店房价',
        ], $sources]);
        self::assertFalse($items[0]['execution_ready']);
        self::assertContains('missing_competitor_evidence', array_column($items[0]['missing_evidence'], 'code'));
    }

    public function testOtaDiagnosisNoDataResultKeepsEvidenceGapsAndActionItems(): void
    {
        $controller = $this->controller();

        $result = $this->invokeNonPublic($controller, 'buildOtaDiagnosisNoDataResult', [[
            'hotel' => ['id' => '1', 'name' => '测试酒店'],
            'sync_logs' => [['id' => 1]],
            'last_sync_time' => '',
        ], '1', '测试酒店', 'ctrip', '2026-06-12', '2026-06-12']);

        self::assertFalse($result['data_summary']['has_ota_data']);
        self::assertSame('database_only_no_synthetic_conclusion', $result['source_policy']);
        self::assertSame('ota_same_period_source_rows_missing', $result['data_gaps'][0]['code']);
        self::assertSame('ota_no_data_scope', $result['evidence_sources'][0]['ref']);
        self::assertSame('blocked_by_missing_ota_data', $result['action_items'][0]['status']);
        self::assertContains('ota_no_data_scope', $result['action_items'][0]['evidence_refs']);
        self::assertSame($result['data_gaps'], $result['evidence_report']['data_gaps']);
        self::assertSame('low', $result['ai_governance']['confidence_level']);
        self::assertTrue($result['ai_governance']['human_confirmation_required']);
        self::assertSame('blocked_by_data', $result['decision_closure']['status']);
        self::assertFalse($result['decision_closure']['data_evidence_input']['enough_for_executable_actions']);
        self::assertSame(1, $result['decision_closure']['suggested_actions']['blocked_count']);
        self::assertSame('blocked_by_data', $result['evidence_report']['decision_closure']['status']);
        self::assertStringContainsString('不能生成可信经营诊断', $result['core_conclusion']);
    }

    public function testOtaDiagnosisLatestAvailableDataBlocksExecutionActions(): void
    {
        $controller = $this->controller();

        $result = $this->invokeNonPublic($controller, 'blockOtaDiagnosisActionsForLatestAvailableData', [[
            'date_range' => ['start_date' => '2026-06-14', 'end_date' => '2026-06-14'],
            'data_summary' => ['used_latest_available_data' => true],
            'data_gaps' => [],
            'evidence_sources' => [[
                'ref' => 'online_daily_data#10',
                'table' => 'online_daily_data',
            ]],
            'action_items' => [[
                'id' => 'ota_action_1',
                'action' => 'review price',
                'status' => 'pending_manual_review',
                'evidence_refs' => ['online_daily_data#10'],
            ]],
        ], '2026-06-12', '2026-06-12', '2026-06-14', '2026-06-14']);

        self::assertSame('database_only_latest_available_reference_not_execution_ready', $result['source_policy']);
        self::assertFalse($result['data_summary']['target_date_execution_ready']);
        self::assertSame('ota_requested_period_source_rows_missing_used_latest_available', $result['data_gaps'][0]['code']);
        self::assertSame('ota_latest_available_not_target_date', $result['evidence_sources'][1]['ref']);
        self::assertSame('blocked_by_non_target_date_data', $result['action_items'][0]['status']);
        self::assertSame('pending_manual_review', $result['action_items'][0]['original_status']);
        self::assertContains('ota_latest_available_not_target_date', $result['action_items'][0]['evidence_refs']);

        $report = $this->invokeNonPublic($controller, 'buildOtaEvidenceReport', [$result]);
        self::assertSame('database_only_latest_available_reference_not_execution_ready', $report['source_policy']);
        self::assertSame($result['data_gaps'], $report['data_gaps']);

        $closure = $this->invokeNonPublic($controller, 'buildAiDecisionClosure', [$result]);
        self::assertSame('blocked_by_data', $closure['status']);
        self::assertFalse($closure['data_evidence_input']['enough_for_executable_actions']);
        self::assertSame('missing_target_date_ota_evidence', $closure['suggested_actions']['items'][0]['missing_evidence'][0]['code']);
    }

    public function testOtaDiagnosisHealthyCoreMetricsCanFinishAsNoAction(): void
    {
        $controller = $this->controller();
        $actions = $this->invokeNonPublic($controller, 'buildOtaDiagnosisActions', [
            true,
            true,
            false,
            false,
            [
                'list_exposure' => 1000,
                'detail_visitors' => 120,
                'detail_rate' => 12.0,
                'order_rate' => 8.0,
            ],
            ['metric_missing:advertising_spend'],
        ]);
        self::assertSame([], $actions, 'Competitor presence and optional fields must not manufacture an action.');

        $closure = $this->invokeNonPublic($controller, 'buildAiDecisionClosure', [[
            'action_items' => [],
            'data_gaps' => ['metric_missing:advertising_spend'],
            'main_problems' => [],
            'data_summary' => ['source_counts' => ['online_rows' => 2]],
            'evidence_sources' => [[
                'ref' => 'online_daily_data#1',
                'table' => 'online_daily_data',
            ]],
        ]]);

        self::assertSame('no_action', $closure['status']);
        self::assertFalse($closure['blocked_state']['is_blocked']);
        self::assertTrue($closure['data_evidence_input']['enough_for_decision']);
        self::assertFalse($closure['data_evidence_input']['enough_for_executable_actions']);
        self::assertFalse($closure['human_confirmation']['required']);
        self::assertSame('not_required', $closure['human_confirmation']['status']);
        self::assertSame('metric_missing:advertising_spend', $closure['data_evidence_input']['optional_data_gaps'][0]['code']);
    }

    public function testOtaDiagnosisCoreGapCanNeverBecomeNoAction(): void
    {
        $controller = $this->controller();
        $closure = $this->invokeNonPublic($controller, 'buildAiDecisionClosure', [[
            'action_items' => [],
            'data_gaps' => ['metric_missing:book_order_num'],
            'main_problems' => [],
            'data_summary' => ['source_counts' => ['online_rows' => 1]],
        ]]);

        self::assertSame('blocked_by_data', $closure['status']);
        self::assertTrue($closure['blocked_state']['is_blocked']);
        self::assertSame('metric_missing:book_order_num', $closure['data_evidence_input']['blocking_data_gaps'][0]['code']);

        $final = $this->invokeNonPublic($controller, 'finalizeOtaDiagnosisDecision', [[
            'priority' => 'high',
            'action_items' => [],
            'data_gaps' => ['metric_missing:book_order_num'],
            'main_problems' => [],
            'data_summary' => ['source_counts' => ['online_rows' => 1]],
            'diagnosis' => ['summary' => '核心订单证据缺失', 'actions' => []],
        ]]);
        self::assertSame('blocked_by_data', $final['decision_status']);
        self::assertSame('none', $final['priority']);
    }

    public function testOtaDiagnosisExecutionIntentUsesSavedEvidenceWithoutInventingTargetDelta(): void
    {
        $controller = $this->controller();
        $input = $this->invokeNonPublic($controller, 'buildOtaDiagnosisExecutionIntentInput', [[
            'hotel' => ['id' => 80],
            'platform' => 'meituan',
            'date_range' => ['start_date' => '2026-07-14', 'end_date' => '2026-07-14'],
            'decision_status' => 'action_required',
            'priority' => 'high',
            'metrics' => [
                'list_exposure' => 1000,
                'detail_visitors' => 20,
                'detail_rate' => 2.0,
                'book_order_num' => 0,
            ],
            'evidence_sources' => [[
                'ref' => 'online_daily_data#901',
                'table' => 'online_daily_data',
                'record_id' => 901,
            ]],
            'derived_metric_lineage' => [[
                'metric' => 'detail_rate',
                'formula' => 'detail_visitors / list_exposure * 100',
            ]],
            'core_conclusion' => '曝光到访问转化偏低',
        ], [
            'id' => 'ota_action_1',
            'action' => '优先优化列表页主图、标题卖点和页面信息呈现，提升曝光到访问转化。',
            'status' => 'pending_manual_review',
            'execution_ready' => true,
            'can_request_execution_intent' => true,
            'evidence_refs' => ['online_daily_data#901'],
        ], 77, 80, [
            'assignee_id' => 9,
            'due_at' => '2099-07-18T18:00',
            'review_at' => '2099-07-19T10:00',
        ]]);

        self::assertSame('ota_diagnosis_saved', $input['source_module']);
        self::assertSame(77, $input['source_record_id']);
        self::assertSame('meituan', $input['platform']);
        self::assertSame('campaign', $input['object_type']);
        self::assertSame('listing_conversion_optimization', $input['action_type']);
        self::assertSame(0, $input['current_value']['book_order_num'], 'A verified zero must remain observable.');
        self::assertSame('target_not_quantified_until_manual_confirmation', $input['target_value']['measurement_policy']);
        self::assertArrayNotHasKey('expected_delta', $input);
        self::assertSame('not_quantified', $input['evidence']['expected_delta_status']);
        self::assertSame(['online_daily_data#901'], $input['evidence']['evidence_refs']);
        self::assertSame(9, $input['target_value']['assignee_id']);
        self::assertSame('2099-07-18 18:00:00', $input['target_value']['due_at']);
        self::assertSame('2099-07-19 10:00:00', $input['target_value']['review_at']);
        self::assertSame($input['target_value']['workflow_schedule'], $input['evidence']['workflow_schedule']);
    }

    public function testOtaDiagnosisExecutionScheduleRequiresOwnerAndOrderedReviewTime(): void
    {
        $controller = $this->controller();
        $valid = $this->invokeNonPublic($controller, 'normalizeOtaDiagnosisExecutionSchedule', [[
            'assignee_id' => '7',
            'due_at' => '2099-07-18T18:00',
            'review_at' => '2099-07-19 09:30:00',
        ]]);
        self::assertSame(7, $valid['assignee_id']);
        self::assertSame('2099-07-18 18:00:00', $valid['due_at']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('review_at must not be earlier than due_at');
        $this->invokeNonPublic($controller, 'normalizeOtaDiagnosisExecutionSchedule', [[
            'assignee_id' => 7,
            'due_at' => '2099-07-20 10:00:00',
            'review_at' => '2099-07-19 10:00:00',
        ]]);
    }

    public function testOtaDiagnosisSupersedeScopeUsesRequestedDatesAndSnapshotKeepsRecordStatus(): void
    {
        $controller = $this->controller();
        $range = $this->invokeNonPublic($controller, 'normalizeOtaDiagnosisScopeDateRange', [[
            'end_date' => '2026-07-14',
            'start_date' => '2026-07-14',
            'ignored' => 'does-not-affect-scope',
        ]]);
        self::assertSame([
            'start_date' => '2026-07-14',
            'end_date' => '2026-07-14',
        ], $range);

        $snapshot = $this->invokeNonPublic($controller, 'buildOtaDiagnosisSnapshot', [[
            'platform' => 'meituan',
            'date_range' => ['start_date' => '2026-07-15', 'end_date' => '2026-07-15'],
            'requested_date_range' => ['start_date' => '2026-07-14', 'end_date' => '2026-07-14'],
            'record_status' => 'superseded',
            'superseded_by' => ['log_id' => 211],
            'saved_record' => ['id' => 210, 'status' => 'superseded'],
            'unsafe_field' => 'must-not-persist',
        ]]);

        self::assertSame('superseded', $snapshot['record_status']);
        self::assertSame(211, $snapshot['superseded_by']['log_id']);
        self::assertSame('2026-07-14', $snapshot['requested_date_range']['start_date']);
        self::assertArrayNotHasKey('unsafe_field', $snapshot);
    }

    public function testNormalizeRequestedModelKeyCoversDefaultAliasesAndFallback(): void
    {
        $controller = $this->controller();

        self::assertSame('deepseek_chat', $this->invokeNonPublic($controller, 'normalizeRequestedModelKey', ['', []]));
        self::assertSame('deepseek_reasoner', $this->invokeNonPublic($controller, 'normalizeRequestedModelKey', ['', ['model_mode' => 'pro']]));
        self::assertSame('deepseek_reasoner', $this->invokeNonPublic($controller, 'normalizeRequestedModelKey', ['deepseek-v4-pro', []]));
        self::assertSame('deepseek_chat', $this->invokeNonPublic($controller, 'normalizeRequestedModelKey', ['deepseek_v4_default', ['model_mode' => 'flash']]));
        self::assertSame('custom_model', $this->invokeNonPublic($controller, 'normalizeRequestedModelKey', ['custom_model', []]));
    }

    /**
     * 覆盖 buildLlmDebug/buildLlmSuccessDebug/safeResponsePreview/sanitizeLlmErrorMessage：
     * 验证调试结构、密钥脱敏、响应预览截断。
     */
    public function testLlmDebugSanitizesSecretsAndKeepsKeyMetadata(): void
    {
        $controller = $this->controller();
        $config = [
            'provider' => 'deepseek',
            'model_key' => 'deepseek_chat',
            'model' => 'deepseek-chat',
            'source' => 'db',
        ];

        $debug = $this->invokeNonPublic($controller, 'buildLlmDebug', [
            'request_failed',
            $config,
            401,
            'Bearer live-token-123456 failed',
            'hello prompt',
            str_repeat('r', 600),
            'api_key=sk-abcdefghijklmnopqrstuvwxyz; cookie=sessionid',
            ['selected_hotel_count' => 3],
            2048,
        ]);

        self::assertSame('request_failed', $debug['error_type']);
        self::assertSame(401, $debug['debug']['http_status']);
        self::assertSame(3, $debug['debug']['selected_hotel_count']);
        self::assertSame(2048, $debug['debug']['request_payload_size']);
        self::assertStringNotContainsString('live-token-123456', $debug['debug']['curl_error']);
        self::assertStringNotContainsString('sk-abcdefghijklmnopqrstuvwxyz', $debug['debug']['error_message']);
        self::assertStringNotContainsString('sessionid', $debug['debug']['error_message']);
        self::assertSame(500, mb_strlen($debug['debug']['response_preview']));

        $success = $this->invokeNonPublic($controller, 'buildLlmSuccessDebug', [
            $config,
            200,
            'prompt',
            ['prompt_length' => 99],
            128,
        ]);
        self::assertSame('deepseek', $success['provider']);
        self::assertSame(99, $success['prompt_length']);
        self::assertSame(128, $success['request_payload_size']);
    }

    /**
     * 覆盖 buildCapturedOtaSummary/sanitizeCapturedOtaMetrics/readCapturedNullableMetric/recordCapturedFlowQuality：
     * 验证 OTA 抓取摘要的汇总、排序、截断和数据质量统计。
     */
    public function testBuildCapturedOtaSummaryAggregatesSortsAndTruncates(): void
    {
        $controller = $this->controller();
        $hotels = [
            [
                'hotel_id' => 'h1',
                'hotel_name' => 'Hotel 1',
                'platform' => 'ctrip',
                'start_date' => '2026-05-01',
                'end_date' => '2026-05-02',
                'source_method' => 'local_browser_profile',
                'collected_at' => '2026-05-02 09:30:15',
                'stored' => true,
                'readback_verified' => true,
                'validation_status' => 'verified',
                'metrics' => [
                    'room_nights' => 10,
                    'revenue' => 2000,
                    'sales' => 2100,
                    'exposure' => 1000,
                    'visitors' => 200,
                    'orders' => 20,
                    'score' => 4.8,
                    'browse_rate' => 20,
                ],
            ],
            [
                'hotel_id' => 'h2',
                'hotel_name' => 'Hotel 2',
                'platform' => 'ctrip',
                'start_date' => '2026-05-01',
                'end_date' => '2026-05-02',
                'source_method' => 'local_browser_profile',
                'collected_at' => '2026-05-02 09:31:16',
                'stored' => true,
                'readback_verified' => true,
                'validation_status' => 'verified',
                'raw_metrics' => [
                    'room_nights' => 5,
                    'revenue' => 3000,
                    'exposure' => 0,
                    'visitors' => null,
                    'orders' => 10,
                    'score' => 4.6,
                ],
            ],
            ['name' => ''],
        ];

        $summary = $this->invokeNonPublic($controller, 'buildCapturedOtaSummary', [
            $hotels,
            'ctrip',
            'captured',
            '2026-05-01',
            '2026-05-02',
        ]);

        self::assertSame(3, $summary['input_hotel_count']);
        self::assertSame(2, $summary['hotel_count']);
        self::assertSame(15.0, $summary['totals']['room_nights']);
        self::assertSame(5000.0, $summary['totals']['room_revenue']);
        self::assertSame('h2', $summary['top_hotels_by_revenue'][0]['hotel_id']);
        self::assertSame(333.33, $summary['averages']['adr']);
        self::assertArrayHasKey('data_quality', $summary);

        $manyHotels = [];
        for ($i = 1; $i <= 51; $i++) {
            $manyHotels[] = [
                'hotel_id' => 'h' . $i,
                'hotel_name' => 'Hotel ' . $i,
                'platform' => 'ctrip',
                'start_date' => '2026-05-01',
                'end_date' => '2026-05-01',
                'source_method' => 'local_browser_profile',
                'collected_at' => '2026-05-01 09:30:15',
                'stored' => true,
                'readback_verified' => true,
                'validation_status' => 'verified',
                'revenue' => $i,
            ];
        }
        $truncated = $this->invokeNonPublic($controller, 'buildCapturedOtaSummary', [
            $manyHotels,
            'ctrip',
            'captured',
            '2026-05-01',
            '2026-05-01',
        ]);
        self::assertTrue($truncated['truncated']);
        self::assertSame(50, $truncated['hotel_count']);
        self::assertNull($truncated['totals']['room_nights']);
        self::assertNull($truncated['totals']['orders']);
        self::assertNull($truncated['averages']['adr']);
        self::assertSame(0, $truncated['metric_sample_counts']['room_nights']);
    }

    public function testCapturedOtaSummaryOnlyAggregatesVerifiedTraceableRows(): void
    {
        $controller = $this->controller();
        $verified = [
            'hotel_id' => 'verified-1',
            'hotel_name' => 'Verified Hotel',
            'platform' => 'ctrip',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-02',
            'source_method' => 'local_browser_profile',
            'collected_at' => '2026-05-02 09:30:15',
            'stored' => true,
            'readback_verified' => true,
            'validation_status' => 'verified',
            'raw_metrics' => [
                'room_nights' => 10,
                'revenue' => 2000,
                'sales' => 2100,
                'exposure' => 1000,
                'visitors' => 200,
                'orders' => 20,
            ],
        ];
        $verifiedZero = array_replace($verified, [
            'hotel_id' => 'verified-zero',
            'hotel_name' => 'Verified Zero Hotel',
            'collected_at' => '2026-05-02 09:31:16',
            'raw_metrics' => [
                'room_nights' => 0,
                'revenue' => 0,
                'sales' => 0,
                'exposure' => 0,
                'visitors' => 0,
                'orders' => 0,
            ],
        ]);
        $partial = array_replace($verified, [
            'hotel_id' => 'partial-1',
            'hotel_name' => 'Partial Hotel',
            'validation_status' => 'partial',
            'raw_metrics' => ['room_nights' => 999, 'revenue' => 999999],
        ]);
        $unverified = array_replace($verified, [
            'hotel_id' => 'unverified-1',
            'hotel_name' => 'Unverified Hotel',
            'readback_verified' => false,
            'validation_status' => 'unverified',
            'raw_metrics' => ['room_nights' => 888, 'revenue' => 888888],
        ]);
        $failed = array_replace($verified, [
            'hotel_id' => 'failed-1',
            'hotel_name' => 'Failed Hotel',
            'validation_status' => 'collection_failed',
            'failure_reason' => 'upstream_timeout',
            'raw_metrics' => ['room_nights' => 777, 'revenue' => 777777],
        ]);

        $summary = $this->invokeNonPublic($controller, 'buildCapturedOtaSummary', [[
            $verified,
            $verifiedZero,
            $partial,
            $unverified,
            $failed,
        ], 'ctrip', 'captured', '2026-05-01', '2026-05-02']);

        self::assertSame(5, $summary['input_hotel_count']);
        self::assertSame(2, $summary['hotel_count']);
        self::assertSame(3, $summary['excluded_hotel_count']);
        self::assertSame(10.0, $summary['totals']['room_nights']);
        self::assertSame(2000.0, $summary['totals']['room_revenue']);
        self::assertSame(2, $summary['metric_sample_counts']['room_revenue']);
        self::assertSame('partial', $summary['truth_context']['status']);
        self::assertSame('ota_channel', $summary['truth_context']['scope']);
        self::assertFalse($summary['truth_context']['whole_hotel_scope']);
        self::assertSame('partial', $summary['metric_truth']['room_revenue']['status']);
        self::assertSame(2, $summary['metric_truth']['room_revenue']['observed_count']);
        self::assertSame('verified', $summary['hotels'][1]['metric_truth']['room_revenue']['status']);
        self::assertSame('ctrip', $summary['hotels'][0]['metric_truth']['room_revenue']['platform']);
        self::assertSame('2026-05-01', $summary['hotels'][0]['metric_truth']['room_revenue']['date_range']['start_date']);
        self::assertSame('local_browser_profile', $summary['hotels'][0]['metric_truth']['room_revenue']['source_method']);
        self::assertSame('2026-05-02 09:30:15', $summary['hotels'][0]['metric_truth']['room_revenue']['collected_at']);
        self::assertTrue($summary['hotels'][0]['metric_truth']['room_revenue']['stored']);
        self::assertTrue($summary['hotels'][0]['metric_truth']['room_revenue']['readback_verified']);

        $excludedById = [];
        foreach ($summary['excluded'] as $row) {
            $excludedById[$row['hotel_id']] = $row;
        }
        self::assertSame('partial', $excludedById['partial-1']['truth_status']);
        self::assertSame('unverified', $excludedById['unverified-1']['truth_status']);
        self::assertSame('collection_failed', $excludedById['failed-1']['truth_status']);
        self::assertSame('upstream_timeout', $excludedById['failed-1']['failure_reason']);
        self::assertContains('readback_not_verified', $excludedById['unverified-1']['data_gaps']);
    }

    public function testCapturedOtaSummaryKeepsNullDistinctFromVerifiedZeroDuringAggregationAndSorting(): void
    {
        $controller = $this->controller();
        $truth = [
            'platform' => 'ctrip',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-01',
            'source_method' => 'local_browser_profile',
            'collected_at' => '2026-05-01 10:11:12',
            'stored' => true,
            'readback_verified' => true,
            'validation_status' => 'verified',
        ];
        $summary = $this->invokeNonPublic($controller, 'buildCapturedOtaSummary', [[
            array_replace($truth, [
                'hotel_id' => 'missing-revenue',
                'hotel_name' => 'Missing Revenue',
                'raw_metrics' => ['orders' => 1],
            ]),
            array_replace($truth, [
                'hotel_id' => 'verified-zero',
                'hotel_name' => 'Verified Zero',
                'raw_metrics' => ['revenue' => 0, 'orders' => 0],
            ]),
        ], 'ctrip', 'captured', '2026-05-01', '2026-05-01']);

        self::assertSame(0.0, $summary['totals']['room_revenue']);
        self::assertSame(1, $summary['metric_sample_counts']['room_revenue']);
        self::assertSame(1, $summary['metric_truth']['room_revenue']['observed_count']);
        self::assertSame('verified-zero', $summary['top_hotels_by_revenue'][0]['hotel_id']);
        self::assertSame('missing-revenue', $summary['top_hotels_by_revenue'][1]['hotel_id']);

        $noVerified = $this->invokeNonPublic($controller, 'buildCapturedOtaSummary', [[
            array_replace($truth, [
                'hotel_id' => 'failed-only',
                'hotel_name' => 'Failed Only',
                'validation_status' => 'collection_failed',
                'failure_reason' => 'capture_timeout',
                'raw_metrics' => ['revenue' => 999999],
            ]),
        ], 'ctrip', 'captured', '2026-05-01', '2026-05-01']);

        self::assertSame(0, $noVerified['hotel_count']);
        self::assertNull($noVerified['totals']['room_revenue']);
        self::assertSame(0, $noVerified['metric_sample_counts']['room_revenue']);
        self::assertSame('collection_failed', $noVerified['truth_context']['status']);
        self::assertSame('collection_failed', $noVerified['metric_truth']['room_revenue']['status']);
    }

    public function testCapturedOtaSummaryRequiresEveryTruthDimensionBeforeVerification(): void
    {
        $controller = $this->controller();
        $base = [
            'hotel_id' => 'complete-id',
            'hotel_name' => 'Complete Name',
            'platform' => 'ctrip',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-01',
            'source_method' => 'local_browser_profile',
            'collected_at' => '2026-05-01 10:11:12',
            'stored' => true,
            'readback_verified' => true,
            'validation_status' => 'verified',
            'raw_metrics' => ['room_nights' => 1, 'revenue' => 200, 'orders' => 1],
        ];
        $incompleteRows = [
            array_replace($base, ['hotel_id' => '']),
            array_replace($base, ['hotel_id' => 'missing-name', 'hotel_name' => '']),
            array_replace($base, ['hotel_id' => 'missing-platform', 'platform' => '']),
            array_replace($base, ['hotel_id' => 'wrong-platform', 'platform' => 'meituan']),
            array_replace($base, ['hotel_id' => 'missing-date', 'start_date' => '', 'end_date' => '']),
            array_replace($base, ['hotel_id' => 'missing-source', 'source_method' => '']),
            array_replace($base, ['hotel_id' => 'manual-source', 'source_method' => 'manual_import']),
            array_replace($base, ['hotel_id' => 'imprecise-time', 'collected_at' => '2026-05-01']),
            array_replace($base, ['hotel_id' => 'not-stored', 'stored' => false]),
            array_replace($base, ['hotel_id' => 'not-read-back', 'readback_verified' => false]),
        ];

        $summary = $this->invokeNonPublic($controller, 'buildCapturedOtaSummary', [
            $incompleteRows,
            'ctrip',
            'captured',
            '2026-05-01',
            '2026-05-01',
        ]);

        self::assertSame(0, $summary['hotel_count']);
        self::assertSame(10, $summary['excluded_hotel_count']);
        self::assertNull($summary['totals']['room_revenue']);
        self::assertSame('partial', $summary['truth_context']['status']);
        self::assertSame('partial', $summary['metric_truth']['room_revenue']['status']);
        self::assertSame([], $summary['top_hotels_by_revenue']);

        $allGaps = [];
        foreach ($summary['excluded'] as $row) {
            $allGaps = array_merge($allGaps, $row['data_gaps']);
            self::assertFalse($row['metric_truth']['room_revenue']['decision_eligible']);
        }
        foreach ([
            'hotel_id_missing',
            'hotel_name_missing',
            'platform_missing',
            'platform_mismatch',
            'date_range_missing_or_invalid',
            'source_method_missing',
            'source_method_not_verified_online_capture',
            'collected_at_not_precise',
            'not_stored',
            'readback_not_verified',
        ] as $expectedGap) {
            self::assertContains($expectedGap, $allGaps);
        }
    }

    /**
     * 覆盖 sanitizeCapturedOtaMetrics/readCapturedNullableMetric/recordCapturedFlowQuality：
     * 验证指标白名单、数值精度、空值统计边界。
     */
    public function testCapturedMetricSanitizersHandleAllowedKeysAndMissingValues(): void
    {
        $controller = $this->controller();

        $safe = $this->invokeNonPublic($controller, 'sanitizeCapturedOtaMetrics', [[
            'revenue' => '123.45678',
            'views' => '',
            'orders' => null,
            'unsafe' => 999,
        ]]);

        self::assertSame(123.4568, $safe['revenue']);
        self::assertNull($safe['views']);
        self::assertNull($safe['orders']);
        self::assertArrayNotHasKey('unsafe', $safe);

        self::assertSame(123.4568, $this->invokeNonPublic($controller, 'readCapturedNullableMetric', [$safe, ['missing', 'revenue']]));
        self::assertNull($this->invokeNonPublic($controller, 'readCapturedNullableMetric', [$safe, ['views']]));

        $stats = ['views' => ['missing' => 0, 'zero' => 0]];
        $this->invokeNonPublic($controller, 'recordCapturedFlowQuality', [&$stats, 'views', null]);
        $this->invokeNonPublic($controller, 'recordCapturedFlowQuality', [&$stats, 'views', 0.0]);
        self::assertSame(['missing' => 1, 'zero' => 1], $stats['views']);
    }

    /**
     * 覆盖 textContainsAny/sanitizeReportList/sanitizeProblemHotels/parseProblemHotelString：
     * 验证文本命中、报告列表清洗、问题酒店结构化解析。
     */
    public function testReportTextAndProblemHotelSanitizers(): void
    {
        $controller = $this->controller();

        self::assertTrue($this->invokeNonPublic($controller, 'textContainsAny', ['OTA revenue dropped', ['revenue', 'score']]));
        self::assertFalse($this->invokeNonPublic($controller, 'textContainsAny', ['OTA revenue dropped', ['margin']]));

        $list = $this->invokeNonPublic($controller, 'sanitizeReportList', [[
            ['hotel' => 'A', 'issue' => 'low conversion'],
            ' keep ',
            '',
        ], 3]);
        self::assertCount(2, $list);
        self::assertStringContainsString('hotel: A', $list[0]);

        $hotels = $this->invokeNonPublic($controller, 'sanitizeProblemHotels', [[
            [
                'hotel_name' => 'Hotel A',
                'problem' => 'Low ADR',
                'key_metrics' => 'ADR 200; OCC 60%',
                'suggestion' => 'Adjust price',
            ],
            'hotel_name: Hotel B problem: Weak traffic key_metrics: views 10; orders 1 suggestion: Improve listing',
        ], 2]);

        self::assertCount(2, $hotels);
        self::assertSame('Hotel A', $hotels[0]['hotel_name']);
        self::assertContains('ADR 200', $hotels[0]['key_metrics']);
        self::assertSame('Hotel B', $hotels[1]['hotel_name']);
        self::assertSame('Weak traffic', $hotels[1]['problem']);
    }

    /**
     * 覆盖 topDimensionStats/average/percentRate/missingDates/parseOtaDiagnosisResult/parseCapturedOtaAnalysisResult/extractJsonObjectFromText：
     * 验证统计计算、缺失日期、LLM JSON 包裹文本解析。
     */
    public function testStatisticsAndJsonParsersCoverNormalAndFallbackInputs(): void
    {
        $controller = $this->controller();

        $top = $this->invokeNonPublic($controller, 'topDimensionStats', [[
            'low' => ['data_value' => 1],
            'high' => ['data_value' => 9],
        ]]);
        self::assertSame(['high', 'low'], array_keys($top));
        self::assertSame(2.5, $this->invokeNonPublic($controller, 'average', [[1, 2, 4.5]]));
        self::assertSame(25.0, $this->invokeNonPublic($controller, 'percentRate', [1.0, 4.0]));
        self::assertSame(0.0, $this->invokeNonPublic($controller, 'percentRate', [1.0, 0.0]));
        self::assertSame(
            ['2026-05-02'],
            $this->invokeNonPublic($controller, 'missingDates', ['2026-05-01', '2026-05-03', ['2026-05-01', '2026-05-03']])
        );

        $diagnosis = $this->invokeNonPublic($controller, 'parseOtaDiagnosisResult', [
            'prefix ```json {"summary":"ok","data_overview":["a"],"actions":["b"],"priority":"high"} ``` suffix',
        ]);
        self::assertSame('ok', $diagnosis['summary']);
        self::assertSame(['b'], $diagnosis['actions']);

        $captured = $this->invokeNonPublic($controller, 'parseCapturedOtaAnalysisResult', [
            '{"overall_conclusion":"ok","key_findings":["a"],"problem_hotels":[{"hotel_name":"A","problem":"B"}],"priority":"low"}',
        ]);
        self::assertSame('ok', $captured['overall_conclusion']);
        self::assertSame('A', $captured['problem_hotels'][0]['hotel_name']);

        $fallback = $this->invokeNonPublic($controller, 'parseCapturedOtaAnalysisResult', ['not-json']);
        self::assertSame('medium', $fallback['priority']);
        self::assertArrayHasKey('raw_text', $fallback);
    }

    /**
     * 覆盖 buildCapturedOtaPrompt：
     * 验证当前抓取数据分析提示词会显式整合知识库摘要，而不只把知识库混在原始 JSON 中。
     */
    public function testCapturedOtaPromptIncludesExplicitKnowledgeContext(): void
    {
        $controller = $this->controller();

        $prompt = $this->invokeNonPublic($controller, 'buildCapturedOtaPrompt', [[
            'scope' => ['platform' => 'ctrip', 'data_source' => 'captured'],
            'hotel_count' => 1,
            'knowledge_context' => [
                'status' => 'available',
                'items' => [[
                    'title' => '酒店OTA专业指标口径知识库',
                    'summary' => '分母为 0 或缺失时返回不可计算，不返回 0。',
                    'chunks' => ['诊断模板: 预订转化低先查房型、图片、退改、价格、点评和问答。'],
                ]],
            ],
        ]]);

        self::assertStringContainsString('知识库参考', $prompt);
        self::assertStringContainsString('酒店OTA专业指标口径知识库', $prompt);
        self::assertStringContainsString('分母为 0 或缺失时返回不可计算', $prompt);
        self::assertStringContainsString('异常描述必须优先写成数据口径提示或需复核提示', $prompt);
    }

    public function testCapturedOtaFinalPromptKeepsCtripChannelBoundary(): void
    {
        $controller = $this->controller();

        $prompt = $this->invokeNonPublic($controller, 'buildCapturedOtaFinalPrompt', [[
            'scope' => ['platform' => 'ctrip', 'data_source' => 'captured'],
            'hotel_count' => 1,
        ]]);

        self::assertStringContainsString('携程OTA渠道样本诊断报告', $prompt);
        self::assertStringContainsString('不得外推全酒店营收、全渠道需求或整体经营状况', $prompt);
        self::assertStringContainsString('建议不等于已执行', $prompt);
        self::assertStringNotContainsString('整体经营现状', $prompt);
    }

    /**
     * 覆盖 applyCapturedOtaDataQualityGuard：
     * 验证跨日统计窗口下不会把流量未更新写成严重采集异常。
     */
    public function testAiGovernancePayloadCitesKnowledgeAndRequiresManualReview(): void
    {
        $controller = $this->controller();

        $payload = $this->invokeNonPublic($controller, 'buildAiGovernancePayload', [
            'ota_diagnosis',
            [
                'platform' => 'ctrip',
                'date_range' => ['start_date' => '2026-05-24', 'end_date' => '2026-05-24'],
                'missing_sections' => ['competitor_prices'],
                'knowledge_context' => [
                    'status' => 'available',
                    'items' => [[
                        'source' => 'knowledge_units',
                        'id' => 7,
                        'title' => 'OTA metric knowledge',
                    ]],
                ],
                'evidence_sources' => [[
                    'ref' => 'online_daily_data#10',
                    'table' => 'online_daily_data',
                    'record_id' => 10,
                    'label' => 'ctrip traffic',
                ]],
                'action_items' => [[
                    'id' => 'ota_action_1',
                    'status' => 'pending_manual_review',
                    'evidence_refs' => ['online_daily_data#10'],
                ]],
            ],
            [
                'ok' => true,
                'provider' => 'deepseek',
                'model_key' => 'deepseek_chat',
                'model' => 'deepseek-chat',
                'data' => [
                    'governance' => [
                        'call_id' => 'llm_abc',
                        'prompt_version' => 'ota_diagnosis:v1',
                    ],
                ],
            ],
        ]);

        self::assertSame('ota_diagnosis', $payload['scenario']);
        self::assertSame('ota_diagnosis:v1', $payload['prompt_version']);
        self::assertSame('ota_diagnosis_governance_v1', $payload['evaluation_set']);
        self::assertSame('medium', $payload['confidence_level']);
        self::assertTrue($payload['low_confidence']);
        self::assertTrue($payload['human_confirmation_required']);
        self::assertSame('knowledge_units#7', $payload['knowledge_citations'][0]['ref']);
        self::assertSame('online_daily_data#10', $payload['evidence_refs'][0]);
        self::assertSame('llm_abc', $payload['model_call']['call_id']);
        self::assertSame('ai_model_call_logs', $payload['log_sink']);
    }

    public function testDirectPriceSuggestionApplyIsDisabledForManualExecutionBoundary(): void
    {
        $controller = $this->controller();

        $result = $this->invokeNonPublic($controller, 'applyPriceSuggestionById', [77, [
            'platform' => 'ctrip',
            'room_type_key' => 'RT-1001',
            'rate_plan_key' => 'BAR',
        ]]);

        self::assertFalse($result['ok']);
        self::assertSame(409, $result['code']);
        self::assertSame('direct_price_apply_disabled', $result['data']['reason']);
        self::assertSame(77, $result['data']['suggestion_id']);
        self::assertTrue($result['data']['advisory_only']);
        self::assertTrue($result['data']['manual_review_required']);
        self::assertFalse($result['data']['local_price_updated']);
        self::assertFalse($result['data']['auto_write_ota']);
        self::assertSame('/api/revenue-ai/price-suggestions/77/execution-intent', $result['data']['allowed_endpoint']);
        self::assertContains('update_room_type_base_price', $result['data']['forbidden_actions']);
        self::assertContains('ota_write', $result['data']['forbidden_actions']);
        self::assertStringContainsString('执行意图', $result['data']['next_action']);
    }

    public function testPriceSuggestionGenerationBlockedResultExposesCtripPreconditions(): void
    {
        $controller = $this->controller();

        $result = $this->invokeNonPublic($controller, 'buildPriceSuggestionGenerationBlockedResult', [
            'room_types_empty',
            64,
            '2026-06-28',
            [],
            '携程目标酒店暂无启用房型，不能生成待审调价建议。',
        ]);

        self::assertSame('blocked', $result['status']);
        self::assertSame('room_types_empty', $result['reason']);
        self::assertSame('ctrip_ota_channel', $result['source_scope']);
        self::assertSame(['ctrip'], $result['source_channels']);
        self::assertSame([64], $result['target_hotel_ids']);
        self::assertSame(['hotel_id' => 64, 'date' => '2026-06-28', 'status' => 0], $result['target_filter']);
        self::assertSame(0, $result['created_count']);
        self::assertFalse($result['can_generate_pending_suggestions']);
        self::assertTrue($result['advisory_only']);
        self::assertTrue($result['manual_review_required']);
        self::assertFalse($result['auto_write_ota']);
        self::assertContains('room_types_enabled', array_column($result['required_inputs'], 'code'));
        self::assertContains('floor_price_or_min_rate_guard', array_column($result['required_inputs'], 'code'));
        self::assertContains('demand_forecast', array_column($result['required_inputs'], 'code'));
        self::assertContains('competitor_price_samples', array_column($result['required_inputs'], 'code'));
    }

    public function testPriceSuggestionGenerationCreatedResultKeepsCtripReviewGate(): void
    {
        $controller = $this->controller();

        $result = $this->invokeNonPublic($controller, 'buildPriceSuggestionGenerationRuntimeResult', [
            64,
            '2026-06-28',
            [[
                'id' => 101,
                'hotel_id' => 64,
                'room_type_id' => 12,
                'status' => 1,
                'current_price' => 320,
                'suggested_price' => 352,
            ]],
            [],
            [
                'advisory_only' => true,
                'model' => 'advisory_revenue_pricing_v1',
            ],
        ]);

        self::assertSame('created', $result['status']);
        self::assertSame('price_suggestions_pending_review', $result['reason']);
        self::assertSame('ctrip_ota_channel', $result['source_scope']);
        self::assertSame(['ctrip'], $result['source_channels']);
        self::assertSame([64], $result['target_hotel_ids']);
        self::assertSame(['hotel_id' => 64, 'date' => '2026-06-28', 'status' => 1], $result['target_filter']);
        self::assertSame(1, $result['created_count']);
        self::assertTrue($result['can_generate_pending_suggestions']);
        self::assertTrue($result['advisory_only']);
        self::assertTrue($result['manual_review_required']);
        self::assertFalse($result['auto_write_ota']);
        self::assertSame('/api/revenue-ai/price-suggestions/{id}/review', $result['review_endpoint_base']);
        self::assertSame('/api/revenue-ai/price-suggestions/{id}/execution-intent', $result['execution_intent_endpoint_base']);
        self::assertSame('pending_manual_review', $result['ai_review_gate']['status']);
        self::assertSame('operation_execution_intent', $result['ai_review_gate']['required_before']);
        self::assertFalse($result['ai_review_gate']['auto_apply_ai_advice']);
        self::assertFalse($result['ai_review_gate']['operation_intake_allowed']);
        self::assertFalse($result['ai_review_gate']['auto_write_ota']);
        self::assertSame([], $result['required_inputs']);
    }

    public function testRoomTypePayloadRequiresManualPricingGuards(): void
    {
        $controller = $this->controller();

        $payload = $this->invokeNonPublic($controller, 'normalizeRoomTypePayload', [[
            'hotel_id' => 64,
            'name' => 'Deluxe King',
            'base_price' => '320.126',
            'min_price' => 260,
            'max_price' => 420,
            'room_count' => 12,
            'sort_order' => 2,
            'is_enabled' => 1,
        ]]);

        self::assertSame(64, $payload['hotel_id']);
        self::assertSame('Deluxe King', $payload['name']);
        self::assertSame(320.13, $payload['base_price']);
        self::assertSame(260.0, $payload['min_price']);
        self::assertSame(420.0, $payload['max_price']);
        self::assertSame(12, $payload['room_count']);
        self::assertSame(2, $payload['sort_order']);
        self::assertSame(1, $payload['is_enabled']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('min_price cannot be greater than base_price');
        $this->invokeNonPublic($controller, 'normalizeRoomTypePayload', [[
            'hotel_id' => 64,
            'name' => 'Invalid',
            'base_price' => 200,
            'min_price' => 260,
            'max_price' => 420,
        ]]);
    }

    public function testDemandForecastPayloadKeepsManualCtripPreflightBoundary(): void
    {
        $controller = $this->controller();

        $payload = $this->invokeNonPublic($controller, 'normalizeDemandForecastPayload', [[
            'hotel_id' => 64,
            'forecast_date' => '2026-06-28',
            'room_type_id' => 12,
            'predicted_occupancy' => '86.5',
            'predicted_demand' => '9',
            'confidence_percent' => 82,
            'forecast_method' => 3,
            'historical_data' => ['operator_note' => 'manual input'],
            'remark' => 'manual forecast',
        ]]);

        self::assertSame(64, $payload['hotel_id']);
        self::assertSame('2026-06-28', $payload['forecast_date']);
        self::assertSame(12, $payload['room_type_id']);
        self::assertSame(86.5, $payload['predicted_occupancy']);
        self::assertSame(9, $payload['predicted_demand']);
        self::assertSame(0.82, $payload['confidence_score']);
        self::assertSame('manual input', $payload['historical_data']['operator_note']);
        self::assertSame('manual_pricing_configuration', $payload['historical_data']['input_scope']);
        self::assertSame('ctrip_ota_channel', $payload['historical_data']['source_scope']);
        self::assertSame('ctrip_revenue_ai_pricing_generation', $payload['historical_data']['target_workflow']);
        self::assertSame('operator_provided', $payload['historical_data']['evidence_status']);
        self::assertFalse($payload['historical_data']['auto_write_ota']);
        self::assertSame('manual_demand_forecast', $payload['historical_data']['input_type']);
    }

    public function testDemandForecastPayloadRejectsMissingManualEvidenceFields(): void
    {
        $controller = $this->controller();
        $valid = [
            'hotel_id' => 64,
            'forecast_date' => '2026-06-28',
            'room_type_id' => 12,
            'forecast_method' => 3,
            'predicted_occupancy' => 86,
            'predicted_demand' => 9,
            'confidence_score' => 0.82,
        ];

        foreach ([
            'forecast_method' => 'forecast_method is required',
            'predicted_occupancy' => 'predicted_occupancy must be numeric',
            'predicted_demand' => 'predicted_demand must be numeric',
            'confidence_score' => 'confidence_score must be numeric',
        ] as $field => $message) {
            $payload = $valid;
            unset($payload[$field]);
            try {
                $this->invokeNonPublic($controller, 'normalizeDemandForecastPayload', [$payload]);
                self::fail($field . ' should be required');
            } catch (\InvalidArgumentException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
    }

    public function testDemandForecastPayloadRejectsMissingRoomTypeMapping(): void
    {
        $controller = $this->controller();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('room_type_id is required');
        $this->invokeNonPublic($controller, 'normalizeDemandForecastPayload', [[
            'hotel_id' => 64,
            'forecast_date' => '2026-06-28',
            'room_type_id' => 0,
            'predicted_occupancy' => 86,
        ]]);
    }

    public function testCtripCompetitorPricePayloadKeepsManualPreflightBoundary(): void
    {
        $controller = $this->controller();

        $payload = $this->invokeNonPublic($controller, 'normalizeCtripCompetitorPricePayload', [[
            'hotel_id' => 64,
            'analysis_date' => '2026-06-28',
            'room_type_id' => 12,
            'competitor_hotel_id' => 0,
            'competitor_name' => 'Manual competitor source',
            'our_price' => '320.128',
            'competitor_price' => 350,
            'ota_platform' => 1,
            'competitor_data' => ['sample_note' => 'ctrip page'],
        ]]);

        self::assertSame(64, $payload['hotel_id']);
        self::assertSame('2026-06-28', $payload['analysis_date']);
        self::assertSame(12, $payload['room_type_id']);
        self::assertSame(0, $payload['competitor_hotel_id']);
        self::assertSame(320.13, $payload['our_price']);
        self::assertSame(350.0, $payload['competitor_price']);
        self::assertSame(91.47, $payload['price_index']);
        self::assertSame(1, $payload['ota_platform']);
        self::assertSame('ctrip page', $payload['competitor_data']['sample_note']);
        self::assertSame('Manual competitor source', $payload['competitor_data']['competitor_name']);
        self::assertSame('manual_pricing_configuration', $payload['competitor_data']['input_scope']);
        self::assertSame('ctrip_ota_channel', $payload['competitor_data']['source_scope']);
        self::assertSame('ctrip_revenue_ai_pricing_generation', $payload['competitor_data']['target_workflow']);
        self::assertSame('operator_provided', $payload['competitor_data']['evidence_status']);
        self::assertFalse($payload['competitor_data']['auto_write_ota']);
        self::assertSame('manual_ctrip_competitor_price_sample', $payload['competitor_data']['input_type']);
    }

    public function testCtripCompetitorPricePayloadRejectsNonCtripPlatform(): void
    {
        $controller = $this->controller();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ota_platform must be ctrip');
        $this->invokeNonPublic($controller, 'normalizeCtripCompetitorPricePayload', [[
            'hotel_id' => 64,
            'analysis_date' => '2026-06-28',
            'room_type_id' => 12,
            'competitor_hotel_id' => 8,
            'our_price' => 320,
            'competitor_price' => 350,
            'ota_platform' => 2,
        ]]);
    }

    public function testExtractKnowledgeHotelIdsPrefersSystemHotelIds(): void
    {
        $controller = $this->controller();

        if (!method_exists($controller, 'extractKnowledgeHotelIds')) {
            self::fail('extractKnowledgeHotelIds is required');
        }

        $hotelIds = $this->invokeNonPublic($controller, 'extractKnowledgeHotelIds', [[
            'system_hotel_id' => 7,
            'hotel_id' => 'ota-should-not-be-used',
            'hotels' => [
                ['system_hotel_id' => '8', 'hotel_id' => 'platform-8'],
                ['hotel' => ['id' => 9, 'hotel_id' => 'platform-9']],
                ['hotel_id' => 'non-numeric-platform-id'],
            ],
            'groups' => [
                ['hotels' => [['system_hotel_id' => 8], ['system_hotel_id' => 10]]],
            ],
        ]]);

        self::assertSame([7, 8, 9, 10], $hotelIds);
    }

    public function testOtaDiagnosisIntentIdempotencyIsPerActionAndFailedTerminalsCanRetry(): void
    {
        $controller = $this->controller();
        $action = ['id' => 'action-1'];
        $input = [
            'action_type' => 'review_price',
            'platform' => 'ctrip',
            'target_value' => ['workflow_schedule' => [
                'assignee_id' => 7,
                'due_at' => '2099-07-18 18:00:00',
                'review_at' => '2099-07-19 10:00:00',
                'source_policy' => 'human_assigned_schedule_requires_manual_approval_and_readback_review',
            ]],
        ];

        $key = $this->invokeNonPublic($controller, 'otaDiagnosisActionIdempotencyKey', [31, 0, $action, $input]);
        self::assertSame(
            $key,
            $this->invokeNonPublic($controller, 'otaDiagnosisActionIdempotencyKey', [31, 0, $action, $input])
        );
        self::assertNotSame(
            $key,
            $this->invokeNonPublic($controller, 'otaDiagnosisActionIdempotencyKey', [31, 1, $action, $input])
        );
        self::assertNotSame(
            $key,
            $this->invokeNonPublic($controller, 'otaDiagnosisActionIdempotencyKey', [31, 0, ['id' => 'action-2'], $input])
        );
        $rescheduled = $input;
        $rescheduled['target_value']['workflow_schedule']['due_at'] = '2099-07-18 20:00:00';
        self::assertNotSame(
            $key,
            $this->invokeNonPublic($controller, 'otaDiagnosisActionIdempotencyKey', [31, 0, $action, $rescheduled]),
            'A changed persisted schedule must not reuse the old intent identity.'
        );

        $storedSchedule = $this->invokeNonPublic($controller, 'otaDiagnosisIntentWorkflowSchedule', [[
            'target_value_json' => json_encode($input['target_value'], JSON_UNESCAPED_UNICODE),
        ]]);
        self::assertSame($input['target_value']['workflow_schedule'], $storedSchedule);

        foreach (['failed', 'failure', 'rejected', 'cancelled', 'canceled'] as $status) {
            self::assertTrue(
                $this->invokeNonPublic($controller, 'isRetryableOtaDiagnosisIntentTerminal', [$status]),
                $status
            );
        }
        foreach (['pending_approval', 'approved', 'completed'] as $status) {
            self::assertFalse(
                $this->invokeNonPublic($controller, 'isRetryableOtaDiagnosisIntentTerminal', [$status]),
                $status
            );
        }
    }

    public function testCapturedOtaDataQualityGuardRewritesProblemHotelAnomalyTone(): void
    {
        $controller = $this->controller();

        $report = $this->invokeNonPublic($controller, 'applyCapturedOtaDataQualityGuard', [[
            'overall_conclusion' => '存在严重采集异常，违反基本漏斗逻辑。',
            'key_findings' => ['严重异常：访客为0但订单存在。'],
            'competitor_insights' => [],
            'problem_hotels' => [[
                'hotel_name' => '测试酒店',
                'problem' => '严重采集异常：访客为0但有订单。',
                'key_metrics' => ['订单10', '访客0'],
                'suggestion' => '立即联系携程 ebooking 支持团队。',
            ]],
            'recommended_actions' => ['立即联系携程ebooking支持团队。'],
            'data_anomalies' => ['严重采集异常。'],
            'priority' => 'high',
            'data_quality' => [
                'is_reliable' => true,
                'is_cross_day_window' => true,
                'warning' => '当前可能处于OTA跨日统计窗口，流量类指标可能尚未完成统计。',
            ],
        ]]);

        $problemHotel = $report['problem_hotels'][0] ?? [];
        self::assertStringContainsString('数据口径提示', (string)($problemHotel['problem'] ?? ''));
        self::assertStringNotContainsString('严重采集异常', (string)($problemHotel['problem'] ?? ''));
        self::assertStringNotContainsString('立即联系携程', (string)($problemHotel['suggestion'] ?? ''));
        self::assertStringNotContainsString('严重采集异常', implode(' ', $report['data_anomalies']));
    }
}
