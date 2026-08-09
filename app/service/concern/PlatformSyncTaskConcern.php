<?php
declare(strict_types=1);

namespace app\service\concern;

use app\contract\DataSourceAdapter;
use app\service\CloudOtaBundleCodec;
use app\service\CollectionResultContractService;
use app\service\CtripCollectorWorkflowService;
use app\service\OtaOperatingScope;
use app\service\OtaOrderedCollectionPlanner;
use app\service\OtaStructuredCaptureEvidenceService;
use app\service\OtaTrafficAttributionService;
use app\service\platform\ApiDataSourceAdapter;
use app\service\platform\CtripBrowserProfileDataSourceAdapter;
use app\service\platform\LocalCollectorDataSourceAdapter;
use app\service\platform\ManualImportDataSourceAdapter;
use app\service\platform\MeituanBrowserProfileDataSourceAdapter;
use RuntimeException;
use think\facade\Cache;
use think\facade\Db;

trait PlatformSyncTaskConcern
{
    private function refreshDatabaseConnectionAfterExternalFetch(): void
    {
        try {
            Db::connect()->close();
            Db::connect(null, true);
        } catch (\Throwable) {
            // Let the next write expose any real database failure.
        }
    }

    /**
     * @return array<string, int>
     */
    private function emptySyncTiming(): array
    {
        return [
            'capture_elapsed_ms' => 0,
            'raw_store_elapsed_ms' => 0,
            'normalize_elapsed_ms' => 0,
            'daily_rows_save_elapsed_ms' => 0,
            'finish_task_elapsed_ms' => 0,
            'total_elapsed_ms' => 0,
        ];
    }

    private function elapsedMilliseconds(float $startedAt): int
    {
        return max(0, (int)round((microtime(true) - $startedAt) * 1000));
    }

    /**
     * @param array<string, mixed> $timing
     * @return array<string, int>
     */
    private function normalizeSyncTiming(array $timing): array
    {
        $normalized = $this->emptySyncTiming();
        foreach ($normalized as $key => $_) {
            $normalized[$key] = max(0, (int)($timing[$key] ?? 0));
        }
        return $normalized;
    }

    private function createTask(array $source, $user, string $triggerType, array $options = []): int
    {
        $acquisition = $this->acquireSyncTask($source, $user, $triggerType, $options);
        return (int)$acquisition['task_id'];
    }

    /**
     * @return array{task_id:int,created:bool,reused_active_task:bool,task:array<string,mixed>}
     */
    private function acquireSyncTask(
        array $source,
        $user,
        string $triggerType,
        array $options = []
    ): array {
        return Db::transaction(function () use ($source, $user, $triggerType, $options): array {
            $now = date('Y-m-d H:i:s');
            $lockedSourceQuery = Db::name('platform_data_sources')
                ->field('id,tenant_id,system_hotel_id');
            $this->applyStoredSourceIdentity($lockedSourceQuery, $source);
            $lockedSource = $lockedSourceQuery->lock(true)->find();
            if (!is_array($lockedSource)) {
                throw new RuntimeException('Data source not found.', 404);
            }
            [$tenantId, $hotelId] = $this->assertStoredSourceTenant($lockedSource);
            $predecessorQuery = Db::name('platform_data_sync_tasks')
                ->whereIn('status', self::ACTIVE_SYNC_TASK_STATUSES)
                ->order('id', 'desc')
                ->lock(true);
            $this->applyTaskSourceIdentity($predecessorQuery, $source, $tenantId, $hotelId);
            $predecessor = $predecessorQuery->find();
            $predecessorId = 0;
            $attemptCount = 1;
            $recoveryContextStatus = '';

            if (is_array($predecessor)) {
                $predecessorId = (int)($predecessor['id'] ?? 0);
                $predecessorStats = $this->decodeConfig($predecessor['stats_json'] ?? []);
                $hasRecoveryContext = $this->syncTaskHasRecoveryContext($predecessorStats);
                if (!self::isStaleRunningSyncTask($predecessor)) {
                    $requestedPlan = $this->orderedCollectionTaskPlanFromOptions(
                        $options,
                        $source
                    );
                    if ($this->sameOrderedCollectionTaskScope(
                        $requestedPlan,
                        is_array($predecessorStats['ordered_collection'] ?? null)
                            ? $predecessorStats['ordered_collection']
                            : []
                    )) {
                        return [
                            'task_id' => $predecessorId,
                            'created' => false,
                            'reused_active_task' => true,
                            'task' => $predecessor,
                        ];
                    }
                    $message = 'data source sync task is already active';
                    if (!$hasRecoveryContext) {
                        $message .= '; recovery_context missing (checkpoint unavailable)';
                    }
                    throw new RuntimeException($message, 409);
                }

                $recoveryContextStatus = $hasRecoveryContext
                    ? 'recovery_context_available'
                    : 'missing recovery_context/checkpoint';
                $predecessorStats['recovery_context_status'] = $recoveryContextStatus;
                $predecessorStats['interrupted_at'] = $now;
                $predecessorUpdate = Db::name('platform_data_sync_tasks')
                    ->where('id', $predecessorId)
                    ->whereIn('status', self::ACTIVE_SYNC_TASK_STATUSES);
                $this->applyTaskSourceIdentity($predecessorUpdate, $source, $tenantId, $hotelId);
                $affected = (int)$predecessorUpdate->update([
                        'status' => 'failed',
                        'finished_at' => $now,
                        'message' => 'stale active sync interrupted before recovered retry',
                        'stats_json' => json_encode($predecessorStats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'update_time' => $now,
                    ]);
                if ($affected !== 1) {
                    throw new RuntimeException('stale sync predecessor could not be made terminal before retry', 409);
                }
                $attemptCount = max(1, (int)($predecessor['attempt_count'] ?? 1)) + 1;
            }

            $taskStats = $this->syncTaskFlowStatsFromOptions($options);
            if ($predecessorId > 0) {
                $taskStats = array_merge($taskStats, [
                    'predecessor_task_id' => $predecessorId,
                    'recovery_context_status' => $recoveryContextStatus,
                ]);
            }
            $orderedCollection = $this->orderedCollectionTaskPlanFromOptions(
                $options,
                $source
            );
            if ($orderedCollection !== []) {
                $taskStats['ordered_collection'] = $orderedCollection;
            }
            $data = [
                'data_source_id' => (int)$source['id'],
                'system_hotel_id' => $hotelId,
                'platform' => (string)$source['platform'],
                'data_type' => (string)$source['data_type'],
                'ingestion_method' => (string)$source['ingestion_method'],
                'trigger_type' => $triggerType,
                'status' => 'running',
                'attempt_count' => $attemptCount,
                'max_attempts' => max(3, $attemptCount),
                'started_at' => $now,
                'requested_by' => (int)($user->id ?? 0) ?: null,
                'stats_json' => $taskStats === []
                    ? null
                    : json_encode($taskStats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'create_time' => $now,
                'update_time' => $now,
            ];
            if (isset($this->tableColumns('platform_data_sync_tasks')['tenant_id'])) {
                $data['tenant_id'] = $tenantId;
            }

            $taskId = (int)Db::name('platform_data_sync_tasks')->insertGetId($data);
            return [
                'task_id' => $taskId,
                'created' => true,
                'reused_active_task' => false,
                'task' => [],
            ];
        });
    }

    /**
     * Keeps the bounded Ctrip collection flow auditable without persisting
     * browser credentials, request payloads or raw responses in task metadata.
     *
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private function syncTaskFlowStatsFromOptions(array $options): array
    {
        $stats = [];
        $flow = (new CtripCollectorWorkflowService())->normalizeFlow(
            $options['collector_flow']
                ?? $options['collectorFlow']
                ?? ''
        );
        if ($flow !== '') {
            $stats['collector_flow'] = $flow;
        }

        $capturePlan = strtolower(trim((string)(
            $options['capture_plan']
                ?? $options['capturePlan']
                ?? $options['ctrip_capture_plan']
                ?? $options['ctripCapturePlan']
                ?? ''
        )));
        if (in_array($capturePlan, [
            'full',
            'historical_review',
            'realtime_broadcast',
            'intraday_trend',
            'future_demand',
        ], true)) {
            $stats['capture_plan'] = $capturePlan;
        }

        $dataDate = $this->normalizeDate(
            $options['data_date']
                ?? $options['dataDate']
                ?? null
        );
        if ($dataDate !== null) {
            $stats['data_date'] = $dataDate;
        }

        return $stats;
    }

    /**
     * Persist only the bounded, non-secret orchestration contract so running,
     * failed and completed tasks all explain their hotel/date/field scope.
     *
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function sanitizeOrderedCollectionTaskPlan(mixed $value, array $source): array
    {
        if (!is_array($value)) {
            return [];
        }
        $platform = strtolower(trim((string)($value['platform'] ?? $source['platform'] ?? '')));
        $targetDate = $this->normalizeDate($value['target_date'] ?? null) ?? '';
        if (!in_array($platform, ['ctrip', 'meituan'], true) || $targetDate === '') {
            return [];
        }
        $safeToken = static function (mixed $token): string {
            $token = strtolower(trim((string)$token));
            return preg_match('/^[a-z0-9._:-]{1,80}$/D', $token) === 1 ? $token : '';
        };
        $safeList = static function (mixed $items) use ($safeToken): array {
            if (!is_array($items)) {
                return [];
            }
            return array_values(array_unique(array_filter(array_map($safeToken, $items))));
        };
        $reason = $safeToken($value['reason'] ?? '');
        return [
            'contract_version' => $safeToken($value['contract_version'] ?? ''),
            'mode' => $safeToken($value['mode'] ?? ''),
            'scope' => $safeToken($value['scope'] ?? ''),
            'platform' => $platform,
            'system_hotel_id' => max(0, (int)($source['system_hotel_id'] ?? 0)),
            'data_source_id' => max(0, (int)($source['id'] ?? 0)),
            'target_date' => $targetDate,
            'stage' => $safeToken($value['stage'] ?? ''),
            'reason' => $reason,
            'sections' => $safeList($value['sections'] ?? []),
            'interface_ids' => $safeList($value['interface_ids'] ?? []),
            'required_field_keys' => $safeList($value['required_field_keys'] ?? []),
            'captured_field_keys' => $safeList($value['captured_field_keys'] ?? []),
            'missing_field_keys' => $safeList($value['missing_field_keys'] ?? []),
            'excluded_example_capabilities' => $safeList($value['excluded_example_capabilities'] ?? []),
            'source_recovery_required' => ($value['source_recovery_required'] ?? false) === true,
            'eligible_row_count' => max(0, (int)($value['eligible_row_count'] ?? 0)),
        ];
    }

    /**
     * Ordered callers provide the full plan. Older direct Profile callers still
     * get a minimal exact-date scope so a repeated request can reuse the active
     * task instead of launching a second browser process.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function orderedCollectionTaskPlanFromOptions(array $options, array $source): array
    {
        $plan = $this->sanitizeOrderedCollectionTaskPlan(
            $options['ordered_collection'] ?? null,
            $source
        );
        if ($plan !== [] || !$this->isOtaBrowserProfileSource($source)) {
            return $plan;
        }
        $targetDate = $this->normalizeDate(
            $options['data_date']
            ?? $options['target_date']
            ?? null
        ) ?? '';
        if ($targetDate === '') {
            return [];
        }
        return $this->sanitizeOrderedCollectionTaskPlan([
            'contract_version' => OtaOrderedCollectionPlanner::CONTRACT_VERSION,
            'mode' => 'direct_profile_date_collection',
            'scope' => 'ota_date_collection',
            'platform' => (string)($source['platform'] ?? ''),
            'target_date' => $targetDate,
            'stage' => 'requested_date',
            'reason' => 'direct_profile_date_request',
            'sections' => [],
            'interface_ids' => [],
            'required_field_keys' => [],
            'missing_field_keys' => [],
            'excluded_example_capabilities' => [],
        ], $source);
    }

    /**
     * Same Profile source is already tenant/hotel/platform scoped by the
     * locked query. Exact target date completes the active-task reuse scope.
     *
     * @param array<string, mixed> $requested
     * @param array<string, mixed> $active
     */
    private function sameOrderedCollectionTaskScope(array $requested, array $active): bool
    {
        if ($requested === [] || $active === []) {
            return false;
        }
        foreach ([
            'platform',
            'system_hotel_id',
            'data_source_id',
            'target_date',
        ] as $key) {
            if ((string)($requested[$key] ?? '') !== (string)($active[$key] ?? '')) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $stats */
    private function syncTaskHasRecoveryContext(array $stats): bool
    {
        foreach (['recovery_context', 'checkpoint'] as $key) {
            $value = $stats[$key] ?? null;
            if ((is_array($value) && $value !== []) || (is_string($value) && trim($value) !== '')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Return the already-running exact-scope task without invoking the browser
     * adapter a second time.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private function reusedActiveSyncTaskResult(array $source, array $task): array
    {
        $taskId = max(0, (int)($task['id'] ?? 0));
        $taskStatus = strtolower(trim((string)($task['status'] ?? 'running')));
        if (!in_array($taskStatus, self::ACTIVE_SYNC_TASK_STATUSES, true)) {
            $taskStatus = 'running';
        }
        $stats = $this->sanitizeSyncTaskStats(
            $this->decodeConfig($task['stats_json'] ?? []),
            $taskStatus
        );
        $result = [
            'task_id' => $taskId,
            'data_source_id' => max(0, (int)($source['id'] ?? 0)),
            'status' => 'in_progress',
            'task_status' => $taskStatus,
            'message' => 'data_source_sync_task_reused_in_progress',
            'reused_active_task' => true,
            'normalized_count' => (int)($stats['normalized_count'] ?? 0),
            'saved_count' => (int)($stats['saved_count'] ?? 0),
            'inserted_count' => (int)($stats['inserted_count'] ?? 0),
            'updated_count' => (int)($stats['updated_count'] ?? 0),
            'readback_count' => (int)($stats['readback_count'] ?? 0),
            'readback_verified' => ($stats['readback_verified'] ?? false) === true,
            'run_readback' => is_array($stats['run_readback'] ?? null)
                ? $stats['run_readback']
                : [],
            'ordered_collection' => is_array($stats['ordered_collection'] ?? null)
                ? $stats['ordered_collection']
                : null,
            'timing' => is_array($stats['timing'] ?? null)
                ? $stats['timing']
                : $this->emptySyncTiming(),
            'read_fallback_summary' => is_array($stats['read_fallback_summary'] ?? null)
                ? $stats['read_fallback_summary']
                : null,
            'next_retry_at' => null,
        ];
        $collectionResult = $this->otaCollectionResult($source, $result);
        if ($collectionResult !== null) {
            $result['collection_result'] = $collectionResult;
        }
        return $result;
    }

    private function finishTask(int $taskId, array $source, string $status, string $message, int $normalizedCount, int $savedCount, array $payload, array $timing = [], ?float $syncStartedAt = null): array
    {
        [$tenantId, $hotelId] = $this->assertStoredSourceTenant($source);
        $taskQuery = Db::name('platform_data_sync_tasks')->where('id', $taskId);
        $this->applyTaskSourceIdentity($taskQuery, $source, $tenantId, $hotelId);
        $task = $taskQuery->find();
        if (!is_array($task)) {
            throw new RuntimeException('Sync task identity does not match the source scope.', 409);
        }

        try {
            return $this->finishTaskWithinValidatedScope(
                $taskId,
                $source,
                $status,
                $message,
                $normalizedCount,
                $savedCount,
                $payload,
                $timing,
                $syncStartedAt,
                $tenantId,
                $hotelId,
                $task
            );
        } catch (\Throwable $exception) {
            return $this->failSyncTaskFinalization(
                $taskId,
                $source,
                $tenantId,
                $hotelId,
                $exception,
                $normalizedCount,
                $savedCount,
                $payload
            );
        }
    }

    /**
     * Complete the rich task receipt only after the task/source scope has been
     * verified. The wrapper above keeps an auxiliary receipt failure from
     * leaving the exact task permanently active.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $timing
     * @param array<string, mixed> $existingTask
     * @return array<string, mixed>
     */
    private function finishTaskWithinValidatedScope(
        int $taskId,
        array $source,
        string $status,
        string $message,
        int $normalizedCount,
        int $savedCount,
        array $payload,
        array $timing,
        ?float $syncStartedAt,
        int $tenantId,
        int $hotelId,
        array $existingTask
    ): array
    {
        $finishStartedAt = microtime(true);
        $now = date('Y-m-d H:i:s');
        $timing = $this->normalizeSyncTiming($timing);
        $safeMessage = $this->safeSyncTaskMessage($status, $message);
        $safeDiagnostics = $this->sanitizeSyncDiagnosticsForResponse(
            is_array($payload['sync_diagnostics'] ?? null) ? $payload['sync_diagnostics'] : [],
            $status
        );
        $existingTaskStats = $this->decodeConfig($existingTask['stats_json'] ?? []);
        $stats = [
            'normalized_count' => $normalizedCount,
            'saved_count' => $savedCount,
            'payload_keys' => array_slice(array_keys($payload), 0, 30),
        ];
        foreach ([
            'predecessor_task_id',
            'recovery_context_status',
            'ordered_collection',
            'collector_flow',
            'capture_plan',
            'data_date',
        ] as $recoveryKey) {
            if (array_key_exists($recoveryKey, $existingTaskStats)) {
                $stats[$recoveryKey] = $existingTaskStats[$recoveryKey];
            }
        }
        $saveReceipt = is_array($payload['_save_receipt'] ?? null) ? $payload['_save_receipt'] : [];
        foreach (['attempted_count', 'inserted_count', 'updated_count', 'deduplicated_count', 'readback_count', 'readback_verified', 'rolled_back', 'failure_reason', 'mismatch_field'] as $receiptKey) {
            if (array_key_exists($receiptKey, $saveReceipt)) {
                $stats[$receiptKey] = $saveReceipt[$receiptKey];
            }
        }
        $stats['run_readback'] = $this->buildRunReadbackReceipt(
            $taskId,
            $source,
            $saveReceipt,
            $payload,
            $existingTask
        );
        if ($safeDiagnostics !== []) {
            $stats['sync_diagnostics'] = $safeDiagnostics;
        }
        $readFallbackSummary = $this->otaReadFallbackSummaryFromPayload($payload);
        if ($readFallbackSummary !== []) {
            $stats['read_fallback_summary'] = $readFallbackSummary;
        }
        $stats['collection_quality'] = $this->buildSyncTaskCollectionQualitySnapshot(
            $status,
            $source,
            $safeDiagnostics,
            $normalizedCount,
            $savedCount,
            $now
        );
        foreach (['data_period', 'snapshot_time', 'snapshot_bucket'] as $periodKey) {
            if (!empty($payload[$periodKey])) {
                $stats[$periodKey] = (string)$payload[$periodKey];
            }
        }
        $stats = $this->sanitizeSyncTaskStats($stats, $status);
        $nextRetryAt = in_array($status, ['failed', 'partial_success'], true) ? date('Y-m-d H:i:s', time() + 900) : null;

        $finalizeQuery = Db::name('platform_data_sync_tasks')
            ->where('id', $taskId)
            ->where('status', 'running');
        $this->applyTaskSourceIdentity($finalizeQuery, $source, $tenantId, $hotelId);
        $finalized = (int)$finalizeQuery->update([
            'status' => $status,
            'finished_at' => $now,
            'next_retry_at' => $nextRetryAt,
            'message' => $safeMessage,
            'stats_json' => json_encode($stats, JSON_UNESCAPED_UNICODE),
            'update_time' => $now,
        ]);
        if ($finalized !== 1) {
            $persistedTaskQuery = Db::name('platform_data_sync_tasks')->where('id', $taskId);
            $this->applyTaskSourceIdentity($persistedTaskQuery, $source, $tenantId, $hotelId);
            $persistedTask = $persistedTaskQuery->find();
            return $this->persistedSyncTaskResult($taskId, $source, is_array($persistedTask) ? $persistedTask : []);
        }
        if ($this->shouldPreserveSourceStateForModuleResult($status, $payload)) {
            $this->persistOptionalModuleState($source, $payload, $now);
        } else {
            $sourceUpdateQuery = Db::name('platform_data_sources');
            $this->applyStoredSourceIdentity($sourceUpdateQuery, $source);
            $sourceUpdateQuery->update([
                'last_sync_time' => $now,
                'last_sync_status' => $status,
                'last_error' => in_array($status, ['success'], true) ? null : $safeMessage,
                'status' => $status === 'success' ? 'success' : $status,
                'update_time' => $now,
            ]);
        }
        $timing['finish_task_elapsed_ms'] = $this->elapsedMilliseconds($finishStartedAt);
        if ($syncStartedAt !== null) {
            $timing['total_elapsed_ms'] = $this->elapsedMilliseconds($syncStartedAt);
        }
        $stats = $this->sanitizeSyncTaskStats(array_merge($stats, $timing, ['timing' => $timing]), $status);
        $statsUpdateQuery = Db::name('platform_data_sync_tasks')
            ->where('id', $taskId)
            ->where('status', $status);
        $this->applyTaskSourceIdentity($statsUpdateQuery, $source, $tenantId, $hotelId);
        $statsUpdateQuery->update([
                'stats_json' => json_encode($stats, JSON_UNESCAPED_UNICODE),
                'update_time' => date('Y-m-d H:i:s'),
            ]);
        $this->logSync($taskId, $source, $status === 'success' ? 'info' : 'warning', 'sync_finished', $safeMessage, $stats);

        $result = [
            'task_id' => $taskId,
            'data_source_id' => (int)$source['id'],
            'status' => $status,
            'message' => $safeMessage,
            'normalized_count' => $normalizedCount,
            'saved_count' => $savedCount,
            'inserted_count' => (int)($stats['inserted_count'] ?? 0),
            'updated_count' => (int)($stats['updated_count'] ?? 0),
            'readback_count' => (int)($stats['readback_count'] ?? 0),
            'readback_verified' => ($stats['readback_verified'] ?? false) === true,
            'run_readback' => is_array($stats['run_readback'] ?? null) ? $stats['run_readback'] : [],
            'rolled_back' => ($stats['rolled_back'] ?? false) === true,
            'failure_reason' => (string)($stats['failure_reason'] ?? ''),
            'predecessor_task_id' => (int)($stats['predecessor_task_id'] ?? 0),
            'recovery_context_status' => (string)($stats['recovery_context_status'] ?? ''),
            'next_retry_at' => $nextRetryAt,
            'timing' => $timing,
            'sync_diagnostics' => $safeDiagnostics !== [] ? $safeDiagnostics : null,
            'collection_quality' => $stats['collection_quality'],
            'read_fallback_summary' => is_array($stats['read_fallback_summary'] ?? null)
                ? $stats['read_fallback_summary']
                : null,
            'module_status' => is_array($payload['module_status'] ?? null) ? $payload['module_status'] : null,
        ];
        $collectionResult = $this->otaCollectionResult($source, $result);
        if ($collectionResult !== null) {
            $result['collection_result'] = $collectionResult;
        }
        return $result;
    }

    /**
     * Last-resort terminalization for failures inside finishTask itself. Keep
     * this path deliberately independent from receipt, diagnostics, logging,
     * and timestamp-normalization helpers: one of those helpers is what failed.
     *
     * Identity validation is repeated and deliberately allowed to throw. A
     * tenant/hotel/source mismatch must never be converted into a task update.
     *
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function failSyncTaskFinalization(
        int $taskId,
        array $source,
        int $tenantId,
        int $hotelId,
        \Throwable $exception,
        int $normalizedCount,
        int $savedCount,
        array $payload
    ): array {
        [$currentTenantId, $currentHotelId] = $this->assertStoredSourceTenant($source);
        $sourceId = (int)($source['id'] ?? 0);
        if ($sourceId <= 0
            || $currentTenantId !== $tenantId
            || $currentHotelId !== $hotelId
        ) {
            throw new RuntimeException(
                'Sync task identity changed during finalization.',
                409,
                $exception
            );
        }

        $now = date('Y-m-d H:i:s');
        $nextRetryAt = date('Y-m-d H:i:s', time() + 900);
        $saveReceipt = is_array($payload['_save_receipt'] ?? null)
            ? $payload['_save_receipt']
            : [];
        $receiptCount = static function (array $receipt, string $key): ?int {
            return array_key_exists($key, $receipt) && is_numeric($receipt[$key])
                ? max(0, (int)$receipt[$key])
                : null;
        };
        $attemptedCount = $receiptCount($saveReceipt, 'attempted_count');
        $insertedCount = $receiptCount($saveReceipt, 'inserted_count');
        $updatedCount = $receiptCount($saveReceipt, 'updated_count');
        $deduplicatedCount = $receiptCount($saveReceipt, 'deduplicated_count');
        $readbackCount = $receiptCount($saveReceipt, 'readback_count');
        $readbackKnown = array_key_exists('readback_verified', $saveReceipt)
            && is_bool($saveReceipt['readback_verified']);
        $readbackVerified = $readbackKnown && $saveReceipt['readback_verified'] === true;
        $rolledBackKnown = array_key_exists('rolled_back', $saveReceipt)
            && is_bool($saveReceipt['rolled_back']);
        $rolledBack = $rolledBackKnown && $saveReceipt['rolled_back'] === true;
        $rowIds = [];
        foreach (is_array($saveReceipt['row_ids'] ?? null) ? $saveReceipt['row_ids'] : [] as $rowId) {
            if ((is_int($rowId) || (is_string($rowId) && ctype_digit($rowId))) && (int)$rowId > 0) {
                $rowIds[] = (int)$rowId;
            }
        }
        $rowIds = array_values(array_unique($rowIds));
        sort($rowIds, SORT_NUMERIC);
        $payloadKeys = array_values(array_filter(array_map(
            static fn($key): string => is_int($key) || is_string($key) ? (string)$key : '',
            array_slice(array_keys($payload), 0, 30)
        ), static fn(string $key): bool => $key !== ''));
        $persistenceFactStatus = $saveReceipt !== []
            ? 'preserved_from_save_receipt'
            : (($normalizedCount > 0 || $savedCount > 0) ? 'known_counts_without_save_receipt' : 'unknown');
        $minimalStats = [
            'normalized_count' => max(0, $normalizedCount),
            'saved_count' => max(0, $savedCount),
            'attempted_count' => $attemptedCount,
            'inserted_count' => $insertedCount,
            'updated_count' => $updatedCount,
            'deduplicated_count' => $deduplicatedCount,
            'readback_count' => $readbackCount,
            'readback_verified' => $readbackVerified,
            'readback_status' => $readbackKnown
                ? ($readbackVerified ? 'verified' : 'unverified')
                : 'unknown',
            'rolled_back' => $rolledBack,
            'rolled_back_status' => $rolledBackKnown ? 'known' : 'unknown',
            'row_ids' => $rowIds,
            'row_ids_status' => $rowIds !== [] ? 'known' : 'unknown',
            'persistence_fact_status' => $persistenceFactStatus,
            'saved_rows_may_exist' => $savedCount > 0
                || ($insertedCount ?? 0) > 0
                || ($updatedCount ?? 0) > 0,
            'run_readback_status' => 'unavailable_due_to_finalization_failure',
            'failure_reason' => 'sync_task_finalization_failed',
            'save_failure_reason' => $this->failSafeFinalizationCode($saveReceipt['failure_reason'] ?? null),
            'mismatch_field' => $this->failSafeFinalizationCode($saveReceipt['mismatch_field'] ?? null),
            'payload_keys' => $payloadKeys,
            'fact_status' => [
                'normalized_count' => 'known',
                'saved_count' => 'known',
                'attempted_count' => $attemptedCount === null ? 'unknown' : 'known',
                'inserted_count' => $insertedCount === null ? 'unknown' : 'known',
                'updated_count' => $updatedCount === null ? 'unknown' : 'known',
                'deduplicated_count' => $deduplicatedCount === null ? 'unknown' : 'known',
                'readback_count' => $readbackCount === null ? 'unknown' : 'known',
                'readback_verified' => $readbackKnown
                    ? ($readbackVerified ? 'verified' : 'unverified')
                    : 'unknown',
            ],
        ];
        $minimalStatsJson = json_encode(
            $minimalStats,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if (!is_string($minimalStatsJson)) {
            $minimalStatsJson = '{"normalized_count":0,"saved_count":0,"readback_count":null,"readback_verified":false,"readback_status":"unknown","persistence_fact_status":"unknown","failure_reason":"sync_task_finalization_failed"}';
        }

        $finalized = (int)Db::name('platform_data_sync_tasks')
            ->where('id', $taskId)
            ->where('data_source_id', $sourceId)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('status', 'running')
            ->update([
                'status' => 'failed',
                'finished_at' => $now,
                'next_retry_at' => $nextRetryAt,
                'message' => 'collection_failed',
                'stats_json' => $minimalStatsJson,
                'update_time' => $now,
            ]);

        $persistedTask = Db::name('platform_data_sync_tasks')
            ->field('id,status,finished_at,next_retry_at,message,stats_json,update_time')
            ->where('id', $taskId)
            ->where('data_source_id', $sourceId)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->find();
        if (!is_array($persistedTask)) {
            throw new RuntimeException(
                'Sync task identity does not match the source scope.',
                409,
                $exception
            );
        }

        $persistedStatus = strtolower(trim((string)($persistedTask['status'] ?? '')));
        if ($finalized !== 1 && in_array($persistedStatus, self::ACTIVE_SYNC_TASK_STATUSES, true)) {
            throw new RuntimeException(
                'Sync task fail-safe terminalization did not apply.',
                409,
                $exception
            );
        }
        if ($persistedStatus === '') {
            $persistedStatus = 'failed';
        }
        if ($finalized !== 1) {
            return $this->postFinalizeWarningResult(
                $taskId,
                $sourceId,
                $persistedTask,
                $persistedStatus
            );
        }

        $persistedStats = $this->decodeFailSafeFinalizationStats($persistedTask['stats_json'] ?? null);
        $persistedReadbackCount = isset($persistedStats['readback_count'])
            && is_numeric($persistedStats['readback_count'])
            ? max(0, (int)$persistedStats['readback_count'])
            : 0;

        return [
            'task_id' => $taskId,
            'data_source_id' => $sourceId,
            'status' => $persistedStatus,
            'message' => 'collection_failed',
            'normalized_count' => max(0, (int)($persistedStats['normalized_count'] ?? 0)),
            'saved_count' => max(0, (int)($persistedStats['saved_count'] ?? 0)),
            'inserted_count' => isset($persistedStats['inserted_count']) && is_numeric($persistedStats['inserted_count'])
                ? max(0, (int)$persistedStats['inserted_count'])
                : 0,
            'updated_count' => isset($persistedStats['updated_count']) && is_numeric($persistedStats['updated_count'])
                ? max(0, (int)$persistedStats['updated_count'])
                : 0,
            'readback_count' => $persistedReadbackCount,
            'readback_verified' => ($persistedStats['readback_verified'] ?? false) === true,
            'run_readback' => [],
            'rolled_back' => ($persistedStats['rolled_back'] ?? false) === true,
            'failure_reason' => 'sync_task_finalization_failed',
            'predecessor_task_id' => 0,
            'recovery_context_status' => '',
            'next_retry_at' => trim((string)($persistedTask['next_retry_at'] ?? '')) ?: null,
            'timing' => [],
            'sync_diagnostics' => null,
            'collection_quality' => [],
            'read_fallback_summary' => null,
            'module_status' => null,
            'persistence_fact_status' => (string)($persistedStats['persistence_fact_status'] ?? 'unknown'),
            'fact_status' => is_array($persistedStats['fact_status'] ?? null)
                ? $persistedStats['fact_status']
                : [],
            'saved_rows_may_exist' => ($persistedStats['saved_rows_may_exist'] ?? false) === true,
            'finalization_status' => 'failed_before_task_terminalization',
            'post_finalize_warning' => false,
            'post_finalize_warning_code' => '',
        ];
    }

    /** @return array<string,mixed> */
    private function postFinalizeWarningResult(
        int $taskId,
        int $sourceId,
        array $persistedTask,
        string $persistedStatus
    ): array {
        $stats = $this->decodeFailSafeFinalizationStats($persistedTask['stats_json'] ?? null);
        $countFact = static function (array $values, string $key): array {
            if (!array_key_exists($key, $values) || !is_numeric($values[$key])) {
                return [0, 'unknown'];
            }
            return [max(0, (int)$values[$key]), 'known'];
        };
        [$normalizedCount, $normalizedStatus] = $countFact($stats, 'normalized_count');
        [$savedCount, $savedStatus] = $countFact($stats, 'saved_count');
        [$insertedCount, $insertedStatus] = $countFact($stats, 'inserted_count');
        [$updatedCount, $updatedStatus] = $countFact($stats, 'updated_count');
        [$readbackCount, $readbackCountStatus] = $countFact($stats, 'readback_count');
        $readbackKnown = array_key_exists('readback_verified', $stats)
            && is_bool($stats['readback_verified']);
        $readbackVerified = $readbackKnown && $stats['readback_verified'] === true;
        $nextRetryAt = is_string($persistedTask['next_retry_at'] ?? null)
            ? trim((string)$persistedTask['next_retry_at'])
            : '';
        $terminalStatuses = ['success', 'failed', 'partial_success', 'not_applicable', 'cancelled'];
        if (!in_array($persistedStatus, $terminalStatuses, true)) {
            $persistedStatus = 'failed';
        }

        return [
            'task_id' => $taskId,
            'data_source_id' => $sourceId,
            'status' => $persistedStatus,
            'message' => 'sync_task_post_finalize_warning',
            'normalized_count' => $normalizedCount,
            'saved_count' => $savedCount,
            'inserted_count' => $insertedCount,
            'updated_count' => $updatedCount,
            'readback_count' => $readbackCount,
            'readback_verified' => $readbackVerified,
            'run_readback' => is_array($stats['run_readback'] ?? null) ? $stats['run_readback'] : [],
            'rolled_back' => ($stats['rolled_back'] ?? false) === true,
            'failure_reason' => $this->failSafeFinalizationCode($stats['failure_reason'] ?? null),
            'predecessor_task_id' => isset($stats['predecessor_task_id']) && is_numeric($stats['predecessor_task_id'])
                ? max(0, (int)$stats['predecessor_task_id'])
                : 0,
            'recovery_context_status' => $this->failSafeFinalizationCode($stats['recovery_context_status'] ?? null),
            'next_retry_at' => $nextRetryAt !== '' ? $nextRetryAt : null,
            'timing' => is_array($stats['timing'] ?? null) ? $stats['timing'] : [],
            'sync_diagnostics' => is_array($stats['sync_diagnostics'] ?? null) ? $stats['sync_diagnostics'] : null,
            'collection_quality' => is_array($stats['collection_quality'] ?? null) ? $stats['collection_quality'] : [],
            'read_fallback_summary' => is_array($stats['read_fallback_summary'] ?? null)
                ? $stats['read_fallback_summary']
                : null,
            'module_status' => null,
            'persistence_fact_status' => (string)($stats['persistence_fact_status'] ?? 'persisted_task_receipt'),
            'fact_status' => [
                'normalized_count' => $normalizedStatus,
                'saved_count' => $savedStatus,
                'inserted_count' => $insertedStatus,
                'updated_count' => $updatedStatus,
                'readback_count' => $readbackCountStatus,
                'readback_verified' => $readbackKnown
                    ? ($readbackVerified ? 'verified' : 'unverified')
                    : 'unknown',
            ],
            'saved_rows_may_exist' => $savedCount > 0 || $insertedCount > 0 || $updatedCount > 0,
            'finalization_status' => 'post_finalize_warning',
            'post_finalize_warning' => true,
            'post_finalize_warning_code' => 'sync_task_post_finalize_failed',
        ];
    }

    /** @return array<string,mixed> */
    private function decodeFailSafeFinalizationStats(mixed $value): array
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

    private function failSafeFinalizationCode(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = strtolower(trim($value));
        return preg_match('/^[a-z0-9_.:-]{1,120}$/D', $value) === 1 ? $value : '';
    }

    /**
     * Return the task state already persisted by a newer recovery attempt.
     * A late worker must not overwrite the terminal task, source state, or logs.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private function persistedSyncTaskResult(int $taskId, array $source, array $task): array
    {
        $status = strtolower(trim((string)($task['status'] ?? '')));
        if ($status === '') {
            $status = 'failed';
        }
        $stats = $this->sanitizeSyncTaskStats($this->decodeConfig($task['stats_json'] ?? []), $status);
        $timing = is_array($stats['timing'] ?? null) ? $stats['timing'] : $this->emptySyncTiming();
        $nextRetryAt = trim((string)($task['next_retry_at'] ?? ''));

        $result = [
            'task_id' => $taskId,
            'data_source_id' => (int)$source['id'],
            'status' => $status,
            'message' => $this->safeSyncTaskMessage(
                $status,
                (string)($task['message'] ?? 'sync task completion ignored because task is no longer active')
            ),
            'normalized_count' => (int)($stats['normalized_count'] ?? 0),
            'saved_count' => (int)($stats['saved_count'] ?? 0),
            'inserted_count' => (int)($stats['inserted_count'] ?? 0),
            'updated_count' => (int)($stats['updated_count'] ?? 0),
            'readback_count' => (int)($stats['readback_count'] ?? 0),
            'readback_verified' => ($stats['readback_verified'] ?? false) === true,
            'run_readback' => is_array($stats['run_readback'] ?? null) ? $stats['run_readback'] : [],
            'rolled_back' => ($stats['rolled_back'] ?? false) === true,
            'failure_reason' => (string)($stats['failure_reason'] ?? ''),
            'predecessor_task_id' => (int)($stats['predecessor_task_id'] ?? 0),
            'recovery_context_status' => (string)($stats['recovery_context_status'] ?? ''),
            'next_retry_at' => $nextRetryAt !== '' ? $nextRetryAt : null,
            'timing' => $timing,
            'sync_diagnostics' => is_array($stats['sync_diagnostics'] ?? null) ? $stats['sync_diagnostics'] : null,
            'collection_quality' => is_array($stats['collection_quality'] ?? null) ? $stats['collection_quality'] : [],
            'read_fallback_summary' => is_array($stats['read_fallback_summary'] ?? null)
                ? $stats['read_fallback_summary']
                : null,
            'module_status' => null,
        ];
        $collectionResult = $this->otaCollectionResult($source, $result);
        if ($collectionResult !== null) {
            $result['collection_result'] = $collectionResult;
        }
        return $result;
    }

    /**
     * @param array<string,mixed> $source
     * @param array<string,mixed> $result
     * @return array<string,mixed>|null
     */
    private function otaCollectionResult(array $source, array $result): ?array
    {
        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        if (!in_array($platform, ['ctrip', 'meituan'], true)) {
            return null;
        }
        $config = is_array($source['config'] ?? null)
            ? $source['config']
            : $this->decodeConfig($source['config_json'] ?? []);
        $platformHotelId = $this->syncTaskOtaStoreIdentifier($platform, $config);
        if ($platformHotelId === '') {
            $platformHotelId = trim((string)(
                $config['external_hotel_id']
                ?? $source['external_hotel_id']
                ?? ''
            ));
        }
        $runReadback = is_array($result['run_readback'] ?? null)
            ? $result['run_readback']
            : [];
        $requiredTrafficMetricKeys = $this->sanitizeSyncDiagnosticMetricKeys(
            $runReadback['required_traffic_metric_keys'] ?? []
        );
        $businessModule = $requiredTrafficMetricKeys !== []
            ? 'traffic'
            : trim((string)($source['data_type'] ?? ''));
        return (new CollectionResultContractService())->fromOtaRunReadback(
            $result,
            [
                'tenant_id' => max(0, (int)($source['tenant_id'] ?? 0)),
                'system_hotel_id' => max(0, (int)($source['system_hotel_id'] ?? 0)),
                'platform' => $platform,
                'platform_hotel_id' => $platformHotelId,
                'business_module' => $businessModule,
                'source_method' => trim((string)($source['ingestion_method'] ?? '')),
            ]
        );
    }

    /**
     * Build a current-run receipt from rows that are bound to the exact sync
     * task. Aggregate write counts are deliberately insufficient here.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $saveReceipt
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private function buildRunReadbackReceipt(
        int $taskId,
        array $source,
        array $saveReceipt,
        array $payload,
        array $task
    ): array {
        $sourceId = max(0, (int)($source['id'] ?? 0));
        $hotelId = max(0, (int)($source['system_hotel_id'] ?? 0));
        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        $targetDate = $this->normalizeDate($payload['data_date'] ?? $payload['dataDate'] ?? null) ?? '';
        $dataPeriod = $this->normalizeDataPeriod($payload['data_period'] ?? $payload['dataPeriod'] ?? '');
        $startedAt = $this->normalizeDateTime(
            $payload['captured_at']
                ?? $payload['capturedAt']
                ?? $payload['snapshot_time']
                ?? $payload['snapshotTime']
                ?? $task['started_at']
                ?? ''
        ) ?? '';
        $diagnostics = is_array($payload['sync_diagnostics'] ?? null) ? $payload['sync_diagnostics'] : [];
        $receipt = [
            'readback_verified' => false,
            'sync_task_id' => max(0, $taskId),
            'data_source_id' => $sourceId,
            'system_hotel_id' => $hotelId,
            'platform' => $platform,
            'target_date' => $targetDate,
            'data_period' => $dataPeriod,
            'started_at' => $startedAt,
            'row_ids' => [],
            'source_trace_ids' => [],
            'observed_platform_hotel_id' => '',
            'verified_metric_keys' => [],
            'capture_strategy' => 'not_recorded',
            'fallback_from' => null,
            'fallback_reason' => null,
            'response_evidence_type' => null,
            'recipe_plan_hash' => null,
            'recipe_count' => null,
            'p0_status' => strtolower(trim((string)($diagnostics['p0_status'] ?? ''))) === 'ready'
                ? 'ready'
                : 'blocked',
            'field_fact_status' => strtolower(trim((string)($diagnostics['field_fact_status'] ?? ''))),
            'required_traffic_metric_keys' => $this->sanitizeSyncDiagnosticMetricKeys($diagnostics['required_traffic_metric_keys'] ?? []),
            'complete_traffic_metric_keys' => $this->sanitizeSyncDiagnosticMetricKeys($diagnostics['complete_traffic_metric_keys'] ?? []),
            'missing_traffic_metric_keys' => $this->sanitizeSyncDiagnosticMetricKeys($diagnostics['missing_traffic_metric_keys'] ?? []),
            'nonzero_required_metric_rows' => max(0, (int)($diagnostics['nonzero_required_metric_rows'] ?? 0)),
            'platform_hotel_identifier_status' => strtolower(trim((string)($diagnostics['platform_hotel_identifier_status'] ?? 'unverified'))),
            'page_field_fact_status' => strtolower(trim((string)($diagnostics['page_field_fact_status'] ?? 'partial'))),
            'readback_count' => 0,
            'failure_reason' => '',
        ];

        $expectedReadbackCount = max(0, (int)($saveReceipt['readback_count'] ?? $saveReceipt['saved_count'] ?? 0));
        $expectedRowIds = array_values(array_unique(array_filter(array_map(
            static fn($value): int => max(0, (int)$value),
            is_array($saveReceipt['row_ids'] ?? null) ? $saveReceipt['row_ids'] : []
        ))));
        if ($taskId <= 0 || $sourceId <= 0 || $hotelId <= 0 || !in_array($platform, ['ctrip', 'meituan'], true)
            || $targetDate === '' || $dataPeriod === '' || $startedAt === ''
            || ($saveReceipt['readback_verified'] ?? false) !== true || $expectedReadbackCount <= 0
            || $expectedRowIds === []
        ) {
            $receipt['failure_reason'] = 'run_identity_or_persistence_readback_missing';
            return $receipt;
        }

        try {
            $columns = $this->tableColumns('online_daily_data');
            foreach (['id', 'sync_task_id', 'data_source_id', 'system_hotel_id', 'data_date', 'data_period', 'readback_verified', 'source_trace_id'] as $requiredColumn) {
                if (!isset($columns[$requiredColumn])) {
                    $receipt['failure_reason'] = 'run_readback_column_missing:' . $requiredColumn;
                    return $receipt;
                }
            }
            if (!isset($columns['platform']) && !isset($columns['source'])) {
                $receipt['failure_reason'] = 'run_readback_platform_column_missing';
                return $receipt;
            }

            $fields = array_values(array_filter([
                'id', 'sync_task_id', 'data_source_id', 'system_hotel_id', 'data_date', 'data_period',
                'readback_verified', 'source_trace_id', 'platform', 'source', 'hotel_id', 'hotel_name',
                'data_type', 'dimension', 'compare_type', 'amount', 'quantity', 'data_value',
                'list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num',
                'raw_data',
            ], static fn(string $field): bool => isset($columns[$field])));
            $query = Db::name('online_daily_data')
                ->field(implode(',', $fields))
                ->where('sync_task_id', $taskId)
                ->where('data_source_id', $sourceId)
                ->where('system_hotel_id', $hotelId)
                ->where('data_date', $targetDate)
                ->where('data_period', $dataPeriod)
                ->limit(CloudOtaBundleCodec::MAX_ROWS + 1);
            if (isset($columns['platform'])) {
                $query->where('platform', $platform);
            }
            if (isset($columns['source'])) {
                $query->where('source', $platform);
            }
            $rows = $query->order('id', 'asc')->select()->toArray();
        } catch (\Throwable $e) {
            $receipt['failure_reason'] = 'run_readback_query_failed';
            return $receipt;
        }

        $rows = array_values(array_filter($rows, 'is_array'));
        if (count($rows) > CloudOtaBundleCodec::MAX_ROWS) {
            $receipt['readback_count'] = count($rows);
            $receipt['failure_reason'] = 'run_readback_row_limit_exceeded';
            return $receipt;
        }
        $rowIds = array_values(array_unique(array_filter(array_map(
            static fn(array $row): int => max(0, (int)($row['id'] ?? 0)),
            $rows
        ))));
        $traceIds = [];
        $allRowsReadbackVerified = $rows !== [];
        $allRowsHaveTrace = $rows !== [];
        foreach ($rows as $row) {
            if ((int)($row['readback_verified'] ?? 0) !== 1) {
                $allRowsReadbackVerified = false;
            }
            $traceId = trim((string)($row['source_trace_id'] ?? ''));
            if ($traceId === '' || preg_match('/^[A-Za-z0-9._:-]{1,160}$/D', $traceId) !== 1) {
                $allRowsHaveTrace = false;
                continue;
            }
            $traceIds[] = $traceId;
        }
        $traceIds = array_values(array_unique($traceIds));
        $receipt['row_ids'] = $rowIds;
        $receipt['source_trace_ids'] = array_slice($traceIds, 0, 50);
        $receipt['observed_platform_hotel_id']
            = $this->observedPlatformHotelIdFromRunRows($rows);
        $config = is_array($source['config'] ?? null)
            ? $source['config']
            : $this->decodeConfig($source['config_json'] ?? []);
        $expectedPlatformHotelId
            = $this->syncTaskOtaStoreIdentifier($platform, $config);
        if ($expectedPlatformHotelId === '') {
            $expectedPlatformHotelId = trim((string)(
                $config['external_hotel_id']
                    ?? $source['external_hotel_id']
                    ?? ''
            ));
        }
        $receipt['platform_hotel_identifier_status']
            = $expectedPlatformHotelId !== ''
                && $receipt['observed_platform_hotel_id'] !== ''
                && $this->otaHotelIdentifiersMatch(
                    $expectedPlatformHotelId,
                    $receipt['observed_platform_hotel_id']
                )
            ? 'ready'
            : 'unverified';
        $receipt['verified_metric_keys'] = $this->verifiedCoreMetricKeysFromRunRows($rows, $source);
        $receipt = array_replace(
            $receipt,
            $this->collectionStrategyEvidenceFromRunRows($rows, $source)
        );
        $receipt['readback_count'] = count($rows);

        // A Profile run may also persist forecast or realtime rows. Verify the
        // target-day subset against the exact row IDs returned by this save
        // receipt instead of requiring every row from the run to share one
        // date and period.
        $receiptRowsBound = $rows !== []
            && count($rows) <= $expectedReadbackCount
            && count($rowIds) === count($rows)
            && array_diff($rowIds, $expectedRowIds) === [];
        $receipt['readback_verified'] = $receiptRowsBound && $allRowsReadbackVerified && $allRowsHaveTrace;
        if (!$receipt['readback_verified']) {
            $receipt['failure_reason'] = !$receiptRowsBound
                ? 'run_readback_receipt_mismatch'
                : (!$allRowsReadbackVerified ? 'run_row_readback_unverified' : 'run_source_trace_missing');
        }

        return $receipt;
    }

    /**
     * Use only response-observed self identifiers. Ctrip uses `-1` for
     * competitor/average rows inside an otherwise valid same-run response; it
     * is a sentinel, not a second hotel and not evidence for the bound hotel.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    private function observedPlatformHotelIdFromRunRows(array $rows): string
    {
        $observed = [];
        foreach ($rows as $row) {
            $raw = $this->decodeConfig($row['raw_data'] ?? []);
            $proof = strtolower(trim((string)(
                $raw['platform_hotel_identifier_proof'] ?? ''
            )));
            if ($proof !== 'row_field_present') {
                continue;
            }
            $compareType = strtolower(trim((string)(
                $row['compare_type']
                    ?? $raw['compare_type']
                    ?? ''
            )));
            if (in_array(
                $compareType,
                [
                    'competitor',
                    'competitor_avg',
                    'peer',
                    'peer_avg',
                    'competition_circle',
                ],
                true
            )) {
                continue;
            }
            $identifier = trim((string)($row['hotel_id'] ?? ''));
            if ($identifier === ''
                || in_array(
                    strtolower($identifier),
                    ['-1', '0', 'null', 'unknown', 'n/a'],
                    true
                )
            ) {
                continue;
            }
            $observed[strtolower($identifier)] = $identifier;
        }

        return count($observed) === 1
            ? (string)array_values($observed)[0]
            : '';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $source
     * @return array<int, string>
     */
    private function verifiedCoreMetricKeysFromRunRows(array $rows, array $source): array
    {
        $config = $this->decodeConfig($source['config_json'] ?? $source['config'] ?? []);
        $ownNames = array_values(array_unique(array_filter(array_map(
            static fn($value): string => trim((string)$value),
            [$source['hotel_name'] ?? '', $source['name'] ?? '', $config['hotel_name'] ?? '', $config['hotelName'] ?? '']
        ))));
        $ownIds = [];
        foreach (['external_hotel_id', 'hotel_id', 'hotelId', 'ota_hotel_id', 'otaHotelId', 'ctrip_hotel_id', 'ctripHotelId', 'platform_hotel_id', 'platformHotelId', 'store_id', 'storeId', 'poi_id', 'poiId'] as $key) {
            foreach ([$source, $config] as $candidate) {
                $value = trim((string)($candidate[$key] ?? ''));
                if ($value !== '') {
                    $ownIds[] = $value;
                }
            }
        }
        // Meituan rows must carry an observed self marker from the adapter.
        // Do not promote a config fallback identifier or source label into
        // current-run self evidence. Ctrip has a separate payload identity
        // gate, so its validated bound identifier remains usable here.
        $isMeituan = strtolower(trim((string)($source['platform'] ?? ''))) === 'meituan';
        if ($isMeituan) {
            $ownNames = [];
            $ownIds = [];
        }
        $operatingRows = OtaOperatingScope::filterOwnOperatingRows($rows, $ownNames, array_values(array_unique($ownIds)));
        if ($isMeituan) {
            $operatingRows = array_values(array_filter($operatingRows, function (array $row): bool {
                $raw = $this->decodeConfig($row['raw_data'] ?? []);
                $observed = is_array($raw['row'] ?? null) ? array_replace($raw['row'], $raw) : $raw;
                $compareType = strtolower(trim((string)($row['compare_type'] ?? $observed['compare_type'] ?? $observed['compareType'] ?? '')));
                return in_array($compareType, ['self', 'own', 'mine', 'current'], true)
                    || ($observed['is_self'] ?? null) === true
                    || ($observed['isSelf'] ?? null) === true
                    || (string)($observed['is_self'] ?? '') === '1'
                    || (string)($observed['isSelf'] ?? '') === '1';
            }));
        }
        $isCtrip = strtolower(trim((string)($source['platform'] ?? ''))) === 'ctrip';
        if ($isCtrip) {
            foreach ($operatingRows as $row) {
                [$revenueReady, $roomNightsReady] = $this->ctripCheckoutReceiptMetricState($row);
                if ($revenueReady && $roomNightsReady) {
                    return ['revenue', 'room_nights', 'adr'];
                }
            }
            return [];
        }

        $revenueVerified = false;
        $roomNightsVerified = false;
        $adrVerified = false;
        $revenueTotal = 0.0;
        $roomNightsTotal = 0.0;
        foreach ($operatingRows as $row) {
            $raw = $this->decodeConfig($row['raw_data'] ?? []);
            $facts = is_array($raw['field_facts'] ?? null) ? $raw['field_facts'] : [];
            foreach ($facts as $fact) {
                if (!is_array($fact) || strtolower(trim((string)($fact['status'] ?? ''))) !== 'captured'
                    || ($fact['stored_value_present'] ?? false) !== true
                ) {
                    continue;
                }
                $metricKey = strtolower(trim((string)($fact['metric_key'] ?? '')));
                if ($metricKey === 'order_amount' && is_numeric($row['amount'] ?? null)) {
                    $revenueVerified = true;
                }
                if ($metricKey === 'room_nights' && is_numeric($row['quantity'] ?? null)) {
                    $roomNightsVerified = true;
                }
                if ($metricKey === 'data_value' && is_numeric($row['data_value'] ?? null)) {
                    $sourceKey = strtolower((string)preg_replace('/[^a-z0-9]+/', '', (string)($fact['source_key'] ?? '')));
                    if (in_array($sourceKey, ['adr', 'avgprice', 'averageprice', 'averagedailyrate'], true)) {
                        $adrVerified = true;
                    }
                }
            }
            if (is_numeric($row['amount'] ?? null)) {
                $revenueTotal += (float)$row['amount'];
            }
            if (is_numeric($row['quantity'] ?? null)) {
                $roomNightsTotal += (float)$row['quantity'];
            }
        }
        if ($revenueVerified && $roomNightsVerified && $roomNightsTotal > 0) {
            $adrVerified = true;
        }

        return array_values(array_filter([
            $revenueVerified ? 'revenue' : null,
            $roomNightsVerified ? 'room_nights' : null,
            $adrVerified ? 'adr' : null,
        ]));
    }

    /**
     * Ctrip's checkout and booking values share one response. A run receipt may
     * unlock revenue analysis only when the exact persisted checkout
     * amount/quantity pair is backed by captured field facts from that row.
     *
     * @param array<string, mixed> $row
     * @return array{0:bool,1:bool}
     */
    private function ctripCheckoutReceiptMetricState(array $row): array
    {
        $raw = $this->decodeConfig($row['raw_data'] ?? []);
        $detail = is_array($raw['row'] ?? null) ? $raw['row'] : [];
        $catalogRaw = $this->decodeConfig($detail['raw_data'] ?? []);
        $endpointId = strtolower(trim((string)(
            $detail['endpoint_id']
            ?? $catalogRaw['endpoint_id']
            ?? ''
        )));
        if ($endpointId !== 'business_market_overview') {
            return [false, false];
        }

        $amountReady = false;
        $roomNightsReady = false;
        foreach ((array)($raw['field_facts'] ?? []) as $fact) {
            if (!is_array($fact)
                || strtolower(trim((string)($fact['status'] ?? ''))) !== 'captured'
                || ($fact['stored_value_present'] ?? false) !== true
            ) {
                continue;
            }
            $metricKey = strtolower(trim((string)($fact['metric_key'] ?? '')));
            $sourceKey = strtolower(trim((string)($fact['source_key'] ?? '')));
            if ($metricKey === 'order_amount'
                && $sourceKey === 'amount'
                && is_numeric($row['amount'] ?? null)
            ) {
                $amountReady = true;
            }
            if ($metricKey === 'room_nights'
                && $sourceKey === 'quantity'
                && is_numeric($row['quantity'] ?? null)
                && (float)$row['quantity'] > 0
            ) {
                $roomNightsReady = true;
            }
        }

        return [$amountReady, $roomNightsReady];
    }

    /**
     * Derive strategy evidence from the exact rows read back for this run.
     * Static data-source configuration is not sufficient to prove how a
     * particular collection result was obtained.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function collectionStrategyEvidenceFromRunRows(array $rows, array $source): array
    {
        $unverified = [
            'capture_strategy' => 'not_recorded',
            'fallback_from' => null,
            'fallback_reason' => null,
            'response_evidence_type' => null,
            'recipe_plan_hash' => null,
            'recipe_count' => null,
        ];
        $ingestionMethod = strtolower(trim((string)($source['ingestion_method'] ?? '')));
        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        if ($rows === []
            || !in_array(
                $ingestionMethod,
                ['browser_profile', 'profile_browser', 'local_collector'],
                true
            )
        ) {
            return $unverified;
        }

        $policy = new OtaStructuredCaptureEvidenceService();
        $authoritativeTrafficRows = array_values(array_filter(
            $rows,
            static function (array $row) use ($source): bool {
                $dataType = strtolower(trim((string)($row['data_type'] ?? '')));
                $platform = strtolower(trim((string)(
                    $row['platform']
                        ?? $row['source']
                        ?? $source['platform']
                        ?? ''
                )));
                return in_array($dataType, ['traffic', 'flow', 'conversion'], true)
                    && OtaTrafficAttributionService::rowBelongsToAuthoritativeP0Traffic(
                        $row,
                        $platform
                    );
            }
        ));
        // A mixed realtime run can contain auxiliary business/rank rows that
        // are useful diagnostics but are not part of the P0 traffic contract.
        // When canonical traffic exists, only those rows may determine the
        // traffic run's structured-response acceptance status.
        $strategyRows = $authoritativeTrafficRows !== []
            ? $authoritativeTrafficRows
            : $rows;
        if ($authoritativeTrafficRows !== []) {
            $structuredTrafficRows = [];
            foreach ($authoritativeTrafficRows as $row) {
                if (($policy->classifyRow($row, $source)['allowed'] ?? false) === true) {
                    $structuredTrafficRows[] = $row;
                }
            }
            $requiredStorageFields = $platform === 'ctrip'
                ? [
                    'list_exposure',
                    'detail_exposure',
                    'flow_rate',
                    'order_filling_num',
                    'order_submit_num',
                ]
                : ['list_exposure', 'detail_exposure', 'flow_rate'];
            $structuredMetricValues = array_fill_keys($requiredStorageFields, []);
            foreach ($structuredTrafficRows as $row) {
                foreach ($requiredStorageFields as $storageField) {
                    if (!array_key_exists($storageField, $row) || !is_numeric($row[$storageField])) {
                        continue;
                    }
                    $structuredMetricValues[$storageField][(string)(float)$row[$storageField]] = true;
                }
            }
            $structuredP0Complete = $structuredTrafficRows !== [];
            foreach ($structuredMetricValues as $values) {
                if (count($values) !== 1) {
                    // Missing and conflicting structured values both remain
                    // fail-closed; DOM rows cannot complete a structured claim.
                    $structuredP0Complete = false;
                    break;
                }
            }
            if ($structuredP0Complete) {
                // DOM rows remain useful diagnostics, but once one exact-run
                // structured set closes every P0 metric they must not downgrade
                // the authoritative response evidence for that same task.
                $strategyRows = $structuredTrafficRows;
            }
        }
        foreach ($strategyRows as $row) {
            $classification = $policy->classifyRow($row, $source);
            if (($classification['allowed'] ?? false) === true) {
                continue;
            }
            if (($classification['status'] ?? '')
                === OtaStructuredCaptureEvidenceService::STATUS_DOM
            ) {
                return [
                    'capture_strategy' => 'dom_fallback',
                    'fallback_from' => 'browser_response',
                    'fallback_reason' => 'structured_response_unavailable',
                    'response_evidence_type' => 'dom_fields',
                    'recipe_plan_hash' => null,
                    'recipe_count' => null,
                ];
            }
            return $unverified;
        }

        return [
            'capture_strategy' => 'browser_response',
            'fallback_from' => null,
            'fallback_reason' => null,
            'response_evidence_type' => 'structured_json',
            'recipe_plan_hash' => null,
            'recipe_count' => null,
        ];
    }

    private function shouldPreserveSourceStateForModuleResult(string $status, array $payload): bool
    {
        $moduleStatus = is_array($payload['module_status'] ?? null) ? $payload['module_status'] : [];
        return strtolower(trim((string)($moduleStatus['module'] ?? ''))) === 'ads'
            && strtolower(trim($status)) !== 'success';
    }

    private function persistOptionalModuleState(array $expectedSource, array $payload, string $checkedAt): void
    {
        $sourceId = (int)($expectedSource['id'] ?? 0);
        $moduleStatus = is_array($payload['module_status'] ?? null) ? $payload['module_status'] : [];
        $module = strtolower(trim((string)($moduleStatus['module'] ?? '')));
        if ($sourceId <= 0 || $module !== 'ads') {
            return;
        }

        Db::transaction(function () use ($expectedSource, $moduleStatus, $module, $checkedAt): void {
            $sourceQuery = Db::name('platform_data_sources')->field('id,tenant_id,system_hotel_id,config_json');
            $this->applyStoredSourceIdentity($sourceQuery, $expectedSource);
            $source = $sourceQuery->lock(true)->find();
            if (!is_array($source)) {
                return;
            }
            $config = $this->decodeConfig($source['config_json'] ?? []);
            $state = [
                'status' => strtolower(trim((string)($moduleStatus['status'] ?? 'blocked'))) ?: 'blocked',
                'reason' => strtolower(trim((string)($moduleStatus['reason'] ?? 'ads_collection_failed'))) ?: 'ads_collection_failed',
                'checked_at' => $checkedAt,
                'external_action_required' => ($moduleStatus['external_action_required'] ?? false) === true,
            ];
            $states = is_array($config['module_states'] ?? null) ? $config['module_states'] : [];
            $states[$module] = $state;
            $config['module_states'] = $states;
            $config['ads_status'] = $state['status'];
            $config['ads_status_reason'] = $state['reason'];
            $config['ads_status_checked_at'] = $state['checked_at'];
            $entryUrl = trim((string)($moduleStatus['entry_url'] ?? ''));
            if ($entryUrl !== '') {
                try {
                    $this->assertOtaMetadataUrlsAreSafe($entryUrl, 'meituan');
                    $config['ads_url'] = $entryUrl;
                    $config['ads_entry_detected_at'] = $checkedAt;
                } catch (\Throwable) {
                    // Ignore unsafe or malformed optional-module metadata.
                }
            }

            $updateQuery = Db::name('platform_data_sources');
            $this->applyStoredSourceIdentity($updateQuery, $source);
            $updateQuery->update([
                'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'update_time' => $checkedAt,
            ]);
        });
    }

    /**
     * Builds a safe, task-level collection-quality snapshot for task stats.
     * This is evidence for one synchronization task only; the live platform
     * status remains responsible for cross-task freshness and downstream use.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $diagnostics
     * @return array<string, mixed>
     */
    private function buildSyncTaskCollectionQualitySnapshot(
        string $status,
        array $source,
        array $diagnostics,
        int $normalizedCount,
        int $savedCount,
        string $collectedAt
    ): array {
        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        $isOtaPlatform = in_array($platform, ['ctrip', 'meituan'], true);
        $ingestionMethod = strtolower(trim((string)($source['ingestion_method'] ?? '')));
        $safeIngestionMethod = in_array($ingestionMethod, ['browser_profile', 'profile_browser', 'local_collector', 'manual', 'api'], true)
            ? $ingestionMethod
            : 'unknown';
        $isBrowserProfile = in_array($ingestionMethod, ['browser_profile', 'profile_browser'], true);
        $isLocalCollector = $ingestionMethod === 'local_collector';
        $isSessionBoundSource = $isBrowserProfile || $isLocalCollector;
        $isManualImport = $ingestionMethod === 'manual';
        $config = $this->decodeConfig($source['config'] ?? $source['config_json'] ?? []);
        $taskStatus = strtolower(trim($status));
        if (!in_array($taskStatus, ['success', 'partial_success', 'failed', 'capture_failed', 'permission_denied'], true)) {
            $taskStatus = 'unknown';
        }
        $targetDate = $this->normalizeDate($diagnostics['target_date'] ?? null) ?? '';
        $targetRows = max(0, (int)($diagnostics['target_date_rows'] ?? 0));
        $targetTrafficRows = max(0, (int)($diagnostics['target_date_traffic_rows'] ?? 0));
        $fieldFactStatus = strtolower(trim((string)($diagnostics['field_fact_status'] ?? '')));
        if (!in_array($fieldFactStatus, ['ready', 'partial', 'missing', 'not_loaded'], true)) {
            $fieldFactStatus = 'unknown';
        }
        $p0Status = strtolower(trim((string)($diagnostics['p0_status'] ?? '')));
        if (!in_array($p0Status, ['ready', 'blocked', 'not_required', 'not_loaded'], true)) {
            $p0Status = 'unknown';
        }
        $confirmedEmpty = $this->truthy($diagnostics['confirmed_empty'] ?? false);

        $qualityFlags = $this->syncTaskQualityMissingInputFlags($diagnostics['missing_inputs'] ?? []);
        $bindingFlags = [];
        if ($isOtaPlatform && (int)($source['system_hotel_id'] ?? 0) <= 0) {
            $bindingFlags[] = 'system_hotel_id_missing';
        }
        if ($isOtaPlatform && (int)($source['id'] ?? 0) <= 0) {
            $bindingFlags[] = 'data_source_id_missing';
        }
        if ($isSessionBoundSource && $this->syncTaskOtaStoreIdentifier($platform, $config) === '') {
            $bindingFlags[] = 'ota_store_id_missing';
        }
        if ($isSessionBoundSource && $this->syncTaskProfileIdentifier($config) === '') {
            $bindingFlags[] = 'profile_id_missing';
        }

        $profileStatus = strtolower(trim((string)($config['profile_status'] ?? $config['login_status'] ?? '')));
        $permissionDenied = in_array($taskStatus, ['permission_denied'], true)
            || in_array($profileStatus, ['permission_denied', 'no_permission', 'unauthorized'], true);
        $profileLoginVerified = $isBrowserProfile
            ? $this->profileSessionProofService->isCurrentVerified($source)
            : ($isLocalCollector && $this->truthy($config['current_session_verified'] ?? false));
        $taskFailed = in_array($taskStatus, ['failed', 'capture_failed'], true);

        $state = 'unverified';
        $nextAction = 'verify_target_date_evidence';
        if (!$isOtaPlatform) {
            $qualityFlags[] = 'non_ota_platform_source';
            $nextAction = 'verify_task_source_scope';
        } elseif ($bindingFlags !== []) {
            $qualityFlags = array_merge($qualityFlags, $bindingFlags);
            $state = 'binding_missing';
            $nextAction = 'complete_hotel_poi_binding';
        } elseif ($permissionDenied) {
            $qualityFlags[] = 'platform_permission_denied';
            $state = 'permission_denied';
            $nextAction = 'restore_platform_permission';
        } elseif ($taskFailed) {
            $qualityFlags[] = 'task_status_failed';
            $state = 'collection_failed';
            $nextAction = 'inspect_collection_failure';
        } elseif ($isManualImport) {
            $qualityFlags[] = 'manual_import_provenance_unverified';
            $nextAction = 'verify_manual_import_provenance';
        } elseif (!$isSessionBoundSource) {
            $qualityFlags[] = 'source_ingestion_method_unverified';
            $nextAction = 'verify_collection_method';
        } elseif (!$profileLoginVerified) {
            $qualityFlags[] = 'platform_session_not_verified';
            $nextAction = 'verify_platform_login_state';
        } elseif ($targetDate === '') {
            $qualityFlags[] = 'target_date_missing';
            $nextAction = 'select_target_date';
        } elseif ($p0Status === 'not_required'
            && $taskStatus === 'success'
            && (($savedCount > 0 && $targetRows > 0) || $confirmedEmpty)
        ) {
            $state = 'available';
            $nextAction = '';
        } elseif ($p0Status !== 'ready') {
            $qualityFlags[] = 'p0_target_date_evidence_not_ready';
            $nextAction = 'verify_target_date_evidence';
        } elseif ($savedCount <= 0 || $targetRows <= 0 || $targetTrafficRows <= 0) {
            if ($savedCount <= 0) {
                $qualityFlags[] = 'saved_rows_missing';
            }
            if ($targetRows <= 0) {
                $qualityFlags[] = 'target_date_rows_missing';
            }
            if ($targetTrafficRows <= 0) {
                $qualityFlags[] = 'target_date_traffic_rows_missing';
            }
            $nextAction = 'collect_target_date_data';
        } elseif ($fieldFactStatus === 'partial' || $taskStatus === 'partial_success') {
            if ($fieldFactStatus === 'partial') {
                $qualityFlags[] = 'target_date_field_facts_partial';
            }
            if ($taskStatus === 'partial_success') {
                $qualityFlags[] = 'task_partial_success';
            }
            $state = 'partial';
            $nextAction = 'complete_missing_target_date_evidence';
        } elseif ($taskStatus === 'success' && $fieldFactStatus === 'ready') {
            $state = 'available';
            $nextAction = '';
        } else {
            $qualityFlags[] = 'task_quality_not_verified';
        }

        return [
            'primary_quality_state' => $state,
            'quality_flags' => array_values(array_unique($qualityFlags)),
            'metric_scope' => $isOtaPlatform ? 'ota_channel' : 'unknown',
            'evidence_scope' => 'sync_task',
            'target_date' => $targetDate,
            'data_as_of' => (($targetRows > 0 && $savedCount > 0) || $confirmedEmpty) ? $targetDate : '',
            'collected_at' => trim($collectedAt),
            'evidence' => [
                'task_status' => $taskStatus,
                'ingestion_method' => $safeIngestionMethod,
                'p0_status' => $p0Status,
                'target_date_rows' => $targetRows,
                'target_date_traffic_rows' => $targetTrafficRows,
                'field_fact_status' => $fieldFactStatus,
                'normalized_count' => max(0, $normalizedCount),
                'saved_count' => max(0, $savedCount),
                'confirmed_empty' => $confirmedEmpty,
            ],
            'next_action' => $nextAction,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function syncTaskQualityMissingInputFlags(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $allowed = [
            'current_session_verified',
            'manual_login_state_verified',
            'profile_status_logged_in',
            'last_login_verified_at',
            'target_date_traffic_rows',
            'traffic_field_facts',
            'required_traffic_metric_keys',
            'target_date_required_traffic_metrics_zero_unverified',
            'platform_hotel_identifier',
            'page_field_fact_status',
        ];
        $flags = [];
        foreach ($value as $item) {
            $flag = strtolower(trim((string)$item));
            if (in_array($flag, $allowed, true)) {
                $flags[] = $flag;
            }
        }

        return array_values(array_unique($flags));
    }

    private function syncTaskOtaStoreIdentifier(string $platform, array $config): string
    {
        $keys = $platform === 'meituan'
            ? ['store_id', 'storeId', 'poi_id', 'poiId']
            : ['ota_hotel_id', 'otaHotelId', 'ctrip_hotel_id', 'ctripHotelId', 'hotel_code', 'hotelCode', 'hotel_id', 'hotelId'];
        foreach ($keys as $key) {
            $value = trim((string)($config[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function syncTaskProfileIdentifier(array $config): string
    {
        foreach (['profile_id', 'profileId', 'stable_profile_id', 'stableProfileId', 'profile_binding_key', 'profileBindingKey', 'profile_key_hash'] as $key) {
            $value = trim((string)($config[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function buildSyncDiagnostics(array $rows, int $savedCount, array $source, array $options, array $payload, string $adapterStatus, string $adapterMessage): array
    {
        $targetDate = $this->syncTargetDate($options, $payload);
        $dataTypes = [];
        $targetRows = 0;
        $targetTrafficRows = 0;
        $targetTrafficFieldFactReady = 0;
        $targetTrafficFieldFactMissing = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowDate = $this->normalizeDate($row['data_date'] ?? $row['dataDate'] ?? null);
            if ($rowDate !== $targetDate) {
                continue;
            }
            $targetRows++;
            $dataType = $this->normalizeDataType((string)($row['data_type'] ?? $row['dataType'] ?? $source['data_type'] ?? ''));
            if ($dataType !== '') {
                $dataTypes[$dataType] = true;
            }
            if ($dataType === 'traffic') {
                $targetTrafficRows++;
                if ($this->normalizedRowHasFieldFactEvidence($row)) {
                    $targetTrafficFieldFactReady++;
                } else {
                    $targetTrafficFieldFactMissing++;
                }
            }
        }

        $requiresTraffic = $this->syncRequiresTargetDateTrafficEvidence($source, $options, $payload);
        $trafficP0 = $this->targetTrafficP0Closure(
            $rows,
            strtolower(trim((string)($source['platform'] ?? ''))),
            $targetDate
        );
        if ($requiresTraffic) {
            $targetTrafficRows = (int)($trafficP0['traffic_row_count'] ?? 0);
            $targetTrafficFieldFactReady = (int)($trafficP0['field_fact_ready_count'] ?? 0);
            $targetTrafficFieldFactMissing = (int)($trafficP0['field_fact_missing_count'] ?? 0);
        }
        $confirmedEmpty = $this->isAuthoritativeEmptySyncPayload($payload);
        $captureSectionStatuses = $this->syncCaptureSectionStatuses($options, $payload);
        $fieldFactStatus = $targetTrafficRows <= 0
            ? 'not_loaded'
            : ($targetTrafficFieldFactReady > 0 && $targetTrafficFieldFactMissing === 0 ? 'ready' : ($targetTrafficFieldFactReady > 0 ? 'partial' : 'missing'));
        $missingInputs = [];
        if ($requiresTraffic && $targetTrafficRows <= 0) {
            $missingInputs[] = 'target_date_traffic_rows';
        }
        if ($requiresTraffic && $targetTrafficRows > 0 && $targetTrafficFieldFactReady <= 0) {
            $missingInputs[] = 'traffic_field_facts';
        }
        if ($requiresTraffic && (array)($trafficP0['missing_metric_keys'] ?? []) !== []) {
            $missingInputs[] = 'required_traffic_metric_keys';
        }
        if ($requiresTraffic && empty($trafficP0['nonzero_required_metric_ready'])) {
            $missingInputs[] = 'target_date_required_traffic_metrics_zero_unverified';
        }
        if ($requiresTraffic && empty($trafficP0['platform_hotel_identifier_ready'])) {
            $missingInputs[] = 'platform_hotel_identifier';
        }
        if ($requiresTraffic && empty($trafficP0['ui_status_ready'])) {
            $missingInputs[] = 'page_field_fact_status';
        }
        foreach ($this->browserProfileCurrentSessionProofMissingRequirements($source) as $missingLoginRequirement) {
            if (!in_array($missingLoginRequirement, $missingInputs, true)) {
                $missingInputs[] = $missingLoginRequirement;
            }
        }
        $p0Status = $missingInputs !== []
            ? 'blocked'
            : ($requiresTraffic ? 'ready' : (($savedCount > 0 || $confirmedEmpty) ? 'not_required' : 'not_loaded'));
        $capabilityStates = $this->syncTaskCapabilityStates($dataTypes, $savedCount, $adapterStatus);
        if ($confirmedEmpty && $adapterStatus === 'success') {
            $capabilityStates = $this->applyConfirmedEmptyCapabilityStates($capabilityStates, $options, $payload);
        }
        $capabilityStates = $this->applyCaptureSectionCapabilityStates($capabilityStates, $captureSectionStatuses);
        $operatorMessage = 'target_date_traffic_ready';
        if (in_array('current_session_verified', $missingInputs, true)) {
            $operatorMessage = 'current_session_not_verified';
        } elseif (in_array('target_date_traffic_rows', $missingInputs, true)) {
            $operatorMessage = 'profile_reused_no_target_date_traffic_rows';
        } elseif (in_array('traffic_field_facts', $missingInputs, true)) {
            $operatorMessage = 'traffic_field_facts_missing';
        } elseif (in_array('required_traffic_metric_keys', $missingInputs, true)) {
            $operatorMessage = 'required_traffic_metric_keys_missing';
        } elseif (in_array('target_date_required_traffic_metrics_zero_unverified', $missingInputs, true)) {
            $operatorMessage = 'target_date_required_traffic_metrics_zero_unverified';
        } elseif (in_array('platform_hotel_identifier', $missingInputs, true)) {
            $operatorMessage = 'platform_hotel_identifier_unverified';
        } elseif (in_array('page_field_fact_status', $missingInputs, true)) {
            $operatorMessage = 'page_field_fact_status_not_ready';
        } elseif ($adapterStatus !== 'success') {
            $operatorMessage = $this->safeSyncTaskMessage($adapterStatus, $adapterMessage);
        }

        return [
            'target_date' => $targetDate,
            'requires_target_date_traffic' => $requiresTraffic,
            'target_date_rows' => $targetRows,
            'target_date_traffic_rows' => $targetTrafficRows,
            'target_date_data_types' => array_keys($dataTypes),
            'target_date_traffic_field_fact_ready_count' => $targetTrafficFieldFactReady,
            'target_date_traffic_field_fact_missing_count' => $targetTrafficFieldFactMissing,
            'required_traffic_metric_keys' => $trafficP0['required_metric_keys'] ?? [],
            'complete_traffic_metric_keys' => $trafficP0['complete_metric_keys'] ?? [],
            'missing_traffic_metric_keys' => $trafficP0['missing_metric_keys'] ?? [],
            'nonzero_required_metric_rows' => (int)($trafficP0['nonzero_required_metric_rows'] ?? 0),
            'platform_hotel_identifier_status' => !empty($trafficP0['platform_hotel_identifier_ready']) ? 'ready' : 'unverified',
            'page_field_fact_status' => !empty($trafficP0['ui_status_ready']) ? 'ready' : 'partial',
            'field_fact_status' => $fieldFactStatus,
            'p0_status' => $p0Status,
            'capability_states' => $capabilityStates,
            'capture_section_statuses' => $captureSectionStatuses,
            'missing_inputs' => $missingInputs,
            'operator_message' => $operatorMessage,
            'adapter_status' => $adapterStatus,
            'confirmed_empty' => $confirmedEmpty,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function syncCaptureSectionStatuses(array $options, array $payload): array
    {
        $captureGate = $payload['capture_gate'] ?? $payload['captureGate'] ?? null;
        if (!is_array($captureGate)) {
            $captureGate = $payload['data_source_capture']['capture_gate'] ?? null;
        }
        $statuses = $this->sanitizeSyncCaptureSectionStatuses(
            is_array($captureGate) ? ($captureGate['section_statuses'] ?? $captureGate['sectionStatuses'] ?? null) : null
        );

        $skippedSections = [
            $options['skipped_sections_no_entry'] ?? null,
            $options['skippedSectionsNoEntry'] ?? null,
            $payload['skipped_sections_no_entry'] ?? null,
            $payload['skippedSectionsNoEntry'] ?? null,
            $payload['data_source_capture']['skipped_sections_no_entry'] ?? null,
        ];
        foreach ($skippedSections as $value) {
            $sections = is_array($value) ? $value : preg_split('/[,\s]+/', trim((string)$value));
            foreach (is_array($sections) ? $sections : [] as $section) {
                $section = strtolower(trim((string)$section));
                if (!in_array($section, ['traffic', 'order_flow', 'orders', 'ads', 'reviews', 'room_types'], true)) {
                    continue;
                }
                if (!isset($statuses[$section]) || $statuses[$section] === 'not_captured') {
                    $statuses[$section] = 'not_applicable';
                }
            }
        }

        return $statuses;
    }

    /**
     * @param array<string, string> $states
     * @param array<string, string> $sectionStatuses
     * @return array<string, string>
     */
    private function applyCaptureSectionCapabilityStates(array $states, array $sectionStatuses): array
    {
        foreach (['orders' => 'orders', 'reviews' => 'reviews'] as $section => $capability) {
            $status = $sectionStatuses[$section] ?? '';
            if ($status === 'empty_confirmed') {
                $states[$capability] = 'verified';
            } elseif ($status === 'not_applicable') {
                $states[$capability] = 'capability_unavailable';
            }
        }
        return $states;
    }

    /**
     * @param array<string, string> $states
     * @return array<string, string>
     */
    private function applyConfirmedEmptyCapabilityStates(array $states, array $options, array $payload): array
    {
        $sectionText = strtolower(implode(',', array_filter(array_map(
            static fn($value): string => is_string($value) ? trim($value) : '',
            [
                $options['capture_sections'] ?? null,
                $options['captureSections'] ?? null,
                $options['sections'] ?? null,
                $payload['data_source_capture']['requested_capture_sections'] ?? null,
                $payload['data_source_capture']['capture_sections'] ?? null,
            ]
        ))));
        if (preg_match('/(^|[,\s])orders?([,\s]|$)/', $sectionText) === 1) {
            $states['orders'] = 'verified';
        }
        if (preg_match('/(^|[,\s])(reviews?|comments?)([,\s]|$)/', $sectionText) === 1) {
            $states['reviews'] = 'verified';
        }
        return $states;
    }

    /**
     * @param array<string, bool> $targetDataTypes
     * @return array<string, string>
     */
    private function syncTaskCapabilityStates(array $targetDataTypes, int $savedCount, string $adapterStatus): array
    {
        $states = [
            'business' => 'unverified',
            'orders' => 'unverified',
            'reviews' => 'unverified',
        ];
        if (in_array(strtolower(trim($adapterStatus)), ['permission_denied', 'no_permission', 'unauthorized', 'forbidden'], true)) {
            return array_fill_keys(array_keys($states), 'permission_denied');
        }
        if ($savedCount <= 0) {
            return $states;
        }

        foreach ([
            'business' => 'business',
            'order' => 'orders',
            'review' => 'reviews',
        ] as $dataType => $capability) {
            if (isset($targetDataTypes[$dataType])) {
                $states[$capability] = 'verified';
            }
        }

        return $states;
    }

    private function syncTargetDate(array $options, array $payload): string
    {
        $date = $this->normalizeDate(
            $options['target_date']
            ?? $options['targetDate']
            ?? $options['data_date']
            ?? $options['dataDate']
            ?? $payload['target_date']
            ?? $payload['targetDate']
            ?? $payload['data_date']
            ?? $payload['dataDate']
            ?? ($payload['data_source_capture']['data_date'] ?? null)
        );
        return $date ?? date('Y-m-d');
    }

    private function syncRequiresTargetDateTrafficEvidence(array $source, array $options, array $payload): bool
    {
        if (!$this->isOtaBrowserProfileSource($source) && !$this->isOtaLocalCollectorSource($source)) {
            return false;
        }

        $explicitSections = array_values(array_filter([
            $options['capture_sections'] ?? null,
            $options['captureSections'] ?? null,
            $options['sections'] ?? null,
            $payload['data_source_capture']['requested_capture_sections'] ?? null,
            $payload['data_source_capture']['capture_sections'] ?? null,
        ], static fn($value): bool => is_string($value) && trim($value) !== ''));
        if ($explicitSections !== []) {
            $explicitSectionText = strtolower(implode(',', $explicitSections));
            return preg_match('/traffic|flow|core|default|business_overview|traffic_report/', $explicitSectionText) === 1;
        }

        $trigger = strtolower(trim((string)($options['trigger_type'] ?? $options['triggerType'] ?? '')));
        if (in_array($trigger, ['cron', 'auto_fetch', 'daily_profile_reuse', 'profile_login_after_login', 'profile_login_after_sync', 'profile_login_verified_sync'], true)) {
            return true;
        }
        $dataType = $this->normalizeDataType((string)($source['data_type'] ?? $options['data_type'] ?? $options['dataType'] ?? ''));
        if ($dataType === 'traffic') {
            return true;
        }

        $sectionText = strtolower(json_encode([
            $options['capture_sections'] ?? null,
            $options['captureSections'] ?? null,
            $options['sections'] ?? null,
            $payload['data_source_capture']['capture_sections'] ?? null,
            $payload['sync_summary'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        return preg_match('/traffic|flow|core|default|business_overview|traffic_report/', $sectionText) === 1;
    }

    private function normalizedRowHasFieldFactEvidence(array $row): bool
    {
        $raw = $row['raw_data'] ?? [];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return false;
        }

        $summary = is_array($raw['field_fact_summary'] ?? null) ? $raw['field_fact_summary'] : [];
        if ((int)($summary['captured_count'] ?? 0) > 0 || (int)($summary['capture_evidence_count'] ?? 0) > 0) {
            return true;
        }
        $facts = is_array($raw['field_facts'] ?? null) ? $raw['field_facts'] : [];
        foreach ($facts as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            if (($fact['status'] ?? '') === 'captured' || !empty($fact['stored_value_present']) || !empty($fact['capture_evidence'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function targetTrafficP0Closure(array $rows, string $platform, string $targetDate): array
    {
        $requiredMetricKeys = [
            'list_exposure',
            'detail_exposure',
            'flow_rate',
            'order_filling_num',
            'order_submit_num',
        ];
        if ($platform === 'meituan') {
            $requiredMetricKeys = array_slice($requiredMetricKeys, 0, 3);
        }
        $expectedStorageFields = [];
        foreach ($requiredMetricKeys as $metricKey) {
            $expectedStorageFields[$metricKey] = 'online_daily_data.' . $metricKey;
        }

        $trafficRows = [];
        foreach ($rows as $row) {
            if (!is_array($row)
                || $this->normalizeDate($row['data_date'] ?? $row['dataDate'] ?? null) !== $targetDate
                || $this->normalizeDataType((string)($row['data_type'] ?? $row['dataType'] ?? '')) !== 'traffic'
                || !OtaTrafficAttributionService::rowBelongsToOwnPlatformTraffic($row, $platform)
            ) {
                continue;
            }
            $trafficRows[] = $row;
        }

        $completeMetricKeys = [];
        $fieldFactReadyCount = 0;
        $fieldFactMissingCount = 0;
        $nonzeroRequiredMetricRows = 0;
        $allIdentifiersReady = $trafficRows !== [];
        foreach ($trafficRows as $row) {
            $raw = $this->decodeConfig($row['raw_data'] ?? []);
            $rowEvidence = is_array($raw['capture_evidence'] ?? null) ? $raw['capture_evidence'] : [];
            $rowTraceId = trim((string)($row['source_trace_id'] ?? $raw['source_trace_id'] ?? $rowEvidence['source_trace_id'] ?? ''));
            $rowSourceUrlHash = trim((string)($rowEvidence['source_url_hash'] ?? $raw['source_url_hash'] ?? ''));
            $rowCompleteMetricKeys = [];

            foreach (is_array($raw['field_facts'] ?? null) ? $raw['field_facts'] : [] as $fact) {
                if (!is_array($fact)) {
                    continue;
                }
                $metricKey = strtolower(trim((string)($fact['metric_key'] ?? '')));
                if (!isset($expectedStorageFields[$metricKey])) {
                    continue;
                }
                $sourcePath = trim((string)($fact['source_path'] ?? ''));
                $storageField = trim((string)($fact['storage_field'] ?? ''));
                $factEvidence = is_array($fact['capture_evidence'] ?? null) ? $fact['capture_evidence'] : [];
                $factTraceId = trim((string)($factEvidence['source_trace_id'] ?? $factEvidence['_source_trace_id'] ?? ''));
                $factSourceUrlHash = trim((string)($factEvidence['source_url_hash'] ?? $factEvidence['_source_url_hash'] ?? ''));
                $sourcePathStructured = $sourcePath !== ''
                    && (str_contains($sourcePath, '.') || str_contains($sourcePath, '[') || str_contains($sourcePath, '/'));
                $factReady = $sourcePathStructured
                    && $storageField === $expectedStorageFields[$metricKey]
                    && ($fact['stored_value_present'] ?? null) === true
                    && $rowTraceId !== ''
                    && $rowSourceUrlHash !== ''
                    && hash_equals($rowTraceId, $factTraceId)
                    && hash_equals($rowSourceUrlHash, $factSourceUrlHash);
                if ($factReady) {
                    $completeMetricKeys[$metricKey] = true;
                    $rowCompleteMetricKeys[$metricKey] = true;
                }
            }

            $identifierReady = ($raw['platform_hotel_identifier_present'] ?? null) === true
                && trim((string)($raw['platform_hotel_identifier_source'] ?? '')) !== ''
                && !in_array(
                    strtolower(trim((string)($raw['platform_hotel_identifier_proof'] ?? ''))),
                    ['', 'missing', 'unverified'],
                    true
                );
            $bindingStatus = strtolower(trim((string)($raw['platform_hotel_binding_status'] ?? '')));
            if ($bindingStatus !== '') {
                $identifierReady = $identifierReady
                    && $bindingStatus === 'matched'
                    && !in_array(
                        strtolower(trim((string)($raw['platform_hotel_binding_proof'] ?? ''))),
                        ['', 'missing', 'unverified'],
                        true
                    );
            }
            if (!$identifierReady) {
                $allIdentifiersReady = false;
            }

            foreach ($requiredMetricKeys as $metricKey) {
                if (!array_key_exists($metricKey, $row) || !is_numeric($row[$metricKey])) {
                    continue;
                }
                if (abs((float)$row[$metricKey]) > 0.000001) {
                    $nonzeroRequiredMetricRows++;
                    break;
                }
            }
        }

        $completeMetricKeys = array_values(array_intersect($requiredMetricKeys, array_keys($completeMetricKeys)));
        $missingMetricKeys = array_values(array_diff($requiredMetricKeys, $completeMetricKeys));
        // Ctrip and Meituan expose the required traffic metrics through
        // multiple endpoint-granular rows. P0 requires complete evidence for
        // every metric across the current run, not every metric on every row.
        $fieldFactReadyCount = count($completeMetricKeys);
        $fieldFactMissingCount = count($missingMetricKeys);
        return [
            'required_metric_keys' => $requiredMetricKeys,
            'traffic_row_count' => count($trafficRows),
            'field_fact_ready_count' => $fieldFactReadyCount,
            'field_fact_missing_count' => $fieldFactMissingCount,
            'complete_metric_keys' => $completeMetricKeys,
            'missing_metric_keys' => $missingMetricKeys,
            'nonzero_required_metric_rows' => $nonzeroRequiredMetricRows,
            'nonzero_required_metric_ready' => $nonzeroRequiredMetricRows > 0,
            'platform_hotel_identifier_ready' => $allIdentifiersReady,
            'ui_status_ready' => $trafficRows !== [] && $missingMetricKeys === [],
        ];
    }

}
