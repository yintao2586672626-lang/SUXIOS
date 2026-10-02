<?php
declare(strict_types=1);

namespace Tests;

use app\middleware\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use think\App;
use think\exception\RouteNotFoundException;
use think\Request;
use think\Route;
use think\route\Dispatch;
use think\route\dispatch\Controller;
use think\route\Rule;

final class InvestmentPaybackRoutingTest extends TestCase
{
    private InvestmentPaybackRoutingProbe $router;

    protected function setUp(): void
    {
        $app = new App(dirname(__DIR__));
        // Avoid application initialization, cached runtime routes, middleware,
        // and controller execution: only the real route definitions are loaded.
        $app->setRuntimePath(sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'suxios-route-probe-' . bin2hex(random_bytes(8)) . DIRECTORY_SEPARATOR);
        $app->config->set(require dirname(__DIR__) . '/config/route.php', 'route');
        $this->router = new InvestmentPaybackRoutingProbe($app);
        $app->instance('route', $this->router);

        require dirname(__DIR__) . '/route/app.php';
    }

    public function testTheExistingGlobalPartialMatchDefaultIsPreserved(): void
    {
        self::assertFalse($this->router->config('route_complete_match'));
        self::assertTrue($this->router->config('url_route_must'));
    }

    #[DataProvider('projectRoutes')]
    public function testProjectRoutesResolveToTheirExactActionAndParametersWithAuth(
        string $method,
        string $path,
        string $action,
        array $parameters
    ): void {
        $dispatch = $this->resolve($method, $path);

        self::assertInstanceOf(Controller::class, $dispatch);
        self::assertSame(['InvestmentPayback', $action], $dispatch->getDispatch(), $path);
        self::assertSame($parameters, $dispatch->getParam(), $path);

        // Inspect the framework's matched rule, including inherited group
        // options. Auth is not executed and no authenticated state is read.
        $rule = (new ReflectionProperty(Dispatch::class, 'rule'))->getValue($dispatch);
        self::assertInstanceOf(Rule::class, $rule);
        $middlewares = array_map(
            static fn($middleware) => is_array($middleware) ? $middleware[0] : $middleware,
            $rule->getOption('middleware', [])
        );
        self::assertContains(Auth::class, $middlewares, $path);
        self::assertNotContains(Auth::class, $rule->getOption('without_middleware', []), $path);
    }

    public static function projectRoutes(): array
    {
        return [
            'save personal layout' => ['POST', '/api/investment-payback/layout', 'saveLayout', []],
            'list projects' => ['GET', '/api/investment-payback/projects', 'projects', []],
            'save project' => ['POST', '/api/investment-payback/projects', 'saveProject', []],
            'project detail' => ['GET', '/api/investment-payback/projects/37', 'detail', ['id' => '37']],
            'archive project' => ['POST', '/api/investment-payback/projects/37/archive', 'archive', ['id' => '37']],
            'save entry' => ['POST', '/api/investment-payback/projects/37/entries', 'saveEntry', ['id' => '37']],
            'void entry' => ['POST', '/api/investment-payback/projects/37/entries/8/void', 'voidEntry', ['id' => '37', 'entryId' => '8']],
            'delete entry' => ['POST', '/api/investment-payback/projects/37/entries/8/delete', 'deleteEntry', ['id' => '37', 'entryId' => '8']],
        ];
    }

    #[DataProvider('invalidProjectPaths')]
    public function testInvalidChildPathsDoNotFallBackToAnotherProjectAction(string $method, string $path): void
    {
        $this->expectException(RouteNotFoundException::class);
        $this->resolve($method, $path);
    }

    public static function invalidProjectPaths(): array
    {
        return [
            'layout extra child' => ['POST', '/api/investment-payback/layout/extra'],
            'unsupported layout method' => ['GET', '/api/investment-payback/layout'],
            'unknown project child' => ['POST', '/api/investment-payback/projects/37/unknown'],
            'detail extra child' => ['GET', '/api/investment-payback/projects/37/unknown'],
            'archive extra child' => ['POST', '/api/investment-payback/projects/37/archive/extra'],
            'entries extra child' => ['POST', '/api/investment-payback/projects/37/entries/extra'],
            'void extra child' => ['POST', '/api/investment-payback/projects/37/entries/8/void/extra'],
            'delete extra child' => ['POST', '/api/investment-payback/projects/37/entries/8/delete/extra'],
            'incomplete void entry' => ['POST', '/api/investment-payback/projects/37/entries/8'],
            'unsupported detail method' => ['POST', '/api/investment-payback/projects/37'],
            'unsupported archive method' => ['GET', '/api/investment-payback/projects/37/archive'],
            'unsupported entry method' => ['GET', '/api/investment-payback/projects/37/entries'],
            'unsupported void method' => ['GET', '/api/investment-payback/projects/37/entries/8/void'],
            'unsupported delete method' => ['GET', '/api/investment-payback/projects/37/entries/8/delete'],
        ];
    }

    private function resolve(string $method, string $path): Dispatch|false
    {
        $request = (new Request())
            ->setMethod($method)
            ->setUrl($path)
            ->setBaseUrl($path)
            ->setPathinfo(ltrim($path, '/'));

        return $this->router->resolve($request);
    }
}

/** Expose only the matching phase of ThinkPHP's Route::dispatch(). */
final class InvestmentPaybackRoutingProbe extends Route
{
    public function resolve(Request $request): Dispatch|false
    {
        $this->request = $request;
        $this->host = $request->host(true);
        $url = str_replace($this->config('pathinfo_depr'), '|', $this->path());

        return $this->check($url, (bool)$this->config('route_complete_match'));
    }
}
