<?php
declare(strict_types=1);

namespace app\command;

use app\service\BrowserProfileCaptureRequestService;
use app\service\OtaFailureNotificationService;
use app\service\OtaProfileBindingService;
use app\service\OtaProfileSessionProofService;
use app\service\PlatformDataSyncService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;

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

        try {
            $this->assertOrClaimProfileBinding($hotelId, $platform, $profileKey, $request);
        } catch (\RuntimeException $e) {
            $this->writeTask($taskId, [
                'status' => 'failed',
                'status_code' => 'ota_profile_binding_blocked',
                'error_code' => 'ota_profile_binding_blocked',
                'message' => $e->getMessage(),
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            return 1;
        }

        $lock = $this->acquireLock($platform, $profileKey);
        if ($lock === null) {
            $this->writeTask($taskId, [
                'status' => 'failed',
                'status_code' => 'resource_busy_login',
                'error_code' => 'resource_busy_login',
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

    private function assertOrClaimProfileBinding(int $hotelId, string $platform, string $profileKey, array $request): void
    {
        $bindingService = new OtaProfileBindingService();
        try {
            $bindingService->assertBound($hotelId, $platform, $profileKey);
            return;
        } catch (\RuntimeException $e) {
            $localRebindAllowed = $this->truthy($request['allow_existing_local_profile_rebind'] ?? false);
            $bindDataSource = $this->truthy($request['bind_data_source'] ?? $request['bindDataSource'] ?? false);
            if (!$localRebindAllowed || !$bindDataSource) {
                throw $e;
            }
        }

        $bindingService->claim($hotelId, $platform, $profileKey, 0, true);
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
            'message' => ($platform === 'ctrip' ? '携程' : '美团') . '登录浏览器已打开，系统每 3 秒自动检测；登录成功后通常 10–15 秒内自动保存',
            'started_at' => (string)($task['started_at'] ?? date('Y-m-d H:i:s')),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $args = $this->buildCaptureArgs($platform, $request, $projectRoot, $outputPath);
        if ($args === []) {
            $failureProbe = ['performed' => false, 'status' => 'capture_failed'];
            $this->persistProfileSessionBlockFromRequest($request, $platform, $hotelId, $profileKey, $failureProbe, 'capture_failed');
            $this->finishFailed($taskId, $platform, $hotelId, $profileKey, 'Node.js 或采集脚本不可用', $outputPath, $logPath, [], null, $failureProbe);
            return 1;
        }

        $result = $this->runProcess($args, $projectRoot, $timeout, $logPath);
        if (!$this->restrictProfileLoginArtifactPermissions([$outputPath, $logPath])) {
            $failureProbe = ['performed' => false, 'status' => 'capture_failed'];
            $this->persistProfileSessionBlockFromRequest($request, $platform, $hotelId, $profileKey, $failureProbe, 'capture_failed');
            $this->finishFailed($taskId, $platform, $hotelId, $profileKey, '平台登录任务产物权限不安全，已拒绝继续处理', $outputPath, $logPath, [], null, $failureProbe);
            return 1;
        }
        if (!$result['success'] && !is_file($outputPath)) {
            $failureProbe = ['performed' => false, 'status' => 'capture_failed'];
            $this->persistProfileSessionBlockFromRequest($request, $platform, $hotelId, $profileKey, $failureProbe, 'capture_failed');
            $this->finishFailed($taskId, $platform, $hotelId, $profileKey, $result['message'], $outputPath, $logPath, [], null, $failureProbe);
            $output->writeln($result['message']);
            return 1;
        }

        $payload = is_file($outputPath) ? json_decode((string)file_get_contents($outputPath), true) : [];
        $payload = is_array($payload) ? $payload : [];
        $authStatus = is_array($payload['auth_status'] ?? null) ? $payload['auth_status'] : [];
        $sessionProbe = is_array($payload['session_probe'] ?? null) ? $payload['session_probe'] : [];
        $authStatusCode = strtolower(trim((string)($authStatus['status'] ?? '')));
        $sessionProofService = new OtaProfileSessionProofService();
        $sessionContractStatus = $sessionProofService->profileLoginSessionProbeContractStatus($sessionProbe);
        $sessionCollectable = $result['success']
            && !empty($authStatus['ok'])
            && in_array($authStatusCode, ['logged_in', 'authorized'], true)
            && $sessionProofService->isCollectableProfileLoginSessionProbe($sessionProbe);
        $loggedIn = $sessionCollectable
            && $sessionProofService->isStrongProfileLoginSessionProbe($sessionProbe);

        if (!$sessionCollectable) {
            $contractDrift = $sessionContractStatus === 'platform_contract_drift';
            $probeStatus = strtolower(trim((string)($sessionProbe['status'] ?? '')));
            $knownProbeStatus = in_array($probeStatus, [
                'anti_bot', 'cookies_incomplete', 'identity_mismatch', 'identity_unverified',
                'login_required', 'session_expired', 'login_expired', 'platform_contract_drift',
                'permission_denied', 'weak_evidence', 'probe_failed', 'capture_failed',
            ], true);
            $genericCaptureFailure = !$contractDrift && !$knownProbeStatus;
            $failureSessionProbe = $sessionProbe;
            if ($genericCaptureFailure) {
                $failureSessionProbe['performed'] = (bool)($sessionProbe['performed'] ?? false);
                $failureSessionProbe['status'] = 'capture_failed';
            }
            $message = $contractDrift
                ? '平台 Session 探针契约版本已变化，已安全阻断；请先校准平台规则，不要反复登录。'
                : ($genericCaptureFailure
                    ? 'Session 检查未返回可信探针证据，已安全阻断；请查看采集日志后重新检测。'
                    : trim((string)($sessionProbe['message'] ?? '')));
            if ($message === '') {
                $message = $platform === 'ctrip' ? '重新登录携程平台账号' : '重新登录美团平台账号';
            }
            $this->persistProfileSessionBlockFromRequest(
                $request,
                $platform,
                $hotelId,
                $profileKey,
                $failureSessionProbe,
                $contractDrift ? 'platform_contract_drift' : ''
            );
            $this->finishFailed(
                $taskId,
                $platform,
                $hotelId,
                $profileKey,
                $message,
                $outputPath,
                $logPath,
                $authStatus,
                $payload['capture_gate'] ?? null,
                $failureSessionProbe
            );
            return 1;
        }

        if (!$loggedIn) {
            $this->persistProfileSessionBlockFromRequest(
                $request,
                $platform,
                $hotelId,
                $profileKey,
                $sessionProbe,
                'identity_unverified'
            );
            $safeAuthStatus = $this->compactProfileLoginAuthStatus($authStatus);
            $safeSessionProbe = $this->compactProfileLoginSessionProbe($sessionProbe);
            $rawCaptureGate = is_array($payload['capture_gate'] ?? null) ? $payload['capture_gate'] : [];
            $safeCaptureGate = $rawCaptureGate !== [] ? $this->compactProfileLoginCaptureGate($rawCaptureGate) : null;
            $message = '平台账号 Session 可用，但尚未核验为当前目标门店；未写入门店级登录证明。请执行一次带门店身份校验的最小采集。';
            $partialStatus = $this->sanitizeProfileLoginCachePayload([
                'checked_at' => date('Y-m-d H:i:s'),
                'auth_status' => $safeAuthStatus,
                'session_probe' => $safeSessionProbe,
                'capture_gate' => $safeCaptureGate,
                'status_code' => 'hotel_identity_unverified',
                'output' => $outputPath,
            ]);
            Cache::set($this->profileStatusKey($platform, $hotelId, $profileKey), $partialStatus, 86400 * 30);
            $this->writeTask($taskId, [
                'status' => 'session_ready_identity_unverified',
                'status_code' => 'hotel_identity_unverified',
                'error_code' => '',
                'message' => $message,
                'finished_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
                'auth_status' => $safeAuthStatus,
                'session_probe' => $safeSessionProbe,
                'capture_gate' => $safeCaptureGate,
                'output' => $outputPath,
                'log' => $logPath,
            ]);
            return 0;
        }

        $dataSource = null;
        $dataSourceError = '';
        $bindDataSourceRequested = $this->truthy($request['bind_data_source'] ?? $request['bindDataSource'] ?? false);
        if ($bindDataSourceRequested) {
            try {
                $dataSource = $this->bindDataSource(
                    $platform,
                    $hotelId,
                    $profileKey,
                    $request,
                    $payload,
                    (bool)$result['success']
                );
            } catch (\Throwable $e) {
                $dataSourceError = $this->safeProfileLoginStatusText($e->getMessage());
            }
        }

        $safeAuthStatus = $this->compactProfileLoginAuthStatus($authStatus);
        $safeSessionProbe = $this->compactProfileLoginSessionProbe($sessionProbe);
        $rawCaptureGate = is_array($payload['capture_gate'] ?? null) ? $payload['capture_gate'] : [];
        $safeCaptureGate = $rawCaptureGate !== [] ? $this->compactProfileLoginCaptureGate($rawCaptureGate) : null;
        if ($bindDataSourceRequested && !is_array($dataSource)) {
            $this->writeTask($taskId, [
                'status' => 'failed',
                'status_code' => 'profile_login_persistence_failed',
                'error_code' => 'profile_login_persistence_failed',
                'message' => '登录页验证已通过，但 Profile 绑定或登录证明保存失败；本次不标记为已登录',
                'finished_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
                'auth_status' => $safeAuthStatus,
                'session_probe' => $safeSessionProbe,
                'capture_gate' => $safeCaptureGate,
                'output' => $outputPath,
                'log' => $logPath,
                'data_source' => null,
                'data_source_error' => $dataSourceError !== '' ? $dataSourceError : 'profile_login_proof_not_saved',
            ]);
            return 1;
        }
        $profileStatus = $this->sanitizeProfileLoginCachePayload([
            'checked_at' => date('Y-m-d H:i:s'),
            'auth_status' => $safeAuthStatus,
            'session_probe' => $safeSessionProbe,
            'capture_gate' => $safeCaptureGate,
            'status_code' => 'logged_in',
            'output' => $outputPath,
        ]);

        try {
            (new OtaFailureNotificationService())->recordCollectionOutcome([
                'hotel_id' => $hotelId,
                'platform' => $platform,
                'data_date' => $this->profileLoginSyncTargetDate($request),
                'success' => true,
                'saved_count' => 0,
                'auth_verified' => true,
                'actor_user_id' => (int)($request['user_id'] ?? 0),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Resolve OTA auth strong reminder after verified Profile login failed', [
                'hotel_id' => $hotelId,
                'platform' => $platform,
                'exception_type' => get_debug_type($e),
            ]);
        }

        Cache::set($this->profileStatusKey($platform, $hotelId, $profileKey), $profileStatus, 86400 * 30);

        $this->writeTask($taskId, [
            'status' => 'logged_in',
            'status_code' => 'logged_in',
            'error_code' => '',
            'message' => ($platform === 'ctrip' ? '携程' : '美团') . '平台登录态已验证，Profile 已保存',
            'finished_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'auth_status' => $profileStatus['auth_status'],
            'session_probe' => $profileStatus['session_probe'],
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
            $hotelProofVerified = $this->profileLoginDataSourceCurrentSessionVerified($sourceId);
            $this->writeTask($taskId, [
                'status' => $hotelProofVerified ? 'logged_in' : 'session_ready_identity_unverified',
                'status_code' => $hotelProofVerified ? 'logged_in' : 'hotel_identity_unverified',
                'status_text' => $hotelProofVerified ? '登录态与门店身份已验证' : 'Session 可用，门店身份待核验',
                'message' => $hotelProofVerified
                    ? $this->profileLoginSyncTaskMessage($platform, $result)
                    : '平台 Session 可用，但本次采集未形成目标门店身份匹配证据；未写入门店级登录证明。',
                'after_login_sync' => $result,
                'finished_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            $hotelProofVerified = $this->profileLoginDataSourceCurrentSessionVerified($sourceId);
            $this->writeTask($taskId, [
                'status' => $hotelProofVerified ? 'logged_in' : 'session_ready_identity_unverified',
                'status_code' => $hotelProofVerified ? 'logged_in' : 'hotel_identity_unverified',
                'status_text' => $hotelProofVerified ? '登录态与门店身份已验证' : 'Session 可用，门店身份待核验',
                'message' => ($platform === 'ctrip' ? '携程' : '美团')
                    . ($hotelProofVerified
                        ? '平台门店级登录证明已验证，但登录后同步未完成'
                        : '平台 Session 可用，但登录后门店身份校验未完成'),
                'after_login_sync' => [
                    'status' => 'failed',
                    'data_source_id' => $sourceId,
                    'target_date' => $this->profileLoginSyncTargetDate($request),
                    'message' => $this->safeProfileLoginStatusText($e->getMessage()),
                    'finished_at' => date('Y-m-d H:i:s'),
                    'sensitive_values_exposed' => false,
                ],
                'finished_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function profileLoginDataSourceCurrentSessionVerified(int $sourceId): bool
    {
        $source = Db::name('platform_data_sources')
            ->field('id,tenant_id,system_hotel_id,platform,ingestion_method,enabled,status,config_json,last_sync_status,last_error')
            ->where('id', $sourceId)
            ->find();
        return is_array($source) && (new OtaProfileSessionProofService())->isCurrentVerified($source);
    }

    private function syncDataSourceAfterProfileLogin(int $sourceId, string $platform, int $hotelId, array $request): array
    {
        $row = $this->loadProfileLoginDataSourceForSync($sourceId, $platform, $hotelId);
        $options = $this->buildProfileLoginSyncOptions((string)($row['platform'] ?? $platform), $request);
        $options = $this->constrainProfileLoginSyncOptionsBySource($options, $row);
        $result = (new PlatformDataSyncService())->syncDataSource($this->systemSyncUser(), $sourceId, $options);
        return $this->compactProfileLoginSyncResult($result, $sourceId, $options);
    }

    private function loadProfileLoginDataSourceForSync(int $sourceId, string $platform, int $hotelId): array
    {
        $row = Db::name('platform_data_sources')
            ->field('id,system_hotel_id,platform,ingestion_method,enabled,status,config_json')
            ->where('id', $sourceId)
            ->find();
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

    private function constrainProfileLoginSyncOptionsBySource(array $options, array $source): array
    {
        if (strtolower(trim((string)($source['platform'] ?? ''))) !== 'meituan') {
            return $options;
        }

        $sections = array_values(array_unique(array_filter(array_map(
            static fn(mixed $section): string => strtolower(trim((string)$section)),
            (array)($options['sections'] ?? [])
        ))));
        if (count($sections) <= 1 || !in_array('ads', $sections, true)) {
            return $options;
        }

        $config = $this->decodeSafeProfileSourceConfig((string)($source['config_json'] ?? ''));
        $adsUrl = trim((string)($config['ads_url'] ?? $config['adsUrl'] ?? ''));
        $adsReason = strtolower(trim((string)($config['ads_status_reason'] ?? '')));
        if ($adsUrl !== '' && !in_array($adsReason, ['ads_service_not_opened', 'ads_not_enabled'], true)) {
            return $options;
        }

        $sections = array_values(array_filter($sections, static fn(string $section): bool => $section !== 'ads'));
        $options['sections'] = $sections;
        $options['capture_sections'] = implode(',', $sections);
        $options['skipped_sections_no_entry'] = ['ads'];
        return $options;
    }

    private function buildProfileLoginSyncOptions(string $platform, array $request): array
    {
        $sectionsValue = $request['capture_sections']
            ?? $request['captureSections']
            ?? $request['sections']
            ?? ($platform === 'meituan' ? BrowserProfileCaptureRequestService::MEITUAN_DEFAULT_SECTIONS : 'traffic');
        $sections = $platform === 'meituan'
            ? BrowserProfileCaptureRequestService::normalizeMeituanProfileSections($sectionsValue)
            : $this->safeSections($sectionsValue, 'traffic');
        $sectionList = array_values(array_filter(explode(',', $sections), static fn(string $item): bool => trim($item) !== ''));
        if ($sectionList === []) {
            $sectionList = ['traffic'];
            $sections = 'traffic';
        }

        $dataDate = $this->profileLoginSyncTargetDate($request);
        $dataPeriod = trim((string)($request['data_period'] ?? $request['dataPeriod'] ?? ''));
        if ($dataPeriod === '') {
            $dataPeriod = $dataDate >= date('Y-m-d') ? 'realtime_snapshot' : 'historical_daily';
        }
        $snapshotTime = $dataPeriod === 'realtime_snapshot'
            ? (trim((string)($request['snapshot_time'] ?? $request['snapshotTime'] ?? '')) ?: date('Y-m-d H:i:s'))
            : '';

        return [
            'trigger_type' => 'profile_login_after_login',
            'data_date' => $dataDate,
            'capture_sections' => $sections,
            'sections' => $sectionList,
            'data_period' => $dataPeriod,
            'snapshot_time' => $snapshotTime,
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
            'message' => $this->safeProfileLoginStatusText((string)($result['message'] ?? '')),
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
        $postLoginWaitMs = max(0, min(600000, (int)($request['post_login_wait_ms'] ?? $request['postLoginWaitMs'] ?? 5000)));
        $args[] = '--interactive-login=' . ($postLoginWaitMs > 0 ? 'true' : 'false');
        $args[] = '--headless=false';
        $args[] = '--post-login-wait-ms=' . (string)$postLoginWaitMs;
        if ($platform === 'ctrip') {
            $profileId = trim((string)($request['profile_id'] ?? $request['profileId'] ?? $request['profile_key'] ?? ''));
            $hotelId = trim((string)($request['hotel_id'] ?? $request['hotelId'] ?? ''));
            $args[] = '--profile-id=' . $profileId;
            $args[] = '--system-hotel-id=' . (string)($request['system_hotel_id'] ?? '');
            $args[] = '--sections=' . $this->safeSections($request['sections'] ?? $request['capture_sections'] ?? 'business_overview', 'business_overview');
            $args[] = '--login-url=https://ebooking.ctrip.com/home/mainland';
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
            $args[] = '--sections=' . BrowserProfileCaptureRequestService::normalizeMeituanProfileSections($request['sections'] ?? $request['capture_sections'] ?? BrowserProfileCaptureRequestService::MEITUAN_DEFAULT_SECTIONS);
            $adsUrl = trim((string)($request['ads_url'] ?? $request['adsUrl'] ?? ''));
            if ($adsUrl !== '') {
                $args[] = '--ads-url=' . $adsUrl;
            }
            $dataPeriod = trim((string)($request['data_period'] ?? $request['dataPeriod'] ?? ''));
            if ($dataPeriod !== '') {
                $args[] = '--data-period=' . $dataPeriod;
            }
            $snapshotTime = trim((string)($request['snapshot_time'] ?? $request['snapshotTime'] ?? ''));
            if ($snapshotTime !== '') {
                $args[] = '--snapshot-time=' . $snapshotTime;
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

    private function restrictProfileLoginArtifactPermissions(array $paths): bool
    {
        foreach ($paths as $path) {
            $path = trim((string)$path);
            if ($path === '' || !is_file($path)) {
                continue;
            }
            if (!@chmod($path, 0600)) {
                @unlink($path);
                return false;
            }
        }
        return true;
    }

    private function persistProfileSessionBlockFromRequest(
        array $request,
        string $platform,
        int $hotelId,
        string $profileKey,
        array $sessionProbe,
        string $statusOverride = ''
    ): void {
        $sourceId = (int)($request['data_source_id'] ?? $request['source_id'] ?? 0);
        if ($sourceId <= 0) {
            return;
        }
        $status = strtolower(trim($statusOverride !== '' ? $statusOverride : (string)($sessionProbe['status'] ?? '')));
        $contractStatus = (new OtaProfileSessionProofService())->profileLoginSessionProbeContractStatus($sessionProbe);
        if ($contractStatus === 'platform_contract_drift') {
            $status = 'platform_contract_drift';
        } elseif (in_array($status, ['weak_evidence', 'probe_failed'], true)) {
            $status = 'capture_failed';
        }
        $allowedStatuses = [
            'anti_bot', 'cookies_incomplete', 'identity_mismatch', 'identity_unverified',
            'login_required', 'session_expired', 'login_expired', 'platform_contract_drift',
            'permission_denied', 'capture_failed',
        ];
        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'capture_failed';
        }

        try {
            (new OtaProfileSessionProofService())->recordProfileSessionBlocked(
                $sourceId,
                $hotelId,
                $platform,
                $profileKey,
                $status,
                (string)($sessionProbe['next_retry_at'] ?? '')
            );
        } catch (\Throwable $e) {
            Log::warning('Persist Profile session blocking state failed', [
                'data_source_id' => $sourceId,
                'hotel_id' => $hotelId,
                'platform' => $platform,
                'status' => $status,
                'exception_type' => get_debug_type($e),
            ]);
        }
    }

    private function finishFailed(
        string $taskId,
        string $platform,
        int $hotelId,
        string $profileKey,
        string $message,
        string $outputPath,
        string $logPath,
        array $authStatus = [],
        $captureGate = null,
        array $sessionProbe = []
    ): void
    {
        $statusCode = $this->profileLoginFailureStatusCode($message, $authStatus, $captureGate, $sessionProbe);
        $safeMessage = $this->safeProfileLoginStatusText($message);
        $safeAuthStatus = $this->compactProfileLoginAuthStatus($authStatus);
        $safeSessionProbe = $sessionProbe !== [] ? $this->compactProfileLoginSessionProbe($sessionProbe) : null;
        $safeCaptureGate = is_array($captureGate) ? $this->compactProfileLoginCaptureGate($captureGate) : null;
        Cache::set($this->profileStatusKey($platform, $hotelId, $profileKey), $this->sanitizeProfileLoginCachePayload([
            'checked_at' => date('Y-m-d H:i:s'),
            'auth_status' => $safeAuthStatus,
            'session_probe' => $safeSessionProbe,
            'capture_gate' => $safeCaptureGate,
            'status_code' => $statusCode,
            'output' => $outputPath,
        ]), 86400 * 30);

        $this->writeTask($taskId, [
            'status' => 'failed',
            'status_code' => $statusCode,
            'error_code' => $statusCode,
            'message' => $safeMessage,
            'finished_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'auth_status' => $safeAuthStatus,
            'session_probe' => $safeSessionProbe,
            'capture_gate' => $safeCaptureGate,
            'output' => $outputPath,
            'log' => $logPath,
        ]);
    }

    private function profileLoginFailureStatusCode(string $message, array $authStatus = [], $captureGate = null, array $sessionProbe = []): string
    {
        if ((new OtaProfileSessionProofService())->profileLoginSessionProbeContractStatus($sessionProbe) === 'platform_contract_drift') {
            return 'platform_contract_drift';
        }
        $probeStatus = strtolower(trim((string)($sessionProbe['status'] ?? '')));
        if ($probeStatus === 'anti_bot') {
            return 'anti_bot';
        }
        if ($probeStatus === 'platform_contract_drift') {
            return 'platform_contract_drift';
        }
        if ($probeStatus === 'cookies_incomplete') {
            return 'cookies_incomplete';
        }
        if ($probeStatus === 'permission_denied') {
            return 'permission_denied';
        }
        if ($probeStatus === 'capture_failed') {
            return 'capture_failed';
        }
        if ($probeStatus === 'identity_mismatch') {
            return 'hotel_mismatch';
        }
        if (!empty($sessionProbe['collectable']) && empty($sessionProbe['proof_eligible'])) {
            return 'hotel_identity_unverified';
        }
        if (in_array($probeStatus, ['weak_evidence', 'probe_failed'], true)) {
            return 'capture_failed';
        }
        $text = strtolower(json_encode([
            'message' => $message,
            'auth_status' => $authStatus,
            'capture_gate' => $captureGate,
            'session_probe' => $sessionProbe,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        if (preg_match('/anti[_-]?bot|captcha|verification_code|sms_code|required verification|slider|human verification|yoda|risk control|platform limit|rate limit|验证码|短信|人机|滑块|风控/', $text) === 1) {
            return 'anti_bot';
        }
        if (preg_match('/session_expired|session expired|session invalid|expired session/', $text) === 1) {
            return 'session_expired';
        }
        return 'login_expired';
    }

    private function bindDataSource(
        string $platform,
        int $hotelId,
        string $profileKey,
        array $request,
        array $payload,
        bool $loginProcessSucceeded
    ): ?array
    {
        $requestedSourceId = (int)($request['data_source_id'] ?? $request['source_id'] ?? 0);
        if ($requestedSourceId > 0) {
            return $this->markDataSourceProfileLoginVerified(
                $requestedSourceId,
                $platform,
                $hotelId,
                $profileKey,
                $request,
                $payload,
                $loginProcessSucceeded
            );
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
                'capture_sections' => BrowserProfileCaptureRequestService::normalizeMeituanProfileSections($request['capture_sections'] ?? $request['sections'] ?? BrowserProfileCaptureRequestService::MEITUAN_DEFAULT_SECTIONS),
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
        $saved = (new PlatformDataSyncService())->saveDataSource($this->systemSyncUser(), $payloadForSave);
        $savedSourceId = (int)($saved['id'] ?? 0);
        if ($savedSourceId <= 0) {
            throw new \RuntimeException('Profile data source was saved without an id.');
        }

        return $this->markDataSourceProfileLoginVerified(
            $savedSourceId,
            $platform,
            $hotelId,
            $profileKey,
            $request,
            $payload,
            $loginProcessSucceeded
        );
    }

    private function markDataSourceProfileLoginVerified(
        int $sourceId,
        string $platform,
        int $hotelId,
        string $profileKey,
        array $request,
        array $payload,
        bool $loginProcessSucceeded
    ): array
    {
        $row = Db::name('platform_data_sources')
            ->field('id,system_hotel_id,platform,data_type,ingestion_method,config_json,enabled,status,last_error,last_sync_status')
            ->where('id', $sourceId)
            ->find();
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
        $currentConfig = $this->decodeSafeProfileSourceConfig((string)($row['config_json'] ?? ''));
        $verifiedConfig = $this->buildProfileLoginVerifiedConfig($currentConfig, $platform, $profileKey, $request, $payload, $now);
        $this->assertProfileSourceMetadataIsSafe($verifiedConfig);
        $metadataPatch = [];
        foreach ($verifiedConfig as $key => $value) {
            if (!array_key_exists($key, $currentConfig) || $currentConfig[$key] !== $value) {
                $metadataPatch[$key] = $value;
            }
        }
        $authStatus = is_array($payload['auth_status'] ?? null) ? $payload['auth_status'] : [];
        $sessionProbe = is_array($payload['session_probe'] ?? null) ? $payload['session_probe'] : [];
        $proof = (new OtaProfileSessionProofService())->recordProfileLoginVerified(
            $sourceId,
            $hotelId,
            $platform,
            $profileKey,
            $loginProcessSucceeded,
            $authStatus,
            $sessionProbe,
            $metadataPatch
        );
        $clearStaleLoginError = $this->isStaleProfileLoginError((string)($row['last_error'] ?? ''));

        $update = [
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
            'current_session_verified' => (bool)($proof['current_session_verified'] ?? false),
            'current_session_probe_data_source_id' => (int)($proof['current_session_probe_data_source_id'] ?? 0),
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
        $sessionProbe = is_array($payload['session_probe'] ?? null) ? $payload['session_probe'] : [];
        if ($sessionProbe !== []) {
            $config['profile_login_session_probe'] = $this->compactProfileLoginSessionProbe($sessionProbe);
        }
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
        $status = trim((string)($authStatus['status'] ?? ''));
        $compact = [
            'ok' => (bool)($authStatus['ok'] ?? false),
            'status' => $status !== '' ? $status : 'unknown',
        ];
        if (array_key_exists('message', $authStatus)) {
            $compact['message'] = $this->safeProfileLoginStatusText((string)$authStatus['message']);
        }
        if (array_key_exists('timeout_ms', $authStatus) && is_numeric($authStatus['timeout_ms'])) {
            $compact['timeout_ms'] = max(0, (int)$authStatus['timeout_ms']);
        }
        if (array_key_exists('retry_after_seconds', $authStatus) && is_numeric($authStatus['retry_after_seconds'])) {
            $compact['retry_after_seconds'] = max(0, (int)$authStatus['retry_after_seconds']);
        }
        if (array_key_exists('next_retry_at', $authStatus) && trim((string)$authStatus['next_retry_at']) !== '') {
            $compact['next_retry_at'] = $this->safeProfileLoginStatusText((string)$authStatus['next_retry_at']);
        }
        return $compact;
    }

    private function profileLoginSessionProbeEligible(array $probe): bool
    {
        return (new OtaProfileSessionProofService())->isStrongProfileLoginSessionProbe($probe);
    }

    private function compactProfileLoginSessionProbe(array $probe): array
    {
        $compact = [
            'schema_version' => (int)($probe['schema_version'] ?? 0),
            'contract_version' => $this->safeProfileLoginStatusText((string)($probe['contract_version'] ?? '')),
            'mode' => $this->safeProfileLoginStatusText((string)($probe['mode'] ?? 'session_probe_only')),
            'platform' => $this->safeProfileLoginStatusText((string)($probe['platform'] ?? '')),
            'performed' => (bool)($probe['performed'] ?? false),
            'verified' => (bool)($probe['verified'] ?? false),
            'status' => $this->safeProfileLoginStatusText((string)($probe['status'] ?? 'unknown')),
            'collectable' => (bool)($probe['collectable'] ?? false),
            'proof_eligible' => (bool)($probe['proof_eligible'] ?? false),
            'evidence_type' => $this->safeProfileLoginStatusText((string)($probe['evidence_type'] ?? 'insufficient')),
            'evidence_level' => $this->safeProfileLoginStatusText((string)($probe['evidence_level'] ?? 'blocked')),
            'sensitive_values_exposed' => (bool)($probe['sensitive_values_exposed'] ?? true),
            'retry_after_seconds' => max(0, (int)($probe['retry_after_seconds'] ?? 0)),
            'next_retry_at' => $this->safeProfileLoginStatusText((string)($probe['next_retry_at'] ?? '')),
            'checked_at' => $this->safeProfileLoginStatusText((string)($probe['checked_at'] ?? '')),
            'message' => $this->safeProfileLoginStatusText((string)($probe['message'] ?? '')),
            'next_action' => $this->safeProfileLoginStatusText((string)($probe['next_action'] ?? '')),
        ];
        if (is_array($probe['failed_check_ids'] ?? null)) {
            $compact['failed_check_ids'] = array_values(array_slice(array_map(
                fn($value): string => mb_substr(trim((string)$value), 0, 80),
                $probe['failed_check_ids']
            ), 0, 20));
        }
        if (is_array($probe['proof_blocker_ids'] ?? null)) {
            $compact['proof_blocker_ids'] = array_values(array_slice(array_map(
                fn($value): string => mb_substr(trim((string)$value), 0, 80),
                $probe['proof_blocker_ids']
            ), 0, 20));
        }
        $signals = is_array($probe['signals'] ?? null) ? $probe['signals'] : [];
        $compact['signals'] = [
            'auth' => $this->compactProfileLoginProbeSignal($signals['auth'] ?? [], ['status', 'auth_status']),
            'url' => $this->compactProfileLoginProbeSignal($signals['url'] ?? [], ['status', 'trusted_host', 'business_path', 'login_path']),
            'page' => $this->compactProfileLoginProbeSignal($signals['page'] ?? [], ['status', 'business_marker_present', 'login_marker_present', 'session_expired_present', 'challenge_present', 'risk_control_present']),
            'session_state' => $this->compactProfileLoginProbeSignal($signals['session_state'] ?? [], ['status', 'platform_state_count', 'session_state_count']),
            'api' => $this->compactProfileLoginProbeSignal($signals['api'] ?? [], ['status', 'successful_response_count', 'candidate_drift_response_count', 'access_denied_response_count', 'authentication_required_response_count', 'permission_denied_response_count', 'rate_limited_response_count']),
            'identity' => $this->compactProfileLoginProbeSignal($signals['identity'] ?? [], ['status', 'hotel_scope_verified']),
        ];
        $drift = is_array($probe['drift_diagnostics'] ?? null) ? $probe['drift_diagnostics'] : [];
        if ($drift !== []) {
            $compact['drift_diagnostics'] = $this->compactProfileLoginProbeSignal($drift, [
                'contract_version', 'status', 'recognized_response_count', 'candidate_response_count', 'sensitive_values_exposed',
            ]);
            foreach (['signal_ids', 'advisory_signal_ids', 'candidate_reason_ids'] as $diagnosticIdKey) {
                $diagnosticIds = $this->compactProfileLoginProbeDiagnosticIds($drift[$diagnosticIdKey] ?? null);
                $compact['drift_diagnostics'][$diagnosticIdKey] = $diagnosticIds;
            }
            $candidateRouteSamples = $this->compactProfileLoginCandidateRouteSamples($drift['candidate_route_samples'] ?? null);
            $compact['drift_diagnostics']['candidate_route_samples'] = $candidateRouteSamples;
        }
        return $compact;
    }

    private function compactProfileLoginProbeDiagnosticIds($values): array
    {
        if (!is_array($values)) {
            return [];
        }
        $safe = [];
        foreach ($values as $value) {
            $normalized = strtolower(trim((string)$value));
            if ($normalized === '' || preg_match('/^[a-z][a-z0-9_.:-]{0,79}$/D', $normalized) !== 1) {
                continue;
            }
            $safe[$normalized] = true;
            if (count($safe) >= 20) {
                break;
            }
        }
        return array_keys($safe);
    }

    private function compactProfileLoginCandidateRouteSamples($values): array
    {
        if (!is_array($values)) {
            return [];
        }
        $safe = [];
        foreach ($values as $value) {
            $sample = $this->safeProfileLoginCandidateRouteSample((string)$value);
            if ($sample === '') {
                continue;
            }
            $safe[$sample] = true;
            if (count($safe) >= 20) {
                break;
            }
        }
        return array_keys($safe);
    }

    private function safeProfileLoginCandidateRouteSample(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $withoutQuery = preg_split('/[?#]/', $value, 2)[0] ?? '';
        $parsed = parse_url(str_contains($withoutQuery, '://') ? $withoutQuery : 'https://' . ltrim($withoutQuery, '/'));
        if (!is_array($parsed)) {
            return '';
        }
        $host = strtolower(trim((string)($parsed['host'] ?? '')));
        if ($host === '' || strlen($host) > 253 || str_contains($host, '..')
            || preg_match('/^[a-z0-9.-]+$/D', $host) !== 1) {
            return '';
        }

        $redactNextSegment = false;
        $segments = explode('/', (string)($parsed['path'] ?? ''));
        foreach ($segments as &$segment) {
            $decoded = $segment;
            for ($pass = 0; $pass < 2; $pass++) {
                $nextDecoded = rawurldecode($decoded);
                if ($nextDecoded === $decoded) {
                    break;
                }
                $decoded = $nextDecoded;
            }
            if ($redactNextSegment) {
                $segment = ':redacted';
                $redactNextSegment = false;
                continue;
            }
            if (str_contains($decoded, '/') || str_contains($decoded, '\\')
                || str_contains($decoded, '?') || str_contains($decoded, '#')
                || preg_match('/%(?:25|2f|5c|3f|23)/i', $decoded) === 1) {
                $segment = ':redacted';
                continue;
            }
            if ($this->isSensitiveProfileLoginRouteKey($decoded)) {
                $segment = strlen($decoded) >= 24 ? ':redacted' : strtolower($decoded);
                $redactNextSegment = true;
                continue;
            }
            $equalsPosition = strpos($decoded, '=');
            $key = $equalsPosition !== false ? substr($decoded, 0, $equalsPosition) : '';
            if ($key !== '' && $this->isSensitiveProfileLoginRouteKey($key)) {
                $segment = strlen($key) >= 24 ? ':redacted' : strtolower($key) . '=:redacted';
                continue;
            }
            $jwtLike = preg_match('/^[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}$/D', $decoded) === 1;
            $opaqueTokenLike = strlen($decoded) >= 24;
            if (preg_match('/^\d+$/D', $decoded) === 1
                || preg_match('/^[0-9a-f]{16,}$/iD', $decoded) === 1
                || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $decoded) === 1) {
                $segment = ':id';
                continue;
            }
            if ($jwtLike || $opaqueTokenLike) {
                $segment = ':redacted';
                continue;
            }
            $segment = preg_replace('/[^a-z0-9._~:@!$&\'()*+,;=%-]/i', '', $decoded) ?: '';
        }
        unset($segment);

        return mb_substr($host . implode('/', $segments), 0, 320);
    }

    private function isSensitiveProfileLoginRouteKey(string $value): bool
    {
        $key = trim($value);
        return preg_match('/^(?:cticket|(?:asp\.net_)?sessionid|jsessionid|session_?id|sid|auth_?token|access_?token|login_?token|passport|user_?ticket|ssoid|mt_c_token|_mtsi_eb_u|ctrip.*(?:ticket|session|auth|login)|(?:eb|ebk|ebooking).*(?:session|ticket|token))$/iD', $key) === 1
            || preg_match('/(?:^|[._-])(?:token|session|ticket|cookie|auth|authorization|password|secret|signature|api[-_]?key)(?:$|[._-])/i', $key) === 1
            || preg_match('/^(?:ssoid|passport|phpsessid|spidertoken|spiderkey|mtgsig|_mtsi_eb_u)$/iD', $key) === 1
            || preg_match('/(?:^|[._-])sid$/iD', $key) === 1;
    }

    private function compactProfileLoginProbeSignal($signal, array $allowedKeys): array
    {
        $signal = is_array($signal) ? $signal : [];
        $compact = [];
        foreach ($allowedKeys as $key) {
            if (!array_key_exists($key, $signal)) {
                continue;
            }
            $value = $signal[$key];
            if (is_bool($value)) {
                $compact[$key] = $value;
            } elseif (is_numeric($value) && $key !== 'status' && $key !== 'auth_status') {
                $compact[$key] = max(0, (int)$value);
            } else {
                $compact[$key] = $this->safeProfileLoginStatusText((string)$value);
            }
        }
        return $compact;
    }

    private function compactProfileLoginCaptureGate(array $gate): array
    {
        $compact = [];
        foreach (['status', 'mode', 'reason', 'next_retry_at'] as $key) {
            if (array_key_exists($key, $gate) && trim((string)$gate[$key]) !== '') {
                $compact[$key] = $this->safeProfileLoginStatusText((string)$gate[$key]);
            }
        }
        if (array_key_exists('retry_after_seconds', $gate) && is_numeric($gate['retry_after_seconds'])) {
            $compact['retry_after_seconds'] = max(0, (int)$gate['retry_after_seconds']);
        }
        if (is_array($gate['failed_check_ids'] ?? null)) {
            $compact['failed_check_ids'] = array_values(array_slice(array_map('strval', $gate['failed_check_ids']), 0, 20));
        }
        if (is_array($gate['checks'] ?? null)) {
            $compact['checks'] = array_values(array_slice(array_map(function ($check): array {
                $check = is_array($check) ? $check : [];
                return [
                    'id' => mb_substr(trim((string)($check['id'] ?? '')), 0, 80),
                    'status' => mb_substr(trim((string)($check['status'] ?? '')), 0, 40),
                    'message' => $this->safeProfileLoginStatusText((string)($check['message'] ?? '')),
                ];
            }, $gate['checks']), 0, 20));
        }
        return $compact;
    }

    private function safeProfileLoginStatusText(string $value): string
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

    private function sanitizeProfileLoginCachePayload(array $payload): array
    {
        $safe = [];
        foreach ($payload as $key => $value) {
            if (is_string($key) && $this->isSensitiveProfileLoginCacheKey($key)) {
                $safe[$key] = '[redacted]';
                continue;
            }
            if (is_array($value)) {
                $safe[$key] = $this->sanitizeProfileLoginCachePayload($value);
                continue;
            }
            if (is_string($value)) {
                $safe[$key] = $this->safeProfileLoginStatusText($value);
                continue;
            }
            $safe[$key] = is_scalar($value) || $value === null ? $value : '[object]';
        }
        return $safe;
    }

    private function isSensitiveProfileLoginCacheKey(string $key): bool
    {
        if ($this->isSensitiveProfileSourceMetadataKey($key)) {
            return true;
        }
        $normalized = strtolower((string)preg_replace('/[^a-z0-9]+/i', '_', trim($key)));
        $normalized = trim($normalized, '_');
        if (in_array($normalized, [
            'auth_status', 'credential_status', 'authorization_policy',
            'requires_explicit_authorization', 'has_cookies', 'cookie_configured',
        ], true)) {
            return false;
        }
        return preg_match('/(?:^|_)(?:raw_)?(?:cookies?|tokens?|auth_data|authorization|password|secret|api_key|headers?)(?:_|$)/i', $normalized) === 1;
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

        foreach ($rows as $row) {
            $config = $this->decodeSafeProfileSourceConfig((string)($row['config_json'] ?? ''));
            $candidate = $platform === 'ctrip'
                ? (string)($config['profile_id'] ?? $config['profileId'] ?? $config['hotel_id'] ?? '')
                : (string)($config['store_id'] ?? $config['storeId'] ?? $config['poi_id'] ?? '');
            if ($candidate !== '' && $candidate === $profileKey) {
                return (int)($row['id'] ?? 0);
            }
        }

        return 0;
    }

    private function decodeSafeProfileSourceConfig(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }
        try {
            $config = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Profile data source config is invalid; credential migration is required.');
        }
        if (!is_array($config)) {
            throw new \RuntimeException('Profile data source config is invalid; credential migration is required.');
        }

        $this->assertProfileSourceMetadataIsSafe($config);
        return $config;
    }

    private function assertProfileSourceMetadataIsSafe(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_string($key) && $this->isSensitiveProfileSourceMetadataKey($key)) {
                    throw new \RuntimeException('Profile data source contains legacy credential fields; credential migration is required.');
                }
                $this->assertProfileSourceMetadataIsSafe($item);
            }
            return;
        }
        if (is_string($value) && (
            preg_match('/["\']?(?:cookie|set-cookie|authorization|proxy-authorization|x-api-key|api-key|auth_data|token|access_token|refresh_token|spidertoken|spiderkey|mtgsig|usertoken|usersign|password)["\']?\s*[:=]/i', $value) === 1
            || preg_match('/\bbearer\s+[A-Za-z0-9._~+\/=:-]{8,}/i', $value) === 1
        )) {
            throw new \RuntimeException('Profile data source contains legacy credential material; credential migration is required.');
        }
        if (!is_scalar($value) && $value !== null) {
            throw new \RuntimeException('Profile data source config contains an unsupported value.');
        }
    }

    private function isSensitiveProfileSourceMetadataKey(string $key): bool
    {
        $normalized = strtolower((string)preg_replace('/[^a-z0-9]+/i', '_', trim($key)));
        $normalized = trim($normalized, '_');
        return in_array($normalized, [
            'cookies', 'cookie', 'auth_data', 'authorization', 'authorization_header',
            'token', 'spiderkey', 'spider_key', 'spidertoken', 'mtgsig', 'mtsi_eb_u',
            'usertoken', 'usersign', 'password', 'secret', 'api_key', 'secret_json',
            'auth_token', 'headers', 'headers_json', 'set_cookie', 'access_token',
            'refresh_token', 'encrypted_payload', 'ciphertext',
        ], true);
    }

    private function writeTask(string $taskId, array $patch): void
    {
        $current = Cache::get($this->taskKey($taskId), []);
        $current = is_array($current) ? $current : [];
        $merged = $this->sanitizeProfileLoginCachePayload(array_merge($current, $patch));
        Cache::set($this->taskKey($taskId), $merged, 86400);

        $currentKey = trim((string)($merged['current_key'] ?? ''));
        if ($currentKey !== '') {
            Cache::set($currentKey, $merged, 86400);
        }

        $platform = strtolower(trim((string)($merged['platform'] ?? '')));
        $hotelId = (int)($merged['system_hotel_id'] ?? 0);
        if (in_array($platform, ['ctrip', 'meituan'], true) && $hotelId > 0) {
            Cache::set($this->profileLoginHotelCurrentTaskKey($platform, $hotelId), $merged, 86400);
        }
    }

    private function taskKey(string $taskId): string
    {
        return 'platform_profile_login_task_' . $taskId;
    }

    private function profileLoginHotelCurrentTaskKey(string $platform, int $hotelId): string
    {
        return 'platform_profile_login_hotel_current_' . $platform . '_' . $hotelId;
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
