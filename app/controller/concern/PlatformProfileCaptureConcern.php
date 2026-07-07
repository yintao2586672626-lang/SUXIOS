<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\service\BrowserProfileCaptureRequestService;

trait PlatformProfileCaptureConcern
{
    private function prepareCtripCookieApiCaptureFiles(array $requestData, string $projectRoot, ?int $systemHotelId): array
    {
        $config = $this->buildCtripCookieApiCaptureConfigFromRequest($requestData, $systemHotelId);
        $outputDir = $projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'ctrip_capture';
        if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
            throw new \InvalidArgumentException('无法创建携程 Cookie API 采集输出目录');
        }

        $profileId = BrowserProfileCaptureRequestService::safeFilePart((string)($config['profile_id'] ?? 'ctrip_cookie_api'));
        $prefix = $profileId . '_' . date('YmdHis');
        $inputPath = $outputDir . DIRECTORY_SEPARATOR . $prefix . '.input.json';
        $outputPath = $outputDir . DIRECTORY_SEPARATOR . $prefix . '.json';

        file_put_contents($inputPath, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL, LOCK_EX);

        return [
            'config' => $config,
            'input_path' => $inputPath,
            'output_path' => $outputPath,
        ];
    }

    private function buildCtripProfileStatus(array $requestData, ?int $systemHotelId = null, bool $probeCookie = false, bool $probeLogin = false): array
    {
        $hotelId = $systemHotelId !== null ? (int)$systemHotelId : 0;
        $profileId = $this->ctripProfileStoreIdFromConfig($requestData, $hotelId);
        $safeProfileId = $profileId !== '' ? BrowserProfileCaptureRequestService::safeFilePart($profileId) : '';
        $relativeDir = $safeProfileId !== '' ? 'storage/ctrip_profile_' . $safeProfileId : '';
        $projectRoot = dirname(__DIR__, 3);
        $profileDir = $relativeDir !== ''
            ? $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir)
            : '';
        $exists = $profileDir !== '' && is_dir($profileDir);
        $cached = ($hotelId > 0 && $profileId !== '') ? $this->readPlatformProfileStatusCache('ctrip', $hotelId, $profileId) : [];
        $cachedStatusCode = (string)($cached['status_code'] ?? '');
        $statusCode = $exists
            ? ($cachedStatusCode !== '' ? $cachedStatusCode : 'waiting_login')
            : 'waiting_login';

        $status = [
            'profile_id' => $profileId,
            'profile_dir' => $relativeDir,
            'exists' => $exists,
            'cookie_probe_requested' => $probeCookie,
            'login_probe_requested' => $probeLogin,
            'cookie_extractable' => false,
            'cookie_count' => 0,
            'skipped_count' => 0,
            'last_modified_at' => $exists ? date('Y-m-d H:i:s', (int)filemtime($profileDir)) : '',
            'last_login_check_time' => (string)($cached['checked_at'] ?? ''),
            'auth_status' => $cached['auth_status'] ?? null,
            'capture_gate' => $cached['capture_gate'] ?? null,
            'status' => $exists ? 'profile_found' : 'missing_profile',
            'status_code' => $statusCode,
            'current_status' => $this->ctripProfileStatusText($statusCode),
            'next_action' => $exists
                ? 'Profile 目录已发现；需点击“检测登录”确认 OTA 登录态，Cookie API 仅作临时诊断'
                : '点击“登录携程”保存 Profile；手动 Cookie/API 仅作临时排障',
        ];

        if ($exists && $probeLogin) {
            $probe = $this->runCtripProfileLoginProbe($requestData, $projectRoot, $hotelId, $profileId);
            $authStatus = is_array($probe['auth_status'] ?? null) ? $probe['auth_status'] : [
                'ok' => false,
                'status' => 'login_required',
                'message' => (string)($probe['message'] ?? 'Ctrip login probe failed.'),
            ];
            $isOk = !empty($authStatus['ok']);
            $probeStatusCode = $this->ctripProfileProbeStatusCode($probe, $authStatus);
            $status['auth_status'] = $authStatus;
            $status['capture_gate'] = $probe['capture_gate'] ?? null;
            $status['output'] = (string)($probe['output'] ?? '');
            $status['last_login_check_time'] = date('Y-m-d H:i:s');
            $status['status'] = $isOk ? 'ready' : 'login_required';
            $status['status_code'] = $probeStatusCode;
            $status['current_status'] = $this->ctripProfileStatusText((string)$status['status_code']);
            $status['next_action'] = $isOk
                ? 'profile_reuse_ready'
                : (string)$status['status_code'];

            if ($hotelId > 0 && $profileId !== '') {
                $this->cachePlatformProfileStatus('ctrip', $hotelId, $profileId, [
                    'checked_at' => $status['last_login_check_time'],
                    'auth_status' => $authStatus,
                    'capture_gate' => $status['capture_gate'],
                    'status_code' => $status['status_code'],
                    'output' => $status['output'],
                ]);
            }

            return $status;
        }

        if (!$exists || !$probeCookie) {
            return $status;
        }

        $requestData['profile_id'] = $profileId;
        try {
            $meta = $this->createCtripCookieApiCookieFileFromProfile($requestData, $projectRoot, $hotelId);
            $this->removeAutoFetchCookieFile((string)($meta['cookie_file'] ?? ''));
            $cookieCount = (int)($meta['cookie_count'] ?? 0);
            $status['cookie_extractable'] = $cookieCount > 0;
            $status['cookie_count'] = $cookieCount;
            $status['skipped_count'] = (int)($meta['skipped_count'] ?? 0);
            $status['status'] = $cookieCount > 0 ? 'ready' : 'profile_found_without_cookie';
            if ($cookieCount <= 0) {
                $status['status_code'] = 'cookies_incomplete';
            }
            $status['current_status'] = $this->ctripProfileStatusText((string)$status['status_code']);
            $status['next_action'] = $cookieCount > 0
                ? 'Cookie 可读取；仅代表临时 Cookie/API 诊断可尝试，不等于长期授权或采集成功'
                : 'Profile 存在但未提取到可用 Cookie；请先检测登录态或重新登录携程后台';
        } catch (\Throwable $e) {
            $status['status'] = 'profile_cookie_unreadable';
            $status['cookie_error'] = $this->trimMeituanCaptureLog($e->getMessage());
            $status['next_action'] = 'Profile 目录存在但 Cookie 不可读取；这不代表未登录，请关闭相关浏览器窗口后重试或重新检测登录态';
        }

        return $status;
    }

    private function ctripProfileStatusText(string $statusCode): string
    {
        return match ($statusCode) {
            'logged_in' => 'logged_in',
            'login_expired', 'login_required' => 'login_expired',
            'permission_denied' => 'permission_denied',
            'hotel_mismatch' => 'hotel_mismatch',
            'capture_failed' => 'capture_failed',
            'cookies_incomplete' => 'cookies_incomplete',
            'waiting_login' => 'waiting_login',
            default => 'unconfigured',
        };
    }

    private function ctripProfileProbeStatusCode(array $probe, array $authStatus): string
    {
        if (!empty($authStatus['ok'])) {
            return 'logged_in';
        }
        $text = strtolower(json_encode([
            'message' => $authStatus['message'] ?? $probe['message'] ?? '',
            'status' => $authStatus['status'] ?? '',
            'capture_gate' => $probe['capture_gate'] ?? null,
            'stdout' => $probe['stdout'] ?? '',
            'stderr' => $probe['stderr'] ?? '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        if (preg_match('/hotel_mismatch|store_mismatch|poi_mismatch|hotel scope mismatch|source hotel scope mismatch|门店不匹配|酒店不匹配/', $text) === 1) {
            return 'hotel_mismatch';
        }
        if (preg_match('/permission_denied|no_permission|forbidden|http\s*403|status\s*403|access\s*denied|not\s*authorized|无权|无权限|权限不足/', $text) === 1) {
            return 'permission_denied';
        }
        return 'login_expired';
    }

    private function runCtripProfileLoginProbe(array $requestData, string $projectRoot, int $systemHotelId, string $profileId): array
    {
        $scriptPath = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'ctrip_browser_capture.mjs';
        if (!is_file($scriptPath)) {
            return ['message' => 'ctrip_browser_capture_script_missing'];
        }
        $nodeBinary = BrowserProfileCaptureRequestService::resolveNodeBinary();
        if ($nodeBinary === '') {
            return ['message' => 'node_binary_missing'];
        }
        $outputDir = $projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'ctrip_capture';
        if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
            return ['message' => 'ctrip_login_probe_output_dir_unavailable'];
        }
        $safeProfileId = BrowserProfileCaptureRequestService::safeFilePart($profileId);
        $outputPath = $outputDir . DIRECTORY_SEPARATOR . 'ctrip_login_probe_' . $safeProfileId . '_' . date('YmdHis') . '.json';
        $args = [
            $nodeBinary,
            $scriptPath,
            '--profile-id=' . $profileId,
            '--system-hotel-id=' . (string)$systemHotelId,
            '--output=' . $outputPath,
            '--login-only=true',
            '--headless=true',
            '--login-timeout-ms=30000',
            '--sections=business_overview',
            '--login-url=https://ebooking.ctrip.com/home/mainland',
        ];
        $hotelId = trim((string)($requestData['hotel_id'] ?? $requestData['hotelId'] ?? $requestData['ctrip_hotel_id'] ?? $requestData['ctripHotelId'] ?? ''));
        if ($hotelId !== '') {
            $args[] = '--hotel-id=' . $hotelId;
        }
        $hotelName = trim((string)($requestData['hotel_name'] ?? $requestData['hotelName'] ?? ''));
        if ($hotelName !== '') {
            $args[] = '--hotel-name=' . $hotelName;
        }
        $chromePath = BrowserProfileCaptureRequestService::resolveChromePath();
        if ($chromePath !== '') {
            $args[] = '--chrome-path=' . $chromePath;
        }

        $runResult = $this->runMeituanCaptureProcess($args, $projectRoot, 90);
        if (!is_file($outputPath)) {
            return [
                'message' => (string)($runResult['message'] ?? 'ctrip_login_probe_output_missing'),
                'stdout' => $this->trimMeituanCaptureLog((string)($runResult['stdout'] ?? '')),
                'stderr' => $this->trimMeituanCaptureLog((string)($runResult['stderr'] ?? '')),
            ];
        }

        $payload = json_decode((string)file_get_contents($outputPath), true);
        if (!is_array($payload)) {
            return ['message' => 'ctrip_login_probe_json_invalid', 'output' => $outputPath];
        }
        $payload['output'] = $outputPath;
        return $payload;
    }
    private function buildMeituanProfileStatus(array $requestData, ?int $systemHotelId = null, bool $probeLogin = false): array
    {
        $hotelId = $systemHotelId !== null ? (int)$systemHotelId : 0;
        if ($hotelId > 0) {
            $source = $this->firstBrowserProfileSource($hotelId, 'meituan');
            $sourceConfig = $source ? $this->decodeBrowserProfileSourceConfig($source) : [];
            $requestData = array_merge($sourceConfig, $this->resolveMeituanFetchConfigForHotel($hotelId), $requestData);
        }

        $storeId = $this->meituanProfileStoreIdFromConfig($requestData);
        $safeStoreId = $storeId !== '' ? BrowserProfileCaptureRequestService::safeFilePart($storeId) : '';
        $relativeDir = $safeStoreId !== '' ? 'storage/meituan_profile_' . $safeStoreId : '';
        $projectRoot = dirname(__DIR__, 3);
        $profileDir = $relativeDir !== ''
            ? $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir)
            : '';
        $exists = $profileDir !== '' && is_dir($profileDir);
        $cached = ($hotelId > 0 && $storeId !== '') ? $this->readPlatformProfileStatusCache('meituan', $hotelId, $storeId) : [];

        $status = [
            'store_id' => $storeId,
            'poi_id' => (string)($requestData['poi_id'] ?? $requestData['poiId'] ?? ''),
            'profile_dir' => $relativeDir,
            'exists' => $exists,
            'login_probe_requested' => $probeLogin,
            'last_modified_at' => $exists ? date('Y-m-d H:i:s', (int)filemtime($profileDir)) : '',
            'last_login_check_time' => (string)($cached['checked_at'] ?? ''),
            'auth_status' => $cached['auth_status'] ?? null,
            'capture_gate' => $cached['capture_gate'] ?? null,
            'status' => $exists ? 'profile_found' : 'missing_profile',
            'current_status' => $exists ? '登录待验证' : '登录待验证',
            'status_code' => $exists ? 'waiting_login' : 'waiting_login',
            'next_action' => $exists
                ? 'Profile 目录已发现；需点击“检测登录”确认美团登录态'
                : '点击“登录美团”完成平台验证后复用 Profile',
        ];

        if (!$exists || !$probeLogin) {
            return $status;
        }

        $probe = $this->runMeituanProfileLoginProbe($requestData, $projectRoot, $hotelId, $storeId);
        $authStatus = is_array($probe['auth_status'] ?? null) ? $probe['auth_status'] : [
            'ok' => false,
            'status' => 'login_required',
            'message' => (string)($probe['message'] ?? 'Meituan login probe failed.'),
        ];
        $isOk = !empty($authStatus['ok']);
        $status['auth_status'] = $authStatus;
        $status['capture_gate'] = $probe['capture_gate'] ?? null;
        $status['output'] = $probe['output'] ?? '';
        $status['last_login_check_time'] = date('Y-m-d H:i:s');
        $status['status'] = $isOk ? 'ready' : 'login_required';
        $status['status_code'] = $isOk ? 'logged_in' : 'login_expired';
        $status['current_status'] = $isOk ? '登录态已验证' : '登录失效';
        $status['next_action'] = $isOk ? '登录态已验证；仍需执行目标日同步并检查入库结果' : '重新登录美团平台账号';

        if ($hotelId > 0 && $storeId !== '') {
            $this->cachePlatformProfileStatus('meituan', $hotelId, $storeId, [
                'checked_at' => $status['last_login_check_time'],
                'auth_status' => $authStatus,
                'capture_gate' => $status['capture_gate'],
                'status_code' => $status['status_code'],
                'output' => $status['output'],
            ]);
        }

        return $status;
    }

    private function runMeituanProfileLoginProbe(array $requestData, string $projectRoot, int $systemHotelId, string $storeId): array
    {
        $scriptPath = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'meituan_browser_capture.mjs';
        if (!is_file($scriptPath)) {
            return ['message' => '未找到美团浏览器抓取脚本'];
        }
        $nodeBinary = BrowserProfileCaptureRequestService::resolveNodeBinary();
        if ($nodeBinary === '') {
            return ['message' => '未找到 Node.js'];
        }
        $outputDir = $projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'meituan_capture';
        if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
            return ['message' => '无法创建美团登录检测输出目录'];
        }
        $outputPath = $outputDir . DIRECTORY_SEPARATOR . 'meituan_login_probe_' . BrowserProfileCaptureRequestService::safeFilePart($storeId) . '_' . date('YmdHis') . '.json';
        $args = [
            $nodeBinary,
            $scriptPath,
            '--store-id=' . $storeId,
            '--system-hotel-id=' . (string)$systemHotelId,
            '--output=' . $outputPath,
            '--login-only=true',
            '--headless=true',
            '--login-timeout-ms=30000',
            '--sections=traffic',
        ];
        $poiId = trim((string)($requestData['poi_id'] ?? $requestData['poiId'] ?? ''));
        if ($poiId !== '') {
            $args[] = '--poi-id=' . $poiId;
        }
        $chromePath = BrowserProfileCaptureRequestService::resolveChromePath();
        if ($chromePath !== '') {
            $args[] = '--chrome-path=' . $chromePath;
        }

        $runResult = $this->runMeituanCaptureProcess($args, $projectRoot, 90);
        if (!is_file($outputPath)) {
            return [
                'message' => (string)($runResult['message'] ?? '美团登录检测未生成结果文件'),
                'stdout' => $this->trimMeituanCaptureLog((string)($runResult['stdout'] ?? '')),
                'stderr' => $this->trimMeituanCaptureLog((string)($runResult['stderr'] ?? '')),
            ];
        }

        $payload = json_decode((string)file_get_contents($outputPath), true);
        if (!is_array($payload)) {
            return ['message' => '美团登录检测 JSON 无法解析', 'output' => $outputPath];
        }
        $payload['output'] = $outputPath;
        return $payload;
    }

    private function createCtripCookieApiCookieFileFromProfile(array $requestData, string $projectRoot, int $systemHotelId): array
    {
        $profileId = $this->ctripProfileStoreIdFromConfig($requestData, $systemHotelId);
        if ($profileId === '') {
            throw new \InvalidArgumentException('missing Ctrip Cookie and browser Profile ID');
        }

        $safeProfileId = BrowserProfileCaptureRequestService::safeFilePart($profileId);
        $profileDir = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'ctrip_profile_' . $safeProfileId;
        if (!is_dir($profileDir)) {
            throw new \InvalidArgumentException("missing Ctrip Cookie and storage/ctrip_profile_{$safeProfileId}");
        }

        $this->assertProfileCookieSourceLoginVerified($requestData, 'Ctrip');

        return $this->createPlatformCookieFileFromProfile('ctrip', $profileDir, $projectRoot, $profileId, 'ctrip_profile_' . $safeProfileId);
    }

    private function createMeituanCookieFileFromProfile(array $requestData, string $projectRoot, int $systemHotelId): array
    {
        $storeId = $this->meituanProfileStoreIdFromConfig($requestData);
        if ($storeId === '') {
            throw new \InvalidArgumentException('missing Meituan Cookie and browser Profile Store ID');
        }

        $safeStoreId = BrowserProfileCaptureRequestService::safeFilePart($storeId);
        $profileDir = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'meituan_profile_' . $safeStoreId;
        if (!is_dir($profileDir)) {
            throw new \InvalidArgumentException("missing Meituan Cookie and storage/meituan_profile_{$safeStoreId}");
        }

        $this->assertProfileCookieSourceLoginVerified($requestData, 'Meituan');

        return $this->createPlatformCookieFileFromProfile('meituan', $profileDir, $projectRoot, $storeId, 'meituan_profile_' . $safeStoreId);
    }

    private function assertProfileCookieSourceLoginVerified(array $config, string $platform): void
    {
        $missing = $this->profileCookieSourceLoginMissingRequirements($config);
        if ($missing === []) {
            return;
        }

        throw new \InvalidArgumentException(
            $platform . ' browser Profile Cookie source requires ' . implode(', ', $missing)
        );
    }

    private function profileCookieSourceLoginVerified(array $config): bool
    {
        return $this->profileCookieSourceLoginMissingRequirements($config) === [];
    }

    private function profileCookieSourceLoginMissingRequirements(array $config): array
    {
        $missing = [];
        if (!$this->isTruthyRequestValue($config['manual_login_state_verified'] ?? null)) {
            $missing[] = 'manual_login_state_verified';
        }

        $profileStatus = strtolower(trim((string)($config['profile_status'] ?? $config['login_status'] ?? '')));
        if (!in_array($profileStatus, ['logged_in', 'authorized'], true)) {
            $missing[] = 'profile_status_logged_in';
        }

        $lastVerifiedAt = trim((string)(
            $config['last_login_verified_at']
            ?? $config['profile_login_verified_at']
            ?? $config['last_profile_login_at']
            ?? $config['last_verified_at']
            ?? ''
        ));
        if ($lastVerifiedAt === '') {
            $missing[] = 'last_login_verified_at';
        }

        return $missing;
    }

    private function createPlatformCookieFileFromProfile(string $platform, string $profileDir, string $projectRoot, string $profileId, string $filePrefix): array
    {
        $extractor = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'extract_chromium_cookie_header.php';
        if (!is_file($extractor)) {
            throw new \InvalidArgumentException('missing Chromium Cookie extractor');
        }

        $phpBinary = $this->resolveLocalPhpBinary();
        if ($phpBinary === '') {
            throw new \InvalidArgumentException('missing PHP binary for Chromium Cookie extraction');
        }

        $dir = $projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'ota_cookie_injection';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \InvalidArgumentException('failed to create Cookie temp dir');
        }
        try {
            $suffix = bin2hex(random_bytes(6));
        } catch (\Throwable $e) {
            $suffix = str_replace('.', '', uniqid('', true));
        }
        $cookieFile = $dir . DIRECTORY_SEPARATOR . BrowserProfileCaptureRequestService::safeFilePart($filePrefix . '_' . $suffix) . '.txt';

        $runResult = $this->runMeituanCaptureProcess([
            $phpBinary,
            $extractor,
            '--profile-dir=' . $profileDir,
            '--output=' . $cookieFile,
            '--platform=' . $platform,
        ], $projectRoot, 30);
        if (!$runResult['success'] || !is_file($cookieFile)) {
            $this->removeAutoFetchCookieFile($cookieFile);
            throw new \InvalidArgumentException('failed to extract ' . $platform . ' Cookie from browser Profile: ' . $this->trimMeituanCaptureLog((string)($runResult['stderr'] ?? $runResult['stdout'] ?? '')));
        }

        $meta = json_decode(trim((string)($runResult['stdout'] ?? '')), true);
        if (!is_array($meta)) {
            $meta = [];
        }
        return [
            'cookie_file' => $cookieFile,
            'profile_id' => $profileId,
            'cookie_count' => (int)($meta['cookie_count'] ?? 0),
            'skipped_count' => (int)($meta['skipped_count'] ?? 0),
        ];
    }

    private function resolveLocalPhpBinary(): string
    {
        $configured = trim((string)(getenv('PHP_BINARY') ?: env('PHP_BINARY', '')));
        $candidates = array_filter([
            $configured,
            defined('PHP_BINARY') ? PHP_BINARY : '',
            'C:\\xampp\\php\\php.exe',
            'D:\\xampp\\php\\php.exe',
            'C:\\php\\php.exe',
            'php',
        ]);
        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate === '') {
                continue;
            }
            if ($candidate === 'php' || is_file($candidate)) {
                return $candidate;
            }
        }
        return '';
    }

    private function buildCtripCookieApiCaptureConfigFromRequest(array $requestData, ?int $systemHotelId): array
    {
        $dataDate = $this->normalizeOnlineDataDate($requestData['data_date'] ?? $requestData['dataDate'] ?? '');
        if ($dataDate === '') {
            $dataDate = date('Y-m-d');
        }
        $hotelId = trim((string)(
            $requestData['hotel_id']
            ?? $requestData['hotelId']
            ?? $requestData['ctrip_hotel_id']
            ?? $requestData['ctripHotelId']
            ?? ''
        ));
        $platformHotelId = trim((string)(
            $requestData['ota_hotel_id']
            ?? $requestData['otaHotelId']
            ?? $requestData['ctrip_hotel_id']
            ?? $requestData['ctripHotelId']
            ?? $requestData['platform_hotel_id']
            ?? $requestData['platformHotelId']
            ?? $hotelId
        ));
        if (!$this->isMeaningfulCtripPlatformHotelId($platformHotelId, (int)($systemHotelId ?? 0))) {
            $platformHotelId = '';
        }
        if ($hotelId === '' && $platformHotelId !== '') {
            $hotelId = $platformHotelId;
        }
        $profileId = trim((string)($requestData['profile_id'] ?? $requestData['profileId'] ?? $hotelId ?: 'ctrip_cookie_api'));
        $endpoints = $this->normalizeCtripCookieApiEndpointsFromRequest($requestData, $dataDate, $hotelId);
        if ($endpoints === []) {
            throw new \InvalidArgumentException('请提供携程接口 Request URL，或 endpoints/endpoints_json 接口清单');
        }

        return [
            'source' => 'ctrip_cookie_api',
            'profile_id' => $profileId,
            'hotel_id' => $hotelId,
            'ctrip_hotel_id' => $platformHotelId,
            'ctripHotelId' => $platformHotelId,
            'ota_hotel_id' => $platformHotelId,
            'platform_hotel_id' => $platformHotelId,
            'hotel_name' => trim((string)($requestData['hotel_name'] ?? $requestData['hotelName'] ?? '')),
            'system_hotel_id' => $systemHotelId,
            'data_date' => $dataDate,
            'endpoints' => $endpoints,
        ];
    }

    private function normalizeCtripCookieApiEndpointsFromRequest(array $requestData, string $dataDate = '', string $hotelId = ''): array
    {
        $raw = $requestData['endpoints']
            ?? $requestData['requests']
            ?? $requestData['request_urls']
            ?? $requestData['requestUrls']
            ?? $requestData['endpoints_json']
            ?? $requestData['endpointsJson']
            ?? null;

        $items = [];
        if ($raw !== null && $raw !== '') {
            if (is_array($raw)) {
                $items = $raw;
            } elseif (is_scalar($raw)) {
                $text = trim((string)$raw);
                if ($text !== '' && (str_starts_with($text, '[') || str_starts_with($text, '{'))) {
                    $decoded = $this->parseJsonParams($text);
                    $items = $this->isSequentialArray($decoded) ? $decoded : [$decoded];
                } else {
                    $items = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $text) ?: [])));
                }
            }
        }

        if ($items === []) {
            $singleUrl = trim((string)($requestData['request_url'] ?? $requestData['requestUrl'] ?? $requestData['url'] ?? ''));
            if ($singleUrl !== '') {
                $items[] = [
                    'request_url' => $singleUrl,
                    'method' => $requestData['method'] ?? 'GET',
                    'payload' => $this->readCtripEndpointEvidenceObject($requestData, [
                        'payload',
                        'payload_json',
                        'payloadJson',
                        'request_payload',
                        'requestPayload',
                        'request_payload_json',
                    ]),
                    'headers' => $this->readCtripEndpointEvidenceObject($requestData, [
                        'headers',
                        'headers_json',
                        'headersJson',
                        'request_headers',
                        'request_headers_json',
                        'requestHeadersJson',
                    ], true),
                ];
            }
        }

        $endpoints = [];
        foreach ($items as $item) {
            if (is_string($item)) {
                $item = ['request_url' => $item, 'method' => 'GET'];
            }
            if (!is_array($item)) {
                continue;
            }
            $requestUrl = trim((string)($item['request_url'] ?? $item['requestUrl'] ?? $item['url'] ?? ''));
            if ($requestUrl === '') {
                continue;
            }
            if (!$this->isAllowedOtaRequestUrl($requestUrl, ['ctrip.com', 'ctripbiz.com', 'ctripbiz.cn'])) {
                throw new \InvalidArgumentException('携程 Cookie API 采集仅允许 HTTPS 的 ctrip.com / ctripbiz.com / ctripbiz.cn 接口');
            }
            $method = strtoupper(trim((string)($item['method'] ?? $item['request_method'] ?? $item['requestMethod'] ?? 'GET')));
            if (!in_array($method, ['GET', 'POST'], true)) {
                throw new \InvalidArgumentException('携程 Cookie API 采集 method 仅支持 GET 或 POST');
            }
            $payload = $item['payload'] ?? $item['request_payload'] ?? $item['requestPayload'] ?? $item['params'] ?? [];
            if (is_string($payload) && trim($payload) !== '') {
                $payload = $this->parseJsonParams($payload);
            }
            if (!is_array($payload)) {
                $payload = [];
            }
            $payload = $this->normalizeCtripCookieApiPayloadDefaults((string)$requestUrl, $method, $payload, $dataDate, $hotelId);
            $headers = $item['headers'] ?? $item['request_headers'] ?? $item['requestHeaders'] ?? [];
            if (is_string($headers) && trim($headers) !== '') {
                $headers = str_starts_with(trim($headers), '{')
                    ? $this->parseJsonParams($headers)
                    : $this->parseCtripEndpointEvidenceHeaderLines($headers);
            }
            $endpoints[] = [
                'request_url' => $requestUrl,
                'method' => $method,
                'payload' => is_array($payload) ? $payload : [],
                'headers' => is_array($headers) ? $headers : [],
                'section' => trim((string)($item['section'] ?? $item['capture_section'] ?? $item['captureSection'] ?? '')),
            ];
        }

        return $endpoints;
    }

    private function normalizeCtripCookieApiPayloadDefaults(string $requestUrl, string $method, array $payload, string $dataDate = '', string $hotelId = ''): array
    {
        if ($method !== 'POST' || $payload !== []) {
            return $payload;
        }

        $window = $this->buildCtripCookieApiDateWindow($dataDate);
        $url = strtolower((string)$requestUrl);
        $path = strtolower((string)(parse_url($requestUrl, PHP_URL_PATH) ?? ''));
        $endpoint = $path === '' ? '' : strtolower(basename($path));

        $setIfMissing = static function (array &$target, string $key, mixed $value): void {
            if (!array_key_exists($key, $target) || $target[$key] === '') {
                $target[$key] = $value;
            }
        };
        $contains = static function (string $value, array $patterns) use ($url, $endpoint): bool {
            foreach ($patterns as $pattern) {
                if (str_contains($value, (string)$pattern)) {
                    return true;
                }
            }
            return false;
        };
        $hasDateWindow = isset($window['startDate']) && isset($window['endDate']);
        $setDateWindow = function (array &$target) use ($hasDateWindow, $window, $setIfMissing): void {
            if (!$hasDateWindow) {
                return;
            }
            $setIfMissing($target, 'startDate', $window['startDate']);
            $setIfMissing($target, 'endDate', $window['endDate']);
        };

        if (
            $contains($url, ['querymarketdetails', 'queryhotroom', 'queryordertrend', 'queryhoteloccupiedroomtrend', 'queryroomtensities', 'queryhoteltensities', 'querymarketroomtensity', 'queryroomoccupiedtrend'])
            || $contains($endpoint, ['querymarketdetails', 'queryhotroom', 'queryordertrend', 'queryhoteloccupiedroomtrend', 'queryroomtensities', 'queryhoteltensities', 'querymarketroomtensity', 'queryroomoccupiedtrend'])
        ) {
            $setIfMissing($payload, 'hostType', 'HE');
            $setIfMissing($payload, 'platform', 'EBK');
            $setDateWindow($payload);
            return $payload;
        }

        if (
            $contains($url, ['queryscanflowdetailsv2', 'queryflowtransformnewv1', 'queryflowtransfornewv1', 'queryflowtransfernewv1', 'queryflowtransform', 'queryflowsource', 'querycityhotkeywords', 'querysearchflowdetails'])
            || $contains($endpoint, ['queryscanflowdetailsv2', 'queryflowtransformnewv1', 'queryflowtransfornewv1', 'queryflowtransfernewv1', 'queryflowtransform', 'queryflowsource', 'querycityhotkeywords', 'querysearchflowdetails'])
        ) {
            $setIfMissing($payload, 'hostType', 'HE');
            $setIfMissing($payload, 'platform', 'EBK');
            $setDateWindow($payload);
            return $payload;
        }

        if (
            $contains($url, ['querycampaignsummaryreport'])
            || $contains($endpoint, ['querycampaignsummaryreport'])
        ) {
            $setIfMissing($payload, 'hostType', 'HE');
            $setIfMissing($payload, 'platform', 'EBK');
            $setIfMissing($payload, 'pageIndex', 1);
            $setIfMissing($payload, 'pageSize', 20);
            $setDateWindow($payload);
            return $payload;
        }

        if (
            $contains($url, ['queryuser', 'getuserimagelist', 'getorderdistribution'])
            || $contains($endpoint, ['queryusersex', 'queryusertype', 'queryuserpriceinfo', 'queryusersource', 'queryuserbookingdays', 'queryuserstaydays', 'queryuserfeatures', 'queryuserage', 'queryuserpoint', 'queryusertraveltime', 'queryuserstar', 'queryuserprice', 'queryordertype', 'queryuserorders', 'getuserimagelist', 'getorderdistribution'])
        ) {
            $setIfMissing($payload, 'hostType', 'HE');
            $setIfMissing($payload, 'platform', 'EBK');
            $setDateWindow($payload);
            $normalizedHotelId = trim((string)$hotelId);
            if ($normalizedHotelId !== '') {
                $setIfMissing($payload, 'hotelId', $normalizedHotelId);
                $setIfMissing($payload, 'nodeId', $normalizedHotelId);
            }
            return $payload;
        }

        if (
            $contains($url, ['getimindex', 'getimdatedistribute', 'getimsessiondistribute', 'getimorderconversionbyday', 'getimorderconversiondetail'])
            || $contains($endpoint, ['getimindex', 'getimdatedistribute', 'getimsessiondistribute', 'getimorderconversionbyday', 'getimorderconversiondetail'])
        ) {
            $setIfMissing($payload, 'hostType', 'HE');
            $setIfMissing($payload, 'platform', 'EBK');
            $setDateWindow($payload);
            $normalizedHotelId = trim((string)$hotelId);
            if ($normalizedHotelId !== '') {
                $setIfMissing($payload, 'hotelId', $normalizedHotelId);
                $setIfMissing($payload, 'nodeId', $normalizedHotelId);
            }
            return $payload;
        }

        if (
            $contains($url, ['getmanagementdata', 'getmasterhotellabel', 'getflowdata', 'getservicedata', 'getflowsource', 'gettripartiteorderloss', 'getlossordercompetehotel', 'getcompetingrank'])
            || $contains($endpoint, ['getmanagementdata', 'getmasterhotellabel', 'getflowdata', 'getservicedata', 'getflowsource', 'gettripartiteorderloss', 'getlossordercompetehotel', 'getcompetingrank'])
        ) {
            $setIfMissing($payload, 'hostType', 'HE');
            $setIfMissing($payload, 'platform', 'EBK');
            $setDateWindow($payload);
            $normalizedHotelId = trim((string)$hotelId);
            if ($normalizedHotelId !== '') {
                $setIfMissing($payload, 'hotelId', $normalizedHotelId);
                $setIfMissing($payload, 'nodeId', $normalizedHotelId);
            }
            return $payload;
        }

        if (
            $contains($url, ['getbbkcomprehensivetable'])
            || $contains($endpoint, ['getbbkcomprehensivetable'])
        ) {
            $setIfMissing($payload, 'hostType', 'HE');
            $aliasDate = $hasDateWindow ? $window['startDate'] : '';
            $setIfMissing($payload, 'date', $aliasDate);
            $setIfMissing($payload, 'reportDate', $aliasDate);
            return $payload;
        }

        if (
            $contains($url, ['datacenterbusinessreportdetail', 'datacentercomparisonreportdetail', 'datacentercomparatorreportdetail'])
            || $contains($endpoint, ['datacenterbusinessreportdetail', 'datacentercomparisonreportdetail', 'datacentercomparatorreportdetail'])
        ) {
            $setIfMissing($payload, 'hostType', 'HE');
            $setIfMissing($payload, 'platform', 'BBK');
            $setDateWindow($payload);
            $normalizedHotelId = trim((string)$hotelId);
            if ($normalizedHotelId !== '') {
                $setIfMissing($payload, 'hotelId', $normalizedHotelId);
                $setIfMissing($payload, 'nodeId', $normalizedHotelId);
            }
            return $payload;
        }

        return $payload;
    }

    private function buildCtripCookieApiDateWindow(string $dataDate): array
    {
        $dataDate = $this->normalizeOnlineDataDate($dataDate);
        if ($dataDate === '') {
            return [];
        }

        return [
            'startDate' => $dataDate,
            'endDate' => $dataDate,
        ];
    }

    private function prepareCtripEndpointEvidenceValidationFiles(array $requestData, string $projectRoot): array
    {
        $bundle = $this->buildCtripEndpointEvidenceBundleFromRequest($requestData);
        $outputDir = $projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'ctrip_endpoint_evidence';
        if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
            throw new \InvalidArgumentException('无法创建携程接口证据输出目录');
        }

        $path = parse_url((string)$bundle['request_url'], PHP_URL_PATH);
        $endpointName = $path ? basename((string)$path) : 'endpoint';
        $prefix = BrowserProfileCaptureRequestService::safeFilePart($endpointName ?: 'endpoint') . '_' . date('YmdHis');
        $inputPath = $outputDir . DIRECTORY_SEPARATOR . $prefix . '.input.json';
        $outputPath = $outputDir . DIRECTORY_SEPARATOR . $prefix . '.result.json';
        $markdownPath = $outputDir . DIRECTORY_SEPARATOR . $prefix . '.md';
        $candidatePath = $outputDir . DIRECTORY_SEPARATOR . $prefix . '.candidate.json';

        file_put_contents($inputPath, json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);

        return [
            'bundle' => $bundle,
            'input_path' => $inputPath,
            'output_path' => $outputPath,
            'markdown_path' => $markdownPath,
            'candidate_path' => $candidatePath,
        ];
    }

    private function buildCtripEndpointEvidenceBundleFromRequest(array $requestData): array
    {
        $requestUrl = trim((string)($requestData['request_url'] ?? $requestData['requestUrl'] ?? $requestData['url'] ?? ''));
        if ($requestUrl === '') {
            throw new \InvalidArgumentException('Request URL不能为空');
        }
        if (!$this->isAllowedOtaRequestUrl($requestUrl, ['ctrip.com', 'ctripbiz.com', 'ctripbiz.cn'])) {
            throw new \InvalidArgumentException('携程接口证据只允许 HTTPS 的 ctrip.com / ctripbiz.com / ctripbiz.cn 域名');
        }

        $method = strtoupper(trim((string)($requestData['method'] ?? $requestData['request_method'] ?? 'POST')));
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $method = 'POST';
        }

        return [
            'request_url' => $requestUrl,
            'method' => $method,
            'headers' => $this->redactCtripEndpointEvidenceValue($this->readCtripEndpointEvidenceObject($requestData, [
                'headers',
                'headers_json',
                'headersJson',
                'request_headers',
                'request_headers_json',
                'requestHeadersJson',
            ], true)),
            'payload' => $this->redactCtripEndpointEvidenceValue($this->readCtripEndpointEvidenceObject($requestData, [
                'payload',
                'payload_json',
                'payloadJson',
                'request_payload',
                'requestPayload',
                'request_payload_json',
            ])),
            'response' => $this->redactCtripEndpointEvidenceValue($this->readCtripEndpointEvidenceObject($requestData, [
                'response',
                'response_json',
                'responseJson',
                'preview',
                'preview_json',
                'previewJson',
                'response_preview',
                'responsePreview',
            ])),
            'page_context' => $this->redactCtripEndpointEvidenceValue($this->readCtripEndpointEvidenceObject($requestData, [
                'page_context',
                'pageContext',
                'page_context_json',
                'pageContextJson',
            ])),
            'params' => $this->redactCtripEndpointEvidenceValue($this->readCtripEndpointEvidenceObject($requestData, [
                'params',
                'parameters',
                'params_json',
                'paramsJson',
            ])),
        ];
    }

    private function readCtripEndpointEvidenceObject(array $requestData, array $keys, bool $allowHeaderLines = false): array
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $requestData)) {
                continue;
            }
            $value = $requestData[$key];
            if (is_array($value)) {
                return $value;
            }
            if (!is_scalar($value)) {
                continue;
            }
            $raw = trim((string)$value);
            if ($raw === '') {
                return [];
            }
            if ($allowHeaderLines && !str_starts_with($raw, '{') && !str_starts_with($raw, '[')) {
                return $this->parseCtripEndpointEvidenceHeaderLines($raw);
            }
            try {
                return $this->parseJsonParams($raw);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException($key . ' JSON格式不正确');
            }
        }

        return [];
    }

    private function parseCtripEndpointEvidenceHeaderLines(string $raw): array
    {
        $headers = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $line = trim((string)$line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $name = trim($name);
            if ($name !== '') {
                $headers[$name] = trim($value);
            }
        }
        return $headers;
    }

    private function readCtripCookieHeaderFromRequest(array $requestData): string
    {
        foreach (['cookies', 'cookie', 'cookie_header', 'cookieHeader'] as $key) {
            if (!array_key_exists($key, $requestData)) {
                continue;
            }
            $cookie = $this->normalizeCtripCookieHeaderText($requestData[$key]);
            if ($cookie !== '') {
                return $cookie;
            }
        }

        $headers = $this->readCtripEndpointEvidenceObject($requestData, [
            'headers',
            'headers_json',
            'headersJson',
            'request_headers',
            'request_headers_json',
            'requestHeadersJson',
        ], true);
        foreach ($headers as $name => $value) {
            $headerName = strtolower(trim((string)$name));
            if ($headerName !== 'cookie' && $headerName !== 'cookies') {
                continue;
            }
            $cookie = $this->normalizeCtripCookieHeaderText($value);
            if ($cookie !== '') {
                return $cookie;
            }
        }

        return '';
    }

    private function normalizeCtripCookieHeaderText(mixed $value): string
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $headerName = strtolower(trim((string)$key));
                if ($headerName !== 'cookie' && $headerName !== 'cookies') {
                    continue;
                }
                $cookie = $this->normalizeCtripCookieHeaderText($item);
                if ($cookie !== '') {
                    return $cookie;
                }
            }
            return '';
        }
        if (!is_scalar($value)) {
            return '';
        }

        $raw = trim((string)$value);
        if ($raw === '') {
            return '';
        }

        $firstChar = $raw[0] ?? '';
        if ($firstChar === '{' || $firstChar === '[') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $cookie = $this->normalizeCtripCookieHeaderText($decoded);
                if ($cookie !== '') {
                    return $cookie;
                }
            }
        }

        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        if (preg_match('/(?:^|\n)\s*cookie\s*:\s*([^\n]+)/i', $raw, $matches) === 1) {
            return $this->cleanCtripCookieHeaderCandidate((string)$matches[1]);
        }
        if (preg_match('/(?:^|\s)-H\s+(["\'])Cookie:\s*([^"\']+)\1/i', $raw, $matches) === 1) {
            return $this->cleanCtripCookieHeaderCandidate((string)$matches[2]);
        }
        if (str_contains($raw, "\n")) {
            return '';
        }

        $raw = trim($raw, " \t\n\r\0\x0B\"'");
        if (stripos($raw, 'cookie:') === 0) {
            $raw = trim(substr($raw, strlen('cookie:')));
        }
        if (stripos($raw, 'curl ') === 0) {
            return '';
        }

        return $this->cleanCtripCookieHeaderCandidate($raw);
    }

    private function cleanCtripCookieHeaderCandidate(string $candidate): string
    {
        $candidate = trim($candidate, " \t\n\r\0\x0B\"'");
        if ($candidate === '' || !str_contains($candidate, '=')) {
            return '';
        }
        return preg_replace('/\s*;\s*/', '; ', $candidate) ?? $candidate;
    }

    private function redactCtripEndpointEvidenceValue(mixed $value, string $key = ''): mixed
    {
        if ($this->isSensitiveCtripEndpointEvidenceKey($key)) {
            return '[REDACTED]';
        }
        if (is_array($value)) {
            $redacted = [];
            foreach ($value as $childKey => $childValue) {
                $redacted[$childKey] = $this->redactCtripEndpointEvidenceValue($childValue, (string)$childKey);
            }
            return $redacted;
        }
        if (is_string($value)) {
            if (preg_match('/1[3-9]\d{9}/', $value)) {
                return '[REDACTED]';
            }
            return mb_strlen($value) > 1000 ? mb_substr($value, 0, 1000) . '...' : $value;
        }
        return $value;
    }

    private function isSensitiveCtripEndpointEvidenceKey(string $key): bool
    {
        $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', $key) ?: '');
        if ($normalized === '') {
            return false;
        }
        foreach ([
            'cookie',
            'authorization',
            'token',
            'spidertoken',
            'usertoken',
            'usersign',
            'password',
            'passwd',
            'sign',
            'signature',
            'randomkey',
            'cticket',
            'guestname',
            'customername',
            'passengername',
            'guestphone',
            'mobile',
            'phone',
            'tel',
            'idcard',
            'credential',
            'certno',
            'orderid',
            'orderno',
            'ordercode',
        ] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function readLocalJsonFile(string $path): array
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException('JSON文件不存在: ' . $path);
        }
        $data = json_decode((string)file_get_contents($path), true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('JSON文件无法解析: ' . $path);
        }
        return $data;
    }

    private function buildCtripProfileCaptureConfigOptions(array $source, array $original = []): array
    {
        $sectionValue = $this->firstPresentCtripConfigValue(
            $source,
            ['profile_sections', 'capture_sections', 'captureSections'],
            $this->firstPresentCtripConfigValue($original, ['profile_sections', 'capture_sections', 'captureSections'], 'default')
        );
        $sections = $this->normalizeCtripProfileCaptureSections($sectionValue);
        $mappingPath = $this->firstPresentCtripConfigValue(
            $source,
            ['approved_mappings_path', 'approved_mapping_path', 'p3_mappings_path', 'approvedMappingsPath', 'approvedMappingPath', 'p3MappingsPath'],
            $this->firstPresentCtripConfigValue(
                $original,
                ['approved_mappings_path', 'approved_mapping_path', 'p3_mappings_path', 'approvedMappingsPath', 'approvedMappingPath', 'p3MappingsPath'],
                ''
            )
        );

        return [
            'capture_sections' => $sections,
            'profile_sections' => $sections,
            'approved_mappings_path' => trim(str_replace("\0", '', is_scalar($mappingPath) ? (string)$mappingPath : '')),
        ];
    }

    private function firstPresentCtripConfigValue(array $source, array $keys, mixed $default = ''): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $source)) {
                return $source[$key];
            }
        }
        return $default;
    }

    private function normalizeCtripProfileCaptureSections(mixed $value): string
    {
        $raw = is_array($value)
            ? implode(',', array_map(static fn($item): string => (string)$item, $value))
            : (string)$value;
        $items = preg_split('/[,\s]+/', strtolower($raw)) ?: [];
        $sections = [];
        foreach ($items as $item) {
            $item = trim($item);
            if ($item === '' || !preg_match('/^[a-z][a-z0-9_-]*$/', $item)) {
                continue;
            }
            $sections[$item] = true;
        }

        return implode(',', array_keys($sections)) ?: 'core';
    }

    private function resolveCtripApprovedMappingsPath(array $source, string $projectRoot): array
    {
        $rawPath = '';
        foreach ([
            'approved_mappings_path',
            'approved_mapping_path',
            'p3_mappings_path',
            'approvedMappingsPath',
            'approvedMappingPath',
            'p3MappingsPath',
        ] as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }
            $value = $source[$key];
            if (is_scalar($value)) {
                $rawPath = trim(str_replace("\0", '', (string)$value));
                if ($rawPath !== '') {
                    break;
                }
            }
        }

        if ($rawPath === '') {
            return ['configured' => false, 'path' => '', 'error' => ''];
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $rawPath) === 1) {
            return ['configured' => true, 'path' => '', 'error' => '携程 approved mapping 仅支持项目目录内的本地 JSON 文件'];
        }

        $root = realpath($projectRoot);
        if ($root === false || !is_dir($root)) {
            return ['configured' => true, 'path' => '', 'error' => '项目目录不可用，无法加载携程 approved mapping'];
        }

        $candidate = $this->isAbsoluteLocalPath($rawPath)
            ? $rawPath
            : $root . DIRECTORY_SEPARATOR . ltrim($rawPath, "\\/");
        $rootComparable = $this->normalizeLocalPathForComparison($root);
        $candidateComparable = $this->normalizeLocalPathForComparison($candidate);
        if (
            preg_match('/(^|[\\\\\/])\.\.([\\\\\/]|$)/', $rawPath) === 1
            || ($this->isAbsoluteLocalPath($rawPath)
                && $candidateComparable !== $rootComparable
                && !str_starts_with($candidateComparable, $rootComparable . '/'))
        ) {
            return ['configured' => true, 'path' => '', 'error' => '携程 approved mapping 必须位于项目目录内'];
        }

        $resolved = realpath($candidate);
        if ($resolved === false || !is_file($resolved)) {
            return ['configured' => true, 'path' => '', 'error' => '携程 approved mapping 文件不存在'];
        }

        $resolvedComparable = $this->normalizeLocalPathForComparison($resolved);
        if ($resolvedComparable !== $rootComparable && !str_starts_with($resolvedComparable, $rootComparable . '/')) {
            return ['configured' => true, 'path' => '', 'error' => '携程 approved mapping 必须位于项目目录内'];
        }

        if (strtolower(pathinfo($resolved, PATHINFO_EXTENSION)) !== 'json') {
            return ['configured' => true, 'path' => '', 'error' => '携程 approved mapping 必须是 JSON 文件'];
        }

        if (!is_readable($resolved)) {
            return ['configured' => true, 'path' => '', 'error' => '携程 approved mapping 文件不可读取'];
        }

        return ['configured' => true, 'path' => $resolved, 'error' => ''];
    }

    private function isAbsoluteLocalPath(string $path): bool
    {
        return preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
            || str_starts_with($path, '\\\\')
            || str_starts_with($path, '//')
            || str_starts_with($path, '/');
    }

    private function normalizeLocalPathForComparison(string $path): string
    {
        return strtolower(rtrim(str_replace('\\', '/', $path), '/'));
    }

    private function createAutoFetchCookieFile(string $projectRoot, string $platform, int $hotelId, string $cookies): string
    {
        $cookies = trim($cookies);
        if ($cookies === '') {
            return '';
        }

        $dir = $projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'ota_cookie_injection';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return '';
        }

        try {
            $suffix = bin2hex(random_bytes(6));
        } catch (\Throwable $e) {
            $suffix = str_replace('.', '', uniqid('', true));
        }

        $path = $dir . DIRECTORY_SEPARATOR . BrowserProfileCaptureRequestService::safeFilePart($platform . '_' . $hotelId . '_' . $suffix) . '.txt';
        return file_put_contents($path, $cookies, LOCK_EX) === false ? '' : $path;
    }

    private function removeAutoFetchCookieFile(string $path): void
    {
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    private function createCtripProfileFieldConfigFile(string $projectRoot, ?array $payload = null): string
    {
        $dir = $projectRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'ctrip_capture';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return '';
        }

        try {
            $suffix = bin2hex(random_bytes(6));
        } catch (\Throwable $e) {
            $suffix = str_replace('.', '', uniqid('', true));
        }

        $payload = $payload ?? $this->buildCtripProfileFieldConfigPayload($this->readCtripProfileCaptureFields(true));
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return '';
        }

        $path = $dir . DIRECTORY_SEPARATOR . 'ctrip_profile_field_config_' . $suffix . '.json';
        return file_put_contents($path, $json, LOCK_EX) === false ? '' : $path;
    }

    private function buildCtripProfileFieldConfigPayload(array $fields): array
    {
        $activeFields = [];
        $allowedKeys = [];
        $allowedSections = [];
        $moduleMap = $this->activeCtripProfileCaptureModuleMap();
        foreach ($fields as $field) {
            if (!is_array($field) || $this->isCtripProfileCaptureFieldDeleted($field) || empty($field['enabled'])) {
                continue;
            }
            $section = (string)($field['section'] ?? '');
            if ($section === '' || !isset($moduleMap[$section])) {
                continue;
            }
            $fieldKey = strtolower(trim((string)($field['field_key'] ?? '')));
            if ($fieldKey === '') {
                continue;
            }
            $allowedKeys[$fieldKey] = true;
            $allowedSections[$section] = true;
            $activeFields[] = [
                'id' => (string)($field['id'] ?? ''),
                'field_key' => $fieldKey,
                'field_name' => (string)($field['field_name'] ?? ''),
                'section' => $section,
                'data_type' => (string)($field['data_type'] ?? ''),
                'source_interface' => (string)($field['source_interface'] ?? ''),
                'source_keys' => (string)($field['source_keys'] ?? ''),
                'status' => (string)($field['status'] ?? ''),
            ];
        }

        return [
            'version' => self::CTRIP_PROFILE_FIELDS_CONFIG_VERSION,
            'source' => self::CTRIP_PROFILE_FIELDS_CONFIG_KEY,
            'generated_at' => date('Y-m-d H:i:s'),
            'allowed_sections' => array_keys($allowedSections),
            'allowed_field_keys' => array_keys($allowedKeys),
            'fields' => $activeFields,
        ];
    }

    private function resolveCtripProfileCaptureSectionsForRun(array $requestData, array $fieldConfigPayload, bool $loginOnly = false): array
    {
        $allowedSections = array_values(array_unique(array_filter(array_map(
            static fn($item): string => strtolower(trim((string)$item)),
            is_array($fieldConfigPayload['allowed_sections'] ?? null) ? $fieldConfigPayload['allowed_sections'] : []
        ), static fn(string $item): bool => $item !== '')));
        if (empty($allowedSections)) {
            return $loginOnly ? ['business_overview'] : [];
        }

        $sectionValue = $requestData['sections'] ?? $requestData['capture_sections'] ?? $requestData['captureSections'] ?? 'default';
        $requestedRaw = BrowserProfileCaptureRequestService::normalizeProfileSections($sectionValue, 'default');
        $presetTokens = ['default' => true, 'core' => true, 'wide' => true, 'all' => true];
        $tokens = array_values(array_unique(array_filter(
            array_map(static fn($item): string => strtolower(trim((string)$item)), explode(',', $requestedRaw)),
            static fn(string $item): bool => $item !== ''
        )));
        if (empty($tokens) || (count($tokens) === 1 && isset($presetTokens[$tokens[0]]))) {
            return $this->filterAllowedCtripProfilePresetSections($tokens[0] ?? 'default', $allowedSections);
        }

        $aliases = [
            'business' => 'business_overview',
            'overview' => 'business_overview',
            'outline' => 'business_overview',
            'weekly' => 'business_weekly_overview',
            'week' => 'business_weekly_overview',
            'sales' => 'sales_report',
            'sale' => 'sales_report',
            'traffic' => 'traffic_report',
            'flow' => 'traffic_report',
            'rank' => 'competitor_rank',
            'ranking' => 'competitor_rank',
            'ads' => 'ads_pyramid',
            'ad' => 'ads_pyramid',
            'psi' => 'quality_psi',
            'quality' => 'quality_psi',
            'comment' => 'comment_review',
            'comments' => 'comment_review',
            'review' => 'comment_review',
            'reviews' => 'comment_review',
            'market' => 'market_calendar',
            'user' => 'user_profile',
            'profile' => 'user_profile',
        ];
        $allowedMap = array_fill_keys($allowedSections, true);
        $selected = [];
        foreach ($tokens as $token) {
            $section = $aliases[$token] ?? $token;
            if (isset($allowedMap[$section]) && !in_array($section, $selected, true)) {
                $selected[] = $section;
            }
        }

        return $selected;
    }

    /**
     * @param array<int, string> $allowedSections
     * @return array<int, string>
     */
    private function filterAllowedCtripProfilePresetSections(string $preset, array $allowedSections): array
    {
        $presetSections = match ($preset) {
            'all', 'wide' => $allowedSections,
            'core' => ['homepage', 'business_overview', 'business_weekly_overview', 'sales_report', 'traffic_report'],
            default => ['business_overview', 'business_weekly_overview', 'traffic_report'],
        };
        $allowedMap = array_fill_keys($allowedSections, true);
        $selected = array_values(array_filter(
            $presetSections,
            static fn(string $section): bool => isset($allowedMap[$section])
        ));
        return $selected !== [] ? $selected : $allowedSections;
    }
}
