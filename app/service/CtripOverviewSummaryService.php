<?php
declare(strict_types=1);

namespace app\service;

final class CtripOverviewSummaryService
{
    public static function summarizeRows(array $rows): array
    {
        $summary = [
            'yesterday_uv' => 0,
            'order_count' => 0,
            'amount' => 0.0,
            'room_nights' => 0,
            'avg_price' => 0.0,
            'conversion_rate' => 0.0,
            'competitor_uv' => 0,
            'competitor_orders' => 0,
            'competitor_amount' => 0.0,
            'psi' => 0.0,
            'hotel_score' => 0.0,
            'reply_rate' => 0.0,
            'favorite_count' => 0,
            'visitor_rank' => 0,
            'self_list_exposure' => 0,
            'self_detail_exposure' => 0,
            'self_order_filling_num' => 0,
            'self_order_submit_num' => 0,
            'self_flow_rate' => 0.0,
            'self_order_fill_rate' => 0.0,
            'self_deal_rate' => 0.0,
            'competitor_list_exposure' => 0,
            'competitor_detail_exposure' => 0,
            'competitor_order_filling_num' => 0,
            'competitor_order_submit_num' => 0,
            'competitor_flow_rate' => 0.0,
            'competitor_order_fill_rate' => 0.0,
            'competitor_deal_rate' => 0.0,
            'compete_hotel_count' => null,
            'amount_rank' => null,
            'quantity_rank' => null,
            'book_order_num_rank' => null,
            'comment_score_rank' => null,
            'conversion_rank' => null,
            'hot_words_count' => null,
            'top_hot_words' => [],
            'hot_hotels_count' => null,
            'top_hot_hotels' => [],
            'flow_lost_order_num' => null,
            'flow_lost_room_nights' => null,
            'flow_lost_amount' => null,
            'top_flow_hotel' => '',
            'top_flow_hotel_browse_rate' => null,
            'top_flow_hotel_order_rate' => null,
            'top_hot_room' => '',
            'top_hot_room_nights' => null,
            'top_hot_room_sale_percent' => null,
            'last_week_comment_score' => null,
            'last_week_good_add' => null,
            'last_week_bad_add' => null,
            'last_week_price_score' => null,
            'last_week_checkout_room_nights' => null,
            'last_week_checkout_sales' => null,
            'last_week_checkout_room_price' => null,
            'last_week_book_quantity' => null,
            'last_week_book_room_nights' => null,
            'last_week_book_sales' => null,
            'weekly_self_list_exposure' => null,
            'weekly_self_detail_exposure' => null,
            'weekly_self_order_filling_num' => null,
            'weekly_self_order_submit_num' => null,
            'weekly_self_flow_rate' => null,
            'weekly_self_order_fill_rate' => null,
            'weekly_self_deal_rate' => null,
            'weekly_competitor_list_exposure' => null,
            'weekly_competitor_detail_exposure' => null,
            'weekly_competitor_order_filling_num' => null,
            'weekly_competitor_order_submit_num' => null,
            'weekly_competitor_flow_rate' => null,
            'weekly_competitor_order_fill_rate' => null,
            'weekly_competitor_deal_rate' => null,
            'top_competitor_list_exposure' => null,
            'top_competitor_detail_exposure' => null,
            'top_competitor_order_filling_num' => null,
            'top_competitor_order_submit_num' => null,
            'top_competitor_flow_rate' => null,
            'top_competitor_order_fill_rate' => null,
            'top_competitor_deal_rate' => null,
        ];
        $avgPriceValues = [];
        $conversionValues = [];
        $psiValues = [];
        $hotelScoreValues = [];
        $replyRateValues = [];
        $visitorRanks = [];
        $rankValues = [
            'amount_rank' => [],
            'quantity_rank' => [],
            'book_order_num_rank' => [],
            'comment_score_rank' => [],
            'conversion_rank' => [],
        ];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $amount = self::number($row, ['amount', 'Amount', 'totalAmount', 'total_amount', 'saleAmount', 'orderAmount', 'gmv', 'turnover', 'bookingAmount', '成交收入', '成交金额', '销售额'], 0);
            $roomNights = (int)self::number($row, ['quantity', 'Quantity', 'roomNights', 'room_nights', 'checkOutQuantity', 'roomNightCount', 'nightNum', '成交间夜', '间夜', '房晚'], 0);
            $orders = (int)self::number($row, ['bookOrderNum', 'book_order_num', 'orderCount', 'order_count', 'orderNum', 'orders', 'bookings', '成交订单数', '订单数'], 0);

            $summary['amount'] += $amount;
            $summary['room_nights'] += $roomNights;
            $summary['order_count'] += $orders;
            $summary['yesterday_uv'] += (int)self::number($row, ['yesterday_uv', 'yesterdayUv', 'yesterdayUV', 'uv', 'UV', 'visitorCount', 'detailUv', 'totalDetailNum', 'visitors', '昨日UV', '访客数'], 0);
            $summary['competitor_uv'] += (int)self::number($row, ['competitor_uv', 'competitorUv', 'competitorUV', 'competeUv', 'competeUV', 'peerUv', 'peerUV', 'comhtluv', '竞品UV'], 0);
            $summary['competitor_orders'] += (int)self::number($row, ['competitor_orders', 'competitorOrders', 'competitorOrderNum', 'competeOrderNum', 'peerOrderNum', 'ordquantity', '竞品订单', '竞品订单数'], 0);
            $summary['competitor_amount'] += self::number($row, ['competitor_amount', 'competitorAmount', 'competitorRevenue', 'competeAmount', 'peerAmount', 'ordamount', '竞品收入', '竞品成交收入'], 0);
            $summary['favorite_count'] += (int)self::number($row, ['favorite_count', 'favoriteCount', 'collectCount', 'hotelCollect', '收藏数', '收藏量'], 0);
            $summary['self_list_exposure'] += (int)self::number($row, ['self_list_exposure'], 0);
            $summary['self_detail_exposure'] += (int)self::number($row, ['self_detail_exposure'], 0);
            $summary['self_order_filling_num'] += (int)self::number($row, ['self_order_filling_num'], 0);
            $summary['self_order_submit_num'] += (int)self::number($row, ['self_order_submit_num'], 0);
            $summary['competitor_list_exposure'] += (int)self::number($row, ['competitor_list_exposure'], 0);
            $summary['competitor_detail_exposure'] += (int)self::number($row, ['competitor_detail_exposure'], 0);
            $summary['competitor_order_filling_num'] += (int)self::number($row, ['competitor_order_filling_num'], 0);
            $summary['competitor_order_submit_num'] += (int)self::number($row, ['competitor_order_submit_num'], 0);

            foreach (array_keys($rankValues) as $rankKey) {
                $rank = (int)self::number($row, [$rankKey], 0);
                if ($rank > 0) {
                    $rankValues[$rankKey][] = $rank;
                }
            }
            foreach (['compete_hotel_count', 'hot_words_count', 'hot_hotels_count'] as $countKey) {
                $count = (int)self::number($row, [$countKey], 0);
                if ($count > 0) {
                    $summary[$countKey] = max((int)($summary[$countKey] ?? 0), $count);
                }
            }
            foreach (['top_flow_hotel', 'top_hot_room'] as $textKey) {
                $text = trim((string)($row[$textKey] ?? ''));
                if ($text !== '' && (string)($summary[$textKey] ?? '') === '') {
                    $summary[$textKey] = $text;
                }
            }
            foreach ([
                'top_hot_words' => '_overview_hot_words',
                'top_hot_hotels' => '_overview_hot_hotels',
            ] as $summaryKey => $rowListKey) {
                if (empty($summary[$summaryKey])) {
                    $items = self::normalizeStringListItems($row[$summaryKey] ?? $row[$rowListKey] ?? []);
                    if (!empty($items)) {
                        $summary[$summaryKey] = array_slice($items, 0, 10);
                    }
                }
            }
            foreach ([
                'flow_lost_order_num',
                'flow_lost_room_nights',
                'top_hot_room_nights',
                'last_week_good_add',
                'last_week_bad_add',
                'last_week_checkout_room_nights',
                'last_week_book_quantity',
                'last_week_book_room_nights',
            ] as $intKey) {
                $rawValue = self::firstValue($row, [$intKey], null);
                if ($rawValue !== null) {
                    $value = (int)self::number($row, [$intKey], 0);
                    $summary[$intKey] = (int)($summary[$intKey] ?? 0) + $value;
                }
            }
            foreach ([
                'flow_lost_amount',
                'top_flow_hotel_browse_rate',
                'top_flow_hotel_order_rate',
                'top_hot_room_sale_percent',
                'last_week_comment_score',
                'last_week_price_score',
                'last_week_checkout_sales',
                'last_week_checkout_room_price',
                'last_week_book_sales',
            ] as $numberKey) {
                $rawValue = self::firstValue($row, [$numberKey], null);
                if ($rawValue !== null && $summary[$numberKey] === null) {
                    $summary[$numberKey] = self::number($row, [$numberKey], 0);
                }
            }
            foreach (['weekly_self', 'weekly_competitor', 'top_competitor'] as $prefix) {
                foreach (['list_exposure', 'detail_exposure', 'order_filling_num', 'order_submit_num'] as $metricKey) {
                    $summaryKey = $prefix . '_' . $metricKey;
                    $rawValue = self::firstValue($row, [$summaryKey], null);
                    if ($rawValue !== null) {
                        $value = (int)self::number($row, [$summaryKey], 0);
                        $summary[$summaryKey] = (int)($summary[$summaryKey] ?? 0) + $value;
                    }
                }
                foreach (['flow_rate', 'order_fill_rate', 'deal_rate'] as $rateKey) {
                    $summaryKey = $prefix . '_' . $rateKey;
                    $value = self::normalizePercentValue(self::firstValue($row, [$summaryKey], null));
                    if ($value !== null && $summary[$summaryKey] === null) {
                        $summary[$summaryKey] = $value;
                    }
                }
            }

            $avgPrice = self::number($row, ['avg_price', 'avgPrice', 'averagePrice', 'adr', 'ADR', '均价', '平均房价'], 0);
            if ($avgPrice > 0) {
                $avgPriceValues[] = $avgPrice;
            }
            $conversionRate = self::normalizePercentValue(self::firstValue($row, ['closeRate', 'conversion_rate', 'conversionRate', 'convertionRate', 'bookRate', '成交率', '转化率'], null));
            if ($conversionRate !== null) {
                $conversionValues[] = $conversionRate;
            }
            $psi = self::number($row, ['psi', 'PSI', 'psiScore', 'serviceScore', 'service_score', 'PSI值'], 0);
            if ($psi > 0) {
                $psiValues[] = $psi;
            }
            $hotelScore = self::number($row, ['hotel_score', 'hotelScore', 'ctripRatingall', 'ctrip_rating_all', '酒店评分', '酒店点评分'], 0);
            if ($hotelScore > 0) {
                $hotelScoreValues[] = $hotelScore;
            }
            $replyRate = self::normalizePercentValue(self::firstValue($row, ['reply_rate', 'replyRate', 'replyrate5m', '回复率', '5分钟回复率'], null));
            if ($replyRate !== null) {
                $replyRateValues[] = $replyRate;
            }
            $visitorRank = (int)self::number($row, ['visitor_rank', 'visitorRank', 'uvRank', '访客排名'], 0);
            if ($visitorRank > 0) {
                $visitorRanks[] = $visitorRank;
            }
        }

        if ($summary['room_nights'] > 0) {
            $summary['avg_price'] = round($summary['amount'] / $summary['room_nights'], 2);
        } elseif (!empty($avgPriceValues)) {
            $summary['avg_price'] = round(array_sum($avgPriceValues) / count($avgPriceValues), 2);
        }
        if (!empty($conversionValues)) {
            $summary['conversion_rate'] = round(array_sum($conversionValues) / count($conversionValues), 2);
        }
        if (!empty($psiValues)) {
            $summary['psi'] = round(array_sum($psiValues) / count($psiValues), 2);
        }
        if (!empty($hotelScoreValues)) {
            $summary['hotel_score'] = round(array_sum($hotelScoreValues) / count($hotelScoreValues), 2);
        }
        if (!empty($replyRateValues)) {
            $summary['reply_rate'] = round(array_sum($replyRateValues) / count($replyRateValues), 2);
        }
        if (!empty($visitorRanks)) {
            $summary['visitor_rank'] = min($visitorRanks);
        }
        foreach ($rankValues as $rankKey => $values) {
            if (!empty($values)) {
                $summary[$rankKey] = min($values);
            }
        }
        $summary['self_flow_rate'] = self::trafficRate((float)$summary['self_detail_exposure'], (float)$summary['self_list_exposure']);
        $summary['self_order_fill_rate'] = self::trafficRate((float)$summary['self_order_filling_num'], (float)$summary['self_detail_exposure']);
        $summary['self_deal_rate'] = self::trafficRate((float)$summary['self_order_submit_num'], (float)$summary['self_order_filling_num']);
        $summary['competitor_flow_rate'] = self::trafficRate((float)$summary['competitor_detail_exposure'], (float)$summary['competitor_list_exposure']);
        $summary['competitor_order_fill_rate'] = self::trafficRate((float)$summary['competitor_order_filling_num'], (float)$summary['competitor_detail_exposure']);
        $summary['competitor_deal_rate'] = self::trafficRate((float)$summary['competitor_order_submit_num'], (float)$summary['competitor_order_filling_num']);
        foreach (['weekly_self', 'weekly_competitor', 'top_competitor'] as $prefix) {
            if ($summary[$prefix . '_flow_rate'] === null && (float)($summary[$prefix . '_list_exposure'] ?? 0) > 0) {
                $summary[$prefix . '_flow_rate'] = self::trafficRate((float)($summary[$prefix . '_detail_exposure'] ?? 0), (float)($summary[$prefix . '_list_exposure'] ?? 0));
            }
            if ($summary[$prefix . '_order_fill_rate'] === null && (float)($summary[$prefix . '_detail_exposure'] ?? 0) > 0) {
                $summary[$prefix . '_order_fill_rate'] = self::trafficRate((float)($summary[$prefix . '_order_filling_num'] ?? 0), (float)($summary[$prefix . '_detail_exposure'] ?? 0));
            }
            if ($summary[$prefix . '_deal_rate'] === null && (float)($summary[$prefix . '_order_filling_num'] ?? 0) > 0) {
                $summary[$prefix . '_deal_rate'] = self::trafficRate((float)($summary[$prefix . '_order_submit_num'] ?? 0), (float)($summary[$prefix . '_order_filling_num'] ?? 0));
            }
        }
        $summary['amount'] = round($summary['amount'], 2);
        $summary['competitor_amount'] = round($summary['competitor_amount'], 2);
        if ($summary['flow_lost_amount'] !== null) {
            $summary['flow_lost_amount'] = round((float)$summary['flow_lost_amount'], 2);
        }
        foreach (['last_week_checkout_sales', 'last_week_checkout_room_price', 'last_week_book_sales'] as $moneyKey) {
            if ($summary[$moneyKey] !== null) {
                $summary[$moneyKey] = round((float)$summary[$moneyKey], 2);
            }
        }
        return $summary;
    }

    private static function firstValue(array $data, array $keys, $default = null)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
                return $data[$key];
            }
        }
        return $default;
    }

    private static function number(array $data, array $keys, float $default = 0.0): float
    {
        $value = self::firstValue($data, $keys, null);
        if (is_string($value)) {
            $value = str_replace([',', '%', '￥', '¥', '元', ' '], '', trim($value));
        }
        return is_numeric($value) ? (float)$value : $default;
    }

    private static function normalizePercentValue($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            $value = str_replace([',', '%'], '', trim($value));
        }
        if (!is_numeric($value)) {
            return null;
        }
        return round(self::normalizeTrafficPercent((float)$value), 2);
    }

    private static function normalizeTrafficPercent(?float $value): float
    {
        if ($value === null) {
            return 0.0;
        }
        return abs($value) > 0 && abs($value) <= 1 ? $value * 100 : $value;
    }

    private static function trafficRate(float $num, float $denom): float
    {
        return $denom > 0 ? round($num / $denom * 100, 2) : 0.0;
    }

    private static function normalizeStringListItems($data): array
    {
        if (!is_array($data)) {
            return [];
        }

        $items = [];
        foreach ($data as $item) {
            $text = '';
            if (is_scalar($item)) {
                $text = trim((string)$item);
            } elseif (is_array($item)) {
                foreach (['hotelName', 'hotel_name', 'name', 'title', 'keyword', 'word'] as $key) {
                    if (isset($item[$key]) && is_scalar($item[$key]) && trim((string)$item[$key]) !== '') {
                        $text = trim((string)$item[$key]);
                        break;
                    }
                }
            }
            if ($text !== '') {
                $items[] = $text;
            }
        }

        return $items;
    }
}
