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
        self::assertStringContainsString("style-src 'self'", $router);
        self::assertStringNotContainsString("'unsafe-inline'", $router);
        self::assertStringNotContainsString("header('Content-Security-Policy:", $router);
        self::assertStringContainsString('Strict-Transport-Security: max-age=31536000', $router);
        self::assertStringNotContainsString("SERVER_PORT", $router);
        self::assertStringNotContainsString('HTTP_X_FORWARDED_PROTO', $router);

        self::assertStringContainsString('Header always set Content-Security-Policy-Report-Only', $htaccess);
        self::assertStringNotContainsString('Header always set Content-Security-Policy "', $htaccess);
        self::assertStringContainsString(
            'Header always set Strict-Transport-Security "max-age=31536000" "expr=%{HTTPS} == \'on\'"',
            $htaccess
        );

        preg_match('/SUXI_CSP_REPORT_ONLY = "([^"]+)"/', $router, $routerPolicy);
        preg_match('/Content-Security-Policy-Report-Only "([^"]+)"/', $htaccess, $apachePolicy);
        self::assertSame($routerPolicy[1] ?? null, $apachePolicy[1] ?? null);

        $index = (string)file_get_contents(dirname(__DIR__) . '/public/index.html');
        preg_match_all('/<script(?<attributes>[^>]*)>(?<body>[\s\S]*?)<\/script>/i', $index, $scriptMatches, PREG_SET_ORDER);
        $inlineScriptCount = 0;
        foreach ($scriptMatches as $scriptMatch) {
            $attributes = (string)($scriptMatch['attributes'] ?? '');
            if (preg_match('/\bsrc\s*=/i', $attributes) === 1 || str_contains($attributes, 'application/json')) {
                continue;
            }
            $inlineScriptCount++;
            $hash = base64_encode(hash('sha256', (string)$scriptMatch['body'], true));
            self::assertStringContainsString("'sha256-{$hash}'", (string)($routerPolicy[1] ?? ''));
        }

        self::assertSame(3, $inlineScriptCount);
        self::assertStringNotContainsString(' onerror=', $index);

        $bootstrap = (string)file_get_contents(dirname(__DIR__) . '/public/app-bootstrap.js');
        self::assertDoesNotMatchRegularExpression('/\sstyle=["\']/', $bootstrap);
    }
}
