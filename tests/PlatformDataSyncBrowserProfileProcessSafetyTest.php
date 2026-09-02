<?php
declare(strict_types=1);

namespace Tests;

use app\service\platform\BrowserProfileProcessOutputSanitizer;
use app\service\platform\CtripBrowserProfileDataSourceAdapter;
use app\service\platform\MeituanBrowserProfileDataSourceAdapter;
use PHPUnit\Framework\TestCase;
use Tests\Support\PlatformDataSyncBrowserProfileFixture;

final class PlatformDataSyncBrowserProfileProcessSafetyTest extends TestCase
{
    use PlatformDataSyncBrowserProfileFixture;

    public function testBrowserProfileCaptureOutputPathsAreRunUniqueWithinTheSameSecond(): void
    {
        $ctrip = new CtripBrowserProfileDataSourceAdapter(sys_get_temp_dir(), 'node', static fn(): array => []);
        $ctripPath = new \ReflectionMethod($ctrip, 'captureOutputPath');
        $ctripFirst = (string)$ctripPath->invoke($ctrip, sys_get_temp_dir(), 'hotel_001', 'traffic_report');
        $ctripSecond = (string)$ctripPath->invoke($ctrip, sys_get_temp_dir(), 'hotel_001', 'traffic_report');

        self::assertNotSame($ctripFirst, $ctripSecond);
        self::assertMatchesRegularExpression(
            '/_[0-9]{14}_[0-9]{6}_[a-f0-9]{16}_[a-z0-9_-]+\.json$/D',
            $ctripFirst
        );
        self::assertMatchesRegularExpression(
            '/_[0-9]{14}_[0-9]{6}_[a-f0-9]{16}_[a-z0-9_-]+\.json$/D',
            $ctripSecond
        );

        $meituan = new MeituanBrowserProfileDataSourceAdapter(sys_get_temp_dir(), 'node', static fn(): array => []);
        $meituanPath = new \ReflectionMethod($meituan, 'captureOutputPath');
        $meituanFirst = (string)$meituanPath->invoke($meituan, sys_get_temp_dir(), 'store_001');
        $meituanSecond = (string)$meituanPath->invoke($meituan, sys_get_temp_dir(), 'store_001');

        self::assertNotSame($meituanFirst, $meituanSecond);
        self::assertMatchesRegularExpression(
            '/_[0-9]{14}_[0-9]{6}_[a-f0-9]{16}\.json$/D',
            $meituanFirst
        );
        self::assertMatchesRegularExpression(
            '/_[0-9]{14}_[0-9]{6}_[a-f0-9]{16}\.json$/D',
            $meituanSecond
        );
    }

    public function testBrowserProfileAdaptersNeverPromoteOutputFromAFailedCollectorProcess(): void
    {
        $ctripRoot = $this->createCtripBrowserProfileTestRoot('hotel_001');
        $meituanRoot = $this->createMeituanBrowserProfileTestRoot('store_001');
        try {
            $ctripWriter = $this->captureRunner([
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                'capture_gate' => ['status' => 'pass'],
                'standard_rows' => [[
                    'hotel_id' => '24588',
                    'data_date' => '2026-08-09',
                    'data_type' => 'business',
                    'amount' => 100,
                    'source_trace_id' => 'current-run-ctrip-row',
                ]],
            ]);
            $ctrip = new CtripBrowserProfileDataSourceAdapter(
                $ctripRoot,
                'node',
                static function (array $args) use ($ctripWriter): array {
                    $ctripWriter($args);
                    return [
                        'success' => false,
                        'message' => 'collector failed {"\u0063\u006f\u006f\u006b\u0069\u0065\u0073":[{"value":"ctrip-message-secret"}]}',
                        'stdout' => 'Error URL https\u003a\u002f\u002fuser\u003actrip-stdout-secret\u0040example.invalid\u002fpath',
                        'stderr' => 'Authorization: Bearer ctrip-stderr-secret',
                    ];
                }
            );
            $ctripResult = $ctrip->fetch($this->ctripBrowserProfileSource(), [
                'interactive_browser' => false,
                'data_date' => '2026-08-09',
                'capture_sections' => 'business_overview',
            ]);
            self::assertSame('failed', $ctripResult['status']);
            self::assertSame('injected_process_failed', $ctripResult['status_code']);
            self::assertArrayNotHasKey('rows', $ctripResult['payload']);
            $ctripFailureJson = json_encode($ctripResult, JSON_UNESCAPED_SLASHES) ?: '';
            self::assertStringNotContainsString('ctrip-message-secret', $ctripFailureJson);
            self::assertStringNotContainsString('ctrip-stdout-secret', $ctripFailureJson);
            self::assertStringNotContainsString('ctrip-stderr-secret', $ctripFailureJson);
            self::assertStringContainsString('redacted', $ctripFailureJson);

            $meituanWriter = $this->captureRunner([
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                'capture_gate' => ['status' => 'pass'],
                'traffic' => [[
                    'poi_id' => '68471',
                    'data_date' => '2026-08-09',
                    'list_exposure' => 10,
                    'detail_exposure' => 4,
                    'flow_rate' => 40,
                ]],
                'orders' => [],
            ]);
            $meituan = new MeituanBrowserProfileDataSourceAdapter(
                $meituanRoot,
                'node',
                static function (array $args) use ($meituanWriter): array {
                    $meituanWriter($args);
                    return [
                        'success' => false,
                        'message' => 'collector failed {"\u0063\u006f\u006f\u006b\u0069\u0065\u004a\u0061\u0072":{"foo":"meituan-message-secret"}}',
                        'stdout' => 'Error URL https://example.invalid/?token=meituan-stdout-secret',
                        'stderr' => 'Cookie: session=meituan-stderr-secret',
                    ];
                }
            );
            $meituanResult = $meituan->fetch($this->meituanBrowserProfileSource(), [
                'interactive_browser' => false,
                'data_date' => '2026-08-09',
                'capture_sections' => 'traffic',
            ]);
            self::assertSame('failed', $meituanResult['status']);
            self::assertSame('injected_process_failed', $meituanResult['status_code']);
            self::assertArrayNotHasKey('rows', $meituanResult['payload']);
            $meituanFailureJson = json_encode($meituanResult, JSON_UNESCAPED_SLASHES) ?: '';
            self::assertStringNotContainsString('meituan-message-secret', $meituanFailureJson);
            self::assertStringNotContainsString('meituan-stdout-secret', $meituanFailureJson);
            self::assertStringNotContainsString('meituan-stderr-secret', $meituanFailureJson);
            self::assertStringContainsString('redacted', $meituanFailureJson);
        } finally {
            $this->removeDirectory($ctripRoot);
            $this->removeDirectory($meituanRoot);
        }
    }

    public function testBrowserProfileProcessDiagnosticsSuppressCredentialBearingLines(): void
    {
        $nestedEscape = static fn(string $value): string => str_replace(
            ['\\', '"', '/'],
            ['\\\\', '\\"', '\\/'],
            $value
        );
        $tripleEscapedCookie = '{"cookies":[{"value":"live-secret-triple-cookie"}]}';
        $tripleEscapedUrl = 'https://user:live-secret-triple-userinfo@example.invalid/path';
        for ($pass = 0; $pass < 3; $pass++) {
            $tripleEscapedCookie = $nestedEscape($tripleEscapedCookie);
            $tripleEscapedUrl = $nestedEscape($tripleEscapedUrl);
        }
        $cases = [
            ['Error: access_token=live-secret-123', 'live-secret-123'],
            ['Exception Authorization: Bearer live-secret-789', 'live-secret-789'],
            ['failed api_key=live-secret-abc', 'live-secret-abc'],
            ['failed URL https://example.invalid/?token=live-secret-query', 'live-secret-query'],
            ['failed Cookie: session=live-secret-cookie', 'live-secret-cookie'],
            ['failed --refresh-token live-secret-refresh', 'live-secret-refresh'],
            ['failed mtgsig=live-secret-signature', 'live-secret-signature'],
            ['Error payload {"access_token":"live-secret-json"}', 'live-secret-json'],
            [
                'Error payload {"cookies":[{"name":"foo","value":"live-secret-cookie-jar"}]}',
                'live-secret-cookie-jar',
            ],
            [
                'failed URL https://user:live-secret-userinfo@example.invalid/path',
                'live-secret-userinfo',
            ],
            [
                'Error payload {\"cookies\":[{\"value\":\"live-secret-escaped-cookie\"}]}',
                'live-secret-escaped-cookie',
            ],
            [
                'Error payload {\"cookieJar\":{\"value\":\"live-secret-escaped-jar\"}}',
                'live-secret-escaped-jar',
            ],
            [
                'failed URL https:\/\/user:live-secret-escaped-userinfo@example.invalid/path',
                'live-secret-escaped-userinfo',
            ],
            ['Error payload ' . $tripleEscapedCookie, 'live-secret-triple-cookie'],
            ['failed URL ' . $tripleEscapedUrl, 'live-secret-triple-userinfo'],
            [
                'Error payload {"\u0063\u006f\u006f\u006b\u0069\u0065\u0073":[{"value":"live-secret-unicode-cookie"}]}',
                'live-secret-unicode-cookie',
            ],
            [
                'failed URL https\u003a\u002f\u002fuser\u003alive-secret-unicode-userinfo\u0040example.invalid\u002fpath',
                'live-secret-unicode-userinfo',
            ],
            [
                'failed URL \u0068\u0074\u0074\u0070\u0073\u003a\u002f\u002fuser\u003alive-secret-full-unicode-url\u0040example.invalid',
                'live-secret-full-unicode-url',
            ],
        ];

        foreach ($cases as [$line, $secret]) {
            $log = BrowserProfileProcessOutputSanitizer::sanitizeLog("safe prelude\n{$line}\nsafe tail");
            $summary = BrowserProfileProcessOutputSanitizer::summarize($line, '');

            self::assertStringNotContainsString($secret, $log);
            self::assertStringContainsString('[redacted_sensitive_process_output]', $log);
            self::assertSame('browser_profile_process_error_redacted', $summary);
        }

        self::assertSame(
            'Error: browser process timed out after 60 seconds',
            BrowserProfileProcessOutputSanitizer::summarize(
                'Error: browser process timed out after 60 seconds',
                ''
            )
        );
    }
}
