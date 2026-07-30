<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Auth;
use app\model\Role;
use app\model\User;
use PHPUnit\Framework\TestCase;

final class AuthHotelPermissionScopeTest extends TestCase
{
    private const HOTEL_PERMISSIONS = [
        'can_view_report',
        'can_fill_daily_report',
        'can_fill_monthly_task',
        'can_edit_report',
        'can_delete_report',
        'can_view_online_data',
        'can_fetch_online_data',
        'can_delete_online_data',
    ];

    public function testNormalUserPermissionPayloadIsScopedToCurrentHotel(): void
    {
        $user = $this->normalUserWithHotelAOnlyPermission();

        $hotelA = $this->buildPermissions($user, 80);
        $hotelB = $this->buildPermissions($user, 81);
        $missingHotel = $this->buildPermissions($user, null);

        foreach (self::HOTEL_PERMISSIONS as $permission) {
            self::assertTrue($hotelA[$permission], '酒店A应允许: ' . $permission);
            self::assertFalse($hotelB[$permission], '酒店B应拒绝: ' . $permission);
            self::assertFalse($missingHotel[$permission], '缺失当前酒店应拒绝: ' . $permission);
        }
    }

    public function testSuperAdminWithoutSelectedHotelKeepsPortfolioPermissionPayload(): void
    {
        $user = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'isSuperAdmin',
                'hasHotelPermission',
                'canManageOwnHotels',
                'canManageUser',
            ])
            ->getMock();
        $user->method('isSuperAdmin')->willReturn(true);
        $user->expects(self::never())->method('hasHotelPermission');
        $user->method('canManageOwnHotels')->willReturn(true);
        $user->method('canManageUser')->willReturn(true);

        $permissions = $this->buildPermissions($user, null);

        foreach (self::HOTEL_PERMISSIONS as $permission) {
            self::assertTrue($permissions[$permission], '超管组合视图应保留: ' . $permission);
        }
    }

    public function testLegacyUserPermissionApiAndControllerServiceCallsStayRemoved(): void
    {
        self::assertFalse(method_exists(User::class, 'hasPermission'));

        $violations = [];
        foreach (['app/controller', 'app/service'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    dirname(__DIR__) . '/' . $directory,
                    \FilesystemIterator::SKIP_DOTS
                )
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }

                $source = file_get_contents($file->getPathname());
                if (is_string($source) && preg_match('/(?:->|::)\s*hasPermission\s*\(/', $source) === 1) {
                    $violations[] = str_replace('\\', '/', $file->getPathname());
                }
            }
        }

        self::assertSame([], $violations, 'Controller/Service 禁止调用 hasPermission()');
    }

    /** @return array<string, bool> */
    private function buildPermissions(User $user, ?int $hotelId): array
    {
        $controller = (new \ReflectionClass(Auth::class))->newInstanceWithoutConstructor();
        $method = (new \ReflectionClass(Auth::class))->getMethod('buildUserPermissions');

        /** @var array<string, bool> $permissions */
        $permissions = $method->invoke($controller, $user, $hotelId);
        return $permissions;
    }

    private function normalUserWithHotelAOnlyPermission(): User
    {
        $role = $this->getMockBuilder(Role::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPermissionList', 'getAttr', '__get'])
            ->getMock();
        $role->method('getPermissionList')->willReturn(self::HOTEL_PERMISSIONS);
        $role->method('getAttr')->willReturnCallback(
            static fn(string $key) => match ($key) {
                'id' => Role::BETA_USER,
                'name' => 'operator',
                'status' => Role::STATUS_ENABLED,
                'level' => 2,
                default => null,
            }
        );
        $role->method('__get')->willReturnCallback(
            static fn(string $key) => match ($key) {
                'id' => Role::BETA_USER,
                'name' => 'operator',
                'status' => Role::STATUS_ENABLED,
                'level' => 2,
                default => null,
            }
        );

        $user = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'isSuperAdmin',
                'hasHotelPermission',
                'canManageOwnHotels',
                'canManageUser',
                '__get',
                '__isset',
            ])
            ->getMock();
        $user->method('isSuperAdmin')->willReturn(false);
        $user->method('hasHotelPermission')->willReturnCallback(
            static fn(int $hotelId, string $permission): bool => $hotelId === 80
                && in_array($permission, self::HOTEL_PERMISSIONS, true)
        );
        $user->method('canManageOwnHotels')->willReturn(false);
        $user->method('canManageUser')->willReturn(false);
        $user->method('__isset')->willReturnCallback(
            static fn(string $key): bool => in_array($key, ['id', 'role_id', 'role'], true)
        );
        $user->method('__get')->willReturnCallback(
            static fn(string $key) => match ($key) {
                'id' => 42,
                'role_id' => Role::BETA_USER,
                'role' => $role,
                default => null,
            }
        );

        return $user;
    }
}
