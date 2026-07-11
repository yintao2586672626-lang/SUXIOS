<?php
declare(strict_types=1);

namespace app\controller;

use app\model\User as UserModel;
use app\model\Hotel as HotelModel;
use app\model\Role;
use app\model\OperationLog;
use app\service\PermissionService;
use think\Response;
use think\facade\Db;

class User extends Base
{
    private array $tableColumnCache = [];
    private array $tenantColumnCache = [];

    /**
     * 用户列表
     */
    public function index(): Response
    {
        if (!$this->currentUser->canManageUser()) {
            return $this->error('权限不足', 403);
        }

        $pagination = $this->getPagination();
        $username = $this->request->param('username', '');
        $roleId = $this->request->param('role_id', '');
        $status = $this->request->param('status', '');
        $hotelId = $this->request->param('hotel_id', '');

        $query = UserModel::with(['role', 'hotel'])->order('id', 'desc');

        if ($username) {
            $query->whereLike('username', '%' . $username . '%');
        }
        if ($roleId) {
            $query->where('role_id', $roleId);
        }
        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($hotelId) {
            $query->where('hotel_id', $hotelId);
        }

        // 非超级管理员只能看到自己酒店的用户
        if (!$this->currentUser->isSuperAdmin()) {
            $permittedHotelIds = array_values(array_map('intval', $this->currentUser->getPermittedHotelIds()));
            if (empty($permittedHotelIds)) {
                return $this->paginate([], 0, $pagination['page'], $pagination['page_size']);
            }
            $query->whereIn('hotel_id', $permittedHotelIds);
        }

        $total = $query->count();
        $list = $query->page($pagination['page'], $pagination['page_size'])->select()->hidden(['password']);
        $rows = [];
        foreach ($list as $item) {
            $rows[] = $this->appendUserHotelScope($item);
        }

        return $this->paginate($rows, $total, $pagination['page'], $pagination['page_size']);
    }

    /**
     * 用户详情
     */
    public function read(int $id): Response
    {
        if (!$this->currentUser->canManageUser()) {
            return $this->error('权限不足', 403);
        }

        $user = UserModel::with(['role', 'hotel'])->find($id);
        if (!$user) {
            return $this->error('用户不存在');
        }

        if (!$this->currentUser->isSuperAdmin()) {
            $currentUserHotelIds = array_map('intval', $this->currentUser->getPermittedHotelIds());
            $targetUserHotelIds = $this->userEffectiveHotelIds((int)$user->id, (int)($user->hotel_id ?? 0));
            if (empty(array_intersect($targetUserHotelIds, $currentUserHotelIds))) {
                return $this->error('权限不足', 403);
            }
        }

        $user->hidden(['password']);
        return $this->success($this->appendUserHotelScope($user));
    }

    /**
     * 创建用户
     * 超级管理员可以创建任意用户
     * 店长只能创建自己酒店的店员账号
     */
    public function create(): Response
    {
        // 店长及以上可以创建用户
        if (!$this->currentUser->canManageUser()) {
            return $this->error('权限不足');
        }

        $data = $this->requestData();

        $username = trim((string)($data['username'] ?? ''));
        $usernameError = $this->validateUsernamePolicy($username);
        if ($usernameError) {
            return $this->error($usernameError);
        }
        $data['username'] = $username;

        $this->validate($data, [
            'password' => 'require',
            'role_id' => 'require|integer',
        ], [
            'password.require' => '密码不能为空',
            'role_id.require' => '请选择角色',
        ]);

        $passwordError = $this->validatePasswordPolicy((string)$data['password'], '密码');
        if ($passwordError) {
            return $this->error($passwordError);
        }

        // 检查用户名唯一性
        $exists = UserModel::where('username', $data['username'])->find();
        if ($exists) {
            return $this->error('用户名已存在');
        }
        
        // 非超级管理员只能创建自己酒店的店员
        $hotelId = null;
        $roleId = (int)$data['role_id'];
        $targetRole = Role::where('id', $roleId)->where('status', Role::STATUS_ENABLED)->find();
        if (!$targetRole) {
            return $this->error('角色不存在或已停用', 422);
        }
        $hotelIds = [];
        
        if ($this->currentUser->isSuperAdmin()) {
            $hotelIds = $this->normalizeAssignedHotelIds($data);
            $invalidHotelResponse = $this->validateAssignableHotelIds($hotelIds);
            if ($invalidHotelResponse) {
                return $invalidHotelResponse;
            }
            $issueBoundaryResponse = $this->validateExternalUserIssueBoundary($targetRole, $hotelIds);
            if ($issueBoundaryResponse) {
                return $issueBoundaryResponse;
            }
            $hotelId = $hotelIds[0] ?? null;
        } else {
            // 店长只能创建自己酒店的店员
            $permittedHotelIds = array_values(array_map('intval', $this->currentUser->getPermittedHotelIds()));
            $hotelId = count($permittedHotelIds) === 1 ? $permittedHotelIds[0] : (int)($data['hotel_id'] ?? 0);
            if (empty($hotelId)) {
                return $this->error('您未关联酒店，无法创建用户');
            }
            // 店长只能创建店员角色
            if (!in_array((int)$hotelId, $permittedHotelIds, true)) {
                return $this->error('无权为该酒店创建用户', 403);
            }
            if (!$targetRole || (int)$targetRole->level < 3) {
                return $this->error('您只能创建店员账号');
            }
            $hotelIds = [(int)$hotelId];
            $issueBoundaryResponse = $this->validateExternalUserIssueBoundary($targetRole, $hotelIds);
            if ($issueBoundaryResponse) {
                return $issueBoundaryResponse;
            }
        }

        $tenantContext = $this->resolveHotelTenantContext($hotelIds);
        if (!empty($tenantContext['invalid_hotel_ids'])) {
            return $this->error('酒店租户归属无效: ' . implode(',', $tenantContext['invalid_hotel_ids']), 422);
        }
        $hotelTenantIds = $tenantContext['tenant_ids'];

        $user = new UserModel();
        $user->username = $data['username'];
        $user->password = $data['password'];
        $user->realname = $data['realname'] ?? '';
        $user->email = $data['email'] ?? '';
        $user->phone = $data['phone'] ?? '';
        $user->role_id = $roleId;
        $user->hotel_id = $hotelId;
        if ($this->strictTenantColumnExists('users')) {
            $user->tenant_id = $hotelId !== null ? ($hotelTenantIds[(int)$hotelId] ?? null) : null;
        }
        $user->status = $data['status'] ?? UserModel::STATUS_ENABLED;
        Db::transaction(function () use ($user, $hotelIds, $targetRole, $hotelTenantIds): void {
            $user->save();
            $this->syncUserHotelPermissions($user, $hotelIds, $targetRole, $hotelTenantIds);
        });

        OperationLog::record('user', 'create', '创建用户: ' . $user->username, $this->currentUser->id);

        $savedUser = UserModel::with(['role', 'hotel'])->find((int)$user->id);
        return $this->success($this->appendUserHotelScope($savedUser ?: $user), '创建成功');
    }

    /**
     * 更新用户
     * 超级管理员可以修改任意用户
     * 店长只能修改自己酒店的店员
     */
    public function update(int $id): Response
    {
        $user = UserModel::find($id);
        if (!$user) {
            return $this->error('用户不存在');
        }

        // 权限检查
        if ($this->currentUser->isSuperAdmin()) {
            // 超级管理员可以修改任意用户
        } elseif ($this->currentUser->id == $id) {
            // 用户修改自己
        } else {
            return $this->error('权限不足');
        }

        $data = $this->requestData();

        // 用户名唯一性检查
        if (array_key_exists('username', $data)) {
            $nextUsername = trim((string)$data['username']);
            if ($nextUsername !== (string)$user->username) {
                if (!$this->canEditUserUsername($user)) {
                    return $this->error('只有超级管理员可以修改已有内测用户的用户名', 403);
                }
                $usernameError = $this->validateUsernamePolicy($nextUsername);
                if ($usernameError) {
                    return $this->error($usernameError);
                }
                $exists = UserModel::where('username', $nextUsername)->find();
                if ($exists) {
                    return $this->error('用户名已存在');
                }
                $user->username = $nextUsername;
            }
        }

        if (!empty($data['password'])) {
            $passwordError = $this->validatePasswordPolicy((string)$data['password'], '密码');
            if ($passwordError) {
                return $this->error($passwordError);
            }
            $user->password = $data['password'];
        }

        $user->realname = $data['realname'] ?? $user->realname;
        $user->email = $data['email'] ?? $user->email;
        $user->phone = $data['phone'] ?? $user->phone;
        $roleChanged = false;
        $syncHotelIds = null;
        $targetRole = Role::where('id', (int)$user->role_id)->where('status', Role::STATUS_ENABLED)->find();

        // 只有超级管理员可以修改角色和酒店
        if ($this->currentUser->isSuperAdmin()) {
            if (isset($data['role_id'])) {
                $nextRoleId = (int)$data['role_id'];
                $nextRole = Role::where('id', $nextRoleId)->where('status', Role::STATUS_ENABLED)->find();
                if (!$nextRole) {
                    return $this->error('角色不存在或已停用', 422);
                }
                $roleChanged = $nextRoleId !== (int)$user->role_id;
                $user->role_id = $nextRoleId;
                $targetRole = $nextRole;
            }
            if ($this->hasHotelAssignmentInput($data)) {
                $syncHotelIds = $this->normalizeAssignedHotelIds($data);
                $invalidHotelResponse = $this->validateAssignableHotelIds($syncHotelIds);
                if ($invalidHotelResponse) {
                    return $invalidHotelResponse;
                }
                $user->hotel_id = $syncHotelIds[0] ?? null;
            }
            if (isset($data['status'])) {
                $user->status = $data['status'];
            }
        }

        if ($roleChanged && $syncHotelIds === null) {
            $syncHotelIds = $this->existingAssignedHotelIds((int)$user->id, (int)($user->hotel_id ?? 0));
        }

        if ($this->currentUser->isSuperAdmin() && ($roleChanged || $syncHotelIds !== null) && $targetRole instanceof Role) {
            $candidateHotelIds = $syncHotelIds ?? $this->existingAssignedHotelIds((int)$user->id, (int)($user->hotel_id ?? 0));
            $issueBoundaryResponse = $this->validateExternalUserIssueBoundary($targetRole, $candidateHotelIds);
            if ($issueBoundaryResponse) {
                return $issueBoundaryResponse;
            }
        }

        $hotelTenantIds = [];
        if ($syncHotelIds !== null) {
            $tenantContext = $this->resolveHotelTenantContext($syncHotelIds);
            if (!empty($tenantContext['invalid_hotel_ids'])) {
                return $this->error('酒店租户归属无效: ' . implode(',', $tenantContext['invalid_hotel_ids']), 422);
            }
            $hotelTenantIds = $tenantContext['tenant_ids'];

            if ($this->strictTenantColumnExists('users')) {
                $primaryHotelId = (int)($user->hotel_id ?? 0);
                if ($primaryHotelId > 0) {
                    $user->tenant_id = $hotelTenantIds[$primaryHotelId] ?? null;
                } elseif ($this->strictTenantColumnNullable('users')) {
                    $user->tenant_id = null;
                } else {
                    return $this->error('用户租户字段不允许为空，无法移除主酒店', 422);
                }
            }
        }

        Db::transaction(function () use ($user, $syncHotelIds, $targetRole, $hotelTenantIds): void {
            $user->save();
            if ($syncHotelIds !== null && $targetRole instanceof Role) {
                $this->syncUserHotelPermissions($user, $syncHotelIds, $targetRole, $hotelTenantIds);
            }
        });

        OperationLog::record('user', 'update', '更新用户: ' . $user->username, $this->currentUser->id);

        $savedUser = UserModel::with(['role', 'hotel'])->find((int)$user->id);
        return $this->success($this->appendUserHotelScope($savedUser ?: $user), '更新成功');
    }

    /**
     * 删除用户
     */
    public function delete(int $id): Response
    {
        if ($id == $this->currentUser->id) {
            return $this->error('不能删除自己');
        }

        $data = $this->requestData();
        $forceDelete = $this->isForceDeleteRequested($data);

        $user = UserModel::find($id);
        if (!$user) {
            return $this->error('用户不存在');
        }

        // 权限检查
        if ($this->currentUser->isSuperAdmin()) {
            // 超级管理员可以删除任意用户
        } else {
            return $this->error('权限不足');
        }

        $references = $this->ensureUserCanBeDeleted($user);
        if (!empty($references) && $forceDelete) {
            if (!$this->currentUser->isSuperAdmin()) {
                return $this->error('只有超级管理员可以强制删除用户', 403);
            }

            $blockedReferences = $this->forceDeleteBlockedReferences($references);
            if (!empty($blockedReferences)) {
                return $this->error('该用户存在不可自动解除的业务数据，无法强制删除', 409, [
                    'references' => $blockedReferences,
                ]);
            }
        }

        if (!empty($references) && !$forceDelete) {
            return $this->error('该用户存在关联数据，无法删除，超级管理员可以强制删除', 409, [
                'references' => $references,
                'can_force_delete' => $this->currentUser->isSuperAdmin(),
            ]);
        }

        $username = $user->username;
        if ($forceDelete) {
            Db::transaction(function () use ($user): void {
                $userId = (int)$user->id;
                $this->unlinkUserReferencesForForceDelete($userId);
                $this->clearUserTokenCache($userId);
                $user->delete();
            });
        } else {
            $user->delete();
        }

        OperationLog::record('user', 'delete', '删除用户: ' . $username, $this->currentUser->id);

        return $this->success(null, '删除成功');
    }

    /**
     * 角色列表
     */
    public function roles(): Response
    {
        if (!$this->currentUser->canManageUser()) {
            return $this->error('权限不足', 403);
        }

        $roles = Role::where('status', 1)->order('level', 'asc')->select();
        return $this->success($roles);
    }

    private function validateUsernamePolicy(string $username): ?string
    {
        if ($username === '' || !preg_match('/^[A-Za-z0-9_]{3,50}$/', $username)) {
            return '用户名需为 3-50 位字母、数字或下划线';
        }

        return null;
    }

    private function canEditUserUsername(UserModel $targetUser): bool
    {
        return $this->currentUser->isSuperAdmin() && $targetUser->isBetaUser();
    }

    private function appendUserHotelScope($user): array
    {
        if ($user instanceof UserModel) {
            $user->hidden(['password']);
            $data = $user->toArray();
        } elseif (is_array($user)) {
            $data = $user;
            unset($data['password']);
        } else {
            return [];
        }

        $userId = (int)($data['id'] ?? 0);
        $primaryHotelId = (int)($data['hotel_id'] ?? 0);
        $assignedHotelIds = $this->assignedHotelIds($userId);
        $ownedHotelIds = $this->ownedHotelIdsForUser($userId);
        $effectiveHotelIds = $this->mergeHotelIds($assignedHotelIds, $ownedHotelIds, $primaryHotelId > 0 ? [$primaryHotelId] : []);

        $data['assigned_hotel_ids'] = $assignedHotelIds;
        $data['owned_hotel_ids'] = $ownedHotelIds;
        $data['hotel_ids'] = $this->userDataIsSuperAdmin($data)
            ? $assignedHotelIds
            : $effectiveHotelIds;
        $data['assigned_hotels'] = $this->hotelSummaries($data['hotel_ids']);
        $data['hotel_scope_text'] = $this->userDataIsSuperAdmin($data)
            ? '全部门店'
            : $this->hotelScopeText($data['hotel_ids']);

        return $data;
    }

    private function hasHotelAssignmentInput(array $data): bool
    {
        return array_key_exists('hotel_ids', $data) || array_key_exists('hotel_id', $data);
    }

    /**
     * @return array<int, int>
     */
    private function normalizeAssignedHotelIds(array $data): array
    {
        $raw = array_key_exists('hotel_ids', $data) ? $data['hotel_ids'] : ($data['hotel_id'] ?? null);
        return $this->normalizeHotelIdList($raw);
    }

    /**
     * @return array<int, int>
     */
    private function normalizeHotelIdList($value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $values = is_array($value) ? $value : explode(',', (string)$value);
        $ids = [];
        foreach ($values as $item) {
            if (is_array($item)) {
                continue;
            }
            if (is_numeric($item) && (int)$item > 0) {
                $ids[] = (int)$item;
            }
        }

        return array_values(array_unique($ids));
    }

    private function validateAssignableHotelIds(array $hotelIds): ?Response
    {
        if (empty($hotelIds)) {
            return null;
        }

        $enabledHotelIds = array_values(array_map('intval', HotelModel::where('status', HotelModel::STATUS_ENABLED)
            ->whereIn('id', $hotelIds)
            ->column('id')));
        $missingHotelIds = array_values(array_diff($hotelIds, $enabledHotelIds));

        if (!empty($missingHotelIds)) {
            return $this->error('选择的门店不存在或已停用: ' . implode(',', $missingHotelIds), 422);
        }

        return null;
    }

    /**
     * @param array<int, int> $hotelIds
     * @return array{tenant_ids: array<int, int>, invalid_hotel_ids: array<int, int>}
     */
    private function resolveHotelTenantContext(array $hotelIds): array
    {
        $hotelIds = $this->mergeHotelIds($hotelIds);
        $hotelTenantColumn = $this->strictTenantColumnExists('hotels');
        $userTenantColumn = $this->strictTenantColumnExists('users');
        $permissionTenantColumn = $this->strictTenantColumnExists('user_hotel_permissions');
        $tenantColumnsPresent = $hotelTenantColumn || $userTenantColumn || $permissionTenantColumn;

        if (!$tenantColumnsPresent || empty($hotelIds)) {
            return ['tenant_ids' => [], 'invalid_hotel_ids' => []];
        }

        if (!$hotelTenantColumn) {
            return ['tenant_ids' => [], 'invalid_hotel_ids' => $hotelIds];
        }

        $rows = HotelModel::whereIn('id', $hotelIds)
            ->field('id,tenant_id')
            ->select()
            ->toArray();
        $tenantIds = [];
        foreach ($rows as $row) {
            $hotelId = (int)($row['id'] ?? 0);
            $tenantId = (int)($row['tenant_id'] ?? 0);
            if ($hotelId > 0 && $tenantId > 0) {
                $tenantIds[$hotelId] = $tenantId;
            }
        }

        return [
            'tenant_ids' => $tenantIds,
            'invalid_hotel_ids' => array_values(array_diff($hotelIds, array_keys($tenantIds))),
        ];
    }

    private function validateExternalUserIssueBoundary(Role $role, array $hotelIds): ?Response
    {
        if (!$this->isNormalExternalRole($role)) {
            return null;
        }

        $unsafeCapabilities = (new PermissionService())->normalExternalUnsafeCapabilities($role->getPermissionList());
        if (!empty($unsafeCapabilities)) {
            return $this->error('普通用户角色不能包含 OTA 采集权限或其他高风险权限：' . implode('、', $unsafeCapabilities), 422);
        }

        if (empty($hotelIds)) {
            return $this->error('普通用户必须先分配门店，避免生成无业务范围的外部账号', 422);
        }

        return null;
    }

    private function isNormalExternalRole(Role $role): bool
    {
        return (int)$role->getAttr('id') === Role::NORMAL_USER
            || (string)$role->getAttr('name') === 'normal_user'
            || (int)$role->getAttr('level') >= Role::HOTEL_STAFF;
    }

    /**
     * @return array<int, int>
     */
    private function existingAssignedHotelIds(int $userId, int $primaryHotelId = 0): array
    {
        return $this->userEffectiveHotelIds($userId, $primaryHotelId);
    }

    /**
     * @return array<int, int>
     */
    private function userEffectiveHotelIds(int $userId, int $primaryHotelId = 0): array
    {
        return $this->mergeHotelIds(
            $this->assignedHotelIds($userId),
            $this->ownedHotelIdsForUser($userId),
            $primaryHotelId > 0 && $this->hotelIsEnabled($primaryHotelId) ? [$primaryHotelId] : []
        );
    }

    /**
     * @return array<int, int>
     */
    private function assignedHotelIds(int $userId): array
    {
        if ($userId <= 0 || !$this->tableColumnExists('user_hotel_permissions', 'hotel_id')) {
            return [];
        }

        $query = Db::name('user_hotel_permissions')
            ->alias('uhp')
            ->join('hotels h', 'h.id = uhp.hotel_id')
            ->where('uhp.user_id', $userId)
            ->where('h.status', HotelModel::STATUS_ENABLED);

        if ($this->tableColumnExists('user_hotel_permissions', 'status')) {
            $query->whereIn('uhp.status', ['active', '1', 1]);
        }

        if ($this->tableColumnExists('user_hotel_permissions', 'can_view')) {
            $query->where('uhp.can_view', 1);
        } elseif ($this->tableColumnExists('user_hotel_permissions', 'can_view_online_data')) {
            $query->where('uhp.can_view_online_data', 1);
        }

        return array_values(array_map('intval', $query->column('uhp.hotel_id')));
    }

    /**
     * @return array<int, int>
     */
    private function ownedHotelIdsForUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $column = $this->hotelOwnershipColumn();
        if ($column === '') {
            return [];
        }

        return array_values(array_map('intval', HotelModel::where('status', HotelModel::STATUS_ENABLED)
            ->where($column, $userId)
            ->column('id')));
    }

    /**
     * @param array<int, int> ...$groups
     * @return array<int, int>
     */
    private function mergeHotelIds(array ...$groups): array
    {
        $ids = [];
        foreach ($groups as $group) {
            foreach ($group as $hotelId) {
                if ((int)$hotelId > 0) {
                    $ids[] = (int)$hotelId;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private function hotelIsEnabled(int $hotelId): bool
    {
        return $hotelId > 0 && (bool)HotelModel::where('id', $hotelId)
            ->where('status', HotelModel::STATUS_ENABLED)
            ->find();
    }

    private function hotelOwnershipColumn(): string
    {
        if ($this->tableColumnExists('hotels', 'owner_user_id')) {
            return 'owner_user_id';
        }

        if ($this->tableColumnExists('hotels', 'created_by')) {
            return 'created_by';
        }

        return '';
    }

    /**
     * @param array<int, int> $hotelIds
     * @return array<int, array<string, mixed>>
     */
    private function hotelSummaries(array $hotelIds): array
    {
        if (empty($hotelIds)) {
            return [];
        }

        return HotelModel::whereIn('id', $hotelIds)
            ->field('id,name,code,status')
            ->select()
            ->toArray();
    }

    /**
     * @param array<int, int> $hotelIds
     */
    private function hotelScopeText(array $hotelIds): string
    {
        if (empty($hotelIds)) {
            return '未分配门店';
        }

        $names = array_map(static fn(array $hotel): string => trim((string)($hotel['name'] ?? '')), $this->hotelSummaries($hotelIds));
        $names = array_values(array_filter($names, static fn(string $name): bool => $name !== ''));

        return empty($names) ? implode(',', $hotelIds) : implode('、', $names);
    }

    private function userDataIsSuperAdmin(array $data): bool
    {
        $roleId = (int)($data['role_id'] ?? 0);
        if ($roleId === Role::SUPER_ADMIN) {
            return true;
        }

        if (in_array($roleId, [Role::BETA_USER, Role::NORMAL_USER], true)) {
            return false;
        }

        $role = $data['role'] ?? null;
        if (is_array($role)) {
            $roleId = (int)($role['id'] ?? $roleId);
            $roleName = (string)($role['name'] ?? '');
            $roleLevel = (int)($role['level'] ?? 0);
            if (
                in_array($roleId, [Role::BETA_USER, Role::NORMAL_USER], true)
                || in_array($roleName, ['beta_user', 'normal_user'], true)
                || $roleLevel >= Role::BETA_USER
            ) {
                return false;
            }

            $permissions = Role::normalizePermissions($role['permissions'] ?? []);
            return in_array('all', $permissions, true)
                && ($roleId === Role::SUPER_ADMIN || $roleName === 'admin' || $roleLevel === 1);
        }

        return false;
    }

    /**
     * @param array<int, int> $hotelIds
     */
    private function syncUserHotelPermissions(UserModel $targetUser, array $hotelIds, Role $targetRole, array $hotelTenantIds = []): void
    {
        $userId = (int)$targetUser->id;
        if ($userId <= 0 || !$this->tableColumnExists('user_hotel_permissions', 'user_id')) {
            return;
        }

        $hotelIds = $this->mergeHotelIds($hotelIds);
        Db::name('user_hotel_permissions')->where('user_id', $userId)->delete();

        foreach ($hotelIds as $index => $hotelId) {
            $payload = $this->filterExistingColumns(
                'user_hotel_permissions',
                $this->buildHotelPermissionPayload($targetUser, $targetRole, $hotelId, $index === 0, $hotelTenantIds)
            );
            if (!empty($payload)) {
                Db::name('user_hotel_permissions')->insert($payload);
            }
        }
    }

    private function buildHotelPermissionPayload(
        UserModel $targetUser,
        Role $targetRole,
        int $hotelId,
        bool $isPrimary,
        array $hotelTenantIds = []
    ): array
    {
        $permissions = $targetRole->getPermissionList();
        $allows = static fn(string $permission): int => Role::permissionListAllows($permissions, $permission) ? 1 : 0;
        $canViewReport = $allows('report.view');
        $canFillReport = $allows('report.fill');
        $canEditReport = $allows('report.update');
        $canManageHotelRecord = $allows('hotel.update') || $allows('hotel.delete');
        $canViewOta = $allows('ota.view');
        $canFetchOta = $allows('ota.collect');
        $canDeleteOta = $allows('ota.delete');
        $canExport = $allows('ota.export') || $allows('report.export') ? 1 : 0;

        $payload = [
            'user_id' => (int)$targetUser->id,
            'hotel_id' => $hotelId,
            'scope_type' => $this->userOwnsHotel((int)$targetUser->id, $hotelId) ? 'owner' : 'granted',
            'can_view' => 1,
            'can_report' => $canViewReport,
            'can_fill' => $canFillReport,
            'can_edit' => $canManageHotelRecord || $canEditReport ? 1 : 0,
            'can_fetch_ota' => $canFetchOta,
            'can_delete_ota' => $canDeleteOta,
            'can_export' => $canExport,
            'can_ai' => $allows('ai.view') || $allows('ai.execute') ? 1 : 0,
            'can_operation' => $allows('operation.view') || $allows('operation.execute') ? 1 : 0,
            'can_investment' => $allows('investment.view') || $allows('investment.simulate') ? 1 : 0,
            'status' => 'active',
            'created_by' => (int)($this->currentUser->id ?? 0),
            'can_view_report' => $canViewReport,
            'can_fill_daily_report' => $canFillReport,
            'can_fill_monthly_task' => $canFillReport,
            'can_edit_report' => $canEditReport,
            'can_delete_report' => $allows('report.delete'),
            'can_view_online_data' => $canViewOta,
            'can_fetch_online_data' => $canFetchOta,
            'can_delete_online_data' => $canDeleteOta,
            'is_primary' => $isPrimary ? 1 : 0,
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ];

        if ($this->strictTenantColumnExists('user_hotel_permissions')) {
            $tenantId = (int)($hotelTenantIds[$hotelId] ?? 0);
            if ($tenantId <= 0) {
                throw new \RuntimeException('Hotel tenant binding is required before permission creation.');
            }
            $payload['tenant_id'] = $tenantId;
        }

        return $payload;
    }

    private function userOwnsHotel(int $userId, int $hotelId): bool
    {
        if ($userId <= 0 || $hotelId <= 0) {
            return false;
        }

        $column = $this->hotelOwnershipColumn();
        if ($column === '') {
            return false;
        }

        return (bool)HotelModel::where('id', $hotelId)
            ->where($column, $userId)
            ->find();
    }

    private function filterExistingColumns(string $table, array $payload): array
    {
        $columns = $this->tableColumns($table);
        if (empty($columns)) {
            return $payload;
        }

        return array_intersect_key($payload, array_flip($columns));
    }

    private function ensureUserCanBeDeleted(UserModel $user): array
    {
        $userId = (int)$user->id;
        $checks = [
            ['daily_reports', 'submitter_id', '日报'],
            ['monthly_tasks', 'submitter_id', '月任务'],
            ['user_hotel_permissions', 'user_id', '酒店权限'],
            ['operation_logs', 'user_id', '操作日志'],
            ['login_logs', 'user_id', '登录日志'],
            ['quant_simulation_records', 'created_by', '量化测算记录'],
            ['strategy_simulation_records', 'created_by', '战略推演记录'],
            ['feasibility_reports', 'created_by', '可研报告'],
            ['expansion_records', 'created_by', '扩张记录'],
            ['transfer_records', 'created_by', '转让记录'],
            ['maintenance_plans', 'created_by', '维护计划'],
            ['device_maintenance', 'operator_id', '设备维护记录'],
        ];

        $references = [];
        foreach ($checks as [$table, $column, $label]) {
            $count = $this->countReferenceRows($table, $column, $userId);
            if ($count > 0) {
                $references[] = ['table' => $table, 'label' => $label, 'count' => $count];
            }
        }

        return $references;
    }

    private function isForceDeleteRequested(array $data): bool
    {
        $force = $data['force'] ?? $this->request->param('force', false);
        return $force === true || $force === 1 || $force === '1' || $force === 'true';
    }

    private function forceDeleteBlockedReferences(array $references): array
    {
        $columns = $this->forceDeleteReferenceColumns();
        $blocked = [];

        foreach ($references as $reference) {
            $table = (string)($reference['table'] ?? '');
            $column = $columns[$table] ?? null;
            if (!$column) {
                $blocked[] = $reference;
                continue;
            }

            if ($table === 'user_hotel_permissions') {
                continue;
            }

            if (!$this->tableColumnNullable($table, $column)) {
                $blocked[] = $reference;
            }
        }

        return $blocked;
    }

    private function unlinkUserReferencesForForceDelete(int $userId): void
    {
        foreach ($this->forceDeleteReferenceColumns() as $table => $column) {
            if (!$this->tableColumnExists($table, $column)) {
                continue;
            }

            if ($table === 'user_hotel_permissions') {
                Db::name($table)->where($column, $userId)->delete();
                continue;
            }

            if ($this->tableColumnNullable($table, $column)) {
                Db::name($table)->where($column, $userId)->update([$column => null]);
            }
        }
    }

    private function clearUserTokenCache(int $userId): void
    {
        $token = cache('user_token_' . $userId);
        if (is_string($token) && $token !== '') {
            cache('token_' . $token, null);
        }
        cache('user_token_' . $userId, null);
    }

    private function forceDeleteReferenceColumns(): array
    {
        return [
            'daily_reports' => 'submitter_id',
            'monthly_tasks' => 'submitter_id',
            'user_hotel_permissions' => 'user_id',
            'operation_logs' => 'user_id',
            'login_logs' => 'user_id',
            'quant_simulation_records' => 'created_by',
            'strategy_simulation_records' => 'created_by',
            'feasibility_reports' => 'created_by',
            'expansion_records' => 'created_by',
            'transfer_records' => 'created_by',
            'maintenance_plans' => 'created_by',
            'device_maintenance' => 'operator_id',
        ];
    }

    private function countReferenceRows(string $table, string $column, int $value): int
    {
        if (!$this->tableColumnExists($table, $column)) {
            return 0;
        }

        return (int)Db::name($table)->where($column, $value)->count();
    }

    /**
     * @return array<int, string>
     */
    private function tableColumns(string $table): array
    {
        $table = str_replace('`', '', $table);
        if (array_key_exists($table, $this->tableColumnCache)) {
            return $this->tableColumnCache[$table];
        }

        try {
            $rows = Db::query("SHOW COLUMNS FROM `{$table}`");
            $columns = array_values(array_filter(array_map(static fn(array $row): string => (string)($row['Field'] ?? ''), $rows)));
        } catch (\Throwable $e) {
            try {
                $rows = Db::query("PRAGMA table_info(`{$table}`)");
                $columns = array_values(array_filter(array_map(static fn(array $row): string => (string)($row['name'] ?? ''), $rows)));
            } catch (\Throwable $ignored) {
                $columns = [];
            }
        }

        $this->tableColumnCache[$table] = $columns;
        return $columns;
    }

    private function strictTenantColumnExists(string $table): bool
    {
        if (!in_array($table, ['hotels', 'users', 'user_hotel_permissions'], true)) {
            throw new \InvalidArgumentException('Unsupported tenant schema table.');
        }
        if (array_key_exists($table, $this->tenantColumnCache)) {
            return $this->tenantColumnCache[$table];
        }

        try {
            $exists = !empty($this->querySchema("SHOW COLUMNS FROM `{$table}` LIKE 'tenant_id'"));
        } catch (\Throwable $mysqlError) {
            try {
                $rows = $this->querySchema("PRAGMA table_info(`{$table}`)");
            } catch (\Throwable $sqliteError) {
                throw new \RuntimeException(
                    "Unable to inspect required tenant schema for {$table}.tenant_id",
                    0,
                    $sqliteError
                );
            }

            $exists = false;
            foreach ($rows as $row) {
                if (($row['name'] ?? '') === 'tenant_id') {
                    $exists = true;
                    break;
                }
            }
        }

        $this->tenantColumnCache[$table] = $exists;
        return $exists;
    }

    private function strictTenantColumnNullable(string $table): bool
    {
        if ($table !== 'users') {
            throw new \InvalidArgumentException('Unsupported nullable tenant schema table.');
        }

        try {
            $columns = $this->querySchema("SHOW COLUMNS FROM `{$table}` LIKE 'tenant_id'");
            return !empty($columns) && strtoupper((string)($columns[0]['Null'] ?? '')) === 'YES';
        } catch (\Throwable $mysqlError) {
            try {
                $columns = $this->querySchema("PRAGMA table_info(`{$table}`)");
            } catch (\Throwable $sqliteError) {
                throw new \RuntimeException(
                    "Unable to inspect required tenant schema nullability for {$table}.tenant_id",
                    0,
                    $sqliteError
                );
            }

            foreach ($columns as $definition) {
                if ((string)($definition['name'] ?? '') === 'tenant_id') {
                    return (int)($definition['notnull'] ?? 1) === 0;
                }
            }

            return false;
        }
    }

    protected function querySchema(string $sql): array
    {
        return Db::query($sql);
    }

    private function tableColumnExists(string $table, string $column): bool
    {
        $table = str_replace('`', '', $table);
        $column = str_replace(['`', "'"], '', $column);

        return in_array($column, $this->tableColumns($table), true);
    }

    private function tableColumnNullable(string $table, string $column): bool
    {
        $table = str_replace('`', '', $table);
        $column = str_replace(['`', "'"], '', $column);

        try {
            $columns = Db::query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
            if (empty($columns)) {
                return false;
            }

            return strtoupper((string)($columns[0]['Null'] ?? '')) === 'YES';
        } catch (\Throwable $e) {
            try {
                $columns = Db::query("PRAGMA table_info(`{$table}`)");
            } catch (\Throwable $ignored) {
                return false;
            }

            foreach ($columns as $definition) {
                if ((string)($definition['name'] ?? '') === $column) {
                    return (int)($definition['notnull'] ?? 1) === 0;
                }
            }

            return false;
        }
    }
}
