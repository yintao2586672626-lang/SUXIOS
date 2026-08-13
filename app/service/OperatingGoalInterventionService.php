<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use think\facade\Db;

/**
 * Append-only operating-goal, intervention and three-state learning ledger.
 *
 * This service deliberately records plans and evidence reviews only. It never
 * writes an OTA platform and never upgrades an assessment into a causal claim.
 */
final class OperatingGoalInterventionService
{
    private const GOAL_TABLE = 'hotel_operating_goal_contracts';
    private const INTERVENTION_TABLE = 'operation_intervention_contracts';
    private const ASSESSMENT_TABLE = 'operation_intervention_assessments';
    private const MONITOR_RUN_TABLE = 'operating_goal_monitor_runs';

    private ?OperationManagementService $operationManagementService;
    private ?object $judgmentService;
    private ?object $snapshotService;

    public function __construct(
        ?OperationManagementService $operationManagementService = null,
        ?object $judgmentService = null,
        ?object $snapshotService = null
    ) {
        if ($judgmentService !== null && !method_exists($judgmentService, 'judge')) {
            throw new InvalidArgumentException('judgment service must provide judge()');
        }
        if ($snapshotService !== null && !method_exists($snapshotService, 'snapshot')) {
            throw new InvalidArgumentException('snapshot service must provide snapshot()');
        }
        $this->operationManagementService = $operationManagementService;
        $this->judgmentService = $judgmentService;
        $this->snapshotService = $snapshotService;
    }

    /**
     * @param array<int,int|string> $hotelIds
     * @return array<string,mixed>
     */
    public function overview(int $tenantId, array $hotelIds, int $hotelId): array
    {
        $resolvedTenantId = $this->resolveScope($tenantId, $hotelIds, $hotelId);
        $missingTables = $this->missingTables([
            self::GOAL_TABLE,
            self::INTERVENTION_TABLE,
            self::ASSESSMENT_TABLE,
        ]);
        $emptySummary = [
            'supported' => 0,
            'contradicted' => 0,
            'indeterminate' => 0,
            'unassessed' => 0,
        ];
        if ($missingTables !== []) {
            return [
                'status' => 'migration_required',
                'migration_required' => true,
                'missing_tables' => $missingTables,
                'tenant_id' => $resolvedTenantId,
                'hotel_id' => $hotelId,
                'current_goal_contract' => null,
                'history' => [],
                'interventions' => [],
                'summary' => $emptySummary,
                'monitor' => [
                    'status' => 'unavailable',
                    'monitor_state' => 'inactive',
                    'reason_code' => 'goal_learning_migration_required',
                    'last_observed_at' => null,
                ],
            ];
        }

        $goalRows = Db::name(self::GOAL_TABLE)
            ->where('tenant_id', $resolvedTenantId)
            ->where('hotel_id', $hotelId)
            ->order('version_no', 'desc')
            ->order('id', 'desc')
            ->select()
            ->toArray();
        $history = array_map(fn(array $row): array => $this->goalFromRow($row), $goalRows);

        $interventionRows = Db::name(self::INTERVENTION_TABLE)
            ->where('tenant_id', $resolvedTenantId)
            ->where('hotel_id', $hotelId)
            ->order('intent_id', 'asc')
            ->order('version_no', 'desc')
            ->order('id', 'desc')
            ->select()
            ->toArray();

        $latestByIntent = [];
        foreach ($interventionRows as $row) {
            $intentId = (int)($row['intent_id'] ?? 0);
            if ($intentId > 0 && !isset($latestByIntent[$intentId])) {
                $latestByIntent[$intentId] = $row;
            }
        }

        $interventions = [];
        $summary = $emptySummary;
        foreach ($latestByIntent as $row) {
            $intervention = $this->interventionFromRow($row);
            $assessmentRow = Db::name(self::ASSESSMENT_TABLE)
                ->where('tenant_id', $resolvedTenantId)
                ->where('hotel_id', $hotelId)
                ->where('intent_id', (int)$intervention['intent_id'])
                ->where('intervention_contract_id', (int)$intervention['id'])
                ->order('id', 'desc')
                ->find();
            $assessment = is_array($assessmentRow) ? $this->assessmentFromRow($assessmentRow) : null;
            $intervention['latest_assessment'] = $assessment;
            $interventions[] = $intervention;
            if ($assessment === null) {
                $summary['unassessed']++;
            } else {
                $summary[(string)$assessment['verdict']]++;
            }
        }

        usort($interventions, static fn(array $left, array $right): int =>
            ((int)$right['id']) <=> ((int)$left['id']));

        $monitor = $this->latestMonitorState($resolvedTenantId, $hotelId);
        return [
            'status' => 'ready',
            'migration_required' => false,
            'missing_tables' => [],
            'tenant_id' => $resolvedTenantId,
            'hotel_id' => $hotelId,
            'current_goal_contract' => $history[0] ?? null,
            'history' => $history,
            'interventions' => $interventions,
            'summary' => $summary,
            'monitor' => $monitor,
            'data_gaps' => ($monitor['status'] ?? '') === 'migration_required'
                ? ['operating_goal_monitor_runs_migration_required']
                : [],
        ];
    }

    /**
     * @param array<int,int|string> $hotelIds
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createGoalContract(
        int $tenantId,
        array $hotelIds,
        int $hotelId,
        array $input,
        int $createdBy
    ): array {
        $resolvedTenantId = $this->resolveScope($tenantId, $hotelIds, $hotelId);
        $this->assertInputHotel($input, $hotelId);
        $this->assertTableExists(self::GOAL_TABLE);
        if ($createdBy <= 0) {
            throw new InvalidArgumentException('created_by is required');
        }

        $content = $this->normalizeGoalContent($resolvedTenantId, $hotelId, $input);
        $digest = $this->digest($content);

        return $this->persistGoalContractAfterScope(
            $resolvedTenantId,
            $hotelId,
            $createdBy,
            $input,
            $content,
            $digest
        );
    }

    /**
     * Persistence phase kept separate so scope resolution and the write boundary
     * can be regression-tested as two distinct moments.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $content
     * @return array<string,mixed>
     */
    private function persistGoalContractAfterScope(
        int $resolvedTenantId,
        int $hotelId,
        int $createdBy,
        array $input,
        array $content,
        string $digest
    ): array {
        return Db::transaction(function () use (
            $resolvedTenantId,
            $hotelId,
            $createdBy,
            $input,
            $content,
            $digest
        ): array {
            $this->lockHotelScope($resolvedTenantId, $hotelId);
            $goalRows = Db::name(self::GOAL_TABLE)
                ->where('tenant_id', $resolvedTenantId)
                ->where('hotel_id', $hotelId)
                ->order('id', 'asc')
                ->lock(true)
                ->select()
                ->toArray();
            $versionNo = 1;
            foreach ($goalRows as $goalRow) {
                $versionNo = max($versionNo, (int)($goalRow['version_no'] ?? 0) + 1);
                if (hash_equals($digest, (string)($goalRow['content_digest'] ?? ''))) {
                    return $this->withWriteReceipt($this->goalFromRow($goalRow), true);
                }
            }

            $now = date('Y-m-d H:i:s');
            $id = (int)Db::name(self::GOAL_TABLE)->insertGetId([
                'tenant_id' => $resolvedTenantId,
                'hotel_id' => $hotelId,
                'version_no' => $versionNo,
                'contract_schema' => $content['contract_schema'],
                'primary_objective' => $content['primary_objective'],
                'primary_metric_key' => $content['primary_metric_key'],
                'objective_direction' => $content['objective_direction'],
                'guard_metrics_json' => $this->json($content['guard_metrics']),
                'operating_constraints_json' => $this->json($content['operating_constraints']),
                'risk_preference' => $content['risk_preference'],
                'operating_phase' => $content['operating_phase'],
                'phase_note' => $content['phase_note'],
                'stop_conditions_json' => $this->json($content['stop_conditions']),
                'rollback_plan' => $content['rollback_plan'],
                'effective_from' => $content['effective_from'],
                'effective_to' => $content['effective_to'],
                'version_note' => $this->limitedString($input['version_note'] ?? '', 500, 'version_note'),
                'content_digest' => $digest,
                'created_by' => $createdBy,
                'created_at' => $now,
            ]);

            return $this->withWriteReceipt(
                $this->readGoalExact($id, $digest, $resolvedTenantId, $hotelId),
                false
            );
        });
    }

    /**
     * @param array<int,int|string> $hotelIds
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createInterventionForIntent(
        int $tenantId,
        array $hotelIds,
        int $hotelId,
        int $intentId,
        array $input,
        int $createdBy
    ): array {
        $resolvedTenantId = $this->resolveScope($tenantId, $hotelIds, $hotelId);
        $this->assertInputHotel($input, $hotelId);
        foreach ([
            self::GOAL_TABLE,
            self::INTERVENTION_TABLE,
            'operation_execution_intents',
            'operation_execution_tasks',
        ] as $table) {
            $this->assertTableExists($table);
        }
        if ($createdBy <= 0) {
            throw new InvalidArgumentException('created_by is required');
        }

        $input = $this->withAutomaticBaseline(
            $resolvedTenantId,
            $hotelId,
            $input
        );

        return Db::transaction(fn(): array => $this->persistIntervention(
            $resolvedTenantId,
            $hotelId,
            $intentId,
            $input,
            $createdBy
        ));
    }

    /**
     * @param array<int,int|string> $hotelIds
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createAssessmentForTask(
        int $tenantId,
        array $hotelIds,
        int $hotelId,
        int $taskId,
        array $input,
        int $assessedBy
    ): array {
        return $this->operationManagementService()->withExecutionTaskMutationAuthorization(
            $taskId,
            $hotelIds,
            fn(array $authorization): array => $this->createAssessmentForTaskAuthorized(
                $tenantId,
                $hotelIds,
                $hotelId,
                $taskId,
                $input,
                $assessedBy,
                $authorization
            )
        );
    }

    /** @param array<string,mixed> $authorization @return array<string,mixed> */
    private function createAssessmentForTaskAuthorized(
        int $tenantId,
        array $hotelIds,
        int $hotelId,
        int $taskId,
        array $input,
        int $assessedBy,
        array $authorization
    ): array {
        $resolvedTenantId = $this->resolveScope($tenantId, $hotelIds, $hotelId);
        $this->assertInputHotel($input, $hotelId);
        foreach ([
            self::GOAL_TABLE,
            self::INTERVENTION_TABLE,
            self::ASSESSMENT_TABLE,
            'operation_execution_intents',
            'operation_execution_tasks',
            'operation_execution_evidence',
        ] as $table) {
            $this->assertTableExists($table);
        }
        $assessmentOrigin = strtolower(trim((string)($input['assessment_origin'] ?? 'human')));
        $systemMonitorAssessment = $assessedBy === 0 && $assessmentOrigin === 'system_monitor';
        if ($taskId <= 0 || ($assessedBy <= 0 && !$systemMonitorAssessment)) {
            throw new InvalidArgumentException('task_id and assessed_by are required');
        }
        $taskRow = Db::name('operation_execution_tasks')
            ->where('id', $taskId)
            ->where('tenant_id', $resolvedTenantId)
            ->where('hotel_id', $hotelId)
            ->whereNull('deleted_at')
            ->find();
        if (!is_array($taskRow)) {
            throw new InvalidArgumentException('execution task does not belong to the selected hotel');
        }
        $intentId = (int)($taskRow['intent_id'] ?? 0);
        $intentRow = $this->findIntent($resolvedTenantId, $hotelId, $intentId);
        $task = $this->executionTaskFromRow($taskRow);
        $task['intent_id'] = $intentId;
        $task['intent_status'] = (string)($intentRow['status'] ?? '');

        $interventionRow = Db::name(self::INTERVENTION_TABLE)
            ->where('tenant_id', $resolvedTenantId)
            ->where('hotel_id', $hotelId)
            ->where('intent_id', $intentId)
            ->order('version_no', 'desc')
            ->order('id', 'desc')
            ->find();
        if (!is_array($interventionRow)) {
            throw new InvalidArgumentException('intervention contract is required before assessment');
        }
        $intervention = $this->interventionFromRow($interventionRow);

        $goalRow = Db::name(self::GOAL_TABLE)
            ->where('id', (int)$intervention['goal_contract_id'])
            ->where('tenant_id', $resolvedTenantId)
            ->where('hotel_id', $hotelId)
            ->where('version_no', (int)$intervention['goal_contract_version_no'])
            ->find();
        if (!is_array($goalRow)) {
            throw new RuntimeException('frozen goal contract is unavailable');
        }
        $goal = $this->goalFromRow($goalRow);

        $evidenceRows = Db::name('operation_execution_evidence')
            ->where('tenant_id', $resolvedTenantId)
            ->where('task_id', $taskId)
            ->whereNull('deleted_at')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $evidenceRows = array_map(fn(array $row): array => $this->executionEvidenceFromRow($row), $evidenceRows);

        $judgmentInput = $input;
        if (!array_key_exists('followup_snapshot', $judgmentInput)
            && is_array($judgmentInput['followup'] ?? null)
        ) {
            $judgmentInput['followup_snapshot'] = $judgmentInput['followup'];
        }
        $judgment = $this->judgmentService()->judge(
            $goal,
            $intervention,
            $task,
            $evidenceRows,
            $judgmentInput
        );
        if (!is_array($judgment)) {
            throw new RuntimeException('judgment service returned an invalid result');
        }
        if (is_array($judgment['assessment'] ?? null)) {
            $judgment = $judgment['assessment'];
        }
        $content = $this->normalizeAssessmentContent(
            $resolvedTenantId,
            $hotelId,
            $intentId,
            $taskId,
            (int)$intervention['id'],
            $judgment,
            $judgmentInput
        );
        $digest = $this->digest($content);
        $assessedAt = $this->normalizeDateTime($input['assessed_at'] ?? date('Y-m-d H:i:s'), 'assessed_at');

        $lockedTask = $authorization['task'];
        foreach (['id', 'tenant_id', 'hotel_id', 'intent_id', 'status', 'result_status', 'result_summary', 'executed_at', 'updated_at'] as $field) {
            if (($lockedTask[$field] ?? null) !== ($taskRow[$field] ?? null)) {
                throw new InvalidArgumentException('execution task changed; refresh before assessment');
            }
        }
        $existing = Db::name(self::ASSESSMENT_TABLE)
            ->where('tenant_id', $resolvedTenantId)
            ->where('hotel_id', $hotelId)
            ->where('task_id', $taskId)
            ->where('content_digest', $digest)
            ->find();
        if (is_array($existing)) {
            return $this->withWriteReceipt($this->assessmentFromRow($existing), true);
        }

        $now = date('Y-m-d H:i:s');
        $id = (int)Db::name(self::ASSESSMENT_TABLE)->insertGetId([
            'tenant_id' => $resolvedTenantId,
            'hotel_id' => $hotelId,
            'intent_id' => $intentId,
            'task_id' => $taskId,
            'intervention_contract_id' => (int)$intervention['id'],
            'assessment_schema' => $content['assessment_schema'],
            'verdict' => $content['verdict'],
            'reason_codes_json' => $this->json($content['reason_codes']),
            'followup_snapshot_json' => $this->json($content['followup_snapshot']),
            'guard_observations_json' => $this->json($content['guard_observations']),
            'external_interferences_json' => $this->json($content['external_interferences']),
            'stop_triggered' => $content['stop_triggered'] ? 1 : 0,
            'stop_evidence_refs_json' => $this->json($content['stop_evidence_refs']),
            'comparison_json' => $this->json($content['comparison']),
            'result_summary' => $content['result_summary'],
            'causality_claimed' => 0,
            'content_digest' => $digest,
            'assessed_by' => $assessedBy,
            'assessed_at' => $assessedAt,
            'created_at' => $now,
        ]);

        return $this->withWriteReceipt($this->readAssessmentExact($id, $digest), false);
    }

    /**
     * Persist one scheduler-produced assessment under the explicit system
     * actor (0). This does not execute an operation or write any OTA platform.
     *
     * @param array<int,int|string> $hotelIds
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createAutomatedAssessmentForTask(
        int $tenantId,
        array $hotelIds,
        int $hotelId,
        int $taskId,
        array $input
    ): array {
        $input['assessment_origin'] = 'system_monitor';
        return $this->createAssessmentForTask(
            $tenantId,
            $hotelIds,
            $hotelId,
            $taskId,
            $input,
            0
        );
    }

    /**
     * Create a manual, approval-required checklist intent and its intervention in
     * one local database transaction. No platform write is performed here.
     *
     * @param array<int,int|string> $hotelIds
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createManualIntervention(
        int $tenantId,
        array $hotelIds,
        int $hotelId,
        array $input,
        int $createdBy
    ): array {
        $resolvedTenantId = $this->resolveScope($tenantId, $hotelIds, $hotelId);
        $this->assertInputHotel($input, $hotelId);
        foreach ([
            self::GOAL_TABLE,
            self::INTERVENTION_TABLE,
            'operation_execution_intents',
            'operation_execution_tasks',
        ] as $table) {
            $this->assertTableExists($table);
        }
        if ($createdBy <= 0) {
            throw new InvalidArgumentException('created_by is required');
        }

        $input = $this->withAutomaticBaseline(
            $resolvedTenantId,
            $hotelId,
            $input
        );

        return Db::transaction(function () use (
            $resolvedTenantId,
            $hotelIds,
            $hotelId,
            $input,
            $createdBy
        ): array {
            $this->lockHotelScope($resolvedTenantId, $hotelId);
            $baselineInput = $input['baseline'] ?? $input['baseline_snapshot'] ?? [];
            if (!is_array($baselineInput)) {
                throw new InvalidArgumentException('baseline must be an object');
            }
            $window = is_array($input['observation_window'] ?? null) ? $input['observation_window'] : [];
            $dateStart = (string)($window['start'] ?? $input['observation_window_start'] ?? '');
            $dateEnd = (string)($window['end'] ?? $input['observation_window_end'] ?? '');
            $actionType = $this->requiredString($input['action_type'] ?? '', 50, 'action_type');
            $actionText = $this->requiredString($input['action_text'] ?? '', 1000, 'action_text');
            $title = $this->limitedString($input['title'] ?? '', 500, 'title');
            if ($title === '') {
                $title = $actionText;
            }
            $platform = trim((string)($input['platform'] ?? ''));
            $platform = $platform === '' ? 'manual' : $this->metricKey($platform, 'platform', 40);
            $targetMetric = $this->metricKey($input['target_metric_key'] ?? '', 'target_metric_key');
            $expectedDirection = $this->enum(
                $input['expected_direction'] ?? '',
                ['increase', 'decrease'],
                'expected_direction'
            );
            $expectedDelta = $this->positiveDecimal($input['expected_delta'] ?? null, 'expected_delta');
            $expectedDeltaUnit = $this->enum(
                $input['expected_delta_unit'] ?? '',
                ['absolute', 'percent'],
                'expected_delta_unit'
            );
            $steps = isset($input['steps'])
                ? $this->normalizeObjectList($input['steps'], 'steps', true)
                : [$actionText];
            $acceptanceCriteria = isset($input['acceptance_criteria'])
                ? $this->normalizeObjectList($input['acceptance_criteria'], 'acceptance_criteria', true)
                : [[
                    'metric_key' => $targetMetric,
                    'expected_direction' => $expectedDirection,
                    'expected_delta' => (float)$expectedDelta,
                    'expected_delta_unit' => $expectedDeltaUnit,
                ]];
            $baselineEvidenceRefs = $this->normalizeStringList(
                $baselineInput['evidence_refs'] ?? $baselineInput['source_refs'] ?? [],
                'baseline.evidence_refs'
            );
            if ($baselineEvidenceRefs === []) {
                throw new InvalidArgumentException('baseline.evidence_refs must not be empty');
            }

            $intentInput = [
                'source_module' => 'manual',
                'source_record_id' => 0,
                'hotel_id' => $hotelId,
                'platform' => $platform,
                'object_type' => 'operation_checklist',
                'action_type' => $actionType,
                'date_start' => $dateStart,
                'date_end' => $dateEnd,
                'current_value' => $baselineInput,
                'target_value' => [
                    'title' => $title,
                    'action_text' => $actionText,
                    'steps' => $steps,
                    'acceptance_criteria' => $acceptanceCriteria,
                    'target_metric' => $targetMetric,
                    'expected_direction' => $expectedDirection,
                    'expected_delta' => (float)$expectedDelta,
                    'expected_delta_unit' => $expectedDeltaUnit,
                ],
                'evidence' => [
                    'operating_goal_contract_id' => (int)($input['goal_contract_id'] ?? 0),
                    'rationale' => $this->limitedString($input['rationale'] ?? '', 1000, 'rationale'),
                    'action_text' => $actionText,
                    'evidence_refs' => $baselineEvidenceRefs,
                    'auto_write_ota' => false,
                    'external_action_triggered' => false,
                ],
                'expected_metric' => $targetMetric,
                'expected_delta' => (float)$expectedDelta,
                'risk_level' => $this->limitedString($input['risk_level'] ?? 'medium', 30, 'risk_level'),
                'status' => 'pending_approval',
            ];
            $intent = $this->operationManagementService()->createExecutionIntent(
                $hotelIds,
                $hotelId,
                $intentInput,
                $createdBy
            );
            $intentId = (int)($intent['id'] ?? 0);
            if ($intentId <= 0) {
                throw new RuntimeException('manual execution intent exact readback failed');
            }
            if ((string)($intent['source_module'] ?? '') !== 'manual'
                || (string)($intent['object_type'] ?? '') !== 'operation_checklist'
                || (string)($intent['status'] ?? '') !== 'pending_approval'
            ) {
                throw new RuntimeException('manual execution intent pending-approval readback failed');
            }
            $intervention = $this->persistIntervention(
                $resolvedTenantId,
                $hotelId,
                $intentId,
                $input,
                $createdBy
            );

            return [
                'status' => 'pending_approval',
                'tenant_id' => $resolvedTenantId,
                'hotel_id' => $hotelId,
                'external_action_triggered' => false,
                'auto_write_ota' => false,
                'intent' => $intent,
                'intervention' => $intervention,
            ];
        });
    }

    /** @param array<string,mixed> $input */
    private function persistIntervention(
        int $tenantId,
        int $hotelId,
        int $intentId,
        array $input,
        int $createdBy
    ): array {
        if ($intentId <= 0) {
            throw new InvalidArgumentException('intent_id is required');
        }
        // Stable lock order for every contract writer: hotel -> intent -> goal ->
        // execution tasks -> intervention versions.
        $this->lockHotelScope($tenantId, $hotelId);
        $intent = $this->findIntent($tenantId, $hotelId, $intentId, true);
        $goal = $this->findGoalForIntervention(
            $tenantId,
            $hotelId,
            (int)($input['goal_contract_id'] ?? 0),
            true
        );
        $automaticGoalVersion = (int)($input['_automatic_goal_contract_version_no'] ?? 0);
        if ($automaticGoalVersion > 0
            && ($automaticGoalVersion !== (int)$goal['version_no']
                || (int)($input['goal_contract_id'] ?? 0) !== (int)$goal['id'])
        ) {
            throw new RuntimeException('automatic baseline goal contract changed before persistence');
        }
        Db::name('operation_execution_tasks')
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('intent_id', $intentId)
            ->whereNull('deleted_at')
            ->order('id', 'asc')
            ->lock(true)
            ->select();
        $content = $this->normalizeInterventionContent($tenantId, $hotelId, $intent, $goal, $input);
        $digest = $this->digest($content);

        $versionRows = Db::name(self::INTERVENTION_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('intent_id', $intentId)
            ->order('id', 'asc')
            ->lock(true)
            ->select()
            ->toArray();
        $versionNo = 1;
        foreach ($versionRows as $versionRow) {
            $versionNo = max($versionNo, (int)($versionRow['version_no'] ?? 0) + 1);
            if (hash_equals($digest, (string)($versionRow['content_digest'] ?? ''))) {
                return $this->withWriteReceipt($this->interventionFromRow($versionRow), true);
            }
        }

        $now = date('Y-m-d H:i:s');
        $id = (int)Db::name(self::INTERVENTION_TABLE)->insertGetId([
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'intent_id' => $intentId,
            'version_no' => $versionNo,
            'goal_contract_id' => $content['goal_contract_id'],
            'goal_contract_version_no' => $content['goal_contract_version_no'],
            'contract_schema' => $content['contract_schema'],
            'design_timing' => $content['design_timing'],
            'action_type' => $content['action_type'],
            'rationale' => $content['rationale'],
            'target_metric_key' => $content['target_metric_key'],
            'expected_direction' => $content['expected_direction'],
            'expected_delta' => $content['expected_delta'],
            'expected_delta_unit' => $content['expected_delta_unit'],
            'risk_metric_keys_json' => $this->json($content['risk_metric_keys']),
            'baseline_snapshot_json' => $this->json($content['baseline_snapshot']),
            'observation_window_start' => $content['observation_window_start'],
            'observation_window_end' => $content['observation_window_end'],
            'comparison_mode' => $content['comparison_mode'],
            'comparison_reference' => $content['comparison_reference'],
            'minimum_sample_size' => $content['minimum_sample_size'],
            'stop_condition' => $content['stop_condition'],
            'content_digest' => $digest,
            'created_by' => $createdBy,
            'created_at' => $now,
        ]);

        return $this->withWriteReceipt(
            $this->readInterventionExact($id, $digest, $tenantId, $hotelId, $intentId),
            false
        );
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function normalizeGoalContent(int $tenantId, int $hotelId, array $input): array
    {
        $guardMetrics = $this->normalizeGuardMetrics($input['guard_metrics'] ?? []);
        $constraints = $this->normalizeConstraints($input['operating_constraints'] ?? []);
        $stopConditions = $this->normalizeObjectList(
            $input['stop_conditions'] ?? [],
            'stop_conditions',
            true
        );
        $effectiveFrom = $this->normalizeDate($input['effective_from'] ?? '', 'effective_from');
        $effectiveTo = $this->normalizeDate($input['effective_to'] ?? '', 'effective_to');
        if ($effectiveFrom > $effectiveTo) {
            throw new InvalidArgumentException('effective_from must not be after effective_to');
        }

        return [
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'contract_schema' => 'hotel_operating_goal.v1',
            'primary_objective' => $this->enum(
                $input['primary_objective'] ?? '',
                ['revenue', 'profit', 'cash_flow'],
                'primary_objective'
            ),
            'primary_metric_key' => $this->metricKey($input['primary_metric_key'] ?? '', 'primary_metric_key'),
            'objective_direction' => $this->enum(
                $input['objective_direction'] ?? '',
                ['increase', 'preserve'],
                'objective_direction'
            ),
            'guard_metrics' => $guardMetrics,
            'operating_constraints' => $constraints,
            'risk_preference' => $this->enum(
                $input['risk_preference'] ?? '',
                ['conservative', 'balanced', 'aggressive'],
                'risk_preference'
            ),
            'operating_phase' => $this->metricKey($input['operating_phase'] ?? '', 'operating_phase', 40),
            'phase_note' => $this->limitedString($input['phase_note'] ?? '', 500, 'phase_note'),
            'stop_conditions' => $stopConditions,
            'rollback_plan' => $this->requiredString($input['rollback_plan'] ?? '', 1000, 'rollback_plan'),
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
        ];
    }

    /**
     * @param array<string,mixed> $intent
     * @param array<string,mixed> $goal
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function normalizeInterventionContent(
        int $tenantId,
        int $hotelId,
        array $intent,
        array $goal,
        array $input
    ): array {
        $targetMetric = $this->metricKey(
            $input['target_metric_key'] ?? $intent['expected_metric'] ?? '',
            'target_metric_key'
        );
        if ($targetMetric !== (string)$goal['primary_metric_key']) {
            throw new InvalidArgumentException('target_metric_key must match the frozen goal primary metric');
        }
        $intentMetric = trim((string)($intent['expected_metric'] ?? ''));
        if ($intentMetric !== '' && $intentMetric !== $targetMetric) {
            throw new InvalidArgumentException('target_metric_key conflicts with the execution intent');
        }

        $direction = $this->enum(
            $input['expected_direction'] ?? '',
            ['increase', 'decrease'],
            'expected_direction'
        );
        if ((string)$goal['objective_direction'] === 'increase' && $direction !== 'increase') {
            throw new InvalidArgumentException('expected_direction conflicts with the frozen goal');
        }
        $targetValue = $this->jsonObject($intent['target_value_json'] ?? []);
        $intentDirection = strtolower(trim((string)(
            $targetValue['expected_direction'] ?? $targetValue['target_direction'] ?? $targetValue['direction'] ?? ''
        )));
        if ($intentDirection !== '' && $intentDirection !== $direction) {
            throw new InvalidArgumentException('expected_direction conflicts with the execution intent');
        }

        $expectedDelta = $this->positiveDecimal(
            $input['expected_delta'] ?? $intent['expected_delta'] ?? null,
            'expected_delta'
        );
        if (isset($intent['expected_delta']) && is_numeric($intent['expected_delta'])
            && (float)$intent['expected_delta'] > 0
            && abs((float)$intent['expected_delta'] - (float)$expectedDelta) > 0.0000005
        ) {
            throw new InvalidArgumentException('expected_delta conflicts with the execution intent');
        }

        $guardKeys = array_values(array_unique(array_map(
            static fn(array $item): string => (string)$item['metric_key'],
            (array)$goal['guard_metrics']
        )));
        $riskKeysInput = $input['risk_metric_keys'] ?? [];
        if (!is_array($riskKeysInput)) {
            throw new InvalidArgumentException('risk_metric_keys must be an array');
        }
        $riskKeys = [];
        foreach ($riskKeysInput as $key) {
            $riskKey = $this->metricKey($key, 'risk_metric_keys');
            if (!in_array($riskKey, $guardKeys, true)) {
                throw new InvalidArgumentException('risk_metric_keys must belong to goal guard_metrics');
            }
            $riskKeys[] = $riskKey;
        }
        $riskKeys = array_values(array_unique($riskKeys));
        sort($riskKeys, SORT_STRING);

        $baselineInput = $input['baseline'] ?? $input['baseline_snapshot'] ?? null;
        if (!is_array($baselineInput)) {
            throw new InvalidArgumentException('baseline must be an object');
        }
        $baseline = $this->normalizeMetricSnapshot(
            $baselineInput,
            $tenantId,
            $hotelId,
            $targetMetric,
            'baseline'
        );

        $window = is_array($input['observation_window'] ?? null) ? $input['observation_window'] : [];
        $windowStart = $this->normalizeDate(
            $window['start'] ?? $input['observation_window_start'] ?? '',
            'observation_window.start'
        );
        $windowEnd = $this->normalizeDate(
            $window['end'] ?? $input['observation_window_end'] ?? '',
            'observation_window.end'
        );
        if ($windowStart > $windowEnd) {
            throw new InvalidArgumentException('observation window start must not be after end');
        }
        if ($windowStart < (string)$goal['effective_from'] || $windowEnd > (string)$goal['effective_to']) {
            throw new InvalidArgumentException('observation window must remain inside the frozen goal effective dates');
        }

        $comparison = is_array($input['comparison'] ?? null) ? $input['comparison'] : [];
        $comparisonMode = $this->enum(
            $comparison['mode'] ?? $input['comparison_mode'] ?? $input['comparison_strategy'] ?? '',
            ['same_length_period', 'same_day_realtime', 'target_stay_observation'],
            'comparison.mode'
        );
        $comparisonReference = $this->requiredString(
            $comparison['reference'] ?? $input['comparison_reference'] ?? '',
            1000,
            'comparison.reference'
        );
        $minimumSampleSize = filter_var(
            $input['minimum_sample_size'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($minimumSampleSize === false) {
            throw new InvalidArgumentException('minimum_sample_size must be a positive integer');
        }

        $actionType = $this->requiredString(
            $input['action_type'] ?? $intent['action_type'] ?? '',
            80,
            'action_type'
        );
        if (trim((string)($intent['action_type'] ?? '')) !== ''
            && $actionType !== (string)$intent['action_type']
        ) {
            throw new InvalidArgumentException('action_type conflicts with the execution intent');
        }
        $designTiming = $this->inferDesignTiming($tenantId, $hotelId, (int)$intent['id']);
        if (isset($input['design_timing'])
            && $this->enum($input['design_timing'], ['prospective', 'retrospective'], 'design_timing') !== $designTiming
        ) {
            throw new InvalidArgumentException('design_timing conflicts with execution history');
        }

        return [
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'intent_id' => (int)$intent['id'],
            'goal_contract_id' => (int)$goal['id'],
            'goal_contract_version_no' => (int)$goal['version_no'],
            'contract_schema' => 'operation_intervention.v1',
            'design_timing' => $designTiming,
            'action_type' => $actionType,
            'rationale' => $this->requiredString($input['rationale'] ?? '', 1000, 'rationale'),
            'target_metric_key' => $targetMetric,
            'expected_direction' => $direction,
            'expected_delta' => $expectedDelta,
            'expected_delta_unit' => $this->enum(
                $input['expected_delta_unit'] ?? '',
                ['absolute', 'percent'],
                'expected_delta_unit'
            ),
            'risk_metric_keys' => $riskKeys,
            'baseline_snapshot' => $baseline,
            'observation_window_start' => $windowStart,
            'observation_window_end' => $windowEnd,
            'comparison_mode' => $comparisonMode,
            'comparison_reference' => $comparisonReference,
            'minimum_sample_size' => (int)$minimumSampleSize,
            'stop_condition' => $this->requiredString($input['stop_condition'] ?? '', 1000, 'stop_condition'),
        ];
    }

    /**
     * @param array<string,mixed> $judgment
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function normalizeAssessmentContent(
        int $tenantId,
        int $hotelId,
        int $intentId,
        int $taskId,
        int $interventionId,
        array $judgment,
        array $input
    ): array {
        $followup = $judgment['followup_snapshot']
            ?? $judgment['followup']
            ?? $input['followup_snapshot']
            ?? $input['followup']
            ?? [];
        if (!is_array($followup)) {
            throw new InvalidArgumentException('judgment followup must be an object');
        }
        $guardObservations = $judgment['guard_observations']
            ?? $input['guard_observations']
            ?? $judgment['guard_results']
            ?? [];
        $externalInterferences = $judgment['external_interferences']
            ?? $input['external_interferences']
            ?? [];
        $comparison = $judgment['comparison'] ?? [];
        if (is_array($comparison) && is_array($judgment['guard_results'] ?? null)) {
            $comparison['guard_results'] = $judgment['guard_results'];
        }
        $notes = $this->limitedString($input['notes'] ?? '', 1000, 'notes');
        if (is_array($comparison) && $notes !== '') {
            $comparison['notes'] = $notes;
        }
        $assessmentOrigin = strtolower(trim((string)($input['assessment_origin'] ?? 'human')));
        if (is_array($comparison)) {
            $comparison['assessment_origin'] = $assessmentOrigin === 'system_monitor'
                ? 'system_monitor'
                : 'human';
        }
        foreach ([
            'guard_observations' => $guardObservations,
            'external_interferences' => $externalInterferences,
            'comparison' => $comparison,
        ] as $field => $value) {
            if (!is_array($value)) {
                throw new InvalidArgumentException('judgment ' . $field . ' must be an array');
            }
        }

        return [
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'intent_id' => $intentId,
            'task_id' => $taskId,
            'intervention_contract_id' => $interventionId,
            'assessment_schema' => 'operation_intervention_assessment.v1',
            'verdict' => $this->enum(
                $judgment['verdict'] ?? '',
                ['supported', 'contradicted', 'indeterminate'],
                'verdict'
            ),
            'reason_codes' => $this->normalizeStringList($judgment['reason_codes'] ?? [], 'reason_codes'),
            'followup_snapshot' => $this->canonicalize($followup),
            'guard_observations' => $this->canonicalize($guardObservations),
            'external_interferences' => $this->canonicalize($externalInterferences),
            'stop_triggered' => $this->strictBoolean(
                $judgment['stop_triggered'] ?? $input['stop_triggered'] ?? false,
                'stop_triggered'
            ),
            'stop_evidence_refs' => $this->normalizeStringList(
                $judgment['stop_evidence_refs'] ?? $input['stop_evidence_refs'] ?? [],
                'stop_evidence_refs'
            ),
            'comparison' => $this->canonicalize($comparison),
            'result_summary' => $this->requiredString(
                $judgment['result_summary'] ?? $judgment['summary'] ?? '',
                1000,
                'result_summary'
            ),
            'causality_claimed' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function findIntent(int $tenantId, int $hotelId, int $intentId, bool $lock = false): array
    {
        if ($intentId <= 0) {
            throw new InvalidArgumentException('intent_id is required');
        }
        $query = Db::name('operation_execution_intents')
            ->where('id', $intentId)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->whereNull('deleted_at');
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        if (!is_array($row)) {
            throw new InvalidArgumentException('execution intent does not belong to the selected hotel');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function findGoalForIntervention(
        int $tenantId,
        int $hotelId,
        int $goalContractId,
        bool $lock = false
    ): array
    {
        $query = Db::name(self::GOAL_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId);
        if ($goalContractId > 0) {
            $query->where('id', $goalContractId);
        } else {
            $query->order('version_no', 'desc')->order('id', 'desc');
        }
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        if (!is_array($row)) {
            throw new InvalidArgumentException('goal contract does not belong to the selected hotel');
        }
        return $this->goalFromRow($row);
    }

    /**
     * Replace hand-entered baseline values with an exact verified fact-layer
     * snapshot. Legacy callers that already provide a baseline remain fully
     * compatible unless they explicitly request automatic mode.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function withAutomaticBaseline(int $tenantId, int $hotelId, array $input): array
    {
        $baseline = $input['baseline'] ?? $input['baseline_snapshot'] ?? null;
        $mode = strtolower(trim((string)($input['baseline_mode'] ?? '')));
        if (is_array($baseline) && $baseline !== [] && $mode !== 'automatic') {
            return $input;
        }

        $goal = $this->findGoalForIntervention(
            $tenantId,
            $hotelId,
            (int)($input['goal_contract_id'] ?? 0)
        );
        // The fact-layer read happens before the persistence transaction.
        // Freeze this exact append-only goal so a newer version committed
        // during the read cannot silently change the intervention contract.
        $input['goal_contract_id'] = (int)$goal['id'];
        $input['_automatic_goal_contract_version_no'] = (int)$goal['version_no'];
        $targetMetric = $this->metricKey(
            $input['target_metric_key'] ?? $goal['primary_metric_key'] ?? '',
            'target_metric_key'
        );
        if ($targetMetric !== (string)($goal['primary_metric_key'] ?? '')) {
            throw new InvalidArgumentException('target_metric_key must match the frozen goal primary metric');
        }

        $window = is_array($input['observation_window'] ?? null)
            ? $input['observation_window']
            : [];
        $windowStart = $this->normalizeDate(
            $window['start'] ?? $input['observation_window_start'] ?? '',
            'observation_window.start'
        );
        $windowEnd = $this->normalizeDate(
            $window['end'] ?? $input['observation_window_end'] ?? '',
            'observation_window.end'
        );
        if ($windowStart > $windowEnd) {
            throw new InvalidArgumentException('observation window start must not be after end');
        }

        $comparison = is_array($input['comparison'] ?? null) ? $input['comparison'] : [];
        $comparisonMode = strtolower(trim((string)(
            $comparison['mode']
                ?? $input['comparison_mode']
                ?? $input['comparison_strategy']
                ?? 'same_length_period'
        )));
        if ($comparisonMode === 'target_stay_observation') {
            throw new InvalidArgumentException('automatic_baseline_target_stay_observation_not_supported');
        }

        $baselineStartRaw = trim((string)(
            $input['baseline_period_start']
                ?? $input['baseline_date_start']
                ?? ''
        ));
        $baselineEndRaw = trim((string)(
            $input['baseline_period_end']
                ?? $input['baseline_date_end']
                ?? ''
        ));
        if ($baselineStartRaw !== '' || $baselineEndRaw !== '') {
            $baselineStart = $this->normalizeDate($baselineStartRaw, 'baseline_period_start');
            $baselineEnd = $this->normalizeDate($baselineEndRaw, 'baseline_period_end');
            if ($baselineStart > $baselineEnd) {
                throw new InvalidArgumentException('baseline period start must not be after end');
            }
        } elseif ($comparisonMode === 'same_day_realtime') {
            $baselineStart = $windowStart;
            $baselineEnd = $windowStart;
        } else {
            $windowStartDate = new DateTimeImmutable($windowStart);
            $windowEndDate = new DateTimeImmutable($windowEnd);
            $periodDays = (int)$windowStartDate->diff($windowEndDate)->format('%a') + 1;
            $baselineEndDate = $windowStartDate->modify('-1 day');
            $baselineStartDate = $baselineEndDate->modify('-' . ($periodDays - 1) . ' days');
            $baselineStart = $baselineStartDate->format('Y-m-d');
            $baselineEnd = $baselineEndDate->format('Y-m-d');
        }

        $snapshotContext = [];
        $factScope = strtolower(trim((string)(
            $input['baseline_fact_scope']
                ?? $input['fact_scope']
                ?? ''
        )));
        if ($factScope !== '') {
            $snapshotContext['fact_scope'] = $factScope;
        }
        $baselinePlatform = strtolower(trim((string)($input['baseline_platform'] ?? '')));
        if (in_array($baselinePlatform, ['combined', 'ctrip', 'meituan'], true)) {
            $snapshotContext['platform'] = $baselinePlatform;
        }

        $snapshotResult = $this->snapshotService()->snapshot(
            $tenantId,
            $hotelId,
            $targetMetric,
            $baselineStart,
            $baselineEnd,
            $snapshotContext
        );
        $snapshot = is_array($snapshotResult['snapshot'] ?? null)
            ? $snapshotResult['snapshot']
            : null;
        if ((string)($snapshotResult['status'] ?? '') !== 'ready'
            || !is_array($snapshot)
            || strtolower(trim((string)($snapshot['quality_status'] ?? ''))) !== 'verified'
            || strtolower(trim((string)($snapshot['readback_status'] ?? ''))) !== 'readback_verified'
        ) {
            $reasonCodes = $this->automaticBaselineReasonCodes($snapshotResult);
            throw new InvalidArgumentException(
                'automatic_baseline_unavailable:' . implode(',', $reasonCodes)
            );
        }

        $snapshot['baseline_origin'] = 'system_verified_snapshot';
        $snapshot['automatic_readback'] = true;
        $input['baseline'] = $snapshot;
        unset($input['baseline_snapshot']);
        $input['baseline_mode'] = 'automatic';
        $input['baseline_period_start'] = $baselineStart;
        $input['baseline_period_end'] = $baselineEnd;
        if (trim((string)($comparison['reference'] ?? $input['comparison_reference'] ?? '')) === '') {
            $comparison['reference'] = $baselineStart . '..' . $baselineEnd;
            $input['comparison'] = $comparison + ['mode' => $comparisonMode];
        }
        if (!isset($input['minimum_sample_size']) && is_numeric($snapshot['sample_size'] ?? null)) {
            $input['minimum_sample_size'] = (int)$snapshot['sample_size'];
        }
        return $input;
    }

    /** @param array<string,mixed> $result @return array<int,string> */
    private function automaticBaselineReasonCodes(array $result): array
    {
        $codes = [];
        foreach ((array)($result['reason_codes'] ?? []) as $code) {
            if (is_scalar($code) && trim((string)$code) !== '') {
                $codes[] = trim((string)$code);
            }
        }
        foreach ((array)($result['data_gaps'] ?? []) as $gap) {
            if (is_array($gap)) {
                foreach ((array)($gap['reason_codes'] ?? []) as $code) {
                    if (is_scalar($code) && trim((string)$code) !== '') {
                        $codes[] = trim((string)$code);
                    }
                }
            } elseif (is_scalar($gap) && trim((string)$gap) !== '') {
                $codes[] = trim((string)$gap);
            }
        }
        $codes = array_values(array_unique($codes));
        sort($codes, SORT_STRING);
        return $codes !== [] ? $codes : ['verified_baseline_not_available'];
    }

    /** @return array<string,mixed> */
    private function latestMonitorState(int $tenantId, int $hotelId): array
    {
        if (!$this->tableExists(self::MONITOR_RUN_TABLE)) {
            return [
                'status' => 'migration_required',
                'monitor_state' => 'inactive',
                'reason_code' => 'operating_goal_monitor_runs_missing',
                'business_date' => null,
                'last_observed_at' => null,
                'run_count' => 0,
                'alert_count' => 0,
                'assessment_count' => 0,
                'signal_codes' => [],
                'data_gaps' => [],
                'db_readback_verified' => false,
            ];
        }

        $row = Db::name(self::MONITOR_RUN_TABLE)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->order('last_observed_at', 'desc')
            ->order('id', 'desc')
            ->find();
        if (!is_array($row)) {
            return [
                'status' => 'not_run',
                'monitor_state' => 'inactive',
                'reason_code' => 'monitor_heartbeat_missing',
                'business_date' => null,
                'last_observed_at' => null,
                'run_count' => 0,
                'alert_count' => 0,
                'assessment_count' => 0,
                'signal_codes' => [],
                'data_gaps' => [],
                'db_readback_verified' => true,
            ];
        }

        return [
            'status' => 'ready',
            'monitor_state' => (string)($row['monitor_state'] ?? 'inactive'),
            'reason_code' => null,
            'business_date' => (string)($row['business_date'] ?? ''),
            'goal_contract_id' => (int)($row['goal_contract_id'] ?? 0),
            'goal_contract_version_no' => (int)($row['goal_contract_version_no'] ?? 0),
            'last_observed_at' => (string)($row['last_observed_at'] ?? ''),
            'run_count' => (int)($row['run_count'] ?? 0),
            'alert_count' => (int)($row['alert_count'] ?? 0),
            'assessment_count' => (int)($row['assessment_count'] ?? 0),
            'signal_codes' => $this->jsonArray($row['signal_codes_json'] ?? []),
            'data_gaps' => $this->jsonArray($row['data_gaps_json'] ?? []),
            'content_digest' => (string)($row['content_digest'] ?? ''),
            'db_readback_verified' => true,
        ];
    }

    private function inferDesignTiming(int $tenantId, int $hotelId, int $intentId): string
    {
        if (!$this->tableExists('operation_execution_tasks')) {
            return 'prospective';
        }
        $tasks = Db::name('operation_execution_tasks')
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('intent_id', $intentId)
            ->whereNull('deleted_at')
            ->select()
            ->toArray();
        foreach ($tasks as $task) {
            if (in_array((string)($task['status'] ?? ''), ['executed', 'failed'], true)
                || trim((string)($task['executed_at'] ?? '')) !== ''
            ) {
                return 'retrospective';
            }
        }
        return 'prospective';
    }

    /** @param array<string,mixed> $input */
    private function assertInputHotel(array $input, int $hotelId): void
    {
        if (array_key_exists('hotel_id', $input) && (int)$input['hotel_id'] !== $hotelId) {
            throw new InvalidArgumentException('input hotel_id conflicts with selected hotel');
        }
    }

    /** @param array<int,int|string> $hotelIds */
    private function resolveScope(int $tenantId, array $hotelIds, int $hotelId): int
    {
        if ($hotelId <= 0) {
            throw new InvalidArgumentException('hotel_id is required');
        }
        $permittedHotelIds = array_values(array_unique(array_filter(
            array_map('intval', $hotelIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($permittedHotelIds !== [] && !in_array($hotelId, $permittedHotelIds, true)) {
            throw new InvalidArgumentException('hotel_id is not permitted');
        }
        if ($tenantId > 0 && $permittedHotelIds === []) {
            throw new InvalidArgumentException('permitted hotel scope is required');
        }
        if (!$this->tableExists('hotels')) {
            throw new RuntimeException('hotels table is unavailable');
        }
        $hotel = Db::name('hotels')->where('id', $hotelId)->find();
        if (!is_array($hotel)) {
            throw new InvalidArgumentException('hotel does not exist');
        }
        $hotelTenantId = (int)($hotel['tenant_id'] ?? 0);
        if ($hotelTenantId <= 0) {
            throw new RuntimeException('hotel tenant_id is unavailable');
        }
        if ($tenantId > 0 && $tenantId !== $hotelTenantId) {
            throw new InvalidArgumentException('hotel does not belong to tenant');
        }
        return $hotelTenantId;
    }

    private function lockHotelScope(int $tenantId, int $hotelId): array
    {
        $hotel = Db::name('hotels')->where('id', $hotelId)->lock(true)->find();
        if (!is_array($hotel) || (int)($hotel['tenant_id'] ?? 0) !== $tenantId) {
            throw new RuntimeException('hotel is unavailable in the current tenant scope');
        }
        return $hotel;
    }

    /** @return array<string,mixed> */
    private function readGoalExact(int $id, string $digest, int $tenantId, int $hotelId): array
    {
        $row = Db::name(self::GOAL_TABLE)
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('content_digest', $digest)
            ->find();
        if (!is_array($row)) {
            throw new RuntimeException('goal contract exact readback failed');
        }
        return $this->goalFromRow($row);
    }

    /** @return array<string,mixed> */
    private function readInterventionExact(
        int $id,
        string $digest,
        int $tenantId,
        int $hotelId,
        int $intentId
    ): array
    {
        $row = Db::name(self::INTERVENTION_TABLE)
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->where('intent_id', $intentId)
            ->where('content_digest', $digest)
            ->find();
        if (!is_array($row)) {
            throw new RuntimeException('intervention contract exact readback failed');
        }
        return $this->interventionFromRow($row);
    }

    /** @return array<string,mixed> */
    private function readAssessmentExact(int $id, string $digest): array
    {
        $row = Db::name(self::ASSESSMENT_TABLE)->where('id', $id)->where('content_digest', $digest)->find();
        if (!is_array($row)) {
            throw new RuntimeException('intervention assessment exact readback failed');
        }
        return $this->assessmentFromRow($row);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function goalFromRow(array $row): array
    {
        $content = [
            'tenant_id' => (int)$row['tenant_id'],
            'hotel_id' => (int)$row['hotel_id'],
            'contract_schema' => (string)$row['contract_schema'],
            'primary_objective' => (string)$row['primary_objective'],
            'primary_metric_key' => (string)$row['primary_metric_key'],
            'objective_direction' => (string)$row['objective_direction'],
            'guard_metrics' => $this->jsonArray($row['guard_metrics_json'] ?? []),
            'operating_constraints' => $this->jsonArray($row['operating_constraints_json'] ?? []),
            'risk_preference' => (string)$row['risk_preference'],
            'operating_phase' => (string)$row['operating_phase'],
            'phase_note' => (string)($row['phase_note'] ?? ''),
            'stop_conditions' => $this->jsonArray($row['stop_conditions_json'] ?? []),
            'rollback_plan' => (string)$row['rollback_plan'],
            'effective_from' => (string)$row['effective_from'],
            'effective_to' => (string)$row['effective_to'],
        ];
        $this->assertDigestMatches($row, $content, 'goal contract');
        return [
            'id' => (int)$row['id'],
            'tenant_id' => $content['tenant_id'],
            'hotel_id' => $content['hotel_id'],
            'version_no' => (int)$row['version_no'],
            'contract_schema' => $content['contract_schema'],
            'primary_objective' => $content['primary_objective'],
            'primary_metric_key' => $content['primary_metric_key'],
            'objective_direction' => $content['objective_direction'],
            'guard_metrics' => $content['guard_metrics'],
            'operating_constraints' => $content['operating_constraints'],
            'risk_preference' => $content['risk_preference'],
            'operating_phase' => $content['operating_phase'],
            'phase_note' => $content['phase_note'],
            'stop_conditions' => $content['stop_conditions'],
            'rollback_plan' => $content['rollback_plan'],
            'effective_from' => $content['effective_from'],
            'effective_to' => $content['effective_to'],
            'version_note' => (string)($row['version_note'] ?? ''),
            'content_digest' => (string)$row['content_digest'],
            'created_by' => (int)$row['created_by'],
            'created_at' => (string)$row['created_at'],
            'db_readback_verified' => true,
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function interventionFromRow(array $row): array
    {
        $expectedDelta = $this->decimal($row['expected_delta'] ?? null, 'expected_delta');
        $baseline = $this->jsonObject($row['baseline_snapshot_json'] ?? []);
        $content = [
            'tenant_id' => (int)$row['tenant_id'],
            'hotel_id' => (int)$row['hotel_id'],
            'intent_id' => (int)$row['intent_id'],
            'goal_contract_id' => (int)$row['goal_contract_id'],
            'goal_contract_version_no' => (int)$row['goal_contract_version_no'],
            'contract_schema' => (string)$row['contract_schema'],
            'design_timing' => (string)$row['design_timing'],
            'action_type' => (string)$row['action_type'],
            'rationale' => (string)$row['rationale'],
            'target_metric_key' => (string)$row['target_metric_key'],
            'expected_direction' => (string)$row['expected_direction'],
            'expected_delta' => $expectedDelta,
            'expected_delta_unit' => (string)$row['expected_delta_unit'],
            'risk_metric_keys' => $this->jsonArray($row['risk_metric_keys_json'] ?? []),
            'baseline_snapshot' => $baseline,
            'observation_window_start' => (string)$row['observation_window_start'],
            'observation_window_end' => (string)$row['observation_window_end'],
            'comparison_mode' => (string)$row['comparison_mode'],
            'comparison_reference' => (string)$row['comparison_reference'],
            'minimum_sample_size' => (int)$row['minimum_sample_size'],
            'stop_condition' => (string)$row['stop_condition'],
        ];
        $this->assertDigestMatches($row, $content, 'intervention contract');
        return [
            'id' => (int)$row['id'],
            'tenant_id' => $content['tenant_id'],
            'hotel_id' => $content['hotel_id'],
            'intent_id' => $content['intent_id'],
            'version_no' => (int)$row['version_no'],
            'goal_contract_id' => $content['goal_contract_id'],
            'goal_contract_version_no' => $content['goal_contract_version_no'],
            'contract_schema' => $content['contract_schema'],
            'design_timing' => $content['design_timing'],
            'contract_status' => $content['design_timing'],
            'action_type' => $content['action_type'],
            'rationale' => $content['rationale'],
            'target_metric_key' => $content['target_metric_key'],
            'expected_direction' => $content['expected_direction'],
            'expected_delta' => (float)$content['expected_delta'],
            'expected_delta_unit' => $content['expected_delta_unit'],
            'risk_metric_keys' => $content['risk_metric_keys'],
            'baseline_snapshot' => $baseline,
            'baseline' => $baseline,
            'observation_window_start' => $content['observation_window_start'],
            'observation_window_end' => $content['observation_window_end'],
            'observation_window' => [
                'start' => $content['observation_window_start'],
                'end' => $content['observation_window_end'],
            ],
            'comparison_mode' => $content['comparison_mode'],
            'comparison_reference' => $content['comparison_reference'],
            'comparison' => [
                'mode' => $content['comparison_mode'],
                'reference' => $content['comparison_reference'],
            ],
            'minimum_sample_size' => $content['minimum_sample_size'],
            'stop_condition' => $content['stop_condition'],
            'content_digest' => (string)$row['content_digest'],
            'created_by' => (int)$row['created_by'],
            'created_at' => (string)$row['created_at'],
            'db_readback_verified' => true,
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function assessmentFromRow(array $row): array
    {
        $followup = $this->jsonObject($row['followup_snapshot_json'] ?? []);
        $content = [
            'tenant_id' => (int)$row['tenant_id'],
            'hotel_id' => (int)$row['hotel_id'],
            'intent_id' => (int)$row['intent_id'],
            'task_id' => (int)$row['task_id'],
            'intervention_contract_id' => (int)$row['intervention_contract_id'],
            'assessment_schema' => (string)$row['assessment_schema'],
            'verdict' => (string)$row['verdict'],
            'reason_codes' => $this->jsonArray($row['reason_codes_json'] ?? []),
            'followup_snapshot' => $followup,
            'guard_observations' => $this->jsonArray($row['guard_observations_json'] ?? []),
            'external_interferences' => $this->jsonArray($row['external_interferences_json'] ?? []),
            'stop_triggered' => (int)($row['stop_triggered'] ?? 0) === 1,
            'stop_evidence_refs' => $this->jsonArray($row['stop_evidence_refs_json'] ?? []),
            'comparison' => $this->jsonObject($row['comparison_json'] ?? []),
            'result_summary' => (string)$row['result_summary'],
            'causality_claimed' => false,
        ];
        if ((int)($row['causality_claimed'] ?? 0) !== 0) {
            throw new RuntimeException('intervention assessment causality boundary drift detected');
        }
        $this->assertDigestMatches($row, $content, 'intervention assessment');
        return [
            'id' => (int)$row['id'],
            'tenant_id' => $content['tenant_id'],
            'hotel_id' => $content['hotel_id'],
            'intent_id' => $content['intent_id'],
            'task_id' => $content['task_id'],
            'intervention_contract_id' => $content['intervention_contract_id'],
            'assessment_schema' => $content['assessment_schema'],
            'verdict' => $content['verdict'],
            'reason_codes' => $content['reason_codes'],
            'followup_snapshot' => $followup,
            'followup' => $followup,
            'guard_observations' => $content['guard_observations'],
            'external_interferences' => $content['external_interferences'],
            'stop_triggered' => $content['stop_triggered'],
            'stop_evidence_refs' => $content['stop_evidence_refs'],
            'comparison' => $content['comparison'],
            'notes' => (string)($content['comparison']['notes'] ?? ''),
            'result_summary' => $content['result_summary'],
            'causality_claimed' => false,
            'content_digest' => (string)$row['content_digest'],
            'assessed_by' => (int)$row['assessed_by'],
            'assessed_at' => (string)$row['assessed_at'],
            'created_at' => (string)$row['created_at'],
            'db_readback_verified' => true,
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function executionTaskFromRow(array $row): array
    {
        $result = $row;
        $result['id'] = (int)$row['id'];
        $result['intent_id'] = (int)$row['intent_id'];
        $result['hotel_id'] = (int)$row['hotel_id'];
        $result['tenant_id'] = (int)$row['tenant_id'];
        $result['target_value'] = $this->jsonObject($row['target_value_json'] ?? []);
        $result['current_value'] = $this->jsonObject($row['current_value_json'] ?? []);
        unset($result['target_value_json'], $result['current_value_json']);
        return $result;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function executionEvidenceFromRow(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'tenant_id' => (int)$row['tenant_id'],
            'task_id' => (int)$row['task_id'],
            'evidence_type' => (string)($row['evidence_type'] ?? ''),
            'before' => $this->jsonObject($row['before_json'] ?? []),
            'after' => $this->jsonObject($row['after_json'] ?? []),
            'platform_response' => $this->jsonObject($row['platform_response_json'] ?? []),
            'attachment_path' => (string)($row['attachment_path'] ?? ''),
            'remark' => (string)($row['remark'] ?? ''),
            'created_by' => (int)($row['created_by'] ?? 0),
            'created_at' => (string)($row['created_at'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $content */
    private function assertDigestMatches(array $row, array $content, string $label): void
    {
        $expected = $this->digest($content);
        if (!hash_equals($expected, (string)($row['content_digest'] ?? ''))) {
            throw new RuntimeException($label . ' digest readback mismatch');
        }
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function withWriteReceipt(array $record, bool $idempotent): array
    {
        $record['idempotent'] = $idempotent;
        $record['db_readback_verified'] = true;
        return $record;
    }

    /** @return array<int,array<string,mixed>> */
    private function normalizeGuardMetrics(mixed $value): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('guard_metrics must be an array');
        }
        if (!array_is_list($value)) {
            $mapped = [];
            foreach ($value as $metricKey => $settings) {
                $item = is_array($settings) ? $settings : ['threshold' => $settings];
                $item['metric_key'] ??= (string)$metricKey;
                $mapped[] = $item;
            }
            $value = $mapped;
        }
        $result = [];
        foreach ($value as $item) {
            $item = is_string($item) ? ['metric_key' => $item] : $item;
            if (!is_array($item)) {
                throw new InvalidArgumentException('guard_metrics items must be objects');
            }
            $item['metric_key'] = $this->metricKey($item['metric_key'] ?? $item['key'] ?? '', 'guard_metrics.metric_key');
            unset($item['key']);
            $result[] = $this->canonicalize($item);
        }
        usort($result, fn(array $left, array $right): int =>
            ((string)$left['metric_key'] <=> (string)$right['metric_key'])
            ?: ($this->json($left) <=> $this->json($right)));
        $seen = [];
        foreach ($result as $item) {
            $key = (string)$item['metric_key'];
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('guard_metrics metric_key must be unique');
            }
            $seen[$key] = true;
        }
        return $result;
    }

    /** @return array<int,array<string,mixed>> */
    private function normalizeConstraints(mixed $value): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('operating_constraints must be an array');
        }
        if (!array_is_list($value)) {
            $mapped = [];
            foreach ($value as $constraintKey => $settings) {
                $item = is_array($settings) ? $settings : ['value' => $settings];
                $item['constraint_key'] ??= (string)$constraintKey;
                $mapped[] = $item;
            }
            $value = $mapped;
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                throw new InvalidArgumentException('operating_constraints items must be objects');
            }
            $item['constraint_key'] = $this->metricKey(
                $item['constraint_key'] ?? $item['key'] ?? '',
                'operating_constraints.constraint_key'
            );
            unset($item['key']);
            if (is_array($item['value'] ?? null) && array_is_list($item['value'])) {
                $item['value'] = $this->normalizeScalarList($item['value']);
            }
            $result[] = $this->canonicalize($item);
        }
        usort($result, fn(array $left, array $right): int =>
            ((string)$left['constraint_key'] <=> (string)$right['constraint_key'])
            ?: ($this->json($left) <=> $this->json($right)));
        return $result;
    }

    /** @return array<int,mixed> */
    private function normalizeObjectList(mixed $value, string $field, bool $requireNonEmpty): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException($field . ' must be an array');
        }
        $result = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $item = trim($item);
                if ($item === '') {
                    throw new InvalidArgumentException($field . ' items must not be empty');
                }
            } elseif (!is_array($item) || $item === []) {
                throw new InvalidArgumentException($field . ' items must be strings or objects');
            }
            $result[] = $this->canonicalize($item);
        }
        if ($requireNonEmpty && $result === []) {
            throw new InvalidArgumentException($field . ' must not be empty');
        }
        usort($result, fn(mixed $left, mixed $right): int => $this->json($left) <=> $this->json($right));
        return $result;
    }

    /** @return array<int,string> */
    private function normalizeStringList(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException($field . ' must be an array');
        }
        $result = [];
        foreach ($value as $item) {
            $item = trim((string)$item);
            if ($item === '') {
                throw new InvalidArgumentException($field . ' items must not be empty');
            }
            $result[] = $item;
        }
        $result = array_values(array_unique($result));
        sort($result, SORT_STRING);
        return $result;
    }

    /** @param array<int,mixed> $value @return array<int,mixed> */
    private function normalizeScalarList(array $value): array
    {
        $result = [];
        foreach ($value as $item) {
            if (is_array($item) || is_object($item) || is_resource($item)) {
                throw new InvalidArgumentException('constraint list values must be scalar');
            }
            $item = is_string($item) ? trim($item) : $item;
            if ($item === '') {
                continue;
            }
            $result[] = $item;
        }
        usort($result, static fn(mixed $left, mixed $right): int => (string)$left <=> (string)$right);
        return array_values(array_unique($result, SORT_REGULAR));
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    private function normalizeMetricSnapshot(
        array $snapshot,
        int $tenantId,
        int $hotelId,
        string $targetMetric,
        string $field
    ): array {
        if (isset($snapshot['tenant_id']) && (int)$snapshot['tenant_id'] !== $tenantId) {
            throw new InvalidArgumentException($field . ' tenant_id conflicts with selected hotel');
        }
        if (isset($snapshot['hotel_id']) && (int)$snapshot['hotel_id'] !== $hotelId) {
            throw new InvalidArgumentException($field . ' hotel_id conflicts with selected hotel');
        }
        $metricKey = isset($snapshot['metric_key'])
            ? $this->metricKey($snapshot['metric_key'], $field . '.metric_key')
            : $targetMetric;
        if ($metricKey !== $targetMetric) {
            throw new InvalidArgumentException($field . ' metric_key must match target_metric_key');
        }
        if (!array_key_exists('value', $snapshot) || !is_numeric($snapshot['value']) || !is_finite((float)$snapshot['value'])) {
            throw new InvalidArgumentException($field . '.value must be numeric');
        }
        $periodStart = $snapshot['period_start'] ?? $snapshot['business_date'] ?? null;
        $periodEnd = $snapshot['period_end'] ?? $snapshot['business_date'] ?? null;
        if ($periodStart === null || $periodEnd === null) {
            throw new InvalidArgumentException($field . ' period_start and period_end are required');
        }
        $periodStart = $this->normalizeDate($periodStart, $field . '.period_start');
        $periodEnd = $this->normalizeDate($periodEnd, $field . '.period_end');
        if ($periodStart > $periodEnd) {
            throw new InvalidArgumentException($field . ' period_start must not be after period_end');
        }
        $evidenceRefs = $snapshot['evidence_refs'] ?? $snapshot['source_refs'] ?? [];
        $evidenceRefs = $this->normalizeStringList($evidenceRefs, $field . '.evidence_refs');
        if ($evidenceRefs === []) {
            throw new InvalidArgumentException($field . '.evidence_refs must not be empty');
        }
        $qualityStatus = $this->enum(
            $snapshot['quality_status'] ?? '',
            ['verified', 'manual_confirmed', 'source_verified', 'readback_verified', 'unverified'],
            $field . '.quality_status'
        );

        $normalized = $snapshot;
        $normalized['tenant_id'] = $tenantId;
        $normalized['hotel_id'] = $hotelId;
        $normalized['metric_key'] = $metricKey;
        $normalized['value'] = (float)$snapshot['value'];
        $normalized['period_start'] = $periodStart;
        $normalized['period_end'] = $periodEnd;
        $normalized['business_date'] = $periodEnd;
        $normalized['evidence_refs'] = $evidenceRefs;
        $normalized['quality_status'] = $qualityStatus;
        unset($normalized['source_refs']);
        if (isset($normalized['captured_at'])) {
            $normalized['captured_at'] = $this->normalizeDateTime($normalized['captured_at'], $field . '.captured_at');
        }
        if (isset($normalized['sample_size'])) {
            $sampleSize = filter_var($normalized['sample_size'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($sampleSize === false) {
                throw new InvalidArgumentException($field . '.sample_size must be a positive integer');
            }
            $normalized['sample_size'] = (int)$sampleSize;
        }
        return $this->canonicalize($normalized);
    }

    private function judgmentService(): object
    {
        if ($this->judgmentService === null) {
            if (!class_exists(OperationInterventionJudgmentService::class)) {
                throw new RuntimeException('OperationInterventionJudgmentService is unavailable');
            }
            $this->judgmentService = new OperationInterventionJudgmentService();
        }
        if (!method_exists($this->judgmentService, 'judge')) {
            throw new RuntimeException('judgment service must provide judge()');
        }
        return $this->judgmentService;
    }

    private function snapshotService(): object
    {
        if ($this->snapshotService === null) {
            $this->snapshotService = new OperatingGoalMetricSnapshotService();
        }
        if (!method_exists($this->snapshotService, 'snapshot')) {
            throw new RuntimeException('snapshot service must provide snapshot()');
        }
        return $this->snapshotService;
    }

    private function operationManagementService(): OperationManagementService
    {
        return $this->operationManagementService ??= new OperationManagementService();
    }

    /** @param array<int,string> $tables @return array<int,string> */
    private function missingTables(array $tables): array
    {
        return array_values(array_filter($tables, fn(string $table): bool => !$this->tableExists($table)));
    }

    private function assertTableExists(string $table): void
    {
        if (!$this->tableExists($table)) {
            throw new RuntimeException('migration_required:' . $table);
        }
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

    private function enum(mixed $value, array $allowed, string $field): string
    {
        $value = strtolower(trim((string)$value));
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($field . ' is not supported');
        }
        return $value;
    }

    private function metricKey(mixed $value, string $field, int $maxLength = 80): string
    {
        $value = strtolower(trim((string)$value));
        if ($value === '' || strlen($value) > $maxLength || preg_match('/^[a-z0-9][a-z0-9_.:-]*$/', $value) !== 1) {
            throw new InvalidArgumentException($field . ' is invalid');
        }
        return $value;
    }

    private function requiredString(mixed $value, int $maxLength, string $field): string
    {
        $value = trim((string)$value);
        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException($field . ' is required and must be at most ' . $maxLength . ' characters');
        }
        return $value;
    }

    private function limitedString(mixed $value, int $maxLength, string $field): string
    {
        $value = trim((string)$value);
        if (mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException($field . ' must be at most ' . $maxLength . ' characters');
        }
        return $value;
    }

    private function positiveDecimal(mixed $value, string $field): string
    {
        $value = $this->decimal($value, $field);
        if ((float)$value <= 0) {
            throw new InvalidArgumentException($field . ' must be positive');
        }
        return $value;
    }

    private function decimal(mixed $value, string $field): string
    {
        if (!is_numeric($value) || !is_finite((float)$value)) {
            throw new InvalidArgumentException($field . ' must be numeric');
        }
        return number_format((float)$value, 6, '.', '');
    }

    private function normalizeDate(mixed $value, string $field): string
    {
        $value = trim((string)$value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
            || $date->format('Y-m-d') !== $value
        ) {
            throw new InvalidArgumentException($field . ' must be YYYY-MM-DD');
        }
        return $value;
    }

    private function normalizeDateTime(mixed $value, string $field): string
    {
        $value = trim((string)$value);
        foreach (['!Y-m-d H:i:s', '!Y-m-d\\TH:i:s'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date !== false
                && ($errors === false || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0))
            ) {
                return $date->format('Y-m-d H:i:s');
            }
        }
        throw new InvalidArgumentException($field . ' must be a date-time without timezone ambiguity');
    }

    private function strictBoolean(mixed $value, string $field): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === 1 || $value === '0' || $value === '1') {
            return (bool)$value;
        }
        throw new InvalidArgumentException($field . ' must be boolean');
    }

    private function json(mixed $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }

    private function digest(array $content): string
    {
        return hash('sha256', $this->json($content));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (!is_array($value)) {
            return $value;
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

    /** @return array<int,mixed> */
    private function jsonArray(mixed $value): array
    {
        $decoded = $this->decodeJson($value);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new RuntimeException('persisted JSON array is invalid');
        }
        return $decoded;
    }

    /** @return array<string,mixed> */
    private function jsonObject(mixed $value): array
    {
        $decoded = $this->decodeJson($value);
        if (!is_array($decoded)) {
            throw new RuntimeException('persisted JSON object is invalid');
        }
        return $decoded;
    }

    private function decodeJson(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value === null || $value === '') {
            return [];
        }
        try {
            return json_decode((string)$value, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException('persisted JSON is invalid', 0, $exception);
        }
    }
}
