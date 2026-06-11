<?php
declare(strict_types=1);

namespace app\controller;

use app\model\SystemConfig;
use app\model\OperationLog;
use think\Response;

class SystemConfigController extends Base
{
    /**
     * 获取所有系统配置
     */
    public function index(): Response
    {
        $this->checkPermission();

        $requestedKey = trim((string)$this->request->get('key', ''));
        if ($requestedKey !== '') {
            if (!$this->canReadConfigKey($requestedKey)) {
                abort(403, 'Forbidden');
            }
            $defaults = SystemConfig::getDefaultConfigs();
            return $this->success([
                $requestedKey => SystemConfig::getValue($requestedKey, $defaults[$requestedKey] ?? null),
            ]);
        }

        $defaults = SystemConfig::getDefaultConfigs();
        $publicOnly = strtolower(trim((string)$this->request->get('scope', ''))) === 'public'
            || (string)$this->request->get('public', '') === '1';
        if ($publicOnly || !$this->currentUser->isSuperAdmin()) {
            $publicKeys = $this->publicConfigKeys();
            $configs = SystemConfig::getConfigsByKeys($publicKeys);
            foreach ($publicKeys as $key) {
                if (!isset($configs[$key]) && array_key_exists($key, $defaults)) {
                    $configs[$key] = $defaults[$key];
                }
            }

            return $this->success($configs);
        }

        $configs = SystemConfig::getAllConfigs();

        // 合并默认值
        foreach ($defaults as $key => $value) {
            if (!isset($configs[$key])) {
                $configs[$key] = $value;
            }
        }

        if (!$this->currentUser->isSuperAdmin()) {
            $configs = $this->filterPublicConfigs($configs);
        }

        return $this->success($configs);
    }

    /**
     * 更新系统配置
     */
    public function update(): Response
    {
        $this->checkSuperAdmin();

        $data = $this->requestData();

        // 支持自定义配置项（如数据配置）
        if (isset($data['config_key']) && isset($data['config_value'])) {
            $key = $data['config_key'];
            $value = $data['config_value'];
            $description = $data['description'] ?? '自定义配置';

            SystemConfig::setValue($key, $value, $description);

            OperationLog::record('system_config', 'update', '更新配置: ' . $description, $this->currentUser->id);

            return $this->success(null, '配置更新成功');
        }

        // 获取所有配置项描述
        $descriptions = SystemConfig::getConfigDescriptions();

        // 遍历并保存所有提交的配置
        foreach ($data as $key => $value) {
            if (isset($descriptions[$key])) {
                SystemConfig::setValue($key, $value, $descriptions[$key]);
            }
        }

        OperationLog::record('system_config', 'update', '更新系统配置', $this->currentUser->id);

        return $this->success(SystemConfig::getAllConfigs(), '配置更新成功');
    }

    /**
     * 检查权限
     */
    private function checkPermission(): void
    {
        if (!$this->currentUser) {
            abort(401, '未登录');
        }
    }

    /**
     * 获取配置分组信息
     */
    private function canReadConfigKey(string $key): bool
    {
        return $this->currentUser->isSuperAdmin() || in_array($key, $this->publicConfigKeys(), true);
    }

    private function filterPublicConfigs(array $configs): array
    {
        return array_intersect_key($configs, array_fill_keys($this->publicConfigKeys(), true));
    }

    private function publicConfigKeys(): array
    {
        return [
            SystemConfig::KEY_SYSTEM_NAME,
            SystemConfig::KEY_LOGO_URL,
            SystemConfig::KEY_FAVICON_URL,
            SystemConfig::KEY_SYSTEM_DESCRIPTION,
            SystemConfig::KEY_SYSTEM_KEYWORDS,
            SystemConfig::KEY_MENU_HOTEL,
            SystemConfig::KEY_MENU_USERS,
            SystemConfig::KEY_MENU_COMPASS,
            SystemConfig::KEY_MENU_ONLINE_DATA,
            SystemConfig::KEY_THEME,
            SystemConfig::KEY_PRIMARY_COLOR,
            SystemConfig::KEY_DATE_FORMAT,
            SystemConfig::KEY_TIME_FORMAT,
            SystemConfig::KEY_PAGE_SIZE_OPTIONS,
            SystemConfig::KEY_DEFAULT_PAGE_SIZE,
            SystemConfig::KEY_ENABLE_REGISTRATION,
            SystemConfig::KEY_ENABLE_LOGIN_LOG,
            SystemConfig::KEY_ENABLE_OPERATION_LOG,
            SystemConfig::KEY_ENABLE_DATA_BACKUP,
            SystemConfig::KEY_ENABLE_WECHAT_MINI,
            SystemConfig::KEY_ENABLE_ONLINE_DATA,
            SystemConfig::KEY_COMPLAINT_MINI_PAGE,
            SystemConfig::KEY_COMPLAINT_MINI_USE_SCENE,
        ];
    }

    public function groups(): Response
    {
        $this->checkSuperAdmin();

        return $this->success([
            'groups' => SystemConfig::getConfigGroups(),
            'descriptions' => SystemConfig::getConfigDescriptions(),
        ]);
    }

    /**
     * 导出系统配置
     */
    public function export(): Response
    {
        $this->checkSuperAdmin();

        $redactionStats = ['redacted_count' => 0];
        $configs = $this->redactExportConfigs(SystemConfig::getAllConfigs(), $redactionStats);
        $generatedAt = date('Y-m-d H:i:s');
        $requestId = trim((string)($this->request->request_id ?? $this->request->header('X-Request-ID', '')));
        if ($requestId === '') {
            $requestId = 'missing_request_id';
        }
        $tenantId = (int)($this->currentUser->tenant_id ?? $this->currentUser->hotel_id ?? 0);
        $exportData = [
            'export_time' => $generatedAt,
            'version' => 'v2.0.0',
            'trace' => [
                'tenant_id' => $tenantId > 0 ? $tenantId : null,
                'user_id' => (int)($this->currentUser->id ?? 0),
                'hotel_id' => !empty($this->currentUser->hotel_id) ? (int)$this->currentUser->hotel_id : null,
                'request_id' => $requestId,
                'generated_at' => $generatedAt,
                'redaction' => [
                    'status' => $redactionStats['redacted_count'] > 0 ? 'redacted' : 'none',
                    'redacted_count' => $redactionStats['redacted_count'],
                    'rule' => 'sensitive_config_value_masked',
                ],
            ],
            'configs' => $configs,
        ];

        $filename = 'system_config_' . date('YmdHis') . '.json';

        return json($exportData)->header([
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * @param array<string, mixed> $configs
     * @param array<string, int> $stats
     * @return array<string, mixed>
     */
    private function redactExportConfigs(array $configs, array &$stats): array
    {
        $redacted = [];
        foreach ($configs as $key => $value) {
            $redacted[$key] = $this->redactExportConfigValue((string)$key, $value, $stats);
        }

        return $redacted;
    }

    /**
     * @param mixed $value
     * @param array<string, int> $stats
     * @return mixed
     */
    private function redactExportConfigValue(string $key, $value, array &$stats)
    {
        if ($this->isSensitiveExportConfigKey($key)) {
            $stats['redacted_count']++;
            return $this->maskedExportSecret($value);
        }

        if (is_string($value) && $this->looksLikeJsonObject($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $nested = $this->redactExportConfigArray($decoded, $stats);
                return json_encode($nested, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        if (is_array($value)) {
            return $this->redactExportConfigArray($value, $stats);
        }

        return $value;
    }

    /**
     * @param array<mixed> $value
     * @param array<string, int> $stats
     * @return array<mixed>
     */
    private function redactExportConfigArray(array $value, array &$stats): array
    {
        $redacted = [];
        foreach ($value as $key => $child) {
            $keyText = is_string($key) ? $key : (string)$key;
            $redacted[$key] = $this->redactExportConfigValue($keyText, $child, $stats);
        }

        return $redacted;
    }

    private function isSensitiveExportConfigKey(string $key): bool
    {
        $normalized = strtolower((string)preg_replace('/[^a-z0-9]+/i', '_', $key));
        $normalized = trim($normalized, '_');
        if ($normalized === '') {
            return false;
        }

        foreach ([
            'api_key',
            'apikey',
            'app_secret',
            'authorization',
            'auth_data',
            'cookie',
            'cookies',
            'headers',
            'llm_api_key',
            'mtgsig',
            'password',
            'protected_capability_policy',
            'secret',
            'spidertoken',
            'token',
            'wechat_mini_secret',
            'notify_email_pass',
        ] as $needle) {
            if ($normalized === $needle || str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function maskedExportSecret($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return '[REDACTED]';
    }

    private function looksLikeJsonObject(string $value): bool
    {
        $trimmed = trim($value);
        return $trimmed !== '' && (
            (str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}'))
            || (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']'))
        );
    }

    /**
     * 导入系统配置
     */
    public function import(): Response
    {
        $this->checkSuperAdmin();

        $file = $this->request->file('config_file');
        if (!$file) {
            return $this->error('请上传配置文件');
        }

        $content = file_get_contents($file->getPathname());
        $data = json_decode($content, true);

        if (!$data || !isset($data['configs'])) {
            return $this->error('配置文件格式错误');
        }

        $descriptions = SystemConfig::getConfigDescriptions();
        $imported = 0;

        foreach ($data['configs'] as $key => $value) {
            if (isset($descriptions[$key])) {
                SystemConfig::setValue($key, $value, $descriptions[$key]);
                $imported++;
            }
        }

        OperationLog::record('system_config', 'import', '导入系统配置，共' . $imported . '项', $this->currentUser->id);

        return $this->success(['imported' => $imported], '配置导入成功');
    }

    /**
     * 重置系统配置为默认值
     */
    public function reset(): Response
    {
        $this->checkSuperAdmin();

        $data = $this->requestData();
        $group = $data['group'] ?? 'all';
        $defaults = SystemConfig::getDefaultConfigs();
        $descriptions = SystemConfig::getConfigDescriptions();
        $groups = SystemConfig::getConfigGroups();

        $resetCount = 0;

        if ($group === 'all') {
            // 重置所有配置
            foreach ($defaults as $key => $value) {
                SystemConfig::setValue($key, $value, $descriptions[$key] ?? '');
                $resetCount++;
            }
        } elseif (isset($groups[$group])) {
            // 重置指定分组
            foreach ($groups[$group]['keys'] as $key) {
                if (isset($defaults[$key])) {
                    SystemConfig::setValue($key, $defaults[$key], $descriptions[$key] ?? '');
                    $resetCount++;
                }
            }
        }

        OperationLog::record('system_config', 'reset', '重置系统配置: ' . $group, $this->currentUser->id);

        return $this->success(['reset_count' => $resetCount], '配置重置成功');
    }

    /**
     * 检查超级管理员权限
     */
    private function checkSuperAdmin(): void
    {
        if (!$this->currentUser) {
            abort(401, '未登录');
        }

        if (!$this->currentUser->isSuperAdmin()) {
            abort(403, '无权限操作');
        }
    }
}
