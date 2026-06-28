<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class Role extends Model
{
    protected $name = 'roles';
    
    protected $autoWriteTimestamp = true;
    
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    
    protected $type = [
        'id' => 'integer',
        'status' => 'integer',
        'level' => 'integer',
    ];

    // 状态常量
    const STATUS_ENABLED = 1;
    const STATUS_DISABLED = 0;

    // 角色ID常量
    const ADMIN = 1;
    const BETA_USER = 2;
    const NORMAL_USER = 3;

    // Legacy aliases kept for older code paths.
    const SUPER_ADMIN = self::ADMIN;
    const HOTEL_MANAGER = self::BETA_USER;
    const HOTEL_STAFF = self::NORMAL_USER;

    /**
     * 关联用户
     */
    public function users()
    {
        return $this->hasMany(User::class, 'role_id', 'id');
    }

    /**
     * 获取权限列表
     */
    public function getPermissionList(): array
    {
        return self::normalizePermissions($this->permissions);
    }

    public static function normalizePermissions($permissions): array
    {
        if (empty($permissions)) {
            return [];
        }

        $decoded = is_string($permissions) ? json_decode($permissions, true) : $permissions;
        if (!is_array($decoded)) {
            return [];
        }

        $list = [];
        foreach ($decoded as $key => $value) {
            if (is_int($key)) {
                $list[] = (string)$value;
                continue;
            }

            if ($value) {
                $list[] = (string)$key;
            }
        }

        return array_values(array_unique($list));
    }

    /**
     * 是否拥有某个权限
     */
    public function hasPermission(string $permission): bool
    {
        return self::permissionListAllows($this->getPermissionList(), $permission);
    }

    public static function permissionListAllows(array $permissions, string $permission): bool
    {
        if (in_array('all', $permissions, true) || in_array($permission, $permissions, true)) {
            return true;
        }

        $aliases = [
            'dashboard.view' => ['dashboard_view'],
            'hotel.create' => ['can_manage_own_hotels', 'hotel_create'],
            'hotel.view' => ['can_view_online_data', 'hotel_view'],
            'hotel.update' => ['can_manage_own_hotels', 'hotel_update'],
            'hotel.delete' => ['hotel_delete'],
            'ota.view' => ['can_view_online_data', 'hotel_view', 'ota_read'],
            'ota.collect' => ['can_fetch_online_data', 'ota_collect'],
            'ota.delete' => ['can_delete_online_data', 'ota_delete'],
            'ota.export' => ['can_export_data', 'ota_export'],
            'report.view' => ['can_view_report', 'report_view'],
            'report.fill' => ['can_fill_daily_report', 'can_fill_monthly_task', 'report_fill'],
            'report.update' => ['can_edit_report', 'report_edit'],
            'report.delete' => ['can_delete_report', 'report_delete'],
            'report.export' => ['can_export_data', 'report_export'],
            'ai.view' => ['can_use_ai_decision', 'ai_view'],
            'ai.execute' => ['can_use_ai_decision', 'ai_execute'],
            'ai.governance' => ['can_manage_ai_governance'],
            'operation.view' => ['can_use_ai_decision', 'operation_view'],
            'operation.execute' => ['can_use_ai_decision', 'operation_execute'],
            'investment.view' => ['can_use_investment', 'investment_view'],
            'investment.simulate' => ['can_use_investment', 'investment_simulate'],
            'system.config' => ['system_config'],
            'audit.view' => ['audit_view'],
            'can_manage_own_hotels' => ['hotel.create'],
            'can_view_report' => ['report.view', 'report_view'],
            'can_fill_daily_report' => ['report.fill', 'report_fill'],
            'can_fill_monthly_task' => ['report.fill', 'report_fill'],
            'can_edit_report' => ['report.update', 'report_edit'],
            'can_delete_report' => ['report.delete', 'report_delete'],
            'can_view_online_data' => ['ota.view', 'hotel.view', 'hotel_view'],
            'can_fetch_online_data' => ['ota.collect'],
            'can_delete_online_data' => ['ota.delete'],
            'can_use_ai_decision' => ['ai.execute'],
            'can_use_investment' => ['investment.simulate'],
            'can_export_data' => ['report.export', 'ota.export'],
            'can_view_field_assets' => ['field_assets.view'],
            'can_view_diagnostics' => ['collection_health.view'],
            'can_manage_ai_governance' => ['ai.governance'],
        ];

        foreach ($aliases[$permission] ?? [] as $alias) {
            if (in_array($alias, $permissions, true)) {
                return true;
            }
        }

        return false;
    }
}
