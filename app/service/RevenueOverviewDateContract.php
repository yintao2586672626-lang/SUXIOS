<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class RevenueOverviewDateContract
{
    public const VERSION = 'revenue_overview_as_of_date.v1';
    private const TIMEZONE = 'Asia/Shanghai';

    public static function businessDate(mixed $value): string
    {
        $text = trim((string)($value ?? ''));
        if ($text === '') {
            return (new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE)))
                ->modify('-1 day')->format('Y-m-d');
        }
        if (!self::isExactDate($text)) {
            throw new RuntimeException('Invalid business_date, expected YYYY-MM-DD', 422);
        }
        return $text;
    }

    public static function asOfDate(mixed $value = null): string
    {
        $text = trim((string)($value ?? ''));
        if ($text === '') {
            return self::serverAsOfDate();
        }
        if (!self::isExactDate($text)) {
            throw new RuntimeException('Invalid as_of_date, expected a real YYYY-MM-DD date', 422);
        }
        return $text;
    }

    public static function serverAsOfDate(?DateTimeImmutable $now = null): string
    {
        $timezone = new DateTimeZone(self::TIMEZONE);
        $clock = $now ?? new DateTimeImmutable('now', $timezone);
        return $clock->setTimezone($timezone)->format('Y-m-d');
    }

    public static function isCurrentAsOfDate(mixed $value, mixed $contractVersion): bool
    {
        $text = trim((string)($value ?? ''));
        return trim((string)($contractVersion ?? '')) === self::VERSION
            && self::isExactDate($text)
            && hash_equals(self::serverAsOfDate(), $text);
    }

    private static function isExactDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(self::TIMEZONE));
        $errors = DateTimeImmutable::getLastErrors();
        return $date !== false
            && ($errors === false || ((int)$errors['warning_count'] === 0 && (int)$errors['error_count'] === 0))
            && $date->format('Y-m-d') === $value;
    }
}
