<?php
declare(strict_types=1);

namespace app\middleware;

use app\model\OperationLog;
use app\model\SystemNotification;
use app\model\User;
use app\service\OperationAuditClassifier;
use app\service\ProtectedCapabilityService;
use Closure;
use think\Request;
use think\Response;

class Auth
{
    private const TOKEN_MAX_AGE_SECONDS = 86400;

    private ?ProtectedCapabilityService $protectedCapabilityService = null;

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->resolveRequestId($request);
        $authHeader = (string)$request->header('Authorization', '');

        $token = '';
        if (preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
            $token = trim($matches[1]);
        } elseif ($authHeader !== '') {
            $token = trim($authHeader);
        }

        if ($token === '') {
            return $this->withRequestId($this->authErrorResponse(401, 'missing_token', $requestId), $requestId);
        }

        $tokenData = cache('token_' . $token);
        if (!$tokenData) {
            return $this->withRequestId($this->authErrorResponse(401, 'token_expired', $requestId), $requestId);
        }

        if ($this->isTokenExpiredByAge($tokenData)) {
            cache('token_' . $token, null);
            if (is_array($tokenData) && !empty($tokenData['user_id'])) {
                cache('user_token_' . $tokenData['user_id'], null);
            }
            return $this->withRequestId($this->authErrorResponse(401, 'token_expired', $requestId), $requestId);
        }

        $userId = is_array($tokenData) ? ($tokenData['user_id'] ?? null) : $tokenData;
        if (!$userId) {
            return $this->withRequestId($this->authErrorResponse(401, 'invalid_token', $requestId), $requestId);
        }

        $user = User::find($userId);
        if (!$user) {
            return $this->withRequestId($this->authErrorResponse(401, 'user_not_found', $requestId), $requestId);
        }

        if ($user->status != User::STATUS_ENABLED) {
            return $this->withRequestId($this->authErrorResponse(403, 'user_disabled', $requestId), $requestId);
        }

        $request->user = $user;
        $request->request_id = $requestId;

        $protectedCapabilityService = $this->protectedCapabilityService();
        $capability = $protectedCapabilityService->classifyPath($request->method(), $request->url());

        $rateLimitResponse = $this->enforceRateLimit($request, $user, $capability, $requestId);
        if ($rateLimitResponse !== null) {
            return $this->withRequestId($rateLimitResponse, $requestId);
        }

        if ($capability !== null) {
            $authorization = $protectedCapabilityService->authorizeContext($user, $capability, $request->param());
            if (empty($authorization['allowed'])) {
                $this->recordProtectedAccessDenied($request, $user, $capability, $authorization, $requestId);
                return $this->withRequestId($this->protectedAccessDeniedResponse($capability, $authorization, $requestId), $requestId);
            }
        }

        $response = $next($request);
        if ($capability !== null) {
            $response = $this->redactProtectedResponse($request, $response, $protectedCapabilityService, $capability, $user, $requestId);
        }
        $this->recordDataAudit($request, $response, $user);

        return $this->withRequestId($response, $requestId);
    }

    private function isTokenExpiredByAge($tokenData): bool
    {
        if (!is_array($tokenData)) {
            return false;
        }

        $createdAt = (int)($tokenData['created_at'] ?? 0);
        if ($createdAt <= 0) {
            return false;
        }

        return $createdAt + self::TOKEN_MAX_AGE_SECONDS < time();
    }

    private function protectedCapabilityService(): ProtectedCapabilityService
    {
        if ($this->protectedCapabilityService === null) {
            $this->protectedCapabilityService = new ProtectedCapabilityService();
        }

        return $this->protectedCapabilityService;
    }

    private function authErrorResponse(int $status, string $reason, string $requestId): Response
    {
        $messages = [
            'missing_token' => '未提供认证令牌',
            'token_expired' => '登录已过期，请重新登录',
            'invalid_token' => '认证信息无效',
            'user_not_found' => '用户不存在',
            'user_disabled' => '账号待审核或已停用，请联系超级管理员启用',
        ];

        return json([
            'code' => $status,
            'message' => $messages[$reason] ?? ($status === 403 ? 'Forbidden' : 'Unauthorized'),
            'data' => [
                'reason' => $reason,
                'reference_id' => $requestId,
            ],
            'request_id' => $requestId,
        ], $status);
    }

    /**
     * @param array<string, mixed> $capability
     * @param array<string, mixed> $authorization
     */
    private function protectedAccessDeniedResponse(array $capability, array $authorization, string $requestId): Response
    {
        return json([
            'code' => 403,
            'message' => 'Forbidden: protected capability is not authorized',
            'data' => [
                'redacted' => true,
                'redacted_reason' => (string)($authorization['reason'] ?? 'protected_capability_denied'),
                'reference_id' => $requestId,
                'protected_capability' => (string)($capability['key'] ?? ''),
                'required_permission' => (string)($authorization['required_permission'] ?? $capability['permission'] ?? ''),
                'required_module' => (string)($authorization['required_module'] ?? $capability['module'] ?? ''),
                'tenant_id' => (int)($authorization['tenant_id'] ?? 0),
                'hotel_id' => (int)($authorization['hotel_id'] ?? 0),
            ],
            'request_id' => $requestId,
        ], 403);
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
                    'request_id' => (string)($request->request_id ?? ''),
                    'response_status' => $statusCode,
                ]
            );
        } catch (\Throwable $e) {
            // Audit logging must not break the protected business request.
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

    /**
     * @param array<string, mixed>|null $capability
     */
    private function enforceRateLimit(Request $request, User $user, ?array $capability = null, string $requestId = ''): ?Response
    {
        $policy = $this->resolveRateLimitPolicy($request->method(), $request->url(), $capability);
        $window = max(1, (int)$policy['window']);
        $limit = (int)$policy['limit'];
        if ($limit <= 0) {
            return null;
        }
        $bucket = (int)floor(time() / $window);
        $params = $this->sanitizeAuditParams($request->param());
        $tenantId = $this->resolveTenantIdForRateLimit($params, $user);
        $path = (string)$policy['path'];
        $key = $this->buildRateLimitCacheKey(
            $tenantId,
            (int)$user->id,
            (string)$request->ip(),
            (string)$policy['scope'],
            strtoupper($request->method()),
            $path,
            $bucket
        );
        $count = (int)cache($key);

        if ($count >= $limit) {
            $retryAfter = max(1, (($bucket + 1) * $window) - time());
            $this->recordRateLimitExceeded($request, $user, $policy, $tenantId, $requestId, $retryAfter);

            return json([
                'code' => 429,
                'message' => 'Too many requests',
                'data' => [
                    'retry_after' => $retryAfter,
                    'limit' => $limit,
                    'window' => $window,
                    'tenant_id' => $tenantId,
                    'reference_id' => $requestId,
                ],
                'request_id' => $requestId,
            ], 429, ['Retry-After' => (string)$retryAfter]);
        }

        cache($key, $count + 1, $window + 5);

        return null;
    }

    /**
     * @param array<string, mixed>|null $capability
     * @return array<string, mixed>
     */
    private function resolveRateLimitPolicy(string $method, string $uri, ?array $capability = null): array
    {
        $path = $this->normalizeRateLimitPath($uri);
        $method = strtoupper($method);

        if ($method === 'GET' && in_array($path, [
            'api/online-data/get-ctrip-config-list',
            'api/online-data/get-ctrip-config-detail',
            'api/online-data/get-meituan-config-list',
            'api/online-data/get-meituan-config-detail',
            'api/online-data/cookies-list',
            'api/online-data/cookies-detail',
        ], true)) {
            return ['scope' => 'ota_config_read', 'path' => $path, 'limit' => 0, 'window' => 60];
        }

        if (is_array($capability) && isset($capability['rate_limit']) && is_array($capability['rate_limit'])) {
            $rateLimit = $capability['rate_limit'];
            return [
                'scope' => (string)($rateLimit['scope'] ?? ('protected_' . ($capability['key'] ?? 'capability'))),
                'path' => $path,
                'limit' => (int)($rateLimit['limit'] ?? 30),
                'window' => (int)($rateLimit['window'] ?? 3600),
                'capability' => (string)($capability['key'] ?? ''),
            ];
        }

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

    private function buildRateLimitCacheKey(
        int $tenantId,
        int $userId,
        string $ip,
        string $scope,
        string $method,
        string $path,
        int $bucket
    ): string {
        return sprintf(
            'rate_limit_tenant_%d_user_%d_ip_%s_scope_%s_endpoint_%s_window_%d',
            $tenantId,
            $userId,
            substr(sha1($ip), 0, 16),
            preg_replace('/[^a-z0-9_\-]/i', '_', $scope),
            sha1(strtoupper($method) . ':' . strtolower($path)),
            $bucket
        );
    }

    private function resolveTenantIdForRateLimit(array $params, User $user): int
    {
        foreach (['tenant_id', 'system_hotel_id', 'hotel_id'] as $key) {
            if (isset($params[$key]) && is_numeric($params[$key]) && (int)$params[$key] > 0) {
                return (int)$params[$key];
            }
        }

        foreach (['tenant_id', 'hotel_id'] as $key) {
            if (isset($user->{$key}) && is_numeric($user->{$key}) && (int)$user->{$key} > 0) {
                return (int)$user->{$key};
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $policy
     */
    private function recordRateLimitExceeded(Request $request, User $user, array $policy, int $tenantId, string $requestId, int $retryAfter): void
    {
        $hotelId = $this->resolveAuditHotelId($this->sanitizeAuditParams($request->param()), $user);
        try {
            OperationLog::record(
                'security',
                'rate_limited',
                'Protected request rate limited: ' . $policy['path'],
                (int)$user->id,
                $hotelId,
                'HTTP 429',
                [
                    'audit_type' => 'security',
                    'method' => strtoupper($request->method()),
                    'path' => $policy['path'],
                    'scope' => $policy['scope'],
                    'capability' => $policy['capability'] ?? '',
                    'limit' => $policy['limit'],
                    'window' => $policy['window'],
                    'tenant_id' => $tenantId,
                    'request_id' => $requestId,
                    'retry_after' => $retryAfter,
                ]
            );
        } catch (\Throwable $e) {
            // Security audit failure must not mask the actual 429 boundary.
        }

        $this->recordSecurityNotification('rate_limited', $user, $hotelId, $requestId, [
            'path' => (string)$policy['path'],
            'scope' => (string)$policy['scope'],
            'tenant_id' => $tenantId,
            'retry_after' => $retryAfter,
        ]);
    }

    /**
     * @param array<string, mixed> $capability
     * @param array<string, mixed> $authorization
     */
    private function recordProtectedAccessDenied(Request $request, User $user, array $capability, array $authorization, string $requestId): void
    {
        $hotelId = (int)($authorization['hotel_id'] ?? 0) ?: $this->resolveAuditHotelId($this->sanitizeAuditParams($request->param()), $user);
        try {
            OperationLog::record(
                'security',
                'protected_access_denied',
                'Protected capability denied: ' . ($capability['key'] ?? ''),
                (int)$user->id,
                $hotelId ?: null,
                'HTTP 403',
                [
                    'audit_type' => 'security',
                    'method' => strtoupper($request->method()),
                    'path' => $capability['path'] ?? $this->normalizeRateLimitPath($request->url()),
                    'capability' => $capability['key'] ?? '',
                    'reason' => $authorization['reason'] ?? '',
                    'tenant_id' => (int)($authorization['tenant_id'] ?? 0),
                    'hotel_id' => $hotelId ?: null,
                    'required_permission' => $authorization['required_permission'] ?? $capability['permission'] ?? '',
                    'required_module' => $authorization['required_module'] ?? $capability['module'] ?? '',
                    'request_id' => $requestId,
                ]
            );
        } catch (\Throwable $e) {
            // Security audit failure must not mask the actual 403 boundary.
        }

        $this->recordSecurityNotification('protected_access_denied', $user, $hotelId ?: null, $requestId, [
            'path' => (string)($capability['path'] ?? ''),
            'capability' => (string)($capability['key'] ?? ''),
            'reason' => (string)($authorization['reason'] ?? ''),
            'tenant_id' => (int)($authorization['tenant_id'] ?? 0),
            'hotel_id' => $hotelId ?: null,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function recordSecurityNotification(string $category, User $user, ?int $hotelId, string $requestId, array $payload): void
    {
        try {
            SystemNotification::recordEvent([
                'hotel_id' => $hotelId,
                'user_id' => (int)$user->id,
                'platform' => 'system',
                'category' => 'security',
                'severity' => 'warning',
                'title' => 'SUXIOS security guard',
                'message' => $category . ' reference=' . $requestId,
                'action_type' => 'security_review',
                'action_payload' => $payload,
                'source_module' => 'security',
                'source_key' => 'security:' . $category . ':' . $requestId,
            ]);
        } catch (\Throwable $e) {
            // Notification delivery is best-effort; OperationLog remains the source of record.
        }
    }

    /**
     * @param array<string, mixed> $capability
     */
    private function redactProtectedResponse(
        Request $request,
        Response $response,
        ProtectedCapabilityService $service,
        array $capability,
        User $user,
        string $requestId
    ): Response {
        $payload = $this->jsonResponsePayload($response);
        if ($payload === null) {
            return $response;
        }

        if ($service->shouldRedactForUser($user, $capability)) {
            $payload = $service->redactPayload($payload, $capability, $requestId);
        } else {
            $payload['request_id'] = $requestId;
        }
        $payload['protected_trace'] = [
            'tenant_id' => $service->resolveTenantId($request->param(), $user) ?: null,
            'user_id' => (int)$user->id,
            'hotel_id' => $service->resolveHotelId($request->param(), $user) ?: null,
            'request_id' => $requestId,
            'generated_at' => date('Y-m-d H:i:s'),
        ];

        $response->data($payload);
        $response->content(json_encode($payload, JSON_UNESCAPED_UNICODE));

        return $response;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function jsonResponsePayload(Response $response): ?array
    {
        if (method_exists($response, 'getData')) {
            $data = $response->getData();
            if (is_array($data)) {
                return $data;
            }
        }

        $content = trim($response->getContent());
        if ($content === '' || !in_array($content[0], ['{', '['], true)) {
            return null;
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function resolveRequestId(Request $request): string
    {
        $candidate = trim((string)($request->header('X-Request-ID', '') ?: $request->header('X-Correlation-ID', '')));
        if ($candidate !== '' && preg_match('/^[A-Za-z0-9_.:\-]{8,96}$/', $candidate)) {
            return substr($candidate, 0, 96);
        }

        return 'suxios_' . date('YmdHis') . '_' . bin2hex(random_bytes(8));
    }

    private function withRequestId(Response $response, string $requestId): Response
    {
        return $response->header(['X-Request-ID' => $requestId]);
    }
}
