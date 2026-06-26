<?php
declare(strict_types=1);

namespace Tests;

use app\service\SimulationExecutionBridgeService;
use PHPUnit\Framework\TestCase;

final class SimulationExecutionBridgeServiceTest extends TestCase
{
    public function testAttachRowsWithIntentsUsesLatestExecutionIntentForEachRecord(): void
    {
        self::assertTrue(class_exists(SimulationExecutionBridgeService::class), 'Simulation execution bridge service must exist.');

        $service = new SimulationExecutionBridgeService();
        $records = [
            [
                'id' => 91,
                'project_name' => 'Bridge Strategy',
                'execution_readiness' => [
                    'stage' => 'approved_pending_execution',
                    'execution_ready' => false,
                ],
            ],
            [
                'id' => 92,
                'project_name' => 'Unlinked Strategy',
            ],
        ];

        $result = $service->attachRowsWithIntents($records, [
            [
                'id' => 321,
                'source_module' => 'strategy_simulation',
                'source_record_id' => 91,
                'status' => 'pending_approval',
                'blocked_reason' => '',
                'created_at' => '2026-06-24 10:00:00',
                'updated_at' => '2026-06-24 10:00:00',
            ],
            [
                'id' => 322,
                'source_module' => 'strategy_simulation',
                'source_record_id' => 91,
                'status' => 'approved',
                'blocked_reason' => '',
                'created_at' => '2026-06-25 10:00:00',
                'updated_at' => '2026-06-25 10:00:00',
            ],
        ], 'strategy_simulation');

        self::assertSame(322, $result[0]['execution_intent_id']);
        self::assertSame(322, $result[0]['operation_execution_intent_id']);
        self::assertSame('linked', $result[0]['execution_bridge_status']);
        self::assertSame('approved', $result[0]['execution_tracking']['status']);
        self::assertSame('strategy_simulation', $result[0]['execution_tracking']['source_module']);
        self::assertSame(91, $result[0]['execution_tracking']['source_record_id']);

        self::assertSame('not_linked', $result[1]['execution_bridge_status']);
        self::assertArrayNotHasKey('execution_intent_id', $result[1]);
    }

    public function testAttachRowsWithIntentsFiltersBySourceModuleAndPreservesExistingBridge(): void
    {
        $service = new SimulationExecutionBridgeService();

        $result = $service->attachRowsWithIntents([
            [
                'id' => 7,
                'execution_intent_id' => 900,
                'operation_execution_intent_id' => 900,
                'execution_bridge_status' => 'linked',
            ],
        ], [
            [
                'id' => 901,
                'source_module' => 'revenue_research',
                'source_record_id' => 7,
                'status' => 'approved',
            ],
        ], 'quant_simulation');

        self::assertSame(900, $result[0]['execution_intent_id']);
        self::assertSame(900, $result[0]['operation_execution_intent_id']);
        self::assertSame('linked', $result[0]['execution_bridge_status']);
        self::assertArrayNotHasKey('execution_tracking', $result[0]);
    }
}
