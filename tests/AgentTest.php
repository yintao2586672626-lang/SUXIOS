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
        foreach ($items as $item) {
            self::assertSame('pending_manual_review', $item['status']);
            self::assertNotEmpty($item['evidence_refs']);
        }
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
                ['ref' => 'online_daily_data#10', 'tags' => ['traffic']],
                ['ref' => 'competitor_price_log#2', 'tags' => ['price']],
            ],
        ]);
        self::assertSame(['online_daily_data#10'], $refs);
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
            $manyHotels[] = ['hotel_id' => 'h' . $i, 'hotel_name' => 'Hotel ' . $i, 'revenue' => $i];
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
