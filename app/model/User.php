<?php
declare(strict_types=1);

namespace app\model;

use think\Model;

class User extends Model
{
    protected $name = 'users';
    
    protected $autoWriteTimestamp = true;
    
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    
    protected $type = [
        'id' => 'integer',
        'status' => 'integer',
        'hotel_id' => 'integer',
        'role_id' => 'integer',
    ];

    // 隐藏字段
    protected $hidden = ['password'];

    // 状态常量
    const STATUS_ENABLED = 1;
    const STATUS_DISABLED = 0;

    /**
     * 关联角色
     */
    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id', 'id');
    }

    /**
     * 关联主酒店
     */
    public function hotel()
    {
        return $this->belongsTo(Hotel::class, 'hotel_id', 'id');
    }

    /**
     * 关联用户酒店权限
     */
    public function hotelPermissions()
    {
        return $this->hasMany(UserHotelPermission::class, 'user_id', 'id');
    }

    /**
     * 密码修改器
     */
    public function setPasswordAttr($value)
    {
        return password_hash($value, PASSWORD_DEFAULT);
    }

    /**
     * 验证密码
     */
    public function verifyPassword(string $password): bool
    {
        return password_verify($password, $this->password);
    }

    /**
     * 是否是超级管理员
     */
    public function isSuperAdmin(): bool
    {
        if ((int)$this->role_id === Role::SUPER_ADMIN) {
            return true;
        }

        $role = $this->role;
        return $role && $role->hasPermission('all');
    }

    /**
     * 是否是门店管理员
     */
    public function isHotelManager(): bool
    {
        if ((int)$this->role_id === Role::HOTEL_MANAGER) {
            return true;
        }

        $role = $this->role;
        return $role && (int)$role->level === 2;
    }

    /**
     * 是否是店员
     */
    public function isStaff(): bool
    {
        if ((int)$this->role_id === Role::HOTEL_STAFF) {
            return true;
        }

        $role = $this->role;
        return $role && (int)$role->level >= 3;
    }

    /**
     * 获取角色名称
     */
    public function getRoleName(): string
    {
        $role = $this->role;
        return $role ? $role->display_name : '未知';
    }

    /**
     * 检查是否有某个酒店的权限
     * 非超级管理员必须关联酒店才能有权限
     * 酒店必须处于启用状态
     */
    public function hasHotelPermission(int $hotelId, string $permission): bool
    {
        if ($hotelId <= 0) {
            return false;
        }

        if ($this->isSuperAdmin()) {
            // 超级管理员也需要检查酒店是否启用
            return $this->isHotelActive($hotelId);
        }
        
        // 非超级管理员必须关联酒店
        // 检查角色是否拥有该权限
        $role = $this->role;
        if (!$role || !$role->hasPermission($permission)) {
            return false;
        }
        
        // 检查是否是有权限的酒店（包含启用状态检查）
        if (!$this->isHotelActive($hotelId)) {
            return false;
        }

        $permissionRecord = $this->hotelPermissionRecord($hotelId);
        if ($permissionRecord !== null) {
            return $this->permissionRecordAllows($permissionRecord, $permission);
        }

        return !empty($this->hotel_id) && (int)$this->hotel_id === $hotelId;
    }
    
    /**
     * 检查是否有某个权限（从角色继承）
     * 注意：非超级管理员必须关联启用的酒店
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }
        
        // 非超级管理员必须关联酒店
        // 检查关联的酒店是否启用
        $role = $this->role;
        if (!$role || !$role->hasPermission($permission)) {
            return false;
        }

        foreach ($this->getPermittedHotelIds() as $hotelId) {
            if ($this->hasHotelPermission((int)$hotelId, $permission)) {
                return true;
            }
        }

        return false;
    }
    
    /**
     * 检查是否可以管理用户（店长及以上）
     */
    public function canManageUser(): bool
    {
        return $this->isSuperAdmin() || $this->isHotelManager();
    }

    /**
     * 获取用户有权限的酒店ID列表
     * 注意：只返回启用状态的酒店
     */
    public function getPermittedHotelIds(): array
    {
        if ($this->isSuperAdmin()) {
            // 超级管理员只返回启用的酒店
            return Hotel::where('status', Hotel::STATUS_ENABLED)->column('id');
        }

        // 非超级管理员只有自己关联的酒店
        $hotelIds = [];
        if (!empty($this->hotel_id)) {
            $hotelIds[] = (int)$this->hotel_id;
        }

        if (!empty($this->id)) {
            $permissionHotelIds = UserHotelPermission::where('user_id', (int)$this->id)->column('hotel_id');
            foreach ($permissionHotelIds as $permissionHotelId) {
                if ((int)$permissionHotelId > 0) {
                    $hotelIds[] = (int)$permissionHotelId;
                }
            }
        }

        $hotelIds = array_values(array_unique(array_filter($hotelIds, static fn(int $id): bool => $id > 0)));
        if (empty($hotelIds)) {
            return [];
        }
        
        // 检查关联的酒店是否启用
        return array_values(array_map('intval', Hotel::where('status', Hotel::STATUS_ENABLED)->whereIn('id', $hotelIds)->column('id')));
    }

    private function isHotelActive(int $hotelId): bool
    {
        $hotel = Hotel::find($hotelId);
        return $hotel && (int)$hotel->status === Hotel::STATUS_ENABLED;
    }

    private function hotelPermissionRecord(int $hotelId): ?array
    {
        if (empty($this->id)) {
            return null;
        }

        $record = UserHotelPermission::where('user_id', (int)$this->id)
            ->where('hotel_id', $hotelId)
            ->find();

        return $record ? $record->toArray() : null;
    }

    private function permissionRecordAllows(array $record, string $permission): bool
    {
        if (!array_key_exists($permission, $record)) {
            return false;
        }

        return (int)$record[$permission] === 1;
    }
}
