<?php
declare(strict_types=1);

namespace Tests;

use app\service\InvestmentPaybackCalculator as Calculator;
use app\service\InvestmentPaybackService as Service;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class InvestmentPaybackCalculatorTest extends TestCase
{
    public function testCoreAndAdditionalInvestmentUseInvestorCashOnly(): void
    {
        $project = $this->project(['expected_monthly_amount' => '150000.00']);
        $entries = [$this->entry('investment', '3000000.00', '2026-01-01'), $this->entry('recovery', '600000.00', '2026-02-01')];
        $summary = (new Calculator())->summarize($project, $entries);
        self::assertSame('2400000.00', $summary['unrecovered_amount']);
        self::assertSame(20.0, $summary['recovery_percent']);
        self::assertSame(16, $summary['forecast']['whole_months']);
        self::assertSame('ready', $summary['forecast']['status']);
        self::assertNull($summary['first_payback']['date']);
        $entries[] = $this->entry('investment', '300000.00', '2026-03-01');
        $next = (new Calculator())->summarize($project, $entries);
        self::assertSame('3300000.00', $next['invested_amount']);
        self::assertSame(18.18, $next['recovery_percent']);
        self::assertSame(18, $next['forecast']['whole_months']);
    }

    public function testSmallPositiveRecoveryDurationKeepsItsExactValueAndKnownRecoveryStaysSeparate(): void
    {
        $calculator = new Calculator();
        $project = $this->project(['expected_monthly_amount' => '30000.00']);
        $entries = [$this->entry('investment', '500.00', '2026-01-01')];
        $forecast = $calculator->summarize($project, $entries)['forecast'];
        self::assertSame('ready', $forecast['status']);
        self::assertSame(0.0, $forecast['remaining_months']); // Preserve the legacy rounded response.
        self::assertEqualsWithDelta(1 / 60, $forecast['remaining_months_exact'], 1e-15);
        self::assertGreaterThan(0, $forecast['remaining_months_exact']);
        self::assertSame(1, $forecast['whole_months']);
        $entries[] = $this->entry('recovery', '500.00', '2026-02-01');
        $recovered = $calculator->summarize($project, $entries)['forecast'];
        self::assertSame('already_recovered', $recovered['status']);
        self::assertNull($recovered['remaining_months_exact']);
        foreach ([null, '0.00', '-1.00'] as $monthly) {
            $result = $calculator->summarize($this->project(['expected_monthly_amount' => $monthly]), [$entries[0]]);
            self::assertNull($result['forecast']['remaining_months_exact']);
        }
    }

    public function testFractionalPredictionAndCalendarMonthEnd(): void
    {
        $summary = (new Calculator())->summarize($this->project(['forecast_as_of' => '2026-01-31', 'expected_monthly_amount' => '100000.00']), [
            $this->entry('investment', '1000000.00', '2026-01-01'), $this->entry('recovery', '650000.00', '2026-01-02'),
        ]);
        self::assertSame(3.5, $summary['forecast']['remaining_months']);
        self::assertSame(4, $summary['forecast']['whole_months']);
        self::assertSame('2026-05', $summary['forecast']['payback_month']);
        $summary = (new Calculator())->summarize($this->project(['forecast_as_of' => '2026-01-31']), [$this->entry('investment', '100000.00', '2026-01-01')]);
        self::assertSame('2026-02', $summary['forecast']['payback_month']);
    }

    public function testFirstPaybackAndLaterInvestmentPreserveHistoricalEvent(): void
    {
        $entries = [$this->entry('investment', '1000000.00', '2026-01-01')];
        foreach (['02', '03', '04', '05', '06'] as $month) {
            $entries[] = $this->entry('recovery', '200000.00', '2026-' . $month . '-01');
        }
        $calculator = new Calculator();
        $summary = $calculator->summarize($this->project(), $entries);
        self::assertSame('confirmed', $summary['first_payback']['status']);
        self::assertSame('2026-06-01', $summary['first_payback']['date']);
        self::assertSame(5, $summary['first_payback']['elapsed_calendar_months']);
        self::assertSame(151, $summary['first_payback']['elapsed_days']);
        $entries[] = $this->entry('investment', '200000.00', '2026-07-01');
        $next = $calculator->summarize($this->project(), $entries);
        self::assertSame('2026-06-01', $next['first_payback']['date']);
        self::assertSame('reopened', $next['state']);
        self::assertSame(2, $next['forecast']['whole_months']);
        self::assertSame('1000000.00', $calculator->summarize($this->project(), $entries, '2026-06-30')['invested_amount']);
    }

    public function testSameDayNettingCannotInventPaybackFromRowOrder(): void
    {
        $entries = [$this->entry('investment', '100.00', '2026-01-01'), $this->entry('recovery', '100.00', '2026-02-01'), $this->entry('investment', '20.00', '2026-02-01')];
        foreach ([$entries, array_reverse($entries)] as $rows) {
            $summary = (new Calculator())->summarize($this->project(), $rows);
            self::assertSame('not_reached', $summary['first_payback']['status']);
            self::assertNull($summary['first_payback']['date']);
        }
    }

    public function testLaterMonthlyInvestmentCannotDowngradeAnEarlierExactFirstPayback(): void
    {
        $entries = [$this->entry('investment', '100.00', '2026-01-01'), $this->entry('recovery', '100.00', '2026-06-01')];
        $before = (new Calculator())->summarize($this->project(), $entries);
        $entries[] = $this->entry('investment', '20.00', '2026-08', 'month');
        $after = (new Calculator())->summarize($this->project(), $entries);
        self::assertSame($before['first_payback'], $after['first_payback']);
        self::assertSame('2026-06-01', $after['first_payback']['date']);
        self::assertSame('day', $after['first_payback']['precision']);
        self::assertSame('reopened', $after['state']);
    }

    public function testMonthPrecisionAndMixedRecordsNeverClaimExactPaybackDay(): void
    {
        $entries = [$this->entry('investment', '100.00', '2026-01-01'), $this->entry('recovery', '100.00', '2026-02', 'month')];
        $summary = (new Calculator())->summarize($this->project(), $entries);
        self::assertSame('2026-02', $summary['first_payback']['date']);
        self::assertSame('month', $summary['first_payback']['precision']);
        self::assertNull($summary['first_payback']['elapsed_days']);
        self::assertSame(1, $summary['first_payback']['elapsed_calendar_months']);
        $partial = (new Calculator())->summarize($this->project(), $entries, '2026-02-15');
        self::assertSame('0.00', $partial['net_recovered_amount']);
        self::assertFalse($partial['data_quality']['history_complete']);
        self::assertContains('monthly_record_extends_beyond_cutoff', $partial['data_quality']['issues']);
    }

    public function testRefundAndFenArithmeticAreExact(): void
    {
        $summary = (new Calculator())->summarize($this->project(), [$this->entry('investment', '1000000.00', '2026-01-01'), $this->entry('recovery', '600000.00', '2026-02-01'), $this->entry('refund', '100000.00', '2026-03-01')]);
        self::assertSame('500000.00', $summary['net_recovered_amount']);
        self::assertSame(5, $summary['forecast']['whole_months']);
        $small = (new Calculator())->summarize($this->project(), [$this->entry('investment', '0.30', '2026-01-01'), $this->entry('recovery', '0.10', '2026-02-01'), $this->entry('recovery', '0.20', '2026-03-01')]);
        self::assertSame('0.30', $small['net_recovered_amount']);
        self::assertSame('0.00', $small['unrecovered_amount']);
    }

    public function testOpeningBalanceAndLaterIncrementDoNotInventFirstHistoricalDate(): void
    {
        $project = $this->project(['opening_as_of' => '2026-08-31', 'opening_invested' => '1000000.00', 'opening_recovered' => '1200000.00']);
        $summary = (new Calculator())->summarize($project, []);
        self::assertSame('opening_already_recovered', $summary['first_payback']['status']);
        self::assertNull($summary['first_payback']['date']);
        self::assertSame('200000.00', $summary['excess_recovered_amount']);
        self::assertSame(120.0, $summary['recovery_percent']);
        self::assertSame('already_recovered', $summary['forecast']['status']);
        $unchecked = (new Calculator())->summarize(array_merge($project, ['history_complete_through' => null]), []);
        self::assertSame('recorded_only', $unchecked['first_payback']['status']);
        self::assertNull($unchecked['first_payback']['date']);
        $entries = [$this->entry('investment', '500000.00', '2026-09-01')];
        $next = (new Calculator())->summarize($project, $entries);
        self::assertSame('1500000.00', $next['invested_amount']);
        self::assertSame('reopened', $next['state']);
        $before = (new Calculator())->summarize($project, $entries, '2026-08-01');
        self::assertNull($before['invested_amount']);
        self::assertContains('cutoff_before_opening_balance', $before['data_quality']['issues']);
    }

    public function testUnconfirmedHistoryOnlyProducesTrialAndNoExactDuration(): void
    {
        $summary = (new Calculator())->summarize($this->project(['history_complete_through' => null]), [$this->entry('investment', '100.00', '2026-01-01'), $this->entry('recovery', '100.00', '2026-02-01')]);
        self::assertSame('recorded_only', $summary['state']);
        self::assertSame('recorded_only', $summary['first_payback']['status']);
        self::assertNull($summary['first_payback']['elapsed_days']);
        self::assertSame('trial_recovered', $summary['forecast']['status']);
        $summary = (new Calculator())->summarize($this->project(['history_complete_through' => null]), [$this->entry('investment', '100.00', '2026-01-01')]);
        self::assertSame('trial', $summary['forecast']['status']);
    }

    public function testMonthOnlyInvestmentRetainsMonthAndOpeningDoesNotInventStartFromLaterInvestment(): void
    {
        $summary = (new Calculator())->summarize($this->project(), [$this->entry('investment', '100.00', '2026-01', 'month')]);
        self::assertSame('2026-01', $summary['first_invested_on']);
        self::assertSame('month', $summary['first_invested_precision']);
        $declared = (new Calculator())->summarize($this->project(['first_invested_on' => '2026-01-20']), [$this->entry('investment', '100.00', '2026-01', 'month')]);
        self::assertSame('2026-01-20', $declared['first_invested_on']);
        $opening = (new Calculator())->summarize($this->project(['opening_as_of' => '2026-08-31', 'opening_invested' => '100.00', 'opening_recovered' => '0.00']), [$this->entry('investment', '20.00', '2026-09-01')]);
        self::assertNull($opening['first_invested_on']);
        self::assertNull($opening['forecast']['full_cycle_months']);
    }

    public function testAutomaticFirstInvestmentKeepsOverlappingMonthPrecisionRegardlessOfRowOrder(): void
    {
        $entries = [
            $this->entry('investment', '100.00', '2026-01-20'),
            $this->entry('investment', '10.00', '2026-01', 'month'),
            $this->entry('recovery', '50.00', '2026-02-01'),
        ];
        $calculator = new Calculator();
        foreach ([$entries, array_reverse($entries)] as $rows) {
            $summary = $calculator->summarize($this->project(['expected_monthly_amount' => '100.00']), $rows);
            self::assertSame('2026-01', $summary['first_invested_on']);
            self::assertSame('month', $summary['first_invested_precision']);
            self::assertSame(8.6, $summary['forecast']['full_cycle_months']);
        }
        $declared = $calculator->summarize($this->project(['first_invested_on' => '2026-01-20']), $entries);
        self::assertSame('2026-01-20', $declared['first_invested_on']);
        self::assertSame('day', $declared['first_invested_precision']);
        // A shared month start also keeps one deterministic conservative precision.
        $entries[0]['date'] = '2026-01-01';
        foreach ([$entries, array_reverse($entries)] as $rows) {
            self::assertSame('month', $calculator->summarize($this->project(), $rows)['first_invested_precision']);
        }
    }

    public function testDeclaredFirstInvestmentKeepsEarlierNonOverlappingRecordCompatibility(): void
    {
        foreach ([['2026-01-01', 'day', 31], ['2025-12', 'month', null]] as [$date, $precision, $elapsedDays]) {
            $entries = [
                $this->entry('investment', '100.00', $date, $precision),
                $this->entry('recovery', '100.00', '2026-02-01'),
            ];
            foreach ([$entries, array_reverse($entries)] as $rows) {
                $summary = (new Calculator())->summarize($this->project(['first_invested_on' => '2026-01-20']), $rows);
                self::assertSame($date, $summary['first_invested_on']);
                self::assertSame($precision, $summary['first_invested_precision']);
                self::assertSame($elapsedDays, $summary['first_payback']['elapsed_days']);
            }
        }
        $overlapping = (new Calculator())->summarize($this->project(['first_invested_on' => '2026-01-20']), [
            $this->entry('investment', '100.00', '2026-01', 'month'),
        ]);
        self::assertSame('2026-01-20', $overlapping['first_invested_on']);
        self::assertSame('day', $overlapping['first_invested_precision']);
    }

    public function testConfirmedMonthlyZeroReceiptDoesNotDowngradeExactFirstPayback(): void
    {
        $calculator = new Calculator();
        $entries = [$this->entry('investment', '100.00', '2026-01-01'), $this->entry('recovery', '100.00', '2026-06-01')];
        $before = $calculator->summarize($this->project(), $entries);
        $zero = $this->entry('recovery', '0.00', '2026-02', 'month');
        $zero['confirmed_zero'] = true;
        $entries[] = $zero;
        foreach ([$entries, array_reverse($entries)] as $rows) {
            $summary = $calculator->summarize($this->project(), $rows);
            self::assertSame($before['first_payback'], $summary['first_payback']);
            self::assertSame('2026-06-01', $summary['first_payback']['date']);
            self::assertSame(151, $summary['first_payback']['elapsed_days']);
        }
        $partial = $calculator->summarize($this->project(), $entries, '2026-02-15');
        self::assertContains('monthly_record_extends_beyond_cutoff', $partial['data_quality']['issues']);
        self::assertFalse($partial['data_quality']['history_complete']);
        $laterMonthly = $entries;
        $laterMonthly[] = $this->entry('investment', '20.00', '2026-08', 'month');
        self::assertSame($before['first_payback'], $calculator->summarize($this->project(), $laterMonthly)['first_payback']);
        // A nonzero month still carries unknown intra-month timing.
        $entries[2]['amount'] = '1.00';
        $nonzero = $calculator->summarize($this->project(), $entries);
        self::assertSame('2026-06', $nonzero['first_payback']['date']);
        self::assertSame('month', $nonzero['first_payback']['precision']);
        self::assertNull($nonzero['first_payback']['elapsed_days']);
    }

    public function testMissingZeroNegativeAndNoInvestmentDoNotBecomeZeroForecast(): void
    {
        foreach ([[null, 'missing_monthly'], ['0.00', 'non_positive'], ['-1.00', 'non_positive']] as [$amount, $status]) {
            $summary = (new Calculator())->summarize($this->project(['expected_monthly_amount' => $amount]), [$this->entry('investment', '100.00', '2026-01-01')]);
            self::assertSame($status, $summary['forecast']['status']);
            self::assertNull($summary['forecast']['remaining_months']);
        }
        $draft = (new Calculator())->summarize($this->project(), []);
        self::assertNull($draft['invested_amount']);
        self::assertNull($draft['recovery_percent']);
        self::assertSame('missing_investment', $draft['forecast']['status']);
    }

    public function testPlansFutureAndVoidsStayOutOfActuals(): void
    {
        $planned = $this->entry('investment', '999.00', '2027-01-01');
        $planned['is_planned'] = true;
        $voided = $this->entry('recovery', '100.00', '2026-02-01');
        $voided['voided_at'] = '2026-02-02 00:00:00';
        $summary = (new Calculator())->summarize($this->project(), [$this->entry('investment', '100.00', '2026-01-01'), $planned, $voided]);
        self::assertSame('100.00', $summary['invested_amount']);
        self::assertSame('0.00', $summary['net_recovered_amount']);
    }

    public function testZeroRecoveryIsExplicitAndOpeningOverlapIsNotSummed(): void
    {
        $zero = Service::normalizeEntry(['kind' => 'recovery', 'amount' => '0', 'date' => '2026-06', 'precision' => 'month', 'confirmed_zero' => true]);
        self::assertSame('0.00', $zero['amount']);
        self::assertSame(1, $zero['confirmed_zero']);
        $summary = (new Calculator())->summarize($this->project(['opening_as_of' => '2026-08-31', 'opening_invested' => '100.00', 'opening_recovered' => '0.00']), [$this->entry('investment', '100.00', '2026-08-01')]);
        self::assertSame('100.00', $summary['invested_amount']);
        self::assertContains('entry_overlaps_opening_balance', $summary['data_quality']['issues']);
    }

    public function testFutureActualIsRejectedAndPlanRetainsItsDate(): void
    {
        $future = ['kind' => 'investment', 'amount' => '1', 'date' => '2099-01-01'];
        $plan = Service::normalizeEntry($future + ['is_planned' => true]);
        self::assertSame('2099-01-01', $plan['business_date']);
        self::assertSame(1, $plan['is_planned']);
        $this->expectException(InvalidArgumentException::class);
        Service::normalizeEntry($future);
    }

    public function testInvalidMoneyAndDateNeverSilentlyRoundOrCoerce(): void
    {
        foreach (['0.001', '1e6', '3万元', '-1', '', [], '1000000000000'] as $money) {
            try {
                Calculator::fen($money);
                self::fail('Invalid money must fail');
            } catch (InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
        $this->expectException(InvalidArgumentException::class);
        Calculator::date('2026-02-30');
    }

    private function project(array $changes = []): array
    {
        return array_merge(['forecast_as_of' => '2026-09-30', 'history_complete_through' => '2026-09-30', 'opening_as_of' => null, 'opening_invested' => null, 'opening_recovered' => null, 'first_invested_on' => null, 'expected_monthly_amount' => '100000.00', 'expected_source' => '验收假设'], $changes);
    }

    private function entry(string $kind, string $amount, string $date, string $precision = 'day'): array
    {
        return ['kind' => $kind, 'amount' => $amount, 'date' => $date, 'precision' => $precision, 'is_planned' => false, 'voided_at' => null];
    }
}
