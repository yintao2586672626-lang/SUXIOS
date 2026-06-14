<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueResearchService;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;

final class RevenueResearchServiceTest extends TestCase
{
    use ReflectionHelper;

    public function testResearchProductsDoNotRequireReviewCollectionFields(): void
    {
        $products = $this->invokeNonPublic(new RevenueResearchService(), 'products');

        self::assertArrayNotHasKey('review-topic', $products);

        $disabledReviewFields = [
            'comment_text',
            'review_text',
            'comment_score',
            'comment_time',
            'reply_content',
        ];

        foreach ($products as $product) {
            foreach (($product['rules'] ?? []) as $rule) {
                $fields = (array)($rule['fields'] ?? []);
                foreach ($disabledReviewFields as $field) {
                    self::assertNotContains($field, $fields, 'Research product must not require disabled review field: ' . $field);
                }
            }
        }
    }

    public function testServiceQualityResearchUsesCapturedOtaOperatingData(): void
    {
        $products = $this->invokeNonPublic(new RevenueResearchService(), 'products');

        self::assertArrayHasKey('service-quality', $products);
        self::assertSame('service-quality', $products['service-quality']['key']);

        $rules = $products['service-quality']['rules'] ?? [];
        self::assertNotEmpty($rules);

        $onlineRule = null;
        foreach ($rules as $rule) {
            if (($rule['table'] ?? '') === 'online_daily_data') {
                $onlineRule = $rule;
                break;
            }
        }

        self::assertIsArray($onlineRule);
        $fields = (array)($onlineRule['fields'] ?? []);
        foreach (['data_date', 'amount', 'quantity', 'book_order_num', 'list_exposure', 'detail_exposure', 'flow_rate', 'raw_data'] as $field) {
            self::assertContains($field, $fields);
        }
    }

    public function testResearchReadinessRequiresBusinessForecast(): void
    {
        $service = new RevenueResearchService();
        $products = $this->invokeNonPublic($service, 'products');

        $readiness = $service->buildResearchReadiness($products['demand-forecast'], 'pending_data', [], [
            'available' => false,
            'message' => '未找到可用于预测的有效经营记录。',
        ], [
            'recommended_actions' => ['复核近期收入趋势'],
        ]);

        self::assertSame('research_forecast_missing', $readiness['stage']);
        self::assertFalse($readiness['execution_ready']);
        self::assertSame(['business_forecast'], array_column($readiness['missing_evidence'], 'code'));
    }

    public function testResearchReadinessKeepsDataGapsVisible(): void
    {
        $service = new RevenueResearchService();
        $products = $this->invokeNonPublic($service, 'products');

        $readiness = $service->buildResearchReadiness($products['price-elasticity'], 'pending_data', [[
            'table' => 'competitor_analysis',
            'label' => '竞对价格与价格指数',
            'reason' => '样本量不足',
            'collect_from' => '竞对价格监控',
        ]], [
            'available' => true,
        ], [
            'recommended_actions' => ['先补齐竞对价格样本'],
        ]);

        self::assertSame('research_data_gaps_pending', $readiness['stage']);
        self::assertFalse($readiness['execution_ready']);
        self::assertSame(['data_gap_competitor_analysis'], array_column($readiness['missing_evidence'], 'code'));
    }

    public function testResearchReadinessBlocksProductsWithoutSystemModule(): void
    {
        $service = new RevenueResearchService();
        $products = $this->invokeNonPublic($service, 'products');

        $readiness = $service->buildResearchReadiness($products['ltv'], 'done', [], [
            'available' => true,
        ], [
            'recommended_actions' => ['建立高价值客群触达清单'],
        ]);

        self::assertSame('research_module_bridge_missing', $readiness['stage']);
        self::assertFalse($readiness['module_connected']);
        self::assertSame(['module_bridge'], array_column($readiness['missing_evidence'], 'code'));
    }

    public function testResearchReadinessCanEnterExecutionButDoesNotClaimClosedLoop(): void
    {
        $service = new RevenueResearchService();
        $products = $this->invokeNonPublic($service, 'products');

        $readiness = $service->buildResearchReadiness($products['demand-forecast'], 'done', [], [
            'available' => true,
        ], [
            'recommended_actions' => ['进入收益管理生成调价建议'],
        ]);

        self::assertSame('research_ready_for_execution', $readiness['stage']);
        self::assertTrue($readiness['execution_ready']);
        self::assertFalse($readiness['closed_loop']);
        self::assertSame(['execution_record'], array_column($readiness['missing_evidence'], 'code'));
    }
}
