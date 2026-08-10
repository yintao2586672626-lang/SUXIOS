<?php
declare(strict_types=1);

namespace app\service\operation;

use app\service\CanonicalOtaInvestigationActionService;

final class ExecutionOutcomeService
{
    /**
     * Review/readback evidence can be newer than the financial evidence used for ROI.
     * Keep the newest row for display, but calculate ROI from the newest row that
     * contains both before and after revenue facts.
     */
    public function latestExecutionRoiEvidence(array $taskEvidence): array
    {
        foreach ($taskEvidence as $evidence) {
            $before = $this->arrayValue($evidence['before'] ?? []);
            $after = $this->arrayValue($evidence['after'] ?? []);
            $beforeRevenue = $this->firstNumericMetric($before, ['revenue', 'avg_revenue', 'amount', 'income']);
            $afterRevenue = $this->firstNumericMetric($after, ['revenue', 'avg_revenue', 'amount', 'income']);
            if ($beforeRevenue !== null && $afterRevenue !== null) {
                return $evidence;
            }
        }

        return $taskEvidence[0] ?? [];
    }

    public function buildExecutionEvidenceTruth(array $intent, array $task, array $evidenceRows): array
    {
        $assessments = [];
        $operatorAttestedCount = 0;
        $sourceVerifiedCount = 0;
        $failureReasons = [];

        foreach ($evidenceRows as $evidence) {
            if (!is_array($evidence)) {
                continue;
            }
            $assessment = $this->assessExecutionEvidenceTruth($intent, $task, $evidence);
            $assessments[] = $assessment;
            if (($assessment['operator_attested'] ?? false) === true) {
                $operatorAttestedCount++;
            }
            if (($assessment['source_verified'] ?? false) === true) {
                $sourceVerifiedCount++;
            }
            foreach ((array)($assessment['failure_reasons'] ?? []) as $reason) {
                if (is_string($reason) && $reason !== '') {
                    $failureReasons[] = $reason;
                }
            }
        }

        $sourceVerified = $sourceVerifiedCount > 0;
        $operatorAttested = $operatorAttestedCount > 0;
        $status = $sourceVerified ? 'verified' : ($operatorAttested ? 'partial' : 'unverified');
        $failureReason = null;
        if (!$sourceVerified) {
            $failureReason = $operatorAttested
                ? 'operator_attested_only'
                : ($failureReasons[0] ?? ($evidenceRows === [] ? 'execution_evidence_missing' : 'execution_evidence_unverified'));
        }

        return [
            'status' => $status,
            'evidence_count' => count($evidenceRows),
            'operator_attested' => $operatorAttested,
            'operator_attested_count' => $operatorAttestedCount,
            'source_verified' => $sourceVerified,
            'source_verified_count' => $sourceVerifiedCount,
            'failure_reason' => $failureReason,
            'failure_reasons' => array_values(array_unique($failureReasons)),
            'assessments' => $assessments,
        ];
    }

    public function assessExecutionEvidenceTruth(array $intent, array $task, array $evidence): array
    {
        $platformResponse = $this->arrayValue($evidence['platform_response'] ?? []);
        $context = array_merge(
            $platformResponse,
            $this->arrayValue($platformResponse['source_context'] ?? []),
            $this->arrayValue($platformResponse['truth_context'] ?? [])
        );
        $before = $this->arrayValue($evidence['before'] ?? []);
        $after = $this->arrayValue($evidence['after'] ?? []);
        $evidenceType = strtolower(trim((string)($evidence['evidence_type'] ?? '')));
        $createdBy = (int)($evidence['created_by'] ?? 0);
        $attachmentPath = trim((string)($evidence['attachment_path'] ?? ''));
        $remark = trim((string)($evidence['remark'] ?? ''));
        $operatorSignal = $this->executionReadbackFlagIsTrue($context['operator_attested'] ?? false)
            || in_array(strtolower(trim((string)($context['verification_status'] ?? ''))), ['operator_attested'], true)
            || in_array(strtolower(trim((string)($context['mode'] ?? ''))), ['manual', 'operator_attested'], true)
            || str_contains($evidenceType, 'manual')
            || str_contains($evidenceType, 'operator')
            || str_contains($evidenceType, 'screenshot')
            || $attachmentPath !== ''
            || $remark !== '';
        $operatorAttested = $createdBy > 0 && $operatorSignal;

        if ($evidenceType === 'canonical_analysis_completion') {
            return $this->assessCanonicalInvestigationEvidence(
                $intent,
                $task,
                $evidence,
                $platformResponse,
                $before,
                $after
            );
        }

        $checks = [
            'system_authority' => false,
            'source_identity' => false,
            'hotel_alignment' => false,
            'platform_object_alignment' => false,
            'date_window_alignment' => false,
            'database_persistence' => false,
            'database_readback' => false,
            'review_metric_alignment' => false,
            'source_validation' => false,
        ];
        $failureReasons = [];

        $checks['system_authority'] = in_array($evidenceType, [
            'source_verified_metric_readback',
            'ota_source_readback',
            'business_metric_readback',
        ], true)
            && strtolower(trim((string)($context['verification_authority'] ?? ''))) === 'system_readback'
            && $createdBy === 0;
        if (!$checks['system_authority']) {
            $failureReasons[] = 'system_readback_authority_missing';
        }

        $source = trim((string)($context['source'] ?? ''));
        $sourceRef = trim((string)($context['source_ref'] ?? ''));
        $checks['source_identity'] = $source !== '' && $sourceRef !== '';
        if (!$checks['source_identity']) {
            $failureReasons[] = 'source_identity_missing';
        }

        $intentHotelId = (int)($intent['hotel_id'] ?? 0);
        $evidenceHotelId = (int)($context['system_hotel_id'] ?? $context['hotel_id'] ?? 0);
        $checks['hotel_alignment'] = $intentHotelId > 0 && $evidenceHotelId === $intentHotelId;
        if (!$checks['hotel_alignment']) {
            $failureReasons[] = $evidenceHotelId > 0 ? 'evidence_hotel_mismatch' : 'evidence_hotel_missing';
        }

        $intentPlatform = strtolower(trim((string)($intent['platform'] ?? '')));
        $evidencePlatform = strtolower(trim((string)($context['platform'] ?? '')));
        $intentObject = strtolower(trim((string)($intent['object_type'] ?? '')));
        $evidenceObject = strtolower(trim((string)($context['object_type'] ?? '')));
        $checks['platform_object_alignment'] = $intentPlatform !== ''
            && $evidencePlatform === $intentPlatform
            && $intentObject !== ''
            && $evidenceObject === $intentObject;
        if (!$checks['platform_object_alignment']) {
            $failureReasons[] = 'evidence_platform_or_object_mismatch';
        }

        $intentDateStart = substr(trim((string)($intent['date_start'] ?? '')), 0, 10);
        $intentDateEnd = substr(trim((string)($intent['date_end'] ?? $intentDateStart)), 0, 10);
        $evidenceDateStart = substr(trim((string)($context['date_start'] ?? '')), 0, 10);
        $evidenceDateEnd = substr(trim((string)($context['date_end'] ?? $evidenceDateStart)), 0, 10);
        $checks['date_window_alignment'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $intentDateStart) === 1
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $intentDateEnd) === 1
            && $evidenceDateStart === $intentDateStart
            && $evidenceDateEnd === $intentDateEnd;
        if (!$checks['date_window_alignment']) {
            $failureReasons[] = 'evidence_date_window_mismatch';
        }

        $checks['database_persistence'] = $this->executionReadbackFlagIsTrue(
            $context['database_written'] ?? $context['persisted'] ?? false
        );
        if (!$checks['database_persistence']) {
            $failureReasons[] = 'evidence_database_persistence_unverified';
        }

        $readbackAt = trim((string)($context['readback_at'] ?? ''));
        $checks['database_readback'] = $this->executionReadbackFlagIsTrue($context['readback_verified'] ?? false)
            && is_numeric($context['readback_count'] ?? null)
            && (int)$context['readback_count'] > 0
            && $readbackAt !== ''
            && strtotime($readbackAt) !== false;
        if (!$checks['database_readback']) {
            $failureReasons[] = 'evidence_database_readback_unverified';
        }

        $expectedMetric = strtolower(trim((string)($intent['expected_metric'] ?? '')));
        $evidenceMetric = strtolower(trim((string)($context['metric_key'] ?? $context['expected_metric'] ?? '')));
        $metricKeys = $this->executionMetricAliases($expectedMetric);
        $checks['review_metric_alignment'] = $expectedMetric !== ''
            && $evidenceMetric === $expectedMetric
            && $this->firstNumericMetric($before, $metricKeys) !== null
            && $this->firstNumericMetric($after, $metricKeys) !== null;
        if (!$checks['review_metric_alignment']) {
            $failureReasons[] = 'review_metric_alignment_missing';
        }

        $validationStatus = strtolower(trim((string)($context['validation_status'] ?? '')));
        $checks['source_validation'] = in_array($validationStatus, ['verified', 'available', 'normal'], true)
            && trim((string)($context['failure_reason'] ?? '')) === '';
        if (!$checks['source_validation']) {
            $failureReasons[] = trim((string)($context['failure_reason'] ?? '')) !== ''
                ? 'source_validation_failed'
                : 'source_validation_unverified';
        }

        $sourceVerified = !in_array(false, $checks, true);

        return [
            'evidence_id' => (int)($evidence['id'] ?? 0),
            'evidence_type' => $evidenceType,
            'operator_attested' => $operatorAttested,
            'source_verified' => $sourceVerified,
            'status' => $sourceVerified ? 'verified' : ($operatorAttested ? 'partial' : 'unverified'),
            'failure_reason' => $sourceVerified ? null : ($operatorAttested ? 'operator_attested_only' : ($failureReasons[0] ?? 'execution_evidence_unverified')),
            'failure_reasons' => array_values(array_unique($failureReasons)),
            'checks' => $checks,
            'source' => $source !== '' ? $source : null,
            'source_ref' => $sourceRef !== '' ? $sourceRef : null,
        ];
    }

    /**
     * A canonical investigation proves that a bounded arithmetic/scope check was
     * completed against an exact persisted OTA row. It never proves that an OTA
     * mutation occurred or that a business outcome improved.
     *
     * @param array<string,mixed> $intent
     * @param array<string,mixed> $task
     * @param array<string,mixed> $evidence
     * @param array<string,mixed> $platformResponse
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return array<string,mixed>
     */
    private function assessCanonicalInvestigationEvidence(
        array $intent,
        array $task,
        array $evidence,
        array $platformResponse,
        array $before,
        array $after
    ): array {
        $receipt = $this->arrayValue($platformResponse['analysis_receipt'] ?? []);
        $intentEvidence = $this->arrayValue($intent['evidence'] ?? []);
        $checks = [
            'system_authority' => false,
            'source_identity' => false,
            'operation_identity' => false,
            'scope_alignment' => false,
            'canonical_binding' => false,
            'database_readback' => false,
            'deterministic_review' => false,
            'protected_boundary' => false,
            'receipt_digest' => false,
        ];
        $failureReasons = [];

        $checks['system_authority'] = (int)($evidence['created_by'] ?? 0) === 0
            && (string)($intent['source_module'] ?? '') === 'canonical_ota_investigation'
            && (string)($intent['status'] ?? '') === 'system_authorized_analysis'
            && (int)($intent['created_by'] ?? 0) === 0
            && (int)($intent['approved_by'] ?? 0) === 0
            && trim((string)($intent['approved_at'] ?? '')) === ''
            && (string)($task['execution_mode'] ?? '') === 'analysis_only'
            && (int)($task['operator_id'] ?? 0) === 0
            && (string)($receipt['schema_version'] ?? '') === 'canonical_ota_investigation_evidence.v1'
            && (string)($receipt['verification_authority'] ?? '') === 'canonical_ota_investigation_service';
        if (!$checks['system_authority']) {
            $failureReasons[] = 'canonical_analysis_system_authority_invalid';
        }

        $rowId = (int)($receipt['row_id'] ?? 0);
        $dataSourceId = (int)($receipt['data_source_id'] ?? 0);
        $syncTaskId = (int)($receipt['sync_task_id'] ?? 0);
        $source = trim((string)($receipt['source'] ?? ''));
        $sourceRef = trim((string)($receipt['source_ref'] ?? ''));
        $checks['source_identity'] = $rowId > 0
            && $source === 'online_daily_data'
            && $sourceRef === 'online_daily_data#' . $rowId
            && (int)($intent['source_record_id'] ?? 0) === $rowId
            && $rowId === (int)($intentEvidence['row_id'] ?? 0)
            && $dataSourceId > 0
            && $dataSourceId === (int)($intentEvidence['data_source_id'] ?? 0)
            && $syncTaskId > 0
            && $syncTaskId === (int)($intentEvidence['sync_task_id'] ?? 0);
        if (!$checks['source_identity']) {
            $failureReasons[] = 'canonical_analysis_source_identity_invalid';
        }

        $intentId = (int)($intent['id'] ?? 0);
        $taskId = (int)($task['id'] ?? 0);
        $checks['operation_identity'] = $intentId > 0
            && $taskId > 0
            && (int)($task['intent_id'] ?? 0) === $intentId
            && (int)($evidence['task_id'] ?? 0) === $taskId
            && (int)($receipt['operation_intent_id'] ?? 0) === $intentId
            && (int)($receipt['operation_task_id'] ?? 0) === $taskId
            && (string)($receipt['action_type'] ?? '') === (string)($intent['action_type'] ?? '')
            && (string)($intent['object_type'] ?? '') === 'operation_checklist'
            && (string)($intent['expected_metric'] ?? '') === 'investigation_completion'
            && ($intent['expected_delta'] ?? null) === null
            && (string)($task['status'] ?? '') === 'executed'
            && (string)($task['result_status'] ?? '') === 'observing'
            && (int)($task['action_track_id'] ?? 0) === 0;
        if (!$checks['operation_identity']) {
            $failureReasons[] = 'canonical_analysis_operation_identity_invalid';
        }

        $intentTenantId = (int)($intent['tenant_id'] ?? 0);
        $intentHotelId = (int)($intent['hotel_id'] ?? 0);
        $intentPlatform = strtolower(trim((string)($intent['platform'] ?? '')));
        $intentEvidencePlatform = strtolower(trim((string)($intentEvidence['platform'] ?? '')));
        $canonicalPlatforms = ['ctrip', 'meituan'];
        $expectedActionTypes = in_array($intentPlatform, $canonicalPlatforms, true)
            ? CanonicalOtaInvestigationActionService::actionTypesForPlatform($intentPlatform)
            : [];
        $intentDateStart = substr(trim((string)($intent['date_start'] ?? '')), 0, 10);
        $intentDateEnd = substr(trim((string)($intent['date_end'] ?? '')), 0, 10);
        $intentEvidenceDate = trim((string)($intentEvidence['target_date'] ?? ''));
        $receiptPeriod = strtolower(trim((string)($receipt['data_period'] ?? '')));
        $intentEvidencePeriod = strtolower(trim((string)($intentEvidence['data_period'] ?? '')));
        $checks['scope_alignment'] = $intentTenantId > 0
            && $intentTenantId === (int)($task['tenant_id'] ?? 0)
            && $intentTenantId === (int)($evidence['tenant_id'] ?? 0)
            && $intentTenantId === (int)($receipt['tenant_id'] ?? 0)
            && $intentTenantId === (int)($intentEvidence['tenant_id'] ?? 0)
            && $intentHotelId > 0
            && $intentHotelId === (int)($task['hotel_id'] ?? 0)
            && $intentHotelId === (int)($receipt['system_hotel_id'] ?? 0)
            && $intentHotelId === (int)($intentEvidence['hotel_id'] ?? 0)
            && in_array($intentPlatform, $canonicalPlatforms, true)
            && $intentPlatform === strtolower(trim((string)($receipt['platform'] ?? '')))
            && $intentPlatform === $intentEvidencePlatform
            && in_array((string)($receipt['action_type'] ?? ''), $expectedActionTypes, true)
            && $intentDateStart !== ''
            && $intentDateStart === $intentDateEnd
            && $intentDateStart === substr(trim((string)($receipt['date_start'] ?? '')), 0, 10)
            && $intentDateStart === substr(trim((string)($receipt['date_end'] ?? '')), 0, 10)
            && $intentDateStart === $intentEvidenceDate
            && preg_match('/^[a-z0-9_]{1,40}$/D', $receiptPeriod) === 1
            && $receiptPeriod === $intentEvidencePeriod;
        if (!$checks['scope_alignment']) {
            $failureReasons[] = 'canonical_analysis_scope_alignment_invalid';
        }

        $digestBindings = [
            'action_content_digest' => 'action_content_digest',
            'action_set_digest' => 'action_set_digest',
            'source_draft_set_digest' => 'draft_set_content_digest',
            'promotion_content_digest' => 'promotion_content_digest',
            'authoritative_fact_digest' => 'authoritative_fact_digest',
            'platform_hotel_identity_digest' => 'platform_hotel_identity_digest',
            'contract_digest' => 'contract_digest',
        ];
        $checks['canonical_binding'] = true;
        foreach ($digestBindings as $receiptField => $intentEvidenceField) {
            $receiptValue = strtolower(trim((string)($receipt[$receiptField] ?? '')));
            $intentEvidenceValue = strtolower(trim((string)($intentEvidence[$intentEvidenceField] ?? '')));
            if (!$this->canonicalEvidenceDigest($receiptValue)
                || !$this->canonicalEvidenceDigest($intentEvidenceValue)
                || !hash_equals($intentEvidenceValue, $receiptValue)
            ) {
                $checks['canonical_binding'] = false;
                break;
            }
        }
        if (!$checks['canonical_binding']) {
            $failureReasons[] = 'canonical_analysis_canonical_binding_invalid';
        }

        $readbackAt = trim((string)($receipt['readback_at'] ?? ''));
        $checks['database_readback'] = ($receipt['database_written'] ?? false) === true
            && ($receipt['readback_verified'] ?? false) === true
            && (int)($receipt['readback_count'] ?? 0) === 1
            && $readbackAt !== ''
            && strtotime($readbackAt) !== false
            && (string)($receipt['validation_status'] ?? '') === 'verified'
            && trim((string)($receipt['failure_reason'] ?? '')) === '';
        if (!$checks['database_readback']) {
            $failureReasons[] = 'canonical_analysis_database_readback_invalid';
        }

        $review = $this->arrayValue($receipt['deterministic_review'] ?? []);
        $actionSnapshot = $this->arrayValue($receipt['action_snapshot'] ?? []);
        $taskCurrentValue = $this->arrayValue($task['current_value'] ?? []);
        $taskTargetValue = $this->arrayValue($task['target_value'] ?? []);
        $actionDigest = strtolower(trim((string)($receipt['action_content_digest'] ?? '')));
        $checks['deterministic_review'] = (string)($review['reviewer_contract_version'] ?? '')
                === 'canonical_ota_investigation_deterministic_review.v1'
            && ($review['formula_result_match'] ?? false) === true
            && ($review['scope_match'] ?? false) === true
            && ($review['boundary_match'] ?? false) === true
            && (string)($review['verdict'] ?? '') === 'PASS'
            && (string)($review['process_status'] ?? '') === 'READY'
            && $this->canonicalEvidenceDigest($actionDigest)
            && hash_equals(
                $actionDigest,
                (string)($intentEvidence['action_content_digest'] ?? '')
            )
            && hash_equals($actionDigest, (string)($taskTargetValue['action_content_digest'] ?? ''))
            && $actionSnapshot !== []
            && (string)($actionSnapshot['action_type'] ?? '') === (string)($receipt['action_type'] ?? '')
            && (string)($actionSnapshot['action_content_digest'] ?? '') === $actionDigest
            && hash_equals($actionDigest, $this->canonicalInvestigationActionDigest($actionSnapshot))
            && ($actionSnapshot['formula_contract'] ?? null) === ($receipt['formula_contract'] ?? null)
            && ($actionSnapshot['deterministic_result'] ?? null) === ($receipt['deterministic_result'] ?? null)
            && ($actionSnapshot['deterministic_review'] ?? null) === $review
            && ($taskCurrentValue['deterministic_result'] ?? null) === ($receipt['deterministic_result'] ?? null);
        if (!$checks['deterministic_review']) {
            $failureReasons[] = 'canonical_analysis_deterministic_review_invalid';
        }

        $checks['protected_boundary'] = $before === []
            && $after === []
            && ($receipt['external_write'] ?? true) === false
            && ($receipt['external_action_triggered'] ?? true) === false
            && ($receipt['ota_mutation_performed'] ?? true) === false
            && ($receipt['causality_claimed'] ?? true) === false
            && ($receipt['business_outcome_claimed'] ?? true) === false
            && ($intentEvidence['human_approval_claimed'] ?? true) === false
            && ($intentEvidence['external_write'] ?? true) === false
            && ($intentEvidence['causality_claimed'] ?? true) === false
            && ($intentEvidence['outcome_claimed'] ?? true) === false;
        if (!$checks['protected_boundary']) {
            $failureReasons[] = 'canonical_analysis_protected_boundary_invalid';
        }

        $receiptDigest = strtolower(trim((string)($receipt['content_digest'] ?? '')));
        $checks['receipt_digest'] = $this->canonicalEvidenceDigest($receiptDigest)
            && hash_equals($receiptDigest, $this->canonicalInvestigationReceiptDigest($receipt));
        if (!$checks['receipt_digest']) {
            $failureReasons[] = 'canonical_analysis_receipt_digest_invalid';
        }

        $sourceVerified = !in_array(false, $checks, true);
        return [
            'evidence_id' => (int)($evidence['id'] ?? 0),
            'evidence_type' => 'canonical_analysis_completion',
            'operator_attested' => false,
            'source_verified' => $sourceVerified,
            'status' => $sourceVerified ? 'verified' : 'unverified',
            'failure_reason' => $sourceVerified ? null : ($failureReasons[0] ?? 'canonical_analysis_evidence_unverified'),
            'failure_reasons' => array_values(array_unique($failureReasons)),
            'checks' => $checks,
            'source' => $source !== '' ? $source : null,
            'source_ref' => $sourceRef !== '' ? $sourceRef : null,
        ];
    }

    /** @param array<string,mixed> $receipt */
    private function canonicalInvestigationReceiptDigest(array $receipt): string
    {
        unset($receipt['content_digest']);
        return hash('sha256', json_encode(
            $this->canonicalInvestigationDigestValue($receipt),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    /** @param array<string,mixed> $action */
    private function canonicalInvestigationActionDigest(array $action): string
    {
        unset($action['action_content_digest']);
        return hash('sha256', json_encode(
            $this->canonicalInvestigationDigestValue($action),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    private function canonicalInvestigationDigestValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalInvestigationDigestValue($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalInvestigationDigestValue($item);
        }
        return $value;
    }

    private function canonicalEvidenceDigest(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', strtolower(trim($value))) === 1;
    }

    /**
     * Source verification proves provenance. Outcome truth separately proves that
     * the measured metric moved in the recorded direction and met its pre-recorded target.
     */
    public function buildExecutionOutcomeTruth(array $intent, array $task, array $evidenceRows): array
    {
        $metric = strtolower(trim((string)($intent['expected_metric'] ?? '')));
        $base = [
            'status' => 'unverified',
            'outcome_verified' => false,
            'positive_outcome_verified' => false,
            'source_verified' => false,
            'metric_key' => $metric !== '' ? $metric : null,
            'direction' => null,
            'target_type' => null,
            'target_value' => null,
            'expected_delta' => null,
            'expected_delta_status' => null,
            'before_value' => null,
            'after_value' => null,
            'actual_delta' => null,
            'favorable_delta' => null,
            'actual_change_rate' => null,
            'progress_rate' => null,
            'failure_reason' => $metric === '' ? 'expected_metric_missing' : 'source_verified_metric_readback_missing',
            'causality_claimed' => false,
        ];
        if ($metric === '') {
            return $base;
        }

        $metricKeys = $this->executionMetricAliases($metric);
        $beforeValue = null;
        $afterValue = null;
        foreach ($evidenceRows as $evidence) {
            if (!is_array($evidence)) {
                continue;
            }
            $assessment = $this->assessExecutionEvidenceTruth($intent, $task, $evidence);
            if (($assessment['source_verified'] ?? false) !== true) {
                continue;
            }
            $before = $this->arrayValue($evidence['before'] ?? []);
            $after = $this->arrayValue($evidence['after'] ?? []);
            $candidateBefore = $this->firstNumericMetric($before, $metricKeys);
            $candidateAfter = $this->firstNumericMetric($after, $metricKeys);
            if ($candidateBefore === null || $candidateAfter === null) {
                continue;
            }
            $beforeValue = (float)$candidateBefore;
            $afterValue = (float)$candidateAfter;
            break;
        }
        if ($beforeValue === null || $afterValue === null) {
            return $base;
        }

        $direction = $this->executionOutcomeDirection($intent, $metric);
        $base['source_verified'] = true;
        $base['before_value'] = round($beforeValue, 4);
        $base['after_value'] = round($afterValue, 4);
        $base['actual_delta'] = round($afterValue - $beforeValue, 4);
        if ($direction === null) {
            $base['failure_reason'] = 'expected_direction_unknown';
            return $base;
        }
        $base['direction'] = $direction;

        $targetValue = $this->executionOutcomeAbsoluteTarget($intent, $metric);
        $targetType = $targetValue !== null ? 'absolute' : 'delta';
        $expectedDeltaStatus = $this->executionExpectedDeltaStatus($intent);
        $expectedDelta = is_numeric($intent['expected_delta'] ?? null)
            ? (float)$intent['expected_delta']
            : null;

        if ($metric === 'ota_operation_closure') {
            $targetValue = 1.0;
            $targetType = 'absolute';
            $expectedDeltaStatus = 'system_quantified';
        } elseif ($targetValue === null) {
            if (in_array($expectedDeltaStatus, ['not_quantified', 'pending', 'unknown'], true)
                || $expectedDelta === null
                || $expectedDelta < 0
                || ($expectedDelta === 0.0 && !in_array($expectedDeltaStatus, [
                    'quantified',
                    'confirmed',
                    'manual_confirmed',
                    'system_quantified',
                    'verified',
                ], true))
            ) {
                $base['expected_delta'] = $expectedDelta;
                $base['expected_delta_status'] = $expectedDeltaStatus !== '' ? $expectedDeltaStatus : 'not_quantified';
                $base['failure_reason'] = $expectedDelta !== null && $expectedDelta < 0
                    ? 'expected_delta_invalid'
                    : 'target_not_quantified';
                return $base;
            }
        }

        $favorableDelta = $direction === 'increase'
            ? $afterValue - $beforeValue
            : $beforeValue - $afterValue;
        $base['target_type'] = $targetType;
        $base['target_value'] = $targetValue;
        $base['expected_delta'] = $expectedDelta;
        $base['expected_delta_status'] = $expectedDeltaStatus !== '' ? $expectedDeltaStatus : 'quantified';
        $base['favorable_delta'] = round($favorableDelta, 4);

        if ($targetType === 'absolute') {
            $targetFavorableDelta = $direction === 'increase'
                ? (float)$targetValue - $beforeValue
                : $beforeValue - (float)$targetValue;
            $targetMet = $direction === 'increase'
                ? $afterValue >= (float)$targetValue
                : $afterValue <= (float)$targetValue;
            if ($targetMet && $favorableDelta >= 0) {
                return array_replace($base, [
                    'status' => 'met',
                    'outcome_verified' => true,
                    'positive_outcome_verified' => true,
                    'progress_rate' => 100.0,
                    'failure_reason' => null,
                ]);
            }
            if ($favorableDelta < 0) {
                return array_replace($base, [
                    'status' => 'adverse',
                    'outcome_verified' => true,
                    'failure_reason' => 'metric_worsened',
                ]);
            }
            if ($targetFavorableDelta <= 0) {
                return array_replace($base, [
                    'status' => 'missed',
                    'outcome_verified' => true,
                    'failure_reason' => 'target_not_met',
                ]);
            }
            $progressRate = ($favorableDelta / $targetFavorableDelta) * 100;
            return $this->finalizeExecutionOutcomeTruth($base, $progressRate);
        }

        if ($expectedDelta === null) {
            $base['failure_reason'] = 'target_not_quantified';
            return $base;
        }
        if ($expectedDelta === 0.0) {
            return array_replace($base, [
                'status' => $favorableDelta >= 0 ? 'met' : 'adverse',
                'outcome_verified' => true,
                'positive_outcome_verified' => $favorableDelta >= 0,
                'actual_change_rate' => $beforeValue != 0.0
                    ? round(($favorableDelta / abs($beforeValue)) * 100, 4)
                    : null,
                'progress_rate' => $favorableDelta >= 0 ? 100.0 : null,
                'failure_reason' => $favorableDelta >= 0 ? null : 'metric_worsened',
            ]);
        }
        $base['actual_change_rate'] = $beforeValue != 0.0
            ? round(($favorableDelta / abs($beforeValue)) * 100, 4)
            : null;
        if ($favorableDelta < 0) {
            return array_replace($base, [
                'status' => 'adverse',
                'outcome_verified' => true,
                'failure_reason' => 'metric_worsened',
            ]);
        }

        return $this->finalizeExecutionOutcomeTruth($base, ($favorableDelta / $expectedDelta) * 100);
    }

    public function executionPositiveOutcomeAllowsStatus(array $outcomeTruth, string $reviewStatus): bool
    {
        $status = strtolower(trim((string)($outcomeTruth['status'] ?? 'unverified')));
        return match (strtolower(trim($reviewStatus))) {
            'success' => $status === 'met',
            'near_success' => in_array($status, ['met', 'near'], true),
            default => false,
        };
    }

    private function finalizeExecutionOutcomeTruth(array $base, float $progressRate): array
    {
        $status = $progressRate >= 100.0 ? 'met' : ($progressRate >= 70.0 ? 'near' : 'missed');
        return array_replace($base, [
            'status' => $status,
            'outcome_verified' => true,
            'positive_outcome_verified' => in_array($status, ['met', 'near'], true),
            'progress_rate' => round($progressRate, 4),
            'failure_reason' => $status === 'met'
                ? null
                : ($status === 'near' ? 'target_near_met' : 'target_not_met'),
        ]);
    }

    private function executionOutcomeDirection(array $intent, string $metric): ?string
    {
        $targetValue = $this->arrayValue($intent['target_value'] ?? []);
        $evidence = $this->arrayValue($intent['evidence'] ?? []);
        foreach ([
            $intent['expected_direction'] ?? null,
            $targetValue['expected_direction'] ?? null,
            $targetValue['direction'] ?? null,
            $evidence['expected_direction'] ?? null,
            $evidence['direction'] ?? null,
        ] as $candidate) {
            $normalized = strtolower(trim((string)$candidate));
            if (in_array($normalized, ['increase', 'up', 'higher', 'higher_is_better', 'positive'], true)) {
                return 'increase';
            }
            if (in_array($normalized, ['decrease', 'down', 'lower', 'lower_is_better', 'negative'], true)) {
                return 'decrease';
            }
        }

        return in_array($metric, [
            'revenue',
            'avg_revenue',
            'amount',
            'income',
            'orders',
            'avg_orders',
            'order_count',
            'book_order_num',
            'room_nights',
            'avg_room_nights',
            'occ',
            'occupancy',
            'avg_occ',
            'detail_rate',
            'view_rate',
            'flow_rate',
            'conversion',
            'conversion_rate',
            'order_rate',
            'advertising_roas',
            'avg_psi_score',
            'ota_operation_closure',
            'public_page_verified_field_count',
        ], true) ? 'increase' : null;
    }

    private function executionOutcomeAbsoluteTarget(array $intent, string $metric): ?float
    {
        $targetValue = $this->arrayValue($intent['target_value'] ?? []);
        $keys = ['expected_target', 'target_' . $metric];
        foreach ($this->executionMetricAliases($metric) as $alias) {
            $keys[] = 'target_' . $alias;
        }
        if (strtolower(trim((string)($targetValue['target_metric'] ?? ''))) === $metric) {
            $keys[] = 'target';
            $keys[] = 'value';
        }
        $value = $this->firstNumericMetric($targetValue, array_values(array_unique($keys)));
        return $value !== null ? (float)$value : null;
    }

    private function executionExpectedDeltaStatus(array $intent): string
    {
        $targetValue = $this->arrayValue($intent['target_value'] ?? []);
        $evidence = $this->arrayValue($intent['evidence'] ?? []);
        foreach ([
            $targetValue['expected_delta_status'] ?? null,
            $evidence['expected_delta_status'] ?? null,
        ] as $candidate) {
            $status = strtolower(trim((string)$candidate));
            if ($status !== '') {
                return $status;
            }
        }
        return '';
    }

    private function executionMetricAliases(string $metric): array
    {
        return match ($metric) {
            'revenue', 'avg_revenue', 'amount', 'income' => ['revenue', 'avg_revenue', 'amount', 'income'],
            'orders', 'avg_orders', 'order_count' => ['orders', 'avg_orders', 'order_count', 'book_order_num'],
            'room_nights', 'avg_room_nights' => ['room_nights', 'avg_room_nights', 'quantity'],
            'adr', 'avg_adr' => ['adr', 'avg_adr'],
            'occ', 'occupancy', 'avg_occ' => ['occ', 'occupancy', 'avg_occ'],
            'conversion', 'conversion_rate' => ['conversion', 'conversion_rate'],
            default => $metric !== '' ? [$metric] : [],
        };
    }

    public function buildExecutionTruthContext(
        array $intent,
        array $task,
        array $evidenceTruth,
        string $reviewStatus,
        array $outcomeTruth = []
    ): array
    {
        $status = 'unverified';
        $failureReason = $evidenceTruth['failure_reason'] ?? 'execution_evidence_unverified';
        $taskStatus = strtolower(trim((string)($task['status'] ?? '')));

        if ($taskStatus === 'failed') {
            $status = 'partial';
            $failureReason = 'execution_failed';
        } elseif ($taskStatus !== 'executed') {
            $failureReason = 'execution_not_completed';
        } elseif (($evidenceTruth['source_verified'] ?? false) === true) {
            if (in_array($reviewStatus, ['success', 'near_success'], true)) {
                if ($this->executionPositiveOutcomeAllowsStatus($outcomeTruth, $reviewStatus)) {
                    $status = 'verified';
                    $failureReason = null;
                } else {
                    $status = 'partial';
                    $failureReason = (string)($outcomeTruth['failure_reason'] ?? 'positive_outcome_unverified');
                }
            } elseif ($reviewStatus === 'failed') {
                $status = 'partial';
                $failureReason = 'review_status_failed';
            } else {
                $status = 'partial';
                $failureReason = 'review_status_observing';
            }
        } elseif (($evidenceTruth['operator_attested'] ?? false) === true) {
            $status = 'partial';
            $failureReason = 'operator_attested_only';
        }

        return [
            'status' => $status,
            'scope' => 'operation_execution',
            'hotel_id' => (int)($intent['hotel_id'] ?? 0),
            'platform' => (string)($intent['platform'] ?? ''),
            'object_type' => (string)($intent['object_type'] ?? ''),
            'date_start' => (string)($intent['date_start'] ?? ''),
            'date_end' => (string)($intent['date_end'] ?? ''),
            'metric_key' => (string)($intent['expected_metric'] ?? ''),
            'execution_status' => $taskStatus,
            'review_status' => $reviewStatus,
            'outcome_status' => (string)($outcomeTruth['status'] ?? 'unverified'),
            'positive_outcome_verified' => ($outcomeTruth['positive_outcome_verified'] ?? false) === true,
            'operator_attested' => ($evidenceTruth['operator_attested'] ?? false) === true,
            'source_verified' => ($evidenceTruth['source_verified'] ?? false) === true,
            'failure_reason' => $failureReason,
        ];
    }

    public function buildExecutionRoi(
        array $intent,
        array $task,
        array $latestEvidence,
        array $evidenceTruth,
        array $outcomeTruth = []
    ): array
    {
        $emptyMetrics = [
            'value' => null,
            'unit' => null,
            'before_revenue' => null,
            'after_revenue' => null,
            'incremental_revenue' => null,
            'cost' => null,
            'profit' => null,
            'formula' => null,
        ];
        if (empty($latestEvidence)) {
            return ['status' => 'unverified', 'message' => 'execution evidence missing', 'failure_reason' => 'execution_evidence_missing']
                + $emptyMetrics
                + ['evidence_truth' => $evidenceTruth];
        }

        $platformResponse = $this->arrayValue($latestEvidence['platform_response'] ?? []);
        $operatorEvidenceSummary = $this->buildExecutionOperatorEvidenceSummary($platformResponse);
        if (($evidenceTruth['source_verified'] ?? false) !== true) {
            $status = ($evidenceTruth['operator_attested'] ?? false) === true ? 'partial' : 'unverified';
            return array_merge([
                'status' => $status,
                'message' => 'source-verified execution evidence missing',
                'failure_reason' => $evidenceTruth['failure_reason'] ?? 'execution_evidence_unverified',
            ], $emptyMetrics, ['evidence_truth' => $evidenceTruth], $operatorEvidenceSummary);
        }

        $reviewStatus = strtolower(trim((string)($task['result_status'] ?? 'observing')));
        if (!in_array($reviewStatus, ['success', 'near_success'], true)) {
            $failureReason = $reviewStatus === 'failed' ? 'review_status_failed' : 'review_status_observing';
            return array_merge([
                'status' => 'partial',
                'message' => 'execution outcome is not source-verified as successful',
                'failure_reason' => $failureReason,
            ], $emptyMetrics, ['evidence_truth' => $evidenceTruth], $operatorEvidenceSummary);
        }
        if (!$this->executionPositiveOutcomeAllowsStatus($outcomeTruth, $reviewStatus)) {
            return array_merge([
                'status' => 'partial',
                'message' => 'execution outcome does not satisfy its recorded target',
                'failure_reason' => $outcomeTruth['failure_reason'] ?? 'positive_outcome_unverified',
            ], $emptyMetrics, [
                'evidence_truth' => $evidenceTruth,
                'outcome_truth' => $outcomeTruth,
            ], $operatorEvidenceSummary);
        }

        $before = $this->arrayValue($latestEvidence['before'] ?? []);
        $after = $this->arrayValue($latestEvidence['after'] ?? []);
        $beforeRevenue = $this->firstNumericMetric($before, ['revenue', 'avg_revenue', 'amount', 'income']);
        $afterRevenue = $this->firstNumericMetric($after, ['revenue', 'avg_revenue', 'amount', 'income']);
        if ($beforeRevenue === null || $afterRevenue === null) {
            return array_merge([
                'status' => 'partial',
                'message' => 'revenue evidence missing',
                'failure_reason' => 'revenue_evidence_missing',
            ], $emptyMetrics, ['evidence_truth' => $evidenceTruth], $operatorEvidenceSummary);
        }

        $targetValue = $this->arrayValue($task['target_value'] ?? []);
        if (empty($targetValue)) {
            $targetValue = $this->arrayValue($intent['target_value'] ?? []);
        }
        $cost = $this->firstNumericMetric($after, ['cost', 'ad_cost', 'spend', 'budget']);
        $cost ??= $this->firstNumericMetric($platformResponse, ['cost', 'ad_cost', 'spend', 'budget']);
        $cost ??= $this->firstNumericMetric($targetValue, ['cost', 'ad_cost', 'spend', 'budget']);
        if ($cost === null) {
            if ((string)($intent['object_type'] ?? '') === 'price') {
                $incrementalRevenue = $afterRevenue - $beforeRevenue;

                return [
                    'status' => 'ready',
                    'value' => round($incrementalRevenue, 2),
                    'unit' => 'amount',
                    'before_revenue' => round($beforeRevenue, 2),
                    'after_revenue' => round($afterRevenue, 2),
                    'incremental_revenue' => round($incrementalRevenue, 2),
                    'cost' => null,
                    'profit' => null,
                    'formula' => 'after_revenue - before_revenue',
                    'failure_reason' => null,
                    'evidence_truth' => $evidenceTruth,
                ] + $operatorEvidenceSummary;
            }

            return array_merge([
                'status' => 'partial',
                'message' => 'cost evidence missing',
                'failure_reason' => 'cost_evidence_missing',
            ], $emptyMetrics, [
                'before_revenue' => round($beforeRevenue, 2),
                'after_revenue' => round($afterRevenue, 2),
                'incremental_revenue' => round($afterRevenue - $beforeRevenue, 2),
                'evidence_truth' => $evidenceTruth,
            ], $operatorEvidenceSummary);
        }
        if ($cost <= 0 && (string)($intent['object_type'] ?? '') !== 'price') {
            return array_merge([
                'status' => 'partial',
                'message' => 'cost denominator must be greater than zero',
                'failure_reason' => 'roi_cost_denominator_non_positive',
            ], $emptyMetrics, [
                'before_revenue' => round($beforeRevenue, 2),
                'after_revenue' => round($afterRevenue, 2),
                'incremental_revenue' => round($afterRevenue - $beforeRevenue, 2),
                'cost' => round($cost, 2),
                'evidence_truth' => $evidenceTruth,
            ], $operatorEvidenceSummary);
        }
        if ($cost <= 0) {
            $incrementalRevenue = $afterRevenue - $beforeRevenue;
            return [
                'status' => 'ready',
                'value' => round($incrementalRevenue, 2),
                'unit' => 'amount',
                'before_revenue' => round($beforeRevenue, 2),
                'after_revenue' => round($afterRevenue, 2),
                'incremental_revenue' => round($incrementalRevenue, 2),
                'cost' => round($cost, 2),
                'profit' => round($incrementalRevenue - $cost, 2),
                'formula' => 'after_revenue - before_revenue',
                'failure_reason' => null,
                'evidence_truth' => $evidenceTruth,
            ] + $operatorEvidenceSummary;
        }

        $incrementalRevenue = $afterRevenue - $beforeRevenue;
        $profit = $incrementalRevenue - $cost;

        return [
            'status' => 'ready',
            'value' => round($profit / $cost * 100, 2),
            'unit' => '%',
            'before_revenue' => round($beforeRevenue, 2),
            'after_revenue' => round($afterRevenue, 2),
            'incremental_revenue' => round($incrementalRevenue, 2),
            'cost' => round($cost, 2),
            'profit' => round($profit, 2),
            'formula' => '(after_revenue - before_revenue - cost) / cost',
            'failure_reason' => null,
            'evidence_truth' => $evidenceTruth,
        ] + $operatorEvidenceSummary;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function buildExecutionOperatorEvidenceSummary(array $platformResponse): array
    {
        return [
            'operator_execution_evidence_summary' => $this->summarizeExecutionOperatorEvidence(
                $this->arrayValue($platformResponse['operator_execution_evidence'] ?? []),
                ['executed_by', 'executed_at', 'execution_basis', 'room_rate_mapping_source', 'execution_receipt_or_screenshot_path']
            ),
            'operator_roi_evidence_summary' => $this->summarizeExecutionOperatorEvidence(
                $this->arrayValue($platformResponse['operator_roi_evidence'] ?? []),
                ['reviewed_by', 'reviewed_at', 'before_metric_source', 'after_metric_source', 'roi_calculation_basis', 'roi_receipt_or_screenshot_path']
            ),
        ];
    }

    /**
     * @param list<string> $summaryKeys
     * @return array<string, mixed>
     */
    public function summarizeExecutionOperatorEvidence(array $evidence, array $summaryKeys): array
    {
        $summary = [
            'provided' => $evidence !== [],
            'keys' => array_values(array_keys($evidence)),
        ];

        foreach ($summaryKeys as $key) {
            $value = $evidence[$key] ?? null;
            if (is_scalar($value) && trim((string)$value) !== '') {
                $summary[$key] = (string)$value;
            }
        }

        return $summary;
    }

    private function executionReadbackFlagIsTrue(mixed $value): bool
    {
        return $value === true
            || $value === 1
            || in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes'], true);
    }

    private function arrayValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function firstNumericMetric(array $data, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            if ($value === '' || $value === null) {
                continue;
            }
            if (is_numeric($value)) {
                return (float)$value;
            }
        }

        return null;
    }
}
