<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\model\OperationLog;
use app\service\CtripCompetitionCirclePersistenceService;
use app\service\CtripManualFetchRequestService;
use app\service\CtripTrafficDisplayService;
use app\service\ManualOnlineFetchTaskService;
use app\service\MeituanManualIdentityService;
use app\service\MeituanManualFetchRequestService;
use app\service\OtaExecutionStageException;
use think\Response;
use think\facade\Db;

trait OnlineDataManualFetchConcern
{
    /**
     * @param array<string, mixed> $requestData
     * @return array<string, scalar|null>
     */
    private function sanitizeCtripManualFetchRequestData(array $requestData): array
    {
        return $this->sanitizePrimaryManualFetchRequestData($requestData, [
            'config_id',
            'system_hotel_id',
            'url',
            'node_id',
            'nodeId',
            'start_date',
            'end_date',
            'auto_save',
            'async',
            'background',
            'background_task',
            'masterHotelId',
            'master_hotel_id',
            'ota_hotel_id',
            'otaHotelId',
            'ctrip_hotel_id',
            'ctripHotelId',
            'platform_hotel_id',
            'platformHotelId',
            'hotel_name',
            'hotelName',
            'name',
        ], [], 'ctrip');
    }

    /**
     * @param array<string, mixed> $requestData
     * @return array<string, scalar|array<int, scalar|null>|null>
     */
    private function sanitizeMeituanManualFetchRequestData(array $requestData): array
    {
        return $this->sanitizePrimaryManualFetchRequestData($requestData, [
            'config_id',
            'system_hotel_id',
            'url',
            'partner_id',
            'poi_id',
            'rank_type',
            'data_scope',
            'date_range',
            'start_date',
            'end_date',
            'auto_save',
            'async',
            'background',
            'background_task',
            'include_self_trade_metrics',
            'include_self_traffic_metrics',
            'include_self_business_metrics',
            'competitor_room_count',
            'target_poi_id',
            'hotel_id',
            'self_room_nights',
            'selfRoomNights',
            'self_room_revenue',
            'selfRoomRevenue',
            'self_sales_room_nights',
            'selfSalesRoomNights',
            'self_sales',
            'selfSales',
            'self_sales_amount',
            'selfSalesAmount',
            'self_order_count',
            'selfOrderCount',
            'self_pay_order_count',
            'selfPayOrderCount',
            'self_view_conversion',
            'selfViewConversion',
            'self_pay_conversion',
            'selfPayConversion',
            'self_exposure',
            'selfExposure',
            'self_views',
            'selfViews',
            'hotel_name',
            'hotelName',
            'name',
        ], ['date_ranges'], 'meituan');
    }

    /**
     * @param array<string, mixed> $requestData
     * @return array<string, scalar|null>
     */
    private function sanitizeCtripTrafficExecutionRequestData(array $requestData): array
    {
        return $this->sanitizePrimaryManualFetchRequestData($requestData, [
            'config_id',
            'system_hotel_id',
            'url',
            'platform',
            'date_range',
            'start_date',
            'end_date',
            'fingerPrintKeys',
            'spiderVersion',
            'auto_save',
            'async',
            'background',
            'background_task',
        ], [], 'ctrip');
    }

    /**
     * @param array<string, mixed> $requestData
     * @return array<string, scalar|null>
     */
    private function sanitizeCtripAdsExecutionRequestData(array $requestData): array
    {
        return $this->sanitizePrimaryManualFetchRequestData($requestData, [
            'config_id',
            'system_hotel_id',
            'url',
            'api_type',
            'method',
            'date_range',
            'start_date',
            'end_date',
            'campaign_id',
            'hotel_id',
            'hotel_name',
            'auto_save',
            'async',
            'background',
            'background_task',
        ], [], 'ctrip');
    }

    /**
     * @param array<string, mixed> $requestData
     * @return array<string, scalar|null>
     */
    private function sanitizeMeituanTrafficExecutionRequestData(array $requestData): array
    {
        return $this->sanitizePrimaryManualFetchRequestData($requestData, [
            'config_id',
            'system_hotel_id',
            'url',
            'partner_id',
            'poi_id',
            'start_date',
            'end_date',
            'auto_save',
            'async',
            'background',
            'background_task',
        ], [], 'meituan');
    }

    /**
     * @param array<string, mixed> $requestData
     * @return array<string, scalar|null>
     */
    private function sanitizeMeituanBusinessExecutionRequestData(array $requestData): array
    {
        return $this->sanitizePrimaryManualFetchRequestData($requestData, [
            'config_id',
            'system_hotel_id',
            'url',
            'method',
            'partner_id',
            'poi_id',
            'shop_id',
            'hotel_name',
            'start_date',
            'end_date',
            'page_no',
            'pageNo',
            'page_size',
            'pageSize',
            'limit',
            'offset',
            'status',
            'date_range',
            'dateRange',
            'campaign_id',
            'campaignId',
            'plan_id',
            'planId',
            'promotion_id',
            'promotionId',
            'auto_save',
            'async',
            'background',
            'background_task',
        ], [], 'meituan');
    }

    /**
     * @param array<string, mixed> $requestData
     * @return array<string, scalar|null>
     */
    private function meituanManualBusinessParamsFromSanitizedRequest(array $requestData): array
    {
        $aliases = [
            'pageNo' => ['page_no', 'pageNo'],
            'pageSize' => ['page_size', 'pageSize'],
            'limit' => ['limit'],
            'offset' => ['offset'],
            'status' => ['status'],
            'dateRange' => ['date_range', 'dateRange'],
            'campaignId' => ['campaign_id', 'campaignId'],
            'planId' => ['plan_id', 'planId'],
            'promotionId' => ['promotion_id', 'promotionId'],
        ];
        $params = [];
        foreach ($aliases as $target => $fields) {
            foreach ($fields as $field) {
                if (array_key_exists($field, $requestData)) {
                    $params[$target] = $requestData[$field];
                    break;
                }
            }
        }
        return $params;
    }

    private function isContinuousManualFetchList(array $value): bool
    {
        $expectedIndex = 0;
        foreach ($value as $index => $_) {
            if ($index !== $expectedIndex) {
                return false;
            }
            $expectedIndex++;
        }
        return true;
    }

    /**
     * @param array<string, mixed> $requestData
     * @param array<int, string> $scalarFields
     * @param array<int, string> $safeListFields
     * @return array<string, scalar|array<int, scalar|null>|null>
     */
    private function sanitizePrimaryManualFetchRequestData(
        array $requestData,
        array $scalarFields,
        array $safeListFields = [],
        ?string $backgroundPlatform = null
    ): array {
        if (count($requestData) > 64) {
            throw new \InvalidArgumentException('执行参数过多', 400);
        }
        $scalarFieldSet = array_fill_keys($scalarFields, true);
        if ($backgroundPlatform !== null) {
            $scalarFieldSet['task_id'] = true;
        }
        $safeListFieldSet = array_fill_keys($safeListFields, true);
        $sanitized = [];
        $totalItems = count($requestData);
        $totalBytes = 0;

        foreach ($requestData as $field => $value) {
            if (!is_string($field)) {
                throw new \InvalidArgumentException('执行参数字段名无效', 400);
            }
            $totalBytes += strlen($field);
            if (isset($scalarFieldSet[$field])) {
                if (!is_scalar($value) && $value !== null) {
                    throw new \InvalidArgumentException('执行参数必须是简单值：' . $field, 400);
                }
                if (is_string($value)) {
                    $totalBytes += strlen($value);
                }
                $sanitized[$field] = $value;
            } elseif (isset($safeListFieldSet[$field])) {
                if (!is_array($value) || !$this->isContinuousManualFetchList($value) || count($value) > 32) {
                    throw new \InvalidArgumentException('执行参数列表无效：' . $field, 400);
                }
                $totalItems += count($value);
                foreach ($value as $item) {
                    if (!is_scalar($item) && $item !== null) {
                        throw new \InvalidArgumentException('执行参数列表只能包含简单值：' . $field, 400);
                    }
                    if (is_string($item)) {
                        $totalBytes += strlen($item);
                    }
                }
                $sanitized[$field] = array_values($value);
            } else {
                throw new \InvalidArgumentException('不支持的执行参数：' . $field, 400);
            }

            if ($totalItems > 96 || $totalBytes > 65536) {
                throw new \InvalidArgumentException('执行参数内容过大', 400);
            }
        }

        if ($backgroundPlatform !== null) {
            $this->validateManualFetchBackgroundTaskControl($sanitized, $backgroundPlatform);
        }

        return $sanitized;
    }

    /**
     * @param array<string, scalar|array<int, scalar|null>|null> $requestData
     */
    private function validateManualFetchBackgroundTaskControl(array $requestData, string $platform): void
    {
        $isBackgroundTask = $this->isTruthyManualFetchControlValue($requestData['background_task'] ?? false);
        $taskId = trim((string)($requestData['task_id'] ?? ''));

        if (!$isBackgroundTask && $taskId === '') {
            return;
        }
        if (!$isBackgroundTask || $taskId === '' || $this->isTruthyManualFetchControlValue($requestData['async'] ?? false)) {
            throw new \InvalidArgumentException('Invalid manual OTA background task control.', 400);
        }
        if (!in_array($platform, ['ctrip', 'meituan'], true) || strlen($taskId) > 128) {
            throw new \InvalidArgumentException('Invalid manual OTA background task control.', 400);
        }

        $hotelId = $this->strictPositiveOtaConfigHotelId($requestData['system_hotel_id'] ?? null);
        $pattern = '/^manual_' . preg_quote($platform, '/')
            . '_fetch_' . preg_quote((string)$hotelId, '/')
            . '_\d{14}_[a-f0-9]{8}$/D';
        if (preg_match($pattern, $taskId) !== 1) {
            throw new \InvalidArgumentException('Invalid manual OTA background task control.', 400);
        }
    }

    private function isTruthyManualFetchControlValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }
        if (!is_string($value)) {
            return false;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    public function fetchCtrip(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $rawRequestData = $this->request->post();
        try {
            if (!is_array($rawRequestData)) {
                throw new \InvalidArgumentException('Invalid manual OTA execution request schema.', 400);
            }
            $requestData = $this->sanitizeCtripManualFetchRequestData($rawRequestData);
            $configId = trim((string)($requestData['config_id'] ?? ''));
            $systemHotelId = $this->strictPositiveOtaConfigHotelId($requestData['system_hotel_id'] ?? null);

            return $this->withOtaCredentialForExecution(
                'ctrip',
                $configId,
                $systemHotelId,
                fn(array $credentialPayload): Response => $this->executeCtripManualFetch(
                    $requestData,
                    $credentialPayload,
                    $systemHotelId
                ),
                false,
                true
            );
        } catch (\InvalidArgumentException) {
            $detail = '请求包含不支持的执行字段或字段类型';
            return json([
                'code' => 400,
                'message' => '执行参数无效：' . $detail,
                'data' => [
                    'reason' => 'invalid_manual_fetch_request',
                    'detail' => $detail,
                ],
            ], 400);
        } catch (OtaExecutionStageException $e) {
            return $this->otaExecutionStageFailureResponse('ctrip_manual_fetch', $e);
        } catch (\Throwable $e) {
            return $this->otaUnknownExecutionFailureResponse('ctrip_manual_fetch', $e);
        }
    }

    /**
     * @param array<string, mixed> $requestData
     * @param array<string, mixed> $credentialPayload
     */
    private function executeCtripManualFetch(
        array $requestData,
        array $credentialPayload,
        int $systemHotelId
    ): Response {
        $url = CtripManualFetchRequestService::normalizeBusinessReportUrl((string)($requestData['url'] ?? ''));
        $nodeId = CtripManualFetchRequestService::normalizeNodeId((string)($requestData['node_id'] ?? $requestData['nodeId'] ?? ''));
        $cookies = trim((string)($credentialPayload['cookies'] ?? $credentialPayload['cookie'] ?? ''));
        $authDataStr = $credentialPayload['auth_data'] ?? $credentialPayload['authData'] ?? '';
        $startDate = $requestData['start_date'] ?? '';
        $endDate = $requestData['end_date'] ?? '';
        $autoSave = $this->isTruthyRequestValue($requestData['auto_save'] ?? true);
        $backgroundRequested = $this->isTruthyRequestValue($requestData['async'] ?? $requestData['background'] ?? false)
            && !$this->isTruthyRequestValue($requestData['background_task'] ?? false);

        if (empty($cookies)) {
            return json(['code' => 409, 'message' => 'OTA 凭据缺少登录 Cookies', 'data' => null], 409);
        }

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
                $task = $manualFetchTaskService->createTask('ctrip', (int)$systemHotelId, $startDate, $endDate, $requestData, [
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
            $insertedCount = 0;
            $updatedCount = 0;
            $competitionDataSourceId = 0;
            $competitionSyncTaskId = 0;
            $competitionPersistence = null;

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

            $displayHotels = $this->buildCtripBusinessDisplayHotels(['date_results' => $dateResults]);
            $displaySummary = $this->buildCtripBusinessDisplaySummary($displayHotels);
            $qunarVisitorQuality = $this->ctripBusinessQunarVisitorQuality($displayHotels);
            $qunarVisitorGap = $autoSave
                && $qunarVisitorQuality['row_count'] > 0
                && $qunarVisitorQuality['visitor_total'] <= 0;

            $identityCheck = null;
            if ($autoSave) {
                if ($systemHotelId) {
                    $identityCheck = $this->validateCtripManualBusinessHotelIdentity($dateResults, (int)$systemHotelId, $requestData);
                } else {
                    $identityCheck = $this->resolveCtripManualBusinessHotelIdentityFromResponse($dateResults, $requestData);
                    if (!empty($identityCheck['ok']) && !empty($identityCheck['target_system_hotel_id'])) {
                        $systemHotelId = $this->resolveOnlineDataSystemHotelId((int)$identityCheck['target_system_hotel_id']);
                    }
                }

                if (empty($identityCheck['ok'])) {
                    $payload = [
                        'data' => $responseData,
                        'date_results' => $dateResults,
                        'raw_response' => $rawResponse,
                        'saved_count' => 0,
                        'request_start_date' => $startDate,
                        'request_end_date' => $endDate,
                        'identity_check' => $identityCheck,
                        'display_hotels' => $displayHotels,
                        'display_hotel_count' => count($displayHotels),
                        'display_summary' => $displaySummary,
                        'save_status' => 'blocked',
                    ];
                    $responseCode = 200;
                    return json([
                        'code' => $responseCode,
                        'message' => (string)($identityCheck['message'] ?? '携程返回酒店身份未能自动匹配本系统门店，已获取但未入库。'),
                        'data' => array_merge([
                            'reason' => (string)($identityCheck['status'] ?? 'ctrip_hotel_identity_blocked'),
                        ], $payload),
                    ]);
                }
            }

            $fetchedAt = date('Y-m-d H:i:s');
            $selfHotelIds = $this->ctripCompetitionSelfHotelIds($identityCheck);
            $displayHotels = $this->tagCtripCompetitionDisplayRoles(
                $displayHotels,
                $selfHotelIds,
                $systemHotelId
            );

            if ($autoSave && $systemHotelId) {
                $competitionPersistence = new CtripCompetitionCirclePersistenceService();
                $competitionDataSourceId = $competitionPersistence->resolveOrCreateDataSource(
                    (int)$systemHotelId,
                    (int)($this->currentUser->id ?? 0),
                    [
                        'platform_hotel_id' => $selfHotelIds[0] ?? '',
                        'config_id' => (string)($requestData['config_id'] ?? ''),
                    ]
                );
                $competitionSyncTaskId = $competitionPersistence->startSyncTask(
                    $competitionDataSourceId,
                    (int)$systemHotelId,
                    (int)($this->currentUser->id ?? 0)
                );
            }

            try {
                foreach ($dateResults as &$dateResult) {
                    if (!$autoSave || !$systemHotelId || !$competitionPersistence) {
                        continue;
                    }
                    $traceId = CtripCompetitionCirclePersistenceService::buildCaptureTraceId([
                        'data_source_id' => $competitionDataSourceId,
                        'sync_task_id' => $competitionSyncTaskId,
                        'system_hotel_id' => (int)$systemHotelId,
                        'data_date' => (string)$dateResult['date'],
                        'fingerprint' => (string)($dateResult['fingerprint'] ?? ''),
                    ]);
                    $saveResult = $competitionPersistence->persistRows(
                        $this->extractCtripBusinessDataList($dateResult['data']),
                        (string)$dateResult['date'],
                        (int)$systemHotelId,
                        [
                            'self_hotel_ids' => $selfHotelIds,
                            'fetched_at' => $fetchedAt,
                            'data_source_id' => $competitionDataSourceId,
                            'sync_task_id' => $competitionSyncTaskId,
                            'source_trace_id' => $traceId,
                            'ingestion_method' => CtripCompetitionCirclePersistenceService::INGESTION_METHOD,
                        ]
                    );
                    $dateResult['saved_count'] = (int)$saveResult['saved_count'];
                    $dateResult['inserted_count'] = (int)$saveResult['inserted_count'];
                    $dateResult['updated_count'] = (int)$saveResult['updated_count'];
                    $dateResult['source_trace_id'] = $traceId;
                    $savedCount += $dateResult['saved_count'];
                    $insertedCount += $dateResult['inserted_count'];
                    $updatedCount += $dateResult['updated_count'];
                }
                unset($dateResult);

                if ($competitionPersistence && $competitionSyncTaskId > 0) {
                    $competitionPersistence->finishSyncTask(
                        $competitionSyncTaskId,
                        $competitionDataSourceId,
                        [
                            'saved_count' => $savedCount,
                            'inserted_count' => $insertedCount,
                            'updated_count' => $updatedCount,
                            'date_count' => count($dateResults),
                            'self_hotel_ids' => $selfHotelIds,
                        ]
                    );
                }
            } catch (\Throwable $e) {
                if ($competitionPersistence && $competitionSyncTaskId > 0) {
                    $competitionPersistence->failSyncTask(
                        $competitionSyncTaskId,
                        $competitionDataSourceId,
                        'competition_circle_persistence_failed'
                    );
                }
                throw $e;
            }

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

            return json([
                'code' => 200,
                'message' => !empty($identityCheck['warning']) && !empty($identityCheck['message'])
                    ? (string)$identityCheck['message'] . $this->ctripCompetitionSaveSummaryText($insertedCount, $updatedCount)
                    : ($qunarVisitorGap
                        ? '携程数据已获取；去哪儿访客为 0 仅作为字段缺口提示，不阻断携程竞争圈获取和入库。' . $this->ctripCompetitionSaveSummaryText($insertedCount, $updatedCount)
                        : '获取成功' . $this->ctripCompetitionSaveSummaryText($insertedCount, $updatedCount)),
                'data' => [
                    'data' => $responseData,
                    'date_results' => $dateResults,
                    'raw_response' => $rawResponse,
                    'saved_count' => $savedCount,
                    'inserted_count' => $insertedCount,
                    'updated_count' => $updatedCount,
                    'data_source_id' => $competitionDataSourceId ?: null,
                    'sync_task_id' => $competitionSyncTaskId ?: null,
                    'fetched_at' => $fetchedAt,
                    'request_start_date' => $startDate,
                    'request_end_date' => $endDate,
                    'identity_check' => $identityCheck,
                    'display_hotels' => $displayHotels,
                    'display_hotel_count' => count($displayHotels),
                    'display_summary' => $displaySummary,
                    'qunar_visitor_quality' => $qunarVisitorQuality,
                    'save_status' => $qunarVisitorGap
                        ? ($savedCount > 0 ? 'saved_with_qunar_visitor_gap' : 'no_saved_with_qunar_visitor_gap')
                        : ($autoSave ? 'saved_or_empty' : 'skipped'),
                    'save_operation' => $insertedCount > 0 && $updatedCount > 0
                        ? 'inserted_and_updated'
                        : ($insertedCount > 0 ? 'inserted' : ($updatedCount > 0 ? 'updated' : 'none')),
                ]
            ]);
        } catch (\DomainException) {
            return json([
                'code' => 409,
                'message' => '检测到旧版携程明文凭据；请先完成 Task6 迁移再执行采集',
                'data' => ['reason' => 'legacy_credential_requires_task6'],
            ], 409);
        } catch (\Throwable $e) {
            \think\facade\Log::error('Ctrip manual fetch failed.', [
                'exception_type' => get_debug_type($e),
            ]);
            return json(['code' => 500, 'message' => '请求异常', 'data' => null], 500);
        }
    }

    private function ctripCompetitionSelfHotelIds(?array $identityCheck): array
    {
        $ids = [];
        $capturedIds = (array)($identityCheck['captured_hotel_ids'] ?? []);
        $fields = $capturedIds !== [] ? ['captured_hotel_ids'] : ['expected_hotel_ids'];
        foreach ($fields as $field) {
            foreach ((array)($identityCheck[$field] ?? []) as $id) {
                if (is_array($id) || is_object($id)) {
                    continue;
                }
                $value = trim((string)$id);
                if ($value !== '') {
                    $ids[$value] = true;
                }
            }
        }
        return array_values(array_map('strval', array_keys($ids)));
    }

    private function tagCtripCompetitionDisplayRoles(
        array $displayHotels,
        array $selfHotelIds,
        int $systemHotelId
    ): array {
        $selfIdSet = array_fill_keys(array_map('strval', $selfHotelIds), true);
        $systemHotelName = $systemHotelId > 0 ? $this->getSystemHotelName($systemHotelId) : '';

        foreach ($displayHotels as &$hotel) {
            if (!is_array($hotel)) {
                continue;
            }
            $hotelId = trim((string)($hotel['hotelId'] ?? $hotel['poiId'] ?? ''));
            $hotelName = trim((string)($hotel['hotelName'] ?? ''));
            $isGenericSelf = $this->isCtripGenericSelfHotelName($hotelName);
            $isSelf = isset($selfIdSet[$hotelId]) || $isGenericSelf;
            $hotel['isSelf'] = $isSelf;
            $hotel['compareType'] = $isSelf ? 'self' : 'competitor';
            $hotel['systemHotelId'] = $systemHotelId;
            if ($isSelf && $systemHotelName !== '') {
                $hotel['systemHotelName'] = $systemHotelName;
            }
        }
        unset($hotel);
        return $displayHotels;
    }

    private function ctripCompetitionSaveSummaryText(int $insertedCount, int $updatedCount): string
    {
        if ($insertedCount <= 0 && $updatedCount <= 0) {
            return '';
        }
        $parts = [];
        if ($insertedCount > 0) {
            $parts[] = '新增' . $insertedCount . '条';
        }
        if ($updatedCount > 0) {
            $parts[] = '更新' . $updatedCount . '条';
        }
        return '（' . implode('，', $parts) . '）';
    }

    private function ctripBusinessQunarVisitorQuality(array $displayHotels): array
    {
        $rowCount = 0;
        $visitorTotal = 0.0;

        foreach ($displayHotels as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowCount++;
            $value = $row['qunarDetailVisitors']
                ?? $row['qunar_detail_visitors']
                ?? $row['views']
                ?? $row['uv']
                ?? $row['visitorCount']
                ?? $row['detailUv']
                ?? 0;
            $number = is_numeric($value) ? (float)$value : 0.0;
            if ($number > 0) {
                $visitorTotal += $number;
            }
        }

        $hasRows = $rowCount > 0;
        $ready = $hasRows && $visitorTotal > 0;

        return [
            'row_count' => $rowCount,
            'visitor_total' => $visitorTotal,
            'ready' => $ready,
            'status' => $ready ? 'ready' : ($hasRows ? 'partial_qunar_visitor_gap' : 'missing_rows'),
            'message' => $ready
                ? '去哪儿访客字段已返回有效值。'
                : ($hasRows
                    ? '去哪儿访客为 0 仅作为字段缺口提示，不阻断携程竞争圈获取和入库。'
                    : '本次未返回可展示的竞争圈行。'),
        ];
    }

    /**
     * 获取线上数据 - 美团ebooking接口
     * 支持竞对排名数据接口，支持时间维度选择
     */
    private function validateCtripManualBusinessHotelIdentity(array $dateResults, int $systemHotelId, array $requestData = []): array
    {
        $targetHotelName = $this->getSystemHotelName($systemHotelId);
        $config = $this->resolveCtripManualBusinessIdentityConfig($systemHotelId, $requestData);
        $expectedIds = array_values(array_map('strval', $this->extractExpectedCtripPlatformHotelIds($config, $systemHotelId)));
        $nodeIds = array_fill_keys($this->extractCtripNodeResourceIds($config), true);

        $capturedIds = [];
        foreach ($dateResults as $dateResult) {
            if (!is_array($dateResult)) {
                continue;
            }
            foreach ($this->extractCtripManualBusinessSelfHotelIds($dateResult['data'] ?? [], $systemHotelId) as $id) {
                $idValue = (string)$id;
                if ($this->isMeaningfulCtripPlatformHotelId($idValue, $systemHotelId) && !isset($nodeIds[$idValue])) {
                    $capturedIds[$idValue] = true;
                }
            }
        }
        $capturedIds = array_fill_keys(array_keys($capturedIds), true);
        foreach ($this->extractExpectedCtripPlatformHotelIds($requestData, $systemHotelId) as $id) {
            $idValue = (string)$id;
            if ($this->isMeaningfulCtripPlatformHotelId($idValue, $systemHotelId) && !isset($nodeIds[$idValue])) {
                $capturedIds[$idValue] = true;
            }
        }
        $capturedIds = array_values(array_map('strval', array_keys($capturedIds)));

        if ($expectedIds === []) {
            return $this->resolveMissingCtripPlatformHotelIdFromCapturedData($capturedIds, $systemHotelId, $targetHotelName);
        }

        if ($capturedIds === []) {
            return [
                'ok' => true,
                'status' => 'cookie_only_returned_current_hotel_id_missing',
                'warning' => true,
                'message' => '携程返回数据未识别到当前酒店身份，已按当前选择门店继续入库。',
                'target_system_hotel_id' => $systemHotelId,
                'target_hotel_name' => $targetHotelName,
                'captured_hotel_ids' => [],
                'expected_hotel_ids' => $expectedIds,
                'conflicts' => [],
            ];
        }

        $conflicts = $expectedIds !== [] ? $this->findCtripPlatformHotelIdConflicts($capturedIds, $systemHotelId) : [];
        $blockingConflicts = $expectedIds !== [] ? array_values(array_filter($conflicts, function (array $conflict) use ($expectedIds): bool {
            return $this->shouldBlockCtripCurrentHotelIdConflict((string)($conflict['hotel_id'] ?? ''), $expectedIds);
        })) : [];
        if ($blockingConflicts !== []) {
            return $this->buildCtripPlatformHotelConflictResult(
                $systemHotelId,
                $targetHotelName,
                $capturedIds,
                $expectedIds,
                $blockingConflicts
            );
        }

        if ($expectedIds !== [] && array_intersect($expectedIds, $capturedIds) === []) {
            return [
                'ok' => true,
                'status' => 'configured_platform_hotel_id_mismatch',
                'warning' => true,
                'message' => '携程返回酒店ID与已保存配置不一致；本次仍按当前选择门店归属并继续入库。请核对配置ID：' . implode('、', $expectedIds) . '；返回ID：' . implode('、', $capturedIds),
                'target_system_hotel_id' => $systemHotelId,
                'target_hotel_name' => $targetHotelName,
                'captured_hotel_ids' => $capturedIds,
                'expected_hotel_ids' => $expectedIds,
                'conflicts' => [],
                'verification_links' => $this->buildCtripPublicHotelVerificationLinks($capturedIds),
            ];
        }

        return [
            'ok' => true,
            'status' => 'matched',
            'warning' => false,
            'message' => null,
            'target_system_hotel_id' => $systemHotelId,
            'target_hotel_name' => $targetHotelName,
            'captured_hotel_ids' => $capturedIds,
            'expected_hotel_ids' => $expectedIds,
            'conflicts' => [],
        ];
    }

    private function resolveMissingCtripPlatformHotelIdFromCapturedData(array $capturedIds, int $systemHotelId, string $targetHotelName): array
    {
        $normalizedIds = [];
        foreach ($capturedIds as $capturedId) {
            $id = trim((string)$capturedId);
            if ($this->isMeaningfulCtripPlatformHotelId($id, $systemHotelId)) {
                $normalizedIds[$id] = true;
            }
        }
        $capturedIds = array_values(array_map('strval', array_keys($normalizedIds)));

        if ($capturedIds === []) {
            return [
                'ok' => true,
                'status' => 'platform_hotel_id_incomplete',
                'warning' => true,
                'message' => '当前门店未维护携程平台酒店ID，本次返回也未识别到本店ID；数据仍按当前选择门店归属并继续入库，请后续补齐ID以便核验。',
                'target_system_hotel_id' => $systemHotelId,
                'target_hotel_name' => $targetHotelName,
                'captured_hotel_ids' => [],
                'expected_hotel_ids' => [],
                'conflicts' => [],
                'verification_links' => [],
            ];
        }

        if (count($capturedIds) > 1) {
            return [
                'ok' => false,
                'status' => 'captured_platform_hotel_id_ambiguous',
                'message' => '携程返回数据识别到多个本店候选hotelId，系统无法确认归属，已取消入库；请在酒店管理中补充准确携程 hotelId 后重试。',
                'target_system_hotel_id' => $systemHotelId,
                'target_hotel_name' => $targetHotelName,
                'captured_hotel_ids' => $capturedIds,
                'expected_hotel_ids' => [],
                'conflicts' => [],
            ];
        }

        $bindingMatches = $this->findCtripSystemHotelMatchesByPlatformIds($capturedIds);
        $currentBindingMatches = array_values(array_filter($bindingMatches, static function (array $match) use ($systemHotelId): bool {
            return (int)($match['system_hotel_id'] ?? 0) === $systemHotelId;
        }));
        $blockingMatches = array_values(array_filter($bindingMatches, static function (array $match) use ($systemHotelId): bool {
            return (int)($match['system_hotel_id'] ?? 0) !== $systemHotelId;
        }));
        $historyConflicts = $this->findCtripPlatformHotelIdConflicts($capturedIds, $systemHotelId);
        $historyConflictHotelIds = $this->extractCtripConflictSystemHotelIds($historyConflicts, $systemHotelId);
        if (
            $blockingMatches === []
            && $historyConflicts !== []
            && $currentBindingMatches !== []
            && count($historyConflictHotelIds) === 1
            && $this->currentUserCanResolveCtripIdentityConflict()
        ) {
            $matchedIds = [];
            foreach ($currentBindingMatches as $match) {
                foreach (($match['matched_hotel_ids'] ?? []) as $matchedId) {
                    $matchedId = trim((string)$matchedId);
                    if ($matchedId !== '') {
                        $matchedIds[$matchedId] = true;
                    }
                }
            }
            $resolution = $this->buildCtripAdminHotelMergeResolution(
                (int)$historyConflictHotelIds[0],
                $systemHotelId,
                $historyConflicts,
                true
            );

            return [
                'ok' => true,
                'status' => 'admin_allowed_platform_hotel_history_conflict',
                'warning' => true,
                'message' => (string)$resolution['message'],
                'next_action' => (string)$resolution['next_action'],
                'target_system_hotel_id' => $systemHotelId,
                'target_hotel_name' => $targetHotelName,
                'captured_hotel_ids' => $capturedIds,
                'expected_hotel_ids' => array_keys($matchedIds ?: array_fill_keys($capturedIds, true)),
                'conflicts' => $historyConflicts,
                'admin_resolution' => $resolution,
            ];
        }

        if ($blockingMatches !== [] || $historyConflicts !== []) {
            return $this->buildCtripPlatformHotelConflictResult(
                $systemHotelId,
                $targetHotelName,
                $capturedIds,
                [],
                array_values(array_merge($blockingMatches, $historyConflicts))
            );
        }

        $platformHotelId = (string)$capturedIds[0];
        $this->persistCtripResolvedPlatformHotelIdForSystemHotel($systemHotelId, $platformHotelId);

        return [
            'ok' => true,
            'status' => 'request_scoped_platform_hotel_id',
            'warning' => true,
            'message' => '已从携程返回数据识别本店 hotelId，本次仅在当前请求内使用并继续入库；未改写携程凭据配置。',
            'target_system_hotel_id' => $systemHotelId,
            'target_hotel_name' => $targetHotelName,
            'captured_hotel_ids' => $capturedIds,
            'expected_hotel_ids' => [$platformHotelId],
            'conflicts' => [],
            'auto_bound' => false,
        ];
    }

    private function buildCtripPlatformHotelConflictResult(
        int $systemHotelId,
        string $targetHotelName,
        array $capturedIds,
        array $expectedIds,
        array $conflicts
    ): array {
        $result = [
            'ok' => false,
            'status' => 'platform_hotel_conflict',
            'message' => '携程数据已获取并可查看；返回酒店属于其他系统门店，本次未入库，避免错店数据覆盖。',
            'target_system_hotel_id' => $systemHotelId,
            'target_hotel_name' => $targetHotelName,
            'captured_hotel_ids' => $capturedIds,
            'expected_hotel_ids' => $expectedIds,
            'conflicts' => $conflicts,
        ];

        $resolution = $this->buildCtripAdminIdentityConflictResolution($systemHotelId, $conflicts);
        if ($resolution !== null) {
            $result['admin_resolution'] = $resolution;
            $result['message'] = (string)$resolution['message'];
            $result['next_action'] = (string)$resolution['next_action'];
        }

        return $result;
    }

    private function buildCtripAdminIdentityConflictResolution(int $systemHotelId, array $conflicts): ?array
    {
        if (!$this->currentUserCanResolveCtripIdentityConflict()) {
            return null;
        }

        $conflictHotelIds = $this->extractCtripConflictSystemHotelIds($conflicts, $systemHotelId);
        if (count($conflictHotelIds) !== 1) {
            return [
                'action' => 'delete_mismatched_ctrip_config',
                'scope' => 'admin_only',
                'can_continue_current_fetch' => false,
                'can_display_result' => true,
                'config_cleanup_required' => true,
                'source_system_hotel_id' => $systemHotelId,
                'target_system_hotel_id' => null,
                'message' => '携程数据已获取并可查看；当前配置返回了其他门店数据，本次未入库。请删除当前错绑携程配置；不同门店不会合并。',
                'next_action' => '删除当前门店下的错绑携程配置，再为正确门店重新配置。',
                'conflicts' => $conflicts,
            ];
        }

        return $this->buildCtripAdminHotelMergeResolution(
            $systemHotelId,
            (int)$conflictHotelIds[0],
            $conflicts,
            false
        );
    }

    /**
     * @return array<int, int>
     */
    private function extractCtripConflictSystemHotelIds(array $conflicts, int $currentSystemHotelId): array
    {
        $ids = [];
        foreach ($conflicts as $conflict) {
            if (!is_array($conflict)) {
                continue;
            }
            $id = (int)($conflict['system_hotel_id'] ?? 0);
            if ($id > 0 && $id !== $currentSystemHotelId) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    private function currentUserCanResolveCtripIdentityConflict(): bool
    {
        return $this->currentUser
            && method_exists($this->currentUser, 'isSuperAdmin')
            && $this->currentUser->isSuperAdmin();
    }

    private function buildCtripAdminHotelMergeResolution(
        int $sourceSystemHotelId,
        int $targetSystemHotelId,
        array $conflicts,
        bool $canContinueCurrentFetch
    ): array {
        $sourceHotelName = $this->getSystemHotelName($sourceSystemHotelId) ?: ('门店ID ' . $sourceSystemHotelId);
        $targetHotelName = $this->getSystemHotelName($targetSystemHotelId) ?: ('门店ID ' . $targetSystemHotelId);

        if ($canContinueCurrentFetch) {
            $action = 'clean_misbound_ctrip_history';
            $message = '当前门店【' . $targetHotelName . '】已明确绑定该携程酒店ID，本次可继续入库；历史上该ID还出现在【' . $sourceHotelName . '】的数据中，只清理错绑历史行，两家门店不会合并。';
            $nextAction = '核对并清理【' . $sourceHotelName . '】下属于【' . $targetHotelName . '】的错绑携程历史行。';
        } else {
            $action = 'delete_mismatched_ctrip_config';
            $message = '携程数据已获取并可查看；【' . $sourceHotelName . '】下的当前配置实际返回【' . $targetHotelName . '】数据，本次未入库。请删除这个错绑配置，两家门店不会合并。';
            $nextAction = '删除【' . $sourceHotelName . '】下当前错绑的携程配置，再为正确门店重新配置。';
        }

        return [
            'action' => $action,
            'scope' => 'admin_only',
            'can_continue_current_fetch' => $canContinueCurrentFetch,
            'can_display_result' => true,
            'config_cleanup_required' => !$canContinueCurrentFetch,
            'history_cleanup_required' => $canContinueCurrentFetch,
            'source_system_hotel_id' => $sourceSystemHotelId,
            'source_hotel_name' => $sourceHotelName,
            'target_system_hotel_id' => $targetSystemHotelId,
            'target_hotel_name' => $targetHotelName,
            'message' => $message,
            'next_action' => $nextAction,
            'conflicts' => $conflicts,
        ];
    }

    private function persistCtripResolvedPlatformHotelIdForSystemHotel(int $systemHotelId, string $platformHotelId): bool
    {
        $platformHotelId = trim($platformHotelId);
        if ($systemHotelId <= 0 || !$this->isMeaningfulCtripPlatformHotelId($platformHotelId, $systemHotelId)) {
            return false;
        }

        return false;
    }

    /**
     * Read only the identity metadata needed by manual Ctrip matching.
     *
     * @return array<int, array<string, scalar|null>>
     */
    private function readSafeCtripIdentityMetadataList(): array
    {
        $raw = Db::name('system_configs')
            ->where('config_key', 'ctrip_config_list')
            ->value('config_value');
        if ($raw === null || trim((string)$raw) === '') {
            return [];
        }

        $list = json_decode((string)$raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($list)) {
            throw new \RuntimeException('Stored Ctrip identity metadata is invalid.');
        }
        $allowedFields = array_fill_keys([
            'id',
            'config_id',
            'system_hotel_id',
            'hotel_id',
            'ctrip_hotel_id',
            'ctripHotelId',
            'ota_hotel_id',
            'otaHotelId',
            'masterHotelId',
            'master_hotel_id',
            'platform_hotel_id',
            'platformHotelId',
            'name',
            'node_id',
            'nodeId',
        ], true);
        $safeRows = [];

        foreach ($list as $storedKey => $config) {
            if (!is_array($config)) {
                throw new \RuntimeException('Stored Ctrip identity metadata is invalid.');
            }
            [, $legacySecrets] = $this->splitOtaConfigSecrets($config);
            if ($this->otaSecretPayloadHasNonEmptyScalar($legacySecrets)) {
                throw new \DomainException(
                    'Legacy Ctrip plaintext credential requires Task6 migration before identity matching.'
                );
            }

            $safe = [];
            foreach ($config as $field => $value) {
                if (!is_string($field) || !isset($allowedFields[$field])) {
                    continue;
                }
                if (!is_scalar($value) && $value !== null) {
                    throw new \RuntimeException('Stored Ctrip identity metadata is invalid.');
                }
                $safe[$field] = $value;
            }
            if (!isset($safe['id']) && is_string($storedKey) && $storedKey !== '') {
                $safe['id'] = $storedKey;
            }
            if (!isset($safe['config_id']) && isset($safe['id'])) {
                $safe['config_id'] = $safe['id'];
            }
            if ($safe !== []) {
                $safeRows[] = $safe;
            }
        }

        return $safeRows;
    }

    private function resolveCtripManualBusinessIdentityConfig(int $systemHotelId, array $requestData = []): array
    {
        $requestConfig = [];
        foreach (['masterHotelId', 'master_hotel_id', 'ota_hotel_id', 'otaHotelId', 'ctrip_hotel_id', 'ctripHotelId', 'platform_hotel_id', 'platformHotelId', 'node_id', 'nodeId'] as $key) {
            if (array_key_exists($key, $requestData)) {
                $requestConfig[$key] = $requestData[$key];
            }
        }

        foreach ($this->readSafeCtripIdentityMetadataList() as $config) {
            if (!is_array($config)) {
                continue;
            }
            $configHotelId = trim((string)($config['hotel_id'] ?? $config['system_hotel_id'] ?? ''));
            if ($configHotelId !== '' && $configHotelId === (string)$systemHotelId) {
                return array_merge($config, $requestConfig);
            }
        }

        return $requestConfig;
    }

    private function resolveCtripManualBusinessHotelIdentityFromResponse(array $dateResults, array $requestData = []): array
    {
        $capturedIds = $this->extractCtripManualBusinessCapturedSelfHotelIds($dateResults, 0, $requestData);
        return $this->resolveCtripSystemHotelIdentityFromPlatformIds($capturedIds, $requestData);
    }

    private function extractCtripManualBusinessCapturedSelfHotelIds(array $dateResults, int $systemHotelId = 0, array $requestData = []): array
    {
        $nodeIds = array_fill_keys($this->extractCtripNodeResourceIds($requestData), true);
        $capturedIds = [];
        foreach ($dateResults as $dateResult) {
            if (!is_array($dateResult)) {
                continue;
            }
            foreach ($this->extractCtripManualBusinessSelfHotelIds($dateResult['data'] ?? [], $systemHotelId) as $id) {
                $idValue = (string)$id;
                if ($this->isMeaningfulCtripPlatformHotelId($idValue, $systemHotelId) && !isset($nodeIds[$idValue])) {
                    $capturedIds[$idValue] = true;
                }
            }
        }
        return array_keys($capturedIds);
    }

    private function resolveCtripSystemHotelIdentityFromPlatformIds(array $platformHotelIds, array $requestData = []): array
    {
        $nodeIds = array_fill_keys($this->extractCtripNodeResourceIds($requestData), true);
        $capturedIds = [];
        foreach ($platformHotelIds as $id) {
            $value = trim((string)$id);
            if ($this->isMeaningfulCtripPlatformHotelId($value, 0) && !isset($nodeIds[$value])) {
                $capturedIds[$value] = true;
            }
        }
        $capturedIds = array_keys($capturedIds);

        if ($capturedIds === []) {
            return [
                'ok' => false,
                'status' => 'returned_current_hotel_id_missing',
                'message' => '携程返回数据未识别到当前酒店身份，已获取但未入库。请确认 Cookie 对应正确门店后重试。',
                'target_system_hotel_id' => null,
                'target_hotel_name' => '',
                'captured_hotel_ids' => [],
                'expected_hotel_ids' => [],
                'conflicts' => [],
                'auto_resolved' => false,
            ];
        }

        $matches = $this->findCtripSystemHotelMatchesByPlatformIds($capturedIds);
        if ($matches === []) {
            return [
                'ok' => false,
                'status' => 'platform_hotel_unbound',
                'message' => '携程数据已获取，但返回酒店ID未绑定到本系统门店，已取消入库。请在酒店管理中补充该携程 hotelId 后重试。',
                'target_system_hotel_id' => null,
                'target_hotel_name' => '',
                'captured_hotel_ids' => $capturedIds,
                'expected_hotel_ids' => [],
                'conflicts' => [],
                'auto_resolved' => false,
            ];
        }

        if (count($matches) > 1) {
            return [
                'ok' => false,
                'status' => 'platform_hotel_ambiguous',
                'message' => '携程返回酒店ID匹配到多个本系统门店，已取消入库。请先清理重复绑定。',
                'target_system_hotel_id' => null,
                'target_hotel_name' => '',
                'captured_hotel_ids' => $capturedIds,
                'expected_hotel_ids' => [],
                'conflicts' => array_values($matches),
                'auto_resolved' => false,
            ];
        }

        $match = reset($matches);
        $systemHotelId = (int)($match['system_hotel_id'] ?? 0);
        $targetHotelName = $this->getSystemHotelName($systemHotelId);
        $matchedIds = array_values(array_unique(array_map('strval', $match['matched_hotel_ids'] ?? [])));

        return [
            'ok' => true,
            'status' => 'auto_resolved',
            'message' => '已通过携程返回酒店ID自动匹配本系统门店。',
            'target_system_hotel_id' => $systemHotelId,
            'target_hotel_name' => $targetHotelName,
            'captured_hotel_ids' => $capturedIds,
            'expected_hotel_ids' => $matchedIds,
            'conflicts' => [],
            'auto_resolved' => true,
            'match_source' => $match['source'] ?? '',
            'match_source_ids' => $match['source_ids'] ?? [],
        ];
    }

    private function findCtripSystemHotelMatchesByPlatformIds(array $platformHotelIds): array
    {
        $wanted = array_fill_keys(array_values(array_unique(array_filter(array_map(
            static fn($value): string => trim((string)$value),
            $platformHotelIds
        ), static fn(string $value): bool => $value !== '' && $value !== '-1'))), true);
        if ($wanted === []) {
            return [];
        }

        $matches = [];
        foreach ($this->readSafeCtripIdentityMetadataList() as $config) {
            if (!is_array($config)) {
                continue;
            }
            $systemHotelId = (int)($config['system_hotel_id'] ?? $config['hotel_id'] ?? 0);
            if ($systemHotelId <= 0) {
                continue;
            }
            $ids = $this->extractExpectedCtripPlatformHotelIds($config, $systemHotelId);
            $this->appendCtripSystemHotelIdentityMatches($matches, $wanted, $systemHotelId, $ids, 'ctrip_config_list', (string)($config['id'] ?? ''));
        }

        foreach ($this->readCtripPlatformDataSourceIdentityRows() as $row) {
            $systemHotelId = (int)($row['system_hotel_id'] ?? 0);
            if ($systemHotelId <= 0) {
                continue;
            }
            $config = json_decode((string)($row['config_json'] ?? ''), true);
            if (!is_array($config)) {
                $config = [];
            }
            $config['system_hotel_id'] = $systemHotelId;
            $ids = $this->extractCtripBindingPlatformHotelIds($config, $systemHotelId);
            $this->appendCtripSystemHotelIdentityMatches($matches, $wanted, $systemHotelId, $ids, 'platform_data_sources', (string)($row['id'] ?? ''));
        }

        return array_values($matches);
    }

    private function appendCtripSystemHotelIdentityMatches(array &$matches, array $wanted, int $systemHotelId, array $candidateIds, string $source, string $sourceId = ''): void
    {
        $matchedIds = array_values(array_filter(array_unique(array_map(
            static fn($value): string => trim((string)$value),
            $candidateIds
        )), static fn(string $value): bool => $value !== '' && isset($wanted[$value])));
        if ($matchedIds === []) {
            return;
        }

        if (!isset($matches[$systemHotelId])) {
            $matches[$systemHotelId] = [
                'system_hotel_id' => $systemHotelId,
                'matched_hotel_ids' => [],
                'source' => $source,
                'source_ids' => [],
            ];
        }
        $matches[$systemHotelId]['matched_hotel_ids'] = array_values(array_unique(array_merge(
            $matches[$systemHotelId]['matched_hotel_ids'],
            $matchedIds
        )));
        if ($sourceId !== '') {
            $matches[$systemHotelId]['source_ids'][] = $sourceId;
            $matches[$systemHotelId]['source_ids'] = array_values(array_unique($matches[$systemHotelId]['source_ids']));
        }
        if (!str_contains((string)$matches[$systemHotelId]['source'], $source)) {
            $matches[$systemHotelId]['source'] .= '+' . $source;
        }
    }

    private function extractCtripBindingPlatformHotelIds(array $config, int $systemHotelId): array
    {
        $ids = array_fill_keys($this->extractExpectedCtripPlatformHotelIds($config, $systemHotelId), true);
        $nodeIds = array_fill_keys($this->extractCtripNodeResourceIds($config), true);
        foreach (['external_hotel_id', 'externalHotelId', 'request_hotel_id', 'requestHotelId'] as $key) {
            $value = trim((string)($config[$key] ?? ''));
            if ($this->isMeaningfulCtripPlatformHotelId($value, $systemHotelId) && !isset($nodeIds[$value])) {
                $ids[$value] = true;
            }
        }
        return array_keys($ids);
    }

    private function readCtripPlatformDataSourceIdentityRows(): array
    {
        try {
            return Db::name('platform_data_sources')
                ->field('id,system_hotel_id,config_json,enabled,status')
                ->where('platform', 'ctrip')
                ->where('enabled', 1)
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function extractCtripManualBusinessSelfHotelIds($responseData, int $systemHotelId): array
    {
        $ids = [];
        foreach ($this->extractCtripBusinessDataList($responseData) as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (!$this->isCtripManualBusinessSelfRow($item)) {
                continue;
            }
            $id = $this->resolveCtripPlatformHotelId($item);
            if ($this->isMeaningfulCtripPlatformHotelId($id, $systemHotelId)) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    private function isCtripManualBusinessSelfRow(array $item): bool
    {
        foreach (['hotelName', 'hotel_name', 'HotelName', 'name', 'metric_hotel_name'] as $key) {
            $hotelName = trim((string)($item[$key] ?? ''));
            if ($hotelName !== '' && $this->isCtripGenericSelfHotelName($hotelName)) {
                return true;
            }
        }

        foreach (['compare_type', 'compareType', 'role', 'scope', 'type'] as $key) {
            $value = strtolower(trim((string)($item[$key] ?? '')));
            if (in_array($value, ['self', 'current', 'mine', 'myhotel', 'currenthotel'], true)) {
                return true;
            }
        }

        foreach (['isSelf', 'is_self', 'isMine', 'is_mine', 'currentHotel', 'current_hotel'] as $key) {
            if (!empty($item[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $configs
     * @return array<string, mixed>
     */
    private function selectMeituanManualFetchConfigMetadata(
        array $configs,
        string $configId,
        int $systemHotelId
    ): array {
        $configId = trim($configId);
        if ($configId === '' || $systemHotelId <= 0) {
            return [];
        }

        $matches = [];
        foreach ($configs as $config) {
            if (!is_array($config)) {
                continue;
            }
            $candidateIds = [];
            foreach (['id', 'config_id'] as $field) {
                $value = trim((string)($config[$field] ?? ''));
                if ($value !== '') {
                    $candidateIds[$value] = true;
                }
            }
            if (count($candidateIds) !== 1 || !hash_equals($configId, (string)array_key_first($candidateIds))) {
                continue;
            }

            $hotelIds = [];
            foreach (['hotel_id', 'system_hotel_id'] as $field) {
                $value = $config[$field] ?? null;
                if (is_numeric($value) && (int)$value > 0) {
                    $hotelIds[(int)$value] = true;
                }
            }
            if (count($hotelIds) !== 1 || (int)array_key_first($hotelIds) !== $systemHotelId) {
                continue;
            }
            $matches[] = $config;
        }

        return count($matches) === 1 ? $matches[0] : [];
    }

    /** @return array<string, mixed> */
    private function resolveMeituanManualFetchConfigMetadata(string $configId, int $systemHotelId): array
    {
        return $this->selectMeituanManualFetchConfigMetadata(
            $this->getStoredMeituanConfigList(),
            $configId,
            $systemHotelId
        );
    }

    public function fetchMeituan(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $rawRequestData = $this->request->post();
        try {
            if (!is_array($rawRequestData)) {
                throw new \InvalidArgumentException('Invalid manual OTA execution request schema.', 400);
            }
            $requestData = $this->sanitizeMeituanManualFetchRequestData($rawRequestData);
            $configId = trim((string)($requestData['config_id'] ?? ''));
            $systemHotelId = $this->strictPositiveOtaConfigHotelId($requestData['system_hotel_id'] ?? null);

            return $this->withOtaCredentialForExecution(
                'meituan',
                $configId,
                $systemHotelId,
                fn(array $credentialPayload): Response => $this->executeMeituanManualFetch(
                    $requestData,
                    $credentialPayload,
                    $systemHotelId
                ),
                false,
                true
            );
        } catch (\InvalidArgumentException) {
            $detail = '请求包含不支持的执行字段或字段类型';
            return $this->error('执行参数无效：' . $detail, 400, [
                'reason' => 'invalid_manual_fetch_request',
                'detail' => $detail,
            ]);
        } catch (OtaExecutionStageException $e) {
            return $this->otaExecutionStageFailureResponse('meituan_manual_fetch', $e);
        } catch (\Throwable $e) {
            return $this->otaUnknownExecutionFailureResponse('meituan_manual_fetch', $e);
        }
    }

    /**
     * @param array<string, mixed> $requestData
     * @param array<string, mixed> $credentialPayload
     */
    private function executeMeituanManualFetch(
        array $requestData,
        array $credentialPayload,
        int $systemHotelId
    ): Response {

        try {
            $storedConfig = $this->resolveMeituanManualFetchConfigMetadata(
                trim((string)($requestData['config_id'] ?? '')),
                $systemHotelId
            );
        } catch (\Throwable $e) {
            \think\facade\Log::error('Meituan manual fetch config metadata read failed.', [
                'exception_type' => get_debug_type($e),
                'system_hotel_id' => $systemHotelId,
            ]);
            return $this->error('美团门店配置读取失败', 500, [
                'reason' => 'meituan_config_metadata_unavailable',
            ]);
        }
        if ($storedConfig === []) {
            return $this->error('美团门店配置不存在或与所选门店不匹配', 409, [
                'reason' => 'meituan_config_locator_mismatch',
            ]);
        }

        // 执行参数只携带配置定位；平台接口标识统一从已保存的门店配置读取。
        $url = trim((string)($storedConfig['url'] ?? ''))
            ?: 'https://eb.meituan.com/api/v1/ebooking/business/peer/rank/data/detail';
        $partnerId = trim((string)($storedConfig['partner_id'] ?? $storedConfig['partnerId'] ?? ''));
        $poiId = trim((string)($storedConfig['poi_id'] ?? $storedConfig['poiId'] ?? $storedConfig['store_id'] ?? $storedConfig['storeId'] ?? ''));
        $rankType = $requestData['rank_type'] ?? 'P_RZ';
        $dataScope = $storedConfig['data_scope'] ?? $storedConfig['dataScope'] ?? 'vpoi';
        $cookies = (string)($credentialPayload['cookies'] ?? $credentialPayload['cookie'] ?? '');
        $authDataStr = $credentialPayload['auth_data'] ?? $credentialPayload['authData'] ?? '';
        $dateRange = $requestData['date_range'] ?? '1'; // 时间维度：0=今日实时，1=昨日，7=近7天，30=近30天
        $startDate = $requestData['start_date'] ?? '';
        $endDate = $requestData['end_date'] ?? '';
        $autoSave = $requestData['auto_save'] ?? true;
        $backgroundRequested = $this->isTruthyRequestValue($requestData['async'] ?? $requestData['background'] ?? false)
            && !$this->isTruthyRequestValue($requestData['background_task'] ?? false);

        if (empty($cookies)) {
            return $this->error('OTA 凭据缺少登录 Cookies', 409);
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
                $taskRequestData = $requestData;
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
                return $this->error('请求失败: ' . $result['error'], 400, [
                    'reason' => $result['reason'] ?? 'meituan_request_failed',
                    'credential_status' => $result['credential_status'] ?? '',
                    'business_code' => $result['business_code'] ?? null,
                    'business_message' => $result['business_message'] ?? '',
                    'http_code' => $result['http_code'] ?? null,
                ]);
            }

            $responseData = $result['data'] ?? [];
            $selfMetricValues = $this->meituanSelfMetricValuesFromSanitizedRequest($requestData);
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
            if ((string)$dateRange === '7' && (float)($selfMetricValues['roomRevenue'] ?? 0) <= 0) {
                $dailyTradeResult = $this->fetchMeituanSelfDailyTradeMetricValues(
                    (string)$partnerId,
                    (string)$poiId,
                    (string)$startDate,
                    (string)$endDate,
                    (string)$cookies,
                    $authData
                );
                if (($dailyTradeResult['status'] ?? '') === 'returned') {
                    foreach (($dailyTradeResult['values'] ?? []) as $field => $value) {
                        if (!isset($selfMetricValues[$field]) || (float)$selfMetricValues[$field] <= 0) {
                            $selfMetricValues[$field] = $value;
                        }
                    }
                    $selfMetricStatus = $selfMetricStatus === 'missing'
                        ? 'daily_trade_returned'
                        : $selfMetricStatus . '+daily_trade_returned';
                } elseif ($selfMetricMessage === '') {
                    $selfMetricMessage = 'daily_trade_' . (string)($dailyTradeResult['status'] ?? 'empty');
                }
            }
            $savedCount = 0;

            $displayContext = $this->buildMeituanManualDisplayContext($requestData);
            $displayContext['self_metric_values'] = $selfMetricValues;
            $displayContext['self_metric_status'] = $selfMetricStatus;
            $displayContext['rank_type'] = $rankType;
            $displayHotels = $this->buildMeituanBusinessDisplayHotels($responseData, $displayContext);
            $identityCheck = $this->validateMeituanManualFetchHotelIdentity($displayHotels, $systemHotelId ? (int)$systemHotelId : null);
            if (!$identityCheck['ok']) {
                return $this->error((string)$identityCheck['message'], 422, [
                    'reason' => 'meituan_hotel_identity_mismatch',
                    'save_status' => 'blocked',
                    'expected_hotel_id' => $identityCheck['expected_hotel_id'] ?? null,
                    'expected_hotel_name' => $identityCheck['expected_hotel_name'] ?? '',
                    'returned_self_hotel_name' => $identityCheck['returned_self_hotel_name'] ?? '',
                    'returned_self_poi_id' => $identityCheck['returned_self_poi_id'] ?? '',
                    'display_hotels' => $displayHotels,
                    'display_hotel_count' => count($displayHotels),
                    'display_summary' => $this->buildMeituanBusinessDisplaySummary($displayHotels, $displayContext),
                ]);
            }

            if ($autoSave && is_array($responseData) && !empty($responseData)) {
                $savedCount = $this->parseAndSaveMeituanData($responseData, $startDate, $endDate, $systemHotelId ? (int)$systemHotelId : null, [
                    'date_range' => (string)($params['dateRange'] ?? $dateRange),
                    'rank_type' => $rankType,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'target_poi_id' => (string)$poiId,
                    'self_metric_values' => $selfMetricValues,
                    'self_metric_status' => $selfMetricStatus,
                ]);
            }

            OperationLog::record('online_data', 'fetch_meituan', '获取美团线上数据', $this->currentUser->id, $systemHotelId ? (int)$systemHotelId : null);

            // 确保所有数据都是有效的UTF-8编码
            $responseData = $this->ensureUtf8($responseData);
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
            \think\facade\Log::error('Meituan manual fetch failed.', [
                'exception_type' => get_debug_type($e),
            ]);
            return $this->error('请求异常', 500);
        }
    }

    /**
     * @param array<string, scalar|array<int, scalar|null>|null> $requestData
     * @return array<string, float>
     */
    private function meituanSelfMetricValuesFromSanitizedRequest(array $requestData): array
    {
        $aliases = [
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
        $values = [];
        foreach ($aliases as $field => $keys) {
            foreach ($keys as $key) {
                $raw = $requestData[$key] ?? null;
                if ($raw === null || $raw === '' || !is_numeric($raw)) {
                    continue;
                }
                $number = (float)$raw;
                if (in_array($field, ['viewConversion', 'payConversion'], true)) {
                    $number = $this->normalizeMeituanRatioMetric($number);
                }
                $values[$field] = $number;
                break;
            }
        }
        return $values;
    }

    /**
     * @param array<string, scalar|array<int, scalar|null>|null> $requestData
     * @return array<string, mixed>
     */
    private function buildMeituanManualDisplayContext(array $requestData): array
    {
        $dateRanges = $requestData['date_ranges'] ?? ($requestData['date_range'] ?? []);
        if (!is_array($dateRanges)) {
            $dateRanges = $dateRanges === '' || $dateRanges === null ? [] : [$dateRanges];
        }

        return [
            'competitor_room_count' => (int)($requestData['competitor_room_count'] ?? 0),
            'date_ranges' => array_values($dateRanges),
            'date_range' => $requestData['date_range'] ?? '',
            'rank_type' => (string)($requestData['rank_type'] ?? ''),
            'target_poi_id' => (string)($requestData['target_poi_id'] ?? $requestData['poi_id'] ?? ''),
            'system_hotel_id' => (int)($requestData['system_hotel_id'] ?? $requestData['hotel_id'] ?? 0),
            'start_date' => (string)($requestData['start_date'] ?? ''),
            'end_date' => (string)($requestData['end_date'] ?? ''),
            'self_metric_values' => $this->meituanSelfMetricValuesFromSanitizedRequest($requestData),
        ];
    }

    private function validateMeituanManualFetchHotelIdentity(array $displayHotels, ?int $systemHotelId): array
    {
        if ($systemHotelId === null || $systemHotelId <= 0 || empty($displayHotels)) {
            return ['ok' => true];
        }

        $expectedName = trim((string)(\think\facade\Db::name('hotels')->where('id', $systemHotelId)->value('name') ?? ''));
        if ($expectedName === '') {
            return ['ok' => true];
        }

        $selfRow = null;
        foreach ($displayHotels as $row) {
            if (is_array($row) && !empty($row['isSelf'])) {
                $selfRow = $row;
                break;
            }
        }
        if (!is_array($selfRow)) {
            return ['ok' => true];
        }

        $returnedName = trim((string)($selfRow['hotelName'] ?? $selfRow['name'] ?? ''));
        if ($returnedName === '' || $this->isLikelySameMeituanHotelName($expectedName, $returnedName)) {
            return ['ok' => true];
        }

        return [
            'ok' => false,
            'expected_hotel_id' => $systemHotelId,
            'expected_hotel_name' => $expectedName,
            'returned_self_hotel_name' => $returnedName,
            'returned_self_poi_id' => (string)($selfRow['poiId'] ?? $selfRow['poi_id'] ?? ''),
            'message' => "美团返回的本店为「{$returnedName}」，与当前选择门店「{$expectedName}」不一致，已阻止入库。请检查该门店美团 Partner/POI/Cookie 是否套用了其他门店配置。",
        ];
    }

    private function isLikelySameMeituanHotelName(string $expectedName, string $returnedName): bool
    {
        $expected = $this->normalizeMeituanHotelIdentityName($expectedName);
        $returned = $this->normalizeMeituanHotelIdentityName($returnedName);
        if ($expected === '' || $returned === '') {
            return true;
        }
        if (str_contains($returned, $expected) || str_contains($expected, $returned)) {
            return true;
        }

        foreach ($this->meituanHotelIdentityTokens($expected) as $token) {
            if (str_contains($returned, $token)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeMeituanHotelIdentityName(string $name): string
    {
        $name = mb_strtolower(trim($name), 'UTF-8');
        $name = preg_replace('/[\\s\\x{3000}\\(\\)（）\\[\\]【】\\-_,，.。·]+/u', '', $name) ?? $name;
        $name = str_replace([
            '酒店',
            '宾馆',
            '旅馆',
            '客栈',
            '民宿',
            '公寓',
            '国际',
            '连锁',
            '测试',
            '店',
        ], '', $name);
        return trim($name);
    }

    /**
     * @return array<int, string>
     */
    private function meituanHotelIdentityTokens(string $normalizedName): array
    {
        $tokens = [];
        $length = mb_strlen($normalizedName, 'UTF-8');
        if ($length <= 2) {
            return $normalizedName !== '' ? [$normalizedName] : [];
        }

        for ($i = 0; $i <= $length - 2; $i++) {
            $token = mb_substr($normalizedName, $i, 2, 'UTF-8');
            if (mb_strlen($token, 'UTF-8') < 2) {
                continue;
            }
            $tokens[] = $token;
        }

        return array_values(array_unique($tokens));
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

        $rawRequestData = $this->request->post();
        try {
            if (!is_array($rawRequestData)) {
                throw new \InvalidArgumentException('Invalid Ctrip traffic execution request schema.', 400);
            }
            $requestData = $this->sanitizeCtripTrafficExecutionRequestData($rawRequestData);
            $configId = trim((string)($requestData['config_id'] ?? ''));
            $systemHotelId = $this->strictPositiveOtaConfigHotelId($requestData['system_hotel_id'] ?? null);

            return $this->withOtaCredentialForExecution(
                'ctrip',
                $configId,
                $systemHotelId,
                fn(array $credentialPayload): Response => $this->executeCtripTrafficFetch(
                    $requestData,
                    $credentialPayload,
                    $systemHotelId
                ),
                false,
                true
            );
        } catch (\InvalidArgumentException) {
            return $this->error('执行参数无效；请仅提供 config_id、system_hotel_id 与允许的业务参数', 400);
        } catch (OtaExecutionStageException $e) {
            return $this->otaExecutionStageFailureResponse('ctrip_traffic_fetch', $e);
        } catch (\Throwable $e) {
            return $this->otaUnknownExecutionFailureResponse('ctrip_traffic_fetch', $e);
        }
    }

    /**
     * @param array<string, mixed> $requestData
     * @param array<string, mixed> $credentialPayload
     */
    private function executeCtripTrafficFetch(
        array $requestData,
        array $credentialPayload,
        int $systemHotelId
    ): Response {
        $url = (string)($requestData['url'] ?? '');
        $platform = (string)($requestData['platform'] ?? 'Ctrip');
        $dateRange = (string)($requestData['date_range'] ?? 'yesterday');
        $cookies = trim((string)($credentialPayload['cookies'] ?? $credentialPayload['cookie'] ?? ''));
        $authData = $credentialPayload['auth_data'] ?? $credentialPayload['authData'] ?? [];
        if (is_string($authData)) {
            $authData = json_decode($authData, true) ?: [];
        }
        $spiderkey = trim((string)(
            $credentialPayload['spiderkey']
            ?? $credentialPayload['spider_key']
            ?? (is_array($authData) ? ($authData['spiderkey'] ?? $authData['spider_key'] ?? '') : '')
        ));
        $startDate = (string)($requestData['start_date'] ?? '');
        $endDate = (string)($requestData['end_date'] ?? '');
        $autoSave = $this->isTruthyRequestValue($requestData['auto_save'] ?? true);
        $backgroundRequested = $this->isTruthyRequestValue($requestData['async'] ?? $requestData['background'] ?? false)
            && !$this->isTruthyRequestValue($requestData['background_task'] ?? false);

        if ($cookies === '') {
            return $this->error('OTA 凭据缺少登录 Cookies', 409);
        }

        try {
            $platform = ucfirst(strtolower($platform));
            if (!in_array($platform, ['Ctrip', 'Qunar'], true)) {
                return $this->error('platform 仅支持 Ctrip 或 Qunar');
            }

            [$startDate, $endDate] = $this->buildCtripTrafficDateRange($dateRange, $startDate, $endDate);
            $requestUrl = $this->normalizeCtripTrafficUrl($url);

            if ($backgroundRequested && $systemHotelId) {
                $taskRequestData = $requestData;
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

            $postData = [
                'platform' => $platform,
                'startDate' => $startDate,
                'endDate' => $endDate,
                'fingerPrintKeys' => (string)($requestData['fingerPrintKeys'] ?? ''),
                'spiderkey' => $spiderkey,
                'spiderVersion' => (string)($requestData['spiderVersion'] ?? '2.0'),
            ];

            $result = $this->sendCtripJsonRequest($requestUrl, $postData, $cookies);
            if (!empty($result['error'])) {
                $this->recordCookieAlert(strtolower($platform), 'fetch-ctrip-traffic', 'ctrip_traffic_request_failed', $systemHotelId);
                return $this->error('携程流量请求失败', 400, [
                    'reason' => 'ctrip_traffic_request_failed',
                    'http_code' => (int)($result['http_code'] ?? 0),
                ]);
            }

            $responseData = $result['decoded_data'];
            $apiError = $this->getCtripTrafficApiError($responseData);
            if ($apiError !== '') {
                $this->recordCookieAlert(strtolower($platform), 'fetch-ctrip-traffic', 'ctrip_traffic_api_rejected', $systemHotelId);
                return $this->error('携程流量接口未返回可用业务数据', 400, [
                    'reason' => 'ctrip_traffic_api_rejected',
                    'http_code' => (int)($result['http_code'] ?? 0),
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
        } catch (\InvalidArgumentException) {
            return $this->error('携程流量业务参数无效', 400);
        } catch (\Throwable $e) {
            \think\facade\Log::error('Ctrip traffic fetch failed.', [
                'exception_type' => get_debug_type($e),
            ]);
            return $this->error('请求异常', 500);
        }
    }

    /**
     * 直接获取携程金字塔广告数据
     */
    public function fetchCtripAds(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $rawRequestData = $this->request->post();
        try {
            if (!is_array($rawRequestData)) {
                throw new \InvalidArgumentException('Invalid Ctrip ads execution request schema.', 400);
            }
            $requestData = $this->sanitizeCtripAdsExecutionRequestData($rawRequestData);
            $configId = trim((string)($requestData['config_id'] ?? ''));
            $systemHotelId = $this->strictPositiveOtaConfigHotelId($requestData['system_hotel_id'] ?? null);

            return $this->withOtaCredentialForExecution(
                'ctrip',
                $configId,
                $systemHotelId,
                fn(array $credentialPayload): Response => $this->executeCtripAdsFetch(
                    $requestData,
                    $credentialPayload,
                    $systemHotelId
                ),
                false,
                true
            );
        } catch (\InvalidArgumentException) {
            return $this->error('执行参数无效；请仅提供 config_id、system_hotel_id 与允许的业务参数', 400);
        } catch (OtaExecutionStageException $e) {
            return $this->otaExecutionStageFailureResponse('ctrip_ads_fetch', $e);
        } catch (\Throwable $e) {
            return $this->otaUnknownExecutionFailureResponse('ctrip_ads_fetch', $e);
        }
    }

    /**
     * @param array<string, mixed> $requestData
     * @param array<string, mixed> $credentialPayload
     */
    private function executeCtripAdsFetch(
        array $requestData,
        array $credentialPayload,
        int $systemHotelId
    ): Response {
        $url = trim((string)($requestData['url'] ?? ''));
        $cookies = trim((string)($credentialPayload['cookies'] ?? $credentialPayload['cookie'] ?? ''));
        $dateRange = (string)($requestData['date_range'] ?? 'yesterday');
        $startDate = (string)($requestData['start_date'] ?? '');
        $endDate = (string)($requestData['end_date'] ?? '');
        $apiType = $this->normalizeCtripAdsApiType((string)($requestData['api_type'] ?? 'effect_report'));
        $method = strtoupper(trim((string)($requestData['method'] ?? 'POST'))) ?: 'POST';
        $autoSave = $this->isTruthyRequestValue($requestData['auto_save'] ?? true);
        $hotelId = trim((string)($requestData['hotel_id'] ?? ''));
        $hotelName = trim((string)($requestData['hotel_name'] ?? ''));
        $backgroundRequested = $this->isTruthyRequestValue($requestData['async'] ?? $requestData['background'] ?? false)
            && !$this->isTruthyRequestValue($requestData['background_task'] ?? false);

        if ($cookies === '') {
            return $this->error('OTA 凭据缺少登录 Cookies', 409);
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
            $payload = [];
            $payload = $this->buildCtripAdsDirectPayload($payload, $startDate, $endDate, $apiType);
            $campaignId = trim((string)($requestData['campaign_id'] ?? ''));
            if ($campaignId !== '') {
                $payload['campaignId'] = $payload['campaignId'] ?? $campaignId;
                $payload['campaign_id'] = $payload['campaign_id'] ?? $campaignId;
            }

            if ($backgroundRequested && $systemHotelId) {
                $taskRequestData = $requestData;
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
                $this->recordCookieAlert('ctrip', 'fetch-ctrip-ads', 'ctrip_ads_request_failed', $systemHotelId);
                return $this->error('携程广告请求失败', 400, [
                    'reason' => 'ctrip_ads_request_failed',
                    'http_code' => (int)($result['http_code'] ?? 0),
                    'request_url' => $result['request_url'] ?? $url,
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
        } catch (\InvalidArgumentException) {
            return $this->error('携程广告业务参数无效', 400);
        } catch (\Throwable $e) {
            \think\facade\Log::error('Ctrip ads fetch failed.', [
                'exception_type' => get_debug_type($e),
            ]);
            return $this->error('请求异常', 500);
        }
    }

    /**
     * 获取美团流量数据
     */
    public function fetchMeituanTraffic(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $rawRequestData = $this->request->post();
        try {
            if (!is_array($rawRequestData)) {
                throw new \InvalidArgumentException('Invalid Meituan traffic execution request schema.', 400);
            }
            $requestData = $this->sanitizeMeituanTrafficExecutionRequestData($rawRequestData);
            $configId = trim((string)($requestData['config_id'] ?? ''));
            $systemHotelId = $this->strictPositiveOtaConfigHotelId($requestData['system_hotel_id'] ?? null);

            return $this->withOtaCredentialForExecution(
                'meituan',
                $configId,
                $systemHotelId,
                fn(array $credentialPayload): Response => $this->executeMeituanTrafficFetch(
                    $requestData,
                    $credentialPayload,
                    $systemHotelId
                ),
                false,
                true
            );
        } catch (\InvalidArgumentException) {
            return $this->error('执行参数无效；请仅提供 config_id、system_hotel_id 与允许的业务参数', 400);
        } catch (OtaExecutionStageException $e) {
            return $this->otaExecutionStageFailureResponse('meituan_traffic_fetch', $e);
        } catch (\Throwable $e) {
            return $this->otaUnknownExecutionFailureResponse('meituan_traffic_fetch', $e);
        }
    }

    /**
     * @param array<string, mixed> $requestData
     * @param array<string, mixed> $credentialPayload
     */
    private function executeMeituanTrafficFetch(
        array $requestData,
        array $credentialPayload,
        int $systemHotelId
    ): Response {
        $url = trim((string)($requestData['url'] ?? ''));
        $cookies = trim((string)($credentialPayload['cookies'] ?? $credentialPayload['cookie'] ?? ''));
        $authData = $credentialPayload['auth_data'] ?? $credentialPayload['authData'] ?? [];
        if (is_string($authData)) {
            $authData = json_decode($authData, true) ?: [];
        }
        if (!is_array($authData)) {
            $authData = [];
        }
        try {
            $storedConfig = $this->resolveMeituanManualFetchConfigMetadata(
                trim((string)($requestData['config_id'] ?? '')),
                $systemHotelId
            );
            $identity = (new MeituanManualIdentityService())->resolve($requestData, $storedConfig, 'traffic');
        } catch (\InvalidArgumentException $e) {
            return $this->error('美团门店身份无效', $e->getCode() ?: 409, ['reason' => 'meituan_platform_identity_invalid']);
        }
        $partnerId = $identity['partner_id'];
        $poiId = $identity['poi_id'];
        $startDate = (string)($requestData['start_date'] ?? '');
        $endDate = (string)($requestData['end_date'] ?? '');
        $autoSave = $this->isTruthyRequestValue($requestData['auto_save'] ?? true);
        $backgroundRequested = $this->isTruthyRequestValue($requestData['async'] ?? $requestData['background'] ?? false)
            && !$this->isTruthyRequestValue($requestData['background_task'] ?? false);

        if (empty($url)) {
            return $this->error('请提供接口地址');
        }
        if (empty($cookies)) {
            return $this->error('OTA 凭据缺少登录 Cookies', 409);
        }

        try {
            if ($partnerId === '') {
                return $this->error('请提供Partner ID（商家ID）');
            }
            if ($poiId === '') {
                return $this->error('请提供POI ID（门店ID）');
            }

            $trafficRequest = MeituanManualFetchRequestService::buildTrafficRequestParams([], $partnerId, $poiId, (string)$startDate, (string)$endDate);
            $params = $trafficRequest['params'];
            $startDate = $trafficRequest['start_date'];
            $endDate = $trafficRequest['end_date'];

            if ($backgroundRequested && $systemHotelId) {
                $taskRequestData = $requestData;
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

            $result = $this->sendMeituanRequest($url, $params, $cookies, $authData);
            if (!$result['success']) {
                $this->recordCookieAlert('meituan', 'fetch-meituan-traffic', 'meituan_traffic_request_failed', $systemHotelId);
                return $this->error('美团流量请求失败', 400, [
                    'reason' => 'meituan_traffic_request_failed',
                    'http_code' => (int)($result['http_code'] ?? 0),
                ]);
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
            \think\facade\Log::error('Meituan traffic fetch failed.', [
                'exception_type' => get_debug_type($e),
            ]);
            return $this->error('请求异常', 500);
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

        $rawRequestData = $this->request->post();
        try {
            if (!in_array($section, ['orders', 'ads'], true) || !is_array($rawRequestData)) {
                throw new \InvalidArgumentException('Invalid Meituan business execution request schema.', 400);
            }
            $requestData = $this->sanitizeMeituanBusinessExecutionRequestData($rawRequestData);
            $configId = trim((string)($requestData['config_id'] ?? ''));
            $systemHotelId = $this->strictPositiveOtaConfigHotelId($requestData['system_hotel_id'] ?? null);

            return $this->withOtaCredentialForExecution(
                'meituan',
                $configId,
                $systemHotelId,
                fn(array $credentialPayload): Response => $this->executeMeituanManualBusinessSection(
                    $section,
                    $requestData,
                    $credentialPayload,
                    $systemHotelId
                ),
                false,
                true
            );
        } catch (\InvalidArgumentException) {
            return $this->error('执行参数无效；请仅提供 config_id、system_hotel_id 与允许的业务参数', 400);
        } catch (OtaExecutionStageException $e) {
            return $this->otaExecutionStageFailureResponse('meituan_' . $section . '_fetch', $e);
        } catch (\Throwable $e) {
            return $this->otaUnknownExecutionFailureResponse('meituan_' . $section . '_fetch', $e);
        }
    }

    /**
     * @param array<string, mixed> $requestData
     * @param array<string, mixed> $credentialPayload
     */
    private function executeMeituanManualBusinessSection(
        string $section,
        array $requestData,
        array $credentialPayload,
        int $systemHotelId
    ): Response {
        $url = trim((string)($requestData['url'] ?? ''));
        $cookies = trim((string)($credentialPayload['cookies'] ?? $credentialPayload['cookie'] ?? ''));
        try {
            $storedConfig = $this->resolveMeituanManualFetchConfigMetadata(
                trim((string)($requestData['config_id'] ?? '')),
                $systemHotelId
            );
            $identity = (new MeituanManualIdentityService())->resolve($requestData, $storedConfig, $section);
        } catch (\InvalidArgumentException $e) {
            return $this->error('美团门店身份无效', $e->getCode() ?: 409, ['reason' => 'meituan_platform_identity_invalid']);
        }
        $partnerId = $identity['partner_id'];
        $poiId = $identity['poi_id'];
        $shopId = $identity['shop_id'];
        $startDate = (string)($requestData['start_date'] ?? '');
        $endDate = (string)($requestData['end_date'] ?? '');
        $method = strtoupper(trim((string)($requestData['method'] ?? 'GET'))) ?: 'GET';
        $autoSave = $this->isTruthyRequestValue($requestData['auto_save'] ?? true);
        $hotelName = trim((string)($requestData['hotel_name'] ?? ''));
        $backgroundRequested = $this->isTruthyRequestValue($requestData['async'] ?? $requestData['background'] ?? false)
            && !$this->isTruthyRequestValue($requestData['background_task'] ?? false);

        if ($url === '') {
            return $this->error('请提供 Network 中的接口 Request URL');
        }
        if ($cookies === '') {
            return $this->error('OTA 凭据缺少登录 Cookies', 409);
        }
        if (!in_array($method, ['GET', 'POST'], true)) {
            return $this->error('请求方式仅支持 GET 或 POST');
        }

        try {
            $shopId = $shopId !== '' ? $shopId : $poiId;

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
            ], $this->meituanManualBusinessParamsFromSanitizedRequest($requestData));
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
                $taskRequestData = $requestData;
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
                $this->recordCookieAlert('meituan', 'fetch-meituan-' . $section, 'meituan_' . $section . '_request_failed', $systemHotelId);
                return $this->error('美团' . ($section === 'orders' ? '订单' : '广告') . '请求失败', 400, [
                    'reason' => 'meituan_' . $section . '_request_failed',
                    'http_code' => $result['http_code'] ?? 0,
                    'request_url' => $result['request_url'] ?? $url,
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
        } catch (\InvalidArgumentException) {
            return $this->error('美团业务参数无效', 400);
        } catch (\Throwable $e) {
            \think\facade\Log::error('Meituan manual business fetch failed.', [
                'exception_type' => get_debug_type($e),
                'section' => $section,
            ]);
            return $this->error('请求异常', 500);
        }
    }

    /**
     * Meituan comment detail collection stays disabled; this route uses the aggregate Profile capture path.
     */
    public function fetchMeituanComments(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $requestData = $this->requestData();
        $requestData['sections'] = 'reviews';
        $requestData['capture_sections'] = 'reviews';
        $requestData['profile_sections'] = 'reviews';
        $requestData['scope'] = 'ota_channel_review_summary';
        $requestData['privacy_boundary'] = 'aggregate_metrics_only_no_review_text';
        $requestData['review_detail_collection'] = false;
        $requestData['store_review_text'] = false;
        $requestData['store_comment_text'] = false;

        return $this->captureMeituanBrowserData($requestData);
    }

    /**
     * 检查权限
     */
}
