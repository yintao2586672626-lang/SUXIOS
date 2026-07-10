<?php
declare(strict_types=1);

namespace app\service;

/** Shared boundary rules for OTA facts; missing is never coerced to zero. */
final class OtaFactSemantics
{
    public static function channel(string $value): string
    {
        $channel = strtolower(trim($value));
        return match ($channel) {
            'ctrip' => 'ctrip',
            'meituan' => 'meituan',
            default => throw new \InvalidArgumentException('unsupported OTA channel'),
        };
    }

    public static function nullableNumber(array $row, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') {
                continue;
            }
            $value = is_string($row[$key]) ? str_replace([',', '%', ' '], '', trim($row[$key])) : $row[$key];
            if (is_numeric($value)) {
                return (float) $value;
            }
        }
        return null;
    }

    public static function factKey(array $fact): string
    {
        $parts = [
            (string)($fact['system_hotel_id'] ?? ''),
            self::channel((string)($fact['channel'] ?? $fact['platform'] ?? '')),
            (string)($fact['data_date'] ?? ''),
            (string)($fact['metric_key'] ?? $fact['dimension'] ?? ''),
            (string)($fact['entity_id'] ?? $fact['hotel_id'] ?? ''),
        ];
        if (in_array('', $parts, true)) {
            throw new \InvalidArgumentException('fact identity is incomplete');
        }
        return hash('sha256', json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
