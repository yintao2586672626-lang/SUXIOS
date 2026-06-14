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
            'can_view_report' => ['report_view'],
            'can_fill_daily_report' => ['report_fill'],
            'can_fill_monthly_task' => ['report_fill'],
            'can_edit_report' => ['report_edit'],
            'can_delete_report' => ['report_delete'],
            'can_view_online_data' => ['hotel_view'],
        ];

        foreach ($aliases[$permission] ?? [] as $alias) {
            if (in_array($alias, $permissions, true)) {
                return true;
            }
        }

        return false;
    }
}
