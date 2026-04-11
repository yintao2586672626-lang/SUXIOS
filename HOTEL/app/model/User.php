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
        return $this->role_id == 1;
    }

    /**
     * 是否是门店管理员
     */
    public function isHotelManager(): bool
    {
        return $this->role_id == 2;
    }

    /**
     * 是否是店员
     */
    public function isStaff(): bool
    {
        return $this->role_id == 3;
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
        if ($this->isSuperAdmin()) {
            // 超级管理员也需要检查酒店是否启用
            $hotel = Hotel::find($hotelId);
            return $hotel && $hotel->status == Hotel::STATUS_ENABLED;
        }
        
        // 非超级管理员必须关联酒店
        if (empty($this->hotel_id)) {
            return false;
        }
        
        // 检查角色是否拥有该权限
        $role = $this->role;
        if (!$role || !$role->hasPermission($permission)) {
            return false;
        }
        
        // 检查是否是有权限的酒店（包含启用状态检查）
        $permittedIds = $this->getPermittedHotelIds();
        return in_array($hotelId, $permittedIds);
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
        if (empty($this->hotel_id)) {
            return false;
        }
        
        // 检查关联的酒店是否启用
        $hotel = Hotel::find($this->hotel_id);
        if (!$hotel || $hotel->status != Hotel::STATUS_ENABLED) {
            return false;
        }
        
        $role = $this->role;
        return $role && $role->hasPermission($permission);
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
        if (empty($this->hotel_id)) {
            return [];
        }
        
        // 检查关联的酒店是否启用
        $hotel = Hotel::find($this->hotel_id);
        if (!$hotel || $hotel->status != Hotel::STATUS_ENABLED) {
            return [];
        }
        
        return [$this->hotel_id];
    }
}
