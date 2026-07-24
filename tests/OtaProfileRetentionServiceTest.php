<?php
declare(strict_types=1);

namespace Tests;

use app\command\CleanupDormantOtaProfiles;
use app\service\OtaProfileRetentionService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OtaProfileRetentionServiceTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $databasePath = '';
    private static string $projectRoot = '';
    private DateTimeImmutable $now;

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'ota_profile_retention_' . getmypid() . '.sqlite';
        self::$projectRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'ota_profile_retention_root_' . getmypid();

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
        self::removeTree(self::$projectRoot);
    }

    protected function setUp(): void
    {
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        @unlink(self::$databasePath);
        Db::connect(null, true);
        self::removeTree(self::$projectRoot);
        mkdir(self::$projectRoot . DIRECTORY_SEPARATOR . 'storage', 0775, true);
        $this->createSchema();
        $this->now = new DateTimeImmutable('2026-08-31 12:00:00', new DateTimeZone('Asia/Shanghai'));
    }

    public function testDryRunReportsOnlyDormantProfileAndDoesNotExposeProfileKey(): void
    {
        $old = $this->insertProfile('ctrip', 'old-profile-key', 10, '2026-06-15 10:00:00');
        $fresh = $this->insertProfile('meituan', 'fresh-profile-key', 20, '2026-08-20 10:00:00');
        $oldLog = $this->createOldArtifact('storage/logs/old.log', '2026-06-01 00:00:00');

        $result = $this->service()->cleanup(30, true);

        self::assertSame(2, $result['profiles_scanned']);
        self::assertSame(1, $result['profiles_expired']);
        self::assertSame(0, $result['profiles_removed']);
        self::assertSame(1, $result['artifact_files_expired']);
        self::assertFileExists($old['cookie_path']);
        self::assertFileExists($fresh['cookie_path']);
        self::assertFileExists($oldLog);
        self::assertSame(1, (int)Db::name('platform_data_sources')->where('id', $old['source_id'])->value('enabled'));
        self::assertStringNotContainsString('old-profile-key', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('fresh-profile-key', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testCleanupRemovesDormantProfileAndArtifactsButPreservesHistoricalRows(): void
    {
        $old = $this->insertProfile('ctrip', 'expired-profile', 10, '2026-06-15 10:00:00');
        Db::name('online_daily_data')->insert([
            'system_hotel_id' => 10,
            'platform' => 'ctrip',
            'data_date' => '2026-06-15',
        ]);
        $oldCapture = $this->createOldArtifact(
            'runtime/ctrip_capture/ctrip_cookie_api_fixture.json',
            '2026-06-01 00:00:00'
        );
        $orphanLock = $this->createOldArtifact(
            'runtime/locks/profile_capture_meituan_orphan-profile.lock',
            '2026-08-30 00:00:00'
        );

        $result = $this->service()->cleanup(30);

        self::assertSame(1, $result['profiles_removed']);
        self::assertSame(1, $result['sources_disabled']);
        self::assertSame(1, $result['bindings_revoked']);
        self::assertSame(1, $result['artifact_files_removed']);
        self::assertSame(1, $result['orphan_locks_removed']);
        self::assertDirectoryDoesNotExist($old['profile_path']);
        self::assertFileDoesNotExist($oldCapture);
        self::assertFileDoesNotExist($orphanLock);
        self::assertSame(1, Db::name('online_daily_data')->count());

        $source = Db::name('platform_data_sources')->where('id', $old['source_id'])->find();
        self::assertIsArray($source);
        self::assertSame(0, (int)$source['enabled']);
        self::assertSame('disabled', $source['status']);
        self::assertSame('profile_retention_expired', $source['last_error']);
        self::assertNull($source['secret_json']);
        self::assertSame(
            'revoked',
            Db::name('ota_profile_bindings')->where('id', $old['binding_id'])->value('binding_status')
        );
    }

    public function testOrphanedCredentialsAreSanitizedOnlyAfterRetention(): void
    {
        $old = $this->insertProfile('ctrip', 'old-orphan-profile', 10, '2026-06-15 10:00:00');
        $fresh = $this->insertProfile('meituan', 'fresh-orphan-profile', 20, '2026-08-20 10:00:00');
        self::removeTree($old['profile_path']);
        self::removeTree($fresh['profile_path']);

        $result = $this->service()->cleanup(30);

        self::assertSame(2, $result['orphan_metadata_groups_scanned']);
        self::assertSame(1, $result['orphan_metadata_groups_expired']);
        self::assertSame(1, $result['orphan_metadata_groups_cleaned']);
        self::assertSame(1, $result['orphan_metadata_groups_kept']);
        self::assertSame(1, $result['source_secrets_cleared']);
        self::assertNull(
            Db::name('platform_data_sources')->where('id', $old['source_id'])->value('secret_json')
        );
        self::assertSame(
            'revoked',
            Db::name('ota_profile_bindings')->where('id', $old['binding_id'])->value('binding_status')
        );
        self::assertNotNull(
            Db::name('platform_data_sources')->where('id', $fresh['source_id'])->value('secret_json')
        );
        self::assertSame(
            'active',
            Db::name('ota_profile_bindings')->where('id', $fresh['binding_id'])->value('binding_status')
        );
    }

    public function testActiveSyncTaskPreventsProfileDeletion(): void
    {
        $old = $this->insertProfile('ctrip', 'busy-profile', 10, '2026-06-15 10:00:00');
        Db::name('platform_data_sync_tasks')->insert([
            'data_source_id' => $old['source_id'],
            'status' => 'running',
        ]);

        $result = $this->service()->cleanup(30);

        self::assertSame(1, $result['profiles_skipped_active_task']);
        self::assertSame(0, $result['profiles_removed']);
        self::assertDirectoryExists($old['profile_path']);
        self::assertSame(1, (int)Db::name('platform_data_sources')->where('id', $old['source_id'])->value('enabled'));
    }

    public function testRecentFailedAutomationDoesNotRefreshLastSuccessfulUse(): void
    {
        $old = $this->insertProfile('ctrip', 'failed-retry-profile', 10, '2026-08-30 10:00:00');
        Db::name('platform_data_sources')->where('id', $old['source_id'])->update([
            'status' => 'failed',
            'last_sync_status' => 'failed',
            'last_error' => 'login_required',
        ]);

        $result = $this->service()->cleanup(30);

        self::assertSame(1, $result['profiles_expired']);
        self::assertSame(1, $result['profiles_removed']);
        self::assertDirectoryDoesNotExist($old['profile_path']);
    }

    public function testSuccessfulTaskHistorySurvivesARecentFailedRetry(): void
    {
        $old = $this->insertProfile('ctrip', 'history-profile', 10, '2026-08-30 10:00:00');
        Db::name('platform_data_sources')->where('id', $old['source_id'])->update([
            'status' => 'failed',
            'last_sync_status' => 'failed',
            'last_error' => 'login_required',
        ]);
        Db::name('platform_data_sync_tasks')->insert([
            'data_source_id' => $old['source_id'],
            'status' => 'success',
            'finished_at' => '2026-08-15 10:00:00',
            'update_time' => '2026-08-15 10:00:00',
            'create_time' => '2026-08-15 09:59:00',
        ]);

        $result = $this->service()->cleanup(30);

        self::assertSame(0, $result['profiles_expired']);
        self::assertSame(1, $result['profiles_kept']);
        self::assertDirectoryExists($old['profile_path']);
    }

    public function testSuccessfulSourceStatusUsesLastSyncTimeWhenDetailedStatusIsBlank(): void
    {
        $fresh = $this->insertProfile('ctrip', 'blank-detailed-status-profile', 10, '2026-08-20 10:00:00');
        Db::name('platform_data_sources')->where('id', $fresh['source_id'])->update([
            'status' => 'success',
            'last_sync_status' => '',
        ]);

        $result = $this->service()->cleanup(30);

        self::assertSame(0, $result['profiles_expired']);
        self::assertSame(1, $result['profiles_kept']);
        self::assertDirectoryExists($fresh['profile_path']);
    }

    public function testVisibleBrowserProcessPreventsProfileDeletion(): void
    {
        $old = $this->insertProfile('meituan', 'open-profile', 20, '2026-06-15 10:00:00');
        $service = new OtaProfileRetentionService(
            self::$projectRoot,
            fn(): DateTimeImmutable => $this->now,
            static fn(): bool => true
        );

        $result = $service->cleanup(30);

        self::assertSame(1, $result['profiles_skipped_in_use']);
        self::assertSame(0, $result['profiles_removed']);
        self::assertDirectoryExists($old['profile_path']);
    }

    public function testOldDisposableCacheIsRemovedWithoutTouchingFreshSession(): void
    {
        $fresh = $this->insertProfile('ctrip', 'fresh-cache-profile', 10, '2026-08-20 10:00:00');
        $cacheFile = $fresh['profile_path'] . DIRECTORY_SEPARATOR . 'Default'
            . DIRECTORY_SEPARATOR . 'Code Cache' . DIRECTORY_SEPARATOR . 'old.cache';
        mkdir(dirname($cacheFile), 0775, true);
        file_put_contents($cacheFile, 'cache');
        $oldTime = strtotime('2026-06-01 00:00:00');
        touch($cacheFile, $oldTime);
        touch(dirname($cacheFile), $oldTime);

        $result = $this->service()->cleanup(30);

        self::assertSame(1, $result['profile_cache_targets_removed']);
        self::assertDirectoryExists($fresh['profile_path']);
        self::assertFileExists($fresh['cookie_path']);
        self::assertDirectoryDoesNotExist(dirname($cacheFile));
    }

    public function testDormantCredentialDryRunDoesNotMutateCiphertextOrSource(): void
    {
        $credential = $this->insertCredential('dry-run-old', null, '2026-06-01 00:00:00', '2026-06-01 00:00:00', true);

        $result = $this->service()->cleanup(30, true);

        self::assertSame(1, $result['credentials_scanned']);
        self::assertSame(1, $result['credentials_expired']);
        self::assertSame(0, $result['credentials_revoked']);
        self::assertSame('ready', Db::name('ota_credentials')->where('id', $credential['id'])->value('credential_status'));
        self::assertSame('fixture-ciphertext', Db::name('ota_credentials')->where('id', $credential['id'])->value('encrypted_payload'));
        self::assertSame(1, (int)Db::name('platform_data_sources')->where('id', $credential['source_id'])->value('enabled'));
    }

    public function testDormantCredentialIsRevokedAndClearedWhileRecentAndUnknownActivityStayReady(): void
    {
        $old = $this->insertCredential('old', null, '2026-06-01 00:00:00', '2026-06-01 00:00:00', true);
        $recent = $this->insertCredential('recent', '2026-08-20 00:00:00', '2026-06-01 00:00:00', '2026-06-01 00:00:00');
        $unknown = $this->insertCredential('unknown', null, null, null);

        $result = $this->service()->cleanup(30);

        self::assertSame(3, $result['credentials_scanned']);
        self::assertSame(1, $result['credentials_expired']);
        self::assertSame(1, $result['credentials_revoked']);
        self::assertSame(1, $result['credential_ciphertexts_cleared']);
        self::assertSame(1, $result['credentials_activity_unknown']);
        $oldRow = Db::name('ota_credentials')->where('id', $old['id'])->find();
        self::assertSame('revoked', $oldRow['credential_status']);
        self::assertSame('', $oldRow['encrypted_payload']);
        self::assertSame('', $oldRow['secret_mask']);
        self::assertNotEmpty($oldRow['revoked_at']);
        self::assertSame('ready', Db::name('ota_credentials')->where('id', $recent['id'])->value('credential_status'));
        self::assertSame('ready', Db::name('ota_credentials')->where('id', $unknown['id'])->value('credential_status'));

        $source = Db::name('platform_data_sources')->where('id', $old['source_id'])->find();
        self::assertSame(0, (int)$source['enabled']);
        self::assertSame('credential_retention_expired', $source['last_error']);
        self::assertNull($source['secret_json']);
        $config = json_decode((string)$source['config_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('revoked', $config['credential_status']);
        self::assertFalse($config['has_cookies']);
    }

    public function testActiveSyncAndManualTasksFailClosedForDormantCredentials(): void
    {
        $sync = $this->insertCredential('sync-active', null, '2026-06-01 00:00:00', '2026-06-01 00:00:00', true);
        $manual = $this->insertCredential('manual-active', null, '2026-06-01 00:00:00', '2026-06-01 00:00:00');
        Db::name('platform_data_sync_tasks')->insert([
            'data_source_id' => $sync['source_id'],
            'system_hotel_id' => 10,
            'platform' => 'ctrip',
            'status' => 'running',
        ]);
        $manualStatus = [
            'task_id' => 'manual_ctrip_fetch_20_fixture',
            'hotel_id' => 20,
            'platform' => 'ctrip',
            'status' => 'running',
        ];
        Db::name('manual_online_fetch_task_statuses')->insert([
            ...$manualStatus,
            'task_kind' => 'ctrip',
            'stage' => 'fetching',
            'status_json' => json_encode($manualStatus, JSON_THROW_ON_ERROR),
            'created_at' => '2026-08-31 10:00:00',
            'updated_at' => '2026-08-31 10:00:00',
        ]);

        $result = $this->service()->cleanup(30);

        self::assertSame(2, $result['credentials_skipped_active_task']);
        self::assertSame('ready', Db::name('ota_credentials')->where('id', $sync['id'])->value('credential_status'));
        self::assertSame('ready', Db::name('ota_credentials')->where('id', $manual['id'])->value('credential_status'));
    }

    public function testUnavailableManualTaskGuardKeepsDormantCredentialReady(): void
    {
        $credential = $this->insertCredential('guard-unavailable', null, '2026-06-01 00:00:00', '2026-06-01 00:00:00');
        Db::execute('DROP TABLE manual_online_fetch_task_statuses');

        $result = $this->service()->cleanup(30);

        self::assertContains('credential_activity_guard_unavailable', $result['error_codes']);
        self::assertSame(0, $result['credentials_revoked']);
        self::assertSame('ready', Db::name('ota_credentials')->where('id', $credential['id'])->value('credential_status'));
        self::assertSame('fixture-ciphertext', Db::name('ota_credentials')->where('id', $credential['id'])->value('encrypted_payload'));
    }

    public function testDormantCredentialCleanupIsIdempotent(): void
    {
        $credential = $this->insertCredential('idempotent', null, '2026-06-01 00:00:00', '2026-06-01 00:00:00');

        $first = $this->service()->cleanup(30);
        $revokedAt = Db::name('ota_credentials')->where('id', $credential['id'])->value('revoked_at');
        $second = $this->service()->cleanup(30);

        self::assertSame(1, $first['credentials_revoked']);
        self::assertSame(0, $second['credentials_revoked']);
        self::assertSame($revokedAt, Db::name('ota_credentials')->where('id', $credential['id'])->value('revoked_at'));
        self::assertSame(1, Db::name('ota_credentials')->where('id', $credential['id'])->count());
    }

    public function testMissingRetentionColumnsKeepCredentialReadyAndReportSchemaError(): void
    {
        Db::execute('DROP TABLE ota_credentials');
        Db::execute('CREATE TABLE ota_credentials (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            platform VARCHAR(20) NOT NULL,
            config_id VARCHAR(100) NOT NULL,
            encrypted_payload TEXT NOT NULL,
            secret_mask VARCHAR(255) NOT NULL,
            credential_status VARCHAR(20) NOT NULL,
            rotated_at DATETIME,
            create_time DATETIME,
            update_time DATETIME
        )');
        $id = (int)Db::name('ota_credentials')->insertGetId([
            'tenant_id' => 10,
            'system_hotel_id' => 10,
            'platform' => 'ctrip',
            'config_id' => 'missing-columns',
            'encrypted_payload' => 'fixture-ciphertext',
            'secret_mask' => 'fi****xt',
            'credential_status' => 'ready',
            'rotated_at' => '2026-06-01 00:00:00',
            'create_time' => '2026-06-01 00:00:00',
        ]);

        $result = $this->service()->cleanup(30);

        self::assertContains('credential_schema_unavailable', $result['error_codes']);
        self::assertSame('ready', Db::name('ota_credentials')->where('id', $id)->value('credential_status'));
        self::assertSame('fixture-ciphertext', Db::name('ota_credentials')->where('id', $id)->value('encrypted_payload'));
    }

    public function testDurablePhaseTwoAndPhaseThreeRuntimeLedgersAreNeverAgedOut(): void
    {
        $phaseTwo = $this->createOldArtifact(
            'runtime/phase2_daily_workbench_patrol/patrol.json',
            '2026-06-01 00:00:00'
        );
        $phaseThree = $this->createOldArtifact(
            'runtime/phase3_operation_effect_loop/ledger.json',
            '2026-06-01 00:00:00'
        );

        $this->service()->cleanup(30);

        self::assertFileExists($phaseTwo);
        self::assertFileExists($phaseThree);
    }

    public function testCommandIsRegisteredAndAutomaticEntrypointsRunRetention(): void
    {
        $console = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'console.php';
        self::assertSame(
            CleanupDormantOtaProfiles::class,
            $console['commands']['online-data:cleanup-dormant-profiles'] ?? null
        );

        $cron = (string)file_get_contents(
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'daily_workbench_patrol_cron.php'
        );
        self::assertStringContainsString('online-data:cleanup-manual-fetch-tasks', $cron);
        self::assertStringContainsString('online-data:cleanup-dormant-profiles', $cron);
        self::assertStringContainsString('--retention-days=30', $cron);

        $startup = (string)file_get_contents(
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'start_local_stack.ps1'
        );
        self::assertStringContainsString('Invoke-OtaRetentionMaintenance', $startup);
        self::assertStringContainsString('online-data:cleanup-dormant-profiles', $startup);
        self::assertStringContainsString('--retention-days=30', $startup);
        self::assertStringContainsString('kept fail-closed', $startup);
    }

    private function service(): OtaProfileRetentionService
    {
        return new OtaProfileRetentionService(
            self::$projectRoot,
            fn(): DateTimeImmutable => $this->now,
            static fn(): bool => false
        );
    }

    /** @return array{source_id:int,binding_id:int,profile_path:string,cookie_path:string} */
    private function insertProfile(
        string $platform,
        string $profileKey,
        int $hotelId,
        string $lastSyncTime
    ): array {
        $profilePath = self::$projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR
            . $platform . '_profile_' . $profileKey;
        $cookiePath = $profilePath . DIRECTORY_SEPARATOR . 'Default'
            . DIRECTORY_SEPARATOR . 'Network' . DIRECTORY_SEPARATOR . 'Cookies';
        mkdir(dirname($cookiePath), 0775, true);
        file_put_contents($cookiePath, 'fixture-session');
        $oldTime = strtotime('2026-06-01 00:00:00');
        touch($cookiePath, $oldTime);

        $config = $platform === 'meituan'
            ? ['profile_binding_key' => $profileKey, 'store_id' => $profileKey]
            : ['profile_binding_key' => $profileKey, 'profile_id' => $profileKey];
        $sourceId = (int)Db::name('platform_data_sources')->insertGetId([
            'tenant_id' => $hotelId,
            'system_hotel_id' => $hotelId,
            'platform' => $platform,
            'ingestion_method' => 'browser_profile',
            'enabled' => 1,
            'status' => 'success',
            'last_sync_time' => $lastSyncTime,
            'last_sync_status' => 'success',
            'last_error' => null,
            'config_json' => json_encode($config, JSON_THROW_ON_ERROR),
            'secret_json' => json_encode(['fixture_secret' => true], JSON_THROW_ON_ERROR),
            'create_time' => '2026-06-01 00:00:00',
            'update_time' => $lastSyncTime,
        ]);
        $bindingId = (int)Db::name('ota_profile_bindings')->insertGetId([
            'tenant_id' => $hotelId,
            'system_hotel_id' => $hotelId,
            'platform' => $platform,
            'profile_key_hash' => hash('sha256', $profileKey),
            'binding_status' => 'active',
            'revoked_by' => null,
            'create_time' => '2026-06-01 00:00:00',
            'update_time' => '2026-06-01 00:00:00',
        ]);

        return [
            'source_id' => $sourceId,
            'binding_id' => $bindingId,
            'profile_path' => $profilePath,
            'cookie_path' => $cookiePath,
        ];
    }

    private function createOldArtifact(string $relativePath, string $mtime): string
    {
        $path = self::$projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, 'fixture');
        touch($path, strtotime($mtime));
        return $path;
    }

    /** @return array{id:int,source_id:?int} */
    private function insertCredential(
        string $configId,
        ?string $lastUsedAt,
        ?string $rotatedAt,
        ?string $createTime,
        bool $withSource = false
    ): array {
        $hotelId = str_starts_with($configId, 'manual-') ? 20 : 10;
        $id = (int)Db::name('ota_credentials')->insertGetId([
            'tenant_id' => $hotelId,
            'system_hotel_id' => $hotelId,
            'platform' => 'ctrip',
            'config_id' => $configId,
            'encrypted_payload' => 'fixture-ciphertext',
            'payload_version' => 1,
            'key_id' => 'fixture-key',
            'secret_mask' => 'fi****xt',
            'credential_status' => 'ready',
            'created_by' => 1,
            'rotated_at' => $rotatedAt,
            'last_used_at' => $lastUsedAt,
            'revoked_at' => null,
            'create_time' => $createTime,
            'update_time' => $createTime,
        ]);
        $sourceId = null;
        if ($withSource) {
            $sourceId = (int)Db::name('platform_data_sources')->insertGetId([
                'tenant_id' => $hotelId,
                'system_hotel_id' => $hotelId,
                'platform' => 'ctrip',
                'ingestion_method' => 'manual_cookie_api',
                'enabled' => 1,
                'status' => 'ready',
                'last_sync_status' => 'ready',
                'config_json' => json_encode([
                    'credential_ref' => $id,
                    'config_id' => $configId,
                    'credential_status' => 'ready',
                    'has_cookies' => true,
                ], JSON_THROW_ON_ERROR),
                'secret_json' => json_encode(['legacy_fixture' => true], JSON_THROW_ON_ERROR),
                'create_time' => $createTime,
                'update_time' => $createTime,
            ]);
        }
        return ['id' => $id, 'source_id' => $sourceId];
    }

    private function createSchema(): void
    {
        Db::execute('CREATE TABLE platform_data_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            platform VARCHAR(20) NOT NULL,
            ingestion_method VARCHAR(32) NOT NULL,
            enabled INTEGER NOT NULL DEFAULT 1,
            status VARCHAR(32),
            last_sync_time DATETIME,
            last_sync_status VARCHAR(32),
            last_error TEXT,
            config_json TEXT,
            secret_json TEXT,
            create_time DATETIME,
            update_time DATETIME
        )');
        Db::execute('CREATE TABLE ota_profile_bindings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            platform VARCHAR(20) NOT NULL,
            profile_key_hash VARCHAR(64) NOT NULL,
            binding_status VARCHAR(20) NOT NULL,
            revoked_by INTEGER,
            create_time DATETIME,
            update_time DATETIME
        )');
        Db::execute('CREATE TABLE platform_data_sync_tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            data_source_id INTEGER,
            system_hotel_id INTEGER,
            platform VARCHAR(20),
            status VARCHAR(32) NOT NULL,
            started_at DATETIME,
            finished_at DATETIME,
            create_time DATETIME,
            update_time DATETIME
        )');
        Db::execute('CREATE TABLE online_daily_data (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            system_hotel_id INTEGER,
            platform VARCHAR(20),
            data_date DATE
        )');
        Db::execute('CREATE TABLE ota_credentials (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            platform VARCHAR(20) NOT NULL,
            config_id VARCHAR(100) NOT NULL,
            encrypted_payload TEXT NOT NULL,
            payload_version INTEGER NOT NULL,
            key_id VARCHAR(100) NOT NULL,
            secret_mask VARCHAR(255) NOT NULL,
            credential_status VARCHAR(20) NOT NULL,
            created_by INTEGER NOT NULL,
            rotated_at DATETIME,
            last_used_at DATETIME,
            revoked_at DATETIME,
            create_time DATETIME,
            update_time DATETIME,
            UNIQUE(tenant_id,system_hotel_id,platform,config_id)
        )');
        Db::execute('CREATE TABLE manual_online_fetch_task_statuses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id VARCHAR(96) NOT NULL UNIQUE,
            hotel_id INTEGER NOT NULL,
            user_id INTEGER,
            platform VARCHAR(20) NOT NULL,
            task_kind VARCHAR(60) NOT NULL,
            status VARCHAR(40) NOT NULL,
            stage VARCHAR(60) NOT NULL,
            status_json TEXT NOT NULL,
            started_at DATETIME,
            finished_at DATETIME,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        )');
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $item) {
            if ($item->isDir() && !$item->isLink()) {
                self::removeTree($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }
}
