<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;
use Throwable;

class BusinessClosureOverviewService
{
    private const MODULE_ORDER = [
        'ai_daily_report',
        'revenue_pricing',
        'staff_service',
        'asset_maintenance',
        'operation_execution',
        'transfer_investment',
        'expansion',
        'opening',
        'strategy_simulation',
        'feasibility_report',
    ];

    public function overview(array $hotelIds, ?int $hotelId, int $userId = 0, bool $isSuperAdmin = false): array
    {
        $operationService = new OperationManagementService();
        $executionFlow = $operationService->executionFlow($hotelIds, $hotelId, []);
        $executionStats = $this->executionStatsBySource($executionFlow['list'] ?? []);
        $executionSummary = is_array($executionFlow['summary'] ?? null) ? $executionFlow['summary'] : [];
        $dataGaps = is_array($executionFlow['data_gaps'] ?? null) ? $executionFlow['data_gaps'] : [];

        $modules = [
            $this->aiDailyReportSignal($hotelIds, $hotelId, $executionStats),
            $this->revenuePricingSignal($hotelIds, $hotelId, $executionStats),
            $this->staffServiceSignal($hotelIds, $hotelId, $executionStats),
            $this->assetMaintenanceSignal($hotelIds, $hotelId, $executionStats),
            $this->operationExecutionSignal($executionSummary, $executionFlow),
            $this->transferInvestmentSignal($hotelIds, $hotelId, $executionStats),
            $this->expansionSignal($userId, $isSuperAdmin, $executionStats),
            $this->openingSignal($userId, $isSuperAdmin, $executionStats),
            $this->strategySimulationSignal($userId, $isSuperAdmin, $executionStats),
            $this->feasibilityReportSignal($userId, $isSuperAdmin, $executionStats),
        ];

        return $this->buildOverviewFromSignals($modules, $executionSummary, $dataGaps);
    }

    public function buildOverviewFromSignals(array $signals, array $executionSummary = [], array $dataGaps = []): array
    {
        $modules = [];
        foreach ($signals as $signal) {
            if (!is_array($signal)) {
                continue;
            }
            $modules[] = $this->summarizeModuleClosure($signal);
        }

        usort($modules, static function (array $a, array $b): int {
            $left = array_search((string)($a['key'] ?? ''), self::MODULE_ORDER, true);
            $right = array_search((string)($b['key'] ?? ''), self::MODULE_ORDER, true);
            return ($left === false ? 999 : $left) <=> ($right === false ? 999 : $right);
        });

        $total = count($modules);
        $closed = 0;
        $blocked = 0;
        $recordOnly = 0;
        $scoreSum = 0;
        foreach ($modules as $module) {
            if (($module['closed_loop'] ?? false) === true) {
                $closed++;
            }
            if (($module['status'] ?? '') === 'not_loaded' || ($module['status'] ?? '') === 'blocked') {
                $blocked++;
            }
            if (($module['status'] ?? '') === 'record_only') {
                $recordOnly++;
            }
            $scoreSum += (int)($module['maturity_score'] ?? 0);
        }

        $weakModules = array_values(array_filter($modules, static function (array $module): bool {
            return ($module['closed_loop'] ?? false) !== true;
        }));

        return [
            'summary' => [
                'module_count' => $total,
                'closed_loop_count' => $closed,
                'not_closed_count' => max(0, $total - $closed),
                'blocked_count' => $blocked,
                'record_only_count' => $recordOnly,
                'avg_maturity_score' => $total > 0 ? round($scoreSum / $total, 1) : null,
                'operation_execution_total' => (int)($executionSummary['total'] ?? 0),
                'operation_roi_ready' => (int)($executionSummary['roi_ready'] ?? 0),
                'status' => $closed === $total && $total > 0 ? 'closed' : 'not_closed',
            ],
            'modules' => $modules,
            'weak_modules' => array_slice($weakModules, 0, 5),
            'data_status' => empty($dataGaps) ? 'ok' : 'data_gap',
            'data_gaps' => $dataGaps,
            'source_scope' => 'post_ota_closure_only',
            'protected_scope' => 'online data collection and OTA standardization are read-only for this overview',
        ];
    }

    public function summarizeModuleClosure(array $signal): array
    {
        $tableStatus = (string)($signal['table_status'] ?? 'ok');
        $recordCount = max(0, (int)($signal['record_count'] ?? 0));
        $linked = max(0, (int)($signal['linked_execution_count'] ?? 0));
        $approved = max(0, (int)($signal['approved_count'] ?? 0));
        $executed = max(0, (int)($signal['executed_count'] ?? 0));
        $evidenceReady = max(0, (int)($signal['evidence_ready_count'] ?? 0));
        $reviewed = max(0, (int)($signal['reviewed_count'] ?? 0));
        $roiReady = max(0, (int)($signal['roi_ready_count'] ?? 0));
        $blocked = max(0, (int)($signal['blocked_count'] ?? 0));
        $rejected = max(0, (int)($signal['rejected_count'] ?? 0));
        $dataGaps = array_values(array_filter((array)($signal['data_gaps'] ?? []), 'is_array'));

        if ($tableStatus !== 'ok') {
            $status = 'not_loaded';
            $nextAction = 'run_migration_or_verify_table';
            $score = 0;
        } elseif ($recordCount <= 0 && $linked <= 0) {
            $status = 'not_started';
            $nextAction = (string)($signal['empty_next_action'] ?? 'create_source_record');
            $score = 0;
        } elseif ($linked <= 0) {
            $status = 'record_only';
            $nextAction = 'bridge_to_operation_execution';
            $score = 20;
        } elseif ($roiReady > 0) {
            $status = 'roi_ready';
            $nextAction = 'keep_reviewing';
            $score = 100;
        } elseif ($reviewed > 0) {
            $status = 'reviewed_no_roi';
            $nextAction = 'add_roi_evidence';
            $score = 85;
        } elseif ($evidenceReady > 0) {
            $status = 'evidence_ready';
            $nextAction = 'review_execution_result';
            $score = 75;
        } elseif ($executed > 0) {
            $status = 'executed_missing_evidence';
            $nextAction = 'record_execution_evidence';
            $score = 60;
        } elseif ($approved > 0) {
            $status = 'approved_pending_execution';
            $nextAction = 'execute_and_record_evidence';
            $score = 45;
        } elseif ($blocked > 0) {
            $status = 'blocked';
            $nextAction = 'fix_blocked_reason';
            $score = 30;
        } elseif ($rejected > 0) {
            $status = 'rejected';
            $nextAction = 'rework_or_archive';
            $score = 25;
        } else {
            $status = 'pending_approval';
            $nextAction = 'approve_execution_intent';
            $score = 35;
        }

        if ($status === 'record_only') {
            $dataGaps[] = [
                'code' => (string)($signal['key'] ?? 'module') . '_execution_bridge_missing',
                'message' => 'Source records exist, but no operation execution intent/evidence/ROI is linked.',
            ];
        }

        return [
            'key' => (string)($signal['key'] ?? ''),
            'label' => (string)($signal['label'] ?? $signal['key'] ?? ''),
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'closed_loop' => $status === 'roi_ready',
            'maturity_score' => $score,
            'next_action' => $nextAction,
            'next_action_label' => $this->nextActionLabel($nextAction),
            'source_scope' => (string)($signal['source_scope'] ?? ''),
            'record_count' => $recordCount,
            'linked_execution_count' => $linked,
            'approved_count' => $approved,
            'executed_count' => $executed,
            'evidence_ready_count' => $evidenceReady,
            'reviewed_count' => $reviewed,
            'roi_ready_count' => $roiReady,
            'blocked_count' => $blocked,
            'latest_at' => (string)($signal['latest_at'] ?? ''),
            'data_gaps' => $dataGaps,
            'detail' => (string)($signal['detail'] ?? ''),
        ];
    }

    private function aiDailyReportSignal(array $hotelIds, ?int $hotelId, array $executionStats): array
    {
        $signal = $this->baseSignal('ai_daily_report', 'AI经营日报 / AI决策', 'OTA and operation-report scope, not whole-hotel financial truth', $executionStats['ai_daily_report'] ?? []);
        if (!$this->tableExists('ai_daily_reports')) {
            return $this->missingTableSignal($signal, 'ai_daily_reports');
        }

        try {
            $query = Db::name('ai_daily_reports')->whereNull('deleted_at')->where('status', '<>', 'archived');
            $this->applyHotelScope($query, $hotelIds, $hotelId, 'hotel_id');
            $signal['record_count'] = (int)(clone $query)->count();
            $signal['latest_at'] = (string)((clone $query)->max('updated_at') ?: '');
            if ($signal['record_count'] > 0) {
                $readiness = (new AiDailyReportService())->readinessSummaryFromRows((clone $query)->order('id', 'desc')->limit(30)->select()->toArray(), $hotelIds, $hotelId);
                $signal['detail'] = 'best_readiness=' . (string)($readiness['best_status_label'] ?? '')
                    . ', score=' . (int)($readiness['best_score'] ?? 0)
                    . ', transferred=' . (int)($readiness['transferred_count'] ?? 0)
                    . ', evidence_ready=' . (int)($readiness['evidence_ready_count'] ?? 0)
                    . ', roi_ready=' . (int)($readiness['roi_ready_count'] ?? 0);
                foreach (array_slice((array)($readiness['missing_evidence'] ?? []), 0, 3) as $gap) {
                    if (!is_array($gap)) {
                        continue;
                    }
                    $signal['data_gaps'][] = [
                        'code' => 'ai_daily_report_readiness_' . (string)($gap['code'] ?? 'missing'),
                        'message' => (string)($gap['label'] ?? 'AI daily report readiness evidence missing') . ': ' . (string)($gap['next_action'] ?? ''),
                    ];
                }
            }
            if ($signal['record_count'] <= 0) {
                $signal['data_gaps'][] = ['code' => 'ai_daily_report_not_generated', 'message' => 'No AI daily report is generated for the selected hotel scope.'];
            }
        } catch (Throwable $e) {
            $signal['table_status'] = 'read_failed';
            $signal['data_gaps'][] = ['code' => 'ai_daily_reports_read_failed', 'message' => 'AI daily report summary read failed.'];
        }

        return $signal;
    }

    private function revenuePricingSignal(array $hotelIds, ?int $hotelId, array $executionStats): array
    {
        $signal = $this->baseSignal('revenue_pricing', '收益调价建议', 'local price suggestion records; OTA write-back requires manual execution evidence', $executionStats['price_suggestion'] ?? []);
        if (!$this->tableExists('price_suggestions')) {
            return $this->missingTableSignal($signal, 'price_suggestions');
        }

        try {
            $query = Db::name('price_suggestions');
            $this->applyHotelScope($query, $hotelIds, $hotelId, 'hotel_id');
            $signal['record_count'] = (int)(clone $query)->count();
            $signal['latest_at'] = (string)((clone $query)->max('update_time') ?: ((clone $query)->max('create_time') ?: ''));
            $signal['detail'] = 'pending=' . (int)(clone $query)->where('status', 1)->count()
                . ', approved=' . (int)(clone $query)->where('status', 2)->count()
                . ', applied_local=' . (int)(clone $query)->where('status', 4)->count();
            if ($signal['record_count'] > 0 && (int)$signal['linked_execution_count'] <= 0) {
                $signal['data_gaps'][] = ['code' => 'price_suggestion_execution_intent_missing', 'message' => 'Pricing suggestions are advisory/local until an execution intent and evidence are recorded.'];
            }
        } catch (Throwable $e) {
            $signal['table_status'] = 'read_failed';
            $signal['data_gaps'][] = ['code' => 'price_suggestions_read_failed', 'message' => 'Pricing suggestion summary read failed.'];
        }

        return $signal;
    }

    private function staffServiceSignal(array $hotelIds, ?int $hotelId, array $executionStats): array
    {
        $signal = $this->baseSignal('staff_service', '智能员工 / 工单服务', 'agent_work_orders and conversations; service closure requires resolved/closed work orders', $this->mergeExecutionStats($executionStats, ['staff_service', 'agent_staff', 'agent_work_order', 'work_order']));
        if (!$this->tableExists('agent_work_orders')) {
            return $this->missingTableSignal($signal, 'agent_work_orders');
        }

        $conversationCount = 0;
        $resolvedCount = 0;

        try {
            $query = Db::name('agent_work_orders');
            $this->applyHotelScope($query, $hotelIds, $hotelId, 'hotel_id');
            $workOrderCount = (int)(clone $query)->count();
            $assignedCount = (int)(clone $query)->whereIn('status', [2, 3, 4, 5])->count();
            $resolvedCount = (int)(clone $query)->whereIn('status', [4, 5])->count();
            $closedCount = (int)(clone $query)->where('status', 5)->count();
            $urgentOpenCount = (int)(clone $query)->whereIn('status', [1, 2, 3])->where('priority', 4)->count();
            $escalatedCount = (int)(clone $query)->where('status', 6)->count();

            $signal['record_count'] += $workOrderCount;
            $signal['linked_execution_count'] += $workOrderCount;
            $signal['approved_count'] += $assignedCount;
            $signal['executed_count'] += $resolvedCount;
            $signal['evidence_ready_count'] += $resolvedCount;
            $signal['reviewed_count'] += $closedCount;
            $signal['roi_ready_count'] += $closedCount;
            $signal['blocked_count'] += $escalatedCount;
            $signal['latest_at'] = $this->maxDateString((string)$signal['latest_at'], (string)((clone $query)->max('update_time') ?: ((clone $query)->max('create_time') ?: '')));
            $signal['detail'] = 'work_orders=' . $workOrderCount
                . ', assigned=' . $assignedCount
                . ', resolved=' . $resolvedCount
                . ', closed=' . $closedCount;

            if ($workOrderCount > 0 && $resolvedCount <= 0) {
                $signal['data_gaps'][] = ['code' => 'staff_work_order_resolution_missing', 'message' => 'Work orders exist but no resolved/closed record is available.'];
            }
            if ($resolvedCount > 0 && $closedCount <= 0) {
                $signal['data_gaps'][] = ['code' => 'staff_work_order_review_missing', 'message' => 'Resolved work orders are not closed/reviewed yet.'];
            }
            if ($urgentOpenCount > 0) {
                $signal['data_gaps'][] = ['code' => 'staff_urgent_order_open', 'message' => 'Urgent staff work orders are still open.'];
            }
        } catch (Throwable $e) {
            $signal['table_status'] = 'read_failed';
            $signal['data_gaps'][] = ['code' => 'agent_work_orders_read_failed', 'message' => 'Staff work order summary read failed.'];
        }

        if ($this->tableExists('agent_conversations')) {
            try {
                $conversationQuery = Db::name('agent_conversations');
                $this->applyHotelScope($conversationQuery, $hotelIds, $hotelId, 'hotel_id');
                $conversationCount = (int)(clone $conversationQuery)->count();
                $emotionAlertCount = (int)(clone $conversationQuery)->where('emotion_score', '>=', 0.4)->count();
                $serviceIntentCount = (int)(clone $conversationQuery)
                    ->where(function ($q) {
                        $q->whereIn('intent_type', [3, 5])
                            ->whereOr('emotion_score', '>=', 0.4);
                    })
                    ->count();
                $conversationLinkedOrderCount = $this->conversationLinkedWorkOrderCount($hotelIds, $hotelId);
                $signal['record_count'] += $conversationCount;
                $signal['latest_at'] = $this->maxDateString((string)$signal['latest_at'], (string)((clone $conversationQuery)->max('update_time') ?: ((clone $conversationQuery)->max('create_time') ?: '')));
                $signal['detail'] = trim((string)($signal['detail'] ?? '')
                    . ', conversations=' . $conversationCount
                    . ', service_intents=' . $serviceIntentCount
                    . ', conversation_work_orders=' . $conversationLinkedOrderCount
                    . ', emotion_alerts=' . $emotionAlertCount, ', ');
                if ($serviceIntentCount > 0 && $conversationLinkedOrderCount <= 0) {
                    $signal['data_gaps'][] = ['code' => 'staff_conversation_work_order_bridge_missing', 'message' => 'Service/complaint conversations exist but no conversation-linked work order is visible.'];
                }
                if ($serviceIntentCount > $conversationLinkedOrderCount) {
                    $signal['data_gaps'][] = ['code' => 'staff_conversation_pending_work_order', 'message' => 'Some service/complaint conversations are not yet linked to work orders.'];
                }
                if ($emotionAlertCount > 0 && $resolvedCount <= 0) {
                    $signal['data_gaps'][] = ['code' => 'staff_emotion_alert_unresolved', 'message' => 'Emotion alerts exist but no resolved service work order is visible.'];
                }
            } catch (Throwable $e) {
                $signal['data_gaps'][] = ['code' => 'agent_conversations_read_failed', 'message' => 'Conversation summary read failed.'];
            }
        } else {
            $signal['data_gaps'][] = ['code' => 'agent_conversations_missing', 'message' => 'agent_conversations table missing; service-dialogue closure is not visible.'];
        }

        if ($this->tableExists('knowledge_base')) {
            try {
                $knowledgeQuery = Db::name('knowledge_base');
                $this->applyHotelScope($knowledgeQuery, $hotelIds, $hotelId, 'hotel_id');
                $knowledgeCount = (int)(clone $knowledgeQuery)->count();
                $enabledKnowledge = (int)(clone $knowledgeQuery)->where('is_enabled', 1)->count();
                $disabledKnowledge = max(0, $knowledgeCount - $enabledKnowledge);
                $enabledKnowledgeIds = array_values(array_filter(array_map('intval', (clone $knowledgeQuery)->where('is_enabled', 1)->column('id')), static fn(int $id): bool => $id > 0));
                $usedKnowledgeCount = 0;
                $enabledUnusedCount = $enabledKnowledge;
                if ($this->tableExists('agent_conversations')) {
                    $usageQuery = Db::name('agent_conversations')->where('knowledge_id', '>', 0);
                    $this->applyHotelScope($usageQuery, $hotelIds, $hotelId, 'hotel_id');
                    $usedKnowledgeIds = array_values(array_filter(array_map('intval', (clone $usageQuery)->group('knowledge_id')->column('knowledge_id')), static fn(int $id): bool => $id > 0));
                    $usedKnowledgeCount = count(array_unique($usedKnowledgeIds));
                    if (!empty($enabledKnowledgeIds)) {
                        $enabledUnusedCount = count(array_diff($enabledKnowledgeIds, $usedKnowledgeIds));
                    }
                }

                $signal['record_count'] += $knowledgeCount;
                $signal['latest_at'] = $this->maxDateString((string)$signal['latest_at'], (string)((clone $knowledgeQuery)->max('update_time') ?: ((clone $knowledgeQuery)->max('create_time') ?: '')));
                $signal['detail'] = trim((string)($signal['detail'] ?? '')
                    . ', knowledge_total=' . $knowledgeCount
                    . ', knowledge_enabled=' . $enabledKnowledge
                    . ', knowledge_disabled=' . $disabledKnowledge
                    . ', knowledge_used=' . $usedKnowledgeCount
                    . ', knowledge_enabled_unused=' . $enabledUnusedCount, ', ');
                if ((int)$signal['record_count'] > 0 && $enabledKnowledge <= 0) {
                    $signal['data_gaps'][] = ['code' => 'staff_knowledge_base_disabled', 'message' => 'Staff agent records exist but enabled knowledge-base entries are missing.'];
                }
                if ($conversationCount > 0 && $enabledKnowledge > 0 && $usedKnowledgeCount <= 0) {
                    $signal['data_gaps'][] = ['code' => 'staff_knowledge_usage_missing', 'message' => 'Enabled knowledge-base entries exist but no conversation references are visible.'];
                }
                if ($usedKnowledgeCount > 0 && $enabledUnusedCount > 0) {
                    $signal['data_gaps'][] = ['code' => 'staff_knowledge_entries_unused', 'message' => 'Some enabled knowledge-base entries have not been referenced by conversations.'];
                }
            } catch (Throwable $e) {
                $signal['data_gaps'][] = ['code' => 'knowledge_base_read_failed', 'message' => 'Knowledge-base summary read failed.'];
            }
        }

        return $signal;
    }

    private function assetMaintenanceSignal(array $hotelIds, ?int $hotelId, array $executionStats): array
    {
        $signal = $this->baseSignal('asset_maintenance', '资产运维 / 能耗维护', 'devices, energy suggestions, maintenance plans, and maintenance records', $this->mergeExecutionStats($executionStats, ['asset_maintenance', 'energy_saving', 'energy_saving_suggestion', 'maintenance_plan']));
        if (!$this->tableExists('devices')) {
            return $this->missingTableSignal($signal, 'devices');
        }

        try {
            $deviceQuery = Db::name('devices');
            $this->applyHotelScope($deviceQuery, $hotelIds, $hotelId, 'hotel_id');
            $deviceCount = (int)(clone $deviceQuery)->count();
            $monitoredCount = (int)(clone $deviceQuery)->where('is_monitored', 1)->count();
            $faultCount = (int)(clone $deviceQuery)->where('status', 3)->count();
            $signal['record_count'] += $deviceCount;
            $signal['latest_at'] = $this->maxDateString((string)$signal['latest_at'], (string)((clone $deviceQuery)->max('update_time') ?: ((clone $deviceQuery)->max('create_time') ?: '')));
            $signal['detail'] = 'devices=' . $deviceCount . ', monitored=' . $monitoredCount . ', faults=' . $faultCount;
            if ($deviceCount > 0 && $monitoredCount <= 0) {
                $signal['data_gaps'][] = ['code' => 'asset_device_monitoring_missing', 'message' => 'Devices exist but no monitored device is available for energy/anomaly closure.'];
            }
            if ($faultCount > 0) {
                $signal['data_gaps'][] = ['code' => 'asset_fault_device_open', 'message' => 'Fault devices exist and need maintenance evidence.'];
            }
        } catch (Throwable $e) {
            $signal['table_status'] = 'read_failed';
            $signal['data_gaps'][] = ['code' => 'devices_read_failed', 'message' => 'Device summary read failed.'];
        }

        if ($this->tableExists('energy_saving_suggestions')) {
            try {
                $suggestionQuery = Db::name('energy_saving_suggestions');
                $this->applyHotelScope($suggestionQuery, $hotelIds, $hotelId, 'hotel_id');
                $suggestionCount = (int)(clone $suggestionQuery)->count();
                $approvedCount = (int)(clone $suggestionQuery)->whereIn('status', [2, 3, 4])->count();
                $implementingCount = (int)(clone $suggestionQuery)->where('status', 3)->count();
                $completedCount = (int)(clone $suggestionQuery)->where('status', 4)->count();
                $actualSavingReady = (int)(clone $suggestionQuery)->where('status', 4)->where('actual_saving', '>', 0)->count();

                $signal['record_count'] += $suggestionCount;
                $signal['linked_execution_count'] += $approvedCount;
                $signal['approved_count'] += $approvedCount;
                $signal['executed_count'] += $completedCount;
                $signal['evidence_ready_count'] += $completedCount;
                $signal['reviewed_count'] += $actualSavingReady;
                $signal['roi_ready_count'] += $actualSavingReady;
                $signal['latest_at'] = $this->maxDateString((string)$signal['latest_at'], (string)((clone $suggestionQuery)->max('update_time') ?: ((clone $suggestionQuery)->max('create_time') ?: '')));
                $signal['detail'] = trim((string)($signal['detail'] ?? '') . ', energy_suggestions=' . $suggestionCount . ', implementing=' . $implementingCount . ', completed=' . $completedCount . ', saving_ready=' . $actualSavingReady, ', ');

                if ($suggestionCount > 0 && $completedCount <= 0) {
                    $signal['data_gaps'][] = ['code' => 'asset_energy_suggestion_not_completed', 'message' => 'Energy-saving suggestions exist but no completed implementation is visible.'];
                }
                if ($completedCount > 0 && $actualSavingReady <= 0) {
                    $signal['data_gaps'][] = ['code' => 'asset_actual_saving_missing', 'message' => 'Completed energy-saving suggestions lack actual_saving evidence.'];
                }
            } catch (Throwable $e) {
                $signal['data_gaps'][] = ['code' => 'energy_saving_suggestions_read_failed', 'message' => 'Energy suggestion summary read failed.'];
            }
        } else {
            $signal['data_gaps'][] = ['code' => 'energy_saving_suggestions_missing', 'message' => 'energy_saving_suggestions table missing.'];
        }

        if ($this->tableExists('maintenance_plans')) {
            try {
                $planQuery = Db::name('maintenance_plans');
                $this->applyHotelScope($planQuery, $hotelIds, $hotelId, 'hotel_id');
                $planCount = (int)(clone $planQuery)->count();
                $activePlanCount = (int)(clone $planQuery)->where('status', 1)->count();
                $planExecutionCount = (int)((clone $planQuery)->sum('execution_count') ?: 0);
                $signal['record_count'] += $planCount;
                $signal['linked_execution_count'] += $activePlanCount;
                $signal['approved_count'] += $activePlanCount;
                $signal['executed_count'] += $planExecutionCount;
                $signal['evidence_ready_count'] += $planExecutionCount;
                $signal['latest_at'] = $this->maxDateString((string)$signal['latest_at'], (string)((clone $planQuery)->max('update_time') ?: ((clone $planQuery)->max('create_time') ?: '')));
                $signal['detail'] = trim((string)($signal['detail'] ?? '') . ', maintenance_plans=' . $planCount . ', active_plans=' . $activePlanCount . ', plan_executions=' . $planExecutionCount, ', ');
                if ($planCount > 0 && $planExecutionCount <= 0) {
                    $signal['data_gaps'][] = ['code' => 'asset_maintenance_execution_missing', 'message' => 'Maintenance plans exist but no execution count is recorded.'];
                }
            } catch (Throwable $e) {
                $signal['data_gaps'][] = ['code' => 'maintenance_plans_read_failed', 'message' => 'Maintenance plan summary read failed.'];
            }
        } else {
            $signal['data_gaps'][] = ['code' => 'maintenance_plans_missing', 'message' => 'maintenance_plans table missing.'];
        }

        if ($this->tableExists('device_maintenance')) {
            try {
                $maintenanceQuery = Db::name('device_maintenance')->alias('m')->join('devices d', 'm.device_id = d.id');
                $this->applyHotelScope($maintenanceQuery, $hotelIds, $hotelId, 'd.hotel_id');
                $maintenanceCount = (int)(clone $maintenanceQuery)->count();
                $completedMaintenanceCount = (int)(clone $maintenanceQuery)->where('m.status', 3)->count();
                $signal['record_count'] += $maintenanceCount;
                $signal['executed_count'] += $completedMaintenanceCount;
                $signal['evidence_ready_count'] += $completedMaintenanceCount;
                $signal['reviewed_count'] += $completedMaintenanceCount;
                $signal['roi_ready_count'] += $completedMaintenanceCount;
                $signal['latest_at'] = $this->maxDateString((string)$signal['latest_at'], (string)((clone $maintenanceQuery)->max('m.update_time') ?: ((clone $maintenanceQuery)->max('m.create_time') ?: '')));
                $signal['detail'] = trim((string)($signal['detail'] ?? '') . ', maintenance_records=' . $maintenanceCount . ', completed_maintenance=' . $completedMaintenanceCount, ', ');
            } catch (Throwable $e) {
                $signal['data_gaps'][] = ['code' => 'device_maintenance_read_failed', 'message' => 'Device maintenance summary read failed.'];
            }
        } else {
            $signal['data_gaps'][] = ['code' => 'device_maintenance_missing', 'message' => 'device_maintenance table missing.'];
        }

        return $signal;
    }

    private function operationExecutionSignal(array $summary, array $executionFlow): array
    {
        $dataGaps = is_array($executionFlow['data_gaps'] ?? null) ? $executionFlow['data_gaps'] : [];
        $missingExecutionTable = false;
        foreach ($dataGaps as $gap) {
            if (is_array($gap) && str_contains((string)($gap['code'] ?? ''), 'operation_execution_intents_missing')) {
                $missingExecutionTable = true;
                break;
            }
        }
        $flowStatus = (string)($executionFlow['data_status'] ?? '');

        return [
            'key' => 'operation_execution',
            'label' => '运营执行闭环',
            'source_scope' => 'operation_execution_intents/tasks/evidence/review/ROI',
            'table_status' => $missingExecutionTable || ($flowStatus !== '' && $flowStatus !== 'ok') ? 'missing_table' : 'ok',
            'record_count' => (int)($summary['total'] ?? 0),
            'linked_execution_count' => (int)($summary['total'] ?? 0),
            'approved_count' => (int)($summary['approved'] ?? 0),
            'executed_count' => (int)($summary['executed'] ?? 0),
            'evidence_ready_count' => (int)($summary['evidence_ready'] ?? 0),
            'reviewed_count' => (int)($summary['stage_counts']['reviewed'] ?? 0),
            'roi_ready_count' => (int)($summary['roi_ready'] ?? 0),
            'blocked_count' => (int)($summary['stage_counts']['blocked'] ?? 0),
            'data_gaps' => $dataGaps,
            'empty_next_action' => 'create_execution_intent',
        ];
    }

    private function transferInvestmentSignal(array $hotelIds, ?int $hotelId, array $executionStats): array
    {
        $signal = $this->baseSignal('transfer_investment', '转让 / 投资测算', 'transfer_records from selected hotel scope; not full due diligence', $this->mergeExecutionStats($executionStats, ['transfer_decision', 'transfer_investment']));
        if (!$this->tableExists('transfer_records')) {
            return $this->missingTableSignal($signal, 'transfer_records');
        }

        try {
            $query = Db::name('transfer_records')->whereNull('deleted_at');
            $this->applyHotelScope($query, $hotelIds, $hotelId, 'hotel_id');
            $signal['record_count'] = (int)(clone $query)->count();
            $signal['latest_at'] = (string)((clone $query)->max('updated_at') ?: '');
            $readiness = (new TransferDecisionService())->readinessSummaryFromRows((clone $query)->order('id', 'desc')->limit(30)->select()->toArray());
            if ((int)($readiness['record_count'] ?? 0) > 0) {
                $signal['detail'] = 'best_readiness=' . (string)($readiness['best_status_label'] ?? '')
                    . ', score=' . (int)($readiness['best_score'] ?? 0)
                    . ', review_ready=' . (int)($readiness['review_ready_count'] ?? 0)
                    . ', decision_ready=' . (int)($readiness['decision_ready_count'] ?? 0);
                foreach (array_slice((array)($readiness['missing_evidence'] ?? []), 0, 3) as $gap) {
                    if (!is_array($gap)) {
                        continue;
                    }
                    $signal['data_gaps'][] = [
                        'code' => 'transfer_readiness_' . (string)($gap['code'] ?? 'missing'),
                        'message' => (string)($gap['label'] ?? 'Transfer readiness evidence missing') . ': ' . (string)($gap['next_action'] ?? ''),
                    ];
                }
            }
            if ($signal['record_count'] > 0 && (int)$signal['linked_execution_count'] <= 0) {
                $signal['data_gaps'][] = ['code' => 'transfer_due_diligence_loop_missing', 'message' => 'Transfer/investment records are calculations until due-diligence tasks and review evidence are linked.'];
            }
        } catch (Throwable $e) {
            $signal['table_status'] = 'read_failed';
            $signal['data_gaps'][] = ['code' => 'transfer_records_read_failed', 'message' => 'Transfer record summary read failed.'];
        }

        return $signal;
    }

    private function expansionSignal(int $userId, bool $isSuperAdmin, array $executionStats): array
    {
        $signal = $this->baseSignal('expansion', '扩张 / 市场评估', 'expansion_records; screening records only until execution evidence is linked', $this->mergeExecutionStats($executionStats, ['expansion', 'market_evaluation']));
        if (!$this->tableExists('expansion_records')) {
            return $this->missingTableSignal($signal, 'expansion_records');
        }

        try {
            $query = Db::name('expansion_records')->whereNull('deleted_at');
            $this->applyOwnerScope($query, $userId, $isSuperAdmin);
            $signal['record_count'] = (int)(clone $query)->count();
            $signal['latest_at'] = (string)((clone $query)->max('updated_at') ?: '');
            $readiness = (new ExpansionService())->readinessSummaryFromRows((clone $query)->order('id', 'desc')->limit(30)->select()->toArray());
            if ((int)($readiness['record_count'] ?? 0) > 0) {
                $signal['detail'] = 'best_readiness=' . (string)($readiness['best_status_label'] ?? '')
                    . ', score=' . (int)($readiness['best_score'] ?? 0)
                    . ', review_ready=' . (int)($readiness['review_ready_count'] ?? 0)
                    . ', project_ready=' . (int)($readiness['project_ready_count'] ?? 0);
                foreach (array_slice((array)($readiness['missing_evidence'] ?? []), 0, 3) as $gap) {
                    if (!is_array($gap)) {
                        continue;
                    }
                    $signal['data_gaps'][] = [
                        'code' => 'expansion_readiness_' . (string)($gap['code'] ?? 'missing'),
                        'message' => (string)($gap['label'] ?? 'Expansion readiness evidence missing') . ': ' . (string)($gap['next_action'] ?? ''),
                    ];
                }
            }
            if ($signal['record_count'] > 0 && (int)$signal['linked_execution_count'] <= 0) {
                $signal['data_gaps'][] = ['code' => 'expansion_execution_bridge_missing', 'message' => 'Expansion records are screening outputs until diligence/action evidence is linked.'];
            }
        } catch (Throwable $e) {
            $signal['table_status'] = 'read_failed';
            $signal['data_gaps'][] = ['code' => 'expansion_records_read_failed', 'message' => 'Expansion record summary read failed.'];
        }

        return $signal;
    }

    private function openingSignal(int $userId, bool $isSuperAdmin, array $executionStats): array
    {
        $signal = $this->baseSignal('opening', '开业管理', 'opening_projects/tasks; checklist loop, not OTA go-live proof', $this->mergeExecutionStats($executionStats, ['opening']));
        if (!$this->tableExists('opening_projects')) {
            return $this->missingTableSignal($signal, 'opening_projects');
        }

        try {
            $query = Db::name('opening_projects')->where('status', '<>', 'archived');
            $this->applyOwnerScope($query, $userId, $isSuperAdmin);
            $signal['record_count'] = (int)(clone $query)->count();
            $signal['latest_at'] = (string)((clone $query)->max('updated_at') ?: '');
            $unbound = (int)(clone $query)->where('hotel_id', 0)->count();
            if ($unbound > 0) {
                $signal['data_gaps'][] = ['code' => 'opening_project_hotel_scope_missing', 'message' => 'Opening projects exist without a bound hotel_id; they cannot prove hotel/OTA go-live closure.'];
            }
            if ($signal['record_count'] > 0 && (int)$signal['linked_execution_count'] <= 0) {
                $signal['data_gaps'][] = ['code' => 'opening_go_live_evidence_missing', 'message' => 'Opening checklist records are not linked to go-live evidence or post-opening performance review.'];
            }
        } catch (Throwable $e) {
            $signal['table_status'] = 'read_failed';
            $signal['data_gaps'][] = ['code' => 'opening_projects_read_failed', 'message' => 'Opening project summary read failed.'];
        }

        return $signal;
    }

    private function strategySimulationSignal(int $userId, bool $isSuperAdmin, array $executionStats): array
    {
        $signal = $this->baseSignal('strategy_simulation', '策略 / 量化模拟', 'strategy and quant simulation records; not executed until linked to operation execution', $this->mergeExecutionStats($executionStats, ['strategy_simulation', 'quant_simulation']));
        $recordCount = 0;
        $latest = '';
        $missing = [];
        $strategyRows = [];
        $quantRows = [];

        foreach (['strategy_simulation_records', 'quant_simulation_records'] as $table) {
            if (!$this->tableExists($table)) {
                $missing[] = $table;
                continue;
            }
            try {
                $query = Db::name($table)->whereNull('deleted_at');
                $this->applyOwnerScope($query, $userId, $isSuperAdmin);
                $recordCount += (int)(clone $query)->count();
                $tableLatest = (string)((clone $query)->max('updated_at') ?: '');
                if ($tableLatest !== '' && ($latest === '' || $tableLatest > $latest)) {
                    $latest = $tableLatest;
                }
                $rows = (clone $query)->order('id', 'desc')->limit(30)->select()->toArray();
                if ($table === 'strategy_simulation_records') {
                    $strategyRows = $rows;
                } else {
                    $quantRows = $rows;
                }
            } catch (Throwable $e) {
                $signal['table_status'] = 'read_failed';
                $signal['data_gaps'][] = ['code' => $table . '_read_failed', 'message' => $table . ' summary read failed.'];
            }
        }

        $signal['record_count'] = $recordCount;
        $signal['latest_at'] = $latest;
        foreach ($missing as $table) {
            $signal['data_gaps'][] = ['code' => $table . '_missing', 'message' => $table . ' table missing.'];
        }
        if ($recordCount > 0) {
            $readiness = (new SimulationExecutionReadinessService())->readinessSummaryFromRows($strategyRows, $quantRows);
            if ((int)($readiness['record_count'] ?? 0) > 0) {
                $signal['detail'] = 'best_readiness=' . (string)($readiness['best_status_label'] ?? '')
                    . ', score=' . (int)($readiness['best_score'] ?? 0)
                    . ', review_ready=' . (int)($readiness['review_ready_count'] ?? 0)
                    . ', execution_ready=' . (int)($readiness['execution_ready_count'] ?? 0);
                foreach (array_slice((array)($readiness['missing_evidence'] ?? []), 0, 3) as $gap) {
                    if (!is_array($gap)) {
                        continue;
                    }
                    $signal['data_gaps'][] = [
                        'code' => 'simulation_readiness_' . (string)($gap['code'] ?? 'missing'),
                        'message' => (string)($gap['label'] ?? 'Simulation readiness evidence missing') . ': ' . (string)($gap['next_action'] ?? ''),
                    ];
                }
            }
        }
        if ($recordCount > 0 && (int)$signal['linked_execution_count'] <= 0) {
            $signal['data_gaps'][] = ['code' => 'simulation_execution_bridge_missing', 'message' => 'Simulation records are not linked to approval/execution/evidence/ROI.'];
        }

        return $signal;
    }

    private function feasibilityReportSignal(int $userId, bool $isSuperAdmin, array $executionStats): array
    {
        $signal = $this->baseSignal('feasibility_report', 'AI可行性报告', 'feasibility_reports; advisory report until diligence, review, and tracking evidence exist', $this->mergeExecutionStats($executionStats, ['feasibility_report', 'agent_feasibility', 'feasibility']));
        if (!$this->tableExists('feasibility_reports')) {
            return $this->missingTableSignal($signal, 'feasibility_reports');
        }

        try {
            $query = Db::name('feasibility_reports')->whereNull('deleted_at');
            $this->applyOwnerScope($query, $userId, $isSuperAdmin);
            $signal['record_count'] = (int)(clone $query)->count();
            $signal['latest_at'] = (string)((clone $query)->max('updated_at') ?: ((clone $query)->max('created_at') ?: ''));
            $readiness = (new FeasibilityReportService())->readinessSummaryFromRows((clone $query)->order('id', 'desc')->limit(30)->select()->toArray());
            if ((int)($readiness['record_count'] ?? 0) > 0) {
                $signal['detail'] = 'best_readiness=' . (string)($readiness['best_status_label'] ?? '')
                    . ', score=' . (int)($readiness['best_score'] ?? 0)
                    . ', review_ready=' . (int)($readiness['review_ready_count'] ?? 0)
                    . ', feasibility_ready=' . (int)($readiness['feasibility_ready_count'] ?? 0);
                foreach (array_slice((array)($readiness['missing_evidence'] ?? []), 0, 3) as $gap) {
                    if (!is_array($gap)) {
                        continue;
                    }
                    $signal['data_gaps'][] = [
                        'code' => 'feasibility_readiness_' . (string)($gap['code'] ?? 'missing'),
                        'message' => (string)($gap['label'] ?? 'Feasibility readiness evidence missing') . ': ' . (string)($gap['next_action'] ?? ''),
                    ];
                }
            }
            if ($signal['record_count'] > 0 && (int)$signal['linked_execution_count'] <= 0) {
                $signal['data_gaps'][] = ['code' => 'feasibility_investment_loop_missing', 'message' => 'Feasibility reports are advisory until diligence, human review, execution/tracking, and ROI evidence are linked.'];
            }
        } catch (Throwable $e) {
            $signal['table_status'] = 'read_failed';
            $signal['data_gaps'][] = ['code' => 'feasibility_reports_read_failed', 'message' => 'Feasibility report summary read failed.'];
        }

        return $signal;
    }

    private function baseSignal(string $key, string $label, string $sourceScope, array $stats): array
    {
        return array_merge([
            'key' => $key,
            'label' => $label,
            'source_scope' => $sourceScope,
            'table_status' => 'ok',
            'record_count' => 0,
            'linked_execution_count' => 0,
            'approved_count' => 0,
            'executed_count' => 0,
            'evidence_ready_count' => 0,
            'reviewed_count' => 0,
            'roi_ready_count' => 0,
            'blocked_count' => 0,
            'rejected_count' => 0,
            'data_gaps' => [],
            'latest_at' => '',
        ], $stats);
    }

    private function missingTableSignal(array $signal, string $table): array
    {
        $signal['table_status'] = 'missing_table';
        $signal['data_gaps'][] = ['code' => $table . '_missing', 'message' => $table . ' table missing.'];
        return $signal;
    }

    private function executionStatsBySource(array $items): array
    {
        $stats = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $source = (string)($item['recommendation']['source_module'] ?? 'manual');
            if (!isset($stats[$source])) {
                $stats[$source] = [
                    'linked_execution_count' => 0,
                    'approved_count' => 0,
                    'executed_count' => 0,
                    'evidence_ready_count' => 0,
                    'reviewed_count' => 0,
                    'roi_ready_count' => 0,
                    'blocked_count' => 0,
                    'rejected_count' => 0,
                ];
            }
            $stats[$source]['linked_execution_count']++;
            if (($item['approval']['status'] ?? '') === 'approved') {
                $stats[$source]['approved_count']++;
            }
            if (($item['approval']['status'] ?? '') === 'rejected') {
                $stats[$source]['rejected_count']++;
            }
            if (($item['execution']['status'] ?? '') === 'executed') {
                $stats[$source]['executed_count']++;
            }
            if ((int)($item['evidence']['count'] ?? 0) > 0) {
                $stats[$source]['evidence_ready_count']++;
            }
            if (($item['stage'] ?? '') === 'reviewed') {
                $stats[$source]['reviewed_count']++;
            }
            if (($item['stage'] ?? '') === 'blocked') {
                $stats[$source]['blocked_count']++;
            }
            if (($item['roi']['status'] ?? '') === 'ready') {
                $stats[$source]['roi_ready_count']++;
            }
        }

        return $stats;
    }

    private function mergeExecutionStats(array $stats, array $sources): array
    {
        $merged = [];
        foreach ($sources as $source) {
            foreach (($stats[$source] ?? []) as $key => $value) {
                $merged[$key] = (int)($merged[$key] ?? 0) + (int)$value;
            }
        }

        return $merged;
    }

    private function conversationLinkedWorkOrderCount(array $hotelIds, ?int $hotelId): int
    {
        if (!$this->tableExists('agent_work_orders')) {
            return 0;
        }

        try {
            $query = Db::name('agent_work_orders')->field('tags');
            $this->applyHotelScope($query, $hotelIds, $hotelId, 'hotel_id');
            $rows = $query->whereLike('tags', '%conversation:%')->select()->toArray();
        } catch (Throwable $e) {
            return 0;
        }

        $conversationIds = [];
        foreach ($rows as $row) {
            $tags = $row['tags'] ?? [];
            if (is_string($tags)) {
                $decoded = json_decode($tags, true);
                $tags = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($tags)) {
                continue;
            }
            foreach ($tags as $tag) {
                if (!is_string($tag) || !str_starts_with($tag, 'conversation:')) {
                    continue;
                }
                $conversationId = (int)substr($tag, strlen('conversation:'));
                if ($conversationId > 0) {
                    $conversationIds[$conversationId] = true;
                }
            }
        }

        return count($conversationIds);
    }

    private function applyHotelScope($query, array $hotelIds, ?int $hotelId, string $field): void
    {
        if ($hotelId !== null && $hotelId > 0) {
            $query->where($field, $hotelId);
            return;
        }
        if (!empty($hotelIds)) {
            $query->whereIn($field, array_values(array_map('intval', $hotelIds)));
            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function applyOwnerScope($query, int $userId, bool $isSuperAdmin): void
    {
        if ($isSuperAdmin) {
            return;
        }
        if ($userId <= 0) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->where('created_by', $userId);
    }

    private function tableExists(string $table): bool
    {
        try {
            Db::query('SELECT 1 FROM `' . str_replace('`', '', $table) . '` LIMIT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function statusLabel(string $status): string
    {
        return [
            'not_loaded' => '未加载',
            'not_started' => '未开始',
            'record_only' => '仅有记录',
            'pending_approval' => '待审批',
            'approved_pending_execution' => '待执行',
            'executed_missing_evidence' => '缺执行证据',
            'evidence_ready' => '待复盘',
            'reviewed_no_roi' => '已复盘缺效果证据',
            'roi_ready' => '已闭环',
            'blocked' => '阻塞',
            'rejected' => '已驳回',
        ][$status] ?? $status;
    }

    private function nextActionLabel(string $nextAction): string
    {
        return [
            'run_migration_or_verify_table' => '检查数据表',
            'create_source_record' => '先生成业务记录',
            'create_execution_intent' => '创建执行意图',
            'bridge_to_operation_execution' => '接入执行闭环',
            'approve_execution_intent' => '审批执行意图',
            'execute_and_record_evidence' => '执行并录证据',
            'record_execution_evidence' => '补执行证据',
            'review_execution_result' => '复盘执行结果',
            'add_roi_evidence' => '补效果/ROI证据',
            'keep_reviewing' => '持续复盘',
            'fix_blocked_reason' => '处理阻塞原因',
            'rework_or_archive' => '重做或归档',
        ][$nextAction] ?? $nextAction;
    }

    private function maxDateString(string $left, string $right): string
    {
        if ($left === '') {
            return $right;
        }
        if ($right === '') {
            return $left;
        }

        return $right > $left ? $right : $left;
    }
}
