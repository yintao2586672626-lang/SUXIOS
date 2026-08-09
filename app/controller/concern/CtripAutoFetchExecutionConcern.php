<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\model\OperationLog;
use app\model\SystemNotification;
use app\service\BrowserProfileCaptureRequestService;
use app\service\CtripCollectorWorkflowService;
use app\service\OtaProfileBindingService;
use app\service\OtaProfileSessionProofService;
use app\service\OtaFailureNotificationService;
use app\service\OnlineDailyDataPersistenceService;
use app\service\PlatformProfileBindingReadinessService;
use app\service\PlatformDataSyncService;
use app\service\ScheduledAutoFetchPolicy;
use think\Response;
use think\facade\Db;

trait CtripAutoFetchExecutionConcern
{
    private function executeCtripAutoFetch(int $hotelId, string $dataDate, array $options = []): array
    {
        $collectorFlow = (new CtripCollectorWorkflowService())->normalizeFlow(
            $options['ctrip_collector_flow']
            ?? $options['ctripCollectorFlow']
            ?? $options['collector_flow']
            ?? $options['collectorFlow']
            ?? ''
        );
        if ($collectorFlow !== '') {
            $flowOptions = (new CtripCollectorWorkflowService())->applyFlowOptions([
                'collector_flow' => $collectorFlow,
                'capture_plan' => $options['ctrip_capture_plan']
                    ?? $options['ctripCapturePlan']
                    ?? null,
                'bounded_capture_sections' => $options['ctrip_capture_sections']
                    ?? $options['ctripCaptureSections']
                    ?? '',
            ]);
            $options = array_replace($options, $flowOptions);
        }
        $fetchConfig = $this->resolveCtripFetchConfigForHotel($hotelId);
        $mode = $this->resolvePlatformAutoFetchMode($fetchConfig, $options, 'ctrip');
        $runCookieConfig = $this->shouldRunCookieConfigTasks($mode);
        $browserProfileSources = $this->listCollectableCtripBrowserProfileDataSources($hotelId);
        $runProfileBrowser = $this->shouldRunCtripProfileBrowser($mode, $browserProfileSources);
        $taskPlanForConfig = $this->buildAutoFetchConfigTaskPlan($hotelId, $dataDate, $fetchConfig, []);
        $hasConfiguredTask = (bool)array_filter($taskPlanForConfig, static fn(array $task): bool => ($task['platform'] ?? '') === 'ctrip');
        $hasProfile = $this->ctripProfileExistsForConfig($fetchConfig, $hotelId);
        $hasProfileSeed = !empty($fetchConfig) && $this->ctripProfileStoreIdFromConfig($fetchConfig, $hotelId) !== '';

        $hasDirectConfig = $hasConfiguredTask;
        $hasProfileConfig = $runProfileBrowser && ($hasProfile || $hasProfileSeed || $browserProfileSources !== []);
        if (!$hasDirectConfig && !$hasProfileConfig) {
            $message = $runProfileBrowser
                ? '未配置携程浏览器 Profile'
                : '未配置携程 Cookie/接口配置';
            return [
                'platform' => 'ctrip',
                'success' => false,
                'message' => $message,
                'saved_count' => 0,
                'auto_fetch_mode' => $mode,
                'mode_label' => $this->autoFetchModeLabel($mode),
                'modules' => [
                    $this->withAutoFetchResultMeta(['module' => 'configuration', 'saved_count' => 0, 'success' => false, 'skipped' => true, 'message' => $message], $runProfileBrowser ? 'profile_browser' : 'cookie_config'),
                ],
            ];
        }

        $savedCount = 0;
        $errors = [];
        $modules = [];
        $browserResult = [];

        if (!$runCookieConfig) {
            $modules[] = $this->withAutoFetchResultMeta(['module' => 'cookie_config_tasks', 'saved_count' => 0, 'success' => false, 'skipped' => true, 'message' => '当前策略仅使用浏览器 Profile'], 'cookie_config');
        }

        if ($runCookieConfig) {
            foreach ($taskPlanForConfig as $task) {
                if (($task['platform'] ?? '') !== 'ctrip') {
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

        if ($runProfileBrowser) {
            $runProfileByCost = $this->shouldRunCtripProfileBrowserForCost($mode, $savedCount, $browserProfileSources);
            if ($runProfileByCost) {
                $browserResult = $this->syncCtripBrowserProfileDataSourcesForAutoFetch(
                    $hotelId,
                    $dataDate,
                    !empty($options['interactive_browser']),
                    $browserProfileSources,
                    $options
                );
                if (empty($browserResult['attempted'])) {
                    $browserResult = $this->executeCtripBrowserProfileAutoFetch($fetchConfig, $hotelId, $dataDate, !empty($options['interactive_browser']), $options);
                }
            } else {
                $browserResult = [
                    'success' => false,
                    'skipped' => true,
                    'message' => '当前策略未启动 Profile',
                    'saved_count' => 0,
                ];
            }
            if (empty($browserResult['skipped'])) {
                $savedCount += (int)($browserResult['saved_count'] ?? 0);
            }
            $browserModule = $this->withAutoFetchResultMeta([
                'module' => 'browser_profile',
                'saved_count' => (int)($browserResult['saved_count'] ?? 0),
                'success' => (bool)($browserResult['success'] ?? false),
                'message' => (string)($browserResult['message'] ?? ''),
                'skipped' => (bool)($browserResult['skipped'] ?? false),
            ], 'profile_browser');
            $modules[] = $browserModule;

            if (!empty($browserResult['message']) && empty($browserResult['success']) && empty($browserResult['skipped'])) {
                $prefix = ($browserModule['status_code'] ?? '') === 'needs_profile'
                    ? 'browser_profile 需重新登录'
                    : 'browser';
                $errors[] = $prefix . ' ' . $browserResult['message'];
            } elseif (!empty($browserResult['skipped'])) {
                $errors[] = (string)$browserResult['message'];
            }
        }

        $runReadback = is_array($browserResult['run_readback'] ?? null) ? $browserResult['run_readback'] : [];
        $coreReadbackVerified = $this->autoFetchRunReadbackCoreVerified($runReadback);
        if ($savedCount > 0) {
            \think\facade\Log::info("携程自动获取已写入", [
                'hotel_id' => $hotelId,
                'count' => $savedCount,
                'core_readback_verified' => $coreReadbackVerified,
            ]);
            $this->updateCtripLatestFetchStatus($hotelId, date('Y-m-d H:i:s'), $dataDate, $savedCount);

            $message = $coreReadbackVerified
                ? "完成 {$savedCount} 次写入并验证本次任务核心指标回执"
                : "已发生 {$savedCount} 次写入，但本次任务、入库行、来源追踪与收入/间夜/ADR 回执未完整绑定";
            return ['platform' => 'ctrip', 'success' => $this->autoFetchPlatformRunSucceeded($savedCount, $runReadback), 'message' => $message, 'saved_count' => $savedCount, 'data_period' => $options['data_period'] ?? 'historical_daily', 'auto_fetch_mode' => $mode, 'mode_label' => $this->autoFetchModeLabel($mode), 'modules' => $modules, 'run_readback' => $runReadback, 'timing' => is_array($browserResult['timing'] ?? null) ? $browserResult['timing'] : []];
        }

        $message = empty($errors)
            ? '未获取到有效数据'
            : '未获取到有效数据：' . implode('；', array_slice($errors, 0, 3));
        return ['platform' => 'ctrip', 'success' => false, 'message' => $message, 'saved_count' => 0, 'data_period' => $options['data_period'] ?? 'historical_daily', 'auto_fetch_mode' => $mode, 'mode_label' => $this->autoFetchModeLabel($mode), 'modules' => $modules, 'run_readback' => $runReadback, 'timing' => is_array($browserResult['timing'] ?? null) ? $browserResult['timing'] : []];
    }

    private function executeAutoFetchTask(array $task, int $hotelId, string $dataDate): array
    {
        $body = is_array($task['body'] ?? null) ? $task['body'] : [];
        $module = (string)($task['module'] ?? '');
        $label = (string)($task['label'] ?? $module);
        $strategy = (string)($task['strategy'] ?? 'cookie_config');

        try {
            $result = match (($task['platform'] ?? '') . ':' . $module) {
                'ctrip:business' => $this->executeCtripBusinessAutoFetchTask($label, $body, $hotelId),
                'ctrip:cookie_api' => $this->executeCtripCookieApiAutoFetchTask($label, $body, $hotelId, $dataDate),
                'ctrip:traffic' => $this->executeCtripTrafficAutoFetchTask($label, $body, $hotelId),
                'ctrip:comments' => $this->executeCtripBrowserProfileAutoFetch(
                    array_merge($body, ['capture_sections' => 'comment_review']),
                    $hotelId,
                    $dataDate,
                    false,
                    ['capture_sections' => 'comment_review']
                ),
                'meituan:comments' => $this->executeMeituanBrowserProfileAutoFetch(
                    array_merge($body, ['capture_sections' => 'reviews']),
                    $hotelId,
                    $dataDate,
                    false,
                    ['capture_sections' => 'reviews']
                ),
                'meituan:ranking' => $this->executeMeituanRankingAutoFetchTask($label, $body, $hotelId),
                'meituan:traffic' => $this->executeMeituanTrafficAutoFetchTask($label, $body, $hotelId),
                default => ['module' => $label, 'saved_count' => 0, 'success' => false, 'skipped' => true, 'message' => 'unsupported task'],
            };
            return $this->withAutoFetchResultMeta($result, $strategy, $label);
        } catch (\Throwable $e) {
            try {
                \think\facade\Log::warning('OTA auto-fetch task failed', [
                    'hotel_id' => $hotelId,
                    'module' => $module,
                    'exception_type' => get_debug_type($e),
                ]);
            } catch (\Throwable) {
                // Logging failure must not replace the explicit credential execution failure.
            }
            return $this->withAutoFetchResultMeta(['module' => $label, 'saved_count' => 0, 'success' => false, 'message' => 'credential_execution_failed'], $strategy, $label);
        }
    }

    private function withAutoFetchCredential(
        string $platform,
        array $body,
        int $hotelId,
        callable $consumer
    ): mixed {
        $configId = trim((string)($body['config_id'] ?? ''));
        $boundHotelId = (int)($body['system_hotel_id'] ?? 0);
        if ($configId === '' || $boundHotelId !== $hotelId) {
            throw new \RuntimeException('auto_fetch_credential_locator_invalid');
        }

        return $this->withOtaCredentialForExecution(
            $platform,
            $configId,
            $hotelId,
            $consumer,
            true
        );
    }

    private function autoFetchCredentialCookieHeader(array $credentialPayload): string
    {
        $value = $credentialPayload['cookies'] ?? $credentialPayload['cookie'] ?? null;
        return is_scalar($value) ? trim((string)$value) : '';
    }

    private function autoFetchCredentialAuthData(array $credentialPayload): array
    {
        return $this->configValueToArray($credentialPayload['auth_data'] ?? []);
    }

    private function executeCtripBusinessAutoFetchTask(string $label, array $body, int $hotelId): array
    {
        return $this->withAutoFetchCredential('ctrip', $body, $hotelId, function (array $credentialPayload) use ($label, $body, $hotelId): array {
            $cookieHeader = $this->autoFetchCredentialCookieHeader($credentialPayload);
            if ($cookieHeader === '') {
                return ['module' => $label, 'saved_count' => 0, 'success' => false, 'message' => 'credential_payload_missing_cookie'];
            }

            $startDate = (string)($body['start_date'] ?? '');
            $endDate = (string)($body['end_date'] ?? $startDate);
            $result = $this->sendHttpRequest(
                (string)($body['url'] ?? 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportCompeteHotelReport'),
                ['nodeId' => (string)($body['node_id'] ?? '24588'), 'startDate' => $startDate, 'endDate' => $endDate],
                $cookieHeader
            );
            if (empty($result['success']) || !is_array($result['data'] ?? null)) {
                return ['module' => $label, 'saved_count' => 0, 'success' => false, 'message' => 'ctrip_request_failed'];
            }

            $responseData = $result['data'];
            $responseStatus = $responseData['responseStatus'] ?? $responseData['status'] ?? $responseData['code'] ?? null;
            if ($responseStatus !== null && !in_array($responseStatus, [0, '0', 200, '200'], true)) {
                return ['module' => $label, 'saved_count' => 0, 'success' => false, 'message' => 'ctrip_api_rejected'];
            }

            $expectedPlatformHotelId = trim((string)(
                $credentialPayload['platform_hotel_id']
                ?? $credentialPayload['ctrip_hotel_id']
                ?? $credentialPayload['ota_hotel_id']
                ?? $credentialPayload['hotel_id']
                ?? ''
            ));
            $persistenceContext = [
                'ingestion_method' => 'manual_cookie_api',
                'config_id' => trim((string)($body['config_id'] ?? '')),
            ];
            if ($this->isMeaningfulCtripPlatformHotelId($expectedPlatformHotelId, $hotelId)) {
                $persistenceContext['self_hotel_ids'] = [$expectedPlatformHotelId];
            }
            $savedCount = $this->parseAndSaveData($responseData, $startDate, $endDate, $hotelId, $persistenceContext);
            return [
                'module' => $label,
                'saved_count' => $savedCount,
                'success' => $savedCount > 0,
                'message' => $savedCount > 0 ? 'ok' : 'no_rows',
                'credential_source' => 'vault',
            ];
        });
    }

    private function executeCtripCookieApiAutoFetchTask(string $label, array $body, int $hotelId, string $dataDate): array
    {
        return $this->withAutoFetchCredential('ctrip', $body, $hotelId, function (array $credentialPayload) use ($label, $body, $hotelId, $dataDate): array {
            return $this->executeCtripCookieApiAutoFetchWithCredential($label, $body, $hotelId, $dataDate, $credentialPayload);
        });
    }

    private function executeCtripCookieApiAutoFetchWithCredential(
        string $label,
        array $body,
        int $hotelId,
        string $dataDate,
        array $credentialPayload
    ): array
    {
        $requestData = $body;
        foreach (['headers', 'headers_json', 'spidertoken', 'auth_data'] as $credentialField) {
            if (array_key_exists($credentialField, $credentialPayload)) {
                $requestData[$credentialField] = $credentialPayload[$credentialField];
            }
        }
        $requestData['system_hotel_id'] = $requestData['system_hotel_id'] ?? $hotelId;
        $requestData['data_date'] = $this->normalizeOnlineDataDate($requestData['data_date'] ?? $requestData['dataDate'] ?? $dataDate);
        if ((string)$requestData['data_date'] === '') {
            $requestData['data_date'] = $dataDate;
        }

        $hasRequestList = false;
        foreach (['endpoints', 'requests', 'request_urls', 'requestUrls', 'endpoints_json', 'endpointsJson', 'request_url', 'requestUrl', 'url'] as $key) {
            if (!array_key_exists($key, $requestData)) {
                continue;
            }
            $value = $requestData[$key];
            if (is_array($value) ? !empty($value) : trim((string)$value) !== '') {
                $hasRequestList = true;
                break;
            }
        }
        if (!$hasRequestList) {
            return ['module' => $label, 'saved_count' => 0, 'success' => false, 'skipped' => true, 'message' => 'missing Ctrip request_url list config'];
        }

        $autoSave = !array_key_exists('auto_save', $requestData) && !array_key_exists('autoSave', $requestData)
            ? true
            : $this->isTruthyRequestValue($requestData['auto_save'] ?? $requestData['autoSave'] ?? false);
        $cookieHeader = $this->autoFetchCredentialCookieHeader($credentialPayload);
        if ($cookieHeader === '') {
            return ['module' => $label, 'saved_count' => 0, 'success' => false, 'message' => 'credential_payload_missing_cookie'];
        }
        $projectRoot = dirname(__DIR__, 3);
        $scriptPath = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'ctrip_cookie_api_capture.mjs';
        if (!is_file($scriptPath)) {
            return ['module' => $label, 'saved_count' => 0, 'success' => false, 'message' => 'missing Ctrip API capture script'];
        }

        $nodeBinary = BrowserProfileCaptureRequestService::resolveNodeBinary();
        if ($nodeBinary === '') {
            return ['module' => $label, 'saved_count' => 0, 'success' => false, 'message' => 'missing Node.js'];
        }

        $cookieFile = '';
        $inputPath = '';
        try {
            $prepared = $this->prepareCtripCookieApiCaptureFiles($requestData, $projectRoot, $hotelId);
            $inputPath = (string)($prepared['input_path'] ?? '');
            $cookieFile = $this->createAutoFetchCookieFile($projectRoot, 'ctrip_api', $hotelId, $cookieHeader);
            if ($cookieFile === '') {
                return ['module' => $label, 'saved_count' => 0, 'success' => false, 'message' => 'failed to create Ctrip Cookie temp file'];
            }

            $runResult = $this->runMeituanCaptureProcess([
                $nodeBinary,
                $scriptPath,
                '--input=' . $prepared['input_path'],
                '--cookies-file=' . $cookieFile,
                '--output=' . $prepared['output_path'],
            ], $projectRoot, 90);
            if (!$runResult['success']) {
                return [
                    'module' => $label,
                    'saved_count' => 0,
                    'success' => false,
                    'message' => 'ctrip_cookie_api_capture_failed',
                ];
            }

            $payload = $this->readLocalJsonFile((string)$prepared['output_path']);
            $capturedCounts = $this->buildCtripCaptureCounts($payload);
            $saveResult = [
                'saved_count' => 0,
                'business_saved' => 0,
                'traffic_saved' => 0,
                'standard_saved' => 0,
                'modules' => [],
            ];
            if ($autoSave) {
                $requestHotelId = trim((string)($payload['hotel_id'] ?? $prepared['config']['hotel_id'] ?? $requestData['hotel_id'] ?? $requestData['ctrip_hotel_id'] ?? $hotelId));
                $saveResult = $this->saveCtripBrowserProfilePayload(
                    $payload,
                    $hotelId,
                    (string)$requestData['data_date'],
                    $requestHotelId,
                    null,
                    [],
                    ['ingestion_method' => 'manual_cookie_api']
                );
            }

            $savedCount = (int)($saveResult['saved_count'] ?? 0);
            $standardRows = (int)($capturedCounts['standard_rows'] ?? 0);
            $success = $autoSave ? $savedCount > 0 : $standardRows > 0;
            $payloadErrors = is_array($payload['errors'] ?? null) ? $payload['errors'] : [];
            $message = $success
                ? 'ok'
                : ($standardRows > 0 ? 'captured rows but not saved' : 'no standard diagnosis rows');
            $readiness = $this->buildCtripCookieApiReadiness($payload, $capturedCounts, $saveResult, $autoSave);

            return [
                'module' => $label,
                'saved_count' => $savedCount,
                'success' => $success,
                'message' => $message,
                'status' => $readiness['status'],
                'is_ready' => $readiness['is_ready'],
                'next_action' => $readiness['is_ready'] ? '' : $readiness['next_action'],
                'warning' => $readiness['warning'],
                'row_count' => $standardRows,
                'counts' => [
                    'business' => (int)($saveResult['business_saved'] ?? 0),
                    'traffic' => (int)($saveResult['traffic_saved'] ?? 0),
                    'standard_rows' => (int)($saveResult['standard_saved'] ?? 0),
                ],
                'captured_counts' => $capturedCounts,
                'diagnosis_summary' => $this->buildCtripCaptureDiagnosisSummary($payload),
                'request_count' => count($prepared['config']['endpoints'] ?? []),
                'cookie_source' => 'credential_vault',
                'error_count' => count($payloadErrors),
            ];
        } catch (\InvalidArgumentException $e) {
            return [
                'module' => $label,
                'saved_count' => 0,
                'success' => false,
                'message' => 'ctrip_cookie_api_request_invalid',
            ];
        } catch (\Throwable $e) {
            \think\facade\Log::warning('Ctrip Cookie API auto-fetch failed', [
                'hotel_id' => $hotelId,
                'exception_type' => get_debug_type($e),
            ]);
            return ['module' => $label, 'saved_count' => 0, 'success' => false, 'message' => 'ctrip_cookie_api_failed'];
        } finally {
            $this->removeAutoFetchCookieFile($cookieFile);
            if ($inputPath !== '' && is_file($inputPath)) {
                @unlink($inputPath);
            }
        }
    }

    private function executeCtripTrafficAutoFetchTask(string $label, array $body, int $hotelId): array
    {
        return $this->withAutoFetchCredential('ctrip', $body, $hotelId, function (array $credentialPayload) use ($label, $body, $hotelId): array {
            return $this->executeCtripTrafficAutoFetchWithCredential($label, $body, $hotelId, $credentialPayload);
        });
    }

    private function executeCtripTrafficAutoFetchWithCredential(string $label, array $body, int $hotelId, array $credentialPayload): array
    {
        $cookieHeader = $this->autoFetchCredentialCookieHeader($credentialPayload);
        if ($cookieHeader === '') {
            return ['module' => $label, 'saved_count' => 0, 'success' => false, 'message' => 'credential_payload_missing_cookie'];
        }

        [$startDate, $endDate] = $this->buildCtripTrafficDateRange('custom', (string)($body['start_date'] ?? ''), (string)($body['end_date'] ?? ''));
        $extraParams = $this->configValueToArray($credentialPayload['extra_params'] ?? []);
        $spiderkeyValue = $credentialPayload['spiderkey'] ?? $credentialPayload['spider_key'] ?? ($extraParams['spiderkey'] ?? '');
        $spiderkey = is_scalar($spiderkeyValue) ? trim((string)$spiderkeyValue) : '';
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

        $result = $this->sendCtripJsonRequest($this->normalizeCtripTrafficUrl((string)($body['url'] ?? '')), $postData, $cookieHeader);
        if (!empty($result['error'])) {
            $this->recordCookieAlert(strtolower($platform), 'auto-fetch-ctrip-traffic', 'ctrip_traffic_request_failed', $hotelId);
            return ['module' => $label, 'saved_count' => 0, 'success' => false, 'message' => 'ctrip_traffic_request_failed'];
        }

        $responseData = $result['decoded_data'];
        $apiError = $this->getCtripTrafficApiError($responseData);
        if ($apiError !== '') {
            $this->recordCookieAlert(strtolower($platform), 'auto-fetch-ctrip-traffic', 'ctrip_traffic_api_rejected', $hotelId);
            return ['module' => $label, 'saved_count' => 0, 'success' => false, 'message' => 'ctrip_traffic_api_rejected'];
        }

        $expectedPlatformHotelId = trim((string)(
            $credentialPayload['platform_hotel_id']
            ?? $credentialPayload['ctrip_hotel_id']
            ?? $credentialPayload['ota_hotel_id']
            ?? $credentialPayload['hotel_id']
            ?? ''
        ));
        $savedCount = is_array($responseData)
            ? $this->parseAndSaveTrafficData($responseData, $startDate, $endDate, strtolower($platform), $hotelId, $platform, $expectedPlatformHotelId)
            : 0;
        return ['module' => $label, 'saved_count' => $savedCount, 'success' => $savedCount > 0, 'message' => $savedCount > 0 ? 'ok' : 'no rows'];
    }

    private function buildCtripCookieApiReadiness(array $payload, array $capturedCounts, array $saveResult, bool $autoSave): array
    {
        $standardRows = (int)($capturedCounts['standard_rows'] ?? 0);
        $savedCount = (int)($saveResult['saved_count'] ?? 0);
        $authStatus = is_array($payload['auth_status'] ?? null) ? $payload['auth_status'] : [];
        $authOk = (bool)($authStatus['ok'] ?? false);
        $errors = is_array($payload['errors'] ?? null) ? $payload['errors'] : [];
        $ready = $autoSave ? $savedCount > 0 : $standardRows > 0;
        if ($ready) {
            return [
                'status' => 'ready',
                'is_ready' => true,
                'next_action' => '可直接生成携程诊断',
                'warning' => '',
            ];
        }

        if (!$authOk) {
            $nextAction = '更新 Cookie 或重新登录携程 Profile 后重试';
        } elseif ($standardRows === 0 && $errors !== []) {
            $nextAction = '检查携程 Cookie、Request URL、Payload 和账号权限';
        } elseif ($standardRows === 0) {
            $nextAction = '补充可返回业务 JSON 的携程诊断接口';
        } else {
            $nextAction = '已抓到标准诊断行但未入库，请检查 system_hotel_id、携程酒店 ID 和入库日志';
        }

        return [
            'status' => 'not_ready',
            'is_ready' => false,
            'next_action' => $nextAction,
            'warning' => $nextAction,
        ];
    }

    private function executeCtripBrowserProfileAutoFetch(array $config, int $hotelId, string $dataDate, bool $interactiveBrowser = false, array $periodOptions = []): array
    {
        $profileId = $this->ctripProfileStoreIdFromConfig($config, $hotelId);
        if ($profileId === '') {
            return ['success' => false, 'skipped' => true, 'message' => '未配置携程 Profile ID', 'saved_count' => 0];
        }
        $profileSource = $this->loadProfileSessionSource('ctrip', $hotelId, $profileId);
        if (!$interactiveBrowser) {
            $reuseState = (new OtaProfileSessionProofService())->profileReuseState($profileSource ?? []);
            if (empty($reuseState['is_reusable'])) {
                $statusCode = ($reuseState['status'] ?? '') === 'expired'
                    ? 'profile_session_expired'
                    : 'profile_session_unverified';
                return ['success' => false, 'skipped' => true, 'message' => $statusCode, 'status_code' => $statusCode, 'saved_count' => 0];
            }
        }
        if (!$this->ctripProfileExistsForConfig($config, $hotelId) && !$interactiveBrowser) {
            return ['success' => false, 'skipped' => true, 'message' => "未找到 storage/ctrip_profile_{$profileId}", 'saved_count' => 0];
        }

        $projectRoot = dirname(__DIR__, 3);
        $scriptPath = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'ctrip_browser_capture.mjs';
        if (!is_file($scriptPath)) {
            return ['success' => false, 'skipped' => true, 'message' => '未找到携程浏览器采集脚本', 'saved_count' => 0];
        }

        $nodeBinary = BrowserProfileCaptureRequestService::resolveNodeBinary();
        if ($nodeBinary === '') {
            return ['success' => false, 'skipped' => true, 'message' => '未找到 Node.js', 'saved_count' => 0];
        }

        $outputDir = $projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'ctrip_capture';
        if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
            return ['success' => false, 'message' => '无法创建携程采集输出目录', 'saved_count' => 0];
        }

        $outputPath = $outputDir . DIRECTORY_SEPARATOR . 'ctrip_browser_auto_' . BrowserProfileCaptureRequestService::safeFilePart($profileId) . '_' . date('YmdHis') . '.json';
        $fieldConfigPayload = $this->buildCtripProfileFieldConfigPayload($this->readCtripProfileCaptureFields(true));
        $sectionRequest = [
            'sections' => $periodOptions['capture_sections']
                ?? $periodOptions['captureSections']
                ?? $config['capture_sections']
                ?? $config['captureSections']
                ?? $config['profile_sections']
                ?? $config['profileSections']
                ?? 'default',
        ];
        $sectionsList = $this->resolveCtripProfileCaptureSectionsForRun($sectionRequest, $fieldConfigPayload, false);
        if (empty($sectionsList)) {
            return ['success' => false, 'skipped' => true, 'message' => '获取字段配置中没有启用的可抓取字段，请先在“获取字段配置”启用字段或模块', 'saved_count' => 0];
        }
        $args = BrowserProfileCaptureRequestService::buildCtripAutoArgs(
            $nodeBinary,
            $scriptPath,
            $profileId,
            $hotelId,
            $dataDate,
            $outputPath,
            $sectionsList,
            $this->normalizeCtripSectionConcurrency($periodOptions['ctrip_section_concurrency'] ?? 3),
            $interactiveBrowser,
            (string)($periodOptions['capture_plan']
                ?? $periodOptions['capturePlan']
                ?? $config['capture_plan']
                ?? $config['capturePlan']
                ?? 'full')
        );
        $args = $this->appendCtripCaptureGateArgs($args, $config);
        $mappingArgs = $this->appendCtripApprovedMappingsArg($args, $config, $projectRoot);
        if ($mappingArgs['error'] !== '') {
            return [
                'success' => false,
                'message' => (string)$mappingArgs['error'],
                'saved_count' => 0,
                'modules' => [
                    ['module' => 'browser_profile', 'saved_count' => 0, 'success' => false, 'message' => (string)$mappingArgs['error']],
                ],
            ];
        }
        $args = $mappingArgs['args'];

        $ctripHotelId = trim((string)($config['ota_hotel_id'] ?? $config['ctrip_hotel_id'] ?? $config['ctripHotelId'] ?? $config['platform_hotel_id'] ?? $config['platformHotelId'] ?? ''));
        if ($ctripHotelId === '') {
            $legacyHotelId = trim((string)($config['hotelId'] ?? ''));
            if ($this->isMeaningfulCtripPlatformHotelId($legacyHotelId, $hotelId)) {
                $ctripHotelId = $legacyHotelId;
            }
        }
        if ($ctripHotelId !== '') {
            $args[] = '--hotel-id=' . $ctripHotelId;
        }
        $hotelName = trim((string)($config['hotel_name'] ?? $config['name'] ?? ''));
        if ($hotelName !== '') {
            $args[] = '--hotel-name=' . $hotelName;
        }
        $chromePath = BrowserProfileCaptureRequestService::resolveChromePath();
        if ($chromePath !== '') {
            $args[] = '--chrome-path=' . $chromePath;
        }

        $fieldConfigPath = $this->createCtripProfileFieldConfigFile($projectRoot, $fieldConfigPayload);
        if ($fieldConfigPath === '') {
            return ['success' => false, 'message' => '无法创建携程 Profile 字段配置快照', 'saved_count' => 0];
        }
        $args[] = '--field-config=' . $fieldConfigPath;

        try {
            $runResult = $this->runMeituanCaptureProcess($args, $projectRoot, $interactiveBrowser ? 600 : 120);
        } finally {
            $this->removeAutoFetchCookieFile($fieldConfigPath);
        }
        if (!$runResult['success']) {
            return [
                'success' => false,
                'message' => str_replace('美团', '携程', (string)$runResult['message']),
                'saved_count' => 0,
                'stdout' => $this->trimMeituanCaptureLog($runResult['stdout'] ?? ''),
                'stderr' => $this->trimMeituanCaptureLog($runResult['stderr'] ?? ''),
                'partial_capture' => $this->buildCtripPartialCaptureErrorPayload($outputPath),
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
        $payload = $this->applyAutoFetchPeriodOptionsToPayload($payload, $periodOptions);
        $captureGateDecision = $this->buildCtripCaptureGateDecision($payload);
        $captureGateWarning = null;
        if (!$captureGateDecision['accepted']) {
            if ($this->canContinueCtripCaptureWithSoftGateWarning($payload, $captureGateDecision)) {
                $captureGateWarning = $this->buildCtripCaptureGateWarning($captureGateDecision);
            } else {
                $capturedCounts = $this->buildCtripCaptureCounts($payload);
                $rowCount = (int)$capturedCounts['business'] + (int)$capturedCounts['traffic'] + (int)$capturedCounts['standard_rows'] + (int)$capturedCounts['catalog_facts'];
                return array_merge([
                    'success' => false,
                    'message' => 'Profile 真实采集门禁未通过，未入库且未更新最新采集状态',
                    'saved_count' => 0,
                    'row_count' => $rowCount,
                ], $this->buildCtripCaptureFactRowCountPayload($capturedCounts, 0, $rowCount), [
                    'captured_counts' => $capturedCounts,
                    'diagnosis_summary' => $this->buildCtripCaptureDiagnosisSummary($payload),
                    'auth_status' => $payload['auth_status'] ?? null,
                    'capture_gate' => $captureGateDecision['gate'],
                    'capture_gate_status' => $captureGateDecision['status'],
                    'capture_gate_failed_check_ids' => $captureGateDecision['failed_check_ids'],
                    'capture_gate_blocking_failed_check_ids' => $this->getCtripCaptureBlockingFailedCheckIds($captureGateDecision['failed_check_ids']),
                    'capture_audit' => $payload['capture_audit'] ?? null,
                    'output' => $outputPath,
                    'stdout' => $this->trimMeituanCaptureLog($runResult['stdout'] ?? ''),
                    'stderr' => $this->trimMeituanCaptureLog($runResult['stderr'] ?? ''),
                    'modules' => [
                        [
                            'module' => 'browser_profile_gate',
                            'saved_count' => 0,
                            'success' => false,
                            'message' => 'Profile capture gate failed: ' . implode(',', $captureGateDecision['failed_check_ids']),
                        ],
                    ],
                ]);
            }
        }
        $requestHotelId = $ctripHotelId !== '' ? $ctripHotelId : (string)($payload['hotel_id'] ?? '');
        $profileDataSourceId = (int)($profileSource['id'] ?? 0);
        $saveResult = $this->saveCtripBrowserProfilePayload(
            $payload,
            $hotelId,
            $dataDate,
            $requestHotelId,
            $profileDataSourceId > 0 ? $profileDataSourceId : null,
            $periodOptions,
            [
                'ingestion_method' => 'browser_profile',
                'data_source_id' => $profileDataSourceId,
            ]
        );
        $savedCount = (int)$saveResult['saved_count'];
        $capturedCounts = $this->buildCtripCaptureCounts($payload);
        if ($savedCount > 0) {
            $authStatus = is_array($payload['auth_status'] ?? null)
                ? $payload['auth_status']
                : ['ok' => true, 'status' => 'logged_in'];
            $this->cachePlatformProfileStatus('ctrip', $hotelId, $profileId, [
                'checked_at' => date('Y-m-d H:i:s'),
                'last_captured_at' => date('Y-m-d H:i:s'),
                'auth_status' => $authStatus,
                'capture_gate' => $payload['capture_gate'] ?? null,
                'capture_gate_warning' => $captureGateWarning,
                'status_code' => 'logged_in',
                'output' => $outputPath,
            ]);
        }
        $detailParts = [
            "概况 {$saveResult['business_saved']}",
            "流量 {$saveResult['traffic_saved']}",
        ];
        if ((int)($saveResult['review_saved'] ?? 0) > 0) {
            $detailParts[] = "点评 {$saveResult['review_saved']}";
        }
        if ((int)($saveResult['standard_saved'] ?? 0) > 0) {
            $detailParts[] = "标准字段 {$saveResult['standard_saved']}";
        }

        $rowCount = (int)$capturedCounts['business'] + (int)$capturedCounts['traffic'] + (int)$capturedCounts['standard_rows'] + (int)$capturedCounts['catalog_facts'];
        return array_merge([
            'success' => $savedCount > 0,
            'message' => $savedCount > 0
                ? "Profile 真实采集已确认 {$savedCount} 次数据库写入（" . implode('，', $detailParts) . "）" . ($captureGateWarning !== null ? '；字段覆盖率未达阈值，已保留诊断告警' : '')
                : ($rowCount > 0 ? 'Profile 已解析到业务行，但数据库回读未通过' : 'Profile 真实采集未解析到可入库数据'),
            'saved_count' => $savedCount,
            'row_count' => $rowCount,
            'persistence_status' => $savedCount > 0 ? 'readback_verified' : ($rowCount > 0 ? 'readback_not_verified' : 'no_parsed_rows'),
        ], $this->buildCtripCaptureFactRowCountPayload($capturedCounts, $savedCount, $rowCount), [
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
            'capture_gate' => $payload['capture_gate'] ?? null,
            'capture_gate_warning' => $captureGateWarning,
            'modules' => $saveResult['modules'],
            'output' => $outputPath,
        ]);
    }

    private function saveCtripBrowserProfilePayload(
        array $payload,
        int $hotelId,
        string $dataDate,
        string $requestHotelId,
        ?int $dataSourceId = null,
        array $periodOptions = [],
        array $competitionPersistenceContext = []
    ): array
    {
        $payload = $this->applyAutoFetchPeriodOptionsToPayload($payload, $periodOptions);
        if ($this->isMeaningfulCtripPlatformHotelId($requestHotelId, $hotelId)) {
            $selfHotelIds = is_array($competitionPersistenceContext['self_hotel_ids'] ?? null)
                ? $competitionPersistenceContext['self_hotel_ids']
                : [];
            $selfHotelIds[] = $requestHotelId;
            $competitionPersistenceContext['self_hotel_ids'] = array_values(array_unique(array_map('strval', $selfHotelIds)));
        }
        if ($dataSourceId !== null && $dataSourceId > 0 && empty($competitionPersistenceContext['data_source_id'])) {
            $competitionPersistenceContext['data_source_id'] = $dataSourceId;
        }
        $modules = [];

        $businessRows = $this->applyAutoFetchPeriodOptionsToRows($this->extractCtripCapturedSection($payload, 'business'), $periodOptions);
        $businessSaved = 0;
        if (!empty($businessRows)) {
            $businessSaved = $this->parseAndSaveData(
                ['data' => $businessRows],
                $dataDate,
                $dataDate,
                $hotelId,
                $competitionPersistenceContext
            );
        }
        if ($businessSaved === 0) {
            foreach ($this->extractCtripCapturedResponseData($payload, 'business') as $responseData) {
                $businessSaved += $this->parseAndSaveData(
                    $responseData,
                    $dataDate,
                    $dataDate,
                    $hotelId,
                    $competitionPersistenceContext
                );
            }
        }
        $modules[] = ['module' => 'browser_business', 'saved_count' => $businessSaved, 'success' => $businessSaved > 0];

        $trafficRows = $this->applyAutoFetchPeriodOptionsToRows($this->extractCtripCapturedSection($payload, 'traffic'), $periodOptions);
        $trafficSaved = 0;
        if (!empty($trafficRows)) {
            $trafficSaved = $this->parseAndSaveTrafficData(['data' => ['list' => $trafficRows]], $dataDate, $dataDate, 'ctrip', $hotelId, 'Ctrip', $requestHotelId);
        }
        if ($trafficSaved === 0) {
            foreach ($this->extractCtripCapturedResponseData($payload, 'traffic') as $responseData) {
                $trafficSaved += $this->parseAndSaveTrafficData($responseData, $dataDate, $dataDate, 'ctrip', $hotelId, 'Ctrip', $requestHotelId);
            }
        }
        $modules[] = ['module' => 'browser_traffic', 'saved_count' => $trafficSaved, 'success' => $trafficSaved > 0];

        $standardRows = $this->applyAutoFetchPeriodOptionsToRows($this->extractCtripStandardRows($payload, $hotelId, $dataDate, $requestHotelId, $dataSourceId), $periodOptions);
        $standardExpectedCount = count($standardRows);
        $reviewRows = array_values(array_filter($standardRows, static fn(array $row): bool => ($row['data_type'] ?? '') === 'review'));
        $reviewSaved = 0;
        $standardSaved = 0;
        if (!empty($standardRows)) {
            $standardSaved = $this->saveCtripStandardRows($standardRows);
        }
        $reviewSaved = count($reviewRows);
        $modules[] = ['module' => 'browser_reviews', 'saved_count' => $reviewSaved, 'success' => $reviewSaved > 0, 'aggregate_only' => true];
        $modules[] = ['module' => 'browser_catalog_standard', 'saved_count' => $standardSaved, 'success' => $standardSaved > 0];

        return [
            'saved_count' => $businessSaved + $trafficSaved + $standardSaved,
            'business_saved' => $businessSaved,
            'traffic_saved' => $trafficSaved,
            'review_saved' => $reviewSaved,
            'standard_saved' => $standardSaved,
            'standard_expected_count' => $standardExpectedCount,
            'modules' => $modules,
        ];
    }

    private function validateCtripPayloadHotelIdentity(array $payload, int $systemHotelId, array $config = []): array
    {
        $capturedIds = array_values(array_map('strval', $this->extractCtripPayloadSelfHotelIds($payload)));
        $nodeIds = array_fill_keys($this->extractCtripNodeResourceIds($config), true);
        $capturedIds = array_values(array_filter($capturedIds, fn(string $id): bool => $this->isMeaningfulCtripPlatformHotelId($id, $systemHotelId) && !isset($nodeIds[$id])));
        $expectedIds = array_values(array_map('strval', $this->extractExpectedCtripPlatformHotelIds($config, $systemHotelId)));
        $conflicts = $this->findCtripPlatformHotelIdConflicts($capturedIds, $systemHotelId);
        $blockingConflicts = array_values(array_filter($conflicts, function (array $conflict) use ($expectedIds): bool {
            return $this->shouldBlockCtripCurrentHotelIdConflict((string)($conflict['hotel_id'] ?? ''), $expectedIds);
        }));
        $targetHotelName = $this->getSystemHotelName($systemHotelId);

        if ($blockingConflicts !== []) {
            $conflictNames = [];
            foreach ($blockingConflicts as $conflict) {
                $name = trim((string)($conflict['system_hotel_name'] ?? ''));
                $conflictNames[] = $name !== '' ? $name : ('门店ID ' . (string)($conflict['system_hotel_id'] ?? ''));
            }
            $conflictNames = array_values(array_unique(array_filter($conflictNames)));
            return [
                'ok' => false,
                'status' => 'platform_hotel_conflict',
                'message' => '携程返回的酒店标识已绑定到其他门店，已取消入库，避免错店数据覆盖。当前选择：' . ($targetHotelName !== '' ? $targetHotelName : ('门店ID ' . $systemHotelId)) . '；已存在门店：' . implode('、', $conflictNames),
                'target_system_hotel_id' => $systemHotelId,
                'target_hotel_name' => $targetHotelName,
                'captured_hotel_ids' => $capturedIds,
                'expected_hotel_ids' => $expectedIds,
                'conflicts' => $blockingConflicts,
            ];
        }

        $unexpectedCapturedIds = $expectedIds !== [] ? array_values(array_diff($capturedIds, $expectedIds)) : [];
        if ($expectedIds !== [] && $capturedIds !== []
            && (array_intersect($expectedIds, $capturedIds) === [] || $unexpectedCapturedIds !== [])
        ) {
            return [
                'ok' => false,
                'status' => 'configured_platform_hotel_id_mismatch',
                'warning' => true,
                'message' => '携程数据已获取，但返回酒店ID与当前门店配置不一致或同时包含其他门店，本次未入库。请核对配置ID：' . implode('、', $expectedIds) . '；返回ID：' . implode('、', $capturedIds),
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
            'status' => $capturedIds === [] ? 'no_platform_hotel_id' : 'matched',
            'target_system_hotel_id' => $systemHotelId,
            'target_hotel_name' => $targetHotelName,
            'captured_hotel_ids' => $capturedIds,
            'expected_hotel_ids' => $expectedIds,
            'conflicts' => [],
        ];
    }

    private function extractExpectedCtripPlatformHotelIds(array $config, int $systemHotelId): array
    {
        $ids = [];
        foreach (['masterHotelId', 'master_hotel_id', 'ota_hotel_id', 'ctrip_hotel_id', 'ctripHotelId', 'platform_hotel_id', 'platformHotelId'] as $key) {
            $value = trim((string)($config[$key] ?? ''));
            if ($this->isMeaningfulCtripPlatformHotelId($value, $systemHotelId)) {
                $ids[$value] = true;
            }
        }
        return array_keys($ids);
    }

    private function extractCtripNodeResourceIds(array $config): array
    {
        $ids = [];
        foreach (['node_id', 'nodeId'] as $key) {
            $value = trim((string)($config[$key] ?? ''));
            if ($value !== '' && $value !== '-1') {
                $ids[$value] = true;
            }
        }
        return array_keys($ids);
    }

    private function getCtripNodeResourceIdsForSystemHotel(int $systemHotelId): array
    {
        if ($systemHotelId <= 0) {
            return [];
        }

        $ids = [];
        foreach ($this->getStoredCtripConfigList() as $config) {
            if (!is_array($config)) {
                continue;
            }
            $configHotelId = trim((string)($config['hotel_id'] ?? $config['system_hotel_id'] ?? ''));
            if ($configHotelId === '' || $configHotelId !== (string)$systemHotelId) {
                continue;
            }
            foreach ($this->extractCtripNodeResourceIds($config) as $id) {
                $ids[$id] = true;
            }
        }
        if ($ids === []) {
            $ids['24588'] = true;
        }
        return array_keys($ids);
    }

    private function getCtripExpectedPlatformHotelIdsForSystemHotel(int $systemHotelId): array
    {
        if ($systemHotelId <= 0) {
            return [];
        }

        $ids = [];
        foreach ($this->getStoredCtripConfigList() as $config) {
            if (!is_array($config)) {
                continue;
            }
            $configHotelId = trim((string)($config['hotel_id'] ?? $config['system_hotel_id'] ?? ''));
            if ($configHotelId === '' || $configHotelId !== (string)$systemHotelId) {
                continue;
            }
            foreach ($this->extractExpectedCtripPlatformHotelIds($config, $systemHotelId) as $id) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    private function extractCtripPayloadSelfHotelIds(array $payload): array
    {
        $ids = [];
        $trustedSourceKeys = ['masterhotelid', 'master_hotel_id', 'hotelid', 'hotel_id'];
        $hasTrustedStandardHotelId = false;
        foreach (is_array($payload['standard_rows'] ?? null) ? $payload['standard_rows'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rawData = is_array($row['raw_data'] ?? null) ? $row['raw_data'] : [];
            $sourceKey = strtolower(trim((string)($rawData['hotel_id_source_key'] ?? '')));
            if (!in_array($sourceKey, $trustedSourceKeys, true)) {
                continue;
            }
            $hasTrustedStandardHotelId = true;
            if (!$this->isCtripCompetitorLikeValue($row)) {
                $this->addCtripPayloadHotelId($ids, $row['hotel_id'] ?? $row['hotelId'] ?? null);
            }
        }

        // Standard rows carry the parser's explicit self/competitor decision.
        // Once they expose trusted hotel IDs, never re-add competitor IDs from
        // the lower-level fact list.
        if ($hasTrustedStandardHotelId) {
            return array_keys($ids);
        }

        foreach (is_array($payload['catalog_facts'] ?? null) ? $payload['catalog_facts'] : [] as $fact) {
            if (!is_array($fact) || strtolower(trim((string)($fact['metric_key'] ?? ''))) !== 'hotel_id') {
                continue;
            }
            $sourceKey = strtolower(trim((string)($fact['source_key'] ?? '')));
            if (!in_array($sourceKey, $trustedSourceKeys, true)) {
                continue;
            }
            $this->addCtripPayloadHotelId($ids, $fact['value'] ?? null);
        }

        return array_keys($ids);
    }

    private function addCtripPayloadHotelId(array &$ids, mixed $value): void
    {
        if (is_array($value) || is_object($value)) {
            return;
        }
        $id = trim((string)$value);
        if ($id === '' || $id === '-1') {
            return;
        }
        $ids[$id] = true;
    }

    private function resolveCtripPlatformHotelId(array $row, mixed $fallback = ''): string
    {
        foreach (['masterHotelId', 'masterhotelid', 'master_hotel_id', 'hotelId', 'hotel_id', 'HotelId', 'hotelID', 'ota_hotel_id', 'ctrip_hotel_id'] as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            $value = $row[$key];
            if (is_array($value) || is_object($value)) {
                continue;
            }
            $id = trim((string)$value);
            if ($id !== '') {
                return $id;
            }
        }

        if (is_array($fallback) || is_object($fallback)) {
            return '';
        }
        return trim((string)$fallback);
    }

    private function isCtripCompetitorLikeValue(array $value): bool
    {
        $hotelId = trim((string)($value['hotel_id'] ?? $value['hotelId'] ?? $value['HotelId'] ?? $value['_overview_source_hotel_id'] ?? ''));
        if ($hotelId === '-1') {
            return true;
        }

        $parts = [
            $value['compare_type'] ?? '',
            $value['compareType'] ?? '',
            $value['_overview_compare_type'] ?? '',
            $value['rankType'] ?? '',
            $value['type'] ?? '',
            $value['name'] ?? '',
            $value['hotelName'] ?? '',
            $value['hotel_name'] ?? '',
            $value['dimension'] ?? '',
        ];
        $text = mb_strtolower(implode(' ', array_map(static fn($part): string => (string)$part, $parts)), 'UTF-8');
        return str_contains($text, 'competitor')
            || str_contains($text, 'compete')
            || str_contains($text, 'peer')
            || str_contains($text, 'avg')
            || str_contains($text, 'average')
            || str_contains($text, '竞争圈')
            || str_contains($text, '竞品')
            || str_contains($text, '平均');
    }

    private function isMeaningfulCtripPlatformHotelId(string $value, int $systemHotelId = 0): bool
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return false;
        }
        if ($systemHotelId > 0 && $value === (string)$systemHotelId) {
            return false;
        }
        return true;
    }

    /**
     * @param array<int, string> $platformHotelIds
     * @return array<int, array<string, mixed>>
     */
    private function findCtripPlatformHotelIdConflicts(array $platformHotelIds, int $systemHotelId): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn($value): string => trim((string)$value),
            $platformHotelIds
        ), static fn(string $value): bool => $value !== '' && $value !== '-1')));
        if ($ids === [] || $systemHotelId <= 0) {
            return [];
        }

        return Db::name('online_daily_data')
            ->alias('d')
            ->join('hotels h', 'h.id = d.system_hotel_id')
            ->field('d.hotel_id,d.system_hotel_id,MAX(h.name) AS system_hotel_name,MAX(d.hotel_name) AS captured_hotel_name,COUNT(*) AS record_count')
            ->where('d.source', 'ctrip')
            ->where('d.compare_type', 'self')
            ->where('h.status', 1)
            ->whereIn('d.hotel_id', $ids)
            ->whereNotNull('d.system_hotel_id')
            ->where('d.system_hotel_id', '<>', $systemHotelId)
            ->group('d.hotel_id,d.system_hotel_id')
            ->select()
            ->toArray();
    }

    private function getSystemHotelName(int $systemHotelId): string
    {
        if ($systemHotelId <= 0) {
            return '';
        }
        return trim((string)Db::name('hotels')->where('id', $systemHotelId)->value('name'));
    }

    private function extractCtripStandardRows(array $payload, int $systemHotelId, string $dataDate, string $requestHotelId, ?int $dataSourceId = null, ?array $enabledFieldKeys = null): array
    {
        $rows = [];
        $enabledFieldKeys = $enabledFieldKeys === null
            ? $this->ctripProfileEnabledFieldKeyMap()
            : $this->normalizeCtripProfileEnabledFieldKeyMap($enabledFieldKeys);
        foreach (($payload['standard_rows'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $captureSection = strtolower(trim((string)($row['capture_section'] ?? '')));
            $dataType = $this->normalizeCtripStandardDataType((string)($row['data_type'] ?? 'business'));
            $metricKey = $this->ctripStandardRowMetricKey($row);
            $metricKeys = $this->ctripStandardRowMetricKeys($row);
            $matchedMetricKeys = array_intersect_key(array_fill_keys($metricKeys, true), $enabledFieldKeys);
            if (empty($matchedMetricKeys)) {
                continue;
            }
            if ($this->shouldSkipCtripLegacyStandardRow($captureSection, $dataType, $row)) {
                continue;
            }
            $row = $this->reconcileCtripCatalogStandardRowMetricFacts($row);

            $rowDataDate = $this->normalizeOnlineDataDate($row['data_date'] ?? '') ?: $dataDate;
            $dimension = trim((string)($row['dimension'] ?? '')) ?: 'catalog:' . ($captureSection ?: 'unknown');
            $platform = $this->normalizeCtripProfileTrafficPlatform((string)($row['platform'] ?? ''));
            $source = $this->sourceForCtripProfileTrafficPlatform((string)($row['source'] ?? ''), $platform);
            $rawData = $row['raw_data'] ?? $row;
            $observedTrafficMetricKeys = $this->ctripCatalogObservedTrafficMetricKeys(
                $row,
                is_array($rawData) ? $rawData : []
            );
            $rawDataForTrace = is_array($rawData) ? $rawData : [];
            if (is_array($rawData)) {
                $rawData['capture_section'] = $captureSection;
                $rawData['endpoint_id'] = (string)($row['endpoint_id'] ?? ($rawData['endpoint_id'] ?? ''));
                if ($observedTrafficMetricKeys !== []) {
                    $rawData['_observed_traffic_metric_keys'] = $observedTrafficMetricKeys;
                }
                $sourceUrl = trim((string)($row['source_url'] ?? ($rawData['source_url'] ?? '')));
                if ($sourceUrl !== '') {
                    $rawData['source_url'] = $this->sanitizeCtripStandardRowSourceUrl($sourceUrl);
                }
                $rawData = $dataType === 'review'
                    ? $this->sanitizeOnlineReviewRawData($rawData)
                    : $this->sanitizeOnlineOrderRawData($rawData, $dataType === 'order');
                $rawDataForTrace = $rawData;
            } else {
                $sourceUrl = trim((string)($row['source_url'] ?? ''));
            }
            $sourceTraceId = $this->buildCtripStandardRowSourceTraceId(
                array_merge($row, ['source' => $source, 'platform' => $platform]),
                $captureSection,
                $dataType,
                $dimension,
                $rowDataDate,
                $rawDataForTrace
            );
            $sourceUrlHash = $this->ctripStandardRowSourceUrlHash($row, $rawDataForTrace, $sourceUrl);
            if (is_array($rawData)) {
                $rawData = $this->attachCtripStandardRowDesensitizedEvidence(
                    $rawData,
                    $row,
                    $sourceTraceId,
                    $sourceUrlHash
                );
                $rawData = json_encode($rawData, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            } else {
                $rawData = (string)$rawData;
            }

            $standardRow = [
                'hotel_id' => $this->resolveCtripPlatformHotelId($row, $requestHotelId),
                'hotel_name' => trim((string)($row['hotel_name'] ?? '')),
                'system_hotel_id' => $systemHotelId,
                'source' => $source,
                'platform' => $platform,
                'data_date' => $rowDataDate,
                'data_type' => $dataType,
                'dimension' => $dimension,
                'amount' => $this->ctripStandardRowFloatMetric($row, 'amount'),
                'quantity' => $this->ctripStandardRowIntegerMetric($row, 'quantity'),
                'book_order_num' => $this->ctripStandardRowIntegerMetric($row, 'book_order_num'),
                'comment_score' => $this->ctripStandardRowFloatMetric($row, 'comment_score'),
                'qunar_comment_score' => $this->ctripStandardRowFloatMetric($row, 'qunar_comment_score'),
                'data_value' => $this->ctripStandardRowFloatMetric($row, 'data_value'),
                'compare_type' => trim((string)($row['compare_type'] ?? '')),
                'list_exposure' => $this->ctripStandardRowIntegerMetric($row, 'list_exposure'),
                'detail_exposure' => $this->ctripStandardRowIntegerMetric($row, 'detail_exposure'),
                'flow_rate' => $this->ctripStandardRowFloatMetric($row, 'flow_rate'),
                'order_filling_num' => $this->ctripStandardRowIntegerMetric($row, 'order_filling_num'),
                'order_submit_num' => $this->ctripStandardRowIntegerMetric($row, 'order_submit_num'),
                'ingestion_method' => in_array((string)($row['ingestion_method'] ?? ''), ['browser_profile', 'ctrip_cookie_api'], true)
                    ? (string)$row['ingestion_method']
                    : 'browser_profile',
                'source_trace_id' => $sourceTraceId,
                'source_url_hash' => $sourceUrlHash,
                'raw_data' => $rawData,
            ];
            if ($dataSourceId !== null && $dataSourceId > 0) {
                $standardRow['data_source_id'] = $dataSourceId;
            }
            if ($observedTrafficMetricKeys !== []) {
                $standardRow['_observed_traffic_metric_keys'] = $observedTrafficMetricKeys;
            }
            $rows[] = $standardRow;
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function reconcileCtripCatalogStandardRowMetricFacts(array $row): array
    {
        $rawData = $row['raw_data'] ?? null;
        if (is_string($rawData)) {
            $decoded = json_decode($rawData, true);
            $rawData = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($rawData)
            || strtolower(trim((string)($rawData['source'] ?? ''))) !== 'ctrip_catalog_facts'
            || !is_array($rawData['field_facts'] ?? null)
        ) {
            return $row;
        }

        $structuredFields = [
            'amount', 'quantity', 'book_order_num', 'comment_score', 'qunar_comment_score', 'data_value',
            'list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num',
        ];
        $structuredFieldMap = array_fill_keys($structuredFields, true);
        $capturedFields = [];
        foreach ($rawData['field_facts'] as $fact) {
            if (!is_array($fact)
                || strtolower(trim((string)($fact['status'] ?? ''))) !== 'captured'
                || ($fact['stored_value_present'] ?? false) !== true
            ) {
                continue;
            }
            $storageField = trim((string)($fact['storage_field'] ?? ''));
            if (str_starts_with($storageField, 'online_daily_data.')) {
                $storageField = substr($storageField, strlen('online_daily_data.'));
            }
            if (isset($structuredFieldMap[$storageField])) {
                $capturedFields[$storageField] = true;
            }
        }
        foreach ($structuredFields as $field) {
            $value = $row[$field] ?? null;
            $placeholder = $value === null
                || (is_string($value) && trim($value) === '')
                || (is_numeric($value) && (float)$value === 0.0);
            if (!isset($capturedFields[$field]) && $placeholder) {
                $row[$field] = null;
            }
        }
        return $row;
    }

    /** @return array<int, string> */
    private function ctripCatalogObservedTrafficMetricKeys(array $row, array $rawData): array
    {
        $endpointId = strtolower(trim((string)($row['endpoint_id'] ?? ($rawData['endpoint_id'] ?? ''))));
        if (!in_array($endpointId, ['business_flow_transform', 'traffic_flow_transform'], true)
            || strtolower(trim((string)($rawData['source'] ?? ''))) !== 'ctrip_catalog_facts'
            || !is_array($rawData['field_facts'] ?? null)
        ) {
            return [];
        }

        $required = [
            'list_exposure',
            'detail_exposure',
            'flow_rate',
            'order_filling_num',
            'order_submit_num',
        ];
        $captured = [];
        foreach ($rawData['field_facts'] as $fact) {
            if (!is_array($fact)
                || strtolower(trim((string)($fact['status'] ?? ''))) !== 'captured'
                || ($fact['stored_value_present'] ?? false) !== true
            ) {
                continue;
            }
            $storageField = trim((string)($fact['storage_field'] ?? ''));
            if (str_starts_with($storageField, 'online_daily_data.')) {
                $storageField = substr($storageField, strlen('online_daily_data.'));
            }
            if (in_array($storageField, $required, true)) {
                $captured[$storageField] = true;
            }
        }

        return array_diff($required, array_keys($captured)) === [] ? $required : [];
    }

    private function ctripStandardRowFloatMetric(array $row, string $field): ?float
    {
        if (!array_key_exists($field, $row)
            || $row[$field] === null
            || (is_string($row[$field]) && trim($row[$field]) === '')
            || !is_numeric($row[$field])) {
            return null;
        }
        return (float)$row[$field];
    }

    private function ctripStandardRowIntegerMetric(array $row, string $field): ?int
    {
        if (!array_key_exists($field, $row)
            || $row[$field] === null
            || (is_string($row[$field]) && trim($row[$field]) === '')
            || !is_numeric($row[$field])) {
            return null;
        }
        return (int)round((float)$row[$field]);
    }

    private function normalizeCtripProfileEnabledFieldKeyMap(array $keys): array
    {
        $map = [];
        foreach ($keys as $key => $value) {
            $fieldKey = is_int($key) ? (string)$value : (string)$key;
            $fieldKey = strtolower(trim($fieldKey));
            if ($fieldKey !== '') {
                $map[$fieldKey] = true;
            }
        }
        return $map;
    }

    private function normalizeCtripProfileTrafficPlatform(string $platform): string
    {
        $value = strtolower(trim($platform));
        if ($value === 'qunar' || $value === '去哪儿' || $value === 'qunaer') {
            return 'Qunar';
        }
        return 'Ctrip';
    }

    private function sourceForCtripProfileTrafficPlatform(string $source, string $platform): string
    {
        $value = strtolower(trim($source));
        if ($value === 'qunar' || $platform === 'Qunar') {
            return 'qunar';
        }
        return 'ctrip';
    }

    private function buildCtripStandardRowSourceTraceId(array $row, string $captureSection, string $dataType, string $dimension, string $dataDate, array $rawData): string
    {
        $endpointId = trim((string)($row['endpoint_id'] ?? ($rawData['endpoint_id'] ?? '')));
        $sourceUrl = trim((string)($row['source_url'] ?? ($rawData['source_url'] ?? '')));
        $metricKey = $this->ctripStandardRowMetricKey($row);
        if ($metricKey === '' && is_array($rawData['metrics'] ?? null)) {
            $metricKeys = array_keys($rawData['metrics']);
            $metricKey = strtolower(trim((string)($metricKeys[0] ?? '')));
        }

        $basis = [
            'platform' => $this->normalizeCtripProfileTrafficPlatform((string)($row['platform'] ?? '')),
            'hotel_id' => trim((string)($row['hotel_id'] ?? '')),
            'data_date' => $dataDate,
            'data_type' => $dataType,
            'capture_section' => $captureSection,
            'endpoint_id' => $endpointId,
            'dimension' => $dimension,
            'metric_key' => $metricKey,
            'source_url' => $this->canonicalizeCtripStandardRowSourceUrl($sourceUrl),
        ];

        return 'ctrip:' . hash('sha256', (string)json_encode($basis, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function canonicalizeCtripStandardRowSourceUrl(string $sourceUrl): string
    {
        if ($sourceUrl === '') {
            return '';
        }

        $parts = parse_url($sourceUrl);
        if (!is_array($parts)) {
            return preg_replace('/[?#].*$/', '', $sourceUrl) ?? $sourceUrl;
        }

        return strtolower((string)($parts['host'] ?? '')) . (string)($parts['path'] ?? '');
    }

    private function sanitizeCtripStandardRowSourceUrl(string $sourceUrl): string
    {
        $sourceUrl = trim($sourceUrl);
        if ($sourceUrl === '') {
            return '';
        }

        $parts = parse_url($sourceUrl);
        if (!is_array($parts)) {
            return preg_replace('/[?#].*$/', '', $sourceUrl) ?? '';
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        $path = (string)($parts['path'] ?? '');
        if ($host === '') {
            return preg_replace('/[?#].*$/', '', $path !== '' ? $path : $sourceUrl) ?? '';
        }
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        $prefix = in_array($scheme, ['http', 'https'], true) ? $scheme . '://' : '';
        return $prefix . $host . $port . $path;
    }

    private function ctripStandardRowSourceUrlHash(array $row, array $rawData, string $sourceUrl): string
    {
        if ($sourceUrl !== '') {
            return hash('sha256', $sourceUrl);
        }

        $captureEvidence = is_array($row['capture_evidence'] ?? null) ? (array)$row['capture_evidence'] : [];
        $rawCaptureEvidence = is_array($rawData['capture_evidence'] ?? null) ? (array)$rawData['capture_evidence'] : [];
        foreach ([
            $row['source_url_hash'] ?? null,
            $captureEvidence['source_url_hash'] ?? null,
            $rawData['source_url_hash'] ?? null,
            $rawCaptureEvidence['source_url_hash'] ?? null,
        ] as $candidate) {
            $hash = strtolower(trim((string)$candidate));
            if (preg_match('/^[a-f0-9]{64}$/', $hash) === 1) {
                return $hash;
            }
        }

        return '';
    }

    private function attachCtripStandardRowDesensitizedEvidence(
        array $rawData,
        array $sourceRow,
        string $sourceTraceId,
        string $sourceUrlHash
    ): array {
        $baseEvidence = $this->sanitizeCtripStandardRowCaptureEvidence(
            is_array($sourceRow['capture_evidence'] ?? null) ? (array)$sourceRow['capture_evidence'] : []
        );
        $baseEvidence['source_trace_id'] = $sourceTraceId;
        if ($sourceUrlHash !== '') {
            $baseEvidence['source_url_hash'] = $sourceUrlHash;
        }
        $rawData['source_trace_id'] = $sourceTraceId;
        if ($sourceUrlHash !== '') {
            $rawData['source_url_hash'] = $sourceUrlHash;
        }
        $rawData['capture_evidence'] = $baseEvidence;

        if (is_array($rawData['field_facts'] ?? null)) {
            foreach ($rawData['field_facts'] as $index => $fact) {
                if (!is_array($fact)) {
                    continue;
                }
                $factEvidence = $this->sanitizeCtripStandardRowCaptureEvidence(
                    is_array($fact['capture_evidence'] ?? null) ? (array)$fact['capture_evidence'] : []
                );
                $fact['capture_evidence'] = array_merge($factEvidence, $baseEvidence);
                $rawData['field_facts'][$index] = $fact;
            }
        }

        return $rawData;
    }

    private function sanitizeCtripStandardRowCaptureEvidence(array $evidence): array
    {
        $safe = [];
        foreach ([
            'source_path',
            'capture_source',
            'section',
            'method',
            'request_hash',
            'payload_hash',
        ] as $key) {
            $value = $evidence[$key] ?? null;
            if (is_scalar($value) && trim((string)$value) !== '') {
                $safe[$key] = mb_substr(trim((string)$value), 0, 300);
            }
        }
        return $safe;
    }

    private function shouldSkipCtripLegacyStandardRow(string $captureSection, string $dataType, array $row): bool
    {
        $metricKey = $this->ctripStandardRowMetricKey($row);
        if ($metricKey === '') {
            return false;
        }
        if (in_array($captureSection, ['business_overview', 'sales_report', 'room_type'], true) && $dataType === 'business') {
            return in_array($metricKey, ['order_amount', 'room_nights', 'order_count'], true);
        }
        if ($captureSection === 'traffic_report' && $dataType === 'traffic') {
            return in_array($metricKey, [
                'visitor_count',
                'list_exposure',
                'detail_visitor',
                'order_page_visitor',
                'order_submit_user',
                'flow_rate',
            ], true);
        }
        return false;
    }

    private function ctripStandardRowMetricKey(array $row): string
    {
        $dimension = trim((string)($row['dimension'] ?? ''));
        if ($dimension !== '' && preg_match('/^catalog:[^:]+:[^:]+:([^:]+)/', $dimension, $matches)) {
            return strtolower(trim((string)$matches[1]));
        }
        $rawData = $row['raw_data'] ?? [];
        if (is_array($rawData) && is_array($rawData['metrics'] ?? null)) {
            $keys = array_keys($rawData['metrics']);
            return strtolower(trim((string)($keys[0] ?? '')));
        }
        return '';
    }

    private function ctripStandardRowMetricKeys(array $row): array
    {
        $keys = [];
        $dimensionKey = $this->ctripStandardRowMetricKey($row);
        foreach (preg_split('/[|+]/', $dimensionKey) ?: [] as $key) {
            $key = strtolower(trim((string)$key));
            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        $rawData = $row['raw_data'] ?? [];
        if (is_array($rawData)) {
            if (is_array($rawData['metrics'] ?? null)) {
                foreach (array_keys($rawData['metrics']) as $key) {
                    $key = strtolower(trim((string)$key));
                    if ($key !== '') {
                        $keys[$key] = true;
                    }
                }
            }
            if (is_array($rawData['facts'] ?? null)) {
                foreach ($rawData['facts'] as $fact) {
                    if (!is_array($fact)) {
                        continue;
                    }
                    $key = strtolower(trim((string)($fact['metric_key'] ?? '')));
                    if ($key !== '') {
                        $keys[$key] = true;
                    }
                }
            }
        }

        return array_keys($keys);
    }

    private function ctripProfileEnabledFieldKeyMap(?array $fields = null): array
    {
        $fields = $fields ?? $this->readCtripProfileCaptureFields(true);
        $enabled = [];
        foreach ($fields as $field) {
            if (!is_array($field) || $this->isCtripProfileCaptureFieldDeleted($field) || empty($field['enabled'])) {
                continue;
            }
            $fieldKey = strtolower(trim((string)($field['field_key'] ?? '')));
            if ($fieldKey !== '') {
                $enabled[$fieldKey] = true;
            }
        }
        return $enabled;
    }

    private function normalizeCtripStandardDataType(string $value): string
    {
        $value = strtolower(trim($value));
        return match ($value) {
            'ad', 'ads', 'advertising', 'campaign' => 'advertising',
            'flow' => 'traffic',
            'review', 'reviews', 'comment', 'comments' => 'review',
            'order', 'orders' => 'order',
            'service', 'service_quality', 'psi' => 'quality',
            default => $value !== '' ? $value : 'business',
        };
    }

    private function saveCtripStandardRows(array $rows): int
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
            $row = $this->applyOnlineDailyDataPeriodFields($row, $columns, $row);

            $query = Db::name('online_daily_data')
                ->where('source', (string)($row['source'] ?? 'ctrip'))
                ->where('data_type', (string)$row['data_type'])
                ->where('data_date', (string)$row['data_date'])
                ->where('dimension', (string)($row['dimension'] ?? ''));
            $this->applyOnlineDailyDataPeriodQuery($query, $row, $columns);

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
            $data = OnlineDailyDataPersistenceService::resetReadbackVerification($data, $columns);
            if ($exists) {
                $rowId = (int)$exists['id'];
                Db::name('online_daily_data')->where('id', $rowId)->update($data);
            } else {
                $rowId = (int)Db::name('online_daily_data')->insertGetId($data);
            }
            $persisted = $rowId > 0
                ? Db::name('online_daily_data')->where('id', $rowId)->find()
                : null;
            if (is_array($persisted)
                && OnlineDailyDataPersistenceService::matchesBusinessReadback($persisted, $data)
                && OnlineDailyDataPersistenceService::markRowsReadbackVerified([$persisted], $columns)) {
                $savedCount++;
            }
        }

        return $savedCount;
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

}
