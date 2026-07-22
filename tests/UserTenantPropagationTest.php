<?php
declare(strict_types=1);

namespace Tests;

use app\controller\User as UserController;
use app\model\User as UserModel;
use PHPUnit\Framework\TestCase;
use think\App;
use think\Response;
use think\facade\Config;
use think\facade\Db;

final class UserTenantPropagationTest extends TestCase
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
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'user_tenant_propagation_' . getmypid() . '.sqlite';

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
        self::$app->request->user = null;

        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable $e) {
            // The first test has no open SQLite connection yet.
        }
        if (is_file(self::$sqlitePath)) {
            @unlink(self::$sqlitePath);
        }
        Db::connect(null, true);
    }

    public function testSuperAdminUserListIncludesCrossTenantHotelSummaries(): void
    {
        $this->createSchema(true);
        $this->seedRoleAndHotels();
        $this->seedUser(10, 101, 4, 'tenant_a_user');
        $this->seedUser(30, 202, 4, 'tenant_b_user');

        $payload = $this->json($this->userController([])->index());
        self::assertSame(200, $payload['code']);

        $rows = $payload['data']['list'];
        self::assertCount(2, $rows);
        $hotelsByUsername = [];
        foreach ($rows as $row) {
            $hotelsByUsername[(string)$row['username']] = array_map(
                static fn(array $hotel): int => (int)$hotel['id'],
                (array)($row['assigned_hotels'] ?? [])
            );
        }

        self::assertSame([10], $hotelsByUsername['tenant_a_user']);
        self::assertSame([30], $hotelsByUsername['tenant_b_user']);
    }

    public function testUserCreateUsesOneTenantForMultipleHotels(): void
    {
        $this->createSchema(true);
        $this->seedRoleAndHotels();

        $response = $this->userController([
            'username' => 'multi_tenant_user',
            'password' => 'Strong123!',
            'role_id' => 3,
            'hotel_ids' => [10, 20],
            'tenant_id' => 999,
        ])->create();

        self::assertSame(200, $this->json($response)['code']);
        $user = Db::name('users')->where('username', 'multi_tenant_user')->find();
        self::assertSame(10, (int)$user['hotel_id']);
        self::assertSame(101, (int)$user['tenant_id']);

        $permissions = Db::name('user_hotel_permissions')
            ->where('user_id', (int)$user['id'])
            ->order('hotel_id', 'asc')
            ->column('tenant_id', 'hotel_id');
        self::assertSame([10 => 101, 20 => 101], array_map('intval', $permissions));
    }

    public function testUserCreateRejectsHotelsFromDifferentTenantsWithoutPartialWrites(): void
    {
        $this->createSchema(true);
        $this->seedRoleAndHotels();

        $response = $this->userController([
            'username' => 'cross_tenant_user',
            'password' => 'Strong123!',
            'role_id' => 3,
            'hotel_ids' => [10, 30],
        ])->create();

        self::assertSame(422, $this->json($response)['code']);
        self::assertSame(0, Db::name('users')->count());
        self::assertSame(0, Db::name('user_hotel_permissions')->count());
    }

    public function testUserCreateRejectsZeroHotelTenantWithoutPartialWrites(): void
    {
        $this->createSchema(true);
        $this->seedRoleAndHotels();

        $response = $this->userController([
            'username' => 'invalid_user_tenant',
            'password' => 'Strong123!',
            'role_id' => 3,
            'hotel_ids' => [10, 40],
        ])->create();

        self::assertSame(422, $this->json($response)['code']);
        self::assertSame(0, Db::name('users')->count());
        self::assertSame(0, Db::name('user_hotel_permissions')->count());
    }

    public function testUserCreateFailsClosedWhenTenantSchemaIntrospectionFails(): void
    {
        $this->createSchema(true);
        $this->seedRoleAndHotels();
        $controller = $this->userController([
            'username' => 'schema_failure_managed_user',
            'password' => 'Strong123!',
            'role_id' => 3,
            'hotel_ids' => [10],
        ], true);

        $error = null;
        try {
            $controller->create();
        } catch (\RuntimeException $exception) {
            $error = $exception;
        }

        self::assertInstanceOf(\RuntimeException::class, $error);
        self::assertStringContainsString('tenant schema', $error->getMessage());
        self::assertCount(2, $controller->schemaQueries());
        self::assertStringStartsWith('SHOW COLUMNS', $controller->schemaQueries()[0]);
        self::assertStringStartsWith('PRAGMA table_info', $controller->schemaQueries()[1]);
        self::assertSame(0, Db::name('users')->count());
        self::assertSame(0, Db::name('user_hotel_permissions')->count());
    }

    public function testUserUpdateKeepsOneTenantAcrossMultipleHotels(): void
    {
        $this->createSchema(true);
        $this->seedRoleAndHotels();
        $userId = $this->seedUser(10, 101, 4);

        $response = $this->userController([
            'hotel_ids' => [20, 10],
            'tenant_id' => 999,
        ])->update($userId);

        self::assertSame(200, $this->json($response)['code']);
        $user = Db::name('users')->where('id', $userId)->find();
        self::assertSame(20, (int)$user['hotel_id']);
        self::assertSame(101, (int)$user['tenant_id']);

        $permissions = Db::name('user_hotel_permissions')
            ->where('user_id', $userId)
            ->order('hotel_id', 'asc')
            ->column('tenant_id', 'hotel_id');
        self::assertSame([10 => 101, 20 => 101], array_map('intval', $permissions));
    }

    public function testUserUpdateClearsTenantWhenPrimaryHotelIsRemoved(): void
    {
        $this->createSchema(true);
        $this->seedRoleAndHotels();
        $userId = $this->seedUser(10, 101, 4);

        $response = $this->userController(['hotel_ids' => []])->update($userId);

        self::assertSame(200, $this->json($response)['code']);
        $user = Db::name('users')->where('id', $userId)->find();
        self::assertNull($user['hotel_id']);
        self::assertNull($user['tenant_id']);
        self::assertSame(0, Db::name('user_hotel_permissions')->where('user_id', $userId)->count());
    }

    public function testBatchHotelAssignmentsSavesAllUsersInOneTransaction(): void
    {
        $this->createSchema(true);
        $this->seedRoleAndHotels();
        $firstUserId = $this->seedUser(10, 101, 4, 'batch_beta_one');
        $secondUserId = $this->seedUser(10, 101, 4, 'batch_beta_two');

        $payload = $this->json($this->userController([
            'changes' => [
                ['user_id' => $firstUserId, 'hotel_ids' => [10, 20]],
                ['user_id' => $secondUserId, 'hotel_ids' => [20]],
            ],
        ])->batchHotelAssignments());

        self::assertSame(200, $payload['code']);
        self::assertSame(2, (int)$payload['data']['affected_count']);
        self::assertSame(10, (int)Db::name('users')->where('id', $firstUserId)->value('hotel_id'));
        self::assertSame(20, (int)Db::name('users')->where('id', $secondUserId)->value('hotel_id'));
        self::assertSame(101, (int)Db::name('users')->where('id', $firstUserId)->value('tenant_id'));
        self::assertSame(101, (int)Db::name('users')->where('id', $secondUserId)->value('tenant_id'));

        $firstTenants = Db::name('user_hotel_permissions')
            ->where('user_id', $firstUserId)
            ->order('hotel_id', 'asc')
            ->column('tenant_id', 'hotel_id');
        self::assertSame([10 => 101, 20 => 101], array_map('intval', $firstTenants));
        self::assertSame(
            101,
            (int)Db::name('user_hotel_permissions')->where('user_id', $secondUserId)->where('hotel_id', 20)->value('tenant_id')
        );
    }

    public function testBatchHotelAssignmentsRollsBackEveryUserWhenOnePermissionWriteFails(): void
    {
        $this->createSchema(true);
        $this->seedRoleAndHotels();
        $firstUserId = $this->seedUser(10, 101, 4, 'rollback_beta_one');
        $secondUserId = $this->seedUser(10, 101, 4, 'rollback_beta_two');
        Db::execute("CREATE TRIGGER reject_second_batch_permission
            BEFORE INSERT ON user_hotel_permissions
            WHEN NEW.user_id = {$secondUserId} AND NEW.hotel_id = 20
            BEGIN
                SELECT RAISE(ABORT, 'synthetic permission failure');
            END");

        $payload = $this->json($this->userController([
            'changes' => [
                ['user_id' => $firstUserId, 'hotel_ids' => [10, 20]],
                ['user_id' => $secondUserId, 'hotel_ids' => [10, 20]],
            ],
        ])->batchHotelAssignments());

        self::assertSame(500, $payload['code']);
        self::assertStringContainsString('已回滚', (string)$payload['message']);
        self::assertSame(10, (int)Db::name('users')->where('id', $firstUserId)->value('hotel_id'));
        self::assertSame(10, (int)Db::name('users')->where('id', $secondUserId)->value('hotel_id'));
        self::assertSame(0, Db::name('user_hotel_permissions')->count());
        self::assertSame(0, Db::name('operation_logs')->count());
    }

    public function testBatchHotelAssignmentsRejectsNewGrantForDisabledUserWithoutWrites(): void
    {
        $this->createSchema(true);
        $this->seedRoleAndHotels();
        $userId = $this->seedUser(10, 101, 4, 'disabled_batch_beta');
        Db::name('users')->where('id', $userId)->update(['status' => 0]);

        $payload = $this->json($this->userController([
            'changes' => [
                ['user_id' => $userId, 'hotel_ids' => [10, 20]],
            ],
        ])->batchHotelAssignments());

        self::assertSame(422, $payload['code']);
        self::assertStringContainsString('停用账号不能新增', (string)$payload['message']);
        self::assertSame(10, (int)Db::name('users')->where('id', $userId)->value('hotel_id'));
        self::assertSame(0, Db::name('user_hotel_permissions')->count());
        self::assertSame(0, Db::name('operation_logs')->count());
    }

    public function testUserCreateFailsClosedWithoutCoreTenantColumnsAndRollsBackAllWrites(): void
    {
        $this->createSchema(false);
        $this->seedRoleAndHotels(false);

        $error = null;
        try {
            $this->userController([
                'username' => 'unmigrated_schema_user',
                'password' => 'Strong123!',
                'role_id' => 3,
                'hotel_ids' => [10, 20],
                'tenant_id' => 999,
            ])->create();
        } catch (\Throwable $exception) {
            $error = $exception;
        }

        self::assertNotNull($error, 'Tenant core columns must be migrated before the application runs');
        self::assertSame(
            'Required tenant column is missing: user_hotel_permissions.tenant_id',
            $error->getMessage()
        );
        self::assertSame(0, Db::name('users')->where('username', 'unmigrated_schema_user')->count());
        self::assertSame(0, Db::name('user_hotel_permissions')->count());
        self::assertSame(0, Db::name('operation_logs')->count());
    }

    private function createSchema(bool $withTenantColumns): void
    {
        $hotelTenant = $withTenantColumns ? 'tenant_id INTEGER NOT NULL DEFAULT 0,' : '';
        $userTenant = $withTenantColumns ? 'tenant_id INTEGER NULL,' : '';
        $permissionTenant = $withTenantColumns ? 'tenant_id INTEGER NULL,' : '';

        Db::execute("CREATE TABLE hotels (
            id INTEGER PRIMARY KEY,
            {$hotelTenant}
            name VARCHAR(100) NOT NULL,
            code VARCHAR(50),
            status INTEGER NOT NULL DEFAULT 1,
            owner_user_id INTEGER NOT NULL DEFAULT 0,
            created_by INTEGER NOT NULL DEFAULT 0,
            create_time DATETIME,
            update_time DATETIME
        )");
        Db::execute('CREATE TABLE roles (
            id INTEGER PRIMARY KEY,
            name VARCHAR(50) NOT NULL,
            display_name VARCHAR(100),
            description TEXT,
            level INTEGER NOT NULL,
            permissions TEXT,
            status INTEGER NOT NULL DEFAULT 1,
            create_time DATETIME,
            update_time DATETIME
        )');
        Db::execute("CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            {$userTenant}
            username VARCHAR(50) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            realname VARCHAR(100),
            email VARCHAR(100),
            phone VARCHAR(30),
            role_id INTEGER NOT NULL,
            hotel_id INTEGER NULL,
            status INTEGER NOT NULL DEFAULT 1,
            last_login_time DATETIME,
            last_login_ip VARCHAR(50),
            login_count INTEGER NOT NULL DEFAULT 0,
            create_time DATETIME,
            update_time DATETIME
        )");
        Db::execute("CREATE TABLE user_hotel_permissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            {$permissionTenant}
            user_id INTEGER NOT NULL,
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
        )");
        Db::execute('CREATE TABLE system_config (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            config_key VARCHAR(100) NOT NULL UNIQUE,
            config_value TEXT,
            description TEXT,
            create_time DATETIME,
            update_time DATETIME
        )');
        Db::execute('CREATE TABLE operation_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
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

    private function seedRoleAndHotels(bool $withTenantColumns = true): void
    {
        Db::name('roles')->insertAll([
            [
                'id' => 3,
                'name' => 'normal_user',
                'display_name' => 'Normal user',
                'level' => 3,
                'permissions' => json_encode(['hotel.view', 'report.view'], JSON_THROW_ON_ERROR),
                'status' => 1,
            ],
            [
                'id' => 4,
                'name' => 'internal_operator',
                'display_name' => 'Internal operator',
                'level' => 2,
                'permissions' => json_encode(['hotel.view', 'report.view'], JSON_THROW_ON_ERROR),
                'status' => 1,
            ],
        ]);

        $hotels = [
            ['id' => 10, 'name' => 'Hotel A', 'status' => 1],
            ['id' => 20, 'name' => 'Hotel A2', 'status' => 1],
            ['id' => 30, 'name' => 'Hotel B1', 'status' => 1],
            ['id' => 40, 'name' => 'Hotel invalid tenant', 'status' => 1],
        ];
        if ($withTenantColumns) {
            $hotels[0]['tenant_id'] = 101;
            $hotels[1]['tenant_id'] = 101;
            $hotels[2]['tenant_id'] = 202;
            $hotels[3]['tenant_id'] = 0;
        }
        Db::name('hotels')->insertAll($hotels);
    }

    private function seedUser(int $hotelId, int $tenantId, int $roleId = 3, string $username = 'existing_tenant_user'): int
    {
        return (int)Db::name('users')->insertGetId([
            'tenant_id' => $tenantId,
            'username' => $username,
            'password' => password_hash('Strong123!', PASSWORD_DEFAULT),
            'realname' => 'Existing user',
            'role_id' => $roleId,
            'hotel_id' => $hotelId,
            'status' => 1,
        ]);
    }

    private function userController(array $payload, bool $failSchemaIntrospection = false): UserTenantHarness
    {
        $controller = new UserTenantHarness(self::$app, $payload, $failSchemaIntrospection);
        $admin = new TenantTestAdminUser();
        $admin->id = 9001;
        $admin->tenant_id = 0;
        $admin->role_id = 1;
        $controller->useUser($admin);
        self::$app->request->user = $admin;

        return $controller;
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        $decoded = json_decode($response->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

}

final class TenantTestAdminUser extends UserModel
{
    public function canManageUser(): bool
    {
        return true;
    }

    public function isSuperAdmin(): bool
    {
        return true;
    }
}

final class UserTenantHarness extends UserController
{
    /** @var array<int, string> */
    private array $schemaQueries = [];

    /** @param array<string, mixed> $payload */
    public function __construct(App $app, private array $payload, private bool $failSchemaIntrospection = false)
    {
        parent::__construct($app);
    }

    public function useUser(UserModel $user): void
    {
        $this->currentUser = $user;
    }

    /** @return array<int, string> */
    public function schemaQueries(): array
    {
        return $this->schemaQueries;
    }

    protected function querySchema(string $sql): array
    {
        $this->schemaQueries[] = $sql;
        if ($this->failSchemaIntrospection) {
            throw new \RuntimeException('Synthetic schema introspection failure.');
        }

        return Db::query($sql);
    }

    protected function requestData(): array
    {
        return $this->payload;
    }
}
