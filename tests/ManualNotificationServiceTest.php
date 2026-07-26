<?php
declare(strict_types=1);

namespace Tests;

use app\service\ManualNotificationService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class ManualNotificationServiceTest extends TestCase
{
    private static array $databaseConfig;
    private static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . '/manual_notification_' . getmypid() . '.sqlite';
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
        Db::execute('CREATE TABLE IF NOT EXISTS manual_notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, notification_type VARCHAR(40) NOT NULL, template_type VARCHAR(40) NOT NULL, business_date VARCHAR(10) NOT NULL, title VARCHAR(120) NOT NULL, body TEXT NOT NULL, send_method VARCHAR(32) NOT NULL, trigger_type VARCHAR(32) NOT NULL, planned_send_at DATETIME NULL, enabled INTEGER NOT NULL, schedule_status VARCHAR(32) NOT NULL, last_test_status VARCHAR(32) NOT NULL, last_test_message VARCHAR(255) NULL, last_tested_at DATETIME NULL, last_tested_by INTEGER NULL, test_robot_id INTEGER NULL, test_robot_name VARCHAR(120) NULL, created_by INTEGER NOT NULL, create_time DATETIME NOT NULL, update_time DATETIME NOT NULL)');
        Db::execute('CREATE TABLE IF NOT EXISTS competitor_wechat_robot (id INTEGER PRIMARY KEY AUTOINCREMENT, store_id INTEGER NOT NULL, name VARCHAR(120) NOT NULL, webhook TEXT NULL, status INTEGER NOT NULL)');
        Db::execute('CREATE TABLE IF NOT EXISTS manual_notification_schedule_dispatches (id INTEGER PRIMARY KEY AUTOINCREMENT, notification_id INTEGER NOT NULL, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, dispatch_window VARCHAR(32) NOT NULL, delivery_mode VARCHAR(16) NOT NULL, trigger_type VARCHAR(32) NOT NULL, request_kind VARCHAR(32) NOT NULL, business_date VARCHAR(10) NULL, payload_fingerprint VARCHAR(64) NULL, operating_target_record_id INTEGER NULL, snapshot_revision_no INTEGER NULL, render_contract_version VARCHAR(48) NULL, payload_snapshot_json TEXT NULL, attempt_count INTEGER NOT NULL, max_attempts INTEGER NOT NULL, next_retry_at DATETIME NULL, last_attempt_at DATETIME NULL, response_reference VARCHAR(120) NULL, robot_id INTEGER NOT NULL, robot_name VARCHAR(120) NOT NULL, status VARCHAR(24) NOT NULL, result_code VARCHAR(64) NOT NULL, result_message VARCHAR(255) NULL, claimed_at DATETIME NOT NULL, dispatched_at DATETIME NULL, create_time DATETIME NOT NULL, update_time DATETIME NOT NULL, UNIQUE(notification_id, dispatch_window, delivery_mode))');
        Db::execute('CREATE TABLE IF NOT EXISTS manual_notification_dispatch_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, dispatch_id INTEGER NOT NULL, notification_id INTEGER NOT NULL, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, attempt_no INTEGER NOT NULL, request_kind VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL, result_code VARCHAR(64) NOT NULL, result_message VARCHAR(255) NULL, payload_fingerprint VARCHAR(64) NULL, response_reference VARCHAR(120) NULL, attempted_at DATETIME NOT NULL, create_time DATETIME NOT NULL, UNIQUE(dispatch_id, attempt_no))');
        Db::name('manual_notification_dispatch_attempts')->delete(true);
        Db::name('manual_notification_schedule_dispatches')->delete(true);
        Db::name('manual_notifications')->delete(true);
        Db::name('competitor_wechat_robot')->delete(true);
    }

    public function testTemplatesUseChineseMissingAndPendingLabels(): void
    {
        $metadata = (new ManualNotificationService())->metadata('2026-07-26');

        self::assertSame(
            ['operating_target_report', 'today_revenue_management', 'future_room_status', 'daily_review', 'blank_custom'],
            array_column($metadata['types'], 'key')
        );
        self::assertStringContainsString('缺失', $metadata['types'][0]['body']);
        self::assertStringContainsString('待配置', $metadata['types'][2]['body']);
        self::assertContains('{酒店名称}', $metadata['variables']);
        self::assertSame('not_deployed', $metadata['scheduler_status']);
    }

    public function testSaveReadsBackHistoryAndKeepsUnknownValuesExplicit(): void
    {
        $service = new ManualNotificationService();
        $saved = $service->save(9, 80, 7, '敦煌漠蓝新', $this->validInput([
            'enabled' => true,
            'trigger_type' => 'daily_fixed_time',
            'planned_send_at' => '2026-07-26T18:00',
        ]));

        self::assertTrue($saved['readback_verified']);
        self::assertSame('awaiting_test', $saved['record']['schedule_status']);
        self::assertSame('等待一次真实测试成功后启用', $saved['preview']['schedule_status_label']);
        self::assertStringContainsString('敦煌漠蓝新', $saved['preview']['payload']['markdown']['content']);
        self::assertStringContainsString('未取得', $saved['record']['body']);
        self::assertStringNotContainsString('：0', $saved['record']['body']);

        $history = $service->history(9, 80);
        self::assertSame(1, $history['total']);
        self::assertSame($saved['record']['id'], $history['list'][0]['id']);

        $updated = $service->save(9, 80, 7, '敦煌漠蓝新', $this->validInput([
            'id' => $saved['record']['id'],
            'title' => '更新后的今日收益管理',
            'body' => "【今日收益管理】\n酒店：{酒店名称}\n经营结果：未取得\n下一步：待配置",
        ]));
        self::assertSame('updated', $updated['operation']);
        self::assertSame($saved['record']['id'], $updated['record']['id']);
        self::assertSame('更新后的今日收益管理', $updated['record']['title']);
        self::assertSame(1, $service->history(9, 80)['total']);

        $this->expectException(\RuntimeException::class);
        $service->read(10, 80, (int)$saved['record']['id']);
    }

    public function testExplicitTestPushSucceedsOnlyThroughInjectedDispatcher(): void
    {
        Db::name('competitor_wechat_robot')->insert([
            'id' => ManualNotificationService::TEST_ROBOT_ID,
            'store_id' => 80,
            'name' => ManualNotificationService::TEST_ROBOT_NAME,
            'webhook' => 'not-read-by-service-test',
            'status' => 1,
        ]);
        $calls = [];
        $service = new ManualNotificationService(
            static function (int $hotelId, int $robotId, array $payload) use (&$calls): array {
                $calls[] = [$hotelId, $robotId, $payload];
                return ['delivery_status' => 'sent'];
            }
        );
        $saved = $service->save(9, 80, 7, '敦煌漠蓝新', $this->validInput());

        $result = $service->testPush(
            9,
            80,
            (int)$saved['record']['id'],
            7,
            true,
            ManualNotificationService::TEST_ROBOT_ID,
            ManualNotificationService::TEST_ROBOT_NAME,
            '敦煌漠蓝新',
            'service-test-success-1'
        );

        self::assertSame('sent', $result['delivery_status']);
        self::assertCount(1, $calls);
        self::assertSame([80, 1], array_slice($calls[0], 0, 2));
        self::assertStringContainsString('明确点击的测试推送', $calls[0][2]['markdown']['content']);
        self::assertFalse($result['formal_group_delivery_allowed']);
        self::assertSame(1, Db::name('manual_notification_dispatch_attempts')->count());

        $replay = $service->testPush(
            9,
            80,
            (int)$saved['record']['id'],
            7,
            true,
            ManualNotificationService::TEST_ROBOT_ID,
            ManualNotificationService::TEST_ROBOT_NAME,
            '敦煌漠蓝新',
            'service-test-success-1'
        );
        self::assertTrue($replay['idempotent_replay']);
        self::assertCount(1, $calls);
    }

    public function testFailedDispatcherIsPersistedWithoutFalseSuccess(): void
    {
        Db::name('competitor_wechat_robot')->insert([
            'id' => ManualNotificationService::TEST_ROBOT_ID,
            'store_id' => 80,
            'name' => ManualNotificationService::TEST_ROBOT_NAME,
            'webhook' => 'not-read-by-service-test',
            'status' => 1,
        ]);
        $service = new ManualNotificationService(
            static fn(): array => ['delivery_status' => 'failed']
        );
        $saved = $service->save(9, 80, 7, '敦煌漠蓝新', $this->validInput());
        $result = $service->testPush(
            9,
            80,
            (int)$saved['record']['id'],
            7,
            true,
            ManualNotificationService::TEST_ROBOT_ID,
            ManualNotificationService::TEST_ROBOT_NAME,
            '敦煌漠蓝新',
            'service-test-failed-1'
        );

        self::assertSame('failed', $result['delivery_status']);
        self::assertSame(
            'failed',
            Db::name('manual_notifications')->where('id', $saved['record']['id'])->value('last_test_status')
        );
        self::assertStringContainsString('正式群未触发', $result['message']);
    }

    public function testTestPushRejectsMissingConfirmationAndWrongHotel(): void
    {
        $service = new ManualNotificationService();
        $saved = $service->save(9, 80, 7, '敦煌漠蓝新', $this->validInput());

        try {
            $service->testPush(
                9,
                80,
                (int)$saved['record']['id'],
                7,
                false,
                ManualNotificationService::TEST_ROBOT_ID,
                ManualNotificationService::TEST_ROBOT_NAME,
                '敦煌漠蓝新',
                'missing-confirmation'
            );
            self::fail('Missing confirmation must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('manual_notification_test_confirmation_required', $error->getMessage());
        }

        try {
            $service->testPush(
                9,
                80,
                (int)$saved['record']['id'],
                7,
                true,
                2,
                ManualNotificationService::TEST_ROBOT_NAME,
                '敦煌漠蓝新',
                'wrong-robot-id'
            );
            self::fail('Any robot outside the bound id 1 must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('manual_notification_test_target_forbidden', $error->getMessage());
        }

        try {
            $service->testPush(
                9,
                80,
                (int)$saved['record']['id'],
                7,
                true,
                ManualNotificationService::TEST_ROBOT_ID,
                '任意其他机器人',
                '敦煌漠蓝新',
                'wrong-robot-name'
            );
            self::fail('Any robot name outside the bound 漠蓝测试 must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('manual_notification_test_target_forbidden', $error->getMessage());
        }

        $previewOnly = $service->save(9, 80, 7, '敦煌漠蓝新', $this->validInput([
            'send_method' => 'manual_preview',
        ]));
        try {
            $service->testPush(
                9,
                80,
                (int)$previewOnly['record']['id'],
                7,
                true,
                ManualNotificationService::TEST_ROBOT_ID,
                ManualNotificationService::TEST_ROBOT_NAME,
                '敦煌漠蓝新',
                'preview-method-test'
            );
            self::fail('Preview-only notifications must not enter the test dispatcher.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('manual_notification_test_method_forbidden', $error->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('manual_notification_test_target_forbidden');
        $service->testPush(
            9,
            81,
            (int)$saved['record']['id'],
            7,
            true,
            ManualNotificationService::TEST_ROBOT_ID,
            ManualNotificationService::TEST_ROBOT_NAME,
            '其他酒店',
            'wrong-hotel-test'
        );
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function validInput(array $overrides = []): array
    {
        return array_replace([
            'notification_type' => 'today_revenue_management',
            'business_date' => '2026-07-26',
            'title' => '{经营日期} 今日收益管理',
            'body' => "【今日收益管理】\n酒店：{酒店名称}\n今日目标：未取得\n建议动作：待配置",
            'send_method' => 'wecom_test',
            'trigger_type' => 'manual_test',
            'planned_send_at' => '',
            'enabled' => false,
        ], $overrides);
    }
}
