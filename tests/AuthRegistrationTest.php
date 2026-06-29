<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Auth;
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

    /**
     * @param array<int, string> $permissions
     */
    private function roleWithPermissions(array $permissions): Role
    {
        $role = $this->getMockBuilder(Role::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPermissionList'])
            ->getMock();

        $role->method('getPermissionList')->willReturn($permissions);

        return $role;
    }
}
