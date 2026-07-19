<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class InitDatabaseCredentialSecurityTest extends TestCase
{
    public function testDatabaseBootstrapHasNoSharedDefaultPasswords(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/command/InitDatabase.php');

        foreach (['admin123', 'manager123', 'staff123'] as $sharedPassword) {
            self::assertStringNotContainsString($sharedPassword, $source);
        }
        self::assertStringContainsString('random_bytes(12)', $source);
        self::assertStringContainsString("bootstrapPassword('SUXI_BOOTSTRAP_ADMIN_PASSWORD')", $source);
        self::assertStringContainsString("bootstrapPassword('SUXI_BOOTSTRAP_MANAGER_PASSWORD')", $source);
        self::assertStringContainsString("bootstrapPassword('SUXI_BOOTSTRAP_STAFF_PASSWORD')", $source);
    }

    public function testEnvironmentTemplateDefaultsToSafeDebugMode(): void
    {
        $template = (string)file_get_contents(dirname(__DIR__) . '/.example.env');

        self::assertMatchesRegularExpression('/^APP_DEBUG\s*=\s*false\s*$/m', $template);
        self::assertDoesNotMatchRegularExpression('/^APP_DEBUG\s*=\s*true\s*$/m', $template);
    }
}
