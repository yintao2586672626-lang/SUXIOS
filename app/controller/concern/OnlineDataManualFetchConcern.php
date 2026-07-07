<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\model\OperationLog;
use app\service\CtripManualFetchRequestService;
use app\service\CtripTrafficDisplayService;
use app\service\ManualOnlineFetchTaskService;
use app\service\MeituanManualFetchRequestService;
use think\Response;
use think\facade\Db;

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
        $autoSave = $this->isTruthyRequestValue($this->request->post('auto_save', true));
        $systemHotelIdInput = $this->request->post('system_hotel_id', null);
        $systemHotelId = ($systemHotelIdInput !== null && $systemHotelIdInput !== '')
            ? $this->resolveOnlineDataSystemHotelId($systemHotelIdInput)
            : null;
        $backgroundRequested = $this->isTruthyRequestValue($requestData['async'] ?? $requestData['background'] ?? false)
            && !$this->isTruthyRequestValue($requestData['background_task'] ?? false);

        if (empty($cookies)) {
            return json(['code' => 400, 'message' => '请提供登录Cookies', 'data' => null]);
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

            $displayHotels = $this->buildCtripBusinessDisplayHotels(['date_results' => $dateResults]);
            $displaySummary = $this->buildCtripBusinessDisplaySummary($displayHotels);

            $identityCheck = null;
            if ($autoSave) {
                if ($systemHotelId) {
                    $identityCheck = $this->validateCtripManualBusinessHotelIdentity($dateResults, (int)$systemHotelId, is_array($requestData) ? $requestData : []);
                } else {
                    $identityCheck = $this->resolveCtripManualBusinessHotelIdentityFromResponse($dateResults, is_array($requestData) ? $requestData : []);
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
                    $responseCode = $systemHotelId ? 409 : 200;
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
                    'identity_check' => $identityCheck,
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
    private function validateCtripManualBusinessHotelIdentity(array $dateResults, int $systemHotelId, array $requestData = []): array
    {
        $targetHotelName = $this->getSystemHotelName($systemHotelId);
        $config = $this->resolveCtripManualBusinessIdentityConfig($systemHotelId, $requestData);
        $expectedIds = $this->extractExpectedCtripPlatformHotelIds($config, $systemHotelId);
        $nodeIds = array_fill_keys($this->extractCtripNodeResourceIds($config), true);

        if ($expectedIds === []) {
            return [
                'ok' => false,
                'status' => 'expected_platform_hotel_id_missing',
                'message' => '当前门店未维护携程平台酒店ID，已取消入库。请先在酒店管理/携程配置中补充真实携程 hotelId。',
                'target_system_hotel_id' => $systemHotelId,
                'target_hotel_name' => $targetHotelName,
                'captured_hotel_ids' => [],
                'expected_hotel_ids' => [],
                'conflicts' => [],
            ];
        }

        $capturedIds = [];
        foreach ($dateResults as $dateResult) {
            if (!is_array($dateResult)) {
                continue;
            }
            foreach ($this->extractCtripManualBusinessSelfHotelIds($dateResult['data'] ?? [], $systemHotelId, $targetHotelName) as $id) {
                if ($this->isMeaningfulCtripPlatformHotelId($id, $systemHotelId) && !isset($nodeIds[$id])) {
                    $capturedIds[$id] = true;
                }
            }
        }
        $capturedIds = array_keys($capturedIds);

        if ($capturedIds === []) {
            return [
                'ok' => false,
                'status' => 'returned_current_hotel_id_missing',
                'message' => '携程返回数据未识别到当前酒店身份，已取消入库。请确认 Cookie 对应当前门店，并补充真实携程 hotelId 后重试。',
                'target_system_hotel_id' => $systemHotelId,
                'target_hotel_name' => $targetHotelName,
                'captured_hotel_ids' => [],
                'expected_hotel_ids' => $expectedIds,
                'conflicts' => [],
            ];
        }

        $conflicts = $this->findCtripPlatformHotelIdConflicts($capturedIds, $systemHotelId);
        $blockingConflicts = array_values(array_filter($conflicts, function (array $conflict) use ($expectedIds): bool {
            return $this->shouldBlockCtripCurrentHotelIdConflict((string)($conflict['hotel_id'] ?? ''), $expectedIds);
        }));
        if ($blockingConflicts !== []) {
            return [
                'ok' => false,
                'status' => 'platform_hotel_conflict',
                'message' => '携程返回酒店ID已绑定到其他系统门店，已取消入库，避免错店数据覆盖。',
                'target_system_hotel_id' => $systemHotelId,
                'target_hotel_name' => $targetHotelName,
                'captured_hotel_ids' => $capturedIds,
                'expected_hotel_ids' => $expectedIds,
                'conflicts' => $blockingConflicts,
            ];
        }

        if (array_intersect($expectedIds, $capturedIds) === []) {
            return [
                'ok' => false,
                'status' => 'expected_hotel_id_mismatch',
                'message' => '携程返回酒店ID与当前门店配置不一致，已取消入库。当前门店：' . ($targetHotelName !== '' ? $targetHotelName : ('门店ID ' . $systemHotelId)) . '；配置hotelId：' . implode('、', $expectedIds) . '；返回hotelId：' . implode('、', $capturedIds),
                'target_system_hotel_id' => $systemHotelId,
                'target_hotel_name' => $targetHotelName,
                'captured_hotel_ids' => $capturedIds,
                'expected_hotel_ids' => $expectedIds,
                'conflicts' => [],
            ];
        }

        return [
            'ok' => true,
            'status' => 'matched',
            'target_system_hotel_id' => $systemHotelId,
            'target_hotel_name' => $targetHotelName,
            'captured_hotel_ids' => $capturedIds,
            'expected_hotel_ids' => $expectedIds,
            'conflicts' => [],
        ];
    }

    private function resolveCtripManualBusinessIdentityConfig(int $systemHotelId, array $requestData = []): array
    {
        $requestConfig = [];
        foreach (['masterHotelId', 'master_hotel_id', 'ota_hotel_id', 'otaHotelId', 'ctrip_hotel_id', 'ctripHotelId', 'platform_hotel_id', 'platformHotelId', 'node_id', 'nodeId'] as $key) {
            if (array_key_exists($key, $requestData)) {
                $requestConfig[$key] = $requestData[$key];
            }
        }

        foreach ($this->getStoredCtripConfigList() as $config) {
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
        $capturedIds = $this->extractCtripManualBusinessCapturedSelfHotelIds($dateResults, 0, '', $requestData);
        return $this->resolveCtripSystemHotelIdentityFromPlatformIds($capturedIds, $requestData);
    }

    private function extractCtripManualBusinessCapturedSelfHotelIds(array $dateResults, int $systemHotelId = 0, string $targetHotelName = '', array $requestData = []): array
    {
        $nodeIds = array_fill_keys($this->extractCtripNodeResourceIds($requestData), true);
        $capturedIds = [];
        foreach ($dateResults as $dateResult) {
            if (!is_array($dateResult)) {
                continue;
            }
            foreach ($this->extractCtripManualBusinessSelfHotelIds($dateResult['data'] ?? [], $systemHotelId, $targetHotelName) as $id) {
                if ($this->isMeaningfulCtripPlatformHotelId($id, $systemHotelId) && !isset($nodeIds[$id])) {
                    $capturedIds[$id] = true;
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
        foreach ($this->getStoredCtripConfigList() as $config) {
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
        foreach (['hotel_id', 'hotelId', 'external_hotel_id', 'externalHotelId', 'request_hotel_id', 'requestHotelId'] as $key) {
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

    private function extractCtripManualBusinessSelfHotelIds($responseData, int $systemHotelId, string $targetHotelName = ''): array
    {
        $ids = [];
        foreach ($this->extractCtripBusinessDataList($responseData) as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (!$this->isCtripManualBusinessSelfRow($item, $targetHotelName)) {
                continue;
            }
            $id = $this->resolveCtripPlatformHotelId($item);
            if ($this->isMeaningfulCtripPlatformHotelId($id, $systemHotelId)) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    private function isCtripManualBusinessSelfRow(array $item, string $targetHotelName = ''): bool
    {
        foreach (['hotelName', 'hotel_name', 'HotelName', 'name', 'metric_hotel_name'] as $key) {
            $hotelName = trim((string)($item[$key] ?? ''));
            if ($hotelName !== '' && ($this->isCtripGenericSelfHotelName($hotelName) || $this->ctripHotelNameMatches($hotelName, $targetHotelName))) {
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
                return $this->error('请求失败: ' . $result['error'], 400, [
                    'reason' => $result['reason'] ?? 'meituan_request_failed',
                    'credential_status' => $result['credential_status'] ?? '',
                    'business_code' => $result['business_code'] ?? null,
                    'business_message' => $result['business_message'] ?? '',
                    'http_code' => $result['http_code'] ?? null,
                ]);
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
