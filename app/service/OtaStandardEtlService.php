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
        $inputRowCount = count($rows);
        [$rows, $semanticRejectedRows] = $this->resolveLegacyMeituanBusinessSemantics($rows);
        [$rows, $supersededPeriodRows] = $this->selectCanonicalPeriodRows($rows);
        $hotels = [];
        $platforms = [];
        $dailyFacts = [];
        $trafficFacts = [];
        $advertisingFacts = [];
        $qualityFacts = [];
        $searchKeywordFacts = [];
        $peerRankFacts = [];
        $trafficAnalysisFacts = [];
        $trafficForecastFacts = [];
        $orderFlowFacts = [];
        $commentFacts = [];
        $rejectedRows = $semanticRejectedRows;

        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) {
                $rejectedRows[] = ['index' => $index, 'reason' => 'row_not_array'];
                continue;
            }

            $decodedRaw = $this->decodeJson($row['raw_data'] ?? []);
            $dataType = $this->normalizeDataType((string)($row['data_type'] ?? $decodedRaw['data_type'] ?? 'business'));
            $raw = $this->sanitizeRawData($decodedRaw, $dataType === 'order');
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
            if ($dataType === 'advertising') {
                $advertisingFacts[] = $this->advertisingFact($row, $raw, $hotelKey, $source, $date);
                continue;
            }
            if ($dataType === 'quality') {
                $qualityFacts[] = $this->qualityFact($row, $raw, $hotelKey, $source, $date);
                continue;
            }
            if ($dataType === 'search_keyword') {
                $searchKeywordFacts[] = $this->searchKeywordFact($row, $raw, $hotelKey, $source, $date);
                continue;
            }
            if ($dataType === 'peer_rank') {
                $peerRankFacts[] = $this->peerRankFact($row, $raw, $hotelKey, $source, $date);
                continue;
            }
            if ($dataType === 'traffic_analysis') {
                $trafficAnalysisFacts[] = $this->trafficAnalysisFact($row, $raw, $hotelKey, $source, $date);
                continue;
            }
            if ($dataType === 'traffic_forecast') {
                $trafficForecastFacts[] = $this->trafficForecastFact($row, $raw, $hotelKey, $source, $date);
                continue;
            }
            if ($dataType === 'order_flow') {
                $orderFlowFacts[] = $this->orderFlowFact($row, $raw, $hotelKey, $source, $date);
                continue;
            }
            if ($dataType === 'review') {
                $commentFacts[] = $this->commentFact($row, $raw, $hotelKey, $source, $date);
                continue;
            }
            if (!$this->isSelfRevenueFact($row, $raw, $dataType)) {
                $rejectedRows[] = [
                    'index' => $index,
                    'reason' => 'non_self_competitor_scope',
                    'data_type' => $dataType,
                    'compare_type' => strtolower($this->firstText($row, $raw, ['compare_type', 'compareType'])),
                ];
                continue;
            }
            $dailyFacts[] = $this->dailyFact($row, $raw, $hotelKey, $source, $date, $dataType);
        }

        $acceptedCount = count($dailyFacts)
            + count($trafficFacts)
            + count($advertisingFacts)
            + count($qualityFacts)
            + count($searchKeywordFacts)
            + count($peerRankFacts)
            + count($trafficAnalysisFacts)
            + count($trafficForecastFacts)
            + count($orderFlowFacts)
            + count($commentFacts);
        return [
            'status' => $acceptedCount > 0 ? 'ready' : 'empty',
            'dim_hotel' => array_values($hotels),
            'dim_platform' => array_values($platforms),
            'fact_ota_daily' => $dailyFacts,
            'fact_ota_traffic' => $trafficFacts,
            'fact_ota_advertising' => $advertisingFacts,
            'fact_ota_quality' => $qualityFacts,
            'fact_ota_search_keyword' => $searchKeywordFacts,
            'fact_ota_peer_rank' => $peerRankFacts,
            'fact_ota_traffic_analysis' => $trafficAnalysisFacts,
            'fact_ota_traffic_forecast' => $trafficForecastFacts,
            'fact_ota_order_flow' => $orderFlowFacts,
            'fact_ota_comment' => $commentFacts,
            'data_quality' => [
                'source_input_rows' => $inputRowCount,
                'input_rows' => count($rows),
                'canonical_rows' => count($rows),
                'superseded_period_rows' => $supersededPeriodRows,
                'accepted_rows' => $acceptedCount,
                'rejected_rows' => $rejectedRows,
            ],
        ];
    }

    /**
     * Historical Meituan rank rows were stored as business rows. Reclassify
     * only rows with an explicit rank value; reject rank-shaped conflicts so
     * their amount fields cannot become OTA revenue.
     *
     * @param array<int, mixed> $rows
     * @return array{0:array<int, mixed>,1:array<int, array<string, mixed>>}
     */
    private function resolveLegacyMeituanBusinessSemantics(array $rows): array
    {
        $resolvedRows = [];
        $rejectedRows = [];
        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) {
                $resolvedRows[] = $row;
                continue;
            }

            $raw = $this->decodeJson($row['raw_data'] ?? []);
            $dataType = $this->normalizeDataType((string)($row['data_type'] ?? $raw['data_type'] ?? 'business'));
            $source = $this->platformKey($this->firstText($row, $raw, ['source', 'platform', 'ota_source', 'otaSource']));
            if ($source !== 'meituan' || $dataType !== 'business') {
                $resolvedRows[] = $row;
                continue;
            }

            $disposition = $this->legacyMeituanBusinessRankDisposition($row, $raw);
            if ($disposition === '') {
                $resolvedRows[] = $row;
                continue;
            }
            if ($disposition === 'peer_rank') {
                $row['data_type'] = 'peer_rank';
                $resolvedRows[] = $row;
                continue;
            }

            $rejectedRows[] = [
                'index' => $index,
                'reason' => 'semantic_type_conflict',
                'declared_data_type' => 'business',
                'detected_semantics' => 'peer_rank',
            ];
        }

        return [$resolvedRows, $rejectedRows];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     */
    private function legacyMeituanBusinessRankDisposition(array $row, array $raw): string
    {
        $detail = $this->rawDetail($raw);
        $dimension = strtolower($this->firstText($row, $detail, ['dimension', 'dimName', '_dimName', 'metricName', 'aiMetricName']));
        $rankType = $this->firstText($row, $detail, ['rank_type', 'rankType', 'rankListType']);
        $aiMetricName = strtoupper($this->firstText($row, $detail, ['aiMetricName', 'ai_metric_name']));
        $endpoint = strtolower($this->firstText($row, $detail, ['url', 'request_url', 'requestUrl', 'endpoint', 'api_url', 'apiUrl', 'source_url', 'sourceUrl']));
        $compareType = strtolower($this->firstText($row, $detail, ['compare_type', 'compareType']));
        $rank = $this->nullableNumber($row, $detail, ['rank', 'rank_no', 'rankNo', 'currentRank', 'sort']);
        $peerIdentity = $this->firstText($row, $detail, ['poiName', 'peerPoiId', 'peer_poi_id', 'poiId', 'poi_id']);
        $hasRankSignal = str_starts_with($dimension, 'peer_rank')
            || str_contains($dimension, '榜')
            || $rankType !== ''
            || str_starts_with($aiMetricName, 'P_RZ')
            || str_starts_with($aiMetricName, 'P_XS')
            || str_starts_with($aiMetricName, 'P_LL')
            || str_starts_with($aiMetricName, 'P_ZH')
            || str_contains($endpoint, '/peer/rank')
            || str_contains($endpoint, 'peerrank')
            || ($rank !== null && $peerIdentity !== '')
            || in_array($compareType, ['competitor', 'competitor_avg', 'peer'], true);
        if (!$hasRankSignal) {
            return '';
        }

        return $rank !== null && $rank > 0 ? 'peer_rank' : 'conflict';
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
            'readback_verified',
            'readback_verified_at',
            'error_info',
            'failure_reason',
            'failed_reason',
            'data_source_id',
            'sync_task_id',
            'ingestion_method',
            'source_trace_id',
            'data_period',
            'collected_at',
            'snapshot_time',
            'snapshot_bucket',
            'is_final',
        ], array_keys($columns)));

        $query = Db::name('online_daily_data')->field($fields ?: '*');
        if (isset($columns['readback_verified'])) {
            $query->where('readback_verified', 1);
        }
        if (isset($columns['validation_status'])) {
            $blocked = OnlineDataTrustStatusService::quotedSqlList(OnlineDataTrustStatusService::blockingValidationStatuses());
            $query->whereRaw("(`validation_status` IS NULL OR LOWER(TRIM(`validation_status`)) NOT IN ({$blocked}))");
        }
        if (isset($columns['status'])) {
            $blocked = OnlineDataTrustStatusService::quotedSqlList(OnlineDataTrustStatusService::blockingRowStatuses());
            $query->whereRaw("(`status` IS NULL OR LOWER(TRIM(`status`)) NOT IN ({$blocked}))");
        }
        $this->applySystemHotelScopeFilter($query, $filters, $columns);
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

        $pageSize = (int)($filters['limit'] ?? 1000);
        $pageSize = max(1, min(5000, $pageSize));
        $maxRows = (int)($filters['max_rows'] ?? 100000);
        $maxRows = max($pageSize, min(250000, $maxRows));
        $rows = [];
        $offset = 0;
        while (true) {
            $batch = (clone $query)
                ->order('data_date', 'desc')
                ->order('id', 'desc')
                ->limit($offset, $pageSize)
                ->select()
                ->toArray();
            if ($batch === []) {
                break;
            }
            if (count($rows) + count($batch) > $maxRows) {
                throw new RuntimeException(
                    'OTA dataset exceeds the safe row window; narrow the hotel/date/platform scope instead of using truncated metrics.',
                    422
                );
            }
            $rows = array_merge($rows, $batch);
            if (count($batch) < $pageSize) {
                break;
            }
            $offset += $pageSize;
        }

        return $rows;
    }

    /**
     * @param object $query
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $columns
     */
    private function applySystemHotelScopeFilter(object $query, array $filters, array $columns): void
    {
        $rawIds = $filters['permitted_hotel_ids'] ?? [];
        if (!is_array($rawIds)) {
            return;
        }
        $hotelIds = array_values(array_unique(array_filter(
            array_map('intval', $rawIds),
            static fn(int $hotelId): bool => $hotelId > 0
        )));
        sort($hotelIds);
        if ($hotelIds === []) {
            return;
        }
        if (!isset($columns['system_hotel_id'])) {
            throw new RuntimeException('system_hotel_id column is required for permitted hotel scope', 422);
        }
        $query->whereIn('system_hotel_id', $hotelIds);
    }

    /**
     * Keep one cumulative snapshot per business grain. Final historical rows win;
     * otherwise the latest realtime snapshot is used. Only event rows with a
     * stable business event ID bypass snapshot canonicalization.
     *
     * @param array<int, mixed> $rows
     * @return array{0:array<int, mixed>,1:int}
     */
    private function selectCanonicalPeriodRows(array $rows): array
    {
        $grouped = [];
        $selected = [];
        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) {
                $selected[$index] = $row;
                continue;
            }
            $dataType = $this->normalizeDataType((string)($row['data_type'] ?? 'business'));
            $period = $this->snapshotPeriod($row);
            if ($period === '') {
                $selected[$index] = $row;
                continue;
            }
            $raw = $this->decodeJson($row['raw_data'] ?? []);
            if (in_array($dataType, ['order', 'review'], true) && $this->stableEventIdentity($row, $raw, $dataType) !== '') {
                $selected[$index] = $row;
                continue;
            }
            $source = $this->platformKey($this->firstText($row, $raw, ['source', 'platform', 'ota_source', 'otaSource']));
            $systemHotelId = (int)($row['system_hotel_id'] ?? $raw['system_hotel_id'] ?? 0);
            $hotelIdentity = $systemHotelId > 0
                ? 'system:' . $systemHotelId
                : trim((string)($row['hotel_id'] ?? $raw['hotel_id'] ?? $raw['poiId'] ?? ''));
            $key = implode('|', [
                $source,
                $hotelIdentity,
                (string)($row['hotel_id'] ?? $raw['hotel_id'] ?? $raw['poiId'] ?? ''),
                (string)($row['data_date'] ?? $raw['data_date'] ?? $raw['date'] ?? ''),
                $dataType,
                (string)($row['dimension'] ?? $raw['dimension'] ?? ''),
                (string)($row['compare_type'] ?? $raw['compare_type'] ?? 'self'),
                $this->snapshotBusinessIdentity($row, $raw, $dataType),
            ]);
            $grouped[$key][] = ['index' => $index, 'row' => $row];
        }

        $superseded = 0;
        foreach ($grouped as $items) {
            $finalItems = array_values(array_filter($items, fn(array $item): bool => $this->isFinalPeriodRow($item['row'])));
            $candidates = $finalItems !== [] ? $finalItems : $items;
            usort($candidates, fn(array $left, array $right): int => $this->periodRowOrder($left['row']) <=> $this->periodRowOrder($right['row']));
            $winner = $candidates[count($candidates) - 1];
            $selected[(int)$winner['index']] = $winner['row'];
            $superseded += max(0, count($items) - 1);
        }

        ksort($selected);
        return [array_values($selected), $superseded];
    }

    /** @param array<string, mixed> $row */
    private function snapshotPeriod(array $row): string
    {
        $period = strtolower(trim((string)($row['data_period'] ?? '')));
        return in_array($period, ['historical_daily', 'realtime_snapshot'], true) ? $period : '';
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     */
    private function stableEventIdentity(array $row, array $raw, string $dataType): string
    {
        $detail = $this->rawDetail($raw);
        $keys = $dataType === 'order'
            ? ['order_id_hash', 'orderIdHash', 'order_id', 'orderId', 'order_no', 'orderNo', 'order_sn', 'orderSn', 'booking_id', 'bookingId']
            : ['review_id_hash', 'reviewIdHash', 'comment_id_hash', 'commentIdHash', 'review_id', 'reviewId', 'comment_id', 'commentId'];
        $identity = $this->firstText($row, $detail, $keys);
        return $identity !== '' ? $dataType . ':' . $identity : '';
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     */
    private function snapshotBusinessIdentity(array $row, array $raw, string $dataType): string
    {
        $detail = $this->rawDetail($raw);
        $keys = match ($dataType) {
            'advertising' => ['campaignId', 'campaign_id', 'campaignID', 'planId', 'plan_id', 'unitId', 'unit_id'],
            'peer_rank' => ['poiId', 'poi_id', 'peerPoiId', 'peer_poi_id', 'hotelId', 'hotel_id', 'shopId', 'shop_id'],
            'search_keyword' => ['keyword', 'searchKeyword', 'search_word', 'searchWord'],
            'traffic_forecast' => ['forecastDate', 'forecast_date', 'targetDate', 'target_date'],
            default => ['business_id', 'businessId', 'entity_id', 'entityId', 'item_id', 'itemId', 'room_type_id', 'roomTypeId'],
        };
        $identity = $this->firstText([], $detail, $keys);
        return $identity !== '' ? $dataType . ':' . $identity : '';
    }

    /** @param array<string, mixed> $row */
    private function isFinalPeriodRow(array $row): bool
    {
        $isFinal = $row['is_final'] ?? null;
        if (in_array($isFinal, [1, '1', true, 'true'], true)) {
            return true;
        }
        return strtolower(trim((string)($row['data_period'] ?? ''))) === 'historical_daily';
    }

    /** @param array<string, mixed> $row */
    private function periodRowOrder(array $row): int
    {
        foreach (['snapshot_time', 'snapshot_bucket', 'update_time', 'updated_at', 'create_time', 'created_at'] as $key) {
            $value = trim((string)($row[$key] ?? ''));
            if ($value !== '') {
                $time = strtotime($value);
                if ($time !== false) {
                    return $time * 1000000 + max(0, (int)($row['id'] ?? 0));
                }
            }
        }
        return max(0, (int)($row['id'] ?? 0));
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function dailyFact(array $row, array $raw, string $hotelKey, string $source, string $date, string $dataType): array
    {
        $grossRevenue = $this->nullableNumber($row, $raw, ['amount', 'gross_revenue', 'grossRevenue', 'revenue', 'totalAmount', 'saleAmount', 'order_amount', 'orderAmount']);
        $roomRevenue = $this->nullableNumber($row, $raw, ['room_revenue', 'roomRevenue', 'room_amount', 'roomAmount']) ?? $grossRevenue;
        $roomNights = $this->nullableNumber($row, $raw, ['quantity', 'room_nights', 'roomNights', 'checkOutQuantity']);
        $orderCountValue = $this->nullableNumber($row, $raw, ['book_order_num', 'bookOrderNum', 'orderCount', 'orderNum', 'orders']);
        $orders = $orderCountValue !== null ? (int)round($orderCountValue) : null;
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
        ]) ?? ($roomNights !== null && $roomNights > 0 ? $roomNights : null);
        $commissionRate = $this->nullablePercent($row, $raw, ['commission_rate', 'commissionRate', 'ota_commission_rate', 'otaCommissionRate']);
        $directCommissionAmount = $this->nullableNumber($row, $raw, ['commission_amount', 'commissionAmount', 'commission', 'ota_commission', 'otaCommission', 'channel_commission', 'channelCommission']);
        $commissionAmount = $directCommissionAmount;
        $commissionAmountBasis = $directCommissionAmount !== null ? 'direct' : null;
        if ($commissionAmount === null && $commissionRate !== null && $grossRevenue !== null) {
            $commissionAmount = round($grossRevenue * $commissionRate / 100, 2);
            $commissionAmountBasis = 'derived_from_commission_rate';
        }
        $directNetRevenue = $this->nullableNumber($row, $raw, ['net_revenue', 'netRevenue', 'net_amount', 'netAmount', 'after_commission_revenue', 'afterCommissionRevenue', 'settlement_amount', 'settlementAmount']);
        $netRevenue = $directNetRevenue;
        $netRevenueBasis = $directNetRevenue !== null ? 'direct' : null;
        if ($netRevenue === null && $commissionAmount !== null && $grossRevenue !== null) {
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
            'revenue' => $grossRevenue !== null ? round($grossRevenue, 2) : null,
            'gross_revenue' => $grossRevenue !== null ? round($grossRevenue, 2) : null,
            'room_revenue' => $roomRevenue !== null ? round($roomRevenue, 2) : null,
            'net_revenue' => $netRevenue !== null ? round($netRevenue, 2) : null,
            'commission_amount' => $commissionAmount !== null ? round($commissionAmount, 2) : null,
            'commission_rate' => $commissionRate !== null ? round($commissionRate, 2) : null,
            'net_revenue_basis' => $netRevenueBasis,
            'commission_amount_basis' => $commissionAmountBasis,
            'room_nights' => $roomNights !== null ? round($roomNights, 2) : null,
            'available_room_nights' => $availableRoomNights !== null ? round($availableRoomNights, 2) : null,
            'occupied_room_nights' => $occupiedRoomNights !== null ? round($occupiedRoomNights, 2) : null,
            'order_count' => $orders,
            'adr' => $roomRevenue !== null && $roomNights !== null && $roomNights > 0 ? round($roomRevenue / $roomNights, 2) : null,
            'occ' => $availableRoomNights !== null && $availableRoomNights > 0 && $occupiedRoomNights !== null
                ? round($occupiedRoomNights / $availableRoomNights * 100, 2)
                : null,
            'revpar' => $roomRevenue !== null && $availableRoomNights !== null && $availableRoomNights > 0
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
        $listExposure = $this->nullableNumber($row, $raw, ['list_exposure', 'listExposure', 'exposure_count', 'exposureCount']);
        $detailExposure = $this->nullableNumber($row, $raw, ['detail_exposure', 'detailExposure', 'page_views', 'pageViews']);
        $flowRate = $this->nullableNumber($row, $raw, ['flow_rate', 'flowRate', 'conversion_rate', 'conversionRate']);
        $orderFilling = $this->nullableNumber($row, $raw, ['order_filling_num', 'orderFillingNum', 'click_count', 'clickCount']);
        $orderSubmit = $this->nullableNumber($row, $raw, ['order_submit_num', 'orderSubmitNum', 'submit_users', 'submitUsers']);

        return [
            'date_key' => $date,
            'hotel_key' => $hotelKey,
            'platform_key' => $source,
            'compare_type' => (string)($row['compare_type'] ?? $raw['compare_type'] ?? 'self'),
            'list_exposure' => $listExposure !== null ? (int)round($listExposure) : null,
            'detail_exposure' => $detailExposure !== null ? (int)round($detailExposure) : null,
            'flow_rate' => $flowRate !== null ? round($flowRate, 2) : null,
            'order_filling_num' => $orderFilling !== null ? (int)round($orderFilling) : null,
            'order_submit_num' => $orderSubmit !== null ? (int)round($orderSubmit) : null,
            'submit_rate' => $orderFilling !== null && $orderFilling > 0 && $orderSubmit !== null
                ? round($orderSubmit / $orderFilling * 100, 2)
                : null,
            'raw_data' => $raw,
            'source_trace' => $this->rowTrace($row, $hotelKey, $source, 'traffic', $date),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function advertisingFact(array $row, array $raw, string $hotelKey, string $source, string $date): array
    {
        $detail = $this->rawDetail($raw);
        $spend = $this->nullableNumber($row, $detail, ['amount', 'todayCost', 'cost', 'ad_cost', 'adCost', 'spend']);
        $orderAmount = $this->nullableNumber($row, $detail, ['order_amount', 'orderAmount', 'saleAmount', 'revenue']);
        $impressionsValue = $this->nullableNumber($row, $detail, ['list_exposure', 'listExposure', 'impressions', 'exposure_count', 'exposureCount']);
        $clicksValue = $this->nullableNumber($row, $detail, ['detail_exposure', 'detailExposure', 'clicks', 'click_count', 'clickCount']);
        $bookingsValue = $this->nullableNumber($row, $detail, ['book_order_num', 'bookOrderNum', 'bookings', 'bookingCount', 'orderCount']);
        $impressions = $impressionsValue !== null ? (int)round($impressionsValue) : null;
        $clicks = $clicksValue !== null ? (int)round($clicksValue) : null;
        $bookings = $bookingsValue !== null ? (int)round($bookingsValue) : null;
        $roomNights = $this->nullableNumber($row, $detail, ['room_nights', 'roomNights', 'nights']);
        if ($roomNights === null && $source !== 'meituan') {
            $roomNights = $this->nullableNumber($row, $detail, ['quantity']);
        }
        $roas = $this->nullableNumber($row, $detail, ['roas', 'roi']);
        $computedRoas = $spend !== null && $spend > 0 && $orderAmount !== null
            ? $orderAmount / $spend
            : null;
        if ($source === 'meituan' && $roas !== null && $computedRoas !== null) {
            $percentScaled = $computedRoas * 100;
            $tolerance = max(0.01, abs($percentScaled) * 0.001);
            if (abs($roas - $percentScaled) <= $tolerance) {
                $roas = $computedRoas;
            }
        }
        if ($roas === null) {
            $legacyDataValue = $this->nullableNumber($row, $detail, ['data_value', 'dataValue']);
            $isMeituanExposureAlias = $source === 'meituan'
                && $legacyDataValue !== null
                && $impressions !== null
                && $impressions > 0
                && abs($legacyDataValue - $impressions) < 0.00001;
            if (!$isMeituanExposureAlias) {
                $roas = $legacyDataValue;
            }
        }

        return [
            'date_key' => $date,
            'hotel_key' => $hotelKey,
            'platform_key' => $source,
            'campaign_id' => (string)($detail['campaignId'] ?? $detail['campaign_id'] ?? $row['dimension'] ?? ''),
            'spend' => $spend !== null ? round($spend, 2) : null,
            'order_amount' => $orderAmount !== null ? round($orderAmount, 2) : null,
            'bookings' => $bookings,
            'room_nights' => $roomNights !== null ? round($roomNights, 2) : null,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'ctr' => $impressions !== null && $impressions > 0 && $clicks !== null
                ? round($clicks / $impressions * 100, 2)
                : $this->nullablePercent($row, $detail, ['ctr']),
            'cvr' => $this->nullablePercent($row, $detail, ['cvr', 'conversion_rate', 'conversionRate', 'order_rate', 'orderRate'])
                ?? ($clicks !== null && $clicks > 0 && $bookings !== null ? round($bookings / $clicks * 100, 2) : null),
            'roas' => $roas !== null ? round($roas, 2) : ($computedRoas !== null ? round($computedRoas, 2) : null),
            'raw_data' => $raw,
            'source_trace' => $this->rowTrace($row, $hotelKey, $source, 'advertising', $date),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function qualityFact(array $row, array $raw, string $hotelKey, string $source, string $date): array
    {
        $detail = $this->rawDetail($raw);

        return [
            'date_key' => $date,
            'hotel_key' => $hotelKey,
            'platform_key' => $source,
            'service_score' => $this->nullableNumber($row, $detail, ['service_score', 'serviceScore']),
            'psi_score' => $this->nullableNumber($row, $detail, ['data_value', 'dataValue', 'psi_score', 'psiScore', 'psi', 'PSI']),
            'im_score' => $this->nullableNumber($row, $detail, ['im_score', 'imScore']),
            'hotel_collect' => $this->nullableNumber($row, $detail, ['hotel_collect', 'hotelCollect', 'favoriteCount']),
            'reply_rate' => $this->nullablePercent($row, $detail, ['reply_rate', 'replyRate', 'replyrate5m']),
            'raw_data' => $raw,
            'source_trace' => $this->rowTrace($row, $hotelKey, $source, 'quality', $date),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function searchKeywordFact(array $row, array $raw, string $hotelKey, string $source, string $date): array
    {
        $detail = $this->rawDetail($raw);
        $keyword = $this->firstText($row, $detail, ['dimension', 'keyword', 'searchKeyword', 'search_word', 'searchWord']);
        $rank = $this->nullableNumber($row, $detail, ['rank', 'ranking', 'search_rank', 'searchRank', 'position']);

        return [
            'date_key' => $date,
            'hotel_key' => $hotelKey,
            'platform_key' => $source,
            'keyword' => $keyword,
            'rank' => $rank !== null ? round($rank, 2) : null,
            'impressions' => ($value = $this->nullableNumber($row, $detail, ['list_exposure', 'listExposure', 'impressions', 'exposure', 'exposure_count', 'exposureCount'])) !== null ? (int)round($value) : null,
            'clicks' => ($value = $this->nullableNumber($row, $detail, ['detail_exposure', 'detailExposure', 'clicks', 'click_count', 'clickCount'])) !== null ? (int)round($value) : null,
            'order_contribution' => ($value = $this->nullableNumber($row, $detail, ['order_submit_num', 'orderSubmitNum', 'order_contribution', 'orderContribution', 'orders', 'orderCount'])) !== null ? (int)round($value) : null,
            'raw_data' => $raw,
            'source_trace' => $this->rowTrace($row, $hotelKey, $source, 'search_keyword', $date),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function peerRankFact(array $row, array $raw, string $hotelKey, string $source, string $date): array
    {
        $detail = $this->rawDetail($raw);
        $dimension = $this->firstText($row, $detail, ['dimension', 'dimName', '_dimName', 'metricName', 'aiMetricName']);
        $rankType = $this->firstText($row, $detail, ['rank_type', 'rankType', 'type', 'rankListType']);
        if ($rankType === '' && preg_match('/^peer_rank:([^:]+)/', $dimension, $matches) === 1) {
            $rankType = $matches[1];
        }
        if ($dimension === '') {
            $dimension = $rankType !== '' ? 'peer_rank:' . $rankType : 'peer_rank';
        }
        $rank = $this->supplementalNumber($row, $detail, ['rank', 'rank_no', 'rankNo', 'currentRank', 'sort']);
        $metricValue = $this->supplementalNumber($row, $detail, ['data_value', 'dataValue', 'value', 'metric_value']);

        return [
            'date_key' => $date,
            'hotel_key' => $hotelKey,
            'platform_key' => $source,
            'dimension' => $dimension,
            'rank_type' => $rankType,
            'rank' => $rank ?? $metricValue,
            'rank_percent' => $this->supplementalPercent($row, $detail, ['percent', 'ratio', 'rank_percent', 'rankPercent']),
            'metric_value' => $metricValue,
            'compare_type' => (string)($row['compare_type'] ?? $detail['compare_type'] ?? ''),
            'raw_data' => $raw,
            'source_trace' => $this->rowTrace($row, $hotelKey, $source, 'peer_rank', $date),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function trafficAnalysisFact(array $row, array $raw, string $hotelKey, string $source, string $date): array
    {
        $detail = $this->rawDetail($raw);
        $analysisType = $this->firstText($row, $detail, ['analysis_type', 'analysisType', 'type']);
        $dimension = $this->firstText($row, $detail, ['dimension', 'name']);
        if ($dimension === '') {
            $dimension = $analysisType !== '' ? 'traffic_analysis:' . $analysisType : 'traffic_analysis';
        }
        $orderFilling = $this->supplementalNumber($row, $detail, ['order_filling_num', 'orderFillingNum', 'clickCount', 'clicks']);
        $orderSubmit = $this->supplementalNumber($row, $detail, ['order_submit_num', 'orderSubmitNum', 'orderCount', 'payOrderCount', 'orders']);

        return [
            'date_key' => $date,
            'hotel_key' => $hotelKey,
            'platform_key' => $source,
            'dimension' => $dimension,
            'analysis_type' => $analysisType,
            'list_exposure' => $this->supplementalNumber($row, $detail, ['list_exposure', 'listExposure', 'exposeCount', 'exposureCount', 'exposure']),
            'detail_exposure' => $this->supplementalNumber($row, $detail, ['detail_exposure', 'detailExposure', 'visitCount', 'visitorCount', 'uv', 'pv', 'views']),
            'flow_rate' => $this->supplementalPercent($row, $detail, ['flow_rate', 'flowRate', 'visitOrderRate', 'conversionRate', 'orderConversionRate']),
            'order_filling_num' => $orderFilling,
            'order_submit_num' => $orderSubmit,
            'submit_rate' => $orderFilling !== null && $orderFilling > 0 && $orderSubmit !== null
                ? round($orderSubmit / $orderFilling * 100, 2)
                : null,
            'metric_value' => $this->supplementalNumber($row, $detail, ['data_value', 'dataValue', 'value', 'metric_value']),
            'peer_rank' => $this->supplementalNumber($row, $detail, ['peer_rank', 'peerRank', 'rank']),
            'week_over_week' => $this->supplementalNumber($row, $detail, ['week_over_week', 'weekOverWeek', 'wow']),
            'raw_data' => $raw,
            'source_trace' => $this->rowTrace($row, $hotelKey, $source, 'traffic_analysis', $date),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function trafficForecastFact(array $row, array $raw, string $hotelKey, string $source, string $date): array
    {
        $detail = $this->rawDetail($raw);
        $forecastType = $this->firstText($row, $detail, ['forecast_type', 'forecastType', 'type']);
        $dimension = $this->firstText($row, $detail, ['dimension', 'name']);
        if ($dimension === '') {
            $dimension = $forecastType !== '' ? 'traffic_forecast:' . $forecastType : 'traffic_forecast';
        }

        return [
            'date_key' => $date,
            'hotel_key' => $hotelKey,
            'platform_key' => $source,
            'dimension' => $dimension,
            'forecast_type' => $forecastType,
            'forecast_value' => $this->supplementalNumber($row, $detail, ['data_value', 'dataValue', 'current', 'value', 'metric_value']),
            'peer_avg' => $this->supplementalNumber($row, $detail, ['peer_avg', 'peerAvg', 'competitor_avg', 'competitorAvg']),
            'compare_type' => (string)($row['compare_type'] ?? $detail['compare_type'] ?? 'forecast'),
            'raw_data' => $raw,
            'source_trace' => $this->rowTrace($row, $hotelKey, $source, 'traffic_forecast', $date),
        ];
    }

    /**
     * Order-flow rows describe demand moving to or from peers. They remain
     * queryable evidence but must never share the realised-revenue fact grain.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function orderFlowFact(array $row, array $raw, string $hotelKey, string $source, string $date): array
    {
        $detail = $this->rawDetail($raw);
        $orderCount = $this->nullableNumber($row, $detail, ['order_count', 'orderCount', 'lossTotalCnt', 'lossOrderCount']);

        return [
            'date_key' => $date,
            'hotel_key' => $hotelKey,
            'platform_key' => $source,
            'dimension' => (string)($row['dimension'] ?? $detail['dimension'] ?? 'order_flow'),
            'metric_scope' => 'ota_channel_order_flow',
            'calculation_basis' => 'ota_order_flow_non_revenue_fact',
            'direction' => strtolower($this->firstText($row, $detail, ['order_flow_direction', 'orderFlowDirection', 'direction'])),
            'row_type' => strtolower($this->firstText($row, $detail, ['order_flow_row_type', 'orderFlowRowType', 'row_type', 'rowType'])),
            'period' => strtolower($this->firstText($row, $detail, ['order_flow_period', 'orderFlowPeriod', 'period'])),
            'period_start' => $this->dateValue($this->firstText($row, $detail, ['period_start', 'periodStart', 'start_date', 'startDate'])),
            'period_end' => $this->dateValue($this->firstText($row, $detail, ['period_end', 'periodEnd', 'end_date', 'endDate'])),
            'compare_type' => strtolower((string)($row['compare_type'] ?? $detail['compare_type'] ?? '')),
            'flow_order_count' => $orderCount !== null ? max(0, (int)round($orderCount)) : null,
            'flow_room_nights' => $this->nullableNumber($row, $detail, ['room_nights', 'roomNights', 'lossTotalPayRoomNight']),
            'flow_amount' => $this->nullableNumber($row, $detail, ['amount', 'lossTotalPayAmount', 'lossSinglePayAmount']),
            'flow_ratio' => $this->nullablePercent($row, $detail, ['order_ratio', 'orderRatio', 'lossOrderRatio', 'data_value', 'dataValue']),
            'raw_data' => $raw,
            'source_trace' => $this->rowTrace($row, $hotelKey, $source, 'order_flow', $date),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function commentFact(array $row, array $raw, string $hotelKey, string $source, string $date): array
    {
        $detail = $this->rawDetail($raw);
        $metrics = is_array($raw['metrics'] ?? null) ? array_merge($detail, $raw['metrics']) : $detail;

        return [
            'date_key' => $date,
            'hotel_key' => $hotelKey,
            'platform_key' => $source,
            'dimension' => (string)($row['dimension'] ?? $metrics['dimension'] ?? 'review'),
            'channel' => $this->firstText($row, $metrics, ['comment_channel', 'channel', 'channelName', 'platform', 'source']),
            'comment_score' => $this->nullableNumber($row, $metrics, ['comment_score', 'commentScore', 'score', 'rating', 'rate', 'totalScore', 'overallScore', 'star']),
            'comment_count' => $this->supplementalNumber($row, $metrics, ['comment_count', 'commentCount', 'commentsCount', 'review_count', 'reviewCount', 'totalCommentCount', 'totalCount', 'allCount', 'quantity']),
            'bad_review_count' => $this->supplementalNumber($row, $metrics, ['bad_review_count', 'badReviewCount', 'negativeCommentCount', 'negativeCount', 'badCount', 'lowScoreCount', 'noRecommendCount', 'data_value']),
            'qunar_comment_score' => $this->nullableNumber($row, $metrics, ['qunar_comment_score', 'qunarCommentScore', 'qunarRatingall']),
            'review_environment_score' => $this->nullableNumber($row, $metrics, ['review_environment_score', 'ratingLocation', 'environmentScore']),
            'review_facility_score' => $this->nullableNumber($row, $metrics, ['review_facility_score', 'ratingFacility', 'facilityScore']),
            'review_service_score' => $this->nullableNumber($row, $metrics, ['review_service_score', 'ratingService', 'reviewServiceScore']),
            'review_cleanliness_score' => $this->nullableNumber($row, $metrics, ['review_cleanliness_score', 'ratingRoom', 'cleanlinessScore']),
            'review_photo_count' => $this->supplementalNumber($row, $metrics, ['review_photo_count', 'hasPicCount', 'photoCommentCount']),
            'review_photo_rate' => $this->supplementalPercent($row, $metrics, ['review_photo_rate', 'photoRate', 'hasPicRate']),
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
        if (in_array($status, OnlineDataTrustStatusService::blockingRowStatuses(), true)) {
            $failureReasons[] = 'row_status_' . $status;
        }

        $validationStatus = strtolower(trim((string)($row['validation_status'] ?? '')));
        if (in_array($validationStatus, OnlineDataTrustStatusService::blockingValidationStatuses(), true)) {
            $failureReasons[] = 'validation_status_' . $validationStatus;
            foreach ($this->validationFlagReasons($row['validation_flags'] ?? []) as $reason) {
                $failureReasons[] = $reason;
            }
        } else {
            foreach ($this->blockingValidationFlagReasons($row['validation_flags'] ?? []) as $reason) {
                $failureReasons[] = $reason;
            }
        }

        if (array_key_exists('readback_verified', $row) && (int)$row['readback_verified'] !== 1) {
            $failureReasons[] = 'readback_unverified';
        }

        $sourceTraceId = $this->sourceTraceId($row);
        $dataSourceId = (int)($row['data_source_id'] ?? 0);
        $syncTaskId = (int)($row['sync_task_id'] ?? 0);
        if ($sourceTraceId === '' && $dataSourceId <= 0 && $syncTaskId <= 0) {
            $failureReasons[] = 'provenance_missing';
        }
        if ((int)($row['system_hotel_id'] ?? 0) <= 0) {
            $failureReasons[] = 'system_hotel_id_missing';
        }
        if ($this->isManualIngestion($row)
            && !in_array($validationStatus, ['verified', 'valid', 'confirmed', 'approved', 'passed', 'success'], true)) {
            $failureReasons[] = 'manual_override_unverified';
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
            'source_trace_id' => $sourceTraceId,
            'data_source_id' => $row['data_source_id'] ?? null,
            'sync_task_id' => $row['sync_task_id'] ?? null,
            'ingestion_method' => (string)($row['ingestion_method'] ?? ''),
            'hotel_key' => $hotelKey,
            'system_hotel_id' => max(0, (int)($row['system_hotel_id'] ?? 0)) ?: null,
            'platform_hotel_id' => trim((string)($row['hotel_id'] ?? '')),
            'hotel_name' => trim((string)($row['hotel_name'] ?? '')),
            'platform' => $source,
            'data_type' => $dataType,
            'date_key' => $date,
            'collected_at' => $this->traceCollectionTimestamp($row),
            'updated_at' => $this->traceTimestamp($row),
            'stored' => isset($row['id']) && trim((string)$row['id']) !== '',
            'readback_verified' => (int)($row['readback_verified'] ?? 0) === 1,
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
            $code = is_array($flag)
                ? trim((string)($flag['code'] ?? $flag['field'] ?? ''))
                : trim((string)$flag);
            if ($code !== '') {
                $reasons[] = 'validation:' . $code;
            }
        }
        return $reasons;
    }

    /** @return array<int, string> */
    private function blockingValidationFlagReasons(mixed $flags): array
    {
        $blockingFragments = ['mismatch', 'wrong_hotel', 'binding', 'unverified', 'provenance', 'permission_denied', 'collection_failed', 'parse_failed'];
        return array_values(array_filter(
            $this->validationFlagReasons($flags),
            static function (string $reason) use ($blockingFragments): bool {
                $normalized = strtolower($reason);
                foreach ($blockingFragments as $fragment) {
                    if (str_contains($normalized, $fragment)) {
                        return true;
                    }
                }
                return false;
            }
        ));
    }

    /** @param array<string, mixed> $row */
    private function isManualIngestion(array $row): bool
    {
        $values = [
            (string)($row['ingestion_method'] ?? ''),
            (string)($row['source'] ?? ''),
        ];
        foreach ($values as $value) {
            $normalized = strtolower(trim($value));
            if ($normalized !== '' && (str_contains($normalized, 'manual') || str_contains($normalized, 'override'))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Competitor/peer rows can support comparison, but they must never become
     * the hotel's own daily revenue fact.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     */
    private function isSelfRevenueFact(array $row, array $raw, string $dataType): bool
    {
        if (in_array($dataType, ['competitor', 'competitor_avg', 'competition', 'peer'], true)) {
            return false;
        }

        $compareType = strtolower($this->firstText($row, $raw, ['compare_type', 'compareType']));
        if ($compareType !== '' && !in_array($compareType, ['self', 'own', 'ours', 'target_hotel'], true)) {
            return false;
        }

        $dimension = strtolower($this->firstText($row, $raw, ['dimension', 'dimName', '_dimName']));
        return !str_contains($dimension, 'competitor')
            && !str_contains($dimension, 'competition_circle_hotel')
            && !str_contains($dimension, 'peer_hotel');
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

    /**
     * Collection time is kept distinct from database update time. Missing
     * capture evidence stays null so downstream truth status cannot be
     * promoted by a persistence timestamp.
     *
     * @param array<string, mixed> $row
     */
    private function traceCollectionTimestamp(array $row): ?string
    {
        $raw = $this->decodeJson($row['raw_data'] ?? []);
        $meta = is_array($raw['meta'] ?? null) ? $raw['meta'] : [];
        $capture = is_array($raw['capture_evidence'] ?? null) ? $raw['capture_evidence'] : [];
        foreach ([
            $row['collected_at'] ?? null,
            $row['snapshot_time'] ?? null,
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
        ] as $value) {
            $text = trim((string)($value ?? ''));
            if ($text !== '') {
                return $text;
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
        if (in_array($value, ['order', 'orders', 'order_list', 'order-list'], true)) {
            return 'order';
        }
        if (in_array($value, ['ad', 'ads', 'advertising', 'advertisement', 'campaign', 'campaigns'], true)) {
            return 'advertising';
        }
        if (in_array($value, ['quality', 'service', 'service_quality', 'psi'], true)) {
            return 'quality';
        }
        if (in_array($value, ['search_keyword', 'search-keyword', 'search_keywords', 'search-keywords', 'keyword', 'keywords', 'search_word', 'search_words', 'hot_word', 'hot_words'], true)) {
            return 'search_keyword';
        }
        if (in_array($value, ['peer_rank', 'peer-rank', 'peer_ranking', 'peer-ranking', 'competitor_rank', 'competitor-rank', 'rank', 'rankings'], true)) {
            return 'peer_rank';
        }
        if (in_array($value, ['traffic_analysis', 'traffic-analysis', 'flow_analysis', 'flow-analysis', 'flow_conversion', 'flow-conversion', 'flow_trend', 'flow-trend', 'flowtrend', 'flowconversion'], true)) {
            return 'traffic_analysis';
        }
        if (in_array($value, ['traffic_forecast', 'traffic-forecast', 'flow_forecast', 'flow-forecast', 'flowforecast'], true)) {
            return 'traffic_forecast';
        }
        if (in_array($value, ['review', 'reviews', 'comment', 'comments'], true)) {
            return 'review';
        }
        return $value !== '' ? $value : 'business';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function sourceTraceId(array $row): string
    {
        $traceId = trim((string)($row['source_trace_id'] ?? ''));
        if ($traceId !== '') {
            return $traceId;
        }

        $raw = $this->decodeJson($row['raw_data'] ?? []);
        return trim((string)($raw['source_trace_id'] ?? ''));
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function rawDetail(array $raw): array
    {
        return is_array($raw['row'] ?? null) ? array_merge($raw, $raw['row']) : $raw;
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
     * @param array<int, string> $keys
     */
    private function supplementalNumber(array $row, array $raw, array $keys): ?float
    {
        $rawValue = $this->nullableNumber([], $raw, $keys);
        if ($rawValue !== null) {
            return $rawValue;
        }
        $rowValue = $this->nullableNumber($row, [], $keys);
        return $rowValue !== null && $rowValue != 0.0 ? $rowValue : null;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @param array<int, string> $keys
     */
    private function supplementalPercent(array $row, array $raw, array $keys): ?float
    {
        $value = $this->supplementalNumber($row, $raw, $keys);
        if ($value === null || $value < 0) {
            return null;
        }
        $percent = $value > 0 && $value <= 1 ? $value * 100 : $value;
        return $percent <= 100 ? round($percent, 2) : null;
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
    private function sanitizeRawData(array $raw, bool $orderContext = false): array
    {
        $sanitized = [];
        foreach ($raw as $key => $value) {
            $keyText = (string)$key;
            if (preg_match('/cookie|token|authorization|mtgsig|password|secret/i', $keyText)) {
                continue;
            }

            $childOrderContext = $orderContext || $this->isOrderContainerKey($keyText);
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeRawData($value, $childOrderContext);
                continue;
            }

            if ($childOrderContext || $this->isOrderPiiKey($keyText)) {
                $this->appendRedactedOrderField($sanitized, $keyText, $value);
                continue;
            }

            $sanitized[$key] = $value;
        }
        return $sanitized;
    }

    /**
     * @param array<mixed> $target
     */
    private function appendRedactedOrderField(array &$target, string $key, mixed $value): void
    {
        if ($this->isOrderIdKey($key)) {
            $text = trim((string)$value);
            if ($text !== '') {
                $target[$this->redactedOrderFieldName($key, 'hash')] = hash('sha256', 'ota_order|' . $text);
            }
            return;
        }
        if ($this->isPhoneKey($key)) {
            $masked = $this->maskPhone((string)$value);
            if ($masked !== '') {
                $target[$this->redactedOrderFieldName($key, 'masked')] = $masked;
            }
            return;
        }
        if ($this->isGuestNameKey($key)) {
            $masked = $this->maskName((string)$value);
            if ($masked !== '') {
                $target[$this->redactedOrderFieldName($key, 'masked')] = $masked;
            }
            return;
        }
        if ($this->isSensitiveOrderTextKey($key)) {
            return;
        }

        $target[$key] = $value;
    }

    private function isOrderContainerKey(string $key): bool
    {
        return preg_match('/order[_-]?(list|rows|items|data|detail|details|info)|orders/i', $key) === 1;
    }

    private function isOrderPiiKey(string $key): bool
    {
        return $this->isOrderIdKey($key)
            || $this->isPhoneKey($key)
            || $this->isGuestNameKey($key)
            || $this->isSensitiveOrderTextKey($key);
    }

    private function isOrderIdKey(string $key): bool
    {
        return preg_match('/^(order[_-]?(id|no|num|number|sn)|booking[_-]?(id|no|number))$/i', $key) === 1;
    }

    private function isPhoneKey(string $key): bool
    {
        return preg_match('/(phone|mobile|tel)$/i', $key) === 1;
    }

    private function isGuestNameKey(string $key): bool
    {
        return preg_match('/(guest|customer|contact|user|traveller|passenger)[_-]?name$/i', $key) === 1;
    }

    private function isSensitiveOrderTextKey(string $key): bool
    {
        return preg_match('/(certificate|credential|id[_-]?card|card[_-]?no|passport|remark|memo|note|address)/i', $key) === 1;
    }

    private function redactedOrderFieldName(string $key, string $suffix): string
    {
        if ($this->isOrderIdKey($key)) {
            return 'order_id_hash';
        }
        $name = preg_replace('/(?<!^)[A-Z]/', '_$0', $key) ?? $key;
        $name = strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '_', $name));
        $name = trim($name, '_');
        return ($name !== '' ? $name : 'field') . '_' . $suffix;
    }

    private function maskPhone(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) <= 4) {
            return str_repeat('*', strlen($digits));
        }
        return str_repeat('*', strlen($digits) - 4) . substr($digits, -4);
    }

    private function maskName(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        return mb_substr($value, 0, 1) . '***';
    }

    private function tableExists(string $table): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return false;
        }
        if (strtolower((string)Db::connect()->getConfig('type')) === 'sqlite') {
            return Db::query(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
                [$table]
            ) !== [];
        }
        return !empty(Db::query("SHOW TABLES LIKE '" . addslashes($table) . "'"));
    }

    /**
     * @return array<string, bool>
     */
    private function tableColumns(string $table): array
    {
        $columns = [];
        if (strtolower((string)Db::connect()->getConfig('type')) === 'sqlite') {
            foreach (Db::query('PRAGMA table_info(`' . $table . '`)') as $row) {
                if (!empty($row['name'])) {
                    $columns[(string)$row['name']] = true;
                }
            }
            return $columns;
        }
        foreach (Db::query('SHOW COLUMNS FROM `' . $table . '`') as $row) {
            if (!empty($row['Field'])) {
                $columns[(string)$row['Field']] = true;
            }
        }
        return $columns;
    }
}
