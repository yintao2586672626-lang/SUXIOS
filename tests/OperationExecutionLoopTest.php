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
}
