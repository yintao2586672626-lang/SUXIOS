<?php
declare(strict_types=1);

namespace Tests;

use app\controller\OperationLogController;
use app\controller\DailyReport as DailyReportController;
use app\controller\OnlineData;
use app\controller\StrategySimulation;
use app\model\AgentConfig;
use app\model\AgentLog;
use app\model\base\BaseTenantModel;
use app\model\DailyReport;
use app\model\DemandForecast;
use app\model\Hotel;
use app\model\MonthlyTask;
use app\model\OnlineDailyData;
use app\model\OperationLog;
use app\model\PlatformDataSource;
use app\model\PriceSuggestion;
use app\model\Role;
use app\model\User;
use app\service\HotelScopeService;
use app\service\ExpansionService;
use app\service\FeasibilityReportService;
use app\service\QuantSimulationService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\exception\HttpException;
use think\facade\Config;
use think\facade\Db;
use think\Request;

final class TenantIsolationTest extends TestCase
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
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tenant_isolation_' . getmypid() . '.sqlite';

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
        Db::connect()->close();
        Db::connect(null, true);

        if (self::$sqlitePath !== '' && is_file(self::$sqlitePath)) {
            @unlink(self::$sqlitePath);
        }
    }

    protected function setUp(): void
    {
        self::$app->request->user = null;

        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        if (is_file(self::$sqlitePath)) {
            @unlink(self::$sqlitePath);
        }
        Db::connect(null, true);

        $this->createSchema();
        $this->seedTenantFixture();
    }

    public function testTenantACannotAccessTenantBHotelEvenWithForgedGrant(): void
    {
        $user = User::find(501);
        self::assertInstanceOf(User::class, $user);

        $scope = new HotelScopeService();

        self::assertSame([10, 11], $scope->accessibleHotelIds($user));
        self::assertTrue($scope->canAccessHotel($user, 10, 'hotel.view'));
        self::assertFalse($scope->canAccessHotel($user, 20, 'hotel.view'));
        self::assertFalse($scope->hotelPermissionAllows($user, 20, 'hotel.view'));
    }

    public function testOneTenantCanAccessMultipleGrantedHotels(): void
    {
        $user = User::find(501);
        self::assertInstanceOf(User::class, $user);

        $scope = new HotelScopeService();

        self::assertTrue($scope->canAccessHotel($user, 10, 'hotel.view'));
        self::assertTrue($scope->canAccessHotel($user, 11, 'hotel.view'));
        self::assertTrue($scope->hotelPermissionAllows($user, 11, 'hotel.view'));
    }

    public function testNormalUserQueriesAreAutomaticallyIsolatedAcrossTenCoreModels(): void
    {
        $user = User::find(501);
        self::assertInstanceOf(User::class, $user);
        self::$app->request->user = $user;

        foreach ($this->tenantModelClasses() as $modelClass) {
            self::assertTrue(is_subclass_of($modelClass, BaseTenantModel::class), $modelClass);

            $rows = $modelClass::order('id')->select()->toArray();
            self::assertNotEmpty($rows, $modelClass);
            self::assertSame(
                [101],
                array_values(array_unique(array_map(
                    static fn(array $row): int => (int)$row['tenant_id'],
                    $rows
                ))),
                $modelClass
            );
        }
    }

    public function testLegacyUserHotelReferenceUsesAuthoritativeHotelTenantMapping(): void
    {
        Db::name('users')->insertAll([
            [
                'id' => 503,
                'tenant_id' => 0,
                'username' => 'legacy_mapped_user',
                'password' => 'unused',
                'role_id' => 3,
                'hotel_id' => 20,
                'status' => 1,
            ],
            [
                'id' => 504,
                'tenant_id' => 0,
                'username' => 'legacy_unmapped_user',
                'password' => 'unused',
                'role_id' => 3,
                'hotel_id' => 999,
                'status' => 1,
            ],
        ]);

        foreach ([
            new FeasibilityReportService(),
            new ExpansionService(),
            new QuantSimulationService(),
        ] as $service) {
            $method = new \ReflectionMethod($service, 'tenantIdForUser');
            self::assertSame(202, $method->invoke($service, 503));
            self::assertNull($method->invoke($service, 504));
        }

        $controller = (new \ReflectionClass(StrategySimulation::class))->newInstanceWithoutConstructor();
        $currentUser = new \ReflectionProperty(\app\controller\Base::class, 'currentUser');
        $tenantMethod = new \ReflectionMethod($controller, 'tenantIdForCurrentUser');

        $currentUser->setValue($controller, (object)['id' => 503]);
        self::assertSame(202, $tenantMethod->invoke($controller));

        $currentUser->setValue($controller, (object)['id' => 504]);
        self::assertNull($tenantMethod->invoke($controller));
    }

    public function testSuperAdminDefaultAndExplicitQueriesCrossTenantsAcrossTenCoreModels(): void
    {
        $admin = new User();
        $admin->id = 1;
        $admin->tenant_id = 0;
        $admin->role_id = Role::SUPER_ADMIN;
        self::$app->request->user = $admin;

        foreach ($this->tenantModelClasses() as $modelClass) {
            $defaultRows = $modelClass::order('id')->select()->toArray();
            self::assertSame(
                [101, 202],
                array_values(array_unique(array_map(
                    static fn(array $row): int => (int)$row['tenant_id'],
                    $defaultRows
                ))),
                $modelClass . ' superadmin default query'
            );

            $rows = $modelClass::withoutTenantScope()->order('id')->select()->toArray();
            self::assertSame(
                [101, 202],
                array_values(array_unique(array_map(
                    static fn(array $row): int => (int)$row['tenant_id'],
                    $rows
                ))),
                $modelClass
            );
        }
    }

    public function testTenantWritesUseTenantFactsAndRemainIsolatedAcrossTenCoreModels(): void
    {
        $tenantA = User::find(501);
        $tenantB = User::find(502);
        self::assertInstanceOf(User::class, $tenantA);
        self::assertInstanceOf(User::class, $tenantB);
        self::$app->request->user = $tenantA;

        $agentConfig = new AgentConfig();
        $agentConfig->tenant_id = 11;
        $agentConfig->hotel_id = 11;
        $agentConfig->agent_type = AgentConfig::AGENT_TYPE_REVENUE;
        $agentConfig->is_enabled = 1;
        $agentConfig->config_data = [];
        $agentConfig->save();

        $records = [
            Hotel::create(['name' => 'Hotel A3', 'status' => Hotel::STATUS_ENABLED]),
            DailyReport::create(['hotel_id' => 11, 'label' => 'daily_write']),
            MonthlyTask::create(['tenant_id' => 11, 'hotel_id' => 11, 'label' => 'monthly_write']),
            OnlineDailyData::create(['system_hotel_id' => 11, 'label' => 'online_write']),
            OperationLog::create(['hotel_id' => 11, 'label' => 'operation_write']),
            PlatformDataSource::create(['system_hotel_id' => 11, 'label' => 'source_write']),
            $agentConfig,
            AgentLog::record(
                11,
                AgentLog::AGENT_TYPE_REVENUE,
                'tenant_write',
                'tenant write verification'
            ),
            PriceSuggestion::create(['hotel_id' => 11, 'label' => 'price_write']),
            DemandForecast::createForecast(11, '2026-07-22', []),
        ];

        self::assertCount(10, $records);
        foreach ($records as $record) {
            $modelClass = $record::class;
            $recordId = (int)$record->id;

            self::assertSame(101, (int)$record->tenant_id, $modelClass);
            self::assertNotNull($modelClass::find($recordId), $modelClass . ' same-tenant readback');

            self::$app->request->user = $tenantB;
            self::assertNull($modelClass::find($recordId), $modelClass . ' cross-tenant visibility');
            self::$app->request->user = $tenantA;
        }
    }

    public function testSuperAdminHotelScopeServiceUsesExplicitTenantBypass(): void
    {
        $admin = new User();
        $admin->id = 1;
        $admin->tenant_id = 0;
        $admin->role_id = Role::SUPER_ADMIN;
        self::$app->request->user = $admin;

        $scope = new HotelScopeService();

        self::assertSame([10, 11, 20], $scope->accessibleHotelIds($admin));
        self::assertTrue($scope->canAccessHotel($admin, 10, 'hotel.view'));
        self::assertTrue($scope->canAccessHotel($admin, 20, 'hotel.view'));
    }

    public function testOnlineDailyDataWritePrefersSystemHotelOverPlatformHotelId(): void
    {
        Db::name('hotels')->where('id', 20)->update(['tenant_id' => 10]);
        self::$app->request->user = $this->superAdminActor();

        $record = OnlineDailyData::create([
            'system_hotel_id' => 20,
            'hotel_id' => 974065,
            'label' => 'dual_hotel_identity',
        ]);

        self::assertSame(10, (int)$record->tenant_id);
        self::assertSame(20, (int)$record->system_hotel_id);
        self::assertSame(974065, (int)$record->hotel_id);
        self::assertSame(10, (int)Db::name('online_daily_data')->where('id', (int)$record->id)->value('tenant_id'));

        try {
            OnlineDailyData::create([
                'system_hotel_id' => 999,
                'hotel_id' => 20,
                'label' => 'invalid_system_hotel_must_not_fallback',
            ]);
            self::fail('An invalid system hotel must not fall back to the platform hotel id.');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->getStatusCode());
        }

        self::assertSame(
            0,
            Db::name('online_daily_data')->where('label', 'invalid_system_hotel_must_not_fallback')->count()
        );
    }

    public function testSuperAdminOperationLogRequestsReadAcrossTenantsExplicitly(): void
    {
        $admin = new User();
        $admin->id = 1;
        $admin->tenant_id = 0;
        $admin->role_id = Role::SUPER_ADMIN;
        self::$app->request->user = $admin;

        $now = date('Y-m-d H:i:s');
        Db::name('operation_logs')->where('tenant_id', 101)->update([
            'user_id' => 501,
            'hotel_id' => 10,
            'module' => 'hotel',
            'action' => 'delete',
            'description' => 'tenant A audit',
            'create_time' => $now,
        ]);
        Db::name('operation_logs')->where('tenant_id', 202)->update([
            'user_id' => 502,
            'hotel_id' => 20,
            'module' => 'auth',
            'action' => 'reset_password',
            'description' => 'tenant B audit',
            'create_time' => $now,
        ]);

        $controller = new OperationLogController(self::$app);
        $index = $this->responseData($controller->index((new Request())->withGet(['page_size' => 20])));
        self::assertSame(2, (int)$index['total']);
        self::assertSame([101, 202], array_values(array_unique(array_map(
            static fn(array $row): int => (int)$row['tenant_id'],
            $index['list']
        ))));
        self::assertSame([10, 11, 20], array_map(
            static fn(array $hotel): int => (int)$hotel['id'],
            $index['hotels']
        ));
        $tenantBListRow = current(array_filter(
            $index['list'],
            static fn(array $row): bool => (int)$row['tenant_id'] === 202
        ));
        self::assertIsArray($tenantBListRow);
        self::assertSame(20, (int)($tenantBListRow['hotel']['id'] ?? 0));
        self::assertSame('Hotel B1', (string)($tenantBListRow['hotel']['name'] ?? ''));

        $tenantBLogId = (int)Db::name('operation_logs')->where('tenant_id', 202)->value('id');
        $detail = $this->responseData($controller->detail((new Request())->withGet(['id' => $tenantBLogId])));
        self::assertSame(202, (int)$detail['tenant_id']);
        self::assertSame(20, (int)($detail['hotel']['id'] ?? 0));
        self::assertSame('Hotel B1', (string)($detail['hotel']['name'] ?? ''));

        $today = date('Y-m-d');
        $stats = $this->responseData($controller->stats((new Request())->withGet([
            'start_date' => $today,
            'end_date' => $today,
        ])));
        self::assertSame(2, array_sum(array_map(
            static fn(array $row): int => (int)$row['count'],
            $stats['module_stats']
        )));
    }

    public function testPublicEndpointSecurityScopesNormalUserAndExplicitlyBypassesForSuperAdmin(): void
    {
        $now = date('Y-m-d H:i:s');
        $extraData = json_encode(['reason' => 'invalid_token', 'status' => 403], JSON_THROW_ON_ERROR);
        foreach ([101, 202] as $tenantId) {
            Db::name('operation_logs')->where('tenant_id', $tenantId)->update([
                'module' => 'online_data',
                'action' => 'cron_trigger_public_failure',
                'extra_data' => $extraData,
                'create_time' => $now,
            ]);
        }
        Db::name('competitor_device')->insertAll([
            ['tenant_id' => 101, 'status' => 1, 'token_hash' => 'tenant-a-device'],
            ['tenant_id' => 202, 'status' => 1, 'token_hash' => 'tenant-b-device'],
        ]);

        $normalUser = User::find(501);
        self::assertInstanceOf(User::class, $normalUser);
        self::$app->request->user = $normalUser;
        $normalData = $this->responseData((new OnlineData(self::$app))->publicEndpointSecurity());
        $normalCron = current(array_filter(
            $normalData['endpoints'],
            static fn(array $row): bool => $row['endpoint'] === 'cron_trigger'
        ));
        self::assertIsArray($normalCron);
        self::assertSame(1, (int)$normalCron['recent_failure_count']);
        $normalTask = current(array_filter(
            $normalData['endpoints'],
            static fn(array $row): bool => $row['endpoint'] === 'competitor_task'
        ));
        self::assertIsArray($normalTask);
        self::assertSame(1, (int)$normalTask['active_binding_count']);

        $admin = new User();
        $admin->id = 1;
        $admin->tenant_id = 0;
        $admin->role_id = Role::SUPER_ADMIN;
        self::$app->request->user = $admin;
        $adminData = $this->responseData((new OnlineData(self::$app))->publicEndpointSecurity());
        $adminCron = current(array_filter(
            $adminData['endpoints'],
            static fn(array $row): bool => $row['endpoint'] === 'cron_trigger'
        ));
        self::assertIsArray($adminCron);
        self::assertSame(2, (int)$adminCron['recent_failure_count']);
        $adminTask = current(array_filter(
            $adminData['endpoints'],
            static fn(array $row): bool => $row['endpoint'] === 'competitor_task'
        ));
        self::assertIsArray($adminTask);
        self::assertSame(2, (int)$adminTask['active_binding_count']);
    }

    public function testSuperAdminDailyReportUsesAuthoritativeHotelTenantForWrite(): void
    {
        Db::name('hotels')->where('id', 20)->update(['tenant_id' => 10]);
        $admin = new User();
        $admin->id = 1;
        $admin->tenant_id = 0;
        $admin->role_id = Role::SUPER_ADMIN;
        self::$app->request->user = $admin;

        $controller = new DailyReportController(self::$app);
        $method = (new \ReflectionClass($controller))->getMethod('tenantIdForHotel');
        $method->setAccessible(true);
        $tenantId = (int)$method->invoke($controller, 20);
        self::assertSame(10, $tenantId);

        $report = new DailyReport();
        $report->tenant_id = $tenantId;
        $report->hotel_id = 20;
        $report->label = 'superadmin_authoritative_tenant';
        $report->save();

        self::assertSame(10, (int)Db::name('daily_reports')->where('id', $report->id)->value('tenant_id'));
        self::assertNotNull(DailyReport::withoutTenantScope()->find($report->id));

        $invalidReport = new DailyReport();
        $invalidReport->tenant_id = 999;
        $invalidReport->hotel_id = 20;
        $invalidReport->label = 'wrong_superadmin_tenant';
        try {
            $invalidReport->save();
            self::fail('Superadmin writes must reject a tenant that conflicts with the hotel mapping.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
        self::assertSame(0, Db::name('daily_reports')->where('label', 'wrong_superadmin_tenant')->count());
    }

    public function testSuperAdminBusinessWriteRequiresExplicitTenantOrHotelMapping(): void
    {
        $admin = new User();
        $admin->id = 1;
        $admin->tenant_id = 0;
        $admin->role_id = Role::SUPER_ADMIN;
        self::$app->request->user = $admin;

        try {
            DailyReport::create(['label' => 'missing_tenant_and_hotel']);
            self::fail('Superadmin business writes without tenant facts must be rejected.');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->getStatusCode());
        }
        self::assertSame(0, Db::name('daily_reports')->where('label', 'missing_tenant_and_hotel')->count());

        $report = DailyReport::create([
            'tenant_id' => 101,
            'label' => 'explicit_superadmin_tenant',
        ]);
        self::assertSame(101, (int)$report->tenant_id);
        self::assertSame(101, (int)Db::name('daily_reports')->where('id', $report->id)->value('tenant_id'));
    }

    public function testSuperAdminCanUpdateAndDeleteExistingCrossTenantRecordsWithoutTenantMutation(): void
    {
        $admin = new User();
        $admin->id = 1;
        $admin->tenant_id = 0;
        $admin->role_id = Role::SUPER_ADMIN;
        self::$app->request->user = $admin;

        Db::name('agent_configs')->where('tenant_id', 202)->update(['hotel_id' => 20]);

        $config = AgentConfig::where('tenant_id', 202)->find();
        self::assertInstanceOf(AgentConfig::class, $config);
        $config->is_enabled = 1;
        $config->save();
        self::assertSame(202, (int)Db::name('agent_configs')->where('id', $config->id)->value('tenant_id'));
        self::assertSame(1, (int)Db::name('agent_configs')->where('id', $config->id)->value('is_enabled'));

        $config->tenant_id = 101;
        try {
            $config->save();
            self::fail('An existing record must never be reassigned to another tenant.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
        self::assertSame(202, (int)Db::name('agent_configs')->where('id', $config->id)->value('tenant_id'));

        $task = MonthlyTask::where('tenant_id', 202)->find();
        self::assertInstanceOf(MonthlyTask::class, $task);
        $taskId = (int)$task->id;
        self::assertTrue($task->delete());
        self::assertNull(Db::name('monthly_tasks')->where('id', $taskId)->find());
    }

    public function testAgentConfigRejectsZeroHotelForNormalUserAndSuperAdmin(): void
    {
        $normalUser = User::find(501);
        self::assertInstanceOf(User::class, $normalUser);
        self::$app->request->user = $normalUser;
        foreach ([$normalUser, $this->superAdminActor()] as $actor) {
            self::$app->request->user = $actor;
            try {
                AgentConfig::create([
                    'hotel_id' => 0,
                    'agent_type' => AgentConfig::AGENT_TYPE_REVENUE,
                    'is_enabled' => 1,
                    'config_data' => [],
                ]);
                self::fail('AgentConfig hotel_id=0 must be rejected for every actor.');
            } catch (HttpException $exception) {
                self::assertSame(422, $exception->getStatusCode());
            }
        }
    }

    public function testDailyReportRawOtaReadRejectsConflictingTenantRowsAndMissingMappings(): void
    {
        Db::name('online_daily_data')->insertAll([
            [
                'tenant_id' => 101,
                'system_hotel_id' => 10,
                'data_date' => '2026-07-22',
                'label' => 'authoritative_row',
            ],
            [
                'tenant_id' => 202,
                'system_hotel_id' => 10,
                'data_date' => '2026-07-22',
                'label' => 'polluted_cross_tenant_row',
            ],
        ]);
        $normalUser = User::find(501);
        self::assertInstanceOf(User::class, $normalUser);
        self::$app->request->user = $normalUser;

        $controller = new DailyReportController(self::$app);
        $method = (new \ReflectionClass($controller))->getMethod('dailyOtaRows');
        $method->setAccessible(true);
        $status = null;
        $rows = $method->invokeArgs($controller, [10, '2026-07-22', &$status]);

        self::assertSame('ok', $status);
        self::assertSame(['authoritative_row'], array_column($rows, 'label'));

        $missingStatus = null;
        $missingRows = $method->invokeArgs($controller, [999, '2026-07-22', &$missingStatus]);
        self::assertSame([], $missingRows);
        self::assertSame('scope_missing', $missingStatus);
    }

    public function testDailyReportExportWatermarkUsesAuthoritativeHotelTenantsAndMarksMixedScope(): void
    {
        self::$app->request->user = $this->superAdminActor();
        $controller = new DailyReportController(self::$app);
        $method = new \ReflectionMethod($controller, 'buildExportWatermark');
        $method->setAccessible(true);

        $single = $method->invoke($controller, 10, 1, [10]);
        self::assertSame(101, $single['tenant_id']);
        self::assertNotSame(10, $single['tenant_id']);
        self::assertSame([101], $single['tenant_ids']);
        self::assertSame('single', $single['tenant_scope']);

        $sameTenantHotels = $method->invoke($controller, null, 2, [10, 11]);
        self::assertSame(101, $sameTenantHotels['tenant_id']);
        self::assertSame([101], $sameTenantHotels['tenant_ids']);

        $mixed = $method->invoke($controller, null, 2, [10, 20]);
        self::assertNull($mixed['tenant_id']);
        self::assertSame([101, 202], $mixed['tenant_ids']);
        self::assertSame('mixed', $mixed['tenant_scope']);
        self::assertStringContainsString('tenant=mixed[101,202]', $mixed['text']);
    }

    public function testCliBusinessWriteRequiresExplicitTenantOrAuthoritativeHotel(): void
    {
        self::$app->request->user = null;
        try {
            DailyReport::create(['label' => 'cli_without_scope']);
            self::fail('CLI business writes without tenant or hotel scope must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('positive tenant id', $exception->getMessage());
        }
        self::assertSame(0, Db::name('daily_reports')->where('label', 'cli_without_scope')->count());

        $explicit = DailyReport::create(['tenant_id' => 101, 'label' => 'cli_explicit_tenant']);
        self::assertGreaterThan(0, (int)$explicit->id);
        self::assertSame(101, (int)Db::name('daily_reports')->where('id', (int)$explicit->id)->value('tenant_id'));
    }

    public function testDeletedHotelAuditUsesPrevalidatedTenantWithoutHotelFallback(): void
    {
        Db::name('hotels')->where('id', 10)->delete();
        self::$app->request->user = null;

        $log = OperationLog::record(
            'hotel',
            'delete',
            'Deleted hotel after tenant scope was prevalidated',
            null,
            10,
            null,
            ['tenant_id' => 101, 'prevalidated_tenant' => true]
        );

        $stored = Db::name('operation_logs')->where('id', (int)$log->id)->find();
        self::assertNotNull($stored);
        self::assertSame(101, (int)$stored['tenant_id']);
        self::assertSame(10, (int)$stored['hotel_id']);
    }

    public function testNormalUserWithoutTenantFailsClosed(): void
    {
        $user = new User();
        $user->id = 502;
        $user->tenant_id = 0;
        $user->role_id = 3;
        self::$app->request->user = $user;

        try {
            DailyReport::select();
            self::fail('A normal user without tenant context must fail closed.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
    }

    public function testHttpRequestWithoutAuthenticatedTenantFailsClosed(): void
    {
        $originalRequest = self::$app->request;
        $httpRequest = new class extends Request {
            public function isCli(): bool
            {
                return false;
            }
        };
        self::$app->instance('request', $httpRequest);

        try {
            DailyReport::select();
            self::fail('An HTTP request without authenticated tenant context must fail closed.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        } finally {
            self::$app->instance('request', $originalRequest);
        }
    }

    public function testNormalUserCannotDisableTenantScope(): void
    {
        $user = User::find(501);
        self::assertInstanceOf(User::class, $user);
        self::$app->request->user = $user;

        try {
            DailyReport::withoutTenantScope();
            self::fail('A normal user must not bypass tenant isolation.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }

        try {
            DailyReport::withoutGlobalScope(['tenant']);
            self::fail('ThinkORM generic scope bypass must enforce the same authorization.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
    }

    public function testQueryWithoutScopeCannotRemoveTenantPredicate(): void
    {
        $user = User::find(501);
        self::assertInstanceOf(User::class, $user);
        self::$app->request->user = $user;

        $rows = DailyReport::where('id', '>', 0)->withoutScope()->select()->toArray();
        self::assertSame([101], array_values(array_unique(array_map(
            static fn(array $row): int => (int)$row['tenant_id'],
            $rows
        ))));
    }

    private function createSchema(): void
    {
        Db::execute('CREATE TABLE roles (
            id INTEGER PRIMARY KEY,
            name VARCHAR(50) NOT NULL,
            display_name VARCHAR(100),
            level INTEGER NOT NULL,
            permissions TEXT,
            status INTEGER NOT NULL DEFAULT 1
        )');
        Db::execute('CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            username VARCHAR(50) NOT NULL,
            realname VARCHAR(100),
            password VARCHAR(255) NOT NULL,
            role_id INTEGER NOT NULL,
            hotel_id INTEGER,
            status INTEGER NOT NULL DEFAULT 1
        )');
        Db::execute('CREATE TABLE hotels (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            name VARCHAR(100) NOT NULL,
            status INTEGER NOT NULL DEFAULT 1,
            owner_user_id INTEGER,
            created_by INTEGER,
            create_time DATETIME,
            update_time DATETIME
        )');
        Db::execute('CREATE TABLE user_hotel_permissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            hotel_id INTEGER NOT NULL,
            can_view INTEGER NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT \'active\',
            expires_at DATETIME
        )');
        Db::execute('CREATE TABLE competitor_device (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            status INTEGER NOT NULL DEFAULT 1,
            revoked_at DATETIME,
            token_hash VARCHAR(255)
        )');

        foreach ([
            'daily_reports',
            'monthly_tasks',
            'online_daily_data',
            'operation_logs',
            'platform_data_sources',
            'agent_configs',
            'agent_logs',
            'price_suggestions',
            'demand_forecasts',
        ] as $table) {
            Db::execute("CREATE TABLE {$table} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                label VARCHAR(100),
                hotel_id INTEGER,
                system_hotel_id INTEGER,
                agent_type INTEGER,
                is_enabled INTEGER,
                config_data TEXT,
                action VARCHAR(100),
                module VARCHAR(100),
                message TEXT,
                description TEXT,
                error_info TEXT,
                extra_data TEXT,
                ip VARCHAR(50),
                user_agent VARCHAR(255),
                log_level INTEGER,
                context_data TEXT,
                user_id INTEGER,
                forecast_date DATE,
                data_date DATE,
                room_type_id INTEGER,
                forecast_method INTEGER,
                predicted_occupancy REAL,
                predicted_demand INTEGER,
                confidence_score REAL,
                is_event_driven INTEGER,
                event_factors TEXT,
                historical_data TEXT,
                remark TEXT,
                create_time DATETIME,
                update_time DATETIME
            )");
        }
    }

    private function seedTenantFixture(): void
    {
        Db::name('roles')->insert([
            'id' => 3,
            'name' => 'tenant_operator',
            'display_name' => 'Tenant operator',
            'level' => 2,
            'permissions' => json_encode(['hotel.view', 'can_view_online_data'], JSON_THROW_ON_ERROR),
            'status' => 1,
        ]);
        Db::name('users')->insert([
            'id' => 501,
            'tenant_id' => 101,
            'username' => 'tenant_a_user',
            'password' => password_hash('Strong123!', PASSWORD_DEFAULT),
            'role_id' => 3,
            'hotel_id' => 10,
            'status' => 1,
        ]);
        Db::name('users')->insert([
            'id' => 502,
            'tenant_id' => 202,
            'username' => 'tenant_b_user',
            'password' => password_hash('Strong123!', PASSWORD_DEFAULT),
            'role_id' => 3,
            'hotel_id' => 20,
            'status' => 1,
        ]);
        Db::name('hotels')->insertAll([
            ['id' => 10, 'tenant_id' => 101, 'name' => 'Hotel A1', 'status' => 1],
            ['id' => 11, 'tenant_id' => 101, 'name' => 'Hotel A2', 'status' => 1],
            ['id' => 20, 'tenant_id' => 202, 'name' => 'Hotel B1', 'status' => 1],
        ]);
        Db::name('user_hotel_permissions')->insertAll([
            ['tenant_id' => 101, 'user_id' => 501, 'hotel_id' => 11, 'can_view' => 1, 'status' => 'active'],
            ['tenant_id' => 202, 'user_id' => 501, 'hotel_id' => 20, 'can_view' => 1, 'status' => 'active'],
        ]);

        foreach ([
            'daily_reports',
            'monthly_tasks',
            'online_daily_data',
            'operation_logs',
            'platform_data_sources',
            'agent_configs',
            'agent_logs',
            'price_suggestions',
            'demand_forecasts',
        ] as $table) {
            Db::name($table)->insertAll([
                ['tenant_id' => 101, 'label' => $table . '_tenant_a'],
                ['tenant_id' => 202, 'label' => $table . '_tenant_b'],
            ]);
        }
    }

    /** @return list<class-string<BaseTenantModel>> */
    private function tenantModelClasses(): array
    {
        return [
            Hotel::class,
            DailyReport::class,
            MonthlyTask::class,
            OnlineDailyData::class,
            OperationLog::class,
            PlatformDataSource::class,
            AgentConfig::class,
            AgentLog::class,
            PriceSuggestion::class,
            DemandForecast::class,
        ];
    }

    private function superAdminActor(): User
    {
        $admin = new User();
        $admin->id = 1;
        $admin->tenant_id = 0;
        $admin->role_id = Role::SUPER_ADMIN;
        return $admin;
    }

    /** @return array<string, mixed> */
    private function responseData(\think\Response $response): array
    {
        $payload = json_decode((string)$response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(200, $payload['code']);
        self::assertIsArray($payload['data']);

        return $payload['data'];
    }
}
