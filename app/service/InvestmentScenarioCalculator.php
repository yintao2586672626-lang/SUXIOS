<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;

/** Pure scenario arithmetic. It does not load hotel facts or write business data. */
final class InvestmentScenarioCalculator
{
    public const MODEL_VERSION = 'investment-scenario-v1.4';
    private const MAX_CASH = 1000000000000.0;
    private const CASH_KEYS = ['tax_cash', 'financing_net_cash', 'maintenance_capex', 'working_capital_change', 'deposit_refund', 'salvage_cash'];
    private const MONEY_TOTALS = ['revenue', 'operating_cost', 'rent', 'depreciation', 'management_fee', 'pretax_profit', 'pretax_cash_proxy'];

    public function normalize(array $input): array
    {
        $out = [];
        foreach (['scenario_name' => 200, 'as_of' => 10, 'source_label' => 300, 'source_ref' => 1000, 'source_sha256' => 64] as $key => $limit) {
            $value = $input[$key] ?? null;
            if ($value === '' || $value === null) {
                $out[$key] = null;
            } elseif (!is_string($value) || mb_strlen($value) > $limit) {
                throw new InvalidArgumentException($key . ' must be a bounded string');
            } else {
                $trimmed = trim($value);
                $out[$key] = $trimmed === '' ? null : $trimmed;
            }
        }
        if ($out['as_of'] !== null) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $out['as_of']);
            if ($date === false || $date->format('Y-m-d') !== $out['as_of']) {
                throw new InvalidArgumentException('as_of must be a valid YYYY-MM-DD date');
            }
        }
        if ($out['source_sha256'] !== null && !preg_match('/^[a-fA-F0-9]{64}$/D', $out['source_sha256'])) {
            throw new InvalidArgumentException('source_sha256 must contain 64 hexadecimal characters');
        }
        if ($out['source_sha256'] !== null) {
            $out['source_sha256'] = strtolower($out['source_sha256']);
        }
        $out['currency'] = $input['currency'] ?? 'CNY';
        if ($out['currency'] !== 'CNY') {
            throw new InvalidArgumentException('currency must be CNY');
        }
        $reference = $input['reference_example'] ?? false;
        if (!is_bool($reference) && !in_array($reference, [0, 1, '0', '1'], true)) {
            throw new InvalidArgumentException('reference_example must be boolean');
        }
        $out['reference_example'] = in_array($reference, [true, 1, '1'], true);

        foreach (['rooms' => [1, 100000], 'leased_rooms' => [0, 100000], 'years' => [1, 30],
            'mature_from_year' => [1, 30], 'adr_growth_from_year' => [2, 30],
            'depreciation_years' => [1, 100], 'days_per_year' => [1, 366]] as $key => [$min, $max]) {
            $out[$key] = $this->number($input[$key] ?? null, $key, $min, $max, true);
        }
        foreach (['adr_first_year', 'operating_cost_per_night', 'fixed_annual_operating_cost', 'monthly_rent_per_room',
            'renovation_cash', 'franchise_cash', 'refundable_deposit_cash', 'other_initial_cash', 'working_capital_cash',
            'depreciable_amount'] as $key) {
            $out[$key] = $this->number($input[$key] ?? null, $key, 0, self::MAX_CASH);
        }
        foreach (['occupancy_first_year', 'occupancy_mature', 'management_fee_rate'] as $key) {
            $out[$key] = $this->number($input[$key] ?? null, $key, 0, 1);
        }
        foreach (['adr_growth_rate', 'operating_cost_growth_rate'] as $key) {
            $out[$key] = $this->number($input[$key] ?? null, $key, -1, 1);
            if ($out[$key] !== null && $out[$key] <= -1) {
                throw new InvalidArgumentException($key . ' must be greater than -1');
            }
        }
        foreach (['construction_months', 'rent_free_months'] as $key) {
            $out[$key] = $this->number($input[$key] ?? null, $key, 0, 360);
        }
        $basis = $input['operating_cost_basis'] ?? null;
        if ($basis === '') {
            $basis = null;
        }
        if ($basis !== null && !in_array($basis, ['available_room_night', 'occupied_room_night', 'fixed_variable'], true)) {
            throw new InvalidArgumentException('operating_cost_basis is unsupported');
        }
        $out['operating_cost_basis'] = $basis;
        $out['rent_escalations'] = $this->normalizeEscalations($input['rent_escalations'] ?? []);
        $out['cash_adjustments'] = $this->normalizeCashAdjustments($input['cash_adjustments'] ?? []);
        if (isset($input['consumables_cost']) && !is_array($input['consumables_cost'])) {
            throw new InvalidArgumentException('consumables_cost must be an object or null');
        }
        $out['consumables_cost'] = !isset($input['consumables_cost']) ? null : (new ConsumablesCostCalculator())->normalize($input['consumables_cost']);
        foreach (['cash_plan', 'decision_constraints'] as $key) {
            if (isset($input[$key]) && !is_array($input[$key])) throw new InvalidArgumentException($key . ' must be an object or null');
        }
        $planner = new InvestmentScenarioCashPlanner();
        $out['cash_plan'] = $planner->normalize($input['cash_plan'] ?? null);
        $out['decision_constraints'] = $planner->normalizeConstraints($input['decision_constraints'] ?? null);
        $evidenceId = $input['cost_evidence_snapshot_id'] ?? null;
        if ($evidenceId !== null && (!is_numeric($evidenceId) || (int)$evidenceId != $evidenceId || $evidenceId < 1)) throw new InvalidArgumentException('cost_evidence_snapshot_id must be a positive identifier');
        $evidenceDigest = $input['cost_evidence_digest'] ?? null;
        if ($evidenceDigest === '') $evidenceDigest = null;
        if ($evidenceDigest !== null && (!is_string($evidenceDigest) || !preg_match('/^[a-f0-9]{64}$/Di', $evidenceDigest))) throw new InvalidArgumentException('cost_evidence_digest must be SHA256');
        $confirmed = $input['cost_evidence_confirmed'] ?? false;
        if (!is_bool($confirmed)) throw new InvalidArgumentException('cost_evidence_confirmed must be boolean');
        $out['cost_evidence_snapshot_id'] = $evidenceId === null ? null : (int)$evidenceId;
        $out['cost_evidence_digest'] = $evidenceDigest === null ? null : strtolower($evidenceDigest);
        $out['cost_evidence_confirmed'] = $confirmed;
        return $out;
    }

    public function referenceExample(): array
    {
        return $this->normalize([
            'scenario_name' => '清远酒店投资表方法参考示例', 'as_of' => null, 'currency' => 'CNY',
            'rooms' => 153, 'leased_rooms' => 153, 'years' => 10, 'adr_first_year' => 250,
            'occupancy_first_year' => 0.70, 'occupancy_mature' => 0.85, 'mature_from_year' => 2,
            'adr_growth_rate' => 0.03, 'adr_growth_from_year' => 3,
            'operating_cost_basis' => 'available_room_night', 'operating_cost_per_night' => 100,
            'fixed_annual_operating_cost' => null, 'operating_cost_growth_rate' => 0.02,
            'monthly_rent_per_room' => 1500, 'rent_escalations' => [['year' => 7, 'rate' => 0.08]],
            'rent_free_months' => 0, 'construction_months' => 0,
            'renovation_cash' => 3000000, 'franchise_cash' => 321300, 'refundable_deposit_cash' => 50000,
            'other_initial_cash' => 0, 'working_capital_cash' => 0,
            'depreciable_amount' => 3371300, 'depreciation_years' => 10,
            'management_fee_rate' => 0.035, 'days_per_year' => 365, 'cash_adjustments' => [],
            'source_label' => '投资测算（清远酒店）.xlsx / 加盟托管；未验证来源假设',
            'source_ref' => 'docs/knowledge/qingyuan-investment-20261001/source-manifest.json',
            'source_sha256' => '49b5af7ae8655bb10a505b01da17da1db995314420ad6a45de7a90cac51f94f7',
            'reference_example' => true,
        ]);
    }

    public function calculate(array $input): array
    {
        $in = $this->normalize($input);
        $consumables = $in['consumables_cost'] === null ? null : (new ConsumablesCostCalculator())->evaluate($in['consumables_cost']);
        $calculationInput = $in;
        $derived = ($in['consumables_cost']['mode'] ?? 'manual') === 'derived';
        if ($derived) {
            // Preserve the manual aggregate in the saved input; only the explicit selected breakdown enters arithmetic.
            $calculationInput['operating_cost_per_night'] = $this->number($consumables['effective_operating_cost_per_night'], 'consumables_cost.effective_operating_cost_per_night', 0, self::MAX_CASH);
        }
        $missing = [];
        $required = ['rooms', 'leased_rooms', 'years', 'adr_first_year', 'occupancy_first_year', 'occupancy_mature',
            'mature_from_year', 'adr_growth_rate', 'adr_growth_from_year', 'operating_cost_basis',
            'operating_cost_per_night', 'operating_cost_growth_rate', 'monthly_rent_per_room', 'rent_free_months',
            'construction_months', 'renovation_cash', 'franchise_cash', 'refundable_deposit_cash', 'other_initial_cash',
            'working_capital_cash', 'depreciable_amount', 'depreciation_years', 'management_fee_rate', 'days_per_year'];
        if ($in['operating_cost_basis'] === 'fixed_variable') {
            $required[] = 'fixed_annual_operating_cost';
        }
        foreach ($required as $key) {
            if ($derived && $key === 'operating_cost_per_night') {
                continue; // A missing selected breakdown is explained by its own fields, not the retained manual aggregate.
            }
            if ($calculationInput[$key] === null) {
                $missing[] = $key;
            }
        }
        if ($derived) {
            $missing = array_merge($missing, $consumables['missing_fields']);
            if (!in_array($in['operating_cost_basis'], ['occupied_room_night', 'fixed_variable'], true)) {
                $missing[] = 'consumables_cost.occupied_room_night_basis';
            }
        }
        $missing = array_values(array_unique($missing));
        $missingIdentity = [];
        $identityWarnings = [];
        foreach (['as_of' => '测算基准日', 'scenario_name' => '方案名称'] as $key => $label) {
            if ($in[$key] === null) {
                $missingIdentity[] = $key;
                $identityWarnings[] = ['code' => 'missing_' . $key,
                    'message' => '缺少' . $label . '，可保留草稿并预览算术结果，情景参数尚不完整。'];
            }
        }
        $base = [
            'status' => 'inputs_missing', 'source' => 'scenario_assumption', 'data_quality' => 'scenario_assumption',
            'model_version' => self::MODEL_VERSION, 'input' => $in, 'missing_fields' => array_merge($missing, $missingIdentity),
            'annual_rows' => [], 'totals' => null, 'initial_cash_total' => null, 'construction_rent_cash' => null,
            'payback' => null, 'scenario_payback' => null, 'break_even' => null,
            'warnings' => $identityWarnings, 'exclusions' => [], 'sensitivity_rows' => [],
            'consumables_cost' => $consumables,
            'effective_operating_cost_per_night' => $derived && !in_array($in['operating_cost_basis'], ['occupied_room_night', 'fixed_variable'], true) ? null : $calculationInput['operating_cost_per_night'],
            'cash_pressure' => (new InvestmentScenarioCashPlanner())->evaluate($in['cash_plan']),
            'decision_constraints' => null,
        ];
        if ($missing !== []) {
            $base['decision_constraints'] = (new InvestmentScenarioCashPlanner())->constraints($in['decision_constraints'], $base);
            return $base;
        }
        $calculated = $this->annualCalculation($calculationInput);
        $base = array_replace($base, $calculated);
        $base['break_even'] = $this->breakEven($calculationInput, $base['annual_rows'][0]);
        $base['sensitivity_rows'] = $this->sensitivity($calculationInput);
        $base['warnings'] = array_merge($identityWarnings, $this->warnings($calculationInput, $base));
        if ($consumables !== null) {
            $base['warnings'][] = ['code' => $derived ? 'consumables_applied' : 'consumables_not_applied',
                'message' => $derived ? '已采用易耗品明细加其他变动成本；原手填汇总仍保留。用品、租金、固定成本、管理费和折旧须分别列示，勿重复计入。' : '易耗品明细仅供核对，当前仍采用手填单位经营成本。'];
        }
        if ($missingIdentity !== []) {
            $base['status'] = 'partial';
        }
        $base['decision_constraints'] = (new InvestmentScenarioCashPlanner())->constraints($in['decision_constraints'], $base);
        return $base;
    }

    private function annualCalculation(array $in): array
    {
        $constructionRent = $in['leased_rooms'] * $in['monthly_rent_per_room']
            * max(0.0, $in['construction_months'] - $in['rent_free_months']);
        $initial = $constructionRent;
        foreach (['renovation_cash', 'franchise_cash', 'refundable_deposit_cash', 'other_initial_cash', 'working_capital_cash'] as $key) {
            $initial += $in[$key];
        }
        $adjustments = [];
        foreach ($in['cash_adjustments'] as $adjustment) {
            $adjustments[$adjustment['year']] = $adjustment;
        }
        $escalations = [];
        foreach ($in['rent_escalations'] as $escalation) {
            $escalations[$escalation['year']] = $escalation['rate'];
        }
        $rows = [];
        $totals = array_fill_keys(self::MONEY_TOTALS, 0.0);
        $totals['scenario_cashflow'] = 0.0;
        $cumulative = -$initial;
        $scenarioCumulative = -$initial;
        $allAdjustmentsKnown = true;
        $missingAdjustments = [];
        $rentFactor = 1.0;
        for ($year = 1; $year <= $in['years']; ++$year) {
            if (isset($escalations[$year])) {
                $rentFactor *= 1 + $escalations[$year];
            }
            $adr = $in['adr_first_year'] * pow(1 + $in['adr_growth_rate'], max(0, $year - $in['adr_growth_from_year'] + 1));
            $occupancy = $year >= $in['mature_from_year'] ? $in['occupancy_mature'] : $in['occupancy_first_year'];
            $availableNights = $in['rooms'] * $in['days_per_year'];
            $occupiedNights = $availableNights * $occupancy;
            $revenue = $occupiedNights * $adr;
            $costNights = $in['operating_cost_basis'] === 'available_room_night' ? $availableNights : $occupiedNights;
            $operatingCost = $costNights * $in['operating_cost_per_night'];
            if ($in['operating_cost_basis'] === 'fixed_variable') {
                $operatingCost += $in['fixed_annual_operating_cost'];
            }
            $operatingCost *= pow(1 + $in['operating_cost_growth_rate'], $year - 1);
            $freeOperatingMonths = max(0.0, $in['rent_free_months'] - $in['construction_months']);
            $paidMonths = 12 - min(12.0, max(0.0, $freeOperatingMonths - ($year - 1) * 12));
            $rent = $in['leased_rooms'] * $in['monthly_rent_per_room'] * $rentFactor * $paidMonths;
            $depreciation = $year <= $in['depreciation_years'] ? $in['depreciable_amount'] / $in['depreciation_years'] : 0.0;
            $managementFee = $revenue * $in['management_fee_rate'];
            $profit = $revenue - $operatingCost - $rent - $depreciation - $managementFee;
            // Equivalent to profit plus depreciation, without cancelling a large non-cash expense.
            $proxy = $revenue - $operatingCost - $rent - $managementFee;
            $cumulative += $proxy;
            $adjustment = $adjustments[$year] ?? array_merge(['year' => $year], array_fill_keys(self::CASH_KEYS, null));
            $unknown = [];
            foreach (self::CASH_KEYS as $key) {
                if ($adjustment[$key] === null) {
                    $unknown[] = $key;
                }
            }
            $scenarioCashflow = null;
            if ($unknown === []) {
                $scenarioCashflow = $proxy - $adjustment['tax_cash'] + $adjustment['financing_net_cash']
                    - $adjustment['maintenance_capex'] - $adjustment['working_capital_change']
                    + $adjustment['deposit_refund'] + $adjustment['salvage_cash'];
                if ($scenarioCumulative !== null) {
                    $scenarioCumulative += $scenarioCashflow;
                }
                $totals['scenario_cashflow'] += $scenarioCashflow;
            } else {
                $allAdjustmentsKnown = false;
                $scenarioCumulative = null;
                $missingAdjustments[] = ['year' => $year, 'fields' => $unknown];
            }
            $row = [
                'year' => $year, 'days' => $in['days_per_year'], 'adr' => $adr, 'occupancy' => $occupancy,
                'revpar' => $adr * $occupancy, 'revenue' => $revenue, 'operating_cost' => $operatingCost,
                'rent' => $rent, 'depreciation' => $depreciation, 'management_fee' => $managementFee,
                'pretax_profit' => $profit, 'pretax_cash_proxy' => $proxy, 'cumulative_cash_proxy' => $cumulative,
                'cash_adjustments' => $adjustment, 'cash_adjustments_missing' => $unknown,
                'scenario_cashflow' => $scenarioCashflow, 'cumulative_scenario_cashflow' => $scenarioCumulative,
            ];
            foreach (self::MONEY_TOTALS as $key) {
                $totals[$key] += $row[$key];
            }
            $rows[] = $row;
        }
        if (!$allAdjustmentsKnown) {
            $totals['scenario_cashflow'] = null;
        }
        $totals['ending_cumulative_cash_proxy'] = $cumulative;
        $totals['ending_cumulative_scenario_cashflow'] = $scenarioCumulative;
        $exclusions = [['code' => 'scenario_cash_not_verified',
            'message' => 'ready仅表示情景参数完整；全部现金调整仍为用户假设，未核验为会计现金流，也未计算股东实际收回、可分配现金或投资回报。']];
        if (!$allAdjustmentsKnown) {
            $exclusions[] = ['code' => 'cash_adjustments_incomplete',
            'message' => '税费、融资净现金、维护资本支出、营运资金变化、押金退还和残值未逐年明确；回本仅按税前经营现金代理计算。',
                'missing_by_year' => $missingAdjustments];
        }
        return [
            'status' => $allAdjustmentsKnown ? 'ready' : 'partial', 'annual_rows' => $rows, 'totals' => $totals,
            'initial_cash_total' => $initial, 'construction_rent_cash' => $constructionRent,
            'payback' => $this->payback($initial, $rows, 'pretax_cash_proxy', $in['construction_months']),
            'scenario_payback' => $allAdjustmentsKnown ? $this->payback($initial, $rows, 'scenario_cashflow', $in['construction_months']) : null,
            'exclusions' => $exclusions,
        ];
    }

    private function payback(float $initial, array $rows, string $key, float $constructionMonths): array
    {
        $previous = -$initial;
        if ($initial === 0.0) {
            return ['status' => 'recovered_exact_boundary', 'basis' => $key, 'operating_years' => 0.0,
                'total_years' => $constructionMonths / 12, 'remaining_cash' => 0.0];
        }
        foreach ($rows as $row) {
            $current = $previous + $row[$key];
            // The first crossing is decisive, even if later cashflows become negative.
            if ($previous < 0 && $current >= 0 && $row[$key] > 0) {
                $exact = $current === 0.0;
                $operatingYears = $exact ? (float) $row['year'] : $row['year'] - 1 + (-$previous / $row[$key]);
                return ['status' => $exact ? 'recovered_exact_boundary' : 'recovered_interpolated', 'basis' => $key,
                    'operating_years' => $operatingYears, 'total_years' => $operatingYears + $constructionMonths / 12,
                    'remaining_cash' => 0.0];
            }
            $previous = $current;
        }
        return ['status' => 'not_recovered_in_horizon', 'basis' => $key, 'operating_years' => null,
            'total_years' => null, 'remaining_cash' => max(0.0, -$previous)];
    }

    private function breakEven(array $in, array $row): array
    {
        $availableNights = $in['rooms'] * $in['days_per_year'];
        $costVariable = $in['operating_cost_basis'] === 'available_room_night' ? 0.0 : $in['operating_cost_per_night'];
        $costFixed = $in['operating_cost_basis'] === 'available_room_night' ? $availableNights * $in['operating_cost_per_night']
            : ($in['operating_cost_basis'] === 'fixed_variable' ? $in['fixed_annual_operating_cost'] : 0.0);
        $contribution = $row['adr'] * (1 - $in['management_fee_rate']) - $costVariable;
        $denominator = $availableNights * $contribution;
        $cashOccupancy = $denominator > 0 ? ($costFixed + $row['rent']) / $denominator : null;
        $profitOccupancy = $denominator > 0 ? ($costFixed + $row['rent'] + $row['depreciation']) / $denominator : null;
        $adrDenominator = $availableNights * $row['occupancy'] * (1 - $in['management_fee_rate']);
        return [
            'year' => 1, 'basis' => 'first_operating_year_assumption', 'profit_occupancy' => $profitOccupancy,
            'cash_proxy_occupancy' => $cashOccupancy,
            'profit_adr_at_input_occupancy' => $adrDenominator > 0 ? ($row['operating_cost'] + $row['rent'] + $row['depreciation']) / $adrDenominator : null,
            'cash_proxy_adr_at_input_occupancy' => $adrDenominator > 0 ? ($row['operating_cost'] + $row['rent']) / $adrDenominator : null,
            'profit_occupancy_feasible' => $profitOccupancy !== null && $profitOccupancy <= 1,
            'cash_proxy_occupancy_feasible' => $cashOccupancy !== null && $cashOccupancy <= 1,
            'status' => $denominator > 0 && $adrDenominator > 0 ? 'calculated' : 'undefined_denominator',
        ];
    }

    private function sensitivity(array $in): array
    {
        $rows = [];
        foreach ([-0.10, 0.0, 0.10] as $adrDelta) {
            foreach ([-0.10, 0.0, 0.10] as $occupancyDelta) {
                $scenario = $in;
                $scenario['adr_first_year'] = $in['adr_first_year'] * (1 + $adrDelta);
                $scenario['occupancy_first_year'] = max(0.0, min(1.0, $in['occupancy_first_year'] + $occupancyDelta));
                $scenario['occupancy_mature'] = $scenario['occupancy_first_year'];
                $calculation = $this->annualCalculation($scenario);
                $rows[] = ['basis' => 'constant_occupancy_all_years', 'adr_delta_rate' => $adrDelta,
                    'occupancy_delta_points' => $occupancyDelta, 'adr_first_year' => $scenario['adr_first_year'],
                    'occupancy_all_years' => $scenario['occupancy_first_year'],
                    'first_year_pretax_profit' => $calculation['annual_rows'][0]['pretax_profit'],
                    'total_pretax_profit' => $calculation['totals']['pretax_profit'],
                    'payback' => $calculation['payback']];
            }
        }
        return $rows;
    }

    private function warnings(array $in, array $result): array
    {
        $warnings = [['code' => 'scenario_assumption', 'message' => '全部输入为经营假设，未验证为酒店事实；输出不代表市场预测或经营效果。'],
            ['code' => 'payback_proxy', 'message' => '税前经营现金代理等于税前利润加折旧；线性回本插值假定当年现金均匀产生。'],
            ['code' => 'sensitivity_constant_occupancy', 'message' => '九格敏感性将首年出租率加减10个百分点后持续用于全部年份，覆盖原爬坡；ADR为首年加减10%，仍沿用ADR增长假设。'],
            ['code' => 'rent_free_from_construction_start', 'message' => '免租月从营建起算；营建期间未免租的租金计入初始现金支出，租金递增按经营年应用。']];
        if ($in['reference_example']) {
            $warnings[] = ['code' => 'reference_example', 'message' => '显式加载的来源方法示例；不得作为新方案默认值或已核验酒店数据。'];
            $warnings[] = ['code' => 'reference_depreciation_unclassified', 'message' => '来源折旧基数3371300元含50000元可退押金及加盟费，资产分类待确认；保留为显式参考假设。'];
        }
        if ($in['days_per_year'] === 365) {
            $warnings[] = ['code' => 'days_assumption', 'message' => '每年365天为可编辑的统一计算假设，未按自然年闰年展开。'];
        }
        if ($in['fixed_annual_operating_cost'] !== null && $in['operating_cost_basis'] !== 'fixed_variable') {
            $warnings[] = ['code' => 'fixed_cost_not_applied', 'message' => '当前运营成本口径不使用固定年运营成本；切换固定加变动口径才计入该项。'];
        }
        foreach (['rent_escalations', 'cash_adjustments'] as $key) {
            foreach ($in[$key] as $row) {
                if ($row['year'] > $in['years']) {
                    $warnings[] = ['code' => 'outside_horizon', 'message' => $key . ' 的第' . $row['year'] . '年超出当前测算期限，未计入结果。'];
                }
            }
        }
        if (!$result['break_even']['profit_occupancy_feasible']) {
            $warnings[] = ['code' => 'profit_break_even_unreachable', 'message' => '当前首年假设下利润盈亏平衡出租率无有效解或超过100%。'];
        }
        return $warnings;
    }

    private function number($value, string $key, float $min, float $max, bool $integer = false)
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value)) {
            throw new InvalidArgumentException($key . ' must be numeric or null');
        }
        $number = (float) $value;
        if (!is_finite($number) || $number < $min || $number > $max || ($integer && floor($number) !== $number)) {
            throw new InvalidArgumentException($key . ' is outside its allowed range');
        }
        return $integer ? (int) $number : $number;
    }

    private function normalizeEscalations($rows): array
    {
        if ($rows === null) {
            return [];
        }
        if (!is_array($rows) || count($rows) > 30) {
            throw new InvalidArgumentException('rent_escalations must be a bounded array');
        }
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('rent_escalations entries must be objects');
            }
            $year = $this->number($row['year'] ?? null, 'rent_escalations.year', 1, 30, true);
            $rate = $this->number($row['rate'] ?? null, 'rent_escalations.rate', -1, 1);
            if ($year === null || $rate === null || $rate <= -1 || isset($seen[$year])) {
                throw new InvalidArgumentException('rent_escalations requires distinct years and rates greater than -1');
            }
            $seen[$year] = true;
            $out[] = ['year' => $year, 'rate' => $rate];
        }
        usort($out, static fn(array $a, array $b): int => $a['year'] <=> $b['year']);
        return $out;
    }

    private function normalizeCashAdjustments($rows): array
    {
        if ($rows === null) {
            return [];
        }
        if (!is_array($rows) || count($rows) > 30) {
            throw new InvalidArgumentException('cash_adjustments must be a bounded array');
        }
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('cash_adjustments entries must be objects');
            }
            $year = $this->number($row['year'] ?? null, 'cash_adjustments.year', 1, 30, true);
            if ($year === null || isset($seen[$year])) {
                throw new InvalidArgumentException('cash_adjustments requires distinct years');
            }
            $seen[$year] = true;
            $normalized = ['year' => $year];
            foreach (self::CASH_KEYS as $key) {
                $min = in_array($key, ['financing_net_cash', 'working_capital_change'], true) ? -self::MAX_CASH : 0;
                $normalized[$key] = $this->number($row[$key] ?? null, 'cash_adjustments.' . $key, $min, self::MAX_CASH);
            }
            $out[] = $normalized;
        }
        usort($out, static fn(array $a, array $b): int => $a['year'] <=> $b['year']);
        return $out;
    }
}
