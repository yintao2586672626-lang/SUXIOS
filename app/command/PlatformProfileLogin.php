<?php
declare(strict_types=1);

namespace app\command;

use app\service\PlatformDataSyncService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use think\facade\Cache;
use think\facade\Db;

class PlatformProfileLogin extends Command
{
    protected function configure()
    {
        $this->setName('online-data:profile-login')
            ->addOption('task-id', null, Option::VALUE_REQUIRED, 'Profile login task id')
            ->addOption('input', null, Option::VALUE_REQUIRED, 'Profile login task input JSON')
            ->setDescription('Run OTA browser Profile login task in background');
    }

    protected function execute(Input $input, Output $output)
    {
        $taskId = trim((string)$input->getOption('task-id'));
        $inputPath = trim((string)$input->getOption('input'));
        if ($taskId === '' || $inputPath === '' || !is_file($inputPath)) {
            $output->writeln('Missing task input.');
            return 1;
        }

        $data = json_decode((string)file_get_contents($inputPath), true);
        if (!is_array($data)) {
            $this->writeTask($taskId, ['status' => 'failed', 'message' => '登录任务输入无法解析', 'finished_at' => date('Y-m-d H:i:s')]);
            return 1;
        }

        $task = is_array($data['task'] ?? null) ? $data['task'] : [];
        $request = is_array($data['request'] ?? null) ? $data['request'] : [];
        $platform = strtolower((string)($task['platform'] ?? $request['platform'] ?? ''));
        $hotelId = (int)($task['system_hotel_id'] ?? $request['system_hotel_id'] ?? 0);
        $profileKey = trim((string)($task['profile_key'] ?? $request['profile_key'] ?? ''));
        if (!in_array($platform, ['ctrip', 'meituan'], true) || $hotelId <= 0 || $profileKey === '') {
            $this->writeTask($taskId, ['status' => 'failed', 'message' => '登录任务参数不完整', 'finished_at' => date('Y-m-d H:i:s')]);
            return 1;
        }

        $lock = $this->acquireLock($platform, $profileKey);
        if ($lock === null) {
            $this->writeTask($taskId, [
                'status' => 'failed',
                'message' => '同一平台 Profile 登录或采集正在运行',
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            return 1;
        }

        $exitCode = 1;
        try {
            $exitCode = $this->runLoginTask($taskId, $task, $request, $output);
        } finally {
            $this->releaseLock($lock);
        }

        if ($exitCode === 0 && $this->shouldSyncDataSourceAfterProfileLogin($request)) {
            $this->runPostLoginDataSourceSync($taskId, $platform, $hotelId, $request);
        }

        return $exitCode;
    }

    private function runLoginTask(string $taskId, array $task, array $request, Output $output): int
    {
        $projectRoot = dirname(__DIR__, 2);
        $platform = strtolower((string)$task['platform']);
        $hotelId = (int)$task['system_hotel_id'];
        $profileKey = (string)$task['profile_key'];
        $outputPath = (string)$task['output'];
        $logPath = (string)$task['log'];
        $timeout = max(60, min(900, (int)($request['timeout_seconds'] ?? 600)));

        $this->writeTask($taskId, [
            'status' => 'browser_opened',
            'message' => ($platform === 'ctrip' ? '携程' : '美团') . '登录浏览器已打开，请在浏览器中完成平台验证',
            'started_at' => (string)($task['started_at'] ?? date('Y-m-d H:i:s')),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $args = $this->buildCaptureArgs($platform, $request, $projectRoot, $outputPath);
        if ($args === []) {
            $this->finishFailed($taskId, $platform, $hotelId, $profileKey, 'Node.js 或采集脚本不可用', $outputPath, $logPath);
            return 1;
        }

        $result = $this->runProcess($args, $projectRoot, $timeout, $logPath);
        if (!$result['success'] && !is_file($outputPath)) {
            $this->finishFailed($taskId, $platform, $hotelId, $profileKey, $result['message'], $outputPath, $logPath);
            $output->writeln($result['message']);
            return 1;
        }

        $payload = is_file($outputPath) ? json_decode((string)file_get_contents($outputPath), true) : [];
        $payload = is_array($payload) ? $payload : [];
        $authStatus = is_array($payload['auth_status'] ?? null) ? $payload['auth_status'] : [];
        $loggedIn = ($result['success'] || is_file($outputPath)) && ($authStatus === [] || !empty($authStatus['ok']));

        if (!$loggedIn) {
            $message = $platform === 'ctrip' ? '重新登录携程平台账号' : '重新登录美团平台账号';
            $this->finishFailed($taskId, $platform, $hotelId, $profileKey, $message, $outputPath, $logPath, $authStatus, $payload['capture_gate'] ?? null);
            return 1;
        }

        $dataSource = null;
        $dataSourceError = '';
        if ($this->truthy($request['bind_data_source'] ?? $request['bindDataSource'] ?? false)) {
            try {
                $dataSource = $this->bindDataSource($platform, $hotelId, $profileKey, $request, $payload);
            } catch (\Throwable $e) {
                $dataSourceError = $e->getMessage();
            }
        }

        $profileStatus = [
            'checked_at' => date('Y-m-d H:i:s'),
            'auth_status' => $authStatus !== [] ? $authStatus : ['ok' => true, 'status' => 'logged_in'],
            'capture_gate' => $payload['capture_gate'] ?? null,
            'status_code' => 'logged_in',
            'output' => $outputPath,
        ];
        Cache::set($this->profileStatusKey($platform, $hotelId, $profileKey), $profileStatus, 86400 * 30);

        $this->writeTask($taskId, [
            'status' => 'logged_in',
            'message' => ($platform === 'ctrip' ? '携程' : '美团') . '平台登录态已验证，Profile 已保存',
            'finished_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'auth_status' => $profileStatus['auth_status'],
            'capture_gate' => $profileStatus['capture_gate'],
            'output' => $outputPath,
            'log' => $logPath,
            'data_source' => $dataSource,
            'data_source_error' => $dataSourceError,
        ]);

        return 0;
    }

    private function runPostLoginDataSourceSync(string $taskId, string $platform, int $hotelId, array $request): void
    {
        $sourceId = (int)($request['data_source_id'] ?? $request['source_id'] ?? 0);
        $startedAt = date('Y-m-d H:i:s');
        if ($sourceId <= 0) {
            $this->writeTask($taskId, [
                'after_login_sync' => [
                    'status' => 'skipped',
                    'message' => '登录后同步需要 data_source_id',
                    'sensitive_values_exposed' => false,
                ],
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            return;
        }

        $this->writeTask($taskId, [
            'status' => 'syncing_after_login',
            'status_text' => '登录态已验证，正在同步目标日 OTA 数据',
            'message' => '平台登录态已验证，正在按数据源同步目标日 OTA 数据',
            'after_login_sync' => [
                'status' => 'running',
                'data_source_id' => $sourceId,
                'target_date' => $this->profileLoginSyncTargetDate($request),
                'started_at' => $startedAt,
                'sensitive_values_exposed' => false,
            ],
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        try {
            $result = $this->syncDataSourceAfterProfileLogin($sourceId, $platform, $hotelId, $request);
            $this->writeTask($taskId, [
                'status' => 'logged_in',
                'status_text' => '登录态已验证',
                'message' => $this->profileLoginSyncTaskMessage($platform, $result),
                'after_login_sync' => $result,
                'finished_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            $this->writeTask($taskId, [
                'status' => 'logged_in',
                'status_text' => '登录态已验证',
                'message' => ($platform === 'ctrip' ? '携程' : '美团') . '平台登录态已验证，但登录后同步未完成',
                'after_login_sync' => [
                    'status' => 'failed',
                    'data_source_id' => $sourceId,
                    'target_date' => $this->profileLoginSyncTargetDate($request),
                    'message' => mb_substr($e->getMessage(), 0, 300),
                    'finished_at' => date('Y-m-d H:i:s'),
                    'sensitive_values_exposed' => false,
                ],
                'finished_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function syncDataSourceAfterProfileLogin(int $sourceId, string $platform, int $hotelId, array $request): array
    {
        $row = $this->loadProfileLoginDataSourceForSync($sourceId, $platform, $hotelId);
        $options = $this->buildProfileLoginSyncOptions((string)($row['platform'] ?? $platform), $request);
        $result = (new PlatformDataSyncService())->syncDataSource($this->systemSyncUser(), $sourceId, $options);
        return $this->compactProfileLoginSyncResult($result, $sourceId, $options);
    }

    private function loadProfileLoginDataSourceForSync(int $sourceId, string $platform, int $hotelId): array
    {
        $row = Db::name('platform_data_sources')->where('id', $sourceId)->find();
        if (!$row || !is_array($row)) {
            throw new \RuntimeException('Data source not found.');
        }
        if (strtolower(trim((string)($row['platform'] ?? ''))) !== $platform) {
            throw new \RuntimeException('Data source platform mismatch.');
        }
        if ((int)($row['system_hotel_id'] ?? 0) !== $hotelId) {
            throw new \RuntimeException('Data source hotel scope mismatch.');
        }
        $method = strtolower(trim((string)($row['ingestion_method'] ?? '')));
        if (!in_array($method, ['browser_profile', 'profile_browser'], true)) {
            throw new \RuntimeException('Data source is not a browser Profile source.');
        }
        if ((int)($row['enabled'] ?? 1) !== 1 || strtolower(trim((string)($row['status'] ?? ''))) === 'disabled') {
            throw new \RuntimeException('Data source is disabled.');
        }
        return $row;
    }

    private function buildProfileLoginSyncOptions(string $platform, array $request): array
    {
        $sections = $this->safeSections(
            $request['capture_sections']
                ?? $request['captureSections']
                ?? $request['sections']
                ?? ($platform === 'meituan' ? 'traffic,orders' : 'traffic'),
            'traffic'
        );
        $sectionList = array_values(array_filter(explode(',', $sections), static fn(string $item): bool => trim($item) !== ''));
        if ($sectionList === []) {
            $sectionList = ['traffic'];
            $sections = 'traffic';
        }

        return [
            'trigger_type' => 'profile_login_after_login',
            'data_date' => $this->profileLoginSyncTargetDate($request),
            'capture_sections' => $sections,
            'sections' => $sectionList,
            'data_period' => trim((string)($request['data_period'] ?? $request['dataPeriod'] ?? 'historical_daily')) ?: 'historical_daily',
            'snapshot_time' => trim((string)($request['snapshot_time'] ?? $request['snapshotTime'] ?? '')) ?: date('Y-m-d H:i:s'),
            'interactive_browser' => false,
        ];
    }

    private function compactProfileLoginSyncResult(array $result, int $sourceId, array $options): array
    {
        return [
            'status' => (string)($result['status'] ?? 'unknown'),
            'data_source_id' => $sourceId,
            'task_id' => (int)($result['task_id'] ?? 0),
            'target_date' => (string)($options['data_date'] ?? ''),
            'capture_sections' => (string)($options['capture_sections'] ?? ''),
            'normalized_count' => (int)($result['normalized_count'] ?? 0),
            'saved_count' => (int)($result['saved_count'] ?? 0),
            'message' => mb_substr((string)($result['message'] ?? ''), 0, 300),
            'finished_at' => date('Y-m-d H:i:s'),
            'sensitive_values_exposed' => false,
        ];
    }

    private function profileLoginSyncTaskMessage(string $platform, array $result): string
    {
        $name = $platform === 'ctrip' ? '携程' : '美团';
        $saved = (int)($result['saved_count'] ?? 0);
        if ((string)($result['status'] ?? '') === 'success' && $saved > 0) {
            return $name . '平台登录态已验证，目标日 OTA 数据已同步入库 ' . $saved . ' 条';
        }
        return $name . '平台登录态已验证，登录后同步已执行但目标日入库行仍未闭环';
    }

    private function profileLoginSyncTargetDate(array $request): string
    {
        foreach (['data_date', 'dataDate', 'target_date', 'targetDate', 'date'] as $key) {
            $value = trim((string)($request[$key] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return $value;
            }
        }
        return date('Y-m-d');
    }

    private function shouldSyncDataSourceAfterProfileLogin(array $request): bool
    {
        return $this->truthy(
            $request['sync_after_login']
            ?? $request['syncAfterLogin']
            ?? $request['after_login_sync']
            ?? $request['afterLoginSync']
            ?? false
        );
    }

    private function buildCaptureArgs(string $platform, array $request, string $projectRoot, string $outputPath): array
    {
        $node = $this->resolveNodeBinary();
        if ($node === '') {
            return [];
        }

        $script = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR
            . ($platform === 'ctrip' ? 'ctrip_browser_capture.mjs' : 'meituan_browser_capture.mjs');
        if (!is_file($script)) {
            return [];
        }

        $loginTimeoutMs = (string)max(30000, min(600000, (int)($request['login_timeout_ms'] ?? 300000)));
        $args = [$node, $script, '--output=' . $outputPath, '--login-timeout-ms=' . $loginTimeoutMs, '--login-only=true'];
        $postLoginWaitMs = max(0, min(600000, (int)($request['post_login_wait_ms'] ?? $request['postLoginWaitMs'] ?? 120000)));
        $args[] = '--interactive-login=' . ($postLoginWaitMs > 0 ? 'true' : 'false');
        $args[] = '--headless=false';
        $args[] = '--post-login-wait-ms=' . (string)$postLoginWaitMs;
        if ($platform === 'ctrip') {
            $profileId = trim((string)($request['profile_id'] ?? $request['profileId'] ?? $request['profile_key'] ?? ''));
            $hotelId = trim((string)($request['hotel_id'] ?? $request['hotelId'] ?? ''));
            $args[] = '--profile-id=' . $profileId;
            $args[] = '--system-hotel-id=' . (string)($request['system_hotel_id'] ?? '');
            $args[] = '--sections=' . $this->safeSections($request['sections'] ?? $request['capture_sections'] ?? 'business_overview', 'business_overview');
            $args[] = '--login-url=https://ebooking.ctrip.com/login/index';
            if ($hotelId !== '') {
                $args[] = '--hotel-id=' . $hotelId;
            }
            $hotelName = trim((string)($request['hotel_name'] ?? $request['hotelName'] ?? ''));
            if ($hotelName !== '') {
                $args[] = '--hotel-name=' . $hotelName;
            }
        } else {
            $storeId = trim((string)($request['store_id'] ?? $request['storeId'] ?? $request['profile_key'] ?? ''));
            $poiId = trim((string)($request['poi_id'] ?? $request['poiId'] ?? $storeId));
            $args[] = '--store-id=' . $storeId;
            $args[] = '--system-hotel-id=' . (string)($request['system_hotel_id'] ?? '');
            if ($poiId !== '') {
                $args[] = '--poi-id=' . $poiId;
            }
            $poiName = trim((string)($request['poi_name'] ?? $request['poiName'] ?? ''));
            if ($poiName !== '') {
                $args[] = '--poi-name=' . $poiName;
            }
            $args[] = '--sections=' . $this->safeSections($request['sections'] ?? $request['capture_sections'] ?? 'traffic,orders', 'traffic,orders');
            $adsUrl = trim((string)($request['ads_url'] ?? $request['adsUrl'] ?? ''));
            if ($adsUrl !== '') {
                $args[] = '--ads-url=' . $adsUrl;
            }
        }

        $chromePath = $this->resolveChromePath();
        if ($chromePath !== '') {
            $args[] = '--chrome-path=' . $chromePath;
        }

        return $args;
    }

    private function runProcess(array $args, string $cwd, int $timeoutSeconds, string $logPath): array
    {
        $command = implode(' ', array_map('escapeshellarg', $args));
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $logPath, 'a'],
            2 => ['file', $logPath, 'a'],
        ];
        $process = proc_open($command, $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            return ['success' => false, 'message' => '无法启动浏览器登录进程'];
        }

        fclose($pipes[0]);
        $startedAt = time();
        $timedOut = false;
        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (time() - $startedAt > $timeoutSeconds) {
                $timedOut = true;
                proc_terminate($process);
                break;
            }
            sleep(1);
        }
        $exitCode = proc_close($process);
        if ($timedOut) {
            return ['success' => false, 'message' => '浏览器登录任务超时，请确认平台登录是否完成'];
        }
        if ($exitCode !== 0 && $exitCode !== -1) {
            return ['success' => false, 'message' => '浏览器登录任务失败，退出码 ' . $exitCode];
        }
        return ['success' => true, 'message' => 'ok'];
    }

    private function finishFailed(string $taskId, string $platform, int $hotelId, string $profileKey, string $message, string $outputPath, string $logPath, array $authStatus = [], $captureGate = null): void
    {
        Cache::set($this->profileStatusKey($platform, $hotelId, $profileKey), [
            'checked_at' => date('Y-m-d H:i:s'),
            'auth_status' => $authStatus,
            'capture_gate' => $captureGate,
            'status_code' => 'login_expired',
            'output' => $outputPath,
        ], 86400 * 30);

        $this->writeTask($taskId, [
            'status' => 'failed',
            'message' => $message,
            'finished_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'auth_status' => $authStatus,
            'capture_gate' => $captureGate,
            'output' => $outputPath,
            'log' => $logPath,
        ]);
    }

    private function bindDataSource(string $platform, int $hotelId, string $profileKey, array $request, array $payload): ?array
    {
        $requestedSourceId = (int)($request['data_source_id'] ?? $request['source_id'] ?? 0);
        if ($requestedSourceId > 0) {
            return $this->markDataSourceProfileLoginVerified($requestedSourceId, $platform, $hotelId, $profileKey, $request, $payload);
        }

        $isCtrip = $platform === 'ctrip';
        $config = $isCtrip
            ? [
                'profile_id' => $profileKey,
                'stable_profile_id' => $profileKey,
                'profile_binding_key' => $profileKey,
                'profile_reuse_scope' => 'ota_account_store',
                'profile_daily_reuse_enabled' => true,
                'hotel_id' => trim((string)($request['hotel_id'] ?? $request['hotelId'] ?? $payload['hotel_id'] ?? '')),
                'hotel_name' => trim((string)($request['hotel_name'] ?? $request['hotelName'] ?? $payload['hotel_name'] ?? '')),
                'capture_sections' => $this->safeSections($request['capture_sections'] ?? $request['sections'] ?? 'core', 'core'),
            ]
            : [
                'store_id' => $profileKey,
                'stable_profile_id' => $profileKey,
                'profile_binding_key' => $profileKey,
                'profile_reuse_scope' => 'ota_account_store',
                'profile_daily_reuse_enabled' => true,
                'poi_id' => trim((string)($request['poi_id'] ?? $request['poiId'] ?? $payload['poi_id'] ?? '')),
                'poi_name' => trim((string)($request['poi_name'] ?? $request['poiName'] ?? $payload['poi_name'] ?? '')),
                'partner_id' => trim((string)($request['partner_id'] ?? $request['partnerId'] ?? '')),
                'capture_sections' => $this->safeSections($request['capture_sections'] ?? $request['sections'] ?? 'traffic,orders', 'traffic,orders'),
            ];
        if (!$isCtrip && trim((string)($request['ads_url'] ?? $request['adsUrl'] ?? '')) !== '') {
            $config['ads_url'] = trim((string)($request['ads_url'] ?? $request['adsUrl']));
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
        return (new PlatformDataSyncService())->saveDataSource($this->systemSyncUser(), $payloadForSave);
    }

    private function markDataSourceProfileLoginVerified(int $sourceId, string $platform, int $hotelId, string $profileKey, array $request, array $payload): array
    {
        $row = Db::name('platform_data_sources')->where('id', $sourceId)->find();
        if (!$row || !is_array($row)) {
            throw new \RuntimeException('Data source not found.');
        }

        $sourcePlatform = strtolower(trim((string)($row['platform'] ?? '')));
        if ($sourcePlatform !== $platform) {
            throw new \RuntimeException('Data source platform mismatch.');
        }
        if ((int)($row['system_hotel_id'] ?? 0) !== $hotelId) {
            throw new \RuntimeException('Data source hotel scope mismatch.');
        }
        $method = strtolower(trim((string)($row['ingestion_method'] ?? '')));
        if (!in_array($method, ['browser_profile', 'profile_browser'], true)) {
            throw new \RuntimeException('Data source is not a browser Profile source.');
        }
        if ((int)($row['enabled'] ?? 1) !== 1 || strtolower(trim((string)($row['status'] ?? ''))) === 'disabled') {
            throw new \RuntimeException('Data source is disabled.');
        }

        $now = date('Y-m-d H:i:s');
        $config = json_decode((string)($row['config_json'] ?? ''), true);
        $config = is_array($config) ? $config : [];
        $config = $this->buildProfileLoginVerifiedConfig($config, $platform, $profileKey, $request, $payload, $now);
        $clearStaleLoginError = $this->isStaleProfileLoginError((string)($row['last_error'] ?? ''));

        $update = [
            'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => $this->dataSourceStatusAfterProfileLogin($row),
            'last_error' => $clearStaleLoginError ? null : ($row['last_error'] ?? null),
            'update_time' => $now,
        ];
        if ($clearStaleLoginError && in_array(strtolower(trim((string)($row['last_sync_status'] ?? ''))), ['waiting_config', 'failed', 'capture_failed'], true)) {
            $update['last_sync_status'] = null;
        }

        Db::name('platform_data_sources')->where('id', $sourceId)->update($update);

        return [
            'id' => $sourceId,
            'system_hotel_id' => $hotelId,
            'platform' => $platform,
            'data_type' => (string)($row['data_type'] ?? ''),
            'ingestion_method' => $method,
            'status' => (string)$update['status'],
            'manual_login_state_verified' => true,
            'login_state_verified' => true,
            'profile_login_verified' => true,
            'last_login_verified_at' => $now,
            'stale_login_error_cleared' => $clearStaleLoginError,
            'sensitive_values_exposed' => false,
        ];
    }

    private function buildProfileLoginVerifiedConfig(array $config, string $platform, string $profileKey, array $request, array $payload, string $now): array
    {
        $config['manual_login_state_verified'] = true;
        $config['login_state_verified'] = true;
        $config['profile_login_verified'] = true;
        $config['profile_status'] = 'logged_in';
        $config['login_status'] = 'logged_in';
        $config['last_profile_login_at'] = $now;
        $config['last_login_verified_at'] = $now;
        $config['profile_login_verified_at'] = $now;
        $config['profile_login_verified_by'] = 'platform_profile_login_task';
        $config['profile_login_verification_scope'] = 'browser_profile_session_only';
        $config['stable_profile_id'] = $profileKey;
        $config['profile_binding_key'] = $profileKey;
        $config['profile_reuse_scope'] = 'ota_account_store';
        $config['profile_daily_reuse_enabled'] = true;
        $config['profile_daily_reuse_entry'] = 'data-sources/:id/sync';
        $config['profile_login_probe_required_before_relogin'] = true;

        if ($platform === 'ctrip' && trim((string)($config['profile_id'] ?? $config['profileId'] ?? '')) === '') {
            $config['profile_id'] = $profileKey;
        }
        if ($platform === 'meituan' && trim((string)($config['store_id'] ?? $config['storeId'] ?? '')) === '') {
            $config['store_id'] = $profileKey;
        }

        $authStatus = is_array($payload['auth_status'] ?? null) ? $payload['auth_status'] : [];
        $config['auth_status'] = $this->compactProfileLoginAuthStatus($authStatus);
        $captureGate = is_array($payload['capture_gate'] ?? null) ? $payload['capture_gate'] : [];
        if ($captureGate !== []) {
            $config['profile_login_capture_gate'] = $this->compactProfileLoginCaptureGate($captureGate);
        }

        $sections = $request['capture_sections'] ?? $request['sections'] ?? null;
        if ($sections !== null && trim($this->safeSections($sections, '')) !== '') {
            $config['capture_sections'] = $this->safeSections($sections, (string)($config['capture_sections'] ?? ''));
        }

        return $config;
    }

    private function compactProfileLoginAuthStatus(array $authStatus): array
    {
        $compact = [
            'ok' => (bool)($authStatus['ok'] ?? true),
            'status' => trim((string)($authStatus['status'] ?? 'logged_in')) ?: 'logged_in',
        ];
        if (array_key_exists('message', $authStatus)) {
            $compact['message'] = mb_substr(trim((string)$authStatus['message']), 0, 200);
        }
        if (array_key_exists('timeout_ms', $authStatus) && is_numeric($authStatus['timeout_ms'])) {
            $compact['timeout_ms'] = max(0, (int)$authStatus['timeout_ms']);
        }
        return $compact;
    }

    private function compactProfileLoginCaptureGate(array $gate): array
    {
        $compact = [];
        foreach (['status', 'mode', 'reason'] as $key) {
            if (array_key_exists($key, $gate) && trim((string)$gate[$key]) !== '') {
                $compact[$key] = mb_substr(trim((string)$gate[$key]), 0, 120);
            }
        }
        if (is_array($gate['failed_check_ids'] ?? null)) {
            $compact['failed_check_ids'] = array_values(array_slice(array_map('strval', $gate['failed_check_ids']), 0, 20));
        }
        if (is_array($gate['checks'] ?? null)) {
            $compact['checks'] = array_values(array_slice(array_map(static function ($check): array {
                $check = is_array($check) ? $check : [];
                return [
                    'id' => mb_substr(trim((string)($check['id'] ?? '')), 0, 80),
                    'status' => mb_substr(trim((string)($check['status'] ?? '')), 0, 40),
                    'message' => mb_substr(trim((string)($check['message'] ?? '')), 0, 160),
                ];
            }, $gate['checks']), 0, 20));
        }
        return $compact;
    }

    private function dataSourceStatusAfterProfileLogin(array $source): string
    {
        $status = strtolower(trim((string)($source['status'] ?? '')));
        if (in_array($status, ['success', 'partial_success'], true)) {
            return $status;
        }
        return 'ready';
    }

    private function isStaleProfileLoginError(string $message): bool
    {
        $message = strtolower(trim($message));
        if ($message === '') {
            return false;
        }
        foreach ([
            'profile is not prepared',
            'profile_not_prepared',
            'profile directory',
            'login session is not ready',
            're-login',
            'login_required',
            'login expired',
            '登录',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function systemSyncUser(): object
    {
        return new class {
            public int $id = 1;
            public function isSuperAdmin(): bool
            {
                return true;
            }
        };
    }

    private function findBrowserProfileDataSourceId(int $hotelId, string $platform, string $profileKey): int
    {
        try {
            $rows = Db::name('platform_data_sources')
                ->where('system_hotel_id', $hotelId)
                ->where('platform', $platform)
                ->where('ingestion_method', 'browser_profile')
                ->where('status', '<>', 'disabled')
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            return 0;
        }

        foreach ($rows as $row) {
            $config = json_decode((string)($row['config_json'] ?? ''), true);
            $config = is_array($config) ? $config : [];
            $candidate = $platform === 'ctrip'
                ? (string)($config['profile_id'] ?? $config['profileId'] ?? $config['hotel_id'] ?? '')
                : (string)($config['store_id'] ?? $config['storeId'] ?? $config['poi_id'] ?? '');
            if ($candidate !== '' && $candidate === $profileKey) {
                return (int)($row['id'] ?? 0);
            }
        }

        return 0;
    }

    private function writeTask(string $taskId, array $patch): void
    {
        $current = Cache::get($this->taskKey($taskId), []);
        $current = is_array($current) ? $current : [];
        $merged = array_merge($current, $patch);
        Cache::set($this->taskKey($taskId), $merged, 86400);

        $currentKey = trim((string)($merged['current_key'] ?? ''));
        if ($currentKey !== '') {
            Cache::set($currentKey, $merged, 86400);
        }
    }

    private function taskKey(string $taskId): string
    {
        return 'platform_profile_login_task_' . $taskId;
    }

    private function profileStatusKey(string $platform, int $hotelId, string $profileKey): string
    {
        return 'platform_profile_status_' . $platform . '_' . $hotelId . '_' . $this->safeName($profileKey);
    }

    private function acquireLock(string $platform, string $profileKey)
    {
        $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'locks';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        $path = $dir . DIRECTORY_SEPARATOR . 'profile_capture_' . $platform . '_' . $this->safeName($profileKey) . '.lock';
        $handle = fopen($path, 'c+');
        if (!$handle) {
            return null;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        ftruncate($handle, 0);
        fwrite($handle, json_encode(['platform' => $platform, 'profile_key' => $profileKey, 'pid' => getmypid(), 'locked_at' => date('c')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $handle;
    }

    private function releaseLock($lock): void
    {
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function resolveNodeBinary(): string
    {
        $configured = trim((string)(getenv('NODE_BINARY') ?: env('NODE_BINARY', '')));
        $candidates = array_filter([
            $configured,
            'C:\\Program Files\\nodejs\\node.exe',
            'C:\\Program Files (x86)\\nodejs\\node.exe',
            getenv('USERPROFILE') ? getenv('USERPROFILE') . '\\.cache\\codex-runtimes\\codex-primary-runtime\\dependencies\\node\\bin\\node.exe' : '',
            DIRECTORY_SEPARATOR === '\\' ? 'node.exe' : 'node',
            'node',
        ]);

        foreach ($candidates as $candidate) {
            if (in_array($candidate, ['node', 'node.exe'], true) || is_file($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    private function resolveChromePath(): string
    {
        $configured = trim((string)(getenv('CHROME_PATH') ?: ''));
        return $configured !== '' && is_file($configured) ? $configured : '';
    }

    private function safeSections($sections, string $fallback): string
    {
        if (is_array($sections)) {
            $sections = implode(',', array_map(static fn($item): string => (string)$item, $sections));
        }
        $sections = (string)$sections;
        $sections = preg_replace('/[^a-zA-Z,_\-\s]+/', '', $sections) ?: '';
        $list = array_values(array_unique(array_filter(array_map(
            static fn($item): string => strtolower(trim((string)$item)),
            preg_split('/[,\s]+/', $sections) ?: []
        ))));
        return implode(',', $list) ?: $fallback;
    }

    private function safeName(string $value): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', trim($value));
        return trim((string)$safe, '_') ?: 'default';
    }

    private function truthy($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }
}
