<?php
declare(strict_types=1);

namespace app\service;

final class OtaOperatingScope
{
    public static function isCoreBusinessDataType(string $dataType): bool
    {
        return in_array(
            strtolower(trim($dataType)),
            ['business', 'business_overview', 'revenue', 'order', 'orders'],
            true
        );
    }

    public static function isOwnOperatingRow(
        array $row,
        ?array $raw = null,
        array $ownHotelNames = [],
        array $ownPlatformHotelIds = []
    ): bool
    {
        $raw ??= self::decodeRaw($row['raw_data'] ?? null);
        $raw = self::operatingEvidence($raw);

        $dataType = strtolower(trim((string)($row['data_type'] ?? $raw['data_type'] ?? '')));
        if (in_array($dataType, ['competitor', 'competitor_avg', 'competition', 'peer', 'peer_rank'], true)
            || self::isPeerRankDimension((string)($row['dimension'] ?? ''))
        ) {
            return false;
        }

        $compareType = self::compareType($row, $raw);
        if (in_array($compareType, [
            'competitor',
            'competitor_avg',
            'competition',
            'peer',
            'peer_avg',
            'peer_rank',
            'compete',
            'rival',
        ], true)) {
            return false;
        }

        $hotelName = trim((string)($row['hotel_name'] ?? $raw['hotelName'] ?? $raw['hotel_name'] ?? $raw['poiName'] ?? $raw['poi_name'] ?? ''));
        if (self::isSelfRow($row, $raw, $hotelName, $ownHotelNames, $ownPlatformHotelIds)) {
            return true;
        }

        if ($hotelName !== '') {
            return false;
        }

        return true;
    }

    public static function filterOwnOperatingRows(
        array $rows,
        array $ownHotelNames = [],
        array $ownPlatformHotelIds = []
    ): array
    {
        return array_values(array_filter(
            $rows,
            static fn ($row): bool => is_array($row)
                && self::isOwnOperatingRow($row, null, $ownHotelNames, $ownPlatformHotelIds)
        ));
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

    private static function isSelfRow(
        array $row,
        array $raw,
        string $hotelName,
        array $ownHotelNames,
        array $ownPlatformHotelIds
    ): bool
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

        $boundIds = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => trim((string)$value),
            $ownPlatformHotelIds
        ), static fn (string $value): bool => $value !== '')));
        if ($boundIds !== []) {
            foreach (self::platformHotelIdCandidates($row, $raw) as $candidate) {
                if (in_array($candidate, $boundIds, true)) {
                    return true;
                }
            }
        }

        return in_array(self::compareType($row, $raw), ['self', 'own', 'mine', 'current'], true);
    }

    /** @return array<int, string> */
    private static function platformHotelIdCandidates(array $row, array $raw): array
    {
        $values = [];
        foreach (['hotel_id', 'hotelId', 'poi_id', 'poiId', 'store_id', 'storeId', 'external_hotel_id'] as $key) {
            foreach ([$row, $raw] as $source) {
                $value = trim((string)($source[$key] ?? ''));
                if ($value !== '') {
                    $values[] = $value;
                }
            }
        }

        return array_values(array_unique($values));
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

    /**
     * Persisted OTA rows keep normalized source facts under raw_data.row while
     * the wrapper carries provenance and field-fact evidence. Read both layers
     * so an explicit self/competitor identity survives the save-readback path.
     */
    private static function operatingEvidence(array $raw): array
    {
        $normalizedRow = $raw['row'] ?? null;
        if (!is_array($normalizedRow)) {
            return $raw;
        }

        return array_replace($normalizedRow, $raw);
    }
}
