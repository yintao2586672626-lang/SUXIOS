<?php
declare(strict_types=1);

namespace Tests;

use app\controller\OperationManagement;
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
}
