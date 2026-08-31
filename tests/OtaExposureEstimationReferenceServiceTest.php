<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaExposureEstimationReferenceService;
use PHPUnit\Framework\TestCase;

final class OtaExposureEstimationReferenceServiceTest extends TestCase
{
    public function testSevenStrictPairsProduceEstimateOnlyWithoutChangingPlatformFact(): void
    {
        $closures = $this->closures(7);
        $result = (new OtaExposureEstimationReferenceService(
            static fn(int $hotelId, string $date): array => $closures[$date] ?? []
        ))->estimate(10, 80, 'meituan', '2026-08-15');

        self::assertSame('estimated', $result['status']);
        self::assertSame('derived_estimate', $result['evidence_type']);
        self::assertSame('estimate_only', $result['quality_status']);
        self::assertSame(7, $result['accepted_verified_pairs']);
        self::assertSame(1000, $result['estimate']['value']);
        self::assertSame('people', $result['estimate']['unit']);
        self::assertSame(10.0, $result['estimate']['median_multiplier']);
        self::assertNull($result['estimate']['interval']);
        self::assertFalse($result['decision_eligible']);
        self::assertFalse($result['writeback_allowed']);
        self::assertSame('unchanged', $result['platform_fact_status']);
        self::assertSame(0, $result['external_write_count']);
    }

    public function testSixPairsStayInsufficientWithoutDefaultMultiplier(): void
    {
        $closures = $this->closures(6);
        $result = (new OtaExposureEstimationReferenceService(
            static fn(int $hotelId, string $date): array => $closures[$date] ?? []
        ))->estimate(10, 80, 'meituan', '2026-08-15');

        self::assertSame('insufficient_baseline', $result['status']);
        self::assertSame(6, $result['accepted_verified_pairs']);
        self::assertNull($result['estimate']);
        self::assertSame('verified_pair_baseline_insufficient', $result['reason_code']);
        self::assertStringContainsString('没有套用默认倍数', $result['reason']);
    }

    public function testExposureCountUnitCannotMasqueradeAsExposureUsers(): void
    {
        $closures = $this->closures(7, exposureUnit: 'impressions');
        $result = (new OtaExposureEstimationReferenceService(
            static fn(int $hotelId, string $date): array => $closures[$date] ?? []
        ))->estimate(10, 80, 'meituan', '2026-08-15');

        self::assertSame('insufficient_baseline', $result['status']);
        self::assertSame(0, $result['accepted_verified_pairs']);
        self::assertNull($result['estimate']);
    }

    public function testExistingTargetExposureStopsReferenceEstimation(): void
    {
        $closures = $this->closures(7, targetExposure: true);
        $result = (new OtaExposureEstimationReferenceService(
            static fn(int $hotelId, string $date): array => $closures[$date] ?? []
        ))->estimate(10, 80, 'meituan', '2026-08-15');

        self::assertSame('fact_already_available', $result['status']);
        self::assertSame('target_exposure_already_available', $result['reason_code']);
        self::assertNull($result['estimate']);
        self::assertFalse($result['writeback_allowed']);
    }

    public function testPairMustShareOneSourceRecordAndScopeIdentity(): void
    {
        $closures = $this->closures(7, mismatchedPairRef: true);
        $result = (new OtaExposureEstimationReferenceService(
            static fn(int $hotelId, string $date): array => $closures[$date] ?? []
        ))->estimate(10, 80, 'meituan', '2026-08-15');

        self::assertSame('insufficient_baseline', $result['status']);
        self::assertSame(0, $result['accepted_verified_pairs']);
    }

    /** @return array<string,array<string,mixed>> */
    private function closures(
        int $pairCount,
        string $exposureUnit = 'people',
        bool $targetExposure = false,
        bool $mismatchedPairRef = false
    ): array {
        $closures = [];
        $targetDate = '2026-08-15';
        $closures[$targetDate] = $this->closure($targetDate, [
            $this->field('visits', 100, 'people', 'online_daily_data#900'),
            ...($targetExposure ? [$this->field('exposure', 1000, 'people', 'online_daily_data#900')] : []),
        ]);
        for ($offset = 1; $offset <= $pairCount; $offset++) {
            $date = (new \DateTimeImmutable($targetDate))->modify('-' . $offset . ' days')->format('Y-m-d');
            $visitsRef = 'online_daily_data#' . (900 + $offset);
            $exposureRef = $mismatchedPairRef ? 'online_daily_data#' . (1900 + $offset) : $visitsRef;
            $closures[$date] = $this->closure($date, [
                $this->field('visits', 100 + $offset, 'people', $visitsRef),
                $this->field('exposure', (100 + $offset) * 10, $exposureUnit, $exposureRef),
            ]);
        }
        return $closures;
    }

    /** @param list<array<string,mixed>> $fields @return array<string,mixed> */
    private function closure(string $date, array $fields): array
    {
        return [
            'contract_version' => 'dual_ota_field_closure.v1',
            'tenant_id' => 10,
            'hotel_id' => 80,
            'business_date' => $date,
            'consumer_contract' => ['contract_version' => 'trusted_ota_daily_fact_consumer.v1'],
            'platforms' => ['meituan' => ['fields' => $fields], 'ctrip' => ['fields' => []]],
        ];
    }

    /** @return array<string,mixed> */
    private function field(string $key, int $value, string $unit, string $ref): array
    {
        return [
            'key' => $key,
            'metric_key' => $key,
            'value' => $value,
            'unit' => $unit,
            'validation_status' => 'verified',
            'history_statuses' => ['success'],
            'readback_status' => 'readback_verified',
            'strict_final_gate' => true,
            'revenue_analysis_consumable' => true,
            'source_record_refs' => [$ref],
            'source_paths' => ['fixture.same_snapshot'],
            'cumulative_cutoff' => '23:00',
            'metric_definition_version' => 'fixture-exposure-users-detail-visitors.v1',
        ];
    }
}
