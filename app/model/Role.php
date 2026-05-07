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
    const SUPER_ADMIN = 1;
    const HOTEL_MANAGER = 2;
    const HOTEL_STAFF = 3;

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
        $permissions = $this->permissions;
        if (empty($permissions)) {
            return [];
        }
        return json_decode($permissions, true) ?: [];
    }

    /**
     * 是否拥有某个权限
     */
    public function hasPermission(string $permission): bool
    {
        $permissions = $this->getPermissionList();
        return in_array('all', $permissions) || in_array($permission, $permissions);
    }
}
