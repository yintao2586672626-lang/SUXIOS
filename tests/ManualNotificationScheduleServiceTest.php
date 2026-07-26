<?php
declare(strict_types=1);

namespace Tests;

use app\service\ManualNotificationScheduleService;
use app\service\ManualNotificationDispatchLedgerService;
use app\service\ManualNotificationTestTargetService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class ManualNotificationScheduleServiceTest extends TestCase
{
    private const HOTEL_ID = 5;
    private const ROBOT_ID = 2;
    private const ROBOT_NAME = '宿析OS云端日报';

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
            create_time DATETIME NOT NULL,
            update_time DATETIME NOT NULL
        )');
        Db::execute('CREATE TABLE IF NOT EXISTS manual_notification_schedule_dispatches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
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
        Db::execute('CREATE TABLE IF NOT EXISTS competitor_wechat_robot (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            store_id INTEGER NOT NULL,
            notification_scope VARCHAR(40) NULL,
            name VARCHAR(120) NOT NULL,
            status INTEGER NOT NULL
        )');
        Db::execute('CREATE TABLE IF NOT EXISTS hotels (
            id INTEGER PRIMARY KEY,
            name VARCHAR(120) NOT NULL
        )');
        Db::name('manual_notification_dispatch_attempts')->delete(true);
        Db::name('manual_notification_schedule_dispatches')->delete(true);
        Db::name('manual_notification_schedule_runs')->delete(true);
        Db::name('manual_notifications')->delete(true);
        Db::name('competitor_wechat_robot')->delete(true);
        Db::name('hotels')->delete(true);
        Db::name('hotels')->insert(['id' => self::HOTEL_ID, 'name' => '敦煌漠蓝新']);
        Db::name('competitor_wechat_robot')->insert([
            'id' => self::ROBOT_ID,
            'store_id' => self::HOTEL_ID,
            'notification_scope' => ManualNotificationTestTargetService::TEST_SCOPE,
            'name' => self::ROBOT_NAME,
            'status' => 1,
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

    public function testScopedPreviewEnumeratesTheFullDueSetBeforeDispatch(): void
    {
        for ($index = 0; $index < 6; $index++) {
            $this->insertRecord();
        }

        $result = (new ManualNotificationScheduleService())->runDue(
            $this->time('2026-07-26 18:04:00'),
            false,
            'test',
            5,
            self::HOTEL_ID,
            self::ROBOT_ID
        );

        self::assertSame('preview', $result['status']);
        self::assertSame(6, $result['candidate_count']);
        self::assertSame(6, $result['due_count']);
        self::assertCount(6, $result['results']);
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

        $first = $service->runDue(
            $now,
            true,
            'test',
            100,
            self::HOTEL_ID,
            self::ROBOT_ID
        );
        $second = $service->runDue(
            $now,
            true,
            'test',
            100,
            self::HOTEL_ID,
            self::ROBOT_ID
        );

        self::assertSame('sent', $first['results'][0]['status']);
        self::assertSame('skipped', $second['results'][0]['status']);
        self::assertSame('dispatch_window_already_claimed', $second['results'][0]['reason_code']);
        self::assertCount(1, $calls);
        self::assertSame([self::HOTEL_ID, self::ROBOT_ID], array_slice($calls[0], 0, 2));
        self::assertSame($notificationId, $calls[0][3]['notification_id']);
        self::assertSame(1, Db::name('manual_notification_schedule_dispatches')->count());
    }

    public function testDispatchLimitCapsExternalCallsWithoutStarvingLaterDueRecords(): void
    {
        for ($index = 0; $index < 6; $index++) {
            $this->insertRecord();
        }
        $calls = [];
        $service = new ManualNotificationScheduleService(
            static function (int $hotelId, int $robotId) use (&$calls): array {
                $calls[] = [$hotelId, $robotId];
                return ['delivery_status' => 'sent'];
            }
        );
        $now = $this->time('2026-07-26 18:04:00');

        $first = $service->runDue(
            $now,
            true,
            'test',
            5,
            self::HOTEL_ID,
            self::ROBOT_ID
        );

        self::assertSame('dispatch_blocked', $first['status']);
        self::assertSame(6, $first['candidate_count']);
        self::assertSame(6, $first['due_count']);
        self::assertSame(5, $first['delivery_attempt_count']);
        self::assertSame(1, $first['deferred_count']);
        self::assertSame('deferred', $first['results'][5]['status']);
        self::assertCount(5, $calls);

        $second = $service->runDue(
            $now,
            true,
            'test',
            5,
            self::HOTEL_ID,
            self::ROBOT_ID
        );

        self::assertSame('dispatch_checked', $second['status']);
        self::assertSame(1, $second['delivery_attempt_count']);
        self::assertSame(0, $second['deferred_count']);
        self::assertSame('sent', $second['results'][5]['status']);
        self::assertCount(6, $calls);
        self::assertSame(6, Db::name('manual_notification_schedule_dispatches')->count());
    }

    public function testTestAndFormalIdentitiesCannotCrossModes(): void
    {
        $notificationId = $this->insertRecord();
        $calls = [];
        $service = new ManualNotificationScheduleService(
            static function () use (&$calls): array {
                $calls[] = true;
                return ['delivery_status' => 'sent'];
            }
        );

        try {
            $service->runDue(
                $this->time('2026-07-26 18:01:00'),
                true,
                'formal',
                100,
                self::HOTEL_ID,
                self::ROBOT_ID
            );
            self::fail('Formal dispatch must be rejected before any delivery ledger claim.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('manual_notification_dispatch_scope_required', $error->getMessage());
        }

        Db::name('manual_notifications')
            ->where('id', $notificationId)
            ->update(['test_robot_name' => '正式经营群']);
        $test = $service->runDue(
            $this->time('2026-07-26 18:01:00'),
            true,
            'test',
            100,
            self::HOTEL_ID,
            self::ROBOT_ID
        );
        self::assertSame('blocked', $test['results'][0]['status']);
        self::assertSame('dispatch_blocked', $test['status']);
        self::assertSame('target_robot_identity_mismatch', $test['results'][0]['reason_code']);
        self::assertSame([], $calls);
        self::assertSame(1, Db::name('manual_notification_schedule_dispatches')->count());
        $blockedDispatch = Db::name('manual_notification_schedule_dispatches')
            ->where('notification_id', $notificationId)
            ->find();
        self::assertSame(self::ROBOT_ID, (int)$blockedDispatch['robot_id']);
        self::assertSame('正式经营群', $blockedDispatch['robot_name']);

        $stillBlocked = $service->runDue(
            $this->time('2026-07-26 18:01:30'),
            true,
            'test',
            100,
            self::HOTEL_ID,
            self::ROBOT_ID
        );
        self::assertSame('dispatch_blocked', $stillBlocked['status']);
        self::assertSame('blocked', $stillBlocked['results'][0]['status']);
        self::assertSame([], $calls);

        Db::name('manual_notifications')
            ->where('id', $notificationId)
            ->update(['test_robot_name' => self::ROBOT_NAME]);
        $recovered = $service->runDue(
            $this->time('2026-07-26 18:02:00'),
            true,
            'test',
            100,
            self::HOTEL_ID,
            self::ROBOT_ID
        );
        self::assertSame('dispatch_checked', $recovered['status']);
        self::assertSame('sent', $recovered['results'][0]['status']);
        self::assertSame([true], $calls);
        self::assertSame(1, Db::name('manual_notification_schedule_dispatches')->count());
        self::assertSame(
            1,
            (int)Db::name('manual_notification_schedule_dispatches')
                ->where('notification_id', $notificationId)
                ->value('attempt_count')
        );
    }

    public function testStaleSendingBecomesOutcomeUnknownWithoutAutomaticResend(): void
    {
        $notificationId = $this->insertRecord(['enabled' => 0]);
        $otherNotificationId = $this->insertRecord(['enabled' => 0]);
        $ledger = new ManualNotificationDispatchLedgerService();
        $startedAt = $this->time('2026-07-26 18:00:00');
        $payload = ['msgtype' => 'text', 'text' => ['content' => 'stale sending fixture']];
        $claim = $ledger->claim(
            $notificationId,
            9,
            self::HOTEL_ID,
            '2026-07-26 18:00',
            'test',
            'daily_fixed_time',
            'scheduled',
            self::ROBOT_ID,
            self::ROBOT_NAME,
            '2026-07-26',
            [
                'status' => 'ready',
                'payload' => $payload,
                'preview_fingerprint' => hash('sha256', json_encode($payload)),
            ],
            $startedAt
        );
        $ledger->beginAttempt((int)$claim['dispatch']['id'], $startedAt);
        $otherRobotClaim = $ledger->claim(
            $otherNotificationId,
            9,
            self::HOTEL_ID,
            '2026-07-26 18:00',
            'test',
            'daily_fixed_time',
            'scheduled',
            3,
            '其他机器人',
            '2026-07-26',
            [
                'status' => 'ready',
                'payload' => $payload,
                'preview_fingerprint' => hash('sha256', json_encode($payload)),
            ],
            $startedAt
        );
        $ledger->beginAttempt((int)$otherRobotClaim['dispatch']['id'], $startedAt);
        $calls = [];
        $service = new ManualNotificationScheduleService(
            static function () use (&$calls): array {
                $calls[] = true;
                return ['delivery_status' => 'sent'];
            }
        );

        $result = $service->runDue(
            $this->time('2026-07-26 18:06:00'),
            true,
            'test',
            5,
            self::HOTEL_ID,
            self::ROBOT_ID
        );

        self::assertSame('dispatch_blocked', $result['status']);
        self::assertSame(1, $result['stale_sending_outcome_unknown_count']);
        self::assertSame([], $calls);
        self::assertSame(
            'outcome_unknown',
            Db::name('manual_notification_schedule_dispatches')
                ->where('id', (int)$claim['dispatch']['id'])
                ->value('status')
        );
        self::assertSame(
            'outcome_unknown',
            Db::name('manual_notification_dispatch_attempts')
                ->where('dispatch_id', (int)$claim['dispatch']['id'])
                ->value('status')
        );
        self::assertSame(
            'sending',
            Db::name('manual_notification_schedule_dispatches')
                ->where('id', (int)$otherRobotClaim['dispatch']['id'])
                ->value('status')
        );
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

        $first = $service->runDue(
            $now,
            true,
            'test',
            100,
            self::HOTEL_ID,
            self::ROBOT_ID
        );
        $second = $service->runDue(
            $now,
            true,
            'test',
            100,
            self::HOTEL_ID,
            self::ROBOT_ID
        );

        self::assertSame('dispatch_failed', $first['status']);
        self::assertSame('failed', $first['results'][0]['status']);
        self::assertSame('dispatch_failed', $second['status']);
        self::assertSame('failed', $second['results'][0]['status']);
        self::assertSame('failed', $second['results'][0]['existing_status']);
        self::assertCount(1, $calls);
        self::assertStringContainsString('房量：未取得', $calls[0]['markdown']['content']);
        self::assertStringNotContainsString('房量：0', $calls[0]['markdown']['content']);
        self::assertSame(
            'failed',
            Db::name('manual_notification_schedule_dispatches')->value('status')
        );
    }

    public function testScheduledCloudScopeUsesVerifiedHotel5Robot2Pair(): void
    {
        $this->insertRecord([
            'tenant_id' => 1,
            'hotel_id' => self::HOTEL_ID,
            'test_robot_id' => self::ROBOT_ID,
            'test_robot_name' => self::ROBOT_NAME,
        ]);
        $calls = [];
        $service = new ManualNotificationScheduleService(
            static function (int $hotelId, int $robotId) use (&$calls): array {
                $calls[] = [$hotelId, $robotId];
                return ['delivery_status' => 'sent'];
            }
        );

        $result = $service->runDue(
            $this->time('2026-07-26 18:02:00'),
            true,
            ManualNotificationScheduleService::MODE_TEST,
            100,
            self::HOTEL_ID,
            self::ROBOT_ID
        );

        self::assertSame('sent', $result['results'][0]['status']);
        self::assertSame([[self::HOTEL_ID, self::ROBOT_ID]], $calls);
    }

    /** @param array<string, mixed> $overrides */
    private function insertRecord(array $overrides = []): int
    {
        return (int)Db::name('manual_notifications')->insertGetId(array_replace([
            'tenant_id' => 9,
            'hotel_id' => self::HOTEL_ID,
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
            'test_robot_id' => self::ROBOT_ID,
            'test_robot_name' => self::ROBOT_NAME,
            'create_time' => '2026-07-26 12:00:00',
            'update_time' => '2026-07-26 12:00:00',
        ], $overrides));
    }

    private function time(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('Asia/Shanghai'));
    }
}
