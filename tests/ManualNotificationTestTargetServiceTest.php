<?php
declare(strict_types=1);

namespace Tests;

use app\service\ManualNotificationTestTargetService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class ManualNotificationTestTargetServiceTest extends TestCase
{
    private static array $databaseConfig;
    private static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir()
            . '/manual_notification_test_target_' . getmypid() . '.sqlite';
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
        Db::execute(
            'CREATE TABLE IF NOT EXISTS competitor_wechat_robot ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'store_id INTEGER NOT NULL, '
            . 'notification_scope VARCHAR(40) NULL, '
            . 'name VARCHAR(120) NOT NULL, '
            . 'status INTEGER NOT NULL)'
        );
        Db::name('competitor_wechat_robot')->delete(true);
    }

    public function testResolvesExplicitCloudTestBindingWithoutReadingWebhook(): void
    {
        Db::name('competitor_wechat_robot')->insert([
            'id' => 2,
            'store_id' => 5,
            'notification_scope' => ManualNotificationTestTargetService::TEST_SCOPE,
            'name' => '宿析OS云端日报',
            'status' => 1,
        ]);

        $target = (new ManualNotificationTestTargetService())->resolve(5, 2);

        self::assertIsArray($target);
        self::assertSame(5, $target['hotel_id']);
        self::assertSame(2, $target['robot_id']);
        self::assertSame('宿析OS云端日报', $target['robot_name']);
        self::assertSame('verified_test_binding', $target['binding_status']);
        self::assertArrayNotHasKey('webhook', $target);
    }

    public function testRejectsUnscopedOrCrossHotelRobot(): void
    {
        Db::name('competitor_wechat_robot')->insert([
            'id' => 2,
            'store_id' => 5,
            'notification_scope' => null,
            'name' => 'Unverified group',
            'status' => 1,
        ]);

        $resolver = new ManualNotificationTestTargetService();
        self::assertNull($resolver->resolve(5, 2));
        self::assertNull($resolver->resolve(6, 2));
    }

    public function testRejectsAmbiguousTestBindingsUntilRobotIsExplicit(): void
    {
        Db::name('competitor_wechat_robot')->insert([
            'id' => 2,
            'store_id' => 5,
            'notification_scope' => ManualNotificationTestTargetService::TEST_SCOPE,
            'name' => '宿析OS云端日报',
            'status' => 1,
        ]);
        Db::name('competitor_wechat_robot')->insert([
            'id' => 3,
            'store_id' => 5,
            'notification_scope' => ManualNotificationTestTargetService::TEST_SCOPE,
            'name' => '另一测试群',
            'status' => 1,
        ]);

        $resolver = new ManualNotificationTestTargetService();
        self::assertNull($resolver->resolve(5));
        self::assertSame(2, $resolver->resolve(5, 2)['robot_id']);
        self::assertSame(3, $resolver->resolve(5, 3)['robot_id']);
    }
}
