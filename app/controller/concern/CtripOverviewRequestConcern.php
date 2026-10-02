<?php
declare(strict_types=1);

namespace app\controller\concern;

trait CtripOverviewRequestConcern
{
    /** The date-scoped task has one server-owned endpoint; legacy URL requests stay separate. */
    private function buildCtripFlowOverviewTask(array $request, array $storedConfig, int $systemHotelId): array
    {
        $hotelId = trim((string)($request['hotel_id'] ?? ''));
        $date = trim((string)($request['data_date'] ?? ''));
        $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $expectedIds = array_map('strval', $this->extractExpectedCtripPlatformHotelIds($storedConfig, $systemHotelId));
        if (($request['request_source'] ?? '') !== 'flow_overview'
            || $systemHotelId <= 0
            || (string)($request['system_hotel_id'] ?? '') !== (string)$systemHotelId
            || $this->otaConfigBoundSystemHotelId($storedConfig) !== $systemHotelId
            || !preg_match('/^[1-9][0-9]*$/D', $hotelId)
            || !in_array($hotelId, $expectedIds, true)
            || !$parsedDate || $parsedDate->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Ctrip flow overview hotel binding or date is invalid.');
        }
        return [
            'url' => 'https://ebooking.ctrip.com/datacenter/api/inland/marketanalysis/flowanalysis/queryFlowTransforNewV1?hostType=Ebooking',
            'method' => 'POST',
            'hotel_id' => $hotelId,
            'data_date' => $date,
            'payload' => $this->buildCtripOverviewRequestPayload(['platform' => 'Ctrip'], $hotelId, $date),
        ];
    }

    private function executeCtripFlowOverviewTask(array $task, array $credentialPayload, int $systemHotelId): \think\Response
    {
        $cookies = trim((string)($credentialPayload['cookies'] ?? $credentialPayload['cookie'] ?? ''));
        $auth = $credentialPayload['auth_data'] ?? $credentialPayload['authData'] ?? [];
        if (is_string($auth)) $auth = json_decode($auth, true) ?: [];
        $token = (string)($credentialPayload['spidertoken'] ?? $credentialPayload['spider_token']
            ?? (is_array($auth) ? ($auth['spidertoken'] ?? $auth['spider_token'] ?? $auth['token'] ?? '') : ''));
        $result = $cookies === '' ? ['error' => 'credential_missing']
            : $this->sendCtripOverviewRequest($task['url'], $task['payload'], $cookies, 'POST', $token);
        if (!empty($result['error']) || (int)($result['http_code'] ?? 0) !== 200) {
            return json(['code' => 502, 'message' => '携程流量概览请求失败，未取得当前酒店与日期的数据',
                'data' => ['status' => 'error', 'readback_verified' => false, 'saved_count' => 0]], 502);
        }

        $projection = \app\service\CtripOverviewSummaryService::projectFlowOverview(
            $result['decoded_data'] ?? [], $task['hotel_id'], $task['data_date']
        );
        $rows = $projection['rows'];
        $savedCount = $rows === [] ? 0 : $this->parseAndSaveTrafficData(
            $rows, $task['data_date'], $task['data_date'], 'ctrip', $systemHotelId,
            'ctrip', $task['hotel_id'], 'cookie_api', true
        );
        $verified = $rows !== [] && $savedCount === count($rows);
        $payload = [
            'request_source' => 'flow_overview', 'system_hotel_id' => $systemHotelId,
            'hotel_id' => $task['hotel_id'], 'platform' => 'ctrip', 'data_date' => $task['data_date'],
            'metric_scope' => 'ctrip_channel_funnel', 'source_method' => 'cookie_api',
            'collected_at' => date('Y-m-d H:i:s'), 'status' => $projection['status'],
            'data' => $rows, 'total' => count($rows), 'row_count' => count($rows),
            'saved_count' => $savedCount, 'readback_verified' => $verified,
            'persistence_status' => $verified ? 'readback_verified' : ($rows === [] ? 'no_parsed_rows' : 'readback_not_verified'),
            'counts' => ['overview' => count($rows)], 'metrics' => $projection['metrics'], 'gaps' => $projection['gaps'],
        ];
        if (!$verified) {
            $payload['status'] = $rows === [] ? $projection['status'] : 'error';
            $code = $rows === [] ? 422 : 500;
            return json(['code' => $code, 'message' => $rows === []
                ? '未取得当前酒店与日期的可保存流量事实，未返回指标不计零'
                : '携程流量概览已返回，但数据未全部通过保存回读，请重试', 'data' => $payload], $code);
        }
        return json(['code' => 200, 'message' => $projection['status'] === 'ready'
            ? '携程流量概览已获取并确认入库'
            : '携程流量概览已确认入库，部分指标未返回或不可计算', 'data' => $payload]);
    }

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
