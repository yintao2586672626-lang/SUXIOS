<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use InvalidArgumentException;

/** Monthly financing assumptions. No loan, payment, contract or ledger is executed. */
final class InvestmentScenarioCashPlanner
{
    public function normalize(?array $plan): ?array
    {
        if ($plan === null) return null;
        $months = $plan['months'] ?? null;
        if ($months === '') $months = null;
        if ($months !== null && (!is_numeric($months) || (int)$months != $months || $months < 1 || $months > 360)) throw new InvalidArgumentException('cash_plan.months 须为1至360个月');
        $startValue = $plan['start_month'] ?? null;
        $start = $startValue === null || $startValue === '' ? null : $this->month($startValue, 'cash_plan.start_month');
        $out = ['start_month' => $start, 'months' => $months === null ? null : (int)$months,
            'opening_liquidity' => $this->money($plan['opening_liquidity'] ?? null, '期初可用现金'),
            'source_label' => $this->text($plan['source_label'] ?? null), 'loans' => [], 'monthly_inputs' => []];
        $loans = $plan['loans'] ?? [];
        $rows = $plan['monthly_inputs'] ?? [];
        if (!is_array($loans) || count($loans) > 20 || !is_array($rows) || count($rows) > 360) throw new InvalidArgumentException('现金计划最多20笔贷款、360个月');
        $seen = [];
        foreach ($loans as $row) {
            if (!is_array($row)) throw new InvalidArgumentException('贷款参数须为对象');
            $id = $this->text($row['id'] ?? null);
            if (!$id || isset($seen[$id])) throw new InvalidArgumentException('贷款编号须非空且唯一');
            $seen[$id] = true;
            $rate = $row['annual_rate'] ?? null;
            $term = $row['term_months'] ?? null;
            if ($rate === '') $rate = null;
            if ($term === '') $term = null;
            $method = $row['method'] ?? null;
            if (($rate !== null && (!is_numeric($rate) || !is_finite((float)$rate) || $rate < 0 || $rate > 1)) || ($term !== null && (!is_numeric($term) || (int)$term != $term || $term < 1 || $term > 360))
                || !in_array($method, ['equal_principal', 'annuity', 'interest_only'], true)) throw new InvalidArgumentException('贷款利率、期限或偿还方式不正确');
            $principal = $this->money($row['principal'] ?? null, '贷款本金');
            if ($principal !== null && InvestmentPaybackCalculator::fen($principal) <= 0) throw new InvalidArgumentException('贷款本金须大于0');
            $funding = $row['funding'] ?? null;
            if ($funding === '') $funding = null;
            if ($funding !== null && !in_array($funding, ['new', 'existing'], true)) throw new InvalidArgumentException('贷款资金口径须为新增放款或存量余额');
            $out['loans'][] = ['id' => $id, 'name' => $this->text($row['name'] ?? null), 'principal' => $principal, 'funding' => $funding,
                'annual_rate' => $rate === null ? null : (float)$rate, 'start_month' => ($row['start_month'] ?? null) === null || $row['start_month'] === '' ? null : $this->month($row['start_month'], '贷款放款月'),
                'term_months' => $term === null ? null : (int)$term, 'method' => $method];
        }
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) throw new InvalidArgumentException('月度现金参数须为对象');
            $month = $this->month($row['month'] ?? null, '现金计划月份');
            if (isset($seen[$month])) throw new InvalidArgumentException('现金计划月份不可重复');
            $seen[$month] = true;
            $out['monthly_inputs'][] = ['month' => $month,
                'operating_net_cash' => $this->money($row['operating_net_cash'] ?? null, '经营净现金', true),
                'capex_cash' => $this->money($row['capex_cash'] ?? null, '资本支出'),
                'other_net_cash' => $this->money($row['other_net_cash'] ?? null, '其他净现金', true)];
        }
        usort($out['monthly_inputs'], fn($a, $b) => strcmp($a['month'], $b['month']));
        return $out;
    }

    public function evaluate(?array $plan): ?array
    {
        if ($plan === null) return null;
        $plan = $this->normalize($plan);
        $rows = []; $missing = []; $schedules = [];
        if ($plan['start_month'] === null || $plan['months'] === null) return ['status' => 'partial', 'source_quality' => 'scenario_assumption',
            'monthly_rows' => [], 'debt_schedule' => [], 'missing_fields' => array_values(array_filter(['cash_plan.start_month' => $plan['start_month'] === null ? 'cash_plan.start_month' : null, 'cash_plan.months' => $plan['months'] === null ? 'cash_plan.months' : null])),
            'minimum_liquidity' => null, 'minimum_month' => null, 'observed_first_shortage_month' => null, 'minimum_dscr' => null, 'ending_liquidity' => null, 'funding_gap' => null, 'basis' => '计划起点或范围未明确；保留草稿，不计算完整现金低谷。'];
        $start = new DateTimeImmutable($plan['start_month'] . '-01');
        $end = $start->modify('+' . ($plan['months'] - 1) . ' months')->format('Y-m');
        $opening = $plan['opening_liquidity'] === null ? null : InvestmentPaybackCalculator::fen($plan['opening_liquidity']);
        if ($opening === null) $missing[] = 'cash_plan.opening_liquidity';
        if (!$plan['source_label']) $missing[] = 'cash_plan.source_label';
        $inputs = array_column($plan['monthly_inputs'], null, 'month');
        foreach ($plan['monthly_inputs'] as $row) if ($row['month'] < $plan['start_month'] || $row['month'] > $end) throw new InvalidArgumentException('现金输入月份超出计划范围');
        $loansMissing = false; $endingDebt = 0; $laterService = 0; $futureLoans = [];
        foreach ($plan['loans'] as $loan) {
            $gap = [];
            foreach (['principal', 'annual_rate', 'start_month', 'term_months', 'funding'] as $key) if ($loan[$key] === null) { $gap[] = $key; $missing[] = 'cash_plan.loans.' . $loan['id'] . '.' . $key; }
            if ($gap !== []) { $loansMissing = true; continue; }
            if ($loan['start_month'] < $plan['start_month']) throw new InvalidArgumentException('计划前已放款的贷款须改为存量余额，并从计划起点填写剩余本金及剩余期限');
            if ($loan['funding'] === 'existing' && $loan['start_month'] !== $plan['start_month']) throw new InvalidArgumentException('存量贷款须从计划起点计息还款；暂不支持未明确计息规则的宽限期');
            if ($loan['start_month'] > $end) $futureLoans[] = $loan['id'];
            $balance = InvestmentPaybackCalculator::fen($loan['principal']);
            $principal = $balance; $rate = $loan['annual_rate'] / 12; $endBalance = $balance;
            $payment = $rate == 0 ? (int)round($principal / $loan['term_months']) : (int)round($principal * $rate / -expm1(-$loan['term_months'] * log1p($rate)));
            $date = new DateTimeImmutable($loan['start_month'] . '-01');
            for ($n = 1; $n <= $loan['term_months']; $n++) {
                $month = $date->modify('+' . ($n - 1) . ' months')->format('Y-m');
                $interest = (int)round($balance * $rate);
                $repayment = $n === $loan['term_months'] ? $balance : ($loan['method'] === 'interest_only' ? 0 : ($loan['method'] === 'annuity' ? min($balance, max(0, $payment - $interest)) : min($balance, (int)round($principal / $loan['term_months']))));
                $balance -= $repayment;
                if ($month <= $end) $endBalance = $balance;
                else $laterService += $repayment + $interest;
                $schedules[] = ['loan_id' => $loan['id'], 'month' => $month, 'principal_payment' => InvestmentPaybackCalculator::yuan($repayment),
                    'interest_payment' => InvestmentPaybackCalculator::yuan($interest), 'debt_service' => InvestmentPaybackCalculator::yuan($repayment + $interest),
                    'remaining_principal' => InvestmentPaybackCalculator::yuan($balance), 'within_plan' => $month <= $end];
            }
            if ($loan['start_month'] <= $end) $endingDebt += $endBalance;
        }
        $balance = $opening; $min = $opening; $minMonth = $plan['start_month']; $minimumPoint = 'opening'; $firstShortage = null; $minimumDscr = null;
        for ($n = 0; $n < $plan['months']; $n++) {
            $month = $start->modify('+' . $n . ' months')->format('Y-m');
            $source = $inputs[$month] ?? [];
            $gap = []; $values = [];
            foreach (['operating_net_cash', 'capex_cash', 'other_net_cash'] as $key) {
                $values[$key] = ($source[$key] ?? null) === null ? null : InvestmentPaybackCalculator::fen($source[$key], $key !== 'capex_cash');
                if ($values[$key] === null) { $gap[] = $key; $missing[] = 'cash_plan.' . $month . '.' . $key; }
            }
            $draw = 0; $principal = 0; $interest = 0;
            foreach ($plan['loans'] as $loan) if ($loan['start_month'] === $month && $loan['funding'] === 'new' && $loan['principal'] !== null) $draw += InvestmentPaybackCalculator::fen($loan['principal']);
            foreach ($schedules as $row) if ($row['month'] === $month) { $principal += InvestmentPaybackCalculator::fen($row['principal_payment']); $interest += InvestmentPaybackCalculator::fen($row['interest_payment']); }
            $service = $principal + $interest;
            $net = $gap === [] && !$loansMissing ? $values['operating_net_cash'] - $values['capex_cash'] + $values['other_net_cash'] + $draw - $service : null;
            $balance = $balance !== null && $net !== null ? $balance + $net : null;
            if ($balance !== null && ($min === null || $balance < $min)) { $min = $balance; $minMonth = $month; $minimumPoint = 'month_end'; }
            if ($balance !== null && $balance < 0 && $firstShortage === null) $firstShortage = $month;
            $dscr = !$loansMissing && $service > 0 && $values['operating_net_cash'] !== null ? $values['operating_net_cash'] / $service : null;
            if ($dscr !== null) $minimumDscr = $minimumDscr === null ? $dscr : min($minimumDscr, $dscr);
            $rows[] = ['month' => $month, 'operating_net_cash' => $source['operating_net_cash'] ?? null, 'capex_cash' => $source['capex_cash'] ?? null,
                'other_net_cash' => $source['other_net_cash'] ?? null, 'loan_draw' => $loansMissing ? null : InvestmentPaybackCalculator::yuan($draw),
                'principal_payment' => $loansMissing ? null : InvestmentPaybackCalculator::yuan($principal), 'interest_payment' => $loansMissing ? null : InvestmentPaybackCalculator::yuan($interest),
                'debt_service' => $loansMissing ? null : InvestmentPaybackCalculator::yuan($service), 'net_cash' => $net === null ? null : InvestmentPaybackCalculator::yuan($net),
                'ending_liquidity' => $balance === null ? null : InvestmentPaybackCalculator::yuan($balance), 'dscr' => $dscr, 'missing_fields' => $gap];
        }
        return ['status' => $missing === [] ? 'ready_assumption' : 'partial', 'source_quality' => 'scenario_assumption', 'monthly_rows' => $rows,
            'debt_schedule' => $schedules, 'missing_fields' => $missing, 'minimum_liquidity' => $missing === [] && $min !== null ? InvestmentPaybackCalculator::yuan($min) : null,
            'minimum_month' => $missing === [] ? $minMonth : null, 'observed_first_shortage_month' => $firstShortage,
            'minimum_point' => $missing === [] ? $minimumPoint : null, 'opening_liquidity' => $plan['opening_liquidity'], 'plan_end_month' => $end,
            'minimum_dscr' => $missing === [] ? $minimumDscr : null, 'ending_liquidity' => $balance === null ? null : InvestmentPaybackCalculator::yuan($balance),
            'funding_gap' => $missing === [] && $min !== null ? InvestmentPaybackCalculator::yuan(max(0, -$min)) : null,
            'ending_debt_principal' => $loansMissing ? null : InvestmentPaybackCalculator::yuan($endingDebt),
            'debt_service_after_horizon' => $loansMissing ? null : InvestmentPaybackCalculator::yuan($laterService), 'loans_after_horizon' => $futureLoans,
            'basis' => '独立月度假设；经营净现金须已扣经营成本、租金及税费，排除本表贷款和资本支出；不计入实际资金台账。放款月末开始还款，现金低谷包括放款前期初现金，仅观察期初与月末。'
                . ($loansMissing ? ' 部分贷款资料未知，不确认完整债务余额。' : ' 计划期末待还本金' . InvestmentPaybackCalculator::yuan($endingDebt) . '元；期后假设本息' . InvestmentPaybackCalculator::yuan($laterService) . '元，不能由本期现金低谷代表全期限压力。')
                . ($futureLoans !== [] ? ' 计划期之后的放款保留在偿债计划中，不计入本期现金来源。' : '')];
    }

    public function normalizeConstraints(?array $input): ?array
    {
        if ($input === null) return null;
        $target = $input['target_payback_months'] ?? null;
        if ($target === '') $target = null;
        if ($target !== null && (!is_numeric($target) || (int)$target != $target || $target < 1 || $target > 360)) throw new InvalidArgumentException('目标回本月数须为1至360');
        $dates = [];
        foreach (['contract_start_on', 'contract_end_on'] as $key) {
            $value = $input[$key] ?? null;
            $dates[$key] = $value === null || $value === '' ? null : InvestmentPaybackCalculator::date((string)$value, '合同日期');
        }
        if ($dates['contract_start_on'] !== null && $dates['contract_end_on'] !== null && $dates['contract_start_on'] > $dates['contract_end_on']) throw new InvalidArgumentException('合同结束日不得早于开始日');
        $confirmed = $input['contract_confirmed'] ?? false;
        if (!is_bool($confirmed)) throw new InvalidArgumentException('合同核对标记须为布尔值');
        return $dates + ['target_payback_months' => $target === null ? null : (int)$target, 'contract_source' => $this->text($input['contract_source'] ?? null), 'contract_confirmed' => $confirmed];
    }

    public function constraints(?array $input, array $result): ?array
    {
        if ($input === null) return null;
        $missing = []; $asOf = $result['input']['as_of'] ?? null;
        foreach (['contract_start_on', 'contract_end_on', 'contract_source'] as $key) if (!$input[$key]) $missing[] = $key;
        if (!$input['contract_confirmed']) $missing[] = 'contract_confirmed';
        if (!$asOf) $missing[] = 'as_of';
        $remaining = $missing === [] ? max(0, (new DateTimeImmutable($asOf))->diff(new DateTimeImmutable($input['contract_end_on']))->days * ($input['contract_end_on'] >= $asOf ? 1 : -1) / 30.4375) : null;
        $full = $result['scenario_payback'] ?? null; $proxy = $result['payback'] ?? null;
        $payback = $full ?? $proxy; $months = ($payback['total_years'] ?? null) === null ? null : $payback['total_years'] * 12;
        $ready = $full !== null && ($result['status'] ?? '') === 'ready';
        $evaluate = static function (?float $limit) use ($payback, $months, $ready): string {
            if ($limit === null) return 'inputs_missing';
            if (!$payback) return 'forecast_missing';
            if (!$ready) return 'trial_only';
            if ($months === null) return 'not_reached_in_horizon';
            return $months <= $limit ? 'within_limit' : 'beyond_limit';
        };
        return ['contract_status' => $missing === [] ? ($input['contract_end_on'] < $asOf ? 'expired' : 'user_confirmed') : 'unverified',
            'contract_missing_fields' => $missing, 'contract_remaining_months' => $remaining === null ? null : round($remaining, 2),
            'target_payback_months' => $input['target_payback_months'], 'target_status' => $evaluate($input['target_payback_months'] === null ? null : (float)$input['target_payback_months']),
            'contract_payback_status' => $evaluate($remaining), 'forecast_payback_months' => $months,
            'forecast_basis' => $full !== null ? 'cash_adjusted_assumption' : 'pretax_proxy_only',
            'basis_note' => '合同为用户核对来源，未独立鉴真；情景从测算基准日计时，含营建期，不确认投资人实际回本。'];
    }

    private function money(mixed $value, string $label, bool $signed = false): ?string
    {
        if ($value === null || $value === '') return null;
        return InvestmentPaybackCalculator::yuan(InvestmentPaybackCalculator::fen($value, $signed, $label));
    }

    private function text(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value) || mb_strlen($value) > 300) throw new InvalidArgumentException('说明须为300字以内文本');
        return trim($value) === '' ? null : trim($value);
    }

    private function month(mixed $value, string $label): string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $value) || substr($value, 0, 4) < '1900' || substr($value, 0, 4) > '2200') throw new InvalidArgumentException($label . '须为有效年月');
        return $value;
    }
}
