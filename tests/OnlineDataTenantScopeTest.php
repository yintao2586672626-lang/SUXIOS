<?php
declare(strict_types=1);

namespace Tests;

use app\controller\OnlineData;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ReflectionHelper;
use think\exception\HttpException;

final class OnlineDataTenantScopeTest extends TestCase
{
    use ReflectionHelper;

    public function testNonSuperUserDefaultsToOnlyPermittedHotel(): void
    {
        $controller = $this->controllerWithUser($this->tenantUser([7]));

        self::assertSame(7, $this->invokeNonPublic($controller, 'resolveOnlineDataSystemHotelId', [null]));
    }

    public function testNonSuperUserCanUseRequestedPermittedHotel(): void
    {
        $controller = $this->controllerWithUser($this->tenantUser([7, 8]));

        self::assertSame(8, $this->invokeNonPublic($controller, 'resolveOnlineDataSystemHotelId', [8]));
    }

    public function testNonSuperUserCannotUseUnpermittedHotel(): void
    {
        $controller = $this->controllerWithUser($this->tenantUser([7]));

        $this->expectException(HttpException::class);

        $this->invokeNonPublic($controller, 'resolveOnlineDataSystemHotelId', [99]);
    }

    public function testNonSuperMultiHotelUserMustChooseHotel(): void
    {
        $controller = $this->controllerWithUser($this->tenantUser([7, 8]));

        $this->expectException(HttpException::class);

        $this->invokeNonPublic($controller, 'resolveOnlineDataSystemHotelId', [null]);
    }

    public function testSuperAdminCanUseRequestedHotel(): void
    {
        $controller = $this->controllerWithUser($this->tenantUser([], true));

        self::assertSame(99, $this->invokeNonPublic($controller, 'resolveOnlineDataSystemHotelId', [99]));
    }

    private function controllerWithUser(object $user): OnlineData
    {
        $reflection = new ReflectionClass(OnlineData::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        $property = $reflection->getParentClass()->getProperty('currentUser');
        $property->setAccessible(true);
        $property->setValue($controller, $user);

        return $controller;
    }

    /**
     * @param array<int, int> $hotelIds
     */
    private function tenantUser(array $hotelIds, bool $superAdmin = false): object
    {
        return new class($hotelIds, $superAdmin) {
            /**
             * @param array<int, int> $hotelIds
             */
            public function __construct(private array $hotelIds, private bool $superAdmin)
            {
            }

            public function isSuperAdmin(): bool
            {
                return $this->superAdmin;
            }

            /**
             * @return array<int, int>
             */
            public function getPermittedHotelIds(): array
            {
                return $this->hotelIds;
            }
        };
    }
}
