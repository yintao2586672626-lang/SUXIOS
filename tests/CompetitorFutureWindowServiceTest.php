<?php
declare(strict_types=1);

namespace Tests;

use app\service\CompetitorFutureWindowService;
use PHPUnit\Framework\TestCase;

final class CompetitorFutureWindowServiceTest extends TestCase
{
    public function testBuildsTwentyOneDayMatrixAndKeepsPricingBlockedByMapping(): void
    {
        $calls = [];
        $service = new CompetitorFutureWindowService(
            function (int $hotelId, string $platform, string $stayDate) use (&$calls): array {
                $calls[] = [$hotelId, $platform, $stayDate];
                return $this->feed($stayDate, [$this->event(1, $stayDate)]);
            },
            $this->clock()
        );

        $result = $service->build(80, 'ctrip', '2026-08-29', 21);

        self::assertSame('partial', $result['status']);
        self::assertSame('2026-09-18', $result['end_date']);
        self::assertSame('2026-08-29 03:30:00', $result['as_of_collected_at']);
        self::assertSame(21, $result['days']);
        self::assertSame(21, $result['covered_date_count']);
        self::assertSame(21, $result['cell_count']);
        self::assertSame(21, $result['availability_evidence_cell_count']);
        self::assertSame(21, $result['price_evidence_cell_count']);
        self::assertSame('blocked_by_room_type_mapping', $result['pricing_decision_status']);
        self::assertSame('mapping_missing', $result['room_type_mapping_status']);
        self::assertContains('room_type_mapping_missing', $result['data_gaps']);
        self::assertFalse($result['decision_eligible']);
        self::assertFalse($result['price_suggestion_created']);
        self::assertFalse($result['auto_write_ota']);
        self::assertCount(21, $calls);
        self::assertCount(21, $result['matrix']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['matrix'][0]['cells'][0]['rate_terms_key']);
    }

    public function testMissingDatesAndSoldOutNullPriceRemainExplicit(): void
    {
        $service = new CompetitorFutureWindowService(
            function (int $hotelId, string $platform, string $stayDate): array {
                if ($stayDate === '2026-08-29') {
                    return $this->feed($stayDate, [$this->event(1, $stayDate, [
                        'availability' => 'sold_out',
                        'price' => null,
                        'price_evidence_eligible' => false,
                    ])]);
                }
                return $this->feed($stayDate, []);
            },
            $this->clock()
        );

        $result = $service->build(80, 'xc', '2026-08-29', 3);

        self::assertSame('partial', $result['status']);
        self::assertSame(1, $result['covered_date_count']);
        self::assertSame(2, $result['missing_date_count']);
        self::assertSame(1, $result['availability_evidence_cell_count']);
        self::assertSame(0, $result['price_evidence_cell_count']);
        self::assertNull($result['matrix'][0]['cells'][0]['price']);
        self::assertSame('sold_out', $result['matrix'][0]['cells'][0]['availability']);
        self::assertContains('collection_missing', $result['data_gaps']);
    }

    public function testLatestSnapshotWinsOnlyInsideTheSameRateCell(): void
    {
        $service = new CompetitorFutureWindowService(
            fn(int $hotelId, string $platform, string $stayDate): array => $this->feed($stayDate, [
                $this->event(1, $stayDate, ['collected_at' => '2026-08-29 08:00:00', 'price' => 299]),
                $this->event(2, $stayDate, ['collected_at' => '2026-08-29 09:00:00', 'price' => 319]),
                $this->event(3, $stayDate, ['room_type_key' => 'family-suite', 'price' => 499]),
                $this->event(4, $stayDate, [
                    'nights' => 2,
                    'check_out_date' => '2026-08-31',
                    'package_name' => '连住双早',
                    'price' => 599,
                ]),
            ]),
            $this->clock()
        );

        $result = $service->build(80, 'ctrip', '2026-08-29', 1);
        $cells = $result['matrix'][0]['cells'];

        self::assertCount(3, $cells);
        $byRoom = array_column($cells, null, 'room_type_key');
        $oneNight = array_values(array_filter($cells, static fn(array $cell): bool =>
            $cell['room_type_key'] === 'deluxe-king' && $cell['nights'] === 1
        ))[0];
        self::assertSame(2, $oneNight['event_id']);
        self::assertSame(319.0, $oneNight['price']);
        self::assertSame(3, $byRoom['family-suite']['event_id']);
        self::assertCount(2, array_filter($cells, static fn(array $cell): bool => $cell['room_type_key'] === 'deluxe-king'));
    }

    public function testFutureWindowRejectsPastDatesAndUnsupportedPlatforms(): void
    {
        $service = new CompetitorFutureWindowService(
            static fn(): array => [],
            $this->clock()
        );
        try {
            $service->build(80, 'booking', '2026-08-29', 21);
            self::fail('Unsupported platform must fail.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('platform only supports', $error->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('start_date must be today through the next 90 days');
        $service->build(80, 'ctrip', '2026-08-28', 21);
    }

    public function testWindowEndCannotExtendPastTheNinetyDayCollectionHorizon(): void
    {
        $service = new CompetitorFutureWindowService(static fn(): array => [], $this->clock());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('end_date must stay within the next 90 days');
        $service->build(80, 'ctrip', '2026-11-27', 21);
    }

    public function testApiIsAuthenticatedHotelScopedAndReadOnly(): void
    {
        $root = dirname(__DIR__);
        $controller = (string)file_get_contents($root . '/app/controller/CompetitorApi.php');
        $routes = (string)file_get_contents($root . '/route/app.php');
        $service = (string)file_get_contents($root . '/app/service/CompetitorFutureWindowService.php');

        self::assertStringContainsString('public function futureWindow()', $controller);
        self::assertStringContainsString("'can_view_online_data'", $controller);
        self::assertStringContainsString('new CompetitorFutureWindowService()', $controller);
        self::assertStringContainsString("Route::get('api/competitor/future-window'", $routes);
        self::assertStringContainsString('middleware(\\app\\middleware\\Auth::class)', $routes);
        self::assertStringContainsString('new CompetitorEventFeedService()', $service);
        self::assertStringContainsString("'price_suggestion_created' => false", $service);
        self::assertStringContainsString("'auto_write_ota' => false", $service);
        self::assertStringNotContainsString("Db::name('price_suggestions')", $service);
    }

    /** @return callable():\DateTimeImmutable */
    private function clock(): callable
    {
        return static fn(): \DateTimeImmutable => new \DateTimeImmutable(
            '2026-08-29 03:30:00',
            new \DateTimeZone('Asia/Shanghai')
        );
    }

    /** @param list<array<string,mixed>> $events @return array<string,mixed> */
    private function feed(string $stayDate, array $events): array
    {
        return [
            'status' => $events === [] ? 'empty' : 'available',
            'stay_date' => $stayDate,
            'events' => $events,
            'data_gaps' => $events === [] ? ['no_matching_competitor_price_events'] : [],
            'collection_coverage' => [
                'target_count' => 1,
            ],
        ];
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function event(int $id, string $stayDate, array $overrides = []): array
    {
        return array_replace([
            'id' => $id,
            'platform' => 'ctrip',
            'competitor_hotel_id' => 501,
            'competitor_hotel_name' => '同圈竞品A',
            'ota_hotel_id' => '90001',
            'stay_date' => $stayDate,
            'check_out_date' => (new \DateTimeImmutable($stayDate))->modify('+1 day')->format('Y-m-d'),
            'nights' => 1,
            'room_type_key' => 'deluxe-king',
            'rate_plan_key' => 'breakfast-flex',
            'package_name' => '双早',
            'breakfast' => '2份',
            'cancellation_policy' => '18点前免费取消',
            'payment_mode' => '预付',
            'tax_fee_included' => true,
            'currency' => 'CNY',
            'adults' => 2,
            'children' => 0,
            'price_basis' => 'room_night_total',
            'availability' => 'bookable',
            'price' => 299.0,
            'collected_at' => '2026-08-29 08:00:00',
            'availability_evidence_eligible' => true,
            'price_evidence_eligible' => true,
            'readback_verified' => true,
            'source_ref' => 'competitor_price_log#' . $id,
            'evidence_gaps' => [],
        ], $overrides);
    }
}
