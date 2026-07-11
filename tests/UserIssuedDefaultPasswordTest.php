<?php
declare(strict_types=1);

namespace Tests;

use app\controller\User;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class UserIssuedDefaultPasswordTest extends TestCase
{
    public function testIssuedDefaultPasswordIsExactlySixSixes(): void
    {
        $reflection = new ReflectionClass(User::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('isIssuedDefaultPassword');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($controller, '666666'));
        self::assertFalse($method->invoke($controller, '666'));
        self::assertFalse($method->invoke($controller, '666666 '));
    }
}
