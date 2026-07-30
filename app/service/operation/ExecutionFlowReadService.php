<?php
declare(strict_types=1);

namespace app\service\operation;

final class ExecutionFlowReadService
{
    public function __construct(
        private readonly ExecutionOutcomeService $executionOutcomeService
    ) {
    }

    public function buildItem(array $intent, array $tasks = [], array $evidence = []): array
    {
        usort($tasks, static fn(array $a, array $b): int => (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));
        usort($evidence, static fn(array $a, array $b): int => (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));

        $task = $this->latestTask($tasks);
        $taskId = (int)($task['id'] ?? 0);
        $taskEvidence = $taskId > 0
            ? array_values(array_filter(
                $evidence,
                static fn(array $row): bool => (int)($row['task_id'] ?? 0) === $taskId
            ))
            : $evidence;
        $latestEvidence = $taskEvidence[0] ?? [];
        $longitudinalReview = $this->latestLongitudinalReview($taskEvidence);
        $roiEvidence = $this->executionOutcomeService->latestExecutionRoiEvidence($taskEvidence);
        $evidenceSummary = $this->buildSafeEvidenceSummary($taskEvidence);
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
        $stage = $this->stage($intent, $task, $evidenceTruth, $reviewStatus, $outcomeTruth);

        return [
            'id' => (int)$intent['id'],
            'hotel_id' => (int)$intent['hotel_id'],
            'stage' => $stage,
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
                'expected_delta' => (float)($intent['expected_delta'] ?? 0),
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
            'assignment' => $this->buildWorkflowAssignment($intent),
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
            'truth_context' => $truthContext,
            'review' => [
                'status' => $displayReviewStatus,
                'reported_status' => $reviewStatus,
                'truth_status' => (string)($truthContext['status'] ?? 'unverified'),
                'failure_reason' => $truthContext['failure_reason'] ?? null,
                'summary' => (string)($task['result_summary'] ?? ''),
                'action_track_id' => (int)($task['action_track_id'] ?? 0),
                'available_on' => $reviewAvailableOn,
                'is_available' => $reviewAvailableOn === '' || $reviewAvailableOn <= date('Y-m-d'),
            ],
            'roi' => $this->executionOutcomeService->buildExecutionRoi(
                $intent,
                $task,
                $roiEvidence,
                $roiEvidenceTruth,
                $outcomeTruth
            ),
            'next_action' => $this->buildNextAction($stage, $intent, $task),
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
            'failed' => 0,
        ];
        $roiPercentValues = [];
        $revenueLiftValues = [];
        $profitable = 0;
        $approved = 0;
        $executed = 0;
        $operatorAttested = 0;
        $evidenceReady = 0;
        $totalIncrementalRevenue = 0.0;
        $totalCost = 0.0;
        $totalProfit = 0.0;
        $incrementalRevenueReady = 0;
        $costReady = 0;
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
            if (($item['execution']['status'] ?? '') === 'executed') {
                $executed++;
            }
            if (($item['evidence_truth']['operator_attested'] ?? false) === true) {
                $operatorAttested++;
            }
            $sourceVerified = ($item['evidence_truth']['source_verified'] ?? false) === true;
            if ($sourceVerified) {
                $evidenceReady++;
            }
            if ($sourceVerified && ($item['roi']['status'] ?? '') === 'ready') {
                $unit = (string)($item['roi']['unit'] ?? '%');
                $value = (float)($item['roi']['value'] ?? 0);
                if ($unit === 'amount') {
                    $revenueLiftValues[] = $value;
                } else {
                    $roiPercentValues[] = $value;
                }
                if (is_numeric($item['roi']['incremental_revenue'] ?? null)) {
                    $totalIncrementalRevenue += (float)$item['roi']['incremental_revenue'];
                    $incrementalRevenueReady++;
                }
                if (is_numeric($item['roi']['cost'] ?? null)) {
                    $totalCost += (float)$item['roi']['cost'];
                    $costReady++;
                }
                if (is_numeric($item['roi']['profit'] ?? null)) {
                    $profit = (float)$item['roi']['profit'];
                    $totalProfit += $profit;
                    $profitReady++;
                    if ($profit > 0) {
                        $profitable++;
                    }
                }
            }
        }

        $total = count($items);
        $roiPercentReady = count($roiPercentValues);
        $revenueLiftReady = count($revenueLiftValues);
        $roiReady = $roiPercentReady + $revenueLiftReady;

        return [
            'total' => $total,
            'stage_counts' => $stageCounts,
            'bottleneck' => $this->buildBottleneck($stageCounts),
            'approved' => $approved,
            'executed' => $executed,
            'operator_attested' => $operatorAttested,
            'evidence_ready' => $evidenceReady,
            'source_verified' => $evidenceReady,
            'roi_ready' => $roiReady,
            'roi_percent_ready' => $roiPercentReady,
            'revenue_lift_ready' => $revenueLiftReady,
            'avg_roi' => $roiPercentReady > 0 ? round(array_sum($roiPercentValues) / $roiPercentReady, 2) : null,
            'avg_revenue_lift' => $revenueLiftReady > 0 ? round(array_sum($revenueLiftValues) / $revenueLiftReady, 2) : null,
            'approval_rate' => $total > 0 ? round($approved / $total * 100, 2) : null,
            'execution_rate' => $total > 0 ? round($executed / $total * 100, 2) : null,
            'evidence_rate' => $total > 0 ? round($evidenceReady / $total * 100, 2) : null,
            'roi_ready_rate' => $total > 0 ? round($roiReady / $total * 100, 2) : null,
            'profitable' => $profitable,
            'profitable_rate' => $profitReady > 0 ? round($profitable / $profitReady * 100, 2) : null,
            'total_incremental_revenue' => $roiReady > 0 && $incrementalRevenueReady === $roiReady
                ? round($totalIncrementalRevenue, 2)
                : null,
            'total_cost' => $roiReady > 0 && $costReady === $roiReady ? round($totalCost, 2) : null,
            'total_profit' => $roiReady > 0 && $profitReady === $roiReady ? round($totalProfit, 2) : null,
            'money_status' => $this->moneyStatus(
                $roiReady,
                $roiReady > 0 && $profitReady === $roiReady ? $totalProfit : null
            ),
        ];
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
    public function buildSafeEvidenceSummary(array $rows): array
    {
        $types = [];
        foreach ($rows as $row) {
            $type = trim((string)($row['evidence_type'] ?? ''));
            if ($type !== '') {
                $types[] = $type;
            }
        }
        $types = array_values(array_unique($types));
        $latest = $rows[0] ?? [];

        return [
            'count' => count($rows),
            'types' => $types,
            'latest_type' => trim((string)($latest['evidence_type'] ?? '')),
            'latest_at' => trim((string)($latest['created_at'] ?? '')),
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

    private function buildNextAction(string $stage, array $intent, array $task): array
    {
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
        ][$stage] ?? '';
    }

    private function moneyStatus(int $roiReady, ?float $totalProfit): string
    {
        if ($roiReady <= 0) {
            return 'no_roi';
        }
        if ($totalProfit === null) {
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
        if ($intentStatus !== 'approved') {
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

    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
