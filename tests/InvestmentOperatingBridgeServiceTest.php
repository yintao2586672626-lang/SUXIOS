<?php
declare(strict_types=1);

namespace Tests;

use app\service\InvestmentOperatingBridgeService as Bridge;
use app\service\InvestmentPaybackCalculator as Calculator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InvestmentOperatingBridgeServiceTest extends TestCase
{
    private function project(int $id = 1, array $changes = [], array $summaryChanges = []): array
    {
        return array_replace([
            'id' => $id, 'tenant_id' => 2, 'hotel_id' => 80, 'project_name' => '自投项目' . $id,
            'investor_name' => '本人', 'basis' => 'investor_cash', 'currency' => 'CNY', 'archived_at' => null,
            'status' => 'operating',
            'summary' => array_replace([
                'as_of' => '2026-09-30', 'basis' => 'investor_cash', 'currency' => 'CNY',
                'invested_amount' => '100.01', 'net_recovered_amount' => '30.01',
                'unrecovered_amount' => '70.00', 'excess_recovered_amount' => '0.00',
                'data_quality' => ['manual_unverified' => true, 'history_complete' => true, 'history_complete_through' => '2026-10-02', 'issues' => []],
            ], $summaryChanges),
        ], $changes);
    }

    private function service(array $projects, ?callable $reader = null): Bridge
    {
        return new Bridge(null, $reader ?? static function (array $filters) use ($projects): array {
            return ['list' => array_slice($projects, ($filters['page'] - 1) * $filters['page_size'], $filters['page_size']), 'pagination' => ['total' => count($projects)]];
        }, static fn(): string => '2026-10-02');
    }

    public function testExactFenTotalsAndHotelFilteringNeverAddProfitOrScenario(): void
    {
        $projects = [$this->project(1), $this->project(2, ['archived_at' => '2026-09-29'], [
            'invested_amount' => '50.02', 'net_recovered_amount' => '60.03', 'unrecovered_amount' => '0.00', 'excess_recovered_amount' => '10.01',
            'gop' => 999999, 'forecast' => ['payback_month' => '2027-01'], 'scenario_cashflow' => 888888,
        ]), $this->project(3, ['hotel_id' => 81]), $this->project(4, ['hotel_id' => null])];
        $result = $this->service($projects)->overview(2, [80, 81], 80, '2026-09');
        self::assertSame('ready', $result['status']);
        self::assertSame(['actual_invested' => '150.03', 'net_actual_recovered' => '90.04', 'unrecovered' => '70.00', 'excess_return' => '10.01'], $result['totals']);
        self::assertCount(2, $result['projects']);
        self::assertSame('manual_unverified', $result['quality']['source_quality_status']);
        self::assertFalse($result['quality']['actual_cash_independently_verified']);
        self::assertFalse($result['boundaries']['gop_is_actual_recovery']);
        self::assertFalse($result['boundaries']['actual_cash_written']);
    }

    public function testCurrentMonthUsesTodayAndFutureMonthDoesNotRead(): void
    {
        $calls = [];
        $service = $this->service([], function (array $filters) use (&$calls): array {
            $calls[] = $filters;
            return ['list' => [$this->project(1, [], ['as_of' => $filters['as_of']])], 'pagination' => ['total' => 1]];
        });
        $current = $service->overview(2, [80], 80, '2026-10');
        self::assertSame('2026-10-31', $current['requested_period_end']);
        self::assertSame('2026-10-02', $current['effective_as_of']);
        self::assertSame('current_month_to_date', $current['cutoff_status']);
        self::assertSame('2026-10-02', $calls[0]['as_of']);
        self::assertSame(2, $calls[0]['tenant_id']);
        self::assertSame(80, $calls[0]['hotel_id']);
        self::assertTrue($calls[0]['include_archived']);
        $future = $service->overview(2, [80], 80, '2026-11');
        self::assertSame('not_started', $future['status']);
        self::assertSame('2026-10-02', $future['effective_as_of']);
        self::assertNull($future['projects']);
        self::assertNull($future['totals']);
        self::assertCount(1, $calls);
    }

    public function testMoreThanOneHundredProjectsAreReadAcrossPages(): void
    {
        $projects = array_map(fn(int $id): array => $this->project($id), range(1, 101));
        $result = $this->service($projects)->overview(2, [80], 80, '2026-09');
        self::assertSame('10101.01', $result['totals']['actual_invested']);
        self::assertSame(2, $result['coverage']['pages_read']);
        self::assertTrue($result['coverage']['read_complete']);
        self::assertCount(101, $result['projects']);
    }

    public function testPageLimitIsExplicitAndDoesNotClaimCompleteTotals(): void
    {
        $service = $this->service([], function (array $filters): array {
            $start = ($filters['page'] - 1) * 100 + 1;
            return ['list' => array_map(fn(int $id): array => $this->project($id), range($start, $start + 99)), 'pagination' => ['total' => 10001]];
        });
        $result = $service->overview(2, [80], 80, '2026-09');
        self::assertSame('partial', $result['status']);
        self::assertFalse($result['coverage']['read_complete']);
        self::assertNull($result['totals']);
        self::assertSame(100, $result['coverage']['pages_read']);
        self::assertContains('project_pagination_limit_reached', $result['quality']['issues']);
        self::assertNotNull($result['recorded_totals']);
    }

    public function testReadFailureIsNullAndDoesNotExposeExceptionMaterial(): void
    {
        $result = $this->service([], static function (): array { throw new RuntimeException('secret connection string'); })->overview(2, [80], 80, '2026-09');
        self::assertSame('read_failed', $result['status']);
        self::assertNull($result['projects']);
        self::assertNull($result['totals']);
        self::assertStringNotContainsString('secret', json_encode($result));
    }

    public function testNoLinkedProjectsIsMissingNotZero(): void
    {
        $result = $this->service([$this->project(1, ['hotel_id' => 81])])->overview(2, [80, 81], 80, '2026-09');
        self::assertSame('missing', $result['status']);
        self::assertSame([], $result['projects']);
        self::assertNull($result['totals']);
        self::assertNull($result['recorded_totals']);
    }

    public function testPartialHistoryAndMissingActualInvestmentDoNotBecomeCompleteOrZero(): void
    {
        $result = $this->service([$this->project(1, [], [
            'data_quality' => ['manual_unverified' => true, 'history_complete' => false, 'history_complete_through' => '2026-08-31', 'issues' => ['history_not_checked_through_cutoff']],
        ]), $this->project(2, [], ['invested_amount' => null, 'net_recovered_amount' => null, 'unrecovered_amount' => null, 'excess_recovered_amount' => null])])->overview(2, [80], 80, '2026-09');
        self::assertSame('partial', $result['status']);
        self::assertNull($result['totals']);
        self::assertSame('100.01', $result['recorded_totals']['actual_invested']);
        self::assertSame(1, $result['coverage']['summable_project_count']);
        self::assertFalse($result['quality']['history_complete']);
        self::assertNull($result['projects'][1]['amounts']['actual_invested']);
    }

    public function testDifferentInvestorsAndUnknownInvestorAreVisibleButNeverAggregated(): void
    {
        foreach (['另一投资人', ''] as $investor) {
            $result = $this->service([$this->project(1), $this->project(2, ['investor_name' => $investor])])->overview(2, [80], 80, '2026-09');
            self::assertSame('blocked', $result['status']);
            self::assertCount(2, $result['projects']);
            self::assertNull($result['totals']);
            self::assertNull($result['recorded_totals']);
        }
    }

    public function testIncompatibleCutoffBasisAndCurrencyAreNotAggregated(): void
    {
        foreach ([['as_of' => '2026-10-02'], ['basis' => 'hotel_profit'], ['currency' => 'USD']] as $changes) {
            $result = $this->service([$this->project(1, [], $changes)])->overview(2, [80], 80, '2026-09');
            self::assertSame('blocked', $result['status']);
            self::assertNull($result['totals']);
            self::assertNull($result['recorded_totals']);
        }
    }

    public function testCrossTenantOrDuplicateProjectReaderDataFailsClosed(): void
    {
        foreach ([[$this->project(1, ['tenant_id' => 3])], [$this->project(), $this->project()]] as $projects) {
            $result = $this->service($projects)->overview(2, [80], 80, '2026-09');
            self::assertSame('read_failed', $result['status']);
            self::assertNull($result['projects']);
            self::assertNull($result['totals']);
        }
    }

    public function testOtherHotelDuplicatesAndInvalidIdsCannotClaimCompleteRead(): void
    {
        foreach ([[$this->project(7, ['hotel_id' => 81]), $this->project(7, ['hotel_id' => 81])],
            [$this->project(0, ['hotel_id' => 81])], [$this->project(7, ['hotel_id' => 81, 'id' => '7bad'])]] as $projects) {
            $result = $this->service($projects)->overview(2, [80, 81], 80, '2026-09');
            self::assertSame('read_failed', $result['status']);
            self::assertFalse($result['coverage']['read_complete']);
            self::assertNull($result['projects']);
            self::assertNull($result['totals']);
        }
        $calls = 0;
        $result = $this->service([], function () use (&$calls): array {
            $calls++;
            return ['list' => [$this->project(7, ['hotel_id' => 81])], 'pagination' => ['total' => 2]];
        })->overview(2, [80, 81], 80, '2026-09');
        self::assertSame(2, $calls);
        self::assertSame('read_failed', $result['status']);
    }

    public function testLargeCanonicalIdsWithinPhpIntegerRangeAreAccepted(): void
    {
        if (PHP_INT_SIZE < 8) self::markTestSkipped('IDs longer than ten digits require a 64-bit PHP runtime');
        foreach ([10000000000, '10000000000', PHP_INT_MAX, (string)PHP_INT_MAX] as $id) {
            $result = $this->service([$this->project(1, ['id' => $id])])->overview(2, [80], 80, '2026-09');
            self::assertSame('ready', $result['status']);
            self::assertTrue($result['coverage']['read_complete']);
            self::assertSame((int)$id, $result['projects'][0]['project_id']);
            self::assertSame('100.01', $result['totals']['actual_invested']);
        }
    }

    public function testOverflowDecimalAndBooleanProjectIdsFailBeforeHotelFiltering(): void
    {
        $overflow = PHP_INT_SIZE >= 8 ? '9223372036854775808' : '2147483648';
        foreach ([$overflow, $overflow . '0', '1.5', 1.5, 1.0, true, false, '-1', '1e3', '01', ' 1', null] as $id) {
            foreach ([80, 81] as $hotelId) {
                $result = $this->service([$this->project(1, ['id' => $id, 'hotel_id' => $hotelId])])->overview(2, [80, 81], 80, '2026-09');
                self::assertSame('read_failed', $result['status']);
                self::assertFalse($result['coverage']['read_complete']);
                self::assertNull($result['projects']);
                self::assertNull($result['totals']);
            }
        }
        $result = $this->service([$this->project(7), $this->project(7, ['id' => '7', 'hotel_id' => 81])])->overview(2, [80, 81], 80, '2026-09');
        self::assertSame('read_failed', $result['status']);
    }

    public function testHotelDeniedBeforeReading(): void
    {
        $calls = 0;
        $service = $this->service([], static function () use (&$calls): array { $calls++; return []; });
        try {
            $service->overview(2, [81], 80, '2026-09');
            self::fail('Expected scope denial');
        } catch (RuntimeException $error) {
            self::assertSame(403, $error->getCode());
            self::assertSame(0, $calls);
        }
    }

    public function testChangingPaginationTotalReportsPartialWithoutFillingMissingProjects(): void
    {
        $result = $this->service([], function (array $filters): array {
            return ['list' => [$this->project($filters['page'])], 'pagination' => ['total' => $filters['page'] === 1 ? 2 : 3]];
        })->overview(2, [80], 80, '2026-09');
        self::assertSame('partial', $result['status']);
        self::assertNull($result['totals']);
        self::assertCount(1, $result['projects']);
        self::assertContains('project_list_changed_during_read', $result['quality']['issues']);
    }

    public function testExistingCalculatorExcludesPlannedAndFutureCashBeforeBridgeAggregation(): void
    {
        $project = ['opening_as_of' => null, 'first_invested_on' => '2026-01-01', 'history_complete_through' => '2026-09-30', 'expected_monthly_amount' => null];
        $entry = static fn(string $kind, string $amount, string $date, bool $planned = false): array => [
            'id' => 1, 'kind' => $kind, 'amount' => $amount, 'date' => $date, 'precision' => 'day', 'is_planned' => $planned, 'voided_at' => null,
        ];
        $summary = (new Calculator())->summarize($project, [
            $entry('investment', '100.00', '2026-01-01'), $entry('recovery', '30.00', '2026-09-15'),
            $entry('recovery', '50.00', '2026-09-20', true), $entry('recovery', '60.00', '2026-10-01'),
        ], '2026-09-30');
        $result = $this->service([$this->project(1, ['summary' => $summary])])->overview(2, [80], 80, '2026-09');
        self::assertSame('30.00', $result['totals']['net_actual_recovered']);
        self::assertSame('70.00', $result['totals']['unrecovered']);
    }
}
