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
        'tenant_id' => 'integer',
        'status' => 'integer',
        'hotel_id' => 'integer',
        'role_id' => 'integer',
    ];

    // 隐藏字段
    protected $hidden = ['password'];

    /**
     * Authorization helpers are reused only by this model instance. This keeps
     * login payload construction from rebuilding schema and hotel-scope state
     * for every permission without sharing tenant state across users.
     */
    private ?HotelScopeService $hotelScopeServiceInstance = null;
    private ?PermissionService $permissionServiceInstance = null;

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
     * Bind every login token to the current password hash without exposing it.
     * A password change produces a new version and invalidates all older tokens.
     */
    public function authSessionVersion(): string
    {
        return hash('sha256', (string)$this->getAttr('password'));
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
        $role = $this->enabledRole();
        if (!$role) {
            return false;
        }

        $roleId = (int)($role->getAttr('id') ?? $this->role_id ?? 0);
        return $roleId === Role::HOTEL_MANAGER || (int)$role->getAttr('level') === Role::HOTEL_MANAGER;
    }

    /**
     * 是否是内测用户
     */
    public function isBetaUser(): bool
    {
        $role = $this->enabledRole();
        if (!$role) {
            return false;
        }

        $roleId = (int)($role->getAttr('id') ?? $this->role_id ?? 0);
        $roleName = (string)($role->getAttr('name') ?? '');
        $roleLevel = (int)$role->getAttr('level');

        return $roleId === Role::BETA_USER
            || in_array($roleName, ['beta_user', 'hotel_manager'], true)
            || $roleLevel === Role::HOTEL_MANAGER;
    }

    /**
     * 是否是店员
     */
    public function isStaff(): bool
    {
        $role = $this->enabledRole();
        if (!$role) {
            return false;
        }

        $roleId = (int)($role->getAttr('id') ?? $this->role_id ?? 0);
        return $roleId === Role::HOTEL_STAFF || (int)$role->getAttr('level') >= Role::HOTEL_STAFF;
    }

    private function enabledRole(): ?Role
    {
        $role = $this->role;
        if (!$role instanceof Role || (int)$role->status !== Role::STATUS_ENABLED) {
            return null;
        }

        return $role;
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
        $authorization = $this->permissionService()->authorize($this, $permission, $hotelId);
        return (bool)$authorization['allowed'];
    }

    public function hasHotelPermissionOrFail(
        int $hotelId,
        string $permission,
        string $message = '无权限操作该门店'
    ): void {
        if (!$this->hasHotelPermission($hotelId, $permission)) {
            throw new \think\exception\HttpException(403, $message);
        }
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

        return $this->permissionService()->roleAllows($this, 'can_manage_own_hotels');
    }

    /**
     * 获取用户有权限的酒店ID列表
     * 注意：只返回启用状态的酒店
     */
    public function getPermittedHotelIds(): array
    {
        return $this->hotelScopeService()->accessibleHotelIds($this);
    }

    public function getHotelScopeContext(): array
    {
        return $this->hotelScopeService()->scopeContext($this);
    }

    public function resetAuthorizationContext(): void
    {
        if ($this->hotelScopeServiceInstance !== null) {
            $this->hotelScopeServiceInstance->invalidateUser($this);
        }
        $this->hotelScopeServiceInstance = null;
        $this->permissionServiceInstance = null;
    }

    private function hotelScopeService(): HotelScopeService
    {
        return $this->hotelScopeServiceInstance ??= new HotelScopeService();
    }

    private function permissionService(): PermissionService
    {
        return $this->permissionServiceInstance ??= new PermissionService($this->hotelScopeService());
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

}
