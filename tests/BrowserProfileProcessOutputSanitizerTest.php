<?php
declare(strict_types=1);

use app\service\platform\BrowserProfileProcessOutputSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BrowserProfileProcessOutputSanitizerTest extends TestCase
{
    public static function sensitiveProcessOutputProvider(): array
    {
        return [
            ['Authorization: Bearer abc.def.ghi'],
            ['cookie=sensitive-cookie-value'],
            ['--refresh-token sensitive-token-value'],
            ['https://user:password@example.invalid/path'],
            ['{\"\\u0063\\u006f\\u006f\\u006b\\u0069\\u0065\":\"value\"}'],
        ];
    }

    #[DataProvider('sensitiveProcessOutputProvider')]
    public function testSensitiveMaterialIsRedactedFromLogsAndSummaries(string $line): void
    {
        $log = BrowserProfileProcessOutputSanitizer::sanitizeLog("safe prelude\n{$line}\nsafe tail");

        self::assertStringContainsString('[redacted_sensitive_process_output]', $log);
        self::assertStringNotContainsString($line, $log);
        self::assertSame(
            'browser_profile_process_error_redacted',
            BrowserProfileProcessOutputSanitizer::summarize($line, '')
        );
    }

    public function testKnownRuntimeErrorKeepsSafeRecoveryHint(): void
    {
        self::assertSame(
            'browser_runtime_error=spawn EPERM; check browser executable permission and scheduled-task runtime account.',
            BrowserProfileProcessOutputSanitizer::summarize('Error: spawn EPERM', '')
        );
    }

    public function testNodeUncaughtExceptionReportsTheUnderlyingSafeReason(): void
    {
        $stderr = "node:internal/process/promises:394\n"
            . "triggerUncaughtException(err, true);\n"
            . "^\n"
            . "browserContext.newCDPSession: Target page, context or browser has been closed\n"
            . "at safeFrame (capture.mjs:1:1)";

        self::assertSame(
            'browserContext.newCDPSession: Target page, context or browser has been closed',
            BrowserProfileProcessOutputSanitizer::summarize($stderr, '')
        );
    }
}
