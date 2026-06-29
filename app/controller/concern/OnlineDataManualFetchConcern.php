<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\model\OperationLog;
use app\service\CtripManualFetchRequestService;
use app\service\CtripTrafficDisplayService;
use app\service\ManualOnlineFetchTaskService;
use app\service\MeituanManualFetchRequestService;
use think\Response;

trait OnlineDataManualFetchConcern
{
    public function fetchCtrip(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $requestData = $this->request->post();
        $url = CtripManualFetchRequestService::normalizeBusinessReportUrl((string)$this->request->post('url', ''));
        $nodeId = CtripManualFetchRequestService::normalizeNodeId((string)$this->request->post('node_id', ''));
        $cookies = trim((string)$this->request->post('cookies', ''));
        $authDataStr = $this->request->post('auth_data', '');
        $startDate = $this->request->post('start_date', '');
        $endDate = $this->request->post('end_date', '');
        $autoSave = $this->request->post('auto_save', true);
        $systemHotelId = $this->resolveOnlineDataSystemHotelId($this->request->post('system_hotel_id', null));
        $backgroundRequested = $this->isTruthyRequestValue($requestData['async'] ?? $requestData['background'] ?? false)
            && !$this->isTruthyRequestValue($requestData['background_task'] ?? false);

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
            try {
                $dateRangePlan = CtripManualFetchRequestService::normalizeDateRange($startDate, $endDate);
            } catch (\InvalidArgumentException $e) {
                return json(['code' => 400, 'message' => '日期范围无效', 'data' => null]);
            }
            $startDate = $dateRangePlan['start_date'];
            $endDate = $dateRangePlan['end_date'];
            $startTimestamp = $dateRangePlan['start_timestamp'];
            $endTimestamp = $dateRangePlan['end_timestamp'];

            if ($startTimestamp === false || $endTimestamp === false || $startTimestamp > $endTimestamp) {
                return json(['code' => 400, 'message' => '日期范围无效', 'data' => null]);
            }

            if ($backgroundRequested && $systemHotelId) {
                $manualFetchTaskService = new ManualOnlineFetchTaskService();
                $task = $manualFetchTaskService->createTask('ctrip', (int)$systemHotelId, $startDate, $endDate, is_array($requestData) ? $requestData : [], [
                    'authorization' => trim((string)$this->request->header('Authorization', '')),
                    'api_url' => rtrim($this->request->domain(), '/') . '/api/online-data/fetch-ctrip',
                    'user_id' => (int)($this->currentUser->id ?? 0),
                ]);
                if (!empty($task) && $manualFetchTaskService->launchTask($task)) {
                    return json([
                        'code' => 200,
                        'message' => '携程手动获取已提交后台执行，完成后会更新数据列表和通知',
                        'data' => [
                            'status' => 'running',
                            'task_id' => $task['task_id'] ?? '',
                            'platform' => 'ctrip',
                            'async' => true,
                            'saved_count' => 0,
                            'request_start_date' => $startDate,
                            'request_end_date' => $endDate,
                        ],
                    ]);
                }
            }

            $dateResults = [];
            $responseData = null;
            $rawResponse = '';
            $savedCount = 0;

            for ($timestamp = $startTimestamp; $timestamp <= $endTimestamp; $timestamp = strtotime('+1 day', $timestamp)) {
                $currentDate = date('Y-m-d', $timestamp);
                $postData = CtripManualFetchRequestService::buildDailyPostData($nodeId, $currentDate);

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

            if (CtripManualFetchRequestService::hasRepeatedMultiDayFingerprint($startDate, $endDate, $dateResults)) {
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
            if ($this->isTruthyRequestValue($requestData['background_task'] ?? false) && $systemHotelId) {
                $this->recordAutoFetchNotification((int)$systemHotelId, true, '携程手动获取完成', $displayDataDate, [
                    'saved_count' => $savedCount,
                    'platform_results' => [
                        ['platform' => 'ctrip', 'success' => true, 'saved_count' => $savedCount],
                    ],
                ], 'manual_fetch');
            }
            if ($this->currentUser && isset($this->currentUser->id)) {
                OperationLog::record('online_data', 'fetch_ctrip', "获取携程线上数据: {$savedCount}条", $this->currentUser->id, $systemHotelId);
            }

            $displayHotels = $this->buildCtripBusinessDisplayHotels(['date_results' => $dateResults]);
            $displaySummary = $this->buildCtripBusinessDisplaySummary($displayHotels);

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
                    'display_hotels' => $displayHotels,
                    'display_hotel_count' => count($displayHotels),
                    'display_summary' => $displaySummary,
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

        $requestData = $this->request->post();

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
        $backgroundRequested = $this->isTruthyRequestValue($requestData['async'] ?? $requestData['background'] ?? false)
            && !$this->isTruthyRequestValue($requestData['background_task'] ?? false);

        if (empty($cookies)) {
            return $this->error('请提供登录Cookies');
        }

        // 美团榜单接口需要一次性门店标识；缺失时直接返回明确状态，不猜值。
        $missingResourceFields = [];
        $missingResourceFields = MeituanManualFetchRequestService::missingRankResourceFields((string)$partnerId, (string)$poiId);
        if (!empty($missingResourceFields)) {
            return $this->error('需补充一次性门店标识：' . implode(' / ', $missingResourceFields), 400, [
                'reason' => 'missing_resource_id',
                'credential_level' => 'cookie_plus_resource_id',
                'missing_fields' => $missingResourceFields,
                'missing_text' => implode(' / ', $missingResourceFields),
            ]);
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
            $rankRequest = MeituanManualFetchRequestService::buildRankRequestParams(
                (string)$dataScope,
                (string)$partnerId,
                (string)$poiId,
                (string)$rankType,
                $dateRange,
                (string)$startDate,
                (string)$endDate
            );
            $params = $rankRequest['params'];
            $startDate = $rankRequest['start_date'];
            $endDate = $rankRequest['end_date'];
            $dateRange = $rankRequest['date_range'];

            if ($backgroundRequested && $systemHotelId) {
                $taskRequestData = is_array($requestData) ? $requestData : [];
                $taskRequestData['start_date'] = $startDate;
                $taskRequestData['end_date'] = $endDate;
                $taskRequestData['date_range'] = (string)($params['dateRange'] ?? $dateRange);
                $manualFetchTaskService = new ManualOnlineFetchTaskService();
                $task = $manualFetchTaskService->createTask('meituan', (int)$systemHotelId, $startDate, $endDate, $taskRequestData, [
                    'authorization' => trim((string)$this->request->header('Authorization', '')),
                    'api_url' => rtrim($this->request->domain(), '/') . '/api/online-data/fetch-meituan',
                    'user_id' => (int)($this->currentUser->id ?? 0),
                ]);
                if (!empty($task) && $manualFetchTaskService->launchTask($task)) {
                    return $this->success([
                        'status' => 'running',
                        'task_id' => $task['task_id'] ?? '',
                        'platform' => 'meituan',
                        'async' => true,
                        'saved_count' => 0,
                        'request_start_date' => $startDate,
                        'request_end_date' => $endDate,
                    ], '美团手动获取已提交后台执行，完成后会更新数据列表和通知');
                }
            }

            $result = $this->sendMeituanRequest($url, $params, $cookies, $authData);

            if (!$result['success']) {
                $this->recordCookieAlert('meituan', 'fetch-meituan', (string)($result['error'] ?? ''), $systemHotelId ? (int)$systemHotelId : null);
                return $this->error('请求失败: ' . $result['error']);
            }

            $responseData = $result['data'] ?? [];
            $selfMetricValues = $this->requestMeituanSelfMetricValues();
            $selfMetricStatus = !empty($selfMetricValues) ? 'provided' : 'missing';
            $selfMetricMessage = '';
            $selfMetricUpdateTime = '';
            $includeSelfTradeMetrics = $this->isTruthyRequestValue($requestData['include_self_trade_metrics'] ?? true);
            $requiredTradeMetricFields = ['roomNights', 'roomRevenue', 'salesRoomNights', 'sales', 'orderCount'];
            if ($includeSelfTradeMetrics && $this->hasMissingMeituanSelfMetricValues($selfMetricValues, $requiredTradeMetricFields)) {
                $selfMetricResult = $this->fetchMeituanSelfTradeMetricValues(
                    (string)$partnerId,
                    (string)$poiId,
                    (string)$startDate,
                    (string)$endDate,
                    (string)$cookies,
                    $authData,
                    (string)$dateRange
                );
                $tradeMetricValues = is_array($selfMetricResult['values'] ?? null) ? $selfMetricResult['values'] : [];
                foreach ($tradeMetricValues as $field => $value) {
                    if (!isset($selfMetricValues[$field]) || (float)$selfMetricValues[$field] <= 0) {
                        $selfMetricValues[$field] = $value;
                    }
                }
                if (!empty($tradeMetricValues)) {
                    $selfMetricStatus = $selfMetricStatus === 'missing'
                        ? 'trade_returned'
                        : $selfMetricStatus . '+trade_returned';
                } else {
                    $selfMetricStatus = $selfMetricStatus === 'missing'
                        ? (string)($selfMetricResult['status'] ?? 'failed')
                        : $selfMetricStatus . '+trade_' . (string)($selfMetricResult['status'] ?? 'failed');
                }
                $selfMetricMessage = (string)($selfMetricResult['message'] ?? '');
                $selfMetricUpdateTime = (string)($selfMetricResult['update_time'] ?? '');
            }
            $includeSelfTrafficMetrics = $this->isTruthyRequestValue($requestData['include_self_traffic_metrics'] ?? true);
            if ($includeSelfTrafficMetrics) {
                $selfTrafficResult = $this->fetchMeituanSelfTrafficMetricValues(
                    (string)$partnerId,
                    (string)$poiId,
                    (string)$startDate,
                    (string)$endDate,
                    (string)$cookies,
                    $authData,
                    (string)$dateRange
                );
                $trafficMetricValues = is_array($selfTrafficResult['values'] ?? null) ? $selfTrafficResult['values'] : [];
                foreach ($trafficMetricValues as $field => $value) {
                    if (!isset($selfMetricValues[$field]) || (float)$selfMetricValues[$field] <= 0) {
                        $selfMetricValues[$field] = $value;
                    }
                }
                if (!empty($trafficMetricValues)) {
                    $selfMetricStatus = $selfMetricStatus === 'missing'
                        ? 'traffic_returned'
                        : $selfMetricStatus . '+traffic_returned';
                    if ($selfMetricUpdateTime === '') {
                        $selfMetricUpdateTime = (string)($selfTrafficResult['update_time'] ?? '');
                    }
                } elseif ($selfMetricStatus === 'missing') {
                    $selfMetricStatus = 'traffic_' . (string)($selfTrafficResult['status'] ?? 'empty');
                    $selfMetricMessage = (string)($selfTrafficResult['message'] ?? $selfMetricMessage);
                }
            }
            $includeSelfBusinessMetrics = $this->isTruthyRequestValue($requestData['include_self_business_metrics'] ?? true);
            if ($includeSelfBusinessMetrics) {
                $selfBusinessResult = $this->fetchMeituanSelfHomeBusinessMetricValues(
                    (string)$partnerId,
                    (string)$poiId,
                    (string)$startDate,
                    (string)$endDate,
                    (string)$cookies,
                    $authData,
                    (string)$dateRange
                );
                $businessMetricValues = is_array($selfBusinessResult['values'] ?? null) ? $selfBusinessResult['values'] : [];
                foreach ($businessMetricValues as $field => $value) {
                    if (!isset($selfMetricValues[$field]) || (float)$selfMetricValues[$field] <= 0) {
                        $selfMetricValues[$field] = $value;
                    }
                }
                if (!empty($businessMetricValues)) {
                    $selfMetricStatus = $selfMetricStatus === 'missing'
                        ? 'business_returned'
                        : $selfMetricStatus . '+business_returned';
                    if ($selfMetricUpdateTime === '') {
                        $selfMetricUpdateTime = (string)($selfBusinessResult['update_time'] ?? '');
                    }
                } elseif ($selfMetricStatus === 'missing') {
                    $selfMetricStatus = 'business_' . (string)($selfBusinessResult['status'] ?? 'empty');
                    $selfMetricMessage = (string)($selfBusinessResult['message'] ?? $selfMetricMessage);
                }
            }
            $savedCount = 0;

            if ($autoSave && is_array($responseData) && !empty($responseData)) {
                $savedCount = $this->parseAndSaveMeituanData($responseData, $startDate, $endDate, $systemHotelId ? (int)$systemHotelId : null, [
                    'date_range' => (string)($params['dateRange'] ?? $dateRange),
                    'rank_type' => $rankType,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ]);
            }

            OperationLog::record('online_data', 'fetch_meituan', '获取美团线上数据', $this->currentUser->id, $systemHotelId ? (int)$systemHotelId : null);

            // 确保所有数据都是有效的UTF-8编码
            $responseData = $this->ensureUtf8($responseData);
            $displayContext = $this->buildMeituanBusinessDisplayContext();
            $displayContext['self_metric_values'] = $selfMetricValues;
            $displayContext['self_metric_status'] = $selfMetricStatus;
            $displayContext['rank_type'] = $rankType;
            $displayHotels = $this->buildMeituanBusinessDisplayHotels($responseData, $displayContext);
            $displaySummary = $this->buildMeituanBusinessDisplaySummary($displayHotels, $displayContext);
            $rawResponse = substr($this->ensureUtf8String($result['raw'] ?? ''), 0, 1000);
            if ($this->isTruthyRequestValue($requestData['background_task'] ?? false) && $systemHotelId) {
                $displayDataDate = $startDate === $endDate ? $startDate : $startDate . ' 至 ' . $endDate;
                $this->recordAutoFetchNotification((int)$systemHotelId, true, '美团手动获取完成', $displayDataDate, [
                    'saved_count' => $savedCount,
                    'platform_results' => [
                        ['platform' => 'meituan', 'success' => true, 'saved_count' => $savedCount],
                    ],
                ], 'manual_fetch');
            }

            // 直接构建响应数据并使用JSON_INVALID_UTF8_SUBSTITUTE处理无效字符
            $responseArray = [
                'code' => 200,
                'message' => '操作成功',
                'data' => [
                    'data' => $responseData,
                    'raw_response' => $rawResponse,
                    'saved_count' => $savedCount,
                    'self_metric_values' => $selfMetricValues,
                    'self_metric_status' => $selfMetricStatus,
                    'self_metric_message' => $selfMetricMessage,
                    'self_metric_update_time' => $selfMetricUpdateTime,
                    'display_hotels' => $displayHotels,
                    'display_hotel_count' => count($displayHotels),
                    'display_summary' => $displaySummary,
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
    public function meituanDisplayModel(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_view_online_data');

        try {
            $rows = $this->request->post('display_hotels', []);
            if (is_string($rows)) {
                $decodedRows = json_decode($rows, true);
                $rows = is_array($decodedRows) ? $decodedRows : [];
            }
            $displayGroups = $this->request->post('display_groups', $this->request->post('displayGroups', []));
            if (is_string($displayGroups)) {
                $decodedGroups = json_decode($displayGroups, true);
                $displayGroups = is_array($decodedGroups) ? $decodedGroups : [];
            }

            $displayContext = $this->buildMeituanBusinessDisplayContext();
            $displayHotels = is_array($displayGroups) && !empty($displayGroups)
                ? $this->mergeMeituanBusinessDisplayGroups($displayGroups, $displayContext)
                : $this->mergeMeituanBusinessDisplayHotels(is_array($rows) ? $rows : [], $displayContext);
            return $this->success([
                'display_hotels' => $displayHotels,
                'display_hotel_count' => count($displayHotels),
                'display_summary' => $this->buildMeituanBusinessDisplaySummary($displayHotels, $displayContext),
            ]);
        } catch (\Throwable $e) {
            return $this->error('构建美团展示模型失败: ' . $e->getMessage());
        }
    }

    public function competitorSummary(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_view_online_data');

        try {
            $currentUser = $this->request->user ?? $this->currentUser;
            $hotelId = trim((string)$this->request->get('hotel_id', $this->request->get('system_hotel_id', '')));
            $mode = strtolower(trim((string)$this->request->get('mode', '')));
            $includeByHotel = in_array($mode, ['by_hotel', 'all'], true)
                || $this->isExplicitTruthy($this->request->get('include_by_hotel', false));

            return $this->success($this->buildMeituanCompetitorSummaryPayload($hotelId, $currentUser, $includeByHotel));
        } catch (\Throwable $e) {
            return $this->error('获取竞对摘要失败: ' . $e->getMessage());
        }
    }

    public function fetchCtripTraffic(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $requestData = $this->request->post();
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
        $backgroundRequested = $this->isTruthyRequestValue($requestData['async'] ?? $requestData['background'] ?? false)
            && !$this->isTruthyRequestValue($requestData['background_task'] ?? false);

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

            if ($backgroundRequested && $systemHotelId) {
                $taskRequestData = is_array($requestData) ? $requestData : [];
                $taskRequestData['url'] = $requestUrl;
                $taskRequestData['platform'] = $platform;
                $taskRequestData['start_date'] = $startDate;
                $taskRequestData['end_date'] = $endDate;
                $manualFetchTaskService = new ManualOnlineFetchTaskService();
                $task = $manualFetchTaskService->createTask(strtolower($platform) . '_traffic', (int)$systemHotelId, $startDate, $endDate, $taskRequestData, [
                    'authorization' => trim((string)$this->request->header('Authorization', '')),
                    'api_url' => rtrim($this->request->domain(), '/') . '/api/online-data/fetch-ctrip-traffic',
                    'user_id' => (int)($this->currentUser->id ?? 0),
                ]);
                if (!empty($task) && $manualFetchTaskService->launchTask($task)) {
                    return $this->success([
                        'status' => 'running',
                        'task_id' => $task['task_id'] ?? '',
                        'platform' => strtolower($platform),
                        'async' => true,
                        'saved_count' => 0,
                        'request_start_date' => $startDate,
                        'request_end_date' => $endDate,
                    ], '携程流量手动获取已提交后台执行，完成后会更新数据列表和通知');
                }
            }

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
            $displayTrafficRows = CtripTrafficDisplayService::buildCtripTrafficDisplayRows($trafficRows);
            $displayTrafficSummary = CtripTrafficDisplayService::buildCtripTrafficDisplaySummary($displayTrafficRows);
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
            if ($this->isTruthyRequestValue($requestData['background_task'] ?? false) && $systemHotelId) {
                $displayDataDate = $startDate === $endDate ? $startDate : $startDate . ' 至 ' . $endDate;
                $this->recordAutoFetchNotification((int)$systemHotelId, true, '携程流量手动获取完成', $displayDataDate, [
                    'saved_count' => $savedCount,
                    'platform_results' => [
                        ['platform' => strtolower($platform), 'success' => true, 'saved_count' => $savedCount],
                    ],
                ], 'manual_fetch');
            }
            $derivedAnalysis = CtripTrafficDisplayService::buildAppTrafficDerivedAnalysis($trafficRows);

            OperationLog::record('online_data', 'fetch_ctrip_traffic', '获取携程流量数据', $this->currentUser->id, $systemHotelId);

            return $this->success([
                'data' => $responseData,
                'decoded_data' => $responseData,
                'traffic_rows' => $trafficRows,
                'display_traffic_rows' => $displayTrafficRows,
                'display_traffic_summary' => $displayTrafficSummary,
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

        $requestData = $this->request->post();
        $url = trim((string)$this->request->post('url', ''));
        $cookies = trim((string)$this->request->post('cookies', ''));
        $payloadJson = (string)$this->request->post('payload_json', (string)$this->request->post('extra_params', ''));
        $dateRange = (string)$this->request->post('date_range', 'yesterday');
        $startDate = (string)$this->request->post('start_date', '');
        $endDate = (string)$this->request->post('end_date', '');
        $apiType = $this->normalizeCtripAdsApiType((string)$this->request->post('api_type', 'effect_report'));
        $method = strtoupper(trim((string)$this->request->post('method', 'POST'))) ?: 'POST';
        $autoSave = $this->request->post('auto_save', true);
        $systemHotelId = $this->resolveOnlineDataSystemHotelId($this->request->post('system_hotel_id', null));
        $hotelId = trim((string)$this->request->post('hotel_id', ''));
        $hotelName = trim((string)$this->request->post('hotel_name', ''));
        $backgroundRequested = $this->isTruthyRequestValue($requestData['async'] ?? $requestData['background'] ?? false)
            && !$this->isTruthyRequestValue($requestData['background_task'] ?? false);

        if ($cookies === '') {
            return $this->error('请提供携程 Cookie');
        }
        if ($url === '') {
            $url = $this->defaultCtripAdsEffectReportUrl();
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

            if ($backgroundRequested && $systemHotelId) {
                $taskRequestData = is_array($requestData) ? $requestData : [];
                $taskRequestData['url'] = $url;
                $taskRequestData['start_date'] = $startDate;
                $taskRequestData['end_date'] = $endDate;
                $manualFetchTaskService = new ManualOnlineFetchTaskService();
                $task = $manualFetchTaskService->createTask('ctrip_ads', (int)$systemHotelId, $startDate, $endDate, $taskRequestData, [
                    'authorization' => trim((string)$this->request->header('Authorization', '')),
                    'api_url' => rtrim($this->request->domain(), '/') . '/api/online-data/fetch-ctrip-ads',
                    'user_id' => (int)($this->currentUser->id ?? 0),
                ]);
                if (!empty($task) && $manualFetchTaskService->launchTask($task)) {
                    return $this->success([
                        'status' => 'running',
                        'task_id' => $task['task_id'] ?? '',
                        'platform' => 'ctrip',
                        'async' => true,
                        'saved_count' => 0,
                        'request_start_date' => $startDate,
                        'request_end_date' => $endDate,
                    ], '携程广告手动获取已提交后台执行，完成后会更新数据列表和通知');
                }
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
            if ($this->isTruthyRequestValue($requestData['background_task'] ?? false) && $systemHotelId) {
                $displayDataDate = $startDate === $endDate ? $startDate : $startDate . ' 至 ' . $endDate;
                $this->recordAutoFetchNotification((int)$systemHotelId, true, '携程广告手动获取完成', $displayDataDate, [
                    'saved_count' => $savedCount,
                    'platform_results' => [
                        ['platform' => 'ctrip', 'success' => true, 'saved_count' => $savedCount],
                    ],
                ], 'manual_fetch');
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

    /**
     * 获取美团流量数据
     */
    public function fetchMeituanTraffic(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $requestData = $this->request->post();
        $url = trim((string)$this->request->post('url', ''));
        $cookies = trim((string)$this->request->post('cookies', ''));
        $partnerId = trim((string)$this->request->post('partner_id', ''));
        $poiId = trim((string)$this->request->post('poi_id', ''));
        $startDate = $this->request->post('start_date', '');
        $endDate = $this->request->post('end_date', '');
        $autoSave = $this->request->post('auto_save', true);
        $systemHotelId = $this->resolveOnlineDataSystemHotelId($this->request->post('system_hotel_id', null));
        $extraParamsStr = $this->request->post('extra_params', '');
        $backgroundRequested = $this->isTruthyRequestValue($requestData['async'] ?? $requestData['background'] ?? false)
            && !$this->isTruthyRequestValue($requestData['background_task'] ?? false);

        if (empty($url)) {
            return $this->error('请提供接口地址');
        }
        if (empty($cookies)) {
            return $this->error('请提供登录Cookies');
        }

        try {
            $extraParams = $this->parseJsonParams($extraParamsStr);
            [$partnerId, $poiId] = array_slice(MeituanManualFetchRequestService::resolveResourceIds($extraParams, $partnerId, $poiId), 0, 2);

            if ($partnerId === '') {
                return $this->error('请提供Partner ID（商家ID）');
            }
            if ($poiId === '') {
                return $this->error('请提供POI ID（门店ID）');
            }

            $trafficRequest = MeituanManualFetchRequestService::buildTrafficRequestParams($extraParams, $partnerId, $poiId, (string)$startDate, (string)$endDate);
            $params = $trafficRequest['params'];
            $startDate = $trafficRequest['start_date'];
            $endDate = $trafficRequest['end_date'];

            if ($backgroundRequested && $systemHotelId) {
                $taskRequestData = is_array($requestData) ? $requestData : [];
                $taskRequestData['start_date'] = $startDate;
                $taskRequestData['end_date'] = $endDate;
                $manualFetchTaskService = new ManualOnlineFetchTaskService();
                $task = $manualFetchTaskService->createTask('meituan_traffic', (int)$systemHotelId, $startDate, $endDate, $taskRequestData, [
                    'authorization' => trim((string)$this->request->header('Authorization', '')),
                    'api_url' => rtrim($this->request->domain(), '/') . '/api/online-data/fetch-meituan-traffic',
                    'user_id' => (int)($this->currentUser->id ?? 0),
                ]);
                if (!empty($task) && $manualFetchTaskService->launchTask($task)) {
                    return $this->success([
                        'status' => 'running',
                        'task_id' => $task['task_id'] ?? '',
                        'platform' => 'meituan',
                        'async' => true,
                        'saved_count' => 0,
                        'request_start_date' => $startDate,
                        'request_end_date' => $endDate,
                    ], '美团流量手动获取已提交后台执行，完成后会更新数据列表和通知');
                }
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

            if ($this->isTruthyRequestValue($requestData['background_task'] ?? false) && $systemHotelId) {
                $displayDataDate = $startDate === $endDate ? $startDate : $startDate . ' 至 ' . $endDate;
                $this->recordAutoFetchNotification((int)$systemHotelId, true, '美团流量手动获取完成', $displayDataDate, [
                    'saved_count' => $savedCount,
                    'platform_results' => [
                        ['platform' => 'meituan', 'success' => true, 'saved_count' => $savedCount],
                    ],
                ], 'manual_fetch');
            }

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

    /**
     * @param array<string, mixed> $values
     * @param array<int, string> $fields
     */
    private function hasMissingMeituanSelfMetricValues(array $values, array $fields): bool
    {
        $normalized = $this->normalizeMeituanSelfMetricValues($values);
        foreach ($fields as $field) {
            if (!isset($normalized[$field]) || (float)$normalized[$field] <= 0) {
                return true;
            }
        }
        return false;
    }

    private function fetchMeituanManualBusinessSection(string $section): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $requestData = $this->request->post();
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
        $backgroundRequested = $this->isTruthyRequestValue($requestData['async'] ?? $requestData['background'] ?? false)
            && !$this->isTruthyRequestValue($requestData['background_task'] ?? false);

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
            if ($backgroundRequested && $systemHotelId) {
                $taskRequestData = is_array($requestData) ? $requestData : [];
                $taskRequestData['start_date'] = $startDate;
                $taskRequestData['end_date'] = $endDate;
                $apiPath = $section === 'orders' ? '/api/online-data/fetch-meituan-orders' : '/api/online-data/fetch-meituan-ads';
                $manualFetchTaskService = new ManualOnlineFetchTaskService();
                $task = $manualFetchTaskService->createTask('meituan_' . $section, (int)$systemHotelId, $startDate, $endDate, $taskRequestData, [
                    'authorization' => trim((string)$this->request->header('Authorization', '')),
                    'api_url' => rtrim($this->request->domain(), '/') . $apiPath,
                    'user_id' => (int)($this->currentUser->id ?? 0),
                ]);
                if (!empty($task) && $manualFetchTaskService->launchTask($task)) {
                    return $this->success([
                        'status' => 'running',
                        'task_id' => $task['task_id'] ?? '',
                        'platform' => 'meituan',
                        'async' => true,
                        'saved_count' => 0,
                        'request_start_date' => $startDate,
                        'request_end_date' => $endDate,
                    ], $section === 'orders' ? '美团订单手动获取已提交后台执行，完成后会更新数据列表和通知' : '美团广告手动获取已提交后台执行，完成后会更新数据列表和通知');
                }
            }
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
            if ($this->isTruthyRequestValue($requestData['background_task'] ?? false) && $systemHotelId) {
                $displayDataDate = $startDate === $endDate ? $startDate : $startDate . ' 至 ' . $endDate;
                $sectionLabel = $section === 'orders' ? '订单' : '广告';
                $this->recordAutoFetchNotification((int)$systemHotelId, true, '美团' . $sectionLabel . '手动获取完成', $displayDataDate, [
                    'saved_count' => $savedCount,
                    'platform_results' => [
                        ['platform' => 'meituan', 'success' => true, 'saved_count' => $savedCount],
                    ],
                ], 'manual_fetch');
            }

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
     * Direct Meituan comment-detail collection is disabled.
     */
    public function fetchMeituanComments(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        return $this->commentCollectionDisabledResponse();
    }

    /**
     * 检查权限
     */
}
