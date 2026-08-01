<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaOrderedCollectionPlanner;
use PHPUnit\Framework\TestCase;

final class OtaOrderedCollectionPlannerTest extends TestCase
{
    public function testExistingPlatformSectionsHaveAStableSymmetricOrderWithoutExampleScopeExpansion(): void
    {
        $inventory = OtaOrderedCollectionPlanner::inventory();

        self::assertSame(
            ['business_overview', 'traffic_report'],
            $inventory['ctrip']['section_order']
        );
        self::assertSame(
            ['orders', 'traffic'],
            $inventory['meituan']['section_order']
        );

        $encoded = strtolower(json_encode($inventory, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        self::assertStringNotContainsString('comment_review', $encoded);
        self::assertStringNotContainsString('ads_pyramid', $encoded);
        self::assertStringNotContainsString('querycampaign', $encoded);
        self::assertStringNotContainsString('subchannel', $encoded);
        self::assertStringContainsString('queryflowtransformnewv1', $encoded);
        self::assertStringContainsString('/api/v1/ebooking/orders', $encoded);
    }

    public function testMissingFieldsSelectOnlyTheExistingTargetedSections(): void
    {
        self::assertSame(
            ['traffic_report'],
            OtaOrderedCollectionPlanner::sectionsForMissing(
                'ctrip',
                ['list_exposure', 'flow_rate']
            )
        );
        self::assertSame(
            ['business_overview'],
            OtaOrderedCollectionPlanner::sectionsForMissing(
                'ctrip',
                ['order_amount', 'room_nights']
            )
        );
        self::assertSame(
            ['orders', 'traffic'],
            OtaOrderedCollectionPlanner::sectionsForMissing(
                'meituan',
                ['order_amount', 'detail_exposure']
            )
        );
    }

    public function testCapturedCoreFieldsAreAggregatedAcrossNormalizedRowsAndZeroRemainsARealFact(): void
    {
        $rows = [
            [
                'amount' => 680.50,
                'quantity' => 3,
                'book_order_num' => 2,
            ],
            [
                'listExposure' => 120,
                'detailExposure' => 40,
                'flowRate' => 0.0,
                'orderFillingNum' => 9,
                'orderSubmitNum' => 4,
            ],
        ];

        self::assertSame(
            OtaOrderedCollectionPlanner::requiredFieldKeys('ctrip'),
            OtaOrderedCollectionPlanner::capturedFieldKeys('ctrip', $rows)
        );
        self::assertSame([], OtaOrderedCollectionPlanner::missingFieldKeys('ctrip', $rows));
    }

    public function testRequestPlanCarriesTargetDateInterfacesFieldsAndExplicitExcludedExamples(): void
    {
        $plan = OtaOrderedCollectionPlanner::requestPlan(
            'meituan',
            '2026-07-24',
            ['order_amount', 'room_nights', 'order_count'],
            'missing_revenue_fields'
        );

        self::assertSame('ota_ordered_collection.v1', $plan['contract_version']);
        self::assertSame('2026-07-24', $plan['target_date']);
        self::assertSame('targeted_gap', $plan['stage']);
        self::assertSame(['orders'], $plan['sections']);
        self::assertSame(['orders_daily_summary'], $plan['interface_ids']);
        self::assertSame(
            ['comments', 'realtime', 'ads', 'subchannels'],
            $plan['excluded_example_capabilities']
        );
    }

    public function testStoredCorePlanRejectsForecastCompetitorAndAuxiliaryTrafficRows(): void
    {
        $rows = [
            [
                'platform' => 'ctrip',
                'data_type' => 'competitor',
                'data_period' => 'historical_daily',
                'readback_verified' => 1,
                'amount' => 999,
                'quantity' => 99,
                'book_order_num' => 9,
            ],
            [
                'source' => 'ctrip',
                'data_type' => 'traffic',
                'data_period' => 'next_30_days',
                'readback_verified' => 1,
                'list_exposure' => 1000,
                'detail_exposure' => 100,
                'flow_rate' => 10,
            ],
            [
                'source' => 'ctrip',
                'data_type' => 'traffic',
                'data_period' => 'historical_daily',
                'readback_verified' => 1,
                'dimension' => 'catalog:traffic:traffic_scan_flow',
                'list_exposure' => 800,
                'detail_exposure' => 80,
                'flow_rate' => 10,
                'order_filling_num' => 8,
                'order_submit_num' => 4,
            ],
        ];

        $plan = OtaOrderedCollectionPlanner::requestPlanFromStoredRows(
            'ctrip',
            '2026-07-24',
            $rows
        );

        self::assertSame('yesterday_core', $plan['stage']);
        self::assertSame(['business_overview', 'traffic_report'], $plan['sections']);
        self::assertSame(0, $plan['eligible_row_count']);
        self::assertSame(
            OtaOrderedCollectionPlanner::requiredFieldKeys('ctrip'),
            $plan['missing_field_keys']
        );
    }

    public function testStoredCorePlanTargetsOnlyRealGapAndCanBecomeVerifierOnly(): void
    {
        $business = [
            'source' => 'meituan',
            'data_type' => 'order',
            'data_period' => 'historical_daily',
            'readback_verified' => 1,
            'compare_type' => 'self',
            'amount' => 680,
            'quantity' => 3,
            'book_order_num' => 1,
        ];
        $gapPlan = OtaOrderedCollectionPlanner::requestPlanFromStoredRows(
            'meituan',
            '2026-07-24',
            [$business]
        );
        self::assertSame('targeted_gap', $gapPlan['stage']);
        self::assertSame(['traffic'], $gapPlan['sections']);

        $completePlan = OtaOrderedCollectionPlanner::requestPlanFromStoredRows(
            'meituan',
            '2026-07-24',
            [
                $business,
                [
                    'source' => 'meituan',
                    'data_type' => 'traffic',
                    'data_period' => 'historical_daily',
                    'readback_verified' => 1,
                    'compare_type' => 'self',
                    'list_exposure' => 0,
                    'detail_exposure' => 0,
                    'flow_rate' => 0.0,
                ],
            ]
        );
        self::assertSame('verified_complete', $completePlan['stage']);
        self::assertSame([], $completePlan['sections']);
        self::assertSame([], $completePlan['missing_field_keys']);
    }

    public function testDegradedSourceForcesDeterministicFullRecoveryDespiteOldRows(): void
    {
        $plan = OtaOrderedCollectionPlanner::requestPlanFromStoredRows(
            'meituan',
            '2026-07-23',
            [[
                'source' => 'meituan',
                'data_type' => 'order',
                'data_period' => 'historical_daily',
                'readback_verified' => 1,
                'compare_type' => 'self',
                'amount' => 680,
                'quantity' => 3,
                'book_order_num' => 1,
            ]],
            true
        );

        self::assertSame('conflict_recovery', $plan['stage']);
        self::assertSame(['orders', 'traffic'], $plan['sections']);
        self::assertTrue($plan['source_recovery_required']);
    }

    public function testLegacyRowsForOneProfileSelectOneDeterministicAccountSource(): void
    {
        $sources = [
            [
                'id' => 11,
                'platform' => 'ctrip',
                'data_type' => 'business',
                'status' => 'success',
                'last_sync_time' => '2026-07-24 08:30:00',
                'config_json' => json_encode(['profile_id' => 'profile-a'], JSON_THROW_ON_ERROR),
            ],
            [
                'id' => 12,
                'platform' => 'ctrip',
                'data_type' => 'traffic',
                'status' => 'partial_success',
                'last_sync_time' => '2026-07-25 08:30:00',
                'config_json' => json_encode(['profile_id' => 'profile-a'], JSON_THROW_ON_ERROR),
            ],
            [
                'id' => 13,
                'platform' => 'meituan',
                'data_type' => 'traffic',
                'status' => 'ready',
                'last_sync_time' => '2026-07-25 08:30:00',
                'config_json' => json_encode(['store_id' => 'store-b'], JSON_THROW_ON_ERROR),
            ],
            [
                'id' => 14,
                'system_hotel_id' => 102,
                'platform' => 'ctrip',
                'data_type' => 'traffic',
                'status' => 'ready',
                'last_sync_time' => '2026-07-25 08:31:00',
                'config_json' => json_encode(['profile_id' => 'profile-a'], JSON_THROW_ON_ERROR),
            ],
        ];

        $selected = OtaOrderedCollectionPlanner::oneSourcePerBrowserProfileAccount($sources);
        self::assertSame([11, 13, 14], array_column($selected, 'id'));
        self::assertSame(
            OtaOrderedCollectionPlanner::browserProfileAccountScopeKey($sources[0]),
            OtaOrderedCollectionPlanner::browserProfileAccountScopeKey($sources[1])
        );
        self::assertNotSame(
            OtaOrderedCollectionPlanner::browserProfileAccountScopeKey($sources[0]),
            OtaOrderedCollectionPlanner::browserProfileAccountScopeKey($sources[2])
        );
    }

    public function testGeneratedProfileProjectionDoesNotDisplaceOwningSource(): void
    {
        $sources = [
            [
                'id' => 68,
                'platform' => 'meituan',
                'data_type' => 'business',
                'status' => 'success',
                'last_sync_time' => '2026-07-25 08:30:00',
                'config_json' => json_encode(['store_id' => 'store-80'], JSON_THROW_ON_ERROR),
            ],
            [
                'id' => 101,
                'platform' => 'meituan',
                'data_type' => 'traffic',
                'status' => 'success',
                'last_sync_time' => '2026-07-25 08:31:00',
                'config_json' => json_encode([
                    'poi_id' => 'store-80',
                    'source_projection_ids' => [68],
                ], JSON_THROW_ON_ERROR),
            ],
        ];

        $selected = OtaOrderedCollectionPlanner::oneSourcePerBrowserProfileAccount($sources);

        self::assertSame([68], array_column($selected, 'id'));
    }
}
