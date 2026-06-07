<?php
declare(strict_types=1);

namespace app\service;

final class OtaOperatingScope
{
    public static function isOwnOperatingRow(array $row, ?array $raw = null, array $ownHotelNames = []): bool
    {
        $raw ??= self::decodeRaw($row['raw_data'] ?? null);

        if (self::isPeerRankDimension((string)($row['dimension'] ?? ''))) {
            return false;
        }

        $compareType = self::compareType($row, $raw);
        if (in_array($compareType, ['competitor', 'peer', 'compete', 'rival'], true)) {
            return false;
        }

        $hotelName = trim((string)($row['hotel_name'] ?? $raw['hotelName'] ?? $raw['hotel_name'] ?? $raw['poiName'] ?? $raw['poi_name'] ?? ''));
        if (self::isSelfRow($row, $raw, $hotelName, $ownHotelNames)) {
            return true;
        }

        if ($hotelName !== '') {
            return false;
        }

        return true;
    }

    public static function filterOwnOperatingRows(array $rows, array $ownHotelNames = []): array
    {
        return array_values(array_filter($rows, static fn ($row): bool => is_array($row) && self::isOwnOperatingRow($row, null, $ownHotelNames)));
    }

    public static function isPeerRankDimension(string $dimension): bool
    {
        if ($dimension === '') {
            return false;
        }

        foreach (['榜', '排名', '竞争圈', '竞对'] as $keyword) {
            if (str_contains($dimension, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private static function isSelfRow(array $row, array $raw, string $hotelName, array $ownHotelNames): bool
    {
        if ($hotelName === '我的酒店' || $hotelName === '本店') {
            return true;
        }

        $normalizedHotelName = self::normalizeName($hotelName);
        foreach ($ownHotelNames as $ownHotelName) {
            if ($normalizedHotelName !== '' && $normalizedHotelName === self::normalizeName((string)$ownHotelName)) {
                return true;
            }
        }

        foreach (['isSelf', 'is_self', 'self', 'isCurrentHotel', 'is_current_hotel'] as $key) {
            if (($raw[$key] ?? null) === true || (string)($raw[$key] ?? '') === '1') {
                return true;
            }
        }

        return in_array(self::compareType($row, $raw), ['self', 'own', 'mine', 'current'], true);
    }

    private static function compareType(array $row, array $raw): string
    {
        return strtolower(trim((string)($row['compare_type'] ?? $raw['compareType'] ?? $raw['compare_type'] ?? $raw['type'] ?? '')));
    }

    private static function normalizeName(string $value): string
    {
        return preg_replace('/\s+/u', '', trim($value)) ?? '';
    }

    private static function decodeRaw(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value === null || $value === '') {
            return [];
        }
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
