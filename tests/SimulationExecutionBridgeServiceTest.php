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
                '_execution_source_tenant_id' => 7,
                '_execution_source_hotel_id' => 7,
                'project_name' => 'Bridge Strategy',
                'execution_readiness' => [
                    'stage' => 'approved_pending_execution',
                    'execution_ready' => false,
                ],
            ],
            [
                'id' => 92,
                '_execution_source_tenant_id' => 7,
                '_execution_source_hotel_id' => 7,
                'project_name' => 'Unlinked Strategy',
            ],
        ];

        $result = $service->attachRowsWithIntents($records, [
            [
                'id' => 321,
                'tenant_id' => 7,
                'hotel_id' => 7,
                'source_module' => 'strategy_simulation',
                'source_record_id' => 91,
                'status' => 'pending_approval',
                'blocked_reason' => '',
                'created_at' => '2026-06-24 10:00:00',
                'updated_at' => '2026-06-24 10:00:00',
            ],
            [
                'id' => 322,
                'tenant_id' => 7,
                'hotel_id' => 7,
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

    public function testAttachRowsWithIntentsFiltersBySourceModuleAndClearsUnverifiedExistingBridge(): void
    {
        $service = new SimulationExecutionBridgeService();

        $result = $service->attachRowsWithIntents([
            [
                'id' => 7,
                '_execution_source_tenant_id' => 7,
                '_execution_source_hotel_id' => 7,
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

        self::assertArrayNotHasKey('execution_intent_id', $result[0]);
        self::assertArrayNotHasKey('operation_execution_intent_id', $result[0]);
        self::assertSame('not_linked', $result[0]['execution_bridge_status']);
        self::assertArrayNotHasKey('execution_tracking', $result[0]);
    }

    public function testAttachRowsWithIntentsRejectsAnIntentFromThePreviousSourceTenant(): void
    {
        $result = (new SimulationExecutionBridgeService())->attachRowsWithIntents([[
            'id' => 91,
            '_execution_source_tenant_id' => 8,
            '_execution_source_hotel_id' => 7,
            'project_name' => 'Moved tenant simulation',
        ]], [
            [
                'id' => 321,
                'tenant_id' => 7,
                'hotel_id' => 7,
                'source_module' => 'strategy_simulation',
                'source_record_id' => 91,
                'status' => 'approved',
            ],
            [
                'id' => 322,
                'tenant_id' => 8,
                'hotel_id' => 7,
                'source_module' => 'strategy_simulation',
                'source_record_id' => 91,
                'status' => 'pending_approval',
            ],
        ], 'strategy_simulation');

        self::assertSame(322, $result[0]['execution_intent_id']);
        self::assertSame('pending_approval', $result[0]['execution_tracking']['status']);
        self::assertArrayNotHasKey('_execution_source_tenant_id', $result[0]);

        $withoutCurrentTenant = (new SimulationExecutionBridgeService())->attachRowsWithIntents([[
            'id' => 91,
            '_execution_source_tenant_id' => 8,
            '_execution_source_hotel_id' => 7,
        ]], [[
            'id' => 321,
            'tenant_id' => 7,
            'hotel_id' => 7,
            'source_module' => 'strategy_simulation',
            'source_record_id' => 91,
        ]], 'strategy_simulation');
        self::assertSame('not_linked', $withoutCurrentTenant[0]['execution_bridge_status']);
        self::assertArrayNotHasKey('_execution_source_tenant_id', $withoutCurrentTenant[0]);
        self::assertArrayNotHasKey('_execution_source_hotel_id', $withoutCurrentTenant[0]);
    }

    public function testAttachRowsWithIntentsRequiresExactHotelActiveStatusAndCanonicalModule(): void
    {
        $record = [[
            'id' => 93,
            '_execution_source_tenant_id' => 7,
            '_execution_source_hotel_id' => 7,
            'execution_intent_id' => 999,
            'operation_execution_intent_id' => 999,
            'execution_bridge_status' => 'linked',
            'execution_tracking' => ['intent_id' => 999],
            'execution_task_id' => 998,
            'opening_project_id' => 997,
            'tracking_record_id' => 996,
            'post_decision_tracking_id' => 995,
            'investment_tracking_id' => 994,
            'post_decision_tracking' => true,
            'execution_readiness' => [
                'stage' => 'execution_ready',
                'execution_ready' => true,
                'checks' => [[
                    'key' => 'execution_bridge',
                    'passed' => true,
                    'status' => 'ok',
                ]],
            ],
        ]];
        $base = [
            'tenant_id' => 7,
            'source_module' => '  StRaTeGy_SiMuLaTiOn  ',
            'source_record_id' => 93,
            'status' => 'approved',
        ];
        $service = new SimulationExecutionBridgeService();

        $crossHotel = $service->attachRowsWithIntents($record, [array_merge($base, [
            'id' => 930,
            'hotel_id' => 8,
        ])], 'strategy_simulation');
        self::assertSame('not_linked', $crossHotel[0]['execution_bridge_status']);
        self::assertArrayNotHasKey('execution_intent_id', $crossHotel[0]);
        self::assertArrayNotHasKey('execution_tracking', $crossHotel[0]);
        foreach ([
            'execution_task_id',
            'opening_project_id',
            'tracking_record_id',
            'post_decision_tracking_id',
            'investment_tracking_id',
            'post_decision_tracking',
        ] as $staleField) {
            self::assertArrayNotHasKey($staleField, $crossHotel[0]);
        }
        self::assertSame('approved_pending_execution', $crossHotel[0]['execution_readiness']['stage']);
        self::assertFalse($crossHotel[0]['execution_readiness']['execution_ready']);
        self::assertFalse($crossHotel[0]['execution_readiness']['checks'][0]['passed']);

        $rejected = $service->attachRowsWithIntents($record, [array_merge($base, [
            'id' => 931,
            'hotel_id' => 7,
            'status' => 'rejected',
        ])], 'strategy_simulation');
        self::assertSame('not_linked', $rejected[0]['execution_bridge_status']);

        $valid = $service->attachRowsWithIntents($record, [array_merge($base, [
            'id' => 932,
            'hotel_id' => 7,
        ])], '  STRATEGY_SIMULATION ');
        self::assertSame(932, $valid[0]['execution_intent_id']);
        self::assertSame('linked', $valid[0]['execution_bridge_status']);
        self::assertTrue($valid[0]['execution_tracking']['_source_bridge_verified']);
        self::assertSame(7, $valid[0]['execution_tracking']['hotel_id']);
        self::assertSame(7, $valid[0]['execution_tracking']['tenant_id']);
    }
}
