<?php
declare(strict_types=1);

namespace app\service\operation;

use app\service\SimulationExecutionReadinessService;
use app\service\SourceBackedExecutionIntentApprovalService;
use app\service\SourceBackedExecutionIntentIdentityService;
use think\facade\Db;

trait OperationExecutionTenantConcern
{
    /** @return array{code:string,message:string}|null */
    private function operationActionTrackTenantSchemaGap(): ?array
    {
        if (!$this->tableExists('operation_action_tracks')) {
            return null;
        }
        foreach ([
            'operation_action_tracks' => ['tenant_id', 'hotel_id'],
            'hotels' => ['id', 'tenant_id'],
        ] as $table => $columns) {
            if (!$this->tableExists($table)) {
                return [
                    'code' => 'operation_action_track_tenant_scope_missing',
                    'message' => 'migration_required: action tracking tenant scope table is unavailable',
                ];
            }
            foreach ($columns as $column) {
                if (!$this->executionTenantSchemaHasColumn($table, $column)) {
                    return [
                        'code' => 'operation_action_track_tenant_schema_missing',
                        'message' => 'migration_required: action tracking tenant scope columns are unavailable',
                    ];
                }
            }
        }

        return null;
    }

    private function scopeOperationActionTrackQueryToCurrentTenant(mixed $query): mixed
    {
        $actionTable = Db::name('operation_action_tracks')->getTable();
        $hotelTable = Db::name('hotels')->getTable();
        return $query->whereExists(static function ($subQuery) use ($actionTable, $hotelTable): void {
            $subQuery->table([$hotelTable => 'operation_action_hotel'])
                ->whereColumn('operation_action_hotel.id', $actionTable . '.hotel_id')
                ->whereColumn('operation_action_hotel.tenant_id', $actionTable . '.tenant_id')
                ->where('operation_action_hotel.tenant_id', '>', 0);
        });
    }

    /** @param array{code:string,message:string} $gap */
    private function operationActionTrackSchemaGapResponse(array $gap): array
    {
        return [
            'actions' => [],
            'effect_validation' => $this->buildEffectValidationSummary(
                [],
                ['total' => 0, 'adopted' => 0, 'data_status' => 'migration_required'],
                ['reviewed' => 0, 'accurate' => 0, 'data_status' => 'migration_required'],
                [$gap]
            ),
            'data_status' => 'migration_required',
            'data_gaps' => [$gap],
        ];
    }

    /**
     * @param array<int,int|string> $hotelIds
     * @param callable(array<string,mixed>):mixed $mutation
     */
    private function withOperationActionTrackMutationAuthorization(
        int $actionId,
        array $hotelIds,
        callable $mutation
    ): mixed {
        if (($schemaGap = $this->operationActionTrackTenantSchemaGap()) !== null) {
            throw new \RuntimeException($schemaGap['message']);
        }
        $hotelIds = array_values(array_unique(array_filter(array_map('intval', $hotelIds))));
        if ($actionId <= 0 || $hotelIds === []) {
            return false;
        }
        $probe = Db::name('operation_action_tracks')
            ->where('id', $actionId)
            ->whereIn('hotel_id', $hotelIds)
            ->whereNull('deleted_at')
            ->field('id,hotel_id')
            ->find();
        if (!is_array($probe)) {
            return false;
        }
        $hotelId = (int)($probe['hotel_id'] ?? 0);
        if ($hotelId <= 0 || !in_array($hotelId, $hotelIds, true)) {
            return false;
        }

        return Db::transaction(function () use ($actionId, $hotelId, $hotelIds, $mutation): mixed {
            try {
                $hotel = Db::name('hotels')->where('id', $hotelId)->lock(true)->find();
            } catch (\Throwable $exception) {
                throw new \RuntimeException(
                    'migration_required: action tracking hotel tenant scope is unavailable',
                    0,
                    $exception
                );
            }
            if (!is_array($hotel) || (int)($hotel['tenant_id'] ?? 0) <= 0) {
                return false;
            }
            $action = Db::name('operation_action_tracks')
                ->where('id', $actionId)
                ->whereIn('hotel_id', $hotelIds)
                ->whereNull('deleted_at')
                ->lock(true)
                ->find();
            if (!is_array($action)
                || (int)($action['hotel_id'] ?? 0) !== $hotelId
                || (int)($action['tenant_id'] ?? 0) !== (int)($hotel['tenant_id'] ?? 0)
            ) {
                return false;
            }

            return $mutation($action);
        });
    }

    /**
     * Resolve one task together with its parent intent and enforce the durable
     * tenant boundary. Source-backed rows remain owned by the tenant that
     * created them even if the hotel is later transferred to another tenant.
     *
     * @param array<int, int|string> $hotelIds
     * @return array{task:array<string,mixed>,intent:array<string,mixed>}
     */
    private function executionTaskAuthorizationContext(
        int $taskId,
        array $hotelIds,
        bool $lock = false,
        bool $requireCurrentSource = false
    ): array {
        $task = $this->executionTaskRow($taskId, $hotelIds, $lock);
        if ($task === null) {
            throw new \RuntimeException('execution task not found');
        }
        $intent = $this->executionIntentRow((int)($task['intent_id'] ?? 0), $hotelIds, $lock);
        if ($intent === null) {
            throw new \RuntimeException('execution task parent intent not found');
        }
        $this->assertExecutionTaskIntentIdentity($task, $intent);

        if ($this->tableExists('hotels')) {
            $currentTenantId = $this->tenantIdForHotel((int)($intent['hotel_id'] ?? 0));
            $intentTenantId = (int)($intent['tenant_id'] ?? 0);
            $taskTenantId = (int)($task['tenant_id'] ?? 0);
            if ($currentTenantId <= 0
                || $intentTenantId <= 0
                || $taskTenantId <= 0
                || $intentTenantId !== $currentTenantId
                || $taskTenantId !== $currentTenantId
            ) {
                throw new \RuntimeException('execution task not found in the current tenant scope');
            }
            if ($requireCurrentSource && $this->sourceBackedExecutionIntentSupports($intent)) {
                $normalizedIntent = $this->normalizeExecutionIntentRow($intent);
                $sourceModule = $this->canonicalExecutionSourceModule($normalizedIntent['source_module'] ?? '');
                if (in_array($sourceModule, ['strategy_simulation', 'quant_simulation'], true)) {
                    $this->assertSimulationIntentSourceIsCurrent($normalizedIntent);
                } elseif (SourceBackedExecutionIntentApprovalService::supports($sourceModule)) {
                    (new SourceBackedExecutionIntentApprovalService())->assertCurrent($normalizedIntent);
                }
            }
        }

        return ['task' => $task, 'intent' => $intent];
    }

    /**
     * Keep the authorization locks alive for the complete mutation callback.
     * The unlocked probe only discovers the hotel row to lock first. Every
     * identity is re-read and revalidated after locks are acquired in this
     * stable order: hotel -> task -> intent -> source.
     *
     * @param array<int, int|string> $hotelIds
     * @param callable(array{hotel:array<string,mixed>,task:array<string,mixed>,intent:array<string,mixed>,source:?array<string,mixed>}):mixed $mutation
     */
    public function withExecutionTaskMutationAuthorization(
        int $taskId,
        array $hotelIds,
        callable $mutation
    ): mixed {
        $this->ensureExecutionTables();
        $this->assertExecutionTenantMutationSchema();
        $hotelIds = array_values(array_unique(array_filter(array_map('intval', $hotelIds))));
        if ($taskId <= 0 || $hotelIds === []) {
            throw new \RuntimeException('execution task not found');
        }
        $probe = Db::name('operation_execution_tasks')
            ->where('id', $taskId)
            ->whereIn('hotel_id', $hotelIds)
            ->whereNull('deleted_at')
            ->field('id,hotel_id,intent_id')
            ->find();
        if (!is_array($probe)) {
            throw new \RuntimeException('execution task not found');
        }
        $hotelId = (int)($probe['hotel_id'] ?? 0);
        if ($hotelId <= 0 || !in_array($hotelId, $hotelIds, true)) {
            throw new \RuntimeException('execution task not found');
        }
        $intentProbe = Db::name('operation_execution_intents')
            ->where('id', (int)($probe['intent_id'] ?? 0))
            ->where('hotel_id', $hotelId)
            ->whereNull('deleted_at')
            ->field('id,tenant_id,source_module,source_record_id,hotel_id')
            ->find();
        if (!is_array($intentProbe)) {
            throw new \RuntimeException('execution task parent intent not found');
        }
        $sourceBackedProbe = $this->sourceBackedExecutionIntentSupports($intentProbe);

        return Db::transaction(function () use (
            $taskId,
            $hotelIds,
            $hotelId,
            $sourceBackedProbe,
            $mutation
        ): mixed {
            try {
                $hotel = Db::name('hotels')->where('id', $hotelId)->lock(true)->find();
            } catch (\Throwable $exception) {
                throw new \RuntimeException('migration_required: hotel tenant scope table is unavailable', 0, $exception);
            }
            if (!is_array($hotel)) {
                throw new \RuntimeException('execution task hotel scope is unavailable');
            }

            $task = $this->executionTaskRow($taskId, $hotelIds, true);
            if ($task === null || (int)($task['hotel_id'] ?? 0) !== $hotelId) {
                throw new \RuntimeException('execution task not found');
            }
            $intent = $this->executionIntentRow((int)($task['intent_id'] ?? 0), $hotelIds, true);
            if ($intent === null || (int)($intent['hotel_id'] ?? 0) !== $hotelId) {
                throw new \RuntimeException('execution task parent intent not found');
            }
            $this->assertExecutionTaskIntentIdentity($task, $intent);
            $hotelTenantId = (int)($hotel['tenant_id'] ?? 0);
            if ($hotelTenantId <= 0
                || (int)($task['tenant_id'] ?? 0) !== $hotelTenantId
                || (int)($intent['tenant_id'] ?? 0) !== $hotelTenantId
            ) {
                throw new \RuntimeException('execution task not found in the current tenant scope');
            }

            $source = null;
            $sourceBacked = $this->sourceBackedExecutionIntentSupports($intent);
            if ($sourceBacked !== $sourceBackedProbe) {
                throw new \RuntimeException('execution task source identity changed; refresh before mutation');
            }
            if ($sourceBacked) {
                $sourceModule = $this->canonicalExecutionSourceModule($intent['source_module'] ?? '');
                $sourceRecordId = (int)($intent['source_record_id'] ?? 0);
                $sourceTable = $this->executionTaskMutationSourceTable($sourceModule);
                if ($sourceTable === '' || $sourceRecordId <= 0 || !$this->tableExists($sourceTable)) {
                    throw new \InvalidArgumentException('source-backed execution record is unavailable');
                }
                $source = $this->executionSourceRecordQuery($sourceTable, $sourceModule, $sourceRecordId)
                    ->lock(true)
                    ->find();

                $lockedHotelTenantId = (int)($hotel['tenant_id'] ?? 0);
                $taskTenantId = (int)($task['tenant_id'] ?? 0);
                $intentTenantId = (int)($intent['tenant_id'] ?? 0);
                $sourceTenantId = is_array($source) ? (int)($source['tenant_id'] ?? 0) : 0;
                if (!is_array($source)
                    || $lockedHotelTenantId <= 0
                    || $taskTenantId <= 0
                    || $intentTenantId <= 0
                    || $sourceTenantId <= 0
                    || $taskTenantId !== $lockedHotelTenantId
                    || $intentTenantId !== $lockedHotelTenantId
                    || $sourceTenantId !== $lockedHotelTenantId
                ) {
                    throw new \RuntimeException('execution task not found in the current tenant scope');
                }

                $normalizedIntent = $this->normalizeExecutionIntentRow($intent);
                if (in_array($sourceModule, ['strategy_simulation', 'quant_simulation'], true)) {
                    $this->assertSimulationIntentSourceIsCurrent($normalizedIntent, $source, $lockedHotelTenantId);
                } elseif (SourceBackedExecutionIntentApprovalService::supports($sourceModule)) {
                    (new SourceBackedExecutionIntentApprovalService())->assertCurrentAgainstLockedRows(
                        $normalizedIntent,
                        $source,
                        $lockedHotelTenantId
                    );
                }

                // Recheck against the already locked hotel row after every
                // source-specific validation has completed.
                if ((int)($hotel['tenant_id'] ?? 0) !== $taskTenantId
                    || $taskTenantId !== $intentTenantId
                    || $intentTenantId !== $sourceTenantId
                ) {
                    throw new \RuntimeException('execution task not found in the current tenant scope');
                }
            }

            return $mutation([
                'hotel' => $hotel,
                'task' => $task,
                'intent' => $intent,
                'source' => is_array($source) ? $source : null,
            ]);
        });
    }

    /** @param array<int, int|string> $hotelIds */
    public function assertExecutionTaskMutationAuthorized(int $taskId, array $hotelIds): void
    {
        $this->withExecutionTaskMutationAuthorization(
            $taskId,
            $hotelIds,
            static fn(array $context): null => null
        );
    }

    /**
     * Lock order shared with approval and task mutations: hotel -> source.
     * Snapshot validation and persistence both run before this transaction is
     * released, so neither tenant nor source facts may drift between them.
     *
     * @param array<string,mixed> $payload
     * @param array<int,int|string> $hotelIds
     * @param callable(array<string,mixed>):mixed $creation
     */
    private function withSourceBackedExecutionIntentCreationAuthorization(
        array $payload,
        array $hotelIds,
        callable $creation
    ): mixed {
        $this->assertExecutionTenantMutationSchema();
        $payload['source_module'] = $this->canonicalExecutionSourceModule($payload['source_module'] ?? '');
        if (!$this->sourceBackedExecutionIntentSupports($payload)) {
            throw new \InvalidArgumentException('source-backed execution intent identity is unavailable');
        }
        $hotelIds = array_values(array_unique(array_filter(array_map('intval', $hotelIds))));
        $hotelId = (int)($payload['hotel_id'] ?? 0);
        $sourceRecordId = (int)($payload['source_record_id'] ?? 0);
        $sourceTable = $this->executionTaskMutationSourceTable((string)$payload['source_module']);
        if ($hotelId <= 0
            || !in_array($hotelId, $hotelIds, true)
            || $sourceRecordId <= 0
            || $sourceTable === ''
            || !$this->tableExists($sourceTable)
        ) {
            throw new \InvalidArgumentException('source-backed execution intent identity is unavailable');
        }

        return Db::transaction(function () use (
            $payload,
            $hotelId,
            $sourceRecordId,
            $sourceTable,
            $creation
        ): mixed {
            try {
                $hotel = Db::name('hotels')->where('id', $hotelId)->lock(true)->find();
            } catch (\Throwable $exception) {
                throw new \RuntimeException('migration_required: hotel tenant scope table is unavailable', 0, $exception);
            }
            $source = $this->executionSourceRecordQuery(
                $sourceTable,
                (string)$payload['source_module'],
                $sourceRecordId
            )->lock(true)->find();
            $hotelTenantId = is_array($hotel) ? (int)($hotel['tenant_id'] ?? 0) : 0;
            $payloadTenantId = (int)($payload['tenant_id'] ?? 0);
            $sourceTenantId = is_array($source) ? (int)($source['tenant_id'] ?? 0) : 0;
            if (!is_array($hotel)
                || !is_array($source)
                || $hotelTenantId <= 0
                || $payloadTenantId <= 0
                || $sourceTenantId <= 0
                || $payloadTenantId !== $hotelTenantId
                || $sourceTenantId !== $hotelTenantId
            ) {
                throw new \InvalidArgumentException(
                    'source-backed execution record is missing or outside the current tenant scope'
                );
            }
            if ((string)$payload['source_module'] === 'price_suggestion') {
                $foreignLifecycle = Db::name('operation_execution_intents')
                    ->whereRaw('LOWER(TRIM(`source_module`)) = ?', ['price_suggestion'])
                    ->where('source_record_id', $sourceRecordId)
                    ->where('hotel_id', $hotelId)
                    ->where('tenant_id', '<>', $hotelTenantId)
                    ->whereNull('deleted_at')
                    ->find();
                if (is_array($foreignLifecycle)) {
                    throw new \InvalidArgumentException(
                        'price suggestion source is already owned by another tenant lifecycle'
                    );
                }
            }

            $authorization = ['hotel' => $hotel, 'source' => $source];
            $this->assertSourceBackedIntentCurrentWithAuthorization($payload, $authorization);
            if ((int)($hotel['tenant_id'] ?? 0) !== (int)($source['tenant_id'] ?? 0)) {
                throw new \InvalidArgumentException('source-backed execution tenant scope changed');
            }
            $payload['tenant_id'] = $hotelTenantId;

            return $creation($payload);
        });
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<int,int|string> $hotelIds
     * @return array<string,mixed>
     */
    private function persistExecutionIntentPayload(
        array $payload,
        array $hotelIds,
        int $createdBy,
        bool $trustedExpansionSource,
        bool $trustedReservedSource,
        ?string $trustedIdempotencyKey
    ): array {
        $trustedIdempotencyKey = $this->normalizeTrustedExecutionIntentIdempotencyKey($trustedIdempotencyKey);
        $idempotencyKey = null;
        if ($trustedExpansionSource && $payload['source_module'] === 'expansion' && $payload['object_type'] === 'expansion') {
            if ($trustedIdempotencyKey !== null) {
                throw new \InvalidArgumentException('expansion execution intent cannot override its idempotency key');
            }
            $idempotencyKey = SourceBackedExecutionIntentIdentityService::key($payload, null);
            $existingIntent = $this->replayTrustedExecutionIntent($idempotencyKey, $payload, $hotelIds);
            if ($existingIntent !== null) {
                return $existingIntent;
            }
            $existingIntent = $this->replayLegacyExpansionExecutionIntent($payload, $hotelIds);
            if ($existingIntent !== null) {
                return $existingIntent;
            }
        } elseif ($trustedReservedSource && $payload['source_module'] === 'price_suggestion') {
            if ($trustedIdempotencyKey !== null) {
                throw new \InvalidArgumentException('price suggestion execution intent cannot override its idempotency key');
            }
            $idempotencyKey = SourceBackedExecutionIntentIdentityService::key($payload, null);
            $existingIntent = $this->replayTrustedExecutionIntent($idempotencyKey, $payload, $hotelIds);
            if ($existingIntent !== null) {
                return $existingIntent;
            }
        } elseif ($trustedReservedSource && $payload['source_module'] === 'knowledge_sop') {
            if ($trustedIdempotencyKey !== null) {
                throw new \InvalidArgumentException('knowledge SOP execution intent cannot override its idempotency key');
            }
            $idempotencyKey = $this->knowledgeSopExecutionIntentIdempotencyKey($payload);
            $existingIntent = $this->replayTrustedExecutionIntent($idempotencyKey, $payload, $hotelIds);
            if ($existingIntent !== null) {
                return $existingIntent;
            }
        } elseif ($trustedReservedSource && SourceBackedExecutionIntentIdentityService::supports($payload)) {
            $idempotencyKey = SourceBackedExecutionIntentIdentityService::key($payload, $trustedIdempotencyKey);
            $existingIntent = $this->replayTrustedExecutionIntent($idempotencyKey, $payload, $hotelIds);
            if ($existingIntent !== null) {
                return $existingIntent;
            }
        } elseif ($trustedIdempotencyKey !== null) {
            $idempotencyKey = $trustedIdempotencyKey;
            $existingIntent = $this->replayTrustedExecutionIntent($idempotencyKey, $payload, $hotelIds);
            if ($existingIntent !== null) {
                return $existingIntent;
            }
        }

        $now = date('Y-m-d H:i:s');
        $insert = [
            'source_module' => $payload['source_module'],
            'source_record_id' => $payload['source_record_id'],
            'hotel_id' => $payload['hotel_id'],
            'platform' => $payload['platform'],
            'object_type' => $payload['object_type'],
            'action_type' => $payload['action_type'],
            'date_start' => $payload['date_start'],
            'date_end' => $payload['date_end'],
            'current_value_json' => json_encode($payload['current_value'], JSON_UNESCAPED_UNICODE),
            'target_value_json' => json_encode($payload['target_value'], JSON_UNESCAPED_UNICODE),
            'evidence_json' => json_encode($payload['evidence'], JSON_UNESCAPED_UNICODE),
            'expected_metric' => $payload['expected_metric'],
            'expected_delta' => $payload['expected_delta'],
            'risk_level' => $payload['risk_level'],
            'blocked_reason' => $payload['blocked_reason'],
            'status' => $payload['status'],
            'created_by' => $createdBy,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if ($idempotencyKey !== null) {
            $insert['idempotency_key'] = $idempotencyKey;
        }

        $managedActionCard = is_array($payload['target_value']['action_card'] ?? null)
            && (string)($payload['target_value']['action_card']['contract_version'] ?? '')
                === \app\service\OperationActionLifecycleService::CARD_CONTRACT_VERSION;
        try {
            $persist = function () use ($insert, $payload, $hotelIds, $createdBy): array {
                $id = (int)Db::name('operation_execution_intents')->insertGetId(
                    $this->withHotelTenantId($insert, 'operation_execution_intents', (int)$payload['hotel_id'])
                );
                $intent = $this->executionIntentDetail($id, $hotelIds);
                (new \app\service\OperationActionLifecycleService())->appendInitialEvents($intent, $createdBy);
                return $this->executionIntentDetail($id, $hotelIds);
            };
            if ($managedActionCard) {
                return Db::transaction($persist);
            }
            $id = (int)Db::name('operation_execution_intents')->insertGetId(
                $this->withHotelTenantId($insert, 'operation_execution_intents', (int)$payload['hotel_id'])
            );
        } catch (\Throwable $exception) {
            if ($idempotencyKey !== null) {
                $existingIntent = $this->replayTrustedExecutionIntent($idempotencyKey, $payload, $hotelIds);
                if ($existingIntent !== null) {
                    return $existingIntent;
                }
            }
            throw $exception;
        }

        return $this->executionIntentDetail($id, $hotelIds);
    }

    /**
     * @param array<int,int|string> $hotelIds
     * @param callable(array{hotel:array<string,mixed>,tasks:array<int,array<string,mixed>>,intent:array<string,mixed>,source:array<string,mixed>}):mixed $approval
     */
    private function withSourceBackedExecutionIntentApprovalAuthorization(
        int $intentId,
        array $hotelIds,
        callable $approval
    ): mixed {
        $this->assertExecutionTenantMutationSchema();
        $hotelIds = array_values(array_unique(array_filter(array_map('intval', $hotelIds))));
        $probe = Db::name('operation_execution_intents')
            ->where('id', $intentId)
            ->whereIn('hotel_id', $hotelIds)
            ->whereNull('deleted_at')
            ->field('id,tenant_id,source_module,source_record_id,hotel_id')
            ->find();
        if (!is_array($probe)) {
            throw new \RuntimeException('execution intent not found');
        }
        $hotelId = (int)($probe['hotel_id'] ?? 0);
        $sourceModule = $this->canonicalExecutionSourceModule($probe['source_module'] ?? '');
        $sourceRecordId = (int)($probe['source_record_id'] ?? 0);
        $sourceTable = $this->executionTaskMutationSourceTable($sourceModule);
        $sourceBacked = $this->sourceBackedExecutionIntentSupports($probe);
        if ($hotelId <= 0 || ($sourceBacked
            && ($sourceRecordId <= 0 || $sourceTable === '' || !$this->tableExists($sourceTable)))) {
            throw new \RuntimeException('source-backed execution intent identity is unavailable');
        }

        return Db::transaction(function () use (
            $intentId,
            $hotelIds,
            $hotelId,
            $sourceModule,
            $sourceRecordId,
            $sourceTable,
            $sourceBacked,
            $approval
        ): mixed {
            try {
                $hotel = Db::name('hotels')->where('id', $hotelId)->lock(true)->find();
            } catch (\Throwable $exception) {
                throw new \RuntimeException('migration_required: hotel tenant scope table is unavailable', 0, $exception);
            }
            if (!is_array($hotel)) {
                throw new \RuntimeException('execution intent hotel scope is unavailable');
            }
            $tasks = Db::name('operation_execution_tasks')
                ->where('intent_id', $intentId)
                ->where('hotel_id', $hotelId)
                ->whereNull('deleted_at')
                ->order('id', 'asc')
                ->lock(true)
                ->select()
                ->toArray();
            $intent = $this->executionIntentRow($intentId, $hotelIds, true);
            if (!is_array($intent)
                || (int)($intent['hotel_id'] ?? 0) !== $hotelId
                || $this->canonicalExecutionSourceModule($intent['source_module'] ?? '') !== $sourceModule
                || (int)($intent['source_record_id'] ?? 0) !== $sourceRecordId
            ) {
                throw new \RuntimeException('source-backed execution intent identity changed');
            }
            $source = $sourceBacked
                ? $this->executionSourceRecordQuery($sourceTable, $sourceModule, $sourceRecordId)->lock(true)->find()
                : null;

            $hotelTenantId = (int)($hotel['tenant_id'] ?? 0);
            $intentTenantId = (int)($intent['tenant_id'] ?? 0);
            $sourceTenantId = is_array($source) ? (int)($source['tenant_id'] ?? 0) : $hotelTenantId;
            if (($sourceBacked && !is_array($source))
                || $hotelTenantId <= 0
                || $intentTenantId !== $hotelTenantId
                || $sourceTenantId !== $hotelTenantId
            ) {
                throw new \InvalidArgumentException('execution intent is outside the current tenant scope');
            }
            foreach ($tasks as $task) {
                if ((int)($task['intent_id'] ?? 0) !== $intentId
                    || (int)($task['hotel_id'] ?? 0) !== $hotelId
                    || (int)($task['tenant_id'] ?? 0) !== $hotelTenantId
                ) {
                    throw new \RuntimeException('execution intent task set is outside the current tenant scope');
                }
            }
            return $approval([
                'hotel' => $hotel,
                'tasks' => $tasks,
                'intent' => $intent,
                'source' => is_array($source) ? $source : null,
            ]);
        });
    }

    private function executionTaskMutationSourceTable(string $sourceModule): string
    {
        return match ($this->canonicalExecutionSourceModule($sourceModule)) {
            'expansion' => 'expansion_records',
            'opening' => 'opening_projects',
            'transfer_decision' => 'transfer_records',
            'feasibility_report' => 'feasibility_reports',
            'strategy_simulation' => 'strategy_simulation_records',
            'quant_simulation' => 'quant_simulation_records',
            'price_suggestion' => 'price_suggestions',
            'operation_alert' => 'operation_alerts',
            default => '',
        };
    }

    private function executionSourceRecordQuery(string $table, string $sourceModule, int $sourceRecordId): mixed
    {
        $query = Db::name($table)->where('id', $sourceRecordId);
        // price_suggestions is an active business table without a soft-delete
        // column; every other governed source uses deleted_at.
        if (!in_array($this->canonicalExecutionSourceModule($sourceModule), ['price_suggestion'], true)) {
            $query->whereNull('deleted_at');
        }
        return $query;
    }

    /** @param array<string,mixed> $intent @param array<string,mixed>|null $authorization */
    private function assertSourceBackedIntentCurrentWithAuthorization(
        array $intent,
        ?array $authorization
    ): void {
        $sourceModule = $this->canonicalExecutionSourceModule($intent['source_module'] ?? '');
        $intent['source_module'] = $sourceModule;
        $lockedSource = is_array($authorization['source'] ?? null) ? $authorization['source'] : null;
        $lockedHotelTenantId = is_array($authorization['hotel'] ?? null)
            ? (int)($authorization['hotel']['tenant_id'] ?? 0)
            : null;
        if (in_array($sourceModule, ['strategy_simulation', 'quant_simulation'], true)) {
            $this->assertSimulationIntentSourceIsCurrent($intent, $lockedSource, $lockedHotelTenantId);
            return;
        }
        $service = new SourceBackedExecutionIntentApprovalService();
        if ($lockedSource !== null && $lockedHotelTenantId !== null) {
            $service->assertCurrentAgainstLockedRows($intent, $lockedSource, $lockedHotelTenantId);
            return;
        }
        $service->assertCurrent($intent);
    }

    /** @param array<int, array<string, mixed>> $rows @return array<int, array<string, mixed>> */
    private function filterCurrentSourceBackedTenantRows(array $rows): array
    {
        if (!$this->tableExists('hotels')) {
            return $rows;
        }
        $hotelIds = array_values(array_unique(array_filter(array_map(
            static fn(array $row): int => (int)($row['hotel_id'] ?? 0),
            $rows
        ))));
        if ($hotelIds === []) {
            return $rows;
        }

        $currentTenantByHotel = [];
        foreach (Db::name('hotels')->whereIn('id', $hotelIds)->field('id,tenant_id')->select()->toArray() as $hotel) {
            $currentTenantByHotel[(int)($hotel['id'] ?? 0)] = (int)($hotel['tenant_id'] ?? 0);
        }

        return array_values(array_filter($rows, static function (array $row) use ($currentTenantByHotel): bool {
            $hotelId = (int)($row['hotel_id'] ?? 0);
            $storedTenantId = (int)($row['tenant_id'] ?? 0);
            $currentTenantId = (int)($currentTenantByHotel[$hotelId] ?? 0);

            return $storedTenantId > 0 && $currentTenantId > 0 && $storedTenantId === $currentTenantId;
        }));
    }

    private function scopeExecutionIntentQueryToCurrentHotelTenant(mixed $query): mixed
    {
        if (!$this->tableExists('hotels')
            || !$this->executionTenantSchemaHasColumn('operation_execution_intents', 'tenant_id')
            || !$this->executionTenantSchemaHasColumn('hotels', 'tenant_id')
        ) {
            return $query->whereRaw('1 = 0');
        }
        $intentTable = Db::name('operation_execution_intents')->getTable();
        $hotelTable = Db::name('hotels')->getTable();

        return $query->whereExists(static function (mixed $hotelQuery) use ($intentTable, $hotelTable): void {
            $hotelQuery->table([$hotelTable => 'execution_tenant_hotel'])
                ->field('execution_tenant_hotel.id')
                ->whereColumn('execution_tenant_hotel.id', $intentTable . '.hotel_id')
                ->whereColumn('execution_tenant_hotel.tenant_id', $intentTable . '.tenant_id')
                ->where('execution_tenant_hotel.tenant_id', '>', 0);
        });
    }

    private function canonicalExecutionSourceModule(mixed $sourceModule): string
    {
        return strtolower(trim((string)$sourceModule));
    }

    /** @param array<string,mixed> $intent */
    private function sourceBackedExecutionIntentSupports(array $intent): bool
    {
        $intent['source_module'] = $this->canonicalExecutionSourceModule($intent['source_module'] ?? '');
        return SourceBackedExecutionIntentIdentityService::supports($intent);
    }

    /** @return array{code:string,message:string}|null */
    private function executionIntentTenantSchemaGap(): ?array
    {
        if (!$this->tableExists('hotels')) {
            return ['code' => 'hotels_missing', 'message' => 'hotel tenant scope table missing'];
        }
        if (!$this->executionTenantSchemaHasColumn('operation_execution_intents', 'tenant_id')) {
            return ['code' => 'operation_execution_intents_tenant_id_missing', 'message' => 'execution intent tenant scope column missing'];
        }
        if (!$this->executionTenantSchemaHasColumn('hotels', 'tenant_id')) {
            return ['code' => 'hotels_tenant_id_missing', 'message' => 'hotel tenant scope column missing'];
        }
        return null;
    }

    /** @return array{code:string,message:string}|null */
    private function executionFlowDependencySchemaGap(): ?array
    {
        foreach ([
            'operation_execution_tasks' => 'execution task',
            'operation_execution_evidence' => 'execution evidence',
        ] as $table => $label) {
            if (!$this->tableExists($table)) {
                return ['code' => $table . '_missing', 'message' => $label . ' table missing'];
            }
            if (!$this->executionTenantSchemaHasColumn($table, 'tenant_id')) {
                return ['code' => $table . '_tenant_id_missing', 'message' => $label . ' tenant scope column missing'];
            }
        }
        return null;
    }

    private function assertExecutionTenantMutationSchema(): void
    {
        $gap = $this->executionIntentTenantSchemaGap();
        if ($gap === null) {
            foreach (['operation_execution_tasks', 'operation_execution_evidence'] as $table) {
                if (!$this->executionTenantSchemaHasColumn($table, 'tenant_id')) {
                    $gap = ['code' => $table . '_tenant_id_missing', 'message' => $table . ' tenant scope column missing'];
                    break;
                }
            }
        }
        if ($gap !== null) {
            throw new \RuntimeException('migration_required: ' . $gap['code'] . ' - ' . $gap['message']);
        }
    }

    private function assertExecutionTenantReadSchema(): void
    {
        $gap = $this->executionIntentTenantSchemaGap();
        if ($gap === null) {
            $gap = $this->executionFlowDependencySchemaGap();
        }
        if ($gap !== null) {
            throw new \RuntimeException('migration_required: ' . $gap['code'] . ' - ' . $gap['message']);
        }
    }

    private function executionTenantSchemaHasColumn(string $table, string $column): bool
    {
        try {
            Db::query(
                'SELECT `' . str_replace('`', '', $column) . '` FROM `'
                . str_replace('`', '', $table) . '` LIMIT 0'
            );
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array{code:string,message:string} $gap */
    private function executionIntentListSchemaGapResponse(array $gap): array
    {
        return [
            'list' => [], 'data_status' => 'migration_required', 'data_gaps' => [$gap],
            'matched_total' => 0, 'returned_count' => 0, 'truncated' => false,
            'statistics' => ['execution_total_loaded' => false],
        ];
    }

    /** @param array{code:string,message:string} $gap */
    private function executionFlowSchemaGapResponse(array $gap): array
    {
        return [
            'summary' => $this->buildExecutionFlowSummary([]),
            'stages' => $this->buildExecutionFlowStages([]),
            'list' => [], 'data_status' => 'migration_required', 'data_gaps' => [$gap],
            'matched_total' => 0, 'returned_count' => 0, 'truncated' => false,
            'statistics' => [
                'execution_total_loaded' => false, 'task_status_loaded' => false,
                'evidence_loaded' => false, 'roi_loaded' => false,
            ],
        ];
    }

    private function emptyExecutionFlowResponse(): array
    {
        $summary = $this->buildExecutionFlowSummary([]);
        return [
            'summary' => $summary, 'stages' => $this->buildExecutionFlowStages($summary),
            'list' => [], 'data_status' => 'ok', 'data_gaps' => [],
            'matched_total' => 0, 'returned_count' => 0, 'truncated' => false,
            'statistics' => [
                'execution_total_loaded' => true, 'task_status_loaded' => true,
                'evidence_loaded' => true, 'roi_loaded' => true,
            ],
        ];
    }

    /** @param array<string, mixed> $row */
    private function sourceBackedIntentTenantIsCurrent(array $row): bool
    {
        $storedTenantId = (int)($row['tenant_id'] ?? 0);
        $currentTenantId = $this->tenantIdForHotel((int)($row['hotel_id'] ?? 0));

        return $storedTenantId > 0 && $currentTenantId > 0 && $storedTenantId === $currentTenantId;
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $payload */
    private function sourceBackedReplayTenantIsCurrent(array $row, array $payload): bool
    {
        $storedTenantId = (int)($row['tenant_id'] ?? 0);
        $requestedTenantId = (int)($payload['tenant_id'] ?? 0);
        $currentTenantId = $this->tenantIdForHotel((int)($payload['hotel_id'] ?? 0));

        return $storedTenantId > 0
            && $requestedTenantId > 0
            && $currentTenantId > 0
            && $storedTenantId === $requestedTenantId
            && $requestedTenantId === $currentTenantId;
    }

    /** @param array<string, mixed> $intent */
    private function assertSimulationIntentSourceIsCurrent(
        array $intent,
        ?array $lockedSource = null,
        ?int $lockedHotelTenantId = null
    ): void
    {
        $sourceModule = strtolower(trim((string)($intent['source_module'] ?? '')));
        $sourceRecordId = (int)($intent['source_record_id'] ?? 0);
        $hotelId = (int)($intent['hotel_id'] ?? 0);
        $table = match ($sourceModule) {
            'strategy_simulation' => 'strategy_simulation_records',
            'quant_simulation' => 'quant_simulation_records',
            default => '',
        };
        if ($table === '' || $sourceRecordId <= 0 || $hotelId <= 0 || !$this->tableExists($table)) {
            throw new \InvalidArgumentException('simulation source identity is no longer valid');
        }

        $row = $lockedSource ?? Db::name($table)
            ->where('id', $sourceRecordId)
            ->whereNull('deleted_at')
            ->lock(true)
            ->find();
        $intentTenantId = (int)($intent['tenant_id'] ?? 0);
        $sourceTenantId = is_array($row) ? (int)($row['tenant_id'] ?? 0) : 0;
        $hotelTenantId = $lockedHotelTenantId ?? $this->tenantIdForHotel($hotelId);
        if (!is_array($row)
            || $intentTenantId <= 0
            || $sourceTenantId <= 0
            || $hotelTenantId <= 0
            || $intentTenantId !== $sourceTenantId
            || $sourceTenantId !== $hotelTenantId
        ) {
            throw new \InvalidArgumentException('simulation source record is missing or outside the hotel tenant scope');
        }

        $record = $sourceModule === 'strategy_simulation'
            ? $this->strategySimulationRecordForExecution($row)
            : $this->quantSimulationRecordForExecution($row);
        $readiness = new SimulationExecutionReadinessService();
        $sourceHotelId = $sourceModule === 'strategy_simulation'
            ? $readiness->strategyExecutionHotelId($record)
            : $readiness->quantExecutionHotelId($record);
        if ($sourceHotelId !== $hotelId) {
            throw new \InvalidArgumentException($sourceModule . ' hotel scope changed; create a new execution intent');
        }
        $dates = [
            'hotel_id' => $hotelId,
            'date_start' => (string)($intent['date_start'] ?? ''),
            'date_end' => (string)($intent['date_end'] ?? ''),
        ];
        $currentInput = $sourceModule === 'strategy_simulation'
            ? $readiness->buildStrategyExecutionIntentInput($record, $dates)
            : $readiness->buildQuantExecutionIntentInput($record, $dates);
        $storedEvidence = is_array($intent['evidence'] ?? null) ? $intent['evidence'] : [];
        $currentEvidence = is_array($currentInput['evidence'] ?? null) ? $currentInput['evidence'] : [];
        $storedPayloadDigest = strtolower(trim((string)($storedEvidence['simulation_payload_digest'] ?? '')));
        $storedSourceDigest = strtolower(trim((string)($storedEvidence['source_record_digest'] ?? '')));
        $currentPayloadDigest = strtolower(trim((string)($currentEvidence['simulation_payload_digest'] ?? '')));
        $currentSourceDigest = strtolower(trim((string)($currentEvidence['source_record_digest'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/D', $storedPayloadDigest) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $storedSourceDigest) !== 1
            || !hash_equals($storedSourceDigest, $currentSourceDigest)
            || !hash_equals($storedPayloadDigest, $currentPayloadDigest)
            || !hash_equals($storedPayloadDigest, $readiness->simulationPayloadDigest($intent))
            || !in_array((string)($currentEvidence['readiness_stage'] ?? ''), ['review_ready', 'approved_pending_execution', 'execution_ready'], true)
            || !empty($currentEvidence['data_gaps'])
        ) {
            throw new \InvalidArgumentException('simulation source or readiness changed; create a new execution intent');
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function strategySimulationRecordForExecution(array $row): array
    {
        $scores = $this->decodeJson((string)($row['score_json'] ?? ''));

        return [
            'id' => (int)($row['id'] ?? 0),
            'record_id' => (int)($row['id'] ?? 0),
            'project_name' => (string)($row['project_name'] ?? ''),
            'total_score' => (int)($scores['total_score'] ?? 0),
            'input' => $this->decodeJson((string)($row['input_json'] ?? '')),
            'scores' => is_array($scores['items'] ?? null) ? $scores['items'] : $scores,
            'recommendation' => $this->decodeJson((string)($row['recommendation_json'] ?? '')),
            'risk' => $this->decodeJson((string)($row['risk_json'] ?? '')),
            'data_snapshot' => $this->decodeJson((string)($row['data_snapshot_json'] ?? '')),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function quantSimulationRecordForExecution(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'record_id' => (int)($row['id'] ?? 0),
            'project_name' => (string)($row['project_name'] ?? ''),
            'monthly_net_cashflow' => (float)($row['monthly_net_cashflow'] ?? 0),
            'payback_months' => $row['payback_months'] ?? null,
            'risk_level' => (string)($row['risk_level'] ?? ''),
            'input' => $this->decodeJson((string)($row['input_json'] ?? '')),
            'result' => $this->decodeJson((string)($row['result_json'] ?? '')),
            'scenarios' => $this->decodeJson((string)($row['scenarios_json'] ?? '')),
            'risk_hints' => $this->decodeJson((string)($row['risk_hints_json'] ?? '')),
        ];
    }
}
