<?php
declare(strict_types=1);

namespace Tests;

use app\service\HourlyHotelMonitorWechatService;
use app\service\WechatRobotDeliveryService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class HourlyHotelMonitorWechatScopeTest extends TestCase
{
    private static array $databaseConfig;
    private static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . '/hourly_monitor_wechat_scope_' . getmypid() . '.sqlite';
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
        Db::execute('CREATE TABLE hotels (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            name VARCHAR(120) NOT NULL,
            status INTEGER NOT NULL
        )');
        Db::execute('CREATE TABLE competitor_wechat_robot (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NULL,
            store_id INTEGER NOT NULL,
            owner_user_id INTEGER NULL,
            notification_scope VARCHAR(40) NULL,
            name VARCHAR(120) NOT NULL,
            webhook TEXT NULL,
            status INTEGER NOT NULL
        )');
    }

    public static function tearDownAfterClass(): void
    {
        Config::set(self::$databaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$databasePath);
    }

    protected function setUp(): void
    {
        Db::name('competitor_wechat_robot')->delete(true);
        Db::name('hotels')->delete(true);
        Db::name('hotels')->insert([
            'id' => 80,
            'tenant_id' => 9,
            'name' => '敦煌漠蓝新',
            'status' => 1,
        ]);
    }

    public function testFormalGuardAcceptsOnlyTenantMatchedAdminSharedRobot(): void
    {
        $robot = $this->insertRobot();
        $hotel = Db::name('hotels')->where('id', 80)->find();
        $method = new ReflectionMethod(HourlyHotelMonitorWechatService::class, 'assertFormalDeliveryTarget');
        $method->setAccessible(true);

        $method->invoke(
            new HourlyHotelMonitorWechatService(),
            $hotel,
            $robot,
            new WechatRobotDeliveryService()
        );

        self::addToAssertionCount(1);
    }

    public function testFormalGuardAcceptsExactAccountOwnedPolicyRobot(): void
    {
        $robot = $this->insertRobot([
            'owner_user_id' => 7,
            'notification_scope' => 'account_onboarding',
            'name' => '个人经营群',
        ]);
        $hotel = Db::name('hotels')->where('id', 80)->find();
        $method = new ReflectionMethod(HourlyHotelMonitorWechatService::class, 'assertFormalDeliveryTarget');
        $method->setAccessible(true);

        $method->invoke(
            new HourlyHotelMonitorWechatService(),
            $hotel,
            $robot,
            new WechatRobotDeliveryService(),
            7
        );

        self::addToAssertionCount(1);
    }

    public function testFormalGuardRejectsDifferentAccountOwner(): void
    {
        $robot = $this->insertRobot([
            'owner_user_id' => 7,
            'notification_scope' => 'account_onboarding',
            'name' => '个人经营群',
        ]);
        $hotel = Db::name('hotels')->where('id', 80)->find();
        $method = new ReflectionMethod(HourlyHotelMonitorWechatService::class, 'assertFormalDeliveryTarget');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hourly_formal_account_robot_scope_mismatch');
        $method->invoke(
            new HourlyHotelMonitorWechatService(),
            $hotel,
            $robot,
            new WechatRobotDeliveryService(),
            8
        );
    }

    /** @param array<string,mixed> $robot */
    #[DataProvider('forbiddenFormalRobotProvider')]
    public function testFormalRunRejectsTestAccountLegacyAndCrossTenantRobots(
        array $robot,
        string $expectedReason
    ): void {
        $this->insertRobot($robot);

        try {
            (new HourlyHotelMonitorWechatService())->run(80, 27, false, '2026-07-28 10:00:00', false);
            self::fail('The formal hourly target must be rejected before any data or webhook work.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame($expectedReason, $error->getMessage());
        }
    }

    /** @return iterable<string,array{0:array<string,mixed>,1:string}> */
    public static function forbiddenFormalRobotProvider(): iterable
    {
        yield 'test-named shared robot' => [
            ['name' => '正式群测试机器人'],
            'hourly_formal_test_robot_forbidden',
        ];
        yield 'english test-named shared robot' => [
            ['name' => 'formal test robot'],
            'hourly_formal_test_robot_forbidden',
        ];
        yield 'account-owned robot' => [
            [
                'owner_user_id' => 7,
                'notification_scope' => 'account_onboarding',
                'name' => '个人经营群',
            ],
            'hourly_formal_account_robot_forbidden',
        ];
        yield 'legacy unscoped shared robot' => [
            ['notification_scope' => null],
            'hourly_formal_robot_scope_forbidden',
        ];
        yield 'cross-tenant shared robot' => [
            ['tenant_id' => 10],
            'hourly_formal_robot_tenant_mismatch',
        ];
    }

    public function testFormalRunRejectsCrossStoreRobotBeforeDataRead(): void
    {
        $this->insertRobot(['store_id' => 81]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('未找到该门店启用中的目标机器人。');
        (new HourlyHotelMonitorWechatService())->run(80, 27, false, '2026-07-28 10:00:00', false);
    }

    public function testTestModeKeepsExistingNameBoundary(): void
    {
        $this->insertRobot(['name' => '正式经营群']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('每小时监控只能指定名称含“测试”的机器人');
        (new HourlyHotelMonitorWechatService())->run(80, 27, false, '2026-07-28 10:00:00', true);
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function insertRobot(array $overrides = []): array
    {
        $row = array_merge([
            'id' => 27,
            'tenant_id' => 9,
            'store_id' => 80,
            'owner_user_id' => null,
            'notification_scope' => 'admin_shared',
            'name' => '正式经营群',
            'webhook' => 'not-used-by-scope-test',
            'status' => 1,
        ], $overrides);
        Db::name('competitor_wechat_robot')->insert($row);
        return Db::name('competitor_wechat_robot')->where('id', 27)->find();
    }
}
