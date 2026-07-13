<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\service\CtripCompetitionCirclePersistenceService;
use app\service\MeituanManualFetchRequestService;
use app\service\MeituanOnlineDataPersistenceService;
use app\service\MeituanRankDataExtractionService;
use think\facade\Db;

trait BusinessDisplayConcern
{
    private function sendMeituanRequest(string $url, array $params, string $cookies, array $authData = []): array
    {
        if (!$this->isAllowedOtaRequestUrl($url, ['meituan.com'])) {
            return ['success' => false, 'error' => '仅允许请求美团官方域名'];
        }

        $fullUrl = $url;
        if (!empty($params)) {
            $fullUrl .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
        }

        // 基础请求头
        $headers = [
            'Cookie: ' . $cookies,
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept: application/json, text/plain, */*',
            'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
            'Referer: https://eb.meituan.com/',
            'Origin: https://eb.meituan.com',
        ];

        // 添加认证数据作为额外的请求头
        if (!empty($authData)) {
            if (!empty($authData['token'])) {
                $headers[] = 'Authorization: Bearer ' . $authData['token'];
            }
            if (!empty($authData['access_token'])) {
                $headers[] = 'access-token: ' . $authData['access_token'];
            }
            if (!empty($authData['auth_token'])) {
                $headers[] = 'auth-token: ' . $authData['auth_token'];
            }
            // 美团可能使用的其他认证header
            foreach ($authData as $key => $value) {
                if (strpos(strtolower($key), 'token') !== false && !in_array($key, ['token', 'access_token', 'auth_token'])) {
                    $headerKey = str_replace('_', '-', $key);
                    $headers[] = $headerKey . ': ' . $value;
                }
            }
        }

        // 使用file_get_contents替代curl（沙箱环境无curl扩展）
        $options = [
            'http' => [
                'method' => 'GET',
                'timeout' => 30,
                'header' => implode("\r\n", $headers),
            ],
            'ssl' => $this->buildStreamSslOptions(),
        ];

        $context = stream_context_create($options);
        $response = @file_get_contents($fullUrl, false, $context);

        if ($response === false) {
            $error = error_get_last();
            $errorMsg = is_array($error) ? ($error['message'] ?? '请求失败') : '请求失败';
            return ['success' => false, 'error' => $errorMsg];
        }

        // 解析HTTP响应头获取状态码
        $httpCode = 200;
        if (isset($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('/HTTP\/\d+\.?\d*\s+(\d+)/', $header, $matches)) {
                    $httpCode = (int)$matches[1];
                    break;
                }
            }
        }

        // 在JSON解析之前清理响应字符串中的无效UTF-8字符
        $response = $this->sanitizeJsonString($response);

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['success' => false, 'error' => 'JSON解析失败: ' . substr((string)$response, 0, 200)];
        }

        // 递归清理数据中的无效UTF-8字符
        $data = $this->ensureUtf8($data);

        // 检查美团API业务状态码
        // 美团API可能返回: { code: 0, msg: 'success' } 或 { status: 0, message: 'success' }
        $businessCode = $data['code'] ?? $data['status'] ?? null;
        $businessMsg = $data['msg'] ?? $data['message'] ?? $data['data']['msg'] ?? '';

        // 美团API成功状态码通常是0
        if ($businessCode !== null && $businessCode !== 0 && $businessCode !== '0') {
            $failure = $this->buildMeituanBusinessFailurePayload($businessCode, (string)$businessMsg, $httpCode);
            return [
                'success' => false,
                'error' => $failure['error'],
                'reason' => $failure['reason'],
                'credential_status' => $failure['credential_status'],
                'business_code' => $businessCode,
                'business_message' => (string)$businessMsg,
                'data' => $data,
                'raw' => $response,
                'http_code' => $httpCode,
            ];
        }

        return [
            'success' => true,
            'data' => $data,
            'raw' => $response,
            'http_code' => $httpCode,
        ];
    }

    private function buildMeituanBusinessFailurePayload($businessCode, string $businessMsg, int $httpCode): array
    {
        $message = trim($businessMsg);
        $codeText = strtolower(trim((string)$businessCode));
        $loginRequired = in_array($codeText, ['303', '401', '403'], true)
            || preg_match('/未登录|尚未登录|重新登录|登录已过期|登录失效|login|required|unauthorized|forbidden/i', $message) === 1;

        if ($loginRequired) {
            return [
                'reason' => 'login_required',
                'credential_status' => 'login_required',
                'error' => '美团登录态已失效，请重新登录美团后台后更新 Cookie/API 辅助内容',
            ];
        }

        return [
            'reason' => 'meituan_api_error',
            'credential_status' => 'api_error',
            'error' => '美团API返回错误: ' . ($message !== '' ? $message : "状态码: $businessCode"),
        ];
    }

    private function fetchMeituanSelfTradeMetricValues(
        string $partnerId,
        string $poiId,
        string $startDate,
        string $endDate,
        string $cookies,
        array $authData,
        $dateRange
    ): array {
        if ($partnerId === '' || $poiId === '' || $startDate === '' || $endDate === '' || $cookies === '') {
            return [
                'status' => 'skipped',
                'values' => [],
                'message' => 'missing_partner_poi_date_or_cookie',
            ];
        }

        $url = 'https://eb.meituan.com/api/shepherdGw/bizDatacenter/hotel/eb/dataCenter/trade/manage';
        $params = [
            'poiId' => $poiId,
            'partnerId' => $partnerId,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'roomType' => 'ALL',
            'deviceType' => 1,
            'dateType' => $this->meituanSelfTradeDateType((string)$dateRange, $startDate, $endDate),
            'dataScope' => 'vpoi',
            'yodaReady' => 'h5',
            'csecplatform' => 4,
            'csecversion' => '4.2.4',
        ];
        $result = $this->sendMeituanRequest($url, $params, $cookies, $authData);
        if (empty($result['success'])) {
            return [
                'status' => 'failed',
                'values' => [],
                'message' => (string)($result['error'] ?? 'request_failed'),
            ];
        }

        $values = $this->normalizeMeituanSelfMetricValues($result['data'] ?? []);
        return [
            'status' => !empty($values) ? 'returned' : 'empty',
            'values' => $values,
            'message' => !empty($values) ? '' : 'self_trade_cards_missing',
            'update_time' => (string)($result['data']['data']['rtDataUpdateTime'] ?? ''),
        ];
    }

    private function fetchMeituanSelfDailyTradeMetricValues(
        string $partnerId,
        string $poiId,
        string $startDate,
        string $endDate,
        string $cookies,
        array $authData,
        ?callable $fetchDay = null
    ): array {
        $startTime = strtotime($startDate);
        $endTime = strtotime($endDate);
        if ($startTime === false || $endTime === false || $startTime > $endTime) {
            return ['status' => 'invalid_range', 'values' => [], 'days_requested' => 0, 'days_returned' => 0];
        }

        $daysRequested = (int)floor(($endTime - $startTime) / 86400) + 1;
        if ($daysRequested < 1 || $daysRequested > 7) {
            return ['status' => 'unsupported_range', 'values' => [], 'days_requested' => $daysRequested, 'days_returned' => 0];
        }

        $totals = [
            'roomNights' => 0.0,
            'roomRevenue' => 0.0,
            'salesRoomNights' => 0.0,
            'sales' => 0.0,
            'orderCount' => 0.0,
        ];
        $daysReturned = 0;
        for ($time = $startTime; $time <= $endTime; $time += 86400) {
            $date = date('Y-m-d', $time);
            $result = $fetchDay !== null
                ? $fetchDay($date)
                : $this->fetchMeituanSelfTradeMetricValues(
                    $partnerId,
                    $poiId,
                    $date,
                    $date,
                    $cookies,
                    $authData,
                    '1'
                );
            $values = $this->normalizeMeituanSelfMetricValues($result['values'] ?? []);
            if (empty($values)) {
                continue;
            }
            $daysReturned++;
            foreach (array_keys($totals) as $field) {
                $totals[$field] += (float)($values[$field] ?? 0);
            }
        }

        $status = $daysReturned === $daysRequested
            ? 'returned'
            : ($daysReturned > 0 ? 'partial' : 'empty');
        return [
            'status' => $status,
            'values' => $totals,
            'days_requested' => $daysRequested,
            'days_returned' => $daysReturned,
        ];
    }

    private function fetchMeituanSelfTrafficMetricValues(
        string $partnerId,
        string $poiId,
        string $startDate,
        string $endDate,
        string $cookies,
        array $authData,
        $dateRange
    ): array {
        if ($partnerId === '' || $poiId === '' || $cookies === '') {
            return [
                'status' => 'skipped',
                'values' => [],
                'message' => 'missing_partner_poi_or_cookie',
            ];
        }

        $url = 'https://eb.meituan.com/api/shepherdGw/bizDatacenter/hotel/eb/dataCenter/analyse/flowConversion';
        $params = [
            'poiId' => $poiId,
            'partnerId' => $partnerId,
            'dataScope' => 'vpoi',
            'yodaReady' => 'h5',
            'csecplatform' => 4,
            'csecversion' => '4.2.4',
        ];
        $dateRangeText = trim((string)$dateRange);
        if (in_array($dateRangeText, ['0', '1', '7', '30'], true)) {
            $params['dateRange'] = (int)$dateRangeText;
        } elseif ($startDate !== '' && $endDate !== '') {
            $params['startDate'] = str_replace('-', '', $startDate);
            $params['endDate'] = str_replace('-', '', $endDate);
            $params['dateRange'] = 1;
        } else {
            $params['dateRange'] = 1;
        }

        $result = $this->sendMeituanRequest($url, $params, $cookies, $authData);
        if (empty($result['success'])) {
            return [
                'status' => 'failed',
                'values' => [],
                'message' => (string)($result['error'] ?? 'request_failed'),
            ];
        }

        $values = $this->normalizeMeituanSelfMetricValues($result['data'] ?? []);
        return [
            'status' => !empty($values) ? 'returned' : 'empty',
            'values' => $values,
            'message' => !empty($values) ? '' : 'self_traffic_values_missing',
            'update_time' => (string)($result['data']['data']['rtDataUpdateTime'] ?? ''),
        ];
    }

    private function fetchMeituanSelfHomeBusinessMetricValues(
        string $partnerId,
        string $poiId,
        string $startDate,
        string $endDate,
        string $cookies,
        array $authData,
        $dateRange
    ): array {
        if ($partnerId === '' || $poiId === '' || $cookies === '') {
            return [
                'status' => 'skipped',
                'values' => [],
                'message' => 'missing_partner_poi_or_cookie',
            ];
        }

        $url = 'https://eb.meituan.com/api/v1/ebooking/home/businessData';
        $params = [
            'poiId' => $poiId,
            'partnerId' => $partnerId,
            'dataScope' => 'vpoi',
            'deviceType' => 1,
            'yodaReady' => 'h5',
            'csecplatform' => 4,
            'csecversion' => '4.2.4',
        ];
        $dateRangeText = trim((string)$dateRange);
        if (in_array($dateRangeText, ['0', '1', '7', '30'], true)) {
            $params['dateRange'] = (int)$dateRangeText;
        } elseif ($startDate !== '' && $endDate !== '') {
            $params['startDate'] = str_replace('-', '', $startDate);
            $params['endDate'] = str_replace('-', '', $endDate);
            $params['dateRange'] = 1;
        } else {
            $params['dateRange'] = 1;
        }

        $result = $this->sendMeituanRequest($url, $params, $cookies, $authData);
        if (empty($result['success'])) {
            return [
                'status' => 'failed',
                'values' => [],
                'message' => (string)($result['error'] ?? 'request_failed'),
            ];
        }

        $values = $this->normalizeMeituanSelfMetricValues($result['data'] ?? []);
        return [
            'status' => !empty($values) ? 'returned' : 'empty',
            'values' => $values,
            'message' => !empty($values) ? '' : 'self_home_business_values_missing',
            'update_time' => (string)($result['data']['data']['rtDataUpdateTime'] ?? ''),
        ];
    }

    private function meituanSelfTradeDateType(string $dateRange, string $startDate, string $endDate): string
    {
        if ($dateRange === '0' || $dateRange === '1' || $startDate === $endDate) {
            return 'DAY';
        }
        if ($dateRange === '7') {
            return 'WEEK';
        }
        if ($dateRange === '30') {
            return 'MONTH';
        }
        $startTime = strtotime($startDate);
        $endTime = strtotime($endDate);
        if ($startTime !== false && $endTime !== false) {
            $days = (int)floor(($endTime - $startTime) / 86400) + 1;
            if ($days <= 7) {
                return 'WEEK';
            }
        }
        return 'MONTH';
    }

    private function normalizeMeituanManualDateRange(string $startDate, string $endDate): array
    {
        return MeituanManualFetchRequestService::normalizeDateRange($startDate, $endDate);
    }

    private function sendMeituanManualRequest(string $url, array $params, string $cookies, string $method, array $allowedHostSuffixes, string $section): array
    {
        $emptyResult = [
            'http_code' => 0,
            'raw_response' => '',
            'decoded_data' => null,
            'request_url' => $url,
            'error' => '',
        ];
        if (!$this->isAllowedOtaRequestUrl($url, $allowedHostSuffixes)) {
            return array_merge($emptyResult, ['error' => '仅允许请求美团/点评官方业务域名']);
        }

        $lowerUrl = strtolower($url);
        if ($section === 'orders' && str_contains($lowerUrl, '/order-eb/index.html')) {
            return array_merge($emptyResult, ['error' => '请填写 Network 中 /orders/list 等 JSON 接口 Request URL，不要填写订单页面 URL']);
        }
        if ($section === 'ads' && str_contains($lowerUrl, '/shopdiy/account/pccpcentry')) {
            return array_merge($emptyResult, ['error' => '请填写 Network 中 cureShops 等 JSON 接口 Request URL，不要填写推广通入口页面 URL']);
        }

        $method = strtoupper($method) === 'POST' ? 'POST' : 'GET';
        $requestUrl = $url;
        $content = '';
        if ($method === 'GET') {
            if (!empty($params)) {
                $requestUrl .= (str_contains($requestUrl, '?') ? '&' : '?') . http_build_query($params);
            }
        } else {
            $content = json_encode($params, JSON_UNESCAPED_UNICODE);
            if ($content === false) {
                return array_merge($emptyResult, ['error' => '请求 Body JSON 编码失败: ' . json_last_error_msg()]);
            }
        }

        $parts = parse_url($url);
        $host = strtolower((string)($parts['host'] ?? ''));
        $origin = str_contains($host, 'dianping.com') ? 'https://ebmidas.dianping.com' : 'https://eb.meituan.com';
        $referer = $section === 'ads'
            ? 'https://ebmidas.dianping.com/shopdiy/account/pcCpcEntry'
            : 'https://eb.meituan.com/';
        $headers = [
            'Cookie: ' . $cookies,
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'Accept: application/json, text/plain, */*',
            'Accept-Language: zh-CN,zh;q=0.9',
            'Origin: ' . $origin,
            'Referer: ' . $referer,
        ];
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/json;charset=UTF-8';
            $headers[] = 'Content-Length: ' . strlen($content);
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $method === 'POST' ? $content : '',
                'timeout' => 30,
                'ignore_errors' => true,
            ],
            'ssl' => $this->buildStreamSslOptions(),
        ]);
        $rawResponse = @file_get_contents($requestUrl, false, $context);
        $httpCode = 0;
        if (isset($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('/HTTP\/\d+\.?\d*\s+(\d+)/', $header, $matches)) {
                    $httpCode = (int)$matches[1];
                    break;
                }
            }
        }

        $result = array_merge($emptyResult, [
            'http_code' => $httpCode,
            'request_url' => $requestUrl,
            'raw_response' => $rawResponse === false ? '' : (string)$rawResponse,
        ]);
        if ($rawResponse === false) {
            $error = error_get_last();
            $result['error'] = is_array($error) ? (string)($error['message'] ?? '请求失败') : '请求失败';
            return $result;
        }
        if ($httpCode > 0 && $httpCode !== 200) {
            $result['error'] = in_array($httpCode, [301, 302, 401, 403], true)
                ? 'Cookie已失效或当前账号无权限，请重新登录美团后台后复制 Cookie'
                : '美团接口 HTTP 错误: ' . $httpCode;
            return $result;
        }
        if (preg_match('/^\s*<!DOCTYPE|^\s*<html/i', (string)$rawResponse)) {
            $result['error'] = '接口返回 HTML 页面，请填写 Network 中的 JSON 接口 Request URL';
            return $result;
        }

        $sanitizedResponse = $this->sanitizeJsonString((string)$rawResponse);
        $decodedData = json_decode($sanitizedResponse, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decodedData)) {
            $result['error'] = 'JSON 解析失败: ' . json_last_error_msg();
            return $result;
        }
        $decodedData = $this->ensureUtf8($decodedData);
        $businessCode = $decodedData['code'] ?? $decodedData['status'] ?? null;
        $businessMsg = $decodedData['msg'] ?? $decodedData['message'] ?? $decodedData['data']['msg'] ?? '';
        if ($businessCode !== null && !in_array((string)$businessCode, ['0', '200', 'success', 'SUCCESS'], true)) {
            $result['decoded_data'] = $decodedData;
            $result['error'] = '美团接口返回错误: ' . ($businessMsg ?: ('状态码: ' . (string)$businessCode));
            return $result;
        }

        $result['decoded_data'] = $decodedData;
        return $result;
    }

    /**
     * 清理JSON字符串中的无效UTF-8字符
     */
    private function sanitizeJsonString(string $json): string
    {
        // 方法1: 使用iconv移除无效字符
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $json);
            if ($converted !== false) {
                $json = $converted;
            }
        }

        // 方法2: 使用正则表达式移除无效的UTF-8字节序列
        // 有效的UTF-8字节模式
        $json = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $json);

        // 方法3: 移除或替换无效的多字节序列
        // 使用mb_convert_encoding进行清理
        if (function_exists('mb_convert_encoding')) {
            // 检测编码
            $encoding = mb_detect_encoding($json, ['UTF-8', 'GBK', 'GB2312', 'BIG5', 'ISO-8859-1'], true);
            if ($encoding && $encoding !== 'UTF-8') {
                $json = mb_convert_encoding($json, 'UTF-8', $encoding);
            }
        }

        return $json;
    }

    /**
     * 解析并保存美团数据到数据库
     * 支持竞对排名数据（roundrank节点）
     */
    private function parseAndSaveMeituanData($responseData, $startDate, $endDate, ?int $systemHotelId = null, array $context = []): int
    {
        return (new MeituanOnlineDataPersistenceService())->parseAndSaveMeituanData(
            $responseData,
            $startDate,
            $endDate,
            $systemHotelId,
            $context,
            $this->shouldLogOtaDebug()
        );
    }

    /**
     * 解析并保存数据到数据库
     * @param int|null $systemHotelId 系统酒店ID，用于门店隔离
     */
    private function parseAndSaveData(
        $responseData,
        $startDate,
        $endDate,
        ?int $systemHotelId = null,
        array $persistenceContext = []
    ): int
    {
        $dataList = $this->extractCtripBusinessDataList($responseData);

        if (empty($dataList)) {
            // 记录未能解析的数据结构
            \think\facade\Log::warning('parseAndSaveData: 未能解析到有效数据', [
                'response_keys' => array_keys($responseData),
                'response_sample' => json_encode(array_slice($responseData, 0, 3), JSON_UNESCAPED_UNICODE)
            ]);
            return 0;
        }

        $competitionRows = array_values(array_filter(
            $dataList,
            static fn($item): bool => is_array($item)
                && CtripCompetitionCirclePersistenceService::hasCompetitionCircleSignature($item)
        ));
        $savedCount = $this->persistCtripCompetitionCircleRowsFromLegacyParser(
            $competitionRows,
            (string)($startDate ?: ($endDate ?: date('Y-m-d'))),
            $systemHotelId,
            $persistenceContext
        );
        $dataDate = $startDate ?: ($endDate ?: date('Y-m-d'));
        $columns = $this->getOnlineDailyDataColumns();
        $now = date('Y-m-d H:i:s');

        foreach ($dataList as $item) {
            if (!is_array($item)) continue;
            if (CtripCompetitionCirclePersistenceService::hasCompetitionCircleSignature($item)) {
                continue;
            }
            if (!$this->canSaveCtripLegacyBusinessMetricItem($item)) {
                continue;
            }

            // 尝试多种字段名获取酒店ID，masterHotelId 优先作为携程酒店实体归属 ID。
            $hotelId = $this->resolveCtripPlatformHotelId($item);
            if (empty($hotelId)) continue;

            // 尝试多种字段名获取酒店名称
            $hotelName = $item['hotelName'] ?? $item['hotel_name'] ?? $item['HotelName'] ?? $item['name'] ?? '';

            // 尝试多种字段名获取其他数据
            $amount = $this->nullableNumberFromKeys($item, ['amount', 'Amount', 'totalAmount', 'total_amount', 'saleAmount', 'orderAmount', 'ordamount', 'gmv', 'turnover', 'bookingAmount', '成交收入', '成交金额', '销售额']);
            $quantity = $this->nullableNumberFromKeys($item, ['quantity', 'Quantity', 'roomNights', 'room_nights', 'checkOutQuantity', 'roomNightCount', 'nightNum', '成交间夜', '间夜', '房晚']);
            $quantity = $quantity === null ? null : (int)$quantity;
            $bookOrderNum = $this->nullableNumberFromKeys($item, ['bookOrderNum', 'book_order_num', 'orderCount', 'order_count', 'orderNum', 'ordquantity', 'orders', 'bookings', '成交订单数', '订单数']);
            $bookOrderNum = $bookOrderNum === null ? null : (int)$bookOrderNum;
            $commentScoreRaw = $this->firstMeituanValue($item, ['commentScore', 'comment_score', 'score', 'avgScore', 'ctripRatingall'], null);
            $commentScore = is_numeric($commentScoreRaw) && (float)$commentScoreRaw > 0
                ? (float)$commentScoreRaw
                : null;
            $qunarCommentScoreRaw = $this->firstMeituanValue($item, ['qunarCommentScore', 'qunar_comment_score', 'qunarScore'], null);
            $qunarCommentScore = is_numeric($qunarCommentScoreRaw) && (float)$qunarCommentScoreRaw > 0
                ? (float)$qunarCommentScoreRaw
                : null;
            $listExposure = $this->nullableNumberFromKeys($item, ['self_list_exposure', 'listExposure', 'list_exposure']);
            $listExposure = $listExposure === null ? null : (int)$listExposure;
            $detailExposure = $this->nullableNumberFromKeys($item, ['self_detail_exposure', 'detailExposure', 'detail_exposure']);
            $detailExposure = $detailExposure === null ? null : (int)$detailExposure;
            $orderFillingNum = $this->nullableNumberFromKeys($item, ['self_order_filling_num', 'orderFillingNum', 'order_filling_num']);
            $orderFillingNum = $orderFillingNum === null ? null : (int)$orderFillingNum;
            $orderSubmitNum = $this->nullableNumberFromKeys($item, ['self_order_submit_num', 'orderSubmitNum', 'order_submit_num']);
            $orderSubmitNum = $orderSubmitNum === null ? null : (int)$orderSubmitNum;
            $flowRate = $this->normalizeMeituanPercentValue($this->firstMeituanValue($item, ['self_flow_rate', 'flowRate', 'flow_rate'], null));

            // 如果有日期字段，优先使用接口返回日期；没有返回时使用请求日期
            $itemDate = $item['dataDate']
                ?? $item['date']
                ?? $item['data_date']
                ?? $item['statDate']
                ?? $item['stat_date']
                ?? $item['bizDate']
                ?? $item['businessDate']
                ?? $item['reportDate']
                ?? $dataDate;
            if (is_string($itemDate) && preg_match('/^\d{4}-\d{2}-\d{2}/', $itemDate, $matches)) {
                $itemDate = $matches[0];
            }
            $periodFilter = $this->applyOnlineDailyDataPeriodFields([
                'data_date' => $itemDate,
                'data_type' => 'business',
                'source' => 'ctrip',
                'dimension' => '',
            ], $columns, $item);

            // 检查是否已存在（按来源、系统酒店、平台酒店、日期去重）
            $query = Db::name('online_daily_data')
                ->where('source', 'ctrip')
                ->where('data_type', 'business')
                ->where('dimension', '')
                ->where('hotel_id', (string)$hotelId)
                ->where('data_date', $itemDate);
            $this->applyOnlineDailyDataPeriodQuery($query, $periodFilter, $columns);

            if ($systemHotelId !== null) {
                $query->where('system_hotel_id', $systemHotelId);
            } else {
                $query->whereNull('system_hotel_id');
            }

            $exists = $query->find();

            $data = [
                'hotel_id' => (string)$hotelId,
                'hotel_name' => $hotelName,
                'system_hotel_id' => $systemHotelId, // 系统酒店ID，用于门店隔离
                'data_date' => $itemDate,
                'amount' => $amount,
                'quantity' => $quantity,
                'book_order_num' => $bookOrderNum,
                'comment_score' => $commentScore,
                'qunar_comment_score' => $qunarCommentScore,
                'source' => 'ctrip',
                'data_type' => 'business',
                'dimension' => '',
                'compare_type' => trim((string)($item['compare_type'] ?? $item['compareType'] ?? '')) ?: null,
                'raw_data' => json_encode($item, JSON_UNESCAPED_UNICODE),
            ];

            if (isset($columns['update_time'])) {
                $data['update_time'] = $now;
            }
            if (isset($columns['list_exposure'])) {
                $data['list_exposure'] = $listExposure;
            }
            if (isset($columns['detail_exposure'])) {
                $data['detail_exposure'] = $detailExposure;
            }
            if (isset($columns['flow_rate'])) {
                $data['flow_rate'] = $flowRate;
            }
            if (isset($columns['order_filling_num'])) {
                $data['order_filling_num'] = $orderFillingNum;
            }
            if (isset($columns['order_submit_num'])) {
                $data['order_submit_num'] = $orderSubmitNum;
            }
            $data = $this->applyOnlineDailyDataValidationFields($data, $columns);

            if ($exists) {
                Db::name('online_daily_data')
                    ->where('id', $exists['id'])
                    ->update($data);
            } else {
                if (isset($columns['create_time'])) {
                    $data['create_time'] = $now;
                }
                Db::name('online_daily_data')->insert($data);
            }
            $savedCount++;
        }

        return $savedCount;
    }

    private function persistCtripCompetitionCircleRowsFromLegacyParser(
        array $rows,
        string $dataDate,
        ?int $systemHotelId,
        array $context = []
    ): int {
        if ($rows === []) {
            return 0;
        }
        if ($systemHotelId === null || $systemHotelId <= 0) {
            \think\facade\Log::warning('Ctrip competition-circle rows were not stored because the selected system hotel is missing', [
                'data_date' => $dataDate,
                'row_count' => count($rows),
            ]);
            return 0;
        }

        $selfHotelIds = [];
        foreach (is_array($context['self_hotel_ids'] ?? null) ? $context['self_hotel_ids'] : [] as $hotelId) {
            if (!is_array($hotelId) && !is_object($hotelId) && trim((string)$hotelId) !== '') {
                $selfHotelIds[trim((string)$hotelId)] = true;
            }
        }

        $fingerprint = hash('sha256', (string)json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $dataSourceId = (int)($context['data_source_id'] ?? 0);
        $syncTaskId = (int)($context['sync_task_id'] ?? 0);
        $persistence = new CtripCompetitionCirclePersistenceService();
        $ownsEvidenceTask = false;
        if ($dataSourceId <= 0 || $syncTaskId <= 0) {
            $dataSourceId = $persistence->resolveOrCreateDataSource(
                $systemHotelId,
                (int)($this->currentUser->id ?? 0),
                [
                    'platform_hotel_id' => (string)(array_key_first($selfHotelIds) ?? ''),
                    'config_id' => trim((string)($context['config_id'] ?? '')),
                ]
            );
            $syncTaskId = $persistence->startSyncTask(
                $dataSourceId,
                $systemHotelId,
                (int)($this->currentUser->id ?? 0),
                'legacy_parser'
            );
            $ownsEvidenceTask = true;
        }
        $sourceTraceId = trim((string)($context['source_trace_id'] ?? ''));
        if ($sourceTraceId === '') {
            $sourceTraceId = CtripCompetitionCirclePersistenceService::buildCaptureTraceId([
                'data_source_id' => $dataSourceId,
                'sync_task_id' => $syncTaskId,
                'system_hotel_id' => $systemHotelId,
                'data_date' => $dataDate,
                'fingerprint' => $fingerprint,
            ]);
        }

        try {
            $result = $persistence->persistRows(
                $rows,
                $dataDate,
                $systemHotelId,
                [
                    'self_hotel_ids' => array_keys($selfHotelIds),
                    'fetched_at' => (string)($context['fetched_at'] ?? date('Y-m-d H:i:s')),
                    'data_source_id' => $dataSourceId,
                    'sync_task_id' => $syncTaskId,
                    'source_trace_id' => $sourceTraceId,
                    'ingestion_method' => trim((string)($context['ingestion_method'] ?? '')) ?: 'legacy_parser',
                ]
            );
            if ($ownsEvidenceTask) {
                $persistence->finishSyncTask($syncTaskId, $dataSourceId, [
                    'saved_count' => (int)($result['saved_count'] ?? 0),
                    'inserted_count' => (int)($result['inserted_count'] ?? 0),
                    'updated_count' => (int)($result['updated_count'] ?? 0),
                    'row_count' => count($rows),
                    'self_hotel_ids' => array_keys($selfHotelIds),
                ]);
            }
            return (int)($result['saved_count'] ?? 0);
        } catch (\Throwable $e) {
            if ($ownsEvidenceTask) {
                $persistence->failSyncTask($syncTaskId, $dataSourceId, 'competition_circle_legacy_persistence_failed');
            }
            throw $e;
        }
    }

    private function canSaveCtripLegacyBusinessMetricItem(array $item): bool
    {
        if (CtripCompetitionCirclePersistenceService::hasCompetitionCircleSignature($item)) {
            return false;
        }
        $sourceUrl = strtolower((string)($item['_source_url'] ?? $item['source_url'] ?? $item['url'] ?? ''));
        $endpointId = strtolower((string)($item['_endpoint_id'] ?? $item['endpoint_id'] ?? ''));
        if ($sourceUrl === '' && $endpointId === '') {
            return true;
        }
        if ($this->isCtripRankOnlyBusinessItem($item)) {
            return false;
        }

        foreach ([
            'queryhomepagerealtimedata',
            'getdayreportrealtimedate',
            'fetchmarketoverviewv2',
            'fetchcapacityoverviewv4',
            'getdayreportflowcompete',
            'getdayreportserverquantity',
            'fetchvisitortitlev2',
        ] as $needle) {
            if (str_contains($sourceUrl, $needle) || str_contains($endpointId, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isCtripRankOnlyBusinessItem(array $item): bool
    {
        $sourceUrl = strtolower((string)($item['_source_url'] ?? $item['source_url'] ?? $item['url'] ?? ''));
        $endpointId = strtolower((string)($item['_endpoint_id'] ?? $item['endpoint_id'] ?? ''));
        if ($endpointId === 'weekly_compete_report' || $endpointId === 'competitor_rank') {
            return true;
        }
        if (str_contains($sourceUrl, 'getcompetehotelreportv1') || str_contains($sourceUrl, 'getcompetingrank')) {
            return true;
        }

        foreach (['bookingGMVrank', 'bookingOrdersrank', 'stayInRNrank', 'rentalRaterank'] as $key) {
            if (array_key_exists($key, $item)) {
                return true;
            }
        }

        return false;
    }

    private function extractCtripBusinessDataList($responseData): array
    {
        $dataList = [];

        if (isset($responseData['data']['hotelList']) && is_array($responseData['data']['hotelList'])) {
            return $responseData['data']['hotelList'];
        }
        if (isset($responseData['data']) && is_array($responseData['data']) && isset($responseData['data'][0])) {
            return $responseData['data'];
        }
        if (isset($responseData['data']) && is_array($responseData['data']) && !isset($responseData['data'][0])) {
            foreach ($responseData['data'] as $value) {
                if (is_array($value) && isset($value[0]) && is_array($value[0]) && (isset($value[0]['hotelId']) || isset($value[0]['hotel_id']) || isset($value[0]['HotelId']))) {
                    $dataList = array_merge($dataList, $value);
                }
            }
        }
        if (!empty($dataList)) {
            return $dataList;
        }
        if (isset($responseData['hotelList']) && is_array($responseData['hotelList'])) {
            return $responseData['hotelList'];
        }
        if (isset($responseData['Response']['hotelList']) && is_array($responseData['Response']['hotelList'])) {
            return $responseData['Response']['hotelList'];
        }
        if (is_array($responseData) && isset($responseData[0])) {
            return $responseData;
        }

        return $this->extractHotelData($responseData);
    }

    private function buildCtripBusinessDisplayHotels($responseData): array
    {
        if (is_array($responseData) && isset($responseData['date_results']) && is_array($responseData['date_results'])) {
            $hotelMap = [];
            foreach ($responseData['date_results'] as $dateResult) {
                if (!is_array($dateResult)) {
                    continue;
                }
                foreach ($this->buildCtripBusinessDisplayHotels($dateResult['data'] ?? []) as $hotel) {
                    $this->mergeCtripBusinessDisplayHotel($hotelMap, $hotel, true);
                }
            }
            return $this->sortBusinessDisplayHotels($hotelMap, 'quantity');
        }

        $hotelMap = [];
        foreach ($this->extractCtripBusinessDataList($responseData) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $hotel = $this->ctripBusinessDisplayHotelFromItem($item);
            if ($hotel !== null) {
                $this->mergeCtripBusinessDisplayHotel($hotelMap, $hotel, false);
            }
        }

        return $this->sortBusinessDisplayHotels($hotelMap, 'quantity');
    }

    private function ctripBusinessDisplayHotelFromItem(array $item): ?array
    {
        if (isset($item['raw_data']) && is_string($item['raw_data']) && $item['raw_data'] !== '') {
            $raw = json_decode($item['raw_data'], true);
            if (is_array($raw)) {
                $item = array_replace($raw, $item);
            }
        }

        $hotelId = (string)($item['hotelId'] ?? $item['hotel_id'] ?? $item['HotelId'] ?? $item['hotelID'] ?? $item['id'] ?? '');
        $hotelName = (string)($item['hotelName'] ?? $item['hotel_name'] ?? $item['HotelName'] ?? $item['name'] ?? '');
        if ($hotelId === '' && $hotelName === '') {
            return null;
        }

        $bookOrderNum = (int)$this->numberFromKeys($item, ['bookOrderNum', 'book_order_num', 'orderCount', 'order_count']);
        $hotelSeed = $hotelId !== '' ? $hotelId : $hotelName;
        $compareType = strtolower(trim((string)($item['compareType'] ?? $item['compare_type'] ?? '')));
        $normalizedHotelName = strtolower(preg_replace('/\s+/u', '', $hotelName) ?? '');
        $isSelf = !empty($item['isSelf'])
            || !empty($item['is_self'])
            || $compareType === 'self'
            || in_array($normalizedHotelName, ['我的酒店', '本店', 'myhotel', 'currenthotel'], true);
        $systemHotelId = (int)($item['systemHotelId'] ?? $item['system_hotel_id'] ?? 0);
        $systemHotelName = trim((string)($item['systemHotelName'] ?? $item['system_hotel_name'] ?? ''));
        if ($isSelf && $systemHotelName === '' && $systemHotelId > 0 && method_exists($this, 'getSystemHotelName')) {
            $systemHotelName = (string)$this->getSystemHotelName($systemHotelId);
        }

        return [
            'hotelId' => $hotelId,
            'hotelName' => $hotelName !== '' ? $hotelName : 'unknown',
            'systemHotelId' => $systemHotelId,
            'systemHotelName' => $systemHotelName,
            'compareType' => $isSelf ? 'self' : ($compareType !== '' ? $compareType : 'competitor'),
            'isSelf' => $isSelf,
            'amount' => $this->numberFromKeys($item, ['amount', 'Amount', 'totalAmount', 'total_amount', 'saleAmount']),
            'quantity' => (int)$this->numberFromKeys($item, ['quantity', 'Quantity', 'roomNights', 'room_nights', 'checkOutQuantity', 'checkInQuantity']),
            'bookOrderNum' => $bookOrderNum,
            'aiEstimatedTotalRoomNights' => $this->ctripAiEstimatedTotalRoomNights($bookOrderNum, $hotelSeed),
            'totalOrderNum' => (int)$this->numberFromKeys($item, ['totalOrderNum', 'total_order_num', 'bookOrderNum', 'book_order_num', 'orderCount', 'order_count']),
            'commentScore' => $this->numberFromKeys($item, ['commentScore', 'comment_score', 'score', 'avgScore']),
            'qunarCommentScore' => $this->numberFromKeys($item, ['qunarCommentScore', 'qunar_comment_score', 'qunarScore']),
            'totalDetailNum' => (int)$this->numberFromKeys($item, ['totalDetailNum', 'total_detail_num', 'detailVisitors', 'exposure', 'exposureCount', 'pv', 'pageView', 'viewCount']),
            'qunarDetailVisitors' => (int)$this->numberFromKeys($item, ['qunarDetailVisitors', 'qunar_detail_visitors', 'views', 'uv', 'visitorCount', 'detailUv']),
            'convertionRate' => $this->numberFromKeys($item, ['convertionRate', 'convertion_rate', 'conversionRate']),
            'qunarDetailCR' => $this->numberFromKeys($item, ['qunarDetailCR', 'qunar_detail_cr']),
            'amountRank' => (int)$this->numberFromKeys($item, ['amountRank', 'amount_rank'], 0),
            'quantityRank' => (int)$this->numberFromKeys($item, ['quantityRank', 'quantity_rank'], 0),
            'commentScoreRank' => (int)$this->numberFromKeys($item, ['commentScoreRank', 'comment_score_rank'], 0),
            'qunarDetailCRRank' => (int)$this->numberFromKeys($item, ['qunarDetailCRRank', 'qunar_detail_cr_rank'], 0),
            'sourceLabel' => '携程竞争圈返回',
            'sourceStatusText' => '携程竞争圈返回',
            'metricSourceStatus' => $this->buildCtripMetricSourceStatusFromItem($item),
        ];
    }

    private function mergeCtripBusinessDisplayHotel(array &$hotelMap, array $hotel, bool $sumValues): void
    {
        $key = (string)($hotel['hotelId'] ?? '') . '_' . (string)($hotel['hotelName'] ?? '');
        if (!isset($hotelMap[$key])) {
            $hotelMap[$key] = $hotel;
            return;
        }

        foreach (['amount', 'quantity', 'bookOrderNum', 'aiEstimatedTotalRoomNights', 'totalOrderNum', 'totalDetailNum', 'qunarDetailVisitors'] as $field) {
            $hotelMap[$key][$field] = $sumValues
                ? (float)($hotelMap[$key][$field] ?? 0) + (float)($hotel[$field] ?? 0)
                : max((float)($hotelMap[$key][$field] ?? 0), (float)($hotel[$field] ?? 0));
        }
        foreach (['quantity', 'bookOrderNum', 'aiEstimatedTotalRoomNights', 'totalOrderNum', 'totalDetailNum', 'qunarDetailVisitors'] as $field) {
            $hotelMap[$key][$field] = (int)($hotelMap[$key][$field] ?? 0);
        }
        foreach (['commentScore', 'qunarCommentScore', 'convertionRate', 'qunarDetailCR'] as $field) {
            $hotelMap[$key][$field] = max((float)($hotelMap[$key][$field] ?? 0), (float)($hotel[$field] ?? 0));
        }
        foreach (['amountRank', 'quantityRank', 'commentScoreRank', 'qunarDetailCRRank'] as $field) {
            $existing = (int)($hotelMap[$key][$field] ?? 0);
            $incoming = (int)($hotel[$field] ?? 0);
            if ($incoming > 0) {
                $hotelMap[$key][$field] = $existing === 0 ? $incoming : min($existing, $incoming);
            }
        }
        $hotelMap[$key]['sourceLabel'] = '携程竞争圈返回';
        $hotelMap[$key]['sourceStatusText'] = '携程竞争圈返回';
        $hotelMap[$key]['metricSourceStatus'] = $this->mergeCtripMetricSourceStatus(
            is_array($hotelMap[$key]['metricSourceStatus'] ?? null) ? $hotelMap[$key]['metricSourceStatus'] : [],
            is_array($hotel['metricSourceStatus'] ?? null) ? $hotel['metricSourceStatus'] : []
        );
    }

    private function ctripAiEstimatedTotalRoomNights(int $bookOrderNum, string $seed): int
    {
        if ($bookOrderNum <= 0) {
            return 0;
        }

        $hash = (int)(sprintf('%u', crc32($seed !== '' ? $seed : (string)$bookOrderNum)) % 100000);

        $ratio = 1.15 + (($hash % 21) / 100);
        return (int)round($bookOrderNum * $ratio);
    }

    private function buildCtripMetricSourceStatusFromItem(array $item): array
    {
        $status = [];
        foreach ($this->ctripBusinessMetricSourceKeyMap() as $field => $keys) {
            $status[$field] = $this->hasAnySourceKey($item, $keys) ? '携程竞争圈返回' : '系统未返回';
        }
        return $status;
    }

    private function ctripBusinessMetricSourceKeyMap(): array
    {
        return [
            'amount' => ['amount', 'Amount', 'totalAmount', 'total_amount', 'saleAmount'],
            'quantity' => ['quantity', 'Quantity', 'roomNights', 'room_nights', 'checkOutQuantity', 'checkInQuantity'],
            'bookOrderNum' => ['bookOrderNum', 'book_order_num', 'orderCount', 'order_count'],
            'totalOrderNum' => ['totalOrderNum', 'total_order_num', 'bookOrderNum', 'book_order_num', 'orderCount', 'order_count'],
            'commentScore' => ['commentScore', 'comment_score', 'score', 'avgScore'],
            'qunarCommentScore' => ['qunarCommentScore', 'qunar_comment_score', 'qunarScore'],
            'totalDetailNum' => ['totalDetailNum', 'total_detail_num', 'detailVisitors', 'exposure', 'exposureCount', 'pv', 'pageView', 'viewCount'],
            'qunarDetailVisitors' => ['qunarDetailVisitors', 'qunar_detail_visitors', 'views', 'uv', 'visitorCount', 'detailUv'],
            'convertionRate' => ['convertionRate', 'convertion_rate', 'conversionRate'],
            'qunarDetailCR' => ['qunarDetailCR', 'qunar_detail_cr'],
            'amountRank' => ['amountRank', 'amount_rank'],
            'quantityRank' => ['quantityRank', 'quantity_rank'],
            'commentScoreRank' => ['commentScoreRank', 'comment_score_rank'],
            'qunarDetailCRRank' => ['qunarDetailCRRank', 'qunar_detail_cr_rank'],
        ];
    }

    private function hasAnySourceKey(array $item, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $item)) {
                return true;
            }
        }
        return false;
    }

    private function mergeCtripMetricSourceStatus(array $base, array $incoming): array
    {
        foreach ($incoming as $field => $status) {
            if ($status === '携程竞争圈返回' || !isset($base[$field])) {
                $base[$field] = $status;
            }
        }
        return $base;
    }

    private function buildMeituanBusinessDisplayHotels($responseData, array $context = []): array
    {
        $hotelMap = [];
        foreach ($this->extractMeituanBusinessRankRows($responseData) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $hotelId = (string)($item['poiId'] ?? $item['poi_id'] ?? $item['shopId'] ?? $item['shop_id'] ?? $item['hotelId'] ?? '');
            $hotelName = (string)($item['poiName'] ?? $item['poi_name'] ?? $item['shopName'] ?? $item['shop_name'] ?? $item['hotelName'] ?? $item['name'] ?? '');
            if ($hotelId === '' && $hotelName === '') {
                continue;
            }

            $key = $this->meituanBusinessDisplayHotelKey($hotelId, $hotelName);
            if (!isset($hotelMap[$key])) {
                $hotelMap[$key] = $this->emptyMeituanBusinessDisplayHotelRow($hotelId, $hotelName, $context);
            }

            $metricType = $this->classifyMeituanBusinessDisplayMetric((string)($item['_dimName'] ?? $item['dimension'] ?? ''), (string)($item['_aiMetricName'] ?? $item['aiMetricName'] ?? ''), (string)($item['rankType'] ?? $item['rank_type'] ?? ''));
            $value = $this->nullableNumberFromKeys($item, ['dataValue', 'data_value', 'monthRoomNights', 'month_room_nights']);
            $rankPercent = $this->meituanRankPercentValue($item);
            if ($metricType !== '') {
                if ($value !== null) {
                    $hotelMap[$key][$metricType] = max((float)($hotelMap[$key][$metricType] ?? 0), $value);
                    $hotelMap[$key]['metricRankValue'][$metricType] = max((float)($hotelMap[$key]['metricRankValue'][$metricType] ?? 0), $value);
                    $hotelMap[$key]['metricSourceStatus'][$metricType] = '美团榜单返回';
                } elseif ($rankPercent !== null && empty($hotelMap[$key]['metricSourceStatus'][$metricType])) {
                    $hotelMap[$key]['metricSourceStatus'][$metricType] = '美团仅返回百分比';
                }
                if ($rankPercent !== null) {
                    $hotelMap[$key]['metricRankPercent'][$metricType] = $rankPercent;
                }
            }
            $platformTagInfo = $this->extractMeituanPlatformTagInfo($item);
            $hotelMap[$key]['platformTags'] = $this->mergeStringList($hotelMap[$key]['platformTags'] ?? [], $platformTagInfo['tags']);
            if (($platformTagInfo['status'] ?? '') !== 'not_returned') {
                $hotelMap[$key]['platformTagStatus'] = $platformTagInfo['status'];
            }
            if ($hotelName !== '') {
                $hotelMap[$key]['nameAliases'] = $this->mergeStringList($hotelMap[$key]['nameAliases'] ?? [], [$hotelName]);
            }
            $rank = (int)$this->numberFromKeys($item, ['rank', 'ranking'], 0);
            if ($rank > 0) {
                $existingRank = (int)($hotelMap[$key]['rank'] ?? 0);
                $hotelMap[$key]['rank'] = $existingRank === 0 ? $rank : min($existingRank, $rank);
                $sourceLabel = $value !== null ? '美团榜单返回' : ($rankPercent !== null ? '美团仅返回百分比' : '美团榜单未返回数值');
                $hotelMap[$key]['rankHistory'][] = $this->buildMeituanRankHistoryEntry($item, $context, $metricType, $rank, $value ?? 0.0, $rankPercent, $sourceLabel);
            }
        }

        $hotelMap = $this->applyMeituanPercentDerivedMetrics($hotelMap, $context);
        return $this->sortBusinessDisplayHotels($hotelMap, 'roomNights');
    }

    private function mergeMeituanBusinessDisplayHotels(array $rows, array $context = [], bool $applyPercentDerivation = true): array
    {
        $hotelMap = [];
        $useDateRangePriorityMerge = !$applyPercentDerivation && $this->hasMeituanDisplayGroupDateRangeRows($rows);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $poiId = (string)($row['poiId'] ?? $row['poi_id'] ?? $row['hotelId'] ?? '');
            $hotelName = (string)($row['hotelName'] ?? $row['hotel_name'] ?? $row['poiName'] ?? '');
            if ($poiId === '' && $hotelName === '') {
                continue;
            }
            $key = $this->meituanBusinessDisplayHotelKey($poiId, $hotelName);
            if (!isset($hotelMap[$key])) {
                $hotelMap[$key] = $this->emptyMeituanBusinessDisplayHotelRow($poiId, $hotelName, $context);
            }

            $incomingMetricRankPercent = is_array($row['metricRankPercent'] ?? null) ? $row['metricRankPercent'] : [];
            $incomingMetricRankValue = is_array($row['metricRankValue'] ?? null) ? $row['metricRankValue'] : [];
            $incomingMetricDerived = is_array($row['metricDerived'] ?? null) ? $row['metricDerived'] : [];
            foreach (['roomNights', 'roomRevenue', 'salesRoomNights', 'sales', 'orderCount', 'viewConversion', 'payConversion', 'exposure', 'views'] as $field) {
                $incomingValue = (float)($row[$field] ?? 0);
                $incomingStatus = (string)($row['metricSourceStatus'][$field] ?? '');
                if ($useDateRangePriorityMerge) {
                    $hasIncomingSignal = $incomingValue > 0
                        || $incomingStatus !== ''
                        || array_key_exists($field, $incomingMetricRankPercent)
                        || array_key_exists($field, $incomingMetricRankValue)
                        || array_key_exists($field, $incomingMetricDerived);
                    if (!$hasIncomingSignal) {
                        continue;
                    }
                    $incomingQuality = $this->meituanMetricMergeQuality($row, $field);
                    $existingQuality = (int)($hotelMap[$key]['_metricMergeQuality'][$field] ?? $this->meituanMetricMergeQuality($hotelMap[$key], $field));
                    $incomingPriority = $this->meituanDisplayGroupDateRangePriority((string)($row['displayGroupDateRange'] ?? $row['dateRange'] ?? ''));
                    $existingPriority = (int)($hotelMap[$key]['_metricDateRangePriority'][$field] ?? PHP_INT_MAX);
                    $existingValue = (float)($hotelMap[$key][$field] ?? 0);
                    if ($incomingQuality > $existingQuality
                        || ($incomingQuality === $existingQuality && ($incomingPriority < $existingPriority || ($incomingPriority === $existingPriority && $incomingValue > $existingValue)))
                    ) {
                        $hotelMap[$key][$field] = $incomingValue;
                        $selectedStatus = $incomingStatus !== ''
                            ? $incomingStatus
                            : ($incomingValue > 0 ? '美团榜单返回' : (array_key_exists($field, $incomingMetricRankPercent) ? '美团仅返回百分比' : ''));
                        if ($selectedStatus !== '') {
                            $hotelMap[$key]['metricSourceStatus'][$field] = $selectedStatus;
                        } else {
                            unset($hotelMap[$key]['metricSourceStatus'][$field]);
                        }
                        if (array_key_exists($field, $incomingMetricRankPercent) && is_numeric($incomingMetricRankPercent[$field])) {
                            $hotelMap[$key]['metricRankPercent'][$field] = (float)$incomingMetricRankPercent[$field];
                        } else {
                            unset($hotelMap[$key]['metricRankPercent'][$field]);
                        }
                        if (array_key_exists($field, $incomingMetricRankValue) && is_numeric($incomingMetricRankValue[$field])) {
                            $hotelMap[$key]['metricRankValue'][$field] = (float)$incomingMetricRankValue[$field];
                        } else {
                            unset($hotelMap[$key]['metricRankValue'][$field]);
                        }
                        if (array_key_exists($field, $incomingMetricDerived) && is_array($incomingMetricDerived[$field])) {
                            $hotelMap[$key]['metricDerived'][$field] = $incomingMetricDerived[$field];
                        } else {
                            unset($hotelMap[$key]['metricDerived'][$field]);
                        }
                        $hotelMap[$key]['_metricDateRangePriority'][$field] = $incomingPriority;
                        $hotelMap[$key]['_metricMergeQuality'][$field] = $incomingQuality;
                    }
                    continue;
                }
                $hotelMap[$key][$field] = max((float)($hotelMap[$key][$field] ?? 0), $incomingValue);
                if ((float)($row[$field] ?? 0) > 0 || $this->isMeituanMetricSourceUsable($incomingStatus)) {
                    $hotelMap[$key]['metricSourceStatus'][$field] = $incomingStatus !== '' ? $incomingStatus : '美团榜单返回';
                } elseif ($incomingStatus !== '' && empty($hotelMap[$key]['metricSourceStatus'][$field])) {
                    $hotelMap[$key]['metricSourceStatus'][$field] = $incomingStatus;
                }
            }
            if (!$useDateRangePriorityMerge) {
                foreach ($incomingMetricRankPercent as $field => $percent) {
                    if (is_numeric($percent)) {
                        $hotelMap[$key]['metricRankPercent'][$field] = (float)$percent;
                    }
                }
                foreach ($incomingMetricRankValue as $field => $rankValue) {
                    if (is_numeric($rankValue)) {
                        $hotelMap[$key]['metricRankValue'][$field] = max((float)($hotelMap[$key]['metricRankValue'][$field] ?? 0), (float)$rankValue);
                    }
                }
                foreach ($incomingMetricDerived as $field => $derived) {
                    if (is_array($derived)) {
                        $hotelMap[$key]['metricDerived'][$field] = $derived;
                    }
                }
            }
            $hotelMap[$key]['platformTags'] = $this->mergeStringList($hotelMap[$key]['platformTags'] ?? [], is_array($row['platformTags'] ?? null) ? $row['platformTags'] : []);
            if (($row['platformTagStatus'] ?? '') !== '' && ($row['platformTagStatus'] ?? '') !== 'not_returned') {
                $hotelMap[$key]['platformTagStatus'] = (string)$row['platformTagStatus'];
            }
            $hotelMap[$key]['nameAliases'] = $this->mergeStringList($hotelMap[$key]['nameAliases'] ?? [], is_array($row['nameAliases'] ?? null) ? $row['nameAliases'] : [$hotelName]);
            if (!empty($row['isSelf'])) {
                $hotelMap[$key]['isSelf'] = true;
            }
            if (is_array($row['rankHistory'] ?? null)) {
                $hotelMap[$key]['rankHistory'] = array_merge($hotelMap[$key]['rankHistory'] ?? [], $row['rankHistory']);
            }
            $rank = (int)($row['rank'] ?? 0);
            if ($rank > 0) {
                $existingRank = (int)($hotelMap[$key]['rank'] ?? 0);
                $hotelMap[$key]['rank'] = $existingRank === 0 ? $rank : min($existingRank, $rank);
                if (empty($row['rankHistory'])) {
                    $hotelMap[$key]['rankHistory'][] = [
                        'dateRange' => (string)($row['dateRange'] ?? $context['date_range'] ?? ''),
                        'dateRangeLabel' => $this->meituanDateRangeLabel((string)($row['dateRange'] ?? $context['date_range'] ?? '')),
                        'rankType' => (string)($row['rankType'] ?? $context['rank_type'] ?? ''),
                        'rankTypeLabel' => $this->meituanRankTypeLabel((string)($row['rankType'] ?? $context['rank_type'] ?? '')),
                        'metric' => '',
                        'metricLabel' => '平台排名',
                        'rank' => $rank,
                        'value' => 0.0,
                        'sourceLabel' => '美团榜单返回',
                    ];
                }
            }
        }

        if ($useDateRangePriorityMerge) {
            foreach ($hotelMap as &$row) {
                unset($row['_metricDateRangePriority']);
                unset($row['_metricMergeQuality']);
            }
            unset($row);
        }

        if ($applyPercentDerivation) {
            $hotelMap = $this->applyMeituanPercentDerivedMetrics($hotelMap, $context);
        }
        return $this->sortBusinessDisplayHotels($hotelMap, 'roomNights');
    }

    private function hasMeituanDisplayGroupDateRangeRows(array $rows): bool
    {
        foreach ($rows as $row) {
            if (is_array($row) && trim((string)($row['displayGroupDateRange'] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    private function meituanMetricMergeQuality(array $row, string $field): int
    {
        $value = (float)($row[$field] ?? 0);
        $status = (string)($row['metricSourceStatus'][$field] ?? '');
        $derived = is_array($row['metricDerived'][$field] ?? null) ? $row['metricDerived'][$field] : null;
        if (in_array($field, ['roomRevenue', 'sales'], true)) {
            if ($value > 0 && $this->isMeituanDisplayableMoneyMetricSource($row, $field, $status)) {
                return 90;
            }
            if ($value > 0 && $this->isMeituanPercentScaleDerivedMetric($row, $field)) {
                return 30;
            }
            if ($value > 0) {
                return 50;
            }
        } else {
            if ($value > 0 && $this->isMeituanMetricSourceUsable($status)) {
                return 80;
            }
            if ($value > 0) {
                return 60;
            }
        }

        if ($derived !== null) {
            return (string)($derived['method'] ?? '') === 'percent_min_integer_scale' ? 30 : 40;
        }
        $rankValue = $this->meituanMetricRankValue($row, $field);
        if ($rankValue !== null && $rankValue > 0) {
            return 35;
        }
        $rankPercent = $this->meituanMetricRankPercent($row, $field);
        if ($rankPercent !== null && $rankPercent >= 0) {
            return 25;
        }
        return $status !== '' ? 10 : 0;
    }

    private function meituanDisplayGroupDateRangePriority(string $dateRange): int
    {
        $dateRange = trim($dateRange);
        if ($dateRange === '') {
            $dateRange = 'unknown';
        }
        return [
            '0' => 0,
            '1' => 1,
            '7' => 2,
            '30' => 3,
            'custom' => 4,
            'unknown' => 5,
        ][$dateRange] ?? 6;
    }

    private function mergeMeituanBusinessDisplayGroups(array $groups, array $context = []): array
    {
        $resolvedRows = [];
        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }
            $rows = $group['display_hotels'] ?? $group['displayHotels'] ?? [];
            if (is_string($rows)) {
                $decodedRows = json_decode($rows, true);
                $rows = is_array($decodedRows) ? $decodedRows : [];
            }
            if (!is_array($rows) || empty($rows)) {
                continue;
            }

            $dateRange = trim((string)($group['date_range'] ?? $group['dateRange'] ?? ''));
            $groupContext = $context;
            if ($dateRange !== '') {
                $groupContext['date_range'] = $dateRange;
                $groupContext['date_ranges'] = [$dateRange];
            }
            $groupContext['self_metric_values'] = $this->normalizeMeituanSelfMetricValues($group['self_metric_values'] ?? $group['selfMetricValues'] ?? []);

            $groupRows = $this->mergeMeituanBusinessDisplayHotels($rows, $groupContext);
            foreach ($groupRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if ($dateRange !== '') {
                    $row['displayGroupDateRange'] = $dateRange;
                }
                $resolvedRows[] = $row;
            }
        }

        return $this->mergeMeituanBusinessDisplayHotels($resolvedRows, $context, false);
    }

    private function extractMeituanBusinessRankRows($responseData): array
    {
        return MeituanRankDataExtractionService::extractForDisplay($responseData);
    }

    private function classifyMeituanBusinessDisplayMetric(string $dimName, string $metricName, string $rankType): string
    {
        $rankType = strtoupper(trim($rankType));
        $upperMetric = strtoupper($metricName);
        $combined = $dimName . '|' . $upperMetric;

        $isNightMetric = str_contains($upperMetric, 'P_RZ_NIGHT_COUNT')
            || str_contains($upperMetric, 'NIGHT_COUNT')
            || str_contains($upperMetric, 'ROOM_NIGHT')
            || str_contains($combined, '间夜');
        $isMoneyMetric = str_contains($upperMetric, 'P_RZ_ROOM_PAY')
            || str_contains($upperMetric, 'AMT')
            || str_contains($upperMetric, 'AMOUNT')
            || str_contains($combined, '房费')
            || str_contains($combined, '销售额')
            || str_contains($combined, '交易额')
            || str_contains($combined, '收入')
            || str_contains($combined, '金额');

        if ($rankType === 'P_RZ') {
            if ($isNightMetric) {
                return 'roomNights';
            }
            if ($isMoneyMetric) {
                return 'roomRevenue';
            }
        }

        if ($rankType === 'P_XS') {
            if ($isNightMetric) {
                return 'salesRoomNights';
            }
            if ($isMoneyMetric) {
                return 'sales';
            }
        }

        if ($isNightMetric) {
            return str_contains($upperMetric, 'P_XS') ? 'salesRoomNights' : 'roomNights';
        }
        if (str_contains($upperMetric, 'P_RZ_ROOM_PAY') || str_contains($combined, '房费') || str_contains($combined, '收入')) {
            return 'roomRevenue';
        }
        if ((str_contains($upperMetric, 'P_XS') && str_contains($upperMetric, 'AMT')) || str_contains($combined, '销售额') || str_contains($combined, '交易额')) {
            return 'sales';
        }
        if (str_contains($upperMetric, 'VIEW_CONVERT') || str_contains($combined, '浏览转化')) {
            return 'viewConversion';
        }
        if (str_contains($upperMetric, 'PAY_CONVERT') || str_contains($combined, '支付转化')) {
            return 'payConversion';
        }
        if (str_contains($upperMetric, 'EXPOSURE') || str_contains($combined, '曝光')) {
            return 'exposure';
        }
        if (str_contains($upperMetric, 'VIEW') || str_contains($combined, '浏览')) {
            return 'views';
        }
        if ($rankType === 'P_LL') {
            return 'exposure';
        }
        return '';
    }

    private function emptyMeituanBusinessDisplayHotelRow(string $poiId, string $hotelName, array $context = []): array
    {
        $targetPoiId = (string)($context['target_poi_id'] ?? '');
        $displayName = $hotelName !== '' ? $hotelName : 'unknown';
        return [
            'poiId' => $poiId,
            'hotelName' => $displayName,
            'normalizedHotelName' => $this->normalizeMeituanHotelName($displayName),
            'nameAliases' => $hotelName !== '' ? [$hotelName] : [],
            'roomNights' => 0.0,
            'roomRevenue' => 0.0,
            'salesRoomNights' => 0.0,
            'sales' => 0.0,
            'orderCount' => 0,
            'viewConversion' => 0.0,
            'payConversion' => 0.0,
            'exposure' => 0.0,
            'views' => 0.0,
            'rank' => 0,
            'rankHistory' => [],
            'rankByRange' => [],
            'platformTags' => [],
            'platformTagText' => '未返回',
            'platformTagStatus' => 'not_returned',
            'platformTagSourceText' => '系统未返回',
            'hasVipTag' => false,
            'metricSourceStatus' => [],
            'metricRankPercent' => [],
            'metricRankValue' => [],
            'metricDerived' => [],
            'sourceLabel' => '美团榜单返回',
            'sourceStatusText' => '美团榜单返回',
            'isSelf' => $targetPoiId !== '' && $poiId !== '' && $targetPoiId === $poiId,
        ];
    }

    private function applyMeituanPercentDerivedMetrics(array $hotelMap, array $context = []): array
    {
        $context = $this->withMeituanStoredSelfTrafficMetricValues($context);

        $selfKey = null;
        foreach ($hotelMap as $key => $row) {
            if (is_array($row) && !empty($row['isSelf'])) {
                $selfKey = (string)$key;
                break;
            }
        }
        if ($selfKey === null || !isset($hotelMap[$selfKey]) || !is_array($hotelMap[$selfKey])) {
            return $hotelMap;
        }

        $hotelMap = $this->injectMeituanSelfActualMetrics($hotelMap, $selfKey, $context);

        $fields = ['roomNights', 'roomRevenue', 'salesRoomNights', 'sales', 'orderCount', 'viewConversion', 'payConversion', 'exposure', 'views'];
        foreach ($fields as $field) {
            $selfValue = $this->meituanSelfMetricValue($field, $hotelMap[$selfKey], $context);
            $selfPercent = $this->meituanMetricRankPercent($hotelMap[$selfKey], $field);
            if ($selfValue === null || $selfValue <= 0 || $selfPercent === null || $selfPercent <= 0) {
                continue;
            }

            foreach ($hotelMap as &$row) {
                if (!is_array($row)) {
                    continue;
                }
                $rowPercent = $this->meituanMetricRankPercent($row, $field);
                if ($rowPercent === null || $rowPercent < 0) {
                    continue;
                }

                if ($this->shouldPreserveMeituanMetricForSelfPercentDerivation($row, $field)) {
                    continue;
                }

                $derivedValue = $this->roundMeituanDerivedMetric($field, $selfValue * $rowPercent / $selfPercent);
                $row[$field] = $derivedValue;
                $row['metricSourceStatus'][$field] = '按本店值和美团百分比推导';
                $row['metricDerived'][$field] = [
                    'method' => 'self_value_times_row_percent_div_self_percent',
                    'self_value' => $selfValue,
                    'self_percent' => $selfPercent,
                    'row_percent' => $rowPercent,
                ];

                if (is_array($row['rankHistory'] ?? null)) {
                    foreach ($row['rankHistory'] as &$history) {
                        if (is_array($history) && ($history['metric'] ?? '') === $field) {
                            $history['value'] = $derivedValue;
                            $history['sourceLabel'] = '按本店值和美团百分比推导';
                            $history['derived'] = $row['metricDerived'][$field];
                        }
                    }
                    unset($history);
                }
            }
            unset($row);
        }

        $hotelMap = $this->applyMeituanRankValueDerivedMoneyMetrics($hotelMap, $selfKey, $context);

        return $hotelMap;
    }

    private function applyMeituanRankValueDerivedMoneyMetrics(array $hotelMap, string $selfKey, array $context = []): array
    {
        if (!isset($hotelMap[$selfKey]) || !is_array($hotelMap[$selfKey])) {
            return $hotelMap;
        }

        foreach (['roomRevenue', 'sales'] as $field) {
            $selfValue = $this->meituanSelfMetricValue($field, $hotelMap[$selfKey], $context);
            $selfRankValue = $this->meituanMetricRankValue($hotelMap[$selfKey], $field);
            if ($selfValue === null || $selfValue <= 0 || $selfRankValue === null || $selfRankValue <= 0) {
                continue;
            }

            foreach ($hotelMap as &$row) {
                if (!is_array($row)) {
                    continue;
                }
                if ($this->isMeituanSelfAnchoredDerivedMetric($row, $field)
                    || $this->shouldPreserveMeituanMetricForSelfPercentDerivation($row, $field)
                ) {
                    continue;
                }

                $rowRankValue = $this->meituanMetricRankValue($row, $field);
                if ($rowRankValue === null || $rowRankValue <= 0) {
                    continue;
                }

                $derivedValue = $this->roundMeituanDerivedMetric($field, $selfValue * $rowRankValue / $selfRankValue);
                $row[$field] = $derivedValue;
                $row['metricSourceStatus'][$field] = 'meituan_self_rank_value_derived';
                $row['metricDerived'][$field] = [
                    'method' => 'self_value_times_row_rank_value_div_self_rank_value',
                    'self_value' => $selfValue,
                    'self_rank_value' => $selfRankValue,
                    'row_rank_value' => $rowRankValue,
                ];

                if (is_array($row['rankHistory'] ?? null)) {
                    foreach ($row['rankHistory'] as &$history) {
                        if (is_array($history) && ($history['metric'] ?? '') === $field) {
                            $history['value'] = $derivedValue;
                            $history['sourceLabel'] = 'meituan_self_rank_value_derived';
                            $history['derived'] = $row['metricDerived'][$field];
                        }
                    }
                    unset($history);
                }
            }
            unset($row);
        }

        return $hotelMap;
    }

    private function injectMeituanSelfActualMetrics(array $hotelMap, string $selfKey, array $context): array
    {
        $values = $this->normalizeMeituanSelfMetricValues($context['self_metric_values'] ?? $context['selfMetricValues'] ?? []);
        $sourceStatuses = is_array($context['self_metric_source_status'] ?? null) ? $context['self_metric_source_status'] : [];
        if (empty($values) || !isset($hotelMap[$selfKey]) || !is_array($hotelMap[$selfKey])) {
            return $hotelMap;
        }

        foreach (['roomNights', 'roomRevenue', 'salesRoomNights', 'sales', 'orderCount', 'viewConversion', 'payConversion', 'exposure', 'views'] as $field) {
            $value = isset($values[$field]) ? (float)$values[$field] : 0.0;
            if ($value <= 0) {
                continue;
            }
            $sourceStatus = (string)($sourceStatuses[$field] ?? 'meituan_business_detail_returned');
            $hotelMap[$selfKey][$field] = $value;
            $hotelMap[$selfKey]['metricSourceStatus'][$field] = $sourceStatus;
            unset($hotelMap[$selfKey]['metricDerived'][$field]);

            if (is_array($hotelMap[$selfKey]['rankHistory'] ?? null)) {
                foreach ($hotelMap[$selfKey]['rankHistory'] as &$history) {
                    if (is_array($history) && ($history['metric'] ?? '') === $field) {
                        $history['value'] = $value;
                        $history['sourceLabel'] = '美团本店经营接口返回';
                        unset($history['derived']);
                    }
                }
                unset($history);
            }
        }

        return $hotelMap;
    }

    private function withMeituanStoredSelfTrafficMetricValues(array $context): array
    {
        $selfValues = $this->normalizeMeituanSelfMetricValues($context['self_metric_values'] ?? $context['selfMetricValues'] ?? []);
        $storedValues = $this->normalizeMeituanSelfMetricValues($context['stored_self_metric_values'] ?? $context['storedSelfMetricValues'] ?? []);
        if (empty($storedValues)) {
            $storedValues = $this->fetchMeituanStoredSelfTrafficMetricValues($context);
        }
        if (empty($storedValues)) {
            if (!empty($selfValues)) {
                $context['self_metric_values'] = $selfValues;
            }
            return $context;
        }

        $sourceStatuses = is_array($context['self_metric_source_status'] ?? null) ? $context['self_metric_source_status'] : [];
        foreach (['exposure', 'views', 'orderCount', 'payConversion'] as $field) {
            $value = isset($storedValues[$field]) ? (float)$storedValues[$field] : 0.0;
            if ($value <= 0 || isset($selfValues[$field])) {
                continue;
            }
            $selfValues[$field] = $value;
            $sourceStatuses[$field] = 'meituan_stored_self_traffic';
        }

        if (!empty($selfValues)) {
            $context['self_metric_values'] = $selfValues;
        }
        if (!empty($sourceStatuses)) {
            $context['self_metric_source_status'] = $sourceStatuses;
        }
        return $context;
    }

    private function fetchMeituanStoredSelfTrafficMetricValues(array $context): array
    {
        $systemHotelId = (int)($context['system_hotel_id'] ?? $context['systemHotelId'] ?? 0);
        if ($systemHotelId <= 0) {
            return [];
        }

        [$startDate, $endDate] = $this->resolveMeituanStoredSelfTrafficDateRange($context);
        if ($startDate === '' || $endDate === '') {
            return [];
        }

        try {
            $columns = $this->getOnlineDailyDataColumns();
            if (!isset($columns['system_hotel_id'], $columns['data_date'])
                || (!isset($columns['list_exposure']) && !isset($columns['detail_exposure']))
            ) {
                return [];
            }

            $fields = [
                isset($columns['list_exposure']) ? 'SUM(COALESCE(list_exposure, 0)) AS exposure' : '0 AS exposure',
                isset($columns['detail_exposure']) ? 'SUM(COALESCE(detail_exposure, 0)) AS views' : '0 AS views',
                isset($columns['order_submit_num']) ? 'SUM(COALESCE(order_submit_num, 0)) AS orderCount' : '0 AS orderCount',
            ];
            $query = Db::name('online_daily_data')
                ->where('system_hotel_id', $systemHotelId)
                ->where('data_date', '>=', $startDate)
                ->where('data_date', '<=', $endDate);

            if (isset($columns['source'], $columns['platform'])) {
                $query->where(function ($q) {
                    $q->where('source', 'meituan')->whereOr('platform', 'Meituan');
                });
            } elseif (isset($columns['source'])) {
                $query->where('source', 'meituan');
            } elseif (isset($columns['platform'])) {
                $query->where('platform', 'Meituan');
            }
            if (isset($columns['data_type'])) {
                $query->whereIn('data_type', ['traffic', 'traffic_analysis', 'flow', 'flow_analysis']);
            }
            if (isset($columns['compare_type'])) {
                $query->whereRaw("(`compare_type` = 'self' OR `compare_type` = '' OR `compare_type` IS NULL)");
            }

            $row = $query->field(implode(',', $fields))->find();
            if (!is_array($row)) {
                return [];
            }

            $values = [];
            foreach (['exposure', 'views', 'orderCount'] as $field) {
                $value = (float)($row[$field] ?? 0);
                if ($value > 0) {
                    $values[$field] = $value;
                }
            }
            if (($values['orderCount'] ?? 0) > 0 && ($values['views'] ?? 0) > 0) {
                $values['payConversion'] = round((float)$values['orderCount'] / (float)$values['views'], 4);
            }
            return $values;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function resolveMeituanStoredSelfTrafficDateRange(array $context): array
    {
        $startDate = $this->normalizeMeituanStoredTrafficDate($context['start_date'] ?? $context['startDate'] ?? '');
        $endDate = $this->normalizeMeituanStoredTrafficDate($context['end_date'] ?? $context['endDate'] ?? '');
        if ($startDate !== '' || $endDate !== '') {
            if ($startDate === '') {
                $startDate = $endDate;
            }
            if ($endDate === '') {
                $endDate = $startDate;
            }
            return strtotime($startDate) <= strtotime($endDate) ? [$startDate, $endDate] : [$endDate, $startDate];
        }

        $dataDate = $this->normalizeMeituanStoredTrafficDate($context['data_date'] ?? $context['dataDate'] ?? '');
        if ($dataDate !== '') {
            return [$dataDate, $dataDate];
        }

        $dateRange = trim((string)($context['date_range'] ?? $context['dateRange'] ?? ''));
        return match ($dateRange) {
            '0' => [date('Y-m-d'), date('Y-m-d')],
            '7' => [date('Y-m-d', strtotime('-7 days')), date('Y-m-d')],
            '30' => [date('Y-m-d', strtotime('-30 days')), date('Y-m-d')],
            '1' => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
            default => ['', ''],
        };
    }

    private function normalizeMeituanStoredTrafficDate($value): string
    {
        $text = trim((string)($value ?? ''));
        if ($text === '') {
            return '';
        }
        if (preg_match('/^\d{8}$/', $text)) {
            $text = substr($text, 0, 4) . '-' . substr($text, 4, 2) . '-' . substr($text, 6, 2);
        }
        $timestamp = strtotime($text);
        return $timestamp === false ? '' : date('Y-m-d', $timestamp);
    }

    private function shouldPreserveMeituanMetricForSelfPercentDerivation(array $row, string $field): bool
    {
        $value = (float)($row[$field] ?? 0);
        if ($value <= 0) {
            return false;
        }
        if ($this->isMeituanPercentScaleDerivedMetric($row, $field)) {
            return false;
        }
        $status = (string)($row['metricSourceStatus'][$field] ?? '');
        if (in_array($field, ['roomRevenue', 'sales'], true)) {
            return $this->isMeituanActualBusinessMetricSource($row, $field, $status);
        }
        return $this->isMeituanMetricSourceUsable($status);
    }

    private function meituanMetricRankValue(array $row, string $field): ?float
    {
        $values = is_array($row['metricRankValue'] ?? null) ? $row['metricRankValue'] : [];
        return isset($values[$field]) && is_numeric($values[$field]) ? (float)$values[$field] : null;
    }

    private function applyMeituanPercentScaleDerivedMetrics(array $hotelMap): array
    {
        foreach (['roomNights', 'roomRevenue', 'salesRoomNights', 'sales', 'exposure', 'views'] as $field) {
            $scale = $this->inferMeituanMinimalPercentScale($hotelMap, $field);
            if ($scale === null) {
                continue;
            }

            foreach ($hotelMap as &$row) {
                if (!is_array($row)) {
                    continue;
                }
                $rowPercent = $this->meituanMetricRankPercent($row, $field);
                if ($rowPercent === null || $rowPercent < 0) {
                    continue;
                }

                $status = (string)($row['metricSourceStatus'][$field] ?? '');
                if ((float)($row[$field] ?? 0) > 0 && $this->isMeituanMetricSourceUsable($status)) {
                    continue;
                }

                $derivedValue = $this->roundMeituanDerivedMetric($field, $scale * $rowPercent / 100);
                if ($this->isMeituanIntegerScaleMetric($field)) {
                    $derivedValue = (float)max(0, (int)round($derivedValue));
                }

                $row[$field] = $derivedValue;
                $row['metricSourceStatus'][$field] = '按美团百分比最小整数比例尺估算';
                $row['metricDerived'][$field] = [
                    'method' => 'percent_min_integer_scale',
                    'scale_value' => $scale,
                    'row_percent' => $rowPercent,
                    'percent_precision' => 2,
                    'source_scope' => 'meituan_rank_percent_only',
                ];

                if (is_array($row['rankHistory'] ?? null)) {
                    foreach ($row['rankHistory'] as &$history) {
                        if (is_array($history) && ($history['metric'] ?? '') === $field) {
                            $history['value'] = $derivedValue;
                            $history['sourceLabel'] = '按美团百分比最小整数比例尺估算';
                            $history['derived'] = $row['metricDerived'][$field];
                        }
                    }
                    unset($history);
                }
            }
            unset($row);
        }

        return $hotelMap;
    }

    private function isMeituanMetricSourceUsable(string $status): bool
    {
        return in_array($status, [
            'actual_business_value',
            'manual_actual_business_value',
            'meituan_business_detail_returned',
            'meituan_stored_self_traffic',
            'meituan_self_rank_value_derived',
            '美团榜单返回',
            '美团榜单入库',
            '按本店值和美团百分比推导',
            '按销售间夜代理估算',
            '按曝光和浏览估算',
            '按订单量和浏览估算',
            '按订单量和曝光估算',
            '按浏览转化和支付转化估算',
        ], true);
    }

    private function hasMeituanRankOnlyMetricSource(string $status): bool
    {
        return in_array($status, ['美团仅返回百分比', '美团榜单未返回数值'], true);
    }

    private function isMeituanPercentScaleDerivedMetric(array $row, string $field): bool
    {
        $derived = is_array($row['metricDerived'][$field] ?? null) ? $row['metricDerived'][$field] : [];
        if (($derived['method'] ?? '') === 'percent_min_integer_scale') {
            return true;
        }
        $status = (string)($row['metricSourceStatus'][$field] ?? '');
        return str_starts_with($status, '按美团百分比');
    }

    private function meituanDisplayMetricCurrencyPrefix(array $row, string $field): string
    {
        if ($this->isMeituanPercentScaleDerivedMetric($row, $field)) {
            return '';
        }
        $value = (float)($row[$field] ?? 0);
        $status = (string)($row['metricSourceStatus'][$field] ?? '');
        if (in_array($field, ['roomRevenue', 'sales'], true)) {
            if ($value <= 0) {
                return '';
            }
            if ($this->isMeituanActualBusinessMetricSource($row, $field, $status)) {
                return '¥';
            }
            return $this->isMeituanSelfAnchoredDerivedMetric($row, $field) ? '推算 ¥' : '';
        }
        return $value > 0 && $this->isMeituanMetricSourceUsable($status) ? '¥' : '';
    }

    private function isMeituanActualBusinessMetricSource(array $row, string $field, ?string $status = null): bool
    {
        $derived = is_array($row['metricDerived'][$field] ?? null) ? $row['metricDerived'][$field] : null;
        if ($derived !== null) {
            return false;
        }
        $status = $status ?? (string)($row['metricSourceStatus'][$field] ?? '');
        return in_array($status, [
            'actual_business_value',
            'manual_actual_business_value',
            'meituan_business_detail_returned',
        ], true);
    }

    private function isMeituanSelfAnchoredDerivedMetric(array $row, string $field): bool
    {
        $derived = is_array($row['metricDerived'][$field] ?? null) ? $row['metricDerived'][$field] : null;
        if ($derived === null) {
            return false;
        }
        $method = (string)($derived['method'] ?? '');
        if ($method === 'self_value_times_row_percent_div_self_percent') {
            return (float)($derived['self_value'] ?? 0) > 0
                && (float)($derived['self_percent'] ?? 0) > 0
                && (float)($derived['row_percent'] ?? 0) >= 0;
        }
        if ($method === 'self_value_times_row_rank_value_div_self_rank_value') {
            return (float)($derived['self_value'] ?? 0) > 0
                && (float)($derived['self_rank_value'] ?? 0) > 0
                && (float)($derived['row_rank_value'] ?? 0) > 0;
        }
        return false;
    }

    private function isMeituanDisplayableMoneyMetricSource(array $row, string $field, ?string $status = null): bool
    {
        if ($this->isMeituanSelfAnchoredDerivedMetric($row, $field)) {
            return (float)($row[$field] ?? 0) > 0;
        }
        if ($this->isMeituanActualBusinessMetricSource($row, $field, $status)) {
            return true;
        }
        if ($this->isMeituanPercentScaleDerivedMetric($row, $field)) {
            return false;
        }
        $status = $status ?? (string)($row['metricSourceStatus'][$field] ?? '');
        if ($status === 'meituan_self_rank_value_derived') {
            return (float)($row[$field] ?? 0) > 0;
        }
        return (float)($row[$field] ?? 0) > 0 && $this->isMeituanMetricSourceUsable($status);
    }

    private function meituanDisplayMetricIndexPrefix(array $row, string $field): string
    {
        if ($this->isMeituanPercentScaleDerivedMetric($row, $field)) {
            return '指数 ';
        }
        return $this->isMeituanSelfAnchoredDerivedMetric($row, $field) ? '推算 ' : '';
    }

    private function meituanAveragePriceCurrencyPrefix(array $row, string $amountField): string
    {
        $value = (float)($row[$amountField] ?? 0);
        if ($value <= 0) {
            return '';
        }
        return $this->meituanDisplayMetricCurrencyPrefix($row, $amountField);
    }

    private function meituanDisplayMetricText(array $row, string $field, string $format = 'number'): string
    {
        $value = (float)($row[$field] ?? 0);
        $status = (string)($row['metricSourceStatus'][$field] ?? '');
        if ($format === 'money'
            && in_array($field, ['roomRevenue', 'sales'], true)
            && $this->isMeituanPercentScaleDerivedMetric($row, $field)
        ) {
            return '-';
        }
        if ($value <= 0 && !$this->isMeituanMetricSourceUsable($status)) {
            return '-';
        }

        $text = $this->formatMeituanDisplayNumber($value, 0);
        if ($format !== 'money'
            && in_array($field, ['roomNights', 'salesRoomNights'], true)
            && $this->isMeituanSelfAnchoredDerivedMetric($row, $field)
        ) {
            return '推算 ' . $text;
        }
        return $text;
    }

    private function meituanBusinessDisplayHotelKey(string $poiId, string $hotelName): string
    {
        if ($poiId !== '') {
            return 'poi:' . $poiId;
        }
        return 'name:' . $this->normalizeMeituanHotelName($hotelName);
    }

    private function normalizeMeituanHotelName(string $name): string
    {
        $value = str_replace(["\r", "\n", "\t", '（', '）'], [' ', ' ', ' ', '(', ')'], trim($name));
        $value = preg_replace('/\s+/u', ' ', $value) ?: $value;
        return trim($value);
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

    private function buildMeituanRankHistoryEntry(array $item, array $context, string $metricType, int $rank, float $value, ?float $rankPercent = null, string $sourceLabel = '美团榜单返回'): array
    {
        $dateRange = (string)($context['date_range'] ?? '');
        if ($dateRange === '') {
            $dateRange = (string)($item['dateRange'] ?? $item['date_range'] ?? '');
        }
        $rankType = (string)($context['rank_type'] ?? '');
        if ($rankType === '') {
            $rankType = (string)($item['rankType'] ?? $item['rank_type'] ?? '');
        }
        $metricLabel = (string)($item['_dimName'] ?? $item['dimension'] ?? $item['_aiMetricName'] ?? $item['aiMetricName'] ?? '平台排名');
        return [
            'dateRange' => $dateRange,
            'dateRangeLabel' => $this->meituanDateRangeLabel($dateRange),
            'rankType' => $rankType,
            'rankTypeLabel' => $this->meituanRankTypeLabel($rankType),
            'metric' => $metricType,
            'metricLabel' => $metricLabel,
            'rank' => $rank,
            'value' => $value,
            'percent' => $rankPercent,
            'sourceLabel' => $sourceLabel,
        ];
    }

    private function meituanRankTypeLabel(string $rankType): string
    {
        return [
            'P_RZ' => '入住榜',
            'P_XS' => '销售榜',
            'P_LL' => '流量榜',
            'P_ZH' => '转化榜',
        ][$rankType] ?? ($rankType !== '' ? $rankType : '未标明榜单');
    }

    private function meituanDateRangeLabel(string $dateRange): string
    {
        return [
            '0' => '实时',
            '1' => '昨日',
            '7' => '近7天',
            '30' => '近30天',
            'custom' => '自定义',
        ][$dateRange] ?? ($dateRange !== '' ? $dateRange : '未标明时间');
    }

    private function sortBusinessDisplayHotels(array $hotelMap, string $sortField): array
    {
        $rows = $this->enrichMeituanBusinessDisplayMetrics($this->enrichCtripBusinessDisplayMetrics(array_values($hotelMap)));
        $hasSortMetric = count(array_filter($rows, static fn(array $row): bool => (float)($row[$sortField] ?? 0) > 0)) > 0;
        usort($rows, static function (array $a, array $b) use ($sortField, $hasSortMetric): int {
            if ($hasSortMetric) {
                $metricCompare = ((float)($b[$sortField] ?? 0)) <=> ((float)($a[$sortField] ?? 0));
                if ($metricCompare !== 0) {
                    return $metricCompare;
                }
            }

            $aRank = (int)($a['currentPlatformRank'] ?? $a['rank'] ?? 0);
            $bRank = (int)($b['currentPlatformRank'] ?? $b['rank'] ?? 0);
            if ($aRank > 0 && $bRank > 0 && $aRank !== $bRank) {
                return $aRank <=> $bRank;
            }
            if ($aRank > 0 && $bRank <= 0) {
                return -1;
            }
            if ($bRank > 0 && $aRank <= 0) {
                return 1;
            }

            return strcmp((string)($a['hotelName'] ?? ''), (string)($b['hotelName'] ?? ''));
        });
        return $this->finalizeMeituanBusinessDisplayRows($rows, $sortField);
    }

    private function enrichCtripBusinessDisplayMetrics(array $rows): array
    {
        $adrValues = [];
        foreach ($rows as $row) {
            if (!$this->isCtripBusinessDisplayRow($row)) {
                continue;
            }
            $amount = (float)($row['amount'] ?? 0);
            $quantity = (int)($row['quantity'] ?? 0);
            if ($amount > 0 && $quantity > 0) {
                $adrValues[] = $amount / $quantity;
            }
        }
        $circleAdr = count($adrValues) > 0 ? array_sum($adrValues) / count($adrValues) : 0.0;

        foreach ($rows as &$row) {
            if (!$this->isCtripBusinessDisplayRow($row)) {
                continue;
            }

            $amount = (float)($row['amount'] ?? 0);
            $quantity = (int)($row['quantity'] ?? 0);
            $bookOrderNum = (int)($row['bookOrderNum'] ?? 0);
            $totalDetailNum = (int)($row['totalDetailNum'] ?? 0);

            $adr = $quantity > 0 ? round($amount / $quantity, 2) : 0.0;
            $ari = $circleAdr > 0 && $adr > 0 ? round($adr / $circleAdr * 100, 2) : 0.0;
            $sci = $ari > 0 ? round($ari * log(max(1, $quantity)), 2) : 0.0;
            $bookingRate = $totalDetailNum > 0 ? round($bookOrderNum / $totalDetailNum * 100, 2) : 0.0;

            $row['adr'] = $adr;
            $row['adrText'] = $quantity > 0 ? number_format($adr, 2, '.', '') : '-';
            $row['ari'] = $ari;
            $row['ariText'] = $ari > 0 ? number_format($ari, 1, '.', '') : '-';
            $row['sci'] = $sci;
            $row['sciText'] = $sci > 0 ? number_format($sci, 0, '.', '') : '-';
            $row['bookingRate'] = $bookingRate;
            $row['bookingRateText'] = $totalDetailNum > 0 ? number_format($bookingRate, 1, '.', '') . '%' : '-';
            $row['displayMetricStatus'] = [
                'adr' => $quantity > 0 ? 'ok' : 'missing_quantity',
                'ari' => $circleAdr > 0 && $adr > 0 ? 'ok' : ($circleAdr > 0 ? 'missing_adr' : 'missing_circle_adr'),
                'sci' => $sci > 0 ? 'ok' : 'missing_ari',
                'bookingRate' => $totalDetailNum > 0 ? 'ok' : 'missing_total_detail_num',
            ];
            $row['sourceLabel'] = '携程竞争圈返回';
            $row['sourceStatusText'] = '携程竞争圈返回';
            $row['metricSourceStatus'] = $this->buildCtripMetricSourceStatus($row);
        }
        unset($row);

        return $rows;
    }

    private function buildCtripMetricSourceStatus(array $row): array
    {
        $status = is_array($row['metricSourceStatus'] ?? null) ? $row['metricSourceStatus'] : [];
        foreach (array_keys($this->ctripBusinessMetricSourceKeyMap()) as $field) {
            if (!isset($status[$field])) {
                $status[$field] = (float)($row[$field] ?? 0) > 0 ? '携程竞争圈返回' : '系统未返回';
            }
        }
        $amountReturned = ($status['amount'] ?? '') === '携程竞争圈返回';
        $quantityReturned = ($status['quantity'] ?? '') === '携程竞争圈返回';
        $bookOrderReturned = ($status['bookOrderNum'] ?? '') === '携程竞争圈返回';
        $totalDetailReturned = ($status['totalDetailNum'] ?? '') === '携程竞争圈返回';
        $status['adr'] = $amountReturned && $quantityReturned ? '携程竞争圈返回' : '系统未返回';
        $status['ari'] = $status['adr'] === '携程竞争圈返回' ? '携程竞争圈返回' : '系统未返回';
        $status['sci'] = $status['ari'] === '携程竞争圈返回' ? '携程竞争圈返回' : '系统未返回';
        $status['bookingRate'] = $bookOrderReturned && $totalDetailReturned ? '携程竞争圈返回' : '系统未返回';
        return $status;
    }

    private function enrichMeituanBusinessDisplayMetrics(array $rows): array
    {
        foreach ($rows as &$row) {
            if (!$this->isMeituanBusinessDisplayRow($row)) {
                continue;
            }

            $roomNights = (float)($row['roomNights'] ?? 0);
            $roomRevenue = (float)($row['roomRevenue'] ?? 0);
            $salesRoomNights = (float)($row['salesRoomNights'] ?? 0);
            $sales = (float)($row['sales'] ?? 0);
            $exposure = (float)($row['exposure'] ?? 0);
            $views = (float)($row['views'] ?? 0);
            $viewConversion = (float)($row['viewConversion'] ?? 0);
            $payConversion = (float)($row['payConversion'] ?? 0);
            $metricSourceStatus = is_array($row['metricSourceStatus'] ?? null) ? $row['metricSourceStatus'] : [];
            $metricDerived = is_array($row['metricDerived'] ?? null) ? $row['metricDerived'] : [];

            $displayableRoomRevenue = $this->isMeituanDisplayableMoneyMetricSource($row, 'roomRevenue', (string)($metricSourceStatus['roomRevenue'] ?? ''))
                ? $roomRevenue
                : 0.0;
            $displayableSales = $this->isMeituanDisplayableMoneyMetricSource($row, 'sales', (string)($metricSourceStatus['sales'] ?? ''))
                ? $sales
                : 0.0;
            $hasRoomPriceInputs = $roomNights > 0 && $displayableRoomRevenue > 0;
            $hasSalesPriceInputs = $salesRoomNights > 0 && $displayableSales > 0;
            $canUseRoomPriceBasis = $hasRoomPriceInputs
                && $this->canUseMeituanMetricPairForAveragePrice($metricSourceStatus, $metricDerived, 'roomRevenue', 'roomNights');
            $canUseSalesPriceBasis = $hasSalesPriceInputs
                && $this->canUseMeituanMetricPairForAveragePrice($metricSourceStatus, $metricDerived, 'sales', 'salesRoomNights');
            $avgRoomPrice = $hasRoomPriceInputs ? (float)(int)round($displayableRoomRevenue / $roomNights) : 0.0;
            $avgSalesPrice = $hasSalesPriceInputs ? (float)(int)round($displayableSales / $salesRoomNights) : 0.0;
            if ($hasRoomPriceInputs && !$canUseRoomPriceBasis) {
                $metricSourceStatus['avgRoomPrice'] = '按美团榜单指标相除';
                $metricDerived['avgRoomPrice'] = [
                    'method' => 'room_revenue_div_room_nights_display_metric',
                    'room_revenue' => $roomRevenue,
                    'room_nights' => $roomNights,
                    'source_scope' => 'meituan_rank_display_metric',
                ];
            }
            if ($hasSalesPriceInputs && !$canUseSalesPriceBasis) {
                $metricSourceStatus['avgSalesPrice'] = '按美团榜单指标相除';
                $metricDerived['avgSalesPrice'] = [
                    'method' => 'sales_div_sales_room_nights_display_metric',
                    'sales' => $sales,
                    'sales_room_nights' => $salesRoomNights,
                    'source_scope' => 'meituan_rank_display_metric',
                ];
            }
            $orderCount = (int)round((float)($row['orderCount'] ?? 0));
            if ($orderCount <= 0 && $views > 0 && $payConversion > 0) {
                $orderCount = (int)round($views * $payConversion);
                $metricSourceStatus['orderCount'] = '按浏览和支付转化率估算';
                $metricDerived['orderCount'] = [
                    'method' => 'views_times_pay_conversion',
                    'views' => $views,
                    'pay_conversion' => $payConversion,
                    'source_scope' => 'meituan_rank_formula',
                ];
            }
            if ($viewConversion <= 0 && $exposure > 0 && $views > 0) {
                $viewConversion = round($views / $exposure, 4);
                $metricSourceStatus['viewConversion'] = '按曝光和浏览估算';
                $metricDerived['viewConversion'] = [
                    'method' => 'views_div_exposure',
                    'views' => $views,
                    'exposure' => $exposure,
                    'source_scope' => 'meituan_rank_percent_only',
                ];
            }
            if ($payConversion <= 0 && $views > 0 && $orderCount > 0) {
                $payConversion = round($orderCount / $views, 4);
                $metricSourceStatus['payConversion'] = '按订单量和浏览估算';
                $metricDerived['payConversion'] = [
                    'method' => 'order_count_div_views',
                    'order_count' => $orderCount,
                    'views' => $views,
                    'order_count_source' => (string)($metricDerived['orderCount']['method'] ?? 'display_order_count'),
                    'source_scope' => 'meituan_rank_percent_only',
                ];
            }
            $absoluteConversion = (float)($row['absoluteConversion'] ?? 0);
            if ($absoluteConversion <= 0 && $viewConversion > 0 && $payConversion > 0) {
                $absoluteConversion = round($viewConversion * $payConversion, 4);
                if (isset($metricDerived['viewConversion']) || isset($metricDerived['payConversion'])) {
                    $metricSourceStatus['absoluteConversion'] = '按浏览转化和支付转化估算';
                    $metricDerived['absoluteConversion'] = [
                        'method' => 'view_conversion_times_pay_conversion',
                        'view_conversion' => $viewConversion,
                        'pay_conversion' => $payConversion,
                        'source_scope' => 'meituan_rank_percent_only',
                    ];
                }
            } elseif ($absoluteConversion <= 0 && $exposure > 0 && $orderCount > 0) {
                $absoluteConversion = round($orderCount / $exposure, 4);
                $metricSourceStatus['absoluteConversion'] = '按订单量和曝光估算';
                $metricDerived['absoluteConversion'] = [
                    'method' => 'order_count_div_exposure',
                    'order_count' => $orderCount,
                    'exposure' => $exposure,
                    'order_count_source' => (string)($metricDerived['orderCount']['method'] ?? 'display_order_count'),
                    'source_scope' => 'meituan_rank_percent_only',
                ];
            }
            $rankSummary = $this->summarizeMeituanRankHistory(is_array($row['rankHistory'] ?? null) ? $row['rankHistory'] : [], (int)($row['rank'] ?? 0));
            $platformTags = is_array($row['platformTags'] ?? null) ? $this->mergeStringList([], $row['platformTags']) : [];
            $hasVipTag = $this->hasMeituanVipPlatformTag($platformTags);

            $row['avgRoomPrice'] = $avgRoomPrice;
            $row['avgRoomPriceText'] = $avgRoomPrice > 0 ? number_format($avgRoomPrice, 0, '.', ',') : '-';
            $row['avgSalesPrice'] = $avgSalesPrice;
            $row['avgSalesPriceText'] = $avgSalesPrice > 0 ? number_format($avgSalesPrice, 0, '.', ',') : '-';
            $row['roomNightsText'] = $this->meituanDisplayMetricText($row, 'roomNights');
            $row['roomRevenueText'] = $this->meituanDisplayMetricText($row, 'roomRevenue', 'money');
            $row['roomRevenuePrefix'] = $this->meituanDisplayMetricCurrencyPrefix($row, 'roomRevenue');
            $row['salesRoomNightsText'] = $this->meituanDisplayMetricText($row, 'salesRoomNights');
            $row['salesText'] = $this->meituanDisplayMetricText($row, 'sales', 'money');
            $row['salesPrefix'] = $this->meituanDisplayMetricCurrencyPrefix($row, 'sales');
            $row['avgRoomPricePrefix'] = $avgRoomPrice > 0 ? $this->meituanAveragePriceCurrencyPrefix($row, 'roomRevenue') : '';
            $row['avgSalesPricePrefix'] = $avgSalesPrice > 0 ? $this->meituanAveragePriceCurrencyPrefix($row, 'sales') : '';
            $row['exposureText'] = $this->meituanDisplayMetricText($row, 'exposure');
            $row['exposurePrefix'] = $this->meituanDisplayMetricIndexPrefix($row, 'exposure');
            $row['viewsText'] = $this->meituanDisplayMetricText($row, 'views');
            $row['viewsPrefix'] = $this->meituanDisplayMetricIndexPrefix($row, 'views');
            $row['orderCount'] = $orderCount;
            $row['orderCountText'] = $orderCount > 0 ? number_format($orderCount) : '-';
            $row['viewConversion'] = $viewConversion;
            $row['payConversion'] = $payConversion;
            $row['absoluteConversion'] = $absoluteConversion;
            $row['absoluteConversionText'] = $absoluteConversion > 0 ? number_format($absoluteConversion * 100, 2, '.', '') . '%' : '-';
            $row['viewConversionText'] = $viewConversion > 0 ? number_format($viewConversion * 100, 2, '.', '') . '%' : '-';
            $row['payConversionText'] = $payConversion > 0 ? number_format($payConversion * 100, 2, '.', '') . '%' : '-';
            $row['metricSourceStatus'] = $metricSourceStatus;
            $row['metricDerived'] = $metricDerived;
            $row['platformTags'] = $platformTags;
            $row['platformTagText'] = !empty($platformTags) ? implode(' / ', $platformTags) : '未返回';
            $row['platformTagSourceText'] = !empty($platformTags)
                ? '美团榜单返回'
                : (($row['platformTagStatus'] ?? '') === 'returned_empty' ? '平台返回空标签' : '系统未返回');
            $row['hasVipTag'] = $hasVipTag;
            $row['rankByRange'] = $rankSummary['rankByRange'];
            $row['rankSummaryText'] = $rankSummary['rankSummaryText'];
            $row['rankTrendText'] = $rankSummary['rankTrendText'];
            $row['rankTrendClass'] = $rankSummary['rankTrendClass'];
            $row['currentPlatformRank'] = $rankSummary['currentRank'];
            $row['previousPlatformRank'] = $rankSummary['previousRank'];
            $row['rank30Best'] = $rankSummary['rank30Best'];
            $row['rank30Worst'] = $rankSummary['rank30Worst'];
            $row['rank30RangeText'] = $rankSummary['rank30RangeText'];
            $row['sourceStatusText'] = '美团榜单返回';
            $row['metricHealth'] = $this->buildMeituanMetricHealthRows($row, $metricSourceStatus);
            $row['displayMetricStatus'] = [
                'avgRoomPrice' => $this->meituanAveragePriceDisplayStatus($roomNights, $displayableRoomRevenue, $canUseRoomPriceBasis, 'missing_room_nights', 'missing_room_revenue'),
                'avgSalesPrice' => $this->meituanAveragePriceDisplayStatus($salesRoomNights, $displayableSales, $canUseSalesPriceBasis, 'missing_sales_room_nights', 'missing_sales'),
                'orderCount' => $orderCount > 0 ? 'ok' : 'missing_order_count',
                'absoluteConversion' => $absoluteConversion > 0 ? 'ok' : 'missing_conversion',
            ];
        }
        unset($row);

        return $rows;
    }

    private function canUseMeituanMetricPairForAveragePrice(array $metricSourceStatus, array $metricDerived, string $amountField, string $quantityField): bool
    {
        return $this->canUseMeituanMetricForAveragePrice($metricSourceStatus, $metricDerived, $amountField)
            && $this->canUseMeituanMetricForAveragePrice($metricSourceStatus, $metricDerived, $quantityField);
    }

    private function canUseMeituanMetricForAveragePrice(array $metricSourceStatus, array $metricDerived, string $field): bool
    {
        if (isset($metricDerived[$field])) {
            return false;
        }

        // Rank values are not a price basis; only explicit operating-value sources may drive ADR.
        $status = (string)($metricSourceStatus[$field] ?? '');
        return in_array($status, [
            'actual_business_value',
            'manual_actual_business_value',
            'meituan_business_detail_returned',
        ], true);
    }

    private function meituanAveragePriceDisplayStatus(float $quantity, float $amount, bool $canUsePriceBasis, string $missingQuantityStatus, string $missingAmountStatus): string
    {
        if ($canUsePriceBasis) {
            return 'ok';
        }
        if ($quantity <= 0) {
            return $missingQuantityStatus;
        }
        if ($amount <= 0) {
            return $missingAmountStatus;
        }
        return 'derived_from_display_metrics';
    }

    private function summarizeMeituanRankHistory(array $history, int $fallbackRank = 0): array
    {
        $rankByRange = [];
        $thirtyDayRanks = [];
        foreach ($history as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $rank = (int)($entry['rank'] ?? 0);
            if ($rank <= 0) {
                continue;
            }
            $range = (string)($entry['dateRange'] ?? '');
            if ($range === '') {
                $range = 'unknown';
            }
            $rangeLabel = (string)($entry['dateRangeLabel'] ?? '');
            if ($range === '30' || str_contains($range, '30') || str_contains($rangeLabel, '30')) {
                $thirtyDayRanks[] = $rank;
            }
            if (!isset($rankByRange[$range]) || $rank < (int)$rankByRange[$range]['rank']) {
                $rankByRange[$range] = [
                    'rank' => $rank,
                    'label' => $entry['dateRangeLabel'] ?? $this->meituanDateRangeLabel($range),
                    'rankType' => $entry['rankType'] ?? '',
                    'rankTypeLabel' => $entry['rankTypeLabel'] ?? '',
                    'metricLabel' => $entry['metricLabel'] ?? '',
                    'sourceLabel' => $entry['sourceLabel'] ?? '美团榜单返回',
                ];
            }
        }
        if (empty($rankByRange) && $fallbackRank > 0) {
            $rankByRange['unknown'] = [
                'rank' => $fallbackRank,
                'label' => '未标明时间',
                'rankType' => '',
                'rankTypeLabel' => '',
                'metricLabel' => '平台排名',
                'sourceLabel' => '美团榜单返回',
            ];
        }

        $currentRange = null;
        foreach (['0', '1', '7', '30', 'custom', 'unknown'] as $range) {
            if (isset($rankByRange[$range])) {
                $currentRange = $range;
                break;
            }
        }
        $previousRange = null;
        foreach ($currentRange === '0' ? ['1', '7', '30'] : ($currentRange === '1' ? ['7', '30'] : ['30', 'unknown']) as $range) {
            if (isset($rankByRange[$range])) {
                $previousRange = $range;
                break;
            }
        }

        $currentRank = $currentRange !== null ? (int)$rankByRange[$currentRange]['rank'] : 0;
        $previousRank = $previousRange !== null ? (int)$rankByRange[$previousRange]['rank'] : 0;
        $rankParts = [];
        foreach (['0', '1', '7', '30', 'custom', 'unknown'] as $range) {
            if (isset($rankByRange[$range])) {
                $rankParts[] = $rankByRange[$range]['label'] . '第' . $rankByRange[$range]['rank'];
            }
        }

        $trendText = '暂无变化';
        $trendClass = 'text-gray-500';
        if ($currentRank > 0 && $previousRank > 0) {
            if ($previousRank <= 10 && $currentRank > 10) {
                $trendText = '掉出前10';
                $trendClass = 'text-red-600';
            } elseif ($previousRank > 10 && $currentRank <= 10) {
                $trendText = '进入前10';
                $trendClass = 'text-emerald-600';
            } elseif ($currentRank < $previousRank) {
                $trendText = '上升' . ($previousRank - $currentRank) . '名';
                $trendClass = 'text-emerald-600';
            } elseif ($currentRank > $previousRank) {
                $trendText = '下降' . ($currentRank - $previousRank) . '名';
                $trendClass = 'text-red-600';
            } else {
                $trendText = '排名持平';
                $trendClass = 'text-gray-600';
            }
        }

        $rank30Best = count($thirtyDayRanks) > 0 ? min($thirtyDayRanks) : 0;
        $rank30Worst = count($thirtyDayRanks) > 0 ? max($thirtyDayRanks) : 0;
        $rank30RangeText = '近30天未返回';
        if ($rank30Best > 0 && $rank30Worst > 0) {
            $rank30RangeText = $rank30Best === $rank30Worst
                ? '近30天第 ' . $rank30Best
                : '近30天最好第 ' . $rank30Best . ' / 最差第 ' . $rank30Worst;
        }

        return [
            'rankByRange' => $rankByRange,
            'rankSummaryText' => !empty($rankParts) ? implode(' / ', $rankParts) : '平台未返回排名',
            'rankTrendText' => $trendText,
            'rankTrendClass' => $trendClass,
            'currentRank' => $currentRank,
            'previousRank' => $previousRank,
            'rank30Best' => $rank30Best,
            'rank30Worst' => $rank30Worst,
            'rank30RangeText' => $rank30RangeText,
        ];
    }

    private function buildMeituanMetricHealthRows(array $row, array $metricSourceStatus): array
    {
        $groups = [
            ['key' => 'stay', 'label' => '入住榜', 'fields' => ['roomNights', 'roomRevenue']],
            ['key' => 'sales', 'label' => '销售榜', 'fields' => ['salesRoomNights', 'sales']],
            ['key' => 'traffic', 'label' => '流量榜', 'fields' => ['exposure', 'views']],
            ['key' => 'conversion', 'label' => '转化榜', 'fields' => ['viewConversion', 'payConversion']],
        ];
        $rows = [];
        foreach ($groups as $group) {
            $returned = 0;
            $rankOnly = 0;
            foreach ($group['fields'] as $field) {
                $status = (string)($metricSourceStatus[$field] ?? '');
                if ((float)($row[$field] ?? 0) > 0 || $this->isMeituanMetricSourceUsable($status)) {
                    $returned++;
                } elseif ($this->hasMeituanRankOnlyMetricSource($status) || isset($row['metricRankPercent'][$field])) {
                    $rankOnly++;
                }
            }
            if ($returned === count($group['fields'])) {
                $status = 'ok';
                $statusText = '已返回';
                $sourceLabel = '美团榜单返回';
            } elseif ($returned > 0) {
                $status = 'partial';
                $statusText = '部分返回';
                $sourceLabel = '美团榜单返回';
            } elseif ($rankOnly > 0) {
                $status = 'rank_only';
                $statusText = '仅排名';
                $sourceLabel = '美团仅返回百分比';
            } else {
                $status = 'missing';
                $statusText = '未返回';
                $sourceLabel = '系统未返回';
            }
            $rows[] = [
                'key' => $group['key'],
                'label' => $group['label'],
                'status' => $status,
                'statusText' => $statusText,
                'sourceLabel' => $sourceLabel,
            ];
        }
        return $rows;
    }

    private function finalizeMeituanBusinessDisplayRows(array $rows, string $sortField): array
    {
        $total = count($rows);
        foreach ($rows as $index => &$row) {
            if (!$this->isMeituanBusinessDisplayRow($row)) {
                continue;
            }
            $rank = (int)($row['currentPlatformRank'] ?? $row['rank'] ?? 0);
            $row['circlePositionText'] = $rank > 0
                ? ($rank <= $total ? '第 ' . $rank . ' / ' . $total . ' 名' : '第 ' . $rank . ' 名（本次返回' . $total . '家）')
                : '平台未返回';
            $prev = $rows[$index - 1] ?? null;
            $next = $rows[$index + 1] ?? null;
            $value = (float)($row[$sortField] ?? 0);
            $prevValue = is_array($prev) ? (float)($prev[$sortField] ?? 0) : 0.0;
            $nextValue = is_array($next) ? (float)($next[$sortField] ?? 0) : 0.0;
            $row['gapMetric'] = $sortField;
            $row['gapMetricLabel'] = $this->meituanDisplayMetricLabel($sortField);
            $row['gapToPrev'] = is_array($prev) ? round(max(0, $prevValue - $value), 2) : 0.0;
            $row['gapToNext'] = is_array($next) ? round(max(0, $value - $nextValue), 2) : 0.0;
            $row['gapToPrevText'] = is_array($prev)
                ? '距前一名 ' . $this->formatMeituanGapValue($row['gapToPrev'], $sortField)
                : '当前表内领先';
            $row['gapToNextText'] = is_array($next)
                ? '领先后一名 ' . $this->formatMeituanGapValue($row['gapToNext'], $sortField)
                : '尾部酒店';
            $leaderValue = isset($rows[0]) && is_array($rows[0]) ? (float)($rows[0][$sortField] ?? 0) : 0.0;
            $row['gapToLeader'] = round(max(0, $leaderValue - $value), 2);
            $row['gapToLeaderText'] = $index === 0 ? '当前TOP1' : '距TOP1 ' . $this->formatMeituanGapValue($row['gapToLeader'], $sortField);
            $row['rankGapSummaryText'] = implode(' / ', array_values(array_filter([
                (string)($row['gapToPrevText'] ?? ''),
                (string)($row['gapToLeaderText'] ?? ''),
                (string)($row['rank30RangeText'] ?? ''),
            ])));
        }
        unset($row);

        return $rows;
    }

    private function meituanDisplayMetricLabel(string $field): string
    {
        return [
            'roomNights' => '入住间夜',
            'roomRevenue' => '房费收入',
            'salesRoomNights' => '销售间夜',
            'sales' => '销售额',
            'exposure' => '曝光',
            'views' => '浏览',
        ][$field] ?? $field;
    }

    private function formatMeituanGapValue(float $value, string $field): string
    {
        if (in_array($field, ['roomRevenue', 'sales'], true)) {
            return '¥' . number_format((float)round($value));
        }
        return number_format((float)round($value));
    }

    private function findMeituanSelfDisplayRow(array $rows): ?array
    {
        foreach ($rows as $row) {
            if (is_array($row) && !empty($row['isSelf'])) {
                return $row;
            }
        }
        return null;
    }

    private function buildMeituanFunnelDiagnosisCard(array $rows, ?array $selfRow): array
    {
        if ($selfRow === null) {
            return [
                'key' => 'funnel-diagnosis',
                'label' => '四榜卡点',
                'value' => '本店未返回',
                'note' => '目标 POI 未出现在本次榜单，暂不生成卡点判断',
                'className' => 'bg-gray-50 text-gray-500 border-gray-200',
                'details' => [],
                'missing' => ['本店榜单行未返回'],
            ];
        }

        $checks = [
            ['key' => 'exposure', 'label' => '曝光', 'field' => 'exposure', 'threshold' => 0.85, 'action' => '补曝光'],
            ['key' => 'views', 'label' => '浏览', 'field' => 'views', 'threshold' => 0.85, 'action' => '提点击'],
            ['key' => 'conversion', 'label' => '转化', 'field' => 'payConversion', 'threshold' => 0.90, 'action' => '查转化'],
            ['key' => 'avgSalesPrice', 'label' => '客单', 'field' => 'avgSalesPrice', 'threshold' => 0.90, 'action' => '提客单'],
            ['key' => 'sales', 'label' => '收入', 'field' => 'sales', 'threshold' => 0.85, 'action' => '补收入'],
        ];

        $issues = [];
        $missing = [];
        foreach ($checks as $check) {
            $field = (string)$check['field'];
            $selfValue = (float)($selfRow[$field] ?? 0);
            $benchmark = $this->avgBusinessRows($rows, $field);
            if ($selfValue <= 0 || $benchmark <= 0) {
                $missing[] = $check['label'] . '未返回';
                continue;
            }
            if ($selfValue < $benchmark * (float)$check['threshold']) {
                $issues[] = [
                    'key' => (string)$check['key'],
                    'label' => $check['label'] . '低',
                    'action' => (string)$check['action'],
                    'value' => $this->formatMeituanDiagnosticValue($selfValue, $field),
                    'benchmark' => '圈内均值 ' . $this->formatMeituanDiagnosticValue($benchmark, $field),
                ];
            }
        }

        if (!empty($issues)) {
            $primary = $issues[0];
            $noteParts = array_map(
                static fn(array $issue): string => $issue['label'] . '：' . $issue['value'] . '，' . $issue['benchmark'],
                array_slice($issues, 0, 3)
            );
            return [
                'key' => 'funnel-diagnosis',
                'label' => '四榜卡点',
                'value' => (string)$primary['action'] . (count($issues) > 1 ? ' +' . (count($issues) - 1) : ''),
                'note' => implode('；', $noteParts),
                'className' => 'bg-rose-50 text-rose-700 border-rose-100',
                'details' => $issues,
                'missing' => $missing,
            ];
        }

        return [
            'key' => 'funnel-diagnosis',
            'label' => '四榜卡点',
            'value' => '暂无明显卡点',
            'note' => !empty($missing) ? ('部分字段未返回：' . implode('、', array_slice($missing, 0, 3))) : '曝光、浏览、转化、客单、收入未明显低于圈内均值',
            'className' => !empty($missing) ? 'bg-amber-50 text-amber-700 border-amber-100' : 'bg-emerald-50 text-emerald-700 border-emerald-100',
            'details' => [],
            'missing' => $missing,
        ];
    }

    private function formatMeituanDiagnosticValue(float $value, string $field): string
    {
        if (in_array($field, ['payConversion', 'viewConversion', 'absoluteConversion'], true)) {
            return number_format($value * 100, 2, '.', '') . '%';
        }
        if (in_array($field, ['roomRevenue', 'sales', 'avgRoomPrice', 'avgSalesPrice'], true)) {
            return '¥' . number_format((float)round($value));
        }
        return number_format((float)round($value));
    }

    private function buildMeituanBusinessDisplaySummary(array $rows, array $context = []): array
    {
        $rows = array_values(array_filter($this->enrichMeituanBusinessDisplayMetrics($rows), fn($row): bool => is_array($row) && $this->isMeituanBusinessDisplayRow($row)));
        if (empty($rows)) {
            return $this->emptyMeituanBusinessDisplaySummary();
        }

        $hotelCount = count($rows);
        $totalRoomNights = $this->sumBusinessRows($rows, 'roomNights');
        $hasDisplayableRoomNights = $this->hasMeituanDisplayableMetricRows($rows, 'roomNights');
        $totalRoomRevenue = $this->sumMeituanDisplayableMoneyRows($rows, 'roomRevenue');
        $hasDisplayableRoomRevenue = count(array_filter($rows, fn($row): bool =>
            $this->isMeituanDisplayableMoneyMetricSource(
                is_array($row) ? $row : [],
                'roomRevenue',
                (string)($row['metricSourceStatus']['roomRevenue'] ?? '')
            )
        )) > 0;
        $totalSalesRoomNights = $this->sumBusinessRows($rows, 'salesRoomNights');
        $hasDisplayableSalesRoomNights = $this->hasMeituanDisplayableMetricRows($rows, 'salesRoomNights');
        $totalSales = $this->sumMeituanDisplayableMoneyRows($rows, 'sales');
        $hasDisplayableSales = count(array_filter($rows, fn($row): bool =>
            $this->isMeituanDisplayableMoneyMetricSource(
                is_array($row) ? $row : [],
                'sales',
                (string)($row['metricSourceStatus']['sales'] ?? '')
            )
        )) > 0;
        $totalExposure = $this->sumBusinessRows($rows, 'exposure');
        $hasDisplayableExposure = $this->hasMeituanDisplayableMetricRows($rows, 'exposure');
        $totalViews = $this->sumBusinessRows($rows, 'views');
        $hasDisplayableViews = $this->hasMeituanDisplayableMetricRows($rows, 'views');
        $totalOrderCount = (int)$this->sumBusinessRows($rows, 'orderCount');
        $hasDisplayableOrderCount = $this->hasMeituanDisplayableMetricRows($rows, 'orderCount');
        $avgRoomPrice = $this->weightedMeituanAveragePrice($rows, 'roomRevenue', 'roomNights', 'avgRoomPrice');
        $avgSalesPrice = $this->weightedMeituanAveragePrice($rows, 'sales', 'salesRoomNights', 'avgSalesPrice');
        $avgViewConversionRate = $this->avgBusinessRows($rows, 'viewConversion') * 100;
        $avgPayConversionRate = $this->avgBusinessRows($rows, 'payConversion') * 100;
        $avgAbsoluteConversionRate = $this->avgBusinessRows($rows, 'absoluteConversion') * 100;
        $hasViewConversionValue = $this->hasPositiveBusinessRows($rows, 'viewConversion');
        $hasPayConversionValue = $this->hasPositiveBusinessRows($rows, 'payConversion');
        $hasAbsoluteConversionValue = $this->hasPositiveBusinessRows($rows, 'absoluteConversion');
        $revenueHhi = $this->hhiBusinessRows($rows, 'sales');
        $visitHhi = $this->hhiBusinessRows($rows, 'views');
        $operationFocus = $revenueHhi > 0 && $visitHhi > 0 && $revenueHhi - $visitHhi > 0 ? '提高转化率' : ($revenueHhi > 0 && $visitHhi > 0 ? '抢夺流量' : '-');
        $marketInventory = $this->meituanBusinessMarketInventory($context);
        $marketVitalityRate = $marketInventory > 0 ? round($totalRoomNights / $marketInventory * 100, 2) : 0.0;
        $priceSigma = $this->meituanBusinessPriceSigma($rows);
        $marketPriceSignal = $this->meituanBusinessMarketPriceSignal($avgRoomPrice, $avgSalesPrice);
        $inventoryTurnoverRate = $totalRoomNights > 0 ? round($totalSalesRoomNights / $totalRoomNights * 100, 2) : 0.0;
        $rankHealthRows = $this->buildMeituanRankHealthRows($rows);
        $selfRow = $this->findMeituanSelfDisplayRow($rows);
        $funnelDiagnosis = $this->buildMeituanFunnelDiagnosisCard($rows, $selfRow);
        $rankInsights = $this->buildMeituanRankInsightCards($rows, $rankHealthRows, $funnelDiagnosis);
        $topSummaryRows = $this->buildMeituanTopSummaryRows($rows);
        $vipTaggedCount = count(array_filter($rows, static fn($row): bool => !empty($row['hasVipTag'])));
        $platformTagReturnedCount = count(array_filter($rows, static fn($row): bool => !empty($row['platformTags'])));
        $platformTagSummary = $this->buildMeituanPlatformTagSummary($rows);
        $derivedMetricCount = $this->countMeituanDerivedMetrics($rows);
        $rankOnlyCount = count(array_filter($rankHealthRows, static fn($row): bool => ($row['status'] ?? '') === 'rank_only'));
        $sourceNotice = $derivedMetricCount > 0
            ? '美团榜单部分指标仅返回百分比；只有存在本店真实值锚点时才按本店值 × 对方 percent ÷ 本店 percent 推导，并在 metricDerived 中保留依据。缺少真实值锚点时保持缺失，不再按比例尺估算。'
            : ($rankOnlyCount > 0
                ? '美团榜单返回了排名/percent，但未返回可展示数值；数值列保留缺失状态，不用 0 代替。'
                : '仅展示美团榜单已返回字段；不通过订单、客人、房态或房源映射推断。');
        $selfPositionText = $selfRow ? (string)($selfRow['circlePositionText'] ?? '已返回') : '本店未返回';

        return [
            'status' => 'success',
            'metrics' => [
                'hotelCount' => $hotelCount,
                'rankHealthReadyCount' => count(array_filter($rankHealthRows, static fn($row): bool => ($row['status'] ?? '') === 'ok')),
                'rankHealthTotalCount' => count($rankHealthRows),
                'vipTaggedCount' => $vipTaggedCount,
                'platformTagReturnedCount' => $platformTagReturnedCount,
                'selfPositionText' => $selfPositionText,
                'marketInventory' => $marketInventory,
                'marketVitalityRate' => $marketVitalityRate,
                'priceSigma' => $priceSigma,
                'marketPriceSignal' => $marketPriceSignal,
                'inventoryTurnoverRate' => $inventoryTurnoverRate,
                'revenueConcentration' => $revenueHhi,
                'visitConcentration' => $visitHhi,
                'operationFocus' => $operationFocus,
                'totalRoomNights' => round($totalRoomNights, 2),
                'totalRoomRevenue' => round($totalRoomRevenue, 2),
                'totalSalesRoomNights' => round($totalSalesRoomNights, 2),
                'totalSales' => round($totalSales, 2),
                'totalExposure' => round($totalExposure, 2),
                'totalViews' => round($totalViews, 2),
                'totalOrderCount' => $totalOrderCount,
                'avgRoomPrice' => $avgRoomPrice,
                'avgSalesPrice' => $avgSalesPrice,
                'avgViewConversionRate' => round($avgViewConversionRate, 2),
                'avgPayConversionRate' => round($avgPayConversionRate, 2),
                'avgAbsoluteConversionRate' => round($avgAbsoluteConversionRate, 2),
                'funnelDiagnosisValue' => (string)($funnelDiagnosis['value'] ?? '未生成'),
                'funnelDiagnosisIssueCount' => count($funnelDiagnosis['details'] ?? []),
                'derivedMetricCount' => $derivedMetricCount,
            ],
            'cards' => [
                $this->businessSummaryCard('hotelCount', '酒店总数', number_format($hotelCount), 'text-gray-700', 'bg-blue-50 border border-blue-200'),
                $this->businessSummaryCard('rankHealth', '榜单健康度', count(array_filter($rankHealthRows, static fn($row): bool => ($row['status'] ?? '') === 'ok')) . '/' . count($rankHealthRows), 'text-blue-600', 'bg-blue-50 border border-blue-200'),
                $this->businessSummaryCard('selfPosition', '本店圈内位置', $selfPositionText, $selfRow ? 'text-emerald-600' : 'text-gray-500', 'bg-emerald-50 border border-emerald-200'),
                $this->businessSummaryCard('platformVipTags', 'VIP竞对标签', $platformTagReturnedCount > 0 ? ('VIP ' . number_format($vipTaggedCount) . '家') : '未返回', $vipTaggedCount > 0 ? 'text-orange-600' : 'text-gray-500', 'bg-orange-50 border border-orange-200'),
                $this->businessSummaryCard('marketInventory', '市场总库存', $marketInventory > 0 ? number_format($marketInventory) : '-', 'text-indigo-600', 'bg-indigo-50 border border-indigo-200'),
                $this->businessSummaryCard('marketVitalityRate', '市场活力', $marketVitalityRate > 0 ? number_format($marketVitalityRate, 2, '.', '') . '%' : '-', 'text-blue-600', 'bg-blue-50 border border-blue-200', $this->meituanBusinessVitalityLevel($marketVitalityRate)),
                $this->businessSummaryCard('priceSigma', '竞争健康度', $priceSigma > 0 ? number_format($priceSigma, 2, '.', '') . '%' : '-', 'text-orange-600', 'bg-orange-50 border border-orange-200', $this->meituanBusinessPriceSigmaLevel($priceSigma)),
                $this->businessSummaryCard('marketPriceSignal', '市场价格预估', $marketPriceSignal, 'text-blue-600', 'bg-blue-50 border border-blue-200'),
                $this->businessSummaryCard('inventoryTurnoverRate', '库存周转率', $inventoryTurnoverRate > 0 ? number_format($inventoryTurnoverRate, 2, '.', '') . '%' : '-', 'text-cyan-600', 'bg-cyan-50 border border-cyan-200'),
                $this->businessSummaryCard('revenueConcentration', '收益集中度', $revenueHhi > 0 ? number_format($revenueHhi, 2, '.', '') : '-', 'text-orange-600', 'bg-orange-50 border border-orange-200'),
                $this->businessSummaryCard('visitConcentration', '浏览/访客集中度', $visitHhi > 0 ? number_format($visitHhi, 2, '.', '') : '-', 'text-orange-600', 'bg-orange-50 border border-orange-200'),
                $this->businessSummaryCard('operationFocus', '运营重心', $operationFocus, 'text-indigo-600', 'bg-indigo-50 border border-indigo-200'),
                $this->businessSummaryCard('totalRoomNights', '总入住间夜', $hasDisplayableRoomNights ? number_format($totalRoomNights) : '-', 'text-red-600', 'bg-red-50 border border-red-200'),
                $this->businessSummaryCard('totalRoomRevenue', '总房费收入', $hasDisplayableRoomRevenue ? ('¥' . number_format((float)floor($totalRoomRevenue))) : '-', 'text-red-600', 'bg-red-50 border border-red-200'),
                $this->businessSummaryCard('avgRoomPrice', '商圈平均房价', $avgRoomPrice > 0 ? '¥' . number_format($avgRoomPrice, 0, '.', ',') : '-', 'text-red-600', 'bg-red-50 border border-red-200'),
                $this->businessSummaryCard('totalSalesRoomNights', '总销售间夜', $hasDisplayableSalesRoomNights ? number_format($totalSalesRoomNights) : '-', 'text-green-600', 'bg-green-50 border border-green-200'),
                $this->businessSummaryCard('totalSales', '总销售额', $hasDisplayableSales ? ('¥' . number_format((float)floor($totalSales))) : '-', 'text-green-600', 'bg-green-50 border border-green-200'),
                $this->businessSummaryCard('avgSalesPrice', '商圈平均销售房价', $avgSalesPrice > 0 ? '¥' . number_format($avgSalesPrice, 0, '.', ',') : '-', 'text-green-600', 'bg-green-50 border border-green-200'),
                $this->businessSummaryCard('totalExposure', '总曝光量', $hasDisplayableExposure ? number_format($totalExposure) : '-', 'text-blue-600', 'bg-blue-50 border border-blue-200'),
                $this->businessSummaryCard('totalViews', '总浏览量', $hasDisplayableViews ? number_format($totalViews) : '-', 'text-blue-600', 'bg-blue-50 border border-blue-200'),
                $this->businessSummaryCard('totalOrderCount', '总订单量', $hasDisplayableOrderCount ? number_format($totalOrderCount) : '-', 'text-blue-600', 'bg-blue-50 border border-blue-200'),
                $this->businessSummaryCard('avgViewConversionRate', '平均浏览转化率', $hasViewConversionValue ? number_format($avgViewConversionRate, 2, '.', '') . '%' : '-', 'text-purple-600', 'bg-purple-50 border border-purple-200'),
                $this->businessSummaryCard('avgPayConversionRate', '平均支付转化率', $hasPayConversionValue ? number_format($avgPayConversionRate, 2, '.', '') . '%' : '-', 'text-purple-600', 'bg-purple-50 border border-purple-200'),
                $this->businessSummaryCard('avgAbsoluteConversionRate', '绝对转化率', $hasAbsoluteConversionValue ? number_format($avgAbsoluteConversionRate, 2, '.', '') . '%' : '-', 'text-purple-600', 'bg-purple-50 border border-purple-200'),
            ],
            'rank_insights' => $rankInsights,
            'rank_health_rows' => $rankHealthRows,
            'top_summary_rows' => $topSummaryRows,
            'funnel_diagnosis' => $funnelDiagnosis,
            'platform_tag_summary' => $platformTagSummary,
            'source_notice' => $sourceNotice,
        ];
    }

    private function countMeituanDerivedMetrics(array $rows): int
    {
        $count = 0;
        foreach ($rows as $row) {
            if (!is_array($row) || !is_array($row['metricDerived'] ?? null)) {
                continue;
            }
            $count += count($row['metricDerived']);
        }
        return $count;
    }

    private function weightedMeituanAveragePrice(array $rows, string $amountField, string $quantityField, string $statusField): float
    {
        $amount = 0.0;
        $quantity = 0.0;
        foreach ($rows as $row) {
            if (!is_array($row) || !in_array((string)($row['displayMetricStatus'][$statusField] ?? ''), ['ok', 'derived_from_display_metrics'], true)) {
                continue;
            }
            $rowAmount = (float)($row[$amountField] ?? 0);
            $rowQuantity = (float)($row[$quantityField] ?? 0);
            if ($rowAmount <= 0 || $rowQuantity <= 0) {
                continue;
            }
            $amount += $rowAmount;
            $quantity += $rowQuantity;
        }
        return $quantity > 0 ? (float)(int)round($amount / $quantity) : 0.0;
    }

    private function buildMeituanPlatformTagSummary(array $rows): array
    {
        $tagCounts = [];
        $returnedCount = 0;
        $returnedEmptyCount = 0;
        $notReturnedCount = 0;
        $vipCount = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $tags = is_array($row['platformTags'] ?? null) ? $this->mergeStringList([], $row['platformTags']) : [];
            if (!empty($tags)) {
                $returnedCount++;
                if ($this->hasMeituanVipPlatformTag($tags)) {
                    $vipCount++;
                }
                foreach ($tags as $tag) {
                    $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
                }
                continue;
            }

            if (($row['platformTagStatus'] ?? '') === 'returned_empty') {
                $returnedEmptyCount++;
            } else {
                $notReturnedCount++;
            }
        }

        arsort($tagCounts);
        $tags = [];
        foreach ($tagCounts as $tag => $count) {
            $tags[] = ['tag' => (string)$tag, 'count' => (int)$count];
        }

        return [
            'status' => $returnedCount > 0 ? 'returned' : ($returnedEmptyCount > 0 ? 'returned_empty' : 'not_returned'),
            'returned_count' => $returnedCount,
            'vip_count' => $vipCount,
            'returned_empty_count' => $returnedEmptyCount,
            'not_returned_count' => $notReturnedCount,
            'tag_count' => count($tagCounts),
            'tags' => array_slice($tags, 0, 20),
            'source_fields' => ['platformTags', 'platformTagStatus', 'isVip', 'vipFlag', 'memberFlag', 'crownTag'],
            'storage_fields' => [
                'online_daily_data.raw_data.platformTags',
                'online_daily_data.raw_data.platformTagStatus',
                'online_daily_data.raw_data.platformTagText',
                'online_daily_data.raw_data.hasVipTag',
            ],
            'privacy_scope' => '平台门店标签；不包含客人、订单手机号、房态或房源映射',
        ];
    }

    private function buildMeituanRankHealthRows(array $rows): array
    {
        $rankTypes = [
            'P_RZ' => ['label' => '入住榜', 'fields' => ['roomNights', 'roomRevenue']],
            'P_XS' => ['label' => '销售榜', 'fields' => ['salesRoomNights', 'sales']],
            'P_LL' => ['label' => '流量榜', 'fields' => ['exposure', 'views']],
            'P_ZH' => ['label' => '转化榜', 'fields' => ['viewConversion', 'payConversion']],
        ];
        $seen = [];
        $hasValue = [];
        $hasRankOnly = [];
        foreach ($rows as $row) {
            foreach ((array)($row['rankHistory'] ?? []) as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $rankType = (string)($entry['rankType'] ?? '');
                if ($rankType !== '') {
                    $seen[$rankType] = true;
                    if ($this->hasMeituanRankOnlyMetricSource((string)($entry['sourceLabel'] ?? ''))) {
                        $hasRankOnly[$rankType] = true;
                    }
                }
            }
            foreach ($rankTypes as $rankType => $meta) {
                foreach ($meta['fields'] as $field) {
                    $status = (string)($row['metricSourceStatus'][$field] ?? '');
                    if ((float)($row[$field] ?? 0) > 0 || $this->isMeituanMetricSourceUsable($status)) {
                        $hasValue[$rankType] = true;
                    } elseif ($this->hasMeituanRankOnlyMetricSource($status) || isset($row['metricRankPercent'][$field])) {
                        $hasRankOnly[$rankType] = true;
                    }
                }
            }
        }
        $result = [];
        foreach ($rankTypes as $rankType => $meta) {
            $ok = !empty($hasValue[$rankType]);
            $rankOnly = !$ok && (!empty($seen[$rankType]) || !empty($hasRankOnly[$rankType]));
            $status = $ok ? 'ok' : ($rankOnly ? 'rank_only' : 'missing');
            $result[] = [
                'key' => $rankType,
                'label' => $meta['label'],
                'status' => $status,
                'statusText' => $ok ? '已返回' : ($rankOnly ? '仅排名' : '未返回'),
                'sourceLabel' => $ok ? '美团榜单返回' : ($rankOnly ? '美团仅返回百分比' : '系统未返回'),
                'className' => $ok
                    ? 'bg-emerald-50 text-emerald-700 border-emerald-100'
                    : ($rankOnly ? 'bg-amber-50 text-amber-700 border-amber-100' : 'bg-gray-50 text-gray-500 border-gray-200'),
            ];
        }
        return $result;
    }

    private function buildMeituanRankInsightCards(array $rows, array $rankHealthRows, array $funnelDiagnosis = []): array
    {
        $hotelCount = count($rows);
        $healthReady = count(array_filter($rankHealthRows, static fn($row): bool => ($row['status'] ?? '') === 'ok'));
        $vipTaggedCount = count(array_filter($rows, static fn($row): bool => !empty($row['hasVipTag'])));
        $tagReturnedCount = count(array_filter($rows, static fn($row): bool => !empty($row['platformTags'])));
        $selfRow = $this->findMeituanSelfDisplayRow($rows);
        $top = $rows[0] ?? null;
        $conversionRiskRows = array_values(array_filter($rows, static function ($row): bool {
            $views = (float)($row['views'] ?? 0);
            $payConversion = (float)($row['payConversion'] ?? 0);
            return $views > 0 && $payConversion > 0 && $payConversion < 0.05;
        }));
        $positiveExposureRows = array_values(array_filter($rows, static fn($row): bool => (float)($row['exposure'] ?? 0) > 0));
        $positivePayConversionRows = array_values(array_filter($rows, static fn($row): bool => (float)($row['payConversion'] ?? 0) > 0));
        $avgExposure = count($positiveExposureRows) > 0 ? array_sum(array_map(static fn($row): float => (float)($row['exposure'] ?? 0), $positiveExposureRows)) / count($positiveExposureRows) : 0.0;
        $avgPayConversion = count($positivePayConversionRows) > 0 ? array_sum(array_map(static fn($row): float => (float)($row['payConversion'] ?? 0), $positivePayConversionRows)) / count($positivePayConversionRows) : 0.0;
        $vipExposureLowConversionRows = array_values(array_filter($rows, static function ($row) use ($avgExposure, $avgPayConversion): bool {
            return !empty($row['hasVipTag'])
                && $avgExposure > 0
                && $avgPayConversion > 0
                && (float)($row['exposure'] ?? 0) >= $avgExposure
                && (float)($row['payConversion'] ?? 0) < $avgPayConversion;
        }));
        $nonVipSalesOverSelfRows = [];
        if (is_array($selfRow)) {
            $selfSales = (float)($selfRow['sales'] ?? 0);
            if ($selfSales > 0) {
                $nonVipSalesOverSelfRows = array_values(array_filter($rows, static function ($row) use ($selfSales): bool {
                    return empty($row['hasVipTag']) && (float)($row['sales'] ?? 0) > $selfSales;
                }));
            }
        }
        if (count($vipExposureLowConversionRows) > 0) {
            $tagMetricValue = 'VIP转化低 ' . count($vipExposureLowConversionRows) . '家';
            $tagMetricNote = (string)($vipExposureLowConversionRows[0]['hotelName'] ?? 'VIP竞对') . '曝光高于圈内均值但支付转化低于均值';
            $tagMetricClass = 'bg-rose-50 text-rose-700 border-rose-100';
        } elseif (count($nonVipSalesOverSelfRows) > 0) {
            $tagMetricValue = '非VIP超过本店';
            $tagMetricNote = (string)($nonVipSalesOverSelfRows[0]['hotelName'] ?? '非VIP竞对') . '销售额超过本店，优先看价格与转化差距';
            $tagMetricClass = 'bg-amber-50 text-amber-700 border-amber-100';
        } elseif ($tagReturnedCount > 0) {
            $tagMetricValue = '可联动分析';
            $tagMetricNote = '平台标签已返回，可与曝光、销售额、支付转化一起判断权益影响';
            $tagMetricClass = 'bg-blue-50 text-blue-700 border-blue-100';
        } else {
            $tagMetricValue = '未返回';
            $tagMetricNote = '平台未返回标签，不判断 VIP、皇冠或权益造成的差距';
            $tagMetricClass = 'bg-gray-50 text-gray-500 border-gray-200';
        }
        $topRoomNightsText = is_array($top) ? (string)($top['roomNightsText'] ?? '-') : '-';
        $topGapText = is_array($top) ? (string)($top['gapToNextText'] ?? '暂无后一名差距') : '暂无后一名差距';
        $topSummaryNote = $topRoomNightsText !== '-'
            ? ($topRoomNightsText . ' 间夜 · ' . $topGapText)
            : ('入住间夜未返回 · ' . $topGapText);

        return [
            [
                'key' => 'rank-health',
                'label' => '榜单健康度',
                'value' => $healthReady . '/' . count($rankHealthRows),
                'note' => $healthReady === count($rankHealthRows) ? '四类榜单均有返回' : '存在未返回榜单，保留缺失状态',
                'className' => $healthReady === count($rankHealthRows) ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : 'bg-amber-50 text-amber-700 border-amber-100',
            ],
            [
                'key' => 'self-position',
                'label' => '本店位置',
                'value' => $selfRow ? (string)($selfRow['circlePositionText'] ?? '已返回') : '未返回',
                'note' => $selfRow ? (string)($selfRow['gapToLeaderText'] ?? '可对比TOP1') : '目标 POI 未出现在本次榜单',
                'className' => $selfRow ? 'bg-blue-50 text-blue-700 border-blue-100' : 'bg-gray-50 text-gray-500 border-gray-200',
            ],
            [
                'key' => 'rank-gap',
                'label' => '排名差距',
                'value' => $selfRow ? (string)($selfRow['gapToPrevText'] ?? '已返回') : '未返回',
                'note' => $selfRow ? (string)($selfRow['rankGapSummaryText'] ?? $selfRow['rank30RangeText'] ?? '近30天未返回') : '目标 POI 未出现在本次榜单',
                'className' => $selfRow ? 'bg-cyan-50 text-cyan-700 border-cyan-100' : 'bg-gray-50 text-gray-500 border-gray-200',
            ],
            !empty($funnelDiagnosis) ? $funnelDiagnosis : [
                'key' => 'funnel-diagnosis',
                'label' => '四榜卡点',
                'value' => '未生成',
                'note' => '缺少本店或榜单字段，暂不生成卡点判断',
                'className' => 'bg-gray-50 text-gray-500 border-gray-200',
            ],
            [
                'key' => 'platform-tags',
                'label' => '平台标签',
                'value' => $tagReturnedCount > 0 ? ('VIP ' . $vipTaggedCount . '家') : '未返回',
                'note' => $tagReturnedCount > 0 ? "共{$tagReturnedCount}/{$hotelCount}家返回平台标签" : '不猜测 VIP、皇冠或权益标签',
                'className' => $tagReturnedCount > 0 ? 'bg-orange-50 text-orange-700 border-orange-100' : 'bg-gray-50 text-gray-500 border-gray-200',
            ],
            [
                'key' => 'tag-metric-link',
                'label' => '标签指标联动',
                'value' => $tagMetricValue,
                'note' => $tagMetricNote,
                'className' => $tagMetricClass,
            ],
            [
                'key' => 'top-summary',
                'label' => 'TOP1',
                'value' => is_array($top) ? (string)($top['hotelName'] ?? '-') : '-',
                'note' => is_array($top) ? $topSummaryNote : '暂无可展示榜单',
                'className' => 'bg-indigo-50 text-indigo-700 border-indigo-100',
            ],
            [
                'key' => 'conversion-risk',
                'label' => '转化异常',
                'value' => count($conversionRiskRows) > 0 ? (count($conversionRiskRows) . '家') : '暂无',
                'note' => count($conversionRiskRows) > 0 ? '存在浏览高但支付转化偏低的聚合信号' : '当前未命中低支付转化聚合信号',
                'className' => count($conversionRiskRows) > 0 ? 'bg-rose-50 text-rose-700 border-rose-100' : 'bg-emerald-50 text-emerald-700 border-emerald-100',
            ],
        ];
    }

    private function buildMeituanTopSummaryRows(array $rows): array
    {
        return array_map(static fn(array $row): array => [
            'poiId' => (string)($row['poiId'] ?? ''),
            'hotelName' => (string)($row['hotelName'] ?? ''),
            'positionText' => (string)($row['circlePositionText'] ?? ''),
            'rankTrendText' => (string)($row['rankTrendText'] ?? ''),
            'platformTagText' => (string)($row['platformTagText'] ?? '未返回'),
            'roomNights' => (float)($row['roomNights'] ?? 0),
            'roomNightsText' => (string)($row['roomNightsText'] ?? '-'),
            'sales' => (float)($row['sales'] ?? 0),
            'salesText' => (string)($row['salesText'] ?? '-'),
            'gapToNextText' => (string)($row['gapToNextText'] ?? ''),
        ], array_slice($rows, 0, 3));
    }

    private function buildCtripBusinessDisplaySummary(array $rows): array
    {
        $rows = array_values(array_filter($rows, fn($row): bool => is_array($row) && $this->isCtripBusinessDisplayRow($row)));
        if (empty($rows)) {
            return $this->emptyCtripBusinessDisplaySummary();
        }

        $hotelCount = count($rows);
        $totalAmount = round($this->sumCtripBusinessRows($rows, 'amount'), 2);
        $totalQuantity = (int)$this->sumCtripBusinessRows($rows, 'quantity');
        $totalDetailNum = (int)$this->sumCtripBusinessRows($rows, 'totalDetailNum');
        $totalQunarDetailVisitors = (int)$this->sumCtripBusinessRows($rows, 'qunarDetailVisitors');
        $aiEstimatedTotalRoomNights = (int)$this->sumCtripBusinessRows($rows, 'aiEstimatedTotalRoomNights');
        $totalOrderNum = (int)$this->sumCtripBusinessRows($rows, 'totalOrderNum');
        $adr = $totalQuantity > 0 ? round($totalAmount / $totalQuantity, 2) : 0.0;
        $avgAri = $this->avgCtripBusinessRows($rows, 'ari');
        $avgSci = $this->avgCtripBusinessRows($rows, 'sci');
        $trafficValue = ($totalDetailNum + $totalQunarDetailVisitors) > 0 ? round($totalAmount / ($totalDetailNum + $totalQunarDetailVisitors), 2) : 0.0;
        $revenueHhi = $this->hhiCtripBusinessRows($rows, 'amount');
        $visitHhi = $this->hhiCtripBusinessRows($rows, 'totalDetailNum');
        $priceSigma = $this->priceSigmaCtripBusinessRows($rows);
        $ctripReviewImpact = $this->reviewImpactCtripBusinessRows($rows, 'commentScore', 'convertionRate');
        $qunarReviewImpact = $this->reviewImpactCtripBusinessRows($rows, 'qunarCommentScore', 'qunarDetailCR');

        $ariLevel = $this->levelForCtripBusinessMetric($avgAri, [
            [80, '价格偏低', 'text-red-600'],
            [95, '略低于均价', 'text-orange-600'],
            [110, '价格合理', 'text-green-600'],
            [130, '价格偏高', 'text-yellow-600'],
            [INF, '溢价优势', 'text-blue-600'],
        ]);
        $sciLevel = $this->levelForCtripBusinessMetric($avgSci, [
            [150, '极弱', 'text-red-600'],
            [260, '偏弱', 'text-orange-600'],
            [370, '中等', 'text-green-600'],
            [480, '较强', 'text-yellow-600'],
            [INF, '极强', 'text-blue-600'],
        ]);
        $revenueLevel = $this->levelForCtripBusinessMetric($revenueHhi, [
            [500, '低度内卷', 'text-yellow-600'],
            [800, '中度内卷', 'text-orange-600'],
            [INF, '寡头市场', 'text-red-600'],
        ]);
        $visitLevel = $this->levelForCtripBusinessMetric($visitHhi, [
            [400, '低度内卷', 'text-yellow-600'],
            [700, '中度内卷', 'text-orange-600'],
            [INF, '高度内卷', 'text-red-600'],
        ]);
        $priceLevel = $this->levelForCtripBusinessMetric($priceSigma, [
            [3, '健康', 'text-green-600'],
            [8, '良好', 'text-emerald-600'],
            [15, '激烈', 'text-orange-600'],
            [INF, '高分化', 'text-red-600'],
        ]);

        $metrics = [
            'hotelCount' => $hotelCount,
            'totalAmount' => $totalAmount,
            'totalQuantity' => $totalQuantity,
            'adr' => $adr,
            'avgAri' => $avgAri,
            'avgSci' => $avgSci,
            'totalDetailNum' => $totalDetailNum,
            'totalQunarDetailVisitors' => $totalQunarDetailVisitors,
            'aiEstimatedTotalRoomNights' => $aiEstimatedTotalRoomNights,
            'totalOrderNum' => $totalOrderNum,
            'trafficValue' => $trafficValue,
            'revenueConcentration' => $revenueHhi,
            'visitConcentration' => $visitHhi,
            'priceSigma' => $priceSigma,
            'ctripReviewImpact' => $ctripReviewImpact,
            'qunarReviewImpact' => $qunarReviewImpact,
            'sourceStatusReadyCount' => count(array_filter($rows, static fn($row): bool => ($row['sourceStatusText'] ?? '') === '携程竞争圈返回')),
            'sourceStatusTotalCount' => $hotelCount,
        ];

        return [
            'status' => 'success',
            'metrics' => $metrics,
            'cards' => [
                $this->ctripBusinessSummaryCard('hotelCount', '酒店总数', number_format($hotelCount), 'text-gray-700', 'bg-blue-50 border border-blue-200'),
                $this->ctripBusinessSummaryCard('totalAmount', '总销售额', '¥' . number_format((float)round($totalAmount)), 'text-green-600', 'bg-green-50 border border-green-200'),
                $this->ctripBusinessSummaryCard('totalQuantity', '总间夜量', number_format($totalQuantity), 'text-yellow-600', 'bg-yellow-50 border border-yellow-200'),
                $this->ctripBusinessSummaryCard('adr', '平均房价(ADR)', $adr > 0 ? '¥' . number_format($adr, 2, '.', ',') : '-', 'text-purple-600', 'bg-purple-50 border border-purple-200'),
                $this->ctripBusinessSummaryCard('avgAri', '平均房价指数(ARI)', $avgAri > 0 ? number_format($avgAri, 1, '.', '') : '-', 'text-orange-600', 'bg-orange-50 border border-orange-200', $ariLevel),
                $this->ctripBusinessSummaryCard('avgSci', '商圈综合竞争力指数(SCI)', $avgSci > 0 ? number_format($avgSci, 0, '.', ',') : '-', 'text-cyan-600', 'bg-cyan-50 border border-cyan-200', $sciLevel),
                $this->ctripBusinessSummaryCard('totalDetailNum', '携程APP总访客量', number_format($totalDetailNum), 'text-indigo-600', 'bg-indigo-50 border border-indigo-200'),
                $this->ctripBusinessSummaryCard('totalQunarDetailVisitors', '去哪儿总访客量', number_format($totalQunarDetailVisitors), 'text-teal-600', 'bg-teal-50 border border-teal-200'),
                $this->ctripBusinessSummaryCard('aiEstimatedTotalRoomNights', '全渠道AI预计总间夜数', number_format($aiEstimatedTotalRoomNights), 'text-yellow-600', 'bg-yellow-50 border border-yellow-200'),
                $this->ctripBusinessSummaryCard('trafficValue', '流量价值效率', $trafficValue > 0 ? '¥' . number_format($trafficValue, 2, '.', ',') : '-', 'text-blue-600', 'bg-blue-50 border border-blue-200'),
                $this->ctripBusinessSummaryCard('revenueConcentration', '收益集中度', number_format($revenueHhi, 2, '.', ''), 'text-orange-600', 'bg-orange-50 border border-orange-200', $revenueLevel),
                $this->ctripBusinessSummaryCard('visitConcentration', '浏览/访客集中度', number_format($visitHhi, 2, '.', ''), 'text-orange-600', 'bg-orange-50 border border-orange-200', $visitLevel),
                $this->ctripBusinessSummaryCard('priceSigma', '竞争健康度', $priceSigma > 0 ? number_format($priceSigma, 2, '.', '') . '%' : '-', 'text-orange-600', 'bg-orange-50 border border-orange-200', $priceLevel),
                $this->ctripBusinessSummaryCard('ctripReviewImpact', '携程点评分-转化率影响因子(R)', $ctripReviewImpact > 0 ? number_format($ctripReviewImpact, 1, '.', '') : '-', 'text-orange-600', 'bg-orange-50 border border-orange-200'),
                $this->ctripBusinessSummaryCard('qunarReviewImpact', '去哪儿点评分-转化率影响因子(R)', $qunarReviewImpact > 0 ? number_format($qunarReviewImpact, 1, '.', '') : '-', 'text-orange-600', 'bg-orange-50 border border-orange-200'),
                $this->ctripBusinessSummaryCard('sourceStatus', '数据来源', '携程竞争圈返回', 'text-blue-600', 'bg-blue-50 border border-blue-200'),
            ],
            'source_notice' => '仅展示携程竞争圈/榜单已返回字段；“全渠道AI预计总间夜数”为AI推导，非平台原始字段。竞争健康度使用当前快照的房价离散系数=价格标准差/圈内平均房价；无可比基期时不输出改善或恶化趋势。单项缺失保留“系统未返回”，不把 OTA 渠道数据当全酒店经营事实。',
        ];
    }

    private function emptyCtripBusinessDisplaySummary(): array
    {
        return [
            'status' => 'empty',
            'metrics' => [
                'hotelCount' => 0,
                'totalAmount' => 0.0,
                'totalQuantity' => 0,
                'adr' => 0.0,
                'avgAri' => 0.0,
                'avgSci' => 0.0,
                'totalDetailNum' => 0,
                'totalQunarDetailVisitors' => 0,
                'aiEstimatedTotalRoomNights' => 0,
                'totalOrderNum' => 0,
                'trafficValue' => 0.0,
                'revenueConcentration' => 0.0,
                'visitConcentration' => 0.0,
                'priceSigma' => 0.0,
                'ctripReviewImpact' => 0.0,
                'qunarReviewImpact' => 0.0,
                'sourceStatusReadyCount' => 0,
                'sourceStatusTotalCount' => 0,
            ],
            'cards' => [],
            'source_notice' => '携程竞争圈数据未返回；缺失字段保留“系统未返回”。',
        ];
    }

    private function businessSummaryCard(string $key, string $label, string $value, string $valueClass, string $panelClass, array $level = []): array
    {
        return $this->ctripBusinessSummaryCard($key, $label, $value, $valueClass, $panelClass, $level);
    }

    private function ctripBusinessSummaryCard(string $key, string $label, string $value, string $valueClass, string $panelClass, array $level = []): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'valueClass' => $valueClass,
            'panelClass' => $panelClass,
            'level' => (string)($level['level'] ?? ''),
            'levelClass' => (string)($level['levelClass'] ?? ''),
        ];
    }

    private function sumCtripBusinessRows(array $rows, string $field): float
    {
        $sum = 0.0;
        foreach ($rows as $row) {
            $sum += (float)($row[$field] ?? 0);
        }
        return $sum;
    }

    private function sumBusinessRows(array $rows, string $field): float
    {
        return $this->sumCtripBusinessRows($rows, $field);
    }

    private function sumMeituanDisplayableMoneyRows(array $rows, string $field): float
    {
        $sum = 0.0;
        foreach ($rows as $row) {
            if (!is_array($row) || !$this->isMeituanDisplayableMoneyMetricSource($row, $field)) {
                continue;
            }
            $sum += (float)($row[$field] ?? 0);
        }
        return $sum;
    }

    private function hasMeituanDisplayableMetricRows(array $rows, string $field): bool
    {
        foreach ($rows as $row) {
            if (!is_array($row) || $this->isMeituanPercentScaleDerivedMetric($row, $field)) {
                continue;
            }
            $status = (string)($row['metricSourceStatus'][$field] ?? '');
            if ($this->isMeituanMetricSourceUsable($status) || (float)($row[$field] ?? 0) > 0) {
                return true;
            }
        }
        return false;
    }

    private function avgCtripBusinessRows(array $rows, string $field): float
    {
        $values = [];
        foreach ($rows as $row) {
            $value = (float)($row[$field] ?? 0);
            if ($value > 0) {
                $values[] = $value;
            }
        }
        return count($values) > 0 ? round(array_sum($values) / count($values), 2) : 0.0;
    }

    private function avgBusinessRows(array $rows, string $field): float
    {
        $values = [];
        foreach ($rows as $row) {
            $value = (float)($row[$field] ?? 0);
            if ($value > 0) {
                $values[] = $value;
            }
        }
        return count($values) > 0 ? array_sum($values) / count($values) : 0.0;
    }

    private function hasPositiveBusinessRows(array $rows, string $field): bool
    {
        foreach ($rows as $row) {
            if ((float)($row[$field] ?? 0) > 0) {
                return true;
            }
        }
        return false;
    }

    private function hhiCtripBusinessRows(array $rows, string $field): float
    {
        $values = [];
        foreach ($rows as $row) {
            $value = (float)($row[$field] ?? 0);
            if ($value > 0) {
                $values[] = $value;
            }
        }
        $total = array_sum($values);
        if ($total <= 0) {
            return 0.0;
        }
        $hhi = 0.0;
        foreach ($values as $value) {
            $share = $value / $total;
            $hhi += $share * $share;
        }
        return round($hhi * 10000, 2);
    }

    private function hhiBusinessRows(array $rows, string $field): float
    {
        return $this->hhiCtripBusinessRows($rows, $field);
    }

    private function meituanBusinessMarketInventory(array $context): int
    {
        $roomCount = (int)($context['competitor_room_count'] ?? $context['market_inventory'] ?? $context['marketInventory'] ?? 0);
        if ($roomCount <= 0) {
            return 0;
        }
        return $roomCount * $this->resolveMeituanBusinessDisplayDays($context);
    }

    private function resolveMeituanBusinessDisplayDays(array $context): int
    {
        $dateRanges = $context['date_ranges'] ?? [];
        if (is_string($dateRanges)) {
            $decodedDateRanges = json_decode($dateRanges, true);
            $dateRanges = is_array($decodedDateRanges) ? $decodedDateRanges : [$dateRanges];
        }
        if (!is_array($dateRanges)) {
            $dateRanges = [];
        }
        if (!empty($context['date_range'])) {
            $dateRanges[] = (string)$context['date_range'];
        }

        $dateRanges = array_map(static fn($value): string => (string)$value, $dateRanges);
        if (in_array('0', $dateRanges, true) || in_array('1', $dateRanges, true)) {
            return 1;
        }
        if (in_array('7', $dateRanges, true)) {
            return 7;
        }
        if (in_array('30', $dateRanges, true)) {
            return 30;
        }
        if (in_array('custom', $dateRanges, true)) {
            $start = strtotime((string)($context['start_date'] ?? ''));
            $end = strtotime((string)($context['end_date'] ?? ''));
            if ($start !== false && $end !== false && $end >= $start) {
                return max(1, (int)floor(($end - $start) / 86400) + 1);
            }
        }
        return 1;
    }

    private function meituanBusinessPriceSigma(array $rows): float
    {
        $values = [];
        foreach ($rows as $row) {
            $value = (float)($row['avgRoomPrice'] ?? 0);
            if ($value > 0) {
                $values[] = $value;
            }
        }
        if (count($values) < 2) {
            return 0.0;
        }
        $avg = array_sum($values) / count($values);
        if ($avg <= 0) {
            return 0.0;
        }
        $variance = 0.0;
        foreach ($values as $value) {
            $variance += ($value - $avg) ** 2;
        }
        return round(sqrt($variance / count($values)) / max($avg, 1) * 100, 2);
    }

    private function meituanBusinessMarketPriceSignal(float $avgRoomPrice, float $avgSalesPrice): string
    {
        if ($avgRoomPrice <= 0 || $avgSalesPrice <= 0) {
            return '-';
        }
        $ratio = $avgSalesPrice / $avgRoomPrice;
        if ($ratio >= 1.05) {
            return '销售价偏高';
        }
        if ($ratio <= 0.95) {
            return '销售价偏低';
        }
        return '价格稳定';
    }

    private function meituanBusinessVitalityLevel(float $value): array
    {
        return $this->levelForCtripBusinessMetric($value, [
            [50, '偏低', 'text-orange-600'],
            [90, '活跃', 'text-green-600'],
            [INF, '高活跃', 'text-blue-600'],
        ]);
    }

    private function meituanBusinessPriceSigmaLevel(float $value): array
    {
        return $this->levelForCtripBusinessMetric($value, [
            [5, '健康', 'text-green-600'],
            [12, '波动', 'text-orange-600'],
            [INF, '分化', 'text-red-600'],
        ]);
    }

    private function priceSigmaCtripBusinessRows(array $rows): float
    {
        $values = [];
        foreach ($rows as $row) {
            $value = (float)($row['adr'] ?? 0);
            if ($value > 0) {
                $values[] = $value;
            }
        }
        if (count($values) < 2) {
            return 0.0;
        }
        $avg = array_sum($values) / count($values);
        if ($avg <= 0) {
            return 0.0;
        }
        $variance = 0.0;
        foreach ($values as $value) {
            $variance += ($value - $avg) ** 2;
        }
        return round(sqrt($variance / count($values)) / max($avg, 1) * 100, 2);
    }

    private function reviewImpactCtripBusinessRows(array $rows, string $scoreField, string $rateField): float
    {
        $values = [];
        foreach ($rows as $row) {
            $score = (float)($row[$scoreField] ?? 0);
            $rate = (float)($row[$rateField] ?? 0);
            if ($score > 0 && $rate > 0) {
                $values[] = $score / max($rate, 0.01) * 100;
            }
        }
        return count($values) > 0 ? round(array_sum($values) / count($values), 2) : 0.0;
    }

    private function levelForCtripBusinessMetric(float $value, array $ranges): array
    {
        if ($value <= 0) {
            return ['level' => '数据不足', 'levelClass' => 'text-gray-500'];
        }
        foreach ($ranges as $range) {
            if ($value < (float)$range[0] || (float)$range[0] === INF) {
                return ['level' => (string)$range[1], 'levelClass' => (string)$range[2]];
            }
        }
        return ['level' => '', 'levelClass' => ''];
    }

    private function emptyMeituanBusinessDisplaySummary(): array
    {
        return [
            'status' => 'empty',
            'metrics' => [
                'hotelCount' => 0,
                'rankHealthReadyCount' => 0,
                'rankHealthTotalCount' => 4,
                'vipTaggedCount' => 0,
                'platformTagReturnedCount' => 0,
                'selfPositionText' => '本店未返回',
                'marketInventory' => 0,
                'marketVitalityRate' => 0.0,
                'priceSigma' => 0.0,
                'marketPriceSignal' => '-',
                'inventoryTurnoverRate' => 0.0,
                'revenueConcentration' => 0.0,
                'visitConcentration' => 0.0,
                'operationFocus' => '-',
                'totalRoomNights' => 0.0,
                'totalRoomRevenue' => 0.0,
                'totalSalesRoomNights' => 0.0,
                'totalSales' => 0.0,
                'totalExposure' => 0.0,
                'totalViews' => 0.0,
                'totalOrderCount' => 0,
                'avgRoomPrice' => 0.0,
                'avgSalesPrice' => 0.0,
                'avgViewConversionRate' => 0.0,
                'avgPayConversionRate' => 0.0,
                'avgAbsoluteConversionRate' => 0.0,
                'funnelDiagnosisValue' => '本店未返回',
                'funnelDiagnosisIssueCount' => 0,
            ],
            'cards' => [],
            'rank_insights' => [],
            'rank_health_rows' => [],
            'top_summary_rows' => [],
            'platform_tag_summary' => $this->buildMeituanPlatformTagSummary([]),
            'funnel_diagnosis' => [
                'key' => 'funnel-diagnosis',
                'label' => '四榜卡点',
                'value' => '本店未返回',
                'note' => '美团榜单数据未返回，暂不生成卡点判断',
                'className' => 'bg-gray-50 text-gray-500 border-gray-200',
                'details' => [],
                'missing' => ['美团榜单数据未返回'],
            ],
            'source_notice' => '未返回字段保持缺失。',
        ];
    }

    private function buildMeituanBusinessDisplayContext(): array
    {
        $dateRanges = $this->request->post('date_ranges', $this->request->post('date_range', []));
        if (is_string($dateRanges)) {
            $decodedDateRanges = json_decode($dateRanges, true);
            $dateRanges = is_array($decodedDateRanges) ? $decodedDateRanges : [$dateRanges];
        }

        return [
            'competitor_room_count' => (int)$this->request->post('competitor_room_count', 0),
            'date_ranges' => is_array($dateRanges) ? array_values($dateRanges) : [],
            'date_range' => $this->request->post('date_range', ''),
            'rank_type' => (string)$this->request->post('rank_type', ''),
            'target_poi_id' => (string)$this->request->post('target_poi_id', $this->request->post('poi_id', '')),
            'system_hotel_id' => (int)$this->request->post('system_hotel_id', $this->request->post('hotel_id', 0)),
            'start_date' => (string)$this->request->post('start_date', ''),
            'end_date' => (string)$this->request->post('end_date', ''),
            'self_metric_values' => $this->requestMeituanSelfMetricValues(),
        ];
    }

    private function requestMeituanSelfMetricValues(): array
    {
        $payload = $this->request->post('self_metric_values', $this->request->post('selfMetricValues', null));
        if ($payload === null || $payload === '') {
            $payload = $this->request->get('self_metric_values', $this->request->get('selfMetricValues', []));
        }
        $values = $this->normalizeMeituanSelfMetricValues($payload);
        $requestKeys = [
            'roomNights' => ['self_room_nights', 'selfRoomNights'],
            'roomRevenue' => ['self_room_revenue', 'selfRoomRevenue'],
            'salesRoomNights' => ['self_sales_room_nights', 'selfSalesRoomNights'],
            'sales' => ['self_sales', 'selfSales', 'self_sales_amount', 'selfSalesAmount'],
            'orderCount' => ['self_order_count', 'selfOrderCount', 'self_pay_order_count', 'selfPayOrderCount'],
            'viewConversion' => ['self_view_conversion', 'selfViewConversion'],
            'payConversion' => ['self_pay_conversion', 'selfPayConversion'],
            'exposure' => ['self_exposure', 'selfExposure'],
            'views' => ['self_views', 'selfViews'],
        ];
        foreach ($requestKeys as $field => $keys) {
            foreach ($keys as $key) {
                $raw = $this->request->post($key, null);
                if ($raw === null || $raw === '') {
                    $raw = $this->request->get($key, null);
                }
                if ($raw === null || $raw === '') {
                    continue;
                }
                $number = $this->nullableNumberFromKeys([$key => $raw], [$key]);
                if ($number !== null) {
                    if (in_array($field, ['viewConversion', 'payConversion'], true)) {
                        $number = $this->normalizeMeituanRatioMetric($number);
                    }
                    $values[$field] = $number;
                    break;
                }
            }
        }
        return $values;
    }

    private function buildMeituanCompetitorSummaryPayload(string $hotelId, $currentUser = null, bool $includeByHotel = false): array
    {
        $range = $this->normalizeMeituanCompetitorSummaryRange((string)$this->request->get('range', ''));
        $targetDate = $this->resolveMeituanCompetitorSummaryTargetDate($range);
        $latest = $this->findLatestMeituanCompetitorStoredRow($hotelId, $currentUser, $targetDate);
        if (empty($latest)) {
            $payload = $this->emptyMeituanCompetitorSummaryPayload([
                'hotel_id' => $hotelId,
                'message' => '未找到美团竞对榜单入库数据',
            ]);
        } else {
            $effectiveHotelId = $hotelId !== '' ? $hotelId : (string)($latest['system_hotel_id'] ?? '');
            $rows = $this->fetchMeituanCompetitorStoredRowsForLatest($latest, $effectiveHotelId, $currentUser);
            $systemHotelId = (int)($latest['system_hotel_id'] ?? 0);
            $context = [
                'system_hotel_id' => $systemHotelId > 0 ? $systemHotelId : null,
                'target_poi_id' => $this->resolveMeituanTargetPoiIdForSystemHotel($systemHotelId, $currentUser),
                'data_date' => (string)($latest['data_date'] ?? ''),
                'source' => 'online_daily_data',
            ];
            $selfMetricValues = $this->requestMeituanSelfMetricValues();
            if (!empty($selfMetricValues)) {
                $context['self_metric_values'] = $selfMetricValues;
            }
            $payload = $this->buildMeituanCompetitorSummaryFromStoredRows($rows, $context);
            $comparison = $this->buildMeituanCompetitorSummaryComparison($latest, $effectiveHotelId, $currentUser, $context, $range);
            if ($comparison !== null) {
                $payload['comparison'] = $comparison;
            }
        }

        $payload['scope'] = $hotelId !== '' ? 'hotel' : 'latest';
        if ($includeByHotel) {
            $payload['by_hotel'] = $this->buildMeituanCompetitorSummaryByHotel($currentUser);
        }
        return $payload;
    }

    private function normalizeMeituanCompetitorSummaryRange(string $range): string
    {
        $range = trim($range);
        return in_array($range, ['realtime', 'yesterday'], true) ? $range : '';
    }

    private function resolveMeituanCompetitorSummaryTargetDate(string $range): string
    {
        return $range === 'yesterday' ? date('Y-m-d', strtotime('-1 day')) : '';
    }

    private function buildMeituanCompetitorSummaryComparison(array $latest, string $hotelId, $currentUser, array $context, string $range): ?array
    {
        if (!in_array($range, ['realtime', 'yesterday'], true) || empty($latest['data_date'])) {
            return null;
        }
        $previousDate = date('Y-m-d', strtotime((string)$latest['data_date'] . ' -1 day'));
        $previousLatest = $this->findLatestMeituanCompetitorStoredRow($hotelId, $currentUser, $previousDate);
        if (empty($previousLatest)) {
            return null;
        }

        $rows = $this->fetchMeituanCompetitorStoredRowsForLatest($previousLatest, $hotelId, $currentUser);
        if (empty($rows)) {
            return null;
        }

        $comparisonContext = $context;
        $comparisonContext['data_date'] = $previousDate;
        $comparisonContext['source'] = 'online_daily_data';
        unset($comparisonContext['self_metric_values'], $comparisonContext['selfMetricValues']);
        $summary = $this->buildMeituanCompetitorSummaryFromStoredRows($rows, $comparisonContext);
        return [
            'data_date' => $previousDate,
            'display_hotels' => $summary['display_hotels'] ?? [],
            'display_summary' => $summary['display_summary'] ?? $this->emptyMeituanBusinessDisplaySummary(),
            'record_count' => (int)($summary['record_count'] ?? count($rows)),
        ];
    }

    private function findLatestMeituanCompetitorStoredRow(string $hotelId, $currentUser = null, string $targetDate = ''): array
    {
        $columns = $this->getOnlineDailyDataColumns();
        $query = Db::name('online_daily_data');
        $this->applyMeituanCompetitorStoredScope($query, $columns);
        $this->applyMeituanCompetitorUserScope($query, $currentUser, $columns);
        if ($hotelId !== '') {
            $this->applyOnlineDailyDataHotelFilter($query, $hotelId);
        }
        if ($targetDate !== '' && isset($columns['data_date'])) {
            $query->where('data_date', $targetDate);
        }
        if (isset($columns['data_date'])) {
            $query->order('data_date', 'desc');
        }
        $this->orderOnlineDataByFetchTime($query, $columns, 'desc');
        $row = $query->field($this->onlineDailySummaryFieldList($columns))->find();
        return is_array($row) ? $row : [];
    }

    private function fetchMeituanCompetitorStoredRowsForLatest(array $latest, string $hotelId, $currentUser = null): array
    {
        $columns = $this->getOnlineDailyDataColumns();
        $query = Db::name('online_daily_data');
        $this->applyMeituanCompetitorStoredScope($query, $columns);
        $this->applyMeituanCompetitorUserScope($query, $currentUser, $columns);
        if ($hotelId !== '') {
            $this->applyOnlineDailyDataHotelFilter($query, $hotelId);
        }
        $this->applyMeituanCompetitorLatestBatchScope($query, $latest, $hotelId, $columns);
        if (isset($columns['data_date']) && (string)($latest['data_date'] ?? '') !== '') {
            $query->where('data_date', (string)$latest['data_date']);
        }
        $this->orderOnlineDataByFetchTime($query, $columns, 'desc');
        return $query->field($this->onlineDailySummaryFieldList($columns))->limit(800)->select()->toArray();
    }

    private function buildMeituanCompetitorSummaryByHotel($currentUser = null): array
    {
        $columns = $this->getOnlineDailyDataColumns();
        if (!isset($columns['system_hotel_id'])) {
            return [];
        }

        $query = Db::name('online_daily_data')
            ->field('system_hotel_id,MAX(data_date) AS data_date')
            ->whereNotNull('system_hotel_id')
            ->group('system_hotel_id')
            ->order('data_date', 'desc')
            ->limit(200);
        $this->applyMeituanCompetitorStoredScope($query, $columns);
        $this->applyMeituanCompetitorUserScope($query, $currentUser, $columns);

        $result = [];
        foreach ($query->select()->toArray() as $latest) {
            $systemHotelId = (int)($latest['system_hotel_id'] ?? 0);
            if ($systemHotelId <= 0) {
                continue;
            }
            $rows = $this->fetchMeituanCompetitorStoredRowsForLatest($latest, (string)$systemHotelId, $currentUser);
            $summary = $this->buildMeituanCompetitorSummaryFromStoredRows($rows, [
                'system_hotel_id' => $systemHotelId,
                'target_poi_id' => $this->resolveMeituanTargetPoiIdForSystemHotel($systemHotelId, $currentUser),
                'data_date' => (string)($latest['data_date'] ?? ''),
                'source' => 'online_daily_data',
            ]);
            $result[(string)$systemHotelId] = $summary;
        }
        return $result;
    }

    private function buildMeituanCompetitorSummaryFromStoredRows(array $storedRows, array $context = []): array
    {
        $context = $this->withMeituanStoredSelfMetricAnchor($storedRows, $context);
        $displayRows = [];
        $latestDataDate = (string)($context['data_date'] ?? '');
        $latestFetchedAt = '';

        foreach ($storedRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $latestDataDate = max($latestDataDate, (string)($row['data_date'] ?? ''));
            $latestFetchedAt = max($latestFetchedAt, (string)($row['update_time'] ?? $row['create_time'] ?? ''));
            $displayRow = $this->meituanStoredRankRowToDisplayRow($row, $context);
            if (!empty($displayRow)) {
                $displayRows[] = $displayRow;
            }
        }

        if (empty($displayRows)) {
            return $this->emptyMeituanCompetitorSummaryPayload(array_merge($context, [
                'record_count' => count($storedRows),
                'latest_data_date' => $latestDataDate,
                'latest_fetched_at' => $latestFetchedAt,
                'message' => '未找到可还原的美团竞对榜单行',
            ]));
        }

        $displayHotels = $this->mergeMeituanBusinessDisplayHotels($displayRows, $context);
        $displaySummary = $this->buildMeituanBusinessDisplaySummary($displayHotels, $context);
        $readiness = $this->buildMeituanCompetitorSummaryReadiness($displayHotels, $context, true, $displaySummary);
        return [
            'status' => 'success',
            'data_status' => 'success',
            'message' => 'ok',
            'source' => 'online_daily_data',
            'system_hotel_id' => $context['system_hotel_id'] ?? null,
            'target_poi_id' => (string)($context['target_poi_id'] ?? ''),
            'latest_data_date' => $latestDataDate,
            'latest_fetched_at' => $latestFetchedAt,
            'record_count' => count($storedRows),
            'display_hotels' => $displayHotels,
            'display_hotel_count' => count($displayHotels),
            'display_summary' => $displaySummary,
            'rank_insights' => $displaySummary['rank_insights'] ?? [],
            'rank_health_rows' => $displaySummary['rank_health_rows'] ?? [],
            'top_summary_rows' => $displaySummary['top_summary_rows'] ?? [],
            'funnel_diagnosis' => $displaySummary['funnel_diagnosis'] ?? [],
            'source_notice' => $displaySummary['source_notice'] ?? '未返回字段保持缺失。',
            'readiness' => $readiness,
        ];
    }

    private function withMeituanStoredSelfMetricAnchor(array $storedRows, array $context): array
    {
        $existing = $this->normalizeMeituanSelfMetricValues($context['self_metric_values'] ?? $context['selfMetricValues'] ?? []);
        if (!empty($existing)) {
            $context['self_metric_values'] = $existing;
            return $context;
        }

        $targetPoiId = trim((string)($context['target_poi_id'] ?? $context['targetPoiId'] ?? ''));
        if ($targetPoiId === '') {
            return $context;
        }

        $values = [];
        foreach ($storedRows as $row) {
            if (!is_array($row) || empty($row['raw_data'])) {
                continue;
            }
            $raw = json_decode((string)$row['raw_data'], true);
            if (!is_array($raw)) {
                continue;
            }
            $poiId = trim((string)($row['hotel_id'] ?? $raw['poiId'] ?? $raw['poi_id'] ?? ''));
            if ($poiId !== $targetPoiId) {
                continue;
            }
            $storedValues = $this->normalizeMeituanSelfMetricValues($raw['selfMetricValues'] ?? $raw['self_metric_values'] ?? []);
            foreach ($storedValues as $field => $value) {
                if (!isset($values[$field]) || (float)$values[$field] <= 0) {
                    $values[$field] = $value;
                }
            }
        }

        if (!empty($values)) {
            $context['self_metric_values'] = $values;
            $context['self_metric_source_status'] = array_fill_keys(array_keys($values), 'meituan_persisted_self_anchor');
        }
        return $context;
    }

    private function emptyMeituanCompetitorSummaryPayload(array $context = []): array
    {
        return [
            'status' => 'empty',
            'data_status' => 'missing',
            'message' => (string)($context['message'] ?? '未找到美团竞对榜单入库数据'),
            'source' => 'online_daily_data',
            'system_hotel_id' => $context['system_hotel_id'] ?? null,
            'hotel_id' => (string)($context['hotel_id'] ?? ''),
            'target_poi_id' => (string)($context['target_poi_id'] ?? ''),
            'latest_data_date' => (string)($context['latest_data_date'] ?? $context['data_date'] ?? ''),
            'latest_fetched_at' => (string)($context['latest_fetched_at'] ?? ''),
            'record_count' => (int)($context['record_count'] ?? 0),
            'display_hotels' => [],
            'display_hotel_count' => 0,
            'display_summary' => $this->emptyMeituanBusinessDisplaySummary(),
            'rank_insights' => [],
            'rank_health_rows' => [],
            'top_summary_rows' => [],
            'funnel_diagnosis' => $this->emptyMeituanBusinessDisplaySummary()['funnel_diagnosis'] ?? [],
            'source_notice' => '未找到美团竞对榜单入库数据；不使用订单、客人、房态或房源映射推断。',
            'readiness' => $this->buildMeituanCompetitorSummaryReadiness([], $context, false),
        ];
    }

    private function buildMeituanCompetitorSummaryReadiness(array $displayHotels, array $context = [], bool $hasRows = true, array $displaySummary = []): array
    {
        $targetPoiId = trim((string)($context['target_poi_id'] ?? ''));
        $systemHotelId = (int)($context['system_hotel_id'] ?? 0);
        if (!$hasRows || empty($displayHotels)) {
            return [
                'status' => 'missing',
                'label' => '待同步',
                'detail' => '未找到美团竞对榜单入库数据',
                'next_action' => '先同步美团竞对榜单',
                'system_hotel_id' => $systemHotelId,
                'target_poi_id' => $targetPoiId,
            ];
        }

        if ($targetPoiId === '') {
            return [
                'status' => 'attention',
                'label' => '缺POI标识',
                'detail' => '榜单有数据，但当前系统酒店未绑定美团POI/Store标识，无法确认本店行',
                'next_action' => '在酒店管理补充美团 POI ID / Store ID',
                'system_hotel_id' => $systemHotelId,
                'target_poi_id' => $targetPoiId,
            ];
        }

        $selfRows = array_values(array_filter($displayHotels, static fn(array $row): bool => !empty($row['isSelf'])));
        if (empty($selfRows)) {
            return [
                'status' => 'attention',
                'label' => '缺本店行',
                'detail' => '榜单已入库，但未命中当前门店POI，不能计算本店第几和前一名差距',
                'next_action' => '核对美团 POI ID 与榜单返回门店是否一致',
                'system_hotel_id' => $systemHotelId,
                'target_poi_id' => $targetPoiId,
            ];
        }

        $rankHealthRows = is_array($displaySummary['rank_health_rows'] ?? null) ? $displaySummary['rank_health_rows'] : [];
        $rankHealthTotal = count($rankHealthRows);
        $rankHealthReady = count(array_filter($rankHealthRows, static fn(array $row): bool => ($row['status'] ?? '') === 'ok'));
        if ($rankHealthTotal > 0 && $rankHealthReady < $rankHealthTotal) {
            return [
                'status' => 'attention',
                'label' => '榜单待补齐',
                'detail' => '已命中本店POI，但美团榜单维度未完整返回，快捷摘要只可做部分参考',
                'next_action' => '补采美团入住、销售、流量、转化榜后再复核摘要',
                'system_hotel_id' => $systemHotelId,
                'target_poi_id' => $targetPoiId,
            ];
        }

        return [
            'status' => 'ok',
            'label' => '可用于快捷判断',
            'detail' => '已命中本店POI，可展示本店排名、TOP1、差距、VIP/平台标签和榜单升降',
            'next_action' => '进入美团榜单复核明细',
            'system_hotel_id' => $systemHotelId,
            'target_poi_id' => $targetPoiId,
        ];
    }

    private function meituanStoredRankRowToDisplayRow(array $row, array $context = []): array
    {
        $raw = [];
        if (!empty($row['raw_data'])) {
            $decoded = json_decode((string)$row['raw_data'], true);
            if (is_array($decoded)) {
                $raw = $decoded;
            }
        }

        $poiId = (string)($row['hotel_id'] ?? $raw['poiId'] ?? $raw['poi_id'] ?? $raw['hotelId'] ?? '');
        $hotelName = (string)($row['hotel_name'] ?? $raw['poiName'] ?? $raw['hotelName'] ?? $raw['name'] ?? '');
        if ($poiId === '' && $hotelName === '') {
            return [];
        }

        $rankType = (string)($raw['rankType'] ?? $raw['rank_type'] ?? '');
        $dimName = (string)($row['dimension'] ?? $raw['dimension'] ?? $raw['_dimName'] ?? '');
        $metricName = (string)($raw['aiMetricName'] ?? $raw['ai_metric_name'] ?? $raw['_aiMetricName'] ?? '');
        $rankType = $this->resolveMeituanRankType($rankType, $dimName, $metricName);
        $metricField = $this->meituanStoredRankMetricField($row, $raw, $dimName, $metricName, $rankType);
        $value = $this->nullableNumberFromKeys($raw, ['dataValue', 'data_value', 'value', 'metricValue']);
        if ($value === null && (float)($row['data_value'] ?? 0) > 0) {
            $value = (float)$row['data_value'];
        }
        if ($value === null && in_array($metricField, ['roomRevenue', 'sales'], true) && (float)($row['amount'] ?? 0) > 0) {
            $value = (float)$row['amount'];
        }
        if ($value === null && in_array($metricField, ['roomNights', 'salesRoomNights'], true) && (float)($row['quantity'] ?? 0) > 0) {
            $value = (float)$row['quantity'];
        }
        $rankPercent = $this->meituanRankPercentValue($raw);
        $rank = (int)$this->numberFromKeys($raw, ['rank', 'ranking'], 0);
        $dateRange = (string)($raw['dateRange'] ?? $raw['date_range'] ?? $context['date_range'] ?? '');
        $platformTagInfo = $this->extractMeituanPlatformTagInfo($raw);
        if ($platformTagInfo['status'] === 'not_returned' && isset($raw['platformTagStatus'])) {
            $platformTagInfo['status'] = (string)$raw['platformTagStatus'];
        }
        $sourceLabel = $value !== null ? '美团榜单入库' : ($rankPercent !== null ? '美团仅返回百分比' : '美团榜单未返回数值');

        $displayRow = [
            'poiId' => $poiId,
            'hotelName' => $hotelName,
            'rank' => $rank,
            'rankType' => $rankType,
            'dateRange' => $dateRange,
            'platformTags' => $platformTagInfo['tags'],
            'platformTagStatus' => $platformTagInfo['status'],
            'metricSourceStatus' => [],
            'metricRankPercent' => [],
            'metricDerived' => [],
            'rankHistory' => $rank > 0 ? [[
                'dateRange' => $dateRange,
                'dateRangeLabel' => $this->meituanDateRangeLabel($dateRange),
                'rankType' => $rankType,
                'rankTypeLabel' => $this->meituanRankTypeLabel($rankType),
                'metric' => $metricField,
                'metricLabel' => $dimName !== '' ? $dimName : ($metricName !== '' ? $metricName : '平台排名'),
                'rank' => $rank,
                'value' => $value ?? 0.0,
                'percent' => $rankPercent,
                'sourceLabel' => $sourceLabel,
            ]] : [],
        ];
        if ($metricField !== '') {
            if ($value !== null) {
                $displayRow[$metricField] = $value;
                $displayRow['metricSourceStatus'][$metricField] = '美团榜单入库';
            } elseif ($rankPercent !== null) {
                $displayRow['metricSourceStatus'][$metricField] = '美团仅返回百分比';
            }
            if ($rankPercent !== null) {
                $displayRow['metricRankPercent'][$metricField] = $rankPercent;
            }
        }
        if ((string)($context['target_poi_id'] ?? '') !== '' && $poiId === (string)$context['target_poi_id']) {
            $displayRow['isSelf'] = true;
        }
        return $displayRow;
    }

    private function meituanStoredRankMetricField(array $row, array $raw, string $dimName, string $metricName, string $rankType): string
    {
        $metricField = $this->classifyMeituanBusinessDisplayMetric($dimName, $metricName, $rankType);
        if ($metricField !== '') {
            return $metricField;
        }

        $amount = (float)($row['amount'] ?? 0);
        $quantity = (float)($row['quantity'] ?? 0);
        if ($amount > 0) {
            return $rankType === 'P_XS' ? 'sales' : 'roomRevenue';
        }
        if ($quantity > 0) {
            return $rankType === 'P_XS' ? 'salesRoomNights' : 'roomNights';
        }

        $combined = strtoupper($dimName . '|' . $metricName . '|' . implode('|', array_keys($raw)));
        if ($rankType === 'P_LL') {
            return str_contains($combined, 'VIEW') || str_contains($combined, '浏览') ? 'views' : 'exposure';
        }
        if ($rankType === 'P_ZH') {
            return str_contains($combined, 'PAY') || str_contains($combined, '支付') ? 'payConversion' : 'viewConversion';
        }
        return '';
    }

    private function resolveMeituanRankType(string $rankType, string $dimName = '', string $metricName = ''): string
    {
        $rankType = strtoupper(trim($rankType));
        if (in_array($rankType, ['P_RZ', 'P_XS', 'P_LL', 'P_ZH'], true)) {
            return $rankType;
        }

        $combined = strtoupper($dimName . '|' . $metricName);
        if (str_contains($combined, 'P_XS')) {
            return 'P_XS';
        }
        if (str_contains($combined, 'P_RZ') || str_contains($combined, 'NIGHT_COUNT') || str_contains($combined, '入住') || str_contains($combined, '房费')) {
            return 'P_RZ';
        }
        if (str_contains($combined, 'P_XS') || str_contains($combined, '销售')) {
            return 'P_XS';
        }
        if (str_contains($combined, 'P_ZH') || str_contains($combined, '转化') || str_contains($combined, '支付') || str_contains($combined, 'CONVERSION')) {
            return 'P_ZH';
        }
        if (str_contains($combined, 'P_LL') || str_contains($combined, '曝光') || str_contains($combined, '浏览') || str_contains($combined, '流量') || str_contains($combined, '访客') || str_contains($combined, 'VIEW') || str_contains($combined, 'EXPOSURE')) {
            return 'P_LL';
        }

        return '';
    }

    private function applyMeituanCompetitorStoredScope($query, array $columns): void
    {
        if (isset($columns['source'], $columns['platform'])) {
            $query->where(function ($q) {
                $q->where('source', 'meituan')->whereOr('platform', 'Meituan');
            });
        } elseif (isset($columns['source'])) {
            $query->where('source', 'meituan');
        } elseif (isset($columns['platform'])) {
            $query->where('platform', 'Meituan');
        }

        if (isset($columns['data_type'])) {
            $query->where('data_type', 'peer_rank');
        }
    }

    private function applyMeituanCompetitorUserScope($query, $currentUser, array $columns): void
    {
        if (!$currentUser || $currentUser->isSuperAdmin()) {
            return;
        }
        $permittedHotelIds = $currentUser->getPermittedHotelIds();
        if (empty($permittedHotelIds) || !isset($columns['system_hotel_id'])) {
            $query->where('id', 0);
            return;
        }
        $query->whereIn('system_hotel_id', $permittedHotelIds);
    }

    private function applyMeituanCompetitorLatestBatchScope($query, array $latest, string $hotelId, array $columns): void
    {
        if ($hotelId === '' && isset($columns['system_hotel_id'])) {
            if (($latest['system_hotel_id'] ?? null) !== null && (string)($latest['system_hotel_id'] ?? '') !== '') {
                $query->where('system_hotel_id', (int)$latest['system_hotel_id']);
            } else {
                $query->whereNull('system_hotel_id');
            }
        }

        if (isset($columns['sync_task_id']) && (int)($latest['sync_task_id'] ?? 0) > 0) {
            $query->where('sync_task_id', (int)$latest['sync_task_id']);
            return;
        }

        if (isset($columns['source_trace_id']) && trim((string)($latest['source_trace_id'] ?? '')) !== '') {
            $query->where('source_trace_id', trim((string)$latest['source_trace_id']));
            return;
        }

        // Meituan ranking modules are written as adjacent batches for one
        // hotel/date. Use a short fetch-time window so the four rank modules
        // stay together without mixing older runs from the same data_date.
        foreach (['update_time', 'create_time'] as $column) {
            if (!isset($columns[$column]) || empty($latest[$column])) {
                continue;
            }
            $latestTime = (string)$latest[$column];
            $latestTimestamp = strtotime($latestTime);
            if ($latestTimestamp === false) {
                $query->where($column, $latestTime);
                return;
            }
            $query->whereBetween($column, [
                date('Y-m-d H:i:s', $latestTimestamp - self::MEITUAN_COMPETITOR_BATCH_WINDOW_SECONDS),
                $latestTime,
            ]);
            return;
        }
    }

    private function onlineDailySummaryFieldList(array $columns): string
    {
        $fields = [];
        foreach (['id', 'system_hotel_id', 'hotel_id', 'hotel_name', 'data_date', 'data_value', 'amount', 'quantity', 'source', 'platform', 'data_type', 'dimension', 'raw_data', 'create_time', 'update_time', 'sync_task_id', 'source_trace_id'] as $field) {
            if (isset($columns[$field])) {
                $fields[] = $field;
            }
        }
        return empty($fields) ? '*' : implode(',', $fields);
    }

    private function resolveMeituanTargetPoiIdForSystemHotel(int $systemHotelId, $currentUser = null): string
    {
        if ($systemHotelId <= 0) {
            return '';
        }
        $configList = $this->getStoredMeituanConfigList();
        $effectiveUser = $currentUser ?? $this->currentUser;
        if ($effectiveUser) {
            $configList = $this->filterOtaConfigListForUser($configList, $effectiveUser);
        }
        foreach ($configList as $config) {
            $configHotelId = trim((string)($config['hotel_id'] ?? $config['system_hotel_id'] ?? ''));
            if ($configHotelId !== '' && (string)$systemHotelId === $configHotelId) {
                return trim((string)($config['poi_id'] ?? $config['poiId'] ?? $config['store_id'] ?? $config['storeId'] ?? ''));
            }
        }
        return '';
    }

    private function isCtripBusinessDisplayRow(array $row): bool
    {
        return array_key_exists('hotelId', $row)
            && array_key_exists('amount', $row)
            && array_key_exists('quantity', $row);
    }

    private function isMeituanBusinessDisplayRow(array $row): bool
    {
        return array_key_exists('poiId', $row)
            && array_key_exists('roomNights', $row)
            && array_key_exists('roomRevenue', $row);
    }

    private function buildCtripBusinessFingerprint($responseData): string
    {
        $dataList = $this->extractCtripBusinessDataList($responseData);
        if (empty($dataList)) {
            return '';
        }

        $rows = [];
        foreach ($dataList as $item) {
            if (!is_array($item)) {
                continue;
            }
            $hotelId = (string)($item['hotelId'] ?? $item['hotel_id'] ?? $item['HotelId'] ?? $item['hotelID'] ?? '');
            if ($hotelId === '') {
                continue;
            }
            $rows[] = [
                'hotel_id' => $hotelId,
                'hotel_name' => (string)($item['hotelName'] ?? $item['hotel_name'] ?? $item['HotelName'] ?? $item['name'] ?? ''),
                'amount' => (float)($item['amount'] ?? $item['Amount'] ?? $item['totalAmount'] ?? $item['total_amount'] ?? $item['saleAmount'] ?? 0),
                'quantity' => (int)($item['quantity'] ?? $item['Quantity'] ?? $item['roomNights'] ?? $item['room_nights'] ?? $item['checkOutQuantity'] ?? 0),
                'book_order_num' => (int)($item['bookOrderNum'] ?? $item['book_order_num'] ?? $item['orderCount'] ?? $item['order_count'] ?? 0),
                'comment_score' => (float)($item['commentScore'] ?? $item['comment_score'] ?? $item['score'] ?? $item['avgScore'] ?? 0),
                'qunar_comment_score' => (float)($item['qunarCommentScore'] ?? $item['qunar_comment_score'] ?? $item['qunarScore'] ?? 0),
                'total_detail_num' => (int)($item['totalDetailNum'] ?? $item['total_detail_num'] ?? $item['exposure'] ?? $item['exposureCount'] ?? $item['pv'] ?? $item['pageView'] ?? $item['viewCount'] ?? $item['detailVisitors'] ?? 0),
                'convertion_rate' => (float)($item['convertionRate'] ?? $item['convertion_rate'] ?? $item['conversionRate'] ?? 0),
                'qunar_detail_visitors' => (int)($item['qunarDetailVisitors'] ?? $item['qunar_detail_visitors'] ?? $item['views'] ?? $item['uv'] ?? $item['visitorCount'] ?? $item['detailUv'] ?? 0),
                'qunar_detail_cr' => (float)($item['qunarDetailCR'] ?? $item['qunar_detail_cr'] ?? $item['qunarDetailConversionRate'] ?? 0),
            ];
        }

        if (empty($rows)) {
            return '';
        }

        usort($rows, static fn($a, $b) => strcmp($a['hotel_id'], $b['hotel_id']));
        return sha1(json_encode($rows, JSON_UNESCAPED_UNICODE));
    }

    private function extractCtripResponseDates($data): array
    {
        $dates = [];
        $this->collectCtripResponseDates($data, $dates);
        return array_values(array_unique($dates));
    }

    private function collectCtripResponseDates($data, array &$dates): void
    {
        if (!is_array($data)) {
            return;
        }

        $dateKeys = ['dataDate', 'date', 'data_date', 'statDate', 'stat_date', 'bizDate', 'businessDate', 'reportDate'];
        foreach ($data as $key => $value) {
            if (in_array((string)$key, $dateKeys, true)) {
                $date = $this->normalizeCtripDate($value);
                if ($date !== null) {
                    $dates[] = $date;
                }
            }
            if (is_array($value)) {
                $this->collectCtripResponseDates($value, $dates);
            }
        }
    }

    private function normalizeCtripDate($value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string)$value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $matches)) {
            return "{$matches[1]}-{$matches[2]}-{$matches[3]}";
        }
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $matches)) {
            return "{$matches[1]}-{$matches[2]}-{$matches[3]}";
        }

        return null;
    }

    /**
     * 递归提取酒店数据
     */
    private function extractHotelData($data): array
    {
        $result = [];

        if (!is_array($data)) {
            return $result;
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                // 检查是否是酒店数据项
                if (isset($value['hotelId']) || isset($value['hotel_id']) || isset($value['HotelId'])) {
                    $result[] = $value;
                } elseif (isset($value[0]) && is_array($value[0])) {
                    // 递归查找
                    $result = array_merge($result, $this->extractHotelData($value));
                }
            }
        }

        return $result;
    }

    /**
     * 获取线上数据 - 自定义URL
     */
}
