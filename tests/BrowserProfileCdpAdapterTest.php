<?php
declare(strict_types=1);

namespace Tests;

use app\service\platform\CtripBrowserProfileDataSourceAdapter;
use app\service\platform\MeituanBrowserProfileDataSourceAdapter;
use PHPUnit\Framework\TestCase;

final class BrowserProfileCdpAdapterTest extends TestCase
{
    public function testCtripUsesControlledCdpWithoutLocalProfileDirectory(): void
    {
        $root = $this->createRoot('ctrip_browser_capture.mjs');
        $capturedArgs = [];

        try {
            $adapter = new CtripBrowserProfileDataSourceAdapter(
                $root,
                'node',
                static function (array $args) use (&$capturedArgs): array {
                    $capturedArgs = $args;
                    return ['success' => false, 'message' => 'bounded test stop', 'stdout' => '', 'stderr' => ''];
                }
            );
            $result = $adapter->fetch($this->ctripSource(), [
                'cdp_url' => 'http://127.0.0.1:9223',
                'data_date' => '2026-08-13',
            ]);

            self::assertSame('failed', $result['status']);
            self::assertContains('--cdp-url=http://127.0.0.1:9223', $capturedArgs);
            self::assertCount(1, array_filter(
                $capturedArgs,
                static fn(string $arg): bool => str_starts_with($arg, '--cdp-url=')
            ));
            self::assertContains('--section-concurrency=1', $capturedArgs);
            self::assertCount(1, array_filter(
                $capturedArgs,
                static fn(string $arg): bool => str_starts_with($arg, '--report-dir=')
            ));
            self::assertCount(1, array_filter(
                $capturedArgs,
                static fn(string $arg): bool => str_starts_with($arg, '--profile-dir=')
            ));
            self::assertDirectoryDoesNotExist($root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'ctrip_profile_cloud_profile');
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testMeituanUsesControlledCdpWithoutLocalProfileDirectory(): void
    {
        $root = $this->createRoot('meituan_browser_capture.mjs');
        $capturedArgs = [];

        try {
            $adapter = new MeituanBrowserProfileDataSourceAdapter(
                $root,
                'node',
                static function (array $args) use (&$capturedArgs): array {
                    $capturedArgs = $args;
                    return ['success' => false, 'message' => 'bounded test stop', 'stdout' => '', 'stderr' => ''];
                }
            );
            $result = $adapter->fetch($this->meituanSource(), [
                'cdpUrl' => 'http://127.0.0.1:9223/',
                'data_date' => '2026-08-13',
            ]);

            self::assertSame('failed', $result['status']);
            self::assertContains('--cdp-url=http://127.0.0.1:9223', $capturedArgs);
            self::assertCount(1, array_filter(
                $capturedArgs,
                static fn(string $arg): bool => str_starts_with($arg, '--cdp-url=')
            ));
            self::assertCount(1, array_filter(
                $capturedArgs,
                static fn(string $arg): bool => str_starts_with($arg, '--report-dir=')
            ));
            self::assertCount(1, array_filter(
                $capturedArgs,
                static fn(string $arg): bool => str_starts_with($arg, '--profile-dir=')
            ));
            self::assertDirectoryDoesNotExist($root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'meituan_profile_68471');
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testAdaptersRejectUnprotectedCdpBeforeStartingCapture(): void
    {
        $runnerCalled = false;
        $runner = static function () use (&$runnerCalled): array {
            $runnerCalled = true;
            return [];
        };

        $ctrip = new CtripBrowserProfileDataSourceAdapter(sys_get_temp_dir(), 'node', $runner);
        $ctripResult = $ctrip->fetch($this->ctripSource(), ['cdp_url' => 'http://example.test:9223']);
        self::assertSame('failed', $ctripResult['status']);
        self::assertSame('cloud_browser_cdp_url_invalid', $ctripResult['status_code']);

        $meituan = new MeituanBrowserProfileDataSourceAdapter(sys_get_temp_dir(), 'node', $runner);
        $meituanResult = $meituan->fetch($this->meituanSource(), ['cdp_url' => 'http://localhost:9223']);
        self::assertSame('failed', $meituanResult['status']);
        self::assertSame('cloud_browser_cdp_url_invalid', $meituanResult['status_code']);
        self::assertFalse($runnerCalled);
    }

    public function testAdaptersPreserveRunnerFailureBeforeDiagnosticPayloadFailure(): void
    {
        foreach ([
            'ctrip' => [CtripBrowserProfileDataSourceAdapter::class, 'ctrip_browser_capture.mjs', $this->ctripSource()],
            'meituan' => [MeituanBrowserProfileDataSourceAdapter::class, 'meituan_browser_capture.mjs', $this->meituanSource()],
        ] as $platform => [$adapterClass, $scriptName, $source]) {
            $root = $this->createRoot($scriptName);
            try {
                $runner = static function (array $args): array {
                    $outputPath = '';
                    foreach ($args as $arg) {
                        if (str_starts_with((string)$arg, '--output=')) {
                            $outputPath = substr((string)$arg, strlen('--output='));
                            break;
                        }
                    }
                    if ($outputPath !== '') {
                        file_put_contents($outputPath, (string)json_encode([
                            'auth_status' => ['ok' => false, 'status' => 'login_expired'],
                            'capture_gate' => ['status' => 'fail', 'failed_check_ids' => ['auth']],
                        ], JSON_UNESCAPED_SLASHES));
                    }
                    return [
                        'success' => false,
                        'status_code' => 'process_timeout',
                        'message' => 'capture timed out',
                        'stdout' => '',
                        'stderr' => 'diagnostic payload retained',
                        'exit_code' => 124,
                        'process_started' => true,
                        'process_pid' => 4242,
                        'process_tree_exit_confirmed' => true,
                        'termination' => [
                            'contract' => 'suxios.browser_capture_process_termination.v2',
                            'platform' => 'injected',
                            'reason' => 'timeout',
                            'confirmed_exited' => true,
                        ],
                    ];
                };
                $adapter = new $adapterClass($root, 'node', $runner);
                $result = $adapter->fetch($source, [
                    'cdp_url' => 'http://127.0.0.1:9223',
                    'data_date' => '2026-08-13',
                ]);

                self::assertSame('failed', $result['status'], $platform);
                self::assertSame('process_timeout', $result['status_code'], $platform);
                self::assertSame('process_timeout', $result['error_code'], $platform);
                self::assertSame('login_expired', $result['payload']['payload_failure_reason'], $platform);
                self::assertSame('process_timeout', $result['payload']['process_status_code'], $platform);
            } finally {
                $this->removeDirectory($root);
            }
        }
    }

    /** @return array<string, mixed> */
    private function ctripSource(): array
    {
        return [
            'id' => 77,
            'platform' => 'ctrip',
            'system_hotel_id' => 7,
            'tenant_id' => 1,
            'ingestion_method' => 'browser_profile',
            'config' => [
                'profile_id' => 'cloud_profile',
                'hotel_id' => '24588',
                'hotel_name' => 'Ctrip Demo Hotel',
                'capture_sections' => 'business_overview,traffic_report',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function meituanSource(): array
    {
        return [
            'id' => 78,
            'platform' => 'meituan',
            'system_hotel_id' => 7,
            'tenant_id' => 1,
            'ingestion_method' => 'browser_profile',
            'config' => [
                'store_id' => '68471',
                'poi_id' => '68471',
                'poi_name' => 'Meituan Demo Hotel',
                'capture_sections' => 'traffic',
            ],
        ];
    }

    private function createRoot(string $scriptName): string
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'browser_profile_cdp_adapter_' . bin2hex(random_bytes(4));
        mkdir($root . DIRECTORY_SEPARATOR . 'scripts', 0775, true);
        file_put_contents($root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . $scriptName, '// test script');
        return $root;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }

    public function testAdaptersRejectNonLoopbackCdpBeforeStartingCapture(): void
    {
        $runnerCalled = false;
        $runner = static function () use (&$runnerCalled): array {
            $runnerCalled = true;
            return [];
        };

        $ctrip = new CtripBrowserProfileDataSourceAdapter(sys_get_temp_dir(), 'node', $runner);
        $ctripResult = $ctrip->fetch($this->ctripSource(), ['cdp_url' => 'http://example.test:9223']);
        self::assertSame('failed', $ctripResult['status']);
        self::assertSame('cloud_browser_cdp_url_invalid', $ctripResult['status_code']);

        $meituan = new MeituanBrowserProfileDataSourceAdapter(sys_get_temp_dir(), 'node', $runner);
        $meituanResult = $meituan->fetch($this->meituanSource(), ['cdp_url' => 'http://localhost:9223']);
        self::assertSame('failed', $meituanResult['status']);
        self::assertSame('cloud_browser_cdp_url_invalid', $meituanResult['status_code']);
        self::assertFalse($runnerCalled);
    }
}
