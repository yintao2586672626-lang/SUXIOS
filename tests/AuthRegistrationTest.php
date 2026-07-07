<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Auth;
use app\controller\User as UserController;
use app\model\Role;
use app\model\SystemConfig;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AuthRegistrationTest extends TestCase
{
    public function testDefaultConfigEnablesSelfRegistration(): void
    {
        $defaults = SystemConfig::getDefaultConfigs();

        self::assertSame('1', $defaults[SystemConfig::KEY_ENABLE_REGISTRATION]);
    }

    public function testRegistrationEnabledConfigParsing(): void
    {
        $reflection = new ReflectionClass(Auth::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('isEnabledConfigValue');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($controller, '1'));
        self::assertTrue($method->invoke($controller, 'yes'));
        self::assertFalse($method->invoke($controller, '0'));
        self::assertFalse($method->invoke($controller, ''));
    }

    public function testSelfRegistrationHotelPermissionDefaultsFollowRoleCapabilities(): void
    {
        $reflection = new ReflectionClass(Auth::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('buildSelfRegistrationHotelPermissionDefaults');
        $method->setAccessible(true);

        $normalRole = $this->roleWithPermissions(['dashboard.view', 'hotel.view', 'ota.view', 'report.view']);
        $normalDefaults = $method->invoke($controller, $normalRole);

        self::assertSame(1, $normalDefaults['can_view_online_data']);
        self::assertSame(0, $normalDefaults['can_fetch_online_data']);
        self::assertSame(0, $normalDefaults['can_delete_online_data']);

        $operatorRole = $this->roleWithPermissions(['hotel.view', 'ota.view', 'ota.collect', 'ota.delete']);
        $operatorDefaults = $method->invoke($controller, $operatorRole);

        self::assertSame(1, $operatorDefaults['can_view_online_data']);
        self::assertSame(1, $operatorDefaults['can_fetch_online_data']);
        self::assertSame(1, $operatorDefaults['can_delete_online_data']);
    }

    public function testNormalExternalUserIssueRejectsOtaCollectionPermission(): void
    {
        $reflection = new ReflectionClass(UserController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('validateExternalUserIssueBoundary');
        $method->setAccessible(true);

        $response = $method->invoke($controller, $this->roleWithPermissions([
            'dashboard.view',
            'hotel.view',
            'ota.view',
            'ota.collect',
        ], Role::NORMAL_USER, 'normal_user'), [7]);

        self::assertSame(422, $response->getCode());
        self::assertStringContainsString('普通用户角色不能包含 OTA 采集权限', (string)$response->getContent());
    }

    /**
     * @param array<int, string> $permissions
     */
    private function roleWithPermissions(array $permissions, int $id = Role::BETA_USER, string $name = 'operator'): Role
    {
        $role = $this->getMockBuilder(Role::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPermissionList', 'getAttr'])
            ->getMock();

        $role->method('getPermissionList')->willReturn($permissions);
        $role->method('getAttr')->willReturnCallback(
            static fn(string $key) => match ($key) {
                'id' => $id,
                'name' => $name,
                default => null,
            }
        );

        return $role;
    }
}
