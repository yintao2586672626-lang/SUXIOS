<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueResearchService;
use app\service\OperationManagementService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
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

    public function testBuildExecutionIntentInputForReadyResearchUsesCanonicalOperationPayload(): void
    {
        $service = new RevenueResearchService();

        $input = $service->buildExecutionIntentInput([
            'status' => 'done',
            'product_key' => 'demand-forecast',
            'model_key' => 'deepseek_chat',
            'generation_mode' => 'configured_model',
            'hotel_scope' => ['hotel_id' => 7],
            'readiness' => [
                'stage' => 'research_ready_for_execution',
                'execution_ready' => true,
                'target_module' => '酒店AI工具箱 / 收益管理 / 收益分析',
            ],
            'result' => [
                'title' => '需求预测经营预测',
                'summary' => '未来 7 天需求上升',
                'recommended_actions' => ['复核未来 7 天价格策略'],
                'data_gaps' => [],
                'next_review_date' => '2026-06-26',
            ],
            'gaps' => [],
        ], [
            'source_record_id' => 901,
            'hotel_id' => 7,
        ]);

        self::assertSame('revenue_research', $input['source_module']);
        self::assertSame('revenue_research', $input['object_type']);
        self::assertSame('demand-forecast', $input['action_type']);
        self::assertSame('复核未来 7 天价格策略', $input['target_value']['action_text']);
        self::assertSame('research_ready_for_execution', $input['evidence']['research_readiness_stage']);
        self::assertSame('ota_channel', $input['evidence']['metric_scope']);

        $payload = (new OperationManagementService())->buildExecutionIntentPayload([7], 7, $input, 3);
        self::assertSame('pending_approval', $payload['status']);
        self::assertSame('', $payload['blocked_reason']);
        self::assertSame('revenue_research_closure', $payload['expected_metric']);
    }

    public function testBuildExecutionIntentInputBlocksResearchWithDataGaps(): void
    {
        $service = new RevenueResearchService();

        $input = $service->buildExecutionIntentInput([
            'status' => 'pending_data',
            'product_key' => 'price-elasticity',
            'hotel_scope' => ['hotel_id' => 7],
            'readiness' => [
                'stage' => 'research_data_gaps_pending',
                'execution_ready' => false,
                'target_module' => '酒店AI工具箱 / 收益管理 / 定价建议',
            ],
            'result' => [
                'title' => '价格弹性与收益管理经营预测',
                'summary' => '竞对样本不足',
                'recommended_actions' => ['先补齐竞对价格样本'],
                'data_gaps' => ['样本量不足'],
            ],
            'gaps' => [[
                'table' => 'competitor_analysis',
                'label' => '竞对价格与价格指数',
                'reason' => '样本量不足',
            ]],
        ], [
            'source_record_id' => 902,
            'hotel_id' => 7,
        ]);

        $payload = (new OperationManagementService())->buildExecutionIntentPayload([7], 7, $input, 3);

        self::assertSame('blocked', $payload['status']);
        self::assertStringContainsString('research_data_gaps_pending', $payload['blocked_reason']);
        self::assertContains('competitor_analysis', $payload['evidence']['data_gaps']);
    }

    public function testBuildReadyExecutionIntentInputRejectsResearchWithDataGaps(): void
    {
        $service = new RevenueResearchService();
        self::assertTrue(
            method_exists($service, 'buildReadyExecutionIntentInput'),
            'Revenue research bridge must expose a ready-only execution-intent input builder.'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(422);
        $this->expectExceptionMessage('research_data_gaps_pending');

        $service->buildReadyExecutionIntentInput([
            'status' => 'pending_data',
            'product_key' => 'price-elasticity',
            'hotel_scope' => ['hotel_id' => 7],
            'readiness' => [
                'stage' => 'research_data_gaps_pending',
                'execution_ready' => false,
                'missing_evidence' => [
                    ['code' => 'data_gap_competitor_analysis', 'label' => '竞对价格与价格指数'],
                ],
            ],
            'result' => [
                'recommended_actions' => ['先补齐竞对价格样本'],
            ],
        ], [
            'source_record_id' => 902,
            'hotel_id' => 7,
        ]);
    }

    public function testBuildReadyExecutionIntentInputRejectsInconsistentReadyFlagWithDataGaps(): void
    {
        $service = new RevenueResearchService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(422);
        $this->expectExceptionMessage('pending_data');

        $service->buildReadyExecutionIntentInput([
            'status' => 'pending_data',
            'product_key' => 'demand-forecast',
            'hotel_scope' => ['hotel_id' => 7],
            'readiness' => [
                'stage' => 'research_ready_for_execution',
                'execution_ready' => true,
                'missing_evidence' => [],
            ],
            'result' => [
                'recommended_actions' => ['Review next 7 days pricing strategy'],
                'data_gaps' => ['business_forecast_missing'],
            ],
            'gaps' => [[
                'table' => 'business_forecast',
                'label' => 'Business forecast',
                'reason' => 'Business forecast input is missing',
            ]],
        ], [
            'source_record_id' => 904,
            'hotel_id' => 7,
        ]);
    }

    public function testBuildReadyExecutionIntentInputKeepsReadyResearchPayload(): void
    {
        $service = new RevenueResearchService();
        self::assertTrue(
            method_exists($service, 'buildReadyExecutionIntentInput'),
            'Revenue research bridge must expose a ready-only execution-intent input builder.'
        );

        $input = $service->buildReadyExecutionIntentInput([
            'status' => 'done',
            'product_key' => 'demand-forecast',
            'hotel_scope' => ['hotel_id' => 7],
            'readiness' => [
                'stage' => 'research_ready_for_execution',
                'execution_ready' => true,
                'target_module' => '酒店AI工具箱 / 收益管理 / 收益分析',
            ],
            'result' => [
                'recommended_actions' => ['复核未来 7 天价格策略'],
            ],
        ], [
            'source_record_id' => 903,
            'hotel_id' => 7,
        ]);

        self::assertSame('revenue_research', $input['source_module']);
        self::assertSame(903, $input['source_record_id']);
        self::assertSame('pending_revenue_research_execution', $input['target_value']['tracking_status']);
        self::assertTrue($input['evidence']['execution_ready']);
    }

    public function testAssertNoDuplicateExecutionIntentRejectsExistingRevenueResearchIntent(): void
    {
        $service = new RevenueResearchService();
        self::assertTrue(
            method_exists($service, 'assertNoDuplicateExecutionIntent'),
            'Revenue research bridge must reject duplicate execution intent linkage.'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(409);
        $this->expectExceptionMessage('already linked to execution intent');

        $service->assertNoDuplicateExecutionIntent([
            'source_module' => 'revenue_research',
            'source_record_id' => 903,
            'hotel_id' => 7,
        ], [
            [
                'id' => 8801,
                'source_module' => 'revenue_research',
                'source_record_id' => 903,
                'hotel_id' => 7,
                'status' => 'pending_approval',
                'deleted_at' => null,
            ],
        ]);
    }

    public function testAssertNoDuplicateExecutionIntentIgnoresDifferentHotelOrDeletedRows(): void
    {
        $service = new RevenueResearchService();
        self::assertTrue(
            method_exists($service, 'assertNoDuplicateExecutionIntent'),
            'Revenue research bridge must reject duplicate execution intent linkage.'
        );

        $service->assertNoDuplicateExecutionIntent([
            'source_module' => 'revenue_research',
            'source_record_id' => 903,
            'hotel_id' => 7,
        ], [
            [
                'id' => 8801,
                'source_module' => 'revenue_research',
                'source_record_id' => 903,
                'hotel_id' => 8,
                'status' => 'pending_approval',
                'deleted_at' => null,
            ],
            [
                'id' => 8802,
                'source_module' => 'revenue_research',
                'source_record_id' => 903,
                'hotel_id' => 7,
                'status' => 'archived',
                'deleted_at' => '2026-06-25 10:00:00',
            ],
        ]);

        self::assertTrue(true);
    }

    public function testGeneratedExecutionIntentSourceRecordIdFitsSignedInteger(): void
    {
        $service = new RevenueResearchService();

        $input = $service->buildExecutionIntentInput([
            'status' => 'done',
            'product_key' => 'revenue-test-0',
            'hotel_scope' => ['hotel_id' => 0],
            'readiness' => [
                'stage' => 'research_ready_for_execution',
                'execution_ready' => true,
            ],
            'result' => [
                'recommended_actions' => ['Review action'],
                'data_gaps' => [],
            ],
            'gaps' => [],
        ]);

        self::assertGreaterThan(0, $input['source_record_id']);
        self::assertLessThanOrEqual(2147483647, $input['source_record_id']);
    }

    public function testEmptyExecutionDateOverrideFallsBackToToday(): void
    {
        $service = new RevenueResearchService();

        $input = $service->buildExecutionIntentInput([
            'status' => 'done',
            'product_key' => 'demand-forecast',
            'hotel_scope' => ['hotel_id' => 7],
            'readiness' => [
                'stage' => 'research_ready_for_execution',
                'execution_ready' => true,
            ],
            'result' => [
                'recommended_actions' => ['Review price action'],
                'data_gaps' => [],
            ],
            'gaps' => [],
        ], [
            'hotel_id' => 7,
            'date_start' => '',
            'date_end' => '',
        ]);

        $payload = (new OperationManagementService())->buildExecutionIntentPayload([7], 7, $input, 3);

        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $payload['date_start']);
        self::assertSame($payload['date_start'], $payload['date_end']);
    }

    public function testEmptyPlatformOverrideFallsBackToOta(): void
    {
        $service = new RevenueResearchService();

        $input = $service->buildExecutionIntentInput([
            'status' => 'done',
            'product_key' => 'demand-forecast',
            'hotel_scope' => ['hotel_id' => 7],
            'readiness' => [
                'stage' => 'research_ready_for_execution',
                'execution_ready' => true,
            ],
            'result' => [
                'recommended_actions' => ['Review price action'],
                'data_gaps' => [],
            ],
            'gaps' => [],
        ], [
            'hotel_id' => 7,
            'platform' => '',
        ]);

        $payload = (new OperationManagementService())->buildExecutionIntentPayload([7], 7, $input, 3);

        self::assertSame('ota', $input['platform']);
        self::assertSame('ota', $payload['platform']);
    }
}
