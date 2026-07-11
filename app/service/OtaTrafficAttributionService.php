<?php
declare(strict_types=1);

namespace app\service;

final class OtaTrafficAttributionService
{
    private const TRAFFIC_DATA_TYPES = ['traffic', 'flow', 'conversion'];
    private const PROFILE_INGESTION_METHODS = ['browser_profile', 'profile_browser'];

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

        $compareType = strtolower(trim((string)($row['compare_type'] ?? '')));
        return $compareType === '' || $compareType === 'self';
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
