<?php
declare(strict_types=1);

namespace Tests;

use app\middleware\Auth;
use app\model\User;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;

final class AuthMiddlewareAuditTest extends TestCase
{
    use ReflectionHelper;

    public function testSanitizeAuditParamsMasksSensitiveNestedValuesAndTruncatesLongText(): void
    {
        $safe = $this->invokeNonPublic(new Auth(), 'sanitizeAuditParams', [[
            'token' => 'secret-token',
            'hotel_id' => 12,
            'nested' => [
                'password' => 'secret-password',
                'normal' => str_repeat('a', 130),
            ],
            'payload' => (object)['a' => 1],
        ]]);

        self::assertSame('***', $safe['token']);
        self::assertSame(12, $safe['hotel_id']);
        self::assertSame('***', $safe['nested']['password']);
        self::assertSame(str_repeat('a', 120) . '...', $safe['nested']['normal']);
        self::assertSame('[object]', $safe['payload']);
    }

    public function testResolveAuditHotelIdPrefersRequestHotelThenFallsBackToUserHotel(): void
    {
        $middleware = new Auth();
        $user = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__get', '__isset'])
            ->getMock();
        $user->method('__isset')->with('hotel_id')->willReturn(true);
        $user->method('__get')->with('hotel_id')->willReturn(7);

        self::assertSame(15, $this->invokeNonPublic($middleware, 'resolveAuditHotelId', [['hotel_id' => '15'], $user]));
        self::assertSame(16, $this->invokeNonPublic($middleware, 'resolveAuditHotelId', [['system_hotel_id' => '16', 'hotel_id' => '15'], $user]));
        self::assertSame(7, $this->invokeNonPublic($middleware, 'resolveAuditHotelId', [[], $user]));
    }

    public function testRateLimitPolicyUsesStricterExportAndWriteBuckets(): void
    {
        $middleware = new Auth();

        $export = $this->invokeNonPublic($middleware, 'resolveRateLimitPolicy', ['GET', '/api/daily-reports/export?hotel_id=7']);
        self::assertSame('export', $export['scope']);
        self::assertSame(10, $export['limit']);
        self::assertSame(3600, $export['window']);

        $write = $this->invokeNonPublic($middleware, 'resolveRateLimitPolicy', ['POST', '/api/online-data/save-daily-data']);
        self::assertSame('write', $write['scope']);
        self::assertSame(60, $write['limit']);
        self::assertSame(60, $write['window']);
    }
}
