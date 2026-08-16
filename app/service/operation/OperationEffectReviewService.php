<?php
declare(strict_types=1);

namespace app\service\operation;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use think\facade\Db;

/**
 * Append-only effect reviews for completed operation tasks.
 *
 * This service never writes OTA state and never treats operator-attested values
 * as effect facts. The before/after values and source references are copied only
 * from one verified source-readback evidence row in the same tenant/hotel/task
 * scope. Targets and metric definitions must already have been frozen on the
 * approved intent.
 */
final class OperationEffectReviewService
{
    public const TABLE = 'operation_effect_reviews';

    /** @var list<string> */
    private const REQUIRED_TABLES = [
        self::TABLE,
        'operation_execution_intents',
        'operation_execution_tasks',
        'operation_execution_evidence',
    ];

    /** @var list<string> */
    private const SOURCE_READBACK_EVIDENCE_TYPES = [
        'source_verified_metric_readback',
        'ota_source_readback',
        'business_metric_readback',
    ];

    /** @var list<string> */
    private const OUTCOME_STATUSES = ['met', 'near', 'missed', 'adverse'];

    public function __construct(
        private readonly ExecutionOutcomeService $outcomeService = new ExecutionOutcomeService()
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(
        int $tenantId,
        int $hotelId,
        int $intentId,
        int $taskId,
        array $input,
        int $reviewedBy
    ): array {
        $this->assertTablesReady();
        $this->assertPositiveIdentity($tenantId, $hotelId, $intentId, $taskId, $reviewedBy);
        $this->assertInputIdentity($input, $tenantId, $hotelId, $intentId, $taskId);

        $platform = $this->requiredToken($input['platform'] ?? null, '效果复盘平台', 40);
        $metricKey = $this->requiredToken($input['metric_key'] ?? null, '效果复盘指标', 80);
        $baselineDate = $this->requiredDate($input['baseline_business_date'] ?? null, '基准经营日期');
        $reviewDate = $this->requiredDate($input['review_business_date'] ?? null, '复盘经营日期');
        $this->assertReviewDateOrder($baselineDate, $reviewDate);
        $sourceEvidenceId = is_numeric($input['source_readback_evidence_id'] ?? null)
            ? (int)$input['source_readback_evidence_id']
            : 0;
        if ($sourceEvidenceId <= 0) {
            throw new InvalidArgumentException('效果复盘缺少来源回读证据ID');
        }
        $resultSummaryValue = $input['result_summary'] ?? '';
        $resultSummary = is_scalar($resultSummaryValue) ? trim((string)$resultSummaryValue) : '';
        if ($resultSummary === '' || mb_strlen($resultSummary) > 1000) {
            throw new InvalidArgumentException('效果复盘结论不能为空且不能超过1000字');
        }
        $reviewedAt = $this->requiredDateTime(
            $input['reviewed_at'] ?? date('Y-m-d H:i:s'),
            '复盘时间'
        );
        if (array_key_exists('causality_claimed', $input)
            && $this->strictBoolean($input['causality_claimed']) !== false
        ) {
            throw new InvalidArgumentException('效果复盘只能记录观察结果，不能宣称因果');
        }

        $write = Db::transaction(function () use (
            $tenantId,
            $hotelId,
            $intentId,
            $taskId,
            $input,
            $reviewedBy,
            $reviewedAt,
            $platform,
            $metricKey,
            $baselineDate,
            $reviewDate,
            $sourceEvidenceId,
            $resultSummary
        ): array {
            $intent = $this->scopedIntent($tenantId, $hotelId, $intentId, true);
            $task = $this->scopedTask($tenantId, $hotelId, $intentId, $taskId, true);
            $sourceEvidence = $this->scopedSourceEvidence(
                $tenantId,
                $taskId,
                $sourceEvidenceId,
                true
            );

            if (strtolower(trim((string)($intent['status'] ?? ''))) !== 'approved') {
                throw new InvalidArgumentException('效果复盘只能绑定已人工批准的执行意图');
            }
            if (strtolower(trim((string)($task['status'] ?? ''))) !== 'executed') {
                throw new InvalidArgumentException('效果复盘只能绑定已执行的运营任务');
            }

            $intentPlatform = strtolower(trim((string)($intent['platform'] ?? '')));
            if ($intentPlatform === '' || $intentPlatform !== $platform) {
                throw new InvalidArgumentException('效果复盘平台与执行意图范围不一致');
            }
            $intentMetric = strtolower(trim((string)($intent['expected_metric'] ?? '')));
            if ($intentMetric === '' || $intentMetric !== $metricKey) {
                throw new InvalidArgumentException('效果复盘指标与执行意图口径不一致');
            }
            $intentBaselineDate = $this->intentBaselineDate($intent);
            if ($intentBaselineDate === null || $intentBaselineDate !== $baselineDate) {
                throw new InvalidArgumentException('效果复盘基准日期与执行意图范围不一致');
            }

            $approvalContract = $this->frozenApprovalContract(
                $intent,
                $platform,
                $metricKey,
                $baselineDate,
                $reviewDate
            );
            $approvalTargetDigest = strtolower(trim((string)($approvalContract['content_digest'] ?? '')));
            if (!$this->isDigest($approvalTargetDigest)) {
                throw new InvalidArgumentException('效果复盘缺少有效人工审批冻结目标摘要');
            }
            $target = $this->frozenTarget($intent, $metricKey, $input, $approvalContract);
            $metricDefinition = $this->frozenMetricDefinition($intent, $metricKey, $input, $approvalContract);
            $readback = $this->verifiedSourceReadback(
                $sourceEvidence,
                $intent,
                $task,
                $tenantId,
                $hotelId,
                $platform,
                $metricKey,
                $baselineDate,
                $reviewDate,
                $reviewedAt,
                $input
            );

            $normalizedIntent = $intent;
            $normalizedIntent['target_value'] = $target['normalized_target_value'];
            $normalizedIntent['evidence'] = $this->decodeJson($intent['evidence_json'] ?? null);
            $normalizedIntent['expected_delta'] = $target['expected_delta'];
            $normalizedIntent['expected_direction'] = $target['expected_direction'];
            $normalizedTask = $task;
            $normalizedSourceEvidence = $sourceEvidence;
            $normalizedSourceEvidence['before'] = [$metricKey => (float)$readback['before_value']];
            $normalizedSourceEvidence['after'] = [$metricKey => (float)$readback['after_value']];
            $normalizedSourceEvidence['platform_response'] = $readback['source_context'];

            $outcome = $this->outcomeService->buildExecutionOutcomeTruth(
                $normalizedIntent,
                $normalizedTask,
                [$normalizedSourceEvidence]
            );
            $outcomeStatus = strtolower(trim((string)($outcome['status'] ?? 'unverified')));
            if (($outcome['source_verified'] ?? false) !== true
                || ($outcome['outcome_verified'] ?? false) !== true
                || !in_array($outcomeStatus, self::OUTCOME_STATUSES, true)
            ) {
                $reason = trim((string)($outcome['failure_reason'] ?? 'effect_outcome_unverified'));
                throw new InvalidArgumentException('来源回读无法形成已核验效果结论：' . $reason);
            }
            $resultStatus = match ($outcomeStatus) {
                'met' => 'success',
                'near' => 'near_success',
                default => 'failed',
            };
            if (strtolower(trim((string)($task['result_status'] ?? ''))) !== $resultStatus
                || trim((string)($task['result_summary'] ?? '')) !== $resultSummary
            ) {
                throw new InvalidArgumentException('效果复盘结论必须与当前任务已保存的人工结论完全一致');
            }
            $this->assertOptionalStringMatch($input, 'outcome_status', $outcomeStatus, '效果状态');
            $this->assertOptionalStringMatch($input, 'result_status', $resultStatus, '复盘结果状态');

            $outcomePayload = $this->canonicalize([
                'source_verified' => true,
                'outcome_verified' => true,
                'positive_outcome_verified' => ($outcome['positive_outcome_verified'] ?? false) === true,
                'status' => $outcomeStatus,
                'metric_key' => $metricKey,
                'direction' => $target['expected_direction'],
                'target_type' => $target['target_type'],
                'target_value' => $target['target_value'],
                'expected_delta' => $target['expected_delta'],
                'before_value' => $readback['before_value'],
                'after_value' => $readback['after_value'],
                'actual_delta' => $this->optionalDecimal($outcome['actual_delta'] ?? null, '实际指标变化'),
                'favorable_delta' => $this->optionalDecimal($outcome['favorable_delta'] ?? null, '有利方向变化'),
                'progress_rate' => $this->optionalDecimal($outcome['progress_rate'] ?? null, '目标进度'),
                'failure_reason' => $outcome['failure_reason'] ?? null,
                'source_readback_evidence_id' => $sourceEvidenceId,
                'verification_authority' => 'system_readback',
                'metric_definition_digest' => $metricDefinition['digest'],
                'approval_target_digest' => $approvalTargetDigest,
                'causality_claimed' => false,
            ]);

            $digestPayload = [
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'intent_id' => $intentId,
                'task_id' => $taskId,
                'platform' => $platform,
                'baseline_business_date' => $baselineDate,
                'review_business_date' => $reviewDate,
                'metric_key' => $metricKey,
                'metric_definition' => $metricDefinition['payload'],
                'metric_definition_digest' => $metricDefinition['digest'],
                'approval_target_digest' => $approvalTargetDigest,
                'before_value' => $readback['before_value'],
                'after_value' => $readback['after_value'],
                'expected_direction' => $target['expected_direction'],
                'target_type' => $target['target_type'],
                'target_value' => $target['target_value'],
                'expected_delta' => $target['expected_delta'],
                'expected_delta_status' => $target['expected_delta_status'],
                'target_confirmed_by' => $target['target_confirmed_by'],
                'target_confirmed_at' => $target['target_confirmed_at'],
                'baseline_refs' => $readback['baseline_refs'],
                'followup_refs' => $readback['followup_refs'],
                'source_readback_evidence_id' => $sourceEvidenceId,
                'outcome_status' => $outcomeStatus,
                'outcome' => $outcomePayload,
                'result_status' => $resultStatus,
                'result_summary' => $resultSummary,
                'causality_claimed' => false,
                'reviewed_by' => $reviewedBy,
                'reviewed_at' => $reviewedAt,
            ];
            $contentDigest = hash('sha256', $this->canonicalJson($digestPayload));

            $existing = Db::name(self::TABLE)
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->where('task_id', $taskId)
                ->where('content_digest', $contentDigest)
                ->find();
            if (is_array($existing) && (int)($existing['id'] ?? 0) > 0) {
                return ['id' => (int)$existing['id'], 'created' => false];
            }

            $id = (int)Db::name(self::TABLE)->insertGetId([
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'intent_id' => $intentId,
                'task_id' => $taskId,
                'platform' => $platform,
                'baseline_business_date' => $baselineDate,
                'review_business_date' => $reviewDate,
                'metric_key' => $metricKey,
                'metric_definition_json' => $this->canonicalJson($metricDefinition['payload']),
                'metric_definition_digest' => $metricDefinition['digest'],
                'approval_target_digest' => $approvalTargetDigest,
                'before_value' => $readback['before_value'],
                'after_value' => $readback['after_value'],
                'expected_direction' => $target['expected_direction'],
                'target_type' => $target['target_type'],
                'target_value' => $target['target_value'],
                'expected_delta' => $target['expected_delta'],
                'expected_delta_status' => $target['expected_delta_status'],
                'target_confirmed_by' => $target['target_confirmed_by'],
                'target_confirmed_at' => $target['target_confirmed_at'],
                'baseline_refs_json' => $this->canonicalJson($readback['baseline_refs']),
                'followup_refs_json' => $this->canonicalJson($readback['followup_refs']),
                'source_readback_evidence_id' => $sourceEvidenceId,
                'outcome_status' => $outcomeStatus,
                'outcome_json' => $this->canonicalJson($outcomePayload),
                'result_status' => $resultStatus,
                'result_summary' => $resultSummary,
                'causality_claimed' => 0,
                'reviewed_by' => $reviewedBy,
                'reviewed_at' => $reviewedAt,
                'content_digest' => $contentDigest,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            if ($id <= 0) {
                throw new RuntimeException('效果复盘保存失败：未取得记录ID');
            }

            return ['id' => $id, 'created' => true];
        });

        $review = $this->readVerified(
            (int)$write['id'],
            $tenantId,
            $hotelId,
            $intentId,
            $taskId
        );

        return [
            'review' => $review,
            'created' => (bool)$write['created'],
            'persistence_status' => 'readback_verified',
            'write_boundaries' => [
                'append_only' => true,
                'ota_write' => false,
                'execution_evidence_mutated' => false,
                'causality_claimed' => false,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function listForTask(
        int $tenantId,
        int $hotelId,
        int $intentId,
        int $taskId
    ): array {
        $this->assertTablesReady();
        $this->assertPositiveIdentity($tenantId, $hotelId, $intentId, $taskId, 1);
        $intent = $this->scopedIntent($tenantId, $hotelId, $intentId, false);
        $this->scopedTask($tenantId, $hotelId, $intentId, $taskId, false);

        $rows = Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('intent_id', $intentId)
            ->where('task_id', $taskId)
            ->order('id', 'desc')
            ->select()
            ->toArray();
        $reviews = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $reviews[] = $this->bindCurrentApprovalContract(
                $this->normalizeAndVerifyRow($row),
                $intent
            );
        }
        $approvalContractVerified = count(array_filter(
            $reviews,
            static fn(array $review): bool => ($review['approval_contract_verified'] ?? false) === true
        ));

        return [
            'list' => $reviews,
            'count' => count($reviews),
            'approval_contract_verified_count' => $approvalContractVerified,
            'persistence_status' => $approvalContractVerified === count($reviews)
                ? 'readback_verified'
                : 'approval_target_drifted',
        ];
    }

    /** @return array<string, mixed> */
    public function readVerified(
        int $id,
        int $tenantId,
        int $hotelId,
        int $intentId,
        int $taskId
    ): array {
        $this->assertTablesReady();
        if ($id <= 0) {
            throw new InvalidArgumentException('效果复盘ID无效');
        }
        $this->assertPositiveIdentity($tenantId, $hotelId, $intentId, $taskId, 1);
        $intent = $this->scopedIntent($tenantId, $hotelId, $intentId, false);
        $this->scopedTask($tenantId, $hotelId, $intentId, $taskId, false);
        $row = Db::name(self::TABLE)
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('intent_id', $intentId)
            ->where('task_id', $taskId)
            ->find();
        if (!is_array($row)) {
            throw new RuntimeException('效果复盘不存在或不属于当前经营范围');
        }

        $review = $this->bindCurrentApprovalContract($this->normalizeAndVerifyRow($row), $intent);
        if (($review['approval_contract_verified'] ?? false) !== true
            || ($review['active_eligible'] ?? false) !== true
        ) {
            throw new RuntimeException('效果复盘绑定的人工审批冻结目标已漂移');
        }

        return $review;
    }

    /** @param array<string,mixed> $review @param array<string,mixed> $intent @return array<string,mixed> */
    private function bindCurrentApprovalContract(array $review, array $intent): array
    {
        $persistedDigest = strtolower(trim((string)($review['approval_target_digest'] ?? '')));
        $review['approval_contract_verified'] = false;
        $review['active_eligible'] = false;
        $review['approval_contract_validation_status'] = 'approval_target_digest_missing';
        $review['current_approval_target_digest'] = '';
        if (!$this->isDigest($persistedDigest)) {
            return $review;
        }

        try {
            $contract = $this->frozenApprovalContract(
                $intent,
                strtolower(trim((string)($review['platform'] ?? ''))),
                strtolower(trim((string)($review['metric_key'] ?? ''))),
                trim((string)($review['baseline_business_date'] ?? '')),
                trim((string)($review['review_business_date'] ?? ''))
            );
        } catch (InvalidArgumentException|RuntimeException) {
            $review['approval_contract_validation_status'] = 'current_approval_contract_invalid';
            return $review;
        }

        $currentDigest = strtolower(trim((string)($contract['content_digest'] ?? '')));
        $review['current_approval_target_digest'] = $currentDigest;
        if (!$this->isDigest($currentDigest) || !hash_equals($persistedDigest, $currentDigest)) {
            $review['approval_contract_validation_status'] = 'approval_target_digest_mismatch';
            return $review;
        }

        $review['approval_contract_verified'] = true;
        $review['active_eligible'] = true;
        $review['approval_contract_validation_status'] = 'verified';
        return $review;
    }

    /** @return array<string,mixed> */
    private function frozenApprovalContract(
        array $intent,
        string $platform,
        string $metricKey,
        string $baselineDate,
        string $reviewDate
    ): array {
        $targetValue = $this->decodeJson($intent['target_value_json'] ?? null);
        $intentEvidence = $this->decodeJson($intent['evidence_json'] ?? null);
        $contract = $this->arrayValue($intentEvidence['approval_target'] ?? []);
        $contentDigest = strtolower(trim((string)($contract['content_digest'] ?? '')));
        $declaredContractDigest = strtolower(trim((string)($intentEvidence['approval_target_digest'] ?? '')));
        $targetContractDigest = strtolower(trim((string)($targetValue['approval_target_digest'] ?? '')));
        if (($contract['version'] ?? '') !== 'ota_execution_approval_target.v1'
            || !$this->isDigest($contentDigest)
            || !hash_equals($contentDigest, $this->approvalContractDigest($contract))
            || !hash_equals($contentDigest, $declaredContractDigest)
            || !hash_equals($contentDigest, $targetContractDigest)
        ) {
            throw new InvalidArgumentException('效果复盘缺少完整且未漂移的人工审批冻结契约');
        }

        $intentId = (int)($intent['id'] ?? 0);
        $tenantId = (int)($intent['tenant_id'] ?? 0);
        $hotelId = (int)($intent['hotel_id'] ?? 0);
        $sourceModule = strtolower(trim((string)($intent['source_module'] ?? '')));
        $sourceRecordId = (int)($intent['source_record_id'] ?? 0);
        $approvedBy = (int)($intent['approved_by'] ?? 0);
        $approvedAt = $this->requiredDateTime($intent['approved_at'] ?? null, '目标审批时间');
        if ((int)($contract['intent_id'] ?? 0) !== $intentId
            || (int)($contract['tenant_id'] ?? 0) !== $tenantId
            || (int)($contract['hotel_id'] ?? 0) !== $hotelId
            || strtolower(trim((string)($contract['source_module'] ?? ''))) !== $sourceModule
            || (int)($contract['source_record_id'] ?? 0) !== $sourceRecordId
            || strtolower(trim((string)($contract['platform'] ?? ''))) !== $platform
            || strtolower(trim((string)($contract['expected_metric'] ?? ''))) !== $metricKey
            || trim((string)($contract['baseline_business_date'] ?? '')) !== $baselineDate
            || trim((string)($contract['review_business_date'] ?? '')) !== $reviewDate
            || (int)($contract['approved_by'] ?? 0) !== $approvedBy
            || $this->requiredDateTime($contract['approved_at'] ?? null, '冻结契约审批时间') !== $approvedAt
            || strtolower(trim((string)($contract['expected_delta_status'] ?? ''))) !== 'manual_confirmed'
        ) {
            throw new InvalidArgumentException('效果复盘范围、日期、指标或审批身份与冻结契约不一致');
        }
        $expectedReviewDate = (new DateTimeImmutable($baselineDate))->modify('+1 day')->format('Y-m-d');
        if ($reviewDate !== $expectedReviewDate) {
            throw new InvalidArgumentException('效果复盘经营日期必须是审批基准日的下一日');
        }

        $contractDefinition = $this->arrayValue($contract['metric_definition'] ?? []);
        $targetDefinition = $this->arrayValue($targetValue['metric_definition'] ?? []);
        $evidenceDefinition = $this->arrayValue($intentEvidence['metric_definition'] ?? []);
        $contractDefinitionDigest = strtolower(trim((string)($contract['metric_definition_digest'] ?? '')));
        $definitionPayload = [
            'metric_key' => $metricKey,
            'definition' => $this->normalizeDefinition($contractDefinition),
        ];
        $expectedDefinitionDigest = hash('sha256', $this->canonicalJson($definitionPayload));
        if ($contractDefinition === []
            || $contractDefinition !== $targetDefinition
            || $contractDefinition !== $evidenceDefinition
            || !$this->isDigest($contractDefinitionDigest)
            || !hash_equals($expectedDefinitionDigest, $contractDefinitionDigest)
            || !hash_equals($contractDefinitionDigest, strtolower(trim((string)($targetValue['metric_definition_digest'] ?? ''))))
            || !hash_equals($contractDefinitionDigest, strtolower(trim((string)($intentEvidence['metric_definition_digest'] ?? ''))))
        ) {
            throw new InvalidArgumentException('效果复盘指标定义与人工审批冻结契约不一致');
        }

        $targetType = strtolower(trim((string)($contract['target_type'] ?? '')));
        $direction = $this->normalizeDirection((string)($contract['expected_direction'] ?? ''));
        if (!in_array($targetType, ['absolute', 'delta'], true) || $direction === null) {
            throw new InvalidArgumentException('人工审批冻结契约缺少有效目标类型或方向');
        }
        if ($targetType !== strtolower(trim((string)($targetValue['target_type'] ?? '')))
            || $direction !== $this->normalizeDirection((string)($targetValue['expected_direction'] ?? ''))
            || $targetType !== strtolower(trim((string)($intentEvidence['target_type'] ?? '')))
            || $direction !== $this->normalizeDirection((string)($intentEvidence['expected_direction'] ?? ''))
            || $reviewDate !== trim((string)($targetValue['review_business_date'] ?? ''))
            || $reviewDate !== trim((string)($intentEvidence['review_business_date'] ?? ''))
        ) {
            throw new InvalidArgumentException('人工审批冻结目标的镜像字段发生漂移');
        }

        if ($targetType === 'delta') {
            $contractDelta = $this->decimal($contract['expected_delta'] ?? null, '冻结目标增量');
            $intentDelta = $this->decimal($intent['expected_delta'] ?? null, '意图目标增量');
            $targetDelta = $this->decimal($targetValue['expected_delta'] ?? null, '目标镜像增量');
            $evidenceDelta = $this->decimal($intentEvidence['expected_delta'] ?? null, '证据镜像增量');
            if ((float)$contractDelta <= 0.0
                || $contractDelta !== $intentDelta
                || $contractDelta !== $targetDelta
                || $contractDelta !== $evidenceDelta
            ) {
                throw new InvalidArgumentException('意图目标增量与人工审批冻结契约不一致');
            }
        } else {
            $contractTarget = $this->decimal($contract['target_value'] ?? null, '冻结绝对目标');
            $persistedTarget = $this->decimal($targetValue['expected_target'] ?? null, '意图绝对目标');
            $evidenceTarget = $this->decimal($intentEvidence['target_value'] ?? null, '证据镜像绝对目标');
            if ((float)$contractTarget < 0.0
                || $contractTarget !== $persistedTarget
                || $contractTarget !== $evidenceTarget
            ) {
                throw new InvalidArgumentException('意图绝对目标与人工审批冻结契约不一致');
            }
        }

        return $contract;
    }

    /** @param array<string,mixed> $contract */
    private function approvalContractDigest(array $contract): string
    {
        unset($contract['content_digest']);
        return hash('sha256', $this->canonicalJson($contract));
    }

    /** @return array<string, mixed> */
    private function frozenTarget(array $intent, string $metricKey, array $input, array $approvalContract): array
    {
        $targetValue = $this->decodeJson($intent['target_value_json'] ?? null);
        $intentEvidence = $this->decodeJson($intent['evidence_json'] ?? null);
        $targetStatus = strtolower($this->firstString([
            $approvalContract['expected_delta_status'] ?? null,
            $targetValue['expected_delta_status'] ?? null,
            $targetValue['target_status'] ?? null,
            $intentEvidence['expected_delta_status'] ?? null,
            $intentEvidence['target_status'] ?? null,
        ]));
        if ($targetStatus !== 'manual_confirmed') {
            throw new InvalidArgumentException('效果复盘要求审批前人工冻结量化目标');
        }

        $targetType = strtolower($this->firstString([
            $approvalContract['target_type'] ?? null,
            $targetValue['target_type'] ?? null,
            $intentEvidence['target_type'] ?? null,
        ]));
        if (!in_array($targetType, ['absolute', 'delta'], true)) {
            throw new InvalidArgumentException('人工冻结目标必须明确 target_type=absolute 或 delta');
        }
        $expectedDirection = $this->normalizeDirection($this->firstString([
            $approvalContract['expected_direction'] ?? null,
            $targetValue['expected_direction'] ?? null,
            $targetValue['direction'] ?? null,
            $intentEvidence['expected_direction'] ?? null,
            $intentEvidence['direction'] ?? null,
        ]));
        if ($expectedDirection === null) {
            throw new InvalidArgumentException('人工冻结目标缺少 expected_direction');
        }

        $targetConfirmedBy = (int)($intent['approved_by'] ?? 0);
        $targetConfirmedAt = $this->requiredDateTime($intent['approved_at'] ?? null, '目标审批时间');
        if ($targetConfirmedBy <= 0) {
            throw new InvalidArgumentException('人工冻结目标缺少有效审批人');
        }

        $absoluteTarget = null;
        $expectedDelta = null;
        $normalizedTargetValue = $targetValue;
        $normalizedTargetValue['target_metric'] = $metricKey;
        $normalizedTargetValue['target_type'] = $targetType;
        $normalizedTargetValue['expected_direction'] = $expectedDirection;
        $normalizedTargetValue['expected_delta_status'] = 'manual_confirmed';
        if ($targetType === 'absolute') {
            $candidate = $this->firstNumeric([
                $approvalContract['target_value'] ?? null,
                $targetValue['expected_target'] ?? null,
                $targetValue['target_' . $metricKey] ?? null,
                $targetValue['target'] ?? null,
                $targetValue['value'] ?? null,
            ]);
            if ($candidate === null) {
                throw new InvalidArgumentException('人工冻结的绝对目标值缺失');
            }
            $absoluteTarget = $this->decimal($candidate, '人工冻结的绝对目标值');
            $normalizedTargetValue['expected_target'] = (float)$absoluteTarget;
        } else {
            $candidate = $this->firstNumeric([
                $approvalContract['expected_delta'] ?? null,
                $intent['expected_delta'] ?? null,
                $targetValue['expected_delta'] ?? null,
            ]);
            if ($candidate === null || (float)$candidate <= 0.0) {
                throw new InvalidArgumentException('人工冻结的目标增量必须是大于0的量化值');
            }
            $expectedDelta = $this->decimal($candidate, '人工冻结的目标增量');
        }

        $this->assertOptionalStringMatch($input, 'target_type', $targetType, '人工冻结目标类型');
        $this->assertOptionalStringMatch($input, 'expected_direction', $expectedDirection, '人工冻结目标方向');
        $this->assertOptionalDecimalMatch($input, 'target_value', $absoluteTarget, '人工冻结绝对目标');
        $this->assertOptionalDecimalMatch($input, 'expected_delta', $expectedDelta, '人工冻结目标增量');
        if (array_key_exists('target_confirmed_by', $input)
            && (!is_numeric($input['target_confirmed_by'])
                || (int)$input['target_confirmed_by'] !== $targetConfirmedBy)
        ) {
            throw new InvalidArgumentException('人工冻结目标审批人断言不匹配');
        }
        if (array_key_exists('target_confirmed_at', $input)
            && $this->requiredDateTime($input['target_confirmed_at'], '目标审批时间') !== $targetConfirmedAt
        ) {
            throw new InvalidArgumentException('人工冻结目标审批时间断言不匹配');
        }

        return [
            'target_type' => $targetType,
            'target_value' => $absoluteTarget,
            'expected_delta' => $expectedDelta,
            'expected_delta_status' => 'manual_confirmed',
            'expected_direction' => $expectedDirection,
            'target_confirmed_by' => $targetConfirmedBy,
            'target_confirmed_at' => $targetConfirmedAt,
            'normalized_target_value' => $normalizedTargetValue,
        ];
    }

    /** @return array{payload: array<string, mixed>, digest: string} */
    private function frozenMetricDefinition(
        array $intent,
        string $metricKey,
        array $input,
        array $approvalContract
    ): array
    {
        $targetValue = $this->decodeJson($intent['target_value_json'] ?? null);
        $intentEvidence = $this->decodeJson($intent['evidence_json'] ?? null);
        $definition = $approvalContract['metric_definition']
            ?? $targetValue['metric_definition']
            ?? $intentEvidence['metric_definition']
            ?? null;
        if ($definition === null || $definition === '' || $definition === []) {
            throw new InvalidArgumentException('批准意图缺少审批前冻结的指标定义');
        }
        $payload = [
            'metric_key' => $metricKey,
            'definition' => $this->normalizeDefinition($definition),
        ];
        $digest = hash('sha256', $this->canonicalJson($payload));
        $declaredDigest = strtolower($this->firstString([
            $approvalContract['metric_definition_digest'] ?? null,
            $targetValue['metric_definition_digest'] ?? null,
            $intentEvidence['metric_definition_digest'] ?? null,
        ]));
        if (!$this->isDigest($declaredDigest) || !hash_equals($digest, $declaredDigest)) {
            throw new InvalidArgumentException('批准意图的指标定义摘要缺失或不匹配');
        }
        if (array_key_exists('metric_definition_digest', $input)) {
            $assertedDigest = strtolower(trim((string)$input['metric_definition_digest']));
            if (!$this->isDigest($assertedDigest) || !hash_equals($digest, $assertedDigest)) {
                throw new InvalidArgumentException('效果复盘指标定义摘要断言不匹配');
            }
        }
        if (array_key_exists('metric_definition', $input)) {
            $assertedPayload = [
                'metric_key' => $metricKey,
                'definition' => $this->normalizeDefinition($input['metric_definition']),
            ];
            if (!hash_equals($digest, hash('sha256', $this->canonicalJson($assertedPayload)))) {
                throw new InvalidArgumentException('效果复盘指标定义与批准意图不一致');
            }
        }

        return ['payload' => $payload, 'digest' => $digest];
    }

    /** @return array<string, mixed> */
    private function verifiedSourceReadback(
        array $sourceEvidence,
        array $intent,
        array $task,
        int $tenantId,
        int $hotelId,
        string $platform,
        string $metricKey,
        string $baselineDate,
        string $reviewDate,
        string $reviewedAt,
        array $input
    ): array {
        $evidenceType = strtolower(trim((string)($sourceEvidence['evidence_type'] ?? '')));
        if (!in_array($evidenceType, self::SOURCE_READBACK_EVIDENCE_TYPES, true)) {
            throw new InvalidArgumentException('效果复盘必须引用来源回读证据');
        }
        $platformResponse = $this->decodeJson($sourceEvidence['platform_response_json'] ?? null);
        $context = array_merge(
            $platformResponse,
            $this->arrayValue($platformResponse['source_context'] ?? []),
            $this->arrayValue($platformResponse['truth_context'] ?? [])
        );
        if (strtolower(trim((string)($context['verification_authority'] ?? ''))) !== 'system_readback'
            || !$this->booleanTrue($context['database_written'] ?? false)
            || !$this->booleanTrue($context['readback_verified'] ?? false)
            || (int)($context['readback_count'] ?? 0) <= 0
            || strtolower(trim((string)($context['validation_status'] ?? ''))) !== 'verified'
            || strtolower(trim((string)($context['source_validation_status'] ?? ''))) !== 'source_verified'
        ) {
            throw new InvalidArgumentException('来源证据没有通过系统保存回读校验');
        }
        if ((int)($context['system_hotel_id'] ?? 0) !== $hotelId
            || (isset($context['tenant_id']) && (int)$context['tenant_id'] !== $tenantId)
            || strtolower(trim((string)($context['platform'] ?? ''))) !== $platform
        ) {
            throw new InvalidArgumentException('来源回读证据与租户、酒店或平台范围不一致');
        }
        if (strtolower(trim((string)($context['metric_key'] ?? ''))) !== $metricKey
            || trim((string)($context['baseline_date'] ?? '')) !== $baselineDate
            || trim((string)($context['review_date'] ?? '')) !== $reviewDate
        ) {
            throw new InvalidArgumentException('来源回读证据与日期或指标口径不一致');
        }
        $readbackAt = $this->requiredDateTime($context['readback_at'] ?? null, '来源回读时间');
        $executedAt = $this->requiredDateTime($task['executed_at'] ?? null, '任务执行时间');
        if (strtotime($readbackAt) <= strtotime($executedAt)
            || strtotime($reviewedAt) < strtotime($readbackAt)
        ) {
            throw new InvalidArgumentException('来源回读与任务执行、人工复盘时间顺序不正确');
        }
        $intentStart = trim((string)($intent['date_start'] ?? ''));
        $intentEnd = trim((string)($intent['date_end'] ?? $intentStart));
        if (trim((string)($context['date_start'] ?? '')) !== $intentStart
            || trim((string)($context['date_end'] ?? '')) !== $intentEnd
        ) {
            throw new InvalidArgumentException('来源回读证据与批准意图日期范围不一致');
        }

        $before = $this->decodeJson($sourceEvidence['before_json'] ?? null);
        $after = $this->decodeJson($sourceEvidence['after_json'] ?? null);
        if (!array_key_exists($metricKey, $before) || !is_numeric($before[$metricKey])
            || !array_key_exists($metricKey, $after) || !is_numeric($after[$metricKey])
        ) {
            throw new InvalidArgumentException('来源回读证据缺少同口径前后指标值');
        }
        $beforeValue = $this->decimal($before[$metricKey], '来源回读基准值');
        $afterValue = $this->decimal($after[$metricKey], '来源回读复盘值');
        $this->assertOptionalDecimalMatch($input, 'before_value', $beforeValue, '来源回读基准值');
        $this->assertOptionalDecimalMatch($input, 'after_value', $afterValue, '来源回读复盘值');

        $baselineRefs = $this->contextRefs($context, 'baseline');
        $followupRefs = $this->contextRefs($context, 'followup');
        if ($baselineRefs === [] || $followupRefs === []) {
            throw new InvalidArgumentException('来源回读证据缺少基准或复盘事实引用');
        }
        $this->assertOptionalRefsMatch($input, 'baseline_refs', $baselineRefs, '基准事实引用');
        $this->assertOptionalRefsMatch($input, 'followup_refs', $followupRefs, '复盘事实引用');

        return [
            'before_value' => $beforeValue,
            'after_value' => $afterValue,
            'baseline_refs' => $baselineRefs,
            'followup_refs' => $followupRefs,
            'source_context' => $context,
        ];
    }

    /** @return array<string, mixed> */
    private function scopedIntent(int $tenantId, int $hotelId, int $intentId, bool $lock): array
    {
        $query = Db::name('operation_execution_intents')
            ->where('id', $intentId)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->whereNull('deleted_at');
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        if (!is_array($row)) {
            throw new RuntimeException('执行意图不存在或不属于当前经营范围');
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private function scopedTask(
        int $tenantId,
        int $hotelId,
        int $intentId,
        int $taskId,
        bool $lock
    ): array {
        $query = Db::name('operation_execution_tasks')
            ->where('id', $taskId)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('intent_id', $intentId)
            ->whereNull('deleted_at');
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        if (!is_array($row)) {
            throw new RuntimeException('运营任务不存在或不属于当前经营范围');
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private function scopedSourceEvidence(int $tenantId, int $taskId, int $evidenceId, bool $lock): array
    {
        $query = Db::name('operation_execution_evidence')
            ->where('id', $evidenceId)
            ->where('tenant_id', $tenantId)
            ->where('task_id', $taskId)
            ->whereNull('deleted_at');
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        if (!is_array($row)) {
            throw new RuntimeException('来源回读证据不存在或不属于当前经营范围');
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private function normalizeAndVerifyRow(array $row): array
    {
        $normalized = $row;
        $normalized['tenant_id'] = (int)($row['tenant_id'] ?? 0);
        $normalized['hotel_id'] = (int)($row['hotel_id'] ?? 0);
        $normalized['intent_id'] = (int)($row['intent_id'] ?? 0);
        $normalized['task_id'] = (int)($row['task_id'] ?? 0);
        $normalized['source_readback_evidence_id'] = (int)($row['source_readback_evidence_id'] ?? 0);
        $normalized['target_confirmed_by'] = (int)($row['target_confirmed_by'] ?? 0);
        $normalized['reviewed_by'] = (int)($row['reviewed_by'] ?? 0);
        $normalized['before_value'] = $this->decimal($row['before_value'] ?? null, '效果复盘基准值');
        $normalized['after_value'] = $this->decimal($row['after_value'] ?? null, '效果复盘复盘值');
        $normalized['target_value'] = $row['target_value'] === null
            ? null
            : $this->decimal($row['target_value'], '效果复盘绝对目标');
        $normalized['expected_delta'] = $row['expected_delta'] === null
            ? null
            : $this->decimal($row['expected_delta'], '效果复盘目标增量');
        $normalized['metric_definition'] = $this->decodeJson($row['metric_definition_json'] ?? null);
        $normalized['approval_target_digest'] = strtolower(trim((string)($row['approval_target_digest'] ?? '')));
        $normalized['approval_target_digest_persisted'] = $this->isDigest($normalized['approval_target_digest']);
        $normalized['baseline_refs'] = $this->normalizeRefs($this->decodeJson($row['baseline_refs_json'] ?? null));
        $normalized['followup_refs'] = $this->normalizeRefs($this->decodeJson($row['followup_refs_json'] ?? null));
        $normalized['outcome'] = $this->decodeJson($row['outcome_json'] ?? null);
        $normalized['causality_claimed'] = (int)($row['causality_claimed'] ?? 1) === 1;

        $metricDefinitionDigest = strtolower(trim((string)($row['metric_definition_digest'] ?? '')));
        $expectedMetricDefinitionDigest = hash(
            'sha256',
            $this->canonicalJson($normalized['metric_definition'])
        );
        $baselineDate = (string)($row['baseline_business_date'] ?? '');
        $reviewDate = (string)($row['review_business_date'] ?? '');
        $targetType = strtolower(trim((string)($row['target_type'] ?? '')));
        $outcomeStatus = strtolower(trim((string)($row['outcome_status'] ?? '')));
        $resultStatus = strtolower(trim((string)($row['result_status'] ?? '')));
        $expectedResultStatus = match ($outcomeStatus) {
            'met' => 'success',
            'near' => 'near_success',
            'missed', 'adverse' => 'failed',
            default => '',
        };
        if (!$this->isDigest($metricDefinitionDigest)
            || !hash_equals($expectedMetricDefinitionDigest, $metricDefinitionDigest)
            || !$this->isDate($baselineDate)
            || !$this->isDate($reviewDate)
            || $reviewDate <= $baselineDate
            || $normalized['baseline_refs'] === []
            || $normalized['followup_refs'] === []
            || $normalized['source_readback_evidence_id'] <= 0
            || strtolower(trim((string)($row['expected_delta_status'] ?? ''))) !== 'manual_confirmed'
            || !in_array($targetType, ['absolute', 'delta'], true)
            || ($targetType === 'absolute' && $normalized['target_value'] === null)
            || ($targetType === 'delta'
                && ($normalized['expected_delta'] === null || (float)$normalized['expected_delta'] <= 0.0))
            || !in_array($outcomeStatus, self::OUTCOME_STATUSES, true)
            || strtolower(trim((string)($normalized['outcome']['status'] ?? ''))) !== $outcomeStatus
            || ($normalized['outcome']['source_verified'] ?? false) !== true
            || ($normalized['outcome']['outcome_verified'] ?? false) !== true
            || ($normalized['approval_target_digest_persisted']
                && !hash_equals(
                    $normalized['approval_target_digest'],
                    strtolower(trim((string)($normalized['outcome']['approval_target_digest'] ?? '')))
                ))
            || $expectedResultStatus === ''
            || $resultStatus !== $expectedResultStatus
        ) {
            throw new RuntimeException('效果复盘业务口径回读校验失败');
        }

        $digestPayload = [
            'tenant_id' => $normalized['tenant_id'],
            'hotel_id' => $normalized['hotel_id'],
            'intent_id' => $normalized['intent_id'],
            'task_id' => $normalized['task_id'],
            'platform' => (string)$row['platform'],
            'baseline_business_date' => (string)$row['baseline_business_date'],
            'review_business_date' => (string)$row['review_business_date'],
            'metric_key' => (string)$row['metric_key'],
            'metric_definition' => $normalized['metric_definition'],
            'metric_definition_digest' => (string)$row['metric_definition_digest'],
            'before_value' => $normalized['before_value'],
            'after_value' => $normalized['after_value'],
            'expected_direction' => (string)$row['expected_direction'],
            'target_type' => (string)$row['target_type'],
            'target_value' => $normalized['target_value'],
            'expected_delta' => $normalized['expected_delta'],
            'expected_delta_status' => (string)$row['expected_delta_status'],
            'target_confirmed_by' => $normalized['target_confirmed_by'],
            'target_confirmed_at' => (string)$row['target_confirmed_at'],
            'baseline_refs' => $normalized['baseline_refs'],
            'followup_refs' => $normalized['followup_refs'],
            'source_readback_evidence_id' => $normalized['source_readback_evidence_id'],
            'outcome_status' => (string)$row['outcome_status'],
            'outcome' => $normalized['outcome'],
            'result_status' => (string)$row['result_status'],
            'result_summary' => (string)$row['result_summary'],
            'causality_claimed' => $normalized['causality_claimed'],
            'reviewed_by' => $normalized['reviewed_by'],
            'reviewed_at' => (string)$row['reviewed_at'],
        ];
        if ($normalized['approval_target_digest_persisted']) {
            $digestPayload['approval_target_digest'] = $normalized['approval_target_digest'];
        }
        $expectedDigest = hash('sha256', $this->canonicalJson($digestPayload));
        $actualDigest = strtolower(trim((string)($row['content_digest'] ?? '')));
        if (!$this->isDigest($actualDigest)
            || !hash_equals($expectedDigest, $actualDigest)
            || $normalized['causality_claimed'] !== false
        ) {
            throw new RuntimeException('效果复盘严格回读校验失败');
        }
        $normalized['readback_verified'] = true;
        unset(
            $normalized['metric_definition_json'],
            $normalized['baseline_refs_json'],
            $normalized['followup_refs_json'],
            $normalized['outcome_json']
        );

        return $normalized;
    }

    private function intentBaselineDate(array $intent): ?string
    {
        $evidence = $this->decodeJson($intent['evidence_json'] ?? null);
        $approvalTarget = $this->arrayValue($evidence['approval_target'] ?? []);
        foreach ([$approvalTarget['baseline_business_date'] ?? null, $intent['date_end'] ?? null, $intent['date_start'] ?? null] as $value) {
            $date = trim((string)$value);
            if ($date !== '' && $this->isDate($date)) {
                return $date;
            }
        }
        return null;
    }

    private function assertReviewDateOrder(string $baselineDate, string $reviewDate): void
    {
        $expectedReviewDate = (new DateTimeImmutable($baselineDate))->modify('+1 day')->format('Y-m-d');
        if ($reviewDate !== $expectedReviewDate) {
            throw new InvalidArgumentException('复盘经营日期必须是基准经营日期的下一日：' . $expectedReviewDate);
        }
    }

    /** @return list<string> */
    private function contextRefs(array $context, string $kind): array
    {
        $keys = $kind === 'baseline'
            ? ['baseline_source_refs', 'reconciled_baseline_source_refs', 'baseline_refs', 'baseline_source_ref']
            : ['followup_source_refs', 'review_source_refs', 'followup_refs', 'followup_source_ref'];
        $refs = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $context)) {
                continue;
            }
            $refs = array_merge($refs, $this->normalizeRefs($context[$key]));
        }
        $refs = array_values(array_unique($refs));
        sort($refs, SORT_STRING);
        return $refs;
    }

    /** @return list<string> */
    private function normalizeRefs(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_string($value)) {
            $value = [$value];
        }
        if (!is_array($value)) {
            throw new InvalidArgumentException('效果事实引用格式无效');
        }
        if (!array_is_list($value) && isset($value['type'])) {
            $value = [$value];
        }
        $refs = [];
        foreach ($value as $entry) {
            if (is_array($entry)) {
                $type = strtolower(trim((string)($entry['type'] ?? '')));
                $id = trim((string)($entry['id'] ?? ''));
                $entry = $type !== '' && $id !== '' ? $type . '#' . $id : '';
            }
            if (!is_string($entry) || trim($entry) === '') {
                throw new InvalidArgumentException('效果事实引用格式无效');
            }
            foreach ($this->splitReference(trim($entry)) as $ref) {
                $refs[] = $ref;
            }
        }
        $refs = array_values(array_unique($refs));
        sort($refs, SORT_STRING);
        return $refs;
    }

    /** @return list<string> */
    private function splitReference(string $reference): array
    {
        if (mb_strlen($reference) > 500 || !str_contains($reference, '#')) {
            throw new InvalidArgumentException('效果事实引用必须是 type#id 格式');
        }
        [$type, $identityList] = explode('#', $reference, 2);
        $type = strtolower(trim($type));
        if (preg_match('/^[a-z0-9_.-]{1,80}$/D', $type) !== 1) {
            throw new InvalidArgumentException('效果事实引用类型无效');
        }
        $refs = [];
        foreach (explode(',', $identityList) as $identity) {
            $identity = trim($identity);
            if (preg_match('/^[A-Za-z0-9_.:-]{1,100}$/D', $identity) !== 1) {
                throw new InvalidArgumentException('效果事实引用标识无效');
            }
            $refs[] = $type . '#' . $identity;
        }
        return $refs;
    }

    private function assertOptionalRefsMatch(array $input, string $key, array $expected, string $label): void
    {
        if (!array_key_exists($key, $input)) {
            return;
        }
        if ($this->normalizeRefs($input[$key]) !== $expected) {
            throw new InvalidArgumentException($label . '断言与来源回读不一致');
        }
    }

    private function assertOptionalStringMatch(array $input, string $key, string $expected, string $label): void
    {
        if (array_key_exists($key, $input)
            && (!is_scalar($input[$key])
                || strtolower(trim((string)$input[$key])) !== strtolower($expected))
        ) {
            throw new InvalidArgumentException($label . '断言不匹配');
        }
    }

    private function assertOptionalDecimalMatch(
        array $input,
        string $key,
        ?string $expected,
        string $label
    ): void {
        if (!array_key_exists($key, $input)) {
            return;
        }
        if ($expected === null || $this->decimal($input[$key], $label) !== $expected) {
            throw new InvalidArgumentException($label . '断言不匹配');
        }
    }

    private function assertPositiveIdentity(
        int $tenantId,
        int $hotelId,
        int $intentId,
        int $taskId,
        int $userId
    ): void {
        if ($tenantId <= 0 || $hotelId <= 0 || $intentId <= 0 || $taskId <= 0 || $userId <= 0) {
            throw new InvalidArgumentException('效果复盘缺少有效租户、酒店、意图、任务或用户身份');
        }
    }

    private function assertInputIdentity(
        array $input,
        int $tenantId,
        int $hotelId,
        int $intentId,
        int $taskId
    ): void {
        foreach ([
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'intent_id' => $intentId,
            'task_id' => $taskId,
        ] as $field => $expected) {
            if (array_key_exists($field, $input)
                && (!is_numeric($input[$field]) || (int)$input[$field] !== $expected)
            ) {
                throw new InvalidArgumentException('效果复盘身份断言不匹配：' . $field);
            }
        }
    }

    private function assertTablesReady(): void
    {
        foreach (self::REQUIRED_TABLES as $table) {
            try {
                Db::query('SELECT 1 FROM `' . $table . '` LIMIT 1');
            } catch (Throwable $e) {
                if ($this->isMissingTableException($e, $table)) {
                    throw new RuntimeException('效果复盘功能尚未启用：缺少数据库表 ' . $table, 503, $e);
                }
                throw new RuntimeException('效果复盘数据库表探测失败：' . $table, 503, $e);
            }
        }
    }

    private function isMissingTableException(Throwable $exception, string $table): bool
    {
        $table = strtolower($table);
        for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
            $code = strtoupper(trim((string)$current->getCode()));
            $message = strtolower($current->getMessage());
            if ($code === '42S02'
                || str_contains($message, "table '{$table}' doesn't exist")
                || str_contains($message, 'no such table: ' . $table)
                || str_contains($message, 'relation "' . $table . '" does not exist')
                || preg_match(
                    '/table\s+[' . "'`\"" . '](?:[a-z0-9_]+\.)?'
                        . preg_quote($table, '/')
                        . '[' . "'`\"" . ']\s+(?:doesn.t|does not)\s+exist/i',
                    $message
                ) === 1
            ) {
                return true;
            }
        }
        return false;
    }

    private function requiredToken(mixed $value, string $label, int $maxLength): string
    {
        $token = is_scalar($value) ? strtolower(trim((string)$value)) : '';
        if ($token === ''
            || mb_strlen($token) > $maxLength
            || preg_match('/^[a-z0-9_.:-]+$/D', $token) !== 1
        ) {
            throw new InvalidArgumentException($label . '格式无效');
        }
        return $token;
    }

    private function requiredDate(mixed $value, string $label): string
    {
        $date = is_scalar($value) ? trim((string)$value) : '';
        if (!$this->isDate($date)) {
            throw new InvalidArgumentException($label . '格式无效');
        }
        return $date;
    }

    private function isDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();
        return $parsed !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $parsed->format('Y-m-d') === $date;
    }

    private function requiredDateTime(mixed $value, string $label): string
    {
        $dateTime = is_scalar($value) ? trim((string)$value) : '';
        foreach (['!Y-m-d H:i:s', '!Y-m-d\\TH:i:s', '!Y-m-d\\TH:i'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $dateTime);
            $errors = DateTimeImmutable::getLastErrors();
            if ($parsed !== false
                && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            ) {
                return $parsed->format('Y-m-d H:i:s');
            }
        }
        throw new InvalidArgumentException($label . '格式无效');
    }

    private function decimal(mixed $value, string $label): string
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException($label . '必须是数值');
        }
        $number = (float)$value;
        if (is_nan($number) || is_infinite($number) || abs($number) >= 100000000000000.0) {
            throw new InvalidArgumentException($label . '超出允许范围');
        }
        return number_format($number, 6, '.', '');
    }

    private function optionalDecimal(mixed $value, string $label): ?string
    {
        return $value === null ? null : $this->decimal($value, $label);
    }

    private function normalizeDirection(string $direction): ?string
    {
        return match (strtolower(trim($direction))) {
            'increase', 'up', 'higher', 'higher_is_better', 'positive' => 'increase',
            'decrease', 'down', 'lower', 'lower_is_better', 'negative' => 'decrease',
            default => null,
        };
    }

    private function strictBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (!is_scalar($value)) {
            return null;
        }
        if ($value === 1 || $value === '1' || strtolower(trim((string)$value)) === 'true') {
            return true;
        }
        if ($value === 0 || $value === '0' || strtolower(trim((string)$value)) === 'false') {
            return false;
        }
        return null;
    }

    private function booleanTrue(mixed $value): bool
    {
        return $this->strictBoolean($value) === true;
    }

    private function firstString(array $values): string
    {
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string)$value) !== '') {
                return trim((string)$value);
            }
        }
        return '';
    }

    private function firstNumeric(array $values): int|float|string|null
    {
        foreach ($values as $value) {
            if (is_numeric($value)) {
                return $value;
            }
        }
        return null;
    }

    private function normalizeDefinition(mixed $definition): mixed
    {
        if (is_string($definition)) {
            $definition = trim($definition);
            if ($definition === '' || mb_strlen($definition) > 4000) {
                throw new InvalidArgumentException('指标定义不能为空且不能超过4000字');
            }
            return $definition;
        }
        if (!is_array($definition) || $definition === []) {
            throw new InvalidArgumentException('指标定义必须是非空文本或对象');
        }
        $normalized = $this->normalizeDefinitionNode($definition);
        if (strlen($this->canonicalJson($normalized)) > 16000) {
            throw new InvalidArgumentException('指标定义内容过大');
        }
        return $normalized;
    }

    private function normalizeDefinitionNode(mixed $value): mixed
    {
        if (is_array($value)) {
            if ($value === []) {
                return [];
            }
            if (array_is_list($value)) {
                return array_map(fn(mixed $item): mixed => $this->normalizeDefinitionNode($item), $value);
            }
            ksort($value, SORT_STRING);
            foreach ($value as $key => $item) {
                $value[$key] = $this->normalizeDefinitionNode($item);
            }
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return $this->decimal($value, '指标定义数值');
        }
        if (is_string($value)) {
            return trim($value);
        }
        if (is_bool($value) || $value === null) {
            return $value;
        }
        throw new InvalidArgumentException('指标定义包含不支持的数据类型');
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            if (is_object($value) || is_resource($value)) {
                throw new InvalidArgumentException('效果复盘内容包含不支持的数据类型');
            }
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

    /** @return array<string, mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('效果复盘依赖的JSON记录无法解析');
        }
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string, mixed> */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function isDigest(string $digest): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $digest) === 1;
    }
}
