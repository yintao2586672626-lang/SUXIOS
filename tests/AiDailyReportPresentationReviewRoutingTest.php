<?php
declare(strict_types=1);

namespace Tests;

use app\middleware\Auth;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use think\App;
use think\Request;
use think\Route;
use think\route\Dispatch;
use think\route\dispatch\Controller;

final class AiDailyReportPresentationReviewRoutingTest extends TestCase
{
    public function testReviewReadAndSaveRoutesKeepExactParametersAndAuthentication(): void
    {
        foreach (['GET' => 'presentationReview', 'POST' => 'savePresentationReview'] as $verb => $action) {
            $app = new App(dirname(__DIR__));
            $app->setRuntimePath(sys_get_temp_dir() . '/suxios-review-route-' . bin2hex(random_bytes(8)) . '/');
            $app->config->set(require dirname(__DIR__) . '/config/route.php', 'route');
            $router = new AiDailyReportPresentationReviewRoutingProbe($app);
            $app->instance('route', $router);
            require dirname(__DIR__) . '/route/app.php';
            $path = '/api/ai-daily-reports/37/presentation-review';
            $request = (new Request())->setMethod($verb)->setUrl($path)->setBaseUrl($path)
                ->setPathinfo(ltrim($path, '/'))->withHeader(['accept' => 'application/json', 'authorization' => '']);
            $dispatch = $router->resolve($request);
            self::assertInstanceOf(Controller::class, $dispatch);
            self::assertSame(['AiDailyReport', $action], $dispatch->getDispatch());
            self::assertSame(['id' => '37'], $dispatch->getParam());
            $rule = (new ReflectionProperty(Dispatch::class, 'rule'))->getValue($dispatch);
            self::assertTrue($rule->getOption('complete_match'));
            $middlewares = array_map(static fn($item) => is_array($item) ? $item[0] : $item, $rule->getOption('middleware', []));
            self::assertContains(Auth::class, $middlewares);
            self::assertNotContains(Auth::class, $rule->getOption('without_middleware', []));
            $response = (new Auth())->handle($request, static function (): never {
                self::fail('Anonymous review requests must not execute a report action.');
            });
            self::assertSame(401, $response->getCode());
        }
    }
}

/** Expose only the matching phase of ThinkPHP's Route::dispatch(). */
final class AiDailyReportPresentationReviewRoutingProbe extends Route
{
    public function resolve(Request $request): Dispatch|false
    {
        $this->request = $request;
        $this->host = $request->host(true);
        $url = str_replace($this->config('pathinfo_depr'), '|', $this->path());

        return $this->check($url, (bool)$this->config('route_complete_match'));
    }
}
