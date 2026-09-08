<?php
declare(strict_types=1);

namespace app\service;

/** Pure applicability contract. Authorization remains with authenticated callers. */
final class KnowledgeApplicabilityService
{
    public const CONTRACT = 'knowledge_applicability.v1';

    public function assess(array $unit, array $content, array $context = [], array $chunk = []): array
    {
        $gate = (new KnowledgeDecisionGateService())->assess($unit, $content, $context['as_of'] ?? $context['business_date'] ?? null);
        $reasons = [];
        $hotelId = (int)($context['hotel_id'] ?? 0);
        $tenantId = (int)($context['tenant_id'] ?? 0);
        if ((int)($unit['hotel_id'] ?? 0) > 0 && (int)$unit['hotel_id'] !== $hotelId) {
            $reasons[] = 'knowledge_hotel_mismatch';
        }
        if ((int)($unit['tenant_id'] ?? 0) > 0 && (int)$unit['tenant_id'] !== $tenantId) {
            $reasons[] = $tenantId > 0 ? 'knowledge_tenant_mismatch' : 'knowledge_tenant_missing';
        }
        $rules = is_array($content['applicability'] ?? null) ? $content['applicability'] : [];
        if (array_key_exists('applicability', $content) && !is_array($content['applicability'])) {
            $reasons[] = 'knowledge_conditions_invalid';
        }
        foreach (['hotel_ids' => $hotelId, 'tenant_ids' => $tenantId] as $field => $requested) {
            foreach ([$content, $rules] as $declaration) {
                $values = $declaration[$field] ?? [];
                if ($values !== [] && (!is_array($values) || !in_array($requested, $values, true))) {
                    $reasons[] = 'knowledge_' . ($field === 'hotel_ids' ? 'hotel' : 'tenant') . ($requested > 0 ? '_mismatch' : '_missing');
                }
            }
        }
        // Each declaration narrows scope. An empty intersection is blocked,
        // whereas an absent declaration retains legacy platform-neutral use.
        $platforms = null;
        foreach ([$content, $rules] as $declaration) {
            $declared = $this->platforms($declaration['platforms'] ?? []);
            if ($declared === []) continue;
            if (in_array('all_ota', $declared, true)) {
                $declared = array_values(array_unique(array_merge(array_diff($declared, ['all_ota']), ['ctrip', 'meituan'])));
            }
            $platforms = $platforms === null ? $declared : array_values(array_intersect($platforms, $declared));
        }
        $platform = $this->platform((string)($context['platform'] ?? ''));
        if ($platforms !== null) {
            $requestedPlatforms = $platform === 'all_ota' ? ['ctrip', 'meituan'] : [$platform];
            if (!in_array($platform, ['ctrip', 'meituan', 'qunar', 'dianping', 'pms', 'dingdandao', 'suxios_internal', 'all_ota'], true) || array_intersect($requestedPlatforms, $platforms) === []) {
                $reasons[] = $platform === '' ? 'knowledge_platform_missing' : 'knowledge_platform_mismatch';
            }
        }
        $platforms ??= [];
        $conditions = $rules['hotel_conditions'] ?? [];
        if (!is_array($conditions)) {
            $reasons[] = 'knowledge_conditions_invalid';
        } else {
            $observed = is_array($context['hotel_conditions'] ?? null) ? $context['hotel_conditions'] : [];
            foreach ($conditions as $key => $allowed) {
                if (!array_key_exists($key, $observed)) {
                    $reasons[] = 'knowledge_condition_missing:' . $key;
                } elseif (!in_array($observed[$key], is_array($allowed) ? $allowed : [$allowed], true)) {
                    $reasons[] = 'knowledge_condition_mismatch:' . $key;
                }
            }
        }
        if (!empty($chunk['superseded_by_chunk_id']) || !empty($chunk['_revision_superseded'])) {
            $reasons[] = 'knowledge_version_superseded';
        }
        if (isset($chunk['lifecycle_status']) && !in_array($chunk['lifecycle_status'], ['', 'active', null], true)) {
            $reasons[] = 'knowledge_chunk_not_active';
        }
        $digest = (new KnowledgeContentDigestService())->digest($content);
        if (!empty($chunk['content_digest']) && !hash_equals(strtolower((string)$chunk['content_digest']), $digest)) {
            $reasons[] = 'knowledge_content_digest_mismatch';
        }
        if ($reasons !== []) {
            foreach (['reference_safe', 'retrieval_safe', 'decision_safe', 'task_draft_safe'] as $field) {
                $gate[$field] = false;
            }
            $gate['status'] = 'blocked';
            $gate['status_label'] = '当前范围不可用';
        }
        $gate['reason_codes'] = array_values(array_unique(array_merge($reasons, $gate['reason_codes'])));
        $gate['primary_reason'] = $gate['reason_codes'][0] ?? '';
        $gate['reason_labels'] = array_map([$this, 'reasonLabel'], $gate['reason_codes']);
        return $gate + [
            'contract' => self::CONTRACT,
            'scope' => ['hotel_id' => $hotelId, 'tenant_id' => $tenantId ?: null, 'platform' => $platform, 'platforms' => $platforms, 'hotel_conditions' => $context['hotel_conditions'] ?? []],
            'version' => $content['knowledge_revision']['number'] ?? $chunk['version_no'] ?? $content['seed_version'] ?? null,
            'content_digest' => $digest,
            'source_refs' => $content['source_refs'] ?? [],
            'warnings' => array_values(array_filter([
                empty($content['source_verification_status']) ? 'knowledge_source_verification_not_recorded' : null,
                empty($content['valid_until']) ? 'knowledge_valid_until_not_recorded' : null,
                'knowledge_is_not_hotel_fact_or_authorization',
            ])),
        ];
    }

    public function platform(string $value): string
    {
        return match (strtolower(trim($value))) {
            '携程', 'xc', 'xiecheng' => 'ctrip', '美团', 'mt' => 'meituan',
            '去哪儿', 'qunar.com' => 'qunar', 'all', 'ota', 'all-ota' => 'all_ota',
            default => strtolower(trim($value)),
        };
    }

    public function reasonLabel(string $reason): string
    {
        if (str_starts_with($reason, 'knowledge_condition_missing:')) return '缺少酒店适用条件：' . substr($reason, 28);
        if (str_starts_with($reason, 'knowledge_condition_mismatch:')) return '酒店适用条件不符：' . substr($reason, 29);
        return [
            'knowledge_hotel_mismatch' => '不适用于当前酒店', 'knowledge_hotel_missing' => '请指定适用酒店',
            'knowledge_tenant_mismatch' => '租户范围不符', 'knowledge_tenant_missing' => '缺少租户身份',
            'knowledge_platform_mismatch' => '不适用于当前平台', 'knowledge_platform_missing' => '缺少适用平台',
            'knowledge_version_superseded' => '已有新版本，旧版本仅供历史回读',
            'knowledge_expired' => '知识已过期，不能用于当前问题', 'knowledge_not_yet_effective' => '尚未生效',
            'knowledge_review_due' => '已到复核期限，不能用于当前动作',
            'knowledge_review_date_missing' => '未记录复核日期，不能用于当前动作',
            'knowledge_reference_only' => '仅作参考，不构成事实或授权', 'knowledge_source_unverifiable' => '来源尚不可核验',
            'knowledge_traceability_missing' => '缺少来源或证据范围', 'knowledge_conflict_unresolved' => '规则冲突尚未解决',
            'knowledge_conflict_other_version_selected' => '冲突已裁定采用另一版本',
            'knowledge_unknown_explicit' => '存在明确未知项', 'knowledge_current_verification_required' => '需要当前来源核验',
            'knowledge_evidence_unverified' => '证据未核验', 'knowledge_evidence_unrated' => '来源等级未记录',
            'knowledge_date_invalid' => '有效期日期不合法', 'knowledge_as_of_invalid' => '查询日期不合法',
            'knowledge_validity_range_invalid' => '有效期起止顺序不合法',
            'knowledge_content_digest_mismatch' => '内容摘要不一致，需要重新核验',
            'knowledge_chunk_not_active' => '片段当前停用', 'knowledge_unit_not_active' => '经验单元当前停用',
        ][$reason] ?? '知识适用性待核验';
    }

    public function assessRows(array $unit, array $chunks, array $context): array
    {
        $superseded = KnowledgeRevisionService::supersededIds($chunks);
        $assessments = []; $eligible = [];
        foreach ($chunks as $chunk) {
            $id = (int)$chunk['chunk_id']; $content = KnowledgeRevisionService::content($chunk);
            $assessments[$id] = $this->assess($unit, $content, $context, $chunk + ['_revision_superseded' => isset($superseded[$id])]);
            if ($assessments[$id]['retrieval_safe']) $eligible[] = ['chunk_id' => $id, 'content' => $content, 'applicability' => $assessments[$id]];
        }
        $resolution = (new KnowledgeDecisionGateService())->resolveConflictingClaims($eligible);
        foreach ($resolution['conflicts'] as $conflict) {
            foreach ($conflict['withheld_chunk_ids'] as $id) {
                foreach (['retrieval_safe', 'decision_safe', 'task_draft_safe'] as $field) $assessments[$id][$field] = false;
                $reason = $conflict['status'] === 'resolved' ? 'knowledge_conflict_other_version_selected' : 'knowledge_conflict_unresolved';
                $assessments[$id]['status'] = 'blocked'; $assessments[$id]['status_label'] = '冲突版本不可用';
                $assessments[$id]['reason_codes'][] = $reason;
                $assessments[$id]['reason_labels'][] = $this->reasonLabel($reason);
            }
        }
        return ['assessments' => $assessments, 'conflicts' => $resolution['conflicts']];
    }

    public function platforms(mixed $values): array
    {
        if (is_string($values)) {
            $values = preg_split('/[\s,，、;；|\/]+/u', trim($values)) ?: [];
        }
        if (!is_array($values)) {
            return ['invalid_platform_metadata'];
        }
        return array_values(array_unique(array_map(fn($value) => is_scalar($value) ? $this->platform((string)$value) : 'invalid_platform_metadata', $values)));
    }
}
