<?php
declare(strict_types=1);

namespace app\service;

/**
 * Builds a deterministic self-review receipt for one persisted hotel analysis.
 *
 * The receipt never upgrades source facts, invents a score, or authorizes an
 * external action. It only states whether the already saved answer is usable,
 * partially usable, or blocked under the current evidence contract.
 */
final class HotelDataAnalystQualityReceiptService
{
    public const CONTRACT_VERSION = 'hotel_data_analyst_quality_receipt.v1';
    public const ROLE_KEY = 'hotel_data_analyst';

    /** @var list<string> */
    private const PLATFORMS = ['ctrip', 'meituan', 'qunar', 'all_ota'];

    /** @var list<string> */
    private const STRICT_VERIFICATION_STATUSES = ['verified', 'derived_verified'];

    /** @return array<string,mixed> */
    public function evaluate(array $record): array
    {
        $answer = is_array($record['answer'] ?? null) ? $record['answer'] : [];
        $answerStatus = strtolower(trim((string)($record['answer_status'] ?? $answer['status'] ?? '')));
        $scopeCheck = $this->scopeCheck($record, $answer);
        $persistenceCheck = $this->persistenceCheck($record);
        $metricAssessment = $this->metricAssessment($answer);
        $evidenceAssessment = $this->evidenceAssessment($record, $answer, $answerStatus, $metricAssessment);
        $gapCheck = $this->gapCheck($record, $answer, $answerStatus, $metricAssessment, $evidenceAssessment);
        $clarityCheck = $this->clarityCheck($record, $answer, $answerStatus);
        $runtimeCheck = $this->runtimeCheck($answer);
        $authorityCheck = $this->authorityCheck($answer);
        $coherenceCheck = $this->statusCoherenceCheck(
            $answerStatus,
            $metricAssessment,
            $evidenceAssessment,
            $gapCheck,
            $runtimeCheck
        );

        $checks = [
            $scopeCheck,
            $persistenceCheck,
            $evidenceAssessment['check'],
            $metricAssessment['check'],
            $gapCheck,
            $clarityCheck,
            $runtimeCheck,
            $authorityCheck,
            $coherenceCheck,
        ];
        $blocked = array_values(array_filter(
            $checks,
            static fn(array $check): bool => (string)($check['status'] ?? '') === 'blocked'
        ));
        $partial = array_values(array_filter(
            $checks,
            static fn(array $check): bool => (string)($check['status'] ?? '') === 'partial'
        ));
        $contractCheckKeys = [
            'scope_identity',
            'persistence_integrity',
            'gap_transparency',
            'answer_clarity',
            'runtime_provenance',
            'authority_boundary',
            'status_coherence',
        ];
        $qualityFailed = array_filter($checks, static fn(array $check): bool => (
            in_array((string)($check['key'] ?? ''), $contractCheckKeys, true)
            && (string)($check['status'] ?? '') === 'blocked'
        )) !== [];
        $qualityStatus = $qualityFailed ? 'failed' : 'passed';
        $claimStatus = 'supported';
        if ($qualityFailed
            || str_starts_with($answerStatus, 'blocked')
            || (string)($evidenceAssessment['check']['status'] ?? '') === 'blocked'
            || (($metricAssessment['has_precise_result'] ?? false) === true
                && (string)($metricAssessment['check']['status'] ?? '') === 'blocked')
        ) {
            $claimStatus = 'blocked';
        } elseif ($partial !== []
            || $answerStatus === 'evidence_ready'
            || str_contains($answerStatus, 'partial')
        ) {
            $claimStatus = 'limited';
        }
        $status = match ($claimStatus) {
            'supported' => 'ready',
            'limited' => 'partial',
            default => 'blocked',
        };
        $verifiedPortionUsable = ($evidenceAssessment['verified_portion'] ?? false) === true
            && (string)$scopeCheck['status'] !== 'blocked'
            && (string)$persistenceCheck['status'] !== 'blocked'
            && (string)$authorityCheck['status'] !== 'blocked';
        $improvementTargets = array_values(array_map(
            static fn(array $check): string => (string)$check['key'],
            array_filter($checks, static fn(array $check): bool => (string)$check['status'] !== 'passed')
        ));
        $nextActions = $this->nextActions($improvementTargets);
        $passedCount = count($checks) - count($blocked) - count($partial);
        $reasonCodes = array_values(array_unique(array_filter(array_map(
            static fn(array $check): string => trim((string)($check['reason_code'] ?? '')),
            $checks
        ))));
        $scopeDigest = $this->digest([
            'tenant_id' => (int)($record['tenant_id'] ?? 0),
            'hotel_id' => (int)($record['hotel_id'] ?? 0),
            'platform' => (string)($record['platform'] ?? ''),
            'date_start' => (string)($record['date_start'] ?? ''),
            'date_end' => (string)($record['date_end'] ?? ''),
            'answer_scope' => is_array($answer['scope'] ?? null) ? $answer['scope'] : [],
        ]);
        $evidenceDigest = $this->digest([
            'fact_refs' => $this->stringList($record['fact_refs'] ?? []),
            'memory_refs' => $this->stringList($record['memory_refs'] ?? []),
            'knowledge_refs' => $this->stringList($record['knowledge_refs'] ?? []),
            'execution_refs' => $this->stringList($record['execution_refs'] ?? []),
        ]);
        $subjectDigest = strtolower(trim((string)($record['content_digest'] ?? '')));

        $receipt = [
            'contract_version' => self::CONTRACT_VERSION,
            'role_key' => self::ROLE_KEY,
            'quality_status' => $qualityStatus,
            'claim_status' => $claimStatus,
            'readback_verified' => (string)($record['persistence_status'] ?? '') === 'readback_verified',
            'external_action_authorized' => false,
            'subject_digest' => $subjectDigest,
            'source_content_digest' => $subjectDigest,
            'scope_digest' => $scopeDigest,
            'evidence_digest' => $evidenceDigest,
            'status' => $status,
            'status_label' => match ($status) {
                'ready' => '质量回执通过',
                'partial' => '部分结果可用',
                default => '分析已阻断',
            },
            'summary' => match ($status) {
                'ready' => '范围、证据、指标、缺口披露、保存回读和权限边界均通过。',
                'partial' => '已有严格可用部分，但仍有明确缺口；只使用已验证部分。',
                default => '存在阻断项，当前结果不能作为确定经营结论。',
            },
            'check_count' => count($checks),
            'passed_count' => $passedCount,
            'partial_count' => count($partial),
            'blocked_count' => count($blocked),
            'checks' => $checks,
            'reason_codes' => $reasonCodes,
            'improvement_targets' => $improvementTargets,
            'next_actions' => $nextActions,
            'usage_policy' => [
                'verified_portion_usable' => $verifiedPortionUsable,
                'analysis_claim_allowed' => $status === 'ready',
                'whole_hotel_conclusion_allowed' => false,
                'human_confirmation_required' => true,
                'external_action_authorized' => false,
                'ota_write' => false,
                'pms_write' => false,
                'external_message' => false,
                'automatic_execution' => false,
            ],
        ];
        $receipt['receipt_digest'] = $this->digest($receipt);
        return $receipt;
    }

    /** @return array<string,mixed> */
    private function runtimeCheck(array $answer): array
    {
        $mode = strtolower(trim((string)($answer['mode'] ?? '')));
        $runtime = is_array($answer['ai_runtime'] ?? null) ? $answer['ai_runtime'] : [];
        $boundaries = is_array($answer['boundaries'] ?? null) ? $answer['boundaries'] : [];
        if ($mode === 'deterministic_precise_query') {
            $precise = is_array($answer['precise_result'] ?? null) ? $answer['precise_result'] : [];
            $valid = (string)($runtime['status'] ?? '') === 'not_called_deterministic'
                && ($runtime['model_attempted'] ?? false) !== true
                && ($runtime['llm_client_invoked'] ?? false) !== true
                && ($boundaries['llm_attempted'] ?? false) !== true
                && ($precise['decision_safe'] ?? false) !== true
                && ($precise['external_write_authorized'] ?? false) !== true;
            return $this->check(
                'runtime_provenance',
                '运行来源',
                $valid ? 'passed' : 'blocked',
                $valid ? '确定性查数未调用模型，也未获得外部写入权限。' : '确定性查数出现模型调用或权限状态矛盾。',
                $valid ? '' : 'deterministic_query_runtime_incoherent'
            );
        }
        if ($mode === 'grounded_ai_saved_evidence') {
            $provider = strtolower(trim((string)($runtime['provider'] ?? '')));
            $model = strtolower(trim((string)($runtime['model'] ?? '')));
            $callStatus = strtolower(trim((string)($runtime['external_llm_call_status'] ?? '')));
            $deepSeek = $provider === 'deepseek'
                && $model === 'deepseek-v4-pro'
                && $callStatus === 'confirmed_success'
                && ($runtime['external_llm_called'] ?? false) === true;
            $local = $provider === 'ollama'
                && $model === 'qwen3:8b'
                && $callStatus === 'confirmed_local_success'
                && ($runtime['external_llm_called'] ?? true) === false;
            $valid = (string)($runtime['status'] ?? '') === 'ready'
                && strtolower(trim((string)($runtime['finish_reason'] ?? ''))) === 'stop'
                && ($runtime['fallback_used'] ?? false) !== true
                && ($runtime['cache_hit'] ?? false) !== true
                && ($runtime['degraded'] ?? false) !== true
                && ($deepSeek || $local);
            return $this->check(
                'runtime_provenance',
                '运行来源',
                $valid ? 'passed' : 'blocked',
                $valid ? '模型来源、完成状态和降级边界可核对。' : '模型来源、完成状态或降级边界不一致。',
                $valid ? '' : 'grounded_ai_runtime_incoherent'
            );
        }
        if ($runtime === []) {
            return $this->check(
                'runtime_provenance',
                '运行来源',
                'partial',
                '历史记录未保存完整运行来源，不能升级为模型结论。',
                'analysis_runtime_legacy_fields_missing'
            );
        }
        return $this->check(
            'runtime_provenance',
            '运行来源',
            'passed',
            '当前为规则/证据摘要，未把模型调用冒充为确定性事实。'
        );
    }

    /** @return array<string,mixed> */
    private function statusCoherenceCheck(
        string $answerStatus,
        array $metricAssessment,
        array $evidenceAssessment,
        array $gapCheck,
        array $runtimeCheck
    ): array {
        $metricStatus = (string)($metricAssessment['check']['status'] ?? 'passed');
        $evidenceStatus = (string)($evidenceAssessment['check']['status'] ?? 'blocked');
        $gapStatus = (string)($gapCheck['status'] ?? 'blocked');
        $runtimeStatus = (string)($runtimeCheck['status'] ?? 'partial');
        if (str_starts_with($answerStatus, 'blocked')) {
            $coherent = (($metricAssessment['has_precise_result'] ?? false) === true
                    ? ($metricStatus === 'blocked' && (int)($metricAssessment['verified_count'] ?? 0) === 0)
                    : $evidenceStatus === 'blocked')
                && $gapStatus !== 'blocked';
        } elseif ($answerStatus === 'evidence_ready') {
            $coherent = ($evidenceAssessment['verified_portion'] ?? false) === true
                && $evidenceStatus === 'partial'
                && $gapStatus !== 'blocked';
        } elseif (str_contains($answerStatus, 'partial')) {
            $coherent = ($evidenceAssessment['verified_portion'] ?? false) === true
                && (($metricAssessment['has_precise_result'] ?? false) === true
                    ? $metricStatus === 'partial'
                    : $evidenceStatus === 'partial')
                && $gapStatus !== 'blocked';
        } elseif ($answerStatus !== '') {
            $coherent = $evidenceStatus === 'passed'
                && $metricStatus !== 'blocked'
                && $runtimeStatus !== 'blocked';
        } else {
            $coherent = false;
        }
        return $this->check(
            'status_coherence',
            '状态一致性',
            $coherent ? 'passed' : 'blocked',
            $coherent
                ? '回答状态与证据、指标、缺口和运行来源一致。'
                : '回答状态与证据、指标、缺口或运行来源相互矛盾。',
            $coherent ? '' : 'analysis_status_contract_incoherent'
        );
    }

    /** @return array<string,mixed> */
    private function scopeCheck(array $record, array $answer): array
    {
        $scope = is_array($answer['scope'] ?? null) ? $answer['scope'] : [];
        $platform = strtolower(trim((string)($record['platform'] ?? '')));
        $dateStart = trim((string)($record['date_start'] ?? ''));
        $dateEnd = trim((string)($record['date_end'] ?? ''));
        $valid = (int)($record['tenant_id'] ?? 0) > 0
            && (int)($record['hotel_id'] ?? 0) > 0
            && in_array($platform, self::PLATFORMS, true)
            && $this->validDate($dateStart)
            && $this->validDate($dateEnd)
            && $dateEnd >= $dateStart
            && (int)($scope['tenant_id'] ?? 0) === (int)$record['tenant_id']
            && (int)($scope['hotel_id'] ?? 0) === (int)$record['hotel_id']
            && strtolower(trim((string)($scope['platform'] ?? ''))) === $platform
            && (string)($scope['date_start'] ?? '') === $dateStart
            && (string)($scope['date_end'] ?? '') === $dateEnd
            && (string)($scope['source_scope'] ?? '') === 'ota_channel';
        return $this->check(
            'scope_identity',
            '范围身份',
            $valid ? 'passed' : 'blocked',
            $valid
                ? '租户、酒店、平台、日期与 OTA 渠道范围一致。'
                : '租户、酒店、平台、日期或渠道范围缺失/不一致。',
            $valid ? '' : 'analysis_scope_identity_invalid'
        );
    }

    /** @return array<string,mixed> */
    private function persistenceCheck(array $record): array
    {
        $digest = strtolower(trim((string)($record['content_digest'] ?? '')));
        $valid = (int)($record['id'] ?? 0) > 0
            && (string)($record['persistence_status'] ?? '') === 'readback_verified'
            && preg_match('/^[a-f0-9]{64}$/D', $digest) === 1;
        return $this->check(
            'persistence_integrity',
            '保存与回读',
            $valid ? 'passed' : 'blocked',
            $valid ? '分析记录已保存并按编号完成摘要校验。' : '缺少编号、摘要或精确回读凭证。',
            $valid ? '' : 'analysis_persistence_not_verified'
        );
    }

    /** @return array{check:array<string,mixed>,verified_count:int,blocked_count:int,has_precise_result:bool} */
    private function metricAssessment(array $answer): array
    {
        $precise = is_array($answer['precise_result'] ?? null) ? $answer['precise_result'] : [];
        if ($precise === []) {
            return [
                'check' => $this->check('metric_integrity', '指标资格', 'passed', '本次不是确定性指标查询。'),
                'verified_count' => 0,
                'blocked_count' => 0,
                'has_precise_result' => false,
            ];
        }
        $metricSet = is_array($precise['metric_set'] ?? null) ? $precise['metric_set'] : [];
        $items = is_array($metricSet['items'] ?? null) ? $metricSet['items'] : [];
        if ($items === []) {
            $items = is_array($precise['items'] ?? null) ? $precise['items'] : [];
        }
        if ($items === []) {
            $items = is_array($precise['precise_results'] ?? null) ? $precise['precise_results'] : [];
        }
        if ($items === [] && is_array($precise['metric_readback']['values'] ?? null)) {
            $items = $precise['metric_readback']['values'];
        }
        if ($items === []
            && (string)($precise['kind'] ?? '') === 'operating_metric_range'
            && is_array($precise['points'] ?? null)
        ) {
            $items = $precise['points'];
        }
        if ($items === [] && array_key_exists('value', $precise)) {
            $items = [$precise];
        }

        $verified = 0;
        $blocked = 0;
        $scope = is_array($answer['scope'] ?? null) ? $answer['scope'] : [];
        $scopeStart = trim((string)($scope['date_start'] ?? ''));
        $scopeEnd = trim((string)($scope['date_end'] ?? ''));
        foreach ($items as $item) {
            if (!is_array($item)) {
                $blocked++;
                continue;
            }
            $raw = is_array($item['result'] ?? null) ? array_merge($item, $item['result']) : $item;
            $value = $raw['value'] ?? null;
            $hasValue = $value !== null && $value !== '' && (is_int($value) || is_float($value) || is_numeric($value));
            $status = strtolower(trim((string)($raw['status'] ?? $raw['result_status'] ?? '')));
            $explicitlyBlocked = preg_match('/^(blocked|missing|unavailable|failed|error|not_)/', $status) === 1
                || trim((string)($raw['blocked_reason'] ?? '')) !== '';
            $verification = strtolower(trim((string)($raw['verification_status'] ?? '')));
            $readback = strtolower(trim((string)($raw['readback_status'] ?? '')));
            $itemDate = trim((string)($raw['business_date'] ?? $raw['date'] ?? ''));
            $unit = trim((string)($raw['unit'] ?? ''));
            $refs = array_values(array_unique(array_filter(array_merge(
                [trim((string)($raw['source_record'] ?? ''))],
                $this->stringList($raw['source_records'] ?? [])
            ))));
            $strict = $hasValue
                && !$explicitlyBlocked
                && in_array($verification, self::STRICT_VERIFICATION_STATUSES, true)
                && $readback === 'readback_verified'
                && $refs !== []
                && $unit !== ''
                && $this->validDate($itemDate)
                && $this->validDate($scopeStart)
                && $this->validDate($scopeEnd)
                && $itemDate >= $scopeStart
                && $itemDate <= $scopeEnd;
            if ($strict) {
                $verified++;
            } else {
                $blocked++;
            }
        }

        $declared = $metricSet !== [] ? $metricSet : (is_array($precise['items'] ?? null) ? $precise : []);
        $hasDeclaredCounts = array_key_exists('result_count', $declared)
            || array_key_exists('ready_count', $declared)
            || array_key_exists('blocked_count', $declared);
        $declaredCountsValid = !$hasDeclaredCounts || (
            (int)($declared['result_count'] ?? -1) === count($items)
            && (int)($declared['ready_count'] ?? -1) === $verified
            && (int)($declared['blocked_count'] ?? -1) === $blocked
        );
        if (!$declaredCountsValid) {
            $check = $this->check(
                'metric_integrity',
                '指标资格',
                'blocked',
                '指标集合声明的总数、可用数或阻断数与逐项重算不一致。',
                'precise_metric_declared_count_mismatch'
            );
        } elseif ($items === [] || $verified === 0) {
            $check = $this->check(
                'metric_integrity',
                '指标资格',
                'blocked',
                '指标缺少 verified/derived_verified、readback_verified 或来源记录。',
                'precise_metric_not_strictly_verified'
            );
        } elseif ($blocked > 0) {
            $check = $this->check(
                'metric_integrity',
                '指标资格',
                'partial',
                sprintf('%d 项指标严格可用，%d 项保持阻断。', $verified, $blocked),
                'precise_metric_partial'
            );
        } else {
            $check = $this->check(
                'metric_integrity',
                '指标资格',
                'passed',
                sprintf('%d 项指标均具有验证、回读和来源凭证。', $verified)
            );
        }
        return [
            'check' => $check,
            'verified_count' => $verified,
            'blocked_count' => $blocked,
            'has_precise_result' => true,
        ];
    }

    /** @return array{check:array<string,mixed>,verified_portion:bool} */
    private function evidenceAssessment(
        array $record,
        array $answer,
        string $answerStatus,
        array $metricAssessment
    ): array {
        $factRefs = array_values(array_filter(
            $this->stringList($record['fact_refs'] ?? []),
            static fn(string $ref): bool => preg_match('/^online_daily_data#[1-9][0-9]*$/D', $ref) === 1
        ));
        $factCount = max(0, (int)($answer['evidence_counts']['facts'] ?? 0));
        $verifiedMetrics = (int)($metricAssessment['verified_count'] ?? 0);
        $verifiedPortion = $factRefs !== [] && ($factCount > 0 || $verifiedMetrics > 0);
        $platform = strtolower(trim((string)($record['platform'] ?? '')));
        $platformCounts = is_array($answer['evidence_counts']['fact_platforms'] ?? null)
            ? $answer['evidence_counts']['fact_platforms']
            : [];
        $allOtaCovered = $platform !== 'all_ota'
            || ((int)($platformCounts['ctrip'] ?? 0) > 0 && (int)($platformCounts['meituan'] ?? 0) > 0);
        $mode = strtolower(trim((string)($answer['mode'] ?? '')));
        $usedRefs = $this->stringList($answer['used_evidence_refs'] ?? []);
        $groundedRefsValid = $mode !== 'grounded_ai_saved_evidence'
            || array_intersect($factRefs, $usedRefs) !== [];

        if (str_starts_with($answerStatus, 'blocked') || !$verifiedPortion || !$allOtaCovered || !$groundedRefsValid) {
            $check = $this->check(
                'evidence_quality',
                '证据资格',
                'blocked',
                !$allOtaCovered
                    ? '全 OTA 范围缺少携程或美团同范围事实。'
                    : (!$groundedRefsValid
                        ? '模型结论没有引用当前保存事实。'
                        : '缺少同范围严格事实，不能形成经营结论。'),
                !$allOtaCovered
                    ? 'all_ota_fact_coverage_missing'
                    : (!$groundedRefsValid ? 'grounded_answer_fact_ref_missing' : 'verified_fact_missing')
            );
        } elseif ($answerStatus === 'evidence_ready'
            || str_contains($answerStatus, 'partial')
            || (string)($metricAssessment['check']['status'] ?? '') === 'partial'
        ) {
            $check = $this->check(
                'evidence_quality',
                '证据资格',
                'partial',
                '已有严格事实，但当前只支持证据摘要或部分指标。',
                'verified_evidence_partial'
            );
        } else {
            $check = $this->check(
                'evidence_quality',
                '证据资格',
                'passed',
                sprintf('已绑定 %d 条严格事实引用。', count($factRefs))
            );
        }
        return ['check' => $check, 'verified_portion' => $verifiedPortion];
    }

    /** @return array<string,mixed> */
    private function gapCheck(
        array $record,
        array $answer,
        string $answerStatus,
        array $metricAssessment,
        array $evidenceAssessment
    ): array {
        $gapCount = count($this->arrayList($record['data_gaps'] ?? []))
            + count($this->arrayList($answer['missing_information'] ?? []));
        foreach ($this->preciseItems($answer) as $item) {
            if (is_array($item)) {
                $gapCount += count($this->arrayList($item['data_gaps'] ?? $item['gaps'] ?? []));
            }
        }
        $nonReady = str_starts_with($answerStatus, 'blocked')
            || $answerStatus === 'evidence_ready'
            || str_contains($answerStatus, 'partial')
            || (string)($metricAssessment['check']['status'] ?? '') !== 'passed'
            || (string)($evidenceAssessment['check']['status'] ?? '') !== 'passed';
        if ($nonReady && $gapCount === 0) {
            return $this->check(
                'gap_transparency',
                '缺口披露',
                'blocked',
                '结果未就绪，但没有给出可核对的数据缺口。',
                'analysis_gap_not_disclosed'
            );
        }
        if (!$nonReady && $gapCount > 0) {
            return $this->check(
                'gap_transparency',
                '缺口披露',
                'partial',
                sprintf('结论可读，但仍披露 %d 项未知或缺口。', $gapCount),
                'analysis_has_disclosed_unknowns'
            );
        }
        return $this->check(
            'gap_transparency',
            '缺口披露',
            'passed',
            $gapCount > 0 ? sprintf('%d 项缺口已明确披露。', $gapCount) : '当前结果没有未披露缺口。'
        );
    }

    /** @return array<string,mixed> */
    private function clarityCheck(array $record, array $answer, string $answerStatus): array
    {
        $summary = trim((string)($record['answer_summary'] ?? $answer['summary'] ?? ''));
        $mode = strtolower(trim((string)($answer['mode'] ?? '')));
        $confidence = strtolower(trim((string)($answer['confidence'] ?? '')));
        $valid = $summary !== '' && $answerStatus !== '';
        if ($mode === 'grounded_ai_saved_evidence') {
            $valid = $valid && in_array($confidence, ['low', 'medium', 'high'], true);
        }
        return $this->check(
            'answer_clarity',
            '结果表达',
            $valid ? 'passed' : 'blocked',
            $valid ? '摘要、状态和必要置信边界完整。' : '摘要、状态或模型置信边界缺失。',
            $valid ? '' : 'analysis_answer_contract_incomplete'
        );
    }

    /** @return array<string,mixed> */
    private function authorityCheck(array $answer): array
    {
        $boundaries = is_array($answer['boundaries'] ?? null) ? $answer['boundaries'] : [];
        $required = ['ota_write', 'external_message', 'automatic_execution'];
        foreach (['ota_write', 'pms_write', 'external_message', 'automatic_execution'] as $key) {
            if (($boundaries[$key] ?? false) === true) {
                return $this->check(
                    'authority_boundary',
                    '权限边界',
                    'blocked',
                    '分析结果包含未经授权的外部写入或自动执行能力。',
                    'analysis_external_action_boundary_violation'
                );
            }
        }
        $actionDrafts = $this->arrayList($answer['action_drafts'] ?? []);
        foreach ($actionDrafts as $draft) {
            if (!is_array($draft)
                || ($draft['decision_quality']['human_confirmation_required'] ?? false) !== true
            ) {
                return $this->check(
                    'authority_boundary',
                    '权限边界',
                    'blocked',
                    '行动草案缺少人工确认约束。',
                    'analysis_action_human_confirmation_missing'
                );
            }
        }
        $missing = array_values(array_filter(
            $required,
            static fn(string $key): bool => !array_key_exists($key, $boundaries)
        ));
        return $this->check(
            'authority_boundary',
            '权限边界',
            $missing === [] ? 'passed' : 'partial',
            $missing === []
                ? '不写 OTA/PMS、不外发、不自动执行；行动仍需人工确认。'
                : '旧记录缺少部分权限字段，按无外部权限解释并等待重新生成。',
            $missing === [] ? '' : 'analysis_boundary_legacy_fields_missing'
        );
    }

    /** @return list<array<string,mixed>> */
    private function preciseItems(array $answer): array
    {
        $precise = is_array($answer['precise_result'] ?? null) ? $answer['precise_result'] : [];
        $set = is_array($precise['metric_set'] ?? null) ? $precise['metric_set'] : [];
        foreach ([$set['items'] ?? null, $precise['items'] ?? null, $precise['precise_results'] ?? null, $precise['metric_readback']['values'] ?? null, $precise['points'] ?? null] as $items) {
            if (is_array($items) && array_is_list($items) && $items !== []) {
                return array_values(array_filter($items, 'is_array'));
            }
        }
        return array_key_exists('value', $precise) ? [$precise] : [];
    }

    /** @param list<string> $targets @return list<string> */
    private function nextActions(array $targets): array
    {
        $messages = [
            'scope_identity' => '重新锁定酒店、平台和业务日期后再分析。',
            'persistence_integrity' => '重新保存并按记录编号完成精确回读。',
            'evidence_quality' => '补齐同酒店、同平台、同日期的严格回读事实。',
            'metric_integrity' => '补齐指标的验证状态、回读状态、单位和来源记录。',
            'gap_transparency' => '在结果中明确列出缺失字段、不可计算原因和补证入口。',
            'answer_clarity' => '重新生成包含摘要、状态和置信边界的结果。',
            'authority_boundary' => '移除外部写入能力并恢复人工确认边界。',
        ];
        $result = [];
        foreach ($targets as $target) {
            if (isset($messages[$target])) {
                $result[] = $messages[$target];
            }
        }
        return array_slice(array_values(array_unique($result)), 0, 4);
    }

    /** @return array<string,mixed> */
    private function check(
        string $key,
        string $label,
        string $status,
        string $message,
        string $reasonCode = ''
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'message' => $message,
            'reason_code' => $reasonCode,
        ];
    }

    /** @return list<mixed> */
    private function arrayList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (!is_array($value)) {
            return [$value];
        }
        return array_is_list($value) ? $value : [$value];
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        $items = [];
        foreach ($this->arrayList($value) as $item) {
            if (is_scalar($item)) {
                $text = trim((string)$item);
                if ($text !== '') {
                    $items[] = $text;
                }
            }
        }
        return array_values(array_unique($items));
    }

    private function validDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function digest(mixed $value): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([$this, 'canonicalize'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
