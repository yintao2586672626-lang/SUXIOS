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

    public function testNormalUserCannotUseOtaMutationsEvenIfLegacyRoleStillContainsThem(): void
    {
        $service = new PermissionService(new AllowingHotelScopeService());
        $user = $this->userWithRole([
            'dashboard.view',
            'hotel.view',
            'ota.view',
            'hotel.update',
            'can_fetch_online_data',
            'can_delete_online_data',
            'can_export_data',
            'can_use_ai_decision',
        ], Role::NORMAL_USER, 'normal_user');

        foreach (['hotel.update', 'ota.collect', 'ota.delete', 'ota.export', 'report.export', 'ai.execute'] as $capability) {
            $authorization = $service->authorize($user, $capability, 7);

            self::assertFalse($authorization['allowed'], $capability);
            self::assertSame('role_permission_denied', $authorization['reason'], $capability);
        }
        self::assertNotContains('can_fetch_online_data', $service->roleCapabilities($user));
        self::assertNotContains('ota.collect', $service->roleCapabilities($user));
        self::assertNotContains('can_delete_online_data', $service->roleCapabilities($user));
        self::assertNotContains('ota.delete', $service->roleCapabilities($user));
        self::assertNotContains('can_export_data', $service->roleCapabilities($user));
        self::assertNotContains('ota.export', $service->roleCapabilities($user));
        self::assertNotContains('hotel.update', $service->roleCapabilities($user));
        self::assertNotContains('can_use_ai_decision', $service->roleCapabilities($user));
        self::assertContains('ota.view', $service->roleCapabilities($user));
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
    private function userWithRole(array $permissions, int $roleId = Role::BETA_USER, string $roleName = 'operator'): User
    {
        $role = $this->getMockBuilder(Role::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPermissionList', 'getAttr', '__get'])
            ->getMock();
        $role->method('getPermissionList')->willReturn($permissions);
        $role->method('getAttr')->willReturnCallback(
            static fn(string $key) => match ($key) {
                'id' => $roleId,
                'name' => $roleName,
                'status' => Role::STATUS_ENABLED,
                default => null,
            }
        );
        $role->method('__get')->willReturnCallback(
            static fn(string $key) => match ($key) {
                'id' => $roleId,
                'name' => $roleName,
                'status' => Role::STATUS_ENABLED,
                default => null,
            }
        );

        $user = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isSuperAdmin', '__get', '__isset'])
            ->getMock();
        $user->method('isSuperAdmin')->willReturn(false);
        $user->method('__isset')->willReturnCallback(
            static fn(string $key): bool => in_array($key, ['id', 'role_id', 'role'], true)
        );
        $user->method('__get')->willReturnCallback(
            static fn(string $key) => match ($key) {
                'id' => 42,
                'role_id' => $roleId,
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
