<?php
declare(strict_types=1);

namespace Tests;

use app\service\InvestmentContractDateConstraintService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class InvestmentContractDateConstraintServiceTest extends TestCase
{
    private function constraints(): array
    {
        return ['target_payback_months' => 12, 'contract_start_on' => '2026-10-01', 'contract_end_on' => '2027-10-01',
            'contract_source' => 'synthetic lease', 'contract_confirmed' => true];
    }

    private function scenarioResult(?float $years = 1.0): array
    {
        return ['status' => 'ready', 'input' => ['as_of' => '2026-10-01'],
            'scenario_payback' => ['total_years' => $years], 'payback' => ['total_years' => $years]];
    }

    public function testExactNaturalYearIsWithinContractAndTargetAtSameDate(): void
    {
        $result = (new InvestmentContractDateConstraintService())->evaluate($this->constraints(), $this->scenarioResult());
        self::assertSame('2027-10-01', $result['forecast_payback_on']);
        self::assertSame('2027-10-01', $result['target_payback_on']);
        self::assertSame(365, $result['contract_remaining_days']);
        self::assertSame(12.0, $result['contract_remaining_months']);
        self::assertSame('within_limit', $result['contract_payback_status']);
        self::assertSame('within_limit', $result['target_status']);
        self::assertSame(0, $result['contract_safety_days']);
        self::assertSame('scenario_assumption', $result['source_quality']);
        self::assertFalse($result['actual_cash_written']);
    }

    public function testMonthEndAndLeapYearUseOriginalDayAnchors(): void
    {
        $service = new InvestmentContractDateConstraintService();
        self::assertSame('2027-02-28', $service->dateFromMonths('2027-01-31', 1)['date']);
        self::assertSame('2028-02-29', $service->dateFromMonths('2028-01-31', 1)['date']);
        self::assertSame('2027-03-31', $service->dateFromMonths('2027-01-31', 2)['date']);
        self::assertSame('2028-02-28', $service->dateFromMonths('2027-02-28', 12)['date']);
    }

    public function testFractionalMonthRoundsActualAnchorDaysUpAndExposesAssumption(): void
    {
        $service = new InvestmentContractDateConstraintService();
        $fraction = $service->dateFromMonths('2027-01-31', 1.5);
        self::assertSame('2027-03-16', $fraction['date']);
        self::assertSame(31, $fraction['anchor_span_days']);
        self::assertSame(16, $fraction['fraction_days']);
        self::assertSame('ceil_fractional_day', $fraction['rounding']);
        self::assertSame('2027-01-05', $service->dateFromMonths('2027-01-01', 0.1)['date']);
        self::assertSame('2027-02-28', $service->dateFromMonths('2027-01-31', 1.000000000000001)['date']);
    }

    public function testOneDayBeyondContractHasNegativeSafetyRatherThanAverageMonthSuccess(): void
    {
        $constraints = $this->constraints(); $constraints['contract_end_on'] = '2027-09-30';
        $result = (new InvestmentContractDateConstraintService())->evaluate($constraints, $this->scenarioResult());
        self::assertSame('beyond_limit', $result['contract_payback_status']);
        self::assertSame(-1, $result['contract_safety_days']);
    }

    public function testMissingDatesConfirmationAndForecastStayUnknown(): void
    {
        $service = new InvestmentContractDateConstraintService();
        self::assertNull($service->evaluate(null, $this->scenarioResult()));
        $constraints = $this->constraints(); unset($constraints['contract_source']);
        $result = $service->evaluate($constraints, $this->scenarioResult());
        self::assertSame('unverified', $result['contract_status']);
        self::assertNull($result['contract_remaining_days']); self::assertNull($result['contract_safety_days']);
        self::assertSame('inputs_missing', $result['contract_payback_status']);
        $missingDate = $this->scenarioResult(); $missingDate['input']['as_of'] = null;
        $result = $service->evaluate($this->constraints(), $missingDate);
        self::assertNull($result['forecast_payback_on']); self::assertNull($result['target_payback_on']);
        self::assertSame('inputs_missing', $result['target_status']);
        self::assertContains('as_of', $result['contract_missing_fields']);
    }

    public function testNoRecoveryAndPretaxProxyNeverBecomeConfirmedDeadline(): void
    {
        $service = new InvestmentContractDateConstraintService();
        $result = $service->evaluate($this->constraints(), $this->scenarioResult(null));
        self::assertSame('not_reached_in_horizon', $result['target_status']);
        self::assertNull($result['forecast_payback_on']); self::assertNull($result['contract_safety_days']);
        $proxy = $this->scenarioResult(); $proxy['status'] = 'partial'; $proxy['scenario_payback'] = null;
        $result = $service->evaluate($this->constraints(), $proxy);
        self::assertSame('trial_only', $result['contract_payback_status']);
        self::assertSame('pretax_proxy_only', $result['forecast_basis']);
        self::assertNull($result['contract_safety_days']);
        $absent = $this->scenarioResult(); $absent['scenario_payback'] = null; $absent['payback'] = null;
        self::assertSame('forecast_missing', $service->evaluate($this->constraints(), $absent)['target_status']);
    }

    public function testExpiredAndNotStartedContractsHaveExplicitStates(): void
    {
        $service = new InvestmentContractDateConstraintService();
        $expired = $this->constraints(); $expired['contract_start_on'] = '2025-01-01'; $expired['contract_end_on'] = '2026-01-01';
        $result = $service->evaluate($expired, $this->scenarioResult());
        self::assertSame('expired', $result['contract_status']); self::assertSame(0, $result['contract_remaining_days']);
        self::assertSame('beyond_limit', $result['contract_payback_status']);
        $future = $this->constraints(); $future['contract_start_on'] = '2027-01-01';
        $result = $service->evaluate($future, $this->scenarioResult(0.0));
        self::assertSame('not_started', $result['contract_status']);
        self::assertSame('before_contract_start', $result['contract_payback_status']);
    }

    public function testInvalidProjectionDoesNotProduceFiniteDate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new InvestmentContractDateConstraintService())->dateFromMonths('2027-01-31', INF);
    }

    public function testProjectionBeyondSupportedYearDoesNotWrapToYear2000(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new InvestmentContractDateConstraintService())->dateFromMonths('9999-12-31', 1);
    }

    public function testZeroMonthsAtLastSupportedDayKeepsValidDateAndSpan(): void
    {
        $result = (new InvestmentContractDateConstraintService())->dateFromMonths('9999-12-31', 0);
        self::assertSame('9999-12-31', $result['date']);
        self::assertSame(31, $result['anchor_span_days']);
        self::assertNull($result['next_anchor_on']);
    }

    public function testContractRemainingFractionDoesNotReparseExpandedYear(): void
    {
        $constraints = array_replace($this->constraints(), ['target_payback_months'=>null,'contract_start_on'=>'9999-01-01','contract_end_on'=>'9999-12-31']);
        $input = $this->scenarioResult(null); $input['input']['as_of'] = '9999-10-01';
        $service = new InvestmentContractDateConstraintService();
        self::assertSame(2.967742, $service->evaluate($constraints, $input)['contract_remaining_months']);
        $input['input']['as_of'] = '9999-10-31';
        self::assertSame(2.0, $service->evaluate($constraints, $input)['contract_remaining_months']);
    }
}
