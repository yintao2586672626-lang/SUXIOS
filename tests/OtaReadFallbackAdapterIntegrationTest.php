<?php
declare(strict_types=1);

namespace Tests;

use app\service\PlatformDataSyncService;
use app\service\platform\CtripBrowserProfileDataSourceAdapter;
use app\service\platform\MeituanBrowserProfileDataSourceAdapter;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class OtaReadFallbackAdapterIntegrationTest extends TestCase
{
    public function testCtripAdapterKeepsOnlySafeFallbackDiagnosticsAndBuildsSummary(): void
    {
        $adapter = new CtripBrowserProfileDataSourceAdapter(sys_get_temp_dir(), 'node', static fn(): array => []);
        $diagnostics = [
            array_merge($this->diagnostic('ctrip', 'response_observed', 'same_origin_read_replay'), [
                'url' => 'https://ebooking.ctrip.com/read?token=secret-query',
                'body' => '{"hotelId":"secret-hotel"}',
                'headers' => ['Authorization' => 'Bearer secret-token'],
                'cookie' => 'session=secret-cookie',
            ]),
            array_merge($this->diagnostic('ctrip', 'blocked', 'target_date_unverified'), [
                'safe_route' => 'https://evil.example/read?token=secret-route',
            ]),
            $this->diagnostic('meituan', 'failed', 'fetch_failed'),
            array_merge($this->diagnostic('ctrip', 'failed', 'fetch_failed'), [
                'sensitive_values_exposed' => true,
            ]),
        ];

        $sanitized = $this->invokePrivate($adapter, 'sanitizeReadFallbackDiagnostics', [$diagnostics]);
        self::assertCount(2, $sanitized);
        self::assertSame('', $sanitized[1]['safe_route']);
        self::assertSame('blocked', $sanitized[1]['status']);
        self::assertFalse($sanitized[0]['sensitive_values_exposed']);
        self::assertArrayNotHasKey('url', $sanitized[0]);
        self::assertArrayNotHasKey('body', $sanitized[0]);
        self::assertArrayNotHasKey('headers', $sanitized[0]);
        self::assertArrayNotHasKey('cookie', $sanitized[0]);
        self::assertStringNotContainsString('secret', json_encode($sanitized, JSON_THROW_ON_ERROR));

        $summary = $this->invokePrivate($adapter, 'readFallbackSummary', [[
            'read_fallbacks' => array_merge($diagnostics, [
                $this->diagnostic('ctrip', 'failed', 'fetch_failed'),
            ]),
        ]]);
        self::assertSame('partial', $summary['status']);
        self::assertSame(3, $summary['diagnostic_count']);
        self::assertSame(2, $summary['attempted_count']);
        self::assertSame(1, $summary['response_observed_count']);
        self::assertSame(1, $summary['blocked_count']);
        self::assertSame(1, $summary['failed_count']);
        self::assertFalse($summary['sensitive_values_exposed']);
    }

    public function testMeituanAdapterPreservesBlockedDateReasonWithoutCrossPlatformData(): void
    {
        $adapter = new MeituanBrowserProfileDataSourceAdapter(sys_get_temp_dir(), 'node', static fn(): array => []);
        $diagnostics = [
            $this->diagnostic('meituan', 'blocked', 'target_date_unverified'),
            $this->diagnostic('ctrip', 'response_observed', 'same_origin_read_replay'),
        ];

        $sanitized = $this->invokePrivate($adapter, 'sanitizeReadFallbackDiagnostics', [$diagnostics]);
        self::assertCount(1, $sanitized);
        self::assertSame('meituan', $sanitized[0]['platform']);
        self::assertSame('target_date_unverified', $sanitized[0]['reason']);

        $summary = $this->invokePrivate($adapter, 'readFallbackSummary', [[
            'read_fallbacks' => $diagnostics,
        ]]);
        self::assertSame('blocked', $summary['status']);
        self::assertSame(0, $summary['attempted_count']);
        self::assertSame(1, $summary['blocked_count']);
    }

    public function testCtripSequentialCaptureKeepsFallbackEvidenceAcrossSections(): void
    {
        $adapter = new CtripBrowserProfileDataSourceAdapter(sys_get_temp_dir(), 'node', static fn(): array => []);
        $payloads = [
            [
                'capture_plan' => ['id' => 'full'],
                'read_fallbacks' => [
                    $this->diagnostic('ctrip', 'response_observed', 'same_origin_read_replay'),
                ],
            ],
            [
                'read_fallbacks' => [
                    $this->diagnostic('ctrip', 'blocked', 'target_date_unverified'),
                ],
            ],
        ];
        $moduleResults = [
            ['section' => 'business_overview', 'status' => 'success', 'output' => 'one.json'],
            ['section' => 'traffic_report', 'status' => 'success', 'output' => 'two.json'],
        ];

        $merged = $this->invokePrivate($adapter, 'mergeSequentialCapturePayloads', [
            $payloads,
            $moduleResults,
            ['business_overview', 'traffic_report'],
            'hotel_001',
            '2026-07-29',
        ]);

        self::assertCount(2, $merged['read_fallbacks']);
        self::assertSame('partial', $merged['sync_summary']['read_fallback_summary']['status']);
        self::assertSame(1, $merged['sync_summary']['read_fallback_summary']['response_observed_count']);
        self::assertSame(1, $merged['sync_summary']['read_fallback_summary']['blocked_count']);
        self::assertFalse($merged['data_source_capture']['read_fallback_summary']['sensitive_values_exposed']);
    }

    public function testSyncTaskKeepsOnlyBoundedFallbackSummary(): void
    {
        $service = new PlatformDataSyncService();
        $summary = $this->invokePrivate($service, 'otaReadFallbackSummaryFromPayload', [[
            'read_fallback_summary' => [
                'response_observed_count' => 0,
                'blocked_count' => 0,
                'failed_count' => 0,
                'sensitive_values_exposed' => false,
            ],
            'sync_summary' => [
                'read_fallback_summary' => [
                    'status' => 'forged',
                    'diagnostic_count' => 999,
                    'attempted_count' => 999,
                    'response_observed_count' => 3,
                    'blocked_count' => 2,
                    'failed_count' => 1,
                    'sensitive_values_exposed' => false,
                    'url' => 'https://example.test/read?token=secret',
                    'cookie' => 'session=secret',
                ],
            ],
        ]]);

        self::assertSame('partial', $summary['status']);
        self::assertSame(6, $summary['diagnostic_count']);
        self::assertSame(4, $summary['attempted_count']);
        self::assertSame(3, $summary['response_observed_count']);
        self::assertSame(2, $summary['blocked_count']);
        self::assertSame(1, $summary['failed_count']);
        self::assertFalse($summary['sensitive_values_exposed']);
        self::assertArrayNotHasKey('url', $summary);
        self::assertArrayNotHasKey('cookie', $summary);
        self::assertStringNotContainsString('secret', json_encode($summary, JSON_THROW_ON_ERROR));

        $rejected = $this->invokePrivate($service, 'otaReadFallbackSummaryFromPayload', [[
            'read_fallback_summary' => [
                'response_observed_count' => 1,
                'sensitive_values_exposed' => true,
            ],
        ]]);
        self::assertSame([], $rejected);
    }

    /** @return array<string, mixed> */
    private function diagnostic(string $platform, string $status, string $reason): array
    {
        return [
            'schema_version' => 1,
            'platform' => $platform,
            'section' => 'traffic',
            'endpoint_id' => $platform . '_traffic_home',
            'safe_route' => 'observed-read:/datacenter/home/traffic',
            'request_fingerprint' => '0123456789abcdef01234567',
            'status' => $status,
            'reason' => $reason,
            'http_status' => $status === 'response_observed' ? 200 : 0,
            'replay_source' => 'observed_request_same_origin',
            'sensitive_values_exposed' => false,
        ];
    }

    private function invokePrivate(object $target, string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod($target, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($target, $arguments);
    }
}
