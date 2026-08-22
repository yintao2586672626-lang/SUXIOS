<?php
declare(strict_types=1);

namespace Tests;

use app\service\AiGuestAcquisitionRadarService;
use PHPUnit\Framework\TestCase;

final class AiGuestAcquisitionRadarServiceTest extends TestCase
{
    public function testCompleteRepeatedObservationsAreMeasuredAcrossAllFourGates(): void
    {
        $publicMethods = array_values(array_map(
            static fn(\ReflectionMethod $method): string => $method->getName(),
            array_filter(
                (new \ReflectionClass(AiGuestAcquisitionRadarService::class))
                    ->getMethods(\ReflectionMethod::IS_PUBLIC),
                static fn(\ReflectionMethod $method): bool =>
                    $method->getDeclaringClass()->getName() === AiGuestAcquisitionRadarService::class
            )
        ));
        $result = (new AiGuestAcquisitionRadarService())->evaluate(
            $this->input($this->repeatedObservations())
        );

        self::assertSame(['evaluate'], $publicMethods);
        self::assertSame('ai_guest_acquisition_radar.v2', $result['contract_version']);
        self::assertSame('measured', $result['status']);
        self::assertSame(3, $result['summary']['eligible_observation_count']);
        self::assertSame([], $result['missing_evidence']);
        self::assertSame([], $result['repairable_fact_gaps']);

        foreach (['hotel_identified', 'facts_correct', 'matched', 'bookable_handoff'] as $gate) {
            self::assertSame(3, $result['gate_pass_rates'][$gate]['eligible_count']);
            self::assertSame(3, $result['gate_pass_rates'][$gate]['passed_count']);
            self::assertSame(100.0, $result['gate_pass_rates'][$gate]['pass_rate_percent']);
        }

        self::assertSame('sufficient', $result['repeatability']['status']);
        self::assertSame(3, $result['repeatability']['groups'][0]['distinct_repeat_count']);
        self::assertSame(3, $result['repeatability']['groups'][0]['distinct_observed_at_count']);
        self::assertSame(3, $result['repeatability']['groups'][0]['distinct_evidence_ref_count']);
        self::assertSame('consistent', $result['repeatability']['groups'][0]['consistency_status']);
        self::assertSame(100.0, $result['repeatability']['groups'][0]['outcome_consistency_rate_percent']);
        self::assertFalse($result['evidence_boundary']['network_collection_performed']);
        self::assertFalse($result['evidence_boundary']['promotional_content_generated']);
        self::assertFalse($result['evidence_boundary']['single_model_response_is_market_fact']);
    }

    public function testHotelIdentificationFailureStopsTheDownstreamFunnel(): void
    {
        $result = (new AiGuestAcquisitionRadarService())->evaluate(
            $this->input($this->repeatedObservations([
                'hotel_identified' => false,
                'facts_checked' => false,
                'facts_correct' => false,
                'matched' => false,
                'bookable_handoff' => false,
            ]))
        );

        self::assertSame('measured', $result['status']);
        self::assertSame(0.0, $result['gate_pass_rates']['hotel_identified']['pass_rate_percent']);
        self::assertSame(0, $result['gate_pass_rates']['facts_correct']['eligible_count']);
        self::assertNull($result['gate_pass_rates']['facts_correct']['pass_rate_percent']);
        self::assertSame(
            ['hotel_not_identified'],
            array_column($result['failure_points_by_intent'][0]['failure_points'], 'code')
        );
        self::assertSame([], $result['repairable_fact_gaps']);
    }

    public function testIncorrectFactsAreExposedAsRepairableFactGaps(): void
    {
        $result = (new AiGuestAcquisitionRadarService())->evaluate(
            $this->input($this->repeatedObservations([
                'facts_correct' => false,
                'matched' => false,
                'bookable_handoff' => false,
            ]))
        );

        self::assertSame('measured', $result['status']);
        self::assertSame(0.0, $result['gate_pass_rates']['facts_correct']['pass_rate_percent']);
        self::assertSame(0, $result['gate_pass_rates']['matched']['eligible_count']);
        self::assertSame(
            ['facts_incorrect'],
            array_column($result['failure_points_by_intent'][0]['failure_points'], 'code')
        );
        self::assertSame('facts_incorrect', $result['repairable_fact_gaps'][0]['code']);
        self::assertSame([1, 2, 3], $result['repairable_fact_gaps'][0]['affected_repeat_nos']);
        self::assertSame(3, $result['repairable_fact_gaps'][0]['observation_count']);
    }

    public function testSingleObservationIsInsufficientForRepeatability(): void
    {
        $result = (new AiGuestAcquisitionRadarService())->evaluate(
            $this->input([$this->observation(1)])
        );

        self::assertSame('insufficient_repeatability', $result['status']);
        self::assertSame('insufficient', $result['repeatability']['status']);
        self::assertSame(1, $result['repeatability']['groups'][0]['distinct_repeat_count']);
        self::assertFalse($result['repeatability']['groups'][0]['minimum_repeat_count_met']);
        self::assertSame('not_measurable', $result['repeatability']['groups'][0]['consistency_status']);
        self::assertNull($result['repeatability']['groups'][0]['outcome_consistency_rate_percent']);
        self::assertFalse($result['evidence_boundary']['market_fact_claimed']);
        self::assertFalse($result['evidence_boundary']['generalization_allowed']);
    }

    public function testMissingEvidenceReferenceBlocksTheEvaluation(): void
    {
        $observations = $this->repeatedObservations();
        unset($observations[1]['evidence_ref']);

        $result = (new AiGuestAcquisitionRadarService())->evaluate([
            'business_date' => '2026-08-22',
            'observations' => $observations,
        ]);

        self::assertSame('blocked_by_missing_evidence', $result['status']);
        self::assertSame(3, $result['summary']['received_observation_count']);
        self::assertSame(2, $result['summary']['eligible_observation_count']);
        self::assertSame(1, $result['summary']['blocked_observation_count']);
        self::assertContains('missing_evidence_ref', array_column($result['missing_evidence'], 'code'));
        self::assertSame(2, $result['missing_evidence'][0]['observation_no']);
        self::assertTrue($result['evidence_boundary']['calculation_only']);
        self::assertFalse($result['evidence_boundary']['promotional_content_published']);
    }

    public function testCopiedEvidenceCannotPassRepeatabilityByChangingOnlyRepeatNumber(): void
    {
        $observations = $this->repeatedObservations([
            'observed_at' => '2026-08-22T09:00:00+08:00',
            'evidence_ref' => 'fixture://ai-guest-radar/copied-once',
        ]);

        $result = (new AiGuestAcquisitionRadarService())->evaluate($this->input($observations));

        self::assertSame('insufficient_repeatability', $result['status']);
        self::assertSame(3, $result['repeatability']['groups'][0]['distinct_repeat_count']);
        self::assertSame(1, $result['repeatability']['groups'][0]['distinct_observed_at_count']);
        self::assertSame(1, $result['repeatability']['groups'][0]['distinct_evidence_ref_count']);
        self::assertFalse($result['repeatability']['groups'][0]['minimum_repeat_count_met']);
    }

    public function testObservationOutsideBusinessDateIsBlocked(): void
    {
        $result = (new AiGuestAcquisitionRadarService())->evaluate($this->input(
            $this->repeatedObservations(['observed_at' => '2025-01-01T09:00:00+08:00'])
        ));

        self::assertSame('blocked_by_missing_evidence', $result['status']);
        self::assertContains(
            'observed_at_business_date_mismatch',
            array_column($result['missing_evidence'], 'code')
        );
    }

    public function testUnknownSourceQualityIsNeverTrusted(): void
    {
        $result = (new AiGuestAcquisitionRadarService())->evaluate($this->input(
            $this->repeatedObservations(['source_quality' => 'totally_made_up_quality'])
        ));

        self::assertSame('blocked_by_missing_evidence', $result['status']);
        self::assertContains('untrusted_source_quality', array_column($result['missing_evidence'], 'code'));
    }

    public function testCalculatorInputBudgetAcceptsOneHundredObservationsAndRejectsOneHundredOne(): void
    {
        $service = new AiGuestAcquisitionRadarService();
        $observations = array_fill(0, 100, $this->observation(1));
        self::assertSame(100, $service->evaluate($this->input($observations))['summary']['received_observation_count']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('经营机会观察记录不能超过100条');
        $observations[] = $this->observation(2);
        $service->evaluate($this->input($observations));
    }

    public function testCalculatorInputBudgetRejectsReferenceAndTextOverflow(): void
    {
        $service = new AiGuestAcquisitionRadarService();
        try {
            $service->evaluate($this->input([$this->observation(1)]) + [
                'source_references' => array_map(static fn(int $index): string => 'ref#' . $index, range(1, 51)),
            ]);
            self::fail('51 refs must be rejected');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('经营机会来源引用不能超过50条', $error->getMessage());
        }

        $observation = $this->observation(1, ['intent' => str_repeat('意', 1001)]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('经营机会单条文本不能超过1000字符');
        $service->evaluate($this->input([$observation]));
    }

    /** @param array<int, array<string, mixed>> $observations */
    private function input(array $observations): array
    {
        return ['business_date' => '2026-08-22', 'observations' => $observations];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function repeatedObservations(array $overrides = []): array
    {
        return [
            $this->observation(1, $overrides),
            $this->observation(2, $overrides),
            $this->observation(3, $overrides),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function observation(int $repeatNo, array $overrides = []): array
    {
        return array_replace([
            'intent' => '上海外滩亲子酒店推荐',
            'model' => 'fixture-model',
            'region' => '上海',
            'observed_at' => sprintf('2026-08-22T09:0%d:00+08:00', $repeatNo),
            'repeat_no' => $repeatNo,
            'hotel_identified' => true,
            'facts_checked' => true,
            'facts_correct' => true,
            'matched' => true,
            'bookable_handoff' => true,
            'source_quality' => 'verified',
            'evidence_ref' => 'fixture://ai-guest-radar/repeat-' . $repeatNo,
        ], $overrides);
    }
}
