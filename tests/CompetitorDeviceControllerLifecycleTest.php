<?php
declare(strict_types=1);

namespace Tests;

use app\controller\admin\CompetitorDeviceController;
use app\model\CompetitorDevice;
use app\model\Role;
use app\model\User;
use app\service\CompetitorDeviceAuthService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;
use think\Request;
use think\Response;

final class CompetitorDeviceControllerLifecycleTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $databasePath = '';
    private static App $app;
    private CompetitorDeviceControllerHarness $controller;

    public static function setUpBeforeClass(): void
    {
        self::$app = new App(dirname(__DIR__));
        self::$app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'competitor_device_controller_' . getmypid() . '.sqlite';
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

        Db::execute('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT, display_name TEXT, permissions TEXT, level INTEGER NOT NULL, status INTEGER NOT NULL, create_time TEXT, update_time TEXT)');
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT, status INTEGER NOT NULL, owner_user_id INTEGER, created_by INTEGER, create_time TEXT, update_time TEXT)');
        Db::execute('CREATE TABLE users (id INTEGER PRIMARY KEY, tenant_id INTEGER, username TEXT, password TEXT, hotel_id INTEGER, role_id INTEGER, status INTEGER NOT NULL, create_time TEXT, update_time TEXT)');
        Db::execute('CREATE TABLE user_hotel_permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, user_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, can_view INTEGER, can_view_online_data INTEGER, can_fetch_ota INTEGER, can_fetch_online_data INTEGER, status TEXT, expires_at TEXT, create_time TEXT, update_time TEXT)');
        Db::execute('CREATE TABLE competitor_device (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, user_id INTEGER, store_id INTEGER, device_id TEXT NOT NULL, name TEXT, platform TEXT NOT NULL, token_hash TEXT, token_hint TEXT, token_version INTEGER NOT NULL, status INTEGER NOT NULL, last_time TEXT, revoked_at TEXT, create_time TEXT, update_time TEXT, UNIQUE(device_id, platform, store_id))');
        Db::execute('CREATE TABLE operation_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, user_id INTEGER, hotel_id INTEGER, module TEXT, action TEXT, description TEXT, error_info TEXT, extra_data TEXT, ip TEXT, user_agent TEXT, create_time TEXT)');

        Db::name('roles')->insertAll([
            ['id' => 1, 'name' => 'admin', 'display_name' => 'Admin', 'permissions' => '["all"]', 'level' => 1, 'status' => 1],
            ['id' => 2, 'name' => 'hotel_operator', 'display_name' => 'Operator', 'permissions' => '["ota.collect"]', 'level' => 2, 'status' => 1],
        ]);
        Db::name('hotels')->insertAll([
            ['id' => 80, 'tenant_id' => 12, 'name' => 'Hotel A', 'status' => 1],
            ['id' => 81, 'tenant_id' => 12, 'name' => 'Hotel B', 'status' => 1],
        ]);
        Db::name('users')->insert([
            'id' => 7,
            'tenant_id' => 12,
            'username' => 'collector',
            'password' => 'fixture',
            'hotel_id' => 80,
            'role_id' => 2,
            'status' => 1,
        ]);
        Db::name('user_hotel_permissions')->insertAll([
            ['tenant_id' => 12, 'user_id' => 7, 'hotel_id' => 80, 'can_view' => 1, 'can_view_online_data' => 1, 'can_fetch_ota' => 1, 'can_fetch_online_data' => 1, 'status' => 'active'],
            ['tenant_id' => 12, 'user_id' => 7, 'hotel_id' => 81, 'can_view' => 1, 'can_view_online_data' => 1, 'can_fetch_ota' => 1, 'can_fetch_online_data' => 1, 'status' => 'active'],
        ]);

        $admin = new User();
        $admin->id = 9001;
        $admin->role_id = Role::SUPER_ADMIN;
        $admin->tenant_id = 0;
        $this->controller = new CompetitorDeviceControllerHarness(self::$app);
        $this->controller->actAs($admin);
    }

    public function testCredentialLifecycleInvalidatesEveryPreviousToken(): void
    {
        $created = $this->createBinding();
        $bindingId = (int)$created['data']['id'];
        $token1 = (string)$created['data']['device_token'];
        self::assertSame(1, $created['data']['token_version']);
        self::assertNotNull($this->authorized('scope-device-1', 'xc', 80, $token1));

        $rotated = $this->invoke('POST', ['expected_token_version' => 1], fn(): Response => $this->controller->rotateToken($bindingId));
        $token2 = (string)$rotated['data']['device_token'];
        self::assertSame(2, $rotated['data']['token_version']);
        self::assertNull($this->authorized('scope-device-1', 'xc', 80, $token1));
        self::assertNotNull($this->authorized('scope-device-1', 'xc', 80, $token2));

        $disabled = $this->invoke('PUT', [
            'status' => 0,
            'expected_token_version' => 2,
        ], fn(): Response => $this->controller->updateStatus($bindingId));
        self::assertSame(0, $disabled['data']['status']);
        self::assertSame(3, $disabled['data']['token_version']);
        self::assertSame('', $disabled['data']['token_hint']);
        self::assertNull($this->authorized('scope-device-1', 'xc', 80, $token2));

        $prepared = $this->invoke('POST', ['expected_token_version' => 3], fn(): Response => $this->controller->rotateToken($bindingId));
        $token3 = (string)$prepared['data']['device_token'];
        self::assertSame(0, $prepared['data']['status']);
        self::assertSame(4, $prepared['data']['token_version']);
        self::assertNull($this->authorized('scope-device-1', 'xc', 80, $token3));

        $enabled = $this->invoke('PUT', [
            'status' => 1,
            'expected_token_version' => 4,
        ], fn(): Response => $this->controller->updateStatus($bindingId));
        self::assertSame(1, $enabled['data']['status']);
        self::assertNotNull($this->authorized('scope-device-1', 'xc', 80, $token3));

        $rebound = $this->invoke('PUT', [
            'name' => 'Rebound Device',
            'platform' => 'mt',
            'store_id' => 81,
            'user_id' => 7,
            'expected_token_version' => 4,
        ], fn(): Response => $this->controller->rebind($bindingId));
        $token4 = (string)$rebound['data']['device_token'];
        self::assertSame(5, $rebound['data']['token_version']);
        self::assertSame(81, $rebound['data']['store_id']);
        self::assertSame('mt', $rebound['data']['platform']);
        self::assertNull($this->authorized('scope-device-1', 'xc', 80, $token3));
        self::assertNotNull($this->authorized('scope-device-1', 'mt', 81, $token4));
    }

    public function testStaleExpectedVersionCannotOverwriteWinningCredential(): void
    {
        $created = $this->createBinding();
        $bindingId = (int)$created['data']['id'];
        $winner = $this->invoke('POST', ['expected_token_version' => 1], fn(): Response => $this->controller->rotateToken($bindingId));
        $winnerToken = (string)$winner['data']['device_token'];
        $snapshot = Db::name('competitor_device')->where('id', $bindingId)->find();

        $stale = $this->invoke('POST', ['expected_token_version' => 1], fn(): Response => $this->controller->rotateToken($bindingId));
        $after = Db::name('competitor_device')->where('id', $bindingId)->find();

        self::assertSame(409, $stale['code']);
        self::assertSame('binding_changed', $stale['data']['reason']);
        self::assertSame($snapshot['token_hash'], $after['token_hash']);
        self::assertSame(2, (int)$after['token_version']);
        self::assertNotNull($this->authorized('scope-device-1', 'xc', 80, $winnerToken));
    }

    public function testAuditFailureRollsBackCredentialMutation(): void
    {
        $created = $this->createBinding();
        $bindingId = (int)$created['data']['id'];
        $before = Db::name('competitor_device')->where('id', $bindingId)->find();
        Db::execute("CREATE TRIGGER fail_competitor_device_audit BEFORE INSERT ON operation_logs WHEN NEW.module = 'competitor_device' BEGIN SELECT RAISE(ABORT, 'forced competitor device audit failure'); END");

        try {
            $this->invoke('POST', ['expected_token_version' => 1], fn(): Response => $this->controller->rotateToken($bindingId));
            self::fail('Expected the audit failure to abort the credential mutation.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('forced competitor device audit failure', $exception->getMessage());
        }

        $after = Db::name('competitor_device')->where('id', $bindingId)->find();
        self::assertSame($before['token_hash'], $after['token_hash']);
        self::assertSame((int)$before['token_version'], (int)$after['token_version']);
        self::assertSame((int)$before['status'], (int)$after['status']);
    }

    public function testListAndOneTimeCredentialNeverExposeStoredHash(): void
    {
        $created = $this->createBinding();
        self::assertTrue($created['data']['token_visible_once']);
        self::assertArrayHasKey('device_token', $created['data']);
        self::assertArrayNotHasKey('token_hash', $created['data']);

        $listed = $this->invoke('GET', [], fn(): Response => $this->controller->index());
        self::assertCount(1, $listed['data']['list']);
        self::assertArrayNotHasKey('token_hash', $listed['data']['list'][0]);
        self::assertArrayNotHasKey('device_token', $listed['data']['list'][0]);
    }

    /** @return array<string, mixed> */
    private function createBinding(): array
    {
        return $this->invoke('POST', [
            'device_id' => 'scope-device-1',
            'name' => 'Scope Device',
            'platform' => 'xc',
            'store_id' => 80,
            'user_id' => 7,
        ], fn(): Response => $this->controller->create());
    }

    private function authorized(string $deviceId, string $platform, int $storeId, string $token): ?CompetitorDevice
    {
        return (new CompetitorDeviceAuthService())->findAuthorizedBinding($deviceId, $platform, $storeId, $token);
    }

    /**
     * @param array<string, mixed> $payload
     * @param callable(): Response $action
     * @return array<string, mixed>
     */
    private function invoke(string $method, array $payload, callable $action): array
    {
        $this->controller->useRequest($method, $payload);
        $response = $action();
        $decoded = json_decode((string)$response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}

final class CompetitorDeviceControllerHarness extends CompetitorDeviceController
{
    public function actAs(?User $user): void
    {
        $this->currentUser = $user;
    }

    /** @param array<string, mixed> $payload */
    public function useRequest(string $method, array $payload): void
    {
        $this->request = (new Request())
            ->setMethod($method)
            ->setUrl('/api/admin/competitor-devices')
            ->setBaseUrl('/api/admin/competitor-devices')
            ->setPathinfo('api/admin/competitor-devices')
            ->withPost($payload)
            ->withGet($payload)
            ->withHeader(['Accept' => 'application/json']);
    }
}
