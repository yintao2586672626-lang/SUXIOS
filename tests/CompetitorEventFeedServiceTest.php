<?php
declare(strict_types=1);

namespace Tests;

use app\service\CompetitorEventFeedService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CompetitorEventFeedServiceTest extends TestCase
{
    public function testBuildsContinuousCtripAndMeituanEventsInCaptureOrder(): void
    {
        $service = new CompetitorEventFeedService();
        $rows = [
            $this->completeRow([
                'id' => 2,
                'platform' => 'mt',
                'collected_at' => '2026-07-17 10:05:00',
                'price' => '329.00',
            ]),
            $this->completeRow([
                'id' => 1,
                'platform' => 'xc',
                'collected_at' => '2026-07-17 09:05:00',
                'price' => '299.00',
            ]),
        ];

        $result = $service->buildFromRows($rows, 7, 'all', '2026-07-20');

        self::assertSame('available', $result['status']);
        self::assertSame(['ctrip', 'meituan'], $result['platforms']);
        self::assertSame(2, $result['sample_count']);
        self::assertSame(2, $result['decision_eligible_sample_count']);
        self::assertSame(2, $result['readback_verified_count']);
        self::assertSame(['ctrip', 'meituan'], array_column($result['events'], 'platform'));
        self::assertSame(
            ['2026-07-17 09:05:00', '2026-07-17 10:05:00'],
            array_column($result['events'], 'collected_at')
        );
        self::assertSame([299.0, 329.0], array_column($result['events'], 'price'));
        self::assertSame('https://hotels.example.test/rate/90001', $result['events'][0]['source_ref']);
        self::assertStringNotContainsString('trace=private', json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertSame([], $result['data_gaps']);
        self::assertArrayNotHasKey('score', $result);
    }

    public function testEmptyScopeIsExplicitAndDoesNotInventFacts(): void
    {
        $service = new CompetitorEventFeedService();
        $rows = [
            $this->completeRow(['store_id' => 8]),
            $this->completeRow(['check_in_date' => '2026-07-21']),
        ];

        $result = $service->buildFromRows($rows, 7, 'ctrip', '2026-07-20');

        self::assertSame('empty', $result['status']);
        self::assertSame(0, $result['sample_count']);
        self::assertSame(0, $result['returned_event_count']);
        self::assertSame([], $result['events']);
        self::assertSame(['no_matching_competitor_price_events'], $result['data_gaps']);
        self::assertSame('no_matching_events', $result['decision_gate']);
        self::assertArrayNotHasKey('score', $result);
    }

    public function testUnverifiedRowsRemainVisibleButCannotBecomeDecisionEvidence(): void
    {
        $service = new CompetitorEventFeedService();
        $row = $this->completeRow([
            'id' => 9,
            'collected_at' => null,
            'price' => 0,
            'source_ref' => 'token=do-not-expose',
            'validation_status' => 'incomplete',
            'readback_verified' => 0,
            'comparison_key' => '',
            'availability' => 'unknown',
        ]);

        $result = $service->buildFromRows([$row], 7, 'xc', '2026-07-20');
        $event = $result['events'][0];

        self::assertSame('insufficient_evidence', $result['status']);
        self::assertSame(1, $result['sample_count']);
        self::assertSame(0, $result['decision_eligible_sample_count']);
        self::assertNull($event['collected_at']);
        self::assertNull($event['price']);
        self::assertNull($event['source_ref']);
        self::assertFalse($event['readback_verified']);
        self::assertFalse($event['decision_eligible']);
        self::assertContains('readback_unverified', $event['evidence_gaps']);
        self::assertContains('price_missing', $event['evidence_gaps']);
        self::assertStringNotContainsString('do-not-expose', json_encode($result, JSON_UNESCAPED_UNICODE));
    }

    public function testPlatformAndDateTimeFiltersRejectInvalidScope(): void
    {
        $service = new CompetitorEventFeedService();

        $this->expectException(InvalidArgumentException::class);
        $service->buildFromRows([], 7, 'booking', '2026-07-20');
    }

    public function testBuildsBookableSoldOutAndReopenedAvailabilityTimelineWithoutFakeZeroPrice(): void
    {
        $service = new CompetitorEventFeedService();
        $rows = [
            $this->completeRow([
                'id' => 1,
                'collected_at' => '2026-07-17 09:00:00',
                'price' => 299.0,
                'availability' => 'bookable',
                'competitor_hotel_name' => '真实竞品酒店',
            ]),
            $this->completeRow([
                'id' => 2,
                'collected_at' => '2026-07-17 10:00:00',
                'price' => null,
                'availability' => 'sold_out',
                'competitor_hotel_name' => '真实竞品酒店',
            ]),
            $this->completeRow([
                'id' => 3,
                'collected_at' => '2026-07-17 11:00:00',
                'price' => 319.0,
                'availability' => 'bookable',
                'competitor_hotel_name' => '真实竞品酒店',
            ]),
        ];

        $result = $service->buildFromRows($rows, 7, 'ctrip', '2026-07-20');

        self::assertSame('available', $result['status']);
        self::assertSame(3, $result['availability_evidence_eligible_sample_count']);
        self::assertSame(2, $result['price_evidence_eligible_sample_count']);
        self::assertSame(
            ['first_observation', 'became_sold_out', 'became_available'],
            array_column($result['events'], 'event_type')
        );
        self::assertNull($result['events'][1]['price']);
        self::assertTrue($result['events'][1]['availability_evidence_eligible']);
        self::assertFalse($result['events'][1]['price_evidence_eligible']);
        self::assertFalse($result['events'][1]['decision_eligible']);
        self::assertNotContains('price_missing', $result['events'][1]['evidence_gaps']);
        self::assertSame('真实竞品酒店', $result['events'][1]['competitor_hotel_name']);
        self::assertSame('bookable', $result['events'][1]['previous_availability']);
        self::assertSame('sold_out', $result['events'][2]['previous_availability']);
        self::assertSame('price_increased', $result['events'][2]['secondary_event_type']);
        self::assertSame(299.0, $result['events'][2]['previous_price']);
        self::assertSame(20.0, $result['events'][2]['price_change_amount']);
    }

    public function testBuildsComparablePriceChangeEvent(): void
    {
        $service = new CompetitorEventFeedService();
        $rows = [
            $this->completeRow(['id' => 1, 'collected_at' => '2026-07-17 09:00:00', 'price' => 299.0]),
            $this->completeRow(['id' => 2, 'collected_at' => '2026-07-17 10:00:00', 'price' => 329.0]),
        ];

        $result = $service->buildFromRows($rows, 7, 'ctrip', '2026-07-20');
        $change = $result['events'][1];

        self::assertSame('price_increased', $change['event_type']);
        self::assertSame(299.0, $change['previous_price']);
        self::assertSame(30.0, $change['price_change_amount']);
        self::assertSame(10.03, $change['price_change_percent']);
        self::assertTrue($change['event_eligible']);
    }

    public function testBookableStatusAliasesDoNotHideComparablePriceChanges(): void
    {
        $service = new CompetitorEventFeedService();
        $rows = [
            $this->completeRow([
                'id' => 1,
                'collected_at' => '2026-07-17 09:00:00',
                'availability' => 'available',
                'price' => 299.0,
            ]),
            $this->completeRow([
                'id' => 2,
                'collected_at' => '2026-07-17 10:00:00',
                'availability' => 'bookable',
                'price' => 329.0,
            ]),
        ];

        $result = $service->buildFromRows($rows, 7, 'ctrip', '2026-07-20');
        $change = $result['events'][1];

        self::assertSame('price_increased', $change['event_type']);
        self::assertNull($change['secondary_event_type']);
        self::assertSame(299.0, $change['previous_price']);
        self::assertSame(30.0, $change['price_change_amount']);
    }

    public function testAvailabilityTimelineDoesNotCrossRoomOrSourceSurfaces(): void
    {
        $service = new CompetitorEventFeedService();
        $rows = [
            $this->completeRow([
                'id' => 1,
                'collected_at' => '2026-07-17 09:00:00',
                'room_type_key' => 'deluxe-king',
                'source_ref' => 'https://hotels.example.test/rate/90001',
                'availability' => 'bookable',
            ]),
            $this->completeRow([
                'id' => 2,
                'collected_at' => '2026-07-17 10:00:00',
                'room_type_key' => 'family-suite',
                'source_ref' => 'https://hotels.example.test/other/90001',
                'availability' => 'sold_out',
                'price' => null,
            ]),
        ];

        $result = $service->buildFromRows($rows, 7, 'ctrip', '2026-07-20');

        self::assertSame(['first_observation', 'first_observation'], array_column($result['events'], 'event_type'));
        self::assertNull($result['events'][1]['previous_event_id']);
    }

    public function testPartialPublicCardCanProveAvailabilityButNotComparablePrice(): void
    {
        $service = new CompetitorEventFeedService();
        $row = $this->completeRow([
            'validation_status' => 'incomplete',
            'comparison_key' => '',
            'price' => 391.0,
            'availability' => 'bookable',
            'source_method' => 'ctrip_public_nearby_card',
            'competitor_hotel_name' => '公开页真实竞品',
        ]);

        $result = $service->buildFromRows([$row], 7, 'ctrip', '2026-07-20');
        $event = $result['events'][0];

        self::assertSame('available', $result['status']);
        self::assertSame('verified_availability_events_only', $result['decision_gate']);
        self::assertSame(1, $result['availability_evidence_eligible_sample_count']);
        self::assertSame(0, $result['price_evidence_eligible_sample_count']);
        self::assertTrue($event['availability_evidence_eligible']);
        self::assertTrue($event['event_eligible']);
        self::assertFalse($event['price_evidence_eligible']);
        self::assertFalse($event['decision_eligible']);
        self::assertSame('partial', $event['quality_status']);
        self::assertContains('validation_status_not_eligible', $event['price_evidence_gaps']);
        self::assertNotContains('validation_status_not_eligible', $event['availability_evidence_gaps']);
    }

    public function testPublicCardWithoutVerifiedOtaIdentityCannotProveAvailability(): void
    {
        $service = new CompetitorEventFeedService();
        $row = $this->completeRow([
            'ota_hotel_id' => null,
            'competitor_ota_hotel_id' => null,
            'validation_status' => 'incomplete',
            'comparison_key' => '',
            'price' => 391.0,
            'availability' => 'bookable',
            'source_method' => 'ctrip_public_nearby_card',
        ]);

        $result = $service->buildFromRows([$row], 7, 'ctrip', '2026-07-20');
        $event = $result['events'][0];

        self::assertSame('insufficient_evidence', $result['status']);
        self::assertSame('competitor_identity_binding_missing', $result['decision_gate']);
        self::assertContains('competitor_ota_identity_binding_missing', $result['data_gaps']);
        self::assertSame(0, $result['availability_evidence_eligible_sample_count']);
        self::assertFalse($event['availability_evidence_eligible']);
        self::assertFalse($event['event_eligible']);
        self::assertContains('ota_hotel_id_missing_or_unverified', $event['availability_evidence_gaps']);
    }

    public function testBoundTargetDoesNotRetroactivelyVerifyIdentitylessObservation(): void
    {
        $service = new CompetitorEventFeedService();
        $row = $this->completeRow([
            'ota_hotel_id' => null,
            'competitor_ota_hotel_id' => '90001',
            'validation_status' => 'incomplete',
            'comparison_key' => '',
            'price' => 391.0,
            'availability' => 'bookable',
            'source_method' => 'ctrip_public_nearby_card',
        ]);

        $result = $service->buildFromRows([$row], 7, 'ctrip', '2026-07-20');
        $event = $result['events'][0];

        self::assertSame('insufficient_evidence', $result['status']);
        self::assertSame('observation_identity_unverified', $result['decision_gate']);
        self::assertContains('observation_ota_identity_unverified', $result['data_gaps']);
        self::assertSame(0, $result['identity_bound_sample_count']);
        self::assertSame(1, $result['target_identity_bound_sample_count']);
        self::assertSame('90001', $event['target_ota_hotel_id']);
        self::assertSame('target_bound_observation_unverified', $event['identity_status']);
        self::assertFalse($event['availability_evidence_eligible']);
    }

    public function testObservationIdentityMustMatchConfiguredCompetitorTarget(): void
    {
        $service = new CompetitorEventFeedService();
        $row = $this->completeRow([
            'ota_hotel_id' => '90002',
            'competitor_ota_hotel_id' => '90001',
            'validation_status' => 'valid',
        ]);

        $result = $service->buildFromRows([$row], 7, 'ctrip', '2026-07-20');
        $event = $result['events'][0];

        self::assertSame('insufficient_evidence', $result['status']);
        self::assertSame('observation_identity_mismatch', $result['decision_gate']);
        self::assertContains('observation_ota_identity_mismatch', $result['data_gaps']);
        self::assertSame(0, $result['identity_verified_sample_count']);
        self::assertSame(1, $result['identity_mismatch_sample_count']);
        self::assertSame('observation_identity_mismatch', $event['identity_status']);
        self::assertContains('ota_hotel_id_target_mismatch', $event['availability_evidence_gaps']);
        self::assertFalse($event['availability_evidence_eligible']);
        self::assertFalse($event['price_evidence_eligible']);
    }

    public function testProtectedRouteAndHotelScopeGuardAreWired(): void
    {
        $root = realpath(__DIR__ . '/..');
        self::assertIsString($root);
        $routes = (string)file_get_contents($root . '/route/app.php');
        $controller = (string)file_get_contents($root . '/app/controller/CompetitorApi.php');
        $service = (string)file_get_contents($root . '/app/service/CompetitorEventFeedService.php');

        self::assertStringContainsString(
            "Route::get('api/competitor/events', 'CompetitorApi/events')->middleware(\\app\\middleware\\Auth::class);",
            $routes
        );
        self::assertStringContainsString(
            "Route::get('api/competitor/targets', 'CompetitorApi/targets')->middleware(\\app\\middleware\\Auth::class);",
            $routes
        );
        self::assertStringContainsString(
            "Route::post('api/competitor/manual-observation', 'CompetitorApi/manualObservation')->middleware(\\app\\middleware\\Auth::class);",
            $routes
        );
        self::assertStringContainsString('public function events(): Response', $controller);
        self::assertStringContainsString('public function manualObservation(): Response', $controller);
        self::assertStringContainsString('HotelScopeService())->canAccessHotel(', $controller);
        self::assertStringContainsString("hotelPermissionAllows(\$this->currentUser, \$systemHotelId, 'ota.collect')", $controller);
        self::assertStringContainsString("'can_collect_manual_observation'", $controller);
        self::assertStringContainsString('CompetitorManualObservationService())->persist(', $controller);
        self::assertStringContainsString("->where('store_id', \$systemHotelId)", $service);
        self::assertStringNotContainsString("'screenshot',", $service);
        self::assertStringNotContainsString("'device_id',", $service);
    }

    public function testDatabaseWindowKeepsLatestEventsThenReturnsChronologicalFeed(): void
    {
        $service = (string)file_get_contents(__DIR__ . '/../app/service/CompetitorEventFeedService.php');

        self::assertStringContainsString("->order('collected_at', 'desc')", $service);
        self::assertStringContainsString("->order('id', 'desc')", $service);
        self::assertStringNotContainsString("->order('collected_at', 'asc')", $service);
        self::assertStringContainsString('usort($events, static function', $service);
        self::assertStringContainsString('strcmp((string)$leftTime, (string)$rightTime)', $service);
    }

    public function testTruncatedLatestWindowCannotClaimCompleteAvailability(): void
    {
        $service = new CompetitorEventFeedService();
        $rows = [];
        for ($id = 2; $id <= 201; $id++) {
            $rows[] = $this->completeRow([
                'id' => $id,
                'collected_at' => sprintf('2026-07-17 %02d:%02d:00', 8 + intdiv($id % 720, 60), $id % 60),
            ]);
        }

        $result = $service->buildFromRows($rows, 7, 'ctrip', '2026-07-20', '', '', 201);

        self::assertSame('partial', $result['status']);
        self::assertTrue($result['truncated']);
        self::assertSame('latest_returned_events_only', $result['summary_scope']);
        self::assertSame('latest_returned_events_only', $result['decision_eligible_count_scope']);
        self::assertSame('returned_window_only_matching_events_truncated', $result['decision_gate']);
        self::assertContains('matching_events_truncated', $result['data_gaps']);
        self::assertSame('partial', $result['platform_summaries'][0]['status']);
    }

    public function testRuntimeVerifierStrictModeRejectsPartialOrTruncatedWindows(): void
    {
        $verifier = (string)file_get_contents(
            __DIR__ . '/../scripts/verify_competitor_event_feed_runtime.php'
        );

        self::assertStringContainsString("'feed_status_available' =>", $verifier);
        self::assertStringContainsString("'not_truncated' =>", $verifier);
        self::assertStringContainsString("'all_matching_rows_returned' =>", $verifier);
        self::assertStringContainsString("'all_events_evidence_eligible' =>", $verifier);
        self::assertStringContainsString('exit($strict && !$passed ? 1 : 0);', $verifier);
    }

    /** @return array<string,mixed> */
    private function completeRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'tenant_id' => 3,
            'store_id' => 7,
            'hotel_id' => 70,
            'ota_hotel_id' => '90001',
            'competitor_ota_hotel_id' => '90001',
            'platform' => 'xc',
            'price' => 299.0,
            'collected_at' => '2026-07-17 09:05:00',
            'fetch_time' => '2026-07-17 09:05:02',
            'source_method' => 'public_page',
            'source_ref' => 'https://hotels.example.test/rate/90001?trace=private',
            'validation_status' => 'valid',
            'readback_verified' => 1,
            'check_in_date' => '2026-07-20',
            'check_out_date' => '2026-07-21',
            'nights' => 1,
            'adults' => 2,
            'children' => 0,
            'room_type_key' => 'deluxe-king',
            'ota_product_id' => 'product-1',
            'rate_plan_key' => 'breakfast-flex',
            'package_name' => '标准套餐',
            'breakfast' => '双早',
            'cancellation_policy' => '入住前一天可取消',
            'payment_mode' => 'prepay',
            'tax_fee_included' => 1,
            'price_basis' => 'per_room_per_night',
            'currency' => 'CNY',
            'availability' => 'bookable',
            'availability_scope_key' => str_repeat('b', 64),
            'comparison_key' => str_repeat('a', 64),
        ], $overrides);
    }
}
