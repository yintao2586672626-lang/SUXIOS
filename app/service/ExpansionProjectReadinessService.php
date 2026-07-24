<?php
declare(strict_types=1);

namespace app\service;

/**
 * Evaluates whether an expansion project has enough evidence to enter review
 * and whether the decision loop has been closed with execution tracking.
 */
class ExpansionProjectReadinessService
{
    public function build(string $recordType, array $input, array $result): array
    {
        $marketInput = is_array($input['market_input'] ?? null) ? $input['market_input'] : $input;
        $benchmarkInput = is_array($input['benchmark_input'] ?? null) ? $input['benchmark_input'] : $input;
        $marketResult = $this->marketResult($recordType, $input, $result);
        $benchmarkResult = $this->benchmarkResult($recordType, $input, $result);
        $collaborationResult = $this->collaborationResult($recordType, $input, $result);

        $marketReady = isset($marketResult['market_heat_score']) && trim((string)($marketResult['decision'] ?? '')) !== '';
        $benchmarkReady = is_array($benchmarkResult['recommended_benchmarks'] ?? null)
            && count($benchmarkResult['recommended_benchmarks']) > 0
            && (string)($benchmarkResult['source'] ?? '') !== 'synthetic_rule_scenario';
        $taskPlanReady = $this->taskPlanReady($collaborationResult);
        $collaborationReady = $taskPlanReady;
        $financialReady = $this->financialInputsReady($marketInput, $marketResult);
        $sourceBacked = $this->sourceEvidenceReady($input, $marketResult, $benchmarkInput, $benchmarkResult);
        $riskClear = !$this->hasHighRisk($marketResult, $collaborationResult);
        $humanReviewReady = $this->hasHumanReviewApproval($input, $result);
        $trackingReady = $this->hasPostDecisionTracking($input, $result);

        $checks = [
            $this->check('market_screening', '市场规则初筛结果', $marketReady, '已形成规则初筛指数、风险提示和补证建议；不等于真实市场热度', '先生成市场规则初筛，不能只保留物业输入。', 15),
            $this->check('benchmark_model', '有来源的标杆选模结果', $benchmarkReady, '已基于用户提供的竞品指标形成参考标杆；仍需核验样本来源', '补齐真实竞品指标；合成规则情景不能作为标杆证据。', 12),
            $this->check('collaboration_plan', '人工确认的协同推进看板', $collaborationReady, '任务名称、状态、责任人和期限均由人工提供', '确认任务状态、责任人和截止日期；规则模板不代表真实进度。', 12),
            $this->check('financial_assumptions', '财务与租赁假设', $financialReady, '房量、租金、租期、ADR、入住率等关键假设已填写', '补齐房量、租金、租期、免租期、装修预算、ADR和入住率。', 14),
            $this->check('source_evidence', '真实样本证据', $sourceBacked, $this->sourceEvidenceText($input, $marketResult, $benchmarkResult), '补充竞品样本、OTA热度、点评量、租约或实勘证据；当前仅能视为初筛。', 16),
            $this->check('risk_recheck', '风险复核', $riskClear, '未出现高风险或严重延期标记', '先复核高风险项，明确重谈、放弃或补证动作。', 8),
            $this->check('task_owner_due_date', '责任人与时限', $taskPlanReady, '任务板已包含责任人、截止时间和状态', '为关键任务补齐责任人、截止日期和风险说明。', 8),
            $this->check('manual_review', '人工立项复核', $humanReviewReady, '已记录人工复核/审批状态', '补一条人工复核结论，明确推进、重谈、暂缓或放弃。', 8),
            $this->check('post_decision_tracking', '后续执行跟踪', $trackingReady, '已关联执行、开业或跟踪记录', '关联开业项目、运营执行或投后跟踪记录，避免立项后断链。', 7),
        ];

        $missingEvidence = [];
        foreach ($checks as $check) {
            if (!$check['passed']) {
                $missingEvidence[] = [
                    'code' => $check['key'],
                    'label' => $check['label'],
                    'next_action' => $check['next_action'],
                ];
            }
        }

        $stage = $this->stage(
            $marketReady,
            $benchmarkReady,
            $collaborationReady,
            $financialReady,
            $sourceBacked,
            $riskClear,
            $taskPlanReady,
            $humanReviewReady,
            $trackingReady
        );
        $score = 0;
        foreach ($checks as $check) {
            if ($check['passed']) {
                $score += (int)$check['weight'];
            }
        }

        return [
            'stage' => $stage,
            'status_label' => $this->stageLabel($stage),
            'score' => $score,
            'ready_for_review' => in_array($stage, ['review_ready', 'approved_pending_tracking', 'project_ready'], true),
            'project_ready' => $stage === 'project_ready',
            'record_type' => $recordType,
            'checks' => $checks,
            'missing_evidence' => $missingEvidence,
            'next_action' => $missingEvidence[0]['next_action'] ?? '进入人工立项复核，并保留执行跟踪证据。',
            'notice' => $this->stageNotice($stage),
        ];
    }

    public function summaryFromRows(array $rows): array
    {
        $summary = [
            'record_count' => 0,
            'stage_counts' => [],
            'review_ready_count' => 0,
            'project_ready_count' => 0,
            'best_score' => 0,
            'best_stage' => '',
            'best_status_label' => '',
            'missing_evidence' => [],
        ];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $readiness = $this->build(
                (string)($row['record_type'] ?? ''),
                $this->decodeJson($row['input_json'] ?? ''),
                $this->decodeJson($row['result_json'] ?? '')
            );
            $summary['record_count']++;
            $stage = (string)$readiness['stage'];
            $summary['stage_counts'][$stage] = (int)($summary['stage_counts'][$stage] ?? 0) + 1;
            if (($readiness['ready_for_review'] ?? false) === true) {
                $summary['review_ready_count']++;
            }
            if (($readiness['project_ready'] ?? false) === true) {
                $summary['project_ready_count']++;
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

    private function marketResult(string $recordType, array $input, array $result): array
    {
        if ($recordType === 'market') {
            return $result;
        }

        foreach ([$input, $result] as $payload) {
            foreach (['market_result', 'market_evaluation_result', 'market'] as $key) {
                if (is_array($payload[$key] ?? null)) {
                    return $payload[$key];
                }
            }
        }

        return [];
    }

    private function benchmarkResult(string $recordType, array $input, array $result): array
    {
        if ($recordType === 'benchmark') {
            return $result;
        }

        foreach ([$input, $result] as $payload) {
            foreach (['benchmark_result', 'benchmark_model_result', 'benchmark'] as $key) {
                if (is_array($payload[$key] ?? null)) {
                    return $payload[$key];
                }
            }
        }

        return [];
    }

    private function collaborationResult(string $recordType, array $input, array $result): array
    {
        if ($recordType === 'collaboration') {
            return $result;
        }

        foreach ([$input, $result] as $payload) {
            foreach (['collaboration_result', 'collaboration_efficiency_result', 'collaboration'] as $key) {
                if (is_array($payload[$key] ?? null)) {
                    return $payload[$key];
                }
            }
        }

        return [];
    }

    private function financialInputsReady(array $input, array $marketResult): bool
    {
        foreach (['property_area', 'estimated_rent', 'target_room_count', 'lease_years', 'fitout_budget', 'expected_adr', 'expected_occupancy_rate'] as $key) {
            if (!$this->hasPositiveValue($this->value($input, $marketResult, [$key]))) {
                return false;
            }
        }

        $rentFreeMonths = $this->value($input, $marketResult, ['rent_free_months']);
        return is_numeric($rentFreeMonths) && (float)$rentFreeMonths >= 0;
    }

    private function sourceEvidenceReady(array $input, array $marketResult, array $benchmarkInput, array $benchmarkResult): bool
    {
        foreach ([$input, $marketResult, $benchmarkInput, $benchmarkResult] as $payload) {
            foreach ([
                'source_evidence',
                'evidence',
                'evidence_files',
                'attachments',
                'competitor_samples',
                'competitor_sample_evidence',
                'field_visit_evidence',
                'lease_contract_evidence',
                'rent_contract_evidence',
                'ota_evidence',
                'review_sample_evidence',
                'sample_records',
                'diligence_evidence',
            ] as $key) {
                if ($this->hasNonEmptyEvidenceValue($payload[$key] ?? null)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasHighRisk(array $marketResult, array $collaborationResult): bool
    {
        $riskTexts = [
            (string)($marketResult['investment_risk_level'] ?? ''),
            (string)($marketResult['decision'] ?? ''),
            (string)($collaborationResult['delay_risk']['level'] ?? ''),
        ];
        $score = $marketResult['market_heat_score'] ?? null;
        if (is_numeric($score) && (int)$score < 60) {
            return true;
        }

        foreach ($riskTexts as $text) {
            if ($this->containsAny($text, ['高风险', '不建议']) || in_array(strtoupper(trim($text)), ['D', 'E'], true)) {
                return true;
            }
        }

        foreach ((array)($collaborationResult['delay_risk']['points'] ?? []) as $point) {
            if ($this->containsAny((string)$point, ['高风险', '逾期', '已过期'])) {
                return true;
            }
        }

        return false;
    }

    private function taskPlanReady(array $collaborationResult): bool
    {
        $tasks = is_array($collaborationResult['task_board'] ?? null) ? $collaborationResult['task_board'] : [];
        if (empty($tasks)) {
            return false;
        }

        foreach ($tasks as $task) {
            if (!is_array($task)) {
                return false;
            }
            if (($task['is_observed'] ?? false) !== true
                || (string)($task['source'] ?? '') === 'rule_template'
                || (string)($task['evidence_status'] ?? '') === 'unconfirmed_template'
            ) {
                return false;
            }
            $name = trim((string)($task['name'] ?? ''));
            $status = trim((string)($task['status'] ?? ''));
            $owner = trim((string)($task['owner'] ?? ''));
            $dueDate = trim((string)($task['due_date'] ?? ''));
            if ($name === '' || $status === '' || $owner === '' || $dueDate === '') {
                return false;
            }
            if ($this->containsAny($owner, ['待分配', '未分配', 'TBD'])) {
                return false;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
                return false;
            }
        }

        return true;
    }

    private function hasHumanReviewApproval(array $input, array $result): bool
    {
        foreach ([$input, $result] as $payload) {
            foreach (['review_status', 'approval_status', 'decision_status', 'manual_review_status', 'project_review_status'] as $key) {
                $value = strtolower(trim((string)($payload[$key] ?? '')));
                if (in_array($value, ['approved', 'reviewed', 'passed', '通过', '已复核', '已审批', '立项通过'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasPostDecisionTracking(array $input, array $result): bool
    {
        foreach ([$input, $result] as $payload) {
            foreach (['opening_project_id', 'operation_execution_intent_id', 'execution_intent_id', 'tracking_record_id', 'post_decision_tracking_id'] as $key) {
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

    private function stage(
        bool $marketReady,
        bool $benchmarkReady,
        bool $collaborationReady,
        bool $financialReady,
        bool $sourceBacked,
        bool $riskClear,
        bool $taskPlanReady,
        bool $humanReviewReady,
        bool $trackingReady
    ): string {
        if (!$marketReady && !$benchmarkReady && !$collaborationReady) {
            return 'screening_missing';
        }
        if (!$riskClear) {
            return 'risk_recheck_required';
        }
        if (!$marketReady || !$benchmarkReady) {
            return 'screening_record_only';
        }
        if (!$collaborationReady) {
            return 'partial_screening';
        }
        if (!$financialReady || !$sourceBacked || !$taskPlanReady) {
            return 'diligence_required';
        }
        if (!$humanReviewReady) {
            return 'review_ready';
        }
        if (!$trackingReady) {
            return 'approved_pending_tracking';
        }

        return 'project_ready';
    }

    private function stageLabel(string $stage): string
    {
        return [
            'screening_missing' => '未形成筛选',
            'screening_record_only' => '仅单点筛选',
            'partial_screening' => '筛选未闭合',
            'risk_recheck_required' => '需风险复核',
            'diligence_required' => '需补立项证据',
            'review_ready' => '可进入人工复核',
            'approved_pending_tracking' => '已复核待跟踪',
            'project_ready' => '立项闭环就绪',
        ][$stage] ?? $stage;
    }

    private function stageNotice(string $stage): string
    {
        return [
            'screening_missing' => '当前还没有可复核的扩张筛选结果。',
            'screening_record_only' => '当前仅有单点筛选记录，不能替代完整立项判断。',
            'partial_screening' => '市场与标杆已部分形成，但尚未落到协同任务和责任人。',
            'risk_recheck_required' => '存在显式高风险或否决信号，需先复核再继续推进。',
            'diligence_required' => '筛选和任务已形成，但缺少财务假设、样本证据或责任期限。',
            'review_ready' => '核心证据已具备，可进入人工立项复核；尚不等同于已审批。',
            'approved_pending_tracking' => '已有人工复核痕迹，但还缺开业、执行或投后跟踪记录。',
            'project_ready' => '已有筛选、证据、复核和跟踪记录，可视为立项闭环就绪。',
        ][$stage] ?? '';
    }

    private function check(string $key, string $label, bool $passed, string $evidence, string $nextAction, int $weight): array
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

    private function sourceEvidenceText(array $input, array $marketResult, array $benchmarkResult): string
    {
        foreach ([$input, $marketResult, $benchmarkResult] as $payload) {
            foreach (['source_evidence', 'evidence', 'evidence_files', 'attachments', 'competitor_samples', 'sample_records', 'diligence_evidence'] as $key) {
                if ($this->hasNonEmptyEvidenceValue($payload[$key] ?? null)) {
                    return '已记录来源证据、样本说明或附件';
                }
            }
        }

        return '尚未记录可追溯来源证据';
    }

    private function value(array $input, array $result, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $input)) {
                return $input[$key];
            }
            if (array_key_exists($key, $result)) {
                return $result[$key];
            }
        }

        return null;
    }

    private function hasPositiveValue(mixed $value): bool
    {
        return is_numeric($value) && (float)$value > 0;
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

    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
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
        if (in_array($text, ['1', 'true', 'yes', 'on', '是', '有', '齐全', '完整'], true)) {
            return true;
        }
        if (in_array($text, ['0', 'false', 'no', 'off', '否', '无', '不齐全', '缺失'], true)) {
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
