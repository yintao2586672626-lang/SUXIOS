<?php
declare(strict_types=1);

namespace app\service;

final class OtaTrafficAttributionService
{
    private const TRAFFIC_DATA_TYPES = ['traffic', 'flow', 'conversion'];
    private const PROFILE_INGESTION_METHODS = ['browser_profile', 'profile_browser'];
    private const TRAFFIC_ENDPOINT_CONFLICT = '__endpoint_conflict__';

    /**
     * A Profile source can provide traffic even when its primary data_type is
     * business, provided its explicit capture contract includes traffic.
     *
     * @param array<string, mixed> $source
     * @param array<string, mixed> $config
     */
    public static function sourceCanProvideTraffic(array $source, array $config = []): bool
    {
        $dataType = strtolower(trim((string)($source['data_type'] ?? '')));
        if (in_array($dataType, self::TRAFFIC_DATA_TYPES, true)) {
            return true;
        }

        $method = strtolower(trim((string)($source['ingestion_method'] ?? '')));
        if (!in_array($method, self::PROFILE_INGESTION_METHODS, true)) {
            return false;
        }

        $sections = $config['capture_sections'] ?? $config['captureSections'] ?? [];
        $sectionText = strtolower(implode(',', self::flattenSectionValues($sections)));
        return preg_match('/(?:^|[^a-z0-9])traffic(?:[^a-z0-9]|$)/', $sectionText) === 1;
    }

    /**
     * P0 hotel traffic evaluates only the selected OTA's own-hotel rows.
     * Competitor rows and cross-platform comparison rows remain stored but do
     * not participate in the selected hotel's traffic closure.
     *
     * @param array<string, mixed> $row
     */
    public static function rowBelongsToOwnPlatformTraffic(array $row, string $platform): bool
    {
        $platform = strtolower(trim($platform));
        if (!in_array($platform, ['ctrip', 'meituan'], true)) {
            return false;
        }

        $rowPlatform = strtolower(trim((string)($row['platform'] ?? '')));
        if ($rowPlatform !== '' && $rowPlatform !== $platform) {
            return false;
        }

        // Ctrip-family captures can persist Ctrip and Qunar rows under the same
        // acquisition platform. The explicit dimension remains authoritative:
        // Qunar traffic must never satisfy Ctrip's own-hotel P0 closure.
        $dimensionChannel = self::dimensionChannel((string)($row['dimension'] ?? ''));
        if ($dimensionChannel !== '' && $dimensionChannel !== $platform) {
            return false;
        }

        $compareType = strtolower(trim((string)($row['compare_type'] ?? '')));
        return $compareType === '' || $compareType === 'self';
    }

    /**
     * P0 closure accepts only the selected OTA's own canonical traffic rows.
     * Auxiliary endpoints remain available for diagnostics, but cannot make
     * the canonical field-loop gate pass or fail.
     *
     * @param array<string, mixed> $row
     */
    public static function rowBelongsToAuthoritativeP0Traffic(array $row, string $platform): bool
    {
        $platform = strtolower(trim($platform));
        if (!self::rowBelongsToOwnPlatformTraffic($row, $platform)
            || !self::rowDateScopeIsAuthoritative($row, $platform)
        ) {
            return false;
        }

        if ($platform !== 'ctrip') {
            return true;
        }

        $endpointId = self::trafficRowEndpointId($row);
        if ($endpointId === '') {
            return trim((string)($row['dimension'] ?? '')) === '';
        }

        return in_array($endpointId, ['business_flow_transform', 'traffic_flow_transform'], true);
    }

    /** @param array<string, mixed> $row */
    public static function rowDateScopeIsAuthoritative(array $row, string $platform): bool
    {
        if (strtolower(trim($platform)) !== 'meituan') {
            return true;
        }

        $raw = self::decodeRawData($row['raw_data'] ?? null);
        $dateSource = strtolower(trim((string)($raw['date_source'] ?? $raw['dateSource'] ?? '')));
        return $dateSource !== 'response.rtdataupdatetime'
            && $dateSource !== 'page.visible_update_time'
            && preg_match('/(?:^|\.)cards\.rtdataupdatetime$/', $dateSource) !== 1;
    }

    private static function dimensionChannel(string $dimension): string
    {
        $dimension = strtolower(trim($dimension));
        if ($dimension === '') {
            return '';
        }

        foreach (['ctrip', 'qunar', 'meituan'] as $channel) {
            if (preg_match(
                '/(?:^|[^a-z0-9])' . preg_quote($channel, '/') . '(?:[^a-z0-9]|$)/',
                $dimension
            ) === 1) {
                return $channel;
            }
        }

        return '';
    }

    /** @param array<string, mixed> $row */
    private static function trafficRowEndpointId(array $row): string
    {
        $endpointIds = [];
        $raw = self::decodeRawData($row['raw_data'] ?? null);
        foreach ([
            is_array($raw['row'] ?? null) ? $raw['row'] : [],
            is_array($raw['source_row'] ?? null) ? $raw['source_row'] : [],
            is_array($raw['row']['capture'] ?? null) ? $raw['row']['capture'] : [],
            is_array($raw['source_row']['capture'] ?? null) ? $raw['source_row']['capture'] : [],
            is_array($raw['capture'] ?? null) ? $raw['capture'] : [],
            $raw,
            $row,
        ] as $container) {
            foreach (['endpoint_id', 'endpointId', '_endpoint_id'] as $key) {
                $endpointId = strtolower(trim((string)($container[$key] ?? '')));
                if ($endpointId !== '') {
                    $endpointIds[$endpointId] = true;
                }
            }
        }

        $dimension = trim((string)($row['dimension'] ?? ''));
        if (preg_match('/^catalog:[^:]+:([^:]+)/', $dimension, $matches) === 1) {
            $endpointId = strtolower(trim((string)($matches[1] ?? '')));
            if ($endpointId !== '') {
                $endpointIds[$endpointId] = true;
            }
        }

        $resolved = array_keys($endpointIds);
        if (count($resolved) > 1) {
            return self::TRAFFIC_ENDPOINT_CONFLICT;
        }

        return $resolved[0] ?? '';
    }

    /** @return array<string, mixed> */
    private static function decodeRawData(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<int, int|string> $sourceHotelIds
     * @param array<int, int|string> $profileBindingHotelIds
     * @param array<int, int|string> $storedTrafficHotelIds
     * @return array<int, int>
     */
    public static function mergeP0HotelScopeIds(
        array $sourceHotelIds,
        array $profileBindingHotelIds,
        array $storedTrafficHotelIds
    ): array {
        $hotelIds = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): int => (int)$value,
            array_merge($sourceHotelIds, $profileBindingHotelIds, $storedTrafficHotelIds)
        ), static fn(int $hotelId): bool => $hotelId > 0)));
        sort($hotelIds, SORT_NUMERIC);
        return $hotelIds;
    }

    /** @return array<int, string> */
    private static function flattenSectionValues(mixed $value): array
    {
        if (is_scalar($value)) {
            return [trim((string)$value)];
        }
        if (!is_array($value)) {
            return [];
        }

        $values = [];
        foreach ($value as $item) {
            $values = array_merge($values, self::flattenSectionValues($item));
        }
        return $values;
    }
}
