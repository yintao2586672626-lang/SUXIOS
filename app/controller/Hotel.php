<?php
declare(strict_types=1);

namespace app\controller;

use app\model\Hotel as HotelModel;
use app\model\OperationLog;
use think\exception\ValidateException;
use think\Response;

class Hotel extends Base
{
    /**
     * 酒店列表
     */
    public function index(): Response
    {
        $this->checkPermission();

        $pagination = $this->getPagination();
        $name = $this->request->param('name', '');
        $status = $this->request->param('status', '');

        $query = HotelModel::order('id', 'desc');

        if ($name) {
            $query->whereLike('name', '%' . $name . '%');
        }
        if ($status !== '') {
            $query->where('status', $status);
        }

        // 非超级管理员只能看到有权限的酒店
        if (!$this->currentUser->isSuperAdmin()) {
            $permittedHotelIds = $this->currentUser->getPermittedHotelIds();
            $query->whereIn('id', $permittedHotelIds);
        }

        $total = $query->count();
        $list = $query->page($pagination['page'], $pagination['page_size'])->select();

        return $this->paginate($list, $total, $pagination['page'], $pagination['page_size']);
    }

    /**
     * 所有酒店（下拉选择用）
     */
    public function all(): Response
    {
        $query = HotelModel::where('status', HotelModel::STATUS_ENABLED)
            ->field('id, name, code')
            ->order('id', 'asc');

        // 非超级管理员只能看到有权限的酒店
        if ($this->currentUser && !$this->currentUser->isSuperAdmin()) {
            $permittedHotelIds = $this->currentUser->getPermittedHotelIds();
            $query->whereIn('id', $permittedHotelIds);
        }

        $list = $query->select();

        return $this->success($list);
    }

    /**
     * 酒店详情
     */
    public function read(int $id): Response
    {
        $this->checkPermission();

        $hotel = HotelModel::find($id);
        if (!$hotel) {
            return $this->error('酒店不存在');
        }

        // 权限检查
        if (!$this->currentUser->isSuperAdmin()) {
            $permittedHotelIds = $this->currentUser->getPermittedHotelIds();
            if (!in_array($id, $permittedHotelIds)) {
                return $this->error('无权查看此酒店');
            }
        }

        return $this->success($hotel);
    }

    /**
     * 创建酒店
     */
    public function create(): Response
    {
        $this->checkPermission(true);

        $data = $this->request->post();

        $this->validate($data, [
            'name' => 'require|max:100',
        ], [
            'name.require' => '酒店名称不能为空',
            'name.max' => '酒店名称最多100个字符',
        ]);

        // 检查编码唯一性
        if (!empty($data['code'])) {
            $exists = HotelModel::where('code', $data['code'])->find();
            if ($exists) {
                return $this->error('酒店编码已存在');
            }
        }

        $hotel = new HotelModel();
        $hotel->name = $data['name'];
        $hotel->code = $data['code'] ?? '';
        $hotel->address = $data['address'] ?? '';
        $hotel->contact_person = $data['contact_person'] ?? '';
        $hotel->contact_phone = $data['contact_phone'] ?? '';
        $hotel->description = $data['description'] ?? '';
        $hotel->status = $data['status'] ?? HotelModel::STATUS_ENABLED;
        $hotel->save();

        OperationLog::record('hotel', 'create', '创建酒店: ' . $hotel->name, $this->currentUser->id ?? null);

        return $this->success($hotel, '创建成功');
    }

    /**
     * 更新酒店
     */
    public function update(int $id): Response
    {
        $this->checkPermission(true);

        $hotel = HotelModel::find($id);
        if (!$hotel) {
            return $this->error('酒店不存在');
        }

        $data = $this->request->post();

        $this->validate($data, [
            'name' => 'require|max:100',
        ], [
            'name.require' => '酒店名称不能为空',
            'name.max' => '酒店名称最多100个字符',
        ]);

        // 检查编码唯一性
        if (!empty($data['code']) && $data['code'] != $hotel->code) {
            $exists = HotelModel::where('code', $data['code'])->find();
            if ($exists) {
                return $this->error('酒店编码已存在');
            }
        }

        // 记录状态变更
        $oldStatus = $hotel->status;
        $newStatus = $data['status'] ?? $oldStatus;
        $statusChanged = false;
        $affectedUsers = 0;
        
        if ($oldStatus != $newStatus) {
            $statusChanged = true;
            // 统计受影响的用户数
            $affectedUsers = \app\model\User::where('hotel_id', $id)->count();
        }

        $hotel->name = $data['name'];
        $hotel->code = $data['code'] ?? '';
        $hotel->address = $data['address'] ?? '';
        $hotel->contact_person = $data['contact_person'] ?? '';
        $hotel->contact_phone = $data['contact_phone'] ?? '';
        $hotel->description = $data['description'] ?? '';
        if (isset($data['status'])) {
            $hotel->status = $data['status'];
        }
        $hotel->save();

        // 记录操作日志
        $logDesc = '更新酒店: ' . $hotel->name;
        if ($statusChanged) {
            $statusText = $newStatus == HotelModel::STATUS_ENABLED ? '启用' : '禁用';
            $logDesc .= " (状态变更: {$statusText}, 影响{$affectedUsers}个用户)";
        }
        OperationLog::record('hotel', 'update', $logDesc, $this->currentUser->id ?? null, $id);

        // 返回结果，包含状态变更信息
        $result = $hotel->toArray();
        if ($statusChanged) {
            $result['status_changed'] = true;
            $result['affected_users'] = $affectedUsers;
            $result['status_text'] = $newStatus == HotelModel::STATUS_ENABLED ? '已启用' : '已禁用';
        }

        return $this->success($result, $statusChanged ? "酒店已{$result['status_text']}，影响{$affectedUsers}个用户的权限" : '更新成功');
    }

    /**
     * 删除酒店
     */
    public function delete(int $id): Response
    {
        $this->checkPermission(true);

        $hotel = HotelModel::find($id);
        if (!$hotel) {
            return $this->error('酒店不存在');
        }

        $hotelName = $hotel->name;
        $hotel->delete();

        OperationLog::record('hotel', 'delete', '删除酒店: ' . $hotelName, $this->currentUser->id ?? null);

        return $this->success(null, '删除成功');
    }

    /**
     * 检查权限
     */
    private function checkPermission(bool $requireAdmin = false): void
    {
        // 未登录检查
        if (!$this->currentUser) {
            abort(401, '未登录');
        }

        // 管理员权限检查
        if ($requireAdmin && !$this->currentUser->isSuperAdmin()) {
            abort(403, '权限不足');
        }
    }
}
