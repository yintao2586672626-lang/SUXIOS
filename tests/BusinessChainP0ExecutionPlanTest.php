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
                    'readback_check_supported' => true,
                    'readback_verified_rows' => 3,
                    'readback_unverified_rows' => 0,
                    'readback_status' => 'ready',
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
        self::assertSame('ready', $plan['platform_summaries'][0]['readback_status']);
        self::assertSame(3, $plan['platform_summaries'][0]['readback_verified_rows']);
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

    public function testUnifiedSourceQualityKeepsMissingMeituanBindingAndReadbackExplicit(): void
    {
        $rows = [[
            'source' => 'meituan',
            'target_date' => '2026-07-28',
            'target_status' => 'empty',
            'target_counts' => ['accepted' => 0, 'traffic' => 0],
        ]];
        $plan = [
            'scope' => [
                'system_hotel_id' => 80,
                'system_hotel_identity' => [
                    'status' => 'ready',
                    'system_hotel_id' => 80,
                    'system_hotel_name' => '敦煌漠蓝新',
                    'expected_hotel_name' => '敦煌漠蓝新',
                    'expected_name_status' => 'matched',
                    'same_name_system_hotel_ids' => [80],
                ],
            ],
            'platform_summaries' => [[
                'platform' => 'meituan',
                'target_date_rows' => 0,
                'traffic_rows' => 0,
                'field_fact_status' => 'not_loaded',
                'traffic_gate_status' => 'missing_target_date_traffic_rows',
                'platform_ready' => false,
                'readback_check_supported' => true,
                'readback_verified_rows' => 0,
                'readback_unverified_rows' => 0,
                'readback_status' => 'not_loaded',
                'action_entry' => '/api/online-data/capture-meituan-browser',
                'missing_inputs' => [
                    'registered_browser_profile_data_source',
                    'active_hotel_scoped_profile_binding',
                ],
            ]],
        ];

        $qualityRows = \business_chain_attach_source_date_quality($rows, $plan);
        $contract = $qualityRows[0]['source_date_quality'];

        self::assertSame('binding_missing', $qualityRows[0]['quality_status']);
        self::assertSame('blocked', $contract['status']);
        self::assertFalse($contract['claim_allowed']);
        self::assertSame('2026-07-28', $contract['target_date']);
        self::assertSame(80, $contract['system_hotel_id']);
        self::assertSame('敦煌漠蓝新', $contract['system_hotel_name']);
        self::assertSame('敦煌漠蓝新', $contract['expected_hotel_name']);
        self::assertSame('matched', $contract['evidence']['expected_name_status']);
        self::assertSame('ready', $contract['evidence']['system_hotel_identity_status']);
        self::assertSame('not_loaded', $contract['evidence']['readback_status']);
        self::assertContains('registered_browser_profile_data_source', $contract['quality_flags']);
        self::assertContains('target_date_readback_not_loaded', $contract['quality_flags']);
    }

    public function testFloorPriceGapRoutesToTheRoomTypePricingGuard(): void
    {
        $queue = \business_chain_ctrip_chain_action_queue(
            [
                'source_scope' => 'ctrip_meituan_target_date_ota_channel',
                'source_platforms' => ['ctrip', 'meituan'],
                'manual_review_packet' => [
                    'status' => 'blocked_ready_for_manual_review',
                    'primary_action' => ['reason' => 'competitor_price_fields_missing'],
                    'primary_blocker' => ['reason' => 'floor_price_missing'],
                ],
            ],
            [
                'status' => 'operation_intake_blocked_by_manual_review',
                'operation_intake_packet' => [
                    'candidate_blocked_reason' => 'floor_price_missing',
                ],
            ]
        );

        $item = $queue['items'][0];
        self::assertSame('floor_price_missing', $item['evidence_code']);
        self::assertSame('ai_decision_review_inputs', $item['stage']);
        self::assertSame('agent-center', $item['target_entry']);
        self::assertSame(
            'room_type_floor_price_saved_and_readback_verified',
            $item['required_gate']
        );
        self::assertSame(
            'configure_room_type_floor_price_before_ai_review',
            $item['next_action']
        );
    }

    public function testNextRequiredGateAdvancesPastReadyP0ToTheRealDataGap(): void
    {
        $gate = \business_chain_next_required_gate(
            [
                'status' => 'ready',
                'current_upstream_status' => 'ready',
                'required_upstream_status' => 'ready',
                'required_gate_command' => 'verify-ready-p0',
            ],
            [
                'unique_remaining_gap' => [
                    'code' => 'floor_price_missing',
                    'status' => 'missing',
                ],
            ],
            []
        );

        self::assertSame('data_gap', $gate['type']);
        self::assertSame('', $gate['command']);
        self::assertSame('floor_price_missing', $gate['evidence_code']);
        self::assertSame('ai_decision_review_inputs', $gate['stage']);
        self::assertSame('agent-center', $gate['target_entry']);
        self::assertSame(
            'room_type_floor_price_saved_and_readback_verified',
            $gate['required_gate']
        );
        self::assertSame('missing', $gate['current_status']);
    }

    public function testNextRequiredGateKeepsP0FirstWhileItIsBlocked(): void
    {
        $gate = \business_chain_next_required_gate(
            [
                'status' => 'blocked',
                'current_upstream_status' => 'missing_target_date_rows',
                'required_upstream_status' => 'ready',
                'required_gate_command' => 'verify-blocked-p0',
            ],
            [
                'unique_remaining_gap' => [
                    'code' => 'floor_price_missing',
                    'status' => 'missing',
                ],
            ],
            []
        );

        self::assertSame('p0_verifier', $gate['type']);
        self::assertSame('verify-blocked-p0', $gate['command']);
        self::assertSame('ready', $gate['required_status']);
        self::assertSame('missing_target_date_rows', $gate['current_status']);
    }

    public function testNextRequiredGateRoutesPmsReadbackGapToPmsOperatingData(): void
    {
        $gate = \business_chain_next_required_gate(
            [
                'status' => 'ready',
                'current_upstream_status' => 'ready',
                'required_upstream_status' => 'ready',
                'required_gate_command' => 'verify-ready-p0',
            ],
            [
                'unique_remaining_gap' => [
                    'code' => 'dingdandao_pms_not_readback_verified',
                    'status' => 'not_verified',
                ],
            ],
            []
        );

        self::assertSame('data_gap', $gate['type']);
        self::assertSame('dingdandao_pms_not_readback_verified', $gate['evidence_code']);
        self::assertSame('pms_collection', $gate['stage']);
        self::assertSame('pms-operating-data', $gate['target_entry']);
        self::assertSame(
            'pms_collection_claim_and_readback_verified',
            $gate['required_gate']
        );
        self::assertSame(
            'recollect_pms_with_current_contract_and_exact_readback',
            $gate['next_action']
        );
    }

    public function testNextRequiredGateDoesNotHideMultipleSourceGapsBehindOneRevenueMetric(): void
    {
        $gate = \business_chain_next_required_gate(
            [
                'status' => 'ready',
                'current_upstream_status' => 'ready',
                'required_upstream_status' => 'ready',
            ],
            [
                'status' => 'blocked',
                'unique_remaining_gap' => null,
                'analysis_gaps' => [
                    ['code' => 'dingdandao_pms_not_readback_verified'],
                    ['code' => 'ctrip_ota_not_readback_verified'],
                ],
            ],
            [
                'ctrip_chain_action_queue' => [
                    'items' => [[
                        'code' => 'resolve_revenue_metric_gap',
                        'status' => 'blocked',
                        'blocking' => true,
                        'evidence_code' => 'available_room_nights_missing',
                    ]],
                ],
            ]
        );

        self::assertSame('data_gaps', $gate['type']);
        self::assertSame('resolve_three_source_fact_gaps', $gate['code']);
        self::assertSame('multiple_three_source_fact_gaps', $gate['evidence_code']);
        self::assertSame(
            ['dingdandao_pms_not_readback_verified', 'ctrip_ota_not_readback_verified'],
            $gate['evidence_codes']
        );
        self::assertSame('all_three_sources_readback_verified', $gate['required_gate']);
        self::assertSame('source_collection', $gate['stage']);
    }

    public function testHistoricalPmsGapRoutesToAuthorizedSingleDateRecollection(): void
    {
        $gate = \business_chain_next_required_gate(
            [
                'status' => 'ready',
                'current_upstream_status' => 'ready',
                'required_upstream_status' => 'ready',
            ],
            [
                'business_date' => '2026-07-29',
                'unique_remaining_gap' => [
                    'code' => 'dingdandao_pms_not_readback_verified',
                    'status' => 'not_verified',
                    'recovery_status' => 'historical_recollection_available',
                    'live_recollection_allowed' => false,
                    'historical_recollection_allowed' => true,
                ],
                'sources' => [
                    'dingdandao_pms' => [
                        'source' => [
                            'capture_source_scope' => 'today_only',
                        ],
                    ],
                ],
            ],
            []
        );

        self::assertSame('data_gap', $gate['type']);
        self::assertSame('pms_historical_collection', $gate['stage']);
        self::assertSame('pms-operating-data', $gate['target_entry']);
        self::assertSame(
            'historical_pms_single_date_collection_and_readback_verified',
            $gate['required_gate']
        );
        self::assertSame(
            'recollect_authorized_historical_pms_single_date_and_exact_readback',
            $gate['next_action']
        );
        self::assertSame(
            'historical_recollection_available',
            $gate['recovery_status']
        );
        self::assertFalse($gate['live_recollection_allowed']);
        self::assertTrue($gate['historical_recollection_allowed']);
    }

    public function testSelectedHotelP0GateCanBeReadyWhileGlobalVerifierRetainsNonP0Issues(): void
    {
        $plan = [
            'status' => 'incomplete',
            'verifier_exit_code' => 2,
            'scope' => [
                'system_hotel_id' => 80,
                'platforms' => ['ctrip', 'meituan'],
            ],
            'platform_summaries' => [
                $this->readyPlatformSummary('ctrip', 80),
                $this->readyPlatformSummary('meituan', 80),
            ],
        ];

        self::assertTrue(\business_chain_p0_execution_plan_ready($plan));
    }

    public function testSelectedHotelP0GateUsesHotelScopedReadinessWhenOtherHotelsAreIncomplete(): void
    {
        $summary = $this->readyPlatformSummary('ctrip', 80);
        $summary['platform_ready'] = false;
        $summary['next_steps'][] = [
            'platform' => 'ctrip',
            'system_hotel_id' => 77,
            'hotel_ready' => false,
            'operator_skip_active' => false,
        ];

        $plan = [
            'status' => 'incomplete',
            'verifier_exit_code' => 2,
            'scope' => [
                'system_hotel_id' => 80,
                'platforms' => ['ctrip'],
            ],
            'platform_summaries' => [$summary],
        ];

        self::assertTrue(\business_chain_p0_execution_plan_ready($plan));
    }

    public function testSelectedHotelP0GateRejectsMissingPlatformsFailedVerifierAndOperatorSkip(): void
    {
        $readyPlan = [
            'status' => 'incomplete',
            'verifier_exit_code' => 2,
            'scope' => [
                'system_hotel_id' => 80,
                'platforms' => ['ctrip', 'meituan'],
            ],
            'platform_summaries' => [
                $this->readyPlatformSummary('ctrip', 80),
            ],
        ];
        self::assertFalse(\business_chain_p0_execution_plan_ready($readyPlan));

        $failedPlan = $readyPlan;
        $failedPlan['status'] = 'failed';
        $failedPlan['verifier_exit_code'] = 1;
        $failedPlan['scope']['platforms'] = ['ctrip'];
        self::assertFalse(\business_chain_p0_execution_plan_ready($failedPlan));

        $skippedPlan = $readyPlan;
        $skippedPlan['scope']['platforms'] = ['ctrip'];
        $skippedPlan['platform_summaries'][0]['operator_skip_active'] = true;
        self::assertFalse(\business_chain_p0_execution_plan_ready($skippedPlan));
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

    /**
     * @return array<string, mixed>
     */
    private function readyPlatformSummary(string $platform, int $hotelId): array
    {
        return [
            'platform' => $platform,
            'target_date_rows' => 10,
            'traffic_rows' => 3,
            'readback_check_supported' => true,
            'readback_verified_rows' => 3,
            'readback_unverified_rows' => 0,
            'readback_status' => 'ready',
            'platform_ready' => true,
            'operator_skip_active' => false,
            'next_steps' => [[
                'platform' => $platform,
                'system_hotel_id' => $hotelId,
                'hotel_ready' => true,
                'operator_skip_active' => false,
            ]],
        ];
    }
}
