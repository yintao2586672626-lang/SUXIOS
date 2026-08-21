<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Selects one evidence-backed issue for the hotel to review today.
 *
 * The score is only an attention-ordering aid. It is not a revenue metric and
 * never grants permission to change PMS/OTA state.
 */
final class DailyOneThingService
{
    public const CONTRACT_VERSION = 'daily_one_thing.v1';

    /** @var array<string,string> */
    private const LABELS = [
        'service_promise_risk' => '权益履约预警',
        'promotion_incrementality' => '促销真实增量',
        'bookability_gap' => '客人端真实可售',
        'ai_guest_acquisition' => 'AI客源检测',
    ];

    /**
     * @param array<int,array<string,mixed>> $runs
     * @return array<string,mixed>
     */
    public function select(array $runs, string $businessDate): array
    {
        $businessDate = $this->validDate($businessDate);
        $candidates = [];
        $blockedCount = 0;

        foreach ($runs as $run) {
            $featureKey = trim((string)($run['feature_key'] ?? ''));
            if (!isset(self::LABELS[$featureKey])) {
                continue;
            }
            $result = is_array($run['result'] ?? null) ? $run['result'] : [];
            $candidate = $this->candidate($featureKey, $result, $run);
            if ($candidate === null) {
                if ($this->isBlocked($result)) {
                    $blockedCount++;
                }
                continue;
            }
            $candidates[] = $candidate;
        }

        usort($candidates, static function (array $left, array $right): int {
            $scoreOrder = (float)$right['selection_score'] <=> (float)$left['selection_score'];
            if ($scoreOrder !== 0) {
                return $scoreOrder;
            }
            return strcmp((string)$right['created_at'], (string)$left['created_at']);
        });

        $selected = $candidates[0] ?? null;
        $status = $selected !== null
            ? 'action_required'
            : ($blockedCount > 0 ? 'blocked_by_missing_facts' : 'no_action');

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'business_date' => $businessDate,
            'status' => $status,
            'headline' => $selected !== null
                ? (string)$selected['headline']
                : ($status === 'blocked_by_missing_facts'
                    ? '今天先补齐一项关键证据'
                    : '今天没有需要打断老板的高优先事项'),
            'selected' => $selected,
            'candidate_count' => count($candidates),
            'blocked_count' => $blockedCount,
            'candidates' => array_values($candidates),
            'selection_boundary' => '排序分只用于分配注意力，不是经营指标；未取得人工审批，不执行任何OTA、PMS或对外发布动作。',
            'can_execute' => false,
            'requires_human_approval' => true,
        ];
    }

    /** @return array<string,mixed>|null */
    private function candidate(string $featureKey, array $result, array $run): ?array
    {
        $status = $this->status($result);
        $quality = strtolower(trim((string)($run['source_quality_status'] ?? $result['source_quality_status'] ?? '')));
        $qualityWeight = $this->qualityWeight($quality);
        $createdAt = trim((string)($run['created_at'] ?? ''));
        $money = 0.0;
        $base = 0.0;
        $headline = '';
        $reason = '';
        $nextStep = trim((string)(
            $result['recommendation_draft']['summary']
            ?? $result['recommended_action']['summary']
            ?? $result['recommended_action']
            ?? $result['next_action']
            ?? $result['retest_requirement']
            ?? ((array)($result['retest_requirements'] ?? []))[0]
            ?? '打开详情核对证据后再决定'
        ));

        if ($featureKey === 'service_promise_risk' && $status === 'risk_detected') {
            $riskCount = max(0, (int)($result['shortage_quantity'] ?? $result['risk_count'] ?? $result['capacity_gap'] ?? 0));
            if ($riskCount <= 0) return null;
            $money = $this->number($result, ['estimated_risk_amount', 'estimated_exposure', 'risk_amount']);
            $base = 88;
            $headline = "有 {$riskCount} 份权益可能无法兑现";
            $reason = $money > 0 ? '预计违约成本 ¥' . number_format($money, 2) : '承诺数量超过当前可履约容量';
        } elseif ($featureKey === 'bookability_gap' && $status === 'gap_detected') {
            $gapCount = max(1, (int)($result['gap_count'] ?? count((array)($result['affected_conditions'] ?? $result['affected_scenarios'] ?? []))));
            $base = 92;
            $headline = "发现 {$gapCount} 个客人端不可售断点";
            $stage = trim((string)($result['earliest_failure_stage_label'] ?? $result['earliest_failure_stage'] ?? ''));
            $stageLabel = ['search' => '搜索页', 'detail' => '详情页', 'pre_checkout' => '提交订单前'][$stage] ?? $stage;
            $potentialLoss = $this->signedNumber($result, ['potential_loss']);
            $reason = $stageLabel !== '' ? "最早断点：{$stageLabel}" : '后台状态与客人端结果不一致';
            if ($potentialLoss !== null) {
                $reason .= '；潜在损失约 ' . number_format(max(0.0, $potentialLoss), 2) . ' 间夜';
            }
        } elseif ($featureKey === 'promotion_incrementality' && in_array($status, ['contradicted', 'supported'], true)) {
            $net = $this->signedNumber($result, ['net_incremental_profit', 'incremental_net_profit']);
            if ($net === null) return null;
            $money = abs($net);
            $base = $net < 0 ? 82 : 56;
            $headline = $net < 0 ? '当前促销可能在倒贴利润' : '当前促销存在可验证增量';
            $reason = '净增量利润 ' . ($net >= 0 ? '+' : '-') . '¥' . number_format(abs($net), 2);
        } elseif ($featureKey === 'ai_guest_acquisition' && $status === 'measured') {
            $failureGroups = array_values(array_filter(
                (array)($result['failure_points_by_intent'] ?? []),
                static fn(mixed $group): bool => is_array($group) && (array)($group['failure_points'] ?? []) !== []
            ));
            $failed = max(0, (int)($result['failed_intent_count'] ?? count($failureGroups)));
            if ($failed <= 0) return null;
            $base = 48;
            $headline = "有 {$failed} 个高意图问题没有走到可订";
            $reason = trim((string)(
                $result['primary_gap']
                ?? $failureGroups[0]['failure_points'][0]['label']
                ?? 'AI识别、事实、匹配或预订交接至少一关失败'
            ));
        } else {
            return null;
        }

        $impactBoost = $money > 0 ? min(22.0, log10($money + 1.0) * 6.0) : 0.0;
        $selectionScore = round(($base + $impactBoost) * $qualityWeight, 2);

        return [
            'feature_key' => $featureKey,
            'feature_label' => self::LABELS[$featureKey],
            'run_id' => (int)($run['id'] ?? 0),
            'headline' => $headline,
            'reason' => $reason,
            'next_step' => $nextStep,
            'source_quality_status' => $quality !== '' ? $quality : 'unverified',
            'selection_score' => $selectionScore,
            'selection_score_notice' => '仅用于同页候选排序，不代表收入、概率或置信度。',
            'estimated_money_at_risk' => $money > 0 ? round($money, 2) : null,
            'created_at' => $createdAt,
            'can_execute' => false,
        ];
    }

    private function isBlocked(array $result): bool
    {
        $status = $this->status($result);
        return str_starts_with($status, 'blocked')
            || in_array($status, ['indeterminate', 'insufficient_repeatability'], true);
    }

    /** @param array<string,mixed> $result */
    private function status(array $result): string
    {
        $explicit = strtolower(trim((string)($result['status'] ?? $result['effect_status'] ?? $result['verdict'] ?? '')));
        if ($explicit !== '') return $explicit;
        if (($result['blocked_by_missing_evidence'] ?? false) === true) return 'blocked_by_missing_evidence';
        if (($result['gap_detected'] ?? false) === true) return 'gap_detected';
        if (($result['aligned'] ?? false) === true) return 'aligned';
        return '';
    }

    private function qualityWeight(string $quality): float
    {
        return match ($quality) {
            'verified', 'readback_verified', 'available' => 1.0,
            'manual_verified', 'manual_declared' => 0.82,
            'partial', 'manual_unverified', 'unverified' => 0.55,
            default => 0.45,
        };
    }

    /** @param array<int,string> $keys */
    private function number(array $result, array $keys): float
    {
        return max(0.0, $this->signedNumber($result, $keys) ?? 0.0);
    }

    /** @param array<int,string> $keys */
    private function signedNumber(array $result, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $result) && is_numeric($result[$key])) {
                return round((float)$result[$key], 2);
            }
        }
        return null;
    }

    private function validDate(string $date): string
    {
        $date = trim($date);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false
            || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
            || $parsed->format('Y-m-d') !== $date
        ) {
            throw new InvalidArgumentException('业务日期必须是有效的YYYY-MM-DD日期');
        }
        return $date;
    }
}
