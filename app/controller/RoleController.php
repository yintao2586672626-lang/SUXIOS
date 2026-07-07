<?php
declare(strict_types=1);

namespace app\controller;

use app\model\Role;
use app\model\OperationLog;
use app\service\PermissionService;
use think\Response;

class RoleController extends Base
{
    /**
     * 角色列表
     */
    public function index(): Response
    {
        $this->checkSuperAdmin();

        $roles = Role::with(['users'])->order('level', 'asc')->select();
        return $this->success($roles);
    }

    /**
     * 角色详情
     */
    public function read(int $id): Response
    {
        $this->checkSuperAdmin();

        $role = Role::find($id);
        if (!$role) {
            return $this->error('角色不存在');
        }

        return $this->success($role);
    }

    /**
     * 创建角色
     */
    public function create(): Response
    {
        $this->checkSuperAdmin();

        $data = $this->requestData();

        $this->validate($data, [
            'name' => 'require|max:50',
            'display_name' => 'require|max:50',
            'level' => 'require|integer',
        ], [
            'name.require' => '角色标识不能为空',
            'display_name.require' => '角色名称不能为空',
            'level.require' => '角色等级不能为空',
        ]);

        // 检查标识唯一性
        $exists = Role::where('name', $data['name'])->find();
        if ($exists) {
            return $this->error('角色标识已存在');
        }

        $permissions = $this->normalizePermissionPayload($data['permissions'] ?? []);
        $boundaryResponse = $this->validateRolePermissionBoundary((string)$data['name'], $permissions);
        if ($boundaryResponse) {
            return $boundaryResponse;
        }

        $role = new Role();
        $role->name = $data['name'];
        $role->display_name = $data['display_name'];
        $role->description = $data['description'] ?? '';
        $role->level = $data['level'];
        $role->permissions = json_encode($permissions);
        $role->status = $data['status'] ?? Role::STATUS_ENABLED;
        $role->save();

        OperationLog::record('role', 'create', '创建角色: ' . $role->display_name, $this->currentUser->id);

        return $this->success($role, '创建成功');
    }

    /**
     * 更新角色
     */
    public function update(int $id): Response
    {
        $this->checkSuperAdmin();

        $role = Role::find($id);
        if (!$role) {
            return $this->error('角色不存在');
        }

        // 超级管理员角色(id=1)只能修改权限
        if ($id === 1) {
            $data = $this->requestData();
            $role->permissions = isset($data['permissions']) ? json_encode($data['permissions']) : '[]';
            $role->save();
            OperationLog::record('role', 'update', '更新超级管理员权限', $this->currentUser->id);
            return $this->success($role, '更新成功');
        }

        $data = $this->requestData();
        $identityResponse = $this->validateBuiltInExternalRoleIdentity($role, $data);
        if ($identityResponse) {
            return $identityResponse;
        }

        $nextName = !empty($data['name']) ? (string)$data['name'] : (string)$role->name;
        $permissions = isset($data['permissions'])
            ? $this->normalizePermissionPayload($data['permissions'])
            : Role::normalizePermissions($role->permissions);
        $boundaryResponse = $this->validateRolePermissionBoundary($nextName, $permissions, $role);
        if ($boundaryResponse) {
            return $boundaryResponse;
        }

        // 检查标识唯一性
        if (!empty($data['name']) && $data['name'] != $role->name) {
            $exists = Role::where('name', $data['name'])->find();
            if ($exists) {
                return $this->error('角色标识已存在');
            }
            $role->name = $data['name'];
        }

        $role->display_name = $data['display_name'] ?? $role->display_name;
        $role->description = $data['description'] ?? $role->description;
        $role->level = $data['level'] ?? $role->level;
        $role->permissions = isset($data['permissions']) ? json_encode($permissions) : $role->permissions;
        $role->status = $data['status'] ?? $role->status;
        $role->save();

        OperationLog::record('role', 'update', '更新角色: ' . $role->display_name, $this->currentUser->id);

        return $this->success($role, '更新成功');
    }

    /**
     * 删除角色
     */
    public function delete(int $id): Response
    {
        $this->checkSuperAdmin();

        $role = Role::find($id);
        if (!$role) {
            return $this->error('角色不存在');
        }

        // 系统内置角色不能删除
        if (in_array($id, [1, 2, 3])) {
            return $this->error('系统内置角色不能删除');
        }

        // 检查是否有用户使用该角色
        $userCount = \app\model\User::where('role_id', $id)->count();
        if ($userCount > 0) {
            return $this->error("该角色下有 {$userCount} 个用户，无法删除");
        }

        $name = $role->display_name;
        $role->delete();

        OperationLog::record('role', 'delete', '删除角色: ' . $name, $this->currentUser->id);

        return $this->success(null, '删除成功');
    }

    /**
     * 获取所有可用权限列表
     */
    public function permissions(): Response
    {
        $this->checkSuperAdmin();

        // 系统定义的权限列表
        $permissions = [
            ['key' => 'all', 'name' => '全部权限', 'group' => '系统'],
            ['key' => 'can_view_report', 'name' => '查看报表', 'group' => '报表'],
            ['key' => 'can_fill_daily_report', 'name' => '填写日报', 'group' => '报表'],
            ['key' => 'can_fill_monthly_task', 'name' => '填写月任务', 'group' => '报表'],
            ['key' => 'can_edit_report', 'name' => '编辑报表', 'group' => '报表'],
            ['key' => 'can_delete_report', 'name' => '删除报表', 'group' => '报表'],
            ['key' => 'can_view_online_data', 'name' => '查看线上数据', 'group' => '线上数据'],
            ['key' => 'can_fetch_online_data', 'name' => '获取线上数据', 'group' => '线上数据'],
            ['key' => 'can_delete_online_data', 'name' => '删除线上数据', 'group' => '线上数据'],
            ['key' => 'can_manage_own_hotels', 'name' => '管理自己添加的酒店', 'group' => '酒店'],
            ['key' => 'can_use_ai_decision', 'name' => 'Use AI decision', 'group' => 'P0 protected core'],
            ['key' => 'can_use_investment', 'name' => 'Use investment simulation', 'group' => 'P0 protected core'],
            ['key' => 'can_export_data', 'name' => 'Export protected data', 'group' => 'P0 protected core'],
            ['key' => 'can_view_field_assets', 'name' => 'View field assets', 'group' => 'P0 protected core'],
            ['key' => 'can_view_diagnostics', 'name' => 'View diagnostics', 'group' => 'P0 protected core'],
            ['key' => 'can_manage_ai_governance', 'name' => 'Manage AI governance', 'group' => 'P0 protected core'],
        ];

        return $this->success($permissions);
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
            abort(403, '需要超级管理员权限');
        }
    }

    private function normalizePermissionPayload($permissions): array
    {
        return Role::normalizePermissions($permissions);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateBuiltInExternalRoleIdentity(Role $role, array $data): ?Response
    {
        $roleId = (int)$role->getAttr('id');
        if (!in_array($roleId, [Role::BETA_USER, Role::NORMAL_USER], true)) {
            return null;
        }

        $nameChanged = array_key_exists('name', $data)
            && (string)$data['name'] !== (string)$role->getAttr('name');
        $levelChanged = array_key_exists('level', $data)
            && (int)$data['level'] !== (int)$role->getAttr('level');

        if ($nameChanged || $levelChanged) {
            return $this->error('内置外发角色的标识和等级不能修改', 422);
        }

        return null;
    }

    private function validateRolePermissionBoundary(string $roleName, array $permissions, ?Role $existingRole = null): ?Response
    {
        if (!$this->isNormalExternalRoleIdentity($roleName, $existingRole)) {
            return null;
        }

        $unsafeCapabilities = (new PermissionService())->normalExternalUnsafeCapabilities($permissions);
        if (!empty($unsafeCapabilities)) {
            return $this->error('普通用户角色不能包含 OTA 采集权限或其他高风险权限：' . implode('、', $unsafeCapabilities), 422);
        }

        return null;
    }

    private function isNormalExternalRoleIdentity(string $roleName, ?Role $existingRole = null): bool
    {
        if ($roleName === 'normal_user') {
            return true;
        }

        if (!$existingRole instanceof Role) {
            return false;
        }

        return (int)$existingRole->getAttr('id') === Role::NORMAL_USER
            || (string)$existingRole->getAttr('name') === 'normal_user';
    }
}
