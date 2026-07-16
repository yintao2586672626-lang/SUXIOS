<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class UserIssuedDefaultPasswordTest extends TestCase
{
    public function testUserControllerHasNoPredictableIssuedPasswordBypass(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/controller/User.php');

        self::assertStringNotContainsString('ISSUED_DEFAULT_PASSWORD', $source);
        self::assertStringNotContainsString('isIssuedDefaultPassword', $source);
        self::assertStringContainsString("validatePasswordPolicy((string)\$data['password'], '密码')", $source);
    }
}
