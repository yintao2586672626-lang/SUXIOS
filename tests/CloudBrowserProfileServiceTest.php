<?php
declare(strict_types=1);

namespace Tests;

use app\service\CloudBrowserProfileService;
use app\service\OtaProfileBindingService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class CloudBrowserProfileServiceTest extends TestCase
{
    private static array $databaseConfig;
    private static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . '/cloud_browser_profile_' . getmypid() . '.sqlite';
        @unlink(self::$databasePath);
        $config = self::$databaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = ['type' => 'sqlite', 'database' => self::$databasePath, 'prefix' => '', 'fields_strict' => false];
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
        Db::execute('CREATE TABLE IF NOT EXISTS hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT NOT NULL, status INTEGER NOT NULL)');
        Db::execute('CREATE TABLE IF NOT EXISTS cloud_browser_profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, owner_user_id INTEGER NOT NULL, platform TEXT NOT NULL, profile_public_id TEXT NOT NULL UNIQUE, authorization_status TEXT NOT NULL, status_reason TEXT NOT NULL, login_verified_at TEXT NULL, ready_at TEXT NULL, session_expires_at TEXT NULL, last_state_change_at TEXT NOT NULL, create_time TEXT NOT NULL, update_time TEXT NOT NULL, UNIQUE(tenant_id, owner_user_id, system_hotel_id, platform))');
        Db::execute('CREATE TABLE IF NOT EXISTS cloud_browser_login_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, profile_id INTEGER NOT NULL, session_public_id TEXT NOT NULL UNIQUE, ticket_hash TEXT NOT NULL, session_status TEXT NOT NULL, requested_by INTEGER NOT NULL, expires_at TEXT NOT NULL, verified_at TEXT NULL, create_time TEXT NOT NULL, update_time TEXT NOT NULL)');
        Db::execute('CREATE TABLE IF NOT EXISTS platform_data_sources (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, user_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, platform TEXT NOT NULL, ingestion_method TEXT NOT NULL, enabled INTEGER NOT NULL, status TEXT NOT NULL, config_json TEXT NULL)');
        Db::execute('CREATE TABLE IF NOT EXISTS ota_profile_bindings (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, platform TEXT NOT NULL, profile_key_hash TEXT NOT NULL, binding_status TEXT NOT NULL, bound_by INTEGER NULL, revoked_by INTEGER NULL, create_time TEXT NOT NULL, update_time TEXT NOT NULL)');
        Db::name('ota_profile_bindings')->delete(true);
        Db::name('platform_data_sources')->delete(true);
        Db::name('cloud_browser_login_sessions')->delete(true);
        Db::name('cloud_browser_profiles')->delete(true);
        Db::name('hotels')->delete(true);
        Db::name('hotels')->insertAll([
            ['id' => 80, 'tenant_id' => 8, 'name' => '敦煌漠蓝新', 'status' => 1],
            ['id' => 81, 'tenant_id' => 9, 'name' => '其他酒店', 'status' => 1],
        ]);
    }

    public function testIssuesOpaqueLoginEntryAndNeverPersistsTicketOrSessionMaterial(): void
    {
        $service = new CloudBrowserProfileService();
        $entry = $service->requestLoginEntry(80, 7, 'ctrip');
        self::assertSame(CloudBrowserProfileService::AWAITING_LOGIN, $entry['profile']['authorization_status']);
        self::assertFalse($entry['login_entry']['browser_started']);
        self::assertSame('cloud_browser_gateway_loopback', $entry['login_entry']['entry_mode']);
        self::assertSame('POST', $entry['login_entry']['gateway_method']);
        self::assertSame('/v1/login/open', $entry['login_entry']['gateway_path']);
        self::assertNotEmpty($entry['login_entry']['ticket']);
        $stored = Db::name('cloud_browser_login_sessions')->order('id', 'asc')->find();
        self::assertSame(hash('sha256', $entry['login_entry']['ticket']), (string)$stored['ticket_hash']);
        self::assertStringNotContainsString($entry['login_entry']['ticket'], (string)$stored['ticket_hash']);
        self::assertArrayNotHasKey('ticket_hash', $entry['profile']);
        self::assertArrayNotHasKey('profile_path', $entry['profile']);
        self::assertArrayNotHasKey('cookie', $entry['profile']);
    }

    public function testScopesProfilesByTenantHotelUserAndPlatform(): void
    {
        $service = new CloudBrowserProfileService();
        $a = $service->requestLoginEntry(80, 7, 'ctrip');
        $b = $service->requestLoginEntry(80, 8, 'ctrip');
        $c = $service->requestLoginEntry(81, 7, 'ctrip');
        self::assertNotSame($a['profile']['profile_id'], $b['profile']['profile_id']);
        self::assertNotSame($a['profile']['profile_id'], $c['profile']['profile_id']);
        self::assertCount(1, $service->status(80, 7)['profiles']);
        self::assertCount(1, $service->status(80, 8)['profiles']);
        self::assertSame(3, (int)Db::name('cloud_browser_profiles')->count());
    }

    public function testTrustedGatewayMustConsumeTicketBeforeProfileCanBeCollectable(): void
    {
        $service = new CloudBrowserProfileService();
        $entry = $service->requestLoginEntry(80, 7, 'meituan');
        $this->expectException(\RuntimeException::class);
        $service->markReadyToCollect((string)$entry['profile']['profile_id']);
    }

    public function testRejectsInvalidSessionExpiryBeforeProfileBecomesCollectable(): void
    {
        $service = new CloudBrowserProfileService();
        $entry = $service->requestLoginEntry(80, 7, 'ctrip');
        $service->markLoginVerified(
            (string)$entry['profile']['profile_id'],
            (string)$entry['login_entry']['session_id'],
            (string)$entry['login_entry']['ticket']
        );

        try {
            $service->markReadyToCollect((string)$entry['profile']['profile_id'], 'not-a-date');
            self::fail('invalid session expiry must be rejected');
        } catch (\RuntimeException $e) {
            self::assertSame('cloud_browser_session_expiry_invalid', $e->getMessage());
        }

        $status = $service->status(80, 7, 'ctrip');
        self::assertSame(CloudBrowserProfileService::LOGIN_VERIFIED, $status['profiles'][0]['authorization_status']);
        $expiresAt = date('Y-m-d H:i:s', time() + 3600);
        $ready = $service->markReadyToCollect((string)$entry['profile']['profile_id'], $expiresAt);
        self::assertSame(CloudBrowserProfileService::READY_TO_COLLECT, $ready['authorization_status']);
        self::assertSame($expiresAt, $ready['session_expires_at']);
    }

    public function testStateMachineVerifiesTicketThenAllowsReadyAndReauthorization(): void
    {
        $service = new CloudBrowserProfileService();
        $entry = $service->requestLoginEntry(80, 7, 'meituan');
        $verified = $service->markLoginVerified(
            (string)$entry['profile']['profile_id'],
            (string)$entry['login_entry']['session_id'],
            (string)$entry['login_entry']['ticket']
        );
        self::assertSame(CloudBrowserProfileService::LOGIN_VERIFIED, $verified['authorization_status']);
        $ready = $service->markReadyToCollect((string)$entry['profile']['profile_id']);
        self::assertSame(CloudBrowserProfileService::READY_TO_COLLECT, $ready['authorization_status']);
        $expired = $service->markSessionExpired((string)$entry['profile']['profile_id']);
        self::assertSame(CloudBrowserProfileService::SESSION_EXPIRED, $expired['authorization_status']);
        $retry = $service->requestLoginEntry(80, 7, 'meituan');
        self::assertSame(CloudBrowserProfileService::AWAITING_RELOGIN, $retry['profile']['authorization_status']);
        self::assertSame(1, (int)Db::name('cloud_browser_profiles')->count());

        try {
            $service->requestLoginEntry(80, 7, 'meituan');
            self::fail('an active login session must not be silently superseded');
        } catch (\RuntimeException $error) {
            self::assertSame('cloud_browser_login_session_active', $error->getMessage());
        }
        Db::name('cloud_browser_login_sessions')
            ->where('session_public_id', (string)$retry['login_entry']['session_id'])
            ->update([
                'session_status' => 'expired',
                'expires_at' => date('Y-m-d H:i:s', time() - 1),
            ]);
        $replacement = $service->requestLoginEntry(80, 7, 'meituan');
        self::assertSame(CloudBrowserProfileService::AWAITING_RELOGIN, $replacement['profile']['authorization_status']);
        self::assertNotSame($retry['login_entry']['session_id'], $replacement['login_entry']['session_id']);
        self::assertSame(
            'expired',
            (string)Db::name('cloud_browser_login_sessions')
                ->where('session_public_id', (string)$retry['login_entry']['session_id'])
                ->value('session_status')
        );
        self::assertSame(
            'issued',
            (string)Db::name('cloud_browser_login_sessions')
                ->where('session_public_id', (string)$replacement['login_entry']['session_id'])
                ->value('session_status')
        );
    }

    public function testGatewayPreflightDoesNotConsumeTicketAndCompletionIsAtomic(): void
    {
        $service = new CloudBrowserProfileService();
        $entry = $service->requestLoginEntry(80, 7, 'ctrip');
        $profileId = (string)$entry['profile']['profile_id'];
        $sessionId = (string)$entry['login_entry']['session_id'];
        $ticket = (string)$entry['login_entry']['ticket'];

        $preflight = $service->validateLoginEntry($profileId, $sessionId, $ticket);
        self::assertTrue($preflight['login_entry']['validated']);
        self::assertFalse($preflight['login_entry']['consumed']);
        self::assertSame('issued', (string)Db::name('cloud_browser_login_sessions')->value('session_status'));

        $ready = $service->completeGatewayLogin(
            $profileId,
            $sessionId,
            $ticket,
            date('Y-m-d H:i:s', time() + 86400)
        );
        self::assertSame(CloudBrowserProfileService::READY_TO_COLLECT, $ready['authorization_status']);
        self::assertSame('verified', (string)Db::name('cloud_browser_login_sessions')->value('session_status'));

        $this->expectException(\RuntimeException::class);
        $service->completeGatewayLogin(
            $profileId,
            $sessionId,
            $ticket,
            date('Y-m-d H:i:s', time() + 86400)
        );
    }

    public function testRejectsWrongTicketAndUnsupportedPlatform(): void
    {
        $service = new CloudBrowserProfileService();
        $entry = $service->requestLoginEntry(80, 7, 'ctrip');
        try {
            $service->markLoginVerified((string)$entry['profile']['profile_id'], (string)$entry['login_entry']['session_id'], 'wrong');
            self::fail('wrong ticket must be rejected');
        } catch (\RuntimeException $e) {
            self::assertSame('cloud_browser_login_entry_invalid', $e->getMessage());
        }
        $this->expectException(\RuntimeException::class);
        $service->requestLoginEntry(80, 7, 'unknown-platform');
    }

    public function testDingdandaoProfileRequiresExactReadySameDayCollectionScope(): void
    {
        $service = new CloudBrowserProfileService();
        $entry = $service->requestLoginEntry(80, 7, 'dingdandao');
        $ready = $service->completeGatewayLogin(
            (string)$entry['profile']['profile_id'],
            (string)$entry['login_entry']['session_id'],
            (string)$entry['login_entry']['ticket'],
            date('Y-m-d H:i:s', time() + 86400)
        );
        self::assertSame(CloudBrowserProfileService::READY_TO_COLLECT, $ready['authorization_status']);

        $validated = $service->validateDingdandaoCollectionProfile(
            (string)$entry['profile']['profile_id'],
            8,
            80,
            7,
            date('Y-m-d')
        );
        self::assertTrue($validated['validated']);
        self::assertSame('read_only', $validated['access_mode']);
        self::assertSame('today_only', $validated['source_scope']);
        self::assertSame('敦煌漠蓝新', $validated['expected_hotel_name']);

        $previousDate = (new \DateTimeImmutable(
            'now',
            new \DateTimeZone('Asia/Shanghai')
        ))->modify('-1 day')->format('Y-m-d');
        $historical = $service->validateDingdandaoCollectionProfile(
            (string)$entry['profile']['profile_id'],
            8,
            80,
            7,
            $previousDate
        );
        self::assertTrue($historical['validated']);
        self::assertSame('operating_target_historical', $historical['collection_kind']);
        self::assertSame('historical_single_date', $historical['source_scope']);
        self::assertSame('historical_daily', $historical['data_period']);

        try {
            $service->validateDingdandaoCollectionProfile(
                (string)$entry['profile']['profile_id'],
                8,
                81,
                7,
                date('Y-m-d')
            );
            self::fail('cross-hotel collection scope must be rejected');
        } catch (\RuntimeException $error) {
            self::assertSame('cloud_browser_collection_scope_mismatch', $error->getMessage());
        }

        $service->markSessionExpired((string)$entry['profile']['profile_id']);
        try {
            $service->validateDingdandaoCollectionProfile(
                (string)$entry['profile']['profile_id'],
                8,
                80,
                7,
                date('Y-m-d')
            );
            self::fail('expired Profile must be rejected');
        } catch (\RuntimeException $error) {
            self::assertSame('cloud_browser_collection_profile_not_ready', $error->getMessage());
        }
    }

    public function testOtaProfileRequiresExactReadySourceAndRegisteredBinding(): void
    {
        $service = new CloudBrowserProfileService();
        $cases = [
            ['source_id' => 25, 'platform' => 'ctrip', 'binding_key' => 'ctrip-profile-80', 'platform_hotel_id' => '130079194', 'source_url' => 'https://ebooking.ctrip.com/home/mainland'],
            ['source_id' => 68, 'platform' => 'meituan', 'binding_key' => 'meituan-profile-80', 'platform_hotel_id' => '1029642156589279', 'source_url' => 'https://me.meituan.com/ebooking/'],
        ];

        foreach ($cases as $case) {
            $entry = $service->requestLoginEntry(80, 7, $case['platform']);
            $service->completeGatewayLogin(
                (string)$entry['profile']['profile_id'],
                (string)$entry['login_entry']['session_id'],
                (string)$entry['login_entry']['ticket'],
                date('Y-m-d H:i:s', time() + 86400)
            );
            Db::name('platform_data_sources')->insert([
                'id' => $case['source_id'],
                'tenant_id' => 8,
                'user_id' => 7,
                'system_hotel_id' => 80,
                'platform' => $case['platform'],
                'ingestion_method' => 'browser_profile',
                'enabled' => 1,
                'status' => 'ready',
                'config_json' => json_encode([
                    'profile_binding_key' => (string)$entry['profile']['profile_id'],
                    'platform_hotel_id' => $case['platform_hotel_id'],
                ], JSON_THROW_ON_ERROR),
            ]);
            (new OtaProfileBindingService())->claim(
                80,
                $case['platform'],
                (string)$entry['profile']['profile_id'],
                7,
                true
            );

            $validated = $service->validateOtaDataSourceCollectionProfile(
                (string)$entry['profile']['profile_id'],
                $case['source_id'],
                8,
                80,
                7,
                date('Y-m-d'),
                $case['platform']
            );
            self::assertTrue($validated['validated']);
            self::assertSame('ota_channel_profile', $validated['collection_kind']);
            self::assertSame('read_only', $validated['access_mode']);
            self::assertSame('ota_channel', $validated['source_scope']);
            self::assertSame($case['source_url'], $validated['source_url']);
            self::assertSame($case['source_id'], $validated['data_source_id']);
            self::assertSame($case['platform_hotel_id'], $validated['platform_hotel_id']);
            self::assertSame($case['platform'], $validated['profile']['platform']);

            $previousDate = (new \DateTimeImmutable(
                'now',
                new \DateTimeZone('Asia/Shanghai')
            ))->modify('-1 day')->format('Y-m-d');
            $historical = $service->validateOtaDataSourceCollectionProfile(
                (string)$entry['profile']['profile_id'],
                $case['source_id'],
                8,
                80,
                7,
                $previousDate,
                $case['platform']
            );
            self::assertTrue($historical['validated']);
            self::assertSame('historical_daily', $historical['data_period']);
            self::assertSame($previousDate, $historical['target_date']);
        }

        try {
            $service->validateOtaDataSourceCollectionProfile(
                (string)$service->status(80, 7, 'ctrip')['profiles'][0]['profile_id'],
                68,
                8,
                80,
                7,
                date('Y-m-d'),
                'ctrip'
            );
            self::fail('cross-platform data source must be rejected');
        } catch (\RuntimeException $error) {
            self::assertSame('cloud_browser_ota_data_source_scope_mismatch', $error->getMessage());
        }
    }

    public function testOtaProfileRequiresExactPlatformHotelUserAndSameDayScope(): void
    {
        $service = new CloudBrowserProfileService();
        $entry = $service->requestLoginEntry(80, 7, 'ctrip');
        $service->completeGatewayLogin(
            (string)$entry['profile']['profile_id'],
            (string)$entry['login_entry']['session_id'],
            (string)$entry['login_entry']['ticket'],
            date('Y-m-d H:i:s', time() + 86400)
        );

        $validated = $service->validateOtaCollectionProfile(
            (string)$entry['profile']['profile_id'],
            8,
            80,
            7,
            date('Y-m-d'),
            'ctrip'
        );
        self::assertTrue($validated['validated']);
        self::assertSame('ota_target_date', $validated['collection_kind']);
        self::assertSame('target_date_only', $validated['source_scope']);
        self::assertSame('ctrip', $validated['profile']['platform']);

        $previousDate = (new \DateTimeImmutable(
            'now',
            new \DateTimeZone('Asia/Shanghai')
        ))->modify('-1 day')->format('Y-m-d');
        $historical = $service->validateOtaCollectionProfile(
            (string)$entry['profile']['profile_id'],
            8,
            80,
            7,
            $previousDate,
            'ctrip'
        );
        self::assertSame('historical_daily', $historical['data_period']);
        self::assertSame($previousDate, $historical['target_date']);

        try {
            $service->validateOtaCollectionProfile(
                (string)$entry['profile']['profile_id'],
                8,
                80,
                7,
                date('Y-m-d'),
                'meituan'
            );
            self::fail('cross-platform Profile use must be rejected');
        } catch (\RuntimeException $error) {
            self::assertSame('cloud_browser_collection_scope_mismatch', $error->getMessage());
        }
    }

    public function testEnsureProfileIsIdempotentAndDoesNotIssueLoginTicket(): void
    {
        $service = new CloudBrowserProfileService();
        $first = $service->ensureProfile(80, 7, 'ctrip');
        $second = $service->ensureProfile(80, 7, 'ctrip');

        self::assertSame($first['profile_id'], $second['profile_id']);
        self::assertStringStartsWith('cbp_', (string)$first['profile_id']);
        self::assertSame(CloudBrowserProfileService::UNAUTHORIZED, $first['authorization_status']);
        self::assertSame(1, (int)Db::name('cloud_browser_profiles')->count());
        self::assertSame(0, (int)Db::name('cloud_browser_login_sessions')->count());
    }

    public function testAnySourceProfileAliasMustMatchExactCloudProfileId(): void
    {
        $service = new CloudBrowserProfileService();
        $entry = $service->requestLoginEntry(80, 7, 'ctrip');
        $service->completeGatewayLogin(
            (string)$entry['profile']['profile_id'],
            (string)$entry['login_entry']['session_id'],
            (string)$entry['login_entry']['ticket'],
            date('Y-m-d H:i:s', time() + 86400)
        );
        $wrongManagedProfileId = 'ctrip-profile-80';
        Db::name('platform_data_sources')->insert([
            'id' => 90,
            'tenant_id' => 8,
            'user_id' => 7,
            'system_hotel_id' => 80,
            'platform' => 'ctrip',
            'ingestion_method' => 'browser_profile',
            'enabled' => 1,
            'status' => 'ready',
            'config_json' => json_encode([
                'profile_binding_key' => $wrongManagedProfileId,
                'platform_hotel_id' => '130079194',
            ], JSON_THROW_ON_ERROR),
        ]);
        (new OtaProfileBindingService())->claim(80, 'ctrip', $wrongManagedProfileId, 7, true);

        try {
            $service->validateOtaDataSourceCollectionProfile(
                (string)$entry['profile']['profile_id'],
                90,
                8,
                80,
                7,
                date('Y-m-d'),
                'ctrip'
            );
            self::fail('every source Profile alias must match the exact cloud Profile id');
        } catch (\RuntimeException $error) {
            self::assertSame('cloud_browser_ota_profile_id_mismatch', $error->getMessage());
        }
    }

    public function testConflictingExplicitProfileAliasesAreRejected(): void
    {
        $service = new CloudBrowserProfileService();
        $entry = $service->requestLoginEntry(80, 7, 'meituan');
        $service->completeGatewayLogin(
            (string)$entry['profile']['profile_id'],
            (string)$entry['login_entry']['session_id'],
            (string)$entry['login_entry']['ticket'],
            date('Y-m-d H:i:s', time() + 86400)
        );
        Db::name('platform_data_sources')->insert([
            'id' => 91,
            'tenant_id' => 8,
            'user_id' => 7,
            'system_hotel_id' => 80,
            'platform' => 'meituan',
            'ingestion_method' => 'profile_browser',
            'enabled' => 1,
            'status' => 'ready',
            'config_json' => json_encode([
                'profile_binding_key' => (string)$entry['profile']['profile_id'],
                'stable_profile_id' => 'cbp_conflicting_profile_alias_1234',
                'platform_hotel_id' => '1029642156589279',
            ], JSON_THROW_ON_ERROR),
        ]);

        try {
            $service->validateOtaDataSourceCollectionProfile(
                (string)$entry['profile']['profile_id'],
                91,
                8,
                80,
                7,
                date('Y-m-d'),
                'meituan'
            );
            self::fail('conflicting explicit Profile aliases must fail closed');
        } catch (\RuntimeException $error) {
            self::assertSame('cloud_browser_ota_profile_binding_key_conflict', $error->getMessage());
        }
    }
}
