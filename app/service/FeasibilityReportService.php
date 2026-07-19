<?php
declare(strict_types=1);

namespace app\service;

use app\model\FeasibilityReport;
use think\facade\Db;

class FeasibilityReportService
{
    private LlmClient $client;
    private AiDecisionQualityService $decisionQualityService;

    public function __construct(?LlmClient $client = null, ?AiDecisionQualityService $decisionQualityService = null)
    {
        $this->client = $client ?: new LlmClient();
        $this->decisionQualityService = $decisionQualityService ?? new AiDecisionQualityService();
    }

    public function generate(array $input, int $userId): array
    {
        $this->ensureTable();
        $input = $this->normalizeInput($input);
        $tenantId = $this->tenantIdForUser($userId);
        $snapshot = $this->buildSnapshot($input, $tenantId);
        $calculation = $this->calculate($input, $snapshot);
        $report = $this->buildAiReport($input, $snapshot, $calculation);
        $report = $this->mergeFinancials($report, $input, $calculation);
        $report = $this->enrichDecisionRecommendations($report, $input, $snapshot);

        $record = FeasibilityReport::create([
            'tenant_id' => $tenantId,
            'project_name' => $input['project_name'],
            'input_json' => $input,
            'snapshot_json' => $snapshot,
            'report_json' => $report,
            'conclusion_grade' => $report['conclusion_grade'] ?? null,
            'payback_months' => $report['summary']['payback_months'] ?? null,
            'total_investment' => $report['summary']['total_investment'] ?? null,
            'created_by' => $userId,
        ]);

        return $this->formatRecord($record);
    }

    public function regenerate(int $id, int $userId, bool $isSuperAdmin, array $updatedInput = []): ?array
    {
        $this->ensureTable();
        $query = FeasibilityReport::where('id', $id)->whereNull('deleted_at');
        $this->applyTenantScope($query, $userId, $isSuperAdmin);
        if (!$isSuperAdmin) {
            $query->where('created_by', $userId);
        }

        $old = $query->find();
        if (!$old) {
            return null;
        }

        $storedInput = (array)$old->input_json;
        $nextInput = $updatedInput === [] ? $storedInput : array_merge($storedInput, $updatedInput);

        return $this->generate($nextInput, $userId);
    }

    public function detail(int $id, int $userId, bool $isSuperAdmin): ?array
    {
        $this->ensureTable();
        $query = FeasibilityReport::where('id', $id)->whereNull('deleted_at');
        $this->applyTenantScope($query, $userId, $isSuperAdmin);
        if (!$isSuperAdmin) {
            $query->where('created_by', $userId);
        }

        $record = $query->find();
        return $record ? $this->formatRecord($record) : null;
    }

    public function list(int $page = 1, int $pageSize = 10, int $userId = 0, bool $isSuperAdmin = false): array
    {
        $this->ensureTable();
        $query = FeasibilityReport::whereNull('deleted_at')->order('id', 'desc');
        $this->applyTenantScope($query, $userId, $isSuperAdmin);
        if (!$isSuperAdmin) {
            $query->where('created_by', $userId);
        }

        $total = (clone $query)->count();
        $list = $query->page($page, $pageSize)->select()->toArray();

        return [
            'list' => array_map(fn ($row) => $this->formatArrayRecord($row, false), $list),
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
                'total_page' => (int) ceil($total / max(1, $pageSize)),
            ],
        ];
    }

    public function archive(int $id, int $userId, bool $isSuperAdmin): bool
    {
        $this->ensureTable();

        $query = FeasibilityReport::where('id', $id)->whereNull('deleted_at');
        $this->applyTenantScope($query, $userId, $isSuperAdmin);
        if (!$isSuperAdmin) {
            $query->where('created_by', $userId);
        }

        return $query->update([
            'deleted_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]) > 0;
    }

    public function buildExecutionIntentInput(array $record, int $hotelId, array $overrides = []): array
    {
        if ($hotelId <= 0) {
            throw new \InvalidArgumentException('hotel_id is required for feasibility execution tracking');
        }

        $input = $this->decodeJson($record['input'] ?? $record['input_json'] ?? []);
        $snapshot = $this->decodeJson($record['snapshot'] ?? $record['snapshot_json'] ?? []);
        $report = $this->decodeJson($record['report'] ?? $record['report_json'] ?? []);
        $report = $this->normalizeReportFinancialScenarios($report, $input);
        $readiness = is_array($record['feasibility_readiness'] ?? null)
            ? $record['feasibility_readiness']
            : $this->buildFeasibilityReadiness($input, $snapshot, $report);
        if (($readiness['decision_ready'] ?? false) !== true) {
            throw new \InvalidArgumentException('核心投资输入未齐全，待评估报告不能转投后跟踪');
        }
        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        $projectName = trim((string)($record['project_name'] ?? $summary['project_name'] ?? $input['project_name'] ?? ''));
        $date = date('Y-m-d');
        $dateStart = trim((string)($overrides['date_start'] ?? '')) ?: $date;
        $dateEnd = trim((string)($overrides['date_end'] ?? '')) ?: $dateStart;

        return [
            'source_module' => 'feasibility_report',
            'source_record_id' => (int)($record['id'] ?? 0),
            'hotel_id' => $hotelId,
            'platform' => 'investment',
            'object_type' => 'investment',
            'action_type' => 'post_decision_tracking',
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
            'current_value' => [
                'project_name' => $projectName,
                'conclusion_grade' => (string)($record['conclusion_grade'] ?? $report['conclusion_grade'] ?? ''),
                'payback_months' => $record['payback_months'] ?? $summary['payback_months'] ?? null,
                'total_investment' => (float)($record['total_investment'] ?? $summary['total_investment'] ?? 0),
                'readiness_stage' => (string)($readiness['stage'] ?? ''),
            ],
            'target_value' => [
                'project_name' => $projectName,
                'tracking_status' => 'pending_post_decision_tracking',
                'target_metric' => 'investment_decision_closure',
                'decision_stage' => (string)($readiness['stage'] ?? ''),
                'next_action' => (string)($readiness['next_action'] ?? ''),
            ],
            'evidence' => [
                'readiness_stage' => (string)($readiness['stage'] ?? ''),
                'readiness_score' => (int)($readiness['score'] ?? 0),
                'source_scope' => (string)($readiness['source_scope'] ?? ''),
                'missing_evidence' => array_values((array)($readiness['missing_evidence'] ?? [])),
                'conclusion_text' => (string)($report['conclusion_text'] ?? ''),
                'core_reason' => (string)($report['core_reason'] ?? ''),
                'financial_summary' => $summary,
                'scope_notice' => 'Feasibility evidence is investment decision scope; OTA evidence remains channel-scope unless explicitly backed by whole-hotel data.',
            ],
            'expected_metric' => 'investment_decision_closure',
            'expected_delta' => 0,
            'risk_level' => $this->executionRiskLevel($report),
            'status' => 'pending_approval',
        ];
    }

    public function attachExecutionTracking(int $id, int $userId, bool $isSuperAdmin, array $tracking): ?array
    {
        $this->ensureTable();
        $intentId = (int)($tracking['execution_intent_id'] ?? $tracking['id'] ?? 0);
        if ($intentId <= 0) {
            throw new \InvalidArgumentException('execution_intent_id is required');
        }

        $query = FeasibilityReport::where('id', $id)->whereNull('deleted_at');
        $this->applyTenantScope($query, $userId, $isSuperAdmin);
        if (!$isSuperAdmin) {
            $query->where('created_by', $userId);
        }

        $record = $query->find();
        if (!$record) {
            return null;
        }

        $input = $this->decodeJson($record->input_json ?? []);
        $report = $this->normalizeReportFinancialScenarios(
            $this->decodeJson($record->report_json ?? []),
            $input
        );
        $now = date('Y-m-d H:i:s');
        $trackingPayload = [
            'type' => 'operation_execution_intent',
            'execution_intent_id' => $intentId,
            'hotel_id' => (int)($tracking['hotel_id'] ?? 0),
            'status' => trim((string)($tracking['status'] ?? '')),
            'source_module' => 'feasibility_report',
            'linked_at' => $now,
        ];

        $existing = $report['execution_tracking'] ?? [];
        if (!is_array($existing)) {
            $existing = [];
        }
        if ($existing !== [] && array_keys($existing) !== range(0, count($existing) - 1)) {
            $existing = [$existing];
        }
        $existing[] = $trackingPayload;

        $report['execution_tracking'] = $existing;
        $report['execution_intent_id'] = $intentId;
        $report['post_decision_tracking'] = [
            'status' => 'linked',
            'latest_execution_intent_id' => $intentId,
            'latest_status' => $trackingPayload['status'],
            'hotel_id' => $trackingPayload['hotel_id'],
            'linked_at' => $now,
        ];

        $record->save(['report_json' => $report, 'updated_at' => $now]);
        $record->report_json = $report;
        $record->updated_at = $now;

        return $this->formatRecord($record);
    }

    public function buildFeasibilityReadiness(array $input, array $snapshot, array $report): array
    {
        $report = $this->normalizeReportFinancialScenarios($report, $input);
        $inputDataGaps = $this->feasibilityInputDataGaps($input);
        $reportDataGaps = array_values(array_filter(
            (array)($report['data_gaps'] ?? []),
            static fn ($gap): bool => is_string($gap) && trim($gap) !== ''
        ));
        $dataGaps = array_values(array_unique(array_merge($inputDataGaps, $reportDataGaps)));
        $decisionReady = $dataGaps === [] && (($report['decision_ready'] ?? true) === true);
        $reportReady = $decisionReady
            && trim((string)($report['conclusion_grade'] ?? '')) !== ''
            && trim((string)($report['conclusion_text'] ?? '')) !== '';
        $scenarioReady = $decisionReady && $this->financialScenariosReady($report['financial_scenarios'] ?? null);
        $financialReady = $this->feasibilityFinancialInputsReady($input, $snapshot, $report);
        $sourceBacked = $this->feasibilitySourceEvidenceReady($input, $snapshot, $report);
        $riskClear = $this->feasibilityRiskClear($report);
        $diligenceReady = $this->hasNamedEvidence([$input, $snapshot, $report], [
            'diligence_evidence',
            'due_diligence',
            'legal_review',
            'lease_review',
            'contract_review',
            'license_evidence',
            'site_visit_evidence',
            'attachment_urls',
            'evidence_documents',
        ]);
        $humanReviewReady = $this->hasHumanReviewApproval([$input, $snapshot, $report]);
        $trackingReady = $this->hasPostDecisionTracking([$input, $snapshot, $report]);

        $checks = [
            $this->readinessCheck('report_result', '可研报告结果', $reportReady, '已形成结论等级、结论文本和核心理由', '先生成可行性报告，不能只保留项目输入。', 18),
            $this->readinessCheck('scenario_model', '三情景测算', $scenarioReady, '已形成保守、基准、乐观三类现金流情景', '补齐三情景测算，避免单点结论直接进入投决。', 14),
            $this->readinessCheck('financial_assumptions', '财务假设完整', $financialReady, '面积、房量、租金、租期、投资、ADR/OCC 等关键假设已填充或有来源快照', '补齐面积、房量、租金、租期、投资预算、ADR 和 OCC 来源。', 14),
            $this->readinessCheck('source_evidence', '真实样本证据', $sourceBacked, $this->feasibilitySourceEvidenceText($snapshot, $report), '补齐经营日报、竞品、OTA、租约或外部调研证据；当前仅能视为模型初稿。', 18),
            $this->readinessCheck('risk_recheck', '风险复核', $riskClear, '结论等级和现金流风险未触发显式阻断', '先复核 C/D 等级、高风险、不可回本或负现金流问题。', 12),
            $this->readinessCheck('diligence_evidence', '尽调证据', $diligenceReady, '已记录租约、证照、现场、附件或法务尽调证据', '补齐租约、证照、现场踏勘、法务或附件证据。', 10),
            $this->readinessCheck('manual_review', '人工投决复核', $humanReviewReady, '已记录人工复核或审批状态', '补一条人工复核结论，明确通过、暂缓、重谈或放弃。', 8),
            $this->readinessCheck('post_decision_tracking', '投后跟踪', $trackingReady, '已关联执行、开业或投后跟踪记录', '关联运营执行、开业项目或投后跟踪记录，避免可研后断链。', 6),
        ];

        $missingEvidence = [];
        $score = 0;
        foreach ($checks as $check) {
            if ($check['passed']) {
                $score += (int)$check['weight'];
                continue;
            }
            $missingEvidence[] = [
                'code' => $check['key'],
                'label' => $check['label'],
                'next_action' => $check['next_action'],
            ];
        }

        $stage = $decisionReady
            ? $this->feasibilityReadinessStage(
                $reportReady,
                $scenarioReady,
                $financialReady,
                $sourceBacked,
                $riskClear,
                $diligenceReady,
                $humanReviewReady,
                $trackingReady
            )
            : 'input_pending';

        return [
            'stage' => $stage,
            'status_label' => $this->feasibilityReadinessStageLabel($stage),
            'score' => $score,
            'decision_ready' => $decisionReady,
            'data_gaps' => $dataGaps,
            'evaluation_status' => $decisionReady ? '可评估' : '待评估',
            'ready_for_review' => in_array($stage, ['review_ready', 'approved_pending_tracking', 'feasibility_ready'], true),
            'feasibility_ready' => $stage === 'feasibility_ready',
            'source_scope' => $this->feasibilitySourceScope($snapshot),
            'checks' => $checks,
            'missing_evidence' => $missingEvidence,
            'next_action' => !$decisionReady
                ? '补齐预期 ADR、预期 OCC、开办费及其他核心投资输入后重新评估。'
                : ($missingEvidence[0]['next_action'] ?? '进入人工投决复核，并保留审批、执行和投后跟踪证据。'),
            'notice' => $this->feasibilityReadinessNotice($stage),
        ];
    }

    public function readinessSummaryFromRows(array $rows): array
    {
        $summary = [
            'record_count' => 0,
            'stage_counts' => [],
            'review_ready_count' => 0,
            'feasibility_ready_count' => 0,
            'best_score' => 0,
            'best_stage' => '',
            'best_status_label' => '',
            'missing_evidence' => [],
        ];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $readiness = $this->buildFeasibilityReadiness(
                $this->decodeJson($row['input_json'] ?? []),
                $this->decodeJson($row['snapshot_json'] ?? []),
                $this->decodeJson($row['report_json'] ?? [])
            );
            $summary['record_count']++;
            $stage = (string)$readiness['stage'];
            $summary['stage_counts'][$stage] = (int)($summary['stage_counts'][$stage] ?? 0) + 1;
            if (($readiness['ready_for_review'] ?? false) === true) {
                $summary['review_ready_count']++;
            }
            if (($readiness['feasibility_ready'] ?? false) === true) {
                $summary['feasibility_ready_count']++;
            }
            if ((int)$readiness['score'] >= (int)$summary['best_score']) {
                $summary['best_score'] = (int)$readiness['score'];
                $summary['best_stage'] = $stage;
                $summary['best_status_label'] = (string)$readiness['status_label'];
                $summary['missing_evidence'] = array_slice((array)$readiness['missing_evidence'], 0, 4);
            }
        }

        return $summary;
    }

    public function ensureTable(): void
    {
        Db::execute("
            CREATE TABLE IF NOT EXISTS feasibility_reports (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tenant_id INT UNSIGNED DEFAULT NULL,
                project_name VARCHAR(120) NOT NULL,
                input_json JSON NULL,
                snapshot_json JSON NULL,
                report_json JSON NULL,
                conclusion_grade VARCHAR(8),
                payback_months DECIMAL(10,2) NULL,
                total_investment DECIMAL(14,2) DEFAULT 0,
                created_by INT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                deleted_at DATETIME NULL,
                INDEX idx_feasibility_reports_tenant_user (tenant_id, created_by, id),
                INDEX idx_created_by (created_by),
                INDEX idx_grade (conclusion_grade)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $this->ensureTenantColumns();
    }

    private function ensureTenantColumns(): void
    {
        Db::execute("ALTER TABLE feasibility_reports ADD COLUMN IF NOT EXISTS tenant_id INT UNSIGNED DEFAULT NULL COMMENT 'tenant id, default follows creator user' AFTER id");
        Db::execute("ALTER TABLE feasibility_reports ADD INDEX IF NOT EXISTS idx_feasibility_reports_tenant_user (tenant_id, created_by, id)");
    }

    private function applyTenantScope($query, int $userId, bool $isSuperAdmin): void
    {
        if ($isSuperAdmin) {
            return;
        }

        $tenantId = $this->tenantIdForUser($userId);
        if ($tenantId === null) {
            $query->where('tenant_id', -1);
            return;
        }

        $query->where('tenant_id', $tenantId);
    }

    private function tenantIdForUser(int $userId): ?int
    {
        if ($userId <= 0) {
            return null;
        }

        try {
            $row = Db::name('users')->where('id', $userId)->field('tenant_id,hotel_id')->find();
            if (!$row) {
                return null;
            }

            $tenantId = (int)($row['tenant_id'] ?? 0);
            if ($tenantId > 0) {
                return $tenantId;
            }

            $hotelId = (int)($row['hotel_id'] ?? 0);
            return $hotelId > 0 ? $hotelId : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function normalizeInput(array $input): array
    {
        $fields = [
            'project_name', 'city', 'district', 'address', 'target_brand_level',
            'target_customer', 'notes', 'model_key',
        ];
        $result = [];
        foreach ($fields as $field) {
            $result[$field] = trim((string) ($input[$field] ?? ''));
        }
        $result['hotel_id'] = $this->normalizeTargetHotelId($input);

        $numberFields = [
            'property_area', 'room_count', 'monthly_rent', 'lease_years',
            'decoration_budget', 'transfer_fee', 'opening_cost', 'adr', 'occ',
        ];
        foreach ($numberFields as $field) {
            $result[$field] = $this->nullableNumber($input[$field] ?? null, $field);
        }

        if ($result['project_name'] === '') {
            throw new \InvalidArgumentException('项目名称不能为空');
        }
        if ($result['room_count'] <= 0) {
            throw new \InvalidArgumentException('房间数必须大于 0');
        }
        if ($result['property_area'] <= 0) {
            throw new \InvalidArgumentException('物业面积必须大于 0');
        }
        foreach (['monthly_rent', 'decoration_budget', 'transfer_fee', 'opening_cost'] as $field) {
            if ($result[$field] !== null && $result[$field] < 0) {
                throw new \InvalidArgumentException($field . ' 不能为负数');
            }
        }
        if ($result['occ'] !== null && $result['occ'] > 100) {
            throw new \InvalidArgumentException('预期 OCC 不能大于 100%');
        }

        return $result;
    }

    private function normalizeTargetHotelId(array $input): ?int
    {
        $hotelId = $this->nullablePositiveInteger($input['hotel_id'] ?? null, 'hotel_id');
        $systemHotelId = $this->nullablePositiveInteger($input['system_hotel_id'] ?? null, 'system_hotel_id');
        if ($hotelId !== null && $systemHotelId !== null && $hotelId !== $systemHotelId) {
            throw new \InvalidArgumentException('hotel_id and system_hotel_id must identify the same hotel');
        }

        return $hotelId ?? $systemHotelId;
    }

    private function nullablePositiveInteger(mixed $value, string $field): ?int
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        $normalized = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($normalized === false) {
            throw new \InvalidArgumentException($field . ' must be a positive integer');
        }

        return (int)$normalized;
    }

    private function nullableNumber(mixed $value, string $field): ?float
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException($field . ' 必须是数字');
        }

        return round((float)$value, 2);
    }

    private function numericOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float)$value : null;
    }

    private function normalizeOccupancy(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }
        $occ = (float)$value;
        if ($occ > 1) {
            $occ /= 100;
        }

        return $occ > 0 && $occ <= 1 ? $occ : null;
    }

    private function feasibilityInputDataGaps(array $input): array
    {
        $gaps = [];
        foreach (['property_area', 'room_count', 'lease_years'] as $field) {
            if (!$this->hasPositiveReadinessValue($input[$field] ?? null)) {
                $gaps[] = $field . '_missing_or_invalid';
            }
        }
        foreach (['monthly_rent', 'decoration_budget', 'transfer_fee', 'opening_cost'] as $field) {
            $value = $this->numericOrNull($input[$field] ?? null);
            if ($value === null) {
                $gaps[] = $field . '_missing';
            } elseif ($value < 0) {
                $gaps[] = $field . '_must_be_non_negative';
            }
        }
        $investmentParts = array_map(
            fn (string $field): ?float => $this->numericOrNull($input[$field] ?? null),
            ['decoration_budget', 'transfer_fee', 'opening_cost']
        );
        if (!in_array(null, $investmentParts, true) && array_sum($investmentParts) <= 0) {
            $gaps[] = 'total_investment_must_be_positive';
        }
        if (!$this->hasPositiveReadinessValue($input['adr'] ?? null)) {
            $gaps[] = 'expected_adr_missing_or_invalid';
        }
        if ($this->normalizeOccupancy($input['occ'] ?? null) === null) {
            $gaps[] = 'expected_occ_missing_or_invalid';
        }

        return array_values(array_unique($gaps));
    }

    private function feasibilityDataGapLabels(array $gaps): array
    {
        $labels = [
            'property_area_missing_or_invalid' => '物业面积',
            'room_count_missing_or_invalid' => '房间数',
            'monthly_rent_missing' => '月租金（无租金请显式填 0）',
            'monthly_rent_must_be_non_negative' => '有效月租金',
            'lease_years_missing_or_invalid' => '有效租期',
            'decoration_budget_missing' => '装修预算（无预算请显式填 0）',
            'decoration_budget_must_be_non_negative' => '有效装修预算',
            'transfer_fee_missing' => '转让费（无转让费请显式填 0）',
            'transfer_fee_must_be_non_negative' => '有效转让费',
            'opening_cost_missing' => '预期开办费（无费用请显式填 0）',
            'opening_cost_must_be_non_negative' => '有效预期开办费',
            'expected_adr_missing_or_invalid' => '大于 0 的预期 ADR',
            'expected_occ_missing_or_invalid' => '0%–100% 之间的预期 OCC',
            'total_investment_must_be_positive' => '大于 0 的总投资',
            'rent_ratio_denominator_missing' => '租金占比缺少月收入分母',
            'rent_ratio_denominator_non_positive' => '租金占比的月收入分母必须大于 0',
            'rent_ratio_missing_or_invalid' => '租金占比缺失或无效',
            'rent_ratio_zero_without_explicit_zero_rent' => '租金未明确为 0，不能把租金占比回显为 0',
        ];

        return array_map(
            static fn (string $gap): string => $labels[$gap] ?? $gap,
            array_values(array_filter($gaps, 'is_string'))
        );
    }

    private function financialScenariosReady(mixed $scenarios): bool
    {
        if (!is_array($scenarios) || count($scenarios) < 3) {
            return false;
        }
        foreach (array_slice($scenarios, 0, 3) as $scenario) {
            if (!is_array($scenario)) {
                return false;
            }
            foreach (['adr', 'occ', 'monthly_revenue', 'monthly_operating_cost', 'monthly_net_cashflow'] as $field) {
                if (!is_numeric($scenario[$field] ?? null)) {
                    return false;
                }
            }
            if ((float)$scenario['monthly_revenue'] <= 0 || !is_numeric($scenario['rent_ratio'] ?? null)) {
                return false;
            }
            if (($scenario['calculation_status'] ?? 'rule_scenario_ready') !== 'rule_scenario_ready'
                || trim((string)($scenario['risk_level'] ?? '')) === '待评估'
            ) {
                return false;
            }
        }

        return true;
    }

    private function normalizeReportFinancialScenarios(array $report, array $input = []): array
    {
        if (!array_key_exists('financial_scenarios', $report)) {
            return $report;
        }

        $report['financial_scenarios'] = $this->normalizeFinancialScenarios(
            $report['financial_scenarios'],
            $input
        );
        $scenarioDataGaps = $this->financialScenarioDataGaps($report['financial_scenarios']);
        if ($scenarioDataGaps !== []) {
            $report['data_gaps'] = array_values(array_unique(array_merge(
                array_values(array_filter(
                    (array)($report['data_gaps'] ?? []),
                    static fn ($gap): bool => is_string($gap) && trim($gap) !== ''
                )),
                $scenarioDataGaps
            )));
            $report['decision_ready'] = false;
        }

        return $report;
    }

    private function normalizeFinancialScenarios(mixed $scenarios, array $input = []): array
    {
        if (!is_array($scenarios)) {
            return [];
        }

        $ratioGapCodes = [
            'rent_ratio_denominator_missing',
            'rent_ratio_denominator_non_positive',
            'rent_ratio_missing_or_invalid',
            'rent_ratio_zero_without_explicit_zero_rent',
        ];
        $monthlyRent = $this->numericOrNull($input['monthly_rent'] ?? null);
        $hasExplicitMonthlyRent = array_key_exists('monthly_rent', $input) && $monthlyRent !== null;
        $normalized = [];

        foreach ($scenarios as $scenario) {
            if (!is_array($scenario)) {
                $normalized[] = $scenario;
                continue;
            }

            $dataGaps = array_values(array_filter(
                (array)($scenario['data_gaps'] ?? []),
                static fn ($gap): bool => is_string($gap)
                    && trim($gap) !== ''
                    && !in_array($gap, $ratioGapCodes, true)
            ));
            $monthlyRevenue = $this->numericOrNull($scenario['monthly_revenue'] ?? null);
            $rentRatio = array_key_exists('rent_ratio', $scenario)
                ? $this->numericOrNull($scenario['rent_ratio'])
                : null;
            $calculationStatus = trim((string)($scenario['calculation_status'] ?? ''));
            $rentRatioStatus = 'calculated';
            $rentRatioGap = null;

            if ($monthlyRevenue === null) {
                $rentRatio = null;
                $rentRatioStatus = $calculationStatus === 'pending_input'
                    ? 'pending_input'
                    : 'unavailable_missing_revenue';
                $rentRatioGap = 'rent_ratio_denominator_missing';
            } elseif ($monthlyRevenue <= 0) {
                $rentRatio = null;
                $rentRatioStatus = 'unavailable_non_positive_revenue';
                $rentRatioGap = 'rent_ratio_denominator_non_positive';
            } elseif ($rentRatio === null || $rentRatio < 0) {
                $rentRatio = null;
                $rentRatioStatus = 'unverified_missing_ratio';
                $rentRatioGap = 'rent_ratio_missing_or_invalid';
            } elseif ($rentRatio == 0.0 && (!$hasExplicitMonthlyRent || $monthlyRent !== 0.0)) {
                $rentRatio = null;
                $rentRatioStatus = 'unverified_zero_ratio';
                $rentRatioGap = 'rent_ratio_zero_without_explicit_zero_rent';
            }

            if ($rentRatioGap !== null) {
                $dataGaps[] = $rentRatioGap;
                if ($calculationStatus !== 'pending_input') {
                    $scenario['calculation_status'] = 'rule_scenario_partial';
                    $scenario['risk_level'] = $monthlyRevenue !== null && $monthlyRevenue <= 0
                        ? '高'
                        : '待核验';
                }
            } else {
                $rentRatio = round((float)$rentRatio, 4);
            }

            $scenario['rent_ratio'] = $rentRatio;
            $scenario['rent_ratio_status'] = $rentRatioStatus;
            $scenario['data_gaps'] = array_values(array_unique($dataGaps));
            $normalized[] = $scenario;
        }

        return $normalized;
    }

    private function financialScenarioDataGaps(array $scenarios): array
    {
        $dataGaps = [];
        foreach ($scenarios as $scenario) {
            if (!is_array($scenario)) {
                continue;
            }
            foreach ((array)($scenario['data_gaps'] ?? []) as $gap) {
                if (is_string($gap) && trim($gap) !== '') {
                    $dataGaps[] = $gap;
                }
            }
        }

        return array_values(array_unique($dataGaps));
    }

    private function buildSnapshot(array $input, ?int $tenantId = null): array
    {
        $targetHotelId = max(0, (int)($input['hotel_id'] ?? 0));
        $daily = [];
        $online = [];
        $competitors = [];
        $prices = [];
        if ($targetHotelId > 0) {
            $daily = $this->safeRows('daily_reports', fn () => $this->buildHotelSnapshotQuery('daily_reports', $tenantId, $targetHotelId, 'hotel_id')->order('report_date', 'desc')->limit(30)->select()->toArray());
            $online = $this->safeRows('online_daily_data', fn () => $this->buildHotelSnapshotQuery('online_daily_data', $tenantId, $targetHotelId, 'system_hotel_id')->order('id', 'desc')->limit(30)->select()->toArray());
            $competitors = $this->safeRows('competitor_hotel', fn () => $this->buildHotelSnapshotQuery('competitor_hotel', $tenantId, $targetHotelId, 'store_id')->where('status', 1)->limit(20)->select()->toArray());
            $prices = $this->safeRows('competitor_price_log', fn () => $this->buildHotelSnapshotQuery('competitor_price_log', $tenantId, $targetHotelId, 'store_id')->order('id', 'desc')->limit(50)->select()->toArray());
        }

        $daily = $this->filterSnapshotRowsForHotel($daily, 'hotel_id', $targetHotelId);
        $online = $this->filterSnapshotRowsForHotel($online, 'system_hotel_id', $targetHotelId);
        $competitors = $this->filterSnapshotRowsForHotel($competitors, 'store_id', $targetHotelId);
        $prices = $this->filterSnapshotRowsForHotel($prices, 'store_id', $targetHotelId);
        $onlineSummary = $this->summarizeOnlineData($online, $targetHotelId);
        $competitorSummary = $this->summarizeCompetitors($competitors, $prices);
        $scopeGaps = [];
        if ($tenantId === null || $tenantId <= 0) {
            $scopeGaps[] = 'tenant_scope_missing';
        }
        if ($targetHotelId <= 0) {
            $scopeGaps[] = 'target_hotel_missing';
        }

        return [
            'snapshot_scope' => [
                'tenant_id' => $tenantId,
                'hotel_id' => $targetHotelId > 0 ? $targetHotelId : null,
                'status' => $scopeGaps === [] ? 'hotel_scoped' : 'unverified',
                'failure_reason' => implode('; ', $scopeGaps),
            ],
            'daily_summary' => $this->summarizeDailyReports($daily, $targetHotelId),
            'online_summary' => $onlineSummary,
            'competitor_summary' => $competitorSummary,
            'source_counts' => [
                'daily_reports' => count($daily),
                'online_daily_data' => (int)($onlineSummary['trusted_sample_count'] ?? 0),
                'competitor_hotels' => count($competitors),
                'competitor_price_logs' => count($prices),
                'competitor_price_logs_decision_eligible' => (int)($competitorSummary['decision_eligible_price_count'] ?? 0),
                'competitor_price_logs_reference_only' => (int)($competitorSummary['reference_only_price_count'] ?? 0),
            ],
            'data_gaps' => $scopeGaps,
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function summarizeDailyReports(array $rows, int $targetHotelId = 0): array
    {
        $adr = [];
        $occ = [];
        $revenue = [];
        $dates = [];
        $collectedAt = [];
        $rowIds = [];
        foreach ($rows as $row) {
            $rowId = (int)($row['id'] ?? 0);
            if ($rowId > 0) {
                $rowIds[] = $rowId;
            }
            $reportDate = trim((string)($row['report_date'] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $reportDate) === 1) {
                $dates[] = $reportDate;
            }
            $createdAt = trim((string)($row['created_at'] ?? $row['updated_at'] ?? ''));
            if ($createdAt !== '') {
                $collectedAt[] = $createdAt;
            }
            $data = is_array($row['report_data'] ?? null) ? $row['report_data'] : json_decode((string) ($row['report_data'] ?? ''), true);
            $data = is_array($data) ? $data : [];
            foreach (['adr', 'avg_room_price', 'day_adr'] as $key) {
                $value = array_key_exists($key, $data) ? $this->numericOrNull($data[$key]) : null;
                if ($value !== null) {
                    $adr[] = $value;
                    break;
                }
            }
            foreach (['occ', 'occupancy_rate', 'day_occ_rate'] as $key) {
                $value = array_key_exists($key, $data) ? $this->numericOrNull($data[$key]) : null;
                if ($value !== null) {
                    $occ[] = $value > 1 ? $value / 100 : $value;
                    break;
                }
            }
            foreach (['day_revenue', 'revenue', 'room_revenue'] as $key) {
                $value = array_key_exists($key, $data) ? $this->numericOrNull($data[$key]) : null;
                if ($value !== null) {
                    $revenue[] = $value;
                    break;
                }
            }
        }

        sort($dates);
        sort($collectedAt);
        $metrics = [
            'avg_adr' => $this->avg($adr),
            'avg_occ' => $this->avg($occ),
            'avg_revenue' => $this->avg($revenue),
        ];
        $failureReason = $rows === []
            ? '目标门店没有可回读的本地经营日报。'
            : '本地经营日报虽已回读，但来源未做外部核验，不能自动视为真实全酒店经营数据。';
        $truthContext = [
            'status' => 'unverified',
            'status_label' => '未验证',
            'metric_scope' => 'whole_hotel_local_report',
            'scope_label' => '全酒店口径的本地日报记录（来源未外部验证），不是OTA渠道数据',
            'hotels' => $targetHotelId > 0 ? [['system_hotel_id' => $targetHotelId]] : [],
            'platforms' => ['not_applicable'],
            'date_range' => [
                'start' => $dates[0] ?? null,
                'end' => $dates !== [] ? $dates[count($dates) - 1] : null,
            ],
            'source_methods' => ['internal_daily_report'],
            'source' => [
                'table' => 'daily_reports',
                'row_ids' => array_values(array_unique($rowIds)),
                'methods' => ['internal_daily_report'],
                'caliber' => 'whole_hotel_local_report_unverified',
            ],
            'collected_at_range' => [
                'start' => $collectedAt[0] ?? null,
                'end' => $collectedAt !== [] ? $collectedAt[count($collectedAt) - 1] : null,
            ],
            'persistence' => [
                'record_count' => count($rows),
                'stored_count' => count($rows),
                'readback_verified_count' => count($rows),
                'excluded_untrusted_count' => count($rows),
            ],
            'failure_reason' => $failureReason,
        ];
        $metricTruth = [];
        foreach ($metrics as $key => $value) {
            $metricTruth[$key] = array_merge($truthContext, [
                'metric_key' => $key,
                'calculation_status' => $value === null ? 'missing' : 'calculated',
                'value_observed' => $value !== null,
            ]);
        }

        return array_merge($metrics, [
            'truth_context' => $truthContext,
            'metric_truth' => $metricTruth,
        ]);
    }

    private function safeRows(string $table, callable $reader): array
    {
        try {
            return $reader();
        } catch (\Throwable $e) {
            if (stripos($e->getMessage(), $table) !== false || stripos($e->getMessage(), 'Base table') !== false) {
                return [];
            }
            throw $e;
        }
    }

    private function buildTenantSnapshotQuery(string $table, ?int $tenantId)
    {
        $query = Db::name($table);
        if ($tenantId === null || !$this->tableHasColumn($table, 'tenant_id')) {
            $query->where('id', -1);
            return $query;
        }

        $query->where('tenant_id', $tenantId);
        return $query;
    }

    private function buildHotelSnapshotQuery(string $table, ?int $tenantId, int $hotelId, string $hotelColumn)
    {
        $query = $this->buildTenantSnapshotQuery($table, $tenantId);
        if ($hotelId <= 0 || !$this->tableHasColumn($table, $hotelColumn)) {
            $query->where('id', -1);
            return $query;
        }

        $query->where($hotelColumn, $hotelId);
        return $query;
    }

    private function filterSnapshotRowsForHotel(array $rows, string $hotelColumn, int $hotelId): array
    {
        if ($hotelId <= 0) {
            return [];
        }

        return array_values(array_filter(
            $rows,
            static fn(mixed $row): bool => is_array($row)
                && (int)($row[$hotelColumn] ?? 0) === $hotelId
        ));
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        try {
            $safeTable = str_replace('`', '', $table);
            $safeColumn = str_replace("'", "''", $column);
            return !empty(Db::query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'"));
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function summarizeOnlineData(array $rows, ?int $targetHotelId = null): array
    {
        $targetHotelId = max(0, (int)$targetHotelId);
        $queriedCount = count($rows);
        $rows = $this->filterSnapshotRowsForHotel($rows, 'system_hotel_id', $targetHotelId);
        $truthRows = [];
        $trustedCount = 0;
        foreach ($rows as $row) {
            $truth = OnlineDataTrustStatusService::truthEnvelope($row);
            $truthRows[] = $truth;
            if ($this->onlineSnapshotRowIsTrusted($row, $truth, $targetHotelId)) {
                $trustedCount++;
            }
        }

        $truthSummary = OnlineDataTrustStatusService::summarizeTruthEnvelopes($truthRows, [
            'excluded_untrusted_count' => max(0, $queriedCount - count($rows)),
            'fallback_failure_reason' => $targetHotelId > 0
                ? ($trustedCount > 0 ? '' : 'no_target_hotel_trusted_readback_rows')
                : 'target_hotel_missing',
        ]);
        $dataGaps = [];
        if ($targetHotelId <= 0) {
            $dataGaps[] = 'target_hotel_missing';
        } elseif ($rows === []) {
            $dataGaps[] = 'target_hotel_ota_rows_missing';
        } elseif ($trustedCount === 0) {
            $dataGaps[] = 'target_hotel_trusted_readback_rows_missing';
        }

        return [
            'sample_count' => count($rows),
            'queried_sample_count' => $queriedCount,
            'trusted_sample_count' => $trustedCount,
            'has_real_ota_data' => $targetHotelId > 0 && $trustedCount > 0,
            'status' => (string)($truthSummary['status'] ?? 'unverified'),
            'status_label' => (string)($truthSummary['status_label'] ?? ''),
            'metric_scope' => 'ota_channel',
            'scope_label' => 'OTA channel evidence only; not whole-hotel operating truth',
            'target_hotel_id' => $targetHotelId > 0 ? $targetHotelId : null,
            'truth_summary' => $truthSummary,
            'evidence_rows' => $truthRows,
            'data_gaps' => $dataGaps,
        ];
    }

    private function onlineSnapshotRowIsTrusted(array $row, array $truth, int $targetHotelId): bool
    {
        $hotel = is_array($truth['hotel'] ?? null) ? $truth['hotel'] : [];
        $source = is_array($truth['source'] ?? null) ? $truth['source'] : [];
        $persistence = is_array($truth['persistence'] ?? null) ? $truth['persistence'] : [];
        $validationStatus = strtolower(trim((string)($row['validation_status'] ?? '')));
        $truthStatus = strtolower(trim((string)($truth['status'] ?? 'unverified')));
        $platform = strtolower(trim((string)($truth['platform'] ?? '')));
        $dataDate = trim((string)($truth['data_date'] ?? ''));
        $collectedAt = trim((string)($truth['collected_at'] ?? ''));

        return $targetHotelId > 0
            && (int)($hotel['system_hotel_id'] ?? 0) === $targetHotelId
            && (int)($row['id'] ?? 0) > 0
            && in_array($platform, ['ctrip', 'meituan', 'qunar'], true)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dataDate) === 1
            && trim((string)($source['method'] ?? '')) !== ''
            && preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?/', $collectedAt) === 1
            && ($persistence['stored'] ?? false) === true
            && ($persistence['readback_verified'] ?? false) === true
            && in_array($validationStatus, ['normal', 'available', 'verified', 'valid', 'ok', 'success', 'complete', 'completed'], true)
            && OnlineDataTrustStatusService::classifyRowStatus($row['status'] ?? $row['save_status'] ?? '') === 'usable'
            && in_array($truthStatus, ['verified', 'partial'], true);
    }

    private function summarizeCompetitors(array $hotels, array $prices): array
    {
        $priceSummary = $this->summarizeComparableCompetitorPrices($prices);
        return [
            'competitor_count' => count($hotels),
            'visible_price_count' => count($prices),
            'decision_eligible_price_count' => (int)($priceSummary['decision_eligible_row_count'] ?? 0),
            'reference_only_price_count' => (int)($priceSummary['reference_only_row_count'] ?? count($prices)),
            'avg_competitor_price' => $priceSummary['avg_price'] ?? null,
            'comparison_key' => (string)($priceSummary['comparison_key'] ?? ''),
            'comparison_status' => (string)($priceSummary['comparison_status'] ?? 'no_data'),
            'comparison_scope' => 'same_platform_stay_dates_room_rate_meal_cancel_payment_tax_currency_guest_mix',
            'price_usage_boundary' => 'ota_public_rate_reference_not_project_adr_or_financial_assumption',
            'data_gaps' => array_values((array)($priceSummary['data_gaps'] ?? [])),
            'quality_gap_counts' => (array)($priceSummary['quality_gap_counts'] ?? []),
        ];
    }

    /** @return array<string, mixed> */
    private function summarizeComparableCompetitorPrices(array $rows): array
    {
        $groups = [];
        $gapCounts = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $assessment = $this->assessComparableCompetitorPrice($row);
            if (($assessment['eligible'] ?? false) !== true) {
                foreach ((array)($assessment['reasons'] ?? []) as $reason) {
                    $gapCounts[$reason] = ($gapCounts[$reason] ?? 0) + 1;
                }
                continue;
            }

            $comparisonKey = (string)$assessment['comparison_key'];
            $groups[$comparisonKey]['prices'][] = (float)$row['price'];
            $groups[$comparisonKey]['latest'] = max(
                (string)($groups[$comparisonKey]['latest'] ?? ''),
                (string)($assessment['captured_at'] ?? '')
            );
        }

        if ($groups === []) {
            if ($rows !== []) {
                $gapCounts['strict_comparability_missing'] = count($rows);
            }
            return [
                'comparison_status' => $rows === [] ? 'no_data' : 'reference_only',
                'avg_price' => null,
                'comparison_key' => '',
                'visible_row_count' => count($rows),
                'decision_eligible_row_count' => 0,
                'reference_only_row_count' => count($rows),
                'data_gaps' => array_values(array_keys($gapCounts)),
                'quality_gap_counts' => $gapCounts,
            ];
        }

        uasort($groups, static function (array $left, array $right): int {
            $sampleCompare = count($right['prices'] ?? []) <=> count($left['prices'] ?? []);
            return $sampleCompare !== 0
                ? $sampleCompare
                : strcmp((string)($right['latest'] ?? ''), (string)($left['latest'] ?? ''));
        });
        $comparisonKey = (string)array_key_first($groups);
        $selected = $groups[$comparisonKey];
        $selectedPrices = (array)($selected['prices'] ?? []);
        $selectedCount = count($selectedPrices);
        $eligibleCount = array_sum(array_map(
            static fn (array $group): int => count($group['prices'] ?? []),
            $groups
        ));
        if (count($groups) > 1) {
            $gapCounts['mixed_comparison_key'] = max(1, $eligibleCount - $selectedCount);
        }

        return [
            'comparison_status' => 'eligible',
            'avg_price' => $selectedPrices === [] ? null : round(array_sum($selectedPrices) / $selectedCount, 2),
            'comparison_key' => $comparisonKey,
            'visible_row_count' => count($rows),
            'decision_eligible_row_count' => $selectedCount,
            'reference_only_row_count' => max(0, count($rows) - $selectedCount),
            'data_gaps' => array_values(array_keys($gapCounts)),
            'quality_gap_counts' => $gapCounts,
        ];
    }

    /** @return array{eligible:bool,reasons:array<int,string>,comparison_key:string,captured_at:string} */
    private function assessComparableCompetitorPrice(array $row): array
    {
        $context = $this->competitorPriceContext($row);
        $reasons = [];
        if (!is_numeric($row['price'] ?? null) || (float)$row['price'] <= 0) {
            $reasons[] = 'price_missing';
        }
        foreach ([
            'platform', 'check_in_date', 'check_out_date', 'room_type_key', 'rate_plan_key',
            'breakfast', 'cancellation_policy', 'payment_mode', 'price_basis', 'currency',
            'availability', 'validation_status',
        ] as $field) {
            if (!$this->competitorPriceContextHasValue($context, $field)) {
                $reasons[] = $field . '_missing';
            }
        }
        if (!array_key_exists('tax_fee_included', $context)) {
            $reasons[] = 'tax_fee_included_missing';
        }
        if (!is_numeric($context['adults'] ?? null) || (int)$context['adults'] <= 0) {
            $reasons[] = 'adults_missing';
        }
        if (!is_numeric($context['children'] ?? null) || (int)$context['children'] < 0) {
            $reasons[] = 'children_missing';
        }
        if (!$this->competitorPriceReadbackVerified($context['readback_verified'] ?? null)) {
            $reasons[] = 'readback_unverified';
        }
        if (!in_array(strtolower(trim((string)($context['validation_status'] ?? ''))), ['normal', 'available', 'ok', 'valid', 'verified'], true)) {
            $reasons[] = 'validation_failed';
        }
        if (!in_array(strtolower(trim((string)($context['availability'] ?? ''))), ['available', 'bookable'], true)) {
            $reasons[] = 'not_publicly_bookable';
        }

        $checkIn = trim((string)($context['check_in_date'] ?? ''));
        $checkOut = trim((string)($context['check_out_date'] ?? ''));
        if ($checkIn !== '' && $checkOut !== ''
            && (strtotime($checkIn) === false || strtotime($checkOut) === false || strtotime($checkOut) <= strtotime($checkIn))
        ) {
            $reasons[] = 'stay_date_invalid';
        }

        $keyValues = [];
        foreach ([
            'platform', 'check_in_date', 'check_out_date', 'room_type_key', 'rate_plan_key',
            'breakfast', 'cancellation_policy', 'payment_mode', 'tax_fee_included', 'price_basis',
            'currency', 'adults', 'children',
        ] as $field) {
            $keyValues[] = strtolower(trim((string)($context[$field] ?? '')));
        }

        $derivedComparisonKey = hash('sha256', implode('|', $keyValues));
        $providedComparisonKey = strtolower(trim((string)($context['comparison_key'] ?? '')));

        return [
            'eligible' => $reasons === [],
            'reasons' => array_values(array_unique($reasons)),
            'comparison_key' => $providedComparisonKey === ''
                ? $derivedComparisonKey
                : hash('sha256', $providedComparisonKey . '|' . $derivedComparisonKey),
            'captured_at' => trim((string)($context['captured_at'] ?? '')),
        ];
    }

    /** @return array<string, mixed> */
    private function competitorPriceContext(array $row): array
    {
        $context = $row;
        foreach (['comparison_context', 'rate_context', 'context_json', 'metadata_json', 'raw_data'] as $field) {
            $nested = $this->decodeJson($row[$field] ?? []);
            if ($nested !== []) {
                $context = array_merge($context, $nested);
            }
        }

        $aliases = [
            'platform' => ['platform', 'ota_platform'],
            'check_in_date' => ['check_in_date', 'checkin_date', 'checkInDate', 'stay_date'],
            'check_out_date' => ['check_out_date', 'checkout_date', 'checkOutDate', 'departure_date'],
            'room_type_key' => ['room_type_key', 'room_type_id', 'room_type_name'],
            'rate_plan_key' => ['rate_plan_key', 'rate_plan_id', 'rate_plan_name'],
            'breakfast' => ['breakfast', 'breakfast_policy', 'breakfast_included', 'meal_plan'],
            'cancellation_policy' => ['cancellation_policy', 'cancel_policy', 'cancel_rule'],
            'payment_mode' => ['payment_mode', 'payment_policy', 'payment_type'],
            'tax_fee_included' => ['tax_fee_included', 'tax_included', 'includes_tax'],
            'price_basis' => ['price_basis', 'price_unit'],
            'currency' => ['currency'],
            'adults' => ['adults', 'adult_count'],
            'children' => ['children', 'child_count'],
            'availability' => ['availability', 'availability_status'],
            'validation_status' => ['validation_status', 'quality_status'],
            'readback_verified' => ['readback_verified'],
            'comparison_key' => ['comparison_key'],
            'captured_at' => ['captured_at', 'fetch_time', 'create_time', 'created_at'],
        ];
        foreach ($aliases as $canonical => $fields) {
            $value = $this->firstCompetitorPriceContextValue($context, $fields);
            if ($value !== null) {
                if ($canonical === 'breakfast' && is_bool($value)) {
                    $value = $value ? 'included' : 'excluded';
                }
                $context[$canonical] = $value;
            }
        }
        return $context;
    }

    private function firstCompetitorPriceContextValue(array $context, array $fields): mixed
    {
        foreach ($fields as $field) {
            if (!array_key_exists($field, $context) || $context[$field] === null) {
                continue;
            }
            if (is_string($context[$field]) && trim($context[$field]) === '') {
                continue;
            }
            return $context[$field];
        }
        return null;
    }

    private function competitorPriceContextHasValue(array $context, string $field): bool
    {
        return array_key_exists($field, $context)
            && $context[$field] !== null
            && trim((string)$context[$field]) !== '';
    }

    private function competitorPriceReadbackVerified(mixed $value): bool
    {
        return $value === true
            || $value === 1
            || in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'verified'], true);
    }

    private function calculate(array $input, array $snapshot): array
    {
        $assumptions = [
            '固定成本采用规则情景假设（非经营实绩）：月租金 + 900 元/间人工 + 260 元/间能耗 + 18,000 元其他固定成本。',
            '变动成本采用规则情景假设（非经营实绩）：按情景营业收入的 18% 测算。',
        ];
        $roomCount = (float)$input['room_count'];
        $areaPerRoom = round((float) $input['property_area'] / $roomCount, 2);
        $openingCost = $this->numericOrNull($input['opening_cost'] ?? null);
        $baseAdr = $this->hasPositiveReadinessValue($input['adr'] ?? null) ? (float)$input['adr'] : null;
        $baseOcc = $this->normalizeOccupancy($input['occ'] ?? null);
        $dataGaps = $this->feasibilityInputDataGaps($input);

        $investmentParts = [
            $this->numericOrNull($input['decoration_budget'] ?? null),
            $this->numericOrNull($input['transfer_fee'] ?? null),
            $openingCost,
        ];
        $totalInvestment = in_array(null, $investmentParts, true)
            ? null
            : round(array_sum($investmentParts), 2);
        if ($totalInvestment !== null && $totalInvestment <= 0) {
            $dataGaps[] = 'total_investment_must_be_positive';
        }
        $dataGaps = array_values(array_unique($dataGaps));
        $decisionReady = $dataGaps === [];

        $conservativeAdr = $baseAdr === null ? null : $baseAdr * 0.9;
        $optimisticAdr = $baseAdr === null ? null : $baseAdr * 1.08;
        $conservativeOcc = $baseOcc === null ? null : max(0.0, $baseOcc - 0.10);
        $optimisticOcc = $baseOcc === null ? null : min(1.0, $baseOcc + 0.08);
        $scenarios = [
            $this->scenario('保守情景', $roomCount, $conservativeAdr, $conservativeOcc, $input, $totalInvestment, $decisionReady),
            $this->scenario('基准情景', $roomCount, $baseAdr, $baseOcc, $input, $totalInvestment, $decisionReady),
            $this->scenario('乐观情景', $roomCount, $optimisticAdr, $optimisticOcc, $input, $totalInvestment, $decisionReady),
        ];
        $scenarioDataGaps = $this->financialScenarioDataGaps($scenarios);
        if ($scenarioDataGaps !== []) {
            $dataGaps = array_values(array_unique(array_merge($dataGaps, $scenarioDataGaps)));
            $decisionReady = false;
        }

        return [
            'decision_ready' => $decisionReady,
            'evaluation_status' => $decisionReady ? '可评估' : '待评估',
            'data_gaps' => $dataGaps,
            'area_per_room' => $areaPerRoom,
            'opening_cost' => $openingCost,
            'total_investment' => $totalInvestment,
            'scenarios' => $scenarios,
            'cost_model' => [
                'basis' => 'rule_scenario_assumption',
                'is_actual_performance' => false,
                'variable_cost_ratio' => 0.18,
                'fixed_cost_formula' => 'monthly_rent + room_count * 900 + room_count * 260 + 18000',
            ],
            'assumptions' => $assumptions,
        ];
    }

    private function scenario(
        string $name,
        float $roomCount,
        ?float $adr,
        ?float $occ,
        array $input,
        ?float $totalInvestment,
        bool $decisionReady
    ): array
    {
        $base = [
            'name' => $name,
            'adr' => $adr === null ? null : round($adr, 2),
            'occ' => $occ === null ? null : round($occ, 4),
            'revpar' => null,
            'monthly_revenue' => null,
            'monthly_operating_cost' => null,
            'monthly_net_cashflow' => null,
            'payback_months' => null,
            'rent_ratio' => null,
            'rent_ratio_status' => 'unavailable_missing_revenue',
            'risk_level' => '待评估',
            'calculation_status' => 'pending_input',
            'cost_basis' => 'rule_scenario_assumption_not_actuals',
            'data_gaps' => ['rent_ratio_denominator_missing'],
        ];
        if (!$decisionReady || $adr === null || $occ === null || $totalInvestment === null) {
            return $base;
        }

        $revpar = $adr * $occ;
        $monthlyRevenue = $roomCount * 30 * $revpar;
        $variableCost = $monthlyRevenue * 0.18;
        $fixedCost = (float) $input['monthly_rent'] + $roomCount * 900 + $roomCount * 260 + 18000;
        $monthlyCost = $fixedCost + $variableCost;
        $net = $monthlyRevenue - $monthlyCost;
        $payback = $net > 0 ? $totalInvestment / $net : null;
        $roundedMonthlyRevenue = round($monthlyRevenue, 2);
        $rentRatio = $roundedMonthlyRevenue > 0
            ? (float) $input['monthly_rent'] / $monthlyRevenue
            : null;
        $rentRatioStatus = $rentRatio === null ? 'unavailable_non_positive_revenue' : 'calculated';
        $scenarioDataGaps = $rentRatio === null ? ['rent_ratio_denominator_non_positive'] : [];

        return array_merge($base, [
            'revpar' => round($revpar, 2),
            'monthly_revenue' => $roundedMonthlyRevenue,
            'monthly_operating_cost' => round($monthlyCost, 2),
            'monthly_net_cashflow' => round($net, 2),
            'payback_months' => $payback === null ? null : round($payback, 1),
            'rent_ratio' => $rentRatio === null ? null : round($rentRatio, 4),
            'rent_ratio_status' => $rentRatioStatus,
            'risk_level' => $this->scenarioRisk($net, $payback, $rentRatio),
            'calculation_status' => $rentRatio === null ? 'rule_scenario_partial' : 'rule_scenario_ready',
            'data_gaps' => $scenarioDataGaps,
        ]);
    }

    private function scenarioRisk(float $net, ?float $payback, ?float $rentRatio): string
    {
        if ($net <= 0 || $payback === null || $rentRatio === null || $rentRatio >= 0.42) {
            return '高';
        }
        if ($payback > 42 || $rentRatio >= 0.32) {
            return '中';
        }
        return '低';
    }

    private function buildAiReport(array $input, array $snapshot, array $calculation): array
    {
        if (($calculation['decision_ready'] ?? false) !== true) {
            return $this->normalizeReportFinancialScenarios(
                $this->buildPendingReport($input, $snapshot, $calculation),
                $input
            );
        }

        $messages = [
            [
                'role' => 'system',
                'content' => '你是酒店投资可行性分析师。只输出符合 schema 的 JSON。财务数字必须采用用户显式输入和本地规则情景测算，不要编造来源；固定成本公式与 18% 变动成本均为规则情景假设，不是经营实绩。monthly_revenue 缺失或小于等于 0 时，rent_ratio 必须为 null，禁止用 0 冒充可计算比率。competitor_summary.comparison_status 不是 eligible 或 avg_competitor_price 为 null 时，竞对原始价只能标记为 reference_only，不得与项目 ADR 比较，不得生成调价风险或投资结论；即使严格可比，OTA公开价也不能替代项目 ADR 假设或财务输入。无可追溯市场或竞品证据时，market_score 必须为 null、competition_level 必须写“未评估”；用户未输入产品定位或目标客群时，recommended_model 和 target_customer 必须为 null；无来源的数据写入 assumptions；风险评级保持保守。',
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'user_input' => $input,
                    'system_snapshot' => $snapshot,
                    'deterministic_calculation' => $calculation,
                    'report_language' => 'zh-CN',
                ], JSON_UNESCAPED_UNICODE),
            ],
        ];

        $modelKey = trim((string)($input['model_key'] ?? 'deepseek_v4_default'));
        try {
            $report = $this->client->createJsonResponse($messages, $this->schema(), $modelKey !== '' ? $modelKey : 'deepseek_v4_default');
            return $this->normalizeReportFinancialScenarios(
                $this->enforceMarketEvidenceBoundary($report, $input, $snapshot),
                $input
            );
        } catch (\Throwable $e) {
            return $this->normalizeReportFinancialScenarios(
                $this->buildFallbackReport($input, $snapshot, $calculation, $e->getMessage()),
                $input
            );
        }
    }

    private function buildPendingReport(array $input, array $snapshot, array $calculation): array
    {
        $dataGaps = array_values(array_unique((array)($calculation['data_gaps'] ?? [])));
        $gapLabels = $this->feasibilityDataGapLabels($dataGaps);
        $gapText = $gapLabels ? implode('、', $gapLabels) : '核心投资输入';
        $location = trim((string)$input['city'] . ' ' . (string)$input['district'] . ' ' . (string)$input['address']);
        $hasMarketEvidence = $this->hasTraceableMarketEvidence($snapshot);

        return [
            'decision_ready' => false,
            'evaluation_status' => '待评估',
            'data_gaps' => $dataGaps,
            'report_source' => 'pending_input',
            'conclusion_grade' => null,
            'conclusion_text' => '待评估：核心测算输入未齐全。',
            'core_reason' => '缺少或无效输入：' . $gapText . '。系统未生成回本期或结论等级。',
            'summary' => [
                'project_name' => (string)$input['project_name'],
                'location' => $location,
                'room_count' => (int)$input['room_count'],
                'total_investment' => $calculation['total_investment'] ?? null,
                'payback_months' => null,
            ],
            'basic_info' => [],
            'market_judgement' => [
                'market_score' => null,
                'competition_level' => '未评估',
                'recommended_model' => $input['target_brand_level'] !== '' ? (string)$input['target_brand_level'] : null,
                'target_customer' => $input['target_customer'] !== '' ? (string)$input['target_customer'] : null,
                'reasoning' => $hasMarketEvidence
                    ? '存在本地竞品记录，但核心投资输入未齐，且当前记录来源、门店、日期和字段口径未形成完整投决证据。'
                    : '未读取到可追溯的市场或竞品记录，市场判断保持未评估。',
            ],
            'financial_scenarios' => (array)($calculation['scenarios'] ?? []),
            'risk_list' => [
                [
                    'risk' => '核心输入完整性',
                    'level' => '待核验',
                    'reason' => '缺少或无效输入：' . $gapText . '。',
                    'action' => '在本页显式补齐输入后重新评估，不使用演示值或隐藏默认值。',
                ],
            ],
            'action_plan' => [
                ['title' => '补齐核心测算输入', 'priority' => 'P0', 'detail' => '补齐：' . $gapText . '。'],
                ['title' => '复核规则情景成本', 'priority' => 'P1', 'detail' => '确认固定成本公式和 18% 变动成本假设是否适用于该项目。'],
            ],
            'assumptions' => (array)($calculation['assumptions'] ?? []),
            'evidence' => [
                [
                    'source' => 'user_input_pending',
                    'title' => '待补充的项目输入',
                    'url' => '',
                    'summary' => '当前仅保存已填写内容；未形成可用回本期、结论等级或投资可行性结论。',
                ],
            ],
        ];
    }

    private function buildFallbackReport(array $input, array $snapshot, array $calculation, string $reason): array
    {
        $base = $calculation['scenarios'][1];
        $payback = $base['payback_months'] ?? null;
        $riskLevel = (string)($base['risk_level'] ?? '中');
        $grade = 'C';
        if ($riskLevel === '低' && $payback !== null && $payback <= 30) {
            $grade = 'A';
        } elseif ($riskLevel !== '高' && $payback !== null && $payback <= 42) {
            $grade = 'B';
        } elseif ($riskLevel === '高') {
            $grade = 'D';
        }

        $location = trim((string)$input['city'] . ' ' . (string)$input['district'] . ' ' . (string)$input['address']);
        $sourceCounts = is_array($snapshot['source_counts'] ?? null) ? $snapshot['source_counts'] : [];
        $hasLocalRecords = array_sum(array_map('intval', $sourceCounts)) > 0;
        $hasMarketEvidence = $this->hasTraceableMarketEvidence($snapshot);

        return [
            'decision_ready' => true,
            'evaluation_status' => '可评估',
            'data_gaps' => [],
            'report_source' => 'fallback',
            'conclusion_grade' => $grade,
            'conclusion_text' => $grade === 'A' || $grade === 'B' ? '可进入下一轮尽调，需继续校验租金、ADR和入住率假设。' : '当前条件需谨慎推进，建议先优化租金或投资规模。',
            'core_reason' => $payback === null
                ? '本地规则测算显示基准情景未形成正净现金流，回本期无法计算；财务情景规则风险为' . $riskLevel . '。'
                : '本地规则测算显示基准情景回本周期为' . $payback . '个月，财务情景规则风险为' . $riskLevel . '。',
            'summary' => [
                'project_name' => (string)$input['project_name'],
                'location' => $location,
                'room_count' => (int)$input['room_count'],
                'total_investment' => (float)$calculation['total_investment'],
                'payback_months' => $payback === null ? null : (float)$payback,
            ],
            'basic_info' => [],
            'market_judgement' => [
                'market_score' => null,
                'competition_level' => '未评估',
                'recommended_model' => $input['target_brand_level'] !== '' ? (string)$input['target_brand_level'] : null,
                'target_customer' => $input['target_customer'] !== '' ? (string)$input['target_customer'] : null,
                'reasoning' => $hasMarketEvidence
                    ? '已读取可追溯的本地竞品记录，但当前规则未定义市场评分和竞争强度口径，因此不生成分数或强度结论；记录来源真实性仍待核验。'
                    : '未读取到可追溯的市场或竞品记录，市场评分与竞争强度保持未评估；产品定位和目标客群仅回显用户输入。',
            ],
            'financial_scenarios' => $calculation['scenarios'],
            'risk_list' => [
                [
                    'risk' => '回本周期',
                    'level' => $riskLevel,
                    'reason' => $payback === null ? '基准情景月净现金流为负。' : '基准情景回本周期为' . $payback . '个月。',
                    'action' => '复核ADR、入住率、租金和单房装修投入。',
                ],
                [
                    'risk' => '数据来源',
                    'level' => '待核验',
                    'reason' => $hasLocalRecords
                        ? '存在本地系统记录，但记录存在不等于来源、门店、日期和字段口径已核验。'
                        : '缺少可追溯的经营、OTA和竞品记录。',
                    'action' => '接入近期日报、OTA订单和竞对价格后重新生成。',
                ],
            ],
            'action_plan' => [
                ['title' => '复核核心假设', 'priority' => 'P0', 'detail' => '校验ADR、入住率、月租金、装修预算和转让费。'],
                ['title' => '补齐外部样本', 'priority' => 'P1', 'detail' => '补充竞对价格、评分、商圈客流和OTA转化数据。'],
                ['title' => '形成投决结论', 'priority' => 'P2', 'detail' => '按保守、基准、乐观三情景确定是否立项。'],
            ],
            'assumptions' => array_values(array_unique(array_merge(
                $calculation['assumptions'],
                ['LLM报告生成失败，已启用本地测算兜底：' . mb_substr($reason, 0, 120)]
            ))),
            'evidence' => [
                [
                    'source' => 'local_calculation',
                    'title' => '宿析OS规则情景测算',
                    'url' => '',
                    'summary' => $hasLocalRecords
                        ? '基于用户输入、可用本地记录和内置财务规则生成；本地记录来源真实性仍待核验。'
                        : '仅基于用户输入和内置财务规则生成，未使用可追溯市场或竞品证据。',
                ],
            ],
        ];
    }

    private function mergeFinancials(array $report, array $input, array $calculation): array
    {
        $decisionReady = ($calculation['decision_ready'] ?? false) === true;
        $base = is_array($calculation['scenarios'][1] ?? null) ? $calculation['scenarios'][1] : [];
        $payback = $decisionReady && array_key_exists('payback_months', $base) ? $base['payback_months'] : null;
        $report['summary'] = array_merge($report['summary'] ?? [], [
            'project_name' => $input['project_name'],
            'location' => trim($input['city'] . ' ' . $input['district'] . ' ' . $input['address']),
            'room_count' => (int) $input['room_count'],
            'total_investment' => $calculation['total_investment'],
            'payback_months' => $payback,
        ]);
        $report['decision_ready'] = $decisionReady;
        $report['evaluation_status'] = $decisionReady ? '可评估' : '待评估';
        $report['data_gaps'] = array_values(array_unique((array)($calculation['data_gaps'] ?? [])));
        if (!$decisionReady) {
            $report['conclusion_grade'] = null;
            $report['conclusion_text'] = '待评估：核心测算输入未齐全。';
            $report['summary']['payback_months'] = null;
        }
        $report['financial_scenarios'] = $this->normalizeFinancialScenarios(
            $calculation['scenarios'],
            $input
        );
        $report['cost_model'] = $calculation['cost_model'] ?? [];
        $report['assumptions'] = array_values(array_unique(array_merge($calculation['assumptions'], $report['assumptions'] ?? [])));
        $report['basic_info'] = [
            ['label' => '项目名称', 'value' => $input['project_name'], 'source' => '用户输入'],
            ['label' => '城市区域', 'value' => trim($input['city'] . ' ' . $input['district']), 'source' => '用户输入'],
            ['label' => '物业面积', 'value' => $input['property_area'] . '㎡', 'source' => '用户输入'],
            ['label' => '计划房量', 'value' => $input['room_count'] . '间', 'source' => '用户输入'],
            ['label' => '单房面积', 'value' => $calculation['area_per_room'] . '㎡/间', 'source' => '本地测算'],
            [
                'label' => '总投资',
                'value' => $calculation['total_investment'] ?? '待评估',
                'source' => $decisionReady ? '规则情景测算' : '输入待补充',
            ],
        ];

        return $this->normalizeReportFinancialScenarios($report, $input);
    }

    private function enforceMarketEvidenceBoundary(array $report, array $input, array $snapshot): array
    {
        $market = is_array($report['market_judgement'] ?? null) ? $report['market_judgement'] : [];
        if (!$this->hasTraceableMarketEvidence($snapshot)) {
            $market['market_score'] = null;
            $market['competition_level'] = '未评估';
            $market['reasoning'] = '未读取到可追溯的市场或竞品记录，市场评分与竞争强度保持未评估；产品定位和目标客群仅回显用户输入。';
        }
        $market['recommended_model'] = $input['target_brand_level'] !== '' ? (string)$input['target_brand_level'] : null;
        $market['target_customer'] = $input['target_customer'] !== '' ? (string)$input['target_customer'] : null;
        $report['market_judgement'] = $market;

        return $report;
    }

    private function hasTraceableMarketEvidence(array $snapshot): bool
    {
        $counts = is_array($snapshot['source_counts'] ?? null) ? $snapshot['source_counts'] : [];
        $competitorSummary = is_array($snapshot['competitor_summary'] ?? null)
            ? $snapshot['competitor_summary']
            : [];
        return (int)($counts['competitor_hotels'] ?? 0) > 0
            || (
                ($competitorSummary['comparison_status'] ?? '') === 'eligible'
                && (int)($competitorSummary['decision_eligible_price_count'] ?? 0) > 0
                && is_numeric($competitorSummary['avg_competitor_price'] ?? null)
                && (float)$competitorSummary['avg_competitor_price'] > 0
            );
    }

    private function formatRecord(FeasibilityReport $record): array
    {
        return $this->formatArrayRecord($record->toArray(), true);
    }

    private function formatArrayRecord(array $row, bool $full): array
    {
        $input = $this->decodeJson($row['input_json'] ?? []);
        $snapshot = $this->decodeJson($row['snapshot_json'] ?? []);
        $report = $this->decodeJson($row['report_json'] ?? []);
        $report = $this->normalizeReportFinancialScenarios($report, $input);
        $report = $this->enrichDecisionRecommendations($report, $input, $snapshot);
        $readiness = $this->buildFeasibilityReadiness($input, $snapshot, $report);
        $decisionReady = ($readiness['decision_ready'] ?? false) === true;
        $truthContext = $this->feasibilityRecordTruthContext($row, $input);
        $inputTruthContext = array_merge($truthContext, [
            'source_methods' => ['user_input'],
            'source' => array_merge((array)$truthContext['source'], [
                'methods' => ['user_input'],
                'caliber' => 'user_provided_investment_scenario',
            ]),
            'calculation_status' => 'not_applicable',
        ]);
        $otaTruthContext = is_array($snapshot['online_summary']['truth_summary'] ?? null)
            ? $snapshot['online_summary']['truth_summary']
            : $this->missingFeasibilityOtaTruthContext($input);
        $marketTruthContext = $this->feasibilityMarketTruthContext($truthContext, $snapshot, $report);
        $metricTruth = $this->feasibilityReportMetricTruth($report, $truthContext, $marketTruthContext);
        $inputMetricTruth = $this->feasibilityInputMetricTruth($input, $inputTruthContext);
        foreach ((array)($report['financial_scenarios'] ?? []) as $index => $scenario) {
            if (!is_array($scenario)) {
                continue;
            }
            $scenarioTruth = [];
            foreach (['adr', 'occ', 'monthly_revenue', 'monthly_operating_cost', 'monthly_net_cashflow', 'payback_months', 'rent_ratio'] as $field) {
                $key = 'financial_scenarios.' . $index . '.' . $field;
                $scenarioTruth[$field] = $metricTruth[$key] ?? $this->feasibilityMetricEnvelope(null, $truthContext, $key);
            }
            $report['financial_scenarios'][$index]['metric_truth'] = $scenarioTruth;
        }
        $report['truth_context'] = $truthContext;
        $report['input_truth_context'] = $inputTruthContext;
        $report['input_metric_truth'] = $inputMetricTruth;
        $report['metric_truth'] = $metricTruth;
        $report['ota_truth_context'] = $otaTruthContext;
        $report['daily_report_truth_context'] = is_array($snapshot['daily_summary']['truth_context'] ?? null)
            ? $snapshot['daily_summary']['truth_context']
            : null;
        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];

        $data = [
            'id' => (int) $row['id'],
            'project_name' => $row['project_name'],
            'city' => (string)($input['city'] ?? ''),
            'district' => (string)($input['district'] ?? ''),
            'decision_ready' => $decisionReady,
            'evaluation_status' => $decisionReady ? '可评估' : '待评估',
            'data_gaps' => array_values((array)($readiness['data_gaps'] ?? [])),
            'conclusion_grade' => $decisionReady ? ($report['conclusion_grade'] ?? $row['conclusion_grade'] ?? null) : null,
            'payback_months' => $decisionReady ? ($summary['payback_months'] ?? $row['payback_months'] ?? null) : null,
            'total_investment' => array_key_exists('total_investment', $summary)
                ? $summary['total_investment']
                : ($row['total_investment'] ?? null),
            'risk_level' => $this->feasibilityRiskLevel($report),
            'feasibility_readiness' => $readiness,
            'truth_context' => $truthContext,
            'input_truth_context' => $inputTruthContext,
            'input_metric_truth' => $inputMetricTruth,
            'metric_truth' => $metricTruth,
            'ota_truth_context' => $otaTruthContext,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
        if ($full) {
            $data['input'] = $input;
            $data['snapshot'] = $snapshot;
            $data['report'] = $report;
        }
        return $data;
    }

    /** @return array<string, mixed> */
    private function feasibilityRecordTruthContext(array $row, array $input): array
    {
        $recordId = (int)($row['id'] ?? 0);
        $hotelId = (int)($input['hotel_id'] ?? 0);
        $storedAt = trim((string)($row['updated_at'] ?? $row['created_at'] ?? ''));
        $date = preg_match('/^\d{4}-\d{2}-\d{2}/', $storedAt) === 1 ? substr($storedAt, 0, 10) : null;
        $gaps = ['user_input_source_unverified', 'collection_time_not_applicable'];
        if ($hotelId <= 0) {
            $gaps[] = 'target_hotel_missing';
        }

        return [
            'status' => 'unverified',
            'status_label' => '未验证',
            'metric_scope' => 'investment_scenario',
            'scope_label' => '投资情景测算，不是OTA数据，也不是全酒店经营实绩',
            'hotels' => $hotelId > 0 ? [['system_hotel_id' => $hotelId]] : [],
            'hotel_id' => $hotelId > 0 ? $hotelId : null,
            'platforms' => ['not_applicable'],
            'date_range' => ['start' => $date, 'end' => $date],
            'source_methods' => ['user_input', 'deterministic_formula'],
            'source' => [
                'table' => 'feasibility_reports',
                'row_ids' => $recordId > 0 ? [$recordId] : [],
                'methods' => ['user_input', 'deterministic_formula'],
                'caliber' => 'investment_scenario',
            ],
            'collected_at_range' => ['start' => null, 'end' => null],
            'collection_time_applicability' => 'not_applicable_manual_input',
            'calculated_at' => $storedAt !== '' ? $storedAt : null,
            'persistence' => [
                'stored' => $recordId > 0,
                'readback_verified' => $recordId > 0,
                'stored_at' => $storedAt !== '' ? $storedAt : null,
            ],
            'failure_reason' => ($hotelId > 0 ? '' : '未绑定目标门店；')
                . '投资、租金、ADR和入住率来自人工录入，未提供可外部核验的经营或财务来源。',
            'data_gaps' => $gaps,
        ];
    }

    /** @return array<string, mixed> */
    private function missingFeasibilityOtaTruthContext(array $input): array
    {
        $hotelId = (int)($input['hotel_id'] ?? 0);
        return [
            'status' => 'unverified',
            'status_label' => '未验证',
            'metric_scope' => 'ota_channel',
            'scope_label' => 'OTA渠道证据，不代表全酒店经营',
            'hotels' => $hotelId > 0 ? [['system_hotel_id' => $hotelId]] : [],
            'platforms' => [],
            'date_range' => ['start' => null, 'end' => null],
            'source_methods' => [],
            'source' => ['table' => 'online_daily_data', 'row_ids' => [], 'methods' => []],
            'collected_at_range' => ['start' => null, 'end' => null],
            'persistence' => [
                'record_count' => 0,
                'stored_count' => 0,
                'readback_verified_count' => 0,
                'excluded_untrusted_count' => 0,
            ],
            'failure_reason' => $hotelId > 0 ? '没有可回读的目标门店OTA证据。' : '未绑定目标门店，无法读取OTA证据。',
        ];
    }

    /** @return array<string, mixed> */
    private function feasibilityMarketTruthContext(array $baseTruth, array $snapshot, array $report): array
    {
        $market = is_array($report['market_judgement'] ?? null) ? $report['market_judgement'] : [];
        $marketObserved = $this->metricValueObserved($market['market_score'] ?? null)
            || trim((string)($market['competition_level'] ?? '')) !== '';
        $hasEvidence = $this->hasTraceableMarketEvidence($snapshot);
        $status = $marketObserved && $hasEvidence ? 'partial' : 'unverified';

        return array_merge($baseTruth, [
            'status' => $status,
            'status_label' => $status === 'partial' ? '部分数据' : '未验证',
            'metric_scope' => 'investment_market_assessment',
            'scope_label' => '投资市场评估派生结果，不是OTA指标，也不是全酒店经营实绩',
            'source_methods' => ['local_snapshot', 'llm_assisted_analysis'],
            'source' => array_merge((array)$baseTruth['source'], [
                'methods' => ['local_snapshot', 'llm_assisted_analysis'],
                'caliber' => 'investment_market_assessment',
            ]),
            'failure_reason' => $hasEvidence
                ? '市场判断由局部可追溯样本与模型派生，底层样本范围不足以成为全酒店经营事实。'
                : '缺少可追溯市场或竞品证据，市场评分与竞争强度未验证。',
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    private function feasibilityInputMetricTruth(array $input, array $truthContext): array
    {
        $truth = [];
        foreach (['property_area', 'room_count', 'monthly_rent', 'lease_years', 'decoration_budget', 'transfer_fee', 'opening_cost', 'adr', 'occ'] as $key) {
            $truth[$key] = $this->feasibilityMetricEnvelope($input[$key] ?? null, $truthContext, $key, 'provided');
        }
        return $truth;
    }

    /** @return array<string, array<string, mixed>> */
    private function feasibilityReportMetricTruth(array $report, array $truthContext, array $marketTruthContext): array
    {
        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
        $market = is_array($report['market_judgement'] ?? null) ? $report['market_judgement'] : [];
        $truth = [
            'summary.room_count' => $this->feasibilityMetricEnvelope($summary['room_count'] ?? null, $truthContext, 'summary.room_count'),
            'summary.total_investment' => $this->feasibilityMetricEnvelope($summary['total_investment'] ?? null, $truthContext, 'summary.total_investment'),
            'summary.payback_months' => $this->feasibilityMetricEnvelope($summary['payback_months'] ?? null, $truthContext, 'summary.payback_months'),
            'market_judgement.market_score' => $this->feasibilityMetricEnvelope($market['market_score'] ?? null, $marketTruthContext, 'market_judgement.market_score'),
            'market_judgement.competition_level' => $this->feasibilityMetricEnvelope($market['competition_level'] ?? null, $marketTruthContext, 'market_judgement.competition_level'),
        ];
        foreach ((array)($report['financial_scenarios'] ?? []) as $index => $scenario) {
            if (!is_array($scenario)) {
                continue;
            }
            foreach (['adr', 'occ', 'monthly_revenue', 'monthly_operating_cost', 'monthly_net_cashflow', 'payback_months', 'rent_ratio'] as $field) {
                $key = 'financial_scenarios.' . $index . '.' . $field;
                $truth[$key] = $this->feasibilityMetricEnvelope($scenario[$field] ?? null, $truthContext, $key);
            }
        }
        return $truth;
    }

    /** @return array<string, mixed> */
    private function feasibilityMetricEnvelope(mixed $value, array $truthContext, string $metricKey, string $observedStatus = 'calculated'): array
    {
        $observed = $this->metricValueObserved($value);
        return array_merge($truthContext, [
            'metric_key' => $metricKey,
            'calculation_status' => $observed ? $observedStatus : 'missing',
            'value_observed' => $observed,
            'calculation_basis' => $observedStatus === 'provided'
                ? 'user_input'
                : 'deterministic_formula_or_bounded_assessment',
        ]);
    }

    private function metricValueObserved(mixed $value): bool
    {
        if (is_numeric($value)) {
            return is_finite((float)$value);
        }
        return is_string($value) && trim($value) !== '';
    }

    private function enrichDecisionRecommendations(array $report, array $input, array $snapshot): array
    {
        $evidence = [];
        foreach ((array)($report['evidence'] ?? []) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $source = trim((string)($item['source'] ?? ''));
            $evidence[] = [
                'ref' => $source !== '' ? $source . '#' . ($index + 1) : 'feasibility_evidence#' . ($index + 1),
                'source' => $source !== '' ? $source : (string)($item['title'] ?? 'feasibility_evidence'),
                'summary' => (string)($item['summary'] ?? ''),
                'scope' => 'investment_scenario',
                'quality_status' => str_contains($source, 'verified') ? 'verified' : 'user_provided_unverified',
            ];
        }
        if ($evidence === []) {
            $evidence[] = [
                'ref' => 'feasibility_user_input_snapshot',
                'source' => 'user_input_and_deterministic_calculation',
                'scope' => 'investment_scenario',
                'quality_status' => 'user_provided_unverified',
                'summary' => '基于当前项目录入、规则情景和可用本地记录；不等同于已核验经营实绩。',
            ];
        }

        $context = [
            'scope' => 'investment_scenario',
            'hotel_id' => (int)($input['hotel_id'] ?? 0),
            'data_basis' => $evidence,
            'basis_summary' => (string)($report['core_reason'] ?? ''),
            'default_risk_level' => $this->feasibilityRiskLevel($report),
            'review_window' => '进入投决会前复核真实流水、租约、证照、OTA渠道样本和三情景财务结果',
        ];
        $report['action_plan'] = $this->decisionQualityService->enrichRecommendations(
            $report['action_plan'] ?? [],
            $context
        );
        $report['recommendation_quality'] = $this->decisionQualityService->summarize(
            $report['action_plan'],
            $context
        );

        return $report;
    }

    private function avg(array $values): ?float
    {
        $values = array_values(array_filter($values, fn ($value) => is_numeric($value) && is_finite((float)$value)));
        return $values ? round(array_sum($values) / count($values), 2) : null;
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value === null || $value === '') {
            return [];
        }
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function readinessCheck(string $key, string $label, bool $passed, string $evidence, string $nextAction, int $weight): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'passed' => $passed,
            'status' => $passed ? 'ok' : 'missing',
            'evidence' => $evidence,
            'next_action' => $nextAction,
            'weight' => $weight,
        ];
    }

    private function feasibilityReadinessStage(
        bool $reportReady,
        bool $scenarioReady,
        bool $financialReady,
        bool $sourceBacked,
        bool $riskClear,
        bool $diligenceReady,
        bool $humanReviewReady,
        bool $trackingReady
    ): string {
        if (!$reportReady) {
            return 'report_missing';
        }
        if (!$scenarioReady || !$financialReady) {
            return 'partial_report';
        }
        if (!$sourceBacked) {
            return 'manual_input_only';
        }
        if (!$riskClear) {
            return 'data_recheck_required';
        }
        if (!$diligenceReady) {
            return 'diligence_required';
        }
        if (!$humanReviewReady) {
            return 'review_ready';
        }
        if (!$trackingReady) {
            return 'approved_pending_tracking';
        }
        return 'feasibility_ready';
    }

    private function feasibilityReadinessStageLabel(string $stage): string
    {
        return [
            'input_pending' => '待评估',
            'report_missing' => '未形成报告',
            'partial_report' => '报告未完整',
            'manual_input_only' => '仅手工可研',
            'data_recheck_required' => '需风险复核',
            'diligence_required' => '需补尽调证据',
            'review_ready' => '可进入人工复核',
            'approved_pending_tracking' => '已复核待跟踪',
            'feasibility_ready' => '可研闭环就绪',
        ][$stage] ?? $stage;
    }

    private function feasibilityReadinessNotice(string $stage): string
    {
        return [
            'input_pending' => '核心投资输入未齐全，当前仅保存已填写内容；不生成回本期或结论等级。',
            'report_missing' => '当前还没有可复核的可行性报告结果。',
            'partial_report' => '报告或三情景测算尚未完整，不能进入投决复核。',
            'manual_input_only' => '当前主要依赖手工输入与规则情景测算，缺少真实经营、竞品、OTA、租约或外部调研证据。',
            'data_recheck_required' => '存在 C/D 结论、高风险、负现金流或不可回本信号，需先复核。',
            'diligence_required' => '报告和来源已基本形成，但缺少租约、证照、现场或法务尽调证据。',
            'review_ready' => '核心测算、来源和尽调证据已具备复核条件；尚不等于已审批或已投资。',
            'approved_pending_tracking' => '已有人工复核痕迹，但还缺执行、开业或投后跟踪记录。',
            'feasibility_ready' => '已有报告、来源、尽调、人工复核和跟踪证据，可视为可研闭环就绪。',
        ][$stage] ?? '';
    }

    private function feasibilityFinancialInputsReady(array $input, array $snapshot, array $report): bool
    {
        return $this->feasibilityInputDataGaps($input) === [];
    }

    private function feasibilitySourceEvidenceReady(array $input, array $snapshot, array $report): bool
    {
        if ($this->sourceCountTotal($snapshot) > 0) {
            return true;
        }
        if ($this->hasNamedEvidence([$input, $snapshot, $report], [
            'source_evidence',
            'external_evidence',
            'market_evidence',
            'competitor_evidence',
            'research_evidence',
            'survey_evidence',
        ])) {
            return true;
        }

        foreach ((array)($report['evidence'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $source = strtolower(trim((string)($item['source'] ?? '')));
            $url = trim((string)($item['url'] ?? ''));
            $title = trim((string)($item['title'] ?? ''));
            if ($url !== '') {
                return true;
            }
            if ($title !== '' && !in_array($source, ['', 'system', 'local', 'local_calculation', 'user_input'], true)) {
                return true;
            }
        }

        return false;
    }

    private function feasibilitySourceEvidenceText(array $snapshot, array $report): string
    {
        $count = $this->sourceCountTotal($snapshot);
        if ($count > 0) {
            return '已读取系统快照样本 ' . $count . ' 条';
        }
        $evidenceCount = count((array)($report['evidence'] ?? []));
        if ($evidenceCount > 0) {
            return '报告保留证据条目 ' . $evidenceCount . ' 条，但未确认真实外部或系统样本';
        }
        return '暂无真实样本证据';
    }

    private function feasibilitySourceScope(array $snapshot): string
    {
        $counts = is_array($snapshot['source_counts'] ?? null) ? $snapshot['source_counts'] : [];
        $parts = [];
        foreach ($counts as $key => $value) {
            $count = (int)$value;
            if ($count > 0) {
                $parts[] = $key . ':' . $count;
            }
        }
        return $parts ? implode(', ', $parts) : 'manual_input_or_report_only';
    }

    private function feasibilityRiskClear(array $report): bool
    {
        $grade = strtoupper(trim((string)($report['conclusion_grade'] ?? '')));
        if (!in_array($grade, ['A', 'B'], true)) {
            return false;
        }

        $base = is_array($report['financial_scenarios'][1] ?? null) ? $report['financial_scenarios'][1] : [];
        if ($base) {
            if ((float)($base['monthly_net_cashflow'] ?? 0) <= 0) {
                return false;
            }
            if (!$this->hasPositiveReadinessValue($base['payback_months'] ?? null)) {
                return false;
            }
        }

        foreach ((array)($report['risk_list'] ?? []) as $risk) {
            if (!is_array($risk)) {
                continue;
            }
            $level = strtolower((string)($risk['level'] ?? ''));
            if (str_contains($level, '高') || str_contains($level, 'high')) {
                return false;
            }
        }

        return true;
    }

    private function feasibilityRiskLevel(array $report): string
    {
        $resolvedLevel = '';
        foreach ((array)($report['risk_list'] ?? []) as $risk) {
            if (!is_array($risk)) {
                continue;
            }
            $level = (string)($risk['level'] ?? '');
            if ($level !== '') {
                if (str_contains($level, '待核验') || stripos($level, 'unverified') !== false || stripos($level, 'unknown') !== false) {
                    return '待核验';
                }
                if (str_contains($level, '高') || stripos($level, 'high') !== false) {
                    return '高风险';
                }
                if (str_contains($level, '中') || stripos($level, 'medium') !== false) {
                    $resolvedLevel = '中风险';
                    continue;
                }
                if (str_contains($level, '低') || str_contains($level, '浣') || stripos($level, 'low') !== false) {
                    $resolvedLevel = $resolvedLevel !== '' ? $resolvedLevel : '低风险';
                    continue;
                }
                $resolvedLevel = $resolvedLevel !== '' ? $resolvedLevel : $level;
            }
        }

        if ($resolvedLevel !== '') {
            return $resolvedLevel;
        }

        $grade = strtoupper(trim((string)($report['conclusion_grade'] ?? '')));
        return match ($grade) {
            'A' => '低风险',
            'B' => '中风险',
            'C' => '中高风险',
            'D' => '高风险',
            default => '',
        };
    }

    private function executionRiskLevel(array $report): string
    {
        $grade = strtoupper(trim((string)($report['conclusion_grade'] ?? '')));
        return match ($grade) {
            'A' => 'low',
            'B' => 'medium',
            'C', 'D' => 'high',
            default => 'medium',
        };
    }

    private function sourceCountTotal(array $snapshot): int
    {
        $counts = is_array($snapshot['source_counts'] ?? null) ? $snapshot['source_counts'] : [];
        $total = 0;
        foreach ($counts as $key => $value) {
            if (in_array((string)$key, ['competitor_price_logs', 'competitor_price_logs_reference_only'], true)) {
                continue;
            }
            $total += max(0, (int)$value);
        }
        return $total;
    }

    private function hasPositiveReadinessValue(mixed $value): bool
    {
        return is_numeric($value) && (float)$value > 0;
    }

    private function hasNamedEvidence(array $payloads, array $keys): bool
    {
        foreach ($payloads as $payload) {
            if (!is_array($payload)) {
                continue;
            }
            foreach ($keys as $key) {
                if ($this->hasNonEmptyEvidenceValue($payload[$key] ?? null)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasNonEmptyEvidenceValue(mixed $value): bool
    {
        if (is_array($value)) {
            return !empty(array_filter($value, fn (mixed $item): bool => $this->hasNonEmptyEvidenceValue($item)));
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (float)$value > 0;
        }
        return trim((string)$value) !== '';
    }

    private function hasHumanReviewApproval(array $payloads): bool
    {
        foreach ($payloads as $payload) {
            if (!is_array($payload)) {
                continue;
            }
            foreach (['manual_review', 'human_review', 'review_status', 'approval_status', 'review_result', 'decision_status'] as $key) {
                if (!array_key_exists($key, $payload)) {
                    continue;
                }
                $value = $payload[$key];
                if (is_bool($value)) {
                    return $value;
                }
                if (is_array($value)) {
                    if ($this->hasHumanReviewApproval([$value])) {
                        return true;
                    }
                    continue;
                }
                $text = strtolower(trim((string)$value));
                if ($text !== '' && preg_match('/approved|pass|passed|confirmed|reviewed|yes|true|通过|已审|批准|同意/u', $text) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasPostDecisionTracking(array $payloads): bool
    {
        foreach ($payloads as $payload) {
            if (!is_array($payload)) {
                continue;
            }
            foreach (['post_decision_tracking', 'execution_tracking', 'tracking_records', 'operation_execution_intent_id', 'execution_intent_id', 'opening_project_id', 'investment_tracking_id'] as $key) {
                if ($this->hasNonEmptyEvidenceValue($payload[$key] ?? null)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function schema(): array
    {
        return [
            'x-governance' => [
                'module' => 'agent',
                'scenario' => 'feasibility_report',
                'prompt_version' => 'agent.feasibility_report.v1',
                'decision_impact' => 'investment',
                'knowledge_sources' => ['user_input', 'system_snapshot', 'deterministic_calculation'],
            ],
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['conclusion_grade', 'conclusion_text', 'core_reason', 'summary', 'basic_info', 'market_judgement', 'financial_scenarios', 'risk_list', 'action_plan', 'assumptions', 'evidence'],
            'properties' => [
                'conclusion_grade' => ['type' => 'string', 'enum' => ['A', 'B', 'C', 'D']],
                'conclusion_text' => ['type' => 'string'],
                'core_reason' => ['type' => 'string'],
                'summary' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['project_name', 'location', 'room_count', 'total_investment', 'payback_months'],
                    'properties' => [
                        'project_name' => ['type' => 'string'],
                        'location' => ['type' => 'string'],
                        'room_count' => ['type' => 'number'],
                        'total_investment' => ['type' => 'number'],
                        'payback_months' => ['type' => ['number', 'null']],
                    ],
                ],
                'basic_info' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['label', 'value', 'source'],
                        'properties' => [
                            'label' => ['type' => 'string'],
                            'value' => ['type' => ['string', 'number']],
                            'source' => ['type' => 'string'],
                        ],
                    ],
                ],
                'market_judgement' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['market_score', 'competition_level', 'recommended_model', 'target_customer', 'reasoning'],
                    'properties' => [
                        'market_score' => ['type' => ['number', 'null']],
                        'competition_level' => ['type' => 'string'],
                        'recommended_model' => ['type' => ['string', 'null']],
                        'target_customer' => ['type' => ['string', 'null']],
                        'reasoning' => ['type' => 'string'],
                    ],
                ],
                'financial_scenarios' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['name', 'adr', 'occ', 'monthly_revenue', 'monthly_net_cashflow', 'payback_months', 'rent_ratio', 'risk_level'],
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'adr' => ['type' => 'number'],
                            'occ' => ['type' => 'number'],
                            'monthly_revenue' => ['type' => 'number'],
                            'monthly_net_cashflow' => ['type' => 'number'],
                            'payback_months' => ['type' => ['number', 'null']],
                            'rent_ratio' => ['type' => ['number', 'null']],
                            'risk_level' => ['type' => 'string'],
                        ],
                    ],
                ],
                'risk_list' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['risk', 'level', 'reason', 'action'],
                        'properties' => [
                            'risk' => ['type' => 'string'],
                            'level' => ['type' => 'string', 'enum' => ['高', '中', '低', '待核验']],
                            'reason' => ['type' => 'string'],
                            'action' => ['type' => 'string'],
                        ],
                    ],
                ],
                'action_plan' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['title', 'priority', 'detail'],
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'priority' => ['type' => 'string', 'enum' => ['P0', 'P1', 'P2']],
                            'detail' => ['type' => 'string'],
                        ],
                    ],
                ],
                'assumptions' => ['type' => 'array', 'items' => ['type' => 'string']],
                'evidence' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['source', 'title', 'url', 'summary'],
                        'properties' => [
                            'source' => ['type' => 'string'],
                            'title' => ['type' => 'string'],
                            'url' => ['type' => 'string'],
                            'summary' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
