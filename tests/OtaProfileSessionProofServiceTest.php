<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaProfileBindingService;
use app\service\OtaProfileSessionProofService;
use app\service\PlatformDataSyncService;
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

        $responseService = new PlatformDataSyncService(null, null, $service);
        $responseRows = $responseService->listDataSources(null, ['system_hotel_id' => 10]);
        self::assertCount(1, $responseRows);
        self::assertTrue(
            $responseRows[0]['current_session_verified'] ?? false,
            'The data-source response must expose the server-authoritative session verdict.'
        );
        self::assertTrue($responseRows[0]['profile_reusable'] ?? false);
        self::assertSame('reusable', $responseRows[0]['profile_reuse_status'] ?? null);
        self::assertFalse($responseRows[0]['profile_reuse_warning'] ?? true);
        self::assertSame(0, $responseRows[0]['profile_age_days'] ?? null);
        self::assertSame(10, $responseRows[0]['days_until_forced_login'] ?? null);

        $nextDayResponseService = new PlatformDataSyncService(
            null,
            null,
            $this->service('2026-07-12 00:00:01')
        );
        $nextDayRows = $nextDayResponseService->listDataSources(null, ['system_hotel_id' => 10]);
        self::assertFalse($nextDayRows[0]['current_session_verified'] ?? true);
        self::assertTrue($nextDayRows[0]['profile_reusable'] ?? false);
        self::assertSame('reusable', $nextDayRows[0]['profile_reuse_status'] ?? null);
        self::assertSame(1, $nextDayRows[0]['profile_age_days'] ?? null);
        self::assertSame(9, $nextDayRows[0]['days_until_forced_login'] ?? null);

        $config['current_session_probe_profile_key_hash'] = hash('sha256', 'wrong-profile');
        Db::name('platform_data_sources')->where('id', $sourceId)->update([
            'config_json' => json_encode($config, JSON_THROW_ON_ERROR),
        ]);
        $tamperedRows = $responseService->listDataSources(null, ['system_hotel_id' => 10]);
        self::assertFalse(
            $tamperedRows[0]['current_session_verified'] ?? true,
            'A forged config flag must not be exposed as a verified current session.'
        );
    }

    public function testSuccessfulCollectionPreflightCreatesSameDaySessionProof(): void
    {
        $sourceId = $this->insertBoundSource(10, 1, 'meituan', 'store-10');
        $service = $this->service('2026-07-11 07:30:00');

        $proof = $service->recordCollectionPreflightVerified(
            $sourceId,
            10,
            'meituan',
            'store-10',
            true,
            ['ok' => true, 'status' => 'logged_in']
        );

        self::assertSame('platform_data_sync_preflight', $proof['current_session_probe_producer']);
        $source = Db::name('platform_data_sources')->where('id', $sourceId)->find();
        self::assertIsArray($source);
        self::assertTrue($service->isCurrentVerified($source));
    }

    public function testFailedCollectionPreflightInvalidatesSameDaySessionProof(): void
    {
        $sourceId = $this->insertBoundSource(10, 1, 'meituan', 'store-10');
        $service = $this->service('2026-07-11 07:30:00');
        $service->recordCollectionPreflightVerified(
            $sourceId,
            10,
            'meituan',
            'store-10',
            true,
            ['ok' => true, 'status' => 'logged_in']
        );

        $service->recordCollectionPreflightFailed(
            $sourceId,
            10,
            'meituan',
            'store-10',
            ['ok' => false, 'status' => 'login_required']
        );

        $source = Db::name('platform_data_sources')->where('id', $sourceId)->find();
        self::assertIsArray($source);
        $config = json_decode((string)$source['config_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($config['current_session_verified']);
        self::assertSame('login_required', $config['current_session_status']);
        self::assertFalse($service->isCurrentVerified($source));
    }

    public function testProfileReuseWindowKeepsSameDayProofIndependent(): void
    {
        $sourceId = $this->insertBoundSource(10, 1, 'ctrip', 'profile-10');
        $this->service('2026-07-01 10:00:00')->recordVerified(
            $sourceId,
            10,
            'ctrip',
            'profile-10',
            true,
            ['ok' => true, 'status' => 'logged_in']
        );
        $source = Db::name('platform_data_sources')->where('id', $sourceId)->find();
        self::assertIsArray($source);

        $dayZeroService = $this->service('2026-07-01 23:59:59');
        self::assertTrue($dayZeroService->isCurrentVerified($source));
        self::assertSame([
            'status' => 'reusable',
            'is_reusable' => true,
            'age_days' => 0,
            'days_until_forced_login' => 10,
            'warning' => false,
            'reason' => 'profile_proof_reusable',
        ], $dayZeroService->profileReuseState($source));

        $daySixService = $this->service('2026-07-07 10:00:00');
        self::assertFalse($daySixService->isCurrentVerified($source));
        self::assertSame('reusable', $daySixService->profileReuseState($source)['status']);
        self::assertSame(6, $daySixService->profileReuseState($source)['age_days']);
        self::assertSame(4, $daySixService->profileReuseState($source)['days_until_forced_login']);

        $daySeven = $this->service('2026-07-08 10:00:00')->profileReuseState($source);
        self::assertSame('renewal_warning', $daySeven['status']);
        self::assertTrue($daySeven['is_reusable']);
        self::assertSame(7, $daySeven['age_days']);
        self::assertSame(3, $daySeven['days_until_forced_login']);
        self::assertTrue($daySeven['warning']);
        self::assertSame('profile_reauthentication_recommended', $daySeven['reason']);

        $dayNine = $this->service('2026-07-10 10:00:00')->profileReuseState($source);
        self::assertSame('renewal_warning', $dayNine['status']);
        self::assertTrue($dayNine['is_reusable']);
        self::assertSame(9, $dayNine['age_days']);
        self::assertSame(1, $dayNine['days_until_forced_login']);

        $dayTen = $this->service('2026-07-11 10:00:00')->profileReuseState($source);
        self::assertSame('expired', $dayTen['status']);
        self::assertFalse($dayTen['is_reusable']);
        self::assertSame(10, $dayTen['age_days']);
        self::assertSame(0, $dayTen['days_until_forced_login']);
        self::assertFalse($dayTen['warning']);
        self::assertSame('profile_reauthentication_required', $dayTen['reason']);
    }

    public function testProfileReuseExpiresImmediatelyOnStoredAuthenticationFailure(): void
    {
        $sourceId = $this->insertBoundSource(10, 1, 'meituan', 'store-10');
        $this->service('2026-07-01 10:00:00')->recordVerified(
            $sourceId,
            10,
            'meituan',
            'store-10',
            true,
            ['ok' => true, 'status' => 'authorized']
        );
        Db::name('platform_data_sources')->where('id', $sourceId)->update([
            'last_sync_status' => 'failed',
            'last_error' => 'Platform returned 401 login_required.',
        ]);
        $source = Db::name('platform_data_sources')->where('id', $sourceId)->find();
        self::assertIsArray($source);

        $state = $this->service('2026-07-02 10:00:00')->profileReuseState($source);
        self::assertSame('expired', $state['status']);
        self::assertFalse($state['is_reusable']);
        self::assertSame(1, $state['age_days']);
        self::assertSame('profile_session_explicitly_expired', $state['reason']);
    }

    public function testProfileReuseRejectsTamperedAuthoritativeProof(): void
    {
        $sourceId = $this->insertBoundSource(10, 1, 'ctrip', 'profile-10');
        $this->service('2026-07-01 10:00:00')->recordVerified(
            $sourceId,
            10,
            'ctrip',
            'profile-10',
            true,
            ['ok' => true, 'status' => 'logged_in']
        );
        $source = Db::name('platform_data_sources')->where('id', $sourceId)->find();
        self::assertIsArray($source);
        $config = json_decode((string)$source['config_json'], true, 512, JSON_THROW_ON_ERROR);
        $config['current_session_probe_tenant_id'] = 2;
        $config['current_session_probe_profile_key_hash'] = hash('sha256', 'wrong-profile');
        $source['config_json'] = json_encode($config, JSON_THROW_ON_ERROR);

        self::assertSame([
            'status' => 'unverified',
            'is_reusable' => false,
            'age_days' => null,
            'days_until_forced_login' => 0,
            'warning' => false,
            'reason' => 'profile_proof_unverified',
        ], $this->service('2026-07-02 10:00:00')->profileReuseState($source));
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
            last_sync_status TEXT,
            last_error TEXT,
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
