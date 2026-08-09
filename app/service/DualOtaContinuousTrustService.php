<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * Evaluates the daily Ctrip + Meituan trust loop from persisted facts only.
 *
 * This service never substitutes another date, another hotel, an unverified
 * write, or a numeric zero for missing evidence.
 */
final class DualOtaContinuousTrustService
{
    private const PLATFORMS = ['ctrip', 'meituan'];
    private const TRUSTED_VALIDATION_STATUSES = [
        'normal', 'available', 'verified', 'valid', 'confirmed', 'approved',
        'passed', 'ok', 'success', 'complete', 'completed', 'readback_verified',
    ];
    private const FAILED_TASK_STATUSES = [
        'failed', 'capture_failed', 'permission_denied', 'collection_failed',
        'login_expired', 'waiting_config', 'profile_session_not_ready',
    ];
    private const REQUIRED_TRAFFIC_METRICS = [
        'ctrip' => [
            'list_exposure',
            'detail_exposure',
            'flow_rate',
            'order_filling_num',
            'order_submit_num',
        ],
        'meituan' => [
            'list_exposure',
            'detail_exposure',
            'flow_rate',
        ],
    ];

    /** @var array<string, array<string, bool>> */
    private array $columns = [];

    /** @return array<string, mixed> */
    public function inspectHotel(int $hotelId, string $startDate, string $endDate): array
    {
        if ($hotelId <= 0) {
            throw new \InvalidArgumentException('hotel_id must be a positive integer.');
        }
        self::assertDateRange($startDate, $endDate);
        if (!$this->tableExists('hotels')) {
            return self::unavailable($hotelId, $startDate, $endDate, 'hotels_table_missing');
        }

        $hotelColumns = $this->tableColumns('hotels');
        $hotelFields = array_values(array_intersect(['id', 'tenant_id', 'name', 'status'], array_keys($hotelColumns)));
        $hotel = Db::name('hotels')
            ->where('id', $hotelId)
            ->field(implode(',', $hotelFields))
            ->find();
        if (!is_array($hotel)) {
            return self::unavailable($hotelId, $startDate, $endDate, 'hotel_not_found');
        }
        $hasProfileBindingTable = $this->tableExists('ota_profile_bindings');
        $hasLocalCollectorBindings = $this->tableExists('ota_local_collector_accounts')
            && $this->tableExists('ota_local_collector_account_hotels');
        if (!$this->tableExists('platform_data_sources')
            || !$this->tableExists('platform_data_sync_tasks')
            || !$this->tableExists('platform_data_raw_records')
            || (!$hasProfileBindingTable && !$hasLocalCollectorBindings)
            || !$this->tableExists('online_daily_data')
        ) {
            return self::unavailable($hotelId, $startDate, $endDate, 'continuous_trust_source_table_missing');
        }

        $sources = $this->loadSources($hotelId);
        $tasks = $this->loadTasks($hotelId);
        $rawRecords = $this->loadRawRecords($tasks);
        $profileBindings = $this->loadProfileBindings($sources);
        $rows = $this->loadRows($hotelId, $startDate, $endDate, $sources);
        $dailyColumns = $this->tableColumns('online_daily_data');

        return self::evaluate(
            $hotel,
            $startDate,
            $endDate,
            $rows,
            $sources,
            $tasks,
            isset($dailyColumns['readback_verified']),
            isset($dailyColumns['validation_status']),
            $rawRecords,
            $profileBindings
        );
    }

    /**
     * Pure evaluator used by the live database adapter and focused tests.
     *
     * @param array<string, mixed> $hotel
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $sources
     * @param array<int, array<string, mixed>> $tasks
     * @param array<int, array<string, mixed>> $rawRecords
     * @param array<int, array<string, mixed>> $profileBindings
     * @return array<string, mixed>
     */
    public static function evaluate(
        array $hotel,
        string $startDate,
        string $endDate,
        array $rows,
        array $sources,
        array $tasks,
        bool $hasReadbackColumn = true,
        bool $hasValidationColumn = true,
        array $rawRecords = [],
        array $profileBindings = []
    ): array {
        self::assertDateRange($startDate, $endDate);
        $hotelId = (int)($hotel['id'] ?? 0);
        $tenantId = (int)($hotel['tenant_id'] ?? 0);
        $days = [];
        $verifiedDays = 0;
        $acceptedDays = 0;

        foreach (array_reverse(self::dateRange($startDate, $endDate)) as $date) {
            $platformRows = [];
            foreach (self::PLATFORMS as $platform) {
                $platformRows[] = self::evaluatePlatformDay(
                    $platform,
                    $date,
                    $hotelId,
                    $tenantId,
                    $rows,
                    $sources,
                    $tasks,
                    $hasReadbackColumn,
                    $hasValidationColumn,
                    $rawRecords,
                    $profileBindings
                );
            }

            $platformStatuses = array_column($platformRows, 'status');
            if ($platformStatuses === ['verified', 'verified']) {
                $dayStatus = 'verified';
                $verifiedDays++;
            } elseif ($platformStatuses === ['collection_failed', 'collection_failed']) {
                $dayStatus = 'collection_failed';
            } else {
                $dayStatus = 'partial';
            }
            $platformAcceptanceStatuses = array_column($platformRows, 'acceptance_status');
            if ($platformAcceptanceStatuses === ['verified', 'verified']) {
                $dayAcceptanceStatus = 'verified';
                $acceptedDays++;
            } elseif (in_array('blocked', $platformAcceptanceStatuses, true)) {
                $dayAcceptanceStatus = 'blocked';
            } elseif ($platformAcceptanceStatuses === ['unverified', 'unverified']) {
                $dayAcceptanceStatus = 'unverified';
            } else {
                $dayAcceptanceStatus = 'partial';
            }
            $days[] = [
                'date' => $date,
                'status' => $dayStatus,
                'acceptance_status' => $dayAcceptanceStatus,
                'platforms' => $platformRows,
            ];
        }

        $consecutiveVerifiedDays = 0;
        foreach ($days as $day) {
            if (($day['status'] ?? '') !== 'verified') {
                break;
            }
            $consecutiveVerifiedDays++;
        }
        $consecutiveAcceptedDays = 0;
        foreach ($days as $day) {
            if (($day['acceptance_status'] ?? '') !== 'verified') {
                break;
            }
            $consecutiveAcceptedDays++;
        }
        $latestStatus = (string)($days[0]['status'] ?? 'partial');
        $status = $verifiedDays === count($days) && $days !== []
            ? 'verified'
            : ($latestStatus === 'collection_failed' ? 'collection_failed' : 'partial');
        $latestAcceptanceStatus = (string)($days[0]['acceptance_status'] ?? 'unverified');
        $acceptanceStatus = $acceptedDays === count($days) && $days !== []
            ? 'verified'
            : (in_array($latestAcceptanceStatus, ['blocked', 'partial', 'unverified'], true)
                ? $latestAcceptanceStatus
                : 'unverified');

        return [
            'schema_version' => 2,
            'metric_scope' => 'ota_channel',
            'hotel_id' => $hotelId > 0 ? $hotelId : null,
            'hotel_name' => trim((string)($hotel['name'] ?? '')),
            'tenant_scope_status' => $tenantId > 0 ? 'verified' : 'partial',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => $tenantId > 0 ? $status : 'partial',
            'acceptance_status' => $tenantId > 0 ? $acceptanceStatus : 'unverified',
            'evaluated_days' => count($days),
            'verified_days' => $verifiedDays,
            'consecutive_verified_days' => $consecutiveVerifiedDays,
            'accepted_days' => $acceptedDays,
            'consecutive_accepted_days' => $consecutiveAcceptedDays,
            'required_platforms' => self::PLATFORMS,
            'required_steps' => [
                'source',
                'account_profile_binding',
                'hotel',
                'date',
                'field_facts',
                'raw_save',
                'organized_save',
                'save',
                'readback',
                'conflict_recollect',
                'page_status',
                'p0',
            ],
            'step_semantics' => [
                'account_profile_binding' => 'active_profile_hash_exact_tenant_hotel_platform_scope',
                'raw_save' => 'platform_data_raw_records_exact_source_task_hotel_platform',
                'organized_save' => 'online_daily_data_normalized_save',
                'save' => 'legacy_alias_of_organized_save',
                'conflict_recollect' => 'identity_or_persistence_conflict_resolved_before_trust',
                'page_status' => 'field_fact_projection_contract',
            ],
            'days' => $days,
            'boundary' => 'Only exact-date, tenant/hotel/account-Profile-bound, raw-saved, organized-saved and database-read-back Ctrip and Meituan facts can become verified. Profile keys and hashes are never returned. page_status confirms the stored field-fact projection contract only; it does not claim that a live browser render was observed. An explicit numeric zero is accepted only with matching field and capture evidence; old rows, defaults and missing values never replace evidence.',
        ];
    }

    /** @return array<string, mixed> */
    public static function unscoped(string $startDate, string $endDate): array
    {
        self::assertDateRange($startDate, $endDate);
        return self::unavailable(null, $startDate, $endDate, 'hotel_scope_required');
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $sources
     * @param array<int, array<string, mixed>> $tasks
     * @param array<int, array<string, mixed>> $rawRecords
     * @param array<int, array<string, mixed>> $profileBindings
     * @return array<string, mixed>
     */
    private static function evaluatePlatformDay(
        string $platform,
        string $date,
        int $hotelId,
        int $tenantId,
        array $rows,
        array $sources,
        array $tasks,
        bool $hasReadbackColumn,
        bool $hasValidationColumn,
        array $rawRecords,
        array $profileBindings
    ): array {
        $platformSources = array_values(array_filter($sources, static function (array $source) use ($platform, $hotelId, $tenantId): bool {
            $sourceTenantId = (int)($source['tenant_id'] ?? 0);
            $sourceHotelId = (int)($source['system_hotel_id'] ?? 0);
            return self::rowPlatform($source) === $platform
                && (int)($source['enabled'] ?? 1) === 1
                && $sourceHotelId === $hotelId
                && $tenantId > 0
                && $sourceTenantId === $tenantId;
        }));
        $sourceIds = array_values(array_filter(array_map(
            static fn(array $source): int => (int)($source['id'] ?? 0),
            $platformSources
        ), static fn(int $id): bool => $id > 0));

        $targetRows = array_values(array_filter($rows, static function (array $row) use ($platform, $date, $sourceIds): bool {
            $sourceId = (int)($row['data_source_id'] ?? 0);
            return substr(trim((string)($row['data_date'] ?? '')), 0, 10) === $date
                && (self::rowPlatform($row) === $platform || ($sourceId > 0 && in_array($sourceId, $sourceIds, true)))
                && !self::retiredSnapshot($row);
        }));
        $scopedRows = array_values(array_filter($targetRows, static function (array $row) use ($hotelId, $tenantId, $sourceIds, $hasValidationColumn): bool {
            return $hasValidationColumn
                && (int)($row['system_hotel_id'] ?? 0) === $hotelId
                && $tenantId > 0
                && (int)($row['tenant_id'] ?? 0) === $tenantId
                && (int)($row['data_source_id'] ?? 0) > 0
                && in_array((int)$row['data_source_id'], $sourceIds, true)
                && self::trustedValidationStatus((string)($row['validation_status'] ?? ''));
        }));
        $trafficRows = array_values(array_filter($scopedRows, static function (array $row) use ($platform): bool {
            $dataType = strtolower(trim((string)($row['data_type'] ?? '')));
            return in_array($dataType, ['traffic', 'flow', 'conversion'], true)
                && OtaTrafficAttributionService::rowBelongsToOwnPlatformTraffic(
                    self::attributionRow($row),
                    $platform
                );
        }));

        $task = self::latestExactDateTask($tasks, $platform, $date, $hotelId, $tenantId, $sourceIds);
        $taskStatus = strtolower(trim((string)($task['status'] ?? '')));
        $taskIngestionMethod = strtolower(trim((string)($task['ingestion_method'] ?? '')));
        $taskStats = self::decodeArray($task['stats_json'] ?? []);
        $taskDiagnostics = is_array($taskStats['sync_diagnostics'] ?? null) ? $taskStats['sync_diagnostics'] : [];
        $taskId = (int)($task['id'] ?? 0);
        $taskSourceId = (int)($task['data_source_id'] ?? 0);
        $scopedTrafficRows = $trafficRows;
        $trafficRows = array_values(array_filter(
            $scopedTrafficRows,
            static fn(array $row): bool => $taskId > 0
                && $taskSourceId > 0
                && (int)($row['sync_task_id'] ?? 0) === $taskId
                && (int)($row['data_source_id'] ?? 0) === $taskSourceId
        ));
        // Ordered browser collection can legitimately finish one core section
        // as partial_success while its saved rows already close every P0
        // traffic fact. The composite closure below still requires binding,
        // raw save, exact-task rows, field facts and DB readback; do not
        // discard that evidence solely because optional sections remain.
        $localP0TaskReady = in_array($taskStatus, ['success', 'partial_success'], true)
            && in_array($taskIngestionMethod, ['browser_profile', 'profile_browser', 'local_collector'], true)
            && (
                ($taskStats['readback_verified'] ?? false) === true
                || (($taskStats['run_readback']['readback_verified'] ?? false) === true)
            );
        $cloudP0TaskReady = $taskStatus === 'success'
            && $taskIngestionMethod === 'cloud_bundle'
            && strtolower(trim((string)($taskStats['collection_status'] ?? ''))) === 'success'
            && ($taskStats['readback_verified'] ?? false) === true;
        $p0TaskReady = $localP0TaskReady || $cloudP0TaskReady;

        $facts = self::trafficFactClosure($platform, $trafficRows);
        $accountBinding = self::profileBindingClosure(
            $platform,
            $hotelId,
            $tenantId,
            $platformSources,
            $profileBindings,
            $taskSourceId
        );
        $rawSave = self::rawSaveClosure(
            $platform,
            $hotelId,
            $tenantId,
            $taskId,
            $taskSourceId,
            $rawRecords
        );
        $sourceReady = $platformSources !== [] && (
            count(array_filter($platformSources, static fn(array $source): bool => in_array(
                strtolower(trim((string)($source['ingestion_method'] ?? ''))),
                ['browser_profile', 'profile_browser', 'local_collector'],
                true
            ))) > 0
            || ($cloudP0TaskReady && count(array_filter(
                $trafficRows,
                static fn(array $row): bool => self::rowCarriesProfileOrigin($row)
            )) === count($trafficRows))
        );
        $hotelReady = $sourceReady
            && $trafficRows !== []
            && (bool)($facts['platform_hotel_identifier_ready'] ?? false);
        $accountBindingReady = (bool)($accountBinding['ready'] ?? false);
        $dateReady = $trafficRows !== [];
        $fieldFactsReady = (bool)($facts['ready'] ?? false);
        $organizedSaveReady = $trafficRows !== [] && count(array_filter($trafficRows, static fn(array $row): bool =>
            (int)($row['id'] ?? 0) > 0
            && (int)($row['data_source_id'] ?? 0) > 0
            && (int)($row['sync_task_id'] ?? 0) > 0
        )) === count($trafficRows);
        $rawSaveReady = (bool)($rawSave['ready'] ?? false);
        $readbackReady = $hasReadbackColumn && $trafficRows !== [] && count(array_filter(
            $trafficRows,
            static fn(array $row): bool => (int)($row['readback_verified'] ?? 0) === 1
        )) === count($trafficRows);
        $organizedScopeConflict = ($targetRows !== [] && $scopedRows === [])
            || ($scopedTrafficRows !== [] && $trafficRows === []);
        $gapCodes = self::gapCodes(
            $sourceReady,
            $accountBinding,
            $hotelReady,
            $dateReady,
            $fieldFactsReady,
            $rawSave,
            $organizedSaveReady,
            $organizedScopeConflict,
            $readbackReady,
            $p0TaskReady,
            (bool)($facts['explicit_required_metric_ready'] ?? false)
        );
        $hasScopeConflict = (bool)($accountBinding['conflict'] ?? false)
            || (bool)($rawSave['conflict'] ?? false)
            || $organizedScopeConflict;
        $recollected = $gapCodes === [] && self::hasEarlierFailedExactDateTask(
            $tasks,
            $platform,
            $date,
            $hotelId,
            $tenantId,
            $sourceIds,
            $taskId
        );
        $conflictRecollectReady = $gapCodes === [];
        $conflictRecollectStatus = $conflictRecollectReady
            ? ($recollected ? 'recollected_and_verified' : 'not_required')
            : ($hasScopeConflict ? 'conflict_recollect_required' : 'recollect_required');
        $pageProjectionReady = $fieldFactsReady && (bool)($facts['ui_status_ready'] ?? false) && $readbackReady;
        $p0Ready = $p0TaskReady
            && $accountBindingReady
            && $fieldFactsReady
            && $rawSaveReady
            && $organizedSaveReady
            && $readbackReady
            && $conflictRecollectReady
            && (bool)($facts['explicit_required_metric_ready'] ?? false)
            && (bool)($facts['platform_hotel_identifier_ready'] ?? false);

        $steps = [
            'source' => $sourceReady,
            'account_profile_binding' => $accountBindingReady,
            'hotel' => $hotelReady,
            'date' => $dateReady,
            'field_facts' => $fieldFactsReady,
            'raw_save' => $rawSaveReady,
            'organized_save' => $organizedSaveReady,
            'save' => $organizedSaveReady,
            'readback' => $readbackReady,
            'conflict_recollect' => $conflictRecollectReady,
            'page_status' => $pageProjectionReady,
            'p0' => $p0Ready,
        ];
        $missingSteps = array_keys(array_filter($steps, static fn(bool $ready): bool => !$ready));
        $collectionFailed = in_array($taskStatus, self::FAILED_TASK_STATUSES, true)
            || ($trafficRows === [] && self::sourceReportsCollectionFailure($platformSources, $date));
        $status = $missingSteps === []
            ? 'verified'
            : ($collectionFailed ? 'collection_failed' : 'partial');
        $acceptanceReceipt = self::buildAcceptanceReceipt(
            $platform,
            $date,
            $hotelId,
            $task,
            $taskStats,
            $taskDiagnostics,
            $platformSources,
            $facts,
            $gapCodes,
            $p0Ready,
            $collectionFailed
        );

        return [
            'platform' => $platform,
            'status' => $status,
            'acceptance_status' => $acceptanceReceipt['status'],
            'acceptance_receipt' => $acceptanceReceipt,
            'target_date' => $date,
            'source_method' => $sourceReady
                ? ($cloudP0TaskReady
                    ? 'cloud_profile_bridge'
                    : ($taskIngestionMethod === 'local_collector' ? 'local_account_profile' : 'browser_profile'))
                : null,
            'data_source_ids' => $sourceIds,
            'sync_task_id' => $taskId ?: null,
            'sync_task_status' => $taskStatus !== '' ? $taskStatus : null,
            'steps' => $steps,
            'missing_steps' => $missingSteps,
            'required_metric_keys' => self::REQUIRED_TRAFFIC_METRICS[$platform],
            'complete_metric_keys' => $facts['complete_metric_keys'],
            'missing_metric_keys' => $facts['missing_metric_keys'],
            'gap_codes' => $gapCodes,
            'conflict_recollect_status' => $conflictRecollectStatus,
            'page_status_evidence' => [
                'contract' => 'field_fact_projection_contract',
                'status' => $pageProjectionReady ? 'ready' : 'partial',
                'live_page_verification_status' => 'not_evaluated',
                'message' => $pageProjectionReady
                    ? 'Stored field facts satisfy the page projection contract; no live browser render is asserted.'
                    : 'Stored field facts do not yet satisfy the page projection contract; no live browser render is asserted.',
            ],
            'p0_status' => $p0Ready ? 'ready' : 'blocked',
            'failure_reason' => $collectionFailed
                ? (trim((string)($task['message'] ?? '')) ?: 'target_date_collection_failed')
                : null,
        ];
    }

    /**
     * Project one safe, exact-task acceptance receipt for the selected platform
     * and business date. Counts absent from the task remain null; zero is kept
     * only when the persisted task explicitly returned zero.
     *
     * @param array<string, mixed> $task
     * @param array<string, mixed> $taskStats
     * @param array<string, mixed> $taskDiagnostics
     * @param array<int, array<string, mixed>> $platformSources
     * @param array<string, mixed> $facts
     * @param array<int, string> $gapCodes
     * @return array<string, mixed>
     */
    private static function buildAcceptanceReceipt(
        string $platform,
        string $date,
        int $hotelId,
        array $task,
        array $taskStats,
        array $taskDiagnostics,
        array $platformSources,
        array $facts,
        array $gapCodes,
        bool $p0Ready,
        bool $collectionFailed
    ): array {
        $taskId = (int)($task['id'] ?? 0);
        $sourceId = (int)($task['data_source_id'] ?? 0);
        $taskSource = [];
        foreach ($platformSources as $source) {
            if ((int)($source['id'] ?? 0) === $sourceId) {
                $taskSource = $source;
                break;
            }
        }
        $platformHotelId = self::sourcePlatformHotelId($taskSource);
        $runReadback = is_array($taskStats['run_readback'] ?? null)
            ? $taskStats['run_readback']
            : [];

        $collectionResult = (new CollectionResultContractService())->fromOtaRunReadback(
            $taskStats,
            [
                'tenant_id' => (int)($task['tenant_id'] ?? $taskSource['tenant_id'] ?? 0),
                'system_hotel_id' => $hotelId,
                'platform' => $platform,
                'platform_hotel_id' => $platformHotelId,
                // This projection evaluates the required OTA traffic field
                // loop even when the reusable Profile source is configured as
                // `all` or `business` for other sections.
                'business_module' => 'traffic',
                'source_method' => trim((string)($task['ingestion_method'] ?? $taskSource['ingestion_method'] ?? '')),
                'status' => trim((string)($task['status'] ?? '')),
                'saved_count' => self::nullableTaskCount($taskStats, 'saved_count'),
                'normalized_count' => self::nullableTaskCount($taskStats, 'normalized_count'),
                'task_id' => $taskId > 0 ? $taskId : null,
                'data_source_id' => $sourceId > 0 ? $sourceId : null,
                'target_date' => $date,
            ]
        );

        $taskSavedCount = self::nullableTaskCount($taskStats, 'saved_count');
        $taskReadbackCount = self::nullableTaskCount($taskStats, 'readback_count');
        $taskReadbackVerified = array_key_exists('readback_verified', $taskStats)
            ? ($taskStats['readback_verified'] === true)
            : null;
        $runRowIds = array_key_exists('row_ids', $runReadback) && is_array($runReadback['row_ids'])
            ? array_values(array_unique(array_filter(array_map(
                static fn($value): int => max(0, (int)$value),
                $runReadback['row_ids']
            ))))
            : null;
        $targetSavedCount = is_array($runRowIds) ? count($runRowIds) : null;
        $targetReadbackCount = self::nullableTaskCount($runReadback, 'readback_count');
        $targetReadbackVerified = array_key_exists('readback_verified', $runReadback)
            ? ($runReadback['readback_verified'] === true)
            : null;
        $taskCountsMatch = $taskSavedCount !== null
            && $taskReadbackCount !== null
            && $taskSavedCount > 0
            && $taskSavedCount === $taskReadbackCount
            && $taskReadbackVerified === true;
        $targetCountsMatch = $targetSavedCount !== null
            && $targetReadbackCount !== null
            && $targetSavedCount > 0
            && $targetSavedCount === $targetReadbackCount
            && $targetReadbackVerified === true;

        $receiptTargetDate = substr(trim((string)($runReadback['target_date'] ?? '')), 0, 10);
        $declaredTargetDate = substr(trim((string)($taskDiagnostics['target_date'] ?? '')), 0, 10);
        $targetDateStatus = $receiptTargetDate !== ''
            ? ($receiptTargetDate === $date ? 'matched' : 'mismatch')
            : ($declaredTargetDate !== '' && $declaredTargetDate !== $date ? 'mismatch' : 'unverified');
        $completeMetricKeys = array_values(array_filter(array_map(
            static fn($value): string => strtolower(trim((string)$value)),
            is_array($facts['complete_metric_keys'] ?? null) ? $facts['complete_metric_keys'] : []
        )));
        $missingMetricKeys = array_values(array_filter(array_map(
            static fn($value): string => strtolower(trim((string)$value)),
            is_array($facts['missing_metric_keys'] ?? null) ? $facts['missing_metric_keys'] : []
        )));

        $reasonCodes = [];
        foreach ([$collectionResult['blockers'] ?? [], $gapCodes] as $codes) {
            foreach (is_array($codes) ? $codes : [] as $code) {
                $normalized = strtolower(trim((string)$code));
                if ($normalized !== '' && preg_match('/^[a-z0-9][a-z0-9._:-]{0,159}$/D', $normalized) === 1) {
                    $reasonCodes[] = $normalized;
                }
            }
        }
        if ($targetDateStatus === 'mismatch') {
            $reasonCodes[] = 'target_date_mismatch';
        }
        if (!$taskCountsMatch || !$targetCountsMatch) {
            $reasonCodes[] = 'saved_readback_count_unverified';
        }
        if ($missingMetricKeys !== []) {
            $reasonCodes[] = 'critical_fields_incomplete';
        }
        if (!$p0Ready) {
            $reasonCodes[] = 'p0_not_ready';
        }
        $reasonCodes = array_values(array_unique($reasonCodes));

        $contractClaimAllowed = ($collectionResult['claim']['allowed'] ?? false) === true;
        $claimAllowed = $contractClaimAllowed
            && $p0Ready
            && $taskCountsMatch
            && $targetCountsMatch
            && $targetDateStatus === 'matched'
            && $missingMetricKeys === [];
        $hasBlockingReason = $collectionFailed || $targetDateStatus === 'mismatch';
        $hasUnverifiedReason = false;
        foreach ($reasonCodes as $reasonCode) {
            if (str_starts_with($reasonCode, 'account_profile_binding_')
                || str_contains($reasonCode, 'hotel_binding')
                || str_contains($reasonCode, 'hotel_identity_mismatch')
                || str_contains($reasonCode, 'scope_conflict')
                || in_array($reasonCode, [
                    'target_date_data_missing',
                    'target_date_scope_mismatch',
                    'target_date_mismatch',
                    'binding_missing',
                    'collection_outcome_not_success',
                ], true)
            ) {
                $hasBlockingReason = true;
            }
            if (in_array($reasonCode, [
                'collection_strategy_unverified',
                'structured_response_required',
                'database_readback_not_verified',
                'readback_mismatch',
                'saved_readback_count_unverified',
                'raw_save_missing',
                'raw_save_payload_incomplete',
                'organized_save_missing',
            ], true)) {
                $hasUnverifiedReason = true;
            }
        }
        if ($claimAllowed) {
            $status = 'verified';
        } elseif ($hasBlockingReason) {
            $status = 'blocked';
        } elseif ($hasUnverifiedReason
            || !$taskCountsMatch
            || !$targetCountsMatch
            || $targetDateStatus !== 'matched'
        ) {
            $status = 'unverified';
        } elseif ($missingMetricKeys !== [] || !$p0Ready) {
            $status = 'partial';
        } else {
            $status = 'unverified';
        }

        $strategy = is_array($collectionResult['run']['strategy'] ?? null)
            ? $collectionResult['run']['strategy']
            : [];
        $identityStatus = strtolower(trim((string)($collectionResult['identity_status'] ?? '')));
        $capturedAt = trim((string)(
            $collectionResult['run']['collected_at']
            ?? $task['started_at']
            ?? $task['finished_at']
            ?? ''
        ));
        $finishedAt = trim((string)($task['finished_at'] ?? $task['update_time'] ?? ''));

        return [
            'status' => $status,
            'system_hotel_id' => $hotelId > 0 ? $hotelId : null,
            'platform' => $platform,
            'platform_hotel_id' => $platformHotelId !== '' ? $platformHotelId : null,
            'observed_platform_hotel_id' => trim((string)($runReadback['observed_platform_hotel_id'] ?? '')) ?: null,
            'platform_hotel_status' => $identityStatus === 'matched' ? 'verified' : ($platformHotelId === '' ? 'blocked' : 'unverified'),
            'target_date' => $date,
            'observed_target_date' => $receiptTargetDate !== '' ? $receiptTargetDate : null,
            'declared_target_date' => $declaredTargetDate !== '' ? $declaredTargetDate : null,
            'target_date_status' => $targetDateStatus,
            'captured_at' => $capturedAt !== '' ? $capturedAt : null,
            'finished_at' => $finishedAt !== '' ? $finishedAt : null,
            'source_method' => trim((string)($collectionResult['scope']['source_method'] ?? '')) ?: null,
            'capture_strategy' => [
                'selected' => trim((string)($strategy['selected'] ?? '')) ?: 'not_recorded',
                'status' => trim((string)($strategy['status'] ?? '')) ?: 'unverified',
                'response_evidence_type' => trim((string)($strategy['response_evidence_type'] ?? '')) ?: null,
            ],
            'data_source_id' => $sourceId > 0 ? $sourceId : null,
            'sync_task_id' => $taskId > 0 ? $taskId : null,
            'sync_task_status' => trim((string)($task['status'] ?? '')) ?: null,
            'data_period' => trim((string)($runReadback['data_period'] ?? '')) ?: null,
            'counts' => [
                'normalized' => self::nullableTaskCount($taskStats, 'normalized_count'),
                'saved' => $taskSavedCount,
                'readback' => $taskReadbackCount,
                'saved_readback_match' => $taskCountsMatch,
                'target_saved' => $targetSavedCount,
                'target_readback' => $targetReadbackCount,
                'target_saved_readback_match' => $targetCountsMatch,
            ],
            'critical_fields' => [
                'required' => self::REQUIRED_TRAFFIC_METRICS[$platform],
                'complete' => $completeMetricKeys,
                'missing' => $missingMetricKeys,
                'status' => $missingMetricKeys === [] && $completeMetricKeys !== []
                    ? 'verified'
                    : ($taskId > 0 ? 'partial' : 'unverified'),
            ],
            'contract_claim_allowed' => $contractClaimAllowed,
            'claim_allowed' => $claimAllowed,
            'reason_codes' => $reasonCodes,
            'failure_reason' => $collectionFailed
                ? (trim((string)($task['message'] ?? '')) ?: 'target_date_collection_failed')
                : null,
            'live_page_verification_status' => 'not_evaluated',
        ];
    }

    /** @param array<string, mixed> $values */
    private static function nullableTaskCount(array $values, string $key): ?int
    {
        if (!array_key_exists($key, $values) || !is_numeric($values[$key])) {
            return null;
        }
        return max(0, (int)$values[$key]);
    }

    /** @param array<string, mixed> $source */
    private static function sourcePlatformHotelId(array $source): string
    {
        $config = self::decodeArray($source['config_json'] ?? $source['config'] ?? []);
        foreach ([
            'external_hotel_id', 'hotel_id', 'hotelId', 'ota_hotel_id', 'otaHotelId',
            'ctrip_hotel_id', 'ctripHotelId', 'platform_hotel_id', 'platformHotelId',
            'store_id', 'storeId', 'poi_id', 'poiId',
        ] as $key) {
            foreach ([$source, $config] as $candidate) {
                $value = trim((string)($candidate[$key] ?? ''));
                if ($value !== '' && mb_strlen($value) <= 120) {
                    return $value;
                }
            }
        }
        return '';
    }

    /**
     * Profile keys and hashes are intentionally kept inside this method.
     *
     * @param array<int, array<string, mixed>> $sources
     * @param array<int, array<string, mixed>> $bindings
     * @return array{ready:bool,conflict:bool,code:string}
     */
    private static function profileBindingClosure(
        string $platform,
        int $hotelId,
        int $tenantId,
        array $sources,
        array $bindings,
        int $taskSourceId
    ): array {
        $candidateSources = array_values(array_filter(
            $sources,
            static fn(array $source): bool => $taskSourceId <= 0
                || (int)($source['id'] ?? 0) === $taskSourceId
        ));
        if ($candidateSources === []) {
            return ['ready' => false, 'conflict' => false, 'code' => 'account_profile_source_missing'];
        }

        foreach ($candidateSources as $source) {
            $config = self::decodeArray($source['config_json'] ?? []);
            $profileHash = self::sourceProfileHash($platform, $config);
            if ($profileHash === '') {
                continue;
            }

            $sameIdentity = array_values(array_filter(
                $bindings,
                static fn(array $binding): bool =>
                    self::rowPlatform($binding) === $platform
                    && strtolower(trim((string)($binding['profile_key_hash'] ?? ''))) === $profileHash
            ));
            $active = array_values(array_filter(
                $sameIdentity,
                static fn(array $binding): bool =>
                    strtolower(trim((string)($binding['binding_status'] ?? ''))) === 'active'
            ));
            if (count($active) !== 1) {
                if (count($active) > 1) {
                    return ['ready' => false, 'conflict' => true, 'code' => 'account_profile_binding_ambiguous'];
                }
                if ($sameIdentity !== []) {
                    return ['ready' => false, 'conflict' => false, 'code' => 'account_profile_binding_not_active'];
                }
                continue;
            }

            $binding = $active[0];
            if ((int)($binding['tenant_id'] ?? 0) !== $tenantId
                || (int)($binding['system_hotel_id'] ?? 0) !== $hotelId
            ) {
                return ['ready' => false, 'conflict' => true, 'code' => 'account_profile_binding_scope_conflict'];
            }
            return ['ready' => true, 'conflict' => false, 'code' => ''];
        }

        return ['ready' => false, 'conflict' => false, 'code' => 'account_profile_binding_missing'];
    }

    /**
     * @param array<int, array<string, mixed>> $rawRecords
     * @return array{ready:bool,conflict:bool,code:string}
     */
    private static function rawSaveClosure(
        string $platform,
        int $hotelId,
        int $tenantId,
        int $taskId,
        int $sourceId,
        array $rawRecords
    ): array {
        if ($tenantId <= 0 || $taskId <= 0 || $sourceId <= 0) {
            return ['ready' => false, 'conflict' => false, 'code' => 'raw_save_task_or_source_missing'];
        }
        $taskRecords = array_values(array_filter(
            $rawRecords,
            static fn(array $record): bool => (int)($record['sync_task_id'] ?? 0) === $taskId
        ));
        $exact = array_values(array_filter(
            $taskRecords,
            static fn(array $record): bool =>
                (int)($record['tenant_id'] ?? 0) === $tenantId
                && (int)($record['data_source_id'] ?? 0) === $sourceId
                && (int)($record['system_hotel_id'] ?? 0) === $hotelId
                && self::rowPlatform($record) === $platform
        ));
        if ($exact === []) {
            return [
                'ready' => false,
                'conflict' => $taskRecords !== [],
                'code' => $taskRecords !== [] ? 'raw_save_scope_conflict' : 'raw_save_missing',
            ];
        }
        foreach ($exact as $record) {
            if (trim((string)($record['payload_hash'] ?? '')) !== ''
                && trim((string)($record['raw_payload'] ?? '')) !== ''
                && trim((string)($record['received_at'] ?? '')) !== ''
            ) {
                return ['ready' => true, 'conflict' => false, 'code' => ''];
            }
        }
        return ['ready' => false, 'conflict' => false, 'code' => 'raw_save_payload_incomplete'];
    }

    /** @param array<string, mixed> $config */
    private static function sourceProfileKey(string $platform, array $config): string
    {
        $keys = $platform === 'meituan'
            ? ['store_id', 'storeId', 'profile_id', 'profileId']
            : ['profile_id', 'profileId'];
        foreach ($keys as $key) {
            if (is_scalar($config[$key] ?? null) && trim((string)$config[$key]) !== '') {
                return trim((string)$config[$key]);
            }
        }
        return '';
    }

    private static function profileKeyHash(string $profileKey): string
    {
        if (trim($profileKey) === '') {
            return '';
        }
        $canonical = BrowserProfileCaptureRequestService::safeFilePart(trim($profileKey));
        if ($canonical === '' || $canonical === 'default') {
            return '';
        }
        return hash('sha256', $canonical);
    }

    /** @param array<string, mixed> $config */
    private static function sourceProfileHash(string $platform, array $config): string
    {
        $storedHash = strtolower(trim((string)($config['profile_key_hash'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/D', $storedHash) === 1) {
            return $storedHash;
        }
        return self::profileKeyHash(self::sourceProfileKey($platform, $config));
    }

    /**
     * @param array{ready:bool,conflict:bool,code:string} $accountBinding
     * @param array{ready:bool,conflict:bool,code:string} $rawSave
     * @return array<int, string>
     */
    private static function gapCodes(
        bool $sourceReady,
        array $accountBinding,
        bool $hotelReady,
        bool $dateReady,
        bool $fieldFactsReady,
        array $rawSave,
        bool $organizedSaveReady,
        bool $organizedScopeConflict,
        bool $readbackReady,
        bool $p0TaskReady,
        bool $explicitMetricReady
    ): array {
        $codes = [];
        if (!$sourceReady) {
            $codes[] = 'profile_source_not_ready';
        }
        if (($accountBinding['ready'] ?? false) !== true) {
            $codes[] = (string)($accountBinding['code'] ?? 'account_profile_binding_missing');
        }
        if (!$hotelReady) {
            $codes[] = 'hotel_binding_not_ready';
        }
        if (!$dateReady) {
            $codes[] = 'target_date_data_missing';
        }
        if (!$fieldFactsReady) {
            $codes[] = 'field_facts_incomplete';
        }
        if (($rawSave['ready'] ?? false) !== true) {
            $codes[] = (string)($rawSave['code'] ?? 'raw_save_missing');
        }
        if (!$organizedSaveReady) {
            $codes[] = $organizedScopeConflict ? 'organized_save_scope_conflict' : 'organized_save_missing';
        }
        if (!$readbackReady) {
            $codes[] = 'database_readback_not_verified';
        }
        if (!$p0TaskReady) {
            $codes[] = 'p0_task_receipt_not_ready';
        }
        if (!$explicitMetricReady) {
            $codes[] = 'required_metric_explicit_evidence_missing';
        }
        return array_values(array_unique(array_filter(
            $codes,
            static fn(string $code): bool => $code !== ''
        )));
    }

    /**
     * @param array<int, array<string, mixed>> $tasks
     * @param array<int, int> $sourceIds
     */
    private static function hasEarlierFailedExactDateTask(
        array $tasks,
        string $platform,
        string $date,
        int $hotelId,
        int $tenantId,
        array $sourceIds,
        int $latestTaskId
    ): bool {
        foreach ($tasks as $task) {
            if ((int)($task['id'] ?? 0) === $latestTaskId
                || self::rowPlatform($task) !== $platform
                || (int)($task['system_hotel_id'] ?? 0) !== $hotelId
                || (int)($task['tenant_id'] ?? 0) !== $tenantId
                || !in_array((int)($task['data_source_id'] ?? 0), $sourceIds, true)
            ) {
                continue;
            }
            $stats = self::decodeArray($task['stats_json'] ?? []);
            $diagnostics = is_array($stats['sync_diagnostics'] ?? null) ? $stats['sync_diagnostics'] : [];
            $readback = is_array($stats['run_readback'] ?? null) ? $stats['run_readback'] : [];
            $taskDate = substr(trim((string)(
                $diagnostics['target_date']
                ?? $readback['target_date']
                ?? $stats['target_date']
                ?? ($stats['collection_quality']['target_date'] ?? '')
            )), 0, 10);
            if ($taskDate === $date
                && in_array(strtolower(trim((string)($task['status'] ?? ''))), self::FAILED_TASK_STATUSES, true)
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private static function trafficFactClosure(string $platform, array $rows): array
    {
        $required = self::REQUIRED_TRAFFIC_METRICS[$platform];
        $expectedStorage = [];
        foreach ($required as $metricKey) {
            $expectedStorage[$metricKey] = 'online_daily_data.' . $metricKey;
        }

        $complete = [];
        $allUiReady = $rows !== [];
        $allIdentifiersReady = $rows !== [];
        $explicitRows = 0;
        $nonzeroRows = 0;
        foreach ($rows as $row) {
            $evidenceRow = self::evidenceRow($row);
            $raw = self::decodeArray($evidenceRow['raw_data'] ?? []);
            $rowTraceId = trim((string)($evidenceRow['source_trace_id'] ?? $raw['source_trace_id'] ?? ''));
            $rowEvidence = is_array($raw['capture_evidence'] ?? null) ? $raw['capture_evidence'] : [];
            $rowUrlHash = trim((string)($rowEvidence['source_url_hash'] ?? $raw['source_url_hash'] ?? ''));
            $rowComplete = [];

            foreach (is_array($raw['field_facts'] ?? null) ? $raw['field_facts'] : [] as $fact) {
                if (!is_array($fact)) {
                    continue;
                }
                $metricKey = strtolower(trim((string)($fact['metric_key'] ?? '')));
                if (!isset($expectedStorage[$metricKey])) {
                    continue;
                }
                $sourcePath = trim((string)($fact['source_path'] ?? ''));
                $storageField = trim((string)($fact['storage_field'] ?? ''));
                $factEvidence = is_array($fact['capture_evidence'] ?? null) ? $fact['capture_evidence'] : [];
                $factTraceId = trim((string)($factEvidence['source_trace_id'] ?? ''));
                $factUrlHash = trim((string)($factEvidence['source_url_hash'] ?? ''));
                $storedMetricReady = array_key_exists($metricKey, $evidenceRow)
                    && is_numeric($evidenceRow[$metricKey])
                    && is_finite((float)$evidenceRow[$metricKey]);
                $factReady = self::structuredSourcePath($sourcePath)
                    && $storageField === $expectedStorage[$metricKey]
                    && ($fact['stored_value_present'] ?? null) === true
                    && $storedMetricReady
                    && $rowTraceId !== ''
                    && $rowUrlHash !== ''
                    && hash_equals($rowTraceId, $factTraceId)
                    && hash_equals($rowUrlHash, $factUrlHash);
                if ($factReady) {
                    $complete[$metricKey] = true;
                    $rowComplete[$metricKey] = true;
                }
            }

            $rowUiReady = count(array_diff($required, array_keys($rowComplete))) === 0;
            if (!$rowUiReady) {
                $allUiReady = false;
            } else {
                $explicitRows++;
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

            foreach ($required as $metricKey) {
                if (!array_key_exists($metricKey, $row) || $row[$metricKey] === null || $row[$metricKey] === '') {
                    continue;
                }
                if (is_numeric($row[$metricKey]) && abs((float)$row[$metricKey]) > 0.000001) {
                    $nonzeroRows++;
                    break;
                }
            }
        }

        $completeKeys = array_values(array_intersect($required, array_keys($complete)));
        $missingKeys = array_values(array_diff($required, $completeKeys));
        // Ctrip persists one exact capture task as several normalized rows. Its
        // P0 field group is complete when the same task's rows collectively
        // close all required field facts; requiring every row to repeat every
        // field incorrectly rejects the platform's normalized storage shape.
        $uiStatusReady = $platform === 'ctrip'
            ? ($rows !== [] && $missingKeys === [])
            : $allUiReady;
        $explicitRequiredMetricReady = $platform === 'ctrip'
            ? ($rows !== [] && $missingKeys === [])
            : $explicitRows > 0;
        return [
            'ready' => $rows !== [] && $missingKeys === [] && $uiStatusReady,
            'ui_status_ready' => $uiStatusReady,
            'platform_hotel_identifier_ready' => $allIdentifiersReady,
            'explicit_required_metric_ready' => $explicitRequiredMetricReady,
            'nonzero_required_metric_ready' => $nonzeroRows > 0,
            'complete_metric_keys' => $completeKeys,
            'missing_metric_keys' => $missingKeys,
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function attributionRow(array $row): array
    {
        $evidenceRow = self::evidenceRow($row);
        foreach (['platform', 'source', 'data_type', 'dimension', 'compare_type'] as $field) {
            if (!array_key_exists($field, $evidenceRow) && array_key_exists($field, $row)) {
                $evidenceRow[$field] = $row[$field];
            }
        }
        return $evidenceRow;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function evidenceRow(array $row): array
    {
        if (strtolower(trim((string)($row['ingestion_method'] ?? ''))) !== 'cloud_bundle') {
            return $row;
        }
        $wrapper = self::decodeArray($row['raw_data'] ?? []);
        $sourceRow = is_array($wrapper['row'] ?? null) ? $wrapper['row'] : [];
        return $sourceRow !== [] ? $sourceRow : $row;
    }

    /** @param array<string, mixed> $row */
    private static function rowCarriesProfileOrigin(array $row): bool
    {
        $sourceRow = self::evidenceRow($row);
        return in_array(
            strtolower(trim((string)($sourceRow['ingestion_method'] ?? ''))),
            ['browser_profile', 'profile_browser', 'local_collector'],
            true
        );
    }

    /**
     * @param array<int, array<string, mixed>> $tasks
     * @param array<int, int> $sourceIds
     * @return array<string, mixed>
     */
    private static function latestExactDateTask(
        array $tasks,
        string $platform,
        string $date,
        int $hotelId,
        int $tenantId,
        array $sourceIds
    ): array {
        $matches = array_values(array_filter($tasks, static function (array $task) use ($platform, $date, $hotelId, $tenantId, $sourceIds): bool {
            if (self::rowPlatform($task) !== $platform
                || (int)($task['system_hotel_id'] ?? 0) !== $hotelId
                || $tenantId <= 0
                || (int)($task['tenant_id'] ?? 0) !== $tenantId
                || !in_array((int)($task['data_source_id'] ?? 0), $sourceIds, true)
            ) {
                return false;
            }
            $stats = self::decodeArray($task['stats_json'] ?? []);
            $diagnostics = is_array($stats['sync_diagnostics'] ?? null) ? $stats['sync_diagnostics'] : [];
            if (strtolower(trim((string)($diagnostics['p0_status'] ?? ''))) === 'not_required'
                && ($diagnostics['requires_target_date_traffic'] ?? false) !== true
            ) {
                return false;
            }
            $readback = is_array($stats['run_readback'] ?? null) ? $stats['run_readback'] : [];
            $taskDate = substr(trim((string)(
                $diagnostics['target_date']
                ?? $readback['target_date']
                ?? $stats['target_date']
                ?? ($stats['collection_quality']['target_date'] ?? '')
            )), 0, 10);
            return $taskDate === $date;
        }));
        // The authoritative P0/employee-console projection defines "latest"
        // by the monotonically increasing task id. Timestamp strings can be
        // missing on a failed retry and must never let an older success win.
        usort($matches, static fn(array $left, array $right): int =>
            (int)($right['id'] ?? 0) <=> (int)($left['id'] ?? 0)
        );
        return $matches[0] ?? [];
    }

    /** @param array<int, array<string, mixed>> $sources */
    private static function sourceReportsCollectionFailure(array $sources, string $date): bool
    {
        foreach ($sources as $source) {
            $status = strtolower(trim((string)($source['last_sync_status'] ?? $source['status'] ?? '')));
            $lastSyncDate = substr(trim((string)($source['last_sync_time'] ?? '')), 0, 10);
            if ($lastSyncDate === $date && in_array($status, self::FAILED_TASK_STATUSES, true)) {
                return true;
            }
        }
        return false;
    }

    private static function trustedValidationStatus(string $status): bool
    {
        return in_array(strtolower(trim($status)), self::TRUSTED_VALIDATION_STATUSES, true);
    }

    private static function structuredSourcePath(string $sourcePath): bool
    {
        $sourcePath = trim($sourcePath);
        return $sourcePath !== ''
            && (str_contains($sourcePath, '.') || str_contains($sourcePath, '[') || str_contains($sourcePath, '/'));
    }

    /** @return array<string, mixed> */
    private static function decodeArray(mixed $value): array
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

    /** @param array<string, mixed> $row */
    private static function rowPlatform(array $row): string
    {
        $platform = strtolower(trim((string)($row['platform'] ?? $row['source'] ?? '')));
        return match (true) {
            str_contains($platform, 'ctrip'), str_contains($platform, 'xiecheng') => 'ctrip',
            str_contains($platform, 'meituan') => 'meituan',
            default => $platform,
        };
    }

    /** @param array<string, mixed> $row */
    private static function retiredSnapshot(array $row): bool
    {
        if (strtolower(trim((string)($row['validation_status'] ?? ''))) !== 'unverified'
            || (int)($row['readback_verified'] ?? 1) !== 0
        ) {
            return false;
        }
        $flags = self::decodeArray($row['validation_flags'] ?? []);
        foreach ($flags as $flag) {
            $code = is_array($flag) ? (string)($flag['code'] ?? '') : (string)$flag;
            if ($code === 'cloud_bundle_row_absent_from_newer_verified_snapshot') {
                return true;
            }
        }
        return false;
    }

    /** @return array<int, string> */
    private static function dateRange(string $startDate, string $endDate): array
    {
        $start = new \DateTimeImmutable($startDate . ' 00:00:00', new \DateTimeZone('Asia/Shanghai'));
        $end = new \DateTimeImmutable($endDate . ' 00:00:00', new \DateTimeZone('Asia/Shanghai'));
        $dates = [];
        for ($cursor = $start; $cursor <= $end; $cursor = $cursor->modify('+1 day')) {
            $dates[] = $cursor->format('Y-m-d');
        }
        return $dates;
    }

    private static function assertDateRange(string $startDate, string $endDate): void
    {
        foreach ([$startDate, $endDate] as $date) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('Asia/Shanghai'));
            $errors = \DateTimeImmutable::getLastErrors();
            if (!$parsed instanceof \DateTimeImmutable
                || (is_array($errors) && ((int)($errors['warning_count'] ?? 0) > 0 || (int)($errors['error_count'] ?? 0) > 0))
                || $parsed->format('Y-m-d') !== $date
            ) {
                throw new \InvalidArgumentException('Continuous trust date range must use valid YYYY-MM-DD dates.');
            }
        }
        $days = (int)floor((strtotime($endDate) - strtotime($startDate)) / 86400) + 1;
        if ($startDate > $endDate || $days < 1 || $days > 30) {
            throw new \InvalidArgumentException('Continuous trust date range must contain 1 to 30 days.');
        }
    }

    /** @return array<string, mixed> */
    private static function unavailable(?int $hotelId, string $startDate, string $endDate, string $reason): array
    {
        return [
            'schema_version' => 2,
            'metric_scope' => 'ota_channel',
            'hotel_id' => $hotelId,
            'hotel_name' => '',
            'tenant_scope_status' => 'partial',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => 'partial',
            'acceptance_status' => 'unverified',
            'evaluated_days' => 0,
            'verified_days' => 0,
            'consecutive_verified_days' => 0,
            'accepted_days' => 0,
            'consecutive_accepted_days' => 0,
            'required_platforms' => self::PLATFORMS,
            'required_steps' => [
                'source', 'account_profile_binding', 'hotel', 'date', 'field_facts',
                'raw_save', 'organized_save', 'save', 'readback', 'conflict_recollect',
                'page_status', 'p0',
            ],
            'step_semantics' => [
                'account_profile_binding' => 'active_profile_hash_exact_tenant_hotel_platform_scope',
                'raw_save' => 'platform_data_raw_records_exact_source_task_hotel_platform',
                'organized_save' => 'online_daily_data_normalized_save',
                'save' => 'legacy_alias_of_organized_save',
                'conflict_recollect' => 'identity_or_persistence_conflict_resolved_before_trust',
                'page_status' => 'field_fact_projection_contract',
            ],
            'days' => [],
            'reason' => $reason,
            'boundary' => 'No hotel-scoped source/account binding, raw save, organized save and readback evidence was evaluated, so the result remains partial. Profile keys and hashes are never returned. page_status represents a field-fact projection contract, not a live browser render check.',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function loadSources(int $hotelId): array
    {
        $columns = $this->tableColumns('platform_data_sources');
        $fields = array_values(array_intersect([
            'id', 'tenant_id', 'system_hotel_id', 'platform', 'ingestion_method',
            'status', 'enabled', 'config_json', 'user_id', 'created_by',
            'last_sync_status', 'last_error', 'last_sync_time',
        ], array_keys($columns)));
        return Db::name('platform_data_sources')
            ->where('system_hotel_id', $hotelId)
            ->whereIn('platform', self::PLATFORMS)
            ->field(implode(',', $fields))
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }

    /** @return array<int, array<string, mixed>> */
    private function loadTasks(int $hotelId): array
    {
        $columns = $this->tableColumns('platform_data_sync_tasks');
        $fields = array_values(array_intersect([
            'id', 'tenant_id', 'data_source_id', 'system_hotel_id', 'platform',
            'data_type', 'ingestion_method', 'status', 'message', 'stats_json', 'started_at', 'finished_at',
            'create_time', 'update_time',
        ], array_keys($columns)));
        return Db::name('platform_data_sync_tasks')
            ->where('system_hotel_id', $hotelId)
            ->order('id', 'desc')
            ->limit(600)
            ->field(implode(',', $fields))
            ->select()
            ->toArray();
    }

    /**
     * @param array<int, array<string, mixed>> $tasks
     * @return array<int, array<string, mixed>>
     */
    private function loadRawRecords(array $tasks): array
    {
        $columns = $this->tableColumns('platform_data_raw_records');
        $fields = array_values(array_intersect([
            'id', 'tenant_id', 'data_source_id', 'sync_task_id', 'system_hotel_id',
            'platform', 'data_type', 'ingestion_method', 'payload_hash', 'raw_payload',
            'http_status', 'received_at', 'create_time',
        ], array_keys($columns)));
        $taskIds = array_values(array_filter(array_map(
            static fn(array $task): int => (int)($task['id'] ?? 0),
            $tasks
        ), static fn(int $id): bool => $id > 0));
        if ($fields === [] || $taskIds === []) {
            return [];
        }
        return Db::name('platform_data_raw_records')
            ->whereIn('sync_task_id', $taskIds)
            ->field(implode(',', $fields))
            ->order('id', 'desc')
            ->limit(5000)
            ->select()
            ->toArray();
    }

    /**
     * @param array<int, array<string, mixed>> $sources
     * @return array<int, array<string, mixed>>
     */
    private function loadProfileBindings(array $sources): array
    {
        $hashes = [];
        $localAccountIds = [];
        foreach ($sources as $source) {
            $platform = self::rowPlatform($source);
            $config = self::decodeArray($source['config_json'] ?? []);
            $hash = self::sourceProfileHash($platform, $config);
            if ($hash !== '') {
                $hashes[$hash] = true;
            }
            if (strtolower(trim((string)($source['ingestion_method'] ?? ''))) === 'local_collector'
                && (int)($config['local_collector_account_id'] ?? 0) > 0
            ) {
                $localAccountIds[(int)$config['local_collector_account_id']] = true;
            }
        }
        $bindings = [];
        if ($this->tableExists('ota_profile_bindings') && $hashes !== []) {
            $columns = $this->tableColumns('ota_profile_bindings');
            $fields = array_values(array_intersect([
                'id', 'tenant_id', 'system_hotel_id', 'platform', 'profile_key_hash',
                'binding_status', 'bound_by', 'revoked_by', 'create_time', 'update_time',
            ], array_keys($columns)));
            if ($fields !== []) {
                $bindings = Db::name('ota_profile_bindings')
                    ->whereIn('platform', self::PLATFORMS)
                    ->whereIn('profile_key_hash', array_keys($hashes))
                    ->field(implode(',', $fields))
                    ->order('id', 'asc')
                    ->limit(200)
                    ->select()
                    ->toArray();
            }
        }
        if ($localAccountIds === []
            || !$this->tableExists('ota_local_collector_accounts')
            || !$this->tableExists('ota_local_collector_account_hotels')
        ) {
            return $bindings;
        }

        $accounts = Db::name('ota_local_collector_accounts')
            ->whereIn('id', array_keys($localAccountIds))
            ->select()
            ->toArray();
        $accountMap = [];
        foreach ($accounts as $account) {
            $accountMap[(int)($account['id'] ?? 0)] = $account;
        }
        $mappings = Db::name('ota_local_collector_account_hotels')
            ->whereIn('account_id', array_keys($localAccountIds))
            ->select()
            ->toArray();
        foreach ($mappings as $mapping) {
            $account = $accountMap[(int)($mapping['account_id'] ?? 0)] ?? null;
            if (!is_array($account)) {
                continue;
            }
            $platform = self::rowPlatform($mapping);
            $accountPlatform = self::rowPlatform($account);
            if (!in_array($platform, self::PLATFORMS, true) || $platform !== $accountPlatform) {
                continue;
            }
            $profileHash = strtolower(trim((string)($account['profile_key_hash'] ?? '')));
            if (preg_match('/^[a-f0-9]{64}$/D', $profileHash) !== 1) {
                continue;
            }
            $bindings[] = [
                'id' => 'local:' . (int)($mapping['id'] ?? 0),
                'tenant_id' => (int)($mapping['tenant_id'] ?? $account['tenant_id'] ?? 0),
                'system_hotel_id' => (int)($mapping['system_hotel_id'] ?? 0),
                'platform' => $platform,
                'profile_key_hash' => $profileHash,
                'binding_status' => (string)($mapping['status'] ?? '') === 'active'
                    && !in_array((string)($account['status'] ?? ''), ['revoked', 'disabled'], true)
                    ? 'active'
                    : 'inactive',
            ];
        }
        return $bindings;
    }

    /**
     * @param array<int, array<string, mixed>> $sources
     * @return array<int, array<string, mixed>>
     */
    private function loadRows(int $hotelId, string $startDate, string $endDate, array $sources): array
    {
        $columns = $this->tableColumns('online_daily_data');
        $fields = array_values(array_intersect([
            'id', 'tenant_id', 'system_hotel_id', 'hotel_id', 'hotel_name', 'data_date',
            'source', 'platform', 'data_type', 'dimension', 'validation_status',
            'validation_flags', 'data_source_id', 'sync_task_id', 'ingestion_method',
            'source_trace_id', 'raw_data', 'readback_verified', 'readback_verified_at',
            'list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num',
            'order_submit_num',
        ], array_keys($columns)));
        $load = static function ($query) use ($fields, $startDate, $endDate): array {
            return $query
                ->whereBetween('data_date', [$startDate, $endDate])
                ->field(implode(',', $fields))
                ->order('data_date', 'desc')
                ->order('id', 'desc')
                ->limit(10000)
                ->select()
                ->toArray();
        };

        $rows = $load(Db::name('online_daily_data')->where('system_hotel_id', $hotelId));
        if (!isset($columns['data_source_id'])) {
            return $rows;
        }
        $sourceIds = array_values(array_filter(array_map(
            static fn(array $source): int => (int)($source['id'] ?? 0),
            $sources
        ), static fn(int $id): bool => $id > 0));
        if ($sourceIds === []) {
            return $rows;
        }
        $boundRows = $load(Db::name('online_daily_data')->whereIn('data_source_id', $sourceIds));
        $byId = [];
        foreach (array_merge($rows, $boundRows) as $row) {
            $key = (string)($row['id'] ?? md5(json_encode($row) ?: ''));
            $byId[$key] = $row;
        }
        return array_values($byId);
    }

    private function tableExists(string $table): bool
    {
        try {
            return !empty(Db::query("SHOW TABLES LIKE '" . addslashes($table) . "'"));
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, bool> */
    private function tableColumns(string $table): array
    {
        if (isset($this->columns[$table])) {
            return $this->columns[$table];
        }
        try {
            $rows = Db::query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
            $this->columns[$table] = array_fill_keys(array_map(
                static fn(array $row): string => (string)($row['Field'] ?? ''),
                $rows
            ), true);
        } catch (\Throwable) {
            $this->columns[$table] = [];
        }
        return $this->columns[$table];
    }
}
