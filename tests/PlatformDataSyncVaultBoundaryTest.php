<?php
declare(strict_types=1);

namespace Tests;

use app\contract\DataSourceAdapter;
use app\service\OtaCredentialEnvelope;
use app\service\OtaCredentialVault;
use app\service\OtaProfileSessionProofService;
use app\service\PlatformDataSyncService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class PlatformDataSyncVaultBoundaryTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . '/platform_data_sync_vault_' . getmypid() . '.sqlite';
        @unlink(self::$sqlitePath);

        $config = self::$originalDatabaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$sqlitePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($config, 'database');
        Db::connect(null, true);

        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name VARCHAR(100))');
        Db::execute('CREATE TABLE ota_credentials (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, platform VARCHAR(20) NOT NULL, config_id VARCHAR(100) NOT NULL, encrypted_payload TEXT NOT NULL, payload_version INTEGER NOT NULL, key_id VARCHAR(100) NOT NULL, secret_mask VARCHAR(255) NOT NULL, credential_status VARCHAR(20) NOT NULL, created_by INTEGER NOT NULL, rotated_at DATETIME, create_time DATETIME, update_time DATETIME, UNIQUE(tenant_id,system_hotel_id,platform,config_id))');
        Db::execute('CREATE TABLE ota_profile_bindings (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, platform VARCHAR(20) NOT NULL, profile_key_hash VARCHAR(64) NOT NULL, binding_status VARCHAR(20) NOT NULL, bound_by INTEGER, revoked_by INTEGER, create_time DATETIME, update_time DATETIME, UNIQUE(platform,profile_key_hash))');
        Db::execute('CREATE TABLE platform_data_sources (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, system_hotel_id INTEGER, user_id INTEGER, name VARCHAR(120) NOT NULL, platform VARCHAR(50) NOT NULL, data_type VARCHAR(50) NOT NULL, ingestion_method VARCHAR(30) NOT NULL, status VARCHAR(30) NOT NULL, enabled INTEGER NOT NULL, config_json TEXT, secret_json TEXT, last_sync_time DATETIME, last_sync_status VARCHAR(30), last_error TEXT, created_by INTEGER, updated_by INTEGER, create_time DATETIME, update_time DATETIME)');
        Db::execute('CREATE TABLE platform_data_sync_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, data_source_id INTEGER, system_hotel_id INTEGER, platform VARCHAR(50) NOT NULL, data_type VARCHAR(50) NOT NULL, ingestion_method VARCHAR(30) NOT NULL, trigger_type VARCHAR(30) NOT NULL, status VARCHAR(30) NOT NULL, attempt_count INTEGER NOT NULL, max_attempts INTEGER NOT NULL, started_at DATETIME, finished_at DATETIME, next_retry_at DATETIME, requested_by INTEGER, message TEXT, stats_json TEXT, create_time DATETIME, update_time DATETIME)');
        Db::execute('CREATE TABLE platform_data_sync_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, sync_task_id INTEGER, data_source_id INTEGER, system_hotel_id INTEGER, level VARCHAR(20), event VARCHAR(80), message TEXT, context_json TEXT, create_time DATETIME)');
        Db::execute('CREATE TABLE platform_data_raw_records (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, data_source_id INTEGER, sync_task_id INTEGER, system_hotel_id INTEGER, platform VARCHAR(50), data_type VARCHAR(50), ingestion_method VARCHAR(30), payload_hash VARCHAR(64), raw_payload TEXT, http_status INTEGER, received_at DATETIME, create_time DATETIME)');
        Db::name('hotels')->insertAll([
            ['id' => 101, 'tenant_id' => 7, 'name' => 'Hotel A'],
            ['id' => 102, 'tenant_id' => 8, 'name' => 'Hotel B'],
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$sqlitePath);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Db::name('platform_data_raw_records')->delete(true);
        Db::name('platform_data_sync_logs')->delete(true);
        Db::name('platform_data_sync_tasks')->delete(true);
        Db::name('platform_data_sources')->delete(true);
        Db::name('ota_credentials')->delete(true);
        Db::name('ota_profile_bindings')->delete(true);
    }

    public function testOtaSaveUsesHotelTenantVaultAndMetadataOnlySourceStorage(): void
    {
        $service = $this->service();
        $saved = $service->saveDataSource($this->user(), [
            'name' => 'Ctrip API',
            'system_hotel_id' => 101,
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'ingestion_method' => 'api',
            'config' => [
                'config_id' => 'cfg-101',
                'profile_id' => 'ctrip-101',
                'stable_profile_id' => 'ctrip-101',
                'profile_binding_key' => 'ctrip-101',
                'profile_reuse_scope' => 'ota_account_store',
                'url' => 'https://ebooking.ctrip.com/traffic',
                'headers' => [
                    'Authorization' => 'Bearer source-config-secret',
                    'Content-Type' => 'application/json',
                ],
                'payload' => ['data_date' => '2026-07-09'],
                'untrusted' => 'must-not-persist',
            ],
            'secret' => ['cookies' => 'ctrip-cookie-secret', 'token' => 'ctrip-token-secret'],
            'auth_data' => ['account' => 'bound-account-secret'],
            'spiderkey' => 'ctrip-spider-key-secret',
        ]);

        $row = Db::name('platform_data_sources')->where('id', (int)$saved['id'])->find();
        $config = json_decode((string)$row['config_json'], true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(7, (int)$row['tenant_id']);
        self::assertSame('{}', (string)$row['secret_json']);
        self::assertSame('cfg-101', $config['config_id']);
        self::assertGreaterThan(0, (int)$config['credential_ref']);
        self::assertSame('ready', $config['credential_status']);
        self::assertSame('ready', $config['status']);
        self::assertTrue($config['has_cookies']);
        self::assertSame('ctrip-101', $config['profile_id']);
        self::assertSame('ctrip-101', $config['stable_profile_id']);
        self::assertSame('ctrip-101', $config['profile_binding_key']);
        self::assertSame('ota_account_store', $config['profile_reuse_scope']);
        self::assertSame(['Content-Type' => 'application/json'], $config['headers']);
        self::assertSame(['data_date' => '2026-07-09'], $config['payload']);
        self::assertStringNotContainsString('source-config-secret', (string)$row['config_json']);
        self::assertArrayNotHasKey('untrusted', $config);
        self::assertTrue($saved['has_secret']);
        self::assertTrue($saved['has_cookies']);
        self::assertStringNotContainsString('ctrip-cookie-secret', json_encode($saved, JSON_THROW_ON_ERROR));

        $secret = $this->vault()->withPayloadForExecution(7, 101, 'ctrip', 'cfg-101', static fn(array $payload): array => $payload);
        self::assertSame('ctrip-cookie-secret', $secret['cookies']);
        self::assertSame('ctrip-token-secret', $secret['token']);
        self::assertSame('Bearer source-config-secret', $secret['authorization']);
        self::assertSame(['account' => 'bound-account-secret'], $secret['auth_data']);
        self::assertSame('ctrip-spider-key-secret', $secret['spiderkey']);
    }

    public function testBlankOtaSecretUpdatePreservesExistingVaultCredential(): void
    {
        $service = $this->service();
        $created = $service->saveDataSource($this->user(), [
            'name' => 'Meituan API',
            'system_hotel_id' => 101,
            'platform' => 'meituan',
            'data_type' => 'business',
            'ingestion_method' => 'api',
            'config' => ['config_id' => 'mt-101', 'url' => 'https://eb.meituan.com/business'],
            'secret' => ['cookies' => 'meituan-cookie-secret'],
        ]);

        $updated = $service->saveDataSource($this->user(), [
            'id' => $created['id'],
            'name' => 'Meituan API renamed',
            'system_hotel_id' => 101,
            'platform' => 'meituan',
            'data_type' => 'business',
            'ingestion_method' => 'api',
            'config' => ['config_id' => 'mt-101', 'url' => 'https://eb.meituan.com/business-v2'],
            'secret' => ['cookies' => ''],
        ]);

        $cookie = $this->vault()->withPayloadForExecution(7, 101, 'meituan', 'mt-101', static fn(array $payload): string => (string)$payload['cookies']);
        self::assertSame('meituan-cookie-secret', $cookie);
        self::assertTrue($updated['has_cookies']);
        self::assertSame('{}', (string)Db::name('platform_data_sources')->where('id', (int)$created['id'])->value('secret_json'));
    }

    public function testNewNonProfileOtaSourceWithoutReusableSecretIsRejectedWithoutWrites(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(422);
        $this->expectExceptionMessage('New OTA data source requires a reusable credential.');

        try {
            $this->service()->saveDataSource($this->user(), [
                'name' => 'Ctrip API without credential',
                'system_hotel_id' => 101,
                'platform' => 'ctrip',
                'data_type' => 'traffic',
                'ingestion_method' => 'api',
                'config' => [
                    'config_id' => 'empty-credential-101',
                    'url' => 'https://ebooking.ctrip.com/traffic',
                ],
                'secret' => ['cookies' => ''],
            ]);
        } finally {
            self::assertSame(0, (int)Db::name('ota_credentials')->count());
            self::assertSame(0, (int)Db::name('platform_data_sources')->count());
        }
    }

    public function testBrowserProfileLocatorWithoutReusableSecretDoesNotClaimSecretCustody(): void
    {
        $saved = $this->service()->saveDataSource($this->user(), [
            'name' => 'Ctrip browser profile',
            'system_hotel_id' => 101,
            'platform' => 'ctrip',
            'data_type' => 'business',
            'ingestion_method' => 'browser_profile',
            'config' => [
                'profile_id' => 'profile-101',
                'stable_profile_id' => 'profile-101',
                'manual_login_state_verified' => true,
                'profile_status' => 'logged_in',
                'last_login_verified_at' => '2026-07-10 10:00:00',
            ],
        ]);

        self::assertFalse($saved['has_secret']);
        self::assertFalse($saved['has_cookies']);
        self::assertSame('not_required', $saved['credential_status']);
        self::assertSame('not_required_for_browser_profile', $saved['config']['credential_usage']);
        self::assertSame(0, Db::name('ota_credentials')->count());
    }

    public function testSavingSameBrowserProfileReusesExistingDataSource(): void
    {
        $service = $this->service();
        $first = $service->saveDataSource($this->user(), [
            'name' => 'Ctrip browser profile first save',
            'system_hotel_id' => 101,
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'ingestion_method' => 'browser_profile',
            'config' => [
                'profile_id' => 'profile-101',
                'last_login_verified_at' => '2026-07-10 10:00:00',
            ],
        ]);
        $firstConfigId = (string)($first['config']['config_id'] ?? '');

        $second = $service->saveDataSource($this->user(), [
            'name' => 'Ctrip browser profile refreshed',
            'system_hotel_id' => 101,
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'ingestion_method' => 'browser_profile',
            'config' => [
                'profile_id' => 'profile-101',
                'last_login_verified_at' => '2026-07-11 10:00:00',
            ],
        ]);

        self::assertSame((int)$first['id'], (int)$second['id']);
        self::assertSame(1, (int)Db::name('platform_data_sources')->count());
        self::assertSame($firstConfigId, (string)($second['config']['config_id'] ?? ''));
        self::assertSame('Ctrip browser profile refreshed', $second['name']);
        self::assertSame('2026-07-11 10:00:00', $second['config']['last_login_verified_at']);
    }

    public function testBrowserProfileSourceRejectsReusableCredentialCustody(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Browser Profile data source must not store reusable OTA credentials');

        try {
            $this->service()->saveDataSource($this->user(), [
                'name' => 'Ctrip browser profile with forbidden cookie',
                'system_hotel_id' => 101,
                'platform' => 'ctrip',
                'data_type' => 'traffic',
                'ingestion_method' => 'browser_profile',
                'config' => [
                    'profile_id' => 'profile-101',
                    'hotel_id' => 'ctrip-hotel-101',
                ],
                'secret' => ['cookies' => 'must-not-enter-browser-profile-source'],
            ]);
        } finally {
            self::assertSame(0, Db::name('ota_credentials')->count());
            self::assertSame(0, Db::name('platform_data_sources')->count());
        }
    }

    public function testNormalizedRowsUseSourceTenantAndResolveMissingTenantFromHotel(): void
    {
        $service = $this->service();
        $payload = ['rows' => [[
            'data_date' => '2026-07-10',
            'amount' => 128.5,
        ]]];
        $source = [
            'id' => 501,
            'tenant_id' => 7,
            'system_hotel_id' => 101,
            'platform' => 'ctrip',
            'data_type' => 'business',
            'ingestion_method' => 'api',
        ];

        $rows = $service->normalizeRowsFromPayload($payload, $source, 601);
        self::assertSame(7, $rows[0]['tenant_id']);

        unset($source['tenant_id']);
        $fallbackRows = $service->normalizeRowsFromPayload($payload, $source, 602);
        self::assertSame(7, $fallbackRows[0]['tenant_id']);
        self::assertNotSame(101, $fallbackRows[0]['tenant_id']);
    }

    public function testRawRecordsUseSourceTenantAndResolveMissingTenantFromHotel(): void
    {
        $service = $this->service();
        $store = new \ReflectionMethod($service, 'storeRawRecord');
        $store->setAccessible(true);
        $source = [
            'id' => 501,
            'tenant_id' => 7,
            'system_hotel_id' => 101,
            'platform' => 'ctrip',
            'data_type' => 'business',
            'ingestion_method' => 'api',
        ];

        $store->invoke($service, $source, 601, ['rows' => []], 200);
        unset($source['tenant_id']);
        $store->invoke($service, $source, 602, ['rows' => []], 200);

        self::assertSame(
            [7, 7],
            array_map('intval', Db::name('platform_data_raw_records')->order('id')->column('tenant_id'))
        );
    }

    public function testOtaSourceCannotSwitchInPlaceFromVaultAuthorizationToBrowserProfile(): void
    {
        $service = $this->service();
        $created = $service->saveDataSource($this->user(), [
            'name' => 'Ctrip API before profile switch',
            'system_hotel_id' => 101,
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'ingestion_method' => 'api',
            'config' => ['config_id' => 'switch-101', 'url' => 'https://ebooking.ctrip.com/traffic'],
            'secret' => ['cookies' => 'switch-must-stay-linked'],
        ]);

        try {
            $service->saveDataSource($this->user(), [
                'id' => $created['id'],
                'name' => 'Ctrip Profile after unsafe switch',
                'system_hotel_id' => 101,
                'platform' => 'ctrip',
                'data_type' => 'traffic',
                'ingestion_method' => 'browser_profile',
                'config' => [
                    'config_id' => 'switch-101',
                    'profile_id' => 'profile-101',
                ],
            ]);
            self::fail('Cross-authorization-model update must be rejected.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('cannot switch authorization model in place', $e->getMessage());
        }

        $stored = Db::name('platform_data_sources')->where('id', (int)$created['id'])->find();
        $config = json_decode((string)$stored['config_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('api', $stored['ingestion_method']);
        self::assertSame((int)$created['credential_ref'], (int)$config['credential_ref']);
        self::assertSame(1, (int)Db::name('ota_credentials')->count());
        self::assertSame('ready', $this->vault()->metadata(7, 101, 'ctrip', 'switch-101')['credential_status']);
    }

    public function testOtaSourceSerializationUsesCredentialMetadataWithoutDecodingLegacySecretJson(): void
    {
        $service = $this->service();
        $method = new \ReflectionMethod($service, 'sanitizeSourceRow');
        $method->setAccessible(true);

        $row = $method->invoke($service, [
            'id' => 77,
            'platform' => 'ctrip',
            'config_json' => json_encode([
                'config_id' => 'cfg-77',
                'credential_ref' => 55,
                'credential_status' => 'ready',
                'has_cookies' => true,
            ], JSON_THROW_ON_ERROR),
            'secret_json' => json_encode(['cookies' => 'legacy-plaintext-must-be-ignored'], JSON_THROW_ON_ERROR),
        ]);

        self::assertTrue($row['has_secret']);
        self::assertTrue($row['has_cookies']);
        self::assertSame(55, $row['credential_ref']);
        self::assertSame('ready', $row['credential_status']);
        self::assertStringNotContainsString('legacy-plaintext-must-be-ignored', json_encode($row, JSON_THROW_ON_ERROR));
    }

    public function testOtaSyncDecryptsOnlyForAdapterAndRedactsAdapterResult(): void
    {
        $adapter = new class implements DataSourceAdapter {
            public array $seenSecret = [];

            public function supports(array $source): bool
            {
                return true;
            }

            public function fetch(array $source, array $options = []): array
            {
                $this->seenSecret = is_array($source['secret'] ?? null) ? $source['secret'] : [];
                return [
                    'status' => 'failed',
                    'message' => 'remote echoed ctrip-sync-secret',
                    'payload' => [
                        'echo' => 'ctrip-sync-secret',
                        'token' => 'ctrip-sync-secret',
                    ],
                    'http_status' => 401,
                ];
            }
        };
        $service = $this->service($adapter);
        $saved = $service->saveDataSource($this->user(), [
            'name' => 'Ctrip sync',
            'system_hotel_id' => 101,
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'ingestion_method' => 'api',
            'config' => ['config_id' => 'sync-101', 'url' => 'https://ebooking.ctrip.com/traffic'],
            'secret' => ['token' => 'ctrip-sync-secret'],
        ]);

        $result = $service->syncDataSource($this->user(), (int)$saved['id']);

        self::assertSame('ctrip-sync-secret', $adapter->seenSecret['token']);
        self::assertStringNotContainsString('ctrip-sync-secret', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame('{}', (string)Db::name('platform_data_sources')->where('id', (int)$saved['id'])->value('secret_json'));
        self::assertStringNotContainsString('ctrip-sync-secret', (string)Db::name('platform_data_sync_tasks')->where('data_source_id', (int)$saved['id'])->value('stats_json'));
        self::assertStringNotContainsString('ctrip-sync-secret', (string)Db::name('platform_data_sync_logs')->where('data_source_id', (int)$saved['id'])->value('context_json'));
    }

    public function testLegacyBrowserProfileSyncDoesNotRequireVaultLocator(): void
    {
        $adapter = new class implements DataSourceAdapter {
            public int $calls = 0;
            public bool $secretPresent = true;

            public function supports(array $source): bool
            {
                return ($source['ingestion_method'] ?? '') === 'browser_profile';
            }

            public function fetch(array $source, array $options = []): array
            {
                $this->calls++;
                $this->secretPresent = array_key_exists('secret', $source)
                    || array_key_exists('secret_json', $source);
                return [
                    'status' => 'failed',
                    'message' => 'profile_probe_failed',
                    'payload' => ['token' => 'must-not-cross-profile-boundary'],
                ];
            }
        };
        $service = $this->service($adapter);
        $sourceId = (int)Db::name('platform_data_sources')->insertGetId([
            'tenant_id' => 7,
            'system_hotel_id' => 101,
            'user_id' => 9,
            'name' => 'Legacy Ctrip browser Profile',
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'ingestion_method' => 'browser_profile',
            'status' => 'ready',
            'enabled' => 1,
            'config_json' => json_encode([
                'profile_id' => 'profile-101',
                'hotel_id' => 'ctrip-hotel-101',
                'manual_login_state_verified' => true,
                'profile_status' => 'logged_in',
                'last_login_verified_at' => '2026-07-10 10:00:00',
            ], JSON_THROW_ON_ERROR),
            'secret_json' => '{}',
            'created_by' => 9,
            'updated_by' => 9,
            'create_time' => '2026-07-10 10:00:00',
            'update_time' => '2026-07-10 10:00:00',
        ]);
        $this->bindProfile(7, 101, 'ctrip', 'profile-101');
        (new OtaProfileSessionProofService())->recordVerified(
            $sourceId,
            101,
            'ctrip',
            'profile-101',
            true,
            ['ok' => true, 'status' => 'logged_in']
        );

        $result = $service->syncDataSource($this->user(), $sourceId, [
            'data_date' => '2026-07-10',
            'capture_sections' => 'traffic',
        ]);

        self::assertSame(1, $adapter->calls);
        self::assertFalse($adapter->secretPresent);
        self::assertSame(0, Db::name('ota_credentials')->count());
        self::assertStringNotContainsString('must-not-cross-profile-boundary', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testBrowserProfileSyncNeverDecryptsOrInjectsVaultCredential(): void
    {
        $marker = 'profile-adapter-must-never-see-this-cookie';
        $credential = $this->vault()->store(
            7,
            101,
            'ctrip',
            'profile-with-vault-locator',
            ['cookies' => $marker],
            9
        );
        $adapter = new class implements DataSourceAdapter {
            public int $calls = 0;
            public string $seenSource = '';

            public function supports(array $source): bool
            {
                return ($source['ingestion_method'] ?? '') === 'browser_profile';
            }

            public function fetch(array $source, array $options = []): array
            {
                $this->calls++;
                $this->seenSource = json_encode($source, JSON_THROW_ON_ERROR);
                return ['status' => 'failed', 'message' => 'profile_probe_failed', 'payload' => []];
            }
        };
        $service = $this->service($adapter);
        $sourceId = (int)Db::name('platform_data_sources')->insertGetId([
            'tenant_id' => 7,
            'system_hotel_id' => 101,
            'user_id' => 9,
            'name' => 'Ctrip browser Profile with legacy Vault locator',
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'ingestion_method' => 'browser_profile',
            'status' => 'ready',
            'enabled' => 1,
            'config_json' => json_encode([
                'config_id' => 'profile-with-vault-locator',
                'credential_ref' => (int)$credential['credential_ref'],
                'credential_status' => 'ready',
                'profile_id' => 'profile-101',
                'hotel_id' => 'ctrip-hotel-101',
                'manual_login_state_verified' => true,
                'profile_status' => 'logged_in',
                'last_login_verified_at' => '2026-07-10 10:00:00',
            ], JSON_THROW_ON_ERROR),
            'secret_json' => '{}',
            'created_by' => 9,
            'updated_by' => 9,
            'create_time' => '2026-07-10 10:00:00',
            'update_time' => '2026-07-10 10:00:00',
        ]);
        $this->bindProfile(7, 101, 'ctrip', 'profile-101');
        (new OtaProfileSessionProofService())->recordVerified(
            $sourceId,
            101,
            'ctrip',
            'profile-101',
            true,
            ['ok' => true, 'status' => 'logged_in']
        );

        $service->syncDataSource($this->user(), $sourceId, [
            'data_date' => '2026-07-10',
            'capture_sections' => 'traffic',
        ]);

        self::assertSame(1, $adapter->calls);
        self::assertStringNotContainsString($marker, $adapter->seenSource);
        self::assertStringNotContainsString('"secret"', $adapter->seenSource);
        self::assertStringNotContainsString('secret_json', $adapter->seenSource);
    }

    public function testLegacyOtaSourceCannotExecuteEvilUrlOrInlineAuthorizationFromConfig(): void
    {
        $adapter = new class implements DataSourceAdapter {
            public int $calls = 0;

            public function supports(array $source): bool
            {
                return true;
            }

            public function fetch(array $source, array $options = []): array
            {
                $this->calls++;
                return ['status' => 'success', 'message' => 'must not execute', 'payload' => []];
            }
        };
        $service = $this->service($adapter);
        $cases = [
            'legacy-evil-host' => [
                'config' => ['url' => 'https://collector.evil.example/traffic'],
                'expected_message' => 'ota_source_url_not_allowed',
            ],
            'legacy-inline-auth' => [
                'config' => [
                    'url' => 'https://ebooking.ctrip.com/traffic',
                    'headers' => ['Authorization' => 'Bearer legacy-inline-secret'],
                ],
                'expected_message' => 'ota_source_inline_secret_requires_migration',
            ],
        ];

        foreach ($cases as $configId => $case) {
            $credential = $this->vault()->store(7, 101, 'ctrip', $configId, ['cookies' => 'vault-secret'], 9);
            $sourceId = (int)Db::name('platform_data_sources')->insertGetId([
                'tenant_id' => 7,
                'system_hotel_id' => 101,
                'user_id' => 9,
                'name' => $configId,
                'platform' => 'ctrip',
                'data_type' => 'traffic',
                'ingestion_method' => 'api',
                'status' => 'ready',
                'enabled' => 1,
                'config_json' => json_encode(array_merge($case['config'], [
                    'config_id' => $configId,
                    'credential_ref' => (int)$credential['credential_ref'],
                    'credential_status' => 'ready',
                    'has_secret' => true,
                    'has_cookies' => true,
                ]), JSON_THROW_ON_ERROR),
                'secret_json' => '{}',
                'created_by' => 9,
                'updated_by' => 9,
                'create_time' => '2026-07-10 10:00:00',
                'update_time' => '2026-07-10 10:00:00',
            ]);

            $result = $service->syncDataSource($this->user(), $sourceId);
            self::assertSame('failed', $result['status']);
            self::assertSame($case['expected_message'], $result['message']);
            self::assertStringNotContainsString('legacy-inline-secret', json_encode($result, JSON_THROW_ON_ERROR));
        }

        self::assertSame(0, $adapter->calls);
    }

    public function testCustomSourceKeepsLegacySecretJsonCompatibility(): void
    {
        $service = $this->service();
        $saved = $service->saveDataSource($this->user(), [
            'name' => 'Custom API',
            'system_hotel_id' => 101,
            'platform' => 'custom',
            'data_type' => 'business',
            'ingestion_method' => 'api',
            'config' => ['url' => 'https://example.com/custom'],
            'secret' => ['token' => 'custom-compatible-secret'],
        ]);

        $stored = json_decode((string)Db::name('platform_data_sources')->where('id', (int)$saved['id'])->value('secret_json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('custom-compatible-secret', $stored['token']);
        self::assertTrue($saved['has_secret']);
        self::assertStringNotContainsString('custom-compatible-secret', json_encode($saved, JSON_THROW_ON_ERROR));
    }

    public function testCustomSourcePersistsAuthoritativeHotelTenantInsteadOfHotelId(): void
    {
        $saved = $this->service()->saveDataSource($this->user(), [
            'name' => 'Custom tenant-scoped API',
            'system_hotel_id' => 101,
            'platform' => 'custom',
            'data_type' => 'business',
            'ingestion_method' => 'api',
            'config' => ['url' => 'https://example.com/custom'],
            'secret' => ['token' => 'custom-compatible-secret'],
        ]);

        $tenantId = (int)Db::name('platform_data_sources')
            ->where('id', (int)$saved['id'])
            ->value('tenant_id');

        self::assertSame(7, $tenantId);
        self::assertNotSame(101, $tenantId);
    }

    public function testOtaSaveRejectsCredentialExfiltrationUrlBeforeVaultWrite(): void
    {
        $this->expectException(\RuntimeException::class);
        try {
            $this->service()->saveDataSource($this->user(), [
                'name' => 'Unsafe Ctrip API',
                'system_hotel_id' => 101,
                'platform' => 'ctrip',
                'data_type' => 'traffic',
                'ingestion_method' => 'api',
                'config' => ['config_id' => 'unsafe-101', 'url' => 'https://collector.evil.example/traffic'],
                'secret' => ['cookies' => 'must-never-leave-vault'],
            ]);
        } finally {
            self::assertSame(0, Db::name('ota_credentials')->count());
            self::assertSame(0, Db::name('platform_data_sources')->count());
        }
    }

    public function testOtaSaveRejectsCredentialMaterialHiddenInJsonMetadata(): void
    {
        $this->expectException(\RuntimeException::class);
        try {
            $this->service()->saveDataSource($this->user(), [
                'name' => 'Unsafe Ctrip JSON payload',
                'system_hotel_id' => 101,
                'platform' => 'ctrip',
                'data_type' => 'traffic',
                'ingestion_method' => 'api',
                'config' => [
                    'config_id' => 'unsafe-json-101',
                    'url' => 'https://ebooking.ctrip.com/traffic',
                    'payload_json' => '{"date":"2026-07-09","token":"inline-secret"}',
                ],
                'secret' => ['cookies' => 'must-never-be-stored-after-rejection'],
            ]);
        } finally {
            self::assertSame(0, Db::name('ota_credentials')->count());
            self::assertSame(0, Db::name('platform_data_sources')->count());
        }
    }

    public function testDeletingLastOtaSourceRevokesCredentialAndScrubsLegacySecretColumn(): void
    {
        $service = $this->service();
        $saved = $service->saveDataSource($this->user(), [
            'name' => 'Ctrip source to disable',
            'system_hotel_id' => 101,
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'ingestion_method' => 'api',
            'config' => ['config_id' => 'delete-101', 'url' => 'https://ebooking.ctrip.com/traffic'],
            'secret' => ['cookies' => 'delete-secret'],
        ]);

        self::assertTrue($service->deleteDataSource($this->user(), (int)$saved['id']));
        $row = Db::name('platform_data_sources')->where('id', (int)$saved['id'])->find();
        $config = json_decode((string)$row['config_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, (int)$row['enabled']);
        self::assertSame('disabled', $row['status']);
        self::assertSame('{}', $row['secret_json']);
        self::assertSame('revoked', $config['credential_status']);
        self::assertSame('revoked', $this->vault()->metadata(7, 101, 'ctrip', 'delete-101')['credential_status']);

        $this->expectException(\RuntimeException::class);
        $this->vault()->withPayloadForExecution(7, 101, 'ctrip', 'delete-101', static fn(array $payload): array => $payload);
    }

    public function testDeletingSharedOtaSourceKeepsCredentialUntilLastEnabledReferenceStops(): void
    {
        $service = $this->service();
        $first = $service->saveDataSource($this->user(), [
            'name' => 'Shared Ctrip traffic',
            'system_hotel_id' => 101,
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'ingestion_method' => 'api',
            'config' => ['config_id' => 'shared-101', 'url' => 'https://ebooking.ctrip.com/traffic'],
            'secret' => ['cookies' => 'shared-secret'],
        ]);
        $second = $service->saveDataSource($this->user(), [
            'name' => 'Shared Ctrip business',
            'system_hotel_id' => 101,
            'platform' => 'ctrip',
            'data_type' => 'business',
            'ingestion_method' => 'api',
            'config' => ['config_id' => 'shared-101', 'url' => 'https://ebooking.ctrip.com/business'],
            'secret' => ['cookies' => 'shared-secret'],
        ]);

        self::assertTrue($service->deleteDataSource($this->user(), (int)$first['id']));
        self::assertSame('ready', $this->vault()->metadata(7, 101, 'ctrip', 'shared-101')['credential_status']);
        self::assertSame('shared-secret', $this->vault()->withPayloadForExecution(
            7,
            101,
            'ctrip',
            'shared-101',
            static fn(array $payload): string => (string)$payload['cookies']
        ));

        self::assertTrue($service->deleteDataSource($this->user(), (int)$second['id']));
        self::assertSame('revoked', $this->vault()->metadata(7, 101, 'ctrip', 'shared-101')['credential_status']);
    }

    public function testAdapterResultSanitizerDropsCredentialValuesUsedAsKeys(): void
    {
        $service = $this->service();
        $method = new \ReflectionMethod($service, 'sanitizeAdapterResultForCredentialBoundary');
        $method->setAccessible(true);

        $safe = $method->invoke($service, [
            'status' => 'success',
            'message' => 'ok',
            'payload' => [
                'vault-sentinel-12345' => 'value',
                'nested' => ['echo' => 'vault-sentinel-12345'],
                'token' => 'vault-sentinel-12345',
            ],
        ], ['cookies' => 'vault-sentinel-12345']);

        self::assertStringNotContainsString('vault-sentinel-12345', json_encode($safe, JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('token', $safe['payload']);
    }

    private function bindProfile(int $tenantId, int $hotelId, string $platform, string $profileKey): void
    {
        Db::name('ota_profile_bindings')->insert([
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'platform' => $platform,
            'profile_key_hash' => hash('sha256', $profileKey),
            'binding_status' => 'active',
            'bound_by' => 9,
            'create_time' => '2026-07-10 10:00:00',
            'update_time' => '2026-07-10 10:00:00',
        ]);
    }

    private function service(?DataSourceAdapter $adapter = null): PlatformDataSyncService
    {
        $adapter ??= new class implements DataSourceAdapter {
            public function supports(array $source): bool
            {
                return true;
            }

            public function fetch(array $source, array $options = []): array
            {
                return ['status' => 'failed', 'message' => 'not used', 'payload' => []];
            }
        };
        $service = new PlatformDataSyncService([$adapter], $this->vault());
        $columns = new \ReflectionProperty($service, 'columns');
        $columns->setAccessible(true);
        $columns->setValue($service, [
            'platform_data_sources' => array_fill_keys([
                'id', 'tenant_id', 'system_hotel_id', 'user_id', 'name', 'platform', 'data_type', 'ingestion_method',
                'status', 'enabled', 'config_json', 'secret_json', 'last_sync_time', 'last_sync_status', 'last_error',
                'created_by', 'updated_by', 'create_time', 'update_time',
            ], true),
            'platform_data_sync_tasks' => array_fill_keys([
                'id', 'tenant_id', 'data_source_id', 'system_hotel_id', 'platform', 'data_type', 'ingestion_method',
                'trigger_type', 'status', 'attempt_count', 'max_attempts', 'started_at', 'finished_at', 'next_retry_at',
                'requested_by', 'message', 'stats_json', 'create_time', 'update_time',
            ], true),
            'platform_data_sync_logs' => array_fill_keys([
                'id', 'tenant_id', 'sync_task_id', 'data_source_id', 'system_hotel_id', 'level', 'event', 'message',
                'context_json', 'create_time',
            ], true),
            'platform_data_raw_records' => array_fill_keys([
                'id', 'tenant_id', 'data_source_id', 'sync_task_id', 'system_hotel_id', 'platform', 'data_type',
                'ingestion_method', 'payload_hash', 'raw_payload', 'http_status', 'received_at', 'create_time',
            ], true),
        ]);
        return $service;
    }

    private function vault(): OtaCredentialVault
    {
        return new OtaCredentialVault(
            new OtaCredentialEnvelope(base64_encode(str_repeat('v', 32)), 'platform-sync-test-key'),
            'platform-sync-test-key'
        );
    }

    private function user(): object
    {
        return new class {
            public int $id = 9;

            public function isSuperAdmin(): bool
            {
                return true;
            }
        };
    }
}
