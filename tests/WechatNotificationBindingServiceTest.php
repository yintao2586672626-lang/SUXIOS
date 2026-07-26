<?php
declare(strict_types=1);

namespace Tests;

use app\controller\admin\CompetitorWechatRobotController;
use app\service\WechatNotificationBindingService;
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
        Db::execute('CREATE TABLE IF NOT EXISTS competitor_wechat_robot (id INTEGER PRIMARY KEY AUTOINCREMENT, store_id INTEGER NOT NULL, owner_user_id INTEGER NULL, notification_scope VARCHAR(40) NULL, name VARCHAR(120) NOT NULL, webhook TEXT NOT NULL, status INTEGER NOT NULL, last_tested_at DATETIME NULL, last_test_status VARCHAR(24) NULL, create_time DATETIME NULL, update_time DATETIME NULL)');
        Db::name('competitor_wechat_robot')->delete(true);
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

        $updated = $service->bind(80, 7, '漠蓝新新群', 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=second-key');
        self::assertSame($first['id'], $updated['id']);
        self::assertSame(1, (int)Db::name('competitor_wechat_robot')->count());
        self::assertSame('pending', $updated['last_test_status']);
        self::assertSame('https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=******', $updated['webhook_masked']);
    }

    public function testRejectsNonWechatWebhook(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new WechatNotificationBindingService())->bind(80, 7, '测试群', 'https://example.com/webhook?key=nope');
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
}
