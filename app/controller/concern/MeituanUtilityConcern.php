<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\service\CtripTrafficDisplayService;
use think\facade\Db;

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

    private function inferMeituanMinimalPercentScale(array $hotelMap, string $field): ?int
    {
        $percents = [];
        foreach ($hotelMap as $row) {
            if (!is_array($row)) {
                continue;
            }
            $percent = $this->meituanMetricRankPercent($row, $field);
            if ($percent === null || $percent < 0 || $percent > 100.005) {
                continue;
            }
            $percents[sprintf('%.2F', round($percent, 2))] = round($percent, 2);
        }

        $positivePercents = array_values(array_filter($percents, static fn(float $percent): bool => $percent > 0));
        if (count($positivePercents) < 2 || abs(max($positivePercents) - 100.0) > 0.005) {
            return null;
        }

        sort($positivePercents, SORT_NUMERIC);
        $maxScale = 50000;
        for ($scale = 1; $scale <= $maxScale; $scale++) {
            if ($this->meituanPercentScaleMatches($scale, $positivePercents)) {
                return $scale;
            }
        }

        return null;
    }

    private function meituanPercentScaleMatches(int $scale, array $percents): bool
    {
        foreach ($percents as $percent) {
            $value = (int)round($scale * $percent / 100);
            if ($percent > 0 && $value <= 0) {
                return false;
            }
            $restoredPercent = round($value * 100 / $scale, 2);
            if (abs($restoredPercent - $percent) > 0.0050001) {
                return false;
            }
        }
        return true;
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
            'viewConversion' => ['viewConversion', 'view_conversion'],
            'payConversion' => ['payConversion', 'pay_conversion'],
            'exposure' => ['exposure', 'listExposure', 'list_exposure'],
            'views' => ['views', 'view', 'detailExposure', 'detail_exposure'],
        ];
        $result = [];
        foreach ($aliases as $field => $keys) {
            $number = $this->nullableNumberFromKeys($value, $keys);
            if ($number !== null) {
                $result[$field] = $number;
            }
        }
        return $result;
    }

    private function roundMeituanDerivedMetric(string $field, float $value): float
    {
        if (in_array($field, ['viewConversion', 'payConversion'], true)) {
            return round($value, 4);
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
        $checkIn = $this->normalizeOnlineDataDate($this->firstMeituanValue($item, ['check_in_date', 'checkInDate'], ''));
        $checkOut = $this->normalizeOnlineDataDate($this->firstMeituanValue($item, ['check_out_date', 'checkOutDate'], ''));
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
     * Parse and save Meituan comment API data.
     */
    private function parseAndSaveMeituanComments(array $comments, string $poiId, string $partnerId, ?int $systemHotelId = null): int
    {
        return 0;

        $savedCount = 0;
        $dataDate = date('Y-m-d');

        foreach ($comments as $comment) {
            try {
                $commentId = $comment['id'] ?? $comment['commentId'] ?? null;
                if (!$commentId) {
                    continue;
                }

                // 检查是否已存在相同的评论
                $existing = Db::name('online_daily_data')
                    ->where('source', 'meituan')
                    ->where('data_type', 'review')
                    ->where('raw_data', 'like', '%"' . $commentId . '"%')
                    ->first();

                if ($existing) {
                    continue;
                }

                // 提取评论内容（美团字段：comment）
                $content = $comment['comment'] ?? $comment['content'] ?? $comment['commentContent'] ?? '';

                // 美团评分是50分制：50=5星, 40=4星, 30=3星...
                $score = $comment['score'] ?? $comment['star'] ?? 0;
                $starRating = $score / 10; // 转换为星级（1-5）

                // 时间处理（美团返回毫秒时间戳）
                $commentTime = $comment['commentTime'] ?? $comment['createTime'] ?? null;
                if ($commentTime && is_numeric($commentTime)) {
                    $commentTime = date('Y-m-d H:i:s', $commentTime / 1000);
                } else {
                    $commentTime = $commentTime ?: null;
                }

                $userName = $comment['userName'] ?? $comment['nickName'] ?? '匿名用户';
                $hotelName = $comment['poiName'] ?? $comment['hotelName'] ?? '';
                $bizReply = $comment['bizReply'] ?? ''; // 商家回复
                $badComment = $comment['badComment'] ?? false; // 是否差评

                // 检查内容是否为空（如果评论为空但有商家回复也保存）
                if (empty($content) && empty($bizReply)) {
                    continue;
                }

                // 保存到数据库
                $insertData = [
                    'hotel_id' => $systemHotelId,
                    'hotel_name' => $hotelName,
                    'source' => 'meituan',
                    'data_type' => 'review',
                    'data_date' => $dataDate,
                    'amount' => 0,
                    'quantity' => 1,
                    'book_order_num' => 0,
                    'comment_score' => $starRating,
                    'qunar_comment_score' => 0,
                    'raw_data' => json_encode($comment, JSON_UNESCAPED_UNICODE),
                    'create_time' => date('Y-m-d H:i:s'),
                    'update_time' => date('Y-m-d H:i:s'),
                ];
                $insertData = $this->applyOnlineDailyDataValidationFields($insertData);

                $inserted = Db::name('online_daily_data')->insertGetId($insertData);
                if ($inserted) {
                    $savedCount++;
                }
            } catch (\Throwable $e) {
                // 记录错误但继续处理下一条
                error_log('保存美团评论失败: ' . $e->getMessage());
            }
        }

        return $savedCount;
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
