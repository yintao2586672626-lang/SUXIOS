<?php
declare(strict_types=1);

namespace app\service\concern;

trait PlatformNormalizedConsistencyConcern
{
    /**
     * Keep rows from a mismatched requested period out of the requested fact
     * set, and quarantine same-run all-zero aggregates that contradict
     * positive Meituan order evidence.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $source
     * @return array<int, array<string, mixed>>
     */
    private function applyNormalizedRunConsistencyGuards(
        array $rows,
        array $payload,
        array $source
    ): array {
        if ($rows === []) {
            return $rows;
        }

        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        $requestedPeriod = $this->normalizeDataPeriod(
            $payload['data_period'] ?? $payload['dataPeriod'] ?? ''
        );
        $targetDate = $this->normalizeDate(
            $payload['data_date'] ?? $payload['dataDate'] ?? null
        );
        $coreTypes = ['business', 'traffic', 'order'];

        if ($requestedPeriod !== '' && $targetDate !== null) {
            foreach ($rows as $index => $row) {
                $dataType = $this->normalizeDataType((string)($row['data_type'] ?? ''));
                if (!in_array($dataType, $coreTypes, true)
                    || (string)($row['data_date'] ?? '') !== $targetDate
                    || (string)($row['data_period'] ?? '') === $requestedPeriod
                ) {
                    continue;
                }
                $rows[$index] = $this->quarantineNormalizedConsistencyConflict(
                    $row,
                    'requested_data_period_mismatch',
                    [
                        'requested_data_period' => $requestedPeriod,
                        'observed_data_period' => (string)($row['data_period'] ?? ''),
                        'data_type' => $dataType,
                    ]
                );
            }
        }

        if ($platform !== 'meituan') {
            return $rows;
        }

        $groups = [];
        foreach ($rows as $index => $row) {
            $compareType = strtolower(trim((string)($row['compare_type'] ?? '')));
            if ($compareType !== '' && $compareType !== 'self') {
                continue;
            }
            $date = trim((string)($row['data_date'] ?? ''));
            if ($date === '') {
                continue;
            }
            $groups[implode('|', [
                (string)($row['tenant_id'] ?? ''),
                (string)($row['system_hotel_id'] ?? ''),
                (string)($row['data_source_id'] ?? ''),
                $date,
            ])][] = $index;
        }

        foreach ($groups as $indexes) {
            if (!$this->hasPositiveNormalizedOrderEvidence($rows, $indexes)) {
                continue;
            }
            foreach ($indexes as $index) {
                $row = $rows[$index];
                $dataType = $this->normalizeDataType((string)($row['data_type'] ?? ''));
                $metricKeys = match ($dataType) {
                    'business' => ['amount', 'quantity', 'book_order_num'],
                    'traffic' => ['list_exposure', 'detail_exposure', 'flow_rate'],
                    default => [],
                };
                if ($metricKeys === [] || !$this->normalizedMetricsAreExplicitZero($row, $metricKeys)) {
                    continue;
                }
                $rows[$index] = $this->quarantineNormalizedConsistencyConflict(
                    $row,
                    'same_run_zero_' . $dataType . '_conflicts_with_nonzero_orders',
                    [
                        'data_type' => $dataType,
                        'zero_metric_keys' => $metricKeys,
                        'conflicting_data_type' => 'order',
                    ]
                );
            }
        }

        return $rows;
    }

    /** @param array<int, array<string, mixed>> $rows @param array<int, int> $indexes */
    private function hasPositiveNormalizedOrderEvidence(array $rows, array $indexes): bool
    {
        foreach ($indexes as $index) {
            $row = $rows[$index];
            if ($this->normalizeDataType((string)($row['data_type'] ?? '')) !== 'order') {
                continue;
            }
            foreach (['amount', 'quantity', 'book_order_num', 'order_submit_num'] as $metricKey) {
                if (array_key_exists($metricKey, $row)
                    && is_numeric($row[$metricKey])
                    && (float)$row[$metricKey] > 0.000001
                ) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @param array<string, mixed> $row @param array<int, string> $metricKeys */
    private function normalizedMetricsAreExplicitZero(array $row, array $metricKeys): bool
    {
        foreach ($metricKeys as $metricKey) {
            if (!array_key_exists($metricKey, $row)
                || !is_numeric($row[$metricKey])
                || abs((float)$row[$metricKey]) > 0.000001
            ) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $evidence */
    private function quarantineNormalizedConsistencyConflict(
        array $row,
        string $reasonCode,
        array $evidence
    ): array {
        $flags = json_decode((string)($row['validation_flags'] ?? '[]'), true);
        $flags = is_array($flags) ? $flags : [];
        $flags[] = $reasonCode;
        $row['validation_flags'] = json_encode(
            array_values(array_unique(array_map('strval', $flags))),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $row['validation_status'] = 'quarantined';

        $raw = $this->decodeConfig($row['raw_data'] ?? []);
        $guards = is_array($raw['consistency_guards'] ?? null)
            ? $raw['consistency_guards']
            : [];
        $guards[] = array_merge([
            'status' => 'quarantined',
            'reason_code' => $reasonCode,
        ], $evidence);
        $raw['consistency_guards'] = $guards;
        $row['raw_data'] = json_encode(
            $raw,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        return $row;
    }
}
