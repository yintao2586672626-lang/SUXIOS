<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaProfileBindingService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OtaProfileBindingServiceTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $databasePath = '';
    private static string $projectRoot = '';

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ota_profile_binding_' . getmypid() . '.sqlite';
        self::$projectRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ota_profile_binding_root_' . getmypid();

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
        @rmdir(self::$projectRoot . DIRECTORY_SEPARATOR . 'storage');
        @rmdir(self::$projectRoot);
    }

    protected function setUp(): void
    {
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        @unlink(self::$databasePath);
        Db::connect(null, true);
        if (!is_dir(self::$projectRoot . DIRECTORY_SEPARATOR . 'storage')) {
            mkdir(self::$projectRoot . DIRECTORY_SEPARATOR . 'storage', 0775, true);
        }
        foreach (glob(self::$projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . '*_profile_*', GLOB_ONLYDIR) ?: [] as $dir) {
            @rmdir($dir);
        }
        $this->createSchema();
        Db::name('hotels')->insertAll([
            ['id' => 10, 'tenant_id' => 1, 'name' => 'Tenant one hotel'],
            ['id' => 20, 'tenant_id' => 2, 'name' => 'Tenant two hotel'],
        ]);
    }

    public function testClaimStoresOnlyHashAndEnforcesHotelTenantScope(): void
    {
        $this->insertSource(10, 1, 'ctrip', ['profile_id' => 'profile-10']);
        $service = $this->service();

        $claimed = $service->claim(10, 'ctrip', 'profile-10', 91);

        self::assertSame('active', $claimed['binding_status']);
        self::assertSame(hash('sha256', 'profile-10'), $claimed['profile_key_hash']);
        self::assertArrayNotHasKey('profile_key', $claimed);
        $stored = Db::name('ota_profile_bindings')->where('id', (int)$claimed['id'])->find();
        self::assertIsArray($stored);
        self::assertSame(hash('sha256', 'profile-10'), $stored['profile_key_hash']);
        self::assertStringNotContainsString('profile-10', json_encode($stored, JSON_THROW_ON_ERROR));
        self::assertSame('active', $service->assertBound(10, 'ctrip', 'profile-10')['binding_status']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('another tenant or hotel');
        $service->assertBound(20, 'ctrip', 'profile-10');
    }

    public function testClaimFailsClosedWhenSourceMetadataConflictsAcrossScopes(): void
    {
        $this->insertSource(10, 1, 'ctrip', ['profile_id' => 'shared-profile']);
        $this->insertSource(20, 2, 'ctrip', ['profile_id' => 'shared-profile']);

        try {
            $this->service()->claim(10, 'ctrip', 'shared-profile', 91);
            self::fail('Cross-scope source metadata must not claim a Profile binding.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('source metadata conflicts', $e->getMessage());
            self::assertStringNotContainsString('shared-profile', $e->getMessage());
        }
        self::assertSame(0, Db::name('ota_profile_bindings')->count());
    }

    public function testExistingUnboundDirectoryRequiresExplicitLocalRebind(): void
    {
        $this->insertSource(10, 1, 'ctrip', ['profile_id' => 'legacy-profile']);
        mkdir(self::$projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'ctrip_profile_legacy-profile');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Existing OTA profile directory is unbound');
        $this->service()->claim(10, 'ctrip', 'legacy-profile', 91);
    }

    public function testRevokedBindingCanOnlyBeReactivatedByOriginalScope(): void
    {
        $this->insertSource(10, 1, 'meituan', ['store_id' => 'store-10']);
        $service = $this->service();
        $service->claim(10, 'meituan', 'store-10', 91);
        $service->revoke(10, 'meituan', 'store-10', 92);

        try {
            $service->assertBound(10, 'meituan', 'store-10');
            self::fail('Revoked binding must not be executable.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('not active', $e->getMessage());
        }

        self::assertSame('active', $service->claim(10, 'meituan', 'store-10', 93)['binding_status']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('another tenant or hotel');
        $service->claim(20, 'meituan', 'store-10', 94);
    }

    public function testBindingMigrationUsesHashedKeysAndIsRegistered(): void
    {
        $root = dirname(__DIR__);
        $path = $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations'
            . DIRECTORY_SEPARATOR . '20260710_create_ota_profile_bindings.sql';
        self::assertFileExists($path);
        $sql = (string)file_get_contents($path);
        self::assertStringContainsString('`profile_key_hash` char(64)', $sql);
        self::assertStringContainsString('UNIQUE KEY `uq_ota_profile_binding_key` (`platform`, `profile_key_hash`)', $sql);
        self::assertStringNotContainsString('`profile_key` varchar', $sql);
        self::assertStringContainsString('COUNT(DISTINCT CONCAT(', $sql);
        self::assertStringContainsString("`p`.`last_error` = 'orphan_system_hotel_scope'", $sql);
        self::assertStringContainsString("LOWER(TRIM(`p`.`ingestion_method`)) IN ('browser_profile', 'profile_browser')", $sql);
        self::assertStringContainsString('SOURCE ./database/migrations/20260710_create_ota_profile_bindings.sql;', (string)file_get_contents($root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'init_full.sql'));
    }

    public function testProfileExecutionEntrypointsRequireAuthoritativeBinding(): void
    {
        $root = dirname(__DIR__);
        $sync = (string)file_get_contents($root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'service' . DIRECTORY_SEPARATOR . 'PlatformDataSyncService.php');
        $request = (string)file_get_contents($root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR . 'concern' . DIRECTORY_SEPARATOR . 'OnlineDataRequestConcern.php');
        $login = (string)file_get_contents($root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'command' . DIRECTORY_SEPARATOR . 'PlatformProfileLogin.php');

        self::assertStringContainsString('OtaProfileBindingService', $sync);
        self::assertStringContainsString('assertBound(', $sync);
        self::assertStringContainsString('claim(', $sync);
        self::assertStringContainsString('assertOtaProfileBindingForHotel', $request);
        self::assertStringContainsString('OtaProfileBindingService', $login);
        self::assertStringContainsString('assertBound(', $login);
    }

    private function service(): OtaProfileBindingService
    {
        return new OtaProfileBindingService(self::$projectRoot);
    }

    private function insertSource(int $hotelId, int $tenantId, string $platform, array $config): void
    {
        Db::name('platform_data_sources')->insert([
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'platform' => $platform,
            'ingestion_method' => 'browser_profile',
            'status' => 'waiting_config',
            'enabled' => 1,
            'config_json' => json_encode($config, JSON_THROW_ON_ERROR),
        ]);
    }

    private function createSchema(): void
    {
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT NOT NULL)');
        Db::execute('CREATE TABLE platform_data_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER,
            system_hotel_id INTEGER,
            platform TEXT NOT NULL,
            ingestion_method TEXT NOT NULL,
            status TEXT,
            enabled INTEGER,
            config_json TEXT
        )');
        Db::execute('CREATE TABLE ota_profile_bindings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            platform TEXT NOT NULL,
            profile_key_hash TEXT NOT NULL,
            binding_status TEXT NOT NULL,
            bound_by INTEGER,
            revoked_by INTEGER,
            create_time TEXT,
            update_time TEXT
        )');
        Db::execute('CREATE UNIQUE INDEX uq_test_profile_binding_key ON ota_profile_bindings(platform, profile_key_hash)');
    }
}
