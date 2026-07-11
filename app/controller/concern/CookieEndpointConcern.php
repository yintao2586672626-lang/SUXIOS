<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\model\OperationLog;
use think\Response;

trait CookieEndpointConcern
{
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
        $value = preg_replace(
            '/\b(cookie|set-cookie|authorization|proxy-authorization|x-api-key|api-key|token|access-token|refresh-token|spidertoken|spiderkey|mtgsig)\s*[:=]\s*[^\r\n]*/iu',
            '$1=****',
            $value
        ) ?: '';
        $value = preg_replace('/\bbearer\s+[A-Za-z0-9._~+\/=:-]{4,}/iu', 'Bearer ****', $value) ?: '';
        $value = preg_replace(
            '/\b([A-Za-z0-9_.-]*(?:session|token|auth|cookie|sid)[A-Za-z0-9_.-]*)\s*=\s*[^;\s,]+/iu',
            '$1=****',
            $value
        ) ?: '';
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?: '';

        return mb_substr($value, 0, 160);
    }

    private function buildPublicEndpointSecurityRow(string $endpoint, array $logs, array $meta): array
    {
        $failureActions = array_values(array_filter(array_map(
            'strval',
            (array)($meta['failure_actions'] ?? [$endpoint . '_public_failure'])
        )));
        $failureScope = (string)($meta['failure_scope'] ?? '');
        $endpointLogs = array_values(array_filter(
            $logs,
            function (array $log) use ($failureActions, $failureScope): bool {
                if (!in_array((string)($log['action'] ?? ''), $failureActions, true)) {
                    return false;
                }
                if ($failureScope === '' || (string)($log['action'] ?? '') !== 'external_rate_limited') {
                    return true;
                }
                $extra = $this->decodePublicEndpointFailureExtra($log);
                return (string)($extra['scope'] ?? '') === $failureScope;
            }
        ));
        $reasonCounts = [];
        $statusCounts = [];
        foreach ($endpointLogs as $log) {
            $extra = $this->decodePublicEndpointFailureExtra($log);
            $reason = $this->publicEndpointFailureReason($log, $extra);
            $status = $this->publicEndpointFailureStatus($log, $extra);
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
            'last_failure' => isset($endpointLogs[0]) ? $this->serializePublicEndpointFailureLog($endpointLogs[0], $endpoint) : null,
            'reason_counts' => $reasonCounts,
            'status_counts' => $statusCounts,
            'security_note' => 'Audited failures store endpoint, reason, status, method, origin and hashed IP only; secrets are masked.',
        ];
    }

    private function serializePublicEndpointFailureLog(array $log, string $endpoint = ''): array
    {
        $extra = $this->decodePublicEndpointFailureExtra($log);
        return [
            'id' => (int)($log['id'] ?? 0),
            'endpoint' => $this->safePublicEndpointText((string)($extra['endpoint'] ?? ($endpoint !== '' ? $endpoint : str_replace('_public_failure', '', (string)($log['action'] ?? ''))))),
            'reason' => $this->publicEndpointFailureReason($log, $extra),
            'status' => (int)$this->publicEndpointFailureStatus($log, $extra),
            'method' => $this->safePublicEndpointText((string)($extra['method'] ?? '')),
            'origin' => $this->safePublicEndpointText((string)($extra['origin'] ?? '')),
            'ip_hash' => $this->safePublicEndpointText((string)($extra['ip_hash'] ?? '')),
            'time' => (string)($log['create_time'] ?? ''),
            'error_info' => $this->safePublicEndpointText((string)($log['error_info'] ?? '')),
        ];
    }

    private function decodePublicEndpointFailureExtra(array $log): array
    {
        $raw = (string)($log['extra_data'] ?? '');
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $this->sanitizePublicEndpointExtra($decoded) : [];
    }

    private function publicEndpointFailureReason(array $log, array $extra): string
    {
        $reason = trim((string)($extra['reason'] ?? ''));
        if ($reason !== '') {
            return $this->safePublicEndpointText($reason);
        }
        if ((string)($log['action'] ?? '') === 'external_rate_limited') {
            return 'rate_limited';
        }
        $errorInfo = trim((string)($log['error_info'] ?? ''));
        return $errorInfo !== '' ? $this->safePublicEndpointText($errorInfo) : 'unknown';
    }

    private function publicEndpointFailureStatus(array $log, array $extra): string
    {
        $status = trim((string)($extra['status'] ?? ''));
        if ($status !== '') {
            return $status;
        }
        $errorInfo = (string)($log['error_info'] ?? '');
        if (preg_match('/HTTP\s+(\d{3})/i', $errorInfo, $matches)) {
            return $matches[1];
        }
        return 'unknown';
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
            return $this->corsError('Too many receive-cookies requests, please retry later.', 429);
        }

        $this->recordPublicEndpointFailure('receive_cookies', 'legacy_bookmarklet_disabled', 410, [
            'source_present' => trim((string)$this->request->post('source', '')) !== '',
            'name_present' => trim((string)$this->request->post('name', '')) !== '',
        ]);
        return $this->corsError('旧版 Cookie 书签入口已禁用：禁止把宿析登录 token 暴露到 OTA 页面；请使用平台采集源或浏览器 Profile。', 410);
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
        $competitorActions = [
            'task_denied',
            'report_denied',
            'external_rate_limited',
        ];

        $logs = OperationLog::where('module', 'online_data')
            ->whereIn('action', $actions)
            ->whereBetween('create_time', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->order('create_time', 'desc')
            ->limit(100)
            ->select()
            ->toArray();
        $competitorLogs = OperationLog::where('module', 'competitor')
            ->whereIn('action', $competitorActions)
            ->whereBetween('create_time', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->order('create_time', 'desc')
            ->limit(100)
            ->select()
            ->toArray();
        $logs = array_merge($logs, $competitorLogs);
        usort($logs, static fn(array $a, array $b): int => strcmp((string)($b['create_time'] ?? ''), (string)($a['create_time'] ?? '')));
        $logs = array_slice($logs, 0, 100);

        return $this->success([
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'days' => $days,
            ],
            'scope' => 'public OTA collection and competitor collector endpoints; status only, no Cookie/token/header values',
            'endpoints' => [
                $this->buildPublicEndpointSecurityRow('receive_cookies', $logs, [
                    'method' => 'POST|OPTIONS',
                    'path' => '/api/online-data/receive-cookies',
                    'auth' => 'legacy bookmarklet disabled; no current-session token accepted',
                    'rate_limit' => ['limit' => 30, 'window_seconds' => 60],
                    'token_configured' => false,
                ]),
                $this->buildPublicEndpointSecurityRow('cron_trigger', $logs, [
                    'method' => 'GET',
                    'path' => '/api/online-data/cron-trigger',
                    'auth' => 'X-Cron-Token header only',
                    'rate_limit' => ['limit' => 20, 'window_seconds' => 60],
                    'token_configured' => trim((string)\think\facade\Env::get('CRON_TOKEN', '')) !== '',
                ]),
                $this->buildPublicEndpointSecurityRow('daily_workbench_patrol_cron', $logs, [
                    'method' => 'GET',
                    'path' => '/api/online-data/daily-workbench-patrol-cron',
                    'auth' => 'X-Cron-Token header only',
                    'rate_limit' => ['limit' => 10, 'window_seconds' => 60],
                    'token_configured' => trim((string)\think\facade\Env::get('CRON_TOKEN', '')) !== '',
                ]),
                $this->buildPublicEndpointSecurityRow('competitor_task', $logs, [
                    'method' => 'POST',
                    'path' => '/api/competitor/task',
                    'auth' => 'X-Task-Token header only',
                    'rate_limit' => ['limit' => 30, 'window_seconds' => 60],
                    'token_configured' => trim((string)\think\facade\Env::get('COMPETITOR_TASK_TOKEN', '')) !== '',
                    'failure_actions' => ['task_denied', 'external_rate_limited'],
                    'failure_scope' => 'task',
                ]),
                $this->buildPublicEndpointSecurityRow('competitor_report', $logs, [
                    'method' => 'POST',
                    'path' => '/api/competitor/report',
                    'auth' => 'X-Report-Token header only',
                    'rate_limit' => ['limit' => 60, 'window_seconds' => 60],
                    'token_configured' => trim((string)\think\facade\Env::get('COMPETITOR_REPORT_TOKEN', '')) !== '',
                    'failure_actions' => ['report_denied', 'external_rate_limited'],
                    'failure_scope' => 'report',
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

        $script = $this->buildDisabledCookieBookmarkletScript('携程');

        return $this->success([
            'script' => $script,
            'bookmarklet' => 'javascript:' . $script,
            'status' => 'disabled_by_policy',
            'instructions' => [
                '旧版 Cookie 书签已禁用，避免把宿析登录 token 暴露到 OTA 页面。',
                '新增或更换凭据请使用平台采集源；日常采集请使用门店浏览器 Profile。',
            ],
        ]);
    }

    private function buildDisabledCookieBookmarkletScript(string $platform): string
    {
        $message = sprintf(
            '%s Cookie 书签已禁用：禁止把宿析登录 token 暴露到 OTA 页面；请回到宿析OS使用平台采集源或浏览器 Profile。',
            $platform
        );
        $messageJson = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return '(function(){alert(' . $messageJson . ');})();';
    }

    public function saveCookies(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');
        return $this->error(
            'Legacy Cookie storage is disabled. Save or replace credentials in Ctrip/Meituan platform configuration.',
            410
        );
    }

    public function getCookiesList(): Response
    {
        $this->checkPermission();
        return $this->success([]);
    }

    public function getCookiesDetail(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');
        return $this->error('Legacy Cookie detail access is disabled. Use credential metadata only.', 410);
    }

    public function deleteCookies(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_delete_online_data');
        return $this->error('Legacy Cookie deletion is disabled. Revoke the linked platform credential instead.', 410);
    }

    public function batchDeleteCookies(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_delete_online_data');
        return $this->error('Legacy Cookie batch deletion is disabled. Revoke linked platform credentials instead.', 410);
    }

}
