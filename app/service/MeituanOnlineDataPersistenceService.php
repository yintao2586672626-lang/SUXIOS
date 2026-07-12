<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

final class MeituanOnlineDataPersistenceService
{
    public function parseAndSaveMeituanData($responseData, $startDate, $endDate, ?int $systemHotelId = null, array $context = [], bool $debugLog = false): int
    {
        try {
            $dataList = [];

            if ($debugLog) {
                \think\facade\Log::info('美团数据解析 - 原始响应结构: ' . json_encode([
                    'keys' => array_keys($responseData),
                    'data_keys' => isset($responseData['data']) && is_array($responseData['data']) ? array_keys($responseData['data']) : 'not_array',
                    'data_type' => isset($responseData['data']) ? gettype($responseData['data']) : 'not_set',
                    'data_value_type' => isset($responseData['data']) ? gettype($responseData['data']) : 'not_set',
                ], JSON_UNESCAPED_UNICODE));
            }

            $extraction = MeituanRankDataExtractionService::extractForPersistenceWithSource($responseData);
            $dataList = $extraction['rows'];
            if ($debugLog && $extraction['source'] !== '') {
                \think\facade\Log::info('Meituan rank data extraction - source: ' . $extraction['source'] . ', count: ' . count($dataList));
            }

            if (empty($dataList)) {
                // 无数据可解析，记录警告
                \think\facade\Log::warning('美团数据解析 - 未能解析到有效数据');
                return 0;
            }

            $savedCount = 0;
            $dataDate = $startDate ?: date('Y-m-d', strtotime('-1 day'));
            $rankDataType = 'peer_rank';

            // 记录第一个数据项的字段结构
            if ($debugLog && !empty($dataList[0])) {
                \think\facade\Log::info('美团数据解析 - 首条数据字段: ' . json_encode(array_keys($dataList[0]), JSON_UNESCAPED_UNICODE));
                \think\facade\Log::info('美团数据解析 - 首条数据样例: ' . json_encode($dataList[0], JSON_UNESCAPED_UNICODE));
            }

            foreach ($dataList as $item) {
                if (!is_array($item)) continue;

                // 美团数据简化存储：只保存 poiName 和 dataValue
                $hotelId = $item['poiId'] ?? $item['poi_id'] ?? $item['shopId'] ?? $item['shop_id'] ?? $item['hotelId'] ?? null;
                if (empty($hotelId)) {
                    \think\facade\Log::warning('美团数据解析 - 跳过无酒店ID的数据: ' . json_encode($item, JSON_UNESCAPED_UNICODE));
                    continue;
                }

                $hotelName = $item['poiName'] ?? $item['poi_name'] ?? $item['shopName'] ?? $item['shop_name'] ?? $item['hotelName'] ?? $item['name'] ?? '';

                // 美团竞对排名数据：平台新版可能只返回 percent，不再返回 dataValue。
                $sourceDataValue = $this->nullableNumberFromKeys($item, ['dataValue', 'data_value', 'monthRoomNights', 'month_room_nights']);
                $rankPercent = $this->meituanRankPercentValue($item);
                $dataValue = $sourceDataValue;
                $metricStatus = $sourceDataValue !== null
                    ? 'platform_value_returned'
                    : ($rankPercent !== null ? 'platform_percent_only' : 'platform_value_missing');

                $itemDate = $item['date'] ?? $item['dataDate'] ?? $item['statDate'] ?? $item['stat_date'] ?? $dataDate;
                $dimName = $item['_dimName'] ?? ($item['dimension'] ?? '');
                $aiMetricName = $item['_aiMetricName'] ?? ($item['aiMetricName'] ?? '');

                // 判断榜单类型：P_XS=销售榜(包含销售间夜榜+销售额榜), P_RZ=入住榜, P_ZH=转化榜, P_LL=流量榜
                $rankType = strtoupper(trim((string)($item['rankType'] ?? $item['rank_type'] ?? $context['rank_type'] ?? $context['rankType'] ?? '')));
                $dateRange = trim((string)($context['date_range'] ?? $context['dateRange'] ?? $item['dateRange'] ?? $item['date_range'] ?? ''));
                $identityStartDate = trim((string)($context['start_date'] ?? $context['startDate'] ?? $startDate));
                $identityEndDate = trim((string)($context['end_date'] ?? $context['endDate'] ?? $endDate));
                $storageDimension = $this->buildRankStorageDimension($dimName, $rankType, $dateRange, $identityStartDate, $identityEndDate);
                $platformTagInfo = $this->extractMeituanPlatformTagInfo($item);
                $hasVipTag = $this->hasMeituanVipPlatformTag($platformTagInfo['tags']);
                $platformTagText = !empty($platformTagInfo['tags']) ? implode(' / ', $platformTagInfo['tags']) : '未返回';

                // 精确匹配子榜单类型 - 扩展关键词匹配
                // 结合dimName和aiMetricName进行判断，提高准确性
                $combinedName = $dimName . '|' . $aiMetricName;

                $isAmountMetric = strpos($combinedName, '销售额') !== false
                    || strpos($combinedName, '交易额') !== false
                    || strpos($combinedName, '房费收入') !== false
                    || strpos($combinedName, '房费') !== false
                    || strpos($combinedName, '收入') !== false
                    || strpos($combinedName, '金额') !== false
                    || strpos(strtoupper($combinedName), 'AMT') !== false
                    || strpos(strtoupper($combinedName), 'AMOUNT') !== false;
                $isSalesAmountRank = $rankType === 'P_XS' && $isAmountMetric;
                $isRoomRevenueRank = $rankType === 'P_RZ' && $isAmountMetric;
                // 销售间夜榜：包含"间夜"但不包含"额"（避免与销售额混淆）
                // 同时检查aiMetricName，因为有些API返回的dimName可能是"销售榜"，而aiMetricName才是"销售间夜"
                $isRoomNightMetric = (strpos($combinedName, '间夜') !== false && strpos($combinedName, '额') === false) || strpos($combinedName, '入住') !== false || strpos($combinedName, ' Nights') !== false || strpos($combinedName, 'nights') !== false || strpos($aiMetricName, '间夜') !== false;
                $isRoomNightRank = in_array($rankType, ['P_RZ', 'P_XS'], true) && $isRoomNightMetric;
                $isConversionRank = strpos($combinedName, '转化') !== false || strpos($combinedName, '支付') !== false || $rankType === 'P_ZH';
                $isTrafficRank = strpos($combinedName, '曝光') !== false || strpos($combinedName, '浏览') !== false || strpos($combinedName, '流量') !== false || strpos($combinedName, '访客') !== false || $rankType === 'P_LL';

                // 详细调试日志：记录每个数据项的判断过程
                \think\facade\Log::info("美团数据解析 - 详细判断: dimName=$dimName, rankType=$rankType, dataValue=$dataValue, percent=" . ($rankPercent ?? 'null') . ", metricStatus=$metricStatus, isSalesAmountRank=" . ($isSalesAmountRank ? 'true' : 'false') . ", isRoomRevenueRank=" . ($isRoomRevenueRank ? 'true' : 'false') . ", isRoomNightRank=" . ($isRoomNightRank ? 'true' : 'false'));
                \think\facade\Log::info("美团数据解析 - 完整数据项: " . json_encode($item, JSON_UNESCAPED_UNICODE));

                $storageValues = $this->buildRankMetricStorageValues(
                    $sourceDataValue,
                    $isRoomNightRank,
                    $isSalesAmountRank || $isRoomRevenueRank,
                    $isConversionRank,
                    $isTrafficRank
                );
                $dataValue = $storageValues['data_value'];
                $amount = $storageValues['amount'];
                $quantity = $storageValues['quantity'];

                // 详细记录榜单类型判断结果
                \think\facade\Log::info("美团数据解析 - 榜单判断: dimName=$dimName, rankType=$rankType, isSalesAmountRank=" . ($isSalesAmountRank ? 'true' : 'false') . ", isRoomNightRank=" . ($isRoomNightRank ? 'true' : 'false') . ", isConversionRank=" . ($isConversionRank ? 'true' : 'false') . ", isTrafficRank=" . ($isTrafficRank ? 'true' : 'false'));
                \think\facade\Log::info("美团数据解析 - 保存数据: hotelName=$hotelName, dataValue=$dataValue, percent=" . ($rankPercent ?? 'null') . ", metricStatus=$metricStatus, amount=$amount, quantity=$quantity, dataDate=$itemDate, dimName=$dimName");

                // 检查是否已存在（按酒店名称、日期、来源、维度去重）
                $columns = OnlineDailyDataPersistenceService::getColumns();
                $periodFilter = OnlineDailyDataPersistenceService::applyPeriodFields([
                    'data_date' => $itemDate,
                    'source' => 'meituan',
                    'data_type' => $rankDataType,
                    'dimension' => $storageDimension,
                ], $columns, $item);

                $query = Db::name('online_daily_data')
                    ->where('hotel_name', $hotelName)
                    ->where('data_date', $itemDate)
                    ->where('source', 'meituan')
                    ->where('dimension', $storageDimension);
                if (isset($columns['hotel_id'])) {
                    $query->where('hotel_id', (string)$hotelId);
                }
                if (isset($columns['data_type'])) {
                    $query->where('data_type', $rankDataType);
                }
                OnlineDailyDataPersistenceService::applyPeriodQuery($query, $periodFilter, $columns);

                if ($systemHotelId !== null) {
                    $query->where('system_hotel_id', $systemHotelId);
                }

                $exists = $query->find();

                $rawData = [
                    'poiName' => $hotelName,
                    'dataValue' => $sourceDataValue,
                    'percent' => $rankPercent,
                    'metricStatus' => $metricStatus,
                    'rankType' => $rankType,
                    'rank' => $item['rank'] ?? $item['ranking'] ?? null,
                    'dateRange' => $dateRange,
                    'startDate' => $identityStartDate,
                    'endDate' => $identityEndDate,
                    'dimension' => $dimName,
                    'storageDimension' => $storageDimension,
                    'aiMetricName' => $aiMetricName,
                    'platformTags' => $platformTagInfo['tags'],
                    'platformTagStatus' => $platformTagInfo['status'],
                    'platformTagText' => $platformTagText,
                    'hasVipTag' => $hasVipTag,
                    'sourceLabel' => $sourceDataValue !== null ? '美团榜单返回' : ($rankPercent !== null ? '美团仅返回百分比' : '美团榜单未返回数值'),
                ];
                $targetPoiId = trim((string)($context['target_poi_id'] ?? $context['targetPoiId'] ?? ''));
                if ($targetPoiId !== '' && (string)$hotelId === $targetPoiId) {
                    $selfMetricValues = $context['self_metric_values'] ?? $context['selfMetricValues'] ?? [];
                    if (is_array($selfMetricValues) && !empty($selfMetricValues)) {
                        $rawData['selfMetricValues'] = $selfMetricValues;
                        $rawData['selfMetricStatus'] = (string)($context['self_metric_status'] ?? $context['selfMetricStatus'] ?? 'returned');
                    }
                }
                $sourcePath = trim((string)($item['_source_path'] ?? ''));
                if ($sourcePath !== '') {
                    $rawData['_source_path'] = $sourcePath;
                }
                if (trim((string)($extraction['source'] ?? '')) !== '') {
                    $rawData['_capture_source'] = (string)$extraction['source'];
                }

                // 保存数据：根据榜单类型设置 amount 或 quantity
                $data = [
                    'hotel_id' => (string)$hotelId,
                    'hotel_name' => $hotelName,
                    'system_hotel_id' => $systemHotelId,
                    'data_date' => $itemDate,
                    'data_value' => $dataValue,
                    'amount' => $amount,
                    'quantity' => $quantity,
                    'book_order_num' => 0,
                    'comment_score' => 0,
                    'qunar_comment_score' => 0,
                    'source' => 'meituan',
                    'dimension' => $storageDimension,
                    'data_type' => $rankDataType,
                    'raw_data' => json_encode($rawData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
                $data = OnlineDataFieldFactService::attachToOnlineDailyRow($data, $item);
                $data = OnlineDailyDataPersistenceService::applyValidationFields($data);

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
        } catch (\Throwable $e) {
            \think\facade\Log::error('Meituan rank persistence failed: ' . $e->getMessage());
            throw new \RuntimeException('meituan_rank_persistence_failed', 500, $e);
        }
    }

    private function buildRankStorageDimension(string $dimension, string $rankType, string $dateRange, string $startDate, string $endDate): string
    {
        $dimension = trim($dimension) !== '' ? trim($dimension) : 'unknown';
        $rankType = strtoupper(trim($rankType)) !== '' ? strtoupper(trim($rankType)) : 'UNKNOWN';
        $dateRange = trim($dateRange) !== '' ? trim($dateRange) : 'unknown';
        $windowHash = substr(hash('sha256', trim($startDate) . '|' . trim($endDate)), 0, 12);
        $identitySuffix = 'rank=' . $rankType . '|range=' . $dateRange . '|window=' . $windowHash;
        $dimensionLimit = max(1, 100 - mb_strlen($identitySuffix) - 1);

        return mb_substr($dimension, 0, $dimensionLimit) . '|' . $identitySuffix;
    }

    /**
     * @return array{data_value:?float,amount:?float,quantity:?int}
     */
    private function buildRankMetricStorageValues(?float $sourceDataValue, bool $isRoomNightRank, bool $isAmountRank, bool $isConversionRank, bool $isTrafficRank): array
    {
        if ($sourceDataValue === null) {
            return ['data_value' => null, 'amount' => null, 'quantity' => null];
        }

        if ($isRoomNightRank) {
            return ['data_value' => $sourceDataValue, 'amount' => 0.0, 'quantity' => max(0, (int)$sourceDataValue)];
        }
        if ($isAmountRank) {
            return ['data_value' => $sourceDataValue, 'amount' => $sourceDataValue, 'quantity' => 0];
        }
        if ($isConversionRank || $isTrafficRank) {
            return ['data_value' => $sourceDataValue, 'amount' => 0.0, 'quantity' => 0];
        }

        return ['data_value' => $sourceDataValue, 'amount' => 0.0, 'quantity' => 0];
    }

    private function nullableNumberFromKeys(array $data, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
                continue;
            }
            $value = is_string($data[$key]) ? str_replace([',', '%', ' '], '', trim($data[$key])) : $data[$key];
            if (is_numeric($value)) {
                return (float)$value;
            }
        }
        return null;
    }

    private function meituanRankPercentValue(array $item): ?float
    {
        return $this->nullableNumberFromKeys($item, [
            'percent',
            'rankPercent',
            'rank_percent',
            'dataPercent',
            'data_percent',
            'ratio',
            'percentage',
        ]);
    }

    private function extractMeituanPlatformTagInfo(array $item): array
    {
        $tagKeys = [
            'tags', 'tagList', 'tag_list', 'labels', 'labelList', 'label_list',
            'hotelTags', 'hotelTagList', 'poiTagList', 'rightsTags', 'rightsTagList',
            'badgeList', 'benefitTags', 'titleTags', 'identityTags', 'platformTags',
        ];
        $singleTagKeys = [
            'memberTag', 'rightsTag', 'platformTag', 'crownLevel', 'crownTag',
            'brandTag', 'brandName', 'chainName', 'hotelBrand', 'groupName', 'starTag',
        ];
        $booleanVipKeys = ['vipTag', 'isVip', 'isVIP', 'vip', 'vipFlag', 'memberFlag', 'isMemberHotel'];

        $tags = [];
        $returned = false;
        foreach ($tagKeys as $key) {
            if (array_key_exists($key, $item)) {
                $returned = true;
                $tags = $this->mergeStringList($tags, $this->collectMeituanTagTokens($item[$key]));
            }
        }
        foreach ($singleTagKeys as $key) {
            if (array_key_exists($key, $item)) {
                $returned = true;
                $tokens = $this->collectMeituanTagTokens($item[$key]);
                if (in_array($key, ['crownLevel', 'crownTag'], true)) {
                    $tokens = array_map(static function ($token): string {
                        $text = trim((string)$token);
                        return preg_match('/^\d+$/', $text) ? ('冠级' . $text) : $text;
                    }, $tokens);
                }
                $tags = $this->mergeStringList($tags, $tokens);
            }
        }
        foreach ($booleanVipKeys as $key) {
            if (array_key_exists($key, $item)) {
                $returned = true;
                if ($this->isExplicitTruthy($item[$key])) {
                    $tags = $this->mergeStringList($tags, ['VIP']);
                }
            }
        }

        $tags = array_values(array_filter(array_map([$this, 'normalizeMeituanPlatformTag'], $tags), static fn($tag): bool => $tag !== ''));
        $tags = $this->mergeStringList([], $tags);
        return [
            'tags' => $tags,
            'status' => !empty($tags) ? 'returned' : ($returned ? 'returned_empty' : 'not_returned'),
        ];
    }

    private function collectMeituanTagTokens($value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_scalar($value)) {
            return [(string)$value];
        }
        if (!is_array($value)) {
            return [];
        }

        $tokens = [];
        $preferredKeys = ['name', 'tagName', 'tag_name', 'label', 'text', 'title', 'value', 'displayName', 'rightsName'];
        foreach ($preferredKeys as $key) {
            if (array_key_exists($key, $value) && is_scalar($value[$key]) && trim((string)$value[$key]) !== '') {
                $tokens[] = (string)$value[$key];
            }
        }
        if (!empty($tokens)) {
            return $tokens;
        }
        foreach ($value as $child) {
            $tokens = $this->mergeStringList($tokens, $this->collectMeituanTagTokens($child));
        }
        return $tokens;
    }

    private function normalizeMeituanPlatformTag(string $tag): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $tag) ?: $tag);
        if ($value === '') {
            return '';
        }
        if (preg_match('/\bvip\b/i', $value)) {
            return 'VIP';
        }
        if (preg_match('/^(?:0|1|true|false|yes|no)$/i', $value) || preg_match('/^\d+$/', $value)) {
            return '';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($value, 'UTF-8') > 24 ? mb_substr($value, 0, 24, 'UTF-8') : $value;
        }
        return strlen($value) > 72 ? substr($value, 0, 72) : $value;
    }

    private function hasMeituanVipPlatformTag(array $tags): bool
    {
        foreach ($tags as $tag) {
            if (preg_match('/\bvip\b/i', (string)$tag)) {
                return true;
            }
        }
        return false;
    }

    private function mergeStringList(array $base, array $incoming): array
    {
        $seen = [];
        $result = [];
        foreach (array_merge($base, $incoming) as $value) {
            $text = trim((string)$value);
            if ($text === '') {
                continue;
            }
            $key = strtolower($text);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $text;
        }
        return $result;
    }

    private function isExplicitTruthy($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (float)$value > 0;
        }
        $text = strtolower(trim((string)$value));
        return in_array($text, ['1', 'true', 'yes', 'y', 'vip'], true);
    }
}
