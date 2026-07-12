<?php
declare(strict_types=1);

namespace Tests;

use app\service\BrowserProfileCaptureRequestService;
use PHPUnit\Framework\TestCase;

final class BrowserProfileCaptureRequestServiceTest extends TestCase
{
    public function testConfirmedEmptyGateRequiresEveryRequestedSectionToBeAuthoritativelyEmpty(): void
    {
        self::assertTrue(BrowserProfileCaptureRequestService::isConfirmedEmptyMeituanCaptureGate([
            'status' => 'pass',
            'section_statuses' => ['orders' => 'empty_confirmed', 'reviews' => 'empty_confirmed'],
        ]));
        self::assertFalse(BrowserProfileCaptureRequestService::isConfirmedEmptyMeituanCaptureGate([
            'status' => 'pass',
            'section_statuses' => ['traffic' => 'captured', 'orders' => 'empty_confirmed'],
        ]));
        self::assertFalse(BrowserProfileCaptureRequestService::isConfirmedEmptyMeituanCaptureGate([
            'status' => 'fail',
            'section_statuses' => ['orders' => 'empty_confirmed'],
        ]));
    }

    public function testRejectsCumulativeRowsWhoseDateOnlyCameFromCaptureFallback(): void
    {
        $rows = [[
            'data_type' => 'traffic',
            'data_date' => '2026-07-11',
            'raw_data' => json_encode([
                'dataDate' => '2026-07-11',
                'date_source' => 'capture_context.default_data_date',
            ]),
        ]];

        self::assertSame(
            ['traffic:0'],
            BrowserProfileCaptureRequestService::unverifiedMeituanTargetDateRows($rows, '2026-07-11')
        );
    }

    public function testAcceptsExplicitRowDateAndExemptsEventRowsFromCumulativeDateEvidence(): void
    {
        $rows = [
            [
                'data_type' => 'traffic',
                'data_date' => '2026-07-11',
                'raw_data' => json_encode(['statDate' => '2026-07-11']),
            ],
            [
                'data_type' => 'order',
                'data_date' => '2026-07-10',
                'raw_data' => json_encode(['date_source' => 'capture_context.default_data_date']),
            ],
        ];

        self::assertSame(
            [],
            BrowserProfileCaptureRequestService::unverifiedMeituanTargetDateRows($rows, '2026-07-11')
        );
    }

    public function testSafeFilePartAndTimeoutsAreBounded(): void
    {
        self::assertSame('store_1_bad', BrowserProfileCaptureRequestService::safeFilePart('store 1/../bad'));
        self::assertSame(60, BrowserProfileCaptureRequestService::timeoutSeconds(1));
        self::assertSame(900, BrowserProfileCaptureRequestService::timeoutSeconds(9999));
        self::assertSame(30000, BrowserProfileCaptureRequestService::loginTimeoutMs(10));
        self::assertSame(600000, BrowserProfileCaptureRequestService::loginTimeoutMs(9999999));
        self::assertSame('traffic,orders', BrowserProfileCaptureRequestService::normalizeProfileSections(['traffic', '../orders', 'traffic'], 'fallback'));
    }

    public function testMeituanProfileSectionsNormalizeFullRealtimeAndCommentAliases(): void
    {
        self::assertSame(
            'traffic,orders,ads,reviews',
            BrowserProfileCaptureRequestService::normalizeMeituanProfileSections('full')
        );
        self::assertSame(
            'traffic,reviews,ads',
            BrowserProfileCaptureRequestService::normalizeMeituanProfileSections('realtime comments advertising')
        );
        self::assertSame(
            '',
            BrowserProfileCaptureRequestService::normalizeMeituanProfileSections('unknown', '')
        );
    }

    public function testRuntimePathHelpersUseExplicitExistingFiles(): void
    {
        $oldNode = getenv('NODE_BINARY');
        $oldChrome = getenv('CHROME_PATH');
        $nodePath = tempnam(sys_get_temp_dir(), 'node-bin-');
        $chromePath = tempnam(sys_get_temp_dir(), 'chrome-bin-');

        self::assertIsString($nodePath);
        self::assertIsString($chromePath);

        try {
            putenv('NODE_BINARY=' . $nodePath);
            putenv('CHROME_PATH=' . $chromePath);

            self::assertSame($nodePath, BrowserProfileCaptureRequestService::resolveNodeBinary());
            self::assertSame($chromePath, BrowserProfileCaptureRequestService::resolveChromePath());
        } finally {
            $this->restoreEnvVar('NODE_BINARY', $oldNode);
            $this->restoreEnvVar('CHROME_PATH', $oldChrome);
            @unlink($nodePath);
            @unlink($chromePath);
        }
    }

    private function restoreEnvVar(string $key, string|false $value): void
    {
        if ($value === false) {
            putenv($key);
            return;
        }

        putenv($key . '=' . $value);
    }

    public function testBuildMeituanPlanKeepsRequestFieldsExplicit(): void
    {
        $plan = BrowserProfileCaptureRequestService::buildMeituanPlan(
            [
                'store_id' => 'store-1',
                'poi_id' => 'poi-1',
                'poi_name' => ' Test POI ',
                'ads_url' => 'https://example.test/ads',
                'captureSections' => ['traffic', '../bad', 'orders'],
                'timeout_seconds' => 120,
                'login_timeout_ms' => 45000,
            ],
            'D:\\project',
            'node',
            true,
            7,
            '20260612010101',
            'C:\\Chrome\\chrome.exe'
        );

        self::assertSame('store-1', $plan['store_id']);
        self::assertSame('poi-1', $plan['poi_id']);
        self::assertSame(120, $plan['timeout_seconds']);
        self::assertStringEndsWith('runtime' . DIRECTORY_SEPARATOR . 'meituan_capture', $plan['output_dir']);
        self::assertStringEndsWith('meituan_capture_store-1_20260612010101.json', $plan['output_path']);
        self::assertSame([
            'node',
            'D:\\project' . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'meituan_browser_capture.mjs',
            '--store-id=store-1',
            '--output=' . $plan['output_path'],
            '--login-timeout-ms=45000',
            '--system-hotel-id=7',
            '--poi-id=poi-1',
            '--poi-name=Test POI',
            '--ads-url=https://example.test/ads',
            '--sections=traffic,orders',
            '--login-only=true',
            '--chrome-path=C:\\Chrome\\chrome.exe',
        ], $plan['args']);
    }

    public function testBuildMeituanPlanPropagatesRequestedDataDate(): void
    {
        $plan = BrowserProfileCaptureRequestService::buildMeituanPlan(
            [
                'store_id' => 'store-1',
                'data_date' => '2026-07-11',
            ],
            'D:\\project',
            'node',
            false,
            7,
            '20260712010101'
        );

        self::assertContains('--data-date=2026-07-11', $plan['args']);
    }

    public function testBuildMeituanPlanInfersPeriodTargetDateWhenUiOmitsIt(): void
    {
        $realtime = BrowserProfileCaptureRequestService::buildMeituanPlan(
            ['store_id' => 'store-1', 'data_period' => 'realtime_snapshot'],
            'D:\\project',
            'node',
            false,
            7,
            '20260712010101'
        );
        $historical = BrowserProfileCaptureRequestService::buildMeituanPlan(
            ['store_id' => 'store-1', 'data_period' => 'historical_daily'],
            'D:\\project',
            'node',
            false,
            7,
            '20260712010102'
        );

        self::assertContains('--data-date=' . date('Y-m-d'), $realtime['args']);
        self::assertContains('--data-date=' . date('Y-m-d', strtotime('-1 day')), $historical['args']);
    }

    public function testMeituanTargetDateMismatchIgnoresEventAndForecastDates(): void
    {
        $mismatches = BrowserProfileCaptureRequestService::mismatchedMeituanTargetDates([
            ['data_type' => 'traffic', 'data_date' => '2026-07-10'],
            ['data_type' => 'order', 'data_date' => '2026-07-09'],
            ['data_type' => 'review', 'data_date' => '2026-07-08'],
            ['data_type' => 'traffic_forecast', 'data_date' => '2026-07-20'],
        ], '2026-07-11');

        self::assertSame(['2026-07-10'], $mismatches);
    }

    public function testBuildCtripBasePlanUsesProfileFallbackAndOptionalHotelFields(): void
    {
        $plan = BrowserProfileCaptureRequestService::buildCtripBasePlan(
            [
                'hotel_id' => '',
                'hotel_name' => ' Ctrip Hotel ',
                'timeout_seconds' => 30,
                'login_timeout_ms' => 700000,
            ],
            'D:\\project',
            'node',
            9,
            '2026-06-11',
            '20260612010101'
        );

        self::assertSame('', $plan['hotel_id']);
        self::assertSame('system_9', $plan['profile_id']);
        self::assertSame(60, $plan['timeout_seconds']);
        self::assertStringEndsWith('runtime' . DIRECTORY_SEPARATOR . 'ctrip_capture', $plan['output_dir']);
        self::assertStringEndsWith('ctrip_browser_capture_system_9_20260612010101.json', $plan['output_path']);
        self::assertSame([
            'node',
            'D:\\project' . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'ctrip_browser_capture.mjs',
            '--profile-id=system_9',
            '--system-hotel-id=9',
            '--data-date=2026-06-11',
            '--output=' . $plan['output_path'],
            '--login-timeout-ms=600000',
            '--login-url=https://ebooking.ctrip.com/home/mainland',
            '--hotel-name=Ctrip Hotel',
        ], $plan['args']);
    }

    public function testBuildCtripBasePlanUsesExplicitProfileAndHotelId(): void
    {
        $plan = BrowserProfileCaptureRequestService::buildCtripBasePlan(
            [
                'hotel_id' => 'hotel-1',
                'profile_id' => 'profile-1',
            ],
            'D:\\project',
            'node',
            9,
            '2026-06-11',
            '20260612010101'
        );

        self::assertSame('hotel-1', $plan['hotel_id']);
        self::assertSame('profile-1', $plan['profile_id']);
        self::assertContains('--hotel-id=hotel-1', $plan['args']);
    }

    public function testBuildCtripAutoArgsKeepsHeadlessConcurrencyAndSections(): void
    {
        $args = BrowserProfileCaptureRequestService::buildCtripAutoArgs(
            'node',
            'D:\\project' . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'ctrip_browser_capture.mjs',
            'profile-1',
            9,
            '2026-06-11',
            'D:\\project' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'ctrip_capture' . DIRECTORY_SEPARATOR . 'capture.json',
            ['business_overview', 'traffic_report'],
            3,
            false
        );

        self::assertContains('--login-timeout-ms=30000', $args);
        self::assertContains('--sections=business_overview,traffic_report', $args);
        self::assertContains('--section-concurrency=3', $args);
        self::assertContains('--headless=true', $args);
    }

    public function testBuildMeituanAutoArgsKeepsSectionsAndOptionalMetadata(): void
    {
        $args = BrowserProfileCaptureRequestService::buildMeituanAutoArgs(
            [
                'capture_sections' => ['traffic', '../orders', 'traffic'],
                'poi_id' => 'poi-1',
                'hotel_name' => 'Store Name',
            ],
            'node',
            'D:\\project' . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'meituan_browser_capture.mjs',
            9,
            'store-1',
            'D:\\project' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'meituan_capture' . DIRECTORY_SEPARATOR . 'capture.json',
            true,
            'C:\\Chrome\\chrome.exe'
        );

        self::assertContains('--login-timeout-ms=300000', $args);
        self::assertContains('--headless=false', $args);
        self::assertContains('--sections=traffic,orders', $args);
        self::assertContains('--poi-id=poi-1', $args);
        self::assertContains('--poi-name=Store Name', $args);
        self::assertContains('--chrome-path=C:\\Chrome\\chrome.exe', $args);
    }

    public function testBuildMeituanAutoArgsPropagatesRequestedDataDate(): void
    {
        $args = BrowserProfileCaptureRequestService::buildMeituanAutoArgs(
            ['capture_sections' => 'traffic'],
            'node',
            'D:\\project' . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'meituan_browser_capture.mjs',
            9,
            'store-1',
            'D:\\project' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'meituan_capture' . DIRECTORY_SEPARATOR . 'capture.json',
            false,
            '',
            '2026-07-11'
        );

        self::assertContains('--data-date=2026-07-11', $args);
    }

    public function testBuildMeituanAutoArgsExpandsFullSectionsAndRealtimeMetadata(): void
    {
        $args = BrowserProfileCaptureRequestService::buildMeituanAutoArgs(
            [
                'capture_sections' => 'full',
                'ads_url' => 'https://ads.example.test/full',
                'data_period' => 'realtime_snapshot',
                'snapshot_time' => '2026-07-08 13:15:00',
            ],
            'node',
            'D:\\project' . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'meituan_browser_capture.mjs',
            9,
            'store-1',
            'D:\\project' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'meituan_capture' . DIRECTORY_SEPARATOR . 'capture.json',
            false
        );

        self::assertContains('--sections=traffic,orders,ads,reviews', $args);
        self::assertContains('--ads-url=https://ads.example.test/full', $args);
        self::assertContains('--data-period=realtime_snapshot', $args);
        self::assertContains('--snapshot-time=2026-07-08 13:15:00', $args);
    }
}
