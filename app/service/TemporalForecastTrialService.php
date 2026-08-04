<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use think\facade\Db;

/**
 * Adds a bounded, human-controlled 14-day pilot around an immutable forecast
 * run. Pilot eligibility never weakens the mature operational forecast gate.
 */
final class TemporalForecastTrialService
{
    public const OPERATION_SOURCE_MODULE = 'temporal_forecast_trial';
    public const POLICY_VERSION = 'limited_pilot_v1';

    private const TRIAL_TABLE = 'temporal_forecast_trials';
    private const POINT_TABLE = 'temporal_forecast_trial_points';
    private const FORECAST_TABLE = 'temporal_forecast_snapshots';
    private const REQUIRED_TARGET_DAYS = 14;
    private const REQUIRED_HISTORY_DAYS = 14;
    private const CORE_METRICS = ['ota_revenue', 'ota_orders', 'ota_room_nights'];
    private const ACTIVE_STATUSES = ['draft', 'pending_approval', 'running'];

    /** @var callable|null */
    private $sourceVerifier;

    /** @var callable|null */
    private $actualReader;

    public function __construct(
        private ?TemporalInsightService $temporalInsight = null,
        private ?OperationManagementService $operationManagement = null,
        ?callable $sourceVerifier = null,
        ?callable $actualReader = null
    ) {
        $this->temporalInsight ??= new TemporalInsightService();
        $this->operationManagement ??= new OperationManagementService();
        $this->sourceVerifier = $sourceVerifier;
        $this->actualReader = $actualReader;
    }

    /** @return array<string, mixed> */
    public function createTrial(int $hotelId, string $forecastRunId, int $createdBy): array
    {
        $forecastRunId = trim($forecastRunId);
        if ($hotelId <= 0 || $forecastRunId === '') {
            throw new InvalidArgumentException('创建 14 天试运营前必须明确酒店和预测版本。');
        }
        $this->ensureTables();
        $tenantId = $this->tenantIdForHotel($hotelId);

        $existing = Db::name(self::TRIAL_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('forecast_run_id', $forecastRunId)
            ->find();
        if (is_array($existing)) {
            $result = $this->readTrial((int)$existing['id'], $hotelId);
            $result['idempotent_replay'] = true;
            return $result;
        }
        $active = Db::name(self::TRIAL_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->find();
        if (is_array($active)) {
            throw new InvalidArgumentException('该酒店已有未结束的预测试运营批次，请先完成、停止或拒绝现有批次。');
        }

        $rows = Db::name(self::FORECAST_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('forecast_run_id', $forecastRunId)
            ->whereIn('metric_key', self::CORE_METRICS)
            ->order('metric_key', 'asc')
            ->order('target_date', 'asc')
            ->select()
            ->toArray();
        $eligibility = $this->assessEligibility($rows, $tenantId, $hotelId, $forecastRunId);
        if (($eligibility['eligible'] ?? false) !== true) {
            throw new InvalidArgumentException(
                '当前预测版本不能进入 14 天限定试运营：' . implode('；', (array)($eligibility['reasons'] ?? []))
            );
        }

        $trialVersion = 'tft_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(8)), 0, 16);
        $now = date('Y-m-d H:i:s');
        $pilotPolicy = $this->pilotPolicy();
        $maturePolicy = $this->temporalInsight->matureOperationalPolicy();
        $pointRows = [];
        $sourceIdentities = [];
        foreach ($rows as $row) {
            $sourceRefs = $this->decodeArray($row['source_refs_json'] ?? null);
            $locked = [
                'tenant_id' => $tenantId,
                'system_hotel_id' => $hotelId,
                'forecast_snapshot_id' => (int)$row['id'],
                'forecast_run_id' => $forecastRunId,
                'metric_scope' => 'ota_channel',
                'platform' => 'all_ota',
                'metric_key' => (string)$row['metric_key'],
                'target_date' => (string)$row['target_date'],
                'horizon_days' => (int)$row['horizon_days'],
                'predicted_value' => $this->nullableFloat($row['predicted_value'] ?? null),
                'lower_bound' => $this->nullableFloat($row['lower_bound'] ?? null),
                'upper_bound' => $this->nullableFloat($row['upper_bound'] ?? null),
                'sample_days' => (int)$row['sample_days'],
                'source_refs' => $sourceRefs,
            ];
            $pointRows[] = [
                'tenant_id' => $tenantId,
                'system_hotel_id' => $hotelId,
                'forecast_snapshot_id' => (int)$row['id'],
                'metric_key' => (string)$row['metric_key'],
                'target_date' => (string)$row['target_date'],
                'horizon_days' => (int)$row['horizon_days'],
                'predicted_value' => $locked['predicted_value'],
                'lower_bound' => $locked['lower_bound'],
                'upper_bound' => $locked['upper_bound'],
                'sample_days' => (int)$row['sample_days'],
                'source_refs_json' => $this->json($sourceRefs),
                'point_digest' => $this->digest($locked),
                'actual_status' => 'pending_actual',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $sourceIdentities[] = [
                'forecast_snapshot_id' => (int)$row['id'],
                'metric_key' => (string)$row['metric_key'],
                'target_date' => (string)$row['target_date'],
                'source_identity_digest' => (string)($sourceRefs['source_identity_digest'] ?? ''),
                'point_digest' => $pointRows[count($pointRows) - 1]['point_digest'],
            ];
        }

        $headerIdentity = [
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'trial_version' => $trialVersion,
            'forecast_run_id' => $forecastRunId,
            'policy_version' => self::POLICY_VERSION,
            'metric_scope' => 'ota_channel',
            'platform' => 'all_ota',
            'start_date' => (string)$eligibility['start_date'],
            'end_date' => (string)$eligibility['end_date'],
            'required_target_days' => self::REQUIRED_TARGET_DAYS,
            'required_history_days' => self::REQUIRED_HISTORY_DAYS,
            'core_metrics' => self::CORE_METRICS,
            'pilot_policy' => $pilotPolicy,
            'mature_policy' => $maturePolicy,
            'source_identities' => $sourceIdentities,
        ];
        $header = [
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'trial_version' => $trialVersion,
            'forecast_run_id' => $forecastRunId,
            'policy_version' => self::POLICY_VERSION,
            'metric_scope' => 'ota_channel',
            'platform' => 'all_ota',
            'start_date' => (string)$eligibility['start_date'],
            'end_date' => (string)$eligibility['end_date'],
            'required_target_days' => self::REQUIRED_TARGET_DAYS,
            'required_history_days' => self::REQUIRED_HISTORY_DAYS,
            'core_metrics_json' => $this->json(self::CORE_METRICS),
            'pilot_policy_json' => $this->json($pilotPolicy),
            'mature_policy_json' => $this->json($maturePolicy),
            'source_identity_json' => $this->json($sourceIdentities),
            'immutable_digest' => $this->digest($headerIdentity),
            'eligibility_status' => 'eligible',
            'maturity_status' => 'accruing',
            'status' => 'draft',
            'created_by' => max(0, $createdBy),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $trialId = Db::transaction(function () use ($header, $pointRows): int {
            $active = Db::name(self::TRIAL_TABLE)
                ->where('tenant_id', (int)$header['tenant_id'])
                ->where('system_hotel_id', (int)$header['system_hotel_id'])
                ->whereIn('status', self::ACTIVE_STATUSES)
                ->lock(true)
                ->find();
            if (is_array($active)) {
                throw new InvalidArgumentException('该酒店已有未结束的预测试运营批次，本次创建已取消。');
            }
            $trialId = (int)Db::name(self::TRIAL_TABLE)->insertGetId($header);
            if ($trialId <= 0) {
                throw new RuntimeException('14 天试运营批次保存失败。');
            }
            foreach ($pointRows as &$pointRow) {
                $pointRow['trial_id'] = $trialId;
            }
            unset($pointRow);
            $saved = (int)Db::name(self::POINT_TABLE)->insertAll($pointRows);
            $storedHeader = Db::name(self::TRIAL_TABLE)->where('id', $trialId)->find();
            $storedPoints = Db::name(self::POINT_TABLE)
                ->where('trial_id', $trialId)
                ->order('metric_key', 'asc')
                ->order('target_date', 'asc')
                ->select()
                ->toArray();
            if ($saved !== count($pointRows)
                || !$this->storedTrialMatches($header, $pointRows, $storedHeader, $storedPoints)
            ) {
                throw new RuntimeException('14 天试运营保存后精确回读不一致，事务已回滚。');
            }
            return $trialId;
        });

        $result = $this->readTrial($trialId, $hotelId);
        $result['idempotent_replay'] = false;
        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public function assessEligibility(array $rows, int $tenantId, int $hotelId, string $forecastRunId): array
    {
        $reasons = [];
        $reasonCodes = [];
        $byMetric = array_fill_keys(self::CORE_METRICS, []);
        $asOfDates = [];
        foreach ($rows as $row) {
            $metric = strtolower(trim((string)($row['metric_key'] ?? '')));
            if (!array_key_exists($metric, $byMetric)) {
                continue;
            }
            if ((int)($row['tenant_id'] ?? 0) !== $tenantId
                || (int)($row['system_hotel_id'] ?? 0) !== $hotelId
                || (string)($row['forecast_run_id'] ?? '') !== $forecastRunId
                || (string)($row['metric_scope'] ?? '') !== 'ota_channel'
                || (string)($row['platform'] ?? '') !== 'all_ota'
            ) {
                $reasonCodes['forecast_identity_mismatch'] = true;
                continue;
            }
            $targetDate = trim((string)($row['target_date'] ?? ''));
            $horizon = (int)($row['horizon_days'] ?? 0);
            if (!$this->validDate($targetDate)
                || $horizon < 1
                || $horizon > self::REQUIRED_TARGET_DAYS
                || $this->nullableFloat($row['predicted_value'] ?? null) === null
                || $this->nullableFloat($row['lower_bound'] ?? null) === null
                || $this->nullableFloat($row['upper_bound'] ?? null) === null
            ) {
                $reasonCodes['forecast_point_incomplete'] = true;
                continue;
            }
            if ((int)($row['sample_days'] ?? 0) < self::REQUIRED_HISTORY_DAYS) {
                $reasonCodes['trusted_history_lt_14'] = true;
            }
            if (!$this->sourceVerified($row)) {
                $reasonCodes['forecast_source_identity_unverified'] = true;
            }
            $byMetric[$metric][$targetDate] = $row;
            $asOfDates[(string)($row['as_of_date'] ?? '')] = true;
        }

        if (count($rows) !== count(self::CORE_METRICS) * self::REQUIRED_TARGET_DAYS) {
            $reasonCodes['forecast_point_count_not_42'] = true;
        }
        if (count($asOfDates) !== 1 || !$this->validDate((string)array_key_first($asOfDates))) {
            $reasonCodes['forecast_as_of_identity_mismatch'] = true;
        }
        $expectedDates = [];
        $asOfDate = (string)array_key_first($asOfDates);
        if ($this->validDate($asOfDate)) {
            for ($day = 1; $day <= self::REQUIRED_TARGET_DAYS; $day++) {
                $expectedDates[] = (new DateTimeImmutable($asOfDate))->modify("+{$day} days")->format('Y-m-d');
            }
        }
        foreach (self::CORE_METRICS as $metric) {
            $dates = array_keys($byMetric[$metric]);
            sort($dates, SORT_STRING);
            if ($dates !== $expectedDates) {
                $reasonCodes['metric_target_dates_not_14_consecutive'] = true;
            }
            foreach ($byMetric[$metric] as $date => $row) {
                $expectedHorizon = array_search($date, $expectedDates, true);
                if ($expectedHorizon === false || (int)$row['horizon_days'] !== $expectedHorizon + 1) {
                    $reasonCodes['forecast_horizon_mismatch'] = true;
                }
            }
        }

        $messages = [
            'forecast_identity_mismatch' => '预测点的租户、酒店、范围或版本身份不一致',
            'forecast_point_incomplete' => '预测值、区间、日期或周期不完整',
            'trusted_history_lt_14' => '收入、订单或间夜的可信历史覆盖不足 14 天',
            'forecast_source_identity_unverified' => '预测点来源身份未通过严格校验',
            'forecast_point_count_not_42' => '三个核心指标没有形成 14 天共 42 个预测点',
            'forecast_as_of_identity_mismatch' => '预测点不属于同一个 as_of_date',
            'metric_target_dates_not_14_consecutive' => '三个核心指标未覆盖同一组连续 14 个目标日',
            'forecast_horizon_mismatch' => '目标日期与 T+1 至 T+14 周期不一致',
        ];
        foreach (array_keys($reasonCodes) as $code) {
            $reasons[] = $messages[$code] ?? $code;
        }
        return [
            'eligible' => $reasonCodes === [],
            'eligibility_status' => $reasonCodes === [] ? 'limited_pilot_eligible' : 'blocked',
            'reason_codes' => array_keys($reasonCodes),
            'reasons' => $reasons,
            'required_history_days' => self::REQUIRED_HISTORY_DAYS,
            'required_target_days' => self::REQUIRED_TARGET_DAYS,
            'core_metrics' => self::CORE_METRICS,
            'start_date' => $expectedDates[0] ?? '',
            'end_date' => $expectedDates[self::REQUIRED_TARGET_DAYS - 1] ?? '',
            'mature_operational_gate_preserved' => true,
        ];
    }

    /** @return array<string, mixed> */
    public function readTrial(int $trialId, int $hotelId): array
    {
        $this->ensureTables();
        if ($trialId <= 0 || $hotelId <= 0) {
            throw new InvalidArgumentException('试运营批次和酒店身份无效。');
        }
        // A hotel id by itself is not a sufficient identity contract. Resolve
        // the tenant from the requested hotel before returning any trial data.
        $tenantId = $this->tenantIdForHotel($hotelId);
        $trial = Db::name(self::TRIAL_TABLE)
            ->where('id', $trialId)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->find();
        if (!is_array($trial)) {
            throw new InvalidArgumentException('未找到该酒店的试运营批次。');
        }
        $points = Db::name(self::POINT_TABLE)
            ->where('trial_id', $trialId)
            ->where('tenant_id', (int)$trial['tenant_id'])
            ->where('system_hotel_id', $hotelId)
            ->order('target_date', 'asc')
            ->order('metric_key', 'asc')
            ->select()
            ->toArray();
        $readbackVerified = $this->storedImmutableDigestMatches($trial, $points);
        if (!$readbackVerified) {
            throw new RuntimeException('试运营批次不可变字段回读校验失败。');
        }

        $operationFlow = null;
        $intentId = (int)($trial['operation_intent_id'] ?? 0);
        if ($intentId > 0 && $this->tableExists('operation_execution_intents')) {
            try {
                $operationFlow = $this->operationManagement->readExecutionIntent($intentId, [$hotelId]);
            } catch (Throwable $e) {
                $operationFlow = [
                    'status' => 'readback_failed',
                    'reason' => $e->getMessage(),
                    'intent_id' => $intentId,
                ];
            }
        }
        $normalizedTrial = $this->normalizeTrial($trial);
        if (!$this->finalReviewDigestMatches((array)($normalizedTrial['final_review'] ?? []))) {
            throw new RuntimeException('试运营最终复盘摘要回读校验失败。');
        }

        return [
            'trial' => $normalizedTrial,
            'points' => array_map(fn(array $row): array => $this->normalizePoint($row), $points),
            'actual_summary' => $this->summarizePoints($points),
            'operation_flow' => $operationFlow,
            'readback_verified' => true,
            'readback_count' => count($points),
            'automatic_price_write' => false,
            'metric_scope' => 'ota_channel',
            'causality_claimed' => false,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function listTrials(int $hotelId, int $limit = 20): array
    {
        $this->ensureTables();
        if ($hotelId <= 0) {
            throw new InvalidArgumentException('请选择要查看试运营批次的酒店。');
        }
        $tenantId = $this->tenantIdForHotel($hotelId);
        $rows = Db::name(self::TRIAL_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->order('id', 'desc')
            ->limit(max(1, min(100, $limit)))
            ->select()
            ->toArray();
        return array_map(fn(array $row): array => $this->normalizeTrial($row), $rows);
    }

    /** @return array<string, mixed> */
    public function createOperationReviewIntent(int $trialId, int $hotelId, int $userId): array
    {
        $snapshot = $this->readTrial($trialId, $hotelId);
        $trial = (array)$snapshot['trial'];
        if ((string)$trial['status'] !== 'draft') {
            if ((int)($trial['operation_intent_id'] ?? 0) > 0) {
                $snapshot['idempotent_replay'] = true;
                return $snapshot;
            }
            throw new InvalidArgumentException('只有草稿状态的试运营批次可以送人工审批。');
        }
        if ((string)$trial['end_date'] < date('Y-m-d')) {
            throw new InvalidArgumentException('该试运营日期范围已经全部到期，不能新建执行任务。');
        }
        $checkpoints = [];
        foreach (array_values(array_unique(array_column((array)$snapshot['points'], 'target_date'))) as $date) {
            $checkpoints[] = [
                'target_date' => (string)$date,
                'status' => (string)$date < date('Y-m-d') ? 'actual_due' : 'pending_actual',
            ];
        }
        $input = [
            'source_module' => self::OPERATION_SOURCE_MODULE,
            'source_record_id' => $trialId,
            'hotel_id' => $hotelId,
            'platform' => 'all_ota',
            'object_type' => 'operation_checklist',
            'action_type' => 'manual_forecast_pilot',
            'date_start' => (string)$trial['start_date'],
            'date_end' => (string)$trial['end_date'],
            'current_value' => [
                'trial_version' => (string)$trial['trial_version'],
                'forecast_run_id' => (string)$trial['forecast_run_id'],
                'immutable_digest' => (string)$trial['immutable_digest'],
                'maturity_status' => (string)$trial['maturity_status'],
                'required_history_days' => self::REQUIRED_HISTORY_DAYS,
                'target_days' => self::REQUIRED_TARGET_DAYS,
            ],
            'target_value' => [
                'title' => '14 天 OTA 收益预测限定试运营',
                'action_text' => '人工检查预测区间、数据缺口和到期实际值；不得自动写价。',
                'target_metric' => 'ota_revenue',
                'daily_checkpoints' => $checkpoints,
                'workflow_schedule' => [
                    'assignee_id' => $userId,
                    'due_at' => (string)$trial['end_date'] . ' 20:00:00',
                    'review_at' => (new DateTimeImmutable((string)$trial['end_date']))
                        ->modify('+1 day')->format('Y-m-d') . ' 10:00:00',
                    'source_policy' => 'human_approval_then_manual_monitoring_then_finalized_actual_readback',
                ],
                'expected_delta_status' => 'not_quantified',
                'automatic_price_write' => false,
            ],
            'evidence' => [
                'evidence_refs' => [[
                    'table' => self::TRIAL_TABLE,
                    'row_id' => $trialId,
                    'trial_version' => (string)$trial['trial_version'],
                    'forecast_run_id' => (string)$trial['forecast_run_id'],
                    'immutable_digest' => (string)$trial['immutable_digest'],
                ]],
                'pilot_policy' => $trial['pilot_policy'],
                'mature_operational_policy' => $trial['mature_policy'],
                'review_required' => true,
                'automatic_price_write' => false,
                'protected_boundary' => 'Limited OTA-channel pilot only; no automatic price, inventory or OTA write.',
            ],
            'expected_metric' => 'ota_revenue',
            'expected_delta' => null,
            'risk_level' => 'medium',
        ];
        $idempotencyKey = 'temporal_forecast_trial:v1:' . $trialId . ':'
            . substr((string)$trial['immutable_digest'], 0, 32);
        $intent = $this->operationManagement->createExecutionIntent(
            [$hotelId],
            $hotelId,
            $input,
            $userId,
            false,
            $idempotencyKey,
            true
        );
        if ((string)($intent['status'] ?? '') !== 'pending_approval'
            || !empty($intent['tasks'])
            || trim((string)($intent['blocked_reason'] ?? '')) !== ''
        ) {
            throw new RuntimeException('试运营送审未严格回读为待审批且无任务状态。');
        }
        $updated = (int)Db::name(self::TRIAL_TABLE)
            ->where('id', $trialId)
            ->where('tenant_id', (int)$trial['tenant_id'])
            ->where('system_hotel_id', $hotelId)
            ->where('status', 'draft')
            ->update([
                'operation_intent_id' => (int)$intent['id'],
                'status' => 'pending_approval',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('试运营送审后批次状态回写失败。');
        }
        $result = $this->readTrial($trialId, $hotelId);
        $result['task_created'] = false;
        $result['review_required'] = true;
        $result['idempotent_replay'] = false;
        return $result;
    }

    /** @return array<string, mixed> */
    public function refreshActuals(int $trialId, int $hotelId): array
    {
        $snapshot = $this->readTrial($trialId, $hotelId);
        if ((string)($snapshot['trial']['status'] ?? '') !== 'running') {
            throw new InvalidArgumentException('只有人工审批已通过且仍在运行的试运营批次可以回读到期实际值。');
        }
        $now = date('Y-m-d H:i:s');
        $updates = [];
        foreach ((array)$snapshot['points'] as $point) {
            $pointId = (int)($point['id'] ?? 0);
            $targetDate = (string)($point['target_date'] ?? '');
            if ($pointId <= 0 || $targetDate >= date('Y-m-d')) {
                continue;
            }
            $actual = $this->readActual(
                (int)$point['forecast_snapshot_id'],
                $hotelId,
                (string)$point['metric_key'],
                $targetDate
            );
            if ((string)($actual['status'] ?? '') === 'ready'
                && is_numeric($actual['actual_value'] ?? null)
                && is_numeric($actual['absolute_error'] ?? null)
            ) {
                $updates[$pointId] = [
                    'actual_status' => 'ready',
                    'actual_value' => (float)$actual['actual_value'],
                    'absolute_error' => (float)$actual['absolute_error'],
                    'within_range' => ($actual['within_range'] ?? false) === true ? 1 : 0,
                    'actual_readback_json' => $this->json($actual),
                    'actual_reason_code' => null,
                    'actual_readback_at' => (string)($actual['readback_at'] ?? $now),
                    'updated_at' => $now,
                ];
            } else {
                $updates[$pointId] = [
                    'actual_status' => 'unavailable',
                    'actual_value' => null,
                    'absolute_error' => null,
                    'within_range' => null,
                    'actual_readback_json' => $this->json($actual),
                    'actual_reason_code' => mb_substr(trim((string)($actual['reason_code'] ?? 'actual_readback_unavailable')), 0, 100),
                    'actual_readback_at' => null,
                    'updated_at' => $now,
                ];
            }
        }

        $tenantId = (int)($snapshot['trial']['tenant_id'] ?? 0);
        Db::transaction(function () use ($trialId, $tenantId, $hotelId, $updates, $now): void {
            $lockedTrial = Db::name(self::TRIAL_TABLE)
                ->where('id', $trialId)
                ->where('tenant_id', $tenantId)
                ->where('system_hotel_id', $hotelId)
                ->lock(true)
                ->find();
            if (!is_array($lockedTrial) || (string)($lockedTrial['status'] ?? '') !== 'running') {
                throw new RuntimeException('试运营状态已变化，本次实际值回读已取消。');
            }
            foreach ($updates as $pointId => $update) {
                $affected = (int)Db::name(self::POINT_TABLE)
                    ->where('id', $pointId)
                    ->where('trial_id', $trialId)
                    ->where('tenant_id', $tenantId)
                    ->where('system_hotel_id', $hotelId)
                    ->update($update);
                if ($affected !== 1) {
                    $stored = Db::name(self::POINT_TABLE)
                        ->where('id', $pointId)
                        ->where('tenant_id', $tenantId)
                        ->where('system_hotel_id', $hotelId)
                        ->find();
                    if (!is_array($stored) || !$this->actualUpdateMatches($update, $stored)) {
                        throw new RuntimeException('到期实际值保存后回读不一致。');
                    }
                }
            }
            $points = Db::name(self::POINT_TABLE)->where('trial_id', $trialId)->select()->toArray();
            $summary = $this->summarizePoints($points);
            $maturityStatus = (int)$summary['ready_points'] === (int)$summary['total_points']
                ? 'matured'
                : 'accruing';
            $headerUpdated = (int)Db::name(self::TRIAL_TABLE)
                ->where('id', $trialId)
                ->where('tenant_id', $tenantId)
                ->where('system_hotel_id', $hotelId)
                ->where('status', 'running')
                ->update(['maturity_status' => $maturityStatus, 'updated_at' => $now]);
            if ($headerUpdated !== 1) {
                $storedHeader = Db::name(self::TRIAL_TABLE)
                    ->where('id', $trialId)
                    ->where('tenant_id', $tenantId)
                    ->where('system_hotel_id', $hotelId)
                    ->find();
                if (!is_array($storedHeader)
                    || (string)($storedHeader['status'] ?? '') !== 'running'
                    || (string)($storedHeader['maturity_status'] ?? '') !== $maturityStatus
                ) {
                    throw new RuntimeException('到期实际值汇总状态保存失败或试运营状态已变化。');
                }
            }
        });
        $result = $this->readTrial($trialId, $hotelId);
        $result['actual_refresh_count'] = count($updates);
        return $result;
    }

    /** @return array<string, mixed> */
    public function finalizeReview(
        int $trialId,
        int $hotelId,
        int $reviewerId,
        string $decision,
        string $note = ''
    ): array {
        $snapshot = $this->readTrial($trialId, $hotelId);
        $trial = (array)$snapshot['trial'];
        $summary = (array)$snapshot['actual_summary'];
        $decision = strtolower(trim($decision));
        $note = mb_substr(trim($note), 0, 1000);
        if (!in_array($decision, ['continue_limited_pilot', 'revise', 'stop', 'insufficient_evidence'], true)) {
            throw new InvalidArgumentException('最终复盘决定必须为继续限定试运营、修订、停止或证据不足。');
        }
        $currentStatus = (string)($trial['status'] ?? '');
        if ($currentStatus !== 'running') {
            $storedReview = is_array($trial['final_review'] ?? null) ? $trial['final_review'] : [];
            if (in_array($currentStatus, ['reviewed', 'stopped'], true)
                && (string)($storedReview['decision'] ?? '') === $decision
                && (string)($storedReview['note'] ?? '') === $note
            ) {
                $snapshot['idempotent_replay'] = true;
                return $snapshot;
            }
            throw new InvalidArgumentException('只有仍在运行且尚未结案的试运营批次可以保存最终复盘。');
        }
        if ((string)($trial['maturity_status'] ?? '') !== 'matured'
            || (int)($summary['ready_points'] ?? 0) !== (int)($summary['total_points'] ?? -1)
        ) {
            throw new InvalidArgumentException('14 天目标实际值尚未全部成熟，不能完成最终复盘。');
        }
        $tasks = is_array($snapshot['operation_flow']['tasks'] ?? null)
            ? $snapshot['operation_flow']['tasks']
            : [];
        if ((int)($trial['operation_intent_id'] ?? 0) <= 0 || $tasks === []) {
            throw new InvalidArgumentException('试运营尚未完成“人工审批 → 运营任务”链路，不能结案。');
        }
        $review = [
            'contract_version' => 'temporal_forecast_trial_review.v1',
            'trial_id' => $trialId,
            'trial_version' => (string)$trial['trial_version'],
            'forecast_run_id' => (string)$trial['forecast_run_id'],
            'decision' => $decision,
            'note' => $note,
            'descriptive_accuracy' => $summary,
            'mature_operational_status' => 're_evaluate_with_existing_21_day_10_sample_60_percent_gate',
            'accuracy_pass_threshold' => null,
            'accuracy_pass_threshold_status' => 'not_defined_do_not_invent',
            'operation_outcome_status' => 'reviewed_separately_from_forecast_accuracy',
            'causality_claimed' => false,
            'automatic_price_write' => false,
            'reviewed_by' => $reviewerId,
            'reviewed_at' => date('Y-m-d H:i:s'),
        ];
        $review['review_digest'] = $this->digest($review);
        $status = $decision === 'stop' ? 'stopped' : 'reviewed';
        $tenantId = (int)($trial['tenant_id'] ?? 0);
        Db::transaction(function () use ($trialId, $tenantId, $hotelId, $reviewerId, $review, $status, $decision): void {
            $lockedTrial = Db::name(self::TRIAL_TABLE)
                ->where('id', $trialId)
                ->where('tenant_id', $tenantId)
                ->where('system_hotel_id', $hotelId)
                ->lock(true)
                ->find();
            if (!is_array($lockedTrial)
                || (string)($lockedTrial['status'] ?? '') !== 'running'
                || (string)($lockedTrial['maturity_status'] ?? '') !== 'matured'
            ) {
                throw new RuntimeException('最终复盘保存失败或试运营状态已变化。');
            }
            $affected = (int)Db::name(self::TRIAL_TABLE)
                ->where('id', $trialId)
                ->where('tenant_id', $tenantId)
                ->where('system_hotel_id', $hotelId)
                ->where('status', 'running')
                ->where('maturity_status', 'matured')
                ->update([
                    'status' => $status,
                    'final_review_json' => $this->json($review),
                    'reviewed_by' => $reviewerId,
                    'reviewed_at' => $review['reviewed_at'],
                    'stopped_reason' => $decision === 'stop' ? $review['note'] : null,
                    'updated_at' => $review['reviewed_at'],
                ]);
            if ($affected !== 1) {
                throw new RuntimeException('最终复盘保存失败或状态已变化。');
            }
        });
        $result = $this->readTrial($trialId, $hotelId);
        $storedReview = (array)($result['trial']['final_review'] ?? []);
        if ((string)($storedReview['review_digest'] ?? '') !== (string)$review['review_digest']
            || (string)($storedReview['decision'] ?? '') !== $decision
            || (string)($storedReview['note'] ?? '') !== $note
        ) {
            throw new RuntimeException('最终复盘保存后精确回读不一致。');
        }
        $result['idempotent_replay'] = false;
        return $result;
    }

    /** @param array<string, mixed> $intent */
    public function assertOperationIntentCurrent(array $intent): void
    {
        $trialId = (int)($intent['source_record_id'] ?? 0);
        $hotelId = (int)($intent['hotel_id'] ?? 0);
        if ($trialId <= 0 || $hotelId <= 0) {
            throw new InvalidArgumentException('预测试运营送审身份无效。');
        }
        $snapshot = $this->readTrial($trialId, $hotelId);
        $trial = (array)$snapshot['trial'];
        $currentValue = is_array($intent['current_value'] ?? null) ? $intent['current_value'] : [];
        $targetValue = is_array($intent['target_value'] ?? null) ? $intent['target_value'] : [];
        $evidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
        if ((string)($trial['status'] ?? '') !== 'pending_approval'
            || (int)($trial['operation_intent_id'] ?? 0) !== (int)($intent['id'] ?? 0)
            || !hash_equals((string)$trial['immutable_digest'], (string)($currentValue['immutable_digest'] ?? ''))
            || (string)$trial['trial_version'] !== (string)($currentValue['trial_version'] ?? '')
            || (string)$trial['forecast_run_id'] !== (string)($currentValue['forecast_run_id'] ?? '')
            || ($targetValue['automatic_price_write'] ?? null) !== false
            || ($evidence['automatic_price_write'] ?? null) !== false
        ) {
            throw new InvalidArgumentException('预测试运营版本、来源或人工审核边界已变化，不能审批。');
        }
        if ((string)$trial['end_date'] < date('Y-m-d')) {
            throw new InvalidArgumentException('预测试运营已全部到期，不能再审批执行任务。');
        }
    }

    public function syncApprovalDecision(
        int $trialId,
        int $hotelId,
        int $intentId,
        bool $approved,
        int $userId,
        string $approvedAt
    ): void {
        if (!$this->tableExists(self::TRIAL_TABLE)) {
            throw new RuntimeException('预测试运营表缺失，不能同步审批决定。');
        }
        $tenantId = $this->tenantIdForHotel($hotelId);
        $status = $approved ? 'running' : 'rejected';
        $affected = (int)Db::name(self::TRIAL_TABLE)
            ->where('id', $trialId)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('operation_intent_id', $intentId)
            ->where('status', 'pending_approval')
            ->update([
                'status' => $status,
                'approved_by' => $userId,
                'approved_at' => $approvedAt,
                'updated_at' => $approvedAt,
            ]);
        if ($affected !== 1) {
            throw new RuntimeException('预测试运营审批决定未能精确同步。');
        }
        $stored = Db::name(self::TRIAL_TABLE)
            ->where('id', $trialId)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->find();
        if (!is_array($stored)
            || (string)($stored['status'] ?? '') !== $status
            || (int)($stored['approved_by'] ?? 0) !== $userId
            || (string)($stored['approved_at'] ?? '') !== $approvedAt
        ) {
            throw new RuntimeException('预测试运营审批决定保存后回读不一致。');
        }
    }

    /**
     * @param array<int, array<string, mixed>> $points
     * @return array<string, mixed>
     */
    public function summarizePoints(array $points): array
    {
        $metrics = [];
        $dates = [];
        $readyPoints = 0;
        foreach (self::CORE_METRICS as $metric) {
            $metrics[$metric] = [
                'metric_key' => $metric,
                'total_points' => 0,
                'ready_points' => 0,
                'pending_points' => 0,
                'unavailable_points' => 0,
                '_absolute_error_sum' => 0.0,
                '_actual_sum' => 0.0,
                '_range_hits' => 0,
            ];
        }
        foreach ($points as $point) {
            $metric = (string)($point['metric_key'] ?? '');
            if (!isset($metrics[$metric])) {
                continue;
            }
            $date = (string)($point['target_date'] ?? '');
            $status = (string)($point['actual_status'] ?? 'pending_actual');
            $metrics[$metric]['total_points']++;
            $dates[$date][$metric] = $status;
            if ($status === 'ready'
                && is_numeric($point['actual_value'] ?? null)
                && is_numeric($point['absolute_error'] ?? null)
            ) {
                $metrics[$metric]['ready_points']++;
                $metrics[$metric]['_absolute_error_sum'] += (float)$point['absolute_error'];
                $metrics[$metric]['_actual_sum'] += abs((float)$point['actual_value']);
                $metrics[$metric]['_range_hits'] += (int)($point['within_range'] ?? 0) === 1 ? 1 : 0;
                $readyPoints++;
            } elseif ($status === 'unavailable') {
                $metrics[$metric]['unavailable_points']++;
            } else {
                $metrics[$metric]['pending_points']++;
            }
        }
        foreach ($metrics as &$metric) {
            $ready = (int)$metric['ready_points'];
            $metric['mae'] = $ready > 0 ? round($metric['_absolute_error_sum'] / $ready, 4) : null;
            $metric['wape_percent'] = $ready > 0 && $metric['_actual_sum'] > 0
                ? round(100 * $metric['_absolute_error_sum'] / $metric['_actual_sum'], 2)
                : null;
            $metric['range_hit_rate_percent'] = $ready > 0
                ? round(100 * $metric['_range_hits'] / $ready, 2)
                : null;
            unset($metric['_absolute_error_sum'], $metric['_actual_sum'], $metric['_range_hits']);
        }
        unset($metric);
        $maturedDates = 0;
        foreach ($dates as $statuses) {
            if (count($statuses) === count(self::CORE_METRICS)
                && count(array_filter($statuses, static fn(string $status): bool => $status === 'ready')) === count(self::CORE_METRICS)
            ) {
                $maturedDates++;
            }
        }
        return [
            'total_points' => count(self::CORE_METRICS) * self::REQUIRED_TARGET_DAYS,
            'ready_points' => $readyPoints,
            'matured_target_days' => $maturedDates,
            'required_target_days' => self::REQUIRED_TARGET_DAYS,
            'metrics' => array_values($metrics),
            'descriptive_only' => true,
            'accuracy_pass_threshold' => null,
            'accuracy_pass_threshold_status' => 'not_defined_do_not_invent',
            'causality_claimed' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function pilotPolicy(): array
    {
        return [
            'policy_version' => self::POLICY_VERSION,
            'eligibility_mode' => 'limited_pilot',
            'metric_scope' => 'ota_channel',
            'platform' => 'all_ota',
            'core_metrics' => self::CORE_METRICS,
            'required_history_days_per_metric' => self::REQUIRED_HISTORY_DAYS,
            'required_consecutive_target_days' => self::REQUIRED_TARGET_DAYS,
            'source_identity_required' => true,
            'execution_mode' => 'human_review_only',
            'automatic_price_write' => false,
            'maturity_initial_status' => 'accruing',
            'accuracy_metrics' => ['mae', 'wape_percent', 'range_hit_rate_percent'],
            'accuracy_pass_threshold' => null,
            'accuracy_pass_threshold_status' => 'not_defined_do_not_invent',
            'causality_claimed' => false,
        ];
    }

    /** @param array<string, mixed> $trial @return array<string, mixed> */
    private function normalizeTrial(array $trial): array
    {
        foreach (['core_metrics_json', 'pilot_policy_json', 'mature_policy_json', 'source_identity_json', 'final_review_json'] as $field) {
            $target = match ($field) {
                'core_metrics_json' => 'core_metrics',
                'pilot_policy_json' => 'pilot_policy',
                'mature_policy_json' => 'mature_policy',
                'source_identity_json' => 'source_identity',
                default => 'final_review',
            };
            $trial[$target] = $this->decodeArray($trial[$field] ?? null);
            unset($trial[$field]);
        }
        foreach (['id', 'tenant_id', 'system_hotel_id', 'required_target_days', 'required_history_days', 'operation_intent_id', 'created_by', 'approved_by', 'reviewed_by'] as $field) {
            if (array_key_exists($field, $trial) && $trial[$field] !== null) {
                $trial[$field] = (int)$trial[$field];
            }
        }
        return $trial;
    }

    /** @param array<string, mixed> $point @return array<string, mixed> */
    private function normalizePoint(array $point): array
    {
        foreach (['id', 'trial_id', 'tenant_id', 'system_hotel_id', 'forecast_snapshot_id', 'horizon_days', 'sample_days'] as $field) {
            $point[$field] = (int)($point[$field] ?? 0);
        }
        foreach (['predicted_value', 'lower_bound', 'upper_bound', 'actual_value', 'absolute_error'] as $field) {
            $point[$field] = $this->nullableFloat($point[$field] ?? null);
        }
        $point['within_range'] = $point['within_range'] === null ? null : (int)$point['within_range'] === 1;
        $point['source_refs'] = $this->decodeArray($point['source_refs_json'] ?? null);
        $point['actual_readback'] = $this->decodeArray($point['actual_readback_json'] ?? null);
        unset($point['source_refs_json'], $point['actual_readback_json']);
        return $point;
    }

    /** @param array<string, mixed> $row */
    private function sourceVerified(array $row): bool
    {
        return $this->sourceVerifier !== null
            ? (bool)call_user_func($this->sourceVerifier, $row)
            : $this->temporalInsight->forecastSourceIdentityVerifiedForPilot($row);
    }

    /** @return array<string, mixed> */
    private function readActual(int $forecastPointId, int $hotelId, string $metricKey, string $targetDate): array
    {
        if ($this->actualReader !== null) {
            $result = call_user_func($this->actualReader, $forecastPointId, $hotelId, $metricKey, $targetDate);
            return is_array($result) ? $result : ['status' => 'unavailable', 'reason_code' => 'actual_reader_invalid'];
        }
        return $this->temporalInsight->forecastActualReadback($forecastPointId, $hotelId, $metricKey, $targetDate);
    }

    /** @param array<string, mixed> $expectedHeader @param array<int, array<string, mixed>> $expectedPoints */
    private function storedTrialMatches(array $expectedHeader, array $expectedPoints, mixed $storedHeader, array $storedPoints): bool
    {
        if (!is_array($storedHeader) || count($expectedPoints) !== count($storedPoints)) {
            return false;
        }
        foreach (['tenant_id', 'system_hotel_id', 'trial_version', 'forecast_run_id', 'policy_version', 'metric_scope', 'platform', 'start_date', 'end_date', 'required_target_days', 'required_history_days', 'immutable_digest'] as $field) {
            if ((string)($storedHeader[$field] ?? '') !== (string)($expectedHeader[$field] ?? '')) {
                return false;
            }
        }
        $expectedByDigest = [];
        foreach ($expectedPoints as $point) {
            $expectedByDigest[(string)$point['point_digest']] = $point;
        }
        foreach ($storedPoints as $stored) {
            $expected = $expectedByDigest[(string)($stored['point_digest'] ?? '')] ?? null;
            if (!is_array($expected)) {
                return false;
            }
            foreach (['tenant_id', 'system_hotel_id', 'forecast_snapshot_id', 'metric_key', 'target_date', 'horizon_days', 'sample_days', 'point_digest'] as $field) {
                if ((string)($stored[$field] ?? '') !== (string)($expected[$field] ?? '')) {
                    return false;
                }
            }
            foreach (['predicted_value', 'lower_bound', 'upper_bound'] as $field) {
                if (!$this->numericMatches($stored[$field] ?? null, $expected[$field] ?? null)) {
                    return false;
                }
            }
        }
        return true;
    }

    /** @param array<string, mixed> $trial @param array<int, array<string, mixed>> $points */
    private function storedImmutableDigestMatches(array $trial, array $points): bool
    {
        if (count($points) !== count(self::CORE_METRICS) * self::REQUIRED_TARGET_DAYS) {
            return false;
        }
        foreach ($points as $point) {
            $locked = [
                'tenant_id' => (int)($point['tenant_id'] ?? 0),
                'system_hotel_id' => (int)($point['system_hotel_id'] ?? 0),
                'forecast_snapshot_id' => (int)($point['forecast_snapshot_id'] ?? 0),
                'forecast_run_id' => (string)($trial['forecast_run_id'] ?? ''),
                'metric_scope' => (string)($trial['metric_scope'] ?? ''),
                'platform' => (string)($trial['platform'] ?? ''),
                'metric_key' => (string)($point['metric_key'] ?? ''),
                'target_date' => (string)($point['target_date'] ?? ''),
                'horizon_days' => (int)($point['horizon_days'] ?? 0),
                'predicted_value' => $this->nullableFloat($point['predicted_value'] ?? null),
                'lower_bound' => $this->nullableFloat($point['lower_bound'] ?? null),
                'upper_bound' => $this->nullableFloat($point['upper_bound'] ?? null),
                'sample_days' => (int)($point['sample_days'] ?? 0),
                'source_refs' => $this->decodeArray($point['source_refs_json'] ?? null),
            ];
            $storedPointDigest = trim((string)($point['point_digest'] ?? ''));
            if (preg_match('/^[a-f0-9]{64}$/D', $storedPointDigest) !== 1
                || !hash_equals($storedPointDigest, $this->digest($locked))
            ) {
                return false;
            }
        }
        $sourceIdentities = $this->decodeArray($trial['source_identity_json'] ?? null);
        $pointDigests = array_values(array_filter(array_map(
            static fn(array $row): string => trim((string)($row['point_digest'] ?? '')),
            $points
        )));
        $sourcePointDigests = array_values(array_filter(array_map(
            static fn(mixed $row): string => is_array($row) ? trim((string)($row['point_digest'] ?? '')) : '',
            $sourceIdentities
        )));
        sort($pointDigests, SORT_STRING);
        sort($sourcePointDigests, SORT_STRING);
        if ($pointDigests !== $sourcePointDigests) {
            return false;
        }
        $headerIdentity = [
            'tenant_id' => (int)($trial['tenant_id'] ?? 0),
            'system_hotel_id' => (int)($trial['system_hotel_id'] ?? 0),
            'trial_version' => (string)($trial['trial_version'] ?? ''),
            'forecast_run_id' => (string)($trial['forecast_run_id'] ?? ''),
            'policy_version' => (string)($trial['policy_version'] ?? ''),
            'metric_scope' => (string)($trial['metric_scope'] ?? ''),
            'platform' => (string)($trial['platform'] ?? ''),
            'start_date' => (string)($trial['start_date'] ?? ''),
            'end_date' => (string)($trial['end_date'] ?? ''),
            'required_target_days' => (int)($trial['required_target_days'] ?? 0),
            'required_history_days' => (int)($trial['required_history_days'] ?? 0),
            'core_metrics' => $this->decodeArray($trial['core_metrics_json'] ?? null),
            'pilot_policy' => $this->decodeArray($trial['pilot_policy_json'] ?? null),
            'mature_policy' => $this->decodeArray($trial['mature_policy_json'] ?? null),
            'source_identities' => $sourceIdentities,
        ];
        return hash_equals((string)($trial['immutable_digest'] ?? ''), $this->digest($headerIdentity));
    }

    /** @param array<string, mixed> $update @param array<string, mixed> $stored */
    private function actualUpdateMatches(array $update, array $stored): bool
    {
        foreach (['actual_status', 'actual_reason_code', 'actual_readback_at'] as $field) {
            if ((string)($stored[$field] ?? '') !== (string)($update[$field] ?? '')) {
                return false;
            }
        }
        foreach (['actual_value', 'absolute_error', 'within_range'] as $field) {
            if (!$this->numericMatches($stored[$field] ?? null, $update[$field] ?? null)) {
                return false;
            }
        }
        return $this->decodeArray($stored['actual_readback_json'] ?? null) == $this->decodeArray($update['actual_readback_json'] ?? null);
    }

    private function ensureTables(): void
    {
        foreach ([self::FORECAST_TABLE, self::TRIAL_TABLE, self::POINT_TABLE] as $table) {
            if (!$this->tableExists($table)) {
                throw new RuntimeException(
                    $table . ' 尚未初始化；请执行 20260803_create_temporal_forecast_trials.sql。',
                    422
                );
            }
        }
    }

    private function tenantIdForHotel(int $hotelId): int
    {
        $tenantId = (int)(Db::name('hotels')->where('id', $hotelId)->value('tenant_id') ?? 0);
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('酒店租户身份缺失，不能创建试运营。');
        }
        return $tenantId;
    }

    private function tableExists(string $table): bool
    {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $table) !== 1) {
            return false;
        }
        try {
            Db::query('SELECT 1 FROM `' . $table . '` LIMIT 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function validDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();
        return $parsed !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $parsed->format('Y-m-d') === $date;
    }

    /** @return array<string|int, mixed> */
    private function decodeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $review */
    private function finalReviewDigestMatches(array $review): bool
    {
        if ($review === []) {
            return true;
        }
        $storedDigest = trim((string)($review['review_digest'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/', $storedDigest)) {
            return false;
        }
        unset($review['review_digest']);
        return hash_equals($storedDigest, $this->digest($review));
    }

    private function digest(mixed $value): string
    {
        return hash('sha256', $this->json($this->canonicalize($value)));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float)$value : null;
    }

    private function numericMatches(mixed $stored, mixed $expected): bool
    {
        if ($expected === null) {
            return $stored === null;
        }
        return is_numeric($stored) && is_numeric($expected) && abs((float)$stored - (float)$expected) <= 0.0001;
    }
}
