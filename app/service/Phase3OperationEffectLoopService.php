<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

final class Phase3OperationEffectLoopService
{
    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT = 300;
    private const MAX_METRIC_WINDOW_ROWS = 2000;
    private const LEDGER_DIR = 'phase3_operation_effect_loop';
    private const SOP_LEDGER_FILE = 'sops.json';
    private const REPLICATION_LEDGER_FILE = 'replication_plans.json';

    public function build(array $options = []): array
    {
        $runId = trim((string)($options['run_id'] ?? ''));
        $scopeHotelId = $this->scopeHotelId($options);
        $patrolService = new DailyWorkbenchPatrolService();
        if ($scopeHotelId > 0) {
            $snapshot = $runId !== ''
                ? $patrolService->findByRunIdForHotel($runId, $scopeHotelId)
                : $patrolService->latestForHotel($scopeHotelId);
        } else {
            $snapshot = $runId !== '' ? $patrolService->findByRunId($runId) : $patrolService->latest();
        }
        if ($snapshot === null) {
            throw new \RuntimeException('Daily workbench patrol snapshot not found.');
        }

        return $this->buildFromSnapshot($snapshot, $options);
    }

    public function ledger(int $limit = 50): array
    {
        return $this->buildLedger($limit, null);
    }

    public function ledgerForHotel(int $hotelId, int $limit = 50): array
    {
        if ($hotelId <= 0) {
            throw new \InvalidArgumentException('hotel_id is required.');
        }

        return $this->buildLedger($limit, $hotelId);
    }

    private function buildLedger(int $limit, ?int $scopeHotelId): array
    {
        $limit = max(1, min(200, $limit));

        return [
            'phase' => 'phase3_operation_effect_loop_ledger',
            'scope' => [
                'metric_scope' => 'ota_channel',
                'source_policy' => 'read_runtime_phase3_operation_effect_loop_ledger_only',
                'collection_logic_changed' => false,
                'collection_fields_changed' => false,
                'raw_data_exposed' => false,
                'auto_decision_enabled' => false,
                'hotel_id' => $scopeHotelId,
            ],
            'sops' => $this->readLedger(self::SOP_LEDGER_FILE, $limit, $scopeHotelId),
            'replication_plans' => $this->readLedger(self::REPLICATION_LEDGER_FILE, $limit, $scopeHotelId),
        ];
    }

    public function publishSop(array $input, ?int $userId = null): array
    {
        $row = $this->resolveLoopRow($input);
        $this->assertLoopRowScope($row, $this->scopeHotelId($input));
        return $this->publishSopFromLoopRow($row, $input, $userId);
    }

    public function publishSopFromLoopRow(array $row, array $input = [], ?int $userId = null): array
    {
        $scopeHotelId = $this->scopeHotelId($input);
        $this->assertLoopRowScope($row, $scopeHotelId);
        $stages = is_array($row['stages'] ?? null) ? $row['stages'] : [];
        $sop = is_array($stages['sop'] ?? null) ? $stages['sop'] : [];
        if ((string)($sop['status'] ?? '') !== 'candidate') {
            $reasonCodes = implode(', ', array_values(array_filter((array)($sop['reason_codes'] ?? []))));
            throw new \InvalidArgumentException('SOP candidate is not ready' . ($reasonCodes !== '' ? ': ' . $reasonCodes : '.'));
        }

        $anomaly = is_array($stages['anomaly'] ?? null) ? $stages['anomaly'] : [];
        $action = is_array($stages['operation_action'] ?? null) ? $stages['operation_action'] : [];
        $execution = is_array($stages['execution_evidence'] ?? null) ? $stages['execution_evidence'] : [];
        $review = is_array($stages['effect_review'] ?? null) ? $stages['effect_review'] : [];
        $entry = [
            'id' => $this->ledgerId('sop', (string)($row['tracking_key'] ?? '')),
            'status' => 'published',
            'title' => $this->safeText((string)($input['title'] ?? 'OTA operation SOP - ' . ($sop['template_key'] ?? 'operation_action'))),
            'template_key' => (string)($sop['template_key'] ?? ''),
            'source_run_id' => (string)($input['run_id'] ?? $row['run_id'] ?? ''),
            'tracking_key' => (string)($row['tracking_key'] ?? ''),
            'hotel_id' => (int)($row['hotel_id'] ?? 0),
            'hotel_name' => (string)($row['hotel_name'] ?? ''),
            'target_date' => (string)($row['target_date'] ?? ''),
            'action_code' => (string)($action['action_code'] ?? ''),
            'question_key' => (string)($action['question_key'] ?? ''),
            'problem_codes' => $this->collectCodes($anomaly['data_gap_codes'] ?? [], $anomaly['ai_missing_codes'] ?? []),
            'action_text' => $this->safeText((string)($action['action_text'] ?? '')),
            'execution_evidence' => [
                'intent_id' => (int)($execution['intent_id'] ?? 0),
                'task_id' => (int)($execution['task_id'] ?? 0),
                'status' => (string)($execution['status'] ?? ''),
                'evidence_status' => (string)($execution['evidence_status'] ?? ''),
            ],
            'effect_review' => [
                'status' => (string)($review['status'] ?? ''),
                'result_status' => (string)($review['result_status'] ?? ''),
                'result_summary' => $this->safeText((string)($review['result_summary'] ?? '')),
                'metric_window_status' => (string)($review['metric_window_status'] ?? ''),
                'causality_claimed' => false,
            ],
            'steps' => [
                'Verify OTA anomaly evidence and missing codes.',
                'Execute the approved operator action and record evidence.',
                'Review target-date and next-window OTA metrics before promoting the action.',
                'Apply to other hotels only after manual confirmation.',
            ],
            'published_at' => date('Y-m-d H:i:s'),
            'published_by_user_id' => $userId,
            'metric_scope' => 'ota_channel',
            'source_policy' => 'runtime_phase3_sop_ledger_from_reviewed_patrol_action',
            'raw_data_exposed' => false,
            'auto_publish_enabled' => false,
            'auto_apply_enabled' => false,
        ];

        return [
            'sop' => $this->appendLedger(self::SOP_LEDGER_FILE, $entry, $scopeHotelId > 0 ? $scopeHotelId : null),
            'ledger' => $scopeHotelId > 0 ? $this->ledgerForHotel($scopeHotelId) : $this->ledger(),
        ];
    }

    public function createReplicationPlan(array $input, ?int $userId = null): array
    {
        $row = $this->resolveLoopRow($input);
        $this->assertLoopRowScope($row, $this->scopeHotelId($input));
        return $this->createReplicationPlanFromLoopRow($row, $input, $userId);
    }

    public function createReplicationPlanFromLoopRow(array $row, array $input = [], ?int $userId = null): array
    {
        $scopeHotelId = $this->scopeHotelId($input);
        $this->assertLoopRowScope($row, $scopeHotelId);
        $stages = is_array($row['stages'] ?? null) ? $row['stages'] : [];
        $sop = is_array($stages['sop'] ?? null) ? $stages['sop'] : [];
        $replication = is_array($stages['replication'] ?? null) ? $stages['replication'] : [];
        if ((string)($sop['status'] ?? '') !== 'candidate') {
            throw new \InvalidArgumentException('Replication plan requires a ready SOP candidate.');
        }
        if ((string)($replication['status'] ?? '') !== 'candidate') {
            $reasonCodes = implode(', ', array_values(array_filter((array)($replication['reason_codes'] ?? []))));
            throw new \InvalidArgumentException('Replication candidate is not ready' . ($reasonCodes !== '' ? ': ' . $reasonCodes : '.'));
        }

        $action = is_array($stages['operation_action'] ?? null) ? $stages['operation_action'] : [];
        $targets = array_values(array_filter((array)($replication['target_hotels'] ?? []), static fn($item): bool => is_array($item)));
        $entry = [
            'id' => $this->ledgerId('replication', (string)($row['tracking_key'] ?? '')),
            'status' => 'draft',
            'source_run_id' => (string)($input['run_id'] ?? $row['run_id'] ?? ''),
            'source_tracking_key' => (string)($row['tracking_key'] ?? ''),
            'source_hotel_id' => (int)($row['hotel_id'] ?? 0),
            'source_hotel_name' => (string)($row['hotel_name'] ?? ''),
            'template_key' => (string)($sop['template_key'] ?? ''),
            'action_code' => (string)($action['action_code'] ?? ''),
            'question_key' => (string)($action['question_key'] ?? ''),
            'target_hotels' => array_map(static function (array $target): array {
                return [
                    'hotel_id' => (int)($target['hotel_id'] ?? 0),
                    'hotel_name' => (string)($target['hotel_name'] ?? ''),
                    'matched_key' => (string)($target['matched_key'] ?? ''),
                    'status' => 'pending_manual_confirmation',
                ];
            }, $targets),
            'required_checks' => [
                'Confirm target hotel has the same OTA anomaly pattern.',
                'Confirm price, inventory, traffic, and conversion context are comparable.',
                'Assign an operator and evidence requirement before execution.',
                'Review the target hotel effect window after execution.',
            ],
            'created_at' => date('Y-m-d H:i:s'),
            'created_by_user_id' => $userId,
            'metric_scope' => 'ota_channel',
            'source_policy' => 'runtime_phase3_replication_plan_from_reviewed_sop_candidate',
            'raw_data_exposed' => false,
            'auto_apply_enabled' => false,
            'auto_decision_enabled' => false,
        ];

        return [
            'replication_plan' => $this->appendLedger(self::REPLICATION_LEDGER_FILE, $entry, $scopeHotelId > 0 ? $scopeHotelId : null),
            'ledger' => $scopeHotelId > 0 ? $this->ledgerForHotel($scopeHotelId) : $this->ledger(),
        ];
    }

    public function buildFromSnapshot(array $snapshot, array $options = []): array
    {
        $scopeHotelId = $this->scopeHotelId($options);
        if ($scopeHotelId > 0) {
            $snapshotScope = is_array($snapshot['scope'] ?? null) ? $snapshot['scope'] : [];
            if ((int)($snapshotScope['hotel_id'] ?? 0) !== $scopeHotelId) {
                throw new \RuntimeException('Daily workbench patrol snapshot is outside the selected hotel scope.');
            }
        }
        $scope = is_array($snapshot['scope'] ?? null) ? $snapshot['scope'] : [];
        $targetDate = $this->normalizeDate((string)($options['target_date'] ?? $scope['target_date'] ?? date('Y-m-d')));
        $limit = $this->normalizeLimit($options['limit'] ?? self::DEFAULT_LIMIT);
        $rows = $this->snapshotRows($snapshot);
        $actions = array_slice($this->snapshotActions($snapshot, $rows), 0, $limit);
        $trackingItems = is_array($snapshot['action_tracking']['items'] ?? null) ? $snapshot['action_tracking']['items'] : [];
        $hotelIds = array_values(array_unique(array_filter(array_map(
            static fn(array $action): int => (int)($action['hotel_id'] ?? 0),
            $actions
        ))));
        $platformsByHotel = [];
        foreach ($actions as $action) {
            $hotelId = (int)($action['hotel_id'] ?? 0);
            $platform = $this->normalizeEffectPlatform((string)($action['platform'] ?? ''));
            if ($hotelId > 0 && $platform !== '') {
                $platformsByHotel[$hotelId][$platform] = true;
            }
        }
        $platformsByHotel = array_map(static fn(array $platforms): array => array_keys($platforms), $platformsByHotel);
        $metricWindow = is_array($options['metric_window'] ?? null)
            ? $options['metric_window']
            : $this->loadOtaMetricWindow($targetDate, $hotelIds, $platformsByHotel);
        $similarIndex = $this->buildSimilarIndex($actions);

        $loopRows = [];
        foreach ($actions as $action) {
            $hotelId = (int)($action['hotel_id'] ?? 0);
            $actionCode = trim((string)($action['action_code'] ?? ''));
            $questionKey = trim((string)($action['question_key'] ?? ''));
            $trackingKey = $this->actionTrackingKey($hotelId, $actionCode, $questionKey);
            $tracked = is_array($trackingItems[$trackingKey] ?? null) ? $trackingItems[$trackingKey] : [];
            $sourceRow = $this->findHotelRow($rows, $hotelId);
            $hotelMetricWindow = is_array($metricWindow['by_hotel'][$hotelId] ?? null)
                ? $metricWindow['by_hotel'][$hotelId]
                : $this->missingMetricWindow($targetDate);

            $anomaly = $this->buildAnomalyStage($action, $sourceRow, $targetDate);
            $operationAction = $this->buildOperationActionStage($action);
            $executionEvidence = $this->buildExecutionEvidenceStage($tracked);
            $effectReview = $this->buildEffectReviewStage($executionEvidence, $tracked, $hotelMetricWindow);
            $sop = $this->buildSopStage($action, $executionEvidence, $effectReview);
            $replication = $this->buildReplicationStage($action, $sop, $similarIndex);

            $loopRows[] = [
                'tracking_key' => $trackingKey,
                'run_id' => (string)($snapshot['run_id'] ?? ''),
                'hotel_id' => $hotelId,
                'hotel_name' => (string)($action['hotel_name'] ?? $sourceRow['hotel_name'] ?? ''),
                'target_date' => $targetDate,
                'hotel_id' => $scopeHotelId > 0 ? $scopeHotelId : ($scope['hotel_id'] ?? null),
                'metric_scope' => 'ota_channel',
                'source_policy' => 'read_existing_daily_workbench_patrol_snapshot_and_online_daily_data_only',
                'stages' => [
                    'anomaly' => $anomaly,
                    'operation_action' => $operationAction,
                    'execution_evidence' => $executionEvidence,
                    'effect_review' => $effectReview,
                    'sop' => $sop,
                    'replication' => $replication,
                ],
            ];
        }

        return [
            'phase' => 'phase3_operation_effect_loop',
            'run_id' => (string)($snapshot['run_id'] ?? ''),
            'snapshot_type' => (string)($snapshot['snapshot_type'] ?? ''),
            'created_at' => date('Y-m-d H:i:s'),
            'scope' => [
                'metric_scope' => 'ota_channel',
                'target_date' => $targetDate,
                'source_run_id' => (string)($snapshot['run_id'] ?? ''),
                'source_snapshot_type' => (string)($snapshot['snapshot_type'] ?? ''),
                'source_policy' => 'read_existing_daily_workbench_patrol_snapshot_and_online_daily_data_only',
                'collection_logic_changed' => false,
                'collection_fields_changed' => false,
                'manual_collection_logic_changed' => false,
                'automatic_collection_logic_changed' => false,
                'raw_data_exposed' => false,
                'auto_decision_enabled' => false,
            ],
            'summary' => $this->summarizeLoopRows($loopRows, $trackingItems),
            'rows' => $loopRows,
            'sop_candidates' => $this->candidateRows($loopRows, 'sop'),
            'replication_candidates' => $this->candidateRows($loopRows, 'replication'),
            'metric_window' => [
                'status' => (string)($metricWindow['status'] ?? 'unknown'),
                'target_date' => $targetDate,
                'previous_date' => (string)($metricWindow['previous_date'] ?? $this->previousDate($targetDate)),
                'data_gaps' => array_values(array_filter((array)($metricWindow['data_gaps'] ?? []))),
                'truncated' => ($metricWindow['truncated'] ?? false) === true,
                'record_limit' => (int)($metricWindow['record_limit'] ?? self::MAX_METRIC_WINDOW_ROWS),
                'records_scanned' => (int)($metricWindow['records_scanned'] ?? 0),
                'source_policy' => 'read_existing_online_daily_data_only_without_raw_data',
            ],
            'boundaries' => [
                'metric_scope' => 'ota_channel',
                'business_scope' => 'OTA channel only; not whole-hotel operating truth.',
                'source_policy' => 'read_existing_daily_workbench_patrol_snapshot_and_online_daily_data_only',
                'collection_logic_changed' => false,
                'collection_fields_changed' => false,
                'manual_collection_logic_changed' => false,
                'automatic_collection_logic_changed' => false,
                'raw_data_exposed' => false,
                'sensitive_credentials_exposed' => false,
                'auto_decision_enabled' => false,
                'protected_boundary' => 'Phase 3 only organizes patrol anomalies, operator actions, evidence, reviews, SOP candidates, and replication candidates; it does not change Ctrip or Meituan acquisition logic, fields, routes, or storage mappings.',
                'causality_policy' => 'Effect review is observational unless execution evidence, operator review, and metric window are all present.',
            ],
        ];
    }

    private function snapshotRows(array $snapshot): array
    {
        return array_values(array_filter((array)($snapshot['rows'] ?? []), static fn($row): bool => is_array($row)));
    }

    private function snapshotActions(array $snapshot, array $rows): array
    {
        $actions = [];
        foreach ((array)($snapshot['next_actions'] ?? []) as $action) {
            if (is_array($action)) {
                $actions[] = $action;
            }
        }
        foreach ($rows as $row) {
            $action = is_array($row['next_action'] ?? null) ? $row['next_action'] : [];
            if ($action !== []) {
                $actions[] = array_replace([
                    'hotel_id' => (int)($row['hotel_id'] ?? 0),
                    'hotel_name' => (string)($row['hotel_name'] ?? ''),
                    'target_date' => (string)($row['target_date'] ?? ''),
                ], $action);
            }
        }

        $seen = [];
        $deduped = [];
        foreach ($actions as $action) {
            $hotelId = (int)($action['hotel_id'] ?? 0);
            $actionCode = trim((string)($action['action_code'] ?? ''));
            $questionKey = trim((string)($action['question_key'] ?? ''));
            if ($hotelId <= 0 || ($actionCode === '' && $questionKey === '')) {
                continue;
            }
            $key = $this->actionTrackingKey($hotelId, $actionCode, $questionKey);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $action;
        }

        return $deduped;
    }

    private function buildAnomalyStage(array $action, array $row, string $targetDate): array
    {
        $aiEvidence = is_array($row['ai_evidence'] ?? null) ? $row['ai_evidence'] : [];
        $explanation = is_array($aiEvidence['explanation'] ?? null) ? $aiEvidence['explanation'] : [];
        $dataGapCodes = $this->collectCodes(
            $action['data_gaps'] ?? [],
            $action['blocking_missing_codes'] ?? [],
            $row['metric_diagnosis']['data_gap_codes'] ?? [],
            $row['operation_execution']['blocking_missing_codes'] ?? []
        );
        $aiMissingCodes = $this->collectCodes(
            $aiEvidence['blocking_missing_codes'] ?? [],
            $explanation['missing_codes'] ?? []
        );

        return [
            'status' => $row === [] ? 'source_row_missing' : 'patrol_anomaly_confirmed',
            'hotel_id' => (int)($action['hotel_id'] ?? $row['hotel_id'] ?? 0),
            'hotel_name' => (string)($action['hotel_name'] ?? $row['hotel_name'] ?? ''),
            'target_date' => (string)($action['target_date'] ?? $row['target_date'] ?? $targetDate),
            'platform' => (string)($action['platform'] ?? 'ota'),
            'question_key' => (string)($action['question_key'] ?? ''),
            'data_gap_codes' => $dataGapCodes,
            'ai_missing_codes' => $aiMissingCodes,
            'diagnosis_status' => (string)($aiEvidence['diagnosis_status'] ?? $aiEvidence['status'] ?? 'unknown'),
            'summary' => $this->safeText((string)($explanation['summary'] ?? $action['reason'] ?? $action['action'] ?? '')),
            'source_policy' => 'read_existing_daily_workbench_patrol_snapshot_only',
        ];
    }

    private function buildOperationActionStage(array $action): array
    {
        return [
            'status' => 'action_required',
            'action_code' => (string)($action['action_code'] ?? ''),
            'question_key' => (string)($action['question_key'] ?? ''),
            'priority' => (string)($action['priority'] ?? 'medium'),
            'action_text' => $this->safeText((string)($action['action'] ?? $action['reason'] ?? '')),
            'entry' => (string)($action['entry'] ?? ''),
            'owner_status' => 'owner_missing',
            'source_policy' => 'read_existing_daily_workbench_patrol_action_only',
        ];
    }

    private function buildExecutionEvidenceStage(array $tracked): array
    {
        if ($tracked === []) {
            return [
                'status' => 'execution_missing',
                'tracking_status' => 'not_tracked',
                'intent_id' => 0,
                'task_id' => 0,
                'task_status' => '',
                'evidence_status' => 'missing',
                'missing_codes' => ['action_status_missing', 'operation_execution_missing', 'execution_evidence_missing'],
                'source_policy' => 'read_existing_action_tracking_only',
            ];
        }

        $execution = is_array($tracked['operation_execution'] ?? null) ? $tracked['operation_execution'] : [];
        $trackingStatus = (string)($tracked['status'] ?? 'pending');
        $taskStatus = (string)($execution['task_status'] ?? '');
        $intentId = (int)($execution['intent_id'] ?? 0);
        $taskId = (int)($execution['task_id'] ?? 0);
        $evidenceTruth = is_array($execution['evidence_truth'] ?? null) ? $execution['evidence_truth'] : [];
        $outcomeTruth = is_array($execution['outcome_truth'] ?? null) ? $execution['outcome_truth'] : [];
        $truthContext = is_array($execution['truth_context'] ?? null) ? $execution['truth_context'] : [];
        $evidenceCount = max(0, (int)($execution['execution_evidence_count'] ?? $evidenceTruth['evidence_count'] ?? 0));
        $sourceVerified = $evidenceCount > 0
            && ($evidenceTruth['source_verified'] ?? false) === true
            && (string)($evidenceTruth['status'] ?? '') === 'verified';
        $outcomeVerified = ($outcomeTruth['outcome_verified'] ?? false) === true;
        $positiveOutcomeVerified = $outcomeVerified
            && ($outcomeTruth['positive_outcome_verified'] ?? false) === true;
        $truthContextStatus = (string)($truthContext['status'] ?? 'unverified');
        $missingCodes = [];
        if ($intentId <= 0) {
            $missingCodes[] = 'operation_execution_intent_missing';
        }
        if ($taskId <= 0) {
            $missingCodes[] = 'operation_execution_task_missing';
        }
        if ($trackingStatus !== 'done' && $taskStatus !== 'executed') {
            $missingCodes[] = 'execution_not_completed';
        }
        if ($taskStatus === 'executed' && $evidenceCount <= 0) {
            $missingCodes[] = 'execution_evidence_missing';
        }
        if ($taskStatus === 'executed' && !$sourceVerified) {
            $missingCodes[] = 'execution_evidence_source_unverified';
        }
        if ($taskStatus === 'executed' && !$outcomeVerified) {
            $missingCodes[] = 'execution_outcome_unverified';
        }
        if ($taskStatus === 'executed' && !$positiveOutcomeVerified) {
            $missingCodes[] = 'execution_positive_outcome_unverified';
        }

        $status = 'execution_in_progress';
        $evidenceStatus = 'pending';
        if ($trackingStatus === 'skipped') {
            $status = 'skipped';
            $evidenceStatus = 'not_applicable';
        } elseif ($taskStatus === 'executed' && $sourceVerified) {
            $status = 'executed_evidence_recorded';
            $evidenceStatus = 'source_verified';
        } elseif ($taskStatus === 'executed') {
            $status = 'executed_evidence_unverified';
            $evidenceStatus = 'unverified';
        } elseif ($trackingStatus === 'done') {
            $status = 'done_without_execution_task';
            $evidenceStatus = 'missing';
            $missingCodes[] = 'execution_task_not_executed';
        }

        return [
            'status' => $status,
            'tracking_status' => $trackingStatus,
            'intent_id' => $intentId,
            'intent_status' => (string)($execution['intent_status'] ?? ''),
            'task_id' => $taskId,
            'task_status' => $taskStatus,
            'evidence_status' => $evidenceStatus,
            'evidence_count' => $evidenceCount,
            'source_verified' => $sourceVerified,
            'outcome_status' => (string)($outcomeTruth['status'] ?? 'unverified'),
            'outcome_verified' => $outcomeVerified,
            'positive_outcome_verified' => $positiveOutcomeVerified,
            'truth_context_status' => $truthContextStatus,
            'note' => $this->safeText((string)($tracked['note'] ?? '')),
            'updated_at' => (string)($tracked['updated_at'] ?? ''),
            'missing_codes' => array_values(array_unique($missingCodes)),
            'source_policy' => 'read_existing_action_tracking_and_operation_execution_only',
        ];
    }

    private function buildEffectReviewStage(array $executionEvidence, array $tracked, array $metricWindow): array
    {
        $review = is_array($tracked['review_result'] ?? null) ? $tracked['review_result'] : [];
        $execution = is_array($tracked['operation_execution'] ?? null) ? $tracked['operation_execution'] : [];
        $reviewStatus = (string)($review['result_status'] ?? $execution['review_status'] ?? '');
        $reviewSummary = $this->safeText((string)($review['result_summary'] ?? $execution['review_summary'] ?? ''));
        $metricStatus = (string)($metricWindow['status'] ?? 'metric_window_missing');
        $reasonCodes = array_values(array_filter((array)($metricWindow['data_gaps'] ?? [])));

        if (($executionEvidence['status'] ?? '') === 'execution_missing') {
            $status = 'execution_missing';
            $reasonCodes[] = 'execution_missing';
        } elseif (!in_array((string)($executionEvidence['status'] ?? ''), ['executed_evidence_recorded'], true)) {
            $status = 'execution_incomplete';
            $reasonCodes[] = 'execution_evidence_not_ready';
        } elseif ($reviewStatus === '') {
            $status = 'review_missing';
            $reasonCodes[] = 'operator_review_missing';
        } elseif ($reviewStatus === 'observing') {
            $status = 'observing';
        } elseif (in_array($reviewStatus, ['success', 'near_success'], true)
            && ($executionEvidence['outcome_verified'] ?? false) !== true
        ) {
            $status = 'outcome_unverified';
            $reasonCodes[] = 'execution_outcome_unverified';
        } elseif (in_array($reviewStatus, ['success', 'near_success'], true)
            && ($executionEvidence['positive_outcome_verified'] ?? false) !== true
        ) {
            $status = 'outcome_unverified';
            $reasonCodes[] = 'execution_positive_outcome_unverified';
        } elseif (in_array($reviewStatus, ['success', 'near_success'], true)
            && (string)($executionEvidence['truth_context_status'] ?? '') !== 'verified'
        ) {
            $status = 'outcome_unverified';
            $reasonCodes[] = 'execution_truth_context_unverified';
        } else {
            $status = 'reviewed';
        }

        if ($metricStatus !== 'ready') {
            $reasonCodes[] = $metricStatus !== '' ? $metricStatus : 'metric_window_missing';
        }

        return [
            'status' => $status,
            'result_status' => $reviewStatus,
            'result_summary' => $reviewSummary,
            'metric_window_status' => $metricStatus,
            'outcome_status' => (string)($executionEvidence['outcome_status'] ?? 'unverified'),
            'outcome_verified' => ($executionEvidence['outcome_verified'] ?? false) === true,
            'positive_outcome_verified' => ($executionEvidence['positive_outcome_verified'] ?? false) === true,
            'metric_window' => [
                'current' => is_array($metricWindow['current'] ?? null) ? $metricWindow['current'] : [],
                'previous' => is_array($metricWindow['previous'] ?? null) ? $metricWindow['previous'] : [],
                'delta' => is_array($metricWindow['delta'] ?? null) ? $metricWindow['delta'] : [],
                'missing_dates' => array_values(array_filter((array)($metricWindow['missing_dates'] ?? []))),
            ],
            'causality_claimed' => false,
            'reason_codes' => array_values(array_unique($reasonCodes)),
            'source_policy' => 'read_existing_operator_review_and_online_daily_data_window_only',
        ];
    }

    private function buildSopStage(array $action, array $executionEvidence, array $effectReview): array
    {
        $ready = in_array((string)($effectReview['result_status'] ?? ''), ['success', 'near_success'], true)
            && (string)($effectReview['status'] ?? '') === 'reviewed'
            && (string)($executionEvidence['status'] ?? '') === 'executed_evidence_recorded'
            && (int)($executionEvidence['evidence_count'] ?? 0) > 0
            && ($executionEvidence['source_verified'] ?? false) === true
            && ($executionEvidence['outcome_verified'] ?? false) === true
            && ($executionEvidence['positive_outcome_verified'] ?? false) === true
            && (string)($executionEvidence['truth_context_status'] ?? '') === 'verified'
            && (string)($effectReview['metric_window_status'] ?? '') === 'ready';
        $reasonCodes = [];
        if ((int)($executionEvidence['evidence_count'] ?? 0) <= 0) {
            $reasonCodes[] = 'execution_evidence_missing';
        }
        if (($executionEvidence['source_verified'] ?? false) !== true) {
            $reasonCodes[] = 'execution_evidence_source_unverified';
        }
        if (($executionEvidence['outcome_verified'] ?? false) !== true) {
            $reasonCodes[] = 'execution_outcome_unverified';
        }
        if (($executionEvidence['positive_outcome_verified'] ?? false) !== true) {
            $reasonCodes[] = 'execution_positive_outcome_unverified';
        }
        if ((string)($executionEvidence['truth_context_status'] ?? '') !== 'verified') {
            $reasonCodes[] = 'execution_truth_context_unverified';
        }
        if (!in_array((string)($effectReview['result_status'] ?? ''), ['success', 'near_success'], true)) {
            $reasonCodes[] = 'effective_review_missing';
        }
        if ((string)($effectReview['metric_window_status'] ?? '') !== 'ready') {
            $reasonCodes[] = 'metric_window_missing';
        }

        return [
            'status' => $ready ? 'candidate' : 'not_ready',
            'template_key' => $this->sopTemplateKey($action),
            'reason_codes' => array_values(array_unique($reasonCodes)),
            'source_policy' => 'candidate_from_source_verified_execution_and_verified_positive_outcome_only',
            'auto_publish_enabled' => false,
        ];
    }

    private function buildReplicationStage(array $action, array $sop, array $similarIndex): array
    {
        if ((string)($sop['status'] ?? '') !== 'candidate') {
            return [
                'status' => 'not_ready',
                'target_hotels' => [],
                'reason_codes' => ['sop_candidate_missing'],
                'source_policy' => 'similarity_from_same_patrol_snapshot_only',
                'auto_apply_enabled' => false,
            ];
        }

        $sourceHotelId = (int)($action['hotel_id'] ?? 0);
        $keys = $this->similarityKeys($action);
        $targets = [];
        foreach ($keys as $key) {
            foreach ((array)($similarIndex[$key] ?? []) as $candidate) {
                if ((int)($candidate['hotel_id'] ?? 0) === $sourceHotelId) {
                    continue;
                }
                $targets[(int)$candidate['hotel_id']] = [
                    'hotel_id' => (int)($candidate['hotel_id'] ?? 0),
                    'hotel_name' => (string)($candidate['hotel_name'] ?? ''),
                    'matched_key' => $key,
                    'question_key' => (string)($candidate['question_key'] ?? ''),
                    'action_code' => (string)($candidate['action_code'] ?? ''),
                ];
            }
        }

        return [
            'status' => $targets === [] ? 'not_ready' : 'candidate',
            'target_hotels' => array_values($targets),
            'reason_codes' => $targets === [] ? ['similar_hotel_missing_in_snapshot'] : [],
            'source_policy' => 'similarity_from_same_patrol_snapshot_only',
            'auto_apply_enabled' => false,
        ];
    }

    private function summarizeLoopRows(array $loopRows, array $trackingItems): array
    {
        $summary = [
            'anomaly_count' => count($loopRows),
            'action_count' => count($loopRows),
            'tracked_action_count' => count($trackingItems),
            'executed_action_count' => 0,
            'effect_review_ready_count' => 0,
            'reviewed_action_count' => 0,
            'observing_action_count' => 0,
            'sop_candidate_count' => 0,
            'replication_candidate_count' => 0,
        ];
        foreach ($loopRows as $row) {
            $stages = is_array($row['stages'] ?? null) ? $row['stages'] : [];
            $execution = is_array($stages['execution_evidence'] ?? null) ? $stages['execution_evidence'] : [];
            $review = is_array($stages['effect_review'] ?? null) ? $stages['effect_review'] : [];
            $sop = is_array($stages['sop'] ?? null) ? $stages['sop'] : [];
            $replication = is_array($stages['replication'] ?? null) ? $stages['replication'] : [];
            if ((string)($execution['status'] ?? '') === 'executed_evidence_recorded') {
                $summary['executed_action_count']++;
            }
            if (in_array((string)($review['status'] ?? ''), ['review_missing', 'observing', 'reviewed'], true)) {
                $summary['effect_review_ready_count']++;
            }
            if (in_array((string)($review['result_status'] ?? ''), ['success', 'near_success', 'failed'], true)) {
                $summary['reviewed_action_count']++;
            }
            if ((string)($review['result_status'] ?? '') === 'observing') {
                $summary['observing_action_count']++;
            }
            if ((string)($sop['status'] ?? '') === 'candidate') {
                $summary['sop_candidate_count']++;
            }
            if ((string)($replication['status'] ?? '') === 'candidate') {
                $summary['replication_candidate_count']++;
            }
        }

        return $summary;
    }

    private function candidateRows(array $loopRows, string $stage): array
    {
        $candidates = [];
        foreach ($loopRows as $row) {
            $stagePayload = $row['stages'][$stage] ?? null;
            if (!is_array($stagePayload) || (string)($stagePayload['status'] ?? '') !== 'candidate') {
                continue;
            }
            $candidates[] = [
                'hotel_id' => (int)($row['hotel_id'] ?? 0),
                'hotel_name' => (string)($row['hotel_name'] ?? ''),
                'tracking_key' => (string)($row['tracking_key'] ?? ''),
                'stage' => $stage,
                'payload' => $stagePayload,
            ];
        }

        return $candidates;
    }

    private function resolveLoopRow(array $input): array
    {
        $payload = $this->build($input);
        $trackingKey = trim((string)($input['tracking_key'] ?? ''));
        $hotelId = isset($input['hotel_id']) && is_numeric($input['hotel_id']) ? (int)$input['hotel_id'] : 0;
        $actionCode = trim((string)($input['action_code'] ?? ''));
        $questionKey = trim((string)($input['question_key'] ?? ''));
        if ($trackingKey === '' && $hotelId > 0 && ($actionCode !== '' || $questionKey !== '')) {
            $trackingKey = $this->actionTrackingKey($hotelId, $actionCode, $questionKey);
        }
        foreach ((array)($payload['rows'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($trackingKey !== '' && (string)($row['tracking_key'] ?? '') === $trackingKey) {
                return $row;
            }
            if ($hotelId > 0 && (int)($row['hotel_id'] ?? 0) === $hotelId) {
                $action = is_array($row['stages']['operation_action'] ?? null) ? $row['stages']['operation_action'] : [];
                $rowActionCode = trim((string)($action['action_code'] ?? ''));
                $rowQuestionKey = trim((string)($action['question_key'] ?? ''));
                if (($actionCode !== '' && $rowActionCode === $actionCode) || ($questionKey !== '' && $rowQuestionKey === $questionKey)) {
                    return $row;
                }
            }
        }

        throw new \RuntimeException('Phase 3 operation effect loop row not found.');
    }

    private function readLedger(string $fileName, int $limit, ?int $scopeHotelId = null): array
    {
        $path = $this->ledgerPath($fileName, $scopeHotelId);
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string)file_get_contents($path), true);
        $items = is_array($decoded['items'] ?? null) ? $decoded['items'] : [];
        return array_slice(array_values(array_filter($items, static fn($item): bool => is_array($item))), 0, $limit);
    }

    private function appendLedger(string $fileName, array $entry, ?int $scopeHotelId = null): array
    {
        $path = $this->ledgerPath($fileName, $scopeHotelId);
        $decoded = is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
        $items = is_array($decoded['items'] ?? null) ? $decoded['items'] : [];
        array_unshift($items, $entry);
        $payload = [
            'updated_at' => date('Y-m-d H:i:s'),
            'metric_scope' => 'ota_channel',
            'source_policy' => 'runtime_phase3_operation_effect_loop_ledger_only',
            'collection_logic_changed' => false,
            'raw_data_exposed' => false,
            'items' => array_slice($items, 0, 500),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException('Phase 3 operation effect loop ledger write failed.');
        }

        return $entry;
    }

    private function ledgerPath(string $fileName, ?int $scopeHotelId = null): string
    {
        $safeFile = preg_replace('/[^a-zA-Z0-9_.-]+/', '', $fileName) ?: $fileName;
        $dir = rtrim(runtime_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::LEDGER_DIR;
        if ($scopeHotelId !== null && $scopeHotelId > 0) {
            $dir .= DIRECTORY_SEPARATOR . 'hotel_' . $scopeHotelId;
        }
        $this->ensureDirectory($dir);
        return $dir . DIRECTORY_SEPARATOR . $safeFile;
    }

    private function scopeHotelId(array $input): int
    {
        return isset($input['scope_hotel_id']) && is_numeric($input['scope_hotel_id'])
            ? max(0, (int)$input['scope_hotel_id'])
            : 0;
    }

    private function assertLoopRowScope(array $row, int $scopeHotelId): void
    {
        if ($scopeHotelId > 0 && (int)($row['hotel_id'] ?? 0) !== $scopeHotelId) {
            throw new \RuntimeException('Phase 3 operation effect loop row is outside the selected hotel scope.');
        }
    }

    private function ledgerId(string $prefix, string $seed): string
    {
        return 'phase3_' . $prefix . '_' . date('YmdHis') . '_' . substr(sha1($seed . microtime(true) . random_int(1, PHP_INT_MAX)), 0, 8);
    }

    private function loadOtaMetricWindow(string $targetDate, array $hotelIds, array $platformsByHotel = []): array
    {
        $previousDate = $this->previousDate($targetDate);
        if ($hotelIds === []) {
            return [
                'status' => 'hotel_scope_missing',
                'target_date' => $targetDate,
                'previous_date' => $previousDate,
                'by_hotel' => [],
                'data_gaps' => ['hotel_scope_missing'],
            ];
        }

        try {
            if (!$this->tableExists('online_daily_data')) {
                return [
                    'status' => 'online_daily_data_missing',
                    'target_date' => $targetDate,
                    'previous_date' => $previousDate,
                    'by_hotel' => [],
                    'data_gaps' => ['online_daily_data_missing'],
                ];
            }

            $columns = $this->tableColumns('online_daily_data');
            $required = ['system_hotel_id', 'data_date', 'readback_verified'];
            foreach ($required as $field) {
                if (!in_array($field, $columns, true)) {
                    return [
                        'status' => 'online_daily_data_schema_incomplete',
                        'target_date' => $targetDate,
                        'previous_date' => $previousDate,
                        'by_hotel' => [],
                        'data_gaps' => ['online_daily_data_schema_incomplete'],
                    ];
                }
            }

            $select = array_values(array_intersect([
                'id',
                'system_hotel_id',
                'hotel_id',
                'data_date',
                'source',
                'platform',
                'data_type',
                'dimension',
                'compare_type',
                'validation_status',
                'readback_verified',
                'data_period',
                'snapshot_time',
                'is_final',
                'update_time',
                'create_time',
                'amount',
                'quantity',
                'book_order_num',
                'data_value',
                'comment_score',
                'list_exposure',
                'detail_exposure',
                'flow_rate',
                'order_filling_num',
                'order_submit_num',
            ], $columns));

            $records = Db::name('online_daily_data')
                ->field($select)
                ->whereIn('system_hotel_id', $hotelIds)
                ->whereIn('data_date', [$targetDate, $previousDate])
                ->where('readback_verified', 1)
                ->limit(self::MAX_METRIC_WINDOW_ROWS + 1)
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            return [
                'status' => 'online_daily_data_unavailable',
                'target_date' => $targetDate,
                'previous_date' => $previousDate,
                'by_hotel' => [],
                'data_gaps' => ['online_daily_data_unavailable'],
                'error_class' => (new \ReflectionClass($e))->getShortName(),
            ];
        }

        $truncated = count($records) > self::MAX_METRIC_WINDOW_ROWS;
        if ($truncated) {
            $records = array_slice($records, 0, self::MAX_METRIC_WINDOW_ROWS);
        }
        $recordsScanned = count($records);
        $selectedRecords = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $systemHotelId = (int)($record['system_hotel_id'] ?? 0);
            $allowedPlatforms = is_array($platformsByHotel[$systemHotelId] ?? null)
                ? $platformsByHotel[$systemHotelId]
                : [];
            $role = $this->effectMetricRecordRole($record, $allowedPlatforms);
            if ($systemHotelId <= 0 || $role === '') {
                continue;
            }
            $date = (string)($record['data_date'] ?? '');
            $channel = $this->normalizeEffectPlatform((string)($record['source'] ?? $record['platform'] ?? ''));
            $key = $systemHotelId . '|' . $date . '|' . $channel . '|' . $role;
            $current = $selectedRecords[$key] ?? null;
            if (!is_array($current) || $this->preferEffectMetricRecord($record, $current, $role)) {
                $selectedRecords[$key] = $record;
            }
        }

        $byHotel = [];
        foreach ($hotelIds as $hotelId) {
            $byHotel[(int)$hotelId] = $this->missingMetricWindow($targetDate);
        }
        foreach ($selectedRecords as $record) {
            if (!is_array($record)) {
                continue;
            }
            $hotelId = (int)($record['system_hotel_id'] ?? 0);
            $date = (string)($record['data_date'] ?? '');
            if ($hotelId <= 0 || !in_array($date, [$targetDate, $previousDate], true)) {
                continue;
            }
            if (!isset($byHotel[$hotelId])) {
                $byHotel[$hotelId] = $this->missingMetricWindow($targetDate);
            }
            $key = $date === $targetDate ? 'current' : 'previous';
            $byHotel[$hotelId][$key] = $this->mergeMetricAggregate(
                is_array($byHotel[$hotelId][$key] ?? null) ? $byHotel[$hotelId][$key] : [],
                $record
            );
        }

        foreach ($byHotel as $hotelId => $window) {
            $missingDates = [];
            if ((int)($window['current']['source_row_count'] ?? 0) <= 0) {
                $missingDates[] = $targetDate;
            }
            if ((int)($window['previous']['source_row_count'] ?? 0) <= 0) {
                $missingDates[] = $previousDate;
            }
            $current = is_array($window['current'] ?? null) ? $window['current'] : [];
            $previous = is_array($window['previous'] ?? null) ? $window['previous'] : [];
            $byHotel[$hotelId]['status'] = $truncated
                ? 'metric_window_truncated'
                : ($missingDates === [] ? 'ready' : 'metric_window_missing');
            $byHotel[$hotelId]['missing_dates'] = $missingDates;
            $byHotel[$hotelId]['data_gaps'] = array_values(array_unique(array_merge(
                $missingDates === [] ? [] : ['metric_window_missing'],
                $truncated ? ['metric_window_truncated'] : []
            )));
            $byHotel[$hotelId]['truncated'] = $truncated;
            $byHotel[$hotelId]['delta'] = $this->metricDelta($current, $previous);
        }

        $missingCount = 0;
        foreach ($byHotel as $window) {
            if ((string)($window['status'] ?? '') !== 'ready') {
                $missingCount++;
            }
        }

        return [
            'status' => $missingCount === 0 && !$truncated ? 'ready' : 'partial',
            'target_date' => $targetDate,
            'previous_date' => $previousDate,
            'by_hotel' => $byHotel,
            'data_gaps' => array_values(array_unique(array_merge(
                $missingCount === 0 ? [] : ['metric_window_missing'],
                $truncated ? ['metric_window_truncated'] : []
            ))),
            'truncated' => $truncated,
            'record_limit' => self::MAX_METRIC_WINDOW_ROWS,
            'records_scanned' => $recordsScanned,
        ];
    }

    /** @param array<string, mixed> $record @param array<int, string> $allowedPlatforms */
    private function effectMetricRecordRole(array $record, array $allowedPlatforms = []): string
    {
        $validationStatus = strtolower(trim((string)($record['validation_status'] ?? '')));
        if (!in_array($validationStatus, [
            'normal',
            'available',
            'verified',
            'valid',
            'confirmed',
            'approved',
            'passed',
            'ok',
            'success',
            'complete',
            'completed',
        ], true)) {
            return '';
        }
        if ((int)($record['readback_verified'] ?? 0) !== 1) {
            return '';
        }
        $compareType = strtolower(trim((string)($record['compare_type'] ?? '')));
        if ($compareType !== '' && $compareType !== 'self') {
            return '';
        }
        $otaHotelId = trim((string)($record['hotel_id'] ?? ''));
        if ($otaHotelId !== '' && is_numeric($otaHotelId) && (float)$otaHotelId <= 0) {
            return '';
        }

        $source = $this->normalizeEffectPlatform((string)($record['source'] ?? ''));
        $platform = $this->normalizeEffectPlatform((string)($record['platform'] ?? ''));
        $knownPlatforms = ['ctrip', 'meituan', 'qunar'];
        $channel = $source !== '' ? $source : $platform;
        if (!in_array($channel, $knownPlatforms, true)) {
            return '';
        }
        if (in_array($source, $knownPlatforms, true)
            && in_array($platform, $knownPlatforms, true)
            && $source !== $platform
        ) {
            return '';
        }
        if ($allowedPlatforms !== [] && !in_array($source !== '' ? $source : $platform, $allowedPlatforms, true)) {
            return '';
        }

        $dataDate = substr(trim((string)($record['data_date'] ?? '')), 0, 10);
        $period = strtolower(trim((string)($record['data_period'] ?? '')));
        if ($dataDate === ''
            || $dataDate > date('Y-m-d')
            || $period !== 'historical_daily'
            || !array_key_exists('is_final', $record)
            || (int)$record['is_final'] !== 1
        ) {
            return '';
        }

        $dataType = strtolower(trim((string)($record['data_type'] ?? '')));
        $dimension = trim((string)($record['dimension'] ?? ''));
        if (in_array($dataType, ['business', 'business_overview', 'overview', 'operation', 'order', 'orders'], true) && $dimension === '') {
            return 'operating';
        }
        if (!in_array($dataType, ['traffic', 'flow', 'traffic_flow', 'traffic_overview'], true)) {
            return '';
        }
        $endpointId = '';
        if (preg_match('/^catalog:[^:]+:([^:]+)/', $dimension, $matches) === 1) {
            $endpointId = (string)($matches[1] ?? '');
        }
        if ($endpointId !== '' && !in_array($endpointId, ['business_flow_transform', 'traffic_flow_transform'], true)) {
            return '';
        }
        return 'flow';
    }

    /** @param array<string, mixed> $candidate @param array<string, mixed> $current */
    private function preferEffectMetricRecord(array $candidate, array $current, string $role): bool
    {
        $fields = $role === 'operating'
            ? ['amount', 'quantity', 'book_order_num']
            : ['list_exposure', 'detail_exposure', 'order_filling_num', 'order_submit_num'];
        $rank = static function (array $record) use ($fields): int {
            $score = 0;
            foreach ($fields as $field) {
                if (is_numeric($record[$field] ?? null) && (float)$record[$field] > 0) {
                    $score += 10;
                }
            }
            return $score;
        };
        $candidateRank = $rank($candidate);
        $currentRank = $rank($current);
        if ($candidateRank !== $currentRank) {
            return $candidateRank > $currentRank;
        }
        $timestamp = static function (array $record): int {
            foreach (['snapshot_time', 'update_time', 'create_time'] as $field) {
                $value = trim((string)($record[$field] ?? ''));
                if ($value !== '' && ($time = strtotime($value)) !== false) {
                    return $time;
                }
            }
            return 0;
        };
        $candidateTime = $timestamp($candidate);
        $currentTime = $timestamp($current);
        return $candidateTime !== $currentTime
            ? $candidateTime > $currentTime
            : (int)($candidate['id'] ?? 0) > (int)($current['id'] ?? 0);
    }

    private function normalizeEffectPlatform(string $value): string
    {
        $value = strtolower(trim($value));
        return match ($value) {
            '携程', 'trip', 'trip.com', 'ebooking' => 'ctrip',
            '美团', 'meituan hotel' => 'meituan',
            '去哪儿', 'qunar.com' => 'qunar',
            default => $value,
        };
    }

    private function mergeMetricAggregate(array $aggregate, array $record): array
    {
        $aggregate['source_row_count'] = (int)($aggregate['source_row_count'] ?? 0) + 1;
        $sources = is_array($aggregate['sources'] ?? null) ? $aggregate['sources'] : [];
        $source = trim((string)($record['source'] ?? ''));
        if ($source !== '') {
            $sources[$source] = true;
        }
        $aggregate['sources'] = array_keys($sources);
        foreach (['amount', 'quantity', 'book_order_num', 'data_value', 'comment_score', 'list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num'] as $field) {
            if (!array_key_exists($field, $record) || !is_numeric($record[$field])) {
                continue;
            }
            $aggregate[$field] = round((float)($aggregate[$field] ?? 0) + (float)$record[$field], 4);
        }

        return $aggregate;
    }

    private function metricDelta(array $current, array $previous): array
    {
        $delta = [];
        foreach (['amount', 'quantity', 'book_order_num', 'list_exposure', 'detail_exposure', 'order_submit_num'] as $field) {
            if (!array_key_exists($field, $current) || !array_key_exists($field, $previous)) {
                continue;
            }
            $delta[$field] = round((float)$current[$field] - (float)$previous[$field], 4);
        }

        return $delta;
    }

    private function missingMetricWindow(string $targetDate): array
    {
        return [
            'status' => 'metric_window_missing',
            'current' => [],
            'previous' => [],
            'delta' => [],
            'missing_dates' => [$targetDate, $this->previousDate($targetDate)],
            'data_gaps' => ['metric_window_missing'],
        ];
    }

    private function buildSimilarIndex(array $actions): array
    {
        $index = [];
        foreach ($actions as $action) {
            foreach ($this->similarityKeys($action) as $key) {
                $index[$key][] = [
                    'hotel_id' => (int)($action['hotel_id'] ?? 0),
                    'hotel_name' => (string)($action['hotel_name'] ?? ''),
                    'question_key' => (string)($action['question_key'] ?? ''),
                    'action_code' => (string)($action['action_code'] ?? ''),
                ];
            }
        }

        return $index;
    }

    private function similarityKeys(array $action): array
    {
        return array_values(array_unique($this->collectCodes(
            $action['question_key'] ?? '',
            $action['action_code'] ?? '',
            $action['data_gaps'] ?? [],
            $action['blocking_missing_codes'] ?? []
        )));
    }

    private function sopTemplateKey(array $action): string
    {
        $keys = $this->similarityKeys($action);
        $key = $keys[0] ?? 'ota_operation_action';
        $key = preg_replace('/[^a-zA-Z0-9_.:-]+/', '_', $key) ?? 'ota_operation_action';
        return trim($key, '_') !== '' ? trim($key, '_') : 'ota_operation_action';
    }

    private function findHotelRow(array $rows, int $hotelId): array
    {
        foreach ($rows as $row) {
            if (is_array($row) && (int)($row['hotel_id'] ?? 0) === $hotelId) {
                return $row;
            }
        }

        return [];
    }

    private function collectCodes(mixed ...$groups): array
    {
        $codes = [];
        foreach ($groups as $group) {
            foreach ((array)$group as $value) {
                if (is_array($value)) {
                    $value = $value['code'] ?? $value['key'] ?? '';
                }
                $value = trim((string)$value);
                if ($value !== '') {
                    $codes[] = $value;
                }
            }
        }

        return array_values(array_unique($codes));
    }

    private function tableExists(string $table): bool
    {
        try {
            Db::query('SELECT 1 FROM `' . str_replace('`', '', $table) . '` LIMIT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function tableColumns(string $table): array
    {
        try {
            $rows = Db::query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
        } catch (\Throwable) {
            return [];
        }

        $columns = [];
        foreach ($rows as $row) {
            $field = is_array($row) ? (string)($row['Field'] ?? $row['field'] ?? '') : '';
            if ($field !== '') {
                $columns[] = $field;
            }
        }

        return $columns;
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create directory: ' . $dir);
        }
    }

    private function actionTrackingKey(int $hotelId, string $actionCode, string $questionKey): string
    {
        $identity = $actionCode !== '' ? $actionCode : $questionKey;
        return $hotelId . '|' . preg_replace('/[^a-zA-Z0-9_.:-]+/', '_', $identity);
    }

    private function normalizeDate(string $value): string
    {
        $value = trim($value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : date('Y-m-d');
    }

    private function previousDate(string $targetDate): string
    {
        $time = strtotime($targetDate . ' -1 day');
        return $time === false ? date('Y-m-d', strtotime('-1 day') ?: time()) : date('Y-m-d', $time);
    }

    private function normalizeLimit(mixed $value): int
    {
        $limit = is_numeric($value) ? (int)$value : self::DEFAULT_LIMIT;
        return max(1, min(self::MAX_LIMIT, $limit));
    }

    private function safeText(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';
        return function_exists('mb_substr') ? mb_substr($value, 0, 500) : substr($value, 0, 500);
    }
}
