<?php
declare(strict_types=1);

namespace app\service\operation;

use app\service\OperatingNetworkService;

trait OperationEffectReadbackConcern
{
    /**
     * Persist the observed effect separately from action/execution evidence.
     * The effect service re-verifies every identity, date, metric, target and
     * source-readback assertion before it appends the immutable review row.
     *
     * @param array<string,mixed> $intent
     * @param array<string,mixed> $task
     * @param array<int,array<string,mixed>> $evidenceRows
     */
    private function createOperationEffectReview(
        array $intent,
        array $task,
        array $evidenceRows,
        string $resultStatus,
        string $resultSummary,
        int $reviewerId,
        string $reviewedAt
    ): void {
        if ($reviewerId <= 0) {
            throw new \InvalidArgumentException('authenticated human reviewer is required for effect review');
        }
        if (!$this->tableExists(OperationEffectReviewService::TABLE)) {
            throw new \RuntimeException('operation_effect_reviews table does not exist, run database migration first');
        }

        $sourceEvidenceId = 0;
        $sourceContext = [];
        foreach ($evidenceRows as $row) {
            if (!is_array($row)
                || strtolower(trim((string)($row['evidence_type'] ?? ''))) !== 'source_verified_metric_readback'
            ) {
                continue;
            }
            $candidateId = (int)($row['id'] ?? 0);
            $candidateContext = $this->decodeJson((string)($row['platform_response_json'] ?? ''));
            if ($candidateId > 0 && $candidateContext !== []) {
                $sourceEvidenceId = $candidateId;
                $sourceContext = $candidateContext;
                break;
            }
        }
        if ($sourceEvidenceId <= 0) {
            throw new \InvalidArgumentException('source-verified metric readback evidence is required for effect review');
        }

        $tenantId = (int)($task['tenant_id'] ?? $intent['tenant_id'] ?? 0);
        $hotelId = (int)($task['hotel_id'] ?? $intent['hotel_id'] ?? 0);
        $intentId = (int)($intent['id'] ?? 0);
        $taskId = (int)($task['id'] ?? 0);
        $platform = strtolower(trim((string)($intent['platform'] ?? '')));
        $metricKey = strtolower(trim((string)($intent['expected_metric'] ?? '')));
        $baselineDate = $this->intentEffectBaselineDate($intent);
        $reviewDate = substr(trim((string)($sourceContext['review_date'] ?? '')), 0, 10);

        (new OperationEffectReviewService($this->executionOutcomeService))->create(
            $tenantId,
            $hotelId,
            $intentId,
            $taskId,
            [
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'intent_id' => $intentId,
                'task_id' => $taskId,
                'platform' => $platform,
                'metric_key' => $metricKey,
                'baseline_business_date' => $baselineDate,
                'review_business_date' => $reviewDate,
                'source_readback_evidence_id' => $sourceEvidenceId,
                'result_status' => $resultStatus,
                'result_summary' => $resultSummary,
                'reviewed_at' => $reviewedAt,
                'causality_claimed' => false,
            ],
            $reviewerId
        );
    }

    /** @param array<string,mixed> $intent */
    private function intentDeclaresEffectContract(array $intent): bool
    {
        $target = is_array($intent['target_value'] ?? null)
            ? $intent['target_value']
            : $this->decodeJson((string)($intent['target_value_json'] ?? ''));
        $evidence = is_array($intent['evidence'] ?? null)
            ? $intent['evidence']
            : $this->decodeJson((string)($intent['evidence_json'] ?? ''));
        $approvalTarget = is_array($evidence['approval_target'] ?? null)
            ? $evidence['approval_target']
            : [];
        $approvalTargetDigest = trim((string)(
            $evidence['approval_target_digest']
            ?? $target['approval_target_digest']
            ?? ''
        ));

        return $approvalTarget !== [] || $approvalTargetDigest !== '';
    }

    /** @param array<string,mixed> $intent */
    private function intentEffectBaselineDate(array $intent): string
    {
        $evidence = is_array($intent['evidence'] ?? null)
            ? $intent['evidence']
            : $this->decodeJson((string)($intent['evidence_json'] ?? ''));
        $approvalTarget = is_array($evidence['approval_target'] ?? null)
            ? $evidence['approval_target']
            : [];
        foreach ([$approvalTarget['baseline_business_date'] ?? null, $intent['date_end'] ?? null, $intent['date_start'] ?? null] as $value) {
            $date = substr(trim((string)$value), 0, 10);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1) {
                return $date;
            }
        }
        return '';
    }

    /**
     * A controlled replication still needs its own target-hotel source
     * readback. The source hotel's successful SOP and the target fact used to
     * create the draft cannot stand in for the post-execution observation.
     *
     * @param array<string,mixed> $task
     * @param array<string,mixed> $intent
     * @return array<string,mixed>|null
     */
    private function buildOperatingNetworkSourceVerifiedReadbackPayload(
        array $task,
        array $intent,
        string $intentPlatform,
        string $readbackPlatform,
        string $expectedMetric,
        string $objectType,
        string $dateStart,
        string $dateEnd,
        int $executedTimestamp
    ): ?array {
        (new OperatingNetworkService())->assertReplicationExecutionIntentCurrent($intent);

        $taskId = (int)($task['id'] ?? 0);
        $hotelId = (int)($intent['hotel_id'] ?? 0);
        $replicationId = (int)($intent['source_record_id'] ?? 0);
        $evidence = is_array($intent['evidence'] ?? null)
            ? $intent['evidence']
            : $this->decodeJson((string)($intent['evidence_json'] ?? ''));
        $targetValue = is_array($intent['target_value'] ?? null)
            ? $intent['target_value']
            : $this->decodeJson((string)($intent['target_value_json'] ?? ''));
        $approvalTarget = is_array($evidence['approval_target'] ?? null)
            ? $evidence['approval_target']
            : [];
        $lineage = is_array($evidence['operating_network_replication'] ?? null)
            ? $evidence['operating_network_replication']
            : [];
        $baselineDate = substr(trim((string)($approvalTarget['baseline_business_date'] ?? '')), 0, 10);
        $reviewDate = substr(trim((string)($approvalTarget['review_business_date'] ?? '')), 0, 10);
        $reviewTimestamp = $this->savedOtaDiagnosisReviewTimestamp($intent);
        $approvalDigest = strtolower(trim((string)($approvalTarget['content_digest'] ?? '')));
        $evidenceApprovalDigest = strtolower(trim((string)($evidence['approval_target_digest'] ?? '')));
        $targetApprovalDigest = strtolower(trim((string)($targetValue['approval_target_digest'] ?? '')));
        $declaredTargetFactRefs = array_values(array_filter(array_map(
            static fn(mixed $ref): string => is_scalar($ref) ? trim((string)$ref) : '',
            (array)($lineage['target_fact_refs'] ?? [])
        ), static fn(string $ref): bool => preg_match('/^online_daily_data#[1-9][0-9]*$/D', $ref) === 1));
        $declaredTargetFactIds = array_values(array_unique(array_map(
            static fn(string $ref): int => (int)substr($ref, strlen('online_daily_data#')),
            $declaredTargetFactRefs
        )));
        sort($declaredTargetFactIds, SORT_NUMERIC);

        if ($taskId <= 0
            || $hotelId <= 0
            || $replicationId <= 0
            || $dateStart > $dateEnd
            || $baselineDate !== $dateEnd
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $reviewDate) !== 1
            || $reviewDate <= $baselineDate
            || $reviewTimestamp === null
            || date('Y-m-d', $reviewTimestamp) !== $reviewDate
            || $reviewTimestamp <= $executedTimestamp
            || time() < $reviewTimestamp
            || $approvalDigest === ''
            || preg_match('/^[a-f0-9]{64}$/D', $approvalDigest) !== 1
            || !hash_equals($approvalDigest, $this->savedOtaDiagnosisApprovalTargetDigest($approvalTarget))
            || !hash_equals($approvalDigest, $evidenceApprovalDigest)
            || !hash_equals($approvalDigest, $targetApprovalDigest)
            || strtolower(trim((string)($approvalTarget['source_module'] ?? ''))) !== OperatingNetworkService::EXECUTION_SOURCE_MODULE
            || (int)($approvalTarget['source_record_id'] ?? 0) !== $replicationId
            || (int)($approvalTarget['hotel_id'] ?? 0) !== $hotelId
            || strtolower(trim((string)($approvalTarget['platform'] ?? ''))) !== $intentPlatform
            || strtolower(trim((string)($approvalTarget['expected_metric'] ?? ''))) !== $expectedMetric
            || $declaredTargetFactRefs === []
        ) {
            return null;
        }

        return $this->buildOperatingNetworkSourceVerifiedReadbackPayloadFromRows(
            $task,
            $intent,
            [
                'intent_platform' => $intentPlatform,
                'readback_platform' => $readbackPlatform,
                'expected_metric' => $expectedMetric,
                'object_type' => $objectType,
                'date_start' => $dateStart,
                'date_end' => $dateEnd,
                'baseline_date' => $baselineDate,
                'review_date' => $reviewDate,
                'review_timestamp' => $reviewTimestamp,
                'executed_timestamp' => $executedTimestamp,
                'replication_id' => $replicationId,
                'replication_content_digest' => strtolower(trim((string)($lineage['replication_content_digest'] ?? ''))),
                'execution_contract_digest' => strtolower(trim((string)($lineage['execution_contract_digest'] ?? ''))),
                'declared_target_fact_refs' => $declaredTargetFactRefs,
                'declared_target_fact_ids' => $declaredTargetFactIds,
            ],
            $this->onlineRows([$hotelId], $baselineDate, $baselineDate),
            $this->onlineRows([$hotelId], $reviewDate, $reviewDate)
        );
    }

    /**
     * @param array<string,mixed> $task
     * @param array<string,mixed> $intent
     * @param array<string,mixed> $scope
     * @param array<int,array<string,mixed>> $baselineSourceRows
     * @param array<int,array<string,mixed>> $reviewSourceRows
     * @return array<string,mixed>|null
     */
    private function buildOperatingNetworkSourceVerifiedReadbackPayloadFromRows(
        array $task,
        array $intent,
        array $scope,
        array $baselineSourceRows,
        array $reviewSourceRows
    ): ?array {
        $taskId = (int)($task['id'] ?? 0);
        $hotelId = (int)($intent['hotel_id'] ?? 0);
        $tenantId = (int)($intent['tenant_id'] ?? 0);
        $intentPlatform = strtolower(trim((string)($scope['intent_platform'] ?? '')));
        $readbackPlatform = strtolower(trim((string)($scope['readback_platform'] ?? '')));
        $expectedMetric = strtolower(trim((string)($scope['expected_metric'] ?? '')));
        $objectType = strtolower(trim((string)($scope['object_type'] ?? '')));
        $dateStart = substr(trim((string)($scope['date_start'] ?? '')), 0, 10);
        $dateEnd = substr(trim((string)($scope['date_end'] ?? '')), 0, 10);
        $baselineDate = substr(trim((string)($scope['baseline_date'] ?? '')), 0, 10);
        $reviewDate = substr(trim((string)($scope['review_date'] ?? '')), 0, 10);
        $reviewTimestamp = (int)($scope['review_timestamp'] ?? 0);
        $executedTimestamp = (int)($scope['executed_timestamp'] ?? 0);
        if ($taskId <= 0
            || $hotelId <= 0
            || !in_array($readbackPlatform, ['ctrip', 'meituan', 'ota'], true)
            || $expectedMetric === ''
            || $objectType === ''
            || $baselineDate === ''
            || $reviewDate === ''
            || $reviewTimestamp <= $executedTimestamp
        ) {
            return null;
        }

        $sameScopeRows = static function (
            array $rows,
            int $expectedTenantId,
            int $expectedHotelId,
            string $expectedDate
        ): array {
            return array_values(array_filter($rows, static fn(array $row): bool =>
                ($expectedTenantId <= 0 || (int)($row['tenant_id'] ?? 0) === $expectedTenantId)
                && (int)($row['system_hotel_id'] ?? 0) === $expectedHotelId
                && substr(trim((string)($row['data_date'] ?? '')), 0, 10) === $expectedDate
            ));
        };
        $baselineRows = $this->canonicalExecutionReadbackRows(
            $this->trustedExecutionReadbackRows(
                $sameScopeRows($baselineSourceRows, $tenantId, $hotelId, $baselineDate),
                $readbackPlatform
            ),
            $expectedMetric
        );
        $reviewRows = $this->canonicalExecutionReadbackRows(
            $this->trustedExecutionReadbackRows(
                $sameScopeRows($reviewSourceRows, $tenantId, $hotelId, $reviewDate),
                $readbackPlatform,
                $executedTimestamp
            ),
            $expectedMetric
        );
        if (!$this->trustedExecutionReadbackPlatformCoverage($baselineRows, $readbackPlatform)
            || !$this->trustedExecutionReadbackPlatformCoverage($reviewRows, $readbackPlatform)
        ) {
            return null;
        }

        $baselineIds = $this->executionReadbackRowIds($baselineRows);
        $reviewIds = $this->executionReadbackRowIds($reviewRows);
        $declaredTargetFactIds = array_values(array_unique(array_filter(array_map(
            'intval',
            (array)($scope['declared_target_fact_ids'] ?? [])
        ), static fn(int $id): bool => $id > 0)));
        sort($declaredTargetFactIds, SORT_NUMERIC);
        $beforeValue = $this->executionReadbackMetricValue(
            $expectedMetric,
            $baselineRows,
            $hotelId,
            $baselineDate
        );
        $afterValue = $this->executionReadbackMetricValue(
            $expectedMetric,
            $reviewRows,
            $hotelId,
            $reviewDate
        );
        if ($baselineIds === []
            || $reviewIds === []
            || $declaredTargetFactIds === []
            || array_diff($baselineIds, $declaredTargetFactIds) !== []
            || $beforeValue === null
            || $afterValue === null
        ) {
            return null;
        }

        $readbackTimestamp = 0;
        foreach ($reviewRows as $row) {
            $readbackTimestamp = max($readbackTimestamp, $this->executionReadbackRowTimestamp($row));
        }
        if ($readbackTimestamp < $reviewTimestamp) {
            return null;
        }

        $sourceIds = array_values(array_unique(array_merge($baselineIds, $reviewIds)));
        sort($sourceIds, SORT_NUMERIC);
        $currentValue = is_array($intent['current_value'] ?? null)
            ? $intent['current_value']
            : $this->decodeJson((string)($intent['current_value_json'] ?? ''));
        $intentSnapshotValue = $this->executionIntentMetricSnapshotValue($expectedMetric, $currentValue);
        $baselineReconciliationStatus = $intentSnapshotValue === null
            ? 'intent_snapshot_missing'
            : (abs($intentSnapshotValue - $beforeValue) <= 0.0001
                ? 'matched_intent_snapshot'
                : 'source_readback_supersedes_intent_snapshot');

        return [
            'task_id' => $taskId,
            'evidence_type' => 'source_verified_metric_readback',
            'before' => [$expectedMetric => $beforeValue],
            'after' => [$expectedMetric => $afterValue],
            'attachment_path' => '',
            'platform_response' => [
                'verification_authority' => 'system_readback',
                'source' => 'online_daily_data',
                'source_ref' => 'online_daily_data#' . implode(',', $sourceIds),
                'baseline_source_ref' => 'online_daily_data#' . implode(',', $baselineIds),
                'followup_source_ref' => 'online_daily_data#' . implode(',', $reviewIds),
                'system_hotel_id' => $hotelId,
                'platform' => $intentPlatform,
                'object_type' => $objectType,
                'date_start' => $dateStart,
                'date_end' => $dateEnd,
                'baseline_date' => $baselineDate,
                'review_date' => $reviewDate,
                'scheduled_review_at' => date('Y-m-d H:i:s', $reviewTimestamp),
                'metric_key' => $expectedMetric,
                'database_written' => true,
                'readback_verified' => true,
                'readback_count' => count($reviewRows),
                'readback_at' => date('Y-m-d H:i:s', $readbackTimestamp),
                'validation_status' => 'verified',
                'source_validation_status' => 'source_verified',
                'failure_reason' => '',
                'source_module' => OperatingNetworkService::EXECUTION_SOURCE_MODULE,
                'replication_id' => (int)($scope['replication_id'] ?? 0),
                'replication_content_digest' => (string)($scope['replication_content_digest'] ?? ''),
                'execution_contract_digest' => (string)($scope['execution_contract_digest'] ?? ''),
                'declared_target_fact_refs' => array_values((array)($scope['declared_target_fact_refs'] ?? [])),
                'baseline_reconciliation_status' => $baselineReconciliationStatus,
                'intent_snapshot_value' => $intentSnapshotValue,
                'source_readback_value' => $beforeValue,
                'causality_claimed' => false,
                'effect_evidence_status' => 'observed_not_attributed',
                'measurement_policy' => 'controlled_replication_same_hotel_platform_metric_baseline_to_approved_review',
            ],
            'remark' => 'system-generated same-scope target-hotel readback for controlled replication; observation only',
            'created_by' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }
}
