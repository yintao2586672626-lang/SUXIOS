<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\model\OperationLog;
use app\service\BrowserProfileCaptureRequestService;
use app\service\MeituanManualIdentityService;
use app\service\OtaExecutionStageException;
use app\service\OtaTrafficUrlNormalizer;
use app\service\PlatformDataSyncService;
use think\Response;
use think\facade\Db;

trait OnlineDataRequestConcern
{
    /**
     * @param array<string, mixed> $requestData
     * @return array<string, scalar|array<int, scalar|null>|null>
     */
    private function sanitizeCtripCookieApiExecutionRequestData(array $requestData): array
    {
        return $this->sanitizePrimaryManualFetchRequestData($requestData, [
            'config_id',
            'system_hotel_id',
            'request_url',
            'requestUrl',
            'url',
            'method',
            'hotel_id',
            'hotelId',
            'ctrip_hotel_id',
            'ctripHotelId',
            'ota_hotel_id',
            'otaHotelId',
            'platform_hotel_id',
            'platformHotelId',
            'profile_id',
            'profileId',
            'hotel_name',
            'hotelName',
            'data_date',
            'dataDate',
            'start_date',
            'end_date',
            'auto_save',
            'request_source',
        ], ['request_urls', 'requestUrls']);
    }

    /**
     * @param array<string, mixed> $requestData
     * @return array<string, scalar|array<int, scalar|null>|null>
     */
    private function sanitizeCtripOverviewExecutionRequestData(array $requestData): array
    {
        return $this->sanitizePrimaryManualFetchRequestData($requestData, [
            'config_id',
            'system_hotel_id',
            'url',
            'method',
            'hotel_id',
            'hotelId',
            'ctrip_hotel_id',
            'ctripHotelId',
            'hotel_name',
            'hotelName',
            'data_date',
            'dataDate',
        ], ['request_urls', 'requestUrls']);
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
        $manualImport = $this->isMeituanManualCapturedPayload($requestData, $payload);

        $systemHotelId = $this->resolveOnlineDataSystemHotelId(
            $requestData['system_hotel_id']
            ?? $requestData['hotel_id']
            ?? $payload['system_hotel_id']
            ?? $payload['hotel_id']
            ?? null
        );
        if (!$systemHotelId) {
            return $this->error('Meituan captured payload requires a system hotel binding.', 409, [
                'status_code' => $manualImport ? 'meituan_manual_binding_missing' : 'meituan_profile_binding_missing',
            ]);
        }
        try {
            $profileIdentity = $manualImport
                ? $this->resolveMeituanCapturedManualIdentity($requestData, $payload, (int)$systemHotelId)
                : $this->resolveMeituanCapturedProfileIdentity($requestData, $payload, (int)$systemHotelId);
        } catch (\Throwable $e) {
            $status = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 409;
            return $this->error($e->getMessage(), $status, [
                'status_code' => $e->getMessage(),
                'ingestion_method' => $manualImport ? 'manual_import' : 'browser_profile',
            ]);
        }
        $targetDataDate = $this->normalizeOnlineDataDate(
            $requestData['data_date']
            ?? $requestData['dataDate']
            ?? $payload['default_data_date']
            ?? $payload['defaultDataDate']
            ?? ''
        );
        if ($targetDataDate === '') {
            return $this->error('meituan_target_date_missing', 422, [
                'status_code' => 'meituan_target_date_missing',
            ]);
        }
        $payload['default_data_date'] = $targetDataDate;
        $rows = $this->buildMeituanCapturedDailyRows($payload, $systemHotelId);
        $mismatchedDates = BrowserProfileCaptureRequestService::mismatchedMeituanTargetDates($rows, $targetDataDate);
        if ($mismatchedDates !== []) {
            return $this->error('meituan_target_date_mismatch', 422, [
                'status_code' => 'meituan_target_date_mismatch',
                'target_date' => $targetDataDate,
                'returned_dates' => $mismatchedDates,
            ]);
        }
        $unverifiedDates = BrowserProfileCaptureRequestService::unverifiedMeituanTargetDateRows($rows, $targetDataDate);
        if ($unverifiedDates !== []) {
            return $this->error('meituan_target_date_unverified', 422, [
                'status_code' => 'meituan_target_date_unverified',
                'target_date' => $targetDataDate,
                'unverified_rows' => $unverifiedDates,
            ]);
        }
        if (empty($rows)) {
            $gate = is_array($payload['capture_gate'] ?? null) ? $payload['capture_gate'] : [];
            if (!$manualImport && BrowserProfileCaptureRequestService::isConfirmedEmptyMeituanCaptureGate($gate)) {
                return $this->success([
                    'saved_count' => 0,
                    'row_count' => 0,
                    'counts' => [],
                    'capture_gate' => $gate,
                    'ingestion_method' => $manualImport ? 'manual_import' : 'browser_profile',
                ], 'Meituan returned an authoritative empty result; no rows were written.');
            }
            return $this->error('meituan_capture_no_business_rows', 422, [
                'status_code' => 'meituan_capture_no_business_rows',
                'ingestion_method' => $manualImport ? 'manual_import' : 'browser_profile',
            ]);
        }

        $dataSourceId = (int)($profileIdentity['data_source_id'] ?? 0);
        if ($dataSourceId > 0) {
            foreach ($rows as &$row) {
                $row['data_source_id'] = $dataSourceId;
            }
            unset($row);
        }
        $rows = $this->uniqueMeituanCapturedRowsForPersistence($rows);
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
            'ingestion_method' => $manualImport ? 'manual_import' : 'browser_profile',
        ]);
    }

    /** @param array<string, mixed> $requestData @param array<string, mixed> $payload */
    private function isMeituanManualCapturedPayload(array $requestData, array $payload): bool
    {
        $method = strtolower(trim((string)(
            $requestData['ingestion_method']
            ?? $requestData['ingestionMethod']
            ?? $payload['data_period']
            ?? $payload['dataPeriod']
            ?? $payload['ingestion_method']
            ?? $payload['ingestionMethod']
            ?? ''
        )));
        if (in_array($method, ['manual_dom_csv', 'manual_import'], true)) {
            return true;
        }
        foreach (['orders', 'traffic', 'ads', 'reviews'] as $section) {
            foreach (is_array($payload[$section] ?? null) ? $payload[$section] : [] as $row) {
                if (is_array($row)
                    && in_array(strtolower(trim((string)($row['_ingestion_method'] ?? ''))), ['manual_dom_csv', 'manual_import'], true)
                ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $requestData
     * @param array<string, mixed> $payload
     * @return array{store_id:string,poi_id:string,shop_id:string,data_source_id:int}
     */
    private function resolveMeituanCapturedManualIdentity(array $requestData, array &$payload, int $systemHotelId): array
    {
        $configId = trim((string)($requestData['config_id'] ?? $requestData['configId'] ?? $payload['config_id'] ?? $payload['configId'] ?? ''));
        if ($configId === '') {
            throw new \RuntimeException('meituan_manual_config_missing', 409);
        }
        $storedConfig = $this->resolveMeituanManualFetchConfigMetadata($configId, $systemHotelId);
        if ($storedConfig === []) {
            throw new \RuntimeException('meituan_config_locator_mismatch', 409);
        }
        $identity = (new MeituanManualIdentityService())->resolveCapturedPayloadIdentity($payload, $storedConfig);
        $payload['store_id'] = $identity['store_id'];
        $payload['poi_id'] = $identity['poi_id'];
        $payload['shop_id'] = $identity['shop_id'];
        return $identity + ['data_source_id' => 0];
    }

    /**
     * @param array<string, mixed> $requestData
     * @param array<string, mixed> $payload
     * @return array{store_id:string,poi_id:string,shop_id:string,data_source_id:int}
     */
    private function resolveMeituanCapturedProfileIdentity(array $requestData, array &$payload, int $systemHotelId): array
    {
        $profileKey = trim((string)(
            $requestData['profile_key']
            ?? $requestData['profileKey']
            ?? $requestData['store_id']
            ?? $requestData['storeId']
            ?? $requestData['poi_id']
            ?? $requestData['poiId']
            ?? $payload['store_id']
            ?? $payload['storeId']
            ?? $payload['poi_id']
            ?? $payload['poiId']
            ?? ''
        ));
        if ($profileKey === '') {
            throw new \RuntimeException('meituan_profile_binding_missing', 409);
        }

        $this->assertOtaProfileBindingForHotel('meituan', $systemHotelId, $profileKey);
        $source = $this->loadProfileSessionSource('meituan', $systemHotelId, $profileKey);
        if (!is_array($source)) {
            throw new \RuntimeException('meituan_profile_source_missing', 409);
        }
        $storedConfig = json_decode((string)($source['config_json'] ?? ''), true);
        if (!is_array($storedConfig)) {
            throw new \RuntimeException('meituan_profile_source_invalid', 409);
        }

        $identity = (new MeituanManualIdentityService())->resolveCapturedPayloadIdentity($payload, $storedConfig);
        $payload['store_id'] = $identity['store_id'];
        $payload['poi_id'] = $identity['poi_id'];
        $payload['shop_id'] = $identity['shop_id'];

        return $identity + ['data_source_id' => max(0, (int)($source['id'] ?? 0))];
    }

    // Launch local browser profile capture, then save the captured payload.
    public function captureMeituanBrowserData(?array $requestDataOverride = null): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');
        @set_time_limit(0);

        $requestData = $requestDataOverride ?? $this->requestData();
        $systemHotelId = $this->resolveOnlineDataSystemHotelId(
            $requestData['system_hotel_id']
            ?? $requestData['hotel_id']
            ?? null
        );
        $storeId = BrowserProfileCaptureRequestService::resolveMeituanStoreId($requestData);
        if ($storeId === '') {
            return $this->error('请填写美团 Store ID / 门店 ID');
        }

        if (!$systemHotelId) {
            return $this->error('Please select the system hotel that owns this Meituan Profile.', 400);
        }
        try {
            $this->assertOtaProfileBindingForHotel('meituan', (int)$systemHotelId, $storeId);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 409, [
                'status_code' => 'ota_profile_binding_blocked',
                'sensitive_values_exposed' => false,
            ]);
        }

        $loginOnly = $this->isCtripLoginOnlyRequest($requestData);

        $projectRoot = dirname(__DIR__, 3);
        $scriptPath = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'meituan_browser_capture.mjs';
        if (!is_file($scriptPath)) {
            return $this->error('未找到美团浏览器抓取脚本');
        }

        $nodeBinary = BrowserProfileCaptureRequestService::resolveNodeBinary();
        if ($nodeBinary === '') {
            return $this->error('未找到 Node.js，请先安装 Node.js 或配置 NODE_BINARY');
        }

        $chromePath = BrowserProfileCaptureRequestService::resolveChromePath();
        $capturePlan = BrowserProfileCaptureRequestService::buildMeituanPlan(
            $requestData,
            $projectRoot,
            $nodeBinary,
            $loginOnly,
            $systemHotelId ? (int)$systemHotelId : null,
            date('YmdHis'),
            $chromePath
        );
        $outputDir = $capturePlan['output_dir'];
        if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
            return $this->error('无法创建美团抓取输出目录');
        }

        $outputPath = $capturePlan['output_path'];
        $targetDataDate = (string)($capturePlan['data_date'] ?? '');
        $timeoutSeconds = (int)$capturePlan['timeout_seconds'];

        $args = $capturePlan['args'];
        $poiId = (string)$capturePlan['poi_id'];

        $lock = $this->acquirePlatformProfileCaptureLock('meituan', $storeId);
        if ($lock === null) {
            return $this->error('Meituan browser Profile capture is already running for this store.', 409, [
                'status_code' => 'resource_busy_login',
                'error_code' => 'resource_busy_login',
                'lock_key' => 'meituan:' . BrowserProfileCaptureRequestService::safeFilePart($storeId),
            ]);
        }

        try {
            $runResult = $this->runMeituanCaptureProcess($args, $projectRoot, $timeoutSeconds);
        } finally {
            $this->releasePlatformProfileCaptureLock($lock);
        }
        if (!$runResult['success']) {
            if (is_file($outputPath)) {
                $failedPayload = json_decode((string)file_get_contents($outputPath), true);
                if (is_array($failedPayload)) {
                    $authStatus = is_array($failedPayload['auth_status'] ?? null) ? $failedPayload['auth_status'] : [];
                    if ($authStatus !== [] && empty($authStatus['ok'])) {
                        if ($systemHotelId) {
                            $this->cachePlatformProfileStatus('meituan', (int)$systemHotelId, $storeId, [
                                'checked_at' => date('Y-m-d H:i:s'),
                                'auth_status' => $authStatus,
                                'capture_gate' => $failedPayload['capture_gate'] ?? null,
                                'status_code' => 'login_expired',
                                'output' => $outputPath,
                            ]);
                        }
                        return $this->error('重新登录美团平台账号', 400, [
                            'auth_status' => $authStatus,
                            'capture_gate' => $failedPayload['capture_gate'] ?? null,
                            'output' => $outputPath,
                            'stdout' => $this->trimMeituanCaptureLog($runResult['stdout'] ?? ''),
                            'stderr' => $this->trimMeituanCaptureLog($runResult['stderr'] ?? ''),
                        ]);
                    }
                }
            }
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

        $authStatus = is_array($payload['auth_status'] ?? null) ? $payload['auth_status'] : [];
        if ($authStatus !== [] && empty($authStatus['ok'])) {
            if ($systemHotelId) {
                $this->cachePlatformProfileStatus('meituan', (int)$systemHotelId, $storeId, [
                    'checked_at' => date('Y-m-d H:i:s'),
                    'auth_status' => $authStatus,
                    'capture_gate' => $payload['capture_gate'] ?? null,
                    'status_code' => 'login_expired',
                    'output' => $outputPath,
                ]);
            }
            return $this->error('重新登录美团平台账号', 400, [
                'auth_status' => $authStatus,
                'capture_gate' => $payload['capture_gate'] ?? null,
                'output' => $outputPath,
                'stdout' => $this->trimMeituanCaptureLog($runResult['stdout'] ?? ''),
            ]);
        }

        if ($systemHotelId && $authStatus !== []) {
            $this->cachePlatformProfileStatus('meituan', (int)$systemHotelId, $storeId, [
                'checked_at' => date('Y-m-d H:i:s'),
                'auth_status' => $authStatus,
                'capture_gate' => $payload['capture_gate'] ?? null,
                'status_code' => 'logged_in',
                'output' => $outputPath,
            ]);
        }

        if ($loginOnly) {
            $responsePayload = [
                'mode' => (string)($payload['mode'] ?? 'login_only'),
                'store_id' => (string)($payload['store_id'] ?? $storeId),
                'poi_id' => (string)($payload['poi_id'] ?? $poiId),
                'auth_status' => $payload['auth_status'] ?? null,
                'capture_gate' => $payload['capture_gate'] ?? null,
                'pages' => $payload['pages'] ?? [],
                'saved_count' => 0,
                'row_count' => 0,
                'counts' => [],
                'output' => $outputPath,
                'stdout' => $this->trimMeituanCaptureLog($runResult['stdout'] ?? ''),
            ];
            if ($systemHotelId && $this->isTruthyRequestValue($requestData['bind_data_source'] ?? $requestData['bindDataSource'] ?? false)) {
                $responsePayload['data_source'] = $this->bindBrowserProfileDataSource('meituan', (int)$systemHotelId, $requestData, $payload);
            }

            return $this->success($responsePayload, '美团浏览器 Profile 登录状态已准备，未执行数据采集和入库');
        }

        $gate = is_array($payload['capture_gate'] ?? null) ? $payload['capture_gate'] : [];
        if ($gate !== [] && ($gate['status'] ?? 'fail') !== 'pass') {
            return $this->error('美团浏览器 Profile 采集门禁未通过，未入库空数据', 400, [
                'auth_status' => $payload['auth_status'] ?? null,
                'capture_gate' => $gate,
                'output' => $outputPath,
                'pages' => $payload['pages'] ?? [],
                'responses' => array_slice(is_array($payload['responses'] ?? null) ? $payload['responses'] : [], 0, 20),
                'stdout' => $this->trimMeituanCaptureLog($runResult['stdout'] ?? ''),
                'stderr' => $this->trimMeituanCaptureLog($runResult['stderr'] ?? ''),
            ]);
        }

        try {
            $profileIdentity = $this->resolveMeituanCapturedProfileIdentity($requestData, $payload, (int)$systemHotelId);
        } catch (\Throwable $e) {
            $status = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 409;
            return $this->error($e->getMessage(), $status, [
                'status_code' => $e->getMessage() === 'meituan_platform_identity_mismatch'
                    ? 'meituan_platform_identity_mismatch'
                    : 'meituan_profile_binding_blocked',
                'capture_gate' => $gate,
                'output' => $outputPath,
            ]);
        }

        $rows = $this->buildMeituanCapturedDailyRows($payload, $systemHotelId);
        $mismatchedDates = BrowserProfileCaptureRequestService::mismatchedMeituanTargetDates($rows, $targetDataDate);
        if ($mismatchedDates !== []) {
            return $this->error('美团浏览器 Profile 返回了目标日期之外的累计数据，未入库', 422, [
                'status_code' => 'meituan_target_date_mismatch',
                'target_date' => $targetDataDate,
                'returned_dates' => $mismatchedDates,
                'capture_gate' => $gate,
                'output' => $outputPath,
            ]);
        }
        $unverifiedDates = BrowserProfileCaptureRequestService::unverifiedMeituanTargetDateRows($rows, $targetDataDate);
        if ($unverifiedDates !== []) {
            return $this->error('美团浏览器 Profile 缺少可验证的目标日期证据，未入库', 422, [
                'status_code' => 'meituan_target_date_unverified',
                'target_date' => $targetDataDate,
                'unverified_rows' => $unverifiedDates,
                'capture_gate' => $gate,
                'output' => $outputPath,
            ]);
        }
        if (empty($rows)) {
            if (BrowserProfileCaptureRequestService::isConfirmedEmptyMeituanCaptureGate($gate)) {
                return $this->success([
                    'auth_status' => $payload['auth_status'] ?? null,
                    'capture_gate' => $gate,
                    'saved_count' => 0,
                    'row_count' => 0,
                    'counts' => [],
                    'output' => $outputPath,
                ], '美团平台已确认当前条件无数据，未写入空行');
            }
            return $this->error('美团浏览器 Profile 采集未解析到业务行，未入库空数据', 400, [
                'auth_status' => $payload['auth_status'] ?? null,
                'capture_gate' => $payload['capture_gate'] ?? null,
                'output' => $outputPath,
                'pages' => $payload['pages'] ?? [],
                'responses' => array_slice(is_array($payload['responses'] ?? null) ? $payload['responses'] : [], 0, 20),
                'stdout' => $this->trimMeituanCaptureLog($runResult['stdout'] ?? ''),
            ]);
        }
        $dataSourceBinding = null;
        $dataSourceBindingError = '';
        $resolvedDataSourceId = (int)($profileIdentity['data_source_id'] ?? 0);
        $dataSourceId = $resolvedDataSourceId > 0 ? $resolvedDataSourceId : null;
        if ($systemHotelId && $this->isTruthyRequestValue($requestData['bind_data_source'] ?? $requestData['bindDataSource'] ?? false)) {
            try {
                $dataSourceBinding = $this->bindBrowserProfileDataSource('meituan', (int)$systemHotelId, $requestData, $payload);
                $boundId = (int)($dataSourceBinding['id'] ?? 0);
                $dataSourceId = $boundId > 0 ? $boundId : null;
            } catch (\Throwable $e) {
                $dataSourceBindingError = $e->getMessage();
            }
        }
        if ($dataSourceId !== null) {
            foreach ($rows as &$row) {
                if (is_array($row)) {
                    $row['data_source_id'] = $dataSourceId;
                }
            }
            unset($row);
        }
        $rows = $this->uniqueMeituanCapturedRowsForPersistence($rows);
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

        $responsePayload = [
            'saved_count' => $savedCount,
            'row_count' => count($rows),
            'persistence_status' => $savedCount === count($rows) ? 'readback_verified' : 'readback_not_verified',
            'auth_status' => $payload['auth_status'] ?? null,
            'capture_gate' => $gate,
            'counts' => $this->summarizeMeituanCapturedRows($rows),
            'payload_counts' => [
                'reviews' => $this->countMeituanPayloadSection($payload, 'reviews'),
                'traffic' => $this->countMeituanPayloadSection($payload, 'traffic'),
                'peer_rank' => $this->countMeituanPayloadSection($payload, 'peerRank'),
                'traffic_analysis' => $this->countMeituanPayloadSection($payload, 'flowAnalysis'),
                'order_flow' => $this->countMeituanPayloadSection($payload, 'order_flow'),
                'search_keywords' => $this->countMeituanPayloadSection($payload, 'searchKeywords'),
                'traffic_forecast' => $this->countMeituanPayloadSection($payload, 'trafficForecast'),
                'ads' => $this->countMeituanPayloadSection($payload, 'ads'),
                'orders' => $this->countMeituanPayloadSection($payload, 'orders'),
                'responses' => $this->countMeituanPayloadSection($payload, 'responses'),
            ],
            'output' => $outputPath,
            'pages' => $payload['pages'] ?? [],
            'screenshots' => $payload['screenshots'] ?? [],
            'stdout' => $this->trimMeituanCaptureLog($runResult['stdout'] ?? ''),
        ];
        if ($dataSourceBinding !== null) {
            $responsePayload['data_source'] = $dataSourceBinding;
            $responsePayload['data_source_binding'] = ['status' => 'success', 'data_source_id' => (int)($dataSourceBinding['id'] ?? 0)];
        } elseif ($dataSourceBindingError !== '') {
            $responsePayload['data_source_binding'] = ['status' => 'failed', 'message' => $dataSourceBindingError];
        }

        if ($savedCount !== count($rows)) {
            return json([
                'code' => 500,
                'message' => '美团浏览器已解析到业务行，但数据库完整回读未通过；本次不标记为入库成功。',
                'data' => $responsePayload,
            ], 500);
        }

        return $this->success($responsePayload, '美团浏览器抓取完成并已确认入库');
    }

    // Direct Ctrip comment-detail browser capture is disabled.
    public function captureCtripCommentsBrowserData(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $requestData = array_merge($this->requestData(), [
            'sections' => 'comment_review',
            'capture_sections' => 'comment_review',
            'captureSections' => 'comment_review',
        ]);

        return $this->captureCtripBrowserData($requestData);
    }

    public function captureCtripBrowserData(?array $requestDataOverride = null): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');
        @set_time_limit(0);

        $requestData = $requestDataOverride ?? $this->requestData();
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
            return $this->error('请选择本次采集数据对应的业务日期；系统不会为空日期自动代填。', 422);
        }

        $hotelId = BrowserProfileCaptureRequestService::resolveCtripHotelId($requestData);
        $profileId = BrowserProfileCaptureRequestService::resolveCtripProfileId($requestData, (int)$systemHotelId, $hotelId);
        try {
            $this->assertOtaProfileBindingForHotel('ctrip', (int)$systemHotelId, $profileId);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 409, [
                'status_code' => 'ota_profile_binding_blocked',
                'sensitive_values_exposed' => false,
            ]);
        }
        $loginOnly = $this->isCtripLoginOnlyRequest($requestData);

        $projectRoot = dirname(__DIR__, 3);
        $scriptPath = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'ctrip_browser_capture.mjs';
        if (!is_file($scriptPath)) {
            return $this->error('未找到携程浏览器 Profile 采集脚本');
        }

        $nodeBinary = BrowserProfileCaptureRequestService::resolveNodeBinary();
        if ($nodeBinary === '') {
            return $this->error('未找到 Node.js，请先安装 Node.js 或配置 NODE_BINARY');
        }

        $capturePlan = BrowserProfileCaptureRequestService::buildCtripBasePlan(
            $requestData,
            $projectRoot,
            $nodeBinary,
            (int)$systemHotelId,
            $dataDate,
            date('YmdHis')
        );
        $outputDir = $capturePlan['output_dir'];
        if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
            return $this->error('无法创建携程采集输出目录');
        }

        $outputPath = $capturePlan['output_path'];
        $timeoutSeconds = (int)$capturePlan['timeout_seconds'];
        $args = $capturePlan['args'];
        $fieldConfigPayload = $this->buildCtripProfileFieldConfigPayload($this->readCtripProfileCaptureFields(true));
        $sectionsList = $this->resolveCtripProfileCaptureSectionsForRun($requestData, $fieldConfigPayload, $loginOnly);
        if (!$loginOnly && empty($sectionsList)) {
            return $this->error('获取字段配置中没有启用的可抓取字段，请先在“获取字段配置”启用字段或模块', 400);
        }
        $args[] = '--sections=' . implode(',', $sectionsList ?: ['business_overview']);
        $args = $this->appendCtripLoginOnlyArg($args, $requestData);
        $args = $this->appendCtripCaptureGateArgs($args, $requestData);
        $mappingArgs = $this->appendCtripApprovedMappingsArg($args, $requestData, $projectRoot);
        $approvedMappings = $mappingArgs['approved_mappings'];
        if ($mappingArgs['error'] !== '') {
            return $this->error((string)$mappingArgs['error'], 400);
        }
        $args = $mappingArgs['args'];
        $fieldConfigPath = $this->createCtripProfileFieldConfigFile($projectRoot, $fieldConfigPayload);
        if ($fieldConfigPath === '') {
            return $this->error('无法创建携程 Profile 字段配置快照', 500);
        }
        $args[] = '--field-config=' . $fieldConfigPath;
        $chromePath = BrowserProfileCaptureRequestService::resolveChromePath();
        if ($chromePath !== '') {
            $args[] = '--chrome-path=' . $chromePath;
        }

        $lock = $this->acquirePlatformProfileCaptureLock('ctrip', $profileId);
        if ($lock === null) {
            $this->removeAutoFetchCookieFile($fieldConfigPath);
            return $this->error('Ctrip browser Profile capture is already running for this profile.', 409, [
                'lock_key' => 'ctrip:' . BrowserProfileCaptureRequestService::safeFilePart($profileId),
            ]);
        }

        try {
            $runResult = $this->runMeituanCaptureProcess($args, $projectRoot, $timeoutSeconds);
        } finally {
            $this->removeAutoFetchCookieFile($fieldConfigPath);
            $this->releasePlatformProfileCaptureLock($lock);
        }
        if (!$runResult['success']) {
            return $this->error(str_replace('美团', '携程', $runResult['message']), 400, [
                'stdout' => $this->trimMeituanCaptureLog($runResult['stdout'] ?? ''),
                'stderr' => $this->trimMeituanCaptureLog($runResult['stderr'] ?? ''),
                'partial_capture' => $this->buildCtripPartialCaptureErrorPayload($outputPath),
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

        if ($loginOnly) {
            $authStatus = is_array($payload['auth_status'] ?? null) ? $payload['auth_status'] : [];
            if ($authStatus !== [] && empty($authStatus['ok'])) {
                $this->cachePlatformProfileStatus('ctrip', (int)$systemHotelId, $profileId, [
                    'checked_at' => date('Y-m-d H:i:s'),
                    'auth_status' => $authStatus,
                    'capture_gate' => $payload['capture_gate'] ?? null,
                    'status_code' => 'login_expired',
                    'output' => $outputPath,
                ]);
                return $this->error('重新登录携程平台账号', 400, [
                    'auth_status' => $authStatus,
                    'capture_gate' => $payload['capture_gate'] ?? null,
                    'output' => $outputPath,
                    'stdout' => $this->trimMeituanCaptureLog($runResult['stdout'] ?? ''),
                ]);
            }

            $this->cachePlatformProfileStatus('ctrip', (int)$systemHotelId, $profileId, [
                'checked_at' => date('Y-m-d H:i:s'),
                'auth_status' => $authStatus !== [] ? $authStatus : ['ok' => true, 'status' => 'logged_in'],
                'capture_gate' => $payload['capture_gate'] ?? null,
                'status_code' => 'logged_in',
                'output' => $outputPath,
            ]);
            $responsePayload = $this->buildCtripLoginOnlyResponsePayload(
                $payload,
                $outputPath,
                $this->trimMeituanCaptureLog($runResult['stdout'] ?? '')
            );
            if ($this->isTruthyRequestValue($requestData['bind_data_source'] ?? $requestData['bindDataSource'] ?? false)) {
                $responsePayload['data_source'] = $this->bindBrowserProfileDataSource('ctrip', (int)$systemHotelId, $requestData, $payload);
            }

            return $this->success($responsePayload, '携程浏览器 Profile 登录状态已准备，未执行数据采集和入库');
        }

        $captureGateDecision = $this->buildCtripCaptureGateDecision($payload);
        $captureGateWarning = null;
        if (!$captureGateDecision['accepted']) {
            if ($this->canContinueCtripCaptureWithSoftGateWarning($payload, $captureGateDecision)) {
                $captureGateWarning = $this->buildCtripCaptureGateWarning($captureGateDecision);
            } else {
                $capturedCounts = $this->buildCtripCaptureCounts($payload);
                return $this->error('携程浏览器 Profile 采集门禁未通过，未入库且未更新最新采集状态', 400, [
                    'output' => $outputPath,
                    'auth_status' => $payload['auth_status'] ?? null,
                    'capture_gate' => $captureGateDecision['gate'],
                    'capture_gate_status' => $captureGateDecision['status'],
                    'capture_gate_failed_check_ids' => $captureGateDecision['failed_check_ids'],
                    'capture_gate_blocking_failed_check_ids' => $this->getCtripCaptureBlockingFailedCheckIds($captureGateDecision['failed_check_ids']),
                    'capture_audit' => $payload['capture_audit'] ?? null,
                    'captured_counts' => $capturedCounts,
                    'diagnosis_summary' => $this->buildCtripCaptureDiagnosisSummary($payload),
                    'pages' => $payload['pages'] ?? [],
                    'xhr_urls' => array_slice($payload['xhr_urls'] ?? [], 0, 20),
                    'stdout' => $this->trimMeituanCaptureLog($runResult['stdout'] ?? ''),
                    'stderr' => $this->trimMeituanCaptureLog($runResult['stderr'] ?? ''),
                ]);
            }
        }

        $dataSourceBinding = null;
        $dataSourceBindingError = '';
        $dataSourceId = null;
        if ($this->isTruthyRequestValue($requestData['bind_data_source'] ?? $requestData['bindDataSource'] ?? false)) {
            try {
                $dataSourceBinding = $this->bindBrowserProfileDataSource('ctrip', (int)$systemHotelId, $requestData, $payload);
                $boundId = (int)($dataSourceBinding['id'] ?? 0);
                $dataSourceId = $boundId > 0 ? $boundId : null;
            } catch (\Throwable $e) {
                $dataSourceBindingError = $e->getMessage();
            }
        }

        $requestHotelId = $hotelId !== '' ? $hotelId : (string)($payload['hotel_id'] ?? '');
        $saveResult = $this->saveCtripBrowserProfilePayload($payload, (int)$systemHotelId, $dataDate, $requestHotelId, $dataSourceId);
        $savedCount = (int)$saveResult['saved_count'];
        $capturedCounts = $this->buildCtripCaptureCounts($payload);

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

        $rowCount = (int)$capturedCounts['business'] + (int)$capturedCounts['traffic'] + (int)$capturedCounts['standard_rows'];
        $responsePayload = array_merge([
            'saved_count' => $savedCount,
            'row_count' => $rowCount,
        ], $this->buildCtripCaptureFactRowCountPayload($capturedCounts, $savedCount, $rowCount), [
            'counts' => [
                'business' => (int)$saveResult['business_saved'],
                'traffic' => (int)$saveResult['traffic_saved'],
                'standard_rows' => (int)($saveResult['standard_saved'] ?? 0),
            ],
            'captured_counts' => $capturedCounts,
            'diagnosis_summary' => $this->buildCtripCaptureDiagnosisSummary($payload),
            'standard_data_type_counts' => $capturedCounts['standard_by_data_type'],
            'standard_section_counts' => $capturedCounts['standard_by_section'],
            'endpoint_candidate_counts' => $capturedCounts['candidate_by_section'],
            'endpoint_candidates' => array_slice(is_array($payload['endpoint_candidates'] ?? null) ? $payload['endpoint_candidates'] : [], 0, 20),
            'p3_evidence_counts' => $capturedCounts['p3_evidence_by_section'],
            'p3_evidence_status_counts' => $capturedCounts['p3_evidence_by_status'],
            'p3_evidence_ready_count' => $capturedCounts['p3_evidence_ready'],
            'p3_evidence_drafts' => array_slice(is_array($payload['p3_evidence_drafts'] ?? null) ? $payload['p3_evidence_drafts'] : [], 0, 20),
            'p3_evidence_matrix' => is_array($payload['p3_evidence_matrix'] ?? null) ? $payload['p3_evidence_matrix'] : null,
            'auth_status' => $payload['auth_status'] ?? null,
            'capture_gate' => $payload['capture_gate'] ?? null,
            'capture_gate_warning' => $captureGateWarning,
            'capture_audit' => $payload['capture_audit'] ?? null,
            'approved_mappings' => $payload['approved_mappings'] ?? ['configured' => (bool)$approvedMappings['configured']],
            'modules' => $saveResult['modules'],
            'output' => $outputPath,
            'pages' => $payload['pages'] ?? [],
            'xhr_urls' => array_slice($payload['xhr_urls'] ?? [], 0, 20),
            'screenshots' => $payload['screenshots'] ?? [],
            'stdout' => $this->trimMeituanCaptureLog($runResult['stdout'] ?? ''),
        ]);
        if ($dataSourceBinding !== null) {
            $responsePayload['data_source'] = $dataSourceBinding;
            $responsePayload['data_source_binding'] = ['status' => 'success', 'data_source_id' => (int)($dataSourceBinding['id'] ?? 0)];
        } elseif ($dataSourceBindingError !== '') {
            $responsePayload['data_source_binding'] = ['status' => 'failed', 'message' => $dataSourceBindingError];
        }

        if ($rowCount > 0 && $savedCount <= 0) {
            $responsePayload['persistence_status'] = 'readback_not_verified';
            return json([
                'code' => 500,
                'message' => '携程浏览器 Profile 已解析到数据，但未确认数据库入库；请检查回读结果后重试。',
                'data' => $responsePayload,
            ], 500);
        }

        $responsePayload['persistence_status'] = $savedCount > 0 ? 'readback_verified' : 'no_parsed_rows';
        return $this->success($responsePayload, $savedCount > 0 ? '携程浏览器 Profile 采集完成并已确认入库' : '携程浏览器 Profile 采集完成，但未解析到可入库数据');
    }

    public function validateCtripEndpointEvidence(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        try {
            $projectRoot = dirname(__DIR__, 3);
            $scriptPath = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'validate_ctrip_endpoint_evidence.mjs';
            if (!is_file($scriptPath)) {
                return $this->error('未找到携程接口证据校验脚本');
            }

            $nodeBinary = BrowserProfileCaptureRequestService::resolveNodeBinary();
            if ($nodeBinary === '') {
                return $this->error('未找到 Node.js，请先安装 Node.js 或配置 NODE_BINARY');
            }

            $requestData = $this->requestData();
            $prepared = $this->prepareCtripEndpointEvidenceValidationFiles($requestData, $projectRoot);
            $runResult = $this->runMeituanCaptureProcess([
                $nodeBinary,
                $scriptPath,
                '--input=' . $prepared['input_path'],
                '--output=' . $prepared['output_path'],
                '--markdown=' . $prepared['markdown_path'],
            ], $projectRoot, 60);

            if (!$runResult['success']) {
                return $this->error('携程接口证据校验失败: ' . str_replace('美团浏览器抓取', 'Node脚本', $runResult['message']), 400, [
                    'stdout' => $this->trimMeituanCaptureLog($runResult['stdout'] ?? ''),
                    'stderr' => $this->trimMeituanCaptureLog($runResult['stderr'] ?? ''),
                ]);
            }

            $validation = $this->readLocalJsonFile($prepared['output_path']);
            $catalogPreviewImport = $this->buildCtripEndpointEvidenceCatalogPreviewImportPlan($validation, $requestData);
            if ($catalogPreviewImport['requested']) {
                $requestData['_resolved_system_hotel_id'] = $this->resolveOnlineDataSystemHotelId(
                    $requestData['system_hotel_id']
                    ?? $requestData['systemHotelId']
                    ?? null
                );
                $catalogPreviewImport = $this->buildCtripEndpointEvidenceCatalogPreviewImportPlan($validation, $requestData);
            }
            $candidatePath = '';
            $candidate = null;
            $candidateError = '';
            if (($validation['field_mapping_draft']['ready_for_mapping'] ?? false) === true) {
                $promoteScript = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'promote_ctrip_mapping_draft.mjs';
                if (is_file($promoteScript)) {
                    $candidatePath = $prepared['candidate_path'];
                    $promoteResult = $this->runMeituanCaptureProcess([
                        $nodeBinary,
                        $promoteScript,
                        '--input=' . $prepared['output_path'],
                        '--output=' . $candidatePath,
                    ], $projectRoot, 60);
                    if ($promoteResult['success'] && is_file($candidatePath)) {
                        $candidate = $this->readLocalJsonFile($candidatePath);
                    } else {
                        $candidateError = trim((string)($promoteResult['stderr'] ?? $promoteResult['stdout'] ?? $promoteResult['message'] ?? '候选映射生成失败'));
                        $candidatePath = '';
                    }
                }
            }

            if ($catalogPreviewImport['requested'] && $catalogPreviewImport['can_save']) {
                $standardRows = $this->extractCtripStandardRows(
                    ['standard_rows' => $catalogPreviewImport['rows']],
                    (int)$catalogPreviewImport['system_hotel_id'],
                    (string)$catalogPreviewImport['data_date'],
                    (string)$catalogPreviewImport['request_hotel_id']
                );
                $catalogPreviewImport['saved_count'] = !empty($standardRows) ? $this->saveCtripStandardRows($standardRows) : 0;
                $catalogPreviewImport['message'] = $catalogPreviewImport['saved_count'] > 0
                    ? 'catalog preview standard rows saved'
                    : 'catalog preview import requested but no rows were saved';
            }

            return $this->success($this->buildCtripEndpointEvidenceValidationPayload(
                $validation,
                $prepared,
                $candidate,
                $candidatePath,
                $candidateError,
                $this->trimMeituanCaptureLog($runResult['stdout'] ?? ''),
                $catalogPreviewImport
            ), ($validation['evidence_status'] ?? '') === 'complete_redacted'
                ? '携程接口证据完整，已生成待人工审核映射草案'
                : '携程接口证据已校验，但仍缺少必要信息');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 400);
        } catch (\Throwable $e) {
            return $this->error('携程接口证据校验异常: ' . $e->getMessage(), 500);
        }
    }

    public function ctripDiagnosisSnapshot(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $profileId = trim((string)($this->request->get('profile_id', $this->request->get('profileId', ''))));
        $snapshot = $this->buildLatestCtripDiagnosisSnapshot($profileId);
        if (empty($snapshot['available'])) {
            return $this->error('暂无可用于诊断的携程采集快照，请先完成 Profile 采集或 Cookie 采集。', 404, $snapshot);
        }

        return $this->success($snapshot, '携程诊断快照读取完成');
    }

    public function ctripProfileStatus(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $requestData = array_merge($this->request->get(), $this->requestData());
        $systemHotelId = $this->resolveOnlineDataSystemHotelId(
            $requestData['system_hotel_id']
            ?? $requestData['systemHotelId']
            ?? null
        );
        $probeCookie = $this->isTruthyRequestValue($requestData['probe_cookie'] ?? $requestData['probeCookie'] ?? false);
        $probeLogin = $this->isTruthyRequestValue($requestData['probe_login'] ?? $requestData['probeLogin'] ?? false);
        try {
            $status = $this->buildCtripProfileStatus($requestData, $systemHotelId, $probeCookie, $probeLogin);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 409, [
                'status_code' => 'ota_profile_binding_blocked',
                'sensitive_values_exposed' => false,
            ]);
        }
        if ($probeLogin && $systemHotelId !== null && !empty($status['profile_id'])) {
            $this->cachePlatformProfileStatus('ctrip', (int)$systemHotelId, (string)$status['profile_id'], [
                'checked_at' => date('Y-m-d H:i:s'),
                'auth_status' => [
                    'ok' => ($status['status_code'] ?? '') === 'logged_in',
                    'status' => ($status['status_code'] ?? '') === 'logged_in' ? 'logged_in' : 'login_required',
                    'message' => (string)($status['next_action'] ?? ''),
                ],
                'capture_gate' => $status['capture_gate'] ?? null,
                'status_code' => (string)($status['status_code'] ?? 'login_expired'),
                'output' => (string)($status['output'] ?? ''),
            ]);
        }

        return $this->success(
            $status,
            !empty($status['exists']) ? '携程 Profile 状态已读取' : '未找到携程 Profile'
        );
    }

    public function meituanProfileStatus(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $requestData = array_merge($this->request->get(), $this->requestData());
        $systemHotelId = $this->resolveOnlineDataSystemHotelId(
            $requestData['system_hotel_id']
            ?? $requestData['systemHotelId']
            ?? null
        );
        $probeLogin = $this->isTruthyRequestValue($requestData['probe_login'] ?? $requestData['probeLogin'] ?? false);
        try {
            $status = $this->buildMeituanProfileStatus($requestData, $systemHotelId, $probeLogin);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 409, [
                'status_code' => 'ota_profile_binding_blocked',
                'sensitive_values_exposed' => false,
            ]);
        }

        return $this->success(
            $status,
            !empty($status['exists']) ? '美团 Profile 状态已读取' : '未找到美团 Profile'
        );
    }

    public function platformProfileStatus(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_view_online_data');

        $requestData = array_merge($this->request->get(), $this->requestData());
        $systemHotelId = $this->resolveOnlineDataSystemHotelId(
            $requestData['system_hotel_id']
            ?? $requestData['systemHotelId']
            ?? $requestData['hotel_id']
            ?? $requestData['hotelId']
            ?? null
        );
        if (!$systemHotelId) {
            return $this->error('请选择酒店后查看平台账号状态', 400);
        }

        return $this->success($this->buildPlatformProfileStatus((int)$systemHotelId), '平台账号/Profile 状态已读取');
    }

    public function deletePlatformProfileBinding(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_delete_online_data');

        $requestData = $this->requestData();
        $platform = strtolower(trim((string)($requestData['platform'] ?? '')));
        if (!in_array($platform, ['ctrip', 'meituan'], true)) {
            return $this->error('不支持的平台绑定类型', 400);
        }

        $systemHotelId = $this->resolveOnlineDataSystemHotelId(
            $requestData['system_hotel_id']
            ?? $requestData['systemHotelId']
            ?? $requestData['hotel_id']
            ?? $requestData['hotelId']
            ?? null
        );
        if (!$systemHotelId) {
            return $this->error('请选择酒店后解除平台 Profile 绑定', 400);
        }

        $profileKey = trim((string)(
            $requestData['profile_key']
            ?? $requestData['profileId']
            ?? $requestData['profile_id']
            ?? $requestData['storeId']
            ?? $requestData['store_id']
            ?? ''
        ));
        $sourceId = (int)($requestData['data_source_id'] ?? $requestData['source_id'] ?? 0);

        try {
            if ($sourceId > 0) {
                $source = Db::name('platform_data_sources')
                    ->field('id,system_hotel_id,platform,ingestion_method,config_json,enabled,status')
                    ->where('id', $sourceId)
                    ->find();
                if (is_array($source)) {
                    $safeSources = $this->sanitizeBrowserProfileSourcesForSharedCache([$source]);
                    $source = $safeSources[0] ?? [];
                }
            } else {
                $source = $this->findBrowserProfileDataSourceForUnbind((int)$systemHotelId, $platform, $profileKey);
            }
            if (!$source || !is_array($source)) {
                if ($profileKey === '') {
                    return $this->error('未找到当前酒店可解除的平台 Profile 数据源绑定', 404);
                }

                $this->clearPlatformProfileStatusCache($platform, (int)$systemHotelId, $profileKey);
                OperationLog::record(
                    'online_data',
                    'clear_platform_profile_status',
                    '清除' . ($platform === 'ctrip' ? '携程' : '美团') . ' Profile状态缓存: ' . $profileKey,
                    $this->currentUser->id,
                    (int)$systemHotelId
                );

                return $this->success([
                    'id' => null,
                    'platform' => $platform,
                    'system_hotel_id' => (int)$systemHotelId,
                    'profile_key' => $profileKey,
                    'cleared_status_cache' => true,
                ], '未找到数据源绑定，已清除当前酒店的 Profile 登录状态缓存');
            }
            if ((int)($source['system_hotel_id'] ?? 0) !== (int)$systemHotelId
                || strtolower((string)($source['platform'] ?? '')) !== $platform
                || strtolower((string)($source['ingestion_method'] ?? '')) !== 'browser_profile') {
                return $this->error('平台 Profile 绑定与当前酒店不匹配，已拒绝解除', 400);
            }

            $service = new PlatformDataSyncService();
            $service->deleteDataSource($this->currentUser, (int)$source['id']);
            $this->clearBrowserProfileStatusCacheForSource($source);
            $this->clearAutoFetchLightProfileSourcesCache((int)($source['system_hotel_id'] ?? 0), (string)($source['platform'] ?? ''));
            OperationLog::record(
                'online_data',
                'unbind_platform_profile',
                '解除' . ($platform === 'ctrip' ? '携程' : '美团') . ' Profile绑定ID: ' . (int)$source['id'],
                $this->currentUser->id,
                (int)$systemHotelId
            );

            return $this->success([
                'id' => (int)$source['id'],
                'platform' => $platform,
                'system_hotel_id' => (int)$systemHotelId,
            ], 'Profile 绑定已解除');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 400);
        } catch (\Throwable $e) {
            return $this->error('解除平台 Profile 绑定失败: ' . $e->getMessage(), 500);
        }
    }

    public function triggerPlatformProfileLogin(string $platform): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $platform = strtolower(trim($platform));
        if (!in_array($platform, ['ctrip', 'meituan'], true)) {
            return $this->error('不支持的平台登录类型', 400);
        }

        if (!$this->isLocalPlatformProfileLoginRequest()) {
            $platformName = $platform === 'ctrip' ? '携程' : '美团';
            return $this->error(
                $platformName . '授权必须由账号使用者在自己电脑完成。当前不是本机访问，已禁止启动平台登录窗口；请使用浏览器辅助采集 JSON 或本机采集器导入授权证据。',
                409,
                [
                    'status' => 'blocked',
                    'status_code' => 'client_local_authorization_required',
                    'error_code' => 'client_local_authorization_required',
                    'platform' => $platform,
                    'platform_name' => $platformName,
                    'authorization_policy' => 'account_owner_local_computer_only',
                    'server_browser_launch_disabled' => true,
                    'next_action' => 'open_platform_on_account_owner_computer_and_import_browser_assist_json',
                ]
            );
        }

        $requestData = $this->requestData();
        try {
            $requestData = $this->applyPlatformProfileLoginDataSourceRequest($platform, $requestData);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 400);
        }
        $systemHotelId = $this->resolveOnlineDataSystemHotelId(
            $requestData['system_hotel_id']
            ?? $requestData['systemHotelId']
            ?? $requestData['hotel_id']
            ?? $requestData['hotelId']
            ?? null
        );
        if (!$systemHotelId) {
            return $this->error('请选择酒店后登录平台账号', 400);
        }

        $profileKey = $this->resolvePlatformProfileLoginProfileKey($platform, $requestData, (int)$systemHotelId);
        if ($profileKey === '') {
            return $this->error($platform === 'ctrip' ? '请填写携程 Profile ID 或酒店 ID' : '请填写美团 Store ID / POI ID', 400);
        }

        $requestData['allow_existing_local_profile_rebind'] = true;
        $requestData = $this->preparePlatformProfileLoginRequest($platform, $requestData, (int)$systemHotelId, $profileKey);

        try {
            $currentTask = $this->readPlatformProfileLoginCurrentTask($platform, (int)$systemHotelId, $profileKey);
            $currentStatus = strtolower(trim((string)($currentTask['status'] ?? '')));
            if ($currentTask !== []
                && in_array($currentStatus, ['queued', 'browser_opened', 'running', 'syncing_after_login'], true)
                && !$this->isPlatformProfileLoginTaskStale($currentTask, $currentStatus === 'queued' ? 45 : 600)) {
                return $this->error('同一平台 Profile 登录任务正在运行，请勿重复提交', 409, [
                    'status' => 'blocked',
                    'status_code' => 'resource_busy_login',
                    'error_code' => 'resource_busy_login',
                    'platform' => $platform,
                    'system_hotel_id' => (int)$systemHotelId,
                ]);
            }

            $task = $this->createPlatformProfileLoginTask($platform, (int)$systemHotelId, $profileKey, $requestData);
            if (!$this->launchPlatformProfileLoginTask($task)) {
                $task = array_merge($task, [
                    'status' => 'failed',
                    'message' => '无法启动平台登录后台任务，请检查 PHP CLI / think 命令是否可用',
                    'finished_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $this->cachePlatformProfileLoginTask($task);
                return $this->error($task['message'], 500, $this->normalizePlatformProfileLoginTask($task));
            }

            OperationLog::record(
                'online_data',
                'trigger_profile_login',
                '从账号使用者本机触发' . ($platform === 'ctrip' ? '携程' : '美团') . '平台账号登录: ' . $profileKey,
                $this->currentUser->id ?? null,
                (int)$systemHotelId
            );

            return $this->success(
                $this->normalizePlatformProfileLoginTask($task),
                ($platform === 'ctrip' ? '携程' : '美团') . '专用 Profile 浏览器正在打开，请在弹出的窗口中完成验证'
            );
        } catch (\Throwable $e) {
            return $this->error('启动平台登录失败: ' . $e->getMessage(), 500);
        }
    }

    private function isLocalPlatformProfileLoginRequest(): bool
    {
        $ip = strtolower(trim((string)$this->request->ip()));
        $localIp = in_array($ip, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true);
        if (!$localIp) {
            return false;
        }

        $hostHeader = strtolower(trim((string)$this->request->server('HTTP_HOST', '')));
        $host = (string)(parse_url('http://' . $hostHeader, PHP_URL_HOST) ?: '');
        return in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
    }

    public function platformProfileLoginStatus(string $platform): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_view_online_data');

        $platform = strtolower(trim($platform));
        if (!in_array($platform, ['ctrip', 'meituan'], true)) {
            return $this->error('不支持的平台登录类型', 400);
        }

        $requestData = array_merge($this->request->get(), $this->requestData());
        $taskId = trim((string)($requestData['task_id'] ?? $requestData['taskId'] ?? ''));
        if ($taskId !== '') {
            $task = $this->readPlatformProfileLoginTask($taskId);
            if ($task === []) {
                return $this->error('未找到平台登录任务', 404);
            }
        } else {
            $systemHotelId = $this->resolveOnlineDataSystemHotelId(
                $requestData['system_hotel_id']
                ?? $requestData['systemHotelId']
                ?? $requestData['hotel_id']
                ?? $requestData['hotelId']
                ?? null
            );
            $profileKey = $systemHotelId
                ? $this->resolvePlatformProfileLoginProfileKey($platform, $requestData, (int)$systemHotelId)
                : '';
            if (!$systemHotelId || $profileKey === '') {
                return $this->success([
                    'status' => 'idle',
                    'status_text' => '暂无登录任务',
                    'done' => true,
                    'message' => '暂无登录任务',
                ], '暂无登录任务');
            }
            if (!$this->currentUserCanViewOnlineDataHotel((int)$systemHotelId)) {
                return $this->error('No permission to view this hotel profile login task', 403);
            }
            $task = $this->readPlatformProfileLoginCurrentTask($platform, (int)$systemHotelId, $profileKey);
            if ($task === []) {
                return $this->success([
                    'status' => 'idle',
                    'status_text' => '暂无登录任务',
                    'done' => true,
                    'platform' => $platform,
                    'system_hotel_id' => (int)$systemHotelId,
                    'profile_key' => $profileKey,
                    'message' => '暂无登录任务',
                ], '暂无登录任务');
            }
        }

        $taskHotelId = (int)($task['system_hotel_id'] ?? 0);
        if ($taskHotelId <= 0) {
            return $this->error('Profile login task is missing hotel scope', 409);
        }
        if (!$this->currentUserCanViewOnlineDataHotel($taskHotelId)) {
            return $this->error('无权查看该酒店登录任务', 403);
        }

        if (($task['platform'] ?? '') !== $platform) {
            return $this->error('登录任务平台不匹配', 400);
        }

        return $this->success($this->normalizePlatformProfileLoginTask($task), '平台登录任务状态已读取');
    }

    private function currentUserCanViewOnlineDataHotel(int $hotelId): bool
    {
        if ($hotelId <= 0 || !$this->currentUser) {
            return false;
        }

        if (method_exists($this->currentUser, 'isSuperAdmin') && $this->currentUser->isSuperAdmin()) {
            return true;
        }

        return method_exists($this->currentUser, 'hasHotelPermission')
            && $this->currentUser->hasHotelPermission($hotelId, 'can_view_online_data');
    }

    public function fetchCtripCookieApiData(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $rawRequestData = $this->requestData();
        try {
            if (!is_array($rawRequestData)) {
                throw new \InvalidArgumentException('Invalid Ctrip Cookie API execution request schema.', 400);
            }
            $requestData = $this->sanitizeCtripCookieApiExecutionRequestData($rawRequestData);
            $configId = trim((string)($requestData['config_id'] ?? ''));
            $systemHotelId = $this->strictPositiveOtaConfigHotelId($requestData['system_hotel_id'] ?? null);

            return $this->withOtaCredentialForExecution(
                'ctrip',
                $configId,
                $systemHotelId,
                fn(array $credentialPayload): Response => $this->executeCtripCookieApiDataFetch(
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
            return $this->otaExecutionStageFailureResponse('ctrip_cookie_api_fetch', $e);
        } catch (\Throwable $e) {
            return $this->otaUnknownExecutionFailureResponse('ctrip_cookie_api_fetch', $e);
        }
    }

    /**
     * @param array<string, mixed> $requestData
     * @param array<string, mixed> $credentialPayload
     */
    private function executeCtripCookieApiDataFetch(
        array $requestData,
        array $credentialPayload,
        int $systemHotelId
    ): Response {
        $autoSave = $this->isTruthyRequestValue($requestData['auto_save'] ?? true);
        $cookies = trim((string)($credentialPayload['cookies'] ?? $credentialPayload['cookie'] ?? ''));
        if ($cookies === '') {
            return $this->error('OTA 凭据缺少登录 Cookies', 409);
        }

        try {
            $projectRoot = dirname(__DIR__, 3);
            $scriptPath = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'ctrip_cookie_api_capture.mjs';
            if (!is_file($scriptPath)) {
                return $this->error('未找到携程 Cookie API 采集脚本', 500);
            }

            $nodeBinary = BrowserProfileCaptureRequestService::resolveNodeBinary();
            if ($nodeBinary === '') {
                return $this->error('未找到 Node.js，请先安装 Node.js 或配置 NODE_BINARY', 500);
            }

            $prepared = $this->prepareCtripCookieApiCaptureFiles(
                $requestData,
                $projectRoot,
                $systemHotelId,
                $credentialPayload
            );
            $cookieFile = $this->createAutoFetchCookieFile($projectRoot, 'ctrip_api', $systemHotelId, $cookies);
            if ($cookieFile === '') {
                return $this->error('无法创建 OTA 凭据临时文件', 500);
            }

            try {
                $runResult = $this->runMeituanCaptureProcess([
                    $nodeBinary,
                    $scriptPath,
                    '--input=' . $prepared['input_path'],
                    '--cookies-file=' . $cookieFile,
                    '--output=' . $prepared['output_path'],
                ], $projectRoot, 90);
            } finally {
                $this->removeAutoFetchCookieFile($cookieFile);
                @unlink($prepared['input_path']);
            }

            if (!$runResult['success']) {
                return $this->error('携程 Cookie API 采集失败', 400, [
                    'reason' => 'ctrip_cookie_api_capture_failed',
                    'output' => $prepared['output_path'],
                ]);
            }

            $payload = $this->readLocalJsonFile($prepared['output_path']);
            $capturedCounts = $this->buildCtripCaptureCounts($payload);
            $saveResult = [
                'saved_count' => 0,
                'business_saved' => 0,
                'traffic_saved' => 0,
                'standard_saved' => 0,
                'modules' => [],
            ];
            $identityCheck = null;
            $saveBlockedIdentity = null;
            if ($autoSave) {
                if (!isset($prepared['config']) || !is_array($prepared['config'])) {
                    $prepared['config'] = [];
                }
                if ($saveBlockedIdentity === null) {
                    $identityCheck = $this->validateCtripPayloadHotelIdentity($payload, (int)$systemHotelId, $prepared['config'] ?? []);
                    if (empty($identityCheck['ok'])) {
                        $identityStatus = (string)($identityCheck['status'] ?? '');
                        if (in_array($identityStatus, ['expected_platform_hotel_id_missing', 'no_platform_hotel_id', 'returned_current_hotel_id_missing'], true)) {
                            $identityCheck['warning'] = true;
                            $identityCheck['message'] = (string)($identityCheck['message'] ?? '携程未返回可校验酒店身份，已阻止 Cookie API 自动入库；请先确认并补充真实携程 hotelId。');
                            $saveBlockedIdentity = $identityCheck;
                        } else {
                            return $this->error((string)$identityCheck['message'], 409, [
                                'reason' => 'hotel_identity_mismatch',
                                'identity_check' => $identityCheck,
                                'saved_count' => 0,
                                'row_count' => (int)$capturedCounts['standard_rows'],
                                'output' => $prepared['output_path'],
                            ]);
                        }
                    }
                    if ($saveBlockedIdentity === null && empty($identityCheck['expected_hotel_ids'])) {
                        $identityCheck['ok'] = false;
                        $identityCheck['status'] = 'expected_platform_hotel_id_missing';
                        $identityCheck['warning'] = true;
                        $identityCheck['message'] = '当前门店未配置可校验的携程 hotelId，已阻止 Cookie API 自动入库；请先补充真实携程 hotelId 后重试。';
                        $saveBlockedIdentity = $identityCheck;
                    }
                    if ($saveBlockedIdentity === null && ($identityCheck['status'] ?? '') === 'no_platform_hotel_id') {
                        $identityCheck['ok'] = false;
                        $identityCheck['warning'] = true;
                        $identityCheck['message'] = '携程返回数据未识别到可校验酒店身份，已阻止 Cookie API 自动入库；请确认 Cookie 对应门店并补充真实携程 hotelId。';
                        $saveBlockedIdentity = $identityCheck;
                    }
                    if ($saveBlockedIdentity === null) {
                        $requestHotelId = trim((string)($payload['hotel_id'] ?? $prepared['config']['hotel_id'] ?? $systemHotelId ?? ''));
                        $dataDate = $this->normalizeOnlineDataDate($payload['default_data_date'] ?? $prepared['config']['data_date'] ?? '');
                        if ($dataDate === '') {
                            $dataDate = date('Y-m-d');
                        }
                        $saveResult = $this->saveCtripBrowserProfilePayload($payload, (int)$systemHotelId, $dataDate, $requestHotelId);
                    }
                }
            }

            $readiness = $this->buildCtripCookieApiReadiness($payload, $capturedCounts, $saveResult, $autoSave);
            if ($saveBlockedIdentity !== null) {
                $readiness['status'] = 'save_blocked';
                $readiness['is_ready'] = false;
                $readiness['warning'] = (string)($saveBlockedIdentity['message'] ?? '携程 Cookie API 已采集但未完成门店归属，未入库。');
                $readiness['next_action'] = '在酒店管理中补充真实携程 hotelId 后重试。';
            }

            if ($this->currentUser && isset($this->currentUser->id)) {
                OperationLog::record(
                    'online_data',
                    'fetch_ctrip_cookie_api',
                    'Fetch Ctrip data by Cookie and API request list',
                    $this->currentUser->id,
                    $systemHotelId
                );
            }

            $capturedRowCount = (int)($capturedCounts['business'] ?? 0)
                + (int)($capturedCounts['traffic'] ?? 0)
                + (int)($capturedCounts['standard_rows'] ?? 0);
            $savedCount = (int)($saveResult['saved_count'] ?? 0);
            $saveStatus = $saveBlockedIdentity !== null
                ? 'blocked'
                : (!$autoSave
                    ? 'skipped'
                    : ($capturedRowCount === 0
                        ? 'no_parsed_rows'
                        : ($savedCount > 0 ? 'readback_verified' : 'readback_not_verified')));
            $responsePayload = [
                'status' => $readiness['status'],
                'is_ready' => $readiness['is_ready'],
                'next_action' => $readiness['next_action'],
                'warning' => $readiness['warning'],
                'auth_status' => $payload['auth_status'] ?? null,
                'saved_count' => $savedCount,
                'row_count' => $capturedRowCount,
                'counts' => [
                    'business' => (int)($saveResult['business_saved'] ?? 0),
                    'traffic' => (int)($saveResult['traffic_saved'] ?? 0),
                    'standard_rows' => (int)($saveResult['standard_saved'] ?? 0),
                ],
                'captured_counts' => $capturedCounts,
                'diagnosis_summary' => $this->buildCtripCaptureDiagnosisSummary($payload),
                'identity_check' => $saveBlockedIdentity ?? $identityCheck,
                'save_status' => $saveStatus,
                'persistence_status' => $saveStatus,
                'standard_data_type_counts' => $capturedCounts['standard_by_data_type'],
                'standard_section_counts' => $capturedCounts['standard_by_section'],
                'request_count' => count($prepared['config']['endpoints'] ?? []),
                'cookie_source' => 'credential_vault',
                'responses' => array_slice(is_array($payload['responses'] ?? null) ? $payload['responses'] : [], 0, 20),
                'error_count' => count(is_array($payload['errors'] ?? null) ? $payload['errors'] : []),
                'output' => $prepared['output_path'],
            ];

            if ($autoSave && $saveBlockedIdentity === null && $capturedRowCount > 0 && $savedCount <= 0) {
                return json([
                    'code' => 500,
                    'message' => '携程 Cookie API 已解析到数据，但数据库回读未通过；本次不标记为入库成功。',
                    'data' => $responsePayload,
                ], 500);
            }

            return $this->success($responsePayload, $readiness['is_ready'] ? '携程 Cookie API 采集完成并已确认入库' : '携程 Cookie API 未达到诊断就绪');
        } catch (\InvalidArgumentException) {
            return $this->error('携程 Cookie API 业务参数无效', 400);
        } catch (\Throwable $e) {
            \think\facade\Log::error('Ctrip Cookie API fetch failed.', [
                'exception_type' => get_debug_type($e),
            ]);
            return $this->error('携程 Cookie API 采集异常', 500);
        }
    }

    private function buildCtripEndpointEvidenceCatalogPreviewImportPlan(array $validation, array $requestData): array
    {
        $requested = $this->isCtripEndpointEvidenceCatalogPreviewImportRequested($requestData);
        $preview = is_array($validation['catalog_preview'] ?? null) ? $validation['catalog_preview'] : [];
        $rows = array_values(array_filter(
            is_array($preview['standard_rows'] ?? null) ? $preview['standard_rows'] : [],
            static fn($row): bool => is_array($row)
        ));

        $systemHotelId = $this->readPositiveInt($requestData['_resolved_system_hotel_id'] ?? $requestData['system_hotel_id'] ?? $requestData['systemHotelId'] ?? null);
        $dataDate = $this->normalizeOnlineDataDate(
            $requestData['data_date']
            ?? $requestData['dataDate']
            ?? $requestData['report_date']
            ?? $requestData['reportDate']
            ?? ($rows[0]['data_date'] ?? '')
        );
        $requestHotelId = trim((string)(
            $requestData['request_hotel_id']
            ?? $requestData['requestHotelId']
            ?? $requestData['ctrip_hotel_id']
            ?? $requestData['ctripHotelId']
            ?? ($rows[0]['hotel_id'] ?? '')
        ));

        $available = !empty($rows);
        $catalogReady = (bool)($validation['catalog_ready'] ?? false);
        $safeToCatalog = (bool)($validation['safe_to_catalog'] ?? false);
        $canSave = $requested
            && $available
            && $catalogReady
            && $safeToCatalog
            && $systemHotelId !== null
            && $dataDate !== '';

        $message = 'preview only';
        if ($requested && !$available) {
            $message = 'catalog preview has no standard rows';
        } elseif ($requested && (!$catalogReady || !$safeToCatalog)) {
            $message = 'catalog preview is not catalog ready';
        } elseif ($requested && $systemHotelId === null) {
            $message = 'missing system_hotel_id for catalog preview import';
        } elseif ($requested && $dataDate === '') {
            $message = 'missing data_date for catalog preview import';
        } elseif ($canSave) {
            $message = 'catalog preview import ready';
        }

        return [
            'requested' => $requested,
            'available' => $available,
            'can_save' => $canSave,
            'row_count' => count($rows),
            'saved_count' => 0,
            'system_hotel_id' => $systemHotelId,
            'data_date' => $dataDate,
            'request_hotel_id' => $requestHotelId,
            'rows' => $canSave ? $rows : [],
            'message' => $message,
        ];
    }

    private function isCtripEndpointEvidenceCatalogPreviewImportRequested(array $requestData): bool
    {
        foreach (['save_standard_rows', 'saveStandardRows', 'import_catalog_preview', 'importCatalogPreview', 'save_catalog_preview', 'saveCatalogPreview'] as $key) {
            if (array_key_exists($key, $requestData) && $this->isTruthyRequestValue($requestData[$key])) {
                return true;
            }
        }

        return false;
    }

    private function isTruthyRequestValue($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (float)$value !== 0.0;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'y', 'on', 'save', 'import'], true);
        }

        return false;
    }

    private function isFalseRequestValue($value): bool
    {
        if (is_bool($value)) {
            return !$value;
        }
        if (is_int($value) || is_float($value)) {
            return (float)$value === 0.0;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['0', 'false', 'no', 'n', 'off', 'light'], true);
        }

        return false;
    }

    private function readPositiveInt($value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value) || (int)$value <= 0) {
            return null;
        }

        return (int)$value;
    }

    private function buildCtripEndpointEvidenceValidationPayload(array $validation, array $prepared, ?array $candidate, string $candidatePath, string $candidateError, string $stdout, ?array $catalogPreviewImport = null): array
    {
        return [
            'evidence_status' => $validation['evidence_status'] ?? 'unknown',
            'catalog_ready' => (bool)($validation['catalog_ready'] ?? false),
            'safe_to_catalog' => (bool)($validation['safe_to_catalog'] ?? false),
            'candidate_section' => $validation['candidate_section'] ?? '',
            'candidate_label' => $validation['candidate_label'] ?? '',
            'data_type' => $validation['data_type'] ?? '',
            'missing_evidence' => $validation['missing_evidence'] ?? [],
            'field_mapping_draft' => $validation['field_mapping_draft'] ?? [],
            'catalog_preview' => $validation['catalog_preview'] ?? null,
            'paths' => [
                'input' => $prepared['input_path'] ?? '',
                'output' => $prepared['output_path'] ?? '',
                'markdown' => $prepared['markdown_path'] ?? '',
                'candidate_mapping' => $candidatePath,
            ],
            'candidate_mapping' => $candidate,
            'candidate_error' => $candidateError,
            'stdout' => $stdout,
            'catalog_preview_import' => $catalogPreviewImport,
        ];
    }

    public function fetchCtripOverviewData(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $rawRequestData = $this->requestData();
        try {
            if (!is_array($rawRequestData)) {
                throw new \InvalidArgumentException('Invalid Ctrip overview execution request schema.', 400);
            }
            $requestData = $this->sanitizeCtripOverviewExecutionRequestData($rawRequestData);
            $configId = trim((string)($requestData['config_id'] ?? ''));
            $systemHotelId = $this->strictPositiveOtaConfigHotelId($requestData['system_hotel_id'] ?? null);

            return $this->withOtaCredentialForExecution(
                'ctrip',
                $configId,
                $systemHotelId,
                fn(array $credentialPayload): Response => $this->executeCtripOverviewDataFetch(
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
            return $this->otaExecutionStageFailureResponse('ctrip_overview_fetch', $e);
        } catch (\Throwable $e) {
            return $this->otaUnknownExecutionFailureResponse('ctrip_overview_fetch', $e);
        }
    }

    /**
     * @param array<int, mixed> $requestUrls
     * @param array<int, mixed> $xhrUrls
     * @param array<int, mixed> $responses
     * @return array{request_urls: array<int, string>, xhr_urls: array<int, array<string, mixed>>, responses: array<int, array<string, mixed>>}
     */
    private function summarizeCtripOverviewExecutionEvidence(
        array $requestUrls,
        array $xhrUrls,
        array $responses
    ): array {
        $safeRequestUrls = [];
        foreach ($requestUrls as $requestUrl) {
            $url = trim((string)$requestUrl);
            if ($url !== '' && !in_array($url, $safeRequestUrls, true)) {
                $safeRequestUrls[] = $url;
            }
        }

        $summarizeRows = static function (array $rows): array {
            $summaries = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $url = trim((string)($row['url'] ?? $row['request_url'] ?? $row['requestUrl'] ?? ''));
                if ($url === '') {
                    continue;
                }

                $summaries[] = [
                    'url' => $url,
                    'status' => (int)($row['status'] ?? $row['http_code'] ?? 0),
                    'request_type' => strtolower(trim((string)($row['request_type'] ?? $row['method'] ?? ''))),
                ];
            }

            return $summaries;
        };

        return [
            'request_urls' => $safeRequestUrls,
            'xhr_urls' => $summarizeRows($xhrUrls),
            'responses' => $summarizeRows($responses),
        ];
    }

    /**
     * @param array<string, mixed> $requestData
     * @param array<string, mixed> $credentialPayload
     */
    private function executeCtripOverviewDataFetch(
        array $requestData,
        array $credentialPayload,
        int $systemHotelId
    ): Response {
        $hotelId = trim((string)($requestData['hotel_id'] ?? $requestData['hotelId'] ?? $requestData['ctrip_hotel_id'] ?? $requestData['ctripHotelId'] ?? ''));
        $hotelName = trim((string)($requestData['hotel_name'] ?? $requestData['hotelName'] ?? ''));
        $cookies = trim((string)($credentialPayload['cookies'] ?? $credentialPayload['cookie'] ?? ''));
        $requestUrls = $this->normalizeCtripOverviewRequestUrls($requestData['request_urls'] ?? $requestData['requestUrls'] ?? $requestData['url'] ?? '');
        $method = strtoupper(trim((string)($requestData['method'] ?? 'GET')));
        $authData = $credentialPayload['auth_data'] ?? $credentialPayload['authData'] ?? [];
        if (is_string($authData)) {
            $authData = json_decode($authData, true) ?: [];
        }
        $spidertoken = trim((string)(
            $credentialPayload['spidertoken']
            ?? $credentialPayload['spider_token']
            ?? (is_array($authData) ? ($authData['spidertoken'] ?? $authData['spider_token'] ?? $authData['token'] ?? '') : '')
        ));
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

        $basePayload = [];
        $basePayload = $this->buildCtripOverviewRequestPayload($basePayload, $hotelId, $dataDate);

        $responses = [];
        $errors = [];
        $xhrUrls = [];
        foreach ($requestUrls as $requestUrl) {
            if (preg_match('#/datacenter/inland/businessreport/outline(?:\?|$)#i', $requestUrl)) {
                $errors[] = 'overview_page_url_not_api';
                continue;
            }
            if (!$this->isAllowedOtaRequestUrl($requestUrl, ['ctrip.com'])) {
                $errors[] = 'overview_host_not_allowed';
                continue;
            }
            if (!$this->isCtripOverviewApiUrl($requestUrl)) {
                $errors[] = 'overview_api_not_allowed';
                continue;
            }

            $result = $this->sendCtripOverviewRequest($requestUrl, $basePayload, $cookies, $method, $spidertoken);
            $xhrUrls[] = ['url' => $requestUrl, 'status' => (int)($result['http_code'] ?? 0), 'request_type' => strtolower($method)];
            if (!empty($result['error'])) {
                $errors[] = 'overview_request_failed';
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

        $executionEvidence = $this->summarizeCtripOverviewExecutionEvidence($requestUrls, $xhrUrls, $responses);

        if (empty($responses) && !empty($errors)) {
            return $this->error('携程今日概况接口请求失败: ' . implode('；', array_slice($errors, 0, 3)), 400, [
                'errors' => $errors,
                'request_urls' => $executionEvidence['request_urls'],
                'xhr_urls' => $executionEvidence['xhr_urls'],
                'responses' => $executionEvidence['responses'],
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

        $responsePayload = [
            'data' => $overviewRows,
            'total' => count($overviewRows),
            'saved_count' => $savedCount,
            'row_count' => count($overviewRows),
            'persistence_status' => $savedCount > 0 ? 'readback_verified' : (count($overviewRows) > 0 ? 'readback_not_verified' : 'no_parsed_rows'),
            'counts' => ['overview' => count($overviewRows)],
            'metrics' => $this->summarizeCtripOverviewRows($overviewRows),
            'payload_counts' => [
                'responses' => count($responses),
                'xhr_urls' => count($xhrUrls),
            ],
            'request_urls' => $executionEvidence['request_urls'],
            'xhr_urls' => $executionEvidence['xhr_urls'],
            'responses' => $executionEvidence['responses'],
            'errors' => $errors,
        ];

        if (count($overviewRows) > 0 && $savedCount <= 0) {
            return json([
                'code' => 500,
                'message' => '携程今日概况已解析到数据，但数据库回读未通过；本次不标记为入库成功。',
                'data' => $responsePayload,
            ], 500);
        }

        return $this->success($responsePayload, $savedCount > 0 ? '携程今日概况获取完成并已确认入库' : '携程今日概况获取完成，但未解析到可入库概况数据');
    }

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
        if (!$this->isAllowedOtaRequestUrl($url, ['ctrip.com', 'ctripbiz.com', 'ctripbiz.cn', 'meituan.com'])) {
            return $this->error('仅允许请求携程、携程商旅或美团官方域名');
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
        } catch (\Throwable $e) {
            return $this->error('请求异常: ' . $e->getMessage());
        }
    }

    /**
     * 接收来自书签脚本的Cookies（跨域请求）
     * 不需要常规认证，通过 Authorization 头中的 token 识别用户
     */
    /**
     * 返回CORS错误响应
     */
    /**
     * 返回CORS成功响应
     */
    /**
     * Public OTA endpoints bypass the auth middleware, so they keep a small
     * independent rate gate and audit trail. Do not store Cookie/token values.
     *
     * @return array<string, mixed>|null
     */
    /**
     * @param array<string, mixed> $extra
     */
    /**
     * 获取书签脚本代码
     */
    /**
     * 保存Cookies配置（按门店隔离）
     */
    /**
     * 获取已保存的Cookies列表（按门店隔离）
     */
    /**
     * 删除Cookies配置（按门店隔离）
     */
    /**
     * 批量删除Cookies配置（按门店隔离）
     */
    /**
     * 保存美团配置（API地址、Partner ID、POI ID、排名类型、时间维度、Cookies等）
     */
    /**
     * 获取美团配置
     */
    /**
     * 保存美团配置（列表方式，支持多个配置）
     */
    /**
     * 获取美团配置列表
     */
    /**
     * 删除美团配置
     */
    /**
     * 保存美团点评配置（优化版）
     */
    /**
     * 获取美团点评配置列表
     */
    /**
     * 生成美团一键获取Cookies书签脚本
     */

    public function saveCtripConfig(): Response
    {
        $this->checkPermission();

        try {
            $requestData = $this->requestData();
            $config = $this->saveCtripConfigPayload($requestData);
            $this->clearAutoFetchLightConfigListCache('ctrip');
            return json(['code' => 200, 'message' => '配置保存成功', 'data' => $this->sanitizeSecretConfig($config)]);
        } catch (\think\exception\HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        } catch (\InvalidArgumentException $e) {
            return json(['code' => 400, 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            \think\facade\Log::error(sprintf(
                '保存携程配置异常 [%s]: %s',
                get_debug_type($e),
                $e->getMessage()
            ));
            return $this->error('保存失败', 500);
        }
    }

    /**
     * @param array<string, mixed> $requestData
     * @return array<string, mixed>
     */
    private function saveCtripConfigPayload(array $requestData): array
    {
        $idField = trim((string)($requestData['id'] ?? ''));
        $configIdField = trim((string)($requestData['config_id'] ?? $requestData['configId'] ?? ''));
        if ($idField !== '' && $configIdField !== '' && !hash_equals($idField, $configIdField)) {
            throw new \InvalidArgumentException('配置 ID 冲突');
        }
        $id = $idField !== '' ? $idField : $configIdField;
        if ($id !== '' && preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $id) !== 1) {
            throw new \InvalidArgumentException('配置 ID 无效');
        }

        $row = Db::name('system_configs')->where('config_key', 'ctrip_config_list')->find();
        $list = [];
        if ($row) {
            $decoded = json_decode((string)($row['config_value'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new \RuntimeException('携程配置列表格式无效');
            }
            $list = $decoded;
        }

        $isUpdate = $id !== '';
        if ($isUpdate && (!isset($list[$id]) || !is_array($list[$id]))) {
            throw new \InvalidArgumentException('配置不存在');
        }
        $originalConfig = $isUpdate ? $list[$id] : [];

        $hotelCandidates = [];
        foreach (['system_hotel_id', 'systemHotelId', 'hotel_id'] as $field) {
            if (!array_key_exists($field, $requestData) || $requestData[$field] === null || $requestData[$field] === '') {
                continue;
            }
            $hotelCandidates[] = $this->strictPositiveOtaConfigHotelId($requestData[$field]);
        }
        $hotelCandidates = array_values(array_unique($hotelCandidates));
        if (count($hotelCandidates) > 1) {
            throw new \InvalidArgumentException('系统酒店绑定冲突');
        }
        $requestedHotelId = $hotelCandidates[0] ?? null;

        if ($isUpdate) {
            $storedConfigId = trim((string)($originalConfig['config_id'] ?? $originalConfig['id'] ?? $id));
            if ($storedConfigId === '' || !hash_equals($id, $storedConfigId)) {
                throw new \InvalidArgumentException('配置 ID 绑定冲突');
            }
            if ($this->otaConfigHasHotelBindingConflict($originalConfig)) {
                throw new \InvalidArgumentException('配置的酒店绑定冲突，需要先迁移');
            }
            $originalHotelId = $this->otaConfigBoundSystemHotelId($originalConfig);
            if ($originalHotelId === null) {
                throw new \InvalidArgumentException('配置未绑定系统酒店，需要先迁移');
            }
            if ($requestedHotelId !== null && $requestedHotelId !== $originalHotelId) {
                throw new \InvalidArgumentException('不允许变更已有凭据的系统酒店绑定');
            }
            if (!$this->isOtaConfigVisibleToCurrentUser($originalConfig)
                || !$this->currentUserCanMaintainOtaConfigItem($originalConfig, $originalHotelId)) {
                throw new \think\exception\HttpException(403, '无权修改此配置');
            }
            $resolvedHotelId = $originalHotelId;
        } else {
            $resolvedHotelId = $this->resolveOnlineDataSystemHotelId($requestedHotelId);
            if ($resolvedHotelId === null || $resolvedHotelId <= 0) {
                throw new \InvalidArgumentException('请选择系统酒店');
            }
            $this->checkOtaConfigMaintenancePermission($resolvedHotelId);
            $primaryConfig = $this->selectLatestSuccessfulCtripConfigForHotel($list, $resolvedHotelId);
            $primaryConfigId = trim((string)($primaryConfig['config_id'] ?? $primaryConfig['id'] ?? ''));
            if ($primaryConfigId !== ''
                && isset($list[$primaryConfigId])
                && is_array($list[$primaryConfigId])
                && $this->isOtaConfigVisibleToCurrentUser($list[$primaryConfigId])
                && $this->currentUserCanMaintainOtaConfigItem($list[$primaryConfigId], $resolvedHotelId)) {
                $id = $primaryConfigId;
                $isUpdate = true;
                $originalConfig = $list[$primaryConfigId];
            } else {
                $id = 'ctrip_' . date('YmdHis') . '_' . substr(hash('sha256', random_bytes(16)), 0, 8);
            }
        }

        $name = trim((string)($requestData['name'] ?? $originalConfig['name'] ?? ''));
        if ($name === '') {
            $name = '携程Cookie ' . date('Y-m-d');
        }
        [, $secretPayload] = $this->splitOtaConfigSecrets($requestData);
        if (!$isUpdate && !$this->otaSecretPayloadHasNonEmptyScalar($secretPayload)) {
            throw new \InvalidArgumentException('临时 Cookie/API 辅助内容不能为空');
        }

        $ctripHotelId = trim((string)(
            $requestData['ctrip_hotel_id']
            ?? $requestData['ctripHotelId']
            ?? $requestData['ota_hotel_id']
            ?? $requestData['otaHotelId']
            ?? $originalConfig['ctrip_hotel_id']
            ?? $originalConfig['ctripHotelId']
            ?? $originalConfig['ota_hotel_id']
            ?? $originalConfig['otaHotelId']
            ?? ''
        ));
        $hotelRoomCount = $this->requiredPositiveCtripRoomCount(
            $requestData['hotel_room_count'] ?? $requestData['hotelRoomCount'] ?? null,
            '酒店实际房量'
        );
        $competitorRoomCount = $this->requiredPositiveCtripRoomCount(
            $requestData['competitor_room_count'] ?? $requestData['competitorRoomCount'] ?? null,
            '竞争圈总房量'
        );
        $captureOptions = $this->buildCtripProfileCaptureConfigOptions($requestData, $originalConfig);
        if ($captureOptions['approved_mappings_path'] !== '') {
            $mappingCheck = $this->resolveCtripApprovedMappingsPath(
                ['approved_mappings_path' => $captureOptions['approved_mappings_path']],
                dirname(__DIR__, 3)
            );
            if ($mappingCheck['path'] === '') {
                throw new \InvalidArgumentException((string)$mappingCheck['error']);
            }
        }

        $safeOriginal = $this->sanitizeSecretConfig($originalConfig);
        $userId = $this->currentUser->isSuperAdmin()
            ? ($safeOriginal['user_id'] ?? null)
            : $this->currentUser->id;
        $config = array_merge($safeOriginal, [
            'id' => $id,
            'config_id' => $id,
            'name' => $name,
            'hotel_id' => (string)$resolvedHotelId,
            'system_hotel_id' => $resolvedHotelId,
            'ctrip_hotel_id' => $ctripHotelId,
            'ctripHotelId' => $ctripHotelId,
            'ota_hotel_id' => $ctripHotelId,
            'hotel_room_count' => $hotelRoomCount,
            'competitor_room_count' => $competitorRoomCount,
            'url' => $requestData['url'] ?? ($safeOriginal['url'] ?? ''),
            'node_id' => $requestData['node_id'] ?? ($safeOriginal['node_id'] ?? ''),
            'user_id' => $userId,
            'update_time' => date('Y-m-d H:i:s'),
            'created_at' => $safeOriginal['created_at'] ?? date('Y-m-d H:i:s'),
        ], $captureOptions, $secretPayload);

        return $this->persistCtripConfigMetadata($config, (int)$this->currentUser->id, $isUpdate);
    }

    private function requiredPositiveCtripRoomCount(mixed $value, string $label): int
    {
        if (is_bool($value) || is_float($value)) {
            throw new \think\exception\HttpException(422, $label . '必须为正整数');
        }

        $text = trim((string)$value);
        if (preg_match('/^[1-9]\d*$/D', $text) !== 1 || (int)$text > 1000000) {
            throw new \think\exception\HttpException(422, $label . '必须为1-1000000之间的正整数');
        }

        return (int)$text;
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
            $list = $raw ? json_decode((string)$raw, true, 512, JSON_THROW_ON_ERROR) : [];
            if (!is_array($list)) {
                $list = [];
            }
            $list = $this->normalizeStoredOtaConfigList('system_configs', $key, $list, 'ctrip');

            $list = $this->filterOtaConfigListForCurrentUser($list);
            $list = $this->sanitizeStoredOtaConfigListForRuntime($list);
            $list = $this->collapseCtripConfigListByHotel($list);
            $list = $this->appendOtaConfigCollectionEvidence(array_values($list), 'ctrip');

            return $this->success(array_values($list));
        } catch (\Throwable $e) {
            \think\facade\Log::error('获取携程配置列表失败: ' . $e->getMessage(), ['exception' => $e]);
            return $this->error('获取携程配置列表失败', 500);
        }
    }

    public function getCtripConfigDetail(): Response
    {
        $this->checkPermission();

        $id = trim((string)$this->request->get('id', ''));
        if ($id === '') {
            return $this->error('Config id is required.');
        }

        $key = 'ctrip_config_list';
        $raw = \think\facade\Db::name('system_configs')->where('config_key', $key)->value('config_value');
        $list = $raw ? json_decode((string)$raw, true, 512, JSON_THROW_ON_ERROR) : [];
        if (!is_array($list)) {
            $list = [];
        }
        $list = $this->normalizeStoredOtaConfigList('system_configs', $key, $list, 'ctrip');

        if (!isset($list[$id])) {
            return $this->error('Config not found.', 404);
        }
        $storedConfigId = trim((string)($list[$id]['config_id'] ?? $list[$id]['id'] ?? $id));
        if ($storedConfigId === '' || !hash_equals($id, $storedConfigId)) {
            return $this->error('Config binding conflict.', 409);
        }
        if (!$this->isOtaConfigVisibleToCurrentUser($list[$id])) {
            return $this->error('Forbidden', 403);
        }
        if (!$this->currentUserCanMaintainOtaConfigItem($list[$id])) {
            return $this->error('Forbidden', 403);
        }

        $safeList = $this->sanitizeStoredOtaConfigListForRuntime([$id => $list[$id]]);
        return $this->success($safeList[$id] ?? []);
    }

    /**
     * 删除携程配置
     */
    public function deleteCtripConfig(): Response
    {
        $this->checkPermission();
        $id = trim((string)$this->request->param('id', ''));
        if ($id === '') {
            return $this->error('配置ID不能为空');
        }
        if (preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $id) !== 1) {
            return $this->error('配置ID无效');
        }

        try {
            $key = 'ctrip_config_list';
            $existing = Db::name('system_configs')->where('config_key', $key)->find();
            if (!$existing) {
                return $this->error('配置不存在', 404);
            }

            $list = json_decode((string)$existing['config_value'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($list) || !isset($list[$id]) || !is_array($list[$id])) {
                return $this->error('配置不存在', 404);
            }
            $config = $list[$id];
            $storedConfigId = trim((string)($config['config_id'] ?? $config['id'] ?? $id));
            if ($storedConfigId === '' || !hash_equals($id, $storedConfigId)) {
                return $this->error('配置 ID 绑定冲突，拒绝删除', 409);
            }
            if ($this->otaConfigHasHotelBindingConflict($config)) {
                return $this->error('配置的酒店绑定冲突，拒绝删除', 409);
            }
            $systemHotelId = $this->otaConfigBoundSystemHotelId($config);
            if ($systemHotelId === null) {
                return $this->error('配置未绑定系统酒店，拒绝删除', 409);
            }

            if (!$this->isOtaConfigVisibleToCurrentUser($config)) {
                return $this->error('无权删除此配置', 403);
            }
            if (!$this->currentUserCanMaintainOtaConfigItem($config, $systemHotelId)) {
                $this->checkActionPermission('can_delete_online_data');
            }

            $name = (string)($config['name'] ?? '');
            $this->deleteCtripConfigMetadata($id, $systemHotelId);

            \app\model\SystemConfig::clearProtectedOtaCaches();
            $this->clearAutoFetchLightConfigListCache('ctrip');

            OperationLog::record('online_data', 'delete_ctrip_config', "删除携程配置: {$name}", $this->currentUser->id);

            return $this->success(null, '删除成功');
        } catch (\think\exception\HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 400);
        } catch (\Throwable $e) {
            \think\facade\Log::error('删除携程配置失败: ' . $e->getMessage(), ['exception' => $e]);
            return $this->error('删除携程配置失败', 500);
        }
    }

    /**
     * 生成携程一键获取Cookies书签脚本
     */
    public function generateCtripBookmarklet(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $script = $this->buildDisabledCookieBookmarkletScript('携程');

        return $this->success([
            'script' => $script,
            'bookmarklet' => 'javascript:' . $script,
            'status' => 'disabled_by_policy',
            'message' => '旧版携程 Cookie 书签已禁用，避免把宿析登录 token 暴露到 OTA 页面。',
        ], '旧版携程 Cookie 书签已禁用');
    }

    /**
     * 自动捕获携程Cookie（从请求头中获取）
     */
    public function autoCaptureCtripCookie(): Response
    {
        return $this->error(
            '旧版携程 Cookie 自动捕获入口已禁用。请使用平台采集源凭据或门店浏览器 Profile。',
            410
        );
    }

    /**
     * 通过书签保存携程配置（接收Cookie数据）
     */
    public function saveCtripConfigByBookmark(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');
        return $this->error(
            '旧版携程 Cookie 书签保存入口已禁用。请在平台采集源中保存凭据。',
            410
        );
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
                'error' => "HTTP错误: {$httpCode}" . ($httpCode === 302 ? ' (Cookie已失效，请重新登录携程)' : ''),
                'http_code' => $httpCode,
                'raw' => $decodedResponse,
            ];
        }

        // 检查是否返回了HTML而不是JSON
        if (preg_match('/^\s*<!DOCTYPE|^\s*<html/i', $decodedResponse)) {
            return [
                'success' => false,
                'error' => '返回了HTML页面而非JSON数据，未获取到业务数据；请检查登录状态与请求参数后重试',
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
                $result['error'] = 'Cookie已失效，请重新登录携程 eBooking 后复制 Cookie';
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
                $result['error'] = 'Cookie已失效，请重新登录携程 eBooking 后复制 Cookie';
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
}
