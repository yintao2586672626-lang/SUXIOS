<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Reapplies the operating-question strict fact gate to the exact source rows
 * used by revenue metrics. This is evidence filtering, not a second metric
 * calculation: metric values and units continue to come from the canonical
 * three-source fact layer.
 */
final class RevenueCockpitStrictEvidenceService
{
    /** @var Closure(int,int,string,string,array<int,string>):array<int,array<string,mixed>> */
    private Closure $strictFactReader;

    public function __construct(?callable $strictFactReader = null)
    {
        $this->strictFactReader = $strictFactReader !== null
            ? Closure::fromCallable($strictFactReader)
            : static fn(
                int $tenantId,
                int $hotelId,
                string $platform,
                string $businessDate,
                array $refs
            ): array => (new OperatingQuestionService())->readCurrentVerifiedFactsForRefs(
                $tenantId,
                $hotelId,
                $platform,
                $businessDate,
                $businessDate,
                $refs
            );
    }

    /** @return array<string,mixed> */
    public function build(
        array $overview,
        int $tenantId,
        int $hotelId,
        string $businessDate,
        string $platform
    ): array {
        if ($tenantId <= 0 || $hotelId <= 0) {
            throw new InvalidArgumentException('revenue_cockpit_strict_scope_invalid');
        }
        $businessDate = $this->date($businessDate);
        $platform = strtolower(trim($platform));
        if (!in_array($platform, ['ctrip', 'meituan', 'all_ota'], true)) {
            throw new InvalidArgumentException('revenue_cockpit_strict_platform_invalid');
        }

        $factLayer = is_array($overview['three_source_fact_layer'] ?? null)
            ? $overview['three_source_fact_layer']
            : [];
        $hotel = is_array($factLayer['hotel'] ?? null) ? $factLayer['hotel'] : [];
        if ((int)($overview['hotel_id'] ?? $hotel['system_hotel_id'] ?? 0) !== $hotelId
            || (int)($hotel['tenant_id'] ?? 0) !== $tenantId
            || (int)($hotel['system_hotel_id'] ?? 0) !== $hotelId
            || (string)($overview['business_date'] ?? '') !== $businessDate
            || (string)($factLayer['business_date'] ?? '') !== $businessDate
        ) {
            throw new RuntimeException('revenue_cockpit_strict_overview_scope_mismatch', 422);
        }

        $sources = is_array($factLayer['sources'] ?? null) ? $factLayer['sources'] : [];
        $selectedPlatforms = $platform === 'all_ota' ? ['ctrip', 'meituan'] : [$platform];
        $platformEvidence = [];
        foreach ($selectedPlatforms as $selectedPlatform) {
            $sourceKey = $selectedPlatform . '_ota';
            $source = is_array($sources[$sourceKey] ?? null) ? $sources[$sourceKey] : [];
            $statuses = is_array($source['fact_statuses'] ?? null) ? $source['fact_statuses'] : [];
            $metricRequestedIds = [];
            $requestedIds = [];
            foreach ($statuses as $metricKey => $status) {
                $status = is_array($status) ? $status : [];
                $provenance = is_array($status['source_provenance'] ?? null)
                    ? $status['source_provenance']
                    : [];
                $ids = $this->positiveIds($provenance['row_ids'] ?? []);
                $metricRequestedIds[(string)$metricKey] = $ids;
                if ($this->metricStatusReady((string)($status['status'] ?? ''))) {
                    $requestedIds = array_merge($requestedIds, $ids);
                }
            }
            $requestedIds = $this->positiveIds($requestedIds);
            $requestedRefs = array_map(
                static fn(int $id): string => 'online_daily_data#' . $id,
                $requestedIds
            );
            $verifiedFacts = $requestedRefs === []
                ? []
                : ($this->strictFactReader)(
                    $tenantId,
                    $hotelId,
                    $selectedPlatform,
                    $businessDate,
                    $requestedRefs
                );
            $acceptedIds = [];
            foreach (is_array($verifiedFacts) ? $verifiedFacts : [] as $fact) {
                if (preg_match('/^online_daily_data#([1-9][0-9]*)$/D', (string)($fact['ref'] ?? ''), $matches) === 1) {
                    $acceptedIds[] = (int)$matches[1];
                }
            }
            $acceptedIds = $this->positiveIds($acceptedIds);
            $acceptedLookup = array_fill_keys($acceptedIds, true);
            $metrics = [];
            foreach ($statuses as $metricKey => $status) {
                $status = is_array($status) ? $status : [];
                $ids = $metricRequestedIds[(string)$metricKey] ?? [];
                $acceptedMetricIds = array_values(array_filter(
                    $ids,
                    static fn(int $id): bool => isset($acceptedLookup[$id])
                ));
                $rejectedMetricIds = array_values(array_diff($ids, $acceptedMetricIds));
                $ready = $this->metricStatusReady((string)($status['status'] ?? ''));
                $metrics[(string)$metricKey] = [
                    'source_status' => (string)($status['status'] ?? 'missing'),
                    'requested_row_ids' => $ids,
                    'accepted_row_ids' => $acceptedMetricIds,
                    'rejected_row_ids' => $rejectedMetricIds,
                    'strict_readback' => $ready
                        && $ids !== []
                        && $rejectedMetricIds === []
                        && count($acceptedMetricIds) === count($ids),
                ];
            }

            $rejectedIds = array_values(array_diff($requestedIds, $acceptedIds));
            $platformEvidence[$selectedPlatform] = [
                'source_key' => $sourceKey,
                'source_status' => (string)($source['data_status'] ?? 'missing'),
                'business_date' => $businessDate,
                'requested_row_ids' => $requestedIds,
                'accepted_row_ids' => $acceptedIds,
                'rejected_row_ids' => $rejectedIds,
                'accepted_fact_refs' => array_map(
                    static fn(int $id): string => 'online_daily_data#' . $id,
                    $acceptedIds
                ),
                'source_strict_readback' => (string)($source['data_status'] ?? '') === 'readback_verified'
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
            'pms_included' => false,
            'all_selected_ota_sources_strict' => $allSelectedSourcesStrict,
            'platforms' => $platformEvidence,
        ];
    }

    private function metricStatusReady(string $status): bool
    {
        return in_array(strtolower(trim($status)), ['readback_verified', 'derived_verified', 'verified'], true);
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
