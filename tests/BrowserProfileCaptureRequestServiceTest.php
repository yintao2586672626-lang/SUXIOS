<?php
declare(strict_types=1);

namespace Tests;

use app\service\BrowserProfileCaptureRequestService;
use PHPUnit\Framework\TestCase;

final class BrowserProfileCaptureRequestServiceTest extends TestCase
{
    public function testSafeFilePartAndTimeoutsAreBounded(): void
    {
        self::assertSame('store_1_bad', BrowserProfileCaptureRequestService::safeFilePart('store 1/../bad'));
        self::assertSame(60, BrowserProfileCaptureRequestService::timeoutSeconds(1));
        self::assertSame(900, BrowserProfileCaptureRequestService::timeoutSeconds(9999));
        self::assertSame(30000, BrowserProfileCaptureRequestService::loginTimeoutMs(10));
        self::assertSame(600000, BrowserProfileCaptureRequestService::loginTimeoutMs(9999999));
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
            '--sections=traffic,bad,orders',
            '--login-only=true',
            '--chrome-path=C:\\Chrome\\chrome.exe',
        ], $plan['args']);
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
            '--login-url=https://ebooking.ctrip.com/login/index',
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
}
