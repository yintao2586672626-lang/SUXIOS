<?php
declare(strict_types=1);

namespace Tests;

use app\ExceptionHandle;
use PHPUnit\Framework\TestCase;
use think\App;
use think\exception\HttpException;
use think\Request;

final class ExceptionHandleSecurityTest extends TestCase
{
    public function testApiRuntimeExceptionNeverLeaksMessageStackOrAbsolutePath(): void
    {
        $handle = new ExceptionHandle(new App(dirname(__DIR__)));
        $request = (new Request())->setPathinfo('api/definitely-not-real');
        $secret = 'D:\\private\\hotel\\credential.php';

        $response = $handle->render($request, new \RuntimeException('failure at ' . $secret));
        $body = (string)$response->getContent();

        self::assertSame(500, $response->getCode());
        self::assertStringNotContainsString($secret, $body);
        self::assertStringNotContainsString('RuntimeException', $body);
        self::assertStringNotContainsString('Call Stack', $body);
        self::assertSame('internal_error', json_decode($body, true)['data']['reason'] ?? null);
    }

    public function testUnknownApiRouteReturnsStableJson404WithoutFrameworkDetails(): void
    {
        $handle = new ExceptionHandle(new App(dirname(__DIR__)));
        $request = (new Request())->setPathinfo('api/definitely-not-real');

        $response = $handle->render($request, new HttpException(404, 'RouteNotFoundException D:\\secret'));
        $body = (string)$response->getContent();

        self::assertSame(404, $response->getCode());
        self::assertStringNotContainsString('RouteNotFoundException', $body);
        self::assertStringNotContainsString('D:\\secret', $body);
        self::assertSame('route_not_found', json_decode($body, true)['data']['reason'] ?? null);
    }

    public function testUnknownWebRouteReturnsPlainSafe404(): void
    {
        $handle = new ExceptionHandle(new App(dirname(__DIR__)));
        $request = (new Request())->setPathinfo('definitely-not-real');

        $response = $handle->render($request, new HttpException(404, 'sensitive framework detail'));
        $body = (string)$response->getContent();

        self::assertSame(404, $response->getCode());
        self::assertSame('接口不存在', $body);
        self::assertStringNotContainsString('sensitive framework detail', $body);
    }
}
