<?php

declare(strict_types=1);

namespace app\service\operation;

use app\service\OperatingNetworkService;
use app\service\OperatingOpportunityLabService;
use app\service\OperatingQuestionExecutionBridgeService;
use app\service\OperationActionLifecycleService;
use app\service\RevenueCockpitActionContract;
use DateTimeImmutable;
use think\facade\Db;

trait OperationActionLifecycleConcern
{
    public function cancelExecutionIntent(
        int $id,
        string $reason,
        int $userId,
        array $hotelIds
    ): array {
        $this->assertExecutionPayloadHasNoCredentialMaterial($reason);
        $this->ensureExecutionTables();
        $reason = trim($reason);
        if ($userId <= 0 || $reason === '') {
            throw new \InvalidArgumentException('managed operation action cancellation requires an authenticated user and reason');
        }
        $this->withSourceBackedExecutionIntentApprovalAuthorization(
            $id,
            $hotelIds,
            function (array $authorization) use ($id, $hotelIds, $reason, $userId): void {
                $intentRow = $authorization['intent'];
                $intent = $this->normalizeExecutionIntentRow($intentRow);
                $lifecycle = new OperationActionLifecycleService();
                if (!$lifecycle->isManagedIntent($intent)) {
                    throw new \InvalidArgumentException('only versioned managed operation actions use the cancellation endpoint');
                }
                $dailyManagedAction = $lifecycle->isDailyOneThingIntent($intent);
                $events = $lifecycle->eventsForIntent(
                    (int)$intent['tenant_id'],
                    (int)$intent['hotel_id'],
                    (int)$intent['id']
                );
                $tasks = array_map([$this, 'normalizeExecutionTaskRow'], (array)$authorization['tasks']);
                $fromStatus = $lifecycle->currentStatus(array_merge($intent, ['tasks' => $tasks]), $events);
                $cancellableStatuses = $dailyManagedAction
                    ? ['draft', 'pending_approval', 'approved', 'executing']
                    : ['draft', 'pending_approval', 'approved', 'in_progress'];
                if (!in_array($fromStatus, $cancellableStatuses, true)) {
                    throw new \InvalidArgumentException('completed or reviewed operation action cannot be cancelled');
                }
                foreach ($tasks as $task) {
                    if (in_array((string)($task['status'] ?? ''), ['executed', 'failed'], true)) {
                        throw new \InvalidArgumentException('completed operation task cannot be cancelled');
                    }
                }
                $now = date('Y-m-d H:i:s');
                $affected = (int)Db::name('operation_execution_intents')
                    ->where('id', $id)
                    ->where('tenant_id', (int)$intent['tenant_id'])
                    ->where('hotel_id', (int)$intent['hotel_id'])
                    ->whereIn('status', ['draft', 'pending_approval', 'approved'])
                    ->whereNull('deleted_at')
                    ->update([
                        'status' => $dailyManagedAction ? 'blocked' : 'cancelled',
                        'blocked_reason' => $dailyManagedAction
                            ? $reason
                            : (string)($intent['blocked_reason'] ?? ''),
                        'review_remark' => $reason,
                        'updated_at' => $now,
                    ]);
                if ($affected !== 1) {
                    throw new \InvalidArgumentException('operation action state changed; refresh before cancellation');
                }
                if ($tasks !== []) {
                    Db::name('operation_execution_tasks')
                        ->where('intent_id', $id)
                        ->where('tenant_id', (int)$intent['tenant_id'])
                        ->where('hotel_id', (int)$intent['hotel_id'])
                        ->whereIn('status', ['pending_execute', 'executing', 'blocked'])
                        ->whereNull('deleted_at')
                        ->update([
                            'status' => $dailyManagedAction ? 'blocked' : 'cancelled',
                            'blocked_reason' => $reason,
                            'updated_at' => $now,
                        ]);
                }
                $task = $tasks === [] ? [] : $tasks[count($tasks) - 1];
                $lifecycle->appendEvent(
                    $intent,
                    (int)($task['id'] ?? 0),
                    $fromStatus,
                    $dailyManagedAction ? 'blocked' : 'cancelled',
                    $dailyManagedAction ? 'blocked' : 'cancelled',
                    $userId,
                    [
                        'reason' => $reason,
                        'task_ref' => (int)($task['id'] ?? 0) > 0
                            ? 'operation_execution_tasks#' . (int)$task['id']
                            : null,
                        'external_action_performed_by_system' => false,
                    ]
                );
            }
        );
        return $this->executionIntentDetail($id, $hotelIds);
    }

    /** @return array{target_value:array<string,mixed>,evidence:array<string,mixed>,expected_metric:string,expected_delta:?float} */
    private function buildSavedOtaDiagnosisApprovalTarget(
        array $intent,
        array $input,
        int $approvedBy,
        string $approvedAt
    ): array {
        $expectedMetric = strtolower(trim((string)($input['expected_metric'] ?? '')));
        $sourceMetric = strtolower(trim((string)($intent['expected_metric'] ?? '')));
        $approvalSourceModule = strtolower(trim((string)($intent['source_module'] ?? '')));
        $isOperatingQuestion = $approvalSourceModule === OperatingQuestionExecutionBridgeService::SOURCE_MODULE;
        $isManagedObservation = $isOperatingQuestion
            || $approvalSourceModule === RevenueCockpitActionContract::SOURCE_MODULE
            || $approvalSourceModule === OperatingOpportunityLabService::DAILY_SOURCE_MODULE;
        $approvalMode = strtolower(trim((string)($input['_approval_mode'] ?? 'human')));
        $aiReviewDigest = strtolower(trim((string)($input['_ai_review_digest'] ?? '')));
        if ($approvalMode === 'ai_independent_review') {
            if (!$isOperatingQuestion
                || $approvedBy !== 0
                || preg_match('/^[a-f0-9]{64}$/D', $aiReviewDigest) !== 1
            ) {
                throw new \InvalidArgumentException('AI independent review approval identity is invalid');
            }
        } elseif ($approvalMode !== 'human') {
            throw new \InvalidArgumentException('execution approval mode is not supported');
        }
        if ($expectedMetric === '' || $sourceMetric === '' || $expectedMetric !== $sourceMetric) {
            throw new \InvalidArgumentException('approval expected_metric must match the saved execution-intent metric');
        }

        $direction = strtolower(trim((string)($input['expected_direction'] ?? '')));
        $targetType = strtolower(trim((string)($input['target_type'] ?? '')));
        $observationOnly = $direction === 'observe' || $targetType === 'observation';
        if ($observationOnly) {
            if (!$isManagedObservation
                || $direction !== 'observe'
                || $targetType !== 'observation'
                || !$this->managedActionDeclaresObservationTarget($intent)
            ) {
                throw new \InvalidArgumentException(
                    'observation approval is allowed only for a managed verification target'
                );
            }
            foreach (['target_value', 'expected_delta'] as $field) {
                $raw = $input[$field] ?? null;
                if ($raw !== null && trim((string)$raw) !== '') {
                    throw new \InvalidArgumentException(
                        'observation approval must not declare a numeric target or expected delta'
                    );
                }
            }
        } else {
            if (!in_array($direction, ['increase', 'decrease'], true)) {
                throw new \InvalidArgumentException('approval expected_direction must be increase or decrease');
            }
            if (!in_array($targetType, ['absolute', 'delta'], true)) {
                throw new \InvalidArgumentException('approval target_type must be absolute or delta');
            }
        }

        $targetValue = is_array($intent['target_value'] ?? null) ? $intent['target_value'] : [];
        $evidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
        $baselineDate = substr(trim((string)($intent['date_end'] ?? $intent['date_start'] ?? '')), 0, 10);
        $reviewBusinessDate = substr(trim((string)($input['review_business_date'] ?? '')), 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $baselineDate) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $reviewBusinessDate) !== 1
        ) {
            throw new \InvalidArgumentException('baseline and review business dates must use YYYY-MM-DD');
        }
        $expectedReviewBusinessDate = (new DateTimeImmutable($baselineDate))
            ->modify('+1 day')
            ->format('Y-m-d');
        if (!$isManagedObservation && $reviewBusinessDate !== $expectedReviewBusinessDate) {
            throw new \InvalidArgumentException(
                'review_business_date must be exactly the next calendar business date: ' . $expectedReviewBusinessDate
            );
        }
        if ($isManagedObservation && $reviewBusinessDate < $expectedReviewBusinessDate) {
            throw new \InvalidArgumentException(
                'operating-question review_business_date must be later than the baseline business window'
            );
        }

        $intentPlatform = $this->normalizeOtaChannel((string)($intent['platform'] ?? ''));
        $currentValue = is_array($intent['current_value'] ?? null) ? $intent['current_value'] : [];
        $listExposureBaseline = $sourceMetric === 'list_exposure'
            && array_key_exists('list_exposure', $currentValue)
            && is_numeric($currentValue['list_exposure'])
            ? (float)$currentValue['list_exposure']
            : null;
        if (!$observationOnly
            && $sourceMetric === 'list_exposure'
            && ($intentPlatform !== 'ctrip'
                || $direction !== 'increase'
                || $listExposureBaseline === null
                || $listExposureBaseline < 0.0
                || floor($listExposureBaseline) !== $listExposureBaseline)
        ) {
            throw new \InvalidArgumentException(
                'list_exposure approval requires a Ctrip unique-user integer baseline and increase direction'
            );
        }

        $absoluteTarget = null;
        $expectedDelta = null;
        if ($observationOnly) {
            // A verification target freezes the metric and observation window,
            // not a fabricated uplift promise.
        } elseif ($targetType === 'absolute') {
            $rawTarget = $input['target_value'] ?? null;
            if (!is_numeric($rawTarget) || (float)$rawTarget < 0) {
                throw new \InvalidArgumentException('approval target_value must be a non-negative number');
            }
            $absoluteTarget = round((float)$rawTarget, 6);
        } else {
            $rawDelta = $input['expected_delta'] ?? null;
            if (!is_numeric($rawDelta) || (float)$rawDelta <= 0) {
                throw new \InvalidArgumentException('approval expected_delta must be a positive number');
            }
            $expectedDelta = round((float)$rawDelta, 6);
            if ($expectedDelta <= 0.0) {
                throw new \InvalidArgumentException('approval expected_delta must remain positive after 6-decimal normalization');
            }
        }
        if (!$observationOnly && $sourceMetric === 'list_exposure') {
            $approvedNumber = $targetType === 'absolute' ? $absoluteTarget : $expectedDelta;
            $projectedTarget = $targetType === 'absolute'
                ? $absoluteTarget
                : $listExposureBaseline + $expectedDelta;
            if ($approvedNumber === null
                || floor($approvedNumber) !== $approvedNumber
                || $approvedNumber <= 0.0
                || $projectedTarget === null
                || $projectedTarget <= $listExposureBaseline
                || $projectedTarget > 2147483647
            ) {
                throw new \InvalidArgumentException(
                    'list_exposure approval target must be a positive whole-user increase within the persisted integer range'
                );
            }
        }

        $workflowSchedule = is_array($targetValue['workflow_schedule'] ?? null)
            ? $targetValue['workflow_schedule']
            : [];
        $dueAt = trim((string)($workflowSchedule['due_at'] ?? $targetValue['due_at'] ?? ''));
        $existingReviewAt = trim((string)($workflowSchedule['review_at'] ?? $targetValue['review_at'] ?? ''));
        $reviewTime = preg_match('/\s(\d{2}:\d{2}:\d{2})$/D', $existingReviewAt, $matches) === 1
            ? $matches[1]
            : '10:00:00';
        $reviewAt = $reviewBusinessDate . ' ' . $reviewTime;
        if ($isManagedObservation
            && substr(trim((string)($workflowSchedule['review_at'] ?? '')), 0, 10) !== $reviewBusinessDate
        ) {
            throw new \InvalidArgumentException('managed action review date must match the approved workflow schedule');
        }
        if ($dueAt !== '' && strtotime($dueAt) !== false && strtotime($reviewAt) < strtotime($dueAt)) {
            throw new \InvalidArgumentException('review_business_date cannot be earlier than the execution due date');
        }
        $workflowSchedule['review_at'] = $reviewAt;
        $targetValue['review_at'] = $reviewAt;
        $targetValue['workflow_schedule'] = $workflowSchedule;

        $metricDefinition = $this->savedOtaDiagnosisMetricDefinition(
            $sourceMetric,
            $intentPlatform,
            $approvalSourceModule
        );
        $metricDefinitionDigest = $this->savedOtaDiagnosisMetricDefinitionDigest(
            $sourceMetric,
            $metricDefinition
        );
        $expectedDeltaStatus = $observationOnly ? 'observation_only' : 'manual_confirmed';
        $contract = [
            'version' => $observationOnly
                ? 'operation_observation_approval_target.v1'
                : 'ota_execution_approval_target.v1',
            'intent_id' => (int)($intent['id'] ?? 0),
            'tenant_id' => (int)($intent['tenant_id'] ?? 0),
            'hotel_id' => (int)($intent['hotel_id'] ?? 0),
            'source_module' => (string)($intent['source_module'] ?? ''),
            'source_record_id' => (int)($intent['source_record_id'] ?? 0),
            'platform' => strtolower(trim((string)($intent['platform'] ?? ''))),
            'baseline_business_date' => $baselineDate,
            'review_business_date' => $reviewBusinessDate,
            'expected_metric' => $sourceMetric,
            'metric_definition' => $metricDefinition,
            'metric_definition_digest' => $metricDefinitionDigest,
            'expected_direction' => $direction,
            'target_type' => $targetType,
            'target_value' => $absoluteTarget === null ? null : number_format($absoluteTarget, 6, '.', ''),
            'expected_delta' => $expectedDelta === null ? null : number_format($expectedDelta, 6, '.', ''),
            'expected_delta_status' => $expectedDeltaStatus,
            'approval_mode' => $approvalMode,
            'ai_review_digest' => $approvalMode === 'ai_independent_review' ? $aiReviewDigest : null,
            'approved_by' => $approvedBy,
            'approved_at' => $approvedAt,
            'diagnosis_recommendation_digest' => (string)($evidence['decision_recommendation_digest'] ?? ''),
            'source_policy' => match ($approvalSourceModule) {
                OperatingNetworkService::EXECUTION_SOURCE_MODULE =>
                    'operating_network_replication_and_human_target_frozen_before_task_creation',
                OperatingQuestionExecutionBridgeService::SOURCE_MODULE =>
                    $approvalMode === 'ai_independent_review'
                        ? ($observationOnly
                            ? 'operating_question_fact_reread_and_independent_ai_observation_frozen_before_task_creation'
                            : 'operating_question_fact_reread_and_independent_ai_target_frozen_before_task_creation')
                        : ($observationOnly
                            ? 'operating_question_fact_reread_and_human_observation_frozen_before_task_creation'
                            : 'operating_question_fact_reread_and_human_target_frozen_before_task_creation'),
                RevenueCockpitActionContract::SOURCE_MODULE =>
                    'revenue_cockpit_fact_reread_and_human_observation_frozen_before_task_creation',
                OperatingOpportunityLabService::DAILY_SOURCE_MODULE =>
                    'daily_one_thing_fact_snapshot_and_human_observation_frozen_before_task_creation',
                default => 'saved_diagnosis_metric_and_human_target_frozen_before_task_creation',
            },
        ];
        $contract['content_digest'] = $this->savedOtaDiagnosisApprovalTargetDigest($contract);

        $targetValue['expected_direction'] = $direction;
        $targetValue['target_type'] = $targetType;
        $targetValue['expected_delta_status'] = $expectedDeltaStatus;
        $targetValue['review_business_date'] = $reviewBusinessDate;
        $targetValue['metric_definition'] = $metricDefinition;
        $targetValue['metric_definition_digest'] = $metricDefinitionDigest;
        $targetValue['approval_target_digest'] = $contract['content_digest'];
        unset($targetValue['expected_target'], $targetValue['expected_delta'], $targetValue['target'], $targetValue['value']);
        if ($targetType === 'absolute') {
            $targetValue['expected_target'] = $absoluteTarget;
        } elseif ($targetType === 'delta') {
            $targetValue['expected_delta'] = $expectedDelta;
        }

        $evidence['expected_direction'] = $direction;
        $evidence['target_type'] = $targetType;
        $evidence['expected_delta_status'] = $expectedDeltaStatus;
        $evidence['target_value'] = $absoluteTarget;
        $evidence['expected_delta'] = $expectedDelta;
        $evidence['review_business_date'] = $reviewBusinessDate;
        $evidence['metric_definition'] = $metricDefinition;
        $evidence['metric_definition_digest'] = $metricDefinitionDigest;
        $evidence['workflow_schedule'] = $workflowSchedule;
        $evidence['approval_target'] = $contract;
        $evidence['approval_target_digest'] = $contract['content_digest'];

        return [
            'target_value' => $targetValue,
            'evidence' => $evidence,
            'expected_metric' => $sourceMetric,
            'expected_delta' => $expectedDelta,
        ];
    }

    private function assertSavedOtaDiagnosisApprovalTargetReadback(array $intent): void
    {
        $evidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
        $targetValue = is_array($intent['target_value'] ?? null) ? $intent['target_value'] : [];
        $contract = is_array($evidence['approval_target'] ?? null) ? $evidence['approval_target'] : [];
        $metricKey = strtolower(trim((string)($intent['expected_metric'] ?? '')));
        $metricDefinition = is_array($targetValue['metric_definition'] ?? null)
            ? $targetValue['metric_definition']
            : [];
        $metricDefinitionDigest = $metricDefinition === [] || $metricKey === ''
            ? ''
            : $this->savedOtaDiagnosisMetricDefinitionDigest($metricKey, $metricDefinition);
        $targetType = strtolower(trim((string)($contract['target_type'] ?? '')));
        $contractExpectedDelta = $contract['expected_delta'] ?? null;
        $intentExpectedDelta = $intent['expected_delta'] ?? null;
        $contractTargetValue = $contract['target_value'] ?? null;
        $persistedTargetValue = $targetValue['expected_target'] ?? null;
        $tasks = is_array($intent['tasks'] ?? null) ? $intent['tasks'] : [];
        $taskTargetValue = count($tasks) === 1 && is_array($tasks[0]['target_value'] ?? null)
            ? $tasks[0]['target_value']
            : [];
        $digest = strtolower(trim((string)($contract['content_digest'] ?? '')));
        if ($digest === ''
            || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1
            || !hash_equals($digest, $this->savedOtaDiagnosisApprovalTargetDigest($contract))
            || !hash_equals($digest, strtolower(trim((string)($evidence['approval_target_digest'] ?? ''))))
            || !hash_equals($digest, strtolower(trim((string)($targetValue['approval_target_digest'] ?? ''))))
            || $metricDefinitionDigest === ''
            || !hash_equals($metricDefinitionDigest, strtolower(trim((string)($contract['metric_definition_digest'] ?? ''))))
            || !hash_equals($metricDefinitionDigest, strtolower(trim((string)($targetValue['metric_definition_digest'] ?? ''))))
            || !hash_equals($metricDefinitionDigest, strtolower(trim((string)($evidence['metric_definition_digest'] ?? ''))))
            || $metricDefinition !== ($evidence['metric_definition'] ?? null)
            || $metricDefinition !== ($contract['metric_definition'] ?? null)
            || (string)($contract['expected_metric'] ?? '') !== (string)($intent['expected_metric'] ?? '')
            || (int)($contract['approved_by'] ?? 0) !== (int)($intent['approved_by'] ?? 0)
            || (string)($contract['approved_at'] ?? '') !== (string)($intent['approved_at'] ?? '')
            || (int)($contract['tenant_id'] ?? 0) !== (int)($intent['tenant_id'] ?? 0)
            || (int)($contract['hotel_id'] ?? 0) !== (int)($intent['hotel_id'] ?? 0)
            || ($targetType === 'delta'
                && (!is_numeric($contractExpectedDelta)
                    || !is_numeric($intentExpectedDelta)
                    || abs((float)$contractExpectedDelta - (float)$intentExpectedDelta) > 0.0000001))
            || ($targetType === 'absolute'
                && (!is_numeric($contractTargetValue)
                    || !is_numeric($persistedTargetValue)
                    || abs((float)$contractTargetValue - (float)$persistedTargetValue) > 0.0000001))
            || count($tasks) !== 1
            || !hash_equals($digest, strtolower(trim((string)($taskTargetValue['approval_target_digest'] ?? ''))))
            || $taskTargetValue !== $targetValue
        ) {
            throw new \RuntimeException('execution approval target save readback verification failed');
        }
    }

}
