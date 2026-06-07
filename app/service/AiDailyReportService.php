<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;
use Throwable;

class AiDailyReportService
{
    private const TABLE = 'ai_daily_reports';
    private const DATA_OK = 'ok';
    private const DATA_PENDING = 'pending';
    private const DEFAULT_MODEL_KEY = 'deepseek_v4_default';

    private OperationManagementService $operationService;
    private LlmClient $llmClient;

    public function __construct(?OperationManagementService $operationService = null, ?LlmClient $llmClient = null)
    {
        $this->operationService = $operationService ?? new OperationManagementService();
        $this->llmClient = $llmClient ?? new LlmClient();
    }

    public function list(array $hotelIds, ?int $hotelId, array $filters = []): array
    {
        if (!$this->tableExists(self::TABLE)) {
            return [
                'list' => [],
                'data_status' => 'missing_table',
                'data_gaps' => [['code' => 'ai_daily_reports_table_missing', 'message' => 'ai_daily_reports table does not exist']],
            ];
        }

        $query = Db::name(self::TABLE)->whereNull('deleted_at');
        $this->applyHotelScope($query, $hotelIds, $hotelId);

        $date = trim((string)($filters['report_date'] ?? $filters['date'] ?? ''));
        if ($date !== '') {
            $query->where('report_date', $this->normalizeDate($date));
        }

        $page = max(1, (int)($filters['page'] ?? 1));
        $pageSize = min(50, max(1, (int)($filters['page_size'] ?? 10)));
        $total = (int)(clone $query)->count();
        $rows = $query
            ->order('report_date', 'desc')
            ->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return [
            'list' => array_map([$this, 'normalizeReportRow'], $rows),
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
                'total_page' => (int)ceil($total / max(1, $pageSize)),
            ],
            'data_status' => self::DATA_OK,
        ];
    }

    public function latest(array $hotelIds, ?int $hotelId): array
    {
        if (!$this->tableExists(self::TABLE)) {
            return [
                'report' => null,
                'data_status' => 'missing_table',
                'data_gaps' => [['code' => 'ai_daily_reports_table_missing', 'message' => 'ai_daily_reports table does not exist']],
            ];
        }

        $query = Db::name(self::TABLE)->whereNull('deleted_at');
        $this->applyHotelScope($query, $hotelIds, $hotelId);
        $row = $query->order('report_date', 'desc')->order('id', 'desc')->find();

        return [
            'report' => is_array($row) ? $this->normalizeReportRow($row) : null,
            'data_status' => is_array($row) ? self::DATA_OK : self::DATA_PENDING,
            'data_gaps' => is_array($row) ? [] : [['code' => 'ai_daily_report_not_generated', 'message' => 'AI daily report has not been generated for the selected hotel']],
        ];
    }

    public function read(int $id, array $hotelIds): ?array
    {
        if (!$this->tableExists(self::TABLE)) {
            throw new \RuntimeException('ai_daily_reports table does not exist, run database migration first');
        }

        if ($id <= 0 || empty($hotelIds)) {
            return null;
        }

        $row = Db::name(self::TABLE)
            ->where('id', $id)
            ->whereIn('hotel_id', $hotelIds)
            ->whereNull('deleted_at')
            ->find();

        return is_array($row) ? $this->normalizeReportRow($row) : null;
    }

    public function generate(array $hotelIds, ?int $hotelId, string $reportDate, int $userId, array $options = []): array
    {
        if (!$this->tableExists(self::TABLE)) {
            throw new \RuntimeException('ai_daily_reports table does not exist, run database migration first');
        }

        $selectedHotelId = $this->resolveSingleHotelId($hotelIds, $hotelId);
        $reportDate = $this->normalizeDate($reportDate);
        $snapshot = $this->buildSnapshot($hotelIds, $selectedHotelId, $reportDate);
        $ruleReport = $this->buildRuleReport($snapshot, $reportDate, $selectedHotelId);

        $modelKey = trim((string)($options['model_key'] ?? ''));
        if ($modelKey === '') {
            $modelKey = self::DEFAULT_MODEL_KEY;
        }
        $useLlm = !array_key_exists('use_llm', $options) || filter_var($options['use_llm'], FILTER_VALIDATE_BOOL);
        $finalReport = $ruleReport;
        $generationMode = 'rule';
        $modelStatus = 'not_requested';
        $modelMessage = '';

        if ($useLlm) {
            $llmResult = $this->tryEnhanceWithLlm($ruleReport, $snapshot, $modelKey);
            $modelStatus = $llmResult['model_status'];
            $modelMessage = $llmResult['model_message'];
            if (is_array($llmResult['report'])) {
                $finalReport = $this->mergeLlmReport($ruleReport, $llmResult['report']);
                $generationMode = 'llm';
            } else {
                $generationMode = 'rule';
            }
        }

        $now = date('Y-m-d H:i:s');
        $payload = $this->withTenantId([
            'hotel_id' => $selectedHotelId,
            'report_date' => $reportDate,
            'status' => 'generated',
            'generation_mode' => $generationMode,
            'model_key' => $modelKey,
            'model_status' => $modelStatus,
            'model_message' => $modelMessage,
            'summary' => (string)($finalReport['summary'] ?? ''),
            'yesterday_result_json' => $this->json($finalReport['yesterday_result'] ?? []),
            'abnormal_metrics_json' => $this->json($finalReport['abnormal_metrics'] ?? []),
            'competitor_changes_json' => $this->json($finalReport['competitor_changes'] ?? []),
            'data_gaps_json' => $this->json($finalReport['data_gaps'] ?? []),
            'recommended_actions_json' => $this->json($finalReport['recommended_actions'] ?? []),
            'source_refs_json' => $this->json($finalReport['source_refs'] ?? []),
            'snapshot_json' => $this->json($snapshot),
            'created_by' => $userId,
            'updated_at' => $now,
        ], self::TABLE, $selectedHotelId);

        $existing = Db::name(self::TABLE)
            ->where('hotel_id', $selectedHotelId)
            ->where('report_date', $reportDate)
            ->whereNull('deleted_at')
            ->find();

        if (is_array($existing)) {
            Db::name(self::TABLE)->where('id', (int)$existing['id'])->update($payload);
            return $this->read((int)$existing['id'], [$selectedHotelId]) ?? [];
        }

        $payload['created_at'] = $now;
        $id = (int)Db::name(self::TABLE)->insertGetId($payload);
        return $this->read($id, [$selectedHotelId]) ?? [];
    }

    public function createExecutionIntentFromAction(int $reportId, int $actionIndex, array $hotelIds, int $userId): array
    {
        $report = $this->read($reportId, $hotelIds);
        if (!$report) {
            throw new \RuntimeException('AI daily report not found');
        }

        $actions = $report['recommended_actions'] ?? [];
        if (!isset($actions[$actionIndex]) || !is_array($actions[$actionIndex])) {
            throw new \InvalidArgumentException('AI daily report action index is invalid');
        }

        $action = $actions[$actionIndex];
        if (($action['can_create_execution_intent'] ?? true) === false) {
            throw new \InvalidArgumentException((string)($action['blocked_reason'] ?? 'action cannot create execution intent'));
        }

        $hotelId = (int)$report['hotel_id'];
        if ($hotelId <= 0 || !in_array($hotelId, array_map('intval', $hotelIds), true)) {
            throw new \InvalidArgumentException('hotel_id is not permitted');
        }

        $targetValue = is_array($action['target_value'] ?? null) ? $action['target_value'] : [];
        if (empty($targetValue)) {
            $targetValue = $this->defaultTargetValue($action);
        }

        $input = [
            'source_module' => 'ai_daily_report',
            'source_record_id' => $reportId,
            'hotel_id' => $hotelId,
            'platform' => (string)($action['platform'] ?? 'ota'),
            'object_type' => (string)($action['object_type'] ?? 'campaign'),
            'action_type' => (string)($action['action_type'] ?? 'promotion'),
            'date_start' => (string)($action['execution_time'] ?? $report['report_date']),
            'date_end' => (string)($action['date_end'] ?? $report['report_date']),
            'current_value' => is_array($action['current_value'] ?? null) ? $action['current_value'] : [],
            'target_value' => $targetValue,
            'evidence' => [
                'ai_daily_report_id' => $reportId,
                'action_index' => $actionIndex,
                'title' => (string)($action['title'] ?? ''),
                'reason' => (string)($action['reason'] ?? ''),
                'source_refs' => $action['source_refs'] ?? [],
                'data_gaps' => $report['data_gaps'] ?? [],
            ],
            'expected_metric' => (string)($action['expected_metric'] ?? $targetValue['target_metric'] ?? 'orders'),
            'expected_delta' => (float)($action['expected_delta'] ?? 0),
            'risk_level' => (string)($action['risk_level'] ?? 'medium'),
        ];

        $intent = $this->operationService->createExecutionIntent([$hotelId], $hotelId, $input, $userId);
        $actions[$actionIndex]['execution_intent_id'] = (int)($intent['id'] ?? 0);
        $actions[$actionIndex]['execution_status'] = (string)($intent['status'] ?? '');
        $actions[$actionIndex]['execution_blocked_reason'] = (string)($intent['blocked_reason'] ?? '');

        Db::name(self::TABLE)->where('id', $reportId)->update([
            'recommended_actions_json' => $this->json($actions),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return [
            'report_id' => $reportId,
            'action_index' => $actionIndex,
            'execution_intent' => $intent,
        ];
    }

    private function buildSnapshot(array $hotelIds, int $hotelId, string $reportDate): array
    {
        $operation = $this->operationService->fullData($hotelIds, $hotelId, $reportDate);
        $rootCause = $this->operationService->rootCause($hotelIds, $hotelId, $reportDate, '');
        $execution = $this->operationService->executionFlow($hotelIds, $hotelId, ['page_size' => 20]);

        return [
            'scope' => [
                'hotel_id' => $hotelId,
                'report_date' => $reportDate,
                'source_scope' => 'OTA channel and operating-report scope, not whole-hotel financial truth',
            ],
            'operation' => $operation,
            'root_cause' => $rootCause,
            'execution_flow' => $execution,
            'source_refs' => [
                ['key' => 'operation.full_data', 'label' => 'OperationManagementService.fullData', 'scope' => 'OTA/revenue/competitor/service quality modules'],
                ['key' => 'operation.root_cause', 'label' => 'OperationManagementService.rootCause', 'scope' => 'rule-based abnormal attribution'],
                ['key' => 'operation.execution_flow', 'label' => 'OperationManagementService.executionFlow', 'scope' => 'action execution and ROI loop'],
            ],
        ];
    }

    private function buildRuleReport(array $snapshot, string $reportDate, int $hotelId): array
    {
        $operation = $snapshot['operation'] ?? [];
        $summary = is_array($operation['summary'] ?? null) ? $operation['summary'] : [];
        $ota = is_array($operation['ota'] ?? null) ? $operation['ota'] : [];
        $competitors = is_array($operation['competitors'] ?? null) ? $operation['competitors'] : [];
        $rootCause = is_array($snapshot['root_cause'] ?? null) ? $snapshot['root_cause'] : [];
        $executionFlow = is_array($snapshot['execution_flow'] ?? null) ? $snapshot['execution_flow'] : [];

        $sourceRefs = $snapshot['source_refs'] ?? [];
        $dataGaps = $this->collectDataGaps($operation, $rootCause, $executionFlow);
        $abnormalMetrics = $this->collectAbnormalMetrics($operation, $rootCause);
        $competitorChanges = $this->collectCompetitorChanges($competitors);
        $yesterdayResult = $this->collectYesterdayResult($summary, $ota, $reportDate);
        $actions = $this->buildRecommendedActions($operation, $rootCause, $executionFlow, $dataGaps);

        return [
            'summary' => $this->buildSummaryText($yesterdayResult, $abnormalMetrics, $dataGaps),
            'yesterday_result' => $yesterdayResult,
            'abnormal_metrics' => $abnormalMetrics,
            'competitor_changes' => $competitorChanges,
            'data_gaps' => $dataGaps,
            'recommended_actions' => array_slice($actions, 0, 3),
            'source_refs' => $sourceRefs,
            'report_scope' => [
                'hotel_id' => $hotelId,
                'report_date' => $reportDate,
                'scope_note' => 'Based on authorized OTA and operating-report data. Guest privacy, order phone, room status and room-source mapping are excluded.',
            ],
        ];
    }

    private function tryEnhanceWithLlm(array $ruleReport, array $snapshot, string $modelKey): array
    {
        $schema = [
            'type' => 'object',
            'required' => ['summary', 'recommended_actions'],
            'properties' => [
                'summary' => ['type' => 'string'],
                'abnormal_metrics' => ['type' => 'array'],
                'competitor_changes' => ['type' => 'array'],
                'recommended_actions' => ['type' => 'array'],
            ],
        ];

        $messages = [
            [
                'role' => 'system',
                'content' => 'You are SUXIOS operating assistant. Use only the provided snapshot. Do not invent metrics. Keep missing data explicit. Return JSON only.',
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'task' => 'Generate an audited AI daily operating report with exactly up to 3 actions.',
                    'rule_report' => $ruleReport,
                    'snapshot' => $this->compactSnapshotForLlm($snapshot),
                ], JSON_UNESCAPED_UNICODE),
            ],
        ];

        try {
            $report = $this->llmClient->createJsonResponse($messages, $schema, $modelKey);
            return ['report' => $report, 'model_status' => 'ok', 'model_message' => ''];
        } catch (Throwable $e) {
            return [
                'report' => null,
                'model_status' => 'failed',
                'model_message' => mb_substr($e->getMessage(), 0, 500),
            ];
        }
    }

    private function mergeLlmReport(array $ruleReport, array $llmReport): array
    {
        $merged = $ruleReport;
        foreach (['summary', 'abnormal_metrics', 'competitor_changes'] as $field) {
            if (isset($llmReport[$field]) && $llmReport[$field] !== '') {
                $merged[$field] = $llmReport[$field];
            }
        }

        if (!empty($llmReport['recommended_actions']) && is_array($llmReport['recommended_actions'])) {
            $baseActions = $ruleReport['recommended_actions'] ?? [];
            $llmActions = array_slice($llmReport['recommended_actions'], 0, 3);
            $merged['recommended_actions'] = [];
            foreach ($llmActions as $index => $action) {
                if (!is_array($action)) {
                    continue;
                }
                $base = is_array($baseActions[$index] ?? null) ? $baseActions[$index] : [];
                $merged['recommended_actions'][] = array_merge($base, [
                    'title' => (string)($action['title'] ?? $base['title'] ?? ''),
                    'action' => (string)($action['action'] ?? $base['action'] ?? ''),
                    'reason' => (string)($action['reason'] ?? $base['reason'] ?? ''),
                    'source_refs' => $base['source_refs'] ?? [],
                ]);
            }
            foreach ($baseActions as $base) {
                if (count($merged['recommended_actions']) >= 3) {
                    break;
                }
                if (is_array($base)) {
                    $merged['recommended_actions'][] = $base;
                }
            }
        }

        $merged['data_gaps'] = $ruleReport['data_gaps'] ?? [];
        $merged['source_refs'] = $ruleReport['source_refs'] ?? [];
        return $merged;
    }

    private function collectYesterdayResult(array $summary, array $ota, string $reportDate): array
    {
        return [
            'report_date' => $reportDate,
            'source_scope' => 'OTA and operating-report scope',
            'metrics' => [
                ['key' => 'revenue', 'label' => 'Revenue', 'value' => $this->numericOrNull($summary['revenue'] ?? null), 'source_ref' => 'operation.full_data.summary.revenue'],
                ['key' => 'orders', 'label' => 'Orders', 'value' => $this->numericOrNull($summary['orders'] ?? $ota['orders'] ?? null), 'source_ref' => 'operation.full_data.summary.orders'],
                ['key' => 'room_nights', 'label' => 'Room nights', 'value' => $this->numericOrNull($summary['room_nights'] ?? null), 'source_ref' => 'operation.full_data.summary.room_nights'],
                ['key' => 'adr', 'label' => 'ADR', 'value' => $this->numericOrNull($summary['adr'] ?? null), 'source_ref' => 'operation.full_data.summary.adr'],
                ['key' => 'exposure', 'label' => 'Exposure', 'value' => $this->numericOrNull($ota['exposure'] ?? null), 'source_ref' => 'operation.full_data.ota.exposure'],
                ['key' => 'visitors', 'label' => 'Visitors', 'value' => $this->numericOrNull($ota['visitors'] ?? null), 'source_ref' => 'operation.full_data.ota.visitors'],
            ],
        ];
    }

    private function collectAbnormalMetrics(array $operation, array $rootCause): array
    {
        $items = [];
        foreach (($operation['abnormal_flags'] ?? []) as $flag) {
            $items[] = [
                'type' => 'abnormal_flag',
                'label' => (string)$flag,
                'level' => 'medium',
                'source_ref' => 'operation.full_data.abnormal_flags',
            ];
        }

        foreach (($rootCause['root_causes'] ?? []) as $cause) {
            if (!is_array($cause)) {
                continue;
            }
            $items[] = [
                'type' => (string)($cause['code'] ?? $cause['type'] ?? 'root_cause'),
                'label' => (string)($cause['title'] ?? ''),
                'level' => (string)($rootCause['problem_level'] ?? 'medium'),
                'evidence' => (string)($cause['evidence'] ?? ''),
                'suggestion' => (string)($cause['suggestion'] ?? ''),
                'source_ref' => 'operation.root_cause.root_causes',
            ];
        }

        return array_values(array_filter($items, static fn(array $item): bool => trim((string)$item['label']) !== ''));
    }

    private function collectCompetitorChanges(array $competitors): array
    {
        if (empty($competitors)) {
            return [];
        }

        $items = [];
        $meituan = is_array($competitors['meituan_rank_summary'] ?? null) ? $competitors['meituan_rank_summary'] : [];
        if (!empty($meituan)) {
            $items[] = [
                'label' => 'Meituan competitor summary',
                'top_hotel' => (string)($meituan['top_hotel_name'] ?? ''),
                'self_position' => (string)($meituan['self_position_text'] ?? ''),
                'gap_to_previous' => (string)($meituan['gap_to_previous_text'] ?? ''),
                'top1_gap' => (string)($meituan['top1_gap_text'] ?? ''),
                'vip_signal' => (string)($meituan['platform_tag_text'] ?? ''),
                'rank_trend' => (string)($meituan['rank_trend_text'] ?? ''),
                'rank_status' => (string)($meituan['rank_status'] ?? ''),
                'platform_tag_status' => (string)($meituan['platform_tag_status'] ?? ''),
                'latest_data_date' => (string)($meituan['latest_data_date'] ?? ''),
                'sample_count' => (int)($meituan['sample_count'] ?? 0),
                'data_status' => (string)($meituan['data_status'] ?? ''),
                'source_ref' => 'operation.full_data.competitors.meituan_rank_summary',
                'note' => (string)($meituan['rank_missing_reason'] ?? $meituan['privacy_scope'] ?? ''),
            ];
        }

        $items[] = [
            'label' => 'Competitor price/rank signal',
            'avg_price' => $this->numericOrNull($competitors['avg_price'] ?? null),
            'price_gap' => $this->numericOrNull($competitors['price_gap'] ?? null),
            'rank' => $competitors['rank_position'] ?? null,
            'data_status' => (string)($competitors['data_status'] ?? ''),
            'source_ref' => 'operation.full_data.competitors',
            'note' => 'Only authorized competitor aggregate data is used.',
        ];

        return $items;
    }

    private function collectDataGaps(array $operation, array $rootCause, array $executionFlow): array
    {
        $gaps = [];
        foreach (['summary', 'ota', 'competitors', 'service_quality'] as $module) {
            $data = is_array($operation[$module] ?? null) ? $operation[$module] : [];
            $status = (string)($data['data_status'] ?? '');
            if ($status !== '' && $status !== self::DATA_OK) {
                $gaps[] = [
                    'code' => $module . '_data_pending',
                    'message' => $module . ' data is missing or pending',
                    'source_ref' => 'operation.full_data.' . $module,
                ];
            }
        }

        foreach (($operation['abnormal_flags'] ?? []) as $flag) {
            if (str_contains((string)$flag, '数据') || str_contains((string)$flag, '采集')) {
                $gaps[] = [
                    'code' => 'collection_abnormal_flag',
                    'message' => (string)$flag,
                    'source_ref' => 'operation.full_data.abnormal_flags',
                ];
            }
        }

        if (($rootCause['problem_level'] ?? '') === 'data_insufficient') {
            $gaps[] = [
                'code' => 'root_cause_data_insufficient',
                'message' => (string)($rootCause['conclusion'] ?? 'root cause data is insufficient'),
                'source_ref' => 'operation.root_cause',
            ];
        }

        foreach (($executionFlow['data_gaps'] ?? []) as $gap) {
            if (!is_array($gap)) {
                continue;
            }
            $gaps[] = [
                'code' => (string)($gap['code'] ?? 'execution_flow_gap'),
                'message' => (string)($gap['message'] ?? 'execution flow data gap'),
                'source_ref' => 'operation.execution_flow.data_gaps',
            ];
        }

        return $this->uniqueByCodeAndMessage($gaps);
    }

    private function buildRecommendedActions(array $operation, array $rootCause, array $executionFlow, array $dataGaps): array
    {
        $actions = [];
        $rootCauses = is_array($rootCause['root_causes'] ?? null) ? $rootCause['root_causes'] : [];
        foreach ($rootCauses as $cause) {
            if (!is_array($cause)) {
                continue;
            }
            $code = (string)($cause['code'] ?? $cause['type'] ?? '');
            if (str_contains($code, 'price') || str_contains((string)($cause['title'] ?? ''), '价格')) {
                $actions[] = [
                    'title' => 'Review price competitiveness',
                    'action' => (string)($cause['suggestion'] ?? 'Review OTA price gap and decide whether to create a price adjustment order.'),
                    'reason' => (string)($cause['evidence'] ?? $cause['title'] ?? ''),
                    'source_refs' => ['operation.root_cause.root_causes', 'operation.full_data.competitors'],
                    'platform' => 'ota',
                    'object_type' => 'price',
                    'action_type' => 'price_adjust',
                    'expected_metric' => 'orders',
                    'expected_delta' => 0.0,
                    'risk_level' => 'medium',
                    'target_value' => ['target_metric' => 'orders'],
                    'can_create_execution_intent' => true,
                ];
                continue;
            }

            if (str_contains($code, 'conversion') || str_contains($code, 'traffic') || str_contains((string)($cause['title'] ?? ''), '曝光')) {
                $actions[] = [
                    'title' => 'Create conversion improvement task',
                    'action' => (string)($cause['suggestion'] ?? 'Check listing content, campaign entry and conversion blockers.'),
                    'reason' => (string)($cause['evidence'] ?? $cause['title'] ?? ''),
                    'source_refs' => ['operation.root_cause.root_causes', 'operation.full_data.ota'],
                    'platform' => 'ota',
                    'object_type' => 'campaign',
                    'action_type' => 'promotion',
                    'expected_metric' => 'conversion',
                    'expected_delta' => 0.0,
                    'risk_level' => 'medium',
                    'target_value' => ['campaign_type' => 'conversion_review', 'target_metric' => 'conversion'],
                    'can_create_execution_intent' => true,
                ];
            }
        }

        $summary = is_array($executionFlow['summary'] ?? null) ? $executionFlow['summary'] : [];
        if ((int)($summary['total'] ?? 0) > 0 && (string)($summary['money_status'] ?? '') === 'no_roi') {
            $actions[] = [
                'title' => 'Complete execution evidence and ROI review',
                'action' => 'For executed actions, add before/after evidence and trigger ROI review.',
                'reason' => 'Existing execution flow has actions but lacks ROI evidence.',
                'source_refs' => ['operation.execution_flow.summary'],
                'platform' => 'internal',
                'object_type' => 'campaign',
                'action_type' => 'evidence_review',
                'expected_metric' => 'roi',
                'expected_delta' => 0.0,
                'risk_level' => 'low',
                'target_value' => ['campaign_type' => 'evidence_review', 'target_metric' => 'roi'],
                'can_create_execution_intent' => true,
            ];
        }

        $competitors = is_array($operation['competitors'] ?? null) ? $operation['competitors'] : [];
        $meituanSummary = is_array($competitors['meituan_rank_summary'] ?? null) ? $competitors['meituan_rank_summary'] : [];
        $meituanAction = $this->buildMeituanCompetitorRecommendedAction($meituanSummary);
        if ($meituanAction !== null) {
            $actions[] = $meituanAction;
        }

        if (!empty($dataGaps)) {
            $actions[] = [
                'title' => 'Repair data gaps before business decision',
                'action' => 'Check OTA collection, account binding and metric mapping for the listed missing items.',
                'reason' => 'Daily report has explicit data gaps; decisions must not hide missing evidence.',
                'source_refs' => array_values(array_unique(array_column($dataGaps, 'source_ref'))),
                'platform' => 'internal',
                'object_type' => 'data_quality',
                'action_type' => 'data_repair',
                'expected_metric' => 'data_completeness',
                'expected_delta' => 0.0,
                'risk_level' => 'high',
                'target_value' => [],
                'can_create_execution_intent' => false,
                'blocked_reason' => 'Data repair is handled as configuration/checklist work, not an OTA execution order.',
            ];
        }

        $actions = $this->dedupeActions($actions);
        $fallbackIndex = 1;
        while (count($actions) < 3) {
            $actions[] = [
                'title' => 'Review daily operating signal ' . $fallbackIndex,
                'action' => 'Confirm whether revenue, orders, conversion and competitor signal need manual follow-up.',
                'reason' => 'No stronger abnormal signal was detected in available data.',
                'source_refs' => ['operation.full_data', 'operation.root_cause'],
                'platform' => 'internal',
                'object_type' => 'campaign',
                'action_type' => 'manual_review',
                'expected_metric' => 'orders',
                'expected_delta' => 0.0,
                'risk_level' => 'low',
                'target_value' => ['campaign_type' => 'manual_review', 'target_metric' => 'orders'],
                'can_create_execution_intent' => true,
            ];
            $fallbackIndex++;
        }

        return $actions;
    }

    private function buildMeituanCompetitorRecommendedAction(array $summary): ?array
    {
        if (empty($summary)) {
            return null;
        }

        $rankStatus = (string)($summary['rank_status'] ?? '');
        $tagStatus = (string)($summary['platform_tag_status'] ?? '');
        $trendStatus = (string)($summary['rank_trend_status'] ?? '');
        $topGap = (string)($summary['top1_gap_text'] ?? '');
        $hasTopGap = $topGap !== '' && $topGap !== '未返回' && $topGap !== '本店为TOP1';
        $needsEvidenceRepair = !in_array($rankStatus, ['ok'], true) || $tagStatus === 'not_returned';
        $needsBusinessReview = $trendStatus === 'down' || $hasTopGap || (int)($summary['vip_count'] ?? 0) > 0;
        if (!$needsEvidenceRepair && !$needsBusinessReview) {
            return null;
        }

        $reasonParts = array_filter([
            'TOP1=' . (string)($summary['top_hotel_name'] ?? '未返回'),
            'self=' . (string)($summary['self_position_text'] ?? '未返回'),
            'gap=' . (string)($summary['gap_to_previous_text'] ?? '未返回'),
            'VIP=' . (string)($summary['platform_tag_text'] ?? '未返回'),
            'trend=' . (string)($summary['rank_trend_text'] ?? '未返回'),
            (string)($summary['rank_missing_reason'] ?? ''),
        ], static fn(string $value): bool => trim($value) !== '');

        return [
            'title' => $needsEvidenceRepair ? 'Repair Meituan competitor evidence' : 'Review Meituan competitor gap',
            'action' => $needsEvidenceRepair
                ? 'Check Meituan POI binding, latest ranking capture and platform tag return status before using the competitor summary for decisions.'
                : 'Review TOP1, self position, gap, VIP/platform tags and rank trend, then decide whether price, conversion or content actions need a separate evidence-backed task.',
            'reason' => implode(' / ', $reasonParts),
            'source_refs' => ['operation.full_data.competitors.meituan_rank_summary'],
            'platform' => 'meituan',
            'object_type' => $needsEvidenceRepair ? 'data_quality' : 'campaign',
            'action_type' => $needsEvidenceRepair ? 'data_repair' : 'manual_review',
            'expected_metric' => $needsEvidenceRepair ? 'data_completeness' : 'orders',
            'expected_delta' => 0.0,
            'risk_level' => $needsEvidenceRepair ? 'high' : 'medium',
            'target_value' => $needsEvidenceRepair ? [] : ['campaign_type' => 'competitor_review', 'target_metric' => 'orders'],
            'can_create_execution_intent' => !$needsEvidenceRepair,
            'blocked_reason' => $needsEvidenceRepair ? 'Competitor evidence repair must be completed before creating an OTA execution order.' : '',
        ];
    }

    private function buildSummaryText(array $yesterdayResult, array $abnormalMetrics, array $dataGaps): string
    {
        $metrics = $yesterdayResult['metrics'] ?? [];
        $orders = $this->metricValue($metrics, 'orders');
        $revenue = $this->metricValue($metrics, 'revenue');
        $parts = [];
        if ($orders !== null) {
            $parts[] = 'orders=' . $orders;
        }
        if ($revenue !== null) {
            $parts[] = 'revenue=' . $revenue;
        }

        $summary = empty($parts) ? 'No complete yesterday operating result in available OTA/report data.' : ('Yesterday result: ' . implode(', ', $parts) . '.');
        if (!empty($abnormalMetrics)) {
            $summary .= ' Abnormal signals: ' . count($abnormalMetrics) . '.';
        }
        if (!empty($dataGaps)) {
            $summary .= ' Data gaps: ' . count($dataGaps) . '.';
        }

        return $summary;
    }

    private function defaultTargetValue(array $action): array
    {
        $objectType = (string)($action['object_type'] ?? '');
        if ($objectType === 'campaign') {
            return [
                'campaign_type' => (string)($action['action_type'] ?? 'manual_review'),
                'target_metric' => (string)($action['expected_metric'] ?? 'orders'),
            ];
        }

        if ($objectType === 'price') {
            return [
                'target_metric' => (string)($action['expected_metric'] ?? 'orders'),
            ];
        }

        return [];
    }

    private function compactSnapshotForLlm(array $snapshot): array
    {
        return [
            'scope' => $snapshot['scope'] ?? [],
            'summary' => $snapshot['operation']['summary'] ?? [],
            'ota' => $snapshot['operation']['ota'] ?? [],
            'competitors' => $snapshot['operation']['competitors'] ?? [],
            'abnormal_flags' => $snapshot['operation']['abnormal_flags'] ?? [],
            'root_cause' => $snapshot['root_cause'] ?? [],
            'execution_summary' => $snapshot['execution_flow']['summary'] ?? [],
            'execution_data_gaps' => $snapshot['execution_flow']['data_gaps'] ?? [],
        ];
    }

    private function resolveSingleHotelId(array $hotelIds, ?int $hotelId): int
    {
        $hotelIds = array_values(array_map('intval', $hotelIds));
        if ($hotelId !== null && $hotelId > 0) {
            if (!in_array($hotelId, $hotelIds, true)) {
                throw new \InvalidArgumentException('hotel_id is not permitted');
            }
            return $hotelId;
        }

        if (count($hotelIds) === 1) {
            return $hotelIds[0];
        }

        throw new \InvalidArgumentException('hotel_id is required for AI daily report generation');
    }

    private function normalizeReportRow(array $row): array
    {
        foreach (['id', 'hotel_id', 'created_by'] as $field) {
            $row[$field] = (int)($row[$field] ?? 0);
        }
        foreach ([
            'yesterday_result',
            'abnormal_metrics',
            'competitor_changes',
            'data_gaps',
            'recommended_actions',
            'source_refs',
            'snapshot',
        ] as $field) {
            $row[$field] = $this->decodeJson((string)($row[$field . '_json'] ?? ''));
            unset($row[$field . '_json']);
        }

        return $row;
    }

    private function applyHotelScope($query, array $hotelIds, ?int $hotelId): void
    {
        if ($hotelId !== null && $hotelId > 0) {
            $query->where('hotel_id', $hotelId);
            return;
        }
        if (!empty($hotelIds)) {
            $query->whereIn('hotel_id', array_values(array_map('intval', $hotelIds)));
        }
    }

    private function normalizeDate(string $date): string
    {
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            throw new \InvalidArgumentException('date is invalid');
        }

        return date('Y-m-d', $timestamp);
    }

    private function numericOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return is_numeric($value) ? (float)$value : null;
    }

    private function metricValue(array $metrics, string $key): ?float
    {
        foreach ($metrics as $metric) {
            if (is_array($metric) && ($metric['key'] ?? '') === $key) {
                return $this->numericOrNull($metric['value'] ?? null);
            }
        }

        return null;
    }

    private function uniqueByCodeAndMessage(array $items): array
    {
        $seen = [];
        $result = [];
        foreach ($items as $item) {
            $key = (string)($item['code'] ?? '') . '|' . (string)($item['message'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $item;
        }

        return $result;
    }

    private function dedupeActions(array $actions): array
    {
        $seen = [];
        $result = [];
        foreach ($actions as $action) {
            $key = (string)($action['title'] ?? '') . '|' . (string)($action['action_type'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $action;
        }

        return $result;
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function decodeJson(string $value): array
    {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
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

    private function withTenantId(array $data, string $table, int $tenantId): array
    {
        if ($this->tableHasColumn($table, 'tenant_id')) {
            $data['tenant_id'] = $tenantId > 0 ? $tenantId : null;
        }

        return $data;
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $rows = Db::query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
            $columns = array_fill_keys(array_map(static fn(array $row): string => (string)$row['Field'], $rows), true);
            return $cache[$key] = isset($columns[$column]);
        } catch (Throwable $e) {
            return $cache[$key] = false;
        }
    }
}
