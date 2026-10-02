<?php
declare(strict_types=1);

namespace Tests;

use app\controller\StrategySimulation;
use app\model\Role;
use app\model\User;
use app\service\ProtectedCapabilityService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Db;
use think\Request;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class StrategyHistoryHotelScopeTest extends TestCase
{
    private App $app;

    protected function setUp(): void
    {
        // No initialize(), .env, account or application DB: all records are synthetic.
        $this->app = new App(dirname(__DIR__));
        $state = sys_get_temp_dir() . '/suxi-strategy-history-' . bin2hex(random_bytes(6)) . '/';
        $this->app->setRuntimePath($state);
        $this->app->config->set(['default' => 'file', 'channels' => ['file' => ['type' => 'File', 'path' => $state . 'log/']]], 'log');
        $this->app->config->set(['default' => 'file', 'stores' => ['file' => ['type' => 'File', 'path' => $state . 'cache/']]], 'cache');
        $this->app->config->set(['default' => 'sqlite', 'connections' => ['sqlite' => [
            'type' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'fields_strict' => false,
        ]]], 'database');
        (new \think\service\ModelService($this->app))->boot();
        Db::connect(null, true);
        Db::execute('CREATE TABLE users (id INTEGER PRIMARY KEY, tenant_id INTEGER, hotel_id INTEGER)');
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER)');
        Db::execute('CREATE TABLE strategy_simulation_records (id INTEGER PRIMARY KEY, tenant_id INTEGER, project_name TEXT, city TEXT, district TEXT, input_json TEXT, data_snapshot_json TEXT, score_json TEXT, recommendation_json TEXT, risk_json TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        Db::execute('CREATE TABLE strategy_data_snapshots (id INTEGER PRIMARY KEY)');
        Db::execute('CREATE TABLE operation_execution_intents (id INTEGER PRIMARY KEY, tenant_id INTEGER, hotel_id INTEGER, source_module TEXT, source_record_id INTEGER, status TEXT, deleted_at TEXT)');
        Db::name('users')->insert(['id' => 3, 'tenant_id' => 9, 'hotel_id' => 7]);
        Db::name('hotels')->insertAll([['id' => 7, 'tenant_id' => 9], ['id' => 8, 'tenant_id' => 9]]);
        $this->put(37, ['hotel_id' => 8]);
        $this->put(38, ['hotel_id' => 7]);
        $this->put(39, ['hotel_id' => 7], 10);
        $this->put(40, ['hotel_id' => 7], 9, 4);
        $this->put(41, []);
    }

    public function testRealAuthorizationForSelectedHotelDoesNotGrantAnotherHotelHistory(): void
    {
        $user = $this->user([7]);
        $capabilities = new ProtectedCapabilityService(['default_enabled_modules' => ['investment']]);
        $capability = $capabilities->classifyPath('GET', '/api/strategy/records/37');
        $authorization = $capabilities->authorizeContext($user, $capability, ['hotel_id' => 7]);
        self::assertTrue($authorization['allowed']);
        $response = $this->controller($user)->detail(37);
        self::assertSame(404, $response->getCode());
        self::assertEmpty($response->getData()['data']);
    }

    public function testAllowedHotelAndUnboundLegacyRecordsRemainReadableWithRealZero(): void
    {
        $controller = $this->controller($this->user([7]));
        foreach ([38, 41] as $id) {
            $response = $controller->detail($id);
            self::assertSame(200, $response->getCode());
            self::assertSame($id, $response->getData()['data']['id']);
            self::assertSame(0, $response->getData()['data']['total_score']);
        }
        self::assertSame([41, 38], array_column($controller->records()->getData()['data']['list'], 'id'));
    }

    public function testHotelFilterRunsBeforeVisibleLimitAndContinuesAcrossCursorBatches(): void
    {
        for ($id = 60; $id < 90; $id++) $this->put($id, ['hotel_id' => 7]);
        for ($id = 500; $id < 650; $id++) $this->put($id, ['hotel_id' => 8]);
        $response = $this->controller($this->user([7]))->records();
        self::assertSame(200, $response->getCode());
        self::assertSame(range(89, 60), array_column($response->getData()['data']['list'], 'id'));
    }

    public function testSelectedHotelNarrowsEvenAnActorWithTwoPermittedHotels(): void
    {
        $controller = $this->controller($this->user([7, 8]));
        self::assertSame(404, $controller->detail(37)->getCode());
        self::assertSame(200, $this->controller($this->user([7, 8]), 8)->detail(37)->getCode());
    }

    public function testTenantAndCreatorIsolationRemainRequired(): void
    {
        $controller = $this->controller($this->user([7, 8]));
        self::assertSame(404, $controller->detail(39)->getCode());
        self::assertSame(404, $controller->detail(40)->getCode());
    }

    public function testConflictingAndMalformedHotelIdentityNeverFallsBackToLegacyAccess(): void
    {
        $controller = $this->controller($this->user([7, 8]));
        foreach ([['hotel_id' => 7, 'system_hotel_id' => 8], ['hotel_id' => true],
            ['hotel_id' => '7-invalid'], ['hotel_id' => -7], ['hotel_id' => [7]]] as $offset => $input) {
            $this->put(70 + $offset, $input);
            self::assertSame(404, $controller->detail(70 + $offset)->getCode(), json_encode($input));
        }
    }

    public function testSnapshotOnlyHotelIdentityStillEnforcesPermission(): void
    {
        $this->put(70, [], 9, 3, ['system_hotel_id' => 8]);
        self::assertSame(404, $this->controller($this->user([7]))->detail(70)->getCode());
        self::assertSame(200, $this->controller($this->user([8]), 8)->detail(70)->getCode());
    }

    public function testRetiredGenerationSnapshotTableIsNotNeededForHistory(): void
    {
        Db::execute('DROP TABLE strategy_data_snapshots');
        $response = $this->controller($this->user([7]))->records();
        self::assertSame(200, $response->getCode());
        self::assertSame([41, 38], array_column($response->getData()['data']['list'], 'id'));
    }

    private function put(int $id, array $input, int $tenant = 9, int $creator = 3, array $snapshot = []): void
    {
        Db::name('strategy_simulation_records')->insert([
            'id' => $id, 'tenant_id' => $tenant, 'project_name' => 'synthetic historical strategy',
            'city' => '', 'district' => '', 'input_json' => json_encode($input),
            'data_snapshot_json' => json_encode($snapshot), 'score_json' => '{"total_score":0}',
            'recommendation_json' => '{}', 'risk_json' => '{}', 'created_by' => $creator,
            'created_at' => '2026-08-13 09:00:00', 'updated_at' => '2026-08-13 09:00:00', 'deleted_at' => null,
        ]);
    }

    private function user(array $hotelIds): User
    {
        $user = new class($hotelIds) extends User {
            public function __construct(private array $allowedHotels) { parent::__construct(['id' => 3, 'tenant_id' => 9, 'hotel_id' => 7, 'role_id' => 2]); }
            public function isSuperAdmin(): bool { return false; }
            public function getPermittedHotelIds(): array { return $this->allowedHotels; }
            public function hasHotelPermission(int $hotelId, string $permission): bool { return in_array($hotelId, $this->allowedHotels, true) && $permission === 'can_use_investment'; }
        };
        $user->setRelation('role', new Role(['id' => 2, 'name' => 'tenant_manager', 'level' => 2, 'status' => 1, 'permissions' => ['can_use_investment']]));
        return $user;
    }

    private function controller(User $user, int $hotelId = 7): StrategySimulation
    {
        $request = (new Request())->withGet(['hotel_id' => $hotelId]);
        $request->user = $user;
        $this->app->instance('request', $request);
        return new StrategySimulation($this->app);
    }
}
