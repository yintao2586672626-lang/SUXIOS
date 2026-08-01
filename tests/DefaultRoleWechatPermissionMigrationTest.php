<?php
declare(strict_types=1);

namespace Tests;

use app\model\Role;
use app\model\User;
use app\service\HotelScopeService;
use app\service\PermissionService;
use PHPUnit\Framework\TestCase;

final class DefaultRoleWechatPermissionMigrationTest extends TestCase
{
    private const BROAD_MIGRATION = '20260728_u_grant_role2_wechat_notification_access.sql';
    private const RESTRICT_MIGRATION = '20260728_v_restrict_role2_wechat_notification_access.sql';

    public function testImmutableBroadMigrationIsFollowedByScopedCorrectiveMigration(): void
    {
        $root = dirname(__DIR__);
        $broadPath = $root . '/database/migrations/' . self::BROAD_MIGRATION;
        $broadSql = (string)file_get_contents($broadPath);
        $restrictSql = (string)file_get_contents(
            $root . '/database/migrations/' . self::RESTRICT_MIGRATION
        );

        self::assertSame(
            'd3c3156103cd06d2409ea24e5bf3cea99b800abb1cfe29a928a14477b5a3a3df',
            hash_file('sha256', $broadPath),
            'The already-registered u migration is immutable.'
        );
        self::assertStringContainsString(
            "sv.`migration` = '" . self::BROAD_MIGRATION . "'",
            $restrictSql
        );
        self::assertStringContainsString('JSON_REMOVE(', $restrictSql);
        self::assertStringContainsString(
            "JSON_SEARCH(r.`permissions`, 'one', 'report.fill', NULL, '$[*]')",
            $restrictSql
        );
        self::assertMatchesRegularExpression('/WHERE\s+r\.`id`\s*=\s*2\b/', $restrictSql);
        self::assertStringContainsString("r.`name` = 'beta_user'", $restrictSql);
        self::assertStringContainsString('r.`level` = 2', $restrictSql);
        self::assertStringContainsString('r.`status` = 1', $restrictSql);
        self::assertStringContainsString('JSON_LENGTH(r.`permissions`) = 15', $restrictSql);
        self::assertStringContainsString(
            'r.`update_time` BETWEEN DATE_SUB(sv.`executed_at`, INTERVAL 30 SECOND) AND sv.`executed_at`',
            $restrictSql
        );

        foreach ($this->defaultRolePermissions(Role::BETA_USER) as $permission) {
            self::assertStringContainsString(
                "JSON_QUOTE('{$permission}')",
                $restrictSql,
                "The default-role identity guard must include {$permission}."
            );
        }

        self::assertStringContainsString('JSON_ARRAY_APPEND(`permissions`, \'$\', \'report.fill\')', $broadSql);
        self::assertStringContainsString('JSON_VALID(`permissions`) = 1', $broadSql);
        self::assertStringContainsString('JSON_TYPE(`permissions`) = \'ARRAY\'', $broadSql);
        self::assertStringContainsString(
            'JSON_CONTAINS(`permissions`, JSON_QUOTE(\'report.fill\'), \'$\') = 0',
            $broadSql
        );
        self::assertMatchesRegularExpression('/WHERE\s+`id`\s*=\s*2\b/', $broadSql);
        self::assertDoesNotMatchRegularExpression('/WHERE\s+`id`\s*=\s*3\b/', $broadSql);
        self::assertDoesNotMatchRegularExpression('/WHERE\s+r\.`id`\s*=\s*3\b/', $restrictSql);
    }

    public function testMigratedDefaultBetaRoleCanFillReportWhileNormalRoleRemainsDenied(): void
    {
        $betaPermissions = $this->defaultRolePermissions(Role::BETA_USER);
        self::assertNotContains('report.fill', $betaPermissions, 'The forward migration must close the legacy default-role gap.');
        $betaPermissions[] = 'report.fill';

        $service = new PermissionService(new DefaultRoleReportFillHotelScopeService());
        $betaAuthorization = $service->authorize(
            $this->userWithRole(Role::BETA_USER, 'beta_user', 2, $betaPermissions),
            'can_fill_daily_report',
            7
        );

        self::assertTrue($betaAuthorization['allowed']);
        self::assertSame('authorized', $betaAuthorization['reason']);

        $restrictedBetaPermissions = ['dashboard.view', 'report.view'];
        $restrictedBetaAuthorization = $service->authorize(
            $this->userWithRole(Role::BETA_USER, 'custom_restricted', 2, $restrictedBetaPermissions),
            'can_fill_daily_report',
            7
        );
        self::assertFalse($restrictedBetaAuthorization['allowed']);
        self::assertSame('role_permission_denied', $restrictedBetaAuthorization['reason']);

        $customExistingGrant = $service->authorize(
            $this->userWithRole(
                Role::BETA_USER,
                'custom_report_operator',
                2,
                [...$restrictedBetaPermissions, 'report.fill']
            ),
            'can_fill_daily_report',
            7
        );
        self::assertTrue($customExistingGrant['allowed']);
        self::assertSame('authorized', $customExistingGrant['reason']);

        $normalPermissions = $this->defaultRolePermissions(Role::NORMAL_USER);
        self::assertNotContains('report.fill', $normalPermissions);
        $normalAuthorization = $service->authorize(
            $this->userWithRole(Role::NORMAL_USER, 'normal_user', 3, $normalPermissions),
            'can_fill_daily_report',
            7
        );

        self::assertFalse($normalAuthorization['allowed']);
        self::assertSame('role_permission_denied', $normalAuthorization['reason']);

        $normalPermissions[] = 'report.fill';
        $defensiveAuthorization = $service->authorize(
            $this->userWithRole(Role::NORMAL_USER, 'normal_user', 3, $normalPermissions),
            'can_fill_daily_report',
            7
        );
        self::assertFalse($defensiveAuthorization['allowed']);
        self::assertSame('role_permission_denied', $defensiveAuthorization['reason']);
    }

    /**
     * @return array<int, string>
     */
    private function defaultRolePermissions(int $roleId): array
    {
        $sql = (string)file_get_contents(
            dirname(__DIR__) . '/database/migrations/20260614_add_access_tier_hotel_owner_scope.sql'
        );

        $roleStatements = preg_split('/(?=UPDATE\s+`roles`)/i', $sql);
        self::assertIsArray($roleStatements);

        foreach ($roleStatements as $statement) {
            if (preg_match('/^\s*UPDATE\s+`roles`/i', $statement) !== 1
                || preg_match('/WHERE\s+`id`\s*=\s*' . $roleId . '\s*;/i', $statement) !== 1
            ) {
                continue;
            }

            self::assertSame(
                1,
                preg_match('/`permissions`\s*=\s*\'([^\']+)\'/i', $statement, $match),
                "Default role {$roleId} must declare a JSON permission list."
            );

            return Role::normalizePermissions($match[1]);
        }

        self::fail("Default role {$roleId} was not found in the access-tier migration.");
    }

    /**
     * @param array<int, string> $permissions
     */
    private function userWithRole(
        int $roleId,
        string $roleName,
        int $roleLevel,
        array $permissions
    ): User {
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
                'id' => 42 + $roleId,
                'role_id' => $roleId,
                'role' => $role,
                default => null,
            }
        );

        return $user;
    }
}

final class DefaultRoleReportFillHotelScopeService extends HotelScopeService
{
    public function canAccessHotel(User $user, int $hotelId, ?string $capability = null): bool
    {
        return $hotelId === 7;
    }

    public function hotelPermissionAllows(User $user, int $hotelId, string $capability): bool
    {
        return $hotelId === 7 && $capability === 'report.fill';
    }
}
