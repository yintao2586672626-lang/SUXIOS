<?php
declare(strict_types=1);

namespace app\service\concern;

use app\service\CloudOtaBundleCodec;
use app\service\CollectionResultContractService;
use app\service\OtaOperatingScope;
use app\service\OtaStructuredCaptureEvidenceService;
use app\service\OtaTrafficAttributionService;
use think\facade\Db;

/**
 * Exact-run task readback, metric verification, and capture-strategy evidence.
 */
trait PlatformSyncTaskReadbackConcern
{
    use PlatformSyncTaskReadbackCoverageConcern;

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
        $taskStats = $this->decodeConfig($task['stats_json'] ?? []);
        $dispatcherRunId = $this->normalizeSyncDispatcherRunId(
            $taskStats['dispatcher_run_id'] ?? ''
        );
        $triggerType = strtolower(trim((string)($task['trigger_type'] ?? '')));
        if (preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $triggerType) !== 1) {
            $triggerType = '';
        }
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
            'target_date_expected_row_ids' => [],
            'target_date_expected_row_count' => 0,
            'exact_coverage' => $this->targetDateExactCoverage([], []),
            'failure_reason' => '',
        ];
        if ($dispatcherRunId !== '') {
            $receipt['dispatcher_run_id'] = $dispatcherRunId;
        }
        if ($triggerType !== '') {
            $receipt['trigger_type'] = $triggerType;
        }

        if ($taskId <= 0 || $sourceId <= 0 || $hotelId <= 0 || !in_array($platform, ['ctrip', 'meituan'], true)
            || $targetDate === '' || $dataPeriod === '' || $startedAt === ''
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
            $expectation = $this->resolveTargetDateReadbackExpectation(
                $saveReceipt,
                $columns,
                $taskId,
                $sourceId,
                $hotelId,
                $platform,
                $targetDate,
                $dataPeriod,
                $this->isOtaBrowserProfileSource($source)
            );
            $receipt['target_date_expected_row_ids'] = $expectation['target_date_expected_row_ids'];
            $receipt['target_date_expected_row_count'] = $expectation['target_date_expected_row_count'];
            $receipt['exact_coverage'] = $expectation['exact_coverage'];
            if (($expectation['ok'] ?? false) !== true) {
                $receipt['failure_reason'] = (string)($expectation['failure_reason'] ?? 'run_readback_receipt_mismatch');
                return $receipt;
            }
            $expectedTargetRowIds = $expectation['target_date_expected_row_ids'];
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
        sort($rowIds, SORT_NUMERIC);
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
        $receipt['observed_platform_hotel_id'] = $this->observedPlatformHotelIdFromRunRows($rows, $platform);
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

        // A Profile run may also persist forecast or realtime rows. Derive the
        // target-day expectation from every exact ID in the save receipt, then
        // require the scoped query to cover that set exactly. A returned subset
        // or an unrelated extra row cannot prove persistence readback.
        $receipt['exact_coverage'] = $this->targetDateExactCoverage($expectedTargetRowIds, $rowIds);
        $receiptRowsBound = $rows !== []
            && count($rowIds) === count($rows)
            && ($receipt['exact_coverage']['complete'] ?? false) === true;
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
    private function observedPlatformHotelIdFromRunRows(array $rows, string $platform = 'ctrip'): string
    {
        $platform = strtolower(trim($platform));
        $observed = [];
        foreach ($rows as $row) {
            $raw = $this->decodeConfig($row['raw_data'] ?? []);
            $detail = is_array($raw['row'] ?? null) ? $raw['row'] : [];
            $proof = strtolower(trim((string)($raw['platform_hotel_identifier_proof'] ?? '')));
            if ($proof !== 'row_field_present') {
                continue;
            }
            $compareType = '';
            foreach ([
                $row['compare_type'] ?? null,
                $raw['compare_type'] ?? null,
                $detail['compare_type'] ?? $detail['compareType'] ?? null,
            ] as $candidate) {
                $candidate = strtolower(trim((string)$candidate));
                if ($candidate !== '') {
                    $compareType = $candidate;
                    break;
                }
            }
            if ($platform === 'meituan') {
                $isSelf = in_array($compareType, ['self', 'own', 'mine', 'current'], true)
                    || ($raw['is_self'] ?? $raw['isSelf'] ?? null) === true
                    || ($detail['is_self'] ?? $detail['isSelf'] ?? null) === true
                    || (string)($raw['is_self'] ?? $raw['isSelf'] ?? '') === '1'
                    || (string)($detail['is_self'] ?? $detail['isSelf'] ?? '') === '1';
                if (!$isSelf) {
                    continue;
                }
            } elseif (in_array(
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
        // Keep same-platform/date DOM traffic rows only as strategy evidence:
        // they can prove that this run fell back to the page, but may never
        // complete or override a structured P0 metric set.
        $strategyRows = $authoritativeTrafficRows !== []
            ? $authoritativeTrafficRows
            : $rows;
        if ($authoritativeTrafficRows !== []) {
            $authoritativeDates = [];
            foreach ($authoritativeTrafficRows as $row) {
                $date = $this->normalizeDate($row['data_date'] ?? $row['dataDate'] ?? null);
                if ($date !== null) {
                    $authoritativeDates[$date] = true;
                }
            }
            $diagnosticDomTrafficRows = array_values(array_filter(
                $rows,
                function (array $row) use ($source, $platform, $policy, $authoritativeDates): bool {
                    $dataType = strtolower(trim((string)($row['data_type'] ?? '')));
                    $date = $this->normalizeDate($row['data_date'] ?? $row['dataDate'] ?? null);
                    return in_array($dataType, ['traffic', 'flow', 'conversion'], true)
                        && $date !== null
                        && isset($authoritativeDates[$date])
                        && OtaTrafficAttributionService::rowBelongsToOwnPlatformTraffic($row, $platform)
                        && ($policy->classifyRow($row, $source)['status'] ?? '')
                            === OtaStructuredCaptureEvidenceService::STATUS_DOM;
                }
            ));
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
            } elseif ($diagnosticDomTrafficRows !== []) {
                $strategyRows = array_merge($authoritativeTrafficRows, $diagnosticDomTrafficRows);
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
}
