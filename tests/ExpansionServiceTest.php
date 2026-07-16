<?php
declare(strict_types=1);

namespace Tests;

use app\service\ExpansionService;
use app\service\LlmClient;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExpansionServiceTest extends TestCase
{
    public function testEvaluateMarketReturnsDecisionMetricsAndDataStatus(): void
    {
        $result = $this->fallbackService()->evaluateMarket([
            'city' => '上海',
            'business_area' => '虹桥',
            'property_area' => 3200,
            'estimated_rent' => 120000,
            'target_room_count' => 80,
            'city_tier' => '一线',
            'decoration_level' => '中端精选',
            'primary_customer' => '商务差旅',
            'lease_years' => 10,
            'rent_free_months' => 4,
            'fitout_budget' => 420,
            'expected_adr' => 320,
            'expected_occupancy_rate' => 82,
            'ota_market_penetration_rate' => 65,
            'parking_spaces' => 30,
        ]);

        self::assertArrayHasKey('market_heat_score', $result);
        self::assertGreaterThanOrEqual(0, $result['market_heat_score']);
        self::assertLessThanOrEqual(100, $result['market_heat_score']);
        self::assertSame(40.0, $result['metrics']['area_per_room']);
        self::assertSame(1500.0, $result['metrics']['rent_per_room']);
        self::assertNotSame('', $result['decision']);
        self::assertSame('待接入真实数据', $result['data_status']['status']);
        self::assertSame('fallback', $result['ai_evaluation']['source']);
        self::assertSame('rule_screening_index', $result['score_type']);
        self::assertFalse($result['decision_ready']);
        self::assertSame('数据不足，仅可规则初筛', $result['decision']);
        self::assertStringContainsString('不是真实市场热度', $result['market_heat_score_formula']['semantics']);
    }

    public function testEvaluateMarketReturnsDetailedHeatScoreBreakdown(): void
    {
        $result = $this->fallbackService()->evaluateMarket([
            'city' => '上海',
            'business_area' => '虹桥',
            'property_area' => 3200,
            'estimated_rent' => 120000,
            'target_room_count' => 80,
            'city_tier' => '一线',
            'decoration_level' => '中端精选',
            'primary_customer' => '商务差旅',
            'lease_years' => 10,
            'rent_free_months' => 4,
            'fitout_budget' => 420,
            'expected_adr' => 320,
            'expected_occupancy_rate' => 82,
            'ota_market_penetration_rate' => 65,
            'parking_spaces' => 30,
        ]);

        self::assertArrayHasKey('market_heat_score_breakdown', $result);
        self::assertArrayHasKey('market_heat_score_formula', $result);
        self::assertNotEmpty($result['market_heat_score_breakdown']);
        self::assertSame('基础分', $result['market_heat_score_breakdown'][0]['label']);
        self::assertSame(62, $result['market_heat_score_breakdown'][0]['score_change']);

        foreach ($result['market_heat_score_breakdown'] as $item) {
            self::assertArrayHasKey('label', $item);
            self::assertArrayHasKey('score_change', $item);
            self::assertArrayHasKey('raw_score_after', $item);
            self::assertArrayHasKey('reason', $item);
            self::assertNotSame('', $item['label']);
            self::assertNotSame('', $item['reason']);
        }

        $rawScore = array_sum(array_column($result['market_heat_score_breakdown'], 'score_change'));
        self::assertSame($rawScore, $result['market_heat_score_formula']['raw_score']);
        self::assertSame($result['market_heat_score'], $result['market_heat_score_formula']['final_score']);
        self::assertSame('0-100封顶/保底', $result['market_heat_score_formula']['cap_rule']);
    }

    public function testEvaluateMarketUsesLlmEvaluationWhenConfigured(): void
    {
        $client = new class extends LlmClient {
            public array $messages = [];

            public function createJsonResponse(array $messages, array $schema, string $modelKey = 'deepseek_v4_default'): array
            {
                $this->messages = $messages;
                return [
                    'summary' => '项目租金承压可控，但需补充竞品价格和OTA转化数据。',
                    'decision' => '谨慎推进，先完成商圈复核。',
                    'market_judgement' => [
                        'supply_competition_strength' => 'AI判断为中等竞争，需补充同档竞品样本。',
                        'price_band_suggestion' => 'AI建议先按260-320元测试。',
                        'decision' => '谨慎推进，先完成商圈复核。',
                    ],
                    'recommendations' => [
                        ['priority' => 'P0', 'title' => '补齐竞品', 'detail' => '采集3公里竞品ADR、评分和点评量后校准价格带。'],
                    ],
                    'watch_points' => [
                        [
                            'severity' => 'P0',
                            'metric' => '竞品ADR',
                            'threshold' => '低于目标ADR时',
                            'evidence' => '当前未接入真实竞品ADR样本。',
                            'impact' => '目标价带可能高估开业爬坡收入。',
                            'validation' => '采集3公里同档竞品可订价后复核目标ADR。',
                            'owner' => '收益管理',
                            'deadline' => '投决会前',
                            'action' => '下调收益假设或重谈租金。',
                        ],
                    ],
                    'assumptions' => ['未接入真实竞品样本。'],
                ];
            }
        };

        $result = (new ExpansionService($client))->evaluateMarket([
            'city' => '上海',
            'business_area' => '虹桥',
            'property_area' => 3200,
            'estimated_rent' => 120000,
            'target_room_count' => 80,
            'city_tier' => '一线',
            'model_key' => 'deepseek_chat',
        ]);

        self::assertSame('llm', $result['ai_evaluation']['source']);
        self::assertSame('deepseek_chat', $result['ai_evaluation']['model_key']);
        self::assertSame('AI建议先按260-320元测试。', $result['ai_evaluation']['market_judgement']['price_band_suggestion']);
        self::assertSame('采集3公里竞品ADR、评分和点评量后校准价格带。', $result['ai_operation_suggestions'][0]);
        self::assertSame('P0', $result['ai_evaluation']['watch_points'][0]['severity']);
        self::assertSame('当前未接入真实竞品ADR样本。', $result['ai_evaluation']['watch_points'][0]['evidence']);
        self::assertSame('投决会前', $result['ai_evaluation']['watch_points'][0]['deadline']);
        self::assertStringContainsString('rule_result', (string)($client->messages[1]['content'] ?? ''));
    }

    #[DataProvider('invalidMarketInputProvider')]
    public function testEvaluateMarketRejectsInvalidRequiredNumbers(array $override): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->fallbackService()->evaluateMarket(array_merge([
            'city' => '上海',
            'property_area' => 3200,
            'estimated_rent' => 120000,
            'target_room_count' => 80,
        ], $override));
    }

    public function testBuildBenchmarkModelReturnsThreeBenchmarksAndMissingBusinessArea(): void
    {
        $result = $this->fallbackService()->buildBenchmarkModel([
            'city' => '杭州',
            'target_price_band' => '220-320',
            'hotel_type' => '商务酒店',
            'target_room_count' => 70,
        ]);

        self::assertSame('杭州', $result['position']['city']);
        self::assertCount(3, $result['recommended_benchmarks']);
        self::assertContains('商圈', $result['data_status']['missing_fields']);
        self::assertSame('0/6', $result['position']['detail_metrics']['data_completeness']);
        self::assertSame('synthetic_rule_scenario', $result['source']);
        self::assertNull($result['position']['detail_metrics']['avg_competitor_price']);
        self::assertSame('情景模型A', $result['recommended_benchmarks'][0]['name']);
        self::assertSame('synthetic_rule_scenario', $result['recommended_benchmarks'][0]['source']);
        self::assertNull($result['recommended_benchmarks'][0]['score']);
        self::assertNull($result['recommended_benchmarks'][0]['heat']);
        self::assertNull($result['recommended_benchmarks'][0]['review_count']);
        self::assertNull($result['recommended_benchmarks'][0]['distance_km']);
        self::assertNull($result['recommended_benchmarks'][0]['model_fit_score']);
        self::assertSame('基于用户输入及默认假设生成的规则情景，非真实竞品样本', $result['recommended_benchmarks'][0]['sample_basis']);
        self::assertSame('fallback', $result['ai_evaluation']['source']);
        self::assertSame('情景模型A', $result['ai_evaluation']['model_judgement']['best_fit_model']);
    }

    public function testBuildBenchmarkModelKeepsProvidedCompetitorMetricsPath(): void
    {
        $result = $this->fallbackService()->buildBenchmarkModel($this->benchmarkInput());

        self::assertSame('user_provided_competitor_metrics', $result['source']);
        self::assertSame('标杆模型A', $result['recommended_benchmarks'][0]['name']);
        self::assertSame(315, $result['position']['detail_metrics']['avg_competitor_price']);
        self::assertSame(4.8, $result['recommended_benchmarks'][0]['score']);
        self::assertNotNull($result['recommended_benchmarks'][0]['review_count']);
        self::assertNotNull($result['recommended_benchmarks'][0]['distance_km']);
        self::assertNotNull($result['recommended_benchmarks'][0]['model_fit_score']);
        self::assertSame('3公里内12家竞品样本', $result['recommended_benchmarks'][0]['sample_basis']);
    }

    public function testBuildBenchmarkModelKeepsPartialMetricsVisibleButUsesSyntheticScenario(): void
    {
        $result = $this->fallbackService()->buildBenchmarkModel([
            'city' => '杭州',
            'business_area' => '武林商圈',
            'target_price_band' => '220-320',
            'hotel_type' => '商务酒店',
            'target_room_count' => 70,
            'avg_competitor_price' => 288,
        ]);

        self::assertSame('synthetic_rule_scenario', $result['source']);
        self::assertSame('1/6', $result['position']['detail_metrics']['data_completeness']);
        self::assertSame(288, $result['position']['detail_metrics']['avg_competitor_price']);
        self::assertNull($result['position']['detail_metrics']['avg_competitor_score']);
        self::assertSame('情景模型A', $result['recommended_benchmarks'][0]['name']);
        self::assertNull($result['recommended_benchmarks'][0]['score']);
        self::assertNull($result['recommended_benchmarks'][0]['model_fit_score']);
    }

    public function testBuildBenchmarkModelUsesLlmEvaluationWhenConfigured(): void
    {
        $client = new class extends LlmClient {
            public array $messages = [];

            public function createJsonResponse(array $messages, array $schema, string $modelKey = 'deepseek_v4_default'): array
            {
                $this->messages = $messages;
                return [
                    'summary' => 'AI复核后建议优先复制模型A，规则引擎结果仅作初筛。',
                    'decision' => '优先复制模型A，先做小范围价格测试。',
                    'model_judgement' => [
                        'best_fit_model' => '标杆模型A',
                        'copy_priority' => '先复制房型效率和渠道首图标准。',
                        'differentiation_focus' => '用商务便利性作为差异化标签。',
                    ],
                    'recommendations' => [
                        ['priority' => 'P0', 'title' => '复核样本', 'detail' => '补齐3公里竞品点评文本和OTA转化数据。'],
                    ],
                    'watch_points' => [
                        ['metric' => '价格差', 'threshold' => '高于竞品均价30元以上', 'action' => '改为节假日上浮，不作为常态挂牌价。'],
                    ],
                    'assumptions' => ['未接入真实点评文本。'],
                ];
            }
        };

        $result = (new ExpansionService($client))->buildBenchmarkModel([
            'city' => '上海',
            'business_area' => '核心商务区',
            'target_price_band' => '220-320',
            'hotel_type' => '中端商务',
            'target_room_count' => 72,
            'competitor_count' => 12,
            'avg_competitor_price' => 285,
            'avg_competitor_score' => 4.7,
            'avg_review_count' => 520,
            'ota_heat_index' => 82,
            'traffic_radius_km' => 3,
            'model_key' => 'openai_fast',
        ]);

        self::assertSame('llm', $result['ai_evaluation']['source']);
        self::assertSame('openai_fast', $result['ai_evaluation']['model_key']);
        self::assertStringNotContainsString('规则引擎', $result['ai_evaluation']['summary']);
        self::assertSame('优先复制模型A，先做小范围价格测试。', $result['ai_evaluation']['decision']);
        self::assertSame('补齐3公里竞品点评文本和OTA转化数据。', $result['ai_evaluation']['recommendations'][0]['detail']);
        self::assertStringContainsString('benchmark_result', (string)($client->messages[1]['content'] ?? ''));
    }

    public function testImproveCollaborationFlagsOverdueCriticalTasks(): void
    {
        $result = $this->fallbackService()->improveCollaboration([
            'project_name' => '虹桥新店',
            'city_area' => '上海虹桥',
            'current_stage' => '装修筹建',
            'owner' => '运营负责人',
            'expected_online_date' => date('Y-m-d', strtotime('+10 days')),
            'tasks' => [
                [
                    'name' => '装修筹建',
                    'status' => '进行中',
                    'owner' => '工程',
                    'due_date' => date('Y-m-d', strtotime('-1 day')),
                ],
            ],
        ]);

        self::assertSame('高风险', $result['delay_risk']['level']);
        self::assertGreaterThan(0, $result['progress']['total']);
        self::assertNotEmpty($result['next_actions']);
    }

    public function testImproveCollaborationDoesNotInventTaskProgressFromCurrentStage(): void
    {
        $result = $this->fallbackService()->improveCollaboration([
            'project_name' => '未确认项目',
            'city_area' => '上海虹桥',
            'current_stage' => '装修筹建',
            'owner' => '项目负责人',
            'expected_online_date' => date('Y-m-d', strtotime('+60 days')),
        ]);

        self::assertSame(0, $result['progress']['completed']);
        self::assertSame(0, $result['progress']['confirmed_total']);
        self::assertNull($result['progress']['percent']);
        self::assertSame('待评估', $result['delay_risk']['level']);
        self::assertStringContainsString('尚无已确认进度', $result['progress']['status_text']);
        foreach ($result['task_board'] as $task) {
            self::assertSame('待确认', $task['status']);
            self::assertSame('', $task['due_date']);
            self::assertSame('rule_template', $task['source']);
            self::assertFalse($task['is_observed']);
        }
    }

    public function testProjectReadinessKeepsMarketRecordAsScreeningOnly(): void
    {
        $service = $this->fallbackService();
        $marketInput = $this->marketInput();
        $market = $service->evaluateMarket($marketInput);

        $readiness = $service->buildProjectReadiness('market', $marketInput, $market);
        $missingCodes = array_column($readiness['missing_evidence'], 'code');

        self::assertSame('screening_record_only', $readiness['stage']);
        self::assertFalse($readiness['project_ready']);
        self::assertContains('benchmark_model', $missingCodes);
        self::assertContains('collaboration_plan', $missingCodes);
        self::assertContains('source_evidence', $missingCodes);
    }

    public function testProjectReadinessRequiresEvidenceReviewAndTracking(): void
    {
        $service = $this->fallbackService();
        $marketInput = $this->marketInput();
        $benchmarkInput = $this->benchmarkInput();
        $market = $service->evaluateMarket($marketInput);
        $benchmark = $service->buildBenchmarkModel($benchmarkInput);
        $collaborationInput = array_merge($this->collaborationInput(), [
            'market_input' => $marketInput,
            'market_result' => $market,
            'benchmark_input' => $benchmarkInput,
            'benchmark_result' => $benchmark,
        ]);
        $collaboration = $service->improveCollaboration($collaborationInput);

        $readiness = $service->buildProjectReadiness('collaboration', $collaborationInput, $collaboration);
        $missingCodes = array_column($readiness['missing_evidence'], 'code');

        self::assertSame('diligence_required', $readiness['stage']);
        self::assertFalse($readiness['project_ready']);
        self::assertContains('source_evidence', $missingCodes);
        self::assertContains('manual_review', $missingCodes);

        $approved = $service->buildProjectReadiness('collaboration', array_merge($collaborationInput, [
            'source_evidence' => ['competitor_samples' => 'checked'],
            'review_status' => 'approved',
            'opening_project_id' => 88,
        ]), $collaboration);

        self::assertSame('project_ready', $approved['stage']);
        self::assertTrue($approved['project_ready']);
    }

    public function testBuildExecutionIntentInputRequiresExplicitHotel(): void
    {
        $service = $this->fallbackService();

        $this->expectException(InvalidArgumentException::class);
        $service->buildExecutionIntentInput(['id' => 9], 0);
    }

    public function testBuildExecutionIntentInputRejectsScreeningOnlyRecord(): void
    {
        $service = $this->fallbackService();
        $marketInput = $this->marketInput();
        $market = $service->evaluateMarket($marketInput);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('screening_record_only');
        $service->buildExecutionIntentInput([
            'id' => 9,
            'record_type' => 'market',
            'input' => $marketInput,
            'result' => $market,
        ], 7);
    }

    public function testBuildExecutionIntentInputUsesExpansionDecisionScope(): void
    {
        $service = $this->fallbackService();
        $marketInput = $this->marketInput();
        $benchmarkInput = $this->benchmarkInput();
        $market = $service->evaluateMarket($marketInput);
        $benchmark = $service->buildBenchmarkModel($benchmarkInput);
        $collaborationInput = array_merge($this->collaborationInput(), [
            'market_input' => $marketInput,
            'market_result' => $market,
            'benchmark_input' => $benchmarkInput,
            'benchmark_result' => $benchmark,
            'source_evidence' => ['competitor_samples' => 'checked'],
            'review_status' => 'approved',
        ]);
        $collaboration = $service->improveCollaboration($collaborationInput);
        $readiness = $service->buildProjectReadiness('collaboration', $collaborationInput, $collaboration);

        $intentInput = $service->buildExecutionIntentInput([
            'id' => 9,
            'record_type' => 'collaboration',
            'project_name' => '上海虹桥新店',
            'city_area' => '上海虹桥',
            'decision' => '可推进',
            'risk_level' => '中风险',
            'input' => $collaborationInput,
            'result' => $collaboration,
            'project_readiness' => $readiness,
        ], 7, ['date_start' => '2026-06-14']);

        self::assertSame('expansion', $intentInput['source_module']);
        self::assertSame(9, $intentInput['source_record_id']);
        self::assertSame(7, $intentInput['hotel_id']);
        self::assertSame('investment', $intentInput['platform']);
        self::assertSame('expansion', $intentInput['object_type']);
        self::assertSame('expansion_project_closure', $intentInput['target_value']['target_metric']);
        self::assertSame('pending_expansion_post_decision_tracking', $intentInput['target_value']['tracking_status']);
        self::assertSame($readiness['stage'], $intentInput['evidence']['readiness_stage']);
        self::assertSame('expansion_screening_and_project_decision', $intentInput['evidence']['source_scope']);
        self::assertSame('medium', $intentInput['risk_level']);
    }

    public static function invalidMarketInputProvider(): array
    {
        return [
            'empty area' => [['property_area' => 0]],
            'negative rent' => [['estimated_rent' => -1]],
            'empty room count' => [['target_room_count' => 0]],
            'non numeric area' => [['property_area' => 'abc']],
        ];
    }

    private function marketInput(): array
    {
        return [
            'city' => '上海',
            'business_area' => '虹桥',
            'property_area' => 3200,
            'estimated_rent' => 120000,
            'target_room_count' => 80,
            'city_tier' => '一线',
            'decoration_level' => '中端精选',
            'primary_customer' => '商务差旅',
            'secondary_customer' => '会议会展',
            'lease_years' => 10,
            'rent_free_months' => 4,
            'fitout_budget' => 420,
            'expected_adr' => 320,
            'expected_occupancy_rate' => 82,
            'ota_market_penetration_rate' => 65,
            'parking_spaces' => 30,
        ];
    }

    private function benchmarkInput(): array
    {
        return [
            'city' => '上海',
            'business_area' => '虹桥',
            'target_price_band' => '260-360',
            'hotel_type' => '中端商务',
            'target_room_count' => 80,
            'competitor_count' => 12,
            'avg_competitor_price' => 315,
            'avg_competitor_score' => 4.7,
            'avg_review_count' => 520,
            'ota_heat_index' => 82,
            'traffic_radius_km' => 3,
        ];
    }

    private function collaborationInput(): array
    {
        $dueDate = date('Y-m-d', strtotime('+30 days'));
        $tasks = array_map(static fn(string $name): array => [
            'name' => $name,
            'status' => '已完成',
            'owner' => '拓展负责人',
            'due_date' => $dueDate,
        ], ['市场调研', '物业评估', '合同谈判', '装修筹建', '证照办理', 'OTA上线', '运营交接']);

        return [
            'project_name' => '上海虹桥新店',
            'city_area' => '上海虹桥',
            'current_stage' => '上线',
            'owner' => '拓展负责人',
            'expected_online_date' => date('Y-m-d', strtotime('+60 days')),
            'tasks' => $tasks,
        ];
    }

    private function fallbackService(): ExpansionService
    {
        return new ExpansionService(new class extends LlmClient {
            public function createJsonResponse(array $messages, array $schema, string $modelKey = 'deepseek_v4_default'): array
            {
                throw new RuntimeException('missing model config');
            }
        });
    }
}
