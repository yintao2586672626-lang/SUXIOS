<?php
declare(strict_types=1);

namespace Tests;

use app\controller\ota\LocalCollectorController;
use PHPUnit\Framework\TestCase;
use think\App;
use think\Request;

final class LocalCollectorControllerSecurityTest extends TestCase
{
    private static App $app;

    public static function setUpBeforeClass(): void
    {
        self::$app = new App(dirname(__DIR__));
        self::$app->initialize();
    }

    public function testConflictingCollectorCredentialsAreRejectedBeforeServiceLookup(): void
    {
        $request = $this->request('POST', '/api/online-data/local-collector/heartbeat')
            ->withHeader([
                'X-Collector-Device-Id' => 'fixture-device',
                'X-Collector-Token' => 'fixture-header-token',
                'Authorization' => 'Collector fixture-authorization-token',
            ])
            ->withInput('{}');

        $response = $this->controller($request)->heartbeat();
        $payload = json_decode((string)$response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(401, $response->getCode());
        self::assertSame(401, $payload['code']);
        self::assertSame('采集设备认证信息无效。', $payload['message']);
        self::assertStringNotContainsString('fixture-header-token', (string)$response->getContent());
        self::assertStringNotContainsString('fixture-authorization-token', (string)$response->getContent());
    }

    public function testOversizedPairingBodyIsRejectedAndRepeatedAbuseIsRateLimited(): void
    {
        $request = $this->request('POST', '/api/online-data/local-collector/pair')
            ->withInput(str_repeat('x', 16_385));
        $controller = $this->controller($request);

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            self::assertSame(413, $controller->pair()->getCode());
        }

        $limited = $controller->pair();
        $payload = json_decode((string)$limited->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(429, $limited->getCode());
        self::assertSame('rate_limited', $payload['data']['reason']);
        self::assertNotSame('', (string)$limited->getHeader('Retry-After'));
    }

    private function request(string $method, string $url): Request
    {
        $path = (string)(parse_url($url, PHP_URL_PATH) ?: '/');
        $ip = sprintf(
            '10.%d.%d.%d',
            random_int(1, 254),
            random_int(1, 254),
            random_int(1, 254)
        );

        return (new Request())
            ->setMethod($method)
            ->setUrl($url)
            ->setBaseUrl($path)
            ->setPathinfo(ltrim($path, '/'))
            ->withServer(['REMOTE_ADDR' => $ip])
            ->withHeader(['Accept' => 'application/json']);
    }

    private function controller(Request $request): LocalCollectorController
    {
        self::$app->instance('request', $request);

        return new LocalCollectorController(self::$app);
    }
}
