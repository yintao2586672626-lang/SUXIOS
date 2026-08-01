<?php
declare(strict_types=1);

namespace Tests;

use app\controller\concern\OnlineDataSupportConcern;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;

final class OnlineDataHotelActionScopeTest extends TestCase
{
    use ReflectionHelper;

    public function testMixedHotelPermissionsOnlyReturnHotelsAllowedForTheRequestedAction(): void
    {
        $controller = new class {
            use OnlineDataSupportConcern;

            public object $currentUser;
        };
        $controller->currentUser = new class {
            public function isSuperAdmin(): bool
            {
                return false;
            }

            /** @return array<int, int> */
            public function getPermittedHotelIds(): array
            {
                return [80, 124, 125];
            }

            public function hasHotelPermission(int $hotelId, string $permission): bool
            {
                return $permission === 'can_delete_online_data' && $hotelId === 80;
            }
        };

        self::assertSame(
            [80],
            $this->invokeNonPublic($controller, 'permittedHotelIdsForAction', ['can_delete_online_data'])
        );
    }

    public function testRecordWritesUseTargetHotelPermissionAndActionScopedAllowlist(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__) . '/app/controller/concern/OnlineDataRecordConcern.php'
        );

        self::assertStringContainsString(
            "checkHotelActionPermission(\$hotelId, 'can_fetch_online_data')",
            $source
        );
        self::assertStringContainsString(
            "checkHotelActionPermission(\$hotelId, 'can_delete_online_data')",
            $source
        );
        self::assertGreaterThanOrEqual(
            3,
            substr_count($source, "permittedHotelIdsForAction('can_delete_online_data')")
        );
        self::assertStringNotContainsString(
            "whereIn('system_hotel_id', \$this->currentUser->getPermittedHotelIds())",
            $source
        );
    }
}
