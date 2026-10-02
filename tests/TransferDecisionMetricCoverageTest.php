<?php
declare(strict_types=1);

namespace Tests;

use app\service\TransferDecisionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use think\Container;

require_once __DIR__ . '/TransferDecisionDayScopeTest.php';

final class TransferDecisionMetricCoverageTest extends TestCase
{
    public static function coverageCases(): iterable
    {
        $full = ['revenue' => 10000, 'room_nights' => 40, 'salable_rooms' => 80];
        yield 'one complete' => [[$full], 50.0, 250.0, 30.0, [1, 1, 1], false];
        yield 'two complete' => [[$full, $full], 50.0, 250.0, 30.0, [2, 2, 2], false];
        yield 'missing second day' => [[$full, ['salable_rooms' => 80]], 50.0, 250.0, 30.0, [1, 1, 1], true];
        yield 'null second day' => [[$full, ['revenue' => null, 'room_nights' => null, 'salable_rooms' => 80]], 50.0, 250.0, 30.0, [1, 1, 1], true];
        yield 'true zero second day' => [[$full, ['revenue' => 0, 'room_nights' => 0, 'salable_rooms' => 80]], 25.0, 250.0, 15.0, [2, 2, 2], false];
        yield 'revenue-only second day' => [[$full, ['revenue' => 30000, 'salable_rooms' => 80]], 50.0, 250.0, 60.0, [2, 1, 1], true];
        yield 'nights-only second day' => [[$full, ['room_nights' => 80, 'salable_rooms' => 80]], 75.0, 250.0, 30.0, [1, 2, 1], true];
        yield 'no same-day pair' => [[['revenue' => 10000, 'salable_rooms' => 80], ['room_nights' => 40, 'salable_rooms' => 80]], 50.0, null, 30.0, [1, 1, 0], true];
        yield 'complete plus two unpaired days' => [[$full, ['revenue' => 90000, 'salable_rooms' => 80], ['room_nights' => 120, 'salable_rooms' => 80]], 100.0, 250.0, 150.0, [2, 2, 1], true];
        yield 'only missing' => [[['salable_rooms' => 80]], null, null, null, [0, 0, 0], true];
        yield 'only true zero' => [[['revenue' => 0, 'room_nights' => 0, 'salable_rooms' => 80]], 0.0, null, 0.0, [1, 1, 1], false];
        yield 'zero revenue positive nights' => [[['revenue' => 0, 'room_nights' => 40, 'salable_rooms' => 80]], 50.0, 0.0, 0.0, [1, 1, 1], false];
        yield 'positive revenue zero nights' => [[['revenue' => 10000, 'room_nights' => 0, 'salable_rooms' => 80]], 0.0, null, 30.0, [1, 1, 1], false];
        yield 'legacy aliases zero pair' => [[['xb_revenue' => '0', 'xb_rooms' => '2', 'salable_rooms' => 80]], 2.5, 0.0, 0.0, [1, 1, 1], false];
    }

    #[DataProvider('coverageCases')]
    public function testPublicSourceUsesOnlyMetricObservedDaysAndSameDayPairs(array $facts, ?float $occ, ?float $adr, ?float $annualRevenue, array $days, bool $gap): void
    {
        $daily = array_map(fn(array $data, int $i): array => $this->row($data, sprintf('2026-09-%02d', 14 - $i), $i + 1), $facts, array_keys($facts));
        $result = $this->source($daily);
        self::assertSame($occ, $result['pricing_input']['occupancy_rate']);
        self::assertSame($adr, $result['pricing_input']['adr']);
        self::assertSame($annualRevenue, $result['timing_input']['previous_revenue']);
        self::assertSame($adr, $result['timing_input']['previous_adr']);
        $current = $result['snapshot']['current'];
        self::assertSame($days, [$current['revenue_observed_days'], $current['room_nights_observed_days'], $current['adr_paired_days']]);
        self::assertSame(count($facts), $current['actual_days']);
        self::assertSame(count($facts), $current['daily_report_days']);
        self::assertSame($adr !== null, $current['adr_observed']);
        $currentGaps = array_values(array_filter($result['snapshot']['data_gaps'], static fn(string $code): bool => str_starts_with($code, 'current_daily_')));
        self::assertSame($gap, $currentGaps !== []);
        if ($gap) {
            self::assertStringContainsString('当前30天', $result['snapshot']['data_status']);
            self::assertStringContainsString('年度365天', $result['snapshot']['data_status']);
            self::assertTrue($result['timing_input']['has_data_gap']);
        }
        self::assertFalse($result['snapshot']['source_verified']);
        self::assertSame('local_daily_report_only', $result['snapshot']['source_scope']);
        self::assertSame(7, $result['hotel_id']);
        self::assertSame(42, $result['tenant_id']);
        self::assertSame('2026-09-14', $result['source_date']);
    }

    public function testSevenCompleteDaysDoNotHideTheEighthMissingDay(): void
    {
        $full = ['revenue' => 10000, 'room_nights' => 40, 'salable_rooms' => 80];
        $daily = array_map(fn(int $i): array => $this->row($full, sprintf('2026-09-%02d', 14 - $i), $i + 1), range(0, 6));
        $before = $this->source($daily);
        self::assertFalse($before['timing_input']['has_data_gap']);
        $after = $this->source([...$daily, $this->row(['salable_rooms' => 80], '2026-09-07', 8)]);
        self::assertTrue($after['timing_input']['has_data_gap']);
        foreach (['revenue', 'room_nights', 'adr', 'occupancy_rate'] as $key) self::assertSame($before['snapshot']['current'][$key], $after['snapshot']['current'][$key], $key);
        self::assertSame(30.0, $after['timing_input']['previous_revenue']);
        self::assertSame(8, $after['snapshot']['current']['daily_report_days']);
        self::assertSame(7, $after['snapshot']['current']['revenue_observed_days']);
        self::assertContains('current_daily_revenue_coverage_incomplete', $after['snapshot']['data_gaps']);
        self::assertStringContainsString('7/8', $after['snapshot']['data_status']);
    }

    public function testAnnualOnlyMissingDayIsReportedInTheAnnualWindow(): void
    {
        $daily = array_map(fn(int $i): array => $this->row(['revenue' => 10000, 'room_nights' => 40, 'salable_rooms' => 80], sprintf('2026-09-%02d', 14 - $i), $i + 1), range(0, 6));
        $data = $this->source([...$daily, $this->row([], '2026-08-01', 8)]);
        self::assertSame(7, $data['snapshot']['current']['daily_report_days']);
        self::assertSame(8, $data['snapshot']['annual']['daily_report_days']);
        self::assertSame(30.0, $data['timing_input']['previous_revenue']);
        self::assertContains('annual_daily_revenue_coverage_incomplete', $data['snapshot']['data_gaps']);
        self::assertNotContains('current_daily_revenue_coverage_incomplete', $data['snapshot']['data_gaps']);
        self::assertStringContainsString('年度365天', $data['snapshot']['data_status']);
        self::assertStringNotContainsString('当前30天', $data['snapshot']['data_status']);
    }

    public function testCoverageCountsUniqueDatesAndPairsSameDateFragments(): void
    {
        $data = $this->source([$this->row(['revenue' => 10000, 'salable_rooms' => 80]), $this->row(['room_nights' => 40, 'salable_rooms' => 80], '2026-09-14', 2)]);
        self::assertSame(2, $data['snapshot']['source_counts']['daily_reports']);
        self::assertSame(1, $data['snapshot']['current']['daily_report_days']);
        self::assertSame(1, $data['snapshot']['current']['revenue_observed_days']);
        self::assertSame(1, $data['snapshot']['current']['room_nights_observed_days']);
        self::assertSame(1, $data['snapshot']['current']['adr_paired_days']);
        self::assertSame(250.0, $data['pricing_input']['adr']);
        self::assertSame(30.0, $data['timing_input']['previous_revenue']);
    }

    public function testForeignHotelTenantAndDatesCannotCompleteAMissingPair(): void
    {
        $nights = ['room_nights' => 40, 'salable_rooms' => 80];
        $data = $this->source([$this->row(['revenue' => 10000, 'salable_rooms' => 80]),
            array_replace($this->row($nights, '2026-09-14', 2), ['hotel_id' => 8]),
            array_replace($this->row($nights, '2026-09-14', 3), ['tenant_id' => 999]),
            $this->row($nights, '2026-09-15', 4), $this->row($nights, '2025-01-01', 5)]);
        self::assertNull($data['pricing_input']['adr']);
        self::assertNull($data['pricing_input']['occupancy_rate']);
        self::assertSame(1, $data['snapshot']['source_counts']['daily_reports']);
        self::assertSame(1, $data['snapshot']['source_counts']['annual_daily_reports']);
        self::assertSame(0, $data['snapshot']['current']['adr_paired_days']);
    }

    public function testUnknownRevenueDaysCannotBorrowKnownDailyOrChannelCoverage(): void
    {
        $service = (new \ReflectionClass(TransferDecisionService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($service, 'annualThirtyDayBenchmark');
        $base = ['actual_days' => 60, 'daily_report_days' => 30, 'revenue' => 600000, 'revenue_observed' => true, 'orders' => 120];
        foreach ([$base, array_replace($base, ['revenue_observed_days' => 0]), array_replace($base, ['revenue_observed_days' => null])] as $input) {
            $result = $method->invoke($service, $input);
            self::assertFalse($result['revenue_observed']);
            self::assertSame(60, $result['orders']);
        }
    }

    private function row(array $data, string $date = '2026-09-14', int $id = 1): array
    {
        return ['id' => $id, 'hotel_id' => 7, 'tenant_id' => 42, 'report_date' => $date, 'status' => 2, 'report_data' => json_encode($data, JSON_THROW_ON_ERROR)];
    }

    private function source(array $daily): array
    {
        TransferDayMemoryStore::reset($daily);
        TransferDayMemoryStore::$tables['hotels'][] = ['id' => 8, 'tenant_id' => 42, 'name' => 'Synthetic other hotel', 'address' => 'Synthetic B'];
        $previous = (new \ReflectionProperty(Container::class, 'instance'))->getValue();
        $container = new Container();
        $container->instance('think\DbManager', new TransferDayMemoryDatabase());
        Container::setInstance($container);
        try {
            return (new \ReflectionClass(TransferDecisionService::class))->newInstanceWithoutConstructor()->buildSourcePayload([7, 8], 7, '2026-09-14');
        } finally {
            Container::setInstance($previous);
        }
    }
}
