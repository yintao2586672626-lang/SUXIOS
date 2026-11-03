<?php
declare(strict_types=1);

use app\service\RevenueDecisionViewModelAttestationService;
use app\service\RevenueOverviewDateContract;
use PHPUnit\Framework\TestCase;

/** All inputs are local_synthetic_fixture_only; no database or operating writes. */
final class RevenueComparisonBaselineScopeTest extends TestCase
{
    private function overview(string $date): array
    {
        return [
            'hotel_id' => 64, 'business_date' => $date,
            'as_of_date' => RevenueOverviewDateContract::serverAsOfDate(),
            'as_of_date_contract_version' => RevenueOverviewDateContract::VERSION,
            'three_source_fact_layer' => ['business_date' => $date,
                'hotel' => ['tenant_id' => 64, 'system_hotel_id' => 64, 'name' => 'synthetic fixture'],
                'sources' => [], 'data_gaps' => []],
            'cockpit_strict_evidence' => ['contract_version' => 'revenue_cockpit_strict_evidence.v1',
                'tenant_id' => 64, 'hotel_id' => 64, 'business_date' => $date,
                'platform' => 'all_ota', 'platforms' => []],
        ];
    }

    private function comparisons(): array
    {
        return ['previous_date' => '2026-09-30', 'same_weekday_date' => '2026-09-24',
            'previous_overview' => $this->overview('2026-09-30'),
            'same_weekday_overview' => $this->overview('2026-09-24'),
            'scope_notice' => 'local_synthetic_fixture_only', 'selected_platform_label' => '双OTA'];
    }

    private function issue(array $comparisons): array
    {
        return (new RevenueDecisionViewModelAttestationService())->issue(
            $this->overview('2026-10-01'), $comparisons, [], 64, 64, '2026-10-01', 'all_ota');
    }

    public function testMatchingBaselinesSignAndAttestExactlyWithoutInventingMissingMetrics(): void
    {
        $comparisons = $this->comparisons();
        $model = $this->issue($comparisons);
        self::assertSame($model, (new RevenueDecisionViewModelAttestationService())->attest(
            $model, $this->overview('2026-10-01'), $comparisons, [], 64, 64, '2026-10-01', 'all_ota'));
        self::assertSame('2026-09-30', $model['previousDate']);
        self::assertSame('2026-09-24', $model['sameWeekdayDate']);
        foreach ($model['comparisonFrames'] as $frame) {
            self::assertTrue($frame['sameHotel']);
            self::assertTrue($frame['samePlatform']);
        }
    }

    public function testAbsentAndLegacyMissingBaselinesRemainCompatible(): void
    {
        foreach ([
            ['previous_date' => '', 'same_weekday_date' => '', 'previous_overview' => [], 'same_weekday_overview' => []],
            ['previous_overview' => [], 'same_weekday_overview' => []],
        ] as $missing) {
            $comparisons = array_replace($this->comparisons(), $missing);
            $model = $this->issue($comparisons);
            self::assertSame($model, (new RevenueDecisionViewModelAttestationService())->attest(
                $model, $this->overview('2026-10-01'), $comparisons, [], 64, 64, '2026-10-01', 'all_ota'));
        }
        $legacy = $this->comparisons();
        unset($legacy['previous_overview']['cockpit_strict_evidence']['platform']);
        unset($legacy['same_weekday_overview']['cockpit_strict_evidence']['platform']);
        self::assertSame('2026-09-30', $this->issue($legacy)['previousDate']);
    }

    public function testBothBaselinePathsRejectConflictingMetadataDuringIssuanceAndSaveAttestation(): void
    {
        foreach (['previous', 'same_weekday'] as $basis) {
            $good = $this->comparisons();
            $issued = $this->issue($good);
            foreach ([
                ['hotel_id' => 65],
                ['business_date' => '2026-09-29'],
                ['three_source_fact_layer' => ['hotel' => ['tenant_id' => 65]]],
                ['three_source_fact_layer' => ['hotel' => ['system_hotel_id' => 65]]],
                ['three_source_fact_layer' => ['business_date' => '2026-09-29']],
                ['cockpit_strict_evidence' => ['tenant_id' => 65]],
                ['cockpit_strict_evidence' => ['hotel_id' => 65]],
                ['cockpit_strict_evidence' => ['business_date' => '2026-09-29']],
                ['cockpit_strict_evidence' => ['platform' => 'ctrip']],
                ['cockpit_strict_evidence' => ['contract_version' => 'wrong']],
                ['as_of_date' => '2026-09-30'],
                ['as_of_date_contract_version' => 'wrong'],
            ] as $index => $conflict) {
                $bad = $good;
                $bad[$basis . '_overview'] = array_replace_recursive($bad[$basis . '_overview'], $conflict);
                foreach (['issue', 'attest'] as $method) {
                    try {
                        $service = new RevenueDecisionViewModelAttestationService();
                        if ($method === 'issue') $this->issue($bad);
                        else $service->attest($issued, $this->overview('2026-10-01'), $bad, [],
                            64, 64, '2026-10-01', 'all_ota');
                        self::fail("$basis $index $method must reject baseline identity drift");
                    } catch (InvalidArgumentException $error) {
                        self::assertSame('revenue_decision_snapshot_view_model_unattested:comparison_'
                            . $basis . '_identity', $error->getMessage());
                    }
                }
            }
        }
    }

    public function testNonEarlierInvalidAndWrongWeekdayDatesCannotBeSigned(): void
    {
        foreach ([['previous', '2026-10-01'], ['previous', '2026-10-02'],
            ['previous', '2026-02-30'], ['previous', ''], ['same_weekday', '2026-09-30'],
            ['same_weekday', '2026-10-08']] as [$basis, $date]) {
            $bad = $this->comparisons();
            $bad[$basis . '_date'] = $date;
            $bad[$basis . '_overview'] = $date !== '' ? $this->overview($date) : $bad[$basis . '_overview'];
            try {
                $this->issue($bad);
                self::fail("$basis $date must reject invalid comparison dates");
            } catch (InvalidArgumentException $error) {
                self::assertSame('revenue_decision_snapshot_view_model_unattested:comparison_'
                    . $basis . '_date', $error->getMessage());
            }
        }
    }
}
