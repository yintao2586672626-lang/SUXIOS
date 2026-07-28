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
        Db::execute('CREATE TABLE IF NOT EXISTS competitor_wechat_robot (id INTEGER PRIMARY KEY AUTOINCREMENT, store_id INTEGER NOT NULL, name VARCHAR(120) NOT NULL, webhook TEXT NULL, status INTEGER NOT NULL, owner_user_id INTEGER NULL, notification_scope VARCHAR(40) NULL)');
        Db::execute('CREATE TABLE IF NOT EXISTS hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name VARCHAR(120) NOT NULL)');
        Db::execute('CREATE TABLE IF NOT EXISTS manual_notification_schedule_dispatches (id INTEGER PRIMARY KEY AUTOINCREMENT, notification_id INTEGER NOT NULL, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, dispatch_window VARCHAR(32) NOT NULL, delivery_mode VARCHAR(16) NOT NULL, trigger_type VARCHAR(32) NOT NULL, request_kind VARCHAR(32) NOT NULL, business_date VARCHAR(10) NULL, payload_fingerprint VARCHAR(64) NULL, operating_target_record_id INTEGER NULL, snapshot_revision_no INTEGER NULL, render_contract_version VARCHAR(48) NULL, payload_snapshot_json TEXT NULL, attempt_count INTEGER NOT NULL, max_attempts INTEGER NOT NULL, next_retry_at DATETIME NULL, last_attempt_at DATETIME NULL, response_reference VARCHAR(120) NULL, robot_id INTEGER NOT NULL, robot_name VARCHAR(120) NOT NULL, status VARCHAR(24) NOT NULL, result_code VARCHAR(64) NOT NULL, result_message VARCHAR(255) NULL, claimed_at DATETIME NOT NULL, dispatched_at DATETIME NULL, create_time DATETIME NOT NULL, update_time DATETIME NOT NULL, UNIQUE(notification_id, dispatch_window, delivery_mode))');
        Db::execute('CREATE TABLE IF NOT EXISTS manual_notification_dispatch_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, dispatch_id INTEGER NOT NULL, notification_id INTEGER NOT NULL, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, attempt_no INTEGER NOT NULL, request_kind VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL, result_code VARCHAR(64) NOT NULL, result_message VARCHAR(255) NULL, payload_fingerprint VARCHAR(64) NULL, response_reference VARCHAR(120) NULL, attempted_at DATETIME NOT NULL, create_time DATETIME NOT NULL, UNIQUE(dispatch_id, attempt_no))');
        Db::name('manual_notification_dispatch_attempts')->delete(true);
        Db::name('manual_notification_schedule_dispatches')->delete(true);
        Db::name('manual_notifications')->delete(true);
        Db::name('competitor_wechat_robot')->delete(true);
        Db::name('hotels')->delete(true);
        Db::name('hotels')->insert(['id' => 80, 'tenant_id' => 9, 'name' => '敦煌漠蓝新']);
    }

    public function testTemplatesUseChineseMissingAndPendingLabels(): void
    {
        $metadata = (new ManualNotificationService())->metadata('2026-07-26');

        self::assertSame(
            [
                'operating_daily_report',
                'operating_target_report',
                'ai_analysis_result',
                'anomaly_alert',
                'task_notification',
                'today_revenue_management',
                'future_room_status',
                'daily_review',
                'blank_custom',
            ],
            array_column($metadata['types'], 'key')
        );
        self::assertStringContainsString('缺失', $metadata['types'][0]['body']);
        self::assertStringContainsString('待配置', $metadata['types'][6]['body']);
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

    public function testFormalScheduleFailureAndExplicitRetryPersistRealAttempts(): void
    {
        Db::name('competitor_wechat_robot')->insert([
            'id' => 27,
            'store_id' => 80,
            'name' => '正式经营群',
            'webhook' => 'not-read-by-service-test',
            'status' => 1,
            'owner_user_id' => null,
            'notification_scope' => 'admin_shared',
        ]);
        $testService = new ManualNotificationService(
            static fn(): array => ['delivery_status' => 'sent', 'response_reference' => 'test:ok']
        );
        $saved = $testService->save(9, 80, 7, '敦煌漠蓝新', $this->validInput([
            'notification_type' => 'anomaly_alert',
            'title' => '价格规则异常',
            'body' => "异常编号：A-17\n业务服务正文：价格规则未回读",
            'send_method' => 'wecom_formal',
            'trigger_type' => 'daily_fixed_time',
            'planned_send_at' => '2026-07-26T18:00',
            'enabled' => true,
            'target_robot_id' => 27,
            'target_robot_name' => '正式经营群',
        ]));
        self::assertSame('awaiting_test', $saved['record']['schedule_status']);
        $tested = $testService->testPush(
            9,
            80,
            (int)$saved['record']['id'],
            7,
            true,
            27,
            '正式经营群',
            '敦煌漠蓝新',
            'formal-plan-test-001'
        );
        self::assertSame('schedule_enabled', $tested['schedule_status']);
        self::assertTrue($tested['formal_group_delivery_allowed']);

        $scheduled = (new ManualNotificationScheduleService(
            static fn(): array => [
                'delivery_status' => 'failed',
                'error' => 'provider_rejected',
            ]
        ))->runDue(
            new DateTimeImmutable('2026-07-26 18:01:00', new DateTimeZone('Asia/Shanghai')),
            true,
            ManualNotificationScheduleService::MODE_FORMAL
        );
        self::assertSame('dispatch_failed', $scheduled['status']);
        self::assertSame('failed', $scheduled['results'][0]['status']);
        $formalDispatchId = (int)$scheduled['results'][0]['dispatch_id'];
        self::assertNotNull(
            Db::name('manual_notification_schedule_dispatches')
                ->where('id', $formalDispatchId)
                ->value('next_retry_at')
        );

        $retried = (new ManualNotificationService(
            static fn(): array => [
                'delivery_status' => 'sent',
                'response_reference' => 'wecom:errcode=0',
            ]
        ))->retryDispatch(9, 80, $formalDispatchId, 7, true);
        self::assertSame('sent', $retried['delivery_status']);
        self::assertSame('formal', $retried['delivery_mode']);
        self::assertTrue($retried['formal_group_delivery_allowed']);
        self::assertSame(2, $retried['dispatch']['attempt_count']);
        self::assertSame(
            2,
            Db::name('manual_notification_dispatch_attempts')
                ->where('dispatch_id', $formalDispatchId)
                ->count()
        );
    }

    public function testOutcomeUnknownCanOnlyBeRetriedAfterExplicitConfirmation(): void
    {
        Db::name('competitor_wechat_robot')->insert([
            'id' => 27,
            'store_id' => 80,
            'name' => 'formal-operations-robot',
            'webhook' => 'not-read-by-service-test',
            'status' => 1,
            'owner_user_id' => null,
            'notification_scope' => 'admin_shared',
        ]);
        $activationService = new ManualNotificationService(
            static fn(): array => ['delivery_status' => 'sent', 'response_reference' => 'test:ok']
        );
        $saved = $activationService->save(9, 80, 7, 'lease-recovery-hotel', $this->validInput([
            'notification_type' => 'anomaly_alert',
            'template_type' => 'anomaly_alert',
            'title' => 'lease recovery alert',
            'body' => 'A persisted alert body',
            'send_method' => 'wecom_formal',
            'trigger_type' => 'daily_fixed_time',
            'planned_send_at' => '2026-07-26T18:00',
            'enabled' => true,
            'target_robot_id' => 27,
            'target_robot_name' => 'formal-operations-robot',
        ]));
        $activationService->testPush(
            9,
            80,
            (int)$saved['record']['id'],
            7,
            true,
            27,
            'formal-operations-robot',
            'lease-recovery-hotel',
            'lease-recovery-activation-test'
        );

        $ledger = new ManualNotificationDispatchLedgerService();
        $startedAt = new DateTimeImmutable(
            '2026-07-26 18:00:00',
            new DateTimeZone('Asia/Shanghai')
        );
        $claim = $ledger->claim(
            (int)$saved['record']['id'],
            9,
            80,
            '2026-07-26 18:00',
            ManualNotificationScheduleService::MODE_FORMAL,
            'daily_fixed_time',
            'scheduled',
            27,
            'formal-operations-robot',
            '2026-07-26',
            [
                'payload' => [
                    'msgtype' => 'markdown',
                    'markdown' => ['content' => 'persisted retry payload'],
                ],
            ],
            $startedAt
        );
        $attempt = $ledger->beginAttempt((int)$claim['dispatch']['id'], $startedAt);
        self::assertTrue($attempt['allowed']);
        self::assertSame(
            1,
            $ledger->recoverExpiredSending(
                $startedAt->modify('+3 minutes'),
                ManualNotificationScheduleService::MODE_FORMAL,
                80
            )
        );

        $calls = [];
        $retryService = new ManualNotificationService(
            static function (int $hotelId, int $robotId, array $payload, array $context) use (&$calls): array {
                $calls[] = [$hotelId, $robotId, $payload, $context];
                return [
                    'delivery_status' => 'sent',
                    'response_reference' => 'wecom:errcode=0',
                ];
            },
            null,
            $ledger
        );
        try {
            $retryService->retryDispatch(9, 80, (int)$claim['dispatch']['id'], 7, false);
            self::fail('An ambiguous delivery must require explicit operator confirmation.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame(
                'manual_notification_retry_confirmation_required',
                $error->getMessage()
            );
        }

        $retried = $retryService->retryDispatch(
            9,
            80,
            (int)$claim['dispatch']['id'],
            7,
            true
        );
        self::assertSame('sent', $retried['delivery_status']);
        self::assertCount(1, $calls);
        self::assertSame('explicit_retry', $calls[0][3]['request_kind']);
        self::assertSame(
            'explicit_retry',
            Db::name('manual_notification_dispatch_attempts')
                ->where('dispatch_id', (int)$claim['dispatch']['id'])
                ->where('attempt_no', 2)
                ->value('request_kind')
        );
        self::assertSame(2, $retried['dispatch']['attempt_count']);
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
