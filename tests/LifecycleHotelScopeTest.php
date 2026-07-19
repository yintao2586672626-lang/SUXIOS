<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Base;
use app\controller\Lifecycle;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;

final class LifecycleHotelScopeTest extends TestCase
{
    use ReflectionHelper;

    public function testExplicitEmptyPermissionScopeFailsClosed(): void
    {
        $query = new class {
            public array $calls = [];
            public function whereRaw(string $sql): self
            {
                $this->calls[] = ['whereRaw', $sql];
                return $this;
            }
            public function whereIn(string $field, array $ids): self
            {
                $this->calls[] = ['whereIn', $field, $ids];
                return $this;
            }
        };
        $controller = (new \ReflectionClass(Lifecycle::class))->newInstanceWithoutConstructor();

        $result = $this->invokeNonPublic($controller, 'withHotelIds', [$query, [0], 'store_id']);

        self::assertSame($query, $result);
        self::assertSame([['whereRaw', '1 = 0']], $query->calls);
    }

    public function testSuperAdminEmptyScopeRemainsUnfilteredAndPositiveIdsUseStoreField(): void
    {
        $query = new class {
            public array $calls = [];
            public function whereRaw(string $sql): self
            {
                $this->calls[] = ['whereRaw', $sql];
                return $this;
            }
            public function whereIn(string $field, array $ids): self
            {
                $this->calls[] = ['whereIn', $field, $ids];
                return $this;
            }
        };
        $controller = (new \ReflectionClass(Lifecycle::class))->newInstanceWithoutConstructor();

        $this->invokeNonPublic($controller, 'withHotelIds', [$query, [], 'store_id']);
        self::assertSame([], $query->calls);

        $this->invokeNonPublic($controller, 'withHotelIds', [$query, [10, 20], 'store_id']);
        self::assertSame([['whereIn', 'store_id', [10, 20]]], $query->calls);
    }

    public function testLifecycleHotelScopeKeepsOnlyHotelsWithInvestmentPermission(): void
    {
        $controller = (new \ReflectionClass(Lifecycle::class))->newInstanceWithoutConstructor();
        $currentUser = new \ReflectionProperty(Base::class, 'currentUser');
        $currentUser->setAccessible(true);
        $currentUser->setValue($controller, new class {
            public int $id = 77;
            public function isSuperAdmin(): bool { return false; }
            public function getPermittedHotelIds(): array { return [10, 20]; }
            public function hasHotelPermission(int $hotelId, string $permission): bool
            {
                return $permission === 'can_use_investment' && $hotelId === 20;
            }
        });

        self::assertSame([20], $this->invokeNonPublic($controller, 'resolveHotelIds'));
    }

    public function testLifecycleOwnedRecordScopeUsesCurrentUserAndFailsClosedWithoutIdentity(): void
    {
        $query = new class {
            public array $calls = [];
            public function where(string $field, int $value): self
            {
                $this->calls[] = ['where', $field, $value];
                return $this;
            }
            public function whereRaw(string $sql): self
            {
                $this->calls[] = ['whereRaw', $sql];
                return $this;
            }
        };
        $controller = (new \ReflectionClass(Lifecycle::class))->newInstanceWithoutConstructor();
        $currentUser = new \ReflectionProperty(Base::class, 'currentUser');
        $currentUser->setAccessible(true);
        $currentUser->setValue($controller, new class {
            public int $id = 77;
            public function isSuperAdmin(): bool { return false; }
        });

        $this->invokeNonPublic($controller, 'withCurrentUser', [$query, 'created_by']);
        self::assertSame([['where', 'created_by', 77]], $query->calls);
    }
}
