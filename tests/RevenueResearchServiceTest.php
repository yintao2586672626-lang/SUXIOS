<?php
declare(strict_types=1);

namespace Tests;

use app\service\AiDecisionQualityService;
use app\service\RevenueResearchService;
use app\service\RevenueOperationsKnowledgeService;
use app\service\OperationManagementService;
use app\service\OutboundUrlGuard;
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
            self::assertNotEmpty($product['knowledge_module_ids'] ?? [], 'Every research product needs an action-knowledge module contract.');
            self::assertSame('ota', $product['action_platform'] ?? null);
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
            'decision_recommendations' => [
                $this->readyDecisionRecommendation('进入收益管理复核未来7天订单预测，并记录实际订单偏差率'),
            ],
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
            'knowledge_context' => [
                'entry_count' => 1,
                'entries' => [[
                    'chunk_id' => 901,
                    'unit_id' => 91,
                    'module_id' => 'hotel_revenue_success_practices_extension',
                    'platforms' => ['ota_generic'],
                    'source_refs' => ['revenue_practice_source'],
                    'knowledge_gate' => [
                        'decision_safe' => true,
                        'task_draft_safe' => true,
                    ],
                ]],
                'data_gaps' => [],
            ],
            'result' => [
                'title' => '需求预测经营预测',
                'summary' => '未来 7 天需求上升',
                'recommended_actions' => ['复核未来 7 天价格策略'],
                'decision_recommendations' => [
                    $this->readyDecisionRecommendation('复核未来 7 天价格策略'),
                ],
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
        $service = new RevenueResearchService(static fn(array $filters): array => [
            'status' => 'available',
            'entry_count' => 1,
            'entries' => [[
                'chunk_id' => 903,
                'unit_id' => 93,
                'module_id' => 'hotel_revenue_success_practices_extension',
                'platforms' => ['ota_generic'],
                'source_refs' => ['revenue_practice_source'],
                'knowledge_gate' => [
                    'decision_safe' => true,
                    'task_draft_safe' => true,
                ],
            ]],
            'data_gaps' => [],
        ]);
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
            'knowledge_context' => [
                'entry_count' => 1,
                'entries' => [[
                    'chunk_id' => 903,
                    'unit_id' => 93,
                    'module_id' => 'hotel_revenue_success_practices_extension',
                    'platforms' => ['ota_generic'],
                    'source_refs' => ['revenue_practice_source'],
                    'knowledge_gate' => [
                        'decision_safe' => true,
                        'task_draft_safe' => true,
                    ],
                ]],
                'data_gaps' => [],
            ],
            'result' => [
                'recommended_actions' => ['复核未来 7 天价格策略'],
                'decision_recommendations' => [
                    $this->readyDecisionRecommendation('复核未来 7 天价格策略'),
                ],
            ],
        ], [
            'source_record_id' => 903,
            'hotel_id' => 7,
        ]);

        self::assertSame('revenue_research', $input['source_module']);
        self::assertSame(903, $input['source_record_id']);
        self::assertSame('pending_revenue_research_execution', $input['target_value']['tracking_status']);
        self::assertTrue($input['evidence']['execution_ready']);
        self::assertContains('knowledge_chunks#903', $input['evidence']['evidence_refs']);
        self::assertSame(903, $input['evidence']['recommendation_knowledge_binding']['chunk_id']);
    }

    public function testActionKnowledgeBindingUsesServerModulePlatformAndCanonicalReferences(): void
    {
        $service = new RevenueResearchService();
        $recommendation = $this->readyDecisionRecommendation('在携程复核未来 7 天价格策略并记录订单偏差');
        $recommendation['knowledge_refs'] = ['model_supplied#999'];
        $recommendation['recommendation_knowledge_binding'] = [
            'status' => 'bound',
            'chunk_id' => 999,
        ];

        $input = $service->buildExecutionIntentInput([
            'status' => 'done',
            'product_key' => 'price-elasticity',
            'hotel_scope' => ['hotel_id' => 7],
            'readiness' => [
                'stage' => 'research_ready_for_execution',
                'execution_ready' => true,
            ],
            'knowledge_context' => [
                'entry_count' => 3,
                'entries' => [
                    $this->actionKnowledgeEntry(710, 71, 'traffic_operation_management_golden_sentences', ['ctrip']),
                    $this->actionKnowledgeEntry(711, 72, 'traffic_operation_management_golden_sentences', ['meituan']),
                    $this->actionKnowledgeEntry(712, 73, 'unrelated_module', ['ctrip']),
                ],
                'data_gaps' => [],
            ],
            'result' => [
                'recommended_actions' => [$recommendation['action']],
                'decision_recommendations' => [$recommendation],
                'data_gaps' => [],
            ],
            'gaps' => [],
        ], [
            'source_record_id' => 710,
            'hotel_id' => 7,
        ]);

        $binding = $input['evidence']['recommendation_knowledge_binding'];
        self::assertSame('bound', $binding['status']);
        self::assertSame('ctrip', $binding['action_platform']);
        self::assertSame('traffic_operation_management_golden_sentences', $binding['module_id']);
        self::assertSame(710, $binding['chunk_id']);
        self::assertSame(71, $binding['unit_id']);
        self::assertSame(['knowledge_chunks#710', 'knowledge_units#71'], $binding['canonical_refs']);
        self::assertSame($binding['canonical_refs'], $input['target_value']['decision_recommendation']['knowledge_refs']);
        self::assertContains('knowledge_chunks#710', $input['evidence']['evidence_refs']);
        self::assertNotContains('model_supplied#999', $input['evidence']['evidence_refs']);
    }

    public function testMissingActionKnowledgeCanOnlyTightenRecommendation(): void
    {
        $service = new RevenueResearchService();
        $products = $this->invokeNonPublic($service, 'products');
        $recommendation = $this->readyDecisionRecommendation('在携程复核价格策略并记录订单偏差');
        $recommendation['knowledge_refs'] = ['model_supplied#999'];

        $bound = $this->invokeNonPublic($service, 'applyActionKnowledgeBindings', [[
            $recommendation,
        ], $products['price-elasticity'], [
            'entry_count' => 5,
            'entries' => [
                array_replace_recursive(
                    $this->actionKnowledgeEntry(720, 72, 'traffic_operation_management_golden_sentences', ['ctrip']),
                    ['knowledge_gate' => ['decision_safe' => false, 'task_draft_safe' => true]]
                ),
                array_replace_recursive(
                    $this->actionKnowledgeEntry(721, 72, 'traffic_operation_management_golden_sentences', ['ctrip']),
                    ['knowledge_gate' => ['decision_safe' => true, 'task_draft_safe' => false]]
                ),
                $this->actionKnowledgeEntry(722, 72, 'unrelated_module', ['ctrip']),
                $this->actionKnowledgeEntry(723, 72, 'traffic_operation_management_golden_sentences', ['meituan']),
                array_replace($this->actionKnowledgeEntry(724, 72, 'traffic_operation_management_golden_sentences', ['ctrip']), [
                    'unit_id' => 0,
                    'source_refs' => [],
                ]),
            ],
            'data_gaps' => [],
        ]]);

        self::assertCount(1, $bound);
        self::assertFalse($bound[0]['can_create_execution_intent']);
        self::assertFalse($bound[0]['decision_quality']['execution_ready']);
        self::assertContains('knowledge_binding', $bound[0]['decision_quality']['missing_fields']);
        self::assertSame('blocked', $bound[0]['recommendation_knowledge_binding']['status']);
        self::assertSame('action_knowledge_binding_missing', $bound[0]['recommendation_knowledge_binding']['reason_code']);
        self::assertSame([], $bound[0]['knowledge_refs']);
        self::assertStringContainsString('action-specific current knowledge binding is missing', $bound[0]['blocked_reason']);
    }

    public function testReadyExecutionRevalidatesCurrentKnowledgeInsteadOfTrustingArchivedBinding(): void
    {
        $service = new RevenueResearchService(fn(array $filters): array => [
            'status' => 'available',
            'entry_count' => 1,
            'entries' => [
                $this->actionKnowledgeEntry(731, 73, 'traffic_operation_management_golden_sentences', ['meituan']),
            ],
            'data_gaps' => [],
        ]);
        $recommendation = $this->readyDecisionRecommendation('在携程复核价格策略并记录订单偏差');
        $recommendation['knowledge_refs'] = ['knowledge_chunks#999'];
        $recommendation['recommendation_knowledge_binding'] = [
            'status' => 'bound',
            'chunk_id' => 999,
            'unit_id' => 999,
            'canonical_refs' => ['knowledge_chunks#999'],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(422);
        $this->expectExceptionMessage('action_knowledge_binding_missing');
        $service->buildReadyExecutionIntentInput([
            'status' => 'done',
            'product_key' => 'price-elasticity',
            'hotel_scope' => ['hotel_id' => 7],
            'readiness' => [
                'stage' => 'research_ready_for_execution',
                'execution_ready' => true,
                'missing_evidence' => [],
            ],
            'knowledge_context' => [
                'entry_count' => 1,
                'entries' => [
                    $this->actionKnowledgeEntry(999, 999, 'traffic_operation_management_golden_sentences', ['ctrip']),
                ],
                'data_gaps' => [],
            ],
            'result' => [
                'recommended_actions' => [$recommendation['action']],
                'decision_recommendations' => [$recommendation],
                'data_gaps' => [],
            ],
            'gaps' => [],
        ], [
            'source_record_id' => 731,
            'hotel_id' => 7,
        ]);
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

    public function testBusinessForecastSelectorKeepsOnlyTrustedCanonicalHotelDailyFacts(): void
    {
        $service = new RevenueResearchService();
        $rows = $this->invokeNonPublic($service, 'selectCanonicalOnlineOperatingRows', [[
            [
                'id' => 17652,
                'system_hotel_id' => 80,
                'hotel_id' => '130079194',
                'data_date' => '2026-07-15',
                'source' => 'ctrip',
                'data_type' => 'business',
                'dimension' => '',
                'compare_type' => 'self',
                'data_period' => 'historical_daily',
                'is_final' => 1,
                'validation_status' => 'verified',
                'source_trace_id' => 'trace-17652',
                'ingestion_method' => 'browser_profile',
                'snapshot_time' => '2026-07-15 09:10:00',
                'readback_verified' => 1,
                'update_time' => '2026-07-15 09:15:46',
                'amount' => 5939,
                'quantity' => 7,
                'book_order_num' => 11,
                'hotel_name' => '敦煌漠蓝新',
            ],
            [
                'id' => 34952,
                'system_hotel_id' => 80,
                'data_date' => '2026-07-15',
                'source' => 'ctrip',
                'data_type' => 'business',
                'dimension' => 'catalog:business_overview:business_flow_compete:order_count',
                'compare_type' => 'self',
                'data_period' => 'historical_daily',
                'is_final' => 1,
                'validation_status' => 'verified',
                'source_trace_id' => 'trace-34952',
                'update_time' => '2026-07-15 09:13:33',
                'amount' => 377223.9,
                'quantity' => 0,
                'book_order_num' => 288,
                'hotel_name' => '敦煌漠蓝新',
            ],
            [
                'id' => 90000,
                'system_hotel_id' => 80,
                'data_date' => '2026-07-15',
                'source' => 'ctrip',
                'data_type' => 'business',
                'dimension' => '',
                'validation_status' => 'unverified',
                'compare_type' => 'self',
                'data_period' => 'historical_daily',
                'is_final' => 1,
                'source_trace_id' => 'trace-90000',
                'update_time' => '2026-07-15 10:00:00',
                'amount' => 999999,
                'quantity' => 999,
                'book_order_num' => 999,
                'hotel_name' => '未验证样本',
            ],
            [
                'id' => 90001,
                'system_hotel_id' => 80,
                'data_date' => '2026-07-14',
                'source' => 'ctrip',
                'data_type' => 'business',
                'dimension' => '',
                'compare_type' => 'competitor',
                'data_period' => 'historical_daily',
                'is_final' => 1,
                'validation_status' => 'verified',
                'source_trace_id' => 'trace-competitor',
                'amount' => 88888,
                'quantity' => 88,
                'book_order_num' => 88,
            ],
            [
                'id' => 90002,
                'system_hotel_id' => 80,
                'data_date' => '2026-07-14',
                'source' => 'ctrip',
                'data_type' => 'business',
                'dimension' => '',
                'compare_type' => 'self',
                'data_period' => 'realtime_snapshot',
                'is_final' => 0,
                'validation_status' => 'verified',
                'source_trace_id' => 'trace-realtime',
                'amount' => 77777,
                'quantity' => 77,
                'book_order_num' => 77,
            ],
            [
                'id' => 90003,
                'system_hotel_id' => 80,
                'data_date' => '2026-07-14',
                'source' => 'ctrip',
                'data_type' => 'order',
                'dimension' => '',
                'compare_type' => 'self',
                'data_period' => 'historical_daily',
                'is_final' => 1,
                'validation_status' => 'verified',
                'source_trace_id' => 'trace-single-order',
                'amount' => 66666,
                'quantity' => 66,
                'book_order_num' => 1,
            ],
        ]]);

        self::assertCount(1, $rows);
        self::assertSame(17652, $rows[0]['id']);
        self::assertSame(5939.0, $rows[0]['amount']);
        self::assertSame('敦煌漠蓝新', $rows[0]['hotel_name']);
    }

    public function testRevenueOperationsKnowledgeIsWiredIntoAiPromptWithProtectedBoundary(): void
    {
        $service = new RevenueResearchService();
        $prompt = $this->invokeNonPublic($service, 'buildPrompt', [
            ['name' => '需求预测', 'module' => '收益研究', 'query' => 'pickup'],
            [],
            [],
            ['available' => true, 'method' => 'trusted daily facts'],
            false,
            [
                'status' => 'available',
                'source' => RevenueOperationsKnowledgeService::SOURCE,
                'entries' => [[
                    'chunk_id' => 7,
                    'unit_id' => 8,
                    'module_id' => 'traffic_operation_management_golden_sentences',
                    'platforms' => ['ota_generic'],
                    'source_refs' => ['knowledge_contract_source'],
                    'scope' => 'generic_methodology',
                    'knowledge_gate' => ['decision_safe' => true, 'task_draft_safe' => true],
                    'knowledge_type' => '建议卡契约',
                    'content' => ['readiness_rule' => 'missing facts means data gaps only'],
                ]],
                'protected_boundary' => 'never becomes current-hotel fact or an automatic OTA write instruction',
            ],
        ]);

        self::assertStringContainsString(RevenueOperationsKnowledgeService::SOURCE, $prompt);
        self::assertStringContainsString('不得替代当前酒店事实或触发 OTA 写入', $prompt);
        self::assertStringContainsString('generic_methodology', $prompt);
    }

    public function testReferenceOnlyKnowledgeIsWithheldFromActionPromptAndBlocksExecution(): void
    {
        $service = new RevenueResearchService();
        $context = $this->invokeNonPublic($service, 'decisionSafeKnowledgeContext', [[
            'status' => 'partial',
            'entry_count' => 2,
            'entries' => [
                [
                    'chunk_id' => 1,
                    'unit_id' => 10,
                    'module_id' => 'traffic_operation_management_golden_sentences',
                    'platforms' => ['ota_generic'],
                    'source_refs' => ['safe_source'],
                    'content' => ['rule' => 'SAFE_RULE'],
                    'knowledge_gate' => ['decision_safe' => true, 'task_draft_safe' => true],
                ],
                [
                    'chunk_id' => 2,
                    'content' => ['rule' => 'UNSAFE_RULE'],
                    'knowledge_gate' => [
                        'decision_safe' => false,
                        'status' => 'reference_only',
                    ],
                ],
            ],
            'data_gaps' => [[
                'code' => 'knowledge_review_due',
                'label' => 'review due',
            ]],
        ]]);

        self::assertSame([1], array_column($context['entries'], 'chunk_id'));
        self::assertSame(1, $context['excluded_for_decision_count']);
        self::assertFalse($context['execution_ready']);
        self::assertContains('knowledge_review_due', $context['data_gap_codes']);

        $prompt = $this->invokeNonPublic($service, 'buildPrompt', [
            ['name' => 'demand', 'module' => 'revenue', 'query' => 'pickup'],
            [],
            [],
            ['decision_ready' => true],
            false,
            $context,
        ]);
        self::assertStringContainsString('SAFE_RULE', $prompt);
        self::assertStringNotContainsString('UNSAFE_RULE', $prompt);
    }

    public function testDecisionSafeReferenceCannotUnlockTaskDrafting(): void
    {
        $service = new RevenueResearchService();
        $context = $this->invokeNonPublic($service, 'decisionSafeKnowledgeContext', [[
            'status' => 'available',
            'entry_count' => 1,
            'entries' => [[
                'chunk_id' => 31,
                'content' => ['rule' => 'REFERENCE_METHOD_ONLY'],
                'knowledge_gate' => [
                    'decision_safe' => true,
                    'task_draft_safe' => false,
                ],
            ]],
            'data_gaps' => [],
        ]]);

        self::assertSame(1, $context['decision_safe_entry_count']);
        self::assertSame(0, $context['task_draft_safe_entry_count']);
        self::assertSame(1, $context['excluded_by_task_draft_gate_count']);
        self::assertSame([], $context['entries']);
        self::assertFalse($context['execution_ready']);
        self::assertContains('task_draft_safe_knowledge_missing', $context['data_gap_codes']);
        self::assertSame('blocked', $context['status']);
    }

    public function testCanonicalSelectorPrefersExplicitZeroMetricsOverNewerMissingFields(): void
    {
        $service = new RevenueResearchService();
        $base = [
            'system_hotel_id' => 80,
            'hotel_id' => '130079194',
            'data_date' => '2026-07-14',
            'source' => 'ctrip',
            'data_type' => 'business',
            'dimension' => '',
            'compare_type' => 'self',
            'data_period' => 'historical_daily',
            'is_final' => 1,
            'validation_status' => 'verified',
            'source_trace_id' => 'trace-zero',
            'ingestion_method' => 'browser_profile',
            'snapshot_time' => '2026-07-14 08:50:00',
            'readback_verified' => 1,
        ];
        $rows = $this->invokeNonPublic($service, 'selectCanonicalOnlineOperatingRows', [[
            array_merge($base, [
                'id' => 1,
                'update_time' => '2026-07-14 09:00:00',
                'amount' => 0,
                'quantity' => 0,
                'book_order_num' => 0,
            ]),
            array_merge($base, [
                'id' => 2,
                'update_time' => '2026-07-14 10:00:00',
                'amount' => null,
                'quantity' => null,
                'book_order_num' => null,
            ]),
        ]]);

        self::assertCount(1, $rows);
        self::assertSame(1, $rows[0]['id']);
        self::assertSame(0.0, $rows[0]['amount']);
        self::assertSame(0.0, $rows[0]['quantity']);
        self::assertSame(0.0, $rows[0]['book_order_num']);
    }

    public function testCanonicalForecastSelectorRequiresReadbackCollectionTimeAndNonManualSource(): void
    {
        $service = new RevenueResearchService();
        $base = [
            'system_hotel_id' => 80,
            'hotel_id' => '130079194',
            'hotel_name' => '测试酒店',
            'data_date' => '2026-07-14',
            'source' => 'ctrip',
            'data_type' => 'business',
            'dimension' => '',
            'compare_type' => 'self',
            'data_period' => 'historical_daily',
            'is_final' => 1,
            'validation_status' => 'verified',
            'source_trace_id' => 'trace-base',
            'ingestion_method' => 'browser_profile',
            'snapshot_time' => '2026-07-14 08:50:00',
            'readback_verified' => 1,
            'amount' => 100,
            'quantity' => 2,
            'book_order_num' => 1,
        ];
        $rows = $this->invokeNonPublic($service, 'selectCanonicalOnlineOperatingRows', [[
            array_merge($base, ['id' => 1, 'readback_verified' => 0]),
            array_merge($base, ['id' => 2, 'snapshot_time' => '']),
            array_merge($base, ['id' => 3, 'ingestion_method' => 'manual_import']),
            array_merge($base, ['id' => 4, 'validation_status' => 'partial']),
            array_merge($base, ['id' => 5, 'failed_reason' => 'capture_failed']),
            array_merge($base, ['id' => 6, 'source_trace_id' => 'trace-ready']),
        ]]);

        self::assertCount(1, $rows);
        self::assertSame(6, $rows[0]['id']);
    }

    public function testForecastSelectorUsesSharedCtripAndMeituanStandardFacts(): void
    {
        $service = new RevenueResearchService();
        $sourceUrlHash = str_repeat('b', 64);
        $fact = static function (
            string $metricKey,
            string $storageField,
            string $sourcePath,
            string $traceId
        ) use ($sourceUrlHash): array {
            return [
                'metric_key' => $metricKey,
                'storage_field' => $storageField,
                'source_path' => $sourcePath,
                'source_key' => match ($metricKey) {
                    'order_amount' => 'amount',
                    'room_nights' => 'quantity',
                    'order_count' => 'book_order_num',
                    default => $metricKey,
                },
                'status' => 'captured',
                'stored_value_present' => true,
                'capture_evidence' => [
                    'source_trace_id' => $traceId,
                    'source_url_hash' => $sourceUrlHash,
                ],
            ];
        };
        $common = [
            'system_hotel_id' => 80,
            'data_date' => '2026-07-29',
            'compare_type' => 'self',
            'data_period' => 'historical_daily',
            'is_final' => 1,
            'readback_verified' => 1,
            'ingestion_method' => 'browser_profile',
            'snapshot_time' => '2026-07-30 10:06:10',
            'source_url_hash' => $sourceUrlHash,
        ];
        $ctripCheckoutTrace = 'ctrip:' . str_repeat('1', 64);
        $ctripCapacityTrace = 'ctrip:' . str_repeat('2', 64);
        $meituanBusinessTrace = 'meituan:' . str_repeat('3', 64);
        $meituanOrderTrace = 'meituan:' . str_repeat('4', 64);

        $rows = $this->invokeNonPublic($service, 'selectCanonicalOnlineOperatingRows', [[
            array_replace($common, [
                'id' => 68698,
                'hotel_id' => 'ctrip-hotel-80',
                'hotel_name' => 'Ctrip Checkout',
                'source' => 'ctrip',
                'data_type' => 'business',
                'dimension' => 'catalog:business_overview:business_market_overview:order_amount:data.amount',
                'validation_status' => 'partial',
                'amount' => 2168,
                'quantity' => 3,
                'sync_task_id' => 2042,
                'source_trace_id' => $ctripCheckoutTrace,
                'raw_data' => json_encode([
                    'row' => [
                        'endpoint_id' => 'business_market_overview',
                        'amount' => 2168,
                        'quantity' => 3,
                    ],
                    'field_facts' => [
                        $fact('order_amount', 'online_daily_data.amount', 'data.amount', $ctripCheckoutTrace),
                        $fact('room_nights', 'online_daily_data.quantity', 'data.quantity', $ctripCheckoutTrace),
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ]),
            array_replace($common, [
                'id' => 68699,
                'hotel_id' => 'ctrip-hotel-80',
                'hotel_name' => 'Ctrip Capacity',
                'source' => 'ctrip',
                'data_type' => 'business',
                'dimension' => 'catalog:business_overview:business_capacity:occupied_rooms:occupiedRooms',
                'validation_status' => 'partial',
                'quantity' => 2,
                'book_order_num' => 1,
                'sync_task_id' => 2042,
                'source_trace_id' => $ctripCapacityTrace,
                'raw_data' => json_encode([
                    'row' => [
                        'endpoint_id' => 'business_capacity',
                        'quantity' => 2,
                        'book_order_num' => 1,
                    ],
                    'field_facts' => [
                        $fact('order_count', 'online_daily_data.book_order_num', 'data.bookOrderNum', $ctripCapacityTrace),
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ]),
            array_replace($common, [
                'id' => 68703,
                'hotel_id' => 'ctrip-hotel-80',
                'hotel_name' => 'Ctrip Booking Fields',
                'source' => 'ctrip',
                'data_type' => 'business',
                'dimension' => '',
                'validation_status' => 'normal',
                'amount' => 3322,
                'quantity' => 4,
                'book_order_num' => 2,
                'sync_task_id' => 2042,
                'source_trace_id' => 'ctrip:' . str_repeat('5', 64),
                'raw_data' => json_encode([
                    'row' => [
                        'endpoint_id' => 'business_market_overview',
                        'bookAmount' => 3322,
                        'bookQuantity' => 4,
                        'bookOrderNum' => 2,
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ]),
            array_replace($common, [
                'id' => 68706,
                'hotel_id' => 'meituan-hotel-80',
                'hotel_name' => 'Meituan Business Cards',
                'source' => 'meituan',
                'data_type' => 'business',
                'dimension' => '',
                'validation_status' => 'normal',
                'amount' => 5272.34,
                'quantity' => 6,
                'book_order_num' => 3,
                'sync_task_id' => 2043,
                'source_trace_id' => $meituanBusinessTrace,
                'raw_data' => json_encode([
                    'row' => [
                        'sales_amount' => 5272.34,
                        'sales_room_nights' => 6,
                        'sales_avg_price' => 878.72,
                        'amount' => 5272.34,
                        'quantity' => 6,
                        'room_nights' => 6,
                        'book_order_num' => 3,
                        'compare_type' => 'self',
                        'is_self' => true,
                        'business_evidence_source' => 'page.business_period_selection.readback',
                        'date_source' => 'page.business_period_selection.readback',
                        'date_scope_evidence' => 'meituan_business_yesterday_tab',
                        '_capture_source' => 'xhr:traffic:business_data',
                        '_meituan_business_metric_sources' => [
                            'sales_amount' => [
                                'source_path' => 'data.cards.7.value',
                                'source_kind' => 'card',
                            ],
                            'sales_room_nights' => [
                                'source_path' => 'data.cards.5.value',
                                'source_kind' => 'card',
                            ],
                        ],
                    ],
                    'field_facts' => [
                        $fact('order_amount', 'online_daily_data.amount', 'data.amount', $meituanBusinessTrace),
                        $fact('room_nights', 'online_daily_data.quantity', 'data.quantity', $meituanBusinessTrace),
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ]),
            array_replace($common, [
                'id' => 66381,
                'hotel_id' => 'meituan-hotel-80',
                'hotel_name' => 'Meituan Old Order Aggregate',
                'source' => 'meituan',
                'data_type' => 'order',
                'dimension' => '',
                'validation_status' => 'normal',
                'amount' => 5501.8,
                'quantity' => 6,
                'book_order_num' => 3,
                'sync_task_id' => 1990,
                'source_trace_id' => $meituanOrderTrace,
                'raw_data' => json_encode([
                    'row' => [
                        'amount' => 5501.8,
                        'quantity' => 6,
                        'room_nights' => 6,
                        'compare_type' => 'self',
                        'is_self' => true,
                        'amount_scope' => 'meituan_sale_price_total',
                        'pagination_complete' => true,
                        'floor_price_used_as_revenue' => false,
                        'guarantee_amount_used_as_revenue' => false,
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ]),
        ]]);

        self::assertSame([68698, 68699, 68706], array_column($rows, 'id'));
        self::assertSame(7440.34, array_sum(array_column($rows, 'amount')));
        self::assertSame(9.0, array_sum(array_column($rows, 'quantity')));
        self::assertSame(4.0, array_sum(array_column($rows, 'book_order_num')));
        self::assertSame(
            ['ctrip_checkout_daily', 'ctrip_capacity_daily', 'meituan_business_sales_daily'],
            array_column($rows, 'metric_semantic_scope')
        );
    }

    public function testBusinessForecastTruthContextExposesFullOtaEvidenceBoundary(): void
    {
        $service = new RevenueResearchService();
        $truth = $this->invokeNonPublic($service, 'businessForecastTruthContext', [[[
            'id' => 16,
            'system_hotel_id' => 80,
            'hotel_name' => '测试酒店',
            'data_date' => '2026-07-14',
            'source' => 'ctrip',
            'source_trace_id' => 'trace-16',
            'ingestion_method' => 'browser_profile',
            'snapshot_time' => '2026-07-14 08:50:00',
            'readback_verified' => 1,
        ]]]);

        self::assertSame('verified', $truth['status']);
        self::assertSame('已验证', $truth['status_label']);
        self::assertSame('ota_channel', $truth['metric_scope']);
        self::assertSame('OTA渠道指标，不代表全酒店经营', $truth['scope_label']);
        self::assertSame(80, $truth['hotels'][0]['system_hotel_id']);
        self::assertSame(['ctrip'], $truth['platforms']);
        self::assertSame('2026-07-14', $truth['date_range']['start']);
        self::assertSame('browser_profile', $truth['source']['methods'][0]);
        self::assertSame('2026-07-14 08:50:00', $truth['collected_at_range']['start']);
        self::assertTrue($truth['persistence']['stored']);
        self::assertTrue($truth['persistence']['readback_verified']);
        self::assertSame('rule_forecast', $truth['result_layer']);
    }

    public function testSparseDailyMetricsRemainNullAndBlockForecastDecision(): void
    {
        $service = new RevenueResearchService();
        $forecast = $this->invokeNonPublic($service, 'buildBusinessForecastFromRows', [[
            ['data_date' => '2026-07-10', 'amount' => 0, 'quantity' => null, 'book_order_num' => null, 'source' => 'ctrip'],
            ['data_date' => '2026-07-11', 'amount' => 120, 'quantity' => null, 'book_order_num' => null, 'source' => 'ctrip'],
            ['data_date' => '2026-07-12', 'amount' => 180, 'quantity' => null, 'book_order_num' => null, 'source' => 'ctrip'],
        ]]);

        self::assertFalse($forecast['available']);
        self::assertFalse($forecast['decision_ready']);
        self::assertSame('ota_channel', $forecast['metric_scope']);
        self::assertSame(3, $forecast['metric_sample_days']['revenue']);
        self::assertSame(0, $forecast['metric_sample_days']['room_nights']);
        self::assertSame(0, $forecast['metric_sample_days']['orders']);
        self::assertSame(0, $forecast['complete_sample_days']);
        self::assertContains('room_nights_sample_days_below_3', $forecast['data_gaps']);
        self::assertContains('orders_sample_days_below_3', $forecast['data_gaps']);
        self::assertStringContainsString('未生成经营预测', $forecast['message']);
        self::assertArrayNotHasKey('forecast_7d', $forecast);
    }

    public function testWindowAggregationPreservesExplicitZeroAndDoesNotAlignDifferentMissingDays(): void
    {
        $service = new RevenueResearchService();
        $window = $this->invokeNonPublic($service, 'aggregateWindow', [[
            ['date' => '2026-07-10', 'revenue' => 0, 'room_nights' => null, 'orders' => null],
            ['date' => '2026-07-11', 'revenue' => null, 'room_nights' => 2, 'orders' => null],
            ['date' => '2026-07-12', 'revenue' => null, 'room_nights' => null, 'orders' => 1],
        ]]);

        self::assertSame(0.0, $window['revenue']);
        self::assertSame(2.0, $window['room_nights']);
        self::assertSame(1.0, $window['orders']);
        self::assertSame(['revenue' => 1, 'room_nights' => 1, 'orders' => 1], $window['metric_sample_days']);
        self::assertNull($window['adr'], 'Revenue and room nights from different days cannot form ADR.');
        self::assertNull($window['aov'], 'Revenue and orders from different days cannot form AOV.');
        self::assertContains('adr_not_calculable', $window['data_gaps']);
        self::assertContains('aov_not_calculable', $window['data_gaps']);
    }

    public function testCompleteAlignedOtaDailySamplesProduceDecisionReadyForecast(): void
    {
        $service = new RevenueResearchService();
        $forecast = $this->invokeNonPublic($service, 'buildBusinessForecastFromRows', [[
            ['data_date' => '2026-07-10', 'amount' => 0, 'quantity' => 1, 'book_order_num' => 1, 'source' => 'ctrip'],
            ['data_date' => '2026-07-11', 'amount' => 200, 'quantity' => 2, 'book_order_num' => 2, 'source' => 'ctrip'],
            ['data_date' => '2026-07-12', 'amount' => 300, 'quantity' => 3, 'book_order_num' => 3, 'source' => 'ctrip'],
        ], '2026-07-13 12:00:00']);

        self::assertTrue($forecast['available']);
        self::assertTrue($forecast['decision_ready']);
        self::assertSame('ota_channel', $forecast['metric_scope']);
        self::assertSame(3, $forecast['sample_days']);
        self::assertSame(['revenue' => 3, 'room_nights' => 3, 'orders' => 3], $forecast['metric_sample_days']);
        self::assertSame([], $forecast['data_gaps']);
        self::assertTrue($forecast['forecast_7d']['decision_ready']);
        self::assertNull($forecast['forecast_7d']['trend_adjustment_percent']);
        self::assertNotNull($forecast['forecast_7d']['adr']);
        self::assertNotNull($forecast['forecast_7d']['aov']);
    }

    public function testStaleOrNonContiguousCompleteSamplesCannotDriveForecast(): void
    {
        $service = new RevenueResearchService();
        $stale = $this->invokeNonPublic($service, 'buildBusinessForecastFromRows', [[
            ['data_date' => '2026-07-08', 'amount' => 100, 'quantity' => 1, 'book_order_num' => 1, 'source' => 'ctrip'],
            ['data_date' => '2026-07-09', 'amount' => 200, 'quantity' => 2, 'book_order_num' => 2, 'source' => 'ctrip'],
            ['data_date' => '2026-07-10', 'amount' => 300, 'quantity' => 3, 'book_order_num' => 3, 'source' => 'ctrip'],
        ], '2026-07-15 12:00:00']);
        $nonContiguous = $this->invokeNonPublic($service, 'buildBusinessForecastFromRows', [[
            ['data_date' => '2026-07-10', 'amount' => 100, 'quantity' => 1, 'book_order_num' => 1, 'source' => 'ctrip'],
            ['data_date' => '2026-07-12', 'amount' => 200, 'quantity' => 2, 'book_order_num' => 2, 'source' => 'ctrip'],
            ['data_date' => '2026-07-13', 'amount' => 300, 'quantity' => 3, 'book_order_num' => 3, 'source' => 'ctrip'],
        ], '2026-07-14 12:00:00']);

        self::assertFalse($stale['decision_ready']);
        self::assertContains('latest_complete_operating_day_stale', $stale['data_gaps']);
        self::assertSame(4, $stale['sample_freshness']['staleness_days']);
        self::assertFalse($nonContiguous['decision_ready']);
        self::assertContains('complete_operating_series_not_contiguous', $nonContiguous['data_gaps']);
        self::assertSame('non_contiguous', $nonContiguous['sample_freshness']['continuity_status']);
    }

    public function testForecastAndDailyRowsKeepMissingOperandsNull(): void
    {
        $service = new RevenueResearchService();
        $forecast = $this->invokeNonPublic($service, 'forecastWindow', [[
            'avg_daily_revenue' => 100,
            'avg_daily_room_nights' => 2,
            'avg_daily_orders' => null,
            'adr' => 50,
            'aov' => null,
        ], 7, null]);
        $daily = $this->invokeNonPublic($service, 'buildDailyForecast', [$forecast, null]);

        self::assertSame(700.0, $forecast['revenue']);
        self::assertSame(14.0, $forecast['room_nights']);
        self::assertNull($forecast['orders']);
        self::assertNull($forecast['aov']);
        self::assertFalse($forecast['decision_ready']);
        self::assertNull($daily[0]['orders']);
        self::assertSame('partial', $daily[0]['data_status']);
    }

    public function testPendingDataSummaryCannotClaimForecastWasGenerated(): void
    {
        $service = new RevenueResearchService();
        $result = $this->invokeNonPublic($service, 'normalizeAiResult', [[
            'summary' => '已生成经营预测，未来收入上涨。',
            'key_metrics' => ['未来收入 99999 元'],
            'recommended_actions' => ['先补数据'],
        ], [
            'name' => '需求预测',
            'module' => '收益研究',
        ], [], [
            'available' => false,
            'decision_ready' => false,
            'message' => 'OTA 渠道订单和间夜样本不足，未生成经营预测。',
            'data_gaps' => ['orders_sample_days_below_3', 'room_nights_sample_days_below_3'],
        ]]);

        self::assertFalse($result['decision_ready']);
        self::assertSame('ota_channel', $result['metric_scope']);
        self::assertStringNotContainsString('已生成', $result['summary']);
        self::assertStringContainsString('未生成经营预测', $result['summary']);
        self::assertSame([], $result['key_metrics']);
        self::assertContains('orders_sample_days_below_3', $result['data_gaps']);
    }

    public function testModelInventedRevenueAdrAndGrowthAreRejected(): void
    {
        $service = new RevenueResearchService();
        $forecast = $this->trustedForecastForModelNumericValidation();
        $result = $this->invokeNonPublic($service, 'normalizeAiResult', [[
            'summary' => '未来7天预测收入99999元，ADR 888元，增长率66%。',
            'key_metrics' => ['预测收入99999元', 'ADR 888元', '增长率66%'],
            'risk_signals' => ['收入可能下降66%'],
            'recommended_actions' => ['将ADR调整为888元'],
        ], [
            'key' => 'demand-forecast',
            'name' => '需求预测',
            'module' => '收益研究',
        ], [], $forecast]);

        $modelText = implode("\n", array_merge(
            [$result['summary']],
            $result['key_metrics'],
            $result['risk_signals'],
            $result['recommended_actions']
        ));
        self::assertStringNotContainsString('99999', $modelText);
        self::assertStringNotContainsString('888', $modelText);
        self::assertStringNotContainsString('66', $modelText);
        self::assertContains('model_numeric_claim_unverified', $result['data_gaps']);
        self::assertTrue($result['decision_ready']);

        $readiness = $service->buildResearchReadiness([
            'key' => 'demand-forecast',
            'name' => '需求预测',
            'module' => '收益研究',
        ], 'done', [], $forecast, $result);
        self::assertSame('research_model_numeric_claim_unverified', $readiness['stage']);
        self::assertFalse($readiness['execution_ready']);
    }

    public function testLocalInventoryCountsCannotWhitelistModelBusinessNumbers(): void
    {
        $service = new RevenueResearchService();
        $forecast = $this->trustedForecastForModelNumericValidation();
        $tokens = $this->invokeNonPublic($service, 'trustedModelNumericTokens', [[[
            'source' => 'knowledge_units',
            'status' => 'available',
            'count' => 25,
            'unit_id' => 888,
        ]], $forecast]);

        self::assertArrayNotHasKey('25', $tokens);
        self::assertArrayNotHasKey('888', $tokens);
        self::assertArrayHasKey('7000', $tokens);
    }

    public function testModelExactStructuredForecastNumbersAreRetained(): void
    {
        $service = new RevenueResearchService();
        $result = $this->invokeNonPublic($service, 'normalizeAiResult', [[
            'summary' => '未来7天预测收入7000元，ADR 200元，增长率5%。',
            'key_metrics' => ['预测收入7000元', 'ADR 200元', '增长率5%'],
            'risk_signals' => ['增长率5%时继续观察需求'],
            'recommended_actions' => ['第1步在2026-07-20以ADR 200元为复核基线'],
        ], [
            'key' => 'demand-forecast',
            'name' => '需求预测',
            'module' => '收益研究',
        ], [], $this->trustedForecastForModelNumericValidation()]);

        self::assertSame('未来7天预测收入7000元，ADR 200元，增长率5%。', $result['summary']);
        self::assertContains('预测收入7000元', $result['key_metrics']);
        self::assertContains('ADR 200元', $result['key_metrics']);
        self::assertContains('增长率5%', $result['key_metrics']);
        self::assertContains('增长率5%时继续观察需求', $result['risk_signals']);
        self::assertSame(['第1步在2026-07-20以ADR 200元为复核基线'], $result['recommended_actions']);
        self::assertNotContains('model_numeric_claim_unverified', $result['data_gaps']);
    }

    public function testModelProseWithoutNumbersIsUnaffected(): void
    {
        $service = new RevenueResearchService();
        $result = $this->invokeNonPublic($service, 'normalizeAiResult', [[
            'summary' => '需求保持稳定，建议人工复核。',
            'key_metrics' => ['收入趋势平稳'],
            'risk_signals' => ['竞对变化需关注'],
            'recommended_actions' => ['人工复核价格策略'],
        ], [
            'key' => 'demand-forecast',
            'name' => '需求预测',
            'module' => '收益研究',
        ], [], $this->trustedForecastForModelNumericValidation()]);

        self::assertSame('需求保持稳定，建议人工复核。', $result['summary']);
        self::assertContains('收入趋势平稳', $result['key_metrics']);
        self::assertContains('竞对变化需关注', $result['risk_signals']);
        self::assertSame(['人工复核价格策略'], $result['recommended_actions']);
        self::assertNotContains('model_numeric_claim_unverified', $result['data_gaps']);
    }

    public function testReadinessUsesDecisionReadyInsteadOfLegacyAvailableFlag(): void
    {
        $service = new RevenueResearchService();
        $products = $this->invokeNonPublic($service, 'products');
        $readiness = $service->buildResearchReadiness($products['demand-forecast'], 'pending_data', [], [
            'available' => true,
            'decision_ready' => false,
            'message' => '订单与间夜样本不完整',
        ], [
            'recommended_actions' => ['补齐订单和间夜'],
        ]);

        self::assertSame('research_forecast_missing', $readiness['stage']);
        self::assertFalse($readiness['execution_ready']);
    }

    public function testOpenAiResponsesTargetRequiresExactHostAndPinsValidatedAddress(): void
    {
        $service = new RevenueResearchService();
        $guard = new OutboundUrlGuard(static fn(string $host): array => ['93.184.216.34']);

        $target = $this->invokeNonPublic($service, 'validateOpenAiResponsesTarget', [
            'https://api.openai.com/v1',
            $guard,
        ]);
        self::assertSame('api.openai.com', $target['host']);
        self::assertSame(['api.openai.com:443:93.184.216.34'], $target['curl_resolve']);

        $this->expectException(RuntimeException::class);
        $this->invokeNonPublic($service, 'validateOpenAiResponsesTarget', [
            'https://api.openai.com.attacker.example/v1',
            $guard,
        ]);
    }

    /** @return array<string, mixed> */
    private function trustedForecastForModelNumericValidation(): array
    {
        return [
            'available' => true,
            'decision_ready' => true,
            'metric_scope' => 'ota_channel',
            'generated_at' => '2026-07-19 10:00:00',
            'confidence' => 'medium',
            'data_gaps' => [],
            'risk_signals' => [],
            'forecast_7d' => [
                'days' => 7,
                'revenue' => 7000.0,
                'room_nights' => 35.0,
                'orders' => 28.0,
                'adr' => 200.0,
                'aov' => 250.0,
                'trend_adjustment_percent' => 5.0,
                'decision_ready' => true,
            ],
            'forecast_30d' => [
                'days' => 30,
                'revenue' => 30000.0,
                'room_nights' => 150.0,
                'orders' => 120.0,
                'adr' => 200.0,
                'aov' => 250.0,
                'trend_adjustment_percent' => 5.0,
                'decision_ready' => true,
            ],
            'truth_context' => [
                'status' => 'verified',
                'metric_scope' => 'ota_channel',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function readyDecisionRecommendation(string $action): array
    {
        return [
            'title' => '收益研究执行建议',
            'action' => $action,
            'can_create_execution_intent' => true,
            'decision_quality' => [
                'contract_version' => AiDecisionQualityService::CONTRACT_VERSION,
                'execution_ready' => true,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function actionKnowledgeEntry(
        int $chunkId,
        int $unitId,
        string $moduleId,
        array $platforms
    ): array {
        return [
            'chunk_id' => $chunkId,
            'unit_id' => $unitId,
            'module_id' => $moduleId,
            'platforms' => $platforms,
            'source_refs' => ['source_' . $chunkId],
            'knowledge_gate' => [
                'decision_safe' => true,
                'task_draft_safe' => true,
            ],
        ];
    }
}
