<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;
use Throwable;

class QuantSimulationService
{
    private LlmClient $client;
    private AiDecisionQualityService $decisionQualityService;
    private bool $tableEnsured = false;

    public function __construct(?LlmClient $client = null, ?AiDecisionQualityService $decisionQualityService = null)
    {
        $this->client = $client ?: new LlmClient();
        $this->decisionQualityService = $decisionQualityService ?? new AiDecisionQualityService();
    }

    public function calculateAndSave(array $payload, int $userId): array
    {
        $this->ensureTable();

        $input = $this->normalizeInput($payload['input'] ?? $payload);
        $projectName = trim((string)($payload['project_name'] ?? $payload['projectName'] ?? '量化模拟项目'));
        if ($projectName === '') {
            $projectName = '量化模拟项目';
        }

        $result = $this->calculateSimulation($input);
        $scenarios = $this->buildScenarios($input);
        $riskHints = $this->buildRiskHints($result);
        $modelKey = trim((string)($payload['model_key'] ?? $payload['modelKey'] ?? 'deepseek_v4_default'));
        if ($modelKey === '') {
            $modelKey = 'deepseek_v4_default';
        }
        $modelAnalysis = $this->buildModelAnalysis($input, $result, $scenarios, $riskHints, $modelKey);
        $result['modelAnalysis'] = $modelAnalysis;
        $now = date('Y-m-d H:i:s');

        $id = (int)Db::name('quant_simulation_records')->insertGetId([
            'tenant_id' => $this->tenantIdForUser($userId),
            'project_name' => $projectName,
            'input_json' => json_encode($input, JSON_UNESCAPED_UNICODE),
            'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE),
            'scenarios_json' => json_encode($scenarios, JSON_UNESCAPED_UNICODE),
            'risk_hints_json' => json_encode($riskHints, JSON_UNESCAPED_UNICODE),
            'monthly_net_cashflow' => (float)$result['monthlyNetCashflow'],
            'payback_months' => $result['paybackMonths'],
            'risk_level' => (string)$result['riskLevel'],
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->detail($id, $userId, true);
    }

    public function records(int $userId, bool $isSuperAdmin): array
    {
        $this->ensureTable();

        $query = Db::name('quant_simulation_records')->whereNull('deleted_at');
        $this->applyTenantScope($query, $userId, $isSuperAdmin);
        if (!$isSuperAdmin) {
            $query->where('created_by', $userId);
        }

        $rows = $query->order('id', 'desc')->limit(30)->select()->toArray();
        $list = array_values(array_map(fn(array $row): array => $this->formatRecord($row, false), $rows));

        return (new SimulationExecutionBridgeService())->attachToRecords($list, 'quant_simulation');
    }

    public function detail(int $id, int $userId, bool $isSuperAdmin): array
    {
        $this->ensureTable();

        $query = Db::name('quant_simulation_records')->where('id', $id)->whereNull('deleted_at');
        $this->applyTenantScope($query, $userId, $isSuperAdmin);
        if (!$isSuperAdmin) {
            $query->where('created_by', $userId);
        }

        $row = $query->find();
        if (!$row) {
            throw new \RuntimeException('量化模拟记录不存在或无权访问');
        }

        $record = $this->formatRecord($row, true);

        return (new SimulationExecutionBridgeService())->attachToRecord($record, 'quant_simulation');
    }

    public function archive(int $id, int $userId, bool $isSuperAdmin): bool
    {
        $this->ensureTable();

        $query = Db::name('quant_simulation_records')->where('id', $id)->whereNull('deleted_at');
        $this->applyTenantScope($query, $userId, $isSuperAdmin);
        if (!$isSuperAdmin) {
            $query->where('created_by', $userId);
        }

        $now = date('Y-m-d H:i:s');
        $affected = $query->update([
            'deleted_at' => $now,
            'updated_at' => $now,
        ]);
        if ((int)$affected <= 0) {
            throw new \RuntimeException('量化模拟记录不存在或无权访问');
        }

        return true;
    }

    public function ensureTable(): void
    {
        if ($this->tableEnsured) {
            return;
        }

        DatabaseSchemaRequirement::assertTableColumns('quant_simulation_records', [
            'id', 'tenant_id', 'project_name', 'input_json', 'result_json', 'scenarios_json',
            'risk_hints_json', 'monthly_net_cashflow', 'payback_months', 'risk_level',
            'created_by', 'created_at', 'updated_at', 'deleted_at',
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
        } catch (Throwable $e) {
            return null;
        }
    }

    private function calculateSimulation(array $input): array
    {
        $roomCount = (float)$input['roomCount'];
        $revenue = $this->calculateRevenueSummary($input);
        $adr = (float)$revenue['adr'];
        $occupancyRate = $this->percentToDecimal((float)$revenue['occupancyRate']);
        $otaRate = $this->percentToDecimal((float)$input['otaCommissionRate']);
        $availableRoomNights = (float)$revenue['availableRoomNights'];
        $roomRevenue = (float)$revenue['roomRevenue'];
        $monthlyRevenue = (float)$revenue['monthlyRevenue'];
        $otaCommission = $roomRevenue * $otaRate;
        $fixedMonthlyCost =
            (float)$input['monthlyRent']
            + (float)$input['laborCost']
            + (float)$input['utilityCost']
            + (float)$input['consumableCost']
            + (float)$input['maintenanceCost']
            + (float)$input['otherFixedCost'];
        $monthlyCost = $fixedMonthlyCost + $otaCommission;
        $monthlyNetCashflow = $monthlyRevenue - $monthlyCost;
        $totalInvestment =
            (float)$input['decorationInvestment']
            + (float)$input['furnitureInvestment']
            + (float)$input['openingCost']
            + (float)$input['otherInvestment'];
        $revPAR = $adr * $occupancyRate;
        $paybackMonths = $monthlyNetCashflow > 0 ? $totalInvestment / $monthlyNetCashflow : null;
        $rentRatio = $monthlyRevenue > 0 ? (float)$input['monthlyRent'] / $monthlyRevenue : null;
        $breakEvenOccupancy = $this->calculateBreakEvenOccupancy(
            $fixedMonthlyCost,
            (float)$input['otherIncome'],
            $availableRoomNights,
            $adr,
            $otaRate
        );

        $result = [
            'availableRoomNights' => round($availableRoomNights, 2),
            'roomRevenue' => round($roomRevenue, 2),
            'monthlyRevenue' => round($monthlyRevenue, 2),
            'otaCommission' => round($otaCommission, 2),
            'monthlyCost' => round($monthlyCost, 2),
            'monthlyNetCashflow' => round($monthlyNetCashflow, 2),
            'totalInvestment' => round($totalInvestment, 2),
            'revPAR' => round($revPAR, 2),
            'paybackMonths' => $paybackMonths === null ? null : round($paybackMonths, 2),
            'rentRatio' => $rentRatio === null ? null : round($rentRatio, 4),
            'breakEvenOccupancy' => round($breakEvenOccupancy, 4),
            'riskLevel' => $this->calculateRiskLevel($monthlyNetCashflow, $paybackMonths, $rentRatio, $breakEvenOccupancy),
        ];

        if (isset(
            $input['valuation_date'],
            $input['currency'],
            $input['construction_cashflows'],
            $input['operation_cashflows'],
            $input['terminal_value']
        )) {
            $result = array_merge($result, $this->buildCashflowSeriesResult($input));
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function buildCashflowSeriesResult(array $input): array
    {
        $construction = array_values(array_map('floatval', (array)$input['construction_cashflows']));
        $operation = array_values(array_map('floatval', (array)$input['operation_cashflows']));
        $terminalValue = (float)$input['terminal_value'];
        $values = array_merge($construction, $operation);
        $lastPeriod = count($values) - 1;
        if ($lastPeriod >= 0) {
            $values[$lastPeriod] += $terminalValue;
        }

        $valuationDate = (string)$input['valuation_date'];
        $baseDate = new \DateTimeImmutable($valuationDate);
        $series = [];
        foreach ($values as $period => $value) {
            $series[] = [
                'period' => $period,
                'date' => $baseDate->modify('+' . $period . ' months')->format('Y-m-d'),
                'value' => round((float)$value, 2),
            ];
        }

        return [
            'valuation_date' => $valuationDate,
            'freshness_status' => $this->cashflowFreshnessStatus($baseDate),
            'currency' => (string)$input['currency'],
            'terminal_value' => $terminalValue,
            'construction_periods' => count($construction),
            'operation_periods' => count($operation),
            'cashflow_series' => $series,
        ];
    }

    private function cashflowFreshnessStatus(\DateTimeImmutable $valuationDate): string
    {
        $today = new \DateTimeImmutable('today');
        $cutoff = (new \DateTimeImmutable('first day of this month'))->modify('-12 months');
        return $valuationDate >= $cutoff && $valuationDate <= $today ? 'fresh' : 'stale';
    }

    private function calculateBreakEvenOccupancy(
        float $fixedMonthlyCost,
        float $otherIncome,
        float $availableRoomNights,
        float $adr,
        float $otaRate
    ): float {
        $requiredRoomMargin = max(0.0, $fixedMonthlyCost - $otherIncome);
        if ($requiredRoomMargin <= 0) {
            return 0.0;
        }

        $netRoomRevenuePerFullOccupancy = $availableRoomNights * $adr * max(0.0, 1 - $otaRate);
        if ($netRoomRevenuePerFullOccupancy <= 0) {
            return 1.0;
        }

        return $requiredRoomMargin / $netRoomRevenuePerFullOccupancy;
    }

    private function buildScenarios(array $input): array
    {
        return [
            $this->calculateScenario($input, '保守情景'),
            $this->calculateScenario($input, '基准情景'),
            $this->calculateScenario($input, '乐观情景'),
        ];
    }

    private function calculateScenario(array $input, string $scenarioType): array
    {
        $scenarioInput = $input;
        if ($scenarioType === '保守情景') {
            $scenarioInput['adr'] = max(1, (float)$input['adr'] - 20);
            $scenarioInput['occupancyRate'] = max(0, (float)$input['occupancyRate'] - 10);
            $scenarioInput['otherIncome'] = max(0, (float)$input['otherIncome'] - 1700);
            $this->adjustScenarioRevenueDetails($scenarioInput, -20, -10, (float)$scenarioInput['otherIncome']);
        } elseif ($scenarioType === '乐观情景') {
            $scenarioInput['adr'] = max(1, (float)$input['adr'] + 20);
            $scenarioInput['occupancyRate'] = min(100, (float)$input['occupancyRate'] + 8);
            $scenarioInput['otherIncome'] = max(0, (float)$input['otherIncome'] + 2000);
            $this->adjustScenarioRevenueDetails($scenarioInput, 20, 8, (float)$scenarioInput['otherIncome']);
        }

        return array_merge(['scenarioType' => $scenarioType], $this->calculateSimulation($scenarioInput));
    }

    private function calculateRevenueSummary(array $input): array
    {
        $roomCount = (float)$input['roomCount'];
        $totalDays = 0.0;
        $occupiedRoomNights = 0.0;
        $roomRevenue = 0.0;

        foreach ($this->roomRevenueSegments() as $segment) {
            $days = max(0.0, (float)($input[$segment['daysKey']] ?? 0));
            $adr = max(0.0, (float)($input[$segment['adrKey']] ?? 0));
            $occupancy = $this->percentToDecimal($this->clamp((float)($input[$segment['occupancyKey']] ?? 0), 0, 100));
            $totalDays += $days;
            $occupiedRoomNights += $roomCount * $days * $occupancy;
            $roomRevenue += $roomCount * $days * $occupancy * $adr;
        }

        if ($totalDays <= 0) {
            $totalDays = 30.0;
            $occupiedRoomNights = $roomCount * $totalDays * $this->percentToDecimal((float)$input['occupancyRate']);
            $roomRevenue = $occupiedRoomNights * (float)$input['adr'];
        }

        $availableRoomNights = $roomCount * $totalDays;
        $adr = $occupiedRoomNights > 0 ? $roomRevenue / $occupiedRoomNights : (float)$input['adr'];
        $occupancyRate = $availableRoomNights > 0 ? $occupiedRoomNights / $availableRoomNights * 100 : (float)$input['occupancyRate'];
        $otherIncome = array_sum(array_map(
            fn(array $field): float => (float)($input[$field['key']] ?? 0),
            $this->otherIncomeFields()
        ));

        return [
            'totalDays' => round($totalDays, 2),
            'availableRoomNights' => round($availableRoomNights, 2),
            'occupiedRoomNights' => round($occupiedRoomNights, 2),
            'roomRevenue' => round($roomRevenue, 2),
            'otherIncome' => round($otherIncome, 2),
            'monthlyRevenue' => round($roomRevenue + $otherIncome, 2),
            'adr' => round($adr, 2),
            'occupancyRate' => round($occupancyRate, 2),
        ];
    }

    private function adjustScenarioRevenueDetails(array &$input, float $adrDelta, float $occupancyDelta, float $targetOtherIncome): void
    {
        foreach ($this->roomRevenueSegments() as $segment) {
            $input[$segment['adrKey']] = max(1.0, (float)($input[$segment['adrKey']] ?? 0) + $adrDelta);
            $input[$segment['occupancyKey']] = $this->clamp((float)($input[$segment['occupancyKey']] ?? 0) + $occupancyDelta, 0, 100);
        }

        $fields = $this->otherIncomeFields();
        $currentOtherIncome = array_sum(array_map(
            fn(array $field): float => (float)($input[$field['key']] ?? 0),
            $fields
        ));
        $targetOtherIncome = max(0.0, $targetOtherIncome);
        if ($currentOtherIncome > 0) {
            $ratio = $targetOtherIncome / $currentOtherIncome;
            foreach ($fields as $field) {
                $input[$field['key']] = round((float)($input[$field['key']] ?? 0) * $ratio, 2);
            }
            return;
        }

        foreach ($fields as $index => $field) {
            $input[$field['key']] = $index === 0 ? round($targetOtherIncome, 2) : 0.0;
        }
    }

    private function buildRiskHints(array $result): array
    {
        $rentRatio = isset($result['rentRatio']) && is_numeric($result['rentRatio'])
            ? (float)$result['rentRatio']
            : null;
        $rentRisk = $rentRatio === null
            ? ['riskLevel' => '高风险', 'content' => '月收入为0，租金占比不可计算，不能按0%判断租金安全。', 'className' => 'bg-red-50 border-red-100 text-red-800']
            : ($rentRatio >= 0.4
                ? ['riskLevel' => '高风险', 'content' => '租金占比超过40%，需压降租金或提高ADR。', 'className' => 'bg-red-50 border-red-100 text-red-800']
                : ($rentRatio >= 0.3
                    ? ['riskLevel' => '中高风险', 'content' => '租金占比超过30%，需持续压降租金或提高ADR。', 'className' => 'bg-orange-50 border-orange-100 text-orange-800']
                    : ['riskLevel' => '低风险', 'content' => '租金占比处于相对可控区间。', 'className' => 'bg-green-50 border-green-100 text-green-800']));

        $paybackRisk = $result['paybackMonths'] === null
            ? ['riskLevel' => '高风险', 'content' => '当前月净现金流为负，项目暂不可回本。', 'className' => 'bg-red-50 border-red-100 text-red-800']
            : ($result['paybackMonths'] <= 18
                ? ['riskLevel' => '低风险', 'content' => '回本周期可控。', 'className' => 'bg-green-50 border-green-100 text-green-800']
                : ($result['paybackMonths'] <= 30
                    ? ['riskLevel' => '中风险', 'content' => '回本周期偏长，需关注现金流稳定性。', 'className' => 'bg-yellow-50 border-yellow-100 text-yellow-800']
                    : ['riskLevel' => '高风险', 'content' => '回本周期过长，需重新评估投资规模。', 'className' => 'bg-red-50 border-red-100 text-red-800']));

        $breakEvenRisk = $result['breakEvenOccupancy'] >= 0.65
            ? ['riskLevel' => '高风险', 'content' => '保本入住率过高，需要长期高入住才能盈利。', 'className' => 'bg-red-50 border-red-100 text-red-800']
            : ($result['breakEvenOccupancy'] >= 0.55
                ? ['riskLevel' => '中风险', 'content' => '保本入住率偏高，需持续跟踪淡季入住。', 'className' => 'bg-yellow-50 border-yellow-100 text-yellow-800']
                : ['riskLevel' => '低风险', 'content' => '保本入住率处于相对安全区间。', 'className' => 'bg-green-50 border-green-100 text-green-800']);

        return [
            array_merge(['title' => '租金压力'], $rentRisk),
            array_merge(['title' => '回本周期'], $paybackRisk),
            array_merge(['title' => '保本边界'], $breakEvenRisk),
        ];
    }

    private function buildModelAnalysis(array $input, array $result, array $scenarios, array $riskHints, string $modelKey): array
    {
        $messages = [
            [
                'role' => 'system',
                'content' => '你是酒店投资量化模拟分析师。只输出符合 schema 的 JSON。必须基于用户输入、本地公式结果和三情景结果生成经营解读；不得改写或发明财务数字；缺少真实经营数据时明确写入 assumptions；建议必须可执行、克制、面向投决复核。',
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'input' => $input,
                    'deterministic_result' => $result,
                    'scenarios' => $scenarios,
                    'formula_risk_hints' => $riskHints,
                    'report_language' => 'zh-CN',
                ], JSON_UNESCAPED_UNICODE),
            ],
        ];

        try {
            $analysis = $this->client->createJsonResponse($messages, $this->modelAnalysisSchema(), $modelKey);
            return $this->normalizeModelAnalysis($analysis, [
                'source' => 'llm',
                'model_key' => $modelKey,
                'generated_at' => date('Y-m-d H:i:s'),
                'decision_quality_context' => $this->quantDecisionQualityContext($input, $result, $scenarios, $riskHints),
            ]);
        } catch (Throwable $e) {
            return $this->buildFallbackModelAnalysis($input, $result, $scenarios, $riskHints, $modelKey, $e->getMessage());
        }
    }

    private function buildFallbackModelAnalysis(array $input, array $result, array $scenarios, array $riskHints, string $modelKey, string $reason): array
    {
        $basePayback = $result['paybackMonths'] ?? null;
        $paybackText = $basePayback === null ? '暂不可回本' : round((float)$basePayback, 1) . '个月';
        $riskLevel = (string)($result['riskLevel'] ?? '中风险');
        $netCashflow = (float)($result['monthlyNetCashflow'] ?? 0);
        $rentRatio = isset($result['rentRatio']) && is_numeric($result['rentRatio'])
            ? (float)$result['rentRatio']
            : null;
        $breakEven = (float)($result['breakEvenOccupancy'] ?? 0);

        $decision = ($riskLevel === '高风险' || $netCashflow <= 0)
            ? '暂缓推进，先复核租金、ADR、入住率和投资规模。'
            : '可进入下一轮复核，重点校验核心经营假设。';

        $scenarioSummary = array_map(
            fn(array $row): string => (string)($row['scenarioType'] ?? '-') . '净现金流' . round((float)($row['monthlyNetCashflow'] ?? 0), 2),
            $scenarios
        );

        return $this->normalizeModelAnalysis([
            'source' => 'fallback',
            'model_key' => $modelKey,
            'generated_at' => date('Y-m-d H:i:s'),
            'summary' => '本地量化结果显示，基准情景月净现金流为' . round($netCashflow, 2) . '元，回本周期为' . $paybackText . '，综合风险为' . $riskLevel . '。',
            'decision' => $decision,
            'recommendations' => [
                [
                    'priority' => 'P0',
                    'title' => '复核核心假设',
                    'detail' => '逐项校验ADR、入住率、月租金、OTA佣金率和总投资，避免单一乐观假设推动投决。',
                ],
                [
                    'priority' => 'P1',
                    'title' => '压测保守情景',
                    'detail' => '以保守情景作为底线，确认现金流、回本周期和保本入住率仍在可承受范围内。',
                ],
                [
                    'priority' => 'P2',
                    'title' => '补齐经营样本',
                    'detail' => '接入近期日报、OTA订单、竞品价格和商圈客源数据后重新测算。',
                ],
            ],
            'watch_points' => [
                [
                    'metric' => '月净现金流',
                    'threshold' => '连续为正且覆盖租金、人工和OTA佣金波动',
                    'action' => '若低于安全垫，优先压降租金或缩减装修投入。',
                ],
                [
                    'metric' => '租金占比',
                    'threshold' => $rentRatio === null ? '不可计算（月收入为0）' : round($rentRatio * 100, 1) . '%',
                    'action' => '超过30%需重谈租金或提高ADR，超过40%按高风险处理。',
                ],
                [
                    'metric' => '保本入住率',
                    'threshold' => round($breakEven * 100, 1) . '%',
                    'action' => '高于55%需补充淡季入住率和竞品供给验证。',
                ],
            ],
            'assumptions' => array_values(array_unique(array_merge(
                array_map(fn(array $hint): string => (string)($hint['title'] ?? '') . '：' . (string)($hint['content'] ?? ''), $riskHints),
                [
                    '模型解读未生成，已使用本地规则兜底：' . mb_substr(trim($reason), 0, 120),
                    '本次未引入真实经营复核数据，投决前需补齐经营、OTA和竞品样本。',
                    '三情景现金流参考：' . implode('；', $scenarioSummary),
                ]
            ))),
            'error' => mb_substr(trim($reason), 0, 120),
        ], [
            'decision_quality_context' => $this->quantDecisionQualityContext($input, $result, $scenarios, $riskHints),
        ]);
    }

    private function modelAnalysisSchema(): array
    {
        return [
            'x-governance' => [
                'module' => 'simulation',
                'scenario' => 'quant_model_analysis',
                'prompt_version' => 'simulation.quant_model_analysis.v1',
                'decision_impact' => 'investment',
                'knowledge_sources' => ['input', 'deterministic_result', 'scenarios', 'formula_risk_hints'],
            ],
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['summary', 'decision', 'recommendations', 'watch_points', 'assumptions'],
            'properties' => [
                'summary' => ['type' => 'string'],
                'decision' => ['type' => 'string'],
                'recommendations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['priority', 'title', 'detail'],
                        'properties' => [
                            'priority' => ['type' => 'string', 'enum' => ['P0', 'P1', 'P2']],
                            'title' => ['type' => 'string'],
                            'detail' => ['type' => 'string'],
                        ],
                    ],
                ],
                'watch_points' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['metric', 'threshold', 'action'],
                        'properties' => [
                            'metric' => ['type' => 'string'],
                            'threshold' => ['type' => 'string'],
                            'action' => ['type' => 'string'],
                        ],
                    ],
                ],
                'assumptions' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }

    private function normalizeInput(array $raw): array
    {
        $this->requireNumericAlias($raw, ['roomCount', 'room_count'], 'roomCount', false);
        $input = [
            'roomCount' => $this->number($raw, 'roomCount', 'room_count'),
            'adr' => $this->number($raw, 'adr', 'adr'),
            'occupancyRate' => $this->number($raw, 'occupancyRate', 'occupancy_rate'),
            'otherIncome' => $this->number($raw, 'otherIncome', 'other_income'),
            'monthlyRent' => $this->number($raw, 'monthlyRent', 'monthly_rent'),
            'laborCost' => $this->number($raw, 'laborCost', 'labor_cost'),
            'utilityCost' => $this->number($raw, 'utilityCost', 'utility_cost'),
            'otaCommissionRate' => $this->number($raw, 'otaCommissionRate', 'ota_commission_rate'),
            'consumableCost' => $this->number($raw, 'consumableCost', 'consumable_cost'),
            'maintenanceCost' => $this->number($raw, 'maintenanceCost', 'maintenance_cost'),
            'otherFixedCost' => $this->number($raw, 'otherFixedCost', 'other_fixed_cost'),
        ];
        $input = array_merge(
            $input,
            $this->investmentGroup($raw, 'decorationInvestment', 'decoration_investment', [
                'decorationHardCost' => 'decoration_hard_cost',
                'decorationSoftCost' => 'decoration_soft_cost',
                'fireSafetyCost' => 'fire_safety_cost',
                'signageDesignCost' => 'signage_design_cost',
            ]),
            $this->investmentGroup($raw, 'furnitureInvestment', 'equipment_investment', [
                'roomFurnitureCost' => 'room_furniture_cost',
                'applianceEquipmentCost' => 'appliance_equipment_cost',
                'linenSuppliesCost' => 'linen_supplies_cost',
                'techSystemCost' => 'tech_system_cost',
            ]),
            $this->investmentGroup($raw, 'openingCost', 'pre_opening_cost', [
                'licensePermitCost' => 'license_permit_cost',
                'openingMarketingCost' => 'opening_marketing_cost',
                'recruitmentTrainingCost' => 'recruitment_training_cost',
                'openingMaterialCost' => 'opening_material_cost',
            ]),
            $this->investmentGroup($raw, 'otherInvestment', 'other_investment', [
                'contingencyCost' => 'contingency_cost',
                'rentDepositCost' => 'rent_deposit_cost',
                'otherProjectCost' => 'other_project_cost',
            ])
        );
        $input = array_merge($input, $this->roomRevenueGroup($raw, $input));
        $input = array_merge($input, $this->otherIncomeGroup($raw, $input));
        $input = array_merge(
            $input,
            $this->costGroup($raw, 'monthlyRent', 'monthly_rent', [
                'baseRentCost' => 'base_rent_cost',
                'propertyManagementCost' => 'property_management_cost',
            ]),
            $this->costGroup($raw, 'laborCost', 'labor_cost', [
                'frontDeskLaborCost' => 'front_desk_labor_cost',
                'housekeepingLaborCost' => 'housekeeping_labor_cost',
                'managementLaborCost' => 'management_labor_cost',
                'socialSecurityCost' => 'social_security_cost',
            ]),
            $this->costGroup($raw, 'utilityCost', 'utility_cost', [
                'electricityCost' => 'electricity_cost',
                'waterGasCost' => 'water_gas_cost',
                'networkEnergyCost' => 'network_energy_cost',
            ]),
            $this->costGroup($raw, 'consumableCost', 'consumable_cost', [
                'roomConsumableCost' => 'room_consumable_cost',
                'cleaningSuppliesCost' => 'cleaning_supplies_cost',
                'linenReplacementCost' => 'linen_replacement_cost',
            ]),
            $this->costGroup($raw, 'maintenanceCost', 'maintenance_cost', [
                'routineRepairCost' => 'routine_repair_cost',
                'equipmentMaintenanceCost' => 'equipment_maintenance_cost',
                'roomRenovationReserve' => 'room_renovation_reserve',
            ]),
            $this->costGroup($raw, 'otherFixedCost', 'other_fixed_cost', [
                'marketingSystemCost' => 'marketing_system_cost',
                'insuranceTaxCost' => 'insurance_tax_cost',
                'adminMiscCost' => 'admin_misc_cost',
            ])
        );
        $input = array_merge($input, $this->otaCommissionGroup($raw, $input));
        $input = array_merge($input, $this->normalizeCashflowSeriesInput($raw));
        $revenue = $this->calculateRevenueSummary($input);
        $input['adr'] = (float)$revenue['adr'];
        $input['occupancyRate'] = (float)$revenue['occupancyRate'];
        $input['otherIncome'] = (float)$revenue['otherIncome'];
        $input['otaCommissionRate'] = $this->weightedOtaCommissionRate($input);

        if ($input['roomCount'] <= 0) {
            throw new \InvalidArgumentException('房间数必须大于0');
        }
        if ($input['adr'] <= 0) {
            throw new \InvalidArgumentException('ADR必须大于0');
        }
        $totalRevenueDays = 0.0;
        foreach ($this->roomRevenueSegments() as $segment) {
            $days = (float)$input[$segment['daysKey']];
            $occupancy = (float)$input[$segment['occupancyKey']];
            if ($days < 0) {
                throw new \InvalidArgumentException('客房收入天数不能为负数');
            }
            if ((float)$input[$segment['adrKey']] < 0) {
                throw new \InvalidArgumentException('客房ADR不能为负数');
            }
            if ($occupancy < 0 || $occupancy > 100) {
                throw new \InvalidArgumentException('客房入住率必须在0到100之间');
            }
            $totalRevenueDays += $days;
        }
        if ($totalRevenueDays <= 0 || $totalRevenueDays > 31) {
            throw new \InvalidArgumentException('客房收入天数必须在1到31天之间');
        }
        if ($input['occupancyRate'] < 0 || $input['occupancyRate'] > 100) {
            throw new \InvalidArgumentException('入住率必须在0到100之间');
        }
        $totalOtaShare = 0.0;
        foreach ($this->otaCommissionChannels() as $channel) {
            $share = (float)$input[$channel['shareKey']];
            $rate = (float)$input[$channel['rateKey']];
            if ($share < 0 || $share > 100) {
                throw new \InvalidArgumentException('渠道收入占比必须在0到100之间');
            }
            if ($rate < 0 || $rate > 100) {
                throw new \InvalidArgumentException('渠道佣金率必须在0到100之间');
            }
            $totalOtaShare += $share;
        }
        if ($totalOtaShare > 100) {
            throw new \InvalidArgumentException('渠道收入占比合计不能超过100%');
        }
        if ($input['otaCommissionRate'] < 0 || $input['otaCommissionRate'] > 100) {
            throw new \InvalidArgumentException('OTA佣金率必须在0到100之间');
        }
        foreach ($input as $key => $value) {
            if (!is_int($value) && !is_float($value)) {
                continue;
            }
            if (!in_array($key, ['occupancyRate', 'otaCommissionRate'], true) && $value < 0) {
                throw new \InvalidArgumentException('量化模拟输入不能为负数');
            }
        }

        return $input;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function normalizeCashflowSeriesInput(array $raw): array
    {
        $aliases = [
            'valuation_date' => ['valuation_date', 'valuationDate'],
            'currency' => ['currency'],
            'construction_cashflows' => ['construction_cashflows', 'constructionCashflows'],
            'operation_cashflows' => ['operation_cashflows', 'operationCashflows'],
            'terminal_value' => ['terminal_value', 'terminalValue'],
        ];
        $requested = false;
        foreach ($aliases as $keys) {
            foreach ($keys as $key) {
                if (array_key_exists($key, $raw)) {
                    $requested = true;
                    break 2;
                }
            }
        }
        if (!$requested) {
            return [];
        }

        $currency = strtoupper(trim((string)($raw['currency'] ?? '')));
        if ($currency === '') {
            throw new \InvalidArgumentException('Cashflow currency is required.');
        }
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new \InvalidArgumentException('Cashflow currency must be a three-letter ISO code.');
        }

        $valuationDate = trim((string)($raw['valuation_date'] ?? $raw['valuationDate'] ?? ''));
        $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $valuationDate);
        if (!$parsedDate || $parsedDate->format('Y-m-d') !== $valuationDate) {
            throw new \InvalidArgumentException('Cashflow valuation_date is required in YYYY-MM-DD format.');
        }

        $construction = $this->normalizeCashflowValues(
            $raw['construction_cashflows'] ?? $raw['constructionCashflows'] ?? null,
            'construction_cashflows',
            true
        );
        $operation = $this->normalizeCashflowValues(
            $raw['operation_cashflows'] ?? $raw['operationCashflows'] ?? null,
            'operation_cashflows',
            false
        );
        $terminalRaw = $raw['terminal_value'] ?? $raw['terminalValue'] ?? null;
        if (!is_numeric($terminalRaw) || !is_finite((float)$terminalRaw) || (float)$terminalRaw < 0) {
            throw new \InvalidArgumentException('Cashflow terminal_value is required and cannot be negative.');
        }

        return [
            'valuation_date' => $valuationDate,
            'currency' => $currency,
            'construction_cashflows' => $construction,
            'operation_cashflows' => $operation,
            'terminal_value' => (float)$terminalRaw,
        ];
    }

    /**
     * @return array<int, float>
     */
    private function normalizeCashflowValues(mixed $raw, string $field, bool $allowEmpty): array
    {
        if (!is_array($raw) || array_keys($raw) !== range(0, count($raw) - 1)) {
            throw new \InvalidArgumentException('Cashflow ' . $field . ' must be a numeric list.');
        }
        if (!$allowEmpty && $raw === []) {
            throw new \InvalidArgumentException('Cashflow ' . $field . ' must contain at least one period.');
        }

        $values = [];
        foreach ($raw as $value) {
            if (!is_numeric($value) || !is_finite((float)$value)) {
                throw new \InvalidArgumentException('Cashflow ' . $field . ' contains a non-numeric period.');
            }
            $values[] = (float)$value;
        }
        return $values;
    }

    private function calculateRiskLevel(float $monthlyNetCashflow, ?float $paybackMonths, ?float $rentRatio, float $breakEvenOccupancy): string
    {
        if ($monthlyNetCashflow <= 0 || $paybackMonths === null || $rentRatio === null || $breakEvenOccupancy >= 0.65) {
            return '高风险';
        }
        if ($rentRatio >= 0.4 || $paybackMonths > 30) {
            return '高风险';
        }
        if ($rentRatio >= 0.3) {
            return '中高风险';
        }
        if ($paybackMonths > 18 || $breakEvenOccupancy >= 0.55) {
            return '中风险';
        }

        return '低风险';
    }

    private function formatRecord(array $row, bool $withDetail): array
    {
        $input = $this->decodeJson($row['input_json'] ?? '');
        $result = $this->decodeJson($row['result_json'] ?? '');
        $scenarios = $this->decodeJson($row['scenarios_json'] ?? '');
        $riskHints = $this->decodeJson($row['risk_hints_json'] ?? '');
        $modelAnalysis = $this->normalizeModelAnalysis(
            $result['modelAnalysis'] ?? $result['model_analysis'] ?? [],
            ['decision_quality_context' => $this->quantDecisionQualityContext($input, $result, $scenarios, $riskHints)]
        );
        $truthContext = $this->quantRecordTruthContext($row);
        $metricTruth = $this->quantMetricTruth($result, $truthContext);
        $resultNumber = static function (string $key) use ($result): ?float {
            if (!array_key_exists($key, $result) || !is_numeric($result[$key])) {
                return null;
            }
            $value = (float)$result[$key];
            return is_finite($value) ? $value : null;
        };
        $record = [
            'id' => (int)$row['id'],
            'project_name' => (string)($row['project_name'] ?? ''),
            'monthly_net_cashflow' => $resultNumber('monthlyNetCashflow'),
            'payback_months' => $row['payback_months'] === null ? null : (float)$row['payback_months'],
            'risk_level' => (string)($row['risk_level'] ?? ''),
            'created_by' => (int)($row['created_by'] ?? 0),
            'created_at' => (string)($row['created_at'] ?? ''),
            'summary' => [
                'monthlyRevenue' => $resultNumber('monthlyRevenue'),
                'monthlyNetCashflow' => $resultNumber('monthlyNetCashflow'),
                'paybackMonths' => $result['paybackMonths'] ?? null,
                'riskLevel' => (string)($result['riskLevel'] ?? ($row['risk_level'] ?? '')),
            ],
            'truth_context' => $truthContext,
            'summary_metric_truth' => array_intersect_key($metricTruth, array_flip([
                'monthlyRevenue',
                'monthlyNetCashflow',
                'paybackMonths',
            ])),
            'execution_readiness' => (new SimulationExecutionReadinessService())->buildQuantReadiness($input, $result, $scenarios, $riskHints),
        ];

        if ($withDetail) {
            foreach ($scenarios as $index => $scenario) {
                if (!is_array($scenario)) {
                    continue;
                }
                $scenarios[$index]['metric_truth'] = $this->quantMetricTruth($scenario, $truthContext);
            }
            $record['input'] = $input;
            $record['result'] = $result;
            $record['scenarios'] = $scenarios;
            $record['risk_hints'] = $riskHints;
            $record['model_analysis'] = $modelAnalysis;
            $record['metric_truth'] = $metricTruth;
            $record['input_truth_context'] = array_merge($truthContext, [
                'source_methods' => ['user_input'],
                'source' => array_merge($truthContext['source'], [
                    'methods' => ['user_input'],
                    'caliber' => 'user_provided_investment_scenario',
                ]),
                'calculation_status' => 'not_applicable',
            ]);
        }

        return $record;
    }

    /** @return array<string, mixed> */
    private function quantRecordTruthContext(array $row): array
    {
        $recordId = (int)($row['id'] ?? 0);
        $createdAt = trim((string)($row['created_at'] ?? ''));
        $date = preg_match('/^\d{4}-\d{2}-\d{2}/', $createdAt) === 1
            ? substr($createdAt, 0, 10)
            : null;

        return [
            'status' => 'unverified',
            'status_label' => '未验证',
            'metric_scope' => 'investment_scenario',
            'scope_label' => '投资情景测算，不是OTA数据，也不是全酒店经营实绩',
            'hotels' => [],
            'hotel_id' => null,
            'platforms' => ['not_applicable'],
            'date_range' => ['start' => $date, 'end' => $date],
            'source_methods' => ['user_input', 'deterministic_formula'],
            'source' => [
                'table' => 'quant_simulation_records',
                'row_ids' => $recordId > 0 ? [$recordId] : [],
                'trace_ids' => [],
                'methods' => ['user_input', 'deterministic_formula'],
                'caliber' => 'investment_scenario',
            ],
            'collected_at_range' => ['start' => null, 'end' => null],
            'collection_time_applicability' => 'not_applicable_manual_input',
            'calculated_at' => $createdAt !== '' ? $createdAt : null,
            'persistence' => [
                'stored' => $recordId > 0,
                'readback_verified' => $recordId > 0,
                'stored_count' => $recordId > 0 ? 1 : 0,
                'readback_verified_count' => $recordId > 0 ? 1 : 0,
            ],
            'failure_reason' => '输入为人工录入且未提供可核验经营或财务来源；记录未绑定目标门店，不能作为真实经营或OTA数据。',
            'data_gaps' => [
                'target_hotel_missing',
                'user_input_source_unverified',
                'collection_time_not_applicable',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $truthContext
     * @return array<string, array<string, mixed>>
     */
    private function quantMetricTruth(array $result, array $truthContext): array
    {
        $metrics = [];
        foreach ([
            'availableRoomNights',
            'roomRevenue',
            'monthlyRevenue',
            'otaCommission',
            'monthlyCost',
            'monthlyNetCashflow',
            'totalInvestment',
            'revPAR',
            'paybackMonths',
            'rentRatio',
            'breakEvenOccupancy',
        ] as $metricKey) {
            $value = array_key_exists($metricKey, $result) ? $result[$metricKey] : null;
            $calculated = $value !== null && is_numeric($value) && is_finite((float)$value);
            $metrics[$metricKey] = array_merge($truthContext, [
                'metric_key' => $metricKey,
                'calculation_status' => $calculated ? 'calculated' : 'missing',
                'value_observed' => $calculated,
                'calculation_basis' => 'deterministic_formula_from_user_input',
            ]);
        }
        return $metrics;
    }

    private function normalizeModelAnalysis(mixed $raw, array $defaults = []): array
    {
        if (!is_array($raw)) {
            $raw = [];
        }

        $decisionQualityContext = is_array($defaults['decision_quality_context'] ?? null)
            ? $defaults['decision_quality_context']
            : $this->quantDecisionQualityContext([], [], [], []);
        $recommendations = $this->decisionQualityService->enrichRecommendations(
            $this->normalizeRecommendationItems($raw['recommendations'] ?? []),
            $decisionQualityContext
        );
        $watchPoints = $this->normalizeWatchPointItems($raw['watch_points'] ?? $raw['watchPoints'] ?? []);
        $assumptions = $this->stringList($raw['assumptions'] ?? []);

        return [
            'source' => trim((string)($raw['source'] ?? $defaults['source'] ?? '')),
            'model_key' => trim((string)($raw['model_key'] ?? $raw['modelKey'] ?? $defaults['model_key'] ?? '')),
            'generated_at' => trim((string)($raw['generated_at'] ?? $raw['generatedAt'] ?? $defaults['generated_at'] ?? '')),
            'summary' => trim((string)($raw['summary'] ?? '')),
            'decision' => trim((string)($raw['decision'] ?? '')),
            'recommendations' => $recommendations,
            'recommendation_quality' => $this->decisionQualityService->summarize($recommendations, $decisionQualityContext),
            'watch_points' => $watchPoints,
            'assumptions' => $assumptions,
            'error' => mb_substr(trim((string)($raw['error'] ?? '')), 0, 120),
        ];
    }

    private function quantDecisionQualityContext(array $input, array $result, array $scenarios, array $riskHints): array
    {
        return [
            'scope' => 'investment_scenario',
            'data_basis' => [
                [
                    'ref' => 'quant_user_input_snapshot',
                    'source' => 'user_provided_financial_inputs',
                    'scope' => 'investment_scenario',
                    'quality_status' => 'user_provided_unverified',
                    'summary' => '房量、ADR、入住率、成本和投资额来自当前录入，未自动等同于真实经营实绩。',
                ],
                [
                    'ref' => 'quant_deterministic_result',
                    'source' => 'deterministic_formula_and_scenarios',
                    'scope' => 'investment_scenario',
                    'quality_status' => 'derived_unverified',
                    'summary' => '公式结果和三情景由当前输入派生；输入真实性仍需外部证据复核。',
                ],
            ],
            'basis_summary' => '基于当前录入、规则公式、三情景结果和风险提示生成。',
            'default_risk_level' => (string)($result['riskLevel'] ?? 'medium'),
            'review_window' => '进入投决前，以真实流水、租约、成本单据和同口径经营样本复核三情景结果',
        ];
    }

    private function normalizeRecommendationItems(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = trim((string)($item['title'] ?? ''));
            $detail = trim((string)($item['detail'] ?? $item['content'] ?? ''));
            if ($title === '' && $detail === '') {
                continue;
            }
            $priority = strtoupper(trim((string)($item['priority'] ?? 'P1')));
            if (!in_array($priority, ['P0', 'P1', 'P2'], true)) {
                $priority = 'P1';
            }
            $normalized[] = array_merge($item, [
                'priority' => $priority,
                'title' => $title !== '' ? $title : '经营建议',
                'detail' => $detail,
            ]);
        }

        return array_slice($normalized, 0, 5);
    }

    private function normalizeWatchPointItems(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $metric = trim((string)($item['metric'] ?? ''));
            $threshold = trim((string)($item['threshold'] ?? ''));
            $action = trim((string)($item['action'] ?? ''));
            if ($metric === '' && $threshold === '' && $action === '') {
                continue;
            }
            $normalized[] = [
                'metric' => $metric !== '' ? $metric : '关键指标',
                'threshold' => $threshold,
                'action' => $action,
            ];
        }

        return array_slice($normalized, 0, 5);
    }

    private function stringList(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $list = [];
        foreach ($items as $item) {
            $value = trim((string)$item);
            if ($value !== '') {
                $list[] = mb_substr($value, 0, 200);
            }
        }

        return array_values(array_unique($list));
    }

    private function number(array $raw, string $camel, string $snake): float
    {
        foreach (array_values(array_unique([$camel, $snake])) as $key) {
            if (!array_key_exists($key, $raw)) {
                continue;
            }
            $value = $raw[$key];
            if (!is_numeric($value) || !is_finite((float)$value)) {
                throw new \InvalidArgumentException($camel . '必须是有效数字');
            }
            return round((float)$value, 4);
        }

        return 0.0;
    }

    private function roomRevenueGroup(array $raw, array $baseInput): array
    {
        $values = [];
        $detailAliases = [];
        foreach ($this->roomRevenueSegments() as $segment) {
            foreach ([
                $segment['daysKey'] => $segment['daysSnake'],
                $segment['adrKey'] => $segment['adrSnake'],
                $segment['occupancyKey'] => $segment['occupancySnake'],
            ] as $camel => $snake) {
                $detailAliases[] = [(string)$camel, (string)$snake];
                $values[$camel] = $this->number($raw, (string)$camel, (string)$snake);
            }
        }
        $hasDetail = $this->requireNumericGroup(
            $raw,
            [['adr'], ['occupancyRate', 'occupancy_rate']],
            $detailAliases,
            '客房收入'
        );

        if (!$hasDetail) {
            $adr = (float)$baseInput['adr'];
            $occupancy = (float)$baseInput['occupancyRate'];
            foreach ($this->roomRevenueSegments() as $index => $segment) {
                $values[$segment['daysKey']] = $index === 0 ? 30.0 : 0.0;
                $values[$segment['adrKey']] = $adr;
                $values[$segment['occupancyKey']] = $occupancy;
            }
        }

        return $values;
    }

    private function otherIncomeGroup(array $raw, array $baseInput): array
    {
        $values = [];
        $detailAliases = [];
        foreach ($this->otherIncomeFields() as $field) {
            $detailAliases[] = [$field['key'], $field['snake']];
            $values[$field['key']] = $this->number($raw, $field['key'], $field['snake']);
        }
        $hasDetail = $this->requireNumericGroup(
            $raw,
            [['otherIncome', 'other_income']],
            $detailAliases,
            '其他收入'
        );

        if (!$hasDetail) {
            foreach ($this->otherIncomeFields() as $field) {
                $values[$field['key']] = $field['key'] === 'otherMiscIncome' ? (float)$baseInput['otherIncome'] : 0.0;
            }
        }

        return $values;
    }

    private function investmentGroup(array $raw, string $totalCamel, string $totalSnake, array $detailFields): array
    {
        $detailValues = [];
        $detailAliases = [];
        foreach ($detailFields as $camel => $snake) {
            $detailAliases[] = [(string)$camel, (string)$snake];
            $detailValues[$camel] = $this->number($raw, (string)$camel, (string)$snake);
        }
        $hasDetail = $this->requireNumericGroup(
            $raw,
            [[$totalCamel, $totalSnake]],
            $detailAliases,
            $totalCamel
        );

        if (!$hasDetail) {
            $legacyTotal = $this->number($raw, $totalCamel, $totalSnake);
            $firstKey = array_key_first($detailValues);
            foreach ($detailValues as $key => $_) {
                $detailValues[$key] = $key === $firstKey ? $legacyTotal : 0.0;
            }
        }

        $detailValues[$totalCamel] = round(array_sum($detailValues), 4);
        return $detailValues;
    }

    private function costGroup(array $raw, string $totalCamel, string $totalSnake, array $detailFields): array
    {
        return $this->investmentGroup($raw, $totalCamel, $totalSnake, $detailFields);
    }

    private function otaCommissionGroup(array $raw, array $baseInput): array
    {
        $values = [];
        $detailAliases = [];
        foreach ($this->otaCommissionChannels() as $channel) {
            foreach ([
                $channel['shareKey'] => $channel['shareSnake'],
                $channel['rateKey'] => $channel['rateSnake'],
            ] as $camel => $snake) {
                $detailAliases[] = [(string)$camel, (string)$snake];
                $values[$camel] = $this->number($raw, (string)$camel, (string)$snake);
            }
        }
        $hasDetail = $this->requireNumericGroup(
            $raw,
            [['otaCommissionRate', 'ota_commission_rate']],
            $detailAliases,
            'OTA佣金'
        );

        if (!$hasDetail) {
            $legacyRate = (float)$baseInput['otaCommissionRate'];
            foreach ($this->otaCommissionChannels() as $channel) {
                $values[$channel['shareKey']] = $channel['key'] === 'otherOta' ? 100.0 : 0.0;
                $values[$channel['rateKey']] = $legacyRate;
            }
        }

        $values['otaCommissionRate'] = $this->weightedOtaCommissionRate($values);
        return $values;
    }

    /**
     * A group may use either its legacy summary fields or its complete detail fields.
     * Once any detail field is supplied, every detail field must be explicit so that
     * an omitted value is never silently treated as a real zero.
     *
     * @param array<int, array<int, string>> $summaryAliases
     * @param array<int, array<int, string>> $detailAliases
     */
    private function requireNumericGroup(
        array $raw,
        array $summaryAliases,
        array $detailAliases,
        string $label
    ): bool {
        $hasDetail = false;
        foreach ($detailAliases as $aliases) {
            if ($this->hasAnyAlias($raw, $aliases)) {
                $hasDetail = true;
                break;
            }
        }

        $required = $hasDetail ? $detailAliases : $summaryAliases;
        foreach ($required as $aliases) {
            $field = (string)($aliases[0] ?? $label);
            $this->requireNumericAlias($raw, $aliases, $field, true);
        }

        return $hasDetail;
    }

    /** @param array<int, string> $aliases */
    private function requireNumericAlias(array $raw, array $aliases, string $label, bool $zeroAllowed): void
    {
        foreach (array_values(array_unique($aliases)) as $key) {
            if (!array_key_exists($key, $raw)) {
                continue;
            }
            $value = $raw[$key];
            if (!is_numeric($value) || !is_finite((float)$value)) {
                throw new \InvalidArgumentException($label . '必须是有效数字');
            }
            return;
        }

        $suffix = $zeroAllowed ? '；无该项请显式填0' : '';
        throw new \InvalidArgumentException($label . '缺失' . $suffix);
    }

    /** @param array<int, string> $aliases */
    private function hasAnyAlias(array $raw, array $aliases): bool
    {
        foreach (array_values(array_unique($aliases)) as $key) {
            if (array_key_exists($key, $raw)) {
                return true;
            }
        }
        return false;
    }

    private function weightedOtaCommissionRate(array $input): float
    {
        $rate = 0.0;
        foreach ($this->otaCommissionChannels() as $channel) {
            $rate += (float)($input[$channel['shareKey']] ?? 0) * (float)($input[$channel['rateKey']] ?? 0) / 100;
        }

        return round($rate, 4);
    }

    private function roomRevenueSegments(): array
    {
        return [
            [
                'daysKey' => 'weekdayDays',
                'daysSnake' => 'weekday_days',
                'adrKey' => 'weekdayAdr',
                'adrSnake' => 'weekday_adr',
                'occupancyKey' => 'weekdayOccupancyRate',
                'occupancySnake' => 'weekday_occupancy_rate',
            ],
            [
                'daysKey' => 'weekendDays',
                'daysSnake' => 'weekend_days',
                'adrKey' => 'weekendAdr',
                'adrSnake' => 'weekend_adr',
                'occupancyKey' => 'weekendOccupancyRate',
                'occupancySnake' => 'weekend_occupancy_rate',
            ],
            [
                'daysKey' => 'holidayDays',
                'daysSnake' => 'holiday_days',
                'adrKey' => 'holidayAdr',
                'adrSnake' => 'holiday_adr',
                'occupancyKey' => 'holidayOccupancyRate',
                'occupancySnake' => 'holiday_occupancy_rate',
            ],
        ];
    }

    private function otaCommissionChannels(): array
    {
        return [
            [
                'key' => 'ctrip',
                'shareKey' => 'ctripRevenueShare',
                'shareSnake' => 'ctrip_revenue_share',
                'rateKey' => 'ctripCommissionRate',
                'rateSnake' => 'ctrip_commission_rate',
            ],
            [
                'key' => 'meituan',
                'shareKey' => 'meituanRevenueShare',
                'shareSnake' => 'meituan_revenue_share',
                'rateKey' => 'meituanCommissionRate',
                'rateSnake' => 'meituan_commission_rate',
            ],
            [
                'key' => 'otherOta',
                'shareKey' => 'otherOtaRevenueShare',
                'shareSnake' => 'other_ota_revenue_share',
                'rateKey' => 'otherOtaCommissionRate',
                'rateSnake' => 'other_ota_commission_rate',
            ],
        ];
    }

    private function otherIncomeFields(): array
    {
        return [
            ['key' => 'breakfastIncome', 'snake' => 'breakfast_income'],
            ['key' => 'meetingIncome', 'snake' => 'meeting_income'],
            ['key' => 'retailIncome', 'snake' => 'retail_income'],
            ['key' => 'parkingLaundryIncome', 'snake' => 'parking_laundry_income'],
            ['key' => 'otherMiscIncome', 'snake' => 'other_misc_income'],
        ];
    }

    private function percentToDecimal(float $value): float
    {
        return $value / 100;
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
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
