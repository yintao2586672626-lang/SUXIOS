<?php
declare(strict_types=1);

namespace app\service;

final class OnlineDataAnalysisMetricScopeService
{
    public static function normalize(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 255 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new \InvalidArgumentException('指标口径无效，请从当前范围的指标列表选择', 422);
        }
        return trim($value, ' ');
    }

    public static function apply($query, string $dimension): void
    {
        if ($dimension !== '') {
            // HEX makes the exact identity independent of MySQL's case-insensitive collation.
            $query->whereRaw('HEX(TRIM(`dimension`)) = :analysis_metric_dimension', [
                'analysis_metric_dimension' => strtoupper(bin2hex($dimension)),
            ]);
        }
    }

    public static function select(array $rows, string $dimension): array
    {
        return $dimension === '' ? $rows : array_values(array_filter($rows,
            static fn(array $row): bool => trim((string)($row['dimension'] ?? ''), ' ') === $dimension
        ));
    }

    public static function options(array $rows): array
    {
        $dimensions = [];
        foreach ($rows as $row) {
            try {
                $dimension = self::normalize($row['dimension'] ?? '');
            } catch (\InvalidArgumentException) { continue; }
            if ($dimension !== '') $dimensions[$dimension] = ['value' => $dimension, 'label' => $dimension];
        }
        ksort($dimensions);
        return array_values($dimensions);
    }

    public static function scope(string $dataType, bool $defaulted, string $startDate, string $endDate,
        string $source, string $hotelId): array
    {
        return [
            'data_type' => $dataType,
            'data_type_defaulted' => $defaulted,
            'metric_scope' => $dataType === 'business'
                ? 'ota_channel_business_operating_facts' : 'ota_channel_typed_facts',
            'truth_policy' => 'readback_verified_and_validation_usable',
            'legacy_untyped_rows' => 'excluded',
            'cross_type_aggregation' => false,
            'excluded_default_types' => $defaulted ? ['untyped', 'advertising', 'peer_rank', 'ranking', 'traffic'] : [],
            'start_date' => $startDate,
            'end_date' => $endDate,
            'source' => $source !== '' ? $source : null,
            'system_hotel_id' => $hotelId !== '' ? $hotelId : null,
        ];
    }
}
