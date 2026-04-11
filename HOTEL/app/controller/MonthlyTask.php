<?php
declare(strict_types=1);

namespace app\controller;

use app\model\MonthlyTask as MonthlyTaskModel;
use app\model\Hotel;
use app\model\OperationLog;
use think\exception\ValidateException;
use think\Response;
use think\facade\Db;

class MonthlyTask extends Base
{
    /**
     * 获取月任务配置
     */
    public function config(): Response
    {
        $this->checkPermission();
        
        // 获取月任务配置
        $configs = Db::table('report_configs')
            ->where('report_type', 'monthly')
            ->where('status', 1)
            ->order('sort_order', 'asc')
            ->select()
            ->toArray();
        
        return $this->success($configs);
    }

    /**
     * 月任务列表
     */
    public function index(): Response
    {
        $this->checkPermission();

        $pagination = $this->getPagination();
        $hotelId = $this->request->param('hotel_id', '');
        $year = $this->request->param('year', '');
        $month = $this->request->param('month', '');

        $query = MonthlyTaskModel::with(['hotel', 'submitter'])->order('id', 'desc');

        // 根据权限过滤酒店
        if (!$this->currentUser->isSuperAdmin()) {
            $permittedHotelIds = $this->currentUser->getPermittedHotelIds();
            if (empty($permittedHotelIds)) {
                return $this->paginate([], 0, $pagination['page'], $pagination['page_size']);
            }
            if ($hotelId && in_array($hotelId, $permittedHotelIds)) {
                $query->where('hotel_id', $hotelId);
            } else {
                $query->whereIn('hotel_id', $permittedHotelIds);
            }
        } elseif ($hotelId) {
            $query->where('hotel_id', $hotelId);
        }

        if ($year) {
            $query->where('year', $year);
        }
        if ($month) {
            $query->where('month', $month);
        }

        $total = $query->count();
        $list = $query->page($pagination['page'], $pagination['page_size'])->select();

        return $this->paginate($list, $total, $pagination['page'], $pagination['page_size']);
    }

    /**
     * 月任务详情
     */
    public function read(int $id): Response
    {
        $this->checkPermission();

        $task = MonthlyTaskModel::with(['hotel', 'submitter'])->find($id);
        if (!$task) {
            return $this->error('任务不存在');
        }

        // 权限检查
        if (!$this->currentUser->isSuperAdmin() && !$this->currentUser->hasHotelPermission($task->hotel_id, 'can_view_report')) {
            return $this->error('无权查看此任务');
        }

        return $this->success($task);
    }

    /**
     * 创建月任务
     */
    public function create(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fill_monthly_task');

        $data = $this->request->post();

        $this->validate($data, [
            'hotel_id' => 'require|integer',
            'year' => 'require|integer',
            'month' => 'require|between:1,12',
        ], [
            'hotel_id.require' => '请选择酒店',
            'year.require' => '请选择年份',
            'month.require' => '请选择月份',
            'month.between' => '月份必须是1-12',
        ]);

        $hotelId = (int)$data['hotel_id'];

        // 权限检查
        if (!$this->currentUser->isSuperAdmin() && !$this->currentUser->hasHotelPermission($hotelId, 'can_fill_monthly_task')) {
            return $this->error('您没有该酒店的月任务填写权限');
        }

        // 检查是否已存在
        $exists = MonthlyTaskModel::where('hotel_id', $hotelId)
            ->where('year', $data['year'])
            ->where('month', $data['month'])
            ->find();
        if ($exists) {
            return $this->error('该月份的任务已存在，请直接编辑');
        }

        // 提取任务数据
        $taskData = $this->extractTaskData($data);

        $task = new MonthlyTaskModel();
        $task->hotel_id = $hotelId;
        $task->year = $data['year'];
        $task->month = $data['month'];
        $task->task_data = $taskData;
        $task->submitter_id = $this->currentUser->id;
        $task->status = MonthlyTaskModel::STATUS_ENABLED;
        $task->save();

        OperationLog::record('monthly_task', 'create', "创建月任务: {$data['year']}年{$data['month']}月", $this->currentUser->id, $hotelId);

        return $this->success($task, '创建成功');
    }

    /**
     * 更新月任务
     */
    public function update(int $id): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_edit_report');

        $task = MonthlyTaskModel::find($id);
        if (!$task) {
            return $this->error('任务不存在');
        }

        // 权限检查
        if (!$this->currentUser->isSuperAdmin() && !$this->currentUser->hasHotelPermission($task->hotel_id, 'can_edit_report')) {
            return $this->error('无权编辑此任务');
        }

        $data = $this->request->post();

        // 提取任务数据
        $taskData = $this->extractTaskData($data);
        
        // 合并原有数据
        $existingData = $task->task_data ?? [];
        $task->task_data = array_merge($existingData, $taskData);
        $task->save();

        OperationLog::record('monthly_task', 'update', "更新月任务: {$task->year}年{$task->month}月", $this->currentUser->id, $task->hotel_id);

        return $this->success($task, '更新成功');
    }

    /**
     * 删除月任务
     */
    public function delete(int $id): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_delete_report');

        $task = MonthlyTaskModel::find($id);
        if (!$task) {
            return $this->error('任务不存在');
        }

        // 权限检查
        if (!$this->currentUser->isSuperAdmin() && !$this->currentUser->hasHotelPermission($task->hotel_id, 'can_delete_report')) {
            return $this->error('无权删除此任务');
        }

        // 检查是否有关联的日报表
        $dailyReportCount = \app\model\DailyReport::where('hotel_id', $task->hotel_id)
            ->whereYear('report_date', $task->year)
            ->whereMonth('report_date', $task->month)
            ->count();
        
        if ($dailyReportCount > 0) {
            return $this->error("该月份已有 {$dailyReportCount} 条日报记录，无法删除");
        }

        $year = $task->year;
        $month = $task->month;
        $hotelId = $task->hotel_id;
        $task->delete();

        OperationLog::record('monthly_task', 'delete', "删除月任务: {$year}年{$month}月", $this->currentUser->id, $hotelId);

        return $this->success(null, '删除成功');
    }

    /**
     * 提取任务数据（只保留配置中定义的字段）
     */
    private function extractTaskData(array $data): array
    {
        // 获取所有配置的字段名
        $fieldNames = Db::table('report_configs')
            ->where('report_type', 'monthly')
            ->where('status', 1)
            ->column('field_name');
        
        $taskData = [];
        foreach ($fieldNames as $field) {
            if (isset($data[$field])) {
                // 转换为数值类型
                $value = $data[$field];
                if (is_string($value)) {
                    $value = str_replace(',', '', $value);
                    $value = is_numeric($value) ? floatval($value) : $value;
                }
                $taskData[$field] = $value;
            }
        }
        
        return $taskData;
    }

    /**
     * 检查权限
     */
    private function checkPermission(): void
    {
        if (!$this->currentUser) {
            abort(401, '未登录');
        }
        // 非超级管理员必须有酒店关联
        $this->requireHotel();
    }

    /**
     * 检查操作权限
     */
    private function checkActionPermission(string $permission): void
    {
        if ($this->currentUser->isSuperAdmin()) {
            return;
        }
        
        if (!$this->currentUser->hasPermission($permission)) {
            abort(403, '无权限操作');
        }
    }
}
