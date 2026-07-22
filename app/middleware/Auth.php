<?php
declare(strict_types=1);

namespace app\middleware;

use app\model\OperationLog;
use app\model\SystemNotification;
use app\model\User;
use app\service\FixedWindowRateLimiter;
use app\service\OperationAuditClassifier;
use app\service\OperationAuditSanitizerService;
use app\service\ProtectedCapabilityService;
use Closure;
use think\Request;
use think\Response;

class Auth
{
    private const TOKEN_MAX_AGE_SECONDS = 259200; // 72 hours

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

        $currentAuthVersion = $user->authSessionVersion();
        $tokenAuthVersion = is_array($tokenData) ? trim((string)($tokenData['auth_version'] ?? '')) : '';
        if ($tokenAuthVersion === '') {
            $tokenAuthVersion = $this->upgradeLegacyTokenAuthVersion(
                $token,
                $tokenData,
                (int)$userId,
                $currentAuthVersion
            ) ? $currentAuthVersion : '';
        }
        if ($tokenAuthVersion === '' || !hash_equals($currentAuthVersion, $tokenAuthVersion)) {
            cache('token_' . $token, null);
            if ((string)cache('user_token_' . $userId) === $token) {
                cache('user_token_' . $userId, null);
            }
            return $this->withRequestId($this->authErrorResponse(401, 'token_revoked', $requestId), $requestId);
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

        try {
            $response = $next($request);
        } catch (\Throwable $exception) {
            $this->recordControllerExceptionAudit($request, $user, $requestId, $exception);
            throw $exception;
        }
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

    /**
     * Preserve valid pre-upgrade sessions without weakening password-change
     * revocation. A legacy token is upgraded once, only when no password
     * change happened after it was issued.
     */
    private function upgradeLegacyTokenAuthVersion(
        string $token,
        mixed $tokenData,
        int $userId,
        string $authVersion
    ): bool {
        if (!is_array($tokenData)) {
            return false;
        }

        $createdAt = (int)($tokenData['created_at'] ?? 0);
        $remainingTtl = self::TOKEN_MAX_AGE_SECONDS - max(0, time() - $createdAt);
        if ($createdAt <= 0 || $remainingTtl <= 0) {
            return false;
        }

        try {
            $revokedAt = $this->normalizeTimestamp(cache('auth_revoked_after_' . $userId));
            $revokedAt = max(
                $revokedAt,
                OperationLog::latestCredentialRevocationTimestamp(
                    $userId,
                    time() - self::TOKEN_MAX_AGE_SECONDS
                )
            );
            if ($revokedAt > 0 && $createdAt <= $revokedAt) {
                return false;
            }

            $tokenData['auth_version'] = $authVersion;
            cache('token_' . $token, $tokenData, $remainingTtl);
            $stored = cache('token_' . $token);
            return is_array($stored)
                && hash_equals($authVersion, trim((string)($stored['auth_version'] ?? '')));
        } catch (\Throwable) {
            return false;
        }
    }

    private function normalizeTimestamp(mixed $value): int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }
        if (is_numeric($value)) {
            return max(0, (int)$value);
        }

        $timestamp = strtotime(trim((string)$value));
        return $timestamp === false ? 0 : $timestamp;
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
            'token_revoked' => '登录凭证已撤销，请重新登录',
            'invalid_token' => '认证信息无效',
            'user_not_found' => '用户不存在',
            'user_disabled' => '账号已停用，请联系管理员启用',
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
            $statusCode = $response->getCode();
            $classifier = new OperationAuditClassifier();
            $audit = $statusCode >= 400
                ? $classifier->classifyFailure($request->method(), $request->url())
                : $classifier->classify($request->method(), $request->url());
            if ($audit === null) {
                return;
            }

            $params = $this->sanitizeAuditRequestParams($request, (string)$audit['path']);
            $hotelId = $this->resolveAuditHotelId($params, $user);
            $failed = $statusCode >= 400;

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
                    'outcome' => $failed ? 'failed' : 'success',
                    'http_status' => $statusCode,
                    'response_status' => $statusCode,
                    'reason_code' => $failed ? 'http_response_failure' : null,
                ]
            );
        } catch (\Throwable $e) {
            // Audit logging must not break the protected business request.
        }
    }

    private function recordControllerExceptionAudit(
        Request $request,
        User $user,
        string $requestId,
        \Throwable $exception
    ): void {
        try {
            $audit = (new OperationAuditClassifier())->classifyFailure($request->method(), $request->url());
            if ($audit === null) {
                return;
            }

            $params = $this->sanitizeAuditRequestParams($request, (string)$audit['path']);
            $hotelId = $this->resolveAuditHotelId($params, $user);

            OperationLog::record(
                $audit['module'],
                $audit['action'],
                $audit['description'],
                (int)$user->id,
                $hotelId,
                'controller_exception',
                [
                    'audit_type' => $audit['category'],
                    'method' => strtoupper($request->method()),
                    'path' => $audit['path'],
                    'params' => $params,
                    'request_id' => $requestId,
                    'outcome' => 'failed',
                    'http_status' => 500,
                    'response_status' => 500,
                    'reason_code' => 'controller_exception',
                    'exception_type' => get_debug_type($exception),
                ]
            );
        } catch (\Throwable $auditFailure) {
            // Terminal audit failure must not replace the controller exception.
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
        $safe = (new OperationAuditSanitizerService())->sanitizeArray($params, 1000);
        return $this->truncateAuditParams($safe, 120);
    }

    /** @param array<mixed> $params */
    private function truncateAuditParams(array $params, int $limit): array
    {
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $params[$key] = $this->truncateAuditParams($value, $limit);
                continue;
            }
            if (is_string($value) && mb_strlen($value) > $limit) {
                $params[$key] = mb_substr($value, 0, $limit) . '...';
            }
        }

        return $params;
    }

    private function sanitizeAuditRequestParams(Request $request, string $path): array
    {
        $params = $this->sanitizeAuditParams($request->param());
        if (!$this->isCredentialAuditPath($path)) {
            return $params;
        }

        $params = array_intersect_key($params, array_flip([
            'id',
            'config_id',
            'hotel_id',
            'system_hotel_id',
            'platform',
            'channel',
            'status',
        ]));
        $params['credential_payload_redacted'] = true;

        return $params;
    }

    private function isCredentialAuditPath(string $path): bool
    {
        $path = trim(strtolower($path), '/');
        foreach ([
            'api/online-data/save-ctrip-config',
            'api/online-data/save-meituan-config',
            'api/online-data/data-sources',
        ] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
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
        $params = $this->sanitizeAuditParams($request->param());
        $tenantId = $this->resolveTenantIdForRateLimit($params, $user);
        $path = (string)$policy['path'];
        $key = $this->buildRateLimitCacheKey(
            $tenantId,
            (int)$user->id,
            (string)$request->ip(),
            (string)$policy['scope'],
            strtoupper($request->method()),
            $path
        );
        try {
            $rateLimit = $this->fixedWindowRateLimiter()->consume($key, $limit, $window);
        } catch (\Throwable $exception) {
            \think\facade\Log::error('Authenticated rate limiter unavailable.', [
                'exception_type' => get_debug_type($exception),
                'tenant_id' => $tenantId,
                'user_id' => (int)$user->id,
                'scope' => (string)$policy['scope'],
                'path' => $path,
                'request_id' => $requestId,
            ]);

            return json([
                'code' => 503,
                'message' => 'Rate limiter unavailable',
                'data' => [
                    'reason' => 'rate_limiter_unavailable',
                    'reference_id' => $requestId,
                ],
                'request_id' => $requestId,
            ], 503, ['Retry-After' => '1']);
        }

        if (!$rateLimit['allowed']) {
            $retryAfter = $rateLimit['retry_after'];
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

        return null;
    }

    protected function fixedWindowRateLimiter(): FixedWindowRateLimiter
    {
        return new FixedWindowRateLimiter();
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

        if ($method === 'POST' && in_array($path, [
            'api/online-data/fetch-ctrip',
            'api/online-data/fetch-ctrip-temporary-cookie',
            'api/online-data/fetch-meituan',
        ], true)) {
            return [
                'scope' => 'protected_ota_manual_fetch',
                'path' => $path,
                'limit' => 600,
                'window' => 3600,
                'capability' => (string)($capability['key'] ?? 'online_data'),
            ];
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
        string $path
    ): string {
        return sprintf(
            'rate_limit_tenant_%d_user_%d_ip_%s_scope_%s_endpoint_%s_window',
            $tenantId,
            $userId,
            substr(sha1($ip), 0, 16),
            preg_replace('/[^a-z0-9_\-]/i', '_', $scope),
            sha1(strtoupper($method) . ':' . strtolower($path))
        );
    }

    private function resolveTenantIdForRateLimit(array $_params, User $user): int
    {
        return isset($user->tenant_id) && is_numeric($user->tenant_id)
            ? max(0, (int)$user->tenant_id)
            : 0;
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
            'tenant_id' => $service->resolveTenantId($user) ?: null,
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
