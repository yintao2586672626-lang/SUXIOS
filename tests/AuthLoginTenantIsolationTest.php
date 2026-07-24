<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Auth as AuthController;
use app\model\User;
use app\service\LoginRateLimiter;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use think\App;
use think\facade\Cache;
use think\facade\Config;
use think\facade\Db;
use think\Request;

final class AuthLoginTenantIsolationTest extends TestCase
{
    private static App $app;
    private static array $originalDatabaseConfig = [];
    private static array $originalCacheConfig = [];
    private static string $databaseConnection = '';
    private static string $cacheStore = '';
    private static string $sqlitePath = '';
    private static string $cachePath = '';

    public static function setUpBeforeClass(): void
    {
        self::$app = new App(dirname(__DIR__));
        self::$app->initialize();
        $nonce = getmypid() . '_' . bin2hex(random_bytes(4));
        self::$databaseConnection = 'auth_login_tenant_' . $nonce;
        self::$cacheStore = 'auth_login_tenant_' . $nonce;
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::$databaseConnection . '.sqlite';
        self::$cachePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::$cacheStore . '_cache';

        self::$originalDatabaseConfig = Config::get('database');
        $database = self::$originalDatabaseConfig;
        $database['default'] = self::$databaseConnection;
        $database['connections'][self::$databaseConnection] = [
            'type' => 'sqlite',
            'database' => self::$sqlitePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($database, 'database');
        Db::connect(null, true);

        self::$originalCacheConfig = Config::get('cache');
        $cache = self::$originalCacheConfig;
        $cache['default'] = self::$cacheStore;
        $cache['stores'][self::$cacheStore] = [
            'type' => 'File',
            'path' => self::$cachePath,
            'prefix' => 'auth-login-tenant',
            'expire' => 0,
            'tag_prefix' => 'tag:',
            'serialize' => [],
        ];
        Config::set($cache, 'cache');
        app('cache')->forgetDriver(self::$cacheStore);

        self::createSchema();
    }

    public static function tearDownAfterClass(): void
    {
        Cache::clear();
        app('cache')->forgetDriver(self::$cacheStore);
        Config::set(self::$originalCacheConfig, 'cache');
        Db::connect()->close();
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$sqlitePath);
        self::removeDirectory(self::$cachePath);
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['operation_logs', 'login_logs', 'user_hotel_permissions', 'users', 'hotels', 'roles'] as $table) {
            Db::name($table)->delete(true);
        }
        Cache::clear();
        $this->seedFixture();
    }

    public function testNormalLoginBuildsSameTenantMultiHotelScopeBeforePublishingToken(): void
    {
        $response = $this->login('tenant_operator');
        $payload = $this->successPayload($response);
        $hotelIds = array_map('intval', array_column($payload['user']['permitted_hotels'], 'id'));

        self::assertSame([10, 11], $hotelIds);
        self::assertSame('Tenant A primary', $payload['user']['hotel_name']);
        self::assertSame(10, (int)$payload['context']['hotelId']);
        self::assertSame(101, (int)$payload['context']['tenantId']);
        self::assertSame(501, (int)(cache('token_' . $payload['token'])['user_id'] ?? 0));
        self::assertSame($payload['token'], cache('user_token_501'));

        $audit = Db::name('operation_logs')->where('action', 'login')->find();
        self::assertSame(101, (int)$audit['tenant_id']);
        self::assertSame(10, (int)$audit['hotel_id']);
    }

    public function testWrongTenantAndDisabledPrimaryHotelsNeverEnterLoginScope(): void
    {
        foreach ([
            ['username' => 'wrong_tenant_hotel', 'user_id' => 502],
            ['username' => 'disabled_hotel', 'user_id' => 503],
        ] as $case) {
            $payload = $this->successPayload($this->login($case['username']));
            self::assertSame([], $payload['user']['permitted_hotels'], $case['username']);
            self::assertNull($payload['user']['hotel_id'], $case['username']);
            self::assertSame('', $payload['user']['hotel_name'], $case['username']);
            self::assertNull($payload['context']['hotelId'], $case['username']);
            self::assertNull($payload['context']['tenantId'], $case['username']);
            self::assertSame($payload['token'], cache('user_token_' . $case['user_id']), $case['username']);

            $info = $this->successPayload($this->info($case['user_id']));
            self::assertNull($info['hotel_id'], $case['username'] . ' info');
            self::assertNull($info['hotel'], $case['username'] . ' info');
            self::assertSame([], $info['permitted_hotels'], $case['username'] . ' info');
            self::assertNull($info['context']['hotelId'], $case['username'] . ' info');
        }
    }

    public function testSuperAdminLoginCanBuildCrossTenantHotelScope(): void
    {
        $payload = $this->successPayload($this->login('root_admin'));
        $hotelIds = array_map('intval', array_column($payload['user']['permitted_hotels'], 'id'));

        self::assertSame([10, 11, 20], $hotelIds);
        self::assertTrue($payload['user']['is_super_admin']);
        self::assertSame($payload['token'], cache('user_token_1'));
    }

    public function testMissingTenantReturnsExplicitRejectionBeforeAnyLoginStateIsUpdated(): void
    {
        $response = $this->login('missing_tenant');
        self::assertSame(403, $response->getCode());
        $payload = json_decode((string)$response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(403, $payload['code']);
        self::assertSame('tenant_context_missing', $payload['data']['reason'] ?? null);
        self::assertSame('账号门店租户绑定异常，请联系管理员重新分配门店', $payload['message']);

        self::assertNull(cache('user_token_504'));
        self::assertSame(0, Db::name('operation_logs')->where('user_id', 504)->count());
        self::assertSame(0, Db::name('login_logs')->where('user_id', 504)->where('status', 'success')->count());
        self::assertSame(
            '账号缺少有效租户绑定',
            Db::name('login_logs')->where('user_id', 504)->where('status', 'failed')->value('message')
        );
        self::assertNull(Db::name('users')->where('id', 504)->value('last_login_time'));
        self::assertSame(0, (int)Db::name('users')->where('id', 504)->value('login_count'));
    }

    public function testOwnerWithDedicatedTenantCanLoginBeforeCreatingFirstHotel(): void
    {
        $payload = $this->successPayload($this->login('owner_without_hotel'));

        self::assertSame([], $payload['user']['permitted_hotels']);
        self::assertNull($payload['user']['hotel_id']);
        self::assertNull($payload['context']['hotelId']);
        self::assertNull($payload['context']['tenantId']);
        self::assertTrue($payload['user']['permissions']['can_manage_own_hotels']);
        self::assertSame($payload['token'], cache('user_token_505'));
        self::assertSame(303, (int)Db::name('operation_logs')->where('user_id', 505)->value('tenant_id'));
        self::assertSame(1, (int)Db::name('users')->where('id', 505)->value('login_count'));
    }

    public function testLoginCachePublicationIsAtomicAndNeverAuditsPartialSessions(): void
    {
        foreach ([[true, false], [false, false]] as $writeResults) {
            Cache::clear();
            Db::name('operation_logs')->delete(true);
            Db::name('login_logs')->delete(true);

            [$response, $harness] = $this->loginWithCacheWriteResults('tenant_operator', $writeResults);
            self::assertSame(503, $response->getCode(), json_encode($writeResults));
            $content = (string)$response->getContent();
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(503, $decoded['code']);
            self::assertSame('login_session_store_failed', $decoded['data']['reason'] ?? null);
            self::assertStringNotContainsString('"token"', $content);

            self::assertCount(2, $harness->attemptedLoginCacheKeys);
            foreach ($harness->attemptedLoginCacheKeys as $key) {
                self::assertNull(cache($key), $key);
            }
            self::assertNull(cache('user_token_501'));
            self::assertSame(0, Db::name('operation_logs')->where('user_id', 501)->where('action', 'login')->count());
            self::assertSame(0, Db::name('login_logs')->where('user_id', 501)->where('status', 'success')->count());
        }
    }

    private function login(string $username): \think\Response
    {
        return $this->loginWithCacheWriteResults($username, null)[0];
    }

    /** @param list<bool>|null $writeResults @return array{0:\think\Response,1:AuthLoginTenantHarness} */
    private function loginWithCacheWriteResults(string $username, ?array $writeResults): array
    {
        $request = new class extends Request {
            public function isCli(): bool
            {
                return false;
            }
        };
        $request->setMethod('POST')
            ->setUrl('/api/auth/login')
            ->setBaseUrl('/api/auth/login')
            ->setPathinfo('api/auth/login')
            ->withPost(['username' => $username, 'password' => 'Strong123!'])
            ->withHeader(['Accept' => 'application/json', 'User-Agent' => 'tenant-login-test']);
        self::$app->instance('request', $request);

        $harness = new AuthLoginTenantHarness(self::$app);
        if ($writeResults !== null) {
            $harness->injectLoginCacheWriteResults($writeResults);
        }

        return [$harness->login(), $harness];
    }

    private function info(int $userId): \think\Response
    {
        $user = User::find($userId);
        self::assertInstanceOf(User::class, $user);

        $request = new class extends Request {
            public function isCli(): bool
            {
                return false;
            }
        };
        $request->setMethod('GET')
            ->setUrl('/api/auth/info')
            ->setBaseUrl('/api/auth/info')
            ->setPathinfo('api/auth/info')
            ->withHeader(['Accept' => 'application/json']);
        $request->user = $user;
        self::$app->instance('request', $request);

        return (new AuthLoginTenantHarness(self::$app))->info();
    }

    /** @return array<string, mixed> */
    private function successPayload(\think\Response $response): array
    {
        self::assertSame(200, $response->getCode());
        $decoded = json_decode((string)$response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(200, $decoded['code']);
        return $decoded['data'];
    }

    private function seedFixture(): void
    {
        Db::name('roles')->insertAll([
            ['id' => 1, 'name' => 'admin', 'display_name' => 'Super admin', 'level' => 1, 'permissions' => '["all"]', 'status' => 1],
            ['id' => 3, 'name' => 'normal_user', 'display_name' => 'Normal user', 'level' => 3, 'permissions' => '["dashboard.view","hotel.view"]', 'status' => 1],
            ['id' => 8, 'name' => 'VIPUser', 'display_name' => 'Owner beta user', 'level' => 2, 'permissions' => '["hotel.create","hotel.view"]', 'status' => 1],
        ]);
        Db::name('hotels')->insertAll([
            ['id' => 10, 'tenant_id' => 101, 'name' => 'Tenant A primary', 'status' => 1],
            ['id' => 11, 'tenant_id' => 101, 'name' => 'Tenant A secondary', 'status' => 1],
            ['id' => 12, 'tenant_id' => 101, 'name' => 'Tenant A disabled', 'status' => 0],
            ['id' => 20, 'tenant_id' => 202, 'name' => 'Tenant B hotel', 'status' => 1],
        ]);
        Db::name('users')->insertAll([
            $this->userRow(1, 0, 'root_admin', 1, null),
            $this->userRow(501, 101, 'tenant_operator', 3, 10),
            $this->userRow(502, 101, 'wrong_tenant_hotel', 3, 20),
            $this->userRow(503, 101, 'disabled_hotel', 3, 12),
            $this->userRow(504, 0, 'missing_tenant', 3, 10),
            $this->userRow(505, 303, 'owner_without_hotel', 8, null),
        ]);
        Db::name('user_hotel_permissions')->insertAll([
            ['tenant_id' => 101, 'user_id' => 501, 'hotel_id' => 11, 'can_view' => 1, 'status' => 'active'],
            ['tenant_id' => 202, 'user_id' => 501, 'hotel_id' => 20, 'can_view' => 1, 'status' => 'active'],
        ]);
    }

    /** @return array<string, mixed> */
    private function userRow(int $id, int $tenantId, string $username, int $roleId, ?int $hotelId): array
    {
        return [
            'id' => $id,
            'tenant_id' => $tenantId,
            'username' => $username,
            'realname' => $username,
            'password' => password_hash('Strong123!', PASSWORD_DEFAULT),
            'role_id' => $roleId,
            'hotel_id' => $hotelId,
            'status' => 1,
            'login_count' => 0,
        ];
    }

    private static function createSchema(): void
    {
        Db::execute('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT, display_name TEXT, level INTEGER, permissions TEXT, status INTEGER, create_time TEXT, update_time TEXT)');
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT, status INTEGER, owner_user_id INTEGER, created_by INTEGER, create_time TEXT, update_time TEXT)');
        Db::execute('CREATE TABLE users (id INTEGER PRIMARY KEY, tenant_id INTEGER, username TEXT, realname TEXT, password TEXT, role_id INTEGER, hotel_id INTEGER, status INTEGER, login_count INTEGER, last_login_time TEXT, last_login_ip TEXT, create_time TEXT, update_time TEXT)');
        Db::execute('CREATE TABLE user_hotel_permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, user_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, can_view INTEGER, status TEXT, expires_at TEXT)');
        Db::execute('CREATE TABLE login_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, user_id INTEGER, username TEXT, action TEXT, status TEXT, message TEXT, ip_address TEXT, user_agent TEXT, client_info TEXT, created_at TEXT)');
        Db::execute('CREATE TABLE operation_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, user_id INTEGER, hotel_id INTEGER, module TEXT, action TEXT, description TEXT, error_info TEXT, extra_data TEXT, ip TEXT, user_agent TEXT, create_time TEXT)');
    }

    private static function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}

final class AuthLoginTenantHarness extends AuthController
{
    /** @var array<string, mixed> */
    private array $rateLimitStore = [];

    /** @var list<bool>|null */
    private ?array $loginCacheWriteResults = null;

    /** @var list<string> */
    public array $attemptedLoginCacheKeys = [];

    /** @param list<bool> $writeResults */
    public function injectLoginCacheWriteResults(array $writeResults): void
    {
        $this->loginCacheWriteResults = array_values($writeResults);
    }

    protected function writeLoginCacheValue(string $key, mixed $value, int $ttl): bool
    {
        if ($this->loginCacheWriteResults === null) {
            return parent::writeLoginCacheValue($key, $value, $ttl);
        }

        $this->attemptedLoginCacheKeys[] = $key;
        $shouldSucceed = array_shift($this->loginCacheWriteResults) ?? false;
        return $shouldSucceed
            ? parent::writeLoginCacheValue($key, $value, $ttl)
            : false;
    }

    protected function makeLoginRateLimiter(): LoginRateLimiter
    {
        return new LoginRateLimiter(
            fn(string $key): mixed => $this->rateLimitStore[$key] ?? null,
            function (string $key, int $count, int $ttl): void {
                $this->rateLimitStore[$key] = $count;
            },
            function (string $key): void {
                unset($this->rateLimitStore[$key]);
            },
            static fn(): int => 1_750_000_000
        );
    }
}
