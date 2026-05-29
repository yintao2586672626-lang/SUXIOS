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

        $rateLimitResponse = $this->enforceRateLimit($request, $user);
        if ($rateLimitResponse !== null) {
            return $rateLimitResponse;
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

    private function enforceRateLimit(Request $request, User $user): ?Response
    {
        $policy = $this->resolveRateLimitPolicy($request->method(), $request->url());
        $window = max(1, (int)$policy['window']);
        $limit = max(1, (int)$policy['limit']);
        $bucket = (int)floor(time() / $window);
        $key = sprintf(
            'rate_limit_%d_%s_%s_%d',
            (int)$user->id,
            $policy['scope'],
            sha1(strtoupper($request->method()) . ':' . $policy['path']),
            $bucket
        );
        $count = (int)cache($key);

        if ($count >= $limit) {
            $retryAfter = max(1, (($bucket + 1) * $window) - time());
            OperationLog::record(
                'security',
                'rate_limited',
                '请求触发限流: ' . $policy['path'],
                (int)$user->id,
                $this->resolveAuditHotelId($this->sanitizeAuditParams($request->param()), $user),
                'HTTP 429',
                [
                    'audit_type' => 'operation',
                    'method' => strtoupper($request->method()),
                    'path' => $policy['path'],
                    'scope' => $policy['scope'],
                    'limit' => $limit,
                    'window' => $window,
                ]
            );

            return json([
                'code' => 429,
                'message' => '请求过于频繁，请稍后再试',
                'data' => [
                    'retry_after' => $retryAfter,
                    'limit' => $limit,
                    'window' => $window,
                ],
            ], 429, ['Retry-After' => (string)$retryAfter]);
        }

        cache($key, $count + 1, $window + 5);

        return null;
    }

    private function resolveRateLimitPolicy(string $method, string $uri): array
    {
        $path = $this->normalizeRateLimitPath($uri);
        $method = strtoupper($method);

        if ($path === 'api/daily-reports/export' || str_contains($path, '/export')) {
            return ['scope' => 'export', 'path' => $path, 'limit' => 10, 'window' => 3600];
        }

        foreach (['batch', 'import', 'auto-fetch', 'sync', 'ai-analysis', 'analyze'] as $keyword) {
            if (str_contains($path, $keyword)) {
                return ['scope' => 'heavy', 'path' => $path, 'limit' => 30, 'window' => 3600];
            }
        }

        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return ['scope' => 'write', 'path' => $path, 'limit' => 60, 'window' => 60];
        }

        return ['scope' => 'read', 'path' => $path, 'limit' => 180, 'window' => 60];
    }

    private function normalizeRateLimitPath(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path)) {
            $path = $uri;
        }

        return trim(strtolower($path), '/');
    }
}
