<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperationManagementService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OperationExecutionLoopTest extends TestCase
{
    public function testPriceIntentIsBlockedWhenRoomOrRateMappingIsMissing(): void
    {
        $service = new OperationManagementService();
        self::assertTrue(method_exists($service, 'buildExecutionIntentPayload'), 'Missing execution intent payload builder');

        $payload = $service->buildExecutionIntentPayload([7], null, [
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

        self::assertSame('blocked', $payload['status']);
        self::assertStringContainsString('room_type_key', $payload['blocked_reason']);
        self::assertStringContainsString('rate_plan_key', $payload['blocked_reason']);
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

    public function testPriceSuggestionBuildsExecutionIntentInput(): void
    {
        $service = new OperationManagementService();

        $input = $service->buildPriceSuggestionExecutionIntentInput([
            'id' => 77,
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

    public function testExecutedTaskRequiresEvidence(): void
    {
        $service = new OperationManagementService();
        self::assertTrue(method_exists($service, 'buildExecutionTaskUpdate'), 'Missing execution task update builder');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('evidence');

        $service->buildExecutionTaskUpdate(
            ['id' => 9, 'status' => 'pending_execute'],
            ['id' => 4, 'status' => 'approved'],
            ['status' => 'executed'],
            3
        );
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
            'target_value_json' => json_encode(['campaign_type' => 'discount', 'budget' => 200, 'target_metric' => 'revenue'], JSON_UNESCAPED_UNICODE),
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
        ]], [[
            'id' => 41,
            'task_id' => 31,
            'evidence_type' => 'manual_screenshot',
            'before_json' => json_encode(['revenue' => 1000], JSON_UNESCAPED_UNICODE),
            'after_json' => json_encode(['revenue' => 1300, 'cost' => 200], JSON_UNESCAPED_UNICODE),
            'platform_response_json' => json_encode(['mode' => 'manual'], JSON_UNESCAPED_UNICODE),
            'remark' => 'updated in OTA backend',
            'created_at' => '2026-05-27 11:10:00',
        ]]);

        self::assertSame('reviewed', $item['stage']);
        self::assertSame('strategy_simulation#22', $item['recommendation']['source']);
        self::assertSame('approved', $item['approval']['status']);
        self::assertSame('executed', $item['execution']['status']);
        self::assertSame(1, $item['evidence']['count']);
        self::assertSame('success', $item['review']['status']);
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

    public function testExecutionFlowSummaryExposesMoneyAndConversionRates(): void
    {
        $service = new OperationManagementService();

        $summary = $service->buildExecutionFlowSummary([
            [
                'stage' => 'reviewed',
                'approval' => ['status' => 'approved'],
                'execution' => ['status' => 'executed'],
                'evidence' => ['count' => 1],
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
            ['stage' => 'reviewed', 'approval' => ['status' => 'approved'], 'execution' => ['status' => 'executed'], 'evidence' => ['count' => 1], 'roi' => ['status' => 'ready', 'value' => 40, 'incremental_revenue' => 280, 'cost' => 200, 'profit' => 80]],
        ]);

        self::assertSame('approval', $summary['bottleneck']['stage']);
        self::assertSame(2, $summary['bottleneck']['count']);
        self::assertSame('profit_positive', $summary['money_status']);
    }
}
