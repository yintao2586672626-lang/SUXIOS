<?php
declare(strict_types=1);
namespace Tests;

use app\service\InvestmentScenarioCashPlanner;
use app\service\InvestmentScenarioCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class InvestmentScenarioCashPlannerTest extends TestCase
{
    private function plan(array $changes = []): array
    {
        return array_replace(['start_month' => '2026-10', 'months' => 2, 'opening_liquidity' => '1000.00', 'source_label' => '合成月度假设',
            'loans' => [['id' => 'loan1', 'principal' => '1000.00', 'annual_rate' => .12, 'start_month' => '2026-10', 'term_months' => 2, 'method' => 'equal_principal', 'funding' => 'existing']],
            'monthly_inputs' => [['month' => '2026-10', 'operating_net_cash' => 300, 'capex_cash' => 0, 'other_net_cash' => 0],
                ['month' => '2026-11', 'operating_net_cash' => 300, 'capex_cash' => 1000, 'other_net_cash' => 0]]], $changes);
    }

    public function testNonzeroExistingLoanDoesNotCreateCashAndShowsExactTrough(): void
    {
        $result = (new InvestmentScenarioCashPlanner())->evaluate($this->plan());
        self::assertSame('ready_assumption', $result['status']);
        self::assertSame('0.00', $result['monthly_rows'][0]['loan_draw']);
        self::assertSame('500.00', $result['monthly_rows'][0]['principal_payment']);
        self::assertSame('10.00', $result['monthly_rows'][0]['interest_payment']);
        self::assertSame('790.00', $result['monthly_rows'][0]['ending_liquidity']);
        self::assertSame('-415.00', $result['minimum_liquidity']);
        self::assertSame('415.00', $result['funding_gap']);
        self::assertSame('2026-11', $result['minimum_month']);
        self::assertSame('2026-11', $result['observed_first_shortage_month']);
        self::assertEqualsWithDelta(300 / 510, $result['minimum_dscr'], 1e-12);
        self::assertSame('0.00', $result['debt_schedule'][1]['remaining_principal']);
    }

    public function testZeroRateAnnuityAdjustsFinalCentAndNewLoanDrawIsExplicit(): void
    {
        $plan = $this->plan(['months' => 3, 'opening_liquidity' => 0]);
        $plan['loans'][0] = array_replace($plan['loans'][0], ['term_months' => 3, 'annual_rate' => 0, 'method' => 'annuity', 'funding' => 'new']);
        $plan['monthly_inputs'] = array_map(fn($month) => ['month' => $month, 'operating_net_cash' => 0, 'capex_cash' => 0, 'other_net_cash' => 0], ['2026-10', '2026-11', '2026-12']);
        $result = (new InvestmentScenarioCashPlanner())->evaluate($plan);
        self::assertSame('1000.00', $result['monthly_rows'][0]['loan_draw']);
        self::assertSame(['333.33', '333.33', '333.34'], array_column($result['debt_schedule'], 'principal_payment'));
        self::assertSame('0.00', $result['ending_liquidity']);
        self::assertSame('0.00', $result['funding_gap']);
    }

    public function testInterestOnlyBalloonAndBeyondHorizonRepaymentsRemainVisible(): void
    {
        $plan = $this->plan(['months' => 1]);
        $plan['loans'][0]['method'] = 'interest_only';
        $plan['monthly_inputs'] = [$plan['monthly_inputs'][0]];
        $result = (new InvestmentScenarioCashPlanner())->evaluate($plan);
        self::assertSame('0.00', $result['debt_schedule'][0]['principal_payment']);
        self::assertSame('1000.00', $result['debt_schedule'][1]['principal_payment']);
        self::assertFalse($result['debt_schedule'][1]['within_plan']);
        self::assertSame('10.00', $result['debt_schedule'][1]['interest_payment']);
    }

    public function testMissingMonthNeverBecomesZeroOrCompleteCashTrough(): void
    {
        $plan = $this->plan(); unset($plan['monthly_inputs'][0]['capex_cash']);
        $result = (new InvestmentScenarioCashPlanner())->evaluate($plan);
        self::assertSame('partial', $result['status']);
        self::assertNull($result['monthly_rows'][0]['capex_cash']);
        self::assertNull($result['monthly_rows'][0]['net_cash']);
        self::assertNull($result['monthly_rows'][1]['ending_liquidity']);
        self::assertNull($result['minimum_liquidity']); self::assertNull($result['funding_gap']);
        self::assertContains('cash_plan.2026-10.capex_cash', $result['missing_fields']);
    }

    public function testContractSourceAndConfirmationAreRequiredAndTargetsCanBeUnreachable(): void
    {
        $calc = new InvestmentScenarioCalculator(); $input = $calc->referenceExample();
        $input['as_of'] = '2026-10-01'; $input['decision_constraints'] = ['target_payback_months' => 1, 'contract_start_on' => '2026-01-01', 'contract_end_on' => '2027-01-01'];
        $result = $calc->calculate($input)['decision_constraints'];
        self::assertSame('unverified', $result['contract_status']); self::assertNull($result['contract_remaining_months']);
        self::assertSame('trial_only', $result['target_status']);
        $input['decision_constraints'] += ['contract_source' => '合成租约编号', 'contract_confirmed' => true];
        $input['years'] = 1; $input['adr_first_year'] = 1;
        $input['cash_adjustments'] = [['year' => 1, 'tax_cash' => 0, 'financing_net_cash' => 0, 'maintenance_capex' => 0, 'working_capital_change' => 0, 'deposit_refund' => 0, 'salvage_cash' => 0]];
        $result = $calc->calculate($input)['decision_constraints'];
        self::assertSame('user_confirmed', $result['contract_status']); self::assertGreaterThan(0, $result['contract_remaining_months']);
        self::assertSame('not_reached_in_horizon', $result['target_status']); self::assertSame('not_reached_in_horizon', $result['contract_payback_status']);
        $input['decision_constraints']['contract_end_on'] = '2026-02-01';
        $result = $calc->calculate($input)['decision_constraints'];
        self::assertSame('expired', $result['contract_status']); self::assertSame(0.0, $result['contract_remaining_months']);
    }

    public function testOutOfWindowInputsAndInvalidLoansFailInsteadOfBeingIgnored(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $plan = $this->plan(); $plan['monthly_inputs'][0]['month'] = '2027-01';
        (new InvestmentScenarioCashPlanner())->evaluate($plan);
    }

    public function testIncompleteLoanAndEmptyPlanStartCanPersistAsUnknownDraft(): void
    {
        $plan = $this->plan(); $plan['loans'][0]['annual_rate'] = null;
        $result = (new InvestmentScenarioCashPlanner())->evaluate($plan);
        self::assertSame('partial', $result['status']); self::assertNull($result['monthly_rows'][0]['interest_payment']);
        self::assertNull($result['monthly_rows'][0]['debt_service']); self::assertNull($result['funding_gap']);
        $plan['start_month'] = '';
        $calc = new InvestmentScenarioCalculator(); $input = $calc->referenceExample(); $input['cash_plan'] = $plan;
        $result = $calc->calculate($input);
        self::assertNull($result['input']['cash_plan']['start_month']); self::assertSame([], $result['cash_pressure']['monthly_rows']);
        self::assertNull($result['cash_pressure']['minimum_liquidity']);
    }

    public function testUnclassifiedLoanDoesNotSilentlyBecomeNewFunding(): void
    {
        $plan = $this->plan(); unset($plan['loans'][0]['funding']);
        $result = (new InvestmentScenarioCashPlanner())->evaluate($plan);
        self::assertSame('partial', $result['status']); self::assertNull($result['monthly_rows'][0]['loan_draw']);
        self::assertNull($result['ending_debt_principal']); self::assertContains('cash_plan.loans.loan1.funding', $result['missing_fields']);
    }

    public function testExistingBalanceCannotSkipEarlyInterestByStartingAfterThePlanOrigin(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('存量贷款须从计划起点');
        $plan = $this->plan(); $plan['loans'][0]['start_month'] = '2026-11';
        (new InvestmentScenarioCashPlanner())->evaluate($plan);
    }

    public function testFutureLoanOutsideHorizonIsPreservedButCannotFundCurrentShortfall(): void
    {
        $plan = $this->plan(['opening_liquidity' => 0]);
        $plan['loans'][0]['funding'] = 'new'; $plan['loans'][0]['start_month'] = '2026-12';
        $result = (new InvestmentScenarioCashPlanner())->evaluate($plan);
        self::assertSame('ready_assumption', $result['status']); self::assertSame('0.00', $result['monthly_rows'][0]['loan_draw']);
        self::assertSame('400.00', $result['funding_gap']); self::assertSame('0.00', $result['ending_debt_principal']);
        self::assertSame(['loan1'], $result['loans_after_horizon']); self::assertCount(2, $result['debt_schedule']);
        self::assertFalse($result['debt_schedule'][0]['within_plan']); self::assertSame('1015.00', $result['debt_service_after_horizon']);
    }

    public function testHorizonDoesNotHideBalloonAndOpeningCashIsIncludedInMinimum(): void
    {
        $plan = $this->plan(['months' => 1, 'opening_liquidity' => 0]);
        $plan['loans'][0]['funding'] = 'new'; $plan['loans'][0]['method'] = 'interest_only';
        $plan['monthly_inputs'] = [$plan['monthly_inputs'][0]];
        $result = (new InvestmentScenarioCashPlanner())->evaluate($plan);
        self::assertSame('opening', $result['minimum_point']); self::assertSame('0.00', $result['minimum_liquidity']);
        self::assertSame('1290.00', $result['ending_liquidity']); self::assertSame('1000.00', $result['ending_debt_principal']);
        self::assertSame('1010.00', $result['debt_service_after_horizon']);
    }

    public function testMissingWholeMonthAndMissingOpeningNeverRestoreKnownCumulativeCash(): void
    {
        $plan = $this->plan(['opening_liquidity' => null]); $plan['monthly_inputs'] = [$plan['monthly_inputs'][1]];
        $result = (new InvestmentScenarioCashPlanner())->evaluate($plan);
        self::assertNull($result['monthly_rows'][0]['operating_net_cash']); self::assertNull($result['monthly_rows'][0]['net_cash']);
        self::assertNull($result['monthly_rows'][1]['ending_liquidity']); self::assertNull($result['funding_gap']);
        self::assertContains('cash_plan.opening_liquidity', $result['missing_fields']);
    }

    public function testPositiveRateAnnuityAndVerySmallRateBothClosePrincipalExactly(): void
    {
        $plan = $this->plan(); $plan['loans'][0]['method'] = 'annuity';
        $result = (new InvestmentScenarioCashPlanner())->evaluate($plan);
        self::assertSame(['507.51', '507.51'], array_column($result['debt_schedule'], 'debt_service'));
        self::assertSame(['10.00', '5.02'], array_column($result['debt_schedule'], 'interest_payment'));
        self::assertSame('0.00', $result['debt_schedule'][1]['remaining_principal']);
        $plan['loans'][0]['annual_rate'] = 1e-18;
        $result = (new InvestmentScenarioCashPlanner())->evaluate($plan);
        self::assertSame(['500.00', '500.00'], array_column($result['debt_schedule'], 'principal_payment'));
        self::assertSame('0.00', $result['debt_schedule'][1]['remaining_principal']);
    }
}
