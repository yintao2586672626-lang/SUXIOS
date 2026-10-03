<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Investor cash ledger. Monetary arithmetic stays in integer fen. */
class InvestmentPaybackCalculator
{
    public const SOURCE_LABEL = '人工录入，来源未独立核验';
    private const MAX_AMOUNT_FEN = 99999999999999;

    public static function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
    }

    public static function date(string $value, string $label = '日期'): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException($label . '必须是有效的 YYYY-MM-DD 日期');
        }
        return $value;
    }

    public static function period(string $value, string $precision): array
    {
        if ($precision === 'day') {
            return [self::date($value, '资金日期'), $value];
        }
        if ($precision !== 'month' || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $value)) {
            throw new InvalidArgumentException('资金日期必须与 day/month 粒度匹配');
        }
        $start = self::date($value . '-01', '资金月份');
        return [$start, (new DateTimeImmutable($start))->format('Y-m-t')];
    }

    /** Accept exact decimal yuan strings, never silently round fractional fen. */
    public static function fen($value, bool $signed = false, string $label = '金额'): int
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new InvalidArgumentException($label . '必须是元金额，最多两位小数');
        }
        $text = trim((string)$value);
        $pattern = $signed ? '/^(-?)(\d{1,12})(?:\.(\d{1,2}))?$/D' : '/^()(\d{1,12})(?:\.(\d{1,2}))?$/D';
        if (!preg_match($pattern, $text, $match)) {
            throw new InvalidArgumentException($label . '必须是元金额，最多两位小数，不能使用指数或单位');
        }
        $fen = (int)$match[2] * 100 + (int)str_pad($match[3] ?? '', 2, '0');
        if ($fen > self::MAX_AMOUNT_FEN) {
            throw new InvalidArgumentException($label . '超过支持范围');
        }
        return ($match[1] ?? '') === '-' ? -$fen : $fen;
    }

    public static function yuan(int $fen): string
    {
        $absolute = abs($fen);
        return ($fen < 0 ? '-' : '') . intdiv($absolute, 100) . '.' . str_pad((string)($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    public function summarize(array $project, array $entries, ?string $asOf = null): array
    {
        $asOf = self::date($asOf ?: (string)($project['forecast_as_of'] ?? self::today()), '截至日');
        if ($asOf > self::today()) {
            throw new InvalidArgumentException('实际资金截至日不能晚于今天');
        }
        $issues = [];
        $openingDate = $project['opening_as_of'] ?? null;
        $openingUsable = $openingDate !== null && $openingDate <= $asOf;
        $beforeOpening = $openingDate !== null && !$openingUsable;
        if ($beforeOpening) {
            $issues[] = 'cutoff_before_opening_balance';
        }
        $invested = $openingUsable ? self::fen($project['opening_invested']) : 0;
        $recovered = $openingUsable ? self::fen($project['opening_recovered'], true) : 0;
        $active = [];
        $hasMonthly = false;
        $firstDate = !empty($project['first_invested_on']) && $project['first_invested_on'] <= $asOf
            ? (string)$project['first_invested_on'] : null;
        $firstPrecision = $firstDate ? 'day' : null;
        $declaredFirstDate = $firstDate !== null;
        foreach ($entries as $entry) {
            if (!empty($entry['voided_at']) || !empty($entry['is_planned'])) {
                continue;
            }
            [$start, $end] = self::period((string)$entry['date'], (string)$entry['precision']);
            if ($start > $asOf) {
                continue;
            }
            if ($end > $asOf) {
                $issues[] = 'monthly_record_extends_beyond_cutoff';
                continue;
            }
            if ($openingDate !== null && $start <= $openingDate) {
                $issues[] = 'entry_overlaps_opening_balance';
                continue;
            }
            if ($beforeOpening) {
                continue;
            }
            $amount = self::fen($entry['amount']);
            if ($entry['kind'] === 'investment') {
                $invested = $this->add($invested, $amount);
                $firstStart = $firstDate !== null && strlen($firstDate) === 7 ? $firstDate . '-01' : $firstDate;
                // Earlier disjoint records still correct a declared date as
                // before. Inferred dates also compare overlapping period
                // starts, retaining month precision on a tie regardless of row order.
                if (!$openingUsable && ($firstStart === null || $end < $firstStart
                    || (!$declaredFirstDate && ($start < $firstStart
                        || ($start === $firstStart && $entry['precision'] === 'month'))))) {
                    $firstDate = $entry['date'];
                    $firstPrecision = $entry['precision'];
                }
            } else {
                $recovered = $this->add($recovered, $entry['kind'] === 'refund' ? -$amount : $amount);
            }
            $active[] = $entry;
            // A confirmed zero receipt has no intra-month cash timing that
            // could change the first crossing. Keep its cutoff-quality checks.
            $hasMonthly = $hasMonthly || ($entry['precision'] === 'month' && $amount !== 0);
        }
        $hasInvestment = $invested > 0 && !$beforeOpening;
        $checkedThrough = $project['history_complete_through'] ?? null;
        $complete = $hasInvestment && $checkedThrough !== null && $checkedThrough >= $asOf && $issues === [];
        if ($checkedThrough === null || $checkedThrough < $asOf) {
            $issues[] = 'history_not_checked_through_cutoff';
        }
        if (!$hasInvestment) {
            $issues[] = 'actual_investment_missing';
        }
        $unrecovered = max($invested - $recovered, 0);
        $excess = max($recovered - $invested, 0);
        $firstPayback = $this->firstPayback($project, $active, $hasMonthly, $openingUsable, $firstDate, $firstPrecision, $checkedThrough);
        $historicalCrossing = in_array($firstPayback['status'], ['confirmed', 'recorded_only', 'opening_already_recovered', 'observed_after_opening'], true);
        $state = !$hasInvestment ? 'draft' : (!$complete ? 'recorded_only' : ($unrecovered === 0 ? 'recovered' : ($historicalCrossing ? 'reopened' : 'unrecovered')));
        if ($hasInvestment && $unrecovered > 0 && $historicalCrossing) {
            $state = 'reopened';
        }
        $forecast = $this->forecast($project, $asOf, $hasInvestment, $unrecovered, $complete, $firstDate);
        return [
            'schema_version' => 'investment-payback.v1',
            'as_of' => $asOf,
            'basis' => 'investor_cash',
            'currency' => 'CNY',
            'invested_amount' => $hasInvestment ? self::yuan($invested) : null,
            'net_recovered_amount' => $hasInvestment ? self::yuan($recovered) : null,
            'unrecovered_amount' => $hasInvestment ? self::yuan($unrecovered) : null,
            'excess_recovered_amount' => $hasInvestment ? self::yuan($excess) : null,
            'recovery_percent' => $hasInvestment ? round($recovered / $invested * 100, 2) : null,
            'state' => $state,
            'first_invested_on' => $firstDate,
            'first_invested_precision' => $firstPrecision,
            'data_quality' => [
                'manual_unverified' => true,
                'source_label' => self::SOURCE_LABEL,
                'history_complete' => $complete,
                'history_complete_through' => $checkedThrough,
                'issues' => array_values(array_unique($issues)),
            ],
            'first_payback' => $firstPayback,
            'forecast' => $forecast,
        ];
    }

    private function firstPayback(array $project, array $entries, bool $monthly, bool $openingUsable, ?string $firstDate, ?string $firstPrecision, ?string $checkedThrough): array
    {
        $result = ['status' => 'unknown', 'date' => null, 'precision' => null, 'elapsed_days' => null, 'elapsed_calendar_months' => null];
        $invested = $openingUsable ? self::fen($project['opening_invested']) : 0;
        $recovered = $openingUsable ? self::fen($project['opening_recovered'], true) : 0;
        if ($openingUsable && $invested > 0 && $recovered >= $invested) {
            $result['status'] = $checkedThrough !== null && $checkedThrough >= $project['opening_as_of'] ? 'opening_already_recovered' : 'recorded_only';
            $result['known_by'] = $project['opening_as_of'];
            $result['observation_basis'] = 'opening_balance';
            return $result;
        }
        if ($monthly) {
            $firstMonthlyPeriod = min(array_map(
                static fn(array $entry): string => substr($entry['date'], 0, 7),
                array_values(array_filter($entries, static fn(array $entry): bool => $entry['precision'] === 'month' && self::fen($entry['amount']) !== 0))
            ));
            $exactPrefix = array_values(array_filter($entries, static fn(array $entry): bool => substr($entry['date'], 0, 7) < $firstMonthlyPeriod));
            $prefixResult = $this->firstPayback($project, $exactPrefix, false, $openingUsable, $firstDate, $firstPrecision, $checkedThrough);
            if ($prefixResult['date'] !== null) {
                // Later month-only additions/refunds do not erase a first
                // crossing already determined from an earlier exact timeline.
                return $prefixResult;
            }
        }
        $buckets = [];
        foreach ($entries as $entry) {
            $key = $monthly ? substr($entry['date'], 0, 7) : $entry['date'];
            $buckets[$key] ??= ['investment' => 0, 'recovery' => 0];
            $amount = self::fen($entry['amount']);
            $bucketKind = $entry['kind'] === 'investment' ? 'investment' : 'recovery';
            $buckets[$key][$bucketKind] = $this->add($buckets[$key][$bucketKind], $entry['kind'] === 'refund' ? -$amount : $amount);
        }
        ksort($buckets);
        foreach ($buckets as $key => $bucket) {
            $invested = $this->add($invested, $bucket['investment']);
            $recovered = $this->add($recovered, $bucket['recovery']);
            if ($invested <= 0 || $recovered < $invested) {
                continue;
            }
            $eventEnd = $monthly ? (new DateTimeImmutable($key . '-01'))->format('Y-m-t') : $key;
            $confirmed = $checkedThrough !== null && $checkedThrough >= $eventEnd;
            $result['status'] = $openingUsable ? 'observed_after_opening' : ($confirmed ? 'confirmed' : 'recorded_only');
            $result['date'] = $key;
            $result['precision'] = $monthly ? 'month' : 'day';
            if ($confirmed && !$openingUsable && $firstDate !== null) {
                $normalizedFirst = strlen($firstDate) === 7 ? $firstDate . '-01' : $firstDate;
                $result['elapsed_calendar_months'] = $this->calendarMonths($normalizedFirst, $monthly ? $key . '-01' : $key, $monthly || $firstPrecision === 'month');
                if (!$monthly && $firstPrecision === 'day') {
                    $result['elapsed_days'] = (int)(new DateTimeImmutable($firstDate))->diff(new DateTimeImmutable($key))->days;
                }
            }
            return $result;
        }
        if ($invested > 0) {
            $result['status'] = 'not_reached';
        }
        return $result;
    }

    private function forecast(array $project, string $asOf, bool $hasInvestment, int $unrecovered, bool $complete, ?string $firstDate): array
    {
        $monthly = $project['expected_monthly_amount'] ?? null;
        $result = [
            'status' => 'missing_investment', 'base_date' => $asOf, 'monthly_amount' => $monthly,
            'remaining_months' => null, 'remaining_months_exact' => null, 'whole_months' => null, 'payback_month' => null, 'full_cycle_months' => null,
            'source' => (string)($project['expected_source'] ?? ''),
            'saved_base_date' => $project['forecast_as_of'] ?? null,
            'assumptions' => ['future_equal_monthly_net_receipts', 'no_new_investment_or_extra_refund', 'no_time_value_of_money'],
        ];
        if (!$hasInvestment) {
            return $result;
        }
        if ($unrecovered === 0) {
            $result['status'] = $complete ? 'already_recovered' : 'trial_recovered';
            return $result;
        }
        if ($monthly === null || $monthly === '') {
            $result['status'] = 'missing_monthly';
            return $result;
        }
        $monthlyFen = self::fen($monthly, true, '预计每月净收回');
        if ($monthlyFen <= 0) {
            $result['status'] = 'non_positive';
            return $result;
        }
        $wholeMonths = intdiv($unrecovered, $monthlyFen) + ($unrecovered % $monthlyFen === 0 ? 0 : 1);
        $result['status'] = $complete ? 'ready' : 'trial';
        $result['remaining_months'] = round($unrecovered / $monthlyFen, 1);
        $result['remaining_months_exact'] = $unrecovered / $monthlyFen;
        $result['whole_months'] = $wholeMonths;
        // Anchor on month start to avoid PHP's Jan 31 + 1 month => March rollover.
        if ($wholeMonths <= 120000) {
            $result['payback_month'] = (new DateTimeImmutable(substr($asOf, 0, 7) . '-01'))->modify('+' . $wholeMonths . ' months')->format('Y-m');
        }
        if ($firstDate !== null) {
            $normalizedFirst = strlen($firstDate) === 7 ? $firstDate . '-01' : $firstDate;
            $result['full_cycle_months'] = round($this->calendarMonths($normalizedFirst, $asOf, strlen($firstDate) === 7) + $unrecovered / $monthlyFen, 1);
        }
        return $result;
    }

    private function calendarMonths(string $start, string $end, bool $monthOnly): int
    {
        $months = ((int)substr($end, 0, 4) - (int)substr($start, 0, 4)) * 12 + (int)substr($end, 5, 2) - (int)substr($start, 5, 2);
        return max(0, $months - (!$monthOnly && substr($end, 8, 2) < substr($start, 8, 2) ? 1 : 0));
    }

    private function add(int $left, int $right): int
    {
        if (($right > 0 && $left > PHP_INT_MAX - $right) || ($right < 0 && $left < PHP_INT_MIN - $right)) {
            throw new InvalidArgumentException('累计金额超出整数分支持范围');
        }
        return $left + $right;
    }
}
