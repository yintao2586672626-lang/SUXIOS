<?php
declare(strict_types=1);

namespace Tests;

use app\command\ManualFetchOnlineDataOnce;
use app\service\BrowserCaptureTaskExecutionService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use think\Request;
use think\Response;

final class BrowserCaptureTaskExecutionServiceTest extends TestCase
{
    public function testQueuedCaptureRunsThroughAnAuthenticatedInProcessRequest(): void
    {
        $captured = null;
        $service = new BrowserCaptureTaskExecutionService(
            static function (Request $request) use (&$captured): Response {
                $captured = $request;
                return Response::create((string)json_encode([
                    'code' => 200,
                    'message' => 'capture complete',
                    'data' => [
                        'saved_count' => 2,
                        'readback_count' => 2,
                        'readback_verified' => true,
                    ],
                ], JSON_UNESCAPED_SLASHES), 'html', 200);
            }
        );

        $result = $service->execute(
            'http://127.0.0.1:8080/api/online-data/capture-ctrip-browser',
            'Bearer fixture-token',
            ['system_hotel_id' => 7, 'profile_id' => 'profile-fixture', 'async' => true]
        );

        self::assertTrue($result['success']);
        self::assertSame('capture complete', $result['message']);
        self::assertInstanceOf(Request::class, $captured);
        self::assertSame('POST', $captured->method());
        self::assertSame('api/online-data/capture-ctrip-browser', $captured->pathinfo());
        self::assertSame('Bearer fixture-token', $captured->header('Authorization'));
        self::assertSame(7, (int)$captured->post('system_hotel_id'));
        self::assertFalse((bool)$captured->post('async'));
        self::assertFalse((bool)$captured->post('background'));
        self::assertTrue((bool)$captured->post('background_task'));
        self::assertSame(2, (int)$result['response']['data']['saved_count']);
    }

    public function testExecutionScopeRejectsUnknownPathsQueriesAndMissingHotels(): void
    {
        $service = new BrowserCaptureTaskExecutionService(
            static fn(Request $request): Response => Response::create('{}', 'html', 200)
        );
        foreach ([
            ['http://127.0.0.1:8080/api/online-data/fetch-ctrip', ['system_hotel_id' => 7]],
            ['http://127.0.0.1:8080/api/online-data/capture-ctrip-browser?hotel=7', ['system_hotel_id' => 7]],
            ['http://127.0.0.1:8080/api/online-data/capture-meituan-browser', ['system_hotel_id' => 0]],
        ] as [$url, $body]) {
            try {
                $service->execute($url, 'Bearer fixture-token', $body);
                self::fail('Invalid browser capture task scope must be rejected.');
            } catch (RuntimeException $exception) {
                self::assertSame('browser_capture_background_scope_invalid', $exception->getMessage());
            }
        }
    }

    public function testBrowserTaskCommandSelectsInProcessExecutionBeforeCurlFallback(): void
    {
        $root = dirname(__DIR__);
        $commandSource = (string)file_get_contents($root . '/app/command/ManualFetchOnlineDataOnce.php');
        $executorSource = (string)file_get_contents($root . '/app/service/BrowserCaptureTaskExecutionService.php');
        self::assertMatchesRegularExpression(
            '/\$this->isBrowserCaptureTask\([^\n]+\)\s*\? \(new BrowserCaptureTaskExecutionService\(\)\)->execute\([^\n]+\)\s*:\s*\$this->postJson\(/s',
            $commandSource
        );
        self::assertStringContainsString('$http->run($request)', $executorSource);
        self::assertStringNotContainsString('curl_', $executorSource);

        $method = new ReflectionMethod(new ManualFetchOnlineDataOnce(), 'isBrowserCaptureTask');
        $method->setAccessible(true);
        self::assertTrue($method->invoke(new ManualFetchOnlineDataOnce(), 'ctrip_browser_profile'));
        self::assertTrue($method->invoke(new ManualFetchOnlineDataOnce(), 'meituan_browser_profile'));
        self::assertFalse($method->invoke(new ManualFetchOnlineDataOnce(), 'ctrip_traffic'));
    }
}
