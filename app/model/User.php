<?php
declare(strict_types=1);

namespace app\model;

use app\service\HotelScopeService;
use app\service\PermissionService;
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
        if (!$role || (int)$role->status !== Role::STATUS_ENABLED || !$role->hasPermission('all')) {
            return false;
        }

        $roleId = (int)($role->getAttr('id') ?? 0);
        $roleName = (string)($role->getAttr('name') ?? '');
        $roleLevel = (int)($role->getAttr('level') ?? 0);

        if (in_array($roleId, [Role::BETA_USER, Role::NORMAL_USER], true)) {
            return false;
        }

        return $roleId === Role::SUPER_ADMIN || $roleName === 'admin' || $roleLevel === 1;
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
        return $role && (int)$role->status === Role::STATUS_ENABLED && (int)$role->level === 2;
    }

    /**
     * 是否是内测用户
     */
    public function isBetaUser(): bool
    {
        if ((int)$this->role_id === Role::BETA_USER) {
            return true;
        }

        $role = $this->role;
        return $role
            && (int)$role->status === Role::STATUS_ENABLED
            && in_array((string)$role->name, ['beta_user', 'hotel_manager'], true);
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
        return $role && (int)$role->status === Role::STATUS_ENABLED && (int)$role->level >= 3;
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
        $authorization = (new PermissionService())->authorize($this, $permission, $hotelId);
        return (bool)$authorization['allowed'];
    }

    private function legacyHasHotelPermission(int $hotelId, string $permission): bool
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
        if (!$role || (int)$role->status !== Role::STATUS_ENABLED || !$role->hasPermission($permission)) {
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
        if (!$role || (int)$role->status !== Role::STATUS_ENABLED || !$role->hasPermission($permission)) {
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
        return $this->isSuperAdmin();
    }

    /**
     * 是否可以管理自己创建的酒店
     */
    public function canManageOwnHotels(): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return (new PermissionService())->roleAllows($this, 'can_manage_own_hotels');
    }

    /**
     * 获取用户有权限的酒店ID列表
     * 注意：只返回启用状态的酒店
     */
    public function getPermittedHotelIds(): array
    {
        return (new HotelScopeService())->accessibleHotelIds($this);
    }

    public function getHotelScopeContext(): array
    {
        return (new HotelScopeService())->scopeContext($this);
    }

    private function legacyGetPermittedHotelIds(): array
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
