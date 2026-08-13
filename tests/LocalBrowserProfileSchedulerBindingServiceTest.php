<?php
declare(strict_types=1);

namespace tests;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use app\service\BrowserProfileCaptureRequestService;
use app\service\LocalBrowserProfileSchedulerBindingService;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class LocalBrowserProfileSchedulerBindingServiceTest extends TestCase
{
    private const TENANT_ID = 80;
    private const HOTEL_ID = 80;
    private const USER_ID = 7;
    private const CTRIP_SOURCE_ID = 25;
    private const MEITUAN_SOURCE_ID = 68;
    private const DEVICE_ID = 'SUXIOS-H80-LOCAL';
    private const CTRIP_PLATFORM_HOTEL_ID = 'CTRIP-H80-PRIVATE';
    private const MEITUAN_PLATFORM_HOTEL_ID = 'MEITUAN-H80-PRIVATE';
    private const CTRIP_PROFILE_KEY = 'ctrip-profile-private';
    private const MEITUAN_PROFILE_KEY = 'meituan-profile-private';

    private static array $originalDatabaseConfig = [];
    private static string $databasePath = '';

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . 'local_browser_profile_scheduler_binding_' . getmypid() . '.sqlite';
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
        $this->seedReadyScope();
    }

    public function testPreflightIsReadOnlyReadyAndSecretFree(): void
    {
        $before = $this->sourceConfigJsonById();

        $receipt = $this->service()->preflight(
            self::TENANT_ID,
            self::HOTEL_ID,
            self::USER_ID,
            self::CTRIP_SOURCE_ID,
            self::MEITUAN_SOURCE_ID,
            self::DEVICE_ID
        );

        self::assertSame('ready', $receipt['status']);
        self::assertTrue($receipt['binding_ready']);
        self::assertFalse($receipt['bound']);
        self::assertFalse($receipt['database_write_performed']);
        self::assertSame(2, $receipt['write']['needed_sources']);
        self::assertFalse($receipt['write']['attempted']);
        self::assertFalse($receipt['ota_collection_performed']);
        self::assertFalse($receipt['profile_opened']);
        self::assertSame($before, $this->sourceConfigJsonById());
        foreach ($receipt['sources'] as $source) {
            self::assertSame('verified', $source['canonical_identity']);
            self::assertSame('verified', $source['profile_binding']);
            self::assertSame('verified', $source['profile_reuse']);
            self::assertSame('verified', $source['current_session']);
            self::assertSame('missing', $source['binding_status']);
        }
        $this->assertSafeReceipt($receipt);
    }

    public function testExecuteWritesExactBindingAndPreservesEveryCurrentSessionField(): void
    {
        $before = $this->sourceConfigsById();

        $receipt = $this->service()->execute(
            self::TENANT_ID,
            self::HOTEL_ID,
            self::USER_ID,
            self::CTRIP_SOURCE_ID,
            self::MEITUAN_SOURCE_ID,
            self::DEVICE_ID
        );
        $after = $this->sourceConfigsById();

        self::assertSame('ready', $receipt['status']);
        self::assertTrue($receipt['binding_ready']);
        self::assertTrue($receipt['bound']);
        self::assertFalse($receipt['already_bound']);
        self::assertTrue($receipt['database_write_performed']);
        self::assertSame(2, $receipt['write']['affected_rows']);
        self::assertTrue($receipt['write']['readback_verified']);
        self::assertTrue($receipt['write']['current_session_preserved']);

        foreach ([self::CTRIP_SOURCE_ID => 'ctrip', self::MEITUAN_SOURCE_ID => 'meituan'] as $sourceId => $platform) {
            $config = $after[$sourceId];
            self::assertSame('single_user_local', $config['source_method']);
            self::assertSame('single_user_local', $config['collector_binding_mode']);
            self::assertSame(self::DEVICE_ID, $config['collector_device_id']);
            self::assertSame(hash('sha256', self::DEVICE_ID), $config['collector_device_id_hash']);
            self::assertSame(self::USER_ID, $config['collector_user_id']);
            self::assertSame(self::TENANT_ID, $config['collector_tenant_id']);
            self::assertSame(self::HOTEL_ID, $config['collector_hotel_id']);
            self::assertSame($platform, $config['collector_platform']);
            self::assertSame('2026-08-11 10:00:00', $config['collector_bound_at']);
            self::assertSame(
                $this->currentSessionFields($before[$sourceId]),
                $this->currentSessionFields($config)
            );
            self::assertSame('keep-me-verbatim', $config['non_binding_sentinel']);
        }
        foreach ($receipt['sources'] as $source) {
            self::assertTrue($source['current_session_preserved']);
            self::assertTrue($source['readback_verified']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $source['current_session_digest']);
        }
        $this->assertSafeReceipt($receipt);
    }

    public function testExpiredGrantBlocksBeforeWrite(): void
    {
        Db::name('user_hotel_permissions')->where('id', 1)->update([
            'expires_at' => '2026-08-11 09:59:59',
        ]);
        $before = $this->sourceConfigJsonById();

        $receipt = $this->execute();

        self::assertSame('blocked', $receipt['status']);
        self::assertContains(
            'local_profile_scheduler_fetch_permission_missing',
            array_column($receipt['blockers'], 'code')
        );
        self::assertSame($before, $this->sourceConfigJsonById());
    }

    public function testProfileHashMismatchBlocksBeforeWrite(): void
    {
        Db::name('ota_profile_bindings')
            ->where('platform', 'ctrip')
            ->update(['profile_key_hash' => hash('sha256', 'wrong-profile')]);

        $receipt = $this->execute();

        self::assertSame('blocked', $receipt['status']);
        self::assertContains(
            'local_profile_scheduler_profile_binding_mismatch',
            array_column($receipt['blockers'], 'code')
        );
    }

    public function testNonReusableProfileStateBlocksBeforeWrite(): void
    {
        $service = new LocalBrowserProfileSchedulerBindingService(
            static fn(array $source): array => strtolower((string)($source['platform'] ?? '')) === 'ctrip'
                ? [
                    'status' => 'expired',
                    'is_reusable' => false,
                    'reason' => 'profile_reauthentication_required',
                ]
                : ['status' => 'reusable', 'is_reusable' => true],
            $this->clock(...)
        );

        $receipt = $service->preflight(
            self::TENANT_ID,
            self::HOTEL_ID,
            self::USER_ID,
            self::CTRIP_SOURCE_ID,
            self::MEITUAN_SOURCE_ID,
            self::DEVICE_ID
        );

        self::assertSame('blocked', $receipt['status']);
        self::assertContains(
            'local_profile_scheduler_profile_session_not_reusable',
            array_column($receipt['blockers'], 'code')
        );
    }

    public function testCurrentSessionScopeDriftBlocksBeforeWrite(): void
    {
        $config = $this->config(self::MEITUAN_SOURCE_ID);
        $config['current_session_probe_data_source_id'] = 999;
        $this->replaceConfig(self::MEITUAN_SOURCE_ID, $config);

        $receipt = $this->execute();

        self::assertSame('blocked', $receipt['status']);
        self::assertContains(
            'local_profile_scheduler_current_session_scope_drift',
            array_column($receipt['blockers'], 'code')
        );
    }

    public function testPreviousDaySessionProofCannotAuthorizeSchedulerBinding(): void
    {
        $config = $this->config(self::CTRIP_SOURCE_ID);
        $config['current_session_probe_at'] = '2026-08-10 23:59:00';
        $config['current_session_probe_date'] = '2026-08-10';
        $this->replaceConfig(self::CTRIP_SOURCE_ID, $config);

        $receipt = $this->execute();

        self::assertSame('blocked', $receipt['status']);
        self::assertContains(
            'local_profile_scheduler_current_session_scope_drift',
            array_column($receipt['blockers'], 'code')
        );
        self::assertFalse($receipt['database_write_performed']);
    }

    public function testCrossHotelCanonicalOwnerBlocksBeforeWrite(): void
    {
        Db::name('platform_data_sources')->insert([
            'id' => 99,
            'tenant_id' => 81,
            'user_id' => 99,
            'system_hotel_id' => 81,
            'platform' => 'ctrip',
            'ingestion_method' => 'browser_profile',
            'enabled' => 1,
            'status' => 'ready',
            'config_json' => json_encode([
                'platform_hotel_id' => self::CTRIP_PLATFORM_HOTEL_ID,
            ], JSON_THROW_ON_ERROR),
            'update_time' => null,
        ]);

        $receipt = $this->execute();

        self::assertSame('blocked', $receipt['status']);
        self::assertContains(
            'local_profile_scheduler_cross_hotel_identity_conflict',
            array_column($receipt['blockers'], 'code')
        );
    }

    #[DataProvider('existingBindingConflictProvider')]
    public function testDifferentOrIncompleteExistingBindingFailsClosed(array $bindingPatch): void
    {
        $config = $this->config(self::CTRIP_SOURCE_ID);
        foreach ($bindingPatch as $key => $value) {
            $config[$key] = $value;
        }
        $this->replaceConfig(self::CTRIP_SOURCE_ID, $config);
        $before = $this->sourceConfigJsonById();

        $receipt = $this->execute();

        self::assertSame('blocked', $receipt['status']);
        self::assertContains(
            'local_profile_scheduler_existing_binding_conflict',
            array_column($receipt['blockers'], 'code')
        );
        self::assertSame($before, $this->sourceConfigJsonById());
    }

    public static function existingBindingConflictProvider(): array
    {
        return [
            'incomplete declaration' => [[
                'source_method' => 'single_user_local',
            ]],
            'different device' => [[
                'source_method' => 'single_user_local',
                'collector_binding_mode' => 'single_user_local',
                'collector_device_id' => 'OTHER-LOCAL-DEVICE',
                'collector_device_id_hash' => hash('sha256', 'OTHER-LOCAL-DEVICE'),
                'collector_user_id' => self::USER_ID,
                'collector_tenant_id' => self::TENANT_ID,
                'collector_hotel_id' => self::HOTEL_ID,
                'collector_platform' => 'ctrip',
                'collector_bound_at' => '2026-08-11 09:30:00',
            ]],
        ];
    }

    public function testExecuteIsIdempotentWithStableBoundAtAndNoSecondWrite(): void
    {
        $first = $this->execute();
        $afterFirst = $this->sourceConfigJsonById();
        $second = $this->execute();

        self::assertSame('ready', $first['status']);
        self::assertTrue($first['database_write_performed']);
        self::assertSame('ready', $second['status']);
        self::assertTrue($second['bound']);
        self::assertTrue($second['already_bound']);
        self::assertFalse($second['database_write_performed']);
        self::assertSame(0, $second['write']['affected_rows']);
        self::assertTrue($second['write']['idempotent']);
        self::assertTrue($second['write']['readback_verified']);
        self::assertSame($afterFirst, $this->sourceConfigJsonById());
    }

    public function testCrossTenantSuperAdminStillRequiresExactHotelGrant(): void
    {
        Db::name('users')->where('id', self::USER_ID)->update([
            'tenant_id' => 7,
            'role_id' => 1,
        ]);

        $ready = $this->service()->preflight(
            self::TENANT_ID,
            self::HOTEL_ID,
            self::USER_ID,
            self::CTRIP_SOURCE_ID,
            self::MEITUAN_SOURCE_ID,
            self::DEVICE_ID
        );
        self::assertSame('ready', $ready['status']);
        self::assertSame('cross_tenant_super_admin_explicit_hotel_grant', $ready['authorization_mode']);

        Db::name('user_hotel_permissions')->where('id', 1)->delete();
        $blocked = $this->service()->preflight(
            self::TENANT_ID,
            self::HOTEL_ID,
            self::USER_ID,
            self::CTRIP_SOURCE_ID,
            self::MEITUAN_SOURCE_ID,
            self::DEVICE_ID
        );
        self::assertSame('blocked', $blocked['status']);
        self::assertContains(
            'local_profile_scheduler_fetch_permission_missing',
            array_column($blocked['blockers'], 'code')
        );
    }

    public function testCliDefaultsToPreflightAndRequiresExactConfirmationWithoutEchoingInputs(): void
    {
        $script = (string)file_get_contents(
            dirname(__DIR__) . '/scripts/bind_local_browser_profile_scheduler.php'
        );

        self::assertStringContainsString("'execute' => false", $script);
        self::assertStringContainsString('executionConfirmation(', $script);
        self::assertStringContainsString('hash_equals($requiredConfirmation', $script);
        self::assertStringContainsString('local_profile_scheduler_binding_confirmation_mismatch', $script);
        self::assertStringNotContainsString("'collector_device_id' =>", $script);
        self::assertStringNotContainsString("'platform_hotel_id' =>", $script);
        self::assertStringNotContainsString("'profile_key' =>", $script);
    }

    /** @return array<string,mixed> */
    private function execute(): array
    {
        return $this->service()->execute(
            self::TENANT_ID,
            self::HOTEL_ID,
            self::USER_ID,
            self::CTRIP_SOURCE_ID,
            self::MEITUAN_SOURCE_ID,
            self::DEVICE_ID
        );
    }

    private function service(): LocalBrowserProfileSchedulerBindingService
    {
        return new LocalBrowserProfileSchedulerBindingService(
            static fn(array $source): array => [
                'status' => 'reusable',
                'is_reusable' => true,
                'age_days' => 1,
                'reason' => 'profile_proof_reusable',
            ],
            $this->clock(...)
        );
    }

    private function clock(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-11 10:00:00', new DateTimeZone('Asia/Shanghai'));
    }

    private function createSchema(): void
    {
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, status INTEGER NOT NULL)');
        Db::execute('CREATE TABLE users (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, role_id INTEGER NOT NULL, status INTEGER NOT NULL)');
        Db::execute('CREATE TABLE user_hotel_permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, user_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, can_fetch_online_data INTEGER NOT NULL, status TEXT, expires_at TEXT)');
        Db::execute('CREATE TABLE platform_data_sources (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, user_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, platform TEXT NOT NULL, ingestion_method TEXT NOT NULL, enabled INTEGER NOT NULL, status TEXT, config_json TEXT NOT NULL, update_time TEXT)');
        Db::execute('CREATE TABLE ota_profile_bindings (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, platform TEXT NOT NULL, profile_key_hash TEXT NOT NULL, binding_status TEXT NOT NULL)');
    }

    private function seedReadyScope(): void
    {
        Db::name('hotels')->insert([
            'id' => self::HOTEL_ID,
            'tenant_id' => self::TENANT_ID,
            'status' => 1,
        ]);
        Db::name('users')->insert([
            'id' => self::USER_ID,
            'tenant_id' => self::TENANT_ID,
            'role_id' => 2,
            'status' => 1,
        ]);
        Db::name('user_hotel_permissions')->insert([
            'id' => 1,
            'tenant_id' => self::TENANT_ID,
            'user_id' => self::USER_ID,
            'hotel_id' => self::HOTEL_ID,
            'can_fetch_online_data' => 1,
            'status' => 'active',
            'expires_at' => '2026-08-12 10:00:00',
        ]);

        foreach ([
            [self::CTRIP_SOURCE_ID, 'ctrip', self::CTRIP_PROFILE_KEY, self::CTRIP_PLATFORM_HOTEL_ID],
            [self::MEITUAN_SOURCE_ID, 'meituan', self::MEITUAN_PROFILE_KEY, self::MEITUAN_PLATFORM_HOTEL_ID],
        ] as [$sourceId, $platform, $profileKey, $platformHotelId]) {
            Db::name('platform_data_sources')->insert([
                'id' => $sourceId,
                'tenant_id' => self::TENANT_ID,
                'user_id' => self::USER_ID,
                'system_hotel_id' => self::HOTEL_ID,
                'platform' => $platform,
                'ingestion_method' => 'browser_profile',
                'enabled' => 1,
                'status' => 'ready',
                'config_json' => json_encode(
                    $this->readyConfig($sourceId, $platform, $profileKey, $platformHotelId),
                    JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
                ),
                'update_time' => '2026-08-11 09:15:00',
            ]);
            Db::name('ota_profile_bindings')->insert([
                'tenant_id' => self::TENANT_ID,
                'system_hotel_id' => self::HOTEL_ID,
                'platform' => $platform,
                'profile_key_hash' => hash(
                    'sha256',
                    BrowserProfileCaptureRequestService::safeFilePart($profileKey)
                ),
                'binding_status' => 'active',
            ]);
        }
    }

    /** @return array<string,mixed> */
    private function readyConfig(
        int $sourceId,
        string $platform,
        string $profileKey,
        string $platformHotelId
    ): array {
        $profileHash = hash('sha256', BrowserProfileCaptureRequestService::safeFilePart($profileKey));
        return [
            'profile_binding_key' => $profileKey,
            'platform_hotel_id' => $platformHotelId,
            'platform_hotel_identity_source' => 'same_origin_profile_probe',
            'platform_hotel_identity_checked_at' => '2026-08-11 09:00:00',
            'current_session_probe_performed' => true,
            'current_session_verified' => true,
            'current_session_status' => 'verified',
            'current_session_probe_at' => '2026-08-11 09:15:00',
            'current_session_probe_date' => '2026-08-11',
            'current_session_probe_timezone' => 'Asia/Shanghai',
            'current_session_probe_data_source_id' => $sourceId,
            'current_session_probe_tenant_id' => self::TENANT_ID,
            'current_session_probe_system_hotel_id' => self::HOTEL_ID,
            'current_session_probe_platform' => $platform,
            'current_session_probe_profile_key_hash' => $profileHash,
            'current_session_probe_platform_hotel_id' => $platformHotelId,
            'current_session_probe_scope' => 'same_data_source_profile_session',
            'current_session_probe_producer' => 'platform_profile_login_task',
            'current_session_probe_contract_version' => '2026-07-19.1',
            'current_session_probe_evidence_level' => 'strong',
            'current_session_probe_evidence_type' => 'recognized_business_response_2xx_plus_session_cookie',
            'current_session_probe_identity_status' => 'matched',
            'current_session_nested_marker' => ['keep' => true, 'count' => 1.0],
            'non_binding_sentinel' => 'keep-me-verbatim',
        ];
    }

    /** @return array<int,string> */
    private function sourceConfigJsonById(): array
    {
        $rows = Db::name('platform_data_sources')
            ->field('id,config_json')
            ->whereIn('id', [self::CTRIP_SOURCE_ID, self::MEITUAN_SOURCE_ID])
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['id']] = (string)$row['config_json'];
        }
        return $result;
    }

    /** @return array<int,array<string,mixed>> */
    private function sourceConfigsById(): array
    {
        $result = [];
        foreach ($this->sourceConfigJsonById() as $id => $raw) {
            $result[$id] = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function config(int $sourceId): array
    {
        $raw = (string)Db::name('platform_data_sources')->where('id', $sourceId)->value('config_json');
        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $config */
    private function replaceConfig(int $sourceId, array $config): void
    {
        Db::name('platform_data_sources')->where('id', $sourceId)->update([
            'config_json' => json_encode(
                $config,
                JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
            ),
        ]);
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    private function currentSessionFields(array $config): array
    {
        $fields = [];
        foreach ($config as $key => $value) {
            if (str_starts_with((string)$key, 'current_session_')) {
                $fields[(string)$key] = $value;
            }
        }
        ksort($fields, SORT_STRING);
        return $fields;
    }

    /** @param array<string,mixed> $receipt */
    private function assertSafeReceipt(array $receipt): void
    {
        $json = json_encode($receipt, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::DEVICE_ID, $json);
        self::assertStringNotContainsString(self::CTRIP_PLATFORM_HOTEL_ID, $json);
        self::assertStringNotContainsString(self::MEITUAN_PLATFORM_HOTEL_ID, $json);
        self::assertStringNotContainsString(self::CTRIP_PROFILE_KEY, $json);
        self::assertStringNotContainsString(self::MEITUAN_PROFILE_KEY, $json);
        self::assertStringNotContainsString('cookie', strtolower($json));
        self::assertStringNotContainsString('token', strtolower($json));
        self::assertFalse($receipt['sensitive_values_exposed']);
    }
}
