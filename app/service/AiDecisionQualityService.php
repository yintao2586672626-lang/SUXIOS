<?php
declare(strict_types=1);

namespace app\service;

/**
 * Adds one evidence-aware contract to user-facing AI recommendations.
 *
 * This service never invents a numeric target. When no backtest, elasticity or
 * before/after sample exists, the expected effect stays directional and the
 * recommendation explicitly requires a same-scope review.
 */
class AiDecisionQualityService
{
    public const CONTRACT_VERSION = 'ai_recommendation_quality.v1';

    private const TRUSTED_QUALITY_STATUSES = [
        'available', 'verified', 'normal', 'ok', 'success', 'complete', 'completed',
        'readback_verified', 'decision_eligible', 'operator_attested',
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function enrichRecommendations(mixed $items, array $context = []): array
    {
        if (!is_array($items)) {
            $items = $items === null || $items === '' ? [] : [$items];
        } elseif (!array_is_list($items) && array_intersect(['title', 'action', 'detail', 'suggestion'], array_keys($items)) !== []) {
            $items = [$items];
        }

        $result = [];
        foreach ($items as $index => $item) {
            $normalized = $this->enrichRecommendation($item, $context, (int)$index);
            if ($normalized !== null) {
                $result[] = $normalized;
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function enrichRecommendation(mixed $item, array $context = [], int $index = 0): ?array
    {
        if (is_string($item) || is_numeric($item)) {
            $text = trim((string)$item);
            if ($text === '') {
                return null;
            }
            $item = [
                'title' => '建议' . ($index + 1),
                'detail' => $text,
                'action' => $text,
                'legacy_unstructured' => true,
            ];
        }
        if (!is_array($item)) {
            return null;
        }

        $title = $this->firstText($item, ['title', 'name', 'label']);
        $action = $this->firstText($item, ['action', 'suggested_action', 'suggestion', 'detail', 'content']);
        if ($action === '') {
            $action = $title;
        }
        if ($title === '' && $action === '') {
            return null;
        }
        if ($title === '') {
            $title = '建议' . ($index + 1);
        }

        $dataBasis = $this->buildDataBasis($item, $context);
        $priority = $this->normalizePriority(
            $item['priority'] ?? $context['default_priority'] ?? '',
            (string)($item['risk_level'] ?? $context['default_risk_level'] ?? ''),
            $dataBasis
        );
        $expectedEffect = $this->buildExpectedEffect($item, $action, $context);
        $risk = $this->buildRisk($item, $action, $expectedEffect, $dataBasis, $priority, $context);
        $genericTalk = $this->isGenericAction($action);

        $missing = [];
        if ($dataBasis['status'] === 'missing') {
            $missing[] = 'data_basis';
        }
        if ($expectedEffect['status'] === 'missing') {
            $missing[] = 'expected_effect';
        }
        if ($risk['status'] === 'missing') {
            $missing[] = 'risk';
        }
        if ($genericTalk) {
            $missing[] = 'action_specificity';
        }
        $missing = array_values(array_unique($missing));

        $qualityStatus = 'ready_for_human_review';
        if ($genericTalk || in_array('expected_effect', $missing, true) || in_array('risk', $missing, true)) {
            $qualityStatus = 'incomplete';
        } elseif (in_array($dataBasis['status'], ['missing', 'unverified', 'partial'], true)) {
            $qualityStatus = 'requires_evidence_confirmation';
        }

        $item['title'] = mb_substr($title, 0, 100);
        $item['action'] = mb_substr($action, 0, 500);
        if (trim((string)($item['detail'] ?? '')) === '') {
            $item['detail'] = $item['action'];
        }
        $item['priority'] = $priority;
        $item['priority_reason'] = $this->priorityReason($priority, $risk, $dataBasis, $item);
        $item['data_basis'] = $dataBasis;
        $item['expected_effect'] = $expectedEffect;
        $item['risk'] = $risk;
        $item['risk_level'] = (string)$risk['level'];
        $item['decision_quality'] = [
            'contract_version' => self::CONTRACT_VERSION,
            'status' => $qualityStatus,
            'complete' => $missing === [],
            'missing_fields' => $missing,
            'generic_talk_rejected' => $genericTalk,
            'human_confirmation_required' => true,
            'scope' => (string)$dataBasis['scope'],
        ];

        if ($genericTalk) {
            $item['can_create_execution_intent'] = false;
            if (trim((string)($item['blocked_reason'] ?? '')) === '') {
                $item['blocked_reason'] = '建议只有宽泛方向，缺少具体对象、执行步骤或复核指标，不能进入运营执行。';
            }
        }

        return $item;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    public function summarize(array $items, array $context = []): array
    {
        $complete = 0;
        $evidenceBlocked = 0;
        $incomplete = 0;
        foreach ($items as $item) {
            $quality = is_array($item['decision_quality'] ?? null) ? $item['decision_quality'] : [];
            if (($quality['complete'] ?? false) === true) {
                $complete++;
            }
            if (($quality['status'] ?? '') === 'requires_evidence_confirmation') {
                $evidenceBlocked++;
            }
            if (($quality['status'] ?? '') === 'incomplete') {
                $incomplete++;
            }
        }

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'scope' => (string)($context['scope'] ?? $context['metric_scope'] ?? 'unknown'),
            'recommendation_count' => count($items),
            'complete_count' => $complete,
            'requires_evidence_confirmation_count' => $evidenceBlocked,
            'incomplete_count' => $incomplete,
            'all_recommendations_complete' => $items !== [] && $complete === count($items),
            'numeric_effects_require_measured_or_backtested_evidence' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function buildDataBasis(array $item, array $context): array
    {
        $scope = trim((string)($item['metric_scope'] ?? $item['scope'] ?? $context['scope'] ?? $context['metric_scope'] ?? 'unknown'));
        if ($scope === '') {
            $scope = 'unknown';
        }

        $contextSources = array_merge(
            $this->arrayList($context['evidence_sources'] ?? []),
            $this->arrayList($context['source_refs'] ?? []),
            $this->arrayList($context['data_basis'] ?? [])
        );
        $itemDataBasis = is_array($item['data_basis'] ?? null) ? $item['data_basis'] : [];
        $requested = array_merge(
            $this->arrayList($item['evidence_refs'] ?? []),
            $this->arrayList($item['source_refs'] ?? []),
            $this->arrayList($itemDataBasis['refs'] ?? [])
        );

        $requestedKeys = [];
        foreach ($requested as $value) {
            $key = $this->evidenceKey($value);
            if ($key !== '') {
                $requestedKeys[$key] = true;
            }
        }

        $refs = [];
        foreach ($contextSources as $source) {
            $key = $this->evidenceKey($source);
            if ($requestedKeys !== [] && ($key === '' || !isset($requestedKeys[$key]))) {
                continue;
            }
            $normalized = $this->normalizeEvidenceRef($source, $scope, $context);
            if ($normalized !== null) {
                $refs[] = $normalized;
            }
        }
        foreach ($requested as $source) {
            $key = $this->evidenceKey($source);
            $already = false;
            foreach ($refs as $ref) {
                if ($key !== '' && (string)($ref['ref'] ?? '') === $key) {
                    $already = true;
                    break;
                }
            }
            if ($already) {
                continue;
            }
            $normalized = $this->normalizeEvidenceRef($source, $scope, $context);
            if ($normalized !== null) {
                $refs[] = $normalized;
            }
        }

        $refs = $this->dedupeEvidenceRefs($refs);
        $statuses = array_values(array_filter(array_map(
            static fn(array $ref): string => trim((string)($ref['quality_status'] ?? '')),
            $refs
        )));
        $trustedCount = count(array_filter($statuses, fn(string $status): bool => $this->isTrustedStatus($status)));
        $status = 'missing';
        if ($refs !== []) {
            $status = $trustedCount === count($refs)
                ? 'verified'
                : ($trustedCount > 0 ? 'partial' : 'unverified');
        }

        $basisSummary = $this->firstText($item, ['reason', 'evidence_summary', 'basis_summary']);
        if ($basisSummary === '') {
            $basisSummary = trim((string)($context['basis_summary'] ?? ''));
        }
        if ($status === 'missing' && $basisSummary !== '') {
            $status = 'unverified';
        }

        return [
            'status' => $status,
            'scope' => $scope,
            'hotel_id' => (int)($context['hotel_id'] ?? 0),
            'platform' => trim((string)($item['platform'] ?? $context['platform'] ?? '')),
            'date' => trim((string)($context['data_date'] ?? $context['report_date'] ?? '')),
            'date_range' => is_array($context['date_range'] ?? null) ? $context['date_range'] : [],
            'summary' => mb_substr($basisSummary, 0, 500),
            'refs' => array_slice($refs, 0, 8),
            'ref_count' => count($refs),
            'quality_note' => $this->dataBasisQualityNote($status, $scope),
        ];
    }

    /** @return array<string, mixed>|null */
    private function normalizeEvidenceRef(mixed $source, string $scope, array $context): ?array
    {
        if (is_string($source) || is_numeric($source)) {
            $value = trim((string)$source);
            if ($value === '') {
                return null;
            }
            return [
                'ref' => $value,
                'source' => $value,
                'date' => trim((string)($context['data_date'] ?? $context['report_date'] ?? '')),
                'scope' => $scope,
                'quality_status' => 'unverified',
                'metric_keys' => [],
            ];
        }
        if (!is_array($source)) {
            return null;
        }

        $ref = $this->evidenceKey($source);
        $quality = trim((string)($source['quality_status'] ?? $source['validation_status'] ?? $source['verification_status'] ?? $source['data_status'] ?? $source['status'] ?? ''));
        if (($source['decision_eligible'] ?? null) === true || ($source['readback_verified'] ?? null) === true) {
            $quality = 'verified';
        } elseif ($quality === '') {
            $quality = 'unverified';
        }

        return [
            'ref' => $ref !== '' ? $ref : 'evidence_' . substr(sha1(json_encode($source, JSON_UNESCAPED_UNICODE) ?: ''), 0, 10),
            'source' => mb_substr(trim((string)($source['source'] ?? $source['table'] ?? $source['title'] ?? $source['label'] ?? '')), 0, 120),
            'date' => trim((string)($source['data_date'] ?? $source['date'] ?? $source['report_date'] ?? $source['snapshot_time'] ?? $context['data_date'] ?? $context['report_date'] ?? '')),
            'scope' => trim((string)($source['scope'] ?? $source['metric_scope'] ?? $scope)),
            'quality_status' => $quality,
            'metric_keys' => array_slice(array_values(array_unique(array_filter(array_map(
                'strval',
                array_merge($this->arrayList($source['metric_keys'] ?? []), $this->arrayList($source['tags'] ?? []))
            )))), 0, 12),
            'summary' => mb_substr(trim((string)($source['summary'] ?? $source['label'] ?? $source['evidence'] ?? '')), 0, 240),
        ];
    }

    /** @return array<string, mixed> */
    private function buildExpectedEffect(array $item, string $action, array $context): array
    {
        $existing = $item['expected_effect'] ?? $item['expected_impact'] ?? null;
        $targetValue = is_array($item['target_value'] ?? null) ? $item['target_value'] : [];
        $metric = trim((string)($item['expected_metric'] ?? $targetValue['target_metric'] ?? $context['expected_metric'] ?? ''));
        if ($metric === '') {
            $metric = $this->inferMetric($action);
        }
        $direction = trim((string)($item['expected_direction'] ?? ''));
        if ($direction === '') {
            $direction = $this->expectedDirection($metric, (string)($item['action_type'] ?? ''));
        }
        $reviewWindow = trim((string)($item['review_window'] ?? $context['review_window'] ?? ''));
        if ($reviewWindow === '') {
            $reviewWindow = '执行后按同酒店、同渠道/业务范围、同指标口径的前后周期复核';
        }

        $summary = '';
        $status = 'directional_only';
        if (is_array($existing)) {
            $summary = trim((string)($existing['summary'] ?? $existing['description'] ?? ''));
            $status = trim((string)($existing['status'] ?? $status));
            $metric = trim((string)($existing['metric'] ?? $metric));
            $direction = trim((string)($existing['direction'] ?? $direction));
            $reviewWindow = trim((string)($existing['review_window'] ?? $reviewWindow));
        } elseif (is_string($existing) || is_numeric($existing)) {
            $summary = trim((string)$existing);
            if ($summary !== '') {
                $status = preg_match('/\d/', $summary) ? 'quantified' : 'directional_only';
            }
        }

        $target = $this->expectedTarget($item);
        if ($target !== '') {
            $status = 'quantified';
        }
        if ($summary === '') {
            if ($metric === 'data_completeness') {
                $summary = '预期把相关数据从缺失或未验证推进到已保存、可回读、可追溯；这不等同于经营指标改善。';
                $status = 'verification_target';
            } elseif (in_array((string)($item['recommendation_type'] ?? ''), ['investigation', 'investigation_only'], true)) {
                $summary = '预期形成可核验原因或排除项；完成核验前不把调查动作写成经营改善。';
                $status = 'verification_target';
            } elseif ($metric !== '' && $metric !== 'business_metric_pending_definition') {
                $summary = '预期方向：' . $this->directionText($direction) . $this->metricLabel($metric)
                    . '；当前缺少回测或前后对照证据，不承诺具体提升幅度。';
            } else {
                $summary = '当前缺少可量化的效果指标；补齐目标指标和对照周期前，不把该建议视为已验证决策。';
                $status = 'missing';
            }
        }

        return [
            'status' => $status,
            'metric' => $metric !== '' ? $metric : 'business_metric_pending_definition',
            'metric_label' => $this->metricLabel($metric),
            'direction' => $direction,
            'target' => $target,
            'summary' => mb_substr($summary, 0, 500),
            'review_window' => mb_substr($reviewWindow, 0, 240),
            'quantified' => $status === 'quantified',
        ];
    }

    /** @return array<string, mixed> */
    private function buildRisk(
        array $item,
        string $action,
        array $expectedEffect,
        array $dataBasis,
        string $priority,
        array $context
    ): array {
        $existing = $item['risk'] ?? null;
        $summary = '';
        $levelRaw = $item['risk_level'] ?? $context['default_risk_level'] ?? '';
        $controls = [];
        $status = 'derived';
        if (is_array($existing)) {
            $summary = trim((string)($existing['summary'] ?? $existing['description'] ?? ''));
            $levelRaw = $existing['level'] ?? $levelRaw;
            $controls = $this->stringList($existing['controls'] ?? $existing['mitigations'] ?? []);
            $status = trim((string)($existing['status'] ?? 'provided'));
        } elseif (is_string($existing) || is_numeric($existing)) {
            $summary = trim((string)$existing);
            if ($summary !== '') {
                $status = 'provided';
            }
        }
        if ($summary === '') {
            $summary = $this->firstText($item, ['risk_summary', 'risk_note']);
            if ($summary !== '') {
                $status = 'provided';
            }
        }

        $actionType = trim((string)($item['action_type'] ?? ''));
        if ($summary === '') {
            $summary = $this->derivedRiskSummary($action, $actionType, (string)($expectedEffect['metric'] ?? ''), $dataBasis);
        }
        if ($controls === []) {
            $controls = $this->derivedRiskControls($actionType, (string)($expectedEffect['metric'] ?? ''), $dataBasis);
        }
        $blockedReason = trim((string)($item['blocked_reason'] ?? ''));
        if ($blockedReason !== '') {
            $controls[] = $blockedReason;
        }

        $level = $this->normalizeRiskLevel($levelRaw, $priority, $dataBasis);
        if ($summary === '') {
            $summary = '未提供可核验的动作风险；执行前必须由人工补充风险与控制措施。';
            $status = 'missing';
        }

        return [
            'status' => $status,
            'level' => $level,
            'summary' => mb_substr($summary, 0, 500),
            'controls' => array_slice(array_values(array_unique(array_filter($controls))), 0, 5),
        ];
    }

    private function normalizePriority(mixed $value, string $riskLevel, array $dataBasis): string
    {
        $value = strtoupper(trim((string)$value));
        $map = [
            'P0' => 'P0', 'HIGH' => 'P0', 'URGENT' => 'P0', '高' => 'P0', '高优先级' => 'P0',
            'P1' => 'P1', 'MEDIUM' => 'P1', '中' => 'P1', '中优先级' => 'P1',
            'P2' => 'P2', 'LOW' => 'P2', '低' => 'P2', '低优先级' => 'P2',
        ];
        if (isset($map[$value])) {
            return $map[$value];
        }
        $risk = strtolower(trim($riskLevel));
        if (str_contains($risk, 'high') || str_contains($riskLevel, '高') || ($dataBasis['status'] ?? '') === 'missing') {
            return 'P0';
        }
        if (str_contains($risk, 'low') || str_contains($riskLevel, '低')) {
            return 'P2';
        }
        return 'P1';
    }

    private function normalizeRiskLevel(mixed $value, string $priority, array $dataBasis): string
    {
        $raw = strtolower(trim((string)$value));
        if ($raw === '') {
            if (($dataBasis['status'] ?? '') === 'missing') {
                return 'high';
            }
            return $priority === 'P0' ? 'high' : ($priority === 'P2' ? 'low' : 'medium');
        }
        if (str_contains($raw, 'high') || str_contains((string)$value, '高')) {
            return 'high';
        }
        if (str_contains($raw, 'low') || str_contains((string)$value, '低')) {
            return 'low';
        }
        if (str_contains($raw, 'medium') || str_contains((string)$value, '中')) {
            return 'medium';
        }
        return 'unverified';
    }

    private function priorityReason(string $priority, array $risk, array $dataBasis, array $item): string
    {
        $provided = trim((string)($item['priority_reason'] ?? ''));
        if ($provided !== '') {
            return mb_substr($provided, 0, 240);
        }
        if (($dataBasis['status'] ?? '') === 'missing') {
            return 'P0：数据依据缺失，先补证或阻断执行。';
        }
        if (($risk['level'] ?? '') === 'high') {
            return $priority . '：动作风险较高，需优先人工复核。';
        }
        return $priority . '：按当前证据状态、影响范围和动作风险排序。';
    }

    private function inferMetric(string $action): string
    {
        $rules = [
            'data_completeness' => ['补齐', '回读', '采集', '同步', '数据源', '绑定', '证据', '资料'],
            'advertising_roas' => ['广告', '投放', 'ROAS', '出价'],
            'avg_psi_score' => ['服务质量', '服务分', 'PSI', '响应'],
            'detail_rate' => ['曝光到访问', '主图', '标题', '列表页', '点击'],
            'order_rate' => ['访问到订单', '下单', '转化', '取消政策', '促销'],
            'ota_adr' => ['ADR', '房价', '价格', '价格带', '价格策略', '调价', '报价'],
            'occupancy_rate' => ['入住率', 'OCC', '出租率'],
            'monthly_net_cashflow' => ['现金流', '净利润', '净现金'],
            'payback_months' => ['回本', '回收周期'],
            'rent_ratio' => ['租金占比', '租金压力', '重谈租金'],
            'roi' => ['ROI', '效果复盘', '前后证据'],
            'opening_readiness' => ['开业', '证照', '消防', 'PMS', '库存', '验收'],
        ];
        foreach ($rules as $metric => $needles) {
            foreach ($needles as $needle) {
                if (mb_stripos($action, $needle) !== false) {
                    return $metric;
                }
            }
        }
        return 'business_metric_pending_definition';
    }

    private function expectedDirection(string $metric, string $actionType): string
    {
        if ($metric === 'data_completeness' || str_contains($actionType, 'data_repair')) {
            return 'establish';
        }
        if ($metric === 'roi' && str_contains($actionType, 'review')) {
            return 'verify';
        }
        if (in_array($metric, ['payback_months', 'rent_ratio'], true)) {
            return 'decrease';
        }
        return 'improve';
    }

    private function expectedTarget(array $item): string
    {
        $target = $item['target_value'] ?? $item['expected_target'] ?? null;
        if (is_string($target) || is_numeric($target)) {
            return mb_substr(trim((string)$target), 0, 200);
        }
        if (is_array($target) && $target !== []) {
            $parts = [];
            foreach (array_slice($target, 0, 5, true) as $key => $value) {
                if (is_scalar($value) && trim((string)$value) !== '') {
                    $parts[] = (string)$key . '=' . (string)$value;
                }
            }
            if ($parts !== []) {
                return mb_substr(implode('；', $parts), 0, 200);
            }
        }
        $delta = $item['expected_delta'] ?? null;
        if (is_numeric($delta) && (float)$delta !== 0.0) {
            return '变化量 ' . (string)$delta . '（单位沿用原指标口径）';
        }
        return '';
    }

    private function derivedRiskSummary(string $action, string $actionType, string $metric, array $dataBasis): string
    {
        if (($dataBasis['status'] ?? '') === 'missing') {
            return '缺少可追溯数据依据时执行，可能把错误门店、错误日期或错误口径当成经营事实。';
        }
        if (str_contains($actionType, 'price') || $metric === 'ota_adr') {
            return '价格调整可能以牺牲ADR换取订单，或因房型、日期、取消政策不可比而形成错误调价。';
        }
        if (str_contains($actionType, 'promotion') || $metric === 'advertising_roas') {
            return '投放或促销可能增加成本却未带来可归因订单，且短期波动可能被误判为效果。';
        }
        if ($metric === 'detail_rate') {
            return '页面素材调整可能降低点击或触发平台规范问题，单日波动不能证明优化有效。';
        }
        if ($metric === 'order_rate') {
            return '房型、政策或促销调整可能压缩收益或引入取消风险，转化变化需排除流量结构差异。';
        }
        if ($metric === 'data_completeness') {
            return '错误绑定、错误日期或错误字段回填会污染后续收益分析和AI决策。';
        }
        if (in_array($metric, ['monthly_net_cashflow', 'payback_months', 'rent_ratio', 'occupancy_rate'], true)) {
            return '投资建议依赖录入假设和情景公式；未经真实流水、租约与同口径经营样本复核，可能高估回报或低估现金流压力。';
        }
        if ($metric === 'opening_readiness') {
            return '只完成计划动作而没有验收证据，可能把开业准备进度误报为可上线状态。';
        }
        return '执行结果可能受同期价格、流量、库存或外部事件影响；缺少同口径前后样本时不能确认因果。';
    }

    /** @return array<int, string> */
    private function derivedRiskControls(string $actionType, string $metric, array $dataBasis): array
    {
        $controls = ['执行前由人工确认具体对象、日期范围和停止条件。'];
        if (($dataBasis['status'] ?? '') !== 'verified') {
            $controls[] = '先核对来源、酒店、日期、范围和质量状态，再决定是否执行。';
        }
        if (str_contains($actionType, 'price') || $metric === 'ota_adr') {
            $controls[] = '限定房型、入住日、价盘、早餐和取消政策，并同时复核订单与ADR。';
        } elseif ($metric === 'advertising_roas') {
            $controls[] = '设置预算上限，并使用同平台归因订单和成本证据复核ROAS。';
        } elseif ($metric === 'data_completeness') {
            $controls[] = '保存后执行数据库回读，禁止用旧数据、默认值或其他门店记录补位。';
        } else {
            $controls[] = '保留执行前基线、执行记录和复核时间，到期按同口径判断效果。';
        }
        return $controls;
    }

    private function dataBasisQualityNote(string $status, string $scope): string
    {
        return match ($status) {
            'verified' => '证据引用已标明可用/已验证；结论仍仅适用于 ' . $scope . ' 范围。',
            'partial' => '部分证据已验证，仍有来源或质量状态待确认。',
            'unverified' => '已列出依据，但来源或质量状态未完成验证。',
            default => '未提供可追溯的数据依据，不能进入可执行决策。',
        };
    }

    private function metricLabel(string $metric): string
    {
        $labels = [
            'data_completeness' => '数据完整性与回读状态',
            'advertising_roas' => 'OTA广告ROAS',
            'avg_psi_score' => 'OTA服务质量分',
            'detail_rate' => '曝光到访问转化率',
            'order_rate' => '访问到订单转化率',
            'ota_adr' => 'OTA渠道ADR/价格竞争力',
            'ota_revenue' => 'OTA渠道收入',
            'cancellation_rate' => 'OTA渠道取消率',
            'occupancy_rate' => '入住率情景',
            'monthly_net_cashflow' => '月净现金流',
            'payback_months' => '回本周期',
            'rent_ratio' => '租金占比',
            'roi' => '执行ROI',
            'opening_readiness' => '开业准备验收度',
            'orders' => 'OTA订单量',
            'conversion' => 'OTA渠道转化率',
            'business_metric_pending_definition' => '待定义效果指标',
        ];
        return $labels[$metric] ?? ($metric !== '' ? $metric : '待定义效果指标');
    }

    private function directionText(string $direction): string
    {
        return match ($direction) {
            'decrease' => '降低',
            'establish' => '补齐并验证',
            'verify' => '验证',
            default => '改善',
        };
    }

    private function isGenericAction(string $action): bool
    {
        $compact = preg_replace('/[\s，。；、,.!！?？]/u', '', trim($action)) ?? '';
        if ($compact === '') {
            return true;
        }
        $generic = [
            '加强管理', '提升服务', '优化运营', '持续关注', '保持关注', '提高效率',
            '改善经营', '加强培训', '做好服务', '综合提升', '继续优化', '进一步优化',
        ];
        foreach ($generic as $phrase) {
            if ($compact === $phrase || (mb_strlen($compact) <= 12 && str_contains($compact, $phrase))) {
                return true;
            }
        }
        $hasGenericDirection = false;
        foreach (['关注', '优化', '提升', '加强', '改善', '提高', '做好', '推进'] as $verb) {
            if (str_contains($compact, $verb)) {
                $hasGenericDirection = true;
                break;
            }
        }
        if (!$hasGenericDirection || preg_match('/\d/u', $compact)) {
            return false;
        }
        foreach ([
            '携程', '美团', 'OTA', '渠道', '门店', '酒店', '房型', '价盘', '房价', '调价',
            '库存', '订单', '收入', 'ADR', 'OCC', '入住率', '曝光', '访客', '转化率', 'ROAS',
            '主图', '标题', '取消政策', '促销', '投放', '预算', '样本', '字段', '数据源', '回读',
            '租金', '租约', '合同', '证照', '消防', 'PMS', '现金流', '回本', '负责人', '截止', '复核',
        ] as $specificCue) {
            if (mb_stripos($action, $specificCue) !== false) {
                return false;
            }
        }
        return true;
    }

    private function evidenceKey(mixed $source): string
    {
        if (is_string($source) || is_numeric($source)) {
            return trim((string)$source);
        }
        if (!is_array($source)) {
            return '';
        }
        foreach (['ref', 'key', 'source_ref', 'id'] as $field) {
            $value = trim((string)($source[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    /** @param array<int, array<string, mixed>> $refs */
    private function dedupeEvidenceRefs(array $refs): array
    {
        $seen = [];
        $result = [];
        foreach ($refs as $ref) {
            $key = trim((string)($ref['ref'] ?? ''));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $ref;
        }
        return $result;
    }

    private function isTrustedStatus(string $status): bool
    {
        return in_array(strtolower(trim($status)), self::TRUSTED_QUALITY_STATUSES, true);
    }

    /** @return array<int, mixed> */
    private function arrayList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (!is_array($value)) {
            return [$value];
        }
        if (array_is_list($value)) {
            return $value;
        }
        return [$value];
    }

    /** @return array<int, string> */
    private function stringList(mixed $value): array
    {
        $result = [];
        foreach ($this->arrayList($value) as $item) {
            if (is_scalar($item)) {
                $text = trim((string)$item);
                if ($text !== '') {
                    $result[] = mb_substr($text, 0, 240);
                }
            }
        }
        return array_values(array_unique($result));
    }

    private function firstText(array $source, array $fields): string
    {
        foreach ($fields as $field) {
            $value = $source[$field] ?? null;
            if (is_scalar($value)) {
                $text = trim((string)$value);
                if ($text !== '') {
                    return $text;
                }
            }
        }
        return '';
    }
}
