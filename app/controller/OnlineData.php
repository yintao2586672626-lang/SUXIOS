<?php
declare(strict_types=1);

namespace app\controller;

use app\model\OperationLog;
use app\model\SystemConfig;
use think\Response;
use think\facade\Db;

class OnlineData extends Base
{
    /**
     * 获取线上数据 - 携程ebooking接口
     */
    public function fetchCtrip(): Response
    {
        $url = $this->request->post('url', 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportCompeteHotelReport');
        $nodeId = $this->request->post('node_id', '24588');
        $cookies = $this->request->post('cookies', '');
        $authDataStr = $this->request->post('auth_data', '');
        $startDate = $this->request->post('start_date', '');
        $endDate = $this->request->post('end_date', '');
        $autoSave = $this->request->post('auto_save', true);
        $systemHotelId = $this->request->post('system_hotel_id', null);

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

            foreach ($dateResults as &$dateResult) {
                if ($autoSave) {
                    $dateResult['saved_count'] = $this->parseAndSaveData(
                        $dateResult['data'],
                        $dateResult['date'],
                        $dateResult['date'],
                        $systemHotelId ? (int)$systemHotelId : null
                    );
                    $savedCount += $dateResult['saved_count'];
                }
            }
            unset($dateResult);

            return json([
                'code' => 200,
                'message' => '获取成功',
                'data' => [
                    'data' => $responseData,
                    'date_results' => $dateResults,
                    'raw_response' => $rawResponse,
                    'saved_count' => $savedCount,
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
        $systemHotelId = $this->request->post('system_hotel_id', null);
        
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

        $url = (string)$this->request->post('url', '');
        $platform = (string)$this->request->post('platform', 'Ctrip');
        $dateRange = (string)$this->request->post('date_range', 'yesterday');
        $spiderkey = trim((string)$this->request->post('spiderkey', ''));
        $cookies = trim((string)$this->request->post('cookies', ''));
        $startDate = (string)$this->request->post('start_date', '');
        $endDate = (string)$this->request->post('end_date', '');
        $autoSave = $this->request->post('auto_save', true);
        $systemHotelId = $this->request->post('system_hotel_id', null);
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
                return $this->error($result['error'], 400, [
                    'http_code' => $result['http_code'],
                    'raw_response' => $result['raw_response'],
                    'decoded_data' => $result['decoded_data'],
                ]);
            }

            $responseData = $result['decoded_data'];
            $apiError = $this->getCtripTrafficApiError($responseData);
            if ($apiError !== '') {
                return $this->error($apiError, 400, [
                    'http_code' => $result['http_code'],
                    'raw_response' => $result['raw_response'],
                    'decoded_data' => $responseData,
                ]);
            }

            $savedCount = 0;
            if ($autoSave && is_array($responseData)) {
                $savedCount = $this->parseAndSaveTrafficData(
                    $responseData,
                    $startDate,
                    $endDate,
                    strtolower($platform),
                    $systemHotelId ? (int)$systemHotelId : null,
                    $platform
                );
            }

            OperationLog::record('online_data', 'fetch_ctrip_traffic', '获取携程流量数据', $this->currentUser->id, $systemHotelId ? (int)$systemHotelId : null);

            return $this->success([
                'data' => $responseData,
                'decoded_data' => $responseData,
                'raw_response' => $result['raw_response'],
                'http_code' => $result['http_code'],
                'saved_count' => $savedCount,
                'platform' => $platform,
                'request_start_date' => $startDate,
                'request_end_date' => $endDate,
                'request_url' => $requestUrl,
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            return $this->error('请求异常: ' . $e->getMessage());
        }
    }

    /**
     * 获取美团流量数据
     */
    public function fetchMeituanTraffic(): Response
    {
        $this->checkPermission();

        $url = $this->request->post('url', '');
        $cookies = $this->request->post('cookies', '');
        $startDate = $this->request->post('start_date', '');
        $endDate = $this->request->post('end_date', '');
        $autoSave = $this->request->post('auto_save', true);
        $systemHotelId = $this->request->post('system_hotel_id', null);
        $extraParamsStr = $this->request->post('extra_params', '');

        if (empty($url)) {
            return $this->error('请提供接口地址');
        }
        if (empty($cookies)) {
            return $this->error('请提供登录Cookies');
        }

        try {
            $extraParams = $this->parseJsonParams($extraParamsStr);

            $params = $extraParams;
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
     * 获取美团差评数据（优化版）
     * API: https://eb.meituan.com/api/v1/ebooking/comments/commentsInfo
     */
    public function fetchMeituanComments(): Response
    {
        $this->checkPermission();

        $cookies = $this->request->post('cookies', '');
        $partnerId = $this->request->post('partner_id', '');
        $poiId = $this->request->post('poi_id', '');
        $mtsiEbU = $this->request->post('mtsi_eb_u', ''); // _mtsi_eb_u 可选参数
        $replyType = $this->request->post('reply_type', '2'); // 2=差评/待回复
        $tag = $this->request->post('tag', ''); // 标签筛选
        $limit = $this->request->post('limit', 50);
        $offset = $this->request->post('offset', 0);
        $platform = $this->request->post('platform', 1);
        $mtgsig = $this->request->post('mtgsig', ''); // 美团签名（403/418时需要）
        $requestUrl = $this->request->post('request_url', ''); // 自定义请求地址（可选）
        $startDate = $this->request->post('start_date', ''); // 开始日期
        $endDate = $this->request->post('end_date', ''); // 结束日期
        $autoSave = $this->request->post('auto_save', true);
        $systemHotelId = $this->request->post('system_hotel_id', null);

        if (empty($cookies)) {
            return $this->error('请提供登录Cookies');
        }
        if (empty($partnerId)) {
            return $this->error('请提供Partner ID（商家ID）');
        }
        if (empty($poiId)) {
            return $this->error('请提供POI ID（门店ID）');
        }
        
        // 去除前后空格
        $cookies = trim($cookies);
        $partnerId = trim($partnerId);
        $poiId = trim($poiId);
        $mtsiEbU = trim($mtsiEbU);
        $mtgsig = trim($mtgsig);
        $requestUrl = trim($requestUrl);
        $tag = trim($tag);

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
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
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
                if ($httpCode == 403) {
                    return $this->error('美团API拒绝访问（403）。mtgsig签名已过期，请重新获取：在美团ebooking差评页面按F12 → Network → 刷新页面 → 找到commentsInfo请求 → 复制最新的mtgsig值');
                }
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
                    return $this->error('美团API签名验证失败，请在美团ebooking页面打开开发者工具，复制请求头中的mtgsig值');
                }
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
                $existing = Db::table('online_data')
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

                $inserted = Db::table('online_data')->insertGetId($insertData);
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
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
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
            
            // 记录原始响应结构用于调试
            \think\facade\Log::info('美团数据解析 - 完整响应: ' . json_encode($responseData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            \think\facade\Log::info('美团数据解析 - 原始响应结构: ' . json_encode([
                'keys' => array_keys($responseData),
                'data_keys' => isset($responseData['data']) && is_array($responseData['data']) ? array_keys($responseData['data']) : 'not_array',
                'data_type' => isset($responseData['data']) ? gettype($responseData['data']) : 'not_set',
                'data_value' => isset($responseData['data']) ? (is_array($responseData['data']) ? 'is_array' : $responseData['data']) : 'not_set',
            ], JSON_UNESCAPED_UNICODE));
            
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
            if (!empty($dataList[0])) {
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
        
        foreach ($dataList as $item) {
            if (!is_array($item)) continue;
            
            // 尝试多种字段名获取酒店ID
            $hotelId = $item['hotelId'] ?? $item['hotel_id'] ?? $item['HotelId'] ?? $item['hotelID'] ?? null;
            if (empty($hotelId)) continue;
            
            // 尝试多种字段名获取酒店名称
            $hotelName = $item['hotelName'] ?? $item['hotel_name'] ?? $item['HotelName'] ?? $item['name'] ?? '';
            
            // 尝试多种字段名获取其他数据
            $amount = floatval($item['amount'] ?? $item['Amount'] ?? $item['totalAmount'] ?? $item['total_amount'] ?? $item['saleAmount'] ?? 0);
            $quantity = intval($item['quantity'] ?? $item['Quantity'] ?? $item['roomNights'] ?? $item['room_nights'] ?? $item['checkOutQuantity'] ?? 0);
            $bookOrderNum = intval($item['bookOrderNum'] ?? $item['book_order_num'] ?? $item['orderCount'] ?? $item['order_count'] ?? 0);
            $commentScore = floatval($item['commentScore'] ?? $item['comment_score'] ?? $item['score'] ?? $item['avgScore'] ?? 0);
            $qunarCommentScore = floatval($item['qunarCommentScore'] ?? $item['qunar_comment_score'] ?? $item['qunarScore'] ?? 0);
            
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
        
        $url = $this->request->post('url', '');
        $method = $this->request->post('method', 'GET');
        $headers = $this->request->post('headers', '');
        $body = $this->request->post('body', '');
        
        if (empty($url)) {
            return $this->error('请提供URL');
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
     * 不需要常规认证，通过URL中的token识别用户
     */
    public function receiveCookies(): Response
    {
        // 允许跨域请求
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        
        if ($this->request->method() === 'OPTIONS') {
            return response('', 204);
        }
        
        $token = $this->request->param('token', '');
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
        $userId = cache('token_' . $token);
        if (!$userId) {
            return $this->corsError('Token无效或已过期');
        }
        
        // 保存Cookies配置
        $key = 'online_data_cookies_list';
        $list = $this->getConfigList($key);
        $list[$name] = [
            'name' => $name,
            'cookies' => $cookies,
            'source' => $source,
            'update_time' => date('Y-m-d H:i:s'),
            'user_id' => $userId,
        ];
        $this->setConfigList($key, $list);
        
        OperationLog::record('online_data', 'receive_cookies', '通过书签脚本获取Cookies: ' . $name, (int)$userId);
        
        return $this->corsSuccess([
            'name' => $name,
            'message' => 'Cookies已成功保存到系统',
        ]);
    }
    
    /**
     * 返回CORS错误响应
     */
    private function corsError(string $message): Response
    {
        return json([
            'code' => 400,
            'message' => $message,
            'data' => null,
        ])->header([
            'Access-Control-Allow-Origin' => '*',
        ]);
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
        ])->header([
            'Access-Control-Allow-Origin' => '*',
        ]);
    }
    
    /**
     * 获取书签脚本代码
     */
    public function bookmarklet(): Response
    {
        $this->checkPermission();
        
        $token = $this->request->get('token', '');
        if (empty($token)) {
            return $this->error('缺少Token');
        }
        
        // 获取完整的 baseUrl（包含协议）
        $scheme = $this->request->scheme(); // http 或 https
        $host = $this->request->host(true);
        $baseUrl = $scheme . '://' . $host;
        
        // 生成智能书签脚本 - 从网络请求中获取真正的Cookie
                // 涔︾鑴氭湰锛氭嫤鎴猣etch璇锋眰锛屾崟鑾稢ookie骞朵繚瀛?
        $script = "(function(){alert(\"bookmarklet\");})();";
        
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
    
    /**
     * 保存Cookies配置（按门店隔离）
     */
    public function saveCookies(): Response
    {
        $this->checkPermission();
        
        $name = $this->request->post('name', '');
        $cookies = $this->request->post('cookies', '');
        $hotelId = $this->request->post('hotel_id', '');
        
        if (empty($name) || empty($cookies)) {
            return $this->error('名称和Cookies不能为空');
        }
        
        // 非超级管理员只能保存自己酒店的Cookies
        if (!$this->currentUser->isSuperAdmin()) {
            $hotelId = $this->currentUser->hotel_id;
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
            return $this->success(array_values($list));
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
        
        return $this->success($allCookies);
    }
    
    /**
     * 删除Cookies配置（按门店隔离）
     */
    public function deleteCookies(): Response
    {
        $this->checkPermission();
        
        $name = $this->request->post('name', '');
        $hotelId = $this->request->post('hotel_id', '');
        
        if (empty($name)) {
            return $this->error('名称不能为空');
        }
        
        // 非超级管理员只能删除自己酒店的Cookies
        if (!$this->currentUser->isSuperAdmin()) {
            $hotelId = $this->currentUser->hotel_id;
        }
        
        // 构建key
        $key = $hotelId ? "online_data_cookies_hotel_{$hotelId}" : "online_data_cookies_global";
        $list = $this->getConfigList($key);
        if (isset($list[$name])) {
            unset($list[$name]);
            $this->setConfigList($key, $list);
            return $this->success(null, '删除成功');
        }
        
        // 如果指定了酒店但没找到，尝试从全局删除
        if ($hotelId) {
            $globalKey = "online_data_cookies_global";
            $globalList = $this->getConfigList($globalKey);
            if (isset($globalList[$name])) {
                unset($globalList[$name]);
                $this->setConfigList($globalKey, $globalList);
                return $this->success(null, '删除成功');
            }
        }
        
        return $this->error('Cookies配置不存在');
    }
    
    /**
     * 保存美团配置（API地址、Partner ID、POI ID、排名类型、时间维度、Cookies等）
     */
    public function saveMeituanConfig(): Response
    {
        $this->checkPermission();
        
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
        
        $hotelId = $this->request->post('hotel_id', '');
        
        // 非超级管理员只能保存自己酒店的配置
        if (!$this->currentUser->isSuperAdmin()) {
            $hotelId = $this->currentUser->hotel_id;
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
                    return $this->success($decoded);
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
        
        return $this->success($globalConfig);
    }

    /**
     * 保存美团配置（列表方式，支持多个配置）
     */
    public function saveMeituanConfigItem(): Response
    {
        $this->checkPermission();

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

        // 获取配置列表 - 直接查 system_config 表
        $key = 'meituan_config_list';
        $raw = \think\facade\Db::name('system_config')->where('config_key', $key)->value('config_value');
        $list = $raw ? json_decode($raw, true) : [];
        if (!is_array($list)) {
            $list = [];
        }

        // 生成唯一ID
        if (empty($id)) {
            $id = 'meituan_' . date('YmdHis') . '_' . substr(md5($name . time()), 0, 8);
        }

        // 非超级管理员：编辑时只能改自己的配置
        if (!$this->currentUser->isSuperAdmin() && !empty($id) && isset($list[$id])) {
            if (($list[$id]['user_id'] ?? '') != $this->currentUser->id) {
                return $this->error('无权修改此配置');
            }
        }

        // 非超级管理员删除时也只能删自己的
        $userId = $this->currentUser->isSuperAdmin() ? null : $this->currentUser->id;

        $list[$id] = [
            'id' => $id,
            'name' => $name,
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

        // 写回 system_config 表
        $encoded = json_encode($list, JSON_UNESCAPED_UNICODE);
        \think\facade\Db::name('system_config')->where('config_key', $key)->update([
            'config_value' => $encoded,
            'update_time' => date('Y-m-d H:i:s'),
        ]);

        OperationLog::record('online_data', 'save_meituan_config', "保存美团配置: {$name}", $this->currentUser->id);

        return $this->success($list[$id], '配置保存成功');
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
            // 与 getCtripConfigList 保持一致：直接查 system_config 表（注意是单数表，美团数据在此表）
            $raw = \think\facade\Db::name('system_config')->where('config_key', $key)->value('config_value');
            $list = $raw ? json_decode($raw, true) : [];
            if (!is_array($list)) {
                $list = [];
            }

            // 权限控制：超级管理员看到全部，VIP用户只能看到自己保存的配置
            $isAdmin = ($this->currentUser && method_exists($this->currentUser, 'isSuperAdmin') && $this->currentUser->isSuperAdmin());
            if (!$isAdmin) {
                $uid = $this->currentUser ? $this->currentUser->id : null;
                $myList = [];
                foreach ($list as $item) {
                    $itemId = $item['user_id'] ?? null;
                    if ($uid !== null && $itemId !== null && strval($itemId) === strval($uid)) {
                        $myList[] = $item;
                    }
                }
                $list = $myList;
            }

            usort($list, function($a, $b) {
                return strcmp($b['update_time'] ?? '', $a['update_time'] ?? '');
            });

            return $this->success(array_values($list));
        } catch (\Throwable $e) {
            return json(['code' => 500, 'message' => '内部错误: ' . $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
        }
    }

    /**
     * 删除美团配置
     */
    public function deleteMeituanConfig(): Response
    {
        $this->checkPermission();

        $id = $this->request->param('id', '');
        if (empty($id)) {
            return $this->error('请提供配置ID');
        }

        $key = 'meituan_config_list';
        // 与读取保持一致：直接查 system_config 表
        $raw = \think\facade\Db::name('system_config')->where('config_key', $key)->value('config_value');
        $list = $raw ? json_decode($raw, true) : [];
        if (!is_array($list)) {
            $list = [];
        }

        if (!isset($list[$id])) {
            return $this->error('配置不存在');
        }

        // 非超级管理员只能删除自己的配置
        if (!$this->currentUser->isSuperAdmin()) {
            if (($list[$id]['user_id'] ?? null) != $this->currentUser->id) {
                return $this->error('无权删除此配置');
            }
        }

        $name = $list[$id]['name'] ?? '';
        unset($list[$id]);
        // 写回 system_config 表
        $encoded = json_encode($list, JSON_UNESCAPED_UNICODE);
        \think\facade\Db::name('system_config')->update([
            'config_key' => $key,
            'config_value' => $encoded,
            'update_time' => date('Y-m-d H:i:s'),
        ]);

        OperationLog::record('online_data', 'delete_meituan_config', "删除美团配置: {$name}", $this->currentUser->id);

        return $this->success(null, '删除成功');
    }

    /**
     * 保存美团点评配置（优化版）
     */
    public function saveMeituanCommentConfig(): Response
    {
        $this->checkPermission();

        $name = $this->request->post('name', '');
        $partnerId = $this->request->post('partner_id', '');
        $poiId = $this->request->post('poi_id', '');
        $mtsiEbU = $this->request->post('mtsi_eb_u', '');
        $cookies = $this->request->post('cookies', '');
        $mtgsig = $this->request->post('mtgsig', '');
        $requestUrl = $this->request->post('request_url', '');

        if (empty($name)) {
            return $this->error('配置名称不能为空');
        }
        if (empty($partnerId) || empty($poiId)) {
            return $this->error('Partner ID 和 POI ID 不能为空');
        }

        // 获取配置列表
        $key = 'meituan_comment_config_list';
        $list = $this->getConfigList($key);

        $id = md5($name . $partnerId . $poiId);
        $list[$id] = [
            'id' => $id,
            'name' => $name,
            'partner_id' => $partnerId,
            'poi_id' => $poiId,
            'mtsi_eb_u' => $mtsiEbU,
            'cookies' => $cookies,
            'mtgsig' => $mtgsig,
            'request_url' => $requestUrl,
            'update_time' => date('Y-m-d H:i:s'),
            'created_at' => $list[$id]['created_at'] ?? date('Y-m-d H:i:s'),
        ];

        $this->setConfigList($key, $list);

        OperationLog::record('online_data', 'save_meituan_comment_config', "保存美团点评配置: {$name}", $this->currentUser->id);

        return $this->success($list[$id], '配置保存成功');
    }

    /**
     * 获取美团点评配置列表
     */
    public function getMeituanCommentConfigList(): Response
    {
        $this->checkPermission();

        $key = 'meituan_comment_config_list';
        $list = $this->getConfigList($key);

        // 按更新时间降序排序
        $list = array_values($list);
        usort($list, function($a, $b) {
            $timeA = $a['update_time'] ?? $a['created_at'] ?? '1970-01-01 00:00:00';
            $timeB = $b['update_time'] ?? $b['created_at'] ?? '1970-01-01 00:00:00';
            return strcmp($timeB, $timeA);
        });

        return $this->success($list);
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
        try {
            $name = $this->request->post('name', '');
            $cookies = $this->request->post('cookies', '');

            if (empty($name) || empty($cookies)) {
                return json(['code' => 400, 'message' => '配置名称和Cookies不能为空']);
            }

            // 生成唯一ID
            $id = 'ctrip_' . date('YmdHis') . '_' . substr(md5($name . time()), 0, 8);

            // 非超级管理员保存时记录 user_id
            $userId = $this->currentUser->isSuperAdmin() ? null : $this->currentUser->id;

            $config = [
                'id' => $id,
                'name' => $name,
                'cookies' => $cookies,
                'url' => $this->request->post('url', ''),
                'node_id' => $this->request->post('node_id', ''),
                'user_id' => $userId,
                'update_time' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ];

            // 保存到数据库
            $key = 'ctrip_config_list';
            $existing = \think\facade\Db::name('system_configs')->where('config_key', $key)->find();
            $list = [];
            if ($existing) {
                $list = json_decode($existing['config_value'], true) ?: [];
            }
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

            return json(['code' => 200, 'message' => '配置保存成功', 'data' => $config]);
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

            // 非超级管理员只能看到自己保存的配置（排除无user_id的旧数据）
            $isAdmin = ($this->currentUser && method_exists($this->currentUser, 'isSuperAdmin') && $this->currentUser->isSuperAdmin());
            if (!$isAdmin) {
                $uid = $this->currentUser ? $this->currentUser->id : null;
                $myList = [];
                foreach ($list as $item) {
                    $itemId = $item['user_id'] ?? null;
                    if ($uid !== null && $itemId !== null && strval($itemId) === strval($uid)) {
                        $myList[] = $item;
                    }
                }
                $list = $myList;
            }

            usort($list, function($a, $b) {
                return strcmp($b['update_time'] ?? '', $a['update_time'] ?? '');
            });

            return $this->success(array_values($list));
        } catch (\Throwable $e) {
            return json(['code' => 500, 'message' => '内部错误: ' . $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
        }
    }

    /**
     * 删除携程配置
     */
    public function deleteCtripConfig(): Response
    {
        $this->checkPermission();

        $id = $this->request->get('id', '');
        if (empty($id)) {
            return $this->error('配置ID不能为空');
        }

        $key = 'ctrip_config_list';
        $list = $this->getConfigList($key);

        if (!isset($list[$id])) {
            return $this->error('配置不存在');
        }

        // 非超级管理员只能删除自己的配置
        if (!$this->currentUser->isSuperAdmin()) {
            if (($list[$id]['user_id'] ?? null) != $this->currentUser->id) {
                return $this->error('无权删除此配置');
            }
        }

        $name = $list[$id]['name'] ?? '';
        unset($list[$id]);
        $this->setConfigList($key, $list);

        OperationLog::record('online_data', 'delete_ctrip_config', "删除携程配置: {$name}", $this->currentUser->id);

        return $this->success(null, '删除成功');
    }

    /**
     * 生成携程一键获取Cookies书签脚本
     */
    public function generateCtripBookmarklet(): Response
    {
        // 简单测试 - 直接返回成功
        return $this->success([
            'script' => 'test',
            'bookmarklet' => 'javascript:test',
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

        $list[$id] = [
            'id' => $id,
            'name' => $name,
            'hotel_id' => null,
            'hotel_name' => '',
            'url' => 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportCompeteHotelReport',
            'node_id' => '24588',
            'cookies' => $cookies,
            'auth_data' => $authData,
            'update_time' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ];

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
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
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
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
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
        $url = trim($url);
        if ($url === '') {
            $url = 'https://ebooking.ctrip.com/datacenter/api/inland/marketanalysis/flowanalysis/queryFlowTransforNewV1?hostType=Ebooking';
        }
        $url = preg_replace('/\s+/', '', $url) ?? $url;
        $url = preg_replace('/([?&]v=)([0-9.]+)[^&]*/i', '
    private function sendCustomRequest($2', $url) ?? $url;
        $random = rtrim(rtrim(sprintf('%.12F', mt_rand() / mt_getrandmax()), '0'), '.');
        if (stripos($url, 'queryFlowTransforNewV1') === false) {
            throw new \InvalidArgumentException('Request URL 必须是 queryFlowTransforNewV1 接口');
        }
        if (stripos($url, 'hostType=') === false) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . 'hostType=Ebooking';
        }
        if (preg_match('/([?&])v=[^&]*/i', $url)) {
            return preg_replace('/([?&])v=[^&]*/i', '
    private function sendCustomRequest(v=' . $random, $url) ?? $url;
        }
        return $url . (strpos($url, '?') === false ? '?' : '&') . 'v=' . $random;
    }

    private function buildCtripTrafficDateRange(string $dateRange, string $startDate, string $endDate): array
    {
        $today = date('Y-m-d');
        switch ($dateRange) {
            case 'today_realtime':
            case 'today':
            case '0':
                return [$today, $today];
            case 'last_7_days':
            case '7':
                return [date('Y-m-d', strtotime('-6 days')), $today];
            case 'last_30_days':
            case '30':
                return [date('Y-m-d', strtotime('-29 days')), $today];
            case 'custom':
                if ($startDate === '' || $endDate === '') {
                    throw new \InvalidArgumentException('请选择自定义开始日期和结束日期');
                }
                break;
            case 'yesterday':
            case '1':
            default:
                if ($startDate === '' || $endDate === '') {
                    $yesterday = date('Y-m-d', strtotime('-1 day'));
                    return [$yesterday, $yesterday];
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
        if (isset($responseData[0]) && is_array($responseData[0])) {
            return $responseData;
        }
        foreach ([['data'], ['data', 'list'], ['data', 'rows'], ['result'], ['result', 'data'], ['result', 'list'], ['list']] as $path) {
            $current = $responseData;
            foreach ($path as $key) {
                if (!is_array($current) || !array_key_exists($key, $current)) {
                    $current = null;
                    break;
                }
                $current = $current[$key];
            }
            if (is_array($current) && isset($current[0]) && is_array($current[0])) {
                return $current;
            }
        }
        return [];
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
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
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
    
    /**
     * 保存线上数据到数据库
     */
    public function saveDailyData(): Response
    {
        $this->checkPermission();
        
        $dataList = $this->request->post('data', []);
        $dataDate = $this->request->post('data_date', date('Y-m-d', strtotime('-1 day')));
        
        if (empty($dataList)) {
            return $this->error('数据不能为空');
        }
        
        // 使用统一的解析和保存方法
        $savedCount = $this->parseAndSaveData(['data' => $dataList], $dataDate, $dataDate);
        
        OperationLog::record('online_data', 'save_daily', '保存线上数据: ' . $savedCount . '条', $this->currentUser->id);
        
        return $this->success(['saved_count' => $savedCount], '保存成功，共保存 ' . $savedCount . ' 条数据');
    }
    
    /**
     * 获取线上数据列表（支持门店隔离）
     */
    public function dailyDataList(): Response
    {
        // 调试：测试代码是否更新
        $debug = $this->request->get('debug', '');
        if ($debug === 'test') {
            return json(['code' => 200, 'message' => '代码已更新 v5', 'time' => date('Y-m-d H:i:s')]);
        }
        
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
            $hotelId = $this->request->get('hotel_id', '');  // 酒店筛选
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
            
            // 按酒店筛选（hotel_id 是平台酒店ID）
            if (!empty($hotelId)) {
                $query->where('hotel_id', $hotelId);
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
            
            // 调试：记录筛选条件和SQL
            $debugInfo = [
                'createStart' => $createStart,
                'createEnd' => $createEnd,
                'source' => $source,
                'hotelId' => $hotelId,
                'raw_create_start' => $this->request->get('create_start', ''),
                'raw_create_end' => $this->request->get('create_end', ''),
            ];
            
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
                // 计算 total_order_num（全渠道总订单 = 携程预订订单 × 1.2-1.3随机值）
                $bookOrderNum = intval($item['book_order_num'] ?? 0);
                $item['total_order_num'] = intval($bookOrderNum * (1.2 + mt_rand(0, 10) / 100));
                
                if (!empty($item['raw_data'])) {
                    $rawData = json_decode($item['raw_data'], true);
                    if ($rawData) {
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
            }
            
            return $this->success([
                'list' => $list,
                'pagination' => [
                    'total' => $total,
                    'page' => $page,
                    'page_size' => $pageSize,
                ],
                'debug' => $debugInfo,
            ]);
        } catch (\Throwable $e) {
            // 使用 Throwable 捕获所有错误
            return $this->error('获取数据列表失败: ' . $e->getMessage() . ' 在 ' . $e->getFile() . ':' . $e->getLine());
        }
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
        
        // 按日期汇总
        $dailyQuery = Db::name('online_daily_data')
            ->field('data_date, SUM(amount) as total_amount, SUM(quantity) as total_quantity, SUM(book_order_num) as total_book_order_num, AVG(comment_score) as avg_comment_score')
            ->where('data_date', '>=', $startDate)
            ->where('data_date', '<=', $endDate);
        $this->applyDataTypeFilter($dailyQuery, $dataType);
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
            
            $hotels = $query->select()->toArray();
            
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
    public function autoFetch(): Response
    {
        $this->checkPermission();
        
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
            if (!$this->currentUser->hasHotelPermission((int)$systemHotelId, 'can_view_online_data')) {
                return $this->error('无权获取该门店的数据');
            }
        }
        
        if (empty($systemHotelId)) {
            return $this->error('请选择要获取数据的门店');
        }
        
        // 获取该门店的 cookies 配置
        $cookiesKey = "online_data_cookies_hotel_{$systemHotelId}";
        $cookiesList = $this->getConfigList($cookiesKey);
        
        // 取第一个有效的cookies配置（不再限制名称必须包含ctrip）
        $cookies = '';
        foreach ($cookiesList as $item) {
            if (!empty($item['cookies'])) {
                $cookies = $item['cookies'];
                break;
            }
        }
        
        if (empty($cookies)) {
            \think\facade\Log::warning('线上数据获取失败: 未配置Cookies', [
                'user_id' => $this->currentUser->id,
                'hotel_id' => $systemHotelId
            ]);
            return $this->error('未配置携程Cookies，请先在Cookies管理中添加');
        }
        
        // 获取昨天的数据
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        
        // 检查今天是否已经获取过该日期的数据（每天只获取一次）
        $fetchRecordKey = "online_data_fetch_{$systemHotelId}_{$yesterday}";
        $existingRecord = cache($fetchRecordKey);
        if ($existingRecord) {
            return $this->error("该门店 {$yesterday} 的数据今天已经获取过了，请勿重复获取");
        }
        
        try {
            $result = $this->sendHttpRequest(
                'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportCompeteHotelReport',
                ['nodeId' => '24588', 'startDate' => $yesterday, 'endDate' => $yesterday],
                $cookies
            );
            
            if (!$result['success']) {
                \think\facade\Log::error('线上数据获取失败: ' . $result['error'], [
                    'user_id' => $this->currentUser->id,
                    'hotel_id' => $systemHotelId,
                    'response' => $result['raw'] ?? null
                ]);
                
                // 更新状态记录
                $this->updateFetchStatus($systemHotelId, false, '请求失败: ' . $result['error']);
                
                return $this->error('请求失败: ' . $result['error']);
            }
            
            $responseData = $result['data'];
            
            // 检查API返回的错误状态
            $responseStatus = $responseData['responseStatus'] ?? $responseData['status'] ?? $responseData['code'] ?? null;
            $errorMsg = $responseData['message'] ?? $responseData['msg'] ?? $responseData['errorMessage'] ?? null;
            
            // 如果有错误状态码
            if ($responseStatus !== null && $responseStatus !== 0 && $responseStatus !== '0' && $responseStatus !== 200 && $responseStatus !== '200') {
                $errorMsg = $errorMsg ?: "API返回错误状态: {$responseStatus}";
                \think\facade\Log::error('线上数据获取: API返回错误', [
                    'user_id' => $this->currentUser->id,
                    'hotel_id' => $systemHotelId,
                    'response_status' => $responseStatus,
                    'error_msg' => $errorMsg,
                    'response_data' => json_encode($responseData, JSON_UNESCAPED_UNICODE)
                ]);
                $this->updateFetchStatus($systemHotelId, false, $errorMsg);
                return $this->error($errorMsg);
            }
            
            // 使用统一的数据解析和保存方法（传入系统酒店ID）
            $savedCount = $this->parseAndSaveData($responseData, $yesterday, $yesterday, $systemHotelId ? (int)$systemHotelId : null);
            
            if ($savedCount === 0) {
                \think\facade\Log::warning('线上数据获取: 未获取到有效数据', [
                    'user_id' => $this->currentUser->id,
                    'hotel_id' => $systemHotelId,
                    'response_data' => json_encode($responseData, JSON_UNESCAPED_UNICODE)
                ]);
                
                $this->updateFetchStatus($systemHotelId, false, '未获取到有效数据，请检查Cookies是否有效');
                
                return $this->error('未获取到有效数据，请检查Cookies是否有效');
            }
            
            // 标记今天已获取
            cache($fetchRecordKey, [
                'time' => date('Y-m-d H:i:s'),
                'count' => $savedCount,
                'user_id' => $this->currentUser->id
            ], 86400); // 缓存24小时
            
            OperationLog::record('online_data', 'auto_fetch', "自动获取线上数据: {$savedCount}条 (门店ID: {$systemHotelId})", $this->currentUser->id);
            
            // 更新状态记录
            $this->updateFetchStatus($systemHotelId, true, "成功获取 {$savedCount} 条数据");
            
            return $this->success(['saved_count' => $savedCount], '自动获取成功');
            
        } catch (\Exception $e) {
            \think\facade\Log::error('线上数据获取异常: ' . $e->getMessage(), [
                'user_id' => $this->currentUser->id,
                'hotel_id' => $systemHotelId,
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->updateFetchStatus($systemHotelId, false, '获取异常: ' . $e->getMessage());
            
            return $this->error('异常: ' . $e->getMessage());
        }
    }
    
    /**
     * 更新获取状态
     */
    private function updateFetchStatus(?int $hotelId, bool $success, string $message): void
    {
        $statusKey = $hotelId ? "online_data_auto_fetch_status_{$hotelId}" : 'online_data_auto_fetch_status';
        $status = cache($statusKey) ?: [];
        $status['last_run_time'] = date('Y-m-d H:i:s');
        $status['last_result'] = [
            'success' => $success,
            'message' => $message
        ];
        cache($statusKey, $status, 86400 * 30);
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
            $permittedHotelIds = $this->currentUser->getPermittedHotelIds();
            if (empty($permittedHotelIds)) {
                return $this->success([
                    'enabled' => false,
                    'last_run_time' => null,
                    'next_run_time' => '-',
                    'last_result' => null,
                    'schedule_time' => '10:00'
                ]);
            }
            $hotelId = $permittedHotelIds[0];
        }
        
        $statusKey = $hotelId ? "online_data_auto_fetch_status_{$hotelId}" : 'online_data_auto_fetch_status';
        $status = cache($statusKey) ?: [
            'enabled' => false,
            'last_run_time' => null,
            'next_run_time' => null,
            'last_result' => null,
            'schedule_time' => '10:00'
        ];
        
        // 确保必要字段存在
        if (!isset($status['enabled'])) {
            $status['enabled'] = false;
        }
        if (!isset($status['schedule_time'])) {
            $status['schedule_time'] = '10:00';
        }
        
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
        
        return $this->success($status);
    }
    
    /**
     * 切换自动获取开关
     */
    public function toggleAutoFetch(): Response
    {
        $this->checkPermission();
        
        $enabled = $this->request->post('enabled', true);
        $hotelId = $this->request->post('hotel_id', null);
        
        // 非超级管理员只能操作自己酒店的设置
        if (!$this->currentUser->isSuperAdmin()) {
            $permittedHotelIds = $this->currentUser->getPermittedHotelIds();
            if (empty($permittedHotelIds)) {
                return $this->error('无权操作');
            }
            $hotelId = $permittedHotelIds[0];
        }
        
        $statusKey = $hotelId ? "online_data_auto_fetch_status_{$hotelId}" : 'online_data_auto_fetch_status';
        $status = cache($statusKey) ?: [];
        $status['enabled'] = (bool)$enabled;
        if (!isset($status['schedule_time'])) {
            $status['schedule_time'] = '10:00';
        }
        cache($statusKey, $status, 86400 * 30);
        
        OperationLog::record('online_data', 'toggle_auto_fetch', '切换自动获取状态: ' . ($enabled ? '开启' : '关闭') . " (门店ID: {$hotelId})", $this->currentUser->id);
        
        return $this->success(['enabled' => $status['enabled']], $enabled ? '已开启自动获取' : '已关闭自动获取');
    }
    
    /**
     * 设置自动获取时间
     */
    public function setFetchSchedule(): Response
    {
        $this->checkPermission();
        
        $hotelId = $this->request->post('hotel_id', null);
        $scheduleTime = $this->request->post('schedule_time', '10:00');
        
        // 验证时间格式
        if (!preg_match('/^([01]?[0-9]|2[0-3]):([0-5][0-9])$/', $scheduleTime)) {
            return $this->error('时间格式错误，请使用 HH:MM 格式');
        }
        
        // 非超级管理员只能操作自己酒店的设置
        if (!$this->currentUser->isSuperAdmin()) {
            $permittedHotelIds = $this->currentUser->getPermittedHotelIds();
            if (empty($permittedHotelIds)) {
                return $this->error('无权操作');
            }
            $hotelId = $permittedHotelIds[0];
        }
        
        $statusKey = $hotelId ? "online_data_auto_fetch_status_{$hotelId}" : 'online_data_auto_fetch_status';
        $status = cache($statusKey) ?: [];
        $status['schedule_time'] = $scheduleTime;
        if (!isset($status['enabled'])) {
            $status['enabled'] = false;
        }
        cache($statusKey, $status, 86400 * 30);
        
        OperationLog::record('online_data', 'set_schedule', "设置自动获取时间: {$scheduleTime} (门店ID: {$hotelId})", $this->currentUser->id);
        
        return $this->success(['schedule_time' => $scheduleTime], "设置成功，将在每天 {$scheduleTime} 自动获取数据");
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
        $hotelId = $this->request->get('hotel_id', '');
        $dataType = $this->request->get('data_type', '');
        
        $query = Db::name('online_daily_data')
            ->where('data_date', '>=', $startDate)
            ->where('data_date', '<=', $endDate);
        
        // 非超级管理员只能看自己酒店的数据
        if (!$this->currentUser->isSuperAdmin()) {
            $permittedHotelIds = $this->currentUser->getPermittedHotelIds();
            if (empty($permittedHotelIds)) {
                return $this->success(['aggregated' => [], 'summary' => [], 'chart_data' => [], 'hotel_ranking' => []]);
            }
            $query->whereIn('system_hotel_id', $permittedHotelIds);
        } elseif ($hotelId) {
            // 优先用 hotel_id 查询（携程酒店ID）
            $query->where('hotel_id', $hotelId);
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
            'hotel_count' => count(array_unique(array_column($data, 'hotel_id'))),
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
            $hotelId = $item['hotel_id'];
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

                    $hotelId = $item['hotelId'] ?? null;
                    if (!is_numeric($hotelId)) {
                        continue;
                    }
                    $hotelId = (int)$hotelId;
                    if ($hotelId !== -1 && $hotelId <= 0) {
                        continue;
                    }

                    $itemDate = $item['date'] ?? $item['dataDate'] ?? $item['statDate'] ?? $startDate;
                    if (!$itemDate || strtotime((string)$itemDate) === false) {
                        continue;
                    }
                    $itemDate = date('Y-m-d', strtotime((string)$itemDate));
                    $compareType = $hotelId > 0 ? 'self' : 'competitor_avg';
                    $hotelName = $compareType === 'self' ? '我的酒店' : '竞争圈平均';
                    $listExposure = (int)($item['listExposure'] ?? 0);
                    $detailExposure = (int)($item['detailExposure'] ?? 0);
                    $flowRate = isset($item['flowRate']) && is_numeric($item['flowRate'])
                        ? round((float)$item['flowRate'], 2)
                        : ($listExposure > 0 ? round($detailExposure / $listExposure * 100, 2) : 0);
                    $orderFillingNum = (int)($item['orderFillingNum'] ?? 0);
                    $orderSubmitNum = (int)($item['orderSubmitNum'] ?? 0);

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
                    $data = $this->filterOnlineDailyDataFields([
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
                    ]);

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

                $data = $this->filterOnlineDailyDataFields([
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
                ]);

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
     * 清除opcache缓存（开发调试用）
     */
    public function clearCache(): Response
    {
        $result = [];
        
        // 清除 opcache
        if (function_exists('opcache_reset')) {
            $result['opcache_reset'] = opcache_reset();
        } else {
            $result['opcache_reset'] = 'opcache not enabled';
        }
        
        // 清除 ThinkPHP 缓存
        try {
            \think\facade\Cache::clear();
            $result['think_cache_clear'] = true;
        } catch (\Exception $e) {
            $result['think_cache_clear'] = false;
            $result['think_cache_error'] = $e->getMessage();
        }
        
        // 删除运行时缓存文件
        $runtimePath = root_path() . 'runtime/cache/';
        if (is_dir($runtimePath)) {
            $files = glob($runtimePath . '*');
            $deleted = 0;
            foreach ($files as $file) {
                if (is_file($file) && unlink($file)) {
                    $deleted++;
                }
            }
            $result['runtime_cache_deleted'] = $deleted;
        }
        
        return json([
            'code' => 200,
            'message' => '缓存已清除',
            'data' => $result,
            'time' => time()
        ]);
    }

    /**
     * 定时任务触发接口（供外部cron调用）
     * 每分钟调用一次，检查是否有需要执行的自动获取任务
     */
    public function cronTrigger(): Response
    {
        // 简单的token验证
        $token = $this->request->header('X-Cron-Token') ?: $this->request->get('token');
        $configToken = \think\facade\Env::get('CRON_TOKEN', 'auto-fetch-2024');
        
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
            $scheduleTime = $status['schedule_time'] ?? '10:00';
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
            
            // 更新状态
            $status['last_run_time'] = date('Y-m-d H:i:s');
            $status['last_result'] = ['success' => $result['success'], 'message' => $result['message']];
            cache($statusKey, $status, 86400 * 30);
            
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
    private function executeAutoFetch(int $hotelId, string $dataDate): array
    {
        // 获取Cookies
        $cookiesKey = "online_data_cookies_hotel_{$hotelId}";
        $cookiesList = $this->getConfigList($cookiesKey);
        
        if (empty($cookiesList)) {
            $cookiesList = $this->getConfigList('online_data_cookies_list');
        }
        
        $cookies = '';
        foreach ($cookiesList as $item) {
            if (strpos($item['name'], 'ctrip') !== false) {
                $cookies = $item['cookies'];
                break;
            }
        }
        
        if (empty($cookies)) {
            return ['success' => false, 'message' => '未配置Cookies'];
        }
        
        try {
            $result = $this->sendHttpRequest(
                'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportCompeteHotelReport',
                ['nodeId' => '24588', 'startDate' => $dataDate, 'endDate' => $dataDate],
                $cookies
            );
            
            if (!$result['success']) {
                return ['success' => false, 'message' => '请求失败: ' . $result['error']];
            }
            
            $savedCount = $this->parseAndSaveData($result['data'], $dataDate, $dataDate, $hotelId);
            
            if ($savedCount === 0) {
                return ['success' => false, 'message' => '未获取到有效数据'];
            }
            
            \think\facade\Log::info("定时任务自动获取线上数据成功", ['hotel_id' => $hotelId, 'count' => $savedCount]);
            
            return ['success' => true, 'message' => "成功获取 {$savedCount} 条数据"];
            
        } catch (\Exception $e) {
            \think\facade\Log::error("定时任务自动获取异常", ['hotel_id' => $hotelId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => '异常: ' . $e->getMessage()];
        }
    }

    /**
     * 从系统配置读取列表
     */
    private function getConfigList(string $key): array
    {
        $raw = SystemConfig::getValue($key, '[]');
        $data = json_decode((string)$raw, true);
        return is_array($data) ? $data : [];
    }


    /**
     * 保存列表到系统配置
     */
    private function setConfigList(string $key, array $value): void
    {
        SystemConfig::setValue($key, json_encode($value, JSON_UNESCAPED_UNICODE), '在线数据Cookies配置');
    }

    /**
     * 更新线上数据
     */
    public function updateData(): Response
    {
        $this->checkPermission();

        $id = intval($this->request->post('id', 0));
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

        $id = intval($this->request->post('id', 0));
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

        if (empty($requestUrl)) {
            return $this->error('请求地址不能为空');
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
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);

            $response = @file_get_contents($requestUrl, false, $context);

            if ($response === false) {
                $error = error_get_last();
                return $this->error('请求失败: ' . ($error['message'] ?? 'Unknown error'));
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
                return $this->error('HTTP错误: ' . $httpCode);
            }

            $data = json_decode($decodedResponse, true);

            if ($data === null) {
                $snippet = trim(strip_tags(substr($decodedResponse, 0, 300)));
                return $this->error('JSON解析失败，可能Cookie/spidertoken已失效，或Payload/Header与携程当前请求不一致。响应摘要: ' . $snippet);
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

            OperationLog::record('online_data', 'fetch_ctrip_comments', '获取携程点评数据', $this->currentUser->id);

            return $this->success([
                'data' => $comments,
                'total' => $total,
                'saved_count' => 0,
                'upstream_code' => $upstreamCode,
                'upstream_message' => $upstreamMessage,
                'upstream_keys' => array_keys($data),
            ]);

        } catch (\Exception $e) {
            return $this->error('请求异常: ' . $e->getMessage());
        }
    }

    /**
     * 保存携程点评配置
     */
    public function saveCtripCommentConfig(): Response
    {
        $this->checkPermission();

        $name = $this->request->post('name', '');
        $hotelId = $this->request->post('hotel_id', '');
        $masterHotelId = $this->request->post('master_hotel_id', '');
        $requestUrl = $this->request->post('request_url', '');
        $cookies = $this->request->post('cookies', '');
        $token = $this->request->post('token', '');

        if (empty($name)) {
            $name = '携程点评-' . $hotelId;
        }

        if (empty($hotelId)) {
            return $this->error('Hotel ID不能为空');
        }

        // 获取配置列表
        $key = 'ctrip_comment_config_list';
        $list = $this->getConfigList($key);

        $id = md5($name . $hotelId);
        $list[$id] = [
            'id' => $id,
            'name' => $name,
            'hotel_id' => $hotelId,
            'master_hotel_id' => $masterHotelId,
            'request_url' => $requestUrl,
            'cookies' => $cookies,
            'token' => $token,
            'update_time' => date('Y-m-d H:i:s'),
            'created_at' => $list[$id]['created_at'] ?? date('Y-m-d H:i:s'),
        ];

        $this->setConfigList($key, $list);

        OperationLog::record('online_data', 'save_ctrip_comment_config', "保存携程点评配置: {$name}", $this->currentUser->id);

        return $this->success($list[$id], '配置保存成功');
    }

    /**
     * 获取携程点评配置列表
     */
    public function getCtripCommentConfigList(): Response
    {
        $this->checkPermission();

        $key = 'ctrip_comment_config_list';
        $list = $this->getConfigList($key);

        // 按更新时间降序排序
        $list = array_values($list);
        usort($list, function($a, $b) {
            $timeA = $a['update_time'] ?? $a['created_at'] ?? '1970-01-01 00:00:00';
            $timeB = $b['update_time'] ?? $b['created_at'] ?? '1970-01-01 00:00:00';
            return strcmp($timeB, $timeA);
        });

        return $this->success($list);
    }
}
