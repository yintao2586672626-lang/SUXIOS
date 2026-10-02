<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class BusinessChainP0DateIdentityTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../scripts/report_business_chain_status.php';
    }

    public function testMatchingVerifierDateKeepsTheRequestedPlanReady(): void
    {
        $plan = $this->plan('2026-07-28');
        self::assertSame('2026-07-28', $plan['scope']['target_date']);
        self::assertTrue(\business_chain_p0_execution_plan_ready($plan));
        self::assertSame('ready', $plan['scope']['date_identity']['status']);
    }

    public function testWrongVerifierDateCannotReplaceRequestOrOfferOperatingActions(): void
    {
        $plan = $this->plan('2026-07-27');
        self::assertSame('2026-07-28', $plan['scope']['target_date']);
        self::assertFalse(\business_chain_p0_execution_plan_ready($plan));
        self::assertSame('verifier_business_date_mismatch', $plan['scope']['date_identity']['reason']);
        self::assertSame('2026-07-27', $plan['scope']['date_identity']['verifier_target_date']);
        self::assertSame('blocked', $plan['completion_gate']['current_status']);
        self::assertStringContainsString('--date=2026-07-28', $plan['completion_gate']['command']);
        self::assertFalse($plan['platform_summaries'][0]['platform_ready']);
        self::assertSame('', $plan['platform_summaries'][0]['action_entry']);
        self::assertContains('verifier_business_date_mismatch', $plan['platform_summaries'][0]['missing_inputs']);
        self::assertSame(['verify_requested_business_date'], array_column($plan['operator_sequence'], 'type'));
        $step = $plan['platform_summaries'][0]['next_steps'][0];
        self::assertFalse($step['hotel_ready']);
        self::assertSame('', $step['login_trigger_entry']);
        self::assertSame('', $step['after_login_sync_entry']);
        self::assertSame('', $step['verifier_command']);
    }

    public function testMissingAndInvalidVerifierDatesAreBlockedWithoutBorrowingRequest(): void
    {
        foreach ([null, '', '2026-02-30', '2026-02-29', '2026-7-28'] as $date) {
            $plan = $this->plan($date);
            self::assertFalse(\business_chain_p0_execution_plan_ready($plan));
            self::assertSame('2026-07-28', $plan['scope']['target_date']);
            self::assertSame('blocked', $plan['scope']['date_identity']['status']);
        }
        $payload = $this->payload('2026-07-28');
        unset($payload['scope']['date']);
        $plan = \business_chain_compact_p0_execution_plan($payload, '2026-07-28', 80, 0, [], ['meituan']);
        self::assertSame('verifier_business_date_missing', $plan['scope']['date_identity']['reason']);
        self::assertFalse(\business_chain_p0_execution_plan_ready($plan));
    }

    public function testInvalidRequestedDateOffersNoExecutableVerifierCommand(): void
    {
        $plan = \business_chain_compact_p0_execution_plan($this->payload('2026-07-28'), '2026-02-30', 80, 0, [], ['meituan']);
        self::assertFalse(\business_chain_p0_execution_plan_ready($plan));
        self::assertSame('requested_business_date_invalid', $plan['scope']['date_identity']['reason']);
        self::assertSame('', $plan['completion_gate']['command']);
        self::assertSame('', $plan['operator_sequence'][0]['command']);
    }

    public function testLeapDayAndIncompleteGlobalStatusCanKeepMatchingHotelScopeReady(): void
    {
        $payload = $this->payload('2024-02-29');
        $payload['status'] = 'incomplete';
        $plan = \business_chain_compact_p0_execution_plan($payload, '2024-02-29', 80, 2, [], ['meituan']);
        self::assertTrue(\business_chain_p0_execution_plan_ready($plan));
        self::assertSame('incomplete', $plan['status']);
    }

    public function testOldPlanWithoutDateProofIsUnverifiedRatherThanReady(): void
    {
        $plan = $this->plan('2026-07-28');
        unset($plan['scope']['date_identity']);
        self::assertFalse(\business_chain_p0_execution_plan_ready($plan));
    }

    public function testScopeCannotBeChangedAfterTheDateProofWasBuilt(): void
    {
        $plan = $this->plan('2026-07-28');
        $plan['scope']['target_date'] = '2026-07-27';
        self::assertFalse(\business_chain_p0_execution_plan_ready($plan));
        $markdown = \business_chain_markdown(['p0_execution_plan' => $plan]);
        self::assertStringContainsString('date_identity: `blocked`', $markdown);
        self::assertStringContainsString('requested=`2026-07-27`', $markdown);
        self::assertStringContainsString('verifier=`2026-07-28`', $markdown);
        self::assertStringNotContainsString('- already_ready ', $markdown);
    }

    public function testWrongVerifierDateStaysBlockedInTheUnifiedSourceContract(): void
    {
        $plan = $this->plan('2026-07-27');
        $plan['scope']['system_hotel_identity'] = ['status' => 'ready', 'system_hotel_name' => 'test-only-hotel'];
        $rows = \business_chain_attach_source_date_quality([[
            'source' => 'meituan', 'target_date' => '2026-07-28', 'target_status' => 'ready',
        ]], $plan);
        $contract = $rows[0]['source_date_quality'];
        self::assertFalse($contract['claim_allowed']);
        self::assertSame('blocked', $contract['status']);
        self::assertContains('verifier_business_date_mismatch', $contract['quality_flags']);
        self::assertSame('', $contract['next_action']['entry']);
    }

    public function testMarkdownShowsBothDatesAndTheActualRecoveryAction(): void
    {
        $markdown = \business_chain_markdown([
            'status' => 'blocked',
            'scope' => ['target_date' => '2026-07-28'],
            'p0_execution_plan' => $this->plan('2026-07-27'),
        ]);
        self::assertStringContainsString('date_identity: `blocked`', $markdown);
        self::assertStringContainsString('requested=`2026-07-28`', $markdown);
        self::assertStringContainsString('verifier=`2026-07-27`', $markdown);
        self::assertStringContainsString('verifier_business_date_mismatch', $markdown);
        self::assertStringContainsString('--date=2026-07-28', $markdown);
        self::assertStringContainsString('日期身份未通过', $markdown);
        self::assertStringNotContainsString('/test-only-login', $markdown);
        self::assertStringNotContainsString('/test-only-sync', $markdown);
    }

    public function testOldRenderedPlanCannotKeepReadyOrOperatingLinksWithoutDateProof(): void
    {
        $plan = $this->plan('2026-07-28');
        unset($plan['scope']['date_identity']);
        $plan['completion_gate']['selected_scope_status'] = 'ready';
        $plan['operator_sequence'] = [['type' => 'manual_login', 'entry' => '/test-only-legacy-login']];
        $markdown = \business_chain_markdown(['p0_execution_plan' => $plan]);
        self::assertStringContainsString('selected_scope_status: `blocked`', $markdown);
        self::assertStringContainsString('verifier_business_date_missing', $markdown);
        self::assertStringNotContainsString('/test-only-legacy-login', $markdown);
    }

    private function plan(mixed $date): array
    {
        return \business_chain_compact_p0_execution_plan($this->payload($date), '2026-07-28', 80, 0, [], ['meituan']);
    }

    private function payload(mixed $date): array
    {
        return [
            'status' => 'passed',
            'scope' => ['date' => $date, 'system_hotel_id' => 80, 'hotel_scope_policy' => 'system_hotel_id'],
            'platforms' => [[
                'platform' => 'meituan', 'target_date_rows' => 1, 'field_fact_status' => 'ready',
                'p0_traffic_gate' => [
                    'status' => 'ready', 'traffic_rows' => 1, 'action_status' => 'ready',
                    'stored_target_date_traffic_rows' => 1,
                    'traffic_field_fact_status' => 'ready', 'p0_standard_fact_status' => 'ready',
                    'required_metric_value_status' => 'ready', 'platform_hotel_identifier_status' => 'ready', 'readback_check_supported' => true,
                    'readback_verified_rows' => 1, 'readback_unverified_rows' => 0, 'readback_status' => 'ready',
                    'action_entry' => '/api/online-data/capture-meituan-browser',
                    'system_hotel_row_counts' => ['80' => 1],
                    'profile_scope_system_hotel_ids' => [80],
                    'hotel_scoped_next_steps' => [[
                        'system_hotel_id' => 80, 'data_source_id' => 50,
                        'p0_verifier_command' => 'test-only-observed-date-verifier',
                        'profile_login_trigger' => ['entry' => '/test-only-login',
                            'after_login_sync' => ['entry' => '/test-only-sync']],
                    ]],
                ],
            ]],
        ];
    }
}
