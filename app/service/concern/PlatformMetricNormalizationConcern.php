<?php
declare(strict_types=1);

namespace app\service\concern;

trait PlatformMetricNormalizationConcern
{
    /** @param array<string, mixed> $row */
    private function isCtripCheckoutOverviewRow(array $row, string $platform, string $dataType): bool
    {
        if (strtolower(trim($platform)) !== 'ctrip' || $this->normalizeDataType($dataType) !== 'business') {
            return false;
        }
        return strtolower(trim((string)($row['endpoint_id'] ?? ''))) === 'business_market_overview'
            && strtolower(trim((string)($row['section'] ?? ''))) === 'business_overview';
    }

    /** @param array<string, mixed> $row @param array<int, string> $keys */
    private function nullableRoundedInteger(array $row, array $keys): ?int
    {
        $value = $this->nullableNumericValue($row, $keys);
        return $value === null ? null : (int)round($value);
    }

    /** @param array<string, mixed> $row */
    private function flowRateValue(array $row, string $dataType, bool $preserveMissing = false): ?float
    {
        $dataType = $this->normalizeDataType($dataType);
        if ($dataType === 'advertising') {
            return $this->nullableNumericValue($row, ['flow_rate', 'flowRate', 'ctr'])
                ?? ($preserveMissing ? null : 0.0);
        }

        $explicit = $this->nullableNumericValue($row, [
            'exposure_to_browse_rate', 'exposureToBrowseRate', 'intentionPerExposure',
            'expose_visit_rate', 'flow_rate', 'flowRate',
        ]);
        if ($explicit !== null) {
            return $explicit;
        }

        $listExposure = $this->nullableNumericValue($row, [
            'mt_exposure', 'list_exposure', 'listExposure',
            'exposure_users', 'exposureUsers', 'exposureUV',
        ]);
        $detailExposure = $this->nullableNumericValue($row, [
            'mt_intention_uv', 'detail_exposure', 'detailExposure',
            'detail_visitors', 'detailVisitors', 'intentionUV',
        ]);
        if ($listExposure !== null && $listExposure > 0
            && $detailExposure !== null && $detailExposure >= 0
        ) {
            return round($detailExposure / $listExposure * 100, 2);
        }
        return $preserveMissing ? null : 0.0;
    }
}
