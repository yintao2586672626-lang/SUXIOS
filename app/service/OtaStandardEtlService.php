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
        $advertisingFacts = [];
        $qualityFacts = [];
        $searchKeywordFacts = [];
        $peerRankFacts = [];
        $trafficAnalysisFacts = [];
        $trafficForecastFacts = [];
        $commentFacts = [];
        $rejectedRows = [];

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
            if ($dataType === 'review') {
                $rejectedRows[] = ['index' => $index, 'reason' => 'comment_collection_disabled', 'data_type' => 'review'];
                continue;
            }
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
            'fact_ota_comment' => $commentFacts,
            'data_quality' => [
                'input_rows' => count($rows),
                'accepted_rows' => $acceptedCount,
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
            'data_source_id',
            'sync_task_id',
            'ingestion_method',
            'source_trace_id',
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
    private function advertisingFact(array $row, array $raw, string $hotelKey, string $source, string $date): array
    {
        $detail = $this->rawDetail($raw);
        $spend = $this->firstNumber($row, $detail, ['amount', 'todayCost', 'cost', 'ad_cost', 'adCost', 'spend']);
        $orderAmount = $this->firstNumber($row, $detail, ['order_amount', 'orderAmount', 'saleAmount', 'revenue']);
        $impressions = (int)round($this->firstNumber($row, $detail, ['list_exposure', 'listExposure', 'impressions', 'exposure_count', 'exposureCount']));
        $clicks = (int)round($this->firstNumber($row, $detail, ['detail_exposure', 'detailExposure', 'clicks', 'click_count', 'clickCount']));
        $bookings = (int)round($this->firstNumber($row, $detail, ['book_order_num', 'bookOrderNum', 'bookings', 'bookingCount', 'orderCount']));
        $roomNights = $this->firstNumber($row, $detail, ['quantity', 'room_nights', 'roomNights', 'nights']);
        $roas = $this->nullableNumber($row, $detail, ['data_value', 'dataValue', 'roas', 'roi']);

        return [
            'date_key' => $date,
            'hotel_key' => $hotelKey,
            'platform_key' => $source,
            'campaign_id' => (string)($detail['campaignId'] ?? $detail['campaign_id'] ?? $row['dimension'] ?? ''),
            'spend' => round($spend, 2),
            'order_amount' => round($orderAmount, 2),
            'bookings' => $bookings,
            'room_nights' => round($roomNights, 2),
            'impressions' => $impressions,
            'clicks' => $clicks,
            'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 2) : $this->nullablePercent($row, $detail, ['ctr']),
            'cvr' => $this->nullablePercent($row, $detail, ['flow_rate', 'flowRate', 'cvr', 'conversion_rate', 'conversionRate'])
                ?? ($clicks > 0 ? round($bookings / $clicks * 100, 2) : null),
            'roas' => $roas !== null ? round($roas, 2) : ($spend > 0 ? round($orderAmount / $spend, 2) : null),
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
            'impressions' => (int)round($this->firstNumber($row, $detail, ['list_exposure', 'listExposure', 'impressions', 'exposure', 'exposure_count', 'exposureCount'])),
            'clicks' => (int)round($this->firstNumber($row, $detail, ['detail_exposure', 'detailExposure', 'clicks', 'click_count', 'clickCount'])),
            'order_contribution' => (int)round($this->firstNumber($row, $detail, ['order_submit_num', 'orderSubmitNum', 'order_contribution', 'orderContribution', 'orders', 'orderCount'])),
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
     * @param array<string, mixed> $row
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function commentFact(array $row, array $raw, string $hotelKey, string $source, string $date): array
    {
        $score = $this->nullableNumber($row, $raw, ['comment_score', 'commentScore', 'score', 'data_value', 'dataValue']);
        $commentCount = $this->nullableNumber($row, $raw, ['comment_count', 'commentCount', 'commentsCount', 'reviewCount', 'totalCommentCount', 'totalCount', 'data_value', 'dataValue']);
        $badReviewCount = $this->nullableNumber($row, $raw, ['bad_review_count', 'badReviewCount', 'negativeCommentCount', 'negativeCount', 'badCount', 'lowScoreCount']);
        $channel = trim((string)($raw['comment_channel'] ?? $raw['channel'] ?? $raw['channelName'] ?? $raw['platform'] ?? $source));
        $storeName = trim((string)($raw['comment_store_name'] ?? $raw['hotelName'] ?? $raw['hotel_name'] ?? ''));

        return [
            'date_key' => $date,
            'hotel_key' => $hotelKey,
            'platform_key' => $source,
            'score' => $score,
            'comment_count' => $commentCount,
            'bad_review_count' => $badReviewCount,
            'comment_channel' => $channel,
            'comment_store_name' => $storeName,
            'raw_data' => [
                'metric_scope' => 'ota_channel',
                'dimension_values' => array_filter([
                    'comment_store_name' => $storeName,
                    'comment_date' => $date,
                    'comment_channel' => $channel,
                ], static fn($value): bool => $value !== ''),
                'metrics' => array_filter([
                    'comment_score' => $score,
                    'comment_count' => $commentCount,
                    'bad_review_count' => $badReviewCount,
                ], static fn($value): bool => $value !== null && $value !== ''),
            ],
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
            'source_trace_id' => $this->sourceTraceId($row),
            'data_source_id' => $row['data_source_id'] ?? null,
            'sync_task_id' => $row['sync_task_id'] ?? null,
            'ingestion_method' => (string)($row['ingestion_method'] ?? ''),
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
