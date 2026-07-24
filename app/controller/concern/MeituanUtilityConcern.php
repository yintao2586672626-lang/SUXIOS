<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\service\CtripTrafficDisplayService;

trait MeituanUtilityConcern
{
    private function trimMeituanCaptureLog(string $value): string
    {
        $value = trim($value);
        if (mb_strlen($value) <= 2000) {
            return $value;
        }
        return mb_substr($value, -2000);
    }

    private function firstMeituanValue(array $data, array $keys, $default = null)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
                return $data[$key];
            }
        }
        return $default;
    }

    private function meituanNumber(array $data, array $keys, float $default = 0.0): float
    {
        $value = $this->firstMeituanValue($data, $keys, null);
        if (is_string($value)) {
            $value = str_replace([',', '%', '￥', '¥', '元', ' '], '', trim($value));
        }
        return is_numeric($value) ? (float)$value : $default;
    }

    private function numberFromKeys(array $data, array $keys, float $default = 0.0): float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
                $value = is_string($data[$key]) ? str_replace([',', '%', ' '], '', trim($data[$key])) : $data[$key];
                return is_numeric($value) ? (float)$value : $default;
            }
        }
        return $default;
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

    private function isMeituanIntegerScaleMetric(string $field): bool
    {
        return in_array($field, ['roomNights', 'salesRoomNights', 'exposure', 'views'], true);
    }

    private function meituanMetricRankPercent(array $row, string $field): ?float
    {
        $percents = is_array($row['metricRankPercent'] ?? null) ? $row['metricRankPercent'] : [];
        return isset($percents[$field]) && is_numeric($percents[$field]) ? (float)$percents[$field] : null;
    }

    private function meituanSelfMetricValue(string $field, array $selfRow, array $context = []): ?float
    {
        $values = $this->normalizeMeituanSelfMetricValues($context['self_metric_values'] ?? $context['selfMetricValues'] ?? []);
        if (isset($values[$field])) {
            return $values[$field];
        }
        $value = (float)($selfRow[$field] ?? 0);
        return $value > 0 ? $value : null;
    }

    private function normalizeMeituanSelfMetricValues($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value)) {
            return [];
        }

        $aliases = [
            'roomNights' => ['roomNights', 'room_nights', 'quantity', 'stayRoomNights'],
            'roomRevenue' => ['roomRevenue', 'room_revenue', 'stayRevenue', 'stay_revenue'],
            'salesRoomNights' => ['salesRoomNights', 'sales_room_nights', 'salesQuantity'],
            'sales' => ['sales', 'salesAmount', 'sales_amount', 'amount'],
            'orderCount' => ['orderCount', 'order_count', 'payOrderCnt', 'pay_order_cnt', 'payOrderCount', 'pay_order_count'],
            'viewConversion' => ['viewConversion', 'view_conversion', 'intentionPerExposure', 'intention_per_exposure'],
            'payConversion' => ['payConversion', 'pay_conversion', 'payOrderPerIntention', 'pay_order_per_intention'],
            'exposure' => ['exposure', 'listExposure', 'list_exposure', 'exposureUV', 'exposure_uv'],
            'views' => ['views', 'view', 'detailExposure', 'detail_exposure', 'intentionUV', 'intention_uv'],
        ];
        $result = [];
        foreach ($aliases as $field => $keys) {
            $number = $this->nullableNumberFromKeys($value, $keys);
            if ($number !== null) {
                if (in_array($field, ['viewConversion', 'payConversion'], true)) {
                    $number = $this->normalizeMeituanRatioMetric($number);
                }
                $result[$field] = $number;
            }
        }
        $result = $this->mergeMeituanSelfMetricCardValues($result, $value);
        foreach ([$value['myHotel'] ?? null, $value['data']['myHotel'] ?? null] as $nested) {
            if (!is_array($nested)) {
                continue;
            }
            foreach ($this->normalizeMeituanSelfMetricValues($nested) as $field => $number) {
                if (!isset($result[$field])) {
                    $result[$field] = $number;
                }
            }
        }
        return $result;
    }

    private function mergeMeituanSelfMetricCardValues(array $result, array $payload): array
    {
        $cards = $this->extractMeituanSelfMetricCards($payload);
        foreach ($cards as $card) {
            if (!is_array($card)) {
                continue;
            }
            $field = $this->meituanSelfMetricFieldFromCard($card);
            if ($field === '' || isset($result[$field])) {
                continue;
            }
            $number = $this->meituanSelfMetricCardNumber($card, $field);
            if ($number !== null) {
                $result[$field] = $number;
            }
        }
        return $result;
    }

    private function extractMeituanSelfMetricCards(array $payload): array
    {
        if (isset($payload['cards']) && is_array($payload['cards'])) {
            return $payload['cards'];
        }
        if (isset($payload['data']) && is_array($payload['data'])) {
            if (isset($payload['data']['cards']) && is_array($payload['data']['cards'])) {
                return $payload['data']['cards'];
            }
            if (isset($payload['data']['businessData']['cards']) && is_array($payload['data']['businessData']['cards'])) {
                return $payload['data']['businessData']['cards'];
            }
        }

        $numericKeys = 0;
        foreach (array_keys($payload) as $key) {
            if (is_int($key)) {
                $numericKeys++;
            }
        }
        return $numericKeys > 0 && $numericKeys === count($payload) ? $payload : [];
    }

    private function meituanSelfMetricFieldFromCard(array $card): string
    {
        $cardId = strtoupper(trim((string)($card['id'] ?? '')));
        $stringIdMap = [
            'EXPOSE_PV_CNT' => 'exposure',
            'INTENTION_UV' => 'views',
            'PAY_ORDER_CNT_UV' => 'payConversion',
            'PAY_ORDER_CNT' => 'orderCount',
            'PAY_ROOMNIGHT' => 'salesRoomNights',
            'PAY_AMT' => 'sales',
            'CONSUME_ROOMNIGHT_SPLIT_EX_7DAYS_REFUND' => 'roomNights',
        ];
        if (isset($stringIdMap[$cardId])) {
            return $stringIdMap[$cardId];
        }

        $id = (int)($card['id'] ?? 0);
        $idMap = [
            1 => 'salesRoomNights',
            2 => 'sales',
            3 => 'orderCount',
            4 => 'roomNights',
            5 => 'roomRevenue',
        ];
        if (isset($idMap[$id])) {
            return $idMap[$id];
        }

        $title = trim((string)($card['title'] ?? $card['name'] ?? $card['label'] ?? ''));
        if ($title === '') {
            return '';
        }
        if (str_contains($title, '销售间夜')) {
            return 'salesRoomNights';
        }
        if (str_contains($title, '销售额') || str_contains($title, '交易额')) {
            return 'sales';
        }
        if (str_contains($title, '入住间夜')) {
            return 'roomNights';
        }
        if (str_contains($title, '入住金额') || str_contains($title, '房费收入') || str_contains($title, '入住收入')) {
            return 'roomRevenue';
        }
        return '';
    }

    private function meituanSelfMetricCardNumber(array $card, string $field = ''): ?float
    {
        $number = $this->nullableNumberFromKeys($card, ['value', 'dataValue', 'data_value', 'amount']);
        if ($number === null) {
            return null;
        }
        $unitText = implode(' ', array_map(static fn($value): string => (string)$value, [
            $card['unit'] ?? '',
            $card['suffix'] ?? '',
            $card['valueUnit'] ?? '',
            $card['value_unit'] ?? '',
            $card['unitName'] ?? '',
            $card['unit_name'] ?? '',
        ]));
        if ($this->isMeituanTenThousandUnit($unitText)) {
            $number *= 10000;
            return $number;
        }
        if (in_array($field, ['viewConversion', 'payConversion'], true)) {
            return $this->normalizeMeituanRatioMetric($number);
        }
        return $number;
    }

    private function isMeituanTenThousandUnit(string $unit): bool
    {
        $unit = trim($unit);
        if ($unit === '') {
            return false;
        }
        $legacyMojibakeWan = "\xE6\xB6\x93\xE5\x9B\xA7";

        return str_contains($unit, '万')
            || str_contains($unit, '萬')
            || str_contains($unit, $legacyMojibakeWan)
            || preg_match('/\bw\b/i', $unit) === 1;
    }

    private function normalizeMeituanRatioMetric(float $value): float
    {
        return round(abs($value) > 1 ? $value / 100 : $value, 4);
    }

    private function roundMeituanDerivedMetric(string $field, float $value): float
    {
        if (in_array($field, ['viewConversion', 'payConversion'], true)) {
            return round($value, 4);
        }
        if ($this->isMeituanIntegerScaleMetric($field)) {
            return (float)(int)round($value);
        }
        if (in_array($field, ['roomRevenue', 'sales'], true)) {
            return (float)(int)round($value);
        }
        return round($value, 2);
    }

    private function formatMeituanDisplayNumber(float $value, int $decimals = 0): string
    {
        if ($decimals <= 0) {
            return number_format((float)round($value));
        }

        $formatted = number_format($value, $decimals, '.', ',');
        return rtrim(rtrim($formatted, '0'), '.');
    }

    private function normalizeMeituanPercentValue($value): ?float
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
        return round(CtripTrafficDisplayService::normalizeTrafficPercent((float)$value), 2);
    }

    private function normalizeMeituanScore($value): float
    {
        if (is_string($value)) {
            $value = str_replace([',', '%'], '', trim($value));
        }
        if (!is_numeric($value)) {
            return 0.0;
        }
        $score = (float)$value;
        if ($score > 5 && $score <= 50) {
            return round($score / 10, 1);
        }
        if ($score > 50 && $score <= 100) {
            return round($score / 20, 1);
        }
        return round($score, 1);
    }

    private function calculateMeituanOrderNights(array $item): int
    {
        $checkIn = $this->normalizeOnlineDataDate($this->firstMeituanValue($item, ['check_in_date', 'checkInDate', 'checkIn', 'check_in', 'arrivalDate', '入住日期'], ''));
        $checkOut = $this->normalizeOnlineDataDate($this->firstMeituanValue($item, ['check_out_date', 'checkOutDate', 'checkOut', 'check_out', 'departureDate', '离店日期'], ''));
        if ($checkIn === '' || $checkOut === '') {
            return 0;
        }
        $start = strtotime($checkIn);
        $end = strtotime($checkOut);
        if ($start === false || $end === false || $end <= $start) {
            return 0;
        }
        return (int)max(1, floor(($end - $start) / 86400));
    }

    private function readNestedMeituanValue(array $data, array $path)
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

    private function isSequentialArray(array $value): bool
    {
        if ($value === []) {
            return true;
        }
        return array_keys($value) === range(0, count($value) - 1);
    }

    private function meituanBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value === 1;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'y'], true);
    }

    /**
     * 确保字符串是有效的UTF-8编码
     */
    private function ensureUtf8String(?string $str): string
    {
        if (empty($str)) {
            return '';
        }

        // 方法1: 使用iconv移除无效字符
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $str);
            if ($converted !== false) {
                $str = $converted;
            }
        }

        // 方法2: 检测并转换编码
        $encoding = mb_detect_encoding($str, ['UTF-8', 'GBK', 'GB2312', 'BIG5', 'ISO-8859-1'], true);
        if ($encoding && $encoding !== 'UTF-8') {
            $str = mb_convert_encoding($str, 'UTF-8', $encoding);
        }

        // 方法3: 最后检查是否为有效UTF-8，如果无效则使用强制转换
        if (!mb_check_encoding($str, 'UTF-8')) {
            // 强制转换为UTF-8，忽略无效字符
            $str = utf8_encode($str);
        }

        // 移除控制字符（保留换行和制表符）
        $str = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $str);

        return $str;
    }

    /**
     * 递归确保数组中所有字符串都是UTF-8编码
     */
    private function ensureUtf8($data)
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->ensureUtf8($value);
            }
            return $data;
        } elseif (is_string($data)) {
            return $this->ensureUtf8String($data);
        }
        return $data;
    }

    /**
     * 发送美团HTTP请求
     */
}
