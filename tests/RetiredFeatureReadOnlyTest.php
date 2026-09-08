<?php
declare(strict_types=1);

namespace Tests;

use app\middleware\RetiredFeatureReadOnly;
use PHPUnit\Framework\TestCase;
use think\Request;

final class RetiredFeatureReadOnlyTest extends TestCase
{
    public function testRetiredWriteRequestsNeverReachCalculationsOrPersistence(): void
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $request = (new Request())->setMethod($method);
            $called = false;
            $response = (new RetiredFeatureReadOnly())->handle($request, function () use (&$called) {
                $called = true;
                return json(['created' => true]);
            }, '扩张测算');
            self::assertFalse($called, $method);
            self::assertSame(410, $response->getCode());
            self::assertSame('retired_read_only', $response->getData()['data']['status']);
            self::assertTrue($response->getData()['data']['history_preserved']);
        }
    }

    public function testExistingHistoryResponsesKeepOriginalPermissionAndFailureStates(): void
    {
        foreach ([200, 403, 404, 500] as $status) {
            $history = json(['code' => $status, 'data' => ['id' => 7]], $status);
            $response = (new RetiredFeatureReadOnly())->handle((new Request())->setMethod('GET'), fn() => $history);
            self::assertSame($history, $response);
        }
    }
}
