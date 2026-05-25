<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Knowledge;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionObject;
use Tests\Support\ReflectionHelper;

final class KnowledgeOwnerScopeTest extends TestCase
{
    use ReflectionHelper;

    public function testKnowledgeScopeAllowsOnlyCreatorForNonSuperAdmin(): void
    {
        $controller = $this->controllerWithUser(7, false);

        self::assertTrue($this->invokeNonPublic($controller, 'canAccessOwnedRow', [
            ['created_by' => 7],
        ]));
        self::assertFalse($this->invokeNonPublic($controller, 'canAccessOwnedRow', [
            ['created_by' => 8],
        ]));
    }

    public function testKnowledgeScopeAllowsEverythingForSuperAdmin(): void
    {
        $controller = $this->controllerWithUser(7, true);

        self::assertTrue($this->invokeNonPublic($controller, 'canAccessOwnedRow', [
            ['created_by' => 8],
        ]));
    }

    private function controllerWithUser(int $userId, bool $isSuperAdmin): Knowledge
    {
        $controller = (new ReflectionClass(Knowledge::class))->newInstanceWithoutConstructor();
        $reflection = new ReflectionObject($controller);
        $property = $reflection->getParentClass()->getProperty('currentUser');
        $property->setAccessible(true);
        $property->setValue($controller, new class($userId, $isSuperAdmin) {
            public int $id;
            private bool $isSuperAdmin;

            public function __construct(int $id, bool $isSuperAdmin)
            {
                $this->id = $id;
                $this->isSuperAdmin = $isSuperAdmin;
            }

            public function isSuperAdmin(): bool
            {
                return $this->isSuperAdmin;
            }
        });

        return $controller;
    }
}
