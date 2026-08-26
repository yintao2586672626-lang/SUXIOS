<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use RuntimeException;

/**
 * Projects the canonical dual-OTA field closure into the legacy cockpit gate.
 * It never reads or recalculates a second set of OTA facts. Metric values stay
 * in DualOtaFieldClosureService; this adapter only exposes the consumer keys
 * already declared on each canonical field.
 */
final class RevenueCockpitStrictEvidenceService
{
    /** @return array<string,mixed> */
    public function build(
        array $overview,
        int $tenantId,
        int $hotelId,
        string $businessDate,
        string $platform,
        ?array $closure = null
    ): array {
        if ($tenantId <= 0 || $hotelId <= 0) {
            throw new InvalidArgumentException('revenue_cockpit_strict_scope_invalid');
        }
        $businessDate = $this->date($businessDate);
        $platform = strtolower(trim($platform));
        if (!in_array($platform, ['ctrip', 'meituan', 'all_ota'], true)) {
            throw new InvalidArgumentException('revenue_cockpit_strict_platform_invalid');
        }

        $closure = is_array($closure)
            ? $closure
            : (is_array($overview['dual_ota_field_closure'] ?? null)
                ? $overview['dual_ota_field_closure']
                : (new DualOtaFieldClosureService())->build($hotelId, $businessDate));
        if ((string)($closure['contract_version'] ?? '') !== 'dual_ota_field_closure.v1'
            || (int)($closure['tenant_id'] ?? 0) !== $tenantId
            || (int)($closure['hotel_id'] ?? 0) !== $hotelId
            || (string)($closure['business_date'] ?? '') !== $businessDate
            || !is_array($closure['platforms'] ?? null)
        ) {
            throw new RuntimeException('revenue_cockpit_strict_closure_scope_mismatch', 422);
        }
        $overviewHotelId = (int)($overview['hotel_id']
            ?? $overview['three_source_fact_layer']['hotel']['system_hotel_id']
            ?? 0);
        $overviewDate = (string)($overview['business_date']
            ?? $overview['three_source_fact_layer']['business_date']
            ?? '');
        if (($overviewHotelId > 0 && $overviewHotelId !== $hotelId)
            || ($overviewDate !== '' && $overviewDate !== $businessDate)
        ) {
            throw new RuntimeException('revenue_cockpit_strict_overview_scope_mismatch', 422);
        }

        $selectedPlatforms = $platform === 'all_ota' ? ['ctrip', 'meituan'] : [$platform];
        $platformEvidence = [];
        foreach ($selectedPlatforms as $selectedPlatform) {
            $sourceKey = $selectedPlatform . '_ota';
            $source = is_array($closure['platforms'][$selectedPlatform] ?? null)
                ? $closure['platforms'][$selectedPlatform]
                : [];
            $fields = is_array($source['fields'] ?? null) ? $source['fields'] : [];
            $requestedIds = [];
            $acceptedIds = [];
            $metrics = [];
            foreach ($fields as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $consumerKeys = array_values(array_unique(array_filter(array_map(
                    'strval',
                    (array)($field['consumer_metric_keys'] ?? [])
                ))));
                if ($consumerKeys === []) {
                    continue;
                }
                $ids = $this->positiveIds($field['source_record_ids'] ?? []);
                $requestedIds = array_merge($requestedIds, $ids);
                $ready = ($field['revenue_analysis_consumable'] ?? false) === true
                    && ($field['strict_final_gate'] ?? false) === true
                    && (string)($field['readback_status'] ?? '') === 'readback_verified'
                    && $this->metricStatusReady((string)($field['status'] ?? ''))
                    && $ids !== [];
                if ($ready) {
                    $acceptedIds = array_merge($acceptedIds, $ids);
                }
                foreach ($consumerKeys as $consumerKey) {
                    $metrics[$consumerKey] = [
                        'canonical_field_key' => (string)($field['metric_key'] ?? $field['key'] ?? ''),
                        'source_status' => (string)($field['status'] ?? 'source_missing'),
                        'requested_row_ids' => $ids,
                        'accepted_row_ids' => $ready ? $ids : [],
                        'rejected_row_ids' => $ready ? [] : $ids,
                        'strict_readback' => $ready,
                        'closure_identity' => (string)($closure['page_identity'] ?? ''),
                    ];
                }
            }

            $requestedIds = $this->positiveIds($requestedIds);
            $acceptedIds = $this->positiveIds($acceptedIds);
            $rejectedIds = array_values(array_diff($requestedIds, $acceptedIds));
            $platformEvidence[$selectedPlatform] = [
                'source_key' => $sourceKey,
                'source_status' => (string)($source['status'] ?? 'partial'),
                'business_date' => $businessDate,
                'requested_row_ids' => $requestedIds,
                'accepted_row_ids' => $acceptedIds,
                'rejected_row_ids' => $rejectedIds,
                'accepted_fact_refs' => array_map(
                    static fn(int $id): string => 'online_daily_data#' . $id,
                    $acceptedIds
                ),
                'source_strict_readback' => (string)($source['revenue_analysis']['status'] ?? '') === 'ready'
                    && $requestedIds !== []
                    && $rejectedIds === []
                    && count($acceptedIds) === count($requestedIds),
                'metrics' => $metrics,
            ];
        }

        $allSelectedSourcesStrict = $platformEvidence !== []
            && count(array_filter(
                $platformEvidence,
                static fn(array $row): bool => ($row['source_strict_readback'] ?? false) === true
            )) === count($platformEvidence);

        return [
            'contract_version' => 'revenue_cockpit_strict_evidence.v1',
            'status' => $allSelectedSourcesStrict ? 'ready' : 'blocked',
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'platform' => $platform,
            'strict_gate' => 'history_success+validation_verified+readback_verified',
            'metric_values_recalculated' => false,
            'field_source' => 'dual_ota_field_closure',
            'closure_identity' => (string)($closure['page_identity'] ?? ''),
            'consumer_contract_version' => (string)($closure['consumer_contract']['contract_version'] ?? ''),
            'pms_included' => false,
            'all_selected_ota_sources_strict' => $allSelectedSourcesStrict,
            'platforms' => $platformEvidence,
        ];
    }

    private function metricStatusReady(string $status): bool
    {
        return in_array(strtolower(trim($status)), [
            'strict_readback',
            'verified_calculation',
            'readback_verified',
            'derived_verified',
            'verified',
        ], true);
    }

    /** @return list<int> */
    private function positiveIds(mixed $values): array
    {
        $values = is_array($values) ? $values : [$values];
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $values),
            static fn(int $id): bool => $id > 0
        )));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private function date(string $value): string
    {
        $value = trim($value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('revenue_cockpit_strict_business_date_invalid');
        }
        return $value;
    }
}
