<?php
declare(strict_types=1);

namespace Tests;

use app\model\CompetitorDevice;
use app\service\CompetitorDeviceAuthService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class CompetitorDeviceAuthServiceTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $databasePath = '';

    public static function setUpBeforeClass(): void
    {
        (new App(dirname(__DIR__)))->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'competitor_device_auth_' . getmypid() . '.sqlite';
        $database = self::$originalDatabaseConfig;
        $database['default'] = 'sqlite';
        $database['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$databasePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($database, 'database');
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$databasePath);
    }

    protected function setUp(): void
    {
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        @unlink(self::$databasePath);
        Db::connect(null, true);
        Db::execute('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT, display_name TEXT, permissions TEXT, level INTEGER NOT NULL, status INTEGER NOT NULL, create_time TEXT, update_time TEXT)');
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT, status INTEGER NOT NULL, owner_user_id INTEGER, created_by INTEGER, create_time TEXT, update_time TEXT)');
        Db::execute('CREATE TABLE users (id INTEGER PRIMARY KEY, tenant_id INTEGER, username TEXT, password TEXT, hotel_id INTEGER, role_id INTEGER, status INTEGER NOT NULL, create_time TEXT, update_time TEXT)');
        Db::execute('CREATE TABLE user_hotel_permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, user_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, can_view INTEGER, can_view_online_data INTEGER, can_fetch_ota INTEGER, can_fetch_online_data INTEGER, status TEXT, expires_at TEXT, create_time TEXT, update_time TEXT)');
        Db::execute('CREATE TABLE competitor_device (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, user_id INTEGER, store_id INTEGER, device_id TEXT, name TEXT, platform TEXT, token_hash TEXT, token_hint TEXT, token_version INTEGER, status INTEGER, last_time TEXT, revoked_at TEXT, create_time TEXT, update_time TEXT)');
        Db::name('roles')->insertAll([
            ['id' => 4, 'name' => 'hotel_operator', 'display_name' => 'Hotel Operator', 'permissions' => '["ota.collect"]', 'level' => 2, 'status' => 1],
            ['id' => 3, 'name' => 'normal_user', 'display_name' => 'Normal User', 'permissions' => '["ota.collect"]', 'level' => 3, 'status' => 1],
        ]);
        Db::name('hotels')->insert(['id' => 80, 'tenant_id' => 12, 'name' => 'Scope Hotel', 'status' => 1]);
        Db::name('users')->insertAll([
            ['id' => 7, 'tenant_id' => 12, 'username' => 'operator', 'password' => 'fixture', 'hotel_id' => 80, 'role_id' => 4, 'status' => 1],
            ['id' => 8, 'tenant_id' => 12, 'username' => 'normal', 'password' => 'fixture', 'hotel_id' => 80, 'role_id' => 3, 'status' => 1],
        ]);
        Db::name('user_hotel_permissions')->insertAll([
            ['tenant_id' => 12, 'user_id' => 7, 'hotel_id' => 80, 'can_view' => 1, 'can_view_online_data' => 1, 'can_fetch_ota' => 0, 'can_fetch_online_data' => 0, 'status' => 'active'],
            ['tenant_id' => 12, 'user_id' => 8, 'hotel_id' => 80, 'can_view' => 1, 'can_view_online_data' => 1, 'can_fetch_ota' => 1, 'can_fetch_online_data' => 1, 'status' => 'active'],
        ]);
    }

    public function testIssuedCredentialIsOneWayAndRejectsAnotherToken(): void
    {
        $service = new CompetitorDeviceAuthService();
        $credential = $service->issueCredential();

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $credential['token']);
        self::assertNotSame($credential['token'], $credential['hash']);
        self::assertStringEndsWith(substr($credential['token'], -8), $credential['hint']);
        self::assertTrue($service->verifyTokenHash($credential['token'], $credential['hash']));
        self::assertFalse($service->verifyTokenHash(str_repeat('0', 64), $credential['hash']));
        self::assertFalse($service->verifyTokenHash($credential['token'], ''));
    }

    public function testBindingCannotAuthorizeAnotherTenantStoreOrPlatform(): void
    {
        $binding = new CompetitorDevice();
        $binding->tenant_id = 12;
        $binding->store_id = 80;
        $binding->platform = 'ctrip';
        $service = new CompetitorDeviceAuthService();

        self::assertTrue($service->bindingMatchesTarget($binding, 12, 80, 'ctrip'));
        self::assertFalse($service->bindingMatchesTarget($binding, 13, 80, 'ctrip'));
        self::assertFalse($service->bindingMatchesTarget($binding, 12, 81, 'ctrip'));
        self::assertFalse($service->bindingMatchesTarget($binding, 12, 80, 'meituan'));
    }

    public function testActiveScopeRequiresExplicitFetchPermissionEvenForPrimaryHotel(): void
    {
        $service = new CompetitorDeviceAuthService();

        self::assertNull($service->resolveActiveScope(7, 80));
        Db::name('user_hotel_permissions')->where('user_id', 7)->update([
            'can_fetch_ota' => 1,
            'can_fetch_online_data' => 1,
        ]);
        self::assertSame([
            'tenant_id' => 12,
            'user_id' => 7,
            'store_id' => 80,
        ], $service->resolveActiveScope(7, 80));
    }

    public function testNormalExternalRoleCannotCollectEvenWithHotelGrant(): void
    {
        $service = new CompetitorDeviceAuthService();

        self::assertNull($service->resolveActiveScope(8, 80));
    }

    public function testActiveScopeRejectsHotelWithoutAuthoritativeTenant(): void
    {
        Db::name('hotels')->where('id', 80)->update(['tenant_id' => 0]);
        Db::name('user_hotel_permissions')->where('user_id', 7)->update([
            'can_fetch_ota' => 1,
            'can_fetch_online_data' => 1,
        ]);
        $service = new CompetitorDeviceAuthService();

        self::assertNull($service->resolveActiveScope(7, 80));
    }

    public function testActiveScopeRejectsUserFromAnotherTenant(): void
    {
        Db::name('users')->where('id', 7)->update(['tenant_id' => 13]);
        Db::name('user_hotel_permissions')->where('user_id', 7)->update([
            'can_fetch_ota' => 1,
            'can_fetch_online_data' => 1,
        ]);

        self::assertNull((new CompetitorDeviceAuthService())->resolveActiveScope(7, 80));
    }

    public function testAuthenticatedBindingSessionRejectsRotatedVersion(): void
    {
        Db::name('user_hotel_permissions')->where('user_id', 7)->update([
            'can_fetch_ota' => 1,
            'can_fetch_online_data' => 1,
        ]);
        $service = new CompetitorDeviceAuthService();
        $credential = $service->issueCredential();
        $bindingId = Db::name('competitor_device')->insertGetId([
            'tenant_id' => 12,
            'user_id' => 7,
            'store_id' => 80,
            'device_id' => 'scope-device-1',
            'name' => 'Scope Device',
            'platform' => 'xc',
            'token_hash' => $credential['hash'],
            'token_hint' => $credential['hint'],
            'token_version' => 1,
            'status' => 1,
            'revoked_at' => null,
        ]);
        $authenticatedBinding = CompetitorDevice::find($bindingId);

        self::assertNotNull($authenticatedBinding);
        self::assertTrue($service->bindingSessionIsCurrent($authenticatedBinding));

        Db::name('competitor_device')->where('id', $bindingId)->update(['token_version' => 2]);
        self::assertFalse($service->bindingSessionIsCurrent($authenticatedBinding));
    }

    public function testSensitiveHashIsHiddenFromModelSerialization(): void
    {
        $binding = new CompetitorDevice();
        $binding->device_id = 'fixture-device';
        $binding->token_hash = 'must-not-leak';
        $binding->token_hint = '…12345678';

        $serialized = $binding->toArray();
        self::assertArrayNotHasKey('token_hash', $serialized);
        self::assertSame('…12345678', $serialized['token_hint']);
    }
}
