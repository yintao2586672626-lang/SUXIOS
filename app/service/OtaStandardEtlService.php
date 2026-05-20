<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;
use think\facade\Db;

class OtaStandardEtlService
{
    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function buildDataset(array $filters = []): array
    {
        return $this->buildDatasetFromRows($this->fetchRows($filters));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public function buildDatasetFromRows(array $rows): array
    {
        $hotels = [];
        $platforms = [];
        $dailyFacts = [];
        $trafficFacts = [];
        $commentFacts = [];
        $rejectedRows = [];

        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) {
                $rejectedRows[] = ['index' => $index, 'reason' => 'row_not_array'];
                continue;
            }

            $raw = $this->sanitizeRawData($this->decodeJson($row['raw_data'] ?? []));
            $source = $this->platformKey($this->firstText($row, $raw, ['source', 'platform', 'ota_source', 'otaSource']));
            $date = $this->dateValue($row['data_date'] ?? $row['date'] ?? $raw['dataDate'] ?? $raw['date'] ?? '');
            $hotelId = trim((string)($row['hotel_id'] ?? $raw['hotelId'] ?? $raw['poiId'] ?? ''));
            $hotelName = trim((string)($row['hotel_name'] ?? $raw['hotelName'] ?? $raw['poiName'] ?? ''));

            $missing = [];
            if ($source === '') {
                $missing[] = 'source';
            }
            if ($hotelId === '') {
                $missing[] = 'hotel_id';
            }
            if ($date === '') {
                $missing[] = 'data_date';
            }
            if ($missing) {
                $rejectedRows[] = ['index' => $index, 'reason' => 'missing_required_fields', 'fields' => $missing];
                continue;
            }

            $systemHotelId = (int)($row['system_hotel_id'] ?? $raw['system_hotel_id'] ?? 0);
            $hotelKey = $systemHotelId > 0 ? 'system:' . $systemHotelId : $source . ':' . $hotelId;
            $dataType = $this->normalizeDataType((string)($row['data_type'] ?? $raw['data_type'] ?? 'business'));
            $platforms[$source] = [
                'platform_key' => $source,
                'platform_name' => $this->platformName($source),
            ];
            $hotels[$hotelKey] = [
                'hotel_key' => $hotelKey,
                'system_hotel_id' => $systemHotelId > 0 ? $systemHotelId : null,
                'ota_hotel_id' => $hotelId,
                'hotel_name' => $hotelName,
                'primary_platform' => $source,
            ];

            if ($dataType === 'traffic') {
                $trafficFacts[] = $this->trafficFact($row, $raw, $hotelKey, $source, $date);
                continue;
            }
            if ($dataType === 'review') {
                $commentFacts[] = $this->commentFact($row, $raw, $hotelKey, $source, $date);
                continue;
            }

            $dailyFacts[] = $this->dailyFact($row, $raw, $hotelKey, $source, $date, $dataType);
        }

        return [
            'status' => count($dailyFacts) + count($trafficFacts) + count($commentFacts) > 0 ? 'ready' : 'empty',
            'dim_hotel' => array_values($hotels),
            'dim_platform' => array_values($platforms),
            'fact_ota_daily' => $dailyFacts,
            'fact_ota_traffic' => $trafficFacts,
            'fact_ota_comment' => $commentFacts,
            'data_quality' => [
                'input_rows' => count($rows),
                'accepted_rows' => count($dailyFacts) + count($trafficFacts) + count($commentFacts),
                'rejected_rows' => $rejectedRows,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function fetchRows(array $filters): array
    {
        if (!$this->tableExists('online_daily_data')) {
            throw new RuntimeException('online_daily_data table does not exist', 422);
        }

        $columns = $this->tableColumns('online_daily_data');
        $fields = array_values(array_intersect([
            'id',
            'system_hotel_id',
            'hotel_id',
            'hotel_name',
            'data_date',
            'amount',
            'quantity',
            'book_order_num',
            'comment_score',
            'qunar_comment_score',
            'raw_data',
            'data_value',
            'source',
            'dimension',
            'data_type',
            'platform',
            'compare_type',
            'list_exposure',
            'detail_exposure',
            'flow_rate',
            'order_filling_num',
            'order_submit_num',
        ], array_keys($columns)));

        $query = Db::name('online_daily_data')->field($fields ?: '*');
        $sourceFilter = trim((string)($filters['source'] ?? $filters['platform'] ?? ''));
        if ($sourceFilter !== '' && isset($columns['source'])) {
            $query->whereIn('source', $this->sourceFilterValues($sourceFilter));
        }
        $dataTypeFilter = trim((string)($filters['data_type'] ?? ''));
        if ($dataTypeFilter !== '' && isset($columns['data_type'])) {
            $query->where('data_type', $this->normalizeDataType($dataTypeFilter));
        }
        $hotelIdFilter = trim((string)($filters['hotel_id'] ?? ''));
        if ($hotelIdFilter !== '' && isset($columns['hotel_id'])) {
            $query->where('hotel_id', $hotelIdFilter);
        }
        if (!empty($filters['system_hotel_id']) && isset($columns['system_hotel_id'])) {
            $query->where('system_hotel_id', (int)$filters['system_hotel_id']);
        }
        if (!empty($filters['start_date']) && isset($columns['data_date'])) {
            $startDate = $this->filterDateValue($filters['start_date'], 'start_date');
            $query->where('data_date', '>=', $startDate);
        }
        if (!empty($filters['end_date']) && isset($columns['data_date'])) {
            $endDate = $this->filterDateValue($filters['end_date'], 'end_date');
            $query->where('data_date', '<=', $endDate);
        }

        $limit = (int)($filters['limit'] ?? 1000);
        $limit = max(1, min(5000, $limit));
        return $query->order('data_date', 'desc')->order('id', 'desc')->limit($limit)->select()->toArray();
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function dailyFact(array $row, array $raw, string $hotelKey, string $source, string $date, string $dataType): array
    {
        $revenue = $this->firstNumber($row, $raw, ['amount', 'revenue', 'totalAmount', 'saleAmount']);
        $roomNights = $this->firstNumber($row, $raw, ['quantity', 'room_nights', 'roomNights', 'checkOutQuantity']);
        $orders = (int)round($this->firstNumber($row, $raw, ['book_order_num', 'bookOrderNum', 'orderCount', 'orderNum', 'orders']));
        $cancelOrders = $this->nullableNumber($row, $raw, ['cancel_order_num', 'cancelOrderNum', 'cancel_orders', 'cancelOrders']);
        $ourPrice = $this->nullableNumber($row, $raw, ['our_price', 'ourPrice', 'hotel_price', 'hotelPrice']);
        $competitorPrice = $this->nullableNumber($row, $raw, ['competitor_price', 'competitorPrice', 'market_price', 'marketPrice']);

        return [
            'date_key' => $date,
            'hotel_key' => $hotelKey,
            'platform_key' => $source,
            'data_type' => $dataType,
            'dimension' => (string)($row['dimension'] ?? $raw['dimension'] ?? ''),
            'revenue' => round($revenue, 2),
            'room_nights' => round($roomNights, 2),
            'order_count' => $orders,
            'adr' => $roomNights > 0 ? round($revenue / $roomNights, 2) : null,
            'comment_score' => $this->nullableNumber($row, $raw, ['comment_score', 'commentScore', 'score']),
            'data_value' => $this->nullableNumber($row, $raw, ['data_value', 'dataValue']),
            'cancel_order_num' => $cancelOrders,
            'our_price' => $ourPrice,
            'competitor_price' => $competitorPrice,
            'price_gap' => $ourPrice !== null && $competitorPrice !== null ? round($ourPrice - $competitorPrice, 2) : null,
            'raw_data' => $raw,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function trafficFact(array $row, array $raw, string $hotelKey, string $source, string $date): array
    {
        $orderFilling = $this->firstNumber($row, $raw, ['order_filling_num', 'orderFillingNum', 'click_count', 'clickCount']);
        $orderSubmit = $this->firstNumber($row, $raw, ['order_submit_num', 'orderSubmitNum', 'submit_users', 'submitUsers']);

        return [
            'date_key' => $date,
            'hotel_key' => $hotelKey,
            'platform_key' => $source,
            'compare_type' => (string)($row['compare_type'] ?? $raw['compare_type'] ?? 'self'),
            'list_exposure' => (int)round($this->firstNumber($row, $raw, ['list_exposure', 'listExposure', 'exposure_count', 'exposureCount'])),
            'detail_exposure' => (int)round($this->firstNumber($row, $raw, ['detail_exposure', 'detailExposure', 'page_views', 'pageViews'])),
            'flow_rate' => round($this->firstNumber($row, $raw, ['flow_rate', 'flowRate', 'conversion_rate', 'conversionRate']), 2),
            'order_filling_num' => (int)round($orderFilling),
            'order_submit_num' => (int)round($orderSubmit),
            'submit_rate' => $orderFilling > 0 ? round($orderSubmit / $orderFilling * 100, 2) : null,
            'raw_data' => $raw,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function commentFact(array $row, array $raw, string $hotelKey, string $source, string $date): array
    {
        $score = $this->nullableNumber($row, $raw, ['comment_score', 'commentScore', 'score', 'data_value', 'dataValue']);
        $sentiment = trim((string)($raw['sentiment'] ?? ''));
        if ($sentiment === '') {
            $sentiment = $score !== null && $score > 0 && $score < 4 ? 'negative' : 'neutral';
        }

        return [
            'date_key' => $date,
            'hotel_key' => $hotelKey,
            'platform_key' => $source,
            'review_id' => (string)($raw['review_id'] ?? $raw['reviewId'] ?? $raw['commentId'] ?? ''),
            'score' => $score,
            'sentiment' => $sentiment,
            'content' => mb_substr((string)($raw['content'] ?? $raw['comment_text'] ?? $raw['review_text'] ?? ''), 0, 500),
            'raw_data' => $raw,
        ];
    }

    private function platformKey(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }
        if (str_contains($value, 'meituan') || str_contains($value, 'dianping')) {
            return 'meituan';
        }
        if (str_contains($value, 'ctrip') || str_contains($value, 'trip.com')) {
            return 'ctrip';
        }
        if (str_contains($value, 'qunar')) {
            return 'qunar';
        }
        return in_array($value, ['ctrip', 'meituan', 'qunar'], true) ? $value : '';
    }

    private function platformName(string $source): string
    {
        return match ($source) {
            'ctrip' => 'Ctrip',
            'meituan' => 'Meituan',
            'qunar' => 'Qunar',
            default => $source,
        };
    }

    /**
     * @return array<int, string>
     */
    private function sourceFilterValues(string $value): array
    {
        $value = strtolower(trim($value));
        $sourceKey = $this->platformKey($value);
        $values = [$value];

        if ($sourceKey === 'meituan') {
            $values = array_merge($values, ['meituan', 'meituan_rank', 'meituan_business', 'meituan_browser_profile']);
        } elseif ($sourceKey === 'ctrip') {
            $values = array_merge($values, ['ctrip', 'ctrip_business', 'ctrip_manual_overview', 'ctrip_browser_profile']);
        } elseif ($sourceKey === 'qunar') {
            $values = array_merge($values, ['qunar']);
        }

        return array_values(array_unique(array_filter($values, static fn(string $item): bool => $item !== '')));
    }

    private function normalizeDataType(string $value): string
    {
        $value = strtolower(trim($value));
        if (in_array($value, ['traffic', 'flow'], true)) {
            return 'traffic';
        }
        if (in_array($value, ['review', 'reviews', 'comment', 'comments'], true)) {
            return 'review';
        }
        return $value !== '' ? $value : 'business';
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @param array<int, string> $keys
     */
    private function firstNumber(array $row, array $raw, array $keys): float
    {
        return (float)($this->nullableNumber($row, $raw, $keys) ?? 0.0);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @param array<int, string> $keys
     */
    private function nullableNumber(array $row, array $raw, array $keys): ?float
    {
        foreach ($keys as $key) {
            foreach ([$row, $raw] as $source) {
                if (array_key_exists($key, $source) && $source[$key] !== '' && $source[$key] !== null) {
                    $value = is_string($source[$key]) ? str_replace(['%', ','], '', trim($source[$key])) : $source[$key];
                    if (is_numeric($value)) {
                        return (float)$value;
                    }
                }
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @param array<int, string> $keys
     */
    private function firstText(array $row, array $raw, array $keys): string
    {
        foreach ($keys as $key) {
            foreach ([$row, $raw] as $source) {
                $value = $source[$key] ?? null;
                if ($value !== null && trim((string)$value) !== '') {
                    return trim((string)$value);
                }
            }
        }
        return '';
    }

    private function dateValue(mixed $value): string
    {
        $text = trim((string)$value);
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $text) === 1 ? substr($text, 0, 10) : '';
    }

    private function filterDateValue(mixed $value, string $field): string
    {
        $date = $this->dateValue($value);
        if ($date === '') {
            throw new RuntimeException("Invalid {$field}, expected YYYY-MM-DD", 422);
        }
        return $date;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function sanitizeRawData(array $raw): array
    {
        foreach ($raw as $key => $value) {
            if (preg_match('/cookie|token|authorization|mtgsig|password|secret/i', (string)$key)) {
                unset($raw[$key]);
                continue;
            }
            if (is_array($value)) {
                $raw[$key] = $this->sanitizeRawData($value);
            }
        }
        return $raw;
    }

    private function tableExists(string $table): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return false;
        }
        return !empty(Db::query("SHOW TABLES LIKE '" . addslashes($table) . "'"));
    }

    /**
     * @return array<string, bool>
     */
    private function tableColumns(string $table): array
    {
        $columns = [];
        foreach (Db::query('SHOW COLUMNS FROM `' . $table . '`') as $row) {
            if (!empty($row['Field'])) {
                $columns[(string)$row['Field']] = true;
            }
        }
        return $columns;
    }
}
