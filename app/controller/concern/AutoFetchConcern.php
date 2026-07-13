<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\model\OperationLog;
use app\model\SystemNotification;
use app\service\BrowserProfileCaptureRequestService;
use app\service\OtaProfileBindingService;
use app\service\OtaProfileSessionProofService;
use app\service\PlatformProfileBindingReadinessService;
use app\service\PlatformDataSyncService;
use think\Response;
use think\facade\Db;

trait AutoFetchConcern
{
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
            $this->recordAutoFetchNotification((int)$systemHotelId, false, '未配置携程或美团抓取凭证，请先在酒店管理中关联平台配置', date('Y-m-d'), [
                'data_period' => 'realtime_snapshot',
            ], 'auto_fetch');
            return $this->error('未配置携程或美团抓取凭证，请先在酒店管理中关联平台配置');
        }

        $requestData = $this->requestData();
        $dataPeriod = $this->normalizeOnlineDailyDataPeriod($requestData['data_period'] ?? $requestData['dataPeriod'] ?? 'realtime_snapshot');
        if ($dataPeriod === '') {
            $dataPeriod = 'realtime_snapshot';
        }
        $targetDataDate = $dataPeriod === 'realtime_snapshot' ? date('Y-m-d') : date('Y-m-d', strtotime('-1 day'));
        $interactiveBrowser = filter_var(
            $this->request->post('interactive_browser', $this->request->post('interactiveBrowser', false)),
            FILTER_VALIDATE_BOOLEAN
        );
        if (array_key_exists('browser_headless', $requestData) || array_key_exists('headless', $requestData)) {
            $interactiveBrowser = !$this->autoFetchBrowserHeadlessFromRequest($requestData, true);
        }
        $autoFetchModeRaw = $this->request->post('auto_fetch_mode', $this->request->post('autoMode', null));
        $fetchOptions = [
            'interactive_browser' => $interactiveBrowser,
            'browser_headless' => !$interactiveBrowser,
            'data_period' => $dataPeriod,
            'snapshot_time' => date('Y-m-d H:i:s'),
            'ctrip_section_concurrency' => $this->ctripSectionConcurrencyFromRequest($requestData, 3),
        ];
        if ($autoFetchModeRaw !== null && trim((string)$autoFetchModeRaw) !== '') {
            $fetchOptions['auto_fetch_mode'] = $autoFetchModeRaw;
        }
        $fetchOptions = array_merge($fetchOptions, $this->platformAutoFetchModeOptionsFromRequest($requestData));
        $backgroundRequested = $this->isTruthyRequestValue($requestData['async'] ?? $requestData['background'] ?? false)
            && !$this->isTruthyRequestValue($requestData['background_task'] ?? false);
        if ($backgroundRequested) {
            $task = $this->createAutoFetchBackgroundTask((int)$systemHotelId, $targetDataDate, $dataPeriod, $requestData, $fetchOptions);
            if (empty($task)) {
                return $this->error('后台自动获取任务创建失败，请检查 PHP CLI 或登录状态');
            }
            $this->markAutoFetchRunningStatus((int)$systemHotelId, $targetDataDate, $dataPeriod, $task, $fetchOptions);
            if (!$this->launchAutoFetchBackgroundTask($task)) {
                $inputPath = (string)($task['input'] ?? '');
                if ($inputPath !== '' && is_file($inputPath)) {
                    @unlink($inputPath);
                }
                $this->updateFetchStatus((int)$systemHotelId, false, '后台自动获取任务启动失败', $targetDataDate, [
                    'data_period' => $dataPeriod,
                    'auto_fetch_mode' => $fetchOptions['auto_fetch_mode'] ?? null,
                    'ctrip_section_concurrency' => $fetchOptions['ctrip_section_concurrency'] ?? 3,
                ]);
                return $this->error('后台自动获取任务启动失败');
            }

            OperationLog::record('online_data', 'auto_fetch_queued', "平台数据自动获取已提交后台执行 (门店ID: {$systemHotelId})", $this->currentUser->id);
            return $this->success([
                'status' => 'running',
                'task_id' => (string)$task['task_id'],
                'data_date' => $targetDataDate,
                'data_period' => $dataPeriod,
                'saved_count' => 0,
                'auto_fetch_mode' => $fetchOptions['auto_fetch_mode'] ?? 'hybrid_auto',
                'auto_fetch_mode_label' => $this->autoFetchModeLabel((string)($fetchOptions['auto_fetch_mode'] ?? 'hybrid_auto')),
                'ctrip_section_concurrency' => $fetchOptions['ctrip_section_concurrency'] ?? 3,
            ], '自动获取已提交后台执行');
        }

        try {
            $result = $this->executeAutoFetch((int)$systemHotelId, $targetDataDate, $fetchOptions);
            $this->updateFetchStatus((int)$systemHotelId, (bool)$result['success'], (string)$result['message'], $targetDataDate, [
                'saved_count' => (int)($result['saved_count'] ?? 0),
                'auto_fetch_mode' => $result['auto_fetch_mode'] ?? null,
                'platform_results' => $result['platform_results'] ?? [],
                'data_period' => $dataPeriod,
                'timing' => $result['timing'] ?? [],
                'ctrip_section_concurrency' => $result['ctrip_section_concurrency'] ?? $fetchOptions['ctrip_section_concurrency'] ?? 3,
            ]);
            $this->recordAutoFetchNotification((int)$systemHotelId, (bool)$result['success'], (string)$result['message'], $targetDataDate, [
                'saved_count' => (int)($result['saved_count'] ?? 0),
                'auto_fetch_mode' => $result['auto_fetch_mode'] ?? null,
                'platform_results' => $result['platform_results'] ?? [],
                'data_period' => $dataPeriod,
                'timing' => $result['timing'] ?? [],
                'ctrip_section_concurrency' => $result['ctrip_section_concurrency'] ?? $fetchOptions['ctrip_section_concurrency'] ?? 3,
            ], 'auto_fetch');

            if ($result['success']) {
                OperationLog::record('online_data', 'auto_fetch', "平台数据自动获取: {$result['saved_count']}条 (门店ID: {$systemHotelId})", $this->currentUser->id);
                return $this->success([
                    'data_date' => $targetDataDate,
                    'data_period' => $dataPeriod,
                    'saved_count' => (int)($result['saved_count'] ?? 0),
                    'auto_fetch_mode' => $result['auto_fetch_mode'] ?? 'hybrid_auto',
                    'auto_fetch_mode_label' => $result['auto_fetch_mode_label'] ?? '接口直连自动',
                    'platform_results' => $result['platform_results'] ?? [],
                    'timing' => $result['timing'] ?? [],
                    'ctrip_section_concurrency' => $result['ctrip_section_concurrency'] ?? $fetchOptions['ctrip_section_concurrency'] ?? 3,
                ], '自动获取成功');
            }

            return $this->error('自动获取失败: ' . $result['message'], 400, [
                'data_date' => $targetDataDate,
                'data_period' => $dataPeriod,
                'saved_count' => (int)($result['saved_count'] ?? 0),
                'auto_fetch_mode' => $result['auto_fetch_mode'] ?? 'hybrid_auto',
                'auto_fetch_mode_label' => $result['auto_fetch_mode_label'] ?? '接口直连自动',
                'platform_results' => $result['platform_results'] ?? [],
                'timing' => $result['timing'] ?? [],
                'ctrip_section_concurrency' => $result['ctrip_section_concurrency'] ?? $fetchOptions['ctrip_section_concurrency'] ?? 3,
            ]);

        } catch (\Throwable $e) {
            \think\facade\Log::error('Platform auto-fetch execution failed', [
                'user_id' => $this->currentUser->id,
                'hotel_id' => $systemHotelId,
                'exception_type' => get_debug_type($e),
            ]);

            $this->updateFetchStatus((int)$systemHotelId, false, 'auto_fetch_execution_failed', $targetDataDate, [
                'data_period' => $dataPeriod,
                'ctrip_section_concurrency' => $fetchOptions['ctrip_section_concurrency'] ?? 3,
            ]);
            $this->recordAutoFetchNotification((int)$systemHotelId, false, 'auto_fetch_execution_failed', $targetDataDate, [
                'data_period' => $dataPeriod,
                'ctrip_section_concurrency' => $fetchOptions['ctrip_section_concurrency'] ?? 3,
            ], 'auto_fetch');

            return $this->error('auto_fetch_execution_failed');
        }
    }

    /**
     * 更新获取状态
     */
    private function createAutoFetchBackgroundTask(
        int $hotelId,
        string $dataDate,
        string $dataPeriod,
        array $requestData,
        array $fetchOptions,
        string $apiPath = '/api/online-data/auto-fetch',
        array $bodyOverrides = []
    ): array
    {
        $authorization = trim((string)$this->request->header('Authorization', ''));
        if ($hotelId <= 0 || $authorization === '') {
            return [];
        }

        $projectRoot = dirname(__DIR__, 3);
        $phpBinary = $this->resolvePhpCliBinary();
        $thinkPath = $projectRoot . DIRECTORY_SEPARATOR . 'think';
        if ($phpBinary === '' || !is_file($thinkPath)) {
            return [];
        }

        $taskId = 'auto_fetch_' . $hotelId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
        $dir = $projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'auto_fetch_tasks' . DIRECTORY_SEPARATOR . $taskId;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return [];
        }

        $mode = $fetchOptions['auto_fetch_mode'] ?? ($requestData['auto_fetch_mode'] ?? $requestData['autoMode'] ?? 'hybrid_auto');
        $body = [
            'system_hotel_id' => $hotelId,
            'data_period' => $dataPeriod,
            'interactive_browser' => !empty($fetchOptions['interactive_browser']),
            'browser_headless' => !empty($fetchOptions['browser_headless']),
            'async' => false,
            'background_task' => true,
            'auto_fetch_mode' => $mode,
            'ctrip_auto_fetch_mode' => $fetchOptions['ctrip_auto_fetch_mode'] ?? $mode,
            'meituan_auto_fetch_mode' => $fetchOptions['meituan_auto_fetch_mode'] ?? $mode,
            'ctrip_section_concurrency' => $fetchOptions['ctrip_section_concurrency'] ?? 3,
        ];
        $body = array_merge($body, $bodyOverrides);
        $apiPath = '/' . ltrim($apiPath, '/');
        $inputPath = $dir . DIRECTORY_SEPARATOR . 'input.json';
        $task = [
            'task_id' => $taskId,
            'hotel_id' => $hotelId,
            'data_date' => $dataDate,
            'data_period' => $dataPeriod,
            'api_url' => rtrim($this->request->domain(), '/') . $apiPath,
            'authorization' => $authorization,
            'body' => $body,
            'input' => $inputPath,
            'log' => $dir . DIRECTORY_SEPARATOR . 'launcher.log',
            'created_at' => date('Y-m-d H:i:s'),
        ];
        if (file_put_contents($inputPath, json_encode($task, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
            return [];
        }

        return $task;
    }

    private function launchAutoFetchBackgroundTask(array $task): bool
    {
        $projectRoot = dirname(__DIR__, 3);
        $phpBinary = $this->resolvePhpCliBinary();
        $thinkPath = $projectRoot . DIRECTORY_SEPARATOR . 'think';
        $inputPath = (string)($task['input'] ?? '');
        if ($phpBinary === '' || !is_file($thinkPath) || !is_file($inputPath)) {
            return false;
        }

        $dir = dirname($inputPath);
        $taskId = (string)$task['task_id'];

        if (DIRECTORY_SEPARATOR === '\\') {
            $batPath = $dir . DIRECTORY_SEPARATOR . $taskId . '.bat';
            $inputFile = basename($inputPath);
            $lines = [
                '@echo off',
                'setlocal',
                'set "TASK_DIR=%~dp0"',
                'pushd "%TASK_DIR%..\..\.." || exit /b 1',
                $this->quoteWindowsBatchArg($phpBinary)
                    . ' "%CD%\think"'
                    . ' "online-data:auto-fetch-once"'
                    . ' "--task-id=' . $taskId . '"'
                    . ' "--input=%TASK_DIR%' . $inputFile . '"'
                    . ' >> "%TASK_DIR%launcher.log" 2>&1',
                'set "EXIT_CODE=%ERRORLEVEL%"',
                'popd',
                'exit /b %EXIT_CODE%',
            ];
            if (file_put_contents($batPath, implode(PHP_EOL, $lines) . PHP_EOL) === false) {
                return false;
            }
            return $this->launchWindowsBatchFile($batPath);
        }

        $shellPath = $dir . DIRECTORY_SEPARATOR . $taskId . '.sh';
        $command = 'cd ' . escapeshellarg($projectRoot)
            . ' && ' . escapeshellarg($phpBinary)
            . ' ' . escapeshellarg($thinkPath)
            . ' online-data:auto-fetch-once'
            . ' --task-id=' . escapeshellarg($taskId)
            . ' --input=' . escapeshellarg($inputPath)
            . ' >> ' . escapeshellarg((string)$task['log']) . ' 2>&1';
        if (file_put_contents($shellPath, "#!/bin/sh\n" . $command . "\n") === false) {
            return false;
        }
        @chmod($shellPath, 0755);
        $handle = @popen('sh ' . escapeshellarg($shellPath) . ' >/dev/null 2>&1 &', 'r');
        if (!is_resource($handle)) {
            return false;
        }
        pclose($handle);
        return true;
    }

    private function markAutoFetchRunningStatus(int $hotelId, string $dataDate, string $dataPeriod, array $task, array $fetchOptions): void
    {
        $statusKey = $this->autoFetchStatusKey($hotelId);
        $status = cache($statusKey) ?: [];
        $status = is_array($status) ? $status : [];
        $runAt = date('Y-m-d H:i:s');
        $mode = $this->normalizeAutoFetchMode($fetchOptions['auto_fetch_mode'] ?? 'hybrid_auto');
        $ctripConfigured = $this->hasCtripFetchConfigForHotel($hotelId);
        $meituanConfigured = $this->hasMeituanFetchConfigForHotel($hotelId);
        $status['last_run_time'] = $runAt;
        $status['last_data_date'] = $dataDate;
        $status['auto_fetch_mode'] = $mode;
        $status['ctrip_auto_fetch_mode'] = $this->normalizeAutoFetchMode($fetchOptions['ctrip_auto_fetch_mode'] ?? $mode);
        $status['meituan_auto_fetch_mode'] = $this->normalizeAutoFetchMode($fetchOptions['meituan_auto_fetch_mode'] ?? $mode);
        $status['ctrip_section_concurrency'] = $this->normalizeCtripSectionConcurrency($fetchOptions['ctrip_section_concurrency'] ?? 3);
        $status['running_task'] = [
            'task_id' => (string)$task['task_id'],
            'started_at' => $runAt,
            'updated_at' => $runAt,
            'data_date' => $dataDate,
            'data_period' => $dataPeriod,
            'platforms' => [
                'ctrip' => [
                    'platform' => 'ctrip',
                    'status' => $ctripConfigured ? 'queued' : 'skipped',
                    'message' => $ctripConfigured ? '等待开始携程采集' : '未配置携程采集路径',
                    'saved_count' => 0,
                ],
                'meituan' => [
                    'platform' => 'meituan',
                    'status' => $meituanConfigured ? 'queued' : 'skipped',
                    'message' => $meituanConfigured ? '等待开始美团采集' : '未配置美团采集路径',
                    'saved_count' => 0,
                ],
            ],
        ];
        $status['last_result'] = [
            'success' => null,
            'status' => 'running',
            'message' => '自动获取已提交后台执行，采集完成后会更新结果',
            'data_period' => $dataPeriod,
            'saved_count' => 0,
            'auto_fetch_mode' => $mode,
            'auto_fetch_mode_label' => $this->autoFetchModeLabel($mode),
            'ctrip_section_concurrency' => $status['ctrip_section_concurrency'],
            'task_id' => (string)$task['task_id'],
        ];
        cache($statusKey, $status, 86400 * 30);
    }

    private function updateAutoFetchRunningPlatformProgress(
        int $hotelId,
        string $platform,
        string $progressStatus,
        array $details = []
    ): void {
        $platform = strtolower(trim($platform));
        $progressStatus = strtolower(trim($progressStatus));
        if ($hotelId <= 0
            || !in_array($platform, ['ctrip', 'meituan'], true)
            || !in_array($progressStatus, ['queued', 'running', 'success', 'failed', 'skipped'], true)
        ) {
            return;
        }

        $statusKey = $this->autoFetchStatusKey($hotelId);
        $status = cache($statusKey) ?: [];
        if (!is_array($status) || !is_array($status['running_task'] ?? null)) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $platforms = is_array($status['running_task']['platforms'] ?? null)
            ? $status['running_task']['platforms']
            : [];
        $row = is_array($platforms[$platform] ?? null) ? $platforms[$platform] : [];
        $row['platform'] = $platform;
        $row['status'] = $progressStatus;
        $row['updated_at'] = $now;
        if ($progressStatus === 'running' && empty($row['started_at'])) {
            $row['started_at'] = $now;
        }
        if (in_array($progressStatus, ['success', 'failed', 'skipped'], true)) {
            $row['finished_at'] = $now;
        }
        if (array_key_exists('saved_count', $details)) {
            $row['saved_count'] = max(0, (int)$details['saved_count']);
        }
        if (array_key_exists('message', $details)) {
            $row['message'] = trim((string)$details['message']);
        }

        $platforms[$platform] = $row;
        $status['running_task']['platforms'] = $platforms;
        $status['running_task']['updated_at'] = $now;
        cache($statusKey, $status, 86400 * 30);
    }

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
        $dataPeriod = $this->normalizeOnlineDailyDataPeriod($details['data_period'] ?? $details['dataPeriod'] ?? '');
        $statusCode = trim((string)($details['status'] ?? ($success ? 'success' : 'failed')));
        if ($statusCode !== '') {
            $runRecord['status'] = $statusCode;
        }
        if ($dataPeriod !== '') {
            $runRecord['data_period'] = $dataPeriod;
        }
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
        if (!empty($details['timing']) && is_array($details['timing'])) {
            $runRecord['timing'] = $this->normalizeAutoFetchTiming($details['timing']);
        }
        if (array_key_exists('ctrip_section_concurrency', $details)) {
            $runRecord['ctrip_section_concurrency'] = $this->normalizeCtripSectionConcurrency($details['ctrip_section_concurrency']);
            $status['ctrip_section_concurrency'] = $runRecord['ctrip_section_concurrency'];
        }

        $status['last_run_time'] = $runAt;
        $status['last_data_date'] = $dataDate;
        unset($status['running_task']);
        $status['last_result'] = [
            'success' => $success,
            'message' => $message,
            'status' => $statusCode,
        ];
        if ($dataPeriod !== '') {
            $status['last_result']['data_period'] = $dataPeriod;
        }
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
        if (!empty($details['timing']) && is_array($details['timing'])) {
            $status['last_result']['timing'] = $this->normalizeAutoFetchTiming($details['timing']);
        }
        if (array_key_exists('ctrip_section_concurrency', $details)) {
            $status['last_result']['ctrip_section_concurrency'] = $this->normalizeCtripSectionConcurrency($details['ctrip_section_concurrency']);
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
        if (!$success && !in_array($statusCode, ['running', 'queued'], true)) {
            array_unshift($failedRecords, [
                'data_date' => $dataDate,
                'last_failed_at' => $runAt,
                'message' => $message,
            ]);
        }
        $status['failed_records'] = array_slice($failedRecords, 0, 30);

        cache($statusKey, $status, 86400 * 30);
    }

    private function recordAutoFetchNotification(int $hotelId, bool $success, string $message, ?string $dataDate, array $details = [], string $action = 'auto_fetch'): void
    {
        if ($hotelId <= 0) {
            return;
        }

        $dataDate = $dataDate ?: date('Y-m-d');
        $savedCount = (int)($details['saved_count'] ?? 0);
        $isRetry = $action === 'retry_auto_fetch';
        $isManualFetch = $action === 'manual_fetch';
        $title = $success
            ? ($isManualFetch ? 'OTA 手动获取完成' : ($isRetry ? 'OTA 补抓完成' : 'OTA 自动采集完成'))
            : ($isManualFetch ? 'OTA 手动获取失败' : ($isRetry ? 'OTA 补抓失败' : 'OTA 自动采集失败'));
        $displayMessage = $success
            ? "数据日期 {$dataDate}，已保存 {$savedCount} 条 OTA 指标行。"
            : "数据日期 {$dataDate}，" . $message;

        try {
            SystemNotification::recordEvent([
                'hotel_id' => $hotelId,
                'user_id' => (int)($this->currentUser->id ?? 0),
                'platform' => $this->notificationPlatformFromResults($details['platform_results'] ?? []),
                'category' => $success ? 'capture_success' : 'capture_failed',
                'severity' => $success ? 'success' : 'error',
                'title' => $title,
                'message' => $displayMessage,
                'action_type' => $success ? 'view' : 'fetch',
                'action_payload' => [
                    'target_page' => 'online-data',
                    'target_tab' => $isManualFetch ? 'data' : 'data-health',
                    'action_label' => $success ? '查看数据' : '查看原因',
                    'data_date' => $dataDate,
                    'data_period' => $details['data_period'] ?? '',
                    'auto_fetch_mode' => $details['auto_fetch_mode'] ?? '',
                ],
                'source_module' => 'online_data',
                'source_key' => $this->notificationSourceKey($action, $hotelId, $dataDate, $success, $message, $details),
            ]);
        } catch (\Throwable $e) {
            \think\facade\Log::warning('系统通知写入失败: ' . $e->getMessage(), [
                'hotel_id' => $hotelId,
                'action' => $action,
            ]);
        }
    }

    private function notificationPlatformFromResults($platformResults): string
    {
        if (!is_array($platformResults) || empty($platformResults)) {
            return 'ota';
        }

        $platforms = [];
        foreach ($platformResults as $key => $result) {
            $platform = is_string($key) ? $key : (is_array($result) ? (string)($result['platform'] ?? '') : '');
            $platform = strtolower(trim($platform));
            if ($platform !== '') {
                $platforms[$platform] = true;
            }
        }

        if (count($platforms) === 1) {
            return array_key_first($platforms) ?: 'ota';
        }

        return 'ota';
    }

    private function notificationSourceKey(string $action, int $hotelId, string $dataDate, bool $success, string $message, array $details = []): string
    {
        $parts = [
            $action,
            (string)$hotelId,
            $dataDate,
            $success ? 'success' : 'failed',
            (string)($details['data_period'] ?? ''),
            (string)($details['saved_count'] ?? ''),
            $message,
        ];

        return implode(':', [
            'online_data',
            $action,
            $hotelId,
            $dataDate,
            $success ? 'ok' : 'fail',
            substr(sha1(implode('|', $parts)), 0, 16),
        ]);
    }

    private function normalizeFetchScheduleTime(string $scheduleTime): ?string
    {
        $scheduleTime = trim($scheduleTime);
        if (!preg_match('/^([01]?[0-9]|2[0-3]):([0-5][0-9])$/', $scheduleTime, $matches)) {
            return null;
        }
        return sprintf('%02d:%02d', (int)$matches[1], (int)$matches[2]);
    }

    private function normalizeAutoFetchScheduleMinute($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        $minute = (int)$value;
        return $minute >= 0 && $minute <= 59 ? $minute : null;
    }

    private function normalizeAutoFetchScheduleIntervalHours($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        $hours = (int)$value;
        return $hours >= 1 && $hours <= 24 ? $hours : null;
    }

    private function isRealtimeAutoFetchHourDue(int $hour, int $intervalHours): bool
    {
        $intervalHours = $this->normalizeAutoFetchScheduleIntervalHours($intervalHours) ?? 2;
        return $hour % $intervalHours === 0;
    }

    private function normalizeAutoFetchScheduleStatus(array $status): array
    {
        $historicalTime = $this->normalizeFetchScheduleTime((string)($status['historical_schedule_time'] ?? $status['schedule_time'] ?? '10:00')) ?? '10:00';
        $realtimeMinute = $this->normalizeAutoFetchScheduleMinute($status['realtime_schedule_minute'] ?? $status['schedule_minute'] ?? 5);
        if ($realtimeMinute === null) {
            $realtimeMinute = 5;
        }
        $realtimeIntervalHours = $this->normalizeAutoFetchScheduleIntervalHours($status['realtime_schedule_interval_hours'] ?? $status['realtime_interval_hours'] ?? $status['schedule_interval_hours'] ?? null) ?? 2;

        $historicalEnabled = array_key_exists('historical_enabled', $status)
            ? $this->isTruthyRequestValue($status['historical_enabled'])
            : true;
        $realtimeEnabled = array_key_exists('realtime_enabled', $status)
            ? $this->isTruthyRequestValue($status['realtime_enabled'])
            : true;

        $status['historical_enabled'] = $historicalEnabled;
        $status['realtime_enabled'] = $realtimeEnabled;
        $status['historical_schedule_time'] = $historicalTime;
        $status['realtime_schedule_minute'] = $realtimeMinute;
        $status['realtime_schedule_interval_hours'] = $realtimeIntervalHours;
        $status['schedule_time'] = $historicalTime;
        $status['schedule_minute'] = $realtimeMinute;
        $status['schedule_interval_hours'] = $realtimeIntervalHours;
        $status['ctrip_section_concurrency'] = $this->normalizeCtripSectionConcurrency($status['ctrip_section_concurrency'] ?? $status['ctripSectionConcurrency'] ?? 3);

        $enabled = !empty($status['enabled']);
        $historicalNext = $enabled && $historicalEnabled ? $this->nextHistoricalAutoFetchRunTime($historicalTime) : '-';
        $realtimeNext = $enabled && $realtimeEnabled ? $this->nextRealtimeAutoFetchRunTime($realtimeMinute, $realtimeIntervalHours) : '-';
        $status['historical'] = [
            'enabled' => $historicalEnabled,
            'schedule_time' => $historicalTime,
            'data_period' => 'historical_daily',
            'next_run_time' => $historicalNext,
        ];
        $status['realtime'] = [
            'enabled' => $realtimeEnabled,
            'schedule_minute' => $realtimeMinute,
            'schedule_interval_hours' => $realtimeIntervalHours,
            'data_period' => 'realtime_snapshot',
            'next_run_time' => $realtimeNext,
        ];

        if (!$enabled) {
            $status['next_run_time'] = '未开启';
            return $status;
        }

        $candidates = array_values(array_filter([$historicalNext, $realtimeNext], static fn(string $value): bool => $value !== '-'));
        sort($candidates);
        $status['next_run_time'] = $candidates[0] ?? '-';
        return $status;
    }

    private function nextHistoricalAutoFetchRunTime(string $scheduleTime): string
    {
        $timestamp = strtotime(date('Y-m-d') . ' ' . $scheduleTime . ':00');
        if ($timestamp === false || $timestamp <= time()) {
            $timestamp = strtotime('+1 day', $timestamp ?: time());
        }
        return date('Y-m-d H:i', $timestamp);
    }

    private function nextRealtimeAutoFetchRunTime(int $scheduleMinute, int $intervalHours = 2): string
    {
        $intervalHours = $this->normalizeAutoFetchScheduleIntervalHours($intervalHours) ?? 2;
        $base = strtotime(date('Y-m-d H') . sprintf(':%02d:00', $scheduleMinute));
        $now = time();
        for ($offset = 0; $offset <= 48; $offset++) {
            $timestamp = strtotime("+{$offset} hour", $base ?: $now);
            if ($timestamp !== false && $timestamp > $now && $this->isRealtimeAutoFetchHourDue((int)date('G', $timestamp), $intervalHours)) {
                return date('Y-m-d H:i', $timestamp);
            }
        }
        return date('Y-m-d H:i', strtotime("+{$intervalHours} hour", $base ?: $now) ?: $now);
    }

    private function normalizeAutoFetchTiming(array $timing): array
    {
        $keys = [
            'capture_elapsed_ms',
            'raw_store_elapsed_ms',
            'normalize_elapsed_ms',
            'daily_rows_save_elapsed_ms',
            'finish_task_elapsed_ms',
            'total_elapsed_ms',
        ];
        $normalized = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $timing)) {
                $normalized[$key] = max(0, (int)$timing[$key]);
            }
        }
        return $normalized;
    }

    private function mergeAutoFetchPlatformTiming(array $platformResults): array
    {
        $merged = [];
        foreach ($platformResults as $result) {
            if (!is_array($result) || empty($result['timing']) || !is_array($result['timing'])) {
                continue;
            }
            $merged = $this->sumAutoFetchTiming($merged, $result['timing']);
        }
        return $merged;
    }

    private function sumAutoFetchTiming(array $base, array $timing): array
    {
        foreach ($this->normalizeAutoFetchTiming($timing) as $key => $value) {
            $base[$key] = ($base[$key] ?? 0) + (int)$value;
        }
        return $base;
    }

    private function applyAutoFetchPeriodOptionsToPayload(array $payload, array $options): array
    {
        $period = $this->normalizeOnlineDailyDataPeriod($options['data_period'] ?? $options['dataPeriod'] ?? '');
        if ($period !== '' && empty($payload['data_period'])) {
            $payload['data_period'] = $period;
        }
        $snapshotTime = $this->normalizeOnlineDailyDateTime($options['snapshot_time'] ?? $options['snapshotTime'] ?? null);
        if ($snapshotTime !== null && empty($payload['snapshot_time'])) {
            $payload['snapshot_time'] = $snapshotTime;
        }
        return $payload;
    }

    private function applyAutoFetchPeriodOptionsToRows(array $rows, array $options): array
    {
        $payload = $this->applyAutoFetchPeriodOptionsToPayload([], $options);
        if (empty($payload)) {
            return $rows;
        }
        foreach ($rows as &$row) {
            if (is_array($row)) {
                $row = array_merge($payload, $row);
            }
        }
        unset($row);
        return $rows;
    }

    private function normalizeAutoFetchDailyReportTimeFromRequest(array $requestData, string $fallback = '09:00'): ?string
    {
        $time = trim((string)($requestData['daily_report_time'] ?? $requestData['dailyReportTime'] ?? $requestData['report_time'] ?? $requestData['reportTime'] ?? ''));
        if ($time !== '') {
            return $this->normalizeFetchScheduleTime($time);
        }

        $hourRaw = $requestData['daily_report_hour'] ?? $requestData['dailyReportHour'] ?? $requestData['report_hour'] ?? $requestData['reportHour'] ?? null;
        $minuteRaw = $requestData['daily_report_minute'] ?? $requestData['dailyReportMinute'] ?? $requestData['report_minute'] ?? $requestData['reportMinute'] ?? null;
        if ($hourRaw !== null || $minuteRaw !== null) {
            if (!is_numeric($hourRaw) || !is_numeric($minuteRaw)) {
                return null;
            }
            $hour = (int)$hourRaw;
            $minute = (int)$minuteRaw;
            if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
                return null;
            }
            return sprintf('%02d:%02d', $hour, $minute);
        }

        return $this->normalizeFetchScheduleTime($fallback);
    }

    private function autoFetchBrowserHeadlessFromRequest(array $requestData, bool $fallback = true): bool
    {
        foreach (['browser_headless', 'browserHeadless', 'headless', 'headless_browser', 'headlessBrowser'] as $key) {
            if (array_key_exists($key, $requestData)) {
                return $this->isTruthyRequestValue($requestData[$key]);
            }
        }

        return $fallback;
    }

    private function normalizeCtripSectionConcurrency($value): int
    {
        if ($value === null || $value === '') {
            return 3;
        }
        if (!is_numeric($value)) {
            return 3;
        }
        return max(1, min(4, (int)$value));
    }

    private function ctripSectionConcurrencyFromRequest(array $requestData, int $fallback = 3): int
    {
        foreach (['ctrip_section_concurrency', 'ctripSectionConcurrency', 'section_concurrency', 'sectionConcurrency'] as $key) {
            if (array_key_exists($key, $requestData)) {
                return $this->normalizeCtripSectionConcurrency($requestData[$key]);
            }
        }

        return $this->normalizeCtripSectionConcurrency($fallback);
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
        if ($this->hasEnabledCtripBrowserProfileDataSources($hotelId)) {
            return true;
        }

        $fetchConfig = $this->resolveCtripFetchConfigForHotel($hotelId);
        if ($this->autoFetchCredentialReady($fetchConfig)) {
            return true;
        }
        if (!empty($fetchConfig) && $this->ctripProfileStoreIdFromConfig($fetchConfig, $hotelId) !== '') {
            return true;
        }
        if ($this->ctripProfileExistsForConfig($fetchConfig, $hotelId)) {
            return true;
        }

        $tasks = $this->buildAutoFetchConfigTaskPlan($hotelId, date('Y-m-d', strtotime('-1 day')), $fetchConfig, []);
        return (bool)array_filter($tasks, static fn(array $task): bool => ($task['platform'] ?? '') === 'ctrip');
    }

    private function hasMeituanFetchConfigForHotel(int $hotelId): bool
    {
        $fetchConfig = $this->resolveMeituanFetchConfigForHotel($hotelId);
        $apiStatus = $this->meituanAutoFetchConfigStatus($fetchConfig, $hotelId);
        if (!empty($apiStatus['api_configured']) || $this->meituanProfileExistsForConfig($fetchConfig)) {
            return true;
        }

        $tasks = $this->buildAutoFetchConfigTaskPlan($hotelId, date('Y-m-d', strtotime('-1 day')), [], $fetchConfig);
        return (bool)array_filter($tasks, static fn(array $task): bool => ($task['platform'] ?? '') === 'meituan');
    }

    private function autoFetchConfigId(array $config): string
    {
        $configId = trim((string)($config['config_id'] ?? $config['id'] ?? ''));
        return preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $configId) === 1 ? $configId : '';
    }

    private function autoFetchCredentialReady(array $config): bool
    {
        return $this->autoFetchConfigId($config) !== ''
            && (string)($config['credential_status'] ?? '') === 'ready'
            && ($config['has_cookies'] ?? false) === true;
    }

    private function autoFetchCtripRequestUrl(array $config): string
    {
        $default = 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportCompeteHotelReport';
        $url = trim((string)($config['url'] ?? ''));
        if ($url === '') {
            return $default;
        }
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string)($parts['host'] ?? '')) !== 'ebooking.ctrip.com'
        ) {
            return '';
        }
        return $url;
    }

    private function autoFetchCtripNodeId(array $config): string
    {
        $nodeId = trim((string)($config['node_id'] ?? $config['nodeId'] ?? '24588'));
        return preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $nodeId) === 1 ? $nodeId : '';
    }

    private function hasAnyPlatformFetchConfigForHotel(int $hotelId): bool
    {
        return $this->hasCtripFetchConfigForHotel($hotelId) || $this->hasMeituanFetchConfigForHotel($hotelId);
    }

    private function hasEnabledCtripBrowserProfileDataSources(int $hotelId): bool
    {
        return $this->listEnabledCtripBrowserProfileDataSources($hotelId) !== [];
    }

    private function autoFetchLightConfigListCacheKey(string $platform): string
    {
        return 'online_data_auto_fetch_light_' . strtolower(trim($platform)) . '_config_list_metadata_v2';
    }

    private function autoFetchLightProfileSourcesCacheKey(int $hotelId, string $platform = ''): string
    {
        $platformKey = strtolower(trim($platform));
        return 'online_data_auto_fetch_light_profile_sources_v3_' . $hotelId . '_' . ($platformKey !== '' ? $platformKey : 'all');
    }

    private function readAutoFetchLightReadCache(string $cacheKey): ?array
    {
        if (array_key_exists($cacheKey, $this->autoFetchLightReadCache)) {
            return $this->autoFetchLightReadCache[$cacheKey];
        }

        $cached = cache($cacheKey);
        if (is_array($cached)) {
            $this->autoFetchLightReadCache[$cacheKey] = $cached;
            return $cached;
        }

        return null;
    }

    private function writeAutoFetchLightReadCache(string $cacheKey, array $value): array
    {
        $this->autoFetchLightReadCache[$cacheKey] = $value;
        cache($cacheKey, $value, self::AUTO_FETCH_LIGHT_READ_CACHE_TTL_SECONDS);
        return $value;
    }

    /**
     * @param array<mixed> $rows
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeBrowserProfileSourcesForSharedCache(array $rows): array
    {
        $safeRows = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            unset($row['secret_json']);
            $rawConfig = trim((string)($row['config_json'] ?? ''));
            $config = $rawConfig === '' ? [] : json_decode($rawConfig, true);
            $migrationReason = '';
            if (!is_array($config)) {
                $config = [];
                $migrationReason = 'invalid_config_json';
            }

            [$safeConfig, $legacyConfigSecrets] = $this->splitOtaConfigSecrets($config);
            $row['config_json'] = json_encode(
                $safeConfig,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            [$safeRow, $legacyRowSecrets] = $this->splitOtaConfigSecrets($row);
            if ($legacyConfigSecrets !== [] || $legacyRowSecrets !== []) {
                $migrationReason = 'legacy_secret_fields_present';
            }
            if ($migrationReason !== '') {
                $safeRow['migration_required'] = true;
                $safeRow['migration_reason'] = $migrationReason;
                $safeRow['status'] = 'migration_required';
            }

            $safeRows[] = $safeRow;
        }

        return $safeRows;
    }

    private function clearAutoFetchLightConfigListCache(string $platform = ''): void
    {
        $platforms = $platform !== '' ? [strtolower(trim($platform))] : ['ctrip', 'meituan'];
        foreach ($platforms as $platformKey) {
            if ($platformKey === '') {
                continue;
            }
            $cacheKey = $this->autoFetchLightConfigListCacheKey($platformKey);
            unset($this->autoFetchLightReadCache[$cacheKey]);
            cache($cacheKey, null);
        }
    }

    private function clearAutoFetchLightProfileSourcesCache(int $hotelId = 0, string $platform = ''): void
    {
        if ($hotelId <= 0) {
            $this->autoFetchLightReadCache = [];
            return;
        }

        $platforms = $platform !== '' ? ['', strtolower(trim($platform))] : ['', 'ctrip', 'meituan'];
        foreach (array_unique($platforms) as $platformKey) {
            $cacheKey = $this->autoFetchLightProfileSourcesCacheKey($hotelId, $platformKey);
            unset($this->autoFetchLightReadCache[$cacheKey]);
            cache($cacheKey, null);
        }
    }

    private function listEnabledBrowserProfileDataSources(int $hotelId, string $platform = ''): array
    {
        $cacheKey = $this->autoFetchLightProfileSourcesCacheKey($hotelId, $platform);
        $cached = $this->readAutoFetchLightReadCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $query = Db::name('platform_data_sources')
                ->field('id,tenant_id,name,system_hotel_id,platform,data_type,ingestion_method,config_json,enabled,status,last_sync_status,last_error')
                ->where('enabled', 1)
                ->where('status', '<>', 'disabled')
                ->where('system_hotel_id', $hotelId)
                ->whereIn('ingestion_method', ['browser_profile', 'profile_browser']);
            if ($platform !== '') {
                $query->where('platform', $platform);
            }
            $rows = $query->order('id', 'desc')->select()->toArray();
            $safeRows = $this->sanitizeBrowserProfileSourcesForSharedCache($rows);
            return $this->writeAutoFetchLightReadCache($cacheKey, $safeRows);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function listEnabledCtripBrowserProfileDataSources(int $hotelId): array
    {
        return $this->listEnabledBrowserProfileDataSources($hotelId, 'ctrip');
    }

    private function listCollectableBrowserProfileDataSources(int $hotelId, string $platform = ''): array
    {
        return $this->filterCollectableBrowserProfileDataSources(
            $this->listEnabledBrowserProfileDataSources($hotelId, $platform),
            $platform
        );
    }

    private function listCollectableCtripBrowserProfileDataSources(int $hotelId): array
    {
        return $this->listCollectableBrowserProfileDataSources($hotelId, 'ctrip');
    }

    private function filterCollectableBrowserProfileDataSources(array $sources, string $platform = ''): array
    {
        $platform = strtolower(trim($platform));
        $proofService = new OtaProfileSessionProofService();
        $verified = [];
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            if ((int)($source['enabled'] ?? 1) !== 1) {
                continue;
            }
            if ($platform !== '' && strtolower(trim((string)($source['platform'] ?? ''))) !== $platform) {
                continue;
            }
            if (!in_array(strtolower(trim((string)($source['ingestion_method'] ?? ''))), ['browser_profile', 'profile_browser'], true)) {
                continue;
            }
            $status = strtolower(trim((string)($source['status'] ?? '')));
            if (!in_array($status, ['ready', 'success', 'partial_success'], true)) {
                continue;
            }
            if (empty($proofService->profileReuseState($source)['is_reusable'])) {
                continue;
            }
            $verified[] = $source;
        }
        return $verified;
    }

    private function ctripBrowserProfileSourcesHaveProfile(array $sources, int $hotelId): bool
    {
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            if ($this->ctripProfileExistsForConfig($this->decodeBrowserProfileSourceConfig($source), $hotelId)) {
                return true;
            }
        }

        return false;
    }

    private function decodeBrowserProfileSourceConfig(array $source): array
    {
        if (is_array($source['config'] ?? null)) {
            return $source['config'];
        }

        $config = json_decode((string)($source['config_json'] ?? ''), true);
        return is_array($config) ? $config : [];
    }

    private function applyPlatformProfileLoginDataSourceRequest(string $platform, array $requestData): array
    {
        $sourceId = (int)($requestData['data_source_id'] ?? $requestData['source_id'] ?? 0);
        if ($sourceId <= 0) {
            return $requestData;
        }

        $source = Db::name('platform_data_sources')
            ->field('id,name,system_hotel_id,platform,data_type,ingestion_method,config_json,enabled,status')
            ->where('id', $sourceId)
            ->find();
        if (!$source || !is_array($source)) {
            throw new \RuntimeException('未找到平台 Profile 数据源，请先检查数据源配置');
        }
        $safeSources = $this->sanitizeBrowserProfileSourcesForSharedCache([$source]);
        $source = $safeSources[0] ?? [];
        if (($source['migration_required'] ?? false) === true) {
            throw new \RuntimeException('平台 Profile 数据源仍含遗留密钥字段，请先完成凭据迁移');
        }

        return $this->buildPlatformProfileLoginRequestFromDataSource($platform, $requestData, $source);
    }

    private function buildPlatformProfileLoginRequestFromDataSource(string $platform, array $requestData, array $source): array
    {
        $platform = strtolower(trim($platform));
        $sourcePlatform = strtolower(trim((string)($source['platform'] ?? '')));
        if ($sourcePlatform !== $platform) {
            throw new \RuntimeException('平台 Profile 数据源与当前登录平台不匹配');
        }

        $method = strtolower(trim((string)($source['ingestion_method'] ?? '')));
        if (!in_array($method, ['browser_profile', 'profile_browser'], true)) {
            throw new \RuntimeException('该数据源不是浏览器 Profile 采集入口');
        }
        if ((int)($source['enabled'] ?? 1) !== 1 || strtolower(trim((string)($source['status'] ?? ''))) === 'disabled') {
            throw new \RuntimeException('该平台 Profile 数据源已停用');
        }

        $sourceHotelId = (int)($source['system_hotel_id'] ?? 0);
        if ($sourceHotelId <= 0) {
            throw new \RuntimeException('平台 Profile 数据源缺少系统酒店绑定');
        }
        $requestedHotelId = (int)($requestData['system_hotel_id'] ?? $requestData['systemHotelId'] ?? $requestData['hotel_id'] ?? $requestData['hotelId'] ?? 0);
        if ($requestedHotelId > 0 && $requestedHotelId !== $sourceHotelId) {
            throw new \RuntimeException('平台 Profile 数据源与当前酒店不匹配');
        }
        if ($this->currentUser
            && method_exists($this->currentUser, 'isSuperAdmin')
            && !$this->currentUser->isSuperAdmin()
            && (!method_exists($this->currentUser, 'hasHotelPermission')
                || !$this->currentUser->hasHotelPermission($sourceHotelId, 'can_fetch_online_data'))
        ) {
            throw new \RuntimeException('无权触发该门店的平台登录');
        }

        $config = $this->decodeBrowserProfileSourceConfig($source);
        $merged = $requestData;
        $merged['data_source_id'] = (int)($source['id'] ?? 0);
        $merged['source_id'] = (int)($source['id'] ?? 0);
        $merged['system_hotel_id'] = $sourceHotelId;
        $merged['bind_data_source'] = $requestData['bind_data_source'] ?? $requestData['bindDataSource'] ?? true;
        $merged['capture_sections'] = $this->platformProfileLoginSourceCaptureSections($platform, $requestData, $config, (string)($source['data_type'] ?? ''));

        if ($platform === 'ctrip') {
            $profileId = $this->platformProfileLoginFirstString($config, ['profile_id', 'profileId', 'browser_profile_id', 'browserProfileId']);
            $hotelId = $this->platformProfileLoginFirstString($config, ['hotel_id', 'hotelId', 'ctrip_hotel_id', 'ctripHotelId', 'ota_hotel_id', 'otaHotelId', 'external_hotel_id']);
            if ($profileId === '') {
                $profileId = $hotelId !== '' ? $hotelId : 'system_' . $sourceHotelId;
            }
            $merged['profile_id'] = $profileId;
            $merged['hotel_id'] = $hotelId;
            if ($hotelId !== '') {
                $merged['ctrip_hotel_id'] = $hotelId;
            }
            $merged['hotel_name'] = $this->platformProfileLoginFirstString($config, ['hotel_name', 'hotelName', 'name'], (string)($source['name'] ?? ''));
            return $merged;
        }

        $storeId = $this->platformProfileLoginFirstString($config, ['store_id', 'storeId', 'poi_id', 'poiId']);
        if ($storeId === '') {
            throw new \RuntimeException('美团 Profile 数据源缺少 Store ID / POI ID');
        }
        $poiId = $this->platformProfileLoginFirstString($config, ['poi_id', 'poiId'], $storeId);
        $merged['store_id'] = $storeId;
        $merged['poi_id'] = $poiId;
        $merged['poi_name'] = $this->platformProfileLoginFirstString($config, ['poi_name', 'poiName', 'hotel_name', 'hotelName', 'name'], (string)($source['name'] ?? ''));
        $partnerId = $this->platformProfileLoginFirstString($config, ['partner_id', 'partnerId']);
        if ($partnerId !== '') {
            $merged['partner_id'] = $partnerId;
        }
        return $merged;
    }

    private function platformProfileLoginFirstString(array $data, array $keys, string $default = ''): string
    {
        foreach ($keys as $key) {
            $value = trim((string)($data[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return $default;
    }

    private function platformProfileLoginSourceCaptureSections(string $platform, array $requestData, array $config, string $dataType): string
    {
        $value = $requestData['capture_sections']
            ?? $requestData['captureSections']
            ?? $requestData['sections']
            ?? $config['capture_sections']
            ?? $config['captureSections']
            ?? $config['sections']
            ?? $config['profile_sections']
            ?? null;
        if (is_array($value)) {
            $sections = array_values(array_filter(array_map(static fn($item): string => trim((string)$item), $value)));
            if ($sections !== []) {
                return implode(',', $sections);
            }
        } elseif ($value !== null && trim((string)$value) !== '') {
            return trim((string)$value);
        }

        $dataType = strtolower(trim($dataType));
        if (in_array($dataType, ['traffic', 'flow', 'conversion'], true)) {
            return 'traffic';
        }

        return $platform === 'meituan' ? 'traffic,orders' : 'default';
    }

    private function resolveExistingCtripBrowserProfileKey(int $hotelId): string
    {
        foreach ($this->listEnabledCtripBrowserProfileDataSources($hotelId) as $source) {
            if (!is_array($source)) {
                continue;
            }
            $candidate = $this->ctripProfileStoreIdFromConfig($this->decodeBrowserProfileSourceConfig($source), $hotelId);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    private function resolvePlatformProfileLoginProfileKey(string $platform, array $requestData, int $hotelId): string
    {
        if ($platform === 'ctrip') {
            $profileKey = trim((string)(
                $requestData['profile_id']
                ?? $requestData['profileId']
                ?? $requestData['hotel_id']
                ?? $requestData['hotelId']
                ?? $requestData['ctrip_hotel_id']
                ?? $requestData['ctripHotelId']
                ?? ''
            ));
            $existingProfileKey = $this->resolveExistingCtripBrowserProfileKey($hotelId);
            if ($profileKey !== '' && $profileKey === (string)$hotelId && $existingProfileKey !== '' && $existingProfileKey !== $profileKey) {
                return $existingProfileKey;
            }
            if ($profileKey !== '') {
                return $profileKey;
            }

            return $existingProfileKey !== '' ? $existingProfileKey : 'system_' . $hotelId;
        }

        return trim((string)(
            $requestData['store_id']
            ?? $requestData['storeId']
            ?? $requestData['poi_id']
            ?? $requestData['poiId']
            ?? ''
        ));
    }

    private function preparePlatformProfileLoginRequest(string $platform, array $requestData, int $hotelId, string $profileKey): array
    {
        [, $requestSecrets] = $this->splitOtaConfigSecrets($requestData);
        if ($requestSecrets !== []) {
            throw new \RuntimeException('平台 Profile 登录任务只接受元数据，不接受 Cookie、token 或 Authorization 等凭据内容');
        }

        $prepared = [
            'platform' => $platform,
            'system_hotel_id' => $hotelId,
            'profile_key' => $profileKey,
            'bind_data_source' => $this->platformProfileLoginRequestFlag($requestData['bind_data_source'] ?? $requestData['bindDataSource'] ?? true),
            'sync_after_login' => $this->platformProfileLoginRequestFlag(
                $requestData['sync_after_login']
                ?? $requestData['syncAfterLogin']
                ?? $requestData['after_login_sync']
                ?? $requestData['afterLoginSync']
                ?? false
            ),
            'timeout_seconds' => max(60, min(900, (int)($requestData['timeout_seconds'] ?? 600))),
            'login_timeout_ms' => max(30000, min(600000, (int)($requestData['login_timeout_ms'] ?? 300000))),
            'post_login_wait_ms' => max(0, min(600000, (int)($requestData['post_login_wait_ms'] ?? $requestData['postLoginWaitMs'] ?? 5000))),
            'capture_sections' => $this->platformProfileLoginSourceCaptureSections($platform, $requestData, [], ''),
        ];
        if ($this->platformProfileLoginRequestFlag($requestData['allow_existing_local_profile_rebind'] ?? false)) {
            $prepared['allow_existing_local_profile_rebind'] = true;
        }
        $sourceId = (int)($requestData['data_source_id'] ?? $requestData['source_id'] ?? 0);
        if ($sourceId > 0) {
            $prepared['data_source_id'] = $sourceId;
        }
        foreach (['data_date', 'dataDate', 'target_date', 'targetDate', 'date'] as $dateKey) {
            $date = trim((string)($requestData[$dateKey] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1) {
                $prepared['data_date'] = $date;
                break;
            }
        }
        $dataPeriod = strtolower(trim((string)($requestData['data_period'] ?? $requestData['dataPeriod'] ?? '')));
        if ($dataPeriod !== '' && preg_match('/^[a-z0-9_-]{1,40}$/D', $dataPeriod) === 1) {
            $prepared['data_period'] = $dataPeriod;
        }
        $snapshotTime = trim((string)($requestData['snapshot_time'] ?? $requestData['snapshotTime'] ?? ''));
        if ($snapshotTime !== '' && preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}:\d{2})?$/D', $snapshotTime) === 1) {
            $prepared['snapshot_time'] = $snapshotTime;
        }

        if ($platform === 'ctrip') {
            $prepared['profile_id'] = $this->platformProfileLoginFirstString($requestData, ['profile_id', 'profileId'], $profileKey);
            $prepared['hotel_id'] = $this->platformProfileLoginFirstString($requestData, ['hotel_id', 'hotelId', 'ctrip_hotel_id', 'ctripHotelId']);
            $prepared['hotel_name'] = mb_substr($this->platformProfileLoginFirstString($requestData, ['hotel_name', 'hotelName']), 0, 120);
        } else {
            $prepared['store_id'] = $this->platformProfileLoginFirstString($requestData, ['store_id', 'storeId'], $profileKey);
            $prepared['poi_id'] = $this->platformProfileLoginFirstString($requestData, ['poi_id', 'poiId'], $prepared['store_id']);
            $prepared['poi_name'] = mb_substr($this->platformProfileLoginFirstString($requestData, ['poi_name', 'poiName']), 0, 120);
            $partnerId = $this->platformProfileLoginFirstString($requestData, ['partner_id', 'partnerId']);
            if ($partnerId !== '') {
                $prepared['partner_id'] = $partnerId;
            }
            $adsUrl = $this->platformProfileLoginFirstString($requestData, ['ads_url', 'adsUrl']);
            if ($adsUrl !== '') {
                $prepared['ads_url'] = $adsUrl;
            }
        }

        $this->assertPlatformProfileLoginRequestMetadataSafe($prepared);
        return $prepared;
    }

    private function platformProfileLoginRequestFlag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int)$value === 1;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function assertPlatformProfileLoginRequestMetadataSafe(array $requestData): void
    {
        $encoded = json_encode($requestData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (
            $this->otaConfigStringContainsCredentialMaterial($encoded)
            || preg_match('/\b(?:sid|session|sessionid|jsessionid)\s*=\s*[^;\s,]+/i', $encoded) === 1
        ) {
            throw new \RuntimeException('平台 Profile 登录任务元数据包含疑似凭据内容，已拒绝写入任务文件');
        }
    }

    private function createPlatformProfileLoginTask(string $platform, int $hotelId, string $profileKey, array $requestData): array
    {
        $requestData = $this->preparePlatformProfileLoginRequest($platform, $requestData, $hotelId, $profileKey);
        $projectRoot = dirname(__DIR__, 3);
        $dir = $projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'platform_profile_login';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('无法创建平台登录任务目录');
        }

        try {
            $suffix = bin2hex(random_bytes(4));
        } catch (\Throwable $e) {
            $suffix = str_replace('.', '', uniqid('', true));
        }

        $safeProfileKey = BrowserProfileCaptureRequestService::safeFilePart($profileKey);
        $taskId = $platform . '_' . $safeProfileKey . '_' . date('YmdHis') . '_' . $suffix;
        $inputPath = $dir . DIRECTORY_SEPARATOR . $taskId . '.input.json';
        $outputPath = $dir . DIRECTORY_SEPARATOR . $taskId . '.json';
        $logPath = $dir . DIRECTORY_SEPARATOR . $taskId . '.log';
        $now = date('Y-m-d H:i:s');
        $task = [
            'task_id' => $taskId,
            'platform' => $platform,
            'platform_name' => $platform === 'ctrip' ? '携程' : '美团',
            'system_hotel_id' => $hotelId,
            'profile_key' => $profileKey,
            'profile_dir' => $this->platformProfileLoginProfileDir($platform, $profileKey),
            'status' => 'queued',
            'message' => '登录任务已提交，正在打开浏览器',
            'created_at' => $now,
            'updated_at' => $now,
            'started_at' => $now,
            'output' => $outputPath,
            'log' => $logPath,
            'input' => $inputPath,
            'current_key' => $this->platformProfileLoginCurrentCacheKey($platform, $hotelId, $profileKey),
        ];

        $inputPayload = [
            'task' => $task,
            'request' => $requestData,
        ];
        $inputJson = json_encode(
            $inputPayload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        );
        if (file_put_contents($inputPath, $inputJson, LOCK_EX) === false) {
            throw new \RuntimeException('无法写入平台登录任务输入');
        }
        if (!@chmod($inputPath, 0600)) {
            @unlink($inputPath);
            throw new \RuntimeException('无法限制平台登录任务输入文件权限');
        }

        $this->cachePlatformProfileLoginTask($task);
        return $task;
    }

    private function launchPlatformProfileLoginTask(array $task): bool
    {
        $projectRoot = dirname(__DIR__, 3);
        $phpBinary = $this->resolvePhpCliBinary();
        $thinkPath = $projectRoot . DIRECTORY_SEPARATOR . 'think';
        $inputPath = (string)($task['input'] ?? '');
        if ($phpBinary === '' || !is_file($thinkPath) || !is_file($inputPath)) {
            return false;
        }

        $dir = dirname($inputPath);
        $launcherLog = $dir . DIRECTORY_SEPARATOR . 'launcher.log';
        $taskId = (string)$task['task_id'];

        if (DIRECTORY_SEPARATOR === '\\') {
            $batPath = $dir . DIRECTORY_SEPARATOR . $taskId . '.bat';
            $inputFile = basename($inputPath);
            $lines = [
                '@echo off',
                'setlocal',
                'set "TASK_DIR=%~dp0"',
                'pushd "%TASK_DIR%..\.." || exit /b 1',
                $this->quoteWindowsBatchArg($phpBinary)
                    . ' "%CD%\think"'
                    . ' "online-data:profile-login"'
                    . ' "--task-id=' . $taskId . '"'
                    . ' "--input=%TASK_DIR%' . $inputFile . '"'
                    . ' >> "%TASK_DIR%launcher.log" 2>&1',
                'set "EXIT_CODE=%ERRORLEVEL%"',
                'popd',
                'exit /b %EXIT_CODE%',
            ];
            if (file_put_contents($batPath, implode(PHP_EOL, $lines) . PHP_EOL) === false) {
                return false;
            }
            if (!$this->launchWindowsBatchFile($batPath)) {
                return false;
            }
            if (!$this->waitForPlatformProfileLoginTaskStart($taskId)) {
                $this->appendWindowsLauncherDiagnostic($batPath, 'Profile login process did not leave queued state after launch.');
                return false;
            }
            return true;
        }

        $shellPath = $dir . DIRECTORY_SEPARATOR . $taskId . '.sh';
        $command = 'cd ' . escapeshellarg($projectRoot)
            . ' && ' . escapeshellarg($phpBinary)
            . ' ' . escapeshellarg($thinkPath)
            . ' online-data:profile-login'
            . ' --task-id=' . escapeshellarg($taskId)
            . ' --input=' . escapeshellarg($inputPath)
            . ' >> ' . escapeshellarg($launcherLog) . ' 2>&1';
        if (file_put_contents($shellPath, "#!/bin/sh\n" . $command . "\n") === false) {
            return false;
        }
        @chmod($shellPath, 0755);
        $handle = @popen('sh ' . escapeshellarg($shellPath) . ' >/dev/null 2>&1 &', 'r');
        if (!is_resource($handle)) {
            return false;
        }
        pclose($handle);
        return true;
    }

    private function resolvePhpCliBinary(): string
    {
        $configured = trim((string)(getenv('PHP_CLI_BINARY') ?: env('PHP_CLI_BINARY', '')));
        $candidates = array_filter([
            $configured,
            'C:\\xampp\\php\\php.exe',
            PHP_BINARY,
            'php',
        ]);

        foreach ($candidates as $candidate) {
            if ($candidate === 'php' || is_file($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    private function quoteWindowsBatchArg(string $value): string
    {
        return '"' . str_replace('"', '""', $value) . '"';
    }

    private function launchWindowsBatchFile(string $batPath): bool
    {
        if ($this->launchWindowsBatchFileWithPowerShell($batPath)) {
            return true;
        }
        $this->appendWindowsLauncherDiagnostic($batPath, 'PowerShell launcher failed; falling back to wscript.');

        $launcherPath = $this->createWindowsBatchLauncher($batPath);
        if ($launcherPath !== '' && $this->launchWindowsScriptHost($launcherPath)) {
            return true;
        }

        if ($launcherPath !== '' && is_file($launcherPath)) {
            @unlink($launcherPath);
        }
        $this->appendWindowsLauncherDiagnostic($batPath, 'wscript launcher did not confirm execution; falling back to cmd start.');

        return $this->launchWindowsBatchFileWithStart($batPath);
    }

    private function launchWindowsBatchFileWithPowerShell(string $batPath): bool
    {
        $powershell = $this->resolveWindowsPowerShellBinary();
        if ($powershell === '') {
            return false;
        }

        $cmd = (string)(getenv('COMSPEC') ?: 'cmd.exe');
        $arguments = "@('/d', '/c', 'call', " . $this->quotePowerShellSingleQuotedString($batPath) . ')';
        $script = '$process = Start-Process -FilePath '
            . $this->quotePowerShellSingleQuotedString($cmd)
            . ' -ArgumentList '
            . $arguments
            . ' -WorkingDirectory '
            . $this->quotePowerShellSingleQuotedString(dirname($batPath))
            . ' -WindowStyle Hidden -PassThru; if ($null -eq $process) { exit 1 }; exit 0';
        $encoded = $this->encodeWindowsPowerShellCommand($script);
        if ($encoded === '') {
            return false;
        }

        $command = $this->quoteWindowsBatchArg($powershell)
            . ' -NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand '
            . $encoded
            . ' 2>&1';
        $handle = @popen($command, 'r');
        if (!is_resource($handle)) {
            return false;
        }
        stream_get_contents($handle);
        return pclose($handle) === 0;
    }

    private function resolveWindowsPowerShellBinary(): string
    {
        $systemRoot = rtrim((string)(getenv('SystemRoot') ?: 'C:\\Windows'), "\\/");
        $candidates = array_filter([
            $systemRoot !== '' ? $systemRoot . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe' : '',
            'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe',
            'powershell.exe',
        ]);

        foreach ($candidates as $candidate) {
            if ($candidate === 'powershell.exe' || is_file($candidate)) {
                return $candidate;
            }
        }
        return '';
    }

    private function waitForPlatformProfileLoginTaskStart(string $taskId, int $timeoutMs = 5000): bool
    {
        $deadline = microtime(true) + max(500, $timeoutMs) / 1000;
        do {
            $task = $this->readPlatformProfileLoginTask($taskId);
            $status = strtolower(trim((string)($task['status'] ?? '')));
            if ($status !== '' && $status !== 'queued') {
                return true;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        return false;
    }

    private function launchWindowsScriptHost(string $launcherPath): bool
    {
        $wscript = $this->resolveWindowsScriptHost();
        if ($wscript === '') {
            return false;
        }

        $handle = @popen($this->quoteWindowsBatchArg($wscript) . ' //B //Nologo ' . $this->quoteWindowsBatchArg($launcherPath), 'r');
        if (!is_resource($handle)) {
            return false;
        }
        pclose($handle);

        for ($i = 0; $i < 15; $i++) {
            if (!is_file($launcherPath)) {
                return true;
            }
            usleep(100000);
        }

        return false;
    }

    private function launchWindowsBatchFileWithStart(string $batPath): bool
    {
        $cmd = getenv('COMSPEC') ?: 'cmd.exe';
        $command = $this->quoteWindowsBatchArg($cmd)
            . ' /d /c start "" /D '
            . $this->quoteWindowsBatchArg(dirname($batPath))
            . ' '
            . $this->quoteWindowsBatchArg($batPath);

        $handle = @popen($command, 'r');
        if (!is_resource($handle)) {
            $this->appendWindowsLauncherDiagnostic($batPath, 'cmd start launcher failed to start.');
            return false;
        }
        pclose($handle);
        return true;
    }

    private function resolveWindowsScriptHost(): string
    {
        $systemRoot = rtrim((string)(getenv('SystemRoot') ?: 'C:\\Windows'), "\\/");
        $candidates = array_filter([
            $systemRoot !== '' ? $systemRoot . '\\System32\\wscript.exe' : '',
            'C:\\Windows\\System32\\wscript.exe',
            'wscript.exe',
        ]);

        foreach ($candidates as $candidate) {
            if ($candidate === 'wscript.exe' || is_file($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    private function appendWindowsLauncherDiagnostic(string $batPath, string $message): void
    {
        $dir = dirname($batPath);
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        @file_put_contents(
            $dir . DIRECTORY_SEPARATOR . 'launcher.log',
            '[' . date('c') . '] ' . $message . PHP_EOL,
            FILE_APPEND
        );
    }

    private function createWindowsBatchLauncher(string $batPath): string
    {
        $tempDir = rtrim(sys_get_temp_dir(), "\\/");
        if ($tempDir === '' || !is_dir($tempDir) || !is_writable($tempDir)) {
            return '';
        }

        $launcherPath = $tempDir . DIRECTORY_SEPARATOR . 'suxi-bg-launch-' . bin2hex(random_bytes(8)) . '.vbs';
        $command = 'cmd.exe /d /c call "' . $batPath . '"';
        $script = implode("\r\n", [
            'Set sh = CreateObject("WScript.Shell")',
            'sh.Run "' . str_replace('"', '""', $command) . '", 0, False',
            'On Error Resume Next',
            'CreateObject("Scripting.FileSystemObject").DeleteFile WScript.ScriptFullName, True',
            '',
        ]);
        $encoded = $this->encodeUtf16LeWithBom($script);
        if ($encoded === '' || file_put_contents($launcherPath, $encoded) === false) {
            return '';
        }

        return $launcherPath;
    }

    private function encodeUtf16LeWithBom(string $text): string
    {
        if (function_exists('mb_convert_encoding')) {
            return "\xFF\xFE" . (string)mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        }

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-16LE', $text);
            if (is_string($converted)) {
                return "\xFF\xFE" . $converted;
            }
        }

        return '';
    }

    private function quotePowerShellSingleQuotedString(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    private function encodeWindowsPowerShellCommand(string $command): string
    {
        if (function_exists('mb_convert_encoding')) {
            return base64_encode((string)mb_convert_encoding($command, 'UTF-16LE', 'UTF-8'));
        }

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-16LE//IGNORE', $command);
            if (is_string($converted)) {
                return base64_encode($converted);
            }
        }

        return base64_encode($command);
    }

    private function cachePlatformProfileLoginTask(array $task): void
    {
        $task = $this->sanitizePlatformProfileLoginCachePayload($task);
        $taskId = trim((string)($task['task_id'] ?? ''));
        if ($taskId === '') {
            return;
        }
        cache($this->platformProfileLoginTaskCacheKey($taskId), $task, 86400);

        $currentKey = trim((string)($task['current_key'] ?? ''));
        if ($currentKey !== '') {
            cache($currentKey, $task, 86400);
        }

        $platform = strtolower(trim((string)($task['platform'] ?? '')));
        $hotelId = (int)($task['system_hotel_id'] ?? 0);
        if (in_array($platform, ['ctrip', 'meituan'], true) && $hotelId > 0) {
            cache($this->platformProfileLoginHotelCurrentCacheKey($platform, $hotelId), $task, 86400);
        }
    }

    private function readPlatformProfileLoginTask(string $taskId): array
    {
        $task = cache($this->platformProfileLoginTaskCacheKey($taskId));
        return is_array($task) ? $this->sanitizePlatformProfileLoginCachePayload($task) : [];
    }

    private function readPlatformProfileLoginCurrentTask(string $platform, int $hotelId, string $profileKey): array
    {
        $task = cache($this->platformProfileLoginCurrentCacheKey($platform, $hotelId, $profileKey));
        return is_array($task) ? $this->sanitizePlatformProfileLoginCachePayload($task) : [];
    }

    private function readPlatformProfileLoginHotelCurrentTask(string $platform, int $hotelId): array
    {
        $task = cache($this->platformProfileLoginHotelCurrentCacheKey($platform, $hotelId));
        return is_array($task) ? $this->sanitizePlatformProfileLoginCachePayload($task) : [];
    }

    private function platformProfileLoginTaskCacheKey(string $taskId): string
    {
        $safeTaskId = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $taskId) ?: 'default';
        return 'platform_profile_login_task_' . $safeTaskId;
    }

    private function platformProfileLoginCurrentCacheKey(string $platform, int $hotelId, string $profileKey): string
    {
        return 'platform_profile_login_current_' . $platform . '_' . $hotelId . '_' . BrowserProfileCaptureRequestService::safeFilePart($profileKey);
    }

    private function platformProfileLoginHotelCurrentCacheKey(string $platform, int $hotelId): string
    {
        return 'platform_profile_login_hotel_current_' . $platform . '_' . $hotelId;
    }

    private function platformProfileLoginProfileDir(string $platform, string $profileKey): string
    {
        $prefix = $platform === 'ctrip' ? 'ctrip_profile_' : 'meituan_profile_';
        return 'storage/' . $prefix . BrowserProfileCaptureRequestService::safeFilePart($profileKey);
    }

    private function normalizePlatformProfileLoginTask(array $task): array
    {
        $task = $this->sanitizePlatformProfileLoginCachePayload($task);
        unset($task['input'], $task['current_key']);
        $status = (string)($task['status'] ?? 'queued');
        if ($status === 'queued' && $this->isPlatformProfileLoginTaskStale($task, 45)) {
            $status = 'failed';
            $task['status'] = 'failed';
            $task['message'] = '登录任务未真正启动浏览器，请重新触发登录；若仍无窗口，请检查 PHP/Apache 是否运行在可见桌面会话。';
            $task['finished_at'] = $task['finished_at'] ?? date('Y-m-d H:i:s');
        }
        $task['done'] = in_array($status, ['logged_in', 'failed'], true);
        $task['status_text'] = match ($status) {
            'queued' => '登录任务已提交',
            'browser_opened' => '浏览器已打开，自动检测登录中',
            'running' => '正在检测登录状态',
            'syncing_after_login' => '登录已完成，正在同步目标日数据',
            'logged_in' => '登录态已验证',
            'failed' => '登录失败',
            default => '等待处理',
        };
        return $task;
    }

    private function isPlatformProfileLoginTaskStale(array $task, int $seconds): bool
    {
        $timeText = (string)($task['updated_at'] ?? $task['started_at'] ?? $task['created_at'] ?? '');
        $timestamp = $timeText !== '' ? strtotime($timeText) : false;
        return $timestamp !== false && (time() - (int)$timestamp) > $seconds;
    }

    private function buildPlatformProfileStatus(int $hotelId): array
    {
        $ctripSource = $this->firstBrowserProfileSource($hotelId, 'ctrip');
        $meituanSource = $this->firstBrowserProfileSource($hotelId, 'meituan');
        $ctripConfig = $ctripSource ? $this->decodeBrowserProfileSourceConfig($ctripSource) : $this->resolveCtripFetchConfigForHotel($hotelId);
        $meituanConfig = $meituanSource ? $this->decodeBrowserProfileSourceConfig($meituanSource) : $this->resolveMeituanFetchConfigForHotel($hotelId);

        $items = [
            $this->buildPlatformProfileStatusItem('ctrip', $hotelId, $ctripConfig, $ctripSource),
            $this->buildPlatformProfileStatusItem('meituan', $hotelId, $meituanConfig, $meituanSource),
        ];
        $readyToCollect = count(array_filter($items, static fn(array $item): bool => !empty($item['p0_readiness']['is_ready'])));
        $needsIdentityCheck = count(array_filter($items, static function (array $item): bool {
            foreach (($item['binding_checks'] ?? []) as $check) {
                if (($check['key'] ?? '') === 'platform_identity' && ($check['status'] ?? 'missing') !== 'ok') {
                    return true;
                }
            }
            return false;
        }));
        $identityBlocked = count(array_filter($items, static fn(array $item): bool => ($item['p0_readiness']['status'] ?? '') === 'blocked'));
        $loginTasks = [];
        foreach (['ctrip', 'meituan'] as $platform) {
            $task = $this->readPlatformProfileLoginHotelCurrentTask($platform, $hotelId);
            if ($task !== []) {
                $loginTasks[$platform] = $this->normalizePlatformProfileLoginTask($task);
            }
        }

        return [
            'system_hotel_id' => $hotelId,
            'items' => $items,
            'login_tasks' => $loginTasks,
            'summary' => [
                'configured' => count(array_filter($items, static fn(array $item): bool => $item['status_code'] !== 'unconfigured')),
                'logged_in' => count(array_filter($items, static fn(array $item): bool => $item['status_code'] === 'logged_in')),
                'reusable' => count(array_filter($items, static fn(array $item): bool => in_array($item['status_code'], ['logged_in', 'profile_reusable', 'renewal_warning'], true))),
                'renewal_warning' => count(array_filter($items, static fn(array $item): bool => $item['status_code'] === 'renewal_warning')),
                'needs_login' => count(array_filter($items, static fn(array $item): bool => in_array($item['status_code'], ['waiting_login', 'login_expired'], true))),
                'ready_to_collect' => $readyToCollect,
                'needs_identity_check' => $needsIdentityCheck,
                'identity_blocked' => $identityBlocked,
            ],
        ];
    }

    private function firstBrowserProfileSource(int $hotelId, string $platform): ?array
    {
        $sources = $this->listEnabledBrowserProfileDataSources($hotelId, $platform);
        return $sources[0] ?? null;
    }

    private function buildPlatformProfileStatusItem(string $platform, int $hotelId, array $config, ?array $source): array
    {
        $isCtrip = $platform === 'ctrip';
        $profileKey = $isCtrip
            ? $this->ctripProfileStoreIdFromConfig($config, $hotelId)
            : $this->meituanProfileStoreIdFromConfig($config);
        $safeKey = $profileKey !== '' ? BrowserProfileCaptureRequestService::safeFilePart($profileKey) : '';
        $profilePrefix = $isCtrip ? 'ctrip_profile_' : 'meituan_profile_';
        $relativeDir = $safeKey !== '' ? 'storage/' . $profilePrefix . $safeKey : '';
        $projectRoot = dirname(__DIR__, 3);
        $profileDir = $relativeDir !== '' ? $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir) : '';
        $exists = $profileDir !== '' && is_dir($profileDir);
        $scopeBindingStatus = 'missing';
        if ($profileKey !== '') {
            try {
                (new OtaProfileBindingService())->assertBound($hotelId, $platform, $profileKey);
                $scopeBindingStatus = 'active';
            } catch (\RuntimeException) {
                $scopeBindingStatus = 'blocked';
                $exists = false;
            }
        }
        $cache = $profileKey !== '' ? $this->readPlatformProfileStatusCache($platform, $hotelId, $profileKey) : [];
        $statusCode = $this->resolvePlatformProfileStatusCode($profileKey, $exists, $source, $cache, $config);
        if ($scopeBindingStatus === 'blocked') {
            $statusCode = 'binding_blocked';
        }
        $bindingContract = PlatformProfileBindingReadinessService::buildContract($platform, $hotelId, $config, $source, $statusCode, $exists, $profileKey);
        $bindingContract['scope_binding_status'] = $scopeBindingStatus;
        $bindingChecks = $this->buildPlatformProfileBindingChecks($platform, $hotelId, $config, $source, $statusCode, $exists, $profileKey);
        array_unshift($bindingChecks, [
            'key' => 'profile_scope_binding',
            'label' => 'Profile tenant/hotel binding',
            'status' => $scopeBindingStatus === 'active' ? 'ok' : ($profileKey === '' ? 'missing' : 'error'),
            'detail' => $scopeBindingStatus === 'active'
                ? 'Authoritative Profile scope binding is active.'
                : 'Authoritative Profile scope binding is missing or mismatched.',
            'next_action' => 'Resolve the local Profile scope binding before login probes or collection.',
            'action_key' => 'resolve_profile_scope_binding',
            'action_label' => 'Resolve Profile binding',
            'action_target' => '',
        ]);
        $p0Readiness = $this->summarizePlatformProfileBindingChecks($bindingChecks);
        $primaryAction = $this->firstPlatformProfileBindingAction($bindingChecks);

        return [
            'platform' => $platform,
            'platform_name' => $isCtrip ? '携程' : '美团',
            'system_hotel_id' => $hotelId,
            'data_source_id' => isset($source['id']) ? (int)$source['id'] : null,
            'data_source_name' => (string)($source['name'] ?? ''),
            'binding' => $isCtrip
                ? [
                    'profile_id' => $profileKey,
                    'hotel_id' => (string)($config['ota_hotel_id'] ?? $config['ctrip_hotel_id'] ?? $config['ctripHotelId'] ?? ''),
                    'node_id' => (string)($config['node_id'] ?? $config['nodeId'] ?? ''),
                    'hotel_name' => (string)($config['hotel_name'] ?? $config['hotelName'] ?? $config['name'] ?? ''),
                ]
                : [
                    'store_id' => $profileKey,
                    'poi_id' => (string)($config['poi_id'] ?? $config['poiId'] ?? ''),
                    'partner_id_configured' => trim((string)($config['partner_id'] ?? $config['partnerId'] ?? '')) !== '',
                ],
            'binding_contract' => $bindingContract,
            'binding_checks' => $bindingChecks,
            'binding_check_status' => $p0Readiness['status'],
            'p0_readiness' => $p0Readiness,
            'profile_key' => $profileKey,
            'profile_dir' => $relativeDir,
            'profile_exists' => $exists,
            'last_login_check_time' => (string)($cache['checked_at'] ?? ''),
            'last_capture_time' => (string)($source['last_sync_time'] ?? $source['update_time'] ?? ''),
            'current_status' => $this->platformProfileStatusText($statusCode),
            'status_code' => $statusCode,
            'auth_status' => $cache['auth_status'] ?? null,
            'primary_action' => $primaryAction,
            'next_action' => (string)($primaryAction['next_action'] ?? '') ?: $this->platformProfileNextAction($statusCode, $platform),
        ];
    }

    private function buildPlatformProfileBindingChecks(string $platform, int $hotelId, array $config, ?array $source, string $statusCode, bool $profileExists, string $profileKey): array
    {
        return PlatformProfileBindingReadinessService::buildChecks($platform, $hotelId, $config, $source, $statusCode, $profileExists, $profileKey);
    }
    private function buildPlatformProfileBindingCheck(
        string $key,
        string $label,
        string $status,
        string $detail,
        string $nextAction,
        string $actionKey,
        string $actionLabel,
        string $actionTarget
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'detail' => $detail,
            'next_action' => $nextAction,
            'action_key' => $actionKey,
            'action_label' => $actionLabel,
            'action_target' => $actionTarget,
        ];
    }

    private function firstPlatformProfileBindingAction(array $checks): array
    {
        foreach (['error', 'missing', 'warning'] as $status) {
            foreach ($checks as $check) {
                if (($check['status'] ?? '') !== $status) {
                    continue;
                }
                return [
                    'check_key' => (string)($check['key'] ?? ''),
                    'status' => $status,
                    'next_action' => (string)($check['next_action'] ?? ''),
                    'action_key' => (string)($check['action_key'] ?? ''),
                    'action_label' => (string)($check['action_label'] ?? ''),
                    'action_target' => (string)($check['action_target'] ?? ''),
                ];
            }
        }

        return [
            'check_key' => '',
            'status' => 'ok',
            'next_action' => '',
            'action_key' => '',
            'action_label' => '',
            'action_target' => '',
        ];
    }

    private function summarizePlatformProfileBindingChecks(array $checks): array
    {
        $counts = ['ok' => 0, 'warning' => 0, 'missing' => 0, 'error' => 0];
        foreach ($checks as $check) {
            $status = (string)($check['status'] ?? 'missing');
            if (!array_key_exists($status, $counts)) {
                $status = 'missing';
            }
            $counts[$status]++;
        }

        $status = 'ok';
        $label = 'P0就绪';
        if ($counts['error'] > 0) {
            $status = 'blocked';
            $label = 'P0阻塞';
        } elseif ($counts['missing'] > 0) {
            $status = 'missing';
            $label = 'P0待补';
        } elseif ($counts['warning'] > 0) {
            $status = 'attention';
            $label = 'P0待确认';
        }

        return [
            'status' => $status,
            'label' => $label,
            'is_ready' => $status === 'ok',
            'ok_count' => $counts['ok'],
            'warning_count' => $counts['warning'],
            'missing_count' => $counts['missing'],
            'error_count' => $counts['error'],
        ];
    }

    private function resolvePlatformProfileStatusCode(string $profileKey, bool $profileExists, ?array $source, array $cache, array $config = []): string
    {
        if ($profileKey === '' && empty($source)) {
            return 'unconfigured';
        }
        if (!$profileExists) {
            return 'waiting_login';
        }
        if ($this->platformProfileSourceHasHotelMismatchError($source)) {
            return 'hotel_mismatch';
        }
        if ($this->platformProfileSourceHasPermissionError($source)) {
            return 'permission_denied';
        }
        if ($this->platformProfileSourceHasAntiBotError($source)) {
            return 'anti_bot';
        }
        if ($this->platformProfileSourceHasSessionExpiredError($source)) {
            return 'session_expired';
        }
        if ($this->platformProfileSourceHasLoginExpiredError($source)) {
            return 'login_expired';
        }
        if (in_array((string)($cache['status_code'] ?? ''), ['session_expired', 'login_expired', 'login_required'], true)) {
            return (string)($cache['status_code'] ?? '') === 'session_expired' ? 'session_expired' : 'login_expired';
        }
        if (in_array((string)($source['last_sync_status'] ?? ''), ['failed', 'partial_success'], true)) {
            return 'capture_failed';
        }
        if (is_array($source)) {
            $proofService = new OtaProfileSessionProofService();
            if ($proofService->isCurrentVerified($source)) {
                return 'logged_in';
            }
            $reuseState = $proofService->profileReuseState($source);
            if (($reuseState['status'] ?? '') === 'renewal_warning') {
                return 'renewal_warning';
            }
            if (($reuseState['status'] ?? '') === 'reusable') {
                return 'profile_reusable';
            }
            if (($reuseState['status'] ?? '') === 'expired') {
                return 'login_expired';
            }
            return 'waiting_login';
        }
        return 'waiting_login';
    }

    private function platformProfileSourceHasLoginExpiredError(?array $source): bool
    {
        if (empty($source)) {
            return false;
        }

        $syncStatus = strtolower(trim((string)($source['last_sync_status'] ?? $source['status'] ?? '')));
        $message = trim((string)($source['last_error'] ?? $source['message'] ?? ''));
        if ($message === '') {
            return false;
        }

        if (in_array($syncStatus, ['session_expired', 'login_expired', 'auth_failed'], true)) {
            return true;
        }

        if (!in_array($syncStatus, ['failed', 'partial_success'], true)) {
            return false;
        }

        return preg_match('/browser_profile\s*需重新登录|需重新登录|重新登录|login\s*timeout|login_required|login_expired|login\s*expired|login\s*page|unauthorized|forbidden|401|403|未登录|登录失效|登录过期|授权失效/i', $message) === 1;
    }

    private function platformProfileSourceHasPermissionError(?array $source): bool
    {
        if (empty($source)) {
            return false;
        }

        $syncStatus = strtolower(trim((string)($source['last_sync_status'] ?? $source['status'] ?? '')));
        $message = strtolower(trim((string)($source['last_error'] ?? $source['message'] ?? '')));
        if (in_array($syncStatus, ['permission_denied', 'no_permission', 'forbidden'], true)) {
            return true;
        }
        return $message !== '' && preg_match('/permission_denied|no_permission|forbidden|http\s*403|status\s*403|access\s*denied|not\s*authorized|unauthorized_hotel|无权|无权限|权限不足/i', $message) === 1;
    }

    private function platformProfileSourceHasAntiBotError(?array $source): bool
    {
        if (empty($source)) {
            return false;
        }

        $syncStatus = strtolower(trim((string)($source['last_sync_status'] ?? $source['status'] ?? '')));
        $message = strtolower(trim((string)($source['last_error'] ?? $source['message'] ?? '')));
        if (in_array($syncStatus, ['anti_bot', 'manual_intervention_required'], true)) {
            return true;
        }
        return $message !== '' && preg_match('/anti[_-]?bot|captcha|verification_code|sms_code|required verification|slider|human verification|yoda|risk control|platform limit|rate limit|验证码|短信|人机|滑块|风控/i', $message) === 1;
    }

    private function platformProfileSourceHasSessionExpiredError(?array $source): bool
    {
        if (empty($source)) {
            return false;
        }

        $syncStatus = strtolower(trim((string)($source['last_sync_status'] ?? $source['status'] ?? '')));
        $message = strtolower(trim((string)($source['last_error'] ?? $source['message'] ?? '')));
        return $syncStatus === 'session_expired'
            || ($message !== '' && preg_match('/session_expired|session expired|session invalid|expired session/i', $message) === 1);
    }

    private function platformProfileSourceHasHotelMismatchError(?array $source): bool
    {
        if (empty($source)) {
            return false;
        }

        $message = strtolower(trim((string)($source['last_error'] ?? $source['message'] ?? '')));
        return $message !== '' && preg_match('/hotel_mismatch|store_mismatch|poi_mismatch|source hotel scope mismatch|data source hotel scope mismatch|hotel scope mismatch|门店不匹配|酒店不匹配|门店.*不匹配/i', $message) === 1;
    }

    private function platformProfileStatusText(string $statusCode): string
    {
        return match ($statusCode) {
            'logged_in' => '登录态已验证',
            'profile_reusable' => 'Profile 可尝试采集',
            'renewal_warning' => 'Profile 登录态可用，建议续登',
            'session_expired' => 'session_expired',
            'login_expired' => '登录失效',
            'anti_bot' => 'anti_bot',
            'capture_failed' => '采集失败',
            'waiting_login' => '登录待验证',
            default => '未配置',
        };
    }

    private function platformProfileNextAction(string $statusCode, string $platform): string
    {
        $name = $platform === 'meituan' ? '美团' : '携程';
        return match ($statusCode) {
            'logged_in' => '登录态已验证；执行目标日同步并检查入库结果',
            'profile_reusable' => '直接执行采集；仅在平台实际返回登录失效时重新登录',
            'renewal_warning' => '直接执行采集；建议有空时续登，但不阻塞采集',
            'session_expired' => 'session_expired',
            'login_expired' => '重新登录' . $name . '平台账号',
            'anti_bot' => 'anti_bot',
            'capture_failed' => '查看最近同步日志后重新检测登录状态',
            'waiting_login' => '点击“登录' . $name . '”完成平台验证',
            default => '先配置酒店与平台账号/Profile 绑定',
        };
    }

    private function platformProfileStatusActionMeta(string $statusCode, string $platform): array
    {
        $name = $platform === 'meituan' ? '美团' : '携程';
        return match ($statusCode) {
            'logged_in' => ['run_profile_capture', '同步并检查入库', 'platform-auto'],
            'profile_reusable', 'renewal_warning' => ['run_profile_capture', '立即采集', 'platform-auto'],
            'session_expired' => ['login_platform_profile', 'session_expired', 'profile-login'],
            'login_expired' => ['login_platform_profile', '重新登录' . $name, 'profile-login'],
            'anti_bot' => ['login_platform_profile', 'anti_bot', 'profile-login'],
            'capture_failed' => ['open_sync_logs', '查看日志并检测登录', 'sync-logs'],
            'waiting_login' => ['login_platform_profile', '登录' . $name, 'profile-login'],
            default => ['configure_platform_profile', '配置账号/Profile', 'platform-sources'],
        };
    }

    private function cachePlatformProfileStatus(string $platform, int $hotelId, string $profileKey, array $status): void
    {
        cache(
            $this->platformProfileStatusCacheKey($platform, $hotelId, $profileKey),
            $this->sanitizePlatformProfileLoginCachePayload($status),
            86400 * 30
        );
    }

    private function clearPlatformProfileStatusCache(string $platform, int $hotelId, string $profileKey): void
    {
        if ($hotelId <= 0 || trim($profileKey) === '') {
            return;
        }

        cache($this->platformProfileStatusCacheKey($platform, $hotelId, $profileKey), null);
    }

    private function clearBrowserProfileStatusCacheForSource(array $source): void
    {
        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        if (!in_array($platform, ['ctrip', 'meituan'], true)) {
            return;
        }
        if (strtolower(trim((string)($source['ingestion_method'] ?? ''))) !== 'browser_profile') {
            return;
        }

        $hotelId = (int)($source['system_hotel_id'] ?? 0);
        $config = $this->decodeBrowserProfileSourceConfig($source);
        $profileKey = $platform === 'ctrip'
            ? $this->ctripProfileStoreIdFromConfig($config, $hotelId)
            : $this->meituanProfileStoreIdFromConfig($config);

        $this->clearPlatformProfileStatusCache($platform, $hotelId, $profileKey);
    }

    private function readPlatformProfileStatusCache(string $platform, int $hotelId, string $profileKey): array
    {
        $status = cache($this->platformProfileStatusCacheKey($platform, $hotelId, $profileKey));
        return is_array($status) ? $this->sanitizePlatformProfileLoginCachePayload($status) : [];
    }

    private function sanitizePlatformProfileLoginCachePayload(array $payload): array
    {
        $safe = [];
        foreach ($payload as $key => $value) {
            $normalizedKey = is_string($key)
                ? trim(strtolower((string)preg_replace('/[^a-z0-9]+/i', '_', $key)), '_')
                : '';
            $isStatusMetadata = in_array($normalizedKey, [
                'auth_status', 'credential_status', 'authorization_policy',
                'requires_explicit_authorization', 'has_cookies', 'cookie_configured',
            ], true);
            if (is_string($key) && !$isStatusMetadata && (
                $this->isOtaSecretConfigKey($key)
                || preg_match('/(?:^|_)(?:raw_)?(?:cookies?|tokens?|auth_data|authorization|password|secret|api_key|headers?)(?:_|$)/i', $normalizedKey) === 1
            )) {
                $safe[$key] = '[redacted]';
                continue;
            }
            if (is_array($value)) {
                $safe[$key] = $this->sanitizePlatformProfileLoginCachePayload($value);
                continue;
            }
            if (is_string($value)) {
                $safe[$key] = $this->safePlatformProfileLoginCacheText($value);
                continue;
            }
            $safe[$key] = is_scalar($value) || $value === null ? $value : '[object]';
        }
        return $safe;
    }

    private function safePlatformProfileLoginCacheText(string $value): string
    {
        $value = preg_replace(
            '/\b(cookie|set-cookie|authorization|proxy-authorization|x-api-key|api-key|token|access-token|refresh-token|spidertoken|spiderkey|mtgsig)\s*[:=]\s*[^\r\n]*/iu',
            '$1=****',
            $value
        ) ?: '';
        $value = preg_replace('/\bbearer\s+[A-Za-z0-9._~+\/=:-]{4,}/iu', 'Bearer ****', $value) ?: '';
        $value = preg_replace(
            '/\b([A-Za-z0-9_.-]*(?:session|token|auth|cookie|sid)[A-Za-z0-9_.-]*)\s*=\s*[^;\s,]+/iu',
            '$1=****',
            $value
        ) ?: '';
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?: '';
        return mb_substr($value, 0, 300);
    }

    private function platformProfileStatusCacheKey(string $platform, int $hotelId, string $profileKey): string
    {
        return 'platform_profile_status_' . $platform . '_' . $hotelId . '_' . BrowserProfileCaptureRequestService::safeFilePart($profileKey);
    }

    private function bindBrowserProfileDataSource(string $platform, int $hotelId, array $requestData, array $payload = []): array
    {
        $platform = strtolower(trim($platform));
        $isCtrip = $platform === 'ctrip';
        $profileKey = $isCtrip
            ? trim((string)($requestData['profile_id'] ?? $requestData['profileId'] ?? $payload['profile_id'] ?? $requestData['hotel_id'] ?? $requestData['hotelId'] ?? ''))
            : trim((string)($requestData['store_id'] ?? $requestData['storeId'] ?? $payload['store_id'] ?? $requestData['poi_id'] ?? $requestData['poiId'] ?? ''));
        if ($profileKey === '') {
            throw new \InvalidArgumentException($isCtrip ? 'missing Ctrip profile_id' : 'missing Meituan store_id');
        }

        $config = $isCtrip
            ? [
                'profile_id' => $profileKey,
                'stable_profile_id' => $profileKey,
                'profile_binding_key' => $profileKey,
                'profile_reuse_scope' => 'ota_account_store',
                'profile_daily_reuse_enabled' => true,
                'hotel_id' => trim((string)($requestData['hotel_id'] ?? $requestData['hotelId'] ?? $requestData['ctrip_hotel_id'] ?? $requestData['ctripHotelId'] ?? $payload['hotel_id'] ?? '')),
                'hotel_name' => trim((string)($requestData['hotel_name'] ?? $requestData['hotelName'] ?? $payload['hotel_name'] ?? '')),
                'capture_sections' => $this->normalizeCtripProfileCaptureSections(
                    $requestData['capture_sections'] ?? $requestData['captureSections'] ?? $requestData['sections'] ?? 'default'
                ),
            ]
            : [
                'store_id' => $profileKey,
                'stable_profile_id' => $profileKey,
                'profile_binding_key' => $profileKey,
                'profile_reuse_scope' => 'ota_account_store',
                'profile_daily_reuse_enabled' => true,
                'poi_id' => trim((string)($requestData['poi_id'] ?? $requestData['poiId'] ?? $payload['poi_id'] ?? '')),
                'poi_name' => trim((string)($requestData['poi_name'] ?? $requestData['poiName'] ?? $payload['poi_name'] ?? '')),
                'partner_id' => trim((string)($requestData['partner_id'] ?? $requestData['partnerId'] ?? '')),
                'capture_sections' => BrowserProfileCaptureRequestService::normalizeProfileSections(
                    $requestData['capture_sections'] ?? $requestData['captureSections'] ?? $requestData['sections'] ?? 'traffic,orders',
                    'traffic,orders'
                ),
            ];
        if (!$isCtrip) {
            $adsUrl = trim((string)($requestData['ads_url'] ?? $requestData['adsUrl'] ?? ''));
            if ($adsUrl !== '') {
                $config['ads_url'] = $adsUrl;
            }
        }

        $payloadForSave = [
            'id' => $this->findBrowserProfileDataSourceId($hotelId, $platform, $profileKey),
            'name' => ($isCtrip ? '携程' : '美团') . '浏览器 Profile 自动采集',
            'system_hotel_id' => $hotelId,
            'platform' => $platform,
            'data_type' => 'business',
            'ingestion_method' => 'browser_profile',
            'status' => 'ready',
            'enabled' => 1,
            'config' => $config,
        ];
        if ((int)$payloadForSave['id'] <= 0) {
            unset($payloadForSave['id']);
        }

        $saved = (new PlatformDataSyncService())->saveDataSource($this->currentUser, $payloadForSave);
        $this->clearAutoFetchLightProfileSourcesCache($hotelId, $platform);
        return $saved;
    }

    private function findBrowserProfileDataSourceId(int $hotelId, string $platform, string $profileKey): int
    {
        try {
            $rows = Db::name('platform_data_sources')
                ->field('id,config_json')
                ->where('system_hotel_id', $hotelId)
                ->where('platform', $platform)
                ->where('ingestion_method', 'browser_profile')
                ->where('status', '<>', 'disabled')
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            return 0;
        }

        foreach ($this->sanitizeBrowserProfileSourcesForSharedCache($rows) as $row) {
            if (($row['migration_required'] ?? false) === true) {
                continue;
            }
            $config = $this->decodeBrowserProfileSourceConfig(is_array($row) ? $row : []);
            $candidate = $platform === 'ctrip'
                ? $this->ctripProfileStoreIdFromConfig($config, $hotelId)
                : $this->meituanProfileStoreIdFromConfig($config);
            if ($candidate !== '' && $candidate === $profileKey) {
                return (int)($row['id'] ?? 0);
            }
        }

        return 0;
    }

    private function findBrowserProfileDataSourceForUnbind(int $hotelId, string $platform, string $profileKey): ?array
    {
        $sources = $this->listEnabledBrowserProfileDataSources($hotelId, $platform);
        if ($profileKey === '') {
            return count($sources) === 1 ? $sources[0] : null;
        }

        foreach ($sources as $source) {
            $config = $this->decodeBrowserProfileSourceConfig(is_array($source) ? $source : []);
            $candidate = $platform === 'ctrip'
                ? $this->ctripProfileStoreIdFromConfig($config, $hotelId)
                : $this->meituanProfileStoreIdFromConfig($config);
            if ($candidate !== '' && $candidate === $profileKey) {
                return is_array($source) ? $source : null;
            }
        }

        return null;
    }

    private function acquirePlatformProfileCaptureLock(string $platform, string $profileKey)
    {
        $projectRoot = dirname(__DIR__, 3);
        $dir = $projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'locks';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        $safeProfileKey = BrowserProfileCaptureRequestService::safeFilePart($profileKey);
        $path = $dir . DIRECTORY_SEPARATOR . 'profile_capture_' . $platform . '_' . $safeProfileKey . '.lock';
        $handle = fopen($path, 'c+');
        if (!$handle) {
            return null;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        ftruncate($handle, 0);
        fwrite($handle, json_encode([
            'platform' => $platform,
            'profile_key' => $profileKey,
            'pid' => getmypid(),
            'locked_at' => date('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $handle;
    }

    private function releasePlatformProfileCaptureLock($lock): void
    {
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function buildAutoFetchPlatformStatus(int $hotelId): array
    {
        $ctripConfig = $this->resolveCtripFetchConfigForHotel($hotelId);
        $meituanConfig = $this->resolveMeituanFetchConfigForHotel($hotelId);
        $runMode = $this->resolveAutoFetchRunMode($hotelId);
        $ctripBrowserProfileSources = $this->listCollectableCtripBrowserProfileDataSources($hotelId);
        $ctripBrowserProfileSourceCount = count($ctripBrowserProfileSources);
        $meituanBrowserProfileSources = $this->listCollectableBrowserProfileDataSources($hotelId, 'meituan');
        $meituanBrowserProfileSourceCount = count($meituanBrowserProfileSources);
        $ctripHasProfile = $this->ctripProfileExistsForConfig($ctripConfig, $hotelId)
            || $this->ctripBrowserProfileSourcesHaveProfile($ctripBrowserProfileSources, $hotelId);
        $meituanHasProfile = $this->meituanProfileExistsForConfig($meituanConfig);
        foreach ($meituanBrowserProfileSources as $source) {
            if ($this->meituanProfileExistsForConfig($this->decodeBrowserProfileSourceConfig(is_array($source) ? $source : []))) {
                $meituanHasProfile = true;
                break;
            }
        }
        $meituanApiStatus = $this->meituanAutoFetchConfigStatus($meituanConfig, $hotelId);
        $status = cache($this->autoFetchStatusKey($hotelId));
        $status = is_array($status) ? $status : [];
        $modeOptions = [
            'auto_fetch_mode' => $runMode,
            'ctrip_auto_fetch_mode' => $status['ctrip_auto_fetch_mode'] ?? $runMode,
            'meituan_auto_fetch_mode' => $status['meituan_auto_fetch_mode'] ?? $runMode,
        ];
        $ctripMode = $this->resolvePlatformAutoFetchMode($ctripConfig, $modeOptions, 'ctrip');
        $meituanMode = $this->resolvePlatformAutoFetchMode($meituanConfig, $modeOptions, 'meituan');
        $ctripTasks = array_values(array_filter(
            $this->buildAutoFetchConfigTaskPlan($hotelId, date('Y-m-d', strtotime('-1 day')), $ctripConfig, []),
            static fn(array $task): bool => ($task['platform'] ?? '') === 'ctrip'
        ));
        $meituanTasks = array_values(array_filter(
            $this->buildAutoFetchConfigTaskPlan($hotelId, date('Y-m-d', strtotime('-1 day')), [], $meituanConfig),
            static fn(array $task): bool => ($task['platform'] ?? '') === 'meituan'
        ));

        return [
            'ctrip' => [
                'configured' => $this->hasCtripFetchConfigForHotel($hotelId),
                'name' => (string)($ctripConfig['name'] ?? $ctripConfig['hotel_name'] ?? $ctripBrowserProfileSources[0]['name'] ?? ''),
                'mode' => $this->autoFetchModeLabel($ctripMode),
                'auto_fetch_mode' => $ctripMode,
                'cookie_configured' => $this->autoFetchCredentialReady($ctripConfig),
                'profile_configured' => $ctripHasProfile,
                'has_profile' => $ctripHasProfile,
                'task_count' => count($ctripTasks) + $ctripBrowserProfileSourceCount,
                'task_modules' => array_values(array_unique(array_merge(
                    array_map(static fn(array $task): string => (string)($task['module'] ?? ''), $ctripTasks),
                    $ctripBrowserProfileSourceCount > 0 ? ['browser_profile'] : []
                ))),
                'next_action' => $this->autoFetchPlatformNextAction($ctripMode, $this->autoFetchCredentialReady($ctripConfig), $ctripHasProfile, count($ctripTasks) + $ctripBrowserProfileSourceCount),
                'entry_url' => 'https://ebooking.ctrip.com/home/mainland',
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
                'task_count' => count($meituanTasks) + $meituanBrowserProfileSourceCount,
                'task_modules' => array_values(array_unique(array_merge(
                    array_map(static fn(array $task): string => (string)($task['module'] ?? ''), $meituanTasks),
                    $meituanBrowserProfileSourceCount > 0 ? ['browser_profile'] : []
                ))),
                'missing_fields' => $meituanApiStatus['missing_fields'],
                'missing_text' => $meituanApiStatus['missing_text'],
                'next_action' => $this->autoFetchPlatformNextAction($meituanMode, (bool)$meituanApiStatus['api_configured'], $meituanHasProfile, count($meituanTasks) + $meituanBrowserProfileSourceCount),
                'entry_url' => 'https://eb.meituan.com',
            ],
        ];
    }

    private function buildAutoFetchPlatformLightStatus(int $hotelId, array $status): array
    {
        $ctripConfig = $this->resolveCtripFetchConfigForHotelLight($hotelId);
        $meituanConfig = $this->resolveMeituanFetchConfigForHotelLight($hotelId);
        $ctripBrowserProfileSources = $this->listCollectableCtripBrowserProfileDataSources($hotelId);
        $meituanBrowserProfileSources = $this->listCollectableBrowserProfileDataSources($hotelId, 'meituan');
        $modeOptions = [
            'auto_fetch_mode' => $status['auto_fetch_mode'] ?? 'hybrid_auto',
            'ctrip_auto_fetch_mode' => $status['ctrip_auto_fetch_mode'] ?? $status['auto_fetch_mode'] ?? 'hybrid_auto',
            'meituan_auto_fetch_mode' => $status['meituan_auto_fetch_mode'] ?? $status['auto_fetch_mode'] ?? 'hybrid_auto',
        ];
        $ctripMode = $this->resolvePlatformAutoFetchMode($ctripConfig, $modeOptions, 'ctrip');
        $meituanMode = $this->resolvePlatformAutoFetchMode($meituanConfig, $modeOptions, 'meituan');
        $meituanApiStatus = $this->meituanAutoFetchConfigStatus($meituanConfig, $hotelId);

        $ctripCookieConfigured = $this->autoFetchCredentialReady($ctripConfig);
        $ctripProfileConfigured = count($ctripBrowserProfileSources) > 0
            || trim((string)($ctripConfig['profile_id'] ?? $ctripConfig['profileId'] ?? '')) !== '';
        $ctripConfigured = $ctripCookieConfigured || $ctripProfileConfigured;
        $ctripTaskModules = [];
        if ($ctripCookieConfigured) {
            $ctripTaskModules[] = 'cookie_config_tasks';
        }
        if ($ctripProfileConfigured) {
            $ctripTaskModules[] = 'browser_profile';
        }

        $meituanProfileConfigured = count($meituanBrowserProfileSources) > 0
            || $this->meituanProfileStoreIdFromConfig($meituanConfig) !== '';
        $meituanConfigured = !empty($meituanApiStatus['api_configured']) || $meituanProfileConfigured;
        $meituanTaskModules = [];
        if (!empty($meituanApiStatus['api_configured'])) {
            $meituanTaskModules[] = 'cookie_config_tasks';
        }
        if ($meituanProfileConfigured) {
            $meituanTaskModules[] = 'browser_profile';
        }

        return [
            'ctrip' => [
                'configured' => $ctripConfigured,
                'name' => (string)($ctripConfig['name'] ?? $ctripConfig['hotel_name'] ?? $ctripBrowserProfileSources[0]['name'] ?? ''),
                'mode' => $this->autoFetchModeLabel($ctripMode),
                'auto_fetch_mode' => $ctripMode,
                'cookie_configured' => $ctripCookieConfigured,
                'profile_configured' => $ctripProfileConfigured,
                'has_profile' => $ctripProfileConfigured,
                'task_count' => ($ctripConfigured ? 1 : 0) + count($ctripBrowserProfileSources),
                'task_modules' => array_values(array_unique($ctripTaskModules)),
                'next_action' => $this->autoFetchPlatformNextAction($ctripMode, $ctripCookieConfigured, $ctripProfileConfigured, ($ctripConfigured ? 1 : 0) + count($ctripBrowserProfileSources)),
                'entry_url' => 'https://ebooking.ctrip.com/home/mainland',
            ],
            'meituan' => [
                'configured' => $meituanConfigured,
                'name' => (string)($meituanConfig['name'] ?? $meituanConfig['hotel_name'] ?? $meituanBrowserProfileSources[0]['name'] ?? ''),
                'mode' => $this->autoFetchModeLabel($meituanMode),
                'auto_fetch_mode' => $meituanMode,
                'api_configured' => (bool)$meituanApiStatus['api_configured'],
                'cookie_configured' => (bool)$meituanApiStatus['has_cookies'],
                'partner_id_configured' => (bool)$meituanApiStatus['has_partner_id'],
                'poi_id_configured' => (bool)$meituanApiStatus['has_poi_id'],
                'profile_configured' => $meituanProfileConfigured,
                'has_profile' => $meituanProfileConfigured,
                'task_count' => ($meituanConfigured ? 1 : 0) + count($meituanBrowserProfileSources),
                'task_modules' => array_values(array_unique($meituanTaskModules)),
                'missing_fields' => $meituanApiStatus['missing_fields'],
                'missing_text' => $meituanApiStatus['missing_text'],
                'next_action' => $this->autoFetchPlatformNextAction($meituanMode, (bool)$meituanApiStatus['api_configured'], $meituanProfileConfigured, ($meituanConfigured ? 1 : 0) + count($meituanBrowserProfileSources)),
                'entry_url' => 'https://eb.meituan.com',
            ],
        ];
    }

    private function autoFetchPlatformsHaveConfig(array $platforms): bool
    {
        foreach ($platforms as $platform) {
            if (is_array($platform) && !empty($platform['configured'])) {
                return true;
            }
        }

        return false;
    }

    private function emptyAutoFetchPlatformStatus(array $status): array
    {
        return [
            'ctrip' => ['configured' => false, 'name' => '', 'mode' => $status['auto_fetch_mode_label'], 'auto_fetch_mode' => $status['auto_fetch_mode'], 'cookie_configured' => false, 'profile_configured' => false, 'has_profile' => false, 'task_count' => 0, 'task_modules' => [], 'entry_url' => 'https://ebooking.ctrip.com/home/mainland'],
            'meituan' => ['configured' => false, 'name' => '', 'mode' => $status['auto_fetch_mode_label'], 'auto_fetch_mode' => $status['auto_fetch_mode'], 'cookie_configured' => false, 'profile_configured' => false, 'has_profile' => false, 'task_count' => 0, 'task_modules' => [], 'entry_url' => 'https://eb.meituan.com'],
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
        $runTime = (string)($run['run_time'] ?? $run['run_at'] ?? '');
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
        $dataDate = (string)($record['data_date_value'] ?? $record['data_date'] ?? '');

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

    private function isAutoFetchDataRecordListRow(array $record): bool
    {
        if ((string)($record['source_record_type'] ?? '') === 'platform_sync_task') {
            return true;
        }

        if ((string)($record['status'] ?? '') !== 'skipped' || (int)($record['saved_count'] ?? 0) > 0) {
            return true;
        }

        $message = strtolower((string)($record['message'] ?? ''));
        $moduleSummary = strtolower((string)($record['module_summary'] ?? ''));
        return !(
            str_contains($moduleSummary, 'configuration[')
            || str_contains($message, '未配置')
            || str_contains($message, 'partner')
            || str_contains($message, 'poi')
            || str_contains($message, 'cookies')
        );
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
        $includeDetail = !$this->isFalseRequestValue(
            $this->request->get('include_detail', $this->request->get('detail', true))
        );

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
                    'schedule_minute' => 5,
                    'schedule_interval_hours' => 2,
                    'realtime_schedule_interval_hours' => 2,
                    'daily_report_time' => '09:00',
                    'browser_headless' => true,
                    'ctrip_section_concurrency' => 3,
                    'auto_fetch_mode' => 'hybrid_auto',
                    'ctrip_auto_fetch_mode' => 'hybrid_auto',
                    'meituan_auto_fetch_mode' => 'hybrid_auto',
                    'auto_fetch_mode_label' => '接口直连自动',
                    'recent_runs' => [],
                    'failed_records' => [],
                    'missed_dates' => [],
                    'missed_count' => 0,
                    'has_config' => false,
                    'platforms' => [],
                    'detail_loaded' => false,
                    'detail_pending' => false,
                    'status_scope' => 'empty',
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
            'schedule_minute' => 5,
            'schedule_interval_hours' => 2,
            'realtime_schedule_interval_hours' => 2,
            'daily_report_time' => '09:00',
            'browser_headless' => true,
            'ctrip_section_concurrency' => 3,
            'auto_fetch_mode' => 'hybrid_auto',
            'ctrip_auto_fetch_mode' => 'hybrid_auto',
            'meituan_auto_fetch_mode' => 'hybrid_auto',
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
        $status['schedule_minute'] = $this->normalizeAutoFetchScheduleMinute($status['schedule_minute'] ?? null);
        if ($status['schedule_minute'] === null) {
            $status['schedule_minute'] = 5;
        }
        $status['schedule_interval_hours'] = $this->normalizeAutoFetchScheduleIntervalHours($status['realtime_schedule_interval_hours'] ?? $status['schedule_interval_hours'] ?? null) ?? 2;
        $status['realtime_schedule_interval_hours'] = $status['schedule_interval_hours'];
        $status['daily_report_time'] = $this->normalizeFetchScheduleTime((string)($status['daily_report_time'] ?? $status['schedule_time'] ?? '09:00')) ?? '09:00';
        $status['browser_headless'] = array_key_exists('browser_headless', $status) ? $this->isTruthyRequestValue($status['browser_headless']) : true;
        $status['ctrip_section_concurrency'] = $this->normalizeCtripSectionConcurrency($status['ctrip_section_concurrency'] ?? $status['ctripSectionConcurrency'] ?? 3);
        $status['auto_fetch_mode'] = $hotelId
            ? $this->resolveAutoFetchRunMode((int)$hotelId, ['auto_fetch_mode' => $status['auto_fetch_mode'] ?? ''])
            : $this->normalizeAutoFetchMode($status['auto_fetch_mode'] ?? 'hybrid_auto');
        $status['ctrip_auto_fetch_mode'] = $this->normalizeAutoFetchMode($status['ctrip_auto_fetch_mode'] ?? $status['auto_fetch_mode']);
        $status['meituan_auto_fetch_mode'] = $this->normalizeAutoFetchMode($status['meituan_auto_fetch_mode'] ?? $status['auto_fetch_mode']);
        $status['auto_fetch_mode_label'] = $this->autoFetchModeLabel((string)$status['auto_fetch_mode']);
        $status = $this->normalizeAutoFetchScheduleStatus($status);
        $status['recent_runs'] = array_values(is_array($status['recent_runs'] ?? null) ? $status['recent_runs'] : []);
        $status['failed_records'] = array_values(is_array($status['failed_records'] ?? null) ? $status['failed_records'] : []);
        if ($includeDetail) {
            $status['missed_dates'] = $hotelId ? $this->buildCtripAutoFetchMissedDates((int)$hotelId) : [];
            $status['missed_count'] = count($status['missed_dates']);
            $status['has_config'] = $hotelId ? $this->hasAnyPlatformFetchConfigForHotel((int)$hotelId) : false;
            $status['platforms'] = $hotelId ? $this->buildAutoFetchPlatformStatus((int)$hotelId) : [
                'ctrip' => ['configured' => false, 'name' => '', 'mode' => $status['auto_fetch_mode_label'], 'auto_fetch_mode' => $status['auto_fetch_mode'], 'cookie_configured' => false, 'profile_configured' => false, 'has_profile' => false, 'task_count' => 0, 'task_modules' => [], 'entry_url' => 'https://ebooking.ctrip.com/home/mainland'],
                'meituan' => ['configured' => false, 'name' => '', 'mode' => $status['auto_fetch_mode_label'], 'auto_fetch_mode' => $status['auto_fetch_mode'], 'cookie_configured' => false, 'profile_configured' => false, 'has_profile' => false, 'task_count' => 0, 'task_modules' => [], 'entry_url' => 'https://eb.meituan.com'],
            ];
            $status['detail_loaded'] = true;
            $status['detail_pending'] = false;
            $status['status_scope'] = 'full';
        } else {
            $status['missed_dates'] = [];
            $status['missed_count'] = null;
            $status['platforms'] = $hotelId ? $this->buildAutoFetchPlatformLightStatus((int)$hotelId, $status) : $this->emptyAutoFetchPlatformStatus($status);
            $status['has_config'] = $this->autoFetchPlatformsHaveConfig($status['platforms']);
            $status['detail_loaded'] = false;
            $status['detail_pending'] = true;
            $status['status_scope'] = 'light';
        }

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
            $cacheRows = $this->buildAutoFetchRecordRows($status, (int)$hotelId, (string)($hotelMap[(int)$hotelId] ?? '门店ID ' . $hotelId), $filters);
            $cacheRows = array_values(array_filter($cacheRows, [$this, 'isAutoFetchDataRecordListRow']));
            $rows = array_merge($rows, $cacheRows);
        }
        $existingRecordKeys = array_fill_keys(array_filter(array_map([$this, 'autoFetchRecordLogicalKey'], $rows)), true);
        foreach ($this->buildPlatformSyncTaskAutoFetchRecordRows($hotelIds, $hotelMap, $filters) as $taskRecord) {
            $recordKey = $this->autoFetchRecordLogicalKey($taskRecord);
            if ($recordKey !== '' && isset($existingRecordKeys[$recordKey])) {
                continue;
            }
            if ($recordKey !== '') {
                $existingRecordKeys[$recordKey] = true;
            }
            $rows[] = $taskRecord;
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

    private function autoFetchRecordLogicalKey(array $record): string
    {
        $hotelId = (int)($record['hotel_id'] ?? 0);
        $platform = trim((string)($record['platform'] ?? ''));
        $dataDate = trim((string)($record['data_date_value'] ?? $record['data_date'] ?? ''));
        if ($hotelId <= 0 || $platform === '' || $dataDate === '') {
            return '';
        }
        return $hotelId . '|' . $platform . '|' . $dataDate;
    }

    private function buildPlatformSyncTaskAutoFetchRecordRows(array $hotelIds, array $hotelMap, array $filters = []): array
    {
        $hotelIds = array_values(array_filter(array_map('intval', $hotelIds), static fn(int $id): bool => $id > 0));
        if (empty($hotelIds)) {
            return [];
        }

        try {
            $query = Db::name('platform_data_sync_tasks')
                ->whereIn('system_hotel_id', $hotelIds)
                ->where('trigger_type', 'auto_fetch')
                ->order('started_at', 'desc')
                ->order('id', 'desc')
                ->limit(200);
            $source = trim((string)($filters['source'] ?? ''));
            if ($source !== '') {
                $query->where('platform', $source);
            }
            $tasks = $query->select()->toArray();
        } catch (\Throwable $e) {
            \think\facade\Log::warning('读取平台同步任务记录失败: ' . $e->getMessage());
            return [];
        }

        $taskDataMap = $this->buildPlatformSyncTaskDataDateMap(array_column($tasks, 'id'));
        $rows = [];
        foreach ($tasks as $task) {
            if (!is_array($task)) {
                continue;
            }
            $hotelId = (int)($task['system_hotel_id'] ?? 0);
            $record = $this->normalizePlatformSyncTaskAutoFetchRecordRow(
                $task,
                (string)($hotelMap[$hotelId] ?? ('门店ID ' . $hotelId)),
                $taskDataMap[(int)($task['id'] ?? 0)] ?? []
            );
            if ($this->matchesAutoFetchRecordFilters($record, $filters)) {
                $rows[] = $record;
            }
        }

        return $rows;
    }

    private function buildPlatformSyncTaskDataDateMap(array $taskIds): array
    {
        $taskIds = array_values(array_unique(array_filter(array_map('intval', $taskIds), static fn(int $id): bool => $id > 0)));
        if (empty($taskIds)) {
            return [];
        }

        $columns = $this->getOnlineDailyDataColumns();
        if (!isset($columns['sync_task_id'])) {
            return [];
        }

        try {
            $rows = Db::name('online_daily_data')
                ->field('sync_task_id, MIN(data_date) as min_date, MAX(data_date) as max_date, COUNT(*) as row_count')
                ->whereIn('sync_task_id', $taskIds)
                ->group('sync_task_id')
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            \think\facade\Log::warning('读取平台同步任务入库日期失败: ' . $e->getMessage());
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            $taskId = (int)($row['sync_task_id'] ?? 0);
            if ($taskId <= 0) {
                continue;
            }
            $minDate = (string)($row['min_date'] ?? '');
            $maxDate = (string)($row['max_date'] ?? '');
            $map[$taskId] = [
                'data_date' => $maxDate !== '' ? $maxDate : $minDate,
                'data_date_label' => $minDate !== '' && $maxDate !== '' && $minDate !== $maxDate ? ($minDate . ' 至 ' . $maxDate) : ($maxDate ?: $minDate),
                'row_count' => (int)($row['row_count'] ?? 0),
            ];
        }

        return $map;
    }

    private function normalizePlatformSyncTaskAutoFetchRecordRow(array $task, string $hotelName, array $taskData = []): array
    {
        $taskId = (int)($task['id'] ?? 0);
        $stats = json_decode((string)($task['stats_json'] ?? ''), true);
        $stats = is_array($stats) ? $stats : [];
        $savedCount = (int)($stats['saved_count'] ?? $taskData['row_count'] ?? 0);
        $normalizedCount = (int)($stats['normalized_count'] ?? 0);
        $status = $this->normalizePlatformSyncTaskAutoFetchStatus((string)($task['status'] ?? ''), $savedCount);
        $runTime = (string)($task['finished_at'] ?? '') ?: ((string)($task['started_at'] ?? '') ?: (string)($task['create_time'] ?? ''));
        $moduleSummary = trim((string)($task['data_type'] ?? ''));
        $ingestionMethod = trim((string)($task['ingestion_method'] ?? ''));
        if ($moduleSummary !== '') {
            $moduleSummary .= '[' . ($ingestionMethod !== '' ? $ingestionMethod . ':' : '') . $status . ':' . $savedCount . ']';
        }
        if ($normalizedCount > 0) {
            $moduleSummary .= ($moduleSummary !== '' ? ' / ' : '') . '标准行 ' . $normalizedCount;
        }

        return [
            'id' => 'sync_task_' . $taskId,
            'sync_task_id' => $taskId,
            'source_record_type' => 'platform_sync_task',
            'hotel_id' => (int)($task['system_hotel_id'] ?? 0),
            'hotel_name' => $hotelName,
            'run_time' => $runTime,
            'data_date' => (string)($taskData['data_date_label'] ?? $taskData['data_date'] ?? ''),
            'data_date_value' => (string)($taskData['data_date'] ?? ''),
            'platform' => strtolower((string)($task['platform'] ?? '')),
            'platform_label' => strtolower((string)($task['platform'] ?? '')) === 'meituan' ? '美团' : (strtolower((string)($task['platform'] ?? '')) === 'ctrip' ? '携程' : '其他'),
            'status' => $status,
            'status_label' => $this->platformSyncTaskAutoFetchStatusLabel($status),
            'saved_count' => $savedCount,
            'module_summary' => $moduleSummary !== '' ? $moduleSummary : '-',
            'message' => (string)($task['message'] ?? '-'),
            'run_message' => (string)($task['message'] ?? ''),
            'auto_fetch_mode' => (string)($task['ingestion_method'] ?? ''),
            'mode_label' => (string)($task['ingestion_method'] ?? ''),
        ];
    }

    private function normalizePlatformSyncTaskAutoFetchStatus(string $status, int $savedCount): string
    {
        $status = strtolower(trim($status));
        if ($status === 'success') {
            return 'success';
        }
        if (in_array($status, ['pending', 'running'], true)) {
            return $status;
        }
        if ($status === 'partial_success') {
            return $savedCount > 0 ? 'success' : 'failed';
        }
        return in_array($status, ['failed', 'waiting_config'], true) ? 'failed' : ($savedCount > 0 ? 'success' : 'failed');
    }

    private function platformSyncTaskAutoFetchStatusLabel(string $status): string
    {
        return [
            'success' => '成功',
            'failed' => '失败',
            'skipped' => '跳过',
            'pending' => '待执行',
            'running' => '运行中',
        ][$status] ?? '失败';
    }

    private function extractAutoFetchSyncTaskIdsFromRecordIds(array $ids): array
    {
        $taskIds = [];
        foreach ($ids as $id) {
            $value = trim((string)$id);
            if ($value === '' || !preg_match('/^sync_task_(\d+)$/', $value, $matches)) {
                continue;
            }
            $taskId = (int)$matches[1];
            if ($taskId > 0) {
                $taskIds[$taskId] = true;
            }
        }

        return array_keys($taskIds);
    }

    private function isAutoFetchPlatformSyncTaskDeletableStatus(string $status): bool
    {
        return !in_array(strtolower(trim($status)), ['pending', 'running'], true);
    }

    private function getTableColumnsSafe(string $table): array
    {
        static $columns = [];
        if (isset($columns[$table])) {
            return $columns[$table];
        }

        try {
            $rows = Db::query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
            $columns[$table] = array_fill_keys(array_column($rows, 'Field'), true);
        } catch (\Throwable $e) {
            \think\facade\Log::warning('Read table columns failed for ' . $table . ': ' . $e->getMessage());
            $columns[$table] = [];
        }

        return $columns[$table];
    }

    private function deleteRowsBySyncTaskIds(string $table, array $taskIds, array $hotelIds): int
    {
        $taskIds = array_values(array_unique(array_filter(array_map('intval', $taskIds), static fn(int $id): bool => $id > 0)));
        $hotelIds = array_values(array_unique(array_filter(array_map('intval', $hotelIds), static fn(int $id): bool => $id > 0)));
        if (empty($taskIds) || empty($hotelIds)) {
            return 0;
        }

        $columns = $this->getTableColumnsSafe($table);
        if (!isset($columns['sync_task_id'])) {
            return 0;
        }

        try {
            $query = Db::name($table)->whereIn('sync_task_id', $taskIds);
            if (isset($columns['system_hotel_id'])) {
                $query->whereIn('system_hotel_id', $hotelIds);
            }
            return (int)$query->delete();
        } catch (\Throwable $e) {
            \think\facade\Log::warning('Delete rows by sync_task_id failed for ' . $table . ': ' . $e->getMessage());
            return 0;
        }
    }

    private function deletePlatformSyncTaskAutoFetchRecords(array $hotelIds, array $taskIds = []): array
    {
        $result = $this->emptyPlatformSyncTaskDeleteResult();
        $hotelIds = array_values(array_unique(array_filter(array_map('intval', $hotelIds), static fn(int $id): bool => $id > 0)));
        $taskIds = array_values(array_unique(array_filter(array_map('intval', $taskIds), static fn(int $id): bool => $id > 0)));
        if (empty($hotelIds)) {
            return $result;
        }

        $taskColumns = $this->getTableColumnsSafe('platform_data_sync_tasks');
        foreach (['id', 'system_hotel_id', 'trigger_type', 'status'] as $requiredColumn) {
            if (!isset($taskColumns[$requiredColumn])) {
                return $result;
            }
        }

        try {
            $query = Db::name('platform_data_sync_tasks')
                ->field('id,status')
                ->whereIn('system_hotel_id', $hotelIds)
                ->where('trigger_type', 'auto_fetch');
            if (!empty($taskIds)) {
                $query->whereIn('id', $taskIds);
            }
            $tasks = $query->select()->toArray();
        } catch (\Throwable $e) {
            \think\facade\Log::warning('Read platform auto fetch sync tasks for delete failed: ' . $e->getMessage());
            return $result;
        }

        $deletableTaskIds = [];
        foreach ($tasks as $task) {
            $taskId = (int)($task['id'] ?? 0);
            if ($taskId <= 0) {
                continue;
            }
            if (!$this->isAutoFetchPlatformSyncTaskDeletableStatus((string)($task['status'] ?? ''))) {
                $result['skipped_count']++;
                continue;
            }
            $deletableTaskIds[] = $taskId;
        }
        if (empty($deletableTaskIds)) {
            return $result;
        }

        $result['online_daily_deleted_count'] = $this->deleteRowsBySyncTaskIds('online_daily_data', $deletableTaskIds, $hotelIds);
        $result['raw_record_deleted_count'] = $this->deleteRowsBySyncTaskIds('platform_data_raw_records', $deletableTaskIds, $hotelIds);
        $result['log_deleted_count'] = $this->deleteRowsBySyncTaskIds('platform_data_sync_logs', $deletableTaskIds, $hotelIds);

        try {
            $result['deleted_count'] = (int)Db::name('platform_data_sync_tasks')
                ->whereIn('id', $deletableTaskIds)
                ->whereIn('system_hotel_id', $hotelIds)
                ->where('trigger_type', 'auto_fetch')
                ->delete();
        } catch (\Throwable $e) {
            \think\facade\Log::warning('Delete platform auto fetch sync tasks failed: ' . $e->getMessage());
            $result['deleted_count'] = 0;
        }

        return $result;
    }

    private function emptyPlatformSyncTaskDeleteResult(): array
    {
        return [
            'deleted_count' => 0,
            'skipped_count' => 0,
            'online_daily_deleted_count' => 0,
            'raw_record_deleted_count' => 0,
            'log_deleted_count' => 0,
        ];
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
        $taskIds = $this->extractAutoFetchSyncTaskIdsFromRecordIds($ids);
        $taskDeleteResult = !empty($taskIds)
            ? $this->deletePlatformSyncTaskAutoFetchRecords($hotelIds, $taskIds)
            : $this->emptyPlatformSyncTaskDeleteResult();
        $deletedCount += (int)$taskDeleteResult['deleted_count'];
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

        return $this->success([
            'deleted_count' => $deletedCount,
            'platform_sync_task_deleted_count' => (int)$taskDeleteResult['deleted_count'],
            'platform_sync_task_skipped_count' => (int)$taskDeleteResult['skipped_count'],
            'online_daily_deleted_count' => (int)$taskDeleteResult['online_daily_deleted_count'],
        ], '删除成功');
    }

    public function clearAutoFetchRecords(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_delete_online_data');

        $hotelIds = $this->resolveAutoFetchRecordHotelIds($this->request->post('hotel_id', ''));
        $clearedCount = 0;
        $taskDeleteResult = $this->deletePlatformSyncTaskAutoFetchRecords($hotelIds);
        $clearedCount += (int)$taskDeleteResult['deleted_count'];
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

        return $this->success([
            'cleared_count' => $clearedCount,
            'platform_sync_task_deleted_count' => (int)$taskDeleteResult['deleted_count'],
            'platform_sync_task_skipped_count' => (int)$taskDeleteResult['skipped_count'],
            'online_daily_deleted_count' => (int)$taskDeleteResult['online_daily_deleted_count'],
        ], '历史记录已清空');
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
        $requestData = $this->requestData();
        $modeRaw = $this->request->post('auto_fetch_mode', $this->request->post('autoMode', $status['auto_fetch_mode'] ?? 'hybrid_auto'));
        $status['auto_fetch_mode'] = $this->normalizeAutoFetchMode($modeRaw);
        $platformModes = $this->platformAutoFetchModeOptionsFromRequest($requestData);
        $status['ctrip_auto_fetch_mode'] = $platformModes['ctrip_auto_fetch_mode'] ?? ($status['ctrip_auto_fetch_mode'] ?? $status['auto_fetch_mode']);
        $status['meituan_auto_fetch_mode'] = $platformModes['meituan_auto_fetch_mode'] ?? ($status['meituan_auto_fetch_mode'] ?? $status['auto_fetch_mode']);
        if (!isset($status['schedule_time'])) {
            $status['schedule_time'] = '10:00';
        }
        if (!isset($status['schedule_minute'])) {
            $status['schedule_minute'] = 5;
        }
        if (!isset($status['realtime_schedule_interval_hours'])) {
            $status['realtime_schedule_interval_hours'] = 2;
        }
        $intervalRaw = $requestData['realtime_schedule_interval_hours'] ?? $requestData['realtimeScheduleIntervalHours'] ?? $requestData['schedule_interval_hours'] ?? $requestData['scheduleIntervalHours'] ?? $status['realtime_schedule_interval_hours'];
        $status['realtime_schedule_interval_hours'] = $this->normalizeAutoFetchScheduleIntervalHours($intervalRaw) ?? 2;
        $status['schedule_interval_hours'] = $status['realtime_schedule_interval_hours'];
        if (!isset($status['daily_report_time'])) {
            $status['daily_report_time'] = '09:00';
        }
        if (array_key_exists('browser_headless', $requestData) || array_key_exists('headless', $requestData)) {
            $status['browser_headless'] = $this->autoFetchBrowserHeadlessFromRequest($requestData, true);
        } elseif (!isset($status['browser_headless'])) {
            $status['browser_headless'] = true;
        }
        $status['ctrip_section_concurrency'] = $this->ctripSectionConcurrencyFromRequest(
            $requestData,
            (int)($status['ctrip_section_concurrency'] ?? 3)
        );
        cache($statusKey, $status, 86400 * 30);

        OperationLog::record('online_data', 'toggle_auto_fetch', '切换自动获取状态: ' . ($enabled ? '开启' : '关闭') . " (门店ID: {$hotelId})", $this->currentUser->id);

        return $this->success([
            'enabled' => $status['enabled'],
            'auto_fetch_mode' => $status['auto_fetch_mode'],
            'ctrip_auto_fetch_mode' => $status['ctrip_auto_fetch_mode'],
            'meituan_auto_fetch_mode' => $status['meituan_auto_fetch_mode'],
            'schedule_minute' => (int)$status['schedule_minute'],
            'schedule_interval_hours' => (int)$status['schedule_interval_hours'],
            'realtime_schedule_interval_hours' => (int)$status['realtime_schedule_interval_hours'],
            'daily_report_time' => (string)$status['daily_report_time'],
            'browser_headless' => (bool)$status['browser_headless'],
            'ctrip_section_concurrency' => (int)$status['ctrip_section_concurrency'],
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
        $requestData = $this->requestData();
        $scheduleTime = $this->normalizeFetchScheduleTime((string)($requestData['historical_schedule_time'] ?? $requestData['historicalScheduleTime'] ?? $this->request->post('schedule_time', '10:00')));
        $scheduleMinuteRaw = $requestData['realtime_schedule_minute'] ?? $requestData['realtimeScheduleMinute'] ?? $requestData['schedule_minute'] ?? $requestData['scheduleMinute'] ?? null;
        $scheduleMinute = $this->normalizeAutoFetchScheduleMinute($scheduleMinuteRaw);
        $scheduleIntervalRaw = $requestData['realtime_schedule_interval_hours'] ?? $requestData['realtimeScheduleIntervalHours'] ?? $requestData['schedule_interval_hours'] ?? $requestData['scheduleIntervalHours'] ?? null;
        $scheduleIntervalHours = $this->normalizeAutoFetchScheduleIntervalHours($scheduleIntervalRaw);
        $dailyReportTime = $this->normalizeAutoFetchDailyReportTimeFromRequest($requestData, $scheduleTime);

        // 验证时间格式
        if ($scheduleTime === null) {
            return $this->error('时间格式错误，请使用 HH:MM 格式');
        }
        if ($scheduleMinuteRaw !== null && $scheduleMinute === null) {
            return $this->error('实时任务执行分钟必须在 0-59 之间');
        }
        if ($scheduleIntervalRaw !== null && $scheduleIntervalHours === null) {
            return $this->error('实时采集间隔必须在 1-24 小时之间');
        }
        if ($dailyReportTime === null) {
            return $this->error('日报发送时间格式错误，请使用 HH:MM 格式');
        }

        if ($hotelId === null) {
            return $this->error('请选择要设置自动抓取的酒店');
        }
        if (!$this->currentUser->hasHotelPermission((int)$hotelId, 'can_fetch_online_data')) {
            return $this->error('无权操作该门店');
        }
        if (!$this->hasAnyPlatformFetchConfigForHotel((int)$hotelId)) {
            $this->recordAutoFetchNotification((int)$hotelId, false, '未配置携程或美团抓取凭证，请先在酒店管理中关联平台配置', date('Y-m-d'), [
                'data_period' => 'historical_daily',
            ], 'retry_auto_fetch');
            return $this->error('未配置携程或美团抓取凭证，请先在酒店管理中关联平台配置');
        }

        $statusKey = $hotelId ? "online_data_auto_fetch_status_{$hotelId}" : 'online_data_auto_fetch_status';
        $status = cache($statusKey) ?: [];
        $status['historical_schedule_time'] = $scheduleTime;
        $status['realtime_schedule_minute'] = $scheduleMinute ?? $this->normalizeAutoFetchScheduleMinute($status['realtime_schedule_minute'] ?? $status['schedule_minute'] ?? null) ?? 5;
        $status['realtime_schedule_interval_hours'] = $scheduleIntervalHours ?? $this->normalizeAutoFetchScheduleIntervalHours($status['realtime_schedule_interval_hours'] ?? $status['schedule_interval_hours'] ?? null) ?? 2;
        $status['historical_enabled'] = array_key_exists('historical_enabled', $requestData) || array_key_exists('historicalEnabled', $requestData)
            ? $this->isTruthyRequestValue($requestData['historical_enabled'] ?? $requestData['historicalEnabled'] ?? false)
            : ($status['historical_enabled'] ?? true);
        $status['realtime_enabled'] = array_key_exists('realtime_enabled', $requestData) || array_key_exists('realtimeEnabled', $requestData)
            ? $this->isTruthyRequestValue($requestData['realtime_enabled'] ?? $requestData['realtimeEnabled'] ?? false)
            : ($status['realtime_enabled'] ?? true);
        $status['schedule_time'] = $status['historical_schedule_time'];
        $status['schedule_minute'] = $status['realtime_schedule_minute'];
        $status['schedule_interval_hours'] = $status['realtime_schedule_interval_hours'];
        $status['daily_report_time'] = $dailyReportTime;
        if (array_key_exists('browser_headless', $requestData) || array_key_exists('headless', $requestData)) {
            $status['browser_headless'] = $this->autoFetchBrowserHeadlessFromRequest($requestData, true);
        } elseif (!isset($status['browser_headless'])) {
            $status['browser_headless'] = true;
        }
        $modeRaw = $this->request->post('auto_fetch_mode', $this->request->post('autoMode', $status['auto_fetch_mode'] ?? 'hybrid_auto'));
        $status['auto_fetch_mode'] = $this->normalizeAutoFetchMode($modeRaw);
        $platformModes = $this->platformAutoFetchModeOptionsFromRequest($requestData);
        $status['ctrip_auto_fetch_mode'] = $platformModes['ctrip_auto_fetch_mode'] ?? ($status['ctrip_auto_fetch_mode'] ?? $status['auto_fetch_mode']);
        $status['meituan_auto_fetch_mode'] = $platformModes['meituan_auto_fetch_mode'] ?? ($status['meituan_auto_fetch_mode'] ?? $status['auto_fetch_mode']);
        $status['ctrip_section_concurrency'] = $this->ctripSectionConcurrencyFromRequest(
            $requestData,
            (int)($status['ctrip_section_concurrency'] ?? 3)
        );
        $ctripConfig = $this->resolveCtripFetchConfigForHotel((int)$hotelId);
        $meituanConfig = $this->resolveMeituanFetchConfigForHotel((int)$hotelId);
        if ($this->autoFetchCredentialReady($ctripConfig)) {
            $status['ctrip_config_id'] = $this->autoFetchConfigId($ctripConfig);
            $status['ctrip_request_url'] = $this->autoFetchCtripRequestUrl($ctripConfig);
            $status['ctrip_node_id'] = $this->autoFetchCtripNodeId($ctripConfig);
        } else {
            unset($status['ctrip_config_id'], $status['ctrip_request_url'], $status['ctrip_node_id']);
        }
        if ($this->autoFetchCredentialReady($meituanConfig)) {
            $status['meituan_config_id'] = $this->autoFetchConfigId($meituanConfig);
        } else {
            unset($status['meituan_config_id']);
        }
        if (!isset($status['enabled'])) {
            $status['enabled'] = false;
        }
        $status = $this->normalizeAutoFetchScheduleStatus($status);
        cache($statusKey, $status, 86400 * 30);

        OperationLog::record('online_data', 'set_schedule', "设置自动获取时间: {$scheduleTime} (门店ID: {$hotelId})", $this->currentUser->id);

        return $this->success([
            'schedule_time' => $scheduleTime,
            'schedule_minute' => (int)$status['schedule_minute'],
            'historical_enabled' => (bool)$status['historical_enabled'],
            'historical_schedule_time' => (string)$status['historical_schedule_time'],
            'realtime_enabled' => (bool)$status['realtime_enabled'],
            'realtime_schedule_minute' => (int)$status['realtime_schedule_minute'],
            'realtime_schedule_interval_hours' => (int)$status['realtime_schedule_interval_hours'],
            'schedule_interval_hours' => (int)$status['schedule_interval_hours'],
            'historical' => $status['historical'],
            'realtime' => $status['realtime'],
            'daily_report_time' => $dailyReportTime,
            'browser_headless' => (bool)$status['browser_headless'],
            'ctrip_section_concurrency' => (int)$status['ctrip_section_concurrency'],
            'auto_fetch_mode' => $status['auto_fetch_mode'],
            'ctrip_auto_fetch_mode' => $status['ctrip_auto_fetch_mode'],
            'meituan_auto_fetch_mode' => $status['meituan_auto_fetch_mode'],
            'ctrip_config_id' => $status['ctrip_config_id'] ?? null,
            'ctrip_request_url' => $status['ctrip_request_url'] ?? null,
            'ctrip_node_id' => $status['ctrip_node_id'] ?? null,
            'meituan_config_id' => $status['meituan_config_id'] ?? null,
            'auto_fetch_mode_label' => $this->autoFetchModeLabel($status['auto_fetch_mode']),
        ], "设置成功，历史数据 {$scheduleTime} 保底抓取；实时数据每 {$status['realtime_schedule_interval_hours']} 小时第 {$status['schedule_minute']} 分钟抓取");
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

        $requestData = $this->requestData();
        $dataPeriod = $this->normalizeOnlineDailyDataPeriod($requestData['data_period'] ?? $requestData['dataPeriod'] ?? 'historical_daily');
        if ($dataPeriod === '') {
            $dataPeriod = 'historical_daily';
        }
        $autoFetchModeRaw = $this->request->post('auto_fetch_mode', $this->request->post('autoMode', null));
        $browserHeadless = $this->autoFetchBrowserHeadlessFromRequest($requestData, true);
        $fetchOptions = [
            'interactive_browser' => !$browserHeadless,
            'browser_headless' => $browserHeadless,
            'data_period' => $dataPeriod,
            'snapshot_time' => date('Y-m-d H:i:s'),
            'ctrip_section_concurrency' => $this->ctripSectionConcurrencyFromRequest($requestData, 3),
        ];
        if ($autoFetchModeRaw !== null && trim((string)$autoFetchModeRaw) !== '') {
            $fetchOptions['auto_fetch_mode'] = $autoFetchModeRaw;
        }
        $fetchOptions = array_merge($fetchOptions, $this->platformAutoFetchModeOptionsFromRequest($requestData));
        $backgroundRequested = $this->isTruthyRequestValue($requestData['async'] ?? $requestData['background'] ?? false)
            && !$this->isTruthyRequestValue($requestData['background_task'] ?? false);
        if ($backgroundRequested) {
            $task = $this->createAutoFetchBackgroundTask(
                (int)$hotelId,
                $dataDate,
                $dataPeriod,
                $requestData,
                $fetchOptions,
                '/api/online-data/retry-auto-fetch',
                [
                    'hotel_id' => (int)$hotelId,
                    'system_hotel_id' => (int)$hotelId,
                    'data_date' => $dataDate,
                    'data_period' => $dataPeriod,
                    'async' => false,
                    'background_task' => true,
                ]
            );
            if (empty($task)) {
                return $this->error('后台补抓任务创建失败，请检查 PHP CLI 或登录状态');
            }

            $this->markAutoFetchRunningStatus((int)$hotelId, $dataDate, $dataPeriod, $task, $fetchOptions);
            if (!$this->launchAutoFetchBackgroundTask($task)) {
                $this->updateFetchStatus((int)$hotelId, false, '后台补抓任务启动失败', $dataDate, [
                    'data_period' => $dataPeriod,
                    'ctrip_section_concurrency' => $fetchOptions['ctrip_section_concurrency'] ?? 3,
                ]);
                $this->recordAutoFetchNotification((int)$hotelId, false, '后台补抓任务启动失败', $dataDate, [
                    'data_period' => $dataPeriod,
                    'ctrip_section_concurrency' => $fetchOptions['ctrip_section_concurrency'] ?? 3,
                ], 'retry_auto_fetch');
                return $this->error('后台补抓任务启动失败');
            }

            $mode = $this->normalizeAutoFetchMode($fetchOptions['auto_fetch_mode'] ?? 'hybrid_auto');
            OperationLog::record('online_data', 'retry_auto_fetch_queued', "补抓平台数据已提交后台执行: {$dataDate} (门店ID: {$hotelId})", $this->currentUser->id);
            return $this->success([
                'status' => 'running',
                'task_id' => (string)$task['task_id'],
                'data_date' => $dataDate,
                'data_period' => $dataPeriod,
                'saved_count' => 0,
                'auto_fetch_mode' => $mode,
                'auto_fetch_mode_label' => $this->autoFetchModeLabel($mode),
                'ctrip_section_concurrency' => $fetchOptions['ctrip_section_concurrency'] ?? 3,
            ], '补抓任务已提交后台执行');
        }

        $result = $this->executeAutoFetch((int)$hotelId, $dataDate, $fetchOptions);
        $this->updateFetchStatus((int)$hotelId, (bool)$result['success'], (string)$result['message'], $dataDate, [
            'saved_count' => (int)($result['saved_count'] ?? 0),
            'auto_fetch_mode' => $result['auto_fetch_mode'] ?? null,
            'platform_results' => $result['platform_results'] ?? [],
            'data_period' => $dataPeriod,
            'timing' => $result['timing'] ?? [],
            'ctrip_section_concurrency' => $result['ctrip_section_concurrency'] ?? $fetchOptions['ctrip_section_concurrency'] ?? 3,
        ]);
        $this->recordAutoFetchNotification((int)$hotelId, (bool)$result['success'], (string)$result['message'], $dataDate, [
            'saved_count' => (int)($result['saved_count'] ?? 0),
            'auto_fetch_mode' => $result['auto_fetch_mode'] ?? null,
            'platform_results' => $result['platform_results'] ?? [],
            'data_period' => $dataPeriod,
            'timing' => $result['timing'] ?? [],
            'ctrip_section_concurrency' => $result['ctrip_section_concurrency'] ?? $fetchOptions['ctrip_section_concurrency'] ?? 3,
        ], 'retry_auto_fetch');

        if ($result['success']) {
            OperationLog::record('online_data', 'retry_auto_fetch', "补抓平台数据: {$dataDate}，{$result['message']} (门店ID: {$hotelId})", $this->currentUser->id);
            return $this->success([
                'data_date' => $dataDate,
                'data_period' => $dataPeriod,
                'saved_count' => (int)($result['saved_count'] ?? 0),
                'auto_fetch_mode' => $result['auto_fetch_mode'] ?? 'hybrid_auto',
                'auto_fetch_mode_label' => $result['auto_fetch_mode_label'] ?? '接口直连自动',
                'platform_results' => $result['platform_results'] ?? [],
                'timing' => $result['timing'] ?? [],
                'ctrip_section_concurrency' => $result['ctrip_section_concurrency'] ?? $fetchOptions['ctrip_section_concurrency'] ?? 3,
            ], '补抓成功');
        }

        return $this->error('补抓失败: ' . $result['message']);
    }

    /**
     * 数据分析
     */

    public function cronTrigger(): Response
    {
        $rateLimited = $this->checkPublicEndpointRateLimit('cron_trigger', 20, 60);
        if ($rateLimited !== null) {
            $this->recordPublicEndpointFailure('cron_trigger', 'rate_limited', 429, $rateLimited);
            return json(['code' => 429, 'message' => 'Too Many Requests'], 429);
        }

        // 简单的token验证
        $token = trim((string)$this->request->header('X-Cron-Token', ''));
        $configToken = trim((string)\think\facade\Env::get('CRON_TOKEN', ''));
        if ($configToken === '') {
            $this->recordPublicEndpointFailure('cron_trigger', 'cron_token_not_configured', 403);
            return json(['code' => 403, 'message' => 'CRON_TOKEN未配置'], 403);
        }

        if ($token === '' || !hash_equals($configToken, $token)) {
            $this->recordPublicEndpointFailure('cron_trigger', 'invalid_cron_token', 401);
            return json(['code' => 401, 'message' => 'Unauthorized'], 401);
        }

        $currentTime = date('H:i');
        $currentMinute = (int)date('i');
        $currentHour = date('H');
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

            $status = $this->normalizeAutoFetchScheduleStatus($status);
            $browserHeadless = array_key_exists('browser_headless', $status) ? $this->isTruthyRequestValue($status['browser_headless']) : true;
            $baseOptions = [
                'interactive_browser' => !$browserHeadless,
                'browser_headless' => $browserHeadless,
                'auto_fetch_mode' => $status['auto_fetch_mode'] ?? null,
                'ctrip_auto_fetch_mode' => $status['ctrip_auto_fetch_mode'] ?? ($status['auto_fetch_mode'] ?? 'hybrid_auto'),
                'meituan_auto_fetch_mode' => $status['meituan_auto_fetch_mode'] ?? ($status['auto_fetch_mode'] ?? 'hybrid_auto'),
                'ctrip_section_concurrency' => $status['ctrip_section_concurrency'] ?? 3,
            ];

            $dueRuns = [];
            if (!empty($status['historical_enabled']) && $currentTime === (string)$status['historical_schedule_time']) {
                $dueRuns[] = [
                    'period' => 'historical_daily',
                    'data_date' => $yesterday,
                    'executed_key' => "online_data_historical_executed_{$hotelId}_{$yesterday}",
                    'executed_message' => '历史固定数据今天已执行',
                ];
            }
            $realtimeIntervalHours = (int)($status['realtime_schedule_interval_hours'] ?? $status['schedule_interval_hours'] ?? 2);
            if (!empty($status['realtime_enabled'])
                && $currentMinute === (int)$status['realtime_schedule_minute']
                && $this->isRealtimeAutoFetchHourDue((int)$currentHour, $realtimeIntervalHours)
            ) {
                $dueRuns[] = [
                    'period' => 'realtime_snapshot',
                    'data_date' => $today,
                    'executed_key' => "online_data_realtime_executed_{$hotelId}_{$today}_{$currentHour}",
                    'executed_message' => "实时快照本 {$realtimeIntervalHours} 小时窗口已执行",
                ];
            }
            if (empty($dueRuns)) {
                continue;
            }

            $lockKey = "online_data_profile_lock_{$hotelId}";
            $ranLockedTask = false;
            foreach ($dueRuns as $run) {
                if (cache($run['executed_key'])) {
                    $results[] = ['hotel_id' => $hotelId, 'hotel_name' => $hotel['name'], 'data_period' => $run['period'], 'status' => 'skipped', 'message' => $run['executed_message']];
                    continue;
                }
                if ($ranLockedTask || cache($lockKey)) {
                    $results[] = ['hotel_id' => $hotelId, 'hotel_name' => $hotel['name'], 'data_period' => $run['period'], 'status' => 'skipped_locked', 'message' => '同一 Profile 已有采集任务运行，本次跳过'];
                    continue;
                }

                cache($lockKey, true, 7200);
                $ranLockedTask = true;
                try {
                    $result = $this->executeAutoFetch($hotelId, $run['data_date'], array_merge($baseOptions, [
                        'data_period' => $run['period'],
                        'snapshot_time' => date('Y-m-d H:i:s'),
                    ]));
                    $results[] = [
                        'hotel_id' => $hotelId,
                        'hotel_name' => $hotel['name'],
                        'data_period' => $run['period'],
                        'status' => $result['success'] ? 'success' : 'failed',
                        'message' => $result['message']
                    ];

                    $this->updateFetchStatus($hotelId, (bool)$result['success'], (string)$result['message'], $run['data_date'], [
                        'saved_count' => (int)($result['saved_count'] ?? 0),
                        'auto_fetch_mode' => $result['auto_fetch_mode'] ?? null,
                        'platform_results' => $result['platform_results'] ?? [],
                        'data_period' => $run['period'],
                        'timing' => $result['timing'] ?? [],
                        'ctrip_section_concurrency' => $result['ctrip_section_concurrency'] ?? $baseOptions['ctrip_section_concurrency'] ?? 3,
                    ]);
                    cache($run['executed_key'], true, 86400);
                } finally {
                    \think\facade\Cache::delete($lockKey);
                }
            }
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
        $options['data_period'] = $this->normalizeOnlineDailyDataPeriod($options['data_period'] ?? $options['dataPeriod'] ?? '') ?: 'historical_daily';
        $options['snapshot_time'] = $this->normalizeOnlineDailyDateTime($options['snapshot_time'] ?? $options['snapshotTime'] ?? null) ?? date('Y-m-d H:i:s');
        $options['auto_fetch_mode'] = $this->resolveAutoFetchRunMode($hotelId, $options);
        $options['ctrip_auto_fetch_mode'] = $options['ctrip_auto_fetch_mode']
            ?? $options['ctripAutoFetchMode']
            ?? $options['auto_fetch_mode'];
        $options['meituan_auto_fetch_mode'] = $options['meituan_auto_fetch_mode']
            ?? $options['meituanAutoFetchMode']
            ?? $options['auto_fetch_mode'];
        $options['ctrip_section_concurrency'] = $this->normalizeCtripSectionConcurrency(
            $options['ctrip_section_concurrency'] ?? $options['ctripSectionConcurrency'] ?? $options['section_concurrency'] ?? $options['sectionConcurrency'] ?? 3
        );
        $platformResults = [];
        $totalSaved = 0;
        $attempted = 0;
        $successCount = 0;

        if ($this->hasCtripFetchConfigForHotel($hotelId)) {
            $attempted++;
            $this->updateAutoFetchRunningPlatformProgress($hotelId, 'ctrip', 'running', [
                'message' => '正在采集携程 Profile 与业务接口',
            ]);
            try {
                $result = $this->executeCtripAutoFetch($hotelId, $dataDate, $options);
            } catch (\Throwable $e) {
                \think\facade\Log::warning('Ctrip auto-fetch failed', ['hotel_id' => $hotelId, 'exception_type' => get_debug_type($e)]);
                $result = ['platform' => 'ctrip', 'success' => false, 'message' => 'ctrip_auto_fetch_failed', 'saved_count' => 0];
            }
            $this->updateAutoFetchRunningPlatformProgress(
                $hotelId,
                'ctrip',
                !empty($result['success']) ? 'success' : 'failed',
                [
                    'saved_count' => (int)($result['saved_count'] ?? 0),
                    'message' => (string)($result['message'] ?? ''),
                ]
            );
            $platformResults[] = $result;
            $totalSaved += (int)($result['saved_count'] ?? 0);
            if (!empty($result['success'])) {
                $successCount++;
            }
        } else {
            $this->updateAutoFetchRunningPlatformProgress($hotelId, 'ctrip', 'skipped', [
                'message' => '未配置携程凭证',
            ]);
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
            $this->updateAutoFetchRunningPlatformProgress($hotelId, 'meituan', 'running', [
                'message' => '正在采集美团 Profile 与业务接口',
            ]);
            try {
                $result = $this->executeMeituanAutoFetch($hotelId, $dataDate, $options);
            } catch (\Throwable $e) {
                \think\facade\Log::warning('Meituan auto-fetch failed', ['hotel_id' => $hotelId, 'exception_type' => get_debug_type($e)]);
                $result = ['platform' => 'meituan', 'success' => false, 'message' => 'meituan_auto_fetch_failed', 'saved_count' => 0];
            }
            $this->updateAutoFetchRunningPlatformProgress(
                $hotelId,
                'meituan',
                !empty($result['success']) ? 'success' : 'failed',
                [
                    'saved_count' => (int)($result['saved_count'] ?? 0),
                    'message' => (string)($result['message'] ?? ''),
                ]
            );
            $platformResults[] = $result;
            $totalSaved += (int)($result['saved_count'] ?? 0);
            if (!empty($result['success'])) {
                $successCount++;
            }
        } else {
            $message = '未配置美团 Partner ID / POI ID / Cookies';
            $this->updateAutoFetchRunningPlatformProgress($hotelId, 'meituan', 'skipped', [
                'message' => $message,
            ]);
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
                'data_period' => $options['data_period'],
                'auto_fetch_mode' => $options['auto_fetch_mode'],
                'auto_fetch_mode_label' => $this->autoFetchModeLabel((string)$options['auto_fetch_mode']),
                'platform_results' => $platformResults,
                'timing' => $this->mergeAutoFetchPlatformTiming($platformResults),
                'ctrip_section_concurrency' => $options['ctrip_section_concurrency'],
            ];
        }

        return [
            'success' => $successCount > 0,
            'message' => implode('；', $messages),
            'saved_count' => $totalSaved,
            'data_period' => $options['data_period'],
            'auto_fetch_mode' => $options['auto_fetch_mode'],
            'auto_fetch_mode_label' => $this->autoFetchModeLabel((string)$options['auto_fetch_mode']),
            'platform_results' => $platformResults,
            'timing' => $this->mergeAutoFetchPlatformTiming($platformResults),
            'ctrip_section_concurrency' => $options['ctrip_section_concurrency'],
        ];
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

    private function meituanAutoFetchConfigStatus(array $config, int $hotelId = 0): array
    {
        $hasPartnerId = trim((string)$this->firstAutoFetchConfigValue($config, ['partner_id', 'partnerId'], '')) !== '';
        $hasPoiId = trim((string)$this->firstAutoFetchConfigValue($config, ['poi_id', 'poiId'], '')) !== '';
        $hasProfileCookieSourceCandidate = !empty($config['profile_cookie_source'])
            || trim((string)($config['cookie_source'] ?? '')) === 'browser_profile'
            || $this->meituanProfileExistsForConfig($config);
        $profileSource = $hotelId > 0
            ? $this->loadProfileSessionSource('meituan', $hotelId, $this->meituanProfileStoreIdFromConfig($config))
            : null;
        $profileCookieMissing = $hasProfileCookieSourceCandidate
            ? $this->profileCookieSourceLoginMissingRequirements($profileSource ?? [])
            : [];
        $hasProfileCookieSource = $hasProfileCookieSourceCandidate && $profileCookieMissing === [];
        $hasCookies = $this->autoFetchCredentialReady($config) || $hasProfileCookieSource;
        $missingFields = [];
        $missingResourceFields = [];

        if (!$hasPartnerId) {
            $missingResourceFields[] = 'Partner ID';
        }
        if (!$hasPoiId) {
            $missingResourceFields[] = 'POI ID';
        }
        $missingFields = $missingResourceFields;
        if (!$hasCookies) {
            $missingFields[] = 'Cookies';
            $missingFields = array_values(array_unique(array_merge($missingFields, $profileCookieMissing)));
        }

        $credentialStatus = 'ready';
        if (!$hasCookies) {
            $credentialStatus = 'missing_cookie';
        } elseif (!empty($missingResourceFields)) {
            $credentialStatus = 'missing_resource_id';
        }
        $credentialStatusLabel = match ($credentialStatus) {
            'ready' => '可获取',
            'missing_cookie' => '缺少 Cookie',
            'missing_resource_id' => '需补充一次性门店标识',
            default => '待配置',
        };

        return [
            'api_configured' => empty($missingFields),
            'has_partner_id' => $hasPartnerId,
            'has_poi_id' => $hasPoiId,
            'has_cookies' => $hasCookies,
            'has_resource_id' => $hasPartnerId && $hasPoiId,
            'missing_fields' => $missingFields,
            'missing_text' => implode(' / ', $missingFields),
            'missing_resource_fields' => $missingResourceFields,
            'missing_resource_text' => implode(' / ', $missingResourceFields),
            'credential_level' => 'cookie_plus_resource_id',
            'credential_level_label' => '需一次性门店标识',
            'credential_status' => $credentialStatus,
            'credential_status_label' => $credentialStatusLabel,
            'has_profile_cookie_source' => $hasProfileCookieSource,
            'profile_cookie_source_candidate' => $hasProfileCookieSourceCandidate,
            'profile_cookie_missing_requirements' => $profileCookieMissing,
            'cookie_source' => $hasProfileCookieSource && !$this->autoFetchCredentialReady($config) ? 'browser_profile' : 'credential_vault',
            'daily_required_fields' => ['Cookie'],
            'one_time_required_fields' => ['Partner ID', 'POI ID'],
            'network_required_fields' => [],
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

    private function platformAutoFetchModeOptionsFromRequest(array $requestData): array
    {
        $options = [];
        foreach ([
            'ctrip_auto_fetch_mode',
            'ctripAutoFetchMode',
            'ctrip_auto_mode',
            'ctripAutoMode',
        ] as $key) {
            if (array_key_exists($key, $requestData) && trim((string)$requestData[$key]) !== '') {
                $options['ctrip_auto_fetch_mode'] = $this->normalizeAutoFetchMode($requestData[$key]);
                break;
            }
        }
        foreach ([
            'meituan_auto_fetch_mode',
            'meituanAutoFetchMode',
            'meituan_auto_mode',
            'meituanAutoMode',
        ] as $key) {
            if (array_key_exists($key, $requestData) && trim((string)$requestData[$key]) !== '') {
                $options['meituan_auto_fetch_mode'] = $this->normalizeAutoFetchMode($requestData[$key]);
                break;
            }
        }

        return $options;
    }

    private function autoFetchModeLabel(string $mode): string
    {
        return match ($this->normalizeAutoFetchMode($mode)) {
            'cookie_config' => 'Cookie/配置自动',
            'profile_browser' => '浏览器 Profile 自动采集',
            default => '接口直连自动',
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
        return $this->normalizeAutoFetchMode($mode) === 'profile_browser';
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

        return false;
    }

    private function shouldRunCtripProfileBrowser(string $mode, array $browserProfileSources): bool
    {
        $mode = $this->normalizeAutoFetchMode($mode);
        if ($mode === 'profile_browser') {
            return true;
        }

        return $mode === 'hybrid_auto' && $browserProfileSources !== [];
    }

    private function shouldRunCtripProfileBrowserForCost(string $mode, int $savedCount, array $browserProfileSources): bool
    {
        if ($this->normalizeAutoFetchMode($mode) === 'profile_browser') {
            return true;
        }

        return $this->shouldRunCtripProfileBrowser($mode, $browserProfileSources);
    }

    private function autoFetchStatusCode(array $result): string
    {
        if (!empty($result['success'])) {
            return 'ok';
        }

        $message = strtolower((string)($result['message'] ?? ''));
        if (!empty($result['skipped']) && str_contains($message, '当前策略')) {
            return 'skipped';
        }
        $isProfileBrowser = (string)($result['strategy'] ?? '') === 'profile_browser'
            || (string)($result['module'] ?? '') === 'browser_profile';
        if ($isProfileBrowser && (
            str_contains($message, 'login')
            || str_contains($message, 'timeout')
            || str_contains($message, '登录')
            || str_contains($message, '授权')
            || str_contains($message, '过期')
        )) {
            return 'needs_profile';
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

    private function buildAutoFetchConfigTaskPlan(int $hotelId, string $dataDate, array $ctripConfig, array $meituanConfig): array
    {
        $tasks = [];
        $startDate = $dataDate;
        $endDate = $dataDate;

        $ctripConfigId = $this->autoFetchConfigId($ctripConfig);
        if ($this->autoFetchCredentialReady($ctripConfig)) {
            $this->pushAutoFetchTask($tasks, [
                'platform' => 'ctrip',
                'module' => 'business',
                'label' => 'ctrip-business',
                'required' => ['config_id', 'url', 'node_id'],
                'body' => [
                    'config_id' => $ctripConfigId,
                    'url' => $this->autoFetchCtripRequestUrl($ctripConfig),
                    'node_id' => $this->autoFetchCtripNodeId($ctripConfig),
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'auto_save' => true,
                    'system_hotel_id' => $hotelId,
                ],
            ]);
        }

        $meituanConfigId = $this->autoFetchConfigId($meituanConfig);
        $meituanPartnerId = trim((string)$this->firstAutoFetchConfigValue($meituanConfig, ['partner_id', 'partnerId'], ''));
        $meituanPoiId = trim((string)$this->firstAutoFetchConfigValue($meituanConfig, ['poi_id', 'poiId'], ''));
        if ($this->autoFetchCredentialReady($meituanConfig) && $meituanPartnerId !== '' && $meituanPoiId !== '') {
            foreach (['P_RZ', 'P_XS', 'P_ZH', 'P_LL'] as $rankType) {
                $this->pushAutoFetchTask($tasks, [
                    'platform' => 'meituan',
                    'module' => 'ranking',
                    'label' => 'meituan-' . $rankType,
                    'required' => ['config_id', 'partner_id', 'poi_id'],
                    'body' => [
                        'config_id' => $meituanConfigId,
                        'url' => $this->firstAutoFetchConfigValue($meituanConfig, ['url'], 'https://eb.meituan.com/api/v1/ebooking/business/peer/rank/data/detail'),
                        'partner_id' => $meituanPartnerId,
                        'poi_id' => $meituanPoiId,
                        'store_id' => $this->meituanProfileStoreIdFromConfig($meituanConfig),
                        'rank_type' => $rankType,
                        'data_scope' => $this->firstAutoFetchConfigValue($meituanConfig, ['data_scope', 'dataScope'], 'vpoi'),
                        'date_range' => 'custom',
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'auto_save' => true,
                        'system_hotel_id' => $hotelId,
                    ],
                ]);
            }
        }

        return $tasks;
    }

    private function syncCtripBrowserProfileDataSourcesForAutoFetch(int $hotelId, string $dataDate, bool $interactiveBrowser, ?array $sources = null, array $periodOptions = []): array
    {
        $sources = $sources ?? $this->listEnabledCtripBrowserProfileDataSources($hotelId);
        $sources = $this->filterCollectableBrowserProfileDataSources($sources, 'ctrip');
        $sources = $this->selectCurrentBrowserProfileDataSources($sources);
        if (empty($sources)) {
            return [
                'attempted' => false,
                'success' => false,
                'saved_count' => 0,
                'message' => '',
            ];
        }

        $service = new PlatformDataSyncService();
        $savedCount = 0;
        $messages = [];
        $timing = [];
        foreach ($sources as $source) {
            $result = $service->syncDataSource($this->currentUser, (int)$source['id'], [
                'trigger_type' => 'auto_fetch',
                'data_date' => $dataDate,
                'interactive_browser' => $interactiveBrowser,
                'data_period' => $periodOptions['data_period'] ?? 'historical_daily',
                'snapshot_time' => $periodOptions['snapshot_time'] ?? date('Y-m-d H:i:s'),
                'ctrip_section_concurrency' => $periodOptions['ctrip_section_concurrency'] ?? 3,
            ]);
            $savedCount += (int)($result['saved_count'] ?? 0);
            $timing = $this->sumAutoFetchTiming($timing, is_array($result['timing'] ?? null) ? $result['timing'] : []);
            $messages[] = '数据源' . (int)$source['id'] . ': ' . (string)($result['message'] ?? $result['status'] ?? '-');
            $this->markCtripProfileStatusFromDataSourceSync($hotelId, $source, $result);
        }

        return [
            'attempted' => true,
            'success' => $savedCount > 0,
            'saved_count' => $savedCount,
            'data_period' => $periodOptions['data_period'] ?? 'historical_daily',
            'timing' => $timing,
            'message' => $savedCount > 0
                ? "携程 Profile 数据源同步成功 {$savedCount} 条"
                : '携程 Profile 数据源同步失败：' . implode('；', array_slice($messages, 0, 3)),
        ];
    }

    private function selectCurrentBrowserProfileDataSources(array $sources): array
    {
        $sources = array_values(array_filter($sources, static fn($source): bool => is_array($source)));
        return $sources === [] ? [] : [$sources[0]];
    }

    private function markCtripProfileStatusFromDataSourceSync(int $hotelId, array $source, array $result): void
    {
        if (($result['status'] ?? '') !== 'success' || (int)($result['saved_count'] ?? 0) <= 0) {
            return;
        }

        $config = $this->decodeBrowserProfileSourceConfig($source);
        $profileId = $this->ctripProfileStoreIdFromConfig($config, $hotelId);
        if ($profileId === '') {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $this->cachePlatformProfileStatus('ctrip', $hotelId, $profileId, [
            'checked_at' => $now,
            'last_captured_at' => $now,
            'auth_status' => [
                'ok' => true,
                'status' => 'logged_in',
                'message' => 'Ctrip browser Profile data-source sync succeeded.',
            ],
            'capture_gate' => null,
            'status_code' => 'logged_in',
            'data_source_id' => (int)($source['id'] ?? 0),
            'sync_task_id' => (int)($result['task_id'] ?? 0),
        ]);
    }

    private function executeCtripAutoFetch(int $hotelId, string $dataDate, array $options = []): array
    {
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

        if ($savedCount > 0) {
            \think\facade\Log::info("携程自动获取成功", ['hotel_id' => $hotelId, 'count' => $savedCount]);
            $this->updateCtripLatestFetchStatus($hotelId, date('Y-m-d H:i:s'), $dataDate, $savedCount);

            return ['platform' => 'ctrip', 'success' => true, 'message' => "完成 {$savedCount} 次写入操作；写入次数不等于唯一指标数，未返回字段保留为缺口", 'saved_count' => $savedCount, 'data_period' => $options['data_period'] ?? 'historical_daily', 'auto_fetch_mode' => $mode, 'mode_label' => $this->autoFetchModeLabel($mode), 'modules' => $modules, 'timing' => is_array($browserResult['timing'] ?? null) ? $browserResult['timing'] : []];
        }

        $message = empty($errors)
            ? '未获取到有效数据'
            : '未获取到有效数据：' . implode('；', array_slice($errors, 0, 3));
        return ['platform' => 'ctrip', 'success' => false, 'message' => $message, 'saved_count' => 0, 'data_period' => $options['data_period'] ?? 'historical_daily', 'auto_fetch_mode' => $mode, 'mode_label' => $this->autoFetchModeLabel($mode), 'modules' => $modules, 'timing' => is_array($browserResult['timing'] ?? null) ? $browserResult['timing'] : []];
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
            $interactiveBrowser
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
                ? "Profile 真实采集入库 {$savedCount} 条（" . implode('，', $detailParts) . "）" . ($captureGateWarning !== null ? '；字段覆盖率未达阈值，已保留诊断告警' : '')
                : 'Profile 真实采集未解析到可入库数据',
            'saved_count' => $savedCount,
            'row_count' => $rowCount,
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

        if ($expectedIds !== [] && $capturedIds !== [] && array_intersect($expectedIds, $capturedIds) === []) {
            return [
                'ok' => true,
                'status' => 'configured_platform_hotel_id_mismatch',
                'warning' => true,
                'message' => '携程返回酒店ID与已保存配置不一致；本次仍按当前选择门店归属并继续处理。请核对配置ID：' . implode('、', $expectedIds) . '；返回ID：' . implode('、', $capturedIds),
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
        foreach (is_array($payload['standard_rows'] ?? null) ? $payload['standard_rows'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!$this->isCtripCompetitorLikeValue($row)) {
                $this->addCtripPayloadHotelId($ids, $row['hotel_id'] ?? null);
            }
        }
        if ($ids !== []) {
            return array_keys($ids);
        }

        foreach (is_array($payload['responses'] ?? null) ? $payload['responses'] : [] as $response) {
            if (!is_array($response)) {
                continue;
            }
            foreach (['data', 'body', 'json'] as $key) {
                if (is_array($response[$key] ?? null)) {
                    $this->collectCtripPayloadSelfHotelIds($response[$key], $ids);
                }
            }
        }

        foreach (['business', 'traffic', 'catalog_facts'] as $section) {
            if (is_array($payload[$section] ?? null)) {
                $this->collectCtripPayloadSelfHotelIds($payload[$section], $ids);
            }
        }

        return array_keys($ids);
    }

    private function collectCtripPayloadSelfHotelIds(mixed $value, array &$ids, int $depth = 0): void
    {
        if ($depth > 8 || !is_array($value)) {
            return;
        }

        if ($this->isSequentialArray($value)) {
            foreach ($value as $item) {
                $this->collectCtripPayloadSelfHotelIds($item, $ids, $depth + 1);
            }
            return;
        }

        if (!$this->isCtripCompetitorLikeValue($value)) {
            foreach (['masterhotelid', 'masterHotelId', 'master_hotel_id', '_overview_source_hotel_id', 'hotelId', 'hotel_id', 'HotelId', 'hotelID'] as $key) {
                if (array_key_exists($key, $value)) {
                    $this->addCtripPayloadHotelId($ids, $value[$key]);
                }
            }
        }

        foreach ($value as $child) {
            if (is_array($child)) {
                $this->collectCtripPayloadSelfHotelIds($child, $ids, $depth + 1);
            }
        }
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

            $rowDataDate = $this->normalizeOnlineDataDate($row['data_date'] ?? '') ?: $dataDate;
            $dimension = trim((string)($row['dimension'] ?? '')) ?: 'catalog:' . ($captureSection ?: 'unknown');
            $rawData = $row['raw_data'] ?? $row;
            $rawDataForTrace = is_array($rawData) ? $rawData : [];
            if (is_array($rawData)) {
                $rawData['capture_section'] = $captureSection;
                $rawData['endpoint_id'] = (string)($row['endpoint_id'] ?? ($rawData['endpoint_id'] ?? ''));
                $sourceUrl = trim((string)($row['source_url'] ?? ($rawData['source_url'] ?? '')));
                if ($sourceUrl !== '') {
                    $rawData['source_url'] = $sourceUrl;
                }
                $rawData = $dataType === 'review'
                    ? $this->sanitizeOnlineReviewRawData($rawData)
                    : $this->sanitizeOnlineOrderRawData($rawData, $dataType === 'order');
                $rawDataForTrace = $rawData;
                $rawData = json_encode($rawData, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            } else {
                $rawData = (string)$rawData;
            }
            $platform = $this->normalizeCtripProfileTrafficPlatform((string)($row['platform'] ?? ''));
            $source = $this->sourceForCtripProfileTrafficPlatform((string)($row['source'] ?? ''), $platform);

            $standardRow = [
                'hotel_id' => $this->resolveCtripPlatformHotelId($row, $requestHotelId),
                'hotel_name' => trim((string)($row['hotel_name'] ?? '')),
                'system_hotel_id' => $systemHotelId,
                'source' => $source,
                'platform' => $platform,
                'data_date' => $rowDataDate,
                'data_type' => $dataType,
                'dimension' => $dimension,
                'amount' => (float)($row['amount'] ?? 0),
                'quantity' => (int)round((float)($row['quantity'] ?? 0)),
                'book_order_num' => (int)round((float)($row['book_order_num'] ?? 0)),
                'comment_score' => (float)($row['comment_score'] ?? 0),
                'qunar_comment_score' => (float)($row['qunar_comment_score'] ?? 0),
                'data_value' => (float)($row['data_value'] ?? 0),
                'compare_type' => trim((string)($row['compare_type'] ?? '')),
                'list_exposure' => (int)round((float)($row['list_exposure'] ?? 0)),
                'detail_exposure' => (int)round((float)($row['detail_exposure'] ?? 0)),
                'flow_rate' => (float)($row['flow_rate'] ?? 0),
                'order_filling_num' => (int)round((float)($row['order_filling_num'] ?? 0)),
                'order_submit_num' => (int)round((float)($row['order_submit_num'] ?? 0)),
                'ingestion_method' => in_array((string)($row['ingestion_method'] ?? ''), ['browser_profile', 'ctrip_cookie_api'], true)
                    ? (string)$row['ingestion_method']
                    : 'browser_profile',
                'source_trace_id' => $this->buildCtripStandardRowSourceTraceId(array_merge($row, ['source' => $source, 'platform' => $platform]), $captureSection, $dataType, $dimension, $rowDataDate, $rawDataForTrace),
                'raw_data' => $rawData,
            ];
            if ($dataSourceId !== null && $dataSourceId > 0) {
                $standardRow['data_source_id'] = $dataSourceId;
            }
            $rows[] = $standardRow;
        }

        return $rows;
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
            if ($exists) {
                Db::name('online_daily_data')->where('id', $exists['id'])->update($data);
            } else {
                Db::name('online_daily_data')->insert($data);
            }
            $savedCount++;
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

    private function executeMeituanAutoFetch(int $hotelId, string $dataDate, array $options = []): array
    {
        $config = $this->resolveMeituanFetchConfigForHotel($hotelId);
        $apiStatus = $this->meituanAutoFetchConfigStatus($config, $hotelId);
        $missingText = (string)$apiStatus['missing_text'];
        $mode = $this->resolvePlatformAutoFetchMode($config, $options, 'meituan');
        $runCookieConfig = $this->shouldRunCookieConfigTasks($mode);
        $runProfileBrowser = $this->shouldRunProfileBrowser($mode);
        $taskPlanForConfig = $this->buildAutoFetchConfigTaskPlan($hotelId, $dataDate, [], $config);
        $hasConfiguredTask = (bool)array_filter($taskPlanForConfig, static fn(array $task): bool => ($task['platform'] ?? '') === 'meituan');
        $hasProfile = $this->meituanProfileExistsForConfig($config);
        $hasProfileSeed = $this->meituanProfileStoreIdFromConfig($config) !== '';

        $hasDirectConfig = $hasConfiguredTask;
        $hasProfileConfig = $runProfileBrowser && ($hasProfile || $hasProfileSeed);
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
            $browserResult = $runProfileByCost
                ? $this->executeMeituanBrowserProfileAutoFetch($config, $hotelId, $dataDate, !empty($options['interactive_browser']), $options)
                : [
                    'success' => false,
                    'skipped' => true,
                    'message' => '当前策略未启动 Profile',
                    'saved_count' => 0,
                ];
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

        if ($savedCount > 0) {
            \think\facade\Log::info("美团自动获取成功", ['hotel_id' => $hotelId, 'count' => $savedCount]);
            return [
                'platform' => 'meituan',
                'success' => true,
                'message' => "完成 {$savedCount} 次写入操作；排名接口可能仅返回排名或百分比",
                'saved_count' => $savedCount,
                'data_period' => $options['data_period'] ?? 'historical_daily',
                'auto_fetch_mode' => $mode,
                'mode_label' => $this->autoFetchModeLabel($mode),
                'modules' => $modules,
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
            $dataDate
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

        return [
            'success' => $savedCount > 0,
            'message' => $savedCount > 0 ? "浏览器采集保存 {$savedCount} 条" : '浏览器采集未解析到指定日期数据',
            'saved_count' => $savedCount,
            'persistence_gate' => $persistenceGate,
        ];
    }

    /**
     * 从系统配置读取列表
     */

}
