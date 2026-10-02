<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;

/** A bounded inverse of the existing annual scenario model. It never changes a saved project. */
final class InvestmentScenarioTargetSolver
{
    public const VERSION = 'investment-target-solver-v1';
    private InvestmentScenarioCalculator $calculator;

    public function __construct(?InvestmentScenarioCalculator $calculator = null)
    {
        $this->calculator = $calculator ?? new InvestmentScenarioCalculator();
    }

    public function solve(array $scenario, array $request): array
    {
        $input = $this->calculator->normalize($scenario);
        $missing = [];
        $variable = $request['solve_for'] ?? null;
        if ($variable === null || $variable === '') $missing[] = 'target_request.solve_for';
        elseif (!in_array($variable, ['adr', 'occupancy'], true)) throw new InvalidArgumentException('反求变量须为adr或occupancy');
        $target = $request['target_payback_months'] ?? ($input['decision_constraints']['target_payback_months'] ?? null);
        if ($target === null || $target === '') $missing[] = 'decision_constraints.target_payback_months';
        elseif (!is_numeric($target) || !is_finite((float)$target) || (int)$target != $target || $target < 1 || $target > 360) throw new InvalidArgumentException('目标回本月数须为1至360的整数');
        $bounds = $request['bounds'] ?? null;
        if ($bounds !== null && !is_array($bounds)) throw new InvalidArgumentException('求解边界须为对象');
        foreach (['lower', 'upper'] as $key) {
            if (($bounds[$key] ?? null) === null || $bounds[$key] === '') $missing[] = 'target_request.bounds.' . $key;
            elseif (!is_numeric($bounds[$key]) || !is_finite((float)$bounds[$key]) || $bounds[$key] < 0 || $bounds[$key] > ($variable === 'occupancy' ? 1 : 1000000000000)) throw new InvalidArgumentException('求解边界须为合法的非负有限数字；入住率不得超过100%');
        }
        if ($bounds !== null && isset($bounds['lower'], $bounds['upper']) && $bounds['lower'] !== '' && $bounds['upper'] !== '' && $bounds['lower'] >= $bounds['upper']) throw new InvalidArgumentException('求解上界须大于下界');
        $shape = $request['occupancy_shape'] ?? null; $ratio = null;
        if ($variable === 'occupancy') {
            if ($shape === null || $shape === '') $missing[] = 'target_request.occupancy_shape';
            elseif (!in_array($shape, ['preserve_ratio', 'constant_all_years'], true)) throw new InvalidArgumentException('入住率形状须为preserve_ratio或constant_all_years');
            elseif ($shape === 'preserve_ratio') {
                if ($input['occupancy_first_year'] === null) $missing[] = 'occupancy_first_year';
                if ($input['occupancy_mature'] === null || $input['occupancy_mature'] <= 0) $missing[] = 'occupancy_mature.positive_profile';
                if ($input['occupancy_first_year'] !== null && $input['occupancy_mature'] !== null && $input['occupancy_mature'] > 0) {
                    $ratio = $input['occupancy_first_year'] / $input['occupancy_mature'];
                    if (!is_finite($ratio)) throw new InvalidArgumentException('首年与成熟入住率比例超出可计算范围，请调整入住率形状');
                }
            }
        }
        $normalizedRequest = ['solve_for' => $variable, 'target_payback_months' => $target === null || $target === '' ? null : (int)$target,
            'bounds' => $bounds === null ? null : ['lower' => ($bounds['lower'] ?? null) === null || ($bounds['lower'] ?? null) === '' ? null : (float)$bounds['lower'], 'upper' => ($bounds['upper'] ?? null) === null || ($bounds['upper'] ?? null) === '' ? null : (float)$bounds['upper']],
            'occupancy_shape' => $variable === 'occupancy' ? $shape : null, 'first_year_to_mature_ratio' => $ratio];
        $base = ['status' => 'inputs_missing', 'reason' => 'required_inputs_missing', 'solver_version' => self::VERSION,
            'model_version' => InvestmentScenarioCalculator::MODEL_VERSION, 'source_quality' => 'scenario_assumption',
            'request' => $normalizedRequest, 'missing_fields' => array_values(array_unique($missing)),
            'input_digest' => $this->digest($input), 'model_digest' => $this->digest(['model_version' => InvestmentScenarioCalculator::MODEL_VERSION, 'input' => $input, 'request' => $normalizedRequest]),
            'bounds' => $normalizedRequest['bounds'], 'effective_bounds' => null, 'solved_value' => null,
            'target_reached' => false, 'iterations' => 0, 'tolerance' => $variable === 'occupancy' ? 0.000001 : 0.01,
            'suggested_input' => null, 'forward_result' => null, 'solution_digest' => null, 'boundary_results' => null,
            'actual_cash_written' => false, 'external_write_authorized' => false,
            'basis' => 'annual_cash_adjusted_first_recovery_including_construction',
            'basis_note' => '反求复用当前年度正向模型，税费、融资净现金、资本支出及营运资金等必须完整。ADR调整首年净房价并保留增长规则；入住率按明确形状调整。月度cash_plan为独立假设，不自动进入年度回本，不代表投资人实际收回。有限上界无法达标仅表示在本次边界与预测期限内不可达。',
        ];
        if ($missing !== []) return $base;
        $lower = (float)$bounds['lower']; $upper = (float)$bounds['upper'];
        if ($variable === 'occupancy' && $ratio !== null && $ratio > 1) $upper = min($upper, 1 / $ratio);
        $base['effective_bounds'] = ['lower' => $lower, 'upper' => $upper];
        if ($upper < $lower) return array_replace($base, ['status' => 'no_solution', 'reason' => 'occupancy_shape_exceeds_capacity']);
        $input['decision_constraints'] = (new InvestmentScenarioCashPlanner())->normalizeConstraints(array_replace($input['decision_constraints'] ?? [], ['target_payback_months' => (int)$target]));
        $candidate = static function (float $value) use ($input, $variable, $shape, $ratio): array {
            $out = $input;
            if ($variable === 'adr') $out['adr_first_year'] = $value;
            else {
                $out['occupancy_mature'] = $value;
                $out['occupancy_first_year'] = $shape === 'constant_all_years' ? $value : min(1.0, $value * $ratio);
            }
            return $out;
        };
        $lowResult = $this->forward($candidate($lower));
        if (($lowResult['status'] ?? null) !== 'ready') {
            $unknown = $lowResult['missing_fields'] ?? [];
            foreach ($lowResult['annual_rows'] as $row) foreach ($row['cash_adjustments_missing'] as $field) $unknown[] = 'cash_adjustments.' . $row['year'] . '.' . $field;
            return array_replace($base, ['missing_fields' => array_values(array_unique($unknown)), 'forward_result' => $lowResult]);
        }
        $highResult = $this->forward($candidate($upper));
        $base['boundary_results'] = ['lower' => $this->boundary($lower, $lowResult, (int)$target), 'upper' => $this->boundary($upper, $highResult, (int)$target)];
        if ($this->reached($lowResult, (int)$target)) return $this->solution($base, $lower, $lowResult, 0, 'lower_bound_satisfies');
        $positive = false; $negative = false;
        foreach ($lowResult['annual_rows'] as $index => $row) {
            // Only years which can affect first recovery within the requested deadline matter.
            if ($index * 12 + $input['construction_months'] >= $target) break;
            $change = $highResult['annual_rows'][$index]['scenario_cashflow'] - $row['scenario_cashflow'];
            if ($change > 0) $positive = true;
            if ($change < 0) $negative = true;
        }
        if (!$positive) return array_replace($base, ['status' => 'no_solution', 'reason' => $negative ? 'non_positive_cash_response' : 'no_positive_cash_response']);
        if ($negative) return array_replace($base, ['status' => 'unsupported_non_monotonic', 'reason' => 'mixed_annual_cash_response']);
        if (!$this->reached($highResult, (int)$target)) return array_replace($base, ['status' => 'unreachable', 'reason' => 'upper_bound_or_horizon_insufficient', 'forward_result' => $highResult]);
        $iterations = 0;
        while ($upper - $lower > $base['tolerance'] && $iterations < 80) {
            $middle = $lower + ($upper - $lower) / 2;
            if ($middle === $lower || $middle === $upper) break;
            $middleResult = $this->forward($candidate($middle)); $iterations++;
            if ($this->reached($middleResult, (int)$target)) { $upper = $middle; $highResult = $middleResult; }
            else $lower = $middle;
        }
        $base['final_bracket'] = ['lower' => $lower, 'upper' => $upper];
        return $this->solution($base, $upper, $highResult, $iterations, 'bounded_minimum_feasible');
    }

    private function forward(array $input): array
    {
        $result = $this->calculator->calculate($input);
        $result['decision_constraints'] = (new InvestmentContractDateConstraintService())->evaluate($result['input']['decision_constraints'], $result);
        return $result;
    }

    private function reached(array $result, int $target): bool
    {
        $years = $result['scenario_payback']['total_years'] ?? null;
        if (($result['status'] ?? null) !== 'ready' || $years === null || !is_finite((float)$years)) return false;
        $months = (float)$years * 12;
        if (!is_finite($months)) return false;
        // Match the calendar service's existing exact-month floating-point noise rule.
        if (abs($months - round($months)) < 1.0e-9) $months = (float)round($months);
        return $months <= $target;
    }

    private function boundary(float $value, array $result, int $target): array
    {
        return ['value' => $value, 'target_reached' => $this->reached($result, $target),
            'scenario_payback' => $result['scenario_payback'], 'ending_cumulative_scenario_cashflow' => $result['totals']['ending_cumulative_scenario_cashflow']];
    }

    private function solution(array $base, float $value, array $result, int $iterations, string $reason): array
    {
        return array_replace($base, ['status' => 'solved', 'reason' => $reason, 'solved_value' => $value,
            'target_reached' => true, 'iterations' => $iterations, 'suggested_input' => $result['input'], 'forward_result' => $result,
            'solution_digest' => $this->digest(['input' => $result['input'], 'result' => $result]), 'missing_fields' => []]);
    }

    private function digest(array $value): string
    {
        return (new KnowledgeContentDigestService())->digest($value);
    }
}
