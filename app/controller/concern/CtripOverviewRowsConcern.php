<?php

namespace app\controller\concern;

use app\service\CtripOverviewSummaryService;

trait CtripOverviewRowsConcern
{
    private function flattenCtripOverviewCandidateRows($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        if ($this->isSequentialArray($value)) {
            $rows = [];
            foreach ($value as $item) {
                if (is_array($item)) {
                    $rows = array_merge($rows, $this->flattenCtripOverviewCandidateRows($item));
                }
            }
            return $rows;
        }

        $rows = [];
        if (array_key_exists('hotelId', $value) || array_key_exists('hotel_id', $value) || array_key_exists('masterHotelId', $value) || array_key_exists('masterhotelid', $value)) {
            $rows[] = $value;
        }
        foreach (['list', 'rows', 'hotelList', 'flowHotelItemVos', 'data'] as $key) {
            if (isset($value[$key]) && is_array($value[$key])) {
                $rows = array_merge($rows, $this->flattenCtripOverviewCandidateRows($value[$key]));
            }
        }
        return $rows;
    }

    private function collectCtripOverviewRows(array $payload, string $requestHotelId, string $dataDate): array
    {
        $rows = $this->extractCtripOverviewSpecialRows($payload, $requestHotelId, $dataDate);
        $rows = array_merge($rows, $this->extractCtripCapturedSection($payload, 'business'));
        if (empty($rows)) {
            foreach ($this->extractCtripCapturedResponseData($payload, 'business') as $responseData) {
                $rows = array_merge($rows, $this->extractCtripBusinessDataList($responseData));
            }
        }

        $deduped = [];
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $row = $this->normalizeCtripOverviewRow($row, $requestHotelId, $dataDate);
            $identity = (string)($row['_fingerprint'] ?? md5(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)));
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $deduped[] = $row;
        }
        return $this->mergeCtripOverviewRows($deduped);
    }

    private function extractCtripOverviewSpecialRows(array $payload, string $requestHotelId, string $dataDate): array
    {
        $rows = [];
        foreach (($payload['responses'] ?? []) as $response) {
            if (!is_array($response)) {
                continue;
            }
            $url = strtolower((string)($response['url'] ?? ''));
            $responseData = $response['data'] ?? $response['body'] ?? $response['json'] ?? [];
            if (!is_array($responseData)) {
                continue;
            }
            $data = $responseData['data'] ?? $responseData;
            if (str_contains($url, 'getcompetehotelreportv1')) {
                $rows[] = $this->buildCtripCompeteHotelOverviewRow($data, $requestHotelId, $dataDate);
                continue;
            }
            if (str_contains($url, 'gethotwordsv1')) {
                $rows[] = $this->buildCtripStringListOverviewRow($data, $requestHotelId, $dataDate, '_overview_hot_words', 'hot_words_count', null, 'top_hot_words');
                continue;
            }
            if (str_contains($url, 'gethothotelsv1')) {
                $rows[] = $this->buildCtripStringListOverviewRow($data, $requestHotelId, $dataDate, '_overview_hot_hotels', 'hot_hotels_count', null, 'top_hot_hotels');
                continue;
            }
            if (str_contains($url, 'getflowhotelsv1')) {
                $rows[] = $this->buildCtripFlowHotelsOverviewRow($data, $requestHotelId, $dataDate);
                continue;
            }
            if (str_contains($url, 'getuserbehaviorv1') || str_contains($url, 'getuserbehavorv1')) {
                $rows[] = $this->buildCtripUserBehaviorOverviewRow($data, $requestHotelId, $dataDate);
                continue;
            }
            if (str_contains($url, 'gettrafficreportv1')) {
                $rows[] = $this->buildCtripTrafficReportOverviewRow($data, $requestHotelId, $dataDate);
                continue;
            }
            if (str_contains($url, 'getlastweekreportv1')) {
                $rows[] = $this->buildCtripLastWeekReportOverviewRow($data, $requestHotelId, $dataDate);
                continue;
            }
            if (isset($data['hotRooms']) && is_array($data['hotRooms'])) {
                $rows[] = $this->buildCtripHotRoomsOverviewRow($data['hotRooms'], $requestHotelId, $dataDate);
            }
        }

        return array_values(array_filter($rows, static fn($row): bool => is_array($row) && !empty($row)));
    }

    private function ctripOverviewBaseRow(string $requestHotelId, string $dataDate, array $extra = []): array
    {
        return array_merge([
            'hotelId' => $requestHotelId,
            'dataDate' => $dataDate,
            '_overview_compare_type' => 'self',
        ], $extra);
    }

    private function buildCtripCompeteHotelOverviewRow($data, string $requestHotelId, string $dataDate): array
    {
        $list = is_array($data) && $this->isSequentialArray($data) ? $data : [];
        $self = [];
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $hotelId = (string)($item['hotelId'] ?? $item['hotel_id'] ?? $item['masterHotelId'] ?? '');
            $hotelName = (string)($item['hotelName'] ?? $item['hotel_name'] ?? '');
            if (($requestHotelId !== '' && $hotelId === $requestHotelId) || $hotelName === '我的酒店') {
                $self = $item;
                break;
            }
        }

        return $this->ctripOverviewBaseRow($requestHotelId, $dataDate, [
            'hotelName' => (string)($self['hotelName'] ?? '我的酒店'),
            'compete_hotel_count' => count($list),
            'amount_rank' => (int)$this->meituanNumber($self, ['amount'], 0),
            'quantity_rank' => (int)$this->meituanNumber($self, ['quantity'], 0),
            'book_order_num_rank' => (int)$this->meituanNumber($self, ['bookOrderNum'], 0),
            'comment_score_rank' => (int)$this->meituanNumber($self, ['commentScore'], 0),
            'visitor_rank' => (int)$this->meituanNumber($self, ['totalDetailNum'], 0),
            'conversion_rank' => (int)$this->meituanNumber($self, ['convertionRate'], 0),
            '_overview_compete_hotel_rank_list' => $list,
        ]);
    }

    private function buildCtripStringListOverviewRow($data, string $requestHotelId, string $dataDate, string $listKey, string $countKey, ?string $topKey = null, ?string $topListKey = null): array
    {
        $items = $this->normalizeCtripStringListItems($data);
        $row = [
            $listKey => $items,
            $countKey => count($items),
        ];
        if ($topKey !== null) {
            $row[$topKey] = $items[0] ?? '';
        }
        if ($topListKey !== null) {
            $row[$topListKey] = array_slice($items, 0, 10);
        }
        return $this->ctripOverviewBaseRow($requestHotelId, $dataDate, $row);
    }

    private function normalizeCtripStringListItems($data): array
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

    private function buildCtripFlowHotelsOverviewRow($data, string $requestHotelId, string $dataDate): array
    {
        $items = is_array($data['flowHotelItemVos'] ?? null) ? $data['flowHotelItemVos'] : [];
        $lossOrder = is_array($data['lossOrderVo'] ?? null) ? $data['lossOrderVo'] : [];
        $top = is_array($items[0] ?? null) ? $items[0] : [];
        return $this->ctripOverviewBaseRow($requestHotelId, $dataDate, [
            '_overview_flow_hotels' => $items,
            'flow_lost_order_num' => (int)$this->meituanNumber($lossOrder, ['ordernum'], 0),
            'flow_lost_room_nights' => (int)$this->meituanNumber($lossOrder, ['ordquantity'], 0),
            'flow_lost_amount' => $this->meituanNumber($lossOrder, ['ordamount'], 0),
            'top_flow_hotel' => (string)($top['hotelName'] ?? ''),
            'top_flow_hotel_browse_rate' => $this->normalizeMeituanPercentValue($top['proportion'] ?? null) ?? 0.0,
            'top_flow_hotel_order_rate' => $this->normalizeMeituanPercentValue($top['orderPro'] ?? null) ?? 0.0,
        ]);
    }

    private function buildCtripHotRoomsOverviewRow(array $hotRooms, string $requestHotelId, string $dataDate): array
    {
        $top = is_array($hotRooms[0] ?? null) ? $hotRooms[0] : [];
        return $this->ctripOverviewBaseRow($requestHotelId, $dataDate, [
            '_overview_hot_rooms' => $hotRooms,
            'top_hot_room' => (string)($top['roomShortName'] ?? $top['roomName'] ?? ''),
            'top_hot_room_nights' => (int)$this->meituanNumber($top, ['saleRoomNights'], 0),
            'top_hot_room_sale_percent' => $this->normalizeMeituanPercentValue($top['salePercent'] ?? null) ?? 0.0,
        ]);
    }

    private function buildCtripUserBehaviorOverviewRow($data, string $requestHotelId, string $dataDate): array
    {
        $data = is_array($data) ? $data : [];
        return $this->ctripOverviewBaseRow($requestHotelId, $dataDate, [
            'last_week_comment_score' => $this->meituanNumber($data, ['lastWeekCommentScore'], 0),
            'before_last_week_comment_score' => $this->meituanNumber($data, ['beforeLastWeekCommentScore'], 0),
            'last_week_good_add' => (int)$this->meituanNumber($data, ['lastWeekGoodAdd'], 0),
            'before_last_week_good_add' => (int)$this->meituanNumber($data, ['beforeLastWeekGoodAdd'], 0),
            'last_week_bad_add' => (int)$this->meituanNumber($data, ['lastWeekBadAdd'], 0),
            'before_last_week_bad_add' => (int)$this->meituanNumber($data, ['beforeLastWeekBadAdd'], 0),
            'last_week_price_score' => $this->meituanNumber($data, ['lastWeekPriceScore'], 0),
            'before_last_week_price_score' => $this->meituanNumber($data, ['beforeLastWeekPriceScore'], 0),
            'last_week_price_score_change' => $this->meituanNumber($data, ['lastWeekPriceScoreProportion'], 0),
            'last_week_str' => (string)($data['lastWeekStr'] ?? ''),
            'before_last_week_str' => (string)($data['beforeLastWeekStr'] ?? ''),
        ]);
    }

    private function buildCtripTrafficReportOverviewRow($data, string $requestHotelId, string $dataDate): array
    {
        $myHotel = is_array($data['myHotel'] ?? null) ? $data['myHotel'] : [];
        $avg = is_array($data['competeHotelAvg'] ?? null) ? $data['competeHotelAvg'] : [];
        $top = is_array($data['topCompeteHotel'] ?? null) ? $data['topCompeteHotel'] : [];
        return $this->ctripOverviewBaseRow($requestHotelId, $dataDate, array_merge(
            $this->mapCtripTrafficReportPrefix($myHotel, 'weekly_self'),
            $this->mapCtripTrafficReportPrefix($avg, 'weekly_competitor'),
            $this->mapCtripTrafficReportPrefix($top, 'top_competitor')
        ));
    }

    private function mapCtripTrafficReportPrefix(array $data, string $prefix): array
    {
        return [
            "{$prefix}_list_exposure" => (int)$this->meituanNumber($data, ['totalListExposure'], 0),
            "{$prefix}_detail_exposure" => (int)$this->meituanNumber($data, ['totalDetailExposure'], 0),
            "{$prefix}_order_filling_num" => (int)$this->meituanNumber($data, ['orderFillingNum'], 0),
            "{$prefix}_order_submit_num" => (int)$this->meituanNumber($data, ['orderSubmitNum'], 0),
            "{$prefix}_flow_rate" => $this->normalizeMeituanPercentValue($data['listTransforDetailRate'] ?? null) ?? 0.0,
            "{$prefix}_order_fill_rate" => $this->normalizeMeituanPercentValue($data['detailTransforOrderFillRate'] ?? null) ?? 0.0,
            "{$prefix}_deal_rate" => $this->normalizeMeituanPercentValue($data['orderFillTransforOrderSubmitRate'] ?? null) ?? 0.0,
        ];
    }

    private function buildCtripLastWeekReportOverviewRow($data, string $requestHotelId, string $dataDate): array
    {
        $data = is_array($data) ? $data : [];
        return $this->ctripOverviewBaseRow($requestHotelId, $dataDate, [
            'last_week_str' => (string)($data['lastWeekStr'] ?? ''),
            'before_last_week_str' => (string)($data['beforeLastWeekStr'] ?? ''),
            'last_week_checkout_room_nights' => (int)$this->meituanNumber($data, ['lastWeekCheckoutRoomNights'], 0),
            'last_week_checkout_sales' => $this->meituanNumber($data, ['lastWeekCheckoutSales'], 0),
            'last_week_checkout_room_price' => $this->meituanNumber($data, ['lastWeekCheckoutRoomPrice'], 0),
            'last_week_book_quantity' => (int)$this->meituanNumber($data, ['lastWeekBookQuantity'], 0),
            'last_week_book_room_nights' => (int)$this->meituanNumber($data, ['lastWeekBookRoomNights'], 0),
            'last_week_book_sales' => $this->meituanNumber($data, ['lastWeekBookSales'], 0),
        ]);
    }

    private function normalizeCtripOverviewRow(array $row, string $requestHotelId, string $dataDate): array
    {
        $rawHotelId = (string)$this->firstMeituanValue($row, [
            'hotelId',
            'hotel_id',
            'HotelId',
            'hotelID',
            'masterhotelid',
            'masterHotelId',
            'nodeId',
            'node_id',
        ], '');
        $compareType = strtolower((string)$this->firstMeituanValue($row, [
            '_overview_compare_type',
            'compareType',
            'compare_type',
            'type',
        ], ''));
        if (!in_array($compareType, ['self', 'my', 'competitor', 'avg', 'average', 'peer'], true)) {
            $compareType = is_numeric($rawHotelId) && (int)$rawHotelId < 0 ? 'competitor' : 'self';
        }
        $compareType = in_array($compareType, ['competitor', 'avg', 'average', 'peer'], true) ? 'competitor' : 'self';
        $row['_overview_compare_type'] = $compareType;
        if ($rawHotelId !== '') {
            $row['_overview_source_hotel_id'] = $rawHotelId;
        }

        if ($requestHotelId !== '' && ($compareType === 'competitor' || empty($row['hotelId']) && empty($row['hotel_id']) && empty($row['HotelId']))) {
            $row['hotelId'] = $requestHotelId;
        } elseif ($rawHotelId !== '' && empty($row['hotelId']) && empty($row['hotel_id']) && empty($row['HotelId'])) {
            $row['hotelId'] = $rawHotelId;
        }
        if ($dataDate !== '' && empty($row['dataDate']) && empty($row['data_date']) && empty($row['date'])) {
            $row['dataDate'] = $dataDate;
        }

        $prefix = $compareType === 'competitor' ? 'competitor' : 'self';
        $fieldMap = [
            "{$prefix}_list_exposure" => ['listExposure', 'list_exposure', 'exposure', 'exposureCount'],
            "{$prefix}_detail_exposure" => ['detailExposure', 'detail_exposure', 'detailVisitors', 'detailUv', 'visitorCount', 'UV', 'uv'],
            "{$prefix}_order_filling_num" => ['orderFillingNum', 'order_filling_num', 'orderVisitors', 'clickCount', 'clickNum'],
            "{$prefix}_order_submit_num" => ['orderSubmitNum', 'order_submit_num', 'submitUsers', 'submitNum'],
        ];
        foreach ($fieldMap as $target => $aliases) {
            $value = $this->firstMeituanValue($row, $aliases, null);
            if ($value !== null && $value !== '') {
                $row[$target] = $this->meituanNumber($row, $aliases, 0);
            }
        }

        $listExposure = (float)($row["{$prefix}_list_exposure"] ?? 0);
        $detailExposure = (float)($row["{$prefix}_detail_exposure"] ?? 0);
        $orderFillingNum = (float)($row["{$prefix}_order_filling_num"] ?? 0);
        $orderSubmitNum = (float)($row["{$prefix}_order_submit_num"] ?? 0);
        if ($listExposure > 0 && $detailExposure > 0) {
            $row["{$prefix}_flow_rate"] = round($detailExposure / $listExposure * 100, 2);
        } else {
            $flowRate = $this->normalizeMeituanPercentValue($this->firstMeituanValue($row, ['flowRate', 'flow_rate'], null));
            if ($flowRate !== null) {
                $row["{$prefix}_flow_rate"] = $flowRate;
            }
        }
        if ($detailExposure > 0 && $orderFillingNum > 0) {
            $row["{$prefix}_order_fill_rate"] = round($orderFillingNum / $detailExposure * 100, 2);
        }
        if ($orderFillingNum > 0) {
            $row["{$prefix}_deal_rate"] = round($orderSubmitNum / $orderFillingNum * 100, 2);
        }

        return $row;
    }

    private function mergeCtripOverviewRows(array $rows): array
    {
        $merged = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $compareType = (string)($row['_overview_compare_type'] ?? '');
            $hotelId = (string)($row['hotelId'] ?? $row['hotel_id'] ?? $row['HotelId'] ?? '');
            $dataDate = (string)($row['dataDate'] ?? $row['data_date'] ?? $row['date'] ?? '');
            $key = $hotelId . '|' . $dataDate;
            if (!isset($merged[$key])) {
                $merged[$key] = [
                    'hotelId' => $hotelId,
                    'hotelName' => (string)($row['hotelName'] ?? $row['hotel_name'] ?? $row['HotelName'] ?? $row['name'] ?? ''),
                    'dataDate' => $dataDate,
                    '_overview_rows' => [],
                ];
            }
            $merged[$key]['_overview_rows'][] = $row;
            foreach ($row as $field => $value) {
                if ($compareType === 'competitor' && in_array($field, ['listExposure', 'detailExposure', 'flowRate', 'orderFillingNum', 'orderSubmitNum'], true)) {
                    continue;
                }
                if ($value === null || $value === '' || (is_array($value) && empty($value))) {
                    continue;
                }
                $current = $merged[$key][$field] ?? null;
                if ($current === null || $current === '' || (is_numeric($current) && (float)$current === 0.0 && is_numeric($value) && (float)$value !== 0.0)) {
                    $merged[$key][$field] = $value;
                }
            }
        }
        return array_values($merged);
    }

    private function saveCtripOverviewRows(array $rows, string $dataDate, ?int $systemHotelId = null): int
    {
        if (empty($rows)) {
            return 0;
        }
        return $this->parseAndSaveData(['data' => $rows], $dataDate, $dataDate, $systemHotelId);
    }

    private function summarizeCtripOverviewRows(array $rows): array
    {
        return CtripOverviewSummaryService::summarizeRows($rows);
    }
}
