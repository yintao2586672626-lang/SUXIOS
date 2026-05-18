<?php
declare(strict_types=1);

namespace Tests;

use app\service\LlmClient;
use app\service\TransferDecisionService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\ReflectionHelper;

final class TransferDecisionServiceTest extends TestCase
{
    use ReflectionHelper;

    public function testCalculateAssetPricingReturnsProfitValuationAndRiskEnvelope(): void
    {
        $result = $this->fallbackService()->calculateAssetPricing([
            'hotel_name' => '虹桥样板店',
            'location' => '上海虹桥',
            'room_count' => 80,
            'monthly_revenue' => 30,
            'monthly_rent' => 8,
            'labor_cost' => 5,
            'utility_cost' => 1,
            'ota_commission' => 2,
            'other_fixed_cost' => 1,
            'decoration_investment' => 200,
            'remaining_lease_months' => 72,
            'expected_transfer_price' => 180,
            'occupancy_rate' => 82,
            'adr' => 320,
            'rating' => 4.8,
            'order_count' => 900,
            'licenses_complete' => true,
        ]);

        self::assertSame(80, $result['basic_info']['room_count']);
        self::assertSame(17.0, $result['costs']['monthly_total_cost']);
        self::assertSame(13.0, $result['profit']['monthly_net_profit']);
        self::assertIsFloat($result['valuation']['reasonable_valuation']);
        self::assertNotSame('', $result['risk_level']);
        self::assertSame('万元', $result['unit']);
    }

    public function testCalculateAssetPricingAddsFallbackAiEvaluation(): void
    {
        $service = new TransferDecisionService(new class extends LlmClient {
            public function createJsonResponse(array $messages, array $schema, string $modelKey = 'deepseek_v4_default'): array
            {
                throw new RuntimeException('missing model config');
            }
        });

        $result = $service->calculateAssetPricing($this->pricingInput());

        self::assertSame('fallback', $result['ai_evaluation']['source']);
        self::assertNotEmpty($result['ai_evaluation']['summary']);
        self::assertNotEmpty($result['ai_evaluation']['recommendations']);
        self::assertNotEmpty($result['ai_evaluation']['watch_points']);
    }

    public function testCalculateAssetPricingUsesLlmAiEvaluationWhenAvailable(): void
    {
        $client = new class extends LlmClient {
            public array $messages = [];

            public function createJsonResponse(array $messages, array $schema, string $modelKey = 'deepseek_v4_default'): array
            {
                $this->messages = $messages;
                return [
                    'summary' => '报价可进入复核，但需先确认真实流水和租约。',
                    'decision' => '谨慎接盘，先完成尽调。',
                    'recommendations' => [
                        ['priority' => 'P0', 'title' => '核验流水', 'detail' => '核验近90天OTA订单、日报流水和银行收款。'],
                    ],
                    'watch_points' => [
                        ['metric' => '转让报价', 'threshold' => '不高于合理估值', 'action' => '超出区间则重新谈价。'],
                    ],
                    'assumptions' => ['未读取线下租约原件。'],
                ];
            }
        };

        $result = (new TransferDecisionService($client))->calculateAssetPricing(array_merge($this->pricingInput(), [
            'model_key' => 'deepseek_chat',
        ]));

        self::assertSame('llm', $result['ai_evaluation']['source']);
        self::assertSame('deepseek_chat', $result['ai_evaluation']['model_key']);
        self::assertSame('核验流水', $result['ai_evaluation']['recommendations'][0]['title']);
        self::assertStringContainsString('pricing_result', (string)($client->messages[1]['content'] ?? ''));
    }

    public function testCalculateAssetPricingUsesDecorationValuationWhenProfitIsNegative(): void
    {
        $result = $this->fallbackService()->calculateAssetPricing([
            'room_count' => 30,
            'monthly_revenue' => 8,
            'monthly_rent' => 10,
            'labor_cost' => 4,
            'utility_cost' => 1,
            'ota_commission' => 1,
            'other_fixed_cost' => 1,
            'decoration_investment' => 100,
            'remaining_lease_months' => 18,
            'expected_transfer_price' => 120,
            'occupancy_rate' => 45,
            'rating' => 4.3,
            'licenses_complete' => false,
        ]);

        self::assertSame(-9.0, $result['profit']['monthly_net_profit']);
        self::assertNull($result['profit']['payback_months']);
        self::assertSame(15.0, $result['valuation']['conservative_valuation']);
        self::assertSame(25.0, $result['valuation']['reasonable_valuation']);
        self::assertSame(35.0, $result['valuation']['optimistic_valuation']);
    }

    public function testCalculateAssetPricingRejectsInvalidRoomCount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new TransferDecisionService())->calculateAssetPricing(['room_count' => 0]);
    }

    public function testCalculateTransferTimingDetectsCollectionAnomaly(): void
    {
        $result = (new TransferDecisionService())->calculateTransferTiming([
            'exposure' => 0,
            'visitors' => 0,
            'conversion_rate' => 0,
            'order_count' => 20,
            'room_nights' => 30,
            'rating' => 4.7,
        ]);

        self::assertTrue($result['data_quality']['suspected_collection_anomaly']);
        self::assertTrue($result['data_quality']['has_data_anomaly']);
        self::assertContains('疑似采集异常', $result['risk_points']);
    }

    public function testCalculateTransferTimingRewardsPositiveWindow(): void
    {
        $result = (new TransferDecisionService())->calculateTransferTiming([
            'revenue_trend' => '上涨',
            'order_trend' => '上涨',
            'adr_trend' => '上涨',
            'occupancy_trend' => '上涨',
            'rating' => 4.9,
            'holiday_days' => 20,
            'is_peak_season' => true,
        ]);

        self::assertGreaterThanOrEqual(80, $result['timing_score']);
        self::assertSame('适合转让', $result['decision']);
        self::assertFalse($result['data_quality']['has_data_anomaly']);
    }

    public function testCalculateTransferTimingComparesCurrentWindowWithAnnualBenchmarkAliases(): void
    {
        $result = (new TransferDecisionService())->calculateTransferTiming([
            'current_revenue' => 120,
            '年度30天营业额' => 100,
            'current_orders' => 620,
            '年度30天订单量' => 520,
            'current_adr' => 320,
            '年度ADR' => 300,
            'current_occupancy_rate' => 82,
            '年度入住率' => 76,
        ]);

        self::assertSame(100, $result['timing_score']);
        self::assertContains('营业额上涨，加15分', $result['main_reasons']);
        self::assertContains('订单上涨，加15分', $result['main_reasons']);
    }

    public function testAnnualBenchmarkScalesRevenueAndOrdersToThirtyDays(): void
    {
        $benchmark = $this->invokeNonPublic(new TransferDecisionService(), 'annualThirtyDayBenchmark', [[
            'actual_days' => 60,
            'revenue' => 600000,
            'orders' => 120,
            'adr' => 300,
            'occupancy_rate' => 75,
        ]]);

        self::assertSame(300000.0, $benchmark['revenue']);
        self::assertSame(60, $benchmark['orders']);
        self::assertSame(300.0, $benchmark['adr']);
        self::assertSame(75.0, $benchmark['occupancy_rate']);
    }

    public function testBuildTransferDashboardMergesPricingTimingAndMetricRisks(): void
    {
        $result = (new TransferDecisionService())->buildTransferDashboard(
            [
                'valuation' => [
                    'conservative_valuation' => 100,
                    'optimistic_valuation' => 180,
                ],
                'profit' => [
                    'monthly_net_profit' => 12,
                    'payback_months' => 16,
                ],
                'risk_level' => '低风险',
                'risk_points' => ['租金可控'],
                'main_reasons' => ['利润稳定'],
                'suggestion' => '可进入议价',
            ],
            [
                'timing_score' => 86,
                'decision' => '适合转让',
                'risk_points' => ['窗口期较好'],
                'main_reasons' => ['评分较高'],
                'next_suggestions' => ['准备挂牌材料'],
                'data_quality' => ['has_data_anomaly' => false],
            ],
            ['risk_points' => ['需复核证照']]
        );

        self::assertCount(6, $result['cards']);
        self::assertSame('启动挂牌', $result['suggested_action']);
        self::assertContains('需复核证照', $result['risk_points']);
        self::assertNotEmpty($result['final_judgement']);
    }

    private function pricingInput(): array
    {
        return [
            'hotel_name' => '虹桥样板店',
            'location' => '上海虹桥',
            'room_count' => 80,
            'monthly_revenue' => 30,
            'monthly_rent' => 8,
            'labor_cost' => 5,
            'utility_cost' => 1,
            'ota_commission' => 2,
            'other_fixed_cost' => 1,
            'decoration_investment' => 200,
            'remaining_lease_months' => 72,
            'expected_transfer_price' => 180,
            'occupancy_rate' => 82,
            'adr' => 320,
            'rating' => 4.8,
            'order_count' => 900,
            'licenses_complete' => true,
        ];
    }

    private function fallbackService(): TransferDecisionService
    {
        return new TransferDecisionService(new class extends LlmClient {
            public function createJsonResponse(array $messages, array $schema, string $modelKey = 'deepseek_v4_default'): array
            {
                throw new RuntimeException('missing model config');
            }
        });
    }
}
