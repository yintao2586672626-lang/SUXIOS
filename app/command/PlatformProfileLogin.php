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

        try {
            return $this->runLoginTask($taskId, $task, $request, $output);
        } finally {
            $this->releaseLock($lock);
        }
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
            'message' => ($platform === 'ctrip' ? '携程' : '美团') . '平台账号已登录，Profile 已保存',
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
        $isCtrip = $platform === 'ctrip';
        $config = $isCtrip
            ? [
                'profile_id' => $profileKey,
                'hotel_id' => trim((string)($request['hotel_id'] ?? $request['hotelId'] ?? $payload['hotel_id'] ?? '')),
                'hotel_name' => trim((string)($request['hotel_name'] ?? $request['hotelName'] ?? $payload['hotel_name'] ?? '')),
                'capture_sections' => $this->safeSections($request['capture_sections'] ?? $request['sections'] ?? 'core', 'core'),
            ]
            : [
                'store_id' => $profileKey,
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
        $cookies = trim((string)($request['cookies'] ?? $request['cookie'] ?? ''));
        if ($cookies !== '') {
            $payloadForSave['secret'] = ['cookies' => $cookies];
        }

        $systemUser = new class {
            public int $id = 1;
            public function isSuperAdmin(): bool
            {
                return true;
            }
        };

        return (new PlatformDataSyncService())->saveDataSource($systemUser, $payloadForSave);
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
