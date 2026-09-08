<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use InvalidArgumentException;

/** Pure, CNY/yuan scenario arithmetic. It never loads operating facts or writes data. */
final class QuantOperatingScenarioService
{
    public function normalize(array $raw, array $input): array
    {
        foreach (['case_type', 'case_name', 'start_month', 'evidence_basis', 'source_note', 'currency', 'monetary_unit'] as $key) {
            if (!is_string($raw[$key] ?? null) || trim($raw[$key]) === '') {
                throw new InvalidArgumentException("经营情景缺少参数：{$key}");
            }
        }
        if (!in_array($raw['case_type'], ['existing_hotel', 'proposed_investment'], true)
            || !in_array($raw['evidence_basis'], ['assumptions', 'ota_only', 'manual_pms_cost_unverified'], true)) {
            throw new InvalidArgumentException('经营情景类型或来源范围无效');
        }
        if ($raw['currency'] !== 'CNY' || $raw['monetary_unit'] !== 'yuan') {
            throw new InvalidArgumentException('经营情景仅接受 CNY 元；万元须先换算为元');
        }
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $raw['start_month']) !== 1
            || (int)substr($raw['start_month'], 0, 4) < 1900 || (int)substr($raw['start_month'], 0, 4) > 2200) {
            throw new InvalidArgumentException('经营起始月必须为有效 YYYY-MM');
        }
        $out = array_intersect_key($raw, array_flip(['case_type', 'case_name', 'start_month', 'evidence_basis', 'source_note', 'currency', 'monetary_unit']));
        $out['schema_version'] = 'quant-operating.v1';
        foreach ([
            'horizon_months' => [1, 360, true], 'target_payback_months' => [1, 360, true],
            'ramp_months' => [0, 359, true], 'ramp_start_occupancy' => [0, 100, false],
            'loan_amount' => [0, 100000000000, false], 'annual_interest_rate' => [0, 100, false],
            'loan_term_months' => [0, 360, true], 'opening_cash' => [0, 100000000000, false],
            'minimum_monthly_cashflow' => [0, 100000000000, false],
        ] as $key => [$min, $max, $integer]) {
            $value = $raw[$key] ?? null;
            if (is_bool($value) || !is_numeric($value) || !is_finite((float)$value)
                || (float)$value < $min || (float)$value > $max || ($integer && floor((float)$value) !== (float)$value)) {
                throw new InvalidArgumentException("经营情景参数 {$key} 必须在 {$min} 到 {$max} 之间" . ($integer ? '且为整数' : ''));
            }
            $out[$key] = $integer ? (int)$value : (float)$value;
        }
        $investment = array_sum(array_map(fn($k) => (float)$input[$k], ['decorationInvestment', 'furnitureInvestment', 'openingCost', 'otherInvestment']));
        if ($out['loan_amount'] > $investment || ($out['loan_amount'] > 0 && $out['loan_term_months'] < 1)
            || ($out['loan_amount'] == 0 && ($out['loan_term_months'] !== 0 || $out['annual_interest_rate'] != 0))) {
            throw new InvalidArgumentException('融资金额不得超过总投资；有贷款须填期限，无贷款时期限及利率均须为0');
        }
        if ($out['target_payback_months'] > $out['horizon_months'] || $out['ramp_months'] >= $out['horizon_months']
            || $out['ramp_start_occupancy'] > (float)$input['occupancyRate']) {
            throw new InvalidArgumentException('目标回本月须在测算期内；爬坡期须短于测算期，起始入住率不得高于稳定期');
        }
        if (strlen($out['case_name']) > 240 || strlen($out['source_note']) > 3000) {
            throw new InvalidArgumentException('情景名称或来源说明过长');
        }
        return $out;
    }

    public function calculate(array $input, array $baseResult, bool $withSensitivity = true): array
    {
        $s = $input['operatingScenario'];
        $start = new DateTimeImmutable($s['start_month'] . '-01');
        $n = $s['horizon_months'];
        // The caller supplies unrounded totals; the public result rounds only for display.
        $investment = (float)$baseResult['totalInvestment'];
        $referenceDays = (float)$input['weekdayDays'] + (float)$input['weekendDays'] + (float)$input['holidayDays'];
        $fixed = array_sum(array_map(fn($k) => (float)$input[$k], ['monthlyRent', 'laborCost', 'utilityCost', 'consumableCost', 'maintenanceCost', 'otherFixedCost']));
        $loan = $s['loan_amount'];
        $principal = $loan > 0 ? $loan / $s['loan_term_months'] : 0.0;
        $projectCumulative = -$investment;
        $equityCumulative = -$investment + $loan;
        $peakFunding = max(0.0, -$equityCumulative);
        $series = [[
            'month' => 0, 'date' => $start->format('Y-m-d'), 'days' => 0,
            'phase' => 'initial_investment', 'project_cashflow' => -$investment,
            'equity_cashflow' => $equityCumulative, 'project_cumulative' => $projectCumulative,
            'equity_cumulative' => $equityCumulative, 'cash_balance' => $s['opening_cash'] + $equityCumulative,
            'loan_balance' => $loan,
        ]];
        $rawSeries = $series;
        $targetRentCeiling = INF;
        $rentCeiling = INF;
        $rentReferenceMonth = null;
        $cashBreakEven = 0.0;
        $cashThresholdUnreachable = false;
        $cashGoalViolations = [];
        for ($month = 1; $month <= $n; $month++) {
            $date = $start->modify('+' . ($month - 1) . ' months');
            $days = (int)$date->format('t');
            $progress = $s['ramp_months'] === 0 ? 1.0 : min(1.0, ($month - 1) / $s['ramp_months']);
            $occ = $s['ramp_start_occupancy'] + ((float)$input['occupancyRate'] - $s['ramp_start_occupancy']) * $progress;
            // Carry the exact segment total forward instead of multiplying weighted averages
            // back together. At the stable reference month both ratios are exactly one.
            $rampRatio = $progress >= 1 ? 1.0 : ((float)$input['occupancyRate'] > 0 ? $occ / (float)$input['occupancyRate'] : 0.0);
            $roomRevenue = (float)$baseResult['roomRevenue'] * ($days / $referenceDays) * $rampRatio;
            $commission = $roomRevenue * (float)$input['otaCommissionRate'] / 100;
            $revenue = $roomRevenue + (float)$input['otherIncome'];
            $operating = $revenue - $commission - $fixed;
            $interest = $loan * $s['annual_interest_rate'] / 1200;
            $repayment = min($loan, $principal);
            $debt = $interest + $repayment;
            $loan = max(0.0, $loan - $repayment);
            if ($loan < 0.000001) $loan = 0.0;
            $equity = $operating - $debt;
            $cashTargetStatus = 'not_applicable_ramp';
            if ($progress >= 1) {
                $cashTargetStatus = $equity < $s['minimum_monthly_cashflow'] ? 'not_met' : 'met';
                if ($cashTargetStatus === 'not_met') $cashGoalViolations[] = [
                    'month' => $date->format('Y-m'), 'days' => $days,
                    'equity_cashflow' => round($equity, 2),
                    'shortfall' => round($s['minimum_monthly_cashflow'] - $equity, 2),
                ];
                $monthRentCeiling = $equity + (float)$input['monthlyRent'] - $s['minimum_monthly_cashflow'];
                if ($monthRentCeiling < $rentCeiling) {
                    $rentCeiling = $monthRentCeiling;
                    $rentReferenceMonth = $date->format('Y-m');
                }
                $fullContribution = (float)$input['roomCount'] * $days * (float)$input['adr'] * (1 - (float)$input['otaCommissionRate'] / 100);
                $needed = max(0.0, $fixed + $debt + $s['minimum_monthly_cashflow'] - (float)$input['otherIncome']);
                if ($needed > 0 && $fullContribution <= 0) $cashThresholdUnreachable = true;
                elseif ($needed > 0) $cashBreakEven = max($cashBreakEven, $needed / $fullContribution);
            }
            $projectCumulative += $operating;
            $equityCumulative += $equity;
            $rawSeries[] = ['project_cashflow' => $operating, 'equity_cashflow' => $equity,
                'project_cumulative' => $projectCumulative, 'equity_cumulative' => $equityCumulative];
            $peakFunding = max($peakFunding, -$equityCumulative);
            if ($month >= $s['target_payback_months']) {
                // The target must remain recovered through the rest of the explicit horizon.
                $targetRentCeiling = min($targetRentCeiling, (float)$input['monthlyRent'] + $equityCumulative / $month);
            }
            $series[] = [
                'month' => $month, 'date' => $date->format('Y-m-d'), 'days' => $days,
                'phase' => $progress < 1 ? 'ramp' : 'stable', 'occupancy_pct' => round($occ, 4),
                'minimum_cash_target_status' => $cashTargetStatus,
                'revenue' => round($revenue, 2), 'commission' => round($commission, 2), 'fixed_cost' => round($fixed, 2),
                'interest' => round($interest, 2), 'principal' => round($repayment, 2), 'loan_balance' => round($loan, 2),
                'project_cashflow' => round($operating, 2), 'equity_cashflow' => round($equity, 2),
                'project_cumulative' => round($projectCumulative, 2), 'equity_cumulative' => round($equityCumulative, 2),
                'cash_balance' => round($s['opening_cash'] + $equityCumulative, 2),
            ];
        }
        if ($cashThresholdUnreachable) $cashBreakEven = null;
        $projectPayback = $this->payback($rawSeries, 'project_cumulative', 'project_cashflow', $investment, $loan);
        $equityPayback = $this->payback($rawSeries, 'equity_cumulative', 'equity_cashflow', $investment - $s['loan_amount'], $loan);
        $out = [
            'schema_version' => 'quant-operating.v1', 'status' => 'calculated_assumptions',
            'case_type' => $s['case_type'], 'case_name' => $s['case_name'],
            'start_month' => $s['start_month'], 'end_month' => $start->modify('+' . ($n - 1) . ' months')->format('Y-m'),
            'currency' => 'CNY', 'monetary_unit' => 'yuan', 'horizon_months' => $n,
            'project_payback' => $projectPayback, 'equity_payback' => $equityPayback,
            'funding_required' => round($peakFunding, 2), 'additional_cash_gap' => round(max(0, $peakFunding - $s['opening_cash']), 2),
            'additional_cash_gap_status' => $peakFunding > $s['opening_cash'] ? 'gap' : 'covered',
            'ending_cash_balance' => round($s['opening_cash'] + $equityCumulative, 2), 'outstanding_loan' => round($loan, 2),
            'ending_cash_status' => $s['opening_cash'] + $equityCumulative < 0 ? 'negative' : 'nonnegative',
            'cash_break_even_occupancy' => $cashBreakEven === null ? null : round($cashBreakEven, 6),
            'cash_break_even_status' => $cashBreakEven === null || $cashBreakEven > 1 ? 'unreachable' : 'reachable',
            'monthly_rent_ceiling' => round($rentCeiling, 2), 'rent_range' => $rentCeiling < 0 ? null : [0.0, round($rentCeiling, 2)],
            'rent_status' => $rentCeiling < 0 ? 'unreachable_even_without_rent' : ((float)$input['monthlyRent'] > $rentCeiling ? 'above_ceiling' : 'within_ceiling'),
            'rent_reference_month' => $rentReferenceMonth,
            'monthly_cash_target' => [
                'status' => $cashGoalViolations === [] ? 'met' : 'not_met',
                'scope' => 'every_stable_month_in_horizon', 'minimum' => $s['minimum_monthly_cashflow'],
                'start_month' => $start->modify('+' . $s['ramp_months'] . ' months')->format('Y-m'),
                'violations' => $cashGoalViolations,
            ],
            'target_payback_months' => $s['target_payback_months'], 'target_rent_ceiling' => round($targetRentCeiling, 2),
            'target_status' => $targetRentCeiling < 0 ? 'unreachable_even_without_rent' : ((float)$input['monthlyRent'] <= $targetRentCeiling ? 'met_under_assumptions' : 'not_met'),
            'cashflow_series' => $series,
            'formulas' => [
                '每月房费 = 房间数 × 当月实际天数 × 爬坡或稳定入住率 × 加权ADR；平日/周末/节假日结构按输入基准月加权后平移，未预测未来节假日。',
                '项目现金流 = 房费 + 每月其他收入 − 渠道加权佣金 − 全部月成本；其他收入、能耗及人工等按输入月定额，不随入住率自动变化。',
                '股东现金流 = 项目现金流 − 当期本金 − 期初贷款余额 × 年利率 ÷ 12；贷款于期初到账，等额本金从首月偿还。',
                '初期项目支出包含全部装修/设备/开业/其他投资；已有酒店须自行填本次需收回的投资，历史沉没成本不自动推断。',
                '现金缺口 = max(0, 最大累计股东资金需求 − 自有可用现金)；自有现金不计收入或投资收益。',
                '月现金目标仅适用于爬坡结束后的每个稳定月；逐月用实际天数及当月债务支出核对，未达标月份单独列示，爬坡期由资金缺口覆盖。',
                '月租金上限取所有稳定月各自允许租金的最小值；保本入住率取这些月份所需入住率的最大值。负租金上限表示零租金仍不满足目标。',
                '租金使用基础租金与物业/公区费合计，上限也约束此合计；不能直接把合计上限当作基础租金上限。',
                '目标回本租金上限 = min(当前租金 + 当月累计股东现金流 ÷ 月序号)，检查目标月至期末；其他输入不变。',
                '回本为累计现金流首次非负且至期末未再转负的月份；小数月仅为当月均匀现金流插值，不是精确到账日期。',
                '未折现；无增长、税率推断、资产残值或押金回收。税费应包含在输入成本；结果仅在所列假设与期限内有效。',
            ],
        ];
        if ($withSensitivity) $out['sensitivity'] = $this->sensitivity($input, $baseResult, $out);
        return $out;
    }

    private function payback(array $series, string $cumulativeKey, string $flowKey, float $initial, float $outstanding): array
    {
        $lastNegative = null;
        foreach ($series as $i => $row) if ($row[$cumulativeKey] < 0) $lastNegative = $i;
        $last = count($series) - 1;
        $noPositiveFlow = max(array_column(array_slice($series, 1), $flowKey)) <= 0;
        if ($lastNegative === $last) {
            return ['months' => null, 'status' => $noPositiveFlow ? 'never_within_horizon' : 'not_recovered_within_horizon'];
        }
        if ($initial <= 0 && $lastNegative === null) {
            return ['months' => null, 'status' => $outstanding > 0 ? 'no_initial_outlay_with_debt' : 'no_initial_outlay'];
        }
        if ($lastNegative === null) return ['months' => 0.0, 'status' => 'recovered_within_horizon'];
        $flow = $series[$lastNegative + 1][$flowKey];
        return ['months' => round($lastNegative + abs($series[$lastNegative][$cumulativeKey]) / $flow, 2), 'status' => 'recovered_within_horizon'];
    }

    private function sensitivity(array $input, array $base, array $reference): array
    {
        $rows = [];
        foreach ([
            ['monthlyRent', '租金增加10%', 1.1], ['laborCost', '人工增加10%', 1.1],
            ['utilityCost', '能耗增加10%', 1.1], ['decorationInvestment', '装修增加10%', 1.1],
            ['adr', 'ADR下降10%', 0.9], ['occupancyRate', '稳定入住率下降5个百分点', -5],
            ['annual_interest_rate', '融资年利率增加2个百分点', 2], ['ramp_months', '爬坡期延长3个月', 3],
        ] as [$key, $label, $change]) {
            $adjusted = $input;
            $adjustedBase = $base;
            if (in_array($key, ['annual_interest_rate', 'ramp_months'], true)) {
                if ($key === 'annual_interest_rate' && $input['operatingScenario']['loan_amount'] <= 0) {
                    $rows[] = ['factor' => $key, 'label' => $label, 'status' => 'not_applicable_no_loan'];
                    continue;
                }
                $adjusted['operatingScenario'][$key] = min($key === 'ramp_months' ? $input['operatingScenario']['horizon_months'] - 1 : 100, $input['operatingScenario'][$key] + $change);
            } elseif ($key === 'occupancyRate') {
                $adjusted[$key] = max(0, $input[$key] + $change);
                $adjusted['operatingScenario']['ramp_start_occupancy'] = min($adjusted[$key], $input['operatingScenario']['ramp_start_occupancy']);
            } else {
                $adjusted[$key] *= $change;
                if ($key === 'decorationInvestment') $adjustedBase['totalInvestment'] += $adjusted[$key] - $input[$key];
            }
            if ($key === 'adr' || $key === 'occupancyRate') {
                $adjustedBase['roomRevenue'] *= $input[$key] > 0 ? $adjusted[$key] / $input[$key] : 0.0;
            }
            $result = $this->calculate($adjusted, $adjustedBase, false);
            $rows[] = [
                'factor' => $key, 'label' => $label, 'status' => 'calculated_assumptions',
                'from' => $input[$key] ?? $input['operatingScenario'][$key], 'to' => $adjusted[$key] ?? $adjusted['operatingScenario'][$key],
                'ending_cash_delta' => round($result['ending_cash_balance'] - $reference['ending_cash_balance'], 2),
                'additional_cash_gap' => $result['additional_cash_gap'], 'equity_payback' => $result['equity_payback'],
            ];
        }
        usort($rows, fn($a, $b) => abs($b['ending_cash_delta'] ?? 0) <=> abs($a['ending_cash_delta'] ?? 0));
        return $rows;
    }
}
