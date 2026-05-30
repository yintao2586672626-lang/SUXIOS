<?php
declare(strict_types=1);

namespace app\controller;

use app\model\User as UserModel;
use app\model\Role;
use app\model\Hotel;
use app\model\OperationLog;
use think\Response;
use think\facade\Db;

class User extends Base
{
    /**
     * 用户列表
     */
    public function index(): Response
    {
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

        return $this->paginate($list, $total, $pagination['page'], $pagination['page_size']);
    }

    /**
     * 用户详情
     */
    public function read(int $id): Response
    {
        $user = UserModel::with(['role', 'hotel'])->find($id);
        if (!$user) {
            return $this->error('用户不存在');
        }

        if (!$this->currentUser->isSuperAdmin() && !in_array((int)$user->hotel_id, array_map('intval', $this->currentUser->getPermittedHotelIds()), true)) {
            return $this->error('权限不足', 403);
        }

        $user->hidden(['password']);
        return $this->success($user);
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

        $this->validate($data, [
            'username' => 'require|alphaNum|min:3|max:20',
            'password' => 'require',
            'role_id' => 'require|integer',
        ], [
            'username.require' => '用户名不能为空',
            'username.alphaNum' => '用户名只能包含字母和数字',
            'username.min' => '用户名至少3个字符',
            'username.max' => '用户名最多20个字符',
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
        $roleId = $data['role_id'];
        
        if ($this->currentUser->isSuperAdmin()) {
            $hotelId = $data['hotel_id'] ?? null;
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
            $targetRole = Role::find((int)$roleId);
            if (!$targetRole || (int)$targetRole->level < 3) {
                return $this->error('您只能创建店员账号');
            }
        }

        $user = new UserModel();
        $user->username = $data['username'];
        $user->password = $data['password'];
        $user->realname = $data['realname'] ?? '';
        $user->email = $data['email'] ?? '';
        $user->phone = $data['phone'] ?? '';
        $user->role_id = $roleId;
        $user->hotel_id = $hotelId;
        $user->status = $data['status'] ?? UserModel::STATUS_ENABLED;
        $user->save();

        OperationLog::record('user', 'create', '创建用户: ' . $user->username, $this->currentUser->id);

        return $this->success($user->hidden(['password']), '创建成功');
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
        } elseif ($this->currentUser->isHotelManager()) {
            // 店长只能修改自己酒店的店员
            $permittedHotelIds = array_values(array_map('intval', $this->currentUser->getPermittedHotelIds()));
            if (!in_array((int)$user->hotel_id, $permittedHotelIds, true)) {
                return $this->error('只能修改自己酒店的用户');
            }
            $targetRole = Role::find((int)$user->role_id);
            if (!$targetRole || (int)$targetRole->level < 3) {
                return $this->error('只能修改店员账号');
            }
        } elseif ($this->currentUser->id == $id) {
            // 用户修改自己
        } else {
            return $this->error('权限不足');
        }

        $data = $this->requestData();

        // 用户名唯一性检查
        if (!empty($data['username']) && $data['username'] != $user->username) {
            $exists = UserModel::where('username', $data['username'])->find();
            if ($exists) {
                return $this->error('用户名已存在');
            }
            $user->username = $data['username'];
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

        // 只有超级管理员可以修改角色和酒店
        if ($this->currentUser->isSuperAdmin()) {
            if (isset($data['role_id'])) {
                $user->role_id = $data['role_id'];
            }
            if (isset($data['hotel_id'])) {
                $user->hotel_id = $data['hotel_id'];
            }
            if (isset($data['status'])) {
                $user->status = $data['status'];
            }
        }

        $user->save();

        OperationLog::record('user', 'update', '更新用户: ' . $user->username, $this->currentUser->id);

        return $this->success($user->hidden(['password']), '更新成功');
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
        } elseif ($this->currentUser->isHotelManager()) {
            // 店长只能删除自己酒店的店员
            $permittedHotelIds = array_values(array_map('intval', $this->currentUser->getPermittedHotelIds()));
            if (!in_array((int)$user->hotel_id, $permittedHotelIds, true)) {
                return $this->error('只能删除自己酒店的用户');
            }
            $targetRole = Role::find((int)$user->role_id);
            if (!$targetRole || (int)$targetRole->level < 3) {
                return $this->error('只能删除店员账号');
            }
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
        $roles = Role::where('status', 1)->order('level', 'asc')->select();
        return $this->success($roles);
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

    private function tableColumnExists(string $table, string $column): bool
    {
        $table = str_replace('`', '', $table);
        $column = str_replace(['`', "'"], '', $column);

        try {
            return !empty(Db::query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'"));
        } catch (\Throwable $e) {
            return false;
        }
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
            return false;
        }
    }
}
