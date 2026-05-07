<?php
declare(strict_types=1);

namespace app\controller;

use app\model\Role;
use app\model\OperationLog;
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
        
        $data = $this->request->post();
        
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
        
        $role = new Role();
        $role->name = $data['name'];
        $role->display_name = $data['display_name'];
        $role->description = $data['description'] ?? '';
        $role->level = $data['level'];
        $role->permissions = isset($data['permissions']) ? json_encode($data['permissions']) : '[]';
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
            $data = $this->request->post();
            $role->permissions = isset($data['permissions']) ? json_encode($data['permissions']) : '[]';
            $role->save();
            OperationLog::record('role', 'update', '更新超级管理员权限', $this->currentUser->id);
            return $this->success($role, '更新成功');
        }
        
        $data = $this->request->post();
        
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
        $role->permissions = isset($data['permissions']) ? json_encode($data['permissions']) : $role->permissions;
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
}
