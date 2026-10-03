<?php
declare(strict_types=1);

namespace Tests;

use app\middleware\Auth;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use think\App;
use think\exception\RouteNotFoundException;
use think\Request;
use think\route\Dispatch;
use think\route\dispatch\Controller;

require_once __DIR__ . '/InvestmentPaybackRoutingTest.php';
require_once __DIR__ . '/Support/InvestmentScenarioFixture.php';

final class BookingMonitoringRoutingTest extends TestCase
{
    private InvestmentPaybackRoutingProbe $router;

    protected function setUp(): void
    {
        $app = new App(dirname(__DIR__));
        $app->setRuntimePath(sys_get_temp_dir() . '/suxios-booking-route-' . bin2hex(random_bytes(8)) . '/');
        $app->config->set(require dirname(__DIR__) . '/config/route.php', 'route');
        $this->router = new InvestmentPaybackRoutingProbe($app);
        $app->instance('route', $this->router);
        require dirname(__DIR__) . '/route/app.php';
    }

    public function testExactRoutesKeepParametersAndRejectAnonymousRequestsBeforeActions(): void
    {
        foreach ([
            ['GET', '/api/booking-monitoring/overview', 'overview', []],
            ['POST', '/api/booking-monitoring/snapshots', 'saveSnapshots', []],
            ['GET', '/api/booking-monitoring/snapshots/37', 'readSnapshot', ['id' => '37']],
        ] as [$method, $path, $action, $parameters]) {
            $request = $this->request($method, $path);
            $dispatch = $this->router->resolve($request);
            self::assertInstanceOf(Controller::class, $dispatch);
            self::assertSame(['BookingMonitoring', $action], $dispatch->getDispatch());
            self::assertSame($parameters, $dispatch->getParam());
            $rule = (new ReflectionProperty(Dispatch::class, 'rule'))->getValue($dispatch);
            self::assertTrue($rule->getOption('complete_match'), $path);
            $middlewares = array_map(static fn($item) => is_array($item) ? $item[0] : $item,
                $rule->getOption('middleware', []));
            self::assertContains(Auth::class, $middlewares);
            self::assertNotContains(Auth::class, $rule->getOption('without_middleware', []));
            $response = (new Auth())->handle($request, static function (): never {
                self::fail('Anonymous requests must not execute a monitoring action.');
            });
            self::assertSame(401, $response->getCode());
        }
    }

    public function testWrongMethodsAndExtraChildrenCannotResolveToAnotherAction(): void
    {
        foreach ([
            ['POST', '/api/booking-monitoring/overview'],
            ['GET', '/api/booking-monitoring/overview/extra'],
            ['GET', '/api/booking-monitoring/snapshots'],
            ['POST', '/api/booking-monitoring/snapshots/37'],
            ['DELETE', '/api/booking-monitoring/snapshots/37'],
            ['GET', '/api/booking-monitoring/snapshots/37/extra'],
        ] as [$method, $path]) {
            try {
                $this->router->resolve($this->request($method, $path));
                self::fail($method . ' ' . $path . ' must be rejected.');
            } catch (RouteNotFoundException) {
                self::assertTrue(true);
            }
        }
    }

    public function testSelectableScopeExcludesHotelsWithoutTheRequestedOperationCapability(): void
    {
        $path = sys_get_temp_dir().'/investment-scenario-test-'.bin2hex(random_bytes(6)).'.sqlite';
        \Tests\Support\InvestmentScenarioFixture::connect($path);
        \Tests\Support\InvestmentScenarioFixture::schema();
        try {
            $class = new \ReflectionClass(\app\controller\BookingMonitoring::class);
            $controller = $class->newInstanceWithoutConstructor();
            $user = new class {
                public function getPermittedHotelIds(): array { return [80,81,90]; }
                public function hasHotelPermission(int $id,string $capability): bool { return $id !== 81; }
            };
            $class->getProperty('currentUser')->setValue($controller,$user);
            [$tenant,$permitted] = $class->getMethod('scope')->invoke($controller,[80],'operation.view');
            self::assertSame(10,$tenant);
            self::assertSame([80],$permitted);
            try { $class->getMethod('scope')->invoke($controller,[81],'operation.view'); self::fail('Hotel without operation.view must be rejected'); }
            catch (\RuntimeException $error) { self::assertSame(403,$error->getCode()); }
        } finally {
            \think\facade\Db::connect('investment_scenario_test')->close();
            @unlink($path);
        }
    }

    private function request(string $method, string $path): Request
    {
        return (new Request())->setMethod($method)->setUrl($path)->setBaseUrl($path)
            ->setPathinfo(ltrim($path, '/'))->withHeader(['accept' => 'application/json', 'authorization' => '']);
    }

    public function testOverviewRetainsViewOnlyHotelsAndDeclaresPerHotelWriteEligibility(): void
    {
        $path = sys_get_temp_dir().'/investment-scenario-test-'.bin2hex(random_bytes(6)).'.sqlite';
        \Tests\Support\InvestmentScenarioFixture::connect($path);
        \Tests\Support\InvestmentScenarioFixture::schema();
        try {
            \think\facade\Db::execute("ALTER TABLE hotels ADD COLUMN name TEXT DEFAULT 'synthetic-hotel'");
            \think\facade\Db::execute('CREATE TABLE room_types (id INTEGER PRIMARY KEY,hotel_id INTEGER,name TEXT,is_enabled INTEGER)');
            $columns='id INTEGER PRIMARY KEY,contract_version TEXT,tenant_id INTEGER,hotel_id INTEGER,source_hotel_id INTEGER,platform TEXT,fact_scope TEXT,stay_date TEXT,captured_at TEXT,source_method TEXT,source_ref_hash TEXT,on_books_room_nights REAL,on_books_room_revenue REAL,cumulative_cancel_room_nights REAL,gross_booking_room_nights REAL,quality_status TEXT,readback_verified INTEGER,idempotency_key TEXT,content_digest TEXT,created_by INTEGER,created_at TEXT';
            \think\facade\Db::execute('CREATE TABLE hotel_on_books_snapshots ('.$columns.')');
            \think\facade\Db::execute('CREATE TABLE hotel_room_type_on_books_snapshots ('.$columns.',room_type_id INTEGER,room_type_name TEXT,supersedes_snapshot_id INTEGER)');
            $class=new \ReflectionClass(\app\controller\BookingMonitoring::class);
            $controller=$class->newInstanceWithoutConstructor();
            $user=new class {
                public function getPermittedHotelIds(): array { return [80,81,90]; }
                public function hasHotelPermission(int $id,string $capability): bool { return $capability==='operation.view' || $id===80; }
            };
            $request=new class {
                public function param(string $key,mixed $default=null): mixed { return ['hotel_ids'=>'80,81','platform'=>'ctrip','business_date'=>'2026-10-02','fixed_time'=>'09:00','horizon_days'=>1][$key] ?? $default; }
            };
            $class->getProperty('currentUser')->setValue($controller,$user);
            $class->getProperty('request')->setValue($controller,$request);
            $response=$controller->overview();
            self::assertSame(200,$response->getCode(),$response->getContent());
            $data=$response->getData()['data'];
            self::assertSame([80,81],array_column($data['selectable_hotels'],'id'));
            self::assertSame([true,false],array_column($data['selectable_hotels'],'can_execute'));
            self::assertSame([80,81],$data['hotel_ids']);
            self::assertSame([80,81],array_values(array_unique(array_column($data['cells'],'hotel_id'))));
        } finally {
            \think\facade\Db::connect('investment_scenario_test')->close();
            @unlink($path);
        }
    }

    public function testOutOfRangeSnapshotMetricsReturnAnActionableValidationError(): void
    {
        $class = new \ReflectionClass(\app\controller\BookingMonitoring::class);
        $controller = $class->newInstanceWithoutConstructor();
        foreach (['on_books_room_nights'=>'在手间夜','on_books_room_revenue'=>'在手房费',
            'cumulative_cancel_room_nights'=>'累计取消间夜','gross_booking_room_nights'=>'累计毛预订间夜'] as $field=>$label) {
            $response = $class->getMethod('failure')->invoke($controller,new \InvalidArgumentException($field.'_out_of_range'),'预订快照保存失败，整批未完成');
            self::assertSame(422,$response->getCode());
            self::assertStringContainsString($label,$response->getData()['message']);
            self::assertStringContainsString('9,999,999,999.9999',$response->getData()['message']);
            self::assertSame($field.'_out_of_range',$response->getData()['data']['reason_code']);
            self::assertFalse($response->getData()['data']['readback_verified']);
        }
    }

    public function testOversizedMatrixReturnsAnActionableScopeError(): void
    {
        $class = new \ReflectionClass(\app\controller\BookingMonitoring::class);
        $controller = $class->newInstanceWithoutConstructor();
        $response = $class->getMethod('failure')->invoke($controller,
            new \RuntimeException('booking_monitor_cell_limit_narrow_scope',422),'固定基线预订读取失败');
        self::assertSame(422,$response->getCode());
        self::assertStringContainsString('1,000格',$response->getData()['message']);
        self::assertStringContainsString('减少酒店或展示天数',$response->getData()['message']);
        self::assertSame('booking_monitor_cell_limit_narrow_scope',$response->getData()['data']['reason_code']);
        self::assertFalse($response->getData()['data']['readback_verified']);
        self::assertSame(0,$response->getData()['data']['external_write_count']);
    }

    public function testHotelScopeParserRejectsBooleanFloatAndNestedArrayIdentities(): void
    {
        $class=new \ReflectionClass(\app\controller\BookingMonitoring::class);
        $controller=$class->newInstanceWithoutConstructor();
        foreach ([true,false,80.0,[true],[false],[80.0],[[80]]] as $value) {
            $rejected=false;
            try { $class->getMethod('hotelIds')->invoke($controller,$value); }
            catch (\InvalidArgumentException $error) {
                $rejected=true;
                $response=$class->getMethod('failure')->invoke($controller,$error,'固定基线预订读取失败');
                self::assertSame(422,$response->getCode());
                self::assertStringContainsString('整数',$response->getData()['message']);
                self::assertFalse($response->getData()['data']['readback_verified']);
            }
            self::assertTrue($rejected,'Malformed hotel identities must be rejected before any permission lookup');
        }
        foreach ([80,'80',[80],['80']] as $value) self::assertSame([80],$class->getMethod('hotelIds')->invoke($controller,$value));
    }

    public function testScopeChecksOnlyEnabledCandidatesInTheSelectedTenant(): void
    {
        $path = sys_get_temp_dir().'/investment-scenario-test-'.bin2hex(random_bytes(6)).'.sqlite';
        \Tests\Support\InvestmentScenarioFixture::connect($path);
        \Tests\Support\InvestmentScenarioFixture::schema();
        try {
            \think\facade\Db::execute('INSERT INTO hotels VALUES (82,10,1),(83,10,0)');
            $user = new class {
                public array $permitted = [80,81,82,83,90];
                public array $calls = [];
                // A duck-typed flag must not activate the real User super-admin policy.
                public function isSuperAdmin(): bool { return true; }
                public function getPermittedHotelIds(): array { return $this->permitted; }
                public function hasHotelPermission(int $id,string $capability): bool {
                    $this->calls[] = $id;
                    \think\facade\Db::name('hotels')->where('id',$id)->value('status');
                    return $capability === 'operation.view' && in_array($id,[80,82],true);
                }
            };
            $class = new \ReflectionClass(\app\controller\BookingMonitoring::class);
            $controller = $class->newInstanceWithoutConstructor();
            $class->getProperty('currentUser')->setValue($controller,$user);
            $callCounts = $queryCounts = $results = [];
            foreach ([0,500] as $foreignCount) {
                if ($foreignCount > 0) {
                    $foreign = [];
                    for ($i=0; $i<$foreignCount; $i++) {
                        $foreign[] = ['id'=>1000+$i,'tenant_id'=>20+$i%50,'status'=>1];
                        $user->permitted[] = 1000+$i;
                    }
                    \think\facade\Db::name('hotels')->insertAll($foreign);
                }
                $user->calls = [];
                $queries = $this->captureSql(function () use ($class,$controller,&$results): void {
                    $results[] = $class->getMethod('scope')->invoke($controller,[80],'operation.view');
                });
                $callCounts[] = count($user->calls);
                $queryCounts[] = count(array_filter($queries,static fn(string $sql): bool => preg_match('/^SELECT\b/i',$sql) === 1));
            }
            self::assertSame([3,3],$callCounts,'Foreign or disabled hotels must not cause permission lookups');
            self::assertSame([5,5],$queryCounts,'Only two bulk scope SELECTs and three local permission SELECTs are needed');
            self::assertSame([[10,[80,82]],[10,[80,82]]],$results);
            self::assertSame([80,81,82],$user->calls);
            foreach ([[[83],'operation.view'],[[81],'operation.execute'],[[80,90],'operation.view']] as [$ids,$capability]) {
                try { $class->getMethod('scope')->invoke($controller,$ids,$capability); self::fail('Disabled, unauthorized or mixed-tenant scope must fail'); }
                catch (\RuntimeException $error) { self::assertSame(403,$error->getCode()); }
            }
        } finally {
            \think\facade\Db::connect('investment_scenario_test')->close();
            @unlink($path);
        }
    }

    public function testRealSuperAdminOverviewUsesConstantQueriesAcrossHotelPopulationSizes(): void
    {
        $path = sys_get_temp_dir().'/investment-scenario-test-'.bin2hex(random_bytes(6)).'.sqlite';
        \Tests\Support\InvestmentScenarioFixture::connect($path);
        \Tests\Support\InvestmentScenarioFixture::schema();
        $runtimeRequest = request();
        $originalRuntimeUser = $runtimeRequest->user ?? null;
        try {
            $this->createMonitorTables();
            \think\facade\Db::execute('INSERT INTO hotels (id,tenant_id,status) VALUES (82,10,0)');
            $queryCounts = $hotelQueryCounts = [];
            foreach ([2,200] as $localCount) {
                if ($localCount > 2) {
                    $hotels = [];
                    for ($i=0; $i<198; $i++) $hotels[] = ['id'=>100+$i,'tenant_id'=>10,'status'=>1];
                    for ($i=0; $i<500; $i++) $hotels[] = ['id'=>1000+$i,'tenant_id'=>20+$i%50,'status'=>1];
                    foreach (array_chunk($hotels,200) as $batch) \think\facade\Db::name('hotels')->insertAll($batch);
                }
                // Use the actual model and its unmodified permission services.
                $user = new \app\model\User(['id'=>7,'tenant_id'=>10,'role_id'=>\app\model\Role::SUPER_ADMIN,'status'=>1]);
                $runtimeRequest->user = $user;
                $class = new \ReflectionClass(\app\controller\BookingMonitoring::class);
                $controller = $class->newInstanceWithoutConstructor();
                $request = new class {
                    public function param(string $key,mixed $default=null): mixed { return ['hotel_ids'=>'80','business_date'=>'2026-10-02','horizon_days'=>1][$key] ?? $default; }
                };
                $class->getProperty('currentUser')->setValue($controller,$user);
                $class->getProperty('request')->setValue($controller,$request);
                $response = null;
                $queries = $this->captureSql(function () use ($controller,&$response): void { $response = $controller->overview(); });
                self::assertSame(200,$response->getCode(),$response->getContent());
                $data = $response->getData()['data'];
                self::assertCount($localCount,$data['selectable_hotels']);
                self::assertSame([80],$data['hotel_ids']);
                self::assertNotContains(82,array_column($data['selectable_hotels'],'id'));
                foreach ($data['selectable_hotels'] as $hotel) {
                    self::assertSame(10,$hotel['tenant_id']);
                    self::assertSame(true,$hotel['can_execute']);
                }
                $queryCounts[] = count(array_filter($queries,static fn(string $sql): bool => preg_match('/^SELECT\b/i',$sql) === 1));
                $hotelQueryCounts[] = count(array_filter($queries,static fn(string $sql): bool => preg_match('/^SELECT\b.*\bFROM\s+[`"]?hotels[`"]?\b/i',$sql) === 1));
            }
            self::assertSame([7,7],$queryCounts,'Actual super-admin overview must have a constant number of business SELECTs');
            self::assertSame([4,4],$hotelQueryCounts,'Selected identity, local candidates and service scope/selectable reads are bulk queries');
        } finally {
            $runtimeRequest->user = $originalRuntimeUser;
            \think\facade\Db::connect('investment_scenario_test')->close();
            @unlink($path);
        }
    }

    public function testRealUserScopeKeepsEnabledAndTenantPolicyWhenUsingBulkCandidates(): void
    {
        $path = sys_get_temp_dir().'/investment-scenario-test-'.bin2hex(random_bytes(6)).'.sqlite';
        \Tests\Support\InvestmentScenarioFixture::connect($path);
        \Tests\Support\InvestmentScenarioFixture::schema();
        $runtimeRequest = request();
        $originalRuntimeUser = $runtimeRequest->user ?? null;
        try {
            \think\facade\Db::execute('INSERT INTO hotels VALUES (82,10,0)');
            $class = new \ReflectionClass(\app\controller\BookingMonitoring::class);
            $controller = $class->newInstanceWithoutConstructor();
            $superAdmin = new \app\model\User(['id'=>7,'tenant_id'=>10,'role_id'=>\app\model\Role::SUPER_ADMIN]);
            $runtimeRequest->user = $superAdmin;
            $class->getProperty('currentUser')->setValue($controller,$superAdmin);
            self::assertTrue($superAdmin->hasHotelPermission(80,'operation.view'));
            self::assertTrue($superAdmin->hasHotelPermission(80,'operation.execute'));
            self::assertFalse($superAdmin->hasHotelPermission(82,'operation.view'));
            self::assertTrue($superAdmin->hasHotelPermission(90,'operation.execute'));
            foreach ([[82],[80,90],[999]] as $ids) {
                try { $class->getMethod('scope')->invoke($controller,$ids,'operation.view'); self::fail('Disabled, missing or mixed-tenant hotels must fail for a real super admin'); }
                catch (\RuntimeException $error) { self::assertSame(403,$error->getCode(),$error->getMessage()); }
            }
            self::assertSame([20,[90]],$class->getMethod('scope')->invoke($controller,[90],'operation.execute'),'Real super admins may select one other tenant, under the existing policy');
            $normal = new \app\model\User(['id'=>8,'tenant_id'=>10,'role_id'=>\app\model\Role::NORMAL_USER]);
            $normal->setRelation('role',new \app\model\Role(['id'=>\app\model\Role::NORMAL_USER,'status'=>1,'permissions'=>[]]));
            $runtimeRequest->user = $normal;
            $class->getProperty('currentUser')->setValue($controller,$normal);
            try { $class->getMethod('scope')->invoke($controller,[90],'operation.view'); self::fail('A real normal user cannot select another tenant'); }
            catch (\RuntimeException $error) { self::assertSame(403,$error->getCode()); }
            $class->getProperty('currentUser')->setValue($controller,null);
            try { $class->getMethod('scope')->invoke($controller,[80],'operation.view'); self::fail('Anonymous scope must fail'); }
            catch (\RuntimeException $error) { self::assertSame(401,$error->getCode()); }
        } finally {
            $runtimeRequest->user = $originalRuntimeUser;
            \think\facade\Db::connect('investment_scenario_test')->close();
            @unlink($path);
        }
    }

    private function createMonitorTables(): void
    {
        \think\facade\Db::execute("ALTER TABLE hotels ADD COLUMN name TEXT DEFAULT 'TEST-ONLY synthetic hotel'");
        \think\facade\Db::execute('CREATE TABLE room_types (id INTEGER PRIMARY KEY,hotel_id INTEGER,name TEXT,is_enabled INTEGER)');
        $columns='id INTEGER PRIMARY KEY,contract_version TEXT,tenant_id INTEGER,hotel_id INTEGER,source_hotel_id INTEGER,platform TEXT,fact_scope TEXT,stay_date TEXT,captured_at TEXT,source_method TEXT,source_ref_hash TEXT,on_books_room_nights REAL,on_books_room_revenue REAL,cumulative_cancel_room_nights REAL,gross_booking_room_nights REAL,quality_status TEXT,readback_verified INTEGER,idempotency_key TEXT,content_digest TEXT,created_by INTEGER,created_at TEXT';
        \think\facade\Db::execute('CREATE TABLE hotel_on_books_snapshots ('.$columns.')');
        \think\facade\Db::execute('CREATE TABLE hotel_room_type_on_books_snapshots ('.$columns.',room_type_id INTEGER,room_type_name TEXT,supersedes_snapshot_id INTEGER)');
    }

    private function captureSql(callable $operation): array
    {
        $connection=\think\facade\Db::connect();
        $configProperty=new \ReflectionProperty($connection,'config');
        $originalConfig=$configProperty->getValue($connection);
        $manager=\think\Container::getInstance()->make(\think\DbManager::class);
        $listenProperty=new \ReflectionProperty($manager,'listen');
        $originalListeners=$listenProperty->getValue($manager);
        $queries=[];
        $configProperty->setValue($connection,array_replace($originalConfig,['trigger_sql'=>true]));
        \think\facade\Db::listen(static function (string $sql) use (&$queries): void { $queries[]=$sql; });
        try { $operation(); }
        finally {
            $configProperty->setValue($connection,$originalConfig);
            $listenProperty->setValue($manager,$originalListeners);
        }
        return $queries;
    }
}
