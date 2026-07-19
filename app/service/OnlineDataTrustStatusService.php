<?php
declare(strict_types=1);

namespace app\service;

final class OnlineDataTrustStatusService
{
    private const TRUTH_STATUS_LABELS = [
        'verified' => '已验证',
        'partial' => '部分数据',
        'unverified' => '未验证',
        'collection_failed' => '采集失败',
    ];

    private const UNVERIFIED_INGESTION_METHODS = [
        '',
        'legacy',
        'manual',
        'manual_import',
        'manual_override',
        'user_provided',
        'user_provided_unverified',
        'import_csv',
        'import_json',
    ];

    public const FAILED_VALIDATION_STATUSES = [
        'abnormal', 'invalid', 'failed', 'fail', 'error',
        'collection_failed', 'capture_failed', 'permission_denied',
        'binding_missing', 'mismatched', 'mismatch', 'login_required',
    ];

    public const UNVERIFIED_VALIDATION_STATUSES = ['unverified', 'stale'];

    public const PARTIAL_VALIDATION_STATUSES = ['warning', 'partial', 'partial_success'];

    public const FAILED_ROW_STATUSES = [
        'failed', 'fail', 'error', 'collection_failed', 'capture_failed',
        'permission_denied', 'binding_missing', 'blocked', 'rejected', 'login_required',
    ];

    public const UNVERIFIED_ROW_STATUSES = ['unverified', 'stale'];

    public static function classifyValidationStatus(mixed $status): string
    {
        $normalized = strtolower(trim((string)$status));
        if (in_array($normalized, self::FAILED_VALIDATION_STATUSES, true)) {
            return 'failed';
        }
        if (in_array($normalized, self::UNVERIFIED_VALIDATION_STATUSES, true)) {
            return 'unverified';
        }
        if (in_array($normalized, self::PARTIAL_VALIDATION_STATUSES, true)) {
            return 'partial';
        }
        return 'usable';
    }

    public static function classifyRowStatus(mixed $status): string
    {
        $normalized = strtolower(trim((string)$status));
        if (in_array($normalized, self::FAILED_ROW_STATUSES, true)) {
            return 'failed';
        }
        if (in_array($normalized, self::UNVERIFIED_ROW_STATUSES, true)) {
            return 'unverified';
        }
        if (in_array($normalized, self::PARTIAL_VALIDATION_STATUSES, true)) {
            return 'partial';
        }
        return 'usable';
    }

    /** @return array<int, string> */
    public static function blockingValidationStatuses(): array
    {
        return array_values(array_unique(array_merge(
            self::FAILED_VALIDATION_STATUSES,
            self::UNVERIFIED_VALIDATION_STATUSES
        )));
    }

    /** @return array<int, string> */
    public static function blockingRowStatuses(): array
    {
        return array_values(array_unique(array_merge(
            self::FAILED_ROW_STATUSES,
            self::UNVERIFIED_ROW_STATUSES
        )));
    }

    /** @param array<int, string> $statuses */
    public static function quotedSqlList(array $statuses): string
    {
        return "'" . implode("','", array_map(
            static fn(string $status): string => str_replace("'", "''", strtolower(trim($status))),
            $statuses
        )) . "'";
    }

    /** @return array{code: string, label: string} */
    public static function storageStatus(array $row): array
    {
        $rowClass = self::classifyRowStatus($row['status'] ?? $row['save_status'] ?? '');
        $validationClass = self::classifyValidationStatus($row['validation_status'] ?? '');
        if ($rowClass === 'failed' || $validationClass === 'failed') {
            return ['code' => 'failed', 'label' => '入库校验失败'];
        }
        if ($rowClass === 'unverified' || $validationClass === 'unverified') {
            return ['code' => 'unverified', 'label' => '未回读验证'];
        }
        if ($rowClass === 'partial' || $validationClass === 'partial') {
            return ['code' => 'partial', 'label' => '部分入库'];
        }
        if ((int)($row['readback_verified'] ?? 0) === 1) {
            return ['code' => 'success', 'label' => '已入库并回读'];
        }
        return ['code' => 'unverified', 'label' => '未回读验证'];
    }

    /**
     * Build one safe, display-ready truth envelope for a persisted OTA fact.
     * A database row alone is never promoted to verified source truth.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $fieldFactStatus
     * @return array<string, mixed>
     */
    public static function truthEnvelope(array $row, array $fieldFactStatus = []): array
    {
        $raw = self::decodeRawData($row['raw_data'] ?? null);
        $storage = self::storageStatus($row);
        $rowClass = self::classifyRowStatus($row['status'] ?? $row['save_status'] ?? '');
        $validationClass = self::classifyValidationStatus($row['validation_status'] ?? '');
        $platform = self::normalizePlatform($row['platform'] ?? $row['source'] ?? '');
        $sourceMethod = self::firstText([
            $row['ingestion_method'] ?? null,
            $row['source_method'] ?? null,
            $raw['ingestion_method'] ?? null,
            $raw['_ingestion_method'] ?? null,
            $raw['source_method'] ?? null,
        ]);
        $collectedAt = self::resolveCollectedAt($row, $raw);
        $persistedAt = self::latestDateTime([
            $row['update_time'] ?? null,
            $row['create_time'] ?? null,
        ]);
        $stored = filter_var($row['id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]) !== false;
        $readbackVerified = (int)($row['readback_verified'] ?? 0) === 1;
        $systemHotelId = max(0, (int)($row['system_hotel_id'] ?? 0));
        $hotelName = self::firstText([
            $row['system_hotel_name'] ?? null,
            $row['hotel_name'] ?? null,
        ]);
        $dataDate = self::firstText([$row['data_date'] ?? null]);
        $sourceTraceId = self::firstText([
            $row['source_trace_id'] ?? null,
            $raw['source_trace_id'] ?? null,
        ]);
        $collectedAtPrecise = self::collectedAtHasTime($collectedAt);

        $fieldStatus = strtolower(trim((string)($fieldFactStatus['status'] ?? 'not_loaded')));
        $capturedCount = max(0, (int)($fieldFactStatus['captured_count'] ?? 0));
        $missingCount = max(0, (int)($fieldFactStatus['missing_count'] ?? 0));
        $desensitizedEvidenceCount = max(0, (int)($fieldFactStatus['desensitized_capture_evidence_count'] ?? 0));
        $fieldFactsVerified = $fieldStatus === 'ready'
            && $capturedCount > 0
            && $missingCount === 0
            && $desensitizedEvidenceCount >= $capturedCount;
        $hasRawFailure = self::rawContainsFailure($raw);
        $collectionFailed = $rowClass === 'failed'
            || $validationClass === 'failed'
            || $storage['code'] === 'failed'
            || $hasRawFailure;
        $explicitlyUnverified = $rowClass === 'unverified' || $validationClass === 'unverified';
        $explicitlyPartial = $rowClass === 'partial' || $validationClass === 'partial' || $storage['code'] === 'partial';

        $gapCodes = [];
        $gapTexts = [];
        self::appendTruthGap($gapCodes, $gapTexts, $systemHotelId <= 0, 'system_hotel_missing', '门店绑定缺失');
        self::appendTruthGap($gapCodes, $gapTexts, $platform === '', 'platform_missing', '平台来源缺失');
        self::appendTruthGap($gapCodes, $gapTexts, $dataDate === '', 'data_date_missing', '业务日期缺失');
        self::appendTruthGap($gapCodes, $gapTexts, $sourceMethod === '', 'source_method_missing', '来源方式未记录');
        self::appendTruthGap($gapCodes, $gapTexts, $collectedAt === '', 'collected_at_missing', '采集时间未记录');
        self::appendTruthGap($gapCodes, $gapTexts, $collectedAt !== '' && !$collectedAtPrecise, 'collected_at_imprecise', '采集时间缺少时分秒');
        self::appendTruthGap($gapCodes, $gapTexts, !$stored, 'not_stored', '未入库');
        self::appendTruthGap($gapCodes, $gapTexts, !$readbackVerified, 'readback_not_verified', '数据库回读未验证');
        self::appendTruthGap($gapCodes, $gapTexts, $explicitlyUnverified, 'record_explicitly_unverified', '采集或校验状态明确标记为未验证');
        self::appendTruthGap($gapCodes, $gapTexts, $explicitlyPartial, 'record_explicitly_partial', '采集或校验状态明确标记为部分数据');
        self::appendTruthGap($gapCodes, $gapTexts, $fieldStatus === 'not_loaded', 'field_facts_not_loaded', '字段事实未写入');
        self::appendTruthGap($gapCodes, $gapTexts, in_array($fieldStatus, ['missing', 'partial'], true), 'field_facts_incomplete', '字段事实不完整');
        self::appendTruthGap(
            $gapCodes,
            $gapTexts,
            $capturedCount > 0 && $desensitizedEvidenceCount < $capturedCount,
            'desensitized_source_evidence_incomplete',
            '脱敏来源证据不完整'
        );

        $sourceMethodUnverified = in_array(strtolower($sourceMethod), self::UNVERIFIED_INGESTION_METHODS, true);
        if ($sourceMethodUnverified && $sourceMethod !== '') {
            self::appendTruthGap(
                $gapCodes,
                $gapTexts,
                true,
                'source_method_unverified',
                in_array(strtolower($sourceMethod), ['manual_import', 'manual_override', 'user_provided', 'user_provided_unverified', 'import_csv', 'import_json'], true)
                    ? '人工或导入来源尚未核验'
                    : '旧来源方式尚未核验'
            );
        }

        $identityComplete = $systemHotelId > 0 && $platform !== '' && $dataDate !== '';
        if ($collectionFailed) {
            $status = 'collection_failed';
        } elseif ($sourceMethodUnverified || $explicitlyUnverified) {
            $status = 'unverified';
        } elseif (!$explicitlyPartial && $stored && $readbackVerified && $identityComplete && $collectedAtPrecise && $fieldFactsVerified) {
            $status = 'verified';
        } elseif ($stored && $readbackVerified && $identityComplete) {
            $status = 'partial';
        } else {
            $status = 'unverified';
        }

        $failureReason = '';
        if ($status === 'collection_failed') {
            $failureReason = self::validationFailureReason($row, $hasRawFailure);
        } elseif ($status !== 'verified') {
            $failureReason = implode('；', array_slice(array_values(array_unique($gapTexts)), 0, 6));
        }

        return [
            'status' => $status,
            'status_label' => self::TRUTH_STATUS_LABELS[$status],
            'metric_scope' => 'ota_channel',
            'scope_label' => 'OTA渠道数据，不代表全酒店经营',
            'hotel' => [
                'system_hotel_id' => $systemHotelId > 0 ? $systemHotelId : null,
                'platform_hotel_id' => self::firstText([$row['hotel_id'] ?? $row['ota_hotel_id'] ?? null]),
                'name' => $hotelName,
            ],
            'platform' => $platform,
            'data_date' => $dataDate,
            'source' => [
                'method' => $sourceMethod,
                'trace_id' => $sourceTraceId,
            ],
            'collected_at' => $collectedAt,
            'persistence' => [
                'stored' => $stored,
                'stored_at' => $persistedAt,
                'readback_verified' => $readbackVerified,
                'status' => (string)$storage['code'],
                'label' => (string)$storage['label'],
            ],
            'field_fact' => [
                'status' => $fieldStatus,
                'captured_count' => $capturedCount,
                'missing_count' => $missingCount,
                'desensitized_evidence_count' => $desensitizedEvidenceCount,
            ],
            'failure_reason' => $failureReason,
            'evidence_gap_codes' => array_values(array_unique($gapCodes)),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $envelopes
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public static function summarizeTruthEnvelopes(array $envelopes, array $scope = []): array
    {
        $statusCounts = array_fill_keys(array_keys(self::TRUTH_STATUS_LABELS), 0);
        $hotels = [];
        $platforms = [];
        $dataDates = [];
        $sourceMethods = [];
        $collectedTimes = [];
        $failureReasons = [];
        $storedCount = 0;
        $readbackVerifiedCount = 0;

        foreach ($envelopes as $envelope) {
            if (!is_array($envelope)) {
                continue;
            }
            $status = strtolower(trim((string)($envelope['status'] ?? 'unverified')));
            if (!array_key_exists($status, $statusCounts)) {
                $status = 'unverified';
            }
            $statusCounts[$status]++;

            $hotel = is_array($envelope['hotel'] ?? null) ? $envelope['hotel'] : [];
            $hotelId = max(0, (int)($hotel['system_hotel_id'] ?? 0));
            $hotelName = trim((string)($hotel['name'] ?? ''));
            if ($hotelId > 0 || $hotelName !== '') {
                $hotels[($hotelId > 0 ? 'id:' . $hotelId : 'name:' . $hotelName)] = [
                    'system_hotel_id' => $hotelId > 0 ? $hotelId : null,
                    'name' => $hotelName,
                ];
            }

            $platform = trim((string)($envelope['platform'] ?? ''));
            if ($platform !== '') {
                $platforms[$platform] = true;
            }
            $dataDate = trim((string)($envelope['data_date'] ?? ''));
            if ($dataDate !== '') {
                $dataDates[] = $dataDate;
            }
            $source = is_array($envelope['source'] ?? null) ? $envelope['source'] : [];
            $method = trim((string)($source['method'] ?? ''));
            if ($method !== '') {
                $sourceMethods[$method] = true;
            }
            $collectedAt = trim((string)($envelope['collected_at'] ?? ''));
            if ($collectedAt !== '') {
                $collectedTimes[] = $collectedAt;
            }
            $persistence = is_array($envelope['persistence'] ?? null) ? $envelope['persistence'] : [];
            $storedCount += ($persistence['stored'] ?? false) === true ? 1 : 0;
            $readbackVerifiedCount += ($persistence['readback_verified'] ?? false) === true ? 1 : 0;
            $reason = trim((string)($envelope['failure_reason'] ?? ''));
            if ($reason !== '') {
                $failureReasons[$reason] = true;
            }
        }

        $total = array_sum($statusCounts);
        $excludedUntrustedCount = max(0, (int)($scope['excluded_untrusted_count'] ?? 0));
        if ($total === 0) {
            $status = 'unverified';
        } elseif ($statusCounts['verified'] === $total && $excludedUntrustedCount === 0) {
            $status = 'verified';
        } elseif ($statusCounts['collection_failed'] === $total) {
            $status = 'collection_failed';
        } elseif ($statusCounts['unverified'] === $total) {
            $status = 'unverified';
        } else {
            $status = 'partial';
        }

        sort($dataDates);
        sort($collectedTimes);
        $fallbackFailureReason = trim((string)($scope['fallback_failure_reason'] ?? ''));
        $failureReasonList = array_keys($failureReasons);
        if ($status !== 'verified' && $fallbackFailureReason !== '' && !in_array($fallbackFailureReason, $failureReasonList, true)) {
            $failureReasonList[] = $fallbackFailureReason;
        }

        return [
            'status' => $status,
            'status_label' => self::TRUTH_STATUS_LABELS[$status],
            'metric_scope' => 'ota_channel',
            'scope_label' => 'OTA渠道汇总，不代表全酒店经营',
            'hotels' => array_values($hotels),
            'platforms' => array_values(array_keys($platforms)),
            'date_range' => [
                'start' => $dataDates[0] ?? trim((string)($scope['start_date'] ?? '')),
                'end' => $dataDates !== [] ? $dataDates[count($dataDates) - 1] : trim((string)($scope['end_date'] ?? '')),
            ],
            'source_methods' => array_values(array_keys($sourceMethods)),
            'collected_at_range' => [
                'start' => $collectedTimes[0] ?? '',
                'end' => $collectedTimes !== [] ? $collectedTimes[count($collectedTimes) - 1] : '',
            ],
            'persistence' => [
                'record_count' => $total,
                'stored_count' => $storedCount,
                'readback_verified_count' => $readbackVerifiedCount,
                'excluded_untrusted_count' => $excludedUntrustedCount,
            ],
            'status_counts' => $statusCounts,
            'failure_reason' => implode('；', array_slice($failureReasonList, 0, 4)),
        ];
    }

    /**
     * Convert a derived OTA metric trust record into the same four-state truth
     * contract used by persisted facts. A calculated value is never promoted
     * to verified unless its source rows retain hotel, platform, date,
     * collection-time and database readback evidence.
     *
     * @param array<string, mixed> $metricTrust
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function metricTruthEnvelope(array $metricTrust, array $context = []): array
    {
        $source = is_array($metricTrust['source'] ?? null) ? $metricTrust['source'] : [];
        $rowIds = self::scalarList($source['row_ids'] ?? []);
        $platforms = self::scalarList($source['platforms'] ?? ($context['platforms'] ?? []));
        $hotels = self::hotelList($source['hotels'] ?? [], $context);
        $sourceMethods = self::scalarList($source['source_methods'] ?? []);
        $traceIds = self::scalarList($source['trace_ids'] ?? []);
        $dataTypes = self::scalarList($source['data_types'] ?? []);
        $dateRange = is_array($source['date_range'] ?? null) ? $source['date_range'] : [];
        $startDate = self::firstText([$dateRange['start'] ?? null, $context['start_date'] ?? null, $context['data_date'] ?? null]);
        $endDate = self::firstText([$dateRange['end'] ?? null, $context['end_date'] ?? null, $context['data_date'] ?? null]);
        $collectedRange = is_array($source['collected_at_range'] ?? null) ? $source['collected_at_range'] : [];
        $collectedStart = self::firstText([$collectedRange['start'] ?? null]);
        $collectedEnd = self::firstText([$collectedRange['end'] ?? null, $collectedStart]);
        $rowCount = max(0, (int)($source['row_count'] ?? count($rowIds)));
        $storedCount = max(0, (int)($source['stored_count'] ?? count($rowIds)));
        $readbackCount = max(0, (int)($source['readback_verified_count'] ?? 0));
        $failureReasons = self::scalarList($metricTrust['failure_reasons'] ?? []);
        $savedSuccess = ($metricTrust['saved_success'] ?? false) === true;

        $gapCodes = [];
        self::appendMetricGap($gapCodes, $hotels === [], 'hotel_missing');
        self::appendMetricGap($gapCodes, $platforms === [], 'platform_missing');
        self::appendMetricGap($gapCodes, $startDate === '' || $endDate === '', 'data_date_missing');
        self::appendMetricGap($gapCodes, trim((string)($source['table'] ?? '')) === '', 'source_table_missing');
        self::appendMetricGap($gapCodes, $sourceMethods === [] && $traceIds === [], 'source_method_or_trace_missing');
        self::appendMetricGap($gapCodes, $collectedStart === '' || $collectedEnd === '', 'collected_at_missing');
        self::appendMetricGap($gapCodes, $rowCount <= 0, 'source_rows_missing');
        self::appendMetricGap($gapCodes, $rowCount > 0 && $storedCount < $rowCount, 'not_fully_stored');
        self::appendMetricGap($gapCodes, $rowCount > 0 && $readbackCount < $rowCount, 'readback_not_fully_verified');

        $collectionFailed = false;
        foreach ($failureReasons as $reason) {
            $normalized = strtolower($reason);
            if (preg_match('/(?:collection|capture|fetch|save)_failed|row_status_(?:failed|error)|validation_status_(?:failed|error)|(?:error_info|failed_reason|failure_reason):/', $normalized) === 1) {
                $collectionFailed = true;
                break;
            }
        }

        $coreComplete = $hotels !== []
            && $platforms !== []
            && $startDate !== ''
            && $endDate !== ''
            && trim((string)($source['table'] ?? '')) !== ''
            && ($sourceMethods !== [] || $traceIds !== [])
            && $collectedStart !== ''
            && $collectedEnd !== ''
            && $rowCount > 0
            && $storedCount >= $rowCount
            && $readbackCount >= $rowCount;

        if ($collectionFailed) {
            $status = 'collection_failed';
        } elseif ($savedSuccess && $failureReasons === [] && $coreComplete) {
            $status = 'verified';
        } elseif ($rowCount <= 0 || $readbackCount <= 0) {
            $status = 'unverified';
        } else {
            $status = 'partial';
        }

        $allReasons = array_values(array_unique(array_merge($failureReasons, $gapCodes)));

        return [
            'status' => $status,
            'status_label' => self::TRUTH_STATUS_LABELS[$status],
            'metric_scope' => 'ota_channel',
            'scope_label' => 'OTA渠道指标，不代表全酒店经营',
            'hotels' => $hotels,
            'platforms' => $platforms,
            'date_range' => [
                'start' => $startDate,
                'end' => $endDate,
            ],
            'source' => [
                'table' => trim((string)($source['table'] ?? '')),
                'row_ids' => $rowIds,
                'trace_ids' => $traceIds,
                'methods' => $sourceMethods,
                'data_types' => $dataTypes,
                'caliber' => trim((string)($metricTrust['caliber'] ?? '')),
            ],
            'source_methods' => $sourceMethods,
            'collected_at_range' => [
                'start' => $collectedStart,
                'end' => $collectedEnd,
            ],
            'persistence' => [
                'stored' => $rowCount > 0 && $storedCount >= $rowCount,
                'stored_count' => $storedCount,
                'record_count' => $rowCount,
                'readback_verified' => $rowCount > 0 && $readbackCount >= $rowCount,
                'readback_verified_count' => $readbackCount,
            ],
            'failure_reason' => $status === 'verified' ? '' : implode('; ', array_slice($allReasons, 0, 8)),
            'evidence_gap_codes' => $gapCodes,
        ];
    }

    /** @param array<int, string> $codes */
    private static function appendMetricGap(array &$codes, bool $condition, string $code): void
    {
        if ($condition && !in_array($code, $codes, true)) {
            $codes[] = $code;
        }
    }

    /** @return array<int, string|int|float> */
    private static function scalarList(mixed $value): array
    {
        $items = is_array($value) ? $value : ($value === null || $value === '' ? [] : [$value]);
        $result = [];
        foreach ($items as $item) {
            if (!is_scalar($item) || trim((string)$item) === '') {
                continue;
            }
            $result[] = is_string($item) ? trim($item) : $item;
        }
        return array_values(array_unique($result, SORT_REGULAR));
    }

    /**
     * @param mixed $value
     * @param array<string, mixed> $context
     * @return array<int, array{system_hotel_id: int|null, name: string}>
     */
    private static function hotelList(mixed $value, array $context): array
    {
        $items = is_array($value) ? $value : [];
        $hotels = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $hotelId = max(0, (int)($item['system_hotel_id'] ?? $item['hotel_id'] ?? $item['hotel_key'] ?? 0));
                $name = trim((string)($item['name'] ?? $item['hotel_name'] ?? ''));
            } else {
                $hotelId = is_numeric($item) ? max(0, (int)$item) : 0;
                $name = $hotelId > 0 ? '' : trim((string)$item);
            }
            if ($hotelId <= 0 && $name === '') {
                continue;
            }
            $hotels[($hotelId > 0 ? 'id:' . $hotelId : 'name:' . $name)] = [
                'system_hotel_id' => $hotelId > 0 ? $hotelId : null,
                'name' => $name,
            ];
        }

        $contextHotelId = max(0, (int)($context['system_hotel_id'] ?? $context['hotel_id'] ?? 0));
        $contextHotelName = trim((string)($context['hotel_name'] ?? ''));
        if ($hotels === [] && ($contextHotelId > 0 || $contextHotelName !== '')) {
            $hotels[($contextHotelId > 0 ? 'id:' . $contextHotelId : 'name:' . $contextHotelName)] = [
                'system_hotel_id' => $contextHotelId > 0 ? $contextHotelId : null,
                'name' => $contextHotelName,
            ];
        }
        return array_values($hotels);
    }

    /** @param array<int, string> $codes @param array<int, string> $texts */
    private static function appendTruthGap(array &$codes, array &$texts, bool $condition, string $code, string $text): void
    {
        if (!$condition) {
            return;
        }
        $codes[] = $code;
        $texts[] = $text;
    }

    /** @return array<string, mixed> */
    private static function decodeRawData(mixed $value): array
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

    private static function normalizePlatform(mixed $value): string
    {
        $normalized = strtolower(trim((string)$value));
        return match ($normalized) {
            '携程' => 'ctrip',
            '美团' => 'meituan',
            '去哪儿' => 'qunar',
            default => preg_match('/^[a-z0-9_-]{1,50}$/D', $normalized) === 1 ? $normalized : '',
        };
    }

    /** @param array<int, mixed> $values */
    private static function firstText(array $values): string
    {
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $text = trim((string)$value);
            if ($text !== '') {
                return $text;
            }
        }
        return '';
    }

    /** @param array<int, mixed> $values */
    private static function latestDateTime(array $values): string
    {
        $items = array_values(array_filter(array_map(
            static fn(mixed $value): string => is_scalar($value) ? trim((string)$value) : '',
            $values
        ), static fn(string $value): bool => $value !== ''));
        sort($items);
        return $items !== [] ? $items[count($items) - 1] : '';
    }

    /** @param array<string, mixed> $raw */
    private static function resolveCollectedAt(array $row, array $raw): string
    {
        $meta = is_array($raw['meta'] ?? null) ? $raw['meta'] : [];
        $capture = is_array($raw['capture_evidence'] ?? null) ? $raw['capture_evidence'] : [];
        return self::firstText([
            $row['collected_at'] ?? null,
            $row['snapshot_time'] ?? null,
            $row['received_at'] ?? null,
            $raw['collected_at'] ?? null,
            $raw['collectedAt'] ?? null,
            $raw['captured_at'] ?? null,
            $raw['capturedAt'] ?? null,
            $raw['fetched_at'] ?? null,
            $raw['fetch_time'] ?? null,
            $meta['collected_at'] ?? null,
            $meta['captured_at'] ?? null,
            $capture['collected_at'] ?? null,
            $capture['captured_at'] ?? null,
        ]);
    }

    private static function collectedAtHasTime(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?$/D', trim($value)) === 1;
    }

    private static function validationFailureReason(array $row, bool $hasRawFailure): string
    {
        $flags = $row['validation_flags'] ?? null;
        if (is_string($flags) && trim($flags) !== '') {
            $decoded = json_decode($flags, true);
            if (is_array($decoded)) {
                foreach ($decoded as $flag) {
                    if (!is_array($flag)) {
                        continue;
                    }
                    $field = trim((string)($flag['field'] ?? $flag['key'] ?? ''));
                    if ($field !== '' && preg_match('/^[a-zA-Z0-9_.-]{1,80}$/D', $field) === 1) {
                        return '字段 ' . $field . ' 校验失败';
                    }
                    $code = trim((string)($flag['code'] ?? ''));
                    if ($code !== '' && preg_match('/^[a-zA-Z0-9_.-]{1,80}$/D', $code) === 1) {
                        return '入库校验失败：' . $code;
                    }
                }
            }
        }
        if ($hasRawFailure) {
            return '平台返回包含错误状态，未形成可信字段事实';
        }
        $status = strtolower(trim((string)($row['validation_status'] ?? $row['status'] ?? '')));
        return $status !== '' && preg_match('/^[a-z0-9_.-]{1,80}$/D', $status) === 1
            ? '采集或入库校验失败：' . $status
            : '采集或入库校验失败';
    }

    /** @param array<string, mixed> $raw */
    private static function rawContainsFailure(array $raw): bool
    {
        foreach (['error', 'errors'] as $key) {
            if (!array_key_exists($key, $raw)) {
                continue;
            }
            $value = $raw[$key];
            if (is_array($value) && $value !== []) {
                return true;
            }
            if (is_scalar($value) && trim((string)$value) !== '' && !in_array(strtolower(trim((string)$value)), ['0', 'false', 'none', 'null'], true)) {
                return true;
            }
        }
        return false;
    }
}
