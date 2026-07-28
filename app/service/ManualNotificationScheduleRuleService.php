<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;

final class ManualNotificationScheduleRuleService
{
    public const TIMEZONE = 'Asia/Shanghai';
    public const DEFAULT_WEEKDAYS = '1,2,3,4,5,6,7';
    public const DEFAULT_HOURLY_START = '09:00:00';
    public const DEFAULT_HOURLY_END = '22:00:00';

    /** @param array<string, mixed> $row */
    public function resolveBusinessDate(array $row, DateTimeImmutable $observedAt): string
    {
        $now = $observedAt->setTimezone(new DateTimeZone(self::TIMEZONE));
        return (string)($row['business_date_rule'] ?? 'today') === 'yesterday'
            ? $now->modify('-1 day')->format('Y-m-d')
            : $now->format('Y-m-d');
    }

    /** @param array<string, mixed> $row */
    public function dueWindow(
        array $row,
        DateTimeImmutable $observedAt,
        int $graceSeconds = 300
    ): ?string {
        $now = $observedAt->setTimezone(new DateTimeZone(self::TIMEZONE));
        if (!$this->dateIsActive($row, $now)) {
            return null;
        }

        $triggerType = (string)($row['trigger_type'] ?? '');
        if ($triggerType === 'hourly_on_the_hour') {
            if (!$this->hourIsActive($row, $now)) {
                return null;
            }
            $scheduled = $now->setTime((int)$now->format('H'), 0, 0);
        } elseif ($triggerType === 'interval_minutes') {
            $scheduled = $this->intervalScheduledAt($row, $now);
            if ($scheduled === null) {
                return null;
            }
        } elseif ($triggerType === 'daily_fixed_time') {
            $plannedTime = $this->timeValue($row['planned_send_at'] ?? null, '');
            if ($plannedTime === '') {
                return null;
            }
            [$hour, $minute] = array_map('intval', explode(':', $plannedTime));
            $scheduled = $now->setTime($hour, $minute, 0);
        } else {
            return null;
        }

        $delta = $now->getTimestamp() - $scheduled->getTimestamp();
        if ($delta < 0 || $delta >= max(1, $graceSeconds)) {
            return null;
        }
        return $scheduled->format('Y-m-d H:i');
    }

    /** @param array<string, mixed> $row */
    public function nextRunAt(
        array $row,
        DateTimeImmutable $observedAt,
        int $searchDays = 370
    ): ?string {
        $now = $observedAt->setTimezone(new DateTimeZone(self::TIMEZONE));
        $triggerType = (string)($row['trigger_type'] ?? '');
        if (!in_array(
            $triggerType,
            ['daily_fixed_time', 'hourly_on_the_hour', 'interval_minutes'],
            true
        )) {
            return null;
        }

        for ($offset = 0; $offset <= max(1, $searchDays); $offset++) {
            $day = $now->modify('+' . $offset . ' day')->setTime(0, 0, 0);
            if (!$this->dateIsActive($row, $day)) {
                continue;
            }

            if ($triggerType === 'daily_fixed_time') {
                $plannedTime = $this->timeValue($row['planned_send_at'] ?? null, '');
                if ($plannedTime === '') {
                    return null;
                }
                [$hour, $minute] = array_map('intval', explode(':', $plannedTime));
                $candidate = $day->setTime($hour, $minute, 0);
                if ($candidate > $now) {
                    return $candidate->format('Y-m-d H:i:s');
                }
                continue;
            }

            $start = $this->timeValue(
                $row['hourly_start_time'] ?? null,
                self::DEFAULT_HOURLY_START
            );
            $end = $this->timeValue(
                $row['hourly_end_time'] ?? null,
                self::DEFAULT_HOURLY_END
            );
            if ($triggerType === 'interval_minutes') {
                $intervalMinutes = $this->intervalMinutes($row);
                if ($intervalMinutes === null || $start >= $end) {
                    return null;
                }
                [$startHour, $startMinute] = array_map('intval', explode(':', $start));
                [$endHour, $endMinute] = array_map('intval', explode(':', $end));
                $first = $day->setTime($startHour, $startMinute, 0);
                $last = $day->setTime($endHour, $endMinute, 0);
                if ($offset === 0 && $first <= $now) {
                    $elapsedMinutes = (int)floor(
                        ($now->getTimestamp() - $first->getTimestamp()) / 60
                    );
                    $steps = intdiv(max(0, $elapsedMinutes), $intervalMinutes) + 1;
                    $candidate = $first->modify('+' . ($steps * $intervalMinutes) . ' minutes');
                } else {
                    $candidate = $first;
                }
                if ($candidate <= $last && $candidate > $now) {
                    return $candidate->format('Y-m-d H:i:s');
                }
                continue;
            }
            [$startHour] = array_map('intval', explode(':', $start));
            [$endHour] = array_map('intval', explode(':', $end));
            for ($hour = $startHour; $hour <= $endHour; $hour++) {
                $candidate = $day->setTime($hour, 0, 0);
                if ($candidate > $now) {
                    return $candidate->format('Y-m-d H:i:s');
                }
            }
        }
        return null;
    }

    /** @param array<string, mixed> $row */
    private function intervalScheduledAt(
        array $row,
        DateTimeImmutable $now
    ): ?DateTimeImmutable {
        $intervalMinutes = $this->intervalMinutes($row);
        if ($intervalMinutes === null) {
            return null;
        }
        $start = $this->timeValue(
            $row['hourly_start_time'] ?? null,
            self::DEFAULT_HOURLY_START
        );
        $end = $this->timeValue(
            $row['hourly_end_time'] ?? null,
            self::DEFAULT_HOURLY_END
        );
        if ($start >= $end) {
            return null;
        }
        [$startHour, $startMinute] = array_map('intval', explode(':', $start));
        [$endHour, $endMinute] = array_map('intval', explode(':', $end));
        $first = $now->setTime($startHour, $startMinute, 0);
        $last = $now->setTime($endHour, $endMinute, 0);
        if ($now < $first || $now > $last) {
            return null;
        }
        $elapsedMinutes = (int)floor(
            ($now->getTimestamp() - $first->getTimestamp()) / 60
        );
        $scheduled = $first->modify(
            '+' . (intdiv($elapsedMinutes, $intervalMinutes) * $intervalMinutes) . ' minutes'
        );
        return $scheduled <= $last ? $scheduled : null;
    }

    /** @param array<string, mixed> $row */
    private function intervalMinutes(array $row): ?int
    {
        $minutes = filter_var(
            $row['interval_minutes'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 5, 'max_range' => 1440]]
        );
        return $minutes === false ? null : $minutes;
    }

    /** @param array<string, mixed> $row */
    private function dateIsActive(array $row, DateTimeImmutable $date): bool
    {
        $day = $date->format('Y-m-d');
        $effectiveFrom = trim((string)($row['effective_from'] ?? ''));
        $effectiveTo = trim((string)($row['effective_to'] ?? ''));
        if (($effectiveFrom !== '' && $day < $effectiveFrom)
            || ($effectiveTo !== '' && $day > $effectiveTo)
        ) {
            return false;
        }

        $weekdays = $this->weekdays($row['active_weekdays'] ?? self::DEFAULT_WEEKDAYS);
        return in_array((int)$date->format('N'), $weekdays, true);
    }

    /** @param array<string, mixed> $row */
    private function hourIsActive(array $row, DateTimeImmutable $date): bool
    {
        $start = $this->timeValue(
            $row['hourly_start_time'] ?? null,
            self::DEFAULT_HOURLY_START
        );
        $end = $this->timeValue(
            $row['hourly_end_time'] ?? null,
            self::DEFAULT_HOURLY_END
        );
        $current = $date->format('H') . ':00:00';
        return $current >= $start && $current <= $end;
    }

    /** @return list<int> */
    private function weekdays(mixed $value): array
    {
        $parts = is_array($value) ? $value : explode(',', (string)$value);
        $weekdays = [];
        foreach ($parts as $part) {
            $weekday = (int)$part;
            if ($weekday >= 1 && $weekday <= 7) {
                $weekdays[$weekday] = $weekday;
            }
        }
        ksort($weekdays);
        return $weekdays === [] ? range(1, 7) : array_values($weekdays);
    }

    private function timeValue(mixed $value, string $fallback): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return $fallback;
        }
        if (preg_match('/(\d{2}):(\d{2})(?::\d{2})?$/', $value, $matches) !== 1) {
            return $fallback;
        }
        return $matches[1] . ':' . $matches[2] . ':00';
    }
}
