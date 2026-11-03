<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use InvalidArgumentException;

/** Calendar interpretation of a scenario, never a verified contract or actual investor recovery. */
final class InvestmentContractDateConstraintService
{
    public const VERSION = 'investment-contract-calendar-v1';

    public function evaluate(?array $constraints, array $result): ?array
    {
        if ($constraints === null) return null;
        $in = (new InvestmentScenarioCashPlanner())->normalizeConstraints($constraints);
        $asOf = $result['input']['as_of'] ?? null;
        if ($asOf !== null) $asOf = InvestmentPaybackCalculator::date($asOf, '测算基准日');
        $missing = [];
        foreach (['contract_start_on', 'contract_end_on', 'contract_source'] as $key) if ($in[$key] === null) $missing[] = $key;
        if (!$in['contract_confirmed']) $missing[] = 'contract_confirmed';
        if ($asOf === null) $missing[] = 'as_of';
        $full = $result['scenario_payback'] ?? null;
        $payback = $full ?? ($result['payback'] ?? null);
        $months = ($payback['total_years'] ?? null) === null ? null : (float)$payback['total_years'] * 12;
        $projection = $asOf !== null && $months !== null ? $this->dateFromMonths($asOf, $months) : null;
        $forecastDate = $projection['date'] ?? null;
        $ready = $full !== null && ($result['status'] ?? null) === 'ready';
        $targetDate = $asOf !== null && $in['target_payback_months'] !== null ? $this->dateFromMonths($asOf, $in['target_payback_months'])['date'] : null;
        $remainingDays = $missing === [] ? max(0, $this->days($asOf, $in['contract_end_on'])) : null;
        $remainingMonths = $missing === [] ? $this->calendarMonths($asOf, $in['contract_end_on']) : null;
        $contractStatus = $missing !== [] ? 'unverified' : ($in['contract_end_on'] < $asOf ? 'expired' : ($in['contract_start_on'] > $asOf ? 'not_started' : 'user_confirmed'));
        $state = static function (?string $limit) use ($asOf, $payback, $ready, $forecastDate): string {
            if ($limit === null || $asOf === null) return 'inputs_missing';
            if ($payback === null) return 'forecast_missing';
            if (!$ready) return 'trial_only';
            if ($forecastDate === null) return 'not_reached_in_horizon';
            return $forecastDate <= $limit ? 'within_limit' : 'beyond_limit';
        };
        $contractPayback = $state($missing === [] ? $in['contract_end_on'] : null);
        if ($contractPayback === 'within_limit' && $forecastDate < $in['contract_start_on']) $contractPayback = 'before_contract_start';
        return [
            'evaluation_version' => self::VERSION, 'source_quality' => 'scenario_assumption',
            'contract_status' => $contractStatus, 'contract_missing_fields' => $missing,
            'contract_remaining_days' => $remainingDays, 'contract_remaining_months' => $remainingMonths,
            'contract_remaining_months_basis' => 'calendar_months_display_only',
            'target_payback_months' => $in['target_payback_months'], 'target_payback_on' => $targetDate,
            'target_status' => $state($targetDate), 'contract_payback_status' => $contractPayback,
            'forecast_payback_months' => $months, 'forecast_payback_on' => $forecastDate,
            'forecast_date_interpolation' => $projection,
            'contract_safety_days' => $ready && $missing === [] && $forecastDate !== null ? $this->days($forecastDate, $in['contract_end_on']) : null,
            'target_safety_days' => $ready && $targetDate !== null && $forecastDate !== null ? $this->days($forecastDate, $targetDate) : null,
            'forecast_basis' => $full !== null ? 'cash_adjusted_assumption' : 'pretax_proxy_only',
            'basis_note' => '按测算基准日加自然月，月末取目标月最后有效日；小数月按相邻自然月锚点间天数向上取日。年度现金线性插值仍是假设，不代表逐月实绩。合同由用户核对，未独立鉴真；不确认投资人实际回本。',
            'actual_cash_written' => false,
        ];
    }

    /** Fractional months use actual days between calendar anchors and round up conservatively. */
    public function dateFromMonths(string $origin, float $months): array
    {
        $origin = InvestmentPaybackCalculator::date($origin, '测算基准日');
        if (!is_finite($months) || $months < 0 || $months > 720) throw new InvalidArgumentException('回本月份须为0至720的有限数字');
        // Remove floating-point noise at exact month boundaries before conservative day rounding.
        if (abs($months - round($months)) < 1.0e-9) $months = (float)round($months);
        $whole = (int)floor($months);
        $fraction = $months - $whole;
        $anchor = $this->monthAnchor($origin, $whole);
        $next = $this->monthAnchor($origin, $whole + 1);
        $span = (int)$anchor->diff($next)->format('%r%a');
        $days = $fraction > 0 ? (int)ceil($fraction * $span) : 0;
        $forecast = $anchor->modify('+' . $days . ' days');
        if (!$this->supportedYear($anchor) || !$this->supportedYear($forecast)) throw new InvalidArgumentException('预测回本日期超出0001至9999年的可用范围');
        return ['date' => $forecast->format('Y-m-d'),
            'whole_months' => $whole, 'fraction_month' => $fraction, 'fraction_days' => $days,
            'anchor_on' => $anchor->format('Y-m-d'), 'next_anchor_on' => $this->supportedYear($next) ? $next->format('Y-m-d') : null, 'anchor_span_days' => $span,
            'basis' => 'annual_linear_interpolation_to_natural_date', 'rounding' => 'ceil_fractional_day'];
    }

    private function monthAnchor(string $origin, int $months): DateTimeImmutable
    {
        $date = new DateTimeImmutable($origin);
        $month = $date->modify('first day of this month')->modify('+' . $months . ' months');
        return $month->setDate((int)$month->format('Y'), (int)$month->format('m'), min((int)$date->format('d'), (int)$month->format('t')));
    }

    private function supportedYear(DateTimeImmutable $date): bool
    {
        return (int)$date->format('Y') >= 1 && (int)$date->format('Y') <= 9999;
    }

    private function days(string $start, string $end): int
    {
        return (int)(new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->format('%r%a');
    }

    private function calendarMonths(string $start, string $end): float
    {
        if ($end <= $start) return 0.0;
        $a = new DateTimeImmutable($start); $b = new DateTimeImmutable($end);
        $whole = ((int)$b->format('Y') - (int)$a->format('Y')) * 12 + (int)$b->format('m') - (int)$a->format('m');
        while ($this->monthAnchor($start, $whole) > $b) $whole--;
        $anchor = $this->monthAnchor($start, $whole);
        if ($anchor == $b) return (float)$whole;
        $next = $this->monthAnchor($start, $whole + 1);
        return round($whole + (int)$anchor->diff($b)->format('%r%a') / (int)$anchor->diff($next)->format('%r%a'), 6);
    }
}
