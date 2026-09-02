<?php
declare(strict_types=1);

namespace app\service\operation;

use app\service\AiDailyReportService;
use app\service\AiDecisionQualityService;
use app\service\CtripPublicHotelProfileService;
use app\service\KnowledgeSopExecutionProvenanceService;
use app\service\MeituanPublicPageEvidenceService;
use app\service\OperatingNetworkService;
use app\service\OperatingOpportunityLabService;
use app\service\OperatingQuestionExecutionBridgeService;
use app\service\OperatingTargetExecutionProvenanceService;
use app\service\OperatingTargetService;
use app\service\OtaPublicPageDiagnosisService;
use app\service\OperationActionAiReviewService;
use app\service\OperationActionLifecycleService;
use app\service\RevenueCockpitApprovalService;
use app\service\SourceBackedExecutionIntentIdentityService;
use app\service\TemporalForecastTrialService;
use app\service\TemporalInsightService;
use DateTimeImmutable;
use think\facade\Db;

trait OperationApprovalValidationConcern
{
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
            || $approvalSourceModule === RevenueCockpitApprovalService::SOURCE_MODULE
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
                RevenueCockpitApprovalService::SOURCE_MODULE =>
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

    /** @param array<string,mixed> $intent */
    private function managedActionDeclaresObservationTarget(array $intent): bool
    {
        $sourceModule = strtolower(trim((string)($intent['source_module'] ?? '')));
        if (!in_array($sourceModule, [
            OperatingQuestionExecutionBridgeService::SOURCE_MODULE,
            RevenueCockpitApprovalService::SOURCE_MODULE,
            OperatingOpportunityLabService::DAILY_SOURCE_MODULE,
        ], true)) {
            return false;
        }
        $target = is_array($intent['target_value'] ?? null)
            ? $intent['target_value']
            : $this->decodeJson((string)($intent['target_value_json'] ?? ''));
        $card = is_array($target['action_card'] ?? null) ? $target['action_card'] : [];
        if (OperationActionLifecycleService::isManagedCard($card)) {
            $contract = is_array($card['metric_contract'] ?? null) ? $card['metric_contract'] : [];
            return strtolower(trim((string)($contract['expected_direction'] ?? ''))) === 'observe'
                && strtolower(trim((string)($contract['target_type'] ?? ''))) === 'observation'
                && ($contract['target_value'] ?? null) === null
                && ($contract['expected_delta'] ?? null) === null;
        }
        $evidence = is_array($intent['evidence'] ?? null)
            ? $intent['evidence']
            : $this->decodeJson((string)($intent['evidence_json'] ?? ''));
        $recommendation = is_array($evidence['decision_recommendation'] ?? null)
            ? $evidence['decision_recommendation']
            : [];
        $effect = is_array($recommendation['expected_effect'] ?? null)
            ? $recommendation['expected_effect']
            : [];
        $expectedMetric = strtolower(trim((string)($intent['expected_metric'] ?? '')));
        return strtolower(trim((string)($effect['status'] ?? ''))) === 'verification_target'
            && strtolower(trim((string)($effect['direction'] ?? ''))) === 'verify'
            && strtolower(trim((string)($effect['metric'] ?? ''))) === $expectedMetric
            && $expectedMetric !== '';
    }

    private function assertSavedOtaDiagnosisApprovalTargetReadback(array $intent): void
    {
        $evidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
        $targetValue = is_array($intent['target_value'] ?? null) ? $intent['target_value'] : [];
        $contract = is_array($evidence['approval_target'] ?? null) ? $evidence['approval_target'] : [];
        $approvalMode = strtolower(trim((string)($contract['approval_mode'] ?? 'human')));
        $aiReview = is_array($evidence['ai_independent_review'] ?? null)
            ? $evidence['ai_independent_review']
            : [];
        $aiReviewDigest = strtolower(trim((string)($contract['ai_review_digest'] ?? '')));
        $aiReviewValid = true;
        if ($approvalMode === 'ai_independent_review') {
            try {
                OperationActionAiReviewService::assertReviewContract($aiReview, $intent, true);
            } catch (\Throwable) {
                $aiReviewValid = false;
            }
        }
        $metricKey = strtolower(trim((string)($intent['expected_metric'] ?? '')));
        $metricDefinition = is_array($targetValue['metric_definition'] ?? null)
            ? $targetValue['metric_definition']
            : [];
        $metricDefinitionDigest = $metricDefinition === [] || $metricKey === ''
            ? ''
            : $this->savedOtaDiagnosisMetricDefinitionDigest($metricKey, $metricDefinition);
        $targetType = strtolower(trim((string)($contract['target_type'] ?? '')));
        $direction = strtolower(trim((string)($contract['expected_direction'] ?? '')));
        $contractVersion = trim((string)($contract['version'] ?? ''));
        $expectedDeltaStatus = strtolower(trim((string)($contract['expected_delta_status'] ?? '')));
        $isObservation = $contractVersion === 'operation_observation_approval_target.v1'
            && $direction === 'observe'
            && $targetType === 'observation'
            && $expectedDeltaStatus === 'observation_only';
        $isQuantifiedTarget = $contractVersion === 'ota_execution_approval_target.v1'
            && in_array($direction, ['increase', 'decrease'], true)
            && in_array($targetType, ['absolute', 'delta'], true)
            && $expectedDeltaStatus === 'manual_confirmed';
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
            || (!$isObservation && !$isQuantifiedTarget)
            || (string)($contract['expected_metric'] ?? '') !== (string)($intent['expected_metric'] ?? '')
            || (int)($contract['approved_by'] ?? 0) !== (int)($intent['approved_by'] ?? 0)
            || (string)($contract['approved_at'] ?? '') !== (string)($intent['approved_at'] ?? '')
            || !in_array($approvalMode, ['human', 'ai_independent_review'], true)
            || ($approvalMode === 'ai_independent_review'
                && (!$aiReviewValid
                    || (int)($contract['approved_by'] ?? -1) !== 0
                    || (int)($intent['approved_by'] ?? -1) !== 0
                    || preg_match('/^[a-f0-9]{64}$/D', $aiReviewDigest) !== 1
                    || !hash_equals(
                        $aiReviewDigest,
                        strtolower(trim((string)($aiReview['content_digest'] ?? '')))
                    )
                    || strtolower(trim((string)($targetValue['approval_mode'] ?? '')))
                        !== 'ai_independent_review'
                    || !hash_equals(
                        $aiReviewDigest,
                        strtolower(trim((string)($targetValue['ai_review_digest'] ?? '')))
                    )))
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
            || ($isObservation
                && (!in_array((string)($contract['source_module'] ?? ''), [
                        OperatingQuestionExecutionBridgeService::SOURCE_MODULE,
                        RevenueCockpitApprovalService::SOURCE_MODULE,
                        OperatingOpportunityLabService::DAILY_SOURCE_MODULE,
                    ], true)
                    || $contractTargetValue !== null
                    || $contractExpectedDelta !== null
                    || $persistedTargetValue !== null
                    || $intentExpectedDelta !== null))
            || strtolower(trim((string)($targetValue['expected_direction'] ?? ''))) !== $direction
            || strtolower(trim((string)($evidence['expected_direction'] ?? ''))) !== $direction
            || strtolower(trim((string)($targetValue['target_type'] ?? ''))) !== $targetType
            || strtolower(trim((string)($evidence['target_type'] ?? ''))) !== $targetType
            || strtolower(trim((string)($targetValue['expected_delta_status'] ?? ''))) !== $expectedDeltaStatus
            || strtolower(trim((string)($evidence['expected_delta_status'] ?? ''))) !== $expectedDeltaStatus
            || count($tasks) !== 1
            || !hash_equals($digest, strtolower(trim((string)($taskTargetValue['approval_target_digest'] ?? ''))))
            || $taskTargetValue !== $targetValue
        ) {
            throw new \RuntimeException('execution approval target save readback verification failed');
        }
    }

    /** @return array<string,mixed> */
    private function savedOtaDiagnosisMetricDefinition(
        string $metricKey,
        string $platform = '',
        string $sourceModule = ''
    ): array
    {
        $metricKey = strtolower(trim($metricKey));
        $platform = $this->normalizeOtaChannel($platform);
        $sourceModule = strtolower(trim($sourceModule));
        if ($metricKey === 'ctrip_strict_core_fact_count') {
            if ($sourceModule !== OperatingOpportunityLabService::DAILY_SOURCE_MODULE
                || $platform !== 'ctrip'
            ) {
                throw new \InvalidArgumentException(
                    'strict core fact-count review is limited to the Ctrip daily-one-thing data-gap contract'
                );
            }
            return [
                'version' => 'daily_one_thing_metric_definition.v1',
                'platform' => 'ctrip',
                'source_module' => OperatingOpportunityLabService::DAILY_SOURCE_MODULE,
                'metric_key' => $metricKey,
                'semantic_key' => 'ctrip_target_date_strict_core_fact_count',
                'unit' => 'verified_fields',
                'value_type' => 'non_negative_integer',
                'source_table' => 'online_daily_data',
                'source_identity' => ['tenant_id', 'system_hotel_id', 'platform', 'business_date'],
                'source_policy' => 'dual_ota_field_closure_current_receipt_strict_readback',
                'calculation' => 'count_strict_consumable_core_fields',
                'comparison_policy' => 'same_hotel_same_platform_same_target_date_before_vs_later_natural_receipt',
                'causality_claimed' => false,
            ];
        }
        if ($metricKey === 'list_exposure') {
            if ($platform !== 'ctrip') {
                throw new \InvalidArgumentException(
                    'list_exposure same-criterion effect readback is supported only for Ctrip unique-user semantics'
                );
            }
            return [
                'version' => 'ota_execution_metric_definition.v3',
                'platform' => 'ctrip',
                'source_module' => 'ctrip_data_center_flow_transform',
                'source_endpoint_family' => 'ctrip_query_flow_transform_new_v1',
                'source_endpoint_ids' => ['business_flow_transform', 'traffic_flow_transform'],
                'metric_key' => 'list_exposure',
                'semantic_key' => 'ctrip_datacenter_list_exposure_uv',
                'unit' => 'unique_users',
                'value_type' => 'non_negative_integer',
                'source_table' => 'online_daily_data',
                'source_field' => 'list_exposure',
                'source_identity' => ['system_hotel_id', 'platform', 'business_date'],
                'source_policy' => 'trusted_persisted_source_rows_with_metric_scoped_field_fact_readback',
                'field_fact_required' => true,
                'calculation' => 'canonical_daily_snapshot_value',
                'comparison_policy' => 'same_hotel_same_platform_same_semantic_key_baseline_vs_approved_next_calendar_business_date',
                'blocked_aliases' => ['generic_impression_count', 'advertising_impressions'],
            ];
        }
        if ($metricKey === 'detail_exposure') {
            if (!in_array($sourceModule, [
                    OperatingQuestionExecutionBridgeService::SOURCE_MODULE,
                    RevenueCockpitApprovalService::SOURCE_MODULE,
                    OperatingOpportunityLabService::DAILY_SOURCE_MODULE,
                ], true)
                || !in_array($platform, ['ctrip', 'meituan'], true)
            ) {
                throw new \InvalidArgumentException(
                    'detail_exposure same-criterion readback is limited to verified Ctrip/Meituan operating questions'
                );
            }
            return [
                'version' => 'ota_execution_metric_definition.v4',
                'platform' => $platform,
                'source_module' => 'operating_question_verified_online_daily_data',
                'metric_key' => 'detail_exposure',
                'semantic_key' => $platform . '_detail_exposure_count',
                'unit' => 'exposure_count',
                'value_type' => 'non_negative_integer',
                'source_table' => 'online_daily_data',
                'source_field' => 'detail_exposure',
                'source_identity' => ['system_hotel_id', 'platform', 'business_date'],
                'source_policy' => 'trusted_persisted_source_rows_with_metric_scoped_field_fact_readback',
                'field_fact_required' => true,
                'calculation' => 'canonical_daily_snapshot_value',
                'comparison_policy' => 'same_hotel_same_platform_same_semantic_key_baseline_vs_approved_review_business_date',
                'blocked_aliases' => ['list_exposure', 'generic_page_views', 'advertising_impressions'],
            ];
        }
        $calculation = match ($metricKey) {
            'revenue', 'avg_revenue', 'amount', 'income' => 'trusted_daily_revenue',
            'orders', 'avg_orders', 'order_count', 'book_order_num' => 'trusted_daily_order_count',
            'room_nights', 'avg_room_nights' => 'trusted_daily_room_nights',
            'adr', 'avg_adr' => 'trusted_daily_revenue_divided_by_room_nights',
            'occ', 'occupancy', 'avg_occ' => 'trusted_daily_sold_room_nights_divided_by_sellable_room_nights',
            'detail_rate', 'view_rate', 'flow_rate' => 'trusted_daily_detail_or_flow_rate',
            'conversion', 'conversion_rate', 'order_rate' => 'trusted_daily_order_conversion_rate',
            'avg_psi_score' => 'trusted_daily_average_psi_score_with_positive_sample_count',
            default => throw new \InvalidArgumentException(
                'saved OTA diagnosis metric is not supported by same-criterion effect readback: ' . $metricKey
            ),
        };

        return [
            'version' => 'ota_execution_metric_definition.v1',
            'metric_key' => $metricKey,
            'source_table' => 'online_daily_data',
            'source_identity' => ['system_hotel_id', 'platform', 'business_date'],
            'source_policy' => 'trusted_persisted_source_rows_with_strict_readback',
            'calculation' => $calculation,
            'comparison_policy' => 'same_hotel_same_platform_same_metric_baseline_vs_approved_review_business_date',
        ];
    }

    /** @param array<string,mixed> $definition */
    private function savedOtaDiagnosisMetricDefinitionDigest(string $metricKey, array $definition): string
    {
        return hash('sha256', json_encode(
            $this->canonicalizeExecutionApprovalTarget([
                'metric_key' => strtolower(trim($metricKey)),
                'definition' => $definition,
            ]),
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
        ));
    }

    private function savedOtaDiagnosisApprovalTargetDigest(array $contract): string
    {
        unset($contract['content_digest']);
        return hash('sha256', json_encode(
            $this->canonicalizeExecutionApprovalTarget($contract),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    private function canonicalizeExecutionApprovalTarget(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalizeExecutionApprovalTarget($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalizeExecutionApprovalTarget($item);
        }
        return $value;
    }

    /** @param array<string, mixed> $intent */
    private function assertAiDecisionIntentReadyForApproval(array $intent, ?array $authorization = null): void
    {
        $sourceModule = strtolower(trim((string)($intent['source_module'] ?? '')));
        $intent['source_module'] = $sourceModule;
        if ($sourceModule === 'knowledge_sop') {
            (new KnowledgeSopExecutionProvenanceService())->assertIntentCurrent($intent, true);
            return;
        }
        if ($sourceModule === OperatingQuestionExecutionBridgeService::SOURCE_MODULE) {
            (new OperatingQuestionExecutionBridgeService())->assertIntentCurrent($intent);
            return;
        }
        if ($sourceModule === RevenueCockpitApprovalService::SOURCE_MODULE) {
            (new RevenueCockpitApprovalService())->assertIntentCurrent($intent);
            return;
        }
        if ($sourceModule === OperatingOpportunityLabService::DAILY_SOURCE_MODULE) {
            (new OperatingOpportunityLabService())->assertDailyIntentCurrent($intent);
            return;
        }
        if ($sourceModule === 'ota_diagnosis') {
            $this->assertPublicPageDiagnosisIntentReadyForApproval($intent);
            return;
        }
        if (SourceBackedExecutionIntentIdentityService::supports($intent)) {
            $this->assertSourceBackedIntentCurrentWithAuthorization($intent, $authorization);
            return;
        }
        if ($sourceModule === 'operating_target') {
            $this->assertOperatingTargetIntentSourceIsCurrent($intent);
            return;
        }
        if ($sourceModule === OperatingNetworkService::EXECUTION_SOURCE_MODULE) {
            (new OperatingNetworkService())->assertReplicationExecutionIntentCurrent($intent);
            return;
        }
        if ($sourceModule === TemporalInsightService::OPERATION_SOURCE_MODULE) {
            (new TemporalInsightService())->assertOperationRecommendationIntentCurrent($intent);
            return;
        }
        if ($sourceModule === TemporalForecastTrialService::OPERATION_SOURCE_MODULE) {
            (new TemporalForecastTrialService())->assertOperationIntentCurrent($intent);
            return;
        }
        if (!in_array($sourceModule, [
            'ai_daily_report',
            'revenue_research',
            'price_suggestion',
            'ota_diagnosis_saved',
        ], true)) {
            return;
        }

        $evidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
        $recommendation = is_array($evidence['decision_recommendation'] ?? null)
            ? $evidence['decision_recommendation']
            : [];
        $decisionQuality = is_array($recommendation['decision_quality'] ?? null)
            ? $recommendation['decision_quality']
            : [];
        $storedDigest = strtolower(trim((string)($evidence['decision_recommendation_digest'] ?? '')));
        if (($recommendation['can_create_execution_intent'] ?? false) !== true
            || ($decisionQuality['contract_version'] ?? '') !== AiDecisionQualityService::CONTRACT_VERSION
            || ($decisionQuality['execution_ready'] ?? false) !== true
            || preg_match('/^[a-f0-9]{64}$/D', $storedDigest) !== 1
            || !hash_equals($storedDigest, $this->decisionRecommendationDigest($recommendation))
        ) {
            throw new \InvalidArgumentException('AI decision quality v2 provenance is required before approval');
        }

        $hotelId = (int)($intent['hotel_id'] ?? 0);
        $sourceRecordId = (int)($intent['source_record_id'] ?? 0);
        if ($hotelId <= 0 || $sourceRecordId <= 0) {
            throw new \InvalidArgumentException('AI decision source identity is required before approval');
        }

        if ($sourceModule === 'ota_diagnosis_saved') {
            if (!$this->hasVerifiedOtaDiagnosisProvenance($intent, true)) {
                throw new \InvalidArgumentException('saved OTA diagnosis provenance is no longer valid');
            }
            return;
        }

        if ($sourceModule === 'price_suggestion') {
            $suggestion = \app\model\PriceSuggestion::where('id', $sourceRecordId)
                ->where('hotel_id', $hotelId)
                ->find();
            if (!$suggestion || (int)$suggestion->status !== \app\model\PriceSuggestion::STATUS_APPROVED) {
                throw new \InvalidArgumentException('approved price suggestion source is no longer valid');
            }
            $rows = $this->pricingRecommendationService->enrichSuggestionRows([$suggestion->toArray()]);
            $currentRecommendation = is_array($rows[0]['decision_recommendation'] ?? null)
                ? $rows[0]['decision_recommendation']
                : [];
            if ($currentRecommendation === []
                || !hash_equals($storedDigest, $this->decisionRecommendationDigest($currentRecommendation))
            ) {
                throw new \InvalidArgumentException('price suggestion decision provenance changed; create a new execution intent');
            }
            return;
        }

        if ($sourceModule === 'ai_daily_report') {
            $actionIndex = filter_var($evidence['action_index'] ?? null, FILTER_VALIDATE_INT);
            $report = Db::name('ai_daily_reports')
                ->where('id', $sourceRecordId)
                ->where('hotel_id', $hotelId)
                ->whereNull('deleted_at')
                ->find();
            if ($actionIndex === false || $actionIndex < 0 || !is_array($report)) {
                throw new \InvalidArgumentException('AI daily report source is no longer valid');
            }
            $reports = (new AiDailyReportService())->enrichReportRows([$report], [$hotelId], $hotelId);
            $actions = is_array($reports[0]['recommended_actions'] ?? null)
                ? $reports[0]['recommended_actions']
                : [];
            $currentRecommendation = is_array($actions[$actionIndex] ?? null) ? $actions[$actionIndex] : [];
            if ($currentRecommendation === []
                || !hash_equals($storedDigest, $this->decisionRecommendationDigest($currentRecommendation))
            ) {
                throw new \InvalidArgumentException('AI daily report decision provenance changed; create a new execution intent');
            }
            return;
        }

        if (($evidence['execution_ready'] ?? false) !== true
            || ($evidence['research_readiness_stage'] ?? '') !== 'research_ready_for_execution'
            || ($evidence['metric_scope'] ?? '') !== 'ota_channel'
        ) {
            throw new \InvalidArgumentException('revenue research provenance is no longer execution ready');
        }
    }

    /** @param array<string,mixed> $intent */
    private function assertManagedActionSourceCurrent(array $intent): void
    {
        $sourceModule = strtolower(trim((string)($intent['source_module'] ?? '')));
        if ($sourceModule === OperatingQuestionExecutionBridgeService::SOURCE_MODULE) {
            (new OperatingQuestionExecutionBridgeService())->assertIntentCurrent($intent);
            return;
        }
        if ($sourceModule === RevenueCockpitApprovalService::SOURCE_MODULE) {
            (new RevenueCockpitApprovalService())->assertIntentCurrent($intent);
            return;
        }
        if ($sourceModule === OperatingOpportunityLabService::DAILY_SOURCE_MODULE) {
            (new OperatingOpportunityLabService())->assertDailyIntentCurrent($intent);
        }
    }

    /** @param array<string,mixed> $intent @param array<string,mixed> $input */
    private function assertManagedOperationExecutionEvidence(array $intent, array $input, int $operatorId): void
    {
        if (!in_array(strtolower(trim((string)($intent['source_module'] ?? ''))), [
            OperatingQuestionExecutionBridgeService::SOURCE_MODULE,
            RevenueCockpitApprovalService::SOURCE_MODULE,
            OperatingOpportunityLabService::DAILY_SOURCE_MODULE,
        ], true)) {
            return;
        }
        $status = strtolower(trim((string)($input['status'] ?? '')));
        if (!in_array($status, ['executed', 'failed'], true)) {
            return;
        }
        $evidenceType = strtolower(trim((string)($input['evidence_type'] ?? '')));
        $evidence = $this->arrayValue($input['evidence'] ?? []);
        $response = $this->arrayValue($evidence['platform_response'] ?? []);
        $executedBy = trim((string)($response['executed_by'] ?? ''));
        $executedAt = trim((string)($response['executed_at'] ?? ''));
        $executionStatus = strtolower(trim((string)($response['execution_status'] ?? '')));
        $completedAction = trim((string)($response['completed_action'] ?? ''));
        $failureReason = trim((string)($response['failure_reason'] ?? ''));
        if ($operatorId <= 0
            || $evidenceType !== 'manual_operation_execution'
            || strtolower(trim((string)($response['mode'] ?? ''))) !== 'manual_operation_execution'
            || $executedBy === ''
            || $executionStatus !== $status
            || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $executedAt) !== 1
        ) {
            throw new \InvalidArgumentException('受管运营任务必须记录真实执行人、实际时间和执行状态');
        }
        $executedTimestamp = strtotime($executedAt);
        if ($executedTimestamp === false || $executedTimestamp > time() + 300) {
            throw new \InvalidArgumentException('运营任务实际执行时间无效或晚于当前时间');
        }
        if ($status === 'executed' && $completedAction === '') {
            throw new \InvalidArgumentException('执行成功必须记录已实际完成的操作说明');
        }
        if ($status === 'failed' && $failureReason === '') {
            throw new \InvalidArgumentException('执行失败必须记录真实失败原因');
        }
        if (($response['automatic_execution'] ?? false) === true
            || ($response['automatic_ota_write'] ?? false) === true
            || ($response['automatic_pms_write'] ?? false) === true
        ) {
            throw new \InvalidArgumentException('执行证据不得声明系统自动操作 OTA 或 PMS');
        }
    }

    /** @param array<string, mixed> $intent */
    private function assertOperatingTargetIntentSourceIsCurrent(array $intent): void
    {
        $hotelId = (int)($intent['hotel_id'] ?? 0);
        $sourceRecordId = (int)($intent['source_record_id'] ?? 0);
        $targetDate = trim((string)($intent['date_start'] ?? ''));
        $evidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
        $storedDigest = strtolower(trim((string)($evidence['operating_target_source_digest'] ?? '')));
        if ($hotelId <= 0
            || $sourceRecordId <= 0
            || $targetDate === ''
            || ($evidence['operating_target_provenance_contract'] ?? '')
                !== OperatingTargetExecutionProvenanceService::CONTRACT_VERSION
            || preg_match('/^[a-f0-9]{64}$/D', $storedDigest) !== 1
        ) {
            throw new \InvalidArgumentException(
                'operating target execution provenance is required before approval'
            );
        }

        $tenantId = $this->tenantIdForHotel($hotelId);
        $sourceRow = Db::name('operating_target_daily_records')
            ->where('id', $sourceRecordId)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('target_date', $targetDate)
            ->lock(true)
            ->find();
        if (!is_array($sourceRow)) {
            throw new \InvalidArgumentException(
                'operating target source is missing; create a new execution intent'
            );
        }
        $this->afterOperatingTargetSourceLockedForApproval($intent, $sourceRow);

        $current = (new OperatingTargetService())->current(
            $tenantId,
            $hotelId,
            $targetDate
        );
        $record = is_array($current['record'] ?? null) ? $current['record'] : null;
        if ($record === null || (int)($record['id'] ?? 0) !== $sourceRecordId) {
            throw new \InvalidArgumentException(
                'operating target source is missing; create a new execution intent'
            );
        }
        $currentDigest = (new OperatingTargetExecutionProvenanceService())->digest($record);
        if (!hash_equals($storedDigest, $currentDigest)) {
            throw new \InvalidArgumentException(
                'operating target source changed; create a new execution intent'
            );
        }
        $facts = is_array($record['facts'] ?? null) ? $record['facts'] : [];
        $calculation = is_array($record['calculation'] ?? null) ? $record['calculation'] : [];
        if (!in_array((string)($facts['quality_status'] ?? ''), ['verified', 'manual_confirmed'], true)
            || (string)($calculation['status'] ?? '') === 'blocked'
        ) {
            throw new \InvalidArgumentException(
                'operating target facts are no longer actionable'
            );
        }
    }

    /**
     * Transaction-bound extension point for lock-boundary verification.
     *
     * @param array<string, mixed> $intent
     * @param array<string, mixed> $sourceRow
     */
    protected function afterOperatingTargetSourceLockedForApproval(array $intent, array $sourceRow): void
    {
    }

    /** @param array<string, mixed> $intent */
    private function assertPublicPageDiagnosisIntentReadyForApproval(array $intent): void
    {
        if (!$this->hasVerifiedPublicPageDiagnosisProvenance($intent, ['intent_id' => (int)($intent['id'] ?? 0)])) {
            throw new \InvalidArgumentException('public-page diagnosis provenance is invalid');
        }

        $hotelId = (int)($intent['hotel_id'] ?? 0);
        $platform = strtolower(trim((string)($intent['platform'] ?? '')));
        $businessDate = substr(trim((string)($intent['date_start'] ?? '')), 0, 10);
        try {
            $profiles = match ($platform) {
                'ctrip' => (new CtripPublicHotelProfileService())->listDiagnosisProfiles($hotelId, $businessDate),
                'meituan' => (new MeituanPublicPageEvidenceService())->listDiagnosisProfiles($hotelId, $businessDate),
                default => [],
            };
            $diagnosisService = new OtaPublicPageDiagnosisService();
            $diagnosis = $diagnosisService->build($hotelId, $platform, $businessDate, $profiles);
            $timezone = new \DateTimeZone('Asia/Shanghai');
            $today = new \DateTimeImmutable('today', $timezone);
            $currentDraft = $diagnosisService->buildExecutionIntentDraft($diagnosis, [
                'assignee_id' => max(1, (int)($intent['created_by'] ?? 0)),
                'due_at' => $today->modify('+1 day')->setTime(18, 0)->format('Y-m-d H:i:s'),
                'review_at' => $today->modify('+2 days')->setTime(10, 0)->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException('public-page diagnosis source cannot be read back for approval', 0, $exception);
        }

        $evidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
        $currentInput = is_array($currentDraft['input'] ?? null) ? $currentDraft['input'] : [];
        $currentEvidence = is_array($currentInput['evidence'] ?? null) ? $currentInput['evidence'] : [];
        if ((int)($currentDraft['source_record_id'] ?? 0) !== (int)($intent['source_record_id'] ?? 0)
            || (string)($currentInput['action_type'] ?? '') !== (string)($intent['action_type'] ?? '')
            || (string)($currentInput['expected_metric'] ?? '') !== (string)($intent['expected_metric'] ?? '')
            || !hash_equals(
                strtolower(trim((string)($evidence['task_identity_fingerprint'] ?? ''))),
                strtolower(trim((string)($currentEvidence['task_identity_fingerprint'] ?? '')))
            )
        ) {
            throw new \InvalidArgumentException('public-page diagnosis source changed; create a new execution intent');
        }
    }

}
