<?php
declare(strict_types=1);

namespace Tests;

use app\service\CloudBrowserLoginGatewayService;
use app\service\CloudBrowserProfileService;
use app\service\CloudBrowserViewerAuthorizationService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Cache;
use think\facade\Config;
use think\facade\Db;

final class CloudBrowserLoginGatewayServiceTest extends TestCase
{
    private static array $databaseConfig;
    private static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . '/cloud_browser_login_gateway_' . getmypid() . '.sqlite';
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
        Cache::clear();
        Db::execute('CREATE TABLE IF NOT EXISTS hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT NOT NULL, status INTEGER NOT NULL)');
        Db::execute('CREATE TABLE IF NOT EXISTS cloud_browser_profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, owner_user_id INTEGER NOT NULL, platform TEXT NOT NULL, profile_public_id TEXT NOT NULL UNIQUE, authorization_status TEXT NOT NULL, status_reason TEXT NOT NULL, login_verified_at TEXT NULL, ready_at TEXT NULL, session_expires_at TEXT NULL, last_state_change_at TEXT NOT NULL, create_time TEXT NOT NULL, update_time TEXT NOT NULL, UNIQUE(tenant_id, owner_user_id, system_hotel_id, platform))');
        Db::execute('CREATE TABLE IF NOT EXISTS cloud_browser_login_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, profile_id INTEGER NOT NULL, session_public_id TEXT NOT NULL UNIQUE, ticket_hash TEXT NOT NULL, session_status TEXT NOT NULL, requested_by INTEGER NOT NULL, expires_at TEXT NOT NULL, verified_at TEXT NULL, create_time TEXT NOT NULL, update_time TEXT NOT NULL)');
        Db::name('cloud_browser_login_sessions')->delete(true);
        Db::name('cloud_browser_profiles')->delete(true);
        Db::name('hotels')->delete(true);
        Db::name('hotels')->insertAll([
            ['id' => 80, 'tenant_id' => 8, 'name' => 'Hotel A', 'status' => 1],
            ['id' => 81, 'tenant_id' => 8, 'name' => 'Hotel B', 'status' => 1],
        ]);
    }

    public function testOpenReturnsOnlyProtectedRelativeViewerAndKeepsTicketServerSide(): void
    {
        $gatewayCalls = [];
        $viewer = new CloudBrowserViewerAuthorizationService();
        $service = new CloudBrowserLoginGatewayService(
            new CloudBrowserProfileService(),
            $viewer,
            static function (string $path, array $body, ?string $controlToken) use (&$gatewayCalls): array {
                $gatewayCalls[] = compact('path', 'body', 'controlToken');
                return ['status' => 201, 'body' => [
                    'status' => 'awaiting_login',
                    'profile_id' => $body['profile_id'],
                    'session_id' => $body['session_id'],
                    'expires_at' => date('Y-m-d H:i:s', time() + 900),
                    'viewer_url' => 'http://127.0.0.1:6080/secret-local-address',
                    'browser_started' => true,
                ]];
            }
        );

        $result = $service->open(8, 80, 7, 'ctrip');
        self::assertTrue($result['browser_started']);
        self::assertStringStartsWith('/cloud-browser-viewer/', (string)$result['viewer_url']);
        self::assertStringNotContainsString('127.0.0.1', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('ticket', $result);
        self::assertArrayNotHasKey('control_token', $result);
        self::assertSame('/v1/login/open', $gatewayCalls[0]['path']);
        self::assertNull($gatewayCalls[0]['controlToken']);
        self::assertNotEmpty($gatewayCalls[0]['body']['ticket']);

        $authorized = $viewer->authorize((string)$result['_viewer_token']);
        self::assertSame(8, $authorized['tenant_id']);
        self::assertSame(80, $authorized['hotel_id']);
        self::assertSame('ctrip', $authorized['platform']);
        self::assertSame($result['profile_id'], $authorized['profile_id']);
        self::assertSame($result['session_id'], $authorized['session_id']);
    }

    public function testCompleteRequiresExactViewerScopeAndNeverReturnsControlToken(): void
    {
        $gatewayCalls = [];
        $viewer = new CloudBrowserViewerAuthorizationService();
        $service = new CloudBrowserLoginGatewayService(
            new CloudBrowserProfileService(),
            $viewer,
            static function (string $path, array $body, ?string $controlToken) use (&$gatewayCalls): array {
                $gatewayCalls[] = compact('path', 'body', 'controlToken');
                if ($path === '/v1/login/open') {
                    return ['status' => 201, 'body' => [
                        'status' => 'awaiting_login',
                        'profile_id' => $body['profile_id'],
                        'session_id' => $body['session_id'],
                        'expires_at' => date('Y-m-d H:i:s', time() + 900),
                        'browser_started' => true,
                    ]];
                }
                return ['status' => 200, 'body' => [
                    'status' => 'ready_to_collect',
                    'profile_id' => $body['profile_id'],
                    'receipt_id' => 'cbr_test_receipt',
                    'browser_started' => false,
                ]];
            },
            static fn(): string => str_repeat('t', 48)
        );
        $opened = $service->open(8, 80, 7, 'meituan');

        try {
            $service->complete(
                8,
                81,
                7,
                'meituan',
                (string)$opened['profile_id'],
                (string)$opened['session_id'],
                (string)$opened['_viewer_token']
            );
            self::fail('a viewer token must not be usable for another hotel');
        } catch (\RuntimeException $error) {
            self::assertSame('cloud_browser_viewer_scope_mismatch', $error->getMessage());
        }

        $completed = $service->complete(
            8,
            80,
            7,
            'meituan',
            (string)$opened['profile_id'],
            (string)$opened['session_id'],
            (string)$opened['_viewer_token']
        );
        self::assertSame('ready_to_collect', $completed['status']);
        self::assertFalse($completed['browser_started']);
        self::assertArrayNotHasKey('ticket', $completed);
        self::assertArrayNotHasKey('control_token', $completed);
        self::assertSame('/v1/login/complete', $gatewayCalls[1]['path']);
        self::assertSame(str_repeat('t', 48), $gatewayCalls[1]['controlToken']);

        $this->expectException(\RuntimeException::class);
        $viewer->authorize((string)$opened['_viewer_token']);
    }

    public function testFailedGatewayOpenReportsCapacityBusyWithoutPretendingBrowserOpened(): void
    {
        $viewer = new CloudBrowserViewerAuthorizationService();
        $gatewayCalls = [];
        $service = new CloudBrowserLoginGatewayService(
            new CloudBrowserProfileService(),
            $viewer,
            static function (string $path, array $body, ?string $controlToken) use (&$gatewayCalls): array {
                $gatewayCalls[] = compact('path', 'body', 'controlToken');
                return $path === '/v1/login/cancel'
                    ? ['status' => 200, 'body' => [
                        'status' => 'no_active_login',
                        'cleanup_verified' => true,
                    ]]
                    : ['status' => 409, 'body' => [
                        'status' => 'failed',
                        'reason' => 'gateway_login_capacity_busy',
                    ]];
            },
            static fn(): string => str_repeat('t', 48)
        );
        try {
            $service->open(8, 80, 7, 'dingdandao');
            self::fail('busy gateway must fail closed');
        } catch (\RuntimeException $error) {
            self::assertSame('gateway_login_capacity_busy', $error->getMessage());
        }
        self::assertSame('cancelled', (string)Db::name('cloud_browser_login_sessions')->value('session_status'));
        self::assertSame(
            CloudBrowserProfileService::UNAUTHORIZED,
            (string)Db::name('cloud_browser_profiles')->value('authorization_status')
        );
        self::assertSame('/v1/login/cancel', $gatewayCalls[1]['path']);
        self::assertSame(str_repeat('t', 48), $gatewayCalls[1]['controlToken']);
    }

    public function testFailedGatewayOpenRestoresExistingReadyProfileAndDoesNotSupersedeAnotherIssuedSession(): void
    {
        $profiles = new CloudBrowserProfileService();
        $entry = $profiles->requestLoginEntry(80, 7, 'ctrip');
        $expiresAt = date('Y-m-d H:i:s', time() + 86400);
        $ready = $profiles->completeGatewayLogin(
            (string)$entry['profile']['profile_id'],
            (string)$entry['login_entry']['session_id'],
            (string)$entry['login_entry']['ticket'],
            $expiresAt
        );
        self::assertSame(CloudBrowserProfileService::READY_TO_COLLECT, $ready['authorization_status']);

        $service = new CloudBrowserLoginGatewayService(
            $profiles,
            new CloudBrowserViewerAuthorizationService(),
            static fn(string $path, array $body): array => $path === '/v1/login/cancel'
                ? ['status' => 200, 'body' => [
                    'status' => 'no_active_login',
                    'cleanup_verified' => true,
                ]]
                : ['status' => 409, 'body' => [
                    'status' => 'failed',
                    'reason' => 'gateway_login_capacity_busy',
                ]],
            static fn(): string => str_repeat('t', 48)
        );
        try {
            $service->open(8, 80, 7, 'ctrip');
            self::fail('busy gateway must roll back the newly issued relogin session');
        } catch (\RuntimeException $error) {
            self::assertSame('gateway_login_capacity_busy', $error->getMessage());
        }

        $profile = Db::name('cloud_browser_profiles')
            ->where('profile_public_id', (string)$entry['profile']['profile_id'])
            ->find();
        self::assertSame(CloudBrowserProfileService::READY_TO_COLLECT, $profile['authorization_status']);
        self::assertSame($expiresAt, $profile['session_expires_at']);
        $statuses = Db::name('cloud_browser_login_sessions')->order('id')->column('session_status');
        self::assertSame(['verified', 'cancelled'], $statuses);
    }

    public function testLostOpenResponseCancelsExactGatewaySessionBeforeDatabaseRollback(): void
    {
        $events = [];
        $service = new CloudBrowserLoginGatewayService(
            new CloudBrowserProfileService(),
            new CloudBrowserViewerAuthorizationService(),
            static function (string $path, array $body) use (&$events): array {
                $events[] = $path;
                if ($path === '/v1/login/open') {
                    throw new \RuntimeException('cloud_browser_gateway_unavailable');
                }
                return ['status' => 200, 'body' => [
                    'status' => 'cancelled',
                    'cleanup_verified' => true,
                ]];
            },
            static fn(): string => str_repeat('t', 48)
        );

        try {
            $service->open(8, 80, 7, 'meituan');
            self::fail('a lost open response must fail only after exact gateway cleanup');
        } catch (\RuntimeException $error) {
            self::assertSame('cloud_browser_gateway_unavailable', $error->getMessage());
        }
        self::assertSame(['/v1/login/open', '/v1/login/cancel'], $events);
        self::assertSame('cancelled', Db::name('cloud_browser_login_sessions')->value('session_status'));
        self::assertSame(CloudBrowserProfileService::UNAUTHORIZED, Db::name('cloud_browser_profiles')->value('authorization_status'));
    }

    public function testUnverifiedGatewayCleanupKeepsDatabaseIssuedAndFailsClosed(): void
    {
        $service = new CloudBrowserLoginGatewayService(
            new CloudBrowserProfileService(),
            new CloudBrowserViewerAuthorizationService(),
            static fn(string $path, array $body): array => $path === '/v1/login/cancel'
                ? ['status' => 200, 'body' => ['status' => 'cancelled', 'cleanup_verified' => false]]
                : ['status' => 409, 'body' => ['status' => 'failed', 'reason' => 'gateway_login_capacity_busy']],
            static fn(): string => str_repeat('t', 48)
        );
        try {
            $service->open(8, 80, 7, 'dingdandao');
            self::fail('database rollback must not run after unverified gateway cleanup');
        } catch (\RuntimeException $error) {
            self::assertSame('cloud_browser_login_gateway_cleanup_unverified', $error->getMessage());
        }
        self::assertSame('issued', Db::name('cloud_browser_login_sessions')->value('session_status'));
        self::assertSame(CloudBrowserProfileService::AWAITING_LOGIN, Db::name('cloud_browser_profiles')->value('authorization_status'));
    }

    public function testFailedCompleteCleansGatewayAndCancelsIssuedDatabaseSession(): void
    {
        $service = new CloudBrowserLoginGatewayService(
            new CloudBrowserProfileService(),
            new CloudBrowserViewerAuthorizationService(),
            static fn(string $path, array $body): array => match ($path) {
                '/v1/login/open' => ['status' => 201, 'body' => [
                    'status' => 'awaiting_login',
                    'profile_id' => $body['profile_id'],
                    'session_id' => $body['session_id'],
                    'browser_started' => true,
                ]],
                '/v1/login/cancel' => ['status' => 200, 'body' => [
                    'status' => 'no_active_login',
                    'profile_id' => $body['profile_id'],
                    'session_id' => $body['session_id'],
                    'platform' => $body['platform'],
                    'cleanup_verified' => true,
                ]],
                default => ['status' => 500, 'body' => [
                    'status' => 'failed',
                    'reason' => 'gateway_state_bridge_failed',
                ]],
            },
            static fn(): string => str_repeat('t', 48)
        );
        $opened = $service->open(8, 80, 7, 'ctrip');
        try {
            $service->complete(
                8,
                80,
                7,
                'ctrip',
                (string)$opened['profile_id'],
                (string)$opened['session_id'],
                (string)$opened['_viewer_token']
            );
            self::fail('a failed completion must reconcile the exact gateway and database session');
        } catch (\RuntimeException $error) {
            self::assertSame('gateway_state_bridge_failed', $error->getMessage());
        }
        self::assertSame('cancelled', Db::name('cloud_browser_login_sessions')->value('session_status'));
        self::assertSame(CloudBrowserProfileService::UNAUTHORIZED, Db::name('cloud_browser_profiles')->value('authorization_status'));
    }

    public function testLostCompleteResponsePreservesCommittedReadyProfileWithoutReopening(): void
    {
        $profiles = new CloudBrowserProfileService();
        $issued = [];
        $service = new CloudBrowserLoginGatewayService(
            $profiles,
            new CloudBrowserViewerAuthorizationService(),
            static function (string $path, array $body) use ($profiles, &$issued): array {
                if ($path === '/v1/login/open') {
                    $issued = $body;
                    return ['status' => 201, 'body' => [
                        'status' => 'awaiting_login',
                        'profile_id' => $body['profile_id'],
                        'session_id' => $body['session_id'],
                        'browser_started' => true,
                    ]];
                }
                if ($path === '/v1/login/complete') {
                    $profiles->completeGatewayLogin(
                        (string)$issued['profile_id'],
                        (string)$issued['session_id'],
                        (string)$issued['ticket'],
                        gmdate('Y-m-d\TH:i:s.000\Z', time() + 86400)
                    );
                    throw new \RuntimeException('cloud_browser_gateway_unavailable');
                }
                return ['status' => 200, 'body' => [
                    'status' => 'no_active_login',
                    'profile_id' => $body['profile_id'],
                    'session_id' => $body['session_id'],
                    'platform' => $body['platform'],
                    'cleanup_verified' => true,
                ]];
            },
            static fn(): string => str_repeat('t', 48)
        );
        $opened = $service->open(8, 80, 7, 'meituan');
        try {
            $service->complete(
                8,
                80,
                7,
                'meituan',
                (string)$opened['profile_id'],
                (string)$opened['session_id'],
                (string)$opened['_viewer_token']
            );
            self::fail('a lost response must be reported without rolling back committed Profile state');
        } catch (\RuntimeException $error) {
            self::assertSame('cloud_browser_login_complete_response_lost_profile_ready', $error->getMessage());
        }
        self::assertSame('verified', Db::name('cloud_browser_login_sessions')->value('session_status'));
        self::assertSame(CloudBrowserProfileService::READY_TO_COLLECT, Db::name('cloud_browser_profiles')->value('authorization_status'));
    }
}
