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

        // 合并默认值
        foreach ($defaults as $key => $value) {
            if (!isset($configs[$key])) {
                $configs[$key] = $value;
            }
        }

        return $this->success($configs);
    }

    /**
     * 更新系统配置
     */
    public function update(): Response
    {
        $this->checkSuperAdmin();

        $data = $this->request->post();
        
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
    public function groups(): Response
    {
        $this->checkPermission();

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
        $exportData = [
            'export_time' => date('Y-m-d H:i:s'),
            'version' => 'v2.0.0',
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

        $group = $this->request->post('group', 'all');
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
