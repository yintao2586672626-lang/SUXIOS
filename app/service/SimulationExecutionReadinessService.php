<?php
declare(strict_types=1);

namespace app\service;

class SimulationExecutionReadinessService
{
    public function buildStrategyReadiness(
        array $input,
        array $scores,
        array $recommendation,
        array $risk,
        array $dataSnapshot,
        array $executionBridgeProjection = []
    ): array
    {
        $totalScore = (int)($scores['total_score'] ?? 0);
        $decision = trim((string)($recommendation['decision'] ?? ''));
        $coreReady = $totalScore > 0 && $decision !== '';
        $sourceReady = $this->hasStrategySourceEvidence($input, $recommendation, $risk, $dataSnapshot);
        $riskClear = !$this->isHighRisk((string)($risk['risk_level'] ?? ''), $totalScore);
        $explanationReady = trim((string)($recommendation['decision_direction'] ?? '')) !== ''
            || is_array($recommendation['ai_evaluation'] ?? null);
        $humanReviewReady = $this->hasHumanReviewApproval([$input, $recommendation, $risk, $dataSnapshot]);
        $executionReady = $this->hasExecutionTracking([$executionBridgeProjection], 'strategy_simulation');

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

    public function buildQuantReadiness(
        array $input,
        array $result,
        array $scenarios,
        array $riskHints,
        array $executionBridgeProjection = []
    ): array
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
        $executionReady = $this->hasExecutionTracking([$executionBridgeProjection], 'quant_simulation');

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
        $sourceInput = is_array($record['input'] ?? null) ? $record['input'] : [];
        $input = $this->withTopLevelExecutionBridge($sourceInput, $record);
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
        $readiness = $this->buildStrategyReadiness(
            $input,
            $scores,
            $recommendation,
            $risk,
            $dataSnapshot,
            $this->verifiedExecutionBridgeProjection($record, 'strategy_simulation')
        );
        $readyForIntent = $this->canCreateSimulationExecutionIntent($readiness);
        $recordId = (int)($record['id'] ?? $record['record_id'] ?? 0);
        $projectName = trim((string)($record['project_name'] ?? $input['project_name'] ?? ''));
        $executionDates = $this->executionIntentDates($overrides);
        $sourceHotelId = $this->strategyExecutionHotelId($record);
        $this->assertRequestedHotelMatchesSource($overrides, $sourceHotelId, 'strategy');

        $payload = [
            'source_module' => 'strategy_simulation',
            'source_record_id' => $recordId,
            'hotel_id' => $sourceHotelId,
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
                'source_record_digest' => $this->sourceRecordDigest('strategy_simulation', [
                    'id' => $recordId,
                    'project_name' => $projectName,
                    'input' => $sourceInput,
                    'scores' => $scores,
                    'recommendation' => $recommendation,
                    'risk' => $risk,
                    'data_snapshot' => $dataSnapshot,
                ]),
            ]),
            'expected_metric' => 'strategy_simulation_closure',
            'expected_delta' => 0,
            'risk_level' => $this->executionRiskLevel((string)($risk['risk_level'] ?? $record['risk_level'] ?? ''), $readyForIntent),
            'status' => 'pending_approval',
        ];
        $payload['evidence']['simulation_payload_digest'] = $this->simulationPayloadDigest($payload);

        return $payload;
    }

    /** @param array<string, mixed> $record */
    public function strategyExecutionHotelId(array $record): int
    {
        return $this->executionHotelId($record, 'strategy');
    }

    /** @param array<string, mixed> $record */
    public function quantExecutionHotelId(array $record): int
    {
        return $this->executionHotelId($record, 'quant');
    }

    public function buildQuantExecutionIntentInput(array $record, array $overrides = []): array
    {
        $businessSnapshot = $this->quantExecutionBusinessSnapshot($record);
        $sourceInput = $businessSnapshot['input'];
        $input = $this->withTopLevelExecutionBridge($sourceInput, $record);
        $result = $businessSnapshot['result'];
        $scenarios = $businessSnapshot['scenarios'];
        $riskHints = $businessSnapshot['risk_hints'];
        $readiness = $this->buildQuantReadiness(
            $input,
            $result,
            $scenarios,
            $riskHints,
            $this->verifiedExecutionBridgeProjection($record, 'quant_simulation')
        );
        $readyForIntent = $this->canCreateSimulationExecutionIntent($readiness);
        $recordId = $businessSnapshot['id'];
        $projectName = trim((string)($businessSnapshot['project_name'] ?? $input['projectName'] ?? $input['project_name'] ?? ''));
        $executionDates = $this->executionIntentDates($overrides);
        $sourceHotelId = $this->executionHotelId($businessSnapshot, 'quant');
        $this->assertRequestedHotelMatchesSource($overrides, $sourceHotelId, 'quant');

        $payload = [
            'source_module' => 'quant_simulation',
            'source_record_id' => $recordId,
            'hotel_id' => $sourceHotelId,
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
                'source_record_digest' => $this->sourceRecordDigest('quant_simulation', $businessSnapshot),
            ]),
            'expected_metric' => 'quant_simulation_closure',
            'expected_delta' => 0,
            'risk_level' => $this->executionRiskLevel((string)($result['riskLevel'] ?? $record['risk_level'] ?? ''), $readyForIntent),
            'status' => 'pending_approval',
        ];
        $payload['evidence']['simulation_payload_digest'] = $this->simulationPayloadDigest($payload);

        return $payload;
    }

    /**
     * Exact quant business snapshot used by both detail-based creation and
     * approval-time database readback. Only `metric_truth` is removed from a
     * scenario because QuantSimulationService adds that presentation
     * projection after decoding scenarios_json; every persisted business
     * field remains part of the digest.
     *
     * @param array<string, mixed> $record
     * @return array{id:int,project_name:string,input:array<string,mixed>,result:array<string,mixed>,scenarios:array<int,mixed>,risk_hints:array<int|string,mixed>}
     */
    public function quantExecutionBusinessSnapshot(array $record): array
    {
        $scenarios = is_array($record['scenarios'] ?? null) ? $record['scenarios'] : [];
        foreach ($scenarios as $index => $scenario) {
            if (!is_array($scenario)) {
                continue;
            }
            unset($scenario['metric_truth']);
            $scenarios[$index] = $scenario;
        }

        return [
            'id' => (int)($record['id'] ?? $record['record_id'] ?? 0),
            'project_name' => (string)($record['project_name'] ?? ''),
            'input' => is_array($record['input'] ?? null) ? $record['input'] : [],
            'result' => is_array($record['result'] ?? null) ? $record['result'] : [],
            'scenarios' => $scenarios,
            'risk_hints' => is_array($record['risk_hints'] ?? null) ? $record['risk_hints'] : [],
        ];
    }

    /** @param array<string, mixed> $record */
    private function executionHotelId(array $record, string $recordType): int
    {
        $containers = [];
        foreach (['input', 'data_snapshot', 'result', 'truth_context', 'input_truth_context'] as $field) {
            if (is_array($record[$field] ?? null)) {
                $containers[] = $record[$field];
            }
        }

        $candidates = [];
        foreach ($containers as $container) {
            foreach (['hotel_id', 'system_hotel_id', 'target_hotel_id'] as $field) {
                $hotelId = (int)($container[$field] ?? 0);
                if ($hotelId > 0) {
                    $candidates[] = $hotelId;
                }
            }
        }
        $candidates = array_values(array_unique($candidates));
        if ($candidates === []) {
            throw new \InvalidArgumentException(
                $recordType . ' simulation source hotel scope is missing; create a new scoped simulation'
            );
        }
        if (count($candidates) !== 1) {
            throw new \InvalidArgumentException($recordType . ' simulation source hotel scope conflict');
        }

        return $candidates[0];
    }

    /** @param array<string, mixed> $overrides */
    private function assertRequestedHotelMatchesSource(array $overrides, int $sourceHotelId, string $recordType): void
    {
        $requestedHotelId = (int)($overrides['hotel_id'] ?? 0);
        if ($requestedHotelId > 0 && $requestedHotelId !== $sourceHotelId) {
            throw new \InvalidArgumentException($recordType . ' simulation hotel scope mismatch');
        }
    }

    /** @param array<string, mixed> $payload */
    public function simulationPayloadDigest(array $payload): string
    {
        $evidence = is_array($payload['evidence'] ?? null) ? $payload['evidence'] : [];
        if ((string)($payload['source_module'] ?? '') === 'quant_simulation') {
            $current = is_array($payload['current_value'] ?? null) ? $payload['current_value'] : [];
            $target = is_array($payload['target_value'] ?? null) ? $payload['target_value'] : [];

            return $this->stableDigest([
                'source_module' => 'quant_simulation',
                'source_record_id' => (int)($payload['source_record_id'] ?? 0),
                'hotel_id' => (int)($payload['hotel_id'] ?? 0),
                'platform' => (string)($payload['platform'] ?? ''),
                'object_type' => (string)($payload['object_type'] ?? ''),
                'action_type' => (string)($payload['action_type'] ?? ''),
                'date_start' => (string)($payload['date_start'] ?? ''),
                'date_end' => (string)($payload['date_end'] ?? ''),
                'current_value' => [
                    'monthly_net_cashflow' => $current['monthly_net_cashflow'] ?? null,
                    'payback_months' => $current['payback_months'] ?? null,
                    'risk_level' => (string)($current['risk_level'] ?? ''),
                ],
                'target_value' => [
                    'project_name' => (string)($target['project_name'] ?? ''),
                    'target_metric' => (string)($target['target_metric'] ?? ''),
                    'action_text' => (string)($target['action_text'] ?? ''),
                ],
                'source_record_digest' => strtolower(trim((string)($evidence['source_record_digest'] ?? ''))),
                'expected_metric' => (string)($payload['expected_metric'] ?? ''),
                'expected_delta' => $payload['expected_delta'] ?? null,
                'risk_level' => (string)($payload['risk_level'] ?? ''),
            ]);
        }
        unset(
            $evidence['simulation_payload_digest'],
            $evidence['readiness_stage'],
            $evidence['readiness_score'],
            $evidence['readiness_missing_evidence'],
            $evidence['data_gaps']
        );
        $stable = [];
        foreach ([
            'source_module',
            'source_record_id',
            'hotel_id',
            'platform',
            'object_type',
            'action_type',
            'date_start',
            'date_end',
            'current_value',
            'target_value',
            'expected_metric',
            'expected_delta',
            'risk_level',
        ] as $field) {
            if (array_key_exists($field, $payload)) {
                $stable[$field] = $this->withoutExecutionBridgeTracking($payload[$field]);
            }
        }
        if (is_array($stable['current_value'] ?? null)) {
            unset($stable['current_value']['readiness_stage']);
        }
        $stable['evidence'] = $this->withoutExecutionBridgeTracking($evidence);

        return $this->stableDigest($stable);
    }

    /** @param array<string, mixed> $record */
    private function sourceRecordDigest(string $sourceModule, array $record): string
    {
        return $this->stableDigest([
            'source_module' => $sourceModule,
            'record' => $record,
        ]);
    }

    private function withoutExecutionBridgeTracking(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->withoutExecutionBridgeTracking($item), $value);
        }
        foreach ([
            'operation_execution_intent_id',
            'execution_intent_id',
            'execution_task_id',
            'opening_project_id',
            'tracking_record_id',
            'post_decision_tracking_id',
            'post_decision_tracking',
            'execution_tracking',
            'execution_bridge_status',
            'execution_status',
            'execution_idempotency_key',
        ] as $field) {
            unset($value[$field]);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->withoutExecutionBridgeTracking($item);
        }

        return $value;
    }

    private function stableDigest(mixed $value): string
    {
        return hash('sha256', json_encode(
            $this->canonicalizeDigestValue($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        ) ?: '{}');
    }

    private function canonicalizeDigestValue(mixed $value): mixed
    {
        if (is_int($value)) {
            return ['__number' => (string)$value];
        }
        if (is_float($value)) {
            $number = is_finite($value) ? sprintf('%.15G', $value) : (string)$value;
            if (is_finite($value) && floor($value) === $value && abs($value) <= PHP_INT_MAX) {
                $number = sprintf('%.0F', $value);
            }

            return ['__number' => $number];
        }
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalizeDigestValue($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalizeDigestValue($item);
        }

        return $value;
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
        foreach ([
            'operation_execution_intent_id',
            'execution_intent_id',
            'execution_task_id',
            'opening_project_id',
            'tracking_record_id',
            'post_decision_tracking_id',
            'investment_tracking_id',
            'post_decision_tracking',
            'execution_tracking',
            'execution_bridge_status',
        ] as $key) {
            unset($input[$key]);
        }

        $projection = $this->verifiedExecutionBridgeProjection($record);
        if ($projection !== []) {
            foreach (['operation_execution_intent_id', 'execution_intent_id', 'execution_bridge_status', 'execution_tracking'] as $key) {
                $input[$key] = $projection[$key];
            }
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

    private function hasExecutionTracking(array $payloads, string $expectedSourceModule): bool
    {
        foreach ($payloads as $payload) {
            if (is_array($payload) && $this->verifiedExecutionBridgeProjection($payload, $expectedSourceModule) !== []) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,mixed> */
    private function verifiedExecutionBridgeProjection(array $payload, string $expectedSourceModule = ''): array
    {
        $intentId = (int)($payload['execution_intent_id'] ?? 0);
        $operationIntentId = (int)($payload['operation_execution_intent_id'] ?? 0);
        $tracking = is_array($payload['execution_tracking'] ?? null) ? $payload['execution_tracking'] : [];
        $sourceModule = SourceBackedExecutionIntentIdentityService::canonicalSourceModule($tracking['source_module'] ?? null);
        $expectedSourceModule = SourceBackedExecutionIntentIdentityService::canonicalSourceModule($expectedSourceModule);
        $sourceRecordId = (int)($tracking['source_record_id'] ?? 0);
        $recordId = (int)($payload['id'] ?? $payload['record_id'] ?? 0);
        $status = strtolower(trim((string)($tracking['status'] ?? '')));

        if ($intentId <= 0
            || $operationIntentId !== $intentId
            || (int)($tracking['intent_id'] ?? 0) !== $intentId
            || ($tracking['_source_bridge_verified'] ?? false) !== true
            || (string)($payload['execution_bridge_status'] ?? '') !== 'linked'
            || !in_array($sourceModule, ['strategy_simulation', 'quant_simulation'], true)
            || ($expectedSourceModule !== '' && $sourceModule !== $expectedSourceModule)
            || $sourceRecordId <= 0
            || ($recordId > 0 && $recordId !== $sourceRecordId)
            || (int)($tracking['hotel_id'] ?? 0) <= 0
            || (int)($tracking['tenant_id'] ?? 0) <= 0
            || !in_array($status, ['draft', 'pending_approval', 'approved'], true)
        ) {
            return [];
        }

        return [
            'execution_intent_id' => $intentId,
            'operation_execution_intent_id' => $intentId,
            'execution_bridge_status' => 'linked',
            'execution_tracking' => $tracking,
        ];
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
