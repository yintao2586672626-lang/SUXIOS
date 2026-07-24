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
    public const CONTRACT_VERSION = 'ai_recommendation_quality.v2';

    private const TRUSTED_QUALITY_STATUSES = [
        'available', 'verified', 'readback_verified', 'decision_eligible',
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
        $actionSpecificity = $this->actionSpecificity($action, $item, $expectedEffect);
        $genericTalk = ($actionSpecificity['specific'] ?? false) !== true;
        $effectReady = $this->expectedEffectIsDecisionReady($expectedEffect);
        $riskReady = $this->riskIsDecisionReady($risk);
        $priorityBasis = $this->buildPriorityBasis($item, $priority, $risk, $dataBasis, $expectedEffect, $actionSpecificity);

        $formatMissing = [];
        if ($dataBasis['status'] === 'missing') {
            $formatMissing[] = 'data_basis';
        }
        if ($expectedEffect['status'] === 'missing') {
            $formatMissing[] = 'expected_effect';
        }
        if ($risk['status'] === 'missing') {
            $formatMissing[] = 'risk';
        }
        if ($genericTalk) {
            $formatMissing[] = 'action_specificity';
        }
        $formatMissing = array_values(array_unique($formatMissing));
        $missing = $formatMissing;
        if (($dataBasis['status'] ?? 'missing') !== 'verified') {
            $missing[] = 'data_basis_verification';
        }
        if (!$effectReady) {
            $missing[] = 'expected_effect_evidence';
        }
        if (!$riskReady) {
            $missing[] = 'risk_controls';
        }
        $missing = array_values(array_unique($missing));

        $formatComplete = $formatMissing === [];
        $qualityComplete = $formatComplete
            && ($dataBasis['status'] ?? '') === 'verified'
            && $effectReady
            && $riskReady;
        $upstreamAllowsIntent = ($item['can_create_execution_intent'] ?? true) !== false;
        $isInvestigationOnly = in_array(
            strtolower(trim((string)($item['recommendation_type'] ?? ''))),
            ['investigation', 'investigation_only'],
            true
        );
        $executionReady = $qualityComplete && $upstreamAllowsIntent && !$isInvestigationOnly;

        $qualityStatus = 'ready_for_human_review';
        if (!$formatComplete) {
            $qualityStatus = 'incomplete';
        } elseif (!$qualityComplete) {
            $qualityStatus = 'requires_evidence_confirmation';
        }

        if ($genericTalk) {
            if ($this->isGenericAction($title)) {
                $title = '建议不合格，需补齐具体动作';
            }
            $action = '补充明确的对象、日期、执行步骤和复核指标后重新生成建议；当前建议不得执行。';
        }

        $item['title'] = mb_substr($title, 0, 100);
        $item['action'] = mb_substr($action, 0, 500);
        if ($genericTalk || trim((string)($item['detail'] ?? '')) === '') {
            $item['detail'] = $item['action'];
        }
        $item['priority'] = $priority;
        $item['priority_basis'] = $priorityBasis;
        $item['priority_reason'] = $this->priorityReason($priority, $risk, $dataBasis, $item, $priorityBasis);
        $item['data_basis'] = $dataBasis;
        $item['expected_effect'] = $expectedEffect;
        $item['risk'] = $risk;
        $item['risk_level'] = (string)$risk['level'];
        $item['decision_quality'] = [
            'contract_version' => self::CONTRACT_VERSION,
            'status' => $qualityStatus,
            'format_complete' => $formatComplete,
            'complete' => $qualityComplete,
            'execution_ready' => $executionReady,
            'effect_ready' => $effectReady,
            'risk_ready' => $riskReady,
            'missing_fields' => $missing,
            'generic_talk_rejected' => $genericTalk,
            'action_specificity' => $actionSpecificity,
            'human_confirmation_required' => true,
            'scope' => (string)$dataBasis['scope'],
        ];

        $item['can_create_execution_intent'] = $executionReady;
        if (!$executionReady) {
            if (trim((string)($item['blocked_reason'] ?? '')) === '') {
                $item['blocked_reason'] = $this->blockedReason(
                    $genericTalk,
                    $dataBasis,
                    $expectedEffect,
                    $effectReady,
                    $riskReady,
                    $isInvestigationOnly,
                    $upstreamAllowsIntent
                );
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
        $formatComplete = 0;
        $executionReady = 0;
        $evidenceBlocked = 0;
        $incomplete = 0;
        foreach ($items as $item) {
            $quality = is_array($item['decision_quality'] ?? null) ? $item['decision_quality'] : [];
            if (($quality['complete'] ?? false) === true) {
                $complete++;
            }
            if (($quality['format_complete'] ?? false) === true) {
                $formatComplete++;
            }
            if (($quality['execution_ready'] ?? false) === true) {
                $executionReady++;
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
            'format_complete_count' => $formatComplete,
            'complete_count' => $complete,
            'execution_ready_count' => $executionReady,
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
        $bindingContext = $context;
        $declaredPlatform = strtolower(trim((string)($item['platform'] ?? '')));
        $actionText = $this->firstText($item, ['action', 'suggested_action', 'suggestion', 'detail', 'content', 'title']);
        $mentionsCtrip = preg_match('/携程|ctrip/i', $actionText) === 1;
        $mentionsMeituan = preg_match('/美团|meituan/i', $actionText) === 1;
        $actionPlatform = ($mentionsCtrip xor $mentionsMeituan)
            ? ($mentionsCtrip ? 'ctrip' : 'meituan')
            : '';
        $contextPlatform = strtolower(trim((string)($context['platform'] ?? '')));
        $exactPlatforms = ['ctrip', 'meituan'];
        $declaredExact = in_array($declaredPlatform, $exactPlatforms, true) ? $declaredPlatform : '';
        $platformBindingConflict = $declaredExact !== ''
            && $actionPlatform !== ''
            && $declaredExact !== $actionPlatform;

        if (in_array($contextPlatform, $exactPlatforms, true)) {
            $bindingContext['platform'] = $contextPlatform;
            $platformBindingConflict = $platformBindingConflict
                || ($declaredExact !== '' && $declaredExact !== $contextPlatform)
                || ($actionPlatform !== '' && $actionPlatform !== $contextPlatform);
        } else {
            $effectivePlatform = $actionPlatform !== ''
                ? $actionPlatform
                : ($declaredExact !== '' ? $declaredExact : $contextPlatform);
            if ($effectivePlatform !== '') {
                $bindingContext['platform'] = $effectivePlatform;
            }
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

        $contextRefs = [];
        $contextRefsByKey = [];
        foreach ($contextSources as $source) {
            $key = $this->evidenceKey($source);
            $normalized = $this->normalizeEvidenceRef($source, $scope, $bindingContext, 'server_context');
            if ($normalized !== null) {
                $normalized = $this->applyEvidenceBinding($normalized, $scope, $bindingContext);
                $contextRefs[] = $normalized;
                if ($key !== '') {
                    $contextRefsByKey[$key] = $normalized;
                }
            }
        }

        $refs = [];
        if ($requested === []) {
            $refs = $contextRefs;
        } else {
            foreach ($requested as $source) {
                $key = $this->evidenceKey($source);
                if ($key !== '' && isset($contextRefsByKey[$key])) {
                    $refs[] = $contextRefsByKey[$key];
                    continue;
                }
                $normalized = $this->normalizeEvidenceRef($source, $scope, $bindingContext, 'untrusted_recommendation');
                if ($normalized !== null) {
                    $refs[] = $this->applyEvidenceBinding($normalized, $scope, $bindingContext);
                }
            }
        }

        $refs = $this->dedupeEvidenceRefs($refs);
        if ($platformBindingConflict) {
            foreach ($refs as &$ref) {
                $ref['quality_status'] = 'binding_missing';
                $ref['binding_status'] = 'recommendation_platform_conflict';
            }
            unset($ref);
        }
        $statuses = array_values(array_filter(array_map(
            static fn(array $ref): string => trim((string)($ref['quality_status'] ?? '')),
            $refs
        )));
        $trustedCount = count(array_filter($statuses, fn(string $status): bool => $this->isTrustedStatus($status)));
        $status = 'missing';
        if ($refs !== []) {
            if (in_array('binding_missing', $statuses, true)) {
                $status = 'binding_missing';
            } elseif (in_array('stale', $statuses, true)) {
                $status = 'stale';
            } else {
                $status = $trustedCount === count($refs)
                    ? 'verified'
                    : ($trustedCount > 0 ? 'partial' : 'unverified');
            }
        }
        if ($platformBindingConflict) {
            $status = 'binding_missing';
        }

        $basisSummary = $this->firstText($item, ['reason', 'evidence_summary', 'basis_summary']);
        if ($basisSummary === '' && is_array($item['data_basis'] ?? null)) {
            $basisSummary = trim((string)($item['data_basis']['summary'] ?? ''));
        }
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
            'platform' => trim((string)($bindingContext['platform'] ?? '')),
            'platform_conflict' => $platformBindingConflict,
            'date' => trim((string)($context['data_date'] ?? $context['report_date'] ?? '')),
            'date_range' => is_array($context['date_range'] ?? null) ? $context['date_range'] : [],
            'summary' => mb_substr($basisSummary, 0, 500),
            'refs' => array_slice($refs, 0, 8),
            'ref_count' => count($refs),
            'quality_note' => $this->dataBasisQualityNote($status, $scope),
        ];
    }

    /** @return array<string, mixed>|null */
    private function normalizeEvidenceRef(
        mixed $source,
        string $scope,
        array $context,
        string $authority = 'server_context'
    ): ?array
    {
        if (is_string($source) || is_numeric($source)) {
            $value = trim((string)$source);
            if ($value === '') {
                return null;
            }
            return [
                'ref' => $value,
                'source' => $value,
                'date' => '',
                'scope' => $scope,
                'quality_status' => 'unverified',
                'source_status' => 'unverified',
                'authority' => $authority,
                'hotel_id' => 0,
                'platform' => '',
                'binding_status' => 'not_checked',
                'date_role' => '',
                'date_inherited' => false,
                'metric_keys' => [],
            ];
        }
        if (!is_array($source)) {
            return null;
        }

        $ref = $this->evidenceKey($source);
        $sourceStatus = strtolower(trim((string)($source['quality_status']
            ?? $source['validation_status']
            ?? $source['verification_status']
            ?? $source['data_status']
            ?? $source['status']
            ?? '')));
        $quality = 'unverified';
        if ($authority === 'server_context') {
            if (($source['decision_eligible'] ?? null) === true || ($source['readback_verified'] ?? null) === true) {
                $quality = 'verified';
            } elseif ($this->isTrustedStatus($sourceStatus)) {
                $quality = 'verified';
            } elseif (in_array($sourceStatus, ['partial', 'stale', 'binding_missing'], true)) {
                $quality = $sourceStatus;
            }
        }

        $sourceDate = trim((string)($source['data_date']
            ?? $source['date']
            ?? $source['report_date']
            ?? $source['snapshot_time']
            ?? ''));
        $sourceHotelId = (int)($source['system_hotel_id'] ?? $source['hotel_id'] ?? 0);
        $sourcePlatform = trim((string)($source['platform'] ?? $source['source_platform'] ?? ''));

        return [
            'ref' => $ref !== '' ? $ref : 'evidence_' . substr(sha1(json_encode($source, JSON_UNESCAPED_UNICODE) ?: ''), 0, 10),
            'source' => mb_substr(trim((string)($source['source'] ?? $source['table'] ?? $source['title'] ?? $source['label'] ?? '')), 0, 120),
            'date' => $sourceDate,
            'scope' => trim((string)($source['scope'] ?? $source['metric_scope'] ?? $scope)),
            'quality_status' => $quality,
            'source_status' => $sourceStatus !== '' ? $sourceStatus : 'unverified',
            'authority' => $authority,
            'hotel_id' => $sourceHotelId,
            'platform' => $sourcePlatform,
            'binding_status' => 'not_checked',
            'date_role' => trim((string)($source['date_role'] ?? $source['evidence_date_role'] ?? '')),
            'date_inherited' => false,
            'metric_keys' => array_slice(array_values(array_unique(array_filter(array_map(
                'strval',
                array_merge($this->arrayList($source['metric_keys'] ?? []), $this->arrayList($source['tags'] ?? []))
            )))), 0, 12),
            'summary' => mb_substr(trim((string)($source['summary'] ?? $source['label'] ?? $source['evidence'] ?? '')), 0, 240),
        ];
    }

    /** @return array<string, mixed> */
    private function applyEvidenceBinding(array $ref, string $scope, array $context): array
    {
        if (($ref['authority'] ?? '') !== 'server_context') {
            $ref['quality_status'] = 'unverified';
            $ref['binding_status'] = 'untrusted_authority';
            return $ref;
        }

        $expectedHotelId = (int)($context['hotel_id'] ?? 0);
        $actualHotelId = (int)($ref['hotel_id'] ?? 0);
        if ($this->scopeRequiresHotelBinding($scope)) {
            if ($expectedHotelId <= 0 || $actualHotelId <= 0 || $actualHotelId !== $expectedHotelId) {
                $ref['quality_status'] = 'binding_missing';
                $ref['binding_status'] = $actualHotelId <= 0 ? 'hotel_missing' : 'hotel_mismatch';
                return $ref;
            }
        }

        $expectedPlatform = strtolower(trim((string)($context['platform'] ?? '')));
        $actualPlatform = strtolower(trim((string)($ref['platform'] ?? '')));
        if ($expectedPlatform !== '' && !in_array($expectedPlatform, ['all', 'multi'], true)) {
            $platformMatched = $expectedPlatform === 'ota'
                ? in_array($actualPlatform, ['ota', 'ctrip', 'meituan'], true)
                : $actualPlatform === $expectedPlatform;
            if (!$platformMatched) {
                $ref['quality_status'] = 'binding_missing';
                $ref['binding_status'] = $actualPlatform === '' ? 'platform_missing' : 'platform_mismatch';
                return $ref;
            }
        }

        $targetDate = $this->dateOnly((string)($context['data_date'] ?? $context['report_date'] ?? ''));
        $evidenceDate = $this->dateOnly((string)($ref['date'] ?? ''));
        $targetRange = is_array($context['date_range'] ?? null) ? $context['date_range'] : [];
        $rangeStart = $this->dateOnly((string)($targetRange['start'] ?? $targetRange['start_date'] ?? ''));
        $rangeEnd = $this->dateOnly((string)($targetRange['end'] ?? $targetRange['end_date'] ?? ''));
        if ($this->evidenceRequiresTargetDate($ref) && ($targetDate !== '' || $rangeStart !== '' || $rangeEnd !== '')) {
            $dateMatched = $evidenceDate !== '';
            if ($targetDate !== '') {
                $dateMatched = $dateMatched && $evidenceDate === $targetDate;
            } else {
                if ($rangeStart !== '') {
                    $dateMatched = $dateMatched && $evidenceDate >= $rangeStart;
                }
                if ($rangeEnd !== '') {
                    $dateMatched = $dateMatched && $evidenceDate <= $rangeEnd;
                }
            }
            if (!$dateMatched) {
                $ref['quality_status'] = $evidenceDate === '' ? 'binding_missing' : 'stale';
                $ref['binding_status'] = $evidenceDate === '' ? 'date_missing' : 'date_mismatch';
                return $ref;
            }
        }

        $ref['binding_status'] = 'matched';
        return $ref;
    }

    private function scopeRequiresHotelBinding(string $scope): bool
    {
        $scope = strtolower(trim($scope));
        if ($scope === '' || $scope === 'unknown') {
            return false;
        }
        foreach (['multi_hotel', 'portfolio', 'all_permitted', 'investment', 'market', 'same_city', 'target_area'] as $excluded) {
            if (str_contains($scope, $excluded)) {
                return false;
            }
        }
        return str_contains($scope, 'ota')
            || str_contains($scope, 'hotel')
            || str_contains($scope, 'opening')
            || str_contains($scope, 'operation');
    }

    /** @param array<string, mixed> $ref */
    private function evidenceRequiresTargetDate(array $ref): bool
    {
        $role = strtolower(trim((string)($ref['date_role'] ?? '')));
        if (in_array($role, ['historical', 'reference', 'comparison', 'generated_at', 'backtest'], true)) {
            return false;
        }
        if (in_array($role, ['target', 'observation', 'report_date'], true)) {
            return true;
        }
        $source = strtolower(trim((string)($ref['source'] ?? '')));
        return str_contains($source, 'online_daily_data')
            || str_contains($source, 'daily_report')
            || str_contains($source, 'collector_task')
            || str_contains($source, 'target_date');
    }

    private function dateOnly(string $value): string
    {
        $value = trim($value);
        if (preg_match('/\d{4}-\d{2}-\d{2}/', $value, $matches) === 1) {
            return (string)$matches[0];
        }
        return '';
    }

    /** @return array<string, mixed> */
    private function buildExpectedEffect(array $item, string $action, array $context): array
    {
        $existing = $item['expected_effect'] ?? $item['expected_impact'] ?? null;
        $policy = is_array($context['expected_effect_policy'] ?? null)
            ? $context['expected_effect_policy']
            : [];
        $effectAuthority = strtolower(trim((string)($context['expected_effect_authority'] ?? '')));
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
        $origin = 'inferred_directional';
        $authority = 'none';
        if ($policy !== []) {
            $metric = trim((string)($policy['metric'] ?? $metric));
            $direction = trim((string)($policy['direction'] ?? $direction));
            $reviewWindow = trim((string)($policy['review_window'] ?? $reviewWindow));
            $summary = trim((string)($policy['summary'] ?? ''));
            $status = trim((string)($policy['status'] ?? 'verification_target')) ?: 'verification_target';
            $origin = 'server_policy_verification_target';
            $authority = 'server_policy';
        } elseif (is_array($existing)) {
            $summary = trim((string)($existing['summary'] ?? $existing['description'] ?? ''));
            $providedStatus = trim((string)($existing['status'] ?? ''));
            $status = $providedStatus !== ''
                ? $providedStatus
                : ($summary !== '' ? 'provided_directional' : $status);
            $metric = trim((string)($existing['metric'] ?? $metric));
            $direction = trim((string)($existing['direction'] ?? $direction));
            $reviewWindow = trim((string)($existing['review_window'] ?? $reviewWindow));
            if ($summary !== '') {
                $origin = $this->trustedExpectedEffectOrigin($effectAuthority);
                $authority = $effectAuthority !== '' ? $effectAuthority : 'untrusted_recommendation';
            }
        } elseif (is_string($existing) || is_numeric($existing)) {
            $summary = trim((string)$existing);
            if ($summary !== '') {
                $status = preg_match('/\d/', $summary) ? 'quantified' : 'provided_directional';
                $origin = $this->trustedExpectedEffectOrigin($effectAuthority);
                $authority = $effectAuthority !== '' ? $effectAuthority : 'untrusted_recommendation';
            }
        }

        $target = $policy !== []
            ? trim((string)($policy['target'] ?? ''))
            : $this->expectedTarget($item);
        if ($target !== '') {
            $status = 'quantified';
            if ($policy !== []) {
                $origin = 'server_policy_verification_target';
            } elseif (!in_array($origin, ['verified_backtest', 'operator_confirmed', 'deterministic_calculation'], true)) {
                $origin = 'recommendation_provided_unverified';
            }
        }
        if ($summary === '') {
            if ($metric === 'data_completeness') {
                $summary = '预期把相关数据从缺失或未验证推进到已保存、可回读、可追溯；这不等同于经营指标改善。';
                $status = 'verification_target';
                $origin = 'policy_verification_target';
                $authority = 'server_policy';
            } elseif (in_array((string)($item['recommendation_type'] ?? ''), ['investigation', 'investigation_only'], true)) {
                $summary = '预期形成可核验原因或排除项；完成核验前不把调查动作写成经营改善。';
                $status = 'verification_target';
                $origin = 'policy_verification_target';
                $authority = 'server_policy';
            } elseif ($metric !== '' && $metric !== 'business_metric_pending_definition') {
                $summary = '预期方向：' . $this->directionText($direction) . $this->metricLabel($metric)
                    . '；当前缺少回测或前后对照证据，不承诺具体提升幅度。';
                $origin = 'inferred_directional';
            } else {
                $summary = '当前缺少可量化的效果指标；补齐目标指标和对照周期前，不把该建议视为已验证决策。';
                $status = 'missing';
                $origin = 'missing';
            }
        }

        return [
            'status' => $status,
            'origin' => $origin,
            'authority' => $authority,
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
        $origin = 'policy_derived';
        if (is_array($existing)) {
            $summary = trim((string)($existing['summary'] ?? $existing['description'] ?? ''));
            $levelRaw = $existing['level'] ?? $levelRaw;
            $controls = $this->stringList($existing['controls'] ?? $existing['mitigations'] ?? []);
            $status = trim((string)($existing['status'] ?? 'provided'));
            if ($summary !== '') {
                $origin = 'provided';
            }
        } elseif (is_string($existing) || is_numeric($existing)) {
            $summary = trim((string)$existing);
            if ($summary !== '') {
                $status = 'provided';
                $origin = 'provided';
            }
        }
        if ($summary === '') {
            $summary = $this->firstText($item, ['risk_summary', 'risk_note']);
            if ($summary !== '') {
                $status = 'provided';
                $origin = 'provided';
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
            'origin' => $origin,
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

    /** @return array<string, mixed> */
    private function buildPriorityBasis(
        array $item,
        string $priority,
        array $risk,
        array $dataBasis,
        array $expectedEffect,
        array $actionSpecificity
    ): array {
        $hasTarget = trim((string)($expectedEffect['target'] ?? '')) !== '';
        $effectOrigin = (string)($expectedEffect['origin'] ?? 'missing');
        $effectReady = $this->expectedEffectIsDecisionReady($expectedEffect);
        $measuredOrigin = in_array($effectOrigin, ['verified_backtest', 'deterministic_calculation', 'operator_confirmed'], true);
        $impact = $effectReady
            ? ($hasTarget && $measuredOrigin ? 'measured_target' : 'verification_target')
            : 'not_quantified';
        $impactPoints = match ($impact) {
            'critical', 'high', 'measured_target' => 30,
            'medium', 'provided_direction', 'verification_target' => 20,
            default => 8,
        };

        $confidence = (string)($dataBasis['status'] ?? 'missing');
        $confidencePoints = match ($confidence) {
            'verified' => 30,
            'partial' => 12,
            default => 0,
        };

        $urgencyRaw = strtolower(trim((string)($item['urgency'] ?? $item['urgency_level'] ?? '')));
        $urgency = $urgencyRaw !== ''
            ? $urgencyRaw
            : (($actionSpecificity['timing'] ?? false) === true ? 'time_bound' : 'not_stated');
        $urgencyPoints = match ($urgency) {
            'critical', 'urgent', 'high' => 20,
            'medium', 'time_bound' => 12,
            default => 4,
        };

        $effortRaw = strtolower(trim((string)($item['effort'] ?? $item['effort_level'] ?? '')));
        $effort = $effortRaw !== '' ? $effortRaw : 'not_stated';
        $effortPenalty = match ($effort) {
            'high', 'large' => 10,
            'medium' => 6,
            'low', 'small' => 2,
            default => 4,
        };

        $riskLevel = (string)($risk['level'] ?? 'unverified');
        $riskPoints = match ($riskLevel) {
            'high' => 20,
            'medium' => 12,
            'low' => 6,
            default => 4,
        };
        $score = max(0, min(100, $impactPoints + $confidencePoints + $urgencyPoints + $riskPoints - $effortPenalty));

        return [
            'status' => $confidence === 'verified' && $effectReady
                ? 'supported'
                : 'limited',
            'score' => $score,
            'declared_priority' => $priority,
            'source' => trim((string)($item['priority'] ?? '')) !== '' ? 'declared_with_server_factors' : 'server_derived',
            'factors' => [
                'impact' => $impact,
                'confidence' => $confidence,
                'urgency' => $urgency,
                'effort' => $effort,
                'risk' => $riskLevel,
            ],
        ];
    }

    private function priorityReason(
        string $priority,
        array $risk,
        array $dataBasis,
        array $item,
        array $priorityBasis = []
    ): string
    {
        $provided = trim((string)($item['priority_reason'] ?? ''));
        $factors = is_array($priorityBasis['factors'] ?? null) ? $priorityBasis['factors'] : [];
        $computed = sprintf(
            '%s：评分%d/100；证据%s；影响%s；紧迫度%s；投入%s；风险%s。',
            $priority,
            (int)($priorityBasis['score'] ?? 0),
            (string)($factors['confidence'] ?? $dataBasis['status'] ?? 'missing'),
            (string)($factors['impact'] ?? 'not_quantified'),
            (string)($factors['urgency'] ?? 'not_stated'),
            (string)($factors['effort'] ?? 'not_stated'),
            (string)($factors['risk'] ?? $risk['level'] ?? 'unverified')
        );
        if ($provided !== '') {
            return mb_substr($provided . '；系统校验：' . $computed, 0, 500);
        }
        return $computed;
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
            'stale' => '证据日期与本次目标日期不一致，仅可作为历史参考，不得直接执行。',
            'binding_missing' => '证据未完成同酒店、同平台或目标日期绑定，不得进入执行。',
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
        return ($this->actionSpecificity($action, [], [])['specific'] ?? false) !== true;
    }

    /** @return array<string, mixed> */
    private function actionSpecificity(string $action, array $item, array $expectedEffect): array
    {
        $text = trim($action);
        $compact = preg_replace('/[\s，。；、,.!！?？]/u', '', $text) ?? '';
        if ($compact === '') {
            return [
                'specific' => false,
                'object' => false,
                'step' => false,
                'timing' => false,
                'review_metric' => false,
                'missing_dimensions' => ['object', 'step', 'timing_or_review_metric'],
            ];
        }

        $explicitObject = $this->firstText($item, ['action_object', 'target_object', 'object_name', 'room_type_name', 'object_type']) !== '';
        $hasObject = $explicitObject || preg_match(
            '/携程|美团|OTA|渠道|门店|酒店|房型|价盘|房价|价格|库存|订单|收入|ADR|OCC|入住率|曝光|访客|转化率|ROAS|主图|标题|取消政策|促销|投放|预算|样本|字段|数据源|回读|租金|租约|合同|证照|消防|PMS|现金流|回本|项目|任务|Ctrip|Meituan|hotel|room|rate|price|inventory|order|revenue|cashflow/i',
            $text
        ) === 1;
        $explicitSteps = $this->arrayList($item['execution_steps'] ?? $item['steps'] ?? []);
        $hasStep = $explicitSteps !== [] || preg_match(
            '/复核|核验|核对|采集|校准|调整|记录|保存|回读|比较|对照|验证|替换|同步|补齐|检查|设置|导出|上传|分派|联系|审批|停止|回滚|完成|review|verify|compare|adjust|record|save|readback|replace|sync|check|set|export|upload|assign|stop|rollback/i',
            $text
        ) === 1;
        $explicitTiming = $this->firstText($item, ['date_start', 'date_end', 'target_date', 'deadline', 'due_at', 'review_at', 'review_window']) !== ''
            || trim((string)($expectedEffect['review_window'] ?? '')) !== '';
        $hasTiming = $explicitTiming || preg_match(
            '/\d{4}[-\/.年]\d{1,2}|未来\s*\d+\s*(天|日|周)|\d+\s*(天|日|周|小时)内|今日|今天|明日|明天|昨日|昨天|本周|下周|截止|执行后|调整前后|before|after|within|today|tomorrow|yesterday|daily|weekly/i',
            $text
        ) === 1;

        $effectMetric = trim((string)($item['expected_metric']
            ?? (is_array($item['expected_effect'] ?? null) ? ($item['expected_effect']['metric'] ?? null) : null)
            ?? ($expectedEffect['metric'] ?? '')
            ?? ''));
        $explicitReview = $effectMetric !== ''
            || $this->firstText($item, ['review_metric', 'success_metric', 'expected_target']) !== ''
            || !empty($item['target_value'])
            || (is_numeric($item['expected_delta'] ?? null) && (float)$item['expected_delta'] !== 0.0);
        $hasReviewMetric = $explicitReview || preg_match(
            '/ADR|OCC|入住率|订单|间夜|收入|转化|ROAS|现金流|回本|完成率|评分|进度|基线|前后结果|复核指标|baseline|conversion|revenue|orders|occupancy|metric|result/i',
            $text
        ) === 1;

        $missing = [];
        if (!$hasObject) {
            $missing[] = 'object';
        }
        if (!$hasStep) {
            $missing[] = 'step';
        }
        if (!$hasTiming && !$hasReviewMetric) {
            $missing[] = 'timing_or_review_metric';
        }

        return [
            'specific' => $missing === [],
            'object' => $hasObject,
            'step' => $hasStep,
            'timing' => $hasTiming,
            'review_metric' => $hasReviewMetric,
            'missing_dimensions' => $missing,
        ];
    }

    private function expectedEffectIsDecisionReady(array $effect): bool
    {
        $status = (string)($effect['status'] ?? 'missing');
        $origin = (string)($effect['origin'] ?? 'missing');
        $metric = (string)($effect['metric'] ?? 'business_metric_pending_definition');
        return $status !== 'missing'
            && in_array($origin, [
                'server_policy_verification_target',
                'policy_verification_target',
                'verified_backtest',
                'operator_confirmed',
                'deterministic_calculation',
            ], true)
            && $metric !== ''
            && $metric !== 'business_metric_pending_definition'
            && trim((string)($effect['summary'] ?? '')) !== ''
            && trim((string)($effect['review_window'] ?? '')) !== '';
    }

    private function trustedExpectedEffectOrigin(string $authority): string
    {
        return match (strtolower(trim($authority))) {
            'server_policy' => 'server_policy_verification_target',
            'measured', 'backtest', 'verified_backtest' => 'verified_backtest',
            'operator_confirmed', 'human_confirmed' => 'operator_confirmed',
            'deterministic', 'deterministic_calculation' => 'deterministic_calculation',
            default => 'recommendation_provided_unverified',
        };
    }

    private function riskIsDecisionReady(array $risk): bool
    {
        return ($risk['status'] ?? 'missing') !== 'missing'
            && in_array((string)($risk['level'] ?? ''), ['high', 'medium', 'low'], true)
            && trim((string)($risk['summary'] ?? '')) !== ''
            && $this->stringList($risk['controls'] ?? []) !== [];
    }

    /** @return string */
    private function blockedReason(
        bool $genericTalk,
        array $dataBasis,
        array $expectedEffect,
        bool $effectReady,
        bool $riskReady,
        bool $isInvestigationOnly,
        bool $upstreamAllowsIntent
    ): string {
        if (!$upstreamAllowsIntent) {
            return '上游业务门禁已阻断该建议，不能创建执行意图。';
        }
        if ($isInvestigationOnly) {
            return '当前仅为调查或补证建议，不得转换为经营执行意图。';
        }
        if ($genericTalk) {
            return '建议只有宽泛方向，缺少具体对象、执行步骤以及日期或复核指标，不能进入运营执行。';
        }
        return match ((string)($dataBasis['status'] ?? 'missing')) {
            'binding_missing' => '证据缺少同酒店、同平台或目标日期绑定，不能进入运营执行。',
            'stale' => '证据日期与目标日期不一致，不能进入运营执行。',
            'partial' => '仅部分证据完成验证，不能进入运营执行。',
            'unverified' => '数据依据尚未完成持久化与数据库回读验证，不能进入运营执行。',
            'missing' => '建议缺少可追溯的数据依据，不能进入运营执行。',
            default => !$effectReady
                ? '预期效果仅为系统推导方向，缺少明确指标、对照周期或人工提供的效果说明，不能进入运营执行。'
                : (!$riskReady
                    ? '风险或控制措施不完整，不能进入运营执行。'
                    : '当前建议未通过执行资格校验。'),
        };
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
