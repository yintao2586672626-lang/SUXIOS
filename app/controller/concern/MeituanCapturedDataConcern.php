<?php

namespace app\controller\concern;

use app\service\CtripTrafficDisplayService;
use app\service\OnlineDailyDataPersistenceService;
use app\service\OnlineDataFieldFactService;
use InvalidArgumentException;
use think\facade\Db;

trait MeituanCapturedDataConcern
{
    private function buildMeituanCapturedDailyRows(array $payload, ?int $systemHotelId = null): array
    {
        $payloadSystemHotelId = $this->firstMeituanValue($payload, ['system_hotel_id', 'systemHotelId'], null);
        if ($systemHotelId !== null
            && $systemHotelId > 0
            && is_numeric($payloadSystemHotelId)
            && (int)$payloadSystemHotelId > 0
            && (int)$payloadSystemHotelId !== $systemHotelId) {
            throw new InvalidArgumentException('美团采集数据所属酒店与请求酒店不一致');
        }
        $context = $this->buildMeituanCaptureContext($payload, $systemHotelId);
        $rows = [];

        foreach ($this->extractMeituanCapturedSection($payload, 'traffic') as $item) {
            $row = $this->normalizeMeituanCapturedTrafficRow($item, $context);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        foreach ($this->extractMeituanCapturedSection($payload, 'peer_rank') as $item) {
            $row = $this->normalizeMeituanCapturedPeerRankRow($item, $context);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        foreach ($this->extractMeituanCapturedSection($payload, 'traffic_analysis') as $item) {
            $row = $this->normalizeMeituanCapturedTrafficAnalysisRow($item, $context);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        foreach ($this->extractMeituanCapturedSection($payload, 'order_flow') as $item) {
            $row = $this->normalizeMeituanCapturedOrderFlowRow($item, $context);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        foreach ($this->extractMeituanCapturedSection($payload, 'search_keyword') as $item) {
            $row = $this->normalizeMeituanCapturedSearchKeywordRow($item, $context);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        foreach ($this->extractMeituanCapturedSection($payload, 'traffic_forecast') as $item) {
            $row = $this->normalizeMeituanCapturedTrafficForecastRow($item, $context);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        foreach ($this->extractMeituanCapturedSection($payload, 'reviews') as $item) {
            $row = $this->normalizeMeituanCapturedReviewRow($item, $context);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        foreach ($this->extractMeituanCapturedSection($payload, 'ads') as $index => $item) {
            $row = $this->normalizeMeituanCapturedAdsRow($item, array_merge($context, [
                'captured_row_index' => (int)$index,
            ]));
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        foreach ($this->extractMeituanCapturedSection($payload, 'orders') as $item) {
            $row = $this->normalizeMeituanCapturedOrderRow($item, $context);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function buildMeituanCaptureContext(array $payload, ?int $systemHotelId): array
    {
        return [
            'system_hotel_id' => $systemHotelId,
            'store_id' => (string)$this->firstMeituanValue($payload, ['store_id', 'storeId'], ''),
            'poi_id' => (string)$this->firstMeituanValue($payload, ['poi_id', 'poiId', 'hotel_id', 'hotelId'], ''),
            'poi_name' => (string)$this->firstMeituanValue($payload, ['poi_name', 'poiName', 'hotel_name', 'hotelName', 'store_name', 'storeName'], ''),
            'captured_at' => (string)$this->firstMeituanValue($payload, ['captured_at', 'capturedAt', 'scraped_at', 'scrapedAt'], date('Y-m-d H:i:s')),
            'default_data_date' => (string)$this->firstMeituanValue($payload, ['default_data_date', 'defaultDataDate', 'data_date', 'dataDate'], date('Y-m-d')),
            'data_period' => (string)$this->firstMeituanValue($payload, ['data_period', 'dataPeriod'], ''),
            'snapshot_time' => (string)$this->firstMeituanValue($payload, ['snapshot_time', 'snapshotTime'], ''),
        ];
    }

    private function extractMeituanCapturedSection(array $payload, string $section): array
    {
        $rows = [];
        foreach ($this->meituanCapturedSectionAliases($section) as $key) {
            if (array_key_exists($key, $payload)) {
                $rows = array_merge($rows, $this->normalizeMeituanCapturedList($payload[$key], $section));
            }
        }

        if (isset($payload['responses']) && is_array($payload['responses'])) {
            foreach ($payload['responses'] as $response) {
                if (!is_array($response) || !$this->meituanCaptureResponseMatchesSection($response, $section)) {
                    continue;
                }
                $data = $response['data'] ?? $response['body'] ?? $response['json'] ?? [];
                $rows = array_merge($rows, $this->normalizeMeituanCapturedList($data, $section));
            }
        }

        return $rows;
    }

    private function meituanCapturedSectionAliases(string $section): array
    {
        return match ($section) {
            'reviews' => ['reviews', 'review', 'comments', 'commentList', 'commentsInfo'],
            'traffic' => ['traffic', 'businessData', 'business_data', 'weightTraffic', 'weight_traffic', 'peerTrends', 'peer_trends'],
            'peer_rank' => ['peerRank', 'peer_rank', 'competitorRank', 'competitor_rank', 'rankings', 'ranking'],
            'traffic_analysis' => ['flowAnalysis', 'flow_analysis', 'trafficAnalysis', 'traffic_analysis', 'flowConversion', 'flowTrend', 'flowTrendDetail'],
            'order_flow' => ['order_flow', 'orderFlow', 'orderFlowRows', 'order_flow_rows'],
            'search_keyword' => ['searchKeywords', 'searchKeyWords', 'search_keywords', 'keywords', 'search_keyword'],
            'traffic_forecast' => ['trafficForecast', 'traffic_forecast', 'flowForecast', 'flow_forecast'],
            'ads' => ['ads', 'advertising', 'adData', 'cureShops', 'cure_shops'],
            'orders' => ['orders', 'orderList', 'order_list'],
            default => [$section],
        };
    }

    private function meituanCaptureResponseMatchesSection(array $response, string $section): bool
    {
        $type = strtolower((string)($response['type'] ?? $response['section'] ?? ''));
        if ($type !== '' && in_array($type, $this->meituanCapturedSectionAliases($section), true)) {
            return true;
        }

        $url = strtolower((string)($response['url'] ?? ''));
        if ($url === '') {
            return false;
        }

        $needles = match ($section) {
            'reviews' => ['querygeneralcommentinfo', 'commentsinfo', 'comments/statistics'],
            'traffic' => ['businessdata', 'weighttraffic', 'traffic', 'peertrends'],
            'peer_rank' => ['peer/rank', 'peerrank', 'competitorrank'],
            'traffic_analysis' => ['flowconversion', 'flowtrend', 'flowtrenddetail'],
            'order_flow' => ['/peerrank/order/loss/query'],
            'search_keyword' => ['searchkeyword', 'search-keyword', 'searchkeywords'],
            'traffic_forecast' => ['flowforecast', 'trafficforecast'],
            'ads' => ['cureshops'],
            'orders' => ['/orders/list', '/order/unhandled/count'],
            default => [],
        };

        foreach ($needles as $needle) {
            if (str_contains($url, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function normalizeMeituanCapturedList($value, string $section): array
    {
        if (!is_array($value)) {
            return [];
        }

        if ($this->isSequentialArray($value)) {
            return array_values(array_filter($value, static fn($item): bool => is_array($item)));
        }

        foreach ($this->meituanCapturedListPaths($section) as $path) {
            $nested = $this->readNestedMeituanValue($value, $path);
            if (is_array($nested)) {
                $list = $this->normalizeMeituanCapturedList($nested, $section);
                if (!empty($list)) {
                    return $list;
                }
            }
        }

        return $this->looksLikeMeituanCapturedRow($value, $section) ? [$value] : [];
    }

    private function meituanCapturedListPaths(string $section): array
    {
        return match ($section) {
            'reviews' => [
                ['data', 'commentList'],
                ['data', 'comments'],
                ['data', 'list'],
                ['commentList'],
                ['comments'],
                ['list'],
                ['data'],
            ],
            'traffic' => [
                ['data', 'businessData'],
                ['data', 'weightTraffic'],
                ['data', 'weight_traffic'],
                ['data', 'traffic'],
                ['data', 'peerTrends'],
                ['data', 'list'],
                ['data', 'rows'],
                ['businessData'],
                ['weightTraffic'],
                ['weight_traffic'],
                ['traffic'],
                ['peerTrends'],
                ['list'],
                ['rows'],
                ['data'],
            ],
            'peer_rank' => [
                ['data', 'peerRankData'],
                ['data', 'data', 'peerRankData'],
                ['data', 'rankings'],
                ['data', 'list'],
                ['peerRankData'],
                ['rankings'],
                ['list'],
                ['data'],
            ],
            'traffic_analysis' => [
                ['data', 'list'],
                ['data', 'rows'],
                ['data', 'detail'],
                ['data', 'data', 'list'],
                ['list'],
                ['rows'],
                ['detail'],
                ['data'],
            ],
            'order_flow' => [
                ['data', 'order_flow'],
                ['data', 'orderFlow'],
                ['data', 'rows'],
                ['order_flow'],
                ['orderFlow'],
                ['rows'],
                ['data'],
            ],
            'search_keyword' => [
                ['data', 'searchKeywords'],
                ['data', 'searchKeyWords'],
                ['data', 'keywords'],
                ['data', 'cards'],
                ['data', 'list'],
                ['searchKeywords'],
                ['searchKeyWords'],
                ['keywords'],
                ['cards'],
                ['list'],
                ['data'],
            ],
            'traffic_forecast' => [
                ['data', 'detail'],
                ['data', 'data', 'detail'],
                ['data', 'list'],
                ['detail'],
                ['list'],
                ['data'],
            ],
            'ads' => [
                ['data', 'cureShops'],
                ['data', 'list'],
                ['data', 'rows'],
                ['cureShops'],
                ['list'],
                ['rows'],
                ['data'],
            ],
            'orders' => [
                ['data', 'orders'],
                ['data', 'list'],
                ['data', 'orderList'],
                ['orders'],
                ['orderList'],
                ['list'],
                ['data'],
            ],
            default => [['data'], ['list']],
        };
    }

    private function looksLikeMeituanCapturedRow(array $value, string $section): bool
    {
        $keys = match ($section) {
            'reviews' => ['review_id', 'reviewId', 'commentId', 'comment', 'content', 'commentContent'],
            'traffic' => ['exposure_count', 'exposureCount', 'page_views', 'pageViews', 'unique_visitors', 'businessData', 'weightTraffic'],
            'peer_rank' => ['rank', 'rankType', 'rank_type', 'peerRankData', 'roundRanks'],
            'traffic_analysis' => ['analysis_type', 'analysisType', 'listExposure', 'detailExposure', 'flowRate', 'exposeCount', 'visitCount'],
            'order_flow' => ['order_flow_row_type', 'orderFlowRowType', 'order_flow_direction', 'orderFlowDirection', 'lossTotalCnt', 'lossOrderCount'],
            'search_keyword' => ['keyword', 'searchKeyword', 'searchWord', 'itemList', 'keywords'],
            'traffic_forecast' => ['forecast_type', 'forecastType', 'current', 'peerAvg', 'dateTime'],
            'ads' => ['cureShops', 'exposure_count', 'click_count', 'adId', 'campaignId'],
            'orders' => ['order_id_hash', 'order_no_hash', 'booking_id_hash', 'order_id', 'orderId', 'orderNo', 'order_no', 'orderStatus', 'order_status', 'total_amount', 'totalAmount', 'orderCount', 'roomNights', 'buyTime', 'checkIn', 'checkOut', 'basePrice', 'bottomPrice'],
            default => [],
        };
        foreach ($keys as $key) {
            if (array_key_exists($key, $value)) {
                return true;
            }
        }
        return false;
    }

    private function normalizeMeituanCapturedTrafficRow(array $item, array $context): ?array
    {
        $exposure = (int)$this->meituanNumber($item, ['mt_exposure', 'exposure_count', 'exposureCount', 'listExposure', 'impression', 'impressions', 'exposure', 'exposureUV', 'exposure_uv'], 0);
        $pageViews = (int)$this->meituanNumber($item, ['mt_intention_uv', 'intentionUV', 'intention_uv', 'page_views', 'pageViews', 'detailExposure', 'detailVisitors', 'unique_visitors', 'uniqueVisitors', 'visitor_count', 'visitorCount', 'uv', 'UV', 'pv', 'views'], 0);
        $clicks = (int)$this->meituanNumber($item, ['click_count', 'clickCount', 'clickNum', 'clicks', 'click'], 0);
        $payOrders = (int)$this->meituanNumber($item, ['mt_pay_orders', 'pay_orders', 'payOrders', 'payOrderCnt', 'pay_order_cnt', 'payOrderCount', 'pay_order_count', 'order_submit_num', 'orderSubmitNum', 'submit_users', 'submitUsers', 'orderNum', 'order_count', 'orders'], 0);
        $payRooms = (int)$this->meituanNumber($item, ['mt_pay_rooms', 'pay_rooms', 'payRooms', 'payRoomNum', 'pay_room_num', 'roomNights', 'room_nights', 'quantity'], 0);
        $conversion = $this->normalizeMeituanPercentValue($this->firstMeituanValue($item, ['mt_conversion_rate', 'conversion_rate', 'conversionRate', 'flowRate', 'orderRate', 'payOrderPerIntention', 'pay_order_per_intention'], null));
        if ($conversion === null) {
            $conversion = CtripTrafficDisplayService::trafficRate((float)($pageViews ?: $clicks), (float)$exposure);
        }

        if ($exposure <= 0 && $pageViews <= 0 && $clicks <= 0 && $payOrders <= 0 && $payRooms <= 0) {
            return null;
        }

        $dataDate = $this->normalizeOnlineDataDate($this->firstMeituanValue($item, ['data_date', 'dataDate', 'date', 'statDate', 'stat_date'], ''))
            ?: ($context['default_data_date'] ?? date('Y-m-d'));
        $factSource = array_merge($item, [
            'mt_exposure' => $exposure,
            'mt_intention_uv' => $pageViews ?: $clicks,
            'mt_pay_orders' => $payOrders,
            'mt_pay_rooms' => $payRooms,
        ]);

        return $this->baseMeituanCapturedRow($factSource, $context, [
            'data_date' => $dataDate,
            'amount' => 0,
            'quantity' => $payRooms,
            'book_order_num' => $payOrders,
            'comment_score' => 0,
            'data_value' => $exposure,
            'data_type' => 'traffic',
            'dimension' => 'traffic',
            'platform' => 'Meituan',
            'compare_type' => 'self',
            'list_exposure' => $exposure,
            'detail_exposure' => $pageViews ?: $clicks,
            'flow_rate' => round($conversion, 2),
            'order_filling_num' => $clicks,
            'order_submit_num' => $payOrders,
        ]);
    }

    private function normalizeMeituanCapturedPeerRankRow(array $item, array $context): ?array
    {
        $rank = (int)$this->meituanNumber($item, ['rank', 'rank_no', 'rankNo', 'currentRank', 'sort'], 0);
        $dataValue = $this->nullableNumberFromKeys($item, ['data_value', 'dataValue', 'value', 'metric_value']);
        $percent = $this->normalizeMeituanPercentValue($this->firstMeituanValue($item, ['percent', 'ratio', 'rank_percent', 'rankPercent'], null));
        if ($dataValue === null && $percent === null && $rank <= 0) {
            return null;
        }

        $dataDate = $this->normalizeOnlineDataDate($this->firstMeituanValue($item, ['data_date', 'dataDate', 'date', 'statDate', 'stat_date'], ''))
            ?: ($context['default_data_date'] ?? date('Y-m-d'));
        $rankType = trim((string)$this->firstMeituanValue($item, ['rank_type', 'rankType', 'type', 'rankListType'], ''));
        $dateRange = trim((string)$this->firstMeituanValue($item, ['date_range', 'dateRange'], $context['date_range'] ?? ''));
        $metric = trim((string)$this->firstMeituanValue($item, ['dimension', 'dimName', '_dimName', 'metricName', 'aiMetricName'], 'peer_rank'));
        $itemHotelId = trim((string)$this->firstMeituanValue($item, ['poi_id', 'poiId', 'hotel_id', 'hotelId', 'shop_id', 'shopId', 'store_id', 'storeId'], ''));
        $boundHotelIds = array_values(array_filter(array_unique([
            trim((string)($context['poi_id'] ?? '')),
            trim((string)($context['store_id'] ?? '')),
        ]), static fn(string $value): bool => $value !== ''));
        $matchesBoundHotel = $itemHotelId !== '' && in_array($itemHotelId, $boundHotelIds, true);
        $explicitSelf = null;
        foreach (['is_self', 'isSelf', 'self'] as $selfKey) {
            if (array_key_exists($selfKey, $item)) {
                $explicitSelf = $this->meituanBool($item[$selfKey]);
                break;
            }
        }
        if ($explicitSelf !== null && $itemHotelId !== '' && $explicitSelf !== $matchesBoundHotel) {
            throw new InvalidArgumentException('meituan_peer_rank_identity_conflict', 409);
        }
        $compareType = ($matchesBoundHotel || $explicitSelf === true) ? 'self' : 'competitor';
        $factSource = array_merge($item, [
            'rank' => $rank > 0 ? $rank : null,
            'rankType' => $rankType,
            'dateRange' => $dateRange,
            'percent' => $percent,
            'metricStatus' => $dataValue !== null
                ? 'platform_value_returned'
                : ($percent !== null ? 'platform_percent_only' : 'platform_rank_only'),
        ]);

        return $this->baseMeituanCapturedRow($factSource, $context, [
            'data_date' => $dataDate,
            'amount' => 0,
            'quantity' => 0,
            'book_order_num' => 0,
            'comment_score' => 0,
            'data_value' => $dataValue,
            'data_type' => 'peer_rank',
            'dimension' => 'peer_rank:' . ($rankType !== '' ? $rankType : 'unknown') . ':range=' . ($dateRange !== '' ? $dateRange : 'unknown') . ':' . $metric,
            'platform' => 'Meituan',
            'compare_type' => $compareType,
        ]);
    }

    private function normalizeMeituanCapturedTrafficAnalysisRow(array $item, array $context): ?array
    {
        $listExposure = (int)$this->meituanNumber($item, ['list_exposure', 'listExposure', 'exposeCount', 'exposureCount', 'exposureUV', 'exposure'], 0);
        $detailExposure = (int)$this->meituanNumber($item, ['detail_exposure', 'detailExposure', 'visitCount', 'visitorCount', 'intentionUV', 'uv', 'pv', 'views'], 0);
        $orderSubmit = (int)$this->meituanNumber($item, ['order_submit_num', 'orderSubmitNum', 'orderCount', 'payOrderCount', 'payOrderCnt', 'orders'], 0);
        $orderFillingValue = $this->nullableNumberFromKeys($item, ['order_filling_num', 'orderFillingNum', 'clickCount', 'clicks']);
        $orderFilling = $orderFillingValue === null ? null : (int)$orderFillingValue;
        $flowRate = $this->normalizeMeituanPercentValue($this->firstMeituanValue($item, ['flow_rate', 'flowRate', 'intentionPerExposure', 'visitOrderRate', 'conversionRate', 'orderConversionRate'], null));
        $dataValue = $this->meituanNumber($item, ['data_value', 'dataValue', 'value', 'metric_value'], 0.0);
        if ($dataValue <= 0 && $flowRate !== null) {
            $dataValue = $flowRate;
        } elseif ($dataValue <= 0 && $detailExposure > 0) {
            $dataValue = (float)$detailExposure;
        }
        if ($dataValue <= 0 && $listExposure <= 0 && $detailExposure <= 0 && $orderSubmit <= 0 && ($orderFilling ?? 0) <= 0) {
            return null;
        }

        $dataDate = $this->normalizeOnlineDataDate($this->firstMeituanValue($item, ['data_date', 'dataDate', 'date', 'statDate', 'stat_date'], ''))
            ?: ($context['default_data_date'] ?? date('Y-m-d'));
        $analysisType = trim((string)$this->firstMeituanValue($item, ['analysis_type', 'analysisType', 'type'], 'flow_analysis'));
        $dimension = trim((string)$this->firstMeituanValue($item, ['dimension', 'name'], $analysisType));
        $dataType = strtolower(trim((string)$this->firstMeituanValue($item, ['data_type', 'dataType'], 'traffic_analysis'))) === 'traffic'
            ? 'traffic'
            : 'traffic_analysis';

        return $this->baseMeituanCapturedRow($item, $context, [
            'data_date' => $dataDate,
            'amount' => 0,
            'quantity' => 0,
            'book_order_num' => 0,
            'comment_score' => 0,
            'data_value' => $dataValue,
            'data_type' => $dataType,
            'dimension' => $dataType . ':' . ($dimension !== '' ? $dimension : $analysisType),
            'platform' => 'Meituan',
            'compare_type' => 'self',
            'list_exposure' => $listExposure,
            'detail_exposure' => $detailExposure,
            'flow_rate' => $flowRate ?? 0.0,
            'order_filling_num' => $orderFilling,
            'order_submit_num' => $orderSubmit,
        ]);
    }

    private function normalizeMeituanCapturedOrderFlowRow(array $item, array $context): ?array
    {
        $direction = strtolower(trim((string)$this->firstMeituanValue($item, ['order_flow_direction', 'orderFlowDirection', 'direction'], '')));
        $rowType = strtolower(trim((string)$this->firstMeituanValue($item, ['order_flow_row_type', 'orderFlowRowType', 'row_type', 'rowType'], '')));
        $period = strtolower(trim((string)$this->firstMeituanValue($item, ['order_flow_period', 'orderFlowPeriod', 'period'], '')));
        $periodStart = $this->normalizeOnlineDataDate($this->firstMeituanValue($item, ['period_start', 'periodStart', 'start_date', 'startDate'], ''));
        $periodEnd = $this->normalizeOnlineDataDate($this->firstMeituanValue($item, ['period_end', 'periodEnd', 'end_date', 'endDate', 'data_date', 'dataDate'], ''));
        if (!in_array($direction, ['loss', 'inflow'], true)
            || !in_array($rowType, ['summary', 'hotel_detail'], true)
            || !in_array($period, ['yesterday', 'last_7_days', 'last_30_days', 'custom'], true)
            || $periodStart === ''
            || $periodEnd === '') {
            return null;
        }

        $orderCount = $this->nullableNumberFromKeys($item, ['order_count', 'orderCount', 'lossTotalCnt', 'lossOrderCount']);
        $roomNights = $this->nullableNumberFromKeys($item, ['room_nights', 'roomNights', 'lossTotalPayRoomNight']);
        $amount = $this->nullableNumberFromKeys($item, ['amount', 'lossTotalPayAmount', 'lossSinglePayAmount']);
        $ratio = $this->nullableNumberFromKeys($item, ['order_ratio', 'orderRatio', 'lossOrderRatio']);
        if ($rowType === 'summary' && ($orderCount === null || $roomNights === null || $amount === null)) {
            return null;
        }
        if ($rowType === 'hotel_detail') {
            $competitorId = trim((string)$this->firstMeituanValue($item, ['poi_id', 'poiId'], ''));
            $competitorName = trim((string)$this->firstMeituanValue($item, ['poi_name', 'poiName', 'hotel_name', 'hotelName'], ''));
            if ($competitorId === '' && $competitorName === '') {
                return null;
            }
        }

        $dimension = trim((string)$this->firstMeituanValue($item, ['dimension'], ''));
        if ($dimension === '') {
            $identity = $rowType === 'summary'
                ? 'summary'
                : trim((string)$this->firstMeituanValue($item, ['poi_id', 'poiId', 'poi_name', 'poiName'], 'hotel'));
            $dimension = 'order_flow:' . $period . ':' . $direction . ':' . $identity;
        }

        $factSource = array_merge($item, [
            'order_flow_direction' => $direction,
            'order_flow_row_type' => $rowType,
            'order_flow_period' => $period,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'order_count' => $orderCount,
            'room_nights' => $roomNights,
            'amount' => $amount,
            'order_ratio' => $ratio,
        ]);

        return $this->baseMeituanCapturedRow($factSource, $context, [
            'data_date' => $periodEnd,
            // Order-flow values describe diverted/inflow demand, not this hotel's
            // realised revenue, room nights or orders. Keep them in raw_data and
            // the dedicated ETL fact so generic operating aggregators cannot
            // silently treat them as hotel performance.
            'amount' => null,
            'quantity' => null,
            'book_order_num' => null,
            'comment_score' => 0,
            'data_value' => $ratio,
            'data_type' => 'order_flow',
            'dimension' => $dimension,
            'platform' => 'Meituan',
            'compare_type' => $rowType === 'summary' ? 'self' : 'competitor',
        ]);
    }

    private function normalizeMeituanCapturedSearchKeywordRow(array $item, array $context): ?array
    {
        $keyword = trim((string)$this->firstMeituanValue($item, ['keyword', 'searchKeyword', 'searchWord', 'name', 'dimension'], ''));
        $dataValue = $this->meituanNumber($item, ['data_value', 'dataValue', 'value', 'heat', 'rank'], 0.0);
        $impressions = (int)$this->meituanNumber($item, ['impressions', 'exposure', 'exposure_count', 'exposureCount', 'listExposure'], 0);
        $clicks = (int)$this->meituanNumber($item, ['clicks', 'click_count', 'clickCount', 'detailExposure'], 0);
        if ($keyword === '' && $dataValue <= 0 && $impressions <= 0 && $clicks <= 0) {
            return null;
        }
        if ($dataValue <= 0 && $impressions > 0) {
            $dataValue = (float)$impressions;
        }

        $dataDate = $this->normalizeOnlineDataDate($this->firstMeituanValue($item, ['data_date', 'dataDate', 'date', 'statDate', 'stat_date'], ''))
            ?: ($context['default_data_date'] ?? date('Y-m-d'));

        return $this->baseMeituanCapturedRow($item, $context, [
            'data_date' => $dataDate,
            'amount' => 0,
            'quantity' => 0,
            'book_order_num' => 0,
            'comment_score' => 0,
            'data_value' => $dataValue,
            'data_type' => 'search_keyword',
            'dimension' => $keyword !== '' ? $keyword : 'search_keyword',
            'platform' => 'Meituan',
            'compare_type' => 'self',
            'list_exposure' => $impressions,
            'detail_exposure' => $clicks,
        ]);
    }

    private function normalizeMeituanCapturedTrafficForecastRow(array $item, array $context): ?array
    {
        $current = $this->meituanNumber($item, ['current', 'data_value', 'dataValue', 'value', 'metric_value'], 0.0);
        $peerAvg = $this->meituanNumber($item, ['peer_avg', 'peerAvg', 'competitor_avg', 'competitorAvg'], 0.0);
        if ($current <= 0 && $peerAvg <= 0) {
            return null;
        }

        $dataDate = $this->normalizeOnlineDataDate($this->firstMeituanValue($item, ['data_date', 'dataDate', 'date', 'dateTime', 'statDate', 'stat_date'], ''))
            ?: ($context['default_data_date'] ?? date('Y-m-d'));
        $forecastType = trim((string)$this->firstMeituanValue($item, ['forecast_type', 'forecastType', 'type'], 'flow_forecast'));
        $dimension = trim((string)$this->firstMeituanValue($item, ['dimension', 'name'], $forecastType));

        return $this->baseMeituanCapturedRow($item, $context, [
            'data_date' => $dataDate,
            'amount' => 0,
            'quantity' => 0,
            'book_order_num' => 0,
            'comment_score' => 0,
            'data_value' => $current,
            'data_type' => 'traffic_forecast',
            'dimension' => 'traffic_forecast:' . ($dimension !== '' ? $dimension : $forecastType),
            'platform' => 'Meituan',
            'compare_type' => 'forecast',
        ]);
    }

    private function normalizeMeituanCapturedAdsRow(array $item, array $context): ?array
    {
        $exposureValue = $this->nullableNumberFromKeys($item, ['exposure_count', 'exposureCount', 'impression', 'impressions', 'exposure']);
        $clickValue = $this->nullableNumberFromKeys($item, ['click_count', 'clickCount', 'clickNum', 'clicks', 'click']);
        $spend = $this->nullableNumberFromKeys($item, ['amount', 'todayCost', 'cost', 'ad_cost', 'adCost', 'spend', 'consume', 'consumption']);
        $orderAmount = $this->nullableNumberFromKeys($item, ['order_amount', 'orderAmount', 'saleAmount', 'salesAmount', 'revenue', 'gmv']);
        $orderValue = $this->nullableNumberFromKeys($item, ['book_order_num', 'bookOrderNum', 'orderNum', 'order_count', 'orders', 'booking_count', 'bookingCount']);
        $exposure = $exposureValue !== null ? max(0, (int)$exposureValue) : null;
        $clicks = $clickValue !== null ? max(0, (int)$clickValue) : null;
        $orders = $orderValue !== null ? max(0, (int)$orderValue) : null;
        $conversion = $this->normalizeMeituanPercentValue($this->firstMeituanValue($item, ['conversion_rate', 'conversionRate', 'flowRate', 'orderRate'], null));
        if ($conversion === null && $orders !== null && $clicks !== null && ($clicks > 0 || $orders === 0)) {
            $conversion = CtripTrafficDisplayService::trafficRate((float)$orders, (float)$clicks);
        }
        $roasValue = $this->firstMeituanValue($item, ['roas', 'roi'], null);
        $roas = $roasValue !== null ? max(0.0, $this->meituanNumber($item, ['roas', 'roi'], 0.0)) : null;
        if ($roas === null && $spend !== null && $spend > 0 && $orderAmount !== null && $orderAmount > 0) {
            $roas = $orderAmount / $spend;
        }

        if ($exposure === null && $clicks === null && $spend === null && $orderAmount === null && $orders === null && $roas === null) {
            return null;
        }

        $dataDate = $this->normalizeOnlineDataDate($this->firstMeituanValue($item, ['data_date', 'dataDate', 'date', 'statDate', 'stat_date'], ''))
            ?: ($context['default_data_date'] ?? date('Y-m-d'));
        $factSource = array_merge($item, array_filter([
            'spend' => $spend,
            'order_amount' => $orderAmount,
            'book_order_num' => $orders,
        ], static fn($value): bool => $value !== null), $roas !== null ? ['roas' => $roas] : []);
        $identityParts = [];
        foreach ([
            'ad' => ['adId', 'ad_id'],
            'campaign' => ['campaignId', 'campaign_id'],
            'plan' => ['planId', 'plan_id'],
        ] as $identityType => $identityKeys) {
            $identityValue = trim((string)$this->firstMeituanValue($item, $identityKeys, ''));
            if ($identityValue !== '') {
                $identityParts[] = $identityType . '=' . $identityValue;
            }
        }
        if ($identityParts !== []) {
            $adDimension = 'ads:identity:' . substr(hash('sha256', 'meituan_ads|' . implode('|', $identityParts)), 0, 24);
        } else {
            $factSource['ad_identity_status'] = 'missing_stable_id';
            $fingerprintSource = $this->canonicalizeMeituanAdsFingerprintValue(
                $this->sanitizeOnlineOrderRawData($item)
            );
            $adDimension = 'ads:unidentified:' . substr(hash(
                'sha256',
                'meituan_ads_unidentified|' . json_encode($fingerprintSource, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
            ), 0, 24);
        }

        return $this->baseMeituanCapturedRow($factSource, $context, [
            'data_date' => $dataDate,
            'amount' => $spend,
            'quantity' => null,
            'book_order_num' => $orders,
            'comment_score' => 0,
            'data_value' => $roas !== null ? round($roas, 2) : null,
            'data_type' => 'advertising',
            'dimension' => $adDimension,
            'platform' => 'Meituan',
            'compare_type' => 'self',
            'list_exposure' => $exposure,
            'detail_exposure' => $clicks,
            'flow_rate' => $conversion !== null ? round($conversion, 2) : null,
            'order_filling_num' => $clicks,
            'order_submit_num' => $orders,
        ]);
    }

    private function canonicalizeMeituanAdsFingerprintValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalizeMeituanAdsFingerprintValue($item), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalizeMeituanAdsFingerprintValue($item);
        }
        return $value;
    }

    private function normalizeMeituanCapturedReviewRow(array $item, array $context): ?array
    {
        $scoreKeys = [
            'comment_score',
            'commentScore',
            'score',
            'rating',
            'rate',
            'totalScore',
            'overallScore',
            'star',
        ];
        $scoreValue = $this->nullableNumberFromKeys($item, $scoreKeys);
        $score = $scoreValue !== null ? $this->normalizeMeituanScore($scoreValue) : 0.0;
        $scorePresent = $score > 0;
        $commentCountKeys = [
            'comment_count',
            'commentCount',
            'commentsCount',
            'review_count',
            'reviewCount',
            'totalCommentCount',
            'totalCount',
            'allCount',
            'quantity',
        ];
        $badReviewCountKeys = [
            'bad_review_count',
            'badReviewCount',
            'negativeCommentCount',
            'negativeCount',
            'badCount',
            'lowScoreCount',
            'noRecommendCount',
        ];
        $commentCountValue = $this->nullableNumberFromKeys($item, $commentCountKeys);
        $badReviewCountValue = $this->nullableNumberFromKeys($item, $badReviewCountKeys);
        $commentCountKnown = $commentCountValue !== null;
        $badReviewCountKnown = $badReviewCountValue !== null;
        $commentCount = $commentCountKnown ? max(0, (int)$commentCountValue) : null;
        $badReviewCount = $badReviewCountKnown ? max(0, (int)$badReviewCountValue) : null;
        if ($score <= 0 && !$commentCountKnown && !$badReviewCountKnown) {
            return null;
        }

        $dataDate = $this->normalizeOnlineDataDate($this->firstMeituanValue($item, ['data_date', 'dataDate', 'date', 'statDate', 'stat_date', 'commentTime', 'comment_time', 'reviewTime', 'review_time', 'createTime', 'create_time', 'submitTime', 'submit_time'], ''))
            ?: ($context['default_data_date'] ?? date('Y-m-d'));
        $channel = trim((string)$this->firstMeituanValue($item, ['channel', 'channelName', 'platform', 'source', 'commentChannel', 'bizType'], 'meituan'));
        $dimension = $channel !== '' ? 'review:' . $channel : 'review:meituan';

        $normalizedMetrics = [
            'data_date' => $dataDate,
            'dimension' => $dimension,
            'comment_score_status' => $scorePresent ? 'available' : 'missing',
            'comment_score_present' => $scorePresent,
        ];
        if ($scorePresent) {
            $normalizedMetrics['comment_score'] = $score;
        }
        if ($commentCountKnown) {
            $normalizedMetrics['comment_count'] = $commentCount;
        }
        if ($badReviewCountKnown) {
            $normalizedMetrics['bad_review_count'] = $badReviewCount;
        }
        $factSource = array_merge($item, $normalizedMetrics);
        if (!$scorePresent) {
            foreach ($scoreKeys as $scoreKey) {
                unset($factSource[$scoreKey]);
            }
        }
        $factSource = $this->sanitizeOnlineReviewRawData($factSource);

        return $this->baseMeituanCapturedRow($factSource, $context, [
            'data_date' => $dataDate,
            'amount' => 0,
            'quantity' => $commentCount,
            'book_order_num' => 0,
            'comment_score' => $score,
            'data_value' => $badReviewCount,
            'data_type' => 'review',
            'dimension' => $dimension,
            'platform' => 'Meituan',
            'compare_type' => 'self',
        ]);
    }

    private function normalizeMeituanCapturedOrderRow(array $item, array $context): ?array
    {
        $orderId = (string)$this->firstMeituanValue($item, ['order_id', 'orderId', 'orderNo', 'order_no', 'orderNumber', 'order_number', 'bookingNo', 'booking_no', 'bookingNumber', 'id'], '');
        $orderIdHash = $this->meituanCapturedOrderIdentifierHash($item);
        $status = (string)$this->firstMeituanValue($item, ['order_status', 'orderStatus', 'status'], 'unknown');
        $amount = $this->nullableNumberFromKeys($item, ['total_amount', 'totalAmount', 'amount', 'payAmount', 'pay_amount']);
        $basePrice = $this->nullableNumberFromKeys($item, ['base_price', 'basePrice', 'bottom_price', 'bottomPrice', 'price', '底价', '底价(元)']);
        $roomCountValue = $this->nullableNumberFromKeys($item, ['room_count', 'roomCount', 'rooms']);
        $roomCount = $roomCountValue !== null ? max(0, (int)$roomCountValue) : null;
        $nightsValue = $this->nullableNumberFromKeys($item, ['nights', 'night_count', 'nightCount']);
        $nights = $nightsValue !== null ? max(0, (int)$nightsValue) : null;
        $roomNightsValue = $this->nullableNumberFromKeys($item, ['room_nights', 'roomNights', 'quantity']);
        $roomNights = $roomNightsValue !== null ? max(0, (int)$roomNightsValue) : null;
        $orderCountValue = $this->nullableNumberFromKeys($item, ['order_count', 'orderCount', 'book_order_num', 'bookOrderNum', 'orders']);
        $orderCount = $orderCountValue !== null ? max(0, (int)$orderCountValue) : null;
        if ($nights === null) {
            $calculatedNights = $this->calculateMeituanOrderNights($item);
            $nights = $calculatedNights > 0 ? $calculatedNights : null;
        }

        $aggregateOnly = $orderId === '' && $orderIdHash === '' && ($orderCount !== null || $roomNights !== null);
        if ($orderId === '' && $orderIdHash === '' && !$aggregateOnly && ($amount === null || $amount <= 0)) {
            return null;
        }

        $dataDate = $this->normalizeOnlineDataDate($this->firstMeituanValue($item, ['order_time', 'orderTime', 'createTime', 'buyTime', 'purchase_time', 'purchaseTime', '购买时间', 'check_in_date', 'checkInDate', 'checkIn', 'data_date', 'dataDate', 'statDate', 'date'], ''))
            ?: ($context['default_data_date'] ?? date('Y-m-d'));
        if ($orderIdHash !== '') {
            $identity = $orderIdHash;
        } elseif ($orderId !== '') {
            $identity = $this->hashOnlineOrderIdentifier($orderId);
        } elseif ($aggregateOnly) {
            $sourcePath = trim((string)$this->firstMeituanValue($item, ['_source_path', 'source_path', 'sourcePath'], 'daily_summary'));
            $identity = hash('sha256', 'ota_order_aggregate|' . $status . '|' . ($sourcePath !== '' ? $sourcePath : 'daily_summary'));
        } else {
            $identity = hash('sha256', 'ota_order_fallback|' . json_encode($item, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        }
        $avgPrice = $this->nullableNumberFromKeys($item, ['avg_price', 'avgPrice']);
        if (($avgPrice === null || $avgPrice <= 0) && $basePrice !== null && $basePrice > 0) {
            $avgPrice = $basePrice;
        } elseif (($avgPrice === null || $avgPrice <= 0) && $amount !== null && $amount > 0 && $roomCount !== null && $roomCount > 0 && $nights !== null && $nights > 0) {
            $avgPrice = round($amount / ($roomCount * $nights), 2);
        }

        $quantity = $roomCount !== null && $roomCount > 0 && $nights !== null && $nights > 0
            ? $roomCount * $nights
            : $roomNights;

        return $this->baseMeituanCapturedRow($item, $context, [
            'data_date' => $dataDate,
            'amount' => $amount !== null ? round($amount, 2) : null,
            'quantity' => $quantity,
            'book_order_num' => $orderCount,
            'comment_score' => 0,
            'data_value' => $avgPrice,
            'data_type' => 'order',
            'dimension' => $aggregateOnly
                ? 'order:aggregate:' . $identity
                : 'order:' . $status . ':' . $identity,
            'platform' => 'Meituan',
            'compare_type' => 'self',
        ]);
    }

    private function meituanCapturedOrderIdentifierHash(array $item): string
    {
        foreach (['order_id_hash', 'order_no_hash', 'booking_id_hash'] as $key) {
            $value = strtolower(trim((string)($item[$key] ?? '')));
            if (preg_match('/^[a-f0-9]{64}$/D', $value) === 1) {
                return $value;
            }
        }
        return '';
    }

    private function baseMeituanCapturedRow(array $item, array $context, array $fields): array
    {
        $itemHotelId = trim((string)$this->firstMeituanValue($item, ['poi_id', 'poiId', 'hotel_id', 'hotelId', 'shopId', 'shop_id'], ''));
        $boundHotelIds = array_values(array_filter(array_unique([
            trim((string)($context['poi_id'] ?? '')),
            trim((string)($context['store_id'] ?? '')),
        ]), static fn(string $value): bool => $value !== ''));
        if (($fields['compare_type'] ?? 'self') === 'self'
            && $itemHotelId !== ''
            && $boundHotelIds !== []
            && !in_array($itemHotelId, $boundHotelIds, true)) {
            throw new InvalidArgumentException('美团门店标识与当前酒店绑定不一致');
        }
        $hotelId = $itemHotelId !== '' ? $itemHotelId : (string)($context['poi_id'] ?: $context['store_id']);
        $hotelName = (string)$this->firstMeituanValue($item, ['poi_name', 'poiName', 'hotel_name', 'hotelName', 'shopName', 'shop_name', 'name'], $context['poi_name']);
        $dataType = (string)($fields['data_type'] ?? '');
        $raw = $dataType === 'review'
            ? $this->sanitizeOnlineReviewRawData($item)
            : $this->sanitizeOnlineOrderRawData($item, $dataType === 'order');
        $raw['_capture_context'] = array_filter([
            'store_id' => $context['store_id'] ?? '',
            'poi_id' => $context['poi_id'] ?? '',
            'captured_at' => $context['captured_at'] ?? '',
        ], static fn($value): bool => $value !== null && $value !== '');

        $row = array_merge([
            'hotel_id' => $hotelId,
            'hotel_name' => $hotelName,
            'system_hotel_id' => $context['system_hotel_id'] ?? null,
            'source' => 'meituan',
            'qunar_comment_score' => 0,
            'raw_data' => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ], array_filter([
            'data_period' => $context['data_period'] ?? '',
            'snapshot_time' => $context['snapshot_time'] ?? '',
        ], static fn($value): bool => $value !== null && $value !== ''), $fields);

        return OnlineDataFieldFactService::attachToOnlineDailyRow($row, $item);
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function sanitizeOnlineOrderRawData(array $raw, bool $orderContext = false): array
    {
        $sanitized = [];
        foreach ($raw as $key => $value) {
            $keyText = (string)$key;
            if ($this->isOnlineSensitiveConfigKey($keyText)) {
                continue;
            }

            $childOrderContext = $orderContext || $this->isOnlineOrderContainerKey($keyText);
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeOnlineOrderRawData($value, $childOrderContext);
                continue;
            }

            if ($childOrderContext || $this->isOnlineOrderPiiKey($keyText)) {
                $this->appendRedactedOnlineOrderField($sanitized, $keyText, $value, $childOrderContext);
                continue;
            }

            $sanitized[$key] = $value;
        }
        return $sanitized;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function sanitizeOnlineReviewRawData(array $raw): array
    {
        $sanitized = [];
        foreach ($raw as $key => $value) {
            $keyText = (string)$key;
            if (in_array(strtolower($keyText), ['review_id_hash', 'comment_id_hash'], true)
                && preg_match('/^[a-f0-9]{64}$/D', strtolower(trim((string)$value))) === 1) {
                $sanitized['review_id_hash'] = strtolower(trim((string)$value));
                continue;
            }
            if ($this->isOnlineReviewIdKey($keyText)) {
                $text = trim((string)$value);
                if ($text !== '') {
                    $sanitized['review_id_hash'] = $this->hashOnlineOrderIdentifier($text);
                }
                continue;
            }
            if ($this->isOnlineSensitiveConfigKey($keyText) || $this->isOnlineReviewPrivateKey($keyText)) {
                continue;
            }
            if (is_array($value)) {
                $nested = $this->sanitizeOnlineReviewRawData($value);
                if (!empty($nested)) {
                    $sanitized[$key] = $nested;
                }
                continue;
            }
            $sanitized[$key] = $value;
        }
        return $sanitized;
    }

    private function isOnlineReviewIdKey(string $key): bool
    {
        return preg_match('/^(review|comment)[_-]?(id|no|number)$/i', $key) === 1;
    }

    private function isOnlineReviewPrivateKey(string $key): bool
    {
        return preg_match('/content|commentContent|comment_text|review_text|review[_-]?id|comment[_-]?id|reply|guest|customer|userName|username|nick|phone|mobile|tel|certificate|idcard|id_card|identity|openid|avatar|order[_-]?(id|no|number)|room(type|name)|photo|image|pic/i', $key) === 1;
    }

    /**
     * @param array<string|int, mixed> $target
     */
    private function appendRedactedOnlineOrderField(array &$target, string $key, mixed $value, bool $orderContext): void
    {
        $normalizedKey = strtolower($key);
        if (in_array($normalizedKey, ['order_id_hash', 'order_no_hash', 'booking_id_hash'], true)) {
            $hash = strtolower(trim((string)$value));
            if (preg_match('/^[a-f0-9]{64}$/D', $hash) === 1) {
                $target[$normalizedKey] = $hash;
            }
            return;
        }
        if ($this->isOnlineOrderIdKey($key, $orderContext)) {
            $text = trim((string)$value);
            if ($text !== '') {
                $target[$this->redactedOnlineOrderFieldName($key, 'hash')] = $this->hashOnlineOrderIdentifier($text);
            }
            return;
        }
        if ($this->isOnlinePhoneKey($key)) {
            $masked = $this->maskOnlinePhone((string)$value);
            if ($masked !== '') {
                $target[$this->redactedOnlineOrderFieldName($key, 'masked')] = $masked;
            }
            return;
        }
        if ($this->isOnlineGuestNameKey($key, $orderContext)) {
            $masked = $this->maskOnlineName((string)$value);
            if ($masked !== '') {
                $target[$this->redactedOnlineOrderFieldName($key, 'masked')] = $masked;
            }
            return;
        }
        if ($this->isOnlineSensitiveOrderTextKey($key)) {
            return;
        }

        $target[$key] = $value;
    }

    private function isOnlineSensitiveConfigKey(string $key): bool
    {
        return preg_match('/cookie|token|authorization|mtgsig|password|secret|spidertoken|csrf|session/i', $key) === 1;
    }

    private function isOnlineOrderContainerKey(string $key): bool
    {
        return preg_match('/order[_-]?(list|rows|items|data|detail|details|info)|orders/i', $key) === 1;
    }

    private function isOnlineOrderPiiKey(string $key): bool
    {
        return $this->isOnlineOrderIdKey($key, false)
            || $this->isOnlinePhoneKey($key)
            || $this->isOnlineGuestNameKey($key, false)
            || $this->isOnlineSensitiveOrderTextKey($key);
    }

    private function isOnlineOrderIdKey(string $key, bool $orderContext): bool
    {
        if ($orderContext && preg_match('/^(id|sn)$/i', $key) === 1) {
            return true;
        }
        return preg_match('/^(order[_-]?(id|no|num|number|sn)|booking[_-]?(id|no|number))$/i', $key) === 1;
    }

    private function isOnlinePhoneKey(string $key): bool
    {
        return preg_match('/(phone|mobile|tel)$/i', $key) === 1;
    }

    private function isOnlineGuestNameKey(string $key, bool $orderContext): bool
    {
        if ($orderContext && preg_match('/^(name|full[_-]?name)$/i', $key) === 1) {
            return true;
        }
        return preg_match('/(guest|customer|contact|user|traveller|passenger)[_-]?name$/i', $key) === 1;
    }

    private function isOnlineSensitiveOrderTextKey(string $key): bool
    {
        return preg_match('/(certificate|credential|id[_-]?card|card[_-]?no|passport|remark|memo|note|address)/i', $key) === 1;
    }

    private function redactedOnlineOrderFieldName(string $key, string $suffix): string
    {
        if ($this->isOnlineOrderIdKey($key, true)) {
            return 'order_id_hash';
        }
        $name = preg_replace('/(?<!^)[A-Z]/', '_$0', $key) ?? $key;
        $name = strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '_', $name));
        $name = trim($name, '_');
        return ($name !== '' ? $name : 'field') . '_' . $suffix;
    }

    private function hashOnlineOrderIdentifier(string $value): string
    {
        return hash('sha256', 'ota_order|' . $value);
    }

    private function maskOnlinePhone(string $value): string
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

    private function maskOnlineName(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        return mb_substr($value, 0, 1) . '***';
    }

    private function saveMeituanCapturedDailyRows(array $rows): int
    {
        $columns = $this->getOnlineDailyDataColumns();
        $savedCount = 0;
        $now = date('Y-m-d H:i:s');

        foreach ($this->uniqueMeituanCapturedRowsForPersistence($rows) as $row) {
            if (!is_array($row) || empty($row['data_date']) || empty($row['data_type'])) {
                continue;
            }
            $row = OnlineDataFieldFactService::attachToOnlineDailyRow($row);
            $row = $this->applyOnlineDailyDataPeriodFields($row, $columns, $row);
            $row = OnlineDailyDataPersistenceService::applyTenantScope($row, $columns);

            if (isset($columns['update_time'])) {
                $row['update_time'] = $now;
            }

            $query = Db::name('online_daily_data')
                ->where('source', 'meituan')
                ->where('data_type', (string)$row['data_type'])
                ->where('data_date', (string)$row['data_date'])
                ->where('dimension', (string)($row['dimension'] ?? ''));
            $this->applyOnlineDailyDataPeriodQuery($query, $row, $columns);

            if (!empty($row['hotel_id'])) {
                $query->where('hotel_id', (string)$row['hotel_id']);
            } else {
                $query->where('hotel_name', (string)($row['hotel_name'] ?? ''));
            }

            if (array_key_exists('system_hotel_id', $row) && $row['system_hotel_id'] !== null) {
                $query->where('system_hotel_id', (int)$row['system_hotel_id']);
            } else {
                $query->whereNull('system_hotel_id');
            }

            $exists = $query->find();
            if (!$exists && isset($columns['create_time'])) {
                $row['create_time'] = $now;
            }

            $data = array_intersect_key($this->applyOnlineDailyDataValidationFields($row, $columns), $columns);
            $data = OnlineDailyDataPersistenceService::resetReadbackVerification($data, $columns);
            if ($exists) {
                $rowId = (int)$exists['id'];
                Db::name('online_daily_data')->where('id', $rowId)->update($data);
            } else {
                $rowId = (int)Db::name('online_daily_data')->insertGetId($data);
            }
            $readbackRow = $rowId > 0
                ? $this->verifiedMeituanCapturedDailyRowReadback($rowId, $data)
                : null;
            if (is_array($readbackRow)
                && OnlineDailyDataPersistenceService::markRowsReadbackVerified([$readbackRow], $columns)) {
                $savedCount++;
            }
        }

        return $savedCount;
    }

    /** @return array<int,array<string,mixed>> */
    private function uniqueMeituanCapturedRowsForPersistence(array $rows): array
    {
        $unique = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $locator = json_encode([
                'source' => (string)($row['source'] ?? ''),
                'data_type' => (string)($row['data_type'] ?? ''),
                'data_date' => (string)($row['data_date'] ?? ''),
                'dimension' => (string)($row['dimension'] ?? ''),
                'hotel_id' => (string)($row['hotel_id'] ?? ''),
                'hotel_name' => (string)($row['hotel_name'] ?? ''),
                'system_hotel_id' => $row['system_hotel_id'] ?? null,
                'data_period' => (string)($row['data_period'] ?? ''),
                'snapshot_time' => (string)($row['snapshot_time'] ?? ''),
                'snapshot_bucket' => (string)($row['snapshot_bucket'] ?? ''),
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if (isset($seen[$locator])) {
                continue;
            }
            $seen[$locator] = true;
            $unique[] = $row;
        }
        return $unique;
    }

    private function verifiedMeituanCapturedDailyRowReadback(int $rowId, array $expected): ?array
    {
        $persisted = Db::name('online_daily_data')->where('id', $rowId)->find();
        if (!is_array($persisted)) {
            return null;
        }
        return $this->meituanCapturedRowMatchesReadback($persisted, $expected) ? $persisted : null;
    }

    private function meituanCapturedRowMatchesReadback(array $persisted, array $expected): bool
    {
        foreach (['tenant_id', 'source', 'data_type', 'data_date', 'dimension'] as $field) {
            if ((string)($persisted[$field] ?? '') !== (string)($expected[$field] ?? '')) {
                return false;
            }
        }
        if (!empty($expected['hotel_id'])) {
            if ((string)($persisted['hotel_id'] ?? '') !== (string)$expected['hotel_id']) {
                return false;
            }
        } elseif ((string)($persisted['hotel_name'] ?? '') !== (string)($expected['hotel_name'] ?? '')) {
            return false;
        }

        $numericFields = [
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
        foreach ($numericFields as $field) {
            if (!array_key_exists($field, $expected)) {
                continue;
            }
            $expectedValue = $expected[$field];
            $persistedValue = $persisted[$field] ?? null;
            if ($expectedValue === null || $expectedValue === '') {
                if ($persistedValue !== null && $persistedValue !== '') {
                    return false;
                }
                continue;
            }
            if (!is_numeric($persistedValue)
                || !$this->meituanCapturedNumericReadbackMatches($field, (float)$persistedValue, (float)$expectedValue)) {
                return false;
            }
        }

        $expectedSystemHotelId = $expected['system_hotel_id'] ?? null;
        $persistedSystemHotelId = $persisted['system_hotel_id'] ?? null;
        $systemHotelMatches = $expectedSystemHotelId === null
            ? $persistedSystemHotelId === null
            : (int)$persistedSystemHotelId === (int)$expectedSystemHotelId;
        if (!$systemHotelMatches) {
            return false;
        }

        // Reuse the shared identity/raw-fact contract after applying Meituan's
        // field-specific DECIMAL rounding rules above. This keeps facts that
        // live only in raw_data and source trace/period scope in the proof.
        foreach ($numericFields as $field) {
            unset($persisted[$field], $expected[$field]);
        }
        return OnlineDailyDataPersistenceService::matchesBusinessReadback($persisted, $expected);
    }

    private function meituanCapturedNumericReadbackMatches(string $field, float $persistedValue, float $expectedValue): bool
    {
        $scale = match ($field) {
            'comment_score', 'qunar_comment_score' => 1,
            'amount', 'data_value', 'flow_rate' => 2,
            default => 0,
        };
        $persistedValue = round($persistedValue, $scale);
        $expectedValue = round($expectedValue, $scale);
        return abs($persistedValue - $expectedValue) < (10 ** (-$scale - 2));
    }

    private function summarizeMeituanCapturedRows(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $type = (string)($row['data_type'] ?? 'unknown');
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }
        ksort($counts);
        return $counts;
    }
}
