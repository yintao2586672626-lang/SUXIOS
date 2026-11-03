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

    private function request(string $method, string $path): Request
    {
        return (new Request())->setMethod($method)->setUrl($path)->setBaseUrl($path)
            ->setPathinfo(ltrim($path, '/'))->withHeader(['accept' => 'application/json', 'authorization' => '']);
    }
}
