<?php
declare(strict_types=1);

namespace Tests;

use app\service\SecurityMonitoringService;
use PHPUnit\Framework\TestCase;

final class SecurityMonitoringServiceTest extends TestCase
{
    public function testBuildOverviewSeparatesEvidenceFromMaliceAndRedactsSecrets(): void
    {
        $users = [
            ['id' => 1, 'username' => 'admin', 'realname' => '管理员', 'role_id' => 1, 'role_name' => 'admin', 'role_display_name' => '超级管理员'],
            ['id' => 6, 'username' => 'VIP006', 'realname' => '员工甲', 'role_id' => 8, 'role_name' => 'VIPUser', 'role_display_name' => '内测用户'],
            ['id' => 8, 'username' => 'VIP004', 'realname' => '员工乙', 'role_id' => 8, 'role_name' => 'VIPUser', 'role_display_name' => '内测用户'],
        ];

        $loginRows = [];
        for ($i = 1; $i <= 5; $i++) {
            $loginRows[] = [
                'id' => $i,
                'user_id' => 6,
                'username' => 'VIP006',
                'action' => 'login',
                'status' => 'failed',
                'message' => '密码错误',
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Mozilla/5.0 Chrome/140',
                'created_at' => '2026-07-15 09:0' . $i . ':00',
            ];
        }

        $operationRows = [];
        for ($i = 1; $i <= 300; $i++) {
            $operationRows[] = [
                'id' => $i,
                'user_id' => 6,
                'hotel_id' => 12,
                'module' => 'security',
                'action' => 'rate_limited',
                'description' => 'Protected request rate limited',
                'ip' => '127.0.0.1',
                'user_agent' => 'Mozilla/5.0 Chrome/140',
                'create_time' => '2026-07-15 10:00:00',
                'error_info' => 'HTTP 429',
                'extra_data' => json_encode(['path' => 'api/online-data/auto-fetch-status']),
            ];
        }
        $operationRows[] = [
            'id' => 500,
            'user_id' => 8,
            'hotel_id' => 58,
            'module' => 'hotel',
            'action' => 'delete',
            'description' => '删除酒店 token=secret-value',
            'ip' => '127.0.0.1',
            'user_agent' => 'Mozilla/5.0 Chrome/140',
            'create_time' => '2026-07-15 10:05:00',
            'error_info' => '',
            'extra_data' => null,
        ];

        $overview = (new SecurityMonitoringService())->buildOverviewFromRows(
            $loginRows,
            $operationRows,
            [
                ['user_id' => 6, 'operation_count' => 620, 'action_types' => 4, 'last_operation_at' => '2026-07-15 10:00:00'],
                ['user_id' => 8, 'operation_count' => 12, 'action_types' => 3, 'last_operation_at' => '2026-07-15 10:05:00'],
            ],
            $users,
            ['days' => 7]
        );

        $byUsername = [];
        foreach ($overview['risk_users'] as $row) {
            $byUsername[$row['username']] = $row;
        }

        self::assertSame('high', $byUsername['VIP006']['risk_level']);
        self::assertSame(300, $byUsername['VIP006']['rate_limited_count']);
        self::assertSame('critical', $byUsername['VIP004']['risk_level']);
        self::assertSame(1, $byUsername['VIP004']['successful_destructive_count']);
        self::assertSame('unavailable', $overview['ip_evidence']['quality']);
        self::assertStringContainsString('不自动认定主观恶意', $overview['coverage']['note']);

        $deleteEvent = array_values(array_filter(
            $overview['latest_events'],
            static fn(array $event): bool => $event['category'] === 'destructive_operation'
        ))[0];
        self::assertStringContainsString('token=****', $deleteEvent['reason']);
        self::assertStringNotContainsString('secret-value', $deleteEvent['reason']);
    }

    public function testAutomatedClientsAreReportedAsEvidenceWithoutBecomingCriticalAlone(): void
    {
        $overview = (new SecurityMonitoringService())->buildOverviewFromRows(
            [[
                'id' => 1,
                'user_id' => 1,
                'username' => 'admin',
                'action' => 'login',
                'status' => 'failed',
                'message' => '测试登录失败',
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Playwright HeadlessChrome',
                'created_at' => '2026-07-15 08:00:00',
            ]],
            [],
            [],
            [['id' => 1, 'username' => 'admin', 'realname' => '管理员', 'role_id' => 1, 'role_name' => 'admin', 'role_display_name' => '超级管理员']],
            ['days' => 1]
        );

        self::assertCount(1, $overview['risk_users']);
        self::assertSame('low', $overview['risk_users'][0]['risk_level']);
        self::assertSame(1, $overview['risk_users'][0]['automated_logins']);
    }

    public function testAccountAndPermissionChangesRemainVisibleAsHighRiskEvents(): void
    {
        $actions = [
            ['auth', 'change_password'],
            ['auth', 'reset_password'],
            ['user', 'batch_hotel_assignment'],
            ['role', 'update'],
            ['competitor_device', 'rotate_token'],
            ['competitor_device', 'status'],
        ];
        $rows = [];
        foreach ($actions as $index => [$module, $action]) {
            $rows[] = [
                'id' => $index + 1,
                'user_id' => 1,
                'hotel_id' => 118,
                'module' => $module,
                'action' => $action,
                'description' => $action,
                'ip' => '127.0.0.1',
                'user_agent' => 'Chrome/140',
                'create_time' => '2026-07-19 10:00:0' . $index,
                'error_info' => '',
                'extra_data' => null,
            ];
        }

        $overview = (new SecurityMonitoringService())->buildOverviewFromRows(
            [],
            $rows,
            [],
            [['id' => 1, 'username' => 'admin', 'realname' => '管理员', 'role_id' => 1, 'role_name' => 'admin', 'role_display_name' => '超级管理员']],
            ['days' => 1]
        );

        $events = array_values(array_filter(
            $overview['latest_events'],
            static fn(array $event): bool => $event['category'] === 'account_security_change'
        ));
        self::assertCount(count($actions), $events);
        foreach ($events as $event) {
            self::assertSame('high', $event['risk_level']);
        }
    }

    public function testAccountActivityCountsOnlyEnabledNonAdminSuccessfulLogins(): void
    {
        $overview = (new SecurityMonitoringService())->buildOverviewFromRows(
            [
                [
                    'id' => 1,
                    'user_id' => 20,
                    'username' => 'VIP020',
                    'action' => 'login',
                    'status' => 'success',
                    'message' => '登录成功',
                    'ip_address' => '127.0.0.1',
                    'user_agent' => 'Chrome/140',
                    'created_at' => '2026-07-15 09:00:00',
                ],
                [
                    'id' => 2,
                    'user_id' => 22,
                    'username' => 'VIP022',
                    'action' => 'login',
                    'status' => 'failed',
                    'message' => '密码错误',
                    'ip_address' => '127.0.0.1',
                    'user_agent' => 'Chrome/140',
                    'created_at' => '2026-07-15 09:30:00',
                ],
                [
                    'id' => 3,
                    'user_id' => 1,
                    'username' => 'admin',
                    'action' => 'login',
                    'status' => 'success',
                    'message' => '登录成功',
                    'ip_address' => '127.0.0.1',
                    'user_agent' => 'Chrome/140',
                    'created_at' => '2026-07-15 10:00:00',
                ],
            ],
            [],
            [],
            [
                ['id' => 1, 'username' => 'admin', 'realname' => '管理员', 'role_id' => 1, 'role_name' => 'admin', 'role_display_name' => '超级管理员', 'status' => 1, 'last_login_time' => '2026-07-15 10:00:00', 'login_count' => 50],
                ['id' => 20, 'username' => 'VIP020', 'realname' => '活跃员工', 'role_id' => 8, 'role_name' => 'VIPUser', 'role_display_name' => '内测用户', 'status' => 1, 'last_login_time' => '2026-07-15 09:00:00', 'login_count' => 3],
                ['id' => 21, 'username' => 'VIP021', 'realname' => '近期未活跃', 'role_id' => 8, 'role_name' => 'VIPUser', 'role_display_name' => '内测用户', 'status' => 1, 'last_login_time' => '2026-06-01 08:00:00', 'login_count' => 7],
                ['id' => 22, 'username' => 'VIP022', 'realname' => '从未成功登录', 'role_id' => 8, 'role_name' => 'VIPUser', 'role_display_name' => '内测用户', 'status' => 1, 'last_login_time' => null, 'login_count' => 0],
                ['id' => 23, 'username' => 'VIP023', 'realname' => '停用员工', 'role_id' => 8, 'role_name' => 'VIPUser', 'role_display_name' => '内测用户', 'status' => 0, 'last_login_time' => null, 'login_count' => 0],
                ['id' => 24, 'username' => 'CUSTOM_ADMIN', 'realname' => '自定义超级管理员', 'role_id' => 9, 'role_name' => 'admin', 'role_display_name' => '自定义超管', 'role_status' => 1, 'role_level' => 1, 'role_permissions' => json_encode(['all']), 'status' => 1],
            ],
            ['days' => 7],
            [
                'complete' => true,
                'window_rows' => [
                    ['user_id' => 20, 'username' => 'VIP020', 'login_attempts' => 3, 'successful_logins' => 3, 'failed_logins' => 0, 'last_attempt_at' => '2026-07-15 09:00:00', 'window_last_success_at' => '2026-07-15 09:00:00'],
                    ['user_id' => 22, 'username' => 'VIP022', 'login_attempts' => 1, 'successful_logins' => 0, 'failed_logins' => 1, 'last_attempt_at' => '2026-07-15 09:30:00', 'window_last_success_at' => null],
                    ['user_id' => 24, 'username' => 'CUSTOM_ADMIN', 'login_attempts' => 9, 'successful_logins' => 9, 'failed_logins' => 0, 'last_attempt_at' => '2026-07-15 11:00:00', 'window_last_success_at' => '2026-07-15 11:00:00'],
                ],
                'historical_rows' => [
                    ['user_id' => 20, 'username' => 'VIP020', 'historical_successful_logins' => 3, 'historical_last_success_at' => '2026-07-15 09:00:00'],
                    ['user_id' => 21, 'username' => 'VIP021', 'historical_successful_logins' => 7, 'historical_last_success_at' => '2026-06-01 08:00:00'],
                    ['user_id' => 24, 'username' => 'CUSTOM_ADMIN', 'historical_successful_logins' => 9, 'historical_last_success_at' => '2026-07-15 11:00:00'],
                ],
                'source' => 'grouped_login_logs',
            ]
        );

        self::assertSame([
            'enabled_accounts' => 3,
            'active_accounts' => 1,
            'inactive_accounts' => 2,
            'never_logged_in_accounts' => 1,
        ], $overview['account_activity']['summary']);
        self::assertSame('VIP020', $overview['account_activity']['active_accounts'][0]['username']);
        self::assertSame(3, $overview['account_activity']['active_accounts'][0]['successful_logins']);
        self::assertCount(1, $overview['account_activity']['active_accounts']);
        self::assertSame('VIP022', $overview['account_activity']['inactive_accounts'][0]['username']);
        self::assertTrue($overview['account_activity']['inactive_accounts'][0]['never_logged_in']);
        self::assertSame(1, $overview['account_activity']['inactive_accounts'][0]['failed_logins']);
        self::assertArrayNotHasKey('ip', $overview['account_activity']['active_accounts'][0]);
        self::assertArrayNotHasKey('ip', $overview['account_activity']['inactive_accounts'][0]);
        self::assertStringContainsString('仅超级管理员可见', $overview['account_activity']['definition']['visibility']);
    }

    public function testAccountActivityUsesCompleteGroupedEvidenceWhenRiskRowsAreTruncated(): void
    {
        $overview = (new SecurityMonitoringService())->buildOverviewFromRows(
            [[
                'id' => 10000,
                'user_id' => 30,
                'username' => 'NOISY_USER',
                'action' => 'login',
                'status' => 'failed',
                'message' => '高频失败样本中的最后一行',
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Chrome/140',
                'created_at' => '2026-07-15 12:00:00',
            ]],
            [],
            [],
            [
                ['id' => 30, 'username' => 'NOISY_USER', 'realname' => '高频账号', 'role_id' => 8, 'role_name' => 'VIPUser', 'role_display_name' => '内测用户', 'status' => 1],
                ['id' => 31, 'username' => 'QUIET_ACTIVE', 'realname' => '低频活跃账号', 'role_id' => 8, 'role_name' => 'VIPUser', 'role_display_name' => '内测用户', 'status' => 1],
            ],
            ['days' => 1, 'source_errors' => ['login_logs_truncated']],
            [
                'complete' => true,
                'window_rows' => [
                    ['user_id' => 30, 'username' => 'NOISY_USER', 'login_attempts' => 10001, 'successful_logins' => 0, 'failed_logins' => 10001, 'last_attempt_at' => '2026-07-15 12:00:00', 'window_last_success_at' => null],
                    ['user_id' => 31, 'username' => 'QUIET_ACTIVE', 'login_attempts' => 2, 'successful_logins' => 2, 'failed_logins' => 0, 'last_attempt_at' => '2026-07-15 08:00:00', 'window_last_success_at' => '2026-07-15 08:00:00'],
                ],
                'historical_rows' => [
                    ['user_id' => 31, 'username' => 'QUIET_ACTIVE', 'historical_successful_logins' => 2, 'historical_last_success_at' => '2026-07-15 08:00:00'],
                ],
                'source' => 'grouped_login_logs',
            ]
        );

        self::assertTrue($overview['account_activity']['complete']);
        self::assertSame(1, $overview['account_activity']['summary']['active_accounts']);
        self::assertSame('QUIET_ACTIVE', $overview['account_activity']['active_accounts'][0]['username']);
        self::assertSame(2, $overview['account_activity']['active_accounts'][0]['successful_logins']);
        self::assertSame('NOISY_USER', $overview['account_activity']['inactive_accounts'][0]['username']);
        self::assertFalse($overview['coverage']['complete']);
        self::assertContains('login_logs_truncated', $overview['coverage']['source_errors']);

        $source = (string)file_get_contents(__DIR__ . '/../app/service/SecurityMonitoringService.php');
        $start = strpos($source, 'private function loadAccountLoginActivityEvidence');
        $end = strpos($source, "\n    /**", $start + 20);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $queryContract = substr($source, $start, $end - $start);
        self::assertGreaterThanOrEqual(2, substr_count($queryContract, "->where('action', 'login')"));
        self::assertStringContainsString("->whereBetween('created_at', [\$startAt, \$endAt])", $queryContract);
        self::assertGreaterThanOrEqual(2, substr_count($queryContract, "->group('user_id,username')"));
        self::assertStringNotContainsString('->limit(', $queryContract);
        self::assertStringContainsString('limit(self::MAX_LOGIN_ROWS + 1)', $source);
    }

    public function testSummaryCountsAllFailedLoginsIncludingAccountsBelowRiskThreshold(): void
    {
        $overview = (new SecurityMonitoringService())->buildOverviewFromRows(
            [[
                'id' => 1,
                'user_id' => 40,
                'username' => 'VIP040',
                'action' => 'login',
                'status' => 'failed',
                'message' => 'invalid password',
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Chrome/140',
                'created_at' => '2026-07-15 09:00:00',
            ]],
            [],
            [],
            [['id' => 40, 'username' => 'VIP040', 'realname' => 'User 40', 'role_id' => 8, 'role_name' => 'VIPUser', 'role_display_name' => 'User', 'status' => 1]],
            ['days' => 1],
            [
                'complete' => true,
                'window_rows' => [
                    ['user_id' => 40, 'username' => 'VIP040', 'login_attempts' => 1, 'successful_logins' => 0, 'failed_logins' => 1],
                    ['user_id' => 0, 'username' => 'unknown-account', 'login_attempts' => 2, 'successful_logins' => 0, 'failed_logins' => 2],
                ],
                'historical_rows' => [],
            ]
        );

        self::assertSame(3, $overview['summary']['failed_logins']);
        self::assertSame([], $overview['risk_users']);
    }

    public function testOnlyLoginActionsCanAdvanceLastLoginAndLastAttempt(): void
    {
        $overview = (new SecurityMonitoringService())->buildOverviewFromRows(
            [
                ['id' => 1, 'user_id' => 40, 'username' => 'VIP040', 'action' => 'login', 'status' => 'success', 'message' => '', 'ip_address' => '127.0.0.1', 'user_agent' => 'Chrome/140', 'created_at' => '2026-07-15 09:00:00'],
                ['id' => 2, 'user_id' => 40, 'username' => 'VIP040', 'action' => 'logout', 'status' => 'success', 'message' => '', 'ip_address' => '127.0.0.1', 'user_agent' => 'Chrome/140', 'created_at' => '2026-07-15 10:00:00'],
                ['id' => 3, 'user_id' => 40, 'username' => 'VIP040', 'action' => 'register', 'status' => 'success', 'message' => '', 'ip_address' => '127.0.0.1', 'user_agent' => 'Chrome/140', 'created_at' => '2026-07-15 11:00:00'],
                ['id' => 4, 'user_id' => 40, 'username' => 'VIP040', 'action' => 'refresh', 'status' => 'success', 'message' => '', 'ip_address' => '127.0.0.1', 'user_agent' => 'Chrome/140', 'created_at' => '2026-07-15 12:00:00'],
            ],
            [],
            [],
            [['id' => 40, 'username' => 'VIP040', 'realname' => '登录动作测试', 'role_id' => 8, 'role_name' => 'VIPUser', 'role_display_name' => '内测用户', 'status' => 1]],
            ['days' => 1],
            [
                'complete' => true,
                'window_rows' => [
                    ['user_id' => 40, 'username' => 'VIP040', 'login_attempts' => 1, 'successful_logins' => 1, 'failed_logins' => 0, 'last_attempt_at' => '2026-07-15 09:00:00', 'window_last_success_at' => '2026-07-15 09:00:00'],
                ],
                'historical_rows' => [
                    ['user_id' => 40, 'username' => 'VIP040', 'historical_successful_logins' => 1, 'historical_last_success_at' => '2026-07-15 09:00:00'],
                ],
            ]
        );

        self::assertSame('2026-07-15 09:00:00', $overview['login_activity'][0]['last_login_at']);
        self::assertSame('2026-07-15 09:00:00', $overview['account_activity']['active_accounts'][0]['last_attempt_at']);
        self::assertSame(1, $overview['login_activity'][0]['login_count']);
    }
}
