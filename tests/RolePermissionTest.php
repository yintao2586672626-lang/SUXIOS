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

    public function testCapabilityFormatMapsToLegacyPermissionChecks(): void
    {
        $permissions = Role::normalizePermissions('["dashboard.view","hotel.view","ota.view","report.view"]');

        self::assertTrue(Role::permissionListAllows($permissions, 'can_view_online_data'));
        self::assertTrue(Role::permissionListAllows($permissions, 'can_view_report'));
        self::assertFalse(Role::permissionListAllows($permissions, 'can_fetch_online_data'));
        self::assertFalse(Role::permissionListAllows($permissions, 'hotel.create'));
    }

    public function testLegacyPermissionFormatMapsToCapabilityChecks(): void
    {
        $permissions = Role::normalizePermissions('["can_manage_own_hotels","can_fetch_online_data","can_use_ai_decision"]');

        self::assertTrue(Role::permissionListAllows($permissions, 'hotel.create'));
        self::assertTrue(Role::permissionListAllows($permissions, 'hotel.delete'));
        self::assertTrue(Role::permissionListAllows($permissions, 'ota.collect'));
        self::assertTrue(Role::permissionListAllows($permissions, 'ai.execute'));
        self::assertFalse(Role::permissionListAllows($permissions, 'system.config'));
    }

    public function testHotelCreateCapabilityAllowsAssignedStoreDeletionGate(): void
    {
        $permissions = Role::normalizePermissions('["dashboard.view","hotel.create","hotel.view","hotel.update"]');

        self::assertTrue(Role::permissionListAllows($permissions, 'hotel.delete'));
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
