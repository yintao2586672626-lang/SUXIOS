<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;
use Throwable;

class BusinessClosureOverviewService
{
    private const MODULE_PROFILES = [
        'ai_daily_report' => [
            'module_group' => '运营管理（P0）',
            'entry_page' => 'ai-daily-report',
            'ai_connection' => 'llm_optional',
            'ai_connection_label' => '可接入AI日报',
            'data_basis' => 'verified_ota_operation_records',
            'data_basis_label' => 'OTA/运营记录',
            'theory_basis' => 'LLM不可用时使用日报规则摘要、异常指标和缺口清单。',
            'closure_target' => '日报建议 -> 转执行单 -> 执行证据 -> ROI复盘',
        ],
        'revenue_pricing' => [
            'module_group' => '运营管理（P0）',
            'entry_page' => 'agent-center',
            'ai_connection' => 'rule_agent',
            'ai_connection_label' => '收益Agent/规则',
            'data_basis' => 'price_suggestions_and_online_daily_data',
            'data_basis_label' => '调价建议+OTA样本',
            'theory_basis' => '无真实执行回写时使用ADR、RevPAR、价差和价格弹性规则估算。',
            'closure_target' => '调价建议 -> 人工审批 -> 执行证据 -> 应用效果复盘',
        ],
        'operation_execution' => [
            'module_group' => '运营管理（P0）',
            'entry_page' => 'ops-track',
            'ai_connection' => 'execution_hub',
            'ai_connection_label' => '承接AI/人工建议',
            'data_basis' => 'execution_intents_tasks_evidence_roi',
            'data_basis_label' => '执行单/证据/ROI',
            'theory_basis' => '未产生ROI时只显示审批、执行和证据状态，不推断收益完成。',
            'closure_target' => '建议池 -> 审批 -> 执行 -> 证据 -> 复盘/ROI',
        ],
    ];

    private const MODULE_ORDER = [
        'ai_daily_report',
        'revenue_pricing',
        'operation_execution',
    ];

    private const P0_BLOCKED_MODULE_KEYS = [
        'ai_daily_report',
        'revenue_pricing',
        'operation_execution',
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
            $this->operationExecutionSignal($executionSummary, $executionFlow),
        ];

        $p0DownstreamGate = (new P0OtaDownstreamGateService())->normalize([
            'status' => 'blocked_by_p0_ota_gate',
            'current_upstream_status' => 'not_verified',
            'blocking_missing_inputs' => ['p0_field_loop_verifier_ready'],
        ], '', $hotelId);

        return $this->buildOverviewFromSignals($modules, $executionSummary, $dataGaps, $p0DownstreamGate);
    }

    public function buildOverviewFromSignals(array $signals, array $executionSummary = [], array $dataGaps = [], array $p0DownstreamGate = []): array
    {
        $modules = [];
        foreach ($signals as $signal) {
            if (!is_array($signal)) {
                continue;
            }
            $key = (string)($signal['key'] ?? '');
            if (!in_array($key, self::MODULE_ORDER, true)) {
                continue;
            }
            $modules[] = $this->summarizeModuleClosure($signal);
        }

        $p0Gate = $this->normalizeP0DownstreamGate($p0DownstreamGate);
        $p0Blocked = (string)($p0Gate['status'] ?? '') === 'blocked_by_p0_ota_gate';
        if ($p0Blocked) {
            $modules = $this->applyP0GateToModules($modules, $p0Gate);
            $dataGaps[] = [
                'code' => 'p0_ota_gate_not_ready',
                'message' => 'P0 OTA field-loop verifier is not ready; downstream revenue, AI and operation closure claims remain blocked.',
            ];
        }

        usort($modules, static function (array $a, array $b): int {
            $left = array_search((string)($a['key'] ?? ''), self::MODULE_ORDER, true);
            $right = array_search((string)($b['key'] ?? ''), self::MODULE_ORDER, true);
            return ($left === false ? 999 : $left) <=> ($right === false ? 999 : $right);
        });

        $total = count($modules);
        $closed = 0;
        $processClosed = 0;
        $roiReadyModules = 0;
        $blocked = 0;
        $recordOnly = 0;
        $scoreSum = 0;
        foreach ($modules as $module) {
            if (($module['closed_loop'] ?? false) === true) {
                $closed++;
            }
            if (($module['process_closed_loop'] ?? false) === true) {
                $processClosed++;
            }
            if (($module['roi_ready'] ?? false) === true) {
                $roiReadyModules++;
            }
            if (in_array((string)($module['status'] ?? ''), ['not_loaded', 'blocked', 'blocked_by_p0_ota_gate', 'blocked_by_ai_summary_failure'], true)) {
                $blocked++;
            }
            if (($module['status'] ?? '') === 'record_only') {
                $recordOnly++;
            }
            $scoreSum += (int)($module['maturity_score'] ?? 0);
        }

        $processWeakModules = array_values(array_filter($modules, static function (array $module): bool {
            return ($module['process_closed_loop'] ?? false) !== true;
        }));
        $roiWeakModules = array_values(array_filter($modules, static function (array $module): bool {
            return ($module['roi_ready'] ?? false) !== true;
        }));

        return [
            'summary' => [
                'module_count' => $total,
                'closed_loop_count' => $closed,
                'not_closed_count' => max(0, $total - $closed),
                'process_closed_count' => $processClosed,
                'not_process_closed_count' => max(0, $total - $processClosed),
                'roi_ready_module_count' => $roiReadyModules,
                'process_status' => $processClosed === $total && $total > 0 ? 'closed' : 'not_closed',
                'roi_status' => $roiReadyModules === $total && $total > 0 ? 'closed' : 'not_closed',
                'blocked_count' => $blocked,
                'record_only_count' => $recordOnly,
                'avg_maturity_score' => $total > 0 ? round($scoreSum / $total, 1) : null,
                'operation_execution_total' => (int)($executionSummary['total'] ?? 0),
                'operation_roi_ready' => (int)($executionSummary['roi_ready'] ?? 0),
                'status' => $p0Blocked ? 'blocked_by_p0_ota_gate' : ($closed === $total && $total > 0 ? 'closed' : 'not_closed'),
            ],
            'modules' => $modules,
            'weak_modules' => array_slice($roiWeakModules, 0, 5),
            'process_weak_modules' => array_slice($processWeakModules, 0, 5),
            'roi_weak_modules' => array_slice($roiWeakModules, 0, 5),
            'data_status' => empty($dataGaps) ? 'ok' : 'data_gap',
            'data_gaps' => $dataGaps,
            'source_scope' => 'post_ota_closure_only',
            'protected_scope' => 'online data collection and OTA standardization are read-only for this overview',
            'p0_downstream_gate' => $p0Gate,
        ];
    }

    private function normalizeP0DownstreamGate(array $gate): array
    {
        if ($gate === []) {
            return [];
        }

        return (new P0OtaDownstreamGateService())->normalize($gate);
    }

    private function applyP0GateToModules(array $modules, array $p0Gate): array
    {
        return array_map(function (array $module) use ($p0Gate): array {
            if (!in_array((string)($module['key'] ?? ''), self::P0_BLOCKED_MODULE_KEYS, true)) {
                return $module;
            }

            $module['pre_p0_status'] = (string)($module['status'] ?? '');
            $module['status'] = 'blocked_by_p0_ota_gate';
            $module['status_label'] = $this->statusLabel('blocked_by_p0_ota_gate');
            $module['closed_loop'] = false;
            $module['process_closed_loop'] = false;
            $module['process_status'] = 'not_closed';
            $module['roi_ready'] = false;
            $module['roi_status'] = 'not_ready';
            $module['maturity_score'] = min((int)($module['maturity_score'] ?? 0), 40);
            $module['next_action'] = 'verify_p0_ota_gate';
            $module['next_action_label'] = $this->nextActionLabel('verify_p0_ota_gate');
            $module['p0_downstream_gate'] = $p0Gate;
            $module['data_gaps'][] = [
                'code' => 'p0_ota_gate_not_ready',
                'message' => 'P0 OTA field-loop verifier is not ready; this module cannot be claimed as a downstream closed loop.',
            ];

            return $module;
        }, $modules);
    }

    public function summarizeModuleClosure(array $signal): array
    {
        $profile = $this->moduleProfile((string)($signal['key'] ?? ''));
        $tableStatus = (string)($signal['table_status'] ?? 'ok');
        $recordCount = max(0, (int)($signal['record_count'] ?? 0));
        $linked = max(0, (int)($signal['linked_execution_count'] ?? 0));
        $approved = max(0, (int)($signal['approved_count'] ?? 0));
        $executed = max(0, (int)($signal['executed_count'] ?? 0));
        $evidenceReady = max(0, (int)($signal['evidence_ready_count'] ?? 0));
        $reviewed = max(0, (int)($signal['reviewed_count'] ?? 0));
        $roiReady = max(0, (int)($signal['roi_ready_count'] ?? 0));
        $blocked = max(0, (int)($signal['blocked_count'] ?? 0));
        $aiSummaryFailures = max(0, (int)($signal['ai_summary_failure_count'] ?? 0));
        $rejected = max(0, (int)($signal['rejected_count'] ?? 0));
        $dataGaps = array_values(array_filter((array)($signal['data_gaps'] ?? []), 'is_array'));

        if ($aiSummaryFailures > 0) {
            $status = 'blocked_by_ai_summary_failure';
            $nextAction = 'review_ai_summary_failure';
            $score = 0;
            $dataGaps[] = [
                'code' => 'blocked_by_ai_summary_failure',
                'message' => 'AI summary failed; fallback content is investigation-only and cannot support execution or closure claims.',
            ];
        } elseif ($tableStatus !== 'ok') {
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
        $processClosed = $aiSummaryFailures === 0 && ($reviewed > 0 || $roiReady > 0);
        $roiReadyFlag = $aiSummaryFailures === 0 && $roiReady > 0;
        $processStatus = $processClosed ? 'closed' : 'not_closed';
        $roiStatus = $roiReadyFlag ? 'ready' : 'not_ready';

        return [
            'key' => (string)($signal['key'] ?? ''),
            'label' => (string)($signal['label'] ?? $signal['key'] ?? ''),
            'module_group' => $profile['module_group'],
            'entry_page' => $profile['entry_page'],
            'ai_connection' => $profile['ai_connection'],
            'ai_connection_label' => $profile['ai_connection_label'],
            'data_basis' => $profile['data_basis'],
            'data_basis_label' => $profile['data_basis_label'],
            'theory_basis' => $profile['theory_basis'],
            'closure_target' => $profile['closure_target'],
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'closed_loop' => $status === 'roi_ready' && $aiSummaryFailures === 0,
            'process_closed_loop' => $processClosed,
            'process_status' => $processStatus,
            'roi_ready' => $roiReadyFlag,
            'roi_status' => $roiStatus,
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
            'ai_summary_failure_count' => $aiSummaryFailures,
            'latest_at' => (string)($signal['latest_at'] ?? ''),
            'data_gaps' => $dataGaps,
            'detail' => (string)($signal['detail'] ?? ''),
        ];
    }

    private function moduleProfile(string $key): array
    {
        return array_merge([
            'module_group' => '运营收益闭环',
            'entry_page' => '',
            'ai_connection' => 'not_declared',
            'ai_connection_label' => 'AI状态未声明',
            'data_basis' => 'existing_records',
            'data_basis_label' => '现有记录',
            'theory_basis' => '缺少真实样本时只显示缺口，不推断闭环结果。',
            'closure_target' => '业务记录 -> 执行证据 -> 复盘',
        ], self::MODULE_PROFILES[$key] ?? []);
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
                $reportRows = (clone $query)->order('id', 'desc')->limit(30)->select()->toArray();
                $signal['ai_summary_failure_count'] = count(array_filter($reportRows, static function (array $row): bool {
                    return (string)($row['decision_status'] ?? '') === 'blocked_by_ai_summary_failure';
                }));
                $readiness = (new AiDailyReportService())->readinessSummaryFromRows($reportRows, $hotelIds, $hotelId);
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
            'blocked_by_p0_ota_gate' => 'P0未就绪',
            'blocked_by_ai_summary_failure' => 'AI汇总失败',
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
            'verify_p0_ota_gate' => '复验P0 OTA门禁',
            'fix_blocked_reason' => '处理阻塞原因',
            'review_ai_summary_failure' => '复核 AI 汇总失败',
            'rework_or_archive' => '重做或归档',
        ][$nextAction] ?? $nextAction;
    }

}
