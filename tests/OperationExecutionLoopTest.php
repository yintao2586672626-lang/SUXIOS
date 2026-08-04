<?php
declare(strict_types=1);

namespace Tests;

use app\service\AiDecisionQualityService;
use app\service\OperationManagementService;
use app\service\RevenuePricingRecommendationService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OperationExecutionLoopTest extends TestCase
{
    public function testPriceIntentIsRejectedBeforePersistenceWhenRoomOrRateMappingIsMissing(): void
    {
        $service = new OperationManagementService();
        self::assertTrue(method_exists($service, 'buildExecutionIntentPayload'), 'Missing execution intent payload builder');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('room_type_key');

        $service->buildExecutionIntentPayload([7], null, [
            'source_module' => 'root_cause',
            'source_record_id' => 15,
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'object_type' => 'price',
            'action_type' => 'price_adjust',
            'target_value' => ['target_price' => 188],
            'evidence' => ['reason' => 'price_high'],
            'expected_metric' => 'orders',
            'expected_delta' => 8,
        ], 3);

    }

    public function testCompletePromotionIntentMovesToPendingApproval(): void
    {
        $service = new OperationManagementService();
        self::assertTrue(method_exists($service, 'buildExecutionIntentPayload'), 'Missing execution intent payload builder');

        $payload = $service->buildExecutionIntentPayload([7], 7, [
            'source_module' => 'strategy_simulation',
            'source_record_id' => 22,
            'hotel_id' => 7,
            'platform' => 'meituan',
            'object_type' => 'campaign',
            'action_type' => 'promotion',
            'date_start' => '2026-05-27',
            'date_end' => '2026-05-29',
            'target_value' => [
                'campaign_type' => 'discount',
                'discount_rate' => 8,
                'budget' => 300,
                'target_metric' => 'orders',
            ],
            'evidence' => ['reason' => 'conversion_low'],
            'expected_metric' => 'orders',
            'expected_delta' => 10,
            'risk_level' => 'medium',
        ], 3);

        self::assertSame('pending_approval', $payload['status']);
        self::assertSame('', $payload['blocked_reason']);
        self::assertSame('campaign', $payload['object_type']);
        self::assertSame('medium', $payload['risk_level']);
    }

    public function testInvestmentIntentMovesToPendingApprovalWhenTrackingFieldsAreComplete(): void
    {
        $service = new OperationManagementService();

        $payload = $service->buildExecutionIntentPayload([7], 7, [
            'source_module' => 'feasibility_report',
            'source_record_id' => 31,
            'hotel_id' => 7,
            'platform' => 'investment',
            'object_type' => 'investment',
            'action_type' => 'post_decision_tracking',
            'target_value' => [
                'project_name' => 'Valid Project',
                'tracking_status' => 'pending_post_decision_tracking',
                'target_metric' => 'investment_decision_closure',
            ],
            'evidence' => [
                'readiness_stage' => 'approved_pending_tracking',
                'source_scope' => 'daily_reports:12',
            ],
            'expected_metric' => 'investment_decision_closure',
            'risk_level' => 'medium',
        ], 3);

        self::assertSame('pending_approval', $payload['status']);
        self::assertSame('', $payload['blocked_reason']);
        self::assertSame('investment', $payload['object_type']);
    }

    public function testInvestmentIntentIsBlockedWhenProjectTrackingFieldsAreMissing(): void
    {
        $service = new OperationManagementService();

        $payload = $service->buildExecutionIntentPayload([7], 7, [
            'source_module' => 'feasibility_report',
            'source_record_id' => 31,
            'hotel_id' => 7,
            'platform' => 'investment',
            'object_type' => 'investment',
            'action_type' => 'post_decision_tracking',
            'target_value' => [
                'project_name' => '',
            ],
            'evidence' => ['readiness_stage' => 'review_ready'],
        ], 3);

        self::assertSame('blocked', $payload['status']);
        self::assertStringContainsString('project_name missing', $payload['blocked_reason']);
        self::assertStringContainsString('tracking_status missing', $payload['blocked_reason']);
        self::assertStringContainsString('target_metric missing', $payload['blocked_reason']);
    }

    public function testOpeningIntentMovesToPendingApprovalWhenTrackingFieldsAreComplete(): void
    {
        $service = new OperationManagementService();

        $payload = $service->buildExecutionIntentPayload([7], 7, [
            'source_module' => 'opening',
            'source_record_id' => 9,
            'hotel_id' => 7,
            'platform' => 'internal',
            'object_type' => 'opening',
            'action_type' => 'go_live_preparation_tracking',
            'target_value' => [
                'project_name' => 'Opening Project',
                'tracking_status' => 'pending_opening_go_live_evidence',
                'target_metric' => 'opening_go_live_closure',
            ],
            'evidence' => [
                'source_scope' => 'opening_project_and_tasks',
                'days_left' => 14,
            ],
            'expected_metric' => 'opening_go_live_closure',
            'risk_level' => 'medium',
        ], 3);

        self::assertSame('pending_approval', $payload['status']);
        self::assertSame('', $payload['blocked_reason']);
        self::assertSame('opening', $payload['object_type']);
    }

    public function testExpansionIntentMovesToPendingApprovalWhenTrackingFieldsAreComplete(): void
    {
        $service = new OperationManagementService();

        $payload = $service->buildExecutionIntentPayload([7], 7, [
            'source_module' => 'expansion',
            'source_record_id' => 9,
            'hotel_id' => 7,
            'platform' => 'investment',
            'object_type' => 'expansion',
            'action_type' => 'expansion_post_decision_tracking',
            'target_value' => [
                'project_name' => 'Expansion Project',
                'tracking_status' => 'pending_expansion_post_decision_tracking',
                'target_metric' => 'expansion_project_closure',
            ],
            'evidence' => [
                'source_scope' => 'expansion_screening_and_project_decision',
                'readiness_stage' => 'approved_pending_tracking',
            ],
            'expected_metric' => 'expansion_project_closure',
            'risk_level' => 'medium',
        ], 3);

        self::assertSame('pending_approval', $payload['status']);
        self::assertSame('', $payload['blocked_reason']);
        self::assertSame('expansion', $payload['object_type']);
    }

    public function testExpansionIntentIsBlockedBeforeProjectReviewReadiness(): void
    {
        $service = new OperationManagementService();

        $payload = $service->buildExecutionIntentPayload([7], 7, [
            'source_module' => 'expansion',
            'source_record_id' => 9,
            'hotel_id' => 7,
            'platform' => 'investment',
            'object_type' => 'expansion',
            'action_type' => 'expansion_post_decision_tracking',
            'target_value' => [
                'project_name' => 'Screening Only Project',
                'tracking_status' => 'pending_expansion_post_decision_tracking',
                'target_metric' => 'expansion_project_closure',
            ],
            'evidence' => [
                'source_scope' => 'expansion_screening_and_project_decision',
                'readiness_stage' => 'screening_record_only',
            ],
            'expected_metric' => 'expansion_project_closure',
            'risk_level' => 'medium',
        ], 3);

        self::assertSame('blocked', $payload['status']);
        self::assertStringContainsString('expansion_readiness_stage screening_record_only', $payload['blocked_reason']);
    }

    public function testPriceSuggestionBuildsExecutionIntentInput(): void
    {
        $service = $this->priceIntentService();
        $today = date('Y-m-d');

        $input = $service->buildPriceSuggestionExecutionIntentInput([
            'id' => 77,
            'status' => \app\model\PriceSuggestion::STATUS_APPROVED,
            'hotel_id' => 7,
            'room_type_id' => 3,
            'suggestion_date' => '2026-06-01',
            'current_price' => 280,
            'suggested_price' => 318,
            'min_price' => 220,
            'max_price' => 380,
            'reason' => 'competitor price higher',
            'factors' => ['high forecast occupancy'],
            'competitor_data' => ['avg_price' => 330],
        ], [
            'platform' => 'ctrip',
            'room_type_key' => 'RT-1001',
            'rate_plan_key' => 'BAR',
            'expected_delta' => 8,
        ]);

        self::assertSame('price_suggestion', $input['source_module']);
        self::assertSame(77, $input['source_record_id']);
        self::assertSame('ctrip', $input['platform']);
        self::assertSame('price', $input['object_type']);
        self::assertSame('price_adjust', $input['action_type']);
        self::assertSame(280.0, $input['current_value']['current_price']);
        self::assertSame(318.0, $input['target_value']['target_price']);
        self::assertSame('RT-1001', $input['target_value']['room_type_key']);
        self::assertSame('BAR', $input['target_value']['rate_plan_key']);
        self::assertSame(8.0, $input['expected_delta']);
        self::assertSame('competitor price higher', $input['evidence']['reason']);
        self::assertSame($today, $input['date_start']);
        self::assertSame($today, $input['date_end']);
        self::assertSame('2026-06-01', $input['evidence']['source_business_date']);
        self::assertSame($today, $input['evidence']['execution_date']);
    }

    public function testPriceSuggestionExecutionIntentUsesChosenExecutionDate(): void
    {
        $service = $this->priceIntentService();
        $executionDate = date('Y-m-d', strtotime('+1 day'));

        $input = $service->buildPriceSuggestionExecutionIntentInput([
            'id' => 78,
            'status' => \app\model\PriceSuggestion::STATUS_APPROVED,
            'hotel_id' => 7,
            'room_type_id' => 3,
            'suggestion_date' => '2026-06-01',
            'current_price' => 280,
            'suggested_price' => 318,
            'min_price' => 220,
            'max_price' => 380,
            'reason' => 'competitor price higher',
            'factors' => ['high forecast occupancy'],
            'competitor_data' => ['avg_price' => 330],
        ], [
            'platform' => 'ctrip',
            'room_type_key' => 'RT-1001',
            'rate_plan_key' => 'BAR',
            'execution_date' => $executionDate,
        ]);

        self::assertSame($executionDate, $input['date_start']);
        self::assertSame($executionDate, $input['date_end']);
        self::assertSame('2026-06-01', $input['evidence']['source_business_date']);
        self::assertSame($executionDate, $input['evidence']['execution_date']);
    }

    public function testPriceSuggestionExecutionIntentRejectsPastExecutionDate(): void
    {
        $service = $this->priceIntentService();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('计划执行日期不能早于今天');

        $service->buildPriceSuggestionExecutionIntentInput([
            'id' => 79,
            'status' => \app\model\PriceSuggestion::STATUS_APPROVED,
            'hotel_id' => 7,
            'room_type_id' => 3,
            'suggestion_date' => date('Y-m-d', strtotime('-2 days')),
            'current_price' => 280,
            'suggested_price' => 318,
            'min_price' => 220,
            'max_price' => 380,
            'reason' => 'competitor price higher',
            'factors' => ['high forecast occupancy'],
            'competitor_data' => ['avg_price' => 330],
        ], [
            'platform' => 'ctrip',
            'room_type_key' => 'RT-1001',
            'rate_plan_key' => 'BAR',
            'execution_date' => date('Y-m-d', strtotime('-1 day')),
        ]);
    }

    public function testPriceSuggestionExecutionIntentUsesManualApprovedPrice(): void
    {
        $service = $this->priceIntentService();

        $input = $service->buildPriceSuggestionExecutionIntentInput([
            'id' => 77,
            'status' => \app\model\PriceSuggestion::STATUS_APPROVED,
            'hotel_id' => 7,
            'room_type_id' => 3,
            'suggestion_date' => '2026-06-01',
            'current_price' => 280,
            'suggested_price' => 318,
            'min_price' => 220,
            'max_price' => 380,
            'reason' => 'competitor price higher',
            'factors' => [
                'manual_review' => [
                    'version' => 1,
                    'action' => 'approve_with_changes',
                    'original_suggested_price' => 318,
                    'approved_price' => 328,
                ],
            ],
            'competitor_data' => ['avg_price' => 330],
        ], [
            'platform' => 'ctrip',
        ]);

        self::assertSame(328.0, $input['target_value']['target_price']);
        self::assertSame(318.0, $input['evidence']['original_suggested_price']);
        self::assertSame(328.0, $input['evidence']['approved_price']);
        self::assertSame('approve_with_changes', $input['evidence']['manual_review']['action']);
        self::assertSame('price_suggestions.factors.manual_review_versions', $input['evidence']['manual_review_storage']);
        self::assertFalse($input['evidence']['auto_write_ota']);
    }

    public function testApprovedPriceSuggestionStillRejectsLegacyOrBlockedDecisionQuality(): void
    {
        foreach ([
            [
                'can_create_execution_intent' => true,
                'blocked_reason' => '',
                'decision_quality' => ['contract_version' => 'ai_recommendation_quality.v1', 'execution_ready' => true],
            ],
            [
                'can_create_execution_intent' => false,
                'blocked_reason' => 'fresh bound price evidence is missing',
                'decision_quality' => ['contract_version' => AiDecisionQualityService::CONTRACT_VERSION, 'execution_ready' => false],
            ],
        ] as $recommendation) {
            $service = $this->priceIntentServiceWithRecommendation($recommendation);
            try {
                $service->buildPriceSuggestionExecutionIntentInput([
                    'id' => 77,
                    'status' => \app\model\PriceSuggestion::STATUS_APPROVED,
                    'hotel_id' => 7,
                    'room_type_id' => 3,
                    'suggestion_date' => '2026-06-01',
                    'current_price' => 280,
                    'suggested_price' => 318,
                    'reason' => 'competitor price higher',
                    'factors' => [],
                ], [
                    'platform' => 'ctrip',
                    'room_type_key' => 'RT-1001',
                    'rate_plan_key' => 'BAR',
                ]);
                self::fail('Approved status alone must not bypass exact-v2 decision quality.');
            } catch (InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testForecastRecommendationBuildsOnlyAPendingManualChecklistIntent(): void
    {
        $service = new OperationManagementService();
        $payload = $service->buildExecutionIntentPayload([7], 7, [
            'source_module' => \app\service\TemporalInsightService::OPERATION_SOURCE_MODULE,
            'source_record_id' => 88,
            'hotel_id' => 7,
            'platform' => 'all_ota',
            'object_type' => 'operation_checklist',
            'action_type' => 'manual_forecast_review',
            'date_start' => '2026-08-01',
            'date_end' => '2026-08-02',
            'target_value' => [
                'title' => 'OTA收入 T+1 预测运营核查',
                'action_text' => '人工核查来源和可控运营因素',
                'target_metric' => 'ota_revenue',
                'steps' => ['人工核查', '记录证据', '次日复盘'],
                'acceptance_criteria' => ['人工审批后才生成任务', '禁止自动调价'],
                'automatic_price_write' => false,
            ],
            'evidence' => [
                'evidence_refs' => [[
                    'table' => 'temporal_forecast_snapshots',
                    'row_id' => 88,
                ]],
                'review_required' => true,
                'automatic_price_write' => false,
            ],
            'expected_metric' => 'ota_revenue',
        ], 3);

        self::assertSame('pending_approval', $payload['status']);
        self::assertSame('', $payload['blocked_reason']);
        self::assertSame('operation_checklist', $payload['object_type']);
        self::assertFalse($payload['target_value']['automatic_price_write']);
        self::assertArrayNotHasKey('target_price', $payload['target_value']);
    }

    public function testForecastNextDayActualBuildsNonCausalSourceVerifiedEvidence(): void
    {
        $service = new OperationManagementService();
        $method = new \ReflectionMethod($service, 'buildTemporalForecastEvidencePayload');
        $method->setAccessible(true);
        $payload = $method->invoke($service, [
            'id' => 701,
            'executed_at' => '2026-08-02 18:00:00',
        ], [
            'hotel_id' => 7,
            'platform' => 'all_ota',
            'object_type' => 'operation_checklist',
            'date_start' => '2026-08-01',
            'date_end' => '2026-08-02',
            'expected_metric' => 'ota_revenue',
        ], [
            'status' => 'ready',
            'forecast_point_id' => 88,
            'forecast_run_id' => 'run-88',
            'system_hotel_id' => 7,
            'metric_key' => 'ota_revenue',
            'target_date' => '2026-08-02',
            'predicted_value' => 1000,
            'lower_bound' => 850,
            'upper_bound' => 1150,
            'actual_value' => 1080,
            'within_range' => true,
            'absolute_error' => 80,
            'source_row_ids' => [17652, 43491],
            'readback_count' => 2,
            'readback_at' => '2026-08-03 08:10:00',
        ]);

        self::assertIsArray($payload);
        self::assertSame('source_verified_metric_readback', $payload['evidence_type']);
        self::assertSame(['ota_revenue' => 1000.0], $payload['before']);
        self::assertSame(['ota_revenue' => 1080.0], $payload['after']);
        self::assertSame('all_ota', $payload['platform_response']['platform']);
        self::assertSame('2026-08-01', $payload['platform_response']['date_start']);
        self::assertSame('2026-08-02', $payload['platform_response']['date_end']);
        self::assertSame(
            'forecast_target_actual_next_day_readback',
            $payload['platform_response']['measurement_policy']
        );
        self::assertFalse($payload['platform_response']['causality_claimed']);
        self::assertSame('observed_not_attributed', $payload['platform_response']['effect_evidence_status']);
        self::assertFalse($payload['platform_response']['automatic_price_write']);
        self::assertStringNotContainsString('target_price', json_encode($payload));
    }

    public function testForecastOperationExecutionRequiresAnExplicitNextDayReviewDate(): void
    {
        $service = new OperationManagementService();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('next-day review date');

        $service->buildExecutionTaskUpdate(
            ['id' => 9, 'status' => 'pending_execute'],
            [
                'id' => 4,
                'status' => 'approved',
                'source_module' => \app\service\TemporalInsightService::OPERATION_SOURCE_MODULE,
            ],
            [
                'status' => 'executed',
                'evidence' => [
                    'platform_response' => [
                        'mode' => 'manual_operation_execution',
                        'automatic_price_write' => false,
                        'completed_action' => '已人工检查活动展示与库存状态',
                    ],
                    'remark' => '人工动作已执行',
                ],
            ],
            3
        );
    }

    public function testForecastOperationExecutionRequiresTheCompletedManualAction(): void
    {
        $service = new OperationManagementService();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('completed manual action');

        $service->buildExecutionTaskUpdate(
            ['id' => 9, 'status' => 'pending_execute'],
            [
                'id' => 4,
                'status' => 'approved',
                'source_module' => \app\service\TemporalInsightService::OPERATION_SOURCE_MODULE,
            ],
            [
                'status' => 'executed',
                'evidence' => [
                    'platform_response' => [
                        'mode' => 'manual_operation_execution',
                        'automatic_price_write' => false,
                        'next_review_date' => (new \DateTimeImmutable('today'))->modify('+1 day')->format('Y-m-d'),
                    ],
                ],
            ],
            3
        );
    }

    public function testForecastOperationExecutionRejectsAutomaticPriceInstructions(): void
    {
        $service = new OperationManagementService();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('manual-only');

        $service->buildExecutionTaskUpdate(
            ['id' => 9, 'status' => 'pending_execute'],
            [
                'id' => 4,
                'status' => 'approved',
                'source_module' => \app\service\TemporalInsightService::OPERATION_SOURCE_MODULE,
            ],
            [
                'status' => 'executed',
                'evidence' => [
                    'platform_response' => [
                        'mode' => 'manual_operation_execution',
                        'completed_action' => 'manual listing and campaign review completed',
                        'next_review_date' => (new \DateTimeImmutable('today'))->modify('+1 day')->format('Y-m-d'),
                        'automatic_price_write' => true,
                        'target_price' => 288,
                    ],
                ],
            ],
            3
        );
    }

    public function testForecastOperationExecutionRejectsPriceInstructionsOutsidePlatformResponse(): void
    {
        $service = new OperationManagementService();
        $nextDay = (new \DateTimeImmutable('today'))->modify('+1 day')->format('Y-m-d');
        $base = [
            'status' => 'executed',
            'evidence' => [
                'platform_response' => [
                    'mode' => 'manual_operation_execution',
                    'completed_action' => 'manual listing and campaign review completed',
                    'next_review_date' => $nextDay,
                    'automatic_price_write' => false,
                ],
            ],
        ];
        $attempts = [
            array_replace_recursive($base, [
                'status' => 'executing',
                'target_value' => ['target_price' => 288],
            ]),
            array_replace_recursive($base, [
                'target_value' => ['target_price' => 288],
            ]),
            array_replace_recursive($base, [
                'evidence' => [
                    'platform_response' => [
                        'operator_execution_evidence' => ['approvedPrice' => 288],
                    ],
                ],
            ]),
            array_replace_recursive($base, [
                'evidence' => [
                    'platform_response' => [
                        'operator_execution_evidence' => ['autoWriteOta' => true],
                    ],
                ],
            ]),
        ];

        foreach ($attempts as $attempt) {
            try {
                $service->buildExecutionTaskUpdate(
                    ['id' => 9, 'status' => 'pending_execute'],
                    [
                        'id' => 4,
                        'status' => 'approved',
                        'source_module' => \app\service\TemporalInsightService::OPERATION_SOURCE_MODULE,
                    ],
                    $attempt,
                    3
                );
                self::fail('Forecast execution price instructions must be rejected regardless of nesting.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('manual-only', $exception->getMessage());
            }
        }
    }

    public function testForecastOperationExecutionPersistsEvidenceAndNextDayReviewBoundary(): void
    {
        $service = new OperationManagementService();
        $nextDay = (new \DateTimeImmutable('today'))->modify('+1 day')->format('Y-m-d');
        $result = $service->buildExecutionTaskUpdate(
            ['id' => 9, 'status' => 'pending_execute'],
            [
                'id' => 4,
                'status' => 'approved',
                'source_module' => \app\service\TemporalInsightService::OPERATION_SOURCE_MODULE,
            ],
            [
                'status' => 'executed',
                'evidence_type' => 'manual_operation_execution',
                'evidence' => [
                    'platform_response' => [
                        'mode' => 'manual_operation_execution',
                        'completed_action' => '已人工检查活动展示与库存状态',
                        'next_review_date' => $nextDay,
                        'automatic_price_write' => false,
                    ],
                    'remark' => '人工动作已执行，等待次日效果证据',
                ],
            ],
            3
        );

        self::assertSame('executed', $result['task']['status']);
        self::assertSame('manual_operation_execution', $result['evidence']['evidence_type']);
        self::assertSame($nextDay, $result['evidence']['platform_response']['next_review_date']);
        self::assertFalse($result['evidence']['platform_response']['automatic_price_write']);
    }

    public function testManualExecutionCanPersistAValidatedRevenueNodeRecord(): void
    {
        $service = new OperationManagementService();
        $nodeRecord = [
            'contract_version' => 'operation_revenue_node.v1',
            'recorded_at' => '2026-08-02 16:00:00',
            'operating_period' => 'weekend',
            'special_event' => '',
            'source_scope' => 'pms_ota_cross_check',
            'room_status_alignment' => 'operator_confirmed',
            'data_quality_status' => 'manual_confirmed',
            'metric_definition' => '16:00 node; fixed sellable-room denominator',
            'comparison_basis' => 'last four weekend 16:00 nodes with the same channel and room scope',
            'metric_snapshot' => 'same-day booking rate 12%; ADR 288',
            'progress_status' => 'normal',
            'judgment_basis' => 'same-scope booking pace remains inside the comparison band',
            'primary_risk' => 'late cancellation',
            'success_criteria' => 'booking pace remains inside the comparison band at 20:00',
            'stop_condition' => 'stop if PMS and OTA room status no longer match',
        ];

        $result = $service->buildExecutionTaskUpdate(
            ['id' => 31, 'status' => 'pending_execute'],
            ['status' => 'approved', 'source_module' => 'manual'],
            [
                'status' => 'executed',
                'evidence_type' => 'manual_operation_execution',
                'evidence' => [
                    'platform_response' => [
                        'mode' => 'manual_operation_execution',
                        'node_record' => $nodeRecord,
                    ],
                ],
            ],
            9
        );

        self::assertEquals($nodeRecord, $result['evidence']['platform_response']['node_record']);
    }

    public function testRevenueNodeRecordRejectsMissingComparisonBasis(): void
    {
        $service = new OperationManagementService();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('comparison_basis');

        $service->buildExecutionTaskUpdate(
            ['id' => 31, 'status' => 'pending_execute'],
            ['status' => 'approved', 'source_module' => 'manual'],
            [
                'status' => 'executed',
                'evidence' => [
                    'platform_response' => [
                        'node_record' => [
                            'contract_version' => 'operation_revenue_node.v1',
                            'recorded_at' => '2026-08-02 16:00:00',
                            'operating_period' => 'weekend',
                            'source_scope' => 'pms_ota_cross_check',
                            'room_status_alignment' => 'operator_confirmed',
                            'data_quality_status' => 'manual_confirmed',
                            'metric_definition' => 'fixed 16:00 scope',
                            'progress_status' => 'normal',
                            'judgment_basis' => 'same-scope comparison',
                            'success_criteria' => 'review at 20:00',
                            'stop_condition' => 'stop on scope mismatch',
                        ],
                    ],
                ],
            ],
            9
        );
    }

    public function testRevenueNodeCheckAllowsPreExecutionStagesWithoutRelaxingOtherEvidence(): void
    {
        $service = new OperationManagementService();
        $method = new \ReflectionMethod($service, 'executionEvidenceCanBeAddedAtStatus');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($service, 'revenue_node_check', 'pending_execute'));
        self::assertTrue($method->invoke($service, 'revenue_node_check', 'executing'));
        self::assertTrue($method->invoke($service, 'revenue_node_check', 'executed'));
        self::assertFalse($method->invoke($service, 'manual_operation_execution', 'pending_execute'));
        self::assertFalse($method->invoke($service, 'manual_finance', 'executing'));
        self::assertTrue($method->invoke($service, 'manual_operation_execution', 'executed'));
    }

    public function testRevenueNodeV2BindsHotelAndBusinessDateToExecutionIntent(): void
    {
        $service = new OperationManagementService();
        $record = $this->revenueNodeRecordV2();
        $result = $service->buildExecutionTaskUpdate(
            ['id' => 31, 'hotel_id' => 7, 'status' => 'pending_execute'],
            ['id' => 41, 'hotel_id' => 7, 'status' => 'approved', 'source_module' => 'manual', 'date_start' => '2026-08-03'],
            [
                'status' => 'executed',
                'evidence_type' => 'manual_operation_execution',
                'evidence' => ['platform_response' => ['node_record' => $record]],
            ],
            9
        );

        self::assertSame('operation_revenue_node.v2', $result['evidence']['platform_response']['node_record']['contract_version']);
        self::assertSame('7', $result['evidence']['platform_response']['node_record']['system_hotel_id']);
        self::assertSame('2026-08-03', $result['evidence']['platform_response']['node_record']['business_date']);
    }

    public function testRevenueNodeV2RejectsMismatchedHotelOrBusinessDate(): void
    {
        $service = new OperationManagementService();
        foreach ([
            array_replace($this->revenueNodeRecordV2(), ['system_hotel_id' => '8']),
            array_replace($this->revenueNodeRecordV2(), ['business_date' => '2026-08-04']),
        ] as $record) {
            try {
                $service->buildExecutionTaskUpdate(
                    ['id' => 31, 'hotel_id' => 7, 'status' => 'pending_execute'],
                    ['id' => 41, 'hotel_id' => 7, 'status' => 'approved', 'source_module' => 'manual', 'date_start' => '2026-08-03'],
                    [
                        'status' => 'executed',
                        'evidence' => ['platform_response' => ['node_record' => $record]],
                    ],
                    9
                );
                self::fail('Revenue node v2 identity mismatch must be rejected.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('does not match', $exception->getMessage());
            }
        }
    }

    public function testExecutionFlowSafelyReadsBackRevenueNodeSummary(): void
    {
        $service = new OperationManagementService();
        $item = $service->buildExecutionFlowItem(
            [
                'id' => 41,
                'hotel_id' => 7,
                'status' => 'approved',
                'source_module' => 'manual',
                'source_record_id' => 0,
                'platform' => 'ctrip',
            ],
            [[
                'id' => 51,
                'intent_id' => 41,
                'hotel_id' => 7,
                'status' => 'executed',
                'result_status' => 'observing',
            ]],
            [[
                'id' => 61,
                'task_id' => 51,
                'evidence_type' => 'manual_operation_execution',
                'platform_response_json' => json_encode([
                    'node_record' => [
                        'contract_version' => 'operation_revenue_node.v1',
                        'recorded_at' => '2026-08-02 16:00:00',
                        'operating_period' => 'weekend',
                        'source_scope' => 'pms_ota_cross_check',
                        'room_status_alignment' => 'operator_confirmed',
                        'data_quality_status' => 'manual_confirmed',
                        'progress_status' => 'normal',
                        'comparison_basis' => 'same weekend node',
                        'success_criteria' => 'review at 20:00',
                        'stop_condition' => 'stop on mismatch',
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ]]
        );

        self::assertSame('available', $item['evidence_summary']['node_record']['status']);
        self::assertSame('weekend', $item['evidence_summary']['node_record']['operating_period']);
        self::assertSame('2026-08-02 16:00:00', $item['evidence_summary']['node_record']['recorded_at']);
    }

    public function testExecutionFlowReadsBackLatestRevenueNodeUpdateForReopening(): void
    {
        $service = new OperationManagementService();
        $item = $service->buildExecutionFlowItem(
            ['id' => 41, 'hotel_id' => 7, 'tenant_id' => 3, 'status' => 'approved', 'source_module' => 'manual', 'platform' => 'ctrip', 'date_start' => '2026-08-03'],
            [['id' => 51, 'intent_id' => 41, 'hotel_id' => 7, 'tenant_id' => 3, 'status' => 'pending_execute']],
            [
                [
                    'id' => 62,
                    'task_id' => 51,
                    'tenant_id' => 3,
                    'created_by' => 9,
                    'evidence_type' => 'revenue_node_check',
                    'platform_response_json' => json_encode(['node_record' => array_replace(
                        $this->revenueNodeRecordV2(),
                        ['comparison_basis' => 'updated same-weekend pace window', 'progress_status' => 'too_slow']
                    )], JSON_UNESCAPED_UNICODE),
                ],
                [
                    'id' => 61,
                    'task_id' => 51,
                    'tenant_id' => 3,
                    'created_by' => 8,
                    'evidence_type' => 'manual_operation_execution',
                    'platform_response_json' => json_encode(['node_record' => [
                        'contract_version' => 'operation_revenue_node.v1',
                        'recorded_at' => '2026-08-02 16:00:00',
                        'operating_period' => 'weekend',
                        'source_scope' => 'ctrip',
                        'room_status_alignment' => 'unverified',
                        'data_quality_status' => 'unverified',
                        'progress_status' => 'insufficient_evidence',
                        'comparison_basis' => 'legacy basis',
                        'success_criteria' => 'legacy criterion',
                        'stop_condition' => 'legacy stop',
                    ]], JSON_UNESCAPED_UNICODE),
                ],
            ]
        );

        $node = $item['evidence_summary']['node_record'];
        self::assertSame('operation_revenue_node.v2', $node['contract_version']);
        self::assertSame('7', $node['system_hotel_id']);
        self::assertSame('2026-08-03', $node['business_date']);
        self::assertSame('updated same-weekend pace window', $node['comparison_basis']);
        self::assertSame('same-scope booking pace', $node['metric_definition']);
        self::assertSame('too_slow', $node['progress_status']);
        self::assertNull($node['metric_snapshot']);
        self::assertSame(2, $item['evidence_summary']['count']);
        self::assertSame([
            'tenant_id' => 3,
            'system_hotel_id' => 7,
            'task_id' => 51,
            'parent_intent_id' => 41,
            'business_date' => '2026-08-03',
            'platform' => 'ctrip',
            'source_scope' => 'pms_ota_cross_check',
            'operator_id' => 9,
        ], $node['identity']);
    }

    public function testExecutionFlowRejectsNewestRevenueNodeWithDriftedIdentity(): void
    {
        $service = new OperationManagementService();
        $item = $service->buildExecutionFlowItem(
            ['id' => 41, 'hotel_id' => 7, 'tenant_id' => 3, 'status' => 'approved', 'platform' => 'ctrip', 'date_start' => '2026-08-03'],
            [['id' => 51, 'intent_id' => 41, 'hotel_id' => 7, 'tenant_id' => 3, 'status' => 'pending_execute']],
            [[
                'id' => 62,
                'task_id' => 51,
                'tenant_id' => 3,
                'created_by' => 9,
                'evidence_type' => 'revenue_node_check',
                'platform_response_json' => json_encode(['node_record' => array_replace(
                    $this->revenueNodeRecordV2(),
                    ['business_date' => '2026-08-04']
                )], JSON_UNESCAPED_UNICODE),
            ]]
        );

        self::assertSame('identity_mismatch', $item['evidence_summary']['node_record']['status']);
    }

    public function testExecutionRequiresApprovedIntent(): void
    {
        $service = new OperationManagementService();
        self::assertTrue(method_exists($service, 'buildExecutionTaskUpdate'), 'Missing execution task update builder');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('approved');

        $service->buildExecutionTaskUpdate(
            ['id' => 9, 'status' => 'pending_execute'],
            ['id' => 4, 'status' => 'pending_approval'],
            ['status' => 'executed', 'evidence' => ['after' => ['price' => 188]]],
            3
        );
    }

    public function testExecutedTaskWithoutEvidenceIsBlocked(): void
    {
        $service = new OperationManagementService();
        self::assertTrue(method_exists($service, 'buildExecutionTaskUpdate'), 'Missing execution task update builder');

        $result = $service->buildExecutionTaskUpdate(
            ['id' => 9, 'status' => 'pending_execute'],
            ['id' => 4, 'status' => 'approved'],
            ['status' => 'executed'],
            3
        );

        self::assertSame('blocked', $result['task']['status']);
        self::assertStringContainsString('evidence', $result['task']['blocked_reason']);
        self::assertArrayNotHasKey('executed_at', $result['task']);
        self::assertNull($result['evidence']);
    }

    public function testExecutedTaskBuildsTaskUpdateAndEvidencePayload(): void
    {
        $service = new OperationManagementService();
        self::assertTrue(method_exists($service, 'buildExecutionTaskUpdate'), 'Missing execution task update builder');

        $result = $service->buildExecutionTaskUpdate(
            ['id' => 9, 'status' => 'pending_execute'],
            ['id' => 4, 'status' => 'approved'],
            [
                'status' => 'executed',
                'evidence_type' => 'manual_screenshot',
                'evidence' => [
                    'before' => ['price' => 208],
                    'after' => ['price' => 188],
                    'attachment_path' => '/runtime/evidence/price-9.png',
                    'platform_response' => ['mode' => 'manual'],
                    'remark' => 'manual OTA update completed',
                ],
            ],
            3
        );

        self::assertSame('executed', $result['task']['status']);
        self::assertSame(3, $result['task']['operator_id']);
        self::assertArrayHasKey('executed_at', $result['task']);
        self::assertSame('manual_screenshot', $result['evidence']['evidence_type']);
        self::assertSame(['price' => 208], $result['evidence']['before']);
        self::assertSame(['price' => 188], $result['evidence']['after']);
        self::assertSame(['mode' => 'manual'], $result['evidence']['platform_response']);
    }

    public function testExecutionFlowItemTracksRecommendationExecutionEvidenceReviewAndRoi(): void
    {
        $service = new OperationManagementService();
        self::assertTrue(method_exists($service, 'buildExecutionFlowItem'), 'Missing execution flow item builder');
        self::assertTrue(method_exists($service, 'buildExecutionFlowSummary'), 'Missing execution flow summary builder');

        $item = $service->buildExecutionFlowItem([
            'id' => 11,
            'source_module' => 'strategy_simulation',
            'source_record_id' => 22,
            'hotel_id' => 7,
            'platform' => 'meituan',
            'object_type' => 'campaign',
            'action_type' => 'promotion',
            'date_start' => '2026-05-27',
            'date_end' => '2026-05-29',
            'current_value_json' => json_encode(['avg_revenue' => 1000], JSON_UNESCAPED_UNICODE),
            'target_value_json' => json_encode([
                'campaign_type' => 'discount',
                'budget' => 200,
                'target_metric' => 'revenue',
                'workflow_schedule' => [
                    'assignee_id' => 9,
                    'due_at' => '2026-05-27 18:00:00',
                    'review_at' => '2026-05-28 10:00:00',
                    'source_policy' => 'human_assigned_schedule_requires_manual_approval_and_readback_review',
                ],
            ], JSON_UNESCAPED_UNICODE),
            'evidence_json' => json_encode(['recommendation' => 'boost conversion'], JSON_UNESCAPED_UNICODE),
            'expected_metric' => 'revenue',
            'expected_delta' => 15,
            'risk_level' => 'medium',
            'status' => 'approved',
            'blocked_reason' => '',
            'created_at' => '2026-05-26 10:00:00',
            'approved_at' => '2026-05-26 10:30:00',
        ], [[
            'id' => 31,
            'intent_id' => 11,
            'hotel_id' => 7,
            'execution_mode' => 'manual',
            'status' => 'executed',
            'result_status' => 'success',
            'result_summary' => 'revenue lifted after promotion',
            'executed_at' => '2026-05-27 11:00:00',
            'current_value_json' => json_encode(['avg_revenue' => 1000], JSON_UNESCAPED_UNICODE),
            'target_value_json' => json_encode(['budget' => 200], JSON_UNESCAPED_UNICODE),
        ]], [
            [
                'id' => 40,
                'task_id' => 31,
                'evidence_type' => 'manual_operation_execution',
                'before_json' => '{}',
                'after_json' => '{}',
                'platform_response_json' => json_encode([
                    'mode' => 'manual_operation_execution',
                    'completed_action' => 'Promotion was manually applied by the assigned operator.',
                ], JSON_UNESCAPED_UNICODE),
                'remark' => 'operator execution receipt',
                'created_by' => 9,
                'created_at' => '2026-05-27 11:05:00',
            ],
            [
                'id' => 41,
                'task_id' => 31,
                'evidence_type' => 'source_verified_metric_readback',
                'before_json' => json_encode(['revenue' => 1000], JSON_UNESCAPED_UNICODE),
                'after_json' => json_encode(['revenue' => 1300, 'cost' => 200], JSON_UNESCAPED_UNICODE),
                'platform_response_json' => $this->sourceVerifiedPlatformResponse(
                    7,
                    'meituan',
                    'campaign',
                    '2026-05-27',
                    '2026-05-29',
                    'revenue',
                    'operation_metrics#41'
                ),
                'remark' => 'updated in OTA backend',
                'created_by' => 0,
                'created_at' => '2026-05-27 11:10:00',
            ],
        ]);

        self::assertSame('reviewed', $item['stage']);
        self::assertSame('strategy_simulation#22', $item['recommendation']['source']);
        self::assertSame('approved', $item['approval']['status']);
        self::assertSame('executed', $item['execution']['status']);
        self::assertSame('scheduled', $item['assignment']['status']);
        self::assertSame(9, $item['assignment']['assignee_id']);
        self::assertSame('2026-05-27 18:00:00', $item['assignment']['due_at']);
        self::assertSame('2026-05-28 10:00:00', $item['assignment']['review_at']);
        self::assertSame(2, $item['evidence']['count']);
        self::assertSame('success', $item['review']['status']);
        self::assertSame(2, $item['evidence_summary']['count']);
        self::assertSame(
            ['source_verified_metric_readback', 'manual_operation_execution'],
            $item['evidence_summary']['types']
        );
        self::assertSame('candidate', $item['sop_candidate']['status']);
        self::assertSame('pending_approval', $item['sop_candidate']['approval_status']);
        self::assertSame(['operation_metrics#41'], $item['sop_candidate']['source']['metric_readback_refs']);
        self::assertFalse($item['sop_candidate']['review']['causality_claimed']);
        self::assertFalse($item['sop_candidate']['boundaries']['automatic_publish_enabled']);
        self::assertFalse($item['sop_candidate']['boundaries']['cross_hotel_replication_allowed']);
        self::assertSame('ready', $item['roi']['status']);
        self::assertSame(50.0, $item['roi']['value']);
        self::assertSame(300.0, $item['roi']['incremental_revenue']);

        $summary = $service->buildExecutionFlowSummary([$item]);

        self::assertSame(1, $summary['total']);
        self::assertSame(1, $summary['stage_counts']['reviewed']);
        self::assertSame(1, $summary['evidence_ready']);
        self::assertSame(1, $summary['roi_ready']);
        self::assertSame(50.0, $summary['avg_roi']);
    }

    public function testExecutionFlowUsesNewestTaskAttemptInsteadOfOlderTerminalStatus(): void
    {
        $item = (new OperationManagementService())->buildExecutionFlowItem([
            'id' => 12,
            'source_module' => 'ota_diagnosis',
            'source_record_id' => 23,
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'object_type' => 'data_collection',
            'action_type' => 'complete_public_page_evidence',
            'date_start' => '2026-07-18',
            'date_end' => '2026-07-18',
            'current_value_json' => '{}',
            'target_value_json' => '{}',
            'evidence_json' => '{}',
            'expected_metric' => 'public_page_verified_field_count',
            'expected_delta' => 1,
            'risk_level' => 'medium',
            'status' => 'approved',
            'blocked_reason' => '',
        ], [
            [
                'id' => 31,
                'intent_id' => 12,
                'hotel_id' => 7,
                'status' => 'executed',
                'result_status' => 'success',
                'current_value_json' => '{}',
                'target_value_json' => '{}',
            ],
            [
                'id' => 32,
                'intent_id' => 12,
                'hotel_id' => 7,
                'status' => 'pending_execute',
                'result_status' => 'observing',
                'current_value_json' => '{}',
                'target_value_json' => '{}',
            ],
        ], [[
            'id' => 41,
            'task_id' => 31,
            'evidence_type' => 'manual',
            'before_json' => '{}',
            'after_json' => '{}',
            'platform_response_json' => '{}',
        ]]);

        self::assertSame(32, $item['execution']['task_id']);
        self::assertSame('pending_execute', $item['execution']['status']);
        self::assertSame(0, $item['evidence']['count']);
    }

    public function testSourceVerifiedWorseningMetricCannotSurfaceAsSuccessfulReview(): void
    {
        $service = new OperationManagementService();
        $item = $service->buildExecutionFlowItem([
            'id' => 111,
            'source_module' => 'ota_diagnosis_saved',
            'source_record_id' => 222,
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'object_type' => 'campaign',
            'action_type' => 'booking_conversion_optimization',
            'date_start' => '2026-07-18',
            'date_end' => '2026-07-18',
            'target_value_json' => json_encode(['target_metric' => 'orders'], JSON_UNESCAPED_UNICODE),
            'evidence_json' => json_encode(['expected_delta_status' => 'quantified'], JSON_UNESCAPED_UNICODE),
            'expected_metric' => 'orders',
            'expected_delta' => 10,
            'status' => 'approved',
        ], [[
            'id' => 311,
            'intent_id' => 111,
            'hotel_id' => 7,
            'status' => 'executed',
            'result_status' => 'success',
            'executed_at' => '2026-07-18 12:00:00',
        ]], [[
            'id' => 411,
            'task_id' => 311,
            'evidence_type' => 'source_verified_metric_readback',
            'before_json' => json_encode(['orders' => 100], JSON_UNESCAPED_UNICODE),
            'after_json' => json_encode(['orders' => 90], JSON_UNESCAPED_UNICODE),
            'platform_response_json' => $this->sourceVerifiedPlatformResponse(
                7,
                'ctrip',
                'campaign',
                '2026-07-18',
                '2026-07-18',
                'orders',
                'online_daily_data#worsening-orders'
            ),
            'created_by' => 0,
            'created_at' => '2026-07-19 13:00:00',
        ]]);

        self::assertTrue($item['evidence_truth']['source_verified']);
        self::assertSame('adverse', $item['outcome_truth']['status']);
        self::assertSame('metric_worsened', $item['outcome_truth']['failure_reason']);
        self::assertSame('unverified', $item['review']['status']);
        self::assertSame('success', $item['review']['reported_status']);
        self::assertSame('partial', $item['truth_context']['status']);
        self::assertSame('review', $item['stage']);
        self::assertSame('partial', $item['roi']['status']);
        self::assertSame('metric_worsened', $item['roi']['failure_reason']);
    }

    public function testNewerReadbackEvidenceDoesNotReplaceFinancialEvidenceForRoi(): void
    {
        $service = new OperationManagementService();

        $item = $service->buildExecutionFlowItem([
            'id' => 12,
            'source_module' => 'ai_daily_report',
            'source_record_id' => 23,
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'object_type' => 'campaign',
            'action_type' => 'promotion',
            'date_start' => '2026-07-16',
            'date_end' => '2026-07-16',
            'target_value_json' => json_encode(['budget' => 200], JSON_UNESCAPED_UNICODE),
            'expected_metric' => 'revenue',
            'expected_delta' => 10,
            'status' => 'approved',
        ], [[
            'id' => 32,
            'intent_id' => 12,
            'hotel_id' => 7,
            'execution_mode' => 'manual',
            'status' => 'executed',
            'result_status' => 'success',
        ]], [[
            'id' => 41,
            'task_id' => 32,
            'evidence_type' => 'source_verified_metric_readback',
            'before_json' => json_encode(['revenue' => 1000], JSON_UNESCAPED_UNICODE),
            'after_json' => json_encode(['revenue' => 1300, 'cost' => 200], JSON_UNESCAPED_UNICODE),
            'platform_response_json' => $this->sourceVerifiedPlatformResponse(
                7,
                'ctrip',
                'campaign',
                '2026-07-16',
                '2026-07-16',
                'revenue',
                'operation_metrics#financial-41'
            ),
            'created_by' => 0,
        ], [
            'id' => 42,
            'task_id' => 32,
            'evidence_type' => 'manual_platform_readback',
            'before_json' => json_encode([], JSON_UNESCAPED_UNICODE),
            'after_json' => json_encode([], JSON_UNESCAPED_UNICODE),
            'platform_response_json' => json_encode([
                'readback_verified' => true,
                'readback_source' => 'ctrip_ebooking_manual_check',
                'readback_at' => '2026-07-17 09:30:00',
            ], JSON_UNESCAPED_UNICODE),
        ]]);

        self::assertSame(2, $item['evidence']['count']);
        self::assertSame('manual_platform_readback', $item['evidence']['latest']['evidence_type']);
        self::assertSame('ready', $item['roi']['status']);
        self::assertSame(50.0, $item['roi']['value']);
        self::assertSame(300.0, $item['roi']['incremental_revenue']);
    }

    public function testExecutionFlowSummaryExposesMoneyAndConversionRates(): void
    {
        $service = new OperationManagementService();

        $summary = $service->buildExecutionFlowSummary([
            [
                'stage' => 'reviewed',
                'approval' => ['status' => 'approved'],
                'execution' => ['status' => 'executed'],
                'evidence' => ['count' => 1],
                'evidence_truth' => ['source_verified' => true, 'operator_attested' => false],
                'roi' => [
                    'status' => 'ready',
                    'value' => 50.0,
                    'incremental_revenue' => 300.0,
                    'cost' => 200.0,
                    'profit' => 100.0,
                ],
            ],
            [
                'stage' => 'approval',
                'approval' => ['status' => 'pending_approval'],
                'execution' => ['status' => 'pending_create'],
                'evidence' => ['count' => 0],
                'roi' => ['status' => 'data_gap'],
            ],
        ]);

        self::assertSame(2, $summary['total']);
        self::assertSame(1, $summary['approved']);
        self::assertSame(1, $summary['executed']);
        self::assertSame(50.0, $summary['approval_rate']);
        self::assertSame(50.0, $summary['execution_rate']);
        self::assertSame(50.0, $summary['evidence_rate']);
        self::assertSame(50.0, $summary['roi_ready_rate']);
        self::assertSame(300.0, $summary['total_incremental_revenue']);
        self::assertSame(200.0, $summary['total_cost']);
        self::assertSame(100.0, $summary['total_profit']);
        self::assertSame(100.0, $summary['profitable_rate']);
        self::assertSame(1, $summary['roi_percent_ready']);
        self::assertSame(0, $summary['revenue_lift_ready']);
        self::assertSame(50.0, $summary['avg_roi']);
        self::assertNull($summary['avg_revenue_lift']);
    }

    public function testPriceExecutionRoiCanUseIncrementalRevenueWithoutCost(): void
    {
        $service = new OperationManagementService();

        $item = $service->buildExecutionFlowItem([
            'id' => 13,
            'source_module' => 'price_suggestion',
            'source_record_id' => 77,
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'object_type' => 'price',
            'action_type' => 'price_adjust',
            'date_start' => '2026-06-01',
            'date_end' => '2026-06-01',
            'current_value_json' => json_encode(['current_price' => 280], JSON_UNESCAPED_UNICODE),
            'target_value_json' => json_encode(['target_price' => 318], JSON_UNESCAPED_UNICODE),
            'evidence_json' => json_encode(['reason' => 'competitor price higher'], JSON_UNESCAPED_UNICODE),
            'expected_metric' => 'revenue',
            'expected_delta' => 200,
            'risk_level' => 'medium',
            'status' => 'approved',
            'approved_by' => 3,
            'approved_at' => '2026-06-01 10:00:00',
            'review_remark' => '',
            'blocked_reason' => '',
            'created_at' => '2026-06-01 09:00:00',
        ], [[
            'id' => 31,
            'intent_id' => 13,
            'hotel_id' => 7,
            'execution_mode' => 'manual',
            'status' => 'executed',
            'operator_id' => 3,
            'result_status' => 'success',
            'result_summary' => 'ADR lifted after price adjustment',
            'executed_at' => '2026-06-01 11:00:00',
            'current_value_json' => json_encode(['revenue' => 1000], JSON_UNESCAPED_UNICODE),
            'target_value_json' => json_encode(['target_price' => 318], JSON_UNESCAPED_UNICODE),
        ]], [[
            'id' => 41,
            'task_id' => 31,
            'evidence_type' => 'source_verified_metric_readback',
            'before_json' => json_encode(['revenue' => 1000], JSON_UNESCAPED_UNICODE),
            'after_json' => json_encode(['revenue' => 1300], JSON_UNESCAPED_UNICODE),
            'platform_response_json' => $this->sourceVerifiedPlatformResponse(
                7,
                'ctrip',
                'price',
                '2026-06-01',
                '2026-06-01',
                'revenue',
                'operation_metrics#price-41'
            ),
            'remark' => 'price updated manually',
            'created_by' => 0,
            'created_at' => '2026-06-08 11:10:00',
        ]]);

        self::assertSame('reviewed', $item['stage']);
        self::assertSame('ready', $item['roi']['status']);
        self::assertSame('amount', $item['roi']['unit']);
        self::assertSame(300.0, $item['roi']['value']);
        self::assertNull($item['roi']['cost']);
        self::assertNull($item['roi']['profit']);
        self::assertSame('after_revenue - before_revenue', $item['roi']['formula']);

        $summary = $service->buildExecutionFlowSummary([$item]);
        self::assertSame(1, $summary['roi_ready']);
        self::assertSame(0, $summary['roi_percent_ready']);
        self::assertSame(1, $summary['revenue_lift_ready']);
        self::assertNull($summary['avg_roi']);
        self::assertSame(300.0, $summary['avg_revenue_lift']);
        self::assertNull($summary['total_cost']);
        self::assertNull($summary['total_profit']);
        self::assertSame('profit_unverified', $summary['money_status']);
    }

    public function testPriceExecutionEvidenceWithoutRevenueKeepsRoiDataGap(): void
    {
        $service = new OperationManagementService();

        $item = $service->buildExecutionFlowItem([
            'id' => 14,
            'source_module' => 'price_suggestion',
            'source_record_id' => 78,
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'object_type' => 'price',
            'action_type' => 'price_adjust',
            'date_start' => '2026-06-02',
            'date_end' => '2026-06-02',
            'current_value_json' => json_encode(['current_price' => 280], JSON_UNESCAPED_UNICODE),
            'target_value_json' => json_encode(['target_price' => 318, 'room_type_key' => 'RT-1'], JSON_UNESCAPED_UNICODE),
            'evidence_json' => json_encode(['reason' => 'competitor price higher'], JSON_UNESCAPED_UNICODE),
            'expected_metric' => 'revenue',
            'expected_delta' => 200,
            'risk_level' => 'medium',
            'status' => 'approved',
            'approved_by' => 3,
            'approved_at' => '2026-06-02 10:00:00',
            'review_remark' => '',
            'blocked_reason' => '',
            'created_at' => '2026-06-02 09:00:00',
        ], [[
            'id' => 33,
            'intent_id' => 14,
            'hotel_id' => 7,
            'execution_mode' => 'manual',
            'status' => 'executed',
            'operator_id' => 3,
            'result_status' => 'observing',
            'result_summary' => '',
            'executed_at' => '2026-06-02 11:00:00',
            'current_value_json' => json_encode(['executed_before_price' => 280], JSON_UNESCAPED_UNICODE),
            'target_value_json' => json_encode(['executed_after_price' => 318], JSON_UNESCAPED_UNICODE),
        ]], [[
            'id' => 43,
            'task_id' => 33,
            'evidence_type' => 'manual_price_execution',
            'before_json' => json_encode(['price' => 280], JSON_UNESCAPED_UNICODE),
            'after_json' => json_encode(['price' => 318], JSON_UNESCAPED_UNICODE),
            'platform_response_json' => json_encode([
                'mode' => 'manual',
                'scope' => 'ota_channel_manual_execution',
                'platform' => 'ctrip',
                'room_type' => 'RT-1',
                'receipt_path' => '/runtime/evidence/price-14.png',
                'evidence_boundary' => 'local_manual_evidence_no_ota_write',
            ], JSON_UNESCAPED_UNICODE),
            'attachment_path' => '/runtime/evidence/price-14.png',
            'remark' => 'price updated manually; revenue evidence pending',
            'created_by' => 3,
            'created_at' => '2026-06-02 11:10:00',
        ]]);

        self::assertSame('evidence', $item['stage']);
        self::assertSame(1, $item['evidence']['count']);
        self::assertSame('manual_price_execution', $item['evidence']['latest']['evidence_type']);
        self::assertSame('partial', $item['roi']['status']);
        self::assertSame('source-verified execution evidence missing', $item['roi']['message']);
        self::assertSame('operator_attested_only', $item['roi']['failure_reason']);
        self::assertNull($item['roi']['value']);
        self::assertSame('record_evidence', $item['next_action']['key']);
    }

    public function testSupplementedRoiEvidenceUpgradesPriceExecutionToReadyInput(): void
    {
        $service = new OperationManagementService();

        $item = $service->buildExecutionFlowItem([
            'id' => 15,
            'source_module' => 'price_suggestion',
            'source_record_id' => 79,
            'hotel_id' => 7,
            'platform' => 'meituan',
            'object_type' => 'price',
            'action_type' => 'price_adjust',
            'date_start' => '2026-06-03',
            'date_end' => '2026-06-03',
            'current_value_json' => json_encode(['current_price' => 260], JSON_UNESCAPED_UNICODE),
            'target_value_json' => json_encode(['target_price' => 288, 'room_type_key' => 'RT-2'], JSON_UNESCAPED_UNICODE),
            'evidence_json' => json_encode(['reason' => 'competitor median higher'], JSON_UNESCAPED_UNICODE),
            'expected_metric' => 'revenue',
            'expected_delta' => 150,
            'risk_level' => 'medium',
            'status' => 'approved',
            'approved_by' => 3,
            'approved_at' => '2026-06-03 10:00:00',
            'review_remark' => '',
            'blocked_reason' => '',
            'created_at' => '2026-06-03 09:00:00',
        ], [[
            'id' => 34,
            'intent_id' => 15,
            'hotel_id' => 7,
            'execution_mode' => 'manual',
            'status' => 'executed',
            'operator_id' => 3,
            'result_status' => 'success',
            'result_summary' => 'source-verified revenue readback completed',
            'executed_at' => '2026-06-03 11:00:00',
            'current_value_json' => json_encode(['executed_before_price' => 260], JSON_UNESCAPED_UNICODE),
            'target_value_json' => json_encode(['executed_after_price' => 288], JSON_UNESCAPED_UNICODE),
        ]], [[
            'id' => 43,
            'task_id' => 34,
            'evidence_type' => 'manual_price_execution',
            'before_json' => json_encode(['price' => 260], JSON_UNESCAPED_UNICODE),
            'after_json' => json_encode(['price' => 288], JSON_UNESCAPED_UNICODE),
            'platform_response_json' => json_encode(['evidence_boundary' => 'local_manual_evidence_no_ota_write'], JSON_UNESCAPED_UNICODE),
            'remark' => 'price updated manually',
            'created_at' => '2026-06-03 11:10:00',
        ], [
            'id' => 44,
            'task_id' => 34,
            'evidence_type' => 'source_verified_metric_readback',
            'before_json' => json_encode(['revenue' => 1100], JSON_UNESCAPED_UNICODE),
            'after_json' => json_encode(['revenue' => 1285], JSON_UNESCAPED_UNICODE),
            'platform_response_json' => $this->sourceVerifiedPlatformResponse(
                7,
                'meituan',
                'price',
                '2026-06-03',
                '2026-06-03',
                'revenue',
                'operation_metrics#roi-44'
            ),
            'attachment_path' => '/runtime/evidence/roi-15.png',
            'remark' => 'manual next-day revenue evidence',
            'created_by' => 0,
            'created_at' => '2026-06-04 09:00:00',
        ]]);

        self::assertSame('reviewed', $item['stage']);
        self::assertSame(2, $item['evidence']['count']);
        self::assertSame('source_verified_metric_readback', $item['evidence']['latest']['evidence_type']);
        self::assertSame('ready', $item['roi']['status']);
        self::assertSame('amount', $item['roi']['unit']);
        self::assertSame(185.0, $item['roi']['value']);
        self::assertSame('after_revenue - before_revenue', $item['roi']['formula']);
    }

    public function testExecutionSummaryDoesNotMixPercentRoiAndRevenueLift(): void
    {
        $service = new OperationManagementService();

        $summary = $service->buildExecutionFlowSummary([
            [
                'stage' => 'reviewed',
                'approval' => ['status' => 'approved'],
                'execution' => ['status' => 'executed'],
                'evidence' => ['count' => 1],
                'evidence_truth' => ['source_verified' => true, 'operator_attested' => false],
                'roi' => [
                    'status' => 'ready',
                    'unit' => '%',
                    'value' => 50.0,
                    'incremental_revenue' => 300.0,
                    'cost' => 200.0,
                    'profit' => 100.0,
                ],
            ],
            [
                'stage' => 'reviewed',
                'approval' => ['status' => 'approved'],
                'execution' => ['status' => 'executed'],
                'evidence' => ['count' => 1],
                'evidence_truth' => ['source_verified' => true, 'operator_attested' => false],
                'roi' => [
                    'status' => 'ready',
                    'unit' => 'amount',
                    'value' => 300.0,
                    'incremental_revenue' => 300.0,
                    'cost' => 0.0,
                    'profit' => 300.0,
                ],
            ],
        ]);

        self::assertSame(2, $summary['roi_ready']);
        self::assertSame(1, $summary['roi_percent_ready']);
        self::assertSame(1, $summary['revenue_lift_ready']);
        self::assertSame(50.0, $summary['avg_roi']);
        self::assertSame(300.0, $summary['avg_revenue_lift']);
        self::assertSame(600.0, $summary['total_incremental_revenue']);
        self::assertSame(400.0, $summary['total_profit']);
    }

    public function testExecutionFlowItemProvidesNextAction(): void
    {
        $service = new OperationManagementService();

        $approvalItem = $service->buildExecutionFlowItem([
            'id' => 12,
            'source_module' => 'manual',
            'source_record_id' => 0,
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'object_type' => 'price',
            'action_type' => 'price_adjust',
            'date_start' => '2026-05-27',
            'date_end' => '2026-05-27',
            'current_value_json' => json_encode(['current_price' => 260], JSON_UNESCAPED_UNICODE),
            'target_value_json' => json_encode(['target_price' => 288, 'room_type_key' => 'RT-1', 'rate_plan_key' => 'BAR'], JSON_UNESCAPED_UNICODE),
            'evidence_json' => json_encode(['reason' => 'price opportunity'], JSON_UNESCAPED_UNICODE),
            'expected_metric' => 'revenue',
            'expected_delta' => 8,
            'risk_level' => 'medium',
            'status' => 'pending_approval',
            'blocked_reason' => '',
        ]);

        self::assertSame('approval', $approvalItem['stage']);
        self::assertSame('approve_intent', $approvalItem['next_action']['key']);
        self::assertSame('high', $approvalItem['next_action']['priority']);

        $evidenceItem = $service->buildExecutionFlowItem([
            'id' => 13,
            'source_module' => 'strategy_simulation',
            'source_record_id' => 23,
            'hotel_id' => 7,
            'platform' => 'meituan',
            'object_type' => 'campaign',
            'action_type' => 'promotion',
            'date_start' => '2026-05-27',
            'date_end' => '2026-05-29',
            'current_value_json' => json_encode(['avg_revenue' => 1000], JSON_UNESCAPED_UNICODE),
            'target_value_json' => json_encode(['budget' => 200, 'target_metric' => 'revenue'], JSON_UNESCAPED_UNICODE),
            'evidence_json' => json_encode(['reason' => 'conversion low'], JSON_UNESCAPED_UNICODE),
            'expected_metric' => 'revenue',
            'expected_delta' => 12,
            'risk_level' => 'medium',
            'status' => 'approved',
            'blocked_reason' => '',
        ], [[
            'id' => 32,
            'intent_id' => 13,
            'hotel_id' => 7,
            'execution_mode' => 'manual',
            'status' => 'executed',
            'result_status' => 'observing',
            'executed_at' => '2026-05-27 12:00:00',
            'current_value_json' => json_encode(['avg_revenue' => 1000], JSON_UNESCAPED_UNICODE),
            'target_value_json' => json_encode(['budget' => 200], JSON_UNESCAPED_UNICODE),
        ]]);

        self::assertSame('evidence', $evidenceItem['stage']);
        self::assertSame('record_evidence', $evidenceItem['next_action']['key']);
    }

    public function testExecutionFlowSummaryExposesBottleneckAndMoneyStatus(): void
    {
        $service = new OperationManagementService();

        $summary = $service->buildExecutionFlowSummary([
            ['stage' => 'approval', 'approval' => ['status' => 'pending_approval'], 'execution' => ['status' => 'pending_create'], 'evidence' => ['count' => 0], 'roi' => ['status' => 'data_gap']],
            ['stage' => 'approval', 'approval' => ['status' => 'pending_approval'], 'execution' => ['status' => 'pending_create'], 'evidence' => ['count' => 0], 'roi' => ['status' => 'data_gap']],
            ['stage' => 'reviewed', 'approval' => ['status' => 'approved'], 'execution' => ['status' => 'executed'], 'evidence' => ['count' => 1], 'evidence_truth' => ['source_verified' => true], 'roi' => ['status' => 'ready', 'value' => 40, 'incremental_revenue' => 280, 'cost' => 200, 'profit' => 80]],
        ]);

        self::assertSame('approval', $summary['bottleneck']['stage']);
        self::assertSame(2, $summary['bottleneck']['count']);
        self::assertSame('profit_positive', $summary['money_status']);
    }

    public function testManualEvidenceRemainsOperatorAttestedAndCannotProduceVerifiedOutcomeOrRoi(): void
    {
        $service = new OperationManagementService();
        $item = $service->buildExecutionFlowItem([
            'id' => 20,
            'source_module' => 'price_suggestion',
            'source_record_id' => 90,
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'object_type' => 'price',
            'action_type' => 'price_adjust',
            'date_start' => '2026-07-18',
            'date_end' => '2026-07-18',
            'expected_metric' => 'revenue',
            'status' => 'approved',
        ], [[
            'id' => 50,
            'intent_id' => 20,
            'hotel_id' => 7,
            'status' => 'executed',
            'result_status' => 'success',
            'executed_at' => '2026-07-18 12:00:00',
        ]], [[
            'id' => 60,
            'task_id' => 50,
            'evidence_type' => 'manual_screenshot',
            'before_json' => json_encode(['revenue' => 1000], JSON_UNESCAPED_UNICODE),
            'after_json' => json_encode(['revenue' => 1200], JSON_UNESCAPED_UNICODE),
            'attachment_path' => '/runtime/evidence/manual.png',
            'platform_response_json' => json_encode(['mode' => 'manual'], JSON_UNESCAPED_UNICODE),
            'remark' => 'operator says the price was changed',
            'created_by' => 9,
            'created_at' => '2026-07-18 12:10:00',
        ]]);

        self::assertSame(1, $item['evidence']['count']);
        self::assertTrue($item['evidence_truth']['operator_attested']);
        self::assertFalse($item['evidence_truth']['source_verified']);
        self::assertSame('partial', $item['evidence_truth']['status']);
        self::assertSame('operator_attested_only', $item['evidence_truth']['failure_reason']);
        self::assertSame('partial', $item['truth_context']['status']);
        self::assertSame('unverified', $item['review']['status']);
        self::assertSame('success', $item['review']['reported_status']);
        self::assertSame('partial', $item['roi']['status']);
        self::assertNull($item['roi']['value']);
        self::assertNull($item['roi']['before_revenue']);
        self::assertNull($item['roi']['after_revenue']);
        self::assertSame('evidence', $item['stage']);

        $summary = $service->buildExecutionFlowSummary([$item]);
        self::assertSame(0, $summary['evidence_ready']);
        self::assertSame(1, $summary['operator_attested']);
        self::assertSame(0, $summary['roi_ready']);
        self::assertNull($summary['total_incremental_revenue']);
        self::assertNull($summary['total_cost']);
        self::assertNull($summary['total_profit']);
    }

    public function testAlignedSystemReadbackEvidenceCanVerifyExplicitZeroRoi(): void
    {
        $service = new OperationManagementService();
        $item = $service->buildExecutionFlowItem([
            'id' => 21,
            'source_module' => 'price_suggestion',
            'source_record_id' => 91,
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'object_type' => 'price',
            'action_type' => 'price_adjust',
            'date_start' => '2026-07-18',
            'date_end' => '2026-07-18',
            'target_value_json' => json_encode(['expected_delta_status' => 'quantified'], JSON_UNESCAPED_UNICODE),
            'expected_metric' => 'revenue',
            'expected_delta' => 0,
            'status' => 'approved',
        ], [[
            'id' => 51,
            'intent_id' => 21,
            'hotel_id' => 7,
            'status' => 'executed',
            'result_status' => 'success',
            'executed_at' => '2026-07-18 12:00:00',
        ]], [[
            'id' => 61,
            'task_id' => 51,
            'evidence_type' => 'source_verified_metric_readback',
            'before_json' => json_encode(['revenue' => 0], JSON_UNESCAPED_UNICODE),
            'after_json' => json_encode(['revenue' => 0, 'cost' => 0], JSON_UNESCAPED_UNICODE),
            'platform_response_json' => json_encode([
                'verification_authority' => 'system_readback',
                'source' => 'online_daily_data',
                'source_ref' => 'online_daily_data#zero-roi',
                'system_hotel_id' => 7,
                'platform' => 'ctrip',
                'object_type' => 'price',
                'date_start' => '2026-07-18',
                'date_end' => '2026-07-18',
                'metric_key' => 'revenue',
                'database_written' => true,
                'readback_verified' => true,
                'readback_count' => 1,
                'readback_at' => '2026-07-18 13:00:00',
                'validation_status' => 'verified',
            ], JSON_UNESCAPED_UNICODE),
            'created_by' => 0,
            'created_at' => '2026-07-18 13:00:00',
        ]]);

        self::assertTrue($item['evidence_truth']['source_verified']);
        self::assertSame('verified', $item['evidence_truth']['status']);
        self::assertSame('verified', $item['truth_context']['status']);
        self::assertSame('success', $item['review']['status']);
        self::assertSame('ready', $item['roi']['status']);
        self::assertSame(0.0, $item['roi']['value']);
        self::assertSame(0.0, $item['roi']['before_revenue']);
        self::assertSame(0.0, $item['roi']['after_revenue']);
        self::assertSame(0.0, $item['roi']['incremental_revenue']);
        self::assertSame(0.0, $item['roi']['cost']);
        self::assertSame(0.0, $item['roi']['profit']);
        self::assertSame('reviewed', $item['stage']);
    }

    public function testVerifiedEvidenceDoesNotPromoteObservingOrFailedOutcomeToRoiReady(): void
    {
        $service = new OperationManagementService();
        foreach ([
            'observing' => ['review_status_observing', 'review'],
            'failed' => ['review_status_failed', 'failed'],
        ] as $reviewStatus => [$failureReason, $expectedStage]) {
            $item = $service->buildExecutionFlowItem([
                'id' => 30,
                'source_module' => 'strategy_simulation',
                'source_record_id' => 100,
                'hotel_id' => 7,
                'platform' => 'meituan',
                'object_type' => 'campaign',
                'action_type' => 'promotion',
                'date_start' => '2026-07-18',
                'date_end' => '2026-07-18',
                'expected_metric' => 'revenue',
                'status' => 'approved',
            ], [[
                'id' => 70,
                'intent_id' => 30,
                'hotel_id' => 7,
                'status' => 'executed',
                'result_status' => $reviewStatus,
                'executed_at' => '2026-07-18 12:00:00',
            ]], [[
                'id' => 80,
                'task_id' => 70,
                'evidence_type' => 'source_verified_metric_readback',
                'before_json' => json_encode(['revenue' => 100], JSON_UNESCAPED_UNICODE),
                'after_json' => json_encode(['revenue' => 120, 'cost' => 10], JSON_UNESCAPED_UNICODE),
                'platform_response_json' => $this->sourceVerifiedPlatformResponse(
                    7,
                    'meituan',
                    'campaign',
                    '2026-07-18',
                    '2026-07-18',
                    'revenue',
                    'operation_metrics#outcome-' . $reviewStatus
                ),
                'created_by' => 0,
                'created_at' => '2026-07-18 13:00:00',
            ]]);

            self::assertTrue($item['evidence_truth']['source_verified'], $reviewStatus);
            self::assertSame('partial', $item['truth_context']['status'], $reviewStatus);
            self::assertSame($failureReason, $item['truth_context']['failure_reason'], $reviewStatus);
            self::assertSame('partial', $item['roi']['status'], $reviewStatus);
            self::assertSame($failureReason, $item['roi']['failure_reason'], $reviewStatus);
            self::assertNull($item['roi']['value'], $reviewStatus);
            self::assertSame($expectedStage, $item['stage'], $reviewStatus);
        }
    }

    public function testExecutionFlowBlocksReviewBeforeRecordedNextReviewDate(): void
    {
        $service = new OperationManagementService();
        $nextReviewDate = date('Y-m-d', strtotime('+1 day'));

        $item = $service->buildExecutionFlowItem([
            'id' => 16,
            'source_module' => 'ota_diagnosis',
            'source_record_id' => 80,
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'object_type' => 'operation',
            'action_type' => 'manual_follow_up',
            'current_value_json' => '{}',
            'target_value_json' => '{}',
            'evidence_json' => '{}',
            'expected_metric' => 'orders',
            'expected_delta' => 0,
            'risk_level' => 'medium',
            'status' => 'approved',
            'blocked_reason' => '',
        ], [[
            'id' => 35,
            'intent_id' => 16,
            'hotel_id' => 7,
            'execution_mode' => 'manual',
            'status' => 'executed',
            'result_status' => 'observing',
            'executed_at' => date('Y-m-d H:i:s'),
            'current_value_json' => '{}',
            'target_value_json' => '{}',
        ]], [[
            'id' => 45,
            'task_id' => 35,
            'evidence_type' => 'manual_operation_execution',
            'before_json' => '{}',
            'after_json' => '{}',
            'platform_response_json' => json_encode([
                'next_review_date' => $nextReviewDate,
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => date('Y-m-d H:i:s'),
        ]]);

        self::assertSame($nextReviewDate, $item['review']['available_on']);
        self::assertFalse($item['review']['is_available']);
    }

    public function testExecutionFlowUsesExactScheduledReviewTime(): void
    {
        $service = new OperationManagementService();
        $reviewAt = date('Y-m-d H:i:s', strtotime('+2 hours'));

        $item = $service->buildExecutionFlowItem([
            'id' => 17,
            'source_module' => 'ota_diagnosis_saved',
            'source_record_id' => 81,
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'object_type' => 'campaign',
            'action_type' => 'booking_conversion_optimization',
            'current_value_json' => '{}',
            'target_value_json' => json_encode([
                'workflow_schedule' => ['review_at' => $reviewAt],
            ], JSON_UNESCAPED_UNICODE),
            'evidence_json' => '{}',
            'expected_metric' => 'order_rate',
            'expected_delta' => 1,
            'risk_level' => 'medium',
            'status' => 'approved',
            'blocked_reason' => '',
        ], [[
            'id' => 36,
            'intent_id' => 17,
            'hotel_id' => 7,
            'execution_mode' => 'manual',
            'status' => 'executed',
            'result_status' => 'observing',
            'executed_at' => date('Y-m-d H:i:s', strtotime('-1 hour')),
            'current_value_json' => '{}',
            'target_value_json' => '{}',
        ]], [[
            'id' => 46,
            'task_id' => 36,
            'evidence_type' => 'manual_operation_execution',
            'before_json' => '{}',
            'after_json' => '{}',
            'platform_response_json' => '{}',
            'created_at' => date('Y-m-d H:i:s'),
        ]]);

        self::assertSame($reviewAt, $item['review']['available_at']);
        self::assertSame(substr($reviewAt, 0, 10), $item['review']['available_on']);
        self::assertFalse($item['review']['is_available']);
    }

    private function priceIntentService(): OperationManagementService
    {
        return $this->priceIntentServiceWithRecommendation([
            'can_create_execution_intent' => true,
            'blocked_reason' => '',
            'decision_quality' => [
                'contract_version' => AiDecisionQualityService::CONTRACT_VERSION,
                'execution_ready' => true,
            ],
        ]);
    }

    /** @param array<string, mixed> $recommendation */
    private function priceIntentServiceWithRecommendation(array $recommendation): OperationManagementService
    {
        $pricingService = $this->createMock(RevenuePricingRecommendationService::class);
        $pricingService->method('enrichSuggestionRows')->willReturnCallback(static function (array $rows) use ($recommendation): array {
            if (isset($rows[0]) && is_array($rows[0])) {
                $rows[0]['decision_recommendation'] = $recommendation;
            }

            return $rows;
        });

        return new OperationManagementService($pricingService);
    }

    private function sourceVerifiedPlatformResponse(
        int $hotelId,
        string $platform,
        string $objectType,
        string $dateStart,
        string $dateEnd,
        string $metricKey,
        string $sourceRef
    ): string {
        return (string)json_encode([
            'verification_authority' => 'system_readback',
            'source' => 'operation_metric_readback',
            'source_ref' => $sourceRef,
            'system_hotel_id' => $hotelId,
            'platform' => $platform,
            'object_type' => $objectType,
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
            'metric_key' => $metricKey,
            'database_written' => true,
            'readback_verified' => true,
            'readback_count' => 1,
            'readback_at' => $dateEnd . ' 23:59:59',
            'validation_status' => 'verified',
        ], JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, string> */
    private function revenueNodeRecordV2(): array
    {
        return [
            'contract_version' => 'operation_revenue_node.v2',
            'system_hotel_id' => '7',
            'business_date' => '2026-08-03',
            'recorded_at' => '2026-08-03 16:00:00',
            'operating_period' => 'weekend',
            'special_event' => '',
            'source_scope' => 'pms_ota_cross_check',
            'room_status_alignment' => 'operator_confirmed',
            'data_quality_status' => 'manual_confirmed',
            'metric_definition' => 'same-scope booking pace',
            'comparison_basis' => 'last four weekend 16:00 nodes',
            'metric_snapshot' => '',
            'progress_status' => 'normal',
            'judgment_basis' => 'manual same-scope comparison',
            'primary_risk' => '',
            'success_criteria' => 'review the same scope at 20:00',
            'stop_condition' => 'stop on room-status mismatch',
        ];
    }
}
