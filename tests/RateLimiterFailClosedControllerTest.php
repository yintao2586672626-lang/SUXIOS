<?php
declare(strict_types=1);

namespace Tests;

use app\controller\CompetitorApi;
use app\middleware\Auth;
use app\model\User;
use app\service\FixedWindowRateLimiter;
use app\service\Ota\OtaActionHandler;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;
use think\App;
use think\Request;
use think\Response;

final class RateLimiterFailClosedControllerTest extends TestCase
{
    use ReflectionHelper;

    private static App $app;
    private string $tempDirectory = '';

    public static function setUpBeforeClass(): void
    {
        self::$app = new App(dirname(__DIR__));
        self::$app->initialize();
    }

    protected function tearDown(): void
    {
        if ($this->tempDirectory !== '') {
            foreach (glob($this->tempDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($this->tempDirectory);
        }
        $this->tempDirectory = '';
    }

    public function testAuthenticatedLimiterFailureReturnsSanitized503(): void
    {
        $request = $this->request('GET', '/api/dashboard/overview');
        $user = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__get', '__isset'])
            ->getMock();
        $user->method('__isset')->willReturnCallback(
            static fn(string $key): bool => in_array($key, ['id', 'tenant_id'], true)
        );
        $user->method('__get')->willReturnCallback(
            static fn(string $key): ?int => match ($key) {
                'id' => 7,
                'tenant_id' => 3,
                default => null,
            }
        );
        $middleware = new AuthRateLimiterFailureHarness($this->failingLimiter());

        $response = $this->invokeNonPublic($middleware, 'enforceRateLimit', [
            $request,
            $user,
            null,
            'rate-limit-test-request',
        ]);

        $this->assertUnavailable($response);
        self::assertSame('rate-limit-test-request', $this->payload($response)['request_id']);
    }

    public function testCompetitorPublicLimiterFailureReturnsSanitized503BeforeBindingLookup(): void
    {
        $request = $this->request('POST', '/api/competitor/task')
            ->withPost([
                'device_id' => 'test-device',
                'platform' => 'xc',
                'store_id' => 80,
            ]);
        $app = $this->appWithRequest($request);
        $controller = new CompetitorRateLimiterFailureHarness($app, $this->failingLimiter());

        $this->assertUnavailable($controller->task());
    }

    public function testAllCookieConcernPublicLimiterCallersReturnSanitized503(): void
    {
        $cases = [
            ['POST', '/api/online-data/receive-cookies', 'receiveCookies'],
            ['GET', '/api/online-data/cron-trigger', 'cronTrigger'],
            ['GET', '/api/online-data/daily-workbench-patrol-cron', 'dailyWorkbenchPatrolCron'],
        ];

        foreach ($cases as [$method, $url, $action]) {
            $request = $this->request($method, $url);
            if ($action === 'receiveCookies') {
                $request->withHeader(['Origin' => 'https://untrusted.example']);
            }
            $handler = new OtaRateLimiterFailureHarness(
                $this->appWithRequest($request),
                $this->failingLimiter()
            );

            $this->assertUnavailable($handler->{$action}());
        }
    }

    public function testReceiveCookiesOptionsKeepsCompatibilityWithoutConsumingLimiter(): void
    {
        $request = $this->request('OPTIONS', '/api/online-data/receive-cookies');
        $handler = new OtaRateLimiterFailureHarness(
            $this->appWithRequest($request),
            $this->failingLimiter()
        );

        self::assertSame(204, $handler->receiveCookies()->getCode());
    }

    private function failingLimiter(): FixedWindowRateLimiter
    {
        if ($this->tempDirectory === '') {
            $this->tempDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
                . 'suxi_rate_limit_fail_closed_' . bin2hex(random_bytes(8));
            if (!mkdir($this->tempDirectory, 0700, true) && !is_dir($this->tempDirectory)) {
                throw new \RuntimeException('Unable to create rate-limit failure test directory.');
            }
            file_put_contents($this->tempDirectory . DIRECTORY_SEPARATOR . 'blocked-lock-dir', 'blocked');
        }

        return new FixedWindowRateLimiter(
            static fn(string $_key): ?int => null,
            static fn(string $_key, int $_value, int $_ttl): bool => true,
            static fn(): int => 125,
            $this->tempDirectory . DIRECTORY_SEPARATOR . 'blocked-lock-dir'
        );
    }

    private function request(string $method, string $url): Request
    {
        $path = (string)(parse_url($url, PHP_URL_PATH) ?: '/');

        return (new Request())
            ->setMethod($method)
            ->setUrl($url)
            ->setBaseUrl($path)
            ->setPathinfo(ltrim($path, '/'))
            ->withServer(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader(['Accept' => 'application/json']);
    }

    private function appWithRequest(Request $request): App
    {
        self::$app->instance('request', $request);

        return self::$app;
    }

    private function assertUnavailable(Response $response): void
    {
        $payload = $this->payload($response);
        self::assertSame(503, $response->getCode());
        self::assertSame(503, $payload['code']);
        self::assertSame('rate_limiter_unavailable', $payload['data']['reason']);
        self::assertStringNotContainsString('lock directory', (string)$response->getContent());
    }

    /** @return array<string, mixed> */
    private function payload(Response $response): array
    {
        return json_decode((string)$response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}

final class AuthRateLimiterFailureHarness extends Auth
{
    public function __construct(private FixedWindowRateLimiter $limiter)
    {
    }

    protected function fixedWindowRateLimiter(): FixedWindowRateLimiter
    {
        return $this->limiter;
    }
}

final class CompetitorRateLimiterFailureHarness extends CompetitorApi
{
    public function __construct(App $app, private FixedWindowRateLimiter $limiter)
    {
        parent::__construct($app);
    }

    protected function fixedWindowRateLimiter(): FixedWindowRateLimiter
    {
        return $this->limiter;
    }
}

final class OtaRateLimiterFailureHarness extends OtaActionHandler
{
    public function __construct(App $app, private FixedWindowRateLimiter $limiter)
    {
        parent::__construct($app);
    }

    protected function fixedWindowRateLimiter(): FixedWindowRateLimiter
    {
        return $this->limiter;
    }
}
