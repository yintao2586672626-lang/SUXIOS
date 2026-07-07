<?php

namespace app\controller\concern;

use app\service\CtripTrafficDisplayService;
use app\service\OnlineDataFieldFactService;
use think\facade\Db;

trait MeituanCapturedDataConcern
{
    private function buildMeituanCapturedDailyRows(array $payload, ?int $systemHotelId = null): array
    {
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

        foreach ($this->extractMeituanCapturedSection($payload, 'ads') as $item) {
            $row = $this->normalizeMeituanCapturedAdsRow($item, $context);
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
            'search_keyword' => ['keyword', 'searchKeyword', 'searchWord', 'itemList', 'keywords'],
            'traffic_forecast' => ['forecast_type', 'forecastType', 'current', 'peerAvg', 'dateTime'],
            'ads' => ['cureShops', 'exposure_count', 'click_count', 'adId', 'campaignId'],
            'orders' => ['order_id', 'orderId', 'orderNo', 'order_no', 'orderStatus', 'order_status', 'total_amount', 'totalAmount', 'buyTime', 'checkIn', 'checkOut', 'basePrice', 'bottomPrice'],
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
        $exposure = (int)$this->meituanNumber($item, ['exposure_count', 'exposureCount', 'listExposure', 'impression', 'impressions', 'exposure'], 0);
        $pageViews = (int)$this->meituanNumber($item, ['page_views', 'pageViews', 'detailExposure', 'detailVisitors', 'unique_visitors', 'uniqueVisitors', 'visitor_count', 'visitorCount', 'uv', 'UV', 'pv', 'views'], 0);
        $clicks = (int)$this->meituanNumber($item, ['click_count', 'clickCount', 'clickNum', 'clicks', 'click'], 0);
        $conversion = $this->normalizeMeituanPercentValue($this->firstMeituanValue($item, ['conversion_rate', 'conversionRate', 'flowRate', 'orderRate'], null));
        if ($conversion === null) {
            $conversion = CtripTrafficDisplayService::trafficRate((float)($pageViews ?: $clicks), (float)$exposure);
        }

        if ($exposure <= 0 && $pageViews <= 0 && $clicks <= 0) {
            return null;
        }

        $dataDate = $this->normalizeOnlineDataDate($this->firstMeituanValue($item, ['data_date', 'dataDate', 'date', 'statDate', 'stat_date'], ''))
            ?: ($context['default_data_date'] ?? date('Y-m-d'));

        return $this->baseMeituanCapturedRow($item, $context, [
            'data_date' => $dataDate,
            'amount' => 0,
            'quantity' => 0,
            'book_order_num' => 0,
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
            'order_submit_num' => (int)$this->meituanNumber($item, ['order_submit_num', 'orderSubmitNum', 'submit_users', 'submitUsers'], 0),
        ]);
    }

    private function normalizeMeituanCapturedPeerRankRow(array $item, array $context): ?array
    {
        $rank = (int)$this->meituanNumber($item, ['rank', 'rank_no', 'rankNo', 'currentRank', 'sort'], 0);
        $dataValue = $this->meituanNumber($item, ['data_value', 'dataValue', 'value', 'metric_value'], 0.0);
        if ($dataValue <= 0 && $rank > 0) {
            $dataValue = (float)$rank;
        }
        $percent = $this->normalizeMeituanPercentValue($this->firstMeituanValue($item, ['percent', 'ratio', 'rank_percent', 'rankPercent'], null));
        if ($dataValue <= 0 && $percent === null) {
            return null;
        }

        $dataDate = $this->normalizeOnlineDataDate($this->firstMeituanValue($item, ['data_date', 'dataDate', 'date', 'statDate', 'stat_date'], ''))
            ?: ($context['default_data_date'] ?? date('Y-m-d'));
        $rankType = trim((string)$this->firstMeituanValue($item, ['rank_type', 'rankType', 'type', 'rankListType'], ''));
        $metric = trim((string)$this->firstMeituanValue($item, ['dimension', 'dimName', '_dimName', 'metricName', 'aiMetricName'], 'peer_rank'));
        $compareType = $this->meituanBool($this->firstMeituanValue($item, ['is_self', 'isSelf', 'self'], false)) ? 'self' : 'competitor';

        return $this->baseMeituanCapturedRow($item, $context, [
            'data_date' => $dataDate,
            'amount' => 0,
            'quantity' => 0,
            'book_order_num' => 0,
            'comment_score' => 0,
            'data_value' => $dataValue,
            'data_type' => 'peer_rank',
            'dimension' => 'peer_rank:' . ($rankType !== '' ? $rankType : 'unknown') . ':' . $metric,
            'platform' => 'Meituan',
            'compare_type' => $compareType,
        ]);
    }

    private function normalizeMeituanCapturedTrafficAnalysisRow(array $item, array $context): ?array
    {
        $listExposure = (int)$this->meituanNumber($item, ['list_exposure', 'listExposure', 'exposeCount', 'exposureCount', 'exposure'], 0);
        $detailExposure = (int)$this->meituanNumber($item, ['detail_exposure', 'detailExposure', 'visitCount', 'visitorCount', 'uv', 'pv', 'views'], 0);
        $orderSubmit = (int)$this->meituanNumber($item, ['order_submit_num', 'orderSubmitNum', 'orderCount', 'payOrderCount', 'orders'], 0);
        $orderFilling = (int)$this->meituanNumber($item, ['order_filling_num', 'orderFillingNum', 'clickCount', 'clicks'], 0);
        $flowRate = $this->normalizeMeituanPercentValue($this->firstMeituanValue($item, ['flow_rate', 'flowRate', 'visitOrderRate', 'conversionRate', 'orderConversionRate'], null));
        $dataValue = $this->meituanNumber($item, ['data_value', 'dataValue', 'value', 'metric_value'], 0.0);
        if ($dataValue <= 0 && $flowRate !== null) {
            $dataValue = $flowRate;
        } elseif ($dataValue <= 0 && $detailExposure > 0) {
            $dataValue = (float)$detailExposure;
        }
        if ($dataValue <= 0 && $listExposure <= 0 && $detailExposure <= 0 && $orderSubmit <= 0 && $orderFilling <= 0) {
            return null;
        }

        $dataDate = $this->normalizeOnlineDataDate($this->firstMeituanValue($item, ['data_date', 'dataDate', 'date', 'statDate', 'stat_date'], ''))
            ?: ($context['default_data_date'] ?? date('Y-m-d'));
        $analysisType = trim((string)$this->firstMeituanValue($item, ['analysis_type', 'analysisType', 'type'], 'flow_analysis'));
        $dimension = trim((string)$this->firstMeituanValue($item, ['dimension', 'name'], $analysisType));

        return $this->baseMeituanCapturedRow($item, $context, [
            'data_date' => $dataDate,
            'amount' => 0,
            'quantity' => 0,
            'book_order_num' => 0,
            'comment_score' => 0,
            'data_value' => $dataValue,
            'data_type' => 'traffic_analysis',
            'dimension' => 'traffic_analysis:' . ($dimension !== '' ? $dimension : $analysisType),
            'platform' => 'Meituan',
            'compare_type' => 'self',
            'list_exposure' => $listExposure,
            'detail_exposure' => $detailExposure,
            'flow_rate' => $flowRate ?? 0.0,
            'order_filling_num' => $orderFilling,
            'order_submit_num' => $orderSubmit,
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
        $exposure = (int)$this->meituanNumber($item, ['exposure_count', 'exposureCount', 'impression', 'impressions', 'exposure'], 0);
        $clicks = (int)$this->meituanNumber($item, ['click_count', 'clickCount', 'clickNum', 'clicks', 'click'], 0);
        $conversion = $this->normalizeMeituanPercentValue($this->firstMeituanValue($item, ['conversion_rate', 'conversionRate', 'flowRate', 'orderRate'], null));
        if ($conversion === null) {
            $conversion = CtripTrafficDisplayService::trafficRate((float)$clicks, (float)$exposure);
        }

        if ($exposure <= 0 && $clicks <= 0) {
            return null;
        }

        $dataDate = $this->normalizeOnlineDataDate($this->firstMeituanValue($item, ['data_date', 'dataDate', 'date', 'statDate', 'stat_date'], ''))
            ?: ($context['default_data_date'] ?? date('Y-m-d'));

        return $this->baseMeituanCapturedRow($item, $context, [
            'data_date' => $dataDate,
            'amount' => 0,
            'quantity' => 0,
            'book_order_num' => 0,
            'comment_score' => 0,
            'data_value' => $exposure,
            'data_type' => 'advertising',
            'dimension' => 'ads',
            'platform' => 'Meituan',
            'compare_type' => 'self',
            'list_exposure' => $exposure,
            'detail_exposure' => $clicks,
            'flow_rate' => round($conversion, 2),
            'order_filling_num' => $clicks,
            'order_submit_num' => 0,
        ]);
    }

    private function normalizeMeituanCapturedReviewRow(array $item, array $context): ?array
    {
        $score = $this->normalizeMeituanScore($this->firstMeituanValue($item, [
            'comment_score',
            'commentScore',
            'score',
            'rating',
            'rate',
            'totalScore',
            'overallScore',
            'star',
        ], 0));
        $commentCount = (int)$this->meituanNumber($item, [
            'comment_count',
            'commentCount',
            'commentsCount',
            'review_count',
            'reviewCount',
            'totalCommentCount',
            'totalCount',
            'allCount',
            'quantity',
        ], 0.0);
        $badReviewCount = (int)$this->meituanNumber($item, [
            'bad_review_count',
            'badReviewCount',
            'negativeCommentCount',
            'negativeCount',
            'badCount',
            'lowScoreCount',
            'noRecommendCount',
        ], 0.0);
        if ($commentCount <= 0 && $score > 0) {
            $commentCount = 1;
        }
        if ($badReviewCount <= 0 && $score > 0 && $score < 4) {
            $badReviewCount = 1;
        }
        if ($score <= 0 && $commentCount <= 0 && $badReviewCount <= 0) {
            return null;
        }

        $dataDate = $this->normalizeOnlineDataDate($this->firstMeituanValue($item, ['data_date', 'dataDate', 'date', 'statDate', 'stat_date', 'commentTime', 'comment_time', 'reviewTime', 'review_time', 'createTime', 'create_time', 'submitTime', 'submit_time'], ''))
            ?: ($context['default_data_date'] ?? date('Y-m-d'));
        $channel = trim((string)$this->firstMeituanValue($item, ['channel', 'channelName', 'platform', 'source', 'commentChannel', 'bizType'], 'meituan'));
        $dimension = $channel !== '' ? 'review:' . $channel : 'review:meituan';

        $factSource = $this->sanitizeOnlineReviewRawData(array_merge($item, [
            'comment_score' => $score,
            'comment_count' => $commentCount,
            'bad_review_count' => $badReviewCount,
            'data_date' => $dataDate,
            'dimension' => $dimension,
        ]));

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
        $status = (string)$this->firstMeituanValue($item, ['order_status', 'orderStatus', 'status'], 'unknown');
        $amount = $this->meituanNumber($item, ['total_amount', 'totalAmount', 'amount', 'payAmount', 'pay_amount'], 0.0);
        $basePrice = $this->meituanNumber($item, ['base_price', 'basePrice', 'bottom_price', 'bottomPrice', 'price', '底价', '底价(元)'], 0.0);
        $roomCount = (int)$this->meituanNumber($item, ['room_count', 'roomCount', 'rooms'], 1.0);
        $nights = (int)$this->meituanNumber($item, ['nights', 'night_count', 'nightCount'], 0.0);
        if ($nights <= 0) {
            $nights = $this->calculateMeituanOrderNights($item);
        }
        $roomCount = max(1, $roomCount);
        $nights = max(1, $nights);

        if ($orderId === '' && $amount <= 0) {
            return null;
        }

        $dataDate = $this->normalizeOnlineDataDate($this->firstMeituanValue($item, ['order_time', 'orderTime', 'createTime', 'buyTime', 'purchase_time', 'purchaseTime', '购买时间', 'check_in_date', 'checkInDate', 'checkIn'], ''))
            ?: ($context['default_data_date'] ?? date('Y-m-d'));
        $identity = $orderId !== ''
            ? $this->hashOnlineOrderIdentifier($orderId)
            : hash('sha256', 'ota_order_fallback|' . json_encode($item, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        $avgPrice = $this->meituanNumber($item, ['avg_price', 'avgPrice'], 0.0);
        if ($avgPrice <= 0 && $basePrice > 0) {
            $avgPrice = $basePrice;
        } elseif ($avgPrice <= 0 && $amount > 0) {
            $avgPrice = round($amount / ($roomCount * $nights), 2);
        }

        return $this->baseMeituanCapturedRow($item, $context, [
            'data_date' => $dataDate,
            'amount' => round($amount, 2),
            'quantity' => $roomCount * $nights,
            'book_order_num' => (int)$this->meituanNumber($item, ['order_count', 'orderCount'], 1.0),
            'comment_score' => 0,
            'data_value' => $avgPrice,
            'data_type' => 'order',
            'dimension' => 'order:' . $status . ':' . $identity,
            'platform' => 'Meituan',
            'compare_type' => 'self',
        ]);
    }

    private function baseMeituanCapturedRow(array $item, array $context, array $fields): array
    {
        $hotelId = (string)$this->firstMeituanValue($item, ['poi_id', 'poiId', 'hotel_id', 'hotelId', 'shopId', 'shop_id'], $context['poi_id'] ?: $context['store_id']);
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

    private function isOnlineReviewPrivateKey(string $key): bool
    {
        return preg_match('/content|commentContent|comment_text|review_text|review[_-]?id|comment[_-]?id|reply|guest|customer|userName|username|nick|phone|mobile|tel|certificate|idcard|id_card|identity|openid|avatar|order[_-]?(id|no|number)|room(type|name)|photo|image|pic/i', $key) === 1;
    }

    /**
     * @param array<string|int, mixed> $target
     */
    private function appendRedactedOnlineOrderField(array &$target, string $key, mixed $value, bool $orderContext): void
    {
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

        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['data_date']) || empty($row['data_type'])) {
                continue;
            }
            $row = OnlineDataFieldFactService::attachToOnlineDailyRow($row);
            $row = $this->applyOnlineDailyDataPeriodFields($row, $columns, $row);

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
            if ($exists) {
                Db::name('online_daily_data')->where('id', $exists['id'])->update($data);
            } else {
                Db::name('online_daily_data')->insert($data);
            }
            $savedCount++;
        }

        return $savedCount;
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
