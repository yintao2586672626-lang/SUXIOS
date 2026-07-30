<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class BusinessChainP0ExecutionPlanTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../scripts/report_business_chain_status.php';
    }

    public function testMixedHotelReadinessNeverPromotesTheWholePlatformToReady(): void
    {
        $payload = [
            'status' => 'incomplete',
            'scope' => ['date' => '2026-07-25'],
            'summary' => [
                'p0_platforms_incomplete' => 1,
                'traffic_gates_incomplete' => 1,
            ],
            'platforms' => [[
                'platform' => 'ctrip',
                'target_date_rows' => 561,
                'latest_available' => [
                    'date' => '2026-07-25',
                    'rows' => 561,
                ],
                'field_fact_status' => 'partial',
                'p0_traffic_gate' => [
                    'status' => 'profile_scope_traffic_closure_incomplete',
                    'traffic_rows' => 3,
                    'action_status' => 'ready',
                    'action_missing_inputs' => [],
                    'traffic_field_fact_status' => 'ready',
                    'p0_standard_fact_status' => 'ready',
                    'required_metric_value_status' => 'ready',
                    'platform_hotel_identifier_status' => 'ready',
                    'system_hotel_row_counts' => ['80' => 3],
                    'profile_scope_system_hotel_ids' => [77, 80, 219],
                    'profile_scope_missing_profile_source_hotel_ids' => [77],
                    'profile_scope_missing_traffic_source_hotel_ids' => [77],
                    'profile_scope_missing_target_date_traffic_hotel_ids' => [77, 219],
                    'hotel_scoped_next_steps' => [
                        $this->step(64, 15, 'disabled'),
                        $this->step(77, null, 'profile_source_missing', 'not_available'),
                        $this->step(80, 25, 'partial_success'),
                        $this->step(219, 300, 'ready'),
                    ],
                ],
            ]],
        ];

        $plan = \business_chain_compact_p0_execution_plan(
            $payload,
            '2026-07-25',
            null,
            2,
            [],
            ['ctrip']
        );

        self::assertFalse($plan['platform_summaries'][0]['platform_ready']);
        self::assertSame(
            [77, 80, 219],
            array_column($plan['platform_summaries'][0]['next_steps'], 'system_hotel_id')
        );
        self::assertSame(
            [false, true, false],
            array_column($plan['platform_summaries'][0]['next_steps'], 'hotel_ready')
        );

        $typesByHotel = [];
        foreach ($plan['operator_sequence'] as $item) {
            $typesByHotel[(int)$item['system_hotel_id']][] = (string)$item['type'];
        }

        self::assertArrayNotHasKey(64, $typesByHotel);
        self::assertSame(
            ['manual_login', 'after_login_sync', 'single_scope_verifier'],
            $typesByHotel[77]
        );
        self::assertSame(
            ['already_ready', 'single_scope_verifier'],
            $typesByHotel[80]
        );
        self::assertSame(
            ['manual_login', 'after_login_sync', 'single_scope_verifier'],
            $typesByHotel[219]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function step(
        int $hotelId,
        ?int $sourceId,
        string $sourceStatus,
        string $loginStatus = 'ready_for_session_probe'
    ): array {
        return [
            'system_hotel_id' => $hotelId,
            'data_source_id' => $sourceId,
            'data_source_status' => $sourceStatus,
            'last_sync_status' => $sourceStatus,
            'manual_login_state_verified' => false,
            'profile_login_trigger' => [
                'status' => $loginStatus,
                'entry' => $loginStatus === 'not_available'
                    ? ''
                    : 'https://ebooking.ctrip.com/home/mainland',
                'after_login_sync' => [
                    'entry' => $sourceId === null
                        ? ''
                        : '/api/online-data/data-sources/' . $sourceId . '/sync',
                ],
            ],
            'p0_verifier_command' => 'verify --hotel=' . $hotelId,
        ];
    }
}
