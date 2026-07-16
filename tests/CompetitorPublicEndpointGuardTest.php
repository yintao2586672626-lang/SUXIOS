<?php
declare(strict_types=1);

namespace Tests;

use app\controller\CompetitorApi;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ReflectionHelper;
use think\App;

final class CompetitorPublicEndpointGuardTest extends TestCase
{
    use ReflectionHelper;

    private static ?App $app = null;

    public static function setUpBeforeClass(): void
    {
        self::$app = new App(dirname(__DIR__));
        self::$app->initialize();
    }

    private function controller(): CompetitorApi
    {
        $reflection = new ReflectionClass(CompetitorApi::class);

        return $reflection->newInstanceWithoutConstructor();
    }

    public function testTaskAssignmentIsDeviceHotelAndPlatformScopedAndConsumedOnce(): void
    {
        $controller = $this->controller();
        $deviceId = 'test-device-' . bin2hex(random_bytes(6));
        $platform = 'xc';
        $storeId = random_int(100000, 999999);
        $hotelId = random_int(1000000, 9999999);
        $key = $this->invokeNonPublic($controller, 'taskAssignmentCacheKey', [$deviceId, $platform, $storeId, $hotelId]);
        $otherDeviceKey = $this->invokeNonPublic($controller, 'taskAssignmentCacheKey', [$deviceId . '-other', $platform, $storeId, $hotelId]);
        $ownerKey = $this->invokeNonPublic($controller, 'taskOwnershipCacheKey', [$platform, $storeId, $hotelId]);
        $completedKey = $this->invokeNonPublic($controller, 'completedReportCacheKey', [$deviceId, $platform, $storeId, $hotelId]);

        try {
            self::assertFalse($this->invokeNonPublic($controller, 'hasTaskAssignment', [$deviceId, $platform, $storeId, $hotelId]));
            self::assertTrue($this->invokeNonPublic($controller, 'rememberTaskAssignment', [$deviceId, $platform, $storeId, $hotelId]));
            self::assertTrue($this->invokeNonPublic($controller, 'hasTaskAssignment', [$deviceId, $platform, $storeId, $hotelId]));
            self::assertFalse($this->invokeNonPublic($controller, 'rememberTaskAssignment', [$deviceId . '-other', $platform, $storeId, $hotelId]));
            self::assertFalse($this->invokeNonPublic($controller, 'hasTaskAssignment', [$deviceId . '-other', $platform, $storeId, $hotelId]));
            self::assertFalse($this->invokeNonPublic($controller, 'hasTaskAssignment', [$deviceId, 'mt', $storeId, $hotelId]));
            self::assertFalse($this->invokeNonPublic($controller, 'hasTaskAssignment', [$deviceId, $platform, $storeId + 1, $hotelId]));
            self::assertFalse($this->invokeNonPublic($controller, 'hasTaskAssignment', [$deviceId, $platform, $storeId, $hotelId + 1]));
            self::assertTrue($this->invokeNonPublic($controller, 'consumeTaskAssignment', [$deviceId, $platform, $storeId, $hotelId]));
            self::assertFalse($this->invokeNonPublic($controller, 'consumeTaskAssignment', [$deviceId, $platform, $storeId, $hotelId]));
        } finally {
            cache($key, null);
            cache($otherDeviceKey, null);
            cache($ownerKey, null);
            cache($completedKey, null);
        }
    }

    public function testCompletedReportAllowsOnlyAnIdenticalIdempotentRetry(): void
    {
        $controller = $this->controller();
        $deviceId = 'test-device-' . bin2hex(random_bytes(6));
        $platform = 'mt';
        $storeId = random_int(100000, 999999);
        $hotelId = random_int(1000000, 9999999);
        $fingerprint = $this->invokeNonPublic($controller, 'reportFingerprint', [
            $deviceId,
            $platform,
            $storeId,
            $hotelId,
            388.0,
            'screenshot-payload',
        ]);
        $completedKey = $this->invokeNonPublic($controller, 'completedReportCacheKey', [$deviceId, $platform, $storeId, $hotelId]);

        try {
            $this->invokeNonPublic($controller, 'rememberCompletedReport', [
                $deviceId,
                $platform,
                $storeId,
                $hotelId,
                $fingerprint,
                801,
            ]);

            $completed = $this->invokeNonPublic($controller, 'completedReport', [
                $deviceId,
                $platform,
                $storeId,
                $hotelId,
                $fingerprint,
            ]);
            self::assertSame(801, $completed['id']);
            self::assertNull($this->invokeNonPublic($controller, 'completedReport', [
                $deviceId,
                $platform,
                $storeId,
                $hotelId,
                str_repeat('0', 64),
            ]));
        } finally {
            cache($completedKey, null);
        }
    }

    public function testPublicWriteEndpointsKeepFailClosedHeaderGuards(): void
    {
        $root = dirname(__DIR__);
        $competitor = (string)file_get_contents($root . '/app/controller/CompetitorApi.php');
        $cookie = (string)file_get_contents($root . '/app/controller/concern/CookieEndpointConcern.php');
        $cron = (string)file_get_contents($root . '/app/controller/concern/AutoFetchConcern.php');
        $patrolCron = (string)file_get_contents($root . '/app/controller/concern/OperationWorkbenchConcern.php');

        self::assertStringContainsString("header('X-Task-Token', '')", $competitor);
        self::assertStringContainsString("header('X-Report-Token', '')", $competitor);
        self::assertStringContainsString('hasTaskAssignment($deviceId, $platform, $storeId, $hotelId)', $competitor);
        self::assertStringContainsString('withTaskAssignmentLock', $competitor);
        self::assertStringContainsString('taskOwnershipCacheKey', $competitor);
        self::assertStringContainsString("'device_not_active'", $competitor);
        self::assertStringContainsString("'legacy_bookmarklet_disabled'", $cookie);
        self::assertStringContainsString("410);", $cookie);
        self::assertStringContainsString("header('X-Cron-Token', '')", $cron);
        self::assertStringContainsString("'cron_token_not_configured'", $cron);
        self::assertStringContainsString("header('X-Cron-Token', '')", $patrolCron);
        self::assertStringContainsString("'cron_token_not_configured'", $patrolCron);
    }
}
