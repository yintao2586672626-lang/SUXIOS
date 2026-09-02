<?php
declare(strict_types=1);

namespace app\service\concern;

use think\facade\Db;
use Throwable;

trait AiDailyReportExecutionReadConcern
{
    /**
     * @return array{
     *   items_by_report_id:array<int,array<int,array<string,mixed>>>,
     *   read_state:array<string,mixed>
     * }
     */
    private function executionItemsByReportId(array $hotelIds, ?int $hotelId, array $reportIds): array
    {
        $reportIds = array_values(array_unique(array_filter(array_map('intval', $reportIds), static fn(int $id): bool => $id > 0)));
        if (empty($reportIds)) {
            return [
                'items_by_report_id' => [],
                'read_state' => [],
            ];
        }

        try {
            if (!$this->tableExists('operation_execution_intents')) {
                return [
                    'items_by_report_id' => [],
                    'read_state' => [],
                ];
            }
            $query = Db::name('operation_execution_intents')
                ->whereNull('deleted_at')
                ->where('source_module', 'ai_daily_report')
                ->whereIn('source_record_id', $reportIds);
            $this->applyHotelScope($query, $hotelIds, $hotelId);
            $intentRows = $query->order('id', 'desc')->select()->toArray();
            if (empty($intentRows)) {
                return [
                    'items_by_report_id' => [],
                    'read_state' => [],
                ];
            }

            $intentIds = array_map(static fn(array $row): int => (int)$row['id'], $intentRows);
            $tasksByIntent = [];
            $evidenceByIntent = [];
            if ($this->tableExists('operation_execution_tasks')) {
                $taskRows = Db::name('operation_execution_tasks')
                    ->whereIn('intent_id', $intentIds)
                    ->whereNull('deleted_at')
                    ->order('id', 'desc')
                    ->select()
                    ->toArray();
                $taskIntentMap = [];
                foreach ($taskRows as $taskRow) {
                    $intentId = (int)($taskRow['intent_id'] ?? 0);
                    $taskId = (int)($taskRow['id'] ?? 0);
                    if ($intentId <= 0) {
                        continue;
                    }
                    $tasksByIntent[$intentId][] = $taskRow;
                    if ($taskId > 0) {
                        $taskIntentMap[$taskId] = $intentId;
                    }
                }

                if (!empty($taskIntentMap) && $this->tableExists('operation_execution_evidence')) {
                    $evidenceRows = Db::name('operation_execution_evidence')
                        ->whereIn('task_id', array_keys($taskIntentMap))
                        ->whereNull('deleted_at')
                        ->order('id', 'desc')
                        ->select()
                        ->toArray();
                    foreach ($evidenceRows as $evidenceRow) {
                        $taskId = (int)($evidenceRow['task_id'] ?? 0);
                        $intentId = $taskIntentMap[$taskId] ?? 0;
                        if ($intentId > 0) {
                            $evidenceByIntent[$intentId][] = $evidenceRow;
                        }
                    }
                }
            }

            $itemsByReportId = [];
            foreach ($intentRows as $intentRow) {
                $intentId = (int)($intentRow['id'] ?? 0);
                $reportId = (int)($intentRow['source_record_id'] ?? 0);
                if ($intentId <= 0 || $reportId <= 0) {
                    continue;
                }
                $itemsByReportId[$reportId][] = $this->operationService->buildExecutionFlowItem(
                    $intentRow,
                    $tasksByIntent[$intentId] ?? [],
                    $evidenceByIntent[$intentId] ?? []
                );
            }

            return [
                'items_by_report_id' => $itemsByReportId,
                'read_state' => [],
            ];
        } catch (Throwable $e) {
            return [
                'items_by_report_id' => [],
                'read_state' => $this->executionEvidenceReadFailure(),
            ];
        }
    }
    /** @return array<string,mixed> */
    private function executionEvidenceReadFailure(): array
    {
        return [
            'status' => 'blocked',
            'data_status' => 'read_failed',
            'reason_code' => 'ai_daily_report_execution_flow_read_failed',
            'read_scope' => 'operation_execution_intents_tasks_evidence',
            'message' => '执行流程与回读证据读取失败；日报结果仍可阅读，但当前不能判断执行状态或创建新的执行意图。',
            'data_gaps' => [[
                'code' => 'ai_daily_report_execution_flow_read_failed',
                'data_status' => 'read_failed',
                'source_ref' => 'operation.execution_flow',
                'message' => '执行意图、任务或执行证据未能完成回读，未按“没有执行记录”处理。',
            ]],
        ];
    }

    /** @param array<int,mixed> $actions @return array<int,mixed> */
    private function blockActionsForExecutionEvidenceReadFailure(array $actions, array $readState): array
    {
        foreach ($actions as &$action) {
            if (!is_array($action)) {
                continue;
            }
            $action['can_create_execution_intent'] = false;
            $action['execution_evidence_status'] = 'read_failed';
            $action['execution_evidence_reason_code'] = (string)($readState['reason_code'] ?? 'ai_daily_report_execution_flow_read_failed');
            if ($this->isInvestigationOnlyAction($action)) {
                continue;
            }

            $blockedReason = '执行流程证据读取失败，恢复回读前不得创建或重复创建执行意图。';
            $existingReason = trim((string)($action['blocked_reason'] ?? ''));
            $action['blocked_reason'] = $existingReason === ''
                ? $blockedReason
                : $existingReason . '；' . $blockedReason;
            $readiness = $this->actionReadiness(
                'blocked',
                0,
                false,
                '恢复执行流程与证据回读后再操作',
                [$this->readinessMissing(
                    'execution_evidence_readback',
                    '执行证据回读',
                    '修复执行意图、任务和证据读取后重新核验'
                )]
            );
            $readiness['data_status'] = 'read_failed';
            $readiness['reason_code'] = (string)($readState['reason_code'] ?? 'ai_daily_report_execution_flow_read_failed');
            $action['action_readiness'] = $this->withReadinessNotice($readiness);
        }
        unset($action);

        return $actions;
    }

    /** @param array<string,mixed> $readiness @param array<string,mixed> $readState */
    private function blockWorkflowForExecutionEvidenceReadFailure(array $readiness, array $readState): array
    {
        $missing = array_values(array_filter(
            (array)($readiness['missing_evidence'] ?? []),
            'is_array'
        ));
        $missing[] = $this->readinessMissing(
            'execution_evidence_readback',
            '执行证据回读',
            '修复执行意图、任务和证据读取后重新核验'
        );

        $readiness = array_merge($readiness, [
            'stage' => 'blocked',
            'component_stage' => 'blocked',
            'status_label' => '权威经营闭环受阻',
            'component_status_label' => '执行证据读取失败',
            'score' => 0,
            'component_score' => 0,
            'closed_loop' => false,
            'component_closed_loop' => false,
            'authority_status' => 'blocked',
            'data_status' => 'read_failed',
            'reason_code' => (string)($readState['reason_code'] ?? 'ai_daily_report_execution_flow_read_failed'),
            'read_scope' => (string)($readState['read_scope'] ?? 'operation_execution_intents_tasks_evidence'),
            'data_gaps' => array_values(array_filter((array)($readState['data_gaps'] ?? []), 'is_array')),
            'decision_status' => 'blocked_by_data',
            'next_action' => '恢复执行流程与证据回读后再推进经营闭环',
            'component_next_action' => '恢复执行流程与证据回读后再操作',
            'missing_evidence' => $missing,
        ]);

        return $this->withReadinessNotice($readiness);
    }}
