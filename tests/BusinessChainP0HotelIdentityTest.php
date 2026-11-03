<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class BusinessChainP0HotelIdentityTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../scripts/report_business_chain_status.php';
    }

    public function testMatchingHotelRequiresItsOwnProfileAndRowEvidence(): void
    {
        $plan = $this->plan($this->payload());
        self::assertTrue(\business_chain_p0_execution_plan_ready($plan));
        self::assertSame('ready', $plan['scope']['hotel_identity']['status']);
        self::assertTrue($plan['platform_summaries'][0]['selected_hotel_ready']);
    }

    public function testDifferentVerifierHotelCannotOfferAnotherHotelsActions(): void
    {
        $plan = $this->plan($this->payload(), 64);
        self::assertFalse(\business_chain_p0_execution_plan_ready($plan));
        self::assertSame(64, $plan['scope']['system_hotel_id']);
        self::assertSame(80, $plan['scope']['hotel_identity']['verifier_system_hotel_id']);
        self::assertSame('verifier_system_hotel_mismatch', $plan['scope']['hotel_identity']['reason']);
        self::assertSame([], $plan['platform_summaries'][0]['next_steps']);
        self::assertSame('', $plan['platform_summaries'][0]['action_entry']);
        self::assertSame(['verify_requested_system_hotel'], array_column($plan['operator_sequence'], 'type'));
        self::assertStringContainsString('--system-hotel-id=64', $plan['operator_sequence'][0]['command']);
    }

    public function testExactVerifierScopeStillCannotBorrowAnotherHotelsRows(): void
    {
        $payload = $this->payload();
        $payload['scope']['system_hotel_id'] = 64;
        $plan = $this->plan($payload, 64);
        self::assertFalse(\business_chain_p0_execution_plan_ready($plan));
        self::assertFalse($plan['platform_summaries'][0]['selected_hotel_ready']);
        self::assertContains('selected_system_hotel_evidence_missing', $plan['platform_summaries'][0]['missing_inputs']);
        self::assertSame([], $plan['platform_summaries'][0]['next_steps']);
    }

    public function testMissingVerifierHotelScopeIsNotFilledFromTheRequest(): void
    {
        $payload = $this->payload();
        unset($payload['scope']['system_hotel_id']);
        $plan = $this->plan($payload);
        self::assertFalse(\business_chain_p0_execution_plan_ready($plan));
        self::assertSame('verifier_system_hotel_scope_missing', $plan['scope']['hotel_identity']['reason']);
    }

    public function testCurrentHotelCanBeReadyInAnExplicitMultiHotelPartialVerification(): void
    {
        $payload = $this->payload();
        $payload['scope']['system_hotel_id'] = null;
        $payload['scope']['hotel_scope_policy'] = 'platform_date';
        $payload['status'] = 'incomplete';
        $gate = &$payload['platforms'][0]['p0_traffic_gate'];
        $gate['status'] = 'profile_scope_traffic_closure_incomplete';
        $gate['profile_scope_system_hotel_ids'] = [77, 80];
        $gate['profile_scope_missing_target_date_traffic_hotel_ids'] = [77];
        $gate['hotel_scoped_next_steps'][] = ['system_hotel_id' => 77];
        $plan = $this->plan($payload);
        self::assertTrue(\business_chain_p0_execution_plan_ready($plan));
        self::assertFalse($plan['platform_summaries'][0]['platform_ready']);
        self::assertTrue($plan['platform_summaries'][0]['selected_hotel_ready']);
        self::assertSame([80], array_column($plan['platform_summaries'][0]['next_steps'], 'system_hotel_id'));
        $contract = $this->sourceContract($plan);
        self::assertTrue($contract['claim_allowed']);
        self::assertSame(80, $contract['system_hotel_id']);
    }

    public function testPlatformReadyDoesNotBypassMissingHotelProofOrFieldIdentity(): void
    {
        foreach (['profile_scope_system_hotel_ids', 'system_hotel_row_counts', 'platform_hotel_identifier_status'] as $key) {
            $payload = $this->payload();
            unset($payload['platforms'][0]['p0_traffic_gate'][$key]);
            self::assertFalse(\business_chain_p0_execution_plan_ready($this->plan($payload)), $key);
        }
    }

    public function testRegisteredHotelIdentityCannotBelongToAnotherHotel(): void
    {
        $contract = $this->sourceContract($this->plan($this->payload()), 64);
        self::assertFalse($contract['claim_allowed']);
        self::assertSame('blocked', $contract['status']);
        self::assertContains('system_hotel_identity_scope_mismatch', $contract['quality_flags']);
    }

    public function testOldPlanWithoutHotelProofAndChangedSelectionAreBlocked(): void
    {
        $plan = $this->plan($this->payload());
        unset($plan['scope']['hotel_identity']);
        self::assertFalse(\business_chain_p0_execution_plan_ready($plan));
        $plan = $this->plan($this->payload());
        $plan['scope']['system_hotel_id'] = 64;
        self::assertFalse(\business_chain_p0_execution_plan_ready($plan));
    }

    public function testMarkdownShowsHotelRecoveryAndNoOtherHotelOperatingLinks(): void
    {
        $plan = $this->plan($this->payload(), 64);
        $markdown = \business_chain_markdown(['p0_execution_plan' => $plan]);
        self::assertStringContainsString('hotel_identity: `blocked`', $markdown);
        self::assertStringContainsString('requested_hotel=`64`', $markdown);
        self::assertStringContainsString('verifier_hotel=`80`', $markdown);
        self::assertStringContainsString('verifier_system_hotel_mismatch', $markdown);
        self::assertStringContainsString('--system-hotel-id=64', $markdown);
        self::assertStringNotContainsString('/test-only-hotel-80-login', $markdown);
        self::assertStringNotContainsString('- already_ready ', $markdown);
    }

    public function testInvalidVerifierHotelAndScopePolicyCannotBecomeEvidence(): void
    {
        foreach ([0, -1, '80-other', '', [], false] as $hotelId) {
            $payload = $this->payload();
            $payload['scope']['system_hotel_id'] = $hotelId;
            self::assertFalse(\business_chain_p0_execution_plan_ready($this->plan($payload)));
        }
        $payload = $this->payload();
        unset($payload['scope']['hotel_scope_policy']);
        self::assertFalse(\business_chain_p0_execution_plan_ready($this->plan($payload)));
        self::assertFalse(\business_chain_p0_execution_plan_ready($this->plan($this->payload(), 0)));
    }

    public function testOwnProfileMissingListsAndZeroRowsBlockSelectedReadiness(): void
    {
        foreach (['profile_scope_missing_profile_source_hotel_ids', 'profile_scope_missing_traffic_source_hotel_ids',
            'profile_scope_missing_target_date_traffic_hotel_ids'] as $key) {
            $payload = $this->payload();
            $payload['platforms'][0]['p0_traffic_gate'][$key] = [80];
            self::assertFalse(\business_chain_p0_execution_plan_ready($this->plan($payload)));
        }
        $payload = $this->payload();
        $payload['platforms'][0]['p0_traffic_gate']['system_hotel_row_counts']['80'] = 0;
        self::assertFalse(\business_chain_p0_execution_plan_ready($this->plan($payload)));
    }

    public function testOperatorSkipAndFailedVerifierStillCannotPassSelectedScope(): void
    {
        $payload = $this->payload();
        $plan = \business_chain_compact_p0_execution_plan($payload, '2026-07-28', 80, 0, ['meituan'], ['meituan']);
        self::assertFalse(\business_chain_p0_execution_plan_ready($plan));
        self::assertSame('', $plan['platform_summaries'][0]['action_entry']);
        $payload['status'] = 'failed';
        self::assertFalse(\business_chain_p0_execution_plan_ready($this->plan($payload)));
    }

    public function testCachedMarkdownCannotRetainReadyOrAnotherHotelsAction(): void
    {
        $plan = $this->plan($this->payload());
        unset($plan['platform_summaries'][0]['hotel_scope_evidence']);
        $plan['completion_gate']['selected_scope_status'] = 'ready';
        $plan['operator_sequence'][] = ['type' => 'manual_login', 'system_hotel_id' => 64, 'entry' => '/test-only-other-hotel'];
        $markdown = \business_chain_markdown(['p0_execution_plan' => $plan]);
        self::assertStringContainsString('selected_scope_status: `blocked`', $markdown);
        self::assertStringNotContainsString('/test-only-other-hotel', $markdown);
        self::assertStringNotContainsString('- already_ready ', $markdown);
    }

    public function testSourceClaimAndMarkdownKeepRegistrationFailureSkipAndReadbackBlocked(): void
    {
        $plan = $this->plan($this->payload());
        $plan['scope']['system_hotel_identity'] = ['status' => 'ready', 'system_hotel_id' => 64];
        self::assertFalse(\business_chain_p0_execution_plan_ready($plan));
        self::assertStringContainsString('selected_scope_status: `blocked`', \business_chain_markdown(['p0_execution_plan' => $plan]));
        foreach (['operator_skip_active' => true, 'readback_status' => 'failed', 'readback_unverified_rows' => 1,
            'readback_check_supported' => false] as $key => $value) {
            $plan = $this->plan($this->payload());
            $plan['platform_summaries'][0][$key] = $value;
            self::assertFalse($this->sourceContract($plan)['claim_allowed'], $key);
        }
        $plan = $this->plan($this->payload());
        $plan['status'] = 'failed';
        self::assertFalse($this->sourceContract($plan)['claim_allowed']);
    }

    private function sourceContract(array $plan, int $identityHotelId = 80): array
    {
        $plan['scope']['system_hotel_identity'] = [
            'status' => 'ready', 'system_hotel_id' => $identityHotelId, 'system_hotel_name' => 'test-only-hotel',
        ];
        return \business_chain_attach_source_date_quality([[
            'source' => 'meituan', 'target_date' => '2026-07-28', 'target_status' => 'ready',
        ]], $plan)[0]['source_date_quality'];
    }

    private function plan(array $payload, ?int $hotelId = 80): array
    {
        return \business_chain_compact_p0_execution_plan($payload, '2026-07-28', $hotelId, 0, [], ['meituan']);
    }

    private function payload(): array
    {
        return [
            'status' => 'passed',
            'scope' => ['date' => '2026-07-28', 'system_hotel_id' => 80, 'hotel_scope_policy' => 'system_hotel_id'],
            'platforms' => [[
                'platform' => 'meituan', 'target_date_rows' => 1, 'field_fact_status' => 'ready',
                'p0_traffic_gate' => [
                    'status' => 'ready', 'traffic_rows' => 1, 'action_status' => 'ready',
                    'stored_target_date_traffic_rows' => 1,
                    'traffic_field_fact_status' => 'ready', 'p0_standard_fact_status' => 'ready',
                    'required_metric_value_status' => 'ready', 'platform_hotel_identifier_status' => 'ready',
                    'readback_check_supported' => true, 'readback_verified_rows' => 1,
                    'readback_unverified_rows' => 0, 'readback_status' => 'ready',
                    'action_entry' => '/test-only-hotel-80-capture',
                    'system_hotel_row_counts' => ['80' => 1], 'profile_scope_system_hotel_ids' => [80],
                    'hotel_scoped_next_steps' => [[
                        'system_hotel_id' => 80, 'data_source_id' => 50,
                        'p0_verifier_command' => 'test-only-hotel-80-verifier',
                        'profile_login_trigger' => ['entry' => '/test-only-hotel-80-login',
                            'after_login_sync' => ['entry' => '/test-only-hotel-80-sync']],
                    ]],
                ],
            ]],
        ];
    }
}
