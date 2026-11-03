<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Base;
use app\controller\Agent;
use app\controller\StrategySimulation;
use app\controller\Expansion;
use app\controller\TransferDecision;
use app\middleware\Auth;
use app\middleware\RetiredFeatureReadOnly;
use app\service\ExpansionService;
use app\service\TransferDecisionService;
use Closure;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use think\App;
use think\exception\RouteNotFoundException;
use think\Request;
use think\Response;
use think\Route;
use think\route\Dispatch;
use think\route\dispatch\Controller;

final class RetiredFeatureSlimmingTest extends TestCase
{
    private const WRITES = [
        ['POST', '/api/expansion/market-evaluation', 'Expansion', 'marketEvaluation', []],
        ['POST', '/api/expansion/benchmark-model', 'Expansion', 'benchmarkModel', []],
        ['POST', '/api/expansion/collaboration-efficiency', 'Expansion', 'collaborationEfficiency', []],
        ['POST', '/api/expansion/records/37/execution-intent', 'Expansion', 'createExecutionIntent', ['id' => '37']],
        ['DELETE', '/api/expansion/records/market-evaluation', 'Expansion', 'clearMarketEvaluation', []],
        ['DELETE', '/api/expansion/records/37', 'Expansion', 'archive', ['id' => '37']],
        ['DELETE', '/api/expansion/records', 'Expansion', 'clearRecords', []],
        ['POST', '/api/transfer/pricing', 'TransferDecision', 'pricing', []],
        ['POST', '/api/transfer/timing', 'TransferDecision', 'timing', []],
        ['POST', '/api/transfer/dashboard', 'TransferDecision', 'dashboard', []],
        ['POST', '/api/transfer/records/37/execution-intent', 'TransferDecision', 'createExecutionIntent', ['id' => '37']],
        ['DELETE', '/api/transfer/records/37', 'TransferDecision', 'archive', ['id' => '37']],
        ['POST', '/api/strategy/simulate', 'StrategySimulation', 'simulate', []],
        ['POST', '/api/strategy/records/37/execution-intent', 'StrategySimulation', 'createExecutionIntent', ['id' => '37']],
        ['DELETE', '/api/strategy/records/37', 'StrategySimulation', 'archive', ['id' => '37']],
        ['POST', '/api/agent/feasibility-report/generate', 'Agent', 'feasibilityReportGenerate', []],
        ['POST', '/api/agent/feasibility-report/regenerate/37', 'Agent', 'feasibilityReportRegenerate', ['id' => '37']],
        ['POST', '/api/agent/feasibility-report/37/execution-intent', 'Agent', 'createFeasibilityExecutionIntent', ['id' => '37']],
        ['DELETE', '/api/agent/feasibility-report/37', 'Agent', 'feasibilityReportArchive', ['id' => '37']],
    ];

    public function testDirectRetiredActionsRequireLoginAndNeverNeedServiceOrRequestState(): void
    {
        foreach (self::WRITES as [, $path, $name, $action, $parameters]) {
            $class = 'app\\controller\\' . $name;
            // No request or service is initialized: any remaining calculation,
            // database access or input parsing fails this regression test.
            $controller = (new ReflectionClass($class))->newInstanceWithoutConstructor();
            $args = array_map('intval', array_values($parameters));
            self::assertSame(401, $controller->$action(...$args)->getCode(), $path);
            (new ReflectionProperty(Base::class, 'currentUser'))->setValue($controller, (object)['id' => 3]);
            $response = $controller->$action(...$args);
            self::assertSame(410, $response->getCode(), $path);
            self::assertSame('retired_read_only', $response->getData()['data']['status'], $path);
            self::assertTrue($response->getData()['data']['history_preserved'], $path);
            self::assertSame('ops-track', $response->getData()['data']['next_entry'], $path);
        }
    }

    public function testAllRegisteredRetiredWritesPreserveAuthenticationBeforeRetirement(): void
    {
        foreach (self::WRITES as [$method, $path, $name, $action, $parameters]) {
            [$app, $request, $dispatch] = $this->resolve($method, $path);
            self::assertInstanceOf(Controller::class, $dispatch, $path);
            self::assertSame([$name, $action], $dispatch->getDispatch(), $path);
            self::assertSame($parameters, $dispatch->getParam(), $path);
            $rule = (new ReflectionProperty(Dispatch::class, 'rule'))->getValue($dispatch);
            $middlewares = $rule->getOption('middleware', []);
            $names = array_map(static fn($item) => is_array($item) ? $item[0] : $item, $middlewares);
            $auth = array_search(Auth::class, $names, true);
            $retired = array_search(RetiredFeatureReadOnly::class, $names, true);
            self::assertNotFalse($auth, $path);
            self::assertNotFalse($retired, $path);
            self::assertLessThan($retired, $auth, $path);
            $excluded = $rule->getOption('without_middleware', []);
            self::assertNotContains(Auth::class, $excluded, $path);
            self::assertNotContains(RetiredFeatureReadOnly::class, $excluded, $path);
            $app->middleware->import($middlewares, 'route');
            $response = $app->middleware->pipeline('route')->send($request)->then(static function (): Response {
                self::fail('Unauthenticated retired route reached its controller');
            });
            self::assertSame(401, $response->getCode(), $path);
            self::assertSame('missing_token', $response->getData()['data']['reason'], $path);
        }
    }

    public function testSyntheticAuthenticatedRoutesStopBeforeControllerDispatch(): void
    {
        foreach (self::WRITES as [$method, $path]) {
            [$app, $request, $dispatch] = $this->resolve($method, $path);
            // Synthetic authentication only; no account, token or DB is used.
            $auth = new class extends Auth {
                public bool $called = false;
                public function handle(Request $request, Closure $next): Response
                {
                    $this->called = true;
                    return $next($request);
                }
            };
            $app->instance(Auth::class, $auth);
            $rule = (new ReflectionProperty(Dispatch::class, 'rule'))->getValue($dispatch);
            $app->middleware->import($rule->getOption('middleware', []), 'route');
            $response = $app->middleware->pipeline('route')->send($request)->then(static function (): Response {
                self::fail('Retired route reached calculations or persistence');
            });
            self::assertTrue($auth->called, $path);
            self::assertSame(410, $response->getCode(), $path);
            self::assertSame('retired_read_only', $response->getData()['data']['status'], $path);
            self::assertTrue($response->getData()['data']['history_preserved'], $path);
        }
    }

    public function testHistoryAndCurrentTransferSourceStillResolveWithBothBoundaries(): void
    {
        foreach ([
            ['/api/expansion/records', 'Expansion', 'records'],
            ['/api/expansion/records/37', 'Expansion', 'detail'],
            ['/api/transfer/source', 'TransferDecision', 'source'],
            ['/api/transfer/records', 'TransferDecision', 'records'],
            ['/api/transfer/records/37', 'TransferDecision', 'detail'],
            ['/api/strategy/records', 'StrategySimulation', 'records'],
            ['/api/strategy/records/37', 'StrategySimulation', 'detail'],
        ] as [$path, $name, $action]) {
            [, , $dispatch] = $this->resolve('GET', $path);
            self::assertSame([$name, $action], $dispatch->getDispatch(), $path);
            $rule = (new ReflectionProperty(Dispatch::class, 'rule'))->getValue($dispatch);
            $names = array_map(static fn($item) => is_array($item) ? $item[0] : $item, $rule->getOption('middleware', []));
            self::assertContains(Auth::class, $names, $path);
            self::assertContains(RetiredFeatureReadOnly::class, $names, $path);
        }
    }

    public function testHistoricalControllerReadsKeepExactUserAndHotelScope(): void
    {
        foreach ([Expansion::class => ExpansionService::class, TransferDecision::class => TransferDecisionService::class] as $controllerClass => $serviceClass) {
            $app = new App();
            $request = (new Request())->withGet(['hotel_id' => 7]);
            $request->user = new class {
                public int $id = 3;
                public function getPermittedHotelIds(): array { return [7]; }
                public function isSuperAdmin(): bool { return false; }
            };
            $app->instance('request', $request);
            $record = ['id' => 37, 'hotel_id' => 7, 'created_by' => 3, 'result' => ['status' => 'unverified']];
            $service = $this->createMock($serviceClass);
            $listArgs = $controllerClass === Expansion::class ? [3, false] : [[7], 3, false];
            $detailArgs = $controllerClass === Expansion::class ? [37, 3, false] : [37, [7], 3, false];
            $service->expects(self::once())->method('records')->with(...$listArgs)->willReturn([$record]);
            $service->expects(self::once())->method('detail')->with(...$detailArgs)->willReturn($record);
            $controller = new $controllerClass($app, $service);
            $list = $controller->records();
            self::assertSame(200, $list->getCode());
            self::assertSame(['list' => [$record]], $list->getData()['data']);
            $detail = $controller->detail(37);
            self::assertSame(200, $detail->getCode());
            self::assertSame($record, $detail->getData()['data']);
        }
    }

    public function testFeasibilityHistoryAndLifecycleStillHaveAuthenticatedGetRoutes(): void
    {
        foreach ([
            ['/api/agent/feasibility-report/detail/37', 'Agent', 'feasibilityReportDetail'],
            ['/api/agent/feasibility-report/list', 'Agent', 'feasibilityReportList'],
            ['/api/lifecycle/overview', 'Lifecycle', 'overview'],
        ] as [$path, $name, $action]) {
            [, , $dispatch] = $this->resolve('GET', $path);
            self::assertSame([$name, $action], $dispatch->getDispatch(), $path);
            $rule = (new ReflectionProperty(Dispatch::class, 'rule'))->getValue($dispatch);
            $names = array_map(static fn($item) => is_array($item) ? $item[0] : $item, $rule->getOption('middleware', []));
            self::assertContains(Auth::class, $names, $path);
        }
    }

    public function testImplicitControllerPathsCannotBypassTheRegisteredBoundary(): void
    {
        foreach (['/expansion/marketEvaluation', '/expansion/createExecutionIntent/37',
            '/transfer_decision/pricing', '/TransferDecision/createExecutionIntent/37',
            '/strategy_simulation/simulate', '/agent/feasibilityReportGenerate'] as $path) {
            try {
                $this->resolve('POST', $path);
                self::fail('Implicit controller path became routable: ' . $path);
            } catch (RouteNotFoundException) {
                self::assertTrue(true);
            }
        }
    }

    private function resolve(string $method, string $path): array
    {
        $request = (new Request())->setMethod($method)->setUrl($path)
            ->setBaseUrl($path)->setPathinfo(ltrim($path, '/'));
        $app = new App(dirname(__DIR__));
        $app->config->set(require dirname(__DIR__) . '/config/route.php', 'route');
        $app->config->set(['priority' => []], 'middleware');
        $router = new RetiredFeatureRoutingProbe($app);
        self::assertTrue($router->config('url_route_must'));
        $app->instance('route', $router);
        require dirname(__DIR__) . '/route/app.php';
        return [$app, $request, $router->resolve($request)];
    }
}

final class RetiredFeatureRoutingProbe extends Route
{
    public function resolve(Request $request): Dispatch|false
    {
        $this->request = $request;
        $this->host = $request->host(true);
        $url = str_replace($this->config('pathinfo_depr'), '|', $this->path());
        return $this->check($url, (bool)$this->config('route_complete_match'));
    }
}
