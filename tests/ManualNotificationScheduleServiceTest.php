<?php
declare(strict_types=1);

namespace Tests;

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
        Db::name('hotels')->insert(['id' => 80, 'name' => '敦煌漠蓝新']);
        Db::name('competitor_wechat_robot')->insert([
            'id' => ManualNotificationService::TEST_ROBOT_ID,
            'store_id' => 80,
            'name' => ManualNotificationService::TEST_ROBOT_NAME,
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

        $formal = $service->runDue($this->time('2026-07-26 18:01:00'), true, 'formal');
        self::assertSame('blocked', $formal['results'][0]['status']);
        self::assertSame('formal_delivery_not_authorized', $formal['results'][0]['reason_code']);

        Db::name('manual_notifications')
            ->where('id', $notificationId)
            ->update(['test_robot_name' => '正式经营群']);
        $test = $service->runDue($this->time('2026-07-26 18:01:00'), true, 'test');
        self::assertSame('blocked', $test['results'][0]['status']);
        self::assertSame('test_target_binding_missing', $test['results'][0]['reason_code']);
        self::assertSame([], $calls);
        self::assertSame(2, Db::name('manual_notification_schedule_dispatches')->count());
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
        self::assertSame('skipped', $second['results'][0]['status']);
        self::assertCount(1, $calls);
        self::assertStringContainsString('房量：未取得', $calls[0]['markdown']['content']);
        self::assertStringNotContainsString('房量：0', $calls[0]['markdown']['content']);
        self::assertSame(
            'failed',
            Db::name('manual_notification_schedule_dispatches')->value('status')
        );
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
            'create_time' => '2026-07-26 12:00:00',
            'update_time' => '2026-07-26 12:00:00',
        ], $overrides));
    }

    private function time(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('Asia/Shanghai'));
    }
}
