<?php
declare(strict_types=1);

namespace app\service;

final class ScheduledAutoFetchPolicy
{
    private const REQUIRED_DAILY_PLATFORMS = ['ctrip', 'meituan'];
    private const DEFAULT_YESTERDAY_COLLECTION_TIME = '08:30';
    private const DEFAULT_YESTERDAY_CUTOFF_TIME = '09:00';

    /**
     * Explicit source scope is an operator contract, so every selected source
     * must run even when multiple degraded sources belong to one platform.
     * Unscoped schedules retain the bounded one-degraded-source policy.
     *
     * @param array<int, array<string, mixed>> $sources
     * @param array<int, int> $explicitSourceIds
     * @return array<int, array<string, mixed>>
     */
    public function profileSourcesForRun(array $sources, array $explicitSourceIds = []): array
    {
        $explicitSourceIds = array_values(array_unique(array_filter(
            array_map('intval', $explicitSourceIds),
            static fn(int $id): bool => $id > 0
        )));
        sort($explicitSourceIds, SORT_NUMERIC);
        if ($explicitSourceIds === []) {
            return $this->retryableProfileSources($sources);
        }

        $sourcesById = [];
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            $id = (int)($source['id'] ?? 0);
            if ($id > 0) {
                $sourcesById[$id] = $source;
            }
        }

        $selected = [];
        foreach ($explicitSourceIds as $sourceId) {
            if (isset($sourcesById[$sourceId])) {
                $selected[] = $sourcesById[$sourceId];
            }
        }
        return $selected;
    }

    /**
     * Keep every currently usable Profile source. When a platform has no
     * usable source because the previous attempt changed it to a degraded
     * state, retain one deterministic source so the bounded retry policy can
     * actually retry it. Duplicate degraded sources are not fanned out.
     *
     * @param array<int, array<string, mixed>> $sources
     * @return array<int, array<string, mixed>>
     */
    public function retryableProfileSources(array $sources): array
    {
        $activeStatuses = ['ready', 'success', 'partial_success'];
        $degradedStatuses = ['failed', 'waiting_config'];
        $active = [];
        $activePlatforms = [];
        $degradedByPlatform = [];

        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            $platform = strtolower(trim((string)($source['platform'] ?? '')));
            $status = strtolower(trim((string)($source['status'] ?? '')));
            if (!in_array($platform, ['ctrip', 'meituan'], true)) {
                continue;
            }
            if (in_array($status, $activeStatuses, true)) {
                $active[] = $source;
                $activePlatforms[$platform] = true;
                continue;
            }
            if (!in_array($status, $degradedStatuses, true)) {
                continue;
            }
            $current = $degradedByPlatform[$platform] ?? null;
            if (!is_array($current) || $this->profileSourceIsNewer($source, $current)) {
                $degradedByPlatform[$platform] = $source;
            }
        }

        foreach ($degradedByPlatform as $platform => $source) {
            if (!isset($activePlatforms[$platform])) {
                $active[] = $source;
            }
        }

        return array_values($active);
    }

    /**
     * Build a bounded catch-up window without requiring the dispatcher to hit
     * the configured minute exactly.
     *
     * @return array<int, array{slot_id: string, period: string, data_date: string, executed_key: string, retry_key: string, label: string, executed_message: string, target_platforms?: array<int, string>}>
     */
    public function dueRuns(int $hotelId, array $status, \DateTimeImmutable $now): array
    {
        $historicalTime = $this->normalizeTime((string)(
            $status['historical_schedule_time']
            ?? $status['schedule_time']
            ?? self::DEFAULT_YESTERDAY_COLLECTION_TIME
        )) ?? self::DEFAULT_YESTERDAY_COLLECTION_TIME;
        if ($historicalTime > self::DEFAULT_YESTERDAY_COLLECTION_TIME) {
            $historicalTime = self::DEFAULT_YESTERDAY_COLLECTION_TIME;
        }
        $realtimeMinute = $this->normalizeMinute($status['realtime_schedule_minute'] ?? $status['schedule_minute'] ?? 5) ?? 5;
        $realtimeIntervalHours = $this->normalizeIntervalHours($status['realtime_schedule_interval_hours'] ?? $status['realtime_interval_hours'] ?? $status['schedule_interval_hours'] ?? 2);
        $historicalEnabled = array_key_exists('historical_enabled', $status) ? $this->truthy($status['historical_enabled']) : true;
        $realtimeEnabled = array_key_exists('realtime_enabled', $status) ? $this->truthy($status['realtime_enabled']) : true;
        $today = $now->format('Y-m-d');
        $yesterday = $now->modify('-1 day')->format('Y-m-d');
        $currentHour = (int)$now->format('H');
        $currentMinute = (int)$now->format('i');
        $runsBySlot = [];

        foreach (array_reverse((array)($status['failed_records'] ?? [])) as $failedRecord) {
            $pendingRun = $this->pendingRunFromFailure(is_array($failedRecord) ? $failedRecord : [], $hotelId, $now);
            if ($pendingRun !== null) {
                $runsBySlot[$pendingRun['slot_id']] = $pendingRun;
            }
            if (count($runsBySlot) >= 2) {
                break;
            }
        }

        if ($historicalEnabled && $now->format('H:i') >= $historicalTime) {
            $run = [
                'slot_id' => "historical:{$yesterday}",
                'period' => 'historical_daily',
                'data_date' => $yesterday,
                'executed_key' => "online_data_historical_executed_{$hotelId}_{$yesterday}",
                'retry_key' => "online_data_historical_retry_{$hotelId}_{$yesterday}",
                'label' => 'historical',
                'executed_message' => '历史固定数据今天已执行',
            ];
            $runsBySlot[$run['slot_id']] = $run;
        }
        if ($realtimeEnabled
            && $currentMinute >= $realtimeMinute
            && $currentHour % $realtimeIntervalHours === 0
        ) {
            $run = [
                'slot_id' => "realtime:{$today}:{$currentHour}",
                'period' => 'realtime_snapshot',
                'data_date' => $today,
                'executed_key' => "online_data_realtime_executed_{$hotelId}_{$today}_{$currentHour}",
                'retry_key' => "online_data_realtime_retry_{$hotelId}_{$today}_{$currentHour}",
                'label' => 'realtime',
                'executed_message' => "实时快照本 {$realtimeIntervalHours} 小时窗口已执行",
            ];
            $runsBySlot[$run['slot_id']] = $run;
        }

        return array_values($runsBySlot);
    }

    /**
     * @return array{complete: bool, status: string, saved_count: int, required_platforms: array<int, string>, failed_platforms: array<int, string>, successful_platforms: array<int, string>}
     */
    public function classifyOutcome(array $result): array
    {
        $savedCount = max(0, (int)($result['saved_count'] ?? 0));
        $reusedVerifiedCount = max(0, (int)($result['reused_verified_count'] ?? 0));
        $verifiedEvidenceCount = $savedCount + $reusedVerifiedCount;
        $requiredPlatforms = $this->platformList(
            $result['required_platforms']
            ?? $result['target_platforms']
            ?? self::REQUIRED_DAILY_PLATFORMS
        );
        if ($requiredPlatforms === []) {
            $requiredPlatforms = self::REQUIRED_DAILY_PLATFORMS;
        }
        $failedPlatforms = $this->platformList($result['failed_platforms'] ?? []);
        $inProgressPlatforms = $this->platformList($result['in_progress_platforms'] ?? []);
        $successfulPlatforms = $this->platformList($result['successful_platforms'] ?? []);
        foreach ((array)($result['platform_results'] ?? []) as $platformResult) {
            if (!is_array($platformResult)) {
                continue;
            }
            $platform = strtolower(trim((string)($platformResult['platform'] ?? '')));
            if (!in_array($platform, ['ctrip', 'meituan'], true)) {
                continue;
            }
            if (strtolower(trim((string)($platformResult['status'] ?? ''))) === 'in_progress'
                && !empty($platformResult['reused_active_task'])
            ) {
                $inProgressPlatforms[] = $platform;
                continue;
            }
            $platformEvidenceCount = max(0, (int)($platformResult['saved_count'] ?? 0))
                + max(0, (int)($platformResult['reused_verified_count'] ?? 0));
            if (!empty($platformResult['success']) && $platformEvidenceCount > 0) {
                $successfulPlatforms[] = $platform;
            } else {
                $failedPlatforms[] = $platform;
            }
        }
        foreach ($requiredPlatforms as $requiredPlatform) {
            if (!in_array($requiredPlatform, $successfulPlatforms, true)
                && !in_array($requiredPlatform, $inProgressPlatforms, true)
            ) {
                $failedPlatforms[] = $requiredPlatform;
            }
        }
        $failedPlatforms = array_values(array_unique($failedPlatforms));
        $inProgressPlatforms = array_values(array_unique(array_diff(
            $inProgressPlatforms,
            $failedPlatforms
        )));
        $successfulPlatforms = array_values(array_unique($successfulPlatforms));
        $successfulPlatforms = array_values(array_diff(
            $successfulPlatforms,
            $failedPlatforms,
            $inProgressPlatforms
        ));
        $producerSucceeded = !empty($result['success']);
        $complete = $producerSucceeded
            && $verifiedEvidenceCount > 0
            && $failedPlatforms === []
            && $inProgressPlatforms === []
            && array_diff($requiredPlatforms, $successfulPlatforms) === [];
        $partial = !$complete && ($verifiedEvidenceCount > 0 || $successfulPlatforms !== []);
        $status = $complete
            ? 'success'
            : ($inProgressPlatforms !== [] && $failedPlatforms === []
                ? 'in_progress'
                : ($partial ? 'partial_success' : 'failed'));

        return [
            'complete' => $complete,
            'status' => $status,
            'saved_count' => $savedCount,
            'reused_verified_count' => $reusedVerifiedCount,
            'required_platforms' => $requiredPlatforms,
            'failed_platforms' => $failedPlatforms,
            'in_progress_platforms' => $inProgressPlatforms,
            'successful_platforms' => $successfulPlatforms,
        ];
    }

    public function normalizeMaxAttempts(mixed $value): int
    {
        return is_numeric($value) ? max(1, min(10, (int)$value)) : 3;
    }

    public function normalizeDelayMinutes(mixed $value): int
    {
        return is_numeric($value) ? max(1, min(60, (int)$value)) : 5;
    }

    /** @return array<int, string> */
    public function normalizePlatforms(mixed $platforms): array
    {
        return $this->platformList($platforms);
    }

    /**
     * Keep scheduler execution/retry caches isolated by the exact requested
     * source and platform scope. Empty arrays are explicit dynamic markers,
     * so legacy unscoped keys can never satisfy a newly scoped dispatcher.
     *
     * @param array<int, int> $sourceIds
     * @param array<int, string> $platforms
     */
    public function cacheScopeSuffix(array $sourceIds, array $platforms): string
    {
        $sourceIds = $this->positiveIds($sourceIds);
        sort($sourceIds, SORT_NUMERIC);
        $platforms = $this->platformList($platforms);
        $descriptor = [
            'source_ids' => $sourceIds,
            'source_mode' => $sourceIds === [] ? 'dynamic' : 'fixed',
            'platforms' => $platforms,
            'platform_mode' => $platforms === [] ? 'dynamic' : 'fixed',
        ];
        ksort($descriptor, SORT_STRING);
        return '_scope_' . substr(hash('sha256', json_encode(
            $descriptor,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        )), 0, 16);
    }

    /**
     * @param array<int, int> $sourceIds
     * @param array<string, mixed> $outcome
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function buildDailyTrustReceipt(
        int $hotelId,
        string $targetDate,
        array $sourceIds,
        array $outcome,
        array $result,
        string $dataPeriod = 'historical_daily'
    ): array {
        $dataPeriod = strtolower(trim($dataPeriod));
        if (!in_array($dataPeriod, ['historical_daily', 'realtime_snapshot'], true)) {
            $dataPeriod = 'historical_daily';
        }
        $sourceIds = array_values(array_unique(array_filter(
            array_map('intval', $sourceIds),
            static fn(int $id): bool => $id > 0
        )));
        sort($sourceIds, SORT_NUMERIC);
        $sourceTasks = [];
        $failedSourceTasks = [];
        $safeOperationalCode = static function (mixed $value): string {
            $value = strtolower(trim((string)$value));
            return preg_match('/^[a-z0-9][a-z0-9._:-]{0,119}$/D', $value) === 1
                ? $value
                : '';
        };
        foreach ((array)($result['platform_results'] ?? []) as $platformResult) {
            if (!is_array($platformResult)) {
                continue;
            }
            $readback = is_array($platformResult['run_readback'] ?? null) ? $platformResult['run_readback'] : [];
            $dataSourceId = (int)($readback['data_source_id'] ?? $platformResult['data_source_id'] ?? 0);
            $ingestionMethod = strtolower(trim((string)($platformResult['ingestion_method'] ?? '')));
            if (!in_array($ingestionMethod, ['browser_profile', 'local_collector'], true)) {
                $ingestionMethod = '';
            }
            $localCollectorTaskId = max(0, (int)(
                $platformResult['local_collector_task_id']
                ?? $platformResult['collector_task_id']
                ?? 0
            ));
            $executionOwnerUserId = max(0, (int)(
                $platformResult['execution_owner_user_id'] ?? 0
            ));
            $syncTaskId = (int)(
                $readback['sync_task_id']
                ?? $platformResult['platform_sync_task_id']
                ?? ($ingestionMethod === 'local_collector'
                    ? 0
                    : ($platformResult['task_id'] ?? 0))
            );
            $receiptHotelId = (int)($readback['system_hotel_id'] ?? 0);
            $receiptDate = substr(trim((string)($readback['target_date'] ?? '')), 0, 10);
            $platform = strtolower(trim((string)($readback['platform'] ?? $platformResult['platform'] ?? '')));
            $p0Status = strtolower(trim((string)($readback['p0_status'] ?? '')));
            $dispatcherRunId = strtolower(trim((string)($readback['dispatcher_run_id'] ?? '')));
            if ($dispatcherRunId === '') {
                $dispatcherRunId = strtolower(trim((string)($platformResult['dispatcher_run_id'] ?? '')));
            }
            if (preg_match(
                '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',
                $dispatcherRunId
            ) !== 1) {
                $dispatcherRunId = '';
            }
            $triggerType = strtolower(trim((string)($readback['trigger_type'] ?? '')));
            if (preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $triggerType) !== 1) {
                $triggerType = '';
            }
            $startedAt = trim((string)($readback['started_at'] ?? ''));
            $historicalCoreStatus = $dataPeriod === 'historical_daily'
                ? strtolower(trim((string)($platformResult['historical_core_contract_status'] ?? '')))
                : 'not_required';
            if (!in_array($historicalCoreStatus, ['ready', 'blocked', 'not_required'], true)) {
                $historicalCoreStatus = 'not_ready';
            }
            $platformResultHotelId = max(0, (int)($platformResult['system_hotel_id'] ?? 0));
            $platformResultDate = substr(trim((string)($platformResult['target_date'] ?? '')), 0, 10);
            $platformResultStatus = $safeOperationalCode(
                $platformResult['source_task_status'] ?? $platformResult['status'] ?? ''
            );
            $failureReason = $safeOperationalCode($platformResult['failure_reason'] ?? '');
            if ($failureReason === '') {
                $failureReason = $safeOperationalCode($platformResult['message'] ?? '');
            }
            if ($failureReason === '' && $historicalCoreStatus === 'blocked') {
                $failureReason = 'historical_core_contract_incomplete';
            }
            $platformReadbackVerified = ($platformResult['readback_verified'] ?? false) === true
                || ($readback['readback_verified'] ?? false) === true;
            $platformReadbackCount = max(
                0,
                (int)($platformResult['readback_count'] ?? count(
                    is_array($readback['row_ids'] ?? null) ? $readback['row_ids'] : []
                ))
            );
            $failedScopeMatches = $dataSourceId > 0
                && ($syncTaskId > 0
                    || ($ingestionMethod === 'local_collector' && $localCollectorTaskId > 0))
                && in_array($platform, self::REQUIRED_DAILY_PLATFORMS, true)
                && ($sourceIds === [] || in_array($dataSourceId, $sourceIds, true))
                && $platformResultHotelId === $hotelId
                && $platformResultDate === $targetDate;
            if (empty($platformResult['success']) && $failedScopeMatches) {
                $failedTask = [
                    'data_source_id' => $dataSourceId,
                    'sync_task_id' => $syncTaskId > 0 ? $syncTaskId : null,
                    'platform' => $platform,
                    'target_date' => $targetDate,
                    'status' => $platformResultStatus !== '' ? $platformResultStatus : 'failed',
                    'failure_reason' => $failureReason !== '' ? $failureReason : 'collection_not_verified',
                    'readback_count' => $platformReadbackCount,
                    'readback_verified' => $platformReadbackVerified,
                    'historical_core_contract_status' => $historicalCoreStatus,
                ];
                if ($ingestionMethod !== '') {
                    $failedTask['ingestion_method'] = $ingestionMethod;
                }
                if ($localCollectorTaskId > 0) {
                    $failedTask['local_collector_task_id'] = $localCollectorTaskId;
                }
                if ($executionOwnerUserId > 0) {
                    $failedTask['execution_owner_user_id'] = $executionOwnerUserId;
                }
                if ($dispatcherRunId !== '') {
                    $failedTask['dispatcher_run_id'] = $dispatcherRunId;
                }
                $failedSourceTasks[$dataSourceId] = $failedTask;
            }
            $rowIds = array_values(array_unique(array_filter(array_map(
                'intval',
                is_array($readback['row_ids'] ?? null) ? $readback['row_ids'] : []
            ), static fn(int $id): bool => $id > 0)));
            // A task receipt is the source/task/row anchor supplied to the
            // external verifier.  Its local P0 diagnosis can legitimately be
            // stale while the verifier is checking the same persisted rows, so
            // do not discard a valid anchor merely because that preliminary
            // diagnosis is still `partial` or `blocked`.
            $producerIdentityReady = $ingestionMethod === 'local_collector'
                ? ($localCollectorTaskId > 0 && $triggerType === 'local_collector_upload')
                : ($ingestionMethod === 'browser_profile'
                    ? ($localCollectorTaskId === 0 && $triggerType === 'daily_profile_reuse')
                    : true);
            if (($readback['readback_verified'] ?? false) !== true
                || $dataSourceId <= 0
                || $syncTaskId <= 0
                || $receiptHotelId !== $hotelId
                || $receiptDate !== $targetDate
                || !in_array($platform, self::REQUIRED_DAILY_PLATFORMS, true)
                || $rowIds === []
                || !$producerIdentityReady
            ) {
                continue;
            }
            $sourceTask = [
                'data_source_id' => $dataSourceId,
                'sync_task_id' => $syncTaskId,
                'platform' => $platform,
                'collection_status' => !empty($platformResult['success']) ? 'success' : 'partial',
                'p0_status' => $p0Status !== '' ? $p0Status : 'not_ready',
                'historical_core_contract_status' => $historicalCoreStatus,
                'row_ids' => $rowIds,
                'readback_verified' => true,
            ];
            if ($ingestionMethod !== '') {
                $sourceTask['ingestion_method'] = $ingestionMethod;
            }
            if ($localCollectorTaskId > 0) {
                $sourceTask['local_collector_task_id'] = $localCollectorTaskId;
            }
            if ($executionOwnerUserId > 0) {
                $sourceTask['execution_owner_user_id'] = $executionOwnerUserId;
            }
            if ($dispatcherRunId !== '') {
                $sourceTask['dispatcher_run_id'] = $dispatcherRunId;
            }
            if ($triggerType !== '') {
                $sourceTask['trigger_type'] = $triggerType;
            }
            if ($startedAt !== '') {
                $sourceTask['started_at'] = $startedAt;
            }
            $sourceTasks[$dataSourceId] = $sourceTask;
            if (!empty($platformResult['success'])) {
                unset($failedSourceTasks[$dataSourceId]);
            }
        }
        ksort($sourceTasks, SORT_NUMERIC);
        ksort($failedSourceTasks, SORT_NUMERIC);
        $receiptSourceIds = array_map('intval', array_keys($sourceTasks));
        $expectedSourceIds = $sourceIds === [] ? $receiptSourceIds : $sourceIds;
        $requiredPlatforms = $this->platformList(
            $outcome['required_platforms'] ?? self::REQUIRED_DAILY_PLATFORMS
        );
        if ($requiredPlatforms === []) {
            $requiredPlatforms = self::REQUIRED_DAILY_PLATFORMS;
        }
        $receiptPlatforms = array_values(array_unique(array_column($sourceTasks, 'platform')));
        $exportableSnapshotComplete = $expectedSourceIds !== []
            && $receiptSourceIds === $expectedSourceIds
            && array_diff($requiredPlatforms, $receiptPlatforms) === [];
        $authorityRequired = $dataPeriod === 'historical_daily';
        $historicalCoreContractComplete = !$authorityRequired || (
            count($sourceTasks) === count($requiredPlatforms)
            && array_values(array_filter(
                $sourceTasks,
                static fn(array $task): bool =>
                    ($task['historical_core_contract_status'] ?? '') !== 'ready'
            )) === []
        );
        $collectionComplete = !empty($outcome['complete'])
            && $exportableSnapshotComplete
            && $historicalCoreContractComplete;
        $authorityScopeComplete = $collectionComplete && !$authorityRequired;
        // A complete two-source/task/row projection may be hashed before the
        // external P0 authority settles a stale local P0 diagnosis. Missing or
        // failed source facts never enter that projection and therefore never
        // receive an anchor merely because a failure sidecar exists.
        $anchorMaterialComplete = $exportableSnapshotComplete
            && $historicalCoreContractComplete
            && count($sourceTasks) === count($requiredPlatforms);
        $collectionAnchorHash = $anchorMaterialComplete
            ? OtaCollectionAnchorService::hash(array_values($sourceTasks))
            : '';

        return [
            'schema_version' => 3,
            'hotel_id' => $hotelId,
            'target_date' => $targetDate,
            'data_period' => $dataPeriod,
            'source_ids' => $expectedSourceIds,
            'collection_anchor_contract_version' => OtaCollectionAnchorService::CONTRACT_VERSION,
            'collection_anchor_hash' => $collectionAnchorHash,
            'required_platforms' => $requiredPlatforms,
            'status' => (string)($outcome['status'] ?? ''),
            'collection_complete' => $collectionComplete,
            'exportable_snapshot_complete' => $exportableSnapshotComplete,
            'historical_core_contract_complete' => $historicalCoreContractComplete,
            'authority_verifier_required' => $authorityRequired,
            'authority_verifier' => [
                'verification_source' => $authorityRequired
                    ? 'external_p0_verifier'
                    : 'not_applicable',
                'status' => $authorityRequired ? 'not_run' : 'not_applicable',
                'authority_ready' => !$authorityRequired,
                'target_date' => $targetDate,
                'hotel_id' => $hotelId,
                'required_platforms' => $requiredPlatforms,
                'verified_platforms' => [],
                'collection_anchor_hash' => '',
                'issue_codes' => $authorityRequired
                    ? ['p0_authority_verifier_not_run']
                    : [],
                'sensitive_values_exposed' => false,
            ],
            'authority_scope_complete' => $authorityScopeComplete,
            'dual_ota_p0_complete' => $authorityScopeComplete
                && $requiredPlatforms === self::REQUIRED_DAILY_PLATFORMS,
            'source_tasks' => array_values($sourceTasks),
            'failed_source_tasks' => array_values($failedSourceTasks),
        ];
    }

    /**
     * Attach the bounded output from P0OtaFieldLoopVerifierRunner.
     *
     * The collection receipt remains the database readback proof. The external
     * verifier is an additional authority proof and cannot replace missing
     * source/task/row anchors.
     *
     * @param array<string, mixed> $receipt
     * @param array<string, mixed> $verifier
     * @return array<string, mixed>
     */
    public function attachAuthorityVerifier(array $receipt, array $verifier): array
    {
        $requiredPlatforms = $this->platformList($receipt['required_platforms'] ?? []);
        sort($requiredPlatforms, SORT_STRING);
        $verifiedPlatforms = $this->platformList($verifier['verified_platforms'] ?? []);
        sort($verifiedPlatforms, SORT_STRING);
        $targetDate = substr(trim((string)($receipt['target_date'] ?? '')), 0, 10);
        $hotelId = (int)($receipt['hotel_id'] ?? 0);
        $scopeReady = $targetDate !== ''
            && $hotelId > 0
            && substr(trim((string)($verifier['target_date'] ?? '')), 0, 10) === $targetDate
            && (int)($verifier['hotel_id'] ?? 0) === $hotelId
            && $this->platformList($verifier['required_platforms'] ?? []) === $requiredPlatforms;
        $collectionAnchorHash = strtolower(trim((string)(
            $receipt['collection_anchor_hash'] ?? ''
        )));
        $verifierAnchorHash = strtolower(trim((string)(
            $verifier['collection_anchor_hash'] ?? ''
        )));
        $anchorReady = (string)($receipt['collection_anchor_contract_version'] ?? '')
                === OtaCollectionAnchorService::CONTRACT_VERSION
            && OtaCollectionAnchorService::matches(
                $receipt['source_tasks'] ?? [],
                $collectionAnchorHash
            )
            && $this->hasCompleteSourceTaskAnchor($receipt)
            && hash_equals($collectionAnchorHash, $verifierAnchorHash);
        $storageScopes = is_array($verifier['platform_storage_scopes'] ?? null)
            ? $verifier['platform_storage_scopes']
            : [];
        $syntheticProvenanceMissingRows = 0;
        $observedMetricProvenanceReady = $requiredPlatforms !== [];
        foreach ($requiredPlatforms as $requiredPlatform) {
            $scope = is_array($storageScopes[$requiredPlatform] ?? null)
                ? $storageScopes[$requiredPlatform]
                : [];
            $missingRows = max(0, (int)(
                $scope['synthetic_normalization_provenance_missing_rows'] ?? 0
            ));
            $syntheticProvenanceMissingRows += $missingRows;
            if (strtolower(trim((string)(
                $scope['observed_traffic_metric_provenance_status'] ?? ''
            ))) !== 'ready' || $missingRows !== 0) {
                $observedMetricProvenanceReady = false;
            }
        }
        $authorityReady = ($verifier['authority_ready'] ?? false) === true
            && strtolower(trim((string)($verifier['verification_source'] ?? ''))) === 'external_p0_verifier'
            && strtolower(trim((string)($verifier['status'] ?? ''))) === 'passed'
            && (int)($verifier['exit_code'] ?? -1) === 0
            && $scopeReady
            && $anchorReady
            && $verifiedPlatforms === $requiredPlatforms
            && (int)($verifier['p0_platforms_ready'] ?? -1) === count($requiredPlatforms)
            && (int)($verifier['traffic_gates_ready'] ?? -1) === count($requiredPlatforms)
            && $observedMetricProvenanceReady
            && strtolower(trim((string)($verifier['continuous_trust_status'] ?? ''))) === 'verified'
            && $this->stringList($verifier['continuous_trust_missing_steps'] ?? []) === [];

        $receipt['authority_verifier_required'] = true;
        $receipt['authority_verifier'] = [
            'verification_source' => 'external_p0_verifier',
            'status' => strtolower(trim((string)($verifier['status'] ?? 'failed'))) ?: 'failed',
            'exit_code' => (int)($verifier['exit_code'] ?? 1),
            'authority_ready' => $authorityReady,
            'target_date' => substr(trim((string)($verifier['target_date'] ?? '')), 0, 10),
            'hotel_id' => (int)($verifier['hotel_id'] ?? 0) ?: null,
            'required_platforms' => $this->platformList($verifier['required_platforms'] ?? []),
            'verified_platforms' => $verifiedPlatforms,
            'collection_anchor_hash' => preg_match('/^[a-f0-9]{64}$/D', $verifierAnchorHash) === 1
                ? $verifierAnchorHash
                : '',
            'platform_statuses' => is_array($verifier['platform_statuses'] ?? null)
                ? $verifier['platform_statuses']
                : [],
            'observed_traffic_metric_provenance_status' => $observedMetricProvenanceReady
                ? 'ready'
                : 'synthetic_normalization_provenance_missing',
            'synthetic_normalization_provenance_missing_rows' => $syntheticProvenanceMissingRows,
            'p0_platforms_ready' => max(0, (int)($verifier['p0_platforms_ready'] ?? 0)),
            'traffic_gates_ready' => max(0, (int)($verifier['traffic_gates_ready'] ?? 0)),
            'continuous_trust_status' => strtolower(trim((string)(
                $verifier['continuous_trust_status'] ?? 'partial'
            ))),
            'continuous_trust_missing_steps' => $this->stringList(
                $verifier['continuous_trust_missing_steps'] ?? []
            ),
            'issue_codes' => $this->stringList($verifier['issue_codes'] ?? []),
            'verifier_report_hash' => preg_match(
                '/^[a-f0-9]{64}$/D',
                strtolower(trim((string)($verifier['verifier_report_hash'] ?? '')))
            ) === 1
                ? strtolower(trim((string)$verifier['verifier_report_hash']))
                : '',
            'checked_at' => trim((string)($verifier['checked_at'] ?? '')),
            'sensitive_values_exposed' => false,
        ];
        // The authority verifier checks the exact persisted target-date rows
        // represented by the source-task anchor. When it passes for every
        // required platform it may settle a stale local diagnosis, but the
        // signed source-task projection itself must remain immutable.
        if ($authorityReady && $this->hasCompleteSourceTaskAnchor($receipt)) {
            $receipt['collection_complete'] = true;
            $receipt['exportable_snapshot_complete'] = true;
        }
        $receipt['authority_scope_complete'] = ($receipt['collection_complete'] ?? false) === true
            && ($receipt['exportable_snapshot_complete'] ?? false) === true
            && $authorityReady;
        $receipt['dual_ota_p0_complete'] = ($receipt['authority_scope_complete'] ?? false) === true
            && $requiredPlatforms === self::REQUIRED_DAILY_PLATFORMS;
        $receipt['status'] = ($receipt['authority_scope_complete'] ?? false) === true
            ? 'verified'
            : (($receipt['collection_complete'] ?? false) === true ? 'partial_success' : (string)($receipt['status'] ?? 'failed'));
        return $receipt;
    }

    /** @param array<string,mixed> $receipt */
    private function hasCompleteSourceTaskAnchor(array $receipt): bool
    {
        $historical = strtolower(trim((string)($receipt['data_period'] ?? ''))) === 'historical_daily';
        $expectedSourceIds = array_values(array_unique(array_filter(array_map(
            'intval',
            is_array($receipt['source_ids'] ?? null) ? $receipt['source_ids'] : []
        ), static fn(int $id): bool => $id > 0)));
        sort($expectedSourceIds, SORT_NUMERIC);
        $requiredPlatforms = $this->platformList($receipt['required_platforms'] ?? []);
        sort($requiredPlatforms, SORT_STRING);
        $sourceIds = [];
        $platforms = [];
        foreach (is_array($receipt['source_tasks'] ?? null) ? $receipt['source_tasks'] : [] as $task) {
            if (!is_array($task)) {
                return false;
            }
            $sourceId = (int)($task['data_source_id'] ?? 0);
            $taskId = (int)($task['sync_task_id'] ?? 0);
            $rowIds = array_values(array_filter(array_map(
                'intval',
                is_array($task['row_ids'] ?? null) ? $task['row_ids'] : []
            ), static fn(int $id): bool => $id > 0));
            $platform = strtolower(trim((string)($task['platform'] ?? '')));
            if ($sourceId <= 0 || $taskId <= 0 || $rowIds === []
                || !in_array($platform, $requiredPlatforms, true)
                || ($historical
                    && strtolower(trim((string)($task['historical_core_contract_status'] ?? ''))) !== 'ready')
            ) {
                return false;
            }
            $sourceIds[] = $sourceId;
            $platforms[] = $platform;
        }
        sort($sourceIds, SORT_NUMERIC);
        $platforms = array_values(array_unique($platforms));
        sort($platforms, SORT_STRING);
        return $expectedSourceIds !== []
            && $sourceIds === $expectedSourceIds
            && $platforms === $requiredPlatforms;
    }

    /** @param array<string, mixed> $receipt */
    public function dailyTrustReceiptReady(
        array $receipt,
        ?string $expectedDate = null,
        ?int $expectedHotelId = null,
        ?array $expectedSourceIds = null,
        ?array $expectedPlatforms = null
    ): bool {
        if (!$this->receiptMatchesExpectedScope(
            $receipt,
            $expectedSourceIds,
            $expectedPlatforms
        ) || !$this->collectionAnchorReady($receipt)) {
            return false;
        }
        if (strtolower(trim((string)($receipt['data_period'] ?? ''))) === 'realtime_snapshot') {
            return $this->realtimeTrustReceiptReady($receipt, $expectedDate, $expectedHotelId);
        }
        if (($receipt['collection_complete'] ?? false) !== true
            || ($receipt['exportable_snapshot_complete'] ?? false) !== true
            || ($receipt['historical_core_contract_complete'] ?? false) !== true
            || ($receipt['authority_scope_complete'] ?? false) !== true
            || ($expectedDate !== null
                && substr(trim((string)($receipt['target_date'] ?? '')), 0, 10) !== $expectedDate)
            || ($expectedHotelId !== null && (int)($receipt['hotel_id'] ?? 0) !== $expectedHotelId)
        ) {
            return false;
        }
        $requiredPlatforms = $this->platformList($receipt['required_platforms'] ?? []);
        sort($requiredPlatforms, SORT_STRING);
        if ($requiredPlatforms === []) {
            return false;
        }
        $readyPlatforms = [];
        foreach (is_array($receipt['source_tasks'] ?? null) ? $receipt['source_tasks'] : [] as $task) {
            if (!is_array($task)
                || strtolower(trim((string)(
                    $task['historical_core_contract_status'] ?? ''
                ))) !== 'ready'
                || (int)($task['data_source_id'] ?? 0) <= 0
                || (int)($task['sync_task_id'] ?? 0) <= 0
                || array_values(array_filter(
                    is_array($task['row_ids'] ?? null) ? $task['row_ids'] : [],
                    static fn($value): bool => (int)$value > 0
                )) === []
            ) {
                continue;
            }
            $platform = strtolower(trim((string)($task['platform'] ?? '')));
            if (in_array($platform, $requiredPlatforms, true)) {
                $readyPlatforms[$platform] = true;
            }
        }
        $readyPlatforms = array_keys($readyPlatforms);
        sort($readyPlatforms, SORT_STRING);
        if ($readyPlatforms !== $requiredPlatforms) {
            return false;
        }
        if (($receipt['authority_verifier_required'] ?? false) !== true) {
            return false;
        }
        $verifier = is_array($receipt['authority_verifier'] ?? null)
            ? $receipt['authority_verifier']
            : [];
        $verifiedPlatforms = $this->platformList($verifier['verified_platforms'] ?? []);
        sort($verifiedPlatforms, SORT_STRING);
        return strtolower(trim((string)($verifier['verification_source'] ?? ''))) === 'external_p0_verifier'
            && strtolower(trim((string)($verifier['status'] ?? ''))) === 'passed'
            && ($verifier['authority_ready'] ?? false) === true
            && (int)($verifier['exit_code'] ?? -1) === 0
            && substr(trim((string)($verifier['target_date'] ?? '')), 0, 10)
                === substr(trim((string)($receipt['target_date'] ?? '')), 0, 10)
            && (int)($verifier['hotel_id'] ?? 0) === (int)($receipt['hotel_id'] ?? 0)
            && preg_match(
                '/^[a-f0-9]{64}$/D',
                strtolower(trim((string)($receipt['collection_anchor_hash'] ?? '')))
            ) === 1
            && hash_equals(
                strtolower(trim((string)$receipt['collection_anchor_hash'])),
                strtolower(trim((string)($verifier['collection_anchor_hash'] ?? '')))
            )
            && $verifiedPlatforms === $requiredPlatforms
            && (int)($verifier['p0_platforms_ready'] ?? -1) === count($requiredPlatforms)
            && (int)($verifier['traffic_gates_ready'] ?? -1) === count($requiredPlatforms)
            && strtolower(trim((string)(
                $verifier['observed_traffic_metric_provenance_status'] ?? ''
            ))) === 'ready'
            && (int)($verifier['synthetic_normalization_provenance_missing_rows'] ?? -1) === 0
            && strtolower(trim((string)($verifier['continuous_trust_status'] ?? ''))) === 'verified'
            && $this->stringList($verifier['continuous_trust_missing_steps'] ?? []) === [];
    }

    /** @param array<string, mixed> $receipt */
    private function realtimeTrustReceiptReady(
        array $receipt,
        ?string $expectedDate,
        ?int $expectedHotelId
    ): bool {
        if (($receipt['collection_complete'] ?? false) !== true
            || ($receipt['exportable_snapshot_complete'] ?? false) !== true
            || ($receipt['authority_scope_complete'] ?? false) !== true
            || ($receipt['authority_verifier_required'] ?? true) !== false
            || ($expectedDate !== null
                && substr(trim((string)($receipt['target_date'] ?? '')), 0, 10) !== $expectedDate)
            || ($expectedHotelId !== null && (int)($receipt['hotel_id'] ?? 0) !== $expectedHotelId)
            || !$this->hasCompleteSourceTaskAnchor($receipt)
            || !$this->collectionAnchorReady($receipt)
        ) {
            return false;
        }

        $requiredPlatforms = $this->platformList($receipt['required_platforms'] ?? []);
        sort($requiredPlatforms, SORT_STRING);
        if ($requiredPlatforms === []) {
            return false;
        }
        $readyPlatforms = [];
        foreach (is_array($receipt['source_tasks'] ?? null) ? $receipt['source_tasks'] : [] as $task) {
            if (!is_array($task)
                || strtolower(trim((string)($task['collection_status'] ?? ''))) !== 'success'
                || !in_array(
                    strtolower(trim((string)($task['p0_status'] ?? ''))),
                    ['ready', 'not_required'],
                    true
                )
            ) {
                continue;
            }
            $platform = strtolower(trim((string)($task['platform'] ?? '')));
            if (in_array($platform, $requiredPlatforms, true)) {
                $readyPlatforms[$platform] = true;
            }
        }
        $readyPlatforms = array_keys($readyPlatforms);
        sort($readyPlatforms, SORT_STRING);
        return $readyPlatforms === $requiredPlatforms;
    }

    /** @param array<string,mixed> $receipt */
    private function collectionAnchorReady(array $receipt): bool
    {
        return (string)($receipt['collection_anchor_contract_version'] ?? '')
                === OtaCollectionAnchorService::CONTRACT_VERSION
            && OtaCollectionAnchorService::matches(
                $receipt['source_tasks'] ?? [],
                $receipt['collection_anchor_hash'] ?? ''
            );
    }

    /**
     * @param array<string, mixed> $receipt
     * @param array<int, int>|null $expectedSourceIds
     * @param array<int, string>|null $expectedPlatforms
     */
    private function receiptMatchesExpectedScope(
        array $receipt,
        ?array $expectedSourceIds,
        ?array $expectedPlatforms
    ): bool {
        if ($expectedSourceIds !== null) {
            $expectedSourceIds = $this->positiveIds($expectedSourceIds);
            sort($expectedSourceIds, SORT_NUMERIC);
            $receiptSourceIds = $this->positiveIds($receipt['source_ids'] ?? []);
            sort($receiptSourceIds, SORT_NUMERIC);
            if ($receiptSourceIds !== $expectedSourceIds) {
                return false;
            }
        }
        if ($expectedPlatforms !== null) {
            $expectedPlatforms = $this->platformList($expectedPlatforms);
            $receiptPlatforms = $this->platformList($receipt['required_platforms'] ?? []);
            if ($receiptPlatforms !== $expectedPlatforms) {
                return false;
            }
        }
        return true;
    }

    /**
     * Produce the safe terminal status for the 08:30-09:00 yesterday window.
     *
     * @param array<string, mixed> $receipt
     * @param array<string, mixed> $retryState
     * @return array<string, mixed>
     */
    public function buildYesterdayGapReport(
        array $receipt,
        array $retryState,
        \DateTimeImmutable $now,
        string $cutoffTime = self::DEFAULT_YESTERDAY_CUTOFF_TIME
    ): array {
        $cutoffTime = $this->normalizeTime($cutoffTime) ?? self::DEFAULT_YESTERDAY_CUTOFF_TIME;
        $ready = $this->dailyTrustReceiptReady(
            $receipt,
            substr(trim((string)($receipt['target_date'] ?? '')), 0, 10),
            (int)($receipt['hotel_id'] ?? 0)
        );
        $cutoffReached = $now->format('H:i') >= $cutoffTime;
        $requiredPlatforms = $this->platformList(
            $receipt['required_platforms'] ?? self::REQUIRED_DAILY_PLATFORMS
        );
        if ($requiredPlatforms === []) {
            $requiredPlatforms = self::REQUIRED_DAILY_PLATFORMS;
        }
        $collectedPlatforms = [];
        foreach (is_array($receipt['source_tasks'] ?? null) ? $receipt['source_tasks'] : [] as $task) {
            if (!is_array($task)
                || strtolower(trim((string)($task['collection_status'] ?? ''))) !== 'success'
                || !in_array(
                    strtolower(trim((string)($task['p0_status'] ?? ''))),
                    ['ready', 'not_required'],
                    true
                )
                || (int)($task['data_source_id'] ?? 0) <= 0
                || (int)($task['sync_task_id'] ?? 0) <= 0
                || $this->positiveIds($task['row_ids'] ?? []) === []
            ) {
                continue;
            }
            $platform = strtolower(trim((string)($task['platform'] ?? '')));
            if (in_array($platform, $requiredPlatforms, true)) {
                $collectedPlatforms[$platform] = true;
            }
        }
        $verifier = is_array($receipt['authority_verifier'] ?? null)
            ? $receipt['authority_verifier']
            : [];
        $verifiedPlatforms = $this->platformList($verifier['verified_platforms'] ?? []);
        $missingPlatforms = array_values(array_unique(array_merge(
            array_diff($requiredPlatforms, array_keys($collectedPlatforms)),
            array_diff($requiredPlatforms, $verifiedPlatforms)
        )));
        foreach (array_merge(
            $this->stringList($verifier['continuous_trust_missing_steps'] ?? []),
            $this->stringList($verifier['issue_codes'] ?? [])
        ) as $code) {
            foreach ($requiredPlatforms as $platform) {
                if (str_starts_with($code, $platform . '_')) {
                    $missingPlatforms[] = $platform;
                }
            }
        }
        $missingPlatforms = array_values(array_unique($missingPlatforms));
        sort($missingPlatforms, SORT_STRING);
        if ($ready) {
            $missingPlatforms = [];
        }

        $gapCodes = [];
        if (($receipt['collection_complete'] ?? false) !== true) {
            $gapCodes[] = 'collection_incomplete';
        }
        if (($receipt['exportable_snapshot_complete'] ?? false) !== true) {
            $gapCodes[] = 'source_task_readback_incomplete';
        }
        foreach ($missingPlatforms as $platform) {
            $gapCodes[] = $platform . '_recollection_required';
        }
        foreach ($this->stringList($verifier['continuous_trust_missing_steps'] ?? []) as $step) {
            $gapCodes[] = $step;
        }
        foreach ($this->stringList($verifier['issue_codes'] ?? []) as $code) {
            $gapCodes[] = $code;
        }
        if (!$ready && $gapCodes === []) {
            $gapCodes[] = 'p0_authority_verifier_not_ready';
        }
        $gapCodes = array_values(array_unique($gapCodes));
        sort($gapCodes, SORT_STRING);

        return [
            'schema_version' => 1,
            'status' => $ready ? 'ready' : ($cutoffReached ? 'gap' : 'awaiting_completeness'),
            'report_kind' => $ready ? 'official_ready' : ($cutoffReached ? 'explicit_gap_report' : 'pending_status'),
            'formal_report_allowed' => $ready,
            'hotel_id' => (int)($receipt['hotel_id'] ?? 0) ?: null,
            'target_date' => substr(trim((string)($receipt['target_date'] ?? '')), 0, 10),
            'required_platforms' => $requiredPlatforms,
            'missing_platforms' => $missingPlatforms,
            'gap_codes' => $ready ? [] : $gapCodes,
            'recollection_status' => $ready ? 'not_required' : 'required',
            'recollection_platforms' => $ready ? [] : ($missingPlatforms ?: $requiredPlatforms),
            'window_start' => self::DEFAULT_YESTERDAY_COLLECTION_TIME,
            'cutoff_time' => $cutoffTime,
            'cutoff_reached' => $cutoffReached,
            'retry' => [
                'attempts' => max(0, (int)($retryState['attempts'] ?? 0)),
                'max_attempts' => max(0, (int)($retryState['max_attempts'] ?? 0)),
                'next_retry_at' => trim((string)($retryState['next_retry_at'] ?? '')) ?: null,
                'retry_exhausted' => ($retryState['retry_exhausted'] ?? false) === true,
            ],
            'verification_source' => 'external_p0_verifier',
            'sensitive_values_exposed' => false,
        ];
    }

    public function retryDue(array $state, int $maxAttempts, \DateTimeImmutable $now): bool
    {
        $maxAttempts = $this->normalizeMaxAttempts($maxAttempts);
        if ((int)($state['attempts'] ?? 0) >= $maxAttempts) {
            return false;
        }
        $nextRetryAt = trim((string)($state['next_retry_at'] ?? ''));
        if ($nextRetryAt === '') {
            return true;
        }
        try {
            $nextRetry = new \DateTimeImmutable($nextRetryAt, new \DateTimeZone('Asia/Shanghai'));
        } catch (\Throwable) {
            return true;
        }
        return $nextRetry <= $now;
    }

    /** @return array{attempts: int, max_attempts: int, next_retry_at: ?string, retry_exhausted: bool, last_status: string, last_message: string} */
    public function nextRetryState(
        array $currentState,
        int $maxAttempts,
        int $baseDelayMinutes,
        \DateTimeImmutable $now,
        string $status,
        string $message
    ): array {
        $maxAttempts = $this->normalizeMaxAttempts($maxAttempts);
        $baseDelayMinutes = $this->normalizeDelayMinutes($baseDelayMinutes);
        $attempts = max(0, (int)($currentState['attempts'] ?? 0)) + 1;
        $retryExhausted = $attempts >= $maxAttempts;
        $delayMultiplier = 2 ** min(6, max(0, $attempts - 1));
        $delayMinutes = min(60, $baseDelayMinutes * $delayMultiplier);

        return [
            'attempts' => $attempts,
            'max_attempts' => $maxAttempts,
            'next_retry_at' => $retryExhausted ? null : $now->modify("+{$delayMinutes} minutes")->format('Y-m-d H:i:s'),
            'retry_exhausted' => $retryExhausted,
            'last_status' => trim($status),
            'last_message' => mb_substr(trim($message), 0, 300),
        ];
    }

    private function normalizeTime(string $value): ?string
    {
        if (!preg_match('/^([01]?[0-9]|2[0-3]):([0-5][0-9])$/', trim($value), $matches)) {
            return null;
        }
        return sprintf('%02d:%02d', (int)$matches[1], (int)$matches[2]);
    }

    private function normalizeMinute(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $minute = (int)$value;
        return $minute >= 0 && $minute <= 59 ? $minute : null;
    }

    private function normalizeIntervalHours(mixed $value): int
    {
        return is_numeric($value) ? max(1, min(24, (int)$value)) : 2;
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int)$value !== 0;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }
        return !empty($value);
    }

    /** @return array{slot_id: string, period: string, data_date: string, executed_key: string, retry_key: string, label: string, executed_message: string, target_platforms?: array<int, string>}|null */
    private function pendingRunFromFailure(array $record, int $hotelId, \DateTimeImmutable $now): ?array
    {
        if (!empty($record['retry_exhausted'])) {
            return null;
        }
        $slotId = trim((string)($record['slot_id'] ?? ''));
        $dataDate = trim((string)($record['data_date'] ?? ''));
        $targetPlatforms = self::REQUIRED_DAILY_PLATFORMS;
        if (preg_match('/^historical:(\d{4}-\d{2}-\d{2})$/D', $slotId, $matches) === 1
            && $matches[1] === $dataDate
        ) {
            try {
                $slotDate = new \DateTimeImmutable($dataDate . ' 00:00:00', new \DateTimeZone('Asia/Shanghai'));
            } catch (\Throwable) {
                return null;
            }
            $ageSeconds = $now->getTimestamp() - $slotDate->getTimestamp();
            if ($ageSeconds < 0 || $ageSeconds > 7 * 86400) {
                return null;
            }
            $run = [
                'slot_id' => $slotId,
                'period' => 'historical_daily',
                'data_date' => $dataDate,
                'executed_key' => "online_data_historical_executed_{$hotelId}_{$dataDate}",
                'retry_key' => "online_data_historical_retry_{$hotelId}_{$dataDate}",
                'label' => 'historical-retry',
                'executed_message' => '历史补跑窗口已执行',
            ];
            $run['target_platforms'] = $targetPlatforms;
            return $run;
        }
        if (preg_match('/^realtime:(\d{4}-\d{2}-\d{2}):(\d{1,2})$/D', $slotId, $matches) === 1
            && $matches[1] === $dataDate
            && (int)$matches[2] >= 0
            && (int)$matches[2] <= 23
        ) {
            $slotHour = (int)$matches[2];
            try {
                $slotTime = new \DateTimeImmutable(sprintf('%s %02d:00:00', $dataDate, $slotHour), new \DateTimeZone('Asia/Shanghai'));
            } catch (\Throwable) {
                return null;
            }
            $ageSeconds = $now->getTimestamp() - $slotTime->getTimestamp();
            if ($ageSeconds < 0 || $ageSeconds > 6 * 3600) {
                return null;
            }
            $run = [
                'slot_id' => $slotId,
                'period' => 'realtime_snapshot',
                'data_date' => $dataDate,
                'executed_key' => "online_data_realtime_executed_{$hotelId}_{$dataDate}_{$slotHour}",
                'retry_key' => "online_data_realtime_retry_{$hotelId}_{$dataDate}_{$slotHour}",
                'label' => 'realtime-retry',
                'executed_message' => '实时快照补跑窗口已执行',
            ];
            $run['target_platforms'] = $targetPlatforms;
            return $run;
        }
        return null;
    }

    /** @return array<int, string> */
    private function platformList(mixed $platforms): array
    {
        if (!is_array($platforms)) {
            return [];
        }
        $normalized = [];
        foreach ($platforms as $platform) {
            $platform = strtolower(trim((string)$platform));
            if (in_array($platform, ['ctrip', 'meituan'], true)) {
                $normalized[$platform] = true;
            }
        }
        $result = array_keys($normalized);
        sort($result, SORT_STRING);
        return $result;
    }

    /** @return array<int, string> */
    private function stringList(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }
        $result = [];
        foreach ($values as $value) {
            $value = strtolower(trim((string)$value));
            if ($value !== '') {
                $result[$value] = true;
            }
        }
        $result = array_keys($result);
        sort($result, SORT_STRING);
        return $result;
    }

    /** @return array<int, int> */
    private function positiveIds(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }
        return array_values(array_unique(array_filter(
            array_map('intval', $values),
            static fn(int $id): bool => $id > 0
        )));
    }

    /** @param array<string, mixed> $candidate @param array<string, mixed> $current */
    private function profileSourceIsNewer(array $candidate, array $current): bool
    {
        $candidateTime = trim((string)($candidate['last_sync_time'] ?? ''));
        $currentTime = trim((string)($current['last_sync_time'] ?? ''));
        if ($candidateTime !== $currentTime) {
            return $candidateTime > $currentTime;
        }
        return (int)($candidate['id'] ?? PHP_INT_MAX) < (int)($current['id'] ?? PHP_INT_MAX);
    }
}
