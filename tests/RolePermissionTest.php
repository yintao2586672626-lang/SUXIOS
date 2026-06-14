<?php
declare(strict_types=1);

namespace Tests;

use app\model\Role;
use PHPUnit\Framework\TestCase;

final class RolePermissionTest extends TestCase
{
    public function testPermissionObjectFormatSupportsAllPermission(): void
    {
        $permissions = Role::normalizePermissions('{"all":true}');

        self::assertTrue(Role::permissionListAllows($permissions, 'can_view_report'));
    }

    public function testLegacyReportPermissionAliasesAreAccepted(): void
    {
        $permissions = Role::normalizePermissions('["report_view","report_fill","report_edit","report_delete","hotel_view"]');

        self::assertTrue(Role::permissionListAllows($permissions, 'can_view_report'));
        self::assertTrue(Role::permissionListAllows($permissions, 'can_fill_daily_report'));
        self::assertTrue(Role::permissionListAllows($permissions, 'can_fill_monthly_task'));
        self::assertTrue(Role::permissionListAllows($permissions, 'can_edit_report'));
        self::assertTrue(Role::permissionListAllows($permissions, 'can_delete_report'));
        self::assertTrue(Role::permissionListAllows($permissions, 'can_view_online_data'));
        self::assertFalse(Role::permissionListAllows($permissions, 'can_fetch_online_data'));
    }

    public function testAccessTierRoleIdsAreStable(): void
    {
        self::assertSame(1, Role::ADMIN);
        self::assertSame(2, Role::BETA_USER);
        self::assertSame(3, Role::NORMAL_USER);
        self::assertSame(Role::ADMIN, Role::SUPER_ADMIN);
        self::assertSame(Role::BETA_USER, Role::HOTEL_MANAGER);
        self::assertSame(Role::NORMAL_USER, Role::HOTEL_STAFF);
    }
}
