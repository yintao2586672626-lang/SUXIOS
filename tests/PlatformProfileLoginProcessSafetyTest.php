<?php
declare(strict_types=1);

namespace Tests;

use app\command\PlatformProfileLogin;
use app\service\BrowserProfileCaptureRequestService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class PlatformProfileLoginProcessSafetyTest extends TestCase
{
    public function testProcessLogRedactsAuthorizationBeforeFirstAppend(): void
    {
        $logPath = BrowserProfileCaptureRequestService::createEphemeralCaptureFile('profile-login-log-test', 'log');
        self::assertNotSame('', $logPath);
        try {
            $method = new ReflectionMethod(PlatformProfileLogin::class, 'runProcess');
            $method->setAccessible(true);
            $result = $method->invoke(
                new PlatformProfileLogin(),
                [
                    PHP_BINARY,
                    '-r',
                    'fwrite(STDOUT,"safe prelude\\nAuthorization: Bearer fixture-super-secret-token\\nsafe tail\\n");usleep(4000000);',
                ],
                dirname(__DIR__),
                10,
                $logPath
            );

            self::assertTrue($result['success']);
            $log = (string)file_get_contents($logPath);
            self::assertStringContainsString('[redacted_sensitive_process_output]', $log);
            self::assertStringContainsString('safe prelude', $log);
            self::assertStringNotContainsString('fixture-super-secret-token', $log);
            self::assertTrue(BrowserProfileCaptureRequestService::prepareEphemeralCaptureFileForWrite($logPath));
        } finally {
            if (is_file($logPath)) {
                unlink($logPath);
            }
        }
    }

    public function testLoginCommandQuarantinesOutputLogAndFieldConfigWhenTreeIsUnconfirmed(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/command/PlatformProfileLogin.php');
        self::assertStringContainsString('BrowserProfileProcessOutputSanitizer::sanitizeLog(', $source);
        self::assertStringContainsString('prepareEphemeralCaptureFileForWrite($outputPath, true)', $source);
        self::assertStringContainsString('prepareEphemeralCaptureFileForWrite($logPath, true)', $source);
        self::assertStringContainsString("str_starts_with((string)\$arg, '--field-config=')", $source);
        self::assertStringContainsString('quarantineEphemeralArtifactsIfUnconfirmed($result, $ephemeralArtifacts)', $source);
    }
}
