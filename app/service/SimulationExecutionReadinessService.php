<?php
declare(strict_types=1);

namespace app\service;

class SimulationExecutionReadinessService
{
    public function buildStrategyReadiness(array $input, array $scores, array $recommendation, array $risk, array $dataSnapshot): array
    {
        $totalScore = (int)($scores['total_score'] ?? 0);
        $decision = trim((string)($recommendation['decision'] ?? ''));
        $coreReady = $totalScore > 0 && $decision !== '';
        $sourceReady = $this->hasStrategySourceEvidence($input, $recommendation, $risk, $dataSnapshot);
        $riskClear = !$this->isHighRisk((string)($risk['risk_level'] ?? ''), $totalScore);
        $explanationReady = trim((string)($recommendation['decision_direction'] ?? '')) !== ''
            || is_array($recommendation['ai_evaluation'] ?? null);
        $humanReviewReady = $this->hasHumanReviewApproval([$input, $recommendation, $risk, $dataSnapshot]);
        $executionReady = $this->hasExecutionTracking([$input, $recommendation, $risk, $dataSnapshot]);

        $checks = [
            $this->readinessCheck('strategy_result', '战略推演结果', $coreReady, '已形成评分、风险和推荐方向', '先生成战略推演结果，不能只保留项目参数。', 20),
            $this->readinessCheck('source_evidence', '数据来源证据', $sourceReady, $this->strategySourceEvidenceText($dataSnapshot), '补齐本地经营、OTA、竞品、外部 POI 或人工实勘证据；当前仅能视为模型推演。', 20),
            $this->readinessCheck('risk_recheck', '风险复核', $riskClear, '未出现高风险或低评分阻断', '先复核高风险、低评分或否决项，明确重谈、暂缓或放弃。', 15),
            $this->readinessCheck('explanation_layer', '解释与动作层', $explanationReady, '已生成决策方向、关键动作或 AI/规则解释', '补齐关键动作、主要风险和下一步待验证数据。', 15),
            $this->readinessCheck('manual_review', '人工复核审批', $humanReviewReady, '已记录人工复核/审批状态', '补一条人工复核结论，明确通过、暂缓、重谈或放弃。', 15),
            $this->readinessCheck('execution_bridge', '执行跟踪关联', $executionReady, '已关联运营执行、开业或跟踪记录', '关联运营执行意图、任务、开业项目或投后跟踪记录。', 15),
        ];

        return $this->buildReadiness(
            'strategy',
            $checks,
            $this->executionStage($coreReady, $sourceReady, $riskClear, $explanationReady, $humanReviewReady, $executionReady)
        );
    }

    public function buildQuantReadiness(array $input, array $result, array $scenarios, array $riskHints): array
    {
        $calculationReady = array_key_exists('monthlyNetCashflow', $result)
            && array_key_exists('monthlyRevenue', $result)
            && array_key_exists('riskLevel', $result);
        $scenarioReady = count($scenarios) >= 3;
        $assumptionReady = $this->quantAssumptionsReady($input);
        $sourceReady = $this->hasExplicitEvidence([$input, $result, ['scenarios' => $scenarios], ['risk_hints' => $riskHints]]);
        $riskClear = !$this->isHighRisk((string)($result['riskLevel'] ?? ''), null)
            && (float)($result['monthlyNetCashflow'] ?? 0) > 0
            && (($result['paybackMonths'] ?? null) !== null);
        $humanReviewReady = $this->hasHumanReviewApproval([$input, $result]);
        $executionReady = $this->hasExecutionTracking([$input, $result]);

        $checks = [
            $this->readinessCheck('calculation_result', '量化测算结果', $calculationReady, '已形成收入、成本、现金流和风险结果', '先生成量化测算结果，不能只保留输入表单。', 20),
            $this->readinessCheck('scenario_model', '三情景模型', $scenarioReady, '已形成保守、基准、乐观情景', '补齐三情景模拟，避免只看单一基准测算。', 15),
            $this->readinessCheck('financial_assumptions', '财务假设完整度', $assumptionReady, '房量、ADR、入住率、租金、成本和投资额已填写', '补齐房量、ADR、入住率、租金、人工、能耗、佣金和投资额等关键假设。', 15),
            $this->readinessCheck('source_evidence', '真实样本证据', $sourceReady, '已记录经营样本、OTA、竞品、租约或附件证据', '补充近期日报、OTA 订单、竞品价格、租约、成本清单或附件证据。', 20),
            $this->readinessCheck('risk_recheck', '风险复核', $riskClear, '现金流为正且未出现高风险阻断', '先复核负现金流、不可回本、高风险或保本入住率过高的问题。', 10),
            $this->readinessCheck('manual_review', '人工复核审批', $humanReviewReady, '已记录人工复核/审批状态', '补一条人工复核结论，明确通过、暂缓、重谈或放弃。', 10),
            $this->readinessCheck('execution_bridge', '执行跟踪关联', $executionReady, '已关联运营执行、开业或跟踪记录', '关联运营执行意图、任务、开业项目或投后跟踪记录。', 10),
        ];

        return $this->buildReadiness(
            'quant',
            $checks,
            $this->executionStage($calculationReady, $sourceReady, $riskClear, $scenarioReady && $assumptionReady, $humanReviewReady, $executionReady)
        );
    }

    public function buildStrategyExecutionIntentInput(array $record, array $overrides = []): array
    {
        $input = is_array($record['input'] ?? null) ? $record['input'] : [];
        $input = $this->withTopLevelExecutionBridge($input, $record);
        $scores = is_array($record['scores'] ?? null) ? $record['scores'] : [];
        if (!array_key_exists('total_score', $scores)) {
            $scores = [
                'total_score' => (int)($record['total_score'] ?? 0),
                'items' => $scores,
            ];
        }
        $recommendation = is_array($record['recommendation'] ?? null) ? $record['recommendation'] : [];
        $risk = is_array($record['risk'] ?? null) ? $record['risk'] : [];
        $dataSnapshot = is_array($record['data_snapshot'] ?? null) ? $record['data_snapshot'] : [];
        $readiness = $this->buildStrategyReadiness($input, $scores, $recommendation, $risk, $dataSnapshot);
        $readyForIntent = $this->canCreateSimulationExecutionIntent($readiness);
        $recordId = (int)($record['id'] ?? $record['record_id'] ?? 0);
        $projectName = trim((string)($record['project_name'] ?? $input['project_name'] ?? ''));
        $executionDates = $this->executionIntentDates($overrides);

        return [
            'source_module' => 'strategy_simulation',
            'source_record_id' => $recordId,
            'hotel_id' => (int)($overrides['hotel_id'] ?? 0),
            'platform' => 'investment',
            'object_type' => 'investment',
            'action_type' => 'strategy_review',
            'date_start' => $executionDates['date_start'],
            'date_end' => $executionDates['date_end'],
            'current_value' => [
                'total_score' => (int)($scores['total_score'] ?? 0),
                'risk_level' => (string)($risk['risk_level'] ?? $record['risk_level'] ?? ''),
                'readiness_stage' => (string)($readiness['stage'] ?? ''),
            ],
            'target_value' => [
                'project_name' => $projectName,
                'tracking_status' => $readyForIntent ? 'pending_strategy_execution_review' : 'blocked_by_simulation_readiness',
                'target_metric' => 'strategy_simulation_closure',
                'decision' => (string)($recommendation['decision'] ?? $record['decision'] ?? ''),
                'action_text' => $this->strategyActionText($recommendation),
            ],
            'evidence' => $this->simulationExecutionEvidence('strategy_simulation', $recordId, $readiness, $readyForIntent, [
                'source_scope' => 'strategy_simulation_records',
                'data_snapshot_sources' => array_values(array_filter((array)($dataSnapshot['source_summary'] ?? []), 'is_scalar')),
            ]),
            'expected_metric' => 'strategy_simulation_closure',
            'expected_delta' => 0,
            'risk_level' => $this->executionRiskLevel((string)($risk['risk_level'] ?? $record['risk_level'] ?? ''), $readyForIntent),
            'status' => 'pending_approval',
        ];
    }

    public function buildQuantExecutionIntentInput(array $record, array $overrides = []): array
    {
        $input = is_array($record['input'] ?? null) ? $record['input'] : [];
        $input = $this->withTopLevelExecutionBridge($input, $record);
        $result = is_array($record['result'] ?? null) ? $record['result'] : [];
        $scenarios = is_array($record['scenarios'] ?? null) ? $record['scenarios'] : [];
        $riskHints = is_array($record['risk_hints'] ?? null) ? $record['risk_hints'] : [];
        $readiness = $this->buildQuantReadiness($input, $result, $scenarios, $riskHints);
        $readyForIntent = $this->canCreateSimulationExecutionIntent($readiness);
        $recordId = (int)($record['id'] ?? $record['record_id'] ?? 0);
        $projectName = trim((string)($record['project_name'] ?? $input['projectName'] ?? $input['project_name'] ?? ''));
        $executionDates = $this->executionIntentDates($overrides);

        return [
            'source_module' => 'quant_simulation',
            'source_record_id' => $recordId,
            'hotel_id' => (int)($overrides['hotel_id'] ?? 0),
            'platform' => 'investment',
            'object_type' => 'investment',
            'action_type' => 'quant_review',
            'date_start' => $executionDates['date_start'],
            'date_end' => $executionDates['date_end'],
            'current_value' => [
                'monthly_net_cashflow' => (float)($result['monthlyNetCashflow'] ?? $record['monthly_net_cashflow'] ?? 0),
                'payback_months' => $result['paybackMonths'] ?? $record['payback_months'] ?? null,
                'risk_level' => (string)($result['riskLevel'] ?? $record['risk_level'] ?? ''),
                'readiness_stage' => (string)($readiness['stage'] ?? ''),
            ],
            'target_value' => [
                'project_name' => $projectName,
                'tracking_status' => $readyForIntent ? 'pending_quant_execution_review' : 'blocked_by_simulation_readiness',
                'target_metric' => 'quant_simulation_closure',
                'action_text' => $this->quantActionText($result),
            ],
            'evidence' => $this->simulationExecutionEvidence('quant_simulation', $recordId, $readiness, $readyForIntent, [
                'source_scope' => 'quant_simulation_records',
                'scenario_count' => count($scenarios),
            ]),
            'expected_metric' => 'quant_simulation_closure',
            'expected_delta' => 0,
            'risk_level' => $this->executionRiskLevel((string)($result['riskLevel'] ?? $record['risk_level'] ?? ''), $readyForIntent),
            'status' => 'pending_approval',
        ];
    }

    private function executionIntentDates(array $overrides): array
    {
        $dateStart = trim((string)($overrides['date_start'] ?? ''));
        if ($dateStart === '') {
            $dateStart = date('Y-m-d');
        }

        $dateEnd = trim((string)($overrides['date_end'] ?? ''));
        if ($dateEnd === '') {
            $dateEnd = $dateStart;
        }

        return [
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
        ];
    }

    private function withTopLevelExecutionBridge(array $input, array $record): array
    {
        foreach (['operation_execution_intent_id', 'execution_intent_id', 'execution_task_id', 'opening_project_id', 'tracking_record_id', 'post_decision_tracking_id'] as $key) {
            if ((int)($input[$key] ?? 0) <= 0 && (int)($record[$key] ?? 0) > 0) {
                $input[$key] = (int)$record[$key];
            }
        }

        if (!array_key_exists('post_decision_tracking', $input) && array_key_exists('post_decision_tracking', $record)) {
            $input['post_decision_tracking'] = $record['post_decision_tracking'];
        }

        return $input;
    }

    public function readinessSummaryFromRows(array $strategyRows, array $quantRows): array
    {
        $summary = [
            'record_count' => 0,
            'stage_counts' => [],
            'review_ready_count' => 0,
            'execution_ready_count' => 0,
            'best_score' => 0,
            'best_stage' => '',
            'best_status_label' => '',
            'missing_evidence' => [],
        ];

        foreach ($strategyRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $input = $this->withTopLevelExecutionBridge($this->decodeJson($row['input_json'] ?? []), $row);
            $readiness = $this->buildStrategyReadiness(
                $input,
                $this->decodeJson($row['score_json'] ?? []),
                $this->decodeJson($row['recommendation_json'] ?? []),
                $this->decodeJson($row['risk_json'] ?? []),
                $this->decodeJson($row['data_snapshot_json'] ?? [])
            );
            $this->appendSummary($summary, $readiness);
        }

        foreach ($quantRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $input = $this->withTopLevelExecutionBridge($this->decodeJson($row['input_json'] ?? []), $row);
            $readiness = $this->buildQuantReadiness(
                $input,
                $this->decodeJson($row['result_json'] ?? []),
                $this->decodeJson($row['scenarios_json'] ?? []),
                $this->decodeJson($row['risk_hints_json'] ?? [])
            );
            $this->appendSummary($summary, $readiness);
        }

        return $summary;
    }

    private function buildReadiness(string $recordType, array $checks, string $stage): array
    {
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

        return [
            'stage' => $stage,
            'status_label' => $this->stageLabel($stage),
            'score' => $score,
            'ready_for_review' => in_array($stage, ['review_ready', 'approved_pending_execution', 'execution_ready'], true),
            'execution_ready' => $stage === 'execution_ready',
            'record_type' => $recordType,
            'checks' => $checks,
            'missing_evidence' => $missingEvidence,
            'next_action' => $missingEvidence[0]['next_action'] ?? '进入人工复核，并保留执行和效果证据。',
            'notice' => $this->stageNotice($stage),
        ];
    }

    private function canCreateSimulationExecutionIntent(array $readiness): bool
    {
        return in_array((string)($readiness['stage'] ?? ''), ['review_ready', 'approved_pending_execution', 'execution_ready'], true);
    }

    private function simulationExecutionEvidence(string $sourceModule, int $recordId, array $readiness, bool $readyForIntent, array $extra = []): array
    {
        $missingEvidence = array_values(array_filter((array)($readiness['missing_evidence'] ?? []), 'is_array'));
        $dataGaps = [];
        if (!$readyForIntent) {
            $dataGaps = array_values(array_unique(array_map(
                static fn(array $gap): string => (string)($gap['code'] ?? $gap['label'] ?? 'simulation_readiness_gap'),
                $missingEvidence
            )));
        }

        return array_merge([
            'evidence_refs' => [
                $sourceModule . '#' . $recordId,
                $sourceModule === 'strategy_simulation' ? '/api/strategy/records/' . $recordId : '/api/simulation/records/' . $recordId,
            ],
            'readiness_stage' => (string)($readiness['stage'] ?? ''),
            'readiness_score' => (int)($readiness['score'] ?? 0),
            'readiness_missing_evidence' => $missingEvidence,
            'data_gaps' => $dataGaps,
            'source_policy' => $sourceModule . '_record_to_operation_execution_intent',
            'protected_boundary' => 'Execution intent records manual review and tracking for simulation output; it does not assert investment closure or OTA execution.',
            'metric_scope' => 'investment_decision',
        ], $extra);
    }

    private function strategyActionText(array $recommendation): string
    {
        foreach (['decision_direction', 'decision'] as $field) {
            $text = trim((string)($recommendation[$field] ?? ''));
            if ($text !== '') {
                return mb_substr($text, 0, 300);
            }
        }

        return '复核战略推演结论并建立投决执行跟踪';
    }

    private function quantActionText(array $result): string
    {
        $analysis = is_array($result['modelAnalysis'] ?? null) ? $result['modelAnalysis'] : [];
        $decision = trim((string)($analysis['decision'] ?? ''));
        if ($decision !== '') {
            return mb_substr($decision, 0, 300);
        }

        return '复核量化测算结论并建立投决执行跟踪';
    }

    private function executionRiskLevel(string $riskLevel, bool $readyForIntent): string
    {
        if (!$readyForIntent) {
            return 'high';
        }
        if (str_contains($riskLevel, '高') || str_contains(strtolower($riskLevel), 'high')) {
            return 'high';
        }
        if (str_contains($riskLevel, '中') || str_contains(strtolower($riskLevel), 'medium')) {
            return 'medium';
        }

        return 'low';
    }

    private function appendSummary(array &$summary, array $readiness): void
    {
        $summary['record_count']++;
        $stage = (string)$readiness['stage'];
        $summary['stage_counts'][$stage] = (int)($summary['stage_counts'][$stage] ?? 0) + 1;
        if (($readiness['ready_for_review'] ?? false) === true) {
            $summary['review_ready_count']++;
        }
        if (($readiness['execution_ready'] ?? false) === true) {
            $summary['execution_ready_count']++;
        }
        if ((int)$readiness['score'] >= (int)$summary['best_score']) {
            $summary['best_score'] = (int)$readiness['score'];
            $summary['best_stage'] = $stage;
            $summary['best_status_label'] = (string)$readiness['status_label'];
            $summary['missing_evidence'] = array_slice((array)$readiness['missing_evidence'], 0, 4);
        }
    }

    private function executionStage(
        bool $coreReady,
        bool $sourceReady,
        bool $riskClear,
        bool $modelReady,
        bool $humanReviewReady,
        bool $executionReady
    ): string {
        if (!$coreReady) {
            return 'simulation_missing';
        }
        if (!$modelReady) {
            return 'partial_model';
        }
        if (!$sourceReady) {
            return 'manual_input_only';
        }
        if (!$riskClear) {
            return 'data_recheck_required';
        }
        if (!$humanReviewReady) {
            return 'review_ready';
        }
        if (!$executionReady) {
            return 'approved_pending_execution';
        }
        return 'execution_ready';
    }

    private function stageLabel(string $stage): string
    {
        return [
            'simulation_missing' => '未形成推演',
            'partial_model' => '模型未完整',
            'manual_input_only' => '仅手工推演',
            'data_recheck_required' => '需风险复核',
            'review_ready' => '可进入复核',
            'approved_pending_execution' => '已复核待执行',
            'execution_ready' => '执行闭环就绪',
        ][$stage] ?? $stage;
    }

    private function stageNotice(string $stage): string
    {
        return [
            'simulation_missing' => '当前还没有可复核的策略或量化推演结果。',
            'partial_model' => '模型结果尚不完整，不能进入执行判断。',
            'manual_input_only' => '当前主要来自手工输入或模型推演，缺少可追溯经营/市场证据。',
            'data_recheck_required' => '存在高风险、低评分、负现金流或不可回本信号，需先复核。',
            'review_ready' => '推演、证据和风险已具备复核条件；尚不等同于已审批或已执行。',
            'approved_pending_execution' => '已有人工复核痕迹，但还缺执行任务、证据或效果跟踪。',
            'execution_ready' => '已有推演、证据、复核和执行跟踪，可视为执行闭环就绪。',
        ][$stage] ?? '';
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

    private function hasStrategySourceEvidence(array $input, array $recommendation, array $risk, array $dataSnapshot): bool
    {
        if ($this->hasExplicitEvidence([$input, $recommendation, $risk, $dataSnapshot])) {
            return true;
        }

        return ($dataSnapshot['local_data_used'] ?? false) === true
            || ($dataSnapshot['external_data_used'] ?? false) === true
            || ($dataSnapshot['ai_search_used'] ?? false) === true;
    }

    private function strategySourceEvidenceText(array $dataSnapshot): string
    {
        $sources = array_values(array_filter(array_map('strval', (array)($dataSnapshot['source_summary'] ?? []))));
        if (!empty($sources)) {
            return '已记录来源：' . implode('、', array_slice($sources, 0, 3));
        }
        return '尚未记录可追溯来源证据';
    }

    private function quantAssumptionsReady(array $input): bool
    {
        foreach (['roomCount', 'adr', 'occupancyRate', 'monthlyRent', 'laborCost', 'utilityCost', 'otaCommissionRate'] as $key) {
            if (!$this->hasPositiveNumber($input[$key] ?? null)) {
                return false;
            }
        }

        $investment = (float)($input['decorationInvestment'] ?? 0)
            + (float)($input['furnitureInvestment'] ?? 0)
            + (float)($input['openingCost'] ?? 0)
            + (float)($input['otherInvestment'] ?? 0);

        return $investment > 0;
    }

    private function isHighRisk(string $riskLevel, ?int $score): bool
    {
        $riskLevel = trim($riskLevel);
        if (str_contains($riskLevel, '高风险') || str_contains($riskLevel, '不建议')) {
            return true;
        }
        if (in_array(strtoupper($riskLevel), ['D', 'E'], true)) {
            return true;
        }
        return $score !== null && $score < 60;
    }

    private function hasHumanReviewApproval(array $payloads): bool
    {
        foreach ($payloads as $payload) {
            if (!is_array($payload)) {
                continue;
            }
            foreach (['review_status', 'approval_status', 'decision_status', 'manual_review_status', 'execution_review_status'] as $key) {
                $value = strtolower(trim((string)($payload[$key] ?? '')));
                if (in_array($value, ['approved', 'reviewed', 'passed', '通过', '已复核', '已审批'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasExecutionTracking(array $payloads): bool
    {
        foreach ($payloads as $payload) {
            if (!is_array($payload)) {
                continue;
            }
            foreach (['operation_execution_intent_id', 'execution_intent_id', 'execution_task_id', 'opening_project_id', 'tracking_record_id', 'post_decision_tracking_id'] as $key) {
                if ((int)($payload[$key] ?? 0) > 0) {
                    return true;
                }
            }
            if ($this->nullableBool($payload['post_decision_tracking'] ?? null) === true) {
                return true;
            }
        }

        return false;
    }

    private function hasExplicitEvidence(array $payloads): bool
    {
        foreach ($payloads as $payload) {
            if (!is_array($payload)) {
                continue;
            }
            foreach ([
                'source_evidence',
                'evidence',
                'evidence_files',
                'attachments',
                'diligence_evidence',
                'operation_sample_evidence',
                'competitor_samples',
                'lease_contract_evidence',
                'cost_sheet_evidence',
                'daily_report_evidence',
                'ota_sample_evidence',
            ] as $key) {
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
            return !empty(array_filter($value, fn(mixed $item): bool => $this->hasNonEmptyEvidenceValue($item)));
        }
        if (is_bool($value)) {
            return $value;
        }

        return trim((string)$value) !== '';
    }

    private function hasPositiveNumber(mixed $value): bool
    {
        return is_numeric($value) && (float)$value > 0;
    }

    private function nullableBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value === 1;
        }
        $text = strtolower(trim((string)$value));
        if (in_array($text, ['1', 'true', 'yes', 'on', '是', '有', '已完成'], true)) {
            return true;
        }
        if (in_array($text, ['0', 'false', 'no', 'off', '否', '无', '未完成'], true)) {
            return false;
        }

        return null;
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
