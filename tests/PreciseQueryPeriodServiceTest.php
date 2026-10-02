<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\PreciseQuerySyntheticFixture as Fixture;
use think\facade\Db;

final class PreciseQueryPeriodServiceTest extends TestCase
{
    private static string $path;

    public static function setUpBeforeClass(): void
    {
        self::$path = sys_get_temp_dir() . '/precise-period-synthetic-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.sqlite';
        Fixture::connect(self::$path);
    }

    public static function tearDownAfterClass(): void
    {
        Db::connect('sqlite')->close();
        @unlink(self::$path);
    }

    #[DataProvider('explicitRanges')]
    public function testExplicitRangeKeepsRequestedDatesCoverageAndExactReadback(
        string $query,
        string $start,
        string $end,
        array $missing,
        float $sum,
        int $hotelId = 80,
        string $platform = 'ctrip'
    ): void {
        $readDates = [];
        $readHotels = [];
        $router = Fixture::router(static function (int $hotel, string $date) use ($missing, $platform, &$readDates, &$readHotels): array {
            $readDates[] = $date;
            $readHotels[] = $hotel;
            $closure = Fixture::closure($hotel, $date);
            if (in_array($date, $missing, true)) $closure['platforms'][$platform]['fields'] = [];
            return $closure;
        });
        $result = $router->route(10, [$hotelId], 7, [
            'query' => $query,
            'current_scope' => ['hotel_id' => $hotelId, 'date_start' => '2026-09-05', 'date_end' => '2026-09-06'],
        ]);

        self::assertSame($start, $result['parsed_scope']['date_start']);
        self::assertSame($end, $result['parsed_scope']['date_end']);
        self::assertSame('explicit_range', $result['parsed_scope']['date_source']);
        self::assertSame($hotelId, $result['parsed_scope']['hotel_id']);
        self::assertSame($hotelId === 82 ? 11 : 10, $result['parsed_scope']['tenant_id']);
        self::assertSame($platform, $result['parsed_scope']['platform']);
        self::assertSame([$hotelId], array_values(array_unique($readHotels)));
        self::assertSame($result['parsed_scope']['dates'], $readDates);
        self::assertSame($missing, $result['answer']['coverage']['missing_dates']);
        self::assertSame(count($readDates), $result['answer']['coverage']['expected_days']);
        self::assertSame(count($readDates) - count($missing), $result['answer']['coverage']['available_days']);
        self::assertSame($missing === [] ? 'answered_from_period_facts' : 'partial_period', $result['status']);
        self::assertEquals($sum, $result['answer'][$missing === [] ? 'value' : 'partial_value']);
        self::assertNull($result['answer'][$missing === [] ? 'partial_value' : 'value']);
        self::assertSame($result, $router->read($result['id'], 10, [$hotelId]));
        self::assertFalse($result['boundaries']['external_llm_called']);
    }

    public static function explicitRanges(): array
    {
        return [
            'complete same month' => ['携程2026.09.01到2026.09.03订单额', '2026-09-01', '2026-09-03', [], 306.0],
            'missing day remains partial' => ['携程2026.09.01到2026.09.03订单额', '2026-09-01', '2026-09-03', ['2026-09-02'], 204.0],
            'cross month' => ['携程2026.08.30至2026.09.02订单额', '2026-08-30', '2026-09-02', [], 464.0],
            'abbreviated end without suffix' => ['携程2026-09-01至03订单额', '2026-09-01', '2026-09-03', [], 306.0],
            'legacy metric before abbreviated end' => ['携程9月1日订单额到3日', '2026-09-01', '2026-09-03', [], 306.0],
            'abbreviated same day' => ['携程2026-09-01至01订单额', '2026-09-01', '2026-09-01', [], 101.0],
            'metric wording is not another date range' => ['携程2026-09-01至01曝光到访率', '2026-09-01', '2026-09-01', [], 10.0],
            'read request before a valid range' => ['查看到携程2026-09-01至2026-09-03订单额', '2026-09-01', '2026-09-03', [], 306.0],
            'read wording after a valid range' => ['携程2026-09-01至2026-09-03查到的订单额', '2026-09-01', '2026-09-03', [], 306.0],
            'abbreviated maximum month' => ['携程2026-08-01至31订单额', '2026-08-01', '2026-08-31', [], 3596.0],
            'week start to yesterday' => ['携程上周到昨天订单额', '2026-08-31', '2026-09-07', [], 859.0],
            'explicit last day remains missing' => ['携程上周到昨天订单额', '2026-08-31', '2026-09-07', ['2026-09-07'], 752.0],
            'week start to explicit date' => ['携程上周到2026-09-03订单额', '2026-08-31', '2026-09-03', [], 437.0],
            'week range maximum span' => ['携程上周到2026-09-30订单额', '2026-08-31', '2026-09-30', [], 3596.0],
            'other authorized hotel and platform' => ['美团上周到昨天订单额', '2026-08-31', '2026-09-07', [], 859.0, 82, 'meituan'],
        ];
    }

    #[DataProvider('twoExplicitComparisonPeriods')]
    public function testTwoExplicitComparisonPeriodsKeepEveryEndpoint(string $query): void
    {
        $router = Fixture::router();
        $result = $router->route(10, [80], 7, [
            'query' => $query,
            'current_scope' => ['hotel_id' => 80, 'date_start' => '2026-09-05', 'date_end' => '2026-09-06'],
        ]);

        self::assertSame('answered_from_period_facts', $result['status']);
        self::assertSame(['2026-09-01', '2026-09-02', '2026-09-03'], $result['parsed_scope']['dates']);
        self::assertSame(['2026-08-01', '2026-08-02', '2026-08-03'], $result['parsed_scope']['comparison']['dates']);
        self::assertTrue($result['answer']['comparison']['comparable']);
        self::assertEquals(306.0, $result['answer']['comparison']['current']['value']);
        self::assertEquals(306.0, $result['answer']['comparison']['baseline']['value']);
        self::assertEquals(0.0, $result['answer']['comparison']['difference']);
        self::assertSame($result, $router->read($result['id'], 10, [80]));
    }

    public static function twoExplicitComparisonPeriods(): array
    {
        return [
            'all endpoints explicit' => ['携程2026-09-01至2026-09-03对比2026-08-01至2026-08-03订单额'],
            'one abbreviated endpoint in each period' => ['携程2026-09-01至03对比2026-08-01至03订单额'],
        ];
    }

    #[DataProvider('invalidOrAmbiguousRanges')]
    public function testInvalidOrAmbiguousRangeCannotFallBackToSelectedPeriod(string $query, string $reason): void
    {
        $calls = 0;
        $router = Fixture::router(static function (int $hotel, string $date) use (&$calls): array {
            $calls++;
            return Fixture::closure($hotel, $date);
        });
        $result = $router->route(10, [80], 7, [
            'query' => $query,
            'current_scope' => ['hotel_id' => 80, 'date_start' => '2026-09-05', 'date_end' => '2026-09-06'],
        ]);

        self::assertSame($result, $router->read($result['id'], 10, [80]));
        self::assertSame('clarification_required', $result['status'], json_encode([
            'dates' => $result['parsed_scope']['dates'] ?? [], 'source_reads' => $calls,
        ], JSON_THROW_ON_ERROR));
        self::assertSame($reason, $result['answer']['reason']);
        self::assertSame(0, $calls, 'An invalid explicit date must not query unrelated selected days.');
    }

    public static function invalidOrAmbiguousRanges(): array
    {
        return [
            'invalid dotted date' => ['携程2026.02.29至2026.03.01订单额', 'business_date_invalid'],
            'invalid abbreviated date' => ['携程2026-02-28至29订单额', 'business_date_invalid'],
            'reversed abbreviated date' => ['携程2026-09-05至03订单额', 'business_date_invalid'],
            'abbreviation must not invent next month' => ['携程2026-08-30至03订单额', 'business_date_invalid'],
            'abbreviated day zero' => ['携程2026-09-01至00订单额', 'business_date_invalid'],
            'abbreviated day exceeds month' => ['携程2026-09-01至32订单额', 'business_date_invalid'],
            'three digit end is ambiguous' => ['携程2026-09-01至003订单额', 'period_needs_explicit_dates'],
            'multiple abbreviated ends' => ['携程2026-09-01至03至05订单额', 'multiple_periods'],
            'mixed abbreviated then full end' => ['携程2026-09-01至03至2026-09-05订单额', 'multiple_periods'],
            'mixed full then abbreviated end' => ['携程2026-09-01至2026-09-03至05订单额', 'multiple_periods'],
            'mixed abbreviated then unknown end' => ['携程2026-09-01至03到月底订单额', 'multiple_periods'],
            'repeated full endpoint must not be discarded' => ['携程2026-09-01至2026-09-03和2026-09-01订单额', 'multiple_periods'],
            'month boundary unspecified' => ['携程2026-09-01至月底订单额', 'period_needs_explicit_dates'],
            'invalid explicit week end' => ['携程上周到2026-09-31订单额', 'business_date_invalid'],
            'reversed explicit week end' => ['携程上周到2026-08-30订单额', 'business_date_invalid'],
            'explicit week end exceeds limit' => ['携程上周到2026-10-01订单额', 'period_limit'],
            'ambiguous week end' => ['携程上周到下周订单额', 'period_needs_explicit_dates'],
            'period is not an explicit end day' => ['携程上周到本周订单额', 'period_needs_explicit_dates'],
            'week end day has no month' => ['携程上周到03订单额', 'period_needs_explicit_dates'],
        ];
    }
}
