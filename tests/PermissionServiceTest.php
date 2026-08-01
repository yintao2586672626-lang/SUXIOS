<?php
declare(strict_types=1);

namespace Tests;

use app\model\Role;
use app\model\User;
use app\service\HotelScopeService;
use app\service\PermissionService;
use PHPUnit\Framework\TestCase;
use think\exception\HttpException;

final class PermissionServiceTest extends TestCase
{
    public function testHotelPermissionOrFailRejectsHotelBForReadCreateUpdateDelete(): void
    {
        $permissions = [
            'can_view_online_data',
            'can_fetch_online_data',
            'can_edit_report',
            'can_delete_online_data',
        ];
        $user = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['hasHotelPermission'])
            ->getMock();
        $user->method('hasHotelPermission')->willReturnCallback(
            static fn(int $hotelId, string $permission): bool => $hotelId === 7
                && in_array($permission, $permissions, true)
        );

        foreach ($permissions as $permission) {
            $user->hasHotelPermissionOrFail(7, $permission);

            try {
                $user->hasHotelPermissionOrFail(8, $permission);
                self::fail('酒店B必须拒绝权限: ' . $permission);
            } catch (HttpException $e) {
                self::assertSame(403, $e->getStatusCode(), $permission);
            }
        }
    }

    public function testUserAuthorizationHelpersStayInstanceScopedAndShareOneHotelScope(): void
    {
        $firstUser = $this->userWithRole([]);
        $secondUser = $this->userWithRole([]);
        $userReflection = new \ReflectionClass(User::class);
        $hotelScopeMethod = $userReflection->getMethod('hotelScopeService');
        $permissionMethod = $userReflection->getMethod('permissionService');

        $firstHotelScope = $hotelScopeMethod->invoke($firstUser);
        $firstPermissionService = $permissionMethod->invoke($firstUser);
        $secondHotelScope = $hotelScopeMethod->invoke($secondUser);

        self::assertSame($firstHotelScope, $hotelScopeMethod->invoke($firstUser));
        self::assertNotSame($firstHotelScope, $secondHotelScope);

        $permissionReflection = new \ReflectionClass(PermissionService::class);
        $scopeProperty = $permissionReflection->getProperty('hotelScopeService');
        self::assertSame($firstHotelScope, $scopeProperty->getValue($firstPermissionService));
    }

    public function testHotelScopeMemoizationKeySeparatesCapabilitiesWhileWeakMapOwnsObjectIdentity(): void
    {
        $service = new HotelScopeService();
        $method = (new \ReflectionClass($service))->getMethod('userScopeCacheKey');
        $firstUser = $this->userWithRole([]);
        $secondUser = $this->userWithRole([]);

        $firstViewKey = $method->invoke($service, $firstUser, 'ota.view');
        self::assertSame($firstViewKey, $method->invoke($service, $firstUser, 'ota.view'));
        self::assertNotSame($firstViewKey, $method->invoke($service, $firstUser, 'ota.collect'));
        self::assertSame(
            $firstViewKey,
            $method->invoke($service, $secondUser, 'ota.view'),
            'object identity belongs to the WeakMap bucket and must not be encoded with a reusable spl_object_id'
        );
    }

    public function testSuperAdminStillRequiresAnEnabledHotelForHotelScopedAuthorization(): void
    {
        $service = new PermissionService(new AllowingHotelScopeService());
        $user = $this->superAdminUser();

        self::assertTrue($service->authorize($user, 'system.config')['allowed']);
        self::assertTrue($service->authorize($user, 'hotel.update', 7)['allowed']);

        $invalidHotel = $service->authorize($user, 'hotel.update', 8);
        self::assertFalse($invalidHotel['allowed']);
        self::assertSame('hotel_scope_denied', $invalidHotel['reason']);
    }

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
            'user.role_change',
        ], Role::NORMAL_USER, 'normal_user');

        foreach (['hotel.update', 'ota.collect', 'ota.delete', 'ota.export', 'report.export', 'ai.execute', 'can_manage_users'] as $capability) {
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
        self::assertNotContains('user.role_change', $service->roleCapabilities($user));
        self::assertContains('ota.view', $service->roleCapabilities($user));
    }

    public function testLevelThreeRoleCannotUseUnsafeCapabilitiesEvenIfRoleNameIsCustom(): void
    {
        $service = new PermissionService(new AllowingHotelScopeService());
        $user = $this->userWithRole([
            'dashboard.view',
            'hotel.view',
            'hotel.update',
            'ota.view',
            'ota.collect',
            'ota.export',
            'user.role_change',
        ], 9, 'external_reader', 3);

        foreach (['hotel.update', 'ota.collect', 'ota.export', 'can_manage_users'] as $capability) {
            $authorization = $service->authorize($user, $capability, 7);

            self::assertFalse($authorization['allowed'], $capability);
            self::assertSame('role_permission_denied', $authorization['reason'], $capability);
        }
        self::assertContains('ota.view', $service->roleCapabilities($user));
        self::assertNotContains('hotel.update', $service->roleCapabilities($user));
        self::assertNotContains('ota.collect', $service->roleCapabilities($user));
        self::assertNotContains('ota.export', $service->roleCapabilities($user));
        self::assertNotContains('user.role_change', $service->roleCapabilities($user));
    }

    public function testStaffLevelAboveThreeRoleCannotUseUnsafeCapabilitiesEvenIfRoleNameIsCustom(): void
    {
        $service = new PermissionService(new AllowingHotelScopeService());
        $user = $this->userWithRole([
            'dashboard.view',
            'hotel.view',
            'hotel.update',
            'ota.view',
            'ota.collect',
            'ota.export',
            'user.role_change',
        ], 9, 'external_staff_reader', 4);

        foreach (['hotel.update', 'ota.collect', 'ota.export', 'can_manage_users'] as $capability) {
            $authorization = $service->authorize($user, $capability, 7);

            self::assertFalse($authorization['allowed'], $capability);
            self::assertSame('role_permission_denied', $authorization['reason'], $capability);
        }
        self::assertContains('ota.view', $service->roleCapabilities($user));
        self::assertNotContains('hotel.update', $service->roleCapabilities($user));
        self::assertNotContains('ota.collect', $service->roleCapabilities($user));
        self::assertNotContains('ota.export', $service->roleCapabilities($user));
        self::assertNotContains('user.role_change', $service->roleCapabilities($user));
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
    private function userWithRole(array $permissions, int $roleId = Role::BETA_USER, string $roleName = 'operator', int $roleLevel = 2): User
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
                'level' => $roleLevel,
                default => null,
            }
        );
        $role->method('__get')->willReturnCallback(
            static fn(string $key) => match ($key) {
                'id' => $roleId,
                'name' => $roleName,
                'status' => Role::STATUS_ENABLED,
                'level' => $roleLevel,
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

    private function superAdminUser(): User
    {
        $user = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isSuperAdmin'])
            ->getMock();
        $user->method('isSuperAdmin')->willReturn(true);
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
