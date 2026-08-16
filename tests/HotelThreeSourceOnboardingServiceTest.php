<?php
declare(strict_types=1);

namespace Tests;

use app\service\CloudBrowserProfileService;
use app\service\HotelThreeSourceOnboardingService;
use app\service\PlatformDataSyncService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class HotelThreeSourceOnboardingServiceTest extends TestCase
{
    private static array $databaseConfig;
    private static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . '/hotel_three_source_onboarding_' . getmypid() . '.sqlite';
        @unlink(self::$databasePath);
        $config = self::$databaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$databasePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($config, 'database');
        Db::connect(null, true);
    }

    public static function tearDownAfterClass(): void
    {
        Config::set(self::$databaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$databasePath);
    }

    protected function setUp(): void
    {
        Db::execute('CREATE TABLE IF NOT EXISTS hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT NOT NULL, status INTEGER NOT NULL, ota_channel_strategy TEXT NOT NULL DEFAULT "none")');
        Db::execute('CREATE TABLE IF NOT EXISTS cloud_browser_profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, owner_user_id INTEGER NOT NULL, platform TEXT NOT NULL, profile_public_id TEXT NOT NULL UNIQUE, authorization_status TEXT NOT NULL, status_reason TEXT NOT NULL, login_verified_at TEXT NULL, ready_at TEXT NULL, session_expires_at TEXT NULL, last_state_change_at TEXT NOT NULL, create_time TEXT NOT NULL, update_time TEXT NOT NULL, UNIQUE(tenant_id, owner_user_id, system_hotel_id, platform))');
        Db::execute('CREATE TABLE IF NOT EXISTS platform_data_sources (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, user_id INTEGER NULL, name TEXT NOT NULL, platform TEXT NOT NULL, data_type TEXT NOT NULL, ingestion_method TEXT NOT NULL, status TEXT NOT NULL, enabled INTEGER NOT NULL, config_json TEXT NOT NULL, secret_json TEXT NOT NULL, last_sync_time TEXT NULL, last_sync_status TEXT NULL, last_error TEXT NULL, created_by INTEGER NULL, updated_by INTEGER NULL, create_time TEXT NOT NULL, update_time TEXT NOT NULL)');
        Db::execute('CREATE TABLE IF NOT EXISTS ota_profile_bindings (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, platform TEXT NOT NULL, profile_key_hash TEXT NOT NULL, binding_status TEXT NOT NULL, bound_by INTEGER NULL, revoked_by INTEGER NULL, create_time TEXT NOT NULL, update_time TEXT NOT NULL, UNIQUE(platform, profile_key_hash))');
        Db::execute('CREATE TABLE IF NOT EXISTS dingdandao_pms_integrations (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, provider TEXT NOT NULL, status INTEGER NOT NULL, provider_hotel_id TEXT NULL, provider_hotel_name TEXT NULL)');
        Db::execute('CREATE TABLE IF NOT EXISTS meituan_cloud_pms_integrations (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, provider TEXT NOT NULL, status INTEGER NOT NULL, provider_hotel_id TEXT NULL, provider_hotel_name TEXT NULL)');

        foreach (['ota_profile_bindings', 'platform_data_sources', 'cloud_browser_profiles', 'dingdandao_pms_integrations', 'meituan_cloud_pms_integrations', 'hotels'] as $table) {
            Db::name($table)->delete(true);
        }
        Db::name('hotels')->insert([
            'id' => 80,
            'tenant_id' => 8,
            'name' => 'Hotel 80',
            'status' => 1,
            'ota_channel_strategy' => 'dual',
        ]);
        Db::name('dingdandao_pms_integrations')->insert([
            'tenant_id' => 8,
            'hotel_id' => 80,
            'provider' => 'dingdandao_pms',
            'status' => 0,
        ]);
        Db::name('meituan_cloud_pms_integrations')->insert([
            'tenant_id' => 8,
            'hotel_id' => 80,
            'provider' => 'meituan_cloud_pms',
            'status' => 0,
        ]);
    }

    public function testBindsBothOtaPlatformsWithoutCredentialsAndReusesExactProfiles(): void
    {
        $service = $this->service();
        $actor = $this->actor();

        $ctrip = $service->bindPlatform($actor, 8, 80, 'ctrip', '130079194', 'Hotel 80 Ctrip');
        $ctripAgain = $service->bindPlatform($actor, 8, 80, 'ctrip', '130079194', 'Hotel 80 Ctrip');
        $meituan = $service->bindPlatform($actor, 8, 80, 'meituan', '1029642156589279', 'Hotel 80 Meituan');

        self::assertTrue($ctrip['readback_verified']);
        self::assertSame($ctrip['data_source']['id'], $ctripAgain['data_source']['id']);
        self::assertTrue($meituan['readback_verified']);
        self::assertFalse($ctrip['credentials_accepted']);
        self::assertFalse($ctrip['browser_started']);
        self::assertFalse($ctrip['collection_performed']);
        self::assertFalse($ctrip['message_sent']);
        self::assertSame(2, (int)Db::name('platform_data_sources')->count());
        self::assertSame(2, (int)Db::name('cloud_browser_profiles')->count());
        self::assertSame(2, (int)Db::name('ota_profile_bindings')->count());

        $ctripRow = Db::name('platform_data_sources')->where('platform', 'ctrip')->find();
        $ctripConfig = json_decode((string)$ctripRow['config_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertStringStartsWith('cbp_', (string)$ctripConfig['profile_binding_key']);
        self::assertSame($ctripConfig['profile_binding_key'], $ctripConfig['stable_profile_id']);
        self::assertSame($ctripConfig['profile_binding_key'], $ctripConfig['profile_id']);
        self::assertSame('130079194', $ctripConfig['platform_hotel_id']);
        self::assertSame('130079194', $ctripConfig['ctrip_hotel_id']);
        self::assertSame('130079194', $ctripConfig['hotel_id']);
        self::assertSame('operator_confirmed_onboarding', $ctripConfig['platform_hotel_identity_source']);
        self::assertNotFalse(strtotime((string)$ctripConfig['platform_hotel_identity_checked_at']));
        self::assertSame('{}', (string)$ctripRow['secret_json']);

        $meituanRow = Db::name('platform_data_sources')->where('platform', 'meituan')->find();
        $meituanConfig = json_decode((string)$meituanRow['config_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($meituanConfig['profile_binding_key'], $meituanConfig['stable_profile_id']);
        self::assertSame('1029642156589279', $meituanConfig['store_id']);
        self::assertSame('1029642156589279', $meituanConfig['poi_id']);
        self::assertSame('Hotel 80 Meituan', $meituanConfig['poi_name']);
        self::assertStringNotContainsString('secret_json', json_encode($ctrip, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('ticket', json_encode($ctrip, JSON_THROW_ON_ERROR));
    }

    public function testBindingUpgradesOneLegacyNumericProfileSourceWithoutCreatingADuplicate(): void
    {
        Db::name('platform_data_sources')->insert([
            'tenant_id' => 8,
            'system_hotel_id' => 80,
            'user_id' => 7,
            'name' => 'Legacy Ctrip Profile',
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'ingestion_method' => 'browser_profile',
            'status' => 'failed',
            'enabled' => 1,
            'config_json' => json_encode([
                'platform_hotel_id' => '130079194',
                'hotel_name' => 'Hotel 80 Ctrip',
                'profile_binding_key' => 130079194,
                'stable_profile_id' => 130079194,
                'profile_id' => 130079194,
            ], JSON_THROW_ON_ERROR),
            'secret_json' => '{}',
            'created_by' => 7,
            'updated_by' => 7,
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ]);
        $legacyBindingId = (int)Db::name('ota_profile_bindings')->insertGetId([
            'tenant_id' => 8,
            'system_hotel_id' => 80,
            'platform' => 'ctrip',
            'profile_key_hash' => hash('sha256', '130079194'),
            'binding_status' => 'active',
            'bound_by' => 7,
            'revoked_by' => null,
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ]);
        (new CloudBrowserProfileService())->ensureProfile(80, 7, 'ctrip');
        Db::name('cloud_browser_profiles')->where('system_hotel_id', 80)->where('platform', 'ctrip')->update([
            'authorization_status' => CloudBrowserProfileService::READY_TO_COLLECT,
            'ready_at' => date('Y-m-d H:i:s', time() - 60),
            'session_expires_at' => date('Y-m-d H:i:s', time() + 86400),
        ]);

        $result = $this->service()->bindPlatform(
            $this->actor(),
            8,
            80,
            'ctrip',
            '130079194',
            'Hotel 80 Ctrip'
        );

        self::assertTrue($result['readback_verified']);
        self::assertSame(1, (int)Db::name('platform_data_sources')->where('platform', 'ctrip')->count());
        $row = Db::name('platform_data_sources')->where('platform', 'ctrip')->find();
        $config = json_decode((string)$row['config_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertStringStartsWith('cbp_', (string)$config['profile_binding_key']);
        self::assertSame('operator_confirmed_onboarding', $config['platform_hotel_identity_source']);
        self::assertSame('cloud_browser_profile', $config['source_method']);
        self::assertArrayNotHasKey('collector_binding_mode', $config);
        self::assertSame(
            'revoked',
            Db::name('ota_profile_bindings')->where('id', $legacyBindingId)->value('binding_status')
        );
        self::assertSame(
            1,
            (int)Db::name('ota_profile_bindings')->where('platform', 'ctrip')->where('binding_status', 'active')->count()
        );
    }

    public function testBindingRecoversHalfMigratedCloudSourceAndDropsLegacyLocalExecutionMetadata(): void
    {
        $profile = (new CloudBrowserProfileService())->ensureProfile(80, 7, 'meituan');
        $profileId = (string)$profile['profile_id'];
        Db::name('cloud_browser_profiles')->where('system_hotel_id', 80)->where('platform', 'meituan')->update([
            'authorization_status' => CloudBrowserProfileService::READY_TO_COLLECT,
            'ready_at' => date('Y-m-d H:i:s', time() - 60),
            'session_expires_at' => date('Y-m-d H:i:s', time() + 86400),
        ]);
        $sourceId = (int)Db::name('platform_data_sources')->insertGetId([
            'tenant_id' => 8,
            'system_hotel_id' => 80,
            'user_id' => 7,
            'name' => 'Half migrated Meituan Profile',
            'platform' => 'meituan',
            'data_type' => 'traffic',
            'ingestion_method' => 'browser_profile',
            'status' => 'failed',
            'enabled' => 1,
            'config_json' => json_encode([
                'platform_hotel_id' => '1029642156589279',
                'hotel_name' => 'Hotel 80 Meituan',
                'profile_binding_key' => $profileId,
                'stable_profile_id' => $profileId,
                'profile_id' => $profileId,
                'source_method' => 'single_user_local',
                'collector_binding_mode' => 'single_user_local',
                'collector_device_id' => 'legacy-device-80',
                'collector_device_id_hash' => hash('sha256', 'legacy-device-80'),
                'collector_user_id' => 7,
                'collector_tenant_id' => 8,
                'collector_hotel_id' => 80,
                'collector_platform' => 'meituan',
                'collector_bound_at' => date('Y-m-d H:i:s', time() - 600),
            ], JSON_THROW_ON_ERROR),
            'secret_json' => '{}',
            'created_by' => 7,
            'updated_by' => 7,
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ]);
        $legacyBindingId = (int)Db::name('ota_profile_bindings')->insertGetId([
            'tenant_id' => 8,
            'system_hotel_id' => 80,
            'platform' => 'meituan',
            'profile_key_hash' => hash('sha256', '1029642156589279'),
            'binding_status' => 'active',
            'bound_by' => 7,
            'revoked_by' => null,
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ]);

        $result = $this->service()->bindPlatform(
            $this->actor(),
            8,
            80,
            'meituan',
            '1029642156589279',
            'Hotel 80 Meituan'
        );

        self::assertTrue($result['readback_verified']);
        self::assertSame($sourceId, $result['data_source']['id']);
        self::assertSame('revoked', Db::name('ota_profile_bindings')->where('id', $legacyBindingId)->value('binding_status'));
        $config = json_decode(
            (string)Db::name('platform_data_sources')->where('id', $sourceId)->value('config_json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertSame('cloud_browser_profile', $config['source_method']);
        self::assertArrayNotHasKey('collector_binding_mode', $config);
        self::assertArrayNotHasKey('collector_device_id', $config);
    }

    public function testLegacyRotationRequiresTheExactCloudProfileToBeReady(): void
    {
        Db::name('platform_data_sources')->insert([
            'tenant_id' => 8,
            'system_hotel_id' => 80,
            'user_id' => 7,
            'name' => 'Legacy Ctrip Profile',
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'ingestion_method' => 'browser_profile',
            'status' => 'failed',
            'enabled' => 1,
            'config_json' => json_encode([
                'platform_hotel_id' => '130079194',
                'hotel_name' => 'Hotel 80 Ctrip',
                'profile_binding_key' => '130079194',
                'stable_profile_id' => '130079194',
                'profile_id' => '130079194',
            ], JSON_THROW_ON_ERROR),
            'secret_json' => '{}',
            'created_by' => 7,
            'updated_by' => 7,
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ]);
        $legacyBindingId = (int)Db::name('ota_profile_bindings')->insertGetId([
            'tenant_id' => 8,
            'system_hotel_id' => 80,
            'platform' => 'ctrip',
            'profile_key_hash' => hash('sha256', '130079194'),
            'binding_status' => 'active',
            'bound_by' => 7,
            'revoked_by' => null,
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ]);

        try {
            $this->service()->bindPlatform($this->actor(), 8, 80, 'ctrip', '130079194', 'Hotel 80 Ctrip');
            self::fail('Legacy binding rotation must not run before the exact cloud Profile is ready.');
        } catch (\RuntimeException $error) {
            self::assertSame('hotel_three_source_cloud_profile_not_ready_for_legacy_rotation', $error->getMessage());
        }
        self::assertSame('active', Db::name('ota_profile_bindings')->where('id', $legacyBindingId)->value('binding_status'));
        self::assertSame(1, (int)Db::name('ota_profile_bindings')->where('platform', 'ctrip')->count());
    }

    public function testStatusUsesSelectedSourcesAndDeliveryDoesNotBlockPlatformOnboarding(): void
    {
        $service = $this->service($this->activeCollectionPlan());
        $actor = $this->actor();
        Db::name('dingdandao_pms_integrations')->where('hotel_id', 80)->update([
            'status' => 1,
            'provider_hotel_id' => 'dd-80',
            'provider_hotel_name' => 'Hotel 80 PMS',
        ]);
        $service->bindPlatform($actor, 8, 80, 'ctrip', '130079194', 'Hotel 80 Ctrip');
        $service->bindPlatform($actor, 8, 80, 'meituan', '1029642156589279', 'Hotel 80 Meituan');
        (new CloudBrowserProfileService())->ensureProfile(80, 7, 'dingdandao');
        Db::name('cloud_browser_profiles')->where('system_hotel_id', 80)->update([
            'authorization_status' => CloudBrowserProfileService::READY_TO_COLLECT,
            'ready_at' => date('Y-m-d H:i:s'),
            'session_expires_at' => date('Y-m-d H:i:s', time() + 86400),
        ]);

        $status = $service->status(8, 80, 7);
        self::assertSame('ready', $status['status']);
        self::assertTrue($status['ready']);
        self::assertSame(['ctrip', 'meituan', 'dingdandao'], $status['required_platforms']);
        self::assertSame('ready', $status['sources']['ctrip']['status']);
        self::assertSame('130079194', $status['sources']['ctrip']['binding']['platform_hotel_id']);
        self::assertSame('ready', $status['sources']['meituan']['status']);
        self::assertSame('ready', $status['sources']['dingdandao']['status']);
        self::assertSame($status['sources'], $status['platforms']);
        self::assertSame($status['sources'], $status['source_statuses']);
        self::assertSame('unknown', $status['delivery']['wechat']['binding_status']);
        self::assertSame([], $status['blockers']);
        self::assertTrue($status['source_ready']);
        self::assertTrue($status['collection_plan_ready']);
    }

    public function testStatusRequiresOnlyConfiguredOtaStrategyAndSelectedPms(): void
    {
        Db::name('hotels')->where('id', 80)->update(['ota_channel_strategy' => 'ctrip_only']);
        Db::name('dingdandao_pms_integrations')->where('hotel_id', 80)->update([
            'status' => 1,
            'provider_hotel_id' => 'dd-80',
            'provider_hotel_name' => 'Hotel 80 PMS',
        ]);
        $service = $this->service();
        $service->bindPlatform($this->actor(), 8, 80, 'ctrip', '130079194', 'Hotel 80 Ctrip');
        $profileService = new CloudBrowserProfileService();
        $profileService->ensureProfile(80, 7, 'dingdandao');

        $status = $service->status(8, 80, 7);
        self::assertSame(['ctrip', 'dingdandao'], $status['required_platforms']);
        self::assertArrayNotHasKey('meituan', $status['sources']);
        self::assertSame('dd-80', $status['sources']['dingdandao']['platform_hotel_id']);
        self::assertSame('unauthorized', $status['sources']['ctrip']['status']);
        self::assertSame('unauthorized', $status['sources']['dingdandao']['authorization_status']);
        self::assertSame('blocked', $status['status']);
    }

    public function testUnconfiguredPmsBlocksEvenWhenSelectedOtaProfilesAreReady(): void
    {
        Db::name('hotels')->where('id', 80)->update(['ota_channel_strategy' => 'ctrip_only']);
        $service = $this->service();
        $service->bindPlatform($this->actor(), 8, 80, 'ctrip', '130079194', 'Hotel 80 Ctrip');
        Db::name('cloud_browser_profiles')->where('platform', 'ctrip')->update([
            'authorization_status' => CloudBrowserProfileService::READY_TO_COLLECT,
            'ready_at' => date('Y-m-d H:i:s'),
            'session_expires_at' => date('Y-m-d H:i:s', time() + 86400),
        ]);

        $status = $service->status(8, 80, 7);
        self::assertSame('blocked', $status['status']);
        self::assertContains(
            ['code' => 'pms_binding_not_ready', 'action' => 'configure_pms'],
            $status['blockers']
        );
        self::assertSame('unknown', $status['delivery']['hourly_plan']['plan_status']);
    }

    public function testReadySourcesStillNeedAnExplicitActiveAuthorizedCollectionPlan(): void
    {
        Db::name('hotels')->where('id', 80)->update(['ota_channel_strategy' => 'ctrip_only']);
        Db::name('dingdandao_pms_integrations')->where('hotel_id', 80)->update([
            'status' => 1,
            'provider_hotel_id' => 'dd-80',
            'provider_hotel_name' => 'Hotel 80 PMS',
        ]);
        $service = $this->service([
            'status' => 'active_ready',
            'plan_status' => 'active',
            'enabled' => true,
            'active_slot' => true,
            'readback_verified' => true,
            'execution_authorized' => false,
        ]);
        $service->bindPlatform($this->actor(), 8, 80, 'ctrip', '130079194', 'Hotel 80 Ctrip');
        (new CloudBrowserProfileService())->ensureProfile(80, 7, 'dingdandao');
        Db::name('cloud_browser_profiles')->where('system_hotel_id', 80)->update([
            'authorization_status' => CloudBrowserProfileService::READY_TO_COLLECT,
            'ready_at' => date('Y-m-d H:i:s'),
            'session_expires_at' => date('Y-m-d H:i:s', time() + 86400),
        ]);

        $status = $service->status(8, 80, 7);
        self::assertSame('needs_collection_plan', $status['status']);
        self::assertSame('needs_collection_plan', $status['overall_status']);
        self::assertFalse($status['ready']);
        self::assertTrue($status['source_ready']);
        self::assertFalse($status['collection_plan_ready']);
        self::assertContains(
            ['code' => 'collection_plan_not_active', 'action' => 'activate_collection_plan'],
            $status['blockers']
        );
    }

    public function testCloudProfileMustExactlyMatchTheDataSourceProfileAlias(): void
    {
        Db::name('hotels')->where('id', 80)->update(['ota_channel_strategy' => 'ctrip_only']);
        Db::name('dingdandao_pms_integrations')->where('hotel_id', 80)->update([
            'status' => 1,
            'provider_hotel_id' => 'dd-80',
            'provider_hotel_name' => 'Hotel 80 PMS',
        ]);
        $service = $this->service($this->activeCollectionPlan());
        $service->bindPlatform($this->actor(), 8, 80, 'ctrip', '130079194', 'Hotel 80 Ctrip');
        $source = Db::name('platform_data_sources')->where('platform', 'ctrip')->find();
        $config = json_decode((string)$source['config_json'], true, 512, JSON_THROW_ON_ERROR);
        $config['profile_binding_key'] = 'cbp_wrong_profile_identity_123456';
        $config['stable_profile_id'] = 'cbp_wrong_profile_identity_123456';
        $config['profile_id'] = 'cbp_wrong_profile_identity_123456';
        Db::name('platform_data_sources')->where('id', (int)$source['id'])->update([
            'config_json' => json_encode($config, JSON_THROW_ON_ERROR),
        ]);
        (new CloudBrowserProfileService())->ensureProfile(80, 7, 'dingdandao');
        Db::name('cloud_browser_profiles')->where('system_hotel_id', 80)->update([
            'authorization_status' => CloudBrowserProfileService::READY_TO_COLLECT,
            'ready_at' => date('Y-m-d H:i:s'),
            'session_expires_at' => date('Y-m-d H:i:s', time() + 86400),
        ]);

        $status = $service->status(8, 80, 7);
        self::assertFalse($status['sources']['ctrip']['binding']['readback_verified']);
        self::assertSame(
            'cloud_profile_data_source_mismatch',
            $status['sources']['ctrip']['binding']['failure_code']
        );
        self::assertSame('blocked', $status['status']);
    }

    public function testHistoricalProfileBrowserMethodUsesTheSameExactProfileContract(): void
    {
        Db::name('hotels')->where('id', 80)->update(['ota_channel_strategy' => 'ctrip_only']);
        Db::name('dingdandao_pms_integrations')->where('hotel_id', 80)->update([
            'status' => 1,
            'provider_hotel_id' => 'dd-80',
            'provider_hotel_name' => 'Hotel 80 PMS',
        ]);
        $service = $this->service($this->activeCollectionPlan());
        $service->bindPlatform($this->actor(), 8, 80, 'ctrip', '130079194', 'Hotel 80 Ctrip');
        Db::name('platform_data_sources')->where('platform', 'ctrip')->update([
            'ingestion_method' => 'profile_browser',
        ]);
        (new CloudBrowserProfileService())->ensureProfile(80, 7, 'dingdandao');
        Db::name('cloud_browser_profiles')->where('system_hotel_id', 80)->update([
            'authorization_status' => CloudBrowserProfileService::READY_TO_COLLECT,
            'ready_at' => date('Y-m-d H:i:s'),
            'session_expires_at' => date('Y-m-d H:i:s', time() + 86400),
        ]);

        $status = $service->status(8, 80, 7);
        self::assertSame('ready', $status['status']);
        self::assertTrue($status['sources']['ctrip']['binding']['readback_verified']);
    }

    public function testPmsNameWithoutProviderHotelIdRemainsBlocked(): void
    {
        Db::name('hotels')->where('id', 80)->update(['ota_channel_strategy' => 'none']);
        Db::name('dingdandao_pms_integrations')->where('hotel_id', 80)->update([
            'status' => 1,
            'provider_hotel_id' => null,
            'provider_hotel_name' => 'Hotel 80 PMS',
        ]);
        $profile = (new CloudBrowserProfileService())->ensureProfile(80, 7, 'dingdandao');
        Db::name('cloud_browser_profiles')->where('profile_public_id', $profile['profile_id'])->update([
            'authorization_status' => CloudBrowserProfileService::READY_TO_COLLECT,
            'ready_at' => date('Y-m-d H:i:s'),
            'session_expires_at' => date('Y-m-d H:i:s', time() + 86400),
        ]);

        $status = $this->service()->status(8, 80, 7);
        self::assertSame('blocked', $status['status']);
        self::assertSame('missing_binding', $status['sources']['dingdandao']['status']);
        self::assertFalse($status['sources']['dingdandao']['binding']['readback_verified']);
        self::assertContains(
            ['code' => 'pms_binding_not_ready', 'action' => 'configure_pms'],
            $status['blockers']
        );
    }

    public function testRouteAndControllerRejectCredentialShapedFieldsByAllowlist(): void
    {
        $routes = file_get_contents(dirname(__DIR__) . '/route/app.php');
        $controller = file_get_contents(dirname(__DIR__) . '/app/controller/Hotel.php');

        self::assertStringContainsString("Route::get('/:id/three-source-onboarding', 'Hotel/threeSourceOnboarding')", (string)$routes);
        self::assertStringContainsString("Route::put('/:id/platform-bindings/:platform', 'Hotel/updatePlatformBinding')", (string)$routes);
        self::assertStringContainsString("\$allowedKeys = ['platform_hotel_id', 'platform_hotel_name']", (string)$controller);
        self::assertStringContainsString("'credentials_accepted' => false", (string)$controller);
    }

    /** @param array<string,mixed>|null $collectionPlan */
    private function service(?array $collectionPlan = null): HotelThreeSourceOnboardingService
    {
        $dataSources = new PlatformDataSyncService();
        $columns = new \ReflectionProperty($dataSources, 'columns');
        $columns->setAccessible(true);
        $columns->setValue($dataSources, [
            'platform_data_sources' => array_fill_keys([
                'id', 'tenant_id', 'system_hotel_id', 'user_id', 'name', 'platform', 'data_type',
                'ingestion_method', 'status', 'enabled', 'config_json', 'secret_json', 'last_sync_time',
                'last_sync_status', 'last_error', 'created_by', 'updated_by', 'create_time', 'update_time',
            ], true),
        ]);
        $collectionPlan ??= [
            'status' => 'missing',
            'plan_status' => null,
            'enabled' => false,
            'active_slot' => false,
            'readback_verified' => false,
            'execution_authorized' => false,
        ];
        return new HotelThreeSourceOnboardingService(
            new CloudBrowserProfileService(),
            $dataSources,
            null,
            null,
            null,
            static fn(array $hotel, int $ownerUserId): array => $collectionPlan
        );
    }

    /** @return array<string,mixed> */
    private function activeCollectionPlan(): array
    {
        return [
            'status' => 'active_ready',
            'plan_status' => 'active',
            'enabled' => true,
            'active_slot' => true,
            'readback_verified' => true,
            'execution_authorized' => true,
        ];
    }

    private function actor(): object
    {
        return new class {
            public int $id = 7;
            public int $tenant_id = 8;

            public function isSuperAdmin(): bool
            {
                return false;
            }

            public function hasHotelPermission(int $hotelId, string $permission): bool
            {
                return $hotelId === 80 && $permission === 'can_fetch_online_data';
            }
        };
    }
}
