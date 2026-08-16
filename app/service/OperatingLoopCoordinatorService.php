<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

/**
 * Read-only adapters that project existing formal domain records into the
 * authoritative kernel. The coordinator never creates or repairs a PMS/OTA,
 * decision, execution, outcome or knowledge record.
 */
final class OperatingLoopCoordinatorService
{
    public const METRIC_BUNDLE_VERSION = 'hotel_operating_metric_bundle.v1';

    public function __construct(
        private ?OperatingLoopKernelService $kernel = null,
        private ?HotelCollectionPlanService $collectionPlan = null,
        ?callable $revenueFactBuilder = null
    )
    {
        $this->kernel ??= new OperatingLoopKernelService();
        $this->collectionPlan ??= new HotelCollectionPlanService();
        $this->revenueFactBuilder = $revenueFactBuilder !== null
            ? Closure::fromCallable($revenueFactBuilder)
            : static fn(int $hotelId, string $businessDate): array =>
                (new RevenueFactLayerService())->build($hotelId, $businessDate);
    }

    /** @var Closure(int,string):array<string,mixed> */
    private Closure $revenueFactBuilder;

    /** @return array<string,mixed> */
    public function reconcile(
        int $tenantId,
        int $hotelId,
        string $businessDate,
        int $actorId,
        int $maxTransitions = 8
    ): array {
        if ($tenantId <= 0 || $hotelId <= 0 || $actorId <= 0) {
            throw new InvalidArgumentException('经营闭环同步缺少有效租户、酒店或操作者');
        }
        $businessDate = $this->date($businessDate);
        $maxTransitions = max(1, min(8, $maxTransitions));
        $reconciled = [];

        $current = $this->kernel->currentForHotelDate($tenantId, $hotelId, $businessDate);
        if ((int)($current['record_id'] ?? 0) <= 0) {
            $identityBundle = $this->sourceIdentities($tenantId, $hotelId, $businessDate);
            if (($identityBundle['ready'] ?? false) !== true) {
                return $this->waiting(
                    $current,
                    $reconciled,
                    'identity_business_date_confirmed',
                    (string)($identityBundle['code'] ?? 'source_identity_missing'),
                    (string)($identityBundle['detail'] ?? '没有可精确回读的平台门店或 PMS 门店身份。'),
                    is_array($identityBundle['owner'] ?? null) ? $identityBundle['owner'] : []
                );
            }
            $identities = (array)$identityBundle['identities'];
            $metric = $this->metricContract($tenantId, $hotelId, $businessDate);
            $opened = $this->kernel->open($tenantId, $hotelId, [
                'business_date' => $businessDate,
                'metric_version' => $metric['version'],
                'metric_definition' => $metric['definition'],
                'source_identities' => $identities,
                'source_module' => 'operating_loop_coordinator',
                'command_key' => 'coordinator-open-' . $tenantId . '-' . $hotelId . '-' . $businessDate,
                'truth_summary' => $this->identitySummary($hotelId, $businessDate, $identities, $metric['version']),
            ], $actorId);
            $cycle = (array)($opened['cycle'] ?? []);
            $reconciled[] = 'identity_business_date_confirmed';
        } else {
            $cycle = $this->kernel->readVerified(
                (int)$current['record_id'],
                $tenantId,
                [$hotelId]
            );
        }

        for ($index = 0; $index < $maxTransitions; $index++) {
            if ((string)($cycle['cycle_status'] ?? '') === 'completed') {
                break;
            }
            $stage = (string)($cycle['next_required_stage'] ?? '');
            $adapter = $this->transitionForStage($cycle, $stage, $actorId);
            if (($adapter['ready'] ?? false) !== true) {
                return $this->waiting(
                    (array)($cycle['summary'] ?? []),
                    $reconciled,
                    $stage,
                    (string)($adapter['code'] ?? 'evidence_not_ready'),
                    (string)($adapter['detail'] ?? '当前阶段的正式证据尚未齐备。'),
                    is_array($adapter['owner'] ?? null) ? $adapter['owner'] : []
                );
            }

            $input = (array)$adapter['input'];
            $transition = $this->kernel->transition(
                (int)$cycle['id'],
                (int)$cycle['tenant_id'],
                [(int)$cycle['hotel_id']],
                $input,
                $actorId
            );
            $cycle = (array)($transition['cycle'] ?? []);
            $reconciled[] = $stage;
            if ((string)($input['stage_status'] ?? 'completed') === 'blocked') {
                break;
            }
        }

        return [
            'operating_loop' => (array)($cycle['summary'] ?? []),
            'reconciled_stages' => $reconciled,
            'waiting' => null,
            'persistence_status' => 'readback_verified',
            'source_policy' => 'existing_formal_rows_to_operating_cycle_kernel_only',
        ];
    }

    /** @return array<string,mixed> */
    private function transitionForStage(array $cycle, string $stage, int $actorId): array
    {
        return match ($stage) {
            'trusted_collection' => $this->trustedCollectionTransition($cycle),
            'formal_save_exact_readback' => $this->formalSaveTransition($cycle),
            'operating_facts_established' => $this->operatingFactsTransition($cycle),
            'recommendation_human_decision' => $this->decisionTransition($cycle, $actorId),
            'real_execution_receipt' => $this->executionTransition($cycle, $actorId),
            'comparable_outcome_readback' => $this->outcomeTransition($cycle, $actorId),
            'review_experience_promotion' => $this->experienceTransition($cycle, $actorId),
            default => ['ready' => false, 'code' => 'kernel_stage_unknown', 'detail' => '内核下一阶段无法识别。'],
        };
    }

    /** @return array<string,mixed> */
    private function trustedCollectionTransition(array $cycle): array
    {
        $collection = $this->collectionEvidence($cycle, false);
        if (($collection['failure'] ?? null) !== null) {
            $failure = (array)$collection['failure'];
            return ['ready' => true, 'input' => $this->transitionInput(
                $cycle,
                'trusted_collection',
                'blocked',
                'collection-blocked-' . (string)($failure['receipt_id'] ?? 0),
                'system',
                [
                    'block_code' => (string)($failure['code'] ?? 'trusted_collection_failed'),
                    'block_detail' => (string)($failure['detail'] ?? '正式采集回执已报告失败。'),
                    'priority_issue' => '目标业务日仍缺可信采集',
                    'next_action' => '按失败回执修复原来源采集后重新同步权威闭环',
                    'next_owner' => ['role' => 'data_operator'],
                ],
                is_array($failure['ref'] ?? null) ? [$failure['ref']] : [],
                (string)($failure['occurred_at'] ?? date('Y-m-d H:i:s'))
            )];
        }
        if (($collection['ready'] ?? false) !== true) {
            return $collection;
        }

        return ['ready' => true, 'input' => $this->transitionInput(
            $cycle,
            'trusted_collection',
            'completed',
            'collection-trusted-' . $this->idsKey((array)$collection['refs']),
            'system',
            ['truth_summary' => '冻结来源对应的正式采集回执均已达到可信状态。'],
            (array)$collection['refs']
        )];
    }

    /** @return array<string,mixed> */
    private function formalSaveTransition(array $cycle): array
    {
        $collection = $this->collectionEvidence($cycle, true);
        if (($collection['ready'] ?? false) !== true) {
            return $collection;
        }
        $refs = array_merge((array)$collection['refs'], (array)$collection['saved_refs']);
        return ['ready' => true, 'input' => $this->transitionInput(
            $cycle,
            'formal_save_exact_readback',
            'completed',
            'formal-readback-' . $this->idsKey($refs),
            'system',
            ['truth_summary' => '正式采集结果已保存，并按精确数据库行完成回读。'],
            $refs
        )];
    }

    /** @return array<string,mixed> */
    private function operatingFactsTransition(array $cycle): array
    {
        $collection = $this->collectionEvidence($cycle, true);
        if (($collection['ready'] ?? false) !== true) {
            return $collection;
        }
        $factRefs = [];
        $parts = [];
        foreach ((array)$collection['saved_refs'] as $ref) {
            $kind = (string)($ref['source_kind'] ?? '');
            $ref['role'] = $kind === 'pms' ? 'pms_fact_rows' : 'ota_fact_rows';
            $factRefs[] = $ref;
            $parts[] = (string)$ref['platform'] . ' ' . count((array)$ref['row_ids']) . ' 行';
        }
        if ($factRefs === []) {
            return ['ready' => false, 'code' => 'formal_fact_rows_missing', 'detail' => '没有可用于建立经营事实的精确回读行。'];
        }
        try {
            $layer = ($this->revenueFactBuilder)(
                (int)$cycle['hotel_id'],
                (string)$cycle['business_date']
            );
        } catch (\Throwable) {
            return [
                'ready' => false,
                'code' => 'revenue_fact_layer_read_failed',
                'detail' => '收益事实层未完成同酒店、同业务日的可信分析回读。',
                'owner' => ['role' => 'data_operator'],
            ];
        }
        $diagnostics = is_array($layer['analysis_diagnostics'] ?? null)
            ? $layer['analysis_diagnostics']
            : [];
        $layerHotel = is_array($layer['hotel'] ?? null) ? $layer['hotel'] : [];
        $layerStatus = strtolower(trim((string)($layer['revenue_analysis_status'] ?? $layer['status'] ?? '')));
        if ((string)($layer['contract_version'] ?? '') !== RevenueFactLayerService::CONTRACT_VERSION
            || (int)($layerHotel['tenant_id'] ?? 0) !== (int)$cycle['tenant_id']
            || (int)($layerHotel['system_hotel_id'] ?? 0) !== (int)$cycle['hotel_id']
            || (string)($layer['business_date'] ?? '') !== (string)$cycle['business_date']
            || $layerStatus !== 'ready'
            || ($layer['all_three_sources_readback_verified'] ?? false) !== true
            || $diagnostics === []
        ) {
            return [
                'ready' => false,
                'code' => $layerStatus === 'partial'
                    ? 'revenue_fact_layer_partial'
                    : 'revenue_fact_layer_not_ready',
                'detail' => '收益事实层尚未通过三来源、范围和业务日的完整可信门。',
                'owner' => ['role' => 'data_operator'],
            ];
        }
        $truth = trim((string)($diagnostics['summary'] ?? ''));
        if ($truth === '') {
            $truth = '目标业务日已精确回读分域事实：' . implode('；', $parts) . '。';
        }
        $issues = array_values(array_filter((array)($diagnostics['issues'] ?? []), 'is_array'));
        $priorityIssue = trim((string)($issues[0]['message'] ?? $issues[0]['title'] ?? ''));
        if ($priorityIssue === '') {
            $priorityIssue = '已回读事实未直接证明异常原因，最重要问题需由人工判断。';
        }

        return ['ready' => true, 'input' => $this->transitionInput(
            $cycle,
            'operating_facts_established',
            'completed',
            'facts-established-' . $this->idsKey($factRefs),
            'system',
            [
                'metric_definition_digest' => (string)$cycle['metric_definition_digest'],
                'truth_summary' => $truth,
                'priority_issue' => $priorityIssue,
                'fact_scope' => [
                    'pms_scope' => 'whole_hotel_accommodation',
                    'ota_scope' => 'ota_channel',
                    'pms_plus_ota_revenue_addition_allowed' => false,
                ],
                'revenue_analysis' => [
                    'contract_version' => RevenueFactLayerService::CONTRACT_VERSION,
                    'status' => $layerStatus,
                    'analysis_digest' => $this->digest([
                        'contract_version' => RevenueFactLayerService::CONTRACT_VERSION,
                        'tenant_id' => (int)$cycle['tenant_id'],
                        'hotel_id' => (int)$cycle['hotel_id'],
                        'business_date' => (string)$cycle['business_date'],
                        'status' => $layerStatus,
                        'diagnostics' => $diagnostics,
                    ]),
                ],
            ],
            $factRefs
        )];
    }

    /** @return array<string,mixed> */
    private function decisionTransition(array $cycle, int $actorId): array
    {
        $intentRows = Db::name('operation_execution_intents')
            ->where('tenant_id', (int)$cycle['tenant_id'])
            ->where('hotel_id', (int)$cycle['hotel_id'])
            ->whereNull('deleted_at')
            ->whereIn('status', ['approved', 'rejected'])
            ->order('approved_at', 'desc')
            ->order('id', 'desc')
            ->limit(100)
            ->select()
            ->toArray();
        $intent = null;
        foreach ($intentRows as $candidate) {
            if (is_array($candidate)
                && $this->intentBaselineDate($candidate) === (string)$cycle['business_date']
            ) {
                $intent = $candidate;
                break;
            }
        }
        if (!is_array($intent)) {
            return ['ready' => false, 'code' => 'human_decision_not_recorded', 'detail' => '该业务日尚无已批准或已拒绝的正式执行意图。', 'owner' => ['role' => 'decision_owner']];
        }
        $reviewerId = (int)($intent['approved_by'] ?? 0);
        if ($reviewerId <= 0 || $reviewerId !== $actorId) {
            return ['ready' => false, 'code' => 'decision_actor_mismatch', 'detail' => '只有正式审批记录中的判断人可以把该人工判断写入权威事件链。', 'owner' => ['user_id' => $reviewerId, 'role' => 'approver']];
        }
        $target = $this->decode($intent['target_value_json'] ?? null);
        $status = strtolower(trim((string)$intent['status']));
        $outcomeMetric = $this->intentOutcomeMetric($intent, $target);
        if ($status === 'approved' && $outcomeMetric === null) {
            return ['ready' => false, 'code' => 'decision_metric_contract_missing', 'detail' => '人工判断尚未冻结可用于同口径效果回读的指标定义。', 'owner' => ['user_id' => $reviewerId, 'role' => 'approver']];
        }
        $recommendation = trim((string)($target['action_text'] ?? $target['title'] ?? ''));
        if ($recommendation === '') {
            $recommendation = trim((string)$intent['action_type']) . ' / ' . trim((string)$intent['object_type']);
        }
        $judgement = trim((string)($intent['review_remark'] ?? ''));
        if ($judgement === '') {
            $judgement = $status === 'approved'
                ? '正式审批记录已批准该执行意图。'
                : '正式审批记录已拒绝该执行意图。';
        }
        $reviewDate = trim((string)($target['review_business_date'] ?? ''));
        $reviewAt = trim((string)($target['review_at'] ?? $target['workflow_schedule']['review_at'] ?? ''));
        if ($reviewAt === '' && $this->isDate($reviewDate)) {
            $reviewAt = $reviewDate . ' 23:59:59';
        }
        $approvedAt = trim((string)($intent['approved_at'] ?? ''));
        if (!$this->isDateTime($approvedAt)) {
            return ['ready' => false, 'code' => 'decision_timestamp_missing', 'detail' => '正式人工判断没有可回读的审批时间。', 'owner' => ['user_id' => $reviewerId, 'role' => 'approver']];
        }
        if ($status === 'approved' && (!$this->isDateTime($reviewAt) || $reviewAt <= $approvedAt)) {
            return ['ready' => false, 'code' => 'review_window_not_frozen', 'detail' => '人工判断尚未冻结晚于审批时间的可复盘时间。', 'owner' => ['user_id' => $reviewerId, 'role' => 'approver']];
        }
        $task = Db::name('operation_execution_tasks')
            ->where('tenant_id', (int)$cycle['tenant_id'])
            ->where('hotel_id', (int)$cycle['hotel_id'])
            ->where('intent_id', (int)$intent['id'])
            ->whereNull('deleted_at')
            ->order('id', 'desc')
            ->find();
        $nextOwnerId = is_array($task) ? (int)($task['operator_id'] ?? 0) : (int)($target['assignee_id'] ?? 0);
        $refs = [
            $this->ref('recommendation', 'decision', 'operation_execution_intents', [(int)$intent['id']], (string)$intent['platform']),
            $this->ref('human_decision', 'approval', 'operation_execution_intents', [(int)$intent['id']], (string)$intent['platform']),
        ];
        $payload = [
            'recommendation' => $recommendation,
            'judgement' => $judgement,
            'judged_by' => $reviewerId,
            'decision_status' => $status,
            'approved_by' => $status === 'approved' ? $reviewerId : 0,
            'priority_issue' => trim((string)($intent['expected_metric'] ?? '')) ?: '已形成正式人工判断',
            'next_action' => $status === 'approved' ? '由指定执行人完成真实动作并记录回执' : '根据拒绝原因重做建议或结束本业务日动作',
            'next_owner' => ['user_id' => $nextOwnerId, 'role' => $status === 'approved' ? 'executor' : 'decision_owner'],
        ];
        if ($status === 'approved' && is_array($outcomeMetric)) {
            $payload['outcome_metric_definition_digest'] = $outcomeMetric['digest'];
            $payload['review_due_at'] = $reviewAt;
        }

        return ['ready' => true, 'input' => $this->transitionInput(
            $cycle,
            'recommendation_human_decision',
            $status === 'approved' ? 'completed' : 'blocked',
            'decision-intent-' . (int)$intent['id'] . '-' . $status,
            'human',
            $status === 'approved' ? $payload : array_merge($payload, [
                'block_code' => 'human_decision_rejected',
                'block_detail' => $judgement,
            ]),
            $refs,
            $approvedAt
        )];
    }

    /** @return array<string,mixed> */
    private function executionTransition(array $cycle, int $actorId): array
    {
        $decision = (array)($cycle['details']['recommendation_human_decision'] ?? []);
        $intentId = $this->decisionEvidenceIntentId($cycle);
        $intent = $intentId > 0 ? Db::name('operation_execution_intents')->where('id', $intentId)->find() : null;
        $task = $intentId > 0 ? Db::name('operation_execution_tasks')
            ->where('tenant_id', (int)$cycle['tenant_id'])
            ->where('hotel_id', (int)$cycle['hotel_id'])
            ->where('intent_id', $intentId)
            ->whereNull('deleted_at')
            ->where('status', 'executed')
            ->order('executed_at', 'desc')->order('id', 'desc')->find() : null;
        if (!is_array($intent) || !is_array($task)) {
            return ['ready' => false, 'code' => 'execution_receipt_not_ready', 'detail' => '已批准动作尚无已执行任务和正式回执。', 'owner' => (array)($cycle['next_owner'] ?? [])];
        }
        $operatorId = (int)($task['operator_id'] ?? 0);
        if ($operatorId <= 0 || $operatorId !== $actorId) {
            return ['ready' => false, 'code' => 'execution_actor_mismatch', 'detail' => '只有正式任务执行人可以把执行回执写入权威事件链。', 'owner' => ['user_id' => $operatorId, 'role' => 'executor']];
        }
        $evidenceRows = Db::name('operation_execution_evidence')
            ->where('tenant_id', (int)$cycle['tenant_id'])
            ->where('task_id', (int)$task['id'])
            ->whereNull('deleted_at')
            ->order('id', 'asc')->select()->toArray();
        $evidenceIds = array_values(array_map(
            static fn(array $row): int => (int)($row['id'] ?? 0),
            array_filter(
                $evidenceRows,
                fn(array $row): bool => $this->executionEvidenceMeaningful($row, $operatorId)
            )
        ));
        if ($evidenceIds === []) {
            return ['ready' => false, 'code' => 'execution_evidence_missing', 'detail' => '已执行任务尚无由执行人保存的有效执行回执内容。', 'owner' => ['user_id' => $operatorId, 'role' => 'executor']];
        }
        $target = $this->decode($intent['target_value_json'] ?? null);
        $platform = (string)$intent['platform'];
        $refs = [
            $this->ref('execution_intent', 'approval', 'operation_execution_intents', [$intentId], $platform),
            $this->ref('execution_task', 'execution', 'operation_execution_tasks', [(int)$task['id']], $platform),
            $this->ref('execution_receipt', 'execution', 'operation_execution_evidence', $evidenceIds, $platform),
        ];
        return ['ready' => true, 'input' => $this->transitionInput(
            $cycle,
            'real_execution_receipt',
            'completed',
            'execution-task-' . (int)$task['id'] . '-' . $this->idsKey([$refs[2]]),
            'human',
            [
                'executed_by' => $operatorId,
                'intent_id' => $intentId,
                'task_id' => (int)$task['id'],
                'object_type' => (string)$intent['object_type'],
                'action_type' => (string)$intent['action_type'],
                'target_value_digest' => $this->digest($target),
                'executed_action' => trim((string)($task['result_summary'] ?? '')) ?: ('已执行 ' . (string)$intent['action_type']),
                'executed_at' => (string)$task['executed_at'],
                'next_action' => '等待可复盘时间后按同酒店、同平台、同指标回读结果',
            ],
            $refs,
            (string)$task['executed_at']
        )];
    }

    /** @return array<string,mixed> */
    private function outcomeTransition(array $cycle, int $actorId): array
    {
        $execution = (array)($cycle['details']['real_execution_receipt'] ?? []);
        $taskId = (int)($execution['task_id'] ?? 0);
        $intentId = (int)($execution['intent_id'] ?? 0);
        $review = $taskId > 0 ? Db::name('operation_effect_reviews')
            ->where('tenant_id', (int)$cycle['tenant_id'])
            ->where('hotel_id', (int)$cycle['hotel_id'])
            ->where('intent_id', $intentId)
            ->where('task_id', $taskId)
            ->where('baseline_business_date', (string)$cycle['business_date'])
            ->order('reviewed_at', 'desc')->order('id', 'desc')->find() : null;
        if (!is_array($review)) {
            $task = $taskId > 0 ? Db::name('operation_execution_tasks')->where('id', $taskId)->find() : null;
            $intent = $intentId > 0 ? Db::name('operation_execution_intents')->where('id', $intentId)->find() : null;
            $terminal = is_array($task)
                && in_array(strtolower(trim((string)($task['result_status'] ?? ''))), ['success', 'near_success', 'failed'], true);
            $contractDeclared = is_array($intent) && $this->intentDeclaresEffectContract($intent);
            $code = !$terminal
                ? 'effect_review_not_recorded'
                : ($contractDeclared ? 'effect_review_missing' : 'effect_contract_missing');
            $detail = !$terminal
                ? '执行任务尚未形成正式人工复盘结论。'
                : ($contractDeclared
                    ? '人工冻结效果合同已存在，但尚无通过严格保存回读的效果复盘。'
                    : '该执行意图未冻结同口径效果合同，不能补造结果成功。');
            return ['ready' => false, 'code' => $code, 'detail' => $detail, 'owner' => ['role' => 'reviewer']];
        }
        $reviewerId = (int)($review['reviewed_by'] ?? 0);
        if ($reviewerId <= 0 || $reviewerId !== $actorId) {
            return ['ready' => false, 'code' => 'outcome_reviewer_mismatch', 'detail' => '只有正式效果回读的复盘人可以推进结果阶段。', 'owner' => ['user_id' => $reviewerId, 'role' => 'reviewer']];
        }
        $outcome = match (strtolower(trim((string)$review['outcome_status']))) {
            'met', 'near' => 'supported',
            'missed', 'adverse' => 'refuted',
            default => 'indeterminate',
        };
        $platform = (string)$review['platform'];
        return ['ready' => true, 'input' => $this->transitionInput(
            $cycle,
            'comparable_outcome_readback',
            'completed',
            'outcome-review-' . (int)$review['id'],
            'human',
            [
                'outcome_status' => $outcome,
                'reviewed_by' => $reviewerId,
                'result_summary' => (string)$review['result_summary'],
                'metric_definition_digest' => (string)$review['metric_definition_digest'],
            ],
            [$this->ref('outcome_readback', 'outcome', 'operation_effect_reviews', [(int)$review['id']], $platform)],
            (string)$review['reviewed_at']
        )];
    }

    /** @return array<string,mixed> */
    private function experienceTransition(array $cycle, int $actorId): array
    {
        $reviewId = $this->outcomeEvidenceReviewId($cycle);
        $review = $reviewId > 0 ? Db::name('operation_effect_reviews')->where('id', $reviewId)->find() : null;
        $taskId = is_array($review) ? (int)($review['task_id'] ?? 0) : 0;
        $memory = Db::name('hotel_operating_memories')
            ->where('tenant_id', (int)$cycle['tenant_id'])
            ->where('hotel_id', (int)$cycle['hotel_id'])
            ->where('business_date', (string)$cycle['business_date'])
            ->where('quality_status', 'verified')
            ->where('lifecycle_status', 'active')
            ->whereNull('deleted_at')
            ->where(function ($query) use ($reviewId, $taskId): void {
                $query->where(function ($nested) use ($reviewId): void {
                    $nested->where('source_record_type', 'operation_effect_review')->where('source_record_id', $reviewId);
                })->whereOr(function ($nested) use ($taskId): void {
                    $nested->where('source_record_type', 'operation_execution_task')->where('source_record_id', $taskId);
                });
            })
            ->order('id', 'desc')
            ->find();
        if (!is_array($memory)) {
            return ['ready' => false, 'code' => 'verified_operating_memory_missing', 'detail' => '效果复盘尚未形成已核验经营记忆。', 'owner' => ['role' => 'reviewer']];
        }
        $reviewerId = (int)($memory['recorded_by'] ?? 0);
        if ($reviewerId <= 0 || $reviewerId !== $actorId) {
            return ['ready' => false, 'code' => 'memory_reviewer_mismatch', 'detail' => '只有正式经营记忆的记录人可以完成经验复盘。', 'owner' => ['user_id' => $reviewerId, 'role' => 'reviewer']];
        }
        $experienceStatus = in_array(strtolower((string)($memory['usage_level'] ?? '')), ['decision_support', 'sop_template'], true)
            ? 'candidate'
            : 'not_reusable';
        return ['ready' => true, 'input' => $this->transitionInput(
            $cycle,
            'review_experience_promotion',
            'completed',
            'experience-memory-' . (int)$memory['id'],
            'human',
            [
                'reviewed_by' => $reviewerId,
                'experience_status' => $experienceStatus,
                'review_summary' => (string)($memory['summary'] ?? ''),
            ],
            [$this->ref('operating_memory', 'knowledge', 'hotel_operating_memories', [(int)$memory['id']], (string)($memory['platform'] ?? ''))],
            (string)($memory['occurred_at'] ?? $memory['created_at'] ?? date('Y-m-d H:i:s'))
        )];
    }

    /** @return array<string,mixed> */
    private function collectionEvidence(array $cycle, bool $withSavedRows): array
    {
        $refs = [];
        $savedRefs = [];
        foreach ((array)($cycle['source_identities'] ?? []) as $identity) {
            if (!is_array($identity)) {
                continue;
            }
            $kind = (string)$identity['source_kind'];
            $platform = (string)$identity['platform'];
            $planId = (int)($identity['collection_plan_id'] ?? 0);
            $planVersion = (int)($identity['collection_plan_version'] ?? 0);
            $planHash = strtolower(trim((string)($identity['collection_plan_hash'] ?? '')));
            if ($kind === 'pms') {
                $runQuery = Db::name('hotel_collection_plan_runs')
                    ->where('tenant_id', (int)$cycle['tenant_id'])
                    ->where('system_hotel_id', (int)$cycle['hotel_id'])
                    ->where('business_date', (string)$cycle['business_date'])
                    ->where('pms_provider', $platform);
                if ($planId > 0) {
                    $runQuery
                        ->where('plan_id', $planId)
                        ->where('plan_version', $planVersion)
                        ->where('plan_hash', $planHash);
                }
                $run = $runQuery->order('id', 'desc')->find();
                if (!is_array($run)) {
                    return ['ready' => false, 'code' => 'pms_collection_receipt_missing', 'detail' => $platform . ' 尚无目标业务日采集回执。'];
                }
                $pmsStatus = strtolower(trim((string)($run['pms_status'] ?? '')));
                if (!in_array($pmsStatus, ['verified', 'success'], true)) {
                    if (in_array(strtolower((string)($run['status'] ?? '')), ['blocked', 'failed'], true)) {
                        return ['ready' => false, 'failure' => [
                            'receipt_id' => (int)$run['id'],
                            'code' => 'pms_collection_' . ($pmsStatus ?: 'blocked'),
                            'detail' => $platform . ' 采集计划已正式报告未完成：' . ($pmsStatus ?: (string)$run['status']),
                            'occurred_at' => (string)($run['update_time'] ?? date('Y-m-d H:i:s')),
                            'ref' => $this->ref(
                                'collection_source',
                                'pms',
                                'hotel_collection_plan_runs',
                                [(int)$run['id']],
                                $platform,
                                (string)$cycle['business_date']
                            ),
                        ]];
                    }
                    return ['ready' => false, 'code' => 'pms_collection_not_verified', 'detail' => $platform . ' 采集回执尚未核验。'];
                }
                $refs[] = $this->ref('collection_source', 'pms', 'hotel_collection_plan_runs', [(int)$run['id']], $platform, (string)$cycle['business_date']);
                if ($withSavedRows) {
                    $captureId = (int)($run['pms_capture_id'] ?? 0);
                    $table = $platform === 'meituan_cloud_pms' ? 'meituan_cloud_pms_captures' : 'dingdandao_operating_target_captures';
                    if ($captureId <= 0 || (int)($run['pms_readback_verified'] ?? 0) !== 1) {
                        return ['ready' => false, 'code' => 'pms_exact_readback_missing', 'detail' => $platform . ' 尚未绑定精确回读的 PMS 保存行。'];
                    }
                    $savedRefs[] = $this->ref('saved_rows', 'pms', $table, [$captureId], $platform, (string)$cycle['business_date']);
                }
                continue;
            }

            $sourceQuery = Db::name('hotel_collection_plan_run_sources')->alias('s')
                ->join('hotel_collection_plan_runs r', 'r.id=s.run_id')
                ->where('r.tenant_id', (int)$cycle['tenant_id'])
                ->where('r.system_hotel_id', (int)$cycle['hotel_id'])
                ->where('r.business_date', (string)$cycle['business_date'])
                ->where('s.platform', $platform)
                ->where('s.data_source_id', (int)($identity['data_source_id'] ?? 0));
            if ($planId > 0) {
                $sourceQuery
                    ->where('r.plan_id', $planId)
                    ->where('r.plan_version', $planVersion)
                    ->where('r.plan_hash', $planHash);
            }
            $source = $sourceQuery
                ->field('s.*')
                ->order('s.id', 'desc')->find();
            if (!is_array($source)) {
                return ['ready' => false, 'code' => 'ota_collection_receipt_missing', 'detail' => $platform . ' 尚无目标业务日采集回执。'];
            }
            $status = strtolower(trim((string)($source['status'] ?? '')));
            if (!in_array($status, ['success', 'collected', 'verified', 'available'], true)) {
                if (in_array($status, ['blocked', 'failed'], true)) {
                    return ['ready' => false, 'failure' => [
                        'receipt_id' => (int)$source['id'],
                        'code' => trim((string)($source['failure_code'] ?? '')) ?: ('ota_collection_' . $status),
                        'detail' => $platform . ' 采集回执已正式报告 ' . $status . '。',
                        'occurred_at' => (string)($source['finished_at'] ?? $source['update_time'] ?? date('Y-m-d H:i:s')),
                        'ref' => $this->ref(
                            'collection_source',
                            'ota',
                            'hotel_collection_plan_run_sources',
                            [(int)$source['id']],
                            $platform,
                            (string)$cycle['business_date']
                        ),
                    ]];
                }
                return ['ready' => false, 'code' => 'ota_collection_not_verified', 'detail' => $platform . ' 采集回执尚未完成。'];
            }
            $refs[] = $this->ref('collection_source', 'ota', 'hotel_collection_plan_run_sources', [(int)$source['id']], $platform, (string)$cycle['business_date']);
            if ($withSavedRows) {
                $rowIds = $this->otaSavedRowIds($cycle, $source, $platform);
                if ($rowIds === [] || count($rowIds) !== (int)($source['readback_row_count'] ?? 0)) {
                    return ['ready' => false, 'code' => 'ota_exact_readback_missing', 'detail' => $platform . ' 保存行无法按回执精确重建和回读。'];
                }
                $savedRefs[] = $this->ref('saved_rows', 'ota', 'online_daily_data', $rowIds, $platform, (string)$cycle['business_date']);
            }
        }
        return ['ready' => $refs !== [], 'refs' => $refs, 'saved_refs' => $savedRefs];
    }

    /** @return list<int> */
    private function otaSavedRowIds(array $cycle, array $source, string $platform): array
    {
        $query = Db::name('online_daily_data')
            ->where('tenant_id', (int)$cycle['tenant_id'])
            ->where('data_source_id', (int)($source['data_source_id'] ?? 0))
            ->where('sync_task_id', (int)($source['platform_sync_task_id'] ?? 0))
            ->where('system_hotel_id', (int)$cycle['hotel_id'])
            ->where('data_date', (string)$cycle['business_date'])
            ->where('data_period', 'historical_daily')
            ->where('readback_verified', 1);
        $query->where('source', $platform);
        $ids = array_map('intval', $query->order('id', 'asc')->column('id'));
        return array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
    }

    /** @return array<string,mixed> */
    private function sourceIdentities(int $tenantId, int $hotelId, string $businessDate): array
    {
        if (!$this->tableExists('hotel_collection_plans')) {
            return [
                'ready' => false,
                'code' => 'active_collection_plan_missing',
                'detail' => '该酒店尚无可回读的当前生效采集计划，不能自行推断权威来源。',
                'owner' => ['role' => 'data_operator'],
            ];
        }
        $hotel = Db::name('hotels')
            ->where('id', $hotelId)
            ->where('tenant_id', $tenantId)
            ->where('status', 1)
            ->find();
        $planRow = Db::name('hotel_collection_plans')
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('active_slot', 1)
            ->find();
        if (!is_array($hotel) || !is_array($planRow)) {
            return [
                'ready' => false,
                'code' => 'active_collection_plan_missing',
                'detail' => '该酒店尚无当前生效采集计划，不能把候选来源冻结为权威身份。',
                'owner' => ['role' => 'data_operator'],
            ];
        }
        $planOwnerId = (int)($planRow['execution_owner_user_id'] ?? 0);
        if ($planOwnerId <= 0) {
            return [
                'ready' => false,
                'code' => 'collection_plan_owner_missing',
                'detail' => '当前生效采集计划没有唯一执行负责人。',
                'owner' => ['role' => 'data_operator'],
            ];
        }
        try {
            $plan = $this->collectionPlan->read($hotel, $planOwnerId, $businessDate);
        } catch (\Throwable) {
            return [
                'ready' => false,
                'code' => 'collection_plan_readback_failed',
                'detail' => '当前生效采集计划无法完成签名回读。',
                'owner' => ['user_id' => $planOwnerId, 'role' => 'data_operator'],
            ];
        }
        $currentBindingStatus = strtolower(trim((string)($plan['current_binding_status'] ?? '')));
        if ((int)($plan['id'] ?? 0) !== (int)$planRow['id']
            || (string)($plan['plan_status'] ?? '') !== 'active'
            || ($plan['enabled'] ?? false) !== true
            || ($plan['active_slot'] ?? false) !== true
            || ($plan['readback_verified'] ?? false) !== true
            || ($plan['binding_digest_matches'] ?? false) !== true
            || (string)($plan['stored_validation_status'] ?? '') !== 'ready'
            || !in_array($currentBindingStatus, ['ready', 'recoverable'], true)
        ) {
            return [
                'ready' => false,
                'code' => 'collection_plan_not_authoritative',
                'detail' => '当前采集计划未同时通过生效状态、签名回读和来源绑定一致性校验。',
                'owner' => ['user_id' => $planOwnerId, 'role' => 'data_operator'],
            ];
        }

        $sources = is_array($plan['sources'] ?? null) ? $plan['sources'] : [];
        $planId = (int)$plan['id'];
        $planVersion = (int)($plan['plan_version'] ?? 0);
        $planHash = strtolower(trim((string)($plan['plan_hash'] ?? '')));
        if ($planVersion <= 0 || preg_match('/^[a-f0-9]{64}$/D', $planHash) !== 1) {
            return [
                'ready' => false,
                'code' => 'collection_plan_identity_invalid',
                'detail' => '当前采集计划缺少可冻结的版本或签名摘要。',
                'owner' => ['user_id' => $planOwnerId, 'role' => 'data_operator'],
            ];
        }

        $identities = [];
        foreach (['ctrip', 'meituan'] as $platform) {
            $source = is_array($sources[$platform] ?? null) ? $sources[$platform] : [];
            $sourceId = (int)($source['data_source_id'] ?? 0);
            $externalId = trim((string)($source['platform_hotel_id'] ?? ''));
            $row = $sourceId > 0 ? Db::name('platform_data_sources')
                ->where('id', $sourceId)
                ->where('tenant_id', $tenantId)
                ->where('system_hotel_id', $hotelId)
                ->where('platform', $platform)
                ->find() : null;
            if (!is_array($row) || $externalId === '') {
                return [
                    'ready' => false,
                    'code' => 'planned_ota_identity_missing',
                    'detail' => $platform . ' 当前计划指定来源无法按酒店和平台精确回读。',
                    'owner' => ['user_id' => $planOwnerId, 'role' => 'data_operator'],
                ];
            }
            $identities[] = [
                'source_kind' => 'ota',
                'platform' => $platform,
                'platform_hotel_id' => $externalId,
                'data_source_id' => $sourceId,
                'collection_plan_id' => $planId,
                'collection_plan_version' => $planVersion,
                'collection_plan_hash' => $planHash,
                'evidence_ref' => ['table' => 'platform_data_sources', 'row_id' => $sourceId],
            ];
        }

        $pms = is_array($sources['pms'] ?? null) ? $sources['pms'] : [];
        $provider = strtolower(trim((string)($pms['provider'] ?? '')));
        $providerHotelId = trim((string)($pms['provider_hotel_id'] ?? ''));
        $pmsTable = match ($provider) {
            'dingdandao_pms' => 'dingdandao_pms_integrations',
            'meituan_cloud_pms' => 'meituan_cloud_pms_integrations',
            default => '',
        };
        $pmsRow = $pmsTable !== '' && $this->tableExists($pmsTable)
            ? Db::name($pmsTable)
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->where('provider', $provider)
                ->where('provider_hotel_id', $providerHotelId)
                ->where('status', 1)
                ->order('id', 'desc')
                ->find()
            : null;
        if (!is_array($pmsRow) || $providerHotelId === '') {
            return [
                'ready' => false,
                'code' => 'planned_pms_identity_missing',
                'detail' => '当前计划指定的 PMS 门店身份无法按酒店、供应商和门店精确回读。',
                'owner' => ['user_id' => $planOwnerId, 'role' => 'data_operator'],
            ];
        }
        $identities[] = [
            'source_kind' => 'pms',
            'platform' => $provider,
            'provider_hotel_id' => $providerHotelId,
            'collection_plan_id' => $planId,
            'collection_plan_version' => $planVersion,
            'collection_plan_hash' => $planHash,
            'evidence_ref' => ['table' => $pmsTable, 'row_id' => (int)$pmsRow['id']],
        ];

        return [
            'ready' => true,
            'identities' => $identities,
        ];
    }

    /** @return array{version:string,definition:array<string,mixed>} */
    private function metricContract(int $tenantId, int $hotelId, string $businessDate): array
    {
        $intentRows = Db::name('operation_execution_intents')
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->whereNull('deleted_at')
            ->order('id', 'desc')
            ->limit(100)
            ->select()
            ->toArray();
        $intent = null;
        foreach ($intentRows as $candidate) {
            if (is_array($candidate) && $this->intentBaselineDate($candidate) === $businessDate) {
                $intent = $candidate;
                break;
            }
        }
        if (is_array($intent)) {
            $target = $this->decode($intent['target_value_json'] ?? null);
            $outcomeMetric = $this->intentOutcomeMetric($intent, $target);
            if ($outcomeMetric !== null) {
                return [
                    'version' => $outcomeMetric['version'],
                    'definition' => $outcomeMetric['payload'],
                ];
            }
        }
        return [
            'version' => self::METRIC_BUNDLE_VERSION,
            'definition' => [
                'version' => self::METRIC_BUNDLE_VERSION,
                'fact_contract_version' => RevenueFactLayerService::CONTRACT_VERSION,
                'business_date_grain' => 'tenant_id + system_hotel_id + business_date + source',
                'scopes' => ['whole_hotel_accommodation', 'ota_channel'],
                'pms_plus_ota_revenue_addition_allowed' => false,
                'missing_value_policy' => 'null_not_zero',
                'outcome_metric_policy' => 'human_decision_frozen_metric_member',
            ],
        ];
    }

    /** @return array{version:string,payload:array<string,mixed>,digest:string}|null */
    private function intentOutcomeMetric(array $intent, array $target): ?array
    {
        $definition = is_array($target['metric_definition'] ?? null) ? $target['metric_definition'] : [];
        $metricKey = strtolower(trim((string)($intent['expected_metric'] ?? $target['expected_metric'] ?? '')));
        $declaredDigest = strtolower(trim((string)($target['metric_definition_digest'] ?? '')));
        if ($definition === [] || $metricKey === '' || preg_match('/^[a-f0-9]{64}$/D', $declaredDigest) !== 1) {
            return null;
        }
        $payload = ['metric_key' => $metricKey, 'definition' => $definition];
        $digest = $this->digest($payload);
        if (!hash_equals($digest, $declaredDigest)) {
            return null;
        }
        $version = trim((string)($definition['version'] ?? $definition['contract_version'] ?? ''));
        if ($version === '') {
            return null;
        }
        return ['version' => $version, 'payload' => $payload, 'digest' => $digest];
    }

    private function intentBaselineDate(array $intent): string
    {
        $evidence = $this->decode($intent['evidence_json'] ?? null);
        $approvalTarget = is_array($evidence['approval_target'] ?? null)
            ? $evidence['approval_target']
            : [];
        foreach ([$approvalTarget['baseline_business_date'] ?? null, $intent['date_end'] ?? null, $intent['date_start'] ?? null] as $value) {
            $date = trim((string)$value);
            if ($this->isDate($date)) {
                return $date;
            }
        }
        return '';
    }

    private function intentDeclaresEffectContract(array $intent): bool
    {
        $target = $this->decode($intent['target_value_json'] ?? null);
        $evidence = $this->decode($intent['evidence_json'] ?? null);
        return is_array($evidence['approval_target'] ?? null)
            && $evidence['approval_target'] !== []
            || trim((string)($evidence['approval_target_digest'] ?? $target['approval_target_digest'] ?? '')) !== '';
    }

    private function decisionEvidenceIntentId(array $cycle): int
    {
        $events = array_reverse((array)($cycle['events'] ?? []));
        foreach ($events as $event) {
            if (!is_array($event)
                || (string)($event['stage_key'] ?? '') !== 'recommendation_human_decision'
                || (string)($event['stage_status'] ?? '') !== 'completed'
            ) {
                continue;
            }
            foreach ((array)($event['evidence_refs'] ?? []) as $ref) {
                if (is_array($ref)
                    && (string)($ref['role'] ?? '') === 'recommendation'
                    && (string)($ref['table'] ?? '') === 'operation_execution_intents'
                ) {
                    return (int)($ref['row_ids'][0] ?? 0);
                }
            }
        }
        return 0;
    }

    private function outcomeEvidenceReviewId(array $cycle): int
    {
        foreach ((array)($cycle['events'] ?? []) as $event) {
            if (!is_array($event) || (string)($event['stage_key'] ?? '') !== 'comparable_outcome_readback') {
                continue;
            }
            foreach ((array)($event['evidence_refs'] ?? []) as $ref) {
                if (is_array($ref) && (string)($ref['role'] ?? '') === 'outcome_readback') {
                    return (int)($ref['row_ids'][0] ?? 0);
                }
            }
        }
        return 0;
    }

    /** @return array<string,mixed> */
    private function transitionInput(
        array $cycle,
        string $stage,
        string $status,
        string $commandKey,
        string $actorKind,
        array $payload,
        array $refs,
        string $occurredAt = ''
    ): array {
        return [
            'target_stage' => $stage,
            'stage_status' => $status,
            'expected_version' => (int)$cycle['revision'],
            'command_key' => $commandKey,
            'actor_kind' => $actorKind,
            'source_module' => 'operating_loop_coordinator',
            'occurred_at' => $occurredAt !== '' ? $occurredAt : date('Y-m-d H:i:s'),
            'payload' => $payload,
            'evidence_refs' => $refs,
        ];
    }

    /** @return array<string,mixed> */
    private function ref(
        string $role,
        string $sourceKind,
        string $table,
        array $rowIds,
        string $platform,
        string $businessDate = ''
    ): array {
        $ref = [
            'role' => $role,
            'source_kind' => $sourceKind,
            'table' => $table,
            'row_ids' => array_values(array_map('intval', $rowIds)),
            'platform' => $platform,
        ];
        if ($businessDate !== '') {
            $ref['business_date'] = $businessDate;
        }
        return $ref;
    }

    /** @return array<string,mixed> */
    private function waiting(
        array $summary,
        array $reconciled,
        string $stage,
        string $code,
        string $detail,
        array $owner = []
    ): array {
        return [
            'operating_loop' => $summary,
            'reconciled_stages' => $reconciled,
            'waiting' => [
                'stage' => $stage,
                'code' => $code,
                'detail' => $detail,
                'owner' => $owner,
            ],
            'persistence_status' => (int)($summary['record_id'] ?? 0) > 0 ? 'readback_verified' : 'not_written',
            'source_policy' => 'existing_formal_rows_to_operating_cycle_kernel_only',
        ];
    }

    private function identitySummary(int $hotelId, string $businessDate, array $identities, string $metricVersion): string
    {
        $sources = array_map(static fn(array $identity): string => (string)$identity['platform'], $identities);
        return sprintf(
            '酒店 %d、业务日期 %s、来源身份（%s）及指标版本 %s 已由当前登录人确认。',
            $hotelId,
            $businessDate,
            implode('、', $sources),
            $metricVersion
        );
    }

    private function idsKey(array $refs): string
    {
        $parts = [];
        foreach ($refs as $ref) {
            if (!is_array($ref)) {
                continue;
            }
            $parts[] = (string)($ref['table'] ?? '') . ':' . implode(',', (array)($ref['row_ids'] ?? []));
        }
        return substr(hash('sha256', implode('|', $parts)), 0, 24);
    }

    private function executionEvidenceMeaningful(array $row, int $operatorId): bool
    {
        return OperationManagementService::isMeaningfulExecutionReceipt($row, $operatorId);
    }

    private function hasMeaningfulValue(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->hasMeaningfulValue($item)) {
                    return true;
                }
            }
            return false;
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }
        return $value !== null && $value !== false;
    }

    private function date(string $value): string
    {
        if (!$this->isDate($value)) {
            throw new InvalidArgumentException('business_date 必须是有效 YYYY-MM-DD 日期');
        }
        return $value;
    }

    private function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function isDateTime(string $value): bool
    {
        return strtotime($value) !== false && trim($value) !== '';
    }

    /** @return array<string,mixed> */
    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function digest(array $value): string
    {
        $normalized = $this->canonicalize($value);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        return hash('sha256', $json);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }

    private function tableExists(string $table): bool
    {
        try {
            Db::query('SELECT 1 FROM `' . str_replace('`', '', $table) . '` LIMIT 1');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
