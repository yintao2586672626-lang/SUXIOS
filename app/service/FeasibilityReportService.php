<?php
declare(strict_types=1);

namespace app\service;

use app\model\FeasibilityReport;
use think\facade\Db;

/** Retired generators retain scoped historical reads and existing-source approval compatibility. */
class FeasibilityReportService
{
    private AiDecisionQualityService $decisionQualityService;
    private SourceBackedExecutionBridgeProjectionService $executionBridgeProjection;
    private bool $tableEnsured = false;

    public function __construct(
        ?LlmClient $client = null,
        ?AiDecisionQualityService $decisionQualityService = null,
        ?SourceBackedExecutionBridgeProjectionService $executionBridgeProjection = null
    )
    {
        $this->decisionQualityService = $decisionQualityService ?? new AiDecisionQualityService();
        $this->executionBridgeProjection = $executionBridgeProjection
            ?? new SourceBackedExecutionBridgeProjectionService();
    }

    public function generate(array $input, int $userId): array
    {
        throw new \RuntimeException('retired_read_only', 410);
    }

    public function regenerate(int $id, int $userId, bool $isSuperAdmin, array $updatedInput = []): ?array
    {
        throw new \RuntimeException('retired_read_only', 410);
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
        $projectionContext = $this->executionBridgeProjection->projectionContext(
            'feasibility_report',
            array_map(fn(array $row): array => $this->projectionSourceForRow($row), $list)
        );

        return [
            'list' => array_map(
                fn(array $row): array => $this->formatArrayRecord($row, false, $projectionContext),
                $list
            ),
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
        throw new \RuntimeException('retired_read_only', 410);
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
        $this->assertExecutionHotelMatches($record, $hotelId);
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
                'source_snapshot_digest' => SourceBackedExecutionIntentIdentityService::snapshotDigest('feasibility_report', [
                    'id' => (int)($record['id'] ?? 0),
                    'hotel_id' => $hotelId,
                    'input' => $input,
                    'snapshot' => $snapshot,
                    'report' => $report,
                    'conclusion_grade' => (string)($record['conclusion_grade'] ?? ''),
                    'payback_months' => $record['payback_months'] ?? null,
                    'total_investment' => $record['total_investment'] ?? null,
                ]),
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

    public function executionHotelId(array $record): int
    {
        $input = $this->decodeJson($record['input'] ?? $record['input_json'] ?? []);
        $snapshot = $this->decodeJson($record['snapshot'] ?? $record['snapshot_json'] ?? []);
        $snapshotScope = is_array($snapshot['snapshot_scope'] ?? null) ? $snapshot['snapshot_scope'] : [];
        $candidates = array_values(array_unique(array_filter([
            (int)($input['hotel_id'] ?? 0),
            (int)($input['system_hotel_id'] ?? 0),
            (int)($snapshotScope['hotel_id'] ?? 0),
            (int)($snapshotScope['system_hotel_id'] ?? 0),
        ], static fn(int $hotelId): bool => $hotelId > 0)));

        if ($candidates === []) {
            throw new \InvalidArgumentException('feasibility report hotel scope missing');
        }
        if (count($candidates) !== 1) {
            throw new \InvalidArgumentException('feasibility report hotel scope conflict');
        }

        return $candidates[0];
    }

    public function assertExecutionHotelMatches(array $record, int $hotelId): int
    {
        $persistedHotelId = $this->executionHotelId($record);
        if ($hotelId <= 0 || $persistedHotelId !== $hotelId) {
            throw new \InvalidArgumentException('feasibility report hotel scope mismatch');
        }

        return $persistedHotelId;
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
        foreach ($existing as $linked) {
            if (is_array($linked) && (int)($linked['execution_intent_id'] ?? 0) === $intentId) {
                return $this->formatRecord($record);
            }
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

        $sources = array_values(array_map(
            fn(array $row): array => $this->projectionSourceForRow($row),
            array_filter($rows, 'is_array')
        ));
        $projectionContext = $this->executionBridgeProjection->projectionContext('feasibility_report', $sources);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $source = $this->projectionSourceForRow($row);
            [$input, $snapshot, $report] = $this->executionBridgeProjection->trackingForResponses(
                'feasibility_report',
                [
                    ['source' => $source, 'payload' => $this->decodeJson($row['input_json'] ?? [])],
                    ['source' => $source, 'payload' => $this->decodeJson($row['snapshot_json'] ?? [])],
                    ['source' => $source, 'payload' => $this->decodeJson($row['report_json'] ?? [])],
                ],
                $projectionContext
            );
            $readiness = $this->buildFeasibilityReadiness(
                $input,
                $snapshot,
                $report
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
        if ($this->tableEnsured) {
            return;
        }

        DatabaseSchemaRequirement::assertTableColumns('feasibility_reports', [
            'id', 'tenant_id', 'project_name', 'input_json', 'snapshot_json', 'report_json',
            'conclusion_grade', 'payback_months', 'total_investment', 'created_by',
            'created_at', 'updated_at', 'deleted_at',
        ]);
        $this->tableEnsured = true;
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
            if ($hotelId <= 0) {
                return null;
            }

            $hotelTenantId = (int)Db::name('hotels')->where('id', $hotelId)->value('tenant_id');
            return $hotelTenantId > 0 ? $hotelTenantId : null;
        } catch (\Throwable $e) {
            return null;
        }
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

    /** @return array<string, mixed> */

    /** @return array{eligible:bool,reasons:array<int,string>,comparison_key:string,captured_at:string} */

    /** @return array<string, mixed> */

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

    /** @param array<string,mixed>|null $projectionContext */
    private function formatArrayRecord(array $row, bool $full, ?array $projectionContext = null): array
    {
        $input = $this->decodeJson($row['input_json'] ?? []);
        $snapshot = $this->decodeJson($row['snapshot_json'] ?? []);
        $report = $this->decodeJson($row['report_json'] ?? []);
        $sourceScope = $this->projectionSourceForRow($row, $input, $snapshot);
        [$input, $snapshot, $report] = $this->executionBridgeProjection->trackingForResponses(
            'feasibility_report',
            [
                ['source' => $sourceScope, 'payload' => $input],
                ['source' => $sourceScope, 'payload' => $snapshot],
                ['source' => $sourceScope, 'payload' => $report],
            ],
            $projectionContext
        );
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

    /** @return array<string,mixed> */
    private function projectionSourceForRow(array $row, ?array $input = null, ?array $snapshot = null): array
    {
        $input ??= $this->decodeJson($row['input_json'] ?? []);
        $snapshot ??= $this->decodeJson($row['snapshot_json'] ?? []);
        try {
            $row['hotel_id'] = $this->executionHotelId(['input' => $input, 'snapshot' => $snapshot]);
        } catch (\InvalidArgumentException) {
            $row['hotel_id'] = 0;
        }
        return $row;
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
            if (is_array($payload)
                && SourceBackedExecutionBridgeProjectionService::hasProjectedTracking($payload)
            ) {
                return true;
            }
        }

        return false;
    }

}
