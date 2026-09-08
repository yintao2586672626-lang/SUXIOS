<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;

/** Plans and observations are evidence records, never instructions to buy media. */
final class PromotionExperimentAssessmentService
{
    public const VERSION = 'promotion_experiment.v1';
    public const OUTCOMES = ['treated_before', 'treated_after', 'control_before', 'control_after',
        'treated_before_exposure', 'treated_after_exposure', 'control_before_exposure', 'control_after_exposure'];
    public const CHANGES = ['holiday', 'price', 'inventory', 'channel_mix'];

    public function evaluate(array $input): array
    {
        $scope = PaidTrafficReturnService::scope($input['scope'] ?? []);
        $plan = $input['plan'] ?? [];
        if (!is_array($plan)) throw new InvalidArgumentException('实验计划格式无效');
        foreach (['name', 'hypothesis', 'primary_metric', 'treatment', 'control', 'stopping_rule'] as $f) {
            $plan[$f] = PaidTrafficReturnService::text($plan[$f] ?? '', '实验计划 ' . $f);
        }
        if ($plan['primary_metric'] !== 'room_nights_per_available_room_night') throw new InvalidArgumentException('当前主指标需为每可售间夜的成交间夜');
        $design = $plan['design_quality'] ?? 'none';
        if (!in_array($design, ['none', 'randomized', 'validated_matched'], true)) throw new InvalidArgumentException('实验设计无效');
        $plan['design_quality'] = $design;
        $plan['before_start'] = PaidTrafficReturnService::date($plan['before_start'] ?? '');
        $plan['before_end'] = PaidTrafficReturnService::date($plan['before_end'] ?? '');
        if ($plan['before_start'] > $plan['before_end'] || $plan['before_end'] >= $scope['period_start']) throw new InvalidArgumentException('实验前期需早于投放观察期且起止有序');
        $window = PaidTrafficReturnService::number($plan['attribution_window_days'] ?? null, '归因窗口天数', 0);
        if ($window === null || $window > 365) throw new InvalidArgumentException('请明确0至365天的归因窗口');
        $plan['attribution_window_days'] = (int)$window;
        $records = $input['records'] ?? [];
        if (!is_array($records) || !array_is_list($records)) throw new InvalidArgumentException('推广记录需为列表');
        $analysis = (new PaidTrafficReturnService())->evaluate($scope, $records, $input['as_of'] ?? '', (int)$window);
        $obs = $input['observation'] ?? [];
        if (!is_array($obs)) throw new InvalidArgumentException('观察记录格式无效');
        $obs['notes'] = trim((string)($obs['notes'] ?? ''));
        if (strlen($obs['notes']) > 4000) throw new InvalidArgumentException('观察说明过长');
        $obs['source_ref'] = trim((string)($obs['source_ref'] ?? ''));
        $obs['source_method'] = $obs['source_method'] ?? 'manual_input';
        $obs['source_quality'] = $obs['source_quality'] ?? 'unverified';
        if (in_array($obs['source_method'], ['manual_input', 'manual_import'], true)) $obs['source_quality'] = 'manual_unverified';
        $obs['pretrend_status'] = $obs['pretrend_status'] ?? 'unknown';
        if (!in_array($obs['pretrend_status'], ['unknown', 'passed', 'parallel', 'failed'], true)) throw new InvalidArgumentException('前趋势状态无效');
        $gaps = [];
        if ($design === 'none') $gaps[] = 'no_control_design';
        if ($analysis['maturity']['status'] !== 'mature') $gaps[] = 'attribution_window_pending';
        if ($analysis['coverage']['missing_dates'] || $analysis['coverage']['missing_campaign_dates']) $gaps[] = 'period_coverage_incomplete';
        if ($analysis['source_quality'] !== 'verified') $gaps[] = 'advertising_source_unverified';
        if (!in_array($obs['source_quality'], ['verified', 'readback_verified'], true) || $obs['source_ref'] === '') $gaps[] = 'observation_source_unverified';
        $obs['collected_at'] = $obs['collected_at'] ?? '';
        if ($obs['collected_at'] === '') $gaps[] = 'observation_snapshot_time_missing';
        else {
            $obs['collected_at'] = PaidTrafficReturnService::timestamp($obs['collected_at']);
            if (substr($obs['collected_at'], 0, 10) > $analysis['as_of']) throw new InvalidArgumentException('观察截至日早于分组数据采集时间');
            if (substr($obs['collected_at'], 0, 10) < $analysis['maturity']['mature_on']) $gaps[] = 'observation_snapshot_immature';
        }
        foreach (self::OUTCOMES as $f) {
            $obs[$f] = PaidTrafficReturnService::number($obs[$f] ?? null, $f, 0);
            if ($obs[$f] === null) $gaps[] = 'missing_' . $f;
            if (str_ends_with($f, '_exposure') && $obs[$f] === 0.0) throw new InvalidArgumentException('可售间夜暴露量需大于0，未知请留空');
        }
        foreach (['treated_before', 'treated_after', 'control_before', 'control_after'] as $f) {
            if ($obs[$f] !== null && $obs[$f . '_exposure'] !== null && $obs[$f] > $obs[$f . '_exposure']) throw new InvalidArgumentException('成交间夜不可超过同范围可售间夜');
        }
        $obs['sample_size'] = PaidTrafficReturnService::number($obs['sample_size'] ?? null, '独立观测样本量', 0);
        $obs['contribution_per_incremental_room_night'] = PaidTrafficReturnService::number($obs['contribution_per_incremental_room_night'] ?? null, '每增量间夜净贡献');
        $obs['discount_cost'] = PaidTrafficReturnService::number($obs['discount_cost'] ?? null, '额外折扣成本');
        foreach (['sample_size', 'contribution_per_incremental_room_night', 'discount_cost'] as $f) if ($obs[$f] === null) $gaps[] = 'missing_' . $f;
        $changes = [];
        foreach (self::CHANGES as $f) {
            $change = ($input['concurrent_changes'] ?? [])[$f] ?? ['status' => 'unknown', 'note' => ''];
            if (!is_array($change) || !in_array($change['status'] ?? '', ['unknown', 'unchanged', 'changed', 'controlled'], true)) throw new InvalidArgumentException('同期变化状态无效');
            $note = trim((string)($change['note'] ?? ''));
            if (strlen($note) > 1000) throw new InvalidArgumentException('同期变化依据过长');
            if (in_array($change['status'], ['controlled', 'unchanged'], true) && $note === '') throw new InvalidArgumentException('同期变化检查需填写依据');
            if (in_array($change['status'], ['unknown', 'changed'], true)) $gaps[] = 'concurrent_' . $f . '_' . $change['status'];
            $changes[$f] = ['status' => $change['status'], 'note' => $note];
        }
        $estimate = null;
        $complete = !in_array(null, array_intersect_key($obs, array_flip(array_merge(self::OUTCOMES, ['sample_size', 'discount_cost', 'contribution_per_incremental_room_night']))), true);
        if ($complete && $obs['sample_size'] > 0) {
            $estimate = (new PromotionIncrementalityService())->evaluate($obs + [
                'promotion_name' => $plan['name'], 'business_date' => $scope['period_end'], 'design_quality' => $design,
            ]);
            $gaps = array_merge($gaps, $estimate['design_assessment']['evidence_threshold_met'] ? [] : $estimate['reason_codes']);
        } else {
            $gaps[] = 'observation_incomplete';
        }
        // Promotional contribution excludes ad spend in the reused estimator. Subtract it here explicitly.
        if ($analysis['totals']['spend'] === null) $gaps[] = 'spend_missing';
        $gaps = array_values(array_unique($gaps));
        $eligible = $gaps === [] && $estimate !== null;
        $netIncrement = $eligible ? round($estimate['net_incremental_profit'] - $analysis['totals']['spend'], 2) : null;
        return [
            'schema_version' => self::VERSION, 'scope' => $scope, 'as_of' => $analysis['as_of'], 'plan' => $plan,
            'observation' => $obs, 'concurrent_changes' => $changes, 'accounting' => $analysis,
            'incrementality' => ['status' => $eligible ? 'directional_estimate' : 'unknown',
                'room_nights' => $eligible ? $estimate['incremental_room_nights'] : null,
                'net_contribution_after_ads' => $netIncrement,
                'formula' => 'exposure_normalized_DiD * treated_after_exposure * contribution_per_room_night - discount_cost - spend',
                'causality_claimed' => false, 'statistical_significance_tested' => false, 'reason_codes' => $gaps,
                'evidence_gaps' => array_map(fn($code) => ['code' => $code, 'message' => $this->gapMessage($code)], $gaps),
                'statement' => $eligible ? '满足所列证据门槛的方向性估计；未检验统计显著性，不承诺收益。' : '仅报告投放与经营变化的观察关系，真实增量未知。'],
            'next_experiment' => [
                '明确同店同平台主指标、前后期间、互斥处理组和对照组，事先写明停止规则。',
                '保留分组依据与前趋势证据，同时记录节假日、价格、库存及渠道结构变化。',
                '到归因窗口结束后补齐退款、净佣金、同订单群成本及逐组可售间夜，再按版本回读复评。',
            ],
            'evidence_boundary' => ['scope' => 'selected_channel_only', 'automatic_execution' => false, 'source_upgrade_on_save' => false],
        ];
    }

    private function gapMessage(string $code): string
    {
        $messages = [
            'no_control_design' => '没有可验证的对照设计，无法将同期增长归因于投放。',
            'attribution_window_pending' => '归因窗口未结束或缺少窗口结束后的快照，需补采后复评。',
            'period_coverage_incomplete' => '所选期间日期覆盖不完整，目前金额只是非全期间小计。',
            'advertising_source_unverified' => '推广来源尚未验证；保存和回读不会提高来源可信度。',
            'observation_source_unverified' => '分组观察来源尚未验证或缺少证据编号。',
            'observation_incomplete' => '分组观察尚不完整，需补齐间夜、可售暴露量和独立样本量。',
            'observation_snapshot_time_missing' => '缺少分组观察的采集时间，无法确认观察成熟度。',
            'observation_snapshot_immature' => '分组观察采于归因窗口结束之前，需要新的观察快照。',
            'spend_missing' => '广告消耗缺失，无法计算扣除广告后的增量净贡献。',
            'sample_size_below_minimum' => '独立样本量小于30，尚未满足现有评估门槛；门槛不代表统计显著性。',
            'pretrend_failed' => '前趋势检查未通过，处理组与对照组当前不可比。',
            'pretrend_status_unrecognized' => '尚未完成可比前趋势检查。',
            'design_quality_unrecognized' => '尚未提供合格随机分组或匹配组设计。',
            'source_quality_unverified' => '手工或未验证观察仅供参考。',
            'source_quality_insufficient' => '观察数据不完整或过期，需要重新核对。',
            'source_quality_unrecognized' => '观察来源质量未知，不能作增量判断。',
        ];
        if (isset($messages[$code])) return $messages[$code];
        foreach (['holiday' => '节假日', 'price' => '价格', 'inventory' => '库存', 'channel_mix' => '渠道结构'] as $key => $label) {
            if (str_starts_with($code, 'concurrent_' . $key . '_')) return $label . '同期变化未核实或未排除影响，需保留关系描述。';
        }
        foreach (['treated_before' => '处理组前期间夜', 'treated_after' => '处理组后期间夜', 'control_before' => '对照组前期间夜', 'control_after' => '对照组后期间夜', 'sample_size' => '独立样本量', 'contribution_per_incremental_room_night' => '每增量间夜净贡献', 'discount_cost' => '额外折扣成本'] as $key => $label) {
            if ($code === 'missing_' . $key) return $label . '缺失，未知值不会按0计算。';
            if ($code === 'missing_' . $key . '_exposure') return $label . '对应的可售暴露量缺失，无法归一化比较。';
        }
        return '证据不足，需要核对实验设计及来源。';
    }
}
