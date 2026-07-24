<?php
declare(strict_types=1);

namespace app\controller\concern;

trait CtripOverviewRequestConcern
{
    private function normalizeCtripOverviewRequestUrls($value): array
    {
        if (is_array($value)) {
            $items = $value;
        } else {
            $items = preg_split('/[\r\n,]+/', (string)$value) ?: [];
        }

        $urls = [];
        foreach ($items as $item) {
            $url = trim((string)$item);
            if ($url !== '') {
                $urls[] = $url;
            }
        }
        return array_values(array_unique($urls));
    }

    private function isCtripOverviewApiUrl(string $url): bool
    {
        $normalized = strtolower(trim($url));
        foreach ($this->ctripOverviewApiKeywords() as $keyword) {
            if (str_contains($normalized, strtolower($keyword))) {
                return true;
            }
        }
        return false;
    }

    private function ctripOverviewApiKeywords(): array
    {
        return [
            'getDayReportRealTimeDate',
            'fetchMarketOverViewV2',
            'getDayReportFlowCompete',
            'getDayReportServerQuantity',
            'fetchCurrentHotelSeqInfoV1',
            'fetchVisitorTitleV2',
            'fetchCapacityOverViewV4',
            'queryFlowTransforNewV1',
            'queryFlowTransforNew',
            'queryScanFlowDetailsV2',
            'queryHomePageRealTimeData',
            'getDayReportCompeteHotelReport',
            'getFlowData',
            'getTrafficData',
            'getStatData',
            'getReportSuggestV1',
            'getCompeteHotelReportV1',
            'getHotWordsV1',
            'getHotHotelsV1',
            'getFlowHotelsV1',
            'getHotRoomsV1',
            'getUserBehaviorV1',
            'getUserBehavorV1',
            'getTrafficReportV1',
            'getWeekSuggestionV1',
            'getLastWeekReportV1',
        ];
    }

    private function buildCtripOverviewRequestPayload(array $payload, string $hotelId, string $dataDate): array
    {
        foreach ([
            'dataDate' => $dataDate,
            'date' => $dataDate,
            'startDate' => $dataDate,
            'endDate' => $dataDate,
            'statDate' => $dataDate,
            'bizDate' => $dataDate,
        ] as $key => $value) {
            if (!array_key_exists($key, $payload) || $payload[$key] === '' || $payload[$key] === null) {
                $payload[$key] = $value;
            }
        }
        if ($hotelId !== '') {
            foreach (['hotelId', 'nodeId', 'masterHotelId'] as $key) {
                if (!array_key_exists($key, $payload) || $payload[$key] === '' || $payload[$key] === null) {
                    $payload[$key] = $hotelId;
                }
            }
        }
        return $payload;
    }

    private function sendCtripOverviewRequest(string $url, array $payload, string $cookies, string $method, string $spidertoken = ''): array
    {
        $emptyResult = [
            'http_code' => 0,
            'raw_response' => '',
            'decoded_data' => null,
            'error' => '',
        ];

        if (!function_exists('curl_init')) {
            return array_merge($emptyResult, ['error' => '服务器未启用 cURL，无法请求携程今日概况接口']);
        }

        $method = strtoupper($method) === 'GET' ? 'GET' : 'POST';
        $requestUrl = $url;
        $jsonPayload = '';
        if ($method === 'GET') {
            $query = http_build_query($payload);
            if ($query !== '') {
                $requestUrl .= (str_contains($requestUrl, '?') ? '&' : '?') . $query;
            }
        } else {
            $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
            if ($jsonPayload === false) {
                return array_merge($emptyResult, ['error' => '请求 Body JSON 编码失败: ' . json_last_error_msg()]);
            }
        }

        $headers = [
            'Accept: application/json, text/javascript, */*; q=0.01',
            'Accept-Encoding: gzip, deflate, br',
            'Accept-Language: zh-CN,zh;q=0.9',
            'Content-Type: application/json',
            'Origin: https://ebooking.ctrip.com',
            'Referer: https://ebooking.ctrip.com/datacenter/inland/businessreport/outline?microJump=true',
            'X-Requested-With: XMLHttpRequest',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'Cookie: ' . $cookies,
        ];
        if ($spidertoken !== '') {
            $headers[] = 'spidertoken: ' . $spidertoken;
        }

        $ch = curl_init($requestUrl);
        $options = [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING => '',
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => $this->shouldVerifyOtaSsl(),
            CURLOPT_SSL_VERIFYHOST => $this->shouldVerifyOtaSsl() ? 2 : 0,
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $jsonPayload;
        }
        curl_setopt_array($ch, $options);

        $rawResponse = curl_exec($ch);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($rawResponse === false) {
            return [
                'http_code' => $httpCode,
                'raw_response' => '',
                'decoded_data' => null,
                'error' => '请求携程今日概况接口失败: ' . ($curlError ?: 'cURL 错误 ' . $curlErrno),
            ];
        }

        $result = [
            'http_code' => $httpCode,
            'raw_response' => $rawResponse,
            'decoded_data' => null,
            'error' => '',
        ];

        if ($httpCode !== 200) {
            $result['error'] = in_array($httpCode, [301, 302], true)
                ? 'Cookie已失效，请重新登录携程 eBooking 后复制 Cookie'
                : '携程今日概况接口 HTTP 错误: ' . $httpCode;
            return $result;
        }
        if (preg_match('/^\s*<!DOCTYPE|^\s*<html/i', $rawResponse)) {
            $result['error'] = '携程今日概况接口返回页面而非 JSON，请检查 Cookie / Request URL / Payload';
            return $result;
        }

        $decodedData = json_decode($rawResponse, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $result['error'] = '携程今日概况接口 JSON 解析失败，请检查 Request URL / Payload';
            return $result;
        }

        $result['decoded_data'] = $decodedData;
        return $result;
    }

    private function inferCtripOverviewHotelIdFromResponses(array $responses, string $fallback = ''): string
    {
        foreach ($responses as $response) {
            if (!is_array($response)) {
                continue;
            }
            $data = $response['data'] ?? $response['body'] ?? $response['json'] ?? [];
            if (!is_array($data)) {
                continue;
            }
            $payload = $data['data'] ?? $data;
            $directHotelId = $this->firstMeituanValue(is_array($payload) ? $payload : [], [
                'masterhotelid',
                'masterHotelId',
                'hotelId',
                'hotel_id',
                'nodeId',
                'node_id',
            ], '');
            if ($directHotelId !== '' && is_numeric($directHotelId) && (int)$directHotelId > 0) {
                return (string)$directHotelId;
            }
            foreach ($this->flattenCtripOverviewCandidateRows($payload) as $row) {
                $hotelId = $this->firstMeituanValue($row, ['hotelId', 'hotel_id', 'masterHotelId', 'masterhotelid'], '');
                $hotelName = trim((string)($row['hotelName'] ?? $row['hotel_name'] ?? ''));
                if ($hotelName === '我的酒店' && $hotelId !== '' && is_numeric($hotelId) && (int)$hotelId > 0) {
                    return (string)$hotelId;
                }
            }
        }

        foreach ($responses as $response) {
            if (!is_array($response)) {
                continue;
            }
            $data = $response['data'] ?? $response['body'] ?? $response['json'] ?? [];
            $payload = is_array($data) ? ($data['data'] ?? $data) : [];
            foreach ($this->flattenCtripOverviewCandidateRows($payload) as $row) {
                $hotelId = $this->firstMeituanValue($row, ['hotelId', 'hotel_id', 'masterHotelId', 'masterhotelid'], '');
                if ($hotelId !== '' && is_numeric($hotelId) && (int)$hotelId > 0) {
                    return (string)$hotelId;
                }
            }
        }

        return $fallback;
    }
}
