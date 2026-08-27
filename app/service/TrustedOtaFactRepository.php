<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use think\facade\Db;

class TrustedOtaFactRepository
{
    private const TABLE = 'online_daily_data';
    private const MAX_ROWS = 10000;
    private const REQUIRED_COLUMNS = [
        'system_hotel_id',
        'data_date',
        'readback_verified',
        'validation_status',
        'validation_flags',
        'ingestion_method',
        'source_trace_id',
        'raw_data',
    ];
    private const METRIC_COLUMNS = ['amount', 'quantity', 'book_order_num'];
    private const TRUSTED_INGESTION_METHODS = ['browser_profile', 'profile_browser'];
    private const TRUSTED_VALIDATION_STATUSES = [
        'normal',
        'available',
        'verified',
        'valid',
        'confirmed',
        'approved',
        'passed',
        'ok',
        'success',
        'complete',
        'completed',
    ];
    private const METRIC_FIELD_FACTS = [
        'amount' => 'order_amount',
        'quantity' => 'room_nights',
        'book_order_num' => 'order_count',
    ];
    private const ACCEPTED_DATA_TYPES = [
        'business',
        'revenue',
        'order',
        'orders',
        'order_list',
        'room_sale',
        'room_sales',
        'sales',
        'booking',
        'bookings',
        'reservation',
        'reservations',
        'transaction',
        'transactions',
    ];
    private const SUMMARY_DATA_TYPE_PRIORITY = [
        'business' => 0,
        'revenue' => 1,
        'room_sale' => 2,
        'room_sales' => 2,
        'sales' => 3,
    ];
    private const BAD_AUXILIARY_STATUSES = [
        'abnormal',
        'invalid',
        'failed',
        'fail',
        'error',
        'unverified',
        'mismatched',
        'mismatch',
        'collection_failed',
        'permission_denied',
        'binding_missing',
        'stale',
        'partial',
        'quarantined',
    ];
    private const BLOCKING_FLAG_FRAGMENTS = [
        'mismatch',
        'wrong_hotel',
        'binding',
        'unverified',
        'provenance',
        'permission_denied',
        'collection_failed',
        'parse_failed',
    ];

    /**
     * Return only readback-verified, self-revenue OTA facts for one system hotel.
     *
     * @return array<string, mixed>
     */
    public function pricingHistory(int $systemHotelId, string $startDate, string $endDate): array
    {
        $results = $this->pricingHistoryBatch($systemHotelId, [
            'single' => ['start_date' => $startDate, 'end_date' => $endDate],
        ]);
        return $results['single'] ?? $this->blockedResult(
            ['pricing_history_query_failed'],
            []
        );
    }

    /**
     * Read one superset and reduce every requested window with exactly the
     * same row validation, dedupe, snapshot and quality rules as pricingHistory().
     *
     * @param array<string, array{start_date:string,end_date:string}> $windows
     * @return array<string, array<string,mixed>>
     */
    public function pricingHistoryBatch(int $systemHotelId, array $windows): array
    {
        $results = [];
        if ($systemHotelId <= 0) {
            foreach ($windows as $key => $_window) {
                $results[(string)$key] = $this->blockedResult(
                    ['pricing_history_system_hotel_id_invalid'],
                    []
                );
            }
            return $results;
        }
        $validWindows = [];
        foreach ($windows as $key => $window) {
            $startDate = trim((string)($window['start_date'] ?? ''));
            $endDate = trim((string)($window['end_date'] ?? ''));
            if (!$this->isDate($startDate) || !$this->isDate($endDate) || $startDate > $endDate) {
                $results[(string)$key] = $this->blockedResult(
                    ['pricing_history_date_range_invalid'],
                    []
                );
                continue;
            }
            $validWindows[(string)$key] = ['start_date' => $startDate, 'end_date' => $endDate];
        }
        if ($validWindows === []) {
            return $results;
        }
        if (!$this->tableExists()) {
            foreach ($validWindows as $key => $_window) {
                $results[$key] = $this->blockedResult(['pricing_history_table_missing'], []);
            }
            return $results;
        }

        $columns = $this->tableColumns();
        $missingRequired = array_values(array_diff(self::REQUIRED_COLUMNS, array_keys($columns)));
        $schemaBlockers = array_map(
            static fn(string $column): string => match ($column) {
                'system_hotel_id' => 'pricing_history_system_hotel_scope_column_missing',
                'readback_verified' => 'pricing_history_readback_verified_column_missing',
                default => 'pricing_history_' . $column . '_column_missing',
            },
            $missingRequired
        );
        if (!isset($columns['data_type']) && !isset($columns['raw_data'])) {
            $schemaBlockers[] = 'pricing_history_data_type_evidence_missing';
        }
        if ($schemaBlockers !== []) {
            foreach ($validWindows as $key => $_window) {
                $results[$key] = $this->blockedResult($schemaBlockers, $columns);
            }
            return $results;
        }

        $fields = array_values(array_intersect($this->candidateFields(), array_keys($columns)));
        try {
            $sourceRowsByWindow = $this->queryPricingHistoryWindows(
                $systemHotelId,
                $validWindows,
                $fields,
                $columns,
                true
            );
        } catch (\Throwable) {
            foreach ($validWindows as $key => $_window) {
                $results[$key] = $this->blockedResult(
                    array_merge($this->schemaDataGaps($columns), ['pricing_history_query_failed']),
                    $columns
                );
            }
            return $results;
        }
        $orderEvidenceStatus = 'complete';
        try {
            $orderEvidenceByWindow = $this->queryPricingHistoryWindows(
                $systemHotelId,
                $validWindows,
                $fields,
                $columns,
                false
            );
        } catch (\Throwable) {
            $orderEvidenceByWindow = array_fill_keys(array_keys($validWindows), []);
            $orderEvidenceStatus = 'query_failed';
        }
        $allSourceRows = [];
        foreach ($sourceRowsByWindow as $windowRows) {
            array_push($allSourceRows, ...$windowRows);
        }
        $platformHotelIds = $this->platformHotelIdsBySource(
            array_values(array_filter($allSourceRows, 'is_array')),
            $systemHotelId
        );
        foreach ($validWindows as $key => $window) {
            $windowRows = $sourceRowsByWindow[$key] ?? [];
            if (count($windowRows) > self::MAX_ROWS) {
                $results[$key] = $this->blockedResult(
                    array_merge($this->schemaDataGaps($columns), ['pricing_history_row_limit_exceeded']),
                    $columns,
                    ['queried_rows' => count($windowRows)]
                );
                continue;
            }
            $windowEvidence = $orderEvidenceByWindow[$key] ?? [];
            $windowEvidenceStatus = $orderEvidenceStatus;
            if (count($windowEvidence) > self::MAX_ROWS) {
                $windowEvidence = [];
                $windowEvidenceStatus = 'row_limit_exceeded';
            }
            $results[$key] = $this->reducePricingHistoryRows(
                $windowRows,
                $windowEvidence,
                $windowEvidenceStatus,
                $columns,
                $platformHotelIds
            );
        }
        return $results;
    }

    /**
     * @param array<string,array{start_date:string,end_date:string}> $windows
     * @param array<int,string> $fields
     * @param array<string,mixed> $columns
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function queryPricingHistoryWindows(
        int $systemHotelId,
        array $windows,
        array $fields,
        array $columns,
        bool $readbackOnly
    ): array
    {
        $quotedFields = implode(',', array_map(
            static fn(string $field): string => '`' . $field . '`',
            $fields
        ));
        $order = '`data_date` ASC' . (isset($columns['id']) ? ', `id` ASC' : '');
        $branches = [];
        $bindings = [];
        foreach ($windows as $windowKey => $window) {
            $where = '`system_hotel_id` = ? AND `data_date` BETWEEN ? AND ?';
            if ($readbackOnly) {
                $where .= ' AND `readback_verified` = 1';
            }
            $branches[] = 'SELECT ? AS `__window_key`, scoped.* FROM ('
                . 'SELECT ' . $quotedFields . ' FROM `' . self::TABLE . '` WHERE ' . $where
                . ' ORDER BY ' . $order . ' LIMIT ' . (self::MAX_ROWS + 1)
                . ') AS scoped';
            array_push(
                $bindings,
                (string)$windowKey,
                $systemHotelId,
                (string)$window['start_date'],
                (string)$window['end_date']
            );
        }
        $rows = Db::query(implode(' UNION ALL ', $branches), $bindings);
        $grouped = array_fill_keys(array_keys($windows), []);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $windowKey = (string)($row['__window_key'] ?? '');
            unset($row['__window_key']);
            if (array_key_exists($windowKey, $grouped)) {
                $grouped[$windowKey][] = $row;
            }
        }
        return $grouped;
    }

    /**
     * @param array<int,mixed> $sourceRows
     * @param array<int,array<string,mixed>> $orderEvidenceRows
     * @param array<string,mixed> $columns
     * @param array<int,string> $platformHotelIds
     * @return array<string,mixed>
     */
    private function reducePricingHistoryRows(
        array $sourceRows,
        array $orderEvidenceRows,
        string $orderEvidenceStatus,
        array $columns,
        array $platformHotelIds
    ): array {
        $dataGaps = $this->schemaDataGaps($columns);
        $trustedRows = [];
        $rejectedReasons = [];
        foreach ($sourceRows as $row) {
            if (!is_array($row)) {
                $rejectedReasons['row_not_array'] = ($rejectedReasons['row_not_array'] ?? 0) + 1;
                continue;
            }
            $reason = $this->rejectionReason($row);
            if ($reason !== '') {
                $rejectedReasons[$reason] = ($rejectedReasons[$reason] ?? 0) + 1;
                continue;
            }
            $trustedRows[] = $row;
        }
        [$trustedRows, $orderDedupQuality] = $this->deduplicateTrustedOrderRows(
            $trustedRows,
            $orderEvidenceRows,
            $orderEvidenceStatus
        );
        [$trustedRows, $supersededRows] = $this->selectCanonicalRows($trustedRows);
        [$trustedRows, $suppressedMixedTypeRows] = $this->preferSummaryFactsPerSourceDate($trustedRows);
        [$trustedRows, $supersededSnapshotRows] = $this->selectLatestSummarySnapshotRows($trustedRows);
        $rows = [];
        foreach ($trustedRows as $row) {
            $raw = $this->decodeRaw($row['raw_data'] ?? null);
            $dataSourceId = isset($row['data_source_id']) && is_numeric($row['data_source_id'])
                ? (int)$row['data_source_id']
                : 0;
            $rows[] = [
                'row_id' => isset($row['id']) && is_numeric($row['id']) ? (int)$row['id'] : null,
                'system_hotel_id' => isset($row['system_hotel_id']) && is_numeric($row['system_hotel_id'])
                    ? (int)$row['system_hotel_id'] : null,
                'data_date' => (string)($row['data_date'] ?? ''),
                'amount' => $this->metricValue($row, 'amount', $dataGaps),
                'quantity' => $this->metricValue($row, 'quantity', $dataGaps),
                'book_order_num' => $this->metricValue($row, 'book_order_num', $dataGaps),
                'source' => $this->platformIdentity($row, $raw),
                'data_type' => $this->normalizedDataType(
                    $this->firstText($row, $raw, ['data_type', 'dataType'])
                ),
                'snapshot_time' => trim((string)($row['snapshot_time'] ?? '')),
                'metric_scope' => 'ota_channel',
                'readback_verified' => in_array(
                    $row['readback_verified'] ?? null,
                    [1, '1', true, 'true'],
                    true
                ),
                'source_trace_id' => trim((string)($row['source_trace_id'] ?? '')),
                'data_source_id' => $dataSourceId > 0 ? $dataSourceId : null,
                'platform_hotel_id' => $platformHotelIds[$dataSourceId] ?? null,
                'collected_at' => $this->collectedAt($row),
                'sync_task_id' => isset($row['sync_task_id']) && is_numeric($row['sync_task_id'])
                    ? (int)$row['sync_task_id'] : null,
                'ingestion_method' => trim((string)($row['ingestion_method'] ?? '')),
            ];
        }
        if ($rows === []) {
            $dataGaps[] = 'pricing_history_trusted_self_revenue_rows_missing';
        }
        $dataGaps = $this->uniqueStrings($dataGaps);
        return [
            'data_status' => $rows === [] ? 'empty' : ($dataGaps === [] ? 'ready' : 'partial'),
            'rows' => $rows,
            'data_gaps' => $dataGaps,
            'source_policy' => $this->sourcePolicy($columns),
            'data_quality' => [
                'queried_rows' => count($sourceRows),
                'trusted_rows' => count($rows),
                'rejected_rows' => array_sum($rejectedReasons),
                'rejected_reasons' => $rejectedReasons,
                'superseded_period_rows' => $supersededRows,
                'suppressed_mixed_type_rows' => $suppressedMixedTypeRows,
                'superseded_snapshot_rows' => $supersededSnapshotRows,
                'order_dedup' => $orderDedupQuality,
            ],
        ];
    }

    /**
     * A daily business/revenue summary and its underlying order rows describe
     * the same OTA sales. Prefer one summary family per source/date so pricing
     * inputs cannot double count both representations. When no summary exists,
     * individual order facts remain usable.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array{0:array<int, array<string, mixed>>,1:int}
     */
    private function preferSummaryFactsPerSourceDate(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $raw = $this->decodeRaw($row['raw_data'] ?? null);
            $key = implode('|', [
                $this->platformIdentity($row, $raw),
                (string)($row['system_hotel_id'] ?? ''),
                (string)($row['data_date'] ?? ''),
            ]);
            $groups[$key][] = $row;
        }

        $selected = [];
        $suppressed = 0;
        foreach ($groups as $items) {
            $bestPriority = null;
            foreach ($items as $item) {
                $raw = $this->decodeRaw($item['raw_data'] ?? null);
                $dataType = $this->normalizedDataType($this->firstText($item, $raw, ['data_type', 'dataType']));
                if (!array_key_exists($dataType, self::SUMMARY_DATA_TYPE_PRIORITY)) {
                    continue;
                }
                $priority = self::SUMMARY_DATA_TYPE_PRIORITY[$dataType];
                $bestPriority = $bestPriority === null ? $priority : min($bestPriority, $priority);
            }

            if ($bestPriority === null) {
                array_push($selected, ...$items);
                continue;
            }

            foreach ($items as $item) {
                $raw = $this->decodeRaw($item['raw_data'] ?? null);
                $dataType = $this->normalizedDataType($this->firstText($item, $raw, ['data_type', 'dataType']));
                if ((self::SUMMARY_DATA_TYPE_PRIORITY[$dataType] ?? null) === $bestPriority) {
                    $selected[] = $item;
                } else {
                    $suppressed++;
                }
            }
        }

        usort($selected, static function (array $left, array $right): int {
            $dateCompare = ((string)($left['data_date'] ?? '')) <=> ((string)($right['data_date'] ?? ''));
            return $dateCompare !== 0 ? $dateCompare : ((int)($left['id'] ?? 0) <=> (int)($right['id'] ?? 0));
        });

        return [$selected, $suppressed];
    }

    /**
     * Keep all event facts, but never combine daily summary dimensions from
     * different sync tasks into one artificial snapshot. Legacy rows without
     * task identity remain unchanged instead of guessing a grouping key.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array{0:array<int, array<string, mixed>>,1:int}
     */
    private function selectLatestSummarySnapshotRows(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $raw = $this->decodeRaw($row['raw_data'] ?? null);
            $key = implode('|', [
                $this->platformIdentity($row, $raw),
                (string)($row['system_hotel_id'] ?? ''),
                (string)($row['data_date'] ?? ''),
            ]);
            $groups[$key][] = $row;
        }

        $selected = [];
        $superseded = 0;
        foreach ($groups as $items) {
            $summaryItems = array_values(array_filter($items, function (array $item): bool {
                $raw = $this->decodeRaw($item['raw_data'] ?? null);
                $dataType = $this->normalizedDataType(
                    $this->firstText($item, $raw, ['data_type', 'dataType'])
                );
                return array_key_exists($dataType, self::SUMMARY_DATA_TYPE_PRIORITY);
            }));
            if ($summaryItems === []) {
                array_push($selected, ...$items);
                continue;
            }

            $taskIds = array_map(
                static fn(array $item): int => max(0, (int)($item['sync_task_id'] ?? 0)),
                $summaryItems
            );
            if (in_array(0, $taskIds, true)) {
                array_push($selected, ...$items);
                continue;
            }

            $latestTaskId = max($taskIds);
            $latestRows = array_values(array_filter(
                $items,
                static fn(array $item): bool => (int)($item['sync_task_id'] ?? 0) === $latestTaskId
            ));
            array_push($selected, ...$latestRows);
            $superseded += max(0, count($items) - count($latestRows));
        }

        usort($selected, static function (array $left, array $right): int {
            $dateCompare = ((string)($left['data_date'] ?? '')) <=> ((string)($right['data_date'] ?? ''));
            return $dateCompare !== 0 ? $dateCompare : ((int)($left['id'] ?? 0) <=> (int)($right['id'] ?? 0));
        });

        return [$selected, $superseded];
    }

    /** @return array<int, string> */
    private function candidateFields(): array
    {
        return [
            'id',
            'system_hotel_id',
            'hotel_id',
            'data_date',
            'amount',
            'quantity',
            'book_order_num',
            'source',
            'platform',
            'data_type',
            'dimension',
            'compare_type',
            'validation_status',
            'validation_flags',
            'status',
            'save_status',
            'data_period',
            'snapshot_time',
            'snapshot_bucket',
            'is_final',
            'update_time',
            'updated_at',
            'create_time',
            'created_at',
            'source_trace_id',
            'data_source_id',
            'sync_task_id',
            'ingestion_method',
            'raw_data',
            'readback_verified',
        ];
    }

    /** @param array<string, bool> $columns */
    private function schemaDataGaps(array $columns): array
    {
        $gaps = [];
        foreach (self::METRIC_COLUMNS as $column) {
            if (!isset($columns[$column])) {
                $gaps[] = 'pricing_history_' . $column . '_column_missing';
            }
        }
        if (!isset($columns['source']) && !isset($columns['platform'])) {
            $gaps[] = 'pricing_history_source_column_missing';
        }
        if (!isset($columns['validation_flags'])) {
            $gaps[] = 'pricing_history_validation_flags_column_missing';
        }
        if (!isset($columns['compare_type'])) {
            $gaps[] = 'pricing_history_compare_type_column_missing';
        }
        if (!isset($columns['dimension'])) {
            $gaps[] = 'pricing_history_dimension_column_missing';
        }
        if (!isset($columns['data_period']) && !isset($columns['is_final'])) {
            $gaps[] = 'pricing_history_period_evidence_columns_missing';
        }

        return $gaps;
    }

    /** @param array<string, mixed> $row */
    private function rejectionReason(array $row): string
    {
        $raw = $this->decodeRaw($row['raw_data'] ?? null);
        $ingestionMethod = strtolower($this->scalarText($row['ingestion_method'] ?? null));
        if (!in_array($ingestionMethod, self::TRUSTED_INGESTION_METHODS, true)) {
            return 'ingestion_method_untrusted';
        }

        $validationStatus = strtolower($this->scalarText($row['validation_status'] ?? null));
        if (!in_array($validationStatus, self::TRUSTED_VALIDATION_STATUSES, true)) {
            return 'validation_status_untrusted';
        }
        foreach (['status', 'save_status'] as $field) {
            $status = strtolower(trim((string)($row[$field] ?? '')));
            if (in_array($status, self::BAD_AUXILIARY_STATUSES, true)) {
                return $field . '_' . $status;
            }
        }

        $sourceTraceId = $this->scalarText($row['source_trace_id'] ?? null);
        if ($sourceTraceId === '') {
            return 'source_trace_id_missing';
        }
        $rawSourceTraceId = $this->scalarText($raw['source_trace_id'] ?? null);
        if ($rawSourceTraceId === '' || !hash_equals($sourceTraceId, $rawSourceTraceId)) {
            return 'raw_source_trace_id_mismatch';
        }
        if (!$this->hasRawHotelBindingEvidence($raw)) {
            return 'raw_hotel_binding_evidence_missing';
        }

        $fieldFactReason = $this->metricFieldFactRejectionReason($row, $raw, $sourceTraceId);
        if ($fieldFactReason !== '') {
            return $fieldFactReason;
        }
        $captureClassification =
            (new OtaStructuredCaptureEvidenceService())->classifyRow($row);
        if (($captureClassification['allowed'] ?? false) !== true) {
            return ($captureClassification['status'] ?? '')
                    === OtaStructuredCaptureEvidenceService::STATUS_DOM
                ? 'structured_capture_evidence_dom_only'
                : 'structured_capture_evidence_unverified';
        }

        $flags = strtolower($this->flattenFlags($row['validation_flags'] ?? ''));
        foreach (self::BLOCKING_FLAG_FRAGMENTS as $fragment) {
            if ($flags !== '' && str_contains($flags, $fragment)) {
                return 'blocking_validation_flag';
            }
        }

        $dataType = $this->normalizedDataType($this->firstText($row, $raw, ['data_type', 'dataType']));
        if ($dataType === '') {
            return 'data_type_missing';
        }
        if (!in_array($dataType, self::ACCEPTED_DATA_TYPES, true)) {
            return 'non_pricing_data_type';
        }

        $rawDataType = $this->normalizedDataType($this->firstText([], $raw, ['data_type', 'dataType']));
        if ($rawDataType !== '' && !in_array($rawDataType, self::ACCEPTED_DATA_TYPES, true)) {
            return 'raw_non_pricing_data_type';
        }

        foreach ([
            $this->firstText($row, [], ['compare_type', 'compareType']),
            $this->firstText([], $raw, ['compare_type', 'compareType']),
        ] as $compareType) {
            $compareType = strtolower(trim($compareType));
            if ($compareType !== '' && !in_array($compareType, ['self', 'own', 'ours', 'target_hotel'], true)) {
                return 'non_self_compare_type';
            }
        }

        $scopeText = strtolower(implode(' ', [
            $this->firstText($row, [], ['dimension', 'dimName', '_dimName']),
            $this->firstText([], $raw, ['dimension', 'dimName', '_dimName']),
            $this->platformIdentity($row, []),
            $this->firstText($row, [], ['source']),
            $this->platformIdentity([], $raw),
            $this->firstText([], $raw, ['source']),
        ]));
        foreach (['competitor', 'competition_circle', 'peer_hotel', '竞品', '商圈酒店', '同业酒店'] as $fragment) {
            if ($scopeText !== '' && str_contains($scopeText, $fragment)) {
                return 'non_self_dimension';
            }
        }

        return '';
    }

    /** @param array<string, mixed> $raw */
    private function hasRawHotelBindingEvidence(array $raw): bool
    {
        $bindingStatus = strtolower($this->scalarText($raw['platform_hotel_binding_status'] ?? null));
        $bindingProof = strtolower($this->scalarText($raw['platform_hotel_binding_proof'] ?? null));
        if ($bindingStatus !== '') {
            return $bindingStatus === 'matched'
                && $bindingProof !== ''
                && !in_array($bindingProof, ['missing', 'unverified'], true);
        }

        $identifierPresent = in_array(
            $raw['platform_hotel_identifier_present'] ?? null,
            [true, 1, '1', 'true'],
            true
        );
        $identifierSource = $this->scalarText($raw['platform_hotel_identifier_source'] ?? null);
        $identifierProof = strtolower($this->scalarText($raw['platform_hotel_identifier_proof'] ?? null));

        return $identifierPresent
            && $identifierSource !== ''
            && $identifierProof !== ''
            && !in_array($identifierProof, ['missing', 'unverified'], true);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     */
    private function metricFieldFactRejectionReason(array $row, array $raw, string $sourceTraceId): string
    {
        $facts = is_array($raw['field_facts'] ?? null) ? $raw['field_facts'] : [];
        foreach (self::METRIC_FIELD_FACTS as $column => $metricKey) {
            if (!$this->hasNonNullValue($row, $column)) {
                continue;
            }

            $matchingFacts = array_values(array_filter(
                $facts,
                fn(mixed $fact): bool => is_array($fact)
                    && $this->normalizedDataType($this->scalarText($fact['metric_key'] ?? null)) === $metricKey
            ));
            if ($matchingFacts === []) {
                return 'field_fact_missing_' . $metricKey;
            }

            $hasMappedFact = false;
            $hasCapturedFact = false;
            foreach ($matchingFacts as $fact) {
                $normalizedField = $this->normalizedDataType($this->scalarText($fact['normalized_field'] ?? null));
                $storageField = strtolower($this->scalarText($fact['storage_field'] ?? null));
                if (str_starts_with($storageField, self::TABLE . '.')) {
                    $storageField = substr($storageField, strlen(self::TABLE) + 1);
                }
                if ($normalizedField !== $column || $storageField !== $column) {
                    continue;
                }
                $hasMappedFact = true;

                $captured = strtolower($this->scalarText($fact['status'] ?? null)) === 'captured'
                    && ($fact['stored_value_present'] ?? null) === true;
                if (!$captured) {
                    continue;
                }
                $hasCapturedFact = true;

                $captureEvidence = is_array($fact['capture_evidence'] ?? null)
                    ? $fact['capture_evidence']
                    : [];
                $factTraceId = $this->scalarText(
                    $captureEvidence['source_trace_id'] ?? $captureEvidence['_source_trace_id'] ?? null
                );
                if ($factTraceId !== '' && hash_equals($sourceTraceId, $factTraceId)) {
                    continue 2;
                }
            }

            if (!$hasMappedFact) {
                return 'field_fact_storage_mismatch_' . $metricKey;
            }
            if (!$hasCapturedFact) {
                return 'field_fact_not_captured_' . $metricKey;
            }
            return 'field_fact_trace_mismatch_' . $metricKey;
        }

        return '';
    }

    /** @param array<string, mixed> $row */
    private function hasNonNullValue(array $row, string $field): bool
    {
        if (!array_key_exists($field, $row) || $row[$field] === null) {
            return false;
        }
        return !is_string($row[$field]) || trim($row[$field]) !== '';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{0:array<int, array<string, mixed>>,1:int}
     */
    private function selectCanonicalRows(array $rows): array
    {
        $selected = [];
        $grouped = [];
        foreach ($rows as $row) {
            $period = strtolower(trim((string)($row['data_period'] ?? '')));
            if (!in_array($period, ['historical_daily', 'realtime_snapshot'], true)) {
                $selected[] = $row;
                continue;
            }

            $raw = $this->decodeRaw($row['raw_data'] ?? null);
            $dataType = $this->normalizedDataType($this->firstText($row, $raw, ['data_type', 'dataType']));
            $eventIdentity = $this->stableEventIdentity($row, $raw, $dataType);
            if ($eventIdentity !== '') {
                $selected[] = $row;
                continue;
            }

            $key = implode('|', [
                $this->platformIdentity($row, $raw),
                trim((string)($row['hotel_id'] ?? '')),
                (string)($row['data_date'] ?? ''),
                $dataType,
                strtolower($this->firstText($row, $raw, ['dimension', 'dimName', '_dimName'])),
                strtolower($this->firstText($row, $raw, ['compare_type', 'compareType'])),
                $this->businessIdentity($raw),
            ]);
            $grouped[$key][] = $row;
        }

        $superseded = 0;
        foreach ($grouped as $items) {
            $finalItems = array_values(array_filter(
                $items,
                fn(array $row): bool => $this->isFinalPeriodRow($row)
            ));
            $candidates = $finalItems !== [] ? $finalItems : $items;
            $winner = $candidates[0];
            foreach (array_slice($candidates, 1) as $candidate) {
                if ($this->periodRowOrder($candidate) >= $this->periodRowOrder($winner)) {
                    $winner = $candidate;
                }
            }
            $selected[] = $winner;
            $superseded += max(0, count($items) - 1);
        }

        usort($selected, static function (array $left, array $right): int {
            $dateCompare = ((string)($left['data_date'] ?? '')) <=> ((string)($right['data_date'] ?? ''));
            return $dateCompare !== 0 ? $dateCompare : ((int)($left['id'] ?? 0) <=> (int)($right['id'] ?? 0));
        });

        return [$selected, $superseded];
    }

    /**
     * Deduplicate only trusted order events with a complete four-part identity.
     * Untrusted evidence never enters the fact rows, but newer versions remain
     * visible in quality metadata for review.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param null|array<int, array<string, mixed>> $evidenceRows
     * @return array{0:array<int, array<string, mixed>>,1:array<string,mixed>}
     */
    private function deduplicateTrustedOrderRows(
        array $rows,
        ?array $evidenceRows = null,
        string $evidenceStatus = 'complete'
    ): array {
        $selected = [];
        $trustedKeys = [];
        foreach (array_values($rows) as $row) {
            $trustedKeys[$this->orderEvidenceRowKey($row)] = true;
            $raw = $this->decodeRaw($row['raw_data'] ?? null);
            if (!$this->isSelfOrderEvidenceRow($row, $raw)
                || $this->verifiedOrderGrain($row, $raw) === ''
            ) {
                $selected[] = $row;
            }
        }

        $evidenceByKey = [];
        foreach (array_merge($evidenceRows ?? $rows, $rows) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $evidenceByKey[$this->orderEvidenceRowKey($row)] = $row;
        }

        $groups = [];
        $candidateRows = 0;
        $coveredRows = 0;
        foreach ($evidenceByKey as $rowKey => $row) {
            $raw = $this->decodeRaw($row['raw_data'] ?? null);
            if (!$this->isSelfOrderEvidenceRow($row, $raw)) {
                continue;
            }
            $candidateRows++;
            $grain = $this->verifiedOrderGrain($row, $raw);
            $trusted = isset($trustedKeys[$rowKey]);
            if ($grain === '') {
                continue;
            }
            if ($trusted) {
                $coveredRows++;
            }
            $groups[$grain][] = [
                'row' => $row,
                'trusted' => $trusted,
            ];
        }

        $verifiedGrains = 0;
        $suppressedRows = 0;
        $suppressedUntrustedRows = 0;
        $newerUntrustedRows = 0;
        foreach ($groups as $items) {
            $trustedItems = array_values(array_filter(
                $items,
                static fn(array $item): bool => $item['trusted'] === true
            ));
            if ($trustedItems === []) {
                continue;
            }
            $verifiedGrains++;
            usort(
                $trustedItems,
                fn(array $left, array $right): int =>
                    $this->periodRowOrder($left['row'])
                    <=> $this->periodRowOrder($right['row'])
            );
            $winner = $trustedItems[count($trustedItems) - 1];
            $winnerKey = $this->orderEvidenceRowKey($winner['row']);
            $winnerOrder = $this->periodRowOrder($winner['row']);
            $selected[] = $winner['row'];
            $suppressedRows += max(0, count($items) - 1);
            foreach ($items as $item) {
                if ($item['trusted'] === true
                    && $this->orderEvidenceRowKey($item['row']) === $winnerKey
                ) {
                    continue;
                }
                if ($item['trusted'] === false) {
                    $suppressedUntrustedRows++;
                    if ($this->periodRowOrder($item['row']) > $winnerOrder) {
                        $newerUntrustedRows++;
                    }
                }
            }
        }

        usort($selected, static function (array $left, array $right): int {
            $dateCompare = ((string)($left['data_date'] ?? ''))
                <=> ((string)($right['data_date'] ?? ''));
            return $dateCompare !== 0
                ? $dateCompare
                : ((int)($left['id'] ?? 0) <=> (int)($right['id'] ?? 0));
        });
        $coveragePercent = $candidateRows > 0
            ? round(($coveredRows / $candidateRows) * 100, 2)
            : null;

        return [array_values($selected), [
            'policy' =>
                'latest_trusted_per_system_hotel_platform_data_date_order_id_hash',
            'coverage_unit' => 'scoped_order_evidence_rows',
            'evidence_status' => $evidenceStatus,
            'order_identity_candidate_rows' => $candidateRows,
            'order_identity_covered_rows' => $coveredRows,
            'order_identity_unverifiable_rows' =>
                max(0, $candidateRows - $coveredRows),
            'order_identity_coverage_percent' => $coveragePercent,
            'distinct_verified_order_grains' => $verifiedGrains,
            'suppressed_duplicate_order_rows' => $suppressedRows,
            'suppressed_untrusted_duplicate_order_rows' =>
                $suppressedUntrustedRows,
            'newer_untrusted_duplicate_order_rows' => $newerUntrustedRows,
        ]];
    }

    /** @param array<string,mixed> $row */
    private function orderEvidenceRowKey(array $row): string
    {
        if (isset($row['id']) && trim((string)$row['id']) !== '') {
            return 'id:' . (string)$row['id'];
        }
        return 'row:' . hash('sha256', (string)json_encode(
            $row,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $raw
     */
    private function isSelfOrderEvidenceRow(array $row, array $raw): bool
    {
        $dataType = $this->normalizedDataType(
            $this->firstText($row, $raw, ['data_type', 'dataType'])
        );
        if (!in_array($dataType, ['order', 'orders', 'order_list'], true)) {
            return false;
        }
        foreach ([
            $this->firstText($row, [], ['compare_type', 'compareType']),
            $this->firstText([], $raw, ['compare_type', 'compareType']),
        ] as $compareType) {
            $compareType = strtolower(trim($compareType));
            if ($compareType !== ''
                && !in_array(
                    $compareType,
                    ['self', 'own', 'ours', 'target_hotel'],
                    true
                )
            ) {
                return false;
            }
        }
        $scopeText = strtolower(implode(' ', [
            $this->firstText($row, [], ['dimension', 'dimName', '_dimName']),
            $this->firstText([], $raw, ['dimension', 'dimName', '_dimName']),
        ]));
        foreach (['competitor', 'competition_circle', 'peer_hotel'] as $fragment) {
            if ($scopeText !== '' && str_contains($scopeText, $fragment)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $raw
     */
    private function verifiedOrderGrain(array $row, array $raw): string
    {
        $systemHotelId = (int)($row['system_hotel_id'] ?? 0);
        $platform = $this->platformIdentity($row, $raw);
        $businessDate = trim((string)($row['data_date'] ?? ''));
        $orderIdentityHash = $this->verifiedOrderIdentityHash($row, $raw);
        if ($systemHotelId <= 0
            || $platform === ''
            || !$this->isExactBusinessDate($businessDate)
            || $orderIdentityHash === ''
        ) {
            return '';
        }
        return implode('|', [
            (string)$systemHotelId,
            $platform,
            $businessDate,
            $orderIdentityHash,
        ]);
    }

    /** @param array<string, mixed> $row */
    private function isFinalPeriodRow(array $row): bool
    {
        return in_array($row['is_final'] ?? null, [1, '1', true, 'true'], true)
            || strtolower(trim((string)($row['data_period'] ?? ''))) === 'historical_daily';
    }

    /** @param array<string, mixed> $row */
    private function periodRowOrder(array $row): int
    {
        foreach (['snapshot_time', 'update_time', 'updated_at', 'create_time', 'created_at'] as $field) {
            $value = trim((string)($row[$field] ?? ''));
            if ($value !== '') {
                $timestamp = strtotime($value);
                if ($timestamp !== false) {
                    return $timestamp * 1000000 + max(0, (int)($row['id'] ?? 0));
                }
            }
        }

        return max(0, (int)($row['id'] ?? 0));
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     */
    private function stableEventIdentity(array $row, array $raw, string $dataType): string
    {
        if (!in_array($dataType, ['order', 'orders', 'order_list'], true)) {
            return '';
        }
        $verifiedIdentity = $this->verifiedOrderIdentityHash($row, $raw);
        if ($verifiedIdentity !== '') {
            return $verifiedIdentity;
        }
        return $this->firstText($row, $raw, [
            'order_id_hash',
            'orderIdHash',
            'order_no_hash',
            'booking_id_hash',
            'order_id',
            'orderId',
            'order_no',
            'orderNo',
            'order_sn',
            'orderSn',
            'booking_id',
            'bookingId',
        ]);
    }

    /**
     * Return a normalized hash without exposing the original order identifier.
     * A claimed pre-hash must be a full SHA-256 value; raw identifiers are
     * hashed in memory with the established OTA order namespace.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     */
    private function verifiedOrderIdentityHash(array $row, array $raw): string
    {
        $sources = [$row, $raw];
        foreach (['row', 'metrics', 'detail', 'fields'] as $nestedKey) {
            if (is_array($raw[$nestedKey] ?? null)) {
                $sources[] = $raw[$nestedKey];
            }
        }
        foreach (['order_id_hash', 'orderIdHash', 'order_no_hash', 'booking_id_hash'] as $key) {
            foreach ($sources as $source) {
                $preHashed = strtolower(trim((string)($source[$key] ?? '')));
                if (preg_match('/^[a-f0-9]{64}$/', $preHashed) === 1) {
                    return $preHashed;
                }
            }
        }

        foreach (['order_id', 'orderId', 'order_no', 'orderNo', 'order_sn', 'orderSn', 'booking_id', 'bookingId'] as $key) {
            foreach ($sources as $source) {
                $rawIdentity = trim((string)($source[$key] ?? ''));
                if ($rawIdentity !== '') {
                    return hash('sha256', 'ota_order|' . $rawIdentity);
                }
            }
        }

        return '';
    }

    private function isExactBusinessDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof DateTimeImmutable
            && $date->format('Y-m-d') === $value;
    }

    /** @param array<string, mixed> $raw */
    private function businessIdentity(array $raw): string
    {
        return $this->firstText([], $raw, [
            'business_id',
            'businessId',
            'entity_id',
            'entityId',
            'item_id',
            'itemId',
            'room_type_id',
            'roomTypeId',
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $dataGaps
     */
    private function metricValue(array $row, string $field, array &$dataGaps): ?float
    {
        if (!array_key_exists($field, $row) || $row[$field] === null || trim((string)$row[$field]) === '') {
            $dataGaps[] = 'pricing_history_' . $field . '_missing';
            return null;
        }
        if (!is_numeric($row[$field])) {
            $dataGaps[] = 'pricing_history_' . $field . '_invalid';
            return null;
        }

        $value = (float)$row[$field];
        if (!is_finite($value) || $value < 0) {
            $dataGaps[] = 'pricing_history_' . $field . '_invalid';
            return null;
        }

        return $value;
    }

    /** @return array<string, mixed> */
    /** @param array<string, mixed> $row */
    private function collectedAt(array $row): ?string
    {
        foreach (['snapshot_time', 'update_time', 'updated_at', 'create_time', 'created_at'] as $field) {
            $value = trim((string)($row[$field] ?? ''));
            if ($value !== '' && strtotime($value) !== false) {
                return date('Y-m-d H:i:s', (int)strtotime($value));
            }
        }

        return null;
    }

    /**
     * Resolve the platform hotel identifier only from the exact enabled Ctrip
     * browser-profile source that produced each fact. Profile identifiers are
     * deliberately not accepted as hotel identity.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, string>
     */
    private function platformHotelIdsBySource(array $rows, int $systemHotelId): array
    {
        $sourceIds = array_values(array_unique(array_filter(array_map(
            static fn(array $row): int => (int)($row['data_source_id'] ?? 0),
            $rows
        ), static fn(int $id): bool => $id > 0)));
        sort($sourceIds, SORT_NUMERIC);
        if ($sourceIds === []) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($sourceIds), '?'));
            $sources = Db::query(
                'SELECT id, system_hotel_id, platform, ingestion_method, enabled, config_json '
                . 'FROM platform_data_sources WHERE id IN (' . $placeholders . ') '
                . 'AND system_hotel_id = ? AND platform = ? AND ingestion_method = ? AND enabled = ?',
                [...$sourceIds, $systemHotelId, 'ctrip', 'browser_profile', 1]
            );
        } catch (\Throwable) {
            return [];
        }

        $resolved = [];
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            $sourceId = (int)($source['id'] ?? 0);
            $config = $this->decodeRaw($source['config_json'] ?? null);
            foreach ([
                'platform_hotel_id', 'platformHotelId', 'hotel_id', 'hotelId',
                'ota_hotel_id', 'otaHotelId', 'ctrip_hotel_id', 'ctripHotelId',
                'master_hotel_id', 'masterHotelId', 'external_hotel_id',
            ] as $key) {
                $identifier = $this->scalarText($config[$key] ?? null);
                if ($identifier !== '') {
                    $resolved[$sourceId] = $identifier;
                    break;
                }
            }
        }

        return $resolved;
    }

    private function decodeRaw(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @param array<int, string> $keys
     */
    private function firstText(array $row, array $raw, array $keys): string
    {
        $sources = [$row, $raw];
        foreach (['row', 'metrics', 'detail'] as $nestedKey) {
            if (is_array($raw[$nestedKey] ?? null)) {
                $sources[] = $raw[$nestedKey];
            }
        }
        foreach ($sources as $source) {
            foreach ($keys as $key) {
                $value = trim((string)($source[$key] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    /**
     * Resolve the OTA business platform without allowing a storage-source
     * alias (for example source=ctrip for Qunar rows) to override it. Source
     * is a legacy fallback only when no platform field exists in the row or
     * its persisted raw evidence.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     */
    private function platformIdentity(array $row, array $raw): string
    {
        $sources = [$row, $raw];
        foreach (['row', 'metrics', 'detail'] as $nestedKey) {
            if (is_array($raw[$nestedKey] ?? null)) {
                $sources[] = $raw[$nestedKey];
            }
        }
        foreach ($sources as $source) {
            if (array_key_exists('platform', $source)) {
                return $this->normalizedSource($this->scalarText($source['platform']));
            }
        }

        return $this->normalizedSource($this->firstText($row, $raw, ['source']));
    }

    private function flattenFlags(mixed $flags): string
    {
        if (is_array($flags)) {
            return (string)json_encode($flags, JSON_UNESCAPED_UNICODE);
        }

        return trim((string)$flags);
    }

    private function scalarText(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }

    private function normalizedDataType(string $value): string
    {
        return strtolower(trim(str_replace(['-', ' '], '_', $value)));
    }

    private function normalizedSource(string $value): string
    {
        $value = strtolower(trim($value));
        if (str_contains($value, 'ctrip') || str_contains($value, 'trip.com')) {
            return 'ctrip';
        }
        if (str_contains($value, 'meituan') || str_contains($value, 'dianping')) {
            return 'meituan';
        }
        if (str_contains($value, 'qunar')) {
            return 'qunar';
        }

        return $value;
    }

    private function isDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
    }

    private function tableExists(): bool
    {
        try {
            if ($this->driverType() === 'sqlite') {
                return Db::query(
                    "SELECT name FROM sqlite_master WHERE type = 'table' AND name = '" . self::TABLE . "'"
                ) !== [];
            }
            return Db::query("SHOW TABLES LIKE '" . self::TABLE . "'") !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, bool> */
    private function tableColumns(): array
    {
        try {
            $rows = $this->driverType() === 'sqlite'
                ? Db::query('PRAGMA table_info(`' . self::TABLE . '`)')
                : Db::query('SHOW COLUMNS FROM `' . self::TABLE . '`');
        } catch (\Throwable) {
            return [];
        }

        $columns = [];
        foreach ($rows as $row) {
            $field = trim((string)($row['Field'] ?? $row['name'] ?? ''));
            if ($field !== '') {
                $columns[$field] = true;
            }
        }

        return $columns;
    }

    private function driverType(): string
    {
        return strtolower((string)Db::connect()->getConfig('type'));
    }

    /**
     * @param array<int, string> $gaps
     * @param array<string, bool> $columns
     * @param array<string, mixed> $quality
     * @return array<string, mixed>
     */
    private function blockedResult(array $gaps, array $columns, array $quality = []): array
    {
        return [
            'data_status' => 'blocked',
            'rows' => [],
            'data_gaps' => $this->uniqueStrings($gaps),
            'source_policy' => $this->sourcePolicy($columns),
            'data_quality' => array_merge([
                'queried_rows' => 0,
                'trusted_rows' => 0,
                'rejected_rows' => 0,
                'rejected_reasons' => [],
                'superseded_period_rows' => 0,
            ], $quality),
        ];
    }

    /** @param array<string, bool> $columns */
    private function sourcePolicy(array $columns): array
    {
        return [
            'table' => self::TABLE,
            'hotel_scope' => 'system_hotel_id_strict_exact_only',
            'readback_policy' => 'readback_verified_required_equals_1',
            'ingestion_policy' => 'browser_profile_or_profile_browser_only',
            'semantic_policy' => 'self_revenue_and_order_types_only',
            'validation_policy' => 'explicit_trusted_status_allowlist_and_no_blocking_flags',
            'trace_policy' => 'row_raw_and_captured_field_fact_trace_must_match',
            'binding_policy' => 'raw_hotel_binding_evidence_required',
            'platform_hotel_identity_policy' => 'platform_data_source_config_exact_required',
            'metric_fact_policy' => 'each_non_null_pricing_metric_requires_captured_field_fact',
            'period_policy' => 'historical_final_else_latest_realtime_per_business_grain',
            'metric_scope' => 'ota_channel',
            'missing_metric_policy' => 'preserve_null_never_default_zero',
            'available_policy_columns' => array_values(array_intersect([
                'source',
                'platform',
                'data_type',
                'compare_type',
                'dimension',
                'validation_status',
                'validation_flags',
                'ingestion_method',
                'source_trace_id',
                'raw_data',
                'data_period',
                'is_final',
            ], array_keys($columns))),
        ];
    }

    /** @param array<int, string> $values */
    private function uniqueStrings(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $value = trim($value);
            if ($value !== '' && !in_array($value, $result, true)) {
                $result[] = $value;
            }
        }

        return $result;
    }
}
