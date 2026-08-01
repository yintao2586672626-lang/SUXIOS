<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperationManagementService;
use app\service\OperationOptimizationExecutionBridgeService;
use app\service\OperationOptimizationWorkbenchService;
use app\service\operation\ExecutionFlowReadService;
use app\service\operation\ExecutionOutcomeService;
use PHPUnit\Framework\TestCase;

final class OperationOptimizationExecutionBridgeServiceTest extends TestCase
{
    public function testHydrateRestoresPersistedLifecycleByOptimizerActionIdentity(): void
    {
        $workbench = $this->workbench();
        $recommendation = $workbench['keyword_workbench']['rows'][0]['recommendation'];
        $operationService = new CapturingOperationOptimizationService();
        $operationService->flow = [
            'list' => [[
                'id' => 91,
                'hotel_id' => 77,
                'stage' => 'review',
                'recommendation' => [
                    'evidence' => ['optimizer_action_id' => $recommendation['id']],
                ],
                'execution' => ['status' => 'executed'],
                'review' => ['reported_status' => 'observing'],
                'evidence_truth' => ['source_verified' => true],
            ]],
        ];

        $result = (new OperationOptimizationExecutionBridgeService($operationService))
            ->hydrate($workbench, [77], 77);
        $hydrated = $result['keyword_workbench']['rows'][0]['recommendation'];

        self::assertSame(91, $hydrated['execution_flow']['id']);
        self::assertSame('partial', $result['loop_summary']['status']);
        self::assertSame(1, $result['loop_summary']['linked_intent_count']);
        self::assertSame(1, $result['loop_summary']['executed_task_count']);
        self::assertSame('missing', $result['learning_summary']['status']);
        self::assertSame('operation_optimizer', $operationService->flowFilters['source_module']);
    }

    public function testHydrateAggregatesIndependentReviewedActionsIntoPatternCandidateOnly(): void
    {
        $workbench = $this->workbench();
        $recommendation = $workbench['keyword_workbench']['rows'][0]['recommendation'];
        $operationService = new CapturingOperationOptimizationService();
        $comparisonKey = 'longitudinal:' . str_repeat('a', 64);
        $actionIds = [
            $recommendation['id'],
            str_repeat('b', 32),
            str_repeat('c', 32),
        ];
        $operationService->flow = ['list' => []];
        foreach ($actionIds as $index => $actionId) {
            $taskId = 201 + $index;
            $operationService->flow['list'][] = [
                'id' => $taskId,
                'hotel_id' => 77,
                'stage' => 'reviewed',
                'recommendation' => [
                    'evidence' => ['optimizer_action_id' => $actionId],
                ],
                'execution' => ['status' => 'executed'],
                'review' => ['reported_status' => 'success'],
                'evidence_truth' => ['source_verified' => true],
                'evidence' => [
                    'latest' => [
                        'platform_response' => [
                            'longitudinal_review' => $this->reviewedObservation(
                                $comparisonKey,
                                $taskId,
                                '2026-07-' . (string)(21 + $index) . ' 08:00:00',
                                301 + $index
                            ),
                        ],
                    ],
                ],
            ];
        }

        $result = (new OperationOptimizationExecutionBridgeService($operationService))
            ->hydrate($workbench, [77], 77);

        self::assertSame('pattern_candidate', $result['learning_summary']['status']);
        self::assertSame(3, $result['learning_summary']['reviewed_observation_count']);
        self::assertSame(1, $result['learning_summary']['pattern_candidate_count']);
        self::assertSame('pattern_candidate', $result['learning_summary']['items'][0]['learning_stage']);
        self::assertFalse($result['learning_summary']['items'][0]['candidate_sop_eligible']);
        self::assertSame(1, $result['loop_summary']['pattern_candidate_count']);
    }

    public function testExecutionFlowKeepsVerifiedLongitudinalReviewWhenANewerNoteExists(): void
    {
        $review = $this->reviewedObservation(
            'longitudinal:' . str_repeat('d', 64),
            301,
            '2026-07-24 08:00:00',
            401
        );
        $item = (new ExecutionFlowReadService(new ExecutionOutcomeService()))->buildItem(
            [
                'id' => 201,
                'hotel_id' => 77,
                'source_module' => 'operation_optimizer',
                'source_record_id' => 0,
                'status' => 'approved',
                'evidence' => [
                    'review_policy' => [
                        'review_window' => ['length_days' => 7],
                    ],
                ],
            ],
            [[
                'id' => 301,
                'intent_id' => 201,
                'status' => 'executed',
                'result_status' => 'observing',
                'executed_at' => '2026-07-20 10:00:00',
            ]],
            [
                [
                    'id' => 502,
                    'task_id' => 301,
                    'evidence_type' => 'operator_note',
                    'platform_response' => ['note' => 'follow-up note'],
                ],
                [
                    'id' => 501,
                    'task_id' => 301,
                    'evidence_type' => 'source_verified_metric_readback',
                    'platform_response_json' => json_encode(
                        ['longitudinal_review' => $review],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ),
                ],
            ]
        );

        self::assertSame('operator_note', $item['evidence']['latest']['evidence_type']);
        self::assertSame($review, $item['evidence']['longitudinal_review']);
        self::assertSame('2026-07-27', $item['review']['available_on']);
    }

    public function testCreateUsesServerRecommendationAndTrustedIdempotentPendingApprovalSource(): void
    {
        $workbench = $this->workbench();
        $recommendation = $workbench['keyword_workbench']['rows'][0]['recommendation'];
        $operationService = new CapturingOperationOptimizationService();
        $bridge = new OperationOptimizationExecutionBridgeService($operationService);

        $intent = $bridge->createFromWorkbench($workbench, $recommendation['id'], [77], 77, 5);

        self::assertSame(501, $intent['id']);
        self::assertSame('operation_optimizer', $operationService->createdInput['source_module']);
        self::assertSame('pending_approval', $operationService->createdInput['status']);
        self::assertSame(
            $recommendation['id'],
            $operationService->createdInput['evidence']['optimizer_action_id']
        );
        self::assertMatchesRegularExpression(
            '/^operation_optimizer_[a-f0-9]{32}$/',
            $operationService->idempotencyKey
        );
        self::assertTrue($operationService->trustedReservedSource);
    }

    public function testOperationOptimizerIdempotencyKeyIsAcceptedByOperationServiceGuard(): void
    {
        $method = new \ReflectionMethod(
            OperationManagementService::class,
            'normalizeTrustedExecutionIntentIdempotencyKey'
        );
        $method->setAccessible(true);
        $key = 'operation_optimizer_' . str_repeat('a', 32);

        self::assertSame($key, $method->invoke(new OperationManagementService(), $key));
    }

    public function testRoomProductRecommendationCanEnterPendingApproval(): void
    {
        $payload = (new OperationManagementService())->buildExecutionIntentPayload([77], 77, [
            'source_module' => 'operation_optimizer',
            'hotel_id' => 77,
            'platform' => 'ctrip',
            'object_type' => 'room_product',
            'action_type' => 'room_type_price_review',
            'date_start' => '2026-07-27',
            'date_end' => '2026-07-27',
            'status' => 'pending_approval',
            'current_value' => ['competitor_price_gap' => 20],
            'target_value' => [
                'room_type_key' => '高级大床房',
                'target_metric' => 'competitor_price_gap',
            ],
            'evidence' => ['evidence_refs' => ['online_daily_data#52']],
            'expected_metric' => 'competitor_price_gap',
            'expected_delta' => 0.01,
        ], 5);

        self::assertSame('pending_approval', $payload['status']);
        self::assertSame('', $payload['blocked_reason']);
    }

    /** @return array<string, mixed> */
    private function workbench(): array
    {
        return (new OperationOptimizationWorkbenchService())->build([
            'fact_ota_search_keyword' => [],
            'fact_ota_advertising' => [[
                'date_key' => '2026-07-27',
                'platform_key' => 'meituan',
                'impressions' => 200,
                'clicks' => 10,
                'bookings' => 0,
                'spend' => 20,
                'order_amount' => 0,
                'raw_data' => ['keyword' => '敦煌酒店'],
                'source_trace' => [
                    'table' => 'online_daily_data',
                    'row_id' => 41,
                    'source_trace_id' => 'trace-41',
                    'platform_hotel_id' => 'meituan-hotel-77',
                    'ingestion_method' => 'profile_capture',
                    'collected_at' => '2026-07-27 08:00:00',
                    'stored' => true,
                    'readback_verified' => true,
                    'saved_success' => true,
                ],
            ]],
            'fact_ota_daily' => [],
            'fact_ota_traffic' => [],
        ], [
            'hotel_id' => 77,
            'start_date' => '2026-07-27',
            'end_date' => '2026-07-27',
        ]);
    }

    /** @return array<string, mixed> */
    private function reviewedObservation(
        string $comparisonKey,
        int $taskId,
        string $capturedAt,
        int $rowId
    ): array {
        return [
            'status' => 'verified',
            'learning_stage' => 'action_reviewed',
            'comparison_key' => $comparisonKey,
            'causality_claimed' => false,
            'action' => [
                'action_ref' => 'operation_execution_task#' . $taskId,
                'action_type' => 'advertising_budget_review',
                'expected_direction' => 'increase',
                'expectation_status' => 'aligned',
            ],
            'followup' => [
                'period_start' => substr($capturedAt, 0, 10),
                'period_end' => substr($capturedAt, 0, 10),
                'captured_at' => $capturedAt,
                'evidence_refs' => ['online_daily_data#' . $rowId],
            ],
            'delta' => ['movement' => 'increase'],
        ];
    }
}

final class CapturingOperationOptimizationService extends OperationManagementService
{
    /** @var array<string, mixed> */
    public array $flow = ['list' => []];
    /** @var array<string, mixed> */
    public array $flowFilters = [];
    /** @var array<string, mixed> */
    public array $createdInput = [];
    public string $idempotencyKey = '';
    public bool $trustedReservedSource = false;

    public function __construct()
    {
    }

    public function executionFlow(array $hotelIds, ?int $hotelId, array $filters = []): array
    {
        $this->flowFilters = $filters;
        return $this->flow;
    }

    public function createExecutionIntent(
        array $hotelIds,
        ?int $hotelId,
        array $input,
        int $createdBy,
        bool $trustedExpansionSource = false,
        ?string $trustedIdempotencyKey = null,
        bool $trustedReservedSource = false
    ): array {
        $this->createdInput = $input;
        $this->idempotencyKey = (string)$trustedIdempotencyKey;
        $this->trustedReservedSource = $trustedReservedSource;
        return [
            'id' => 501,
            'hotel_id' => $hotelId,
            'source_module' => $input['source_module'] ?? '',
            'status' => $input['status'] ?? '',
            'tasks' => [],
        ];
    }
}
