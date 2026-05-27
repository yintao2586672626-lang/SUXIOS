<?php
declare(strict_types=1);

namespace app\controller;

use app\model\OperationLog;
use app\model\SystemConfig;
use app\model\User as UserModel;
use app\service\OtaTrafficUrlNormalizer;
use think\Response;
use think\facade\Db;

class OnlineData extends Base
{
    private function shouldVerifyOtaSsl(): bool
    {
        $value = env('OTA_SSL_VERIFY', true);
        if (is_bool($value)) {
            return $value;
        }

        return !in_array(strtolower(trim((string)$value)), ['0', 'false', 'no', 'off'], true);
    }

    private function shouldLogOtaDebug(): bool
    {
        $value = env('OTA_DEBUG_LOG', false);
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function buildStreamSslOptions(): array
    {
        $verify = $this->shouldVerifyOtaSsl();
        return [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
        ];
    }

    /**
     * 获取线上数据 - 携程ebooking接口
     */
    public function fetchCtrip(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $url = $this->request->post('url', 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportCompeteHotelReport');
        $nodeId = $this->request->post('node_id', '24588');
        $cookies = $this->request->post('cookies', '');
        $authDataStr = $this->request->post('auth_data', '');
        $startDate = $this->request->post('start_date', '');
        $endDate = $this->request->post('end_date', '');
        $autoSave = $this->request->post('auto_save', true);
        $systemHotelId = $this->resolveOnlineDataSystemHotelId($this->request->post('system_hotel_id', null));

        if (empty($cookies)) {
            return json(['code' => 400, 'message' => '请提供登录Cookies', 'data' => null]);
        }

        // 解析认证数据
        $authData = [];
        if (!empty($authDataStr)) {
            if (is_string($authDataStr)) {
                $authData = json_decode($authDataStr, true) ?: [];
            } elseif (is_array($authDataStr)) {
                $authData = $authDataStr;
            }
        }

        try {
            if (!$startDate || !$endDate) {
                $yesterday = date('Y-m-d', strtotime('-1 day'));
                $startDate = $yesterday;
                $endDate = $yesterday;
            }

            $startTimestamp = strtotime($startDate);
            $endTimestamp = strtotime($endDate);
            if ($startTimestamp === false || $endTimestamp === false || $startTimestamp > $endTimestamp) {
                return json(['code' => 400, 'message' => '日期范围无效', 'data' => null]);
            }

            $dateResults = [];
            $responseData = null;
            $rawResponse = '';
            $savedCount = 0;

            for ($timestamp = $startTimestamp; $timestamp <= $endTimestamp; $timestamp = strtotime('+1 day', $timestamp)) {
                $currentDate = date('Y-m-d', $timestamp);
                $postData = [
                    'nodeId' => $nodeId,
                    'startDate' => $currentDate,
                    'endDate' => $currentDate,
                ];

                // 发送请求
                $result = $this->sendHttpRequest($url, $postData, $cookies, $authData);

                if (!$result['success']) {
                    $this->recordCookieAlert('ctrip', 'fetch-ctrip', (string)($result['error'] ?? ''), $systemHotelId ? (int)$systemHotelId : null);
                    return json([
                        'code' => 500,
                        'message' => $currentDate . ' 请求失败: ' . ($result['error'] ?? '请求失败'),
                        'data' => ['raw_response' => $result['raw'] ?? '']
                    ]);
                }

                $dayResponseData = $result['data'];

                // 检查携程API返回的错误
                if (is_array($dayResponseData)) {
                    if (isset($dayResponseData['error'])) {
                        $errorMsg = $dayResponseData['error_description'] ?? $dayResponseData['error'];
                        return json([
                            'code' => 400,
                            'message' => $currentDate . ' 携程API错误: ' . $errorMsg,
                            'data' => ['raw_response' => $result['raw']]
                        ]);
                    }
                    if (isset($dayResponseData['code']) && $dayResponseData['code'] != 0 && $dayResponseData['code'] != 200) {
                        $errorMsg = $dayResponseData['message'] ?? $dayResponseData['msg'] ?? '未知错误';
                        return json([
                            'code' => 400,
                            'message' => $currentDate . ' 携程API返回错误: ' . $errorMsg,
                            'data' => ['raw_response' => $result['raw']]
                        ]);
                    }
                }

                $responseData = $dayResponseData;
                $rawResponse = $result['raw'];
                $dateResults[] = [
                    'date' => $currentDate,
                    'data' => $dayResponseData,
                    'saved_count' => 0,
                    'fingerprint' => $this->buildCtripBusinessFingerprint($dayResponseData),
                    'response_dates' => $this->extractCtripResponseDates($dayResponseData),
                ];
            }

            $uniqueFingerprints = array_values(array_unique(array_filter(array_column($dateResults, 'fingerprint'))));
            if ($startDate !== $endDate && count($uniqueFingerprints) === 1) {
                return json([
                    'code' => 422,
                    'message' => '携程多日请求返回了同一份经营数据，系统已取消保存，避免把昨天数据按天数写入。请改为单日获取，或确认携程后台该账号是否支持历史日期。',
                    'data' => [
                        'date_results' => $dateResults,
                        'saved_count' => 0,
                        'request_start_date' => $startDate,
                        'request_end_date' => $endDate,
                    ],
                ]);
            }

            $fetchedAt = date('Y-m-d H:i:s');
            foreach ($dateResults as &$dateResult) {
                if ($autoSave) {
                    $dateResult['saved_count'] = $this->parseAndSaveData(
                        $dateResult['data'],
                        $dateResult['date'],
                        $dateResult['date'],
                        $systemHotelId
                    );
                    $savedCount += $dateResult['saved_count'];
                }
            }
            unset($dateResult);

            $displayDataDate = $startDate === $endDate ? $startDate : $startDate . ' 至 ' . $endDate;
            $this->updateCtripLatestFetchStatus($systemHotelId, $fetchedAt, $displayDataDate, $savedCount);
            if ($this->currentUser && isset($this->currentUser->id)) {
                OperationLog::record('online_data', 'fetch_ctrip', "获取携程线上数据: {$savedCount}条", $this->currentUser->id, $systemHotelId);
            }

            return json([
                'code' => 200,
                'message' => '获取成功',
                'data' => [
                    'data' => $responseData,
                    'date_results' => $dateResults,
                    'raw_response' => $rawResponse,
                    'saved_count' => $savedCount,
                    'fetched_at' => $fetchedAt,
                    'request_start_date' => $startDate,
                    'request_end_date' => $endDate,
                ]
            ]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'message' => '请求异常: ' . $e->getMessage(), 'data' => null]);
        }
    }
    
    /**
     * 获取线上数据 - 美团ebooking接口
     * 支持竞对排名数据接口，支持时间维度选择
     */
    public function fetchMeituan(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');
        
        // 默认使用竞对排名数据接口
        $url = $this->request->post('url', 'https://eb.meituan.com/api/v1/ebooking/business/peer/rank/data/detail');
        $partnerId = $this->request->post('partner_id', '');
        $poiId = $this->request->post('poi_id', '');
        $rankType = $this->request->post('rank_type', 'P_RZ');
        $dataScope = $this->request->post('data_scope', 'vpoi');
        $cookies = $this->request->post('cookies', '');
        $authDataStr = $this->request->post('auth_data', '');
        $dateRange = $this->request->post('date_range', '1'); // 时间维度：0=今日实时，1=昨日，7=近7天，30=近30天
        $startDate = $this->request->post('start_date', '');
        $endDate = $this->request->post('end_date', '');
        $autoSave = $this->request->post('auto_save', true);
        $systemHotelId = $this->resolveOnlineDataSystemHotelId($this->request->post('system_hotel_id', null));
        
        if (empty($cookies)) {
            return $this->error('请提供登录Cookies');
        }
        
        // partnerId 和 poiId 是美团API必要参数
        if (empty($partnerId)) {
            return $this->error('请提供Partner ID（商家ID）');
        }
        if (empty($poiId)) {
            return $this->error('请提供POI ID（门店ID）');
        }
        
        // 解析认证数据 - 支持字符串或数组格式
        $authData = [];
        if (!empty($authDataStr)) {
            if (is_string($authDataStr)) {
                $authData = json_decode($authDataStr, true) ?: [];
            } elseif (is_array($authDataStr)) {
                $authData = $authDataStr;
            }
        }
        
        try {
            // 构建请求参数
            $params = [
                'dataScope' => $dataScope,
                'deviceType' => 1,
                'yodaReady' => 'h5',
                'csecplatform' => 4,
                'csecversion' => '4.2.0',
            ];
            
            if ($partnerId) {
                $params['partnerId'] = $partnerId;
            }
            if ($poiId) {
                $params['poiId'] = $poiId;
            }
            if ($rankType) {
                $params['rankType'] = $rankType;
            }
            
            // 根据 dateRange 参数计算日期范围
            $dateRange = intval($dateRange);
            if ($startDate && $endDate) {
                // 如果指定了日期范围，使用指定的
                $params['startDate'] = str_replace('-', '', $startDate);
                $params['endDate'] = str_replace('-', '', $endDate);
                $params['dateRange'] = 1;
            } else {
                // 根据 dateRange 计算日期
                switch ($dateRange) {
                    case 0: // 今日实时
                        $today = date('Ymd');
                        $params['startDate'] = $today;
                        $params['endDate'] = $today;
                        $params['dateRange'] = 0;
                        $startDate = date('Y-m-d');
                        break;
                    case 7: // 近7天
                        $params['startDate'] = date('Ymd', strtotime('-7 days'));
                        $params['endDate'] = date('Ymd');
                        $params['dateRange'] = 7;
                        $startDate = date('Y-m-d', strtotime('-7 days'));
                        break;
                    case 30: // 近30天
                        $params['startDate'] = date('Ymd', strtotime('-30 days'));
                        $params['endDate'] = date('Ymd');
                        $params['dateRange'] = 30;
                        $startDate = date('Y-m-d', strtotime('-30 days'));
                        break;
                    case 1: // 昨日（默认）
                    default:
                        $yesterday = date('Ymd', strtotime('-1 day'));
                        $params['startDate'] = $yesterday;
                        $params['endDate'] = $yesterday;
                        $params['dateRange'] = 1;
                        $startDate = date('Y-m-d', strtotime('-1 day'));
                        break;
                }
            }
            
            // 发送GET请求
            $result = $this->sendMeituanRequest($url, $params, $cookies, $authData);
            
            if (!$result['success']) {
                $this->recordCookieAlert('meituan', 'fetch-meituan', (string)($result['error'] ?? ''), $systemHotelId ? (int)$systemHotelId : null);
                return $this->error('请求失败: ' . $result['error']);
            }
            
            $responseData = $result['data'] ?? [];
            $savedCount = 0;
            
            if ($autoSave && is_array($responseData) && !empty($responseData)) {
                $savedCount = $this->parseAndSaveMeituanData($responseData, $startDate, $endDate, $systemHotelId ? (int)$systemHotelId : null);
            }
            
            OperationLog::record('online_data', 'fetch_meituan', '获取美团线上数据', $this->currentUser->id, $systemHotelId ? (int)$systemHotelId : null);
            
            // 确保所有数据都是有效的UTF-8编码
            $responseData = $this->ensureUtf8($responseData);
            $rawResponse = substr($this->ensureUtf8String($result['raw'] ?? ''), 0, 1000);
            
            // 直接构建响应数据并使用JSON_INVALID_UTF8_SUBSTITUTE处理无效字符
            $responseArray = [
                'code' => 200,
                'message' => '操作成功',
                'data' => [
                    'data' => $responseData,
                    'raw_response' => $rawResponse,
                    'saved_count' => $savedCount,
                ],
                'time' => time(),
            ];
            
            // 使用JSON_INVALID_UTF8_SUBSTITUTE标志处理无效UTF-8字符
            $jsonStr = json_encode($responseArray, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($jsonStr === false) {
                // 如果仍然失败，使用简化响应
                $jsonStr = json_encode([
                    'code' => 200,
                    'message' => '操作成功',
                    'data' => [
                        'data' => ['note' => '数据已保存，但包含特殊字符无法显示'],
                        'raw_response' => '',
                        'saved_count' => $savedCount,
                    ],
                    'time' => time(),
                ], JSON_UNESCAPED_UNICODE);
            }
            
            // 直接返回JSON字符串，绕过框架的json_encode
            return response($jsonStr, 200, ['Content-Type' => 'application/json']);
        } catch (\Throwable $e) {
            return $this->error('请求异常: ' . $e->getMessage());
        }
    }

    /**
     * 获取携程流量数据
     */
    public function fetchCtripTraffic(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $url = (string)$this->request->post('url', '');
        $platform = (string)$this->request->post('platform', 'Ctrip');
        $dateRange = (string)$this->request->post('date_range', 'yesterday');
        $spiderkey = trim((string)$this->request->post('spiderkey', ''));
        $cookies = trim((string)$this->request->post('cookies', ''));
        $startDate = (string)$this->request->post('start_date', '');
        $endDate = (string)$this->request->post('end_date', '');
        $autoSave = $this->request->post('auto_save', true);
        $systemHotelId = $this->resolveOnlineDataSystemHotelId($this->request->post('system_hotel_id', null));
        $extraParamsStr = (string)$this->request->post('extra_params', '');

        if ($cookies === '') {
            return $this->error('请提供携程 Cookie');
        }

        try {
            $extraParams = $this->parseJsonParams($extraParamsStr);
            $platform = ucfirst(strtolower($platform));
            if (!in_array($platform, ['Ctrip', 'Qunar'], true)) {
                return $this->error('platform 仅支持 Ctrip 或 Qunar');
            }

            if ($spiderkey === '' && !empty($extraParams['spiderkey'])) {
                $spiderkey = (string)$extraParams['spiderkey'];
            }

            [$startDate, $endDate] = $this->buildCtripTrafficDateRange($dateRange, $startDate, $endDate);
            $requestUrl = $this->normalizeCtripTrafficUrl($url);

            $postData = $extraParams;
            $postData['platform'] = $platform;
            $postData['startDate'] = $startDate;
            $postData['endDate'] = $endDate;
            $postData['fingerPrintKeys'] = $postData['fingerPrintKeys'] ?? '';
            $postData['spiderkey'] = $spiderkey;
            $postData['spiderVersion'] = $postData['spiderVersion'] ?? '2.0';

            $result = $this->sendCtripJsonRequest($requestUrl, $postData, $cookies);
            if (!empty($result['error'])) {
                $this->recordCookieAlert(strtolower($platform), 'fetch-ctrip-traffic', (string)$result['error'], $systemHotelId ? (int)$systemHotelId : null);
                return $this->error($result['error'], 400, [
                    'http_code' => $result['http_code'],
                    'raw_response' => $result['raw_response'],
                    'decoded_data' => $result['decoded_data'],
                ]);
            }

            $responseData = $result['decoded_data'];
            $apiError = $this->getCtripTrafficApiError($responseData);
            if ($apiError !== '') {
                $this->recordCookieAlert(strtolower($platform), 'fetch-ctrip-traffic', $apiError, $systemHotelId ? (int)$systemHotelId : null);
                return $this->error($apiError, 400, [
                    'http_code' => $result['http_code'],
                    'raw_response' => $result['raw_response'],
                    'decoded_data' => $responseData,
                ]);
            }

            $trafficRows = is_array($responseData) ? $this->extractCtripTrafficRows($responseData) : [];
            $savedCount = 0;
            if ($autoSave && is_array($responseData)) {
                $savedCount = $this->parseAndSaveTrafficData(
                    $responseData,
                    $startDate,
                    $endDate,
                    strtolower($platform),
                    $systemHotelId,
                    $platform
                );
            }
            $derivedAnalysis = $this->buildAppTrafficDerivedAnalysis($responseData);

            OperationLog::record('online_data', 'fetch_ctrip_traffic', '获取携程流量数据', $this->currentUser->id, $systemHotelId);

            return $this->success([
                'data' => $responseData,
                'decoded_data' => $responseData,
                'traffic_rows' => $trafficRows,
                'raw_response' => $result['raw_response'],
                'http_code' => $result['http_code'],
                'saved_count' => $savedCount,
                'platform' => $platform,
                'request_start_date' => $startDate,
                'request_end_date' => $endDate,
                'request_url' => $requestUrl,
                'derived_analysis' => $derivedAnalysis,
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            return $this->error('请求异常: ' . $e->getMessage());
        }
    }

    /**
     * 直接获取携程金字塔广告数据
     */
    public function fetchCtripAds(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $url = trim((string)$this->request->post('url', ''));
        $cookies = trim((string)$this->request->post('cookies', ''));
        $payloadJson = (string)$this->request->post('payload_json', (string)$this->request->post('extra_params', ''));
        $dateRange = (string)$this->request->post('date_range', 'yesterday');
        $startDate = (string)$this->request->post('start_date', '');
        $endDate = (string)$this->request->post('end_date', '');
        $apiType = (string)$this->request->post('api_type', 'campaign_report');
        $method = strtoupper(trim((string)$this->request->post('method', 'POST'))) ?: 'POST';
        $autoSave = $this->request->post('auto_save', true);
        $systemHotelId = $this->resolveOnlineDataSystemHotelId($this->request->post('system_hotel_id', null));
        $hotelId = trim((string)$this->request->post('hotel_id', ''));
        $hotelName = trim((string)$this->request->post('hotel_name', ''));

        if ($cookies === '') {
            return $this->error('请提供携程 Cookie');
        }
        if ($url === '') {
            return $this->error('请在广告配置中填写金字塔广告接口 URL（Network 中 pyramidad / promotion 的 XHR 或 fetch 请求地址）');
        }
        if (preg_match('#/toolcenter/cpc/pyramid(?:\?|$)#i', $url)) {
            return $this->error('当前填写的是金字塔广告页面地址，不是数据接口。请填写 Network 中 pyramidad / promotion 的 JSON 请求 URL');
        }
        if (!$this->isCtripAdsApiUrl($url)) {
            return $this->error('金字塔广告接口 URL 必须来自 Network 中 pyramidad / promotion 的 XHR 或 fetch 请求，不能使用竞品日报等 datacenter 接口');
        }
        if (!in_array($method, ['POST', 'GET'], true)) {
            return $this->error('广告接口请求方式仅支持 POST 或 GET');
        }

        try {
            [$startDate, $endDate] = $this->buildCtripAdsDateRange($dateRange, $startDate, $endDate);
            $payload = $this->parseJsonParams($payloadJson);
            $payload = $this->buildCtripAdsDirectPayload($payload, $startDate, $endDate, $apiType);
            $campaignId = trim((string)$this->request->post('campaign_id', ''));
            if ($campaignId !== '') {
                $payload['campaignId'] = $payload['campaignId'] ?? $campaignId;
                $payload['campaign_id'] = $payload['campaign_id'] ?? $campaignId;
            }

            $result = $this->sendCtripAdsRequest($url, $payload, $cookies, $method);
            if (!empty($result['error'])) {
                $this->recordCookieAlert('ctrip', 'fetch-ctrip-ads', (string)$result['error'], $systemHotelId ? (int)$systemHotelId : null);
                return $this->error($result['error'], 400, [
                    'http_code' => $result['http_code'],
                    'raw_response' => $result['raw_response'],
                    'decoded_data' => $result['decoded_data'],
                    'request_url' => $result['request_url'] ?? $url,
                    'request_payload' => $payload,
                ]);
            }

            $responseData = is_array($result['decoded_data']) ? $result['decoded_data'] : [];
            $capturedPayload = [
                'hotel_id' => $hotelId,
                'hotel_name' => $hotelName,
                'captured_at' => date('Y-m-d H:i:s'),
                'request_start_date' => $startDate,
                'request_end_date' => $endDate,
                'responses' => [[
                    'url' => $result['request_url'] ?? $url,
                    'section' => 'ads',
                    'data' => $responseData,
                ]],
            ];
            $ads = $this->extractCtripCapturedAds($capturedPayload);
            $rows = $this->buildCtripCapturedAdRows($ads, $capturedPayload, $systemHotelId);
            $savedCount = 0;
            if ($autoSave) {
                $savedCount = $this->saveCtripCapturedAdRows($rows);
            }

            if ($this->currentUser && isset($this->currentUser->id)) {
                OperationLog::record('online_data', 'fetch_ctrip_ads', "获取携程广告数据: {$savedCount}条", $this->currentUser->id, $systemHotelId);
            }

            return $this->success([
                'data' => $ads,
                'rows' => $rows,
                'metrics' => $this->summarizeCtripAdRows($rows),
                'total' => count($ads),
                'row_count' => count($rows),
                'saved_count' => $savedCount,
                'decoded_data' => $responseData,
                'raw_response' => $result['raw_response'],
                'http_code' => $result['http_code'],
                'request_url' => $result['request_url'] ?? $url,
                'request_method' => $method,
                'request_payload' => $payload,
                'request_start_date' => $startDate,
                'request_end_date' => $endDate,
            ], '获取成功');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            return $this->error('请求异常: ' . $e->getMessage());
        }
    }

    private function buildAppTrafficDerivedAnalysis($responseData): array
    {
        $rows = $this->extractCtripTrafficRows($responseData);
        if (empty($rows)) {
            return $this->emptyAppTrafficDerivedAnalysis();
        }

        $daily = [];
        foreach ($rows as $row) {
            $normalized = $this->normalizeAppTrafficRow(is_array($row) ? $row : []);
            if ($normalized === null) {
                continue;
            }
            $date = $normalized['date'];
            if (!isset($daily[$date])) {
                $daily[$date] = [
                    'date' => $date,
                    'self' => $this->emptyAppTrafficMetrics(),
                    'competitor' => $this->emptyAppTrafficMetrics(),
                ];
            }
            $daily[$date][$normalized['compare_type']] = $normalized['metrics'];
        }

        if (empty($daily)) {
            return $this->emptyAppTrafficDerivedAnalysis();
        }

        ksort($daily);
        $summaryBase = [
            'date' => '',
            'self' => $this->emptyAppTrafficMetrics(),
            'competitor' => $this->emptyAppTrafficMetrics(),
        ];
        foreach ($daily as $item) {
            foreach (['self', 'competitor'] as $type) {
                foreach (['exposure', 'detail_visitors', 'order_visitors', 'submit_users'] as $key) {
                    $summaryBase[$type][$key] += $item[$type][$key];
                }
            }
        }

        $summary = $this->calculateAppTrafficDerivedMetrics($summaryBase);
        $derivedRows = [];
        foreach ($daily as $item) {
            $derivedRows[] = $this->calculateAppTrafficDerivedMetrics($item);
        }

        return [
            'summary' => $summary,
            'rows' => $derivedRows,
            'diagnosis' => $summary['diagnosis'],
            'main_problem_stage' => $summary['main_problem_stage'],
            'recommendations' => $summary['recommendations'],
        ];
    }

    private function emptyAppTrafficDerivedAnalysis(): array
    {
        $base = $this->calculateAppTrafficDerivedMetrics([
            'date' => '',
            'self' => $this->emptyAppTrafficMetrics(),
            'competitor' => $this->emptyAppTrafficMetrics(),
        ]);
        return [
            'summary' => $base,
            'rows' => [],
            'diagnosis' => $base['diagnosis'],
            'main_problem_stage' => $base['main_problem_stage'],
            'recommendations' => $base['recommendations'],
        ];
    }

    private function emptyAppTrafficMetrics(): array
    {
        return [
            'exposure' => 0.0,
            'detail_visitors' => 0.0,
            'order_visitors' => 0.0,
            'submit_users' => 0.0,
            'exposure_rate' => 0.0,
            'order_rate' => 0.0,
            'deal_rate' => 0.0,
        ];
    }

    private function normalizeAppTrafficRow(array $row): ?array
    {
        $date = $row['date'] ?? $row['dataDate'] ?? $row['statDate'] ?? $row['stat_date'] ?? $row['data_date'] ?? $row['reportDate'] ?? $row['day'] ?? '';
        if ($date === '' || strtotime((string)$date) === false) {
            return null;
        }

        $compareType = $row['compareType'] ?? $row['compare_type'] ?? null;
        if ($compareType === null) {
            $hotelId = $row['hotelId'] ?? $row['hotel_id'] ?? $row['HotelId'] ?? $row['hotelID'] ?? $row['nodeId'] ?? $row['node_id'] ?? null;
            $compareText = strtolower((string)($row['type'] ?? $row['rankType'] ?? $row['name'] ?? $row['hotelName'] ?? ''));
            $compareType = (str_contains($compareText, 'competitor') || str_contains($compareText, 'peer') || str_contains($compareText, 'avg') || str_contains($compareText, 'average'))
                ? 'competitor'
                : (is_numeric($hotelId) && (int)$hotelId > 0 ? 'self' : 'competitor');
        }
        $compareType = in_array($compareType, ['self', 'my'], true) ? 'self' : 'competitor';
        $prefix = $compareType === 'self' ? 'self' : 'competitor';

        $exposure = $this->readTrafficNumber($row, ['listExposure', 'list_exposure', "{$prefix}_exposure", 'exposure', 'exposureCount', 'impressions', 'showCount', 'PV', 'pv', 'pageView', 'pageViews', 'page_view', 'data_value']);
        $detailVisitors = $this->readTrafficNumber($row, ['detailExposure', 'detail_exposure', "{$prefix}_detail_visitors", 'detail_visitors', 'detailVisitors', 'detailUv', 'visitorCount', 'UV', 'uv', 'uniqueVisitors', 'unique_visitors', 'views']);
        $orderVisitors = $this->readTrafficNumber($row, ['orderFillingNum', 'order_filling_num', "{$prefix}_order_visitors", 'order_visitors', 'orderVisitors', 'clickCount', 'click_count', 'clickNum', 'clicks']);
        $submitUsers = $this->readTrafficNumber($row, ['orderSubmitNum', 'order_submit_num', "{$prefix}_submit_users", 'submit_users', 'submitUsers', 'submitNum', 'orderCount', 'order_count', 'orderNum', 'bookOrderNum', 'dealNum', 'orders']);

        $exposureRate = $this->normalizeTrafficPercent($this->readTrafficNumber($row, ['flowRate', 'flow_rate', "{$prefix}_exposure_rate", 'exposure_rate', 'conversionRate', 'conversion_rate', 'convertionRate', 'convertRate', 'transforRate', 'transferRate', 'transRate', 'cvr'], null));
        $orderRate = $this->normalizeTrafficPercent($this->readTrafficNumber($row, ['orderFillRate', 'order_rate', "{$prefix}_order_rate", 'orderConversionRate'], null));
        $dealRate = $this->normalizeTrafficPercent($this->readTrafficNumber($row, ['submitRate', 'deal_rate', "{$prefix}_deal_rate", 'submitConversionRate', 'dealRate'], null));

        return [
            'date' => date('Y-m-d', strtotime((string)$date)),
            'compare_type' => $compareType,
            'metrics' => [
                'exposure' => $exposure,
                'detail_visitors' => $detailVisitors,
                'order_visitors' => $orderVisitors,
                'submit_users' => $submitUsers,
                'exposure_rate' => $exposureRate > 0 ? $exposureRate : $this->trafficRate($detailVisitors, $exposure),
                'order_rate' => $orderRate > 0 ? $orderRate : $this->trafficRate($orderVisitors, $detailVisitors),
                'deal_rate' => $dealRate > 0 ? $dealRate : $this->trafficRate($submitUsers, $orderVisitors),
            ],
        ];
    }

    private function calculateAppTrafficDerivedMetrics(array $base): array
    {
        $self = $base['self'];
        $competitor = $base['competitor'];

        $self['exposure_rate'] = $this->trafficRate($self['detail_visitors'], $self['exposure']);
        $self['order_rate'] = $this->trafficRate($self['order_visitors'], $self['detail_visitors']);
        $self['deal_rate'] = $this->trafficRate($self['submit_users'], $self['order_visitors']);
        $competitor['exposure_rate'] = $this->trafficRate($competitor['detail_visitors'], $competitor['exposure']);
        $competitor['order_rate'] = $this->trafficRate($competitor['order_visitors'], $competitor['detail_visitors']);
        $competitor['deal_rate'] = $this->trafficRate($competitor['submit_users'], $competitor['order_visitors']);

        $detailLoss = $self['exposure'] - $self['detail_visitors'];
        $orderLoss = $self['detail_visitors'] - $self['order_visitors'];
        $submitLoss = $self['order_visitors'] - $self['submit_users'];
        $lossMap = [
            '曝光到详情' => $detailLoss,
            '详情到订单页' => $orderLoss,
            '订单页到提交' => $submitLoss,
        ];
        arsort($lossMap);
        $maxLossStage = (float)reset($lossMap) > 0 ? (string)key($lossMap) : '无明显流失';

        $mainProblemStage = $this->diagnoseAppTrafficStage($self, $competitor);
        $recommendations = $this->buildAppTrafficRecommendations($mainProblemStage);

        $derived = [
            'date' => $base['date'],
            'self' => $self,
            'competitor' => $competitor,
            'exposure_gap' => $competitor['exposure'] - $self['exposure'],
            'detail_gap' => $competitor['detail_visitors'] - $self['detail_visitors'],
            'order_gap' => $competitor['order_visitors'] - $self['order_visitors'],
            'submit_gap' => $competitor['submit_users'] - $self['submit_users'],
            'exposure_achieve_rate' => $this->trafficRate($self['exposure'], $competitor['exposure']),
            'detail_achieve_rate' => $this->trafficRate($self['detail_visitors'], $competitor['detail_visitors']),
            'order_achieve_rate' => $this->trafficRate($self['order_visitors'], $competitor['order_visitors']),
            'submit_achieve_rate' => $this->trafficRate($self['submit_users'], $competitor['submit_users']),
            'detail_loss' => $detailLoss,
            'order_loss' => $orderLoss,
            'submit_loss' => $submitLoss,
            'exposure_rate_gap' => $self['exposure_rate'] - $competitor['exposure_rate'],
            'order_rate_gap' => $self['order_rate'] - $competitor['order_rate'],
            'deal_rate_gap' => $self['deal_rate'] - $competitor['deal_rate'],
            'potential_detail_visitors_by_competitor_rate' => $self['exposure'] * ($competitor['exposure_rate'] / 100),
            'potential_submit_users_by_competitor_exposure' => $competitor['exposure'] * ($self['exposure_rate'] / 100) * ($self['order_rate'] / 100) * ($self['deal_rate'] / 100),
            'max_loss_stage' => $maxLossStage,
            'main_problem_stage' => $mainProblemStage,
            'recommendations' => $recommendations,
        ];
        $derived['potential_submit_gap'] = $derived['potential_submit_users_by_competitor_exposure'] - $self['submit_users'];
        $derived['diagnosis'] = $this->buildAppTrafficDiagnosis($derived);
        return $derived;
    }

    private function diagnoseAppTrafficStage(array $self, array $competitor): string
    {
        $stage = '整体接近竞争圈';
        if ($self['exposure'] < $competitor['exposure'] * 0.5) {
            $stage = '曝光不足';
        }
        if ($self['exposure_rate'] < $competitor['exposure_rate'] - 3) {
            $stage = '列表点击弱';
        }
        if ($self['order_rate'] < $competitor['order_rate'] - 2) {
            $stage = '详情承接弱';
        }
        if ($self['deal_rate'] < $competitor['deal_rate'] - 5) {
            $stage = '成交转化弱';
        }
        if ($self['exposure_rate'] < $competitor['exposure_rate'] && $self['order_rate'] > $competitor['order_rate'] && $self['deal_rate'] > $competitor['deal_rate']) {
            $stage = '前端流量弱，后端转化强';
        }
        return $stage;
    }

    private function buildAppTrafficRecommendations(string $stage): array
    {
        return match ($stage) {
            '曝光不足' => ['检查排名', '价格竞争力', '房态库存', '活动', '商圈标签', '广告投放'],
            '列表点击弱' => ['优化首图', '标题', '点评分', '价格展示', '促销标签', '地理位置卖点'],
            '详情承接弱' => ['优化详情页卖点', '房型结构', '取消政策', '早餐', '接送', '设施图片'],
            '成交转化弱' => ['检查库存', '支付门槛', '担保规则', '价格跳变', '不可订房型'],
            '前端流量弱，后端转化强' => ['优先扩大曝光', '提升列表点击', '暂不优先改订单页'],
            default => ['持续监控曝光规模', '维护详情页转化', '观察竞争圈变化'],
        };
    }

    private function buildAppTrafficDiagnosis(array $derived): string
    {
        if (($derived['self']['exposure'] ?? 0) <= 0 && ($derived['competitor']['exposure'] ?? 0) <= 0) {
            return '当前日期范围暂无可分析的 APP 流量转化数据。';
        }

        $stage = $derived['main_problem_stage'];
        if ($stage === '前端流量弱，后端转化强') {
            return '当前酒店曝光转化率低于竞争圈，但下单转化率和成交转化率高于竞争圈，说明后端成交承接能力较好，核心短板在前端曝光规模和列表点击吸引力。';
        }
        return "当前酒店 APP 流量转化主要问题为{$stage}，最大流失阶段在{$derived['max_loss_stage']}，建议优先处理对应运营动作。";
    }

    private function readTrafficNumber(array $row, array $keys, ?float $default = 0.0): ?float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            $number = $this->coerceTrafficNumber($row[$key]);
            if ($number !== null) {
                return $number;
            }
        }
        return $default;
    }

    private function coerceTrafficNumber($value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float)$value;
        }
        if (!is_string($value)) {
            return null;
        }

        $normalized = str_replace([',', '%', ' '], '', trim($value));
        if ($normalized === '') {
            return null;
        }
        return is_numeric($normalized) ? (float)$normalized : null;
    }

    private function normalizeTrafficPercent(?float $value): float
    {
        if ($value === null) {
            return 0.0;
        }
        return abs($value) > 0 && abs($value) <= 1 ? $value * 100 : $value;
    }

    private function trafficRate(float $num, float $denom): float
    {
        return $denom > 0 ? round($num / $denom * 100, 2) : 0.0;
    }

    /**
     * 获取美团流量数据
     */
    public function fetchMeituanTraffic(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $url = trim((string)$this->request->post('url', ''));
        $cookies = trim((string)$this->request->post('cookies', ''));
        $partnerId = trim((string)$this->request->post('partner_id', ''));
        $poiId = trim((string)$this->request->post('poi_id', ''));
        $startDate = $this->request->post('start_date', '');
        $endDate = $this->request->post('end_date', '');
        $autoSave = $this->request->post('auto_save', true);
        $systemHotelId = $this->resolveOnlineDataSystemHotelId($this->request->post('system_hotel_id', null));
        $extraParamsStr = $this->request->post('extra_params', '');

        if (empty($url)) {
            return $this->error('请提供接口地址');
        }
        if (empty($cookies)) {
            return $this->error('请提供登录Cookies');
        }

        try {
            $extraParams = $this->parseJsonParams($extraParamsStr);
            $partnerId = $partnerId !== '' ? $partnerId : trim((string)($extraParams['partnerId'] ?? $extraParams['partner_id'] ?? ''));
            $poiId = $poiId !== '' ? $poiId : trim((string)($extraParams['poiId'] ?? $extraParams['poi_id'] ?? ''));

            if ($partnerId === '') {
                return $this->error('请提供Partner ID（商家ID）');
            }
            if ($poiId === '') {
                return $this->error('请提供POI ID（门店ID）');
            }

            $params = array_merge([
                'deviceType' => 1,
                'yodaReady' => 'h5',
                'csecplatform' => 4,
                'csecversion' => '4.2.0',
            ], $extraParams);
            $params['partnerId'] = $partnerId;
            $params['poiId'] = $poiId;
            if ($startDate && $endDate) {
                $params['startDate'] = str_replace('-', '', $startDate);
                $params['endDate'] = str_replace('-', '', $endDate);
                $params['dateRange'] = 1;
            } else {
                $yesterday = date('Ymd', strtotime('-1 day'));
                $params['startDate'] = $yesterday;
                $params['endDate'] = $yesterday;
                $params['dateRange'] = 1;
                $startDate = date('Y-m-d', strtotime('-1 day'));
            }

            $result = $this->sendMeituanRequest($url, $params, $cookies);
            if (!$result['success']) {
                $this->recordCookieAlert('meituan', 'fetch-meituan-traffic', (string)($result['error'] ?? ''), $systemHotelId ? (int)$systemHotelId : null);
                return $this->error('请求失败: ' . $result['error']);
            }

            $responseData = $result['data'] ?? [];
            $savedCount = 0;
            if ($autoSave && is_array($responseData)) {
                $savedCount = $this->parseAndSaveTrafficData(
                    $responseData,
                    $startDate,
                    $endDate,
                    'meituan',
                    $systemHotelId ? (int)$systemHotelId : null
                );
            }

            OperationLog::record('online_data', 'fetch_meituan_traffic', '获取美团流量数据', $this->currentUser->id, $systemHotelId ? (int)$systemHotelId : null);

            return $this->success([
                'data' => $responseData,
                'raw_response' => $result['raw'] ?? '',
                'saved_count' => $savedCount,
            ]);
        } catch (\Throwable $e) {
            return $this->error('请求异常: ' . $e->getMessage());
        }
    }

    /**
     * 获取美团订单数据（手动提供 Cookie + Network Request URL）
     */
    public function fetchMeituanOrders(): Response
    {
        return $this->fetchMeituanManualBusinessSection('orders');
    }

    /**
     * 获取美团推广通广告数据（手动提供 Cookie + Network Request URL）
     */
    public function fetchMeituanAds(): Response
    {
        return $this->fetchMeituanManualBusinessSection('ads');
    }

    private function fetchMeituanManualBusinessSection(string $section): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $url = trim((string)$this->request->post('url', ''));
        $cookies = trim((string)$this->request->post('cookies', ''));
        $partnerId = trim((string)$this->request->post('partner_id', ''));
        $poiId = trim((string)$this->request->post('poi_id', ''));
        $shopId = trim((string)$this->request->post('shop_id', ''));
        $startDate = (string)$this->request->post('start_date', '');
        $endDate = (string)$this->request->post('end_date', '');
        $method = strtoupper(trim((string)$this->request->post('method', 'GET'))) ?: 'GET';
        $autoSave = $this->request->post('auto_save', true);
        $payloadJson = (string)$this->request->post('payload_json', '');
        $extraParamsStr = (string)$this->request->post('extra_params', '');
        $hotelName = trim((string)$this->request->post('hotel_name', ''));
        $systemHotelId = $this->resolveOnlineDataSystemHotelId($this->request->post('system_hotel_id', null));

        if ($url === '') {
            return $this->error('请提供 Network 中的接口 Request URL');
        }
        if ($cookies === '') {
            return $this->error('请提供登录 Cookies');
        }
        if (!in_array($method, ['GET', 'POST'], true)) {
            return $this->error('请求方式仅支持 GET 或 POST');
        }

        try {
            $extraParams = $this->parseJsonParams($extraParamsStr);
            $payloadParams = $this->parseJsonParams($payloadJson);
            $partnerId = $partnerId !== '' ? $partnerId : trim((string)($extraParams['partnerId'] ?? $extraParams['partner_id'] ?? $payloadParams['partnerId'] ?? $payloadParams['partner_id'] ?? ''));
            $poiId = $poiId !== '' ? $poiId : trim((string)($extraParams['poiId'] ?? $extraParams['poi_id'] ?? $payloadParams['poiId'] ?? $payloadParams['poi_id'] ?? ''));
            $shopId = $shopId !== '' ? $shopId : trim((string)($extraParams['shopId'] ?? $extraParams['shop_id'] ?? $payloadParams['shopId'] ?? $payloadParams['shop_id'] ?? $poiId));

            if ($section === 'orders' && ($partnerId === '' || $poiId === '')) {
                return $this->error('订单接口需要提供 Partner ID 和 POI ID');
            }
            if ($section === 'ads' && $shopId === '' && $poiId === '') {
                return $this->error('广告接口需要提供 Shop ID 或 POI ID');
            }

            [$startDate, $endDate] = $this->normalizeMeituanManualDateRange($startDate, $endDate);
            $params = array_merge([
                'deviceType' => 1,
                'yodaReady' => 'h5',
                'csecplatform' => 4,
                'csecversion' => '4.2.0',
            ], $extraParams, $payloadParams);
            if ($partnerId !== '') {
                $params['partnerId'] = $partnerId;
            }
            if ($poiId !== '') {
                $params['poiId'] = $poiId;
            }
            if ($shopId !== '') {
                $params['shopId'] = $shopId;
            }
            $params['startDate'] = str_replace('-', '', $startDate);
            $params['endDate'] = str_replace('-', '', $endDate);
            $params['dateRange'] = $params['dateRange'] ?? 1;

            $allowedHosts = $section === 'ads' ? ['dianping.com', 'meituan.com'] : ['meituan.com'];
            $result = $this->sendMeituanManualRequest($url, $params, $cookies, $method, $allowedHosts, $section);
            if (!empty($result['error'])) {
                $this->recordCookieAlert('meituan', 'fetch-meituan-' . $section, (string)$result['error'], $systemHotelId ? (int)$systemHotelId : null);
                return $this->error((string)$result['error'], 400, [
                    'http_code' => $result['http_code'] ?? 0,
                    'raw_response' => $result['raw_response'] ?? '',
                    'decoded_data' => $result['decoded_data'] ?? null,
                    'request_url' => $result['request_url'] ?? $url,
                    'request_payload' => $params,
                ]);
            }

            $responseData = is_array($result['decoded_data'] ?? null) ? $result['decoded_data'] : [];
            $items = $this->normalizeMeituanCapturedList($responseData, $section);
            $capturedPayload = [
                'store_id' => $shopId ?: $poiId,
                'poi_id' => $poiId ?: $shopId,
                'poi_name' => $hotelName,
                'system_hotel_id' => $systemHotelId ? (int)$systemHotelId : null,
                'default_data_date' => $endDate ?: $startDate,
                $section => $items,
            ];
            $rows = $this->buildMeituanCapturedDailyRows($capturedPayload, $systemHotelId ? (int)$systemHotelId : null);
            $savedCount = ($autoSave && !empty($rows)) ? $this->saveMeituanCapturedDailyRows($rows) : 0;

            if ($this->currentUser && isset($this->currentUser->id)) {
                OperationLog::record(
                    'online_data',
                    'fetch_meituan_' . $section,
                    '获取美团' . ($section === 'orders' ? '订单' : '广告') . '数据',
                    $this->currentUser->id,
                    $systemHotelId ? (int)$systemHotelId : null
                );
            }

            return $this->success([
                'data' => $items,
                'rows' => $rows,
                'total' => count($items),
                'row_count' => count($rows),
                'saved_count' => $savedCount,
                'counts' => $this->summarizeMeituanCapturedRows($rows),
                'decoded_data' => $responseData,
                'raw_response' => $result['raw_response'] ?? '',
                'http_code' => $result['http_code'] ?? 0,
                'request_url' => $result['request_url'] ?? $url,
                'request_method' => $method,
                'request_payload' => $params,
                'request_start_date' => $startDate,
                'request_end_date' => $endDate,
            ], $savedCount > 0 ? '获取成功' : '获取成功，但未解析到可入库数据');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            return $this->error('请求异常: ' . $e->getMessage());
        }
    }

    /**
     * 获取美团差评数据（优化版）
     * API: https://eb.meituan.com/api/v1/ebooking/comments/commentsInfo
     */
    public function fetchMeituanComments(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $cookies = trim((string)$this->request->post('cookies', ''));
        $partnerId = trim((string)$this->request->post('partner_id', ''));
        $poiId = trim((string)$this->request->post('poi_id', ''));
        $mtsiEbU = trim((string)$this->request->post('mtsi_eb_u', $this->request->post('_mtsi_eb_u', ''))); // _mtsi_eb_u 可选参数
        $replyType = $this->request->post('reply_type', '2'); // 2=差评/待回复
        $tag = trim((string)$this->request->post('tag', '')); // 标签筛选
        $limit = $this->request->post('limit', 50);
        $offset = $this->request->post('offset', 0);
        $platform = $this->request->post('platform', 1);
        $mtgsig = trim((string)$this->request->post('mtgsig', '')); // 美团签名（403/418时需要）
        $requestUrl = trim((string)$this->request->post('request_url', '')); // 自定义请求地址（可选）
        $startDate = $this->request->post('start_date', ''); // 开始日期
        $endDate = $this->request->post('end_date', ''); // 结束日期
        $autoSave = $this->request->post('auto_save', true);
        $systemHotelId = $this->resolveOnlineDataSystemHotelId($this->request->post('system_hotel_id', null));

        if (empty($cookies)) {
            return $this->error('请提供登录Cookies');
        }
        if (empty($partnerId)) {
            return $this->error('请提供Partner ID（商家ID）');
        }
        if (empty($poiId)) {
            return $this->error('请提供POI ID（门店ID）');
        }
        try {
            // 生成随机traceid
            $traceId = '-' . rand(1000000000, 9999999999) . time();

            // 如果有自定义请求地址，使用它；否则构建默认URL
            if (!empty($requestUrl) && filter_var($requestUrl, FILTER_VALIDATE_URL)) {
                $fullUrl = $requestUrl;
            } else {
                // 构建API URL和参数
                $url = 'https://eb.meituan.com/api/v1/ebooking/comments/commentsInfo';
                $params = [
                    'limit' => (int)$limit,
                    'offset' => (int)$offset,
                    'partnerId' => $partnerId,
                    'platform' => (int)$platform,
                    'poiId' => $poiId,
                    'prefetchIndex' => 1,
                    'replyType' => $replyType,
                    'reportStatus' => '',
                    'tag' => $tag,
                    'yodaReady' => 'h5',
                    'csecplatform' => 4,
                    'csecversion' => '4.2.0',
                ];

                // 添加可选的 _mtsi_eb_u 参数
                if (!empty($mtsiEbU)) {
                    $params['_mtsi_eb_u'] = $mtsiEbU;
                }

                // 添加日期参数（如果有）
                if (!empty($startDate)) {
                    $params['startDate'] = $startDate;
                }
                if (!empty($endDate)) {
                    $params['endDate'] = $endDate;
                }

                // 构建完整URL
                $fullUrl = $url . '?' . http_build_query($params);
            }
            if (!$this->isAllowedOtaRequestUrl($fullUrl, ['meituan.com'])) {
                return $this->error('仅允许请求美团官方域名');
            }

            // 基础请求头（模拟浏览器请求）
            $headers = [
                'Accept: application/json, text/plain, */*',
                'Accept-Language: zh-CN,zh;q=0.9',
                'Cache-Control: no-cache',
                'Connection: keep-alive',
                'Cookie: ' . $cookies,
                'Host: eb.meituan.com',
                'm-appkey: fe_hotel-fe-ebooking',
                'm-traceid: ' . $traceId,
                'Origin: https://eb.meituan.com',
                'Pragma: no-cache',
                'Referer: https://eb.meituan.com/ebk/feedback/feedback.html',
                'Sec-CH-UA: "Not:A-Brand";v="99", "Google Chrome";v="145", "Chromium";v="145"',
                'Sec-CH-UA-Mobile: ?0',
                'Sec-CH-UA-Platform: "Windows"',
                'Sec-Fetch-Dest: empty',
                'Sec-Fetch-Mode: cors',
                'Sec-Fetch-Site: same-origin',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36',
            ];

            // 如果提供了mtgsig签名，添加到请求头（这是关键！）
            if (!empty($mtgsig)) {
                $headers[] = 'mtgsig: ' . $mtgsig;
            }

            // 使用curl发送请求（支持gzip自动解压）
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $fullUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => $this->shouldVerifyOtaSsl(),
                CURLOPT_SSL_VERIFYHOST => $this->shouldVerifyOtaSsl() ? 2 : 0,
                CURLOPT_ENCODING => 'gzip, deflate', // 自动处理gzip
                CURLOPT_FOLLOWLOCATION => true,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            // 调试信息
            if ($response === false || $httpCode !== 200) {
                $errorMsg = $curlError ?: "HTTP {$httpCode}";
                if (in_array((int)$httpCode, [403, 418], true)) {
                    $message = $mtgsig === ''
                        ? "美团API拒绝访问（{$httpCode}）。当前点评接口需要 mtgsig 签名，请在美团ebooking差评页面按F12 → Network → 刷新页面 → 找到commentsInfo请求 → 复制最新mtgsig；如仍失败，可同时粘贴完整commentsInfo请求URL"
                        : "美团API拒绝访问（{$httpCode}）。mtgsig签名无效或已过期，请重新获取：在美团ebooking差评页面按F12 → Network → 刷新页面 → 找到commentsInfo请求 → 复制最新的mtgsig值";
                    $this->recordCookieAlert('meituan', 'fetch-meituan-comments', $message, $systemHotelId ? (int)$systemHotelId : null);
                    return $this->error($message);
                }
                $this->recordCookieAlert('meituan', 'fetch-meituan-comments', $errorMsg, $systemHotelId ? (int)$systemHotelId : null);
                return $this->error('请求美团API失败: ' . $errorMsg);
            }

            // 解析响应
            $data = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                // 尝试处理可能的BOM或其他编码问题
                $response = preg_replace('/^\xEF\xBB\xBF/', '', $response);
                $data = json_decode($response, true);
            }

            if (!is_array($data)) {
                return $this->error('解析响应数据失败，原始响应: ' . mb_substr($response, 0, 500));
            }

            // 检查API返回状态（美团返回status字段，0表示成功）
            $status = $data['status'] ?? $data['code'] ?? null;
            if ($status !== 0 && $status !== 200 && $status !== null) {
                $errorMsg = $data['msg'] ?? $data['message'] ?? '未知错误';
                // 如果是签名相关错误，提示用户
                if (strpos($errorMsg, '签名') !== false || strpos($errorMsg, 'sign') !== false || strpos($errorMsg, 'token') !== false) {
                    $message = $mtgsig === ''
                        ? '美团API签名验证失败，请在美团ebooking页面打开开发者工具，复制commentsInfo请求头中的mtgsig值后重试'
                        : '美团API签名验证失败，当前mtgsig可能已过期，请复制commentsInfo请求头中的最新mtgsig值后重试';
                    $this->recordCookieAlert('meituan', 'fetch-meituan-comments', $message, $systemHotelId ? (int)$systemHotelId : null);
                    return $this->error($message);
                }
                $this->recordCookieAlert('meituan', 'fetch-meituan-comments', (string)$errorMsg, $systemHotelId ? (int)$systemHotelId : null);
                return $this->error('美团API返回错误: ' . $errorMsg);
            }

            // 提取评论数据（美团返回commentList字段）
            $comments = $data['data']['commentList'] ?? $data['data']['list'] ?? $data['data'] ?? [];
            $total = $data['data']['total'] ?? count($comments);

            $savedCount = 0;
            if ($autoSave && !empty($comments)) {
                $savedCount = $this->parseAndSaveMeituanComments(
                    $comments,
                    $poiId,
                    $partnerId,
                    $systemHotelId ? (int)$systemHotelId : null
                );
            }

            OperationLog::record('online_data', 'fetch_meituan_comments', '获取美团差评数据', $this->currentUser->id, $systemHotelId ? (int)$systemHotelId : null);

            return $this->success([
                'data' => $comments,
                'total' => $total,
                'saved_count' => $savedCount,
                'raw_response' => mb_substr($response, 0, 1000),
            ]);
        } catch (\Throwable $e) {
            return $this->error('请求异常: ' . $e->getMessage());
        }
    }

    /**
     * 解析并保存美团差评数据
     */
    // Browser profile capture payload entrypoint.
    public function saveMeituanCapturedData(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $requestData = $this->requestData();
        $payload = $requestData['payload'] ?? $requestData['captured_data'] ?? $requestData;
        if (!is_array($payload)) {
            return $this->error('Invalid Meituan captured payload');
        }

        $systemHotelId = $this->resolveOnlineDataSystemHotelId(
            $requestData['system_hotel_id']
            ?? $requestData['hotel_id']
            ?? $payload['system_hotel_id']
            ?? $payload['hotel_id']
            ?? null
        );
        $rows = $this->buildMeituanCapturedDailyRows($payload, $systemHotelId);
        if (empty($rows)) {
            return $this->success([
                'saved_count' => 0,
                'row_count' => 0,
                'counts' => [],
            ], 'No Meituan captured rows to save');
        }

        $savedCount = $this->saveMeituanCapturedDailyRows($rows);
        if ($this->currentUser && isset($this->currentUser->id)) {
            OperationLog::record(
                'online_data',
                'save_meituan_captured_data',
                'Save Meituan captured OTA data',
                $this->currentUser->id,
                $systemHotelId ? (int)$systemHotelId : null
            );
        }

        return $this->success([
            'saved_count' => $savedCount,
            'row_count' => count($rows),
            'counts' => $this->summarizeMeituanCapturedRows($rows),
        ]);
    }

    // Launch local browser profile capture, then save the captured payload.
    public function captureMeituanBrowserData(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');
        @set_time_limit(0);

        $requestData = $this->requestData();
        $systemHotelId = $this->resolveOnlineDataSystemHotelId(
            $requestData['system_hotel_id']
            ?? $requestData['hotel_id']
            ?? null
        );
        $storeId = trim((string)($requestData['store_id'] ?? $requestData['storeId'] ?? $requestData['poi_id'] ?? ''));
        if ($storeId === '') {
            return $this->error('请填写美团 Store ID / 门店 ID');
        }

        $projectRoot = dirname(__DIR__, 2);
        $scriptPath = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'meituan_browser_capture.mjs';
        if (!is_file($scriptPath)) {
            return $this->error('未找到美团浏览器抓取脚本');
        }

        $nodeBinary = $this->resolveMeituanCaptureNodeBinary();
        if ($nodeBinary === '') {
            return $this->error('未找到 Node.js，请先安装 Node.js 或配置 NODE_BINARY');
        }

        $outputDir = $projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'meituan_capture';
        if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
            return $this->error('无法创建美团抓取输出目录');
        }

        $outputPath = $outputDir . DIRECTORY_SEPARATOR . 'meituan_capture_' . $this->safeMeituanCaptureFilePart($storeId) . '_' . date('YmdHis') . '.json';
        $timeoutSeconds = max(60, min(900, (int)($requestData['timeout_seconds'] ?? 600)));

        $args = [
            $nodeBinary,
            $scriptPath,
            '--store-id=' . $storeId,
            '--output=' . $outputPath,
            '--login-timeout-ms=' . (string)max(30000, min(600000, (int)($requestData['login_timeout_ms'] ?? 300000))),
        ];
        if ($systemHotelId) {
            $args[] = '--system-hotel-id=' . (string)$systemHotelId;
        }
        $poiId = trim((string)($requestData['poi_id'] ?? $requestData['poiId'] ?? ''));
        if ($poiId !== '') {
            $args[] = '--poi-id=' . $poiId;
        }
        $poiName = trim((string)($requestData['poi_name'] ?? $requestData['poiName'] ?? ''));
        if ($poiName !== '') {
            $args[] = '--poi-name=' . $poiName;
        }
        $adsUrl = trim((string)($requestData['ads_url'] ?? $requestData['adsUrl'] ?? ''));
        if ($adsUrl !== '') {
            $args[] = '--ads-url=' . $adsUrl;
        }
        $captureSectionsValue = $requestData['sections'] ?? $requestData['capture_sections'] ?? $requestData['captureSections'] ?? '';
        $captureSections = is_array($captureSectionsValue)
            ? implode(',', array_map('strval', $captureSectionsValue))
            : trim((string)$captureSectionsValue);
        if ($captureSections !== '') {
            $captureSections = preg_replace('/[^a-zA-Z,_\-\s]+/', '', $captureSections) ?: '';
            if ($captureSections !== '') {
                $args[] = '--sections=' . $captureSections;
            }
        }
        $chromePath = $this->resolveMeituanCaptureChromePath();
        if ($chromePath !== '') {
            $args[] = '--chrome-path=' . $chromePath;
        }

        $runResult = $this->runMeituanCaptureProcess($args, $projectRoot, $timeoutSeconds);
        if (!$runResult['success']) {
            return $this->error($runResult['message'], 400, [
                'stdout' => $this->trimMeituanCaptureLog($runResult['stdout'] ?? ''),
                'stderr' => $this->trimMeituanCaptureLog($runResult['stderr'] ?? ''),
            ]);
        }

        if (!is_file($outputPath)) {
            return $this->error('抓取脚本已结束，但未生成结果文件', 400, [
                'stdout' => $this->trimMeituanCaptureLog($runResult['stdout'] ?? ''),
                'stderr' => $this->trimMeituanCaptureLog($runResult['stderr'] ?? ''),
            ]);
        }

        $payload = json_decode((string)file_get_contents($outputPath), true);
        if (!is_array($payload)) {
            return $this->error('抓取结果 JSON 无法解析', 400, [
                'output' => $outputPath,
                'stdout' => $this->trimMeituanCaptureLog($runResult['stdout'] ?? ''),
                'stderr' => $this->trimMeituanCaptureLog($runResult['stderr'] ?? ''),
            ]);
        }

        if ($systemHotelId && empty($payload['system_hotel_id'])) {
            $payload['system_hotel_id'] = $systemHotelId;
        }
        $rows = $this->buildMeituanCapturedDailyRows($payload, $systemHotelId);
        $savedCount = empty($rows) ? 0 : $this->saveMeituanCapturedDailyRows($rows);

        if ($this->currentUser && isset($this->currentUser->id)) {
            OperationLog::record(
                'online_data',
                'capture_meituan_browser_data',
                'Capture Meituan OTA data via local browser profile',
                $this->currentUser->id,
                $systemHotelId ? (int)$systemHotelId : null
            );
        }

        return $this->success([
            'saved_count' => $savedCount,
            'row_count' => count($rows),
            'counts' => $this->summarizeMeituanCapturedRows($rows),
            'payload_counts' => [
                'reviews' => $this->countMeituanPayloadSection($payload, 'reviews'),
                'traffic' => $this->countMeituanPayloadSection($payload, 'traffic'),
                'ads' => $this->countMeituanPayloadSection($payload, 'ads'),
                'orders' => $this->countMeituanPayloadSection($payload, 'orders'),
                'responses' => $this->countMeituanPayloadSection($payload, 'responses'),
            ],
            'output' => $outputPath,
            'pages' => $payload['pages'] ?? [],
            'screenshots' => $payload['screenshots'] ?? [],
            'stdout' => $this->trimMeituanCaptureLog($runResult['stdout'] ?? ''),
        ], $savedCount > 0 ? '美团浏览器抓取完成并已入库' : '美团浏览器抓取完成，但未解析到可入库数据');
    }

    // Launch a local Ctrip eBooking browser profile, capture getCommentList responses, then save reviews.
    public function captureCtripCommentsBrowserData(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');
        @set_time_limit(0);

        $requestData = $this->requestData();
        $systemHotelId = $this->resolveOnlineDataSystemHotelId(
            $requestData['system_hotel_id']
            ?? $requestData['systemHotelId']
            ?? null
        );
        $hotelId = trim((string)($requestData['hotel_id'] ?? $requestData['hotelId'] ?? $requestData['ctrip_hotel_id'] ?? ''));
        $profileId = trim((string)($requestData['profile_id'] ?? $requestData['profileId'] ?? $hotelId));
        if ($profileId === '' && $systemHotelId) {
            $profileId = 'system_' . $systemHotelId;
        }
        if ($profileId === '') {
            return $this->error('请填写携程 Profile ID / OTA酒店ID，或选择数据归属酒店');
        }

        $projectRoot = dirname(__DIR__, 2);
        $scriptPath = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'ctrip_comment_browser_capture.mjs';
        if (!is_file($scriptPath)) {
            return $this->error('未找到携程点评浏览器抓取脚本');
        }

        $nodeBinary = $this->resolveMeituanCaptureNodeBinary();
        if ($nodeBinary === '') {
            return $this->error('未找到 Node.js，请先安装 Node.js 或配置 NODE_BINARY');
        }

        $outputDir = $projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'ctrip_capture';
        if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
            return $this->error('无法创建携程抓取输出目录');
        }

        $outputPath = $outputDir . DIRECTORY_SEPARATOR . 'ctrip_comment_capture_' . $this->safeMeituanCaptureFilePart($profileId) . '_' . date('YmdHis') . '.json';
        $timeoutSeconds = max(60, min(900, (int)($requestData['timeout_seconds'] ?? 600)));

        $args = [
            $nodeBinary,
            $scriptPath,
            '--profile-id=' . $profileId,
            '--output=' . $outputPath,
            '--login-timeout-ms=' . (string)max(30000, min(600000, (int)($requestData['login_timeout_ms'] ?? 300000))),
        ];
        if ($hotelId !== '') {
            $args[] = '--hotel-id=' . $hotelId;
        }
        if ($systemHotelId) {
            $args[] = '--system-hotel-id=' . (string)$systemHotelId;
        }
        $hotelName = trim((string)($requestData['hotel_name'] ?? $requestData['hotelName'] ?? ''));
        if ($hotelName !== '') {
            $args[] = '--hotel-name=' . $hotelName;
        }
        $pageUrl = trim((string)($requestData['page_url'] ?? $requestData['pageUrl'] ?? ''));
        if ($pageUrl !== '') {
            if (!$this->isAllowedOtaRequestUrl($pageUrl, ['ctrip.com'])) {
                return $this->error('仅允许打开携程官方域名的点评页面');
            }
            $args[] = '--page-url=' . $pageUrl;
        }
        $apiKeyword = trim((string)($requestData['api_keyword'] ?? $requestData['apiKeyword'] ?? 'getCommentList'));
        $apiKeyword = preg_replace('/[^a-zA-Z0-9_\-\/.]+/', '', $apiKeyword) ?: 'getCommentList';
        $args[] = '--api-keyword=' . $apiKeyword;
        $chromePath = $this->resolveMeituanCaptureChromePath();
        if ($chromePath !== '') {
            $args[] = '--chrome-path=' . $chromePath;
        }

        $runResult = $this->runMeituanCaptureProcess($args, $projectRoot, $timeoutSeconds);
        if (!$runResult['success']) {
            return $this->error(str_replace('美团', '携程', $runResult['message']), 400, [
                'stdout' => $this->trimMeituanCaptureLog($runResult['stdout'] ?? ''),
                'stderr' => $this->trimMeituanCaptureLog($runResult['stderr'] ?? ''),
            ]);
        }

        if (!is_file($outputPath)) {
            return $this->error('抓取脚本已结束，但未生成结果文件', 400, [
                'stdout' => $this->trimMeituanCaptureLog($runResult['stdout'] ?? ''),
                'stderr' => $this->trimMeituanCaptureLog($runResult['stderr'] ?? ''),
            ]);
        }

        $payload = json_decode((string)file_get_contents($outputPath), true);
        if (!is_array($payload)) {
            return $this->error('抓取结果 JSON 无法解析', 400, [
                'output' => $outputPath,
                'stdout' => $this->trimMeituanCaptureLog($runResult['stdout'] ?? ''),
                'stderr' => $this->trimMeituanCaptureLog($runResult['stderr'] ?? ''),
            ]);
        }

        if ($systemHotelId && empty($payload['system_hotel_id'])) {
            $payload['system_hotel_id'] = $systemHotelId;
        }
        if ($hotelId !== '' && empty($payload['hotel_id'])) {
            $payload['hotel_id'] = $hotelId;
        }
        $comments = $this->extractCtripCapturedComments($payload);
        $requestHotelId = $hotelId !== '' ? $hotelId : (string)($payload['hotel_id'] ?? $profileId);
        $savedCount = $this->parseAndSaveCtripComments(
            $comments,
            array_merge($payload, ['hotelId' => $requestHotelId]),
            $requestHotelId,
            '',
            $systemHotelId
        );

        if ($this->currentUser && isset($this->currentUser->id)) {
            OperationLog::record(
                'online_data',
                'capture_ctrip_comments_browser',
                'Capture Ctrip comment data via local browser profile',
                $this->currentUser->id,
                $systemHotelId ? (int)$systemHotelId : null
            );
        }

        return $this->success([
            'data' => $comments,
            'total' => count($comments),
            'saved_count' => $savedCount,
            'row_count' => count($comments),
            'counts' => ['review' => count($comments)],
            'payload_counts' => [
                'reviews' => count($comments),
                'responses' => $this->countCtripPayloadSection($payload, 'responses'),
                'xhr_urls' => $this->countCtripPayloadSection($payload, 'xhr_urls'),
            ],
            'output' => $outputPath,
            'pages' => $payload['pages'] ?? [],
            'xhr_urls' => array_slice($payload['xhr_urls'] ?? [], 0, 20),
            'screenshots' => $payload['screenshots'] ?? [],
            'stdout' => $this->trimMeituanCaptureLog($runResult['stdout'] ?? ''),
        ], $savedCount > 0 ? '携程点评浏览器抓取完成并已入库' : '携程点评浏览器抓取完成，但未解析到可入库点评');
    }

    public function captureCtripBrowserData(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');
        @set_time_limit(0);

        $requestData = $this->requestData();
        $systemHotelId = $this->resolveOnlineDataSystemHotelId(
            $requestData['system_hotel_id']
            ?? $requestData['systemHotelId']
            ?? null
        );
        if (!$systemHotelId) {
            return $this->error('请选择数据归属酒店');
        }

        $dataDate = $this->normalizeOnlineDataDate($requestData['data_date'] ?? $requestData['dataDate'] ?? '');
        if ($dataDate === '') {
            $dataDate = date('Y-m-d', strtotime('-1 day'));
        }

        $hotelId = trim((string)($requestData['hotel_id'] ?? $requestData['hotelId'] ?? $requestData['ctrip_hotel_id'] ?? ''));
        $profileId = trim((string)($requestData['profile_id'] ?? $requestData['profileId'] ?? $hotelId));
        if ($profileId === '') {
            $profileId = 'system_' . (string)$systemHotelId;
        }

        $projectRoot = dirname(__DIR__, 2);
        $scriptPath = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'ctrip_browser_capture.mjs';
        if (!is_file($scriptPath)) {
            return $this->error('未找到携程浏览器 Profile 采集脚本');
        }

        $nodeBinary = $this->resolveMeituanCaptureNodeBinary();
        if ($nodeBinary === '') {
            return $this->error('未找到 Node.js，请先安装 Node.js 或配置 NODE_BINARY');
        }

        $outputDir = $projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'ctrip_capture';
        if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
            return $this->error('无法创建携程采集输出目录');
        }

        $outputPath = $outputDir . DIRECTORY_SEPARATOR . 'ctrip_browser_capture_' . $this->safeMeituanCaptureFilePart($profileId) . '_' . date('YmdHis') . '.json';
        $timeoutSeconds = max(60, min(900, (int)($requestData['timeout_seconds'] ?? 600)));

        $args = [
            $nodeBinary,
            $scriptPath,
            '--profile-id=' . $profileId,
            '--system-hotel-id=' . (string)$systemHotelId,
            '--data-date=' . $dataDate,
            '--output=' . $outputPath,
            '--login-timeout-ms=' . (string)max(30000, min(600000, (int)($requestData['login_timeout_ms'] ?? 300000))),
        ];
        if ($hotelId !== '') {
            $args[] = '--hotel-id=' . $hotelId;
        }
        $hotelName = trim((string)($requestData['hotel_name'] ?? $requestData['hotelName'] ?? ''));
        if ($hotelName !== '') {
            $args[] = '--hotel-name=' . $hotelName;
        }
        $sectionsValue = $requestData['sections'] ?? $requestData['capture_sections'] ?? $requestData['captureSections'] ?? 'business,traffic';
        $sectionsRaw = is_array($sectionsValue)
            ? implode(',', array_map('strval', $sectionsValue))
            : trim((string)$sectionsValue);
        $sectionsRaw = preg_replace('/[^a-zA-Z,_\-\s]+/', '', $sectionsRaw) ?: 'business,traffic';
        $sectionsList = array_values(array_unique(array_filter(
            array_map(static fn($item): string => strtolower(trim((string)$item)), preg_split('/[,\s]+/', $sectionsRaw) ?: []),
            static fn(string $item): bool => in_array($item, ['business', 'traffic'], true)
        )));
        $sections = implode(',', $sectionsList) ?: 'business,traffic';
        $args[] = '--sections=' . $sections;
        $chromePath = $this->resolveMeituanCaptureChromePath();
        if ($chromePath !== '') {
            $args[] = '--chrome-path=' . $chromePath;
        }

        $runResult = $this->runMeituanCaptureProcess($args, $projectRoot, $timeoutSeconds);
        if (!$runResult['success']) {
            return $this->error(str_replace('美团', '携程', $runResult['message']), 400, [
                'stdout' => $this->trimMeituanCaptureLog($runResult['stdout'] ?? ''),
                'stderr' => $this->trimMeituanCaptureLog($runResult['stderr'] ?? ''),
            ]);
        }

        if (!is_file($outputPath)) {
            return $this->error('采集脚本已结束，但未生成结果文件', 400, [
                'stdout' => $this->trimMeituanCaptureLog($runResult['stdout'] ?? ''),
                'stderr' => $this->trimMeituanCaptureLog($runResult['stderr'] ?? ''),
            ]);
        }

        $payload = json_decode((string)file_get_contents($outputPath), true);
        if (!is_array($payload)) {
            return $this->error('采集结果 JSON 无法解析', 400, [
                'output' => $outputPath,
                'stdout' => $this->trimMeituanCaptureLog($runResult['stdout'] ?? ''),
                'stderr' => $this->trimMeituanCaptureLog($runResult['stderr'] ?? ''),
            ]);
        }

        if (empty($payload['system_hotel_id'])) {
            $payload['system_hotel_id'] = $systemHotelId;
        }
        if ($hotelId !== '' && empty($payload['hotel_id'])) {
            $payload['hotel_id'] = $hotelId;
        }
        if (empty($payload['default_data_date'])) {
            $payload['default_data_date'] = $dataDate;
        }

        $requestHotelId = $hotelId !== '' ? $hotelId : (string)($payload['hotel_id'] ?? $profileId);
        $saveResult = $this->saveCtripBrowserProfilePayload($payload, (int)$systemHotelId, $dataDate, $requestHotelId);
        $savedCount = (int)$saveResult['saved_count'];
        $capturedCounts = [
            'business' => count($this->extractCtripCapturedSection($payload, 'business')),
            'traffic' => count($this->extractCtripCapturedSection($payload, 'traffic')),
            'responses' => $this->countCtripPayloadSection($payload, 'responses'),
            'xhr_urls' => $this->countCtripPayloadSection($payload, 'xhr_urls'),
        ];

        if ($this->currentUser && isset($this->currentUser->id)) {
            OperationLog::record(
                'online_data',
                'capture_ctrip_browser',
                'Capture Ctrip OTA data via local browser profile',
                $this->currentUser->id,
                (int)$systemHotelId
            );
        }
        if ($savedCount > 0) {
            $this->updateCtripLatestFetchStatus((int)$systemHotelId, date('Y-m-d H:i:s'), $dataDate, $savedCount);
        }

        return $this->success([
            'saved_count' => $savedCount,
            'row_count' => array_sum(array_intersect_key($capturedCounts, array_flip(['business', 'traffic']))),
            'counts' => [
                'business' => (int)$saveResult['business_saved'],
                'traffic' => (int)$saveResult['traffic_saved'],
            ],
            'captured_counts' => $capturedCounts,
            'modules' => $saveResult['modules'],
            'output' => $outputPath,
            'pages' => $payload['pages'] ?? [],
            'xhr_urls' => array_slice($payload['xhr_urls'] ?? [], 0, 20),
            'screenshots' => $payload['screenshots'] ?? [],
            'stdout' => $this->trimMeituanCaptureLog($runResult['stdout'] ?? ''),
        ], $savedCount > 0 ? '携程浏览器 Profile 采集完成并已入库' : '携程浏览器 Profile 采集完成，但未解析到可入库数据');
    }

    public function fetchCtripOverviewData(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $requestData = $this->requestData();
        $systemHotelId = $this->resolveOnlineDataSystemHotelId(
            $requestData['system_hotel_id']
            ?? $requestData['systemHotelId']
            ?? null
        );
        $hotelId = trim((string)($requestData['hotel_id'] ?? $requestData['hotelId'] ?? $requestData['ctrip_hotel_id'] ?? $requestData['node_id'] ?? ''));
        $hotelName = trim((string)($requestData['hotel_name'] ?? $requestData['hotelName'] ?? ''));
        $cookies = trim((string)($requestData['cookies'] ?? $requestData['cookie'] ?? ''));
        $requestUrls = $this->normalizeCtripOverviewRequestUrls($requestData['request_urls'] ?? $requestData['requestUrls'] ?? $requestData['url'] ?? '');
        $payloadJson = trim((string)($requestData['payload_json'] ?? $requestData['payloadJson'] ?? ''));
        $method = strtoupper(trim((string)($requestData['method'] ?? 'GET')));
        $spidertoken = trim((string)($requestData['spidertoken'] ?? $requestData['token'] ?? ''));
        $dataDate = $this->normalizeOnlineDataDate($requestData['data_date'] ?? $requestData['dataDate'] ?? '');
        if ($dataDate === '') {
            $dataDate = date('Y-m-d', strtotime('-1 day'));
        }

        if ($cookies === '') {
            return $this->error('请提供携程 Cookie');
        }
        if (empty($requestUrls)) {
            return $this->error('请填写 Network 中今日概况相关 JSON 接口 Request URL');
        }
        if ($method !== 'GET' && $method !== 'POST') {
            return $this->error('今日概况接口请求方式仅支持 POST 或 GET');
        }

        try {
            $basePayload = $payloadJson !== '' ? $this->parseJsonParams($payloadJson) : [];
        } catch (\Throwable $e) {
            return $this->error('Payload JSON格式错误');
        }
        $basePayload = $this->buildCtripOverviewRequestPayload($basePayload, $hotelId, $dataDate);

        $responses = [];
        $errors = [];
        $xhrUrls = [];
        foreach ($requestUrls as $requestUrl) {
            if (preg_match('#/datacenter/inland/businessreport/outline(?:\?|$)#i', $requestUrl)) {
                $errors[] = '不能填写今日概况页面地址，请填写 Network 中的 JSON 接口 URL';
                continue;
            }
            if (!$this->isAllowedOtaRequestUrl($requestUrl, ['ctrip.com'])) {
                $errors[] = '仅允许请求携程官方域名: ' . $requestUrl;
                continue;
            }
            if (!$this->isCtripOverviewApiUrl($requestUrl)) {
                $errors[] = '今日概况接口 URL 必须命中指定接口: ' . $requestUrl;
                continue;
            }

            $result = $this->sendCtripOverviewRequest($requestUrl, $basePayload, $cookies, $method, $spidertoken);
            $xhrUrls[] = ['url' => $requestUrl, 'status' => (int)($result['http_code'] ?? 0), 'request_type' => strtolower($method)];
            if (!empty($result['error'])) {
                $errors[] = $result['error'];
                continue;
            }
            $responses[] = [
                'url' => $requestUrl,
                'section' => 'business',
                'status' => (int)($result['http_code'] ?? 200),
                'request_type' => strtolower($method),
                'data' => $result['decoded_data'] ?? [],
            ];
        }

        if (empty($responses) && !empty($errors)) {
            return $this->error('携程今日概况接口请求失败: ' . implode('；', array_slice($errors, 0, 3)), 400, [
                'errors' => $errors,
                'request_urls' => $requestUrls,
            ]);
        }

        $overviewHotelId = $hotelId !== ''
            ? $hotelId
            : $this->inferCtripOverviewHotelIdFromResponses($responses, $systemHotelId ? (string)$systemHotelId : '');

        $payload = [
            'hotel_id' => $overviewHotelId,
            'hotel_name' => $hotelName,
            'system_hotel_id' => $systemHotelId,
            'default_data_date' => $dataDate,
            'source' => 'ctrip_manual_overview',
            'captured_at' => date('Y-m-d H:i:s'),
            'responses' => $responses,
            'xhr_urls' => $xhrUrls,
        ];
        $overviewRows = $this->collectCtripOverviewRows($payload, $overviewHotelId, $dataDate);
        $savedCount = $this->saveCtripOverviewRows($overviewRows, $dataDate, $systemHotelId ? (int)$systemHotelId : null);

        if ($this->currentUser && isset($this->currentUser->id)) {
            OperationLog::record(
                'online_data',
                'fetch_ctrip_overview',
                'Fetch Ctrip today overview by manual cookie and API URL',
                $this->currentUser->id,
                $systemHotelId ? (int)$systemHotelId : null
            );
        }

        return $this->success([
            'data' => $overviewRows,
            'total' => count($overviewRows),
            'saved_count' => $savedCount,
            'row_count' => count($overviewRows),
            'counts' => ['overview' => count($overviewRows)],
            'metrics' => $this->summarizeCtripOverviewRows($overviewRows),
            'payload_counts' => [
                'responses' => count($responses),
                'xhr_urls' => count($xhrUrls),
            ],
            'request_urls' => $requestUrls,
            'errors' => $errors,
        ], $savedCount > 0 ? '携程今日概况获取完成并已入库' : '携程今日概况获取完成，但未解析到可入库概况数据');
    }

    private function resolveMeituanCaptureNodeBinary(): string
    {
        $configured = trim((string)(getenv('NODE_BINARY') ?: env('NODE_BINARY', '')));
        $candidates = array_filter([
            $configured,
            'C:\\Program Files\\nodejs\\node.exe',
            'C:\\Program Files (x86)\\nodejs\\node.exe',
            getenv('USERPROFILE') ? getenv('USERPROFILE') . '\\.cache\\codex-runtimes\\codex-primary-runtime\\dependencies\\node\\bin\\node.exe' : '',
            'node',
        ]);

        foreach ($candidates as $candidate) {
            if ($candidate === 'node' || is_file($candidate)) {
                return $candidate;
            }
        }
        return '';
    }

    private function countMeituanPayloadSection(array $payload, string $section): int
    {
        return isset($payload[$section]) && is_array($payload[$section]) ? count($payload[$section]) : 0;
    }

    private function countCtripPayloadSection(array $payload, string $section): int
    {
        return isset($payload[$section]) && is_array($payload[$section]) ? count($payload[$section]) : 0;
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
            'fetchVisitorTitleV2',
            'fetchCapacityOverViewV4',
            'queryFlowTransforNewV1',
            'getCompeteHotelReportV1',
            'getHotWordsV1',
            'getHotHotelsV1',
            'getFlowHotelsV1',
            'getHotRoomsV1',
            'getUserBehaviorV1',
            'getTrafficReportV1',
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
                ? 'Cookie 可能已失效，请重新登录携程 eBooking 后复制 Cookie'
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
                $rows[] = $this->buildCtripStringListOverviewRow($data, $requestHotelId, $dataDate, '_overview_hot_words', 'hot_words_count', 'top_hot_word');
                continue;
            }
            if (str_contains($url, 'gethothotelsv1')) {
                $rows[] = $this->buildCtripStringListOverviewRow($data, $requestHotelId, $dataDate, '_overview_hot_hotels', 'hot_hotels_count', 'top_hot_hotel');
                continue;
            }
            if (str_contains($url, 'getflowhotelsv1')) {
                $rows[] = $this->buildCtripFlowHotelsOverviewRow($data, $requestHotelId, $dataDate);
                continue;
            }
            if (str_contains($url, 'getuserbehaviorv1')) {
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

    private function buildCtripStringListOverviewRow($data, string $requestHotelId, string $dataDate, string $listKey, string $countKey, string $topKey): array
    {
        $items = is_array($data) ? array_values(array_filter(array_map('strval', $data), static fn(string $item): bool => trim($item) !== '')) : [];
        return $this->ctripOverviewBaseRow($requestHotelId, $dataDate, [
            $listKey => $items,
            $countKey => count($items),
            $topKey => $items[0] ?? '',
        ]);
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
            'top_hot_word' => '',
            'hot_hotels_count' => null,
            'top_hot_hotel' => '',
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
            $amount = $this->meituanNumber($row, ['amount', 'Amount', 'totalAmount', 'total_amount', 'saleAmount', 'orderAmount', 'gmv', 'turnover', 'bookingAmount', '成交收入', '成交金额', '销售额'], 0);
            $roomNights = (int)$this->meituanNumber($row, ['quantity', 'Quantity', 'roomNights', 'room_nights', 'checkOutQuantity', 'roomNightCount', 'nightNum', '成交间夜', '间夜', '房晚'], 0);
            $orders = (int)$this->meituanNumber($row, ['bookOrderNum', 'book_order_num', 'orderCount', 'order_count', 'orderNum', 'orders', 'bookings', '成交订单数', '订单数'], 0);

            $summary['amount'] += $amount;
            $summary['room_nights'] += $roomNights;
            $summary['order_count'] += $orders;
            $summary['yesterday_uv'] += (int)$this->meituanNumber($row, ['yesterday_uv', 'yesterdayUv', 'yesterdayUV', 'uv', 'UV', 'visitorCount', 'detailUv', 'totalDetailNum', 'visitors', '昨日UV', '访客数'], 0);
            $summary['competitor_uv'] += (int)$this->meituanNumber($row, ['competitor_uv', 'competitorUv', 'competitorUV', 'competeUv', 'competeUV', 'peerUv', 'peerUV', 'comhtluv', '竞品UV'], 0);
            $summary['competitor_orders'] += (int)$this->meituanNumber($row, ['competitor_orders', 'competitorOrders', 'competitorOrderNum', 'competeOrderNum', 'peerOrderNum', 'ordquantity', '竞品订单', '竞品订单数'], 0);
            $summary['competitor_amount'] += $this->meituanNumber($row, ['competitor_amount', 'competitorAmount', 'competitorRevenue', 'competeAmount', 'peerAmount', 'ordamount', '竞品收入', '竞品成交收入'], 0);
            $summary['favorite_count'] += (int)$this->meituanNumber($row, ['favorite_count', 'favoriteCount', 'collectCount', 'hotelCollect', '收藏数', '收藏量'], 0);
            $summary['self_list_exposure'] += (int)$this->meituanNumber($row, ['self_list_exposure'], 0);
            $summary['self_detail_exposure'] += (int)$this->meituanNumber($row, ['self_detail_exposure'], 0);
            $summary['self_order_filling_num'] += (int)$this->meituanNumber($row, ['self_order_filling_num'], 0);
            $summary['self_order_submit_num'] += (int)$this->meituanNumber($row, ['self_order_submit_num'], 0);
            $summary['competitor_list_exposure'] += (int)$this->meituanNumber($row, ['competitor_list_exposure'], 0);
            $summary['competitor_detail_exposure'] += (int)$this->meituanNumber($row, ['competitor_detail_exposure'], 0);
            $summary['competitor_order_filling_num'] += (int)$this->meituanNumber($row, ['competitor_order_filling_num'], 0);
            $summary['competitor_order_submit_num'] += (int)$this->meituanNumber($row, ['competitor_order_submit_num'], 0);

            foreach (array_keys($rankValues) as $rankKey) {
                $rank = (int)$this->meituanNumber($row, [$rankKey], 0);
                if ($rank > 0) {
                    $rankValues[$rankKey][] = $rank;
                }
            }
            foreach (['compete_hotel_count', 'hot_words_count', 'hot_hotels_count'] as $countKey) {
                $count = (int)$this->meituanNumber($row, [$countKey], 0);
                if ($count > 0) {
                    $summary[$countKey] = max((int)($summary[$countKey] ?? 0), $count);
                }
            }
            foreach (['top_hot_word', 'top_hot_hotel', 'top_flow_hotel', 'top_hot_room'] as $textKey) {
                $text = trim((string)($row[$textKey] ?? ''));
                if ($text !== '' && (string)($summary[$textKey] ?? '') === '') {
                    $summary[$textKey] = $text;
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
                $rawValue = $this->firstMeituanValue($row, [$intKey], null);
                if ($rawValue !== null) {
                    $value = (int)$this->meituanNumber($row, [$intKey], 0);
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
                $rawValue = $this->firstMeituanValue($row, [$numberKey], null);
                if ($rawValue !== null && $summary[$numberKey] === null) {
                    $summary[$numberKey] = $this->meituanNumber($row, [$numberKey], 0);
                }
            }
            foreach (['weekly_self', 'weekly_competitor', 'top_competitor'] as $prefix) {
                foreach (['list_exposure', 'detail_exposure', 'order_filling_num', 'order_submit_num'] as $metricKey) {
                    $summaryKey = $prefix . '_' . $metricKey;
                    $rawValue = $this->firstMeituanValue($row, [$summaryKey], null);
                    if ($rawValue !== null) {
                        $value = (int)$this->meituanNumber($row, [$summaryKey], 0);
                        $summary[$summaryKey] = (int)($summary[$summaryKey] ?? 0) + $value;
                    }
                }
                foreach (['flow_rate', 'order_fill_rate', 'deal_rate'] as $rateKey) {
                    $summaryKey = $prefix . '_' . $rateKey;
                    $value = $this->normalizeMeituanPercentValue($this->firstMeituanValue($row, [$summaryKey], null));
                    if ($value !== null && $summary[$summaryKey] === null) {
                        $summary[$summaryKey] = $value;
                    }
                }
            }

            $avgPrice = $this->meituanNumber($row, ['avg_price', 'avgPrice', 'averagePrice', 'adr', 'ADR', '均价', '平均房价'], 0);
            if ($avgPrice > 0) {
                $avgPriceValues[] = $avgPrice;
            }
            $conversionRate = $this->normalizeMeituanPercentValue($this->firstMeituanValue($row, ['closeRate', 'conversion_rate', 'conversionRate', 'convertionRate', 'bookRate', '成交率', '转化率'], null));
            if ($conversionRate !== null) {
                $conversionValues[] = $conversionRate;
            }
            $psi = $this->meituanNumber($row, ['psi', 'PSI', 'psiScore', 'serviceScore', 'service_score', 'PSI值'], 0);
            if ($psi > 0) {
                $psiValues[] = $psi;
            }
            $hotelScore = $this->meituanNumber($row, ['hotel_score', 'hotelScore', 'ctripRatingall', 'ctrip_rating_all', '酒店评分', '酒店点评分'], 0);
            if ($hotelScore > 0) {
                $hotelScoreValues[] = $hotelScore;
            }
            $replyRate = $this->normalizeMeituanPercentValue($this->firstMeituanValue($row, ['reply_rate', 'replyRate', 'replyrate5m', '回复率', '5分钟回复率'], null));
            if ($replyRate !== null) {
                $replyRateValues[] = $replyRate;
            }
            $visitorRank = (int)$this->meituanNumber($row, ['visitor_rank', 'visitorRank', 'uvRank', '访客排名'], 0);
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
        $summary['self_flow_rate'] = $this->trafficRate((float)$summary['self_detail_exposure'], (float)$summary['self_list_exposure']);
        $summary['self_order_fill_rate'] = $this->trafficRate((float)$summary['self_order_filling_num'], (float)$summary['self_detail_exposure']);
        $summary['self_deal_rate'] = $this->trafficRate((float)$summary['self_order_submit_num'], (float)$summary['self_order_filling_num']);
        $summary['competitor_flow_rate'] = $this->trafficRate((float)$summary['competitor_detail_exposure'], (float)$summary['competitor_list_exposure']);
        $summary['competitor_order_fill_rate'] = $this->trafficRate((float)$summary['competitor_order_filling_num'], (float)$summary['competitor_detail_exposure']);
        $summary['competitor_deal_rate'] = $this->trafficRate((float)$summary['competitor_order_submit_num'], (float)$summary['competitor_order_filling_num']);
        foreach (['weekly_self', 'weekly_competitor', 'top_competitor'] as $prefix) {
            if ($summary[$prefix . '_flow_rate'] === null && (float)($summary[$prefix . '_list_exposure'] ?? 0) > 0) {
                $summary[$prefix . '_flow_rate'] = $this->trafficRate((float)($summary[$prefix . '_detail_exposure'] ?? 0), (float)($summary[$prefix . '_list_exposure'] ?? 0));
            }
            if ($summary[$prefix . '_order_fill_rate'] === null && (float)($summary[$prefix . '_detail_exposure'] ?? 0) > 0) {
                $summary[$prefix . '_order_fill_rate'] = $this->trafficRate((float)($summary[$prefix . '_order_filling_num'] ?? 0), (float)($summary[$prefix . '_detail_exposure'] ?? 0));
            }
            if ($summary[$prefix . '_deal_rate'] === null && (float)($summary[$prefix . '_order_filling_num'] ?? 0) > 0) {
                $summary[$prefix . '_deal_rate'] = $this->trafficRate((float)($summary[$prefix . '_order_submit_num'] ?? 0), (float)($summary[$prefix . '_order_filling_num'] ?? 0));
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

    private function buildCtripAdsDirectPayload(array $payload, string $startDate, string $endDate, string $apiType): array
    {
        foreach ([
            'startDate' => $startDate,
            'endDate' => $endDate,
            'beginDate' => $startDate,
            'statStartDate' => $startDate,
            'statEndDate' => $endDate,
            'apiType' => $apiType,
        ] as $key => $value) {
            if (!array_key_exists($key, $payload) || $payload[$key] === '' || $payload[$key] === null) {
                $payload[$key] = $value;
            }
        }
        return $payload;
    }

    private function buildCtripAdsDateRange(string $dateRange, string $startDate, string $endDate, ?int $now = null): array
    {
        $today = $this->ctripAdsClock($now)->format('Y-m-d');
        $reportEndDate = $this->ctripAdsReportEndDate($now);
        switch ($dateRange) {
            case 'today_realtime':
            case 'today':
            case '0':
                return [$today, $today];
            case 'last_7_days':
            case '7':
                return [date('Y-m-d', strtotime($reportEndDate . ' -6 days')), $reportEndDate];
            case 'last_30_days':
            case '30':
                return [date('Y-m-d', strtotime($reportEndDate . ' -29 days')), $reportEndDate];
            case 'custom':
                if ($startDate === '' || $endDate === '') {
                    throw new \InvalidArgumentException('请选择自定义开始日期和结束日期');
                }
                break;
            case 'yesterday':
            case '1':
            default:
                $startDate = $reportEndDate;
                $endDate = $reportEndDate;
                break;
        }

        if (strtotime($startDate) === false || strtotime($endDate) === false || strtotime($startDate) > strtotime($endDate)) {
            throw new \InvalidArgumentException('日期范围无效');
        }
        return [$startDate, $endDate];
    }

    private function ctripAdsReportEndDate(?int $now = null): string
    {
        $clock = $this->ctripAdsClock($now);
        $offsetDays = (int)$clock->format('G') < 7 ? 2 : 1;
        return $clock->modify('-' . $offsetDays . ' days')->format('Y-m-d');
    }

    private function ctripAdsClock(?int $now = null): \DateTimeImmutable
    {
        $timezone = new \DateTimeZone('Asia/Shanghai');
        if ($now === null) {
            return new \DateTimeImmutable('now', $timezone);
        }
        return (new \DateTimeImmutable('@' . $now))->setTimezone($timezone);
    }

    private function isCtripAdsApiUrl(string $url): bool
    {
        $normalized = strtolower(trim($url));
        return str_contains($normalized, 'pyramidad')
            || str_contains($normalized, 'promotion')
            || str_contains($normalized, '/toolcenter/api/cpc/')
            || str_contains($normalized, 'querycampaignreportlist');
    }

    private function extractCtripCapturedAds(array $payload): array
    {
        $rows = [];
        foreach (['ads', 'advertising', 'adData'] as $key) {
            if (array_key_exists($key, $payload)) {
                $rows = array_merge($rows, $this->normalizeCtripCapturedAdList($payload[$key]));
            }
        }

        if (isset($payload['responses']) && is_array($payload['responses'])) {
            foreach ($payload['responses'] as $response) {
                if (!is_array($response)) {
                    continue;
                }
                $url = strtolower((string)($response['url'] ?? ''));
                $section = strtolower((string)($response['section'] ?? $response['type'] ?? ''));
                if ($section !== 'ads' && !$this->isCtripAdsApiUrl($url)) {
                    continue;
                }
                $data = $response['data'] ?? $response['body'] ?? $response['json'] ?? [];
                $rows = array_merge($rows, $this->normalizeCtripCapturedAdList($data));
            }
        }

        $seen = [];
        $deduped = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $identity = json_encode([
                $this->firstMeituanValue($row, ['campaignId', 'campaign_id', 'planId', 'plan_id', 'adId', 'id'], ''),
                $this->firstMeituanValue($row, ['campaign_name', 'campaignName', 'promotionName', 'planName', 'adName', 'name'], ''),
                $this->firstMeituanValue($row, ['stat_date', 'statDate', 'dataDate', 'date'], ''),
                $this->firstMeituanValue($row, ['exposure_count', 'exposureCount', 'exposure', 'impression'], ''),
                $this->firstMeituanValue($row, ['click_count', 'clickCount', 'clicks', 'click'], ''),
                $this->firstMeituanValue($row, ['cost_amount', 'costAmount', 'cost', 'consume', 'spend'], ''),
                $row['_dom_text'] ?? '',
            ], JSON_UNESCAPED_UNICODE);
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $deduped[] = $row;
        }

        return $deduped;
    }

    private function normalizeCtripCapturedAdList($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        if ($this->isSequentialArray($value)) {
            return array_values(array_filter($value, static fn($item): bool => is_array($item)));
        }

        $paths = [
            ['data', 'list'],
            ['data', 'rows'],
            ['data', 'items'],
            ['data', 'details'],
            ['data', 'campaignList'],
            ['data', 'promotionList'],
            ['data', 'adList'],
            ['data', 'records'],
            ['result', 'list'],
            ['result', 'rows'],
            ['result', 'items'],
            ['list'],
            ['rows'],
            ['items'],
            ['campaignList'],
            ['promotionList'],
            ['adList'],
            ['records'],
            ['data'],
        ];
        foreach ($paths as $path) {
            $nested = $this->readNestedMeituanValue($value, $path);
            if (is_array($nested)) {
                $rows = $this->normalizeCtripCapturedAdList($nested);
                if (!empty($rows)) {
                    return $rows;
                }
            }
        }

        return $this->looksLikeCtripCapturedAdRow($value) ? [$value] : [];
    }

    private function looksLikeCtripCapturedAdRow(array $value): bool
    {
        foreach (['exposure', 'exposureCount', 'impression', 'impressions', 'click', 'clickCount', 'clicks', 'cost', 'consume', 'spend', 'todayCost', 'cashCost', 'bonusCost', 'orderNum', 'bookingNum', 'bookings', 'campaignName', 'promotionName', 'planName', '曝光', '曝光量', '展现量', '点击', '点击量', '消耗', '费用', '花费', '预订量', '成交数', '推广名称', '计划名称'] as $key) {
            if (array_key_exists($key, $value)) {
                return true;
            }
        }
        return false;
    }

    private function buildCtripCapturedAdRows(array $ads, array $payload, ?int $systemHotelId = null): array
    {
        $context = [
            'system_hotel_id' => $systemHotelId,
            'hotel_id' => (string)$this->firstMeituanValue($payload, ['hotel_id', 'hotelId', 'profile_id', 'profileId'], ''),
            'hotel_name' => (string)$this->firstMeituanValue($payload, ['hotel_name', 'hotelName'], ''),
            'captured_at' => (string)$this->firstMeituanValue($payload, ['captured_at', 'capturedAt'], date('Y-m-d H:i:s')),
            'request_start_date' => (string)$this->firstMeituanValue($payload, ['request_start_date', 'requestStartDate'], ''),
            'request_end_date' => (string)$this->firstMeituanValue($payload, ['request_end_date', 'requestEndDate'], ''),
        ];
        $rows = [];
        foreach ($ads as $item) {
            if (!is_array($item)) {
                continue;
            }
            $row = $this->normalizeCtripCapturedAdRow($item, $context);
            if ($row !== null) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    private function normalizeCtripCapturedAdRow(array $item, array $context): ?array
    {
        $exposure = (int)$this->meituanNumber($item, ['exposure_count', 'exposureCount', 'exposure', 'impression', 'impressions', 'showNum', 'showCount', 'displayCount', 'pv', '曝光', '曝光量', '展现量', '展示量'], 0);
        $clicks = (int)$this->meituanNumber($item, ['click_count', 'clickCount', 'clickNum', 'clicks', 'click', '点击', '点击量'], 0);
        $orders = (int)$this->meituanNumber($item, ['booking_count', 'bookingCount', 'bookingNum', 'bookings', 'orderNum', 'order_count', 'orderCount', 'dealNum', 'transactionNum', 'conversionNum', '预订量', '预订数', '成交数', '成交量', '成交订单数'], 0);
        $nights = (int)$this->meituanNumber($item, ['nights', 'nightNum', 'roomNights', 'room_nights', 'quantity', '间夜', '成交间夜'], 0);
        $cost = $this->meituanNumber($item, ['cost_amount', 'costAmount', 'cost', 'todayCost', 'cashCost', 'consume', 'consumption', 'spend', 'fee', 'expense', 'amount', 'totalCost', '消耗', '费用', '花费', '广告费', '消耗金额', '消费金额'], 0.0);
        if ($exposure <= 0 && $clicks <= 0 && $orders <= 0 && $cost <= 0 && empty($item['_dom_text'])) {
            return null;
        }

        $dataDate = $this->normalizeOnlineDataDate($this->firstMeituanValue($item, ['effectTime', 'effect_time', 'stat_date', 'statDate', 'data_date', 'dataDate', 'date', 'reportDate', 'day', '日期', '统计日期'], ''))
            ?: ($this->normalizeOnlineDataDate($context['request_end_date'] ?? '') ?: date('Y-m-d'));
        $identity = (string)$this->firstMeituanValue($item, ['campaignId', 'campaign_id', 'planId', 'plan_id', 'adId', 'id', 'campaign_name', 'campaignName', 'promotionName', 'planName', 'adName', 'name', '推广名称', '计划名称', '广告名称', '广告计划'], '');
        if ($identity === '') {
            $identity = substr(md5(json_encode($item, JSON_UNESCAPED_UNICODE)), 0, 12);
        }

        $raw = $item;
        $raw['_capture_context'] = array_filter([
            'profile_id' => $context['hotel_id'] ?? '',
            'captured_at' => $context['captured_at'] ?? '',
            'request_start_date' => $context['request_start_date'] ?? '',
            'request_end_date' => $context['request_end_date'] ?? '',
        ], static fn($value): bool => $value !== null && $value !== '');

        return [
            'hotel_id' => (string)$this->firstMeituanValue($item, ['hotel_id', 'hotelId'], $context['hotel_id'] ?? ''),
            'hotel_name' => (string)$this->firstMeituanValue($item, ['hotel_name', 'hotelName'], $context['hotel_name'] ?? ''),
            'system_hotel_id' => $context['system_hotel_id'] ?? null,
            'data_date' => $dataDate,
            'amount' => round($cost, 2),
            'quantity' => $nights,
            'book_order_num' => $orders,
            'comment_score' => 0,
            'qunar_comment_score' => 0,
            'data_value' => $exposure,
            'source' => 'ctrip',
            'data_type' => 'advertising',
            'dimension' => 'ads:' . $identity,
            'platform' => 'Ctrip',
            'compare_type' => 'self',
            'list_exposure' => $exposure,
            'detail_exposure' => $clicks,
            'flow_rate' => round($this->trafficRate((float)$clicks, (float)$exposure), 2),
            'order_filling_num' => $clicks,
            'order_submit_num' => $orders,
            'raw_data' => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ];
    }

    private function summarizeCtripAdRows(array $rows): array
    {
        $summary = [
            'exposure' => 0,
            'clicks' => 0,
            'orders' => 0,
            'cost' => 0.0,
            'click_rate' => 0.0,
            'cost_per_click' => 0.0,
            'cost_per_order' => 0.0,
        ];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $summary['exposure'] += (int)($row['list_exposure'] ?? 0);
            $summary['clicks'] += (int)($row['detail_exposure'] ?? 0);
            $summary['orders'] += (int)($row['book_order_num'] ?? $row['order_submit_num'] ?? 0);
            $summary['cost'] += (float)($row['amount'] ?? 0);
        }
        $summary['cost'] = round($summary['cost'], 2);
        $summary['click_rate'] = round($this->trafficRate((float)$summary['clicks'], (float)$summary['exposure']), 2);
        $summary['cost_per_click'] = $summary['clicks'] > 0 ? round($summary['cost'] / $summary['clicks'], 2) : 0.0;
        $summary['cost_per_order'] = $summary['orders'] > 0 ? round($summary['cost'] / $summary['orders'], 2) : 0.0;
        return $summary;
    }

    private function saveCtripCapturedAdRows(array $rows): int
    {
        $columns = $this->getOnlineDailyDataColumns();
        $savedCount = 0;
        $now = date('Y-m-d H:i:s');
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['data_date']) || empty($row['data_type'])) {
                continue;
            }
            if (isset($columns['update_time'])) {
                $row['update_time'] = $now;
            }
            $query = Db::name('online_daily_data')
                ->where('source', 'ctrip')
                ->where('data_type', 'advertising')
                ->where('data_date', (string)$row['data_date'])
                ->where('dimension', (string)($row['dimension'] ?? ''));
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

    private function extractCtripCapturedSection(array $payload, string $section): array
    {
        $rows = [];
        foreach ($this->ctripCapturedSectionAliases($section) as $key) {
            if (array_key_exists($key, $payload)) {
                $rows = array_merge($rows, $this->normalizeCtripCapturedSectionList($payload[$key], $section));
            }
        }

        if (isset($payload['responses']) && is_array($payload['responses'])) {
            foreach ($payload['responses'] as $response) {
                if (!is_array($response) || !$this->ctripCaptureResponseMatchesSection($response, $section)) {
                    continue;
                }
                if ($section === 'business' && $this->isCtripOverviewSpecialResponse($response)) {
                    continue;
                }
                $data = $response['data'] ?? $response['body'] ?? $response['json'] ?? [];
                $rows = array_merge($rows, $this->normalizeCtripCapturedSectionList($data, $section));
            }
        }

        $seen = [];
        $deduped = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $identity = (string)($row['_fingerprint'] ?? md5(json_encode($row, JSON_UNESCAPED_UNICODE)));
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $deduped[] = $row;
        }
        return $deduped;
    }

    private function isCtripOverviewSpecialResponse(array $response): bool
    {
        $url = strtolower((string)($response['url'] ?? ''));
        foreach ([
            'getcompetehotelreportv1',
            'gethotwordsv1',
            'gethothotelsv1',
            'getflowhotelsv1',
            'getuserbehaviorv1',
            'gettrafficreportv1',
            'getlastweekreportv1',
        ] as $needle) {
            if (str_contains($url, $needle)) {
                return true;
            }
        }

        $data = $response['data'] ?? $response['body'] ?? $response['json'] ?? [];
        return is_array($data) && isset($data['data']['hotRooms']) && is_array($data['data']['hotRooms']);
    }

    private function ctripCapturedSectionAliases(string $section): array
    {
        return match ($section) {
            'business' => ['business', 'overview', 'hotelList', 'marketOverview'],
            'traffic' => ['traffic', 'businessData', 'peerTrends', 'rankList', 'categoryRankList', 'competitionRankList'],
            default => [$section],
        };
    }

    private function normalizeCtripCapturedSectionList($value, string $section): array
    {
        if (!is_array($value)) {
            return [];
        }
        if ($this->isSequentialArray($value)) {
            $rows = [];
            foreach ($value as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if ($this->looksLikeCtripCapturedSectionRow($item, $section)) {
                    $rows[] = $item;
                } else {
                    $rows = array_merge($rows, $this->normalizeCtripCapturedSectionList($item, $section));
                }
            }
            return $rows;
        }

        if ($this->looksLikeCtripCapturedSectionRow($value, $section)) {
            return [$value];
        }

        foreach ($this->ctripCapturedListPaths($section) as $path) {
            $nested = $this->readNestedMeituanValue($value, $path);
            if (is_array($nested)) {
                $rows = $this->normalizeCtripCapturedSectionList($nested, $section);
                if (!empty($rows)) {
                    return $rows;
                }
            }
        }

        $rows = [];
        foreach ($value as $nested) {
            if (is_array($nested)) {
                $rows = array_merge($rows, $this->normalizeCtripCapturedSectionList($nested, $section));
            }
        }
        return $rows;
    }

    private function ctripCapturedListPaths(string $section): array
    {
        return match ($section) {
            'traffic' => [
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
            ],
            default => [
                ['data', 'hotelList'],
                ['data', 'list'],
                ['data', 'rows'],
                ['data', 'overview'],
                ['data', 'marketOverview'],
                ['data', 'flowHotelItemVos'],
                ['data', 'hotRooms'],
                ['result', 'hotelList'],
                ['result', 'list'],
                ['hotelList'],
                ['list'],
                ['rows'],
                ['data'],
            ],
        };
    }

    private function looksLikeCtripCapturedSectionRow(array $value, string $section): bool
    {
        $keys = $section === 'traffic'
            ? $this->ctripTrafficRowKeys()
            : [
                'amount', 'totalAmount', 'saleAmount', '成交收入', '成交金额',
                'quantity', 'roomNights', '成交间夜',
                'bookOrderNum', 'orderCount', '订单数',
                'closeRate', 'averagePrice',
                'ordamount', 'ordquantity', 'comhtluv',
                'lossOrderVo', 'flowHotelItemVos', 'hotRooms',
                'lastWeekCommentScore', 'lastWeekCheckoutRoomNights', 'lastWeekBookQuantity',
                'totalListExposure', 'totalDetailExposure',
                'listExposure', 'detailExposure', 'flowRate', 'orderFillingNum', 'orderSubmitNum',
                'commentScore', 'uv', 'yesterdayUv', '昨日UV',
                'psi', 'PSI', 'serviceScore', 'ctripRatingall',
                'replyRate', 'replyrate5m', '回复率',
                'favoriteCount', 'hotelCollect', '收藏数',
                'visitorRank', '访客排名',
            ];
        foreach ($keys as $key) {
            if (array_key_exists($key, $value)) {
                return true;
            }
        }
        return false;
    }

    private function ctripTrafficRowKeys(): array
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

    private function ctripCaptureResponseMatchesSection(array $response, string $section): bool
    {
        $type = strtolower((string)($response['type'] ?? $response['section'] ?? ''));
        if ($type !== '' && in_array($type, $this->ctripCapturedSectionAliases($section), true)) {
            return true;
        }

        $url = strtolower((string)($response['url'] ?? ''));
        if ($url === '') {
            return false;
        }

        $needles = $section === 'traffic'
            ? ['queryscanflowdetailsv2', 'queryflowtransfornew', 'queryhomepagerealtimedata', 'getflowdata', 'gettrafficdata', 'getstatdata']
            : ['getdayreportrealtimedate', 'fetchmarketoverviewv2', 'getdayreportflowcompete', 'getdayreportserverquantity', 'fetchvisitortitlev2', 'fetchcapacityoverviewv4', 'queryflowtransfornewv1', 'getcompetehotelreportv1', 'gethotwordsv1', 'gethothotelsv1', 'getflowhotelsv1', 'gethotroomsv1', 'getuserbehaviorv1', 'gettrafficreportv1', 'getlastweekreportv1', 'getdayreportcompetehotelreport'];
        foreach ($needles as $needle) {
            if (str_contains($url, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function extractCtripCapturedComments(array $payload): array
    {
        $rows = [];
        foreach (['reviews', 'comments', 'commentList'] as $key) {
            if (array_key_exists($key, $payload)) {
                $rows = array_merge($rows, $this->normalizeCtripCapturedCommentList($payload[$key]));
            }
        }

        if (isset($payload['responses']) && is_array($payload['responses'])) {
            foreach ($payload['responses'] as $response) {
                if (!is_array($response)) {
                    continue;
                }
                $url = strtolower((string)($response['url'] ?? ''));
                $section = strtolower((string)($response['section'] ?? $response['type'] ?? ''));
                if ($section !== 'reviews' && !str_contains($url, 'getcommentlist')) {
                    continue;
                }
                $data = $response['data'] ?? $response['body'] ?? $response['json'] ?? [];
                $rows = array_merge($rows, $this->normalizeCtripCapturedCommentList($data));
            }
        }

        $seen = [];
        $deduped = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $identity = $this->extractCtripCommentId($row);
            if ($identity === '') {
                $identity = md5(json_encode([
                    $row['content'] ?? $row['commentContent'] ?? $row['_dom_text'] ?? '',
                    $row['user_name'] ?? $row['userName'] ?? '',
                    $row['comment_time'] ?? $row['commentTime'] ?? '',
                ], JSON_UNESCAPED_UNICODE));
            }
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $deduped[] = $row;
        }

        return $deduped;
    }

    private function normalizeCtripCapturedCommentList($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        if ($this->isSequentialArray($value)) {
            return array_values(array_filter($value, static fn($item): bool => is_array($item)));
        }

        $paths = [
            ['data', 'commentList'],
            ['data', 'comments'],
            ['data', 'list'],
            ['data', 'rows'],
            ['result', 'commentList'],
            ['result', 'comments'],
            ['result', 'list'],
            ['commentList'],
            ['comments'],
            ['list'],
            ['rows'],
            ['data'],
        ];
        foreach ($paths as $path) {
            $nested = $this->readNestedMeituanValue($value, $path);
            if (is_array($nested)) {
                $rows = $this->normalizeCtripCapturedCommentList($nested);
                if (!empty($rows)) {
                    return $rows;
                }
            }
        }

        return $this->looksLikeCtripCapturedCommentRow($value) ? [$value] : [];
    }

    private function looksLikeCtripCapturedCommentRow(array $value): bool
    {
        foreach (['review_id', 'reviewId', 'comment_id', 'commentId', 'id', 'commentContent', 'reviewContent', 'content', 'comment', 'score', 'rating', 'totalScore', 'commentScore'] as $key) {
            if (array_key_exists($key, $value)) {
                return true;
            }
        }
        return false;
    }

    private function resolveMeituanCaptureChromePath(): string
    {
        $configured = trim((string)(getenv('CHROME_PATH') ?: env('CHROME_PATH', '')));
        if ($configured !== '' && is_file($configured)) {
            return $configured;
        }
        return '';
    }

    private function runMeituanCaptureProcess(array $args, string $cwd, int $timeoutSeconds): array
    {
        $command = implode(' ', array_map('escapeshellarg', $args));
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            return ['success' => false, 'message' => '无法启动美团抓取进程', 'stdout' => '', 'stderr' => ''];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $startedAt = time();
        $timedOut = false;

        while (true) {
            $stdout .= (string)stream_get_contents($pipes[1]);
            $stderr .= (string)stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (time() - $startedAt > $timeoutSeconds) {
                $timedOut = true;
                proc_terminate($process);
                break;
            }
            usleep(250000);
        }

        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($timedOut) {
            return [
                'success' => false,
                'message' => '美团浏览器抓取超时，请确认弹出的浏览器已完成登录并能访问 eBooking 页面',
                'stdout' => $stdout,
                'stderr' => $stderr,
            ];
        }
        if ($exitCode !== 0 && $exitCode !== -1) {
            return [
                'success' => false,
                'message' => '美团浏览器抓取失败，退出码 ' . $exitCode,
                'stdout' => $stdout,
                'stderr' => $stderr,
            ];
        }

        return ['success' => true, 'message' => 'ok', 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function safeMeituanCaptureFilePart(string $value): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $value) ?: 'default';
        return substr($safe, 0, 80);
    }

    private function createAutoFetchCookieFile(string $projectRoot, string $platform, int $hotelId, string $cookies): string
    {
        $cookies = trim($cookies);
        if ($cookies === '') {
            return '';
        }

        $dir = $projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'ota_cookie_injection';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return '';
        }

        try {
            $suffix = bin2hex(random_bytes(6));
        } catch (\Throwable $e) {
            $suffix = str_replace('.', '', uniqid('', true));
        }

        $path = $dir . DIRECTORY_SEPARATOR . $this->safeMeituanCaptureFilePart($platform . '_' . $hotelId . '_' . $suffix) . '.txt';
        return file_put_contents($path, $cookies, LOCK_EX) === false ? '' : $path;
    }

    private function removeAutoFetchCookieFile(string $path): void
    {
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    private function trimMeituanCaptureLog(string $value): string
    {
        $value = trim($value);
        if (mb_strlen($value) <= 2000) {
            return $value;
        }
        return mb_substr($value, -2000);
    }

    private function buildMeituanCapturedDailyRows(array $payload, ?int $systemHotelId = null): array
    {
        $context = $this->buildMeituanCaptureContext($payload, $systemHotelId);
        $rows = [];

        foreach ($this->extractMeituanCapturedSection($payload, 'reviews') as $item) {
            $row = $this->normalizeMeituanCapturedReviewRow($item, $context);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        foreach ($this->extractMeituanCapturedSection($payload, 'traffic') as $item) {
            $row = $this->normalizeMeituanCapturedTrafficRow($item, $context);
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
            'ads' => ['cureShops', 'exposure_count', 'click_count', 'adId', 'campaignId'],
            'orders' => ['order_id', 'orderId', 'orderStatus', 'order_status', 'total_amount', 'totalAmount'],
            default => [],
        };
        foreach ($keys as $key) {
            if (array_key_exists($key, $value)) {
                return true;
            }
        }
        return false;
    }

    private function normalizeMeituanCapturedReviewRow(array $item, array $context): ?array
    {
        $reviewId = (string)$this->firstMeituanValue($item, ['review_id', 'reviewId', 'comment_id', 'commentId', 'id', 'orderId'], '');
        $content = (string)$this->firstMeituanValue($item, ['content', 'comment', 'commentContent', 'review_content'], '');
        $reply = (string)$this->firstMeituanValue($item, ['reply', 'bizReply', 'merchantReply', 'replyContent'], '');
        if ($reviewId === '' && $content === '' && $reply === '') {
            return null;
        }

        $score = $this->normalizeMeituanScore($this->firstMeituanValue($item, ['score', 'star', 'rating', 'totalScore'], 0));
        $dataDate = $this->normalizeOnlineDataDate($this->firstMeituanValue($item, ['review_time', 'reviewTime', 'commentTime', 'createTime', 'stay_date', 'stayDate'], ''))
            ?: ($context['default_data_date'] ?? date('Y-m-d'));
        $isNegative = $this->meituanBool($this->firstMeituanValue($item, ['is_negative', 'isNegative', 'badComment'], false))
            || ($score > 0 && $score < 3.0);
        $identity = $reviewId !== '' ? $reviewId : substr(md5(json_encode($item, JSON_UNESCAPED_UNICODE)), 0, 12);

        return $this->baseMeituanCapturedRow($item, $context, [
            'data_date' => $dataDate,
            'amount' => 0,
            'quantity' => 1,
            'book_order_num' => 0,
            'comment_score' => $score,
            'data_value' => $score,
            'data_type' => 'review',
            'dimension' => 'review:' . ($isNegative ? 'negative' : 'normal') . ':' . $identity,
        ]);
    }

    private function normalizeMeituanCapturedTrafficRow(array $item, array $context): ?array
    {
        $exposure = (int)$this->meituanNumber($item, ['exposure_count', 'exposureCount', 'listExposure', 'impression', 'impressions', 'exposure'], 0);
        $pageViews = (int)$this->meituanNumber($item, ['page_views', 'pageViews', 'detailExposure', 'detailVisitors', 'unique_visitors', 'uniqueVisitors', 'visitor_count', 'visitorCount', 'uv', 'UV', 'pv', 'views'], 0);
        $clicks = (int)$this->meituanNumber($item, ['click_count', 'clickCount', 'clickNum', 'clicks', 'click'], 0);
        $conversion = $this->normalizeMeituanPercentValue($this->firstMeituanValue($item, ['conversion_rate', 'conversionRate', 'flowRate', 'orderRate'], null));
        if ($conversion === null) {
            $conversion = $this->trafficRate((float)($pageViews ?: $clicks), (float)$exposure);
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

    private function normalizeMeituanCapturedAdsRow(array $item, array $context): ?array
    {
        $exposure = (int)$this->meituanNumber($item, ['exposure_count', 'exposureCount', 'impression', 'impressions', 'exposure'], 0);
        $clicks = (int)$this->meituanNumber($item, ['click_count', 'clickCount', 'clickNum', 'clicks', 'click'], 0);
        $conversion = $this->normalizeMeituanPercentValue($this->firstMeituanValue($item, ['conversion_rate', 'conversionRate', 'flowRate', 'orderRate'], null));
        if ($conversion === null) {
            $conversion = $this->trafficRate((float)$clicks, (float)$exposure);
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

    private function normalizeMeituanCapturedOrderRow(array $item, array $context): ?array
    {
        $orderId = (string)$this->firstMeituanValue($item, ['order_id', 'orderId', 'id'], '');
        $status = (string)$this->firstMeituanValue($item, ['order_status', 'orderStatus', 'status'], 'unknown');
        $amount = $this->meituanNumber($item, ['total_amount', 'totalAmount', 'amount', 'payAmount', 'pay_amount'], 0.0);
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

        $dataDate = $this->normalizeOnlineDataDate($this->firstMeituanValue($item, ['order_time', 'orderTime', 'createTime', 'check_in_date', 'checkInDate'], ''))
            ?: ($context['default_data_date'] ?? date('Y-m-d'));
        $identity = $orderId !== '' ? $orderId : substr(md5(json_encode($item, JSON_UNESCAPED_UNICODE)), 0, 12);

        return $this->baseMeituanCapturedRow($item, $context, [
            'data_date' => $dataDate,
            'amount' => round($amount, 2),
            'quantity' => $roomCount * $nights,
            'book_order_num' => (int)$this->meituanNumber($item, ['order_count', 'orderCount'], 1.0),
            'comment_score' => 0,
            'data_value' => $this->meituanNumber($item, ['avg_price', 'avgPrice'], $amount > 0 ? round($amount / ($roomCount * $nights), 2) : 0.0),
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
        $raw = $item;
        $raw['_capture_context'] = array_filter([
            'store_id' => $context['store_id'] ?? '',
            'poi_id' => $context['poi_id'] ?? '',
            'captured_at' => $context['captured_at'] ?? '',
        ], static fn($value): bool => $value !== null && $value !== '');

        return array_merge([
            'hotel_id' => $hotelId,
            'hotel_name' => $hotelName,
            'system_hotel_id' => $context['system_hotel_id'] ?? null,
            'source' => 'meituan',
            'qunar_comment_score' => 0,
            'raw_data' => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ], $fields);
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

            if (isset($columns['update_time'])) {
                $row['update_time'] = $now;
            }

            $query = Db::name('online_daily_data')
                ->where('source', 'meituan')
                ->where('data_type', (string)$row['data_type'])
                ->where('data_date', (string)$row['data_date'])
                ->where('dimension', (string)($row['dimension'] ?? ''));

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
        return round($this->normalizeTrafficPercent((float)$value), 2);
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
            return [
                'success' => false,
                'error' => '美团API返回错误: ' . ($businessMsg ?: "状态码: $businessCode"),
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

    private function normalizeMeituanManualDateRange(string $startDate, string $endDate): array
    {
        $start = $this->normalizeOnlineDataDate($startDate);
        $end = $this->normalizeOnlineDataDate($endDate);
        if ($start === '' && $end === '') {
            $start = date('Y-m-d', strtotime('-1 day'));
            $end = $start;
        } elseif ($start === '') {
            $start = $end;
        } elseif ($end === '') {
            $end = $start;
        }
        if (strtotime($start) === false || strtotime($end) === false || strtotime($start) > strtotime($end)) {
            throw new \InvalidArgumentException('日期范围无效');
        }
        return [$start, $end];
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
                ? 'Cookie 可能已失效或无权限，请重新登录美团后台后复制 Cookie'
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
    private function parseAndSaveMeituanData($responseData, $startDate, $endDate, ?int $systemHotelId = null): int
    {
        try {
            $dataList = [];
            
            if ($this->shouldLogOtaDebug()) {
                \think\facade\Log::info('美团数据解析 - 原始响应结构: ' . json_encode([
                    'keys' => array_keys($responseData),
                    'data_keys' => isset($responseData['data']) && is_array($responseData['data']) ? array_keys($responseData['data']) : 'not_array',
                    'data_type' => isset($responseData['data']) ? gettype($responseData['data']) : 'not_set',
                    'data_value_type' => isset($responseData['data']) ? gettype($responseData['data']) : 'not_set',
                ], JSON_UNESCAPED_UNICODE));
            }
            
            // 美团竞对排名数据结构解析
            // 实际结构: { data: { peerRankData: [{ dimName, roundRanks: [...], aiMetricName }] } }
            if (isset($responseData['data']['peerRankData']) && is_array($responseData['data']['peerRankData'])) {
                // 遍历每个榜单类型（入住间夜榜、交易额榜等）
                foreach ($responseData['data']['peerRankData'] as $rankData) {
                    if (isset($rankData['roundRanks']) && is_array($rankData['roundRanks'])) {
                        foreach ($rankData['roundRanks'] as $item) {
                            $item['_dimName'] = $rankData['dimName'] ?? '';
                            $item['_aiMetricName'] = $rankData['aiMetricName'] ?? '';
                            $dataList[] = $item;
                        }
                    }
                }
                \think\facade\Log::info('美团数据解析 - 使用结构: data.peerRankData[].roundRanks, 数量: ' . count($dataList));
            }
            // 结构2: { data: { roundrank: [...] } } - 竞对排名数据
            elseif (isset($responseData['data']['roundrank']) && is_array($responseData['data']['roundrank'])) {
                $dataList = $responseData['data']['roundrank'];
                \think\facade\Log::info('美团数据解析 - 使用结构2: data.roundrank, 数量: ' . count($dataList));
            }
            // 结构3: { data: { rankList: [...] } }
            elseif (isset($responseData['data']['rankList']) && is_array($responseData['data']['rankList'])) {
                $dataList = $responseData['data']['rankList'];
                \think\facade\Log::info('美团数据解析 - 使用结构3: data.rankList, 数量: ' . count($dataList));
            }
            // 结构4: { data: { list: [...] } }
            elseif (isset($responseData['data']['list']) && is_array($responseData['data']['list'])) {
                $dataList = $responseData['data']['list'];
                \think\facade\Log::info('美团数据解析 - 使用结构4: data.list, 数量: ' . count($dataList));
            }
            // 结构5: { data: [...] }
            elseif (isset($responseData['data']) && is_array($responseData['data'])) {
                if (isset($responseData['data'][0])) {
                    $dataList = $responseData['data'];
                    \think\facade\Log::info('美团数据解析 - 使用结构5: data是数组, 数量: ' . count($dataList));
                } else {
                    foreach ($responseData['data'] as $key => $value) {
                        if (is_array($value)) {
                            $dataList = array_merge($dataList, $value);
                        }
                    }
                    \think\facade\Log::info('美团数据解析 - 使用结构5展开: 数量: ' . count($dataList));
                }
            }
            // 结构6: { list: [...] }
            elseif (isset($responseData['list']) && is_array($responseData['list'])) {
                $dataList = $responseData['list'];
                \think\facade\Log::info('美团数据解析 - 使用结构6: list, 数量: ' . count($dataList));
            }
            // 结构7: { roundrank: [...] }
            elseif (isset($responseData['roundrank']) && is_array($responseData['roundrank'])) {
                $dataList = $responseData['roundrank'];
                \think\facade\Log::info('美团数据解析 - 使用结构7: roundrank, 数量: ' . count($dataList));
            }
            
            if (empty($dataList)) {
                // 无数据可解析，记录警告
                \think\facade\Log::warning('美团数据解析 - 未能解析到有效数据');
                return 0;
            }
            
            $savedCount = 0;
            $dataDate = $startDate ?: date('Y-m-d', strtotime('-1 day'));
            
            // 记录第一个数据项的字段结构
            if ($this->shouldLogOtaDebug() && !empty($dataList[0])) {
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
                
                // 美团竞对排名数据：根据榜单类型判断 dataValue 是销售额还是间夜数
                $dataValue = floatval($item['dataValue'] ?? $item['data_value'] ?? $item['monthRoomNights'] ?? $item['month_room_nights'] ?? 0);
                
                $itemDate = $item['date'] ?? $item['dataDate'] ?? $item['statDate'] ?? $item['stat_date'] ?? $dataDate;
                $dimName = $item['_dimName'] ?? ($item['dimension'] ?? '');
                $aiMetricName = $item['_aiMetricName'] ?? ($item['aiMetricName'] ?? '');
                
                // 判断榜单类型：P_XS=销售榜(包含销售间夜榜+销售额榜), P_RZ=入住榜, P_ZH=转化榜, P_LL=流量榜
                $rankType = $item['rankType'] ?? $item['rank_type'] ?? '';
                
                // 精确匹配子榜单类型 - 扩展关键词匹配
                // 结合dimName和aiMetricName进行判断，提高准确性
                $combinedName = $dimName . '|' . $aiMetricName;
                
                $isSalesAmountRank = strpos($combinedName, '销售额') !== false || strpos($combinedName, '交易额') !== false || strpos($combinedName, '房费收入') !== false || strpos($combinedName, '收入') !== false || strpos($combinedName, '金额') !== false;
                // 销售间夜榜：包含"间夜"但不包含"额"（避免与销售额混淆）
                // 同时检查aiMetricName，因为有些API返回的dimName可能是"销售榜"，而aiMetricName才是"销售间夜"
                $isRoomNightRank = (strpos($combinedName, '间夜') !== false && strpos($combinedName, '额') === false) || strpos($combinedName, '入住') !== false || strpos($combinedName, ' Nights') !== false || strpos($combinedName, 'nights') !== false || strpos($aiMetricName, '间夜') !== false;
                $isConversionRank = strpos($combinedName, '转化') !== false || strpos($combinedName, '支付') !== false || $rankType === 'P_ZH';
                $isTrafficRank = strpos($combinedName, '曝光') !== false || strpos($combinedName, '浏览') !== false || strpos($combinedName, '流量') !== false || strpos($combinedName, '访客') !== false || $rankType === 'P_LL';
                
                // 详细调试日志：记录每个数据项的判断过程
                \think\facade\Log::info("美团数据解析 - 详细判断: dimName=$dimName, rankType=$rankType, dataValue=$dataValue, isSalesAmountRank=" . ($isSalesAmountRank ? 'true' : 'false') . ", isRoomNightRank=" . ($isRoomNightRank ? 'true' : 'false'));
                \think\facade\Log::info("美团数据解析 - 完整数据项: " . json_encode($item, JSON_UNESCAPED_UNICODE));
                
                // 根据榜单类型设置 amount 和 quantity
                if ($isRoomNightRank) {
                    // 间夜榜（销售间夜榜、入住间夜榜）：dataValue 是间夜数
                    $amount = 0;
                    $quantity = intval($dataValue);
                } elseif ($isSalesAmountRank) {
                    // 销售额榜（交易额榜、房费收入榜）：dataValue 是销售额（元）
                    $amount = $dataValue;
                    $quantity = 0;
                } elseif ($isConversionRank || $isTrafficRank) {
                    // 转化榜和流量榜：dataValue 可能是百分比或次数，保存到 data_value
                    $amount = 0;
                    $quantity = 0;
                } else {
                    // 无法识别的榜单类型：根据数值大小智能判断
                    if ($dataValue > 10000) {
                        // 数值较大，可能是销售额
                        $amount = $dataValue;
                        $quantity = 0;
                    } else {
                        // 数值较小，可能是间夜数
                        $amount = 0;
                        $quantity = intval($dataValue);
                    }
                }

                // 详细记录榜单类型判断结果
                \think\facade\Log::info("美团数据解析 - 榜单判断: dimName=$dimName, rankType=$rankType, isSalesAmountRank=" . ($isSalesAmountRank ? 'true' : 'false') . ", isRoomNightRank=" . ($isRoomNightRank ? 'true' : 'false') . ", isConversionRank=" . ($isConversionRank ? 'true' : 'false') . ", isTrafficRank=" . ($isTrafficRank ? 'true' : 'false'));
                \think\facade\Log::info("美团数据解析 - 保存数据: hotelName=$hotelName, dataValue=$dataValue, amount=$amount, quantity=$quantity, dataDate=$itemDate, dimName=$dimName");

                // 检查是否已存在（按酒店名称、日期、来源、维度去重）
                $query = Db::name('online_daily_data')
                    ->where('hotel_name', $hotelName)
                    ->where('data_date', $itemDate)
                    ->where('source', 'meituan')
                    ->where('dimension', $dimName);

                if ($systemHotelId !== null) {
                    $query->where('system_hotel_id', $systemHotelId);
                }

                $exists = $query->find();
                
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
                    'dimension' => $dimName,
                    'data_type' => 'business',
                    'raw_data' => json_encode(['poiName' => $hotelName, 'dataValue' => $dataValue, 'rankType' => $rankType], JSON_UNESCAPED_UNICODE),
                ];
                $data = $this->applyOnlineDailyDataValidationFields($data);
                
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
            return 0;
        }
    }
    
    /**
     * 解析并保存数据到数据库
     * @param int|null $systemHotelId 系统酒店ID，用于门店隔离
     */
    private function parseAndSaveData($responseData, $startDate, $endDate, ?int $systemHotelId = null): int
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
        
        $savedCount = 0;
        $dataDate = $startDate ?: ($endDate ?: date('Y-m-d'));
        $columns = $this->getOnlineDailyDataColumns();
        $now = date('Y-m-d H:i:s');
        
        foreach ($dataList as $item) {
            if (!is_array($item)) continue;
            
            // 尝试多种字段名获取酒店ID
            $hotelId = $item['hotelId'] ?? $item['hotel_id'] ?? $item['HotelId'] ?? $item['hotelID'] ?? null;
            if (empty($hotelId)) continue;
            
            // 尝试多种字段名获取酒店名称
            $hotelName = $item['hotelName'] ?? $item['hotel_name'] ?? $item['HotelName'] ?? $item['name'] ?? '';
            
            // 尝试多种字段名获取其他数据
            $amount = $this->meituanNumber($item, ['amount', 'Amount', 'totalAmount', 'total_amount', 'saleAmount', 'orderAmount', 'gmv', 'turnover', 'bookingAmount', '成交收入', '成交金额', '销售额'], 0);
            $quantity = (int)$this->meituanNumber($item, ['quantity', 'Quantity', 'roomNights', 'room_nights', 'checkOutQuantity', 'roomNightCount', 'nightNum', '成交间夜', '间夜', '房晚'], 0);
            $bookOrderNum = (int)$this->meituanNumber($item, ['bookOrderNum', 'book_order_num', 'orderCount', 'order_count', 'orderNum', 'orders', 'bookings', '成交订单数', '订单数'], 0);
            $commentScore = floatval($item['commentScore'] ?? $item['comment_score'] ?? $item['score'] ?? $item['avgScore'] ?? $item['ctripRatingall'] ?? 0);
            $qunarCommentScore = floatval($item['qunarCommentScore'] ?? $item['qunar_comment_score'] ?? $item['qunarScore'] ?? 0);
            $listExposure = (int)$this->meituanNumber($item, ['self_list_exposure', 'listExposure', 'list_exposure'], 0);
            $detailExposure = (int)$this->meituanNumber($item, ['self_detail_exposure', 'detailExposure', 'detail_exposure'], 0);
            $orderFillingNum = (int)$this->meituanNumber($item, ['self_order_filling_num', 'orderFillingNum', 'order_filling_num'], 0);
            $orderSubmitNum = (int)$this->meituanNumber($item, ['self_order_submit_num', 'orderSubmitNum', 'order_submit_num'], 0);
            $flowRate = $this->normalizeMeituanPercentValue($this->firstMeituanValue($item, ['self_flow_rate', 'flowRate', 'flow_rate'], null)) ?? 0.0;
            
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
            
            // 检查是否已存在（按来源、系统酒店、平台酒店、日期去重）
            $query = Db::name('online_daily_data')
                ->where('source', 'ctrip')
                ->where('hotel_id', (string)$hotelId)
                ->where('data_date', $itemDate);
            
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
    public function fetchCustom(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');
        
        $url = $this->request->post('url', '');
        $method = $this->request->post('method', 'GET');
        $headers = $this->request->post('headers', '');
        $body = $this->request->post('body', '');
        
        if (empty($url)) {
            return $this->error('请提供URL');
        }
        if (!$this->isAllowedOtaRequestUrl($url, ['ctrip.com', 'meituan.com'])) {
            return $this->error('仅允许请求携程或美团官方域名');
        }
        
        try {
            $result = $this->sendCustomRequest($url, $method, $headers, $body);
            
            if ($result['success']) {
                OperationLog::record('online_data', 'fetch_custom', '获取自定义线上数据: ' . $url, $this->currentUser->id);
                return $this->success([
                    'data' => $result['data'],
                    'status' => $result['status'],
                    'headers' => $result['response_headers'],
                ]);
            } else {
                return $this->error('请求失败: ' . $result['error']);
            }
        } catch (\Exception $e) {
            return $this->error('请求异常: ' . $e->getMessage());
        }
    }
    
    /**
     * 接收来自书签脚本的Cookies（跨域请求）
     * 不需要常规认证，通过 Authorization 头中的 token 识别用户
     */
    public function receiveCookies(): Response
    {
        $origin = $this->resolveCookieCorsOrigin();
        if ($this->request->header('Origin', '') !== '' && $origin === '') {
            return json(['code' => 403, 'message' => 'Origin not allowed', 'data' => null], 403);
        }

        // 允许受信来源跨域请求
        if ($origin !== '') {
            header('Access-Control-Allow-Origin: ' . $origin);
        }
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Vary: Origin');
        
        if ($this->request->method() === 'OPTIONS') {
            return response('', 204, $this->cookieCorsHeaders($origin));
        }
        
        $token = $this->extractTokenFromAuthorizationHeader((string)$this->request->header('Authorization', ''));
        $name = $this->request->post('name', 'ctrip_auto');
        $cookies = $this->request->post('cookies', '');
        $source = $this->request->post('source', '');
        
        if (empty($token)) {
            return $this->corsError('缺少认证Token');
        }
        
        if (empty($cookies)) {
            return $this->corsError('Cookies内容为空');
        }
        
        // 验证token
        $tokenData = cache('token_' . $token);
        if (!$tokenData) {
            return $this->corsError('Token无效或已过期');
        }

        $userId = $this->resolveUserIdFromTokenData($tokenData);
        if ($userId === null) {
            return $this->corsError('Token认证信息无效');
        }
        
        // 保存Cookies配置
        $user = UserModel::find($userId);
        if (!$user) {
            return $this->corsError('Token user not found');
        }

        $hotelId = null;
        if ($user->isSuperAdmin()) {
            $requestHotelId = $this->request->post('hotel_id', $this->request->post('system_hotel_id', null));
            $hotelId = is_numeric($requestHotelId) && (int)$requestHotelId > 0 ? (int)$requestHotelId : null;
        } else {
            if (empty($user->hotel_id)) {
                return $this->corsError('User is not bound to a hotel');
            }
            $hotelId = (int)$user->hotel_id;
        }

        $key = $hotelId ? "online_data_cookies_hotel_{$hotelId}" : 'online_data_cookies_global';
        $list = $this->getConfigList($key);
        $list[$name] = [
            'name' => $name,
            'cookies' => $cookies,
            'source' => $source,
            'update_time' => date('Y-m-d H:i:s'),
            'user_id' => $userId,
            'hotel_id' => $hotelId,
            'system_hotel_id' => $hotelId,
        ];
        $this->setConfigList($key, $list);
        
        OperationLog::record('online_data', 'receive_cookies', '通过书签脚本获取Cookies: ' . $name, (int)$userId, $hotelId);
        
        return $this->corsSuccess([
            'name' => $name,
            'message' => 'Cookies已成功保存到系统',
        ]);
    }

    private function resolveUserIdFromTokenData($tokenData): ?int
    {
        $userId = is_array($tokenData)
            ? ($tokenData['user_id'] ?? $tokenData['id'] ?? null)
            : $tokenData;

        if (is_int($userId)) {
            return $userId > 0 ? $userId : null;
        }

        if (is_string($userId)) {
            $userId = trim($userId);
            return ctype_digit($userId) && (int)$userId > 0 ? (int)$userId : null;
        }

        return null;
    }

    private function extractTokenFromAuthorizationHeader(string $authHeader): string
    {
        $authHeader = trim($authHeader);
        if ($authHeader === '') {
            return '';
        }

        if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return trim($matches[1]);
        }

        return $authHeader;
    }

    private function resolveCookieCorsOrigin(): string
    {
        $origin = trim((string)$this->request->header('Origin', ''));
        if ($origin === '') {
            return '';
        }

        if (in_array($origin, $this->cookieAllowedOrigins(), true)) {
            return $origin;
        }

        $host = strtolower((string)parse_url($origin, PHP_URL_HOST));
        foreach (['.ctrip.com', '.ctripcorp.com', '.meituan.com', '.dianping.com'] as $suffix) {
            if ($host !== '' && str_ends_with($host, $suffix)) {
                return $origin;
            }
        }

        return '';
    }

    private function cookieAllowedOrigins(): array
    {
        $configured = trim((string)env('ONLINE_DATA_COOKIE_ALLOWED_ORIGINS', ''));
        $origins = $configured === '' ? [] : array_map('trim', explode(',', $configured));
        $origins[] = $this->request->scheme() . '://' . $this->request->host(true);
        $origins[] = 'https://ebooking.ctrip.com';
        $origins[] = 'https://eb.meituan.com';
        $origins[] = 'https://e.meituan.com';
        $origins[] = 'https://e.dianping.com';

        return array_values(array_unique(array_filter($origins)));
    }

    private function cookieCorsHeaders(?string $origin = null): array
    {
        $origin = $origin ?? $this->resolveCookieCorsOrigin();
        $headers = [
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
            'Vary' => 'Origin',
        ];
        if ($origin !== '') {
            $headers['Access-Control-Allow-Origin'] = $origin;
        }

        return $headers;
    }
    
    /**
     * 返回CORS错误响应
     */
    private function corsError(string $message, int $status = 400): Response
    {
        return json([
            'code' => $status,
            'message' => $message,
            'data' => null,
        ], $status)->header($this->cookieCorsHeaders());
    }
    
    /**
     * 返回CORS成功响应
     */
    private function corsSuccess(array $data): Response
    {
        return json([
            'code' => 200,
            'message' => '操作成功',
            'data' => $data,
        ])->header($this->cookieCorsHeaders());
    }
    
    /**
     * 获取书签脚本代码
     */
    public function bookmarklet(): Response
    {
        $this->checkPermission();
        
        $token = $this->extractTokenFromAuthorizationHeader((string)$this->request->header('Authorization', ''));
        if (empty($token)) {
            return $this->error('缺少Token', 401);
        }
        $script = $this->buildCookieBookmarkletScript($token, 'ctrip_auto');
        
        // 压缩脚本
        $script = preg_replace('/\s+/', ' ', $script);
        
        return $this->success([
            'script' => $script,
            'bookmarklet' => 'javascript:' . $script,
            'instructions' => [
                '1. 将下面的按钮拖拽到浏览器书签栏',
                '2. 在携程ebooking页面登录后，点击该书签',
                '3. 输入配置名称，Cookies将自动保存到系统',
            ],
        ]);
    }

    private function buildCookieBookmarkletScript(string $token, string $defaultName): string
    {
        $apiUrl = $this->request->domain() . '/api/online-data/receive-cookies';
        $apiUrlJson = json_encode($apiUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $authHeaderJson = json_encode('Bearer ' . $token, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $defaultNameJson = json_encode($defaultName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return <<<JAVASCRIPT
(function(){
  try{
    var cookies=document.cookie||'';
    if(!cookies){alert('未读取到 Cookie，请确认已登录当前 OTA 后台');return;}
    var name=prompt('请输入配置名称',{$defaultNameJson}+'_'+new Date().toLocaleDateString());
    if(!name){return;}
    var form=new FormData();
    form.append('name',name);
    form.append('cookies',cookies);
    form.append('source',location.hostname);
    fetch({$apiUrlJson},{
      method:'POST',
      mode:'cors',
      body:form,
      headers:{'Authorization':{$authHeaderJson}}
    }).then(function(response){return response.json();}).then(function(result){
      if(result.code===200){alert('Cookies 已保存：'+name);return;}
      alert('保存失败：'+(result.message||'未知错误'));
    }).catch(function(error){alert('请求失败：'+error.message);});
  }catch(error){
    alert('脚本执行失败：'+error.message);
  }
})();
JAVASCRIPT;
    }
    
    /**
     * 保存Cookies配置（按门店隔离）
     */
    public function saveCookies(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');
        
        $name = $this->request->post('name', '');
        $cookies = $this->request->post('cookies', '');
        $hotelId = $this->resolveOnlineDataSystemHotelId($this->request->post('hotel_id', null));
        
        if (empty($name) || empty($cookies)) {
            return $this->error('名称和Cookies不能为空');
        }
        
        // 非超级管理员只能保存自己酒店的Cookies
        if (!$this->currentUser->isSuperAdmin()) {
            $hotelId = $this->resolveOnlineDataSystemHotelId(null);
            if (empty($hotelId)) {
                return $this->error('您未关联酒店，无法保存Cookies');
            }
        }
        // 超级管理员可以选择酒店，也可以不选（保存全局Cookies）
        
        // 构建存储key（持久化到数据库）
        $key = $hotelId ? "online_data_cookies_hotel_{$hotelId}" : "online_data_cookies_global";
        $list = $this->getConfigList($key);
        $list[$name] = [
            'name' => $name,
            'cookies' => $cookies,
            'update_time' => date('Y-m-d H:i:s'),
            'hotel_id' => $hotelId ?: null,
        ];
        $this->setConfigList($key, $list);
        
        OperationLog::record('online_data', 'save_cookies', "保存Cookies配置: {$name}", $this->currentUser->id, $hotelId ? (int)$hotelId : null);
        
        return $this->success(null, 'Cookies保存成功');
    }
    
    /**
     * 获取已保存的Cookies列表（按门店隔离）
     */
    public function getCookiesList(): Response
    {
        $this->checkPermission();
        
        $hotelId = $this->request->get('hotel_id', '');
        
        // 非超级管理员只能查看自己酒店的Cookies
        if (!$this->currentUser->isSuperAdmin()) {
            $hotelId = $this->currentUser->hotel_id;
            if (empty($hotelId)) {
                return $this->success([]);
            }
            $key = "online_data_cookies_hotel_{$hotelId}";
            $list = $this->getConfigList($key);
            return $this->success(array_map([$this, 'sanitizeSecretConfig'], array_values($list)));
        }
        
        // 超级管理员查看所有Cookies（全局 + 所有酒店）
        $allCookies = [];
        
        // 获取全局Cookies
        $globalKey = "online_data_cookies_global";
        $globalList = $this->getConfigList($globalKey);
        foreach ($globalList as $item) {
            $allCookies[] = $item;
        }
        
        // 获取所有酒店的Cookies
        $hotels = \app\model\Hotel::select();
        foreach ($hotels as $hotel) {
            $key = "online_data_cookies_hotel_{$hotel->id}";
            $list = $this->getConfigList($key);
            foreach ($list as $item) {
                $allCookies[] = $item;
            }
        }
        
        return $this->success(array_map([$this, 'sanitizeSecretConfig'], $allCookies));
    }

    public function getCookiesDetail(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $name = trim((string)$this->request->get('name', ''));
        if ($name === '') {
            return $this->error('Cookies name is required.');
        }

        $hotelId = $this->resolveOnlineDataSystemHotelId($this->request->get('hotel_id', null));
        $keys = [];
        if ($hotelId) {
            $keys[] = "online_data_cookies_hotel_{$hotelId}";
        }
        if ($this->currentUser->isSuperAdmin()) {
            $keys[] = 'online_data_cookies_global';
        }

        foreach (array_unique($keys) as $key) {
            $list = $this->getConfigList($key);
            if (isset($list[$name])) {
                return $this->success($list[$name]);
            }
        }

        return $this->error('Cookies config not found.', 404);
    }
    
    /**
     * 删除Cookies配置（按门店隔离）
     */
    public function deleteCookies(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_delete_online_data');
        
        $name = $this->request->post('name', '');
        $hotelId = $this->resolveOnlineDataSystemHotelId($this->request->post('hotel_id', null));
        
        if (empty($name)) {
            return $this->error('名称不能为空');
        }
        
        // 非超级管理员只能删除自己酒店的Cookies
        if (!$this->currentUser->isSuperAdmin()) {
            $hotelId = $this->resolveOnlineDataSystemHotelId(null);
        }

        // 构建key
        $key = $hotelId ? "online_data_cookies_hotel_{$hotelId}" : "online_data_cookies_global";
        $list = $this->getConfigList($key);
        if (isset($list[$name])) {
            unset($list[$name]);
            $this->setConfigList($key, $list);
            return $this->success(null, '删除成功');
        }

        return $this->error('Cookies配置不存在');
    }

    /**
     * 批量删除Cookies配置（按门店隔离）
     */
    public function batchDeleteCookies(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_delete_online_data');

        $items = $this->request->post('items', []);
        if (empty($items) || !is_array($items)) {
            return $this->error('请选择要删除的Cookies配置');
        }

        $deletedCount = 0;
        $skippedCount = 0;
        $changedLists = [];
        $isSuperAdmin = $this->currentUser->isSuperAdmin();
        $userHotelId = $isSuperAdmin ? null : $this->resolveOnlineDataSystemHotelId(null);

        if (!$isSuperAdmin && empty($userHotelId)) {
            return $this->error('您未关联酒店，无法删除Cookies配置');
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                $skippedCount++;
                continue;
            }

            $name = trim((string)($item['name'] ?? ''));
            if ($name === '') {
                $skippedCount++;
                continue;
            }

            if ($isSuperAdmin) {
                $rawHotelId = $item['hotel_id'] ?? null;
                $hasHotelId = $rawHotelId !== null && trim((string)$rawHotelId) !== '';
                $hotelId = $this->resolveOnlineDataSystemHotelId($rawHotelId);
                if ($hasHotelId && empty($hotelId)) {
                    $skippedCount++;
                    continue;
                }
            } else {
                $hotelId = $userHotelId;
            }

            $key = $hotelId ? "online_data_cookies_hotel_{$hotelId}" : 'online_data_cookies_global';
            if (!array_key_exists($key, $changedLists)) {
                $changedLists[$key] = $this->getConfigList($key);
            }

            if (isset($changedLists[$key][$name])) {
                unset($changedLists[$key][$name]);
                $deletedCount++;
            } else {
                $skippedCount++;
            }
        }

        foreach ($changedLists as $key => $list) {
            $this->setConfigList($key, $list);
        }

        OperationLog::record('online_data', 'batch_delete_cookies', '批量删除Cookies配置: ' . $deletedCount . '条', $this->currentUser->id);

        return $this->success([
            'deleted_count' => $deletedCount,
            'skipped_count' => $skippedCount,
        ], $deletedCount > 0 ? '删除成功' : '未删除任何Cookies配置');
    }
    
    /**
     * 保存美团配置（API地址、Partner ID、POI ID、排名类型、时间维度、Cookies等）
     */
    public function saveMeituanConfig(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');
        
        $config = [
            'url' => $this->request->post('url', 'https://eb.meituan.com/api/v1/ebooking/business/peer/rank/data/detail'),
            'partner_id' => $this->request->post('partner_id', ''),
            'poi_id' => $this->request->post('poi_id', ''),
            'rank_type' => $this->request->post('rank_type', 'P_RZ'),
            'rank_types' => $this->request->post('rank_types', ['P_RZ']),
            'date_ranges' => $this->request->post('date_ranges', ['1']),
            'cookies' => $this->request->post('cookies', ''),
            'update_time' => date('Y-m-d H:i:s'),
        ];
        
        $hotelId = $this->resolveOnlineDataSystemHotelId($this->request->post('hotel_id', null));
        
        // 非超级管理员只能保存自己酒店的配置
        if (!$this->currentUser->isSuperAdmin()) {
            $hotelId = $this->resolveOnlineDataSystemHotelId(null);
        }
        
        // 构建存储key
        $key = $hotelId ? "meituan_config_hotel_{$hotelId}" : "meituan_config_global";
        SystemConfig::setValue($key, json_encode($config, JSON_UNESCAPED_UNICODE), '美团配置');
        
        return $this->success($config, '保存成功');
    }
    
    /**
     * 获取美团配置
     */
    public function getMeituanConfig(): Response
    {
        $this->checkPermission();
        
        $hotelId = $this->request->get('hotel_id', '');
        
        // 非超级管理员只能获取自己酒店的配置
        if (!$this->currentUser->isSuperAdmin()) {
            $hotelId = $this->currentUser->hotel_id;
        }
        
        // 优先查找酒店配置，再查找全局配置
        if ($hotelId) {
            $key = "meituan_config_hotel_{$hotelId}";
            $raw = SystemConfig::getValue($key, '');
            if ($raw) {
                $decoded = json_decode((string)$raw, true);
                if (is_array($decoded)) {
                    return $this->success($this->sanitizeSecretConfig($decoded));
                }
            }
        }
        
        // 查找全局配置
        $globalRaw = SystemConfig::getValue('meituan_config_global', '');
        $globalConfig = json_decode((string)$globalRaw, true);
        if (!is_array($globalConfig)) {
            $globalConfig = [
                'url' => 'https://eb.meituan.com/api/v1/ebooking/business/peer/rank/data/detail',
                'partner_id' => '',
                'poi_id' => '',
                'rank_type' => 'P_RZ',
                'rank_types' => ['P_RZ'],
                'date_ranges' => ['1'],
                'cookies' => '',
            ];
        }
        
        return $this->success($this->sanitizeSecretConfig($globalConfig));
    }

    /**
     * 保存美团配置（列表方式，支持多个配置）
     */
    public function saveMeituanConfigItem(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $id = $this->request->post('id', '');
        $name = $this->request->post('name', '');
        $partnerId = $this->request->post('partner_id', '');
        $poiId = $this->request->post('poi_id', '');
        $cookies = $this->request->post('cookies', '');
        $authDataStr = $this->request->post('auth_data', '');
        $hotelRoomCount = $this->request->post('hotel_room_count', '');
        $competitorRoomCount = $this->request->post('competitor_room_count', '');

        if (empty($name) || empty($cookies)) {
            return $this->error('配置名称和Cookies不能为空');
        }
        if (empty($partnerId)) {
            return $this->error('Partner ID不能为空');
        }
        if (empty($poiId)) {
            return $this->error('POI ID（门店ID）不能为空');
        }

        // 解析认证数据
        $authData = [];
        if (!empty($authDataStr)) {
            $authData = json_decode($authDataStr, true) ?: [];
        }

        $key = 'meituan_config_list';
        $raw = SystemConfig::getValue($key, '[]');
        $list = $raw ? json_decode($raw, true) : [];
        if (!is_array($list)) {
            $list = [];
        }

        // 生成唯一ID
        if (empty($id)) {
            $id = 'meituan_' . date('YmdHis') . '_' . substr(md5($name . time()), 0, 8);
        }
        $hotelId = $this->resolveOnlineDataSystemHotelId($this->request->post('hotel_id', $list[$id]['hotel_id'] ?? ($list[$id]['system_hotel_id'] ?? null)));
        $hotelIdValue = $hotelId !== null ? (string)$hotelId : '';

        // 非超级管理员可维护本人创建或本人酒店绑定的配置
        if (!empty($id) && isset($list[$id])) {
            if (!$this->isOtaConfigVisibleToCurrentUser($list[$id])) {
                return $this->error('无权修改此配置');
            }
        }

        // 非超级管理员删除时也只能删自己的
        $userId = $this->currentUser->isSuperAdmin() ? null : $this->currentUser->id;

        $config = [
            'id' => $id,
            'name' => $name,
            'hotel_id' => $hotelIdValue,
            'system_hotel_id' => $hotelId,
            'partner_id' => $partnerId,
            'poi_id' => $poiId,
            'cookies' => $cookies,
            'auth_data' => $authData,
            'hotel_room_count' => $hotelRoomCount,
            'competitor_room_count' => $competitorRoomCount,
            'user_id' => $userId,
            'update_time' => date('Y-m-d H:i:s'),
            'created_at' => $list[$id]['created_at'] ?? date('Y-m-d H:i:s'),
        ];
        $config = $this->normalizeOtaConfigHotelBinding($config, 'meituan');
        $list[$id] = $config;

        $encoded = json_encode($list, JSON_UNESCAPED_UNICODE);
        SystemConfig::setValue($key, $encoded, '美团配置列表');

        OperationLog::record('online_data', 'save_meituan_config', "保存美团配置: {$name}", $this->currentUser->id);

        return $this->success($this->sanitizeSecretConfig($config), '配置保存成功');
    }

    /**
     * 获取美团配置列表
     */
    public function getMeituanConfigList(): Response
    {
        // 仅检查登录状态，不强制要求酒店关联（配置读取不需要绑定酒店）
        if (!$this->currentUser || !$this->currentUser->id) {
            return json(['code' => 401, 'message' => '未登录']);
        }

        try {
            $key = 'meituan_config_list';
            $raw = SystemConfig::getValue($key, '[]');
            $list = $raw ? json_decode($raw, true) : [];
            if (!is_array($list)) {
                $list = [];
            }
            $list = $this->normalizeStoredOtaConfigList('system_config', $key, $list, 'meituan');

            $list = $this->filterOtaConfigListForCurrentUser($list);

            usort($list, function($a, $b) {
                return strcmp($b['update_time'] ?? '', $a['update_time'] ?? '');
            });

            return $this->success(array_map([$this, 'sanitizeSecretConfig'], array_values($list)));
        } catch (\Throwable $e) {
            \think\facade\Log::error('获取美团配置列表失败: ' . $e->getMessage(), ['exception' => $e]);
            return $this->error('获取美团配置列表失败', 500);
        }
    }

    public function getMeituanConfigDetail(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $id = trim((string)$this->request->get('id', ''));
        if ($id === '') {
            return $this->error('Config id is required.');
        }

        $key = 'meituan_config_list';
        $raw = SystemConfig::getValue($key, '[]');
        $list = $raw ? json_decode((string)$raw, true) : [];
        if (!is_array($list)) {
            $list = [];
        }
        $list = $this->normalizeStoredOtaConfigList('system_config', $key, $list, 'meituan');

        if (!isset($list[$id])) {
            return $this->error('Config not found.', 404);
        }
        if (!$this->isOtaConfigVisibleToCurrentUser($list[$id])) {
            return $this->error('Forbidden', 403);
        }

        return $this->success($list[$id]);
    }

    /**
     * 删除美团配置
     */
    public function deleteMeituanConfig(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_delete_online_data');

        $id = $this->request->param('id', '');
        if (empty($id)) {
            return $this->error('请提供配置ID');
        }

        $key = 'meituan_config_list';
        $raw = SystemConfig::getValue($key, '[]');
        $list = $raw ? json_decode($raw, true) : [];
        if (!is_array($list)) {
            $list = [];
        }
        $list = $this->normalizeStoredOtaConfigList('system_config', $key, $list, 'meituan');

        if (!isset($list[$id])) {
            return $this->error('配置不存在');
        }

        if (!$this->isOtaConfigVisibleToCurrentUser($list[$id])) {
            return $this->error('无权删除此配置');
        }

        $name = $list[$id]['name'] ?? '';
        unset($list[$id]);
        $encoded = json_encode($list, JSON_UNESCAPED_UNICODE);
        SystemConfig::setValue($key, $encoded, '美团配置列表');

        OperationLog::record('online_data', 'delete_meituan_config', "删除美团配置: {$name}", $this->currentUser->id);

        return $this->success(null, '删除成功');
    }

    /**
     * 保存美团点评配置（优化版）
     */
    public function saveMeituanCommentConfig(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $name = $this->request->post('name', '');
        $partnerId = $this->request->post('partner_id', '');
        $poiId = $this->request->post('poi_id', '');
        $mtsiEbU = $this->request->post('mtsi_eb_u', $this->request->post('_mtsi_eb_u', ''));
        $cookies = $this->request->post('cookies', '');
        $mtgsig = $this->request->post('mtgsig', '');
        $requestUrl = $this->request->post('request_url', '');

        if (empty($name)) {
            return $this->error('配置名称不能为空');
        }
        if (empty($partnerId) || empty($poiId)) {
            return $this->error('Partner ID 和 POI ID 不能为空');
        }
        if ($requestUrl !== '' && !$this->isAllowedOtaRequestUrl($requestUrl, ['meituan.com'])) {
            return $this->error('仅允许保存美团官方域名请求地址');
        }

        // 获取配置列表
        $key = 'meituan_comment_config_list';
        $list = $this->getConfigList($key);

        $id = md5($name . $partnerId . $poiId);
        $originalConfig = $list[$id] ?? [];
        $systemHotelId = $this->resolveOnlineDataSystemHotelId($this->request->post('system_hotel_id', $originalConfig['system_hotel_id'] ?? null));
        $userId = $this->currentUser->isSuperAdmin() ? ($originalConfig['user_id'] ?? null) : $this->currentUser->id;
        $list[$id] = [
            'id' => $id,
            'name' => $name,
            'partner_id' => $partnerId,
            'poi_id' => $poiId,
            'system_hotel_id' => $systemHotelId,
            'user_id' => $userId,
            'mtsi_eb_u' => $mtsiEbU,
            'cookies' => $cookies,
            'mtgsig' => $mtgsig,
            'request_url' => $requestUrl,
            'update_time' => date('Y-m-d H:i:s'),
            'created_at' => $list[$id]['created_at'] ?? date('Y-m-d H:i:s'),
        ];

        $this->setConfigList($key, $list);

        OperationLog::record('online_data', 'save_meituan_comment_config', "保存美团点评配置: {$name}", $this->currentUser->id);

        return $this->success($this->sanitizeSecretConfig($list[$id]), '配置保存成功');
    }

    /**
     * 获取美团点评配置列表
     */
    public function getMeituanCommentConfigList(): Response
    {
        $this->checkPermission();

        $key = 'meituan_comment_config_list';
        $list = $this->getConfigList($key);
        $list = $this->filterOtaConfigListForCurrentUser($list);

        // 按更新时间降序排序
        $list = array_values($list);
        usort($list, function($a, $b) {
            $timeA = $a['update_time'] ?? $a['created_at'] ?? '1970-01-01 00:00:00';
            $timeB = $b['update_time'] ?? $b['created_at'] ?? '1970-01-01 00:00:00';
            return strcmp($timeB, $timeA);
        });

        return $this->success(array_map([$this, 'sanitizeSecretConfig'], $list));
    }

    /**
     * 生成美团一键获取Cookies书签脚本
     */
    public function generateMeituanBookmarklet(): Response
    {
        $this->checkPermission();

        // 获取当前用户的token
        $token = $this->request->header('Authorization', '');
        if (empty($token)) {
            $userId = $this->currentUser->id;
            $cacheKey = 'user_token_' . $userId;
            $token = cache($cacheKey) ?? '';
        }

        $apiBase = $this->request->domain() . '/api/online-data';

        $script = <<<JAVASCRIPT
(function(){
  try{
    var h=location.hostname;
    if(h.indexOf('eb.meituan.com')===-1){
      alert('请先打开美团ebooking页面！当前页面: '+h);
      return;
    }
    var c=document.cookie;
    if(!c){alert('未检测到Cookies，请先登录美团ebooking');return;}
    var authData={};
    try{
      for(var i=0;i<localStorage.length;i++){
        var k=localStorage.key(i);
        if(k.indexOf('token')!==-1||k.indexOf('auth')!==-1||k.indexOf('user')!==-1){
          authData[k]=localStorage.getItem(k);
        }
      }
    }catch(e){}
    var n=prompt('请输入配置名称：','美团配置_'+new Date().toLocaleDateString());
    if(!n)return;
    var d=new FormData();
    d.append('name',n);
    d.append('cookies',c);
    d.append('auth_data',JSON.stringify(authData));
    fetch('{$apiBase}/save-meituan-config-item',{
      method:'POST',
      body:d,
      mode:'cors',
      headers:{'Authorization':'{$token}'}
    }).then(function(r){return r.json()}).then(function(j){
      if(j.code===200){
        alert('保存成功！配置名: '+n);
      }else{
        alert('保存失败: '+j.message);
      }
    }).catch(function(e){
      alert('请求失败: '+e.message);
    });
  }catch(err){
    alert('脚本执行错误: '+err.message);
  }
})();
JAVASCRIPT;

        // 压缩脚本（移除换行符）
        $script = preg_replace('/\s+/', ' ', $script);
        $script = str_replace([' (function', ' {', '} ', ' ;'], ['(function', '{', '}', ';'], $script);

        return $this->success([
            'script' => $script,
            'bookmarklet' => 'javascript:' . $script,
        ]);
    }

    /**
     * 保存携程配置
     */
    public function saveCtripConfig(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        try {
            $id = trim((string)$this->request->post('id', ''));
            $name = trim((string)$this->request->post('name', ''));
            $cookies = (string)$this->request->post('cookies', '');

            if ($name === '' || trim($cookies) === '') {
                return json(['code' => 400, 'message' => '配置名称和Cookies不能为空']);
            }

            // 读取现有配置，编辑时复用原 ID
            $key = 'ctrip_config_list';
            $existing = \think\facade\Db::name('system_configs')->where('config_key', $key)->find();
            $list = [];
            if ($existing) {
                $list = json_decode($existing['config_value'], true) ?: [];
            }

            if ($id !== '') {
                if (!isset($list[$id])) {
                    return $this->error('配置不存在');
                }
                if (!$this->isOtaConfigVisibleToCurrentUser($list[$id])) {
                    return $this->error('无权修改此配置');
                }
            } else {
                $id = 'ctrip_' . date('YmdHis') . '_' . substr(md5($name . time()), 0, 8);
            }

            // 非超级管理员保存时记录 user_id；超级管理员编辑旧配置时保留原归属
            $originalConfig = $list[$id] ?? [];
            $userId = $this->currentUser->isSuperAdmin() ? ($originalConfig['user_id'] ?? null) : $this->currentUser->id;
            $resolvedHotelId = $this->resolveOnlineDataSystemHotelId($this->request->post('hotel_id', $originalConfig['hotel_id'] ?? ($originalConfig['system_hotel_id'] ?? null)));
            $hotelIdValue = $resolvedHotelId !== null ? (string)$resolvedHotelId : '';

            $config = array_merge($originalConfig, [
                'id' => $id,
                'name' => $name,
                'cookies' => $cookies,
                'hotel_id' => $hotelIdValue,
                'system_hotel_id' => $resolvedHotelId,
                'url' => $this->request->post('url', $originalConfig['url'] ?? ''),
                'node_id' => $this->request->post('node_id', $originalConfig['node_id'] ?? ''),
                'user_id' => $userId,
                'update_time' => date('Y-m-d H:i:s'),
                'created_at' => $originalConfig['created_at'] ?? date('Y-m-d H:i:s'),
            ]);
            $config = $this->normalizeOtaConfigHotelBinding($config, 'ctrip');

            $list[$id] = $config;
            
            $jsonValue = json_encode($list, JSON_UNESCAPED_UNICODE);
            
            if ($existing) {
                \think\facade\Db::name('system_configs')->where('config_key', $key)->update([
                    'config_value' => $jsonValue,
                    'update_time' => date('Y-m-d H:i:s'),
                ]);
            } else {
                \think\facade\Db::name('system_configs')->insert([
                    'config_key' => $key,
                    'config_value' => $jsonValue,
                    'description' => '携程配置列表',
                    'create_time' => date('Y-m-d H:i:s'),
                    'update_time' => date('Y-m-d H:i:s'),
                ]);
            }

            return json(['code' => 200, 'message' => '配置保存成功', 'data' => $this->sanitizeSecretConfig($config)]);
        } catch (\Exception $e) {
            \think\facade\Log::error('保存携程配置异常: ' . $e->getMessage());
            return json(['code' => 500, 'message' => '保存失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 获取携程配置列表
     */
    public function getCtripConfigList(): Response
    {
        // 仅检查登录状态，不强制要求酒店关联（配置读取不需要绑定酒店）
        if (!$this->currentUser || !$this->currentUser->id) {
            return json(['code' => 401, 'message' => '未登录']);
        }

        try {
            $key = 'ctrip_config_list';
            $raw = \think\facade\Db::name('system_configs')->where('config_key', $key)->value('config_value');
            $list = $raw ? json_decode($raw, true) : [];
            if (!is_array($list)) {
                $list = [];
            }
            $list = $this->normalizeStoredOtaConfigList('system_configs', $key, $list, 'ctrip');

            $list = $this->filterOtaConfigListForCurrentUser($list);

            usort($list, function($a, $b) {
                return strcmp($b['update_time'] ?? '', $a['update_time'] ?? '');
            });

            return $this->success(array_map([$this, 'sanitizeSecretConfig'], array_values($list)));
        } catch (\Throwable $e) {
            \think\facade\Log::error('获取携程配置列表失败: ' . $e->getMessage(), ['exception' => $e]);
            return $this->error('获取携程配置列表失败', 500);
        }
    }

    public function getCtripConfigDetail(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $id = trim((string)$this->request->get('id', ''));
        if ($id === '') {
            return $this->error('Config id is required.');
        }

        $key = 'ctrip_config_list';
        $raw = \think\facade\Db::name('system_configs')->where('config_key', $key)->value('config_value');
        $list = $raw ? json_decode((string)$raw, true) : [];
        if (!is_array($list)) {
            $list = [];
        }
        $list = $this->normalizeStoredOtaConfigList('system_configs', $key, $list, 'ctrip');

        if (!isset($list[$id])) {
            return $this->error('Config not found.', 404);
        }
        if (!$this->isOtaConfigVisibleToCurrentUser($list[$id])) {
            return $this->error('Forbidden', 403);
        }

        return $this->success($list[$id]);
    }

    /**
     * 删除携程配置
     */
    public function deleteCtripConfig(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_delete_online_data');

        $id = $this->request->param('id', '');
        if (empty($id)) {
            return $this->error('配置ID不能为空');
        }

        $key = 'ctrip_config_list';
        $existing = \think\facade\Db::name('system_configs')->where('config_key', $key)->find();
        if (!$existing) {
            return $this->error('配置不存在');
        }

        $list = json_decode($existing['config_value'], true) ?: [];
        $list = $this->normalizeStoredOtaConfigList('system_configs', $key, $list, 'ctrip');

        if (!isset($list[$id])) {
            return $this->error('配置不存在');
        }

        if (!$this->isOtaConfigVisibleToCurrentUser($list[$id])) {
            return $this->error('无权删除此配置');
        }

        $name = $list[$id]['name'] ?? '';
        unset($list[$id]);
        \think\facade\Db::name('system_configs')->where('config_key', $key)->update([
            'config_value' => json_encode($list, JSON_UNESCAPED_UNICODE),
            'update_time' => date('Y-m-d H:i:s'),
        ]);

        OperationLog::record('online_data', 'delete_ctrip_config', "删除携程配置: {$name}", $this->currentUser->id);

        return $this->success(null, '删除成功');
    }

    /**
     * 生成携程一键获取Cookies书签脚本
     */
    public function generateCtripBookmarklet(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $token = $this->extractTokenFromAuthorizationHeader((string)$this->request->header('Authorization', ''));
        if ($token === '') {
            return $this->error('缺少Token', 401);
        }

        $script = $this->buildCookieBookmarkletScript($token, 'ctrip_config');
        $script = preg_replace('/\s+/', ' ', $script);

        return $this->success([
            'script' => $script,
            'bookmarklet' => 'javascript:' . $script,
        ]);
    }

    /**
     * 自动捕获携程Cookie（从请求头中获取）
     */
    public function autoCaptureCtripCookie(): Response
    {
        // 允许跨域请求
        header('Access-Control-Allow-Origin: https://ebooking.ctrip.com');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Allow-Credentials: true');

        // 处理 OPTIONS 预检请求
        if ($this->request->method() === 'OPTIONS') {
            return $this->success([]);
        }

        // 从请求头中获取 Cookie
        $cookieHeader = $this->request->header('cookie', '');

        if (empty($cookieHeader)) {
            return $this->error('未能获取到Cookie，请确保在携程页面执行此操作');
        }

        // 检查关键 Cookie
        if (strpos($cookieHeader, 'usertoken') === false && strpos($cookieHeader, 'usersign') === false) {
            return $this->error('Cookie中缺少关键认证信息(usertoken/usersign)，请确保已登录携程ebooking');
        }

        return $this->error('此方法已弃用，请使用书签脚本');
    }

    /**
     * 通过书签保存携程配置（接收Cookie数据）
     */
    public function saveCtripConfigByBookmark(): Response
    {
        // 允许跨域请求
        header('Access-Control-Allow-Origin: https://ebooking.ctrip.com');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Allow-Credentials: true');

        // 处理 OPTIONS 预检请求
        if ($this->request->method() === 'OPTIONS') {
            return $this->success([]);
        }

        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        // 获取请求数据
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);

        if (!$data) {
            return $this->error('无效的请求数据');
        }

        $name = $data['name'] ?? '携程配置_' . date('Y-m-d');
        $cookies = $data['cookies'] ?? '';
        $authData = $data['auth_data'] ?? [];
        $uid = $data['uid'] ?? null;
        $resolvedHotelId = $this->resolveOnlineDataSystemHotelId($data['hotel_id'] ?? ($data['system_hotel_id'] ?? null));
        $hotelIdValue = $resolvedHotelId !== null ? (string)$resolvedHotelId : '';
        $userId = $this->currentUser->isSuperAdmin() ? null : $this->currentUser->id;

        if (empty($cookies)) {
            return $this->error('Cookie不能为空');
        }

        // 获取配置列表
        $key = 'ctrip_config_list';
        $list = $this->getConfigList($key);

        // 生成唯一ID
        $id = 'ctrip_' . date('YmdHis') . '_' . substr(md5($name . time()), 0, 8);

        // 解析Cookie为对象
        $cookieObj = [];
        $cookiePairs = explode(';', $cookies);
        foreach ($cookiePairs as $pair) {
            $kv = array_map('trim', explode('=', $pair, 2));
            if (count($kv) === 2) {
                $cookieObj[$kv[0]] = $kv[1];
            }
        }

        // 构建完整的auth_data
        if (empty($authData)) {
            $authData = [
                'cookieObj' => $cookieObj,
                'xCtxCurrency' => $cookieObj['cookiePricesDisplayed'] ?? 'CNY',
                'xCtxLocale' => 'zh-CN',
                'xCtxUbtPageid' => $cookieObj['GUID'] ?? '',
                'xCtxUbtVid' => $cookieObj['UBT_VID'] ?? '',
                'xCtxUbtSid' => '',
                'xCtxUbtPvid' => '',
                'xCtxWclientReq' => substr(md5(uniqid()), 0, 32),
            ];
        }

        $config = [
            'id' => $id,
            'name' => $name,
            'hotel_id' => $hotelIdValue,
            'system_hotel_id' => $resolvedHotelId,
            'hotel_name' => '',
            'url' => 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportCompeteHotelReport',
            'node_id' => '24588',
            'cookies' => $cookies,
            'auth_data' => $authData,
            'user_id' => $userId,
            'update_time' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $config = $this->normalizeOtaConfigHotelBinding($config, 'ctrip');
        $list[$id] = $config;

        $this->setConfigList($key, $list);

        // 检查关键Cookie
        $hasUsertoken = strpos($cookies, 'usertoken') !== false;
        $hasUsersign = strpos($cookies, 'usersign') !== false;

        return $this->success([
            'id' => $id,
            'has_usertoken' => $hasUsertoken,
            'has_usersign' => $hasUsersign,
        ], '配置保存成功');
    }

    /**
     * 发送HTTP请求到携程ebooking（使用file_get_contents）
     */
    private function sendHttpRequest(string $url, array $postData, string $cookies, array $authData = []): array
    {
        if (!$this->isAllowedOtaRequestUrl($url, ['ctrip.com'])) {
            return ['success' => false, 'error' => '仅允许请求携程官方域名'];
        }

        // 从authData中提取cookieObj
        $cookieObj = $authData['cookieObj'] ?? [];
        
        $headers = [
            'Accept: */*',
            'Accept-Encoding: gzip, deflate, br, zstd',
            'Accept-Language: zh-CN,zh;q=0.9',
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            'Origin: https://ebooking.ctrip.com',
            'Referer: https://ebooking.ctrip.com/',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36',
            'Cookie: ' . $cookies,
            'cookieorigin: https://ebooking.ctrip.com',
            'sec-ch-ua: "Not:A-Brand";v="99", "Google Chrome";v="145", "Chromium";v="145"',
            'sec-ch-ua-mobile: ?0',
            'sec-ch-ua-platform: "Windows"',
            'sec-fetch-dest: empty',
            'sec-fetch-mode: cors',
            'sec-fetch-site: same-origin',
        ];
        
        // x-ctx 系列请求头 - 必须的
        $headers[] = 'x-ctx-currency: ' . ($authData['xCtxCurrency'] ?? $cookieObj['cookiePricesDisplayed'] ?? 'CNY');
        $headers[] = 'x-ctx-locale: ' . ($authData['xCtxLocale'] ?? 'zh-CN');
        $headers[] = 'x-ctx-ubt-pageid: ' . ($authData['xCtxUbtPageid'] ?? $cookieObj['GUID'] ?? '');
        $headers[] = 'x-ctx-ubt-vid: ' . ($authData['xCtxUbtVid'] ?? $cookieObj['UBT_VID'] ?? '');
        $headers[] = 'x-ctx-ubt-sid: ' . ($authData['xCtxUbtSid'] ?? '');
        $headers[] = 'x-ctx-ubt-pvid: ' . ($authData['xCtxUbtPvid'] ?? '');
        $headers[] = 'x-ctx-wclient-req: ' . ($authData['xCtxWclientReq'] ?? substr(md5(uniqid()), 0, 32));
        
        $headerStr = implode("\r\n", $headers);
        
        $formContent = http_build_query($postData);
        
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $headerStr,
                'content' => $formContent,
                'timeout' => 30,
                'ignore_errors' => true,
                'follow_location' => 0, // 不跟随重定向
            ],
            'ssl' => $this->buildStreamSslOptions(),
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            $error = error_get_last();
            return [
                'success' => false,
                'error' => $error['message'] ?? 'Unknown error',
            ];
        }
        
        // 解压gzip响应
        $decodedResponse = $response;
        if (substr($response, 0, 2) === "\x1f\x8b") {
            $decodedResponse = gzdecode($response);
        }
        
        // 获取HTTP响应码
        $httpCode = 0;
        if (isset($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('/HTTP\/\d+\.?\d*\s+(\d+)/', $header, $matches)) {
                    $httpCode = (int)$matches[1];
                    break;
                }
            }
        }
        
        // 检查HTTP响应码
        if ($httpCode !== 200) {
            return [
                'success' => false,
                'error' => "HTTP错误: {$httpCode}" . ($httpCode === 302 ? ' (可能Cookie已失效，请重新登录携程)' : ''),
                'http_code' => $httpCode,
                'raw' => $decodedResponse,
            ];
        }
        
        // 检查是否返回了HTML而不是JSON
        if (preg_match('/^\s*<!DOCTYPE|^\s*<html/i', $decodedResponse)) {
            return [
                'success' => false,
                'error' => '返回了HTML页面而非JSON数据（可能Cookie已过期或请求参数不一致，请重新获取）',
                'http_code' => $httpCode,
                'raw' => substr($decodedResponse, 0, 500),
            ];
        }
        
        $decoded = json_decode($decodedResponse, true);
        
        // JSON解析失败
        if ($decoded === null && !empty($decodedResponse)) {
            return [
                'success' => false,
                'error' => 'JSON解析失败: ' . json_last_error_msg(),
                'http_code' => $httpCode,
                'raw' => substr($decodedResponse, 0, 500),
            ];
        }
        
        return [
            'success' => true,
            'data' => $decoded,
            'raw' => $decodedResponse,
            'http_code' => $httpCode,
        ];
    }

    /**
     * 发送携程流量 JSON 请求
     */
    private function sendCtripJsonRequest(string $url, array $postData, string $cookies): array
    {
        $emptyResult = [
            'http_code' => 0,
            'raw_response' => '',
            'decoded_data' => null,
            'error' => '',
        ];

        if (!$this->isAllowedOtaRequestUrl($url, ['ctrip.com'])) {
            return array_merge($emptyResult, ['error' => '仅允许请求携程官方域名']);
        }

        if (!function_exists('curl_init')) {
            return array_merge($emptyResult, ['error' => '服务器未启用 cURL，无法请求携程流量接口']);
        }

        $jsonPayload = json_encode($postData, JSON_UNESCAPED_UNICODE);
        if ($jsonPayload === false) {
            return array_merge($emptyResult, ['error' => '请求 Body JSON 编码失败: ' . json_last_error_msg()]);
        }

        $headers = [
            'Accept: application/json, text/javascript, */*; q=0.01',
            'Accept-Encoding: gzip, deflate, br',
            'Accept-Language: zh-CN,zh;q=0.9',
            'Content-Type: application/json',
            'Origin: https://ebooking.ctrip.com',
            'Referer: https://ebooking.ctrip.com/datacenter/inland/businessreport/flowdata?microJump=true',
            'X-Requested-With: XMLHttpRequest',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'Cookie: ' . $cookies,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING => '',
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => $this->shouldVerifyOtaSsl(),
            CURLOPT_SSL_VERIFYHOST => $this->shouldVerifyOtaSsl() ? 2 : 0,
        ]);

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
                'error' => '请求携程流量接口失败: ' . ($curlError ?: 'cURL 错误 ' . $curlErrno),
            ];
        }

        $result = [
            'http_code' => $httpCode,
            'raw_response' => $rawResponse,
            'decoded_data' => null,
            'error' => '',
        ];

        if ($httpCode !== 200) {
            if (in_array($httpCode, [301, 302], true)) {
                $result['error'] = 'Cookie 可能已失效，请重新登录携程 eBooking 后复制 Cookie';
            } elseif ($httpCode === 415) {
                $result['error'] = '携程流量接口必须使用 JSON Body，请检查 Content-Type 和 POSTFIELDS';
            } else {
                $result['error'] = '携程流量接口 HTTP 错误: ' . $httpCode;
            }
            return $result;
        }

        if (preg_match('/^\s*<!DOCTYPE|^\s*<html/i', $rawResponse)) {
            $result['error'] = '携程接口返回异常，请检查 Cookie / 日期参数';
            return $result;
        }

        $decodedData = json_decode($rawResponse, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $result['error'] = '携程接口返回异常，请检查 Cookie / 日期参数';
            return $result;
        }

        $result['decoded_data'] = $decodedData;
        return $result;
    }

    private function sendCtripAdsRequest(string $url, array $params, string $cookies, string $method = 'POST'): array
    {
        $emptyResult = [
            'http_code' => 0,
            'raw_response' => '',
            'decoded_data' => null,
            'request_url' => $url,
            'error' => '',
        ];

        if (!$this->isAllowedOtaRequestUrl($url, ['ctrip.com'])) {
            return array_merge($emptyResult, ['error' => '仅允许请求携程官方域名']);
        }
        if (!$this->isCtripAdsApiUrl($url)) {
            return array_merge($emptyResult, ['error' => '金字塔广告接口 URL 必须来自 Network 中 pyramidad / promotion 的 XHR 或 fetch 请求']);
        }
        if (!function_exists('curl_init')) {
            return array_merge($emptyResult, ['error' => '服务器未启用 cURL，无法请求携程广告接口']);
        }

        $method = strtoupper($method) === 'GET' ? 'GET' : 'POST';
        $requestUrl = $url;
        $jsonPayload = '';
        if ($method === 'GET') {
            if (!empty($params)) {
                $query = http_build_query($params);
                $requestUrl .= (str_contains($requestUrl, '?') ? '&' : '?') . $query;
            }
        } else {
            $jsonPayload = json_encode($params, JSON_UNESCAPED_UNICODE);
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
            'Referer: https://ebooking.ctrip.com/toolcenter/cpc/pyramid',
            'X-Requested-With: XMLHttpRequest',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'Cookie: ' . $cookies,
        ];

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
        } else {
            $options[CURLOPT_HTTPGET] = true;
        }
        curl_setopt_array($ch, $options);

        $rawResponse = curl_exec($ch);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $result = [
            'http_code' => $httpCode,
            'raw_response' => $rawResponse === false ? '' : (string)$rawResponse,
            'decoded_data' => null,
            'request_url' => $requestUrl,
            'error' => '',
        ];

        if ($rawResponse === false) {
            $result['error'] = '请求携程广告接口失败: ' . ($curlError ?: 'cURL 错误 ' . $curlErrno);
            return $result;
        }
        if ($httpCode !== 200) {
            if (in_array($httpCode, [301, 302], true)) {
                $result['error'] = 'Cookie 可能已失效，请重新登录携程 eBooking 后复制 Cookie';
            } else {
                $result['error'] = '携程广告接口 HTTP 错误: ' . $httpCode;
            }
            return $result;
        }
        if (preg_match('/^\s*<!DOCTYPE|^\s*<html/i', (string)$rawResponse)) {
            $result['error'] = '携程广告接口返回了页面 HTML。请填写 Network 中 pyramidad / promotion 的 JSON 请求 URL，而不是金字塔广告页面地址';
            return $result;
        }

        $decodedData = json_decode((string)$rawResponse, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $result['error'] = '携程广告接口返回异常，JSON 解析失败: ' . json_last_error_msg();
            return $result;
        }

        $result['decoded_data'] = $decodedData;
        return $result;
    }

    /**
     * 识别携程流量接口业务错误
     */
    private function getCtripTrafficApiError($responseData): string
    {
        if (!is_array($responseData)) {
            return '';
        }

        $code = $responseData['code'] ?? $responseData['resultCode'] ?? $responseData['status'] ?? null;
        $message = $responseData['message']
            ?? $responseData['msg']
            ?? $responseData['errorMessage']
            ?? $responseData['error_description']
            ?? $responseData['error']
            ?? '';

        if (isset($responseData['success']) && $responseData['success'] === false) {
            return '携程流量接口返回失败: ' . ($message ?: '未知错误');
        }

        if (isset($responseData['error'])) {
            return '携程流量接口返回异常: ' . ($message ?: (string)$responseData['error']);
        }

        if ($code !== null && !in_array((string)$code, ['0', '200', 'success', 'SUCCESS'], true)) {
            $error = '携程流量接口返回异常: ' . ($message ?: ('code=' . (string)$code));
            if (preg_match('/登录|过期|权限|未授权|unauthorized|forbidden/i', (string)$message)) {
                $error .= '，请重新登录携程后台复制Cookie';
            }
            return $error;
        }

        $ack = $responseData['ResponseStatus']['Ack'] ?? null;
        if ($ack !== null && !in_array((string)$ack, ['Success', 'SUCCESS'], true)) {
            $errorMessage = $responseData['ResponseStatus']['Errors'][0]['Message'] ?? $message ?: '未知错误';
            return '携程流量接口返回异常: ' . $errorMessage;
        }

        return '';
    }
    
    /**
     * 发送自定义HTTP请求（使用file_get_contents）
     */
    private function normalizeCtripTrafficUrl(string $url): string
    {
        return OtaTrafficUrlNormalizer::normalizeCtripTrafficUrl($url);
    }

    private function buildCtripTrafficDateRange(string $dateRange, string $startDate, string $endDate, ?int $now = null): array
    {
        $baseTime = $now ?? time();
        $today = date('Y-m-d', $baseTime);
        $settledEndDate = date('Y-m-d', strtotime('-1 day', $baseTime));
        switch ($dateRange) {
            case 'today_realtime':
            case 'today':
            case '0':
                return [$today, $today];
            case 'last_7_days':
            case '7':
                return [date('Y-m-d', strtotime($settledEndDate . ' -6 days')), $settledEndDate];
            case 'last_30_days':
            case '30':
                return [date('Y-m-d', strtotime($settledEndDate . ' -29 days')), $settledEndDate];
            case 'custom':
                if ($startDate === '' || $endDate === '') {
                    throw new \InvalidArgumentException('请选择自定义开始日期和结束日期');
                }
                break;
            case 'yesterday':
            case '1':
            default:
                if ($startDate === '' || $endDate === '') {
                    return [$settledEndDate, $settledEndDate];
                }
                break;
        }

        if (strtotime($startDate) === false || strtotime($endDate) === false || strtotime($startDate) > strtotime($endDate)) {
            throw new \InvalidArgumentException('日期范围无效');
        }
        return [$startDate, $endDate];
    }

    private function extractCtripTrafficRows($responseData): array
    {
        if (!is_array($responseData)) {
            return [];
        }
        return $this->extractCtripTrafficRowsRecursive($responseData);
    }

    private function extractCtripTrafficRowsRecursive(array $value, int $depth = 0): array
    {
        if ($depth > 8) {
            return [];
        }

        if ($this->isSequentialArray($value)) {
            $rows = [];
            foreach ($value as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if ($this->looksLikeCtripTrafficDataRow($item)) {
                    $rows[] = $item;
                } else {
                    $rows = array_merge($rows, $this->extractCtripTrafficRowsRecursive($item, $depth + 1));
                }
            }
            return $rows;
        }

        $expandedRows = $this->expandCtripTrafficDailySeries($value);
        if (!empty($expandedRows)) {
            return $expandedRows;
        }

        if ($this->looksLikeCtripTrafficDataRow($value)) {
            return [$value];
        }

        foreach ($this->ctripCapturedListPaths('traffic') as $path) {
            $nested = $this->readNestedMeituanValue($value, $path);
            if (is_array($nested)) {
                $rows = $this->extractCtripTrafficRowsRecursive($nested, $depth + 1);
                if (!empty($rows)) {
                    return $rows;
                }
            }
        }

        $rows = [];
        foreach ($value as $nested) {
            if (is_array($nested)) {
                $rows = array_merge($rows, $this->extractCtripTrafficRowsRecursive($nested, $depth + 1));
            }
        }
        return $rows;
    }

    private function expandCtripTrafficDailySeries(array $value): array
    {
        $dates = $this->readCtripTrafficDateSeries($value);
        if (empty($dates)) {
            return [];
        }

        $groups = $this->collectCtripTrafficSeriesGroups($value);
        if (empty($groups)) {
            $groups = [[
                'data' => $value,
                'compare_type' => $this->resolveCtripTrafficCompareType($value),
            ]];
        }

        $rows = [];
        foreach ($groups as $group) {
            $groupData = is_array($group['data'] ?? null) ? $group['data'] : [];
            $compareType = (string)($group['compare_type'] ?? $this->resolveCtripTrafficCompareType($groupData));
            $hotelId = $groupData['hotelId'] ?? $groupData['hotel_id'] ?? $groupData['nodeId'] ?? $groupData['node_id'] ?? null;

            foreach ($dates as $index => $date) {
                if (strtotime((string)$date) === false) {
                    continue;
                }

                $row = [
                    'date' => date('Y-m-d', strtotime((string)$date)),
                    'compareType' => $compareType,
                    'listExposure' => (int)$this->readCtripTrafficSeriesMetric($groupData, $index, [
                        ['listExposure'], ['list_exposure'], ['totalListExposure'], ['exposure'], ['exposureCount'], ['impressions'], ['showCount'], ['PV'], ['pv'], ['pageView'], ['pageViews'], ['page_view'],
                    ]),
                    'detailExposure' => (int)$this->readCtripTrafficSeriesMetric($groupData, $index, [
                        ['detailExposure'], ['detail_exposure'], ['totalDetailExposure'], ['detailVisitors'], ['detailUv'], ['visitorCount'], ['UV'], ['uv'], ['uniqueVisitors'], ['unique_visitors'], ['views'],
                    ]),
                    'flowRate' => round($this->normalizeTrafficPercent($this->readCtripTrafficSeriesMetric($groupData, $index, [
                        ['flowRate'], ['flow_rate'], ['listTransforDetailRate'], ['conversionRate'], ['conversion_rate'], ['convertionRate'], ['convertRate'], ['transforRate'], ['transferRate'], ['transRate'], ['cvr'],
                    ], null)), 2),
                    'orderFillingNum' => (int)$this->readCtripTrafficSeriesMetric($groupData, $index, [
                        ['orderFillingNum'], ['order_filling_num'], ['orderVisitors'], ['clickCount'], ['click_count'], ['clickNum'], ['clicks'],
                    ]),
                    'orderSubmitNum' => (int)$this->readCtripTrafficSeriesMetric($groupData, $index, [
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

                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function readCtripTrafficDateSeries(array $value): array
    {
        return $this->readCtripTrafficSeries($value, [
            ['dateList'], ['date_list'], ['dates'], ['dataDates'], ['data_dates'], ['statDates'], ['stat_dates'],
            ['xAxis', 'data'], ['xaxis', 'data'], ['xAxisData'], ['x_axis_data'], ['categories'], ['labels'],
        ]);
    }

    private function collectCtripTrafficSeriesGroups(array $value): array
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

    private function resolveCtripTrafficCompareType(array $value): string
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

    private function readCtripTrafficSeriesMetric(array $value, int $index, array $paths, ?float $default = 0.0): ?float
    {
        $series = $this->readCtripTrafficSeries($value, $paths);
        if (isset($series[$index])) {
            $number = $this->coerceTrafficNumber($series[$index]);
            if ($number !== null) {
                return $number;
            }
        }

        return $default;
    }

    private function readCtripTrafficSeries(array $value, array $paths): array
    {
        foreach ($paths as $path) {
            $series = $this->readNestedMeituanValue($value, $path);
            if (is_array($series)) {
                if ($this->isSequentialArray($series)) {
                    return $series;
                }
                if (isset($series['data']) && is_array($series['data']) && $this->isSequentialArray($series['data'])) {
                    return $series['data'];
                }
                if (isset($series['value']) && is_array($series['value']) && $this->isSequentialArray($series['value'])) {
                    return $series['value'];
                }
            }
        }
        return [];
    }

    private function looksLikeCtripTrafficDataRow(array $value): bool
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

        foreach ($this->ctripTrafficRowKeys() as $key) {
            if (array_key_exists($key, $value)) {
                return true;
            }
        }
        return false;
    }

    private function getOnlineDailyDataColumns(): array
    {
        static $columns = null;
        if ($columns !== null) {
            return $columns;
        }
        $rows = Db::query('SHOW COLUMNS FROM online_daily_data');
        $columns = array_fill_keys(array_column($rows, 'Field'), true);
        return $columns;
    }

    private function filterOnlineDailyDataFields(array $data): array
    {
        $columns = $this->getOnlineDailyDataColumns();
        return array_intersect_key($data, $columns);
    }

    private function buildOnlineDailyDataValidationFields(array $data): array
    {
        $flags = [];
        foreach (['source', 'hotel_id', 'data_date'] as $field) {
            if (!isset($data[$field]) || trim((string)$data[$field]) === '') {
                $flags[] = [
                    'level' => 'error',
                    'field' => $field,
                    'message' => $field . ' is missing',
                ];
            }
        }

        foreach (['amount', 'quantity', 'book_order_num', 'data_value'] as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === '' || $data[$field] === null) {
                continue;
            }
            if (!is_numeric($data[$field])) {
                $flags[] = [
                    'level' => 'error',
                    'field' => $field,
                    'message' => $field . ' must be numeric',
                ];
                continue;
            }
            if ((float)$data[$field] < 0) {
                $flags[] = [
                    'level' => 'error',
                    'field' => $field,
                    'message' => $field . ' must not be negative',
                ];
            }
        }

        $amount = isset($data['amount']) && is_numeric($data['amount']) ? (float)$data['amount'] : 0.0;
        $quantity = isset($data['quantity']) && is_numeric($data['quantity']) ? (float)$data['quantity'] : null;
        if ($amount > 0 && $quantity === 0.0) {
            $flags[] = [
                'level' => 'warning',
                'field' => 'quantity',
                'message' => 'amount exists but quantity is zero',
            ];
        }

        $hasError = array_reduce($flags, static fn(bool $carry, array $flag): bool => $carry || ($flag['level'] ?? '') === 'error', false);
        return [
            'validation_status' => $hasError ? 'abnormal' : (empty($flags) ? 'normal' : 'warning'),
            'validation_flags' => json_encode($flags, JSON_UNESCAPED_UNICODE),
        ];
    }

    private function applyOnlineDailyDataValidationFields(array $data, ?array $columns = null): array
    {
        $columns = $columns ?? $this->getOnlineDailyDataColumns();
        foreach ($this->buildOnlineDailyDataValidationFields($data) as $field => $value) {
            if (isset($columns[$field])) {
                $data[$field] = $value;
            }
        }
        return $data;
    }

    private function resolveOnlineDataSystemHotelId($input): ?int
    {
        if ($this->currentUser && !$this->currentUser->isSuperAdmin()) {
            $permittedHotelIds = array_values(array_map('intval', $this->currentUser->getPermittedHotelIds()));
            if (empty($permittedHotelIds)) {
                abort(403, '无可访问酒店');
            }

            if ($input !== null && $input !== '' && is_numeric($input) && (int)$input > 0) {
                $hotelId = (int)$input;
                if (!in_array($hotelId, $permittedHotelIds, true)) {
                    abort(403, '无权访问该酒店');
                }
                return $hotelId;
            }

            if (count($permittedHotelIds) === 1) {
                return $permittedHotelIds[0];
            }

            abort(400, '请选择酒店');
        }

        if ($input !== null && $input !== '' && is_numeric($input) && (int)$input > 0) {
            return (int)$input;
        }

        return null;
    }

    private function isAllowedOtaRequestUrl(string $url, array $allowedHostSuffixes): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        if ($scheme !== 'https' || $host === '') {
            return false;
        }

        foreach ($allowedHostSuffixes as $suffix) {
            $suffix = strtolower(ltrim($suffix, '.'));
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function sendCustomRequest(string $url, string $method, string $headersStr, string $body): array
    {
        $headers = [];
        if (!empty($headersStr)) {
            $headerLines = explode("\n", $headersStr);
            foreach ($headerLines as $line) {
                $line = trim($line);
                if (!empty($line)) {
                    $headers[] = $line;
                }
            }
        }
        
        $headerStr = implode("\r\n", $headers);
        
        $options = [
            'http' => [
                'method' => strtoupper($method),
                'header' => $headerStr,
                'timeout' => 30,
                'ignore_errors' => true,
            ],
            'ssl' => $this->buildStreamSslOptions(),
        ];
        
        if (strtoupper($method) === 'POST' && !empty($body)) {
            $options['http']['content'] = $body;
        }
        
        $context = stream_context_create($options);
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            $error = error_get_last();
            return [
                'success' => false,
                'error' => $error['message'] ?? 'Unknown error',
            ];
        }
        
        // 获取响应头
        $responseHeaders = '';
        $status = 200;
        if (isset($http_response_header)) {
            $responseHeaders = implode("\r\n", $http_response_header);
            // 解析HTTP状态码
            if (preg_match('/HTTP\/\d+\.?\d*\s+(\d+)/', $http_response_header[0] ?? '', $matches)) {
                $status = (int)$matches[1];
            }
        }
        
        $decoded = json_decode($response, true);
        
        return [
            'success' => true,
            'data' => $decoded,
            'raw' => $response,
            'status' => $status,
            'response_headers' => $responseHeaders,
        ];
    }
    
    /**
     * 检查权限
     */
    private function checkPermission(): void
    {
        if (!$this->currentUser) {
            abort(401, '未登录');
        }
        // 非超级管理员必须有酒店关联
        $this->requireHotel();
    }

    private function checkActionPermission(string $permission): void
    {
        if (!$this->currentUser) {
            abort(401, '未登录');
        }
        if ($this->currentUser->isSuperAdmin()) {
            return;
        }
        if (!$this->currentUser->hasPermission($permission)) {
            abort(403, '无权限操作');
        }
    }
    
    /**
     * 保存线上数据到数据库
     */
    public function saveDailyData(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');
        
        $dataList = $this->request->post('data', []);
        $dataDate = $this->request->post('data_date', date('Y-m-d', strtotime('-1 day')));
        $systemHotelId = $this->resolveOnlineDataSystemHotelId($this->request->post('system_hotel_id', null));
        
        if (empty($dataList)) {
            return $this->error('数据不能为空');
        }
        
        // 使用统一的解析和保存方法
        $savedCount = $this->parseAndSaveData(['data' => $dataList], $dataDate, $dataDate, $systemHotelId);
        
        OperationLog::record('online_data', 'save_daily', '保存线上数据: ' . $savedCount . '条', $this->currentUser->id, $systemHotelId);
        
        return $this->success(['saved_count' => $savedCount], '保存成功，共保存 ' . $savedCount . ' 条数据');
    }
    
    /**
     * 获取线上数据列表（支持门店隔离）
     */
    public function dailyDataList(): Response
    {
        try {
            // 从请求中获取当前用户（中间件已注入）
            $currentUser = $this->request->user ?? null;
            
            // 只检查登录，不强制要求酒店关联
            if (!$currentUser) {
                return $this->error('未登录', 401);
            }
            
            $startDate = $this->request->get('start_date', '');
            $endDate = $this->request->get('end_date', '');
            $source = $this->request->get('source', '');
            $hotelId = trim((string)$this->request->get('system_hotel_id', $this->request->get('hotel_id', '')));  // 系统酒店筛选
            $otaHotelId = trim((string)$this->request->get('ota_hotel_id', '')); // OTA平台酒店ID筛选
            $dataType = $this->request->get('data_type', ''); // 数据类型筛选
            $createStart = $this->request->get('create_start', ''); // 获取开始时间
            $createEnd = $this->request->get('create_end', ''); // 获取结束时间
            $page = intval($this->request->get('page', 1));
            $pageSize = intval($this->request->get('page_size', 30)); // 默认30条
            
            // 简化查询，先不添加复杂的权限过滤
            $query = Db::name('online_daily_data');
            
            // 按数据日期查询
            if (!empty($startDate) && !empty($endDate)) {
                $query->where('data_date', '>=', $startDate)
                      ->where('data_date', '<=', $endDate);
            }
            
            // 按来源筛选
            if (!empty($source)) {
                $query->where('source', $source);
            }
            
            if ($hotelId !== '') {
                $this->applyOnlineDailyDataHotelFilter($query, $hotelId);
            }

            if ($otaHotelId !== '') {
                $query->where('hotel_id', $otaHotelId);
            }
            
            // 按数据类型筛选
            if (!empty($dataType)) {
                $query->where('data_type', $dataType);
            }
            
            // 按获取时间筛选（支持单日筛选）
            // 如果只填了一个日期，自动设置为同一天
            if (!empty($createStart) && empty($createEnd)) {
                $createEnd = $createStart; // 单日筛选
            } elseif (empty($createStart) && !empty($createEnd)) {
                $createStart = $createEnd; // 单日筛选
            }
            
            if (!empty($createStart) && !empty($createEnd)) {
                $query->where('create_time', '>=', $createStart . ' 00:00:00')
                      ->where('create_time', '<=', $createEnd . ' 23:59:59');
            }
            
            // 非超级管理员只能看自己酒店的数据
            if (!$currentUser->isSuperAdmin()) {
                $permittedHotelIds = $currentUser->getPermittedHotelIds();
                if (empty($permittedHotelIds)) {
                    return $this->success([
                        'list' => [],
                        'pagination' => ['total' => 0, 'page' => $page, 'page_size' => $pageSize],
                    ]);
                }
                $query->whereIn('system_hotel_id', $permittedHotelIds);
            }
            
            $total = $query->count();
            $list = $query->order('data_date', 'desc')
                ->order('id', 'desc')
                ->page($page, $pageSize)
                ->select()
                ->toArray();
            
            // 解析 raw_data 添加排名等额外字段
            foreach ($list as &$item) {
                $bookOrderNum = intval($item['book_order_num'] ?? 0);
                $rawTotalOrderNum = 0;

                if (!empty($item['raw_data'])) {
                    $rawData = json_decode($item['raw_data'], true);
                    if ($rawData) {
                        $rawTotalOrderNum = intval($rawData['totalOrderNum'] ?? $rawData['total_order_num'] ?? 0);
                        // 添加排名字段
                        $item['amount_rank'] = $rawData['amountRank'] ?? null;
                        $item['quantity_rank'] = $rawData['quantityRank'] ?? null;
                        $item['book_order_num_rank'] = $rawData['bookOrderNumRank'] ?? null;
                        $item['comment_score_rank'] = $rawData['commentScoreRank'] ?? null;
                        $item['total_detail_num'] = $rawData['totalDetailNum'] ?? $item['total_detail_num'] ?? null;
                        $item['convertion_rate'] = $rawData['convertionRate'] ?? $item['convertion_rate'] ?? null;
                        $item['qunar_comment_score'] = $rawData['qunarCommentScore'] ?? $item['qunar_comment_score'] ?? null;
                        $item['qunar_detail_visitors'] = $rawData['qunarDetailVisitors'] ?? $item['qunar_detail_visitors'] ?? null;
                        $item['qunar_detail_cr'] = $rawData['qunarDetailCR'] ?? $item['qunar_detail_cr'] ?? null;
                    }
                }
                $item['total_order_num'] = $rawTotalOrderNum > 0 ? $rawTotalOrderNum : $bookOrderNum;
                $item['data_quality'] = $this->buildOnlineDataQuality($item);
            }

            return $this->success([
                'list' => $list,
                'pagination' => [
                    'total' => $total,
                    'page' => $page,
                    'page_size' => $pageSize,
                ],
                'data_quality_summary' => $this->buildOnlineDataQualitySummary($list),
            ]);
        } catch (\Throwable $e) {
            \think\facade\Log::error('获取线上数据列表失败: ' . $e->getMessage(), ['exception' => $e]);
            return $this->error('获取数据列表失败', 500);
        }
    }

    private function buildOnlineDataQualitySummary(array $rows): array
    {
        $checkedRecords = count($rows);
        $issueRecords = 0;
        $missingCount = 0;
        $abnormalCount = 0;
        $errorCount = 0;
        $warningCount = 0;
        $prompts = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $quality = isset($row['data_quality']) && is_array($row['data_quality'])
                ? $row['data_quality']
                : $this->buildOnlineDataQuality($row);

            if (($quality['status'] ?? 'ok') !== 'ok') {
                $issueRecords++;
            }
            $missingCount += count($quality['missing_metrics'] ?? []);
            $abnormalCount += count($quality['abnormal_metrics'] ?? []);
            $errorCount += (int)($quality['error_count'] ?? 0);
            $warningCount += (int)($quality['warning_count'] ?? 0);
            foreach (($quality['prompts'] ?? []) as $prompt) {
                $prompt = trim((string)$prompt);
                if ($prompt !== '' && !in_array($prompt, $prompts, true)) {
                    $prompts[] = $prompt;
                }
            }
        }

        $status = 'ok';
        if ($errorCount > 0) {
            $status = 'error';
        } elseif ($issueRecords > 0) {
            $status = 'warning';
        }

        return [
            'status' => $status,
            'checked_records' => $checkedRecords,
            'ok_records' => max(0, $checkedRecords - $issueRecords),
            'issue_records' => $issueRecords,
            'missing_count' => $missingCount,
            'abnormal_count' => $abnormalCount,
            'error_count' => $errorCount,
            'warning_count' => $warningCount,
            'top_prompts' => array_slice($prompts, 0, 6),
        ];
    }

    private function buildOnlineDataQuality(array $row): array
    {
        [$raw, $rawError] = $this->decodeOnlineDataQualityRaw($row['raw_data'] ?? null);
        $source = strtolower(trim((string)($row['source'] ?? '')));
        $dataType = strtolower(trim((string)($row['data_type'] ?? '')));
        if ($dataType === '') {
            $dataType = 'business';
        }

        $missing = [];
        $abnormal = [];

        $this->addOnlineDataMissingMetric($missing, $row, $raw, 'hotel_id', '酒店ID', ['hotel_id'], ['hotelId', 'hotel_id', 'poiId', 'poi_id']);
        $this->addOnlineDataMissingMetric($missing, $row, $raw, 'hotel_name', '酒店名称', ['hotel_name'], ['hotelName', 'hotel_name', 'poiName', 'poi_name']);
        $this->addOnlineDataMissingMetric($missing, $row, $raw, 'data_date', '数据日期', ['data_date'], ['dataDate', 'data_date', 'date', 'statDate']);
        $this->addOnlineDataMissingMetric($missing, $row, $raw, 'source', '数据来源', ['source'], []);

        if ($source === 'meituan') {
            $this->addOnlineDataMissingMetric($missing, $row, $raw, 'data_value', '指标值', ['data_value'], ['dataValue', 'data_value', 'monthRoomNights']);
            $this->addOnlineDataMissingMetric($missing, $row, $raw, 'dimension', '榜单维度', ['dimension'], ['dimension', 'dimName', '_dimName']);
        } elseif ($dataType === 'traffic') {
            $this->addOnlineDataMissingMetric($missing, $row, $raw, 'exposure', '曝光', ['list_exposure', 'exposure_count', 'exposure', 'data_value'], ['listExposure', 'exposure', 'exposure_count']);
            $this->addOnlineDataMissingMetric($missing, $row, $raw, 'detail_visitors', '浏览/访客', ['detail_exposure', 'click_count', 'total_detail_num'], ['detailExposure', 'totalDetailNum', 'views', 'visitorCount']);
        } else {
            $requireRaw = !empty($raw);
            $this->addOnlineDataMissingMetric($missing, $row, $raw, 'amount', '营业额', ['amount'], ['amount', 'Amount', 'totalAmount', 'total_amount', 'saleAmount'], $requireRaw);
            $this->addOnlineDataMissingMetric($missing, $row, $raw, 'quantity', '间夜', ['quantity'], ['quantity', 'Quantity', 'roomNights', 'room_nights', 'checkOutQuantity'], $requireRaw);
            $this->addOnlineDataMissingMetric($missing, $row, $raw, 'book_order_num', '订单数', ['book_order_num'], ['bookOrderNum', 'book_order_num', 'orderCount', 'order_count', 'orderNum', 'orders', 'bookings'], $requireRaw);
        }

        if ($rawError !== null) {
            $abnormal[] = $this->makeOnlineDataAbnormalIssue('warning', 'raw_data_json', 'raw_data', '原始JSON', null, '原始数据无法解析');
        }

        foreach ([
            'amount' => '营业额',
            'quantity' => '间夜',
            'book_order_num' => '订单数',
            'data_value' => '指标值',
        ] as $key => $label) {
            $value = $this->onlineDataQualityNumber($row[$key] ?? null);
            if ($value !== null && $value < 0) {
                $abnormal[] = $this->makeOnlineDataAbnormalIssue('error', $key . '_negative', $key, $label, $value, $label . '不能为负数');
            }
        }

        $amount = $this->onlineDataQualityFirstNumber($row, $raw, ['amount'], ['amount', 'Amount', 'totalAmount', 'total_amount', 'saleAmount']);
        $quantity = $this->onlineDataQualityFirstNumber($row, $raw, ['quantity'], ['quantity', 'Quantity', 'roomNights', 'room_nights', 'checkOutQuantity']);
        $orders = $this->onlineDataQualityFirstNumber($row, $raw, ['book_order_num'], ['bookOrderNum', 'book_order_num', 'orderCount', 'order_count', 'orderNum', 'orders', 'bookings']);

        if ($source !== 'meituan' && $dataType !== 'traffic') {
            if ($amount !== null && $amount > 0 && ($quantity === null || $quantity <= 0)) {
                $abnormal[] = $this->makeOnlineDataAbnormalIssue('warning', 'adr_denominator_zero', 'quantity', '间夜', $quantity, '营业额存在但间夜为0，ADR无法计算');
            }
            if ($quantity !== null && $quantity > 0 && ($amount === null || $amount <= 0)) {
                $abnormal[] = $this->makeOnlineDataAbnormalIssue('warning', 'amount_missing_for_quantity', 'amount', '营业额', $amount, '间夜存在但营业额为0');
            }
            if ($orders !== null && $orders > 0 && ($quantity === null || $quantity <= 0)) {
                $abnormal[] = $this->makeOnlineDataAbnormalIssue('warning', 'orders_without_room_nights', 'book_order_num', '订单数', $orders, '订单数存在但间夜为0');
            }
            if ($amount !== null && $quantity !== null && $quantity > 0) {
                $adr = round($amount / $quantity, 2);
                if ($adr > 5000) {
                    $abnormal[] = $this->makeOnlineDataAbnormalIssue('warning', 'adr_high', 'adr', 'ADR', $adr, 'ADR高于常规阈值');
                }
            }
        }

        foreach ([
            'comment_score' => '点评分',
            'qunar_comment_score' => '去哪儿评分',
        ] as $key => $label) {
            $score = $this->onlineDataQualityNumber($row[$key] ?? null);
            if ($score !== null && ($score < 0 || $score > 5)) {
                $abnormal[] = $this->makeOnlineDataAbnormalIssue('warning', 'comment_score_range', $key, $label, $score, $label . '应在0到5之间');
            }
        }

        foreach ([
            ['convertion_rate', '浏览转化率', ['convertion_rate'], ['convertionRate', 'conversionRate']],
            ['qunar_detail_cr', '去哪儿转化率', ['qunar_detail_cr'], ['qunarDetailCR', 'qunarDetailConversionRate']],
        ] as [$key, $label, $rowKeys, $rawKeys]) {
            $rate = $this->onlineDataQualityFirstNumber($row, $raw, $rowKeys, $rawKeys);
            if ($rate !== null && ($rate < 0 || $rate > 100)) {
                $abnormal[] = $this->makeOnlineDataAbnormalIssue('warning', $key . '_range', $key, $label, $rate, $label . '应在0到100之间');
            }
        }

        $this->appendOnlineDataTrafficAnomalies($abnormal, $row, $raw);

        $errorCount = count(array_filter([...$missing, ...$abnormal], static fn($issue): bool => ($issue['level'] ?? '') === 'error'));
        $warningCount = count($missing) + count($abnormal) - $errorCount;
        $status = $errorCount > 0 ? 'error' : ($warningCount > 0 ? 'warning' : 'ok');
        $prompts = $this->buildOnlineDataQualityPrompts($missing, $abnormal);

        return [
            'status' => $status,
            'status_label' => $status === 'ok' ? '完整' : ($status === 'error' ? '异常' : '需复核'),
            'score' => max(0, 100 - count($missing) * 12 - count($abnormal) * 18),
            'missing_metrics' => $missing,
            'abnormal_metrics' => $abnormal,
            'missing_count' => count($missing),
            'abnormal_count' => count($abnormal),
            'error_count' => $errorCount,
            'warning_count' => $warningCount,
            'prompts' => $prompts,
            'summary' => empty($prompts) ? '数据完整' : implode('；', $prompts),
        ];
    }

    private function decodeOnlineDataQualityRaw($rawData): array
    {
        if (is_array($rawData)) {
            return [$rawData, null];
        }
        if ($rawData === null || $rawData === '') {
            return [[], null];
        }
        if (!is_string($rawData)) {
            return [[], 'raw_data is not string'];
        }
        $decoded = json_decode($rawData, true);
        if (!is_array($decoded)) {
            return [[], json_last_error_msg()];
        }
        return [$decoded, null];
    }

    private function addOnlineDataMissingMetric(array &$missing, array $row, array $raw, string $key, string $label, array $rowKeys, array $rawKeys, bool $requireRaw = false): void
    {
        if ($this->onlineDataQualityMetricPresent($row, $raw, $rowKeys, $rawKeys, $requireRaw)) {
            return;
        }
        $missing[] = [
            'level' => 'warning',
            'key' => $key,
            'label' => $label,
            'message' => '缺失' . $label,
        ];
    }

    private function onlineDataQualityMetricPresent(array $row, array $raw, array $rowKeys, array $rawKeys, bool $requireRaw = false): bool
    {
        if (!$requireRaw) {
            foreach ($rowKeys as $key) {
                if (array_key_exists($key, $row) && !$this->onlineDataQualityBlank($row[$key])) {
                    return true;
                }
            }
        }
        foreach ($rawKeys as $key) {
            if (array_key_exists($key, $raw) && !$this->onlineDataQualityBlank($raw[$key])) {
                return true;
            }
        }
        return false;
    }

    private function onlineDataQualityBlank($value): bool
    {
        return $value === null || $value === '';
    }

    private function onlineDataQualityNumber($value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float)$value) ? (float)$value : null;
        }
        if (is_string($value)) {
            $normalized = trim(str_replace([',', '%'], '', $value));
            if ($normalized === '' || !is_numeric($normalized)) {
                return null;
            }
            return (float)$normalized;
        }
        return null;
    }

    private function onlineDataQualityFirstNumber(array $row, array $raw, array $rowKeys, array $rawKeys): ?float
    {
        foreach ($rowKeys as $key) {
            if (array_key_exists($key, $row)) {
                $value = $this->onlineDataQualityNumber($row[$key]);
                if ($value !== null) {
                    return $value;
                }
            }
        }
        foreach ($rawKeys as $key) {
            if (array_key_exists($key, $raw)) {
                $value = $this->onlineDataQualityNumber($raw[$key]);
                if ($value !== null) {
                    return $value;
                }
            }
        }
        return null;
    }

    private function makeOnlineDataAbnormalIssue(string $level, string $code, string $key, string $label, $value, string $message): array
    {
        return [
            'level' => $level,
            'code' => $code,
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'message' => $message,
        ];
    }

    private function appendOnlineDataTrafficAnomalies(array &$abnormal, array $row, array $raw): void
    {
        $exposure = $this->onlineDataQualityFirstNumber($row, $raw, ['list_exposure', 'exposure_count', 'exposure', 'data_value'], ['listExposure', 'exposure', 'exposure_count']);
        $views = $this->onlineDataQualityFirstNumber($row, $raw, ['detail_exposure', 'click_count', 'total_detail_num'], ['detailExposure', 'totalDetailNum', 'views', 'visitorCount']);
        $orderVisitors = $this->onlineDataQualityFirstNumber($row, $raw, ['order_filling_num', 'order_visitors'], ['orderFillingNum', 'order_visitors']);
        $submitUsers = $this->onlineDataQualityFirstNumber($row, $raw, ['order_submit_num', 'submit_users'], ['orderSubmitNum', 'submit_users']);

        if ($exposure !== null && $views !== null && $exposure > 0 && $views > $exposure) {
            $abnormal[] = $this->makeOnlineDataAbnormalIssue('warning', 'views_gt_exposure', 'detail_visitors', '浏览/访客', $views, '浏览/访客大于曝光');
        }
        if ($views !== null && $orderVisitors !== null && $views > 0 && $orderVisitors > $views) {
            $abnormal[] = $this->makeOnlineDataAbnormalIssue('warning', 'orders_gt_views', 'order_visitors', '订单页访客', $orderVisitors, '订单页访客大于浏览/访客');
        }
        if ($orderVisitors !== null && $submitUsers !== null && $orderVisitors > 0 && $submitUsers > $orderVisitors) {
            $abnormal[] = $this->makeOnlineDataAbnormalIssue('warning', 'submit_gt_orders', 'submit_users', '提交用户', $submitUsers, '提交用户大于订单页访客');
        }
    }

    private function buildOnlineDataQualityPrompts(array $missing, array $abnormal): array
    {
        $prompts = [];
        if (!empty($missing)) {
            $labels = array_values(array_unique(array_map(static fn($issue): string => (string)($issue['label'] ?? $issue['key'] ?? ''), $missing)));
            $labels = array_filter($labels, static fn($label): bool => $label !== '');
            $prompts[] = '缺失：' . implode('、', array_slice($labels, 0, 6));
        }
        if (!empty($abnormal)) {
            $messages = array_values(array_unique(array_map(static fn($issue): string => (string)($issue['message'] ?? $issue['label'] ?? ''), $abnormal)));
            $messages = array_filter($messages, static fn($message): bool => $message !== '');
            $prompts[] = '异常：' . implode('、', array_slice($messages, 0, 6));
        }
        return $prompts;
    }

    private function applyOnlineDailyDataHotelFilter($query, string $hotelId): void
    {
        $columns = $this->getOnlineDailyDataColumns();
        if (isset($columns['system_hotel_id']) && is_numeric($hotelId)) {
            $query->where('system_hotel_id', (int)$hotelId);
            return;
        }

        if (isset($columns['hotel_id'])) {
            $query->where('hotel_id', $hotelId);
        }
    }

    /**
     * OTA历史快照查询中心
     */
    public function history(): Response
    {
        $currentUser = $this->request->user ?? $this->currentUser;
        if (!$currentUser) {
            return $this->error('未登录', 401);
        }

        try {
            $page = max(1, intval($this->request->get('page', 1)));
            $pageSizeInput = $this->request->get('page_size', null);
            if ($pageSizeInput === null || $pageSizeInput === '') {
                $pageSizeInput = $this->request->get('limit', 20);
            }
            $pageSize = min(100, max(1, intval($pageSizeInput)));
            $keyword = trim((string)$this->request->get('keyword', $this->request->get('search', '')));

            $query = Db::name('online_daily_data');
            $this->applyOnlineHistoryFilters($query, $currentUser);
            $this->applyOnlineHistoryKeywordFilter($query, $keyword);

            $rows = (clone $query)->order('create_time', 'desc')
                ->order('id', 'desc')
                ->select()
                ->toArray();

            $hotelMap = $this->getConfiguredHotelNameMap();
            $historyGroups = $this->mergeOnlineHistoryRows($rows, $hotelMap);
            $total = count($historyGroups);
            $summary = $this->buildOnlineHistorySummary($historyGroups);
            $historyList = array_slice($historyGroups, ($page - 1) * $pageSize, $pageSize);

            return $this->success([
                'list' => $historyList,
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
                'summary' => $summary,
            ]);
        } catch (\Throwable $e) {
            return $this->error('获取历史记录失败: ' . $e->getMessage());
        }
    }

    /**
     * OTA历史快照详情
     */
    public function historyDetail(int $id): Response
    {
        $currentUser = $this->request->user ?? $this->currentUser;
        if (!$currentUser) {
            return $this->error('未登录', 401);
        }

        try {
            $row = Db::name('online_daily_data')->where('id', $id)->find();
            if (!$row) {
                return $this->error('历史记录不存在', 404);
            }

            if (!$currentUser->isSuperAdmin()) {
                $permittedHotelIds = $currentUser->getPermittedHotelIds();
                if (empty($row['system_hotel_id']) || !in_array((int)$row['system_hotel_id'], $permittedHotelIds, true)) {
                    return $this->error('无权查看该历史记录', 403);
                }
            }

            $item = $this->normalizeOnlineHistoryRow($row, $this->getConfiguredHotelNameMap());
            $rawData = $item['raw_data'] ?? '';
            $decoded = is_string($rawData) && $rawData !== '' ? json_decode($rawData, true) : null;
            $item['raw_data_json'] = json_last_error() === JSON_ERROR_NONE ? $decoded : null;

            return $this->success($item);
        } catch (\Throwable $e) {
            return $this->error('获取历史详情失败: ' . $e->getMessage());
        }
    }

    /**
     * 携程最近一次成功采集数据
     */
    public function ctripLatest(): Response
    {
        $currentUser = $this->request->user ?? $this->currentUser;
        if (!$currentUser) {
            return $this->error('未登录', 401);
        }

        try {
            $hotelId = trim((string)$this->request->get('hotel_id', ''));
            $sections = [
                'rank' => $this->buildCtripLatestSection('rank', $hotelId, $currentUser),
                'traffic' => $this->buildCtripLatestSection('traffic', $hotelId, $currentUser),
                'review' => $this->buildCtripLatestSection('review', $hotelId, $currentUser),
            ];

            return $this->success([
                'metadata' => $this->buildCtripLatestMetadata($sections, $hotelId),
                'rank' => $sections['rank'],
                'traffic' => $sections['traffic'],
                'review' => $sections['review'],
            ]);
        } catch (\Throwable $e) {
            return $this->error('获取携程最近采集数据失败: ' . $e->getMessage());
        }
    }

    /**
     * 携程采集历史
     */
    public function ctripHistory(): Response
    {
        $currentUser = $this->request->user ?? $this->currentUser;
        if (!$currentUser) {
            return $this->error('未登录', 401);
        }

        try {
            $page = max(1, intval($this->request->get('page', 1)));
            $pageSize = min(100, max(1, intval($this->request->get('page_size', $this->request->get('limit', 20)))));
            $hotelId = trim((string)$this->request->get('hotel_id', ''));
            $dataType = trim((string)$this->request->get('data_type', ''));
            $columns = $this->getOnlineDailyDataColumns();

            $query = Db::name('online_daily_data');
            $this->applyCtripStorageFilter($query, $columns);
            $this->applyCtripHotelScope($query, $hotelId, $currentUser, $columns);
            if ($dataType !== '' && $dataType !== 'all') {
                $this->applyCtripSectionTypeFilter($query, $dataType, $columns);
            }

            $total = (int)(clone $query)->count();
            $summary = $this->buildOnlineHistorySummaryFromQuery(clone $query, $total);
            $rows = $this->orderOnlineDataByFetchTime(clone $query, $columns)
                ->limit(($page - 1) * $pageSize, $pageSize)
                ->select()
                ->toArray();

            $hotelMap = $this->getConfiguredHotelNameMap();
            $list = [];
            foreach ($rows as $row) {
                $list[] = $this->normalizeOnlineHistoryRow($row, $hotelMap);
            }

            return $this->success([
                'list' => $list,
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
                'summary' => $summary,
            ]);
        } catch (\Throwable $e) {
            return $this->error('获取携程采集历史失败: ' . $e->getMessage());
        }
    }

    private function buildCtripLatestSection(string $section, string $hotelId, $currentUser): array
    {
        $columns = $this->getOnlineDailyDataColumns();
        $labelMap = [
            'rank' => '榜单数据',
            'traffic' => '流量数据',
            'review' => '点评数据',
        ];

        $query = Db::name('online_daily_data');
        $this->applyCtripStorageFilter($query, $columns);
        $this->applyCtripSectionTypeFilter($query, $section, $columns);
        $this->applyCtripHotelScope($query, $hotelId, $currentUser, $columns);

        $latest = $this->orderOnlineDataByFetchTime($query, $columns)->find();
        if (!$latest) {
            return $this->emptyCtripLatestSection($section, $labelMap[$section] ?? $section);
        }

        $rowsQuery = Db::name('online_daily_data');
        $this->applyCtripStorageFilter($rowsQuery, $columns);
        $this->applyCtripSectionTypeFilter($rowsQuery, $section, $columns);
        $this->applyCtripHotelScope($rowsQuery, $hotelId, $currentUser, $columns);
        if (isset($columns['data_date']) && !empty($latest['data_date'])) {
            $rowsQuery->where('data_date', $latest['data_date']);
        }
        $this->applyCtripLatestBatchScope($rowsQuery, $latest, $hotelId, $columns);

        $rows = $this->orderOnlineDataByFetchTime($rowsQuery, $columns, 'asc')
            ->select()
            ->toArray();

        $fetchedAt = $this->maxOnlineRowsFetchedAt($rows, $columns);

        return [
            'data_type' => $section,
            'data_type_label' => $labelMap[$section] ?? $section,
            'data_source' => '携程 ebooking',
            'status' => empty($rows) ? 'empty' : 'success',
            'status_label' => empty($rows) ? '暂无数据' : '成功',
            'data_date' => (string)($latest['data_date'] ?? ''),
            'fetched_at' => $fetchedAt !== '' ? $fetchedAt : $this->onlineRowFetchedAt($latest, $columns),
            'total' => count($rows),
            'rows' => $this->decodeOnlineRawRows($rows),
        ];
    }

    private function emptyCtripLatestSection(string $section, string $label): array
    {
        return [
            'data_type' => $section,
            'data_type_label' => $label,
            'data_source' => '携程 ebooking',
            'status' => 'empty',
            'status_label' => '暂无数据',
            'data_date' => '',
            'fetched_at' => '',
            'total' => 0,
            'rows' => [],
        ];
    }

    private function buildCtripLatestMetadata(array $sections, string $hotelId): array
    {
        $fetchedAt = '';
        $dataDate = '';
        $total = 0;
        foreach ($sections as $section) {
            $total += (int)($section['total'] ?? 0);
            $sectionFetchedAt = (string)($section['fetched_at'] ?? '');
            if ($sectionFetchedAt !== '' && ($fetchedAt === '' || strcmp($sectionFetchedAt, $fetchedAt) > 0)) {
                $fetchedAt = $sectionFetchedAt;
            }
            $sectionDataDate = (string)($section['data_date'] ?? '');
            if ($sectionDataDate !== '' && ($dataDate === '' || strcmp($sectionDataDate, $dataDate) > 0)) {
                $dataDate = $sectionDataDate;
            }
        }

        $fetchStatus = $this->getCtripLatestFetchStatus($hotelId);
        if (!empty($fetchStatus['fetched_at']) && ($fetchedAt === '' || strcmp((string)$fetchStatus['fetched_at'], $fetchedAt) >= 0)) {
            $fetchedAt = (string)$fetchStatus['fetched_at'];
            $dataDate = (string)($fetchStatus['data_date'] ?? $dataDate);
            $total = max($total, (int)($fetchStatus['saved_count'] ?? 0));
        }

        return [
            'hotel_id' => $hotelId,
            'platform' => 'ctrip',
            'data_source' => '携程 ebooking',
            'status' => $total > 0 ? 'success' : 'empty',
            'status_label' => $total > 0 ? '成功' : '暂无成功采集',
            'data_date' => $dataDate,
            'fetched_at' => $fetchedAt,
            'total_records' => $total,
        ];
    }

    private function applyCtripStorageFilter($query, array $columns): void
    {
        if (isset($columns['source'], $columns['platform'])) {
            $query->where(function ($q) {
                $q->where('source', 'ctrip')->whereOr('platform', 'Ctrip');
            });
            return;
        }
        if (isset($columns['source'])) {
            $query->where('source', 'ctrip');
            return;
        }
        if (isset($columns['platform'])) {
            $query->where('platform', 'Ctrip');
        }
    }

    private function applyCtripSectionTypeFilter($query, string $section, array $columns): void
    {
        if (!isset($columns['data_type'])) {
            return;
        }

        $section = strtolower($section);
        if (in_array($section, ['rank', 'business'], true)) {
            $query->where(function ($q) {
                $q->where('data_type', 'business')->whereOr('data_type', '');
            });
            return;
        }
        if ($section === 'review') {
            $query->where(function ($q) {
                $q->where('data_type', 'review')->whereOr('data_type', 'comment')->whereOr('data_type', 'comments');
            });
            return;
        }
        $query->where('data_type', $section);
    }

    private function applyCtripHotelScope($query, string $hotelId, $currentUser, array $columns): void
    {
        if ($hotelId !== '') {
            if (isset($columns['system_hotel_id']) && is_numeric($hotelId)) {
                $query->where('system_hotel_id', (int)$hotelId);
            } elseif (isset($columns['hotel_id'])) {
                $query->where('hotel_id', $hotelId);
            }
        }

        if ($currentUser && !$currentUser->isSuperAdmin()) {
            $permittedHotelIds = $currentUser->getPermittedHotelIds();
            if (empty($permittedHotelIds) || !isset($columns['system_hotel_id'])) {
                $query->where('id', 0);
            } else {
                $query->whereIn('system_hotel_id', $permittedHotelIds);
            }
        }
    }

    private function applyCtripLatestBatchScope($query, array $latest, string $hotelId, array $columns): void
    {
        if ($hotelId === '' && isset($columns['system_hotel_id'])) {
            if (isset($latest['system_hotel_id']) && $latest['system_hotel_id'] !== null && $latest['system_hotel_id'] !== '') {
                $query->where('system_hotel_id', (int)$latest['system_hotel_id']);
            } else {
                $query->whereNull('system_hotel_id');
            }
        }

        $this->applyOnlineLatestFetchTimeScope($query, $latest, $columns);
    }

    private function applyOnlineLatestFetchTimeScope($query, array $latest, array $columns): void
    {
        foreach (['update_time', 'create_time'] as $column) {
            if (isset($columns[$column]) && !empty($latest[$column])) {
                $query->where($column, (string)$latest[$column]);
                return;
            }
        }
    }

    private function orderOnlineDataByFetchTime($query, array $columns, string $direction = 'desc')
    {
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';
        if (isset($columns['update_time'])) {
            $query->order('update_time', $direction);
        }
        if (isset($columns['create_time'])) {
            $query->order('create_time', $direction);
        }
        return $query->order('id', $direction);
    }

    private function onlineRowFetchedAt(array $row, array $columns): string
    {
        if (isset($columns['update_time']) && !empty($row['update_time'])) {
            return (string)$row['update_time'];
        }
        if (isset($columns['create_time']) && !empty($row['create_time'])) {
            return (string)$row['create_time'];
        }
        return '';
    }

    private function maxOnlineRowsFetchedAt(array $rows, array $columns): string
    {
        $max = '';
        foreach ($rows as $row) {
            $time = $this->onlineRowFetchedAt($row, $columns);
            if ($time !== '' && ($max === '' || strcmp($time, $max) > 0)) {
                $max = $time;
            }
        }
        return $max;
    }

    private function decodeOnlineRawRows(array $rows): array
    {
        $payload = [];
        foreach ($rows as $row) {
            $raw = (string)($row['raw_data'] ?? '');
            $decoded = $raw !== '' ? json_decode($raw, true) : null;
            if (!is_array($decoded)) {
                $decoded = $this->buildOnlineRowPayload($row);
            }
            $decoded['_record_id'] = (int)($row['id'] ?? 0);
            $decoded['_data_date'] = (string)($row['data_date'] ?? '');
            $decoded['_fetch_time'] = (string)($row['update_time'] ?? $row['create_time'] ?? '');
            $payload[] = $decoded;
        }
        return $payload;
    }

    private function buildOnlineRowPayload(array $row): array
    {
        return [
            'hotelId' => $row['hotel_id'] ?? '',
            'hotelName' => $row['hotel_name'] ?? '',
            'date' => $row['data_date'] ?? '',
            'amount' => (float)($row['amount'] ?? 0),
            'quantity' => (int)($row['quantity'] ?? 0),
            'bookOrderNum' => (int)($row['book_order_num'] ?? 0),
            'commentScore' => (float)($row['comment_score'] ?? 0),
            'qunarCommentScore' => (float)($row['qunar_comment_score'] ?? 0),
            'dataValue' => (float)($row['data_value'] ?? 0),
            'listExposure' => (int)($row['list_exposure'] ?? 0),
            'detailExposure' => (int)($row['detail_exposure'] ?? 0),
            'flowRate' => (float)($row['flow_rate'] ?? 0),
            'orderFillingNum' => (int)($row['order_filling_num'] ?? 0),
            'orderSubmitNum' => (int)($row['order_submit_num'] ?? 0),
        ];
    }

    private function applyOnlineHistoryFilters($query, $currentUser): void
    {
        $columns = $this->getOnlineDailyDataColumns();
        $platform = strtolower((string)$this->request->get('platform', $this->request->get('source', '')));
        $dataType = (string)$this->request->get('data_type', '');
        $hotelScope = (string)$this->request->get('hotel_scope', 'all');
        $hotelId = (string)$this->request->get('hotel_id', '');
        $otaHotelId = (string)$this->request->get('ota_hotel_id', '');
        $startDate = (string)$this->request->get('start_date', '');
        $endDate = (string)$this->request->get('end_date', '');

        if ($platform !== '' && $platform !== 'all') {
            if ($platform === 'ctrip') {
                if (isset($columns['source'], $columns['platform'])) {
                    $query->where(function ($q) {
                        $q->where('source', 'ctrip')->whereOr('platform', 'Ctrip');
                    });
                } elseif (isset($columns['source'])) {
                    $query->where('source', 'ctrip');
                } elseif (isset($columns['platform'])) {
                    $query->where('platform', 'Ctrip');
                }
            } elseif ($platform === 'meituan') {
                if (isset($columns['source'], $columns['platform'])) {
                    $query->where(function ($q) {
                        $q->where('source', 'meituan')->whereOr('platform', 'Meituan');
                    });
                } elseif (isset($columns['source'])) {
                    $query->where('source', 'meituan');
                } elseif (isset($columns['platform'])) {
                    $query->where('platform', 'Meituan');
                }
            } elseif ($platform === 'qunar') {
                if (isset($columns['source'], $columns['platform'])) {
                    $query->where(function ($q) {
                        $q->where('source', 'qunar')->whereOr('platform', 'Qunar');
                    });
                } elseif (isset($columns['source'])) {
                    $query->where('source', 'qunar');
                } elseif (isset($columns['platform'])) {
                    $query->where('platform', 'Qunar');
                }
            }
        }

        if ($dataType !== '' && $dataType !== 'all' && isset($columns['data_type'])) {
            if ($dataType === 'business') {
                $this->applyDataTypeFilter($query, 'business');
            } elseif ($dataType === 'competitor') {
                if (isset($columns['compare_type'])) {
                    $query->where(function ($q) {
                        $q->where('data_type', 'competitor')
                            ->whereOr('compare_type', 'competitor_avg')
                            ->whereOr('hotel_name', 'like', '%竞争圈平均%');
                    });
                } else {
                    $query->where(function ($q) {
                        $q->where('data_type', 'competitor')->whereOr('hotel_name', 'like', '%竞争圈平均%');
                    });
                }
            } elseif ($dataType === 'review') {
                $query->where(function ($q) {
                    $q->where('data_type', 'review')->whereOr('data_type', 'comment');
                });
            } elseif ($dataType === 'advertising') {
                $query->where(function ($q) {
                    $q->where('data_type', 'advertising')->whereOr('data_type', 'ad');
                });
            } else {
                $query->where('data_type', $dataType);
            }
        }

        if ($startDate !== '' && isset($columns['data_date'])) {
            $query->where('data_date', '>=', $startDate);
        }
        if ($endDate !== '' && isset($columns['data_date'])) {
            $query->where('data_date', '<=', $endDate);
        }

        if (!in_array($hotelScope, ['all', 'mine', 'competitor_avg', 'hotel'], true) && $hotelScope !== '') {
            $hotelId = $hotelScope;
            $hotelScope = 'hotel';
        }

        if ($hotelScope === 'mine') {
            if (isset($columns['system_hotel_id'], $columns['compare_type'], $columns['hotel_name'])) {
                $query->where(function ($q) {
                    $q->whereNotNull('system_hotel_id')
                        ->whereOr('compare_type', 'self')
                        ->whereOr('hotel_name', '我的酒店');
                });
            } elseif (isset($columns['system_hotel_id'], $columns['hotel_name'])) {
                $query->where(function ($q) {
                    $q->whereNotNull('system_hotel_id')->whereOr('hotel_name', '我的酒店');
                });
            } elseif (isset($columns['system_hotel_id'])) {
                $query->whereNotNull('system_hotel_id');
            } elseif (isset($columns['hotel_name'])) {
                $query->where('hotel_name', '我的酒店');
            }
        } elseif ($hotelScope === 'competitor_avg') {
            if (isset($columns['compare_type'])) {
                $query->where(function ($q) {
                    $q->where('compare_type', 'competitor_avg')
                        ->whereOr('hotel_id', '-1')
                        ->whereOr('hotel_name', '竞争圈平均');
                });
            } else {
                $query->where(function ($q) {
                    $q->where('hotel_id', '-1')->whereOr('hotel_name', '竞争圈平均');
                });
            }
        } elseif ($hotelScope === 'hotel' && $hotelId !== '' && $hotelId !== 'all') {
            $this->applyOnlineHistoryHotelIdFilter($query, $columns, $hotelId);
        } elseif ($hotelScope === 'all' && $hotelId !== '' && $hotelId !== 'all') {
            $this->applyOnlineHistoryHotelIdFilter($query, $columns, $hotelId);
        }

        if ($otaHotelId !== '' && isset($columns['hotel_id'])) {
            $query->where('hotel_id', $otaHotelId);
        }

        if (!$currentUser->isSuperAdmin()) {
            $permittedHotelIds = $currentUser->getPermittedHotelIds();
            if (empty($permittedHotelIds) || !isset($columns['system_hotel_id'])) {
                $query->where('id', 0);
            } else {
                $query->whereIn('system_hotel_id', $permittedHotelIds);
            }
        }
    }

    private function applyOnlineHistoryHotelIdFilter($query, array $columns, string $hotelId): void
    {
        if (isset($columns['system_hotel_id']) && is_numeric($hotelId)) {
            $query->where('system_hotel_id', (int)$hotelId);
            return;
        }

        if (isset($columns['hotel_id'])) {
            $query->where('hotel_id', $hotelId);
        }
    }

    private function applyOnlineHistoryKeywordFilter($query, string $keyword): void
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return;
        }

        $columns = $this->getOnlineDailyDataColumns();
        $searchableColumns = array_values(array_filter([
            'id',
            'hotel_name',
            'hotel_id',
            'source',
            'platform',
            'data_type',
            'compare_type',
            'batch_no',
            'create_time',
            'data_date',
        ], static fn (string $column): bool => isset($columns[$column])));

        if (empty($searchableColumns)) {
            return;
        }

        $terms = $this->expandOnlineHistoryKeywordTerms($keyword);
        $query->where(function ($q) use ($searchableColumns, $terms) {
            $hasCondition = false;
            foreach ($terms as $term) {
                $like = '%' . $term . '%';
                foreach ($searchableColumns as $column) {
                    if ($hasCondition) {
                        $q->whereOr($column, 'like', $like);
                    } else {
                        $q->where($column, 'like', $like);
                        $hasCondition = true;
                    }
                }
            }
        });
    }

    private function expandOnlineHistoryKeywordTerms(string $keyword): array
    {
        $keyword = trim($keyword);
        $lowerKeyword = mb_strtolower($keyword);
        $terms = [$keyword];
        $labelMap = [
            '携程' => ['ctrip', 'Ctrip'],
            '美团' => ['meituan', 'Meituan'],
            '去哪儿' => ['qunar', 'Qunar'],
            '经营数据' => ['business'],
            '流量数据' => ['traffic'],
            '竞对数据' => ['competitor', 'competitor_avg'],
            '竞争圈' => ['competitor', 'competitor_avg'],
            '点评数据' => ['review', 'comment'],
            '广告数据' => ['advertising', 'ad'],
        ];

        foreach ($labelMap as $label => $values) {
            if (mb_strpos(mb_strtolower($label), $lowerKeyword) !== false || mb_strpos($lowerKeyword, mb_strtolower($label)) !== false) {
                array_push($terms, ...$values);
            }
        }

        return array_values(array_unique(array_filter($terms, static fn (string $term): bool => $term !== '')));
    }

    private function buildOnlineHistorySummaryFromQuery($query, int $total): array
    {
        $columns = $this->getOnlineDailyDataColumns();
        $latestFetchTime = '';
        $todayRecords = 0;
        $failedRecords = 0;

        if (isset($columns['create_time'])) {
            $today = date('Y-m-d');
            $latestFetchTime = (string)((clone $query)->max('create_time') ?: '');
            $todayRecords = (int)(clone $query)
                ->where('create_time', '>=', $today . ' 00:00:00')
                ->where('create_time', '<=', $today . ' 23:59:59')
                ->count();
        }

        if (isset($columns['status'])) {
            $failedRecords = (int)(clone $query)->whereIn('status', ['failed', 'fail', 'error'])->count();
        } elseif (isset($columns['raw_data'])) {
            $failedRecords = (int)(clone $query)->where(function ($q) {
                $q->where('raw_data', 'like', '%"error"%')->whereOr('raw_data', 'like', '%"errors"%');
            })->count();
        }

        return [
            'total_records' => $total,
            'latest_fetch_time' => $latestFetchTime,
            'today_records' => $todayRecords,
            'failed_records' => $failedRecords,
        ];
    }

    private function getConfiguredHotelNameMap(): array
    {
        try {
            return Db::name('hotels')->where('status', 1)->column('name', 'id');
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function normalizeOnlineHistoryRow(array $row, array $hotelMap): array
    {
        $rawData = (string)($row['raw_data'] ?? $row['response_json'] ?? $row['data'] ?? '');
        $source = strtolower((string)($row['source'] ?? ''));
        $platformCode = $this->normalizeHistoryPlatformCode($row['platform'] ?? $source);
        $compareType = (string)($row['compare_type'] ?? '');
        $otaHotelId = (string)($row['ota_hotel_id'] ?? $row['hotel_id'] ?? '');
        $systemHotelId = $row['system_hotel_id'] ?? null;
        $displayHotelName = $this->buildHistoryHotelDisplayName($row, $hotelMap);
        $dataType = $this->normalizeHistoryDataType((string)($row['data_type'] ?? ''), $compareType);
        $status = $this->resolveHistoryStatus($row, $rawData);

        $item = $row;
        $item['id'] = (int)$row['id'];
        $item['fetch_time'] = (string)($row['create_time'] ?? '');
        $item['data_date'] = (string)($row['data_date'] ?? '');
        $item['platform'] = $platformCode;
        $item['platform_label'] = $this->historyPlatformLabel($platformCode);
        $item['data_type'] = $dataType;
        $item['data_type_label'] = $this->historyDataTypeLabel($dataType);
        $item['hotel_name'] = $displayHotelName;
        $item['original_hotel_name'] = (string)($row['hotel_name'] ?? '');
        $item['hotel_id'] = $systemHotelId;
        $item['ota_hotel_id'] = $otaHotelId;
        $item['is_my_hotel'] = $this->isMyHotelHistoryRow($row);
        $item['batch_no'] = $this->buildHistoryBatchNo($row, $rawData, $platformCode, $dataType);
        $item['status'] = $status;
        $item['status_label'] = $this->historyStatusLabel($status);
        $item['raw_data'] = $rawData;
        $item['metrics_summary'] = $this->buildHistoryMetricSummary($row, $rawData);

        return $item;
    }

    private function mergeOnlineHistoryRows(array $rows, array $hotelMap): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $item = $this->normalizeOnlineHistoryRow($row, $hotelMap);
            $groupKey = $this->buildOnlineHistoryMergeKey($item);

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = $item;
                $groups[$groupKey]['record_count'] = 0;
                $groups[$groupKey]['raw_record_count'] = 0;
                $groups[$groupKey]['hotel_count'] = 0;
                $groups[$groupKey]['record_ids'] = [];
                $groups[$groupKey]['merged_hotel_names'] = [];
                $groups[$groupKey]['merged_ota_hotel_ids'] = [];
                $groups[$groupKey]['merged_batch_nos'] = [];
                $groups[$groupKey]['_dedupe_key_map'] = [];
                $groups[$groupKey]['_hotel_name_map'] = [];
                $groups[$groupKey]['_ota_hotel_id_map'] = [];
                $groups[$groupKey]['_batch_no_map'] = [];
                $groups[$groupKey]['_raw_samples'] = [];
                $groups[$groupKey]['_amount_total'] = 0.0;
                $groups[$groupKey]['_quantity_total'] = 0.0;
                $groups[$groupKey]['_order_total'] = 0.0;
                $groups[$groupKey]['_data_value_total'] = 0.0;
                $groups[$groupKey]['_failed_count'] = 0;
                $groups[$groupKey]['_empty_count'] = 0;
                $groups[$groupKey]['_success_count'] = 0;
            }

            $this->appendOnlineHistoryGroupRow($groups[$groupKey], $item);
        }

        $historyGroups = [];
        foreach ($groups as $group) {
            $historyGroups[] = $this->finalizeOnlineHistoryGroup($group);
        }
        return $historyGroups;
    }

    private function buildOnlineHistoryMergeKey(array $item): string
    {
        return implode('|', [
            (string)($item['data_date'] ?? ''),
            (string)($item['platform'] ?? ''),
            (string)($item['data_type'] ?? ''),
            (string)($item['hotel_id'] ?? ''),
            (string)($item['dimension'] ?? ''),
            (string)($item['compare_type'] ?? ''),
        ]);
    }

    private function appendOnlineHistoryGroupRow(array &$group, array $item): void
    {
        $group['raw_record_count']++;
        $group['record_ids'][] = (int)($item['id'] ?? 0);

        $hotelName = trim((string)($item['hotel_name'] ?? ''));
        $otaHotelId = trim((string)($item['ota_hotel_id'] ?? ''));
        $hotelKey = $otaHotelId !== '' ? $otaHotelId : $hotelName;
        $dedupeKey = $hotelKey !== '' ? $hotelKey : 'record-' . (string)($item['id'] ?? '');
        $batchNo = trim((string)($item['batch_no'] ?? ''));
        if ($batchNo !== '' && !isset($group['_batch_no_map'][$batchNo])) {
            $group['_batch_no_map'][$batchNo] = $batchNo;
        }

        if (isset($group['_dedupe_key_map'][$dedupeKey])) {
            return;
        }
        $group['_dedupe_key_map'][$dedupeKey] = true;
        $group['record_count']++;

        if ($hotelKey !== '' && !isset($group['_hotel_name_map'][$hotelKey])) {
            $group['_hotel_name_map'][$hotelKey] = $hotelName !== '' ? $hotelName : $hotelKey;
        }
        if ($otaHotelId !== '' && !isset($group['_ota_hotel_id_map'][$otaHotelId])) {
            $group['_ota_hotel_id_map'][$otaHotelId] = $otaHotelId;
        }
        if (!empty($item['is_my_hotel'])) {
            $group['is_my_hotel'] = true;
        }

        $status = (string)($item['status'] ?? '');
        if ($status === 'failed') {
            $group['_failed_count']++;
        } elseif ($status === 'empty') {
            $group['_empty_count']++;
        } else {
            $group['_success_count']++;
        }

        $group['_amount_total'] += (float)($item['amount'] ?? 0);
        $group['_quantity_total'] += (float)($item['quantity'] ?? 0);
        $group['_order_total'] += (float)($item['book_order_num'] ?? $item['order_submit_num'] ?? 0);
        $group['_data_value_total'] += (float)($item['data_value'] ?? 0);

        if (count($group['_raw_samples']) < 5) {
            $rawData = (string)($item['raw_data'] ?? '');
            $decodedRaw = $rawData !== '' ? json_decode($rawData, true) : null;
            $group['_raw_samples'][] = [
                'id' => (int)($item['id'] ?? 0),
                'hotel_name' => $hotelName,
                'ota_hotel_id' => $otaHotelId,
                'metrics_summary' => (string)($item['metrics_summary'] ?? ''),
                'raw_data' => is_array($decodedRaw) ? $decodedRaw : $rawData,
            ];
        }
    }

    private function finalizeOnlineHistoryGroup(array $group): array
    {
        $hotelNames = array_values($group['_hotel_name_map'] ?? []);
        $otaHotelIds = array_values($group['_ota_hotel_id_map'] ?? []);
        $batchNos = array_values($group['_batch_no_map'] ?? []);
        $recordCount = (int)($group['record_count'] ?? 1);
        $rawRecordCount = (int)($group['raw_record_count'] ?? $recordCount);
        $hotelCount = count($hotelNames);
        $failedCount = (int)($group['_failed_count'] ?? 0);
        $emptyCount = (int)($group['_empty_count'] ?? 0);
        $successCount = (int)($group['_success_count'] ?? 0);

        $group['hotel_count'] = $hotelCount;
        $group['merged_hotel_names'] = $hotelNames;
        $group['merged_ota_hotel_ids'] = $otaHotelIds;
        $group['merged_batch_nos'] = $batchNos;

        if ($recordCount > 1 || $rawRecordCount > 1) {
            $group['hotel_name'] = $hotelCount > 1 ? '全部酒店（' . $hotelCount . '家）' : ($hotelNames[0] ?? $group['hotel_name'] ?? '-');
            $group['ota_hotel_id'] = count($otaHotelIds) > 1 ? '多个' : ($otaHotelIds[0] ?? $group['ota_hotel_id'] ?? '');
            $group['metrics_summary'] = $this->buildMergedHistoryMetricSummary($group);
            $group['raw_data'] = $this->buildMergedHistoryRawData($group);
        }

        if ($failedCount > 0) {
            $group['status'] = 'failed';
        } elseif ($successCount > 0) {
            $group['status'] = 'success';
        } elseif ($emptyCount > 0) {
            $group['status'] = 'empty';
        }
        $group['status_label'] = $this->historyStatusLabel((string)($group['status'] ?? ''));

        unset(
            $group['_dedupe_key_map'],
            $group['_hotel_name_map'],
            $group['_ota_hotel_id_map'],
            $group['_batch_no_map'],
            $group['_raw_samples'],
            $group['_amount_total'],
            $group['_quantity_total'],
            $group['_order_total'],
            $group['_data_value_total'],
            $group['_failed_count'],
            $group['_empty_count'],
            $group['_success_count']
        );

        return $group;
    }

    private function buildMergedHistoryMetricSummary(array $group): string
    {
        $recordCount = (int)($group['record_count'] ?? 0);
        $rawRecordCount = (int)($group['raw_record_count'] ?? $recordCount);
        $hotelCount = (int)($group['hotel_count'] ?? 0);
        $amountTotal = (float)($group['_amount_total'] ?? 0);
        $quantityTotal = (float)($group['_quantity_total'] ?? 0);
        $orderTotal = (float)($group['_order_total'] ?? 0);
        $dataValueTotal = (float)($group['_data_value_total'] ?? 0);
        $failedCount = (int)($group['_failed_count'] ?? 0);

        $metrics = [];
        if ($rawRecordCount > $recordCount && $recordCount > 0) {
            $metrics[] = '合并 ' . number_format($rawRecordCount) . ' 条为 ' . number_format($recordCount) . ' 条';
        } elseif ($recordCount > 0) {
            $metrics[] = '合并 ' . number_format($recordCount) . ' 条';
        }
        if ($hotelCount > 0) {
            $metrics[] = number_format($hotelCount) . ' 家酒店';
        }
        if ($quantityTotal > 0) {
            $metrics[] = '间夜 ' . number_format($quantityTotal);
        }
        if ($orderTotal > 0) {
            $metrics[] = '订单 ' . number_format($orderTotal);
        }
        if ($amountTotal > 0 && $quantityTotal > 0) {
            $metrics[] = '均房价 ¥' . number_format($amountTotal / $quantityTotal, 2);
        } elseif ($amountTotal > 0) {
            $metrics[] = '金额 ¥' . number_format($amountTotal, 2);
        }
        if ($dataValueTotal > 0 && $amountTotal <= 0 && $quantityTotal <= 0 && $orderTotal <= 0) {
            $metrics[] = '指标值 ' . number_format($dataValueTotal, 2);
        }
        if ($failedCount > 0) {
            $metrics[] = '异常 ' . number_format($failedCount) . ' 条';
        }

        return empty($metrics) ? '-' : implode(' / ', $metrics);
    }

    private function buildMergedHistoryRawData(array $group): string
    {
        $payload = [
            'merged' => true,
            'batch_no' => (string)($group['batch_no'] ?? ''),
            'data_date' => (string)($group['data_date'] ?? ''),
            'platform' => (string)($group['platform_label'] ?? $group['platform'] ?? ''),
            'data_type' => (string)($group['data_type_label'] ?? $group['data_type'] ?? ''),
            'record_count' => (int)($group['record_count'] ?? 0),
            'raw_record_count' => (int)($group['raw_record_count'] ?? $group['record_count'] ?? 0),
            'hotel_count' => (int)($group['hotel_count'] ?? 0),
            'record_ids' => $group['record_ids'] ?? [],
            'batch_nos' => $group['merged_batch_nos'] ?? [],
            'hotel_names' => $group['merged_hotel_names'] ?? [],
            'ota_hotel_ids' => $group['merged_ota_hotel_ids'] ?? [],
            'sample_records' => $group['_raw_samples'] ?? [],
        ];

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    private function buildHistoryHotelDisplayName(array $row, array $hotelMap): string
    {
        $compareType = (string)($row['compare_type'] ?? '');
        if ($compareType === 'competitor_avg' || (string)($row['hotel_id'] ?? '') === '-1') {
            return '竞争圈平均';
        }

        $name = trim((string)($row['hotel_name'] ?? ''));
        if ($name !== '' && !$this->isDirtyQuestionMarkName($name)) {
            return $name;
        }

        $systemHotelId = $row['system_hotel_id'] ?? null;
        if ($systemHotelId && isset($hotelMap[(int)$systemHotelId])) {
            return $hotelMap[(int)$systemHotelId];
        }

        $otaHotelId = (string)($row['hotel_id'] ?? '');
        return $otaHotelId !== '' ? 'OTA酒店ID ' . $otaHotelId : '未知酒店';
    }

    private function isDirtyQuestionMarkName(string $name): bool
    {
        $text = preg_replace('/\s+/u', '', $name) ?? '';
        if ($text === '') {
            return false;
        }
        $questionCount = substr_count($text, '?');
        if ($questionCount === 0) {
            return false;
        }
        return $questionCount >= 4 && ($questionCount / max(1, strlen($text))) >= 0.35;
    }

    private function normalizeHistoryPlatformCode($platform): string
    {
        $value = strtolower((string)$platform);
        if (in_array($value, ['ctrip', '携程'], true)) {
            return 'ctrip';
        }
        if (in_array($value, ['meituan', '美团'], true)) {
            return 'meituan';
        }
        if (in_array($value, ['qunar', '去哪儿'], true)) {
            return 'qunar';
        }
        return $value !== '' ? $value : 'unknown';
    }

    private function historyPlatformLabel(string $platform): string
    {
        return [
            'ctrip' => '携程',
            'meituan' => '美团',
            'qunar' => '去哪儿',
            'unknown' => '未知',
        ][$platform] ?? $platform;
    }

    private function normalizeHistoryDataType(string $dataType, string $compareType): string
    {
        if ($compareType === 'competitor_avg') {
            return 'competitor';
        }
        $value = strtolower(trim($dataType));
        if ($value === '') {
            return 'business';
        }
        if (in_array($value, ['comment', 'comments'], true)) {
            return 'review';
        }
        if (in_array($value, ['ad', 'ads'], true)) {
            return 'advertising';
        }
        return $value;
    }

    private function historyDataTypeLabel(string $dataType): string
    {
        return [
            'business' => '经营数据',
            'traffic' => '流量数据',
            'competitor' => '竞对数据',
            'review' => '点评数据',
            'advertising' => '广告数据',
        ][$dataType] ?? $dataType;
    }

    private function resolveHistoryStatus(array $row, string $rawData): string
    {
        $status = strtolower((string)($row['status'] ?? ''));
        if (in_array($status, ['failed', 'fail', 'error'], true)) {
            return 'failed';
        }
        if ($rawData !== '') {
            $decoded = json_decode($rawData, true);
            if (is_array($decoded) && (isset($decoded['error']) || isset($decoded['errors']))) {
                return 'failed';
            }
            return 'success';
        }

        $metrics = [
            $row['amount'] ?? 0,
            $row['quantity'] ?? 0,
            $row['book_order_num'] ?? 0,
            $row['data_value'] ?? 0,
            $row['list_exposure'] ?? 0,
            $row['detail_exposure'] ?? 0,
            $row['order_submit_num'] ?? 0,
        ];
        foreach ($metrics as $metric) {
            if ((float)$metric > 0) {
                return 'success';
            }
        }
        return 'empty';
    }

    private function historyStatusLabel(string $status): string
    {
        return [
            'success' => '成功',
            'failed' => '失败',
            'empty' => '数据为空',
        ][$status] ?? $status;
    }

    private function isMyHotelHistoryRow(array $row): bool
    {
        if (!empty($row['system_hotel_id'])) {
            return true;
        }
        if (($row['compare_type'] ?? '') === 'self') {
            return true;
        }
        return trim((string)($row['hotel_name'] ?? '')) === '我的酒店';
    }

    private function buildHistoryBatchNo(array $row, string $rawData, string $platformCode, string $dataType): string
    {
        if (!empty($row['batch_no'])) {
            return (string)$row['batch_no'];
        }

        if ($rawData !== '') {
            $decoded = json_decode($rawData, true);
            if (is_array($decoded)) {
                foreach (['batch_no', 'batchNo', 'fetch_batch_no', 'fetchBatchNo'] as $key) {
                    if (!empty($decoded[$key])) {
                        return (string)$decoded[$key];
                    }
                }
            }
        }

        $fetchTime = (string)($row['create_time'] ?? '');
        $batchTime = $fetchTime !== '' && strtotime($fetchTime) !== false
            ? date('YmdHis', strtotime($fetchTime))
            : 'unknown';
        return 'B' . $batchTime . '-' . $platformCode . '-' . $dataType;
    }

    private function buildHistoryMetricSummary(array $row, string $rawData): string
    {
        $raw = $rawData !== '' ? json_decode($rawData, true) : [];
        $raw = is_array($raw) ? $raw : [];
        $metrics = [];

        $exposure = (int)($row['list_exposure'] ?? $raw['listExposure'] ?? $raw['exposure'] ?? $raw['exposure_count'] ?? 0);
        if ($exposure > 0) {
            $metrics[] = '曝光 ' . $exposure;
        }

        $views = (int)($row['detail_exposure'] ?? $raw['totalDetailNum'] ?? $raw['detailExposure'] ?? $raw['views'] ?? 0);
        if ($views > 0) {
            $metrics[] = '浏览 ' . $views;
        }

        $orders = (int)($row['book_order_num'] ?? $row['order_submit_num'] ?? $raw['bookOrderNum'] ?? $raw['orderCount'] ?? 0);
        if ($orders > 0) {
            $metrics[] = '订单 ' . $orders;
        }

        $amount = (float)($row['amount'] ?? 0);
        $quantity = (float)($row['quantity'] ?? 0);
        if ($amount > 0 && $quantity > 0) {
            $metrics[] = '均房价 ¥' . number_format($amount / $quantity, 2);
        }

        $rank = $raw['amountRank'] ?? $raw['quantityRank'] ?? $raw['bookOrderNumRank'] ?? $raw['rank'] ?? null;
        if ($rank !== null && $rank !== '') {
            $metrics[] = '排名 ' . $rank;
        }

        if (empty($metrics)) {
            $dataValue = (float)($row['data_value'] ?? 0);
            if ($dataValue > 0) {
                $metrics[] = '指标值 ' . number_format($dataValue, 2);
            }
        }

        return empty($metrics) ? '-' : implode(' / ', $metrics);
    }


    private function buildOnlineHistorySummary(array $historyList): array
    {
        $latestFetchTime = '';
        $today = date('Y-m-d');
        $todayRecords = 0;
        $failedRecords = 0;

        foreach ($historyList as $item) {
            $fetchTime = (string)($item['fetch_time'] ?? '');
            if ($fetchTime !== '' && ($latestFetchTime === '' || strcmp($fetchTime, $latestFetchTime) > 0)) {
                $latestFetchTime = $fetchTime;
            }
            if ($fetchTime !== '' && substr($fetchTime, 0, 10) === $today) {
                $todayRecords++;
            }
            if (($item['status'] ?? '') !== 'success') {
                $failedRecords++;
            }
        }

        return [
            'total_records' => count($historyList),
            'latest_fetch_time' => $latestFetchTime,
            'today_records' => $todayRecords,
            'failed_records' => $failedRecords,
        ];
    }
    
    /**
     * 获取数据统计汇总
     */
    public function dailyDataSummary(): Response
    {
        $this->checkPermission();
        
        $startDate = $this->request->get('start_date', date('Y-m-d', strtotime('-7 days')));
        $endDate = $this->request->get('end_date', date('Y-m-d'));
        $dataType = $this->request->get('data_type', '');
        $hotelId = trim((string)$this->request->get('system_hotel_id', $this->request->get('hotel_id', '')));
        $permittedHotelIds = [];
        if (!$this->currentUser->isSuperAdmin()) {
            $permittedHotelIds = $this->currentUser->getPermittedHotelIds();
            if (empty($permittedHotelIds)) {
                return $this->success([
                    'daily' => [],
                    'total' => [
                        'total_amount' => 0,
                        'total_quantity' => 0,
                        'total_book_order_num' => 0,
                        'avg_comment_score' => 0,
                    ],
                ]);
            }
        }
        
        // 按日期汇总
        $dailyQuery = Db::name('online_daily_data')
            ->field('data_date, SUM(amount) as total_amount, SUM(quantity) as total_quantity, SUM(book_order_num) as total_book_order_num, AVG(comment_score) as avg_comment_score')
            ->where('data_date', '>=', $startDate)
            ->where('data_date', '<=', $endDate);
        $this->applyDataTypeFilter($dailyQuery, $dataType);
        if ($hotelId !== '') {
            $this->applyOnlineDailyDataHotelFilter($dailyQuery, $hotelId);
        }
        if (!$this->currentUser->isSuperAdmin()) {
            $dailyQuery->whereIn('system_hotel_id', $permittedHotelIds);
        }
        $dailySummary = $dailyQuery->group('data_date')
            ->order('data_date', 'desc')
            ->select()
            ->toArray();
        
        // 总计
        $totalQuery = Db::name('online_daily_data')
            ->field('SUM(amount) as total_amount, SUM(quantity) as total_quantity, SUM(book_order_num) as total_book_order_num, AVG(comment_score) as avg_comment_score')
            ->where('data_date', '>=', $startDate)
            ->where('data_date', '<=', $endDate);
        $this->applyDataTypeFilter($totalQuery, $dataType);
        if ($hotelId !== '') {
            $this->applyOnlineDailyDataHotelFilter($totalQuery, $hotelId);
        }
        if (!$this->currentUser->isSuperAdmin()) {
            $totalQuery->whereIn('system_hotel_id', $permittedHotelIds);
        }
        $totalSummary = $totalQuery->find();
        
        return $this->success([
            'daily' => $dailySummary,
            'total' => $totalSummary,
        ]);
    }

    /**
     * 获取酒店列表（用于筛选）- 根据用户权限过滤
     */
    public function hotelList(): Response
    {
        // 从请求中获取当前用户（中间件已注入）
        $currentUser = $this->request->user ?? null;
        
        // 只检查登录，不强制要求酒店关联
        if (!$currentUser) {
            return $this->error('未登录', 401);
        }
        
        try {
            $dataType = $this->request->get('data_type', '');
            
            $query = Db::name('online_daily_data')
                ->field('hotel_id, MAX(hotel_name) as hotel_name, MAX(system_hotel_id) as system_hotel_id')
                ->group('hotel_id');

            $this->applyDataTypeFilter($query, $dataType);
            
            // 非超级管理员只能看自己酒店的数据
            if (!$currentUser->isSuperAdmin()) {
                $permittedHotelIds = $currentUser->getPermittedHotelIds();
                if (empty($permittedHotelIds)) {
                    // 没有酒店关联则返回空列表
                    return $this->success([]);
                }
                $query->whereIn('system_hotel_id', $permittedHotelIds);
            }
            
            $hotels = $this->mergeOnlineDataHotelList($query->select()->toArray());
            
            // 添加 id 字段用于前端筛选
            foreach ($hotels as &$hotel) {
                $hotel['id'] = $hotel['system_hotel_id'] ?? $hotel['hotel_id'];
            }
            
            return $this->success($hotels);
        } catch (\Exception $e) {
            return $this->error('获取酒店列表失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 自动获取并保存数据（每个门店独立运行，每天只获取一次）
     */
    private function mergeOnlineDataHotelList(array $hotels): array
    {
        $merged = [];
        foreach ($hotels as $hotel) {
            if (!is_array($hotel)) {
                continue;
            }
            $key = $this->onlineDataHotelKey($hotel);
            if ($key === '') {
                continue;
            }

            $mapKey = is_int($key) ? 'system:' . $key : 'ota:' . $key;
            if (!isset($merged[$mapKey])) {
                $hotel['id'] = $key;
                if (!isset($hotel['ota_hotel_id'])) {
                    $hotel['ota_hotel_id'] = $hotel['hotel_id'] ?? '';
                }
                $merged[$mapKey] = $hotel;
                continue;
            }

            if (empty($merged[$mapKey]['hotel_name']) && !empty($hotel['hotel_name'])) {
                $merged[$mapKey]['hotel_name'] = $hotel['hotel_name'];
            }
        }

        return array_values($merged);
    }

    public function autoFetch(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');
        @set_time_limit(0);
        
        $systemHotelId = $this->request->post('system_hotel_id', null);
        
        // 非超级管理员必须有门店ID，且只能获取自己有权限的门店数据
        if (!$this->currentUser->isSuperAdmin()) {
            if (empty($systemHotelId)) {
                // 使用用户关联的酒店
                $systemHotelId = $this->currentUser->hotel_id;
            }
            if (empty($systemHotelId)) {
                return $this->error('您未关联酒店，无法获取数据');
            }
            // 检查用户是否有该门店的权限
            if (!$this->currentUser->hasHotelPermission((int)$systemHotelId, 'can_fetch_online_data')) {
                return $this->error('无权获取该门店的数据');
            }
        }
        
        if (empty($systemHotelId)) {
            return $this->error('请选择要获取数据的门店');
        }
        
        if (!$this->hasAnyPlatformFetchConfigForHotel((int)$systemHotelId)) {
            \think\facade\Log::warning('平台数据自动获取失败: 未配置携程或美团凭证', [
                'user_id' => $this->currentUser->id,
                'hotel_id' => $systemHotelId
            ]);
            return $this->error('未配置携程或美团抓取凭证，请先在酒店管理中关联平台配置');
        }
        
        // 获取昨天的数据
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $interactiveBrowser = filter_var(
            $this->request->post('interactive_browser', $this->request->post('interactiveBrowser', false)),
            FILTER_VALIDATE_BOOLEAN
        );
        $autoFetchModeRaw = $this->request->post('auto_fetch_mode', $this->request->post('autoMode', null));
        $fetchOptions = ['interactive_browser' => $interactiveBrowser];
        if ($autoFetchModeRaw !== null && trim((string)$autoFetchModeRaw) !== '') {
            $fetchOptions['auto_fetch_mode'] = $autoFetchModeRaw;
        }
        
        try {
            $result = $this->executeAutoFetch((int)$systemHotelId, $yesterday, $fetchOptions);
            $this->updateFetchStatus((int)$systemHotelId, (bool)$result['success'], (string)$result['message'], $yesterday, [
                'saved_count' => (int)($result['saved_count'] ?? 0),
                'auto_fetch_mode' => $result['auto_fetch_mode'] ?? null,
                'platform_results' => $result['platform_results'] ?? [],
            ]);

            if ($result['success']) {
                OperationLog::record('online_data', 'auto_fetch', "平台数据自动获取: {$result['saved_count']}条 (门店ID: {$systemHotelId})", $this->currentUser->id);
                return $this->success([
                    'saved_count' => (int)($result['saved_count'] ?? 0),
                    'auto_fetch_mode' => $result['auto_fetch_mode'] ?? 'hybrid_auto',
                    'auto_fetch_mode_label' => $result['auto_fetch_mode_label'] ?? '最低成本自动',
                    'platform_results' => $result['platform_results'] ?? [],
                ], '自动获取成功');
            }

            return $this->error('自动获取失败: ' . $result['message'], 400, [
                'saved_count' => (int)($result['saved_count'] ?? 0),
                'auto_fetch_mode' => $result['auto_fetch_mode'] ?? 'hybrid_auto',
                'auto_fetch_mode_label' => $result['auto_fetch_mode_label'] ?? '最低成本自动',
                'platform_results' => $result['platform_results'] ?? [],
            ]);

        } catch (\Exception $e) {
            \think\facade\Log::error('平台数据自动获取异常: ' . $e->getMessage(), [
                'user_id' => $this->currentUser->id,
                'hotel_id' => $systemHotelId,
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->updateFetchStatus($systemHotelId, false, '获取异常: ' . $e->getMessage(), $yesterday);
            
            return $this->error('异常: ' . $e->getMessage());
        }
    }
    
    /**
     * 更新获取状态
     */
    private function updateFetchStatus(?int $hotelId, bool $success, string $message, ?string $dataDate = null, array $details = []): void
    {
        $statusKey = $hotelId ? "online_data_auto_fetch_status_{$hotelId}" : 'online_data_auto_fetch_status';
        $status = cache($statusKey) ?: [];
        if (!is_array($status)) {
            $status = [];
        }

        $runAt = date('Y-m-d H:i:s');
        $dataDate = $dataDate ?: date('Y-m-d', strtotime('-1 day'));
        $runRecord = [
            'run_at' => $runAt,
            'data_date' => $dataDate,
            'success' => $success,
            'message' => $message,
        ];
        if (array_key_exists('saved_count', $details)) {
            $runRecord['saved_count'] = (int)$details['saved_count'];
        }
        if (!empty($details['auto_fetch_mode'])) {
            $runRecord['auto_fetch_mode'] = $this->normalizeAutoFetchMode($details['auto_fetch_mode']);
            $runRecord['auto_fetch_mode_label'] = $this->autoFetchModeLabel($runRecord['auto_fetch_mode']);
        }
        if (!empty($details['platform_results']) && is_array($details['platform_results'])) {
            $runRecord['platform_results'] = $details['platform_results'];
        }

        $status['last_run_time'] = $runAt;
        $status['last_data_date'] = $dataDate;
        $status['last_result'] = [
            'success' => $success,
            'message' => $message
        ];
        if (array_key_exists('saved_count', $details)) {
            $status['last_result']['saved_count'] = (int)$details['saved_count'];
        }
        if (!empty($details['auto_fetch_mode'])) {
            $status['auto_fetch_mode'] = $this->normalizeAutoFetchMode($details['auto_fetch_mode']);
            $status['last_result']['auto_fetch_mode'] = $status['auto_fetch_mode'];
            $status['last_result']['auto_fetch_mode_label'] = $this->autoFetchModeLabel($status['auto_fetch_mode']);
        }
        if (!empty($details['platform_results']) && is_array($details['platform_results'])) {
            $status['last_result']['platform_results'] = $details['platform_results'];
        }

        $recentRuns = $status['recent_runs'] ?? [];
        $recentRuns = is_array($recentRuns) ? $recentRuns : [];
        array_unshift($recentRuns, $runRecord);
        $status['recent_runs'] = array_slice($recentRuns, 0, 10);

        $failedRecords = $status['failed_records'] ?? [];
        $failedRecords = is_array($failedRecords) ? $failedRecords : [];
        $failedRecords = array_values(array_filter($failedRecords, function ($item) use ($dataDate) {
            return (string)($item['data_date'] ?? '') !== $dataDate;
        }));
        if (!$success) {
            array_unshift($failedRecords, [
                'data_date' => $dataDate,
                'last_failed_at' => $runAt,
                'message' => $message,
            ]);
        }
        $status['failed_records'] = array_slice($failedRecords, 0, 30);

        cache($statusKey, $status, 86400 * 30);
    }

    private function normalizeFetchScheduleTime(string $scheduleTime): ?string
    {
        $scheduleTime = trim($scheduleTime);
        if (!preg_match('/^([01]?[0-9]|2[0-3]):([0-5][0-9])$/', $scheduleTime, $matches)) {
            return null;
        }
        return sprintf('%02d:%02d', (int)$matches[1], (int)$matches[2]);
    }

    private function resolveAutoFetchHotelId($hotelId): ?int
    {
        $hotelId = is_numeric($hotelId) ? (int)$hotelId : 0;
        if ($this->currentUser->isSuperAdmin()) {
            return $hotelId > 0 ? $hotelId : null;
        }

        $permittedHotelIds = array_values(array_map('intval', $this->currentUser->getPermittedHotelIds()));
        if (empty($permittedHotelIds)) {
            return null;
        }
        if ($hotelId <= 0) {
            return $permittedHotelIds[0];
        }
        return in_array($hotelId, $permittedHotelIds, true) ? $hotelId : null;
    }

    private function hasCtripFetchConfigForHotel(int $hotelId): bool
    {
        $fetchConfig = $this->resolveCtripFetchConfigForHotel($hotelId);
        if (trim((string)($fetchConfig['cookies'] ?? '')) !== '') {
            return true;
        }
        if (!empty($fetchConfig) && $this->ctripProfileStoreIdFromConfig($fetchConfig, $hotelId) !== '') {
            return true;
        }
        if ($this->ctripProfileExistsForConfig($fetchConfig, $hotelId)) {
            return true;
        }

        $tasks = $this->buildAutoFetchConfigTaskPlan($hotelId, date('Y-m-d', strtotime('-1 day')), $fetchConfig, [], $this->getAutoFetchSavedDataConfigs());
        return (bool)array_filter($tasks, static fn(array $task): bool => ($task['platform'] ?? '') === 'ctrip');
    }

    private function hasMeituanFetchConfigForHotel(int $hotelId): bool
    {
        $fetchConfig = $this->resolveMeituanFetchConfigForHotel($hotelId);
        $apiStatus = $this->meituanAutoFetchConfigStatus($fetchConfig);
        if (!empty($apiStatus['api_configured']) || $this->meituanProfileExistsForConfig($fetchConfig)) {
            return true;
        }

        $tasks = $this->buildAutoFetchConfigTaskPlan($hotelId, date('Y-m-d', strtotime('-1 day')), [], $fetchConfig, $this->getAutoFetchSavedDataConfigs());
        return (bool)array_filter($tasks, static fn(array $task): bool => ($task['platform'] ?? '') === 'meituan');
    }

    private function hasAnyPlatformFetchConfigForHotel(int $hotelId): bool
    {
        return $this->hasCtripFetchConfigForHotel($hotelId) || $this->hasMeituanFetchConfigForHotel($hotelId);
    }

    private function buildAutoFetchPlatformStatus(int $hotelId): array
    {
        $ctripConfig = $this->resolveCtripFetchConfigForHotel($hotelId);
        $meituanConfig = $this->resolveMeituanFetchConfigForHotel($hotelId);
        $savedConfigs = $this->getAutoFetchSavedDataConfigs();
        $runMode = $this->resolveAutoFetchRunMode($hotelId);
        $ctripHasProfile = $this->ctripProfileExistsForConfig($ctripConfig, $hotelId);
        $meituanHasProfile = $this->meituanProfileExistsForConfig($meituanConfig);
        $meituanApiStatus = $this->meituanAutoFetchConfigStatus($meituanConfig);
        $ctripMode = $this->resolvePlatformAutoFetchMode($ctripConfig, ['auto_fetch_mode' => $runMode], 'ctrip');
        $meituanMode = $this->resolvePlatformAutoFetchMode($meituanConfig, ['auto_fetch_mode' => $runMode], 'meituan');
        $ctripTasks = array_values(array_filter(
            $this->buildAutoFetchConfigTaskPlan($hotelId, date('Y-m-d', strtotime('-1 day')), $ctripConfig, [], $savedConfigs),
            static fn(array $task): bool => ($task['platform'] ?? '') === 'ctrip'
        ));
        $meituanTasks = array_values(array_filter(
            $this->buildAutoFetchConfigTaskPlan($hotelId, date('Y-m-d', strtotime('-1 day')), [], $meituanConfig, $savedConfigs),
            static fn(array $task): bool => ($task['platform'] ?? '') === 'meituan'
        ));

        return [
            'ctrip' => [
                'configured' => $this->hasCtripFetchConfigForHotel($hotelId),
                'name' => (string)($ctripConfig['name'] ?? $ctripConfig['hotel_name'] ?? ''),
                'mode' => $this->autoFetchModeLabel($ctripMode),
                'auto_fetch_mode' => $ctripMode,
                'cookie_configured' => trim((string)($ctripConfig['cookies'] ?? $ctripConfig['cookie'] ?? '')) !== '',
                'profile_configured' => $ctripHasProfile,
                'has_profile' => $ctripHasProfile,
                'task_count' => count($ctripTasks),
                'task_modules' => array_values(array_unique(array_map(static fn(array $task): string => (string)($task['module'] ?? ''), $ctripTasks))),
                'next_action' => $this->autoFetchPlatformNextAction($ctripMode, trim((string)($ctripConfig['cookies'] ?? $ctripConfig['cookie'] ?? '')) !== '', $ctripHasProfile, count($ctripTasks)),
                'entry_url' => 'https://ebooking.ctrip.com',
            ],
            'meituan' => [
                'configured' => $this->hasMeituanFetchConfigForHotel($hotelId),
                'name' => (string)($meituanConfig['name'] ?? $meituanConfig['hotel_name'] ?? ''),
                'mode' => $this->autoFetchModeLabel($meituanMode),
                'auto_fetch_mode' => $meituanMode,
                'api_configured' => (bool)$meituanApiStatus['api_configured'],
                'cookie_configured' => (bool)$meituanApiStatus['has_cookies'],
                'partner_id_configured' => (bool)$meituanApiStatus['has_partner_id'],
                'poi_id_configured' => (bool)$meituanApiStatus['has_poi_id'],
                'profile_configured' => $meituanHasProfile,
                'has_profile' => $meituanHasProfile,
                'task_count' => count($meituanTasks),
                'task_modules' => array_values(array_unique(array_map(static fn(array $task): string => (string)($task['module'] ?? ''), $meituanTasks))),
                'missing_fields' => $meituanApiStatus['missing_fields'],
                'missing_text' => $meituanApiStatus['missing_text'],
                'next_action' => $this->autoFetchPlatformNextAction($meituanMode, (bool)$meituanApiStatus['api_configured'], $meituanHasProfile, count($meituanTasks)),
                'entry_url' => 'https://eb.meituan.com',
            ],
        ];
    }

    private function autoFetchPlatformNextAction(string $mode, bool $hasCookie, bool $hasProfile, int $taskCount): string
    {
        $mode = $this->normalizeAutoFetchMode($mode);
        if ($mode === 'cookie_config' && !$hasCookie && $taskCount === 0) {
            return '补充 Cookie、Request URL、Payload 或平台 ID';
        }
        if ($mode === 'profile_browser' && !$hasProfile) {
            return '先运行一次浏览器 Profile 登录采集';
        }
        if ($mode === 'hybrid_auto' && !$hasCookie && !$hasProfile && $taskCount === 0) {
            return '至少配置 Cookie/接口参数或浏览器 Profile';
        }
        if ($mode === 'hybrid_auto' && !$hasProfile) {
            return 'Cookie/配置可先跑；建议补建 Profile 处理动态页面';
        }
        if ($mode === 'hybrid_auto' && !$hasCookie && $taskCount === 0) {
            return 'Profile 可先跑；建议补充 Cookie/接口配置提高稳定性';
        }

        return '配置可用';
    }

    private function autoFetchStatusKey(?int $hotelId): string
    {
        return $hotelId ? "online_data_auto_fetch_status_{$hotelId}" : 'online_data_auto_fetch_status';
    }

    private function resolveAutoFetchRecordHotelIds($hotelIdRaw): array
    {
        $requestedHotelId = trim((string)$hotelIdRaw);
        $permittedHotelIds = array_values(array_map('intval', $this->currentUser->getPermittedHotelIds()));
        if ($requestedHotelId !== '') {
            $hotelId = (int)$requestedHotelId;
            if ($hotelId <= 0 || !in_array($hotelId, $permittedHotelIds, true)) {
                return [];
            }
            return [$hotelId];
        }

        return $permittedHotelIds;
    }

    private function getAutoFetchRecordHotelMap(array $hotelIds): array
    {
        $hotelIds = array_values(array_filter(array_map('intval', $hotelIds), static fn(int $id): bool => $id > 0));
        if (empty($hotelIds)) {
            return [];
        }

        try {
            $rows = Db::name('hotels')
                ->whereIn('id', $hotelIds)
                ->field('id,name')
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['id']] = (string)($row['name'] ?? ('门店ID ' . $row['id']));
        }

        return $map;
    }

    private function buildAutoFetchRecordRows(array $status, int $hotelId, string $hotelName, array $filters = []): array
    {
        $rows = [];
        $runs = is_array($status['recent_runs'] ?? null) ? $status['recent_runs'] : [];
        foreach ($runs as $runIndex => $run) {
            if (!is_array($run)) {
                continue;
            }
            $platformResults = is_array($run['platform_results'] ?? null) && !empty($run['platform_results'])
                ? array_values($run['platform_results'])
                : [[
                    'platform' => '',
                    'success' => (bool)($run['success'] ?? false),
                    'message' => (string)($run['message'] ?? ''),
                    'saved_count' => (int)($run['saved_count'] ?? 0),
                ]];

            foreach ($platformResults as $platformIndex => $platformResult) {
                if (!is_array($platformResult)) {
                    continue;
                }
                $record = $this->normalizeAutoFetchRecordRow($hotelId, $hotelName, $run, $platformResult, (int)$runIndex, (int)$platformIndex);
                if ($this->matchesAutoFetchRecordFilters($record, $filters)) {
                    $rows[] = $record;
                }
            }
        }

        return $rows;
    }

    private function normalizeAutoFetchRecordRow(int $hotelId, string $hotelName, array $run, array $platformResult, int $runIndex, int $platformIndex): array
    {
        $platform = strtolower(trim((string)($platformResult['platform'] ?? '')));
        $success = (bool)($platformResult['success'] ?? false);
        $skipped = (bool)($platformResult['skipped'] ?? false);
        $status = $success ? 'success' : ($skipped ? 'skipped' : 'failed');
        $runTime = (string)($run['run_time'] ?? '');
        $dataDate = (string)($run['data_date'] ?? '');
        $moduleSummary = $this->formatAutoFetchModuleSummary(is_array($platformResult['modules'] ?? null) ? $platformResult['modules'] : []);
        $id = substr(sha1(implode('|', [$hotelId, $runTime, $dataDate, $platform, (string)$runIndex, (string)$platformIndex])), 0, 24);

        return [
            'id' => $id,
            'hotel_id' => $hotelId,
            'hotel_name' => $hotelName,
            'run_time' => $runTime,
            'data_date' => $dataDate,
            'platform' => $platform,
            'platform_label' => $platform === 'meituan' ? '美团' : ($platform === 'ctrip' ? '携程' : '全部平台'),
            'status' => $status,
            'status_label' => $status === 'success' ? '成功' : ($status === 'skipped' ? '跳过' : '失败'),
            'saved_count' => (int)($platformResult['saved_count'] ?? 0),
            'module_summary' => $moduleSummary !== '' ? $moduleSummary : '-',
            'message' => (string)($platformResult['message'] ?? $run['message'] ?? '-'),
            'run_message' => (string)($run['message'] ?? ''),
            'auto_fetch_mode' => (string)($platformResult['auto_fetch_mode'] ?? $run['auto_fetch_mode'] ?? ''),
            'mode_label' => (string)($platformResult['mode_label'] ?? $run['auto_fetch_mode_label'] ?? ''),
        ];
    }

    private function formatAutoFetchModuleSummary(array $modules): string
    {
        $parts = [];
        foreach ($modules as $module) {
            if (!is_array($module)) {
                continue;
            }
            $name = trim((string)($module['module'] ?? ''));
            if ($name === '') {
                continue;
            }
            $savedCount = (int)($module['saved_count'] ?? 0);
            $state = !empty($module['success']) ? 'ok' : (!empty($module['skipped']) ? 'skip' : 'fail');
            $strategy = trim((string)($module['strategy'] ?? ''));
            $strategyText = $strategy !== '' ? $strategy . ':' : '';
            $parts[] = $name . '[' . $strategyText . $state . ':' . $savedCount . ']';
        }

        return implode(' / ', $parts);
    }

    private function matchesAutoFetchRecordFilters(array $record, array $filters): bool
    {
        $startDate = trim((string)($filters['start_date'] ?? ''));
        $endDate = trim((string)($filters['end_date'] ?? ''));
        $source = trim((string)($filters['source'] ?? ''));
        $status = trim((string)($filters['status'] ?? ''));
        $dataDate = (string)($record['data_date'] ?? '');

        if ($startDate !== '' && $dataDate !== '' && $dataDate < $startDate) {
            return false;
        }
        if ($endDate !== '' && $dataDate !== '' && $dataDate > $endDate) {
            return false;
        }
        if ($source !== '' && (string)($record['platform'] ?? '') !== $source) {
            return false;
        }
        if ($status !== '' && (string)($record['status'] ?? '') !== $status) {
            return false;
        }

        return true;
    }

    private function removeAutoFetchRecordIds(array $status, int $hotelId, array $idSet): array
    {
        $runs = is_array($status['recent_runs'] ?? null) ? $status['recent_runs'] : [];
        $deletedCount = 0;
        $newRuns = [];
        foreach ($runs as $runIndex => $run) {
            if (!is_array($run)) {
                continue;
            }
            $platformResults = is_array($run['platform_results'] ?? null) ? array_values($run['platform_results']) : [];
            if (empty($platformResults)) {
                $record = $this->normalizeAutoFetchRecordRow($hotelId, '', $run, [
                    'platform' => '',
                    'success' => (bool)($run['success'] ?? false),
                    'message' => (string)($run['message'] ?? ''),
                    'saved_count' => (int)($run['saved_count'] ?? 0),
                ], (int)$runIndex, 0);
                if (isset($idSet[$record['id']])) {
                    $deletedCount++;
                    continue;
                }
                $newRuns[] = $run;
                continue;
            }

            $newPlatformResults = [];
            foreach ($platformResults as $platformIndex => $platformResult) {
                if (!is_array($platformResult)) {
                    continue;
                }
                $record = $this->normalizeAutoFetchRecordRow($hotelId, '', $run, $platformResult, (int)$runIndex, (int)$platformIndex);
                if (isset($idSet[$record['id']])) {
                    $deletedCount++;
                    continue;
                }
                $newPlatformResults[] = $platformResult;
            }
            if (!empty($newPlatformResults)) {
                $run['platform_results'] = $newPlatformResults;
                $run['saved_count'] = array_sum(array_map(static fn(array $item): int => (int)($item['saved_count'] ?? 0), $newPlatformResults));
                $newRuns[] = $run;
            }
        }

        $status['recent_runs'] = $newRuns;
        return [$status, $deletedCount];
    }

    private function rebuildAutoFetchStatusHistory(array $status): array
    {
        $runs = array_values(is_array($status['recent_runs'] ?? null) ? $status['recent_runs'] : []);
        $status['recent_runs'] = $runs;
        if (empty($runs)) {
            $status['last_run_time'] = null;
            $status['last_data_date'] = null;
            $status['last_result'] = null;
            return $status;
        }

        $latest = $runs[0];
        $status['last_run_time'] = $latest['run_time'] ?? null;
        $status['last_data_date'] = $latest['data_date'] ?? null;
        $status['last_result'] = [
            'success' => (bool)($latest['success'] ?? false),
            'message' => (string)($latest['message'] ?? ''),
            'saved_count' => (int)($latest['saved_count'] ?? 0),
            'platform_results' => is_array($latest['platform_results'] ?? null) ? $latest['platform_results'] : [],
        ];

        return $status;
    }

    private function buildCtripAutoFetchMissedDates(int $hotelId, int $days = 7): array
    {
        $days = max(1, min($days, 30));
        $endTimestamp = strtotime('-1 day');
        $startTimestamp = strtotime('-' . $days . ' days');
        if ($startTimestamp === false || $endTimestamp === false) {
            return [];
        }

        $startDate = date('Y-m-d', $startTimestamp);
        $endDate = date('Y-m-d', $endTimestamp);

        try {
            $rows = Db::name('online_daily_data')
                ->where('system_hotel_id', $hotelId)
                ->where('source', 'ctrip')
                ->whereBetween('data_date', [$startDate, $endDate])
                ->field('data_date,data_type')
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            \think\facade\Log::warning('读取携程自动抓取缺失日期失败', [
                'hotel_id' => $hotelId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        $existingSet = [];
        foreach ($rows as $row) {
            $dataType = trim((string)($row['data_type'] ?? ''));
            if ($dataType === '' || $dataType === 'business') {
                $existingSet[(string)$row['data_date']] = true;
            }
        }
        $missedDates = [];
        for ($timestamp = $startTimestamp; $timestamp <= $endTimestamp; $timestamp = strtotime('+1 day', $timestamp)) {
            $date = date('Y-m-d', $timestamp);
            if (!isset($existingSet[$date])) {
                $missedDates[] = $date;
            }
        }

        return array_reverse($missedDates);
    }
    
    /**
     * 获取自动获取状态
     */
    public function autoFetchStatus(): Response
    {
        $this->checkPermission();
        
        $hotelId = $this->request->get('hotel_id', null);
        
        // 非超级管理员只能查看自己酒店的状态
        if (!$this->currentUser->isSuperAdmin()) {
            $hotelId = $this->resolveAutoFetchHotelId($hotelId);
            if ($hotelId === null) {
                return $this->success([
                    'enabled' => false,
                    'last_run_time' => null,
                    'next_run_time' => '-',
                    'last_result' => null,
                    'schedule_time' => '10:00',
                    'auto_fetch_mode' => 'hybrid_auto',
                    'auto_fetch_mode_label' => '最低成本自动',
                    'recent_runs' => [],
                    'failed_records' => [],
                    'missed_dates' => [],
                    'missed_count' => 0,
                ]);
            }
        }
        
        $statusKey = $hotelId ? "online_data_auto_fetch_status_{$hotelId}" : 'online_data_auto_fetch_status';
        $status = cache($statusKey) ?: [
            'enabled' => false,
            'last_run_time' => null,
            'next_run_time' => null,
            'last_result' => null,
            'schedule_time' => '10:00',
            'auto_fetch_mode' => 'hybrid_auto',
            'recent_runs' => [],
            'failed_records' => [],
            'missed_dates' => [],
        ];
        if (!is_array($status)) {
            $status = [];
        }
        
        // 确保必要字段存在
        if (!isset($status['enabled'])) {
            $status['enabled'] = false;
        }
        if (!isset($status['schedule_time'])) {
            $status['schedule_time'] = '10:00';
        }
        $status['schedule_time'] = $this->normalizeFetchScheduleTime((string)$status['schedule_time']) ?? '10:00';
        $status['auto_fetch_mode'] = $hotelId
            ? $this->resolveAutoFetchRunMode((int)$hotelId, ['auto_fetch_mode' => $status['auto_fetch_mode'] ?? ''])
            : $this->normalizeAutoFetchMode($status['auto_fetch_mode'] ?? 'hybrid_auto');
        $status['auto_fetch_mode_label'] = $this->autoFetchModeLabel((string)$status['auto_fetch_mode']);
        // 计算下次运行时间
        if ($status['enabled']) {
            $scheduleTime = $status['schedule_time'];
            $now = time();
            $todaySchedule = strtotime(date('Y-m-d') . ' ' . $scheduleTime . ':00');
            if ($todaySchedule > $now) {
                $status['next_run_time'] = '今天 ' . $scheduleTime;
            } else {
                $status['next_run_time'] = '明天 ' . $scheduleTime;
            }
        } else {
            $status['next_run_time'] = '未开启';
        }

        $status['recent_runs'] = array_values(is_array($status['recent_runs'] ?? null) ? $status['recent_runs'] : []);
        $status['failed_records'] = array_values(is_array($status['failed_records'] ?? null) ? $status['failed_records'] : []);
        $status['missed_dates'] = $hotelId ? $this->buildCtripAutoFetchMissedDates((int)$hotelId) : [];
        $status['missed_count'] = count($status['missed_dates']);
        $status['has_config'] = $hotelId ? $this->hasAnyPlatformFetchConfigForHotel((int)$hotelId) : false;
        $status['platforms'] = $hotelId ? $this->buildAutoFetchPlatformStatus((int)$hotelId) : [
            'ctrip' => ['configured' => false, 'name' => '', 'mode' => $status['auto_fetch_mode_label'], 'auto_fetch_mode' => $status['auto_fetch_mode'], 'cookie_configured' => false, 'profile_configured' => false, 'has_profile' => false, 'task_count' => 0, 'task_modules' => [], 'entry_url' => 'https://ebooking.ctrip.com'],
            'meituan' => ['configured' => false, 'name' => '', 'mode' => $status['auto_fetch_mode_label'], 'auto_fetch_mode' => $status['auto_fetch_mode'], 'cookie_configured' => false, 'profile_configured' => false, 'has_profile' => false, 'task_count' => 0, 'task_modules' => [], 'entry_url' => 'https://eb.meituan.com'],
        ];
        
        return $this->success($status);
    }

    public function autoFetchRecords(): Response
    {
        $this->checkPermission();

        $hotelIdRaw = $this->request->get('hotel_id', '');
        $hotelIds = $this->resolveAutoFetchRecordHotelIds($hotelIdRaw);
        $hotelMap = $this->getAutoFetchRecordHotelMap($hotelIds);
        $filters = [
            'start_date' => trim((string)$this->request->get('start_date', '')),
            'end_date' => trim((string)$this->request->get('end_date', '')),
            'source' => trim((string)$this->request->get('source', '')),
            'status' => trim((string)$this->request->get('status', '')),
        ];
        $page = max(1, (int)$this->request->get('page', 1));
        $pageSize = max(1, min(100, (int)$this->request->get('page_size', 30)));

        $rows = [];
        foreach ($hotelIds as $hotelId) {
            $status = cache($this->autoFetchStatusKey((int)$hotelId));
            if (!is_array($status)) {
                continue;
            }
            $rows = array_merge($rows, $this->buildAutoFetchRecordRows($status, (int)$hotelId, (string)($hotelMap[(int)$hotelId] ?? '门店ID ' . $hotelId), $filters));
        }

        usort($rows, static fn(array $a, array $b): int => strcmp((string)($b['run_time'] ?? ''), (string)($a['run_time'] ?? '')));

        $total = count($rows);
        $list = array_slice($rows, ($page - 1) * $pageSize, $pageSize);

        return $this->success([
            'list' => $list,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
            ],
        ]);
    }

    public function batchDeleteAutoFetchRecords(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_delete_online_data');

        $ids = $this->request->post('ids', []);
        if (empty($ids) || !is_array($ids)) {
            return $this->error('请选择要删除的抓取记录');
        }

        $idSet = array_fill_keys(array_values(array_filter(array_map('strval', $ids))), true);
        if (empty($idSet)) {
            return $this->error('无效的抓取记录ID');
        }

        $hotelIds = $this->resolveAutoFetchRecordHotelIds($this->request->post('hotel_id', ''));
        $deletedCount = 0;
        foreach ($hotelIds as $hotelId) {
            $statusKey = $this->autoFetchStatusKey((int)$hotelId);
            $status = cache($statusKey);
            if (!is_array($status)) {
                continue;
            }
            [$status, $count] = $this->removeAutoFetchRecordIds($status, (int)$hotelId, $idSet);
            if ($count > 0) {
                $deletedCount += $count;
                cache($statusKey, $this->rebuildAutoFetchStatusHistory($status), 86400 * 30);
            }
        }

        OperationLog::record('online_data', 'batch_delete_auto_fetch_records', '批量删除自动抓取记录: ' . $deletedCount . '条', $this->currentUser->id);

        return $this->success(['deleted_count' => $deletedCount], '删除成功');
    }

    public function clearAutoFetchRecords(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_delete_online_data');

        $hotelIds = $this->resolveAutoFetchRecordHotelIds($this->request->post('hotel_id', ''));
        $clearedCount = 0;
        foreach ($hotelIds as $hotelId) {
            $statusKey = $this->autoFetchStatusKey((int)$hotelId);
            $status = cache($statusKey);
            if (!is_array($status)) {
                continue;
            }
            $clearedCount += count(is_array($status['recent_runs'] ?? null) ? $status['recent_runs'] : []);
            $status['recent_runs'] = [];
            $status['failed_records'] = [];
            $status['last_result'] = null;
            $status['last_run_time'] = null;
            $status['last_data_date'] = null;
            cache($statusKey, $status, 86400 * 30);
        }

        OperationLog::record('online_data', 'clear_auto_fetch_records', '清空自动抓取历史记录: ' . $clearedCount . '条', $this->currentUser->id);

        return $this->success(['cleared_count' => $clearedCount], '历史记录已清空');
    }
    
    /**
     * 切换自动获取开关
     */
    public function toggleAutoFetch(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');
        
        $enabledRaw = $this->request->post('enabled', true);
        $enabled = filter_var($enabledRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $enabled = $enabled === null ? (bool)$enabledRaw : $enabled;
        $hotelId = $this->resolveAutoFetchHotelId($this->request->post('hotel_id', null));
        
        if ($hotelId === null) {
            return $this->error('请选择要设置自动抓取的酒店');
        }
        if (!$this->currentUser->hasHotelPermission((int)$hotelId, 'can_fetch_online_data')) {
            return $this->error('无权操作该门店');
        }
        if ($enabled && !$this->hasAnyPlatformFetchConfigForHotel((int)$hotelId)) {
            return $this->error('未配置携程或美团抓取凭证，请先在酒店管理中关联平台配置');
        }
        
        $statusKey = $hotelId ? "online_data_auto_fetch_status_{$hotelId}" : 'online_data_auto_fetch_status';
        $status = cache($statusKey) ?: [];
        $status['enabled'] = (bool)$enabled;
        $modeRaw = $this->request->post('auto_fetch_mode', $this->request->post('autoMode', $status['auto_fetch_mode'] ?? 'hybrid_auto'));
        $status['auto_fetch_mode'] = $this->normalizeAutoFetchMode($modeRaw);
        if (!isset($status['schedule_time'])) {
            $status['schedule_time'] = '10:00';
        }
        cache($statusKey, $status, 86400 * 30);
        
        OperationLog::record('online_data', 'toggle_auto_fetch', '切换自动获取状态: ' . ($enabled ? '开启' : '关闭') . " (门店ID: {$hotelId})", $this->currentUser->id);
        
        return $this->success([
            'enabled' => $status['enabled'],
            'auto_fetch_mode' => $status['auto_fetch_mode'],
            'auto_fetch_mode_label' => $this->autoFetchModeLabel($status['auto_fetch_mode']),
        ], $enabled ? '已开启自动获取' : '已关闭自动获取');
    }
    
    /**
     * 设置自动获取时间
     */
    public function setFetchSchedule(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');
        
        $hotelId = $this->resolveAutoFetchHotelId($this->request->post('hotel_id', null));
        $scheduleTime = $this->normalizeFetchScheduleTime((string)$this->request->post('schedule_time', '10:00'));
        
        // 验证时间格式
        if ($scheduleTime === null) {
            return $this->error('时间格式错误，请使用 HH:MM 格式');
        }
        
        if ($hotelId === null) {
            return $this->error('请选择要设置自动抓取的酒店');
        }
        if (!$this->currentUser->hasHotelPermission((int)$hotelId, 'can_fetch_online_data')) {
            return $this->error('无权操作该门店');
        }
        if (!$this->hasAnyPlatformFetchConfigForHotel((int)$hotelId)) {
            return $this->error('未配置携程或美团抓取凭证，请先在酒店管理中关联平台配置');
        }
        
        $statusKey = $hotelId ? "online_data_auto_fetch_status_{$hotelId}" : 'online_data_auto_fetch_status';
        $status = cache($statusKey) ?: [];
        $status['schedule_time'] = $scheduleTime;
        $modeRaw = $this->request->post('auto_fetch_mode', $this->request->post('autoMode', $status['auto_fetch_mode'] ?? 'hybrid_auto'));
        $status['auto_fetch_mode'] = $this->normalizeAutoFetchMode($modeRaw);
        if (!isset($status['enabled'])) {
            $status['enabled'] = false;
        }
        cache($statusKey, $status, 86400 * 30);
        
        OperationLog::record('online_data', 'set_schedule', "设置自动获取时间: {$scheduleTime} (门店ID: {$hotelId})", $this->currentUser->id);
        
        return $this->success([
            'schedule_time' => $scheduleTime,
            'auto_fetch_mode' => $status['auto_fetch_mode'],
            'auto_fetch_mode_label' => $this->autoFetchModeLabel($status['auto_fetch_mode']),
        ], "设置成功，将在每天 {$scheduleTime} 自动获取数据");
    }

    public function retryAutoFetch(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $hotelId = $this->resolveAutoFetchHotelId($this->request->post('hotel_id', $this->request->post('system_hotel_id', null)));
        $dataDate = trim((string)$this->request->post('data_date', ''));

        if ($hotelId === null) {
            return $this->error('请选择要补抓的酒店');
        }
        if (!$this->currentUser->hasHotelPermission((int)$hotelId, 'can_fetch_online_data')) {
            return $this->error('无权操作该门店');
        }
        if (!$this->hasAnyPlatformFetchConfigForHotel((int)$hotelId)) {
            return $this->error('未配置携程或美团抓取凭证，请先在酒店管理中关联平台配置');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataDate)) {
            return $this->error('请选择要补抓的数据日期');
        }
        if (strtotime($dataDate) === false || strtotime($dataDate) > strtotime(date('Y-m-d'))) {
            return $this->error('补抓日期不能晚于今天');
        }

        $autoFetchModeRaw = $this->request->post('auto_fetch_mode', $this->request->post('autoMode', null));
        $fetchOptions = [];
        if ($autoFetchModeRaw !== null && trim((string)$autoFetchModeRaw) !== '') {
            $fetchOptions['auto_fetch_mode'] = $autoFetchModeRaw;
        }
        $result = $this->executeAutoFetch((int)$hotelId, $dataDate, $fetchOptions);
        $this->updateFetchStatus((int)$hotelId, (bool)$result['success'], (string)$result['message'], $dataDate, [
            'saved_count' => (int)($result['saved_count'] ?? 0),
            'auto_fetch_mode' => $result['auto_fetch_mode'] ?? null,
            'platform_results' => $result['platform_results'] ?? [],
        ]);

        if ($result['success']) {
            OperationLog::record('online_data', 'retry_auto_fetch', "补抓平台数据: {$dataDate}，{$result['message']} (门店ID: {$hotelId})", $this->currentUser->id);
            return $this->success([
                'data_date' => $dataDate,
                'saved_count' => (int)($result['saved_count'] ?? 0),
                'auto_fetch_mode' => $result['auto_fetch_mode'] ?? 'hybrid_auto',
                'auto_fetch_mode_label' => $result['auto_fetch_mode_label'] ?? '最低成本自动',
                'platform_results' => $result['platform_results'] ?? [],
            ], '补抓成功');
        }

        return $this->error('补抓失败: ' . $result['message']);
    }
    
    /**
     * 数据分析
     */
    public function dataAnalysis(): Response
    {
        $this->checkPermission();
        
        $dimension = $this->request->get('dimension', 'day'); // day, week, month
        $startDate = $this->request->get('start_date', date('Y-m-d', strtotime('-30 days')));
        $endDate = $this->request->get('end_date', date('Y-m-d'));
        $hotelId = trim((string)$this->request->get('system_hotel_id', $this->request->get('hotel_id', '')));
        $dataType = $this->request->get('data_type', '');
        
        $query = Db::name('online_daily_data')
            ->where('data_date', '>=', $startDate)
            ->where('data_date', '<=', $endDate);
        
        // 非超级管理员只能看自己酒店的数据
        if ($hotelId !== '') {
            $this->applyOnlineDailyDataHotelFilter($query, $hotelId);
        }

        if (!$this->currentUser->isSuperAdmin()) {
            $permittedHotelIds = $this->currentUser->getPermittedHotelIds();
            if (empty($permittedHotelIds)) {
                return $this->success(['aggregated' => [], 'summary' => [], 'chart_data' => [], 'hotel_ranking' => []]);
            }
            $query->whereIn('system_hotel_id', $permittedHotelIds);
        }

        $this->applyDataTypeFilter($query, $dataType);
        
        $data = $query->order('data_date', 'asc')->select()->toArray();
        
        // 按维度聚合数据
        $aggregated = $this->aggregateByDimension($data, $dimension);
        
        // 计算汇总统计 - 基于聚合数据
        $totalAmount = array_sum(array_column($aggregated, 'amount'));
        $totalQuantity = array_sum(array_column($aggregated, 'quantity'));
        $totalDataValue = array_sum(array_column($aggregated, 'data_value'));
        $totalOrders = array_sum(array_column($aggregated, 'book_order_num'));
        $periodCount = count($aggregated);
        
        $validScores = array_filter(array_column($data, 'comment_score'), fn($s) => $s > 0);
        $summary = [
            'total_amount' => $totalAmount,
            'total_quantity' => $totalQuantity,
            'total_data_value' => $totalDataValue,
            'total_orders' => $totalOrders,
            'avg_score' => count($validScores) > 0 ? array_sum($validScores) / count($validScores) : 0,
            'period_count' => $periodCount, // 维度周期数（天数/周数/月数）
            'hotel_count' => count(array_unique(array_filter(array_map([$this, 'onlineDataHotelKey'], $data), static fn($value): bool => $value !== ''))),
            'avg_amount' => $periodCount > 0 ? $totalAmount / $periodCount : 0, // 平均每周期销售额
            'avg_quantity' => $periodCount > 0 ? $totalQuantity / $periodCount : 0, // 平均每周期房晚数
            'avg_data_value' => $periodCount > 0 ? $totalDataValue / $periodCount : 0, // 平均每周期月间夜
        ];
        
        // 图表数据
        $chartData = $this->buildChartData($aggregated, $dimension);
        
        // 酒店排名 - 按维度聚合
        $hotelRanking = $this->buildHotelRanking($data, $dimension);
        
        return $this->success([
            'aggregated' => $aggregated,
            'summary' => $summary,
            'chart_data' => $chartData,
            'hotel_ranking' => $hotelRanking,
        ]);
    }
    
    /**
     * 按维度聚合数据
     */
    private function aggregateByDimension(array $data, string $dimension): array
    {
        $result = [];
        
        foreach ($data as $item) {
            $date = $item['data_date'];
            $key = match ($dimension) {
                'week' => date('Y-W', strtotime($date)),
                'month' => date('Y-m', strtotime($date)),
                default => $date,
            };
            
            if (!isset($result[$key])) {
                $result[$key] = [
                    'period' => $key,
                    'amount' => 0,
                    'quantity' => 0,
                    'data_value' => 0,
                    'book_order_num' => 0,
                    'comment_score_sum' => 0,
                    'comment_score_count' => 0,
                    'record_count' => 0,
                ];
            }
            
            $result[$key]['amount'] += floatval($item['amount']);
            $result[$key]['quantity'] += intval($item['quantity']);
            $result[$key]['data_value'] += floatval($item['data_value'] ?? 0);
            $result[$key]['book_order_num'] += intval($item['book_order_num']);
            if (floatval($item['comment_score']) > 0) {
                $result[$key]['comment_score_sum'] += floatval($item['comment_score']);
                $result[$key]['comment_score_count']++;
            }
            $result[$key]['record_count']++;
        }
        
        // 计算平均评分
        foreach ($result as &$item) {
            $item['avg_comment_score'] = $item['comment_score_count'] > 0 
                ? round($item['comment_score_sum'] / $item['comment_score_count'], 2) 
                : 0;
        }
        
        ksort($result);
        return array_values($result);
    }
    
    /**
     * 构建图表数据
     */
    private function buildChartData(array $aggregated, string $dimension): array
    {
        $labels = array_column($aggregated, 'period');
        $amounts = array_column($aggregated, 'amount');
        $quantities = array_column($aggregated, 'quantity');
        $orders = array_column($aggregated, 'book_order_num');
        $scores = array_column($aggregated, 'avg_comment_score');
        
        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => '销售额',
                    'data' => array_map('round', $amounts, array_fill(0, count($amounts), 2)),
                    'borderColor' => 'rgb(59, 130, 246)',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                    'yAxisID' => 'y',
                ],
                [
                    'label' => '房晚数',
                    'data' => $quantities,
                    'borderColor' => 'rgb(16, 185, 129)',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'yAxisID' => 'y1',
                ],
                [
                    'label' => '订单数',
                    'data' => $orders,
                    'borderColor' => 'rgb(245, 158, 11)',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.1)',
                    'yAxisID' => 'y1',
                ],
            ],
        ];
    }
    
    /**
     * 构建酒店排名（按维度聚合）
     */
    private function buildHotelRanking(array $data, string $dimension = 'day'): array
    {
        $hotels = [];

        // 仅使用最新周期的数据进行排序（day: 最新日期；week/month: 最新周期）
        $latestKey = '';
        foreach ($data as $item) {
            $date = $item['data_date'] ?? '';
            if (!$date) {
                continue;
            }
            $key = match ($dimension) {
                'week' => date('Y-W', strtotime($date)),
                'month' => date('Y-m', strtotime($date)),
                default => $date,
            };
            if ($key > $latestKey) {
                $latestKey = $key;
            }
        }
        if ($latestKey) {
            $data = array_filter($data, function ($item) use ($latestKey, $dimension) {
                $date = $item['data_date'] ?? '';
                if (!$date) {
                    return false;
                }
                $key = match ($dimension) {
                    'week' => date('Y-W', strtotime($date)),
                    'month' => date('Y-m', strtotime($date)),
                    default => $date,
                };
                return $key === $latestKey;
            });
        }
        
        foreach ($data as $item) {
            $hotelId = $this->onlineDataHotelKey($item);
            if ($hotelId === '') {
                continue;
            }
            $date = $item['data_date'];
            
            // 根据维度生成周期key
            $periodKey = match ($dimension) {
                'week' => date('Y-W', strtotime($date)),
                'month' => date('Y-m', strtotime($date)),
                default => $date, // 日维度
            };
            
            // 使用酒店ID+周期作为唯一key
            $key = $hotelId . '_' . $periodKey;
            
            if (!isset($hotels[$key])) {
                $hotels[$key] = [
                    'hotel_id' => $hotelId,
                    'hotel_name' => $item['hotel_name'] ?: '未知酒店',
                    'period' => $periodKey,
                    'amount' => 0,
                    'quantity' => 0,
                    'book_order_num' => 0,
                    'record_count' => 0,
                ];
            }
            
            $hotels[$key]['amount'] += floatval($item['amount']);
            $hotels[$key]['quantity'] += intval($item['quantity']);
            $hotels[$key]['book_order_num'] += intval($item['book_order_num']);
            $hotels[$key]['record_count']++;
        }
        
        // 按间夜数排序
        usort($hotels, fn($a, $b) => $b['quantity'] <=> $a['quantity']);
        
        return array_slice($hotels, 0, 10);
    }

    private function onlineDataHotelKey(array $item)
    {
        $systemHotelId = $item['system_hotel_id'] ?? null;
        if ($systemHotelId !== null && $systemHotelId !== '' && is_numeric($systemHotelId) && (int)$systemHotelId > 0) {
            return (int)$systemHotelId;
        }

        return (string)($item['hotel_id'] ?? '');
    }

    /**
     * 解析并保存流量数据
     */
    private function parseAndSaveTrafficData($responseData, $startDate, $endDate, string $source, ?int $systemHotelId = null, ?string $platform = null): int
    {
        try {
            if (in_array($source, ['ctrip', 'qunar'], true)) {
                $dataList = $this->extractCtripTrafficRows($responseData);
                if (empty($dataList)) {
                    return 0;
                }

                $savedCount = 0;
                $platform = $platform ?: ($source === 'qunar' ? 'Qunar' : 'Ctrip');
                foreach ($dataList as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $hotelId = $item['hotelId'] ?? $item['hotel_id'] ?? $item['HotelId'] ?? $item['hotelID'] ?? $item['nodeId'] ?? $item['node_id'] ?? null;
                    $compareText = strtolower((string)($item['compareType'] ?? $item['compare_type'] ?? $item['type'] ?? $item['rankType'] ?? $item['name'] ?? $item['hotelName'] ?? ''));
                    $isCompetitor = str_contains($compareText, 'competitor')
                        || str_contains($compareText, 'peer')
                        || str_contains($compareText, 'avg')
                        || str_contains($compareText, 'average')
                        || (is_numeric($hotelId) && (int)$hotelId < 0);
                    if (!is_numeric($hotelId)) {
                        if ($isCompetitor) {
                            $hotelId = -1;
                        } elseif ($systemHotelId !== null) {
                            $hotelId = $systemHotelId;
                        } else {
                            continue;
                        }
                    }
                    $hotelId = (int)$hotelId;
                    if ($hotelId !== -1 && $hotelId <= 0) {
                        continue;
                    }

                    $itemDate = $item['date'] ?? $item['dataDate'] ?? $item['statDate'] ?? $item['stat_date'] ?? $item['data_date'] ?? $item['reportDate'] ?? $item['day'] ?? $startDate;
                    if (!$itemDate || strtotime((string)$itemDate) === false) {
                        continue;
                    }
                    $itemDate = date('Y-m-d', strtotime((string)$itemDate));
                    $compareType = $isCompetitor || $hotelId < 0 ? 'competitor_avg' : 'self';
                    $hotelName = (string)($item['hotelName'] ?? $item['hotel_name'] ?? $item['HotelName'] ?? $item['name'] ?? ($compareType === 'self' ? '????' : '?????'));
                    $listExposure = (int)$this->readTrafficNumber($item, ['listExposure', 'list_exposure', 'exposure', 'exposureCount', 'impressions', 'showCount', 'PV', 'pv', 'pageView', 'pageViews', 'page_view'], 0.0);
                    $detailExposure = (int)$this->readTrafficNumber($item, ['detailExposure', 'detail_exposure', 'detailVisitors', 'detailUv', 'visitorCount', 'UV', 'uv', 'uniqueVisitors', 'unique_visitors', 'views'], 0.0);
                    $flowRate = round($this->normalizeTrafficPercent($this->readTrafficNumber($item, ['flowRate', 'flow_rate', 'conversionRate', 'conversion_rate', 'convertionRate', 'convertRate', 'transforRate', 'transferRate', 'transRate', 'cvr'], $listExposure > 0 ? $detailExposure / $listExposure * 100 : 0.0)), 2);
                    $orderFillingNum = (int)$this->readTrafficNumber($item, ['orderFillingNum', 'order_filling_num', 'orderVisitors', 'clickCount', 'click_count', 'clickNum', 'clicks'], 0.0);
                    $orderSubmitNum = (int)$this->readTrafficNumber($item, ['orderSubmitNum', 'order_submit_num', 'submitUsers', 'submitNum', 'orderCount', 'order_count', 'orderNum', 'bookOrderNum', 'dealNum', 'orders'], 0.0);

                    $query = Db::name('online_daily_data')
                        ->where('data_date', $itemDate)
                        ->where('source', $source)
                        ->where('data_type', 'traffic')
                        ->where('hotel_id', (string)$hotelId);

                    $columns = $this->getOnlineDailyDataColumns();
                    if (isset($columns['platform'])) {
                        $query->where('platform', $platform);
                    }
                    if (isset($columns['compare_type'])) {
                        $query->where('compare_type', $compareType);
                    }
                    if ($systemHotelId !== null) {
                        $query->where('system_hotel_id', $systemHotelId);
                    } else {
                        $query->whereNull('system_hotel_id');
                    }

                    $exists = $query->find();
                    $data = $this->filterOnlineDailyDataFields($this->applyOnlineDailyDataValidationFields([
                        'hotel_id' => (string)$hotelId,
                        'hotel_name' => $hotelName,
                        'system_hotel_id' => $systemHotelId,
                        'data_date' => $itemDate,
                        'amount' => 0,
                        'quantity' => 0,
                        'book_order_num' => 0,
                        'comment_score' => 0,
                        'qunar_comment_score' => 0,
                        'data_value' => $listExposure,
                        'source' => $source,
                        'data_type' => 'traffic',
                        'dimension' => $platform . ':' . $compareType,
                        'platform' => $platform,
                        'compare_type' => $compareType,
                        'list_exposure' => $listExposure,
                        'detail_exposure' => $detailExposure,
                        'flow_rate' => $flowRate,
                        'order_filling_num' => $orderFillingNum,
                        'order_submit_num' => $orderSubmitNum,
                        'raw_data' => json_encode($item, JSON_UNESCAPED_UNICODE),
                    ]));

                    if ($exists) {
                        Db::name('online_daily_data')->where('id', $exists['id'])->update($data);
                    } else {
                        Db::name('online_daily_data')->insert($data);
                    }
                    $savedCount++;
                }

                return $savedCount;
            }

            $dataList = [];

            if (isset($responseData['data']['list']) && is_array($responseData['data']['list'])) {
                $dataList = $responseData['data']['list'];
            } elseif (isset($responseData['data']['hotelList']) && is_array($responseData['data']['hotelList'])) {
                $dataList = $responseData['data']['hotelList'];
            } elseif (isset($responseData['data']['records']) && is_array($responseData['data']['records'])) {
                $dataList = $responseData['data']['records'];
            } elseif (isset($responseData['data']['rows']) && is_array($responseData['data']['rows'])) {
                $dataList = $responseData['data']['rows'];
            } elseif (isset($responseData['data']) && is_array($responseData['data']) && isset($responseData['data'][0])) {
                $dataList = $responseData['data'];
            } elseif (isset($responseData['list']) && is_array($responseData['list'])) {
                $dataList = $responseData['list'];
            } else {
                $dataList = $this->extractTrafficData($responseData);
            }

            if (empty($dataList)) {
                return 0;
            }

            $savedCount = 0;
            $dataDate = $startDate ?: date('Y-m-d', strtotime('-1 day'));

            foreach ($dataList as $item) {
                if (!is_array($item)) continue;

                $hotelId = $item['hotelId'] ?? $item['hotel_id'] ?? $item['HotelId'] ?? $item['hotelID'] ?? $item['poiId'] ?? $item['poi_id'] ?? null;
                $hotelName = $item['hotelName'] ?? $item['hotel_name'] ?? $item['HotelName'] ?? $item['name'] ?? $item['poiName'] ?? $item['poi_name'] ?? '';

                if (empty($hotelId) && empty($hotelName)) {
                    continue;
                }

                $trafficValue = $this->extractTrafficValue($item);
                $itemDate = $item['dataDate'] ?? $item['date'] ?? $item['statDate'] ?? $item['stat_date'] ?? $item['data_date'] ?? $dataDate;
                $dimension = $item['metric'] ?? $item['metricName'] ?? $item['dimension'] ?? $item['_metric'] ?? 'traffic';

                $query = Db::name('online_daily_data')
                    ->where('data_date', $itemDate)
                    ->where('source', $source)
                    ->where('data_type', 'traffic');

                if (!empty($hotelId)) {
                    $query->where('hotel_id', (string)$hotelId);
                } else {
                    $query->where('hotel_name', $hotelName);
                }

                if ($systemHotelId !== null) {
                    $query->where('system_hotel_id', $systemHotelId);
                }

                $exists = $query->find();

                $data = $this->filterOnlineDailyDataFields($this->applyOnlineDailyDataValidationFields([
                    'hotel_id' => $hotelId ? (string)$hotelId : '',
                    'hotel_name' => $hotelName,
                    'system_hotel_id' => $systemHotelId,
                    'data_date' => $itemDate,
                    'amount' => 0,
                    'quantity' => 0,
                    'book_order_num' => 0,
                    'comment_score' => 0,
                    'qunar_comment_score' => 0,
                    'data_value' => $trafficValue ?? 0,
                    'source' => $source,
                    'data_type' => 'traffic',
                    'dimension' => $dimension ?: 'traffic',
                    'raw_data' => json_encode($item, JSON_UNESCAPED_UNICODE),
                ]));

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
            return 0;
        }
    }

    /**
     * 提取流量数值
     */
    private function extractTrafficValue(array $item): ?float
    {
        $keys = [
            'traffic', 'trafficValue', 'traffic_value', 'pv', 'uv', 'pageView', 'page_view',
            'visit', 'visits', 'exposure', 'exposureNum', 'impression', 'impressions',
            'click', 'clickNum', 'detailView', 'detail_view', 'view', 'views', 'session', 'sessions'
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

    /**
     * 解析JSON参数
     */
    private function parseJsonParams(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('额外参数JSON格式不正确');
        }
        return $data;
    }

    /**
     * 递归提取流量数据
     */
    private function extractTrafficData($data): array
    {
        $result = [];
        if (!is_array($data)) {
            return $result;
        }
        foreach ($data as $value) {
            if (is_array($value)) {
                if (isset($value['hotelId']) || isset($value['hotel_id']) || isset($value['hotelName']) || isset($value['hotel_name']) || isset($value['poiId']) || isset($value['poiName'])) {
                    $result[] = $value;
                } elseif (isset($value[0]) && is_array($value[0])) {
                    $result = array_merge($result, $this->extractTrafficData($value));
                }
            }
        }
        return $result;
    }

    /**
     * 应用数据类型筛选
     */
    private function applyDataTypeFilter($query, ?string $dataType): void
    {
        if (empty($dataType)) {
            return;
        }
        if ($dataType === 'business') {
            $query->where(function ($q) {
                $q->whereNull('data_type')
                    ->whereOr('data_type', '')
                    ->whereOr('data_type', 'business');
            });
            return;
        }
        $query->where('data_type', $dataType);
    }


    /**
     * 定时任务触发接口（供外部cron调用）
     * 每分钟调用一次，检查是否有需要执行的自动获取任务
     */
    public function cronTrigger(): Response
    {
        // 简单的token验证
        $token = $this->request->header('X-Cron-Token') ?: $this->request->get('token');
        $configToken = trim((string)\think\facade\Env::get('CRON_TOKEN', ''));
        if ($configToken === '') {
            return json(['code' => 403, 'message' => 'CRON_TOKEN未配置'], 403);
        }
        
        if ($token !== $configToken) {
            return json(['code' => 401, 'message' => 'Unauthorized'], 401);
        }
        
        $currentTime = date('H:i');
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $results = [];
        
        // 获取所有酒店
        $hotels = Db::name('hotels')->where('status', 1)->select()->toArray();
        
        foreach ($hotels as $hotel) {
            $hotelId = $hotel['id'];
            $statusKey = "online_data_auto_fetch_status_{$hotelId}";
            $status = cache($statusKey) ?: [];
            
            // 检查是否开启
            if (empty($status['enabled'])) {
                continue;
            }

            // 检查运行时间
            $scheduleTime = $this->normalizeFetchScheduleTime((string)($status['schedule_time'] ?? '10:00')) ?? '10:00';
            if ($currentTime !== $scheduleTime) {
                continue;
            }
            
            // 检查今天是否已执行
            $executedKey = "online_data_executed_{$hotelId}_{$today}";
            if (cache($executedKey)) {
                $results[] = ['hotel_id' => $hotelId, 'hotel_name' => $hotel['name'], 'status' => 'skipped', 'message' => '今天已执行'];
                continue;
            }
            
            // 执行获取
            $result = $this->executeAutoFetch($hotelId, $yesterday);
            $results[] = [
                'hotel_id' => $hotelId,
                'hotel_name' => $hotel['name'],
                'status' => $result['success'] ? 'success' : 'failed',
                'message' => $result['message']
            ];
            
            $this->updateFetchStatus($hotelId, (bool)$result['success'], (string)$result['message'], $yesterday, [
                'saved_count' => (int)($result['saved_count'] ?? 0),
                'auto_fetch_mode' => $result['auto_fetch_mode'] ?? null,
                'platform_results' => $result['platform_results'] ?? [],
            ]);
            
            // 标记今天已执行
            cache($executedKey, true, 86400);
        }
        
        return json([
            'code' => 200,
            'message' => 'ok',
            'time' => date('Y-m-d H:i:s'),
            'executed' => count($results),
            'results' => $results
        ]);
    }
    
    /**
     * 执行自动获取
     */
    private function executeAutoFetch(int $hotelId, string $dataDate, array $options = []): array
    {
        $options['auto_fetch_mode'] = $this->resolveAutoFetchRunMode($hotelId, $options);
        $platformResults = [];
        $totalSaved = 0;
        $attempted = 0;
        $successCount = 0;

        if ($this->hasCtripFetchConfigForHotel($hotelId)) {
            $attempted++;
            try {
                $result = $this->executeCtripAutoFetch($hotelId, $dataDate, $options);
            } catch (\Throwable $e) {
                $result = ['platform' => 'ctrip', 'success' => false, 'message' => '异常: ' . $e->getMessage(), 'saved_count' => 0];
            }
            $platformResults[] = $result;
            $totalSaved += (int)($result['saved_count'] ?? 0);
            if (!empty($result['success'])) {
                $successCount++;
            }
        } else {
            $platformResults[] = [
                'platform' => 'ctrip',
                'success' => false,
                'skipped' => true,
                'message' => '未配置携程凭证',
                'saved_count' => 0,
                'auto_fetch_mode' => $options['auto_fetch_mode'],
                'mode_label' => $this->autoFetchModeLabel((string)$options['auto_fetch_mode']),
            ];
        }

        if ($this->hasMeituanFetchConfigForHotel($hotelId)) {
            $attempted++;
            try {
                $result = $this->executeMeituanAutoFetch($hotelId, $dataDate, $options);
            } catch (\Throwable $e) {
                $result = ['platform' => 'meituan', 'success' => false, 'message' => '异常: ' . $e->getMessage(), 'saved_count' => 0];
            }
            $platformResults[] = $result;
            $totalSaved += (int)($result['saved_count'] ?? 0);
            if (!empty($result['success'])) {
                $successCount++;
            }
        } else {
            $message = '未配置美团 Partner ID / POI ID / Cookies';
            $platformResults[] = [
                'platform' => 'meituan',
                'success' => false,
                'skipped' => true,
                'message' => $message,
                'saved_count' => 0,
                'auto_fetch_mode' => $options['auto_fetch_mode'],
                'mode_label' => $this->autoFetchModeLabel((string)$options['auto_fetch_mode']),
                'modules' => [
                    $this->withAutoFetchResultMeta(['module' => 'configuration', 'saved_count' => 0, 'success' => false, 'skipped' => true, 'message' => $message], 'cookie_config'),
                ],
            ];
        }

        $messages = array_map(static function (array $item): string {
            $label = ($item['platform'] ?? '') === 'meituan' ? '美团' : '携程';
            return $label . ': ' . (string)($item['message'] ?? '-');
        }, $platformResults);

        if ($attempted === 0) {
            return [
                'success' => false,
                'message' => '未配置任何平台抓取凭证',
                'saved_count' => 0,
                'auto_fetch_mode' => $options['auto_fetch_mode'],
                'auto_fetch_mode_label' => $this->autoFetchModeLabel((string)$options['auto_fetch_mode']),
                'platform_results' => $platformResults,
            ];
        }

        return [
            'success' => $successCount > 0,
            'message' => implode('；', $messages),
            'saved_count' => $totalSaved,
            'auto_fetch_mode' => $options['auto_fetch_mode'],
            'auto_fetch_mode_label' => $this->autoFetchModeLabel((string)$options['auto_fetch_mode']),
            'platform_results' => $platformResults,
        ];
    }

    private function getAutoFetchSavedDataConfigs(): array
    {
        return [
            'ctrip-traffic' => $this->readSavedOtaDataConfig('ctrip-traffic'),
            'meituan-traffic' => $this->readSavedOtaDataConfig('meituan-traffic'),
            'ctrip-comments' => $this->readSavedOtaDataConfig('ctrip-comments'),
            'meituan-comments' => $this->readSavedOtaDataConfig('meituan-comments'),
        ];
    }

    private function readSavedOtaDataConfig(string $type): array
    {
        $key = 'data_config_' . str_replace('-', '_', $type);
        $raw = SystemConfig::getValue($key, '');
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function firstAutoFetchConfigValue(array $config, array $keys, $default = '')
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $config)) {
                continue;
            }
            $value = $config[$key];
            if ($value === null || $value === '') {
                continue;
            }
            if (is_array($value) && empty($value)) {
                continue;
            }
            return $value;
        }

        return $default;
    }

    private function configValueToArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value === null || trim((string)$value) === '') {
            return [];
        }
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function meituanAutoFetchConfigStatus(array $config): array
    {
        $hasPartnerId = trim((string)$this->firstAutoFetchConfigValue($config, ['partner_id', 'partnerId'], '')) !== '';
        $hasPoiId = trim((string)$this->firstAutoFetchConfigValue($config, ['poi_id', 'poiId'], '')) !== '';
        $hasCookies = trim((string)$this->firstAutoFetchConfigValue($config, ['cookies', 'cookie'], '')) !== '';
        $missingFields = [];

        if (!$hasPartnerId) {
            $missingFields[] = 'Partner ID';
        }
        if (!$hasPoiId) {
            $missingFields[] = 'POI ID';
        }
        if (!$hasCookies) {
            $missingFields[] = 'Cookies';
        }

        return [
            'api_configured' => empty($missingFields),
            'has_partner_id' => $hasPartnerId,
            'has_poi_id' => $hasPoiId,
            'has_cookies' => $hasCookies,
            'missing_fields' => $missingFields,
            'missing_text' => implode(' / ', $missingFields),
        ];
    }

    private function normalizeAutoFetchMode($value): string
    {
        $mode = strtolower(str_replace(['-', ' '], '_', trim((string)$value)));
        return match ($mode) {
            'cookie', 'cookies', 'cookie_auto', 'cookie_config', 'config', 'api', 'direct_api' => 'cookie_config',
            'profile', 'browser', 'browser_profile', 'profile_browser' => 'profile_browser',
            default => 'hybrid_auto',
        };
    }

    private function autoFetchModeLabel(string $mode): string
    {
        return match ($this->normalizeAutoFetchMode($mode)) {
            'cookie_config' => 'Cookie/配置自动',
            'profile_browser' => '浏览器 Profile 自动',
            default => '最低成本自动',
        };
    }

    private function resolveAutoFetchRunMode(int $hotelId, array $options = []): string
    {
        foreach (['auto_fetch_mode', 'autoMode', 'auto_mode', 'fetch_mode'] as $key) {
            if (array_key_exists($key, $options) && trim((string)$options[$key]) !== '') {
                return $this->normalizeAutoFetchMode($options[$key]);
            }
        }

        $status = cache($this->autoFetchStatusKey($hotelId));
        if (is_array($status)) {
            foreach (['auto_fetch_mode', 'autoMode', 'auto_mode', 'fetch_mode'] as $key) {
                if (array_key_exists($key, $status) && trim((string)$status[$key]) !== '') {
                    return $this->normalizeAutoFetchMode($status[$key]);
                }
            }
        }

        return 'hybrid_auto';
    }

    private function resolvePlatformAutoFetchMode(array $config, array $options, string $platform): string
    {
        foreach ([
            $platform . '_auto_fetch_mode',
            $platform . '_auto_mode',
            'auto_fetch_mode',
            'autoMode',
            'auto_mode',
            'fetch_mode',
        ] as $key) {
            if (array_key_exists($key, $options) && trim((string)$options[$key]) !== '') {
                return $this->normalizeAutoFetchMode($options[$key]);
            }
        }

        foreach (['auto_fetch_mode', 'autoMode', 'auto_mode', 'fetch_mode'] as $key) {
            if (array_key_exists($key, $config) && trim((string)$config[$key]) !== '') {
                return $this->normalizeAutoFetchMode($config[$key]);
            }
        }

        return 'hybrid_auto';
    }

    private function shouldRunCookieConfigTasks(string $mode): bool
    {
        return $this->normalizeAutoFetchMode($mode) !== 'profile_browser';
    }

    private function shouldRunProfileBrowser(string $mode): bool
    {
        return $this->normalizeAutoFetchMode($mode) !== 'cookie_config';
    }

    private function shouldRunProfileBrowserForCost(string $mode, int $savedCount): bool
    {
        $mode = $this->normalizeAutoFetchMode($mode);
        if ($mode === 'cookie_config') {
            return false;
        }
        if ($mode === 'profile_browser') {
            return true;
        }

        return $savedCount <= 0;
    }

    private function autoFetchStatusCode(array $result): string
    {
        if (!empty($result['success'])) {
            return 'ok';
        }

        $message = strtolower((string)($result['message'] ?? ''));
        if (!empty($result['skipped']) && (str_contains($message, '当前策略') || str_contains($message, '最低成本'))) {
            return 'skipped';
        }
        if (str_contains($message, 'partner') || str_contains($message, 'poi')) {
            return 'needs_config';
        }
        if (str_contains($message, 'cookie') || str_contains($message, '登录') || str_contains($message, '授权') || str_contains($message, '过期')) {
            return 'needs_cookie';
        }
        if (str_contains($message, 'profile') || str_contains($message, '浏览器')) {
            return 'needs_profile';
        }
        if (str_contains($message, 'payload') || str_contains($message, 'request_url') || str_contains($message, 'spidertoken')) {
            return 'needs_payload';
        }
        if (!empty($result['skipped'])) {
            return 'skipped';
        }

        return 'failed';
    }

    private function withAutoFetchResultMeta(array $result, string $strategy = '', string $module = ''): array
    {
        if ($module !== '' && empty($result['module'])) {
            $result['module'] = $module;
        }
        if ($strategy !== '' && empty($result['strategy'])) {
            $result['strategy'] = $strategy;
        }
        if (empty($result['status_code'])) {
            $result['status_code'] = $this->autoFetchStatusCode($result);
        }
        if (empty($result['next_action'])) {
            $result['next_action'] = match ($result['status_code']) {
                'needs_config' => '补齐美团 Partner ID / POI ID / Cookies',
                'needs_cookie' => '更新 Cookie 或重新登录 OTA 后台',
                'needs_profile' => '建立或重新登录浏览器 Profile',
                'needs_payload' => '补充 Request URL / Payload / 动态令牌',
                default => '',
            };
        }

        return $result;
    }

    private function isAutoFetchDataConfigUsable(array $config, int $hotelId): bool
    {
        if (empty($config)) {
            return false;
        }
        $enabled = $config['enabled'] ?? true;
        if ($enabled === false || $enabled === 0 || strtolower(trim((string)$enabled)) === 'false') {
            return false;
        }
        $configHotelId = trim((string)$this->firstAutoFetchConfigValue($config, ['system_hotel_id', 'hotelId', 'hotel_id'], ''));
        return $configHotelId === '' || $configHotelId === (string)$hotelId;
    }

    private function compactAutoFetchTaskBody(array $body): array
    {
        $compacted = [];
        foreach ($body as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (is_array($value) && empty($value)) {
                continue;
            }
            $compacted[$key] = $value;
        }

        return $compacted;
    }

    private function pushAutoFetchTask(array &$tasks, array $task): void
    {
        $body = $this->compactAutoFetchTaskBody($task['body'] ?? []);
        foreach (($task['required'] ?? []) as $field) {
            if (!array_key_exists($field, $body) || trim((string)$body[$field]) === '') {
                return;
            }
        }
        $task['body'] = $body;
        unset($task['required']);
        $task['strategy'] = $task['strategy'] ?? 'cookie_config';
        $tasks[] = $task;
    }

    private function buildAutoFetchConfigTaskPlan(int $hotelId, string $dataDate, array $ctripConfig, array $meituanConfig, array $savedConfigs = []): array
    {
        $tasks = [];
        $startDate = $dataDate;
        $endDate = $dataDate;

        $ctripCookies = trim((string)$this->firstAutoFetchConfigValue($ctripConfig, ['cookies', 'cookie'], ''));
        if ($ctripCookies !== '') {
            $this->pushAutoFetchTask($tasks, [
                'platform' => 'ctrip',
                'module' => 'business',
                'label' => 'ctrip-business',
                'required' => ['cookies', 'node_id'],
                'body' => [
                    'url' => $this->firstAutoFetchConfigValue($ctripConfig, ['url'], 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportCompeteHotelReport'),
                    'node_id' => $this->firstAutoFetchConfigValue($ctripConfig, ['node_id', 'nodeId'], '24588'),
                    'cookies' => $ctripCookies,
                    'auth_data' => $this->firstAutoFetchConfigValue($ctripConfig, ['auth_data', 'authData'], []),
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'auto_save' => true,
                    'system_hotel_id' => $hotelId,
                ],
            ]);
        }

        $ctripTrafficConfig = is_array($savedConfigs['ctrip-traffic'] ?? null) ? $savedConfigs['ctrip-traffic'] : [];
        $ctripTrafficUsable = $this->isAutoFetchDataConfigUsable($ctripTrafficConfig, $hotelId);
        $ctripTrafficCookies = trim((string)$this->firstAutoFetchConfigValue($ctripTrafficConfig, ['cookies', 'cookie'], $ctripCookies));
        if ($ctripTrafficCookies !== '' && ($ctripTrafficUsable || $ctripCookies !== '')) {
            $this->pushAutoFetchTask($tasks, [
                'platform' => 'ctrip',
                'module' => 'traffic',
                'label' => 'ctrip-traffic',
                'required' => ['cookies'],
                'body' => [
                    'url' => $this->firstAutoFetchConfigValue($ctripTrafficConfig, ['url'], ''),
                    'platform' => $this->firstAutoFetchConfigValue($ctripTrafficConfig, ['platform'], 'Ctrip'),
                    'date_range' => 'custom',
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'spiderkey' => $this->firstAutoFetchConfigValue($ctripTrafficConfig, ['spiderkey', 'spider_key'], ''),
                    'cookies' => $ctripTrafficCookies,
                    'extra_params' => $this->firstAutoFetchConfigValue($ctripTrafficConfig, ['extra_params', 'extraParams'], ''),
                    'auto_save' => true,
                    'system_hotel_id' => $hotelId,
                ],
            ]);
        }

        // 点评接口当前不纳入默认自动抓取计划，保留单独手动入口。

        $meituanCookies = trim((string)$this->firstAutoFetchConfigValue($meituanConfig, ['cookies', 'cookie'], ''));
        $meituanPartnerId = trim((string)$this->firstAutoFetchConfigValue($meituanConfig, ['partner_id', 'partnerId'], ''));
        $meituanPoiId = trim((string)$this->firstAutoFetchConfigValue($meituanConfig, ['poi_id', 'poiId'], ''));
        if ($meituanCookies !== '' && $meituanPartnerId !== '' && $meituanPoiId !== '') {
            foreach (['P_RZ', 'P_XS', 'P_ZH', 'P_LL'] as $rankType) {
                $this->pushAutoFetchTask($tasks, [
                    'platform' => 'meituan',
                    'module' => 'ranking',
                    'label' => 'meituan-' . $rankType,
                    'required' => ['cookies', 'partner_id', 'poi_id'],
                    'body' => [
                        'url' => $this->firstAutoFetchConfigValue($meituanConfig, ['url'], 'https://eb.meituan.com/api/v1/ebooking/business/peer/rank/data/detail'),
                        'partner_id' => $meituanPartnerId,
                        'poi_id' => $meituanPoiId,
                        'rank_type' => $rankType,
                        'data_scope' => $this->firstAutoFetchConfigValue($meituanConfig, ['data_scope', 'dataScope'], 'vpoi'),
                        'date_range' => 'custom',
                        'cookies' => $meituanCookies,
                        'auth_data' => $this->firstAutoFetchConfigValue($meituanConfig, ['auth_data', 'authData'], []),
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'auto_save' => true,
                        'system_hotel_id' => $hotelId,
                    ],
                ]);
            }
        }

        $meituanTrafficConfig = is_array($savedConfigs['meituan-traffic'] ?? null) ? $savedConfigs['meituan-traffic'] : [];
        if ($this->isAutoFetchDataConfigUsable($meituanTrafficConfig, $hotelId)) {
            $this->pushAutoFetchTask($tasks, [
                'platform' => 'meituan',
                'module' => 'traffic',
                'label' => 'meituan-traffic',
                'required' => ['url', 'cookies', 'partner_id', 'poi_id'],
                'body' => [
                    'url' => $this->firstAutoFetchConfigValue($meituanTrafficConfig, ['url'], ''),
                    'partner_id' => $this->firstAutoFetchConfigValue($meituanTrafficConfig, ['partner_id', 'partnerId'], $meituanPartnerId),
                    'poi_id' => $this->firstAutoFetchConfigValue($meituanTrafficConfig, ['poi_id', 'poiId'], $meituanPoiId),
                    'cookies' => $this->firstAutoFetchConfigValue($meituanTrafficConfig, ['cookies', 'cookie'], $meituanCookies),
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'extra_params' => $this->firstAutoFetchConfigValue($meituanTrafficConfig, ['extra_params', 'extraParams'], ''),
                    'auto_save' => true,
                    'system_hotel_id' => $hotelId,
                ],
            ]);
        }

        return $tasks;
    }

    private function executeCtripAutoFetch(int $hotelId, string $dataDate, array $options = []): array
    {
        $fetchConfig = $this->resolveCtripFetchConfigForHotel($hotelId);
        $cookies = trim((string)($fetchConfig['cookies'] ?? $fetchConfig['cookie'] ?? ''));
        $mode = $this->resolvePlatformAutoFetchMode($fetchConfig, $options, 'ctrip');
        $runCookieConfig = $this->shouldRunCookieConfigTasks($mode);
        $runProfileBrowser = $this->shouldRunProfileBrowser($mode);
        $taskPlanForConfig = $this->buildAutoFetchConfigTaskPlan($hotelId, $dataDate, $fetchConfig, [], $this->getAutoFetchSavedDataConfigs());
        $hasConfiguredTask = (bool)array_filter($taskPlanForConfig, static fn(array $task): bool => ($task['platform'] ?? '') === 'ctrip');
        $hasProfile = $this->ctripProfileExistsForConfig($fetchConfig, $hotelId);
        $hasProfileSeed = !empty($fetchConfig) && $this->ctripProfileStoreIdFromConfig($fetchConfig, $hotelId) !== '';

        if ($cookies === '' && !$hasProfile && !$hasConfiguredTask && !($runProfileBrowser && $hasProfileSeed)) {
            return [
                'platform' => 'ctrip',
                'success' => false,
                'message' => '未配置携程 Cookie/接口配置或浏览器 Profile',
                'saved_count' => 0,
                'auto_fetch_mode' => $mode,
                'mode_label' => $this->autoFetchModeLabel($mode),
                'modules' => [
                    $this->withAutoFetchResultMeta(['module' => 'configuration', 'saved_count' => 0, 'success' => false, 'skipped' => true, 'message' => '缺少携程 Cookie/接口配置/Profile'], 'hybrid_auto'),
                ],
            ];
        }
        
        $savedCount = 0;
        $errors = [];
        $modules = [];

        if ($runCookieConfig) {
            if ($cookies !== '') {
                try {
                    $result = $this->sendHttpRequest(
                        (string)($fetchConfig['url'] ?? 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportCompeteHotelReport'),
                        ['nodeId' => (string)($fetchConfig['node_id'] ?? '24588'), 'startDate' => $dataDate, 'endDate' => $dataDate],
                        $cookies
                    );

                    if (!empty($result['success'])) {
                        $responseData = $result['data'] ?? [];
                        $responseStatus = is_array($responseData) ? ($responseData['responseStatus'] ?? $responseData['status'] ?? $responseData['code'] ?? null) : null;
                        if ($responseStatus !== null && $responseStatus !== 0 && $responseStatus !== '0' && $responseStatus !== 200 && $responseStatus !== '200') {
                            $errorMsg = is_array($responseData) ? ($responseData['message'] ?? $responseData['msg'] ?? $responseData['errorMessage'] ?? null) : null;
                            $errors[] = $errorMsg ?: "API返回错误状态: {$responseStatus}";
                            $modules[] = $this->withAutoFetchResultMeta(['module' => 'day_report_api', 'saved_count' => 0, 'success' => false, 'message' => end($errors)], 'cookie_config');
                        } else {
                            $moduleSaved = is_array($responseData) ? $this->parseAndSaveData($responseData, $dataDate, $dataDate, $hotelId) : 0;
                            $savedCount += $moduleSaved;
                            $modules[] = $this->withAutoFetchResultMeta(['module' => 'day_report_api', 'saved_count' => $moduleSaved, 'success' => $moduleSaved > 0, 'message' => $moduleSaved > 0 ? 'ok' : '未解析到有效数据'], 'cookie_config');
                            if ($moduleSaved === 0) {
                                $errors[] = 'day_report_api 未解析到有效数据';
                            }
                        }
                    } else {
                        $errors[] = '请求失败: ' . (string)($result['error'] ?? '未知错误');
                        $modules[] = $this->withAutoFetchResultMeta(['module' => 'day_report_api', 'saved_count' => 0, 'success' => false, 'message' => end($errors)], 'cookie_config');
                    }

                } catch (\Exception $e) {
                    \think\facade\Log::error("携程自动获取异常", ['hotel_id' => $hotelId, 'error' => $e->getMessage()]);
                    $errors[] = '异常: ' . $e->getMessage();
                    $modules[] = $this->withAutoFetchResultMeta(['module' => 'day_report_api', 'saved_count' => 0, 'success' => false, 'message' => end($errors)], 'cookie_config');
                }
            } else {
                $message = '未配置携程 Cookie';
                if ($mode === 'cookie_config') {
                    $errors[] = $message;
                }
                $modules[] = $this->withAutoFetchResultMeta(['module' => 'day_report_api', 'saved_count' => 0, 'success' => false, 'skipped' => true, 'message' => $message], 'cookie_config');
            }
        } else {
            $modules[] = $this->withAutoFetchResultMeta(['module' => 'cookie_config_tasks', 'saved_count' => 0, 'success' => false, 'skipped' => true, 'message' => '当前策略仅使用浏览器 Profile'], 'cookie_config');
        }

        if ($runCookieConfig) {
            foreach ($taskPlanForConfig as $task) {
                if (($task['platform'] ?? '') !== 'ctrip' || ($task['module'] ?? '') === 'business') {
                    continue;
                }
                $taskResult = $this->executeAutoFetchTask($task, $hotelId, $dataDate);
                $savedCount += (int)($taskResult['saved_count'] ?? 0);
                $modules[] = $taskResult;
                if (empty($taskResult['success']) && empty($taskResult['skipped'])) {
                    $errors[] = (string)($taskResult['message'] ?? (($task['label'] ?? 'task') . ' failed'));
                }
            }
        }

        $runProfileByCost = $this->shouldRunProfileBrowserForCost($mode, $savedCount);
        $browserResult = $runProfileBrowser && $runProfileByCost
            ? $this->executeCtripBrowserProfileAutoFetch($fetchConfig, $hotelId, $dataDate, !empty($options['interactive_browser']))
            : [
                'success' => false,
                'skipped' => true,
                'message' => $runProfileBrowser ? 'Cookie/配置已有入库，按最低成本跳过浏览器 Profile' : '当前策略仅使用 Cookie/配置自动',
                'saved_count' => 0,
            ];
        if (empty($browserResult['skipped'])) {
            $savedCount += (int)($browserResult['saved_count'] ?? 0);
        }
        $modules[] = $this->withAutoFetchResultMeta([
            'module' => 'browser_profile',
            'saved_count' => (int)($browserResult['saved_count'] ?? 0),
            'success' => (bool)($browserResult['success'] ?? false),
            'message' => (string)($browserResult['message'] ?? ''),
            'skipped' => (bool)($browserResult['skipped'] ?? false),
        ], 'profile_browser');

        if (!empty($browserResult['message']) && empty($browserResult['success']) && empty($browserResult['skipped'])) {
            $errors[] = 'browser ' . $browserResult['message'];
        } elseif (!empty($browserResult['skipped']) && $mode === 'profile_browser') {
            $errors[] = (string)$browserResult['message'];
        }

        if ($savedCount > 0) {
            \think\facade\Log::info("携程自动获取成功", ['hotel_id' => $hotelId, 'count' => $savedCount]);
            $this->updateCtripLatestFetchStatus($hotelId, date('Y-m-d H:i:s'), $dataDate, $savedCount);
            
            return ['platform' => 'ctrip', 'success' => true, 'message' => "成功获取 {$savedCount} 条数据", 'saved_count' => $savedCount, 'auto_fetch_mode' => $mode, 'mode_label' => $this->autoFetchModeLabel($mode), 'modules' => $modules];
        }

        $message = empty($errors)
            ? '未获取到有效数据'
            : '未获取到有效数据：' . implode('；', array_slice($errors, 0, 3));
        return ['platform' => 'ctrip', 'success' => false, 'message' => $message, 'saved_count' => 0, 'auto_fetch_mode' => $mode, 'mode_label' => $this->autoFetchModeLabel($mode), 'modules' => $modules];
    }

    private function executeAutoFetchTask(array $task, int $hotelId, string $dataDate): array
    {
        $body = is_array($task['body'] ?? null) ? $task['body'] : [];
        $module = (string)($task['module'] ?? '');
        $label = (string)($task['label'] ?? $module);
        $strategy = (string)($task['strategy'] ?? 'cookie_config');

        try {
            $result = match (($task['platform'] ?? '') . ':' . $module) {
                'ctrip:traffic' => $this->executeCtripTrafficAutoFetchTask($label, $body, $hotelId),
                'ctrip:comments' => $this->executeCtripCommentsAutoFetchTask($label, $body, $hotelId, $dataDate),
                'meituan:ranking' => $this->executeMeituanRankingAutoFetchTask($label, $body, $hotelId),
                'meituan:traffic' => $this->executeMeituanTrafficAutoFetchTask($label, $body, $hotelId),
                'meituan:comments' => $this->executeMeituanCommentsAutoFetchTask($label, $body, $hotelId),
                default => ['module' => $label, 'saved_count' => 0, 'success' => false, 'skipped' => true, 'message' => 'unsupported task'],
            };
            return $this->withAutoFetchResultMeta($result, $strategy, $label);
        } catch (\Throwable $e) {
            return $this->withAutoFetchResultMeta(['module' => $label, 'saved_count' => 0, 'success' => false, 'message' => $e->getMessage()], $strategy, $label);
        }
    }

    private function executeCtripTrafficAutoFetchTask(string $label, array $body, int $hotelId): array
    {
        $cookies = trim((string)($body['cookies'] ?? ''));
        if ($cookies === '') {
            return ['module' => $label, 'saved_count' => 0, 'success' => false, 'skipped' => true, 'message' => 'missing cookies'];
        }

        [$startDate, $endDate] = $this->buildCtripTrafficDateRange('custom', (string)($body['start_date'] ?? ''), (string)($body['end_date'] ?? ''));
        $extraParams = $this->configValueToArray($body['extra_params'] ?? []);
        $spiderkey = trim((string)($body['spiderkey'] ?? ($extraParams['spiderkey'] ?? '')));
        $platform = ucfirst(strtolower((string)($body['platform'] ?? 'Ctrip')));
        if (!in_array($platform, ['Ctrip', 'Qunar'], true)) {
            $platform = 'Ctrip';
        }

        $postData = $extraParams;
        $postData['platform'] = $platform;
        $postData['startDate'] = $startDate;
        $postData['endDate'] = $endDate;
        $postData['fingerPrintKeys'] = $postData['fingerPrintKeys'] ?? '';
        $postData['spiderkey'] = $spiderkey;
        $postData['spiderVersion'] = $postData['spiderVersion'] ?? '2.0';

        $result = $this->sendCtripJsonRequest($this->normalizeCtripTrafficUrl((string)($body['url'] ?? '')), $postData, $cookies);
        if (!empty($result['error'])) {
            $this->recordCookieAlert(strtolower($platform), 'auto-fetch-ctrip-traffic', (string)$result['error'], $hotelId);
            return ['module' => $label, 'saved_count' => 0, 'success' => false, 'message' => (string)$result['error']];
        }

        $responseData = $result['decoded_data'];
        $apiError = $this->getCtripTrafficApiError($responseData);
        if ($apiError !== '') {
            $this->recordCookieAlert(strtolower($platform), 'auto-fetch-ctrip-traffic', $apiError, $hotelId);
            return ['module' => $label, 'saved_count' => 0, 'success' => false, 'message' => $apiError];
        }

        $savedCount = is_array($responseData)
            ? $this->parseAndSaveTrafficData($responseData, $startDate, $endDate, strtolower($platform), $hotelId, $platform)
            : 0;
        return ['module' => $label, 'saved_count' => $savedCount, 'success' => $savedCount > 0, 'message' => $savedCount > 0 ? 'ok' : 'no rows'];
    }

    private function executeCtripCommentsAutoFetchTask(string $label, array $body, int $hotelId, string $dataDate): array
    {
        $requestUrl = trim((string)($body['request_url'] ?? ''));
        $cookies = trim((string)($body['cookies'] ?? ''));
        $token = trim((string)($body['spidertoken'] ?? $body['token'] ?? ''));
        $payload = $this->configValueToArray($body['payload_json'] ?? []);
        $requestHotelId = trim((string)($body['hotel_id'] ?? $payload['hotelId'] ?? $payload['hotel_id'] ?? ''));

        if ($requestUrl === '' || $cookies === '' || $token === '' || empty($payload)) {
            return ['module' => $label, 'saved_count' => 0, 'success' => false, 'skipped' => true, 'message' => 'missing ctrip comments config'];
        }
        if (!$this->isAllowedOtaRequestUrl($requestUrl, ['ctrip.com'])) {
            return ['module' => $label, 'saved_count' => 0, 'success' => false, 'message' => 'invalid ctrip comments url'];
        }

        $query = [];
        if (!empty($body['_fxpcqlniredt'])) {
            $query['_fxpcqlniredt'] = (string)$body['_fxpcqlniredt'];
        }
        if (!empty($body['x_trace_id'])) {
            $query['x-traceID'] = (string)$body['x_trace_id'];
        }
        if (!empty($query)) {
            $requestUrl .= (strpos($requestUrl, '?') === false ? '?' : '&') . http_build_query($query);
        }

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($jsonPayload === false) {
            return ['module' => $label, 'saved_count' => 0, 'success' => false, 'message' => 'payload encode failed'];
        }

        $headers = [
            'Accept: application/json, text/plain, */*',
            'Accept-Encoding: identity',
            'Content-Type: application/json',
            'Origin: https://ebooking.ctrip.com',
            'Referer: https://ebooking.ctrip.com/comment/commentList?microJump=true',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Cookie: ' . $cookies,
            'spidertoken: ' . $token,
            'Content-Length: ' . strlen($jsonPayload),
        ];

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $jsonPayload,
                'timeout' => 30,
                'ignore_errors' => true,
            ],
            'ssl' => $this->buildStreamSslOptions(),
        ]);
        $response = @file_get_contents($requestUrl, false, $context);
        if ($response === false) {
            $message = error_get_last()['message'] ?? 'request failed';
            $this->recordCookieAlert('ctrip', 'auto-fetch-ctrip-comments', $message, $hotelId);
            return ['module' => $label, 'saved_count' => 0, 'success' => false, 'message' => $message];
        }

        $decodedResponse = substr($response, 0, 2) === "\x1f\x8b" ? gzdecode($response) : $response;
        $data = json_decode((string)$decodedResponse, true);
        if (!is_array($data)) {
            return ['module' => $label, 'saved_count' => 0, 'success' => false, 'message' => 'invalid json'];
        }
        $comments = $data['data']['commentList']
            ?? $data['data']['list']
            ?? $data['data']['comments']
            ?? $data['commentList']
            ?? $data['comments']
            ?? $data['data']
            ?? [];
        $comments = is_array($comments) ? $comments : [];
        $savedCount = $this->parseAndSaveCtripComments($comments, $payload, $requestHotelId, $dataDate, $hotelId);
        return ['module' => $label, 'saved_count' => $savedCount, 'success' => $savedCount > 0, 'message' => $savedCount > 0 ? 'ok' : 'no rows'];
    }

    private function executeCtripBrowserProfileAutoFetch(array $config, int $hotelId, string $dataDate, bool $interactiveBrowser = false): array
    {
        $profileId = $this->ctripProfileStoreIdFromConfig($config, $hotelId);
        if ($profileId === '') {
            return ['success' => false, 'skipped' => true, 'message' => '未配置携程 Profile ID', 'saved_count' => 0];
        }
        if (!$this->ctripProfileExistsForConfig($config, $hotelId) && !$interactiveBrowser) {
            return ['success' => false, 'skipped' => true, 'message' => "未找到 storage/ctrip_profile_{$profileId}", 'saved_count' => 0];
        }

        $projectRoot = dirname(__DIR__, 2);
        $scriptPath = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'ctrip_browser_capture.mjs';
        if (!is_file($scriptPath)) {
            return ['success' => false, 'skipped' => true, 'message' => '未找到携程浏览器采集脚本', 'saved_count' => 0];
        }

        $nodeBinary = $this->resolveMeituanCaptureNodeBinary();
        if ($nodeBinary === '') {
            return ['success' => false, 'skipped' => true, 'message' => '未找到 Node.js', 'saved_count' => 0];
        }

        $outputDir = $projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'ctrip_capture';
        if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
            return ['success' => false, 'message' => '无法创建携程采集输出目录', 'saved_count' => 0];
        }

        $outputPath = $outputDir . DIRECTORY_SEPARATOR . 'ctrip_browser_auto_' . $this->safeMeituanCaptureFilePart($profileId) . '_' . date('YmdHis') . '.json';
        $sections = trim((string)($config['profile_sections'] ?? $config['capture_sections'] ?? 'business,traffic'));
        $sections = $sections !== '' ? $sections : 'business,traffic';
        $args = [
            $nodeBinary,
            $scriptPath,
            '--profile-id=' . $profileId,
            '--system-hotel-id=' . (string)$hotelId,
            '--data-date=' . $dataDate,
            '--output=' . $outputPath,
            '--login-timeout-ms=' . ($interactiveBrowser ? '300000' : '30000'),
            '--sections=business,traffic',
        ];
        if ($sections !== 'business,traffic') {
            $args[count($args) - 1] = '--sections=' . $sections;
        }
        $args[] = $interactiveBrowser ? '--headless=false' : '--headless=true';

        $ctripHotelId = trim((string)($config['ota_hotel_id'] ?? $config['ctrip_hotel_id'] ?? $config['hotelId'] ?? ''));
        if ($ctripHotelId !== '') {
            $args[] = '--hotel-id=' . $ctripHotelId;
        }
        $hotelName = trim((string)($config['hotel_name'] ?? $config['name'] ?? ''));
        if ($hotelName !== '') {
            $args[] = '--hotel-name=' . $hotelName;
        }
        $chromePath = $this->resolveMeituanCaptureChromePath();
        if ($chromePath !== '') {
            $args[] = '--chrome-path=' . $chromePath;
        }

        $cookieFile = $this->createAutoFetchCookieFile($projectRoot, 'ctrip', $hotelId, trim((string)($config['cookies'] ?? $config['cookie'] ?? '')));
        if ($cookieFile !== '') {
            $args[] = '--cookies-file=' . $cookieFile;
        }

        try {
            $runResult = $this->runMeituanCaptureProcess($args, $projectRoot, $interactiveBrowser ? 600 : 120);
        } finally {
            $this->removeAutoFetchCookieFile($cookieFile);
        }
        if (!$runResult['success']) {
            return [
                'success' => false,
                'message' => str_replace('美团', '携程', (string)$runResult['message']),
                'saved_count' => 0,
                'stdout' => $this->trimMeituanCaptureLog($runResult['stdout'] ?? ''),
                'stderr' => $this->trimMeituanCaptureLog($runResult['stderr'] ?? ''),
            ];
        }
        if (!is_file($outputPath)) {
            return ['success' => false, 'message' => '携程浏览器采集未生成结果文件', 'saved_count' => 0];
        }

        $payload = json_decode((string)file_get_contents($outputPath), true);
        if (!is_array($payload)) {
            return ['success' => false, 'message' => '携程浏览器采集结果 JSON 无法解析', 'saved_count' => 0];
        }

        if (empty($payload['system_hotel_id'])) {
            $payload['system_hotel_id'] = $hotelId;
        }
        $requestHotelId = $ctripHotelId !== '' ? $ctripHotelId : (string)($payload['hotel_id'] ?? $profileId);
        $saveResult = $this->saveCtripBrowserProfilePayload($payload, $hotelId, $dataDate, $requestHotelId);
        $savedCount = (int)$saveResult['saved_count'];
        $capturedCounts = [
            'business' => count($this->extractCtripCapturedSection($payload, 'business')),
            'traffic' => count($this->extractCtripCapturedSection($payload, 'traffic')),
            'reviews' => count($this->extractCtripCapturedComments($payload)),
        ];
        $detailParts = [
            "概况 {$saveResult['business_saved']}",
            "流量 {$saveResult['traffic_saved']}",
        ];
        if ((int)($saveResult['review_saved'] ?? 0) > 0) {
            $detailParts[] = "点评 {$saveResult['review_saved']}";
        }

        return [
            'success' => $savedCount > 0,
            'message' => $savedCount > 0
                ? "Profile 真实采集入库 {$savedCount} 条（" . implode('，', $detailParts) . "）"
                : 'Profile 真实采集未解析到可入库数据',
            'saved_count' => $savedCount,
            'row_count' => array_sum($capturedCounts),
            'captured_counts' => $capturedCounts,
            'modules' => $saveResult['modules'],
            'output' => $outputPath,
        ];
    }

    private function saveCtripBrowserProfilePayload(array $payload, int $hotelId, string $dataDate, string $requestHotelId): array
    {
        $modules = [];

        $businessRows = $this->extractCtripCapturedSection($payload, 'business');
        $businessSaved = 0;
        if (!empty($businessRows)) {
            $businessSaved = $this->parseAndSaveData(['data' => $businessRows], $dataDate, $dataDate, $hotelId);
        }
        if ($businessSaved === 0) {
            foreach ($this->extractCtripCapturedResponseData($payload, 'business') as $responseData) {
                $businessSaved += $this->parseAndSaveData($responseData, $dataDate, $dataDate, $hotelId);
            }
        }
        $modules[] = ['module' => 'browser_business', 'saved_count' => $businessSaved, 'success' => $businessSaved > 0];

        $trafficRows = $this->extractCtripCapturedSection($payload, 'traffic');
        $trafficSaved = 0;
        if (!empty($trafficRows)) {
            $trafficSaved = $this->parseAndSaveTrafficData(['data' => ['list' => $trafficRows]], $dataDate, $dataDate, 'ctrip', $hotelId, 'Ctrip');
        }
        if ($trafficSaved === 0) {
            foreach ($this->extractCtripCapturedResponseData($payload, 'traffic') as $responseData) {
                $trafficSaved += $this->parseAndSaveTrafficData($responseData, $dataDate, $dataDate, 'ctrip', $hotelId, 'Ctrip');
            }
        }
        $modules[] = ['module' => 'browser_traffic', 'saved_count' => $trafficSaved, 'success' => $trafficSaved > 0];

        $reviewRows = $this->extractCtripCapturedComments($payload);
        $reviewSaved = 0;
        if (!empty($reviewRows)) {
            $reviewSaved = $this->parseAndSaveCtripComments($reviewRows, $payload, $requestHotelId, $dataDate, $hotelId);
            $modules[] = ['module' => 'browser_reviews', 'saved_count' => $reviewSaved, 'success' => $reviewSaved > 0];
        }

        return [
            'saved_count' => $businessSaved + $trafficSaved + $reviewSaved,
            'business_saved' => $businessSaved,
            'traffic_saved' => $trafficSaved,
            'review_saved' => $reviewSaved,
            'modules' => $modules,
        ];
    }

    private function extractCtripCapturedResponseData(array $payload, string $section): array
    {
        $result = [];
        foreach (($payload['responses'] ?? []) as $response) {
            if (!is_array($response) || strtolower((string)($response['section'] ?? '')) !== $section) {
                continue;
            }
            $data = $response['data'] ?? $response['body'] ?? $response['json'] ?? null;
            if (is_array($data)) {
                $result[] = $data;
            }
        }
        return $result;
    }

    private function executeMeituanAutoFetch(int $hotelId, string $dataDate, array $options = []): array
    {
        $config = $this->resolveMeituanFetchConfigForHotel($hotelId);
        $cookies = trim((string)($config['cookies'] ?? $config['cookie'] ?? ''));
        $partnerId = trim((string)($config['partner_id'] ?? $config['partnerId'] ?? ''));
        $poiId = trim((string)($config['poi_id'] ?? $config['poiId'] ?? ''));
        $apiStatus = $this->meituanAutoFetchConfigStatus($config);
        $missingText = (string)$apiStatus['missing_text'];
        $mode = $this->resolvePlatformAutoFetchMode($config, $options, 'meituan');
        $runCookieConfig = $this->shouldRunCookieConfigTasks($mode);
        $runProfileBrowser = $this->shouldRunProfileBrowser($mode);
        $taskPlanForConfig = $this->buildAutoFetchConfigTaskPlan($hotelId, $dataDate, [], $config, $this->getAutoFetchSavedDataConfigs());
        $hasConfiguredTask = (bool)array_filter($taskPlanForConfig, static fn(array $task): bool => ($task['platform'] ?? '') === 'meituan');
        $hasProfile = $this->meituanProfileExistsForConfig($config);
        $hasProfileSeed = $this->meituanProfileStoreIdFromConfig($config) !== '';

        if ($cookies === '' && !$hasProfile && !$hasConfiguredTask && !($runProfileBrowser && $hasProfileSeed)) {
            $message = $missingText !== '' ? '未配置美团 ' . $missingText : '未配置美团 Partner ID / POI ID / Cookies';
            return [
                'platform' => 'meituan',
                'success' => false,
                'message' => $message,
                'saved_count' => 0,
                'auto_fetch_mode' => $mode,
                'mode_label' => $this->autoFetchModeLabel($mode),
                'modules' => [
                    $this->withAutoFetchResultMeta(['module' => 'configuration', 'saved_count' => 0, 'success' => false, 'skipped' => true, 'message' => $message], 'hybrid_auto'),
                ],
            ];
        }

        $savedCount = 0;
        $errors = [];
        $modules = [];

        if ($runCookieConfig && !empty($apiStatus['api_configured'])) {
            $url = trim((string)($config['url'] ?? '')) ?: 'https://eb.meituan.com/api/v1/ebooking/business/peer/rank/data/detail';
            $authDataRaw = $config['auth_data'] ?? [];
            try {
                $authData = is_array($authDataRaw) ? $authDataRaw : $this->parseJsonParams((string)$authDataRaw);
            } catch (\Throwable $e) {
                $authData = [];
            }
            $baseParams = [
                'dataScope' => $config['data_scope'] ?? 'vpoi',
                'deviceType' => 1,
                'yodaReady' => 'h5',
                'csecplatform' => 4,
                'csecversion' => '4.2.0',
                'partnerId' => $partnerId,
                'poiId' => $poiId,
                'startDate' => str_replace('-', '', $dataDate),
                'endDate' => str_replace('-', '', $dataDate),
                'dateRange' => 1,
            ];

            foreach (['P_RZ', 'P_XS', 'P_ZH', 'P_LL'] as $rankType) {
                try {
                    $params = $baseParams;
                    $params['rankType'] = $rankType;
                    $result = $this->sendMeituanRequest($url, $params, $cookies, $authData);
                    if (!$result['success']) {
                        $errors[] = "{$rankType} " . (string)($result['error'] ?? '请求失败');
                        $modules[] = $this->withAutoFetchResultMeta(['module' => $rankType, 'saved_count' => 0, 'success' => false, 'message' => end($errors)], 'cookie_config');
                        continue;
                    }
                    $moduleSaved = is_array($result['data'] ?? null)
                        ? $this->parseAndSaveMeituanData($result['data'], $dataDate, $dataDate, $hotelId)
                        : 0;
                    $savedCount += $moduleSaved;
                    $modules[] = $this->withAutoFetchResultMeta(['module' => $rankType, 'saved_count' => $moduleSaved, 'success' => $moduleSaved > 0, 'message' => $moduleSaved > 0 ? 'ok' : '未解析到有效数据'], 'cookie_config');
                } catch (\Throwable $e) {
                    $errors[] = "{$rankType} " . $e->getMessage();
                    $modules[] = $this->withAutoFetchResultMeta(['module' => $rankType, 'saved_count' => 0, 'success' => false, 'message' => $e->getMessage()], 'cookie_config');
                }
            }
        } elseif ($runCookieConfig) {
            $message = $missingText !== '' ? '缺少美团 ' . $missingText : '缺少美团 Partner ID / POI ID / Cookies';
            if ($mode === 'cookie_config') {
                $errors[] = $message;
            }
            $modules[] = $this->withAutoFetchResultMeta(['module' => 'ranking_api', 'saved_count' => 0, 'success' => false, 'skipped' => true, 'message' => $message], 'cookie_config');
        } else {
            $modules[] = $this->withAutoFetchResultMeta(['module' => 'cookie_config_tasks', 'saved_count' => 0, 'success' => false, 'skipped' => true, 'message' => '当前策略仅使用浏览器 Profile'], 'cookie_config');
        }

        if ($runCookieConfig) {
            foreach ($taskPlanForConfig as $task) {
                if (($task['platform'] ?? '') !== 'meituan' || ($task['module'] ?? '') === 'ranking') {
                    continue;
                }
                $taskResult = $this->executeAutoFetchTask($task, $hotelId, $dataDate);
                $savedCount += (int)($taskResult['saved_count'] ?? 0);
                $modules[] = $taskResult;
                if (empty($taskResult['success']) && empty($taskResult['skipped'])) {
                    $errors[] = (string)($taskResult['message'] ?? (($task['label'] ?? 'task') . ' failed'));
                }
            }
        }

        $runProfileByCost = $this->shouldRunProfileBrowserForCost($mode, $savedCount);
        $browserResult = $runProfileBrowser && $runProfileByCost
            ? $this->executeMeituanBrowserProfileAutoFetch($config, $hotelId, $dataDate, !empty($options['interactive_browser']))
            : [
                'success' => false,
                'skipped' => true,
                'message' => $runProfileBrowser ? 'Cookie/配置已有入库，按最低成本跳过浏览器 Profile' : '当前策略仅使用 Cookie/配置自动',
                'saved_count' => 0,
            ];
        if (empty($browserResult['skipped'])) {
            $savedCount += (int)($browserResult['saved_count'] ?? 0);
        }
        $modules[] = $this->withAutoFetchResultMeta([
            'module' => 'browser_profile',
            'saved_count' => (int)($browserResult['saved_count'] ?? 0),
            'success' => (bool)($browserResult['success'] ?? false),
            'message' => (string)($browserResult['message'] ?? ''),
            'skipped' => (bool)($browserResult['skipped'] ?? false),
        ], 'profile_browser');

        if (!empty($browserResult['message']) && empty($browserResult['success']) && empty($browserResult['skipped'])) {
            $errors[] = 'browser ' . $browserResult['message'];
        } elseif (!empty($browserResult['skipped']) && $mode === 'profile_browser') {
            $errors[] = (string)$browserResult['message'];
        }

        if ($savedCount > 0) {
            \think\facade\Log::info("美团自动获取成功", ['hotel_id' => $hotelId, 'count' => $savedCount]);
            return [
                'platform' => 'meituan',
                'success' => true,
                'message' => "成功获取 {$savedCount} 条数据",
                'saved_count' => $savedCount,
                'auto_fetch_mode' => $mode,
                'mode_label' => $this->autoFetchModeLabel($mode),
                'modules' => $modules,
            ];
        }

        $message = empty($errors)
            ? '未获取到有效数据'
            : '未获取到有效数据：' . implode('；', array_slice($errors, 0, 3));
        return [
            'platform' => 'meituan',
            'success' => false,
            'message' => $message,
            'saved_count' => 0,
            'auto_fetch_mode' => $mode,
            'mode_label' => $this->autoFetchModeLabel($mode),
            'modules' => $modules,
        ];
    }

    private function executeMeituanRankingAutoFetchTask(string $label, array $body, int $hotelId): array
    {
        $cookies = trim((string)($body['cookies'] ?? ''));
        $partnerId = trim((string)($body['partner_id'] ?? ''));
        $poiId = trim((string)($body['poi_id'] ?? ''));
        $apiStatus = $this->meituanAutoFetchConfigStatus($body);
        if (empty($apiStatus['api_configured'])) {
            return ['module' => $label, 'saved_count' => 0, 'success' => false, 'skipped' => true, 'message' => '缺少美团 ' . $apiStatus['missing_text']];
        }

        $params = [
            'dataScope' => $body['data_scope'] ?? 'vpoi',
            'deviceType' => 1,
            'yodaReady' => 'h5',
            'csecplatform' => 4,
            'csecversion' => '4.2.0',
            'partnerId' => $partnerId,
            'poiId' => $poiId,
            'rankType' => $body['rank_type'] ?? 'P_RZ',
            'startDate' => str_replace('-', '', (string)($body['start_date'] ?? '')),
            'endDate' => str_replace('-', '', (string)($body['end_date'] ?? '')),
            'dateRange' => 1,
        ];
        $result = $this->sendMeituanRequest(
            trim((string)($body['url'] ?? '')) ?: 'https://eb.meituan.com/api/v1/ebooking/business/peer/rank/data/detail',
            $params,
            $cookies,
            $this->configValueToArray($body['auth_data'] ?? [])
        );
        if (!$result['success']) {
            $message = (string)($result['error'] ?? 'request failed');
            $this->recordCookieAlert('meituan', 'auto-fetch-meituan-ranking', $message, $hotelId);
            return ['module' => $label, 'saved_count' => 0, 'success' => false, 'message' => $message];
        }

        $savedCount = is_array($result['data'] ?? null)
            ? $this->parseAndSaveMeituanData($result['data'], (string)($body['start_date'] ?? ''), (string)($body['end_date'] ?? ''), $hotelId)
            : 0;
        return ['module' => $label, 'saved_count' => $savedCount, 'success' => $savedCount > 0, 'message' => $savedCount > 0 ? 'ok' : 'no rows'];
    }

    private function executeMeituanTrafficAutoFetchTask(string $label, array $body, int $hotelId): array
    {
        $url = trim((string)($body['url'] ?? ''));
        $cookies = trim((string)($body['cookies'] ?? ''));
        $partnerId = trim((string)($body['partner_id'] ?? ''));
        $poiId = trim((string)($body['poi_id'] ?? ''));
        $apiStatus = $this->meituanAutoFetchConfigStatus($body);
        if ($url === '' || empty($apiStatus['api_configured'])) {
            $missing = $apiStatus['missing_fields'];
            if ($url === '') {
                array_unshift($missing, 'Request URL');
            }
            return ['module' => $label, 'saved_count' => 0, 'success' => false, 'skipped' => true, 'message' => '缺少美团 ' . implode(' / ', $missing)];
        }

        $extraParams = $this->configValueToArray($body['extra_params'] ?? []);
        $params = array_merge([
            'deviceType' => 1,
            'yodaReady' => 'h5',
            'csecplatform' => 4,
            'csecversion' => '4.2.0',
        ], $extraParams);
        $params['partnerId'] = $partnerId;
        $params['poiId'] = $poiId;
        $startDate = (string)($body['start_date'] ?? date('Y-m-d', strtotime('-1 day')));
        $endDate = (string)($body['end_date'] ?? $startDate);
        $params['startDate'] = str_replace('-', '', $startDate);
        $params['endDate'] = str_replace('-', '', $endDate);
        $params['dateRange'] = 1;

        $result = $this->sendMeituanRequest($url, $params, $cookies);
        if (!$result['success']) {
            $message = (string)($result['error'] ?? 'request failed');
            $this->recordCookieAlert('meituan', 'auto-fetch-meituan-traffic', $message, $hotelId);
            return ['module' => $label, 'saved_count' => 0, 'success' => false, 'message' => $message];
        }

        $responseData = $result['data'] ?? [];
        $savedCount = is_array($responseData)
            ? $this->parseAndSaveTrafficData($responseData, $startDate, $endDate, 'meituan', $hotelId)
            : 0;
        return ['module' => $label, 'saved_count' => $savedCount, 'success' => $savedCount > 0, 'message' => $savedCount > 0 ? 'ok' : 'no rows'];
    }

    private function executeMeituanCommentsAutoFetchTask(string $label, array $body, int $hotelId): array
    {
        $cookies = trim((string)($body['cookies'] ?? ''));
        $partnerId = trim((string)($body['partner_id'] ?? ''));
        $poiId = trim((string)($body['poi_id'] ?? ''));
        $apiStatus = $this->meituanAutoFetchConfigStatus($body);
        if (empty($apiStatus['api_configured'])) {
            return ['module' => $label, 'saved_count' => 0, 'success' => false, 'skipped' => true, 'message' => '缺少美团 ' . $apiStatus['missing_text']];
        }

        $requestUrl = trim((string)($body['request_url'] ?? ''));
        if ($requestUrl !== '' && filter_var($requestUrl, FILTER_VALIDATE_URL)) {
            $fullUrl = $requestUrl;
        } else {
            $params = [
                'limit' => (int)($body['limit'] ?? 50),
                'offset' => (int)($body['offset'] ?? 0),
                'partnerId' => $partnerId,
                'platform' => 1,
                'poiId' => $poiId,
                'prefetchIndex' => 1,
                'replyType' => (string)($body['reply_type'] ?? '2'),
                'reportStatus' => '',
                'tag' => (string)($body['tag'] ?? ''),
                'yodaReady' => 'h5',
                'csecplatform' => 4,
                'csecversion' => '4.2.0',
            ];
            if (!empty($body['_mtsi_eb_u'])) {
                $params['_mtsi_eb_u'] = (string)$body['_mtsi_eb_u'];
            }
            if (!empty($body['start_date'])) {
                $params['startDate'] = (string)$body['start_date'];
            }
            if (!empty($body['end_date'])) {
                $params['endDate'] = (string)$body['end_date'];
            }
            $fullUrl = 'https://eb.meituan.com/api/v1/ebooking/comments/commentsInfo?' . http_build_query($params);
        }
        if (!$this->isAllowedOtaRequestUrl($fullUrl, ['meituan.com'])) {
            return ['module' => $label, 'saved_count' => 0, 'success' => false, 'message' => 'invalid meituan comments url'];
        }

        $traceId = '-' . rand(1000000000, 9999999999) . time();
        $headers = [
            'Accept: application/json, text/plain, */*',
            'Accept-Language: zh-CN,zh;q=0.9',
            'Cookie: ' . $cookies,
            'm-appkey: fe_hotel-fe-ebooking',
            'm-traceid: ' . $traceId,
            'Origin: https://eb.meituan.com',
            'Referer: https://eb.meituan.com/ebk/feedback/feedback.html',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ];
        if (!empty($body['mtgsig'])) {
            $headers[] = 'mtgsig: ' . (string)$body['mtgsig'];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => 30,
                'ignore_errors' => true,
            ],
            'ssl' => $this->buildStreamSslOptions(),
        ]);
        $response = @file_get_contents($fullUrl, false, $context);
        if ($response === false) {
            $message = error_get_last()['message'] ?? 'request failed';
            $this->recordCookieAlert('meituan', 'auto-fetch-meituan-comments', $message, $hotelId);
            return ['module' => $label, 'saved_count' => 0, 'success' => false, 'message' => $message];
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data)) {
            return ['module' => $label, 'saved_count' => 0, 'success' => false, 'message' => 'invalid json'];
        }
        $comments = $data['data']['commentList'] ?? $data['data']['list'] ?? $data['data'] ?? [];
        $comments = is_array($comments) ? $comments : [];
        $savedCount = !empty($comments) ? $this->parseAndSaveMeituanComments($comments, $poiId, $partnerId, $hotelId) : 0;
        return ['module' => $label, 'saved_count' => $savedCount, 'success' => $savedCount > 0, 'message' => $savedCount > 0 ? 'ok' : 'no rows'];
    }

    private function executeMeituanBrowserProfileAutoFetch(array $config, int $hotelId, string $dataDate, bool $interactiveBrowser = false): array
    {
        $storeId = $this->meituanProfileStoreIdFromConfig($config);
        if ($storeId === '') {
            return ['success' => false, 'skipped' => true, 'message' => '未配置 Store ID / POI ID', 'saved_count' => 0];
        }
        if (!$this->meituanProfileExistsForConfig($config) && !$interactiveBrowser) {
            return ['success' => false, 'skipped' => true, 'message' => '未发现本地美团浏览器 Profile，跳过浏览器采集', 'saved_count' => 0];
        }

        $projectRoot = dirname(__DIR__, 2);
        $scriptPath = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'meituan_browser_capture.mjs';
        if (!is_file($scriptPath)) {
            return ['success' => false, 'skipped' => true, 'message' => '未找到美团浏览器抓取脚本', 'saved_count' => 0];
        }
        $nodeBinary = $this->resolveMeituanCaptureNodeBinary();
        if ($nodeBinary === '') {
            return ['success' => false, 'skipped' => true, 'message' => '未找到 Node.js', 'saved_count' => 0];
        }

        $outputDir = $projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'meituan_capture';
        if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
            return ['success' => false, 'message' => '无法创建美团抓取输出目录', 'saved_count' => 0];
        }

        $outputPath = $outputDir . DIRECTORY_SEPARATOR . 'meituan_auto_' . $this->safeMeituanCaptureFilePart($storeId) . '_' . date('YmdHis') . '.json';
        $args = [
            $nodeBinary,
            $scriptPath,
            '--store-id=' . $storeId,
            '--output=' . $outputPath,
            '--system-hotel-id=' . (string)$hotelId,
            '--login-timeout-ms=' . ($interactiveBrowser ? '300000' : '30000'),
        ];
        $args[] = $interactiveBrowser ? '--headless=false' : '--headless=true';
        $sections = trim((string)($config['profile_sections'] ?? $config['capture_sections'] ?? 'traffic,orders'));
        $args[] = '--sections=' . ($sections !== '' ? $sections : 'traffic,orders');
        $poiId = trim((string)($config['poi_id'] ?? $config['poiId'] ?? ''));
        if ($poiId !== '') {
            $args[] = '--poi-id=' . $poiId;
        }
        $poiName = trim((string)($config['name'] ?? $config['hotel_name'] ?? ''));
        if ($poiName !== '') {
            $args[] = '--poi-name=' . $poiName;
        }
        $chromePath = $this->resolveMeituanCaptureChromePath();
        if ($chromePath !== '') {
            $args[] = '--chrome-path=' . $chromePath;
        }

        $cookieFile = $this->createAutoFetchCookieFile($projectRoot, 'meituan', $hotelId, trim((string)($config['cookies'] ?? $config['cookie'] ?? '')));
        if ($cookieFile !== '') {
            $args[] = '--cookies-file=' . $cookieFile;
        }

        try {
            $runResult = $this->runMeituanCaptureProcess($args, $projectRoot, $interactiveBrowser ? 600 : 180);
        } finally {
            $this->removeAutoFetchCookieFile($cookieFile);
        }
        if (!$runResult['success']) {
            return ['success' => false, 'message' => $runResult['message'], 'saved_count' => 0];
        }
        if (!is_file($outputPath)) {
            return ['success' => false, 'message' => '浏览器采集未生成结果文件', 'saved_count' => 0];
        }

        $payload = json_decode((string)file_get_contents($outputPath), true);
        if (!is_array($payload)) {
            return ['success' => false, 'message' => '浏览器采集结果 JSON 无法解析', 'saved_count' => 0];
        }
        $payload['system_hotel_id'] = $hotelId;
        $payload['default_data_date'] = $dataDate;
        $rows = $this->buildMeituanCapturedDailyRows($payload, $hotelId);
        $savedCount = empty($rows) ? 0 : $this->saveMeituanCapturedDailyRows($rows);

        return [
            'success' => $savedCount > 0,
            'message' => $savedCount > 0 ? "浏览器采集保存 {$savedCount} 条" : '浏览器采集未解析到指定日期数据',
            'saved_count' => $savedCount,
        ];
    }

    /**
     * 从系统配置读取列表
     */
    private function sanitizeSecretConfig(array $item): array
    {
        foreach (['cookies', 'cookie'] as $field) {
            if (array_key_exists($field, $item)) {
                $value = (string)$item[$field];
                $item['has_cookies'] = trim($value) !== '';
                $item['cookies_preview'] = $this->maskSecretValue($value);
                unset($item[$field]);
            }
        }

        foreach (['token', 'spidertoken', 'mtgsig'] as $field) {
            if (array_key_exists($field, $item)) {
                $value = (string)$item[$field];
                $item["has_{$field}"] = trim($value) !== '';
                $item["{$field}_preview"] = $this->maskSecretValue($value);
                unset($item[$field]);
            }
        }

        return $item;
    }

    private function maskSecretValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $length = strlen($value);
        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($value, 0, 4) . '...' . substr($value, -4);
    }

    private function getConfigList(string $key): array
    {
        $raw = SystemConfig::getValue($key, '[]');
        $data = json_decode((string)$raw, true);
        return is_array($data) ? $data : [];
    }

    private function getStoredCtripConfigList(): array
    {
        try {
            $raw = Db::name('system_configs')->where('config_key', 'ctrip_config_list')->value('config_value');
            $list = $raw ? json_decode((string)$raw, true) : [];
            if (!is_array($list)) {
                return [];
            }
            $list = $this->normalizeStoredOtaConfigList('system_configs', 'ctrip_config_list', $list, 'ctrip');
            return array_values($list);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function getStoredMeituanConfigList(): array
    {
        try {
            $list = $this->getConfigList('meituan_config_list');
            if (!is_array($list)) {
                return [];
            }
            $list = $this->normalizeStoredOtaConfigList('system_config', 'meituan_config_list', $list, 'meituan');
            return array_values($list);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function filterOtaConfigListForCurrentUser(array $list): array
    {
        if (!$this->currentUser || !$this->currentUser->id) {
            return [];
        }

        if (method_exists($this->currentUser, 'isSuperAdmin') && $this->currentUser->isSuperAdmin()) {
            return array_values($list);
        }

        $permittedHotelIdSet = $this->getCurrentUserPermittedHotelIdSet();
        $visibleList = [];

        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }

            if ($this->isOtaConfigVisibleToCurrentUser($item, $permittedHotelIdSet)) {
                $visibleList[] = $item;
            }
        }

        return $visibleList;
    }

    private function isOtaConfigVisibleToCurrentUser(array $item, ?array $permittedHotelIdSet = null): bool
    {
        if (!$this->currentUser || !$this->currentUser->id) {
            return false;
        }

        if (method_exists($this->currentUser, 'isSuperAdmin') && $this->currentUser->isSuperAdmin()) {
            return true;
        }

        $itemUserId = $item['user_id'] ?? null;
        if ($itemUserId !== null && $itemUserId !== '' && (string)$itemUserId === (string)$this->currentUser->id) {
            return true;
        }

        $permittedHotelIdSet = $permittedHotelIdSet ?? $this->getCurrentUserPermittedHotelIdSet();
        $systemHotelId = trim((string)($item['system_hotel_id'] ?? ''));
        if ($systemHotelId !== '' && isset($permittedHotelIdSet[$systemHotelId])) {
            return true;
        }

        return false;
    }

    private function getCurrentUserPermittedHotelIdSet(): array
    {
        if (!$this->currentUser || !method_exists($this->currentUser, 'getPermittedHotelIds')) {
            return [];
        }

        $hotelIds = array_map('strval', $this->currentUser->getPermittedHotelIds());
        return array_fill_keys($hotelIds, true);
    }

    private function resolveCtripFetchConfigForHotel(int $hotelId): array
    {
        foreach ($this->getStoredCtripConfigList() as $config) {
            $configHotelId = (string)($config['hotel_id'] ?? $config['system_hotel_id'] ?? '');
            if ($configHotelId !== '' && (string)$hotelId === $configHotelId) {
                return $config;
            }
        }

        $cookiesList = $this->getConfigList("online_data_cookies_hotel_{$hotelId}");

        foreach ($cookiesList as $item) {
            if (!empty($item['cookies'])) {
                return [
                    'cookies' => $item['cookies'],
                    'url' => 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportCompeteHotelReport',
                    'node_id' => '24588',
                ];
            }
        }

        return [];
    }

    private function resolveMeituanFetchConfigForHotel(int $hotelId): array
    {
        foreach ($this->getStoredMeituanConfigList() as $config) {
            $configHotelId = (string)($config['hotel_id'] ?? $config['system_hotel_id'] ?? '');
            if ($configHotelId !== '' && (string)$hotelId === $configHotelId) {
                return $config;
            }
        }

        return [];
    }

    private function ctripProfileStoreIdFromConfig(array $config, int $hotelId = 0): string
    {
        foreach (['profile_id', 'profileId'] as $key) {
            $value = trim((string)($config[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        foreach ([(string)$hotelId, (string)($config['system_hotel_id'] ?? ''), (string)($config['hotel_id'] ?? '')] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && $this->ctripProfileDirExists($candidate)) {
                return $candidate;
            }
        }

        foreach (['ota_hotel_id', 'ctrip_hotel_id', 'ctripHotelId', 'hotel_code', 'hotelCode', 'node_id', 'hotel_id', 'system_hotel_id'] as $key) {
            $value = trim((string)($config[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return $hotelId > 0 ? (string)$hotelId : '';
    }

    private function ctripProfileExistsForConfig(array $config, int $hotelId = 0): bool
    {
        $profileId = $this->ctripProfileStoreIdFromConfig($config, $hotelId);
        if ($profileId === '') {
            return false;
        }

        return $this->ctripProfileDirExists($profileId);
    }

    private function ctripProfileDirExists(string $profileId): bool
    {
        $profileId = trim($profileId);
        if ($profileId === '') {
            return false;
        }

        $projectRoot = dirname(__DIR__, 2);
        $profileDir = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'ctrip_profile_' . $this->safeMeituanCaptureFilePart($profileId);
        return is_dir($profileDir);
    }

    private function meituanProfileStoreIdFromConfig(array $config): string
    {
        return trim((string)($config['store_id'] ?? $config['storeId'] ?? $config['poi_id'] ?? $config['poiId'] ?? ''));
    }

    private function meituanProfileExistsForConfig(array $config): bool
    {
        $storeId = $this->meituanProfileStoreIdFromConfig($config);
        if ($storeId === '') {
            return false;
        }

        $projectRoot = dirname(__DIR__, 2);
        $profileDir = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'meituan_profile_' . $this->safeMeituanCaptureFilePart($storeId);
        return is_dir($profileDir);
    }

    private function ctripLatestFetchStatusKey(?int $hotelId): string
    {
        return $hotelId ? "online_data_ctrip_latest_fetch_{$hotelId}" : 'online_data_ctrip_latest_fetch';
    }

    private function updateCtripLatestFetchStatus(?int $hotelId, string $fetchedAt, string $dataDate, int $savedCount): void
    {
        cache($this->ctripLatestFetchStatusKey($hotelId), [
            'fetched_at' => $fetchedAt,
            'data_date' => $dataDate,
            'saved_count' => $savedCount,
        ], 86400 * 30);
    }

    private function getCtripLatestFetchStatus(string $hotelId): array
    {
        $statusKeyHotelId = is_numeric($hotelId) && (int)$hotelId > 0 ? (int)$hotelId : null;
        $status = cache($this->ctripLatestFetchStatusKey($statusKeyHotelId)) ?: [];
        return is_array($status) ? $status : [];
    }


    private function getHotelsForOtaConfigMatching(): array
    {
        try {
            $rows = Db::name('hotels')
                ->field('id,name,code,status')
                ->order('status', 'desc')
                ->order('id', 'asc')
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }

        $rows = array_values(array_filter($rows, static function ($row): bool {
            return is_array($row) && trim((string)($row['name'] ?? '')) !== '';
        }));

        usort($rows, static function (array $a, array $b): int {
            $statusCompare = (int)($b['status'] ?? 0) <=> (int)($a['status'] ?? 0);
            if ($statusCompare !== 0) {
                return $statusCompare;
            }
            return mb_strlen((string)($b['name'] ?? '')) <=> mb_strlen((string)($a['name'] ?? ''));
        });

        return $rows;
    }

    private function normalizeOtaConfigMatchText(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/(携程|美团|ebooking|e-booking|ebk|数据源|配置|主账号|账号|cookie|cookies)/iu', '', $value) ?? $value;
        $value = preg_replace('/[^\p{Han}a-z0-9]+/iu', '', $value) ?? $value;

        return mb_strtolower($value, 'UTF-8');
    }

    private function findOtaConfigHotelMatch(array $config, array $hotels): ?array
    {
        $currentHotelId = trim((string)($config['hotel_id'] ?? $config['system_hotel_id'] ?? ''));
        if ($currentHotelId !== '') {
            foreach ($hotels as $hotel) {
                if ((string)($hotel['id'] ?? '') === $currentHotelId) {
                    return $hotel;
                }
            }
        }

        $sourceParts = [
            $config['hotel_name'] ?? '',
            $config['name'] ?? '',
            $config['config_name'] ?? '',
            $config['remark'] ?? '',
        ];
        $source = trim(implode(' ', array_filter(array_map(static fn($part): string => trim((string)$part), $sourceParts))));
        if ($source === '') {
            return null;
        }

        foreach ($hotels as $hotel) {
            $hotelName = trim((string)($hotel['name'] ?? ''));
            if ($hotelName !== '' && mb_strpos($source, $hotelName, 0, 'UTF-8') !== false) {
                return $hotel;
            }
        }

        $normalizedSource = $this->normalizeOtaConfigMatchText($source);
        if ($normalizedSource === '') {
            return null;
        }

        foreach ($hotels as $hotel) {
            $hotelName = $this->normalizeOtaConfigMatchText((string)($hotel['name'] ?? ''));
            $hotelCode = $this->normalizeOtaConfigMatchText((string)($hotel['code'] ?? ''));
            if ($hotelName !== '' && mb_strpos($normalizedSource, $hotelName, 0, 'UTF-8') !== false) {
                return $hotel;
            }
            if ($hotelCode !== '' && mb_strpos($normalizedSource, $hotelCode, 0, 'UTF-8') !== false) {
                return $hotel;
            }
        }

        return null;
    }

    private function normalizeOtaConfigHotelBinding(array $config, string $platform, ?array $hotels = null): array
    {
        $hotels = $hotels ?? $this->getHotelsForOtaConfigMatching();
        $match = $this->findOtaConfigHotelMatch($config, $hotels);

        if (!$match) {
            $config['hotel_id'] = $config['hotel_id'] ?? '';
            $config['hotel_name'] = $config['hotel_name'] ?? '';
            return $config;
        }

        $hotelId = (string)($match['id'] ?? '');
        if ($hotelId === '') {
            return $config;
        }

        $config['hotel_id'] = $hotelId;
        $config['system_hotel_id'] = $hotelId;
        $config['hotel_name'] = (string)($match['name'] ?? $config['hotel_name'] ?? '');
        $config['platform'] = $config['platform'] ?? $platform;

        return $config;
    }

    private function normalizeStoredOtaConfigList(string $table, string $key, array $list, string $platform): array
    {
        if (empty($list)) {
            return $list;
        }

        $hotels = $this->getHotelsForOtaConfigMatching();
        if (empty($hotels)) {
            return $list;
        }

        $changed = false;
        $normalizedList = [];

        foreach ($list as $index => $item) {
            if (!is_array($item)) {
                $normalizedList[$index] = $item;
                continue;
            }

            $normalized = $this->normalizeOtaConfigHotelBinding($item, $platform, $hotels);
            if ($normalized != $item) {
                $changed = true;
            }
            $normalizedList[$index] = $normalized;
        }

        if ($changed) {
            Db::name($table)->where('config_key', $key)->update([
                'config_value' => json_encode($normalizedList, JSON_UNESCAPED_UNICODE),
                'update_time' => date('Y-m-d H:i:s'),
            ]);
        }

        return $normalizedList;
    }

    /**
     * 保存列表到系统配置
     */
    private function setConfigList(string $key, array $value): void
    {
        SystemConfig::setValue($key, json_encode($value, JSON_UNESCAPED_UNICODE), '在线数据Cookies配置');
    }

    public function cookieStatus(): Response
    {
        $this->checkPermission();
        return $this->success([
            'list' => $this->buildCookieStatusRows(),
            'alerts' => $this->getCookieAlerts(),
            'warning_days' => $this->cookieWarningDays(),
            'expire_days' => $this->cookieExpireDays(),
            'reauthorize_entry' => $this->cookieReauthorizeEntry(),
        ]);
    }

    public function collectionReliability(): Response
    {
        $this->checkPermission();

        $hotelIdRaw = $this->request->get('hotel_id', $this->request->get('system_hotel_id', ''));
        $hotelId = $this->resolveOnlineDataSystemHotelId($hotelIdRaw);
        $days = max(1, min(90, (int)$this->request->get('days', 30)));
        $endDate = trim((string)$this->request->get('end_date', date('Y-m-d')));
        $startDate = trim((string)$this->request->get('start_date', date('Y-m-d', strtotime($endDate . ' -' . ($days - 1) . ' days'))));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            return $this->error('日期格式错误，请使用 YYYY-MM-DD');
        }
        if (strtotime($startDate) === false || strtotime($endDate) === false || $startDate > $endDate) {
            return $this->error('日期范围无效');
        }
        $periodDays = (int)floor((strtotime($endDate) - strtotime($startDate)) / 86400) + 1;

        try {
            $authorizationRows = $this->filterCollectionAuthorizationRows($this->buildCookieStatusRows(), $hotelId);
            $alerts = $this->filterCollectionAlertsByHotel($this->getCookieAlerts(), $hotelId);
            $collectionLogs = $this->buildCollectionLogRows($hotelId, $startDate, $endDate, 30);
            $qualityRows = $this->loadCollectionQualityRows($hotelId, $startDate, $endDate, 2000);

            return $this->success([
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'days' => $periodDays,
                ],
                'hotel_id' => $hotelId,
                'authorization' => [
                    'summary' => $this->buildCollectionAuthorizationSummary($authorizationRows),
                    'list' => $authorizationRows,
                    'reauthorize_entry' => $this->cookieReauthorizeEntry(),
                ],
                'failure_reasons' => $this->buildCollectionFailureReasons($alerts, $collectionLogs, 20),
                'field_definitions' => $this->buildOtaCollectionFieldDefinitions(),
                'collection_logs' => $collectionLogs,
                'history_replay' => $this->buildCollectionHistoryReplayRows($hotelId, $startDate, $endDate, 30),
                'data_quality' => $this->buildCollectionQualitySnapshot($qualityRows),
            ]);
        } catch (\Throwable $e) {
            return $this->error('采集可靠性查询失败: ' . $e->getMessage());
        }
    }

    private function buildCookieStatusRows(): array
    {
        $rows = [];
        $hotelIds = $this->visibleCookieHotelIds();

        foreach ($this->getConfigList('online_data_cookies_global') as $item) {
            $rows[] = $this->buildCookieHealth('generic', 'global', null, $item);
        }

        foreach ($hotelIds as $hotelId) {
            foreach ($this->getConfigList("online_data_cookies_hotel_{$hotelId}") as $item) {
                $rows[] = $this->buildCookieHealth('generic', 'hotel', (int)$hotelId, $item);
            }
        }

        foreach ($this->getStoredCtripConfigList() as $item) {
            $itemHotelId = (int)($item['hotel_id'] ?? $item['system_hotel_id'] ?? 0);
            if ($this->canSeeCookieHotel($itemHotelId)) {
                $rows[] = $this->buildCookieHealth('ctrip', $itemHotelId > 0 ? 'hotel' : 'global', $itemHotelId ?: null, $item);
            }
        }

        foreach ($this->getConfigList('meituan_config_list') as $item) {
            $itemHotelId = (int)($item['hotel_id'] ?? $item['system_hotel_id'] ?? 0);
            if ($this->canSeeCookieHotel($itemHotelId)) {
                $rows[] = $this->buildCookieHealth('meituan', $itemHotelId > 0 ? 'hotel' : 'global', $itemHotelId ?: null, $item);
            }
        }

        usort($rows, static fn(array $a, array $b): int => strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? '')));
        return $rows;
    }

    private function buildCookieHealth(string $platform, string $scope, ?int $hotelId, array $item): array
    {
        $name = (string)($item['name'] ?? $item['hotel_name'] ?? $item['config_name'] ?? $platform);
        $cookieValue = (string)($item['cookies'] ?? $item['cookie'] ?? '');
        $updatedAt = (string)($item['update_time'] ?? $item['updated_at'] ?? $item['created_at'] ?? '');
        $timestamp = $updatedAt !== '' ? strtotime($updatedAt) : false;
        $ageDays = $timestamp ? max(0, (int)floor((time() - $timestamp) / 86400)) : null;
        $hasAlert = false;
        $alertMessage = '';
        foreach ($this->getCookieAlerts() as $alert) {
            if (($alert['platform'] ?? '') === $platform && (string)($alert['name'] ?? '') === $name) {
                $hasAlert = true;
                $alertMessage = (string)($alert['message'] ?? '');
                break;
            }
        }
        $status = $this->resolveCookieHealthState($cookieValue, $ageDays, $hasAlert, $this->cookieWarningDays(), $this->cookieExpireDays());
        $reason = $status;
        if ($cookieValue === '') {
            $reason = 'empty';
        } elseif ($ageDays === null && !$hasAlert) {
            $reason = 'unknown';
        }
        $message = $hasAlert && $alertMessage !== ''
            ? $alertMessage
            : $this->cookieHealthMessage($platform, $reason, $ageDays);

        return [
            'platform' => $platform,
            'scope' => $scope,
            'hotel_id' => $hotelId,
            'name' => $name,
            'status' => $status,
            'message' => $message,
            'next_action' => $status === 'ok' ? '' : '重新登录OTA后台并通过书签脚本或配置页更新授权',
            'updated_at' => $updatedAt,
            'age_days' => $ageDays,
            'has_cookie' => $cookieValue !== '',
            'reauthorize_entry' => $this->cookieReauthorizeEntry(),
        ];
    }

    private function resolveCookieHealthState(string $cookieValue, ?int $ageDays, bool $hasAlert, int $warningDays, int $expireDays): string
    {
        if ($hasAlert || $cookieValue === '') {
            return 'expired';
        }
        if ($ageDays === null) {
            return 'unknown';
        }
        if ($ageDays >= $expireDays) {
            return 'expired';
        }
        if ($ageDays >= $warningDays) {
            return 'warning';
        }
        return 'ok';
    }

    private function cookieHealthMessage(string $platform, string $reason, ?int $ageDays): string
    {
        $label = $this->otaPlatformLabel($platform);
        return match ($reason) {
            'empty' => $label . ' Cookie为空，请重新登录OTA后台后更新授权。',
            'unknown' => $label . ' Cookie缺少更新时间，请重新保存一次配置以便系统判断有效期。',
            'expired' => $label . ' Cookie已超过' . $this->cookieExpireDays() . '天有效期阈值，请重新授权。',
            'warning' => $label . ' Cookie已使用' . (string)$ageDays . '天，接近' . $this->cookieExpireDays() . '天过期阈值，建议提前更新。',
            default => $label . ' Cookie状态正常。',
        };
    }

    private function cookieReauthorizeEntry(): string
    {
        return '/online-data?tab=cookies';
    }

    private function otaPlatformLabel(string $platform): string
    {
        return match (strtolower($platform)) {
            'ctrip' => '携程',
            'qunar' => '去哪儿',
            'meituan' => '美团',
            default => 'OTA',
        };
    }

    private function visibleCookieHotelIds(): array
    {
        if (!$this->currentUser || !$this->currentUser->isSuperAdmin()) {
            $hotelId = (int)($this->currentUser->hotel_id ?? 0);
            return $hotelId > 0 ? [$hotelId] : [];
        }

        try {
            return array_map('intval', \app\model\Hotel::column('id'));
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function canSeeCookieHotel(int $hotelId): bool
    {
        if ($hotelId <= 0 || !$this->currentUser || $this->currentUser->isSuperAdmin()) {
            return true;
        }
        return (int)($this->currentUser->hotel_id ?? 0) === $hotelId;
    }

    private function cookieWarningDays(): int
    {
        return max(1, (int)SystemConfig::getValue('ota_cookie_warning_days', '5'));
    }

    private function cookieExpireDays(): int
    {
        return max($this->cookieWarningDays(), (int)SystemConfig::getValue('ota_cookie_expire_days', '14'));
    }

    private function isCookieAuthError(string $message): bool
    {
        return preg_match('/cookie|login|auth|unauthorized|forbidden|expired|302|401|403|html|登录|授权|过期|失效|权限/i', $message) === 1;
    }

    private function recordCookieAlert(string $platform, string $name, string $message, ?int $hotelId = null): void
    {
        if (!$this->isCookieAuthError($message)) {
            return;
        }

        $alerts = $this->getCookieAlerts();
        $key = md5($platform . '|' . $name . '|' . (string)$hotelId);
        $alerts[$key] = [
            'platform' => $platform,
            'name' => $name,
            'hotel_id' => $hotelId,
            'message' => mb_substr($message, 0, 240),
            'created_at' => date('Y-m-d H:i:s'),
            'next_action' => '重新登录' . $this->otaPlatformLabel($platform) . '后台，复制最新Cookie或重新运行书签脚本。',
            'reauthorize_entry' => $this->cookieReauthorizeEntry(),
        ];
        $alerts = array_slice($alerts, -50, null, true);
        SystemConfig::setValue('ota_cookie_alerts', json_encode($alerts, JSON_UNESCAPED_UNICODE), 'OTA Cookie alerts');

        try {
            OperationLog::record('online_data', 'cookie_expired', 'OTA cookie needs reauthorization: ' . $platform . '/' . $name, $this->currentUser->id ?? null, $hotelId, $message);
        } catch (\Throwable $e) {
            // Alert storage must not block OTA fetching.
        }
    }

    private function getCookieAlerts(): array
    {
        $raw = SystemConfig::getValue('ota_cookie_alerts', '{}');
        $data = json_decode((string)$raw, true);
        return is_array($data) ? $data : [];
    }

    private function filterCollectionAuthorizationRows(array $rows, ?int $hotelId): array
    {
        if ($hotelId === null) {
            return array_values($rows);
        }

        return array_values(array_filter($rows, static function (array $row) use ($hotelId): bool {
            $rowHotelId = (int)($row['hotel_id'] ?? 0);
            return $rowHotelId === 0 || $rowHotelId === $hotelId;
        }));
    }

    private function buildCollectionAuthorizationSummary(array $rows): array
    {
        $counts = [
            'ok' => 0,
            'warning' => 0,
            'expired' => 0,
            'unknown' => 0,
        ];

        foreach ($rows as $row) {
            $status = (string)($row['status'] ?? 'unknown');
            if (!array_key_exists($status, $counts)) {
                $status = 'unknown';
            }
            $counts[$status]++;
        }

        $overall = 'empty';
        if ($counts['expired'] > 0) {
            $overall = 'risk';
        } elseif ($counts['warning'] > 0 || $counts['unknown'] > 0) {
            $overall = 'warning';
        } elseif ($counts['ok'] > 0) {
            $overall = 'ok';
        }

        return [
            'overall_status' => $overall,
            'total' => count($rows),
            'ok' => $counts['ok'],
            'warning' => $counts['warning'],
            'expired' => $counts['expired'],
            'unknown' => $counts['unknown'],
        ];
    }

    private function filterCollectionAlertsByHotel(array $alerts, ?int $hotelId): array
    {
        return array_values(array_filter($alerts, function (array $alert) use ($hotelId): bool {
            $alertHotelId = (int)($alert['hotel_id'] ?? 0);
            if ($hotelId !== null) {
                return $alertHotelId === 0 || $alertHotelId === $hotelId;
            }
            return $alertHotelId === 0 || $this->canSeeCookieHotel($alertHotelId);
        }));
    }

    private function buildCollectionLogRows(?int $hotelId, string $startDate, string $endDate, int $limit): array
    {
        $hotelIds = $hotelId !== null ? [$hotelId] : $this->resolveAutoFetchRecordHotelIds('');
        if (empty($hotelIds)) {
            $hotelIds = $this->visibleCookieHotelIds();
        }

        $hotelMap = $this->getAutoFetchRecordHotelMap($hotelIds);
        $filters = [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];
        $rows = [];
        foreach ($hotelIds as $id) {
            $status = cache($this->autoFetchStatusKey((int)$id));
            if (!is_array($status)) {
                continue;
            }
            $rows = array_merge($rows, $this->buildAutoFetchRecordRows($status, (int)$id, (string)($hotelMap[(int)$id] ?? ('Hotel ID ' . $id)), $filters));
        }

        usort($rows, static fn(array $a, array $b): int => strcmp((string)($b['run_time'] ?? ''), (string)($a['run_time'] ?? '')));
        return array_slice($rows, 0, max(1, $limit));
    }

    private function buildCollectionFailureReasons(array $alerts, array $collectionLogs, int $limit): array
    {
        $rows = [];
        foreach ($alerts as $alert) {
            $rows[] = [
                'type' => 'authorization',
                'platform' => (string)($alert['platform'] ?? ''),
                'hotel_id' => $alert['hotel_id'] ?? null,
                'occurred_at' => (string)($alert['created_at'] ?? ''),
                'reason' => (string)($alert['message'] ?? ''),
                'next_action' => (string)($alert['next_action'] ?? ''),
                'source_ref' => 'SystemConfig.ota_cookie_alerts',
            ];
        }

        foreach ($collectionLogs as $log) {
            if (($log['status'] ?? '') !== 'failed') {
                continue;
            }
            $rows[] = [
                'type' => 'collection',
                'platform' => (string)($log['platform'] ?? ''),
                'hotel_id' => $log['hotel_id'] ?? null,
                'occurred_at' => (string)($log['run_time'] ?? ''),
                'data_date' => (string)($log['data_date'] ?? ''),
                'reason' => (string)($log['message'] ?? ''),
                'next_action' => '检查授权、字段结构和平台接口返回后重试采集',
                'source_ref' => 'cache.online_data_auto_fetch_status',
                'record_id' => (string)($log['id'] ?? ''),
            ];
        }

        usort($rows, static fn(array $a, array $b): int => strcmp((string)($b['occurred_at'] ?? ''), (string)($a['occurred_at'] ?? '')));
        return array_slice($rows, 0, max(1, $limit));
    }

    private function buildOtaCollectionFieldDefinitions(): array
    {
        return [
            [
                'source' => 'ctrip',
                'module' => 'traffic',
                'storage_table' => 'online_daily_data',
                'fields' => [
                    ['field' => 'list_exposure', 'label' => '列表页曝光量', 'source_fields' => ['myHotel.totalListExposure', 'totalListExposure', 'listExposure'], 'calculation' => '平台原始值直接入库', 'required' => true],
                    ['field' => 'detail_exposure', 'label' => '详情页访客量', 'source_fields' => ['myHotel.totalDetailExposure', 'totalDetailExposure', 'detailExposure'], 'calculation' => '平台原始值直接入库', 'required' => true],
                    ['field' => 'flow_rate', 'label' => '曝光转化率', 'source_fields' => ['listTransforDetailRate', 'flowRate'], 'calculation' => '详情页访客量 / 列表页曝光量 * 100', 'required' => false],
                    ['field' => 'order_filling_num', 'label' => '订单页访客量', 'source_fields' => ['orderFillingNum'], 'calculation' => '平台原始值直接入库', 'required' => false],
                    ['field' => 'order_submit_num', 'label' => '订单提交人数', 'source_fields' => ['orderSubmitNum'], 'calculation' => '平台原始值直接入库', 'required' => false],
                ],
            ],
            [
                'source' => 'ctrip',
                'module' => 'business',
                'storage_table' => 'online_daily_data',
                'fields' => [
                    ['field' => 'amount', 'label' => '营业额', 'source_fields' => ['amount', 'totalAmount', 'saleAmount'], 'calculation' => '平台原始值直接入库', 'required' => true],
                    ['field' => 'quantity', 'label' => '间夜量', 'source_fields' => ['quantity', 'roomNights', 'checkOutQuantity'], 'calculation' => '平台原始值直接入库', 'required' => true],
                    ['field' => 'book_order_num', 'label' => '订单数', 'source_fields' => ['bookOrderNum', 'orderCount', 'orders'], 'calculation' => '平台原始值直接入库', 'required' => true],
                    ['field' => 'comment_score', 'label' => '点评分', 'source_fields' => ['commentScore', 'score'], 'calculation' => '平台原始值直接入库', 'required' => false],
                ],
            ],
            [
                'source' => 'meituan',
                'module' => 'business',
                'storage_table' => 'online_daily_data',
                'fields' => [
                    ['field' => 'data_value', 'label' => '榜单指标值', 'source_fields' => ['dataValue', 'monthRoomNights'], 'calculation' => '按美团榜单维度保存原始指标值', 'required' => true],
                    ['field' => 'dimension', 'label' => '榜单维度', 'source_fields' => ['dimension', 'dimName', '_dimName'], 'calculation' => '平台维度名称直接入库', 'required' => true],
                    ['field' => 'amount', 'label' => '营业额', 'source_fields' => ['amount', 'saleAmount'], 'calculation' => '如接口返回则直接入库', 'required' => false],
                    ['field' => 'quantity', 'label' => '间夜量', 'source_fields' => ['quantity', 'roomNights'], 'calculation' => '如接口返回则直接入库', 'required' => false],
                ],
            ],
            [
                'source' => 'meituan',
                'module' => 'traffic',
                'storage_table' => 'online_daily_data',
                'fields' => [
                    ['field' => 'list_exposure', 'label' => '列表页曝光量', 'source_fields' => ['self_list_exposure', 'totalListExposure', 'listExposure'], 'calculation' => '平台原始值直接入库', 'required' => true],
                    ['field' => 'detail_exposure', 'label' => '详情页访客量', 'source_fields' => ['self_detail_exposure', 'totalDetailExposure', 'detailExposure'], 'calculation' => '平台原始值直接入库', 'required' => true],
                    ['field' => 'flow_rate', 'label' => '曝光转化率', 'source_fields' => ['flowRate'], 'calculation' => '详情页访客量 / 列表页曝光量 * 100', 'required' => false],
                    ['field' => 'order_filling_num', 'label' => '订单页访客量', 'source_fields' => ['self_order_filling_num', 'orderFillingNum'], 'calculation' => '平台原始值直接入库', 'required' => false],
                    ['field' => 'order_submit_num', 'label' => '订单提交人数', 'source_fields' => ['self_order_submit_num', 'orderSubmitNum'], 'calculation' => '平台原始值直接入库', 'required' => false],
                ],
            ],
        ];
    }

    private function loadCollectionQualityRows(?int $hotelId, string $startDate, string $endDate, int $limit): array
    {
        $columns = $this->getOnlineDailyDataColumns();
        $fields = array_values(array_filter([
            'id', 'system_hotel_id', 'hotel_id', 'hotel_name', 'source', 'data_type', 'data_date',
            'amount', 'quantity', 'book_order_num', 'comment_score', 'qunar_comment_score', 'data_value',
            'dimension', 'list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num',
            'raw_data', 'validation_status', 'validation_flags', 'create_time', 'update_time',
        ], static fn(string $field): bool => isset($columns[$field])));

        if (empty($fields)) {
            return [];
        }

        $query = Db::name('online_daily_data')
            ->field($fields)
            ->where('data_date', '>=', $startDate)
            ->where('data_date', '<=', $endDate);

        if (!$this->applyCollectionHotelScope($query, $hotelId, $columns)) {
            return [];
        }

        return $query
            ->order('data_date', 'desc')
            ->order('id', 'desc')
            ->limit(max(1, $limit))
            ->select()
            ->toArray();
    }

    private function buildCollectionHistoryReplayRows(?int $hotelId, string $startDate, string $endDate, int $limit): array
    {
        $rows = $this->loadCollectionQualityRows($hotelId, $startDate, $endDate, $limit);
        $result = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            $quality = $this->buildOnlineDataQuality($row);
            $result[] = [
                'id' => $id,
                'source_ref' => 'online_daily_data#' . $id,
                'replay_api' => $id > 0 ? '/api/online-data/history/' . $id : '',
                'data_date' => (string)($row['data_date'] ?? ''),
                'source' => (string)($row['source'] ?? ''),
                'data_type' => (string)($row['data_type'] ?? ''),
                'system_hotel_id' => $row['system_hotel_id'] ?? null,
                'hotel_id' => $row['hotel_id'] ?? null,
                'hotel_name' => (string)($row['hotel_name'] ?? ''),
                'quality_status' => (string)($quality['status'] ?? ''),
                'quality_score' => (int)($quality['score'] ?? 0),
                'metric_preview' => $this->buildCollectionMetricPreview($row),
                'raw_data_available' => trim((string)($row['raw_data'] ?? '')) !== '',
                'updated_at' => (string)($row['update_time'] ?? $row['create_time'] ?? ''),
            ];
        }
        return $result;
    }

    private function applyCollectionHotelScope($query, ?int $hotelId, array $columns): bool
    {
        if ($hotelId !== null) {
            if (isset($columns['system_hotel_id'])) {
                $query->where('system_hotel_id', $hotelId);
            } elseif (isset($columns['hotel_id'])) {
                $query->where('hotel_id', (string)$hotelId);
            }
        }

        if ($this->currentUser && !$this->currentUser->isSuperAdmin()) {
            if (!isset($columns['system_hotel_id'])) {
                return false;
            }
            $permittedHotelIds = array_values(array_map('intval', $this->currentUser->getPermittedHotelIds()));
            if (empty($permittedHotelIds)) {
                return false;
            }
            $query->whereIn('system_hotel_id', $permittedHotelIds);
        }

        return true;
    }

    private function buildCollectionQualitySnapshot(array $rows): array
    {
        $summary = $this->buildOnlineDataQualitySummary($rows);
        $checkedRecords = (int)($summary['checked_records'] ?? 0);
        if ($checkedRecords === 0) {
            return array_merge($summary, [
                'status' => 'no_data',
                'score' => 0,
                'grade' => 'no_data',
                'coverage_days' => 0,
                'source_breakdown' => [],
                'scoring_rule' => 'Average of per-record online_daily_data quality scores in the selected period.',
            ]);
        }

        $scoreTotal = 0;
        $dates = [];
        $sourceBreakdown = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $quality = $this->buildOnlineDataQuality($row);
            $scoreTotal += (int)($quality['score'] ?? 0);
            $date = (string)($row['data_date'] ?? '');
            if ($date !== '') {
                $dates[$date] = true;
            }
            $source = (string)($row['source'] ?? 'unknown');
            $type = (string)($row['data_type'] ?? 'business');
            $key = $source . ':' . $type;
            if (!isset($sourceBreakdown[$key])) {
                $sourceBreakdown[$key] = [
                    'source' => $source,
                    'data_type' => $type,
                    'records' => 0,
                    'issue_records' => 0,
                ];
            }
            $sourceBreakdown[$key]['records']++;
            if (($quality['status'] ?? 'ok') !== 'ok') {
                $sourceBreakdown[$key]['issue_records']++;
            }
        }

        $score = round($scoreTotal / $checkedRecords, 1);
        $status = (string)($summary['status'] ?? 'ok');
        if ($score < 60 && $status !== 'error') {
            $status = 'error';
        } elseif ($score < 85 && $status === 'ok') {
            $status = 'warning';
        }

        return array_merge($summary, [
            'status' => $status,
            'score' => $score,
            'grade' => $score >= 90 ? 'A' : ($score >= 75 ? 'B' : ($score >= 60 ? 'C' : 'D')),
            'coverage_days' => count($dates),
            'source_breakdown' => array_values($sourceBreakdown),
            'scoring_rule' => 'Average of per-record online_daily_data quality scores in the selected period.',
        ]);
    }

    private function buildCollectionMetricPreview(array $row): array
    {
        $preview = [];
        foreach ([
            'amount', 'quantity', 'book_order_num', 'data_value', 'dimension',
            'list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num',
            'comment_score', 'qunar_comment_score',
        ] as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null && $row[$field] !== '') {
                $preview[$field] = $row[$field];
            }
        }
        return $preview;
    }


    /**
     * 更新线上数据
     */
    public function updateData(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $id = intval($this->request->param('id', 0));
        if ($id <= 0) {
            return $this->error('无效的数据ID');
        }

        // 查询数据
        $data = Db::name('online_daily_data')->where('id', $id)->find();
        if (!$data) {
            return $this->error('数据不存在');
        }

        // 权限检查：非超级管理员只能修改自己酒店的数据
        if (!$this->currentUser->isSuperAdmin()) {
            $permittedHotelIds = $this->currentUser->getPermittedHotelIds();
            if (!in_array($data['system_hotel_id'], $permittedHotelIds)) {
                return $this->error('无权修改该数据');
            }
        }

        // 获取更新字段
        $updateData = [];
        $fields = ['amount', 'quantity', 'book_order_num', 'comment_score', 'qunar_comment_score'];
        foreach ($fields as $field) {
            if ($this->request->has($field)) {
                $updateData[$field] = $this->request->post($field);
            }
        }

        if (empty($updateData)) {
            return $this->error('没有要更新的数据');
        }

        $updateData['update_time'] = date('Y-m-d H:i:s');

        try {
            Db::name('online_daily_data')->where('id', $id)->update($updateData);
            OperationLog::record('online_data', 'update', '更新线上数据ID: ' . $id, $this->currentUser->id, $data['system_hotel_id']);
            return $this->success(['id' => $id], '更新成功');
        } catch (\Throwable $e) {
            return $this->error('更新失败: ' . $e->getMessage());
        }
    }

    /**
     * 删除线上数据
     */
    public function deleteData(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_delete_online_data');

        $id = intval($this->request->param('id', 0));
        if ($id <= 0) {
            return $this->error('无效的数据ID');
        }

        // 查询数据
        $data = Db::name('online_daily_data')->where('id', $id)->find();
        if (!$data) {
            return $this->error('数据不存在');
        }

        // 权限检查：非超级管理员只能删除自己酒店的数据
        if (!$this->currentUser->isSuperAdmin()) {
            $permittedHotelIds = $this->currentUser->getPermittedHotelIds();
            if (!in_array($data['system_hotel_id'], $permittedHotelIds)) {
                return $this->error('无权删除该数据');
            }
        }

        try {
            Db::name('online_daily_data')->where('id', $id)->delete();
            OperationLog::record('online_data', 'delete', '删除线上数据ID: ' . $id, $this->currentUser->id, $data['system_hotel_id']);
            return $this->success(['id' => $id], '删除成功');
        } catch (\Throwable $e) {
            return $this->error('删除失败: ' . $e->getMessage());
        }
    }

    /**
     * 批量删除线上数据
     */
    public function batchDelete(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_delete_online_data');

        $ids = $this->request->post('ids', []);
        if (empty($ids) || !is_array($ids)) {
            return $this->error('请选择要删除的数据');
        }

        $ids = array_map('intval', $ids);
        $ids = array_filter($ids, fn($id) => $id > 0);

        if (empty($ids)) {
            return $this->error('无效的数据ID');
        }

        // 权限检查
        $query = Db::name('online_daily_data')->whereIn('id', $ids);
        if (!$this->currentUser->isSuperAdmin()) {
            $permittedHotelIds = $this->currentUser->getPermittedHotelIds();
            $query->whereIn('system_hotel_id', $permittedHotelIds);
        }

        try {
            $deletedCount = $query->delete();
            OperationLog::record('online_data', 'batch_delete', '批量删除线上数据: ' . $deletedCount . '条', $this->currentUser->id);
            return $this->success(['deleted_count' => $deletedCount], '删除成功');
        } catch (\Throwable $e) {
            return $this->error('删除失败: ' . $e->getMessage());
        }
    }

    /**
     * AI智能分析
     * 基于携程/美团数据进行智能分析，提供经营建议
     */
    public function aiAnalysis(): Response
    {
        $this->checkPermission();

        $hotels = $this->request->post('hotels', []);
        $analysisType = $this->request->post('analysis_type', 'business_overview');
        $includeSuggestions = $this->request->post('include_suggestions', true);

        if (empty($hotels) || !is_array($hotels)) {
            return $this->error('请提供要分析的酒店数据');
        }

        try {
            // 计算统计数据
            $totalRoomNights = 0;
            $totalRoomRevenue = 0;
            $totalSales = 0;
            $totalExposure = 0;
            $totalViews = 0;
            $totalViewConversion = 0;
            $totalPayConversion = 0;

            foreach ($hotels as $hotel) {
                $totalRoomNights += floatval($hotel['roomNights'] ?? 0);
                $totalRoomRevenue += floatval($hotel['roomRevenue'] ?? 0);
                $totalSales += floatval($hotel['sales'] ?? 0);
                $totalExposure += floatval($hotel['exposure'] ?? 0);
                $totalViews += floatval($hotel['views'] ?? 0);
                $totalViewConversion += floatval($hotel['viewConversion'] ?? 0);
                $totalPayConversion += floatval($hotel['payConversion'] ?? 0);
            }

            $hotelCount = count($hotels);
            $avgRoomNights = $hotelCount > 0 ? $totalRoomNights / $hotelCount : 0;
            $avgRoomRevenue = $hotelCount > 0 ? $totalRoomRevenue / $hotelCount : 0;
            $avgPricePerNight = $totalRoomNights > 0 ? $totalRoomRevenue / $totalRoomNights : 0;
            $avgViewConversion = $hotelCount > 0 ? $totalViewConversion / $hotelCount : 0;
            $avgPayConversion = $hotelCount > 0 ? $totalPayConversion / $hotelCount : 0;

            // 排序获取TOP酒店
            $sortByRoomNights = $hotels;
            usort($sortByRoomNights, function($a, $b) {
                return floatval($b['roomNights'] ?? 0) - floatval($a['roomNights'] ?? 0);
            });
            $top5ByRoomNights = array_slice($sortByRoomNights, 0, 5);

            $sortByRevenue = $hotels;
            usort($sortByRevenue, function($a, $b) {
                return floatval($b['roomRevenue'] ?? 0) - floatval($a['roomRevenue'] ?? 0);
            });
            $top5ByRevenue = array_slice($sortByRevenue, 0, 5);

            // 生成分析报告
            $report = $this->generateAnalysisReport([
                'hotel_count' => $hotelCount,
                'total_room_nights' => $totalRoomNights,
                'total_room_revenue' => $totalRoomRevenue,
                'total_sales' => $totalSales,
                'total_exposure' => $totalExposure,
                'total_views' => $totalViews,
                'avg_room_nights' => $avgRoomNights,
                'avg_room_revenue' => $avgRoomRevenue,
                'avg_price_per_night' => $avgPricePerNight,
                'avg_view_conversion' => $avgViewConversion,
                'avg_pay_conversion' => $avgPayConversion,
                'top5_by_room_nights' => $top5ByRoomNights,
                'top5_by_revenue' => $top5ByRevenue,
            ], $includeSuggestions);

            // 记录操作日志
            OperationLog::record('online_data', 'ai_analysis', 'AI智能分析: ' . $hotelCount . '家酒店', $this->currentUser->id);

            return $this->success([
                'report' => $report,
                'summary' => "分析了 {$hotelCount} 家酒店，总入住间夜 " . number_format($totalRoomNights) . "，总房费收入 ¥" . number_format($totalRoomRevenue),
                'data' => [
                    'hotel_count' => $hotelCount,
                    'total_room_nights' => $totalRoomNights,
                    'total_room_revenue' => $totalRoomRevenue,
                    'avg_price_per_night' => round($avgPricePerNight, 2),
                ]
            ]);

        } catch (\Throwable $e) {
            return $this->error('分析失败: ' . $e->getMessage());
        }
    }

    /**
     * 生成分析报告HTML
     */
    private function generateAnalysisReport(array $data, bool $includeSuggestions = true): string
    {
        $hotelCount = $data['hotel_count'];
        $totalRoomNights = $data['total_room_nights'];
        $totalRoomRevenue = $data['total_room_revenue'];
        $totalSales = $data['total_sales'];
        $totalExposure = $data['total_exposure'];
        $totalViews = $data['total_views'];
        $avgRoomNights = $data['avg_room_nights'];
        $avgRoomRevenue = $data['avg_room_revenue'];
        $avgPricePerNight = $data['avg_price_per_night'];
        $avgViewConversion = $data['avg_view_conversion'];
        $avgPayConversion = $data['avg_pay_conversion'];
        $top5ByRoomNights = $data['top5_by_room_nights'];
        $top5ByRevenue = $data['top5_by_revenue'];

        // 生成TOP5列表HTML
        $top5RoomNightsHtml = '';
        foreach ($top5ByRoomNights as $i => $hotel) {
            $rank = $i + 1;
            $bgClass = $i === 0 ? 'bg-yellow-50 border-l-4 border-yellow-400' : 'bg-gray-50';
            $badgeClass = $i < 3 ? 'bg-yellow-400 text-white' : 'bg-gray-300 text-white';
            $roomNights = number_format(floatval($hotel['roomNights'] ?? 0));
            $top5RoomNightsHtml .= <<<HTML
            <div class="flex items-center justify-between p-2 {$bgClass} rounded">
                <div class="flex items-center">
                    <span class="w-6 h-6 rounded-full {$badgeClass} flex items-center justify-center text-xs font-bold mr-2">{$rank}</span>
                    <span class="text-sm font-medium">{$hotel['hotelName']}</span>
                </div>
                <span class="text-sm font-bold text-blue-600">{$roomNights} 间夜</span>
            </div>
HTML;
        }

        $top5RevenueHtml = '';
        foreach ($top5ByRevenue as $i => $hotel) {
            $rank = $i + 1;
            $bgClass = $i === 0 ? 'bg-green-50 border-l-4 border-green-400' : 'bg-gray-50';
            $badgeClass = $i < 3 ? 'bg-green-400 text-white' : 'bg-gray-300 text-white';
            $revenue = number_format(floatval($hotel['roomRevenue'] ?? 0));
            $top5RevenueHtml .= <<<HTML
            <div class="flex items-center justify-between p-2 {$bgClass} rounded">
                <div class="flex items-center">
                    <span class="w-6 h-6 rounded-full {$badgeClass} flex items-center justify-center text-xs font-bold mr-2">{$rank}</span>
                    <span class="text-sm font-medium">{$hotel['hotelName']}</span>
                </div>
                <span class="text-sm font-bold text-green-600">¥{$revenue}</span>
            </div>
HTML;
        }

        // 生成建议HTML
        $suggestionsHtml = '';
        if ($includeSuggestions) {
            $pricingAdvice = $avgPricePerNight > 300 
                ? '建议关注性价比，可适当推出优惠套餐吸引更多客源' 
                : '定价相对亲民，可通过增值服务提升客单价';
            
            $trafficAdvice = '';
            if ($totalExposure > 0 && $totalViews > 0) {
                $viewRate = ($totalViews / $totalExposure) * 100;
                $trafficAdvice = "曝光到浏览转化率 " . number_format($viewRate, 1) . "%，";
            }
            $trafficAdvice .= $avgViewConversion > 0 
                ? "平均浏览转化 " . number_format($avgViewConversion, 1) . "，建议优化详情页图片和描述提升转化率。" 
                : '建议关注流量入口优化，提升曝光量和浏览量。';

            $topHotelName = !empty($top5ByRoomNights) ? $top5ByRoomNights[0]['hotelName'] : '';
            $topHotelNights = !empty($top5ByRoomNights) ? number_format(floatval($top5ByRoomNights[0]['roomNights'] ?? 0)) : '0';

            $marketingAdvice = $totalExposure > $totalViews * 10 
                ? '曝光量充足但浏览转化偏低，建议优化主图和标题吸引点击。' 
                : '建议增加平台推广投放，扩大曝光量，同时关注评价维护。';

            $suggestionsHtml = <<<HTML
    <!-- AI建议 -->
    <div class="bg-gradient-to-r from-indigo-50 to-purple-50 border border-indigo-200 rounded-lg p-4">
        <h3 class="font-bold text-indigo-800 mb-3 flex items-center">
            <i class="fas fa-lightbulb text-indigo-500 mr-2"></i>AI经营建议
        </h3>
        <div class="space-y-3 text-sm text-gray-700">
            <div class="flex items-start">
                <i class="fas fa-check-circle text-green-500 mr-2 mt-0.5"></i>
                <div>
                    <strong>定价策略：</strong>
                    当前平均房价 ¥{$avgPricePerNight}，{$pricingAdvice}。
                </div>
            </div>
            <div class="flex items-start">
                <i class="fas fa-check-circle text-green-500 mr-2 mt-0.5"></i>
                <div>
                    <strong>流量转化：</strong>
                    {$trafficAdvice}
                </div>
            </div>
            <div class="flex items-start">
                <i class="fas fa-check-circle text-green-500 mr-2 mt-0.5"></i>
                <div>
                    <strong>竞对分析：</strong>
                    共分析 {$hotelCount} 家竞对酒店，
                    {$topHotelName}表现最佳（{$topHotelNights} 间夜），
                    建议分析其成功因素并借鉴学习。
                </div>
            </div>
            <div class="flex items-start">
                <i class="fas fa-check-circle text-green-500 mr-2 mt-0.5"></i>
                <div>
                    <strong>营销建议：</strong>
                    {$marketingAdvice}
                </div>
            </div>
        </div>
    </div>
HTML;
        }

        // 完整报告
        $totalRoomNightsFormatted = number_format($totalRoomNights);
        $totalRoomRevenueFormatted = number_format($totalRoomRevenue);
        $totalSalesFormatted = number_format($totalSales);
        $totalExposureFormatted = number_format($totalExposure);
        $totalViewsFormatted = number_format($totalViews);
        $avgRoomNightsFormatted = number_format($avgRoomNights, 1);
        $avgRoomRevenueFormatted = number_format($avgRoomRevenue, 0);
        $avgPricePerNightFormatted = number_format($avgPricePerNight, 0);

        $report = <<<HTML
<div class="space-y-6">
    <!-- 概览卡片 -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-blue-50 rounded-lg p-4 text-center">
            <div class="text-2xl font-bold text-blue-600">{$hotelCount}</div>
            <div class="text-sm text-gray-600">分析酒店数</div>
        </div>
        <div class="bg-green-50 rounded-lg p-4 text-center">
            <div class="text-2xl font-bold text-green-600">{$totalRoomNightsFormatted}</div>
            <div class="text-sm text-gray-600">总入住间夜</div>
        </div>
        <div class="bg-orange-50 rounded-lg p-4 text-center">
            <div class="text-2xl font-bold text-orange-600">¥{$totalRoomRevenueFormatted}</div>
            <div class="text-sm text-gray-600">总房费收入</div>
        </div>
        <div class="bg-purple-50 rounded-lg p-4 text-center">
            <div class="text-2xl font-bold text-purple-600">¥{$avgPricePerNightFormatted}</div>
            <div class="text-sm text-gray-600">平均房价</div>
        </div>
    </div>
    
    <!-- 经营分析 -->
    <div class="bg-white border rounded-lg p-4">
        <h3 class="font-bold text-gray-800 mb-3 flex items-center">
            <i class="fas fa-chart-line text-blue-500 mr-2"></i>经营数据分析
        </h3>
        <div class="space-y-3 text-sm">
            <div class="flex items-start">
                <span class="w-28 text-gray-500 flex-shrink-0">平均间夜：</span>
                <span class="text-gray-800">{$avgRoomNightsFormatted} 间夜/店</span>
            </div>
            <div class="flex items-start">
                <span class="w-28 text-gray-500 flex-shrink-0">平均收入：</span>
                <span class="text-gray-800">¥{$avgRoomRevenueFormatted}/店</span>
            </div>
            <div class="flex items-start">
                <span class="w-28 text-gray-500 flex-shrink-0">总销售额：</span>
                <span class="text-gray-800">¥{$totalSalesFormatted}</span>
            </div>
            <div class="flex items-start">
                <span class="w-28 text-gray-500 flex-shrink-0">曝光量：</span>
                <span class="text-gray-800">{$totalExposureFormatted} 次</span>
            </div>
            <div class="flex items-start">
                <span class="w-28 text-gray-500 flex-shrink-0">浏览量：</span>
                <span class="text-gray-800">{$totalViewsFormatted} 次</span>
            </div>
        </div>
    </div>
    
    <!-- 入住间夜TOP5 -->
    <div class="bg-white border rounded-lg p-4">
        <h3 class="font-bold text-gray-800 mb-3 flex items-center">
            <i class="fas fa-trophy text-yellow-500 mr-2"></i>入住间夜 TOP5
        </h3>
        <div class="space-y-2">
            {$top5RoomNightsHtml}
        </div>
    </div>
    
    <!-- 房费收入TOP5 -->
    <div class="bg-white border rounded-lg p-4">
        <h3 class="font-bold text-gray-800 mb-3 flex items-center">
            <i class="fas fa-coins text-green-500 mr-2"></i>房费收入 TOP5
        </h3>
        <div class="space-y-2">
            {$top5RevenueHtml}
        </div>
    </div>
    
    {$suggestionsHtml}
    
    <!-- 分析时间 -->
    <div class="text-xs text-gray-400 text-right">
        <i class="fas fa-clock mr-1"></i>分析时间：{$this->getCurrentTime()}
    </div>
</div>
HTML;

        return $report;
    }

    /**
     * 获取当前时间字符串
     */
    private function getCurrentTime(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * 获取携程点评数据
     */
    public function fetchCtripComments(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $requestUrl = $this->request->post('request_url', '');
        $hotelId = $this->request->post('hotel_id', '');
        $masterHotelId = $this->request->post('master_hotel_id', '');
        $cookies = $this->request->post('cookies', '');
        $token = $this->request->post('spidertoken', '') ?: $this->request->post('token', '');
        $pageIndex = intval($this->request->post('page_index', 1));
        $pageSize = intval($this->request->post('page_size', 50));
        $startDate = $this->request->post('start_date', '');
        $endDate = $this->request->post('end_date', '');
        $tagType = $this->request->post('tag_type', '');
        $fxpcqlniredt = $this->request->post('_fxpcqlniredt', '');
        $xTraceId = $this->request->post('x_trace_id', '');
        $payloadJson = trim((string)$this->request->post('payload_json', ''));
        $systemHotelId = $this->resolveOnlineDataSystemHotelId($this->request->post('system_hotel_id', null));

        if (empty($requestUrl)) {
            return $this->error('请求地址不能为空');
        }
        if (!$this->isAllowedOtaRequestUrl($requestUrl, ['ctrip.com'])) {
            return $this->error('仅允许请求携程官方域名');
        }
        if (empty($cookies)) {
            return $this->error('Cookies不能为空');
        }
        if (empty($token)) {
            return $this->error('spidertoken不能为空');
        }
        try {
            if ($payloadJson !== '') {
                $payload = json_decode($payloadJson, true);
                if (!is_array($payload)) {
                    return $this->error('Payload JSON格式错误');
                }
            } else {
                if (empty($hotelId)) {
                    return $this->error('请提供原始Payload JSON；当前携程点评接口不再强制要求hotelId');
                }
                $payload = [
                    'hotelId' => $hotelId,
                    'pageIndex' => $pageIndex,
                    'pageSize' => $pageSize,
                ];

                if (!empty($masterHotelId)) {
                    $payload['masterHotelId'] = $masterHotelId;
                }
                if (!empty($startDate)) {
                    $payload['startDate'] = $startDate;
                }
                if (!empty($endDate)) {
                    $payload['endDate'] = $endDate;
                }
                if (!empty($tagType)) {
                    $payload['tagType'] = $tagType;
                }
            }

            if (!empty($fxpcqlniredt) || !empty($xTraceId)) {
                $query = [];
                if (!empty($fxpcqlniredt)) {
                    $query['_fxpcqlniredt'] = $fxpcqlniredt;
                }
                if (!empty($xTraceId)) {
                    $query['x-traceID'] = $xTraceId;
                }
                $requestUrl .= (strpos($requestUrl, '?') === false ? '?' : '&') . http_build_query($query);
            }

            $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);

            // 构建请求头
            $headers = [
                'Accept: application/json, text/plain, */*',
                'Accept-Encoding: identity',
                'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
                'Content-Type: application/json',
                'Origin: https://ebooking.ctrip.com',
                'Referer: https://ebooking.ctrip.com/comment/commentList?microJump=true',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Cookie: ' . $cookies,
                'spidertoken: ' . $token,
                'Content-Length: ' . strlen($jsonPayload),
            ];

            // 发送请求
            $headerStr = implode("\r\n", $headers);
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => $headerStr,
                    'content' => $jsonPayload,
                    'timeout' => 30,
                    'ignore_errors' => true,
                ],
                'ssl' => $this->buildStreamSslOptions(),
            ]);

            $response = @file_get_contents($requestUrl, false, $context);

            if ($response === false) {
                $error = error_get_last();
                $message = '请求失败: ' . ($error['message'] ?? 'Unknown error');
                $this->recordCookieAlert('ctrip', 'fetch-ctrip-comments', $message, $systemHotelId);
                return $this->error($message);
            }

            // 解压gzip响应
            $decodedResponse = $response;
            if (substr($response, 0, 2) === "\x1f\x8b") {
                $decodedResponse = gzdecode($response);
            }

            // 获取HTTP响应码
            $httpCode = 0;
            if (isset($http_response_header)) {
                foreach ($http_response_header as $header) {
                    if (preg_match('/HTTP\/\d+\.?\d*\s+(\d+)/', $header, $matches)) {
                        $httpCode = (int)$matches[1];
                        break;
                    }
                }
            }

            if ($httpCode !== 200) {
                $message = 'HTTP错误: ' . $httpCode;
                $this->recordCookieAlert('ctrip', 'fetch-ctrip-comments', $message, $systemHotelId);
                return $this->error($message);
            }

            $data = json_decode($decodedResponse, true);

            if ($data === null) {
                $snippet = trim(strip_tags(substr($decodedResponse, 0, 300)));
                $message = 'JSON解析失败，可能Cookie/spidertoken已失效，或Payload/Header与携程当前请求不一致。响应摘要: ' . $snippet;
                $this->recordCookieAlert('ctrip', 'fetch-ctrip-comments', $message, $systemHotelId);
                return $this->error($message);
            }

            // 解析评论数据
            $comments = $data['data']['commentList']
                ?? $data['data']['list']
                ?? $data['data']['comments']
                ?? $data['commentList']
                ?? $data['comments']
                ?? $data['data']
                ?? [];
            $total = $data['data']['total'] ?? $data['total'] ?? count($comments);
            $upstreamCode = $data['code'] ?? $data['resultCode'] ?? $data['status'] ?? $data['ResponseStatus']['Ack'] ?? null;
            $upstreamMessage = $data['message'] ?? $data['msg'] ?? $data['errorMessage'] ?? $data['ResponseStatus']['Errors'][0]['Message'] ?? '';

            if (!is_array($comments)) {
                $comments = [];
            }

            $savedCount = $this->parseAndSaveCtripComments(
                $comments,
                $payload,
                $hotelId,
                $startDate ?: $endDate,
                $systemHotelId
            );

            OperationLog::record('online_data', 'fetch_ctrip_comments', '获取携程点评数据', $this->currentUser->id, $systemHotelId);

            return $this->success([
                'data' => $comments,
                'total' => $total,
                'saved_count' => $savedCount,
                'upstream_code' => $upstreamCode,
                'upstream_message' => $upstreamMessage,
                'upstream_keys' => array_keys($data),
            ]);

        } catch (\Exception $e) {
            return $this->error('请求异常: ' . $e->getMessage());
        }
    }

    private function parseAndSaveCtripComments(array $comments, array $payload, string $requestHotelId, string $fallbackDate = '', ?int $systemHotelId = null): int
    {
        if (empty($comments)) {
            return 0;
        }

        $savedCount = 0;
        $platformHotelId = $requestHotelId
            ?: (string)($payload['hotelId'] ?? $payload['hotel_id'] ?? $payload['masterHotelId'] ?? $payload['master_hotel_id'] ?? '');
        $fallbackDate = $this->normalizeOnlineDataDate($fallbackDate) ?: date('Y-m-d');

        foreach ($comments as $comment) {
            if (!is_array($comment)) {
                continue;
            }

            $commentId = $this->extractCtripCommentId($comment);
            $commentHotelId = (string)($comment['hotelId'] ?? $comment['hotel_id'] ?? $comment['masterHotelId'] ?? $platformHotelId);
            $dataDate = $this->extractCtripCommentDate($comment, $fallbackDate);
            $score = $this->extractCtripCommentScore($comment);

            $query = Db::name('online_daily_data')
                ->where('source', 'ctrip')
                ->where('data_type', 'review');
            if ($commentId !== '') {
                $query->where('raw_data', 'like', '%"' . $commentId . '"%');
            } else {
                $query->where('hotel_id', $commentHotelId)->where('data_date', $dataDate);
            }
            if ($systemHotelId !== null) {
                $query->where('system_hotel_id', $systemHotelId);
            } else {
                $query->whereNull('system_hotel_id');
            }
            $exists = $query->find();

            $data = $this->filterOnlineDailyDataFields($this->applyOnlineDailyDataValidationFields([
                'hotel_id' => $commentHotelId,
                'hotel_name' => (string)($comment['hotelName'] ?? $comment['hotel_name'] ?? ''),
                'system_hotel_id' => $systemHotelId,
                'data_date' => $dataDate,
                'amount' => 0,
                'quantity' => 0,
                'book_order_num' => 0,
                'comment_score' => $score,
                'qunar_comment_score' => 0,
                'data_value' => $score,
                'source' => 'ctrip',
                'data_type' => 'review',
                'dimension' => '点评',
                'platform' => 'Ctrip',
                'compare_type' => 'self',
                'raw_data' => json_encode($comment, JSON_UNESCAPED_UNICODE),
            ]));

            if ($exists) {
                Db::name('online_daily_data')->where('id', $exists['id'])->update($data);
            } else {
                Db::name('online_daily_data')->insert($data);
            }
            $savedCount++;
        }

        return $savedCount;
    }

    private function extractCtripCommentId(array $comment): string
    {
        foreach (['commentId', 'comment_id', 'id', 'reviewId', 'review_id', 'orderId'] as $field) {
            if (!empty($comment[$field])) {
                return (string)$comment[$field];
            }
        }
        return '';
    }

    private function extractCtripCommentDate(array $comment, string $fallbackDate): string
    {
        foreach (['commentTime', 'comment_time', 'reviewTime', 'review_time', 'createTime', 'create_time', 'submitTime', 'submit_time', 'checkOutDate', 'date'] as $field) {
            if (!isset($comment[$field]) || $comment[$field] === '') {
                continue;
            }
            $date = $this->normalizeOnlineDataDate($comment[$field]);
            if ($date !== '') {
                return $date;
            }
        }
        return $fallbackDate;
    }

    private function normalizeOnlineDataDate($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $rawText = trim((string)$value);
        if (preg_match('/^(19|20)\d{6}$/', $rawText)) {
            $year = (int)substr($rawText, 0, 4);
            $month = (int)substr($rawText, 4, 2);
            $day = (int)substr($rawText, 6, 2);
            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }
        if (is_numeric($value)) {
            $timestamp = (int)$value;
            if ($timestamp > 9999999999) {
                $timestamp = (int)floor($timestamp / 1000);
            }
            return date('Y-m-d', $timestamp);
        }
        $text = $rawText;
        if (preg_match('/(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $text, $matches)) {
            return sprintf('%04d-%02d-%02d', (int)$matches[1], (int)$matches[2], (int)$matches[3]);
        }
        $timestamp = strtotime($text);
        return $timestamp === false ? '' : date('Y-m-d', $timestamp);
    }

    private function extractCtripCommentScore(array $comment): float
    {
        foreach (['score', 'rating', 'rate', 'totalScore', 'overallScore', 'commentScore', 'star'] as $field) {
            if (isset($comment[$field]) && is_numeric($comment[$field])) {
                $score = (float)$comment[$field];
                if ($score > 5 && $score <= 50) {
                    return round($score / 10, 1);
                }
                if ($score > 50 && $score <= 100) {
                    return round($score / 20, 1);
                }
                return round($score, 1);
            }
        }
        return 0.0;
    }

    /**
     * 保存携程点评配置
     */
    public function saveCtripCommentConfig(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $name = $this->request->post('name', '');
        $hotelId = $this->request->post('hotel_id', '');
        $masterHotelId = $this->request->post('master_hotel_id', '');
        $requestUrl = $this->request->post('request_url', '');
        $cookies = $this->request->post('cookies', '');
        $token = $this->request->post('spidertoken', '') ?: $this->request->post('token', '');

        if (empty($name)) {
            $name = '携程点评-' . $hotelId;
        }

        if (empty($hotelId)) {
            return $this->error('Hotel ID不能为空');
        }
        if ($requestUrl !== '' && !$this->isAllowedOtaRequestUrl($requestUrl, ['ctrip.com'])) {
            return $this->error('仅允许保存携程官方域名请求地址');
        }

        // 获取配置列表
        $key = 'ctrip_comment_config_list';
        $list = $this->getConfigList($key);

        $id = md5($name . $hotelId);
        $originalConfig = $list[$id] ?? [];
        $systemHotelId = $this->resolveOnlineDataSystemHotelId($this->request->post('system_hotel_id', $originalConfig['system_hotel_id'] ?? null));
        $userId = $this->currentUser->isSuperAdmin() ? ($originalConfig['user_id'] ?? null) : $this->currentUser->id;
        $list[$id] = [
            'id' => $id,
            'name' => $name,
            'hotel_id' => $hotelId,
            'system_hotel_id' => $systemHotelId,
            'user_id' => $userId,
            'master_hotel_id' => $masterHotelId,
            'request_url' => $requestUrl,
            'cookies' => $cookies,
            'token' => $token,
            'update_time' => date('Y-m-d H:i:s'),
            'created_at' => $list[$id]['created_at'] ?? date('Y-m-d H:i:s'),
        ];

        $this->setConfigList($key, $list);

        OperationLog::record('online_data', 'save_ctrip_comment_config', "保存携程点评配置: {$name}", $this->currentUser->id);

        return $this->success($this->sanitizeSecretConfig($list[$id]), '配置保存成功');
    }

    /**
     * 获取携程点评配置列表
     */
    public function getCtripCommentConfigList(): Response
    {
        $this->checkPermission();

        $key = 'ctrip_comment_config_list';
        $list = $this->getConfigList($key);
        $list = $this->filterOtaConfigListForCurrentUser($list);

        // 按更新时间降序排序
        $list = array_values($list);
        usort($list, function($a, $b) {
            $timeA = $a['update_time'] ?? $a['created_at'] ?? '1970-01-01 00:00:00';
            $timeB = $b['update_time'] ?? $b['created_at'] ?? '1970-01-01 00:00:00';
            return strcmp($timeB, $timeA);
        });

        return $this->success(array_map([$this, 'sanitizeSecretConfig'], $list));
    }
}
