<?php
declare(strict_types=1);

namespace Tests;

use app\controller\admin\CompetitorWechatRobotController;
use app\service\WechatNotificationBindingService;
use app\service\WechatRobotWebhookSecret;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class WechatNotificationBindingServiceTest extends TestCase
{
    private static array $databaseConfig;
    private static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . '/wechat_notification_binding_' . getmypid() . '.sqlite';
        @unlink(self::$databasePath);
        $config = self::$databaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = ['type' => 'sqlite', 'database' => self::$databasePath, 'prefix' => '', 'fields_strict' => false];
        Config::set($config, 'database');
        Db::connect(null, true);
        putenv('SUXI_SECRET_KEY_B64=' . base64_encode(str_repeat('w', 32)));
        putenv('SUXI_SECRET_KEY_ID=wechat-binding-test');
    }

    public static function tearDownAfterClass(): void
    {
        Config::set(self::$databaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$databasePath);
        putenv('SUXI_SECRET_KEY_B64');
        putenv('SUXI_SECRET_KEY_ID');
    }

    protected function setUp(): void
    {
        Db::execute('CREATE TABLE IF NOT EXISTS hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name VARCHAR(120) NOT NULL)');
        Db::execute('CREATE TABLE IF NOT EXISTS competitor_wechat_robot (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NULL, store_id INTEGER NOT NULL, owner_user_id INTEGER NULL, notification_scope VARCHAR(40) NULL, name VARCHAR(120) NOT NULL, webhook TEXT NOT NULL, status INTEGER NOT NULL, last_tested_at DATETIME NULL, last_test_status VARCHAR(24) NULL, create_time DATETIME NULL, update_time DATETIME NULL)');
        Db::execute('CREATE TABLE IF NOT EXISTS manual_notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, test_robot_id INTEGER NULL, enabled INTEGER NOT NULL, schedule_status VARCHAR(32) NOT NULL, last_test_status VARCHAR(32) NULL, last_test_message VARCHAR(255) NULL, last_tested_at DATETIME NULL, last_tested_by INTEGER NULL, update_time DATETIME NULL)');
        Db::name('manual_notifications')->delete(true);
        Db::name('competitor_wechat_robot')->delete(true);
        Db::name('hotels')->delete(true);
        Db::name('hotels')->insert(['id' => 80, 'tenant_id' => 9, 'name' => '测试酒店']);
    }

    public function testBindingIsEncryptedScopedAndUpsertsOnlyTheSameAccount(): void
    {
        $service = new WechatNotificationBindingService();
        $first = $service->bind(80, 7, '漠蓝新经营群', 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=first-key');
        $stored = (string)Db::name('competitor_wechat_robot')->where('id', $first['id'])->value('webhook');
        self::assertStringStartsWith('suxi-secret:v1:', $stored);
        self::assertStringNotContainsString('first-key', $stored);
        self::assertSame('configured', $service->status(80, 7)['binding_status']);
        self::assertSame('binding_missing', $service->status(80, 8)['binding_status']);
        self::assertSame(9, (int)Db::name('competitor_wechat_robot')
            ->where('id', $first['id'])
            ->value('tenant_id'));

        Db::name('competitor_wechat_robot')->where('id', $first['id'])->update(['tenant_id' => 8]);
        $updated = $service->bind(80, 7, '漠蓝新新群', 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=second-key');
        self::assertSame($first['id'], $updated['id']);
        self::assertSame(1, (int)Db::name('competitor_wechat_robot')->count());
        self::assertSame(9, (int)Db::name('competitor_wechat_robot')
            ->where('id', $first['id'])
            ->value('tenant_id'));
        self::assertSame('pending', $updated['last_test_status']);
        self::assertSame('https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=******', $updated['webhook_masked']);
    }

    public function testPersonalWebhookChangeInvalidatesOnlyEnabledPlansThatReferenceTheRobot(): void
    {
        $service = new WechatNotificationBindingService();
        $binding = $service->bind(
            80,
            7,
            '个人经营群',
            'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=personal-first'
        );
        $enabledPlanId = (int)Db::name('manual_notifications')->insertGetId([
            'test_robot_id' => $binding['id'],
            'enabled' => 1,
            'schedule_status' => 'schedule_enabled',
            'last_test_status' => 'sent',
            'last_test_message' => 'ok',
            'last_tested_at' => '2026-07-28 10:00:00',
            'last_tested_by' => 7,
            'update_time' => '2026-07-28 10:00:00',
        ]);
        $disabledPlanId = (int)Db::name('manual_notifications')->insertGetId([
            'test_robot_id' => $binding['id'],
            'enabled' => 0,
            'schedule_status' => 'schedule_enabled',
            'last_test_status' => 'sent',
            'last_test_message' => 'ok',
            'last_tested_at' => '2026-07-28 10:00:00',
            'last_tested_by' => 7,
            'update_time' => '2026-07-28 10:00:00',
        ]);
        Db::name('competitor_wechat_robot')->where('id', $binding['id'])->update([
            'last_test_status' => 'sent',
            'last_tested_at' => '2026-07-28 10:00:00',
        ]);

        $service->bind(
            80,
            7,
            '个人经营群',
            'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=personal-first'
        );
        self::assertSame(
            'schedule_enabled',
            Db::name('manual_notifications')->where('id', $enabledPlanId)->value('schedule_status')
        );
        self::assertSame(
            'sent',
            Db::name('competitor_wechat_robot')
                ->where('id', $binding['id'])
                ->value('last_test_status')
        );

        $service->bind(
            80,
            7,
            '个人经营群',
            'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=personal-second'
        );

        $enabled = Db::name('manual_notifications')->where('id', $enabledPlanId)->find();
        self::assertSame('awaiting_test', $enabled['schedule_status']);
        self::assertSame('never_tested', $enabled['last_test_status']);
        self::assertNull($enabled['last_test_message']);
        self::assertNull($enabled['last_tested_at']);
        self::assertNull($enabled['last_tested_by']);
        self::assertSame(
            'pending',
            Db::name('competitor_wechat_robot')
                ->where('id', $binding['id'])
                ->value('last_test_status')
        );
        self::assertNull(
            Db::name('competitor_wechat_robot')
                ->where('id', $binding['id'])
                ->value('last_tested_at')
        );
        self::assertSame(
            'schedule_enabled',
            Db::name('manual_notifications')->where('id', $disabledPlanId)->value('schedule_status')
        );
    }

    public function testRejectsNonWechatWebhook(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new WechatNotificationBindingService())->bind(80, 7, '测试群', 'https://example.com/webhook?key=nope');
    }

    public function testPersonalRobotTestKeepsTransactionOpenAndUpdatesOnlyTheExactAccount(): void
    {
        $bindingA = (new WechatNotificationBindingService())->bind(
            80,
            7,
            '账号 A 通知群',
            'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=account-a'
        );
        $bindingB = (new WechatNotificationBindingService())->bind(
            80,
            8,
            '账号 B 通知群',
            'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=account-b'
        );
        $transactionObserved = false;
        $testedRobotIds = [];
        $successTester = new WechatNotificationBindingService(
            static function (
                int $hotelId,
                array $payload,
                array $robotIds,
                array $binding
            ) use (&$transactionObserved, &$testedRobotIds): array {
                $transactionObserved = Db::connect()->getPdo()->inTransaction();
                $testedRobotIds = $robotIds;
                self::assertSame(80, $hotelId);
                self::assertSame('markdown', $payload['msgtype']);
                self::assertSame(7, (int)$binding['owner_user_id']);
                return ['delivery_status' => 'sent'];
            }
        );

        $result = $successTester->test(80, 7);
        self::assertTrue($transactionObserved);
        self::assertSame([(int)$bindingA['id']], $testedRobotIds);
        self::assertSame('sent', $result['delivery']['delivery_status']);
        self::assertSame('sent', $result['binding']['last_test_status']);
        self::assertNotNull($result['binding']['last_tested_at']);
        self::assertSame(
            'pending',
            Db::name('competitor_wechat_robot')->where('id', $bindingB['id'])->value('last_test_status')
        );
        self::assertNull(
            Db::name('competitor_wechat_robot')->where('id', $bindingB['id'])->value('last_tested_at')
        );

        $failureTester = new WechatNotificationBindingService(
            static fn(): array => ['delivery_status' => 'failed']
        );
        $failure = $failureTester->test(80, 8);
        self::assertSame('failed', $failure['binding']['last_test_status']);
        self::assertNotNull($failure['binding']['last_tested_at']);
    }

    public function testPersonalRobotStaleDeliveryCannotCertifyAChangedWebhook(): void
    {
        $binding = (new WechatNotificationBindingService())->bind(
            80,
            7,
            '账号通知群',
            'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=account-old'
        );
        $replacement = (new WechatRobotWebhookSecret())->protect(
            'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=account-new',
            (int)$binding['id']
        );
        $tester = new WechatNotificationBindingService(
            static function () use ($binding, $replacement): array {
                self::assertTrue(Db::connect()->getPdo()->inTransaction());
                Db::name('competitor_wechat_robot')->where('id', $binding['id'])->update([
                    'webhook' => $replacement,
                    'last_test_status' => 'pending',
                    'last_tested_at' => null,
                ]);
                return ['delivery_status' => 'sent'];
            }
        );

        $result = $tester->test(80, 7);
        self::assertSame('binding_changed', $result['delivery']['delivery_status']);
        self::assertSame('sent', $result['delivery']['message_delivery_status']);
        self::assertSame('pending', $result['binding']['last_test_status']);
        self::assertNull($result['binding']['last_tested_at']);
        self::assertSame(
            'pending',
            Db::name('competitor_wechat_robot')->where('id', $binding['id'])->value('last_test_status')
        );
    }

    public function testAdminRobotQueryExcludesAccountOwnedBindings(): void
    {
        $legacySharedId = (int)Db::name('competitor_wechat_robot')->insertGetId([
            'store_id' => 80,
            'owner_user_id' => null,
            'notification_scope' => null,
            'name' => '历史门店共享群',
            'webhook' => 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=legacy-shared-key',
            'status' => 1,
        ]);
        $adminSharedId = (int)Db::name('competitor_wechat_robot')->insertGetId([
            'store_id' => 80,
            'owner_user_id' => null,
            'notification_scope' => 'admin_shared',
            'name' => '新门店共享群',
            'webhook' => 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=admin-shared-key',
            'status' => 1,
        ]);
        $accountBindingId = (int)Db::name('competitor_wechat_robot')->insertGetId([
            'store_id' => 80,
            'owner_user_id' => 7,
            'notification_scope' => 'account_onboarding',
            'name' => '账号自己的通知群',
            'webhook' => 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=account-owned-key',
            'status' => 1,
        ]);

        $controller = (new ReflectionClass(CompetitorWechatRobotController::class))
            ->newInstanceWithoutConstructor();
        $queryMethod = new ReflectionMethod(CompetitorWechatRobotController::class, 'adminManagedRobotQuery');
        $rows = $queryMethod->invoke($controller)->order('id', 'asc')->select()->toArray();
        self::assertSame([$legacySharedId, $adminSharedId], array_map(
            static fn(array $row): int => (int)$row['id'],
            $rows
        ));

        $findMethod = new ReflectionMethod(CompetitorWechatRobotController::class, 'findAdminManagedRobot');
        self::assertSame($adminSharedId, (int)$findMethod->invoke($controller, $adminSharedId)['id']);
        self::assertNull($findMethod->invoke($controller, $accountBindingId));
    }

    public function testAdminSharedRobotPersistsTenantAndWebhookChangeInvalidatesItsEnabledPlan(): void
    {
        $controller = (new ReflectionClass(CompetitorWechatRobotController::class))
            ->newInstanceWithoutConstructor();
        $insert = new ReflectionMethod(CompetitorWechatRobotController::class, 'insertProtectedRobot');
        $robotId = (int)$insert->invoke($controller, [
            'store_id' => 80,
            'owner_user_id' => null,
            'notification_scope' => 'admin_shared',
            'name' => '管理员共享群',
            'status' => 1,
            'create_time' => '2026-07-28 10:00:00',
        ], 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=shared-first');
        self::assertSame(
            9,
            (int)Db::name('competitor_wechat_robot')->where('id', $robotId)->value('tenant_id')
        );
        $planId = (int)Db::name('manual_notifications')->insertGetId([
            'test_robot_id' => $robotId,
            'enabled' => 1,
            'schedule_status' => 'schedule_enabled',
            'last_test_status' => 'sent',
            'last_test_message' => 'ok',
            'last_tested_at' => '2026-07-28 10:00:00',
            'last_tested_by' => 1,
            'update_time' => '2026-07-28 10:00:00',
        ]);
        Db::name('competitor_wechat_robot')->where('id', $robotId)->update(['tenant_id' => 8]);
        $robot = Db::name('competitor_wechat_robot')->where('id', $robotId)->find();
        $resolve = new ReflectionMethod(
            CompetitorWechatRobotController::class,
            'resolveStoredRobotWebhookForUpdate'
        );
        $changed = new ReflectionMethod(
            CompetitorWechatRobotController::class,
            'robotWebhookChanged'
        );
        $input = ['webhook' => 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=shared-second'];
        $storedWebhook = (string)$resolve->invoke($controller, $input, $robot);
        $webhookChanged = (bool)$changed->invoke($controller, $input, $robot);
        self::assertTrue($webhookChanged);
        $persist = new ReflectionMethod(
            CompetitorWechatRobotController::class,
            'persistAdminManagedRobotUpdate'
        );
        $persist->invoke($controller, $robotId, [
            'store_id' => 80,
            'name' => '管理员共享群',
            'webhook' => $storedWebhook,
            'status' => 1,
        ], $webhookChanged);

        $plan = Db::name('manual_notifications')->where('id', $planId)->find();
        self::assertSame(
            9,
            (int)Db::name('competitor_wechat_robot')->where('id', $robotId)->value('tenant_id')
        );
        self::assertSame(
            'pending',
            Db::name('competitor_wechat_robot')->where('id', $robotId)->value('last_test_status')
        );
        self::assertNull(
            Db::name('competitor_wechat_robot')->where('id', $robotId)->value('last_tested_at')
        );
        self::assertSame('awaiting_test', $plan['schedule_status']);
        self::assertSame('never_tested', $plan['last_test_status']);
        self::assertNull($plan['last_test_message']);
    }

    public function testAdminSingleRobotTestsPersistSuccessAndFailureWithoutCrossingScopes(): void
    {
        $controller = $this->adminController();
        $sentRobotId = $this->insertAdminRobot($controller, '共享成功群', 'shared-sent');
        $failedRobotId = $this->insertAdminRobot($controller, '共享失败群', 'shared-failed');
        $personal = (new WechatNotificationBindingService())->bind(
            80,
            7,
            '个人通知群',
            'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=personal-only'
        );
        $method = new ReflectionMethod(CompetitorWechatRobotController::class, 'testAdminManagedRobot');
        $transactionObserved = false;

        $sent = $method->invoke(
            $controller,
            $sentRobotId,
            ['msgtype' => 'markdown', 'markdown' => ['content' => 'test']],
            static function (string $url, array $payload, array $robot) use (&$transactionObserved): array {
                $transactionObserved = Db::connect()->getPdo()->inTransaction();
                self::assertStringContainsString('key=shared-sent', $url);
                self::assertSame('共享成功群', $robot['name']);
                return ['success' => true];
            }
        );
        $failed = $method->invoke(
            $controller,
            $failedRobotId,
            ['msgtype' => 'markdown', 'markdown' => ['content' => 'test']],
            static fn(): array => ['success' => false, 'error' => 'mock_rejected']
        );

        self::assertTrue($transactionObserved);
        self::assertTrue($sent['success']);
        self::assertSame('sent', $sent['test_status']);
        self::assertTrue($sent['state_persisted']);
        self::assertFalse($failed['success']);
        self::assertSame('failed', $failed['test_status']);
        self::assertTrue($failed['state_persisted']);
        self::assertSame(
            'sent',
            Db::name('competitor_wechat_robot')->where('id', $sentRobotId)->value('last_test_status')
        );
        self::assertNotNull(
            Db::name('competitor_wechat_robot')->where('id', $sentRobotId)->value('last_tested_at')
        );
        self::assertSame(
            'failed',
            Db::name('competitor_wechat_robot')->where('id', $failedRobotId)->value('last_test_status')
        );
        self::assertSame(
            'pending',
            Db::name('competitor_wechat_robot')->where('id', $personal['id'])->value('last_test_status')
        );
    }

    public function testAdminStoreTestWritesEachRobotResultAndExcludesPersonalBindings(): void
    {
        $controller = $this->adminController();
        $sentRobotId = $this->insertAdminRobot($controller, '门店共享一群', 'store-first');
        $failedRobotId = $this->insertAdminRobot($controller, '门店共享二群', 'store-second');
        $personal = (new WechatNotificationBindingService())->bind(
            80,
            7,
            '个人通知群',
            'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=personal-store'
        );
        $transactionObservations = [];
        $method = new ReflectionMethod(CompetitorWechatRobotController::class, 'sendPayloadToStore');
        $delivery = $method->invoke(
            $controller,
            80,
            ['msgtype' => 'markdown', 'markdown' => ['content' => 'batch-test']],
            static function (string $url, array $payload, array $robot) use (&$transactionObservations): array {
                $transactionObservations[] = Db::connect()->getPdo()->inTransaction();
                return (string)$robot['name'] === '门店共享一群'
                    ? ['success' => true]
                    : ['success' => false, 'error' => 'mock_rejected'];
            }
        );

        self::assertSame([true, true], $transactionObservations);
        self::assertSame('partial', $delivery['delivery_status']);
        self::assertSame(2, $delivery['robot_count']);
        self::assertSame(1, $delivery['sent_count']);
        self::assertSame(1, $delivery['failed_count']);
        self::assertCount(2, $delivery['results']);
        self::assertSame(
            'sent',
            Db::name('competitor_wechat_robot')->where('id', $sentRobotId)->value('last_test_status')
        );
        self::assertSame(
            'failed',
            Db::name('competitor_wechat_robot')->where('id', $failedRobotId)->value('last_test_status')
        );
        self::assertNotNull(
            Db::name('competitor_wechat_robot')->where('id', $sentRobotId)->value('last_tested_at')
        );
        self::assertNotNull(
            Db::name('competitor_wechat_robot')->where('id', $failedRobotId)->value('last_tested_at')
        );
        self::assertSame(
            'pending',
            Db::name('competitor_wechat_robot')->where('id', $personal['id'])->value('last_test_status')
        );
    }

    public function testAdminStaleDeliveryCannotCertifyAChangedWebhook(): void
    {
        $controller = $this->adminController();
        $robotId = $this->insertAdminRobot($controller, '门店共享群', 'admin-old');
        $replacement = (new WechatRobotWebhookSecret())->protect(
            'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=admin-new',
            $robotId
        );
        $method = new ReflectionMethod(CompetitorWechatRobotController::class, 'testAdminManagedRobot');
        $result = $method->invoke(
            $controller,
            $robotId,
            ['msgtype' => 'markdown', 'markdown' => ['content' => 'test']],
            static function () use ($robotId, $replacement): array {
                self::assertTrue(Db::connect()->getPdo()->inTransaction());
                Db::name('competitor_wechat_robot')->where('id', $robotId)->update([
                    'webhook' => $replacement,
                    'last_test_status' => 'pending',
                    'last_tested_at' => null,
                ]);
                return ['success' => true];
            }
        );

        self::assertFalse($result['success']);
        self::assertFalse($result['state_persisted']);
        self::assertSame('binding_changed', $result['test_status']);
        self::assertSame(
            'pending',
            Db::name('competitor_wechat_robot')->where('id', $robotId)->value('last_test_status')
        );
        self::assertNull(
            Db::name('competitor_wechat_robot')->where('id', $robotId)->value('last_tested_at')
        );
    }

    private function adminController(): CompetitorWechatRobotController
    {
        return (new ReflectionClass(CompetitorWechatRobotController::class))
            ->newInstanceWithoutConstructor();
    }

    private function insertAdminRobot(
        CompetitorWechatRobotController $controller,
        string $name,
        string $key
    ): int {
        $insert = new ReflectionMethod(CompetitorWechatRobotController::class, 'insertProtectedRobot');
        $robotId = (int)$insert->invoke($controller, [
            'store_id' => 80,
            'owner_user_id' => null,
            'notification_scope' => 'admin_shared',
            'name' => $name,
            'status' => 1,
            'last_test_status' => 'pending',
            'create_time' => '2026-07-28 10:00:00',
        ], 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=' . $key);
        self::assertGreaterThan(0, $robotId);
        return $robotId;
    }
}
