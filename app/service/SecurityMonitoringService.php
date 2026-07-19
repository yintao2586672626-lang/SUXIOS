<?php
declare(strict_types=1);

namespace app\service;

use app\model\Role;
use think\facade\Db;

/**
 * Builds a read-only, evidence-based security overview for super administrators.
 *
 * The service deliberately reports suspicious signals instead of declaring that
 * a user is malicious. It never reads or returns passwords, tokens or cookies.
 */
class SecurityMonitoringService
{
    private const MAX_DAYS = 30;
    private const MAX_LOGIN_ROWS = 10000;
    private const MAX_OPERATION_ROWS = 10000;
    private const MAX_LATEST_EVENTS = 50;
    private const MAX_RISK_USERS = 50;

    public function overview(int $days = 30): array
    {
        $days = min(self::MAX_DAYS, max(1, $days));
        $endAt = date('Y-m-d 23:59:59');
        $startAt = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));
        $sourceErrors = [];

        try {
            $users = Db::name('users')
                ->alias('u')
                ->leftJoin('roles r', 'r.id = u.role_id')
                ->field('u.id,u.username,u.realname,u.role_id,u.status,u.last_login_time,u.login_count,r.name AS role_name,r.display_name AS role_display_name,r.status AS role_status,r.level AS role_level,r.permissions AS role_permissions')
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            $users = [];
            $sourceErrors[] = 'user_directory_unavailable';
        }

        try {
            $loginRows = Db::name('login_logs')
                ->whereBetween('created_at', [$startAt, $endAt])
                ->field('id,user_id,username,action,status,message,ip_address,user_agent,created_at')
                ->order('created_at', 'desc')
                ->limit(self::MAX_LOGIN_ROWS + 1)
                ->select()
                ->toArray();
            if (count($loginRows) > self::MAX_LOGIN_ROWS) {
                $loginRows = array_slice($loginRows, 0, self::MAX_LOGIN_ROWS);
                $sourceErrors[] = 'login_logs_truncated';
            }
        } catch (\Throwable $e) {
            $loginRows = [];
            $sourceErrors[] = 'login_logs_unavailable';
        }

        try {
            $operationRows = Db::name('operation_logs')
                ->whereBetween('create_time', [$startAt, $endAt])
                ->where(function ($query): void {
                    $query->where('module', 'security')
                        ->whereOr('module', 'role')
                        ->whereOr('module', 'competitor_device')
                        ->whereOr('error_info', '<>', '')
                        ->whereOr('action', 'like', '%delete%')
                        ->whereOr('action', 'like', '%clear%')
                        ->whereOr('action', 'like', '%archive%')
                        ->whereOr('action', 'like', '%export%')
                        ->whereOr('action', 'like', '%config%')
                        ->whereOr('action', 'change_password')
                        ->whereOr('action', 'reset_password')
                        ->whereOr('action', 'batch_hotel_assignment')
                        ->whereOr('action', 'rotate_token')
                        ->whereOr('action', 'save_cookies')
                        ->whereOr('action', 'save_data_source');
                })
                ->field('id,user_id,hotel_id,module,action,description,ip,user_agent,create_time,error_info,extra_data')
                ->order('create_time', 'desc')
                ->limit(self::MAX_OPERATION_ROWS + 1)
                ->select()
                ->toArray();
            if (count($operationRows) > self::MAX_OPERATION_ROWS) {
                $operationRows = array_slice($operationRows, 0, self::MAX_OPERATION_ROWS);
                $sourceErrors[] = 'operation_logs_truncated';
            }
        } catch (\Throwable $e) {
            $operationRows = [];
            $sourceErrors[] = 'operation_logs_unavailable';
        }

        try {
            $activityRows = Db::name('operation_logs')
                ->whereBetween('create_time', [$startAt, $endAt])
                ->field('user_id,COUNT(*) AS operation_count,COUNT(DISTINCT action) AS action_types,MAX(create_time) AS last_operation_at')
                ->group('user_id')
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            $activityRows = [];
            $sourceErrors[] = 'operation_activity_unavailable';
        }

        $accountActivityEvidence = [
            'complete' => false,
            'window_rows' => [],
            'historical_rows' => [],
            'source' => 'grouped_login_logs',
        ];
        try {
            $accountActivityEvidence = $this->loadAccountLoginActivityEvidence($startAt, $endAt);
        } catch (\Throwable $e) {
            $sourceErrors[] = 'account_login_activity_unavailable';
        }

        return $this->buildOverviewFromRows(
            $loginRows,
            $operationRows,
            $activityRows,
            $users,
            [
                'days' => $days,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'source_errors' => $sourceErrors,
            ],
            $accountActivityEvidence
        );
    }

    /**
     * Account activity uses complete grouped login-only evidence and is never
     * derived from the capped risk-event sample above.
     *
     * @return array<string, mixed>
     */
    private function loadAccountLoginActivityEvidence(string $startAt, string $endAt): array
    {
        $windowRows = Db::name('login_logs')
            ->where('action', 'login')
            ->whereBetween('created_at', [$startAt, $endAt])
            ->fieldRaw(
                "user_id,username,COUNT(*) AS login_attempts,"
                . "SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS successful_logins,"
                . "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_logins,"
                . "MAX(created_at) AS last_attempt_at,"
                . "MAX(CASE WHEN status = 'success' THEN created_at ELSE NULL END) AS window_last_success_at"
            )
            ->group('user_id,username')
            ->select()
            ->toArray();

        $historicalRows = Db::name('login_logs')
            ->where('action', 'login')
            ->where('status', 'success')
            ->fieldRaw('user_id,username,COUNT(*) AS historical_successful_logins,MAX(created_at) AS historical_last_success_at')
            ->group('user_id,username')
            ->select()
            ->toArray();

        return [
            'complete' => true,
            'window_rows' => $windowRows,
            'historical_rows' => $historicalRows,
            'source' => 'grouped_login_logs',
        ];
    }

    /**
     * Pure aggregation entry point used by focused tests.
     *
     * @param array<int, array<string, mixed>> $loginRows
     * @param array<int, array<string, mixed>> $operationRows
     * @param array<int, array<string, mixed>> $activityRows
     * @param array<int, array<string, mixed>> $users
     * @param array<string, mixed> $coverage
     * @param array<string, mixed> $accountActivityEvidence
     */
    public function buildOverviewFromRows(
        array $loginRows,
        array $operationRows,
        array $activityRows,
        array $users,
        array $coverage = [],
        array $accountActivityEvidence = []
    ): array {
        $userDirectory = $this->buildUserDirectory($users);
        $userStats = [];
        $events = [];
        $ipObservations = [];

        foreach ($activityRows as $row) {
            $userId = (int)($row['user_id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }
            $identity = $this->identityFor($userId, '', $userDirectory);
            $key = $identity['key'];
            $stats = &$this->ensureUserStats($userStats, $key, $identity);
            $stats['operation_count'] = (int)($row['operation_count'] ?? 0);
            $stats['action_types'] = (int)($row['action_types'] ?? 0);
            $stats['last_operation_at'] = (string)($row['last_operation_at'] ?? '');
            unset($stats);
        }

        foreach ($loginRows as $row) {
            $userId = (int)($row['user_id'] ?? 0);
            $username = trim((string)($row['username'] ?? ''));
            $identity = $this->identityFor($userId, $username, $userDirectory);
            $key = $identity['key'];
            $stats = &$this->ensureUserStats($userStats, $key, $identity);
            $status = strtolower((string)($row['status'] ?? ''));
            $action = strtolower((string)($row['action'] ?? ''));
            $createdAt = (string)($row['created_at'] ?? '');
            $ip = $this->normalizeIp((string)($row['ip_address'] ?? ''));
            $clientType = $this->clientType((string)($row['user_agent'] ?? ''));

            if ($action === 'login') {
                $stats['login_count']++;
                if ($status === 'success') {
                    $stats['successful_logins']++;
                    $stats['last_successful_login_at'] = $this->latestTime($stats['last_successful_login_at'], $createdAt);
                } elseif ($status === 'failed') {
                    $stats['failed_logins']++;
                    $events[] = [
                        'source' => 'login',
                        'source_id' => (int)($row['id'] ?? 0),
                        'risk_level' => 'medium',
                        'category' => 'failed_login',
                        'title' => '登录失败',
                        'reason' => $this->safeText((string)($row['message'] ?? '登录校验未通过')),
                        'user_id' => $identity['user_id'],
                        'username' => $identity['username'],
                        'realname' => $identity['realname'],
                        'hotel_id' => null,
                        'ip' => $ip,
                        'client_type' => $clientType,
                        'occurred_at' => $createdAt,
                    ];
                }

                if (in_array($clientType, ['HeadlessChrome', 'Playwright', 'curl', 'PowerShell', 'node'], true)) {
                    $stats['automated_logins']++;
                }
                $stats['last_login_at'] = $this->latestTime($stats['last_login_at'], $createdAt);
            }

            if ($ip !== '') {
                $stats['ips'][$ip] = true;
                $ipObservations[] = $ip;
            }
            unset($stats);
        }

        foreach ($operationRows as $row) {
            $userId = (int)($row['user_id'] ?? 0);
            $identity = $this->identityFor($userId, '', $userDirectory);
            $key = $identity['key'];
            $stats = &$this->ensureUserStats($userStats, $key, $identity);
            $action = strtolower((string)($row['action'] ?? ''));
            $module = strtolower((string)($row['module'] ?? ''));
            $errorInfo = trim((string)($row['error_info'] ?? ''));
            $createdAt = (string)($row['create_time'] ?? '');
            $ip = $this->normalizeIp((string)($row['ip'] ?? ''));
            $extra = $this->decodeExtraData($row['extra_data'] ?? null);
            $path = $this->safeText((string)($extra['path'] ?? ''), 160);
            $event = null;

            if ($module === 'security' && $action === 'rate_limited') {
                $stats['rate_limited_count']++;
                $event = $this->operationEvent($row, $identity, 'high', 'rate_limited', '高频请求被系统限流', $path ?: '请求频率超过当前接口阈值');
            } elseif ($module === 'security' && $action === 'protected_access_denied') {
                $stats['access_denied_count']++;
                $event = $this->operationEvent($row, $identity, 'medium', 'protected_access_denied', '受保护接口访问被拒绝', $path ?: '账号缺少所需权限或酒店范围不匹配');
            } elseif ($this->isAccountSecurityAction($module, $action)) {
                $event = $this->operationEvent(
                    $row,
                    $identity,
                    'high',
                    'account_security_change',
                    '账号凭据/角色权限/门店授权发生变更',
                    (string)($row['description'] ?? '')
                );
            } elseif ($this->isDestructiveAction($action)) {
                $stats['destructive_count']++;
                if ($errorInfo === '') {
                    $stats['successful_destructive_count']++;
                }
                $level = $errorInfo === '' && !$identity['is_super_admin'] ? 'critical' : 'high';
                $event = $this->operationEvent(
                    $row,
                    $identity,
                    $level,
                    'destructive_operation',
                    $errorInfo === '' ? '删除/清理动作已执行' : '删除/清理动作执行失败',
                    (string)($row['description'] ?? '')
                );
            } elseif ($this->isConfigAction($action)) {
                $stats['config_change_count']++;
                $event = $this->operationEvent($row, $identity, 'medium', 'config_change', '配置变更动作', (string)($row['description'] ?? ''));
            } elseif (str_contains($action, 'export')) {
                $stats['export_count']++;
                $event = $this->operationEvent($row, $identity, 'medium', 'data_export', '数据导出动作', (string)($row['description'] ?? ''));
            } elseif ($errorInfo !== '') {
                $stats['failed_operation_count']++;
                $event = $this->operationEvent($row, $identity, 'medium', 'operation_error', '后台动作出现异常', $errorInfo);
            }

            if ($event !== null) {
                $events[] = $event;
            }
            if ($ip !== '') {
                $stats['ips'][$ip] = true;
                $ipObservations[] = $ip;
            }
            $stats['last_risk_at'] = $this->latestTime($stats['last_risk_at'], $createdAt);
            unset($stats);
        }

        $riskUsers = [];
        $loginActivity = [];
        foreach ($userStats as $stats) {
            $stats['distinct_ips'] = count($stats['ips']);
            unset($stats['ips']);
            [$score, $level, $signals] = $this->scoreUser($stats);
            $stats['risk_score'] = $score;
            $stats['risk_level'] = $level;
            $stats['signals'] = $signals;
            $stats['last_event_at'] = $this->latestTime($stats['last_risk_at'], $stats['last_login_at'], $stats['last_operation_at']);

            if ($signals !== []) {
                $riskUsers[] = $stats;
            }
            if ($stats['login_count'] > 0) {
                $loginActivity[] = [
                    'user_id' => $stats['user_id'],
                    'username' => $stats['username'],
                    'realname' => $stats['realname'],
                    'role_name' => $stats['role_name'],
                    'login_count' => $stats['login_count'],
                    'successful_logins' => $stats['successful_logins'],
                    'failed_logins' => $stats['failed_logins'],
                    'automated_logins' => $stats['automated_logins'],
                    'distinct_ips' => $stats['distinct_ips'],
                    'last_login_at' => $stats['last_login_at'],
                ];
            }
        }

        $riskOrder = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
        usort($riskUsers, static function (array $left, array $right) use ($riskOrder): int {
            $levelCompare = ($riskOrder[$right['risk_level']] ?? 0) <=> ($riskOrder[$left['risk_level']] ?? 0);
            return $levelCompare !== 0 ? $levelCompare : (($right['risk_score'] ?? 0) <=> ($left['risk_score'] ?? 0));
        });
        usort($loginActivity, static fn(array $left, array $right): int => ($right['login_count'] ?? 0) <=> ($left['login_count'] ?? 0));
        usort($events, static fn(array $left, array $right): int => strcmp((string)($right['occurred_at'] ?? ''), (string)($left['occurred_at'] ?? '')));
        $accountActivity = $this->buildAccountActivity($userDirectory, $accountActivityEvidence);

        $failedLoginTotal = $this->allFailedLoginCount($userStats, $accountActivityEvidence);
        $summary = [
            'critical_users' => count(array_filter($riskUsers, static fn(array $row): bool => $row['risk_level'] === 'critical')),
            'high_users' => count(array_filter($riskUsers, static fn(array $row): bool => $row['risk_level'] === 'high')),
            'needs_review_users' => count($riskUsers),
            'rate_limited_events' => array_sum(array_column($riskUsers, 'rate_limited_count')),
            'access_denied_events' => array_sum(array_column($riskUsers, 'access_denied_count')),
            'destructive_events' => array_sum(array_column($riskUsers, 'destructive_count')),
            'successful_destructive_events' => array_sum(array_column($riskUsers, 'successful_destructive_count')),
            'failed_logins' => $failedLoginTotal,
            'automated_logins' => array_sum(array_column($riskUsers, 'automated_logins')),
        ];

        return [
            'summary' => $summary,
            'account_activity' => $accountActivity,
            'risk_users' => array_slice($riskUsers, 0, self::MAX_RISK_USERS),
            'login_activity' => array_slice($loginActivity, 0, 30),
            'latest_events' => array_slice($events, 0, self::MAX_LATEST_EVENTS),
            'ip_evidence' => $this->buildIpEvidence($ipObservations),
            'coverage' => array_merge([
                'days' => 30,
                'start_at' => '',
                'end_at' => '',
                'login_rows_scanned' => count($loginRows),
                'operation_rows_scanned' => count($operationRows),
                'activity_users_scanned' => count($activityRows),
                'source_errors' => [],
                'complete' => true,
                'note' => '风险结论来自登录日志、受保护访问拒绝、限流与操作日志；只表示需要核查，不自动认定主观恶意。',
            ], $coverage, [
                'login_rows_scanned' => count($loginRows),
                'operation_rows_scanned' => count($operationRows),
                'activity_users_scanned' => count($activityRows),
                'account_activity_complete' => !empty($accountActivityEvidence['complete']),
                'complete' => empty($coverage['source_errors'] ?? []) && !empty($accountActivityEvidence['complete']),
            ]),
        ];
    }

    /**
     * Summary counts every failed login in the selected window, including
     * accounts below the risk threshold and identities not in the user table.
     *
     * @param array<int|string, array<string, mixed>> $userStats
     * @param array<string, mixed> $accountActivityEvidence
     */
    private function allFailedLoginCount(array $userStats, array $accountActivityEvidence): int
    {
        if (($accountActivityEvidence['complete'] ?? false) === true
            && is_array($accountActivityEvidence['window_rows'] ?? null)
        ) {
            return array_sum(array_map(
                static fn(array $row): int => max(0, (int)($row['failed_logins'] ?? 0)),
                array_values(array_filter($accountActivityEvidence['window_rows'], 'is_array'))
            ));
        }

        return array_sum(array_map(
            static fn(array $stats): int => max(0, (int)($stats['failed_logins'] ?? 0)),
            array_values(array_filter($userStats, 'is_array'))
        ));
    }

    /**
     * Active means at least one successful login in the selected window.
     * The list intentionally excludes super administrators and never exposes IPs.
     *
     * @param array<int, array<string, mixed>> $userDirectory
     * @param array<string, mixed> $evidence
     * @return array<string, mixed>
     */
    private function buildAccountActivity(array $userDirectory, array $evidence): array
    {
        $eligibleAccounts = array_values(array_filter(
            $userDirectory,
            static fn(array $identity): bool => empty($identity['is_super_admin'])
                && (int)($identity['status'] ?? 0) === 1
        ));
        $definition = [
            'active' => '所选时间范围内至少一次成功登录',
            'inactive' => '已启用的非超级管理员账号在所选时间范围内没有成功登录',
            'never_logged_in' => '账号全历史成功登录次数为0',
            'visibility' => '仅超级管理员可见；本名单不返回IP地址',
            'source' => '仅聚合action=login的完整分组查询，不依赖风险日志采样上限',
        ];

        if (empty($evidence['complete'])) {
            return [
                'complete' => false,
                'summary' => [
                    'enabled_accounts' => count($eligibleAccounts),
                    'active_accounts' => null,
                    'inactive_accounts' => null,
                    'never_logged_in_accounts' => null,
                ],
                'active_accounts' => [],
                'inactive_accounts' => [],
                'definition' => $definition,
                'error' => '账号登录聚合不可用，未生成活跃/未活跃结论',
            ];
        }

        $aggregates = $this->mergeAccountLoginEvidence(
            $userDirectory,
            is_array($evidence['window_rows'] ?? null) ? $evidence['window_rows'] : [],
            is_array($evidence['historical_rows'] ?? null) ? $evidence['historical_rows'] : []
        );
        $activeAccounts = [];
        $inactiveAccounts = [];

        foreach ($eligibleAccounts as $identity) {
            $aggregate = $aggregates[(int)$identity['user_id']] ?? [];
            $successfulLogins = (int)($aggregate['successful_logins'] ?? 0);
            $historicalSuccessfulLogins = max(
                $successfulLogins,
                (int)($aggregate['historical_successful_logins'] ?? 0),
                (int)($identity['legacy_successful_login_count'] ?? 0)
            );
            $lastSuccessfulLoginAt = $this->latestTime(
                (string)($aggregate['window_last_success_at'] ?? ''),
                (string)($aggregate['historical_last_success_at'] ?? ''),
                (string)($identity['legacy_last_success_at'] ?? '')
            );
            $neverLoggedIn = $historicalSuccessfulLogins === 0;

            $row = [
                'user_id' => (int)$identity['user_id'],
                'username' => (string)$identity['username'],
                'realname' => (string)$identity['realname'],
                'role_name' => (string)$identity['role_name'],
                'activity_status' => $successfulLogins > 0 ? 'active' : 'inactive',
                'successful_logins' => $successfulLogins,
                'failed_logins' => (int)($aggregate['failed_logins'] ?? 0),
                'login_attempts' => (int)($aggregate['login_attempts'] ?? 0),
                'last_successful_login_at' => $lastSuccessfulLoginAt,
                'last_attempt_at' => (string)($aggregate['last_attempt_at'] ?? ''),
                'never_logged_in' => $neverLoggedIn,
            ];

            if ($successfulLogins > 0) {
                $activeAccounts[] = $row;
            } else {
                $inactiveAccounts[] = $row;
            }
        }

        usort($activeAccounts, static function (array $left, array $right): int {
            $countCompare = ($right['successful_logins'] ?? 0) <=> ($left['successful_logins'] ?? 0);
            return $countCompare !== 0
                ? $countCompare
                : strcmp((string)($right['last_successful_login_at'] ?? ''), (string)($left['last_successful_login_at'] ?? ''));
        });
        usort($inactiveAccounts, static function (array $left, array $right): int {
            $neverCompare = (int)($right['never_logged_in'] ?? false) <=> (int)($left['never_logged_in'] ?? false);
            return $neverCompare !== 0
                ? $neverCompare
                : strcmp((string)($left['last_successful_login_at'] ?? ''), (string)($right['last_successful_login_at'] ?? ''));
        });

        return [
            'complete' => true,
            'summary' => [
                'enabled_accounts' => count($activeAccounts) + count($inactiveAccounts),
                'active_accounts' => count($activeAccounts),
                'inactive_accounts' => count($inactiveAccounts),
                'never_logged_in_accounts' => count(array_filter(
                    $inactiveAccounts,
                    static fn(array $row): bool => !empty($row['never_logged_in'])
                )),
            ],
            'active_accounts' => array_slice($activeAccounts, 0, self::MAX_RISK_USERS),
            'inactive_accounts' => array_slice($inactiveAccounts, 0, self::MAX_RISK_USERS),
            'definition' => $definition,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $userDirectory
     * @param array<int, array<string, mixed>> $windowRows
     * @param array<int, array<string, mixed>> $historicalRows
     * @return array<int, array<string, mixed>>
     */
    private function mergeAccountLoginEvidence(array $userDirectory, array $windowRows, array $historicalRows): array
    {
        $usernameCandidates = [];
        foreach ($userDirectory as $identity) {
            $username = mb_strtolower(trim((string)($identity['username'] ?? '')));
            if ($username !== '') {
                $usernameCandidates[$username][] = (int)$identity['user_id'];
            }
        }
        $usernameMap = [];
        foreach ($usernameCandidates as $username => $ids) {
            $ids = array_values(array_unique($ids));
            if (count($ids) === 1) {
                $usernameMap[$username] = $ids[0];
            }
        }

        $aggregates = [];
        foreach ($windowRows as $row) {
            $userId = $this->resolveAggregateUserId($row, $userDirectory, $usernameMap);
            if ($userId === null) {
                continue;
            }
            $aggregates[$userId] ??= [
                'login_attempts' => 0,
                'successful_logins' => 0,
                'failed_logins' => 0,
                'last_attempt_at' => '',
                'window_last_success_at' => '',
                'historical_successful_logins' => 0,
                'historical_last_success_at' => '',
            ];
            $aggregates[$userId]['login_attempts'] += (int)($row['login_attempts'] ?? 0);
            $aggregates[$userId]['successful_logins'] += (int)($row['successful_logins'] ?? 0);
            $aggregates[$userId]['failed_logins'] += (int)($row['failed_logins'] ?? 0);
            $aggregates[$userId]['last_attempt_at'] = $this->latestTime(
                $aggregates[$userId]['last_attempt_at'],
                (string)($row['last_attempt_at'] ?? '')
            );
            $aggregates[$userId]['window_last_success_at'] = $this->latestTime(
                $aggregates[$userId]['window_last_success_at'],
                (string)($row['window_last_success_at'] ?? '')
            );
        }

        foreach ($historicalRows as $row) {
            $userId = $this->resolveAggregateUserId($row, $userDirectory, $usernameMap);
            if ($userId === null) {
                continue;
            }
            $aggregates[$userId] ??= [
                'login_attempts' => 0,
                'successful_logins' => 0,
                'failed_logins' => 0,
                'last_attempt_at' => '',
                'window_last_success_at' => '',
                'historical_successful_logins' => 0,
                'historical_last_success_at' => '',
            ];
            $aggregates[$userId]['historical_successful_logins'] += (int)($row['historical_successful_logins'] ?? 0);
            $aggregates[$userId]['historical_last_success_at'] = $this->latestTime(
                $aggregates[$userId]['historical_last_success_at'],
                (string)($row['historical_last_success_at'] ?? '')
            );
        }

        return $aggregates;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $userDirectory
     * @param array<string, int> $usernameMap
     */
    private function resolveAggregateUserId(array $row, array $userDirectory, array $usernameMap): ?int
    {
        $userId = (int)($row['user_id'] ?? 0);
        if ($userId > 0 && isset($userDirectory[$userId])) {
            return $userId;
        }

        $username = mb_strtolower(trim((string)($row['username'] ?? '')));
        return $username !== '' && isset($usernameMap[$username]) ? $usernameMap[$username] : null;
    }

    /**
     * @param array<int, array<string, mixed>> $users
     * @return array<int, array<string, mixed>>
     */
    private function buildUserDirectory(array $users): array
    {
        $directory = [];
        foreach ($users as $user) {
            $id = (int)($user['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $directory[$id] = [
                'user_id' => $id,
                'username' => trim((string)($user['username'] ?? '')),
                'realname' => trim((string)($user['realname'] ?? '')),
                'role_id' => (int)($user['role_id'] ?? 0),
                'role_name' => trim((string)($user['role_display_name'] ?? $user['role_name'] ?? '')),
                'status' => (int)($user['status'] ?? 0),
                'legacy_last_success_at' => trim((string)($user['last_login_time'] ?? '')),
                'legacy_successful_login_count' => (int)($user['login_count'] ?? 0),
                'is_super_admin' => $this->matchesSuperAdminIdentity($user),
            ];
        }

        return $directory;
    }

    /** @param array<int, array<string, mixed>> $userDirectory */
    private function identityFor(int $userId, string $username, array $userDirectory): array
    {
        if ($userId > 0 && isset($userDirectory[$userId])) {
            $identity = $userDirectory[$userId];
            $identity['key'] = 'id:' . $userId;
            return $identity;
        }

        $username = $username !== '' ? $this->safeText($username, 80) : '[未知账号]';
        return [
            'key' => $userId > 0 ? 'id:' . $userId : 'name:' . mb_strtolower($username),
            'user_id' => $userId > 0 ? $userId : null,
            'username' => $username,
            'realname' => '',
            'role_id' => 0,
            'role_name' => $userId > 0 ? '账号已删除或不可用' : '未识别账号',
            'status' => 0,
            'legacy_last_success_at' => '',
            'legacy_successful_login_count' => 0,
            'is_super_admin' => false,
        ];
    }

    /** @param array<string, mixed> $user */
    private function matchesSuperAdminIdentity(array $user): bool
    {
        $roleId = (int)($user['role_id'] ?? 0);
        if ($roleId === Role::SUPER_ADMIN) {
            return true;
        }
        if ((int)($user['role_status'] ?? 0) !== Role::STATUS_ENABLED) {
            return false;
        }

        $permissions = Role::normalizePermissions($user['role_permissions'] ?? null);
        if (!Role::permissionListAllows($permissions, 'all')) {
            return false;
        }
        if (in_array($roleId, [Role::BETA_USER, Role::NORMAL_USER], true)) {
            return false;
        }

        return (string)($user['role_name'] ?? '') === 'admin'
            || (int)($user['role_level'] ?? 0) === Role::SUPER_ADMIN;
    }

    /**
     * @param array<string, array<string, mixed>> $userStats
     * @param array<string, mixed> $identity
     * @return array<string, mixed>
     */
    private function &ensureUserStats(array &$userStats, string $key, array $identity): array
    {
        if (!isset($userStats[$key])) {
            $userStats[$key] = [
                'user_id' => $identity['user_id'],
                'username' => $identity['username'],
                'realname' => $identity['realname'],
                'role_id' => $identity['role_id'],
                'role_name' => $identity['role_name'],
                'is_super_admin' => $identity['is_super_admin'],
                'login_count' => 0,
                'successful_logins' => 0,
                'failed_logins' => 0,
                'automated_logins' => 0,
                'rate_limited_count' => 0,
                'access_denied_count' => 0,
                'destructive_count' => 0,
                'successful_destructive_count' => 0,
                'config_change_count' => 0,
                'export_count' => 0,
                'failed_operation_count' => 0,
                'operation_count' => 0,
                'action_types' => 0,
                'ips' => [],
                'last_login_at' => '',
                'last_successful_login_at' => '',
                'last_operation_at' => '',
                'last_risk_at' => '',
            ];
        }

        return $userStats[$key];
    }

    /** @param array<string, mixed> $stats */
    private function scoreUser(array $stats): array
    {
        $score = 0;
        $signals = [];
        $critical = false;

        if ($stats['successful_destructive_count'] > 0 && !$stats['is_super_admin']) {
            $critical = true;
            $score += 100;
            $signals[] = [
                'code' => 'non_admin_destructive_success',
                'label' => '非管理员账号执行了删除/清理动作',
                'count' => $stats['successful_destructive_count'],
                'severity' => 'critical',
            ];
        } elseif ($stats['destructive_count'] > 0) {
            $score += min(40, $stats['destructive_count'] * 10);
            $signals[] = [
                'code' => 'destructive_operation',
                'label' => '存在删除/清理操作',
                'count' => $stats['destructive_count'],
                'severity' => 'high',
            ];
        }

        if ($stats['rate_limited_count'] > 0) {
            $score += min(60, 20 + intdiv($stats['rate_limited_count'], 10));
            $signals[] = [
                'code' => 'rate_limited',
                'label' => '高频请求被限流，可能是自动脚本失控或疑似爬取',
                'count' => $stats['rate_limited_count'],
                'severity' => 'high',
            ];
        }

        if ($stats['access_denied_count'] > 0) {
            $score += min(40, 10 + intdiv($stats['access_denied_count'], 5) * 5);
            $signals[] = [
                'code' => 'protected_access_denied',
                'label' => '反复访问无权限或跨范围接口',
                'count' => $stats['access_denied_count'],
                'severity' => $stats['access_denied_count'] >= 20 ? 'high' : 'medium',
            ];
        }

        if ($stats['failed_logins'] >= 3) {
            $score += min(30, $stats['failed_logins'] * 2);
            $signals[] = [
                'code' => 'failed_login',
                'label' => '重复登录失败',
                'count' => $stats['failed_logins'],
                'severity' => $stats['failed_logins'] >= 10 ? 'high' : 'medium',
            ];
        }

        if ($stats['automated_logins'] > 0) {
            $score += min(10, $stats['automated_logins']);
            $signals[] = [
                'code' => 'automated_client',
                'label' => '检测到自动化客户端登录；测试工具也可能产生此信号',
                'count' => $stats['automated_logins'],
                'severity' => 'low',
            ];
        }

        if ($stats['config_change_count'] > 0 && !$stats['is_super_admin']) {
            $score += min(30, $stats['config_change_count'] * 10);
            $signals[] = [
                'code' => 'non_admin_config_change',
                'label' => '普通账号触发配置变更',
                'count' => $stats['config_change_count'],
                'severity' => 'medium',
            ];
        }

        if ($stats['export_count'] > 0) {
            $score += min(20, $stats['export_count'] * 5);
            $signals[] = [
                'code' => 'data_export',
                'label' => '存在数据导出操作',
                'count' => $stats['export_count'],
                'severity' => 'medium',
            ];
        }

        $level = $critical ? 'critical' : ($score >= 50 ? 'high' : ($score >= 20 ? 'medium' : 'low'));
        return [$score, $level, $signals];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $identity
     */
    private function operationEvent(array $row, array $identity, string $level, string $category, string $title, string $reason): array
    {
        return [
            'source' => 'operation',
            'source_id' => (int)($row['id'] ?? 0),
            'risk_level' => $level,
            'category' => $category,
            'title' => $title,
            'reason' => $this->safeText($reason),
            'user_id' => $identity['user_id'],
            'username' => $identity['username'],
            'realname' => $identity['realname'],
            'hotel_id' => isset($row['hotel_id']) ? (int)$row['hotel_id'] : null,
            'ip' => $this->normalizeIp((string)($row['ip'] ?? '')),
            'client_type' => $this->clientType((string)($row['user_agent'] ?? '')),
            'occurred_at' => (string)($row['create_time'] ?? ''),
        ];
    }

    private function isDestructiveAction(string $action): bool
    {
        return str_contains($action, 'delete') || str_contains($action, 'clear') || str_contains($action, 'archive');
    }

    private function isConfigAction(string $action): bool
    {
        return str_contains($action, 'config') || in_array($action, ['save_cookies', 'save_data_source'], true);
    }

    private function isAccountSecurityAction(string $module, string $action): bool
    {
        if ($module === 'role') {
            return true;
        }
        if ($module === 'competitor_device' && in_array($action, ['create', 'rotate_token', 'status'], true)) {
            return true;
        }

        return in_array($action, [
            'change_password',
            'reset_password',
            'batch_hotel_assignment',
        ], true);
    }

    /** @return array<string, mixed> */
    private function decodeExtraData($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function clientType(string $userAgent): string
    {
        if ($userAgent === '') {
            return '未知客户端';
        }
        foreach ([
            'HeadlessChrome' => 'HeadlessChrome',
            'Playwright' => 'Playwright',
            'curl/' => 'curl',
            'WindowsPowerShell' => 'PowerShell',
            'node' => 'node',
            'Edg/' => 'Edge',
            'Chrome/' => 'Chrome',
            'Firefox/' => 'Firefox',
            'iPhone' => 'iPhone',
        ] as $needle => $label) {
            if (stripos($userAgent, $needle) !== false) {
                return $label;
            }
        }
        return '其他客户端';
    }

    private function safeText(string $value, int $limit = 180): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $patterns = [
            '/\bAuthorization\s*:\s*Bearer\s+[^\s,;]+/iu' => 'Authorization=****',
            '/\bBearer\s+[A-Za-z0-9._\-]{8,}/u' => 'Bearer ****',
            '/\b(cookie|token|authorization|password|secret|spidertoken|mtgsig|usersign|usertoken|api[_-]?key|access[_-]?key)\s*[:=]\s*["\']?[^"\'\s,;]+/iu' => '$1=****',
            '/([?&](?:token|key|api[_-]?key|authorization|spidertoken|mtgsig|usersign|usertoken)=)[^&#\s]+/iu' => '$1****',
            '/sk-[A-Za-z0-9_-]{8,}/u' => 'sk-****',
            '/\s+/u' => ' ',
        ];
        foreach ($patterns as $pattern => $replacement) {
            $value = preg_replace($pattern, $replacement, $value) ?? $value;
        }
        return mb_substr($value, 0, max(0, $limit));
    }

    private function normalizeIp(string $ip): string
    {
        $ip = trim($ip);
        return mb_substr($ip, 0, 50);
    }

    /** @param array<int, string> $observations */
    private function buildIpEvidence(array $observations): array
    {
        $observations = array_values(array_filter($observations, static fn(string $ip): bool => $ip !== ''));
        $loopback = array_values(array_filter($observations, fn(string $ip): bool => $this->isLoopbackIp($ip)));
        $usable = array_values(array_filter($observations, fn(string $ip): bool => !$this->isLoopbackIp($ip)));
        $total = count($observations);
        $loopbackRatio = $total > 0 ? count($loopback) / $total : 1.0;
        $quality = count($usable) === 0 ? 'unavailable' : ($loopbackRatio >= 0.8 ? 'degraded' : 'available');

        $notes = [
            'available' => '来源 IP 可用于辅助核查，但仍不能单独证明账号实际操作者。',
            'degraded' => '大部分请求只记录为本机/反向代理地址，真实来源 IP 证据不完整。',
            'unavailable' => '当前日志只记录到 127.0.0.1/::1 或空值，无法据此区分真实登录地址。',
        ];

        return [
            'quality' => $quality,
            'observed_count' => $total,
            'loopback_count' => count($loopback),
            'usable_count' => count($usable),
            'distinct_usable_ips' => count(array_unique($usable)),
            'note' => $notes[$quality],
        ];
    }

    private function isLoopbackIp(string $ip): bool
    {
        return $ip === '::1' || $ip === '0:0:0:0:0:0:0:1' || str_starts_with($ip, '127.');
    }

    private function latestTime(string ...$values): string
    {
        $values = array_values(array_filter($values, static fn(string $value): bool => $value !== ''));
        if ($values === []) {
            return '';
        }
        rsort($values, SORT_STRING);
        return $values[0];
    }
}
