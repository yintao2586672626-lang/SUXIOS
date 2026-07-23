<?php
declare(strict_types=1);

namespace app\controller;

use app\model\User;
use app\model\OperationLog;
use app\model\LoginLog;
use app\model\SystemConfig;
use app\model\Hotel;
use app\service\LoginRateLimiter;
use app\service\PermissionService;
use think\Response;

class Auth extends Base
{
    private const TOKEN_TTL_SECONDS = 259200; // 72 hours

    /**
     * Public self-registration is intentionally unavailable.
     * Accounts must be created by an authorized administrator.
     */
    public function register(): Response
    {
        return $this->error('系统已关闭自助注册，请联系管理员创建账号', 403);
    }

    /**
     * Public login support exposes one whitelisted, read-only field.
     */
    public function loginSupport(): Response
    {
        $defaults = SystemConfig::getDefaultConfigs();
        $fallback = trim((string)($defaults[SystemConfig::KEY_LOGIN_SUPPORT_CONTACT] ?? '请联系系统管理员'));
        try {
            $contact = trim((string)SystemConfig::getValue(SystemConfig::KEY_LOGIN_SUPPORT_CONTACT, $fallback));
        } catch (\Throwable $e) {
            $contact = $fallback;
        }
        $contact = trim((string)(preg_replace('/\s+/u', ' ', $contact) ?? $contact));
        $contact = mb_substr($contact !== '' ? $contact : '请联系系统管理员', 0, 120);

        return $this->success([
            'contact' => $contact,
        ]);
    }

    /**
     * 登录
     */
    public function login(): Response
    {
        $rawUsername = $this->request->post('username', '');
        $rawPassword = $this->request->post('password', '');
        $rawClientInfo = $this->request->post('client_info', []);

        // Reserve before any branch that persists a failure. Otherwise malformed
        // public requests can bypass throttling and grow login_logs without bound.
        $username = is_string($rawUsername)
            ? mb_substr(trim($rawUsername), 0, 50)
            : '';
        $ip = (string)$this->request->ip();
        $userAgent = (string)$this->request->header('User-Agent', '');
        $loginRateLimiter = $this->makeLoginRateLimiter();
        try {
            $rateLimit = $loginRateLimiter->consumeAttempt($ip, $username);
        } catch (\Throwable $exception) {
            return $this->loginProtectionUnavailable($exception);
        }
        if (!$rateLimit['allowed']) {
            $retryAfter = max(1, (int)$rateLimit['retry_after']);
            return $this->error('登录请求过于频繁，请稍后重试', 429, [
                'reason' => 'login_rate_limited',
                'retry_after' => $retryAfter,
            ])->header(['Retry-After' => (string)$retryAfter]);
        }
        $reservationBucket = isset($rateLimit['reservation_bucket'])
            ? (int)$rateLimit['reservation_bucket']
            : null;

        if (!is_string($rawUsername) || !is_string($rawPassword)) {
            return $this->invalidLoginPayload();
        }
        $username = trim($rawUsername);
        $password = $rawPassword;
        if (mb_strlen($username) > 50 || strlen($password) > 1024) {
            return $this->invalidLoginPayload();
        }
        $clientInfo = $this->normalizeLoginClientInfo($rawClientInfo);
        if ($clientInfo === null) {
            return $this->invalidLoginPayload();
        }

        // 参数验证
        if (empty($username) || empty($password)) {
            return $this->error('请输入用户名和密码');
        }

        $user = User::with(['role'])->where('username', $username)->find();
        
        // 用户不存在
        if (!$user) {
            // 记录失败日志
            LoginLog::record(null, $username, 'login', 'failed', '用户不存在', $ip, $userAgent, $clientInfo);
            return $this->error('用户名或密码错误');
        }

        // 密码验证失败
        if (!$user->verifyPassword($password)) {
            // 记录失败日志
            LoginLog::record($user->id, $username, 'login', 'failed', '密码错误', $ip, $userAgent, $clientInfo);
            return $this->error('用户名或密码错误');
        }

        // 账号被禁用
        if ($user->status != User::STATUS_ENABLED) {
            // 记录失败日志
            LoginLog::record($user->id, $username, 'login', 'failed', '账号已停用', $ip, $userAgent, $clientInfo);
            $this->releaseLoginRateLimitReservation($loginRateLimiter, $ip, $username, $reservationBucket);
            return $this->error('账号已停用，请联系管理员启用');
        }

        // Tenant-scoped queries run immediately after authentication. Reject a
        // broken legacy binding explicitly before updating login counters or
        // publishing any session state; deterministic bindings are repaired by
        // the governed migration.
        if (!$user->isSuperAdmin() && (int)($user->tenant_id ?? 0) <= 0) {
            LoginLog::record($user->id, $username, 'login', 'failed', '账号缺少有效租户绑定', $ip, $userAgent, $clientInfo);
            $this->releaseLoginRateLimitReservation($loginRateLimiter, $ip, $username, $reservationBucket);
            return $this->error('账号门店租户绑定异常，请联系管理员重新分配门店', 403, [
                'reason' => 'tenant_context_missing',
            ]);
        }

        $this->releaseLoginRateLimitReservation($loginRateLimiter, $ip, $username, $reservationBucket);

        // 更新用户登录信息
        $user->last_login_time = date('Y-m-d H:i:s');
        $user->last_login_ip = $ip;
        $user->login_count = $user->login_count + 1;
        $user->save();

        // Password verification establishes the request actor for all
        // tenant-scoped work in the successful login path.
        $this->request->user = $user;

        // 生成更安全的 Token
        $token = $this->generateToken($user->id);
        
        // 缓存 Token 信息
        $tokenData = [
            'user_id' => $user->id,
            'created_at' => time(),
            'ip' => $ip,
            'user_agent' => substr($userAgent, 0, 255),
            'auth_version' => $user->authSessionVersion(),
        ];
        $permittedHotels = $this->buildPermittedHotels($user);
        $authContext = $this->buildAuthContext($user, $permittedHotels);
        $loginUserPayload = $this->buildLoginUserPayload(
            $user,
            $permittedHotels,
            $this->positiveIntOrNull($authContext['hotelId'] ?? null)
        );
        $loginNotices = $this->buildLoginNotices($user, $permittedHotels);

        if (!$this->publishLoginTokenState($token, (int)$user->id, $tokenData)) {
            return $this->error('登录会话暂不可用，请稍后重试', 503, [
                'reason' => 'login_session_store_failed',
            ]);
        }

        // A successful login is audited only after both session indexes are
        // durable. This prevents cache failures from producing false success
        // audits or exposing a token backed by only one index.
        LoginLog::record($user->id, $username, 'login', 'success', null, $ip, $userAgent, $clientInfo);
        $loginHotelId = isset($loginUserPayload['hotel_id']) ? (int)$loginUserPayload['hotel_id'] : null;
        OperationLog::record('auth', 'login', '用户登录: ' . $username, $user->id, $loginHotelId);

        return $this->success([
            'token' => $token,
            'expires_in' => self::TOKEN_TTL_SECONDS,
            'user' => $loginUserPayload,
            'context' => $authContext,
            'notices' => $loginNotices,
        ], '登录成功');
    }

    protected function makeLoginRateLimiter(): LoginRateLimiter
    {
        return new LoginRateLimiter();
    }

    /** @param array<string, mixed> $tokenData */
    private function publishLoginTokenState(string $token, int $userId, array $tokenData): bool
    {
        $tokenKey = 'token_' . $token;
        $userTokenKey = 'user_token_' . $userId;
        $tokenStored = false;
        $userTokenStored = false;

        try {
            $tokenStored = $this->writeLoginCacheValue($tokenKey, $tokenData, self::TOKEN_TTL_SECONDS);
            $userTokenStored = $this->writeLoginCacheValue($userTokenKey, $token, self::TOKEN_TTL_SECONDS);
        } catch (\Throwable) {
            // Cleanup below is mandatory even when the cache driver throws.
        }

        if ($tokenStored && $userTokenStored) {
            return true;
        }

        foreach ([$tokenKey, $userTokenKey] as $key) {
            try {
                $this->deleteLoginCacheValue($key);
            } catch (\Throwable) {
                // The login still fails closed; never publish the token.
            }
        }

        return false;
    }

    protected function writeLoginCacheValue(string $key, mixed $value, int $ttl): bool
    {
        return cache($key, $value, $ttl) === true;
    }

    protected function deleteLoginCacheValue(string $key): void
    {
        cache($key, null);
    }

    private function releaseLoginRateLimitReservation(
        LoginRateLimiter $loginRateLimiter,
        string $ip,
        string $username,
        ?int $reservationBucket
    ): void {
        try {
            $loginRateLimiter->releaseSuccessfulAttempt($ip, $username, $reservationBucket);
        } catch (\Throwable $exception) {
            \think\facade\Log::warning('Login rate-limit reservation cleanup failed.', [
                'exception_type' => get_debug_type($exception),
            ]);
        }
    }

    private function loginProtectionUnavailable(\Throwable $exception): Response
    {
        try {
            \think\facade\Log::error('Login rate limiter unavailable.', [
                'exception_type' => get_debug_type($exception),
            ]);
        } catch (\Throwable) {
            // Logging must never replace the stable fail-closed response.
        }

        return $this->error('登录保护暂不可用，请稍后重试', 503, [
            'reason' => 'login_rate_limiter_unavailable',
        ]);
    }

    private function invalidLoginPayload(): Response
    {
        return $this->error('登录参数格式无效', 422, [
            'reason' => 'invalid_login_payload',
        ]);
    }

    /**
     * @return array<string, string>|null
     */
    private function normalizeLoginClientInfo(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (!is_array($value) || count($value) > 24) {
            return null;
        }

        $allowedKeys = array_fill_keys([
            'browser', 'browser_version', 'os', 'os_version', 'device', 'device_type',
            'platform', 'language', 'timezone', 'screen', 'app_version',
        ], true);
        $normalized = [];
        foreach ($value as $key => $item) {
            $key = strtolower(trim((string)$key));
            if (!isset($allowedKeys[$key])) {
                continue;
            }
            if (!is_scalar($item) && $item !== null) {
                return null;
            }
            $text = trim((string)$item);
            if (strlen($text) > 160) {
                return null;
            }
            if ($text !== '') {
                $normalized[$key] = $text;
            }
        }

        return $normalized;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildPermittedHotels(User $user): array
    {
        $permittedHotelIds = $user->getPermittedHotelIds();
        if (empty($permittedHotelIds)) {
            return [];
        }

        $query = $user->isSuperAdmin()
            ? Hotel::withoutTenantScope()
            : Hotel::where([]);

        return $query->whereIn('id', array_values(array_map('intval', $permittedHotelIds)))
            ->select()
            ->toArray();
    }

    /**
     * @param array<int, array<string, mixed>> $permittedHotels
     * @return array<string, mixed>
     */
    private function buildLoginUserPayload(User $user, array $permittedHotels, ?int $currentHotelId): array
    {
        $primaryHotel = $this->permittedPrimaryHotel($user, $permittedHotels);
        return [
            'id' => $user->id,
            'username' => $user->username,
            'realname' => $user->realname,
            'role_id' => $user->role_id,
            'role_name' => $user->role ? $user->role->display_name : '',
            'hotel_id' => $primaryHotel !== null ? (int)($primaryHotel['id'] ?? 0) : null,
            'hotel_name' => (string)($primaryHotel['name'] ?? $primaryHotel['hotel_name'] ?? ''),
            'is_super_admin' => $user->isSuperAdmin(),
            'is_hotel_manager' => $user->isHotelManager(),
            'permitted_hotels' => $permittedHotels,
            'permissions' => $this->buildUserPermissions($user, $currentHotelId),
            'capabilities' => $this->buildUserCapabilities($user),
            'hotel_scope' => $user->getHotelScopeContext(),
            'modules' => $this->buildUserModules($user),
            'notices' => $this->buildLoginNotices($user, $permittedHotels),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $permittedHotels
     * @return array<string, mixed>|null
     */
    private function permittedPrimaryHotel(User $user, array $permittedHotels): ?array
    {
        $primaryHotelId = (int)($user->hotel_id ?? 0);
        if ($primaryHotelId <= 0) {
            return null;
        }

        foreach ($permittedHotels as $hotel) {
            if ((int)($hotel['id'] ?? 0) === $primaryHotelId) {
                return $hotel;
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $permittedHotels
     * @return array<int, array<string, mixed>>
     */
    private function buildLoginNotices(User $user, array $permittedHotels): array
    {
        if ($user->isSuperAdmin() || !$user->isBetaUser()) {
            return [];
        }

        $hotelCount = count($permittedHotels);
        $message = $hotelCount > 0
            ? "内测用户当前仅可查看已绑定或管理员分配的门店（{$hotelCount}家）。未绑定或未分配的门店将无法查看。"
            : "内测用户当前未绑定或未被管理员分配门店。请绑定自己的门店或联系超级管理员分配后查看门店数据。";

        return [[
            'type' => 'beta_hotel_binding_deadline',
            'level' => 'warning',
            'message' => $message,
            'action_page' => 'hotels',
        ]];
    }

    /**
     * @param array<int, array<string, mixed>> $permittedHotels
     * @return array<string, mixed>
     */
    private function buildAuthContext(User $user, array $permittedHotels): array
    {
        $requestedHotelId = $this->positiveIntOrNull(
            $this->request->get('system_hotel_id', $this->request->get('hotel_id', null))
        );
        $permittedHotelIds = array_values(array_map(static fn(array $hotel): int => (int)($hotel['id'] ?? 0), $permittedHotels));
        $requestedHotelIsPermitted = $requestedHotelId === null || in_array($requestedHotelId, $permittedHotelIds, true);
        $currentHotel = $this->resolveAuthContextHotel(
            $user,
            $permittedHotels,
            $requestedHotelIsPermitted ? $requestedHotelId : null
        );
        $hotelId = $this->positiveIntOrNull($currentHotel['id'] ?? null);
        $tenantId = $this->positiveIntOrNull($currentHotel['tenant_id'] ?? null);
        $permissionStatus = $requestedHotelId !== null
            ? ($requestedHotelIsPermitted ? 'allowed' : 'denied')
            : ($hotelId !== null ? 'allowed' : 'unknown');

        $context = [
            'tokenStatus' => 'valid',
            'hotelId' => $hotelId,
            'tenantId' => $tenantId,
            'platform' => $this->normalizeAuthContextPlatform($this->request->get('platform', 'unknown')),
            'currentHotelName' => $currentHotel ? (string)($currentHotel['name'] ?? $currentHotel['hotel_name'] ?? '') : '',
            'permissionStatus' => $permissionStatus,
            'platformLoginScope' => ['ctrip', 'meituan'],
        ];

        if ($requestedHotelId !== null && !$requestedHotelIsPermitted) {
            $context['requestedHotelId'] = $requestedHotelId;
        }

        return $context;
    }

    /**
     * @param array<int, array<string, mixed>> $permittedHotels
     * @return array<string, mixed>|null
     */
    private function resolveAuthContextHotel(User $user, array $permittedHotels, ?int $requestedHotelId): ?array
    {
        foreach ($permittedHotels as $hotel) {
            $id = (int)($hotel['id'] ?? 0);
            if ($requestedHotelId !== null && $id === $requestedHotelId) {
                return $hotel;
            }
        }

        $userHotelId = (int)($user->hotel_id ?? 0);
        if ($userHotelId > 0) {
            foreach ($permittedHotels as $hotel) {
                if ((int)($hotel['id'] ?? 0) === $userHotelId) {
                    return $hotel;
                }
            }
        }

        if (count($permittedHotels) === 1) {
            return $permittedHotels[0];
        }

        return null;
    }

    private function normalizeAuthContextPlatform($value): string
    {
        $platform = strtolower(trim((string)$value));
        if (in_array($platform, ['ctrip', 'meituan', 'all'], true)) {
            return $platform;
        }
        return 'unknown';
    }

    private function positiveIntOrNull($value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value) || (int)$value <= 0) {
            return null;
        }
        return (int)$value;
    }

    private function buildUserPermissions(User $user, ?int $hotelId): array
    {
        $allows = function (string $permission) use ($user, $hotelId): bool {
            if ($hotelId === null) {
                return $user->isSuperAdmin() && $this->roleAllows($user, $permission);
            }

            return $user->hasHotelPermission($hotelId, $permission)
                && $this->roleAllows($user, $permission);
        };

        return [
            'can_view_report' => $allows('can_view_report'),
            'can_fill_daily_report' => $allows('can_fill_daily_report'),
            'can_fill_monthly_task' => $allows('can_fill_monthly_task'),
            'can_edit_report' => $allows('can_edit_report'),
            'can_delete_report' => $allows('can_delete_report'),
            'can_view_online_data' => $allows('can_view_online_data'),
            'can_fetch_online_data' => $allows('can_fetch_online_data'),
            'can_delete_online_data' => $allows('can_delete_online_data'),
            'can_manage_own_hotels' => $user->canManageOwnHotels() && $this->roleAllows($user, 'can_manage_own_hotels'),
            'can_manage_users' => $user->canManageUser() && $this->roleAllows($user, 'can_manage_users'),
            'can_use_ai_decision' => $this->roleAllows($user, 'can_use_ai_decision'),
            'can_use_investment' => $this->roleAllows($user, 'can_use_investment'),
            'can_export_data' => $this->roleAllows($user, 'can_export_data'),
            'can_view_field_assets' => $this->roleAllows($user, 'can_view_field_assets'),
            'can_view_diagnostics' => $this->roleAllows($user, 'can_view_diagnostics'),
            'can_manage_ai_governance' => $this->roleAllows($user, 'can_manage_ai_governance'),
        ];
    }

    private function buildUserCapabilities(User $user): array
    {
        return (new PermissionService())->roleCapabilities($user);
    }

    private function buildUserModules(User $user): array
    {
        $capabilities = $this->buildUserCapabilities($user);
        $allows = static fn(string $capability): bool => in_array('all', $capabilities, true)
            || in_array($capability, $capabilities, true);

        return [
            'ai' => $allows('ai.view') || $allows('ai.execute'),
            'investment' => $allows('investment.view') || $allows('investment.simulate'),
            'operation' => $allows('operation.view') || $allows('operation.execute'),
            'export' => $allows('report.export') || $allows('ota.export'),
            'online_data' => $allows('ota.view') || $allows('ota.collect')
                || $this->roleAllows($user, 'can_view_online_data')
                || $this->roleAllows($user, 'can_fetch_online_data'),
            'collection_health' => $allows('collection_health.view')
                || $this->roleAllows($user, 'can_view_diagnostics'),
            'field_assets' => $allows('field_assets.view')
                || $this->roleAllows($user, 'can_view_field_assets'),
            'ai_governance' => $allows('ai.governance')
                || $this->roleAllows($user, 'can_manage_ai_governance'),
        ];
    }

    private function roleAllows(User $user, string $permission): bool
    {
        return (new PermissionService())->roleAllows($user, $permission);
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

    private function generateToken(int $userId): string
    {
        $random = bin2hex(random_bytes(16));
        $time = microtime(true);
        return hash('sha256', $userId . $time . $random . uniqid('', true));
    }

    /**
     * 登出
     */
    public function logout(): Response
    {
        $token = $this->extractTokenFromAuthorizationHeader((string)$this->request->header('Authorization', ''));
        $ip = $this->request->ip();
        $userAgent = $this->request->header('User-Agent', '');
        
        if ($token) {
            // 获取Token数据
            $tokenData = cache('token_' . $token);
            if ($tokenData && is_array($tokenData) && isset($tokenData['user_id'])) {
                $userId = $tokenData['user_id'];
                $user = User::find($userId);
                
                // 记录登出日志
                if ($user) {
                    LoginLog::record($user->id, $user->username, 'logout', 'success', null, $ip, $userAgent);
                }
                
                cache('user_token_' . $userId, null);
            }
            cache('token_' . $token, null);
        }
        
        return $this->success(null, '登出成功');
    }

    /**
     * 获取当前用户信息
     */
    public function info(): Response
    {
        if (!$this->currentUser) {
            return $this->error('未登录', 401);
        }

        $user = User::with(['role'])->find($this->currentUser->id);

        // 获取用户可访问的酒店（只包含启用的酒店）
        $permittedHotels = $this->buildPermittedHotels($user);
        $primaryHotel = $this->permittedPrimaryHotel($user, $permittedHotels);
        $authContext = $this->buildAuthContext($user, $permittedHotels);
        $userPermissions = $this->buildUserPermissions(
            $user,
            $this->positiveIntOrNull($authContext['hotelId'] ?? null)
        );

        return $this->success([
            'id' => $user->id,
            'username' => $user->username,
            'realname' => $user->realname,
            'email' => $user->email,
            'phone' => $user->phone,
            'role_id' => $user->role_id,
            'role_name' => $user->role ? $user->role->display_name : '',
            'hotel_id' => $primaryHotel !== null ? (int)($primaryHotel['id'] ?? 0) : null,
            'hotel' => $primaryHotel,
            'is_super_admin' => $user->isSuperAdmin(),
            'is_hotel_manager' => $user->isHotelManager(),
            'permitted_hotels' => $permittedHotels,
            'permissions' => $userPermissions,
            'capabilities' => $this->buildUserCapabilities($user),
            'hotel_scope' => $user->getHotelScopeContext(),
            'modules' => $this->buildUserModules($user),
            'context' => $authContext,
            'notices' => $this->buildLoginNotices($user, $permittedHotels),
        ]);
    }

    /**
     * 修改密码
     */
    public function changePassword(): Response
    {
        if (!$this->currentUser) {
            return $this->error('未登录', 401);
        }

        $oldPassword = $this->request->post('old_password', '');
        $newPassword = $this->request->post('new_password', '');

        $passwordError = $this->validatePasswordPolicy((string)$newPassword, '新密码');
        if ($passwordError) {
            return $this->error($passwordError);
        }

        $user = User::find($this->currentUser->id);
        if (!$user->verifyPassword($oldPassword)) {
            return $this->error('原密码错误');
        }

        $user->password = $newPassword;
        $user->save();

        // Tokens are bound to the password hash through auth_version. Clearing
        // the convenience pointer also prevents clients from treating the last
        // pre-change token as the active session.
        cache('auth_revoked_after_' . $user->id, time(), self::TOKEN_TTL_SECONDS);
        cache('user_token_' . $user->id, null);

        OperationLog::record('auth', 'change_password', '修改密码', $user->id);

        return $this->success(null, '密码修改成功');
    }
}
