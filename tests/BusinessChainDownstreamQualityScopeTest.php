<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class BusinessChainDownstreamQualityScopeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../scripts/report_business_chain_status.php';
    }

    public function testBlockedQualityContractCannotBeSelectedAsTargetDateFacts(): void
    {
        $scope = \business_chain_downstream_reference_scope([$this->row('meituan', false)], []);
        self::assertSame([], $scope['target_ready_platforms']);
        self::assertSame(['meituan'], $scope['target_blocked_platforms']);
        self::assertSame(['meituan'], $scope['reference_ready_platforms']);
        self::assertSame('latest_reference_ready', $scope['status']);
    }

    public function testAvailableSameScopeContractRemainsSelectable(): void
    {
        $scope = \business_chain_downstream_reference_scope([$this->row('meituan', true)], []);
        self::assertSame(['meituan'], $scope['target_ready_platforms']);
        self::assertSame([], $scope['target_blocked_platforms']);
        self::assertSame('target_date_ready', $scope['status']);
    }

    public function testOldRowWithoutQualityProofRemainsUnverified(): void
    {
        $row = $this->row('meituan', true);
        unset($row['source_date_quality']);
        $scope = \business_chain_downstream_reference_scope([$row], []);
        self::assertSame([], $scope['target_ready_platforms']);
        self::assertSame(['meituan'], $scope['target_blocked_platforms']);
    }

    public function testMixedChannelsSelectOnlyTheContractReadyChannel(): void
    {
        $scope = \business_chain_downstream_reference_scope([
            $this->row('ctrip', true), $this->row('meituan', false),
        ], []);
        self::assertSame(['ctrip'], $scope['target_ready_platforms']);
        self::assertSame(['meituan'], $scope['target_blocked_platforms']);
        self::assertSame('partial_target_date_ready', $scope['status']);
    }

    public function testInvalidQualityVerdictsNeverBecomeCurrentFacts(): void
    {
        foreach ([['status', 'partial'], ['status', 'failed'], ['quality_status', 'missing'],
            ['quality_status', 'unverified'], ['claim_allowed', 'true'], ['contract_version', 'unknown'],
            ['source', 'ctrip'], ['target_date', '2026-07-27'], ['system_hotel_id', 64],
            ['metric_scope', 'whole_hotel']] as [$key, $value]) {
            $row = $this->row('meituan', true);
            $row['source_date_quality'][$key] = $value;
            $scope = \business_chain_downstream_reference_scope([$row], []);
            self::assertSame([], $scope['target_ready_platforms'], $key . '=' . (string)$value);
            self::assertNotEmpty($scope['quality_blockers']['meituan']);
        }
    }

    public function testExpectedHotelDateAndPlatformsCannotBeReplacedByRows(): void
    {
        foreach ([['system_hotel_id', 64], ['target_date', '2026-07-27'], ['platforms', ['ctrip']]] as [$key, $value]) {
            $expected = ['target_date' => '2026-07-28', 'system_hotel_id' => 80, 'platforms' => ['meituan']];
            $expected[$key] = $value;
            $scope = \business_chain_downstream_reference_scope([$this->row('meituan', true)], [], $expected);
            self::assertSame([], $scope['target_ready_platforms']);
            self::assertNotEmpty($scope['target_blocked_platforms']);
        }
    }

    public function testSkipCannotPromoteCurrentRowsAndMissingRequestedChannelRemainsBlocked(): void
    {
        $scope = \business_chain_downstream_reference_scope([$this->row('meituan', true)], ['meituan'],
            ['target_date' => '2026-07-28', 'system_hotel_id' => 80, 'platforms' => ['ctrip', 'meituan']]);
        self::assertSame([], $scope['target_ready_platforms']);
        self::assertSame(['ctrip', 'meituan'], $scope['target_blocked_platforms']);
        self::assertSame(['meituan'], $scope['operator_skip_platforms']);
    }

    public function testReferenceMustHaveAnExplicitValidHistoricalDate(): void
    {
        foreach (['', '2026-07-28', '2026-07-29', '2026-02-30'] as $date) {
            $row = $this->row('meituan', false);
            $row['reference_date'] = $date;
            $scope = \business_chain_downstream_reference_scope([$row], []);
            self::assertSame([], $scope['reference_ready_platforms'], $date);
        }
    }

    public function testBlockedCurrentDatasetCannotReturnThroughTheAllChannelsFallback(): void
    {
        $scope = \business_chain_downstream_reference_scope([$this->row('meituan', false)], []);
        $input = \business_chain_diagnosis_input($scope, ['meituan' => $this->dataset('meituan')], [], false);
        self::assertSame('blocked', $input['scope']['mode']);
        self::assertSame([], $input['channel_datasets']);
        $revenue = $this->diagnose($input);
        self::assertSame([], $revenue['actual_source_channels']);
        self::assertNull($revenue['metrics']['ota_room_revenue']['value']);
        // Even an old consumer's display channels and draft must not restore a blocked source.
        $revenue['source_channels'] = ['meituan'];
        $revenue['actions'] = [['key' => 'unsafe_old_action', 'status' => 'ready']];
        $scope['diagnosis_input'] = $input['scope'];
        $workflow = \business_chain_downstream_reference_workflow($revenue, [], false, $scope, true);
        self::assertSame('blocked', $workflow['revenue_diagnosis']['status']);
        self::assertSame([], $workflow['revenue_diagnosis']['source_channels']);
        self::assertSame(0, $workflow['ai_advice_draft']['action_count']);
        self::assertSame('handoff_blocked', $workflow['revenue_to_ai_handoff']['status']);
    }

    public function testPartialDiagnosisUsesOnlyTheQualifiedCurrentChannel(): void
    {
        $scope = \business_chain_downstream_reference_scope([$this->row('ctrip', true), $this->row('meituan', false)], []);
        $input = \business_chain_diagnosis_input($scope, [
            'ctrip' => $this->dataset('ctrip'), 'meituan' => $this->dataset('meituan', '2026-07-28', 80, 900),
        ], [], false);
        self::assertSame(['ctrip'], array_keys($input['channel_datasets']));
        $revenue = $this->diagnose($input);
        self::assertSame(['ctrip'], $revenue['actual_source_channels']);
        self::assertEquals(100, $revenue['metrics']['ota_room_revenue']['value']);
        $scope['diagnosis_input'] = $input['scope'];
        $workflow = \business_chain_downstream_reference_workflow($revenue, [], false, $scope, false);
        self::assertSame(['ctrip'], $workflow['revenue_diagnosis']['source_channels']);
        self::assertSame('partial_reference_only', $workflow['revenue_diagnosis']['status']);
        self::assertSame('handoff_reference_only', $workflow['revenue_to_ai_handoff']['status']);
    }

    public function testHistoricalDiagnosisKeepsItsDateAndCannotBecomeCurrentReviewReady(): void
    {
        $scope = \business_chain_downstream_reference_scope([$this->row('meituan', false)], []);
        $input = \business_chain_diagnosis_input($scope, ['meituan' => $this->dataset('meituan')],
            ['meituan' => $this->dataset('meituan', '2026-07-27')], true);
        self::assertSame('historical_reference', $input['scope']['mode']);
        self::assertSame('2026-07-27', $input['context']['business_date']);
        $revenue = $this->diagnose($input);
        self::assertSame(['meituan'], $revenue['actual_source_channels']);
        self::assertEquals(100, $revenue['metrics']['ota_room_revenue']['value']);
        $scope['diagnosis_input'] = $input['scope'];
        $workflow = \business_chain_downstream_reference_workflow($revenue, [], true, $scope, true);
        self::assertSame('2026-07-28', $workflow['revenue_diagnosis']['requested_target_date']);
        self::assertSame('2026-07-27', $workflow['revenue_diagnosis']['business_date']);
        self::assertSame('draft_reference_only', $workflow['ai_advice_draft']['status']);
        self::assertSame('handoff_reference_only', $workflow['revenue_to_ai_handoff']['status']);
        self::assertFalse($workflow['revenue_to_ai_handoff']['can_create_operation_execution']);
    }

    public function testMixedHistoricalPeriodsAreNotSummedAsOneDate(): void
    {
        $ctrip = $this->row('ctrip', false);
        $ctrip['reference_date'] = '2026-07-26';
        $scope = \business_chain_downstream_reference_scope([$ctrip, $this->row('meituan', false)], []);
        $input = \business_chain_diagnosis_input($scope, [], [
            'ctrip' => $this->dataset('ctrip', '2026-07-26'), 'meituan' => $this->dataset('meituan', '2026-07-27'),
        ], true);
        self::assertSame('blocked', $input['scope']['mode']);
        self::assertSame('historical_reference_dates_differ', $input['scope']['reason']);
        self::assertSame([], $input['channel_datasets']);
    }

    public function testCurrentSourceHasToContainSameHotelDateAndPlatformFacts(): void
    {
        $scope = \business_chain_downstream_reference_scope([$this->row('meituan', true)], []);
        foreach ([$this->dataset('meituan', '2026-07-27'), $this->dataset('meituan', '2026-07-28', 64),
            $this->dataset('ctrip')] as $dataset) {
            $input = \business_chain_diagnosis_input($scope, ['meituan' => $dataset], [], false);
            $revenue = $this->diagnose($input);
            self::assertSame([], $revenue['actual_source_channels']);
            self::assertNull($revenue['metrics']['ota_room_revenue']['value']);
            $scope['diagnosis_input'] = $input['scope'];
            $workflow = \business_chain_downstream_reference_workflow($revenue, [], false, $scope, true);
            self::assertSame('handoff_blocked', $workflow['revenue_to_ai_handoff']['status']);
            $stages = \business_chain_stage_rows($input['dataset'], $revenue, [], false,
                ['status' => 'ready'], $workflow['revenue_diagnosis']);
            self::assertSame('blocked_by_diagnosis_scope', $stages[0]['status']);
            self::assertFalse($stages[0]['claim_allowed']);
        }
    }

    public function testLegacyWorkflowAndHandoffCannotTrustDisplayChannels(): void
    {
        $revenue = ['data_status' => 'ready', 'source_channels' => ['meituan'],
            'metrics' => ['ota_room_revenue' => ['value' => 100, 'status' => 'ready']],
            'actions' => [['key' => 'legacy', 'status' => 'ready']]];
        $workflow = \business_chain_downstream_reference_workflow($revenue, [], false, [], true);
        self::assertSame('blocked', $workflow['revenue_diagnosis']['status']);
        self::assertNull($workflow['revenue_diagnosis']['metrics']['ota_room_revenue']['value']);
        $handoff = \business_chain_revenue_to_ai_handoff([], ['source_channels' => ['meituan']],
            ['status' => 'ready_for_manual_review'], true);
        self::assertSame('handoff_blocked', $handoff['status']);
        self::assertSame([], $handoff['source_platforms']);
    }

    public function testMissingDatasetDoesNotInventAnActualSource(): void
    {
        $scope = \business_chain_downstream_reference_scope([$this->row('meituan', true)], []);
        $input = \business_chain_diagnosis_input($scope, [], [], false);
        $revenue = $this->diagnose($input);
        self::assertSame([], $revenue['actual_source_channels']);
        self::assertNull($revenue['metrics']['ota_room_revenue']['value']);
        $scope['diagnosis_input'] = $input['scope'];
        $workflow = \business_chain_downstream_reference_workflow($revenue, [], false, $scope, true);
        self::assertSame('blocked', $workflow['revenue_diagnosis']['status']);
        self::assertSame('blocked', $workflow['revenue_diagnosis']['metrics']['ota_room_revenue']['status']);
    }

    public function testValidCurrentFactsCanReachManualReviewButRemainUnauthorizedForExecution(): void
    {
        $scope = \business_chain_downstream_reference_scope([$this->row('meituan', true)], []);
        $input = \business_chain_diagnosis_input($scope, ['meituan' => $this->dataset('meituan')], [], false);
        $scope['diagnosis_input'] = $input['scope'];
        $workflow = \business_chain_downstream_reference_workflow($this->diagnose($input), [], false, $scope, true);
        self::assertSame(['meituan'], $workflow['revenue_diagnosis']['source_channels']);
        self::assertSame('ready_for_manual_review', $workflow['ai_advice_draft']['status']);
        self::assertSame('handoff_ready_for_manual_review', $workflow['revenue_to_ai_handoff']['status']);
        self::assertFalse($workflow['revenue_to_ai_handoff']['can_auto_write_ota']);
        self::assertFalse($workflow['revenue_to_ai_handoff']['can_create_operation_execution']);
    }

    public function testHandoffRejectsCachedDiagnosisForAnotherDateOrHotel(): void
    {
        $scope = \business_chain_downstream_reference_scope([$this->row('meituan', true)], []);
        $input = \business_chain_diagnosis_input($scope, ['meituan' => $this->dataset('meituan')], [], false);
        $scope['diagnosis_input'] = $input['scope'];
        $workflow = \business_chain_downstream_reference_workflow($this->diagnose($input), [], false, $scope, true);
        foreach ([['business_date', '2026-07-27'], ['system_hotel_id', 64]] as [$key, $value]) {
            $diagnosis = $workflow['revenue_diagnosis'];
            $diagnosis[$key] = $value;
            $handoff = \business_chain_revenue_to_ai_handoff($scope, $diagnosis, $workflow['ai_advice_draft'], true);
            self::assertSame('handoff_blocked', $handoff['status']);
            self::assertSame([], $handoff['source_platforms']);
        }
    }

    public function testConflictingDuplicateEvidenceCannotReplaceABlockedChannel(): void
    {
        $scope = \business_chain_downstream_reference_scope([$this->row('meituan', false), $this->row('meituan', true)], []);
        self::assertSame([], $scope['target_ready_platforms']);
        self::assertSame(['meituan'], $scope['target_blocked_platforms']);
    }

    public function testFailedOrUnverifiedSelectedDatasetCannotSupplyCurrentFacts(): void
    {
        $scope = \business_chain_downstream_reference_scope([$this->row('meituan', true)], []);
        foreach (['failed', 'error', 'unverified'] as $status) {
            $dataset = $this->dataset('meituan');
            $dataset['status'] = $status;
            $input = \business_chain_diagnosis_input($scope, ['meituan' => $dataset], [], false);
            $revenue = $this->diagnose($input);
            self::assertSame([], $revenue['actual_source_channels']);
            self::assertNull($revenue['metrics']['ota_room_revenue']['value']);
            self::assertSame($status, $input['scope']['dataset_issues']['meituan']);
        }
    }

    public function testSelectedHotelReadyContractMayRetainNonblockingGlobalQualityFlags(): void
    {
        $row = $this->row('meituan', true);
        $row['source_date_quality']['quality_flags'] = ['profile_scope_incomplete'];
        $scope = \business_chain_downstream_reference_scope([$row], []);
        self::assertSame(['meituan'], $scope['target_ready_platforms']);
        self::assertSame([], $scope['target_blocked_platforms']);
    }

    private function diagnose(array $input): array
    {
        return \business_chain_build_revenue_diagnosis($input);
    }

    private function dataset(string $platform, string $date = '2026-07-28', int $hotelId = 80, float $revenue = 100): array
    {
        return ['status' => 'ready', 'dim_hotel' => [['hotel_key' => 'system:' . $hotelId]],
            'dim_platform' => [['platform_key' => $platform]], 'fact_ota_daily' => [[
                'date_key' => $date, 'hotel_key' => 'system:' . $hotelId, 'platform_key' => $platform,
                'data_type' => 'business', 'metric_scope' => 'ota_channel',
                'calculation_basis' => 'ota_daily_standard_fact', 'room_revenue' => $revenue,
                'revenue' => $revenue, 'gross_revenue' => $revenue, 'net_revenue' => $revenue,
                'room_nights' => 1, 'available_room_nights' => 10, 'order_count' => 1,
            ]], 'data_quality' => ['input_rows' => 1, 'accepted_rows' => 1, 'rejected_rows' => []]];
    }

    private function row(string $source, bool $claimAllowed): array
    {
        return [
            'source' => $source, 'target_date' => '2026-07-28', 'target_status' => 'ready', 'system_hotel_id' => 80,
            'reference_date' => '2026-07-27', 'reference_status' => 'ready', 'reference_only' => true,
            'source_date_quality' => [
                'contract_version' => 'ota-source-date-quality-v1', 'source' => $source,
                'target_date' => '2026-07-28', 'system_hotel_id' => 80, 'metric_scope' => 'ota_channel',
                'status' => $claimAllowed ? 'ready' : 'blocked',
                'quality_status' => $claimAllowed ? 'available' : 'unverified',
                'claim_allowed' => $claimAllowed,
                'quality_flags' => $claimAllowed ? [] : ['readback_coverage_incomplete'],
            ],
        ];
    }
}
