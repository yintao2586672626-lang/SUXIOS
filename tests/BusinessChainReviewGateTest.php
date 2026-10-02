<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class BusinessChainReviewGateTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once getenv('SUXI_REPORT_TEST_SOURCE') ?: __DIR__ . '/../scripts/report_business_chain_status.php';
    }

    public function testEmptyDiagnosisCannotApproveAnEmptyReviewPacket(): void
    {
        $workflow = \business_chain_downstream_reference_workflow([], [], false, [], false);
        $this->assertBlocked($workflow['revenue_to_ai_handoff']['manual_review_packet']);
        self::assertSame(0, $workflow['revenue_to_ai_handoff']['ai_action_count']);
    }

    public function testRejectedDateHotelOrSourceCannotReenterTheReviewPacket(): void
    {
        foreach (['business_date' => '2026-07-27', 'system_hotel_id' => 81, 'source_channels' => ['meituan']] as $key => $value) {
            $diagnosis = $this->diagnosis();
            $diagnosis[$key] = $value;
            $handoff = \business_chain_revenue_to_ai_handoff($this->scope(), $diagnosis, $this->draft(), true);
            self::assertSame('handoff_blocked', $handoff['status']);
            $this->assertBlocked($handoff['manual_review_packet']);
            self::assertSame([], $handoff['manual_review_packet']['revenue_metrics']);
            self::assertSame('', $handoff['manual_review_packet']['primary_action']['key']);
        }
    }

    public function testFailedOrEmptyDiagnosisAndMissingActionRemainBlocked(): void
    {
        foreach (['blocked', 'empty', 'failed', 'error', 'unknown', ''] as $status) {
            $diagnosis = $this->diagnosis();
            $diagnosis['status'] = $status;
            $handoff = \business_chain_revenue_to_ai_handoff($this->scope(), $diagnosis, $this->draft(), true);
            $this->assertBlocked($handoff['manual_review_packet']);
        }
        $draft = $this->draft();
        $draft['actions'] = [];
        $handoff = \business_chain_revenue_to_ai_handoff($this->scope(), $this->diagnosis(), $draft, true);
        $this->assertBlocked($handoff['manual_review_packet']);
    }

    public function testP0BlockerReachesManualReviewEvenWithCompleteScopedEvidence(): void
    {
        $handoff = \business_chain_revenue_to_ai_handoff($this->scope(), $this->diagnosis(), $this->draft(), false);
        $this->assertBlocked($handoff['manual_review_packet']);
        self::assertContains('all_required_p0_platforms_ready', $handoff['required_before_execution']);
    }

    public function testActionReasonCannotLeaveTheApprovalContractOpen(): void
    {
        $draft = $this->draft();
        $draft['actions'][0]['reason'] = 'available_room_nights_missing';
        $handoff = \business_chain_revenue_to_ai_handoff($this->scope(), $this->diagnosis(), $draft, true);
        $this->assertBlocked($handoff['manual_review_packet']);
    }

    public function testCompleteScopedEvidenceMayBeReviewedButNeverAutoExecutes(): void
    {
        $handoff = \business_chain_revenue_to_ai_handoff($this->scope(), $this->diagnosis(), $this->draft(), true);
        $packet = $handoff['manual_review_packet'];
        self::assertSame('ready_for_manual_review', $packet['status']);
        self::assertTrue($packet['ai_decision_review_contract']['approval_allowed']);
        self::assertFalse($packet['ai_decision_review_contract']['operation_intake_allowed']);
        self::assertFalse($handoff['can_create_operation_execution']);
        self::assertFalse($handoff['can_auto_write_ota']);
        self::assertSame(0, $packet['revenue_metrics'][0]['value']);
    }

    public function testP0PrecedesDiagnosisBlockerWhileBothStillCloseClaims(): void
    {
        $diagnosis = ['status' => 'blocked', 'source_channels' => []];
        $stages = \business_chain_stage_rows([], [], [], false, ['status' => 'blocked'], $diagnosis);
        self::assertSame('blocked_by_p0_ota_gate', $stages[0]['status']);
        self::assertFalse($stages[0]['claim_allowed']);
        $stages = \business_chain_stage_rows([], [], [], false, ['status' => 'ready'], $diagnosis);
        self::assertSame('blocked_by_diagnosis_scope', $stages[0]['status']);
        self::assertFalse($stages[0]['claim_allowed']);
    }

    public function testPendingHumanReviewReasonDoesNotBecomeAMissingEvidenceBlocker(): void
    {
        $draft = $this->draft();
        $draft['actions'][0]['status'] = 'pending_review';
        $draft['actions'][0]['reason'] = 'price_suggestions_pending_review';
        $packet = \business_chain_revenue_to_ai_handoff($this->scope(), $this->diagnosis(), $draft, true)['manual_review_packet'];
        self::assertSame('ready_for_manual_review', $packet['status']);
        self::assertTrue($packet['ai_decision_review_contract']['approval_allowed']);
        self::assertFalse($packet['ai_decision_review_contract']['operation_intake_allowed']);
    }

    private function assertBlocked(array $packet): void
    {
        self::assertSame('blocked_ready_for_manual_review', $packet['status']);
        $contract = $packet['ai_decision_review_contract'];
        self::assertFalse($contract['approval_allowed']);
        self::assertFalse($contract['operation_intake_allowed']);
        self::assertFalse($contract['auto_apply_ai_advice']);
        self::assertNotEmpty($contract['required_input_items']);
        self::assertFalse($contract['resolution_plan']['approval_allowed_after_resolution']);
        foreach ($contract['allowed_decision_outputs'] as $output) {
            if ($output['code'] === 'approve_ai_advice_for_operation_intake') self::assertFalse($output['allowed']);
        }
    }

    private function scope(): array
    {
        return ['contract_version' => 'ota-downstream-quality-v1', 'target_ready_platforms' => ['ctrip'],
            'target_blocked_platforms' => [], 'diagnosis_input' => ['mode' => 'target_date',
                'business_date' => '2026-07-28', 'requested_target_date' => '2026-07-28',
                'system_hotel_id' => 80, 'platforms' => ['ctrip']]];
    }

    private function diagnosis(): array
    {
        return ['status' => 'ok', 'business_date' => '2026-07-28', 'system_hotel_id' => 80,
            'source_channels' => ['ctrip'], 'metrics' => ['ota_room_revenue' =>
                ['key' => 'ota_room_revenue', 'value' => 0, 'status' => 'ok']]];
    }

    private function draft(): array
    {
        return ['status' => 'ready_for_manual_review', 'actions' => [
            ['key' => 'synthetic_manual_review', 'status' => 'ready', 'reason' => '',
                'blocking_reasons' => [], 'decision_basis_summary' => ['items' => []]]]];
    }
}
