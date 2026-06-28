<?php
declare(strict_types=1);

namespace Tests;

use app\model\Role;
use app\model\User;
use app\service\HotelScopeService;
use app\service\PermissionService;
use PHPUnit\Framework\TestCase;

final class PermissionServiceTest extends TestCase
{
    public function testNormalUserCanReadGrantedOtaButCannotCollect(): void
    {
        $service = new PermissionService(new AllowingHotelScopeService());
        $user = $this->userWithRole(['dashboard.view', 'hotel.view', 'ota.view', 'report.view']);

        self::assertTrue($service->authorize($user, 'ota.view', 7)['allowed']);

        $collect = $service->authorize($user, 'ota.collect', 7);
        self::assertFalse($collect['allowed']);
        self::assertSame('role_permission_denied', $collect['reason']);
    }

    public function testVipCapabilityStillRequiresHotelPermissionLayer(): void
    {
        $service = new PermissionService(new DenyingHotelPermissionScopeService());
        $user = $this->userWithRole(['hotel.create', 'hotel.view', 'hotel.update', 'ota.view', 'ota.collect']);

        $authorization = $service->authorize($user, 'ota.collect', 7);

        self::assertFalse($authorization['allowed']);
        self::assertSame('hotel_permission_denied', $authorization['reason']);
    }

    /**
     * @param array<int, string> $permissions
     */
    private function userWithRole(array $permissions): User
    {
        $role = $this->getMockBuilder(Role::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPermissionList', '__get'])
            ->getMock();
        $role->method('getPermissionList')->willReturn($permissions);
        $role->method('__get')->willReturnCallback(
            static fn(string $key) => $key === 'status' ? Role::STATUS_ENABLED : null
        );

        $user = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isSuperAdmin', '__get', '__isset'])
            ->getMock();
        $user->method('isSuperAdmin')->willReturn(false);
        $user->method('__isset')->willReturnCallback(
            static fn(string $key): bool => in_array($key, ['id', 'role'], true)
        );
        $user->method('__get')->willReturnCallback(
            static fn(string $key) => match ($key) {
                'id' => 42,
                'role' => $role,
                default => null,
            }
        );

        return $user;
    }
}

final class AllowingHotelScopeService extends HotelScopeService
{
    public function canAccessHotel(User $user, int $hotelId, ?string $capability = null): bool
    {
        return $hotelId === 7;
    }

    public function hotelPermissionAllows(User $user, int $hotelId, string $capability): bool
    {
        return $hotelId === 7 && in_array($capability, ['ota.view', 'hotel.view', 'report.view'], true);
    }
}

final class DenyingHotelPermissionScopeService extends HotelScopeService
{
    public function canAccessHotel(User $user, int $hotelId, ?string $capability = null): bool
    {
        return $hotelId === 7;
    }

    public function hotelPermissionAllows(User $user, int $hotelId, string $capability): bool
    {
        return false;
    }
}
