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

final class HotelTenantCreationTest extends TestCase
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
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hotel_tenant_creation_' . getmypid() . '.sqlite';

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
        } catch (\Throwable $e) {
            // Test cleanup only; assertions have already completed.
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
        } catch (\Throwable $e) {
            // The first test has no open SQLite connection yet.
        }
        if (is_file(self::$sqlitePath)) {
            @unlink(self::$sqlitePath);
        }
        Db::connect(null, true);
        self::$app->request->user = null;
    }

    public function testTenantUserCanCreateSecondHotelInSameTenant(): void
    {
        $this->createSchema(true);
        $this->createPermissionTable();
        $firstHotelId = Db::name('hotels')->insertGetId([
            'tenant_id' => 101,
            'name' => 'Tenant A hotel 1',
            'status' => 1,
            'owner_user_id' => 9002,
            'created_by' => 9002,
        ]);
        $user = $this->tenantManager(9002, 101, $firstHotelId);

        $response = $this->createHotel('Tenant A hotel 2', $user);
        $payload = $this->json($response);
        $hotelId = (int)$payload['data']['id'];

        self::assertSame(200, $payload['code']);
        self::assertGreaterThan(0, $hotelId);
        self::assertNotSame($firstHotelId, $hotelId);
        self::assertSame(2, Db::name('hotels')->where('tenant_id', 101)->count());
        self::assertSame(101, (int)Db::name('hotels')->where('id', $hotelId)->value('tenant_id'));
        self::assertSame(101, (int)$payload['data']['tenant_id']);
        $permission = Db::name('user_hotel_permissions')->where('hotel_id', $hotelId)->find();
        self::assertIsArray($permission);
        self::assertSame(9002, (int)$permission['user_id']);
        self::assertSame(101, (int)$permission['tenant_id']);
        $audit = Db::name('operation_logs')->where('action', 'create')->find();
        self::assertIsArray($audit);
        self::assertSame($hotelId, (int)$audit['hotel_id']);
        self::assertSame(101, (int)$audit['tenant_id']);
    }

    public function testCreateFailsClosedWithoutTenantColumnAndRollsBackAllWrites(): void
    {
        $this->createSchema(false);

        $error = null;
        try {
            $this->createHotel('Unmigrated-schema hotel');
        } catch (\Throwable $exception) {
            $error = $exception;
        }

        self::assertNotNull($error, 'An unmigrated tenant schema must be rejected before runtime use');
        self::assertStringContainsString(
            'A positive tenant id or authoritative hotel mapping is required',
            $error->getMessage()
        );
        self::assertSame(0, Db::name('hotels')->count());
        self::assertSame(0, Db::name('operation_logs')->count());
    }

    public function testSuperAdminCanCreateHotelOnlyForExplicitExistingTenant(): void
    {
        $this->createSchema(true);
        $this->createPermissionTable();

        $payload = $this->json($this->createHotel('Tenant B hotel 1', null, ['tenant_id' => 202]));
        $hotelId = (int)$payload['data']['id'];

        self::assertSame(200, $payload['code']);
        self::assertSame(202, (int)Db::name('hotels')->where('id', $hotelId)->value('tenant_id'));
        self::assertSame(202, (int)$payload['data']['tenant_id']);
        self::assertSame(202, (int)Db::name('operation_logs')->where('action', 'create')->value('tenant_id'));
        self::assertSame(0, Db::name('user_hotel_permissions')->count());
    }

    public function testTenantUserCannotCreateHotelForAnotherTenantWithoutPartialWrites(): void
    {
        $this->createSchema(true);
        $this->createPermissionTable();
        $user = $this->tenantManager(9002, 101);

        $payload = $this->json($this->createHotel('Cross-tenant hotel', $user, ['tenant_id' => 202]));

        self::assertSame(403, $payload['code']);
        $this->assertNoCreateWrites();
    }

    public function testTenantUserWithoutExplicitUserTenantIsRejectedWithoutPartialWrites(): void
    {
        $this->createSchema(true);
        $this->createPermissionTable();
        $user = $this->tenantManager(9002, 0);

        $payload = $this->json($this->createHotel('Missing user tenant hotel', $user, ['tenant_id' => 101]));

        self::assertSame(422, $payload['code']);
        $this->assertNoCreateWrites();
    }

    public function testSuperAdminMissingTenantIsRejectedWithoutPartialWrites(): void
    {
        $this->createSchema(true);
        $this->createPermissionTable();

        $payload = $this->json($this->createHotel('Missing tenant hotel'));

        self::assertSame(422, $payload['code']);
        $this->assertNoCreateWrites();
    }

    public function testSuperAdminUnknownTenantIsRejectedWithoutPartialWrites(): void
    {
        $this->createSchema(true);
        $this->createPermissionTable();

        $payload = $this->json($this->createHotel('Unknown tenant hotel', null, ['tenant_id' => 999]));

        self::assertSame(422, $payload['code']);
        $this->assertNoCreateWrites();
    }

    public function testCreateRollsBackHotelPermissionAndLogWhenPermissionGrantFails(): void
    {
        $this->createSchema(true);
        $this->createRejectingPermissionTable();
        $user = $this->tenantManager(9002, 101);

        try {
            $this->createHotel('Atomic-create hotel', $user);
            self::fail('Permission insertion failure must abort hotel creation.');
        } catch (\Throwable $e) {
            self::assertSame(0, Db::name('hotels')->count());
            self::assertSame(0, Db::name('user_hotel_permissions')->count());
            self::assertSame(0, Db::name('operation_logs')->count());
        }
    }

    public function testOtaChannelStrategyCanBeSelectedOnCreateAndChangedAfterwards(): void
    {
        $this->createSchema(true);

        $user = new User();
        $user->id = 9001;
        $user->role_id = Role::SUPER_ADMIN;
        $user->tenant_id = 101;
        $controller = new HotelTenantCreationHarness(self::$app, [
            'name' => 'Editable OTA hotel',
            'code' => 'OTA-EDIT',
            'status' => 1,
            'tenant_id' => 101,
            'ota_channel_strategy' => 'ctrip_only',
        ]);
        $controller->useUser($user);

        $created = $this->json($controller->create());
        $hotelId = (int)$created['data']['id'];

        self::assertSame(200, $created['code']);
        self::assertSame('ctrip_only', $created['data']['ota_channel_strategy']);
        self::assertSame('ctrip_only', Db::name('hotels')->where('id', $hotelId)->value('ota_channel_strategy'));

        $controller->replacePayload([
            'name' => 'Editable OTA hotel',
            'code' => 'OTA-EDIT',
            'status' => 1,
            'ota_channel_strategy' => 'dual',
        ]);
        $updated = $this->json($controller->update($hotelId));

        self::assertSame(200, $updated['code']);
        self::assertSame('dual', $updated['data']['ota_channel_strategy']);
        self::assertSame('dual', Db::name('hotels')->where('id', $hotelId)->value('ota_channel_strategy'));

        $controller->replacePayload([
            'name' => 'Editable OTA hotel',
            'code' => 'OTA-EDIT',
            'status' => 1,
        ]);
        $legacyUpdate = $this->json($controller->update($hotelId));

        self::assertSame(200, $legacyUpdate['code']);
        self::assertSame('dual', $legacyUpdate['data']['ota_channel_strategy']);
        self::assertSame('dual', Db::name('hotels')->where('id', $hotelId)->value('ota_channel_strategy'));
    }

    public function testInvalidOtaChannelStrategyIsRejectedWithoutCreatingHotel(): void
    {
        $this->createSchema(true);

        $response = $this->createHotel('Invalid OTA hotel', null, [
            'ota_channel_strategy' => 'unsupported_platform',
        ]);
        $payload = $this->json($response);

        self::assertSame(422, $payload['code']);
        self::assertSame(0, Db::name('hotels')->count());
    }

    private function createSchema(bool $withTenantId): void
    {
        if ($withTenantId) {
            Db::execute('PRAGMA foreign_keys = ON');
            Db::execute('CREATE TABLE tenants (
                id INTEGER PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT \'active\',
                plan_id INTEGER,
                created_at DATETIME,
                updated_at DATETIME
            )');
            Db::name('tenants')->insertAll([
                ['id' => 101, 'name' => 'Tenant A', 'status' => 'active'],
                ['id' => 202, 'name' => 'Tenant B', 'status' => 'active'],
            ]);
        }

        $tenantColumn = $withTenantId ? 'tenant_id INTEGER NOT NULL REFERENCES tenants(id),' : '';
        Db::execute("CREATE TABLE hotels (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            {$tenantColumn}
            name VARCHAR(100) NOT NULL,
            code VARCHAR(50),
            address VARCHAR(255),
            contact_person VARCHAR(50),
            contact_phone VARCHAR(20),
            description TEXT,
            status INTEGER NOT NULL DEFAULT 1,
            ota_channel_strategy VARCHAR(20) NOT NULL DEFAULT 'none',
            owner_user_id INTEGER NOT NULL DEFAULT 0,
            created_by INTEGER NOT NULL DEFAULT 0,
            create_time DATETIME,
            update_time DATETIME
        )");
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

    private function createRejectingPermissionTable(): void
    {
        $this->createPermissionTable(true);
    }

    private function createPermissionTable(bool $rejectWrites = false): void
    {
        $userConstraint = $rejectWrites ? 'CHECK (user_id < 0)' : '';
        Db::execute('CREATE TABLE user_hotel_permissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER,
            user_id INTEGER NOT NULL ' . $userConstraint . ',
            hotel_id INTEGER NOT NULL,
            scope_type VARCHAR(20),
            can_view INTEGER,
            can_report INTEGER,
            can_fill INTEGER,
            can_edit INTEGER,
            can_fetch_ota INTEGER,
            can_delete_ota INTEGER,
            can_export INTEGER,
            can_ai INTEGER,
            can_operation INTEGER,
            can_investment INTEGER,
            status VARCHAR(20),
            created_by INTEGER,
            can_view_report INTEGER,
            can_fill_daily_report INTEGER,
            can_fill_monthly_task INTEGER,
            can_edit_report INTEGER,
            can_delete_report INTEGER,
            can_view_online_data INTEGER,
            can_fetch_online_data INTEGER,
            can_delete_online_data INTEGER,
            is_primary INTEGER,
            create_time DATETIME,
            update_time DATETIME
        )');
    }

    private function tenantManager(int $userId, int $tenantId, int $hotelId = 0): HotelCreationManagerUser
    {
        $user = new HotelCreationManagerUser();
        $user->id = $userId;
        $user->tenant_id = $tenantId;
        $user->hotel_id = $hotelId;

        return $user;
    }

    private function assertNoCreateWrites(): void
    {
        self::assertSame(0, Db::name('hotels')->count());
        self::assertSame(0, Db::name('user_hotel_permissions')->count());
        self::assertSame(0, Db::name('operation_logs')->count());
    }

    /** @param array<string, mixed> $payload */
    private function createHotel(string $name, ?User $user = null, array $payload = []): Response
    {
        $controller = new HotelTenantCreationHarness(self::$app, array_merge(['name' => $name], $payload));
        if ($user === null) {
            $user = new User();
            $user->id = 9001;
            $user->role_id = Role::SUPER_ADMIN;
        }
        $controller->useUser($user);

        return $controller->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        $decoded = json_decode($response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}

final class HotelCreationManagerUser extends User
{
    public function isSuperAdmin(): bool
    {
        return false;
    }

    public function canManageOwnHotels(): bool
    {
        return true;
    }
}

final class HotelTenantCreationHarness extends HotelController
{
    /** @param array<string, mixed> $payload */
    public function __construct(App $app, private array $payload)
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
