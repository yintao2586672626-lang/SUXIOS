<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaConfigVerificationService;
use app\service\OtaProfileBindingService;
use app\service\OtaProfileSessionProofService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OtaConfigVerificationServiceTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $databasePath = '';

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ota_config_verification_' . getmypid() . '.sqlite';
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
        $this->createSchema();
    }

    public function testReadyCredentialStaysPendingUntilSameHotelCurrentSessionProofOccursAfterSave(): void
    {
        $clock = static fn(): DateTimeImmutable => new DateTimeImmutable('2026-07-11 21:00:00', new DateTimeZone('Asia/Shanghai'));
        $bindingService = new OtaProfileBindingService(sys_get_temp_dir());
        $proofService = new OtaProfileSessionProofService($bindingService, $clock);
        $service = new OtaConfigVerificationService($proofService);
        $config = $this->config('2026-07-11 20:59:00');

        self::assertSame('saved_pending_verification', $service->statusForConfig($config, 'ctrip')['verification_status']);

        $proofService->recordVerified(
            1,
            10,
            'ctrip',
            'profile-10',
            true,
            ['ok' => true, 'status' => 'logged_in']
        );

        $verified = (new OtaConfigVerificationService($proofService))->statusForConfig($config, 'ctrip');
        self::assertSame('verified_current', $verified['verification_status']);
        self::assertTrue($verified['configuration_saved']);
        self::assertTrue($verified['configuration_verified']);
        self::assertSame('2026-07-11 21:00:00', $verified['verified_at']);

        $newerConfig = $this->config('2026-07-11 21:01:00');
        $pending = (new OtaConfigVerificationService($proofService))->statusForConfig($newerConfig, 'ctrip');
        self::assertSame('saved_pending_verification', $pending['verification_status']);
        self::assertFalse($pending['configuration_verified']);
    }

    public function testMissingCredentialIsNotSavedOrVerified(): void
    {
        $service = new OtaConfigVerificationService();
        $status = $service->statusForConfig([
            'system_hotel_id' => 10,
            'credential_status' => 'revoked',
            'has_cookies' => false,
        ], 'ctrip');

        self::assertSame('not_saved', $status['verification_status']);
        self::assertFalse($status['configuration_saved']);
        self::assertFalse($status['configuration_verified']);
    }

    /** @return array<string, mixed> */
    private function config(string $updatedAt): array
    {
        return [
            'id' => 'config-10',
            'config_id' => 'config-10',
            'system_hotel_id' => 10,
            'hotel_id' => '10',
            'credential_status' => 'ready',
            'has_cookies' => true,
            'update_time' => $updatedAt,
        ];
    }

    private function createSchema(): void
    {
        foreach ([
            'CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT NOT NULL)',
            'CREATE TABLE ota_profile_bindings (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, platform TEXT NOT NULL, profile_key_hash TEXT NOT NULL, binding_status TEXT NOT NULL, bound_by INTEGER NULL, revoked_by INTEGER NULL, create_time TEXT NULL, update_time TEXT NULL)',
            'CREATE TABLE platform_data_sources (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, platform TEXT NOT NULL, ingestion_method TEXT NOT NULL, enabled INTEGER NOT NULL, status TEXT NOT NULL, config_json TEXT NULL, update_time TEXT NULL)',
        ] as $sql) {
            Db::execute($sql);
        }
        Db::name('hotels')->insert(['id' => 10, 'tenant_id' => 10, 'name' => '验证酒店']);
        Db::name('ota_profile_bindings')->insert([
            'id' => 1,
            'tenant_id' => 10,
            'system_hotel_id' => 10,
            'platform' => 'ctrip',
            'profile_key_hash' => hash('sha256', 'profile-10'),
            'binding_status' => 'active',
        ]);
        Db::name('platform_data_sources')->insert([
            'id' => 1,
            'tenant_id' => 10,
            'system_hotel_id' => 10,
            'platform' => 'ctrip',
            'ingestion_method' => 'browser_profile',
            'enabled' => 1,
            'status' => 'ready',
            'config_json' => json_encode(['profile_id' => 'profile-10'], JSON_THROW_ON_ERROR),
            'update_time' => '2026-07-11 20:00:00',
        ]);
    }
}
