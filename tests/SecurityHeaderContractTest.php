<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class SecurityHeaderContractTest extends TestCase
{
    public function testCspIsReportOnlyAndHstsRequiresVerifiedHttps(): void
    {
        $router = (string)file_get_contents(dirname(__DIR__) . '/public/router.php');
        $htaccess = (string)file_get_contents(dirname(__DIR__) . '/public/.htaccess');

        self::assertStringContainsString('Content-Security-Policy-Report-Only', $router);
        self::assertStringContainsString("script-src-attr 'none'", $router);
        self::assertStringNotContainsString("header('Content-Security-Policy:", $router);
        self::assertStringContainsString('Strict-Transport-Security: max-age=31536000', $router);
        self::assertStringContainsString("SERVER_PORT", $router);
        self::assertStringNotContainsString('HTTP_X_FORWARDED_PROTO', $router);

        self::assertStringContainsString('Header always set Content-Security-Policy-Report-Only', $htaccess);
        self::assertStringNotContainsString('Header always set Content-Security-Policy "', $htaccess);
        self::assertStringContainsString(
            'Header always set Strict-Transport-Security "max-age=31536000" "expr=%{HTTPS} == \'on\'"',
            $htaccess
        );
    }
}
