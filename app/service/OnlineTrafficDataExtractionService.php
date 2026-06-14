<?php
declare(strict_types=1);

namespace app\service;

final class OnlineTrafficDataExtractionService
{
    public static function extractCtripTrafficRows($responseData): array
    {
        if (!is_array($responseData)) {
            return [];
        }

        return self::extractCtripTrafficRowsRecursive($responseData);
    }

    public static function extractTrafficValue(array $item): ?float
    {
        $keys = [
            'traffic', 'trafficValue', 'traffic_value', 'pv', 'uv', 'pageView', 'page_view',
            'visit', 'visits', 'exposure', 'exposureNum', 'impression', 'impressions',
            'click', 'clickNum', 'detailView', 'detail_view', 'view', 'views', 'session', 'sessions',
        ];
        foreach ($keys as $key) {
            if (isset($item[$key]) && is_numeric($item[$key])) {
                return (float)$item[$key];
            }
        }
        if (isset($item['value']) && is_numeric($item['value'])) {
            return (float)$item['value'];
        }

        return null;
    }

    public static function extractGenericTrafficRows($data, array $path = []): array
    {
        $result = [];
        if (!is_array($data)) {
            return $result;
        }

        foreach ($data as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            $itemPath = array_merge($path, [(string)$key]);
            if (isset($value['hotelId']) || isset($value['hotel_id']) || isset($value['hotelName']) || isset($value['hotel_name']) || isset($value['poiId']) || isset($value['poiName'])) {
                $result[] = self::withSourcePath($value, $itemPath);
            } elseif (isset($value[0]) && is_array($value[0])) {
                $result = array_merge($result, self::extractGenericTrafficRows($value, $itemPath));
            } else {
                $result = array_merge($result, self::extractGenericTrafficRows($value, $itemPath));
            }
        }

        return $result;
    }

    public static function ctripTrafficRowKeys(): array
    {
        return [
            'listExposure', 'list_exposure', 'exposure', 'exposureCount', 'impressions', 'showCount',
            'PV', 'pv', 'pageView', 'pageViews', 'page_view',
            'detailExposure', 'detail_exposure', 'detailVisitors', 'detailUv', 'visitorCount',
            'UV', 'uv', 'uniqueVisitors', 'unique_visitors', 'views',
            'orderFillingNum', 'order_filling_num', 'orderVisitors', 'clickCount', 'clickNum', 'clicks',
            'orderSubmitNum', 'order_submit_num', 'submitUsers', 'submitNum', 'orderCount', 'orderNum',
            'bookOrderNum', 'dealNum', 'orders',
            'flowRate', 'flow_rate', 'conversionRate', 'conversion_rate', 'convertionRate',
            'convertRate', 'transforRate', 'transferRate', 'transRate', 'cvr',
            'rank', 'ranking', 'rankNo', 'rankIndex', 'competitionRank', 'competitorRank',
            'competeRank', 'categoryRank', 'cateRank', 'categoryRanking', 'rankJson',
            'rawRankJson', 'rankingJson',
        ];
    }

    private static function extractCtripTrafficRowsRecursive(array $value, int $depth = 0, array $path = []): array
    {
        if ($depth > 8) {
            return [];
        }

        if (self::isSequentialArray($value)) {
            $rows = [];
            foreach ($value as $index => $item) {
                if (!is_array($item)) {
                    continue;
                }
                $itemPath = array_merge($path, [(string)$index]);
                if (self::looksLikeCtripTrafficDataRow($item)) {
                    $rows[] = self::withSourcePath($item, $itemPath);
                } else {
                    $rows = array_merge($rows, self::extractCtripTrafficRowsRecursive($item, $depth + 1, $itemPath));
                }
            }
            return $rows;
        }

        $expandedRows = self::expandCtripTrafficDailySeries($value, $path);
        if (!empty($expandedRows)) {
            return $expandedRows;
        }

        if (self::looksLikeCtripTrafficDataRow($value)) {
            return [self::withSourcePath($value, $path)];
        }

        foreach (self::trafficListPaths() as $candidatePath) {
            $nested = self::readNestedValue($value, $candidatePath);
            if (is_array($nested)) {
                $rows = self::extractCtripTrafficRowsRecursive($nested, $depth + 1, array_merge($path, $candidatePath));
                if (!empty($rows)) {
                    return $rows;
                }
            }
        }

        $rows = [];
        foreach ($value as $key => $nested) {
            if (is_array($nested)) {
                $rows = array_merge($rows, self::extractCtripTrafficRowsRecursive($nested, $depth + 1, array_merge($path, [(string)$key])));
            }
        }

        return $rows;
    }

    private static function expandCtripTrafficDailySeries(array $value, array $path = []): array
    {
        $dates = self::readCtripTrafficDateSeries($value);
        if (empty($dates)) {
            return [];
        }

        $groups = self::collectCtripTrafficSeriesGroups($value);
        if (empty($groups)) {
            $groups = [[
                'data' => $value,
                'compare_type' => self::resolveCtripTrafficCompareType($value),
            ]];
        }

        $rows = [];
        foreach ($groups as $group) {
            $groupData = is_array($group['data'] ?? null) ? $group['data'] : [];
            $compareType = (string)($group['compare_type'] ?? self::resolveCtripTrafficCompareType($groupData));
            $hotelId = $groupData['hotelId'] ?? $groupData['hotel_id'] ?? $groupData['nodeId'] ?? $groupData['node_id'] ?? null;

            foreach ($dates as $index => $date) {
                if (strtotime((string)$date) === false) {
                    continue;
                }

                $row = [
                    'date' => date('Y-m-d', strtotime((string)$date)),
                    'compareType' => $compareType,
                    'listExposure' => (int)self::readCtripTrafficSeriesMetric($groupData, $index, [
                        ['listExposure'], ['list_exposure'], ['totalListExposure'], ['exposure'], ['exposureCount'], ['impressions'], ['showCount'], ['PV'], ['pv'], ['pageView'], ['pageViews'], ['page_view'],
                    ]),
                    'detailExposure' => (int)self::readCtripTrafficSeriesMetric($groupData, $index, [
                        ['detailExposure'], ['detail_exposure'], ['totalDetailExposure'], ['detailVisitors'], ['detailUv'], ['visitorCount'], ['UV'], ['uv'], ['uniqueVisitors'], ['unique_visitors'], ['views'],
                    ]),
                    'flowRate' => round(CtripTrafficDisplayService::normalizeTrafficPercent(self::readCtripTrafficSeriesMetric($groupData, $index, [
                        ['flowRate'], ['flow_rate'], ['listTransforDetailRate'], ['conversionRate'], ['conversion_rate'], ['convertionRate'], ['convertRate'], ['transforRate'], ['transferRate'], ['transRate'], ['cvr'],
                    ], null)), 2),
                    'orderFillingNum' => (int)self::readCtripTrafficSeriesMetric($groupData, $index, [
                        ['orderFillingNum'], ['order_filling_num'], ['orderVisitors'], ['clickCount'], ['click_count'], ['clickNum'], ['clicks'],
                    ]),
                    'orderSubmitNum' => (int)self::readCtripTrafficSeriesMetric($groupData, $index, [
                        ['orderSubmitNum'], ['order_submit_num'], ['submitUsers'], ['submitNum'], ['orderCount'], ['order_count'], ['orderNum'], ['bookOrderNum'], ['dealNum'], ['orders'],
                    ]),
                ];

                if ($hotelId !== null && $hotelId !== '') {
                    $row['hotelId'] = $hotelId;
                } elseif ($compareType !== 'self') {
                    $row['hotelId'] = -1;
                }

                if ($row['listExposure'] <= 0 && $row['detailExposure'] <= 0 && $row['orderFillingNum'] <= 0 && $row['orderSubmitNum'] <= 0) {
                    continue;
                }

                $row['_source_path'] = self::sourcePathString($path);
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private static function readCtripTrafficDateSeries(array $value): array
    {
        return self::readCtripTrafficSeries($value, [
            ['dateList'], ['date_list'], ['dates'], ['dataDates'], ['data_dates'], ['statDates'], ['stat_dates'],
            ['xAxis', 'data'], ['xaxis', 'data'], ['xAxisData'], ['x_axis_data'], ['categories'], ['labels'],
        ]);
    }

    private static function collectCtripTrafficSeriesGroups(array $value): array
    {
        $groups = [];
        foreach ([
            'myHotel' => 'self',
            'self' => 'self',
            'currentHotel' => 'self',
            'hotel' => 'self',
            'mine' => 'self',
            'competeHotelAvg' => 'competitor',
            'competitorAvg' => 'competitor',
            'competitorAverage' => 'competitor',
            'competitor' => 'competitor',
            'peerAvg' => 'competitor',
            'competeAvg' => 'competitor',
            'avg' => 'competitor',
            'average' => 'competitor',
        ] as $key => $compareType) {
            if (isset($value[$key]) && is_array($value[$key])) {
                $groups[] = ['data' => $value[$key], 'compare_type' => $compareType];
            }
        }

        return $groups;
    }

    private static function resolveCtripTrafficCompareType(array $value): string
    {
        $compareText = strtolower((string)($value['compareType'] ?? $value['compare_type'] ?? $value['type'] ?? $value['rankType'] ?? $value['name'] ?? $value['hotelName'] ?? ''));
        $hotelId = $value['hotelId'] ?? $value['hotel_id'] ?? $value['nodeId'] ?? $value['node_id'] ?? null;
        if (str_contains($compareText, 'self') || str_contains($compareText, 'my')) {
            return 'self';
        }
        if (str_contains($compareText, 'competitor') || str_contains($compareText, 'peer') || str_contains($compareText, 'avg') || str_contains($compareText, 'average') || str_contains($compareText, 'compete')) {
            return 'competitor';
        }

        return is_numeric($hotelId) && (int)$hotelId > 0 ? 'self' : 'competitor';
    }

    private static function readCtripTrafficSeriesMetric(array $value, int $index, array $paths, ?float $default = 0.0): ?float
    {
        $series = self::readCtripTrafficSeries($value, $paths);
        if (isset($series[$index])) {
            $number = CtripTrafficDisplayService::coerceTrafficNumber($series[$index]);
            if ($number !== null) {
                return $number;
            }
        }

        return $default;
    }

    private static function readCtripTrafficSeries(array $value, array $paths): array
    {
        foreach ($paths as $path) {
            $series = self::readNestedValue($value, $path);
            if (is_array($series)) {
                if (self::isSequentialArray($series)) {
                    return $series;
                }
                if (isset($series['data']) && is_array($series['data']) && self::isSequentialArray($series['data'])) {
                    return $series['data'];
                }
                if (isset($series['value']) && is_array($series['value']) && self::isSequentialArray($series['value'])) {
                    return $series['value'];
                }
            }
        }

        return [];
    }

    private static function looksLikeCtripTrafficDataRow(array $value): bool
    {
        $hasIdentity = array_key_exists('hotelId', $value)
            || array_key_exists('hotel_id', $value)
            || array_key_exists('nodeId', $value)
            || array_key_exists('node_id', $value);
        $hasDate = array_key_exists('date', $value)
            || array_key_exists('dataDate', $value)
            || array_key_exists('statDate', $value)
            || array_key_exists('data_date', $value)
            || array_key_exists('stat_date', $value);
        if ($hasIdentity && $hasDate) {
            return true;
        }

        foreach (self::ctripTrafficRowKeys() as $key) {
            if (array_key_exists($key, $value)) {
                return true;
            }
        }

        return false;
    }

    private static function trafficListPaths(): array
    {
        return [
            ['data', 'list'],
            ['data', 'rows'],
            ['data', 'traffic'],
            ['data', 'businessData'],
            ['data', 'peerTrends'],
            ['data', 'rankList'],
            ['data', 'ranking'],
            ['data', 'rankData'],
            ['data', 'categoryRank'],
            ['data', 'categoryRankList'],
            ['data', 'competitionRank'],
            ['data', 'competitionRankList'],
            ['data', 'competeRank'],
            ['data', 'competeRankList'],
            ['data', 'scanFlowDetails'],
            ['data', 'flowData'],
            ['data', 'trafficData'],
            ['data', 'statData'],
            ['result', 'list'],
            ['result', 'rows'],
            ['result', 'rankList'],
            ['list'],
            ['rows'],
            ['rankList'],
            ['categoryRankList'],
            ['competitionRankList'],
            ['data'],
        ];
    }

    private static function readNestedValue(array $data, array $path)
    {
        $current = $data;
        foreach ($path as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }

        return $current;
    }

    private static function isSequentialArray(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    private static function withSourcePath(array $row, array $path): array
    {
        if (!isset($row['_source_path']) || trim((string)$row['_source_path']) === '') {
            $row['_source_path'] = self::sourcePathString($path);
        }
        return $row;
    }

    private static function sourcePathString(array $path): string
    {
        return implode('.', array_map(static fn($part): string => (string)$part, $path));
    }
}
