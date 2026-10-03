<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;
use Throwable;

/** Retired generators retain scoped historical reads and existing-source approval compatibility. */
class ExpansionService
{
    private AiDecisionQualityService $decisionQualityService;
    private bool $tableEnsured = false;

    public function __construct(?LlmClient $client = null, ?AiDecisionQualityService $decisionQualityService = null)
    {
        $this->decisionQualityService = $decisionQualityService ?? new AiDecisionQualityService();
    }

    public function evaluateMarket(array $input): array
    {
        throw new \RuntimeException('retired_read_only', 410);
    }

    public function buildBenchmarkModel(array $input): array
    {
        throw new \RuntimeException('retired_read_only', 410);
    }

    public function improveCollaboration(array $input): array
    {
        throw new \RuntimeException('retired_read_only', 410);
    }

    public function buildProjectReadiness(string $recordType, array $input, array $result): array
    {
        return (new ExpansionProjectReadinessService())->build($recordType, $input, $result);
    }

    public function readinessSummaryFromRows(array $rows): array
    {
        return (new ExpansionProjectReadinessService())->summaryFromRows($rows);
    }

    public function saveRecord(string $recordType, array $input, array $result, int $userId): int
    {
        throw new \RuntimeException('retired_read_only', 410);
    }

    public function records(int $userId, bool $isSuperAdmin): array
    {
        $this->ensureTable();

        $query = Db::name('expansion_records')->whereNull('deleted_at');
        $this->applyTenantScope($query, $userId, $isSuperAdmin);
        if (!$isSuperAdmin) {
            $query->where('created_by', $userId);
        }

        $rows = $query->order('id', 'desc')->limit(50)->select()->toArray();
        return array_values(array_map(fn(array $row): array => $this->formatRecord($row, false), $rows));
    }

    public function detail(int $id, int $userId, bool $isSuperAdmin, bool $lockForUpdate = false): array
    {
        $this->ensureTable();

        $query = Db::name('expansion_records')->where('id', $id)->whereNull('deleted_at');
        $this->applyTenantScope($query, $userId, $isSuperAdmin);
        if (!$isSuperAdmin) {
            $query->where('created_by', $userId);
        }
        if ($lockForUpdate) {
            $query->lock(true);
        }

        $row = $query->find();
        if (!$row) {
            throw new RuntimeException('扩张记录不存在或无权访问');
        }

        return $this->formatRecord($row, true);
    }

    public function archive(int $id, int $userId, bool $isSuperAdmin): bool
    {
        throw new \RuntimeException('retired_read_only', 410);
    }

    public function archiveByType(string $recordType, int $userId, bool $isSuperAdmin): int
    {
        throw new \RuntimeException('retired_read_only', 410);
    }

    public function archiveByTypes(array $recordTypes, int $userId, bool $isSuperAdmin): int
    {
        throw new \RuntimeException('retired_read_only', 410);
    }

    public function buildExecutionIntentInput(array $record, int $hotelId, array $overrides = []): array
    {
        if ($hotelId <= 0) {
            throw new InvalidArgumentException('hotel_id is required for expansion execution tracking');
        }

        $input = $this->decodeJson($record['input'] ?? $record['input_json'] ?? []);
        $result = $this->decodeJson($record['result'] ?? $record['result_json'] ?? []);
        $recordType = trim((string)($record['record_type'] ?? ''));
        if ($recordType === 'market' && is_array($result['ai_evaluation'] ?? null)) {
            $result['ai_evaluation'] = $this->normalizeMarketAiEvaluation($result['ai_evaluation'], [
                'decision_quality_context' => $this->expansionDecisionQualityContext($input, $result, 'market'),
            ]);
        }
        if ($recordType === 'benchmark' && is_array($result['ai_evaluation'] ?? null)) {
            $result['ai_evaluation'] = $this->normalizeBenchmarkAiEvaluation($result['ai_evaluation'], [
                'decision_quality_context' => $this->expansionDecisionQualityContext($input, $result, 'benchmark'),
            ]);
        }
        $readiness = is_array($record['project_readiness'] ?? null)
            ? $record['project_readiness']
            : $this->buildProjectReadiness($recordType, $input, $result);
        $readinessStage = trim((string)($readiness['stage'] ?? ''));
        if (!in_array($readinessStage, ['review_ready', 'approved_pending_tracking'], true)) {
            throw new InvalidArgumentException(
                'expansion record is not ready for execution review: ' . ($readinessStage !== '' ? $readinessStage : 'readiness_stage missing')
            );
        }
        $date = date('Y-m-d');
        $dateStart = trim((string)($overrides['date_start'] ?? '')) ?: $date;
        $dateEnd = trim((string)($overrides['date_end'] ?? '')) ?: $dateStart;
        $projectName = trim((string)($record['project_name'] ?? $input['project_name'] ?? ''));
        if ($projectName === '') {
            $projectName = 'expansion_record_' . (int)($record['id'] ?? 0);
        }
        $sourceSnapshotDigest = SourceBackedExecutionIntentIdentityService::snapshotDigest('expansion', [
            'id' => (int)($record['id'] ?? 0),
            'record_type' => $recordType,
            'project_name' => (string)($record['project_name'] ?? ''),
            'city_area' => (string)($record['city_area'] ?? ''),
            'decision' => (string)($record['decision'] ?? ''),
            'risk_level' => (string)($record['risk_level'] ?? ''),
            'input' => $input,
            'result' => $result,
        ]);

        return [
            'source_module' => 'expansion',
            'source_record_id' => (int)($record['id'] ?? 0),
            'hotel_id' => $hotelId,
            'platform' => 'investment',
            'object_type' => 'expansion',
            'action_type' => 'expansion_post_decision_tracking',
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
            'current_value' => [
                'project_name' => $projectName,
                'record_type' => $recordType,
                'city_area' => (string)($record['city_area'] ?? $input['city_area'] ?? ''),
                'decision' => (string)($record['decision'] ?? $result['decision'] ?? ''),
                'risk_level' => (string)($record['risk_level'] ?? $result['investment_risk_level'] ?? ''),
                'readiness_stage' => $readinessStage,
            ],
            'target_value' => [
                'project_name' => $projectName,
                'tracking_status' => 'pending_expansion_post_decision_tracking',
                'target_metric' => 'expansion_project_closure',
                'decision_stage' => $readinessStage,
                'next_action' => (string)($readiness['next_action'] ?? ''),
            ],
            'evidence' => [
                'source_snapshot_digest' => $sourceSnapshotDigest,
                'record_type' => $recordType,
                'readiness_stage' => $readinessStage,
                'readiness_score' => (int)($readiness['score'] ?? 0),
                'missing_evidence' => array_values((array)($readiness['missing_evidence'] ?? [])),
                'decision' => (string)($record['decision'] ?? ''),
                'city_area' => (string)($record['city_area'] ?? ''),
                'source_scope' => 'expansion_screening_and_project_decision',
                'scope_notice' => 'Expansion evidence is project screening and investment-decision scope; OTA channel evidence remains channel-scope unless backed by whole-hotel operating data.',
            ],
            'expected_metric' => 'expansion_project_closure',
            'expected_delta' => 0,
            'risk_level' => $this->executionRiskLevel((string)($record['risk_level'] ?? $result['investment_risk_level'] ?? ''), (string)($record['decision'] ?? $result['decision'] ?? '')),
            'status' => 'pending_approval',
        ];
    }

    public function attachExecutionTracking(int $id, int $userId, bool $isSuperAdmin, array $tracking): array
    {
        $this->ensureTable();
        $intentId = (int)($tracking['execution_intent_id'] ?? $tracking['id'] ?? 0);
        if ($intentId <= 0) {
            throw new InvalidArgumentException('execution_intent_id is required');
        }

        $query = Db::name('expansion_records')->where('id', $id)->whereNull('deleted_at');
        $this->applyTenantScope($query, $userId, $isSuperAdmin);
        if (!$isSuperAdmin) {
            $query->where('created_by', $userId);
        }
        $query->lock(true);

        $row = $query->find();
        if (!$row) {
            throw new RuntimeException('扩张记录不存在或无权访问');
        }

        $intent = Db::name('operation_execution_intents')
            ->where('id', $intentId)
            ->whereRaw('LOWER(TRIM(`source_module`)) = ?', ['expansion'])
            ->where('source_record_id', $id)
            ->whereNull('deleted_at')
            ->find();
        if (!is_array($intent)) {
            throw new InvalidArgumentException('current expansion execution intent is required');
        }
        $intentHotelId = (int)($intent['hotel_id'] ?? 0);
        $trackingHotelId = (int)($tracking['hotel_id'] ?? 0);
        $recordTenantId = (int)($row['tenant_id'] ?? 0);
        $intentTenantId = (int)($intent['tenant_id'] ?? 0);
        $hotelTenantId = $intentHotelId > 0
            ? (int)(Db::name('hotels')->where('id', $intentHotelId)->value('tenant_id') ?: 0)
            : 0;
        if ($intentHotelId <= 0
            || $trackingHotelId !== $intentHotelId
            || $recordTenantId <= 0
            || $intentTenantId !== $recordTenantId
            || $hotelTenantId !== $recordTenantId
        ) {
            throw new InvalidArgumentException('expansion execution intent is outside the current hotel tenant scope');
        }

        $currentInput = $this->buildExecutionIntentInput($this->formatRecord($row, true), $intentHotelId, [
            'date_start' => (string)($intent['date_start'] ?? ''),
            'date_end' => (string)($intent['date_end'] ?? ''),
        ]);
        $intentEvidence = $this->decodeJson($intent['evidence_json'] ?? '');
        $currentEvidence = is_array($currentInput['evidence'] ?? null) ? $currentInput['evidence'] : [];
        $intentDigest = strtolower(trim((string)($intentEvidence['source_snapshot_digest'] ?? '')));
        $currentDigest = strtolower(trim((string)($currentEvidence['source_snapshot_digest'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/D', $intentDigest) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $currentDigest) !== 1
            || !hash_equals($intentDigest, $currentDigest)
        ) {
            throw new InvalidArgumentException('expansion execution source snapshot changed; link the current lifecycle only');
        }

        $result = $this->decodeJson($row['result_json'] ?? '');
        $linkedIntentId = (int)($result['operation_execution_intent_id'] ?? $result['execution_intent_id'] ?? 0);
        if ($linkedIntentId > 0) {
            if ($linkedIntentId === $intentId) {
                return $this->formatRecord($row, true);
            }
            if ($intentId <= $linkedIntentId) {
                throw new RuntimeException('expansion record can only advance to a newer current execution lifecycle', 409);
            }
        }

        $now = date('Y-m-d H:i:s');
        $trackingPayload = [
            'type' => 'operation_execution_intent',
            'execution_intent_id' => $intentId,
            'hotel_id' => (int)($tracking['hotel_id'] ?? 0),
            'status' => trim((string)($tracking['status'] ?? '')),
            'source_module' => 'expansion',
            'linked_at' => $now,
        ];

        $existing = $result['execution_tracking'] ?? [];
        if (!is_array($existing)) {
            $existing = [];
        }
        if ($existing !== [] && array_keys($existing) !== range(0, count($existing) - 1)) {
            $existing = [$existing];
        }
        $existing[] = $trackingPayload;

        $result['execution_tracking'] = $existing;
        $result['operation_execution_intent_id'] = $intentId;
        $result['execution_intent_id'] = $intentId;
        $result['post_decision_tracking'] = [
            'status' => 'linked',
            'latest_execution_intent_id' => $intentId,
            'latest_status' => $trackingPayload['status'],
            'hotel_id' => $trackingPayload['hotel_id'],
            'linked_at' => $now,
        ];

        Db::name('expansion_records')->where('id', $id)->update([
            'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE),
            'updated_at' => $now,
        ]);

        $row['result_json'] = $result;
        $row['updated_at'] = $now;
        return $this->formatRecord($row, true);
    }

    public function ensureTable(): void
    {
        if ($this->tableEnsured) {
            return;
        }

        DatabaseSchemaRequirement::assertTableColumns('expansion_records', [
            'id', 'tenant_id', 'record_type', 'project_name', 'city_area', 'input_json',
            'result_json', 'decision', 'risk_level', 'created_by', 'created_at',
            'updated_at', 'deleted_at',
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

    private function formatRecord(array $row, bool $withDetail): array
    {
        $input = $this->decodeJson($row['input_json'] ?? '');
        $result = $this->decodeJson($row['result_json'] ?? '');
        $recordType = (string)($row['record_type'] ?? '');
        if ($recordType === 'market' && is_array($result['ai_evaluation'] ?? null)) {
            $result['ai_evaluation'] = $this->normalizeMarketAiEvaluation($result['ai_evaluation'], [
                'decision_quality_context' => $this->expansionDecisionQualityContext($input, $result, 'market'),
            ]);
        }
        if ($recordType === 'benchmark' && is_array($result['ai_evaluation'] ?? null)) {
            $result['ai_evaluation'] = $this->normalizeBenchmarkAiEvaluation($result['ai_evaluation'], [
                'decision_quality_context' => $this->expansionDecisionQualityContext($input, $result, 'benchmark'),
            ]);
        }
        $record = [
            'id' => (int)$row['id'],
            'record_type' => (string)($row['record_type'] ?? ''),
            'project_name' => (string)($row['project_name'] ?? ''),
            'city_area' => (string)($row['city_area'] ?? ''),
            'decision' => (string)($row['decision'] ?? ''),
            'risk_level' => (string)($row['risk_level'] ?? ''),
            'execution_intent_id' => (int)($result['operation_execution_intent_id'] ?? $result['execution_intent_id'] ?? 0),
            'created_by' => (int)($row['created_by'] ?? 0),
            'created_at' => (string)($row['created_at'] ?? ''),
            'summary' => [
                'market_heat_score' => $result['market_heat_score'] ?? null,
                'investment_risk_level' => $result['investment_risk_level'] ?? ($row['risk_level'] ?? ''),
                'benchmark_count' => is_array($result['recommended_benchmarks'] ?? null) ? count($result['recommended_benchmarks']) : null,
                'progress_percent' => $result['progress']['percent'] ?? null,
            ],
            'project_readiness' => $this->buildProjectReadiness(
                (string)($row['record_type'] ?? ''),
                $input,
                $result
            ),
        ];

        if ($withDetail) {
            $record['input'] = $input;
            $record['result'] = $result;
        }

        return $record;
    }

    private function executionRiskLevel(string $riskLevel, string $decision): string
    {
        $text = strtolower($riskLevel . ' ' . $decision);
        if (str_contains($text, 'high') || str_contains($riskLevel, '高') || str_contains($decision, '暂缓') || str_contains($decision, '放弃')) {
            return 'high';
        }
        if (str_contains($text, 'medium') || str_contains($riskLevel, '中')) {
            return 'medium';
        }
        if (str_contains($text, 'low') || str_contains($riskLevel, '低') || str_contains($decision, '推进') || str_contains($decision, '通过')) {
            return 'low';
        }

        return 'medium';
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeMarketAiEvaluation(mixed $raw, array $defaults = []): array
    {
        if (!is_array($raw)) {
            $raw = [];
        }

        $decisionQualityContext = is_array($defaults['decision_quality_context'] ?? null)
            ? $defaults['decision_quality_context']
            : $this->expansionDecisionQualityContext([], [], 'market');
        $recommendations = $this->decisionQualityService->enrichRecommendations(
            $this->normalizeAiRecommendationItems($raw['recommendations'] ?? []),
            $decisionQualityContext
        );

        return [
            'source' => trim((string)($raw['source'] ?? $defaults['source'] ?? '')),
            'model_key' => trim((string)($raw['model_key'] ?? $raw['modelKey'] ?? $defaults['model_key'] ?? '')),
            'generated_at' => trim((string)($raw['generated_at'] ?? $raw['generatedAt'] ?? $defaults['generated_at'] ?? '')),
            'summary' => $this->cleanAiText((string)($raw['summary'] ?? ''), 300),
            'decision' => $this->cleanAiText((string)($raw['decision'] ?? ''), 160),
            'market_judgement' => $this->normalizeMarketJudgement($raw['market_judgement'] ?? $raw['marketJudgement'] ?? []),
            'recommendations' => $recommendations,
            'recommendation_quality' => $this->decisionQualityService->summarize($recommendations, $decisionQualityContext),
            'watch_points' => $this->normalizeAiWatchPointItems($raw['watch_points'] ?? $raw['watchPoints'] ?? []),
            'assumptions' => $this->stringList($raw['assumptions'] ?? []),
            'error' => $this->cleanAiText((string)($raw['error'] ?? ''), 120),
        ];
    }

    private function normalizeMarketJudgement(mixed $raw): array
    {
        if (!is_array($raw)) {
            $raw = [];
        }

        return [
            'supply_competition_strength' => $this->cleanAiText((string)($raw['supply_competition_strength'] ?? $raw['supplyCompetitionStrength'] ?? ''), 160),
            'price_band_suggestion' => $this->cleanAiText((string)($raw['price_band_suggestion'] ?? $raw['priceBandSuggestion'] ?? ''), 160),
            'decision' => $this->cleanAiText((string)($raw['decision'] ?? ''), 160),
        ];
    }

    private function normalizeAiRecommendationItems(mixed $items): array
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
                'title' => $title !== '' ? $this->cleanAiText($title, 80) : '市场评估建议',
                'detail' => $this->cleanAiText($detail, 220),
            ]);
        }

        return array_slice($normalized, 0, 5);
    }

    private function normalizeAiWatchPointItems(mixed $items): array
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
            $severity = strtoupper(trim((string)($item['severity'] ?? $item['priority'] ?? 'P1')));
            if ($metric === '' && $threshold === '' && $action === '') {
                continue;
            }
            if (!in_array($severity, ['P0', 'P1', 'P2'], true)) {
                $severity = 'P1';
            }
            $normalized[] = [
                'metric' => $metric !== '' ? $this->cleanAiText($metric, 80) : '关键指标',
                'threshold' => $this->cleanAiText($threshold, 160),
                'action' => $this->cleanAiText($action, 220),
                'severity' => $severity,
                'evidence' => $this->cleanAiText((string)($item['evidence'] ?? $item['reason'] ?? ''), 220),
                'impact' => $this->cleanAiText((string)($item['impact'] ?? ''), 220),
                'validation' => $this->cleanAiText((string)($item['validation'] ?? $item['verification'] ?? $item['check_method'] ?? ''), 220),
                'owner' => $this->cleanAiText((string)($item['owner'] ?? ''), 80),
                'deadline' => $this->cleanAiText((string)($item['deadline'] ?? $item['timing'] ?? ''), 80),
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
                $list[] = $this->cleanAiText($value, 220);
            }
        }

        return array_values(array_unique($list));
    }

    private function cleanAiText(string $value, int $length): string
    {
        $value = preg_replace('/规则引擎|rule engine/i', '初筛模型', $value) ?? $value;
        return mb_substr(trim($value), 0, $length);
    }

    private function normalizeBenchmarkAiEvaluation(mixed $raw, array $defaults = []): array
    {
        if (!is_array($raw)) {
            $raw = [];
        }

        $decisionQualityContext = is_array($defaults['decision_quality_context'] ?? null)
            ? $defaults['decision_quality_context']
            : $this->expansionDecisionQualityContext([], [], 'benchmark');
        $recommendations = $this->decisionQualityService->enrichRecommendations(
            $this->normalizeAiRecommendationItems($raw['recommendations'] ?? []),
            $decisionQualityContext
        );

        return [
            'source' => trim((string)($raw['source'] ?? $defaults['source'] ?? '')),
            'model_key' => trim((string)($raw['model_key'] ?? $raw['modelKey'] ?? $defaults['model_key'] ?? '')),
            'generated_at' => trim((string)($raw['generated_at'] ?? $raw['generatedAt'] ?? $defaults['generated_at'] ?? '')),
            'summary' => $this->cleanAiText((string)($raw['summary'] ?? ''), 300),
            'decision' => $this->cleanAiText((string)($raw['decision'] ?? ''), 160),
            'model_judgement' => $this->normalizeBenchmarkJudgement($raw['model_judgement'] ?? $raw['modelJudgement'] ?? []),
            'recommendations' => $recommendations,
            'recommendation_quality' => $this->decisionQualityService->summarize($recommendations, $decisionQualityContext),
            'watch_points' => $this->normalizeAiWatchPointItems($raw['watch_points'] ?? $raw['watchPoints'] ?? []),
            'assumptions' => $this->stringList($raw['assumptions'] ?? []),
            'error' => $this->cleanAiText((string)($raw['error'] ?? ''), 120),
        ];
    }

    private function expansionDecisionQualityContext(array $input, array $result, string $scenario): array
    {
        $source = trim((string)($result['source'] ?? ''));
        return [
            'scope' => 'investment_scenario',
            'data_basis' => [
                [
                    'ref' => 'expansion_user_input_snapshot',
                    'source' => 'user_provided_project_inputs',
                    'scope' => 'investment_scenario',
                    'quality_status' => 'user_provided_unverified',
                    'summary' => '城市、物业、租金、房量、客群及竞品汇总来自当前录入，需保留人工来源凭证。',
                ],
                [
                    'ref' => 'expansion_' . $scenario . '_result',
                    'source' => $source !== '' ? $source : 'deterministic_expansion_result',
                    'scope' => 'investment_scenario',
                    'quality_status' => $source === 'verified_source_data' ? 'verified' : 'derived_unverified',
                    'summary' => '当前评估结果由录入数据和规则初筛派生，不代表真实市场热度或投资成功率。',
                ],
            ],
            'basis_summary' => (string)($result['decision'] ?? $result['summary'] ?? ''),
            'default_risk_level' => (string)($result['investment_risk_level'] ?? 'medium'),
            'review_window' => '进入投决会前，以真实竞品样本、租约、经营流水和同口径OTA渠道数据复核',
        ];
    }

    private function normalizeBenchmarkJudgement(mixed $raw): array
    {
        if (!is_array($raw)) {
            $raw = [];
        }

        return [
            'best_fit_model' => $this->cleanAiText((string)($raw['best_fit_model'] ?? $raw['bestFitModel'] ?? ''), 120),
            'copy_priority' => $this->cleanAiText((string)($raw['copy_priority'] ?? $raw['copyPriority'] ?? ''), 180),
            'differentiation_focus' => $this->cleanAiText((string)($raw['differentiation_focus'] ?? $raw['differentiationFocus'] ?? ''), 180),
        ];
    }

}
