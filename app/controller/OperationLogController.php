<?php
declare(strict_types=1);

namespace app\controller;

use app\model\OperationLog;
use app\model\User;
use app\model\Hotel;
use app\service\SecurityMonitoringService;
use think\Request;
use think\exception\ValidateException;

class OperationLogController extends Base
{
    private const MAX_PAGE_SIZE = 100;
    private const HIGH_RISK_SUMMARY_LIMIT = 20;
    private const HIGH_RISK_SUMMARY_SCAN_LIMIT = 100;
    private const HIGH_RISK_SUMMARY_TEXT_LIMIT = 180;

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

    private function sanitizeHighRiskSummaryRow(array $row): array
    {
        $user = is_array($row['user'] ?? null) ? $row['user'] : [];
        $hotel = is_array($row['hotel'] ?? null) ? $row['hotel'] : [];

        return [
            'id' => (int)($row['id'] ?? 0),
            'module' => $this->redactOperationLogSummaryText((string)($row['module'] ?? ''), 80),
            'action' => $this->redactOperationLogSummaryText((string)($row['action'] ?? ''), 80),
            'description' => $this->redactOperationLogSummaryText((string)($row['description'] ?? '')),
            'error_info' => $this->redactOperationLogSummaryText((string)($row['error_info'] ?? '')),
            'create_time' => (string)($row['create_time'] ?? ''),
            'audit_type' => $this->redactOperationLogSummaryText((string)($row['audit_type'] ?? 'operation'), 40),
            'risk_priority' => $this->redactOperationLogSummaryText((string)($row['risk_priority'] ?? 'medium'), 20),
            'risk_title' => $this->redactOperationLogSummaryText((string)($row['risk_title'] ?? ''), 80),
            'user' => [
                'id' => isset($user['id']) ? (int)$user['id'] : null,
                'username' => $this->redactOperationLogSummaryText((string)($user['username'] ?? ''), 80),
                'realname' => $this->redactOperationLogSummaryText((string)($user['realname'] ?? ''), 80),
            ],
            'hotel' => [
                'id' => isset($hotel['id']) ? (int)$hotel['id'] : null,
                'name' => $this->redactOperationLogSummaryText((string)($hotel['name'] ?? ''), 120),
            ],
            'user_name' => $this->redactOperationLogSummaryText(
                (string)($row['user_name'] ?? $user['realname'] ?? $user['username'] ?? ''),
                80
            ),
            'hotel_name' => $this->redactOperationLogSummaryText(
                (string)($row['hotel_name'] ?? $hotel['name'] ?? ''),
                120
            ),
            'summary_redacted' => true,
        ];
    }

    private function redactOperationLogSummaryText(string $value, int $limit = self::HIGH_RISK_SUMMARY_TEXT_LIMIT): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $patterns = [
            '/\bAuthorization\s*:\s*Bearer\s+[^\s,;]+/iu' => 'Authorization=****',
            '/\bBearer\s+[A-Za-z0-9._\-]{8,}/u' => 'Bearer ****',
            '/\b(cookie|token|authorization|password|secret|spidertoken|mtgsig|usersign|usertoken|api[_-]?key|access[_-]?key|key)\s*[:=]\s*["\']?[^"\'\s,;]+/iu' => '$1=****',
            '/([?&](?:token|key|api[_-]?key|authorization|spidertoken|mtgsig|usersign|usertoken)=)[^&#\s]+/iu' => '$1****',
            '/sk-[A-Za-z0-9_-]{8,}/u' => 'sk-****',
            '/(1[3-9]\d)\d{4}(\d{4})/u' => '$1****$2',
            '/\b\d{12,}\b/u' => '[编号已隐藏]',
            '/\s+/u' => ' ',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $value = preg_replace($pattern, $replacement, $value) ?? $value;
        }

        return mb_substr($value, 0, max(0, $limit));
    }

    private function requireSuperAdminAccess(): void
    {
        if (!$this->currentUser) {
            abort(401, '未登录');
        }
        if (!$this->currentUser->isSuperAdmin()) {
            abort(403, '仅超级管理员可访问操作日志');
        }
    }

    /**
     * 日志列表
     */
    public function index(Request $request)
    {
        $this->requireSuperAdminAccess();

        $page = (int)$request->param('page', 1);
        $pageSize = min(self::MAX_PAGE_SIZE, max(1, (int)$request->param('page_size', 20)));
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

    public function highRiskSummary(Request $request)
    {
        $this->requireSuperAdminAccess();

        $days = min(30, max(1, (int)$request->param('days', 7)));
        $limit = min(self::HIGH_RISK_SUMMARY_LIMIT, max(1, (int)$request->param('limit', self::HIGH_RISK_SUMMARY_LIMIT)));
        $endDate = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));

        $rows = OperationLog::with(['user', 'hotel'])
            ->whereBetween('create_time', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->order('create_time', 'desc')
            ->limit(self::HIGH_RISK_SUMMARY_SCAN_LIMIT)
            ->select()
            ->toArray();

        $list = [];
        foreach ($rows as $row) {
            $risk = $this->resolveHighRiskAction($row);
            if ($risk === null) {
                continue;
            }
            $row['audit_type'] = $this->resolveAuditType($row);
            $row['risk_priority'] = $risk['priority'];
            $row['risk_title'] = $risk['title'];
            $list[] = $this->sanitizeHighRiskSummaryRow($row);
            if (count($list) >= $limit) {
                break;
            }
        }

        return $this->success([
            'list' => $list,
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'days' => $days,
            ],
            'limit' => $limit,
            'scan_scope' => [
                'type' => 'recent_operation_logs',
                'scan_limit' => self::HIGH_RISK_SUMMARY_SCAN_LIMIT,
                'scanned_count' => count($rows),
                'matched_count' => count($list),
                'note' => 'Only the latest operation logs in the selected period are scanned; this is a risk summary, not a full audit export.',
            ],
        ]);
    }

    /**
     * Read-only security monitoring overview for super administrators.
     */
    public function securityOverview(Request $request)
    {
        $this->requireSuperAdminAccess();

        $days = min(30, max(1, (int)$request->param('days', 30)));
        return $this->success((new SecurityMonitoringService())->overview($days));
    }

    /**
     * 日志详情
     */
    public function detail(Request $request)
    {
        $this->requireSuperAdminAccess();

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
        $this->requireSuperAdminAccess();

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
        if ($auditType === 'security') {
            $query->where('module', 'security');
            return;
        }

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

    private function resolveHighRiskAction(array $log): ?array
    {
        $action = strtolower((string)($log['action'] ?? ''));
        $module = strtolower((string)($log['module'] ?? ''));
        $errorInfo = trim((string)($log['error_info'] ?? ''));
        $isDelete = str_contains($action, 'delete') || str_contains($action, 'clear') || str_contains($action, 'archive');
        $isExecution = str_contains($action, 'auto_fetch')
            || str_contains($action, 'sync')
            || str_contains($action, 'execute')
            || str_contains($action, 'approve')
            || str_contains($action, 'apply');
        $isConfig = str_contains($action, 'config')
            || str_contains($action, 'save_cookies')
            || str_contains($action, 'save_data_source');
        $isAgent = $module === 'agent' || str_contains($action, 'analysis') || str_contains($action, 'analyze');

        if ($errorInfo !== '') {
            return ['priority' => 'high', 'title' => '后台动作出现异常'];
        }
        if ($isDelete) {
            return ['priority' => 'high', 'title' => '后台删除/清理动作'];
        }
        if ($isExecution || $isConfig || $isAgent) {
            return ['priority' => 'medium', 'title' => $isConfig ? '配置变更动作' : ($isAgent ? 'AI/分析动作' : '自动执行动作')];
        }

        return null;
    }

    private function resolveAuditType(array $log): string
    {
        $extraData = $log['extra_data'] ?? null;
        if (is_array($extraData) && in_array(($extraData['audit_type'] ?? ''), ['acquisition', 'analysis', 'security'], true)) {
            return $extraData['audit_type'];
        }

        if (is_string($extraData) && $extraData !== '') {
            $decoded = json_decode($extraData, true);
            if (is_array($decoded) && in_array(($decoded['audit_type'] ?? ''), ['acquisition', 'analysis', 'security'], true)) {
                return $decoded['audit_type'];
            }
        }

        if (strtolower((string)($log['module'] ?? '')) === 'security') {
            return 'security';
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
