#!/usr/bin/env php
<?php
declare(strict_types=1);

/** Reference replay only. No application boot, database, network or business write. */
function joydoHotelCostReplay(string $root): array
{
    $fixture = json_decode((string)file_get_contents($root . '/docs/knowledge/joydo-jhira-cost-20261001/sources/replay-fixture.json'), true, 512, JSON_THROW_ON_ERROR);
    if ($fixture['version'] !== 'joydo-cost-replay-20261001-v1') {
        throw new RuntimeException('cost_fixture_identity_conflict');
    }
    $passed = [];
    $check = static function (bool $ok, string $name) use (&$passed): void {
        if (!$ok) {
            throw new RuntimeException('cost_replay_failed:' . $name);
        }
        $passed[] = $name;
    };
    $near = static fn(float $a, float $b): bool => abs($a - $b) < 0.00001;
    $ratio = static fn(?float $numerator, ?float $denominator): ?float => $numerator !== null && $denominator !== null && $denominator > 0 ? $numerator / $denominator : null;
    $model = static function (float $available, float $occupancy, float $netAdr, float $fixed, float $variable) use ($ratio): array {
        $sold = $available * $occupancy;
        $revenue = $sold * $netAdr;
        $cost = $fixed + $sold * $variable;
        $cashProxy = $revenue - $cost;
        $contribution = $netAdr - $variable;
        return ['sold' => $sold, 'revenue' => $revenue, 'cost' => $cost, 'cash_proxy' => $cashProxy,
            'cost_ratio' => $ratio($cost, $revenue), 'breakeven' => $ratio($fixed, $available * $contribution),
            'simple_payback_years' => $cashProxy > 0 ? 3000000 / ($cashProxy * 12) : null];
    };
    $s = $fixture['source_sample'];
    $available = (float)($s['rooms'] * $s['days']);
    $v = (float)($s['selected_amenities'] + $s['utilities'] + $s['laundry']);
    $standard = $model($available, $s['occupancy'], $s['net_adr'], $s['fixed_cost'], $v);
    $check($near($standard['sold'], 975) && $near($standard['revenue'], 156000) && $near($standard['cost'], 118598) && $near($standard['cash_proxy'], 37402), 'visible_standard_sample');
    $check(round($standard['breakeven'] * 100, 2) === 46.21 && round($standard['simple_payback_years'], 2) === 6.68, 'visible_breakeven_simple_payback');
    $precision = ($s['precision_configuration_total'] - $s['sugar_configuration']) * $s['source_assumed_usage'] + $s['sugar_configuration'] * $s['sugar_assumed_usage'];
    $check($near($precision, 49.168) && round($precision, 2) === 49.17, 'source_assumed_usage_arithmetic');
    $conditional = $model($available, $s['occupancy'], $s['net_adr'], $s['fixed_cost'], $precision + $s['utilities'] + $s['laundry']);
    $check($near($conditional['cost'], 160901.3) && $near($conditional['cash_proxy'], -4901.3) && round($conditional['breakeven'] * 100, 2) === 68.66 && $conditional['simple_payback_years'] === null, 'conditional_precision_profit_reversal');
    $check($near($standard['cash_proxy'] - $conditional['cash_proxy'], $standard['sold'] * ($precision - $s['selected_amenities'])), 'cost_delta_propagates_to_profit');
    $revpar = $standard['revenue'] / $available;
    $costPerAvailable = $standard['cost'] / $available;
    $costPerSold = $standard['cost'] / $standard['sold'];
    $check($near($standard['cost_ratio'], $costPerAvailable / $revpar) && $near($standard['cost_ratio'], $costPerSold / $s['net_adr']), 'same_scope_denominator_identity');
    $mixed = ($s['fixed_cost'] / $available + $v) / $revpar;
    $check(round($mixed * 100, 2) === 85.21 && round($standard['cost_ratio'] * 100, 2) === 76.02 && !$near($mixed, $standard['cost_ratio']), 'observed_mixed_denominator_reproduced');
    $higherOccupancy = $model($available, 0.8, $s['net_adr'], $s['fixed_cost'], $v);
    $check($near($higherOccupancy['cost'] - $standard['cost'], $available * (0.8 - $s['occupancy']) * $v), 'volume_changes_variable_not_fixed_cost');
    $check($ratio(null, 100) === null && $ratio(1, null) === null && $ratio(1, 0) === null && $ratio(0, 100) === 0.0, 'unknown_and_zero_denominators');
    $noRevenue = $model($available, 0, $s['net_adr'], $s['fixed_cost'], $v);
    $check($noRevenue['cost_ratio'] === null && $near($noRevenue['cost'], $s['fixed_cost']), 'zero_occupancy_retains_fixed_cost');
    $badContribution = $model($available, 0.65, $s['net_adr'], $s['fixed_cost'], 200);
    $oneYearTarget = ($s['fixed_cost'] + 3000000 / 12) / ($available * ($s['net_adr'] - $v));
    $check($badContribution['breakeven'] === null && $oneYearTarget > 1 && round($oneYearTarget * 100, 2) === 171.79, 'invalid_contribution_and_unreachable_target');
    $check($oneYearTarget > 1 && $standard['simple_payback_years'] > 1 && is_finite($standard['simple_payback_years']), 'unreachable_target_does_not_exclude_longer_payback');
    $platformAdr = $s['net_adr'] / (1 - $s['commission']);
    $check($near($platformAdr * (1 - $s['commission']), $s['net_adr']) && $near($standard['revenue'] * $s['commission'], 28080), 'net_adr_commission_not_deducted_twice');
    $syn = $fixture['synthetic_units'];
    $bottleUnit = $syn['bottle_price'] / $syn['bottle_ml'];
    $soldNightUnit = $bottleUnit * $syn['ml_per_guest_night'] * $syn['guests_per_sold_room_night'];
    $check($near($soldNightUnit, 0.84) && !$near($soldNightUnit, (float)$syn['bottle_price']), 'synthetic_bottle_to_guest_to_sold_night');
    $guestConversion = null;
    $missingUnit = $guestConversion === null ? null : $bottleUnit * $syn['ml_per_guest_night'] * $guestConversion;
    $adoptedPrecision = $s['source_usage_basis_verified'] ? $precision : null;
    $check($missingUnit === null && $adoptedPrecision === null, 'missing_units_block_automatic_adoption');
    $inventory = static function (array $in): ?float {
        foreach (['opening', 'purchases', 'transfer_in', 'closing', 'transfer_out', 'returns', 'writeoff'] as $key) {
            if (!array_key_exists($key, $in) || $in[$key] === null) {
                return null;
            }
        }
        $consumed = $in['opening'] + $in['purchases'] + $in['transfer_in'] - $in['closing'] - $in['transfer_out'] - $in['returns'] - $in['writeoff'];
        return $consumed >= 0 ? (float)$consumed : null;
    };
    $check($inventory($syn['inventory']) === 150.0 && $inventory($syn['inventory']) !== (float)$syn['inventory']['purchases'], 'inventory_consumption_differs_from_purchase');
    $check($inventory(array_replace($syn['inventory'], ['closing' => null])) === null && $inventory(array_replace($syn['inventory'], ['closing' => 500])) === null && $inventory(array_fill_keys(array_keys($syn['inventory']), 0)) === 0.0, 'inventory_missing_negative_and_explicit_zero');
    $check($inventory(array_replace($syn['inventory'], ['writeoff' => 10])) === 140.0, 'writeoff_not_double_counted_as_consumption');
    $check($s['source_switch']['checked_observed'] && !$s['source_switch']['propagation_verified'] && !$s['source_switch']['restored_checked'] && $s['source_switch']['selected_value_after_check'] === $s['selected_amenities'], 'source_switch_recorded_as_unverified');

    // Existing pure calculator reuse. No App initialize and no save/preview service call.
    $calculatorPath = $root . '/app/service/InvestmentScenarioCalculator.php';
    $calculatorHash = strtoupper((string)hash_file('sha256', $calculatorPath));
    require_once $calculatorPath;
    $calculator = new \app\service\InvestmentScenarioCalculator();
    $input = [
        'scenario_name' => 'JHIRA成本外部样例等价回放（非酒店事实）', 'as_of' => '2026-10-01', 'currency' => 'CNY',
        'rooms' => $s['rooms'], 'leased_rooms' => $s['rooms'], 'years' => 8,
        'adr_first_year' => $s['net_adr'], 'occupancy_first_year' => $s['occupancy'], 'occupancy_mature' => $s['occupancy'], 'mature_from_year' => 1,
        'adr_growth_rate' => 0, 'adr_growth_from_year' => 2, 'operating_cost_basis' => 'fixed_variable', 'operating_cost_per_night' => $v,
        'fixed_annual_operating_cost' => ($s['fixed_cost'] - $s['rent']) * 12, 'operating_cost_growth_rate' => 0,
        'monthly_rent_per_room' => $s['rent'] / $s['rooms'], 'rent_escalations' => [], 'rent_free_months' => 0, 'construction_months' => 0,
        'renovation_cash' => 3000000, 'franchise_cash' => 0, 'refundable_deposit_cash' => 0, 'other_initial_cash' => 0, 'working_capital_cash' => 0,
        'depreciable_amount' => 0, 'depreciation_years' => 8, 'management_fee_rate' => 0, 'days_per_year' => 360, 'cash_adjustments' => [],
        'source_label' => '外部示例条件回放：到手价、360天、现金调整未知', 'source_ref' => 'https://joydo.us.ci/index.full', 'reference_example' => true,
    ];
    $local = $calculator->calculate($input);
    $row = $local['annual_rows'][0];
    $check($near($row['revenue'], $standard['revenue'] * 12) && $near($row['operating_cost'] + $row['rent'], $standard['cost'] * 12) && $near($row['pretax_cash_proxy'], 448824) && $near($local['break_even']['cash_proxy_occupancy'], $standard['breakeven']), 'existing_calculator_standard_mapping');
    $check($local['status'] === 'partial' && $local['source'] === 'scenario_assumption' && $row['scenario_cashflow'] === null && $local['scenario_payback'] === null, 'existing_calculator_unknown_cash_not_zero_filled');
    $doubleRent = $calculator->calculate(array_replace($input, ['fixed_annual_operating_cost' => $s['fixed_cost'] * 12]));
    $check($near($row['pretax_cash_proxy'] - $doubleRent['annual_rows'][0]['pretax_cash_proxy'], $s['rent'] * 12), 'existing_calculator_rent_duplicate_exposed');
    $localPrecision = $calculator->calculate(array_replace($input, ['operating_cost_per_night' => $precision + $s['utilities'] + $s['laundry']]));
    $check($near($localPrecision['annual_rows'][0]['pretax_cash_proxy'], $conditional['cash_proxy'] * 12) && $near($localPrecision['break_even']['cash_proxy_occupancy'], $conditional['breakeven']) && $localPrecision['payback']['total_years'] === null, 'existing_calculator_conditional_precision_mapping');
    $check(strtoupper((string)hash_file('sha256', $calculatorPath)) === $calculatorHash, 'calculator_fingerprint_stable_during_replay');
    return [
        'status' => 'passed', 'cases_passed' => count($passed), 'cases' => $passed,
        'standard_sample' => $standard, 'conditional_precision_sample' => $conditional,
        'configuration_total' => $s['precision_configuration_total'], 'source_assumed_precision' => $precision,
        'mixed_denominator_cost_ratio' => $mixed, 'same_scope_cost_per_available' => $costPerAvailable,
        'local_mapping' => ['model_version' => $local['model_version'], 'calculator_sha256' => $calculatorHash, 'annualization_days' => 360, 'annual_cash_proxy' => $row['pretax_cash_proxy'], 'cash_adjustments' => 'unknown', 'scenario_cashflow' => null, 'saved' => false],
        'source_switch_propagation_verified' => false, 'business_integration' => false,
        'scope' => 'reference_arithmetic_and_existing_pure_calculator_no_database_or_business_write',
    ];
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        echo json_encode(joydoHotelCostReplay(dirname(__DIR__)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    } catch (Throwable $error) {
        $code = preg_match('/^[a-z_]+(?::[a-z_]+)?$/D', $error->getMessage()) ? $error->getMessage() : 'cost_runtime_validation_failed';
        fwrite(STDERR, json_encode(['status' => 'failed', 'code' => $code, 'exception' => get_class($error)], JSON_THROW_ON_ERROR) . PHP_EOL);
        exit(2);
    }
}
