<?php
declare(strict_types=1);

namespace Tests;

use app\service\BookabilityGapService;
use PHPUnit\Framework\TestCase;

final class BookabilityGapServiceTest extends TestCase
{
    public function testCompleteGuestJourneyAlignsWithPositivePmsAvailability(): void
    {
        $result = (new BookabilityGapService())->evaluate($this->input([
            $this->observation('standard', 2, 0, [], 'available', 'visible', 'reachable'),
        ]));

        self::assertTrue($result['aligned']);
        self::assertFalse($result['gap_detected']);
        self::assertFalse($result['blocked_by_missing_evidence']);
        self::assertNull($result['earliest_failure_stage']);
        self::assertSame([], $result['affected_conditions']);
        self::assertNull($result['potential_loss']);
        self::assertSame([], $result['retest_requirements']);
        self::assertStringContainsString('PMS或后台成功不构成客人可订证据', $result['source_boundary']);
        self::assertSame(BookabilityGapService::CONTRACT_VERSION, $result['contract_version']);
    }

    public function testVerifiedJourneyBreakReportsEarliestStageAndCapsPotentialLoss(): void
    {
        $input = $this->input([
            $this->observation('breakfast', 2, 0, ['breakfast'], 'found', 'unavailable', 'not_reached'),
        ]);
        $input['pms_expected_sellable'] = 5;
        $input['real_demand_estimate'] = 8;

        $result = (new BookabilityGapService())->evaluate($input);

        self::assertFalse($result['aligned']);
        self::assertTrue($result['gap_detected']);
        self::assertFalse($result['blocked_by_missing_evidence']);
        self::assertSame('detail', $result['earliest_failure_stage']);
        self::assertCount(1, $result['affected_conditions']);
        self::assertSame('breakfast', $result['affected_conditions'][0]['condition_id']);
        self::assertSame('pms_sellable_guest_blocked', $result['affected_conditions'][0]['mismatch_type']);
        self::assertSame(5, $result['potential_loss']);
        self::assertSame('room_nights', $result['potential_loss_unit']);
        self::assertSame('calculated', $result['potential_loss_basis']['status']);
        self::assertNotEmpty($result['retest_requirements']);
    }

    public function testMissingJourneyEvidenceBlocksConclusion(): void
    {
        $observation = $this->observation(
            'standard',
            2,
            0,
            [],
            'available',
            'visible',
            'reachable'
        );
        unset($observation['pre_checkout'], $observation['evidence_ref']);
        $observation['source_quality'] = 'unverified';
        $observation['observed_at'] = '2026-02-30T10:30:00+08:00';

        $result = (new BookabilityGapService())->evaluate($this->input([$observation]));

        self::assertFalse($result['aligned']);
        self::assertFalse($result['gap_detected']);
        self::assertTrue($result['blocked_by_missing_evidence']);
        self::assertNull($result['earliest_failure_stage']);
        self::assertNull($result['potential_loss']);
        self::assertContains('source_quality_not_verified', array_column($result['missing_evidence'], 'code'));
        self::assertContains('evidence_ref_missing', array_column($result['missing_evidence'], 'code'));
        self::assertContains('observed_at_invalid', array_column($result['missing_evidence'], 'code'));
        self::assertContains('stage_status_missing_or_unknown', array_column($result['missing_evidence'], 'code'));
        self::assertNotEmpty($result['retest_requirements']);
    }

    public function testDifferentGuestConditionsRemainIndependent(): void
    {
        $input = $this->input([
            $this->observation('two_adults', 2, 0, [], 'found', 'visible', 'reachable'),
            $this->observation('family', 2, [8], ['breakfast'], 'found', 'visible', 'blocked'),
        ]);
        $input['real_demand_estimate'] = 4;

        $result = (new BookabilityGapService())->evaluate($input);

        self::assertTrue($result['gap_detected']);
        self::assertFalse($result['blocked_by_missing_evidence']);
        self::assertSame('pre_checkout', $result['earliest_failure_stage']);
        self::assertSame(['family'], array_column($result['affected_conditions'], 'condition_id'));
        self::assertSame(1, $result['affected_conditions'][0]['children']);
        self::assertSame([8], $result['affected_conditions'][0]['child_ages']);
        self::assertNull($result['potential_loss']);
        self::assertSame(
            'demand_scope_not_limited_to_affected_conditions',
            $result['potential_loss_basis']['reason']
        );
    }

    public function testPotentialLossIsNotInventedWithoutRealDemandEstimate(): void
    {
        $result = (new BookabilityGapService())->evaluate($this->input([
            $this->observation('standard', 2, 0, [], 'unavailable', 'not_reached', 'not_reached'),
        ]));

        self::assertTrue($result['gap_detected']);
        self::assertSame('search', $result['earliest_failure_stage']);
        self::assertNull($result['potential_loss']);
        self::assertNull($result['potential_loss_unit']);
        self::assertSame('real_demand_estimate_missing', $result['potential_loss_basis']['reason']);
    }

    public function testBackendSuccessIsNotAcceptedAsGuestBookabilityEvidence(): void
    {
        $result = (new BookabilityGapService())->evaluate($this->input([
            $this->observation(
                'backend_only',
                2,
                0,
                [],
                'backend_success',
                'backend_success',
                'backend_success'
            ),
        ]));

        self::assertFalse($result['aligned']);
        self::assertFalse($result['gap_detected']);
        self::assertTrue($result['blocked_by_missing_evidence']);
        self::assertSame(
            'stage_status_missing_or_unknown',
            $result['missing_evidence'][0]['code']
        );
        self::assertSame('search', $result['missing_evidence'][0]['field']);
    }

    public function testHtmlDatetimeLocalMinutePrecisionIsAccepted(): void
    {
        $observation = $this->observation('minute_precision', 2, 0, [], 'found', 'visible', 'reachable');
        $observation['observed_at'] = '2026-08-22T10:30';

        $result = (new BookabilityGapService())->evaluate($this->input([$observation]));

        self::assertFalse($result['blocked_by_missing_evidence']);
        self::assertTrue($result['aligned']);
    }

    public function testObservationOutsideBusinessDateIsBlocked(): void
    {
        $observation = $this->observation('historical', 2, 0, [], 'found', 'visible', 'reachable');
        $observation['observed_at'] = '2025-01-01T10:30:00+08:00';

        $result = (new BookabilityGapService())->evaluate($this->input([$observation]));

        self::assertTrue($result['blocked_by_missing_evidence']);
        self::assertContains(
            'observed_at_business_date_mismatch',
            array_column($result['missing_evidence'], 'code')
        );
    }

    public function testCalculatorInputBudgetAcceptsOneHundredObservationsAndRejectsOneHundredOne(): void
    {
        $service = new BookabilityGapService();
        $observations = array_map(
            fn(int $index): array => $this->observation(
                'condition_' . $index,
                2,
                0,
                [],
                'found',
                'visible',
                'reachable'
            ),
            range(1, 100)
        );
        self::assertFalse($service->evaluate($this->input($observations))['blocked_by_missing_evidence']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('经营机会观察记录不能超过100条');
        $observations[] = $this->observation('condition_101', 2, 0, [], 'found', 'visible', 'reachable');
        $service->evaluate($this->input($observations));
    }

    public function testCalculatorInputBudgetRejectsReferenceAndTextOverflow(): void
    {
        $service = new BookabilityGapService();
        try {
            $service->evaluate($this->input([
                $this->observation('text-limit', 2, 0, [], 'found', 'visible', 'reachable'),
            ]) + [
                'source_references' => array_map(static fn(int $index): string => 'ref#' . $index, range(1, 51)),
            ]);
            self::fail('51 refs must be rejected');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('经营机会来源引用不能超过50条', $error->getMessage());
        }

        $observation = $this->observation('text-limit', 2, 0, [], 'found', 'visible', 'reachable');
        $observation['evidence_ref'] = str_repeat('a', 1001);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('经营机会单条文本不能超过1000字符');
        $service->evaluate($this->input([$observation]));
    }

    /** @param array<int, array<string, mixed>> $observations */
    private function input(array $observations): array
    {
        return [
            'business_date' => '2026-08-22',
            'pms_expected_sellable' => 6,
            'platform' => 'ctrip',
            'observations' => $observations,
        ];
    }

    /**
     * @param int|array<int, int> $children
     * @param array<int, string> $benefits
     * @return array<string, mixed>
     */
    private function observation(
        string $conditionId,
        int $adults,
        int|array $children,
        array $benefits,
        string $search,
        string $detail,
        string $preCheckout
    ): array {
        return [
            'condition_id' => $conditionId,
            'adults' => $adults,
            'children' => $children,
            'benefits' => $benefits,
            'search' => $search,
            'detail' => $detail,
            'pre_checkout' => $preCheckout,
            'observed_at' => '2026-08-22T10:30:00+08:00',
            'source_quality' => 'verified',
            'evidence_ref' => 'guest_journey_fixture#' . $conditionId,
        ];
    }
}
