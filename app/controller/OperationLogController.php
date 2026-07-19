<?php
declare(strict_types=1);

namespace app\controller;

use app\model\OperationLog;
use app\model\User;
use app\model\Hotel;
use app\service\SecurityMonitoringService;
use app\service\OperationAuditSanitizerService;
use think\Request;
use think\exception\ValidateException;

class OperationLogController extends Base
{
    private const MAX_PAGE_SIZE = 100;
    private const HIGH_RISK_SUMMARY_LIMIT = 20;
    private const HIGH_RISK_SUMMARY_TEXT_LIMIT = 180;

    private const HIGH_RISK_EXACT_ACTIONS = [
        'change_password',
        'reset_password',
        'rotate_token',
        'save_cookies',
        'save_data_source',
        'batch_hotel_assignment',
    ];

    private const HIGH_RISK_ACTION_KEYWORDS = [
        'delete',
        'clear',
        'archive',
        'auto_fetch',
        'sync',
        'execute',
        'approve',
        'apply',
        'config',
        'analysis',
        'analyze',
        'permission',
        'hotel_assignment',
    ];

    private const HIGH_RISK_USER_SCOPE_ACTIONS = [
        'update',
        'batch_status',
        'batch_hotel_assignment',
    ];

    private const HIGH_RISK_DEVICE_ACTIONS = [
        'create',
        'rotate_token',
        'status',
    ];

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
            '/\b(cookie|token|authorization|password|secret|spidertoken|mtgsig|usersign|usertoken|sessionid|jsessionid|sid|api[_-]?key|access[_-]?key|key)\s*[:=]\s*["\']?[^"\'\s,;}&]+/iu' => '$1=****',
            '/([?&](?:token|key|api[_-]?key|authorization|spidertoken|mtgsig|usersign|usertoken|sessionid|jsessionid|sid)=)[^&#\s]+/iu' => '$1****',
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
            $item = $this->sanitizeOperationLogOutputRow($item, false);
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

        $query = OperationLog::with(['user', 'hotel'])
            ->whereBetween('create_time', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
        $this->applyHighRiskCandidateFilter($query);

        $candidateLimit = $limit + 1;
        $rows = $query
            ->order('create_time', 'desc')
            ->order('id', 'desc')
            ->limit($candidateLimit)
            ->select()
            ->toArray();
        $fetchedCandidateCount = count($rows);
        $truncated = $fetchedCandidateCount > $limit;
        if ($truncated) {
            $rows = array_slice($rows, 0, $limit);
        }

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
        }

        return $this->success([
            'list' => $list,
            'truncated' => $truncated,
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'days' => $days,
            ],
            'limit' => $limit,
            'scan_scope' => [
                'type' => 'sql_filtered_high_risk_candidates',
                'candidate_limit' => $candidateLimit,
                'fetched_candidate_count' => $fetchedCandidateCount,
                'returned_count' => count($list),
                'matched_count' => count($list),
                'truncated' => $truncated,
                'complete_within_limit' => !$truncated,
                'note' => 'The database filters high-risk candidates before ordering and limit+1 retrieval. truncated=true means more matching candidates exist than returned; this is not a full audit export.',
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

        $logArr = $this->sanitizeOperationLogOutputRow($log->toArray(), true);
        $logArr['audit_type'] = $this->resolveAuditType($logArr);

        return $this->success($logArr);
    }

    /** @param array<string, mixed> $row */
    private function sanitizeOperationLogOutputRow(array $row, bool $decodeExtraData): array
    {
        $sanitizer = new OperationAuditSanitizerService();
        $user = is_array($row['user'] ?? null) ? $row['user'] : [];
        $hotel = is_array($row['hotel'] ?? null) ? $row['hotel'] : [];
        $row['user'] = $user === [] ? null : [
            'id' => isset($user['id']) ? (int)$user['id'] : null,
            'username' => $this->sanitizeOperationLogOutputText($sanitizer, (string)($user['username'] ?? ''), 80),
            'realname' => $this->sanitizeOperationLogOutputText($sanitizer, (string)($user['realname'] ?? ''), 80),
        ];
        $row['hotel'] = $hotel === [] ? null : [
            'id' => isset($hotel['id']) ? (int)$hotel['id'] : null,
            'name' => $this->sanitizeOperationLogOutputText($sanitizer, (string)($hotel['name'] ?? ''), 120),
        ];
        foreach (['description', 'error_info', 'ip', 'user_agent'] as $field) {
            if (isset($row[$field]) && is_scalar($row[$field])) {
                $row[$field] = $this->sanitizeOperationLogOutputText($sanitizer, (string)$row[$field], 1000);
            }
        }

        $extraData = $row['extra_data'] ?? null;
        if (is_string($extraData) && $extraData !== '') {
            $decoded = json_decode($extraData, true);
            if (is_array($decoded)) {
                $extraData = $this->redactOperationLogSessionValue($sanitizer->sanitizeArray($decoded, 1000), 1000);
            } else {
                $extraData = $this->sanitizeOperationLogOutputText($sanitizer, $extraData, 1000);
            }
        } elseif (is_array($extraData)) {
            $extraData = $this->redactOperationLogSessionValue($sanitizer->sanitizeArray($extraData, 1000), 1000);
        }

        $row['outcome'] = is_array($extraData) && in_array(($extraData['outcome'] ?? ''), ['success', 'failed', 'denied', 'partial'], true)
            ? (string)$extraData['outcome']
            : (trim((string)($row['error_info'] ?? '')) !== '' ? 'failed' : 'success');
        $row['request_id'] = is_array($extraData)
            ? $this->sanitizeOperationLogOutputText($sanitizer, (string)($extraData['request_id'] ?? ''), 80)
            : '';

        if ($decodeExtraData) {
            $row['extra_data'] = $extraData;
        } elseif (is_array($extraData)) {
            $row['extra_data'] = json_encode($extraData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $row['extra_data'] = $extraData;
        }

        return $row;
    }

    private function sanitizeOperationLogOutputText(
        OperationAuditSanitizerService $sanitizer,
        string $value,
        int $limit
    ): string {
        $value = $sanitizer->sanitizeText($value, $limit);
        $patterns = [
            '/\b(sessionid|jsessionid|sid)\s*[:=]\s*["\']?[^"\'\s,;}&]+/iu' => '$1=***',
            '/([?&](?:sessionid|jsessionid|sid)=)[^&#\s]+/iu' => '$1***',
        ];
        foreach ($patterns as $pattern => $replacement) {
            $value = preg_replace($pattern, $replacement, $value) ?? $value;
        }

        return mb_substr($value, 0, max(0, $limit));
    }

    private function redactOperationLogSessionValue(mixed $value, int $stringLimit): mixed
    {
        if (is_array($value)) {
            $safe = [];
            foreach ($value as $key => $item) {
                $normalized = strtolower((string)preg_replace('/[^a-z0-9]+/i', '', (string)$key));
                if (in_array($normalized, ['sessionid', 'jsessionid', 'sid'], true)) {
                    $safe[$key] = '***';
                    continue;
                }
                $safe[$key] = $this->redactOperationLogSessionValue($item, $stringLimit);
            }

            return $safe;
        }
        if (is_string($value)) {
            $patterns = [
                '/\b(sessionid|jsessionid|sid)\s*[:=]\s*["\']?[^"\'\s,;}&]+/iu' => '$1=***',
                '/([?&](?:sessionid|jsessionid|sid)=)[^&#\s]+/iu' => '$1***',
            ];
            foreach ($patterns as $pattern => $replacement) {
                $value = preg_replace($pattern, $replacement, $value) ?? $value;
            }

            return mb_substr($value, 0, max(0, $stringLimit));
        }

        return $value;
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
        $acquisitionActions = $this->sqlStringList(self::DATA_ACQUISITION_ACTIONS);
        $analysisActions = $this->sqlStringList(self::DATA_ANALYSIS_ACTIONS);

        $row = (clone $query)->field(implode(',', [
            'COUNT(*) AS total',
            "SUM(CASE WHEN create_time BETWEEN '{$today} 00:00:00' AND '{$today} 23:59:59' THEN 1 ELSE 0 END) AS today_total",
            'COUNT(DISTINCT user_id) AS active_users',
            'COUNT(DISTINCT hotel_id) AS hotel_count',
            'COUNT(DISTINCT module) AS module_count',
            "SUM(CASE WHEN action IN ({$acquisitionActions}) OR action LIKE 'fetch\_%' THEN 1 ELSE 0 END) AS data_acquisition_count",
            "SUM(CASE WHEN action IN ({$analysisActions}) OR action LIKE '%analysis%' OR action LIKE '%analyze%' OR action LIKE '%simulate%' OR action LIKE '%forecast%' OR action LIKE '%feasibility%' THEN 1 ELSE 0 END) AS analysis_count",
        ]))->find();

        return [
            'total' => (int)($row['total'] ?? 0),
            'today_total' => (int)($row['today_total'] ?? 0),
            'active_users' => (int)($row['active_users'] ?? 0),
            'hotel_count' => (int)($row['hotel_count'] ?? 0),
            'module_count' => (int)($row['module_count'] ?? 0),
            'data_acquisition_count' => (int)($row['data_acquisition_count'] ?? 0),
            'analysis_count' => (int)($row['analysis_count'] ?? 0),
        ];
    }

    /**
     * @param array<int, string> $values
     */
    private function sqlStringList(array $values): string
    {
        return implode(',', array_map(
            static fn(string $value): string => "'" . str_replace("'", "''", $value) . "'",
            $values
        ));
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

    private function applyHighRiskCandidateFilter($query): void
    {
        $query->where(function ($candidate): void {
            $candidate->whereRaw("TRIM(COALESCE(error_info, '')) <> ''")
                ->whereOr('module', 'security')
                ->whereOr('module', 'agent')
                ->whereOr('module', 'role');

            foreach (self::HIGH_RISK_EXACT_ACTIONS as $action) {
                $candidate->whereOr('action', $action);
            }
            foreach (self::HIGH_RISK_ACTION_KEYWORDS as $keyword) {
                $candidate->whereOr('action', 'like', '%' . $keyword . '%');
            }

            $candidate->whereOr(function ($userScope): void {
                $userScope->where('module', 'user')
                    ->whereIn('action', self::HIGH_RISK_USER_SCOPE_ACTIONS);
            });
            $candidate->whereOr(function ($deviceScope): void {
                $deviceScope->where('module', 'competitor_device')
                    ->whereIn('action', self::HIGH_RISK_DEVICE_ACTIONS);
            });
        });
    }

    private function resolveHighRiskAction(array $log): ?array
    {
        $action = strtolower((string)($log['action'] ?? ''));
        $module = strtolower((string)($log['module'] ?? ''));
        $errorInfo = trim((string)($log['error_info'] ?? ''));
        $isSecurity = $module === 'security';
        $isCredentialChange = in_array($action, ['change_password', 'reset_password', 'rotate_token'], true);
        $isPermissionChange = $module === 'role'
            || str_contains($action, 'permission')
            || str_contains($action, 'hotel_assignment')
            || ($module === 'user' && in_array($action, self::HIGH_RISK_USER_SCOPE_ACTIONS, true));
        $isDeviceChange = $module === 'competitor_device'
            && in_array($action, self::HIGH_RISK_DEVICE_ACTIONS, true);
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
        if ($isSecurity) {
            return ['priority' => 'high', 'title' => '安全边界事件'];
        }
        if ($isCredentialChange) {
            return ['priority' => 'high', 'title' => '密码/访问凭据变更'];
        }
        if ($isDelete) {
            return ['priority' => 'high', 'title' => '后台删除/清理动作'];
        }
        if ($isPermissionChange) {
            return ['priority' => 'high', 'title' => '角色权限/门店授权变更'];
        }
        if ($isDeviceChange) {
            return ['priority' => 'high', 'title' => '采集设备绑定/状态变更'];
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
