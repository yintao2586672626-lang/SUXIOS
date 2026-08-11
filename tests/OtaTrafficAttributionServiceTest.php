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

    public function testCtripOwnTrafficExcludesExplicitQunarFamilyDimension(): void
    {
        self::assertTrue(OtaTrafficAttributionService::rowBelongsToOwnPlatformTraffic([
            'platform' => 'ctrip',
            'dimension' => 'realtime:ctrip',
            'compare_type' => '',
        ], 'ctrip'));

        self::assertFalse(OtaTrafficAttributionService::rowBelongsToOwnPlatformTraffic([
            'platform' => 'ctrip',
            'dimension' => 'realtime:qunar',
            'compare_type' => '',
        ], 'ctrip'));
    }

    public function testAuthoritativeCtripTrafficKeepsCanonicalAndLegacyCoreRowsOnly(): void
    {
        self::assertTrue(OtaTrafficAttributionService::rowBelongsToAuthoritativeP0Traffic([
            'platform' => 'ctrip',
            'compare_type' => 'self',
            'dimension' => 'catalog:business_overview:business_flow_transform:list_exposure',
            'raw_data' => json_encode(['endpoint_id' => 'business_flow_transform']),
        ], 'ctrip'));

        self::assertTrue(OtaTrafficAttributionService::rowBelongsToAuthoritativeP0Traffic([
            'platform' => 'ctrip',
            'compare_type' => '',
            'dimension' => '',
            'raw_data' => '{}',
        ], 'ctrip'));

        self::assertTrue(OtaTrafficAttributionService::rowBelongsToAuthoritativeP0Traffic([
            'platform' => 'ctrip',
            'compare_type' => '',
            'dimension' => '',
            'raw_data' => json_encode(['row' => ['endpoint_id' => 'traffic_flow_transform']]),
        ], 'ctrip'));

        self::assertTrue(OtaTrafficAttributionService::rowBelongsToAuthoritativeP0Traffic([
            'platform' => 'ctrip',
            'compare_type' => '',
            'dimension' => 'catalog:traffic_report:traffic_flow_transform:list_exposure',
            'raw_data' => json_encode(['row' => ['endpoint_id' => 'traffic_flow_transform']]),
        ], 'ctrip'));

        foreach (['traffic_hotel_seq', 'traffic_flow_source'] as $auxiliaryEndpoint) {
            self::assertFalse(OtaTrafficAttributionService::rowBelongsToAuthoritativeP0Traffic([
                'platform' => 'ctrip',
                'compare_type' => '',
                'dimension' => '',
                'raw_data' => json_encode(['row' => ['endpoint_id' => $auxiliaryEndpoint]]),
            ], 'ctrip'));
        }

        foreach ([
            ['row' => ['capture' => ['endpoint_id' => 'traffic_hotel_seq']]],
            ['source_row' => ['endpoint_id' => 'traffic_flow_source']],
            ['source_row' => ['capture' => ['endpointId' => 'traffic_hotel_seq']]],
        ] as $nestedAuxiliaryRaw) {
            self::assertFalse(OtaTrafficAttributionService::rowBelongsToAuthoritativeP0Traffic([
                'platform' => 'ctrip',
                'compare_type' => '',
                'dimension' => '',
                'raw_data' => json_encode($nestedAuxiliaryRaw),
            ], 'ctrip'));
        }

        self::assertFalse(OtaTrafficAttributionService::rowBelongsToAuthoritativeP0Traffic([
            'platform' => 'ctrip',
            'compare_type' => '',
            'dimension' => 'catalog:traffic_report:traffic_flow_transform:list_exposure',
            'raw_data' => json_encode(['row' => ['endpoint_id' => 'traffic_hotel_seq']]),
        ], 'ctrip'));

        self::assertFalse(OtaTrafficAttributionService::rowBelongsToAuthoritativeP0Traffic([
            'platform' => 'ctrip',
            'compare_type' => '',
            'dimension' => '',
            'raw_data' => json_encode([
                'endpoint_id' => 'traffic_flow_transform',
                'row' => ['endpoint_id' => 'traffic_flow_source'],
            ]),
        ], 'ctrip'));

        self::assertFalse(OtaTrafficAttributionService::rowBelongsToAuthoritativeP0Traffic([
            'platform' => 'ctrip',
            'compare_type' => 'self',
            'dimension' => 'catalog:business_overview:traffic_rank_snapshot:rank',
            'raw_data' => json_encode(['endpoint_id' => 'traffic_rank_snapshot']),
        ], 'ctrip'));

        self::assertFalse(OtaTrafficAttributionService::rowBelongsToAuthoritativeP0Traffic([
            'platform' => 'ctrip',
            'compare_type' => 'competitor_avg',
            'dimension' => '',
            'raw_data' => '{}',
        ], 'ctrip'));
    }

    public function testMeituanRefreshTimestampCannotBecomeBusinessDateEvidence(): void
    {
        self::assertFalse(OtaTrafficAttributionService::rowBelongsToAuthoritativeP0Traffic([
            'platform' => 'meituan',
            'compare_type' => 'self',
            'raw_data' => json_encode([
                'date_source' => 'response.rtDataUpdateTime',
                'row' => ['_capture_source' => 'xhr:traffic:traffic'],
            ]),
        ], 'meituan'));

        self::assertTrue(OtaTrafficAttributionService::rowBelongsToAuthoritativeP0Traffic([
            'platform' => 'meituan',
            'compare_type' => 'self',
            'raw_data' => json_encode([
                'date_source' => 'page.traffic_period_selection.readback',
                'row' => ['_capture_source' => 'xhr:traffic:traffic'],
            ]),
        ], 'meituan'));
    }

    public function testMeituanAuthoritativeTrafficRequiresNetworkResponseProvenance(): void
    {
        self::assertFalse(OtaTrafficAttributionService::rowBelongsToAuthoritativeP0Traffic([
            'platform' => 'meituan',
            'compare_type' => 'self',
            'raw_data' => json_encode([
                'date_source' => 'page.traffic_period_selection.readback',
                'row' => [],
            ]),
        ], 'meituan'));

        self::assertFalse(OtaTrafficAttributionService::rowBelongsToAuthoritativeP0Traffic([
            'platform' => 'meituan',
            'compare_type' => 'self',
            'raw_data' => json_encode([
                'date_source' => 'page.traffic_period_selection.readback',
                'row' => ['_capture_source' => 'dom:traffic:flow_funnel'],
            ]),
        ], 'meituan'));

        self::assertTrue(OtaTrafficAttributionService::rowBelongsToAuthoritativeP0Traffic([
            'platform' => 'meituan',
            'compare_type' => 'self',
            'raw_data' => json_encode([
                'date_source' => 'page.traffic_period_selection.readback',
                'row' => ['_capture_source' => 'xhr:traffic:traffic'],
            ]),
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
