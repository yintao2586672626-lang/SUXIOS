<?php
declare(strict_types=1);

namespace Tests;

use app\model\User;
use app\service\HotelScopeService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class HotelScopeTenantMetadataTest extends TestCase
{
    /** @var array<string, mixed> */
    private static array $originalDatabaseConfig = [];

    private static string $sqlitePath = '';

    private static App $app;

    public static function setUpBeforeClass(): void
    {
        self::$app = new App();
        self::$app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hotel_scope_tenant_metadata_' . getmypid() . '.sqlite';

        $config = self::$originalDatabaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$sqlitePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($config, 'database');
    }

    public static function tearDownAfterClass(): void
    {
        self::$app->request->user = null;
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        if (self::$sqlitePath !== '' && is_file(self::$sqlitePath)) {
            @unlink(self::$sqlitePath);
        }
    }

    protected function setUp(): void
    {
        self::$app->request->user = null;
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        if (is_file(self::$sqlitePath)) {
            @unlink(self::$sqlitePath);
        }
        Db::connect(null, true);
        $this->createSchema();
        $this->seedFixture();
    }

    public function testForgedCurrentTenantPermissionCannotGrantAnotherTenantHotel(): void
    {
        $user = User::find(501);
        self::assertInstanceOf(User::class, $user);
        self::$app->request->user = $user;

        $scope = new HotelScopeService();

        self::assertSame([10], $scope->accessibleHotelIds($user));
        self::assertFalse($scope->canAccessHotel($user, 20, 'hotel.view'));
        self::assertFalse($scope->hotelPermissionAllows($user, 20, 'hotel.view'));
    }

    public function testRequiredTenantMetadataProbeFailureThrowsInsteadOfFailingOpen(): void
    {
        $user = User::find(501);
        self::assertInstanceOf(User::class, $user);
        self::$app->request->user = $user;
        $scope = new FailingTenantMetadataHotelScopeService();

        try {
            $scope->accessibleHotelIds($user);
            self::fail('Tenant metadata probe failure must reject authorization.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'Required tenant column metadata unavailable',
                $exception->getMessage()
            );
        }
    }

    public function testMissingRequiredTenantColumnThrowsInsteadOfUsingLegacyAuthorization(): void
    {
        $user = User::find(501);
        self::assertInstanceOf(User::class, $user);
        self::$app->request->user = $user;
        $scope = new MissingTenantMetadataHotelScopeService();

        try {
            $scope->accessibleHotelIds($user);
            self::fail('A missing tenant core column must reject authorization.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'Required tenant column is missing',
                $exception->getMessage()
            );
        }
    }

    private function createSchema(): void
    {
        Db::execute('CREATE TABLE roles (
            id INTEGER PRIMARY KEY,
            name VARCHAR(50) NOT NULL,
            display_name VARCHAR(100),
            level INTEGER NOT NULL,
            permissions TEXT,
            status INTEGER NOT NULL DEFAULT 1
        )');
        Db::execute('CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            hotel_id INTEGER,
            role_id INTEGER NOT NULL,
            status INTEGER NOT NULL DEFAULT 1
        )');
        Db::execute('CREATE TABLE hotels (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            name VARCHAR(100) NOT NULL,
            status INTEGER NOT NULL DEFAULT 1,
            owner_user_id INTEGER,
            created_by INTEGER
        )');
        Db::execute('CREATE TABLE user_hotel_permissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            hotel_id INTEGER NOT NULL,
            can_view INTEGER NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT \'active\'
        )');
    }

    private function seedFixture(): void
    {
        Db::name('roles')->insert([
            'id' => 3,
            'name' => 'tenant_operator',
            'display_name' => 'Tenant operator',
            'level' => 2,
            'permissions' => json_encode(['hotel.view'], JSON_THROW_ON_ERROR),
            'status' => 1,
        ]);
        Db::name('users')->insert([
            'id' => 501,
            'tenant_id' => 101,
            'hotel_id' => 10,
            'role_id' => 3,
            'status' => 1,
        ]);
        Db::name('hotels')->insertAll([
            ['id' => 10, 'tenant_id' => 101, 'name' => 'Tenant A hotel', 'status' => 1],
            ['id' => 20, 'tenant_id' => 202, 'name' => 'Tenant B hotel', 'status' => 1],
        ]);
        Db::name('user_hotel_permissions')->insert([
            'tenant_id' => 101,
            'user_id' => 501,
            'hotel_id' => 20,
            'can_view' => 1,
            'status' => 'active',
        ]);
    }
}

final class FailingTenantMetadataHotelScopeService extends HotelScopeService
{
    protected function probeTableColumn(string $table, string $column): bool
    {
        if ($column === 'tenant_id' && in_array($table, ['hotels', 'user_hotel_permissions'], true)) {
            throw new RuntimeException('simulated metadata failure');
        }

        return parent::probeTableColumn($table, $column);
    }
}

final class MissingTenantMetadataHotelScopeService extends HotelScopeService
{
    protected function probeTableColumn(string $table, string $column): bool
    {
        if ($column === 'tenant_id' && in_array($table, ['hotels', 'user_hotel_permissions'], true)) {
            return false;
        }

        return parent::probeTableColumn($table, $column);
    }
}
