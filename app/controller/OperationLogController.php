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
    private const DATA_ACQUISITION_ACTIONS = [
        'view_data',
        'auto_fetch',
        'retry_auto_fetch',
        'receive_cookies',
        'save_daily',
        'fetch_ctrip',
        'fetch_meituan',
        'fetch_ctrip_traffic',
        'fetch_meituan_traffic',
        'fetch_ctrip_comments',
        'fetch_meituan_comments',
        'fetch_custom',
    ];

    private const DATA_ANALYSIS_ACTIONS = [
        'analyze_data',
        'ai_analysis',
        'analyze_captured_ota_data',
        'summarize_captured_ota_analysis',
        'feasibility_generate',
        'feasibility_regenerate',
    ];

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
        $auditType = $request->param('audit_type', '');
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
        $this->applyAuditTypeFilter($query, (string)$auditType);
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

        foreach ($list as &$item) {
            $item['audit_type'] = $this->resolveAuditType($item);
        }
        unset($item);

        $summaryQuery = $this->buildSummaryQuery($request);
        $summary = $this->buildSummary($summaryQuery);

        // 获取模块列表(去重)
        $modules = OperationLog::field('module')->group('module')->order('module', 'asc')->column('module');
        
        // 获取操作列表(去重)
        $actions = OperationLog::field('action')->group('action')->order('action', 'asc')->column('action');

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
            'summary' => $summary,
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
        $logArr['audit_type'] = $this->resolveAuditType($logArr);

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

    private function buildSummaryQuery(Request $request)
    {
        $query = OperationLog::where([]);
        $module = $request->param('module', '');
        $action = $request->param('action', '');
        $userId = $request->param('user_id', '');
        $hotelId = $request->param('hotel_id', '');
        $auditType = $request->param('audit_type', '');
        $startDate = $request->param('start_date', '');
        $endDate = $request->param('end_date', '');

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
        $this->applyAuditTypeFilter($query, (string)$auditType);
        if ($startDate) {
            $query->where('create_time', '>=', $startDate . ' 00:00:00');
        }
        if ($endDate) {
            $query->where('create_time', '<=', $endDate . ' 23:59:59');
        }

        return $query;
    }

    private function buildSummary($query): array
    {
        $today = date('Y-m-d');

        return [
            'total' => (int)(clone $query)->count(),
            'today_total' => (int)(clone $query)->whereBetween('create_time', [$today . ' 00:00:00', $today . ' 23:59:59'])->count(),
            'active_users' => $this->countDistinct(clone $query, 'user_id'),
            'hotel_count' => $this->countDistinct(clone $query, 'hotel_id'),
            'module_count' => $this->countDistinct(clone $query, 'module'),
            'data_acquisition_count' => $this->countByAuditType(clone $query, 'acquisition'),
            'analysis_count' => $this->countByAuditType(clone $query, 'analysis'),
        ];
    }

    private function countDistinct($query, string $field): int
    {
        $row = $query->whereNotNull($field)->field("COUNT(DISTINCT {$field}) as total")->find();
        return (int)($row['total'] ?? 0);
    }

    private function countByAuditType($query, string $auditType): int
    {
        $this->applyAuditTypeFilter($query, $auditType);
        return (int)$query->count();
    }

    private function applyAuditTypeFilter($query, string $auditType): void
    {
        if ($auditType === 'acquisition') {
            $query->where(function ($q) {
                $q->whereIn('action', self::DATA_ACQUISITION_ACTIONS)
                    ->whereOr('action', 'like', 'fetch_%');
            });
            return;
        }

        if ($auditType === 'analysis') {
            $query->where(function ($q) {
                $q->whereIn('action', self::DATA_ANALYSIS_ACTIONS)
                    ->whereOr('action', 'like', '%analysis%')
                    ->whereOr('action', 'like', '%analyze%')
                    ->whereOr('action', 'like', '%simulate%')
                    ->whereOr('action', 'like', '%forecast%')
                    ->whereOr('action', 'like', '%feasibility%');
            });
        }
    }

    private function resolveAuditType(array $log): string
    {
        $extraData = $log['extra_data'] ?? null;
        if (is_array($extraData) && in_array(($extraData['audit_type'] ?? ''), ['acquisition', 'analysis'], true)) {
            return $extraData['audit_type'];
        }

        if (is_string($extraData) && $extraData !== '') {
            $decoded = json_decode($extraData, true);
            if (is_array($decoded) && in_array(($decoded['audit_type'] ?? ''), ['acquisition', 'analysis'], true)) {
                return $decoded['audit_type'];
            }
        }

        $action = (string)($log['action'] ?? '');
        if ($this->isAnalysisAction($action)) {
            return 'analysis';
        }
        if ($this->isAcquisitionAction($action)) {
            return 'acquisition';
        }

        return 'operation';
    }

    private function isAnalysisAction(string $action): bool
    {
        if (in_array($action, self::DATA_ANALYSIS_ACTIONS, true)) {
            return true;
        }

        foreach (['analysis', 'analyze', 'simulate', 'forecast', 'feasibility'] as $keyword) {
            if (str_contains($action, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function isAcquisitionAction(string $action): bool
    {
        return in_array($action, self::DATA_ACQUISITION_ACTIONS, true) || str_starts_with($action, 'fetch_');
    }
}
