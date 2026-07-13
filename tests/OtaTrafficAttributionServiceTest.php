<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaTrafficAttributionService;
use PHPUnit\Framework\TestCase;

final class OtaTrafficAttributionServiceTest extends TestCase
{
    public function testBrowserProfileWithTrafficCaptureSectionIsTrafficCapable(): void
    {
        self::assertTrue(OtaTrafficAttributionService::sourceCanProvideTraffic([
            'data_type' => 'business',
            'ingestion_method' => 'browser_profile',
        ], [
            'capture_sections' => 'traffic,orders,reviews,ads',
        ]));
    }

    public function testBusinessSourceWithoutTrafficCaptureSectionIsNotTrafficCapable(): void
    {
        self::assertFalse(OtaTrafficAttributionService::sourceCanProvideTraffic([
            'data_type' => 'business',
            'ingestion_method' => 'browser_profile',
        ], [
            'capture_sections' => 'orders,reviews',
        ]));
    }

    public function testOwnTrafficExcludesCompetitorsAndOtherPlatforms(): void
    {
        self::assertTrue(OtaTrafficAttributionService::rowBelongsToOwnPlatformTraffic([
            'platform' => 'Ctrip',
            'compare_type' => 'self',
        ], 'ctrip'));
        self::assertFalse(OtaTrafficAttributionService::rowBelongsToOwnPlatformTraffic([
            'platform' => 'Ctrip',
            'compare_type' => 'competitor',
        ], 'ctrip'));
        self::assertFalse(OtaTrafficAttributionService::rowBelongsToOwnPlatformTraffic([
            'platform' => 'Qunar',
            'compare_type' => 'self',
        ], 'ctrip'));
    }

    public function testLegacyOwnTrafficWithoutProjectionFieldsRemainsCompatible(): void
    {
        self::assertTrue(OtaTrafficAttributionService::rowBelongsToOwnPlatformTraffic([
            'platform' => '',
            'compare_type' => '',
        ], 'meituan'));
    }

    public function testP0HotelScopeIncludesSourcesBindingsAndStoredOwnTraffic(): void
    {
        self::assertSame(
            [7, 61, 64, 80, 94, 107, 133],
            OtaTrafficAttributionService::mergeP0HotelScopeIds(
                [64, 80, 94, 107],
                [7, 61, 64, 80, 94, 107],
                [64, 80, 107, 133]
            )
        );
    }
}
