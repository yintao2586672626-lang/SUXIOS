<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class ManualNotificationContractTest extends TestCase
{
    public function testRoutesStayBehindAuthenticationMiddleware(): void
    {
        $routes = (string)file_get_contents(dirname(__DIR__) . '/route/app.php');
        self::assertMatchesRegularExpression(
            "/Route::group\\('api\\/manual-notifications'.*?"
            . "Route::get\\('\\/metadata', 'ManualNotification\\/metadata'\\);.*?"
            . "Route::get\\('\\/history', 'ManualNotification\\/history'\\);.*?"
            . "Route::post\\('\\/preview', 'ManualNotification\\/preview'\\);.*?"
            . "Route::post\\('\\/:id\\/test-push', 'ManualNotification\\/testPush'\\);.*?"
            . "Route::get\\('\\/:id', 'ManualNotification\\/read'\\);.*?"
            . "Route::post\\('\\/', 'ManualNotification\\/save'\\);.*?"
            . "middleware\\(\\\\app\\\\middleware\\\\Auth::class\\);/s",
            $routes
        );
    }

    public function testControllerRequiresHotelPermissionForReadAndWritePaths(): void
    {
        $controller = (string)file_get_contents(
            dirname(__DIR__) . '/app/controller/ManualNotification.php'
        );
        self::assertStringContainsString("authorizedScope('can_view_report'", $controller);
        self::assertStringContainsString("authorizedScope('can_fill_daily_report'", $controller);
        self::assertStringContainsString('hasHotelPermissionOrFail(', $controller);
        self::assertStringContainsString('当前账号没有该酒店的通知权限', $controller);
    }

    public function testServiceDoesNotReadWebhookOrOtaCredentials(): void
    {
        $service = (string)file_get_contents(
            dirname(__DIR__) . '/app/service/ManualNotificationService.php'
        );
        self::assertStringContainsString('resolvePlanRobot(', $service);
        self::assertStringContainsString("'wecom_formal'", $service);
        self::assertStringNotContainsString("->where('id', self::TEST_ROBOT_ID)", $service);
        self::assertStringNotContainsString("['webhook']", $service);
        self::assertStringNotContainsString('WechatRobotWebhookSecret', $service);
        self::assertStringNotContainsString('Cookie', $service);
        self::assertStringNotContainsString('ota_credentials', $service);
    }
}
