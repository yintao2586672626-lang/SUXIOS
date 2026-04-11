<?php
declare(strict_types=1);

namespace app\controller;

use app\model\ReportConfig as ReportConfigModel;
use app\model\OperationLog;
use think\exception\ValidateException;
use think\Response;
use think\facade\Db;

class ReportConfig extends Base
{
    /**
     * 报表配置列表
     */
    public function index(): Response
    {
        $this->checkPermission();

        $pagination = $this->getPagination();
        $reportType = $this->request->param('report_type', '');

        $query = ReportConfigModel::order('sort_order', 'asc')->order('id', 'asc');

        if ($reportType) {
            $query->where('report_type', $reportType);
        }

        $total = $query->count();
        $list = $query->page($pagination['page'], $pagination['page_size'])->select();

        return $this->paginate($list, $total, $pagination['page'], $pagination['page_size']);
    }

    /**
     * 获取所有启用的配置（按类型）
     */
    public function all(): Response
    {
        $this->checkPermission();

        $reportType = $this->request->param('report_type', 'daily');
        
        $configs = ReportConfigModel::where('report_type', $reportType)
            ->where('status', ReportConfigModel::STATUS_ENABLED)
            ->order('sort_order', 'asc')
            ->select();

        return $this->success($configs);
    }

    /**
     * 配置详情
     */
    public function read(int $id): Response
    {
        $this->checkPermission();

        $config = ReportConfigModel::find($id);
        if (!$config) {
            return $this->error('配置不存在');
        }

        return $this->success($config);
    }

    /**
     * 创建配置
     */
    public function create(): Response
    {
        $this->checkSuperAdmin();

        $data = $this->request->post();

        $this->validate($data, [
            'report_type' => 'require|in:daily,monthly',
            'field_name' => 'require|alphaDash|max:50',
            'display_name' => 'require|max:100',
            'field_type' => 'require|in:number,text,textarea,select,date',
        ], [
            'report_type.require' => '请选择报表类型',
            'report_type.in' => '报表类型无效',
            'field_name.require' => '请输入字段名',
            'field_name.alphaDash' => '字段名只能包含字母、数字和下划线',
            'display_name.require' => '请输入显示名称',
            'field_type.require' => '请选择字段类型',
        ]);

        // 检查字段名是否已存在
        $exists = ReportConfigModel::where('report_type', $data['report_type'])
            ->where('field_name', $data['field_name'])
            ->find();
        if ($exists) {
            return $this->error('该字段名已存在');
        }

        $config = new ReportConfigModel();
        $config->report_type = $data['report_type'];
        $config->field_name = $data['field_name'];
        $config->display_name = $data['display_name'];
        $config->field_type = $data['field_type'];
        $config->unit = $data['unit'] ?? '';
        $config->options = $data['options'] ?? '';
        $config->sort_order = $data['sort_order'] ?? 0;
        $config->is_required = $data['is_required'] ?? 0;
        $config->status = $data['status'] ?? 1;
        $config->save();

        OperationLog::record('report_config', 'create', '创建报表配置: ' . $config->display_name, $this->currentUser->id);

        return $this->success($config, '创建成功');
    }

    /**
     * 更新配置
     */
    public function update(int $id): Response
    {
        $this->checkSuperAdmin();

        $config = ReportConfigModel::find($id);
        if (!$config) {
            return $this->error('配置不存在');
        }

        $data = $this->request->post();

        // 检查字段名是否与其他配置冲突
        if (isset($data['field_name']) && $data['field_name'] !== $config->field_name) {
            $exists = ReportConfigModel::where('report_type', $config->report_type)
                ->where('field_name', $data['field_name'])
                ->where('id', '<>', $id)
                ->find();
            if ($exists) {
                return $this->error('该字段名已存在');
            }
        }

        $config->field_name = $data['field_name'] ?? $config->field_name;
        $config->display_name = $data['display_name'] ?? $config->display_name;
        $config->field_type = $data['field_type'] ?? $config->field_type;
        $config->unit = $data['unit'] ?? $config->unit;
        $config->options = $data['options'] ?? $config->options;
        $config->sort_order = $data['sort_order'] ?? $config->sort_order;
        $config->is_required = $data['is_required'] ?? $config->is_required;
        $config->status = $data['status'] ?? $config->status;
        $config->save();

        OperationLog::record('report_config', 'update', '更新报表配置: ' . $config->display_name, $this->currentUser->id);

        return $this->success($config, '更新成功');
    }

    /**
     * 删除配置
     */
    public function delete(int $id): Response
    {
        $this->checkSuperAdmin();

        $config = ReportConfigModel::find($id);
        if (!$config) {
            return $this->error('配置不存在');
        }

        $displayName = $config->display_name;
        $config->delete();

        OperationLog::record('report_config', 'delete', '删除报表配置: ' . $displayName, $this->currentUser->id);

        return $this->success(null, '删除成功');
    }

    /**
     * 检查权限
     */
    private function checkPermission(): void
    {
        if (!$this->currentUser) {
            abort(401, '未登录');
        }
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
            abort(403, '无权限操作');
        }
    }
}
