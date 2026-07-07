<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Auth;
use app\controller\RoleController;
use app\controller\User as UserController;
use app\model\Role;
use app\model\SystemConfig;
use app\model\User as UserModel;
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

        $dirtyNormalRole = $this->roleWithPermissions([
            'hotel.view',
            'hotel.update',
            'ota.view',
            'ota.collect',
            'ota.delete',
            'can_export_data',
            'ai.execute',
        ], Role::NORMAL_USER, 'normal_user');
        $dirtyNormalDefaults = $method->invoke($controller, $dirtyNormalRole);

        self::assertSame(1, $dirtyNormalDefaults['can_view_online_data']);
        self::assertSame(0, $dirtyNormalDefaults['can_edit']);
        self::assertSame(0, $dirtyNormalDefaults['can_fetch_ota']);
        self::assertSame(0, $dirtyNormalDefaults['can_delete_ota']);
        self::assertSame(0, $dirtyNormalDefaults['can_export']);
        self::assertSame(0, $dirtyNormalDefaults['can_ai']);
        self::assertSame(0, $dirtyNormalDefaults['can_fetch_online_data']);
        self::assertSame(0, $dirtyNormalDefaults['can_delete_online_data']);

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
        self::assertStringContainsString('普通用户角色不能包含 OTA 采集权限或其他高风险权限', (string)$response->getContent());
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
        ], true);

        $permissions = $method->invoke($controller, $user);

        self::assertFalse($permissions['can_manage_own_hotels']);
        self::assertFalse($permissions['can_edit_report']);
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

    public function testAdminLevelAllPermissionStillCountsAsSuperAdmin(): void
    {
        $adminRole = $this->roleWithPermissions(['all'], 99, 'custom_admin', 1);
        $adminUser = $this->userWithRole($adminRole, 99);

        self::assertTrue($adminUser->isSuperAdmin());
        self::assertTrue($adminUser->canManageUser());
    }

    /**
     * @param array<int, string> $permissions
     */
    private function roleWithPermissions(array $permissions, int $id = Role::BETA_USER, string $name = 'operator', int $level = 2): Role
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
                'status' => Role::STATUS_ENABLED,
                'level' => $level,
                default => null,
            }
        );
        $role->method('__get')->willReturnCallback(
            static fn(string $key) => match ($key) {
                'id' => $id,
                'name' => $name,
                'status' => Role::STATUS_ENABLED,
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

    /**
     * @param array<int, string> $legacyPermissions
     */
    private function userWithRoleAndLegacyPermissions(Role $role, int $roleId, array $legacyPermissions, bool $canManageOwnHotels = false): UserModel
    {
        $user = $this->getMockBuilder(UserModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['hasPermission', 'canManageOwnHotels', 'canManageUser', 'isSuperAdmin', '__get', '__isset'])
            ->getMock();

        $user->method('hasPermission')->willReturnCallback(
            static fn(string $permission): bool => in_array($permission, $legacyPermissions, true)
        );
        $user->method('canManageOwnHotels')->willReturn($canManageOwnHotels);
        $user->method('canManageUser')->willReturn(false);
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
