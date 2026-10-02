<?php
declare(strict_types=1);

namespace app\service;

use app\model\User;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

/** Operating assumptions live in the existing project audit stream, never in cash entries. */
class InvestmentScenarioService
{
    private InvestmentPaybackService $ledger;
    private InvestmentScenarioCalculator $calculator;
    private KnowledgeContentDigestService $digest;
    private int $tenantId;
    private int $actorId;

    public function __construct(User $user, ?InvestmentPaybackService $ledger = null, ?InvestmentScenarioCalculator $calculator = null)
    {
        $this->ledger = $ledger ?? new InvestmentPaybackService($user);
        $this->calculator = $calculator ?? new InvestmentScenarioCalculator();
        $this->digest = new KnowledgeContentDigestService();
        $this->tenantId = (int)($user->tenant_id ?? 0);
        $this->actorId = (int)($user->id ?? 0);
        if ($this->tenantId <= 0 || $this->actorId <= 0) {
            throw new RuntimeException('当前账号缺少有效租户归属', 403);
        }
    }

    public function referenceExample(): array
    {
        return [
            'input' => $this->calculator->referenceExample(),
            'quality_status' => 'scenario_assumption',
            'source_label' => '清远投资工作簿参考样例，非当前酒店事实',
            'source_modified_metadata' => '2022-04-26',
            'business_date_verified' => false,
            'default_applied' => false,
        ];
    }

    public function detail(int $projectId, string $scenarioKey = 'base'): array
    {
        $scenarioKey = $this->scenarioKey($scenarioKey);
        $project = $this->authorizedProject($projectId);
        $event = $this->latest($projectId, $scenarioKey);
        if ($event === null) {
            return $this->reply($project, null, null) + ['scenario_key' => $scenarioKey];
        }
        return $this->reply($project, $this->snapshot($event), $event) + ['scenario_key' => $scenarioKey];
    }

    public function library(int $projectId): array
    {
        $project = $this->authorizedProject($projectId);
        $items = [];
        foreach (['conservative', 'base', 'optimistic'] as $key) {
            $event = $this->latest($projectId, $key);
            $snapshot = $event ? $this->snapshot($event) : null;
            $items[] = $this->summary($project, $snapshot, $event, $key);
        }
        return ['project_id' => $projectId, 'project_version' => $project['version'], 'items' => $items, 'quality_status' => 'scenario_assumption'];
    }

    public function history(int $projectId, ?int $beforeEventId = null): array
    {
        $project = $this->authorizedProject($projectId);
        $query = Db::name('investment_payback_events')->where('tenant_id', $this->tenantId)->where('project_id', $projectId)
            ->whereIn('event_type', ['scenario_saved', 'scenario_saved_conservative', 'scenario_saved_optimistic']);
        if ($beforeEventId !== null) { if ($beforeEventId <= 0) throw new InvalidArgumentException('历史游标无效'); $query->where('id', '<', $beforeEventId); }
        $events = $query->order('id', 'desc')->limit(101)->select()->toArray();
        $hasMore = count($events) > 100; $events = array_slice($events, 0, 100);
        $items = [];
        foreach ($events as $event) $items[] = $this->summary($project, $this->snapshot($event), $event, $this->keyFromEvent($event));
        return ['project_id' => $projectId, 'project_version' => $project['version'], 'items' => $items, 'history_limit' => 100,
            'has_more' => $hasMore, 'next_before_event_id' => $hasMore ? (int)end($events)['id'] : null, 'older_events_preserved' => true];
    }

    public function version(int $projectId, int $eventId): array
    {
        $project = $this->authorizedProject($projectId);
        $event = Db::name('investment_payback_events')->where('tenant_id', $this->tenantId)->where('project_id', $projectId)->where('id', $eventId)
            ->whereIn('event_type', ['scenario_saved', 'scenario_saved_conservative', 'scenario_saved_optimistic'])->find();
        if (!$event) throw new RuntimeException('测算历史不存在或不在当前项目范围', 404);
        return $this->reply($project, $this->snapshot($event), $event) + ['scenario_key' => $this->keyFromEvent($event), 'historical_version' => true];
    }

    public function copyVersion(int $projectId, int $eventId, array $payload): array
    {
        $source = $this->version($projectId, $eventId);
        $target = $this->scenarioKey($payload['scenario_key'] ?? 'base');
        return $this->save($projectId, ['expected_version' => $payload['expected_version'] ?? null, 'scenario_key' => $target,
            'scenario' => $source['input']], $eventId);
    }

    public function compare(array $payload): array
    {
        $selected = $payload['selections'] ?? null;
        if (!is_array($selected) || count($selected) < 2 || count($selected) > 12) throw new InvalidArgumentException('请选择2至12项项目方案比较');
        $items = []; $seen = [];
        foreach ($selected as $selection) {
            if (!is_array($selection) || !isset($selection['project_id']) || !preg_match('/^[1-9]\d{0,9}$/D', (string)$selection['project_id'])) throw new InvalidArgumentException('比较项目编号无效');
            $id = (int)$selection['project_id']; $key = $this->scenarioKey($selection['scenario_key'] ?? 'base');
            if (isset($seen[$id . ':' . $key])) throw new InvalidArgumentException('不能重复选择相同项目方案');
            $seen[$id . ':' . $key] = true;
            $data = $this->detail($id, $key);
            $project = $this->authorizedProject($id);
            $event = $data['scenario_event_id'] ? ['id' => $data['scenario_event_id'], 'created_at' => $data['saved_at']] : null;
            $snapshot = $data['input'] ? ['input' => $data['input'], 'result' => $data['result'], 'scenario_version' => $data['scenario_version'], 'model_version' => $data['model_version'], 'content_digest' => $data['content_digest']] : null;
            $item = $this->summary($project, $snapshot, $event, $key);
            $actualAsOf = min($data['input']['as_of'] ?? InvestmentPaybackCalculator::today(), InvestmentPaybackCalculator::today());
            $item['actual_cash'] = $this->ledger->detail($id, $actualAsOf)['summary'];
            $item['actual_cash_note'] = '人工实际投入/实收，独立于经营情景，来源未独立核验';
            $items[] = $item;
        }
        $reasons = [];
        foreach ($items as $item) {
            if ($item['readback'] !== 'exact') $reasons[] = $item['project_name'] . '：方案尚未保存';
            if ($item['result_status'] !== 'ready') $reasons[] = $item['project_name'] . '：完整年度现金调整或测算参数未补齐';
            if (!$item['as_of']) $reasons[] = $item['project_name'] . '：测算日期未明确';
        }
        foreach (['currency', 'as_of', 'years', 'operating_cost_basis', 'model_version'] as $field) if (count(array_unique(array_column($items, $field), SORT_REGULAR)) > 1) $reasons[] = $field . ' 不一致，须调整同口径后再比较';
        return ['items' => $items, 'comparable' => $reasons === [], 'status' => $reasons === [] ? 'comparable_assumptions' : 'scope_mismatch_or_missing',
            'reasons' => array_values(array_unique($reasons)), 'quality_status' => 'scenario_assumption', 'ranking_performed' => false, 'actual_cash_written' => false];
    }

    public function consumablesReference(int $projectId): array
    {
        $project = $this->authorizedProject($projectId);
        if (!class_exists(ConsumablesProcurementReferenceService::class)) {
            throw new RuntimeException('采购参考目录尚未接入，请使用手工成本测算', 503);
        }
        return (new ConsumablesProcurementReferenceService())->catalog() + [
            'project_id' => (int)$project['id'], 'hotel_id' => $project['hotel_id'], 'tenant_id' => $this->tenantId];
    }

    public function preview(int $projectId, array $payload): array
    {
        $project = $this->authorizedProject($projectId);
        $input = $this->input($payload);
        $input = $this->validateActualReference($project, $input);
        $result = $this->calculator->calculate($input);
        $normalized = $result['input'] ?? $this->calculator->normalize($input);
        return array_replace($this->reply($project, null, null), [
            'input' => $normalized, 'result' => $result,
            'model_version' => $result['model_version'],
            'content_digest' => $this->digest->digest(['input' => $normalized, 'result' => $result]),
            'source_label' => '未保存的经营测算假设', 'readback' => 'preview_only',
        ]);
    }

    public function save(int $projectId, array $payload, ?int $copyFromEventId = null): array
    {
        $scenarioKey = $this->scenarioKey($payload['scenario_key'] ?? 'base');
        if (!isset($payload['expected_version']) || !preg_match('/^[1-9]\d{0,9}$/D', (string)$payload['expected_version'])) {
            throw new InvalidArgumentException('保存测算须提供已读取的项目版本');
        }
        $raw = $this->input($payload);
        $project = $this->authorizedProject($projectId);
        $raw = $this->validateActualReference($project, $raw);
        $result = $this->calculator->calculate($raw);
        $input = $result['input'] ?? $this->calculator->normalize($raw);
        $inputDigest = $this->digest->digest($input);
        $contentDigest = $this->digest->digest(['input' => $input, 'result' => $result]);
        $action = Db::transaction(function () use ($projectId, $payload, $copyFromEventId, $scenarioKey, $input, $result, $inputDigest, $contentDigest): string {
            $row = Db::name('investment_payback_projects')->where('tenant_id', $this->tenantId)->where('id', $projectId)->lock(true)->find();
            if (!$row) {
                throw new RuntimeException('项目不存在或不在当前租户范围', 404);
            }
            $project = $this->authorizedProject($projectId);
            if ($row['archived_at'] !== null) {
                throw new RuntimeException('项目已归档，只能查阅历史', 409);
            }
            $previousEvent = $this->latest($projectId, $scenarioKey);
            $previous = $previousEvent === null ? null : $this->snapshot($previousEvent);
            // A retry of the exact current snapshot has no side effects even if its prior version is stale.
            $copyId = $copyFromEventId ?? 0;
            if ($previous !== null && hash_equals($previous['content_digest'], $contentDigest)
                && ($copyId === 0 || ((int)($previous['copied_from_event_id'] ?? 0) === $copyId && (int)$payload['expected_version'] === (int)$previousEvent['project_version'] - 1))) {
                return 'unchanged';
            }
            if ((int)$payload['expected_version'] !== (int)$row['version']) {
                throw new RuntimeException('项目或测算已被修改，请重新读取后再保存；未覆盖当前内容', 409);
            }
            if (($project['currency'] ?? 'CNY') !== ($input['currency'] ?? 'CNY')) {
                throw new InvalidArgumentException('经营测算币种必须与当前项目一致');
            }
            $now = date('Y-m-d H:i:s');
            $nextVersion = (int)$row['version'] + 1;
            $snapshot = [
                'schema_version' => 'hotel_investment_scenario.v1',
                'scenario_version' => (int)($previous['scenario_version'] ?? 0) + 1,
                'model_version' => $result['model_version'], 'input' => $input, 'result' => $result,
                'input_digest' => $inputDigest, 'content_digest' => $contentDigest,
                'quality_status' => 'scenario_assumption',
                'scenario_key' => $scenarioKey, 'copied_from_event_id' => $copyId ?: null,
            ];
            Db::name('investment_payback_projects')->where('tenant_id', $this->tenantId)->where('id', $projectId)->update([
                'version' => $nextVersion, 'updated_by' => $this->actorId, 'updated_at' => $now,
            ]);
            Db::name('investment_payback_events')->insert([
                'tenant_id' => $this->tenantId, 'project_id' => $projectId, 'entry_id' => null, 'actor_id' => $this->actorId,
                'event_type' => $this->eventType($scenarioKey), 'project_version' => $nextVersion, 'created_at' => $now,
                'payload_json' => json_encode([
                    'before' => $previous === null ? null : $this->auditSummary($previous),
                    'after' => $this->auditSummary($snapshot), 'scenario_snapshot' => $snapshot,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
            ]);
            $readbackEvent = $this->latest($projectId, $scenarioKey);
            $saved = $this->snapshot($readbackEvent);
            if (!hash_equals($contentDigest, $saved['content_digest']) || $saved['scenario_version'] !== $snapshot['scenario_version']) {
                throw new RuntimeException('测算保存回读不一致，保存已回滚', 409);
            }
            return 'saved';
        });
        return $this->detail($projectId, $scenarioKey) + ['action' => $action];
    }

    private function authorizedProject(int $id): array
    {
        // Reuse the live project's tenant/hotel/capability checks; source or client fields cannot grant access.
        return $this->ledger->detail($id)['project'];
    }

    private function validateActualReference(array $project, array $input): array
    {
        $id = $input['cost_evidence_snapshot_id'] ?? null;
        $digest = $input['cost_evidence_digest'] ?? '';
        $confirmed = $input['cost_evidence_confirmed'] ?? false;
        if (($id === null || $id === '') && ($digest === null || $digest === '') && $confirmed === false) {
            return $input;
        }
        if (!class_exists(ActualConsumablesScenarioReferenceService::class)) {
            throw new InvalidArgumentException('实际耗材证据尚未接入，请解除引用后使用手工测算');
        }
        return (new ActualConsumablesScenarioReferenceService())->validate($this->tenantId, (int)($project['hotel_id'] ?? 0), $input);
    }

    private function input(array $payload): array
    {
        if (!isset($payload['scenario']) || !is_array($payload['scenario'])) {
            throw new InvalidArgumentException('经营测算参数须为对象');
        }
        // Up to 100 rows may contain server-authored source snapshots when a saved draft is edited or retried.
        $hasReferences = false;
        foreach ((array)($payload['scenario']['consumables_cost']['items'] ?? []) as $item) {
            if (is_array($item) && is_array($item['procurement_reference'] ?? null)) $hasReferences = true;
        }
        if (strlen(json_encode($payload['scenario'], JSON_THROW_ON_ERROR)) > ($hasReferences ? 1000000 : 150000)) {
            throw new InvalidArgumentException('经营测算内容过大');
        }
        return $payload['scenario'];
    }

    private function latest(int $projectId, string $scenarioKey = 'base'): ?array
    {
        return Db::name('investment_payback_events')->where('tenant_id', $this->tenantId)->where('project_id', $projectId)
            ->where('event_type', $this->eventType($scenarioKey))->order('id', 'desc')->find() ?: null;
    }

    private function scenarioKey(mixed $key): string
    {
        if (!is_string($key) || !in_array($key, ['conservative', 'base', 'optimistic'], true)) throw new InvalidArgumentException('方案须为保守、基准或乐观');
        return $key;
    }

    private function eventType(string $key): string { return $key === 'base' ? 'scenario_saved' : 'scenario_saved_' . $key; }
    private function keyFromEvent(array $event): string { return match ($event['event_type']) { 'scenario_saved_conservative' => 'conservative', 'scenario_saved_optimistic' => 'optimistic', default => 'base' }; }

    private function summary(array $project, ?array $snapshot, ?array $event, string $key): array
    {
        $input = $snapshot['input'] ?? []; $result = $snapshot['result'] ?? [];
        return ['project_id' => (int)$project['id'], 'hotel_id' => $project['hotel_id'], 'project_name' => $project['project_name'], 'scenario_key' => $key,
            'scenario_name' => $input['scenario_name'] ?? null, 'scenario_version' => $snapshot['scenario_version'] ?? null,
            'scenario_event_id' => $event ? (int)$event['id'] : null, 'saved_at' => $event['created_at'] ?? null,
            'as_of' => $input['as_of'] ?? null, 'currency' => $input['currency'] ?? $project['currency'], 'years' => $input['years'] ?? null,
            'operating_cost_basis' => $input['operating_cost_basis'] ?? null, 'model_version' => $snapshot['model_version'] ?? null,
            'result_status' => $result['status'] ?? 'not_saved', 'initial_cash_total' => $result['initial_cash_total'] ?? null,
            'scenario_payback' => $result['scenario_payback'] ?? null, 'pretax_payback' => $result['payback'] ?? null,
            'ending_cumulative_scenario_cashflow' => $result['totals']['ending_cumulative_scenario_cashflow'] ?? null,
            'cash_pressure' => isset($result['cash_pressure']) ? array_intersect_key($result['cash_pressure'], array_flip(['status', 'minimum_liquidity', 'minimum_month', 'funding_gap', 'minimum_dscr'])) : null,
            'decision_constraints' => $result['decision_constraints'] ?? null, 'source_label' => $input['source_label'] ?? null,
            'content_digest' => $snapshot['content_digest'] ?? null, 'readback' => $snapshot ? 'exact' : 'no_saved_scenario'];
    }

    private function snapshot(array $event): array
    {
        try {
            $payload = json_decode((string)$event['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            $snapshot = $payload['scenario_snapshot'] ?? null;
            if (!is_array($snapshot) || ($snapshot['schema_version'] ?? '') !== 'hotel_investment_scenario.v1'
                || !is_array($snapshot['input'] ?? null) || !is_array($snapshot['result'] ?? null)
                || !$this->digest->matches((string)($snapshot['input_digest'] ?? ''), $snapshot['input'])
                || !$this->digest->matches((string)($snapshot['content_digest'] ?? ''), ['input' => $snapshot['input'], 'result' => $snapshot['result']])
                || ($snapshot['quality_status'] ?? '') !== 'scenario_assumption') {
                throw new RuntimeException('测算快照或摘要不一致，请核对保存记录', 409);
            }
            return $snapshot;
        } catch (\JsonException $exception) {
            throw new RuntimeException('测算快照损坏，请核对保存记录', 409);
        }
    }

    private function reply(array $project, ?array $snapshot, ?array $event): array
    {
        return [
            'project_id' => (int)$project['id'], 'hotel_id' => $project['hotel_id'], 'project_name' => $project['project_name'],
            'project_version' => (int)$project['version'], 'scenario_version' => (int)($snapshot['scenario_version'] ?? 0),
            'scenario_event_id' => $event === null ? null : (int)$event['id'], 'saved_at' => $event['created_at'] ?? null,
            'schema_ready' => true, 'input' => $snapshot['input'] ?? null, 'result' => $snapshot['result'] ?? null,
            'capabilities' => ['procurement_reference' => class_exists(ConsumablesProcurementReferenceService::class),
                'actual_consumables_reference' => class_exists(ActualConsumablesScenarioReferenceService::class)],
            'model_version' => $snapshot['model_version'] ?? InvestmentScenarioCalculator::MODEL_VERSION,
            'model_status' => $snapshot !== null && $snapshot['model_version'] !== InvestmentScenarioCalculator::MODEL_VERSION ? 'historical_snapshot' : 'current',
            'content_digest' => $snapshot['content_digest'] ?? null, 'quality_status' => 'scenario_assumption',
            'source_label' => '人工经营测算假设，非实际资金收回', 'readback' => $snapshot === null ? 'no_saved_scenario' : 'exact',
            'actual_cash_written' => false, 'external_write_authorized' => false,
        ];
    }

    private function auditSummary(array $snapshot): array
    {
        return ['scenario_name' => $snapshot['input']['scenario_name'], 'model_version' => $snapshot['model_version'],
            'source_label' => $snapshot['input']['source_label'], 'input_digest' => $snapshot['input_digest'],
            'scenario_version' => $snapshot['scenario_version']];
    }
}
