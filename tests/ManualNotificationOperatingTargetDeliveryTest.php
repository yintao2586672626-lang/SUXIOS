<?php
declare(strict_types=1);

namespace Tests;

use app\service\ManualNotificationScheduleService;
use app\service\ManualNotificationService;
use app\service\OperatingTargetService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class ManualNotificationOperatingTargetDeliveryTest extends TestCase
{
    private static array $databaseConfig;
    private static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir()
            . '/manual_notification_operating_target_' . getmypid() . '.sqlite';
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
        Db::execute('CREATE TABLE IF NOT EXISTS operating_target_daily_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            hotel_id INTEGER NOT NULL,
            target_date VARCHAR(10) NOT NULL,
            target_revenue NUMERIC NULL,
            actual_revenue NUMERIC NULL,
            sold_room_nights INTEGER NULL,
            sellable_room_nights INTEGER NULL,
            fact_scope VARCHAR(32) NOT NULL,
            source_type VARCHAR(32) NOT NULL,
            source_reference VARCHAR(255) NULL,
            quality_status VARCHAR(32) NOT NULL,
            quality_reason VARCHAR(255) NULL,
            fact_captured_at DATETIME NULL,
            calculation_status VARCHAR(32) NOT NULL,
            gap_codes_json TEXT NULL,
            calculation_json TEXT NULL,
            report_status VARCHAR(32) NOT NULL,
            created_by INTEGER NULL,
            updated_by INTEGER NULL,
            create_time DATETIME NOT NULL,
            update_time DATETIME NOT NULL,
            UNIQUE(tenant_id, hotel_id, target_date)
        )');
        Db::execute('CREATE TABLE IF NOT EXISTS operating_target_daily_snapshots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            record_id INTEGER NOT NULL,
            tenant_id INTEGER NOT NULL,
            hotel_id INTEGER NOT NULL,
            target_date VARCHAR(10) NOT NULL,
            revision_no INTEGER NOT NULL,
            change_reason VARCHAR(500) NULL,
            snapshot_json TEXT NOT NULL,
            created_by INTEGER NULL,
            create_time DATETIME NOT NULL,
            UNIQUE(record_id, revision_no)
        )');
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
            last_test_status VARCHAR(32) NOT NULL,
            last_test_message VARCHAR(255) NULL,
            last_tested_at DATETIME NULL,
            last_tested_by INTEGER NULL,
            test_robot_id INTEGER NULL,
            test_robot_name VARCHAR(120) NULL,
            created_by INTEGER NOT NULL,
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
            id INTEGER PRIMARY KEY,
            store_id INTEGER NOT NULL,
            name VARCHAR(120) NOT NULL,
            status INTEGER NOT NULL
        )');
        Db::execute('CREATE TABLE IF NOT EXISTS hotels (
            id INTEGER PRIMARY KEY,
            name VARCHAR(120) NOT NULL
        )');
        foreach ([
            'manual_notification_dispatch_attempts',
            'manual_notification_schedule_dispatches',
            'manual_notification_schedule_runs',
            'manual_notifications',
            'operating_target_daily_snapshots',
            'operating_target_daily_records',
            'competitor_wechat_robot',
            'hotels',
        ] as $table) {
            Db::name($table)->delete(true);
        }
        Db::name('hotels')->insert(['id' => 80, 'name' => '敦煌漠蓝新']);
        Db::name('competitor_wechat_robot')->insert([
            'id' => ManualNotificationService::TEST_ROBOT_ID,
            'store_id' => ManualNotificationService::TEST_HOTEL_ID,
            'name' => ManualNotificationService::TEST_ROBOT_NAME,
            'status' => 1,
        ]);
    }

    public function testReadyAccommodationTargetFlowsThroughImmediateAndScheduledDelivery(): void
    {
        $today = $this->today();
        $target = (new OperatingTargetService())->save(80, 80, 7, [
            'target_date' => $today,
            'target_revenue' => 10000,
            'actual_revenue' => 10135.29,
            'sold_room_nights' => 16,
            'sellable_room_nights' => 16,
            'fact_scope' => 'accommodation_room_fee',
            'source_type' => 'pms',
            'source_reference' => '订单来了住宿数据中心 / capture:901',
            'quality_status' => 'verified',
            'quality_reason' => '住宿房费汇总与房费明细已对账并完成数据库回读。',
            'fact_captured_at' => $today . ' 12:00:00',
        ]);
        self::assertSame('ready', $target['status']);

        $immediateCalls = [];
        $notifications = new ManualNotificationService(
            static function (
                int $hotelId,
                int $robotId,
                array $payload,
                array $context
            ) use (&$immediateCalls): array {
                $immediateCalls[] = [$hotelId, $robotId, $payload, $context];
                return [
                    'delivery_status' => 'sent',
                    'sent_count' => 1,
                    'failed_count' => 0,
                ];
            }
        );
        $saved = $notifications->save(80, 80, 7, '敦煌漠蓝新', $this->notificationInput($today));
        self::assertSame('awaiting_test', $saved['record']['schedule_status']);
        self::assertStringContainsString('住宿房费实际额', $saved['preview']['payload']['markdown']['content']);

        $test = $notifications->testPush(
            80,
            80,
            (int)$saved['record']['id'],
            7,
            true,
            1,
            ManualNotificationService::TEST_ROBOT_NAME,
            '敦煌漠蓝新',
            'dynamic-ready-immediate-001'
        );
        self::assertSame('sent', $test['delivery_status']);
        self::assertSame('schedule_enabled', $test['schedule_status']);
        self::assertCount(1, $immediateCalls);
        self::assertSame((int)$target['record']['id'], $test['dispatch']['operating_target_record_id']);
        self::assertSame(1, $test['dispatch']['snapshot_revision_no']);
        self::assertSame(64, strlen((string)$test['dispatch']['payload_fingerprint']));
        self::assertSame('wecom_business_success', $test['dispatch']['result_code']);

        $scheduledCalls = [];
        $scheduler = new ManualNotificationScheduleService(
            static function (
                int $hotelId,
                int $robotId,
                array $payload,
                array $context
            ) use (&$scheduledCalls): array {
                $scheduledCalls[] = [$hotelId, $robotId, $payload, $context];
                return [
                    'delivery_status' => 'sent',
                    'sent_count' => 1,
                    'failed_count' => 0,
                ];
            }
        );
        $run = $scheduler->runDue(
            new DateTimeImmutable($today . ' 18:01:00', new DateTimeZone('Asia/Shanghai')),
            true,
            ManualNotificationScheduleService::MODE_TEST,
            100,
            80
        );
        self::assertSame(1, $run['sent_count']);
        self::assertSame(0, $run['blocked_count']);
        self::assertCount(1, $scheduledCalls);
        self::assertStringContainsString(
            '企业微信测试群定时真实投递',
            $scheduledCalls[0][2]['markdown']['content']
        );

        $history = $notifications->dispatchHistory(80, 80);
        self::assertSame(2, $history['total']);
        self::assertSame(['scheduled', 'immediate_test'], array_column($history['list'], 'request_kind'));
        self::assertSame([1, 1], array_column($history['list'], 'attempt_count'));
        self::assertSame(2, Db::name('manual_notification_dispatch_attempts')->count());
        self::assertSame('completed', Db::name('manual_notification_schedule_runs')->value('status'));
    }

    public function testBlockedAccommodationTargetPersistsGateWithoutCallingSender(): void
    {
        $today = $this->today();
        (new OperatingTargetService())->save(80, 80, 7, [
            'target_date' => $today,
            'target_revenue' => 12000,
            'actual_revenue' => 10135.29,
            'sold_room_nights' => 16,
            'sellable_room_nights' => 16,
            'fact_scope' => 'accommodation_room_fee',
            'source_type' => 'pms',
            'source_reference' => '订单来了住宿数据中心 / capture:902',
            'quality_status' => 'verified',
            'quality_reason' => '住宿房费汇总与房费明细已对账并完成数据库回读。',
            'fact_captured_at' => $today . ' 12:00:00',
        ]);
        $calls = [];
        $service = new ManualNotificationService(
            static function () use (&$calls): array {
                $calls[] = true;
                return ['delivery_status' => 'sent'];
            }
        );
        $saved = $service->save(80, 80, 7, '敦煌漠蓝新', $this->notificationInput($today));
        $result = $service->testPush(
            80,
            80,
            (int)$saved['record']['id'],
            7,
            true,
            1,
            ManualNotificationService::TEST_ROBOT_NAME,
            '敦煌漠蓝新',
            'dynamic-blocked-immediate-001'
        );

        self::assertSame('blocked', $result['delivery_status']);
        self::assertSame([], $calls);
        self::assertSame('blocked', $result['dispatch']['status']);
        self::assertSame(0, $result['dispatch']['attempt_count']);
        self::assertSame('awaiting_test', $result['schedule_status']);
        self::assertSame(0, Db::name('manual_notification_dispatch_attempts')->count());
        self::assertNull(
            Db::name('manual_notification_schedule_dispatches')->value('payload_snapshot_json')
        );
    }

    /** @return array<string, mixed> */
    private function notificationInput(string $today): array
    {
        return [
            'notification_type' => ManualNotificationService::DYNAMIC_REPORT_TYPE,
            'business_date' => $today,
            'title' => $today . ' 每日经营目标报告',
            'body' => "【每日经营目标报告】\n正文由同酒店、同日期的已保存经营目标和已核验经营事实动态生成。",
            'send_method' => 'wecom_test',
            'trigger_type' => 'daily_fixed_time',
            'planned_send_at' => $today . 'T18:00',
            'enabled' => true,
        ];
    }

    private function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))
            ->format('Y-m-d');
    }
}
