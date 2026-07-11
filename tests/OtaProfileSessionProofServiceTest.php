<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaProfileBindingService;
use app\service\OtaProfileSessionProofService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OtaProfileSessionProofServiceTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $databasePath = '';
    private static string $projectRoot = '';

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ota_profile_session_proof_' . getmypid() . '.sqlite';
        self::$projectRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ota_profile_session_proof_root_' . getmypid();

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
        $this->createSchema();
        Db::name('hotels')->insertAll([
            ['id' => 10, 'tenant_id' => 1, 'name' => 'Tenant one hotel'],
            ['id' => 20, 'tenant_id' => 2, 'name' => 'Tenant two hotel'],
        ]);
    }

    public function testRecordsAndValidatesSameSourceCurrentSessionProof(): void
    {
        $sourceId = $this->insertBoundSource(10, 1, 'ctrip', 'profile-10');
        $service = $this->service('2026-07-11 09:15:30');

        $proof = $service->recordVerified(
            $sourceId,
            10,
            'ctrip',
            'profile-10',
            true,
            ['ok' => true, 'status' => 'logged_in'],
            [
                'manual_login_state_verified' => true,
                'profile_status' => 'logged_in',
            ]
        );

        self::assertTrue($proof['current_session_verified']);
        self::assertSame($sourceId, $proof['current_session_probe_data_source_id']);
        self::assertSame('2026-07-11', $proof['current_session_probe_date']);

        $source = Db::name('platform_data_sources')->where('id', $sourceId)->find();
        self::assertIsArray($source);
        $config = json_decode((string)$source['config_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($config['current_session_probe_performed']);
        self::assertTrue($config['current_session_verified']);
        self::assertSame('verified', $config['current_session_status']);
        self::assertSame('2026-07-11 09:15:30', $config['current_session_probe_at']);
        self::assertSame($sourceId, $config['current_session_probe_data_source_id']);
        self::assertSame('2026-07-11', $config['current_session_probe_date']);
        self::assertSame('Asia/Shanghai', $config['current_session_probe_timezone']);
        self::assertSame('ctrip', $config['current_session_probe_platform']);
        self::assertSame(1, $config['current_session_probe_tenant_id']);
        self::assertSame(10, $config['current_session_probe_system_hotel_id']);
        self::assertSame(hash('sha256', 'profile-10'), $config['current_session_probe_profile_key_hash']);
        self::assertSame('same_data_source_profile_session', $config['current_session_probe_scope']);
        self::assertSame('platform_profile_login_task', $config['current_session_probe_producer']);
        self::assertTrue($config['manual_login_state_verified']);
        self::assertTrue($service->isCurrentVerified($source));
        self::assertFalse($this->service('2026-07-12 00:00:01')->isCurrentVerified($source));
    }

    public function testRejectsFailedProcessOrUnverifiedAuthWithoutWritingProof(): void
    {
        $sourceId = $this->insertBoundSource(10, 1, 'ctrip', 'profile-10');
        $service = $this->service('2026-07-11 09:15:30');
        $before = (string)Db::name('platform_data_sources')->where('id', $sourceId)->value('config_json');

        foreach ([
            [false, ['ok' => true, 'status' => 'logged_in']],
            [true, ['ok' => false, 'status' => 'logged_in']],
            [true, ['ok' => true, 'status' => 'login_required']],
        ] as [$processSucceeded, $authStatus]) {
            try {
                $service->recordVerified($sourceId, 10, 'ctrip', 'profile-10', $processSucceeded, $authStatus);
                self::fail('Unverified login evidence must not produce a current-session proof.');
            } catch (RuntimeException $e) {
                self::assertStringContainsString('login evidence is not verified', $e->getMessage());
            }
            self::assertSame($before, (string)Db::name('platform_data_sources')->where('id', $sourceId)->value('config_json'));
        }
    }

    public function testRejectsWrongSourceScopeOrProfileWithoutWritingProof(): void
    {
        $sourceId = $this->insertBoundSource(10, 1, 'ctrip', 'profile-10');
        $service = $this->service('2026-07-11 09:15:30');

        Db::name('platform_data_sources')->where('id', $sourceId)->update(['tenant_id' => 2]);
        try {
            $service->recordVerified($sourceId, 10, 'ctrip', 'profile-10', true, ['ok' => true, 'status' => 'authorized']);
            self::fail('Cross-tenant source scope must not produce a proof.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('tenant scope mismatch', $e->getMessage());
        }
        $tenantMismatchConfig = json_decode((string)Db::name('platform_data_sources')->where('id', $sourceId)->value('config_json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('current_session_verified', $tenantMismatchConfig);
        Db::name('platform_data_sources')->where('id', $sourceId)->update(['tenant_id' => 1]);

        Db::name('platform_data_sources')->where('id', $sourceId)->update([
            'config_json' => json_encode(['profile_id' => 'another-profile'], JSON_THROW_ON_ERROR),
        ]);
        try {
            $service->recordVerified($sourceId, 10, 'ctrip', 'profile-10', true, ['ok' => true, 'status' => 'authorized']);
            self::fail('A different source Profile must not produce a proof.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Profile scope mismatch', $e->getMessage());
        }

        $stored = json_decode((string)Db::name('platform_data_sources')->where('id', $sourceId)->value('config_json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('current_session_verified', $stored);
    }

    public function testRejectsInactiveBindingAndValidatorFailsClosedAfterRevocation(): void
    {
        $sourceId = $this->insertBoundSource(10, 1, 'meituan', 'store-10');
        $service = $this->service('2026-07-11 09:15:30');
        $service->recordVerified($sourceId, 10, 'meituan', 'store-10', true, ['ok' => true, 'status' => 'authorized']);
        $source = Db::name('platform_data_sources')->where('id', $sourceId)->find();
        self::assertIsArray($source);
        self::assertTrue($service->isCurrentVerified($source));

        Db::name('ota_profile_bindings')->where('platform', 'meituan')->update(['binding_status' => 'revoked']);
        self::assertFalse($service->isCurrentVerified($source));

        $configBefore = (string)Db::name('platform_data_sources')->where('id', $sourceId)->value('config_json');
        try {
            $service->recordVerified($sourceId, 10, 'meituan', 'store-10', true, ['ok' => true, 'status' => 'authorized']);
            self::fail('A revoked binding must not produce or refresh a proof.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('not active', $e->getMessage());
        }
        self::assertSame($configBefore, (string)Db::name('platform_data_sources')->where('id', $sourceId)->value('config_json'));
    }

    public function testPlatformProfileLoginDelegatesCurrentSessionProofToTheContract(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/command/PlatformProfileLogin.php');

        self::assertStringContainsString('use app\\service\\OtaProfileSessionProofService;', $source);
        self::assertStringContainsString('->recordVerified(', $source);
        self::assertStringContainsString("(bool)\$result['success']", $source);
        self::assertStringNotContainsString("\$config['current_session_verified']", $source);
    }

    private function service(string $now): OtaProfileSessionProofService
    {
        $clock = static fn(): DateTimeImmutable => new DateTimeImmutable($now, new DateTimeZone('Asia/Shanghai'));
        return new OtaProfileSessionProofService(new OtaProfileBindingService(self::$projectRoot), $clock);
    }

    private function insertBoundSource(int $hotelId, int $tenantId, string $platform, string $profileKey): int
    {
        $config = $platform === 'meituan'
            ? ['store_id' => $profileKey]
            : ['profile_id' => $profileKey];
        $sourceId = (int)Db::name('platform_data_sources')->insertGetId([
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'platform' => $platform,
            'ingestion_method' => 'browser_profile',
            'status' => 'waiting_config',
            'enabled' => 1,
            'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'update_time' => '2026-07-10 12:00:00',
        ]);
        Db::name('ota_profile_bindings')->insert([
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'platform' => $platform,
            'profile_key_hash' => hash('sha256', $profileKey),
            'binding_status' => 'active',
            'create_time' => '2026-07-10 12:00:00',
            'update_time' => '2026-07-10 12:00:00',
        ]);
        return $sourceId;
    }

    private function createSchema(): void
    {
        Db::execute('CREATE TABLE hotels (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            name TEXT
        )');
        Db::execute('CREATE TABLE platform_data_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            platform TEXT NOT NULL,
            ingestion_method TEXT NOT NULL,
            status TEXT NOT NULL,
            enabled INTEGER NOT NULL DEFAULT 1,
            config_json TEXT NOT NULL,
            update_time TEXT
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
        Db::execute('CREATE UNIQUE INDEX uq_test_session_binding_key ON ota_profile_bindings(platform, profile_key_hash)');
    }
}
