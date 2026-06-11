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

    public static function filterFields(array $data): array
    {
        $columns = self::getColumns();
        if (isset($columns['tenant_id']) && !isset($data['tenant_id'])) {
            $data['tenant_id'] = self::tenantIdForSystemHotel($data['system_hotel_id'] ?? null);
        }
        return array_intersect_key($data, $columns);
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

        foreach (['amount', 'quantity', 'book_order_num', 'data_value'] as $field) {
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
        if (isset($columns['tenant_id']) && !isset($data['tenant_id'])) {
            $data['tenant_id'] = self::tenantIdForSystemHotel($data['system_hotel_id'] ?? null);
        }
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
            $snapshotBucket = date('YmdH', strtotime($snapshotTime) ?: time());
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

    public function parseAndSaveTrafficData($responseData, $startDate, $endDate, string $source, ?int $systemHotelId = null, ?string $platform = null): int
    {
        try {
            if (in_array($source, ['ctrip', 'qunar'], true)) {
                return $this->parseAndSaveCtripTrafficData($responseData, (string)$startDate, $source, $systemHotelId, $platform);
            }

            return $this->parseAndSaveGenericTrafficData($responseData, (string)$startDate, $source, $systemHotelId);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function parseAndSaveCtripTrafficData($responseData, string $startDate, string $source, ?int $systemHotelId, ?string $platform): int
    {
        $dataList = OnlineTrafficDataExtractionService::extractCtripTrafficRows($responseData);
        if (empty($dataList)) {
            return 0;
        }

        $savedCount = 0;
        $platform = $platform ?: ($source === 'qunar' ? 'Qunar' : 'Ctrip');
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
            if (!is_numeric($hotelId)) {
                if ($isCompetitor) {
                    $hotelId = -1;
                } elseif ($systemHotelId !== null) {
                    $hotelId = $systemHotelId;
                } else {
                    continue;
                }
            }
            $hotelId = (int)$hotelId;
            if ($hotelId !== -1 && $hotelId <= 0) {
                continue;
            }

            $itemDate = $item['date'] ?? $item['dataDate'] ?? $item['statDate'] ?? $item['stat_date'] ?? $item['data_date'] ?? $item['reportDate'] ?? $item['day'] ?? $startDate;
            if (!$itemDate || strtotime((string)$itemDate) === false) {
                continue;
            }
            $itemDate = date('Y-m-d', strtotime((string)$itemDate));
            $compareType = $isCompetitor || $hotelId < 0 ? 'competitor_avg' : 'self';
            $hotelName = (string)($item['hotelName'] ?? $item['hotel_name'] ?? $item['HotelName'] ?? $item['name'] ?? ($compareType === 'self' ? '本店' : '竞争圈'));
            $listExposure = (int)CtripTrafficDisplayService::readTrafficNumber($item, ['listExposure', 'list_exposure', 'exposure', 'exposureCount', 'impressions', 'showCount', 'PV', 'pv', 'pageView', 'pageViews', 'page_view'], 0.0);
            $detailExposure = (int)CtripTrafficDisplayService::readTrafficNumber($item, ['detailExposure', 'detail_exposure', 'detailVisitors', 'detailUv', 'visitorCount', 'UV', 'uv', 'uniqueVisitors', 'unique_visitors', 'views'], 0.0);
            $flowRate = round(CtripTrafficDisplayService::normalizeTrafficPercent(CtripTrafficDisplayService::readTrafficNumber($item, ['flowRate', 'flow_rate', 'conversionRate', 'conversion_rate', 'convertionRate', 'convertRate', 'transforRate', 'transferRate', 'transRate', 'cvr'], $listExposure > 0 ? $detailExposure / $listExposure * 100 : 0.0)), 2);
            $orderFillingNum = (int)CtripTrafficDisplayService::readTrafficNumber($item, ['orderFillingNum', 'order_filling_num', 'orderVisitors', 'clickCount', 'click_count', 'clickNum', 'clicks'], 0.0);
            $orderSubmitNum = (int)CtripTrafficDisplayService::readTrafficNumber($item, ['orderSubmitNum', 'order_submit_num', 'submitUsers', 'submitNum', 'orderCount', 'order_count', 'orderNum', 'bookOrderNum', 'dealNum', 'orders'], 0.0);
            $columns = self::getColumns();
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
            $data = self::filterFields(self::applyValidationFields([
                'hotel_id' => (string)$hotelId,
                'hotel_name' => $hotelName,
                'system_hotel_id' => $systemHotelId,
                'data_date' => $itemDate,
                'amount' => 0,
                'quantity' => 0,
                'book_order_num' => 0,
                'comment_score' => 0,
                'qunar_comment_score' => 0,
                'data_value' => $listExposure,
                'source' => $source,
                'data_type' => 'traffic',
                'dimension' => $platform . ':' . $compareType,
                'platform' => $platform,
                'compare_type' => $compareType,
                'list_exposure' => $listExposure,
                'detail_exposure' => $detailExposure,
                'flow_rate' => $flowRate,
                'order_filling_num' => $orderFillingNum,
                'order_submit_num' => $orderSubmitNum,
                'raw_data' => json_encode($item, JSON_UNESCAPED_UNICODE),
            ]));

            if ($exists) {
                Db::name('online_daily_data')->where('id', $exists['id'])->update($data);
            } else {
                Db::name('online_daily_data')->insert($data);
            }
            $savedCount++;
        }

        return $savedCount;
    }

    private function parseAndSaveGenericTrafficData($responseData, string $startDate, string $source, ?int $systemHotelId): int
    {
        $dataList = $this->resolveGenericTrafficDataList($responseData);
        if (empty($dataList)) {
            return 0;
        }

        $savedCount = 0;
        $dataDate = $startDate ?: date('Y-m-d', strtotime('-1 day'));

        foreach ($dataList as $item) {
            if (!is_array($item)) {
                continue;
            }

            $hotelId = $item['hotelId'] ?? $item['hotel_id'] ?? $item['HotelId'] ?? $item['hotelID'] ?? $item['poiId'] ?? $item['poi_id'] ?? null;
            $hotelName = $item['hotelName'] ?? $item['hotel_name'] ?? $item['HotelName'] ?? $item['name'] ?? $item['poiName'] ?? $item['poi_name'] ?? '';

            if (empty($hotelId) && empty($hotelName)) {
                continue;
            }

            $trafficValue = OnlineTrafficDataExtractionService::extractTrafficValue($item);
            $itemDate = $item['dataDate'] ?? $item['date'] ?? $item['statDate'] ?? $item['stat_date'] ?? $item['data_date'] ?? $dataDate;
            $dimension = $item['metric'] ?? $item['metricName'] ?? $item['dimension'] ?? $item['_metric'] ?? 'traffic';
            $columns = self::getColumns();
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

            $data = self::filterFields(self::applyValidationFields([
                'hotel_id' => $hotelId ? (string)$hotelId : '',
                'hotel_name' => $hotelName,
                'system_hotel_id' => $systemHotelId,
                'data_date' => $itemDate,
                'amount' => 0,
                'quantity' => 0,
                'book_order_num' => 0,
                'comment_score' => 0,
                'qunar_comment_score' => 0,
                'data_value' => $trafficValue ?? 0,
                'source' => $source,
                'data_type' => 'traffic',
                'dimension' => $dimension ?: 'traffic',
                'raw_data' => json_encode($item, JSON_UNESCAPED_UNICODE),
            ]));

            if ($exists) {
                Db::name('online_daily_data')
                    ->where('id', $exists['id'])
                    ->update($data);
            } else {
                Db::name('online_daily_data')->insert($data);
            }
            $savedCount++;
        }

        return $savedCount;
    }

    private function resolveGenericTrafficDataList($responseData): array
    {
        if (isset($responseData['data']['list']) && is_array($responseData['data']['list'])) {
            return $responseData['data']['list'];
        }
        if (isset($responseData['data']['hotelList']) && is_array($responseData['data']['hotelList'])) {
            return $responseData['data']['hotelList'];
        }
        if (isset($responseData['data']['records']) && is_array($responseData['data']['records'])) {
            return $responseData['data']['records'];
        }
        if (isset($responseData['data']['rows']) && is_array($responseData['data']['rows'])) {
            return $responseData['data']['rows'];
        }
        if (isset($responseData['data']) && is_array($responseData['data']) && isset($responseData['data'][0])) {
            return $responseData['data'];
        }
        if (isset($responseData['list']) && is_array($responseData['list'])) {
            return $responseData['list'];
        }

        return OnlineTrafficDataExtractionService::extractGenericTrafficRows($responseData);
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

    private static function tenantIdForSystemHotel($systemHotelId): ?int
    {
        if ($systemHotelId === null || $systemHotelId === '' || !is_numeric($systemHotelId) || (int)$systemHotelId <= 0) {
            return null;
        }

        return (int)$systemHotelId;
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
