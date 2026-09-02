<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;
use think\facade\Db;

/**
 * Read-only authority boundary for canonical OTA history.
 *
 * Persisted row flags and self-consistent raw JSON are not an authority root.
 * A row is returned only when its source, task, exact-run readback and v3
 * canonical promotion receipt all revalidate against the current row bytes.
 */
class CanonicalOtaHistoryReceiptVerifier
{
    public const MAX_WINDOW_CANDIDATE_ROWS = 2000;

    private const PROMOTION_VERSION = 'ota_canonical_history_promotion.v3';
    private const OPERATION_SELECTION_VERSION = 'ota_operation_row_selection.v1';
    private const OPERATION_SELECTION_POLICY =
        'singleton_or_equivalent_required_metrics_min_row_id.v1';
    private const REQUIRED_METRICS = [
        'detail_exposure',
        'flow_rate',
        'list_exposure',
        'order_filling_num',
        'order_submit_num',
    ];
    private const STORAGE_FIELDS = [
        'detail_exposure' => 'online_daily_data.detail_exposure',
        'flow_rate' => 'online_daily_data.flow_rate',
        'list_exposure' => 'online_daily_data.list_exposure',
        'order_filling_num' => 'online_daily_data.order_filling_num',
        'order_submit_num' => 'online_daily_data.order_submit_num',
    ];
    private const ROW_COLUMNS = [
        'id',
        'tenant_id',
        'system_hotel_id',
        'hotel_id',
        'data_source_id',
        'sync_task_id',
        'source',
        'platform',
        'data_date',
        'data_period',
        'data_type',
        'dimension',
        'compare_type',
        'readback_verified',
        'history_status',
        'validation_status',
        'ingestion_method',
        'source_trace_id',
        'snapshot_time',
        'raw_data',
        'detail_exposure',
        'flow_rate',
        'list_exposure',
        'order_filling_num',
        'order_submit_num',
    ];
    private const SOURCE_COLUMNS = [
        'id',
        'tenant_id',
        'system_hotel_id',
        'platform',
        'status',
        'enabled',
        'ingestion_method',
    ];
    private const TASK_COLUMNS = [
        'id',
        'tenant_id',
        'data_source_id',
        'system_hotel_id',
        'platform',
        'status',
        'stats_json',
    ];
    private const TRUSTED_INGESTION_METHODS = ['browser_profile', 'profile_browser'];

    /**
     * @param array<string,array{start:string,end:string}> $windows
     * @return array<string,array<string,mixed>>
     */
    public function verifyWindows(int $systemHotelId, array $windows): array
    {
        $normalizedWindows = [];
        $results = [];
        foreach ($windows as $key => $window) {
            $start = trim((string)($window['start'] ?? ''));
            $end = trim((string)($window['end'] ?? ''));
            if (!$this->isDate($start) || !$this->isDate($end) || $start > $end) {
                $results[(string)$key] = $this->windowResult(
                    'blocked',
                    [],
                    0,
                    0,
                    ['canonical_ota_history_date_range_invalid']
                );
                continue;
            }
            $normalizedWindows[(string)$key] = ['start' => $start, 'end' => $end];
        }
        if ($normalizedWindows === []) {
            return $results;
        }

        $tenantId = $this->authoritativeTenantId($systemHotelId);
        if ($tenantId <= 0) {
            return $this->blockWindows(
                $results,
                $normalizedWindows,
                ['canonical_ota_history_hotel_tenant_unavailable']
            );
        }
        $schemaGaps = $this->schemaGaps();
        if ($schemaGaps !== []) {
            return $this->blockWindows($results, $normalizedWindows, $schemaGaps);
        }

        $rangeStart = min(array_column($normalizedWindows, 'start'));
        $rangeEnd = max(array_column($normalizedWindows, 'end'));
        try {
            $countsByDate = $this->candidateCountsByDate(
                $tenantId,
                $systemHotelId,
                $rangeStart,
                $rangeEnd
            );
        } catch (\Throwable) {
            return $this->blockWindows(
                $results,
                $normalizedWindows,
                ['canonical_ota_history_candidate_count_failed']
            );
        }

        $eligibleDates = [];
        foreach ($normalizedWindows as $key => $window) {
            $candidateCount = $this->countRange($countsByDate, $window['start'], $window['end']);
            if ($candidateCount > self::MAX_WINDOW_CANDIDATE_ROWS) {
                $results[$key] = $this->windowResult(
                    'blocked',
                    [],
                    $candidateCount,
                    0,
                    ['ctrip_traffic_history_row_limit_exceeded']
                );
                continue;
            }
            foreach ($countsByDate as $date => $count) {
                if ($count > 0 && $date >= $window['start'] && $date <= $window['end']) {
                    $eligibleDates[$date] = true;
                }
            }
        }
        if ($eligibleDates === []) {
            foreach ($normalizedWindows as $key => $window) {
                if (!isset($results[$key])) {
                    $results[$key] = $this->windowResult(
                        'empty',
                        [],
                        0,
                        0,
                        ['canonical_ota_history_candidates_missing']
                    );
                }
            }
            return $results;
        }

        try {
            $rows = $this->candidateRows(
                $tenantId,
                $systemHotelId,
                array_keys($eligibleDates)
            );
            [$sourcesById, $tasksById] = $this->authorityRows($rows);
        } catch (\Throwable) {
            return $this->blockWindows(
                $results,
                $normalizedWindows,
                ['canonical_ota_history_authority_read_failed']
            );
        }

        $rowsById = [];
        $rowsByDate = [];
        foreach ($rows as $row) {
            $rowId = (int)($row['id'] ?? 0);
            $date = substr(trim((string)($row['data_date'] ?? '')), 0, 10);
            if ($rowId > 0 && $this->isDate($date)) {
                $rowsById[$rowId] = $row;
                $rowsByDate[$date][] = $row;
            }
        }

        $taskProofs = [];
        foreach ($rows as $row) {
            $taskId = (int)($row['sync_task_id'] ?? 0);
            if ($taskId <= 0 || array_key_exists($taskId, $taskProofs)) {
                continue;
            }
            try {
                $taskProofs[$taskId] = $this->verifyTaskReceipt(
                    $tasksById[$taskId] ?? [],
                    $sourcesById[(int)($row['data_source_id'] ?? 0)] ?? [],
                    $rowsById,
                    $tenantId,
                    $systemHotelId
                );
            } catch (\Throwable $error) {
                $reason = trim($error->getMessage());
                $taskProofs[$taskId] = [
                    'ready' => false,
                    'reason' => preg_match('/^[a-z0-9_:-]{1,160}$/D', $reason) === 1
                        ? $reason
                        : 'canonical_ota_history_receipt_invalid',
                    'selected_row_id' => 0,
                    'receipt_row_ids' => [],
                    'run_row_ids' => [],
                ];
            }
        }

        $authoritativeByDate = [];
        $ignoredByDate = [];
        $gapsByDate = [];
        foreach ($rowsByDate as $date => $dateRows) {
            foreach ($dateRows as $row) {
                $rowId = (int)$row['id'];
                $taskProof = $taskProofs[(int)($row['sync_task_id'] ?? 0)] ?? [
                    'ready' => false,
                    'reason' => 'canonical_ota_history_task_missing',
                    'selected_row_id' => 0,
                    'receipt_row_ids' => [],
                    'run_row_ids' => [],
                ];
                if (($taskProof['ready'] ?? false) === true
                    && (int)($taskProof['selected_row_id'] ?? 0) === $rowId
                ) {
                    $authoritativeByDate[$date][$rowId] = true;
                    continue;
                }
                $knownUnselected = ($taskProof['ready'] ?? false) === true
                    && (in_array($rowId, (array)($taskProof['receipt_row_ids'] ?? []), true)
                        || in_array($rowId, (array)($taskProof['run_row_ids'] ?? []), true));
                if ($knownUnselected || !$this->claimsStrictAuthority($row)) {
                    $ignoredByDate[$date] = ($ignoredByDate[$date] ?? 0) + 1;
                    continue;
                }
                $reason = trim((string)($taskProof['reason'] ?? ''));
                $gapsByDate[$date][$reason !== ''
                    ? $reason
                    : 'canonical_ota_history_unverified_strict_row'] = true;
            }
        }

        foreach ($normalizedWindows as $key => $window) {
            if (isset($results[$key])) {
                continue;
            }
            $ids = [];
            $ignored = 0;
            $gaps = [];
            foreach ($rowsByDate as $date => $_dateRows) {
                if ($date < $window['start'] || $date > $window['end']) {
                    continue;
                }
                foreach (array_keys($authoritativeByDate[$date] ?? []) as $rowId) {
                    $ids[(int)$rowId] = true;
                }
                $ignored += (int)($ignoredByDate[$date] ?? 0);
                foreach (array_keys($gapsByDate[$date] ?? []) as $gap) {
                    $gaps[$gap] = true;
                }
            }
            $rowIds = array_map('intval', array_keys($ids));
            sort($rowIds, SORT_NUMERIC);
            $candidateCount = $this->countRange($countsByDate, $window['start'], $window['end']);
            if (count($rowIds) > self::MAX_WINDOW_CANDIDATE_ROWS) {
                $gaps['ctrip_traffic_history_row_limit_exceeded'] = true;
            }
            if ($rowIds === [] && $gaps === []) {
                $gaps['canonical_ota_history_authoritative_rows_missing'] = true;
            }
            $gapList = array_keys($gaps);
            $results[$key] = $this->windowResult(
                $gapList === [] ? 'ready' : ($candidateCount === 0 ? 'empty' : 'blocked'),
                $gapList === [] ? $rowIds : [],
                $candidateCount,
                $ignored,
                $gapList
            );
        }
        return $results;
    }

    /** @return array<int,string> */
    private function schemaGaps(): array
    {
        $requirements = [
            'online_daily_data' => self::ROW_COLUMNS,
            'platform_data_sources' => self::SOURCE_COLUMNS,
            'platform_data_sync_tasks' => self::TASK_COLUMNS,
        ];
        $gaps = [];
        foreach ($requirements as $table => $requiredColumns) {
            $inspection = DatabaseSchemaRequirement::inspectTableColumns($table);
            if (($inspection['status'] ?? '') !== DatabaseSchemaRequirement::STATUS_PRESENT) {
                $gaps[] = 'canonical_ota_history_' . $table . '_unavailable';
                continue;
            }
            foreach (array_diff($requiredColumns, (array)($inspection['columns'] ?? [])) as $column) {
                $gaps[] = 'canonical_ota_history_' . $table . '_' . $column . '_column_missing';
            }
        }
        return array_values(array_unique($gaps));
    }

    /** @return array<string,int> */
    private function candidateCountsByDate(
        int $tenantId,
        int $systemHotelId,
        string $startDate,
        string $endDate
    ): array {
        $rows = Db::query(
            'SELECT `data_date`, COUNT(*) AS `candidate_count` FROM `online_daily_data` '
            . 'WHERE `tenant_id` = ? AND `system_hotel_id` = ? '
            . 'AND `source` = ? AND `platform` = ? '
            . 'AND `data_date` BETWEEN ? AND ? '
            . 'AND `data_type` IN (?,?,?) GROUP BY `data_date` ORDER BY `data_date` ASC',
            [
                $tenantId,
                $systemHotelId,
                'ctrip',
                'ctrip',
                $startDate,
                $endDate,
                'traffic',
                'flow',
                'conversion',
            ]
        );
        $counts = [];
        foreach ($rows as $row) {
            $date = substr(trim((string)($row['data_date'] ?? '')), 0, 10);
            if ($this->isDate($date)) {
                $counts[$date] = max(0, (int)($row['candidate_count'] ?? 0));
            }
        }
        return $counts;
    }

    /** @param array<int,string> $dates @return array<int,array<string,mixed>> */
    private function candidateRows(int $tenantId, int $systemHotelId, array $dates): array
    {
        $dates = array_values(array_unique(array_filter(array_map('strval', $dates))));
        sort($dates, SORT_STRING);
        if ($dates === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($dates), '?'));
        return Db::query(
            'SELECT ' . $this->quotedFields(self::ROW_COLUMNS) . ' FROM `online_daily_data` '
            . 'WHERE `tenant_id` = ? AND `system_hotel_id` = ? '
            . 'AND `source` = ? AND `platform` = ? '
            . 'AND `data_date` IN (' . $placeholders . ') '
            . 'AND `data_type` IN (?,?,?) ORDER BY `data_date` ASC, `id` ASC',
            array_merge(
                [$tenantId, $systemHotelId, 'ctrip', 'ctrip'],
                $dates,
                ['traffic', 'flow', 'conversion']
            )
        );
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>}
     */
    private function authorityRows(array $rows): array
    {
        $sourceIds = $this->positiveIds(array_column($rows, 'data_source_id'));
        $taskIds = $this->positiveIds(array_column($rows, 'sync_task_id'));
        $sources = $sourceIds === [] ? [] : Db::query(
            'SELECT ' . $this->quotedFields(self::SOURCE_COLUMNS)
            . ' FROM `platform_data_sources` WHERE `id` BETWEEN ? AND ? ORDER BY `id` ASC',
            [min($sourceIds), max($sourceIds)]
        );
        $tasks = $taskIds === [] ? [] : Db::query(
            'SELECT ' . $this->quotedFields(self::TASK_COLUMNS)
            . ' FROM `platform_data_sync_tasks` WHERE `id` BETWEEN ? AND ? ORDER BY `id` ASC',
            [min($taskIds), max($taskIds)]
        );
        return [$this->indexRows($sources), $this->indexRows($tasks)];
    }

    /**
     * @param array<string,mixed> $task
     * @param array<string,mixed> $source
     * @param array<int,array<string,mixed>> $rowsById
     * @return array<string,mixed>
     */
    private function verifyTaskReceipt(
        array $task,
        array $source,
        array $rowsById,
        int $tenantId,
        int $systemHotelId
    ): array {
        if ((int)($source['id'] ?? 0) <= 0
            || (int)($source['tenant_id'] ?? 0) !== $tenantId
            || (int)($source['system_hotel_id'] ?? 0) !== $systemHotelId
            || strtolower(trim((string)($source['platform'] ?? ''))) !== 'ctrip'
            || strtolower(trim((string)($source['status'] ?? ''))) !== 'success'
            || (int)($source['enabled'] ?? 0) !== 1
            || !in_array(
                strtolower(trim((string)($source['ingestion_method'] ?? ''))),
                self::TRUSTED_INGESTION_METHODS,
                true
            )
        ) {
            throw new RuntimeException('canonical_ota_history_source_scope_invalid');
        }
        $taskId = (int)($task['id'] ?? 0);
        $sourceId = (int)$source['id'];
        if ($taskId <= 0
            || (int)($task['tenant_id'] ?? 0) !== $tenantId
            || (int)($task['system_hotel_id'] ?? 0) !== $systemHotelId
            || (int)($task['data_source_id'] ?? 0) !== $sourceId
            || strtolower(trim((string)($task['platform'] ?? ''))) !== 'ctrip'
        ) {
            throw new RuntimeException('canonical_ota_history_task_scope_invalid');
        }
        if (strtolower(trim((string)($task['status'] ?? ''))) !== 'success') {
            throw new RuntimeException('canonical_ota_history_task_not_success');
        }
        $stats = $this->decodeObject($task['stats_json'] ?? null);
        $runReadback = is_array($stats['run_readback'] ?? null) ? $stats['run_readback'] : [];
        $promotion = is_array($stats['canonical_history_promotion'] ?? null)
            ? $stats['canonical_history_promotion']
            : [];
        $runRowIds = $this->positiveIds($runReadback['row_ids'] ?? []);
        $receiptRowIds = $this->positiveIds($promotion['row_ids'] ?? []);
        $targetDate = substr(trim((string)($promotion['target_date'] ?? '')), 0, 10);
        $period = strtolower(trim((string)($promotion['data_period'] ?? '')));
        $required = $this->stringSet($runReadback['required_traffic_metric_keys'] ?? []);
        if (($runReadback['readback_verified'] ?? null) !== true
            || (int)($runReadback['sync_task_id'] ?? 0) !== $taskId
            || (int)($runReadback['data_source_id'] ?? 0) !== $sourceId
            || (int)($runReadback['system_hotel_id'] ?? 0) !== $systemHotelId
            || strtolower(trim((string)($runReadback['platform'] ?? ''))) !== 'ctrip'
            || substr(trim((string)($runReadback['target_date'] ?? '')), 0, 10) !== $targetDate
            || strtolower(trim((string)($runReadback['data_period'] ?? ''))) !== $period
            || !in_array($period, ['historical_daily', 'realtime_snapshot'], true)
            || $runRowIds === []
            || strtolower(trim((string)($runReadback['p0_status'] ?? ''))) !== 'ready'
            || strtolower(trim((string)($runReadback['field_fact_status'] ?? ''))) !== 'ready'
            || $required !== self::REQUIRED_METRICS
            || $this->stringSet($runReadback['complete_traffic_metric_keys'] ?? []) !== self::REQUIRED_METRICS
            || $this->stringSet($runReadback['missing_traffic_metric_keys'] ?? []) !== []
        ) {
            throw new RuntimeException('canonical_ota_history_run_readback_invalid');
        }
        if ((string)($promotion['version'] ?? '') !== self::PROMOTION_VERSION
            || (int)($promotion['tenant_id'] ?? 0) !== $tenantId
            || (int)($promotion['system_hotel_id'] ?? 0) !== $systemHotelId
            || strtolower(trim((string)($promotion['platform'] ?? ''))) !== 'ctrip'
            || (int)($promotion['data_source_id'] ?? 0) !== $sourceId
            || (int)($promotion['sync_task_id'] ?? 0) !== $taskId
            || !$this->isDate($targetDate)
            || $receiptRowIds === []
            || array_diff($receiptRowIds, $runRowIds) !== []
            || strtolower(trim((string)($promotion['observed_traffic_metric_provenance_status'] ?? ''))) !== 'ready'
            || (int)($promotion['synthetic_normalization_provenance_missing_rows'] ?? -1) !== 0
            || ($promotion['sensitive_values_exposed'] ?? true) !== false
            || trim((string)($promotion['verified_at'] ?? '')) === ''
        ) {
            throw new RuntimeException('canonical_ota_history_promotion_scope_invalid');
        }
        foreach (['collection_anchor_hash', 'verifier_report_hash', 'authoritative_fact_digest',
            'platform_hotel_identity_digest', 'content_digest'] as $hashField) {
            if (!$this->isDigest($promotion[$hashField] ?? null)) {
                throw new RuntimeException('canonical_ota_history_promotion_hash_invalid');
            }
        }
        if (!hash_equals(
            strtolower(trim((string)$promotion['content_digest'])),
            $this->digest($promotion)
        )) {
            throw new RuntimeException('canonical_ota_history_promotion_content_digest_invalid');
        }

        $factDigests = $this->rowDigestMap(
            $promotion['authoritative_row_fact_digests'] ?? null,
            $receiptRowIds
        );
        $identityDigests = $this->rowDigestMap(
            $promotion['authoritative_row_platform_hotel_identity_digests'] ?? null,
            $receiptRowIds
        );
        $operationDigests = $this->rowDigestMap(
            $promotion['operation_row_metric_digests'] ?? null,
            $receiptRowIds
        );
        if ($factDigests === [] || $identityDigests === [] || $operationDigests === []) {
            throw new RuntimeException('canonical_ota_history_promotion_row_digest_map_invalid');
        }

        $metricProfiles = [];
        $aggregateRows = [];
        $nonzeroRows = 0;
        $explicitZeroRows = 0;
        foreach ($receiptRowIds as $rowId) {
            $row = $rowsById[$rowId] ?? null;
            if (!is_array($row)) {
                throw new RuntimeException('canonical_ota_history_promotion_row_missing');
            }
            $proof = $this->canonicalRowProof(
                $row,
                $tenantId,
                $systemHotelId,
                $sourceId,
                $taskId,
                $targetDate,
                $period,
                strtolower(trim((string)$source['ingestion_method']))
            );
            if (!hash_equals((string)$factDigests[$rowId], (string)$proof['fact_digest'])
                || !hash_equals((string)$operationDigests[$rowId], (string)$proof['operation_metric_digest'])
            ) {
                throw new RuntimeException('canonical_ota_history_authoritative_fact_digest_mismatch');
            }
            $metricProfiles[] = (string)$proof['operation_metric_digest'];
            $aggregateRows[] = $proof['aggregate_row'];
            if ((string)$proof['value_status'] === 'nonzero') {
                $nonzeroRows++;
            } else {
                $explicitZeroRows++;
            }
        }
        if ((int)($promotion['nonzero_required_metric_rows'] ?? -1) !== $nonzeroRows
            || (int)($promotion['explicit_zero_confirmed_rows'] ?? -1) !== $explicitZeroRows
            || $nonzeroRows + $explicitZeroRows !== count($receiptRowIds)
        ) {
            throw new RuntimeException('canonical_ota_history_promotion_metric_count_invalid');
        }
        usort($aggregateRows, static fn(array $left, array $right): int =>
            (int)($left['id'] ?? 0) <=> (int)($right['id'] ?? 0)
        );
        $aggregateFactDigest = $this->digest([
            'required_metric_keys' => self::REQUIRED_METRICS,
            'rows' => $aggregateRows,
        ]);
        if (!hash_equals(
            strtolower(trim((string)$promotion['authoritative_fact_digest'])),
            $aggregateFactDigest
        )) {
            throw new RuntimeException('canonical_ota_history_authoritative_aggregate_digest_mismatch');
        }

        $candidateIds = $this->positiveIds($promotion['operation_row_candidate_ids'] ?? []);
        $selectedId = (int)($promotion['selected_operation_row_id'] ?? 0);
        $selection = [
            'version' => trim((string)($promotion['operation_row_selection_version'] ?? '')),
            'status' => strtolower(trim((string)($promotion['operation_row_selection_status'] ?? ''))),
            'policy' => trim((string)($promotion['operation_row_selection_policy'] ?? '')),
            'platform' => 'ctrip',
            'tenant_id' => $tenantId,
            'system_hotel_id' => $systemHotelId,
            'data_source_id' => $sourceId,
            'sync_task_id' => $taskId,
            'target_date' => $targetDate,
            'data_period' => $period,
            'candidate_row_ids' => $candidateIds,
            'selected_row_id' => $selectedId,
            'row_metric_digests' => $operationDigests,
        ];
        $selectionDigest = strtolower(trim((string)($promotion['operation_row_selection_digest'] ?? '')));
        if ($selection['version'] !== self::OPERATION_SELECTION_VERSION
            || $selection['status'] !== 'ready'
            || $selection['policy'] !== self::OPERATION_SELECTION_POLICY
            || $candidateIds !== $receiptRowIds
            || $selectedId !== min($receiptRowIds)
            || count(array_unique($metricProfiles)) !== 1
            || !$this->isDigest($selectionDigest)
            || !hash_equals($selectionDigest, $this->digest($selection))
        ) {
            throw new RuntimeException('canonical_ota_history_operation_selection_invalid');
        }
        return [
            'ready' => true,
            'reason' => '',
            'selected_row_id' => $selectedId,
            'receipt_row_ids' => $receiptRowIds,
            'run_row_ids' => $runRowIds,
        ];
    }

    /** @return array<string,mixed> */
    private function canonicalRowProof(
        array $row,
        int $tenantId,
        int $systemHotelId,
        int $sourceId,
        int $taskId,
        string $targetDate,
        string $period,
        string $ingestionMethod
    ): array {
        if ((int)($row['tenant_id'] ?? 0) !== $tenantId
            || (int)($row['system_hotel_id'] ?? 0) !== $systemHotelId
            || (int)($row['data_source_id'] ?? 0) !== $sourceId
            || (int)($row['sync_task_id'] ?? 0) !== $taskId
            || trim((string)($row['hotel_id'] ?? '')) === ''
            || strtolower(trim((string)($row['source'] ?? ''))) !== 'ctrip'
            || strtolower(trim((string)($row['platform'] ?? ''))) !== 'ctrip'
            || substr(trim((string)($row['data_date'] ?? '')), 0, 10) !== $targetDate
            || strtolower(trim((string)($row['data_period'] ?? ''))) !== $period
            || !in_array(strtolower(trim((string)($row['data_type'] ?? ''))), ['traffic', 'flow', 'conversion'], true)
            || (int)($row['readback_verified'] ?? 0) !== 1
            || strtolower(trim((string)($row['history_status'] ?? ''))) !== 'success'
            || strtolower(trim((string)($row['validation_status'] ?? ''))) !== 'verified'
            || strtolower(trim((string)($row['ingestion_method'] ?? ''))) !== $ingestionMethod
            || !in_array($ingestionMethod, self::TRUSTED_INGESTION_METHODS, true)
            || trim((string)($row['source_trace_id'] ?? '')) === ''
            || !OtaTrafficAttributionService::rowBelongsToAuthoritativeP0Traffic($row, 'ctrip')
        ) {
            throw new RuntimeException('canonical_ota_history_row_scope_invalid');
        }
        $rawJson = trim((string)($row['raw_data'] ?? ''));
        $raw = $this->decodeObject($rawJson);
        $sourceRow = is_array($raw['row'] ?? null) ? $raw['row'] : [];
        if ($rawJson === '' || $sourceRow === []) {
            throw new RuntimeException('canonical_ota_history_raw_source_row_invalid');
        }
        $rowEvidence = $this->desensitizedEvidence($raw);
        if (trim((string)($rowEvidence['source_trace_id'] ?? '')) === ''
            || !hash_equals(
                trim((string)$row['source_trace_id']),
                trim((string)$rowEvidence['source_trace_id'])
            )
            || !$this->isDigest($rowEvidence['source_url_hash'] ?? null)
        ) {
            throw new RuntimeException('canonical_ota_history_structured_evidence_invalid');
        }
        $observed = $this->stringSet($sourceRow['_observed_traffic_metric_keys'] ?? []);
        if ($observed !== self::REQUIRED_METRICS) {
            throw new RuntimeException('canonical_ota_history_metric_provenance_invalid');
        }
        $dateSource = strtolower(trim((string)(
            $raw['date_source'] ?? $sourceRow['date_source'] ?? $sourceRow['dateSource'] ?? ''
        )));
        if ($dateSource === ''
            || str_contains($dateSource, 'default_data_date')
            || str_contains($dateSource, 'command_date')
            || str_contains($dateSource, 'command --date')
        ) {
            throw new RuntimeException('canonical_ota_history_date_provenance_invalid');
        }
        $sourceDate = $sourceRow['date']
            ?? $sourceRow['dataDate']
            ?? $sourceRow['statDate']
            ?? $sourceRow['stat_date']
            ?? $sourceRow['data_date']
            ?? $sourceRow['reportDate']
            ?? $sourceRow['day']
            ?? null;
        if ($this->shanghaiDate($sourceDate) !== $targetDate) {
            throw new RuntimeException('canonical_ota_history_source_date_invalid');
        }
        $captureSource = strtolower(trim((string)(
            $sourceRow['_capture_source']
                ?? $sourceRow['capture_source']
                ?? ($raw['capture_evidence']['capture_source'] ?? '')
        )));
        if (preg_match('/^(?:xhr|fetch|same_origin_api|browser_response|network_response)(?::|$)/D', $captureSource) !== 1) {
            throw new RuntimeException('canonical_ota_history_capture_source_invalid');
        }

        $factsByMetric = [];
        foreach ((array)($raw['field_facts'] ?? []) as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            $metric = (string)($fact['metric_key'] ?? '');
            if (!in_array($metric, self::REQUIRED_METRICS, true)) {
                continue;
            }
            if (isset($factsByMetric[$metric])) {
                throw new RuntimeException('canonical_ota_history_metric_fact_ambiguous');
            }
            $factsByMetric[$metric] = $fact;
        }
        $values = [];
        $hasNonzero = false;
        $rowTraceId = trim((string)$row['source_trace_id']);
        $rowUrlHash = strtolower(trim((string)$rowEvidence['source_url_hash']));
        foreach (self::REQUIRED_METRICS as $metric) {
            $fact = $factsByMetric[$metric] ?? null;
            $sourceKey = is_array($fact) ? trim((string)($fact['source_key'] ?? '')) : '';
            $sourcePath = is_array($fact) ? trim((string)($fact['source_path'] ?? '')) : '';
            $factEvidence = is_array($fact) && is_array($fact['capture_evidence'] ?? null)
                ? $fact['capture_evidence']
                : [];
            $factTrace = trim((string)(
                $factEvidence['source_trace_id'] ?? $factEvidence['_source_trace_id'] ?? ''
            ));
            $factUrlHash = strtolower(trim((string)(
                $factEvidence['source_url_hash']
                    ?? $factEvidence['_source_url_hash']
                    ?? $factEvidence['url_hash']
                    ?? ''
            )));
            $factCaptureSource = strtolower(trim((string)($factEvidence['capture_source'] ?? '')));
            if (!is_array($fact)
                || ($fact['status'] ?? null) !== 'captured'
                || (string)($fact['metric_key'] ?? '') !== $metric
                || $sourceKey === ''
                || !array_key_exists($sourceKey, $sourceRow)
                || !is_numeric($sourceRow[$sourceKey])
                || !is_finite((float)$sourceRow[$sourceKey])
                || preg_match('/[.\[\/]/', $sourcePath) !== 1
                || (string)($fact['storage_field'] ?? '') !== self::STORAGE_FIELDS[$metric]
                || ($fact['stored_value_present'] ?? null) !== true
                || $factTrace === ''
                || !hash_equals($rowTraceId, $factTrace)
                || !$this->isDigest($factUrlHash)
                || !hash_equals($rowUrlHash, $factUrlHash)
                || $factCaptureSource === ''
                || !hash_equals($captureSource, $factCaptureSource)
                || !array_key_exists($metric, $row)
                || !is_numeric($row[$metric])
                || !is_finite((float)$row[$metric])
                || abs((float)$sourceRow[$sourceKey] - (float)$row[$metric]) > 0.000001
            ) {
                throw new RuntimeException('canonical_ota_history_metric_fact_invalid:' . $metric);
            }
            $values[$metric] = sprintf('%.8F', (float)$row[$metric]);
            $hasNonzero = $hasNonzero || abs((float)$row[$metric]) > 0.000001;
        }
        ksort($values, SORT_STRING);
        $valueStatus = $hasNonzero ? 'nonzero' : 'explicit_zero';
        $boundedRow = [
            'id' => (int)$row['id'],
            'source_trace_digest' => hash('sha256', $rowTraceId),
            'raw_data_digest' => hash('sha256', $rawJson),
            'metric_values' => $values,
            'observed_traffic_metric_keys' => self::REQUIRED_METRICS,
            'value_status' => $valueStatus,
        ];
        $aggregateRow = $boundedRow;
        if ($period === 'historical_daily') {
            $captureTime = trim((string)($row['snapshot_time'] ?? ''));
            if ($captureTime === '') {
                $captureTime = trim((string)($raw['captured_at'] ?? $raw['capturedAt'] ?? ''));
            }
            $captureTime = $this->shanghaiTimestamp($captureTime);
            if ($captureTime === '') {
                throw new RuntimeException('canonical_ota_history_capture_time_invalid');
            }
            $aggregateRow['capture_time'] = $captureTime;
        }
        return [
            'fact_digest' => $this->digest([
                'required_metric_keys' => self::REQUIRED_METRICS,
                'rows' => [$boundedRow],
            ]),
            'operation_metric_digest' => $this->digest([
                'required_metric_keys' => self::REQUIRED_METRICS,
                'metric_values' => $values,
                'value_status' => $valueStatus,
            ]),
            'value_status' => $valueStatus,
            'aggregate_row' => $aggregateRow,
        ];
    }

    /** @return array<string,mixed> */
    private function windowResult(
        string $status,
        array $rowIds,
        int $candidateCount,
        int $ignoredCount,
        array $gaps
    ): array {
        return [
            'status' => $status,
            'authoritative_row_ids' => array_values(array_map('intval', $rowIds)),
            'candidate_row_count' => max(0, $candidateCount),
            'authoritative_row_count' => count($rowIds),
            'ignored_unselected_row_count' => max(0, $ignoredCount),
            'data_gaps' => array_values(array_unique(array_filter(array_map('strval', $gaps)))),
        ];
    }

    /** @param array<string,array<string,mixed>> $windows */
    private function blockWindows(array $results, array $windows, array $gaps): array
    {
        foreach ($windows as $key => $_window) {
            if (!isset($results[$key])) {
                $results[$key] = $this->windowResult('blocked', [], 0, 0, $gaps);
            }
        }
        return $results;
    }

    /** @param array<string,int> $counts */
    private function countRange(array $counts, string $start, string $end): int
    {
        $count = 0;
        foreach ($counts as $date => $dateCount) {
            if ($date >= $start && $date <= $end) {
                $count += max(0, (int)$dateCount);
            }
        }
        return $count;
    }

    /** @return array<int,array<string,mixed>> */
    private function indexRows(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $id = is_array($row) ? (int)($row['id'] ?? 0) : 0;
            if ($id > 0) {
                $indexed[$id] = $row;
            }
        }
        return $indexed;
    }

    private function claimsStrictAuthority(array $row): bool
    {
        return (int)($row['readback_verified'] ?? 0) === 1
            && strtolower(trim((string)($row['history_status'] ?? ''))) === 'success'
            && strtolower(trim((string)($row['validation_status'] ?? ''))) === 'verified';
    }

    private function authoritativeTenantId(int $systemHotelId): int
    {
        if ($systemHotelId <= 0) {
            return 0;
        }
        try {
            return max(0, (int)Db::name('hotels')->where('id', $systemHotelId)->value('tenant_id'));
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return array<string,mixed> */
    private function decodeObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException('canonical_ota_history_json_invalid', 0, $error);
        }
        return is_array($decoded) ? $decoded : [];
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
    private function stringSet(mixed $value): array
    {
        $values = array_values(array_unique(array_filter(array_map(
            static fn(mixed $item): string => strtolower(trim((string)$item)),
            is_array($value) ? $value : []
        ), static fn(string $item): bool => $item !== '')));
        sort($values, SORT_STRING);
        return $values;
    }

    /** @param array<int,int> $expectedIds @return array<int,string> */
    private function rowDigestMap(mixed $value, array $expectedIds): array
    {
        if (!is_array($value)) {
            return [];
        }
        $map = [];
        foreach ($value as $rawId => $rawDigest) {
            $id = (int)$rawId;
            $digest = strtolower(trim((string)$rawDigest));
            if ($id <= 0
                || (string)$rawId !== (string)$id
                || !$this->isDigest($digest)
                || isset($map[$id])
            ) {
                return [];
            }
            $map[$id] = $digest;
        }
        ksort($map, SORT_NUMERIC);
        return array_keys($map) === $expectedIds ? $map : [];
    }

    private function digest(array $value): string
    {
        unset($value['content_digest']);
        ksort($value, SORT_STRING);
        return hash('sha256', json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    /** @return array<string,string> */
    private function desensitizedEvidence(array $source): array
    {
        $evidence = [];
        foreach ([
            'source_trace_id' => ['source_trace_id', '_source_trace_id', 'trace_id', '_trace_id'],
            'source_url_hash' => ['source_url_hash', '_source_url_hash', 'url_hash', '_url_hash'],
        ] as $target => $aliases) {
            foreach ($aliases as $alias) {
                if (is_scalar($source[$alias] ?? null) && trim((string)$source[$alias]) !== '') {
                    $evidence[$target] = trim((string)$source[$alias]);
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

    private function quotedFields(array $fields): string
    {
        return implode(',', array_map(
            static fn(string $field): string => '`' . $field . '`',
            $fields
        ));
    }

    private function isDigest(mixed $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', strtolower(trim((string)$value))) === 1;
    }

    private function shanghaiDate(mixed $value): string
    {
        if (!is_scalar($value) || trim((string)$value) === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable(
                (string)$value,
                new \DateTimeZone('Asia/Shanghai')
            ))->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
        } catch (\Throwable) {
            return '';
        }
    }

    private function shanghaiTimestamp(mixed $value): string
    {
        if (!is_scalar($value) || trim((string)$value) === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable(
                (string)$value,
                new \DateTimeZone('Asia/Shanghai')
            ))->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return '';
        }
    }

    private function isDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', trim($value)) !== 1) {
            return false;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));
        return checkdate($month, $day, $year);
    }
}
