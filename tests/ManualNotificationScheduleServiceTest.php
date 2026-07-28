<?php
declare(strict_types=1);

namespace Tests;

use app\service\ManualNotificationDispatchLedgerService;
use app\service\ManualNotificationScheduleService;
use app\service\ManualNotificationService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class ManualNotificationScheduleServiceTest extends TestCase
{
    private static array $databaseConfig;
    private static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . '/manual_notification_schedule_' . getmypid() . '.sqlite';
        @unlink(self::$databasePath);
        $config = self::$databaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$databasePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($config, 'database');
        Db::connect(null, true);
    }

    public static function tearDownAfterClass(): void
    {
        Config::set(self::$databaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$databasePath);
    }

    protected function setUp(): void
    {
        Db::execute('CREATE TABLE IF NOT EXISTS manual_notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            hotel_id INTEGER NOT NULL,
            notification_type VARCHAR(40) NOT NULL,
            template_type VARCHAR(40) NOT NULL,
            business_date VARCHAR(10) NOT NULL,
            title VARCHAR(120) NOT NULL,
            body TEXT NOT NULL,
            send_method VARCHAR(32) NOT NULL,
            trigger_type VARCHAR(32) NOT NULL,
            planned_send_at DATETIME NULL,
            enabled INTEGER NOT NULL,
            schedule_status VARCHAR(32) NOT NULL,
            test_robot_id INTEGER NULL,
            test_robot_name VARCHAR(120) NULL,
            created_by INTEGER NOT NULL,
            create_time DATETIME NOT NULL,
            update_time DATETIME NOT NULL
        )');
        Db::execute('CREATE TABLE IF NOT EXISTS manual_notification_schedule_dispatches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            schedule_run_id INTEGER NULL,
            notification_id INTEGER NOT NULL,
            tenant_id INTEGER NOT NULL,
            hotel_id INTEGER NOT NULL,
            dispatch_window VARCHAR(32) NOT NULL,
            delivery_mode VARCHAR(16) NOT NULL,
            trigger_type VARCHAR(32) NOT NULL,
            request_kind VARCHAR(32) NOT NULL,
            business_date VARCHAR(10) NULL,
            payload_fingerprint VARCHAR(64) NULL,
            operating_target_record_id INTEGER NULL,
            snapshot_revision_no INTEGER NULL,
            render_contract_version VARCHAR(48) NULL,
            payload_snapshot_json TEXT NULL,
            attempt_count INTEGER NOT NULL,
            max_attempts INTEGER NOT NULL,
            next_retry_at DATETIME NULL,
            last_attempt_at DATETIME NULL,
            response_reference VARCHAR(120) NULL,
            robot_id INTEGER NOT NULL,
            robot_name VARCHAR(120) NOT NULL,
            status VARCHAR(24) NOT NULL,
            result_code VARCHAR(64) NOT NULL,
            result_message VARCHAR(255) NULL,
            claimed_at DATETIME NOT NULL,
            dispatched_at DATETIME NULL,
            create_time DATETIME NOT NULL,
            update_time DATETIME NOT NULL,
            UNIQUE(notification_id, dispatch_window, delivery_mode)
        )');
        Db::execute('CREATE TABLE IF NOT EXISTS manual_notification_dispatch_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            dispatch_id INTEGER NOT NULL,
            notification_id INTEGER NOT NULL,
            tenant_id INTEGER NOT NULL,
            hotel_id INTEGER NOT NULL,
            attempt_no INTEGER NOT NULL,
            request_kind VARCHAR(32) NOT NULL,
            status VARCHAR(32) NOT NULL,
            result_code VARCHAR(64) NOT NULL,
            result_message VARCHAR(255) NULL,
            payload_fingerprint VARCHAR(64) NULL,
            response_reference VARCHAR(120) NULL,
            attempted_at DATETIME NOT NULL,
            create_time DATETIME NOT NULL,
            UNIQUE(dispatch_id, attempt_no)
        )');
        Db::execute('CREATE TABLE IF NOT EXISTS manual_notification_schedule_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            scope_tenant_id INTEGER NULL,
            runner_mode VARCHAR(16) NOT NULL,
            dispatch_requested INTEGER NOT NULL,
            scope_hotel_id INTEGER NULL,
            scope_robot_id INTEGER NULL,
            observed_at DATETIME NOT NULL,
            status VARCHAR(32) NOT NULL,
            candidate_count INTEGER NOT NULL,
            due_count INTEGER NOT NULL,
            sent_count INTEGER NOT NULL,
            failed_count INTEGER NOT NULL,
            blocked_count INTEGER NOT NULL,
            result_summary_json TEXT NULL,
            started_at DATETIME NOT NULL,
            finished_at DATETIME NULL,
            create_time DATETIME NOT NULL,
            update_time DATETIME NOT NULL
        )');
        Db::execute('CREATE TABLE IF NOT EXISTS manual_notification_schedule_run_scopes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            schedule_run_id INTEGER NOT NULL,
            tenant_id INTEGER NOT NULL,
            hotel_id INTEGER NOT NULL,
            robot_id INTEGER NOT NULL,
            runner_mode VARCHAR(16) NOT NULL,
            dispatch_requested INTEGER NOT NULL,
            observed_at DATETIME NOT NULL,
            status VARCHAR(32) NOT NULL,
            candidate_count INTEGER NOT NULL,
            due_count INTEGER NOT NULL,
            sent_count INTEGER NOT NULL,
            failed_count INTEGER NOT NULL,
            blocked_count INTEGER NOT NULL,
            create_time DATETIME NOT NULL,
            update_time DATETIME NOT NULL,
            UNIQUE(schedule_run_id, tenant_id, hotel_id, robot_id)
        )');
        Db::execute('CREATE TABLE IF NOT EXISTS competitor_wechat_robot (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            store_id INTEGER NOT NULL,
            name VARCHAR(120) NOT NULL,
            status INTEGER NOT NULL,
            owner_user_id INTEGER NULL,
            notification_scope VARCHAR(40) NULL
        )');
        Db::execute('CREATE TABLE IF NOT EXISTS hotels (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            name VARCHAR(120) NOT NULL
        )');
        Db::name('manual_notification_dispatch_attempts')->delete(true);
        Db::name('manual_notification_schedule_dispatches')->delete(true);
        Db::name('manual_notification_schedule_run_scopes')->delete(true);
        Db::name('manual_notification_schedule_runs')->delete(true);
        Db::name('manual_notifications')->delete(true);
        Db::name('competitor_wechat_robot')->delete(true);
        Db::name('hotels')->delete(true);
        Db::name('hotels')->insert(['id' => 80, 'tenant_id' => 9, 'name' => '敦煌漠蓝新']);
        Db::name('competitor_wechat_robot')->insert([
            'id' => ManualNotificationService::TEST_ROBOT_ID,
            'store_id' => 80,
            'name' => ManualNotificationService::TEST_ROBOT_NAME,
            'status' => 1,
            'owner_user_id' => null,
            'notification_scope' => 'admin_shared',
        ]);
    }

    public function testPreviewSelectsOnlyEnabledPendingDueRecordsWithoutSending(): void
    {
        $this->insertRecord(['trigger_type' => 'daily_fixed_time', 'planned_send_at' => '2026-07-01 18:00:00']);
        $this->insertRecord(['trigger_type' => 'hourly_on_the_hour', 'planned_send_at' => null]);
        $this->insertRecord(['enabled' => 0]);
        $this->insertRecord(['schedule_status' => 'saved_only']);
        $calls = [];
        $service = new ManualNotificationScheduleService(
            static function () use (&$calls): array {
                $calls[] = true;
                return ['delivery_status' => 'sent'];
            }
        );

        $result = $service->runDue($this->time('2026-07-26 18:03:00'));

        self::assertSame('preview', $result['status']);
        self::assertFalse($result['dispatch_requested']);
        self::assertSame(2, $result['candidate_count']);
        self::assertSame(2, $result['due_count']);
        self::assertCount(2, $result['results']);
        self::assertSame([], $calls);
        self::assertSame(0, Db::name('manual_notification_schedule_dispatches')->count());
        self::assertStringContainsString('未取得的数据未使用0或旧日数据补齐', $result['results'][0]['payload']['markdown']['content']);
    }

    public function testExplicitDispatchUsesFakeSenderAndIsIdempotentPerWindowAndMode(): void
    {
        $notificationId = $this->insertRecord();
        $calls = [];
        $service = new ManualNotificationScheduleService(
            static function (int $hotelId, int $robotId, array $payload, array $context) use (&$calls): array {
                $calls[] = [$hotelId, $robotId, $payload, $context];
                return ['delivery_status' => 'sent'];
            }
        );
        $now = $this->time('2026-07-26 18:02:00');

        $first = $service->runDue($now, true);
        $second = $service->runDue($now, true);

        self::assertSame('sent', $first['results'][0]['status']);
        self::assertSame('skipped', $second['results'][0]['status']);
        self::assertSame('dispatch_window_already_claimed', $second['results'][0]['reason_code']);
        self::assertCount(1, $calls);
        self::assertSame([80, 1], array_slice($calls[0], 0, 2));
        self::assertSame($notificationId, $calls[0][3]['notification_id']);
        self::assertSame(1, Db::name('manual_notification_schedule_dispatches')->count());
        self::assertSame(
            $first['schedule_run_id'],
            (int)Db::name('manual_notification_schedule_dispatches')->value('schedule_run_id')
        );
        self::assertSame(
            $first['schedule_run_id'],
            $first['results'][0]['schedule_run_id']
        );
    }

    public function testScheduleRunEvidenceIsReadBackOnlyForTheExactScope(): void
    {
        $this->insertRecord();
        $service = new ManualNotificationScheduleService();
        $run = $service->runDue(
            $this->time('2026-07-26 18:02:00'),
            false,
            ManualNotificationScheduleService::MODE_TEST,
            100,
            80,
            1
        );
        Db::name('manual_notification_schedule_runs')->insert([
            'scope_tenant_id' => 10,
            'runner_mode' => 'formal',
            'dispatch_requested' => 1,
            'scope_hotel_id' => 81,
            'scope_robot_id' => 27,
            'observed_at' => '2026-07-26 18:03:00',
            'status' => 'completed',
            'candidate_count' => 1,
            'due_count' => 1,
            'sent_count' => 1,
            'failed_count' => 0,
            'blocked_count' => 0,
            'result_summary_json' => '{}',
            'started_at' => '2026-07-26 18:03:00',
            'finished_at' => '2026-07-26 18:03:01',
            'create_time' => '2026-07-26 18:03:00',
            'update_time' => '2026-07-26 18:03:01',
        ]);

        $scoped = (new ManualNotificationDispatchLedgerService())->latestScheduleRun(9, 80, 1);
        self::assertSame($run['schedule_run_id'], $scoped['run_id']);
        self::assertSame(9, $scoped['scope_tenant_id']);
        self::assertSame(80, $scoped['scope_hotel_id']);
        self::assertSame(1, $scoped['scope_robot_id']);

        $wrongRobot = (new ManualNotificationDispatchLedgerService())->latestScheduleRun(9, 80, 27);
        self::assertSame('not_run', $wrongRobot['status']);
    }

    public function testGlobalRunIsScopedByItsExactDispatchLink(): void
    {
        $this->insertRecord();
        $service = new ManualNotificationScheduleService(
            static fn(): array => ['delivery_status' => 'sent']
        );
        $run = $service->runDue(
            $this->time('2026-07-26 18:02:00'),
            true,
            ManualNotificationScheduleService::MODE_TEST
        );

        $scoped = (new ManualNotificationDispatchLedgerService())->latestScheduleRun(9, 80, 1);
        self::assertSame($run['schedule_run_id'], $scoped['run_id']);
        self::assertSame('dispatch_link', $scoped['scope_source']);
        self::assertSame(9, $scoped['scope_tenant_id']);
        self::assertSame(80, $scoped['scope_hotel_id']);
        self::assertSame(1, $scoped['scope_robot_id']);

        $otherHotel = (new ManualNotificationDispatchLedgerService())->latestScheduleRun(9, 81, 1);
        self::assertSame('not_run', $otherHotel['status']);
    }

    public function testGlobalRunReadbackAggregatesOnlyTheRequestedDispatchScope(): void
    {
        Db::name('hotels')->insert(['id' => 81, 'tenant_id' => 10, 'name' => 'Other hotel']);
        Db::name('competitor_wechat_robot')->insert([
            'id' => 27,
            'store_id' => 81,
            'name' => 'Other formal robot',
            'status' => 1,
            'owner_user_id' => null,
            'notification_scope' => 'admin_shared',
        ]);
        $runId = (int)Db::name('manual_notification_schedule_runs')->insertGetId([
            'scope_tenant_id' => null,
            'runner_mode' => 'formal',
            'dispatch_requested' => 1,
            'scope_hotel_id' => null,
            'scope_robot_id' => null,
            'observed_at' => date('Y-m-d H:i:s'),
            'status' => 'failed',
            'candidate_count' => 2,
            'due_count' => 2,
            'sent_count' => 1,
            'failed_count' => 1,
            'blocked_count' => 0,
            'result_summary_json' => '{}',
            'started_at' => date('Y-m-d H:i:s'),
            'finished_at' => date('Y-m-d H:i:s'),
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ]);
        $notificationA = $this->insertRecord([
            'send_method' => 'wecom_formal',
            'test_robot_id' => 1,
        ]);
        $notificationB = $this->insertRecord([
            'tenant_id' => 10,
            'hotel_id' => 81,
            'send_method' => 'wecom_formal',
            'test_robot_id' => 27,
        ]);
        $base = [
            'schedule_run_id' => $runId,
            'dispatch_window' => '2026-07-28 03:00',
            'delivery_mode' => 'formal',
            'trigger_type' => 'daily_fixed_time',
            'request_kind' => 'scheduled',
            'business_date' => '2026-07-28',
            'payload_fingerprint' => str_repeat('a', 64),
            'attempt_count' => 1,
            'max_attempts' => 3,
            'next_retry_at' => null,
            'last_attempt_at' => date('Y-m-d H:i:s'),
            'response_reference' => null,
            'claimed_at' => date('Y-m-d H:i:s'),
            'dispatched_at' => date('Y-m-d H:i:s'),
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ];
        Db::name('manual_notification_schedule_dispatches')->insert(array_replace($base, [
            'notification_id' => $notificationA,
            'tenant_id' => 9,
            'hotel_id' => 80,
            'robot_id' => 1,
            'robot_name' => ManualNotificationService::TEST_ROBOT_NAME,
            'status' => 'sent',
            'result_code' => 'sent',
            'result_message' => null,
        ]));
        Db::name('manual_notification_schedule_dispatches')->insert(array_replace($base, [
            'notification_id' => $notificationB,
            'tenant_id' => 10,
            'hotel_id' => 81,
            'robot_id' => 27,
            'robot_name' => 'Other formal robot',
            'status' => 'failed',
            'result_code' => 'delivery_failed',
            'result_message' => 'network failure',
        ]));

        $ledger = new ManualNotificationDispatchLedgerService();
        $hotelA = $ledger->latestScheduleRun(9, 80, 1);
        $hotelB = $ledger->latestScheduleRun(10, 81, 27);

        self::assertSame('dispatch_link', $hotelA['scope_source']);
        self::assertSame('formal_scope_ready', $hotelA['status']);
        self::assertSame('completed', $hotelA['run_status']);
        self::assertSame(1, $hotelA['candidate_count']);
        self::assertSame(1, $hotelA['due_count']);
        self::assertSame(1, $hotelA['sent_count']);
        self::assertSame(0, $hotelA['failed_count']);
        self::assertSame('failed', $hotelB['status']);
        self::assertSame(0, $hotelB['sent_count']);
        self::assertSame(1, $hotelB['failed_count']);
    }

    public function testEveryGlobalRunPersistsAnExactPlanScopeHeartbeatWhenNothingIsDue(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
        $this->insertRecord([
            'planned_send_at' => $now->modify('-30 minutes')->format('Y-m-d H:i:s'),
        ]);

        $run = (new ManualNotificationScheduleService())->runDue(
            $now,
            true,
            ManualNotificationScheduleService::MODE_TEST
        );
        $scoped = (new ManualNotificationDispatchLedgerService())->latestScheduleRun(9, 80, 1);

        self::assertSame(0, $run['due_count']);
        self::assertSame($run['schedule_run_id'], $scoped['run_id']);
        self::assertSame('plan_observation', $scoped['scope_source']);
        self::assertSame('test_scope_ready', $scoped['status']);
        self::assertSame(1, $scoped['candidate_count']);
        self::assertSame(0, $scoped['due_count']);
        self::assertSame(0, $scoped['sent_count']);
        self::assertSame(0, $scoped['failed_count']);
        self::assertSame(0, $scoped['blocked_count']);
    }

    public function testTestAndFormalPlansUseSeparatePersistedRobotScopes(): void
    {
        $testNotificationId = $this->insertRecord();
        Db::name('competitor_wechat_robot')->insertAll([
            [
                'id' => 27,
                'store_id' => 80,
                'name' => '正式经营群',
                'status' => 1,
                'owner_user_id' => null,
                'notification_scope' => 'admin_shared',
            ],
            [
                'id' => 28,
                'store_id' => 80,
                'name' => '其他账号群',
                'status' => 1,
                'owner_user_id' => 99,
                'notification_scope' => 'account_onboarding',
            ],
        ]);
        $formalNotificationId = $this->insertRecord([
            'notification_type' => 'anomaly_alert',
            'template_type' => 'anomaly_alert',
            'body' => "业务服务异常编号：A-17\n异常正文：价格规则未回读",
            'send_method' => 'wecom_formal',
            'test_robot_id' => 27,
            'test_robot_name' => '正式经营群',
        ]);
        $this->insertRecord([
            'notification_type' => 'task_notification',
            'template_type' => 'task_notification',
            'send_method' => 'wecom_formal',
            'test_robot_id' => 28,
            'test_robot_name' => '其他账号群',
        ]);
        $calls = [];
        $service = new ManualNotificationScheduleService(
            static function (int $hotelId, int $robotId, array $payload, array $context) use (&$calls): array {
                $calls[] = [$hotelId, $robotId, $payload, $context];
                return ['delivery_status' => 'sent'];
            }
        );

        $formal = $service->runDue($this->time('2026-07-26 18:01:00'), true, 'formal');
        self::assertSame('dispatch_blocked', $formal['status']);
        self::assertSame(1, $formal['sent_count']);
        self::assertSame(1, $formal['blocked_count']);
        self::assertSame('sent', $formal['results'][0]['status']);
        self::assertSame($formalNotificationId, $formal['results'][0]['notification_id']);
        self::assertSame('target_robot_scope_mismatch', $formal['results'][1]['reason_code']);
        self::assertSame([80, 27], array_slice($calls[0], 0, 2));
        self::assertSame('formal', $calls[0][3]['mode']);
        self::assertStringContainsString('企业微信正式群定时真实投递', $calls[0][2]['markdown']['content']);
        self::assertStringContainsString('异常正文：价格规则未回读', $calls[0][2]['markdown']['content']);

        $test = $service->runDue($this->time('2026-07-26 18:01:00'), true, 'test');
        self::assertSame('sent', $test['results'][0]['status']);
        self::assertSame($testNotificationId, $test['results'][0]['notification_id']);
        self::assertSame([80, 1], array_slice($calls[1], 0, 2));
        self::assertSame('test', $calls[1][3]['mode']);
        self::assertSame(3, Db::name('manual_notification_schedule_dispatches')->count());
    }

    public function testFailedClaimIsNotAutomaticallyResentAndMissingFactsStayExplicit(): void
    {
        $this->insertRecord([
            'body' => "房量：未取得\n建议：待配置",
        ]);
        $calls = [];
        $service = new ManualNotificationScheduleService(
            static function (int $hotelId, int $robotId, array $payload) use (&$calls): array {
                $calls[] = $payload;
                return ['delivery_status' => 'failed'];
            }
        );
        $now = $this->time('2026-07-26 18:04:00');

        $first = $service->runDue($now, true);
        $second = $service->runDue($now, true);

        self::assertSame('failed', $first['results'][0]['status']);
        self::assertSame('failed', $second['results'][0]['status']);
        self::assertFalse($second['results'][0]['delivery_attempted']);
        self::assertCount(1, $calls);
        self::assertStringContainsString('房量：未取得', $calls[0]['markdown']['content']);
        self::assertStringNotContainsString('房量：0', $calls[0]['markdown']['content']);
        self::assertSame(
            'failed',
            Db::name('manual_notification_schedule_dispatches')->value('status')
        );
    }

    public function testExpiredSendingLeaseBecomesOutcomeUnknownWithoutAutomaticResend(): void
    {
        $notificationId = $this->insertRecord();
        $ledger = new ManualNotificationDispatchLedgerService();
        $startedAt = $this->time('2026-07-26 18:00:00');
        $claim = $ledger->claim(
            $notificationId,
            9,
            80,
            '2026-07-26 18:00',
            ManualNotificationScheduleService::MODE_TEST,
            'daily_fixed_time',
            'scheduled',
            ManualNotificationService::TEST_ROBOT_ID,
            ManualNotificationService::TEST_ROBOT_NAME,
            '2026-07-26',
            [
                'payload' => [
                    'msgtype' => 'markdown',
                    'markdown' => ['content' => 'lease recovery test'],
                ],
            ],
            $startedAt
        );
        $attempt = $ledger->beginAttempt((int)$claim['dispatch']['id'], $startedAt);
        self::assertTrue($attempt['allowed']);

        $calls = [];
        $service = new ManualNotificationScheduleService(
            static function () use (&$calls): array {
                $calls[] = true;
                return ['delivery_status' => 'sent'];
            },
            null,
            $ledger
        );
        $result = $service->runDue($this->time('2026-07-26 18:10:00'), true);

        self::assertSame('dispatch_failed', $result['status']);
        self::assertSame(1, $result['recovered_unknown_count']);
        self::assertSame(0, $result['due_count']);
        self::assertSame([], $result['results']);
        self::assertSame(
            'outcome_unknown',
            Db::name('manual_notification_schedule_dispatches')
                ->where('id', (int)$claim['dispatch']['id'])
                ->value('status')
        );
        self::assertSame(
            'delivery_attempt_lease_expired_outcome_unknown',
            Db::name('manual_notification_schedule_dispatches')
                ->where('id', (int)$claim['dispatch']['id'])
                ->value('result_code')
        );
        self::assertSame([], $calls, 'An expired in-flight result must never be resent automatically.');
        self::assertSame(
            'outcome_unknown',
            Db::name('manual_notification_dispatch_attempts')
                ->where('id', (int)$attempt['attempt_id'])
                ->value('status')
        );

        $late = $ledger->finishAttempt(
            (int)$claim['dispatch']['id'],
            (int)$attempt['attempt_id'],
            ['delivery_status' => 'sent', 'response_reference' => 'late:receipt'],
            $this->time('2026-07-26 18:04:00')
        );
        self::assertSame(
            'outcome_unknown',
            $late['status'],
            'A late result from the expired process must not overwrite the reconciled state.'
        );
        self::assertTrue($late['retryable']);
        self::assertTrue($late['retry_requires_confirmation']);
        self::assertTrue($late['retry_may_duplicate']);
        self::assertFalse($late['automatic_retry_allowed']);
    }

    public function testLimitAppliesAfterDueFilteringSoLaterDuePlansAreNotStarved(): void
    {
        $notDueId = $this->insertRecord([
            'planned_send_at' => '2026-07-26 17:30:00',
        ]);
        $dueId = $this->insertRecord([
            'planned_send_at' => '2026-07-26 18:00:00',
        ]);

        $result = (new ManualNotificationScheduleService())
            ->runDue($this->time('2026-07-26 18:02:00'), false, 'test', 1);

        self::assertSame(2, $result['candidate_count']);
        self::assertSame(1, $result['due_count']);
        self::assertCount(1, $result['results']);
        self::assertSame($dueId, $result['results'][0]['notification_id']);
        self::assertNotSame($notDueId, $result['results'][0]['notification_id']);
    }

    /** @param array<string, mixed> $overrides */
    private function insertRecord(array $overrides = []): int
    {
        return (int)Db::name('manual_notifications')->insertGetId(array_replace([
            'tenant_id' => 9,
            'hotel_id' => 80,
            'notification_type' => 'blank_custom',
            'template_type' => 'blank_custom',
            'business_date' => '2026-07-26',
            'title' => '{经营日期} 自定义播报',
            'body' => "酒店：{酒店名称}\n经营日期：{经营日期}\n房量：未取得",
            'send_method' => 'wecom_test',
            'trigger_type' => 'daily_fixed_time',
            'planned_send_at' => '2026-07-26 18:00:00',
            'enabled' => 1,
            'schedule_status' => 'schedule_enabled',
            'test_robot_id' => ManualNotificationService::TEST_ROBOT_ID,
            'test_robot_name' => ManualNotificationService::TEST_ROBOT_NAME,
            'created_by' => 7,
            'create_time' => '2026-07-26 12:00:00',
            'update_time' => '2026-07-26 12:00:00',
        ], $overrides));
    }

    private function time(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('Asia/Shanghai'));
    }
}
