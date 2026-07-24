<?php
declare(strict_types=1);

namespace Tests;

use app\controller\OperationManagement;
use app\model\User;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ReflectionHelper;

final class OperationManagementControllerTest extends TestCase
{
    use ReflectionHelper;

    public function testStrategyExecutionIntentRequiresExecutableRuleScenario(): void
    {
        $controller = (new ReflectionClass(OperationManagement::class))->newInstanceWithoutConstructor();

        self::assertFalse($this->invokeNonPublic($controller, 'canCreateStrategyExecutionIntent', [[
            'simulated' => false,
            'status' => 'insufficient_data',
        ]]));
        self::assertFalse($this->invokeNonPublic($controller, 'canCreateStrategyExecutionIntent', [[
            'simulated' => true,
            'status' => 'insufficient_data',
        ]]));
        self::assertTrue($this->invokeNonPublic($controller, 'canCreateStrategyExecutionIntent', [[
            'simulated' => true,
            'status' => 'rule_scenario',
        ]]));
    }

    public function testHotelScopeFiltersWriteOperationsByExecuteCapability(): void
    {
        $reflection = new ReflectionClass(OperationManagement::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $user = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPermittedHotelIds', 'hasHotelPermission'])
            ->getMock();
        $user->method('getPermittedHotelIds')->willReturn([7, 8]);
        $user->method('hasHotelPermission')->willReturnCallback(
            static fn(int $hotelId, string $capability): bool => $capability === 'operation.view'
                || ($capability === 'operation.execute' && $hotelId === 7)
        );

        $baseReflection = $reflection->getParentClass();
        self::assertNotFalse($baseReflection);
        $currentUser = $baseReflection->getProperty('currentUser');
        $currentUser->setAccessible(true);
        $currentUser->setValue($controller, $user);
        $request = $baseReflection->getProperty('request');
        $request->setAccessible(true);
        $request->setValue($controller, new class {
            public function param(string $key, mixed $default = null): mixed
            {
                return $default;
            }
        });

        self::assertSame([[7, 8], null], $this->invokeNonPublic($controller, 'resolveHotelScope', [0, 'operation.view']));
        self::assertSame([[7], 7], $this->invokeNonPublic($controller, 'resolveHotelScope', [0, 'operation.execute']));

        $this->expectException(\RuntimeException::class);
        $this->invokeNonPublic($controller, 'resolveHotelScope', [8, 'operation.execute']);
    }
}
