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
    }

    public function testCreateBackfillsTenantIdInDatabaseAndResponseWhenColumnExists(): void
    {
        $this->createSchema(true);

        $response = $this->createHotel('Tenant-aware hotel');
        $payload = $this->json($response);
        $hotelId = (int)$payload['data']['id'];

        self::assertGreaterThan(0, $hotelId);
        self::assertSame($hotelId, (int)Db::name('hotels')->where('id', $hotelId)->value('tenant_id'));
        self::assertSame($hotelId, (int)$payload['data']['tenant_id']);
    }

    public function testCreateRemainsCompatibleWhenTenantIdColumnDoesNotExist(): void
    {
        $this->createSchema(false);

        $response = $this->createHotel('Legacy-schema hotel');
        $payload = $this->json($response);

        self::assertSame(200, $payload['code']);
        self::assertGreaterThan(0, (int)$payload['data']['id']);
        self::assertSame(1, Db::name('hotels')->count());
        self::assertArrayNotHasKey('tenant_id', $payload['data']);
    }

    public function testCreatePreservesExistingPositiveTenantId(): void
    {
        $this->createSchema(true, 77);

        $payload = $this->json($this->createHotel('Preassigned-tenant hotel'));
        $hotelId = (int)$payload['data']['id'];

        self::assertSame(77, (int)Db::name('hotels')->where('id', $hotelId)->value('tenant_id'));
        self::assertSame(77, (int)$payload['data']['tenant_id']);
    }

    public function testCreateRollsBackHotelPermissionAndLogWhenPermissionGrantFails(): void
    {
        $this->createSchema(true);
        $this->createRejectingPermissionTable();
        $user = new HotelCreationManagerUser();
        $user->id = 9002;
        $user->hotel_id = 0;

        try {
            $this->createHotel('Atomic-create hotel', $user);
            self::fail('Permission insertion failure must abort hotel creation.');
        } catch (\Throwable $e) {
            self::assertSame(0, Db::name('hotels')->count());
            self::assertSame(0, Db::name('user_hotel_permissions')->count());
            self::assertSame(0, Db::name('operation_logs')->count());
        }
    }

    private function createSchema(bool $withTenantId, int $tenantIdDefault = 0): void
    {
        $tenantColumn = $withTenantId ? "tenant_id INTEGER NOT NULL DEFAULT {$tenantIdDefault}," : '';
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
        Db::execute('CREATE TABLE user_hotel_permissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER,
            user_id INTEGER NOT NULL CHECK (user_id < 0),
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

    private function createHotel(string $name, ?User $user = null): Response
    {
        $controller = new HotelTenantCreationHarness(self::$app, ['name' => $name]);
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
    }

    protected function requestData(): array
    {
        return $this->payload;
    }
}
