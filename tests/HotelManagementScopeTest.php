<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Base;
use app\controller\Hotel as HotelController;
use app\model\Hotel as HotelModel;
use app\model\User;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class HotelManagementScopeTest extends TestCase
{
    public function testAssignedHotelCanBeManagedByBetaUserWithoutOwnership(): void
    {
        $controller = $this->controllerWithUser($this->hotelManager([7]));
        $hotel = $this->hotel(7, 99);

        self::assertTrue($this->invokeCanManageHotelRecord($controller, $hotel));
    }

    public function testUnassignedHotelCannotBeManagedByBetaUser(): void
    {
        $controller = $this->controllerWithUser($this->hotelManager([7]));
        $hotel = $this->hotel(8, 42);

        self::assertFalse($this->invokeCanManageHotelRecord($controller, $hotel));
    }

    private function controllerWithUser(User $user): HotelController
    {
        $reflection = new ReflectionClass(HotelController::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        $property = new ReflectionProperty(Base::class, 'currentUser');
        $property->setAccessible(true);
        $property->setValue($controller, $user);

        return $controller;
    }

    /**
     * @param array<int, int> $hotelIds
     */
    private function hotelManager(array $hotelIds): User
    {
        $user = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isSuperAdmin', 'canManageOwnHotels', 'getPermittedHotelIds'])
            ->getMock();

        $user->method('isSuperAdmin')->willReturn(false);
        $user->method('canManageOwnHotels')->willReturn(true);
        $user->method('getPermittedHotelIds')->willReturn($hotelIds);

        return $user;
    }

    private function invokeCanManageHotelRecord(HotelController $controller, HotelModel $hotel): bool
    {
        $reflection = new ReflectionClass(HotelController::class);
        $method = $reflection->getMethod('currentUserCanManageHotelRecord');
        $method->setAccessible(true);

        return (bool)$method->invoke($controller, $hotel);
    }

    private function hotel(int $hotelId, int $createdBy): HotelModel
    {
        $hotel = $this->getMockBuilder(HotelModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__get', '__isset'])
            ->getMock();

        $hotel->method('__isset')->willReturnCallback(
            static fn(string $key): bool => in_array($key, ['id', 'created_by'], true)
        );
        $hotel->method('__get')->willReturnCallback(
            static fn(string $key) => match ($key) {
                'id' => $hotelId,
                'created_by' => $createdBy,
                default => null,
            }
        );

        return $hotel;
    }
}
