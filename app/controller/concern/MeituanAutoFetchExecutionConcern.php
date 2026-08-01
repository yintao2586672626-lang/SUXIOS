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

trait MeituanAutoFetchExecutionConcern
{
    private function executeMeituanAutoFetch(int $hotelId, string $dataDate, array $options = []): array
    {
        $config = $this->resolveMeituanFetchConfigForHotel($hotelId);
        $apiStatus = $this->meituanAutoFetchConfigStatus($config, $hotelId);
        $missingText = (string)$apiStatus['missing_text'];
        $mode = $this->resolvePlatformAutoFetchMode($config, $options, 'meituan');
        $runCookieConfig = $this->shouldRunCookieConfigTasks($mode);
        $runProfileBrowser = $this->shouldRunProfileBrowser($mode);
        $browserProfileSources = $this->listCollectableBrowserProfileDataSources($hotelId, 'meituan');
        $taskPlanForConfig = $this->buildAutoFetchConfigTaskPlan($hotelId, $dataDate, [], $config);
        $hasConfiguredTask = (bool)array_filter($taskPlanForConfig, static fn(array $task): bool => ($task['platform'] ?? '') === 'meituan');
        $hasProfile = $this->meituanProfileExistsForConfig($config);
        $hasProfileSeed = $this->meituanProfileStoreIdFromConfig($config) !== '';

        $hasDirectConfig = $hasConfiguredTask;
        $hasProfileConfig = $runProfileBrowser && ($hasProfile || $hasProfileSeed || $browserProfileSources !== []);
        if (!$hasDirectConfig && !$hasProfileConfig) {
            $message = $runProfileBrowser
                ? '未配置美团浏览器 Profile'
                : ($missingText !== '' ? '未配置美团 ' . $missingText : '未配置美团 Partner ID / POI ID / Cookies');
            return [
                'platform' => 'meituan',
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

        if ($runCookieConfig && empty($apiStatus['api_configured'])) {
            $message = $missingText !== '' ? '缺少美团 ' . $missingText : '缺少美团 Partner ID / POI ID / Cookies';
            if ($mode === 'cookie_config') {
                $errors[] = $message;
            }
            $modules[] = $this->withAutoFetchResultMeta(['module' => 'ranking_api', 'saved_count' => 0, 'success' => false, 'skipped' => true, 'message' => $message], 'cookie_config');
        } elseif (!$runCookieConfig) {
            $modules[] = $this->withAutoFetchResultMeta(['module' => 'cookie_config_tasks', 'saved_count' => 0, 'success' => false, 'skipped' => true, 'message' => '当前策略仅使用浏览器 Profile'], 'cookie_config');
        }

        if ($runCookieConfig) {
            foreach ($taskPlanForConfig as $task) {
                if (($task['platform'] ?? '') !== 'meituan') {
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
            $runProfileByCost = $this->shouldRunProfileBrowserForCost($mode, $savedCount);
            if ($runProfileByCost) {
                $browserResult = $this->syncMeituanBrowserProfileDataSourcesForAutoFetch(
                    $hotelId,
                    $dataDate,
                    !empty($options['interactive_browser']),
                    $browserProfileSources,
                    $options
                );
                if (empty($browserResult['attempted'])) {
                    $browserResult = $this->executeMeituanBrowserProfileAutoFetch($config, $hotelId, $dataDate, !empty($options['interactive_browser']), $options);
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
            \think\facade\Log::info("美团自动获取已写入", [
                'hotel_id' => $hotelId,
                'count' => $savedCount,
                'core_readback_verified' => $coreReadbackVerified,
            ]);
            return [
                'platform' => 'meituan',
                'success' => $this->autoFetchPlatformRunSucceeded($savedCount, $runReadback),
                'message' => $coreReadbackVerified
                    ? "完成 {$savedCount} 次写入并验证本次任务核心指标回执"
                    : "已发生 {$savedCount} 次写入，但本次任务、入库行、来源追踪与收入/间夜/ADR 回执未完整绑定",
                'saved_count' => $savedCount,
                'data_period' => $options['data_period'] ?? 'historical_daily',
                'auto_fetch_mode' => $mode,
                'mode_label' => $this->autoFetchModeLabel($mode),
                'modules' => $modules,
                'run_readback' => $runReadback,
                'timing' => is_array($browserResult['timing'] ?? null) ? $browserResult['timing'] : [],
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
            'data_period' => $options['data_period'] ?? 'historical_daily',
            'auto_fetch_mode' => $mode,
            'mode_label' => $this->autoFetchModeLabel($mode),
            'modules' => $modules,
            'run_readback' => $runReadback,
            'timing' => is_array($browserResult['timing'] ?? null) ? $browserResult['timing'] : [],
        ];
    }

    private function executeMeituanRankingAutoFetchTask(string $label, array $body, int $hotelId): array
    {
        return $this->withAutoFetchCredential('meituan', $body, $hotelId, function (array $credentialPayload) use ($label, $body, $hotelId): array {
            return $this->executeMeituanRankingAutoFetchWithCredential($label, $body, $hotelId, $credentialPayload);
        });
    }

    private function executeMeituanRankingAutoFetchWithCredential(string $label, array $body, int $hotelId, array $credentialPayload): array
    {
        $partnerId = trim((string)($body['partner_id'] ?? ''));
        $poiId = trim((string)($body['poi_id'] ?? ''));
        $rankType = trim((string)($body['rank_type'] ?? 'P_RZ')) ?: 'P_RZ';
        if ($partnerId === '' || $poiId === '') {
            return ['module' => $label, 'saved_count' => 0, 'success' => false, 'skipped' => true, 'message' => 'meituan_resource_id_missing'];
        }

        $cookieHeader = $this->autoFetchCredentialCookieHeader($credentialPayload);
        if ($cookieHeader === '') {
            return ['module' => $label, 'saved_count' => 0, 'success' => false, 'message' => 'credential_payload_missing_cookie'];
        }

        $params = [
            'dataScope' => $body['data_scope'] ?? 'vpoi',
            'deviceType' => 1,
            'yodaReady' => 'h5',
            'csecplatform' => 4,
            'csecversion' => '4.2.0',
            'partnerId' => $partnerId,
            'poiId' => $poiId,
            'rankType' => $rankType,
            'startDate' => str_replace('-', '', (string)($body['start_date'] ?? '')),
            'endDate' => str_replace('-', '', (string)($body['end_date'] ?? '')),
            'dateRange' => 1,
        ];
        $result = $this->sendMeituanRequest(
            trim((string)($body['url'] ?? '')) ?: 'https://eb.meituan.com/api/v1/ebooking/business/peer/rank/data/detail',
            $params,
            $cookieHeader,
            $this->autoFetchCredentialAuthData($credentialPayload)
        );
        if (!$result['success']) {
            $this->recordCookieAlert('meituan', 'auto-fetch-meituan-ranking', 'meituan_ranking_request_failed', $hotelId);
            return ['module' => $label, 'saved_count' => 0, 'success' => false, 'message' => 'meituan_ranking_request_failed'];
        }

        $savedCount = is_array($result['data'] ?? null)
            ? $this->parseAndSaveMeituanData($result['data'], (string)($body['start_date'] ?? ''), (string)($body['end_date'] ?? ''), $hotelId, [
                'date_range' => (string)($body['date_range'] ?? 'custom'),
                'rank_type' => $rankType,
                'start_date' => (string)($body['start_date'] ?? ''),
                'end_date' => (string)($body['end_date'] ?? ''),
            ])
            : 0;
        return ['module' => $label, 'saved_count' => $savedCount, 'success' => $savedCount > 0, 'message' => $savedCount > 0 ? 'ok' : 'no_rows', 'credential_source' => 'vault'];
    }

    private function executeMeituanTrafficAutoFetchTask(string $label, array $body, int $hotelId): array
    {
        return $this->withAutoFetchCredential('meituan', $body, $hotelId, function (array $credentialPayload) use ($label, $body, $hotelId): array {
            return $this->executeMeituanTrafficAutoFetchWithCredential($label, $body, $hotelId, $credentialPayload);
        });
    }

    private function executeMeituanTrafficAutoFetchWithCredential(string $label, array $body, int $hotelId, array $credentialPayload): array
    {
        $url = trim((string)($body['url'] ?? ''));
        $partnerId = trim((string)($body['partner_id'] ?? ''));
        $poiId = trim((string)($body['poi_id'] ?? ''));
        if ($url === '' || $partnerId === '' || $poiId === '') {
            return ['module' => $label, 'saved_count' => 0, 'success' => false, 'skipped' => true, 'message' => 'meituan_traffic_config_missing'];
        }

        $cookieHeader = $this->autoFetchCredentialCookieHeader($credentialPayload);
        if ($cookieHeader === '') {
            return ['module' => $label, 'saved_count' => 0, 'success' => false, 'message' => 'credential_payload_missing_cookie'];
        }

        $extraParams = $this->configValueToArray($credentialPayload['extra_params'] ?? []);
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

        $result = $this->sendMeituanRequest($url, $params, $cookieHeader, $this->autoFetchCredentialAuthData($credentialPayload));
        if (!$result['success']) {
            $this->recordCookieAlert('meituan', 'auto-fetch-meituan-traffic', 'meituan_traffic_request_failed', $hotelId);
            return ['module' => $label, 'saved_count' => 0, 'success' => false, 'message' => 'meituan_traffic_request_failed'];
        }

        $responseData = $result['data'] ?? [];
        $savedCount = is_array($responseData)
            ? $this->parseAndSaveTrafficData($responseData, $startDate, $endDate, 'meituan', $hotelId, null, $poiId)
            : 0;
        return ['module' => $label, 'saved_count' => $savedCount, 'success' => $savedCount > 0, 'message' => $savedCount > 0 ? 'ok' : 'no_rows', 'credential_source' => 'vault'];
    }

    private function executeMeituanBrowserProfileAutoFetch(array $config, int $hotelId, string $dataDate, bool $interactiveBrowser = false, array $periodOptions = []): array
    {
        $storeId = $this->meituanProfileStoreIdFromConfig($config);
        if ($storeId === '') {
            return ['success' => false, 'skipped' => true, 'message' => '未配置 Store ID / POI ID', 'saved_count' => 0];
        }
        if (!$interactiveBrowser) {
            $profileSource = $this->loadProfileSessionSource('meituan', $hotelId, $storeId);
            $reuseState = (new OtaProfileSessionProofService())->profileReuseState($profileSource ?? []);
            if (empty($reuseState['is_reusable'])) {
                $statusCode = ($reuseState['status'] ?? '') === 'expired'
                    ? 'profile_session_expired'
                    : 'profile_session_unverified';
                return ['success' => false, 'skipped' => true, 'message' => $statusCode, 'status_code' => $statusCode, 'saved_count' => 0];
            }
        }
        if (!$this->meituanProfileExistsForConfig($config) && !$interactiveBrowser) {
            return ['success' => false, 'skipped' => true, 'message' => '未发现本地美团浏览器 Profile，跳过浏览器采集', 'saved_count' => 0];
        }

        $projectRoot = dirname(__DIR__, 3);
        $scriptPath = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'meituan_browser_capture.mjs';
        if (!is_file($scriptPath)) {
            return ['success' => false, 'skipped' => true, 'message' => '未找到美团浏览器抓取脚本', 'saved_count' => 0];
        }
        $nodeBinary = BrowserProfileCaptureRequestService::resolveNodeBinary();
        if ($nodeBinary === '') {
            return ['success' => false, 'skipped' => true, 'message' => '未找到 Node.js', 'saved_count' => 0];
        }

        $outputDir = $projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'meituan_capture';
        if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
            return ['success' => false, 'message' => '无法创建美团抓取输出目录', 'saved_count' => 0];
        }

        $outputPath = $outputDir . DIRECTORY_SEPARATOR . 'meituan_auto_' . BrowserProfileCaptureRequestService::safeFilePart($storeId) . '_' . date('YmdHis') . '.json';
        $chromePath = BrowserProfileCaptureRequestService::resolveChromePath();
        $args = BrowserProfileCaptureRequestService::buildMeituanAutoArgs(
            $config,
            $nodeBinary,
            $scriptPath,
            $hotelId,
            $storeId,
            $outputPath,
            $interactiveBrowser,
            $chromePath,
            $dataDate
        );

        $runResult = $this->runMeituanCaptureProcess($args, $projectRoot, $interactiveBrowser ? 600 : 180);
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
        $payload = $this->applyAutoFetchPeriodOptionsToPayload($payload, $periodOptions);
        try {
            $profileIdentity = $this->resolveMeituanCapturedProfileIdentity(
                ['store_id' => $storeId, 'system_hotel_id' => $hotelId],
                $payload,
                $hotelId
            );
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'meituan_profile_identity_blocked',
                'status_code' => $e->getMessage(),
                'saved_count' => 0,
            ];
        }
        $rows = $this->buildMeituanCapturedDailyRows($payload, $hotelId);
        $persistenceGate = BrowserProfileCaptureRequestService::assessMeituanPersistenceGate(
            $payload,
            $rows,
            $dataDate,
            [
                $profileIdentity['store_id'] ?? null,
                $profileIdentity['poi_id'] ?? null,
            ]
        );
        if (($persistenceGate['ok'] ?? false) !== true) {
            return [
                'success' => false,
                'message' => (string)($persistenceGate['status_code'] ?? 'meituan_capture_unverified'),
                'saved_count' => 0,
                'persistence_gate' => $persistenceGate,
            ];
        }
        if (($persistenceGate['empty_confirmed'] ?? false) === true) {
            return [
                'success' => true,
                'message' => 'empty_confirmed',
                'saved_count' => 0,
                'persistence_gate' => $persistenceGate,
            ];
        }
        $dataSourceId = max(0, (int)($profileIdentity['data_source_id'] ?? 0));
        if ($dataSourceId > 0) {
            foreach ($rows as &$row) {
                if (is_array($row)) {
                    $row['data_source_id'] = $dataSourceId;
                }
            }
            unset($row);
        }
        $rows = $this->uniqueMeituanCapturedRowsForPersistence($rows);
        $savedCount = empty($rows) ? 0 : $this->saveMeituanCapturedDailyRows($rows);
        $rowCount = count($rows);
        $readbackVerified = $rowCount > 0 && $savedCount === $rowCount;

        return [
            'success' => $readbackVerified,
            'message' => $readbackVerified
                ? "浏览器采集已解析并回读确认 {$savedCount} 条"
                : ($rowCount > 0 ? "浏览器采集解析 {$rowCount} 条，但仅回读确认 {$savedCount} 条" : '浏览器采集未解析到指定日期数据'),
            'saved_count' => $savedCount,
            'row_count' => $rowCount,
            'persistence_status' => $readbackVerified ? 'readback_verified' : ($rowCount > 0 ? 'readback_not_verified' : 'no_parsed_rows'),
            'persistence_gate' => $persistenceGate,
        ];
    }
}
