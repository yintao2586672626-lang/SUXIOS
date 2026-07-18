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
        self::assertStringContainsString('public function events(): Response', $controller);
        self::assertStringContainsString('HotelScopeService())->canAccessHotel(', $controller);
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

    /** @return array<string,mixed> */
    private function completeRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'tenant_id' => 3,
            'store_id' => 7,
            'hotel_id' => 70,
            'ota_hotel_id' => '90001',
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
            'comparison_key' => str_repeat('a', 64),
        ], $overrides);
    }
}
