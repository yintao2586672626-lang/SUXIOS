<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class BusinessChainSourceDateQualityScopeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../scripts/report_business_chain_status.php';
    }

    public function testMatchingScopeRemainsAvailable(): void
    {
        $contract = $this->contract($this->row(), $this->plan());
        self::assertTrue($contract['claim_allowed']);
        self::assertSame('available', $contract['quality_status']);
    }

    public function testDifferentResultDateCannotBePromotedByReadyVerifier(): void
    {
        $row = $this->row();
        $row['target_date'] = '2026-07-27';
        $contract = $this->contract($row, $this->plan());
        self::assertFalse($contract['claim_allowed']);
        self::assertSame('blocked', $contract['status']);
        self::assertSame('unverified', $contract['quality_status']);
        self::assertSame('2026-07-28', $contract['target_date']);
        self::assertContains('business_date_identity_mismatch', $contract['quality_flags']);
        self::assertSame('', $contract['next_action']['entry']);
    }

    public function testUnrequestedPlatformCannotBePromotedByReadySummary(): void
    {
        $plan = $this->plan();
        $plan['scope']['platforms'] = ['ctrip'];
        $contract = $this->contract($this->row(), $plan);
        self::assertFalse($contract['claim_allowed']);
        self::assertSame('blocked', $contract['status']);
        self::assertContains('source_identity_mismatch', $contract['quality_flags']);
        self::assertSame('', $contract['next_action']['entry']);
    }

    public function testInvalidAndExplicitMissingDatesCannotInheritReady(): void
    {
        foreach (['', null, '2026-02-30', '2026-02-29'] as $date) {
            $plan = $this->plan();
            $plan['scope']['target_date'] = $date;
            $contract = $this->contract($this->row(), $plan);
            self::assertFalse($contract['claim_allowed']);
            self::assertContains('business_date_identity_invalid', $contract['quality_flags']);
        }
    }

    public function testLegacyOmittedScopeFieldsAndLeapDayRemainCompatible(): void
    {
        $plan = $this->plan();
        unset($plan['scope']['target_date'], $plan['scope']['platforms']);
        self::assertTrue($this->contract($this->row(), $plan)['claim_allowed']);

        $plan['scope']['target_date'] = '2024-02-29';
        $row = $this->row();
        $row['target_date'] = '2024-02-29';
        self::assertTrue($this->contract($row, $plan)['claim_allowed']);
    }

    public function testExplicitMissingObservedDateAndPlatformStayBlocked(): void
    {
        foreach (['', null] as $missing) {
            $row = $this->row();
            $row['target_date'] = $missing;
            self::assertContains('business_date_identity_invalid', $this->contract($row, $this->plan())['quality_flags']);

            $row = $this->row();
            $row['source'] = $missing;
            $contract = $this->contract($row, $this->plan());
            self::assertFalse($contract['claim_allowed']);
            self::assertContains('source_identity_invalid', $contract['quality_flags']);
        }
    }

    public function testExplicitEmptyPlatformScopeAndUnsupportedSourceAreBlocked(): void
    {
        foreach ([[], null, ['qunar']] as $platforms) {
            $plan = $this->plan();
            $plan['scope']['platforms'] = $platforms;
            self::assertFalse($this->contract($this->row(), $plan)['claim_allowed']);
        }
        $row = $this->row();
        $row['source'] = 'qunar';
        self::assertContains('source_identity_invalid', $this->contract($row, $this->plan())['quality_flags']);
    }

    public function testMatchingCtripDoesNotBypassUnreadyP0Evidence(): void
    {
        $row = $this->row();
        $row['source'] = 'ctrip';
        $plan = $this->plan();
        $plan['scope']['platforms'] = ['ctrip'];
        $plan['platform_summaries'][0]['platform'] = 'ctrip';
        self::assertTrue($this->contract($row, $plan)['claim_allowed']);

        $plan['platform_summaries'][0]['platform_ready'] = false;
        $plan['platform_summaries'][0]['hotel_scope_evidence']['required_metric_value_status'] = 'not_ready';
        self::assertFalse($this->contract($row, $plan)['claim_allowed']);
    }

    private function contract(array $row, array $plan): array
    {
        return \business_chain_attach_source_date_quality([$row], $plan)[0]['source_date_quality'];
    }

    private function row(): array
    {
        return ['source' => 'meituan', 'target_date' => '2026-07-28', 'target_status' => 'ready'];
    }

    private function plan(): array
    {
        return [
            'scope' => [
                'target_date' => '2026-07-28',
                'platforms' => ['meituan'],
                'system_hotel_id' => 80,
                'hotel_identity' => \business_chain_p0_hotel_identity(80, ['system_hotel_id' => 80, 'hotel_scope_policy' => 'system_hotel_id']),
                'system_hotel_identity' => [
                    'status' => 'ready', 'system_hotel_id' => 80, 'system_hotel_name' => 'test-only-hotel',
                ],
            ],
            'platform_summaries' => [[
                'platform' => 'meituan', 'platform_ready' => true,
                'selected_system_hotel_id' => 80, 'selected_hotel_ready' => true,
                'hotel_scope_evidence' => [
                    'profile_scope_system_hotel_ids' => [80], 'system_hotel_row_counts' => ['80' => 1],
                    'traffic_field_fact_status' => 'ready', 'p0_standard_fact_status' => 'ready',
                    'required_metric_value_status' => 'ready', 'platform_hotel_identifier_status' => 'ready',
                ],
                'target_date_rows' => 1, 'traffic_rows' => 1, 'field_fact_status' => 'ready',
                'stored_target_date_traffic_rows' => 1,
                'traffic_gate_status' => 'ready', 'readback_status' => 'ready',
                'action_entry' => '/api/online-data/capture-meituan-browser',
                'readback_check_supported' => true, 'readback_verified_rows' => 1,
                'readback_unverified_rows' => 0, 'missing_inputs' => [],
            ]],
        ];
    }
}
