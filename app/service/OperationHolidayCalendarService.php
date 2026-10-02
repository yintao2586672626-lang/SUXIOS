<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;

final class OperationHolidayCalendarService
{
    private const PENDING = '待接入真实数据';
    private const SCHEDULE_SOURCE = 'https://www.gov.cn/zhengce/zhengceku/202511/content_7047091.htm';
    private const STATUTORY_SOURCE = 'https://www.gov.cn/zhengce/content/202411/content_6986380.htm';
    private const CONFIRMED_SCHEDULES = [
        2026 => [
            ['name' => '元旦', 'start_date' => '2026-01-01', 'end_date' => '2026-01-03'],
            ['name' => '春节', 'start_date' => '2026-02-15', 'end_date' => '2026-02-23'],
            ['name' => '清明节', 'start_date' => '2026-04-04', 'end_date' => '2026-04-06'],
            ['name' => '劳动节', 'start_date' => '2026-05-01', 'end_date' => '2026-05-05'],
            ['name' => '端午节', 'start_date' => '2026-06-19', 'end_date' => '2026-06-21'],
            ['name' => '中秋节', 'start_date' => '2026-09-25', 'end_date' => '2026-09-27'],
            ['name' => '国庆节', 'start_date' => '2026-10-01', 'end_date' => '2026-10-07'],
        ],
    ];

    public function build(string $date): array
    {
        $pending = [
            'next_holiday' => null,
            'days_left' => null,
            'suggestion' => '完整节假日及放假调休安排待核验',
            'data_status' => self::PENDING,
            'business_date' => $date,
            'calendar_scope' => 'partial',
            'schedule_status' => 'pending',
            'confirmed_start_date' => null,
            'confirmed_end_date' => null,
            'official_start_date' => null,
            'official_end_date' => null,
        ];
        $timezone = new DateTimeZone('Asia/Shanghai');
        $today = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone) : false;
        if (!$today || $today->format('Y-m-d') !== $date) {
            return array_replace($pending, [
                'reason' => 'invalid_business_date',
                'suggestion' => '业务日期无效，节假日安排待核验',
            ]);
        }
        $year = (int)$today->format('Y');
        foreach (self::CONFIRMED_SCHEDULES[$year] ?? [] as $holiday) {
            if ($holiday['end_date'] >= $date) {
                return $this->confirmedHoliday($holiday, $today, true);
            }
        }
        // The fixed Gregorian dates below are covered by Article 2 of the
        // 2024 amendment effective 2025-01-01. Lunar holidays and adjusted
        // annual leave ranges need a verified year-specific schedule.
        if ($year < 2025) {
            return array_replace($pending, ['reason' => 'historical_calendar_not_loaded']);
        }
        foreach ([$year, min(9999, $year + 1)] as $candidateYear) {
            foreach ([['元旦', '01-01', '01-01'], ['劳动节', '05-01', '05-02'], ['国庆节', '10-01', '10-03']] as [$name, $start, $end]) {
                $holiday = ['name' => $name, 'start_date' => "$candidateYear-$start", 'end_date' => "$candidateYear-$end"];
                if ($holiday['end_date'] < $date) continue;
                $known = $this->confirmedHoliday($holiday, $today, false);
                if ($known['days_left'] < 15) return $known;
                return array_replace($pending, [
                    'reason' => 'complete_calendar_not_loaded',
                    'known_next_holiday' => [
                        'name' => $name,
                        'confirmed_start_date' => $holiday['start_date'],
                        'confirmed_end_date' => $holiday['end_date'],
                        'days_left' => $known['days_left'],
                        'date_basis' => 'statutory_gregorian_dates',
                        'source_url' => self::STATUTORY_SOURCE,
                    ],
                    'suggestion' => "完整节假日及放假调休安排待核验；可确认的公历节日为{$name}，不代表完整日历中的最近节日",
                ]);
            }
        }
        return array_replace($pending, ['reason' => 'calendar_horizon_unavailable']);
    }

    private function confirmedHoliday(array $holiday, DateTimeImmutable $today, bool $official): array
    {
        $start = new DateTimeImmutable($holiday['start_date'], $today->getTimezone());
        $daysLeft = $today < $start ? (int)$today->diff($start)->format('%a') : 0;
        return [
            'next_holiday' => $holiday['name'],
            'days_left' => $daysLeft,
            'suggestion' => $official
                ? ($daysLeft < 15 ? '节假日临近，建议检查库存、价格和活动节奏' : '保持常规监控')
                : '公历法定节日临近，可检查库存、价格和活动节奏；完整放假调休安排待核验，不据此推断农历节日或延长假期',
            'data_status' => 'ok',
            'business_date' => $today->format('Y-m-d'),
            'calendar_year' => (int)$start->format('Y'),
            'calendar_scope' => $official ? 'complete' : 'partial',
            'date_basis' => $official ? 'official_adjusted_schedule' : 'statutory_gregorian_dates',
            'schedule_status' => $official ? 'verified' : 'pending',
            'confirmed_start_date' => $holiday['start_date'],
            'confirmed_end_date' => $holiday['end_date'],
            'official_start_date' => $official ? $holiday['start_date'] : null,
            'official_end_date' => $official ? $holiday['end_date'] : null,
            'source_url' => $official ? self::SCHEDULE_SOURCE : self::STATUTORY_SOURCE,
        ];
    }
}
