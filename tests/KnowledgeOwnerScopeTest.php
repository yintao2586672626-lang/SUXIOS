<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Knowledge;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionObject;
use think\exception\ValidateException;
use Tests\Support\ReflectionHelper;

final class KnowledgeOwnerScopeTest extends TestCase
{
    use ReflectionHelper;

    public function testKnowledgeScopeAllowsOnlyCreatorForNonSuperAdmin(): void
    {
        $controller = $this->controllerWithUser(7, false, [11]);

        self::assertTrue($this->invokeNonPublic($controller, 'canAccessOwnedRow', [
            ['created_by' => 7, 'hotel_id' => 11],
        ]));
        self::assertFalse($this->invokeNonPublic($controller, 'canAccessOwnedRow', [
            ['created_by' => 8, 'hotel_id' => 11],
        ]));
        self::assertFalse($this->invokeNonPublic($controller, 'canAccessOwnedRow', [
            ['created_by' => 7, 'hotel_id' => 12],
        ]));
        self::assertFalse($this->invokeNonPublic($controller, 'canAccessOwnedRow', [
            ['created_by' => 7, 'hotel_id' => 0, 'status' => 'done'],
        ]));
        self::assertTrue($this->invokeNonPublic($controller, 'canAccessOwnedRow', [[
            'created_by' => 0,
            'hotel_id' => 0,
            'status' => 'done',
        ]]));
    }

    public function testKnowledgeScopeAllowsEverythingForSuperAdmin(): void
    {
        $controller = $this->controllerWithUser(7, true);

        self::assertTrue($this->invokeNonPublic($controller, 'canAccessOwnedRow', [
            ['created_by' => 8],
        ]));
    }

    public function testResolveKnowledgeImportHotelRequiresExplicitHotelForMultiHotelUser(): void
    {
        $controller = $this->controllerWithUser(7, false, [11, 12]);

        if (!method_exists($controller, 'resolveKnowledgeImportHotelId')) {
            self::fail('resolveKnowledgeImportHotelId is required');
        }

        $this->expectException(ValidateException::class);

        $this->invokeNonPublic($controller, 'resolveKnowledgeImportHotelId', [0]);
    }

    public function testResolveKnowledgeImportHotelAllowsOnlyPermittedHotel(): void
    {
        $controller = $this->controllerWithUser(7, false, [11, 12]);

        if (!method_exists($controller, 'resolveKnowledgeImportHotelId')) {
            self::fail('resolveKnowledgeImportHotelId is required');
        }

        self::assertSame(12, $this->invokeNonPublic($controller, 'resolveKnowledgeImportHotelId', [12]));
    }

    public function testResolveKnowledgeImportHotelRejectsUnpermittedHotel(): void
    {
        $controller = $this->controllerWithUser(7, false, [11, 12]);

        if (!method_exists($controller, 'resolveKnowledgeImportHotelId')) {
            self::fail('resolveKnowledgeImportHotelId is required');
        }

        $this->expectException(ValidateException::class);

        $this->invokeNonPublic($controller, 'resolveKnowledgeImportHotelId', [99]);
    }

    /**
     * @param array<int, int> $permittedHotelIds
     */
    private function controllerWithUser(int $userId, bool $isSuperAdmin, array $permittedHotelIds = []): Knowledge
    {
        $controller = (new ReflectionClass(Knowledge::class))->newInstanceWithoutConstructor();
        $reflection = new ReflectionObject($controller);
        $property = $reflection->getParentClass()->getProperty('currentUser');
        $property->setAccessible(true);
        $property->setValue($controller, new class($userId, $isSuperAdmin, $permittedHotelIds) {
            public int $id;
            private bool $isSuperAdmin;

            /**
             * @param array<int, int> $permittedHotelIds
             */
            public function __construct(int $id, bool $isSuperAdmin, private array $permittedHotelIds)
            {
                $this->id = $id;
                $this->isSuperAdmin = $isSuperAdmin;
            }

            public function isSuperAdmin(): bool
            {
                return $this->isSuperAdmin;
            }

            /**
             * @return array<int, int>
             */
            public function getPermittedHotelIds(): array
            {
                return $this->permittedHotelIds;
            }
        });

        return $controller;
    }
}
