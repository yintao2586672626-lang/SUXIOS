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
            'room_revenue',
            'gross_revenue',
            'net_revenue',
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
            'available_rooms',
            'available_room_nights',
            'salable_rooms',
            'salable_room_nights',
            'total_rooms_count',
            'rooms_total',
            'occupied_rooms',
            'occupied_room_nights',
            'commission_amount',
            'commission',
            'commission_rate',
            'ota_commission',
            'ota_commission_rate',
            'cancel_order_num',
            'cancel_room_nights',
            'cancel_rate',
            'our_price',
            'competitor_price',
            'price_gap',
            'price_difference',
            'booking_date',
            'order_date',
            'checkin_date',
            'checkout_date',
            'lead_time_days',
            'booking_window',
            'update_time',
            'updated_at',
            'create_time',
            'created_at',
            'status',
            'save_status',
            'validation_status',
            'validation_flags',
            'error_info',
            'failure_reason',
            'failed_reason',
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
        $grossRevenue = $this->firstNumber($row, $raw, ['amount', 'gross_revenue', 'grossRevenue', 'revenue', 'totalAmount', 'saleAmount', 'order_amount', 'orderAmount']);
        $roomRevenue = $this->nullableNumber($row, $raw, ['room_revenue', 'roomRevenue', 'room_amount', 'roomAmount']) ?? $grossRevenue;
        $roomNights = $this->firstNumber($row, $raw, ['quantity', 'room_nights', 'roomNights', 'checkOutQuantity']);
        $orders = (int)round($this->firstNumber($row, $raw, ['book_order_num', 'bookOrderNum', 'orderCount', 'orderNum', 'orders']));
        $cancelOrders = $this->nullableNumber($row, $raw, ['cancel_order_num', 'cancelOrderNum', 'cancel_orders', 'cancelOrders']);
        $cancelRoomNights = $this->nullableNumber($row, $raw, ['cancel_room_nights', 'cancelRoomNights', 'cancelled_room_nights', 'cancelledRoomNights']);
        $cancelRate = $this->nullablePercent($row, $raw, ['cancel_rate', 'cancelRate', 'cancellation_rate', 'cancellationRate']);
        $availableRoomNights = $this->nullableNumber($row, $raw, [
            'available_room_nights',
            'availableRoomNights',
            'salable_room_nights',
            'salableRoomNights',
            'available_rooms',
            'availableRooms',
            'salable_rooms',
            'salableRooms',
            'total_rooms_count',
            'totalRoomsCount',
            'rooms_total',
            'roomsTotal',
        ]);
        $occupiedRoomNights = $this->nullableNumber($row, $raw, [
            'occupied_room_nights',
            'occupiedRoomNights',
            'occupied_rooms',
            'occupiedRooms',
            'rooms_sold',
            'roomsSold',
        ]) ?? ($roomNights > 0 ? $roomNights : null);
        $commissionRate = $this->nullablePercent($row, $raw, ['commission_rate', 'commissionRate', 'ota_commission_rate', 'otaCommissionRate']);
        $directCommissionAmount = $this->nullableNumber($row, $raw, ['commission_amount', 'commissionAmount', 'commission', 'ota_commission', 'otaCommission', 'channel_commission', 'channelCommission']);
        $commissionAmount = $directCommissionAmount;
        $commissionAmountBasis = $directCommissionAmount !== null ? 'direct' : null;
        if ($commissionAmount === null && $commissionRate !== null) {
            $commissionAmount = round($grossRevenue * $commissionRate / 100, 2);
            $commissionAmountBasis = 'derived_from_commission_rate';
        }
        $directNetRevenue = $this->nullableNumber($row, $raw, ['net_revenue', 'netRevenue', 'net_amount', 'netAmount', 'after_commission_revenue', 'afterCommissionRevenue', 'settlement_amount', 'settlementAmount']);
        $netRevenue = $directNetRevenue;
        $netRevenueBasis = $directNetRevenue !== null ? 'direct' : null;
        if ($netRevenue === null && $commissionAmount !== null) {
            $netRevenue = round($grossRevenue - $commissionAmount, 2);
            $netRevenueBasis = 'derived_from_commission_amount';
        }
        $ourPrice = $this->nullableNumber($row, $raw, ['our_price', 'ourPrice', 'hotel_price', 'hotelPrice']);
        $competitorPrice = $this->nullableNumber($row, $raw, ['competitor_price', 'competitorPrice', 'market_price', 'marketPrice']);
        $priceGap = $this->nullableNumber($row, $raw, ['price_gap', 'priceGap', 'price_difference', 'priceDifference']);
        if ($priceGap === null && $ourPrice !== null && $competitorPrice !== null) {
            $priceGap = round($ourPrice - $competitorPrice, 2);
        }

        return [
            'date_key' => $date,
            'hotel_key' => $hotelKey,
            'platform_key' => $source,
            'data_type' => $dataType,
            'dimension' => (string)($row['dimension'] ?? $raw['dimension'] ?? ''),
            'metric_scope' => 'ota_channel',
            'calculation_basis' => 'ota_daily_standard_fact',
            'revenue' => round($grossRevenue, 2),
            'gross_revenue' => round($grossRevenue, 2),
            'room_revenue' => round($roomRevenue, 2),
            'net_revenue' => $netRevenue !== null ? round($netRevenue, 2) : null,
            'commission_amount' => $commissionAmount !== null ? round($commissionAmount, 2) : null,
            'commission_rate' => $commissionRate !== null ? round($commissionRate, 2) : null,
            'net_revenue_basis' => $netRevenueBasis,
            'commission_amount_basis' => $commissionAmountBasis,
            'room_nights' => round($roomNights, 2),
            'available_room_nights' => $availableRoomNights !== null ? round($availableRoomNights, 2) : null,
            'occupied_room_nights' => $occupiedRoomNights !== null ? round($occupiedRoomNights, 2) : null,
            'order_count' => $orders,
            'adr' => $roomNights > 0 ? round($roomRevenue / $roomNights, 2) : null,
            'occ' => $availableRoomNights !== null && $availableRoomNights > 0 && $occupiedRoomNights !== null
                ? round($occupiedRoomNights / $availableRoomNights * 100, 2)
                : null,
            'revpar' => $availableRoomNights !== null && $availableRoomNights > 0
                ? round($roomRevenue / $availableRoomNights, 2)
                : null,
            'net_revpar' => $availableRoomNights !== null && $availableRoomNights > 0 && $netRevenue !== null
                ? round($netRevenue / $availableRoomNights, 2)
                : null,
            'lead_time_days' => $this->leadTimeDays($row, $raw),
            'comment_score' => $this->nullableNumber($row, $raw, ['comment_score', 'commentScore', 'score']),
            'data_value' => $this->nullableNumber($row, $raw, ['data_value', 'dataValue']),
            'cancel_order_num' => $cancelOrders,
            'cancel_room_nights' => $cancelRoomNights,
            'cancel_rate' => $cancelRate,
            'our_price' => $ourPrice,
            'competitor_price' => $competitorPrice,
            'price_gap' => $priceGap,
            'price_gap_rate' => $priceGap !== null && $competitorPrice !== null && $competitorPrice > 0
                ? round($priceGap / $competitorPrice * 100, 2)
                : null,
            'raw_data' => $raw,
            'source_trace' => $this->rowTrace($row, $hotelKey, $source, $dataType, $date),
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
            'source_trace' => $this->rowTrace($row, $hotelKey, $source, 'traffic', $date),
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
            'source_trace' => $this->rowTrace($row, $hotelKey, $source, 'review', $date),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function rowTrace(array $row, string $hotelKey, string $source, string $dataType, string $date): array
    {
        $failureReasons = [];
        $status = strtolower(trim((string)($row['status'] ?? $row['save_status'] ?? '')));
        if (in_array($status, ['failed', 'fail', 'error'], true)) {
            $failureReasons[] = 'row_status_' . $status;
        }

        $validationStatus = strtolower(trim((string)($row['validation_status'] ?? '')));
        if ($validationStatus === 'abnormal') {
            $failureReasons[] = 'validation_status_abnormal';
            foreach ($this->validationFlagReasons($row['validation_flags'] ?? []) as $reason) {
                $failureReasons[] = $reason;
            }
        }

        foreach (['error_info', 'failure_reason', 'failed_reason'] as $field) {
            $reason = trim((string)($row[$field] ?? ''));
            if ($reason !== '') {
                $failureReasons[] = $field . ':' . mb_substr($reason, 0, 120);
            }
        }

        return [
            'table' => 'online_daily_data',
            'row_id' => array_key_exists('id', $row) ? (is_numeric($row['id']) ? (int)$row['id'] : (string)$row['id']) : null,
            'hotel_key' => $hotelKey,
            'platform' => $source,
            'data_type' => $dataType,
            'date_key' => $date,
            'updated_at' => $this->traceTimestamp($row),
            'saved_success' => empty($failureReasons),
            'failure_reasons' => array_values(array_unique($failureReasons)),
        ];
    }

    /**
     * @param mixed $flags
     * @return array<int, string>
     */
    private function validationFlagReasons(mixed $flags): array
    {
        $decoded = is_string($flags) ? json_decode($flags, true) : $flags;
        if (!is_array($decoded)) {
            return [];
        }

        $reasons = [];
        foreach ($decoded as $flag) {
            if (!is_array($flag)) {
                continue;
            }
            $code = trim((string)($flag['code'] ?? $flag['field'] ?? ''));
            if ($code !== '') {
                $reasons[] = 'validation:' . $code;
            }
        }
        return $reasons;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function traceTimestamp(array $row): ?string
    {
        foreach (['update_time', 'updated_at', 'create_time', 'created_at'] as $field) {
            $value = trim((string)($row[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return null;
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
    private function nullablePercent(array $row, array $raw, array $keys): ?float
    {
        $value = $this->nullableNumber($row, $raw, $keys);
        if ($value === null) {
            return null;
        }
        if ($value < 0) {
            return null;
        }
        $percent = $value > 0 && $value <= 1 ? $value * 100 : $value;
        return $percent <= 100 ? $percent : null;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     */
    private function leadTimeDays(array $row, array $raw): ?int
    {
        $explicit = $this->nullableNumber($row, $raw, ['lead_time_days', 'leadTimeDays', 'booking_window', 'bookingWindow']);
        if ($explicit !== null) {
            $days = (int)round($explicit);
            return $days >= 0 ? $days : null;
        }

        $bookingDate = $this->dateValue($this->firstText($row, $raw, ['booking_date', 'bookingDate', 'order_date', 'orderDate', 'create_date', 'createDate']));
        $checkinDate = $this->dateValue($this->firstText($row, $raw, ['checkin_date', 'checkinDate', 'arrival_date', 'arrivalDate', 'stay_date', 'stayDate']));
        if ($bookingDate === '' || $checkinDate === '') {
            return null;
        }

        $booking = new \DateTimeImmutable($bookingDate);
        $checkin = new \DateTimeImmutable($checkinDate);
        $days = (int)$booking->diff($checkin)->format('%r%a');
        return $days >= 0 ? $days : null;
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
