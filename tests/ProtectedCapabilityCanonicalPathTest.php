<?php
declare(strict_types=1);

namespace Tests;

use app\service\ProtectedCapabilityService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use think\App;
use think\Config;
use think\Container;
use think\Request;
use think\Route;

final class ProtectedCapabilityCanonicalPathTest extends TestCase
{
    public function testRouterAcceptedSuffixRetainsEveryCapabilityBoundary(): void
    {
        $previous = Container::getInstance();
        $app = new App(dirname(__DIR__));
        $config = new Config();
        $config->set(require dirname(__DIR__) . '/config/route.php', 'route');
        $app->instance('config', $config);
        Container::setInstance($app);
        try {
            $service = new ProtectedCapabilityService(ProtectedCapabilityService::defaultPolicy());
            foreach ([
                ['POST', '/api/knowledge/add'],
                ['POST', '/api/knowledge/references'],
                ['POST', '/api/knowledge/7/update'],
                ['POST', '/api/agent/ota-diagnosis'],
                ['GET', '/api/lifecycle/overview'],
            ] as [$method, $path]) {
                $request = (new Request())->setMethod($method)->setUrl($path . '.HTML?hotel_id=7')->setPathinfo(ltrim($path . '.HTML', '/'));
                $app->instance('request', $request);
                $route = new Route($app);
                (new ReflectionProperty($route, 'request'))->setValue($route, $request);
                $routerPath = (new ReflectionMethod($route, 'path'))->invoke($route);
                self::assertSame(ltrim($path, '/'), $routerPath, 'Fixture must be accepted by the actual router normalizer');
                $canonical = $service->classifyPath($method, $path);
                $suffixed = $service->classifyPath($method, $request->url());
                self::assertIsArray($canonical, $path);
                self::assertIsArray($suffixed, $path . '.HTML');
                self::assertSame($canonical['key'], $suffixed['key']);
                self::assertSame($canonical['permission'], $suffixed['permission']);
                self::assertSame($canonical['rate_limit'], $suffixed['rate_limit']);
            }
        } finally {
            Container::setInstance($previous);
        }
    }
}
