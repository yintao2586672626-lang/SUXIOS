<?php
declare(strict_types=1);

namespace Tests;

use app\controller\CompetitorApi;
use app\service\CompetitorDeviceAuthService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;
use think\Request;
use think\Response;

final class CompetitorPublicTenantContextTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';
    private static App $app;

    public static function setUpBeforeClass(): void
    {
        self::$app = new App(dirname(__DIR__));
        self::$app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'competitor_public_tenant_' . getmypid() . '.sqlite';

        $config = self::$originalDatabaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$sqlitePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($config, 'database');
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$sqlitePath);
    }

    protected function setUp(): void
    {
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        @unlink(self::$sqlitePath);
        Db::connect(null, true);

        $this->createSchema();
        $this->seedAuthorizedDeviceScope();
        $pdo = Db::connect()->getPdo();
        if (method_exists($pdo, 'sqliteCreateFunction')) {
            $pdo->sqliteCreateFunction(
                'regexp',
                static fn(string $pattern, string $value): int => preg_match('/' . str_replace('/', '\\/', $pattern) . '/', $value) === 1 ? 1 : 0,
                2
            );
        }

        $this->clearRateLimitCache();
    }

    protected function tearDown(): void
    {
        $this->clearRateLimitCache();
    }

    public function testAnonymousTaskAndReportValidationKeepOriginalErrorsAndGlobalAudits(): void
    {
        $task = $this->invokeTask([]);
        self::assertSame(400, $task->getCode());

        $report = $this->invokeReport([]);
        self::assertSame(400, $report->getCode());

        $rows = Db::name('operation_logs')->order('id', 'asc')->select()->toArray();
        self::assertSame(['task_denied', 'report_denied'], array_column($rows, 'action'));
        self::assertSame([null, null], array_column($rows, 'tenant_id'));
    }

    public function testAnonymousTaskRateLimitKeeps429AndWritesGlobalAudit(): void
    {
        $ipHash = substr(sha1('127.0.0.1'), 0, 16);
        $bucket = (int)floor(time() / 60);
        foreach ([$bucket, $bucket + 1] as $candidateBucket) {
            cache('competitor_api_rate_task_' . $ipHash . '_' . $candidateBucket, 30, 65);
        }

        $response = $this->invokeTask([
            'device_id' => 'rate-limited-device',
            'platform' => 'xc',
            'store_id' => 80,
        ]);

        self::assertSame(429, $response->getCode());
        $audit = Db::name('operation_logs')->where('action', 'external_rate_limited')->find();
        self::assertNotNull($audit);
        self::assertNull($audit['tenant_id']);
    }

    public function testVerifiedDeviceTaskAndReportUseBoundTenantAndRejectAnotherStore(): void
    {
        $credential = (new CompetitorDeviceAuthService())->issueCredential();
        Db::name('competitor_device')->insert([
            'tenant_id' => 12,
            'user_id' => 7,
            'store_id' => 80,
            'device_id' => 'public-device',
            'name' => 'Public Device',
            'platform' => 'xc',
            'token_hash' => $credential['hash'],
            'token_hint' => $credential['hint'],
            'token_version' => 1,
            'status' => 1,
            'revoked_at' => null,
        ]);

        $task = $this->invokeTask([
            'device_id' => 'public-device',
            'platform' => 'xc',
            'store_id' => 80,
        ], ['X-Task-Token' => $credential['token']]);
        self::assertSame(200, $task->getCode());
        self::assertSame(12, (int)Db::name('operation_logs')->where('action', 'task')->value('tenant_id'));

        $report = $this->invokeReport([
            'device_id' => 'public-device',
            'platform' => 'xc',
            'store_id' => 80,
            'hotel_id' => 999,
            'city' => 'Xi An',
        ], ['X-Report-Token' => $credential['token']]);
        self::assertSame(403, $report->getCode());
        self::assertSame(12, (int)Db::name('operation_logs')
            ->where('action', 'report_denied')
            ->order('id', 'desc')
            ->value('tenant_id'));

        $crossStore = $this->invokeTask([
            'device_id' => 'public-device',
            'platform' => 'xc',
            'store_id' => 81,
        ], ['X-Task-Token' => $credential['token']]);
        self::assertSame(403, $crossStore->getCode());
    }

    private function invokeTask(array $post, array $headers = []): Response
    {
        $request = $this->httpRequest($post, $headers);
        return (new CompetitorApi(self::$app))->task();
    }

    private function invokeReport(array $post, array $headers = []): Response
    {
        $request = $this->httpRequest($post, $headers);
        return (new CompetitorApi(self::$app))->report();
    }

    private function httpRequest(array $post, array $headers): Request
    {
        $request = new class extends Request {
            public function isCli(): bool
            {
                return false;
            }
        };
        $request->withServer(['REMOTE_ADDR' => '127.0.0.1'])
            ->withPost($post)
            ->withHeader(array_merge(['Accept' => 'application/json'], $headers));
        self::$app->instance('request', $request);

        return $request;
    }

    private function createSchema(): void
    {
        Db::execute('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT, display_name TEXT, permissions TEXT, level INTEGER NOT NULL, status INTEGER NOT NULL)');
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT, status INTEGER NOT NULL, owner_user_id INTEGER, created_by INTEGER)');
        Db::execute('CREATE TABLE users (id INTEGER PRIMARY KEY, tenant_id INTEGER, username TEXT, password TEXT, hotel_id INTEGER, role_id INTEGER, status INTEGER NOT NULL)');
        Db::execute('CREATE TABLE user_hotel_permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, user_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, can_view INTEGER, can_view_online_data INTEGER, can_fetch_ota INTEGER, can_fetch_online_data INTEGER, status TEXT, expires_at TEXT)');
        Db::execute('CREATE TABLE competitor_device (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, user_id INTEGER, store_id INTEGER, device_id TEXT, name TEXT, platform TEXT, token_hash TEXT, token_hint TEXT, token_version INTEGER, status INTEGER, last_time TEXT, revoked_at TEXT, create_time TEXT)');
        Db::execute('CREATE TABLE competitor_hotel (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, store_id INTEGER, hotel_name TEXT, hotel_code TEXT, city TEXT, platform TEXT, status INTEGER, create_time TEXT)');
        Db::execute('CREATE TABLE competitor_price_log (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, store_id INTEGER, hotel_id INTEGER, platform TEXT, fetch_time TEXT)');
        Db::execute('CREATE TABLE operation_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NULL, user_id INTEGER NULL, hotel_id INTEGER NULL, module TEXT, action TEXT, description TEXT, error_info TEXT NULL, extra_data TEXT NULL, ip TEXT, user_agent TEXT, create_time TEXT)');
    }

    private function seedAuthorizedDeviceScope(): void
    {
        Db::name('roles')->insert([
            'id' => 4,
            'name' => 'hotel_operator',
            'display_name' => 'Hotel Operator',
            'permissions' => '["ota.collect"]',
            'level' => 2,
            'status' => 1,
        ]);
        Db::name('hotels')->insertAll([
            ['id' => 80, 'tenant_id' => 12, 'name' => 'Scope Hotel', 'status' => 1],
            ['id' => 81, 'tenant_id' => 13, 'name' => 'Other Tenant Hotel', 'status' => 1],
        ]);
        Db::name('users')->insert([
            'id' => 7,
            'tenant_id' => 12,
            'username' => 'operator',
            'password' => 'fixture',
            'hotel_id' => 80,
            'role_id' => 4,
            'status' => 1,
        ]);
        Db::name('user_hotel_permissions')->insert([
            'tenant_id' => 12,
            'user_id' => 7,
            'hotel_id' => 80,
            'can_view' => 1,
            'can_view_online_data' => 1,
            'can_fetch_ota' => 1,
            'can_fetch_online_data' => 1,
            'status' => 'active',
        ]);
    }

    private function clearRateLimitCache(): void
    {
        $ipHash = substr(sha1('127.0.0.1'), 0, 16);
        $bucket = (int)floor(time() / 60);
        foreach ([$bucket - 1, $bucket, $bucket + 1] as $candidateBucket) {
            cache('competitor_api_rate_task_' . $ipHash . '_' . $candidateBucket, null);
            cache('competitor_api_rate_report_' . $ipHash . '_' . $candidateBucket, null);
        }
    }
}
