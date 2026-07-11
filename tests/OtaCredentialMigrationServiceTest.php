<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaCredentialEnvelope;
use app\service\OtaCredentialMigrationService;
use app\service\OtaCredentialVault;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OtaCredentialMigrationServiceTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $databasePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();

        self::$originalDatabaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . '/ota_migration_' . getmypid() . '.sqlite';
        @unlink(self::$databasePath);

        $database = self::$originalDatabaseConfig;
        $database['default'] = 'sqlite';
        $database['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$databasePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($database, 'database');
        Db::connect(null, true);
    }

    public static function tearDownAfterClass(): void
    {
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$databasePath);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
        foreach (['ota_credentials', 'platform_data_sources', 'system_configs', 'system_config', 'hotels'] as $table) {
            Db::name($table)->delete(true);
        }
        Db::name('hotels')->insertAll([
            ['id' => 10, 'tenant_id' => 1, 'name' => 'Tenant one'],
            ['id' => 20, 'tenant_id' => 2, 'name' => 'Tenant two'],
            ['id' => 30, 'tenant_id' => 0, 'name' => 'Invalid tenant'],
        ]);
    }

    public function testDryRunClassifiesAllLegacySourcesWithoutWritesOrSecrets(): void
    {
        $this->seedClassificationInventory();
        $before = $this->databaseSnapshot();

        $summary = $this->service()->run(false);

        self::assertSame('dry-run', $summary['mode']);
        self::assertSame('migration_required', $summary['status']);
        self::assertSame($before, $this->databaseSnapshot());
        self::assertSame(1, (int)Db::name('ota_credentials')->count());

        $allowed = [
            'bound_verified',
            'unbound',
            'field_conflict',
            'duplicate_config_id',
            'tenant_mismatch',
            'already_migrated',
        ];
        $classifications = array_column($summary['items'], 'classification');
        self::assertSame([], array_values(array_diff(array_unique($classifications), $allowed)));
        foreach ($allowed as $classification) {
            self::assertContains($classification, $classifications);
        }
        self::assertSame([
            'system_config',
            'system_configs',
            'platform_data_sources',
        ], array_keys($summary['sources']));

        $encoded = json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        foreach ($this->secretSentinels() as $secret) {
            self::assertStringNotContainsString($secret, $encoded);
        }
        foreach ($summary['items'] as $item) {
            self::assertSame([
                'item_id',
                'source_table',
                'source_row_id',
                'source_kind',
                'platform',
                'system_hotel_id',
                'classification',
                'reason_code',
            ], array_keys($item));
        }
    }

    public function testExecuteMigratesOnlyVerifiedBindingsRemovesPlaintextAndIsIdempotent(): void
    {
        $this->seedExecutableInventory();
        $service = $this->service();

        $summary = $service->run(true);

        self::assertSame('execute', $summary['mode']);
        self::assertSame('completed', $summary['status']);
        self::assertSame(5, $summary['migrated_count']);
        self::assertCount(5, $summary['migrated']);
        self::assertSame(5, (int)Db::name('ota_credentials')->count());
        self::assertLegacyStoresContainNoSecretSentinels($this->secretSentinels());

        $vault = $this->vault();
        self::assertSame('ctrip-list-secret', $vault->withPayloadForExecution(1, 10, 'ctrip', 'ctrip-list-ok', static fn(array $payload): string => (string)$payload['cookies']));
        self::assertSame('nested-meituan-secret', $vault->withPayloadForExecution(2, 20, 'meituan', 'meituan-data-ok', static fn(array $payload): string => (string)$payload['auth_data']['token']));
        self::assertSame('{"token":"payload-json-secret"}', $vault->withPayloadForExecution(2, 20, 'meituan', 'meituan-data-ok', static fn(array $payload): string => (string)$payload['payload_json']));
        self::assertSame('payload-array-secret', $vault->withPayloadForExecution(2, 20, 'meituan', 'meituan-data-ok', static fn(array $payload): string => (string)$payload['payload']['token']));
        self::assertSame('platform-source-secret', $vault->withPayloadForExecution(1, 10, 'ctrip', 'platform-source-ok', static fn(array $payload): string => (string)$payload['token']));
        self::assertSame('cookie-hotel-secret', $vault->withPayloadForExecution(1, 10, 'ctrip', 'cookie-hotel-ok', static fn(array $payload): string => (string)$payload['cookies']));
        self::assertSame('cookie-short-secret', $vault->withPayloadForExecution(2, 20, 'ctrip', 'cookie-short-ok', static fn(array $payload): string => (string)$payload['cookies']));

        $afterFirstRun = $this->databaseSnapshot();
        $second = $service->run(true);
        self::assertSame('completed', $second['status']);
        self::assertSame(0, $second['migrated_count']);
        self::assertSame($afterFirstRun, $this->databaseSnapshot());
        self::assertSame(5, (int)Db::name('ota_credentials')->count());
        self::assertNotContains('bound_verified', array_column($second['items'], 'classification'));
        $platformConfig = json_decode(
            (string)Db::name('platform_data_sources')->order('id', 'desc')->value('config_json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame('6866634', $platformConfig['hotel_id']);
        $dataConfig = json_decode(
            (string)Db::name('system_config')->where('config_key', 'data_config_meituan_business')->value('config_value'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame('rank', $dataConfig['payload']['metric']);
        self::assertArrayNotHasKey('token', $dataConfig['payload']);
        self::assertArrayNotHasKey('payload_json', $dataConfig);
    }

    public function testBrowserProfileSecretIsSanitizedAndNeverMigratedToVault(): void
    {
        $marker = 'profile-secret-must-not-enter-vault';
        Db::name('platform_data_sources')->insert([
            'tenant_id' => 1,
            'system_hotel_id' => 10,
            'platform' => 'ctrip',
            'ingestion_method' => 'browser_profile',
            'config_json' => $this->json(['config_id' => 'profile-secret-source', 'profile_id' => 'profile-10']),
            'secret_json' => $this->json(['cookies' => $marker]),
        ]);
        $storeCalls = 0;
        $service = new OtaCredentialMigrationService(
            $this->vault(),
            static function () use (&$storeCalls): array {
                $storeCalls++;
                throw new RuntimeException('Browser Profile secret must never reach the vault.');
            }
        );

        $dryRun = $service->run(false);
        self::assertSame('migration_required', $dryRun['status']);
        self::assertSame(0, $dryRun['eligible_count']);
        self::assertSame('profile_secret_cleanup_required', $dryRun['items'][0]['classification']);
        self::assertSame('browser_profile_credential_material_forbidden', $dryRun['items'][0]['reason_code']);
        self::assertSame(1, $dryRun['remaining_issue_count']);

        $execute = $service->run(true);
        self::assertSame('completed', $execute['status']);
        self::assertSame(0, $execute['migrated_count']);
        self::assertSame(1, $execute['sanitized_count']);
        self::assertSame(0, $execute['remaining_issue_count']);
        self::assertSame(0, $storeCalls);
        self::assertSame(0, (int)Db::name('ota_credentials')->count());
        self::assertSame(
            '{}',
            (string)Db::name('platform_data_sources')->where('system_hotel_id', 10)->value('secret_json')
        );
        $config = json_decode(
            (string)Db::name('platform_data_sources')->where('system_hotel_id', 10)->value('config_json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame('not_required_for_browser_profile', $config['credential_usage']);
        self::assertSame('not_required', $config['credential_status']);
        self::assertSame('not_required', $config['status']);
        self::assertFalse($config['has_secret']);
        self::assertFalse($config['has_cookies']);
        self::assertSame('profile_session_metadata_only_no_vault_decrypt', $config['profile_execution_policy']);
        self::assertSame('not_present', $config['profile_vault_detachment_status']);
        self::assertArrayNotHasKey('credential_ref', $config);
        self::assertStringNotContainsString($marker, json_encode($config, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($marker, json_encode($execute, JSON_THROW_ON_ERROR));

        $nextDryRun = $service->run(false);
        self::assertSame('ready', $nextDryRun['status']);
        self::assertSame(0, $nextDryRun['inventory_count']);
    }

    public function testBrowserProfileCleanupRevokesUnreferencedVaultCredential(): void
    {
        $vault = $this->vault();
        $credential = $vault->store(1, 10, 'ctrip', 'profile-vault-only', ['cookies' => 'vault-only-secret'], 7);
        Db::name('platform_data_sources')->insert([
            'tenant_id' => 1,
            'system_hotel_id' => 10,
            'platform' => 'ctrip',
            'ingestion_method' => 'browser_profile',
            'enabled' => 1,
            'config_json' => $this->json([
                'config_id' => 'profile-vault-only',
                'profile_id' => 'profile-10',
                'credential_ref' => $credential['credential_ref'],
                'credential_status' => 'ready',
                'has_secret' => true,
                'has_cookies' => true,
            ]),
            'secret_json' => '{}',
        ]);

        $summary = (new OtaCredentialMigrationService($vault))->run(true);

        self::assertSame('completed', $summary['status']);
        self::assertSame(1, $summary['sanitized_count']);
        self::assertSame('revoked', $summary['sanitized'][0]['vault_action']);
        self::assertSame('revoked', $vault->metadata(1, 10, 'ctrip', 'profile-vault-only')['credential_status']);
        $nextDryRun = (new OtaCredentialMigrationService($vault))->run(false);
        self::assertSame('ready', $nextDryRun['status']);
        self::assertSame(0, $nextDryRun['inventory_count']);
    }

    public function testBrowserProfileCleanupPreservesVaultCredentialUsedByEnabledNonProfileSource(): void
    {
        $vault = $this->vault();
        $credential = $vault->store(1, 10, 'ctrip', 'shared-vault', ['cookies' => 'shared-vault-secret'], 7);
        Db::name('platform_data_sources')->insertAll([
            [
                'tenant_id' => 1,
                'system_hotel_id' => 10,
                'platform' => 'ctrip',
                'ingestion_method' => 'browser_profile',
                'enabled' => 1,
                'config_json' => $this->json([
                    'config_id' => 'shared-vault',
                    'profile_id' => 'profile-10',
                    'credential_ref' => $credential['credential_ref'],
                    'credential_status' => 'ready',
                ]),
                'secret_json' => '{}',
            ],
            [
                'tenant_id' => 1,
                'system_hotel_id' => 10,
                'platform' => 'ctrip',
                'ingestion_method' => 'api',
                'enabled' => 1,
                'config_json' => $this->json([
                    'config_id' => 'shared-vault',
                    'credential_ref' => $credential['credential_ref'],
                    'credential_status' => 'ready',
                ]),
                'secret_json' => '{}',
            ],
        ]);

        $summary = (new OtaCredentialMigrationService($vault))->run(true);

        self::assertSame('completed', $summary['status']);
        self::assertSame(1, $summary['sanitized_count']);
        self::assertSame('preserved_enabled_non_profile_reference', $summary['sanitized'][0]['vault_action']);
        self::assertSame('ready', $vault->metadata(1, 10, 'ctrip', 'shared-vault')['credential_status']);
        $nextDryRun = (new OtaCredentialMigrationService($vault))->run(false);
        self::assertSame('ready', $nextDryRun['status']);
        self::assertSame(1, $nextDryRun['inventory_count']);
        self::assertSame('already_migrated', $nextDryRun['items'][0]['classification']);
    }

    public function testExecuteMigratesVerifiedItemsAndReportsRemainingUnboundWork(): void
    {
        Db::name('system_configs')->insert([
            'config_key' => 'ctrip_config_list',
            'config_value' => $this->json([
                'verified-item' => [
                    'id' => 'verified-item', 'config_id' => 'verified-item',
                    'hotel_id' => 10, 'system_hotel_id' => 10,
                    'cookies' => 'verified-item-secret',
                ],
                'unbound-item' => [
                    'id' => 'unbound-item', 'config_id' => 'unbound-item',
                    'owner_id' => 77,
                    'cookies' => 'unbound-item-secret',
                ],
            ]),
        ]);

        $summary = $this->service()->run(true);

        self::assertSame('migration_required', $summary['status']);
        self::assertSame(1, $summary['migrated_count']);
        self::assertSame(1, $summary['remaining_issue_count']);
        self::assertSame(1, $summary['classification_counts']['unbound']);
        self::assertSame(1, (int)Db::name('ota_credentials')->where('config_id', 'verified-item')->count());
        $legacy = (string)Db::name('system_configs')->where('config_key', 'ctrip_config_list')->value('config_value');
        self::assertStringNotContainsString('verified-item-secret', $legacy);
        self::assertStringContainsString('unbound-item-secret', $legacy);
    }

    public function testDryRunReportsMissingHotelCredentialWithoutWritesOrStore(): void
    {
        $marker = 'orphan-dry-run-secret';
        $this->seedMissingHotelCredential($marker);
        $before = $this->databaseSnapshot();
        $storeCalls = 0;
        $service = new OtaCredentialMigrationService(
            $this->vault(),
            static function () use (&$storeCalls): array {
                $storeCalls++;
                throw new RuntimeException('Orphan credentials must never reach the vault.');
            }
        );

        $summary = $service->run(false);

        self::assertSame('migration_required', $summary['status']);
        self::assertSame(1, $summary['remaining_issue_count']);
        self::assertSame(0, $summary['sanitized_count']);
        self::assertSame([], $summary['sanitized']);
        self::assertSame('tenant_mismatch', $summary['items'][0]['classification']);
        self::assertSame('hotel_not_found', $summary['items'][0]['reason_code']);
        self::assertSame(0, $storeCalls);
        self::assertSame($before, $this->databaseSnapshot());
        self::assertStringNotContainsString($marker, json_encode($summary, JSON_THROW_ON_ERROR));
    }

    public function testExecuteSanitizesMissingHotelCredentialWithoutVaultAndNextDryRunIsReady(): void
    {
        $marker = 'orphan-execute-secret';
        $this->seedMissingHotelCredential($marker);
        $storeCalls = 0;
        $service = new OtaCredentialMigrationService(
            $this->vault(),
            static function () use (&$storeCalls): array {
                $storeCalls++;
                throw new RuntimeException('Orphan credentials must never reach the vault.');
            }
        );

        $summary = $service->run(true);

        self::assertSame('completed', $summary['status']);
        self::assertSame(0, $summary['migrated_count']);
        self::assertSame(1, $summary['sanitized_count']);
        self::assertCount(1, $summary['sanitized']);
        self::assertSame('hotel_not_found', $summary['sanitized'][0]['reason_code']);
        self::assertSame(0, $summary['remaining_issue_count']);
        self::assertSame(1, $summary['initial_remaining_issue_count']);
        self::assertSame(1, $summary['classification_counts']['tenant_mismatch']);
        self::assertSame(0, $summary['post_execution_classification_counts']['tenant_mismatch']);
        self::assertSame(0, $storeCalls);
        self::assertSame(0, (int)Db::name('ota_credentials')->count());

        $stored = json_decode(
            (string)Db::name('system_configs')->where('config_key', 'ctrip_config_list')->value('config_value'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $orphan = $stored['orphan-hotel'];
        self::assertSame('orphan-hotel', $orphan['id']);
        self::assertSame('orphan-hotel', $orphan['config_id']);
        self::assertSame(999, $orphan['hotel_id']);
        self::assertSame(999, $orphan['system_hotel_id']);
        self::assertSame('Preserved safe metadata', $orphan['name']);
        self::assertSame('https://example.invalid/ota', $orphan['url']);
        self::assertSame('migration_required', $orphan['credential_status']);
        self::assertTrue($orphan['migration_required']);
        self::assertSame('hotel_not_found', $orphan['migration_reason']);
        self::assertFalse($orphan['has_cookies']);
        self::assertSame('', $orphan['secret_mask']);
        foreach ([
            'credential_ref',
            'key_id',
            'payload_version',
            'encrypted_payload',
            'ciphertext',
            'cookies',
            'auth_data',
        ] as $removedKey) {
            self::assertArrayNotHasKey($removedKey, $orphan);
        }
        self::assertStringNotContainsString($marker, json_encode($stored, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($marker, json_encode($summary, JSON_THROW_ON_ERROR));

        $nextDryRun = $service->run(false);
        self::assertSame('ready', $nextDryRun['status']);
        self::assertSame(0, $nextDryRun['remaining_issue_count']);
        self::assertSame(0, $nextDryRun['inventory_count']);
        self::assertSame([], $nextDryRun['items']);
        self::assertSame(0, $storeCalls);
    }

    public function testExecuteDoesNotHideUnrelatedUnboundCredentialWhenSanitizingMissingHotel(): void
    {
        $orphanMarker = 'orphan-selective-secret';
        $unboundMarker = 'unbound-must-remain-secret';
        Db::name('system_configs')->insert([
            'config_key' => 'ctrip_config_list',
            'config_value' => $this->json([
                'orphan-hotel' => [
                    'id' => 'orphan-hotel',
                    'config_id' => 'orphan-hotel',
                    'hotel_id' => 999,
                    'system_hotel_id' => 999,
                    'cookies' => $orphanMarker,
                ],
                'unbound-owner' => [
                    'id' => 'unbound-owner',
                    'config_id' => 'unbound-owner',
                    'owner_id' => 77,
                    'cookies' => $unboundMarker,
                ],
            ]),
        ]);
        $storeCalls = 0;
        $service = new OtaCredentialMigrationService(
            $this->vault(),
            static function () use (&$storeCalls): array {
                $storeCalls++;
                throw new RuntimeException('Unverified credentials must never reach the vault.');
            }
        );

        $summary = $service->run(true);

        self::assertSame('migration_required', $summary['status']);
        self::assertSame(1, $summary['sanitized_count']);
        self::assertSame(1, $summary['remaining_issue_count']);
        self::assertSame(2, $summary['initial_remaining_issue_count']);
        self::assertSame(1, $summary['classification_counts']['tenant_mismatch']);
        self::assertSame(1, $summary['classification_counts']['unbound']);
        self::assertSame(0, $summary['post_execution_classification_counts']['tenant_mismatch']);
        self::assertSame(1, $summary['post_execution_classification_counts']['unbound']);
        self::assertSame(0, $storeCalls);
        $legacy = (string)Db::name('system_configs')->where('config_key', 'ctrip_config_list')->value('config_value');
        self::assertStringNotContainsString($orphanMarker, $legacy);
        self::assertStringContainsString($unboundMarker, $legacy);

        $nextDryRun = $service->run(false);
        self::assertSame('migration_required', $nextDryRun['status']);
        self::assertSame(1, $nextDryRun['remaining_issue_count']);
        self::assertSame('unbound', $nextDryRun['items'][0]['classification']);
        self::assertSame('hotel_binding_missing_or_invalid', $nextDryRun['items'][0]['reason_code']);
    }

    public function testExecuteSanitizesMissingHotelPlatformSourceAcrossBothJsonColumns(): void
    {
        $configMarker = 'orphan-platform-config-secret';
        $secretMarker = 'orphan-platform-secret-json-secret';
        $sourceId = (int)Db::name('platform_data_sources')->insertGetId([
            'tenant_id' => 999,
            'system_hotel_id' => 999,
            'platform' => 'ctrip',
            'config_json' => $this->json([
                'config_id' => 'orphan-platform-source',
                'name' => 'Preserved platform metadata',
                'url' => 'https://example.invalid/platform',
                'credential_ref' => 555,
                'credential_status' => 'ready',
                'cookies' => $configMarker,
            ]),
            'secret_json' => $this->json([
                'access_token' => $secretMarker,
            ]),
        ]);
        $storeCalls = 0;
        $service = new OtaCredentialMigrationService(
            $this->vault(),
            static function () use (&$storeCalls): array {
                $storeCalls++;
                throw new RuntimeException('Orphan platform source must never reach the vault.');
            }
        );

        $summary = $service->run(true);

        self::assertSame('completed', $summary['status']);
        self::assertSame(1, $summary['sanitized_count']);
        self::assertSame(0, $summary['migrated_count']);
        self::assertSame(0, $summary['remaining_issue_count']);
        self::assertSame(0, $storeCalls);
        self::assertSame('{}', Db::name('platform_data_sources')->where('id', $sourceId)->value('secret_json'));
        $configJson = (string)Db::name('platform_data_sources')->where('id', $sourceId)->value('config_json');
        $config = json_decode($configJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('orphan-platform-source', $config['config_id']);
        self::assertSame(999, $config['system_hotel_id']);
        self::assertSame(999, $config['tenant_id']);
        self::assertSame('ctrip', $config['platform']);
        self::assertSame('Preserved platform metadata', $config['name']);
        self::assertSame('migration_required', $config['credential_status']);
        self::assertSame('hotel_not_found', $config['migration_reason']);
        self::assertArrayNotHasKey('credential_ref', $config);
        self::assertArrayNotHasKey('cookies', $config);
        self::assertStringNotContainsString($configMarker, $configJson);
        self::assertStringNotContainsString($secretMarker, $configJson);
        self::assertSame('ready', $service->run(false)['status']);
    }

    public function testExecuteRollsBackOrphanSanitizationWithOtherMigrationFailure(): void
    {
        Db::name('system_config')->insert([
            'config_key' => 'ctrip_config_list',
            'config_value' => $this->json([
                'verified-rollback' => [
                    'id' => 'verified-rollback',
                    'config_id' => 'verified-rollback',
                    'hotel_id' => 10,
                    'system_hotel_id' => 10,
                    'cookies' => 'verified-rollback-secret',
                ],
            ]),
        ]);
        $this->seedMissingHotelCredential('orphan-rollback-secret');
        $before = $this->databaseSnapshot();
        $dryRun = $this->service()->run(false);
        $firstClassification = (string)$dryRun['items'][0]['classification'];
        $vault = $this->vault();
        $storeCalls = 0;

        if ($firstClassification === 'bound_verified') {
            Db::execute("CREATE TRIGGER fail_orphan_sanitize BEFORE UPDATE ON system_configs BEGIN SELECT RAISE(ABORT, 'forced orphan sanitize failure'); END");
            $store = static function (
                int $tenantId,
                int $hotelId,
                string $platform,
                string $configId,
                array $payload,
                int $actorId
            ) use ($vault, &$storeCalls): array {
                $storeCalls++;
                return $vault->store($tenantId, $hotelId, $platform, $configId, $payload, $actorId);
            };
        } else {
            $store = static function () use (&$storeCalls): array {
                $storeCalls++;
                throw new RuntimeException('forced vault failure');
            };
        }
        $service = new OtaCredentialMigrationService($vault, $store);

        try {
            $service->run(true);
            self::fail('Migration failure must roll back orphan sanitization and vault writes.');
        } catch (RuntimeException $exception) {
            self::assertSame('OTA credential migration failed.', $exception->getMessage());
        } finally {
            Db::execute('DROP TRIGGER IF EXISTS fail_orphan_sanitize');
        }

        self::assertSame(1, $storeCalls);
        self::assertSame($before, $this->databaseSnapshot());
        self::assertSame(0, (int)Db::name('ota_credentials')->count());
    }

    public function testExecuteRollsBackWhenMissingHotelReappearsBeforeOrphanSanitization(): void
    {
        $rowId = (int)Db::name('system_configs')->insertGetId([
            'config_key' => 'ctrip_config_list',
            'config_value' => '{}',
        ]);
        $boundKey = '';
        $orphanKey = '';
        for ($index = 0; $index < 1000; $index++) {
            $candidateBound = 'race-bound-' . $index;
            $candidateOrphan = 'race-orphan-' . $index;
            $prefix = 'system_configs|' . $rowId . '|config_list|ctrip_config_list|';
            if (strcmp(
                substr(hash('sha256', $prefix . $candidateBound), 0, 20),
                substr(hash('sha256', $prefix . $candidateOrphan), 0, 20)
            ) < 0) {
                $boundKey = $candidateBound;
                $orphanKey = $candidateOrphan;
                break;
            }
        }
        self::assertNotSame('', $boundKey);
        self::assertNotSame('', $orphanKey);
        Db::name('system_configs')->where('id', $rowId)->update([
            'config_value' => $this->json([
                $boundKey => [
                    'id' => $boundKey,
                    'config_id' => $boundKey,
                    'hotel_id' => 10,
                    'system_hotel_id' => 10,
                    'cookies' => 'race-bound-secret',
                ],
                $orphanKey => [
                    'id' => $orphanKey,
                    'config_id' => $orphanKey,
                    'hotel_id' => 999,
                    'system_hotel_id' => 999,
                    'cookies' => 'race-orphan-secret',
                ],
            ]),
        ]);
        $vault = $this->vault();
        $storeCalls = 0;
        $service = new OtaCredentialMigrationService(
            $vault,
            static function (
                int $tenantId,
                int $hotelId,
                string $platform,
                string $configId,
                array $payload,
                int $actorId
            ) use ($vault, &$storeCalls): array {
                $storeCalls++;
                $metadata = $vault->store($tenantId, $hotelId, $platform, $configId, $payload, $actorId);
                Db::name('hotels')->insert([
                    'id' => 999,
                    'tenant_id' => 999,
                    'name' => 'Hotel restored during migration',
                ]);
                return $metadata;
            }
        );
        $dryRun = $service->run(false);
        self::assertSame(
            ['bound_verified', 'tenant_mismatch'],
            array_column($dryRun['items'], 'classification')
        );
        $before = $this->databaseSnapshot();

        try {
            $service->run(true);
            self::fail('A restored hotel must force a fresh dry-run instead of orphan sanitization.');
        } catch (RuntimeException $exception) {
            self::assertSame('OTA credential migration failed.', $exception->getMessage());
        }

        self::assertSame(1, $storeCalls);
        self::assertSame($before, $this->databaseSnapshot());
        self::assertSame(0, (int)Db::name('hotels')->where('id', 999)->count());
        self::assertSame(0, (int)Db::name('ota_credentials')->count());
    }

    public function testExecuteIncludesPostInventoryBlockersInSafeSummary(): void
    {
        Db::name('system_configs')->insert([
            'config_key' => 'ctrip_config_list',
            'config_value' => $this->json([
                'post-scan-bound' => [
                    'id' => 'post-scan-bound',
                    'config_id' => 'post-scan-bound',
                    'hotel_id' => 10,
                    'system_hotel_id' => 10,
                    'cookies' => 'post-scan-bound-secret',
                ],
            ]),
        ]);
        $safeRowId = (int)Db::name('system_config')->insertGetId([
            'config_key' => 'data_config_internal_notes',
            'config_value' => $this->json(['name' => 'initially valid safe metadata']),
        ]);
        $vault = $this->vault();
        $service = new OtaCredentialMigrationService(
            $vault,
            static function (
                int $tenantId,
                int $hotelId,
                string $platform,
                string $configId,
                array $payload,
                int $actorId
            ) use ($vault, $safeRowId): array {
                $metadata = $vault->store($tenantId, $hotelId, $platform, $configId, $payload, $actorId);
                Db::name('system_config')->where('id', $safeRowId)->update([
                    'config_value' => '{"invalid":',
                ]);
                return $metadata;
            }
        );

        $summary = $service->run(true);

        self::assertSame('blocked', $summary['status']);
        self::assertContains('invalid_json:system_config', $summary['blockers']);
        self::assertSame(1, $summary['migrated_count']);
        self::assertSame(0, $summary['remaining_issue_count']);
        self::assertStringNotContainsString(
            'post-scan-bound-secret',
            json_encode($summary, JSON_THROW_ON_ERROR)
        );
    }

    public function testExecuteRollsBackVaultAndLegacyWritesWhenAStoreFails(): void
    {
        Db::name('system_configs')->insert([
            'config_key' => 'ctrip_config_list',
            'config_value' => $this->json([
                'rollback-one' => [
                    'id' => 'rollback-one',
                    'config_id' => 'rollback-one',
                    'hotel_id' => 10,
                    'system_hotel_id' => 10,
                    'cookies' => 'rollback-secret-one',
                ],
                'rollback-two' => [
                    'id' => 'rollback-two',
                    'config_id' => 'rollback-two',
                    'hotel_id' => 20,
                    'system_hotel_id' => 20,
                    'cookies' => 'rollback-secret-two',
                ],
            ]),
        ]);
        $original = (string)Db::name('system_configs')->where('config_key', 'ctrip_config_list')->value('config_value');
        $vault = $this->vault();
        $calls = 0;
        $service = new OtaCredentialMigrationService(
            $vault,
            static function (
                int $tenantId,
                int $hotelId,
                string $platform,
                string $configId,
                array $payload,
                int $actorId
            ) use ($vault, &$calls): array {
                $calls++;
                if ($calls === 2) {
                    throw new RuntimeException('test store failure');
                }
                return $vault->store($tenantId, $hotelId, $platform, $configId, $payload, $actorId);
            }
        );

        try {
            $service->run(true);
            self::fail('Migration failure must be surfaced.');
        } catch (RuntimeException $exception) {
            self::assertSame('OTA credential migration failed.', $exception->getMessage());
        }

        self::assertSame(0, (int)Db::name('ota_credentials')->count());
        self::assertSame($original, Db::name('system_configs')->where('config_key', 'ctrip_config_list')->value('config_value'));
    }

    public function testDataConfigKeepsExternalOtaHotelIdSeparateFromSystemHotelBinding(): void
    {
        Db::name('system_config')->insert([
            'config_key' => 'data_config_ctrip_comments',
            'config_value' => $this->json([
                'id' => 'ctrip-comments-10',
                'config_id' => 'ctrip-comments-10',
                'system_hotel_id' => 10,
                'hotel_id' => '6866634',
                'cookies' => 'comments-cookie-secret',
            ]),
        ]);

        $dryRun = $this->service()->run(false);
        self::assertSame('bound_verified', $dryRun['items'][0]['classification']);

        $executed = $this->service()->run(true);
        self::assertSame(1, $executed['migrated_count']);
        $stored = json_decode(
            (string)Db::name('system_config')->where('config_key', 'data_config_ctrip_comments')->value('config_value'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame('6866634', $stored['hotel_id']);
        self::assertSame(10, $stored['system_hotel_id']);
        self::assertArrayNotHasKey('cookies', $stored);
        self::assertSame(
            'comments-cookie-secret',
            $this->vault()->withPayloadForExecution(
                1,
                10,
                'ctrip',
                'ctrip-comments-10',
                static fn(array $payload): string => (string)$payload['cookies']
            )
        );
    }

    public function testSameConfigIdInDifferentHotelScopesIsNotTreatedAsDuplicate(): void
    {
        Db::name('system_config')->insert([
            'config_key' => 'ctrip_config_list',
            'config_value' => $this->json([
                'main' => [
                    'id' => 'main', 'config_id' => 'main', 'hotel_id' => 10, 'system_hotel_id' => 10,
                    'cookies' => 'hotel-ten-main-secret',
                ],
            ]),
        ]);
        Db::name('system_configs')->insert([
            'config_key' => 'ctrip_config_list',
            'config_value' => $this->json([
                'main' => [
                    'id' => 'main', 'config_id' => 'main', 'hotel_id' => 20, 'system_hotel_id' => 20,
                    'cookies' => 'hotel-twenty-main-secret',
                ],
            ]),
        ]);

        $dryRun = $this->service()->run(false);
        self::assertSame(['bound_verified', 'bound_verified'], array_column($dryRun['items'], 'classification'));

        $executed = $this->service()->run(true);
        self::assertSame(2, $executed['migrated_count']);
        self::assertSame(2, (int)Db::name('ota_credentials')->where('config_id', 'main')->count());
    }

    public function testOrphanCredentialMetadataIsNotClaimedAsAlreadyMigrated(): void
    {
        Db::name('system_configs')->insert([
            'config_key' => 'ctrip_config_list',
            'config_value' => $this->json([
                'orphan-metadata' => [
                    'id' => 'orphan-metadata',
                    'config_id' => 'orphan-metadata',
                    'hotel_id' => 10,
                    'system_hotel_id' => 10,
                    'credential_ref' => 999,
                    'credential_status' => 'ready',
                    'has_cookies' => true,
                    'cookies' => 'orphan-metadata-secret',
                ],
            ]),
        ]);

        $dryRun = $this->service()->run(false);
        self::assertSame('bound_verified', $dryRun['items'][0]['classification']);
        self::assertSame('verified_binding', $dryRun['items'][0]['reason_code']);

        $executed = $this->service()->run(true);
        self::assertSame(1, $executed['migrated_count']);
        self::assertSame(1, (int)Db::name('ota_credentials')->where('config_id', 'orphan-metadata')->count());
    }

    public function testExistingVaultLocatorWithLegacyPlaintextIsSanitizedAndRotated(): void
    {
        $existing = $this->vault()->store(
            1,
            10,
            'ctrip',
            'stale-plaintext',
            ['cookies' => 'old-vault-secret'],
            0
        );
        Db::name('system_configs')->insert([
            'config_key' => 'ctrip_config_list',
            'config_value' => $this->json([
                'stale-plaintext' => [
                    'id' => 'stale-plaintext',
                    'config_id' => 'stale-plaintext',
                    'hotel_id' => 10,
                    'system_hotel_id' => 10,
                    'credential_ref' => $existing['credential_ref'],
                    'credential_status' => 'ready',
                    'cookies' => 'new-legacy-plaintext-secret',
                ],
            ]),
        ]);

        $dryRun = $this->service()->run(false);
        self::assertSame('bound_verified', $dryRun['items'][0]['classification']);

        $executed = $this->service()->run(true);
        self::assertSame(1, $executed['migrated_count']);
        self::assertSame(1, (int)Db::name('ota_credentials')->where('config_id', 'stale-plaintext')->count());
        self::assertSame(
            'new-legacy-plaintext-secret',
            $this->vault()->withPayloadForExecution(
                1,
                10,
                'ctrip',
                'stale-plaintext',
                static fn(array $payload): string => (string)$payload['cookies']
            )
        );
        self::assertStringNotContainsString(
            'new-legacy-plaintext-secret',
            (string)Db::name('system_configs')->where('config_key', 'ctrip_config_list')->value('config_value')
        );
    }

    public function testInvalidJsonBlocksExecuteWithoutPartialWritesOrSecretEcho(): void
    {
        Db::name('system_configs')->insert([
            'config_key' => 'ctrip_config_list',
            'config_value' => $this->json([
                'valid-before-invalid' => [
                    'id' => 'valid-before-invalid',
                    'config_id' => 'valid-before-invalid',
                    'hotel_id' => 10,
                    'system_hotel_id' => 10,
                    'cookies' => 'valid-secret-before-invalid',
                ],
            ]),
        ]);
        Db::name('system_config')->insert([
            'config_key' => 'data_config_meituan_business',
            'config_value' => '{"auth_data":"invalid-json-secret"',
        ]);
        $before = $this->databaseSnapshot();

        $summary = $this->service()->run(true);

        self::assertSame('blocked', $summary['status']);
        self::assertSame(0, $summary['migrated_count']);
        self::assertSame($before, $this->databaseSnapshot());
        self::assertContains('invalid_json:system_config', $summary['blockers']);
        self::assertStringNotContainsString('invalid-json-secret', json_encode($summary, JSON_THROW_ON_ERROR));
    }

    public function testMissingVaultSchemaIsExplicitlyMigrationRequiredAndExecuteIsBlocked(): void
    {
        Db::execute('DROP TABLE ota_credentials');
        Db::name('system_configs')->insert([
            'config_key' => 'ctrip_config_list',
            'config_value' => $this->json([
                'schema-check' => [
                    'id' => 'schema-check',
                    'config_id' => 'schema-check',
                    'hotel_id' => 10,
                    'system_hotel_id' => 10,
                    'cookies' => 'schema-check-secret',
                ],
            ]),
        ]);

        $dryRun = $this->service()->run(false);
        self::assertSame('migration_required', $dryRun['status']);
        self::assertContains('schema_missing:ota_credentials', $dryRun['blockers']);

        $execute = $this->service()->run(true);
        self::assertSame('blocked', $execute['status']);
        self::assertSame(0, $execute['migrated_count']);
        self::assertContains('schema_missing:ota_credentials', $execute['blockers']);

        $this->createOtaCredentialTable();
    }

    public function testMissingHotelsTenantColumnReturnsSchemaBlockerWithoutThrowing(): void
    {
        Db::execute('DROP TABLE hotels');
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, name VARCHAR(100))');

        try {
            $dryRunReturned = true;
            try {
                $dryRun = $this->service()->run(false);
            } catch (\Throwable) {
                $dryRunReturned = false;
                $dryRun = [];
            }
            self::assertTrue($dryRunReturned, 'Dry-run threw instead of returning the missing-column blocker.');
            self::assertSame('migration_required', $dryRun['status']);
            self::assertContains('schema_missing:hotels.tenant_id', $dryRun['blockers']);

            $executeReturned = true;
            try {
                $execute = $this->service()->run(true);
            } catch (\Throwable) {
                $executeReturned = false;
                $execute = [];
            }
            self::assertTrue($executeReturned, 'Execute threw instead of returning the missing-column blocker.');
            self::assertSame('blocked', $execute['status']);
            self::assertSame(0, $execute['migrated_count']);
            self::assertContains('schema_missing:hotels.tenant_id', $execute['blockers']);
        } finally {
            Db::execute('DROP TABLE hotels');
            Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name VARCHAR(100))');
        }
    }

    public function testSafeDataConfigIsScannedWithoutBecomingCredentialMigrationDebt(): void
    {
        Db::name('system_config')->insert([
            'config_key' => 'data_config_internal_notes',
            'config_value' => $this->json([
                'name' => 'safe business metadata',
                'enabled' => true,
                'fields' => ['occupancy', 'revenue'],
            ]),
        ]);

        $summary = $this->service()->run(false);

        self::assertSame('ready', $summary['status']);
        self::assertSame(0, $summary['inventory_count']);
        self::assertSame(1, $summary['sources']['system_config']['row_count']);
        self::assertSame(0, $summary['sources']['system_config']['item_count']);
        self::assertSame([], $summary['items']);
    }

    public function testLegacyMeituanListIsRelocatedToCanonicalTableOnlyAfterSanitization(): void
    {
        $marker = 'legacy-meituan-relocation-secret';
        Db::name('system_config')->insert([
            'config_key' => 'meituan_config_list',
            'config_value' => $this->json([
                'meituan-relocation' => [
                    'id' => 'meituan-relocation',
                    'config_id' => 'meituan-relocation',
                    'hotel_id' => 20,
                    'system_hotel_id' => 20,
                    'name' => 'Legacy Meituan metadata',
                    'cookies' => $marker,
                ],
            ]),
        ]);
        $before = $this->databaseSnapshot();
        $service = $this->service();

        $dryRun = $service->run(false);

        self::assertSame('migration_required', $dryRun['status']);
        self::assertSame(1, $dryRun['metadata_relocation_count']);
        self::assertSame(1, $dryRun['metadata_relocation_eligible_count']);
        self::assertSame(0, $dryRun['metadata_relocated_count']);
        self::assertSame(1, $dryRun['remaining_metadata_relocation_count']);
        self::assertSame('relocation_required', $dryRun['metadata_relocations'][0]['classification']);
        self::assertSame('canonical_missing', $dryRun['metadata_relocations'][0]['reason_code']);
        self::assertSame($before, $this->databaseSnapshot());
        self::assertStringNotContainsString($marker, json_encode($dryRun, JSON_THROW_ON_ERROR));

        $executed = $service->run(true);

        self::assertSame('completed', $executed['status']);
        self::assertSame(1, $executed['migrated_count']);
        self::assertSame(1, $executed['metadata_relocated_count']);
        self::assertSame(0, $executed['remaining_metadata_relocation_count']);
        self::assertSame('{}', Db::name('system_config')->where('config_key', 'meituan_config_list')->value('config_value'));
        $canonicalJson = (string)Db::name('system_configs')
            ->where('config_key', 'meituan_config_list')
            ->value('config_value');
        $canonical = json_decode($canonicalJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Legacy Meituan metadata', $canonical['meituan-relocation']['name']);
        self::assertSame('ready', $canonical['meituan-relocation']['credential_status']);
        self::assertArrayHasKey('credential_ref', $canonical['meituan-relocation']);
        self::assertArrayNotHasKey('cookies', $canonical['meituan-relocation']);
        self::assertStringNotContainsString($marker, $canonicalJson);
        self::assertSame(
            $marker,
            $this->vault()->withPayloadForExecution(
                2,
                20,
                'meituan',
                'meituan-relocation',
                static fn(array $payload): string => (string)$payload['cookies']
            )
        );

        $afterFirstExecution = $this->databaseSnapshot();
        $second = $service->run(true);
        self::assertSame('completed', $second['status']);
        self::assertSame(0, $second['metadata_relocated_count']);
        self::assertSame($afterFirstExecution, $this->databaseSnapshot());
    }

    public function testMatchingCanonicalMeituanListRetiresLegacyCopyIdempotently(): void
    {
        $vaultMetadata = $this->vault()->store(
            2,
            20,
            'meituan',
            'matching-meituan',
            ['cookies' => 'matching-canonical-vault-secret'],
            0
        );
        $safeList = [
            'matching-meituan' => [
                'id' => 'matching-meituan',
                'config_id' => 'matching-meituan',
                'hotel_id' => 20,
                'system_hotel_id' => 20,
                'name' => 'Matching metadata',
                'credential_ref' => $vaultMetadata['credential_ref'],
                'credential_status' => 'ready',
                'has_cookies' => true,
            ],
        ];
        $safeJson = $this->json($safeList);
        Db::name('system_config')->insert([
            'config_key' => 'meituan_config_list',
            'config_value' => $safeJson,
        ]);
        Db::name('system_configs')->insert([
            'config_key' => 'meituan_config_list',
            'config_value' => $safeJson,
        ]);
        $service = $this->service();

        $dryRun = $service->run(false);

        self::assertSame('migration_required', $dryRun['status']);
        self::assertSame(1, $dryRun['metadata_relocation_count']);
        self::assertSame('legacy_retirement_required', $dryRun['metadata_relocations'][0]['classification']);
        self::assertSame('canonical_match', $dryRun['metadata_relocations'][0]['reason_code']);

        $executed = $service->run(true);

        self::assertSame('completed', $executed['status']);
        self::assertSame(0, $executed['migrated_count']);
        self::assertSame(1, $executed['metadata_relocated_count']);
        self::assertSame($safeJson, Db::name('system_configs')->where('config_key', 'meituan_config_list')->value('config_value'));
        self::assertSame('{}', Db::name('system_config')->where('config_key', 'meituan_config_list')->value('config_value'));

        $afterFirstExecution = $this->databaseSnapshot();
        $second = $service->run(true);
        self::assertSame('completed', $second['status']);
        self::assertSame(0, $second['metadata_relocated_count']);
        self::assertSame($afterFirstExecution, $this->databaseSnapshot());
    }

    public function testConflictingCanonicalMeituanListBlocksWithoutOverwritingOrRetiringLegacy(): void
    {
        $marker = 'conflicting-legacy-meituan-secret';
        Db::name('system_config')->insert([
            'config_key' => 'meituan_config_list',
            'config_value' => $this->json([
                'legacy-conflict' => [
                    'id' => 'legacy-conflict',
                    'config_id' => 'legacy-conflict',
                    'hotel_id' => 20,
                    'system_hotel_id' => 20,
                    'cookies' => $marker,
                ],
            ]),
        ]);
        Db::name('system_configs')->insert([
            'config_key' => 'meituan_config_list',
            'config_value' => $this->json([
                'canonical-conflict' => [
                    'id' => 'canonical-conflict',
                    'config_id' => 'canonical-conflict',
                    'hotel_id' => 20,
                    'system_hotel_id' => 20,
                    'credential_status' => 'migration_required',
                ],
            ]),
        ]);
        $before = $this->databaseSnapshot();
        $service = $this->service();

        $dryRun = $service->run(false);

        self::assertSame('blocked', $dryRun['status']);
        self::assertContains('metadata_relocation_conflict:meituan_config_list', $dryRun['blockers']);
        self::assertSame('conflict', $dryRun['metadata_relocations'][0]['classification']);
        self::assertSame('canonical_content_conflict', $dryRun['metadata_relocations'][0]['reason_code']);
        self::assertStringNotContainsString($marker, json_encode($dryRun, JSON_THROW_ON_ERROR));

        $executed = $service->run(true);

        self::assertSame('blocked', $executed['status']);
        self::assertSame(0, $executed['migrated_count']);
        self::assertSame(0, $executed['metadata_relocated_count']);
        self::assertSame($before, $this->databaseSnapshot());
    }

    public function testRelocationAndLegacyRetirementRollBackTogetherWhenRetirementFails(): void
    {
        Db::name('system_config')->insert([
            'config_key' => 'meituan_config_list',
            'config_value' => $this->json([
                'transactional-meituan' => [
                    'id' => 'transactional-meituan',
                    'config_id' => 'transactional-meituan',
                    'hotel_id' => 20,
                    'system_hotel_id' => 20,
                    'cookies' => 'transactional-meituan-secret',
                ],
            ]),
        ]);
        $before = $this->databaseSnapshot();
        Db::execute("CREATE TRIGGER fail_meituan_legacy_retirement BEFORE UPDATE ON system_config WHEN NEW.config_value = '{}' BEGIN SELECT RAISE(ABORT, 'forced legacy retirement failure'); END");

        try {
            $this->service()->run(true);
            self::fail('Relocation must roll back when the legacy row cannot be retired.');
        } catch (RuntimeException $exception) {
            self::assertSame('OTA credential migration failed.', $exception->getMessage());
        } finally {
            Db::execute('DROP TRIGGER IF EXISTS fail_meituan_legacy_retirement');
        }

        self::assertSame($before, $this->databaseSnapshot());
        self::assertSame(0, (int)Db::name('ota_credentials')->count());
        self::assertSame(0, (int)Db::name('system_configs')->where('config_key', 'meituan_config_list')->count());
    }

    public function testPlatformSourceWithoutLocatorUsesStableSourceRowConfigIdAndPersistsSafeMetadata(): void
    {
        $this->seedExecutableInventory();
        Db::name('system_config')->delete(true);
        Db::name('system_configs')->delete(true);
        $sourceId = (int)Db::name('platform_data_sources')->value('id');
        Db::name('platform_data_sources')->where('id', $sourceId)->update([
            'config_json' => $this->json([
                'hotel_id' => '6866634',
                'url' => 'https://ebooking.ctrip.com/api',
            ]),
        ]);
        $expectedConfigId = 'ctrip-source-' . $sourceId;

        $first = $this->service()->run(true);

        self::assertSame('completed', $first['status']);
        self::assertSame(1, $first['migrated_count']);
        self::assertSame(1, $first['classification_counts']['bound_verified']);
        self::assertSame(0, $first['classification_counts']['field_conflict']);
        self::assertSame(0, $first['classification_counts']['unbound']);
        $storedJson = (string)Db::name('platform_data_sources')->where('id', $sourceId)->value('config_json');
        $storedConfig = json_decode($storedJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($expectedConfigId, $storedConfig['id']);
        self::assertSame($expectedConfigId, $storedConfig['config_id']);
        self::assertArrayHasKey('credential_ref', $storedConfig);
        self::assertSame('ready', $storedConfig['credential_status']);
        self::assertSame(1, (int)Db::name('ota_credentials')->where('config_id', $expectedConfigId)->count());

        $second = $this->service()->run(true);

        self::assertSame('completed', $second['status']);
        self::assertSame(0, $second['migrated_count']);
        self::assertSame(1, (int)Db::name('ota_credentials')->where('config_id', $expectedConfigId)->count());
        self::assertSame(
            $storedJson,
            (string)Db::name('platform_data_sources')->where('id', $sourceId)->value('config_json')
        );
    }

    public function testPlatformSourceNormalizesControlledCredentialKeysWithoutLeakingMarkers(): void
    {
        $this->seedExecutableInventory();
        Db::name('system_config')->delete(true);
        Db::name('system_configs')->delete(true);
        $sourceId = (int)Db::name('platform_data_sources')->value('id');
        $credentialKeys = [
            'client_secret',
            'clientSecret',
            'x_auth_token',
            'x-auth-token',
            'access_secret',
            'access_token',
            'refresh_secret',
            'refresh_token',
            'auth_secret',
            'auth_token',
            'client_token',
        ];
        $markers = [];
        foreach ($credentialKeys as $key) {
            $markers[$key] = hash('sha256', __METHOD__ . '|' . $key);
        }
        Db::name('platform_data_sources')->where('id', $sourceId)->update([
            'config_json' => $this->json(array_merge([
                'config_id' => 'platform-source-ok',
                'hotel_id' => '6866634',
                'url' => 'https://ebooking.ctrip.com/api',
                'token_expires_at' => '2099-01-01 00:00:00',
            ], $markers)),
        ]);

        $summary = $this->service()->run(true);

        self::assertSame('completed', $summary['status']);
        self::assertSame(1, $summary['migrated_count']);
        $vaultContainsMarkers = $this->vault()->withPayloadForExecution(
            1,
            10,
            'ctrip',
            'platform-source-ok',
            static function (array $payload) use ($markers): bool {
                foreach ($markers as $key => $expected) {
                    if (!isset($payload[$key])
                        || !is_string($payload[$key])
                        || !hash_equals($expected, $payload[$key])) {
                        return false;
                    }
                }
                return !array_key_exists('token_expires_at', $payload);
            }
        );
        self::assertTrue($vaultContainsMarkers, 'Vault payload is missing a controlled credential key.');

        $encodedSummary = json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $legacyConfigJson = (string)Db::name('platform_data_sources')->where('id', $sourceId)->value('config_json');
        foreach ($markers as $key => $marker) {
            self::assertFalse(str_contains($encodedSummary, $marker), 'Summary leaked marker for ' . $key . '.');
            self::assertFalse(str_contains($legacyConfigJson, $marker), 'Legacy config leaked marker for ' . $key . '.');
        }
        $legacyConfig = json_decode($legacyConfigJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('2099-01-01 00:00:00', $legacyConfig['token_expires_at']);
    }

    public function testPlatformSourceWithExplicitInvalidConfigIdRemainsUnbound(): void
    {
        $this->seedExecutableInventory();
        Db::name('system_config')->delete(true);
        Db::name('system_configs')->delete(true);
        $sourceId = (int)Db::name('platform_data_sources')->value('id');
        $invalidConfigJson = $this->json([
            'config_id' => 'invalid config id',
            'hotel_id' => '6866634',
            'url' => 'https://ebooking.ctrip.com/api',
        ]);
        Db::name('platform_data_sources')->where('id', $sourceId)->update([
            'config_json' => $invalidConfigJson,
        ]);

        $dryRun = $this->service()->run(false);

        self::assertSame('migration_required', $dryRun['status']);
        self::assertSame(0, $dryRun['eligible_count']);
        self::assertSame(1, $dryRun['classification_counts']['unbound']);
        self::assertSame('config_id_missing_or_invalid', $dryRun['items'][0]['reason_code']);

        $execute = $this->service()->run(true);

        self::assertSame('migration_required', $execute['status']);
        self::assertSame(0, $execute['migrated_count']);
        self::assertSame(0, (int)Db::name('ota_credentials')->count());
        self::assertSame(
            $invalidConfigJson,
            (string)Db::name('platform_data_sources')->where('id', $sourceId)->value('config_json')
        );
    }

    public function testCommandAndPackageScriptsAreRegisteredDryRunFirst(): void
    {
        $console = (string)file_get_contents(dirname(__DIR__) . '/config/console.php');
        $command = (string)file_get_contents(dirname(__DIR__) . '/app/command/MigrateOtaCredentials.php');
        $package = json_decode((string)file_get_contents(dirname(__DIR__) . '/package.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertStringContainsString("'migrate:ota-credentials' => 'app\\command\\MigrateOtaCredentials'", $console);
        self::assertStringContainsString("addOption('execute'", $command);
        self::assertStringContainsString("getOption('execute')", $command);
        self::assertStringNotContainsString('encrypted_payload', $command);
        self::assertSame('C:\\xampp\\php\\php.exe think migrate:ota-credentials', $package['scripts']['migrate:ota-credentials:dry-run']);
        self::assertSame('C:\\xampp\\php\\php.exe think migrate:ota-credentials --execute', $package['scripts']['migrate:ota-credentials:execute']);
        self::assertSame('node scripts/verify_ota_credential_vault.mjs', $package['scripts']['verify:ota-credential-vault']);
    }

    public function testAlreadyMigratedRequiresMatchingCredentialReference(): void
    {
        $metadata = $this->seedMetadataOnlyCredential('reference-mismatch');
        $this->seedMetadataOnlyConfig('reference-mismatch', (int)$metadata['credential_ref'] + 999);

        $summary = $this->service()->run(false);

        self::assertSame('migration_required', $summary['status']);
        self::assertSame('unbound', $summary['items'][0]['classification']);
        self::assertSame('credential_reference_mismatch', $summary['items'][0]['reason_code']);
        self::assertSame(0, $summary['classification_counts']['already_migrated']);
    }

    public function testAlreadyMigratedRequiresReadyCredentialStatus(): void
    {
        $metadata = $this->seedMetadataOnlyCredential('status-not-ready');
        $this->seedMetadataOnlyConfig('status-not-ready', (int)$metadata['credential_ref']);
        Db::name('ota_credentials')->where('id', (int)$metadata['credential_ref'])->update([
            'credential_status' => 'expired',
        ]);

        $summary = $this->service()->run(false);

        self::assertSame('migration_required', $summary['status']);
        self::assertSame('unbound', $summary['items'][0]['classification']);
        self::assertSame('credential_vault_not_ready', $summary['items'][0]['reason_code']);
        self::assertSame(0, $summary['classification_counts']['already_migrated']);
    }

    public function testAlreadyMigratedRequiresDecryptableCredentialEnvelope(): void
    {
        $metadata = $this->seedMetadataOnlyCredential('tampered-envelope');
        $this->seedMetadataOnlyConfig('tampered-envelope', (int)$metadata['credential_ref']);
        Db::name('ota_credentials')->where('id', (int)$metadata['credential_ref'])->update([
            'encrypted_payload' => 'tampered',
        ]);

        $summary = $this->service()->run(false);

        self::assertSame('migration_required', $summary['status']);
        self::assertSame('unbound', $summary['items'][0]['classification']);
        self::assertSame('credential_vault_not_ready', $summary['items'][0]['reason_code']);
        self::assertSame(0, $summary['classification_counts']['already_migrated']);
    }

    private function seedClassificationInventory(): void
    {
        $existing = $this->vault()->store(
            1,
            10,
            'ctrip',
            'already-migrated',
            ['cookies' => 'classification-existing-vault-secret'],
            0
        );
        Db::name('system_configs')->insert([
            'config_key' => 'ctrip_config_list',
            'config_value' => $this->json([
                'bound-ok' => [
                    'id' => 'bound-ok', 'config_id' => 'bound-ok', 'hotel_id' => 10, 'system_hotel_id' => 10,
                    'cookies' => 'classification-bound-secret',
                ],
                'unbound-owner' => [
                    'id' => 'unbound-owner', 'config_id' => 'unbound-owner', 'owner_id' => 77,
                    'cookies' => 'classification-unbound-secret',
                ],
                'field-conflict' => [
                    'id' => 'field-conflict', 'config_id' => 'field-conflict', 'hotel_id' => 20, 'system_hotel_id' => 10,
                    'cookies' => 'classification-conflict-secret',
                ],
                'duplicate-shared' => [
                    'id' => 'duplicate-shared', 'config_id' => 'duplicate-shared', 'hotel_id' => 10, 'system_hotel_id' => 10,
                    'cookies' => 'classification-duplicate-one-secret',
                ],
                'tenant-mismatch' => [
                    'id' => 'tenant-mismatch', 'config_id' => 'tenant-mismatch', 'hotel_id' => 20, 'system_hotel_id' => 20,
                    'tenant_id' => 1, 'cookies' => 'classification-tenant-secret',
                ],
                'already-migrated' => [
                    'id' => 'already-migrated', 'config_id' => 'already-migrated', 'hotel_id' => 10, 'system_hotel_id' => 10,
                    'credential_ref' => $existing['credential_ref'], 'credential_status' => 'ready', 'has_cookies' => true,
                ],
            ]),
        ]);
        Db::name('system_config')->insert([
            'config_key' => 'data_config_meituan_business',
            'config_value' => $this->json([
                'id' => 'meituan-bound', 'config_id' => 'meituan-bound', 'hotel_id' => 20, 'system_hotel_id' => 20,
                'auth_data' => ['token' => 'classification-nested-secret'],
            ]),
        ]);
        Db::name('platform_data_sources')->insert([
            'tenant_id' => 1,
            'system_hotel_id' => 10,
            'platform' => 'ctrip',
            'config_json' => $this->json(['config_id' => 'duplicate-shared']),
            'secret_json' => $this->json(['token' => 'classification-duplicate-two-secret']),
        ]);
        Db::name('system_config')->insert([
            'config_key' => 'online_data_cookies_hotel_10',
            'config_value' => $this->json([
                'cookie-inventory' => [
                    'id' => 'cookie-inventory', 'config_id' => 'cookie-inventory', 'cookies' => 'classification-cookie-secret',
                ],
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    private function seedMetadataOnlyCredential(string $configId): array
    {
        return $this->vault()->store(
            1,
            10,
            'ctrip',
            $configId,
            ['cookies' => 'metadata-only-secret'],
            0
        );
    }

    private function seedMetadataOnlyConfig(string $configId, int $credentialRef): void
    {
        Db::name('system_configs')->insert([
            'config_key' => 'ctrip_config_list',
            'config_value' => $this->json([
                $configId => [
                    'id' => $configId,
                    'config_id' => $configId,
                    'hotel_id' => 10,
                    'system_hotel_id' => 10,
                    'credential_ref' => $credentialRef,
                    'credential_status' => 'ready',
                    'has_cookies' => true,
                ],
            ]),
        ]);
    }

    private function seedExecutableInventory(): void
    {
        Db::name('system_configs')->insert([
            'config_key' => 'ctrip_config_list',
            'config_value' => $this->json([
                'ctrip-list-ok' => [
                    'id' => 'ctrip-list-ok', 'config_id' => 'ctrip-list-ok', 'hotel_id' => 10, 'system_hotel_id' => 10,
                    'name' => 'Ctrip list', 'cookies' => 'ctrip-list-secret',
                ],
            ]),
        ]);
        Db::name('system_config')->insert([
            'config_key' => 'data_config_meituan_business',
            'config_value' => $this->json([
                'id' => 'meituan-data-ok', 'config_id' => 'meituan-data-ok', 'hotel_id' => 20, 'system_hotel_id' => 20,
                'name' => 'Meituan data config', 'auth_data' => ['token' => 'nested-meituan-secret'],
                'payload_json' => '{"token":"payload-json-secret"}',
                'payload' => ['metric' => 'rank', 'token' => 'payload-array-secret'],
            ]),
        ]);
        Db::name('platform_data_sources')->insert([
            'tenant_id' => 1,
            'system_hotel_id' => 10,
            'platform' => 'ctrip',
            'config_json' => $this->json([
                'config_id' => 'platform-source-ok',
                'hotel_id' => '6866634',
                'url' => 'https://ebooking.ctrip.com/api',
            ]),
            'secret_json' => $this->json(['token' => 'platform-source-secret']),
        ]);
        Db::name('system_config')->insertAll([
            [
                'config_key' => 'online_data_cookies_hotel_10',
                'config_value' => $this->json([
                    'cookie-hotel-ok' => [
                        'id' => 'cookie-hotel-ok', 'config_id' => 'cookie-hotel-ok', 'cookies' => 'cookie-hotel-secret',
                    ],
                ]),
            ],
            [
                'config_key' => 'online_data_cookies_20',
                'config_value' => $this->json([
                    'cookie-short-ok' => [
                        'id' => 'cookie-short-ok', 'config_id' => 'cookie-short-ok', 'cookies' => 'cookie-short-secret',
                    ],
                ]),
            ],
            [
                'config_key' => 'online_data_cookies_global',
                'config_value' => $this->json([
                    'must-not-enumerate' => ['cookies' => 'unrelated-global-secret'],
                ]),
            ],
        ]);
    }

    private function seedMissingHotelCredential(string $marker): void
    {
        Db::name('system_configs')->insert([
            'config_key' => 'ctrip_config_list',
            'config_value' => $this->json([
                'orphan-hotel' => [
                    'id' => 'orphan-hotel',
                    'config_id' => 'orphan-hotel',
                    'hotel_id' => 999,
                    'system_hotel_id' => 999,
                    'name' => 'Preserved safe metadata',
                    'url' => 'https://example.invalid/ota',
                    'credential_ref' => 444,
                    'credential_status' => 'ready',
                    'has_cookies' => true,
                    'secret_mask' => 'legacy-mask',
                    'key_id' => 'legacy-key',
                    'payload_version' => 1,
                    'encrypted_payload' => 'legacy-encrypted-value',
                    'ciphertext' => 'legacy-ciphertext',
                    'cookies' => $marker,
                    'auth_data' => ['token' => $marker . '-nested'],
                ],
            ]),
        ]);
    }

    private function service(): OtaCredentialMigrationService
    {
        return new OtaCredentialMigrationService($this->vault());
    }

    private function vault(): OtaCredentialVault
    {
        return new OtaCredentialVault(
            new OtaCredentialEnvelope(base64_encode(str_repeat('m', 32)), 'migration-test-key'),
            'migration-test-key'
        );
    }

    private function createSchema(): void
    {
        Db::execute('CREATE TABLE IF NOT EXISTS hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name VARCHAR(100))');
        Db::execute('CREATE TABLE IF NOT EXISTS system_config (id INTEGER PRIMARY KEY AUTOINCREMENT, config_key VARCHAR(100) NOT NULL UNIQUE, config_value TEXT, description VARCHAR(255), create_time DATETIME, update_time DATETIME)');
        Db::execute('CREATE TABLE IF NOT EXISTS system_configs (id INTEGER PRIMARY KEY AUTOINCREMENT, config_key VARCHAR(100) NOT NULL UNIQUE, config_value TEXT, description VARCHAR(255), create_time DATETIME, update_time DATETIME)');
        Db::execute('CREATE TABLE IF NOT EXISTS platform_data_sources (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, system_hotel_id INTEGER, platform VARCHAR(50), ingestion_method VARCHAR(30), enabled INTEGER DEFAULT 1, config_json TEXT, secret_json TEXT, update_time DATETIME)');
        $this->createOtaCredentialTable();
    }

    private function createOtaCredentialTable(): void
    {
        Db::execute('CREATE TABLE IF NOT EXISTS ota_credentials (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, platform VARCHAR(20) NOT NULL, config_id VARCHAR(100) NOT NULL, encrypted_payload TEXT NOT NULL, payload_version INTEGER NOT NULL, key_id VARCHAR(100) NOT NULL, secret_mask VARCHAR(255) NOT NULL, credential_status VARCHAR(20) NOT NULL, created_by INTEGER NOT NULL, rotated_at DATETIME, create_time DATETIME, update_time DATETIME, UNIQUE(tenant_id,system_hotel_id,platform,config_id))');
    }

    private function databaseSnapshot(): array
    {
        $snapshot = [];
        foreach (['hotels', 'system_config', 'system_configs', 'platform_data_sources'] as $table) {
            $snapshot[$table] = Db::name($table)->order('id')->select()->toArray();
        }
        if ($this->tableExists('ota_credentials')) {
            $snapshot['ota_credentials'] = Db::name('ota_credentials')->order('id')->select()->toArray();
        }
        return $snapshot;
    }

    private function tableExists(string $table): bool
    {
        try {
            Db::query('SELECT 1 FROM `' . $table . '` LIMIT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function assertLegacyStoresContainNoSecretSentinels(array $secrets): void
    {
        $legacy = [];
        foreach (['system_config', 'system_configs'] as $table) {
            $legacy[$table] = Db::name($table)->order('id')->select()->toArray();
        }
        $legacy['platform_data_sources'] = Db::name('platform_data_sources')->order('id')->select()->toArray();
        $encoded = json_encode($legacy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        foreach ($secrets as $secret) {
            if ($secret === 'unrelated-global-secret') {
                self::assertStringContainsString($secret, $encoded, 'Unrelated cache namespaces must not be enumerated or changed.');
                continue;
            }
            self::assertStringNotContainsString($secret, $encoded);
        }
    }

    private function secretSentinels(): array
    {
        return [
            'classification-bound-secret',
            'classification-unbound-secret',
            'classification-conflict-secret',
            'classification-duplicate-one-secret',
            'classification-duplicate-two-secret',
            'classification-tenant-secret',
            'classification-nested-secret',
            'payload-array-secret',
            'classification-cookie-secret',
            'classification-existing-vault-secret',
            'ctrip-list-secret',
            'nested-meituan-secret',
            'payload-json-secret',
            'platform-source-secret',
            'cookie-hotel-secret',
            'cookie-short-secret',
            'unrelated-global-secret',
        ];
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
