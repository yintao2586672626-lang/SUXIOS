<?php
declare(strict_types=1);

namespace app\controller;

use app\model\User as UserModel;
use app\model\Role;
use app\model\Hotel;
use app\model\OperationLog;
use think\Response;

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
            $query->where('hotel_id', $this->currentUser->hotel_id);
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

        $data = $this->request->post();

        $this->validate($data, [
            'username' => 'require|alphaNum|min:3|max:20',
            'password' => 'require|min:6',
            'role_id' => 'require|integer',
        ], [
            'username.require' => '用户名不能为空',
            'username.alphaNum' => '用户名只能包含字母和数字',
            'username.min' => '用户名至少3个字符',
            'username.max' => '用户名最多20个字符',
            'password.require' => '密码不能为空',
            'password.min' => '密码至少6个字符',
            'role_id.require' => '请选择角色',
        ]);

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
            $hotelId = $this->currentUser->hotel_id;
            if (empty($hotelId)) {
                return $this->error('您未关联酒店，无法创建用户');
            }
            // 店长只能创建店员角色
            if ($roleId != Role::HOTEL_STAFF) {
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
            if ($user->hotel_id != $this->currentUser->hotel_id) {
                return $this->error('只能修改自己酒店的用户');
            }
            if ($user->role_id != Role::HOTEL_STAFF) {
                return $this->error('只能修改店员账号');
            }
        } elseif ($this->currentUser->id == $id) {
            // 用户修改自己
        } else {
            return $this->error('权限不足');
        }

        $data = $this->request->post();

        // 用户名唯一性检查
        if (!empty($data['username']) && $data['username'] != $user->username) {
            $exists = UserModel::where('username', $data['username'])->find();
            if ($exists) {
                return $this->error('用户名已存在');
            }
            $user->username = $data['username'];
        }

        if (!empty($data['password'])) {
            if (strlen($data['password']) < 6) {
                return $this->error('密码至少6个字符');
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

        $user = UserModel::find($id);
        if (!$user) {
            return $this->error('用户不存在');
        }

        // 权限检查
        if ($this->currentUser->isSuperAdmin()) {
            // 超级管理员可以删除任意用户
        } elseif ($this->currentUser->isHotelManager()) {
            // 店长只能删除自己酒店的店员
            if ($user->hotel_id != $this->currentUser->hotel_id) {
                return $this->error('只能删除自己酒店的用户');
            }
            if ($user->role_id != Role::HOTEL_STAFF) {
                return $this->error('只能删除店员账号');
            }
        } else {
            return $this->error('权限不足');
        }

        $username = $user->username;
        $user->delete();

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
}
