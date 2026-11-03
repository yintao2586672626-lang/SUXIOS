#!/usr/bin/env php
<?php
declare(strict_types=1);

/** Independent reference arithmetic; never executes website JavaScript or reads hotel records. */
function joydoFieldCodeReplay(): array
{
    $checks = [];
    $check = static function (string $name, bool $ok) use (&$checks): void {
        if (!$ok) {
            throw new RuntimeException('reference_replay_failed');
        }
        $checks[] = $name;
    };
    $available = 50 * 30;
    $sold = $available * 0.65;
    $revenue = $sold * 160;
    $cost = 92000 + $sold * 27.28;
    $profit = $revenue - $cost;
    $check('stable_month_sold_revenue_cost_profit', $sold === 975.0 && $revenue === 156000.0 && $cost === 118598.0 && $profit === 37402.0);
    $check('breakeven_occupancy', round(92000 / ($available * (160 - 27.28)) * 100, 2) === 46.21);
    $check('same_period_cost_ratio', round($cost / $revenue * 100, 2) === 76.02);
    $mixedRatio = (92000 / $available + 27.28) / (160 * 0.65) * 100;
    $check('mixed_available_sold_denominator_counterexample', round($mixedRatio, 2) === 85.21 && round($mixedRatio, 2) !== round($cost / $revenue * 100, 2));
    $oneYearTarget = (92000 + 3000000 / 12) / ($available * (160 - 27.28));
    $check('unreachable_target_not_clamped', $oneYearTarget > 1 && round($oneYearTarget * 100, 2) === 171.79);
    $check('nonpositive_contribution_has_no_finite_target', 160 - 160 <= 0);
    $check('zero_occupancy_has_no_finite_breakeven_price', $available * 0.0 === 0.0);
    $check('precision_coefficient_not_actual_usage', round(2.0 * 0.8, 2) === 1.6 && 1.6 !== 2.0);
    $check('valid_zero_requires_null_check', (0.0 ?? 18.0) === 0.0 && (0.0 ?: 18.0) === 18.0);
    $principal = 600000.0;
    $monthlyInterest = $principal * 0.12 / 12;
    $lastDebtService = $principal + $monthlyInterest;
    $cashAfterTwelve = 50000 * 12 - ($principal + $monthlyInterest * 12);
    $check('interest_only_tail_payment', $lastDebtService === 606000.0 && $cashAfterTwelve === -72000.0);
    $start = new DateTimeImmutable('2026-02-01');
    $end = new DateTimeImmutable('2026-03-01');
    $check('contract_compare_dates_not_average_month', $start->modify('+1 month') === $end || $start->modify('+1 month')->format('Y-m-d') === $end->format('Y-m-d'));
    $check('average_month_can_misclassify_natural_month', $start->diff($end)->days / 30.4375 < 1);
    $normal = static fn(float $value, float $min, float $max): float => $max === $min ? 50 : ($value - $min) / ($max - $min) * 100;
    $check('sci_sample_change_changes_same_hotel_score', $normal(20, 10, 30) === 50.0 && $normal(20, 10, 110) === 10.0);
    $check('unknown_metric_not_zero', null !== 0 && null !== 50);
    $sourceRate = static fn(float $value): float => abs($value) <= 1 ? $value * 100 : $value;
    $check('one_percentage_point_cannot_be_guessed_from_value', $sourceRate(1.0) === 100.0 && $sourceRate(0.5) === 50.0);
    $sourceMissingConversion = ((null ?? 0) + 10) / 2;
    $check('missing_channel_rate_must_not_halve_available_rate', $sourceMissingConversion === 5 && $sourceMissingConversion !== 10);
    $equalChannelAverage = (1.0 + 10.0) / 2;
    $visitorWeighted = (1000 * 1.0 + 10 * 10.0) / 1010;
    $check('channel_mean_differs_from_visitor_weighted_rate', $equalChannelAverage === 5.5 && round($visitorWeighted, 2) === 1.09);
    $check('module_ready_not_human_approval', ['module_status' => 'ready', 'human_review_status' => 'pending_review']['human_review_status'] !== 'approved');
    return [
        'status' => 'passed', 'checks' => $checks, 'cases_passed' => count($checks),
        'scope' => 'independent_synthetic_arithmetic_and_counterexamples',
        'business_integration' => false, 'website_code_executed' => false,
        'website_save_or_export_verified' => false, 'real_hotel_or_ota_fact' => false,
        'sample' => ['sold_room_nights' => $sold, 'revenue' => $revenue, 'cost' => $cost, 'profit' => $profit,
            'cost_ratio_percent' => round($cost / $revenue * 100, 2), 'mixed_denominator_ratio_percent' => round($mixedRatio, 2)],
    ];
}
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    echo json_encode(joydoFieldCodeReplay(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
}
