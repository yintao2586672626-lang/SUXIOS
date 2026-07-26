<?php
declare(strict_types=1);

namespace Tests;

use app\service\CloudBrowserProfileService;
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

        $replacement = $service->requestLoginEntry(80, 7, 'meituan');
        self::assertSame(CloudBrowserProfileService::AWAITING_RELOGIN, $replacement['profile']['authorization_status']);
        self::assertNotSame($retry['login_entry']['session_id'], $replacement['login_entry']['session_id']);
        self::assertSame(
            'superseded',
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
}
