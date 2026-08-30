<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;
use think\facade\Db;

/**
 * Promote only the authoritative OTA traffic rows proven by both the exact
 * collection receipt and the external strict P0 verifier receipt.
 *
 * history_status is a generated column and is never written directly.
 */
final class OtaCanonicalHistoryPromotionService
{
    private const VERSION = 'ota_canonical_history_promotion.v3';
    private const OPERATION_ROW_SELECTION_VERSION = 'ota_operation_row_selection.v1';
    private const OPERATION_ROW_SELECTION_POLICY =
        'singleton_or_equivalent_required_metrics_min_row_id.v1';
    private const PROMOTABLE_VALIDATION_STATUSES = [
        'normal', 'available', 'ok', 'valid', 'verified',
    ];

    /** @return array<string,mixed> */
    public function promote(
        array $collectionReceipt,
        array $verifierReceipt,
        string $platform,
        int $expectedTenantId,
        int $expectedHotelId,
        int $timeoutSeconds = 0
    ): array {
        return $this->run(
            $collectionReceipt,
            $verifierReceipt,
            $platform,
            $expectedTenantId,
            $expectedHotelId,
            true,
            $timeoutSeconds
        );
    }

    /** @return array<string,mixed> */
    public function preflight(
        array $collectionReceipt,
        array $verifierReceipt,
        string $platform,
        int $expectedTenantId,
        int $expectedHotelId,
        int $timeoutSeconds = 0
    ): array {
        return $this->run(
            $collectionReceipt,
            $verifierReceipt,
            $platform,
            $expectedTenantId,
            $expectedHotelId,
            false,
            $timeoutSeconds
        );
    }

    /** @return array<string,mixed> */
    private function run(
        array $collectionReceipt,
        array $verifierReceipt,
        string $platform,
        int $expectedTenantId,
        int $expectedHotelId,
        bool $execute,
        int $timeoutSeconds
    ): array {
        $deadlineAt = $timeoutSeconds > 0
            ? microtime(true) + max(1, $timeoutSeconds)
            : null;
        $platform = strtolower(trim($platform));
        $contract = $this->promotionContract(
            $collectionReceipt,
            $verifierReceipt,
            $platform,
            $expectedTenantId,
            $expectedHotelId
        );
        if (($contract['status'] ?? '') !== 'ready') {
            return $contract;
        }

        $lockTimeouts = [];
        try {
            $this->assertDeadline($deadlineAt);
            $lockTimeouts = $this->applyDatabaseLockBudget($deadlineAt);
            return Db::transaction(function () use ($contract, $execute, $deadlineAt): array {
                $this->assertDeadline($deadlineAt);
                $result = $this->promoteInTransaction($contract, $execute, $deadlineAt);
                $this->assertDeadline($deadlineAt);
                return $result;
            });
        } catch (\Throwable $exception) {
            $reason = trim($exception->getMessage());
            if (preg_match('/^[a-z0-9_:-]{1,120}$/D', $reason) !== 1) {
                $reason = 'canonical_history_promotion_transaction_failed';
            }
            return $this->blocked($reason, $contract);
        } finally {
            $this->restoreDatabaseLockBudget($lockTimeouts);
        }
    }

    /** @return array<string,mixed> */
    private function promotionContract(
        array $collectionReceipt,
        array $verifierReceipt,
        string $platform,
        int $expectedTenantId,
        int $expectedHotelId
    ): array {
        if (!in_array($platform, ['ctrip', 'meituan'], true)
            || $expectedTenantId <= 0
            || $expectedHotelId <= 0
        ) {
            return $this->blocked('promotion_platform_invalid');
        }
        $hotelId = (int)($collectionReceipt['hotel_id'] ?? 0);
        $targetDate = substr(trim((string)($collectionReceipt['target_date'] ?? '')), 0, 10);
        $dataPeriod = strtolower(trim((string)($collectionReceipt['data_period'] ?? '')));
        if ($hotelId !== $expectedHotelId
            || !$this->validDate($targetDate)
            || !in_array($dataPeriod, ['historical_daily', 'realtime_snapshot'], true)
        ) {
            return $this->blocked('promotion_collection_scope_invalid');
        }

        $sourceTasks = OtaCollectionAnchorService::normalize(
            $collectionReceipt['source_tasks'] ?? []
        );
        $collectionAnchorHash = strtolower(trim((string)($collectionReceipt['collection_anchor_hash'] ?? '')));
        if ($sourceTasks === []
            || (string)($collectionReceipt['collection_anchor_contract_version'] ?? '')
                !== OtaCollectionAnchorService::CONTRACT_VERSION
            || !OtaCollectionAnchorService::matches(
                $collectionReceipt['source_tasks'] ?? [],
                $collectionAnchorHash
            )
        ) {
            return $this->blocked('promotion_collection_anchor_mismatch');
        }
        $platformTasks = array_values(array_filter(
            $sourceTasks,
            static fn(array $task): bool => ($task['platform'] ?? '') === $platform
        ));
        if (count($platformTasks) !== 1) {
            return $this->blocked('promotion_platform_task_ambiguous');
        }
        $sourceTask = $platformTasks[0];
        if ($dataPeriod === 'historical_daily'
            && ($sourceTask['historical_core_contract_status'] ?? '') !== 'ready'
        ) {
            return $this->blocked('promotion_platform_core_contract_missing');
        }
        $verifierAnchorHash = strtolower(trim((string)($verifierReceipt['collection_anchor_hash'] ?? '')));
        $verifierReportHash = strtolower(trim((string)($verifierReceipt['verifier_report_hash'] ?? '')));
        $requiredPlatforms = $this->platformList($verifierReceipt['required_platforms'] ?? []);
        $verifiedPlatforms = $this->platformList($verifierReceipt['verified_platforms'] ?? []);
        if (($verifierReceipt['authority_ready'] ?? false) !== true
            || strtolower(trim((string)($verifierReceipt['verification_source'] ?? ''))) !== 'external_p0_verifier'
            || strtolower(trim((string)($verifierReceipt['status'] ?? ''))) !== 'passed'
            || (int)($verifierReceipt['exit_code'] ?? -1) !== 0
            || (int)($verifierReceipt['hotel_id'] ?? 0) !== $hotelId
            || substr(trim((string)($verifierReceipt['target_date'] ?? '')), 0, 10) !== $targetDate
            || !in_array($platform, $requiredPlatforms, true)
            || !in_array($platform, $verifiedPlatforms, true)
            || !hash_equals($collectionAnchorHash, $verifierAnchorHash)
            || preg_match('/^[a-f0-9]{64}$/D', $verifierReportHash) !== 1
            || ($verifierReceipt['sensitive_values_exposed'] ?? true) !== false
        ) {
            return $this->blocked('promotion_verifier_receipt_invalid');
        }

        $storageScope = is_array($verifierReceipt['platform_storage_scopes'][$platform] ?? null)
            ? $verifierReceipt['platform_storage_scopes'][$platform]
            : [];
        $tenantId = (int)($storageScope['tenant_id'] ?? 0);
        $dataSourceId = (int)($storageScope['data_source_id'] ?? 0);
        $syncTaskId = (int)($storageScope['sync_task_id'] ?? 0);
        $requiredMetrics = $this->metricKeyList($storageScope['required_metric_keys'] ?? []);
        $completeMetrics = $this->metricKeyList($storageScope['complete_metric_keys'] ?? []);
        $missingMetrics = $this->metricKeyList($storageScope['missing_metric_keys'] ?? []);
        $sampleRowIds = $this->positiveIds($storageScope['sample_row_ids'] ?? []);
        $verifierNonzeroRows = (int)($storageScope['nonzero_required_metric_rows'] ?? -1);
        $verifierExplicitZeroRows = (int)($storageScope['explicit_zero_confirmed_rows'] ?? -1);
        $verifierAuthoritativeRows = (int)($storageScope['authoritative_traffic_row_count'] ?? 0);
        $observedProvenanceStatus = strtolower(trim((string)(
            $storageScope['observed_traffic_metric_provenance_status'] ?? ''
        )));
        $syntheticProvenanceMissingRows = (int)(
            $storageScope['synthetic_normalization_provenance_missing_rows'] ?? -1
        );
        if ($tenantId !== $expectedTenantId
            || $dataSourceId <= 0
            || $syncTaskId <= 0
            || (int)($storageScope['system_hotel_id'] ?? 0) !== $hotelId
            || strtolower(trim((string)($storageScope['platform'] ?? ''))) !== $platform
            || substr(trim((string)($storageScope['target_date'] ?? '')), 0, 10) !== $targetDate
            || $dataSourceId !== (int)$sourceTask['data_source_id']
            || $syncTaskId !== (int)$sourceTask['sync_task_id']
            || $requiredMetrics === []
            || array_diff($requiredMetrics, $completeMetrics) !== []
            || $missingMetrics !== []
            || $verifierAuthoritativeRows <= 0
            || $verifierNonzeroRows < 0
            || $verifierExplicitZeroRows < 0
            || $verifierNonzeroRows + $verifierExplicitZeroRows !== $verifierAuthoritativeRows
            || $observedProvenanceStatus !== 'ready'
            || $syntheticProvenanceMissingRows !== 0
            || strtolower(trim((string)($storageScope['readback_status'] ?? ''))) !== 'ready'
            || $sampleRowIds === []
            || array_diff($sampleRowIds, $sourceTask['row_ids']) !== []
        ) {
            return $this->blocked('promotion_storage_scope_mismatch');
        }

        return [
            'status' => 'ready',
            'reason' => '',
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'platform' => $platform,
            'target_date' => $targetDate,
            'data_period' => $dataPeriod,
            'data_source_id' => $dataSourceId,
            'sync_task_id' => $syncTaskId,
            'collection_row_ids' => $sourceTask['row_ids'],
            'sample_row_ids' => $sampleRowIds,
            'authoritative_traffic_row_count' => (int)$storageScope['authoritative_traffic_row_count'],
            'verifier_nonzero_required_metric_rows' => $verifierNonzeroRows,
            'verifier_explicit_zero_confirmed_rows' => $verifierExplicitZeroRows,
            'observed_traffic_metric_provenance_status' => 'ready',
            'synthetic_normalization_provenance_missing_rows' => 0,
            'required_metric_keys' => $requiredMetrics,
            'collection_anchor_contract_version' => OtaCollectionAnchorService::CONTRACT_VERSION,
            'collection_anchor_hash' => $collectionAnchorHash,
            'verifier_report_hash' => $verifierReportHash,
            'sensitive_values_exposed' => false,
        ];
    }

    /** @param array<string,mixed> $contract @return array<string,mixed> */
    private function promoteInTransaction(
        array $contract,
        bool $execute,
        ?float $deadlineAt
    ): array
    {
        $this->assertDeadline($deadlineAt);
        $tenantId = (int)$contract['tenant_id'];
        $hotelId = (int)$contract['system_hotel_id'];
        $sourceId = (int)$contract['data_source_id'];
        $taskId = (int)$contract['sync_task_id'];
        $platform = (string)$contract['platform'];
        $targetDate = (string)$contract['target_date'];
        $dataPeriod = (string)$contract['data_period'];
        $collectionRowIds = $contract['collection_row_ids'];

        $hotel = Db::name('hotels')
            ->where('id', $hotelId)
            ->lock(true)
            ->find();
        $this->assertDeadline($deadlineAt);
        if (!is_array($hotel) || (int)($hotel['tenant_id'] ?? 0) !== $tenantId) {
            throw new RuntimeException('promotion_hotel_tenant_scope_mismatch');
        }

        $source = Db::name('platform_data_sources')
            ->where('id', $sourceId)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('platform', $platform)
            ->lock(true)
            ->find();
        $this->assertDeadline($deadlineAt);
        if (!is_array($source)
            || (int)($source['enabled'] ?? 0) !== 1
            || strtolower(trim((string)($source['status'] ?? ''))) === 'disabled'
            || !in_array(strtolower(trim((string)($source['ingestion_method'] ?? ''))), [
                'browser_profile', 'profile_browser',
            ], true)
            || !array_key_exists('config_json', $source)
        ) {
            throw new RuntimeException('promotion_source_identity_invalid');
        }

        $task = Db::name('platform_data_sync_tasks')
            ->where('id', $taskId)
            ->where('tenant_id', $tenantId)
            ->where('data_source_id', $sourceId)
            ->where('system_hotel_id', $hotelId)
            ->where('platform', $platform)
            ->lock(true)
            ->find();
        $this->assertDeadline($deadlineAt);
        if (!is_array($task) || strtolower(trim((string)($task['status'] ?? ''))) !== 'success') {
            throw new RuntimeException('promotion_sync_task_not_success');
        }
        $taskStats = $this->decode((string)($task['stats_json'] ?? ''));
        $runReadback = is_array($taskStats['run_readback'] ?? null) ? $taskStats['run_readback'] : [];
        $this->assertRunReadback($runReadback, $contract);
        $runReadbackRowIds = $this->positiveIds($runReadback['row_ids'] ?? []);

        $columns = array_keys(Db::getFields('online_daily_data'));
        $this->assertDeadline($deadlineAt);
        $requiredColumns = [
            'id', 'tenant_id', 'system_hotel_id', 'hotel_id', 'data_source_id', 'sync_task_id',
            'source', 'platform', 'data_date', 'data_period', 'data_type', 'dimension', 'compare_type',
            'validation_status', 'readback_verified', 'ingestion_method',
            'source_trace_id', 'snapshot_time', 'raw_data',
        ];
        foreach ($requiredColumns as $requiredColumn) {
            if (!in_array($requiredColumn, $columns, true)) {
                throw new RuntimeException('promotion_column_missing:' . $requiredColumn);
            }
        }
        foreach ($contract['required_metric_keys'] as $requiredMetricKey) {
            if (!in_array($requiredMetricKey, $columns, true)) {
                throw new RuntimeException('promotion_metric_column_missing:' . $requiredMetricKey);
            }
        }
        $requiredCoreColumns = $dataPeriod === 'historical_daily'
            ? OtaOrderedCollectionPlanner::requiredStorageColumns($platform)
            : [];
        foreach ($requiredCoreColumns as $requiredCoreColumn) {
            if (!in_array($requiredCoreColumn, $columns, true)) {
                throw new RuntimeException('promotion_metric_column_missing:' . $requiredCoreColumn);
            }
        }
        // SQLite's PRAGMA table_info omits generated columns even though they
        // are queryable. Selecting history_status is the cross-database proof
        // that the generated projection is actually available.
        $selectColumns = array_values(array_unique(array_merge(
            $requiredColumns,
            ['history_status'],
            $contract['required_metric_keys'],
            $requiredCoreColumns,
            in_array('update_time', $columns, true) ? ['update_time'] : []
        )));
        $rows = Db::name('online_daily_data')
            ->field(implode(',', $selectColumns))
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('data_source_id', $sourceId)
            ->where('sync_task_id', $taskId)
            ->where('source', $platform)
            ->where('platform', $platform)
            ->where('data_date', $targetDate)
            ->where('data_period', $dataPeriod)
            ->lock(true)
            ->select()
            ->toArray();
        $this->assertDeadline($deadlineAt);
        $exactScopeRowIds = $this->positiveIds(array_column($rows, 'id'));
        if ($exactScopeRowIds !== $collectionRowIds
            || $exactScopeRowIds !== $runReadbackRowIds
        ) {
            throw new RuntimeException('promotion_collection_rows_identity_mismatch');
        }
        if ($dataPeriod === 'historical_daily') {
            $coreRows = OtaOrderedCollectionPlanner::storedCoreRows($platform, $rows);
            if ($coreRows === []
                || OtaOrderedCollectionPlanner::missingFieldKeys($platform, $coreRows) !== []
            ) {
                throw new RuntimeException('promotion_platform_core_contract_db_incomplete');
            }
        }
        $authoritativeRows = $this->authoritativeRows($rows, $contract);
        if (count($authoritativeRows) !== (int)$contract['authoritative_traffic_row_count']) {
            throw new RuntimeException('promotion_authoritative_row_count_mismatch');
        }
        $authoritativeRowIds = $this->positiveIds(array_column($authoritativeRows, 'id'));
        if (array_diff($contract['sample_row_ids'], $authoritativeRowIds) !== []) {
            throw new RuntimeException('promotion_authoritative_sample_mismatch');
        }
        $factProof = $this->authoritativeFactProof(
            $authoritativeRows,
            $contract
        );
        if ((int)$factProof['nonzero_required_metric_rows']
                !== (int)$contract['verifier_nonzero_required_metric_rows']
            || (int)$factProof['explicit_zero_confirmed_rows']
                !== (int)$contract['verifier_explicit_zero_confirmed_rows']
        ) {
            throw new RuntimeException('promotion_authoritative_metric_value_count_mismatch');
        }
        $contract['authoritative_fact_digest'] = (string)$factProof['digest'];
        $contract['authoritative_row_fact_digests'] = is_array(
            $factProof['row_digests'] ?? null
        ) ? $factProof['row_digests'] : [];
        $operationRowSelection = is_array($factProof['operation_row_selection'] ?? null)
            ? $factProof['operation_row_selection']
            : [];
        if ($operationRowSelection === []) {
            throw new RuntimeException('promotion_operation_row_selection_missing');
        }
        $contract['operation_row_selection'] = $operationRowSelection;
        $contract['nonzero_required_metric_rows'] = (int)$factProof['nonzero_required_metric_rows'];
        $contract['explicit_zero_confirmed_rows'] = (int)$factProof['explicit_zero_confirmed_rows'];
        $contract['snapshot_time_backfills'] = is_array($factProof['snapshot_time_backfills'] ?? null)
            ? $factProof['snapshot_time_backfills']
            : [];
        $identityProof = $this->platformHotelIdentityProof(
            $source,
            $authoritativeRows,
            $contract
        );
        $this->assertDeadline($deadlineAt);
        $contract['platform_hotel_identity_digest'] = (string)$identityProof['digest'];
        $contract['authoritative_row_platform_hotel_identity_digests'] = is_array(
            $identityProof['row_digests'] ?? null
        ) ? $identityProof['row_digests'] : [];

        $existingPromotion = is_array($taskStats['canonical_history_promotion'] ?? null)
            ? $taskStats['canonical_history_promotion']
            : [];
        $allAlreadyVerified = array_reduce(
            $authoritativeRows,
            static fn(bool $ready, array $row): bool => $ready
                && strtolower(trim((string)($row['validation_status'] ?? ''))) === 'verified'
                && strtolower(trim((string)($row['history_status'] ?? ''))) === 'success',
            true
        );
        if ($allAlreadyVerified) {
            if (!$this->promotionReceiptMatches($existingPromotion, $contract, $authoritativeRowIds)) {
                throw new RuntimeException('verified_row_without_matching_promotion_receipt');
            }
            $this->assertDeadline($deadlineAt);
            return $execute
                ? $this->successResponse($contract, $authoritativeRowIds, 0, true, $existingPromotion)
                : $this->preflightResponse($contract, $authoritativeRowIds, 0, true);
        }
        if ($existingPromotion !== []) {
            throw new RuntimeException('promotion_receipt_exists_but_rows_not_verified');
        }

        $promotableValidationStatuses = self::PROMOTABLE_VALIDATION_STATUSES;
        if ($platform === 'ctrip'
            && $dataPeriod === 'realtime_snapshot'
            && $targetDate === (new \DateTimeImmutable(
                'today',
                new \DateTimeZone('Asia/Shanghai')
            ))->format('Y-m-d')
            && (int)$contract['authoritative_traffic_row_count'] === count($contract['sample_row_ids'])
        ) {
            // Each strict realtime endpoint proves one member of the aggregate
            // two-metric contract, so its row is partial before the external
            // verifier confirms the exact two-row union.
            $promotableValidationStatuses[] = 'partial';
        }
        foreach ($authoritativeRows as $row) {
            if (!in_array(
                strtolower(trim((string)($row['validation_status'] ?? ''))),
                $promotableValidationStatuses,
                true
            )) {
                throw new RuntimeException('promotion_row_validation_not_promotable');
            }
        }
        $idsToPromote = [];
        foreach ($authoritativeRows as $authoritativeRow) {
            if (strtolower(trim((string)($authoritativeRow['validation_status'] ?? ''))) !== 'verified') {
                $idsToPromote[] = (int)$authoritativeRow['id'];
            }
        }
        $idsToChange = $this->positiveIds(array_merge(
            $idsToPromote,
            array_keys($contract['snapshot_time_backfills'])
        ));
        if (!$execute) {
            $this->assertDeadline($deadlineAt);
            return $this->preflightResponse(
                $contract,
                $authoritativeRowIds,
                count($idsToChange),
                false
            );
        }
        foreach ($contract['snapshot_time_backfills'] as $rowId => $snapshotTime) {
            $this->assertDeadline($deadlineAt);
            $backfill = ['snapshot_time' => (string)$snapshotTime];
            if (in_array('update_time', $columns, true)) {
                $backfill['update_time'] = date('Y-m-d H:i:s');
            }
            $affected = (int)Db::name('online_daily_data')
                ->where('id', (int)$rowId)
                ->where('tenant_id', $tenantId)
                ->where('system_hotel_id', $hotelId)
                ->where('data_source_id', $sourceId)
                ->where('sync_task_id', $taskId)
                ->where('source', $platform)
                ->where('platform', $platform)
                ->where('data_date', $targetDate)
                ->where('data_period', 'historical_daily')
                ->update($backfill);
            $this->assertDeadline($deadlineAt);
            if ($affected !== 1) {
                throw new RuntimeException('promotion_snapshot_time_backfill_failed');
            }
        }
        $update = ['validation_status' => 'verified'];
        if (in_array('update_time', $columns, true)) {
            $update['update_time'] = date('Y-m-d H:i:s');
        }
        if ($idsToPromote !== []) {
            $this->assertDeadline($deadlineAt);
            $affected = (int)Db::name('online_daily_data')
                ->where('tenant_id', $tenantId)
                ->where('system_hotel_id', $hotelId)
                ->where('data_source_id', $sourceId)
                ->where('sync_task_id', $taskId)
                ->where('source', $platform)
                ->where('platform', $platform)
                ->where('data_date', $targetDate)
                ->where('data_period', $dataPeriod)
                ->whereIn('id', $idsToPromote)
                ->whereIn('validation_status', $promotableValidationStatuses)
                ->update($update);
            $this->assertDeadline($deadlineAt);
            if ($affected !== count($idsToPromote)) {
                throw new RuntimeException('promotion_row_update_count_mismatch');
            }
        }

        $promotionReceipt = $this->buildPromotionReceipt($contract, $authoritativeRowIds);
        $taskStats['canonical_history_promotion'] = $promotionReceipt;
        $taskAffected = (int)Db::name('platform_data_sync_tasks')
            ->where('id', $taskId)
            ->where('tenant_id', $tenantId)
            ->where('data_source_id', $sourceId)
            ->where('system_hotel_id', $hotelId)
            ->where('platform', $platform)
            ->where('status', 'success')
            ->update([
                'stats_json' => json_encode(
                    $taskStats,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ),
                'update_time' => date('Y-m-d H:i:s'),
            ]);
        $this->assertDeadline($deadlineAt);
        if ($taskAffected !== 1) {
            throw new RuntimeException('promotion_task_receipt_write_failed');
        }

        $verifiedRows = Db::name('online_daily_data')
            ->field(implode(',', $selectColumns))
            ->whereIn('id', $authoritativeRowIds)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('data_source_id', $sourceId)
            ->where('sync_task_id', $taskId)
            ->select()
            ->toArray();
        $this->assertDeadline($deadlineAt);
        if ($this->positiveIds(array_column($verifiedRows, 'id')) !== $authoritativeRowIds) {
            throw new RuntimeException('promotion_row_readback_identity_mismatch');
        }
        foreach ($verifiedRows as $verifiedRow) {
            if (strtolower(trim((string)($verifiedRow['validation_status'] ?? ''))) !== 'verified'
                || strtolower(trim((string)($verifiedRow['history_status'] ?? ''))) !== 'success'
            ) {
                throw new RuntimeException('promotion_generated_history_readback_failed');
            }
        }
        $storedTask = Db::name('platform_data_sync_tasks')
            ->where('id', $taskId)
            ->where('tenant_id', $tenantId)
            ->where('data_source_id', $sourceId)
            ->where('system_hotel_id', $hotelId)
            ->where('platform', $platform)
            ->find();
        $this->assertDeadline($deadlineAt);
        $storedStats = is_array($storedTask)
            ? $this->decode((string)($storedTask['stats_json'] ?? ''))
            : [];
        if (!$this->promotionReceiptMatches(
            is_array($storedStats['canonical_history_promotion'] ?? null)
                ? $storedStats['canonical_history_promotion']
                : [],
            $contract,
            $authoritativeRowIds
        )) {
            throw new RuntimeException('promotion_task_receipt_readback_failed');
        }

        $this->assertDeadline($deadlineAt);
        return $this->successResponse(
            $contract,
            $authoritativeRowIds,
            count($idsToChange),
            false,
            $promotionReceipt
        );
    }

    /** @param array<string,mixed> $readback @param array<string,mixed> $contract */
    private function assertRunReadback(array $readback, array $contract): void
    {
        $required = $this->metricKeyList($readback['required_traffic_metric_keys'] ?? []);
        $complete = $this->metricKeyList($readback['complete_traffic_metric_keys'] ?? []);
        if (($readback['readback_verified'] ?? false) !== true
            || (int)($readback['sync_task_id'] ?? 0) !== (int)$contract['sync_task_id']
            || (int)($readback['data_source_id'] ?? 0) !== (int)$contract['data_source_id']
            || (int)($readback['system_hotel_id'] ?? 0) !== (int)$contract['system_hotel_id']
            || strtolower(trim((string)($readback['platform'] ?? ''))) !== (string)$contract['platform']
            || substr(trim((string)($readback['target_date'] ?? '')), 0, 10) !== (string)$contract['target_date']
            || strtolower(trim((string)($readback['data_period'] ?? ''))) !== (string)$contract['data_period']
            || $this->positiveIds($readback['row_ids'] ?? []) !== $contract['collection_row_ids']
            || strtolower(trim((string)($readback['p0_status'] ?? ''))) !== 'ready'
            || strtolower(trim((string)($readback['field_fact_status'] ?? ''))) !== 'ready'
            || $required !== $contract['required_metric_keys']
            || array_diff($required, $complete) !== []
            || $this->metricKeyList($readback['missing_traffic_metric_keys'] ?? []) !== []
        ) {
            throw new RuntimeException('promotion_run_readback_mismatch');
        }
    }

    /** @param array<int,array<string,mixed>> $rows @param array<string,mixed> $contract */
    private function authoritativeRows(array $rows, array $contract): array
    {
        return array_values(array_filter($rows, static function (array $row) use ($contract): bool {
            $dataType = strtolower(trim((string)($row['data_type'] ?? '')));
            $platform = (string)$contract['platform'];
            $ctripRealtime = $platform === 'ctrip'
                && (string)$contract['data_period'] === 'realtime_snapshot'
                && (string)$contract['target_date'] === (new \DateTimeImmutable(
                    'today',
                    new \DateTimeZone('Asia/Shanghai')
                ))->format('Y-m-d');
            $sampleRowIds = array_map('intval', (array)($contract['sample_row_ids'] ?? []));
            return (int)($row['tenant_id'] ?? 0) === (int)$contract['tenant_id']
                && (int)($row['system_hotel_id'] ?? 0) === (int)$contract['system_hotel_id']
                && (int)($row['data_source_id'] ?? 0) === (int)$contract['data_source_id']
                && (int)($row['sync_task_id'] ?? 0) === (int)$contract['sync_task_id']
                && strtolower(trim((string)($row['source'] ?? ''))) === (string)$contract['platform']
                && strtolower(trim((string)($row['platform'] ?? ''))) === (string)$contract['platform']
                && substr(trim((string)($row['data_date'] ?? '')), 0, 10) === (string)$contract['target_date']
                && strtolower(trim((string)($row['data_period'] ?? ''))) === (string)$contract['data_period']
                && in_array($dataType, ['traffic', 'flow', 'conversion'], true)
                && (int)($row['readback_verified'] ?? 0) === 1
                && trim((string)($row['hotel_id'] ?? '')) !== ''
                && in_array(strtolower(trim((string)($row['ingestion_method'] ?? ''))), [
                    'browser_profile', 'profile_browser',
                ], true)
                && ($platform !== 'ctrip'
                    || ($ctripRealtime
                        ? in_array((int)($row['id'] ?? 0), $sampleRowIds, true)
                        : !str_starts_with(
                        strtolower(trim((string)($row['dimension'] ?? ''))),
                        'catalog:'
                    )))
                && trim((string)($row['source_trace_id'] ?? '')) !== ''
                && ($ctripRealtime || OtaTrafficAttributionService::rowBelongsToAuthoritativeP0Traffic(
                    $row,
                    $platform
                ));
        }));
    }

    /** @param array<string,mixed> $contract @param array<int,int> $rowIds @return array<string,mixed> */
    private function buildPromotionReceipt(array $contract, array $rowIds): array
    {
        $receipt = [
            'version' => self::VERSION,
            'tenant_id' => (int)$contract['tenant_id'],
            'system_hotel_id' => (int)$contract['system_hotel_id'],
            'platform' => (string)$contract['platform'],
            'target_date' => (string)$contract['target_date'],
            'data_period' => (string)$contract['data_period'],
            'data_source_id' => (int)$contract['data_source_id'],
            'sync_task_id' => (int)$contract['sync_task_id'],
            'row_ids' => $rowIds,
            'collection_anchor_contract_version' =>
                (string)$contract['collection_anchor_contract_version'],
            'collection_anchor_hash' => (string)$contract['collection_anchor_hash'],
            'verifier_report_hash' => (string)$contract['verifier_report_hash'],
            'authoritative_fact_digest' => (string)$contract['authoritative_fact_digest'],
            'authoritative_row_fact_digests' => $contract['authoritative_row_fact_digests'],
            'platform_hotel_identity_digest' => (string)$contract['platform_hotel_identity_digest'],
            'authoritative_row_platform_hotel_identity_digests' =>
                $contract['authoritative_row_platform_hotel_identity_digests'],
            'operation_row_selection_version' =>
                (string)$contract['operation_row_selection']['version'],
            'operation_row_selection_status' =>
                (string)$contract['operation_row_selection']['status'],
            'operation_row_selection_policy' =>
                (string)$contract['operation_row_selection']['policy'],
            'operation_row_candidate_ids' =>
                $contract['operation_row_selection']['candidate_row_ids'],
            'selected_operation_row_id' =>
                (int)$contract['operation_row_selection']['selected_row_id'],
            'operation_row_metric_digests' =>
                $contract['operation_row_selection']['row_metric_digests'],
            'operation_row_selection_digest' =>
                (string)$contract['operation_row_selection']['selection_digest'],
            'nonzero_required_metric_rows' => (int)$contract['nonzero_required_metric_rows'],
            'explicit_zero_confirmed_rows' => (int)$contract['explicit_zero_confirmed_rows'],
            'observed_traffic_metric_provenance_status' => 'ready',
            'synthetic_normalization_provenance_missing_rows' => 0,
            'verified_at' => date('Y-m-d H:i:s'),
            'sensitive_values_exposed' => false,
        ];
        $receipt['content_digest'] = $this->digest($receipt);
        return $receipt;
    }

    /** @param array<string,mixed> $receipt @param array<string,mixed> $contract @param array<int,int> $rowIds */
    private function promotionReceiptMatches(array $receipt, array $contract, array $rowIds): bool
    {
        $digest = strtolower(trim((string)($receipt['content_digest'] ?? '')));
        return (string)($receipt['version'] ?? '') === self::VERSION
            && (int)($receipt['tenant_id'] ?? 0) === (int)$contract['tenant_id']
            && (int)($receipt['system_hotel_id'] ?? 0) === (int)$contract['system_hotel_id']
            && strtolower(trim((string)($receipt['platform'] ?? ''))) === (string)$contract['platform']
            && (string)($receipt['target_date'] ?? '') === (string)$contract['target_date']
            && (string)($receipt['data_period'] ?? '') === (string)$contract['data_period']
            && (int)($receipt['data_source_id'] ?? 0) === (int)$contract['data_source_id']
            && (int)($receipt['sync_task_id'] ?? 0) === (int)$contract['sync_task_id']
            && $this->positiveIds($receipt['row_ids'] ?? []) === $rowIds
            && (string)($receipt['collection_anchor_contract_version'] ?? '')
                === (string)$contract['collection_anchor_contract_version']
            && hash_equals((string)$contract['collection_anchor_hash'], (string)($receipt['collection_anchor_hash'] ?? ''))
            && hash_equals((string)$contract['verifier_report_hash'], (string)($receipt['verifier_report_hash'] ?? ''))
            && hash_equals((string)$contract['authoritative_fact_digest'], (string)($receipt['authoritative_fact_digest'] ?? ''))
            && $this->rowDigestMap($receipt['authoritative_row_fact_digests'] ?? null, $rowIds)
                === $contract['authoritative_row_fact_digests']
            && hash_equals((string)$contract['platform_hotel_identity_digest'], (string)($receipt['platform_hotel_identity_digest'] ?? ''))
            && $this->rowDigestMap(
                $receipt['authoritative_row_platform_hotel_identity_digests'] ?? null,
                $rowIds
            ) === $contract['authoritative_row_platform_hotel_identity_digests']
            && $this->operationRowSelectionReceiptMatches($receipt, $contract, $rowIds)
            && (int)($receipt['nonzero_required_metric_rows'] ?? -1) === (int)$contract['nonzero_required_metric_rows']
            && (int)($receipt['explicit_zero_confirmed_rows'] ?? -1) === (int)$contract['explicit_zero_confirmed_rows']
            && strtolower(trim((string)($receipt['observed_traffic_metric_provenance_status'] ?? ''))) === 'ready'
            && (int)($receipt['synthetic_normalization_provenance_missing_rows'] ?? -1) === 0
            && ($receipt['sensitive_values_exposed'] ?? true) === false
            && preg_match('/^[a-f0-9]{64}$/D', $digest) === 1
            && hash_equals($digest, $this->digest($receipt));
    }

    /** @param array<string,mixed> $contract @param array<int,int> $rowIds */
    private function preflightResponse(
        array $contract,
        array $rowIds,
        int $wouldPromoteCount,
        bool $idempotent
    ): array {
        return [
            'status' => 'ready',
            'reason' => '',
            'execute' => false,
            'would_promote_count' => max(0, $wouldPromoteCount),
            'verified_count' => count($rowIds),
            'idempotent' => $idempotent,
            'tenant_id' => (int)$contract['tenant_id'],
            'system_hotel_id' => (int)$contract['system_hotel_id'],
            'platform' => (string)$contract['platform'],
            'target_date' => (string)$contract['target_date'],
            'data_source_id' => (int)$contract['data_source_id'],
            'sync_task_id' => (int)$contract['sync_task_id'],
            'row_ids' => $rowIds,
            'authoritative_fact_digest' => (string)$contract['authoritative_fact_digest'],
            'platform_hotel_identity_digest' => (string)$contract['platform_hotel_identity_digest'],
            'operation_row_selection_version' =>
                (string)$contract['operation_row_selection']['version'],
            'operation_row_selection_status' =>
                (string)$contract['operation_row_selection']['status'],
            'operation_row_selection_policy' =>
                (string)$contract['operation_row_selection']['policy'],
            'operation_row_candidate_ids' =>
                $contract['operation_row_selection']['candidate_row_ids'],
            'selected_operation_row_id' =>
                (int)$contract['operation_row_selection']['selected_row_id'],
            'operation_row_metric_digests' =>
                $contract['operation_row_selection']['row_metric_digests'],
            'operation_row_selection_digest' =>
                (string)$contract['operation_row_selection']['selection_digest'],
            'nonzero_required_metric_rows' => (int)$contract['nonzero_required_metric_rows'],
            'explicit_zero_confirmed_rows' => (int)$contract['explicit_zero_confirmed_rows'],
            'snapshot_time_backfill_count' => count($contract['snapshot_time_backfills'] ?? []),
            'preflight_verified' => true,
            'sensitive_values_exposed' => false,
        ];
    }

    /** @param array<string,mixed> $contract @param array<int,int> $rowIds @param array<string,mixed> $receipt */
    private function successResponse(
        array $contract,
        array $rowIds,
        int $promotedCount,
        bool $idempotent,
        array $receipt
    ): array {
        return [
            'status' => 'verified',
            'reason' => '',
            'promoted_count' => max(0, $promotedCount),
            'verified_count' => count($rowIds),
            'idempotent' => $idempotent,
            'tenant_id' => (int)$contract['tenant_id'],
            'system_hotel_id' => (int)$contract['system_hotel_id'],
            'platform' => (string)$contract['platform'],
            'target_date' => (string)$contract['target_date'],
            'data_source_id' => (int)$contract['data_source_id'],
            'sync_task_id' => (int)$contract['sync_task_id'],
            'row_ids' => $rowIds,
            'promotion_receipt_digest' => (string)($receipt['content_digest'] ?? ''),
            'operation_row_selection_version' =>
                (string)$contract['operation_row_selection']['version'],
            'operation_row_selection_status' =>
                (string)$contract['operation_row_selection']['status'],
            'operation_row_selection_policy' =>
                (string)$contract['operation_row_selection']['policy'],
            'operation_row_candidate_ids' =>
                $contract['operation_row_selection']['candidate_row_ids'],
            'selected_operation_row_id' =>
                (int)$contract['operation_row_selection']['selected_row_id'],
            'operation_row_metric_digests' =>
                $contract['operation_row_selection']['row_metric_digests'],
            'operation_row_selection_digest' =>
                (string)$contract['operation_row_selection']['selection_digest'],
            'readback_verified' => true,
            'history_status' => 'success',
            'snapshot_time_backfilled_count' => count($contract['snapshot_time_backfills'] ?? []),
            'sensitive_values_exposed' => false,
        ];
    }

    /** @param array<string,mixed> $scope @return array<string,mixed> */
    private function blocked(string $reason, array $scope = []): array
    {
        return [
            'status' => 'blocked',
            'reason' => $reason,
            'promoted_count' => 0,
            'readback_verified' => false,
            'tenant_id' => (int)($scope['tenant_id'] ?? 0) ?: null,
            'system_hotel_id' => (int)($scope['system_hotel_id'] ?? 0) ?: null,
            'platform' => trim((string)($scope['platform'] ?? '')),
            'target_date' => trim((string)($scope['target_date'] ?? '')),
            'data_source_id' => (int)($scope['data_source_id'] ?? 0) ?: null,
            'sync_task_id' => (int)($scope['sync_task_id'] ?? 0) ?: null,
            'sensitive_values_exposed' => false,
        ];
    }

    /**
     * Revalidate the exact persisted facts while their rows are locked. This
     * closes the gap between the external strict verifier and promotion.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed> $contract
     */
    private function authoritativeFactProof(array $rows, array $contract): array
    {
        $platform = (string)$contract['platform'];
        $expectedStorageFields = [
            'list_exposure' => 'online_daily_data.list_exposure',
            'detail_exposure' => 'online_daily_data.detail_exposure',
            'flow_rate' => 'online_daily_data.flow_rate',
            'order_filling_num' => 'online_daily_data.order_filling_num',
            'order_submit_num' => 'online_daily_data.order_submit_num',
        ];
        $realtimeCtrip = $platform === 'ctrip'
            && (string)($contract['data_period'] ?? '') === 'realtime_snapshot'
            && (string)($contract['target_date'] ?? '') === (new \DateTimeImmutable(
                'today',
                new \DateTimeZone('Asia/Shanghai')
            ))->format('Y-m-d');
        $platformRequiredKeys = $platform === 'meituan'
            ? array_slice(array_keys($expectedStorageFields), 0, 3)
            : ($realtimeCtrip
                ? ['detail_exposure', 'order_submit_num']
                : array_keys($expectedStorageFields));
        sort($platformRequiredKeys, SORT_STRING);
        if ($contract['required_metric_keys'] !== $platformRequiredKeys) {
            throw new RuntimeException('promotion_required_metric_contract_mismatch');
        }

        usort($rows, static fn(array $left, array $right): int =>
            (int)($left['id'] ?? 0) <=> (int)($right['id'] ?? 0)
        );
        $boundedRows = [];
        $rowDigests = [];
        $operationRowMetricDigests = [];
        $nonzeroRows = 0;
        $explicitZeroRows = 0;
        $snapshotTimeBackfills = [];
        $coveredMetricKeys = [];
        foreach ($rows as $row) {
            $rawJson = trim((string)($row['raw_data'] ?? ''));
            $raw = json_decode($rawJson, true);
            if (!is_array($raw) || $raw === []) {
                throw new RuntimeException('promotion_authoritative_raw_data_invalid');
            }
            $captureTime = $this->authoritativeCaptureTime($row, $raw, $contract);
            if (trim((string)($row['snapshot_time'] ?? '')) === '') {
                $snapshotTimeBackfills[(int)$row['id']] = $captureTime;
            }
            $observedSourceRow = is_array($raw['row'] ?? null) ? $raw['row'] : [];
            $sourceRow = $observedSourceRow !== [] ? $observedSourceRow : $raw;
            $rowRequiredKeys = $realtimeCtrip
                ? $this->ctripRealtimeRowMetricKeys($row, $raw)
                : $platformRequiredKeys;
            if ($rowRequiredKeys === []) {
                throw new RuntimeException('promotion_authoritative_realtime_endpoint_invalid');
            }
            $observedMetricKeys = $this->observedTrafficMetricKeys($observedSourceRow);
            if ($observedMetricKeys === null && $realtimeCtrip) {
                $observedMetricKeys = $this->capturedTrafficMetricKeys(
                    $raw,
                    $rowRequiredKeys,
                    $expectedStorageFields
                );
            }
            if ($observedMetricKeys === null
                || array_diff($rowRequiredKeys, $observedMetricKeys) !== []
            ) {
                throw new RuntimeException('promotion_synthetic_normalization_provenance_missing');
            }
            $dateSource = strtolower(trim((string)(
                $raw['date_source']
                ?? $sourceRow['date_source']
                ?? $sourceRow['dateSource']
                ?? ''
            )));
            if ($dateSource === ''
                || str_contains($dateSource, 'default_data_date')
                || str_contains($dateSource, 'command_date')
                || str_contains($dateSource, 'command --date')
                || in_array($dateSource, [
                    'capture_argument', 'response.rtdataupdatetime', 'page.visible_update_time',
                ], true)
                || preg_match('/(?:^|\.)cards\.rtdataupdatetime$/', $dateSource) === 1
            ) {
                throw new RuntimeException('promotion_authoritative_date_source_invalid');
            }
            $sourceDate = $sourceRow['date']
                ?? $sourceRow['dataDate']
                ?? $sourceRow['statDate']
                ?? $sourceRow['stat_date']
                ?? $sourceRow['data_date']
                ?? $sourceRow['reportDate']
                ?? $sourceRow['day']
                ?? null;
            if ($this->shanghaiDate($sourceDate) !== (string)$contract['target_date']) {
                throw new RuntimeException('promotion_authoritative_source_date_mismatch');
            }
            $rowCaptureEvidence = is_array($raw['capture_evidence'] ?? null)
                ? $raw['capture_evidence']
                : [];
            $captureSource = strtolower(trim((string)(
                $sourceRow['_capture_source'] ?? $sourceRow['capture_source'] ?? ''
            )));
            if ($captureSource === '') {
                $captureSource = strtolower(trim((string)($rowCaptureEvidence['capture_source'] ?? '')));
            }
            if (preg_match('/^(?:xhr|fetch|same_origin_api|browser_response|network_response)(?::|$)/', $captureSource) !== 1) {
                throw new RuntimeException('promotion_authoritative_capture_source_invalid');
            }

            $rowTraceId = trim((string)($row['source_trace_id'] ?? ''));
            $rowEvidence = $this->desensitizedEvidence($raw);
            $rowEvidenceTraceId = trim((string)($rowEvidence['source_trace_id'] ?? ''));
            $rowUrlHash = strtolower(trim((string)($rowEvidence['source_url_hash'] ?? '')));
            if ($rowTraceId === ''
                || $rowEvidenceTraceId === ''
                || !hash_equals($rowTraceId, $rowEvidenceTraceId)
                || preg_match('/^[a-f0-9]{64}$/D', $rowUrlHash) !== 1
            ) {
                throw new RuntimeException('promotion_authoritative_row_evidence_invalid');
            }

            $factsByMetric = [];
            foreach (is_array($raw['field_facts'] ?? null) ? $raw['field_facts'] : [] as $fact) {
                if (!is_array($fact)) {
                    continue;
                }
                $metricKey = strtolower(trim((string)($fact['metric_key'] ?? '')));
                if (!in_array($metricKey, $platformRequiredKeys, true)) {
                    continue;
                }
                if (isset($factsByMetric[$metricKey])) {
                    throw new RuntimeException('promotion_authoritative_metric_fact_ambiguous');
                }
                $factsByMetric[$metricKey] = $fact;
            }

            $boundedMetrics = [];
            foreach ($rowRequiredKeys as $metricKey) {
                $fact = $factsByMetric[$metricKey] ?? null;
                $sourceKey = is_array($fact) ? trim((string)($fact['source_key'] ?? '')) : '';
                $sourcePath = is_array($fact) ? trim((string)($fact['source_path'] ?? '')) : '';
                $sourceMetricValue = is_array($fact)
                    && $sourceKey !== ''
                    && array_key_exists($sourceKey, $sourceRow)
                    ? $this->authoritativeMetricNumericValue(
                        $sourceRow[$sourceKey],
                        $metricKey
                    )
                    : null;
                $factEvidence = is_array($fact) && is_array($fact['capture_evidence'] ?? null)
                    ? $fact['capture_evidence']
                    : [];
                $normalizedFactEvidence = $this->desensitizedEvidence($factEvidence);
                $factTraceId = trim((string)($normalizedFactEvidence['source_trace_id'] ?? ''));
                $factUrlHash = strtolower(trim((string)($normalizedFactEvidence['source_url_hash'] ?? '')));
                $factCaptureSource = strtolower(trim((string)($factEvidence['capture_source'] ?? '')));
                if (!is_array($fact)
                    || strtolower(trim((string)($fact['status'] ?? ''))) !== 'captured'
                    || $sourceKey === ''
                    || !array_key_exists($sourceKey, $sourceRow)
                    || $sourceMetricValue === null
                    || preg_match('/[.\[\/]/', $sourcePath) !== 1
                    || trim((string)($fact['storage_field'] ?? '')) !== $expectedStorageFields[$metricKey]
                    || ($fact['stored_value_present'] ?? null) !== true
                    || $factTraceId === ''
                    || !hash_equals($rowTraceId, $factTraceId)
                    || $factUrlHash === ''
                    || !hash_equals($rowUrlHash, $factUrlHash)
                    || !hash_equals($captureSource, $factCaptureSource)
                    || !array_key_exists($metricKey, $row)
                    || !is_numeric($row[$metricKey])
                    || abs($sourceMetricValue - (float)$row[$metricKey]) > 0.000001
                ) {
                    throw new RuntimeException('promotion_authoritative_metric_fact_invalid:' . $metricKey);
                }
                $boundedMetrics[$metricKey] = sprintf('%.8F', (float)$row[$metricKey]);
                $coveredMetricKeys[$metricKey] = true;
            }
            ksort($boundedMetrics, SORT_STRING);
            $hasNonzero = array_reduce(
                $boundedMetrics,
                static fn(bool $found, string $value): bool => $found || abs((float)$value) > 0.000001,
                false
            );
            if ($hasNonzero) {
                $nonzeroRows++;
            } else {
                // Every required zero reached this point only after matching an
                // explicit numeric source key from the structured response.
                $explicitZeroRows++;
            }
            $boundedRow = [
                'id' => (int)$row['id'],
                'source_trace_digest' => hash('sha256', $rowTraceId),
                'raw_data_digest' => hash('sha256', $rawJson),
                'metric_values' => $boundedMetrics,
                'observed_traffic_metric_keys' => $rowRequiredKeys,
                'value_status' => $hasNonzero ? 'nonzero' : 'explicit_zero',
            ];
            if ((string)$contract['data_period'] === 'historical_daily') {
                $boundedRow['capture_time'] = $captureTime;
            }
            $boundedRows[] = $boundedRow;
            $rowDigestPayload = $boundedRow;
            unset($rowDigestPayload['capture_time']);
            $rowDigests[(int)$row['id']] = $this->digest([
                'required_metric_keys' => $rowRequiredKeys,
                'rows' => [$rowDigestPayload],
            ]);
            $operationRowMetricDigests[(int)$row['id']] = $this->digest([
                'required_metric_keys' => $rowRequiredKeys,
                'metric_values' => $boundedMetrics,
                'value_status' => $hasNonzero ? 'nonzero' : 'explicit_zero',
            ]);
        }

        $coveredMetricKeys = array_keys($coveredMetricKeys);
        sort($coveredMetricKeys, SORT_STRING);
        if ($coveredMetricKeys !== $platformRequiredKeys) {
            throw new RuntimeException('promotion_authoritative_metric_union_incomplete');
        }

        ksort($rowDigests, SORT_NUMERIC);
        ksort($operationRowMetricDigests, SORT_NUMERIC);
        $candidateRowIds = array_map('intval', array_keys($operationRowMetricDigests));
        $metricProfiles = array_values(array_unique(array_values($operationRowMetricDigests)));
        $selectedOperationRowId = count($metricProfiles) === 1 && $candidateRowIds !== []
            ? min($candidateRowIds)
            : 0;
        $operationRowSelection = [
            'version' => self::OPERATION_ROW_SELECTION_VERSION,
            'status' => $selectedOperationRowId > 0 ? 'ready' : 'ambiguous',
            'policy' => self::OPERATION_ROW_SELECTION_POLICY,
            'platform' => $platform,
            'tenant_id' => (int)$contract['tenant_id'],
            'system_hotel_id' => (int)$contract['system_hotel_id'],
            'data_source_id' => (int)$contract['data_source_id'],
            'sync_task_id' => (int)$contract['sync_task_id'],
            'target_date' => (string)$contract['target_date'],
            'data_period' => (string)$contract['data_period'],
            'candidate_row_ids' => $candidateRowIds,
            'selected_row_id' => $selectedOperationRowId,
            'row_metric_digests' => $operationRowMetricDigests,
        ];
        $operationRowSelection['selection_digest'] = $this->digest($operationRowSelection);

        return [
            'digest' => $this->digest([
                'required_metric_keys' => $platformRequiredKeys,
                'rows' => $boundedRows,
            ]),
            'nonzero_required_metric_rows' => $nonzeroRows,
            'explicit_zero_confirmed_rows' => $explicitZeroRows,
            'snapshot_time_backfills' => $snapshotTimeBackfills,
            'row_digests' => $rowDigests,
            'operation_row_selection' => $operationRowSelection,
        ];
    }

    /**
     * The collector records only metric keys that were actually present in the
     * OTA response before numeric normalization. Missing, associative, or
     * non-snake-case markers cannot distinguish a real zero from a synthetic
     * default and therefore fail closed.
     *
     * @param array<string,mixed> $sourceRow
     * @return array<int,string>|null
     */
    private function observedTrafficMetricKeys(array $sourceRow): ?array
    {
        $marker = $sourceRow['_observed_traffic_metric_keys'] ?? null;
        if (!is_array($marker) || !array_is_list($marker)) {
            return null;
        }
        $keys = [];
        foreach ($marker as $metricKey) {
            if (!is_string($metricKey)
                || $metricKey !== trim($metricKey)
                || preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/D', $metricKey) !== 1
            ) {
                return null;
            }
            $keys[$metricKey] = true;
        }
        $result = array_keys($keys);
        sort($result, SORT_STRING);
        return $result;
    }

    /** @return array<int,string> */
    private function ctripRealtimeRowMetricKeys(array $row, array $raw): array
    {
        $endpointIds = [];
        foreach ([
            is_array($raw['row'] ?? null) ? $raw['row'] : [],
            is_array($raw['capture'] ?? null) ? $raw['capture'] : [],
            $raw,
            $row,
        ] as $container) {
            foreach (['endpoint_id', 'endpointId', '_endpoint_id'] as $key) {
                $endpointId = strtolower(trim((string)($container[$key] ?? '')));
                if ($endpointId !== '') {
                    $endpointIds[$endpointId] = true;
                }
            }
        }
        $dimension = strtolower(trim((string)($row['dimension'] ?? '')));
        if (preg_match('/^catalog:[^:]+:([^:]+)/', $dimension, $matches) === 1) {
            $endpointIds[strtolower(trim((string)($matches[1] ?? '')))] = true;
        }
        if (count($endpointIds) !== 1) {
            return [];
        }
        return match ((string)array_key_first($endpointIds)) {
            'business_visitor_title' => ['detail_exposure'],
            'traffic_order_overview' => ['order_submit_num'],
            default => [],
        };
    }

    /** @return array<int,string>|null */
    private function capturedTrafficMetricKeys(
        array $raw,
        array $requiredMetricKeys,
        array $expectedStorageFields
    ): ?array {
        $captured = [];
        foreach (is_array($raw['field_facts'] ?? null) ? $raw['field_facts'] : [] as $fact) {
            if (!is_array($fact) || strtolower(trim((string)($fact['status'] ?? ''))) !== 'captured') {
                continue;
            }
            $metricKey = strtolower(trim((string)($fact['metric_key'] ?? '')));
            if (in_array($metricKey, $requiredMetricKeys, true)
                && trim((string)($fact['storage_field'] ?? '')) === ($expectedStorageFields[$metricKey] ?? '')
            ) {
                $captured[$metricKey] = true;
            }
        }
        $keys = array_keys($captured);
        sort($keys, SORT_STRING);
        return $keys === [] ? null : $keys;
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $raw @param array<string,mixed> $contract */
    private function authoritativeCaptureTime(array $row, array $raw, array $contract): string
    {
        $value = trim((string)($row['snapshot_time'] ?? ''));
        if ($value === '') {
            if ((string)($contract['data_period'] ?? '') !== 'historical_daily') {
                throw new RuntimeException('promotion_authoritative_capture_time_missing');
            }
            $rawValue = $raw['captured_at'] ?? $raw['capturedAt'] ?? null;
            $value = is_scalar($rawValue) ? trim((string)$rawValue) : '';
        }
        if ($value === '') {
            throw new RuntimeException('promotion_authoritative_capture_time_missing');
        }
        if (preg_match(
            '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})?$/D',
            $value
        ) !== 1) {
            throw new RuntimeException('promotion_authoritative_capture_time_invalid');
        }

        $timezone = new \DateTimeZone('Asia/Shanghai');
        try {
            $captureTime = (new \DateTimeImmutable($value, $timezone))->setTimezone($timezone);
            $parseErrors = \DateTimeImmutable::getLastErrors();
            if (is_array($parseErrors)
                && ((int)($parseErrors['warning_count'] ?? 0) > 0
                    || (int)($parseErrors['error_count'] ?? 0) > 0)
            ) {
                throw new RuntimeException('promotion_authoritative_capture_time_invalid');
            }
            $targetStart = new \DateTimeImmutable((string)$contract['target_date'] . ' 00:00:00', $timezone);
            $futureBoundary = (new \DateTimeImmutable('now', $timezone))->modify('+5 minutes');
        } catch (\Throwable) {
            throw new RuntimeException('promotion_authoritative_capture_time_invalid');
        }
        if ($captureTime < $targetStart || $captureTime > $futureBoundary) {
            throw new RuntimeException('promotion_authoritative_capture_time_invalid');
        }
        return $captureTime->format('Y-m-d H:i:s');
    }

    /**
     * Re-resolve the current Profile binding while source, binding and hotel
     * rows are locked, then compare it with each persisted raw row using only
     * one-way identifier hashes.
     *
     * @param array<string,mixed> $selectedSource
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed> $contract
     */
    private function platformHotelIdentityProof(
        array $selectedSource,
        array $rows,
        array $contract
    ): array {
        $platform = (string)$contract['platform'];
        $tenantId = (int)$contract['tenant_id'];
        $hotelId = (int)$contract['system_hotel_id'];
        $selectedSourceId = (int)$contract['data_source_id'];
        if (!in_array(strtolower(trim((string)($selectedSource['ingestion_method'] ?? ''))), [
            'browser_profile', 'profile_browser',
        ], true)) {
            throw new RuntimeException('promotion_profile_source_required');
        }

        $selectedConfig = $this->decodedConfig($selectedSource['config_json'] ?? null);
        $selectedProfileHash = $this->profileKeyHash($platform, $selectedConfig);
        if ($selectedProfileHash === '') {
            throw new RuntimeException('promotion_profile_key_missing');
        }
        $profileSources = Db::name('platform_data_sources')
            ->where('platform', $platform)
            ->whereIn('ingestion_method', ['browser_profile', 'profile_browser'])
            ->where('enabled', 1)
            ->where('status', '<>', 'disabled')
            ->lock(true)
            ->select()
            ->toArray();
        $profileScopes = [];
        $authorityHashes = [];
        $authoritySourceIds = [];
        $identifierScopes = [];
        foreach ($profileSources as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $config = $this->decodedConfig($candidate['config_json'] ?? null);
            $profileHash = $this->profileKeyHash($platform, $config);
            $candidateHotelId = (int)($candidate['system_hotel_id'] ?? 0);
            $candidateTenantId = $candidateHotelId > 0
                ? (int)Db::name('hotels')->where('id', $candidateHotelId)->lock(true)->value('tenant_id')
                : 0;
            if ($profileHash === $selectedProfileHash) {
                if ($candidateHotelId <= 0 || $candidateTenantId <= 0) {
                    throw new RuntimeException('promotion_profile_scope_metadata_missing');
                }
                $profileScopes[$candidateTenantId . ':' . $candidateHotelId] = true;
            }
            $trafficCapable = OtaTrafficAttributionService::sourceCanProvideTraffic(
                $candidate,
                $config
            );
            $sourceTenantReady = $candidateTenantId > 0
                && (int)($candidate['tenant_id'] ?? 0) === $candidateTenantId;
            $bindingReady = $trafficCapable
                && $sourceTenantReady
                && $this->activeProfileBindingMatches(
                    $platform,
                    $profileHash,
                    $candidateTenantId,
                    $candidateHotelId
                );
            $identifierHashes = $trafficCapable
                ? $this->platformIdentifierHashes($config, $platform)
                : [];
            if ($bindingReady && $identifierHashes !== []) {
                $scopeKey = $candidateTenantId . ':' . $candidateHotelId;
                foreach ($identifierHashes as $identifierHash) {
                    $identifierScopes[$identifierHash][$scopeKey] = true;
                }
            }
            if ($candidateHotelId !== $hotelId || !$trafficCapable) {
                continue;
            }
            if ((int)($candidate['tenant_id'] ?? 0) !== $tenantId
                || $candidateTenantId !== $tenantId
            ) {
                throw new RuntimeException('promotion_profile_source_tenant_scope_mismatch');
            }
            if (!$bindingReady) {
                throw new RuntimeException('promotion_profile_binding_unverified');
            }
            if ($identifierHashes === []) {
                throw new RuntimeException('promotion_profile_identifier_missing');
            }
            foreach ($identifierHashes as $identifierHash) {
                $authorityHashes[$identifierHash] = true;
            }
            $authoritySourceIds[] = (int)($candidate['id'] ?? 0);
        }
        foreach ($identifierScopes as $scopes) {
            if (count($scopes) > 1) {
                throw new RuntimeException('promotion_platform_identifier_scope_conflict');
            }
        }
        if (count($profileScopes) !== 1) {
            throw new RuntimeException('promotion_profile_scope_conflict');
        }
        $authoritySourceIds = $this->positiveIds($authoritySourceIds);
        if (!in_array($selectedSourceId, $authoritySourceIds, true)
            || count($authorityHashes) !== 1
        ) {
            throw new RuntimeException('promotion_profile_identifier_ambiguous');
        }
        $expectedIdentifierHash = (string)array_key_first($authorityHashes);

        $boundedRows = [];
        $rowDigests = [];
        foreach ($rows as $row) {
            $raw = json_decode((string)($row['raw_data'] ?? ''), true);
            $rowIdentifierHashes = is_array($raw)
                ? $this->rowPlatformIdentifierHashes($raw, $platform)
                : [];
            if (count($rowIdentifierHashes) !== 1
                || !hash_equals($expectedIdentifierHash, $rowIdentifierHashes[0])
            ) {
                throw new RuntimeException('promotion_platform_hotel_identifier_mismatch');
            }
            $boundedRow = [
                'id' => (int)($row['id'] ?? 0),
                'identifier_match_digest' => hash(
                    'sha256',
                    $expectedIdentifierHash . "\0" . $rowIdentifierHashes[0]
                ),
            ];
            $boundedRows[] = $boundedRow;
            $rowDigests[(int)($row['id'] ?? 0)] = $this->digest([
                'authority_source_ids' => $authoritySourceIds,
                'expected_identifier_digest' => hash('sha256', $expectedIdentifierHash),
                'profile_scope_digest' => hash('sha256', $tenantId . ':' . $hotelId),
                'rows' => [$boundedRow],
            ]);
        }
        usort($boundedRows, static fn(array $left, array $right): int =>
            (int)$left['id'] <=> (int)$right['id']
        );
        ksort($rowDigests, SORT_NUMERIC);
        return [
            'digest' => $this->digest([
                'authority_source_ids' => $authoritySourceIds,
                'expected_identifier_digest' => hash('sha256', $expectedIdentifierHash),
                'profile_scope_digest' => hash('sha256', $tenantId . ':' . $hotelId),
                'rows' => $boundedRows,
            ]),
            'row_digests' => $rowDigests,
        ];
    }

    private function activeProfileBindingMatches(
        string $platform,
        string $profileKeyHash,
        int $tenantId,
        int $hotelId
    ): bool {
        if ($profileKeyHash === '') {
            return false;
        }
        $bindings = Db::name('ota_profile_bindings')
            ->where('platform', $platform)
            ->where('profile_key_hash', $profileKeyHash)
            ->where('binding_status', 'active')
            ->lock(true)
            ->select()
            ->toArray();
        return count($bindings) === 1
            && (int)($bindings[0]['tenant_id'] ?? 0) === $tenantId
            && (int)($bindings[0]['system_hotel_id'] ?? 0) === $hotelId;
    }

    /** @return array<string,mixed> */
    private function decodedConfig(mixed $value): array
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

    private function profileKeyHash(string $platform, array $config): string
    {
        $keys = $platform === 'meituan'
            ? ['profile_binding_key', 'profileBindingKey', 'stable_profile_id', 'stableProfileId', 'profile_id', 'profileId', 'browser_profile_id', 'browserProfileId', 'store_id', 'storeId', 'poi_id', 'poiId']
            : ['profile_binding_key', 'profileBindingKey', 'stable_profile_id', 'stableProfileId', 'profile_id', 'profileId', 'browser_profile_id', 'browserProfileId'];
        $profileKey = '';
        foreach ($keys as $key) {
            if (is_scalar($config[$key] ?? null) && trim((string)$config[$key]) !== '') {
                $profileKey = trim((string)$config[$key]);
                break;
            }
        }
        if ($profileKey === '') {
            return '';
        }
        $safeFilePart = BrowserProfileCaptureRequestService::safeFilePart($profileKey);
        return $safeFilePart === '' || $safeFilePart === 'default'
            ? ''
            : hash('sha256', $safeFilePart);
    }

    private function authoritativeMetricNumericValue(mixed $value, string $metricKey): ?float
    {
        if (is_numeric($value)) {
            return (float)$value;
        }
        if ($metricKey !== 'flow_rate' || !is_string($value)) {
            return null;
        }
        if (preg_match(
            '/^([+-]?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+))\s*%$/D',
            trim($value),
            $matches
        ) !== 1) {
            return null;
        }
        return (float)$matches[1];
    }

    /** @return array<int,string> */
    private function rowPlatformIdentifierHashes(array $raw, string $platform): array
    {
        $identityProof = strtolower(trim((string)(
            $raw['platform_hotel_identifier_proof']
            ?? (is_array($raw['row'] ?? null)
                ? ($raw['row']['platform_hotel_identifier_proof'] ?? '')
                : '')
        )));
        if ($identityProof === 'row_field_present' && is_array($raw['row'] ?? null)) {
            return $this->platformIdentifierHashes($raw['row'], $platform);
        }
        return $this->platformIdentifierHashes($raw, $platform);
    }

    /** @return array<int,string> */
    private function platformIdentifierHashes(array $container, string $platform): array
    {
        $priorityGroups = $platform === 'meituan'
            ? [['poiid', 'mtpoiid'], ['storeid', 'shopid'], ['partnerid']]
            : [['hotelid', 'ctriphotelid', 'masterhotelid'], ['nodeid']];
        $keyPriorities = [];
        foreach ($priorityGroups as $priority => $keys) {
            foreach ($keys as $key) {
                $keyPriorities[$key] = $priority;
            }
        }
        $hashesByPriority = array_fill(0, count($priorityGroups), []);
        $visited = 0;
        $visit = static function (array $value, int $depth) use (
            &$visit,
            &$hashesByPriority,
            &$visited,
            $keyPriorities,
            $platform
        ): void {
            if ($depth > 12 || $visited >= 10000) {
                return;
            }
            foreach ($value as $key => $item) {
                $visited++;
                if ($visited > 10000) {
                    return;
                }
                $normalizedKey = strtolower((string)preg_replace(
                    '/[^a-z0-9]+/i',
                    '',
                    (string)$key
                ));
                if (isset($keyPriorities[$normalizedKey])
                    && (is_string($item) || is_int($item) || is_float($item))
                    && trim((string)$item) !== ''
                ) {
                    $priority = (int)$keyPriorities[$normalizedKey];
                    $hashesByPriority[$priority][hash(
                        'sha256',
                        $platform . "\0" . trim((string)$item)
                    )] = true;
                }
                if (is_array($item)) {
                    $visit($item, $depth + 1);
                }
            }
        };
        $visit($container, 0);
        foreach ($hashesByPriority as $hashes) {
            if ($hashes !== []) {
                $result = array_keys($hashes);
                sort($result, SORT_STRING);
                return $result;
            }
        }
        return [];
    }

    /** @return array<string,string> */
    private function desensitizedEvidence(array $source): array
    {
        $evidence = [];
        $aliases = [
            'source_trace_id' => ['source_trace_id', '_source_trace_id', 'trace_id', '_trace_id'],
            'source_url_hash' => ['source_url_hash', '_source_url_hash', 'url_hash', '_url_hash'],
        ];
        foreach ($aliases as $target => $keys) {
            foreach ($keys as $key) {
                if (is_scalar($source[$key] ?? null) && trim((string)$source[$key]) !== '') {
                    $evidence[$target] = trim((string)$source[$key]);
                    break;
                }
            }
        }
        if (is_array($source['capture_evidence'] ?? null)) {
            foreach ($this->desensitizedEvidence($source['capture_evidence']) as $key => $value) {
                $evidence[$key] ??= $value;
            }
        }
        return $evidence;
    }

    private function shanghaiDate(mixed $value): string
    {
        if (!is_scalar($value) || trim((string)$value) === '') {
            return '';
        }
        try {
            $date = new \DateTimeImmutable((string)$value, new \DateTimeZone('Asia/Shanghai'));
            return $date->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
        } catch (\Throwable) {
            return '';
        }
    }

    /** @param array<int,int> $expectedRowIds @return array<int,string> */
    private function rowDigestMap(mixed $value, array $expectedRowIds): array
    {
        if (!is_array($value)) {
            return [];
        }
        $digests = [];
        foreach ($value as $rawRowId => $rawDigest) {
            $rowId = (int)$rawRowId;
            $digest = strtolower(trim((string)$rawDigest));
            if ($rowId <= 0
                || (string)$rawRowId !== (string)$rowId
                || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1
                || isset($digests[$rowId])
            ) {
                return [];
            }
            $digests[$rowId] = $digest;
        }
        ksort($digests, SORT_NUMERIC);
        return array_keys($digests) === $expectedRowIds ? $digests : [];
    }

    /**
     * @param array<string,mixed> $receipt
     * @param array<string,mixed> $contract
     * @param array<int,int> $rowIds
     */
    private function operationRowSelectionReceiptMatches(
        array $receipt,
        array $contract,
        array $rowIds
    ): bool {
        $selection = is_array($contract['operation_row_selection'] ?? null)
            ? $contract['operation_row_selection']
            : [];
        if ($selection === []) {
            return false;
        }
        $selectionFields = [
            'operation_row_selection_version',
            'operation_row_selection_status',
            'operation_row_selection_policy',
            'operation_row_candidate_ids',
            'selected_operation_row_id',
            'operation_row_metric_digests',
            'operation_row_selection_digest',
        ];
        foreach ($selectionFields as $field) {
            if (!array_key_exists($field, $receipt)) {
                return false;
            }
        }

        $metricDigests = $this->rowDigestMap(
            $receipt['operation_row_metric_digests'] ?? null,
            $rowIds
        );
        $selectionDigest = strtolower(trim((string)(
            $receipt['operation_row_selection_digest'] ?? ''
        )));
        return (string)($receipt['operation_row_selection_version'] ?? '')
                === self::OPERATION_ROW_SELECTION_VERSION
            && (string)($receipt['operation_row_selection_status'] ?? '')
                === (string)($selection['status'] ?? '')
            && (string)($receipt['operation_row_selection_policy'] ?? '')
                === self::OPERATION_ROW_SELECTION_POLICY
            && $this->positiveIds($receipt['operation_row_candidate_ids'] ?? []) === $rowIds
            && (int)($receipt['selected_operation_row_id'] ?? 0)
                === (int)($selection['selected_row_id'] ?? 0)
            && $metricDigests === ($selection['row_metric_digests'] ?? [])
            && preg_match('/^[a-f0-9]{64}$/D', $selectionDigest) === 1
            && hash_equals(
                (string)($selection['selection_digest'] ?? ''),
                $selectionDigest
            );
    }

    /** @return array<int,int> */
    private function positiveIds(mixed $value): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            'intval',
            is_array($value) ? $value : []
        ), static fn(int $id): bool => $id > 0)));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** @return array<int,string> */
    private function metricKeyList(mixed $value): array
    {
        $keys = array_values(array_unique(array_filter(array_map(
            static fn(mixed $key): string => strtolower(trim((string)$key)),
            is_array($value) ? $value : []
        ), static fn(string $key): bool => preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $key) === 1)));
        sort($keys, SORT_STRING);
        return $keys;
    }

    /** @return array<int,string> */
    private function platformList(mixed $value): array
    {
        $platforms = array_values(array_unique(array_filter(array_map(
            static fn(mixed $platform): string => strtolower(trim((string)$platform)),
            is_array($value) ? $value : []
        ), static fn(string $platform): bool => in_array($platform, ['ctrip', 'meituan'], true))));
        sort($platforms, SORT_STRING);
        return $platforms;
    }

    /** @return array<string,mixed> */
    private function decode(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $value */
    private function digest(array $value): string
    {
        unset($value['content_digest']);
        ksort($value, SORT_STRING);
        return hash('sha256', json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    private function assertDeadline(?float $deadlineAt): void
    {
        if ($deadlineAt !== null && microtime(true) >= $deadlineAt) {
            throw new RuntimeException('canonical_history_promotion_deadline_reached');
        }
    }

    /**
     * Bound row and metadata lock waits on the exact connection used by the
     * transaction. The final in-transaction deadline assertion then prevents
     * a late promotion from committing after its queue budget has expired.
     *
     * @return array{pdo?:\PDO,innodb_lock_wait_timeout?:int,lock_wait_timeout?:int}
     */
    private function applyDatabaseLockBudget(?float $deadlineAt): array
    {
        if ($deadlineAt === null) {
            return [];
        }
        $this->assertDeadline($deadlineAt);
        $pdo = Db::connect()->getPdo();
        if (strtolower((string)$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) !== 'mysql') {
            return [];
        }
        $statement = $pdo->query(
            'SELECT @@SESSION.innodb_lock_wait_timeout AS innodb_lock_wait_timeout, '
            . '@@SESSION.lock_wait_timeout AS lock_wait_timeout'
        );
        $current = $statement !== false ? $statement->fetch(\PDO::FETCH_ASSOC) : false;
        if (!is_array($current)) {
            throw new RuntimeException('canonical_history_promotion_lock_budget_unavailable');
        }
        $remainingSeconds = max(1, (int)ceil($deadlineAt - microtime(true)));
        $budget = [
            'pdo' => $pdo,
            'innodb_lock_wait_timeout' => max(1, (int)($current['innodb_lock_wait_timeout'] ?? 1)),
            'lock_wait_timeout' => max(1, (int)($current['lock_wait_timeout'] ?? 1)),
        ];
        try {
            $pdo->exec(
                'SET SESSION innodb_lock_wait_timeout = '
                . min($remainingSeconds, $budget['innodb_lock_wait_timeout'])
            );
            $pdo->exec(
                'SET SESSION lock_wait_timeout = '
                . min($remainingSeconds, $budget['lock_wait_timeout'])
            );
            $this->assertDeadline($deadlineAt);
            return $budget;
        } catch (\Throwable $exception) {
            $this->restoreDatabaseLockBudget($budget);
            throw $exception;
        }
    }

    /** @param array{pdo?:\PDO,innodb_lock_wait_timeout?:int,lock_wait_timeout?:int} $budget */
    private function restoreDatabaseLockBudget(array $budget): void
    {
        $pdo = $budget['pdo'] ?? null;
        if (!$pdo instanceof \PDO) {
            return;
        }
        try {
            $pdo->exec(
                'SET SESSION innodb_lock_wait_timeout = '
                . max(1, (int)($budget['innodb_lock_wait_timeout'] ?? 1))
            );
            $pdo->exec(
                'SET SESSION lock_wait_timeout = '
                . max(1, (int)($budget['lock_wait_timeout'] ?? 1))
            );
        } catch (\Throwable) {
            // The CLI connection is ending; never mask the promotion outcome.
        }
    }

    private function validDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed instanceof \DateTimeImmutable && $parsed->format('Y-m-d') === $date;
    }
}
