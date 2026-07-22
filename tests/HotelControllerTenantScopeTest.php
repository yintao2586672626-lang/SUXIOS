<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Hotel as HotelController;
use app\model\Role;
use app\model\User;
use PHPUnit\Framework\TestCase;
use think\App;
use think\Response;
use think\facade\Config;
use think\facade\Db;

final class HotelControllerTenantScopeTest extends TestCase
{
    /** @var array<string, mixed> */
    private static array $originalDatabaseConfig = [];

    private static string $sqlitePath = '';

    private static App $app;

    public static function setUpBeforeClass(): void
    {
        self::$app = new App();
        self::$app->initialize();

        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hotel_controller_tenant_scope_' . getmypid() . '.sqlite';

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
        self::$app->request->user = null;
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }

        if (!empty(self::$originalDatabaseConfig)) {
            Config::set(self::$originalDatabaseConfig, 'database');
            Db::connect()->close();
            Db::connect(null, true);
        }

        if (self::$sqlitePath !== '' && is_file(self::$sqlitePath)) {
            @unlink(self::$sqlitePath);
        }
    }

    protected function setUp(): void
    {
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        if (is_file(self::$sqlitePath)) {
            @unlink(self::$sqlitePath);
        }
        Db::connect(null, true);
        self::$app->request->user = null;
        $this->createSchema();
        $this->seedHotels();
    }

    public function testTenantlessSuperAdminCanListAndReadHotelsAcrossTenants(): void
    {
        $controller = $this->controller($this->superAdmin());

        $index = $this->json($controller->index());
        $read = $this->json($controller->read(22));

        self::assertSame(200, $index['code']);
        self::assertSame([22, 11], array_values(array_map(
            'intval',
            array_column($index['data']['list'], 'id')
        )));
        self::assertSame(200, $read['code']);
        self::assertSame(22, (int)$read['data']['id']);
        self::assertSame(202, (int)$read['data']['tenant_id']);
    }

    public function testTenantlessSuperAdminUpdateUsesUnscopedQueryAndPersistsReadback(): void
    {
        $controller = $this->controller($this->superAdmin(), [
            'name' => 'Tenant B updated hotel',
            'code' => 'B-UPDATED',
            'status' => 1,
            'ota_channel_strategy' => 'dual',
        ]);

        $updated = $this->json($controller->update(22));

        self::assertSame(200, $updated['code']);
        self::assertSame('Tenant B updated hotel', $updated['data']['name']);
        self::assertSame('Tenant B updated hotel', Db::name('hotels')->where('id', 22)->value('name'));
        self::assertSame('B-UPDATED', Db::name('hotels')->where('id', 22)->value('code'));
        self::assertSame('dual', Db::name('hotels')->where('id', 22)->value('ota_channel_strategy'));
        self::assertSame(202, (int)Db::name('hotels')->where('id', 22)->value('tenant_id'));
        self::assertSame(202, (int)Db::name('operation_logs')->where('action', 'update')->value('tenant_id'));
    }

    public function testTenantlessSuperAdminDeleteCanReachCrossTenantHotelPreview(): void
    {
        $controller = $this->controller($this->superAdmin());

        $preview = $this->json($controller->delete(22));

        self::assertSame(409, $preview['code']);
        self::assertStringContainsString('永久清除', (string)$preview['message']);
        self::assertTrue((bool)$preview['data']['can_force_delete']);
        self::assertSame(1, Db::name('hotels')->where('id', 22)->count());
    }

    public function testTenantBoundSuperAdminDeleteAuditUsesDeletedHotelTenant(): void
    {
        $controller = $this->controller($this->superAdmin(777), [
            'force' => true,
            'confirmation_name' => 'Tenant B hotel',
        ]);

        $deleted = $this->json($controller->delete(22));

        self::assertSame(200, $deleted['code']);
        self::assertSame(0, Db::name('hotels')->where('id', 22)->count());
        $audit = Db::name('operation_logs')->where('action', 'delete')->find();
        self::assertIsArray($audit);
        self::assertSame(202, (int)$audit['tenant_id']);
        self::assertSame(22, (int)$audit['hotel_id']);
    }

    public function testTenantlessSuperAdminDeleteAuditAlsoUsesDeletedHotelTenant(): void
    {
        $controller = $this->controller($this->superAdmin(), [
            'force' => true,
            'confirmation_name' => 'Tenant B hotel',
        ]);

        $deleted = $this->json($controller->delete(22));

        self::assertSame(200, $deleted['code']);
        self::assertSame(202, (int)Db::name('operation_logs')->where('action', 'delete')->value('tenant_id'));
    }

    public function testNormalTenantKeepsDefaultScopeEvenWithForgedCrossTenantPermission(): void
    {
        $user = new HotelControllerTenantUser();
        $user->id = 9002;
        $user->tenant_id = 101;
        $user->hotel_id = 11;
        $user->permittedHotelIds = [11, 22];
        $controller = $this->controller($user);

        $index = $this->json($controller->index());
        $crossTenantRead = $this->json($controller->read(22));
        $controller->replacePayload([
            'name' => 'Forbidden update',
            'code' => 'FORBIDDEN',
            'status' => 1,
        ]);
        $crossTenantUpdate = $this->json($controller->update(22));

        self::assertSame([11], array_values(array_map(
            'intval',
            array_column($index['data']['list'], 'id')
        )));
        self::assertSame(400, $crossTenantRead['code']);
        self::assertSame(400, $crossTenantUpdate['code']);
        self::assertSame('Tenant B hotel', Db::name('hotels')->where('id', 22)->value('name'));
    }

    /** @param array<string, mixed> $payload */
    private function controller(User $user, array $payload = []): HotelControllerTenantScopeHarness
    {
        $controller = new HotelControllerTenantScopeHarness(self::$app, $payload);
        $controller->useUser($user);
        return $controller;
    }

    private function superAdmin(int $tenantId = 0): User
    {
        $user = new User();
        $user->id = 9001;
        $user->role_id = Role::SUPER_ADMIN;
        $user->tenant_id = $tenantId;
        return $user;
    }

    private function createSchema(): void
    {
        Db::execute('CREATE TABLE tenants (
            id INTEGER PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'active\',
            plan_id INTEGER,
            created_at DATETIME,
            updated_at DATETIME
        )');
        Db::execute('CREATE TABLE hotels (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            name VARCHAR(100) NOT NULL,
            code VARCHAR(50),
            address VARCHAR(255),
            contact_person VARCHAR(50),
            contact_phone VARCHAR(20),
            description TEXT,
            status INTEGER NOT NULL DEFAULT 1,
            ota_channel_strategy VARCHAR(20) NOT NULL DEFAULT \'none\',
            owner_user_id INTEGER NOT NULL DEFAULT 0,
            created_by INTEGER NOT NULL DEFAULT 0,
            create_time DATETIME,
            update_time DATETIME
        )');
        Db::execute('CREATE TABLE operation_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER,
            user_id INTEGER,
            hotel_id INTEGER,
            module VARCHAR(50),
            action VARCHAR(50),
            description TEXT,
            error_info TEXT,
            extra_data TEXT,
            ip VARCHAR(50),
            user_agent TEXT,
            create_time DATETIME
        )');
    }

    private function seedHotels(): void
    {
        Db::name('tenants')->insertAll([
            ['id' => 101, 'name' => 'Tenant A', 'status' => 'active'],
            ['id' => 202, 'name' => 'Tenant B', 'status' => 'active'],
        ]);
        Db::name('hotels')->insertAll([
            [
                'id' => 11,
                'tenant_id' => 101,
                'name' => 'Tenant A hotel',
                'code' => 'A-11',
                'status' => 1,
                'ota_channel_strategy' => 'ctrip_only',
                'owner_user_id' => 9002,
                'created_by' => 9002,
            ],
            [
                'id' => 22,
                'tenant_id' => 202,
                'name' => 'Tenant B hotel',
                'code' => 'B-22',
                'status' => 1,
                'ota_channel_strategy' => 'meituan_only',
                'owner_user_id' => 9003,
                'created_by' => 9003,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        $decoded = json_decode($response->getContent(), true);
        self::assertIsArray($decoded);
        return $decoded;
    }
}

final class HotelControllerTenantUser extends User
{
    /** @var array<int, int> */
    public array $permittedHotelIds = [];

    public function isSuperAdmin(): bool
    {
        return false;
    }

    public function canManageOwnHotels(): bool
    {
        return true;
    }

    public function getPermittedHotelIds(): array
    {
        return $this->permittedHotelIds;
    }
}

final class HotelControllerTenantScopeHarness extends HotelController
{
    /** @param array<string, mixed> $payload */
    public function __construct(App $app, private array $payload = [])
    {
        parent::__construct($app);
    }

    public function useUser(User $user): void
    {
        $this->currentUser = $user;
        $this->request->user = $user;
    }

    /** @param array<string, mixed> $payload */
    public function replacePayload(array $payload): void
    {
        $this->payload = $payload;
    }

    protected function requestData(): array
    {
        return $this->payload;
    }
}
