<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenuePricingRecommendationService;
use app\service\TrustedOtaFactRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class RevenueSuggestionEffectMissingIncomeTest extends TestCase
{
    #[DataProvider('missingSides')]
    public function testMissingIncomeOnEitherSideCannotProduceRevenueOrAdrLift(string $side): void
    {
        $before = $this->period('2026-09-01', $side === 'before' ? 'missing' : 'complete');
        $after = $this->period('2026-09-08', $side === 'after' ? 'missing' : 'complete');
        $missing = $side === 'before' ? $before : $after;
        self::assertNull($missing['amount']);
        self::assertNull($missing['adr']);
        self::assertSame('partial', $missing['data_status']);
        self::assertSame(15, $missing['quantity']);
        self::assertSame(7, $missing['orders']);
        self::assertSame(6, $missing['metric_observation_counts']['amount']);
        self::assertContains('pricing_history_amount_missing', $missing['data_gaps']);
        $service = new RevenuePricingRecommendationService();
        $delta = $service->suggestionEffectDelta($before, $after);
        self::assertNull($delta['amount']);
        self::assertNull($delta['adr']);
        self::assertSame(0, $delta['quantity']);
        $readiness = $service->buildEffectReviewReadiness(['status' => 4], $before, $after, '2026-10-02');
        self::assertFalse($readiness['review_ready']);
        self::assertSame('数据不全', $readiness['status_label']);
    }

    public static function missingSides(): array
    {
        return ['before missing' => ['before'], 'after missing' => ['after']];
    }

    public function testExplicitZeroIncomeRemainsAComparableFact(): void
    {
        $before = $this->period('2026-09-01', 'zero');
        $after = $this->period('2026-09-08', 'complete');
        self::assertSame(0.0, $before['amount']);
        self::assertSame(0.0, $before['adr']);
        self::assertSame('ok', $before['data_status']);
        $service = new RevenuePricingRecommendationService();
        self::assertSame(1500.0, $service->suggestionEffectDelta($before, $after)['amount']);
        self::assertTrue($service->buildEffectReviewReadiness(['status' => 4], $before, $after, '2026-10-02')['review_ready']);
    }

    public function testCompleteAdjacentWindowsPreserveComparisonAndScope(): void
    {
        $before = $this->period('2026-09-01', 'complete');
        $after = $this->period('2026-09-08', 'complete');
        self::assertSame(80, $before['system_hotel_id']);
        self::assertSame(['ctrip'], $before['platforms']);
        self::assertSame('ota_channel', $before['metric_scope']);
        self::assertSame(1500.0, $before['amount']);
        self::assertSame(100.0, $before['adr']);
        $service = new RevenuePricingRecommendationService();
        self::assertSame(['amount' => 0.0, 'quantity' => 0, 'orders' => 0, 'adr' => 0.0], $service->suggestionEffectDelta($before, $after));
        self::assertTrue($service->buildEffectReviewReadiness(['status' => 4], $before, $after, '2026-10-02')['review_ready']);
    }

    #[DataProvider('scopeMismatches')]
    public function testDifferentScopeCannotBeCompared(string $key, mixed $value): void
    {
        $before = $this->period('2026-09-01', 'complete');
        $after = $this->period('2026-09-08', 'complete');
        $after[$key] = $value;
        $this->assertIncomparable($before, $after);
    }

    public static function scopeMismatches(): array
    {
        return [
            'different hotel' => ['system_hotel_id', 81],
            'different platform' => ['platforms', ['meituan']],
            'different platform set' => ['platforms', ['ctrip', 'meituan']],
            'unknown platform' => ['platforms', []],
            'whole hotel vs channel' => ['metric_scope', 'whole_hotel'],
            'different source' => ['source', 'manual_input'],
            'different sample scope' => ['scope', 'whole_hotel_operating_sample'],
        ];
    }

    #[DataProvider('dateMismatches')]
    public function testDifferentOrInvalidComparisonWindowsCannotBeCompared(string $start, string $end): void
    {
        $before = $this->period('2026-09-01', 'complete');
        $after = $this->period('2026-09-08', 'complete');
        $after['start_date'] = $start;
        $after['end_date'] = $end;
        $this->assertIncomparable($before, $after);
    }

    public static function dateMismatches(): array
    {
        return [
            'overlap' => ['2026-09-07', '2026-09-13'],
            'non adjacent' => ['2026-09-09', '2026-09-15'],
            'unequal duration' => ['2026-09-08', '2026-09-13'],
            'reversed' => ['2026-09-14', '2026-09-08'],
            'invalid calendar' => ['2026-02-30', '2026-03-08'],
        ];
    }

    #[DataProvider('missingSides')]
    public function testLegacyPartialNumbersCannotProduceRevenueOrAdrLift(string $side): void
    {
        $before = $this->period('2026-09-01', 'complete');
        $after = $this->period('2026-09-08', 'complete');
        // Previously returned numeric partial summaries must remain untrusted.
        if ($side === 'before') $before['data_status'] = 'partial';
        else $after['data_status'] = 'partial';
        $delta = (new RevenuePricingRecommendationService())->suggestionEffectDelta($before, $after);
        self::assertNull($delta['amount']);
        self::assertNull($delta['adr']);
        self::assertSame(0, $delta['quantity']);
    }

    public function testMissingNightsCannotCreateAdrFromAnIncompleteDenominator(): void
    {
        $period = $this->period('2026-09-01', 'missing_nights');
        self::assertSame(1500.0, $period['amount']);
        self::assertSame(6, $period['quantity']);
        self::assertNull($period['adr']);
        self::assertSame('partial', $period['data_status']);
    }

    private function assertIncomparable(array $before, array $after): void
    {
        $service = new RevenuePricingRecommendationService();
        self::assertSame(['amount' => null, 'quantity' => null, 'orders' => null, 'adr' => null], $service->suggestionEffectDelta($before, $after));
        $readiness = $service->buildEffectReviewReadiness(['status' => 4], $before, $after, '2026-10-02');
        self::assertFalse($readiness['review_ready']);
        self::assertSame('effect_review_scope_mismatch', $readiness['stage']);
    }

    private function period(string $start, string $scenario): array
    {
        $fixture = new TrustedOtaFactRepositoryTest('testReturnsOnlyCanonicalVerifiedSelfFactsForExactSystemHotel');
        $default = new ReflectionMethod($fixture, 'defaultRow');
        $raw = new ReflectionMethod($fixture, 'trustedRawData');
        $rows = [];
        for ($day = 0; $day < 7; $day++) {
            $date = (new \DateTimeImmutable($start))->modify('+' . $day . ' days')->format('Y-m-d');
            $row = array_merge($default->invoke($fixture), [
                'id' => 100 + $day, 'data_date' => $date, 'snapshot_bucket' => $date,
                'snapshot_time' => $date . ' 23:59:00', 'update_time' => $date . ' 23:59:00',
                'source_trace_id' => 'synthetic-null-fix-' . $date, 'sync_task_id' => 1000 + $day,
                'amount' => $scenario === 'zero' ? 0 : ($day === 6 ? 900 : 100),
                'quantity' => $day === 6 ? 9 : 1, 'book_order_num' => 1,
            ]);
            if ($day === 6 && $scenario === 'missing') $row['amount'] = null;
            if ($day === 6 && $scenario === 'missing_nights') $row['quantity'] = null;
            $row['raw_data'] = json_encode($raw->invoke($fixture, $row), JSON_THROW_ON_ERROR);
            $rows[] = $row;
        }
        $method = new ReflectionMethod(TrustedOtaFactRepository::class, 'reducePricingHistoryRows');
        $history = $method->invoke(new TrustedOtaFactRepository(), $rows, [], 'complete', array_fill_keys(array_keys($rows[0]), true), [18 => 'ctrip-80']);
        self::assertCount(7, $history['rows']);
        $repo = new class($history) extends TrustedOtaFactRepository {
            public function __construct(private array $history) {}
            public function pricingHistory(int $systemHotelId, string $startDate, string $endDate): array { return $this->history; }
        };
        return (new RevenuePricingRecommendationService($repo))->aggregateSuggestionEffect(80, $start, (new \DateTimeImmutable($start))->modify('+6 days')->format('Y-m-d'));
    }
}
