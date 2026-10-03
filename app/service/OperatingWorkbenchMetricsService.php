<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

/** Reported daily values and admitted operating facts stay separate. */
final class OperatingWorkbenchMetricsService
{
    public static function date(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Shanghai'));
        if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('workbench_date_invalid');
        return $value;
    }

    public static function month(string $value): string
    {
        self::date($value . '-01');
        return $value;
    }

    public static function number(mixed $value): ?float
    {
        if ($value === null || $value === '') return null;
        if (is_bool($value) || !is_numeric($value) || !is_finite((float)$value) || (float)$value < 0) throw new InvalidArgumentException('workbench_number_invalid');
        return (float)$value;
    }

    public static function finite(float $value): float
    {
        if (!is_finite($value)) throw new InvalidArgumentException('workbench_calculation_non_finite');
        return $value;
    }

    public static function text(mixed $value, int $limit, bool $required = false): string
    {
        if (!is_string($value)) throw new InvalidArgumentException('workbench_text_invalid');
        $value = trim($value);
        if (($required && $value === '') || mb_strlen($value) > $limit || preg_match('/(?:bearer\s+|authorization\s*:|(?:password|cookie|token|secret)\s*[=:])/i', $value)) {
            throw new InvalidArgumentException('workbench_text_invalid');
        }
        return $value;
    }

    public function budget(array $input): array
    {
        $body = ['period_month' => self::month((string)($input['period_month'] ?? '')), 'currency' => 'CNY',
            'fact_scope' => 'whole_hotel', 'source_method' => 'manual_budget', 'source_quality' => 'scenario_assumption',
            'source_ref' => self::text($input['source_ref'] ?? '', 300, true), 'tax_basis' => $input['tax_basis'] ?? 'unknown'];
        if (!in_array($body['tax_basis'], ['tax_included', 'tax_excluded', 'unknown'], true)) throw new InvalidArgumentException('workbench_tax_basis_invalid');
        foreach (['revenue_budget', 'online_target', 'offline_target', 'other_revenue_target', 'break_even_revenue', 'weekly_target'] as $key) $body[$key] = self::number($input[$key] ?? null);
        $body['break_even_basis'] = self::text($input['break_even_basis'] ?? '', 500, $body['break_even_revenue'] !== null);
        $body['tasks'] = $this->tasks($input['tasks'] ?? []);
        $parts = [$body['online_target'], $body['offline_target'], $body['other_revenue_target']];
        if ($body['revenue_budget'] !== null && !in_array(null, $parts, true)
            && abs(self::finite(array_sum($parts)) - $body['revenue_budget']) > .01) throw new InvalidArgumentException('workbench_budget_split_mismatch');
        $body['break_even_definition'] = '人工设定的保本营收门槛；营收差额不是GOP、利润或投资回收现金。';
        return $body;
    }

    public function tasks(mixed $rows): array
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 40) throw new InvalidArgumentException('workbench_tasks_invalid');
        return array_map(function ($row): array {
            if (!is_array($row)) throw new InvalidArgumentException('workbench_tasks_invalid');
            $status = $row['status'] ?? 'pending';
            if (!in_array($status, ['pending', 'in_progress', 'completed'], true)) throw new InvalidArgumentException('workbench_task_status_invalid');
            return ['measure' => self::text($row['measure'] ?? '', 300, true), 'owner' => self::text($row['owner'] ?? '', 100, true),
                'due_date' => self::date((string)($row['due_date'] ?? '')), 'status' => $status,
                'evidence_ref' => self::text($row['evidence_ref'] ?? '', 300, $status === 'completed')];
        }, $rows);
    }

    public function hotelFacts(int $tenant, int $hotel, string $start, string $end): array
    {
        self::date($start); self::date($end);
        $days = (int)(new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->format('%r%a') + 1;
        if ($days < 1 || $days > 31) throw new InvalidArgumentException('workbench_period_invalid');
        $reports = Db::name('daily_reports')->where('tenant_id', $tenant)->where('hotel_id', $hotel)
            ->whereBetween('report_date', [$start, $end])->where('status', 2)->order('id', 'desc')->limit(1001)->select()->toArray();
        if (count($reports) > 1000) throw new RuntimeException('workbench_report_limit', 422);
        $byDate = [];
        foreach ($reports as $row) $byDate[$row['report_date']] ??= $row;
        $series = [];
        for ($date = new DateTimeImmutable($start); $date->format('Y-m-d') <= $end; $date = $date->modify('+1 day')) {
            $day = $date->format('Y-m-d'); $row = $byDate[$day] ?? null;
            $raw = $row ? json_decode((string)$row['report_data'], true, 64, JSON_THROW_ON_ERROR) : [];
            if (!is_array($raw)) throw new RuntimeException('workbench_daily_report_invalid', 409);
            $target = (new OperatingTargetService())->current($tenant, $hotel, $day)['record'] ?? null;
            $admitted = $target && ($target['facts']['fact_scope'] ?? '') === 'whole_hotel'
                && in_array($target['facts']['quality_status'] ?? '', ['verified', 'manual_confirmed'], true)
                && trim((string)($target['facts']['source_reference'] ?? '')) !== '';
            $capturedAt = $target['facts']['fact_captured_at'] ?? null;
            if ($admitted && $capturedAt !== null && new DateTimeImmutable($capturedAt, new DateTimeZone('Asia/Shanghai')) > new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'))) $admitted = false;
            $series[] = ['business_date' => $day, 'daily_report_id' => $row ? (int)$row['id'] : null,
                'source_ref' => $row ? 'daily_reports#' . $row['id'] : null,
                'reported_quality' => $row ? 'manual_unverified' : 'missing',
                'admitted_quality' => $admitted ? $target['facts']['quality_status'] : 'missing',
                'admitted_source_ref' => $admitted ? $target['facts']['source_reference'] : null,
                'admitted_record_id' => $admitted ? $target['id'] : null,
                'admitted_revision_no' => $admitted ? $target['revision_no'] : null, 'fact_captured_at' => $admitted ? $capturedAt : null,
                'admitted_revenue' => $admitted ? self::number($target['facts']['actual_revenue'] ?? null) : null,
                'reported_revenue' => self::number($raw['revenue'] ?? $raw['day_revenue'] ?? null),
                'online_revenue' => self::number($raw['online_revenue'] ?? null), 'offline_revenue' => self::number($raw['offline_revenue'] ?? null),
                'sold_rooms' => self::number($raw['total_rooms'] ?? $raw['day_total_rooms'] ?? null),
                'overnight_rooms' => self::number($raw['overnight_rooms'] ?? null), 'salable_rooms' => self::number($raw['salable_rooms'] ?? null)];
        }
        return $this->summarize($series, $days) + ['series' => $series, 'period_start' => $start, 'period_end' => $end, 'fact_scope' => 'whole_hotel'];
    }

    public function summarize(array $series, int $expectedDays): array
    {
        $out = ['expected_days' => $expectedDays, 'reported_days' => count(array_filter($series, fn($r) => $r['reported_quality'] !== 'missing')),
            'admitted_days' => count(array_filter($series, fn($r) => $r['admitted_revenue'] !== null)), 'status' => 'partial', 'field_coverage' => []];
        foreach (['reported_revenue', 'admitted_revenue', 'online_revenue', 'offline_revenue', 'sold_rooms', 'overnight_rooms', 'salable_rooms'] as $key) {
            $values = array_column($series, $key); $present = array_values(array_filter($values, fn($v) => $v !== null));
            $out['field_coverage'][$key] = count($present);
            $out[$key] = count($present) === $expectedDays ? self::finite(array_sum($present)) : null;
            $out['observed_' . $key] = $present ? self::finite(array_sum($present)) : null;
        }
        $out['combined_occupancy_percent'] = $out['sold_rooms'] !== null && $out['salable_rooms'] > 0 ? self::finite($out['sold_rooms'] / $out['salable_rooms'] * 100) : null;
        $out['overnight_occupancy_percent'] = $out['overnight_rooms'] !== null && $out['salable_rooms'] > 0 ? self::finite($out['overnight_rooms'] / $out['salable_rooms'] * 100) : null;
        $out['status'] = $out['admitted_days'] === $expectedDays ? 'ready' : ($out['reported_days'] ? 'partial' : 'missing');
        return $out;
    }

    public function compare(array $facts, ?array $budget): array
    {
        $inputs = $budget['inputs'] ?? []; $metrics = [];
        foreach (['revenue_budget' => 'admitted_revenue', 'online_target' => 'online_revenue', 'offline_target' => 'offline_revenue'] as $key => $actualKey) {
            $target = $inputs[$key] ?? null; $actual = $facts[$actualKey] ?? null;
            $metrics[$key] = ['target' => $target, 'actual' => $actual, 'actual_quality' => $actualKey === 'admitted_revenue' ? 'admitted_fact' : 'manual_unverified',
                'completion_percent' => $actual !== null && $target > 0 ? self::finite($actual / $target * 100) : null,
                'gap' => $actual !== null && $target !== null ? self::finite($actual - $target) : null];
        }
        $be = $inputs['break_even_revenue'] ?? null;
        return ['matrix' => $metrics, 'budget_snapshot_id' => $budget['snapshot_id'] ?? null,
            'break_even_gap' => ($facts['admitted_revenue'] ?? null) !== null && $be !== null ? self::finite($facts['admitted_revenue'] - $be) : null,
            'break_even_revenue' => $be, 'break_even_definition' => $inputs['break_even_definition'] ?? '未设定保本营收口径',
            'comparison_boundary' => '完整日期才给出期间合计；线上、线下及出租率来自人工日报，未独立核验。'];
    }
}
