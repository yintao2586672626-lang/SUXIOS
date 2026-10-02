<?php
declare(strict_types=1);

namespace Tests;

use app\service\InvestmentScenarioCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class InvestmentScenarioCalculatorTest extends TestCase
{
    public function testExplicitReferenceReproducesFirstTwoYearsAndAppliesSeventhYearRentIncrease(): void
    {
        $calculator = new InvestmentScenarioCalculator();
        $input = $calculator->referenceExample();
        $result = $calculator->calculate($input);

        self::assertTrue($input['reference_example']);
        self::assertNull($input['as_of']);
        self::assertSame('49b5af7ae8655bb10a505b01da17da1db995314420ad6a45de7a90cac51f94f7', $input['source_sha256']);
        self::assertSame('partial', $result['status']);
        self::assertSame('scenario_assumption', $result['source']);
        self::assertSame('scenario_assumption', $result['data_quality']);
        self::assertSame(InvestmentScenarioCalculator::MODEL_VERSION, $result['model_version']);
        self::assertEqualsWithDelta(3371300, $result['initial_cash_total'], 0.001);
        self::assertEqualsWithDelta(9772875, $result['annual_rows'][0]['revenue'], 0.001);
        self::assertEqualsWithDelta(755194.375, $result['annual_rows'][0]['pretax_profit'], 0.001);
        self::assertEqualsWithDelta(1092324.375, $result['annual_rows'][0]['pretax_cash_proxy'], 0.001);
        self::assertEqualsWithDelta(3001525.3125, $result['annual_rows'][1]['pretax_cash_proxy'], 0.001);
        self::assertEqualsWithDelta(1.7592724990554283, $result['payback']['operating_years'], 0.0000001);
        self::assertEqualsWithDelta(2754000, $result['annual_rows'][5]['rent'], 0.001);
        self::assertEqualsWithDelta(2974320, $result['annual_rows'][6]['rent'], 0.001);
        self::assertEqualsWithDelta(2974320, $result['annual_rows'][9]['rent'], 0.001);
        self::assertContains('reference_depreciation_unclassified', array_column($result['warnings'], 'code'));
    }

    public function testNewDraftDoesNotSilentlyLoadReferenceInputsOrTreatMissingAsZero(): void
    {
        $calculator = new InvestmentScenarioCalculator();
        $draft = $calculator->normalize(['scenario_name' => '新方案']);
        $result = $calculator->calculate($draft);

        self::assertFalse($draft['reference_example']);
        self::assertNull($draft['rooms']);
        self::assertNull($draft['depreciable_amount']);
        self::assertNull($draft['source_sha256']);
        self::assertSame('inputs_missing', $result['status']);
        self::assertContains('rooms', $result['missing_fields']);
        self::assertContains('depreciable_amount', $result['missing_fields']);
        self::assertSame([], $result['annual_rows']);
        self::assertNull($result['totals']);
        self::assertNull($result['initial_cash_total']);
        self::assertNull($result['payback']);
        self::assertSame([], $result['sensitivity_rows']);
    }

    public function testMissingDepreciableAmountDoesNotFallBackToInitialSpending(): void
    {
        $calculator = new InvestmentScenarioCalculator();
        $input = $calculator->referenceExample();
        $input['depreciable_amount'] = null;
        $result = $calculator->calculate($input);
        self::assertSame('inputs_missing', $result['status']);
        self::assertSame(['depreciable_amount', 'as_of'], $result['missing_fields']);
        self::assertNull($result['payback']);
    }

    public function testExplicitZeroCashAndZeroOccupancyRemainKnownValues(): void
    {
        $calculator = new InvestmentScenarioCalculator();
        $input = $this->smallScenario();
        $input['renovation_cash'] = 0;
        $input['occupancy_first_year'] = 0;
        $input['occupancy_mature'] = 0;
        $result = $calculator->calculate($input);
        self::assertSame([], $result['missing_fields']);
        self::assertSame('partial', $result['status']);
        self::assertSame(0.0, $result['annual_rows'][0]['revenue']);
        self::assertSame('recovered_exact_boundary', $result['payback']['status']);
        self::assertSame(0.0, $result['payback']['operating_years']);
        self::assertNull($result['break_even']['profit_adr_at_input_occupancy']);
    }

    public function testNoRecoveryIsNullRatherThanMisleadingZero(): void
    {
        $calculator = new InvestmentScenarioCalculator();
        $input = $calculator->referenceExample();
        $input['adr_first_year'] = 180;
        $input['occupancy_first_year'] = 0.5;
        $input['occupancy_mature'] = 0.5;
        $result = $calculator->calculate($input);
        self::assertSame('not_recovered_in_horizon', $result['payback']['status']);
        self::assertNull($result['payback']['operating_years']);
        self::assertNull($result['payback']['total_years']);
        self::assertGreaterThan(3371300, $result['payback']['remaining_cash']);
    }

    public function testFiveYearHorizonIsRespectedAndTotalsAreSumsOfAnnualRows(): void
    {
        $calculator = new InvestmentScenarioCalculator();
        $input = $calculator->referenceExample();
        $input['years'] = 5;
        $result = $calculator->calculate($input);
        self::assertCount(5, $result['annual_rows']);
        self::assertSame(5, $result['annual_rows'][4]['year']);
        foreach (['revenue', 'operating_cost', 'rent', 'depreciation', 'management_fee', 'pretax_profit', 'pretax_cash_proxy'] as $key) {
            self::assertEqualsWithDelta(array_sum(array_column($result['annual_rows'], $key)), $result['totals'][$key], 0.0001, $key);
        }
        self::assertEqualsWithDelta(1685650, $result['totals']['depreciation'], 0.001);
        self::assertEqualsWithDelta($result['totals']['pretax_cash_proxy'] - $result['initial_cash_total'], $result['totals']['ending_cumulative_cash_proxy'], 0.001);
        self::assertContains('outside_horizon', array_column($result['warnings'], 'code'));
    }

    public function testConstructionRentIsInitialCashAndConstructionDurationIsAddedToPayback(): void
    {
        $calculator = new InvestmentScenarioCalculator();
        $input = $calculator->referenceExample();
        $input['construction_months'] = 6;
        $result = $calculator->calculate($input);
        self::assertEqualsWithDelta(1377000, $result['construction_rent_cash'], 0.001);
        self::assertEqualsWithDelta(4748300, $result['initial_cash_total'], 0.001);
        self::assertEqualsWithDelta($result['payback']['operating_years'] + 0.5, $result['payback']['total_years'], 0.000001);
        self::assertEqualsWithDelta(1092324.375 - 4748300, $result['annual_rows'][0]['cumulative_cash_proxy'], 0.001);
    }

    public function testRentFreeMonthsStartDuringConstructionAndContinueIntoOperatingYear(): void
    {
        $calculator = new InvestmentScenarioCalculator();
        $input = $calculator->referenceExample();
        $input['construction_months'] = 6;
        $input['rent_free_months'] = 3;
        $result = $calculator->calculate($input);
        self::assertEqualsWithDelta(688500, $result['construction_rent_cash'], 0.001);
        self::assertEqualsWithDelta(2754000, $result['annual_rows'][0]['rent'], 0.001);
        $input['rent_free_months'] = 9;
        $result = $calculator->calculate($input);
        self::assertSame(0.0, $result['construction_rent_cash']);
        self::assertEqualsWithDelta(2065500, $result['annual_rows'][0]['rent'], 0.001);
        self::assertEqualsWithDelta(2754000, $result['annual_rows'][1]['rent'], 0.001);
    }

    public function testExactZeroBoundaryIsRecoveredWithoutDividingByZero(): void
    {
        $calculator = new InvestmentScenarioCalculator();
        $result = $calculator->calculate($this->smallScenario());
        self::assertSame('recovered_exact_boundary', $result['payback']['status']);
        self::assertSame(1.0, $result['payback']['operating_years']);
        self::assertSame(0.0, $result['annual_rows'][0]['cumulative_cash_proxy']);

        $input = $this->smallScenario();
        $input['adr_first_year'] = 0;
        $result = $calculator->calculate($input);
        self::assertSame('not_recovered_in_horizon', $result['payback']['status']);
        self::assertNull($result['payback']['operating_years']);
        self::assertSame(10.0, $result['payback']['remaining_cash']);
    }

    public function testAlmostZeroNegativeBalanceDoesNotCountAsRecovery(): void
    {
        $calculator = new InvestmentScenarioCalculator();
        $input = $this->smallScenario();
        $input['years'] = 1;
        $input['renovation_cash'] = 10.00000001;
        $result = $calculator->calculate($input);
        self::assertSame('not_recovered_in_horizon', $result['payback']['status']);
        self::assertNull($result['payback']['operating_years']);
        self::assertGreaterThan(0, $result['payback']['remaining_cash']);
    }

    public function testOnlyFirstCrossingDeterminesPaybackEvenIfLaterCashflowIsNegative(): void
    {
        $calculator = new InvestmentScenarioCalculator();
        $input = $this->smallScenario();
        $input['renovation_cash'] = 5;
        $input['leased_rooms'] = 1;
        $input['monthly_rent_per_room'] = 1;
        $input['rent_free_months'] = 12;
        $result = $calculator->calculate($input);
        self::assertSame('recovered_interpolated', $result['payback']['status']);
        self::assertSame(0.5, $result['payback']['operating_years']);
        self::assertLessThan(0, $result['annual_rows'][4]['cumulative_cash_proxy']);
    }

    public function testAvailableOccupiedAndFixedVariableCostBasesHaveDistinctResults(): void
    {
        $calculator = new InvestmentScenarioCalculator();
        $input = $calculator->referenceExample();
        $available = $calculator->calculate($input);
        $input['operating_cost_basis'] = 'occupied_room_night';
        $occupied = $calculator->calculate($input);
        self::assertEqualsWithDelta(5584500, $available['annual_rows'][0]['operating_cost'], 0.001);
        self::assertEqualsWithDelta(3909150, $occupied['annual_rows'][0]['operating_cost'], 0.001);
        self::assertEqualsWithDelta(1675350, $occupied['annual_rows'][0]['pretax_profit'] - $available['annual_rows'][0]['pretax_profit'], 0.001);
        $input['operating_cost_basis'] = 'fixed_variable';
        $input['fixed_annual_operating_cost'] = null;
        self::assertContains('fixed_annual_operating_cost', $calculator->calculate($input)['missing_fields']);
        $input['fixed_annual_operating_cost'] = 100000;
        $fixedVariable = $calculator->calculate($input);
        self::assertEqualsWithDelta(4009150, $fixedVariable['annual_rows'][0]['operating_cost'], 0.001);
        self::assertEqualsWithDelta((153 * 365 * 0.85 * 100 + 100000) * 1.02, $fixedVariable['annual_rows'][1]['operating_cost'], 0.001);
    }

    public function testBreakEvenMatchesReferenceAndRejectsUndefinedDenominator(): void
    {
        $calculator = new InvestmentScenarioCalculator();
        $result = $calculator->calculate($calculator->referenceExample());
        self::assertEqualsWithDelta(0.6439459328813978, $result['break_even']['profit_occupancy'], 0.0000001);
        self::assertEqualsWithDelta(0.618922563702179, $result['break_even']['cash_proxy_occupancy'], 0.0000001);
        self::assertEqualsWithDelta(229.9806903147849, $result['break_even']['profit_adr_at_input_occupancy'], 0.0000001);
        $input = $this->smallScenario();
        $input['management_fee_rate'] = 1;
        $result = $calculator->calculate($input);
        self::assertSame('undefined_denominator', $result['break_even']['status']);
        self::assertNull($result['break_even']['profit_occupancy']);
        self::assertNull($result['break_even']['cash_proxy_adr_at_input_occupancy']);
    }

    public function testOptionalCashAdjustmentsStayMissingAndNeverBecomeZeroCashflow(): void
    {
        $calculator = new InvestmentScenarioCalculator();
        $input = $this->smallScenario();
        $input['cash_adjustments'] = [['year' => 1, 'tax_cash' => 0]];
        $result = $calculator->calculate($input);
        self::assertSame('partial', $result['status']);
        self::assertSame(0.0, $result['annual_rows'][0]['cash_adjustments']['tax_cash']);
        self::assertNull($result['annual_rows'][0]['cash_adjustments']['financing_net_cash']);
        self::assertNull($result['annual_rows'][0]['scenario_cashflow']);
        self::assertNull($result['totals']['scenario_cashflow']);
        self::assertNull($result['scenario_payback']);
        self::assertSame('cash_adjustments_incomplete', $result['exclusions'][1]['code']);
        self::assertContains('financing_net_cash', $result['exclusions'][1]['missing_by_year'][0]['fields']);
    }

    public function testExplicitEveryYearCashAdjustmentsProduceSeparateCashflowAndPayback(): void
    {
        $calculator = new InvestmentScenarioCalculator();
        $input = $this->smallScenario();
        for ($year = 1; $year <= 5; ++$year) {
            $input['cash_adjustments'][] = ['year' => $year, 'tax_cash' => 2, 'financing_net_cash' => -1,
                'maintenance_capex' => 3, 'working_capital_change' => 4, 'deposit_refund' => 1, 'salvage_cash' => 2];
        }
        $result = $calculator->calculate($input);
        self::assertSame('ready', $result['status']);
        self::assertSame('scenario_cash_not_verified', $result['exclusions'][0]['code']);
        self::assertCount(1, $result['exclusions']);
        self::assertSame(3.0, $result['annual_rows'][0]['scenario_cashflow']);
        self::assertSame(15.0, $result['totals']['scenario_cashflow']);
        self::assertSame(5.0, $result['totals']['ending_cumulative_scenario_cashflow']);
        self::assertSame(1.0, $result['payback']['operating_years']);
        self::assertEqualsWithDelta(10 / 3, $result['scenario_payback']['operating_years'], 0.000001);
        self::assertSame('scenario_cashflow', $result['scenario_payback']['basis']);
    }

    public function testMissingBaselineDateAndNameAllowArithmeticButCannotMarkScenarioReady(): void
    {
        $calculator = new InvestmentScenarioCalculator();
        $input = $this->smallScenario();
        for ($year = 1; $year <= 5; ++$year) {
            $input['cash_adjustments'][] = ['year' => $year, 'tax_cash' => 0, 'financing_net_cash' => 0,
                'maintenance_capex' => 0, 'working_capital_change' => 0, 'deposit_refund' => 0, 'salvage_cash' => 0];
        }
        $input['as_of'] = null;
        $result = $calculator->calculate($input);
        self::assertSame('partial', $result['status']);
        self::assertSame(['as_of'], $result['missing_fields']);
        self::assertCount(5, $result['annual_rows']);
        self::assertContains('missing_as_of', array_column($result['warnings'], 'code'));
        self::assertSame('scenario_cash_not_verified', $result['exclusions'][0]['code']);

        $input['as_of'] = '2026-10-01';
        $input['scenario_name'] = '';
        $result = $calculator->calculate($input);
        self::assertSame('partial', $result['status']);
        self::assertSame(['scenario_name'], $result['missing_fields']);
        self::assertContains('missing_scenario_name', array_column($result['warnings'], 'code'));
        self::assertSame(1.0, $result['payback']['operating_years']);
    }

    public function testFixedVariableZeroCostAndNegativeContributionKeepBreakEvenTruthful(): void
    {
        $calculator = new InvestmentScenarioCalculator();
        $input = $this->smallScenario();
        $input['operating_cost_basis'] = 'fixed_variable';
        $input['fixed_annual_operating_cost'] = 0;
        $result = $calculator->calculate($input);
        self::assertSame('calculated', $result['break_even']['status']);
        self::assertSame(0.0, $result['break_even']['profit_occupancy']);
        self::assertSame(0.0, $result['break_even']['cash_proxy_occupancy']);
        $input['operating_cost_per_night'] = 11;
        $result = $calculator->calculate($input);
        self::assertSame('undefined_denominator', $result['break_even']['status']);
        self::assertNull($result['break_even']['profit_occupancy']);
        self::assertFalse($result['break_even']['profit_occupancy_feasible']);
    }

    public function testDepreciationStopsAfterExplicitUsefulLifeAndDoesNotChangeCashProxy(): void
    {
        $calculator = new InvestmentScenarioCalculator();
        $input = $this->smallScenario();
        $input['depreciable_amount'] = 20;
        $input['depreciation_years'] = 2;
        $result = $calculator->calculate($input);
        self::assertSame(10.0, $result['annual_rows'][1]['depreciation']);
        self::assertSame(0.0, $result['annual_rows'][2]['depreciation']);
        self::assertSame(20.0, $result['totals']['depreciation']);
        self::assertSame(10.0, $result['annual_rows'][0]['pretax_cash_proxy']);
    }

    public function testLargeDepreciationDoesNotDistortSmallOperatingCashOrPayback(): void
    {
        $calculator = new InvestmentScenarioCalculator();
        $input = $this->smallScenario();
        $input['years'] = 1;
        $input['adr_first_year'] = 0.01;
        $input['renovation_cash'] = 0.01;
        $input['depreciable_amount'] = 1000000000000;
        $input['depreciation_years'] = 1;
        $input['cash_adjustments'] = [['year' => 1, 'tax_cash' => 0, 'financing_net_cash' => 0,
            'maintenance_capex' => 0, 'working_capital_change' => 0, 'deposit_refund' => 0, 'salvage_cash' => 0]];

        $result = $calculator->calculate($input);
        self::assertSame(0.01, $result['annual_rows'][0]['pretax_cash_proxy']);
        self::assertSame(0.01, $result['annual_rows'][0]['scenario_cashflow']);
        self::assertSame(0.0, $result['totals']['ending_cumulative_cash_proxy']);
        self::assertSame('recovered_exact_boundary', $result['payback']['status']);
        self::assertSame(1.0, $result['payback']['operating_years']);
        self::assertSame(1.0, $result['scenario_payback']['operating_years']);
    }

    public function testSensitivityIsNineConstantOccupancyScenariosNotOriginalRamp(): void
    {
        $calculator = new InvestmentScenarioCalculator();
        $result = $calculator->calculate($calculator->referenceExample());
        self::assertCount(9, $result['sensitivity_rows']);
        $middle = $result['sensitivity_rows'][4];
        self::assertSame('constant_occupancy_all_years', $middle['basis']);
        self::assertSame(250.0, $middle['adr_first_year']);
        self::assertSame(0.7, $middle['occupancy_all_years']);
        self::assertNotEquals($result['totals']['pretax_profit'], $middle['total_pretax_profit']);
        $input = $this->smallScenario();
        $input['occupancy_first_year'] = 0;
        $input['occupancy_mature'] = 0;
        $result = $calculator->calculate($input);
        foreach ($result['sensitivity_rows'] as $row) {
            self::assertGreaterThanOrEqual(0, $row['occupancy_all_years']);
            self::assertLessThanOrEqual(1, $row['occupancy_all_years']);
        }
    }

    public function testNormalizationIsCanonicalAndDoesNotPromoteClientQualityClaims(): void
    {
        $calculator = new InvestmentScenarioCalculator();
        $input = $calculator->referenceExample();
        $input['rooms'] = '153';
        $input['occupancy_first_year'] = '0.7';
        $input['as_of'] = '2026-10-01';
        $input['reference_example'] = '1';
        $input['rent_escalations'] = [['year' => '7', 'rate' => '0.08'], ['year' => '3', 'rate' => '0.02']];
        $input['source'] = 'verified_ota';
        $input['data_quality'] = 'readback_verified';
        $input['hotel_id'] = 80;
        $input['tenant_id'] = 7;
        $normalized = $calculator->normalize($input);
        self::assertSame($normalized, $calculator->normalize($normalized));
        self::assertSame(3, $normalized['rent_escalations'][0]['year']);
        self::assertArrayNotHasKey('hotel_id', $normalized);
        self::assertArrayNotHasKey('tenant_id', $normalized);
        $result = $calculator->calculate($input);
        self::assertSame('scenario_assumption', $result['data_quality']);
        self::assertSame('scenario_assumption', $result['source']);
        self::assertArrayNotHasKey('payback_date', $result['payback']);
        self::assertSame(80, $input['hotel_id']);
    }

    public function testRepeatedCallsAreIsolatedAndDoNotMutateInput(): void
    {
        $calculator = new InvestmentScenarioCalculator();
        $input = $calculator->referenceExample();
        $before = $input;
        $first = $calculator->calculate($input);
        $another = $this->smallScenario();
        $another['source_label'] = '其他方案，未验证';
        $calculator->calculate($another);
        self::assertSame($before, $input);
        self::assertSame($first, $calculator->calculate($input));
        self::assertNotSame($first['input']['source_label'], $calculator->calculate($another)['input']['source_label']);
    }

    public function testInvalidNumericRangesDatesAndDuplicateSchedulesAreRejected(): void
    {
        $calculator = new InvestmentScenarioCalculator();
        $cases = [
            ['rooms' => 0], ['rooms' => 1.5], ['rooms' => true], ['adr_first_year' => 'abc'],
            ['adr_first_year' => INF], ['years' => 31], ['occupancy_first_year' => 70],
            ['adr_growth_rate' => -1], ['operating_cost_growth_rate' => 1.1], ['days_per_year' => 367],
            ['renovation_cash' => -1], ['construction_months' => -1], ['as_of' => '2026-02-30'],
            ['currency' => 'USD'], ['source_sha256' => 'fake'], ['reference_example' => 'true'],
            ['rent_escalations' => [['year' => 7, 'rate' => 0.08], ['year' => 7, 'rate' => 0.02]]],
            ['cash_adjustments' => [['year' => 1, 'tax_cash' => -1]]],
        ];
        foreach ($cases as $case) {
            try {
                $calculator->normalize(array_replace($this->smallScenario(), $case));
                self::fail('Expected invalid input rejection: ' . json_encode($case));
            } catch (InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testConsumableSelectionPropagatesToProfitBreakEvenAndSensitivityWhileKeepingManualCost(): void
    {
        $calculator = new InvestmentScenarioCalculator();
        $input = $this->smallScenario();
        $input['operating_cost_basis'] = 'occupied_room_night';
        $input['operating_cost_per_night'] = 7;
        $input['consumables_cost'] = ['schema_version' => 'consumables-v1', 'mode' => 'derived', 'other_variable_cost_per_night' => 1,
            'items' => [['id' => 'soap', 'name' => '合成洗液', 'enabled' => true, 'package_price' => 30, 'package_quantity' => 500, 'unit' => 'ml',
                'usage_quantity' => 10, 'usage_basis' => 'guest_night', 'occurrences_per_occupied_night' => 1.5, 'source_label' => '合成验收', 'as_of' => '2026-10-01']]];
        $result = $calculator->calculate($input);
        self::assertEqualsWithDelta(0.9, $result['consumables_cost']['consumables_per_night'], 1e-10);
        self::assertEqualsWithDelta(1.9, $result['effective_operating_cost_per_night'], 1e-10);
        self::assertSame(7.0, $result['input']['operating_cost_per_night']);
        self::assertEqualsWithDelta(1.9, $result['annual_rows'][0]['operating_cost'], 1e-10);
        self::assertEqualsWithDelta(8.1, $result['annual_rows'][0]['pretax_profit'], 1e-10);
        self::assertEqualsWithDelta(1.9, $result['break_even']['profit_adr_at_input_occupancy'], 1e-10);
        $input['consumables_cost']['mode'] = 'manual';
        $manual = $calculator->calculate($input);
        self::assertSame(7.0, $manual['annual_rows'][0]['operating_cost']);
        self::assertNotSame($manual['sensitivity_rows'], $result['sensitivity_rows']);
        $input['consumables_cost']['mode'] = 'derived';
        $input['consumables_cost']['items'][0]['occurrences_per_occupied_night'] = null;
        $missing = $calculator->calculate($input);
        self::assertSame('inputs_missing', $missing['status']);
        self::assertSame([], $missing['annual_rows']);
        self::assertNull($missing['effective_operating_cost_per_night']);
        self::assertNotContains('operating_cost_per_night', $missing['missing_fields']);
        $input['consumables_cost']['items'][0]['occurrences_per_occupied_night'] = 1.5;
        $input['operating_cost_basis'] = 'available_room_night';
        self::assertContains('consumables_cost.occupied_room_night_basis', $calculator->calculate($input)['missing_fields']);
        self::assertNull($calculator->calculate($input)['effective_operating_cost_per_night']);
        unset($input['consumables_cost']);
        self::assertNull($calculator->calculate($input)['consumables_cost']);
        self::assertSame(7.0, $calculator->calculate($input)['annual_rows'][0]['operating_cost']);
    }

    private function smallScenario(): array
    {
        return [
            'scenario_name' => '边界算例', 'as_of' => '2026-10-01', 'currency' => 'CNY', 'rooms' => 1,
            'leased_rooms' => 0, 'years' => 5, 'adr_first_year' => 10, 'occupancy_first_year' => 1,
            'occupancy_mature' => 1, 'mature_from_year' => 2, 'adr_growth_rate' => 0, 'adr_growth_from_year' => 3,
            'operating_cost_basis' => 'available_room_night', 'operating_cost_per_night' => 0,
            'fixed_annual_operating_cost' => null, 'operating_cost_growth_rate' => 0,
            'monthly_rent_per_room' => 0, 'rent_escalations' => [], 'rent_free_months' => 0,
            'construction_months' => 0, 'renovation_cash' => 10, 'franchise_cash' => 0,
            'refundable_deposit_cash' => 0, 'other_initial_cash' => 0, 'working_capital_cash' => 0,
            'depreciable_amount' => 0, 'depreciation_years' => 10, 'management_fee_rate' => 0,
            'days_per_year' => 1, 'cash_adjustments' => [],
        ];
    }
}
