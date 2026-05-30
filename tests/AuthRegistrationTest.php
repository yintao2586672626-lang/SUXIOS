<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Auth;
use app\model\SystemConfig;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AuthRegistrationTest extends TestCase
{
    public function testDefaultConfigEnablesSelfRegistration(): void
    {
        $defaults = SystemConfig::getDefaultConfigs();

        self::assertSame('1', $defaults[SystemConfig::KEY_ENABLE_REGISTRATION]);
    }

    public function testRegistrationEnabledConfigParsing(): void
    {
        $reflection = new ReflectionClass(Auth::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('isEnabledConfigValue');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($controller, '1'));
        self::assertTrue($method->invoke($controller, 'yes'));
        self::assertFalse($method->invoke($controller, '0'));
        self::assertFalse($method->invoke($controller, ''));
    }
}
