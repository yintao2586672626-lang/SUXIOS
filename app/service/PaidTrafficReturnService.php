<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use InvalidArgumentException;

/** Channel attribution accounting only. Does not infer advertising income from hotel growth. */
final class PaidTrafficReturnService
{
    public const VERSION = 'paid_traffic_return.v1';
    public const AMOUNTS = ['spend', 'attributed_revenue', 'refunds', 'commission', 'fulfillment_cost', 'other_cost'];
    public const COUNTS = ['attributed_orders', 'refunded_orders'];

    public function evaluate(array $scope, array $records, string $asOf, int $windowDays): array
    {
        $scope = self::scope($scope);
        $asOf = self::date($asOf);
        if ($windowDays < 0 || $windowDays > 365) throw new InvalidArgumentException('归因窗口需为0至365天');
        if (count($records) > 1000) throw new InvalidArgumentException('单次最多1000条推广记录');
        $groups = [];
        $duplicates = 0;
        foreach ($records as $raw) {
            if (!is_array($raw)) throw new InvalidArgumentException('推广记录格式无效');
            $row = $this->record($raw, $scope);
            $rowWindow = self::number($raw['attribution_window_days'] ?? null, '明细归因窗口', 0);
            if ($rowWindow === null || (int)$rowWindow !== $windowDays) throw new InvalidArgumentException('明细归因窗口缺失或与本次计划不一致');
            $row['attribution_window_days'] = $windowDays;
            if (substr($row['collected_at'], 0, 10) > $asOf) throw new InvalidArgumentException('观察时点早于采集时间');
            if (substr($row['collected_at'], 0, 10) < $row['period_end']) throw new InvalidArgumentException('快照采集时间早于覆盖期间结束');
            // Growing cumulative snapshots are revisions of the same campaign/start cohort.
            $key = $row['campaign_id'] . '|' . $row['period_start'] . '|' . ($row['snapshot_kind'] === 'cumulative_snapshot' ? 'cumulative' : $row['period_end']);
            if (isset($groups[$key])) {
                $prior = $groups[$key];
                if ($prior['snapshot_kind'] !== $row['snapshot_kind']) throw new InvalidArgumentException('同一期间快照粒度冲突');
                $order = strcmp($row['collected_at'], $prior['collected_at']);
                if ($order === 0 && $row !== $prior) throw new InvalidArgumentException('同一快照时刻存在冲突记录，请核对来源');
                if (($order > 0 && $row['period_end'] < $prior['period_end']) || ($order < 0 && $row['period_end'] > $prior['period_end'])) throw new InvalidArgumentException('累计快照日期范围回退，请核对来源');
                ++$duplicates;
                if ($order <= 0) continue;
            }
            $groups[$key] = $row;
        }
        ksort($groups);
        $rows = array_values($groups);
        $coverage = [];
        $campaignDates = [];
        foreach ($rows as $row) {
            for ($d = $row['period_start']; $d <= $row['period_end']; $d = self::nextDate($d)) {
                $key = $row['campaign_id'] . '|' . $d;
                if (isset($campaignDates[$key])) throw new InvalidArgumentException('累计期间或日记录重叠，无法安全相加；请选择不重叠记录');
                $campaignDates[$key] = true;
                $coverage[$d] = true;
            }
        }
        $missingDates = [];
        $missingCampaignDates = [];
        $campaigns = array_values(array_unique(array_column($rows, 'campaign_id')));
        for ($d = $scope['period_start']; $d <= $scope['period_end']; $d = self::nextDate($d)) {
            if (!isset($coverage[$d])) $missingDates[] = $d;
            foreach ($campaigns as $campaign) if (!isset($campaignDates[$campaign . '|' . $d])) $missingCampaignDates[$campaign][] = $d;
        }
        $totals = [];
        $missing = [];
        $known = [];
        foreach (array_merge(self::AMOUNTS, self::COUNTS) as $field) {
            $values = array_column($rows, $field);
            $complete = $rows !== [] && !in_array(null, $values, true);
            $sum = array_sum(array_filter($values, static fn($v) => $v !== null));
            $known[$field] = count(array_filter($values, static fn($v) => $v !== null)) ? round($sum, 2) : null;
            $totals[$field] = $complete ? round($sum, 2) : null;
            if (!$complete) $missing[] = $field;
        }
        $subtract = static fn($a, $b) => $a === null || $b === null ? null : round($a - $b, 2);
        $netRevenue = $subtract($totals['attributed_revenue'], $totals['refunds']);
        $netOrders = $subtract($totals['attributed_orders'], $totals['refunded_orders']);
        $balance = $netRevenue;
        foreach (['commission', 'fulfillment_cost', 'other_cost', 'spend'] as $cost) $balance = $subtract($balance, $totals[$cost]);
        $ratio = static fn($a, $b) => $a === null || $b === null || $b == 0 ? null : round($a / $b, 6);
        $matureAt = (new DateTimeImmutable($scope['period_end']))->modify('+' . $windowDays . ' days')->format('Y-m-d');
        $immatureRows = array_values(array_filter($rows, static fn($r) => substr($r['collected_at'], 0, 10) < (new DateTimeImmutable($r['period_end']))->modify('+' . $windowDays . ' days')->format('Y-m-d')));
        $maturityReason = $asOf < $matureAt ? 'window_time_pending' : ($immatureRows || !$rows ? 'mature_snapshot_missing' : null);
        $quality = $rows === [] ? 'missing' : (count(array_filter($rows, static fn($r) => in_array($r['source_quality'], ['verified', 'readback_verified'], true))) === count($rows) ? 'verified' : 'unverified');
        return [
            'schema_version' => self::VERSION, 'scope' => $scope,
            'status' => $rows === [] ? 'blocked' : ($missingDates || $missingCampaignDates || $missing ? 'partial' : ($quality === 'verified' ? 'ready' : 'unverified')),
            'source_quality' => $quality, 'metric_scope' => 'selected_channel_campaigns_only',
            'currency' => 'CNY', 'amount_unit' => 'yuan', 'as_of' => $asOf,
            'maturity' => ['status' => $maturityReason === null ? 'mature' : 'pending', 'mature_on' => $matureAt, 'window_days' => $windowDays, 'reason' => $maturityReason],
            'coverage' => ['covered_days' => count($coverage), 'missing_dates' => $missingDates, 'missing_campaign_dates' => $missingCampaignDates, 'amount_label' => $missingDates || $missingCampaignDates ? '非全期间金额' : '所选活动期间金额'],
            'deduplication' => ['received' => count($records), 'selected' => count($rows), 'superseded_or_duplicate' => $duplicates],
            'totals' => $totals, 'known_subtotals' => $known, 'missing_fields' => $missing,
            'platform_attribution' => ['net_revenue' => $netRevenue, 'net_orders' => $netOrders,
                'gross_roas' => $ratio($totals['attributed_revenue'], $totals['spend']), 'net_roas' => $ratio($netRevenue, $totals['spend'])],
            'book_return' => ['balance_after_known_scope_costs' => $balance, 'return_on_ad_spend' => $ratio($balance, $totals['spend']),
                'status' => $balance === null ? 'unknown' : 'calculated', 'is_hotel_profit' => false],
            'calculation' => [
                ['label' => '退款后归因收入', 'formula' => 'attributed_revenue - refunds', 'value' => $netRevenue],
                ['label' => '退款后归因订单', 'formula' => 'attributed_orders - refunded_orders', 'value' => $netOrders],
                ['label' => '归因账面余额', 'formula' => 'attributed_revenue - refunds - commission - fulfillment_cost - other_cost - spend', 'value' => $balance],
                ['label' => '净归因投产比（倍）', 'formula' => '(attributed_revenue - refunds) / spend', 'value' => $ratio($netRevenue, $totals['spend'])],
            ],
            'records' => $rows,
            'evidence_boundary' => ['causality_claimed' => false, 'incremental_revenue' => null,
                'statement' => '平台归因与账面回报均不等于真实增量。零消耗时比率未知；成本缺失时余额未知。'],
        ];
    }

    private function record(array $r, array $scope): array
    {
        $allowed = array_merge(self::AMOUNTS, self::COUNTS, ['tenant_id', 'system_hotel_id', 'platform', 'platform_store_id', 'campaign_id', 'period_start', 'period_end', 'snapshot_kind', 'attribution_window_days', 'currency', 'amount_unit', 'attribution_model', 'source_method', 'source_quality', 'source_ref', 'cost_source_ref', 'collected_at', 'dimension', 'compare_type']);
        if (array_diff(array_keys($r), $allowed)) throw new InvalidArgumentException('明细含未定义字段，请使用推广明细格式，不要导入原始响应');
        foreach (['tenant_id', 'system_hotel_id', 'platform', 'platform_store_id'] as $f) {
            if (!isset($r[$f]) || (string)$r[$f] !== (string)$scope[$f]) throw new InvalidArgumentException('推广记录范围不匹配：' . $f);
        }
        if (!OtaTrafficAttributionService::rowBelongsToOwnPlatformTraffic($r, $scope['platform'])) throw new InvalidArgumentException('推广记录并非当前平台本店');
        $start = self::date($r['period_start'] ?? '');
        $end = self::date($r['period_end'] ?? '');
        if ($start > $end || $start < $scope['period_start'] || $end > $scope['period_end']) throw new InvalidArgumentException('推广记录超出所选日期范围');
        if (($r['currency'] ?? '') !== 'CNY' || ($r['amount_unit'] ?? '') !== 'yuan') throw new InvalidArgumentException('金额需明确为人民币元');
        if (($r['attribution_model'] ?? '') !== 'single_touch') throw new InvalidArgumentException('仅支持同平台单触点互斥活动归因，多触点不可直接汇总');
        if (!in_array($r['snapshot_kind'] ?? '', ['daily_total', 'cumulative_snapshot'], true)
            || ($r['snapshot_kind'] === 'daily_total' && $start !== $end)) throw new InvalidArgumentException('推广快照粒度无效');
        $method = self::text($r['source_method'] ?? '', '来源方式');
        $quality = $r['source_quality'] ?? 'unverified';
        if (!in_array($quality, ['verified', 'readback_verified', 'manual_unverified', 'unverified', 'synthetic', 'partial', 'stale'], true)) throw new InvalidArgumentException('来源质量状态无效');
        if (in_array($method, ['manual_import', 'manual_input'], true)) $quality = 'manual_unverified';
        $row = array_intersect_key($scope, array_flip(['tenant_id', 'system_hotel_id', 'platform', 'platform_store_id'])) + [
            'campaign_id' => self::text($r['campaign_id'] ?? '', '活动ID'), 'period_start' => $start, 'period_end' => $end,
            'snapshot_kind' => $r['snapshot_kind'], 'currency' => 'CNY', 'amount_unit' => 'yuan', 'attribution_model' => 'single_touch',
            'source_method' => $method, 'source_quality' => $quality, 'source_ref' => self::text($r['source_ref'] ?? '', '来源凭据'),
            'cost_source_ref' => trim((string)($r['cost_source_ref'] ?? '')),
            'collected_at' => self::timestamp($r['collected_at'] ?? ''),
        ];
        foreach (self::AMOUNTS as $f) $row[$f] = self::number($r[$f] ?? null, $f, 2);
        foreach (self::COUNTS as $f) $row[$f] = self::number($r[$f] ?? null, $f, 0);
        if ($row['refunds'] !== null && $row['attributed_revenue'] !== null && $row['refunds'] > $row['attributed_revenue']) throw new InvalidArgumentException('退款超过同订单群归因收入');
        if ($row['refunded_orders'] !== null && $row['attributed_orders'] !== null && $row['refunded_orders'] > $row['attributed_orders']) throw new InvalidArgumentException('退款订单超过归因订单');
        if (array_filter(['commission', 'fulfillment_cost', 'other_cost'], static fn($f) => $row[$f] !== null) && $row['cost_source_ref'] === '') throw new InvalidArgumentException('已填成本需提供同订单群成本依据；未知请留空');
        return $row;
    }

    public static function scope(array $s): array
    {
        foreach (['tenant_id', 'system_hotel_id'] as $f) {
            if (filter_var($s[$f] ?? null, FILTER_VALIDATE_INT) === false || (int)$s[$f] <= 0) throw new InvalidArgumentException('请选择有效租户和酒店');
        }
        if (!in_array($s['platform'] ?? '', ['ctrip', 'meituan'], true)) throw new InvalidArgumentException('请选择携程或美团');
        if (!is_string($s['platform_store_id'] ?? null) || strlen($s['platform_store_id']) > 128) throw new InvalidArgumentException('平台门店ID不可超过128字节');
        $start = self::date($s['period_start'] ?? ''); $end = self::date($s['period_end'] ?? '');
        if ($start > $end || (new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days > 365) throw new InvalidArgumentException('日期范围需在366天以内且起止有序');
        return ['tenant_id' => (int)$s['tenant_id'], 'system_hotel_id' => (int)$s['system_hotel_id'], 'platform' => $s['platform'],
            'platform_store_id' => self::text($s['platform_store_id'] ?? '', '平台门店ID'), 'period_start' => $start, 'period_end' => $end];
    }

    public static function date(mixed $v): string
    {
        if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v)) throw new InvalidArgumentException('日期格式需为YYYY-MM-DD');
        [$y, $m, $d] = array_map('intval', explode('-', $v));
        if (!checkdate($m, $d, $y)) throw new InvalidArgumentException('日期无效');
        return $v;
    }

    public static function timestamp(mixed $v): string
    {
        if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $v)) throw new InvalidArgumentException('采集时间需包含时区');
        self::date(substr($v, 0, 10));
        try { $d = new DateTimeImmutable($v); }
        catch (\Exception) { throw new InvalidArgumentException('采集时间或时区无效'); }
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors && ($errors['warning_count'] || $errors['error_count'])) throw new InvalidArgumentException('采集时间无效');
        return $d->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d\TH:i:sP');
    }

    public static function text(mixed $v, string $label): string
    {
        if (!is_string($v) || trim($v) === '' || strlen($v) > 1000) throw new InvalidArgumentException($label . '需填写且不超过1000字节');
        return trim($v);
    }

    public static function number(mixed $v, string $label, int $decimals = 2): ?float
    {
        if ($v === null || $v === '') return null;
        if (is_bool($v) || !is_numeric($v) || !is_finite((float)$v) || (float)$v < 0 || (float)$v > 1000000000
            || abs(round((float)$v, $decimals) - (float)$v) > 0.0000001) throw new InvalidArgumentException($label . '需为非负数且单位精度正确');
        return (float)$v;
    }

    private static function nextDate(string $d): string { return (new DateTimeImmutable($d))->modify('+1 day')->format('Y-m-d'); }
}
