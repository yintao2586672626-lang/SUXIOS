<?php
declare(strict_types=1);

namespace app\service;

use app\model\Hotel;
use app\model\SystemConfig;
use app\model\User;

class ProtectedCapabilityService
{
    private const DEFAULT_REDACTION_REASON = 'protected_capability_summary_only';
    private const DEFAULT_ENABLED_MODULES = [
        'collection_health',
        'online_data',
        'field_assets',
    ];

    /** @var array<string, mixed> */
    private array $policy;

    /** @var callable(int): int|null */
    private $hotelTenantResolver;

    /** @var array<int, int> */
    private array $hotelTenantCache = [];

    /**
     * @param array<string, mixed>|null $policy
     * @param callable(int): int|null $hotelTenantResolver
     */
    public function __construct(?array $policy = null, ?callable $hotelTenantResolver = null)
    {
        $this->policy = $this->normalizePolicy($policy ?? $this->loadPolicy());
        $this->hotelTenantResolver = $hotelTenantResolver;
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultPolicy(): array
    {
        return [
            'version' => 'p0',
            'default_module_entitlement' => 'deny',
            'default_enabled_modules' => self::DEFAULT_ENABLED_MODULES,
            'enabled_modules' => [],
            'tenant_modules' => [],
            'redaction_reason' => self::DEFAULT_REDACTION_REASON,
            'capabilities' => [
                'ai_governance' => [
                    'label' => 'AI governance and model config',
                    'permission' => 'can_manage_ai_governance',
                    'module' => 'ai_governance',
                    'paths' => [
                        'api/ai-governance',
                        'api/ai-config',
                        'api/agent/config',
                        'api/agent/knowledge',
                        'api/agent/knowledge-categories',
                        'api/agent/logs',
                    ],
                    'response_mode' => 'summary_only',
                    'rate_limit' => ['scope' => 'protected_ai_governance', 'limit' => 20, 'window' => 3600],
                ],
                'field_assets' => [
                    'label' => 'OTA field assets and mapping',
                    'permission' => 'can_view_field_assets',
                    'module' => 'field_assets',
                    'paths' => [
                        'api/online-data/ctrip-profile-fields',
                        'api/online-data/ctrip-profile-modules',
                        'api/online-data/sync-ctrip-profile-fields',
                        'api/online-data/save-ctrip-profile-field',
                        'api/online-data/save-ctrip-profile-module',
                        'api/online-data/verify-ctrip-profile-field-sample',
                        'api/online-data/recheck-ctrip-profile-mismatched-fields',
                        'api/online-data/delete-ctrip-profile-field',
                        'api/online-data/delete-ctrip-profile-module',
                        'api/daily-reports/view-mapping',
                        'api/report-configs',
                    ],
                    'response_mode' => 'summary_only',
                    'rate_limit' => ['scope' => 'protected_field_assets', 'limit' => 30, 'window' => 3600],
                ],
                'collection_health' => [
                    'label' => 'OTA collection health and evidence',
                    'permission' => 'can_view_diagnostics',
                    'module' => 'collection_health',
                    'paths' => [
                        'api/online-data/collection-reliability',
                        'api/online-data/ctrip-diagnosis-snapshot',
                        'api/online-data/validate-ctrip-endpoint-evidence',
                        'api/online-data/ctrip-profile-status',
                        'api/online-data/meituan-profile-status',
                        'api/online-data/platform-profile-status',
                        'api/online-data/cookie-status',
                        'api/online-data/cookies-list',
                        'api/online-data/cookies-detail',
                        ['path' => 'api/online-data/history/*', 'methods' => ['GET']],
                        'api/online-data/sync-tasks',
                        'api/online-data/sync-logs',
                        'api/online-data/auto-fetch-status',
                        'api/online-data/auto-fetch-records',
                    ],
                    'response_mode' => 'summary_only',
                    'rate_limit' => ['scope' => 'protected_collection_health', 'limit' => 60, 'window' => 3600],
                ],
                'online_data_core' => [
                    'label' => 'OTA collection and analysis',
                    'permission' => 'can_fetch_online_data',
                    'module' => 'online_data',
                    'paths' => [
                        'api/online-data/data-analysis',
                        'api/online-data/ai-analysis',
                        'api/online-data/fetch-ctrip',
                        'api/online-data/fetch-ctrip-temporary-cookie',
                        'api/online-data/fetch-meituan',
                        'api/online-data/fetch-custom',
                        'api/online-data/fetch-ctrip-cookie-api',
                        'api/online-data/fetch-ctrip-overview',
                        'api/online-data/fetch-ctrip-traffic',
                        'api/online-data/fetch-ctrip-ads',
                        'api/online-data/fetch-ctrip-comments',
                        'api/online-data/fetch-meituan-traffic',
                        'api/online-data/fetch-meituan-order-flow',
                        'api/online-data/fetch-meituan-orders',
                        'api/online-data/fetch-meituan-ads',
                        'api/online-data/fetch-meituan-comments',
                        'api/online-data/capture-ctrip-browser',
                        'api/online-data/capture-ctrip-comments-browser',
                        'api/online-data/capture-meituan-browser',
                        ['path' => 'api/online-data/data-sources', 'methods' => ['POST']],
                        ['path' => 'api/online-data/data-sources/*', 'methods' => ['DELETE']],
                        ['path' => 'api/online-data/data-sources/*/sync', 'methods' => ['POST']],
                        ['path' => 'api/online-data/data-import', 'methods' => ['POST']],
                    ],
                    'response_mode' => 'summary_only',
                    'rate_limit' => ['scope' => 'protected_online_data', 'limit' => 60, 'window' => 3600],
                ],
                'ai_decision' => [
                    'label' => 'AI decision and revenue analysis',
                    'permission' => 'can_use_ai_decision',
                    'module' => 'ai_decision',
                    'paths' => [
                        'api/agent',
                        'api/ai',
                        'api/ai-daily-reports',
                        'api/ota-standard',
                        'api/revenue-research',
                    ],
                    'response_mode' => 'summary_only',
                    'rate_limit' => ['scope' => 'protected_ai_decision', 'limit' => 30, 'window' => 3600],
                ],
                'operation_decision' => [
                    'label' => 'Operation decision loop',
                    'permission' => 'can_use_ai_decision',
                    'module' => 'operation_decision',
                    'paths' => [
                        'api/operation',
                    ],
                    'response_mode' => 'summary_only',
                    'rate_limit' => ['scope' => 'protected_operation', 'limit' => 60, 'window' => 3600],
                ],
                'investment_decision' => [
                    'label' => 'Investment and simulation',
                    'permission' => 'can_use_investment',
                    'module' => 'investment',
                    'paths' => [
                        'api/transfer',
                        'api/strategy',
                        'api/simulation',
                        'api/expansion',
                        'api/opening',
                        'api/lifecycle',
                        'api/investment-decision',
                    ],
                    'response_mode' => 'summary_only',
                    'rate_limit' => ['scope' => 'protected_investment', 'limit' => 30, 'window' => 3600],
                ],
                'data_export' => [
                    'label' => 'Data export',
                    'permission' => 'can_export_data',
                    'module' => 'export',
                    'paths' => [
                        'api/daily-reports/export',
                        'api/system-config/export',
                    ],
                    'response_mode' => 'summary_only',
                    'rate_limit' => ['scope' => 'protected_export', 'limit' => 10, 'window' => 3600],
                ],
            ],
            'sensitive_keys' => [
                'ai_prompt',
                'api_endpoint',
                'api_url',
                'auth_data',
                'authorization',
                'capture_path',
                'collector_path',
                'cookie',
                'cookies',
                'curl',
                'diagnosis_rule',
                'diagnosis_rules',
                'diagnostic_rule',
                'diagnostic_rules',
                'evidence',
                'evidence_chain',
                'field_mapping',
                'field_mapping_draft',
                'field_path',
                'field_paths',
                'formula',
                'formulas',
                'headers',
                'mapping_draft',
                'mapping_path',
                'p3_evidence',
                'p3_evidence_drafts',
                'p3_evidence_matrix',
                'prompt',
                'raw_data',
                'raw_payload',
                'raw_request',
                'raw_response',
                'request_headers',
                'request_url',
                'response_body',
                'rule_detail',
                'source_path',
                'source_paths',
                'system_prompt',
                'user_prompt',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function policy(): array
    {
        return $this->policy;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function classifyPath(string $method, string $uri): ?array
    {
        $path = $this->normalizePath($uri);
        $method = strtoupper($method);

        foreach ($this->policy['capabilities'] as $key => $capability) {
            if (!is_array($capability)) {
                continue;
            }
            foreach (($capability['paths'] ?? []) as $rule) {
                $pathRule = $this->normalizePathRule($rule);
                if ($pathRule === null) {
                    continue;
                }
                if ($pathRule['methods'] !== [] && !in_array($method, $pathRule['methods'], true)) {
                    continue;
                }
                if ($this->pathMatches($path, $pathRule['path'])) {
                    $capability['key'] = (string)$key;
                    $capability['path'] = $path;
                    $capability['method'] = $method;
                    return $capability;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $capability
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function authorizeContext(User $user, array $capability, array $params = []): array
    {
        if ($user->isSuperAdmin()) {
            return ['allowed' => true, 'reason' => 'super_admin', 'status' => 200];
        }

        $permission = trim((string)($capability['permission'] ?? ''));
        $module = trim((string)($capability['module'] ?? ''));
        $hotelId = $this->resolveHotelId($params, $user);
        $tenantId = $this->resolveTenantId($params, $user);

        $claimedTenantId = $this->positiveInt($params['tenant_id'] ?? null);
        if ($claimedTenantId > 0 && $tenantId > 0 && $claimedTenantId !== $tenantId) {
            return [
                'allowed' => false,
                'reason' => 'tenant_context_mismatch',
                'status' => 403,
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'required_permission' => $permission,
                'required_module' => $module,
            ];
        }

        if (!$this->hotelScopeAllows($user, $hotelId)) {
            return [
                'allowed' => false,
                'reason' => 'hotel_permission_denied',
                'status' => 403,
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'required_permission' => $permission,
                'required_module' => $module,
            ];
        }

        if ($permission !== '' && !$this->roleAllows($user, $permission)) {
            return [
                'allowed' => false,
                'reason' => 'role_permission_denied',
                'status' => 403,
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'required_permission' => $permission,
                'required_module' => $module,
            ];
        }

        if ($permission !== '' && !$this->hotelCapabilityAllows($user, $hotelId, $permission)) {
            return [
                'allowed' => false,
                'reason' => 'hotel_permission_denied',
                'status' => 403,
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'required_permission' => $permission,
                'required_module' => $module,
            ];
        }

        if (!$this->moduleEntitled($tenantId, $module)) {
            return [
                'allowed' => false,
                'reason' => 'module_not_entitled',
                'status' => 403,
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'required_permission' => $permission,
                'required_module' => $module,
            ];
        }

        return [
            'allowed' => true,
            'reason' => 'authorized',
            'status' => 200,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'required_permission' => $permission,
            'required_module' => $module,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $capability
     * @return array<string, mixed>
     */
    public function redactPayload(array $payload, array $capability, string $requestId): array
    {
        $removedCount = 0;
        if (array_key_exists('data', $payload)) {
            $payload['data'] = $this->redactValue($payload['data'], $removedCount);
        } else {
            $payload = $this->redactValue($payload, $removedCount);
            if (!is_array($payload)) {
                $payload = ['result' => $payload];
            }
        }

        $payload['redacted'] = true;
        $payload['redacted_reason'] = (string)($this->policy['redaction_reason'] ?? self::DEFAULT_REDACTION_REASON);
        $payload['reference_id'] = $requestId;
        $payload['protected_capability'] = (string)($capability['key'] ?? '');
        $payload['redacted_key_count'] = $removedCount;

        return $payload;
    }

    /**
     * @param array<string, mixed> $capability
     */
    public function shouldRedactForUser(User $user, ?array $capability): bool
    {
        if ($capability === null || $user->isSuperAdmin()) {
            return false;
        }

        return (string)($capability['response_mode'] ?? 'summary_only') === 'summary_only';
    }

    /**
     * @param array<string, mixed> $params
     */
    public function resolveTenantId(array $params, User $user): int
    {
        $hotelId = $this->resolveHotelId($params, $user);
        if ($hotelId > 0) {
            return $this->tenantIdForHotel($hotelId);
        }

        return $this->positiveInt($user->tenant_id ?? null);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function resolveHotelId(array $params, User $user): int
    {
        foreach (['system_hotel_id', 'hotel_id'] as $key) {
            if (isset($params[$key]) && is_numeric($params[$key]) && (int)$params[$key] > 0) {
                return (int)$params[$key];
            }
        }

        $primaryHotelId = $this->positiveInt($user->hotel_id ?? null);
        if ($primaryHotelId > 0) {
            return $primaryHotelId;
        }

        try {
            $permitted = array_values(array_unique(array_filter(
                array_map('intval', $user->getPermittedHotelIds()),
                static fn(int $id): bool => $id > 0
            )));
            return count($permitted) === 1 ? $permitted[0] : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public function normalizePath(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path)) {
            $path = $uri;
        }

        return trim(strtolower($path), '/');
    }

    /**
     * @return array<string, mixed>
     */
    private function loadPolicy(): array
    {
        try {
            $raw = SystemConfig::getValue(SystemConfig::KEY_PROTECTED_CAPABILITY_POLICY, '');
        } catch (\Throwable $e) {
            return self::defaultPolicy();
        }

        if (!is_string($raw) || trim($raw) === '') {
            return self::defaultPolicy();
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return self::defaultPolicy();
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $policy
     * @return array<string, mixed>
     */
    private function normalizePolicy(array $policy): array
    {
        $default = self::defaultPolicy();
        $merged = array_replace_recursive($default, $policy);

        if (!isset($merged['capabilities']) || !is_array($merged['capabilities'])) {
            $merged['capabilities'] = $default['capabilities'];
        }
        if (!isset($merged['sensitive_keys']) || !is_array($merged['sensitive_keys'])) {
            $merged['sensitive_keys'] = $default['sensitive_keys'];
        }

        return $merged;
    }

    private function pathMatches(string $path, string $pattern): bool
    {
        $pattern = trim(strtolower($pattern), '/');
        if ($pattern === '') {
            return false;
        }

        if (str_ends_with($pattern, '/*')) {
            $prefix = rtrim(substr($pattern, 0, -2), '/');
            return str_starts_with($path, $prefix . '/');
        }

        if (str_contains($pattern, '*')) {
            $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#';
            return (bool)preg_match($regex, $path);
        }

        return $path === $pattern || str_starts_with($path, $pattern . '/');
    }

    /**
     * @param mixed $rule
     * @return array{path: string, methods: array<int, string>}|null
     */
    private function normalizePathRule($rule): ?array
    {
        if (is_string($rule)) {
            return ['path' => trim($rule), 'methods' => []];
        }

        if (!is_array($rule)) {
            return null;
        }

        $path = trim((string)($rule['path'] ?? ''));
        if ($path === '') {
            return null;
        }

        $methods = [];
        foreach (($rule['methods'] ?? []) as $method) {
            $method = strtoupper(trim((string)$method));
            if ($method !== '') {
                $methods[] = $method;
            }
        }

        return ['path' => $path, 'methods' => array_values(array_unique($methods))];
    }

    private function hotelScopeAllows(User $user, int $hotelId): bool
    {
        if ($hotelId <= 0) {
            return false;
        }

        $permitted = array_map('intval', $user->getPermittedHotelIds());
        return in_array($hotelId, $permitted, true);
    }

    private function hotelCapabilityAllows(User $user, int $hotelId, string $permission): bool
    {
        try {
            return $user->hasHotelPermission($hotelId, $permission);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function tenantIdForHotel(int $hotelId): int
    {
        if ($hotelId <= 0) {
            return 0;
        }
        if (array_key_exists($hotelId, $this->hotelTenantCache)) {
            return $this->hotelTenantCache[$hotelId];
        }

        $tenantId = 0;
        try {
            if ($this->hotelTenantResolver !== null) {
                $tenantId = $this->positiveInt(($this->hotelTenantResolver)($hotelId));
            } else {
                $tenantId = $this->positiveInt(Hotel::where('id', $hotelId)->value('tenant_id'));
            }
        } catch (\Throwable $e) {
            $tenantId = 0;
        }

        // Legacy installations used hotel_id as the tenant key. The fallback
        // remains server-derived and never trusts a request tenant_id.
        $this->hotelTenantCache[$hotelId] = $tenantId > 0 ? $tenantId : $hotelId;
        return $this->hotelTenantCache[$hotelId];
    }

    private function positiveInt($value): int
    {
        return is_numeric($value) && (int)$value > 0 ? (int)$value : 0;
    }

    private function roleAllows(User $user, string $permission): bool
    {
        $role = $user->role ?? null;
        if (is_object($role) && method_exists($role, 'hasPermission')) {
            return $role->hasPermission($permission);
        }

        return false;
    }

    private function moduleEntitled(int $tenantId, string $module): bool
    {
        if ($module === '') {
            return true;
        }

        $tenantKey = (string)$tenantId;
        $sources = [
            $this->policy['tenant_modules'][$tenantKey] ?? null,
            $this->policy['tenants'][$tenantKey]['enabled_modules'] ?? null,
            $this->policy['module_entitlements'][$tenantKey] ?? null,
            $this->policy['tenant_modules']['*'] ?? null,
            $this->policy['enabled_modules'] ?? null,
            $this->policy['default_enabled_modules'] ?? null,
        ];

        foreach ($sources as $source) {
            if (is_array($source) && $this->moduleListAllows($source, $module)) {
                return true;
            }
        }

        return in_array(strtolower((string)($this->policy['default_module_entitlement'] ?? 'deny')), ['allow', 'open'], true);
    }

    /**
     * @param array<mixed> $modules
     */
    private function moduleListAllows(array $modules, string $module): bool
    {
        if (isset($modules['*']) && $modules['*']) {
            return true;
        }
        if (isset($modules[$module]) && $modules[$module]) {
            return true;
        }

        foreach ($modules as $value) {
            if ((string)$value === '*' || (string)$value === $module) {
                return true;
            }
        }

        return false;
    }

    private function redactValue($value, int &$removedCount)
    {
        if (!is_array($value)) {
            return $value;
        }

        $redacted = [];
        foreach ($value as $key => $child) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $removedCount++;
                continue;
            }

            $redacted[$key] = $this->redactValue($child, $removedCount);
        }

        return $redacted;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower((string)preg_replace('/[^a-z0-9]+/i', '_', $key));
        $normalized = trim($normalized, '_');
        $sensitiveKeys = array_map(
            static fn($value): string => trim(strtolower((string)$value), '_'),
            $this->policy['sensitive_keys']
        );

        if (in_array($normalized, $sensitiveKeys, true)) {
            return true;
        }

        foreach (['prompt', 'formula', 'raw_', 'source_path', 'request_url', 'headers', 'cookie', 'p3_evidence', 'field_mapping', 'mapping_draft', 'diagnosis_rule', 'diagnostic_rule', 'evidence_chain'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }
}
