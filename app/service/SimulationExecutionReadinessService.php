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
            $readiness = $this->buildStrategyReadiness(
                $this->decodeJson($row['input_json'] ?? []),
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
            $readiness = $this->buildQuantReadiness(
                $this->decodeJson($row['input_json'] ?? []),
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
