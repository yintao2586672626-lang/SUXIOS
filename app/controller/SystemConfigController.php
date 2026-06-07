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

        $configs = SystemConfig::getAllConfigs();
        $defaults = SystemConfig::getDefaultConfigs();

        $requestedKey = trim((string)$this->request->get('key', ''));
        if ($requestedKey !== '') {
            if (!$this->canReadConfigKey($requestedKey)) {
                abort(403, 'Forbidden');
            }
            return $this->success([
                $requestedKey => $configs[$requestedKey] ?? $defaults[$requestedKey] ?? null,
            ]);
        }

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

        $configs = SystemConfig::getAllConfigs();
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
