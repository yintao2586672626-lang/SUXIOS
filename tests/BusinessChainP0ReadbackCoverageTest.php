<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class BusinessChainP0ReadbackCoverageTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../scripts/report_business_chain_status.php';
    }

    public function testOneVerifiedRowCannotProveFiveStoredTrafficRows(): void
    {
        self::assertFalse(\business_chain_p0_execution_plan_ready($this->plan(5, 5, 1)));
    }

    public function testEveryStoredRowVerifiedRemainsReady(): void
    {
        self::assertTrue(\business_chain_p0_execution_plan_ready($this->plan(5, 5, 5)));
    }

    public function testExternalEvidenceSubsetUsesTheStoredReadbackPopulation(): void
    {
        // The verifier can publish one externally matched row while checking all five stored rows.
        self::assertTrue(\business_chain_p0_execution_plan_ready($this->plan(1, 5, 5)));
    }

    public function testOvercountAndPublishedRowsOutsideStoredPopulationAreBlocked(): void
    {
        self::assertFalse(\business_chain_p0_execution_plan_ready($this->plan(5, 5, 6)));
        self::assertFalse(\business_chain_p0_execution_plan_ready($this->plan(6, 5, 5)));
    }

    public function testMissingOldPopulationOrUnverifiedCountDoesNotBecomeZero(): void
    {
        $plan = $this->plan(5, 5, 5);
        unset($plan['platform_summaries'][0]['stored_target_date_traffic_rows']);
        self::assertFalse(\business_chain_p0_execution_plan_ready($plan));
        $markdown = \business_chain_markdown(['p0_execution_plan' => $plan]);
        self::assertStringContainsString('verify_target_date_readback_coverage', $markdown);
        self::assertStringContainsString('--system-hotel-id=80', $markdown);
        $plan = $this->plan(5, 5, 5, ['readback_unverified_rows' => null]);
        self::assertFalse(\business_chain_p0_execution_plan_ready($plan));
        self::assertNull($plan['platform_summaries'][0]['readback_unverified_rows']);
        self::assertContains('readback_unverified_rows_missing', $plan['platform_summaries'][0]['missing_inputs']);
    }

    public function testMalformedCountsAndUnsupportedReadbackCannotBeCastIntoProof(): void
    {
        foreach ([-1, 5.5, true, '5 rows', '5.0', [], ''] as $count) {
            self::assertFalse(\business_chain_p0_execution_plan_ready($this->plan(5, 5, 5,
                ['readback_verified_rows' => $count])), json_encode($count));
        }
        self::assertFalse(\business_chain_p0_execution_plan_ready($this->plan(5, 5, 5, ['readback_unverified_rows' => -1])));
        self::assertFalse(\business_chain_p0_execution_plan_ready($this->plan(5, 5, 5, ['readback_check_supported' => 'false'])));
        $plan = $this->plan(5, 5, 5);
        $plan['platform_summaries'][0]['readback_check_supported'] = 'false';
        self::assertFalse($this->sourceContract($plan)['evidence']['readback_check_supported']);
    }

    public function testCanonicalStringCountsAndCompleteMultiHotelPartialScopeRemainCompatible(): void
    {
        $plan = $this->plan(5, 5, 5, ['readback_verified_rows' => '5', 'readback_unverified_rows' => '0']);
        self::assertTrue(\business_chain_p0_execution_plan_ready($plan));
        $plan = $this->plan(5, 5, 5, [
            'status' => 'profile_scope_traffic_closure_incomplete',
            'profile_scope_system_hotel_ids' => [77, 80], 'system_hotel_row_counts' => ['77' => 2, '80' => 3],
            'profile_scope_missing_profile_source_hotel_ids' => [77],
        ], ['system_hotel_id' => null, 'hotel_scope_policy' => 'platform_date']);
        self::assertFalse($plan['platform_summaries'][0]['platform_ready']);
        self::assertTrue(\business_chain_p0_execution_plan_ready($plan));
    }

    public function testCoverageFailurePropagatesToSourceQualityAndRecovery(): void
    {
        $plan = $this->plan(5, 5, 1);
        self::assertFalse($plan['platform_summaries'][0]['selected_hotel_ready']);
        self::assertContains('verify_target_date_readback_coverage', array_column($plan['operator_sequence'], 'type'));
        $contract = $this->sourceContract($plan);
        self::assertFalse($contract['claim_allowed']);
        self::assertContains('readback_coverage_incomplete', $contract['quality_flags']);
        self::assertSame(5, $contract['evidence']['stored_target_date_traffic_rows']);
        self::assertSame(1, $contract['evidence']['readback_verified_rows']);
        self::assertSame('', $contract['next_action']['entry']);
        self::assertStringContainsString('--system-hotel-id=80', $contract['next_action']['readback_verification_command']);
    }

    public function testActualMarkdownShowsCoverageAndRejectsCachedReady(): void
    {
        $plan = $this->plan(5, 5, 5);
        $plan['platform_summaries'][0]['readback_verified_rows'] = 1;
        $plan['completion_gate']['selected_scope_status'] = 'ready';
        $markdown = \business_chain_markdown(['p0_execution_plan' => $plan]);
        self::assertStringContainsString('selected_scope_status: `blocked`', $markdown);
        self::assertStringContainsString('stored=`5`, verified=`1`', $markdown);
        self::assertStringContainsString('readback_coverage_incomplete', $markdown);
        self::assertStringNotContainsString('- already_ready ', $markdown);
    }

    public function testFailedStatusSkippedPlatformAndEmptyRowsRemainUnready(): void
    {
        $plan = $this->plan(5, 5, 5, ['readback_status' => 'failed']);
        self::assertFalse(\business_chain_p0_execution_plan_ready($plan));
        self::assertFalse($plan['platform_summaries'][0]['selected_hotel_ready']);
        $plan = $this->plan(5, 5, 5);
        $plan['platform_summaries'][0]['operator_skip_active'] = true;
        self::assertFalse(\business_chain_p0_execution_plan_ready($plan));
        $plan['status'] = 'failed';
        self::assertFalse($this->sourceContract($plan)['claim_allowed']);
        self::assertFalse(\business_chain_p0_execution_plan_ready($this->plan(0, 0, 0)));
    }

    public function testHotelRowCountsCannotExceedTheReadbackPopulation(): void
    {
        $plan = $this->plan(2, 2, 2);
        self::assertFalse(\business_chain_p0_execution_plan_ready($plan));
        self::assertFalse($plan['platform_summaries'][0]['selected_hotel_ready']);
        self::assertContains('hotel_row_counts_exceed_readback_population', $plan['platform_summaries'][0]['missing_inputs']);
        self::assertStringNotContainsString('- already_ready ', \business_chain_markdown(['p0_execution_plan' => $plan]));
    }

    private function sourceContract(array $plan): array
    {
        $plan['scope']['system_hotel_identity'] = ['status' => 'ready', 'system_hotel_id' => 80];
        return \business_chain_attach_source_date_quality([[
            'source' => 'meituan', 'target_date' => '2026-07-28', 'target_status' => 'ready',
        ]], $plan)[0]['source_date_quality'];
    }

    private function plan(int $trafficRows, int $storedRows, int $verifiedRows, array $gateOverrides = [], array $scopeOverrides = []): array
    {
        return \business_chain_compact_p0_execution_plan([
            'status' => 'passed',
            'scope' => array_replace(['date' => '2026-07-28', 'system_hotel_id' => 80, 'hotel_scope_policy' => 'system_hotel_id'], $scopeOverrides),
            'platforms' => [[
                'platform' => 'meituan', 'target_date_rows' => 5, 'field_fact_status' => 'ready',
                'p0_traffic_gate' => array_replace([
                    'status' => 'ready', 'traffic_rows' => $trafficRows,
                    'stored_target_date_traffic_rows' => $storedRows,
                    'readback_check_supported' => true, 'readback_status' => 'ready',
                    'readback_verified_rows' => $verifiedRows, 'readback_unverified_rows' => 0,
                    'profile_scope_system_hotel_ids' => [80], 'system_hotel_row_counts' => ['80' => 5],
                    'traffic_field_fact_status' => 'ready', 'p0_standard_fact_status' => 'ready',
                    'required_metric_value_status' => 'ready', 'platform_hotel_identifier_status' => 'ready',
                    'action_entry' => '/test-only-readback-capture',
                    'hotel_scoped_next_steps' => [[
                        'system_hotel_id' => 80, 'p0_verifier_command' => 'test-only-scope-verifier',
                    ]],
                ], $gateOverrides),
            ]],
        ], '2026-07-28', 80, 0, [], ['meituan']);
    }
}
