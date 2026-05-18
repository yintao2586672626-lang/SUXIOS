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
                        ['metric' => '竞品ADR', 'threshold' => '低于目标ADR时', 'action' => '下调收益假设或重谈租金。'],
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
        self::assertSame(270, $result['position']['detail_metrics']['avg_competitor_price']);
        self::assertArrayHasKey('model_fit_score', $result['recommended_benchmarks'][0]);
        self::assertArrayHasKey('price_gap_to_market', $result['recommended_benchmarks'][0]);
        self::assertSame('fallback', $result['ai_evaluation']['source']);
        self::assertSame('标杆模型A', $result['ai_evaluation']['model_judgement']['best_fit_model']);
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

    public static function invalidMarketInputProvider(): array
    {
        return [
            'empty area' => [['property_area' => 0]],
            'negative rent' => [['estimated_rent' => -1]],
            'empty room count' => [['target_room_count' => 0]],
            'non numeric area' => [['property_area' => 'abc']],
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
