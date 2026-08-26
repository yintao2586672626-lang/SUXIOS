<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;
use Throwable;

/**
 * Bridges one append-only operating-opportunity run into a human-only review.
 *
 * It deliberately stops at pending_approval. The bridge never approves an
 * intent, creates an execution task, writes OTA/PMS state, or sends a message.
 */
final class OperatingOpportunityApprovalService
{
    public const CONTRACT_VERSION = 'operating_opportunity_pending_approval.v1';

    /** @var array<string,array{key:string,unit:string,label:string}> */
    private const SOURCE_METRICS = [
        'service_promise_risk' => [
            'key' => 'shortage_quantity',
            'unit' => 'count',
            'label' => '短缺数量',
        ],
        'promotion_incrementality' => [
            'key' => 'net_incremental_profit',
            'unit' => 'CNY',
            'label' => '净增量利润',
        ],
        'bookability_gap' => [
            'key' => 'affected_condition_count',
            'unit' => 'count',
            'label' => '受影响条件数',
        ],
        'ai_guest_acquisition' => [
            'key' => 'failed_intent_count',
            'unit' => 'count',
            'label' => '未走到可订的意图数',
        ],
    ];

    public function __construct(
        private ?OperatingOpportunityLabService $lab = null,
        private ?OperatingApprovalIntentService $approval = null,
        private ?OperationManagementService $operations = null
    ) {
        $this->lab ??= new OperatingOpportunityLabService();
        $this->approval ??= new OperatingApprovalIntentService();
        $this->operations ??= new OperationManagementService();
    }

    /** @return array<string,mixed> */
    public function createPendingApproval(
        int $tenantId,
        int $hotelId,
        int $runId,
        int $actorId,
        string $businessDate,
        string $expectedInputDigest,
        string $expectedResultDigest
    ): array {
        if ($tenantId <= 0 || $hotelId <= 0 || $runId <= 0 || $actorId <= 0) {
            throw new InvalidArgumentException('经营机会待审批范围无效');
        }
        $expectedInputDigest = $this->expectedDigest($expectedInputDigest, '输入摘要');
        $expectedResultDigest = $this->expectedDigest($expectedResultDigest, '结果摘要');

        $run = $this->lab->readRun($tenantId, $hotelId, $runId);
        if ((string)($run['feature_key'] ?? '') === 'daily_one_thing') {
            throw new InvalidArgumentException('每日一件事必须通过统一优先事项保存链创建待审批行动');
        }
        if ((string)($run['business_date'] ?? '') !== $businessDate) {
            throw new RuntimeException('经营机会记录与当前业务日期不一致', 409);
        }
        if (!hash_equals($expectedInputDigest, (string)($run['input_digest'] ?? ''))
            || !hash_equals($expectedResultDigest, (string)($run['result_digest'] ?? ''))
        ) {
            throw new RuntimeException('经营机会记录已变化，请刷新后重试', 409);
        }

        $overview = $this->lab->overview($tenantId, $hotelId, $businessDate);
        $this->assertCurrentRun($run, $overview);
        [$sourceRun, $evidenceRuns] = $this->resolveSourceRun($run, $overview);
        $sourceMetric = $this->sourceMetric($sourceRun);
        $metricDefinitionDigest = $this->digest([
            'version' => 'operating_opportunity_source_metric.v1',
            'feature_key' => (string)$sourceRun['feature_key'],
            'metric_key' => (string)$sourceMetric['key'],
            'unit' => (string)$sourceMetric['unit'],
            'scope' => 'tenant_id + hotel_id + business_date + operating_opportunity_run_id',
            'missing_value_policy' => 'indeterminate',
            'causality_claimed' => false,
        ]);

        $evidenceRefs = [];
        foreach ($evidenceRuns as $index => $evidenceRun) {
            $evidenceRefs[] = $this->evidenceRef(
                $evidenceRun,
                $index === 0 && (string)$run['feature_key'] === 'daily_one_thing'
                    ? 'daily_priority'
                    : 'operating_opportunity',
                $metricDefinitionDigest
            );
        }

        $created = $this->approval->createPendingApproval(
            $tenantId,
            $hotelId,
            $businessDate,
            $actorId,
            $evidenceRefs
        );
        $intent = is_array($created['execution_intent'] ?? null) ? $created['execution_intent'] : [];
        $this->assertPendingIntentReadback($intent, $tenantId, $hotelId, $businessDate, $runId);

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'status' => 'pending_approval',
            'persistence_status' => 'readback_verified',
            'reused_existing_intent' => ($created['reused_existing_intent'] ?? false) === true,
            'execution_task_created' => false,
            'external_action_triggered' => false,
            'execution_intent' => $intent,
            'opportunity_scope' => [
                'tenant_id' => $tenantId,
                'system_hotel_id' => $hotelId,
                'business_date' => $businessDate,
                'run_id' => $runId,
                'feature_key' => (string)$run['feature_key'],
                'selected_source_run_id' => (int)$sourceRun['id'],
                'selected_feature_key' => (string)$sourceRun['feature_key'],
                'input_digest' => (string)$run['input_digest'],
                'result_digest' => (string)$run['result_digest'],
                'record_readback_status' => (string)($run['record_readback_status'] ?? ''),
                'fact_status' => (string)($sourceRun['source_quality_status'] ?? 'unverified'),
                'platform' => $this->platform($sourceRun),
                'source_reference' => $sourceRun['source_reference'] ?? null,
                'source_metric' => $sourceMetric + [
                    'definition_digest' => $metricDefinitionDigest,
                ],
                'approval_metric' => [
                    'key' => 'operating_review_decision',
                    'unit' => 'categorical',
                ],
                'approval_purpose' => 'evidence_review_only',
            ],
            'boundaries' => [
                'human_approval_required' => true,
                'automatic_approval' => false,
                'operation_task_created' => false,
                'automatic_execution' => false,
                'ota_write' => false,
                'pms_write' => false,
                'external_message' => false,
            ],
            'next_page' => 'ops-track',
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $runs
     * @return array<string,array<string,mixed>>
     */
    public function linkedApprovals(int $tenantId, int $hotelId, array $runs): array
    {
        $runsById = [];
        foreach ($runs as $run) {
            $id = (int)($run['id'] ?? 0);
            if ($id > 0
                && (int)($run['tenant_id'] ?? 0) === $tenantId
                && (int)($run['system_hotel_id'] ?? 0) === $hotelId
            ) {
                $runsById[$id] = $run;
            }
        }
        if ($runsById === []) {
            return [];
        }

        try {
            $rows = Db::name('operation_execution_intents')
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->where('source_module', OperatingApprovalIntentService::SOURCE_MODULE)
                ->whereIn('source_record_id', array_keys($runsById))
                ->whereNull('deleted_at')
                ->order('id', 'desc')
                ->field('id,source_record_id')
                ->select()
                ->toArray();
        } catch (Throwable $exception) {
            throw new RuntimeException('运营待审批数据表未就绪', 503, $exception);
        }

        $links = [];
        foreach ($rows as $row) {
            $runId = (int)($row['source_record_id'] ?? 0);
            if ($runId <= 0 || isset($links[(string)$runId]) || !isset($runsById[$runId])) {
                continue;
            }
            $intent = $this->operations->readExecutionIntent((int)($row['id'] ?? 0), [$hotelId]);
            $this->assertLinkedIntentReadback($intent, $runsById[$runId], $tenantId, $hotelId);
            $links[(string)$runId] = [
                'contract_version' => self::CONTRACT_VERSION,
                'intent_id' => (int)$intent['id'],
                'run_id' => $runId,
                'tenant_id' => (int)$intent['tenant_id'],
                'hotel_id' => (int)$intent['hotel_id'],
                'business_date' => (string)$intent['date_start'],
                'status' => (string)$intent['status'],
                'persistence_status' => 'readback_verified',
                'task_count' => count((array)($intent['tasks'] ?? [])),
                'fact_status' => (string)($runsById[$runId]['source_quality_status'] ?? 'unverified'),
                'human_approval_required' => true,
                'automatic_external_action' => false,
            ];
        }
        return $links;
    }

    /** @param array<string,mixed> $run @param array<string,mixed> $overview */
    private function assertCurrentRun(array $run, array $overview): void
    {
        if ((string)($run['feature_key'] ?? '') === 'daily_one_thing') {
            if ((string)($overview['today_state'] ?? '') !== 'saved_current'
                || (int)($overview['today_saved_run']['id'] ?? 0) !== (int)$run['id']
            ) {
                throw new RuntimeException('今日一件事已陈旧，请重新生成并保存', 409);
            }
            return;
        }
        foreach ((array)($overview['latest_runs'] ?? []) as $latest) {
            if ((string)($latest['feature_key'] ?? '') === (string)$run['feature_key']) {
                if ((int)($latest['id'] ?? 0) !== (int)$run['id']) {
                    throw new RuntimeException('经营机会结果已陈旧，请刷新最新结果', 409);
                }
                return;
            }
        }
        throw new RuntimeException('当前经营机会结果未完成回读', 409);
    }

    /**
     * @param array<string,mixed> $run
     * @param array<string,mixed> $overview
     * @return array{0:array<string,mixed>,1:array<int,array<string,mixed>>}
     */
    private function resolveSourceRun(array $run, array $overview): array
    {
        if ((string)$run['feature_key'] !== 'daily_one_thing') {
            return [$run, [$run]];
        }
        $result = is_array($run['result'] ?? null) ? $run['result'] : [];
        if ((string)($result['status'] ?? '') !== 'action_required'
            || !is_array($result['selected'] ?? null)
        ) {
            throw new InvalidArgumentException('今日一件事尚未形成可送审事项');
        }
        $selectedRunId = (int)($result['selected']['run_id'] ?? 0);
        $sourceRunIds = array_values(array_map('intval', (array)($run['input']['source_run_ids'] ?? [])));
        if ($selectedRunId <= 0 || !in_array($selectedRunId, $sourceRunIds, true)) {
            throw new RuntimeException('今日一件事的来源记录不完整', 409);
        }
        $selected = $this->lab->readRun(
            (int)$run['tenant_id'],
            (int)$run['system_hotel_id'],
            $selectedRunId
        );
        if ((string)$selected['business_date'] !== (string)$run['business_date']
            || (string)$selected['feature_key'] !== (string)($result['selected']['feature_key'] ?? '')
        ) {
            throw new RuntimeException('今日一件事与来源记录身份不一致', 409);
        }
        $latestMatch = false;
        foreach ((array)($overview['latest_runs'] ?? []) as $latest) {
            if ((int)($latest['id'] ?? 0) === $selectedRunId) {
                $latestMatch = true;
                break;
            }
        }
        if (!$latestMatch) {
            throw new RuntimeException('今日一件事引用的机会结果已陈旧', 409);
        }
        return [$selected, [$run, $selected]];
    }

    /** @param array<string,mixed> $run @return array<string,mixed> */
    private function sourceMetric(array $run): array
    {
        $featureKey = (string)($run['feature_key'] ?? '');
        $definition = self::SOURCE_METRICS[$featureKey] ?? null;
        if (!is_array($definition)) {
            throw new InvalidArgumentException('经营机会功能没有可复核指标定义');
        }
        $result = is_array($run['result'] ?? null) ? $run['result'] : [];
        $provisional = is_array($result['provisional_metrics'] ?? null) ? $result['provisional_metrics'] : [];
        $metricKey = (string)$definition['key'];
        $value = null;
        $provenance = 'formal_result';

        if ($featureKey === 'bookability_gap' && array_key_exists('affected_conditions', $result)) {
            $value = count((array)$result['affected_conditions']);
        } elseif (array_key_exists($metricKey, $result) && is_numeric($result[$metricKey])) {
            $value = $result[$metricKey] + 0;
        } elseif (array_key_exists($metricKey, $provisional) && is_numeric($provisional[$metricKey])) {
            $value = $provisional[$metricKey] + 0;
            $provenance = 'manual_estimate';
        } elseif ($featureKey === 'ai_guest_acquisition'
            && array_key_exists('failure_points_by_intent', $result)
        ) {
            $value = count(array_filter(
                (array)$result['failure_points_by_intent'],
                static fn(mixed $group): bool => is_array($group) && (array)($group['failure_points'] ?? []) !== []
            ));
        } elseif ($featureKey === 'ai_guest_acquisition'
            && is_numeric($provisional['received_observation_count'] ?? null)
        ) {
            $metricKey = 'received_observation_count';
            $definition = [
                'key' => $metricKey,
                'unit' => 'count',
                'label' => '本次人工观测数',
            ];
            $value = $provisional[$metricKey] + 0;
            $provenance = 'manual_estimate';
        }
        if ($value === null) {
            throw new InvalidArgumentException('当前保存结果缺少可复核指标，不能创建待审批');
        }
        return $definition + [
            'key' => $metricKey,
            'value' => $value,
            'status' => $provenance === 'manual_estimate'
                ? 'provisional_manual_estimate'
                : ((bool)($result['decision_eligible'] ?? false) ? 'decision_eligible' : 'calculated_unverified'),
            'provenance' => $provenance,
        ];
    }

    /** @param array<string,mixed> $run @return array<string,mixed> */
    private function evidenceRef(array $run, string $role, string $metricDefinitionDigest): array
    {
        $platform = $this->platform($run);
        return [
            'role' => $role,
            'source_kind' => (string)$run['feature_key'] === 'daily_one_thing'
                ? 'derived_record'
                : 'formal_record',
            'table' => OperatingOpportunityLabService::RUN_TABLE,
            'row_ids' => [(int)$run['id']],
            'platform' => $platform,
            'business_date' => (string)$run['business_date'],
            'fact_scope' => $platform !== '' ? 'ota_channel' : 'hotel_operation',
            'metric_definition_digest' => $metricDefinitionDigest,
            'readback_verified' => true,
        ];
    }

    /** @param array<string,mixed> $run */
    private function platform(array $run): string
    {
        $input = is_array($run['input'] ?? null) ? $run['input'] : [];
        $result = is_array($run['result'] ?? null) ? $run['result'] : [];
        $platform = strtolower(trim((string)($input['platform'] ?? $result['platform'] ?? '')));
        return preg_match('/^[a-z0-9][a-z0-9_.:-]{0,39}$/D', $platform) === 1 ? $platform : '';
    }

    /** @param array<string,mixed> $intent */
    private function assertPendingIntentReadback(
        array $intent,
        int $tenantId,
        int $hotelId,
        string $businessDate,
        int $runId
    ): void {
        if ((int)($intent['id'] ?? 0) <= 0
            || (int)($intent['tenant_id'] ?? 0) !== $tenantId
            || (int)($intent['hotel_id'] ?? 0) !== $hotelId
            || (string)($intent['date_start'] ?? '') !== $businessDate
            || (string)($intent['date_end'] ?? '') !== $businessDate
            || (string)($intent['source_module'] ?? '') !== OperatingApprovalIntentService::SOURCE_MODULE
            || (int)($intent['source_record_id'] ?? 0) !== $runId
            || (string)($intent['status'] ?? '') !== 'pending_approval'
            || (int)($intent['approved_by'] ?? 0) !== 0
            || ($intent['approved_at'] ?? null) !== null
            || (array)($intent['tasks'] ?? []) !== []
            || ($intent['target_value']['auto_write_ota'] ?? null) !== false
            || ($intent['evidence']['boundaries']['automatic_approval'] ?? null) !== false
            || ($intent['evidence']['boundaries']['automatic_execution'] ?? null) !== false
        ) {
            throw new RuntimeException('经营机会待审批单保存后精确回读失败', 409);
        }
    }

    /** @param array<string,mixed> $intent @param array<string,mixed> $run */
    private function assertLinkedIntentReadback(array $intent, array $run, int $tenantId, int $hotelId): void
    {
        $status = (string)($intent['status'] ?? '');
        if ((int)($intent['id'] ?? 0) <= 0
            || (int)($intent['tenant_id'] ?? 0) !== $tenantId
            || (int)($intent['hotel_id'] ?? 0) !== $hotelId
            || (string)($intent['source_module'] ?? '') !== OperatingApprovalIntentService::SOURCE_MODULE
            || (int)($intent['source_record_id'] ?? 0) !== (int)$run['id']
            || (string)($intent['date_start'] ?? '') !== (string)$run['business_date']
            || (string)($intent['date_end'] ?? '') !== (string)$run['business_date']
            || !in_array($status, ['pending_approval', 'approved', 'rejected'], true)
            || ($intent['target_value']['auto_write_ota'] ?? null) !== false
            || ($intent['evidence']['boundaries']['automatic_approval'] ?? null) !== false
            || ($intent['evidence']['boundaries']['automatic_execution'] ?? null) !== false
        ) {
            throw new RuntimeException('经营机会关联待审批单身份回读不一致', 409);
        }
        $hasSourceRef = false;
        foreach ((array)($intent['evidence']['evidence_refs'] ?? []) as $ref) {
            if (is_array($ref)
                && (string)($ref['table'] ?? '') === OperatingOpportunityLabService::RUN_TABLE
                && in_array((int)$run['id'], array_map('intval', (array)($ref['row_ids'] ?? [])), true)
                && ($ref['readback_verified'] ?? false) === true
            ) {
                $hasSourceRef = true;
                break;
            }
        }
        if (!$hasSourceRef || ($status === 'pending_approval' && (array)($intent['tasks'] ?? []) !== [])) {
            throw new RuntimeException('经营机会关联待审批单证据回读不一致', 409);
        }
    }

    private function expectedDigest(string $value, string $label): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new InvalidArgumentException($label . '无效');
        }
        return $value;
    }

    /** @param array<int|string,mixed> $value */
    private function digest(array $value): string
    {
        return hash('sha256', (string)json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }
}
