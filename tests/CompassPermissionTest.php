<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Base;
use app\controller\admin\Compass;
use app\model\User;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class CompassPermissionTest extends TestCase
{
    public function testSuperAdminUsesGlobalCompassLayoutKey(): void
    {
        $controller = $this->controllerWithUser($this->user(1, true));

        self::assertSame('compass_layout', $this->invokeLayoutConfigKey($controller));
    }

    public function testHotelBoundUserUsesOwnCompassLayoutKey(): void
    {
        $controller = $this->controllerWithUser($this->user(42, false));

        self::assertSame('compass_layout_user_42', $this->invokeLayoutConfigKey($controller));
    }

    private function controllerWithUser(User $user): Compass
    {
        $reflection = new ReflectionClass(Compass::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        $property = new ReflectionProperty(Base::class, 'currentUser');
        $property->setAccessible(true);
        $property->setValue($controller, $user);

        return $controller;
    }

    private function invokeLayoutConfigKey(Compass $controller): string
    {
        $reflection = new ReflectionClass(Compass::class);
        $method = $reflection->getMethod('layoutConfigKey');
        $method->setAccessible(true);

        return (string)$method->invoke($controller);
    }

    private function user(int $id, bool $superAdmin): User
    {
        $user = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isSuperAdmin', '__get', '__isset'])
            ->getMock();

        $user->method('isSuperAdmin')->willReturn($superAdmin);
        $user->method('__isset')->willReturnCallback(
            static fn(string $key): bool => $key === 'id'
        );
        $user->method('__get')->willReturnCallback(
            static fn(string $key) => $key === 'id' ? $id : null
        );

        return $user;
    }
}
