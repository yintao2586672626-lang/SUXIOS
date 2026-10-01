<?php
declare(strict_types=1);

namespace Tests;

use app\controller\InvestmentScenario;
use app\middleware\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use think\App;
use think\exception\RouteNotFoundException;
use think\Request;
use think\Response;
use think\route\Dispatch;
use think\route\dispatch\Controller;
use think\route\Rule;

require_once __DIR__ . '/InvestmentPaybackRoutingTest.php';

final class InvestmentScenarioRoutingTest extends TestCase
{
    private InvestmentPaybackRoutingProbe $router;

    protected function setUp(): void
    {
        $app = new App(dirname(__DIR__));
        // Reuse the matching-only probe: no application initialization, cached
        // routes, database access, session reads, or business actions.
        $app->setRuntimePath(sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'suxios-scenario-route-probe-' . bin2hex(random_bytes(8)) . DIRECTORY_SEPARATOR);
        $app->config->set(require dirname(__DIR__) . '/config/route.php', 'route');
        $this->router = new InvestmentPaybackRoutingProbe($app);
        $app->instance('route', $this->router);

        require dirname(__DIR__) . '/route/app.php';
    }

    #[DataProvider('scenarioRoutes')]
    public function testScenarioRoutesResolveToTheExactControllerActionWithAuth(
        string $method,
        string $path,
        string $action,
        array $parameters
    ): void {
        $dispatch = $this->router->resolve($this->request($method, $path));

        self::assertInstanceOf(Controller::class, $dispatch);
        self::assertSame(['InvestmentScenario', $action], $dispatch->getDispatch(), $path);
        self::assertSame($parameters, $dispatch->getParam(), $path);
        self::assertTrue((new ReflectionMethod(InvestmentScenario::class, $action))->isPublic());

        $rule = $this->matchedRule($dispatch);
        self::assertTrue($rule->getOption('complete_match'), $path);
        self::assertContains(Auth::class, $this->middlewares($rule), $path);
        self::assertNotContains(Auth::class, $rule->getOption('without_middleware', []), $path);
    }

    #[DataProvider('scenarioRoutes')]
    public function testMissingAuthenticationReturns401BeforeTheMatchedBusinessAction(
        string $method,
        string $path,
        string $action,
        array $parameters
    ): void {
        $request = $this->request($method, $path);
        $dispatch = $this->router->resolve($request);
        self::assertInstanceOf(Controller::class, $dispatch);
        self::assertSame(['InvestmentScenario', $action], $dispatch->getDispatch());
        self::assertSame($parameters, $dispatch->getParam());
        $middlewares = $this->middlewares($this->matchedRule($dispatch));
        self::assertContains(Auth::class, $middlewares);

        // Execute the real matched Auth middleware with an explicitly empty
        // request. The missing-token branch returns before cache or DB access.
        $authClass = $middlewares[array_search(Auth::class, $middlewares, true)];
        $response = (new $authClass())->handle($request, static function (Request $request): Response {
            self::fail('An unauthenticated request must not enter the scenario controller.');
        });
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(401, $response->getCode(), $path);
        self::assertSame(401, $payload['code']);
        self::assertSame('missing_token', $payload['data']['reason']);
        self::assertSame('scenario-route-test', $payload['request_id']);
    }

    #[DataProvider('scenarioRoutes')]
    public function testControllerAlsoRejectsMissingUserWithoutCreatingTheBusinessService(
        string $method,
        string $path,
        string $action,
        array $parameters
    ): void {
        $reflection = new ReflectionClass(InvestmentScenario::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $response = $reflection->getMethod($action)->invokeArgs($controller, array_values($parameters));
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(401, $response->getCode(), $path);
        self::assertSame(401, $payload['code']);
        self::assertNull($payload['data']);
    }

    public function testEveryDeclaredPublicScenarioActionHasAnExactRegisteredRoute(): void
    {
        $actions = [];
        foreach ((new ReflectionClass(InvestmentScenario::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() === InvestmentScenario::class) {
                $actions[] = $method->getName();
            }
        }
        $registeredActions = array_column(self::scenarioRoutes(), 2);
        sort($actions);
        sort($registeredActions);

        self::assertSame($actions, $registeredActions);
        self::assertFalse($this->router->config('route_complete_match'));
        self::assertTrue($this->router->config('url_route_must'));
    }

    public static function scenarioRoutes(): array
    {
        return [
            'reference example' => ['GET', '/api/investment-payback/scenario/reference-example', 'referenceExample', []],
            'project scenario detail' => ['GET', '/api/investment-payback/projects/37/scenario', 'detail', ['id' => '37']],
            'project scenario save' => ['POST', '/api/investment-payback/projects/37/scenario', 'save', ['id' => '37']],
            'project scenario preview' => ['POST', '/api/investment-payback/projects/37/scenario/preview', 'preview', ['id' => '37']],
        ];
    }

    #[DataProvider('invalidScenarioPaths')]
    public function testInvalidScenarioChildrenAndMethodsNeverFallBackToAnotherAction(string $method, string $path): void
    {
        $this->expectException(RouteNotFoundException::class);
        $this->router->resolve($this->request($method, $path));
    }

    public static function invalidScenarioPaths(): array
    {
        return [
            'reference extra child' => ['GET', '/api/investment-payback/scenario/reference-example/extra'],
            'reference unsupported method' => ['POST', '/api/investment-payback/scenario/reference-example'],
            'unknown scenario reference' => ['GET', '/api/investment-payback/scenario/unknown'],
            'detail extra child' => ['GET', '/api/investment-payback/projects/37/scenario/extra'],
            'save extra child' => ['POST', '/api/investment-payback/projects/37/scenario/extra'],
            'preview extra child' => ['POST', '/api/investment-payback/projects/37/scenario/preview/extra'],
            'preview unsupported method' => ['GET', '/api/investment-payback/projects/37/scenario/preview'],
            'save unsupported method' => ['PATCH', '/api/investment-payback/projects/37/scenario'],
            'delete unsupported method' => ['DELETE', '/api/investment-payback/projects/37/scenario'],
            'incomplete preview action' => ['POST', '/api/investment-payback/projects/37/scenario/previ'],
        ];
    }

    private function request(string $method, string $path): Request
    {
        return (new Request())
            ->setMethod($method)
            ->setUrl($path)
            ->setBaseUrl($path)
            ->setPathinfo(ltrim($path, '/'))
            ->withHeader([
                'accept' => 'application/json',
                'authorization' => '',
                'x-request-id' => 'scenario-route-test',
            ]);
    }

    private function matchedRule(Dispatch $dispatch): Rule
    {
        $rule = (new ReflectionProperty(Dispatch::class, 'rule'))->getValue($dispatch);
        self::assertInstanceOf(Rule::class, $rule);

        return $rule;
    }

    private function middlewares(Rule $rule): array
    {
        return array_map(
            static fn($middleware) => is_array($middleware) ? $middleware[0] : $middleware,
            $rule->getOption('middleware', [])
        );
    }
}
