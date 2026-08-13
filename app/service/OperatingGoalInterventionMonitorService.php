<?php
declare(strict_types=1);

namespace app\service;

use app\service\operation\ExecutionOutcomeService;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use think\facade\Db;

/**
 * Reads verified hotel facts, watches one active goal contract, and evaluates
 * due interventions. It only writes local monitor receipts, assessments and
 * alert-only signals; it never writes an OTA platform or creates an action.
 */
final class OperatingGoalInterventionMonitorService
{
    public const SCHEMA_VERSION = 'operating_goal_intervention_monitor.v1';
    public const ALERT_SOURCE = 'goal_intervention_monitor';

    private const RUN_TABLE = 'operating_goal_monitor_runs';
    private const ALERT_TABLE = 'operation_alerts';

    private object $goalService;
    private object $snapshotService;

    /** @var callable(int,int,int):array<string,mixed>|null */
    private $taskBundleLoader;

    /** @var callable(int,int,array<string,mixed>,string):array<string,mixed>|null */
    private $interferenceResolver;

    /** @var callable():string|null */
    private $clock;

    public function __construct(
        ?object $goalService = null,
        ?object $snapshotService = null,
        ?callable $taskBundleLoader = null,
        ?callable $interferenceResolver = null,
        ?callable $clock = null
    ) {
        $this->goalService = $goalService ?? new OperatingGoalInterventionService();
        $this->snapshotService = $snapshotService ?? new OperatingGoalMetricSnapshotService();
        if (!method_exists($this->goalService, 'overview')) {
            throw new InvalidArgumentException('goal service must provide overview()');
        }
        if (!method_exists($this->snapshotService, 'snapshot')) {
            throw new InvalidArgumentException('snapshot service must provide snapshot()');
        }
        $this->taskBundleLoader = $taskBundleLoader;
        $this->interferenceResolver = $interferenceResolver;
        $this->clock = $clock;
    }

    /** @return array<string,mixed> */
    public function monitor(
        int $tenantId,
        int $hotelId,
        string $businessDate,
        bool $persist = false
    ): array {
        if ($tenantId <= 0 || $hotelId <= 0) {
            throw new InvalidArgumentException('positive tenant_id and hotel_id are required');
        }
        $businessDate = $this->date($businessDate, 'business_date');
        $observedAt = $this->observedAt();

        $overview = $this->goalService->overview($tenantId, [$hotelId], $hotelId);
        if (!is_array($overview)) {
            throw new RuntimeException('goal overview returned an invalid result');
        }
        if (($overview['migration_required'] ?? false) === true) {
            return $this->baseResult($tenantId, $hotelId, $businessDate, $observedAt) + [
                'status' => 'migration_required',
                'monitor_state' => 'inactive',
                'migration_required' => true,
                'missing_tables' => array_values((array)($overview['missing_tables'] ?? [])),
                'signals' => [],
                'alert_candidates' => [],
            ];
        }

        $signals = [];
        $dataGaps = [];
        $primaryMetric = null;
        $guardResults = [];
        $interventionStates = [];
        $assessments = [];
        $goal = is_array($overview['current_goal_contract'] ?? null)
            ? $overview['current_goal_contract']
            : null;

        if ($goal === null) {
            $signals[] = $this->signal(
                'goal_contract_missing',
                'high',
                '未建立经营目标合同，系统无法判断应优化什么或保护什么。',
                '先建立一次目标合同；之后由系统自动监控。',
                true
            );
        } else {
            $effectiveFrom = $this->date((string)($goal['effective_from'] ?? ''), 'goal.effective_from');
            $effectiveTo = $this->date((string)($goal['effective_to'] ?? ''), 'goal.effective_to');
            if ($businessDate < $effectiveFrom) {
                $signals[] = $this->signal(
                    'goal_contract_not_yet_effective',
                    'low',
                    '目标合同尚未到生效日。',
                    '等待合同生效，或建立适用于当前经营日的新版本。',
                    false,
                    ['effective_from' => $effectiveFrom]
                );
            } elseif ($businessDate > $effectiveTo) {
                $signals[] = $this->signal(
                    'goal_contract_expired',
                    'high',
                    '目标合同已过期，当前经营动作没有有效优化边界。',
                    '建立新的目标合同版本。',
                    true,
                    ['effective_to' => $effectiveTo]
                );
            } else {
                $daysToExpiry = (int)(new DateTimeImmutable($businessDate))
                    ->diff(new DateTimeImmutable($effectiveTo))
                    ->format('%a');
                if ($daysToExpiry <= 7) {
                    $signals[] = $this->signal(
                        'goal_contract_expiring',
                        'medium',
                        '目标合同将在 ' . $daysToExpiry . ' 天内到期。',
                        '复核阶段、风险偏好和保护线后建立下一版本。',
                        true,
                        ['effective_to' => $effectiveTo, 'days_to_expiry' => $daysToExpiry]
                    );
                }

                $primaryMetricKey = strtolower(trim((string)($goal['primary_metric_key'] ?? '')));
                $primaryResult = $this->metricSnapshot(
                    $tenantId,
                    $hotelId,
                    $primaryMetricKey,
                    $businessDate,
                    $businessDate,
                    ['goal_contract' => $goal]
                );
                $primaryMetric = $primaryResult['snapshot'] ?? null;
                if (!$this->snapshotReady($primaryResult)) {
                    $gaps = $this->snapshotGapCodes($primaryResult);
                    array_push($dataGaps, ...$gaps);
                    $signals[] = $this->signal(
                        'goal_metric_unavailable',
                        'medium',
                        '首要目标指标没有取得同酒店、同经营日、同口径的可信回读。',
                        '补齐该经营日的真实来源和精确回读；不要用旧值或默认值代替。',
                        true,
                        ['metric_key' => $primaryMetricKey, 'data_gaps' => $gaps]
                    );
                }

                foreach ($this->guardDefinitions($goal['guard_metrics'] ?? []) as $guard) {
                    $metricKey = (string)$guard['metric_key'];
                    $snapshotResult = $this->metricSnapshot(
                        $tenantId,
                        $hotelId,
                        $metricKey,
                        $businessDate,
                        $businessDate,
                        ['goal_contract' => $goal, 'guard_definition' => $guard]
                    );
                    $guardResult = $this->evaluateCondition($guard, $snapshotResult);
                    $guardResults[] = $guardResult;
                    if ($guardResult['status'] === 'unavailable') {
                        array_push($dataGaps, ...$this->strings($guardResult['data_gaps'] ?? []));
                        $signals[] = $this->signal(
                            'guard_metric_unavailable',
                            'high',
                            '保护指标 ' . $metricKey . ' 没有可信回读，系统当前看不见是否越界。',
                            '先恢复该指标采集和精确回读，再继续扩大经营动作。',
                            true,
                            $guardResult
                        );
                    } elseif ($guardResult['status'] === 'breached') {
                        $signals[] = $this->signal(
                            'guard_metric_breached',
                            'high',
                            '保护指标 ' . $metricKey . ' 已越过合同边界。',
                            '停止扩大当前动作并按合同回滚方案处理。',
                            true,
                            $guardResult + [
                                'rollback_plan' => (string)($goal['rollback_plan'] ?? ''),
                            ]
                        );
                    }
                }

                foreach ($this->structuredStopConditions($goal['stop_conditions'] ?? []) as $condition) {
                    $metricKey = (string)$condition['metric_key'];
                    $snapshotResult = $this->metricSnapshot(
                        $tenantId,
                        $hotelId,
                        $metricKey,
                        $businessDate,
                        $businessDate,
                        ['goal_contract' => $goal, 'stop_condition' => $condition]
                    );
                    $stopResult = $this->evaluateCondition($condition, $snapshotResult, true);
                    if ($stopResult['status'] === 'triggered') {
                        $signals[] = $this->signal(
                            'goal_stop_condition_triggered',
                            'high',
                            '经营停止条件已触发：' . $metricKey . '。',
                            '立即停止相关干预，并按合同回滚方案处理。',
                            true,
                            $stopResult + [
                                'rollback_plan' => (string)($goal['rollback_plan'] ?? ''),
                            ]
                        );
                    } elseif ($stopResult['status'] === 'unavailable') {
                        array_push($dataGaps, ...$this->strings($stopResult['data_gaps'] ?? []));
                    }
                }
            }
        }

        $goalHistory = array_values(array_filter(
            is_array($overview['history'] ?? null) ? $overview['history'] : [],
            'is_array'
        ));
        foreach ((array)($overview['interventions'] ?? []) as $intervention) {
            if (!is_array($intervention)) {
                continue;
            }
            $frozenGoal = null;
            foreach ($goalHistory as $candidateGoal) {
                if ((int)($candidateGoal['id'] ?? 0) === (int)($intervention['goal_contract_id'] ?? 0)) {
                    $frozenGoal = $candidateGoal;
                    break;
                }
            }
            $state = $this->monitorIntervention(
                $tenantId,
                $hotelId,
                $businessDate,
                $observedAt,
                $frozenGoal,
                $intervention,
                $persist
            );
            $interventionStates[] = $state;
            array_push($signals, ...$state['signals']);
            array_push($dataGaps, ...$state['data_gaps']);
            if (is_array($state['assessment'] ?? null)) {
                $assessments[] = $state['assessment'];
            }
        }

        $signals = $this->deduplicateSignals($signals);
        $dataGaps = $this->strings($dataGaps);
        $alertCandidates = array_values(array_filter(
            $signals,
            static fn(array $signal): bool => ($signal['alert'] ?? false) === true
        ));
        $signalCodes = array_values(array_column($signals, 'code'));
        $monitorState = $goal === null || in_array('goal_contract_not_yet_effective', $signalCodes, true)
            ? 'inactive'
            : ($alertCandidates === [] ? 'monitoring' : 'attention');

        $persistedAlertIds = [];
        $runReceipt = null;
        $persistenceErrors = [];
        $missingPersistenceTables = [];
        if ($persist) {
            foreach ([self::RUN_TABLE, self::ALERT_TABLE] as $table) {
                if (!$this->tableExists($table)) {
                    $missingPersistenceTables[] = $table;
                }
            }
            if ($missingPersistenceTables === []) {
                try {
                    $persistedAlertIds = $this->persistAlerts(
                        $tenantId,
                        $hotelId,
                        $businessDate,
                        $alertCandidates,
                        $goal
                    );
                } catch (Throwable $exception) {
                    $persistenceErrors[] = 'alert_persistence_failed';
                }
                try {
                    $runReceipt = $this->persistRun([
                        'tenant_id' => $tenantId,
                        'hotel_id' => $hotelId,
                        'business_date' => $businessDate,
                        'goal_contract_id' => (int)($goal['id'] ?? 0),
                        'goal_contract_version_no' => (int)($goal['version_no'] ?? 0),
                        'monitor_state' => $monitorState,
                        'primary_snapshot' => is_array($primaryMetric) ? $primaryMetric : [],
                        'guard_results' => $guardResults,
                        'intervention_states' => $interventionStates,
                        'signal_codes' => array_values(array_column($signals, 'code')),
                        'data_gaps' => $dataGaps,
                        'observed_at' => $observedAt,
                        'alert_count' => count($persistedAlertIds),
                        'assessment_count' => count($assessments),
                    ]);
                } catch (Throwable $exception) {
                    $persistenceErrors[] = 'monitor_run_persistence_failed';
                }
            }
        }

        $status = !$persist
            ? 'preview'
            : ($missingPersistenceTables !== []
                ? 'migration_required'
                : ($persistenceErrors === [] ? 'completed' : 'partial'));

        return $this->baseResult($tenantId, $hotelId, $businessDate, $observedAt) + [
            'status' => $status,
            'monitor_state' => $monitorState,
            'migration_required' => $missingPersistenceTables !== [],
            'missing_tables' => $missingPersistenceTables,
            'goal_contract' => $goal,
            'primary_metric_snapshot' => $primaryMetric,
            'guard_results' => $guardResults,
            'intervention_states' => $interventionStates,
            'assessments' => $assessments,
            'signals' => $signals,
            'alert_candidates' => $alertCandidates,
            'persisted_alert_ids' => $persistedAlertIds,
            'monitor_run_receipt' => $runReceipt,
            'data_gaps' => $dataGaps,
            'persistence_errors' => $persistenceErrors,
        ];
    }

    /** @return array<string,mixed> */
    private function monitorIntervention(
        int $tenantId,
        int $hotelId,
        string $businessDate,
        string $observedAt,
        ?array $currentGoal,
        array $intervention,
        bool $persist
    ): array {
        $intentId = (int)($intervention['intent_id'] ?? 0);
        $windowStart = $this->date(
            (string)($intervention['observation_window_start'] ?? ''),
            'intervention.observation_window_start'
        );
        $windowEnd = $this->date(
            (string)($intervention['observation_window_end'] ?? ''),
            'intervention.observation_window_end'
        );
        $result = [
            'intent_id' => $intentId,
            'intervention_contract_id' => (int)($intervention['id'] ?? 0),
            'observation_window_start' => $windowStart,
            'observation_window_end' => $windowEnd,
            'status' => 'observing',
            'signals' => [],
            'data_gaps' => [],
            'assessment' => null,
        ];

        $existingAssessment = is_array($intervention['latest_assessment'] ?? null)
            ? $intervention['latest_assessment']
            : null;
        $existingVerdict = strtolower(trim((string)($existingAssessment['verdict'] ?? '')));
        if ($existingAssessment !== null
            && in_array($existingVerdict, ['supported', 'contradicted'], true)
        ) {
            $result['status'] = 'assessed';
            $result['assessment'] = $existingAssessment;
            $result['signals'] = $this->assessmentSignals($existingAssessment, $intervention);
            return $result;
        }

        // Indeterminate is deliberately non-final. A later source readback,
        // execution receipt or interference record may qualify the same frozen
        // intervention for a stronger three-state judgment. Content digests keep
        // unchanged rechecks idempotent.
        if ($existingAssessment !== null) {
            $result['previous_assessment'] = $existingAssessment;
        }

        // A business date is only complete after it has ended. Assessment starts
        // on the next business date, never while the last observation day is open.
        if ($businessDate <= $windowEnd) {
            $result['status'] = 'observing';
            return $result;
        }

        $bundle = $this->loadTaskBundle($tenantId, $hotelId, $intentId);
        if ($bundle === []) {
            $result['status'] = 'assessment_due_without_task';
            $result['signals'][] = $this->signal(
                'intervention_assessment_due',
                'medium',
                '干预观察窗已结束，但没有可绑定的执行任务。',
                '核对执行任务与干预合同的 intent_id 绑定。',
                true,
                ['intent_id' => $intentId, 'observation_window_end' => $windowEnd]
            );
            return $result;
        }

        $task = is_array($bundle['task'] ?? null) ? $bundle['task'] : [];
        $intent = is_array($bundle['intent'] ?? null) ? $bundle['intent'] : [];
        $evidenceRows = array_values(array_filter(
            is_array($bundle['evidence_rows'] ?? null) ? $bundle['evidence_rows'] : [],
            'is_array'
        ));
        $evidenceTruth = is_array($bundle['evidence_truth'] ?? null)
            ? $bundle['evidence_truth']
            : [];
        $targetMetricKey = strtolower(trim((string)($intervention['target_metric_key'] ?? '')));
        $baseline = is_array($intervention['baseline_snapshot'] ?? null)
            ? $intervention['baseline_snapshot']
            : [];
        $followupResult = $this->metricSnapshot(
            $tenantId,
            $hotelId,
            $targetMetricKey,
            $windowStart,
            $windowEnd,
            $baseline + ['intervention_contract' => $intervention]
        );
        $followup = $this->snapshotReady($followupResult)
            ? (array)$followupResult['snapshot']
            : [];
        if ($followup === []) {
            array_push($result['data_gaps'], ...$this->snapshotGapCodes($followupResult));
        }

        $goal = $currentGoal;
        $guardDefinitions = $this->guardDefinitions($goal['guard_metrics'] ?? []);
        $riskKeys = $this->strings($intervention['risk_metric_keys'] ?? []);
        $guardObservations = [];
        $stopTriggered = false;
        $stopEvidenceRefs = [];
        foreach ($riskKeys as $riskKey) {
            $definition = $guardDefinitions[$riskKey] ?? ['metric_key' => $riskKey];
            $snapshotResult = $this->metricSnapshot(
                $tenantId,
                $hotelId,
                $riskKey,
                $windowStart,
                $windowEnd,
                ['goal_contract' => $goal, 'guard_definition' => $definition]
            );
            if (!$this->snapshotReady($snapshotResult)) {
                array_push($result['data_gaps'], ...$this->snapshotGapCodes($snapshotResult));
                continue;
            }
            $snapshot = (array)$snapshotResult['snapshot'];
            $guardObservations[] = $snapshot;
            $guardResult = $this->evaluateCondition($definition, $snapshotResult);
            if ($guardResult['status'] === 'breached') {
                $stopTriggered = true;
                array_push($stopEvidenceRefs, ...$this->strings($snapshot['evidence_refs'] ?? []));
            }
        }

        $preflightReasons = [];
        if (!is_array($goal) || $goal === []) {
            $preflightReasons[] = 'frozen_goal_contract_unavailable';
        }
        if (($bundle['task_ambiguous'] ?? false) === true) {
            $preflightReasons[] = 'execution_task_ambiguous';
        }
        if (($evidenceTruth['source_verified'] ?? false) !== true) {
            $preflightReasons[] = 'execution_evidence_source_unverified';
        }
        if ($followup === []) {
            $preflightReasons[] = 'followup_metric_readback_unavailable';
        }
        $executedAt = trim((string)($task['executed_at'] ?? ''));
        $baselineCapturedAt = trim((string)($baseline['captured_at'] ?? ''));
        $followupCapturedAt = trim((string)($followup['captured_at'] ?? ''));
        if ($executedAt !== ''
            && (($baselineCapturedAt !== '' && strtotime($executedAt) <= strtotime($baselineCapturedAt))
                || ($followupCapturedAt !== '' && strtotime($executedAt) > strtotime($followupCapturedAt)))
        ) {
            $preflightReasons[] = 'execution_timing_outside_observation_evidence';
        }

        $assessmentInput = [
            'hotel_id' => $hotelId,
            'assessment_origin' => 'system_monitor',
            'assessed_at' => $observedAt,
            'followup_snapshot' => $followup,
            'guard_observations' => $guardObservations,
            'monitor_preflight_reason_codes' => $this->strings($preflightReasons),
            'notes' => '由经营目标智能监控器在观察窗结束后自动生成；未执行任何 OTA 写入。',
        ];
        if ($stopTriggered) {
            $assessmentInput['stop_triggered'] = true;
            $assessmentInput['stop_evidence_refs'] = $this->strings($stopEvidenceRefs);
        }
        $interference = $this->resolveInterference($tenantId, $hotelId, $intervention, $businessDate);
        if (($interference['status'] ?? '') === 'verified_absent') {
            $assessmentInput['external_interferences'] = [];
        } elseif (($interference['status'] ?? '') === 'present') {
            $assessmentInput['external_interferences'] = array_values((array)($interference['items'] ?? []));
        }

        $judgment = (new OperationInterventionJudgmentService())->judge(
            is_array($goal) ? $goal : [],
            $intervention,
            $task,
            $evidenceRows,
            $assessmentInput
        );
        $assessment = $judgment;
        if ($persist) {
            if (!method_exists($this->goalService, 'createAutomatedAssessmentForTask')) {
                throw new RuntimeException('goal service does not support automated assessment persistence');
            }
            $assessment = $this->goalService->createAutomatedAssessmentForTask(
                $tenantId,
                [$hotelId],
                $hotelId,
                (int)($task['id'] ?? 0),
                $assessmentInput
            );
        }

        $result['status'] = $persist ? 'assessed' : 'assessment_preview';
        $result['assessment'] = $assessment;
        $result['execution_evidence_truth'] = $evidenceTruth;
        $result['interference_status'] = (string)($interference['status'] ?? 'unknown');
        $result['signals'] = $this->assessmentSignals($assessment, $intervention);
        return $result;
    }

    /** @return array<string,mixed> */
    private function loadTaskBundle(int $tenantId, int $hotelId, int $intentId): array
    {
        if ($this->taskBundleLoader !== null) {
            $loaded = call_user_func($this->taskBundleLoader, $tenantId, $hotelId, $intentId);
            return is_array($loaded) ? $loaded : [];
        }
        foreach (['operation_execution_intents', 'operation_execution_tasks', 'operation_execution_evidence'] as $table) {
            if (!$this->tableExists($table)) {
                return [];
            }
        }

        $intent = Db::name('operation_execution_intents')
            ->where('id', $intentId)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->whereNull('deleted_at')
            ->find();
        if (!is_array($intent)) {
            return [];
        }
        $taskRows = Db::name('operation_execution_tasks')
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('intent_id', $intentId)
            ->whereNull('deleted_at')
            ->order('executed_at', 'desc')
            ->order('id', 'desc')
            ->select()
            ->toArray();
        $taskRows = array_values(array_filter($taskRows, 'is_array'));
        if ($taskRows === []) {
            return [];
        }
        $executedTaskRows = array_values(array_filter(
            $taskRows,
            static fn(array $row): bool => (string)($row['status'] ?? '') === 'executed'
        ));
        // A newer pending retry must not hide the one executed task that the
        // frozen intervention actually observed. Multiple executed attempts
        // stay ambiguous and are never silently collapsed into one action.
        $task = count($executedTaskRows) === 1
            ? $executedTaskRows[0]
            : ($executedTaskRows[0] ?? $taskRows[0]);
        $evidenceRows = Db::name('operation_execution_evidence')
            ->where('tenant_id', $tenantId)
            ->where('task_id', (int)$task['id'])
            ->whereNull('deleted_at')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        $intent = $this->decodeRowJson($intent, [
            'current_value_json' => 'current_value',
            'target_value_json' => 'target_value',
            'evidence_json' => 'evidence',
        ]);
        $task = $this->decodeRowJson($task, [
            'current_value_json' => 'current_value',
            'target_value_json' => 'target_value',
        ]);
        $normalizedEvidence = [];
        foreach ($evidenceRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $normalizedEvidence[] = $this->decodeRowJson($row, [
                'before_json' => 'before',
                'after_json' => 'after',
                'platform_response_json' => 'platform_response',
            ]);
        }
        $truth = (new ExecutionOutcomeService())->buildExecutionEvidenceTruth(
            $intent,
            $task,
            $normalizedEvidence
        );

        return [
            'intent' => $intent,
            'task' => $task,
            'evidence_rows' => $normalizedEvidence,
            'evidence_truth' => $truth,
            'task_ambiguous' => count($executedTaskRows) > 1,
        ];
    }

    /** @return array<string,mixed> */
    private function resolveInterference(
        int $tenantId,
        int $hotelId,
        array $intervention,
        string $businessDate
    ): array {
        if ($this->interferenceResolver === null) {
            return [
                'status' => 'unknown',
                'items' => [],
                'reason_code' => 'external_interference_coverage_unavailable',
            ];
        }
        $resolved = call_user_func(
            $this->interferenceResolver,
            $tenantId,
            $hotelId,
            $intervention,
            $businessDate
        );
        if (!is_array($resolved)
            || !in_array((string)($resolved['status'] ?? ''), ['verified_absent', 'present', 'unknown'], true)
        ) {
            return ['status' => 'unknown', 'items' => [], 'reason_code' => 'external_interference_resolver_invalid'];
        }
        return $resolved;
    }

    /** @return array<string,mixed> */
    private function metricSnapshot(
        int $tenantId,
        int $hotelId,
        string $metricKey,
        string $periodStart,
        string $periodEnd,
        array $context = []
    ): array {
        try {
            $result = $this->snapshotService->snapshot(
                $tenantId,
                $hotelId,
                $metricKey,
                $periodStart,
                $periodEnd,
                $context
            );
            return is_array($result) ? $result : [
                'status' => 'unavailable',
                'snapshot' => null,
                'data_gaps' => ['metric_snapshot_result_invalid:' . $metricKey],
            ];
        } catch (Throwable) {
            return [
                'status' => 'unavailable',
                'snapshot' => null,
                'data_gaps' => ['metric_snapshot_failed:' . $metricKey],
            ];
        }
    }

    /** @param array<string,mixed> $result */
    private function snapshotReady(array $result): bool
    {
        $snapshot = is_array($result['snapshot'] ?? null) ? $result['snapshot'] : [];
        return (string)($result['status'] ?? '') === 'ready'
            && is_numeric($snapshot['value'] ?? null)
            && strtolower(trim((string)($snapshot['quality_status'] ?? ''))) === 'verified'
            && strtolower(trim((string)($snapshot['readback_status'] ?? ''))) === 'readback_verified'
            && $this->strings($snapshot['evidence_refs'] ?? []) !== [];
    }

    /**
     * @param array<string,mixed> $condition
     * @param array<string,mixed> $snapshotResult
     * @return array<string,mixed>
     */
    private function evaluateCondition(array $condition, array $snapshotResult, bool $stopCondition = false): array
    {
        $metricKey = strtolower(trim((string)($condition['metric_key'] ?? '')));
        $snapshot = is_array($snapshotResult['snapshot'] ?? null) ? $snapshotResult['snapshot'] : [];
        if (!$this->snapshotReady($snapshotResult)) {
            return [
                'metric_key' => $metricKey,
                'status' => 'unavailable',
                'value' => null,
                'operator' => (string)($condition['operator'] ?? ''),
                'threshold' => $condition['threshold'] ?? null,
                'evidence_refs' => [],
                'data_gaps' => $this->snapshotGapCodes($snapshotResult),
            ];
        }

        $value = (float)$snapshot['value'];
        [$operator, $threshold] = $this->conditionOperatorAndThreshold($condition);
        if ($operator === '' || $threshold === null) {
            return [
                'metric_key' => $metricKey,
                'status' => 'unavailable',
                'value' => $value,
                'operator' => $operator,
                'threshold' => $threshold,
                'evidence_refs' => $this->strings($snapshot['evidence_refs'] ?? []),
                'data_gaps' => ['condition_not_machine_evaluable:' . $metricKey],
            ];
        }
        $matched = match ($operator) {
            '>' => $value > $threshold,
            '>=' => $value >= $threshold,
            '<' => $value < $threshold,
            '<=' => $value <= $threshold,
            '=' => abs($value - $threshold) <= 0.000001,
            default => false,
        };

        // A guard's comparison describes the safe state; a stop condition's
        // comparison describes the trigger state.
        $status = $stopCondition
            ? ($matched ? 'triggered' : 'clear')
            : ($matched ? 'within_bounds' : 'breached');
        return [
            'metric_key' => $metricKey,
            'status' => $status,
            'value' => $value,
            'unit' => (string)($snapshot['unit'] ?? $condition['unit'] ?? ''),
            'operator' => $operator,
            'threshold' => $threshold,
            'period_start' => (string)($snapshot['period_start'] ?? ''),
            'period_end' => (string)($snapshot['period_end'] ?? ''),
            'fact_scope' => (string)($snapshot['fact_scope'] ?? ''),
            'evidence_refs' => $this->strings($snapshot['evidence_refs'] ?? []),
            'data_gaps' => [],
        ];
    }

    /** @return array{0:string,1:?float} */
    private function conditionOperatorAndThreshold(array $condition): array
    {
        $operator = strtolower(trim((string)($condition['operator'] ?? $condition['comparison'] ?? '')));
        $operator = match ($operator) {
            'gte', 'minimum', 'not_below' => '>=',
            'lte', 'maximum', 'not_above' => '<=',
            'gt', 'above' => '>',
            'lt', 'below' => '<',
            'eq', 'equals' => '=',
            default => $operator,
        };
        $threshold = null;
        foreach (['threshold', 'lower_bound', 'upper_bound', 'minimum', 'maximum', 'min', 'max'] as $key) {
            if (array_key_exists($key, $condition) && is_numeric($condition[$key])) {
                $threshold = (float)$condition[$key];
                if ($operator === '') {
                    $operator = in_array($key, ['lower_bound', 'minimum', 'min'], true) ? '>=' : '<=';
                }
                break;
            }
        }
        return [in_array($operator, ['>', '>=', '<', '<=', '='], true) ? $operator : '', $threshold];
    }

    /** @return array<string,array<string,mixed>> */
    private function guardDefinitions(mixed $raw): array
    {
        $items = is_array($raw) ? $raw : [];
        if (!array_is_list($items)) {
            $mapped = [];
            foreach ($items as $metricKey => $value) {
                $item = is_array($value) ? $value : ['threshold' => $value];
                $item['metric_key'] ??= (string)$metricKey;
                $mapped[] = $item;
            }
            $items = $mapped;
        }
        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $metricKey = strtolower(trim((string)($item['metric_key'] ?? '')));
            if ($metricKey === '') {
                continue;
            }
            $item['metric_key'] = $metricKey;
            $result[$metricKey] = $item;
        }
        return $result;
    }

    /** @return array<int,array<string,mixed>> */
    private function structuredStopConditions(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        return array_values(array_filter($raw, static fn(mixed $item): bool =>
            is_array($item)
            && trim((string)($item['metric_key'] ?? '')) !== ''
            && trim((string)($item['operator'] ?? $item['comparison'] ?? '')) !== ''
            && is_numeric($item['threshold'] ?? null)
        ));
    }

    /** @return array<int,array<string,mixed>> */
    private function assessmentSignals(array $assessment, array $intervention): array
    {
        $verdict = strtolower(trim((string)($assessment['verdict'] ?? 'indeterminate')));
        $context = [
            'intent_id' => (int)($intervention['intent_id'] ?? 0),
            'intervention_contract_id' => (int)($intervention['id'] ?? 0),
            'verdict' => $verdict,
            'reason_codes' => $this->strings($assessment['reason_codes'] ?? []),
        ];
        if (($assessment['stop_triggered'] ?? false) === true) {
            return [$this->signal(
                'intervention_stop_triggered',
                'high',
                '干预已触发停止条件。',
                '停止相关动作并执行合同中的回滚方案。',
                true,
                $context
            )];
        }
        if ($verdict === 'contradicted') {
            return [$this->signal(
                'intervention_contradicted',
                'high',
                '观察结果与干预预期相反。',
                '停止扩大动作，复核并决定是否回滚。',
                true,
                $context
            )];
        }
        if ($verdict === 'indeterminate') {
            return [$this->signal(
                'intervention_indeterminate',
                'medium',
                '当前证据不足，系统没有资格判断干预是否有效。',
                '补齐执行回执、观察窗事实或外部干扰说明后再判定。',
                true,
                $context
            )];
        }
        return [$this->signal(
            'intervention_supported',
            'low',
            '证据支持本次干预达到预先声明的目标阈值。',
            '保留当前动作与证据，继续观察保护指标。',
            false,
            $context
        )];
    }

    /** @return array<string,mixed> */
    private function signal(
        string $code,
        string $level,
        string $message,
        string $suggestion,
        bool $alert,
        array $context = []
    ): array {
        return [
            'code' => $code,
            'level' => in_array($level, ['high', 'medium', 'low'], true) ? $level : 'medium',
            'message' => $message,
            'action_suggestion' => $suggestion,
            'alert' => $alert,
            'context' => $context,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function deduplicateSignals(array $signals): array
    {
        $result = [];
        $seen = [];
        foreach ($signals as $signal) {
            if (!is_array($signal)) {
                continue;
            }
            $identity = hash('sha256', $this->json($signal));
            if (!isset($seen[$identity])) {
                $seen[$identity] = true;
                $result[] = $signal;
            }
        }
        return $result;
    }

    /** @return array<int,int> */
    private function persistAlerts(
        int $tenantId,
        int $hotelId,
        string $businessDate,
        array $signals,
        ?array $goal
    ): array {
        $groups = [];
        foreach ($signals as $signal) {
            $code = strtolower(trim((string)($signal['code'] ?? '')));
            if ($code !== '') {
                $groups[$code][] = $signal;
            }
        }
        $ids = [];
        $now = $this->observedAt();
        foreach ($groups as $code => $items) {
            $highest = $this->highestLevel(array_column($items, 'level'));
            $title = $this->alertTitle($code);
            $message = count($items) === 1
                ? (string)($items[0]['message'] ?? $title)
                : count($items) . ' 个经营监控信号需要处理。';
            $rawData = [
                'schema_version' => self::SCHEMA_VERSION,
                'signal_code' => $code,
                'business_date' => $businessDate,
                'goal_contract_id' => (int)($goal['id'] ?? 0),
                'goal_contract_version_no' => (int)($goal['version_no'] ?? 0),
                'execution_bridge_policy' => 'alert_only',
                'auto_write_ota' => false,
                'items' => $items,
                'rollback_plan' => (string)($goal['rollback_plan'] ?? ''),
                'action_suggestion' => (string)($items[0]['action_suggestion'] ?? ''),
            ];
            $payload = [
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'alert_type' => substr($code, 0, 50),
                'monitor_dedupe_key' => hash('sha256', implode('|', [
                    $tenantId,
                    $hotelId,
                    substr($code, 0, 50),
                    self::ALERT_SOURCE,
                    $businessDate,
                ])),
                'level' => $highest,
                'title' => mb_substr($title, 0, 120),
                'message' => mb_substr($message, 0, 500),
                'source' => self::ALERT_SOURCE,
                'related_date' => $businessDate,
                'raw_data' => $this->json($rawData),
                'updated_at' => $now,
            ];
            $existing = Db::name(self::ALERT_TABLE)
                ->where('monitor_dedupe_key', $payload['monitor_dedupe_key'])
                ->find();
            if (is_array($existing)) {
                Db::name(self::ALERT_TABLE)->where('id', (int)$existing['id'])->update($payload);
                $ids[] = (int)$existing['id'];
                continue;
            }
            $payload['status'] = 'unread';
            $payload['created_at'] = $now;
            try {
                $ids[] = (int)Db::name(self::ALERT_TABLE)->insertGetId($payload);
            } catch (Throwable $error) {
                $raced = Db::name(self::ALERT_TABLE)
                    ->where('monitor_dedupe_key', $payload['monitor_dedupe_key'])
                    ->find();
                if (!is_array($raced)) {
                    throw $error;
                }
                unset($payload['status'], $payload['created_at']);
                Db::name(self::ALERT_TABLE)->where('id', (int)$raced['id'])->update($payload);
                $ids[] = (int)$raced['id'];
            }
        }
        return array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
    }

    /** @return array<string,mixed> */
    private function persistRun(array $input): array
    {
        $content = [
            'schema_version' => self::SCHEMA_VERSION,
            'tenant_id' => (int)$input['tenant_id'],
            'hotel_id' => (int)$input['hotel_id'],
            'business_date' => (string)$input['business_date'],
            'goal_contract_id' => (int)$input['goal_contract_id'],
            'goal_contract_version_no' => (int)$input['goal_contract_version_no'],
            'monitor_state' => (string)$input['monitor_state'],
            'primary_snapshot' => (array)$input['primary_snapshot'],
            'guard_results' => (array)$input['guard_results'],
            'intervention_states' => $this->stableInterventionStates((array)$input['intervention_states']),
            'signal_codes' => $this->strings($input['signal_codes'] ?? []),
            'data_gaps' => $this->strings($input['data_gaps'] ?? []),
        ];
        $digest = hash('sha256', $this->json($content));
        $existing = Db::name(self::RUN_TABLE)
            ->where('tenant_id', $content['tenant_id'])
            ->where('hotel_id', $content['hotel_id'])
            ->where('business_date', $content['business_date'])
            ->where('content_digest', $digest)
            ->find();
        if (is_array($existing)) {
            Db::name(self::RUN_TABLE)
                ->where('id', (int)$existing['id'])
                ->inc('run_count')
                ->update([
                'last_observed_at' => (string)$input['observed_at'],
                'alert_count' => (int)$input['alert_count'],
                'assessment_count' => (int)$input['assessment_count'],
            ]);
            $row = Db::name(self::RUN_TABLE)->where('id', (int)$existing['id'])->find();
            return $this->monitorRunFromRow(is_array($row) ? $row : $existing, true);
        }

        try {
            $id = (int)Db::name(self::RUN_TABLE)->insertGetId([
                'tenant_id' => $content['tenant_id'],
                'hotel_id' => $content['hotel_id'],
                'business_date' => $content['business_date'],
                'goal_contract_id' => $content['goal_contract_id'],
                'goal_contract_version_no' => $content['goal_contract_version_no'],
                'monitor_state' => $content['monitor_state'],
                'primary_snapshot_json' => $this->json($content['primary_snapshot']),
                'guard_results_json' => $this->json($content['guard_results']),
                'intervention_states_json' => $this->json($content['intervention_states']),
                'signal_codes_json' => $this->json($content['signal_codes']),
                'data_gaps_json' => $this->json($content['data_gaps']),
                'content_digest' => $digest,
                'first_observed_at' => (string)$input['observed_at'],
                'last_observed_at' => (string)$input['observed_at'],
                'run_count' => 1,
                'alert_count' => (int)$input['alert_count'],
                'assessment_count' => (int)$input['assessment_count'],
            ]);
        } catch (Throwable $error) {
            $raced = Db::name(self::RUN_TABLE)
                ->where('tenant_id', $content['tenant_id'])
                ->where('hotel_id', $content['hotel_id'])
                ->where('business_date', $content['business_date'])
                ->where('content_digest', $digest)
                ->find();
            if (!is_array($raced)) {
                throw $error;
            }
            Db::name(self::RUN_TABLE)
                ->where('id', (int)$raced['id'])
                ->inc('run_count')
                ->update([
                    'last_observed_at' => (string)$input['observed_at'],
                    'alert_count' => (int)$input['alert_count'],
                    'assessment_count' => (int)$input['assessment_count'],
                ]);
            $row = Db::name(self::RUN_TABLE)->where('id', (int)$raced['id'])->find();
            return $this->monitorRunFromRow(is_array($row) ? $row : $raced, true);
        }
        $row = Db::name(self::RUN_TABLE)->where('id', $id)->find();
        if (!is_array($row) || !hash_equals($digest, (string)($row['content_digest'] ?? ''))) {
            throw new RuntimeException('monitor run exact readback failed');
        }
        return $this->monitorRunFromRow($row, false);
    }

    /** @return array<int,array<string,mixed>> */
    private function stableInterventionStates(array $states): array
    {
        foreach ($states as &$state) {
            if (!is_array($state)) {
                continue;
            }
            if (is_array($state['assessment'] ?? null)) {
                unset(
                    $state['assessment']['created_at'],
                    $state['assessment']['assessed_at'],
                    $state['assessment']['idempotent']
                );
            }
        }
        unset($state);
        return array_values(array_filter($states, 'is_array'));
    }

    /** @return array<string,mixed> */
    private function monitorRunFromRow(array $row, bool $idempotent): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'tenant_id' => (int)($row['tenant_id'] ?? 0),
            'hotel_id' => (int)($row['hotel_id'] ?? 0),
            'business_date' => (string)($row['business_date'] ?? ''),
            'monitor_state' => (string)($row['monitor_state'] ?? ''),
            'last_observed_at' => (string)($row['last_observed_at'] ?? ''),
            'run_count' => (int)($row['run_count'] ?? 0),
            'alert_count' => (int)($row['alert_count'] ?? 0),
            'assessment_count' => (int)($row['assessment_count'] ?? 0),
            'content_digest' => (string)($row['content_digest'] ?? ''),
            'idempotent' => $idempotent,
            'db_readback_verified' => true,
        ];
    }

    /** @return array<string,mixed> */
    private function decodeRowJson(array $row, array $mapping): array
    {
        foreach ($mapping as $column => $target) {
            $raw = $row[$column] ?? null;
            if (is_array($raw)) {
                $row[$target] = $raw;
                continue;
            }
            try {
                $decoded = $raw === null || $raw === ''
                    ? []
                    : json_decode((string)$raw, true, 512, JSON_THROW_ON_ERROR);
                $row[$target] = is_array($decoded) ? $decoded : [];
            } catch (Throwable) {
                $row[$target] = [];
            }
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function baseResult(int $tenantId, int $hotelId, string $businessDate, string $observedAt): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'observed_at' => $observedAt,
            'external_action_triggered' => false,
            'auto_write_ota' => false,
            'causality_claimed' => false,
        ];
    }

    private function alertTitle(string $code): string
    {
        return match ($code) {
            'goal_contract_missing' => '经营目标合同缺失',
            'goal_contract_expired' => '经营目标合同已过期',
            'goal_contract_expiring' => '经营目标合同即将到期',
            'goal_metric_unavailable' => '首要目标指标未回读',
            'guard_metric_unavailable' => '保护指标监控失明',
            'guard_metric_breached' => '保护指标已越界',
            'goal_stop_condition_triggered' => '经营停止条件已触发',
            'intervention_assessment_due' => '经营干预已到复盘时间',
            'intervention_stop_triggered' => '经营干预已触发停止条件',
            'intervention_contradicted' => '经营干预结果与预期相反',
            'intervention_indeterminate' => '经营干预暂无法判断',
            default => '经营目标智能监控提醒',
        };
    }

    /** @param array<int,mixed> $levels */
    private function highestLevel(array $levels): string
    {
        foreach (['high', 'medium', 'low'] as $candidate) {
            if (in_array($candidate, $levels, true)) {
                return $candidate;
            }
        }
        return 'medium';
    }

    /** @return array<int,string> */
    private function strings(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $result = [];
        foreach ($raw as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $value = trim((string)$value);
            if ($value !== '') {
                $result[] = $value;
            }
        }
        $result = array_values(array_unique($result));
        sort($result, SORT_STRING);
        return $result;
    }

    /**
     * Snapshot services return per-day structured gaps. Flatten only their
     * declared reason codes; never stringify an object or silently drop it.
     *
     * @param array<string,mixed> $snapshotResult
     * @return array<int,string>
     */
    private function snapshotGapCodes(array $snapshotResult): array
    {
        $codes = $this->strings($snapshotResult['reason_codes'] ?? []);
        foreach ((array)($snapshotResult['data_gaps'] ?? []) as $gap) {
            if (is_array($gap)) {
                array_push($codes, ...$this->strings($gap['reason_codes'] ?? []));
                continue;
            }
            if (is_scalar($gap)) {
                $value = trim((string)$gap);
                if ($value !== '') {
                    $codes[] = $value;
                }
            }
        }
        return $this->strings($codes);
    }

    private function date(string $value, string $field): string
    {
        $value = trim($value);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false
            || ($errors !== false && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0))
            || $parsed->format('Y-m-d') !== $value
        ) {
            throw new InvalidArgumentException($field . ' must be YYYY-MM-DD');
        }
        return $value;
    }

    private function observedAt(): string
    {
        $value = $this->clock === null
            ? (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d H:i:s')
            : trim((string)call_user_func($this->clock));
        if ($value === '' || strtotime($value) === false) {
            throw new RuntimeException('monitor clock returned an invalid datetime');
        }
        return date('Y-m-d H:i:s', (int)strtotime($value));
    }

    private function tableExists(string $table): bool
    {
        try {
            Db::query('SELECT 1 FROM `' . str_replace('`', '', $table) . '` LIMIT 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function json(mixed $value): string
    {
        return (string)json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return is_string($value) ? trim($value) : $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }
}
