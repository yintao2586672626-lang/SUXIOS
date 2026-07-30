<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Base;
use app\controller\OnlineData;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;

final class CtripCollectorWorkflowScopeTest extends TestCase
{
    use ReflectionHelper;

    public function testCollectorSourceRequiresCtripPlatformAndHotelPermission(): void
    {
        $controller = (new \ReflectionClass(OnlineData::class))->newInstanceWithoutConstructor();
        $currentUser = new \ReflectionProperty(Base::class, 'currentUser');
        $currentUser->setAccessible(true);
        $currentUser->setValue($controller, new class {
            public function isSuperAdmin(): bool { return false; }
            public function hasHotelPermission(int $hotelId, string $permission): bool
            {
                return $hotelId === 7 && $permission === 'can_view_online_data';
            }
        });

        self::assertTrue($this->invokeNonPublic($controller, 'canAccessCtripCollectorSource', [[
            'platform' => 'ctrip',
            'system_hotel_id' => 7,
        ]]));
        self::assertFalse($this->invokeNonPublic($controller, 'canAccessCtripCollectorSource', [[
            'platform' => 'ctrip',
            'system_hotel_id' => 8,
        ]]));
        self::assertFalse($this->invokeNonPublic($controller, 'canAccessCtripCollectorSource', [[
            'platform' => 'meituan',
            'system_hotel_id' => 7,
        ]]));
        self::assertFalse($this->invokeNonPublic($controller, 'canAccessCtripCollectorSource', [[
            'platform' => 'ctrip',
            'system_hotel_id' => 0,
        ]]));
    }
}
