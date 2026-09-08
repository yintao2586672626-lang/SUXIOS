<?php
declare(strict_types=1);
namespace Tests;

use app\service\QuantSimulationService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\QuantOperatingFixture as Fixture;

final class QuantOperatingScenarioTest extends TestCase
{
    public function testExistingHotelNaturalMonthAndCashflowGoldenValues(): void
    {
        [$input, $r] = Fixture::calculate(Fixture::input());
        self::assertSame(29.0, $input['weekdayDays']);
        self::assertSame(14500.0, $r['roomRevenue']);
        self::assertSame(6550.0, $r['monthlyNetCashflow']);
        $s = $r['operatingScenario'];
        self::assertSame([29,31,30,31,30,31,31,30,31,30,31,31], array_column(array_slice($s['cashflow_series'],1), 'days'));
        self::assertSame(30000.0, $s['funding_required']);
        self::assertSame(0.0, $s['additional_cash_gap']);
        self::assertSame(10000.0, $s['cashflow_series'][0]['cash_balance']);
    }

    public function testGoldenRentAndPaybackConstraints(): void
    {
        [, $r] = Fixture::calculate(Fixture::input());
        $s = $r['operatingScenario'];
        self::assertSame(11550.0, $s['monthly_rent_ceiling']);
        self::assertSame(96700.0, $s['ending_cash_balance']);
        // Feb..Jul: 182 days; 182*450 - 6*6500 - 30000 = 12900, cap=5000+12900/6.
        self::assertSame(7150.0, $s['target_rent_ceiling']);
        self::assertSame(4.22, $s['equity_payback']['months']);
        self::assertSame('met_under_assumptions', $s['target_status']);
    }

    public function testProposedInvestmentLoanRampAndFundingGap(): void
    {
        $input = Fixture::input('proposed_investment');
        $input['operatingScenario'] = array_replace($input['operatingScenario'], ['loan_amount'=>12000,'annual_interest_rate'=>12,'loan_term_months'=>12,'ramp_months'=>2,'ramp_start_occupancy'=>10,'opening_cash'=>18000]);
        [, $r] = Fixture::calculate($input);
        $s = $r['operatingScenario'];
        self::assertSame([10.0,30.0,50.0], array_column(array_slice($s['cashflow_series'],1,3),'occupancy_pct'));
        self::assertSame(-3890.0, $s['cashflow_series'][1]['project_cashflow']);
        self::assertSame(120.0, $s['cashflow_series'][1]['interest']);
        self::assertSame(1000.0, $s['cashflow_series'][1]['principal']);
        self::assertSame(-5010.0, $s['cashflow_series'][1]['equity_cashflow']);
        self::assertSame(5010.0, $s['additional_cash_gap']);
        self::assertSame(0.0, $s['outstanding_loan']);
        self::assertCount(8, $s['sensitivity']);
        self::assertSame('proposed_investment', $s['case_type']);
    }

    public function testOtaOnlyCannotBecomeWholeHotelGopOrVerifiedPayback(): void
    {
        $input = Fixture::input();
        $input['operatingScenario']['evidence_basis'] = 'ota_only';
        $input['operatingScenario']['whole_hotel_gop'] = 999999;
        $input['operatingScenario']['verified_facts'] = ['gop'=>999999];
        [, $r] = Fixture::calculate($input);
        self::assertNull($r['scenarioBoundary']['whole_hotel_gop']);
        self::assertNull($r['scenarioBoundary']['verified_payback_months']);
        self::assertFalse($r['scenarioBoundary']['can_use_for_investment_judgement']);
        self::assertSame([], $r['scenarioBoundary']['verified_facts']);
        self::assertContains('pms_whole_hotel_revenue_missing', $r['scenarioBoundary']['missing_evidence']);
        unset($input['laborCost']);
        $this->expectException(InvalidArgumentException::class);
        Fixture::calculate($input);
    }

    public function testUnreachableAndNeverRecoveredRemainExplicit(): void
    {
        $input = Fixture::input(); $input['monthlyRent'] = 50000;
        [, $r] = Fixture::calculate($input);
        $s = $r['operatingScenario'];
        self::assertGreaterThan(1, $s['cash_break_even_occupancy']);
        self::assertSame('unreachable', $s['cash_break_even_status']);
        self::assertSame('never_within_horizon', $s['equity_payback']['status']);
        self::assertNull($s['equity_payback']['months']);
        self::assertLessThan(0, $s['ending_cash_balance']);
        $input['otaCommissionRate'] = 100;
        [, $r] = Fixture::calculate($input);
        self::assertNull($r['operatingScenario']['cash_break_even_occupancy']);
        self::assertNull($r['operatingScenario']['rent_range']);
    }

    public function testZeroInvestmentAndFiniteHorizonDoNotInventReturn(): void
    {
        $input = Fixture::input(); $input['decorationInvestment'] = 0;
        [, $r] = Fixture::calculate($input);
        self::assertSame('no_initial_outlay', $r['operatingScenario']['equity_payback']['status']);
        self::assertNull($r['operatingScenario']['equity_payback']['months']);
        $input['decorationInvestment'] = 999999;
        [, $r] = Fixture::calculate($input);
        self::assertSame('not_recovered_within_horizon', $r['operatingScenario']['equity_payback']['status']);
    }

    public function testFutureMonthLeapDaysAndNonLeapDays(): void
    {
        foreach (['2023-02'=>28, '2024-02'=>29, '2100-02'=>28, '2000-02'=>29, '2026-01'=>31, '2026-04'=>30] as $month=>$days) {
            $input = Fixture::input(); $input['operatingScenario']['start_month']=$month;
            [, $r] = Fixture::calculate($input);
            self::assertSame($days, $r['operatingScenario']['cashflow_series'][1]['days']);
            self::assertSame($days * 450.0 - 6500, $r['operatingScenario']['cashflow_series'][1]['project_cashflow']);
        }
    }

    public function testUnroundedNegativeCashflowCannotBecomePaybackOrDivideByZero(): void
    {
        $input = array_replace(Fixture::input(), ['adr'=>100.01,'occupancyRate'=>50.09,'monthlyRent'=>14026.60,
            'decorationInvestment'=>1502.86,'laborCost'=>0,'utilityCost'=>0,'otaCommissionRate'=>0]);
        $input['operatingScenario'] = array_replace($input['operatingScenario'], ['start_month'=>'2026-01','horizon_months'=>2,'target_payback_months'=>2]);
        [, $r] = Fixture::calculate($input);
        $s = $r['operatingScenario'];
        self::assertSame('not_recovered_within_horizon', $s['project_payback']['status']);
        self::assertSame('not_recovered_within_horizon', $s['equity_payback']['status']);
        self::assertNull($s['equity_payback']['months']);
        self::assertSame('not_met', $s['target_status']);
        self::assertSame(0.0, $s['cashflow_series'][2]['equity_cashflow']);
    }

    public function testSubCentFundingGapAndNegativeRentRemainUnmetDespiteDisplayRounding(): void
    {
        $input=Fixture::input();$input['operatingScenario']['opening_cash']=29999.999;
        [, $r]=Fixture::calculate($input);
        self::assertSame(0.0,$r['operatingScenario']['additional_cash_gap']);
        self::assertSame('gap',$r['operatingScenario']['additional_cash_gap_status']);
        self::assertContains('可用现金低于测算期最大资金需求',$r['scenarioBoundary']['conditions']);
        $input=array_replace(Fixture::input(),['roomCount'=>1,'adr'=>1,'occupancyRate'=>100,'otaCommissionRate'=>0,'monthlyRent'=>0,'laborCost'=>29.001,'utilityCost'=>0]);
        [, $r]=Fixture::calculate($input);
        self::assertSame(0.0,$r['operatingScenario']['monthly_rent_ceiling']);
        self::assertNull($r['operatingScenario']['rent_range']);
        self::assertSame('unreachable_even_without_rent',$r['operatingScenario']['rent_status']);
    }

    public function testMinimumCashTargetRejectsUnmetStartingMonth(): void
    {
        $input=Fixture::input();$input['operatingScenario']['minimum_monthly_cashflow']=7000;
        [, $r]=Fixture::calculate($input);
        self::assertSame('above_ceiling',$r['operatingScenario']['rent_status']);
        self::assertSame('not_met',$r['operatingScenario']['monthly_cash_target']['status']);
        self::assertSame(['2024-02'],array_column($r['operatingScenario']['monthly_cash_target']['violations'],'month'));
        self::assertSame(6550.0,$r['operatingScenario']['monthly_cash_target']['violations'][0]['equity_cashflow']);
        self::assertNotEmpty($r['scenarioBoundary']['conditions']);
        self::assertStringNotContainsString('所列现金与回本约束在本组假设下满足',$r['scenarioBoundary']['explanation']);
    }

    public function testWeightedOccupancyCannotTurnNegativeCashIntoRecovery(): void
    {
        $input = array_replace(Fixture::input(), [
            'roomCount'=>100, 'adr'=>300, 'occupancyRate'=>50,
            'weekdayDays'=>22, 'weekdayAdr'=>300, 'weekdayOccupancyRate'=>50,
            'weekendDays'=>9, 'weekendAdr'=>300, 'weekendOccupancyRate'=>80,
            'holidayDays'=>0, 'holidayAdr'=>300, 'holidayOccupancyRate'=>50,
            'monthlyRent'=>546001, 'laborCost'=>0, 'utilityCost'=>0,
            'otaCommissionRate'=>0, 'decorationInvestment'=>1,
        ]);
        $input['operatingScenario'] = array_replace($input['operatingScenario'], [
            'start_month'=>'2026-01', 'horizon_months'=>1, 'target_payback_months'=>1, 'opening_cash'=>1,
        ]);
        [$normalized, $r] = Fixture::calculate($input);
        $s = $r['operatingScenario'];
        // 100 * (22 * .5 + 9 * .8) * 300 = 546000; rent leaves a real loss of 1.
        self::assertEqualsWithDelta(1820 / 3100 * 100, $normalized['occupancyRate'], 1e-12);
        self::assertSame(546000.0, $r['roomRevenue']);
        self::assertSame(-1.0, $r['monthlyNetCashflow']);
        self::assertSame(546000.0, $s['cashflow_series'][1]['revenue']);
        self::assertSame(-1.0, $s['cashflow_series'][1]['equity_cashflow']);
        self::assertSame(-2.0, $s['cashflow_series'][1]['equity_cumulative']);
        self::assertNull($s['equity_payback']['months']);
        self::assertSame('not_met', $s['target_status']);
        self::assertSame('not_met', $s['monthly_cash_target']['status']);
        $sensitivities = array_column($s['sensitivity'], null, 'factor');
        self::assertSame(-54600.0, $sensitivities['adr']['ending_cash_delta']);
        self::assertSame(-46500.0, $sensitivities['occupancyRate']['ending_cash_delta']);
        self::assertSame(-0.1, $sensitivities['decorationInvestment']['ending_cash_delta']);

        unset($input['operatingScenario']);
        [$legacy] = Fixture::calculate($input);
        self::assertSame(58.71, $legacy['occupancyRate']);
    }

    public function testWeightedAdrPreservesExactReferenceMonthBreakEven(): void
    {
        $input = array_replace(Fixture::input(), [
            'weekdayDays'=>23, 'weekdayAdr'=>100, 'weekdayOccupancyRate'=>50,
            'weekendDays'=>8, 'weekendAdr'=>120, 'weekendOccupancyRate'=>80,
            'holidayDays'=>0, 'holidayAdr'=>100, 'holidayOccupancyRate'=>50,
            'monthlyRent'=>19180, 'laborCost'=>0, 'utilityCost'=>0,
            'otaCommissionRate'=>0, 'decorationInvestment'=>0,
        ]);
        $input['operatingScenario'] = array_replace($input['operatingScenario'], [
            'start_month'=>'2026-01', 'horizon_months'=>1, 'target_payback_months'=>1, 'opening_cash'=>0,
        ]);
        [$normalized, $r] = Fixture::calculate($input);
        self::assertEqualsWithDelta(19180 / 179, $normalized['adr'], 1e-12);
        self::assertSame(19180.0, $r['operatingScenario']['cashflow_series'][1]['revenue']);
        self::assertSame(0.0, $r['operatingScenario']['cashflow_series'][1]['equity_cashflow']);
        self::assertSame('met', $r['operatingScenario']['monthly_cash_target']['status']);
        self::assertSame('covered', $r['operatingScenario']['additional_cash_gap_status']);
    }

    public function testSubcentInitialInvestmentCannotBecomeRecovered(): void
    {
        $input = array_replace(Fixture::input(), [
            'roomCount'=>1, 'adr'=>1, 'occupancyRate'=>100, 'monthlyRent'=>0,
            'laborCost'=>0, 'utilityCost'=>0, 'otaCommissionRate'=>0, 'decorationInvestment'=>29.004,
        ]);
        $input['operatingScenario'] = array_replace($input['operatingScenario'], [
            'horizon_months'=>1, 'target_payback_months'=>1, 'opening_cash'=>29.004,
        ]);
        [, $r] = Fixture::calculate($input);
        $s = $r['operatingScenario'];
        self::assertSame(29.0, $r['totalInvestment']);
        self::assertSame(-29.004, $s['cashflow_series'][0]['project_cashflow']);
        self::assertSame(0.0, $s['cashflow_series'][0]['cash_balance']);
        self::assertNull($s['project_payback']['months']);
        self::assertNull($s['equity_payback']['months']);
        self::assertSame('not_recovered_within_horizon', $s['equity_payback']['status']);
        self::assertSame('unreachable_even_without_rent', $s['target_status']);
    }

    public function testDecorationSensitivityRetainsUnroundedInitialInvestment(): void
    {
        $input = array_replace(Fixture::input(), [
            'roomCount'=>1, 'adr'=>1, 'occupancyRate'=>100, 'monthlyRent'=>0,
            'laborCost'=>0, 'utilityCost'=>0, 'otaCommissionRate'=>0, 'decorationInvestment'=>26.3637,
        ]);
        $input['operatingScenario'] = array_replace($input['operatingScenario'], [
            'horizon_months'=>1, 'target_payback_months'=>1, 'opening_cash'=>26.3637,
        ]);
        [, $r] = Fixture::calculate($input);
        self::assertSame('recovered_within_horizon', $r['operatingScenario']['equity_payback']['status']);
        $rows = array_column($r['operatingScenario']['sensitivity'], null, 'factor');
        $decoration = $rows['decorationInvestment'];
        // 26.3637 * 1.1 = 29.00007 > the entire month's 29 revenue.
        self::assertEqualsWithDelta(29.00007, $decoration['to'], 1e-10);
        self::assertNull($decoration['equity_payback']['months']);
        self::assertSame('not_recovered_within_horizon', $decoration['equity_payback']['status']);
        self::assertSame(-2.64, $decoration['ending_cash_delta']);
    }

    public function testShorterFutureMonthsLimitRentAndBlockWholePeriodCashTarget(): void
    {
        $input=array_replace(Fixture::input(),['occupancyRate'=>90,'monthlyRent'=>27000,'laborCost'=>0,'utilityCost'=>0,'decorationInvestment'=>0,'otaCommissionRate'=>0]);
        $input['operatingScenario']=array_replace($input['operatingScenario'],['start_month'=>'2026-01','target_payback_months'=>12,'minimum_monthly_cashflow'=>500]);
        [, $r]=Fixture::calculate($input);$s=$r['operatingScenario'];
        self::assertSame(900.0,$s['cashflow_series'][1]['equity_cashflow']);
        self::assertSame(-1800.0,$s['cashflow_series'][2]['equity_cashflow']);
        self::assertSame(0.0,$s['cashflow_series'][4]['equity_cashflow']);
        self::assertSame('above_ceiling',$s['rent_status']);
        self::assertSame(24700.0,$s['monthly_rent_ceiling']);
        self::assertSame('2026-02',$s['rent_reference_month']);
        self::assertSame(['2026-02','2026-04','2026-06','2026-09','2026-11'],array_column($s['monthly_cash_target']['violations'],'month'));
        self::assertStringNotContainsString('所列现金与回本约束在本组假设下满足',$r['scenarioBoundary']['explanation']);
    }

    #[DataProvider('invalidInputs')]
    public function testMissingInvalidAndMismatchedUnitsAreRejected(array $patch, bool $scenario): void
    {
        $input = Fixture::input();
        if ($scenario) $input['operatingScenario'] = array_replace($input['operatingScenario'], $patch);
        else $input = array_replace($input, $patch);
        $this->expectException(InvalidArgumentException::class);
        Fixture::calculate($input);
    }

    public static function invalidInputs(): array
    {
        return [
            [['loan_amount'=>null],true], [['opening_cash'=>''],true], [['annual_interest_rate'=>101],true],
            [['loan_amount'=>40000],true], [['loan_amount'=>1,'loan_term_months'=>0],true], [['loan_term_months'=>3],true],
            [['ramp_months'=>12],true], [['target_payback_months'=>13],true], [['horizon_months'=>361],true],
            [['start_month'=>'2024-13'],true], [['monetary_unit'=>'wan'],true], [['currency'=>'USD'],true],
            [['ramp_start_occupancy'=>51],true], [['ramp_start_occupancy'=>-1],true], [['horizon_months'=>1.5],true],
            [['roomCount'=>1.5],false], [['laborCost'=>null],false], [['weekdayDays'=>30,'weekdayAdr'=>100,'weekdayOccupancyRate'=>50,'weekendDays'=>0,'weekendAdr'=>100,'weekendOccupancyRate'=>50,'holidayDays'=>0,'holidayAdr'=>100,'holidayOccupancyRate'=>50],false],
        ];
    }
}
