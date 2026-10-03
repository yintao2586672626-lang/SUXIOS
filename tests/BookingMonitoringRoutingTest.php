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
            self::assertSame([80,90],$permitted);
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
}
