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
        $bindingId = random_int(1000, 9999);
        $tokenVersion = 3;
        $key = $this->invokeNonPublic($controller, 'taskAssignmentCacheKey', [$deviceId, $platform, $storeId, $hotelId, $bindingId, $tokenVersion]);
        $otherDeviceKey = $this->invokeNonPublic($controller, 'taskAssignmentCacheKey', [$deviceId . '-other', $platform, $storeId, $hotelId, $bindingId + 1, $tokenVersion]);
        $ownerKey = $this->invokeNonPublic($controller, 'taskOwnershipCacheKey', [$platform, $storeId, $hotelId]);
        $completedKey = $this->invokeNonPublic($controller, 'completedReportCacheKey', [$deviceId, $platform, $storeId, $hotelId, $bindingId, $tokenVersion]);

        try {
            $scope = [$deviceId, $platform, $storeId, $hotelId, $bindingId, $tokenVersion];
            self::assertFalse($this->invokeNonPublic($controller, 'hasTaskAssignment', $scope));
            self::assertTrue($this->invokeNonPublic($controller, 'rememberTaskAssignment', $scope));
            self::assertTrue($this->invokeNonPublic($controller, 'hasTaskAssignment', $scope));
            self::assertFalse($this->invokeNonPublic($controller, 'hasTaskAssignment', [$deviceId, $platform, $storeId, $hotelId, $bindingId, $tokenVersion + 1]));
            self::assertFalse($this->invokeNonPublic($controller, 'rememberTaskAssignment', [$deviceId . '-other', $platform, $storeId, $hotelId, $bindingId + 1, $tokenVersion]));
            self::assertFalse($this->invokeNonPublic($controller, 'hasTaskAssignment', [$deviceId, 'mt', $storeId, $hotelId, $bindingId, $tokenVersion]));
            self::assertFalse($this->invokeNonPublic($controller, 'hasTaskAssignment', [$deviceId, $platform, $storeId + 1, $hotelId, $bindingId, $tokenVersion]));
            self::assertFalse($this->invokeNonPublic($controller, 'hasTaskAssignment', [$deviceId, $platform, $storeId, $hotelId + 1, $bindingId, $tokenVersion]));
            self::assertTrue($this->invokeNonPublic($controller, 'consumeTaskAssignment', $scope));
            self::assertFalse($this->invokeNonPublic($controller, 'consumeTaskAssignment', $scope));
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
        $bindingId = random_int(1000, 9999);
        $tokenVersion = 2;
        $fingerprint = $this->invokeNonPublic($controller, 'reportFingerprint', [
            $deviceId,
            $platform,
            $storeId,
            $hotelId,
            388.0,
            'screenshot-payload',
        ]);
        $completedKey = $this->invokeNonPublic($controller, 'completedReportCacheKey', [$deviceId, $platform, $storeId, $hotelId, $bindingId, $tokenVersion]);

        try {
            $this->invokeNonPublic($controller, 'rememberCompletedReport', [
                $deviceId,
                $platform,
                $storeId,
                $hotelId,
                $fingerprint,
                801,
                $bindingId,
                $tokenVersion,
            ]);

            $completed = $this->invokeNonPublic($controller, 'completedReport', [
                $deviceId,
                $platform,
                $storeId,
                $hotelId,
                $fingerprint,
                $bindingId,
                $tokenVersion,
            ]);
            self::assertSame(801, $completed['id']);
            self::assertNull($this->invokeNonPublic($controller, 'completedReport', [
                $deviceId,
                $platform,
                $storeId,
                $hotelId,
                str_repeat('0', 64),
                $bindingId,
                $tokenVersion,
            ]));
            self::assertNull($this->invokeNonPublic($controller, 'completedReport', [
                $deviceId,
                $platform,
                $storeId,
                $hotelId,
                $fingerprint,
                $bindingId,
                $tokenVersion + 1,
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
        self::assertStringContainsString('findAuthorizedBinding(', $competitor);
        self::assertStringContainsString("where('store_id', \$storeId)", $competitor);
        self::assertStringContainsString("where('tenant_id', \$tenantId)", $competitor);
        self::assertStringContainsString('$bindingId, $tokenVersion', $competitor);
        self::assertStringNotContainsString('$device = new CompetitorDevice();', $competitor);
        self::assertStringContainsString('withTaskAssignmentLock', $competitor);
        self::assertStringContainsString('taskOwnershipCacheKey', $competitor);
        self::assertStringContainsString("'device_not_active'", $competitor);
        self::assertStringContainsString("'target_competitor_hotel_ids'", $competitor);
        self::assertStringContainsString("'competitor_hotel_id'", $competitor);
        self::assertStringContainsString("'report_persist_failed:'", $competitor);
        self::assertStringNotContainsString("null, \$hotelId, 'invalid_report", $competitor);
        self::assertStringContainsString("'legacy_bookmarklet_disabled'", $cookie);
        self::assertStringContainsString("410);", $cookie);
        self::assertStringContainsString("header('X-Cron-Token', '')", $cron);
        self::assertStringContainsString("'cron_token_not_configured'", $cron);
        self::assertStringContainsString("header('X-Cron-Token', '')", $patrolCron);
        self::assertStringContainsString("'cron_token_not_configured'", $patrolCron);
    }

    public function testReceiveCookiesRateLimitsPostBeforeRejectedOriginAuditAndKeepsOptionsBranch(): void
    {
        $cookie = (string)file_get_contents(
            dirname(__DIR__) . '/app/controller/concern/CookieEndpointConcern.php'
        );
        $methodStart = strpos($cookie, 'public function receiveCookies(): Response');
        $methodEnd = strpos($cookie, 'private function recordPublicEndpointFailure', $methodStart ?: 0);
        self::assertNotFalse($methodStart);
        self::assertNotFalse($methodEnd);

        $method = substr($cookie, (int)$methodStart, (int)$methodEnd - (int)$methodStart);
        $rateLimit = strpos($method, "checkPublicEndpointRateLimit('receive_cookies'");
        $originAudit = strpos($method, "recordPublicEndpointFailure('receive_cookies', 'origin_not_allowed'");
        self::assertNotFalse($rateLimit);
        self::assertNotFalse($originAudit);
        self::assertTrue($rateLimit < $originAudit);
        self::assertStringContainsString("\$isOptions = \$this->request->method() === 'OPTIONS'", $method);
        self::assertStringContainsString('if (!$isOptions)', $method);
    }
}
