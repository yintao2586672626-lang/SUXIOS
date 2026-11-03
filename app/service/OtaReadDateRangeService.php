<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;

final class OtaReadDateRangeService
{
    /** @return array{0:string,1:string} */
    public static function normalize(mixed $start, mixed $end, string $label = '业务日期'): array
    {
        $dates = [];
        foreach ([$start, $end] as $value) {
            if ($value !== null && !is_string($value)) {
                throw new InvalidArgumentException($label . '必须是真实的 YYYY-MM-DD 日期', 422);
            }
            $value = trim((string)$value);
            if ($value !== '' && (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) !== 1
                || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]))) {
                throw new InvalidArgumentException($label . '必须是真实的 YYYY-MM-DD 日期', 422);
            }
            $dates[] = $value;
        }
        if ($dates[0] !== '' && $dates[1] !== '' && $dates[0] > $dates[1]) {
            throw new InvalidArgumentException($label . '开始日期不能晚于结束日期', 422);
        }
        return $dates;
    }
}
