<?php
declare(strict_types=1);

namespace app\controller;

use app\model\Hotel as HotelModel;
use app\model\OperationLog;
use think\exception\ValidateException;
use think\Response;
use think\facade\Db;

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

        $data = $this->requestData();
        $code = $this->normalizeHotelCode($data['code'] ?? null);
        $data['code'] = $code ?? '';

        $this->validate($data, [
            'name' => 'require|max:100',
            'code' => 'max:50',
        ], [
            'name.require' => '酒店名称不能为空',
            'name.max' => '酒店名称最多100个字符',
            'code.max' => '酒店编码最多50个字符',
        ]);

        // 检查编码唯一性
        if ($code !== null) {
            $exists = HotelModel::where('code', $code)->find();
            if ($exists) {
                return $this->error('酒店编码已存在');
            }
        }

        $hotel = new HotelModel();
        $hotel->name = $data['name'];
        $hotel->code = $code;
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

        $data = $this->requestData();
        $code = $this->normalizeHotelCode($data['code'] ?? null);
        $data['code'] = $code ?? '';

        $this->validate($data, [
            'name' => 'require|max:100',
            'code' => 'max:50',
        ], [
            'name.require' => '酒店名称不能为空',
            'name.max' => '酒店名称最多100个字符',
            'code.max' => '酒店编码最多50个字符',
        ]);

        // 检查编码唯一性
        if ($code !== null) {
            $exists = HotelModel::where('code', $code)->where('id', '<>', $id)->find();
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
        $hotel->code = $code;
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

    private function normalizeHotelCode($value): ?string
    {
        $code = trim((string)($value ?? ''));
        return $code === '' ? null : $code;
    }

    /**
     * 删除酒店
     */
    public function delete(int $id): Response
    {
        $this->checkPermission(true);
        $data = $this->requestData();
        $forceDelete = $this->isForceDeleteRequested($data);

        $hotel = HotelModel::find($id);
        if (!$hotel) {
            return $this->error('酒店不存在');
        }

        $references = $this->ensureHotelCanBeDeleted($id);
        if ($this->shouldBlockHotelDelete($references, $forceDelete)) {
            return $this->error('该酒店存在关联数据，超级管理员可以确认后强制删除；如需保留历史经营入口，请改为禁用酒店', 409, [
                'references' => $references,
                'can_force_delete' => true,
            ]);
        }

        $hotelName = $hotel->name;
        $forcedDelete = !empty($references) && $forceDelete;
        $hotel->delete();

        OperationLog::record(
            'hotel',
            'delete',
            ($forcedDelete ? '强制删除酒店: ' : '删除酒店: ') . $hotelName,
            $this->currentUser->id ?? null,
            $id,
            null,
            $forcedDelete ? ['references' => $references] : []
        );

        return $this->success([
            'forced' => $forcedDelete,
            'references' => $forcedDelete ? $references : [],
        ], $forcedDelete ? '删除成功，关联历史数据已保留' : '删除成功');
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

    private function ensureHotelCanBeDeleted(int $hotelId): array
    {
        $checks = [
            ['users', 'hotel_id', '用户'],
            ['user_hotel_permissions', 'hotel_id', '用户酒店权限'],
            ['daily_reports', 'hotel_id', '日报'],
            ['monthly_tasks', 'hotel_id', '月任务'],
            ['online_daily_data', 'system_hotel_id', '线上数据'],
            ['operation_logs', 'hotel_id', '操作日志'],
            ['field_mappings', 'hotel_id', '字段映射'],
            ['hotel_field_templates', 'hotel_id', '字段模板'],
            ['room_types', 'hotel_id', '房型'],
            ['devices', 'hotel_id', '设备'],
            ['device_maintenance', 'hotel_id', '设备维护'],
            ['energy_consumption', 'hotel_id', '能耗记录'],
            ['energy_benchmarks', 'hotel_id', '能耗基准'],
            ['energy_saving_suggestions', 'hotel_id', '节能建议'],
            ['maintenance_plans', 'hotel_id', '维护计划'],
            ['price_suggestions', 'hotel_id', '价格建议'],
            ['demand_forecasts', 'hotel_id', '需求预测'],
            ['knowledge_categories', 'hotel_id', '知识分类'],
            ['knowledge_base', 'hotel_id', '知识库'],
            ['transfer_records', 'hotel_id', '转让记录'],
            ['operation_strategy_actions', 'hotel_id', '运营策略动作'],
        ];

        $references = [];
        foreach ($checks as [$table, $column, $label]) {
            $count = $this->countReferenceRows($table, $column, $hotelId);
            if ($count > 0) {
                $references[] = ['table' => $table, 'label' => $label, 'count' => $count];
            }
        }

        return $references;
    }

    protected function shouldBlockHotelDelete(array $references, bool $forceDelete): bool
    {
        return !empty($references) && !$forceDelete;
    }

    protected function isForceDeleteRequested(array $data): bool
    {
        $force = $data['force'] ?? $this->request->param('force', false);
        return $force === true || $force === 1 || $force === '1' || $force === 'true';
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
}
