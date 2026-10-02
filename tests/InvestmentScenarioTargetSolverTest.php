<?php
declare(strict_types=1);

namespace Tests;

use app\service\InvestmentScenarioCalculator;
use app\service\InvestmentScenarioTargetSolver;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class InvestmentScenarioTargetSolverTest extends TestCase
{
    private function input(): array
    {
        $input = (new InvestmentScenarioCalculator())->referenceExample();
        return array_replace($input, ['scenario_name' => 'synthetic inverse model', 'as_of' => '2026-10-01',
            'rooms' => 1, 'leased_rooms' => 0, 'years' => 1, 'adr_first_year' => 100,
            'occupancy_first_year' => 1, 'occupancy_mature' => 1, 'operating_cost_per_night' => 0,
            'monthly_rent_per_room' => 0, 'renovation_cash' => 36500, 'franchise_cash' => 0,
            'refundable_deposit_cash' => 0, 'other_initial_cash' => 0, 'working_capital_cash' => 0,
            'depreciable_amount' => 0, 'management_fee_rate' => 0,
            'cash_adjustments' => [['year' => 1, 'tax_cash' => 0, 'financing_net_cash' => 0,
                'maintenance_capex' => 0, 'working_capital_change' => 0, 'deposit_refund' => 0, 'salvage_cash' => 0]],
            'decision_constraints' => ['target_payback_months' => 12, 'contract_start_on' => '2026-10-01',
                'contract_end_on' => '2027-10-01', 'contract_source' => 'synthetic lease', 'contract_confirmed' => true]]);
    }

    public function testAdrInverseReplaysCurrentForwardModelAndIncludesCalendarEvaluation(): void
    {
        $input = $this->input(); $input['adr_first_year'] = null;
        $result = (new InvestmentScenarioTargetSolver())->solve($input, ['solve_for' => 'adr', 'bounds' => ['lower' => 0, 'upper' => 200]]);
        self::assertSame('solved', $result['status']); self::assertTrue($result['target_reached']);
        self::assertGreaterThanOrEqual(100, $result['solved_value']); self::assertLessThanOrEqual(100.01, $result['solved_value']);
        self::assertSame($result['solved_value'], $result['suggested_input']['adr_first_year']);
        self::assertLessThanOrEqual(12, $result['forward_result']['scenario_payback']['total_years'] * 12);
        $replayed = (new InvestmentScenarioCalculator())->calculate($result['suggested_input']);
        self::assertSame($replayed['annual_rows'], $result['forward_result']['annual_rows']);
        self::assertSame('within_limit', $result['forward_result']['decision_constraints']['contract_payback_status']);
        self::assertSame(InvestmentScenarioCalculator::MODEL_VERSION, $result['model_version']);
        self::assertSame('scenario_assumption', $result['source_quality']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['model_digest']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['solution_digest']);
        self::assertNull($input['adr_first_year']); self::assertFalse($result['actual_cash_written']);
    }

    public function testOccupancyPreservesRampRatioAndNeverExceedsCapacity(): void
    {
        $input = $this->input(); $input['occupancy_first_year'] = 0.5; $input['occupancy_mature'] = 0.8; $input['renovation_cash'] = 10000;
        $result = (new InvestmentScenarioTargetSolver())->solve($input, ['solve_for' => 'occupancy', 'occupancy_shape' => 'preserve_ratio', 'bounds' => ['lower' => 0, 'upper' => 1]]);
        self::assertSame('solved', $result['status']);
        self::assertEqualsWithDelta(0.625, $result['request']['first_year_to_mature_ratio'], 1.0e-12);
        self::assertEqualsWithDelta($result['solved_value'] * 0.625, $result['suggested_input']['occupancy_first_year'], 1.0e-12);
        self::assertSame($result['solved_value'], $result['suggested_input']['occupancy_mature']);
        self::assertLessThanOrEqual(1, $result['suggested_input']['occupancy_first_year']);
        self::assertLessThanOrEqual(12, $result['forward_result']['scenario_payback']['total_years'] * 12);
        $input['occupancy_first_year'] = 1; $input['occupancy_mature'] = 0.5;
        $result = (new InvestmentScenarioTargetSolver())->solve($input, ['solve_for' => 'occupancy', 'occupancy_shape' => 'preserve_ratio', 'bounds' => ['lower' => 0, 'upper' => 1]]);
        self::assertSame(0.5, $result['effective_bounds']['upper']);
    }

    public function testConstantOccupancyRequiresExplicitShapeAndWorksForZeroProfile(): void
    {
        $input = $this->input(); $input['occupancy_first_year'] = 0; $input['occupancy_mature'] = 0; $input['renovation_cash'] = 10000;
        $request = ['solve_for' => 'occupancy', 'bounds' => ['lower' => 0, 'upper' => 1]];
        $solver = new InvestmentScenarioTargetSolver();
        self::assertContains('target_request.occupancy_shape', $solver->solve($input, $request)['missing_fields']);
        $request['occupancy_shape'] = 'preserve_ratio';
        self::assertContains('occupancy_mature.positive_profile', $solver->solve($input, $request)['missing_fields']);
        $request['occupancy_shape'] = 'constant_all_years';
        $result = $solver->solve($input, $request);
        self::assertSame('solved', $result['status']);
        self::assertSame($result['suggested_input']['occupancy_first_year'], $result['suggested_input']['occupancy_mature']);
    }

    public function testUpperBoundFailureIsUnreachableOnlyWithinRequestedBoundsAndHorizon(): void
    {
        $result = (new InvestmentScenarioTargetSolver())->solve($this->input(), ['solve_for' => 'adr', 'bounds' => ['lower' => 0, 'upper' => 80]]);
        self::assertSame('unreachable', $result['status']); self::assertSame('upper_bound_or_horizon_insufficient', $result['reason']);
        self::assertNull($result['solved_value']); self::assertFalse($result['target_reached']);
        self::assertFalse($result['boundary_results']['upper']['target_reached']);
    }

    public function testNoPositiveContributionDoesNotReturnFiniteTarget(): void
    {
        $input = $this->input(); $input['management_fee_rate'] = 1;
        $result = (new InvestmentScenarioTargetSolver())->solve($input, ['solve_for' => 'adr', 'bounds' => ['lower' => 0, 'upper' => 200]]);
        self::assertSame('no_solution', $result['status']); self::assertSame('no_positive_cash_response', $result['reason']);
        self::assertNull($result['solved_value']); self::assertNull($result['suggested_input']);
    }

    public function testMissingCashAdjustmentsRemainMissingAndTargetAndBoundsAreExplicit(): void
    {
        $input = $this->input(); $input['cash_adjustments'][0]['tax_cash'] = null;
        $result = (new InvestmentScenarioTargetSolver())->solve($input, ['solve_for' => 'adr', 'bounds' => ['lower' => 0, 'upper' => 200]]);
        self::assertSame('inputs_missing', $result['status']); self::assertContains('cash_adjustments.1.tax_cash', $result['missing_fields']);
        self::assertNull($result['solved_value']);
        $input = $this->input(); $input['decision_constraints'] = null;
        $result = (new InvestmentScenarioTargetSolver())->solve($input, ['solve_for' => 'adr']);
        self::assertContains('decision_constraints.target_payback_months', $result['missing_fields']);
        self::assertContains('target_request.bounds.upper', $result['missing_fields']);
    }

    public function testConstructionAndInitialCashAreIncludedAndLowerBoundCanAlreadySatisfy(): void
    {
        $input = $this->input(); $input['construction_months'] = 6;
        $result = (new InvestmentScenarioTargetSolver())->solve($input, ['solve_for' => 'adr', 'bounds' => ['lower' => 0, 'upper' => 300]]);
        self::assertSame('solved', $result['status']); self::assertGreaterThanOrEqual(200, $result['solved_value']);
        self::assertLessThanOrEqual(200.01, $result['solved_value']);
        $result = (new InvestmentScenarioTargetSolver())->solve($input, ['solve_for' => 'adr', 'bounds' => ['lower' => 220, 'upper' => 300]]);
        self::assertSame('lower_bound_satisfies', $result['reason']); self::assertSame(0, $result['iterations']);
    }

    public function testMonthlyLoanAssumptionDoesNotSilentlyChangeAnnualRecovery(): void
    {
        $input = $this->input();
        $input['cash_plan'] = ['start_month' => '2026-10', 'months' => 1, 'opening_liquidity' => 0, 'source_label' => 'synthetic cash plan',
            'loans' => [['id' => 'new1', 'principal' => 10000, 'annual_rate' => 0, 'start_month' => '2026-10', 'term_months' => 12, 'method' => 'annuity', 'funding' => 'new']],
            'monthly_inputs' => [['month' => '2026-10', 'operating_net_cash' => 0, 'capex_cash' => 0, 'other_net_cash' => 0]]];
        $result = (new InvestmentScenarioTargetSolver())->solve($input, ['solve_for' => 'adr', 'bounds' => ['lower' => 0, 'upper' => 200]]);
        self::assertEqualsWithDelta(100, $result['solved_value'], 0.01);
        self::assertSame('10000.00', $result['forward_result']['cash_pressure']['monthly_rows'][0]['loan_draw']);
        self::assertSame(0.0, $result['suggested_input']['cash_adjustments'][0]['financing_net_cash']);
    }

    public function testSolverPreservesMaturityGrowthRentFreeAndFixedVariableCostRules(): void
    {
        $input = $this->input();
        $input = array_replace($input, ['years' => 3, 'rooms' => 2, 'leased_rooms' => 2, 'occupancy_first_year' => 0.5,
            'occupancy_mature' => 0.8, 'adr_growth_rate' => 0.1, 'adr_growth_from_year' => 2,
            'operating_cost_basis' => 'fixed_variable', 'operating_cost_per_night' => 40, 'fixed_annual_operating_cost' => 5000,
            'construction_months' => 3, 'rent_free_months' => 6, 'monthly_rent_per_room' => 150,
            'rent_escalations' => [['year' => 2, 'rate' => 0.08]], 'renovation_cash' => 50000]);
        $input['cash_adjustments'] = array_map(fn($year) => array_replace($input['cash_adjustments'][0], ['year' => $year]), [1, 2, 3]);
        $result = (new InvestmentScenarioTargetSolver())->solve($input, ['solve_for' => 'adr', 'target_payback_months' => 30, 'bounds' => ['lower' => 0, 'upper' => 1000]]);
        self::assertSame('solved', $result['status']);
        foreach (['occupancy_first_year', 'occupancy_mature', 'adr_growth_rate', 'operating_cost_basis', 'rent_free_months', 'rent_escalations'] as $field) {
            self::assertEquals($input[$field], $result['suggested_input'][$field]);
        }
        self::assertSame(30, $result['suggested_input']['decision_constraints']['target_payback_months']);
        self::assertLessThanOrEqual(30, $result['forward_result']['scenario_payback']['total_years'] * 12);
        self::assertEqualsWithDelta($result['solved_value'] * 1.1, $result['forward_result']['annual_rows'][1]['adr'], 1.0e-9);
        self::assertEqualsWithDelta(2700, $result['forward_result']['annual_rows'][0]['rent'], 1.0e-9);
        self::assertEqualsWithDelta(3888, $result['forward_result']['annual_rows'][1]['rent'], 1.0e-9);
    }

    public function testInvalidBoundsFailBeforeProducingTarget(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new InvestmentScenarioTargetSolver())->solve($this->input(), ['solve_for' => 'occupancy', 'occupancy_shape' => 'constant_all_years', 'bounds' => ['lower' => 0, 'upper' => 1.01]]);
    }

    public function testOverflowingOccupancyShapeIsRejectedBeforeDigest(): void
    {
        $input = $this->input();
        $input['occupancy_mature'] = 1e-320;
        $this->expectException(InvalidArgumentException::class);
        (new InvestmentScenarioTargetSolver())->solve($input, ['solve_for'=>'occupancy','occupancy_shape'=>'preserve_ratio','bounds'=>['lower'=>0,'upper'=>1]]);
    }

    public function testDeadlineNoiseAgreesWithCalendarWithoutAcceptingRealOverrun(): void
    {
        $input = $this->input();
        $input['construction_months'] = 0.4;
        $input['renovation_cash'] = 36500 * (12 - 0.4) / 12;
        $request = ['solve_for'=>'adr','bounds'=>['lower'=>0,'upper'=>100]];
        $service = new InvestmentScenarioTargetSolver();
        $result = $service->solve($input, $request);
        self::assertSame('solved', $result['status']);
        self::assertTrue($result['target_reached']);
        self::assertSame('within_limit', $result['forward_result']['decision_constraints']['target_status']);
        $input['renovation_cash'] = 35284;
        self::assertSame('unreachable', $service->solve($input, $request)['status']);
    }
}
