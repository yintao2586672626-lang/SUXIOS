<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperationHolidayCalendarService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OperationHolidayCalendarServiceTest extends TestCase
{
    #[DataProvider('confirmed2026Schedules')]
    public function testPreservesPublished2026HolidayRanges(string $date, string $name, string $start, string $end): void
    {
        $result = (new OperationHolidayCalendarService())->build($date);
        self::assertSame('ok', $result['data_status']);
        self::assertSame($name, $result['next_holiday']);
        self::assertSame(0, $result['days_left']);
        self::assertSame($start, $result['official_start_date']);
        self::assertSame($end, $result['official_end_date']);
        self::assertSame('complete', $result['calendar_scope']);
        self::assertSame('verified', $result['schedule_status']);
        self::assertSame('official_adjusted_schedule', $result['date_basis']);
    }

    public static function confirmed2026Schedules(): array
    {
        return [
            ['2026-01-02', '元旦', '2026-01-01', '2026-01-03'],
            ['2026-02-22', '春节', '2026-02-15', '2026-02-23'],
            ['2026-04-06', '清明节', '2026-04-04', '2026-04-06'],
            ['2026-05-05', '劳动节', '2026-05-01', '2026-05-05'],
            ['2026-06-21', '端午节', '2026-06-19', '2026-06-21'],
            ['2026-09-27', '中秋节', '2026-09-25', '2026-09-27'],
            ['2026-10-07', '国庆节', '2026-10-01', '2026-10-07'],
        ];
    }

    #[DataProvider('fixedGregorianWindows')]
    public function testConfirmsOnlyStatutoryDatesWhileAnnualAdjustedScheduleRemainsPending(string $date, string $name, string $start, string $end, int $days): void
    {
        $result = (new OperationHolidayCalendarService())->build($date);
        self::assertSame('ok', $result['data_status']);
        self::assertSame($name, $result['next_holiday']);
        self::assertSame($days, $result['days_left']);
        self::assertSame($start, $result['confirmed_start_date']);
        self::assertSame($end, $result['confirmed_end_date']);
        self::assertSame('partial', $result['calendar_scope']);
        self::assertSame('pending', $result['schedule_status']);
        self::assertSame('statutory_gregorian_dates', $result['date_basis']);
        self::assertNull($result['official_start_date']);
        self::assertNull($result['official_end_date']);
        self::assertStringContainsString('完整放假调休安排待核验', $result['suggestion']);
        self::assertSame((int)substr($start, 0, 4), $result['calendar_year']);
    }

    public static function fixedGregorianWindows(): array
    {
        return [
            ['2027-01-01', '元旦', '2027-01-01', '2027-01-01', 0],
            ['2027-04-25', '劳动节', '2027-05-01', '2027-05-02', 6],
            ['2027-04-17', '劳动节', '2027-05-01', '2027-05-02', 14],
            ['2027-05-02', '劳动节', '2027-05-01', '2027-05-02', 0],
            ['2027-09-25', '国庆节', '2027-10-01', '2027-10-03', 6],
            ['2027-10-03', '国庆节', '2027-10-01', '2027-10-03', 0],
            ['2026-12-28', '元旦', '2027-01-01', '2027-01-01', 4],
            ['2028-12-25', '元旦', '2029-01-01', '2029-01-01', 7],
            ['2030-04-20', '劳动节', '2030-05-01', '2030-05-02', 11],
            ['9999-10-03', '国庆节', '9999-10-01', '9999-10-03', 0],
        ];
    }

    #[DataProvider('unknownScheduleDates')]
    public function testUnknownLunarDatesAndAdjustedDaysDoNotBecomeHolidayFacts(string $date): void
    {
        $result = (new OperationHolidayCalendarService())->build($date);
        self::assertSame('待接入真实数据', $result['data_status']);
        self::assertNull($result['next_holiday']);
        self::assertNull($result['days_left']);
        self::assertNull($result['official_start_date']);
        self::assertNull($result['official_end_date']);
        self::assertSame('pending', $result['schedule_status']);
    }

    public static function unknownScheduleDates(): array
    {
        return [
            ['2027-02-05'], ['2027-04-05'], ['2027-06-09'], ['2027-09-10'],
            ['2027-05-03'], ['2027-10-04'], ['2027-04-16'], ['2024-05-01'],
        ];
    }

    public function testDistantKnownGregorianDateDoesNotMasqueradeAsNearestHoliday(): void
    {
        $result = (new OperationHolidayCalendarService())->build('2027-01-02');
        self::assertSame('待接入真实数据', $result['data_status']);
        self::assertNull($result['next_holiday']);
        self::assertNull($result['days_left']);
        self::assertSame('劳动节', $result['known_next_holiday']['name']);
        self::assertSame('2027-05-01', $result['known_next_holiday']['confirmed_start_date']);
        self::assertSame(119, $result['known_next_holiday']['days_left']);
        self::assertStringContainsString('不代表完整日历中的最近节日', $result['suggestion']);
    }

    #[DataProvider('invalidDates')]
    public function testInvalidDateDoesNotBorrowTodayOrNormalizeAnImpossibleDate(string $date): void
    {
        $result = (new OperationHolidayCalendarService())->build($date);
        self::assertSame('invalid_business_date', $result['reason']);
        self::assertSame('待接入真实数据', $result['data_status']);
        self::assertNull($result['next_holiday']);
        self::assertNull($result['days_left']);
        self::assertNull($result['confirmed_start_date']);
        self::assertNull($result['confirmed_end_date']);
        self::assertSame($date, $result['business_date']);
    }

    public static function invalidDates(): array
    {
        return [['2027-02-29'], ['2026-02-30'], ['not-a-date'], [''], ['2027-1-01'], ['2027-01-01T00:00'], ["2027-01-01\n"], ["\0"]];
    }

    public function testFourDigitYearHorizonDoesNotFabricateYear10000(): void
    {
        $result = (new OperationHolidayCalendarService())->build('9999-12-31');
        self::assertSame('待接入真实数据', $result['data_status']);
        self::assertSame('calendar_horizon_unavailable', $result['reason']);
        self::assertNull($result['next_holiday']);
        self::assertNull($result['days_left']);
    }
}
