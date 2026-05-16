<?php
declare(strict_types=1);

namespace app\controller;

use app\model\OperationLog;
use app\model\User;
use app\model\Hotel;
use think\Request;
use think\exception\ValidateException;

class OperationLogController extends Base
{
    /**
     * 只有超级管理员可以访问
     */
    protected function initialize()
    {
        parent::initialize();
        if (!$this->currentUser || !$this->currentUser->isSuperAdmin()) {
            throw new ValidateException('无权访问');
        }
    }

    /**
     * 日志列表
     */
    public function index(Request $request)
    {
        $page = (int)$request->param('page', 1);
        $pageSize = (int)$request->param('page_size', 20);
        $module = $request->param('module', '');
        $action = $request->param('action', '');
        $userId = $request->param('user_id', '');
        $hotelId = $request->param('hotel_id', '');
        $startDate = $request->param('start_date', '');
        $endDate = $request->param('end_date', '');

        $query = OperationLog::with(['user', 'hotel']);

        if ($module) {
            $query->where('module', $module);
        }
        if ($action) {
            $query->where('action', $action);
        }
        if ($userId) {
            $query->where('user_id', $userId);
        }
        if ($hotelId) {
            $query->where('hotel_id', $hotelId);
        }
        if ($startDate) {
            $query->where('create_time', '>=', $startDate . ' 00:00:00');
        }
        if ($endDate) {
            $query->where('create_time', '<=', $endDate . ' 23:59:59');
        }

        $total = $query->count();
        $list = $query->order('create_time', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        // 获取模块列表(去重)
        $modules = OperationLog::field('module')->group('module')->column('module');
        
        // 获取操作列表(去重)
        $actions = OperationLog::field('action')->group('action')->column('action');

        // 获取用户列表
        $users = User::field('id, username, realname')->select()->toArray();
        
        // 获取酒店列表
        $hotels = Hotel::field('id, name')->select()->toArray();

        return $this->success([
            'list' => $list,
            'total' => $total,
            'modules' => $modules,
            'actions' => $actions,
            'users' => $users,
            'hotels' => $hotels,
        ]);
    }

    /**
     * 日志详情
     */
    public function detail(Request $request)
    {
        $id = (int)$request->param('id');
        if (!$id) {
            throw new ValidateException('参数错误');
        }

        $log = OperationLog::with(['user', 'hotel'])->find($id);
        if (!$log) {
            throw new ValidateException('日志不存在');
        }

        // 解析extra_data
        $logArr = $log->toArray();
        if ($logArr['extra_data']) {
            $logArr['extra_data'] = json_decode($logArr['extra_data'], true);
        }

        return $this->success($logArr);
    }

    /**
     * 统计概览
     */
    public function stats(Request $request)
    {
        $startDate = $request->param('start_date', date('Y-m-d', strtotime('-7 days')));
        $endDate = $request->param('end_date', date('Y-m-d'));

        // 按模块统计
        $moduleStats = OperationLog::whereBetween('create_time', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->field('module, count(*) as count')
            ->group('module')
            ->select()
            ->toArray();

        // 按操作统计
        $actionStats = OperationLog::whereBetween('create_time', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->field('action, count(*) as count')
            ->group('action')
            ->select()
            ->toArray();

        // 按日期统计
        $dateStats = OperationLog::whereBetween('create_time', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->field('DATE(create_time) as date, count(*) as count')
            ->group('DATE(create_time)')
            ->order('date', 'asc')
            ->select()
            ->toArray();

        // 按用户统计(前10)
        $userStats = OperationLog::alias('log')
            ->leftJoin('users user', 'user.id = log.user_id')
            ->whereBetween('log.create_time', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->field('log.user_id, user.username, user.realname, count(*) as count')
            ->group('log.user_id, user.username, user.realname')
            ->order('count', 'desc')
            ->limit(10)
            ->select()
            ->toArray();

        return $this->success([
            'module_stats' => $moduleStats,
            'action_stats' => $actionStats,
            'date_stats' => $dateStats,
            'user_stats' => $userStats,
        ]);
    }
}
