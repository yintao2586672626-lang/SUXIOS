<?php
declare(strict_types=1);

namespace app\middleware;

use app\model\OperationLog;
use app\model\User;
use app\service\OperationAuditClassifier;
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

        $response = $next($request);
        $this->recordDataAudit($request, $response, $user);

        return $response;
    }

    private function recordDataAudit(Request $request, Response $response, User $user): void
    {
        try {
            $audit = (new OperationAuditClassifier())->classify($request->method(), $request->url());
            if ($audit === null) {
                return;
            }

            $params = $this->sanitizeAuditParams($request->param());
            $hotelId = $this->resolveAuditHotelId($params, $user);
            $statusCode = $response->getCode();

            OperationLog::record(
                $audit['module'],
                $audit['action'],
                $audit['description'],
                (int)$user->id,
                $hotelId,
                $statusCode >= 400 ? 'HTTP ' . $statusCode : null,
                [
                    'audit_type' => $audit['category'],
                    'method' => strtoupper($request->method()),
                    'path' => $audit['path'],
                    'params' => $params,
                    'response_status' => $statusCode,
                ]
            );
        } catch (\Throwable $e) {
            // 审计日志不能影响主业务请求。
        }
    }

    private function resolveAuditHotelId(array $params, User $user): ?int
    {
        foreach (['system_hotel_id', 'hotel_id'] as $key) {
            if (isset($params[$key]) && is_numeric($params[$key]) && (int)$params[$key] > 0) {
                return (int)$params[$key];
            }
        }

        return !empty($user->hotel_id) ? (int)$user->hotel_id : null;
    }

    private function sanitizeAuditParams(array $params): array
    {
        $sensitiveKeys = ['authorization', 'token', 'password', 'cookie', 'cookies', 'api_key', 'apikey', 'auth_data', 'headers'];
        $safe = [];

        foreach ($params as $key => $value) {
            if (in_array(strtolower((string)$key), $sensitiveKeys, true)) {
                $safe[$key] = '***';
                continue;
            }

            if (is_array($value)) {
                $safe[$key] = $this->sanitizeAuditParams($value);
                continue;
            }

            $text = is_scalar($value) || $value === null ? (string)$value : '[object]';
            $safe[$key] = mb_strlen($text) > 120
                ? mb_substr($text, 0, 120) . '...'
                : (is_scalar($value) || $value === null ? $value : $text);
        }

        return $safe;
    }
}
