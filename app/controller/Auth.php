<?php
declare(strict_types=1);

namespace app\controller;

use app\model\User;
use app\model\OperationLog;
use app\model\LoginLog;
use think\exception\ValidateException;
use think\Response;

class Auth extends Base
{
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

        $user = User::with(['role', 'hotel'])->where('username', $username)->find();
        
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
            LoginLog::record($user->id, $username, 'login', 'failed', '账号已被禁用', $ip, $userAgent, $clientInfo);
            return $this->error('账号已被禁用，请联系管理员');
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
        cache('token_' . $token, $tokenData, 86400); // 缓存24小时
        cache('user_token_' . $user->id, $token, 86400);

        // 记录登录成功日志
        LoginLog::record($user->id, $username, 'login', 'success', null, $ip, $userAgent, $clientInfo);
        OperationLog::record('auth', 'login', '用户登录: ' . $username, $user->id, $user->hotel_id);

        return $this->success([
            'token' => $token,
            'expires_in' => 86400,
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'realname' => $user->realname,
                'role_id' => $user->role_id,
                'role_name' => $user->role ? $user->role->display_name : '',
                'hotel_id' => $user->hotel_id,
                'hotel_name' => $user->hotel ? $user->hotel->name : '',
                'is_super_admin' => $user->isSuperAdmin(),
            ],
        ], '登录成功');
    }

    /**
     * 生成安全的 Token
     */
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
        $token = $this->request->header('Authorization', '');
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
        $permittedHotelIds = $user->getPermittedHotelIds();
        $permittedHotels = [];
        if (!empty($permittedHotelIds)) {
            $permittedHotels = \app\model\Hotel::whereIn('id', $permittedHotelIds)->select();
        }
        
        // 从角色继承权限
        $userPermissions = [
            'can_view_report' => $user->hasPermission('can_view_report'),
            'can_fill_daily_report' => $user->hasPermission('can_fill_daily_report'),
            'can_fill_monthly_task' => $user->hasPermission('can_fill_monthly_task'),
            'can_edit_report' => $user->hasPermission('can_edit_report'),
            'can_delete_report' => $user->hasPermission('can_delete_report'),
        ];

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

        if (strlen($newPassword) < 6) {
            return $this->error('新密码长度至少6位');
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
