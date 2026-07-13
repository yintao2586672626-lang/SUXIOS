<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use think\App;
use think\Request;
use think\Response;

final class UnauthenticatedRouteBoundaryTest extends TestCase
{
    public function testLegacyControllerPathsAreNotRoutable(): void
    {
        $config = require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'route.php';
        self::assertTrue(
            $config['url_route_must'] ?? false,
            'Refusing to probe legacy controller paths while controller fallback routing is enabled.'
        );

        $cases = [
            ['GET', '/operation_log_controller/index', ''],
            ['GET', '/macro_signal/trends?range=30', ''],
            ['GET', '/lifecycle/overview', ''],
            ['POST', '/strategy_simulation/simulate', '{}'],
            ['POST', '/knowledge/runDistillation', '{"mode":"invalid"}'],
        ];

        foreach ($cases as [$method, $path, $body]) {
            $response = $this->dispatch($method, $path, $body);
            self::assertSame(404, $response->getCode(), "Legacy controller path must stay closed: {$path}");
        }
    }

    public function testCanonicalSensitiveRoutesRequireAuthentication(): void
    {
        $cases = [
            ['GET', '/api/operation-logs', ''],
            ['GET', '/api/macro-signals/trends?range=30', ''],
            ['GET', '/api/lifecycle/overview', ''],
            ['POST', '/api/strategy/simulate', '{}'],
            ['POST', '/api/knowledge/distillation/run', '{"mode":"invalid"}'],
        ];

        foreach ($cases as [$method, $path, $body]) {
            $response = $this->dispatch($method, $path, $body);
            $payload = json_decode((string)$response->getContent(), true);

            self::assertSame(401, $response->getCode(), "Canonical route must require authentication: {$path}");
            self::assertSame('missing_token', $payload['data']['reason'] ?? null, "Missing-token reason expected: {$path}");
        }
    }

    private function dispatch(string $method, string $url, string $body): Response
    {
        $path = (string)(parse_url($url, PHP_URL_PATH) ?: '/');
        $request = (new Request())
            ->setMethod($method)
            ->setUrl($url)
            ->setBaseUrl($path)
            ->setPathinfo(ltrim($path, '/'))
            ->withHeader([
                'accept' => 'application/json',
                'content-type' => 'application/json',
            ]);

        if ($body !== '') {
            $request->withInput($body);
        }

        try {
            return (new App(dirname(__DIR__)))->http->run($request);
        } finally {
            restore_error_handler();
            restore_exception_handler();
        }
    }
}
