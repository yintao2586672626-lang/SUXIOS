<?php
declare(strict_types=1);

namespace app\middleware;

use app\model\User;
use Closure;
use think\Request;
use think\Response;

class Auth
{
    /**
     * 处理请求
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 获取 Token
        $authHeader = $request->header('Authorization', '');
        
        // 处理 Bearer Token 格式
        $token = '';
        if (preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
            $token = $matches[1];
        } elseif (!empty($authHeader)) {
            // 兼容直接传递 token 的情况
            $token = $authHeader;
        }
        
        if (empty($token)) {
            // 兼容通过URL参数传递token的场景（如后台页面打开）
            $token = (string)$request->param('token', '');
        }
        
        if (empty($token)) {
            return json([
                'code' => 401,
                'message' => '未提供认证令牌',
                'data' => null,
            ], 401);
        }

        // 从缓存中获取Token数据
        $tokenData = cache('token_' . $token);
        
        if (!$tokenData) {
            return json([
                'code' => 401,
                'message' => '登录已过期，请重新登录',
                'data' => null,
            ], 401);
        }

        // 兼容旧版Token格式
        $userId = is_array($tokenData) ? ($tokenData['user_id'] ?? null) : $tokenData;
        
        if (!$userId) {
            return json([
                'code' => 401,
                'message' => '认证信息无效',
                'data' => null,
            ], 401);
        }

        // 获取用户信息
        $user = User::find($userId);
        
        if (!$user) {
            return json([
                'code' => 401,
                'message' => '用户不存在',
                'data' => null,
            ], 401);
        }

        if ($user->status != User::STATUS_ENABLED) {
            return json([
                'code' => 403,
                'message' => '账号已被禁用',
                'data' => null,
            ], 403);
        }

        // 将用户信息注入请求
        $request->user = $user;

        return $next($request);
    }
}
