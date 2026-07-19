<?php
declare(strict_types=1);

namespace Tests;

use app\model\User;
use app\service\HotelScopeService;
use app\service\PermissionService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class Tc038PermissionExpiryL8Test extends TestCase
{
    private const TENANT_ID = 38;
    private const ROLE_ID = 4;
    private const TARGET_USER_ID = 38;
    private const OTHER_USER_ID = 39;
    private const TARGET_HOTEL_ID = 380;
    private const TIMEZONE = 'Asia/Shanghai';

    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';
    private static string $originalTimezone = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$originalTimezone = date_default_timezone_get();
        date_default_timezone_set(self::TIMEZONE);

        self::$sqlitePath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'tc038_permission_expiry_l8_'
            . getmypid()
            . '_'
            . bin2hex(random_bytes(4))
            . '.sqlite';
        @unlink(self::$sqlitePath);

        $config = self::$originalDatabaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$sqlitePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($config, 'database');
        Db::connect(null, true);
        self::createSchema();
    }

    public static function tearDownAfterClass(): void
    {
        Db::connect()->close();
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        date_default_timezone_set(self::$originalTimezone);
        if (is_file(self::$sqlitePath) && !unlink(self::$sqlitePath)) {
            throw new RuntimeException('Unable to remove TC-038 SQLite fixture.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['user_hotel_permissions', 'users', 'hotels', 'roles'] as $table) {
            Db::name($table)->delete(true);
        }

        Db::name('roles')->insert([
            'id' => self::ROLE_ID,
            'name' => 'tc038_hotel_operator',
            'display_name' => 'TC-038 Hotel Operator',
            'permissions' => json_encode(['ota.view', 'ota.collect'], JSON_THROW_ON_ERROR),
            'level' => 2,
            'status' => 1,
            'create_time' => '2026-07-15 00:00:00',
            'update_time' => '2026-07-15 00:00:00',
        ]);
        $this->insertUser(self::TARGET_USER_ID, null);
        $this->insertUser(self::OTHER_USER_ID, null);
    }

    /**
     * The L8 grant is a secondary hotel grant, so ownership and users.hotel_id
     * cannot bypass its actor, permission-bit, expiry, or active-state gates.
     * DX-0297 also carries a four-point boundary matrix to prove NULL, future,
     * equal-to-current-second, and past expiry behavior on both scope queries
     * and the real User -> PermissionService authorization path.
     *
     * @param array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string} $factors
     */
    #[DataProvider('l8VariantProvider')]
    public function testTc038L8PermissionExpiryBoundaries(string $caseId, array $factors): void
    {
        self::assertSame(self::TIMEZONE, date_default_timezone_get(), $caseId);
        $clock = new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE));
        $expiresAt = $factors['freshness'] === 'fresh'
            ? $clock->modify('+1 day')->format('Y-m-d H:i:s')
            : $clock->modify('-1 day')->format('Y-m-d H:i:s');
        $variant = (int)substr($caseId, -1);
        $hotelActive = $factors['upstream_state'] === 'success' || ($variant % 2) === 1;
        $grantActive = $factors['upstream_state'] === 'success' || ($variant % 2) === 0;
        $granteeId = $factors['actor_scope'] === 'authorized' ? self::TARGET_USER_ID : self::OTHER_USER_ID;
        $canView = $factors['data_completeness'] === 'complete' ? 1 : null;

        $this->insertHotel(self::TARGET_HOTEL_ID, $hotelActive);
        $this->insertGrant(
            $granteeId,
            self::TARGET_HOTEL_ID,
            $canView,
            1,
            $grantActive ? 'active' : 'disabled',
            $expiresAt
        );

        $user = $this->loadUser(self::TARGET_USER_ID);
        $scopeService = new HotelScopeService();
        $permissionService = new PermissionService($scopeService);
        $scopeIds = $scopeService->accessibleHotelIds($user, 'ota.view');
        $authorization = $permissionService->authorize($user, 'ota.view', self::TARGET_HOTEL_ID);
        $expectedAllowed = $factors['actor_scope'] === 'authorized'
            && $factors['data_completeness'] === 'complete'
            && $factors['freshness'] === 'fresh'
            && $factors['upstream_state'] === 'success';
        $message = $caseId . ' factors=' . json_encode($factors, JSON_UNESCAPED_SLASHES);

        self::assertSame(
            [
                'in_accessible_scope' => $expectedAllowed,
                'can_access_hotel' => $expectedAllowed,
                'hotel_permission_allows' => $expectedAllowed,
                'permission_service_allows' => $expectedAllowed,
                'user_permission_allows' => $expectedAllowed,
            ],
            [
                'in_accessible_scope' => in_array(self::TARGET_HOTEL_ID, $scopeIds, true),
                'can_access_hotel' => $scopeService->canAccessHotel($user, self::TARGET_HOTEL_ID, 'ota.view'),
                'hotel_permission_allows' => $scopeService->hotelPermissionAllows($user, self::TARGET_HOTEL_ID, 'ota.view'),
                'permission_service_allows' => ($authorization['allowed'] ?? null) === true,
                'user_permission_allows' => $user->hasHotelPermission(self::TARGET_HOTEL_ID, 'can_view_online_data'),
            ],
            $message
        );
        $this->assertL8FixtureWasPersisted($granteeId, $canView, $expiresAt, $hotelActive, $grantActive, $message);

        if ($caseId === 'DX-0297') {
            $this->assertExpiryBoundaryMatrix($clock, $scopeService);
        }
    }

    /**
     * @return array<string, array{0:string,1:array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string}}>
     */
    public static function l8VariantProvider(): array
    {
        return [
            'DX-0297 authorized complete fresh active' => ['DX-0297', self::factors('authorized', 'complete', 'fresh', 'success')],
            'DX-0298 authorized complete expired disabled' => ['DX-0298', self::factors('authorized', 'complete', 'stale', 'failure')],
            'DX-0299 authorized missing fresh disabled' => ['DX-0299', self::factors('authorized', 'missing_required', 'fresh', 'failure')],
            'DX-0300 authorized missing expired active' => ['DX-0300', self::factors('authorized', 'missing_required', 'stale', 'success')],
            'DX-0301 restricted complete fresh disabled' => ['DX-0301', self::factors('restricted', 'complete', 'fresh', 'failure')],
            'DX-0302 restricted complete expired active' => ['DX-0302', self::factors('restricted', 'complete', 'stale', 'success')],
            'DX-0303 restricted missing fresh active' => ['DX-0303', self::factors('restricted', 'missing_required', 'fresh', 'success')],
            'DX-0304 restricted missing expired disabled' => ['DX-0304', self::factors('restricted', 'missing_required', 'stale', 'failure')],
        ];
    }

    public function testRequestLocalScopeMemoizationDoesNotMixUsersHotelsOrCapabilities(): void
    {
        $this->insertHotel(self::TARGET_HOTEL_ID, true);
        $this->insertHotel(self::TARGET_HOTEL_ID + 1, true);
        $this->insertGrant(self::TARGET_USER_ID, self::TARGET_HOTEL_ID, 1, 0, 'active', null);
        $this->insertGrant(self::OTHER_USER_ID, self::TARGET_HOTEL_ID + 1, 1, 1, 'active', null);

        $targetUser = $this->loadUser(self::TARGET_USER_ID);
        $otherUser = $this->loadUser(self::OTHER_USER_ID);
        $scopeService = new HotelScopeService();
        $queryCount = 0;
        Db::listen(static function ($sql) use (&$queryCount): void {
            if (!str_starts_with((string)$sql, 'CONNECT:')) {
                $queryCount++;
            }
        });

        $beforeView = $queryCount;
        self::assertSame([self::TARGET_HOTEL_ID], $scopeService->accessibleHotelIds($targetUser, 'ota.view'));
        $afterFirstView = $queryCount;
        self::assertGreaterThan($beforeView, $afterFirstView);

        self::assertSame([self::TARGET_HOTEL_ID], $scopeService->accessibleHotelIds($targetUser, 'ota.view'));
        self::assertSame($afterFirstView, $queryCount, 'same user and capability must reuse the request-local scope snapshot');

        self::assertSame([], $scopeService->accessibleHotelIds($targetUser, 'ota.collect'));
        self::assertGreaterThan($afterFirstView, $queryCount, 'a different capability must not reuse the ota.view result');
        self::assertSame([self::TARGET_HOTEL_ID + 1], $scopeService->accessibleHotelIds($otherUser, 'ota.view'));

        self::assertTrue($scopeService->hotelPermissionAllows($targetUser, self::TARGET_HOTEL_ID, 'ota.view'));
        $afterFirstPermission = $queryCount;
        self::assertTrue($scopeService->hotelPermissionAllows($targetUser, self::TARGET_HOTEL_ID, 'ota.view'));
        self::assertSame($afterFirstPermission, $queryCount, 'permission lookup must be reused only for the same user object and hotel');
        self::assertFalse($scopeService->hotelPermissionAllows($otherUser, self::TARGET_HOTEL_ID, 'ota.view'));
    }

    public function testExplicitOtaViewDenialOverridesGenericHotelViewGrant(): void
    {
        $this->insertHotel(self::TARGET_HOTEL_ID, true);
        $this->insertGrant(self::TARGET_USER_ID, self::TARGET_HOTEL_ID, 1, 1, 'active', null);
        Db::name('user_hotel_permissions')
            ->where('user_id', self::TARGET_USER_ID)
            ->where('hotel_id', self::TARGET_HOTEL_ID)
            ->update(['can_view_online_data' => 0]);

        $user = $this->loadUser(self::TARGET_USER_ID);
        $scopeService = new HotelScopeService();

        self::assertSame([], $scopeService->accessibleHotelIds($user, 'ota.view'));
        self::assertFalse($scopeService->hotelPermissionAllows($user, self::TARGET_HOTEL_ID, 'ota.view'));
        self::assertFalse((new PermissionService($scopeService))->authorize($user, 'ota.view', self::TARGET_HOTEL_ID)['allowed']);
    }

    public function testPermissionTenantMustMatchTargetHotelTenant(): void
    {
        $this->insertHotel(self::TARGET_HOTEL_ID, true);
        $this->insertGrant(self::TARGET_USER_ID, self::TARGET_HOTEL_ID, 1, 1, 'active', null);
        Db::name('user_hotel_permissions')
            ->where('user_id', self::TARGET_USER_ID)
            ->where('hotel_id', self::TARGET_HOTEL_ID)
            ->update(['tenant_id' => self::TENANT_ID + 1]);

        $user = $this->loadUser(self::TARGET_USER_ID);
        $scopeService = new HotelScopeService();
        self::assertSame([], $scopeService->accessibleHotelIds($user, 'ota.view'));
        self::assertFalse($scopeService->hotelPermissionAllows($user, self::TARGET_HOTEL_ID, 'ota.view'));

        Db::name('user_hotel_permissions')
            ->where('user_id', self::TARGET_USER_ID)
            ->where('hotel_id', self::TARGET_HOTEL_ID)
            ->update(['tenant_id' => self::TENANT_ID]);
        $scopeService->invalidateUser($user);

        self::assertSame([self::TARGET_HOTEL_ID], $scopeService->accessibleHotelIds($user, 'ota.view'));
        self::assertTrue($scopeService->hotelPermissionAllows($user, self::TARGET_HOTEL_ID, 'ota.view'));
    }

    public function testInvalidateUserRefreshesARevokedPermissionSnapshot(): void
    {
        $this->insertHotel(self::TARGET_HOTEL_ID, true);
        $this->insertGrant(self::TARGET_USER_ID, self::TARGET_HOTEL_ID, 1, 1, 'active', null);
        $user = $this->loadUser(self::TARGET_USER_ID);
        $scopeService = new HotelScopeService();
        $permissionService = new PermissionService($scopeService);

        self::assertTrue($permissionService->authorize($user, 'ota.view', self::TARGET_HOTEL_ID)['allowed']);
        Db::name('user_hotel_permissions')
            ->where('user_id', self::TARGET_USER_ID)
            ->where('hotel_id', self::TARGET_HOTEL_ID)
            ->update(['can_view' => 0, 'can_view_online_data' => 0]);

        self::assertTrue(
            $permissionService->authorize($user, 'ota.view', self::TARGET_HOTEL_ID)['allowed'],
            'the request-local snapshot remains stable until its write path explicitly invalidates it'
        );
        $scopeService->invalidateUser($user);
        self::assertFalse($permissionService->authorize($user, 'ota.view', self::TARGET_HOTEL_ID)['allowed']);
    }

    public function testWeakMapReleasesAUserBucketBeforeAnEquivalentModelIsReused(): void
    {
        $this->insertHotel(self::TARGET_HOTEL_ID, true);
        $this->insertGrant(self::TARGET_USER_ID, self::TARGET_HOTEL_ID, 1, 1, 'active', null);
        $scopeService = new HotelScopeService();
        $user = $this->loadUser(self::TARGET_USER_ID);

        self::assertSame([self::TARGET_HOTEL_ID], $scopeService->accessibleHotelIds($user, 'ota.view'));
        $cacheProperty = (new \ReflectionClass($scopeService))->getProperty('userCache');
        $userCache = $cacheProperty->getValue($scopeService);
        self::assertInstanceOf(\WeakMap::class, $userCache);
        self::assertCount(1, $userCache);

        unset($user);
        gc_collect_cycles();
        self::assertCount(0, $userCache, 'released User objects must not leave a reusable identity cache entry');

        Db::name('user_hotel_permissions')
            ->where('user_id', self::TARGET_USER_ID)
            ->where('hotel_id', self::TARGET_HOTEL_ID)
            ->update(['can_view' => 0, 'can_view_online_data' => 0]);
        $replacement = $this->loadUser(self::TARGET_USER_ID);
        self::assertSame([], $scopeService->accessibleHotelIds($replacement, 'ota.view'));
    }

    private static function createSchema(): void
    {
        Db::execute('CREATE TABLE roles (id INTEGER PRIMARY KEY, name VARCHAR(50), display_name VARCHAR(100), permissions TEXT, level INTEGER NOT NULL, status INTEGER NOT NULL, create_time DATETIME, update_time DATETIME)');
        Db::execute('CREATE TABLE users (id INTEGER PRIMARY KEY, tenant_id INTEGER, username VARCHAR(100) NOT NULL, password VARCHAR(255) NOT NULL, role_id INTEGER NOT NULL, hotel_id INTEGER, status INTEGER NOT NULL, create_time DATETIME, update_time DATETIME)');
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER, name VARCHAR(100) NOT NULL, status INTEGER NOT NULL, owner_user_id INTEGER, created_by INTEGER, create_time DATETIME, update_time DATETIME)');
        Db::execute('CREATE TABLE user_hotel_permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, user_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, can_view INTEGER, can_view_online_data INTEGER, can_fetch_ota INTEGER, status VARCHAR(20), expires_at DATETIME, create_time DATETIME, update_time DATETIME)');
    }

    private function assertExpiryBoundaryMatrix(DateTimeImmutable $clock, HotelScopeService $scopeService): void
    {
        $scopeUser = $this->loadUser(self::TARGET_USER_ID);
        $expiryByState = [
            'permanent' => null,
            'future' => $clock->modify('+1 hour')->format('Y-m-d H:i:s'),
            'equal_now' => $clock->format('Y-m-d H:i:s'),
            'past' => $clock->modify('-1 hour')->format('Y-m-d H:i:s'),
        ];
        $scopeHotelIds = [
            'permanent' => 381,
            'future' => 382,
            'equal_now' => 383,
            'past' => 384,
        ];
        foreach ($scopeHotelIds as $state => $hotelId) {
            $this->insertHotel($hotelId, true);
            $this->insertGrant(self::TARGET_USER_ID, $hotelId, 1, 1, 'active', $expiryByState[$state]);
        }

        $scopeIds = $scopeService->accessibleHotelIds($scopeUser, 'ota.view');
        $scopeBoundaryIds = array_values(array_intersect(array_values($scopeHotelIds), $scopeIds));
        sort($scopeBoundaryIds);
        $permissionService = new PermissionService($scopeService);
        $scopeActual = [
            'accessible_hotel_ids' => $scopeBoundaryIds,
            'can_access_hotel' => [],
            'hotel_permission_allows' => [],
            'permission_service_allows' => [],
            'user_permission_allows' => [],
        ];
        foreach ($scopeHotelIds as $state => $hotelId) {
            $scopeActual['can_access_hotel'][$state] = $scopeService->canAccessHotel($scopeUser, $hotelId, 'ota.view');
            $scopeActual['hotel_permission_allows'][$state] = $scopeService->hotelPermissionAllows($scopeUser, $hotelId, 'ota.view');
            $scopeActual['permission_service_allows'][$state] = $permissionService->authorize($scopeUser, 'ota.view', $hotelId)['allowed'];
            $scopeActual['user_permission_allows'][$state] = $scopeUser->hasHotelPermission($hotelId, 'can_view_online_data');
        }

        $permissionRecordActual = [
            'hotel_permission_allows' => [],
            'permission_service_allows' => [],
            'user_permission_allows' => [],
        ];
        foreach ($expiryByState as $index => $expiresAt) {
            $state = (string)$index;
            $hotelId = 391 + array_search($state, array_keys($expiryByState), true);
            $userId = 391 + array_search($state, array_keys($expiryByState), true);
            $this->insertHotel($hotelId, true);
            $this->insertUser($userId, $hotelId);
            $this->insertGrant($userId, $hotelId, 1, 1, 'active', $expiresAt);
            $primaryUser = $this->loadUser($userId);
            $permissionRecordActual['hotel_permission_allows'][$state] = $scopeService->hotelPermissionAllows($primaryUser, $hotelId, 'ota.collect');
            $permissionRecordActual['permission_service_allows'][$state] = (new PermissionService($scopeService))->authorize($primaryUser, 'ota.collect', $hotelId)['allowed'];
            $permissionRecordActual['user_permission_allows'][$state] = $primaryUser->hasHotelPermission($hotelId, 'can_fetch_online_data');
        }

        $stateExpectation = [
            'permanent' => true,
            'future' => true,
            'equal_now' => false,
            'past' => false,
        ];
        self::assertSame(
            [
                'timezone' => self::TIMEZONE,
                'scope_query' => [
                    'accessible_hotel_ids' => [381, 382],
                    'can_access_hotel' => $stateExpectation,
                    'hotel_permission_allows' => $stateExpectation,
                    'permission_service_allows' => $stateExpectation,
                    'user_permission_allows' => $stateExpectation,
                ],
                'permission_record_query' => [
                    'hotel_permission_allows' => $stateExpectation,
                    'permission_service_allows' => $stateExpectation,
                    'user_permission_allows' => $stateExpectation,
                ],
            ],
            [
                'timezone' => date_default_timezone_get(),
                'scope_query' => $scopeActual,
                'permission_record_query' => $permissionRecordActual,
            ],
            'TC-038 expiry boundary: NULL must remain permanent, future valid, equal-now and past invalid'
        );
    }

    private function assertL8FixtureWasPersisted(
        int $granteeId,
        ?int $canView,
        string $expiresAt,
        bool $hotelActive,
        bool $grantActive,
        string $message
    ): void {
        $grant = Db::name('user_hotel_permissions')->where('hotel_id', self::TARGET_HOTEL_ID)->find();
        self::assertIsArray($grant, $message);
        self::assertSame($granteeId, (int)$grant['user_id'], $message);
        self::assertSame($canView, $grant['can_view'] === null ? null : (int)$grant['can_view'], $message);
        self::assertSame($expiresAt, (string)$grant['expires_at'], $message);
        self::assertSame($hotelActive ? 1 : 0, (int)Db::name('hotels')->where('id', self::TARGET_HOTEL_ID)->value('status'), $message);
        self::assertSame($grantActive ? 'active' : 'disabled', (string)$grant['status'], $message);
    }

    private function insertUser(int $userId, ?int $primaryHotelId): void
    {
        Db::name('users')->insert([
            'id' => $userId,
            'tenant_id' => self::TENANT_ID,
            'username' => 'tc038-user-' . $userId,
            'password' => password_hash('Tc038-Only!', PASSWORD_DEFAULT),
            'role_id' => self::ROLE_ID,
            'hotel_id' => $primaryHotelId,
            'status' => 1,
            'create_time' => '2026-07-15 00:00:00',
            'update_time' => '2026-07-15 00:00:00',
        ]);
    }

    private function insertHotel(int $hotelId, bool $active): void
    {
        Db::name('hotels')->insert([
            'id' => $hotelId,
            'tenant_id' => self::TENANT_ID,
            'name' => 'TC-038 Hotel ' . $hotelId,
            'status' => $active ? 1 : 0,
            'owner_user_id' => null,
            'created_by' => null,
            'create_time' => '2026-07-15 00:00:00',
            'update_time' => '2026-07-15 00:00:00',
        ]);
    }

    private function insertGrant(
        int $userId,
        int $hotelId,
        ?int $canView,
        ?int $canFetchOta,
        string $status,
        ?string $expiresAt
    ): void {
        Db::name('user_hotel_permissions')->insert([
            'tenant_id' => self::TENANT_ID,
            'user_id' => $userId,
            'hotel_id' => $hotelId,
            'can_view' => $canView,
            'can_view_online_data' => $canView,
            'can_fetch_ota' => $canFetchOta,
            'status' => $status,
            'expires_at' => $expiresAt,
            'create_time' => '2026-07-15 00:00:00',
            'update_time' => '2026-07-15 00:00:00',
        ]);
    }

    private function loadUser(int $userId): User
    {
        $user = User::with(['role'])->find($userId);
        self::assertInstanceOf(User::class, $user);
        return $user;
    }

    /**
     * @return array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string}
     */
    private static function factors(
        string $actorScope,
        string $dataCompleteness,
        string $freshness,
        string $upstreamState
    ): array {
        return [
            'actor_scope' => $actorScope,
            'data_completeness' => $dataCompleteness,
            'freshness' => $freshness,
            'upstream_state' => $upstreamState,
        ];
    }
}
