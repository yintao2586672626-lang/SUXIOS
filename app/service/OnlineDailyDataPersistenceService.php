<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

final class OnlineDailyDataPersistenceService
{
    public static function getColumns(): array
    {
        static $columns = null;
        if ($columns !== null) {
            return $columns;
        }

        $rows = Db::query('SHOW COLUMNS FROM online_daily_data');
        $columns = array_fill_keys(array_column($rows, 'Field'), true);
        return $columns;
    }

    /**
     * Persist readback proof only while every value from the matched database
     * snapshot is still current. The caller must pass the exact rows it read
     * and compared; scalar IDs are deliberately rejected so a concurrent
     * overwrite cannot be trusted by a later naked-ID update.
     *
     * @param array<int|string, array<string, mixed>> $readbackRows
     * @param array<string, bool>|null $columns
     */
    public static function markRowsReadbackVerified(array $readbackRows, ?array $columns = null): bool
    {
        if ($readbackRows === []) {
            return false;
        }

        $columns ??= self::getColumns();
        if (!isset($columns['readback_verified'], $columns['tenant_id'], $columns['system_hotel_id'])) {
            return false;
        }

        $snapshots = [];
        foreach ($readbackRows as $readbackRow) {
            if (!is_array($readbackRow) || array_diff_key($columns, $readbackRow) !== []) {
                return false;
            }
            $snapshot = array_intersect_key($readbackRow, $columns);
            $rowId = filter_var($snapshot['id'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            $systemHotelId = filter_var($snapshot['system_hotel_id'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            $tenantId = filter_var($snapshot['tenant_id'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if ($rowId === false || $systemHotelId === false || $tenantId === false
                || (int)($snapshot['readback_verified'] ?? -1) !== 0) {
                return false;
            }
            try {
                if (self::resolveTenantIdForSystemHotel($systemHotelId) !== $tenantId) {
                    return false;
                }
            } catch (\Throwable) {
                return false;
            }
            $snapshots[(int)$rowId] = $snapshot;
        }
        if (count($snapshots) !== count($readbackRows)) {
            return false;
        }

        try {
            Db::transaction(static function () use ($snapshots, $columns): void {
                $verifiedAt = date('Y-m-d H:i:s');
                foreach ($snapshots as $rowId => $snapshot) {
                    $query = Db::name('online_daily_data')
                        ->where('id', $rowId)
                        ->where('readback_verified', 0);
                    foreach ($snapshot as $field => $value) {
                        if (in_array($field, ['id', 'readback_verified'], true)) {
                            continue;
                        }
                        if ($value === null) {
                            $query->whereNull($field);
                        } else {
                            $query->where($field, $value);
                        }
                    }

                    $update = ['readback_verified' => 1];
                    if (isset($columns['readback_verified_at'])) {
                        $update['readback_verified_at'] = $verifiedAt;
                    }
                    if ((int)$query->update($update) !== 1) {
                        throw new \RuntimeException('online_daily_data_readback_compare_and_set_failed');
                    }
                }
            });
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /**
     * Every write invalidates the previous proof before touching business data.
     * When the migration is absent no synthetic proof fields are added.
     *
     * @param array<string, bool>|null $columns
     */
    public static function resetReadbackVerification(array $data, ?array $columns = null): array
    {
        $columns ??= self::getColumns();
        if (isset($columns['readback_verified'])) {
            $data['readback_verified'] = 0;
        }
        if (isset($columns['readback_verified_at'])) {
            $data['readback_verified_at'] = null;
        }
        return $data;
    }

    /**
     * Compare the stored identity and every business fact actually written.
     * Missing metrics are not invented; only keys present in the expected row
     * participate in the value-level readback contract.
     */
    public static function matchesBusinessReadback(array $persisted, array $expected): bool
    {
        $tenantId = filter_var($expected['tenant_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($tenantId === false || (string)($persisted['tenant_id'] ?? '') !== (string)$tenantId) {
            return false;
        }
        $identityFields = [
            'tenant_id', 'source', 'platform', 'data_type', 'data_date', 'dimension',
            'hotel_id', 'hotel_name', 'system_hotel_id', 'compare_type',
            'data_period', 'snapshot_bucket', 'source_trace_id', 'persistence_identity_hash',
        ];
        $businessFields = array_values(array_filter([
            'amount', 'quantity', 'book_order_num', 'comment_score',
            'qunar_comment_score', 'data_value', 'list_exposure',
            'detail_exposure', 'flow_rate', 'order_filling_num',
            'order_submit_num', 'raw_data',
        ], static fn(string $field): bool => array_key_exists($field, $expected)));

        return self::matchesMetricReadback(
            $persisted,
            $expected,
            $identityFields,
            $businessFields
        );
    }

    public static function filterFields(array $data): array
    {
        $columns = self::getColumns();
        $data = self::applyTenantScope($data, $columns);
        return array_intersect_key($data, $columns);
    }

    /** @param array<string, bool>|null $columns */
    public static function applyTenantScope(array $data, ?array $columns = null): array
    {
        $columns ??= self::getColumns();
        if (isset($columns['tenant_id'])) {
            $data['tenant_id'] = self::resolveTenantIdForSystemHotel($data['system_hotel_id'] ?? null);
        }
        return $data;
    }

    public static function resolveTenantIdForSystemHotel(mixed $systemHotelId): int
    {
        $systemHotelId = filter_var($systemHotelId, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($systemHotelId === false) {
            throw new \InvalidArgumentException('system_hotel_id_invalid_for_tenant_scope');
        }

        $tenantId = Db::name('hotels')->where('id', $systemHotelId)->value('tenant_id');
        $tenantId = filter_var($tenantId, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($tenantId === false) {
            throw new \RuntimeException('hotel_tenant_id_missing_or_invalid');
        }
        return (int)$tenantId;
    }

    /**
     * Keep updates sparse while making missing metrics explicit on new rows.
     * An observed zero is retained because presence is checked with
     * array_key_exists rather than truthiness.
     *
     * @param array<int, string> $metricFields
     */
    public static function buildMetricAwareWriteData(
        array $base,
        array $observedMetrics,
        array $metricFields,
        bool $isInsert
    ): array {
        foreach ($metricFields as $field) {
            unset($base[$field]);
        }
        $data = array_merge($base, $observedMetrics);
        if (!$isInsert) {
            return $data;
        }

        foreach ($metricFields as $field) {
            if (!array_key_exists($field, $data)) {
                $data[$field] = null;
            }
        }
        return $data;
    }

    /**
     * @param array<int, string> $identityFields
     * @param array<int, string> $observedMetricFields
     */
    public static function matchesMetricReadback(
        array $persisted,
        array $expected,
        array $identityFields,
        array $observedMetricFields
    ): bool {
        foreach ($identityFields as $field) {
            if (!array_key_exists($field, $expected)) {
                continue;
            }
            $expectedValue = $expected[$field];
            $persistedValue = $persisted[$field] ?? null;
            if ($expectedValue === null) {
                if ($persistedValue !== null && $persistedValue !== '') {
                    return false;
                }
                continue;
            }
            if ((string)$persistedValue !== (string)$expectedValue) {
                return false;
            }
        }

        foreach ($observedMetricFields as $field) {
            if (!array_key_exists($field, $expected) || !array_key_exists($field, $persisted)) {
                return false;
            }
            $expectedValue = $expected[$field];
            $persistedValue = $persisted[$field];
            if ($expectedValue === null || $persistedValue === null) {
                if ($expectedValue !== $persistedValue) {
                    return false;
                }
                continue;
            }
            if (is_numeric($expectedValue) && is_numeric($persistedValue)) {
                if (abs((float)$persistedValue - (float)$expectedValue) > 0.000001) {
                    return false;
                }
                continue;
            }
            if ((string)$persistedValue !== (string)$expectedValue) {
                return false;
            }
        }

        return true;
    }

    public static function desensitizedSourceTraceId(array $source): string
    {
        foreach (['source_trace_id', '_source_trace_id', 'trace_id', '_trace_id'] as $key) {
            $value = self::safeSourceTraceValue($source[$key] ?? null);
            if ($value !== '') {
                return $value;
            }
        }

        $nested = $source['capture_evidence'] ?? null;
        if (is_array($nested)) {
            return self::desensitizedSourceTraceId($nested);
        }

        return '';
    }

    private static function safeSourceTraceValue(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $text = trim((string)$value);
        if ($text === ''
            || preg_match('/\b(cookie|authorization|bearer|token|password|secret)\b/i', $text)
            || preg_match('#https?://#i', $text)
        ) {
            return '';
        }

        return mb_substr($text, 0, 80);
    }

    public static function buildValidationFields(array $data): array
    {
        $flags = [];
        foreach (['source', 'hotel_id', 'data_date'] as $field) {
            if (!isset($data[$field]) || trim((string)$data[$field]) === '') {
                $flags[] = [
                    'level' => 'error',
                    'field' => $field,
                    'message' => $field . ' is missing',
                ];
            }
        }

        if (array_key_exists('data_type', $data) && trim((string)$data['data_type']) === '') {
            $flags[] = [
                'level' => 'error',
                'field' => 'data_type',
                'message' => 'data_type is missing',
            ];
        }

        if (array_key_exists('system_hotel_id', $data) && trim((string)$data['system_hotel_id']) === '') {
            $flags[] = [
                'level' => 'warning',
                'field' => 'system_hotel_id',
                'message' => 'system_hotel_id is missing; row is not bound to a system hotel',
            ];
        }

        foreach (['amount', 'quantity', 'book_order_num', 'data_value', 'list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num'] as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === '' || $data[$field] === null) {
                continue;
            }
            if (!is_numeric($data[$field])) {
                $flags[] = [
                    'level' => 'error',
                    'field' => $field,
                    'message' => $field . ' must be numeric',
                ];
                continue;
            }
            if ((float)$data[$field] < 0) {
                $flags[] = [
                    'level' => 'error',
                    'field' => $field,
                    'message' => $field . ' must not be negative',
                ];
            }
        }

        if (isset($data['flow_rate']) && is_numeric($data['flow_rate']) && (float)$data['flow_rate'] > 100.0) {
            $flags[] = [
                'level' => 'error',
                'field' => 'flow_rate',
                'message' => 'flow_rate must not exceed 100',
            ];
        }

        foreach (['comment_score', 'qunar_comment_score'] as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === '' || $data[$field] === null) {
                continue;
            }
            if (!is_numeric($data[$field])) {
                $flags[] = [
                    'level' => 'error',
                    'field' => $field,
                    'message' => $field . ' must be numeric',
                ];
                continue;
            }
            $value = (float)$data[$field];
            if ($value < 0.0 || $value > 5.0) {
                $flags[] = [
                    'level' => 'error',
                    'field' => $field,
                    'message' => $field . ' must be between 0 and 5',
                ];
            }
        }

        $amount = isset($data['amount']) && is_numeric($data['amount']) ? (float)$data['amount'] : 0.0;
        $quantity = isset($data['quantity']) && is_numeric($data['quantity']) ? (float)$data['quantity'] : null;
        if ($amount > 0 && $quantity === 0.0) {
            $flags[] = [
                'level' => 'warning',
                'field' => 'quantity',
                'message' => 'amount exists but quantity is zero',
            ];
        }

        $hasError = array_reduce($flags, static fn(bool $carry, array $flag): bool => $carry || ($flag['level'] ?? '') === 'error', false);
        return [
            'validation_status' => $hasError ? 'abnormal' : (empty($flags) ? 'normal' : 'warning'),
            'validation_flags' => json_encode($flags, JSON_UNESCAPED_UNICODE),
        ];
    }

    public static function applyValidationFields(array $data, ?array $columns = null): array
    {
        $columns = $columns ?? self::getColumns();
        $data = self::applyTenantScope($data, $columns);
        $data = self::applyPeriodFields($data, $columns);
        foreach (self::buildValidationFields($data) as $field => $value) {
            if (isset($columns[$field])) {
                $data[$field] = $value;
            }
        }
        return $data;
    }

    public static function applyPeriodFields(array $data, ?array $columns = null, array $sourceRow = []): array
    {
        $columns = $columns ?? self::getColumns();
        if (!isset($columns['data_period']) && !isset($columns['snapshot_time']) && !isset($columns['snapshot_bucket']) && !isset($columns['is_final'])) {
            return $data;
        }

        $merged = array_merge($sourceRow, $data);
        $period = self::normalizePeriod($merged['data_period'] ?? $merged['dataPeriod'] ?? '');
        if ($period === '') {
            $period = self::looksLikeRealtimeRow($merged) ? 'realtime_snapshot' : 'historical_daily';
        }

        $dataDate = self::normalizeDate($merged['data_date'] ?? $merged['dataDate'] ?? '');
        $dataType = strtolower(str_replace(['-', ' '], '_', trim((string)($merged['data_type'] ?? $merged['dataType'] ?? ''))));
        if (in_array($dataType, ['traffic_forecast', 'trafficforecast', 'flow_forecast', 'flowforecast', 'forecast'], true)) {
            $period = 'next_30_days';
        } elseif ($dataDate === date('Y-m-d') && $period === 'historical_daily') {
            $period = 'realtime_snapshot';
        }
        $snapshotTime = null;
        $snapshotBucket = '';
        if ($period === 'realtime_snapshot') {
            $snapshotTime = self::normalizeDateTime(
                $merged['snapshot_time']
                ?? $merged['snapshotTime']
                ?? $merged['captured_at']
                ?? $merged['capturedAt']
                ?? null
            ) ?? date('Y-m-d H:i:s');
            $snapshotBucket = date('YmdHi', strtotime($snapshotTime) ?: time());
        }

        if (isset($columns['data_period'])) {
            $data['data_period'] = $period;
        }
        if (isset($columns['snapshot_time'])) {
            $data['snapshot_time'] = $snapshotTime;
        }
        if (isset($columns['snapshot_bucket'])) {
            $data['snapshot_bucket'] = $snapshotBucket;
        }
        if (isset($columns['is_final'])) {
            $data['is_final'] = $period === 'historical_daily' ? 1 : 0;
        }

        return $data;
    }

    public static function applyPeriodQuery($query, array $data, array $columns): void
    {
        if (!isset($columns['data_period'])) {
            return;
        }

        $period = self::normalizePeriod($data['data_period'] ?? '');
        if ($period === '') {
            $period = 'historical_daily';
        }
        $query->where('data_period', $period);

        if ($period === 'realtime_snapshot' && isset($columns['snapshot_bucket'])) {
            $query->where('snapshot_bucket', (string)($data['snapshot_bucket'] ?? ''));
        }
    }

    public static function normalizePeriod($value): string
    {
        $value = strtolower(str_replace(['-', ' '], '_', trim((string)$value)));
        return match ($value) {
            'realtime', 'real_time', 'realtime_snapshot', 'today_realtime', 'live', 'snapshot' => 'realtime_snapshot',
            'historical', 'history', 'historical_daily', 'daily', 'fixed', 'final' => 'historical_daily',
            'next_30_days', 'next30days', 'future_forecast', 'forecast', 'forecast_window' => 'next_30_days',
            default => '',
        };
    }

    public static function normalizeDateTime($value): ?string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);
        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }

    public function parseAndSaveTrafficData($responseData, $startDate, $endDate, string $source, ?int $systemHotelId = null, ?string $platform = null, ?string $expectedPlatformHotelId = null): int
    {
        try {
            if (in_array($source, ['ctrip', 'qunar'], true)) {
                return $this->parseAndSaveCtripTrafficData($responseData, (string)$startDate, $source, $systemHotelId, $platform, $expectedPlatformHotelId);
            }

            return $this->parseAndSaveGenericTrafficData($responseData, (string)$startDate, $source, $systemHotelId, $expectedPlatformHotelId);
        } catch (\Throwable $e) {
            throw new \RuntimeException('traffic_data_persistence_failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function parseAndSaveCtripTrafficData($responseData, string $startDate, string $source, ?int $systemHotelId, ?string $platform, ?string $expectedPlatformHotelId = null): int
    {
        $dataList = OnlineTrafficDataExtractionService::extractCtripTrafficRows($responseData);
        if (empty($dataList)) {
            return 0;
        }

        $savedCount = 0;
        $platform = $platform ?: ($source === 'qunar' ? 'Qunar' : 'Ctrip');
        $expectedPlatformHotelId = trim((string)$expectedPlatformHotelId);
        foreach ($dataList as $item) {
            if (!is_array($item)) {
                continue;
            }

            $hotelId = $this->resolveCtripPlatformHotelId($item);
            $compareText = strtolower((string)($item['compareType'] ?? $item['compare_type'] ?? $item['type'] ?? $item['rankType'] ?? $item['name'] ?? $item['hotelName'] ?? ''));
            $isCompetitor = str_contains($compareText, 'competitor')
                || str_contains($compareText, 'peer')
                || str_contains($compareText, 'avg')
                || str_contains($compareText, 'average')
                || (is_numeric($hotelId) && (int)$hotelId < 0);
            $isExplicitSelf = $compareText === 'self'
                || str_contains($compareText, 'my hotel')
                || str_contains($compareText, 'my_hotel')
                || str_contains($compareText, '我的酒店')
                || str_contains($compareText, '本店');
            if (!is_numeric($hotelId)) {
                if ($isCompetitor) {
                    $hotelId = -1;
                } else {
                    // system_hotel_id is an internal ownership key, not the
                    // Ctrip hotelId. Missing platform identity must stay
                    // missing instead of contaminating OTA rows with a local
                    // database ID.
                    continue;
                }
            }
            $hotelId = (int)$hotelId;
            if ($hotelId !== -1 && $hotelId <= 0) {
                continue;
            }
            if ($expectedPlatformHotelId !== '' && $hotelId > 0) {
                if (hash_equals($expectedPlatformHotelId, (string)$hotelId)) {
                    $isCompetitor = false;
                } elseif ($isExplicitSelf) {
                    // An explicit self row with a different platform ID is a
                    // cross-hotel response and must not be stored as this hotel.
                    continue;
                } else {
                    $isCompetitor = true;
                }
            }

            $itemDate = $item['date'] ?? $item['dataDate'] ?? $item['statDate'] ?? $item['stat_date'] ?? $item['data_date'] ?? $item['reportDate'] ?? $item['day'] ?? $startDate;
            if (!$itemDate || strtotime((string)$itemDate) === false) {
                continue;
            }
            $itemDate = date('Y-m-d', strtotime((string)$itemDate));
            $isAverage = $hotelId < 0 || str_contains($compareText, 'avg') || str_contains($compareText, 'average');
            $compareType = $isAverage ? 'competitor_avg' : ($isCompetitor ? 'competitor' : 'self');
            $hotelName = (string)($item['hotelName'] ?? $item['hotel_name'] ?? $item['HotelName'] ?? $item['name'] ?? ($compareType === 'self' ? '本店' : '竞争圈'));
            $trafficMetrics = $this->extractObservedTrafficMetrics($item, false);
            if (array_key_exists('list_exposure', $trafficMetrics)) {
                $trafficMetrics['data_value'] = (float)$trafficMetrics['list_exposure'];
            }
            $columns = self::getColumns();
            $trafficMetrics = array_intersect_key($trafficMetrics, $columns);
            $periodFilter = self::applyPeriodFields([
                'data_date' => $itemDate,
                'source' => $source,
                'data_type' => 'traffic',
                'dimension' => $platform . ':' . $compareType,
            ], $columns, $item);

            $query = Db::name('online_daily_data')
                ->where('data_date', $itemDate)
                ->where('source', $source)
                ->where('data_type', 'traffic')
                ->where('hotel_id', (string)$hotelId);
            self::applyPeriodQuery($query, $periodFilter, $columns);

            if (isset($columns['platform'])) {
                $query->where('platform', $platform);
            }
            if (isset($columns['compare_type'])) {
                $query->where('compare_type', $compareType);
            }
            if ($systemHotelId !== null) {
                $query->where('system_hotel_id', $systemHotelId);
            } else {
                $query->whereNull('system_hotel_id');
            }

            $exists = $query->find();
            $sourceTraceId = self::desensitizedSourceTraceId($item);
            $base = [
                'hotel_id' => (string)$hotelId,
                'hotel_name' => $hotelName,
                'system_hotel_id' => $systemHotelId,
                'data_date' => $itemDate,
                'source' => $source,
                'data_type' => 'traffic',
                'dimension' => $platform . ':' . $compareType,
                'platform' => $platform,
                'compare_type' => $compareType,
                'raw_data' => json_encode($item, JSON_UNESCAPED_UNICODE),
            ];
            if ($sourceTraceId !== '') {
                $base['source_trace_id'] = $sourceTraceId;
            }
            $payload = self::buildMetricAwareWriteData(
                $base,
                $trafficMetrics,
                self::trafficMetricFields(),
                !$exists
            );
            $data = self::applyValidationFields($payload);
            $data = OnlineDataFieldFactService::attachToOnlineDailyRow($data, $item);
            $data = self::filterFields($data);
            $data = self::resetReadbackVerification($data, $columns);

            if ($exists) {
                $rowId = (int)$exists['id'];
                Db::name('online_daily_data')->where('id', $rowId)->update($data);
            } else {
                $rowId = (int)Db::name('online_daily_data')->insertGetId($data);
            }
            $readbackRow = $rowId > 0
                ? $this->verifiedTrafficRowReadback($rowId, $data, array_keys($trafficMetrics))
                : null;
            if (is_array($readbackRow)
                && self::markRowsReadbackVerified([$readbackRow], $columns)) {
                $savedCount++;
            }
        }

        return $savedCount;
    }

    /** @return array<int, string> */
    public function validateGenericTrafficBinding($responseData, string $expectedPlatformHotelId): array
    {
        $expectedPlatformHotelId = trim($expectedPlatformHotelId);
        if ($expectedPlatformHotelId === '') {
            throw new \InvalidArgumentException('Expected Meituan platform hotel identity is missing.');
        }
        $dataList = $this->resolveGenericTrafficDataList($responseData);
        $returnedIds = [];
        foreach ($dataList as $item) {
            if (!is_array($item)) {
                continue;
            }
            $hotelId = trim((string)($item['hotelId'] ?? $item['hotel_id'] ?? $item['HotelId'] ?? $item['hotelID'] ?? $item['poiId'] ?? $item['poi_id'] ?? $item['storeId'] ?? $item['store_id'] ?? ''));
            if ($hotelId === '') {
                continue;
            }
            $returnedIds[$hotelId] = true;
            if (!hash_equals($expectedPlatformHotelId, $hotelId)) {
                throw new \InvalidArgumentException('Meituan traffic response platform hotel identity mismatch.');
            }
        }
        if ($returnedIds === []) {
            throw new \InvalidArgumentException('Meituan traffic response platform hotel identity is unverified.');
        }
        return array_values(array_map('strval', array_keys($returnedIds)));
    }

    private function parseAndSaveGenericTrafficData($responseData, string $startDate, string $source, ?int $systemHotelId, ?string $expectedPlatformHotelId = null): int
    {
        $dataList = $this->resolveGenericTrafficDataList($responseData);
        if (empty($dataList)) {
            return 0;
        }
        if (trim((string)$expectedPlatformHotelId) !== '') {
            $this->validateGenericTrafficBinding($responseData, (string)$expectedPlatformHotelId);
        }

        $savedCount = 0;
        $dataDate = $startDate ?: date('Y-m-d', strtotime('-1 day'));

        foreach ($dataList as $item) {
            if (!is_array($item)) {
                continue;
            }

            $hotelId = $item['hotelId'] ?? $item['hotel_id'] ?? $item['HotelId'] ?? $item['hotelID'] ?? $item['poiId'] ?? $item['poi_id'] ?? $item['storeId'] ?? $item['store_id'] ?? $item['partnerId'] ?? $item['partner_id'] ?? null;
            $hotelName = $item['hotelName'] ?? $item['hotel_name'] ?? $item['HotelName'] ?? $item['name'] ?? $item['poiName'] ?? $item['poi_name'] ?? '';

            if (empty($hotelId) && empty($hotelName)) {
                continue;
            }

            $trafficMetrics = $this->extractObservedTrafficMetrics($item, true);
            $trafficValue = OnlineTrafficDataExtractionService::extractTrafficValue($item);
            if ($trafficValue !== null) {
                $trafficMetrics['data_value'] = round($trafficValue, 2);
            } elseif (array_key_exists('list_exposure', $trafficMetrics)) {
                $trafficMetrics['data_value'] = (float)$trafficMetrics['list_exposure'];
            }
            $itemDate = $item['dataDate'] ?? $item['date'] ?? $item['statDate'] ?? $item['stat_date'] ?? $item['data_date'] ?? $dataDate;
            $dimension = $item['metric'] ?? $item['metricName'] ?? $item['dimension'] ?? $item['_metric'] ?? 'traffic';
            $columns = self::getColumns();
            $trafficMetrics = array_intersect_key($trafficMetrics, $columns);
            $periodFilter = self::applyPeriodFields([
                'data_date' => $itemDate,
                'source' => $source,
                'data_type' => 'traffic',
                'dimension' => $dimension ?: 'traffic',
            ], $columns, $item);

            $query = Db::name('online_daily_data')
                ->where('data_date', $itemDate)
                ->where('source', $source)
                ->where('data_type', 'traffic');
            self::applyPeriodQuery($query, $periodFilter, $columns);

            if (!empty($hotelId)) {
                $query->where('hotel_id', (string)$hotelId);
            } else {
                $query->where('hotel_name', $hotelName);
            }

            if ($systemHotelId !== null) {
                $query->where('system_hotel_id', $systemHotelId);
            }

            $exists = $query->find();

            $sourceTraceId = self::desensitizedSourceTraceId($item);
            $base = [
                'hotel_id' => $hotelId ? (string)$hotelId : '',
                'hotel_name' => $hotelName,
                'system_hotel_id' => $systemHotelId,
                'data_date' => $itemDate,
                'source' => $source,
                'data_type' => 'traffic',
                'dimension' => $dimension ?: 'traffic',
                'raw_data' => json_encode($item, JSON_UNESCAPED_UNICODE),
            ];
            if ($sourceTraceId !== '') {
                $base['source_trace_id'] = $sourceTraceId;
            }
            $payload = self::buildMetricAwareWriteData(
                $base,
                $trafficMetrics,
                self::trafficMetricFields(),
                !$exists
            );
            $data = self::applyValidationFields($payload);
            $data = OnlineDataFieldFactService::attachToOnlineDailyRow($data, $item);
            $data = self::filterFields($data);
            $data = self::resetReadbackVerification($data, $columns);

            if ($exists) {
                $rowId = (int)$exists['id'];
                Db::name('online_daily_data')
                    ->where('id', $rowId)
                    ->update($data);
            } else {
                $rowId = (int)Db::name('online_daily_data')->insertGetId($data);
            }
            $readbackRow = $rowId > 0
                ? $this->verifiedTrafficRowReadback($rowId, $data, array_keys($trafficMetrics))
                : null;
            if (is_array($readbackRow)
                && self::markRowsReadbackVerified([$readbackRow], $columns)) {
                $savedCount++;
            }
        }

        return $savedCount;
    }

    /** @param array<int, string> $observedMetricFields */
    private function verifiedTrafficRowReadback(int $rowId, array $expected, array $observedMetricFields): ?array
    {
        $persisted = Db::name('online_daily_data')->where('id', $rowId)->find();
        if (!is_array($persisted)) {
            return null;
        }

        return self::matchesMetricReadback(
            $persisted,
            $expected,
            ['tenant_id', 'source', 'data_type', 'data_date', 'dimension', 'hotel_id', 'system_hotel_id'],
            $observedMetricFields
        ) ? $persisted : null;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, int|float>
     */
    private function extractObservedTrafficMetrics(array $item, bool $generic): array
    {
        $aliases = [
            'list_exposure' => $generic
                ? ['list_exposure', 'listExposure', 'exposure_count', 'exposureCount', 'exposureNum', 'impression', 'impressions', 'exposure']
                : ['listExposure', 'list_exposure', 'exposure', 'exposureCount', 'impressions', 'showCount', 'PV', 'pv', 'pageView', 'pageViews', 'page_view'],
            'detail_exposure' => $generic
                ? ['detail_exposure', 'detailExposure', 'page_views', 'pageViews', 'unique_visitors', 'uniqueVisitors', 'visitor_count', 'visitorCount', 'click_count', 'clickCount', 'clicks', 'click', 'uv', 'UV', 'pv', 'views']
                : ['detailExposure', 'detail_exposure', 'detailVisitors', 'detailUv', 'visitorCount', 'UV', 'uv', 'uniqueVisitors', 'unique_visitors', 'views'],
            'flow_rate' => $generic
                ? ['flow_rate', 'flowRate', 'conversion_rate', 'conversionRate', 'orderRate']
                : ['flowRate', 'flow_rate', 'conversionRate', 'conversion_rate', 'convertionRate', 'convertRate', 'transforRate', 'transferRate', 'transRate', 'cvr'],
            'order_filling_num' => $generic
                ? ['order_filling_num', 'orderFillingNum', 'orderVisitors', 'click_count', 'clickCount', 'clicks', 'click']
                : ['orderFillingNum', 'order_filling_num', 'orderVisitors', 'clickCount', 'click_count', 'clickNum', 'clicks'],
            'order_submit_num' => $generic
                ? ['order_submit_num', 'orderSubmitNum', 'submit_users', 'submitUsers', 'submitNum', 'orderCount', 'order_count', 'orderNum', 'bookOrderNum', 'orders']
                : ['orderSubmitNum', 'order_submit_num', 'submitUsers', 'submitNum', 'orderCount', 'order_count', 'orderNum', 'bookOrderNum', 'dealNum', 'orders'],
        ];

        $metrics = [];
        foreach ($aliases as $field => $keys) {
            $value = CtripTrafficDisplayService::readTrafficNumber($item, $keys, null);
            if ($value === null) {
                continue;
            }
            $metrics[$field] = $field === 'flow_rate'
                ? round(CtripTrafficDisplayService::normalizeTrafficPercent($value), 2)
                : (int)$value;
        }
        return $metrics;
    }

    /** @return array<int, string> */
    private static function trafficMetricFields(): array
    {
        return [
            'amount',
            'quantity',
            'book_order_num',
            'comment_score',
            'qunar_comment_score',
            'data_value',
            'list_exposure',
            'detail_exposure',
            'flow_rate',
            'order_filling_num',
            'order_submit_num',
        ];
    }

    private function resolveGenericTrafficDataList($responseData): array
    {
        foreach ([
            ['data', 'list'],
            ['data', 'hotelList'],
            ['data', 'records'],
            ['data', 'rows'],
            ['data', 'flowData'],
            ['data', 'trafficData'],
            ['list'],
        ] as $path) {
            $list = $this->readNestedArray($responseData, $path);
            if (is_array($list)) {
                return $this->attachListSourcePaths($list, $path);
            }
        }
        if (isset($responseData['data']) && is_array($responseData['data']) && isset($responseData['data'][0])) {
            return $this->attachListSourcePaths($responseData['data'], ['data']);
        }

        return OnlineTrafficDataExtractionService::extractGenericTrafficRows($responseData);
    }

    private function readNestedArray($value, array $path): ?array
    {
        $current = $value;
        foreach ($path as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return null;
            }
            $current = $current[$part];
        }

        return is_array($current) ? $current : null;
    }

    private function attachListSourcePaths(array $rows, array $basePath): array
    {
        $result = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!isset($row['_source_path']) || trim((string)$row['_source_path']) === '') {
                $row['_source_path'] = $this->sourcePathString(array_merge($basePath, [(string)$index]));
            }
            $result[] = $row;
        }

        return $result;
    }

    private function sourcePathString(array $path): string
    {
        return implode('.', array_map(static fn($part): string => (string)$part, $path));
    }

    private static function looksLikeRealtimeRow(array $row): bool
    {
        $dataDate = self::normalizeDate($row['data_date'] ?? $row['dataDate'] ?? '');
        if ($dataDate !== date('Y-m-d')) {
            return false;
        }

        $signals = [
            $row['endpoint_id'] ?? '',
            $row['_endpoint_id'] ?? '',
            $row['source_url'] ?? '',
            $row['_source_url'] ?? '',
            $row['dimension'] ?? '',
            $row['data_type'] ?? '',
        ];
        $text = strtolower(implode('|', array_map(static fn($value): string => (string)$value, $signals)));
        foreach (['realtime', 'real_time', 'today', 'current', 'rank', 'inventory', 'price'] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeDate($value): string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') {
            return '';
        }

        $timestamp = strtotime($value);
        return $timestamp === false ? '' : date('Y-m-d', $timestamp);
    }

    private function resolveCtripPlatformHotelId(array $row, mixed $fallback = ''): string
    {
        foreach (['masterHotelId', 'masterhotelid', 'master_hotel_id', 'hotelId', 'hotel_id', 'HotelId', 'hotelID', 'ota_hotel_id', 'ctrip_hotel_id'] as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            $value = $row[$key];
            if (is_array($value) || is_object($value)) {
                continue;
            }
            $id = trim((string)$value);
            if ($id !== '') {
                return $id;
            }
        }

        if (is_array($fallback) || is_object($fallback)) {
            return '';
        }
        return trim((string)$fallback);
    }
}
