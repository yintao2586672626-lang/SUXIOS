<?php
declare(strict_types=1);

namespace Tests;

use app\model\LoginLog;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class LoginLogTenantLineageTest extends TestCase
{
    /** @var array<string, mixed> */
    private static array $originalDatabaseConfig = [];

    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'login_log_tenant_lineage_' . getmypid() . '.sqlite';

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
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        if (is_file(self::$sqlitePath)) {
            @unlink(self::$sqlitePath);
        }
        Db::connect(null, true);
        Db::execute('CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER,
            hotel_id INTEGER
        )');
        Db::execute('CREATE TABLE hotels (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER
        )');
    }

    public function testMissingUserTenantUsesExplicitHotelTenantMappingInsteadOfHotelId(): void
    {
        Db::name('hotels')->insert(['id' => 77, 'tenant_id' => 901]);
        Db::name('users')->insert(['id' => 1, 'tenant_id' => null, 'hotel_id' => 77]);

        self::assertSame(901, $this->tenantIdForUser(1));
        self::assertNotSame(77, $this->tenantIdForUser(1));
    }

    public function testMissingUserTenantWithoutHotelMappingRemainsNull(): void
    {
        Db::name('users')->insert(['id' => 2, 'tenant_id' => 0, 'hotel_id' => 88]);

        self::assertNull($this->tenantIdForUser(2));
    }

    public function testExplicitUserTenantRemainsAuthoritative(): void
    {
        Db::name('hotels')->insert(['id' => 77, 'tenant_id' => 901]);
        Db::name('users')->insert(['id' => 3, 'tenant_id' => 555, 'hotel_id' => 77]);

        self::assertSame(555, $this->tenantIdForUser(3));
    }

    private function tenantIdForUser(int $userId): ?int
    {
        $method = new ReflectionMethod(LoginLog::class, 'tenantIdForUser');
        $method->setAccessible(true);
        $tenantId = $method->invoke(null, $userId);
        return $tenantId === null ? null : (int)$tenantId;
    }
}
