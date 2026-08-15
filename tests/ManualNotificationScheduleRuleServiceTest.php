<?php
declare(strict_types=1);

use app\service\ManualNotificationScheduleRuleService;
use PHPUnit\Framework\TestCase;

final class ManualNotificationScheduleRuleServiceTest extends TestCase
{
    public function testDailyRuleUsesConfiguredWeekdayWindowAndYesterdayBusinessDate(): void
    {
        $service = new ManualNotificationScheduleRuleService();
        $row = [
            'trigger_type' => 'daily_fixed_time',
            'planned_send_at' => '2026-07-01 09:15:00',
            'business_date_rule' => 'yesterday',
            'active_weekdays' => '3',
            'effective_from' => '2026-07-01',
            'effective_to' => '2026-08-31',
        ];
        $observedAt = new DateTimeImmutable('2026-07-29 09:15:30', new DateTimeZone('Asia/Shanghai'));

        self::assertSame('2026-07-29 09:15', $service->dueWindow($row, $observedAt));
        self::assertSame('2026-07-28', $service->resolveBusinessDate($row, $observedAt));
        self::assertSame(
            '2026-08-05 09:15:00',
            $service->nextRunAt($row, $observedAt)
        );
    }

    public function testHourlyRuleStaysInsideConfiguredBusinessHours(): void
    {
        $service = new ManualNotificationScheduleRuleService();
        $row = [
            'trigger_type' => 'hourly_on_the_hour',
            'active_weekdays' => '1,2,3,4,5,6,7',
            'hourly_start_time' => '09:00:00',
            'hourly_end_time' => '22:00:00',
        ];
        $timezone = new DateTimeZone('Asia/Shanghai');

        self::assertNull($service->dueWindow(
            $row,
            new DateTimeImmutable('2026-07-29 08:00:10', $timezone)
        ));
        self::assertSame('2026-07-29 09:00', $service->dueWindow(
            $row,
            new DateTimeImmutable('2026-07-29 09:00:10', $timezone)
        ));
        self::assertSame('2026-07-29 22:00', $service->dueWindow(
            $row,
            new DateTimeImmutable('2026-07-29 22:00:10', $timezone)
        ));
        self::assertNull($service->dueWindow(
            $row,
            new DateTimeImmutable('2026-07-29 23:00:10', $timezone)
        ));
        self::assertSame('2026-07-30 09:00:00', $service->nextRunAt(
            $row,
            new DateTimeImmutable('2026-07-29 22:05:00', $timezone)
        ));
    }

    public function testStrictThreeSourceMidnightUsesPreviousDayCloseout(): void
    {
        $service = new ManualNotificationScheduleRuleService();
        $row = [
            'hotel_id' => 80,
            'template_type' => 'operating_daily_report',
            'source_scope' => 'combined',
            'content_sections' =>
                'pms_summary,pms_efficiency,ctrip_traffic,meituan_traffic',
            'business_date_rule' => 'today',
            'send_method' => 'wecom_formal',
            'trigger_type' => 'hourly_on_the_hour',
            'hourly_start_time' => '00:00:00',
            'hourly_end_time' => '23:00:00',
            'active_weekdays' => '1,2,3,4,5,6,7',
            'test_robot_id' => 2,
            'test_robot_name' => '宿析OS云端日报',
        ];
        $timezone = new DateTimeZone('Asia/Shanghai');

        self::assertSame('2026-08-15 00:00', $service->dueWindow(
            $row,
            new DateTimeImmutable('2026-08-15 00:00:04', $timezone)
        ));
        self::assertSame('2026-08-14', $service->resolveBusinessDate(
            $row,
            new DateTimeImmutable('2026-08-15 00:00:04', $timezone)
        ));
        self::assertSame('2026-08-15', $service->resolveBusinessDate(
            $row,
            new DateTimeImmutable('2026-08-15 01:00:04', $timezone)
        ));
    }

    public function testExpiredScheduleHasNoNextRun(): void
    {
        $service = new ManualNotificationScheduleRuleService();
        $row = [
            'trigger_type' => 'daily_fixed_time',
            'planned_send_at' => '2026-07-01 09:15:00',
            'active_weekdays' => '1,2,3,4,5,6,7',
            'effective_to' => '2026-07-28',
        ];

        self::assertNull($service->nextRunAt(
            $row,
            new DateTimeImmutable('2026-07-29 08:00:00', new DateTimeZone('Asia/Shanghai'))
        ));
    }

    public function testEmptyOrInvalidWeekdaysNeverExpandToEveryDay(): void
    {
        $service = new ManualNotificationScheduleRuleService();
        $timezone = new DateTimeZone('Asia/Shanghai');
        $observedAt = new DateTimeImmutable(
            '2026-07-29 09:15:30',
            $timezone
        );
        $row = [
            'trigger_type' => 'daily_fixed_time',
            'planned_send_at' => '2026-07-01 09:15:00',
            'active_weekdays' => '',
        ];

        self::assertNull($service->dueWindow($row, $observedAt));
        self::assertNull($service->nextRunAt($row, $observedAt));

        $row['active_weekdays'] = '0,8,invalid';
        self::assertNull($service->dueWindow($row, $observedAt));
        self::assertNull($service->nextRunAt($row, $observedAt));
    }

    public function testUtcObservationUsesShanghaiWeekdayAndBusinessDate(): void
    {
        $service = new ManualNotificationScheduleRuleService();
        $row = [
            'trigger_type' => 'daily_fixed_time',
            'planned_send_at' => '2026-07-01 00:15:00',
            'business_date_rule' => 'yesterday',
            'active_weekdays' => '3',
        ];
        $observedAt = new DateTimeImmutable(
            '2026-07-28 16:15:30',
            new DateTimeZone('UTC')
        );

        self::assertSame(
            '2026-07-29 00:15',
            $service->dueWindow($row, $observedAt)
        );
        self::assertSame(
            '2026-07-28',
            $service->resolveBusinessDate($row, $observedAt)
        );
    }

    public function testMinuteIntervalUsesConfiguredStartMinuteAndSystemDayEnd(): void
    {
        $service = new ManualNotificationScheduleRuleService();
        $row = [
            'trigger_type' => 'interval_minutes',
            'interval_minutes' => 30,
            'active_weekdays' => '1,2,3,4,5,6,7',
            'hourly_start_time' => '09:15:00',
            'hourly_end_time' => '11:45:00',
        ];
        $timezone = new DateTimeZone('Asia/Shanghai');

        self::assertSame('2026-07-29 09:15', $service->dueWindow(
            $row,
            new DateTimeImmutable('2026-07-29 09:15:20', $timezone)
        ));
        self::assertSame('2026-07-29 10:45', $service->dueWindow(
            $row,
            new DateTimeImmutable('2026-07-29 10:46:00', $timezone)
        ));
        self::assertNull($service->dueWindow(
            $row,
            new DateTimeImmutable('2026-07-29 10:50:01', $timezone)
        ));
        self::assertSame('2026-07-29 11:15:00', $service->nextRunAt(
            $row,
            new DateTimeImmutable('2026-07-29 10:46:00', $timezone)
        ));
        self::assertSame('2026-07-29 12:15:00', $service->nextRunAt(
            $row,
            new DateTimeImmutable('2026-07-29 11:50:00', $timezone)
        ));
    }
}
