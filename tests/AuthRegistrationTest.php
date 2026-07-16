<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Auth;
use app\controller\Base;
use app\controller\RoleController;
use app\controller\User as UserController;
use app\model\Role;
use app\model\User as UserModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class AuthRegistrationTest extends TestCase
{
    public function testPublicRegistrationIsHardDisabledWithoutLegacyWriter(): void
    {
        $reflection = new ReflectionClass(Auth::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $response = $controller->register();

        self::assertSame(403, $response->getCode());
        self::assertStringContainsString('系统已关闭自助注册', (string)$response->getContent());
        self::assertFalse($reflection->hasMethod('registerLegacyDisabled'));
        self::assertFalse($reflection->hasMethod('buildSelfRegistrationHotelPermissionDefaults'));
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
        self::assertStringContainsString('普通用户角色不能包含 OTA 采集权限或其他高风险权限', (string)$response->getContent());
    }

    public function testLevelThreeExternalUserIssueRejectsUnsafePermission(): void
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
        ], 9, 'external_reader', 3), [7]);

        self::assertSame(422, $response->getCode());
        self::assertStringContainsString('OTA', (string)$response->getContent());
    }

    public function testStaffLevelAboveThreeExternalUserIssueRejectsUnsafePermission(): void
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
        ], 9, 'external_staff_reader', 4), [7]);

        self::assertSame(422, $response->getCode());
        self::assertStringContainsString('OTA', (string)$response->getContent());
    }

    public function testRenamedNormalRoleStillRejectsUnsafePermissionOnRoleSave(): void
    {
        $reflection = new ReflectionClass(RoleController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('validateRolePermissionBoundary');
        $method->setAccessible(true);

        $existingRole = $this->roleWithPermissions(['can_view_online_data'], Role::NORMAL_USER, 'external_reader', 3);
        $response = $method->invoke($controller, 'external_reader', ['can_view_online_data', 'ota.collect'], $existingRole);

        self::assertSame(422, $response->getCode());
        self::assertStringContainsString('普通用户角色不能包含 OTA 采集权限或其他高风险权限', (string)$response->getContent());
    }

    public function testLevelThreeRoleSaveRejectsUnsafePermission(): void
    {
        $reflection = new ReflectionClass(RoleController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('validateRolePermissionBoundary');
        $method->setAccessible(true);

        $response = $method->invoke($controller, 'external_reader', ['hotel.view', 'ota.view', 'ota.collect'], null, 3);

        self::assertSame(422, $response->getCode());
        self::assertStringContainsString('OTA', (string)$response->getContent());
    }

    public function testStaffLevelAboveThreeRoleSaveRejectsUnsafePermission(): void
    {
        $reflection = new ReflectionClass(RoleController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('validateRolePermissionBoundary');
        $method->setAccessible(true);

        $response = $method->invoke($controller, 'external_staff_reader', ['hotel.view', 'ota.view', 'ota.collect'], null, 4);

        self::assertSame(422, $response->getCode());
        self::assertStringContainsString('OTA', (string)$response->getContent());
    }

    public function testNormalRoleSaveRejectsLegacyUserManagementPermission(): void
    {
        $reflection = new ReflectionClass(RoleController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('validateRolePermissionBoundary');
        $method->setAccessible(true);

        $response = $method->invoke($controller, 'normal_user', ['hotel.view', 'ota.view', 'can_manage_users'], null, 3);

        self::assertSame(422, $response->getCode());
        self::assertStringContainsString('user.role_change', (string)$response->getContent());
    }

    public function testBuiltInExternalRoleIdentityCannotBeChanged(): void
    {
        $reflection = new ReflectionClass(RoleController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('validateBuiltInExternalRoleIdentity');
        $method->setAccessible(true);

        $betaRole = $this->roleWithPermissions(['can_view_online_data'], Role::BETA_USER, 'beta_user', 2);

        $nameResponse = $method->invoke($controller, $betaRole, ['name' => 'admin', 'level' => 2]);
        self::assertSame(422, $nameResponse->getCode());
        self::assertStringContainsString('内置外发角色的标识和等级不能修改', (string)$nameResponse->getContent());

        $levelResponse = $method->invoke($controller, $betaRole, ['name' => 'beta_user', 'level' => 1]);
        self::assertSame(422, $levelResponse->getCode());
        self::assertStringContainsString('内置外发角色的标识和等级不能修改', (string)$levelResponse->getContent());

        self::assertNull($method->invoke($controller, $betaRole, ['name' => 'beta_user', 'level' => 2]));
    }

    public function testLoginPermissionsHideLegacyDeniedGrantsForNormalExternalUser(): void
    {
        $reflection = new ReflectionClass(Auth::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('buildUserPermissions');
        $method->setAccessible(true);

        $role = $this->roleWithPermissions(
            ['dashboard.view', 'hotel.view', 'hotel.update', 'ota.view', 'can_fetch_online_data', 'can_delete_online_data', 'can_export_data', 'can_edit_report'],
            Role::NORMAL_USER,
            'normal_user'
        );
        $user = $this->userWithRoleAndLegacyPermissions($role, Role::NORMAL_USER, [
            'can_manage_own_hotels',
            'can_edit_report',
            'can_fetch_online_data',
            'can_delete_online_data',
            'can_export_data',
        ], true, true);

        $permissions = $method->invoke($controller, $user);

        self::assertFalse($permissions['can_manage_own_hotels']);
        self::assertFalse($permissions['can_manage_users']);
        self::assertFalse($permissions['can_edit_report']);
        self::assertFalse($permissions['can_fetch_online_data']);
        self::assertFalse($permissions['can_delete_online_data']);
        self::assertFalse($permissions['can_export_data']);
    }

    public function testLoginPermissionsHideDeniedGrantsForLevelThreeRole(): void
    {
        $reflection = new ReflectionClass(Auth::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('buildUserPermissions');
        $method->setAccessible(true);

        $role = $this->roleWithPermissions(
            ['dashboard.view', 'hotel.view', 'hotel.update', 'ota.view', 'can_fetch_online_data', 'can_delete_online_data', 'can_export_data'],
            9,
            'external_reader',
            3
        );
        $user = $this->userWithRoleAndLegacyPermissions($role, 9, [
            'can_manage_own_hotels',
            'can_fetch_online_data',
            'can_delete_online_data',
            'can_export_data',
        ], true, true);

        $permissions = $method->invoke($controller, $user);

        self::assertFalse($permissions['can_manage_own_hotels']);
        self::assertFalse($permissions['can_manage_users']);
        self::assertFalse($permissions['can_fetch_online_data']);
        self::assertFalse($permissions['can_delete_online_data']);
        self::assertFalse($permissions['can_export_data']);
    }

    public function testExternalRolesWithAllPermissionDoNotBecomeSuperAdmin(): void
    {
        $normalRole = $this->roleWithPermissions(['all'], Role::NORMAL_USER, 'normal_user', 3);
        $normalUser = $this->userWithRole($normalRole, Role::NORMAL_USER);

        self::assertFalse($normalUser->isSuperAdmin());
        self::assertFalse($normalUser->canManageUser());

        $betaRole = $this->roleWithPermissions(['all'], Role::BETA_USER, 'beta_user', 2);
        $betaUser = $this->userWithRole($betaRole, Role::BETA_USER);

        self::assertFalse($betaUser->isSuperAdmin());
        self::assertFalse($betaUser->canManageUser());
    }

    public function testTamperedBuiltInExternalRoleStillCannotBecomeSuperAdmin(): void
    {
        $betaRole = $this->roleWithPermissions(['all'], Role::BETA_USER, 'admin', 1);
        $betaUser = $this->userWithRole($betaRole, Role::BETA_USER);

        self::assertFalse($betaUser->isSuperAdmin());
        self::assertFalse($betaUser->canManageUser());

        $normalRole = $this->roleWithPermissions(['all'], Role::NORMAL_USER, 'admin', 1);
        $normalUser = $this->userWithRole($normalRole, Role::NORMAL_USER);

        self::assertFalse($normalUser->isSuperAdmin());
        self::assertFalse($normalUser->canManageUser());
    }

    public function testUserHotelScopeDoesNotTreatExternalLevelRolesAsSuperAdmin(): void
    {
        $reflection = new ReflectionClass(UserController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('userDataIsSuperAdmin');
        $method->setAccessible(true);

        self::assertFalse($method->invoke($controller, [
            'role_id' => 8,
            'role' => [
                'id' => 8,
                'name' => 'VIPUser',
                'level' => 2,
                'permissions' => json_encode(['all'], JSON_THROW_ON_ERROR),
            ],
        ]));

        self::assertFalse($method->invoke($controller, [
            'role_id' => Role::NORMAL_USER,
            'role' => [
                'id' => Role::NORMAL_USER,
                'name' => 'normal_user',
                'level' => 3,
                'permissions' => json_encode(['all'], JSON_THROW_ON_ERROR),
            ],
        ]));

        self::assertTrue($method->invoke($controller, [
            'role_id' => 99,
            'role' => [
                'id' => 99,
                'name' => 'custom_admin',
                'level' => 1,
                'permissions' => json_encode(['all'], JSON_THROW_ON_ERROR),
            ],
        ]));
    }

    public function testOnlySuperAdminCanEditExistingBetaUsername(): void
    {
        $reflection = new ReflectionClass(UserController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('canEditUserUsername');
        $method->setAccessible(true);
        $currentUser = new ReflectionProperty(Base::class, 'currentUser');
        $currentUser->setAccessible(true);

        $betaUser = $this->userWithRole($this->roleWithPermissions(['dashboard.view'], Role::BETA_USER, 'beta_user', 2), Role::BETA_USER);
        $normalUser = $this->userWithRole($this->roleWithPermissions(['dashboard.view'], Role::NORMAL_USER, 'normal_user', 3), Role::NORMAL_USER);

        $currentUser->setValue($controller, $this->currentUserWithSuperAdminFlag(true));
        self::assertTrue($method->invoke($controller, $betaUser));
        self::assertFalse($method->invoke($controller, $normalUser));

        $currentUser->setValue($controller, $this->currentUserWithSuperAdminFlag(false));
        self::assertFalse($method->invoke($controller, $betaUser));
    }

    public function testAdminLevelAllPermissionStillCountsAsSuperAdmin(): void
    {
        $adminRole = $this->roleWithPermissions(['all'], 99, 'custom_admin', 1);
        $adminUser = $this->userWithRole($adminRole, 99);

        self::assertTrue($adminUser->isSuperAdmin());
        self::assertTrue($adminUser->canManageUser());
    }

    public function testCanManageOwnHotelsFollowsRuntimeRolePolicy(): void
    {
        $betaWithoutHotelCreateRole = $this->roleWithPermissions(['dashboard.view', 'hotel.view'], Role::BETA_USER, 'beta_user', 2);
        $betaWithoutHotelCreate = $this->userWithRole($betaWithoutHotelCreateRole, Role::BETA_USER);
        self::assertFalse($betaWithoutHotelCreate->canManageOwnHotels());

        $betaWithHotelCreateRole = $this->roleWithPermissions(['dashboard.view', 'hotel.view', 'hotel.create'], Role::BETA_USER, 'beta_user', 2);
        $betaWithHotelCreate = $this->userWithRole($betaWithHotelCreateRole, Role::BETA_USER);
        self::assertTrue($betaWithHotelCreate->canManageOwnHotels());

        $levelThreeRole = $this->roleWithPermissions(['dashboard.view', 'hotel.view', 'hotel.create'], 9, 'external_reader', 3);
        $levelThreeUser = $this->userWithRole($levelThreeRole, 9);
        self::assertFalse($levelThreeUser->canManageOwnHotels());
    }

    public function testLevelTwoCustomRoleIsTreatedAsBetaUser(): void
    {
        $levelTwoRole = $this->roleWithPermissions(['dashboard.view', 'hotel.view'], 9, 'external_beta_reader', 2);
        $levelTwoUser = $this->userWithRole($levelTwoRole, 9);

        self::assertTrue($levelTwoUser->isBetaUser());
        self::assertTrue($levelTwoUser->isHotelManager());
    }

    public function testDisabledExternalRolesDoNotExposeIssueIdentities(): void
    {
        $disabledBetaRole = $this->roleWithPermissions(
            ['dashboard.view', 'hotel.view'],
            Role::BETA_USER,
            'beta_user',
            2,
            Role::STATUS_DISABLED
        );
        $disabledBetaUser = $this->userWithRole($disabledBetaRole, Role::BETA_USER);

        self::assertFalse($disabledBetaUser->isBetaUser());
        self::assertFalse($disabledBetaUser->isHotelManager());

        $disabledNormalRole = $this->roleWithPermissions(
            ['dashboard.view', 'hotel.view'],
            Role::NORMAL_USER,
            'normal_user',
            3,
            Role::STATUS_DISABLED
        );
        $disabledNormalUser = $this->userWithRole($disabledNormalRole, Role::NORMAL_USER);

        self::assertFalse($disabledNormalUser->isStaff());
    }

    public function testLevelTwoCustomRoleReceivesBetaBindingNotice(): void
    {
        $reflection = new ReflectionClass(Auth::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('buildLoginNotices');
        $method->setAccessible(true);

        $levelTwoRole = $this->roleWithPermissions(['dashboard.view', 'hotel.view'], 9, 'external_beta_reader', 2);
        $levelTwoUser = $this->userWithRole($levelTwoRole, 9);

        $notices = $method->invoke($controller, $levelTwoUser, [['id' => 7, 'name' => 'Test Hotel']]);

        self::assertCount(1, $notices);
        self::assertSame('beta_hotel_binding_deadline', $notices[0]['type']);
        self::assertArrayNotHasKey('deadline', $notices[0]);
        self::assertStringContainsString('未绑定或未分配的门店将无法查看', $notices[0]['message']);
        self::assertStringNotContainsString('2026-07-05', $notices[0]['message']);
    }

    public function testDeniedRequestedHotelDoesNotBecomeActiveAuthContext(): void
    {
        $reflection = new ReflectionClass(Auth::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $request = new class {
            public function get(string $key, $default = null)
            {
                return match ($key) {
                    'system_hotel_id' => 999,
                    'platform' => 'ctrip',
                    default => $default,
                };
            }
        };
        $requestProperty = new ReflectionProperty(Base::class, 'request');
        $requestProperty->setAccessible(true);
        $requestProperty->setValue($controller, $request);

        $user = $this->getMockBuilder(UserModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__get', '__isset'])
            ->getMock();
        $user->method('__isset')->willReturnCallback(static fn(string $key): bool => $key === 'hotel_id');
        $user->method('__get')->willReturnCallback(static fn(string $key): ?int => $key === 'hotel_id' ? 7 : null);

        $method = $reflection->getMethod('buildAuthContext');
        $method->setAccessible(true);
        $context = $method->invoke($controller, $user, [[
            'id' => 7,
            'tenant_id' => 70,
            'name' => 'Permitted Hotel',
        ]]);

        self::assertSame(7, $context['hotelId']);
        self::assertSame(70, $context['tenantId']);
        self::assertSame('Permitted Hotel', $context['currentHotelName']);
        self::assertSame('denied', $context['permissionStatus']);
        self::assertSame(999, $context['requestedHotelId']);
        self::assertNotSame($context['requestedHotelId'], $context['hotelId']);
    }

    /**
     * @param array<int, string> $permissions
     */
    private function roleWithPermissions(
        array $permissions,
        int $id = Role::BETA_USER,
        string $name = 'operator',
        int $level = 2,
        int $status = Role::STATUS_ENABLED
    ): Role
    {
        $role = $this->getMockBuilder(Role::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPermissionList', 'getAttr', '__get'])
            ->getMock();

        $role->method('getPermissionList')->willReturn($permissions);
        $role->method('getAttr')->willReturnCallback(
            static fn(string $key) => match ($key) {
                'id' => $id,
                'name' => $name,
                'status' => $status,
                'level' => $level,
                default => null,
            }
        );
        $role->method('__get')->willReturnCallback(
            static fn(string $key) => match ($key) {
                'id' => $id,
                'name' => $name,
                'status' => $status,
                'level' => $level,
                default => null,
            }
        );

        return $role;
    }

    private function userWithRole(Role $role, int $roleId): UserModel
    {
        $user = $this->getMockBuilder(UserModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__get', '__isset'])
            ->getMock();

        $user->method('__isset')->willReturnCallback(
            static fn(string $key): bool => in_array($key, ['role_id', 'role'], true)
        );
        $user->method('__get')->willReturnCallback(
            static fn(string $key) => match ($key) {
                'role_id' => $roleId,
                'role' => $role,
                default => null,
            }
        );

        return $user;
    }

    private function currentUserWithSuperAdminFlag(bool $isSuperAdmin): UserModel
    {
        $user = $this->getMockBuilder(UserModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isSuperAdmin'])
            ->getMock();

        $user->method('isSuperAdmin')->willReturn($isSuperAdmin);

        return $user;
    }

    /**
     * @param array<int, string> $legacyPermissions
     */
    private function userWithRoleAndLegacyPermissions(
        Role $role,
        int $roleId,
        array $legacyPermissions,
        bool $canManageOwnHotels = false,
        bool $canManageUsers = false
    ): UserModel
    {
        $user = $this->getMockBuilder(UserModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['hasPermission', 'canManageOwnHotels', 'canManageUser', 'isSuperAdmin', '__get', '__isset'])
            ->getMock();

        $user->method('hasPermission')->willReturnCallback(
            static fn(string $permission): bool => in_array($permission, $legacyPermissions, true)
        );
        $user->method('canManageOwnHotels')->willReturn($canManageOwnHotels);
        $user->method('canManageUser')->willReturn($canManageUsers);
        $user->method('isSuperAdmin')->willReturn(false);
        $user->method('__isset')->willReturnCallback(
            static fn(string $key): bool => in_array($key, ['role_id', 'role'], true)
        );
        $user->method('__get')->willReturnCallback(
            static fn(string $key) => match ($key) {
                'role_id' => $roleId,
                'role' => $role,
                default => null,
            }
        );

        return $user;
    }
}
