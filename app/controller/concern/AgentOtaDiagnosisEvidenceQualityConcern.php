<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\service\OtaOperatingScope;

trait AgentOtaDiagnosisEvidenceQualityConcern
{
    /**
     * OTA traffic rows are cumulative snapshots, not additive events. Keep one
     * canonical own-hotel snapshot per platform/date: a final historical day
     * row wins, otherwise the latest realtime snapshot wins. Older snapshots
     * remain available for audit but must not enter the operating baseline.
     *
     * @return array{rows: array<int,array<string,mixed>>, superseded_rows: array<int,array<string,mixed>>}
     */
    private function selectCanonicalOtaDiagnosisTrafficSnapshots(
        array $rows,
        int $hotelId,
        string $hotelName,
        string $platform
    ): array {
        $ownHotelNames = array_values(array_filter(
            [$hotelName],
            static fn(mixed $value): bool => trim((string)$value) !== ''
        ));
        $ownPlatformHotelIds = $this->otaDiagnosisOwnPlatformHotelIds($rows, $hotelId, $platform);
        $groups = [];
        $candidateIndexes = [];

        foreach ($rows as $index => $row) {
            if (!is_array($row)
                || !$this->isOtaDiagnosisTrafficSnapshotRow($row)
                || !OtaOperatingScope::isOwnOperatingRow($row, null, $ownHotelNames, $ownPlatformHotelIds)
            ) {
                continue;
            }

            $date = trim((string)($row['data_date'] ?? ''));
            if ($date === '') {
                continue;
            }
            $source = strtolower(trim((string)($row['source'] ?? $row['platform'] ?? $platform)));
            $systemHotelId = (int)($row['system_hotel_id'] ?? 0);
            if ($systemHotelId <= 0) {
                $systemHotelId = $hotelId;
            }
            $groupKey = implode('|', [$source, (string)$systemHotelId, $date, 'self', 'traffic_core']);
            $groups[$groupKey][] = ['index' => $index, 'row' => $row];
            $candidateIndexes[$index] = true;
        }

        $canonicalIndexes = [];
        foreach ($groups as $candidates) {
            $selected = null;
            foreach ($candidates as $candidate) {
                if ($selected === null || $this->compareOtaDiagnosisTrafficSnapshots(
                    $candidate['row'],
                    $selected['row'],
                    $hotelId,
                    $hotelName,
                    $ownPlatformHotelIds
                ) > 0) {
                    $selected = $candidate;
                }
            }
            if ($selected !== null) {
                $canonicalIndexes[$selected['index']] = true;
            }
        }

        $selectedRows = [];
        $supersededRows = [];
        foreach ($rows as $index => $row) {
            if (isset($candidateIndexes[$index]) && !isset($canonicalIndexes[$index])) {
                $supersededRows[] = $row;
                continue;
            }
            $selectedRows[] = $row;
        }

        return [
            'rows' => array_values($selectedRows),
            'superseded_rows' => array_values($supersededRows),
        ];
    }

    private function isOtaDiagnosisTrafficSnapshotRow(array $row): bool
    {
        $dataType = strtolower(trim((string)($row['data_type'] ?? '')));
        if ($dataType === 'traffic') {
            return true;
        }

        $period = strtolower(trim((string)($row['data_period'] ?? '')));
        return in_array($dataType, ['business', 'business_overview'], true)
            && in_array($period, ['historical_daily', 'realtime_snapshot'], true)
            && $this->hasKnownOtaDiagnosisMetric($row, [
                'list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num',
            ]);
    }

    private function compareOtaDiagnosisTrafficSnapshots(
        array $left,
        array $right,
        int $hotelId,
        string $hotelName,
        array $ownPlatformHotelIds
    ): int {
        $leftScore = $this->otaDiagnosisTrafficSnapshotScore($left, $hotelId, $hotelName, $ownPlatformHotelIds);
        $rightScore = $this->otaDiagnosisTrafficSnapshotScore($right, $hotelId, $hotelName, $ownPlatformHotelIds);
        foreach (array_keys($leftScore) as $key) {
            $comparison = $leftScore[$key] <=> $rightScore[$key];
            if ($comparison !== 0) {
                return $comparison;
            }
        }
        return 0;
    }

    /** @return array{final:int, identity:int, timestamp:int, id:int} */
    private function otaDiagnosisTrafficSnapshotScore(
        array $row,
        int $hotelId,
        string $hotelName,
        array $ownPlatformHotelIds
    ): array {
        $period = strtolower(trim((string)($row['data_period'] ?? '')));
        $isFinal = $this->otaDiagnosisTruthy($row['is_final'] ?? null) || $period === 'historical_daily';
        $compareType = strtolower(trim((string)($row['compare_type'] ?? '')));
        $rowHotelName = preg_replace('/\s+/u', '', trim((string)($row['hotel_name'] ?? ''))) ?? '';
        $expectedHotelName = preg_replace('/\s+/u', '', trim($hotelName)) ?? '';
        $platformHotelId = trim((string)($row['hotel_id'] ?? ''));
        $hasExplicitIdentity = in_array($compareType, ['self', 'own', 'mine', 'current'], true)
            || ($hotelId > 0 && (int)($row['system_hotel_id'] ?? 0) === $hotelId)
            || ($platformHotelId !== '' && in_array($platformHotelId, $ownPlatformHotelIds, true))
            || ($rowHotelName !== '' && $expectedHotelName !== '' && $rowHotelName === $expectedHotelName);

        $timestamp = 0;
        foreach (['snapshot_time', 'collected_at', 'readback_verified_at', 'update_time', 'create_time'] as $field) {
            $value = trim((string)($row[$field] ?? ''));
            if ($value === '') {
                continue;
            }
            $parsed = strtotime($value);
            if ($parsed !== false) {
                $timestamp = $parsed;
                break;
            }
        }

        return [
            'final' => $isFinal ? 1 : 0,
            'identity' => $hasExplicitIdentity ? 1 : 0,
            'timestamp' => $timestamp,
            'id' => (int)($row['id'] ?? 0),
        ];
    }

    private function otaDiagnosisTruthy(mixed $value): bool
    {
        if ($value === true || $value === 1) {
            return true;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'final'], true);
    }

    private function isOtaDiagnosisDecisionEligibleRow(array $row): bool
    {
        if ((int)($row['readback_verified'] ?? 0) !== 1) {
            return false;
        }

        return in_array($this->otaDiagnosisRowQualityStatus($row), [
            'normal',
            'available',
            'ok',
            'valid',
            'verified',
        ], true);
    }

    private function otaDiagnosisRowQualityStatus(array $row): string
    {
        if ((int)($row['readback_verified'] ?? 0) !== 1) {
            return 'readback_unverified';
        }

        $status = strtolower(trim((string)($row['validation_status'] ?? 'unverified')));
        return $status !== '' ? $status : 'unverified';
    }

    private function blockingOtaDiagnosisDataGaps(mixed $dataGaps, array $context = []): array
    {
        $revenueMetricFields = ['amount', 'quantity', 'book_order_num'];
        $trafficMetricFields = ['list_exposure', 'detail_visitors', 'flow_rate', 'order_visitors', 'submit_users'];
        $coreMetricFields = array_merge($revenueMetricFields, $trafficMetricFields);
        $coreMetricCodes = array_fill_keys(array_map(
            static fn(string $field): string => 'metric_missing:' . $field,
            $coreMetricFields
        ), true);
        $metrics = is_array($context['metrics'] ?? null) ? $context['metrics'] : [];
        $hasCompleteMetricGroup = static function (array $fields) use ($metrics): bool {
            if ($metrics === []) {
                return false;
            }
            foreach ($fields as $field) {
                if (!array_key_exists($field, $metrics) || $metrics[$field] === null || $metrics[$field] === '') {
                    return false;
                }
            }
            return true;
        };
        $revenueMetricsComplete = $hasCompleteMetricGroup($revenueMetricFields);
        $trafficMetricsComplete = $hasCompleteMetricGroup($trafficMetricFields);

        $blocking = [];
        foreach ($this->normalizeOtaDiagnosisDataGaps($dataGaps) as $gap) {
            $code = trim((string)($gap['code'] ?? ''));
            $isMetricGap = str_starts_with($code, 'metric_missing:');
            if (($isMetricGap && !isset($coreMetricCodes[$code])) || $code === '') {
                continue;
            }
            if ($isMetricGap) {
                $metric = substr($code, strlen('metric_missing:'));
                $isMissingAlternativePathMetric = ($trafficMetricsComplete && in_array($metric, $revenueMetricFields, true))
                    || ($revenueMetricsComplete && in_array($metric, $trafficMetricFields, true));
                if ($isMissingAlternativePathMetric) {
                    continue;
                }
            }
            $gap['status'] = 'blocked_by_data_gap';
            $gap['blocking'] = true;
            $blocking[] = $gap;
        }

        return $blocking;
    }
}
