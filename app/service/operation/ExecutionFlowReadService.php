<?php
declare(strict_types=1);

namespace app\service\operation;

use app\service\CanonicalOtaScheduledAnalysisAuthorizationService;
use Closure;

final class ExecutionFlowReadService
{
    /** @var Closure(array<string,mixed>,int,int,string):array<string,mixed> */
    private Closure $scheduledAuthorizationResolver;

    public function __construct(
        private readonly ExecutionOutcomeService $executionOutcomeService,
        ?callable $scheduledAuthorizationResolver = null
    ) {
        $this->scheduledAuthorizationResolver = $scheduledAuthorizationResolver !== null
            ? Closure::fromCallable($scheduledAuthorizationResolver)
            : static fn(array $authorization, int $tenantId, int $hotelId, string $platform): array =>
                (new CanonicalOtaScheduledAnalysisAuthorizationService())->assertMatches(
                    $authorization,
                    $tenantId,
                    $hotelId,
                    $platform
                );
    }

    /** @param array<string, mixed> $intent @param array<string, mixed> $task */
    public function taskMatchesIntent(array $intent, array $task): bool
    {
        $intentId = (int)($intent['id'] ?? 0);
        $taskIntentId = (int)($task['intent_id'] ?? 0);
        if ($intentId > 0 && $taskIntentId > 0 && $intentId !== $taskIntentId) {
            return false;
        }

        $intentHotelId = (int)($intent['hotel_id'] ?? 0);
        $taskHotelId = (int)($task['hotel_id'] ?? 0);
        if ($intentHotelId > 0 && $taskHotelId > 0 && $intentHotelId !== $taskHotelId) {
            return false;
        }

        return $this->tenantIdentityMatches($intent, $task);
    }

    /** @param array<string, mixed> $task @param array<string, mixed> $evidence */
    public function evidenceMatchesTask(array $task, array $evidence): bool
    {
        $taskId = (int)($task['id'] ?? 0);
        $evidenceTaskId = (int)($evidence['task_id'] ?? 0);
        if ($taskId > 0 && $evidenceTaskId > 0 && $taskId !== $evidenceTaskId) {
            return false;
        }

        return $this->tenantIdentityMatches($task, $evidence);
    }

    /** Project already strictly read reviews onto this exact task's current result. */
    public function projectEffectReviews(array $intent, array $task, array $reviews, string $readStatus = 'readback_verified'): array
    {
        $reviews = array_values(array_filter($reviews, static fn(array $review): bool =>
            (int)($review['tenant_id'] ?? 0) === (int)($task['tenant_id'] ?? $intent['tenant_id'] ?? 0)
            && (int)($review['hotel_id'] ?? 0) === (int)($task['hotel_id'] ?? 0)
            && (int)($review['intent_id'] ?? 0) === (int)($task['intent_id'] ?? 0)
            && (int)($review['task_id'] ?? 0) === (int)($task['id'] ?? 0)
        ));
        usort($reviews, static fn(array $a, array $b): int => (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));
        $verified = array_values(array_filter($reviews, static fn(array $review): bool =>
            ($review['readback_verified'] ?? false) === true
            && ($review['approval_contract_verified'] ?? false) === true
            && ($review['active_eligible'] ?? false) === true
            && ($review['outcome']['source_verified'] ?? false) === true
            && ($review['outcome']['outcome_verified'] ?? false) === true
            && ($review['causality_claimed'] ?? true) === false
        ));
        $drifted = count(array_filter($reviews, static fn(array $review): bool =>
            ($review['approval_contract_verified'] ?? false) !== true
        )) > 0;
        $active = null;
        if ($readStatus === 'readback_verified') {
            foreach ($verified as $review) {
                if ((string)($review['result_status'] ?? '') === (string)($task['result_status'] ?? '')
                    && (string)($review['result_summary'] ?? '') === (string)($task['result_summary'] ?? '')
                ) {
                    $active = $review;
                    break;
                }
            }
        }
        $strict = in_array(strtolower(trim((string)($intent['source_module'] ?? ''))), [
            'ota_diagnosis_saved',
            \app\service\OperatingQuestionExecutionBridgeService::SOURCE_MODULE,
            \app\service\RevenueCockpitActionContract::SOURCE_MODULE,
            \app\service\OperatingOpportunityLabService::DAILY_SOURCE_MODULE,
        ], true);
        $terminal = in_array((string)($task['result_status'] ?? ''), ['success', 'near_success', 'failed'], true);
        return [
            'effect_reviews' => $reviews,
            'active_effect_review' => $active,
            'strict_required' => $strict,
            'approval_contract_drifted' => $drifted,
            'effect_review_summary' => [
                'count' => count($reviews),
                'verified_count' => count($verified),
                'approval_contract_verified_count' => count(array_filter($reviews, static fn(array $review): bool =>
                    ($review['approval_contract_verified'] ?? false) === true
                )),
                'latest_id' => (int)($reviews[0]['id'] ?? 0),
                'active_id' => (int)($active['id'] ?? 0),
                'active_content_digest' => (string)($active['content_digest'] ?? ''),
                'current_result_bound' => $active !== null,
                'persistence_status' => $readStatus !== 'readback_verified' ? $readStatus
                    : ($active !== null ? 'readback_verified'
                        : ($drifted ? 'approval_target_drifted' : ($reviews === [] ? 'missing' : 'current_result_mismatch'))),
                'execution_effect_storage_separated' => true,
                'causality_claimed' => false,
            ],
            'outcome_truth' => $strict && $terminal ? ($active['outcome'] ?? [
                'status' => 'unverified',
                'source_verified' => false,
                'outcome_verified' => false,
                'positive_outcome_verified' => false,
                'metric_key' => (string)($intent['expected_metric'] ?? ''),
                'before_value' => null,
                'after_value' => null,
                'actual_delta' => null,
                'favorable_delta' => null,
                'target_value' => null,
                'expected_delta' => null,
                'target_delta' => null,
                'actual_change_rate' => null,
                'progress_rate' => null,
                'failure_reason' => $readStatus === 'readback_failed' ? 'separate_effect_review_readback_failed'
                    : ($drifted ? 'approval_target_contract_drifted' : 'current_separate_effect_review_missing'),
                'causality_claimed' => false,
            ]) : null,
        ];
    }

    public function applyEffectReviewSopGate(array $candidate, array $projection): array
    {
        if (($projection['strict_required'] ?? false) === true && ($projection['active_effect_review'] ?? null) === null) {
            $drifted = ($projection['approval_contract_drifted'] ?? false) === true;
            $reasons = is_array($candidate['reason_codes'] ?? null) ? $candidate['reason_codes'] : [];
            $reasons[] = $drifted ? 'approval_target_contract_drifted' : 'separate_effect_review_readback_missing';
            $candidate['candidate_id'] = null;
            $candidate['status'] = 'not_ready';
            $candidate['approval_status'] = 'not_available';
            $candidate['reason_codes'] = array_values(array_unique($reasons));
            $candidate['boundaries']['next_stage'] = $drifted
                ? 'reapprove_target_and_repeat_effect_review' : 'complete_separate_effect_review';
        }
        return $candidate;
    }

    public function buildItem(array $intent, array $tasks = [], array $evidence = [], ?array $effectReviewReadback = null): array
    {
        $inputTaskCount = count($tasks);
        $tasks = array_values(array_filter(
            $tasks,
            fn(array $task): bool => $this->taskMatchesIntent($intent, $task)
        ));
        $tasksById = [];
        foreach ($tasks as $candidateTask) {
            $candidateTaskId = (int)($candidateTask['id'] ?? 0);
            if ($candidateTaskId > 0) {
                $tasksById[$candidateTaskId] = $candidateTask;
            }
        }
        $inputEvidenceCount = count($evidence);
        $evidence = array_values(array_filter(
            $evidence,
            function (array $row) use ($tasksById): bool {
                $taskId = (int)($row['task_id'] ?? 0);
                return $taskId > 0
                    && isset($tasksById[$taskId])
                    && $this->evidenceMatchesTask($tasksById[$taskId], $row);
            }
        ));
        $identityGapCount = ($inputTaskCount - count($tasks))
            + ($inputEvidenceCount - count($evidence));

        usort($tasks, static fn(array $a, array $b): int => (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));
        usort($evidence, static fn(array $a, array $b): int => (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));

        $task = $this->latestTask($tasks);
        $taskId = (int)($task['id'] ?? 0);
        $taskEvidence = $taskId > 0
            ? array_values(array_filter(
                $evidence,
                static fn(array $row): bool => (int)($row['task_id'] ?? 0) === $taskId
            ))
            : [];
        $latestEvidence = $taskEvidence[0] ?? [];
        $longitudinalReview = $this->latestLongitudinalReview($taskEvidence);
        $roiEvidence = $this->executionOutcomeService->latestExecutionRoiEvidence($taskEvidence);
        $evidenceSummary = $this->buildSafeEvidenceSummary($taskEvidence, $task, $intent);
        $reviewStatus = (string)($task['result_status'] ?? 'observing');
        $evidenceTruth = $this->executionOutcomeService->buildExecutionEvidenceTruth(
            $intent,
            $task,
            $taskEvidence
        );
        $outcomeTruth = $this->executionOutcomeService->buildExecutionOutcomeTruth(
            $intent,
            $task,
            $taskEvidence
        );
        if (is_array($effectReviewReadback['outcome_truth'] ?? null)) {
            $outcomeTruth = $effectReviewReadback['outcome_truth'];
        }
        $roiBinding = [];
        $roiBindingFailure = null;
        $activeReview = $effectReviewReadback['active_effect_review'] ?? null;
        if (($effectReviewReadback['strict_required'] ?? false) === true && is_array($activeReview)) {
            $sourceId = (int)($activeReview['source_readback_evidence_id'] ?? 0);
            $roiBinding = [
                'effect_review_id' => (int)$activeReview['id'],
                'effect_review_content_digest' => (string)$activeReview['content_digest'],
                'source_readback_evidence_id' => $sourceId,
                'basis' => 'frozen_effect_review_source',
            ];
            $roiEvidence = [];
            foreach ($taskEvidence as $row) {
                if ((int)($row['id'] ?? 0) === $sourceId) {
                    $roiEvidence = $row;
                    break;
                }
            }
            $roiBinding['source_readback_present'] = $roiEvidence !== [];
            if ($roiEvidence === []) {
                $roiBindingFailure = 'frozen_effect_roi_source_missing';
            } elseif (!in_array((string)($activeReview['metric_key'] ?? ''), ['revenue', 'avg_revenue', 'amount', 'income'], true)) {
                $roiBindingFailure = 'frozen_effect_roi_revenue_not_reviewed';
            } else {
                try {
                    (new OperationEffectReviewService())->assertFrozenSourceReadback($activeReview, $intent, $task, $roiEvidence);
                    // ROI must use the exact reviewed metric, not a different alias in the same row.
                    foreach (['before', 'after'] as $key) {
                        $roiEvidence[$key]['revenue'] = $roiEvidence[$key][$activeReview['metric_key']];
                    }
                } catch (\InvalidArgumentException) {
                    $roiBindingFailure = 'frozen_effect_roi_source_changed';
                }
            }
            if ($roiBindingFailure !== null) $roiEvidence = [];
            $roiBinding['source_binding_verified'] = $roiBindingFailure === null;
        }
        $roiCostFailure = null;
        if ($roiBinding !== [] && $roiBindingFailure === null) {
            $frozenCost = $activeReview['outcome']['roi_cost_basis'] ?? null;
            $currentCost = $this->executionOutcomeService->executionRoiCostBasis($intent, $task, $roiEvidence);
            $roiBinding['cost_basis'] = $frozenCost;
            if (!$this->executionOutcomeService->validExecutionRoiCostBasis($frozenCost)) {
                $roiCostFailure = 'frozen_effect_roi_cost_not_frozen';
            } elseif ($frozenCost != $currentCost) {
                $roiCostFailure = 'frozen_effect_roi_cost_changed';
            } elseif ($frozenCost['status'] === 'non_finite'
                || $frozenCost['measurement_kind'] === 'plan_reference') {
                $roiCostFailure = 'frozen_effect_roi_cost_unverified';
            }
            $roiBinding['cost_binding_verified'] = $roiCostFailure === null && $frozenCost['status'] === 'finite';
            $roiBinding['cost_validation_status'] = $roiCostFailure ?? $frozenCost['status'];
            $roiBinding['cost_reassessment'] = [
                'enabled'=>in_array($reviewStatus, ['success', 'near_success', 'failed'], true)
                    && $roiCostFailure !== null && $currentCost['status'] === 'finite'
                    && $currentCost['measurement_kind'] === 'source_reported_cost',
                'roi_cost_basis'=>$currentCost,
            ];
        }
        $roiEvidenceTruth = $this->executionOutcomeService->buildExecutionEvidenceTruth(
            $intent,
            $task,
            $roiEvidence === [] ? [] : [$roiEvidence]
        );
        $truthContext = $this->executionOutcomeService->buildExecutionTruthContext(
            $intent,
            $task,
            $evidenceTruth,
            $reviewStatus,
            $outcomeTruth
        );
        $displayReviewStatus = $reviewStatus;
        if (in_array($reviewStatus, ['success', 'near_success'], true)
            && (
                ($evidenceTruth['source_verified'] ?? false) !== true
                || !$this->executionOutcomeService->executionPositiveOutcomeAllowsStatus(
                    $outcomeTruth,
                    $reviewStatus
                )
            )
        ) {
            $displayReviewStatus = 'unverified';
        }
        $sourceModule = (string)($intent['source_module'] ?? 'manual');
        $sourceRecordId = (int)($intent['source_record_id'] ?? 0);
        $assignment = $this->buildWorkflowAssignment($intent);
        $reviewAvailableOn = $this->reviewAvailableOn($taskEvidence);
        if (strtolower(trim($sourceModule)) === 'operation_optimizer'
            && trim((string)($task['executed_at'] ?? '')) !== ''
        ) {
            $executedAt = strtotime((string)$task['executed_at']);
            if ($executedAt !== false) {
                $intentEvidence = $this->arrayValue($intent['evidence'] ?? []);
                $reviewPolicy = $this->arrayValue($intentEvidence['review_policy'] ?? []);
                $reviewWindow = $this->arrayValue($reviewPolicy['review_window'] ?? []);
                $reviewWindowDays = max(1, min(90, (int)($reviewWindow['length_days'] ?? 1)));
                $sameLengthReviewDate = date(
                    'Y-m-d',
                    strtotime('+' . $reviewWindowDays . ' days', $executedAt)
                );
                if ($reviewAvailableOn === '' || $sameLengthReviewDate > $reviewAvailableOn) {
                    $reviewAvailableOn = $sameLengthReviewDate;
                }
            }
        }
        $reviewAvailableAt = '';
        $scheduledReviewAt = trim((string)($assignment['review_at'] ?? ''));
        $scheduledReviewTimestamp = $scheduledReviewAt !== '' ? strtotime($scheduledReviewAt) : false;
        if ($scheduledReviewTimestamp !== false) {
            $reviewAvailableAt = date('Y-m-d H:i:s', $scheduledReviewTimestamp);
        }
        if ($reviewAvailableOn !== '') {
            $dateFallback = $reviewAvailableOn . ' 00:00:00';
            if ($reviewAvailableAt === '' || substr($reviewAvailableAt, 0, 10) < $reviewAvailableOn) {
                $reviewAvailableAt = $dateFallback;
            }
        }
        if ($reviewAvailableAt !== '') {
            $reviewAvailableOn = substr($reviewAvailableAt, 0, 10);
        }
        $reviewAvailableTimestamp = $reviewAvailableAt !== '' ? strtotime($reviewAvailableAt) : false;
        $reviewIsAvailable = $reviewAvailableAt === ''
            || ($reviewAvailableTimestamp !== false && time() >= $reviewAvailableTimestamp);
        $stage = $this->stage($intent, $task, $evidenceTruth, $reviewStatus, $outcomeTruth);
        $sopCandidate = $this->buildSopCandidate(
            $intent,
            $task,
            $evidenceTruth,
            $outcomeTruth,
            $reviewStatus
        );
        if ($effectReviewReadback !== null) {
            $sopCandidate = $this->applyEffectReviewSopGate($sopCandidate, $effectReviewReadback);
        }

        $roi = $this->executionOutcomeService->buildExecutionRoi($intent, $task, $roiEvidence, $roiEvidenceTruth, $outcomeTruth);
        $nextAction = $this->buildNextAction($stage, $intent, $task, $displayReviewStatus);
        if ($roiBinding !== []) $roi['effect_review_binding'] = $roiBinding;
        if ($roiBindingFailure !== null) {
            if ($stage === 'reviewed') $stage = 'review';
            $roi['failure_reason'] = $roiBindingFailure;
            $roi['evidence_truth']['evidence_count'] = $roiBinding['source_readback_present'] ? 1 : 0;
            $roi['evidence_truth']['failure_reason'] = $roiBindingFailure;
            $roi['evidence_truth']['failure_reasons'] = [$roiBindingFailure];
            $roi['message'] = match ($roiBindingFailure) {
                'frozen_effect_roi_source_missing' => '冻结复盘的收益来源未读回，收益未确认',
                'frozen_effect_roi_revenue_not_reviewed' => '冻结复盘尚未核验收益指标，收益未确认',
                default => '收益来源与冻结复盘的值、日期或引用不一致，收益未确认',
            };
            $nextAction = ['key'=>'refresh_readback','label'=>'刷新核验收益','reason'=>$roiBindingFailure];
        }
        if ($roiCostFailure !== null) {
            // A failed expense input does not erase the independently verified revenue.
            foreach (['value', 'cost', 'profit', 'formula'] as $key) $roi[$key] = null;
            foreach (['value', 'cost', 'profit'] as $key) $roi['metric_failures'][$key] = $roiCostFailure;
            $roi['status'] = 'partial';
            $roi['unit'] = '%';
            $roi['failure_reason'] = $roiCostFailure;
            $roi['message'] = match ($roiCostFailure) {
                'frozen_effect_roi_cost_changed' => '费用来源与冻结复盘不一致，成本、利润及ROI未确认',
                'frozen_effect_roi_cost_not_frozen' => '历史复盘未冻结费用基础，成本、利润及ROI未确认',
                default => '费用基础不可核验，成本、利润及ROI未确认',
            };
            if ($stage === 'reviewed') $stage = 'review';
            $nextAction = ['key'=>'refresh_readback','label'=>'刷新核验费用','reason'=>$roiCostFailure];
        }

        return [
            'id' => (int)$intent['id'],
            'hotel_id' => (int)$intent['hotel_id'],
            'tenant_id' => isset($intent['tenant_id']) && (int)$intent['tenant_id'] > 0 ? (int)$intent['tenant_id'] : null,
            'source_kind' => is_string($intent['source_kind'] ?? null) ? $intent['source_kind'] : null,
            'stage' => $stage,
            'identity' => [
                'status' => $identityGapCount === 0 ? 'consistent' : 'mismatch_excluded',
                'gap_count' => $identityGapCount,
            ],
            'recommendation' => [
                'source' => $sourceModule . '#' . $sourceRecordId,
                'source_module' => $sourceModule,
                'source_record_id' => $sourceRecordId,
                'platform' => (string)($intent['platform'] ?? ''),
                'object_type' => (string)($intent['object_type'] ?? ''),
                'action_type' => (string)($intent['action_type'] ?? ''),
                'date_start' => (string)($intent['date_start'] ?? ''),
                'date_end' => (string)($intent['date_end'] ?? ''),
                'expected_metric' => (string)($intent['expected_metric'] ?? ''),
                'expected_delta' => is_numeric($intent['expected_delta'] ?? null)
                    && is_finite((float)$intent['expected_delta'])
                    ? (float)$intent['expected_delta']
                    : null,
                'risk_level' => (string)($intent['risk_level'] ?? ''),
                'current_value' => $intent['current_value'] ?? [],
                'target_value' => $intent['target_value'] ?? [],
                'evidence' => $intent['evidence'] ?? [],
                'created_at' => (string)($intent['created_at'] ?? ''),
            ],
            'approval' => [
                'status' => (string)($intent['status'] ?? ''),
                'approved_by' => (int)($intent['approved_by'] ?? 0),
                'approved_at' => (string)($intent['approved_at'] ?? ''),
                'remark' => (string)($intent['review_remark'] ?? ''),
                'blocked_reason' => (string)($intent['blocked_reason'] ?? ''),
            ],
            'execution' => [
                'task_id' => $taskId,
                'mode' => (string)($task['execution_mode'] ?? ''),
                'status' => (string)($task['status'] ?? 'pending_create'),
                'operator_id' => (int)($task['operator_id'] ?? 0),
                'executed_at' => (string)($task['executed_at'] ?? ''),
                'blocked_reason' => (string)($task['blocked_reason'] ?? ''),
                'target_value' => $task['target_value'] ?? [],
                'current_value' => $task['current_value'] ?? [],
            ],
            'assignment' => $assignment,
            'evidence' => [
                'count' => count($taskEvidence),
                'operator_attested_count' => (int)($evidenceTruth['operator_attested_count'] ?? 0),
                'source_verified_count' => (int)($evidenceTruth['source_verified_count'] ?? 0),
                'latest' => $latestEvidence,
                'longitudinal_review' => $longitudinalReview,
            ],
            'evidence_summary' => $evidenceSummary,
            'evidence_truth' => $evidenceTruth,
            'outcome_truth' => $outcomeTruth,
            'effect_review_summary' => $effectReviewReadback['effect_review_summary'] ?? null,
            'truth_context' => $truthContext,
            'review' => [
                'status' => $displayReviewStatus,
                'reported_status' => $reviewStatus,
                'truth_status' => (string)($truthContext['status'] ?? 'unverified'),
                'failure_reason' => $truthContext['failure_reason'] ?? null,
                'summary' => (string)($task['result_summary'] ?? ''),
                'action_track_id' => (int)($task['action_track_id'] ?? 0),
                'available_at' => $reviewAvailableAt,
                'available_on' => $reviewAvailableOn,
                'is_available' => $reviewIsAvailable,
            ],
            'sop_candidate' => $sopCandidate,
            'roi' => $roi,
            'next_action' => $nextAction,
        ];
    }

    /**
     * Build a review-backed SOP candidate without publishing or applying it.
     * Human proof establishes that the action was carried out; persisted OTA
     * readback separately establishes the observed result. One successful
     * review may create a candidate, but never an approved SOP or a cross-hotel
     * replication plan.
     *
     * @param array<string, mixed> $intent
     * @param array<string, mixed> $task
     * @param array<string, mixed> $evidenceTruth
     * @param array<string, mixed> $outcomeTruth
     * @return array<string, mixed>
     */
    public function buildSopCandidate(
        array $intent,
        array $task,
        array $evidenceTruth,
        array $outcomeTruth,
        string $reviewStatus
    ): array {
        $intentId = max(0, (int)($intent['id'] ?? 0));
        $taskId = max(0, (int)($task['id'] ?? 0));
        $hotelId = max(0, (int)($intent['hotel_id'] ?? $task['hotel_id'] ?? 0));
        $reviewStatus = strtolower(trim($reviewStatus));
        $reasonCodes = [];

        if ((string)($intent['status'] ?? '') !== 'approved') {
            $reasonCodes[] = 'execution_intent_not_approved';
        }
        if ($taskId <= 0 || (string)($task['status'] ?? '') !== 'executed') {
            $reasonCodes[] = 'execution_not_completed';
        }
        if (($evidenceTruth['operator_attested'] ?? false) !== true) {
            $reasonCodes[] = 'operator_execution_evidence_missing';
        }
        if (!in_array($reviewStatus, ['success', 'near_success'], true)) {
            $reasonCodes[] = $reviewStatus === 'failed'
                ? 'review_not_positive'
                : 'operator_review_pending';
        }
        if (($evidenceTruth['source_verified'] ?? false) !== true) {
            $reasonCodes[] = 'source_verified_metric_readback_missing';
        }
        if (($outcomeTruth['outcome_verified'] ?? false) !== true) {
            $reasonCodes[] = 'review_outcome_unverified';
        }
        if (($outcomeTruth['positive_outcome_verified'] ?? false) !== true) {
            $reasonCodes[] = 'positive_outcome_unverified';
        }
        $reasonCodes = array_values(array_unique($reasonCodes));
        $ready = $reasonCodes === [];

        $targetValue = $this->arrayValue($intent['target_value'] ?? []);
        $actionText = trim((string)(
            $targetValue['action_text']
            ?? $targetValue['action']
            ?? $intent['action_type']
            ?? ''
        ));
        $sourceRefs = [];
        foreach ((array)($evidenceTruth['assessments'] ?? []) as $assessment) {
            if (!is_array($assessment) || ($assessment['source_verified'] ?? false) !== true) {
                continue;
            }
            $sourceRef = trim((string)($assessment['source_ref'] ?? ''));
            if ($sourceRef !== '') {
                $sourceRefs[$sourceRef] = true;
            }
        }

        $candidateIdentity = implode('|', [
            $intentId,
            $taskId,
            $hotelId,
            strtolower(trim((string)($intent['platform'] ?? ''))),
            strtolower(trim((string)($intent['action_type'] ?? ''))),
            substr(trim((string)($intent['date_start'] ?? '')), 0, 10),
        ]);

        return [
            'schema_version' => 'operation_sop_candidate.v1',
            'candidate_id' => $ready
                ? 'sop_candidate_' . substr(hash('sha256', $candidateIdentity), 0, 24)
                : null,
            'status' => $ready ? 'candidate' : 'not_ready',
            'approval_status' => $ready ? 'pending_approval' : 'not_available',
            'reason_codes' => $reasonCodes,
            'source' => [
                'intent_ref' => $intentId > 0 ? 'operation_execution_intent#' . $intentId : null,
                'task_ref' => $taskId > 0 ? 'operation_execution_task#' . $taskId : null,
                'metric_readback_refs' => array_keys($sourceRefs),
            ],
            'scope' => [
                'hotel_id' => $hotelId,
                'platform' => strtolower(trim((string)($intent['platform'] ?? ''))),
                'metric_scope' => 'ota_channel',
                'business_date' => substr(trim((string)($intent['date_start'] ?? '')), 0, 10),
                'date_end' => substr(trim((string)($intent['date_end'] ?? '')), 0, 10),
            ],
            'action' => [
                'action_type' => strtolower(trim((string)($intent['action_type'] ?? ''))),
                'action_text' => $actionText,
                'execution_evidence_status' => ($evidenceTruth['operator_attested'] ?? false) === true
                    ? 'operator_attested'
                    : 'missing',
            ],
            'review' => [
                'result_status' => $reviewStatus,
                'result_summary' => trim((string)($task['result_summary'] ?? '')),
                'metric_key' => strtolower(trim((string)($outcomeTruth['metric_key'] ?? $intent['expected_metric'] ?? ''))),
                'before_value' => is_numeric($outcomeTruth['before_value'] ?? null)
                    ? (float)$outcomeTruth['before_value']
                    : null,
                'after_value' => is_numeric($outcomeTruth['after_value'] ?? null)
                    ? (float)$outcomeTruth['after_value']
                    : null,
                'outcome_status' => (string)($outcomeTruth['status'] ?? 'unverified'),
                'source_verified' => ($evidenceTruth['source_verified'] ?? false) === true,
                'positive_outcome_verified' => ($outcomeTruth['positive_outcome_verified'] ?? false) === true,
                'causality_claimed' => false,
            ],
            'boundaries' => [
                'automatic_publish_enabled' => false,
                'automatic_apply_enabled' => false,
                'cross_hotel_replication_allowed' => false,
                'next_stage' => $ready ? 'manual_sop_approval' : 'complete_review_evidence',
            ],
        ];
    }

    public function buildSummary(array $items): array
    {
        $stageCounts = [
            'recommendation' => 0,
            'approval' => 0,
            'execution' => 0,
            'evidence' => 0,
            'review' => 0,
            'reviewed' => 0,
            'blocked' => 0,
            'rejected' => 0,
            'cancelled' => 0,
            'failed' => 0,
        ];
        $roiPercentValues = [];
        $revenueLiftValues = [];
        $profitable = 0;
        $approved = 0;
        $authorized = 0;
        $systemAuthorizedAnalysis = 0;
        $executed = 0;
        $operatorReportedExecuted = 0;
        $sourceVerifiedExecuted = 0;
        $operatorAttested = 0;
        $evidenceReady = 0;
        $incrementalRevenueValues = [];
        $costValues = [];
        $profitValues = [];
        $profitReady = 0;

        foreach ($items as $item) {
            $stage = (string)($item['stage'] ?? 'recommendation');
            if (!array_key_exists($stage, $stageCounts)) {
                $stageCounts[$stage] = 0;
            }
            $stageCounts[$stage]++;

            if (($item['approval']['status'] ?? '') === 'approved') {
                $approved++;
            }
            $executionReported = ($item['execution']['status'] ?? '') === 'executed';
            $approvalReady = $this->itemExecutionAuthorized($item);
            if ($approvalReady) {
                $authorized++;
            }
            if (($item['approval']['status'] ?? '') === 'system_authorized_analysis') {
                $systemAuthorizedAnalysis++;
            }
            if ($executionReported) {
                $operatorReportedExecuted++;
            }
            if ($approvalReady && $executionReported) {
                $executed++;
            }
            if (($item['evidence_truth']['operator_attested'] ?? false) === true) {
                $operatorAttested++;
            }
            $sourceVerified = ($item['evidence_truth']['source_verified'] ?? false) === true;
            if ($sourceVerified) {
                $evidenceReady++;
                if ($approvalReady && $executionReported) {
                    $sourceVerifiedExecuted++;
                }
            }
            $roiStatus = (string)($item['roi']['status'] ?? '');
            $financialPartial = $roiStatus === 'partial' && (!empty($item['roi']['metric_failures'])
                || in_array($item['roi']['failure_reason'] ?? '', [
                    'revenue_evidence_missing', 'cost_evidence_missing', 'roi_cost_denominator_non_positive',
                ], true));
            if ($sourceVerified && ($roiStatus === 'ready' || $financialPartial)) {
                $unit = (string)($item['roi']['unit'] ?? ($roiStatus === 'ready' ? '%' : ''));
                $value = $this->financialSample($item, 'value');
                if ($unit === 'amount') {
                    $revenueLiftValues[] = $value;
                } elseif ($unit === '%') {
                    $roiPercentValues[] = $value;
                }
                $incrementalRevenueValues[] = $this->financialSample($item, 'incremental_revenue');
                $costValues[] = $this->financialSample($item, 'cost');
                $profitValues[] = $this->financialSample($item, 'profit');
                if (is_numeric($item['roi']['profit'] ?? null) && is_finite((float)$item['roi']['profit'])) {
                    $profit = (float)$item['roi']['profit'];
                    $profitReady++;
                    if ($profit > 0) {
                        $profitable++;
                    }
                }
            }
        }

        $total = count($items);
        $roiPercentReady = count(array_filter($roiPercentValues, static fn(array $sample): bool => $sample['roi_ready']));
        $revenueLiftReady = count(array_filter($revenueLiftValues, static fn(array $sample): bool => $sample['roi_ready']));
        $roiReady = $roiPercentReady + $revenueLiftReady;
        $financialMetrics = [
            'avg_roi' => $this->aggregateFinancialSamples($roiPercentValues, '%', true),
            'avg_revenue_lift' => $this->aggregateFinancialSamples($revenueLiftValues, 'amount', true),
            'total_incremental_revenue' => $this->aggregateFinancialSamples($incrementalRevenueValues, 'amount'),
            'total_cost' => $this->aggregateFinancialSamples($costValues, 'amount'),
            'total_profit' => $this->aggregateFinancialSamples($profitValues, 'amount'),
        ];
        $moneyProfit = $financialMetrics['total_profit']['unrounded_value'];
        foreach ($financialMetrics as &$metric) unset($metric['unrounded_value']);
        unset($metric);

        return [
            'total' => $total,
            'stage_counts' => $stageCounts,
            'bottleneck' => $this->buildBottleneck($stageCounts),
            'approved' => $approved,
            'authorized' => $authorized,
            'system_authorized_analysis' => $systemAuthorizedAnalysis,
            'executed' => $executed,
            'operator_reported_executed' => $operatorReportedExecuted,
            'source_verified_executed' => $sourceVerifiedExecuted,
            'operator_attested' => $operatorAttested,
            'evidence_ready' => $evidenceReady,
            'source_verified' => $evidenceReady,
            'roi_ready' => $roiReady,
            'roi_percent_ready' => $roiPercentReady,
            'revenue_lift_ready' => $revenueLiftReady,
            'avg_roi' => $financialMetrics['avg_roi']['value'],
            'avg_revenue_lift' => $financialMetrics['avg_revenue_lift']['value'],
            'approval_rate' => $total > 0 ? round($approved / $total * 100, 2) : null,
            'authorization_rate' => $total > 0 ? round($authorized / $total * 100, 2) : null,
            'execution_rate' => $total > 0 ? round($executed / $total * 100, 2) : null,
            'operator_reported_execution_rate' => $total > 0
                ? round($operatorReportedExecuted / $total * 100, 2)
                : null,
            'evidence_rate' => $total > 0 ? round($evidenceReady / $total * 100, 2) : null,
            'roi_ready_rate' => $total > 0 ? round($roiReady / $total * 100, 2) : null,
            'profitable' => $profitable,
            'profitable_rate' => $profitReady > 0 ? round($profitable / $profitReady * 100, 2) : null,
            'total_incremental_revenue' => $financialMetrics['total_incremental_revenue']['value'],
            'total_cost' => $financialMetrics['total_cost']['value'],
            'total_profit' => $financialMetrics['total_profit']['value'],
            'financial_metrics' => $financialMetrics,
            'money_status' => $this->moneyStatus($roiReady, $moneyProfit, $profitValues !== []),
        ];
    }

    private function financialSample(array $item, string $field): array
    {
        $truth = (array)($item['evidence_truth'] ?? []);
        $refs = [];
        foreach (array_merge([$truth], (array)($truth['assessments'] ?? [])) as $assessment) {
            if (is_array($assessment) && is_string($assessment['source_ref'] ?? null)
                && trim($assessment['source_ref']) !== '') {
                $refs[] = $assessment['source_ref'];
            }
        }
        return ['value' => $item['roi'][$field] ?? null, 'item_id' => (int)($item['id'] ?? 0),
            'roi_ready' => ($item['roi']['status'] ?? '') === 'ready',
            'failure_reason' => $item['roi']['metric_failures'][$field] ?? null,
            'source_refs' => array_values(array_unique($refs)),
            'scope' => array_replace(
                array_intersect_key($item, array_flip(['tenant_id', 'hotel_id', 'platform', 'date_start', 'date_end', 'source_kind'])),
                array_intersect_key((array)($item['recommendation'] ?? []), array_flip(['platform', 'date_start', 'date_end']))
            )];
    }

    /** A finite mean can exist even when its intermediate sum exceeds the float range. */
    private function aggregateFinancialSamples(array $samples, string $unit, bool $average = false): array
    {
        $result = ['value' => null, 'unrounded_value' => null, 'unit' => $unit, 'status' => 'missing',
            'failure_reason' => $samples === [] ? 'no_roi_samples' : null,
            'item_ids' => array_values(array_unique(array_column($samples, 'item_id'))),
            'source_refs' => [], 'scopes' => array_column($samples, 'scope')];
        $values = [];
        foreach ($samples as $sample) {
            $result['source_refs'] = array_values(array_unique(array_merge($result['source_refs'], $sample['source_refs'])));
            if (!is_numeric($sample['value'])) {
                if (in_array($sample['failure_reason'], ['roi_input_non_finite', 'roi_calculation_overflow'], true)) {
                    $result['failure_reason'] = 'aggregate_item_calculation_failed';
                    $result['status'] = 'calculation_failed';
                    $result['item_failures'][(string)$sample['item_id']] = $sample['failure_reason'];
                } else {
                    $result['failure_reason'] ??= 'aggregate_input_missing';
                }
            } elseif (!is_finite((float)$sample['value'])) {
                $result['failure_reason'] = 'aggregate_input_non_finite';
                $result['status'] = 'non_finite';
            } else {
                $values[] = (float)$sample['value'];
            }
        }
        if ($result['failure_reason'] !== null) return $result;
        $sum = $this->financialSum($values);
        $value = $sum === null ? NAN : ($average ? $sum / count($values) : $sum);
        if (!is_finite($value)) {
            $scale = max(array_map('abs', $values));
            $normalizedValues = [];
            foreach ($values as $v) {
                $normalizedValue = $v / $scale / ($average ? count($values) : 1);
                if ($v !== 0.0 && $normalizedValue === 0.0) {
                    $result['status'] = 'precision_loss';
                    $result['failure_reason'] = 'aggregate_precision_loss';
                    return $result;
                }
                $normalizedValues[] = $normalizedValue;
            }
            $normalized = $this->financialSum($normalizedValues);
            // The mean is a convex combination; rounding cannot legitimately exceed this range.
            $value = $normalized === null ? NAN : ($average ? max(-1.0, min(1.0, $normalized)) : $normalized) * $scale;
        }
        if (!is_finite($value)) {
            $result['status'] = 'overflow';
            $result['failure_reason'] = 'aggregate_overflow';
            return $result;
        }
        $result['value'] = round($value, 2);
        $result['unrounded_value'] = $value;
        $result['status'] = 'ready';
        return $result;
    }

    /** Preserve low-order residuals when large, opposing financial values cancel. */
    private function financialSum(array $values): ?float
    {
        $sum = 0.0;
        $correction = 0.0;
        foreach ($values as $value) {
            $next = $sum + $value;
            if (!is_finite($next)) return null;
            $correction += abs($sum) >= abs($value)
                ? ($sum - $next) + $value : ($value - $next) + $sum;
            $sum = $next;
        }
        $result = $sum + $correction;
        return is_finite($result) ? $result : null;
    }

    public function buildStages(array $summary): array
    {
        $counts = $summary['stage_counts'] ?? [];

        return [
            ['key' => 'recommendation', 'label' => '建议动作', 'count' => (int)($counts['recommendation'] ?? 0)],
            ['key' => 'approval', 'label' => '审批', 'count' => (int)($counts['approval'] ?? 0)],
            ['key' => 'execution', 'label' => '执行', 'count' => (int)($counts['execution'] ?? 0)],
            ['key' => 'evidence', 'label' => '执行证据', 'count' => (int)($counts['evidence'] ?? 0)],
            ['key' => 'review', 'label' => '效果复盘', 'count' => (int)($counts['review'] ?? 0)],
            ['key' => 'reviewed', 'label' => 'ROI确认', 'count' => (int)($counts['reviewed'] ?? 0)],
        ];
    }

    public function reviewAvailableOn(array $evidenceRows): string
    {
        $dates = [];
        foreach ($evidenceRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $platformResponse = $this->arrayValue($row['platform_response'] ?? []);
            if ($platformResponse === [] && isset($row['platform_response_json'])) {
                $platformResponse = $this->decodeJson((string)$row['platform_response_json']);
            }
            $operatorEvidence = $this->arrayValue($platformResponse['operator_execution_evidence'] ?? []);
            foreach ([$platformResponse['next_review_date'] ?? '', $operatorEvidence['next_review_date'] ?? ''] as $candidate) {
                $candidate = trim((string)$candidate);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidate) === 1) {
                    $dates[] = $candidate;
                }
            }
        }

        return $dates === [] ? '' : max($dates);
    }

    /**
     * Keep a non-sensitive receipt visible after protected-response redaction removes
     * the raw evidence payload for non-super-admin operators.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array{count: int, types: array<int, string>, latest_type: string, latest_at: string}
     */
    public function buildSafeEvidenceSummary(array $rows, array $task = [], array $intent = []): array
    {
        $types = [];
        $nodeRecord = [];
        foreach ($rows as $row) {
            $type = trim((string)($row['evidence_type'] ?? ''));
            if ($type !== '') {
                $types[] = $type;
            }
            if ($nodeRecord === []) {
                $platformResponse = $this->arrayValue($row['platform_response'] ?? []);
                if ($platformResponse === [] && isset($row['platform_response_json'])) {
                    $platformResponse = $this->decodeJson((string)$row['platform_response_json']);
                }
                $candidate = $this->arrayValue($platformResponse['node_record'] ?? []);
                if (in_array(
                    (string)($candidate['contract_version'] ?? ''),
                    ['operation_revenue_node.v1', 'operation_revenue_node.v2'],
                    true
                )) {
                    $contractVersion = trim((string)($candidate['contract_version'] ?? ''));
                    if ($contractVersion === 'operation_revenue_node.v2' && ($task !== [] || $intent !== [])) {
                        $tenantId = (int)($intent['tenant_id'] ?? 0);
                        $taskId = (int)($task['id'] ?? 0);
                        $parentIntentId = (int)($intent['id'] ?? 0);
                        $systemHotelId = (int)($candidate['system_hotel_id'] ?? 0);
                        $businessDate = trim((string)($candidate['business_date'] ?? ''));
                        $identityMismatch = $tenantId <= 0
                            || (int)($task['tenant_id'] ?? 0) !== $tenantId
                            || (int)($row['tenant_id'] ?? 0) !== $tenantId
                            || $taskId <= 0
                            || (int)($row['task_id'] ?? 0) !== $taskId
                            || $parentIntentId <= 0
                            || (int)($task['intent_id'] ?? 0) !== $parentIntentId
                            || $systemHotelId <= 0
                            || $systemHotelId !== (int)($task['hotel_id'] ?? 0)
                            || $systemHotelId !== (int)($intent['hotel_id'] ?? 0)
                            || $businessDate === ''
                            || $businessDate !== substr(trim((string)($intent['date_start'] ?? '')), 0, 10)
                            || (int)($row['created_by'] ?? 0) <= 0;
                        if ($identityMismatch) {
                            $nodeRecord = ['status' => 'identity_mismatch'];
                            break;
                        }
                    }
                    $metricSnapshot = trim((string)($candidate['metric_snapshot'] ?? ''));
                    $nodeRecord = [
                        'status' => 'available',
                        'contract_version' => $contractVersion,
                        'system_hotel_id' => trim((string)($candidate['system_hotel_id'] ?? '')),
                        'business_date' => trim((string)($candidate['business_date'] ?? '')),
                        'recorded_at' => trim((string)($candidate['recorded_at'] ?? '')),
                        'operating_period' => trim((string)($candidate['operating_period'] ?? '')),
                        'special_event' => trim((string)($candidate['special_event'] ?? '')),
                        'source_scope' => trim((string)($candidate['source_scope'] ?? '')),
                        'room_status_alignment' => trim((string)($candidate['room_status_alignment'] ?? '')),
                        'data_quality_status' => trim((string)($candidate['data_quality_status'] ?? '')),
                        'metric_definition' => trim((string)($candidate['metric_definition'] ?? '')),
                        'metric_snapshot' => $metricSnapshot === '' ? null : $metricSnapshot,
                        'progress_status' => trim((string)($candidate['progress_status'] ?? '')),
                        'comparison_basis' => trim((string)($candidate['comparison_basis'] ?? '')),
                        'judgment_basis' => trim((string)($candidate['judgment_basis'] ?? '')),
                        'primary_risk' => trim((string)($candidate['primary_risk'] ?? '')),
                        'success_criteria' => trim((string)($candidate['success_criteria'] ?? '')),
                        'stop_condition' => trim((string)($candidate['stop_condition'] ?? '')),
                    ];
                    if ($contractVersion === 'operation_revenue_node.v2') {
                        $nodeRecord['identity'] = [
                            'tenant_id' => (int)($row['tenant_id'] ?? 0),
                            'system_hotel_id' => (int)($candidate['system_hotel_id'] ?? 0),
                            'task_id' => (int)($row['task_id'] ?? 0),
                            'parent_intent_id' => (int)($task['intent_id'] ?? 0),
                            'business_date' => trim((string)($candidate['business_date'] ?? '')),
                            'platform' => strtolower(trim((string)($intent['platform'] ?? ''))),
                            'source_scope' => trim((string)($candidate['source_scope'] ?? '')),
                            'operator_id' => (int)($row['created_by'] ?? 0),
                        ];
                    }
                }
            }
        }
        $types = array_values(array_unique($types));
        $latest = $rows[0] ?? [];

        return [
            'count' => count($rows),
            'types' => $types,
            'latest_type' => trim((string)($latest['evidence_type'] ?? '')),
            'latest_at' => trim((string)($latest['created_at'] ?? '')),
            'node_record' => $nodeRecord === [] ? ['status' => 'missing'] : $nodeRecord,
        ];
    }

    /**
     * The verified review can be followed by a newer operator note. Keep the newest
     * valid longitudinal review independently from the generic latest evidence row.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function latestLongitudinalReview(array $rows): array
    {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $platformResponse = $this->arrayValue($row['platform_response'] ?? []);
            if ($platformResponse === [] && isset($row['platform_response_json'])) {
                $platformResponse = $this->decodeJson((string)$row['platform_response_json']);
            }
            $review = $this->arrayValue($platformResponse['longitudinal_review'] ?? []);
            if ((string)($review['status'] ?? '') !== 'verified'
                || (string)($review['learning_stage'] ?? '') !== 'action_reviewed'
                || ($review['causality_claimed'] ?? null) !== false
            ) {
                continue;
            }

            return $review;
        }

        return [];
    }

    private function buildWorkflowAssignment(array $intent): array
    {
        $targetValue = $this->arrayValue($intent['target_value'] ?? []);
        $schedule = $this->arrayValue($targetValue['workflow_schedule'] ?? []);
        $assigneeId = (int)($schedule['assignee_id'] ?? $targetValue['assignee_id'] ?? 0);
        $dueAt = trim((string)($schedule['due_at'] ?? $targetValue['due_at'] ?? ''));
        $reviewAt = trim((string)($schedule['review_at'] ?? $targetValue['review_at'] ?? ''));

        return [
            'status' => $assigneeId > 0 && $dueAt !== '' && $reviewAt !== '' ? 'scheduled' : 'not_scheduled',
            'assignee_id' => $assigneeId,
            'due_at' => $dueAt,
            'review_at' => $reviewAt,
            'source_policy' => trim((string)($schedule['source_policy'] ?? '')),
        ];
    }

    private function buildNextAction(string $stage, array $intent, array $task, string $displayReviewStatus): array
    {
        if ((string)($intent['source_module'] ?? '') === 'canonical_ota_investigation'
            && (string)($intent['status'] ?? '') === 'system_authorized_analysis'
        ) {
            return [
                'key' => 'none',
                'label' => '核查已完成；经营效果未声明',
                'priority' => 'low',
                'target_id' => (int)($task['id'] ?? 0),
            ];
        }
        // A saved terminal review cannot be reopened. Re-read current facts
        // without suggesting a review mutation or inventing missing evidence.
        if ($displayReviewStatus === 'unverified'
            && (string)($task['status'] ?? '') === 'executed'
            && (int)($task['id'] ?? 0) > 0
            && in_array((string)($task['result_status'] ?? ''), ['success', 'near_success'], true)
        ) {
            return [
                'key' => 'refresh_readback',
                'label' => '刷新核验结果',
                'priority' => 'medium',
                'target_id' => (int)$task['id'],
            ];
        }
        return match ($stage) {
            'approval' => [
                'key' => 'approve_intent',
                'label' => '审批执行意图',
                'priority' => 'high',
                'target_id' => (int)($intent['id'] ?? 0),
            ],
            'execution' => [
                'key' => empty($task) ? 'wait_task_create' : 'record_execution',
                'label' => empty($task) ? '等待生成执行任务' : '记录执行结果',
                'priority' => empty($task) ? 'medium' : 'high',
                'target_id' => (int)($task['id'] ?? 0),
            ],
            'evidence' => [
                'key' => 'record_evidence',
                'label' => '补充执行证据',
                'priority' => 'high',
                'target_id' => (int)($task['id'] ?? 0),
            ],
            'review' => [
                'key' => 'review_effect',
                'label' => '触发效果复盘',
                'priority' => 'medium',
                'target_id' => (int)($task['id'] ?? 0),
            ],
            'blocked' => [
                'key' => 'resolve_blocker',
                'label' => '处理阻塞原因',
                'priority' => 'high',
                'target_id' => (int)($intent['id'] ?? 0),
            ],
            'failed' => [
                'key' => 'review_failure',
                'label' => '复核失败原因',
                'priority' => 'high',
                'target_id' => (int)($task['id'] ?? 0),
            ],
            'cancelled', 'rejected' => [
                'key' => 'none',
                'label' => '行动已取消',
                'priority' => 'low',
                'target_id' => 0,
            ],
            default => [
                'key' => 'none',
                'label' => '无需操作',
                'priority' => 'low',
                'target_id' => 0,
            ],
        };
    }

    private function buildBottleneck(array $stageCounts): array
    {
        $stage = '';
        $count = 0;
        foreach (['approval', 'execution', 'evidence', 'review', 'blocked', 'failed'] as $candidate) {
            $value = (int)($stageCounts[$candidate] ?? 0);
            if ($value > $count) {
                $stage = $candidate;
                $count = $value;
            }
        }

        return [
            'stage' => $stage,
            'count' => $count,
            'label' => $this->stageLabel($stage),
        ];
    }

    private function stageLabel(string $stage): string
    {
        return [
            'approval' => '审批',
            'execution' => '执行',
            'evidence' => '执行证据',
            'review' => '效果复盘',
            'reviewed' => 'ROI确认',
            'blocked' => '阻塞',
            'failed' => '失败',
            'cancelled' => '已取消',
            'rejected' => '已驳回',
        ][$stage] ?? '';
    }

    private function moneyStatus(int $roiReady, ?float $totalProfit, bool $hasFinancialSamples = false): string
    {
        if ($roiReady <= 0) {
            return $hasFinancialSamples ? 'profit_unverified' : 'no_roi';
        }
        if ($totalProfit === null || !is_finite($totalProfit)) {
            return 'profit_unverified';
        }
        if ($totalProfit > 0) {
            return 'profit_positive';
        }
        if ($totalProfit < 0) {
            return 'profit_negative';
        }

        return 'break_even';
    }

    public function latestTask(array $tasks): array
    {
        if ($tasks === []) {
            return [];
        }
        usort($tasks, static fn(array $left, array $right): int =>
            (int)($right['id'] ?? 0) <=> (int)($left['id'] ?? 0)
        );

        return $tasks[0];
    }

    private function stage(
        array $intent,
        array $task,
        array $evidenceTruth,
        string $reviewStatus,
        array $outcomeTruth = []
    ): string {
        $intentStatus = (string)($intent['status'] ?? '');
        if ($intentStatus === 'blocked') {
            return 'blocked';
        }
        if ($intentStatus === 'rejected') {
            return 'rejected';
        }
        if ($intentStatus === 'cancelled') {
            return 'cancelled';
        }
        if (!$this->intentExecutionAuthorized($intent)) {
            return 'approval';
        }
        if ($task === []) {
            return 'execution';
        }

        $taskStatus = (string)($task['status'] ?? '');
        if ($taskStatus === 'blocked') {
            return 'blocked';
        }
        if ($taskStatus === 'failed') {
            return 'failed';
        }
        if ($taskStatus !== 'executed') {
            return 'execution';
        }
        if (($evidenceTruth['source_verified'] ?? false) !== true) {
            return 'evidence';
        }
        if ($reviewStatus === 'failed') {
            return 'failed';
        }
        if (in_array($reviewStatus, ['success', 'near_success'], true)) {
            return $this->executionOutcomeService->executionPositiveOutcomeAllowsStatus(
                $outcomeTruth,
                $reviewStatus
            )
                ? 'reviewed'
                : 'review';
        }

        return 'review';
    }

    /** @param array<string,mixed> $intent */
    private function intentExecutionAuthorized(array $intent): bool
    {
        if ((string)($intent['status'] ?? '') === 'approved') {
            return true;
        }
        if ((string)($intent['status'] ?? '') !== 'system_authorized_analysis'
            || (string)($intent['source_module'] ?? '') !== 'canonical_ota_investigation'
            || (int)($intent['created_by'] ?? 0) !== 0
            || (int)($intent['approved_by'] ?? 0) !== 0
            || trim((string)($intent['approved_at'] ?? '')) !== ''
        ) {
            return false;
        }
        $evidence = $this->arrayValue($intent['evidence'] ?? []);
        return (string)($evidence['execution_scope'] ?? '') === 'analysis_only'
            && $this->analysisApprovalAuthorityValid(
                $evidence,
                (int)($intent['tenant_id'] ?? 0),
                (int)($intent['hotel_id'] ?? 0),
                strtolower(trim((string)($intent['platform'] ?? '')))
            )
            && ($evidence['human_approval_claimed'] ?? true) === false
            && ($evidence['external_write'] ?? true) === false
            && ($evidence['causality_claimed'] ?? true) === false
            && ($evidence['outcome_claimed'] ?? true) === false;
    }

    /** @param array<string,mixed> $item */
    private function itemExecutionAuthorized(array $item): bool
    {
        $status = (string)($item['approval']['status'] ?? '');
        if ($status === 'approved') {
            return true;
        }
        $recommendation = $this->arrayValue($item['recommendation'] ?? []);
        $evidence = $this->arrayValue($recommendation['evidence'] ?? []);
        return $status === 'system_authorized_analysis'
            && (string)($recommendation['source_module'] ?? '') === 'canonical_ota_investigation'
            && (string)($evidence['execution_scope'] ?? '') === 'analysis_only'
            && $this->analysisApprovalAuthorityValid(
                $evidence,
                (int)($evidence['tenant_id'] ?? 0),
                (int)($item['hotel_id'] ?? 0),
                strtolower(trim((string)($recommendation['platform'] ?? '')))
            )
            && ($evidence['human_approval_claimed'] ?? true) === false
            && ($evidence['external_write'] ?? true) === false
            && ($evidence['causality_claimed'] ?? true) === false
            && ($evidence['outcome_claimed'] ?? true) === false;
    }

    /** @param array<string,mixed> $evidence */
    private function analysisApprovalAuthorityValid(
        array $evidence,
        int $expectedTenantId,
        int $expectedHotelId,
        string $expectedPlatform
    ): bool {
        $authority = (string)($evidence['approval_authority'] ?? '');
        if ($authority === 'system_goal_scoped_analysis') {
            return !array_key_exists('scheduled_analysis_authorization', $evidence);
        }
        if ($authority !== 'system_scheduled_analysis') {
            return false;
        }
        $authorization = $this->arrayValue($evidence['scheduled_analysis_authorization'] ?? []);
        $digest = strtolower(trim((string)($authorization['content_digest'] ?? '')));
        $selfValid = $expectedTenantId > 0
            && $expectedHotelId > 0
            && in_array($expectedPlatform, ['ctrip', 'meituan'], true)
            && (string)($authorization['schema_version'] ?? '')
                === 'canonical_ota_scheduled_analysis_authorization.v1'
            && ($authorization['enabled'] ?? false) === true
            && preg_match(
                '/^[a-z0-9][a-z0-9._:-]{2,119}$/D',
                strtolower(trim((string)($authorization['plan_id'] ?? '')))
            ) === 1
            && (int)($authorization['tenant_id'] ?? 0) === $expectedTenantId
            && (int)($authorization['hotel_id'] ?? 0) === $expectedHotelId
            && strtolower(trim((string)($authorization['platform'] ?? ''))) === $expectedPlatform
            && strtolower(trim((string)($authorization['trigger'] ?? '')))
                === 'historical_daily_canonical_promotion'
            && strtolower(trim((string)($authorization['authorized_by'] ?? ''))) === 'user_goal'
            && $this->authorizationTimestampValid((string)($authorization['authorized_at'] ?? ''))
            && ($authorization['analysis_only'] ?? false) === true
            && (int)($authorization['operation_count'] ?? 0) === 4
            && ($authorization['external_action_allowed'] ?? true) === false
            && preg_match('/^[a-f0-9]{64}$/D', $digest) === 1
            && hash_equals(
                $digest,
                strtolower(trim((string)($evidence['scheduled_analysis_authorization_digest'] ?? '')))
            )
            && hash_equals($digest, $this->authorizationDigest($authorization));
        if (!$selfValid) {
            return false;
        }
        try {
            $resolvedAuthorization = ($this->scheduledAuthorizationResolver)(
                $authorization,
                $expectedTenantId,
                $expectedHotelId,
                $expectedPlatform
            );
        } catch (\Throwable) {
            return false;
        }
        return $resolvedAuthorization === $authorization;
    }

    /** @param array<string,mixed> $authorization */
    private function authorizationDigest(array $authorization): string
    {
        unset($authorization['content_digest']);
        try {
            return hash('sha256', json_encode(
                $this->canonicalize($authorization),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ));
        } catch (\JsonException) {
            return '';
        }
    }

    private function authorizationTimestampValid(string $value): bool
    {
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', trim($value));
        return $time instanceof \DateTimeImmutable
            && $time->format('Y-m-d\TH:i:sP') === trim($value);
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

    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $parent @param array<string, mixed> $child */
    private function tenantIdentityMatches(array $parent, array $child): bool
    {
        $parentHasTenant = array_key_exists('tenant_id', $parent);
        $childHasTenant = array_key_exists('tenant_id', $child);
        if (!$parentHasTenant && !$childHasTenant) {
            return true;
        }

        $parentTenantId = (int)($parent['tenant_id'] ?? 0);
        $childTenantId = (int)($child['tenant_id'] ?? 0);
        return $parentTenantId > 0
            && $childTenantId > 0
            && $parentTenantId === $childTenantId;
    }
}
