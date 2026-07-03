<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\model\OperationLog;
use app\model\User as UserModel;
use think\Response;

trait CookieEndpointConcern
{
    private function resolveUserIdFromTokenData($tokenData): ?int
    {
        $userId = is_array($tokenData)
            ? ($tokenData['user_id'] ?? $tokenData['id'] ?? null)
            : $tokenData;

        if (is_int($userId)) {
            return $userId > 0 ? $userId : null;
        }

        if (is_string($userId)) {
            $userId = trim($userId);
            return ctype_digit($userId) && (int)$userId > 0 ? (int)$userId : null;
        }

        return null;
    }

    private function extractTokenFromAuthorizationHeader(string $authHeader): string
    {
        $authHeader = trim($authHeader);
        if ($authHeader === '') {
            return '';
        }

        if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return trim($matches[1]);
        }

        return $authHeader;
    }

    private function resolveCookieCorsOrigin(): string
    {
        $origin = trim((string)$this->request->header('Origin', ''));
        if ($origin === '') {
            return '';
        }

        if (in_array($origin, $this->cookieAllowedOrigins(), true)) {
            return $origin;
        }

        $host = strtolower((string)parse_url($origin, PHP_URL_HOST));
        foreach (['.ctrip.com', '.ctripcorp.com', '.meituan.com', '.dianping.com'] as $suffix) {
            if ($host !== '' && str_ends_with($host, $suffix)) {
                return $origin;
            }
        }

        return '';
    }

    private function cookieAllowedOrigins(): array
    {
        $configured = trim((string)env('ONLINE_DATA_COOKIE_ALLOWED_ORIGINS', ''));
        $origins = $configured === '' ? [] : array_map('trim', explode(',', $configured));
        $origins[] = $this->request->scheme() . '://' . $this->request->host(true);
        $origins[] = 'https://ebooking.ctrip.com';
        $origins[] = 'https://eb.meituan.com';
        $origins[] = 'https://e.meituan.com';
        $origins[] = 'https://e.dianping.com';

        return array_values(array_unique(array_filter($origins)));
    }

    private function cookieCorsHeaders(?string $origin = null): array
    {
        $origin = $origin ?? $this->resolveCookieCorsOrigin();
        $headers = [
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
            'Vary' => 'Origin',
        ];
        if ($origin !== '') {
            $headers['Access-Control-Allow-Origin'] = $origin;
        }

        return $headers;
    }

    private function corsError(string $message, int $status = 400): Response
    {
        return json([
            'code' => $status,
            'message' => $message,
            'data' => null,
        ], $status)->header($this->cookieCorsHeaders());
    }

    private function corsSuccess(array $data): Response
    {
        return json([
            'code' => 200,
            'message' => '鎿嶄綔鎴愬姛',
            'data' => $data,
        ])->header($this->cookieCorsHeaders());
    }

    /**
     * Public OTA endpoints bypass the auth middleware, so they keep a small
     * independent rate gate and audit trail. Do not store Cookie/token values.
     *
     * @return array<string, mixed>|null
     */
    private function checkPublicEndpointRateLimit(string $endpoint, int $limit, int $window): ?array
    {
        $window = max(1, $window);
        $limit = max(1, $limit);
        $bucket = (int)floor(time() / $window);
        $ipHash = substr(sha1((string)$this->request->ip()), 0, 16);
        $key = sprintf('public_endpoint_rate_%s_%s_%d', preg_replace('/[^a-z0-9_]/i', '_', $endpoint), $ipHash, $bucket);
        $count = (int)cache($key);

        if ($count >= $limit) {
            return [
                'limit' => $limit,
                'window' => $window,
                'retry_after' => max(1, (($bucket + 1) * $window) - time()),
                'ip_hash' => $ipHash,
            ];
        }

        cache($key, $count + 1, $window + 5);
        return null;
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function publicEndpointHotelId(array $extra): ?int
    {
        foreach (['hotel_id', 'system_hotel_id'] as $key) {
            if (isset($extra[$key]) && is_numeric($extra[$key]) && (int)$extra[$key] > 0) {
                return (int)$extra[$key];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function sanitizePublicEndpointExtra(array $extra): array
    {
        $safe = [];
        foreach ($extra as $key => $value) {
            $keyText = strtolower((string)$key);
            if (preg_match('/cookie|token|authorization|password|secret|spidertoken|mtgsig/i', $keyText)) {
                $safe[$key] = '***';
                continue;
            }
            if (is_array($value)) {
                $safe[$key] = $this->sanitizePublicEndpointExtra($value);
                continue;
            }
            $safe[$key] = is_scalar($value) || $value === null
                ? $this->safePublicEndpointText((string)$value)
                : '[object]';
        }

        return $safe;
    }

    private function safePublicEndpointText(string $value): string
    {
        $value = preg_replace('/(1[3-9]\d)\d{4}(\d{4})/u', '$1****$2', $value) ?: '';
        $value = preg_replace('/\b\d{8,}\b/u', '[编号已隐藏]', $value) ?: '';
        $value = preg_replace('/(cookie|token|authorization|spidertoken)\s*[:=]\s*[^;\s,]+/iu', '$1=****', $value) ?: '';
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?: '';

        return mb_substr($value, 0, 160);
    }

    private function buildPublicEndpointSecurityRow(string $endpoint, array $logs, array $meta): array
    {
        $endpointLogs = array_values(array_filter(
            $logs,
            static fn(array $log): bool => (string)($log['action'] ?? '') === $endpoint . '_public_failure'
        ));
        $reasonCounts = [];
        $statusCounts = [];
        foreach ($endpointLogs as $log) {
            $extra = $this->decodePublicEndpointFailureExtra($log);
            $reason = (string)($extra['reason'] ?? 'unknown');
            $status = (string)($extra['status'] ?? ($log['error_info'] ?? 'unknown'));
            $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
        }

        return [
            'endpoint' => $endpoint,
            'method' => $meta['method'],
            'path' => $meta['path'],
            'auth' => $meta['auth'],
            'normal_auth_middleware' => false,
            'rate_limit' => $meta['rate_limit'],
            'token_configured' => $meta['token_configured'],
            'recent_failure_count' => count($endpointLogs),
            'rate_limited_count' => (int)($reasonCounts['rate_limited'] ?? 0),
            'last_failure' => isset($endpointLogs[0]) ? $this->serializePublicEndpointFailureLog($endpointLogs[0]) : null,
            'reason_counts' => $reasonCounts,
            'status_counts' => $statusCounts,
            'security_note' => 'Audited failures store endpoint, reason, status, method, origin and hashed IP only; secrets are masked.',
        ];
    }

    private function serializePublicEndpointFailureLog(array $log): array
    {
        $extra = $this->decodePublicEndpointFailureExtra($log);
        return [
            'id' => (int)($log['id'] ?? 0),
            'endpoint' => (string)($extra['endpoint'] ?? str_replace('_public_failure', '', (string)($log['action'] ?? ''))),
            'reason' => (string)($extra['reason'] ?? 'unknown'),
            'status' => (int)($extra['status'] ?? 0),
            'method' => (string)($extra['method'] ?? ''),
            'origin' => (string)($extra['origin'] ?? ''),
            'ip_hash' => (string)($extra['ip_hash'] ?? ''),
            'time' => (string)($log['create_time'] ?? ''),
            'error_info' => (string)($log['error_info'] ?? ''),
        ];
    }

    private function decodePublicEndpointFailureExtra(array $log): array
    {
        $raw = (string)($log['extra_data'] ?? '');
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }


    public function receiveCookies(): Response
    {
        $origin = $this->resolveCookieCorsOrigin();
        if ($this->request->header('Origin', '') !== '' && $origin === '') {
            $this->recordPublicEndpointFailure('receive_cookies', 'origin_not_allowed', 403, [
                'origin' => (string)$this->request->header('Origin', ''),
            ]);
            return json(['code' => 403, 'message' => 'Origin not allowed', 'data' => null], 403);
        }

        // 允许受信来源跨域请求
        if ($origin !== '') {
            header('Access-Control-Allow-Origin: ' . $origin);
        }
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Vary: Origin');

        if ($this->request->method() === 'OPTIONS') {
            return response('', 204, $this->cookieCorsHeaders($origin));
        }

        $rateLimited = $this->checkPublicEndpointRateLimit('receive_cookies', 30, 60);
        if ($rateLimited !== null) {
            $this->recordPublicEndpointFailure('receive_cookies', 'rate_limited', 429, $rateLimited);
            return $this->corsError('请求过于频繁，请稍后再试', 429);
        }

        $token = $this->extractTokenFromAuthorizationHeader((string)$this->request->header('Authorization', ''));
        $name = $this->request->post('name', 'ctrip_auto');
        $cookies = $this->request->post('cookies', '');
        $source = $this->request->post('source', '');

        if (empty($token)) {
            $this->recordPublicEndpointFailure('receive_cookies', 'missing_token', 401, [
                'name' => $name,
                'source' => $source,
            ]);
            return $this->corsError('缺少认证Token', 401);
        }

        if (empty($cookies)) {
            $this->recordPublicEndpointFailure('receive_cookies', 'empty_cookies', 422, [
                'name' => $name,
                'source' => $source,
            ]);
            return $this->corsError('Cookies内容为空', 422);
        }

        // 验证token
        $tokenData = cache('token_' . $token);
        if (!$tokenData) {
            $this->recordPublicEndpointFailure('receive_cookies', 'invalid_token', 401, [
                'name' => $name,
                'source' => $source,
            ]);
            return $this->corsError('Token无效或已过期', 401);
        }

        $userId = $this->resolveUserIdFromTokenData($tokenData);
        if ($userId === null) {
            $this->recordPublicEndpointFailure('receive_cookies', 'invalid_token_payload', 401, [
                'name' => $name,
                'source' => $source,
            ]);
            return $this->corsError('Token认证信息无效', 401);
        }

        // 保存Cookies配置
        $user = UserModel::find($userId);
        if (!$user) {
            $this->recordPublicEndpointFailure('receive_cookies', 'token_user_not_found', 401, [
                'user_id' => $userId,
                'name' => $name,
                'source' => $source,
            ]);
            return $this->corsError('Token user not found', 401);
        }

        $hotelId = null;
        if ($user->isSuperAdmin()) {
            $requestHotelId = $this->request->post('hotel_id', $this->request->post('system_hotel_id', null));
            $hotelId = is_numeric($requestHotelId) && (int)$requestHotelId > 0 ? (int)$requestHotelId : null;
        } else {
            if (empty($user->hotel_id)) {
                $this->recordPublicEndpointFailure('receive_cookies', 'user_without_hotel', 403, [
                    'user_id' => $userId,
                    'name' => $name,
                    'source' => $source,
                ]);
                return $this->corsError('User is not bound to a hotel', 403);
            }
            $hotelId = (int)$user->hotel_id;
        }

        $key = $hotelId ? "online_data_cookies_hotel_{$hotelId}" : 'online_data_cookies_global';
        $list = $this->getConfigList($key);
        $list[$name] = [
            'name' => $name,
            'cookies' => $cookies,
            'source' => $source,
            'update_time' => date('Y-m-d H:i:s'),
            'user_id' => $userId,
            'hotel_id' => $hotelId,
            'system_hotel_id' => $hotelId,
        ];
        $this->setConfigList($key, $list);

        OperationLog::record('online_data', 'receive_cookies', '通过书签脚本获取Cookies: ' . $name, (int)$userId, $hotelId);

        return $this->corsSuccess([
            'name' => $name,
            'message' => '临时 Cookie/API 辅助内容已保存，仅用于排障或补数',
        ]);
    }

    private function recordPublicEndpointFailure(string $endpoint, string $reason, int $status, array $extra = []): void
    {
        try {
            OperationLog::record(
                'online_data',
                $endpoint . '_public_failure',
                '公开采集入口失败: ' . $endpoint . ' / ' . $reason,
                null,
                $this->publicEndpointHotelId($extra),
                'HTTP ' . $status,
                [
                    'audit_type' => 'security',
                    'endpoint' => $endpoint,
                    'reason' => $reason,
                    'status' => $status,
                    'method' => strtoupper((string)$this->request->method()),
                    'origin' => $this->safePublicEndpointText((string)$this->request->header('Origin', '')),
                    'ip_hash' => substr(sha1((string)$this->request->ip()), 0, 16),
                    'extra' => $this->sanitizePublicEndpointExtra($extra),
                ]
            );
        } catch (\Throwable $e) {
            \think\facade\Log::warning('公开采集入口审计写入失败: ' . $e->getMessage(), [
                'endpoint' => $endpoint,
                'reason' => $reason,
                'status' => $status,
            ]);
        }
    }

    public function publicEndpointSecurity(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_view_online_data');

        $days = min(30, max(1, (int)$this->request->get('days', 7)));
        $limit = min(20, max(1, (int)$this->request->get('limit', 8)));
        $endDate = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $actions = [
            'receive_cookies_public_failure',
            'cron_trigger_public_failure',
            'daily_workbench_patrol_cron_public_failure',
        ];

        $logs = OperationLog::where('module', 'online_data')
            ->whereIn('action', $actions)
            ->whereBetween('create_time', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->order('create_time', 'desc')
            ->limit(100)
            ->select()
            ->toArray();

        return $this->success([
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'days' => $days,
            ],
            'scope' => 'public OTA collection endpoints; status only, no Cookie/token/header values',
            'endpoints' => [
                $this->buildPublicEndpointSecurityRow('receive_cookies', $logs, [
                    'method' => 'POST|OPTIONS',
                    'path' => '/api/online-data/receive-cookies',
                    'auth' => 'Authorization Bearer token from current login session',
                    'rate_limit' => ['limit' => 30, 'window_seconds' => 60],
                    'token_configured' => null,
                ]),
                $this->buildPublicEndpointSecurityRow('cron_trigger', $logs, [
                    'method' => 'GET',
                    'path' => '/api/online-data/cron-trigger',
                    'auth' => 'X-Cron-Token or token query parameter',
                    'rate_limit' => ['limit' => 20, 'window_seconds' => 60],
                    'token_configured' => trim((string)\think\facade\Env::get('CRON_TOKEN', '')) !== '',
                ]),
                $this->buildPublicEndpointSecurityRow('daily_workbench_patrol_cron', $logs, [
                    'method' => 'GET',
                    'path' => '/api/online-data/daily-workbench-patrol-cron',
                    'auth' => 'X-Cron-Token or token query parameter',
                    'rate_limit' => ['limit' => 10, 'window_seconds' => 60],
                    'token_configured' => trim((string)\think\facade\Env::get('CRON_TOKEN', '')) !== '',
                ]),
            ],
            'recent_failures' => array_slice(array_map([$this, 'serializePublicEndpointFailureLog'], $logs), 0, $limit),
            'scan_scope' => [
                'source' => 'operation_logs',
                'scan_limit' => 100,
                'scanned_count' => count($logs),
                'returned_failure_limit' => $limit,
                'sensitive_values' => 'redacted_by_recordPublicEndpointFailure',
            ],
        ]);
    }

    public function bookmarklet(): Response
    {
        $this->checkPermission();

        $token = $this->extractTokenFromAuthorizationHeader((string)$this->request->header('Authorization', ''));
        if (empty($token)) {
            return $this->error('缺少Token', 401);
        }
        $script = $this->buildCookieBookmarkletScript($token, 'ctrip_auto');

        // 压缩脚本
        $script = preg_replace('/\s+/', ' ', $script);

        return $this->success([
            'script' => $script,
            'bookmarklet' => 'javascript:' . $script,
            'instructions' => [
                '1. 将下面的按钮拖拽到浏览器书签栏',
                '2. 在携程ebooking页面登录后，点击该书签',
                '3. 输入临时记录名称，Cookies将自动保存到系统',
            ],
        ]);
    }

    private function buildCookieBookmarkletScript(string $token, string $defaultName): string
    {
        $apiUrl = $this->request->domain() . '/api/online-data/receive-cookies';
        $apiUrlJson = json_encode($apiUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $authHeaderJson = json_encode('Bearer ' . $token, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $defaultNameJson = json_encode($defaultName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return <<<JAVASCRIPT
(function(){
  try{
    var cookies=document.cookie||'';
    if(!cookies){alert('未读取到 Cookie，请确认已登录当前 OTA 后台');return;}
    var name=prompt('请输入临时记录名称',{$defaultNameJson}+'_'+new Date().toLocaleDateString());
    if(!name){return;}
    var form=new FormData();
    form.append('name',name);
    form.append('cookies',cookies);
    form.append('source',location.hostname);
    fetch({$apiUrlJson},{
      method:'POST',
      mode:'cors',
      body:form,
      headers:{'Authorization':{$authHeaderJson}}
    }).then(function(response){return response.json();}).then(function(result){
      if(result.code===200){alert('Cookies 已保存：'+name);return;}
      alert('保存失败：'+(result.message||'未知错误'));
    }).catch(function(error){alert('请求失败：'+error.message);});
  }catch(error){
    alert('脚本执行失败：'+error.message);
  }
})();
JAVASCRIPT;
    }

    public function saveCookies(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $name = $this->request->post('name', '');
        $cookies = $this->request->post('cookies', '');
        $hotelId = $this->resolveOnlineDataSystemHotelId($this->request->post('hotel_id', null));

        if (empty($name) || empty($cookies)) {
            return $this->error('名称和Cookies不能为空');
        }

        // 非超级管理员只能保存自己酒店的Cookies
        if (!$this->currentUser->isSuperAdmin()) {
            $hotelId = $this->resolveOnlineDataSystemHotelId(null);
            if (empty($hotelId)) {
                return $this->error('您未关联酒店，无法保存Cookies');
            }
        }
        // 超级管理员可以选择酒店，也可以不选（保存全局Cookies）

        // 构建存储key（持久化到数据库）
        $key = $hotelId ? "online_data_cookies_hotel_{$hotelId}" : "online_data_cookies_global";
        $list = $this->getConfigList($key);
        $list[$name] = [
            'name' => $name,
            'cookies' => $cookies,
            'update_time' => date('Y-m-d H:i:s'),
            'hotel_id' => $hotelId ?: null,
        ];
        $this->setConfigList($key, $list);

        OperationLog::record('online_data', 'save_cookies', "保存Cookies配置: {$name}", $this->currentUser->id, $hotelId ? (int)$hotelId : null);

        return $this->success(null, '临时 Cookie/API 辅助内容保存成功');
    }

    public function getCookiesList(): Response
    {
        $this->checkPermission();

        $hotelId = $this->request->get('hotel_id', '');

        // 非超级管理员只能查看自己酒店的Cookies
        if (!$this->currentUser->isSuperAdmin()) {
            $hotelId = $this->currentUser->hotel_id;
            if (empty($hotelId)) {
                return $this->success([]);
            }
            $key = "online_data_cookies_hotel_{$hotelId}";
            $list = $this->getConfigList($key);
            return $this->success(array_map([$this, 'sanitizeSecretConfig'], array_values($list)));
        }

        // 超级管理员查看所有Cookies（全局 + 所有酒店）
        $allCookies = [];

        // 获取全局Cookies
        $globalKey = "online_data_cookies_global";
        $globalList = $this->getConfigList($globalKey);
        foreach ($globalList as $item) {
            $allCookies[] = $item;
        }

        // 获取所有酒店的Cookies
        $hotels = \app\model\Hotel::select();
        foreach ($hotels as $hotel) {
            $key = "online_data_cookies_hotel_{$hotel->id}";
            $list = $this->getConfigList($key);
            foreach ($list as $item) {
                $allCookies[] = $item;
            }
        }

        return $this->success(array_map([$this, 'sanitizeSecretConfig'], $allCookies));
    }

    public function getCookiesDetail(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $name = trim((string)$this->request->get('name', ''));
        if ($name === '') {
            return $this->error('Cookies name is required.');
        }

        $hotelId = $this->resolveOnlineDataSystemHotelId($this->request->get('hotel_id', null));
        $keys = [];
        if ($hotelId) {
            $keys[] = "online_data_cookies_hotel_{$hotelId}";
        }
        if ($this->currentUser->isSuperAdmin()) {
            $keys[] = 'online_data_cookies_global';
        }

        foreach (array_unique($keys) as $key) {
            $list = $this->getConfigList($key);
            if (isset($list[$name])) {
                return $this->success($list[$name]);
            }
        }

        return $this->error('Cookies config not found.', 404);
    }

    public function deleteCookies(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_delete_online_data');

        $name = $this->request->post('name', '');
        $hotelId = $this->resolveOnlineDataSystemHotelId($this->request->post('hotel_id', null));

        if (empty($name)) {
            return $this->error('名称不能为空');
        }

        // 非超级管理员只能删除自己酒店的Cookies
        if (!$this->currentUser->isSuperAdmin()) {
            $hotelId = $this->resolveOnlineDataSystemHotelId(null);
        }

        // 构建key
        $key = $hotelId ? "online_data_cookies_hotel_{$hotelId}" : "online_data_cookies_global";
        $list = $this->getConfigList($key);
        if (isset($list[$name])) {
            unset($list[$name]);
            $this->setConfigList($key, $list);
            return $this->success(null, '删除成功');
        }

        return $this->error('Cookies配置不存在');
    }

    public function batchDeleteCookies(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_delete_online_data');

        $items = $this->request->post('items', []);
        if (empty($items) || !is_array($items)) {
            return $this->error('请选择要删除的Cookies配置');
        }

        $deletedCount = 0;
        $skippedCount = 0;
        $changedLists = [];
        $isSuperAdmin = $this->currentUser->isSuperAdmin();
        $userHotelId = $isSuperAdmin ? null : $this->resolveOnlineDataSystemHotelId(null);

        if (!$isSuperAdmin && empty($userHotelId)) {
            return $this->error('您未关联酒店，无法删除Cookies配置');
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                $skippedCount++;
                continue;
            }

            $name = trim((string)($item['name'] ?? ''));
            if ($name === '') {
                $skippedCount++;
                continue;
            }

            if ($isSuperAdmin) {
                $rawHotelId = $item['hotel_id'] ?? null;
                $hasHotelId = $rawHotelId !== null && trim((string)$rawHotelId) !== '';
                $hotelId = $this->resolveOnlineDataSystemHotelId($rawHotelId);
                if ($hasHotelId && empty($hotelId)) {
                    $skippedCount++;
                    continue;
                }
            } else {
                $hotelId = $userHotelId;
            }

            $key = $hotelId ? "online_data_cookies_hotel_{$hotelId}" : 'online_data_cookies_global';
            if (!array_key_exists($key, $changedLists)) {
                $changedLists[$key] = $this->getConfigList($key);
            }

            if (isset($changedLists[$key][$name])) {
                unset($changedLists[$key][$name]);
                $deletedCount++;
            } else {
                $skippedCount++;
            }
        }

        foreach ($changedLists as $key => $list) {
            $this->setConfigList($key, $list);
        }

        OperationLog::record('online_data', 'batch_delete_cookies', '批量删除Cookies配置: ' . $deletedCount . '条', $this->currentUser->id);

        return $this->success([
            'deleted_count' => $deletedCount,
            'skipped_count' => $skippedCount,
        ], $deletedCount > 0 ? '删除成功' : '未删除任何Cookies配置');
    }

}
