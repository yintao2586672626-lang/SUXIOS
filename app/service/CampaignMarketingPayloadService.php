<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Saved manual evidence; no platform collection or operating action. */
final class CampaignMarketingPayloadService
{
    public function normalize(string $kind, array $p, string $businessDate): array
    {
        if ($kind === 'marketing_score_rule') return $this->rule($p);
        if ($kind === 'marketing_coverage') {
            $status = (string)($p['coverage_status'] ?? '');
            if (!in_array($status, ['complete', 'no_posts', 'partial'], true)) throw new InvalidArgumentException('请选择已核对完整、确认无作品或部分覆盖');
            $count = $this->metric($p['expected_posts'] ?? null);
            if ($status !== 'partial' && $count === null) throw new InvalidArgumentException('完整覆盖须明确作品数量；无作品请填写0');
            if ($status === 'no_posts' && $count !== 0) throw new InvalidArgumentException('确认无作品的数量必须为0');
            return ['platform' => 'douyin', 'coverage_status' => $status, 'expected_posts' => $count, 'notes' => $this->text($p['notes'] ?? '', 1000, true)];
        }
        $platform = $this->text($p['platform'] ?? '', 20, true);
        if (!in_array($platform, ['douyin', 'xiaohongshu', 'other'], true)) throw new InvalidArgumentException('营销来源平台不支持');
        $published = trim((string)($p['published_at'] ?? ''));
        if ($published !== '') {
            $published = str_replace('T', ' ', $published);
            if (strlen($published) === 16) $published .= ':00';
            $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $published, new DateTimeZone('Asia/Shanghai'));
            if (!$date || $date->format('Y-m-d H:i:s') !== $published) throw new InvalidArgumentException('作品发布时间须为有效上海本地时间');
            if (substr($published, 0, 10) > $businessDate) throw new InvalidArgumentException('作品发布时间不能晚于当前指标统计业务日');
        }
        $out = ['platform' => $platform, 'work_id' => $this->text($p['work_id'] ?? '', 120, true),
            'title' => $this->text($p['title'] ?? '', 240, true), 'published_at' => $published === '' ? null : $published,
            'published_timezone' => 'Asia/Shanghai', 'attribution_notes' => $this->text($p['attribution_notes'] ?? '', 1000),
            'result_source_label' => $this->text($p['result_source_label'] ?? '', 240),
            'result_business_date' => empty($p['result_business_date']) ? null : $this->date((string)$p['result_business_date'])];
        foreach (['views', 'likes', 'reposts', 'reservations', 'effective_leads', 'actual_arrivals', 'actual_room_nights', 'actual_revenue'] as $metric) {
            $out[$metric] = $this->metric($p[$metric] ?? null, $metric === 'actual_revenue');
        }
        if (($out['actual_arrivals'] !== null || $out['actual_room_nights'] !== null || $out['actual_revenue'] !== null)
            && ($out['result_source_label'] === '' || $out['result_business_date'] === null || $out['attribution_notes'] === '')) {
            throw new InvalidArgumentException('实际结果须保留结果日期、来源和归因核对说明；线索不自动视为到店收入');
        }
        return $out;
    }

    private function rule(array $p): array
    {
        $out = ['platform' => 'douyin', 'name' => $this->text($p['name'] ?? '', 120, true),
            'metric_definition' => $this->text($p['metric_definition'] ?? '', 1000, true), 'weights' => [], 'targets' => []];
        foreach (['posts', 'views', 'likes', 'reposts'] as $metric) {
            $weight = $this->metric($p['weights'][$metric] ?? null);
            $target = $this->metric($p['targets'][$metric] ?? null);
            if ($weight === null || $weight > 100 || $target === null || $target < 1) throw new InvalidArgumentException('四项权重须为0至100整数，周目标须为正整数');
            $out['weights'][$metric] = $weight; $out['targets'][$metric] = $target;
        }
        if (array_sum($out['weights']) !== 100) throw new InvalidArgumentException('四项评分权重之和必须为100');
        $out['formula'] = 'sum(weight * min(actual / weekly_target, 1))';
        return $out;
    }

    private function metric(mixed $value, bool $money = false): int|float|null
    {
        if ($value === '' || $value === null) return null;
        if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0 || (float)$value > 999999999999
            || (!$money && floor((float)$value) !== (float)$value)) throw new InvalidArgumentException('营销指标必须为有效非负数；未知请留空');
        return $money ? round((float)$value, 2) : (int)$value;
    }

    private function date(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('业务日期无效');
        return $value;
    }

    private function text(mixed $value, int $limit, bool $required = false): string
    {
        if (!is_string($value) && !is_numeric($value)) throw new InvalidArgumentException('文本格式无效');
        $value = trim((string)$value);
        if (($required && $value === '') || mb_strlen($value) > $limit) throw new InvalidArgumentException('必填内容缺失或文本超长');
        if (preg_match('~(?:https?://|data:|javascript:|bearer\s|(?:password|token|cookie|secret)\s*[=:])~iu', $value)) throw new InvalidArgumentException('记录不保存媒体URL、凭据或令牌');
        return $value;
    }
}
