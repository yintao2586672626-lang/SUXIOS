<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;
use Throwable;

/** Seven-day manual Douyin evidence, scored with one explicitly selected saved rule. */
final class CampaignMarketingWeeklyService
{
    public function overview(int $tenantId, array $hotelIds, string $start, ?int $ruleId): array
    {
        $campaign = new CampaignOperationsService();
        $hotelIds = array_values(array_unique(array_map('intval', $hotelIds))); sort($hotelIds);
        if ($tenantId <= 0 || $hotelIds === [] || count($hotelIds) > 100) throw new InvalidArgumentException('请选择同一租户的1至100家酒店');
        foreach ($hotelIds as $id) {
            if ($id <= 0 || $campaign->hotelTenantId($id) !== $tenantId) throw new RuntimeException('集团周榜酒店与当前租户不匹配', 403);
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
        if (!$date || $date->format('Y-m-d') !== $start || $date->format('N') !== '1') throw new InvalidArgumentException('周起始日期须为有效周一');
        $dates = []; for ($i = 0; $i < 7; $i++) $dates[] = $date->modify('+' . $i . ' days')->format('Y-m-d');
        $end = $dates[6];
        try {
            $heads = Db::name(CampaignOperationsService::TABLE)->where('tenant_id', $tenantId)->whereIn('hotel_id', $hotelIds)
                ->whereIn('kind', ['marketing', 'marketing_coverage'])->whereBetween('business_date', [$start, $end])
                ->field('MAX(id) AS latest_id,hotel_id')->group('hotel_id,kind,record_key')->limit(5001)->select()->toArray();
            $heads = array_merge($heads, Db::name(CampaignOperationsService::TABLE)->where('tenant_id', $tenantId)->whereIn('hotel_id', $hotelIds)
                ->where('kind', 'marketing_score_rule')->where('business_date', '<=', $end)->field('MAX(id) AS latest_id,hotel_id')
                ->group('hotel_id,kind,record_key')->limit(5001)->select()->toArray());
        } catch (Throwable $e) { throw new RuntimeException('营销周榜数据表未就绪，未确认覆盖或评分', 503, $e); }
        if (count($heads) > 5000) throw new RuntimeException('营销周榜记录超出单次处理上限，请缩小酒店范围；未输出不完整排行', 503);
        $records = []; foreach ($heads as $head) $records[] = $campaign->read($tenantId, (int)$head['hotel_id'], (int)$head['latest_id']);
        $rule = null; $rules = [];
        foreach ($records as $record) {
            if ($record['kind'] !== 'marketing_score_rule') continue;
            $rules[] = $record;
            if ($ruleId === $record['id']) $rule = $record;
        }
        if ($ruleId && $rule === null) throw new InvalidArgumentException('评分规则须为当前选中酒店范围内的有效最新版本');
        $rows = []; foreach ($hotelIds as $id) $rows[] = $this->hotel($id, $dates, $records, $rule);
        $eligible = array_values(array_filter($rows, static fn(array $row): bool => $row['eligible_for_rank']));
        usort($eligible, static fn(array $a, array $b): int => $b['score'] <=> $a['score'] ?: $a['hotel_id'] <=> $b['hotel_id']);
        $rank = 0; $prior = null;
        foreach ($eligible as $index => $item) {
            if ($prior !== $item['score']) $rank = $index + 1;
            foreach ($rows as &$row) if ($row['hotel_id'] === $item['hotel_id']) $row['rank'] = $rank;
            unset($row); $prior = $item['score'];
        }
        return ['schema_version' => 'campaign_marketing_weekly.v1', 'tenant_id' => $tenantId, 'hotel_ids' => $hotelIds,
            'platform' => 'douyin', 'week_start' => $start, 'week_end' => $end, 'dates' => $dates,
            'metric_as_of_date' => $end, 'rows' => $rows, 'rule' => $rule, 'available_rules' => $rules,
            'data_status' => 'unverified', 'ranking_status' => $rule === null ? 'configuration_required' : (count($eligible) === count($rows) ? 'manual_comparable' : 'partial'),
            'boundary' => '人工抖音记录参考周榜；完整7日覆盖、发布时间、同一周末统计日和全部指标才入榜。相同分数并列；预约/线索不等于成交，不代表全酒店收入或经营效果。'];
    }

    private function hotel(int $id, array $dates, array $records, ?array $rule): array
    {
        $works = []; $coverage = []; $unknownPublished = [];
        foreach ($records as $record) {
            if ($record['hotel_id'] !== $id) continue;
            if ($record['kind'] === 'marketing_coverage' && in_array($record['business_date'], $dates, true)) $coverage[$record['business_date']] = $record;
            if ($record['kind'] !== 'marketing' || ($record['payload']['platform'] ?? '') !== 'douyin') continue;
            $workId = $record['payload']['work_id'];
            if (!isset($works[$workId]) || [$works[$workId]['business_date'], $works[$workId]['id']] < [$record['business_date'], $record['id']]) $works[$workId] = $record;
        }
        $selected = []; $reasons = []; $daily = []; $totals = ['posts' => 0, 'views' => 0, 'likes' => 0, 'reposts' => 0];
        foreach ($works as $record) {
            $published = $record['payload']['published_at'] ?? null;
            if ($published === null) {
                if (in_array($record['business_date'], $dates, true)) $unknownPublished[] = $record['id'];
                continue;
            }
            if (!in_array(substr($published, 0, 10), $dates, true)) continue;
            $selected[] = $record; $totals['posts']++;
            if ($record['business_date'] !== $dates[6]) $reasons[] = '作品#' . $record['id'] . '统计日不是周末，不能同口径比较';
            if ($record['source_hotel_id'] !== $id) $reasons[] = '作品#' . $record['id'] . '来源酒店与当前酒店不一致';
            foreach (['views', 'likes', 'reposts'] as $metric) {
                $value = $record['payload'][$metric] ?? null;
                $totals[$metric] = $value === null || $totals[$metric] === null ? null : $totals[$metric] + $value;
            }
        }
        if ($unknownPublished !== []) $reasons[] = '存在发布时间缺失的作品：#' . implode(',#', $unknownPublished);
        foreach ($dates as $date) {
            $row = $coverage[$date] ?? null;
            $actual = count(array_filter($selected, static fn(array $record): bool => substr($record['payload']['published_at'], 0, 10) === $date));
            $expected = $row['payload']['expected_posts'] ?? null;
            $complete = $row !== null && $row['source_hotel_id'] === $id && in_array($row['payload']['coverage_status'], ['complete', 'no_posts'], true) && $expected === $actual;
            $daily[] = ['business_date' => $date, 'coverage_record_id' => $row['id'] ?? null, 'source_label' => $row['source_label'] ?? null,
                'status' => $row === null ? 'missing' : ($complete ? 'manual_complete' : 'partial'),
                'expected_posts' => $expected, 'saved_posts' => $actual, 'data_status' => $row === null ? 'missing' : 'unverified'];
            if (!$complete) $reasons[] = $date . ($row === null ? '缺少覆盖核对' : '覆盖未完整或作品数量不符');
        }
        foreach (['views', 'likes', 'reposts'] as $metric) if ($totals[$metric] === null) $reasons[] = $metric . '指标缺失';
        $allDays = count(array_filter($daily, static fn(array $day): bool => $day['status'] === 'manual_complete'));
        if ($allDays !== 7 || $unknownPublished !== []) {
            foreach ($totals as $metric => $value) $totals[$metric] = null;
        }
        if ($rule === null) $reasons[] = '未选择已保存评分规则';
        $score = null; $breakdown = [];
        if ($reasons === []) {
            $score = 0.0;
            foreach ($totals as $metric => $actual) {
                $points = $rule['payload']['weights'][$metric] * min($actual / $rule['payload']['targets'][$metric], 1);
                $breakdown[$metric] = ['actual' => $actual, 'weekly_target' => $rule['payload']['targets'][$metric], 'weight' => $rule['payload']['weights'][$metric], 'points' => round($points, 4)];
                $score += $points;
            }
            $score = round($score, 4);
        }
        return ['hotel_id' => $id, 'coverage_days' => $allDays, 'daily_coverage' => $daily,
            'totals' => $totals, 'observed_posts' => count($selected), 'work_record_ids' => array_column($selected, 'id'),
            'eligible_for_rank' => $reasons === [], 'exclusion_reasons' => $reasons, 'score' => $score,
            'score_breakdown' => $breakdown, 'rank' => null, 'data_status' => 'unverified'];
    }
}
