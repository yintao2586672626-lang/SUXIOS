<?php
declare(strict_types=1);

namespace app\controller;

use app\model\User;
use app\model\OperationLog;
use app\model\LoginLog;
use app\model\SystemConfig;
use app\model\Role;
use app\model\Hotel;
use app\model\UserHotelPermission;
use app\service\PermissionService;
use think\facade\Db;
use think\Response;

class Auth extends Base
{
    private const TOKEN_TTL_SECONDS = 86400; // 24 hours
    private const BETA_HOTEL_BINDING_CUTOFF_DATE = '2026-07-05';

    /**
     * Public self-registration.
     *
     * New accounts are always created with the lowest enabled role level,
     * kept disabled until admin approval, and never accept role_id/status
     * from the public request.
     */
    public function register(): Response
    {
        if (!$this->isEnabledConfigValue(SystemConfig::getValue(SystemConfig::KEY_ENABLE_REGISTRATION, '1'))) {
            return $this->error('系统已关闭自助注册，请联系管理员创建账号', 403);
        }

        $data = $this->requestData();
        $username = trim((string)($data['username'] ?? ''));
        $password = (string)($data['password'] ?? '');
        $confirmPassword = (string)($data['confirm_password'] ?? $data['password_confirm'] ?? '');
        $realname = trim((string)($data['realname'] ?? $data['name'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $phone = trim((string)($data['phone'] ?? ''));
        $hotelId = $data['hotel_id'] ?? null;
        $ip = $this->request->ip();
        $userAgent = $this->request->header('User-Agent', '');

        if ($username === '' || !preg_match('/^[A-Za-z0-9_]{3,50}$/', $username)) {
            return $this->error('用户名需为 3-50 位字母、数字或下划线');
        }

        if ($password === '') {
            return $this->error('密码不能为空');
        }

        if ($confirmPassword !== '' && $confirmPassword !== $password) {
            return $this->error('两次输入的密码不一致');
        }

        $passwordError = $this->validatePasswordPolicy($password, '密码');
        if ($passwordError) {
            return $this->error($passwordError);
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error('邮箱格式不正确');
        }

        if ($phone !== '' && mb_strlen($phone) > 20) {
            return $this->error('手机号最多 20 个字符');
        }

        if (User::where('username', $username)->find()) {
            return $this->error('用户名已存在', 409);
        }

        $role = $this->resolveSelfRegistrationRole();
        if (!$role) {
            return $this->error('未配置基础用户角色，请管理员先配置普通用户角色', 422);
        }

        $resolvedHotelId = null;
        if ($hotelId !== null && $hotelId !== '') {
            if (!is_numeric($hotelId) || (int)$hotelId <= 0) {
                return $this->error('酒店 ID 不正确');
            }
            $hotel = Hotel::where('id', (int)$hotelId)
                ->where('status', Hotel::STATUS_ENABLED)
                ->find();
            if (!$hotel) {
                return $this->error('酒店不存在或已停用', 422);
            }
            $resolvedHotelId = (int)$hotelId;
        }

        try {
            $user = new User();
            $user->username = $username;
            $user->password = $password;
            $user->realname = $realname !== '' ? $realname : $username;
            $user->email = $email;
            $user->phone = $phone;
            $user->role_id = (int)$role->id;
            $user->hotel_id = $resolvedHotelId;
            $user->status = User::STATUS_DISABLED;
            $user->save();

            if ($resolvedHotelId !== null) {
                $hotelPermissionDefaults = $this->buildSelfRegistrationHotelPermissionDefaults($role);
                $permission = new UserHotelPermission();
                $permission->user_id = (int)$user->id;
                $permission->hotel_id = $resolvedHotelId;
                foreach ($hotelPermissionDefaults as $column => $value) {
                    if ($this->tableColumnExists('user_hotel_permissions', (string)$column)) {
                        $permission->{$column} = $value;
                    }
                }
                $permission->save();
            }

            LoginLog::record((int)$user->id, $username, 'register', 'success', null, $ip, $userAgent);
            try {
                OperationLog::record('auth', 'register', '用户自助注册待审核: ' . $username, (int)$user->id, $resolvedHotelId);
            } catch (\Throwable $logError) {
                // Audit logging must not turn a completed registration into a failed account creation.
            }

            return $this->success([
                'id' => (int)$user->id,
                'username' => $user->username,
                'realname' => $user->realname,
                'role_id' => (int)$user->role_id,
                'role_name' => $role->display_name,
                'hotel_id' => $resolvedHotelId,
                'status' => (int)$user->status,
            ], '注册申请已提交，等待超级管理员审核启用后才能登录');
        } catch (\Throwable $e) {
            try {
                LoginLog::record(null, $username, 'register', 'failed', $e->getMessage(), $ip, $userAgent);
            } catch (\Throwable $logError) {
                // Preserve the public error boundary even if audit logging fails.
            }
            return $this->error('注册失败，请稍后重试或联系管理员', 500);
        }
    }

    /**
     * 登录
     */
    public function login(): Response
    {
        $username = trim($this->request->post('username', ''));
        $password = $this->request->post('password', '');
        $clientInfo = $this->request->post('client_info', []);

        // 参数验证
        if (empty($username) || empty($password)) {
            return $this->error('请输入用户名和密码');
        }

        // 获取客户端信息
        $ip = $this->request->ip();
        $userAgent = $this->request->header('User-Agent', '');
        $lockKey = $this->loginLockKey($username, $ip);
        $lockedUntil = (int) (cache($lockKey . ':locked_until') ?: 0);
        if ($lockedUntil > time()) {
            $remainMinutes = max(1, (int) ceil(($lockedUntil - time()) / 60));
            LoginLog::record(null, $username, 'login', 'failed', '登录已锁定', $ip, $userAgent, $clientInfo);
            return $this->error('登录失败次数过多，请 ' . $remainMinutes . ' 分钟后再试');
        }

        $user = User::with(['role', 'hotel'])->where('username', $username)->find();
        
        // 用户不存在
        if (!$user) {
            // 记录失败日志
            LoginLog::record(null, $username, 'login', 'failed', '用户不存在', $ip, $userAgent, $clientInfo);
            $this->recordLoginFailure($username, $ip);
            return $this->error('用户名或密码错误');
        }

        // 密码验证失败
        if (!$user->verifyPassword($password)) {
            // 记录失败日志
            LoginLog::record($user->id, $username, 'login', 'failed', '密码错误', $ip, $userAgent, $clientInfo);
            $this->recordLoginFailure($username, $ip);
            return $this->error('用户名或密码错误');
        }

        // 账号被禁用
        if ($user->status != User::STATUS_ENABLED) {
            // 记录失败日志
            LoginLog::record($user->id, $username, 'login', 'failed', '账号待审核或已停用', $ip, $userAgent, $clientInfo);
            return $this->error('账号待超级管理员审核或已停用，请联系超级管理员启用');
        }

        // 更新用户登录信息
        $user->last_login_time = date('Y-m-d H:i:s');
        $user->last_login_ip = $ip;
        $user->login_count = $user->login_count + 1;
        $user->save();

        // 生成更安全的 Token
        $token = $this->generateToken($user->id);
        
        // 缓存 Token 信息
        $tokenData = [
            'user_id' => $user->id,
            'created_at' => time(),
            'ip' => $ip,
            'user_agent' => substr($userAgent, 0, 255),
        ];
        cache('token_' . $token, $tokenData, self::TOKEN_TTL_SECONDS);
        cache('user_token_' . $user->id, $token, self::TOKEN_TTL_SECONDS);
        cache($lockKey . ':attempts', null);
        cache($lockKey . ':locked_until', null);

        // 记录登录成功日志
        LoginLog::record($user->id, $username, 'login', 'success', null, $ip, $userAgent, $clientInfo);
        OperationLog::record('auth', 'login', '用户登录: ' . $username, $user->id, $user->hotel_id);

        $permittedHotels = $this->buildPermittedHotels($user);

        return $this->success([
            'token' => $token,
            'expires_in' => self::TOKEN_TTL_SECONDS,
            'user' => $this->buildLoginUserPayload($user, $permittedHotels),
            'context' => $this->buildAuthContext($user, $permittedHotels),
            'notices' => $this->buildLoginNotices($user, $permittedHotels),
        ], '登录成功');
    }

    /**
     * 生成安全的 Token
     */
    private function buildSelfRegistrationHotelPermissionDefaults(Role $role): array
    {
        $permissions = $role->getPermissionList();
        $allows = static fn(string $permission): int => Role::permissionListAllows($permissions, $permission) ? 1 : 0;

        return [
            'scope_type' => 'granted',
            'can_view' => 1,
            'can_report' => $allows('report.view'),
            'can_fill' => $allows('report.fill'),
            'can_edit' => $allows('hotel.update') || $allows('report.update') ? 1 : 0,
            'can_fetch_ota' => $allows('ota.collect'),
            'can_delete_ota' => $allows('ota.delete'),
            'can_export' => $allows('ota.export') || $allows('report.export') ? 1 : 0,
            'can_ai' => $allows('ai.view') || $allows('ai.execute') ? 1 : 0,
            'can_operation' => $allows('operation.view') || $allows('operation.execute') ? 1 : 0,
            'can_investment' => $allows('investment.view') || $allows('investment.simulate') ? 1 : 0,
            'status' => 'active',
            'created_by' => 0,
            'can_view_report' => $allows('report.view'),
            'can_fill_daily_report' => $allows('report.fill'),
            'can_fill_monthly_task' => $allows('report.fill'),
            'can_edit_report' => $allows('report.update'),
            'can_delete_report' => $allows('report.delete'),
            'can_view_online_data' => $allows('ota.view'),
            'can_fetch_online_data' => $allows('ota.collect'),
            'can_delete_online_data' => $allows('ota.delete'),
            'is_primary' => 1,
        ];
    }

    private function tableColumnExists(string $table, string $column): bool
    {
        $table = str_replace('`', '', $table);
        $column = str_replace(['`', "'"], '', $column);

        try {
            return !empty(Db::query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'"));
        } catch (\Throwable $e) {
            try {
                $rows = Db::query("PRAGMA table_info(`{$table}`)");
            } catch (\Throwable $ignored) {
                return false;
            }

            foreach ($rows as $row) {
                if (($row['name'] ?? '') === $column) {
                    return true;
                }
            }

            return false;
        }
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

        return Hotel::whereIn('id', array_values(array_map('intval', $permittedHotelIds)))
            ->select()
            ->toArray();
    }

    /**
     * @param array<int, array<string, mixed>> $permittedHotels
     * @return array<string, mixed>
     */
    private function buildLoginUserPayload(User $user, array $permittedHotels): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'realname' => $user->realname,
            'role_id' => $user->role_id,
            'role_name' => $user->role ? $user->role->display_name : '',
            'hotel_id' => $user->hotel_id,
            'hotel_name' => $user->hotel ? $user->hotel->name : '',
            'is_super_admin' => $user->isSuperAdmin(),
            'is_hotel_manager' => $user->isHotelManager(),
            'permitted_hotels' => $permittedHotels,
            'permissions' => $this->buildUserPermissions($user),
            'capabilities' => $this->buildUserCapabilities($user),
            'hotel_scope' => $user->getHotelScopeContext(),
            'modules' => $this->buildUserModules($user),
            'notices' => $this->buildLoginNotices($user, $permittedHotels),
        ];
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
        $deadline = self::BETA_HOTEL_BINDING_CUTOFF_DATE;
        $message = $hotelCount > 0
            ? "内测用户当前仅可查看已绑定或管理员分配的门店（{$hotelCount}家）。请在 {$deadline} 前确认门店绑定；{$deadline} 之后，未绑定或未分配的门店将无法查看。"
            : "内测用户当前未绑定或未被管理员分配门店。请在 {$deadline} 前绑定自己的门店或联系超级管理员分配；{$deadline} 之后将无法查看门店数据。";

        return [[
            'type' => 'beta_hotel_binding_deadline',
            'level' => 'warning',
            'deadline' => $deadline,
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
        $currentHotel = $this->resolveAuthContextHotel($user, $permittedHotels, $requestedHotelId);
        $hotelId = $requestedHotelId ?: ($currentHotel ? (int)($currentHotel['id'] ?? 0) : ((int)($user->hotel_id ?? 0) ?: null));
        $permissionStatus = 'unknown';
        if ($hotelId) {
            $permissionStatus = ($user->isSuperAdmin() || in_array((int)$hotelId, $permittedHotelIds, true)) ? 'allowed' : 'denied';
        }

        $tenantId = $this->positiveIntOrNull($currentHotel['tenant_id'] ?? $user->tenant_id ?? null);

        return [
            'tokenStatus' => 'valid',
            'hotelId' => $hotelId ?: null,
            'tenantId' => $tenantId,
            'platform' => $this->normalizeAuthContextPlatform($this->request->get('platform', 'unknown')),
            'currentHotelName' => $currentHotel ? (string)($currentHotel['name'] ?? $currentHotel['hotel_name'] ?? '') : '',
            'permissionStatus' => $permissionStatus,
            'platformLoginScope' => ['ctrip', 'meituan'],
        ];
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

    private function buildUserPermissions(User $user): array
    {
        return [
            'can_view_report' => $user->hasPermission('can_view_report'),
            'can_fill_daily_report' => $user->hasPermission('can_fill_daily_report'),
            'can_fill_monthly_task' => $user->hasPermission('can_fill_monthly_task'),
            'can_edit_report' => $user->hasPermission('can_edit_report'),
            'can_delete_report' => $user->hasPermission('can_delete_report'),
            'can_view_online_data' => $user->hasPermission('can_view_online_data'),
            'can_fetch_online_data' => $user->hasPermission('can_fetch_online_data'),
            'can_delete_online_data' => $user->hasPermission('can_delete_online_data'),
            'can_manage_own_hotels' => $user->canManageOwnHotels(),
            'can_manage_users' => $user->canManageUser(),
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
        ];
    }

    private function roleAllows(User $user, string $permission): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $role = $user->role;
        return $role instanceof Role
            && (int)$role->status === Role::STATUS_ENABLED
            && $role->hasPermission($permission);
    }

    private function isEnabledConfigValue($value): bool
    {
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'on', 'yes'], true);
    }

    private function resolveSelfRegistrationRole(): ?Role
    {
        return Role::where('status', Role::STATUS_ENABLED)
            ->where('level', '>=', Role::HOTEL_STAFF)
            ->order('level', 'desc')
            ->order('id', 'asc')
            ->find();
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

    private function loginLockKey(string $username, string $ip): string
    {
        return 'login_lock_' . sha1(strtolower(trim($username)) . '|' . $ip);
    }

    private function recordLoginFailure(string $username, string $ip): void
    {
        $maxAttempts = max(3, min(10, (int) SystemConfig::getValue(SystemConfig::KEY_LOGIN_MAX_ATTEMPTS, 10)));
        $lockMinutes = max(1, min(60, (int) SystemConfig::getValue(SystemConfig::KEY_LOGIN_LOCKOUT_DURATION, 1)));
        $lockKey = $this->loginLockKey($username, $ip);
        $attempts = (int) (cache($lockKey . ':attempts') ?: 0) + 1;

        if ($attempts >= $maxAttempts) {
            cache($lockKey . ':attempts', 0, $lockMinutes * 60);
            cache($lockKey . ':locked_until', time() + $lockMinutes * 60, $lockMinutes * 60);
            return;
        }

        cache($lockKey . ':attempts', $attempts, $lockMinutes * 60);
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

        $user = User::with(['role', 'hotel'])->find($this->currentUser->id);

        // 获取用户可访问的酒店（只包含启用的酒店）
        $permittedHotels = $this->buildPermittedHotels($user);
        
        $userPermissions = $this->buildUserPermissions($user);

        return $this->success([
            'id' => $user->id,
            'username' => $user->username,
            'realname' => $user->realname,
            'email' => $user->email,
            'phone' => $user->phone,
            'role_id' => $user->role_id,
            'role_name' => $user->role ? $user->role->display_name : '',
            'hotel_id' => $user->hotel_id,
            'hotel' => $user->hotel,
            'is_super_admin' => $user->isSuperAdmin(),
            'is_hotel_manager' => $user->isHotelManager(),
            'permitted_hotels' => $permittedHotels,
            'permissions' => $userPermissions,
            'capabilities' => $this->buildUserCapabilities($user),
            'hotel_scope' => $user->getHotelScopeContext(),
            'modules' => $this->buildUserModules($user),
            'context' => $this->buildAuthContext($user, $permittedHotels),
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

        OperationLog::record('auth', 'change_password', '修改密码', $user->id);

        return $this->success(null, '密码修改成功');
    }
}
