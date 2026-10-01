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

    public function detail(int $projectId): array
    {
        $project = $this->authorizedProject($projectId);
        $event = $this->latest($projectId);
        if ($event === null) {
            return $this->reply($project, null, null);
        }
        return $this->reply($project, $this->snapshot($event), $event);
    }

    public function preview(int $projectId, array $payload): array
    {
        $project = $this->authorizedProject($projectId);
        $input = $this->input($payload);
        $result = $this->calculator->calculate($input);
        $normalized = $result['input'] ?? $this->calculator->normalize($input);
        return array_replace($this->reply($project, null, null), [
            'input' => $normalized, 'result' => $result,
            'model_version' => $result['model_version'],
            'content_digest' => $this->digest->digest(['input' => $normalized, 'result' => $result]),
            'source_label' => '未保存的经营测算假设', 'readback' => 'preview_only',
        ]);
    }

    public function save(int $projectId, array $payload): array
    {
        if (!isset($payload['expected_version']) || !preg_match('/^[1-9]\d{0,9}$/D', (string)$payload['expected_version'])) {
            throw new InvalidArgumentException('保存测算须提供已读取的项目版本');
        }
        $raw = $this->input($payload);
        $result = $this->calculator->calculate($raw);
        $input = $result['input'] ?? $this->calculator->normalize($raw);
        $inputDigest = $this->digest->digest($input);
        $contentDigest = $this->digest->digest(['input' => $input, 'result' => $result]);
        $action = Db::transaction(function () use ($projectId, $payload, $input, $result, $inputDigest, $contentDigest): string {
            $row = Db::name('investment_payback_projects')->where('tenant_id', $this->tenantId)->where('id', $projectId)->lock(true)->find();
            if (!$row) {
                throw new RuntimeException('项目不存在或不在当前租户范围', 404);
            }
            $project = $this->authorizedProject($projectId);
            if ($row['archived_at'] !== null) {
                throw new RuntimeException('项目已归档，只能查阅历史', 409);
            }
            $previousEvent = $this->latest($projectId);
            $previous = $previousEvent === null ? null : $this->snapshot($previousEvent);
            // A retry of the exact current snapshot has no side effects even if its prior version is stale.
            if ($previous !== null && hash_equals($previous['content_digest'], $contentDigest)) {
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
            ];
            Db::name('investment_payback_projects')->where('tenant_id', $this->tenantId)->where('id', $projectId)->update([
                'version' => $nextVersion, 'updated_by' => $this->actorId, 'updated_at' => $now,
            ]);
            Db::name('investment_payback_events')->insert([
                'tenant_id' => $this->tenantId, 'project_id' => $projectId, 'entry_id' => null, 'actor_id' => $this->actorId,
                'event_type' => 'scenario_saved', 'project_version' => $nextVersion, 'created_at' => $now,
                'payload_json' => json_encode([
                    'before' => $previous === null ? null : $this->auditSummary($previous),
                    'after' => $this->auditSummary($snapshot), 'scenario_snapshot' => $snapshot,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
            ]);
            $readbackEvent = $this->latest($projectId);
            $saved = $this->snapshot($readbackEvent);
            if (!hash_equals($contentDigest, $saved['content_digest']) || $saved['scenario_version'] !== $snapshot['scenario_version']) {
                throw new RuntimeException('测算保存回读不一致，保存已回滚', 409);
            }
            return 'saved';
        });
        return $this->detail($projectId) + ['action' => $action];
    }

    private function authorizedProject(int $id): array
    {
        // Reuse the live project's tenant/hotel/capability checks; source or client fields cannot grant access.
        return $this->ledger->detail($id)['project'];
    }

    private function input(array $payload): array
    {
        if (!isset($payload['scenario']) || !is_array($payload['scenario'])) {
            throw new InvalidArgumentException('经营测算参数须为对象');
        }
        if (strlen(json_encode($payload['scenario'], JSON_THROW_ON_ERROR)) > 150000) {
            throw new InvalidArgumentException('经营测算内容过大');
        }
        return $payload['scenario'];
    }

    private function latest(int $projectId): ?array
    {
        return Db::name('investment_payback_events')->where('tenant_id', $this->tenantId)->where('project_id', $projectId)
            ->where('event_type', 'scenario_saved')->order('id', 'desc')->find() ?: null;
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
