<?php
declare(strict_types=1);

namespace app\service;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Builds one strictly scoped metric snapshot from daily RevenueFactLayer facts.
 *
 * A range is available only when every requested business day (and, for a
 * combined OTA snapshot, every requested platform) has same-hotel, same-date,
 * metric-level readback evidence. Missing facts remain unavailable; zero is
 * accepted only when it is an explicitly verified source value.
 */
final class OperatingGoalMetricSnapshotService
{
    public const SCOPE_WHOLE_HOTEL = 'whole_hotel_accommodation';
    public const SCOPE_OTA = 'ota_channel';

    /** @var callable(int,string):array<string,mixed> */
    private $factLayerLoader;

    /**
     * @param null|callable(int,string):array<string,mixed> $factLayerLoader
     */
    public function __construct(?callable $factLayerLoader = null)
    {
        if ($factLayerLoader === null) {
            $factLayer = new RevenueFactLayerService();
            $factLayerLoader = static fn(int $hotelId, string $businessDate): array =>
                $factLayer->build($hotelId, $businessDate);
        }
        $this->factLayerLoader = $factLayerLoader;
    }

    /**
     * @return array<string,mixed>
     */
    public function snapshot(
        int $tenantId,
        int $hotelId,
        string $metricKey,
        string $periodStart,
        string $periodEnd,
        array $context = []
    ): array {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('operating_goal_snapshot_tenant_invalid');
        }
        $requestedMetricKey = $this->metricKey($metricKey);
        [$metricScope, $platform] = $this->scopeContext($requestedMetricKey, $context);
        $result = $this->buildRange(
            $tenantId,
            $hotelId,
            $periodStart,
            $periodEnd,
            $metricScope,
            $requestedMetricKey,
            $platform
        );

        $ready = ($result['status'] ?? '') === 'available';
        $verifiedDayCount = (int)($result['verified_day_count'] ?? 0);
        $expectedSampleSize = (int)($result['expected_sample_size'] ?? 0);
        $status = $ready
            ? 'ready'
            : (
                $verifiedDayCount > 0 && $verifiedDayCount < $expectedSampleSize
                    ? 'partial'
                    : 'unavailable'
            );
        $dataGaps = array_values(array_filter(
            is_array($result['data_gaps'] ?? null) ? $result['data_gaps'] : [],
            'is_array'
        ));
        if (!$ready && $dataGaps === []) {
            foreach ($this->stringList($result['reason_codes'] ?? []) as $reasonCode) {
                $dataGaps[] = [
                    'business_date' => null,
                    'platform' => $metricScope === self::SCOPE_OTA ? $platform : null,
                    'reason_codes' => [$reasonCode],
                ];
            }
        }

        $snapshot = null;
        if ($ready) {
            $identity = $this->snapshotIdentity(
                $result,
                $metricScope,
                $platform,
                $hotelId
            );
            $snapshot = $identity + [
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                // Keep the caller's alias stable; canonical_metric_key is additive.
                'metric_key' => $requestedMetricKey,
                'canonical_metric_key' => (string)$result['canonical_metric_key'],
                'value' => $result['value'],
                'unit' => $result['unit'],
                'period_start' => (string)$result['period_start'],
                'period_end' => (string)$result['period_end'],
                'business_dates' => array_values((array)$result['business_dates']),
                'fact_scope' => $metricScope,
                'platform' => $metricScope === self::SCOPE_OTA ? $platform : null,
                'quality_status' => 'verified',
                'readback_status' => 'readback_verified',
                'readback_verified' => true,
                'evidence_refs' => array_values((array)$result['evidence_refs']),
                'sample_size' => (int)$result['sample_size'],
                'sample_size_basis' => (string)$result['sample_size_basis'],
                'source_method' => (string)$result['source_method'],
                'aggregation_formula' => $result['aggregation_formula'],
                'daily_readbacks' => array_values((array)$result['daily_readbacks']),
            ];
        }

        return [
            'status' => $status,
            'snapshot' => $snapshot,
            'data_gaps' => $dataGaps,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'metric_key' => $requestedMetricKey,
            'canonical_metric_key' => (string)$result['canonical_metric_key'],
            'period_start' => (string)$result['period_start'],
            'period_end' => (string)$result['period_end'],
            'fact_scope' => $metricScope,
            'platform' => $metricScope === self::SCOPE_OTA ? $platform : null,
            'quality_status' => $ready ? 'verified' : 'unverified',
            'readback_status' => $ready ? 'readback_verified' : 'not_verified',
            'evidence_refs' => array_values((array)$result['evidence_refs']),
            'sample_size' => (int)$result['sample_size'],
            'expected_sample_size' => $expectedSampleSize,
            'verified_day_count' => $verifiedDayCount,
            'unavailable_dates' => array_values((array)$result['unavailable_dates']),
            'reason_codes' => array_values((array)$result['reason_codes']),
        ];
    }

    /** @return array<string,mixed> */
    private function buildRange(
        int $tenantId,
        int $hotelId,
        string $dateFrom,
        string $dateTo,
        string $metricScope,
        string $metricKey,
        string $platform
    ): array {
        if ($hotelId <= 0) {
            throw new InvalidArgumentException('operating_goal_snapshot_hotel_invalid');
        }
        $dateFrom = $this->date($dateFrom, 'date_from');
        $dateTo = $this->date($dateTo, 'date_to');
        if ($dateFrom > $dateTo) {
            throw new InvalidArgumentException('operating_goal_snapshot_date_range_invalid');
        }

        $dates = $this->dateRange($dateFrom, $dateTo);
        if (count($dates) > 366) {
            throw new InvalidArgumentException('operating_goal_snapshot_date_range_too_large');
        }
        $metricScope = strtolower(trim($metricScope));
        if (!in_array($metricScope, [self::SCOPE_WHOLE_HOTEL, self::SCOPE_OTA], true)) {
            throw new InvalidArgumentException('operating_goal_snapshot_scope_invalid');
        }
        $requestedMetricKey = $this->metricKey($metricKey);
        $platform = $this->platform($metricScope, $platform);
        $definition = $this->metricDefinition($metricScope, $requestedMetricKey);
        $base = $this->baseResult(
            $tenantId,
            $hotelId,
            $dateFrom,
            $dateTo,
            $dates,
            $metricScope,
            $requestedMetricKey,
            $platform,
            $definition
        );
        if ($definition === null) {
            return $this->unavailable(
                $base,
                ['metric_not_supported:' . $requestedMetricKey],
                [],
                [],
                $dates,
                $tenantId
            );
        }

        $dailyReadbacks = [];
        $unavailableDays = [];
        $allEvidenceRefs = [];
        $totals = [];
        $wholeHotelPmsSources = [];

        foreach ($dates as $businessDate) {
            try {
                $layer = call_user_func($this->factLayerLoader, $hotelId, $businessDate);
            } catch (Throwable $exception) {
                $layer = null;
            }
            if (!is_array($layer)) {
                $unavailableDays[] = $this->dayGap(
                    $businessDate,
                    $platform,
                    ['revenue_fact_layer_load_failed']
                );
                continue;
            }

            $scopeResult = $this->layerScope($layer, $tenantId, $hotelId, $businessDate);
            if (($scopeResult['ready'] ?? false) !== true) {
                $unavailableDays[] = $this->dayGap(
                    $businessDate,
                    $platform,
                    (array)($scopeResult['reason_codes'] ?? ['fact_layer_scope_unverified'])
                );
                continue;
            }
            $daily = $metricScope === self::SCOPE_WHOLE_HOTEL
                ? $this->wholeHotelDay($layer, $hotelId, $tenantId, $businessDate, $definition)
                : $this->otaDay($layer, $hotelId, $tenantId, $businessDate, $definition, $platform);
            if (($daily['ready'] ?? false) !== true) {
                $unavailableDays[] = $this->dayGap(
                    $businessDate,
                    $platform,
                    (array)($daily['reason_codes'] ?? ['metric_readback_unverified'])
                );
                continue;
            }

            $components = is_array($daily['components'] ?? null) ? $daily['components'] : [];
            foreach ($components as $key => $value) {
                if (!is_numeric($value) || !is_finite((float)$value)) {
                    $unavailableDays[] = $this->dayGap(
                        $businessDate,
                        $platform,
                        ['metric_component_invalid:' . (string)$key]
                    );
                    continue 2;
                }
                $totals[(string)$key] = ($totals[(string)$key] ?? 0.0) + (float)$value;
            }
            $evidenceRefs = $this->stringList($daily['evidence_refs'] ?? []);
            if ($evidenceRefs === []) {
                $unavailableDays[] = $this->dayGap(
                    $businessDate,
                    $platform,
                    ['metric_evidence_refs_missing']
                );
                continue;
            }
            $allEvidenceRefs = array_merge($allEvidenceRefs, $evidenceRefs);
            if ($metricScope === self::SCOPE_WHOLE_HOTEL) {
                $wholeHotelPmsSources[] = (string)(
                    $daily['pms_source_key'] ?? 'pms'
                );
            }
            $dailyReadbacks[] = [
                'business_date' => $businessDate,
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'fact_scope' => $metricScope,
                'platform' => $metricScope === self::SCOPE_OTA ? $platform : null,
                'evidence_refs' => $evidenceRefs,
                'sample_size' => 1,
                'quality_status' => 'verified',
                'readback_status' => 'readback_verified',
                'source_readbacks' => array_values((array)($daily['source_readbacks'] ?? [])),
            ];
        }

        $allEvidenceRefs = array_values(array_unique($allEvidenceRefs));
        sort($allEvidenceRefs, SORT_STRING);
        if ($unavailableDays !== []) {
            $reasons = ['date_range_not_fully_verified'];
            foreach ($unavailableDays as $gap) {
                foreach ((array)($gap['reason_codes'] ?? []) as $reason) {
                    $reasons[] = (string)$reason;
                }
            }
            return $this->unavailable(
                $base,
                $reasons,
                $dailyReadbacks,
                $allEvidenceRefs,
                array_values(array_column($unavailableDays, 'business_date')),
                $tenantId,
                $unavailableDays
            );
        }
        $wholeHotelPmsSources = array_values(array_unique(
            $wholeHotelPmsSources
        ));
        if ($metricScope === self::SCOPE_WHOLE_HOTEL
            && count($wholeHotelPmsSources) !== 1
        ) {
            return $this->unavailable(
                $base,
                ['whole_hotel_pms_provider_changed_within_range'],
                $dailyReadbacks,
                $allEvidenceRefs,
                [],
                $tenantId
            );
        }

        [$value, $calculationReason] = $this->aggregateValue($definition, $totals);
        if ($value === null) {
            return $this->unavailable(
                $base,
                [$calculationReason !== '' ? $calculationReason : 'metric_not_calculable'],
                $dailyReadbacks,
                $allEvidenceRefs,
                [],
                $tenantId
            );
        }

        return array_replace($base, [
            'status' => 'available',
            'tenant_id' => $tenantId,
            'scope' => [
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'fact_scope' => $metricScope,
                'platform' => $metricScope === self::SCOPE_OTA ? $platform : null,
            ],
            'value' => $value,
            'evidence_refs' => $allEvidenceRefs,
            'sample_size' => count($dailyReadbacks),
            'verified_day_count' => count($dailyReadbacks),
            'quality_status' => 'verified',
            'readback_status' => 'readback_verified',
            'unavailable_dates' => [],
            'reason_codes' => [],
            'daily_readbacks' => $dailyReadbacks,
            'data_gaps' => [],
        ]);
    }

    /** @return array<string,mixed> */
    private function baseResult(
        int $tenantId,
        int $hotelId,
        string $dateFrom,
        string $dateTo,
        array $dates,
        string $metricScope,
        string $metricKey,
        string $platform,
        ?array $definition
    ): array {
        return [
            'status' => 'unavailable',
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'metric_key' => $metricKey,
            'canonical_metric_key' => (string)($definition['canonical_key'] ?? $metricKey),
            'metric_scope' => $metricScope,
            'fact_scope' => $metricScope,
            'platform' => $metricScope === self::SCOPE_OTA ? $platform : null,
            'scope' => [
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'fact_scope' => $metricScope,
                'platform' => $metricScope === self::SCOPE_OTA ? $platform : null,
            ],
            'value' => null,
            'unit' => $definition['unit'] ?? null,
            'period_start' => $dateFrom,
            'period_end' => $dateTo,
            'business_dates' => $dates,
            'expected_sample_size' => count($dates),
            'sample_size' => 0,
            'sample_size_basis' => 'fully_verified_business_days',
            'verified_day_count' => 0,
            'source_method' => 'revenue_fact_layer',
            'aggregation_formula' => $definition['formula'] ?? null,
            'evidence_refs' => [],
            'quality_status' => 'unverified',
            'readback_status' => 'not_verified',
            'unavailable_dates' => [],
            'reason_codes' => [],
            'daily_readbacks' => [],
            'data_gaps' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function unavailable(
        array $base,
        array $reasonCodes,
        array $dailyReadbacks,
        array $evidenceRefs,
        array $unavailableDates,
        ?int $tenantId,
        array $dataGaps = []
    ): array {
        $reasonCodes = $this->stringList($reasonCodes);
        return array_replace($base, [
            'status' => 'unavailable',
            'tenant_id' => $tenantId,
            'scope' => array_replace((array)$base['scope'], ['tenant_id' => $tenantId]),
            'value' => null,
            'evidence_refs' => $this->stringList($evidenceRefs),
            'sample_size' => count($dailyReadbacks),
            'verified_day_count' => count($dailyReadbacks),
            'quality_status' => 'unverified',
            'readback_status' => 'not_verified',
            'unavailable_dates' => array_values(array_unique($unavailableDates)),
            'reason_codes' => $reasonCodes,
            'daily_readbacks' => $dailyReadbacks,
            'data_gaps' => $dataGaps,
        ]);
    }

    /** @return array<string,mixed> */
    private function layerScope(
        array $layer,
        int $expectedTenantId,
        int $hotelId,
        string $businessDate
    ): array
    {
        $hotel = is_array($layer['hotel'] ?? null) ? $layer['hotel'] : [];
        $tenantId = (int)($hotel['tenant_id'] ?? 0);
        $layerHotelId = (int)($hotel['system_hotel_id'] ?? 0);
        $layerDate = trim((string)($layer['business_date'] ?? ''));
        $reasons = [];
        if ($tenantId <= 0) {
            $reasons[] = 'fact_layer_tenant_missing';
        } elseif ($tenantId !== $expectedTenantId) {
            $reasons[] = 'fact_layer_tenant_mismatch';
        }
        if ($layerHotelId !== $hotelId) {
            $reasons[] = 'fact_layer_hotel_mismatch';
        }
        if ($layerDate !== $businessDate) {
            $reasons[] = 'fact_layer_business_date_mismatch';
        }
        return [
            'ready' => $reasons === [],
            'tenant_id' => $tenantId,
            'reason_codes' => $reasons,
        ];
    }

    /** @return array<string,mixed> */
    private function wholeHotelDay(
        array $layer,
        int $hotelId,
        int $tenantId,
        string $businessDate,
        array $definition
    ): array {
        $pmsSelection = (new RevenuePmsFactSelectorService())
            ->select($layer);
        $pmsSourceKey = (string)$pmsSelection['source_key'];
        $source = is_array($pmsSelection['source'] ?? null)
            ? $pmsSelection['source']
            : [];
        $identityReasons = $this->sourceIdentityReasons(
            $source,
            $hotelId,
            $tenantId,
            $businessDate,
            self::SCOPE_WHOLE_HOTEL,
            null
        );
        if ((string)($pmsSelection['data_status'] ?? '') !== 'readback_verified') {
            $identityReasons[] = 'whole_hotel_source_not_readback_verified';
        }
        $sourceMeta = is_array($source['source'] ?? null) ? $source['source'] : [];
        $expectedTable = (string)($pmsSelection['expected_table'] ?? '');
        $provider = trim((string)($sourceMeta['provider'] ?? ''));
        $legacyProviderCompatible = ($pmsSelection['legacy_fixture'] ?? false) === true
            && $provider === '';
        if ($provider !== $pmsSourceKey && !$legacyProviderCompatible) {
            $identityReasons[] = 'whole_hotel_source_provider_mismatch';
        }
        if ($expectedTable === ''
            || (string)($sourceMeta['table'] ?? '') !== $expectedTable
        ) {
            $identityReasons[] = 'whole_hotel_source_table_mismatch';
        }
        if ((string)($sourceMeta['readback_status'] ?? '') !== 'readback_verified') {
            $identityReasons[] = 'whole_hotel_source_readback_status_unverified';
        }

        $facts = is_array($source['facts'] ?? null) ? $source['facts'] : [];
        $statuses = is_array($source['fact_statuses'] ?? null) ? $source['fact_statuses'] : [];
        $components = [];
        foreach ((array)$definition['components'] as $component => $sourceMetric) {
            $allowedStatuses = $sourceMetric === 'sellable_room_nights'
                ? ['readback_verified', 'derived_verified']
                : ($sourceMetric === 'adr' || $sourceMetric === 'revpar'
                    ? ['readback_verified', 'derived_verified']
                    : ['readback_verified']);
            $status = is_array($statuses[$sourceMetric] ?? null) ? $statuses[$sourceMetric] : [];
            $value = $this->number($facts[$sourceMetric] ?? null);
            if (!in_array((string)($status['status'] ?? ''), $allowedStatuses, true)) {
                $identityReasons[] = 'whole_hotel_metric_unverified:' . $sourceMetric;
                continue;
            }
            if ($value === null || $value < 0) {
                $identityReasons[] = 'whole_hotel_metric_value_invalid:' . $sourceMetric;
                continue;
            }
            $components[(string)$component] = $value;
        }
        if (isset($components['sold_room_nights'], $components['sellable_room_nights'])) {
            if ($components['sellable_room_nights'] <= 0
                || $components['sold_room_nights'] > $components['sellable_room_nights']
            ) {
                $identityReasons[] = 'whole_hotel_room_night_denominator_invalid';
            }
        }
        if (isset($components['revenue'], $components['sold_room_nights'])
            && $components['sold_room_nights'] === 0.0
            && $components['revenue'] !== 0.0
        ) {
            $identityReasons[] = 'whole_hotel_zero_sold_room_nights_conflict';
        }
        $evidenceRefs = $this->sourceEvidenceRefs($source, array_values((array)$definition['components']));
        if ($evidenceRefs === []) {
            $identityReasons[] = 'whole_hotel_evidence_refs_missing';
        }

        return [
            'ready' => $identityReasons === [],
            'pms_source_key' => $pmsSourceKey,
            'components' => $components,
            'evidence_refs' => $evidenceRefs,
            'reason_codes' => array_values(array_unique($identityReasons)),
            'source_readbacks' => [[
                'source' => $pmsSourceKey,
                'platform' => null,
                'platform_hotel_id' => trim((string)($sourceMeta['provider_hotel_id'] ?? '')),
                'business_date' => $businessDate,
                'captured_at' => trim((string)($sourceMeta['captured_at'] ?? '')),
                'data_status' => (string)($source['data_status'] ?? 'missing'),
                'readback_status' => (string)($sourceMeta['readback_status'] ?? 'not_verified'),
                'evidence_refs' => $evidenceRefs,
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private function otaDay(
        array $layer,
        int $hotelId,
        int $tenantId,
        string $businessDate,
        array $definition,
        string $platform
    ): array {
        $platforms = $platform === 'combined' ? ['ctrip', 'meituan'] : [$platform];
        $components = [];
        $evidenceRefs = [];
        $reasonCodes = [];
        $sourceReadbacks = [];

        foreach ($platforms as $sourcePlatform) {
            $sourceKey = $sourcePlatform . '_ota';
            $source = is_array($layer['sources'][$sourceKey] ?? null)
                ? $layer['sources'][$sourceKey]
                : [];
            $platformReasons = $this->sourceIdentityReasons(
                $source,
                $hotelId,
                $tenantId,
                $businessDate,
                self::SCOPE_OTA,
                $sourcePlatform
            );
            $sourceMeta = is_array($source['source'] ?? null) ? $source['source'] : [];
            if (!in_array(
                (string)($sourceMeta['readback_status'] ?? ''),
                ['readback_verified', 'partial_readback_verified'],
                true
            )) {
                $platformReasons[] = 'ota_source_readback_status_unverified:' . $sourcePlatform;
            }
            if (($definition['requires_revenue_analysis'] ?? false) === true
                && ($source['analysis_readiness']['allowed'] ?? false) !== true
            ) {
                $platformReasons[] = 'ota_revenue_analysis_blocked:' . $sourcePlatform;
            }

            $facts = is_array($source['facts'] ?? null) ? $source['facts'] : [];
            $statuses = is_array($source['fact_statuses'] ?? null) ? $source['fact_statuses'] : [];
            $sourceComponents = [];
            foreach ((array)$definition['components'] as $component => $sourceMetric) {
                $status = is_array($statuses[$sourceMetric] ?? null) ? $statuses[$sourceMetric] : [];
                $allowedStatuses = $sourceMetric === 'adr'
                    ? ['readback_verified', 'derived_verified']
                    : ['readback_verified'];
                $value = $this->number($facts[$sourceMetric] ?? null);
                if (!in_array((string)($status['status'] ?? ''), $allowedStatuses, true)) {
                    $platformReasons[] = 'ota_metric_unverified:' . $sourcePlatform . ':' . $sourceMetric;
                    continue;
                }
                if ($value === null || $value < 0) {
                    $platformReasons[] = 'ota_metric_value_invalid:' . $sourcePlatform . ':' . $sourceMetric;
                    continue;
                }
                if ($sourceMetric === 'cancellation_rate_percent' && $value > 100) {
                    $platformReasons[] = 'ota_cancellation_rate_invalid:' . $sourcePlatform;
                    continue;
                }
                $sourceComponents[(string)$component] = $value;
            }
            if (isset($sourceComponents['revenue'], $sourceComponents['room_nights'])
                && $sourceComponents['room_nights'] === 0.0
                && $sourceComponents['revenue'] !== 0.0
            ) {
                $platformReasons[] = 'ota_zero_room_nights_conflict:' . $sourcePlatform;
            }
            if (isset($sourceComponents['cancellation_rate'], $sourceComponents['gross_orders'])) {
                $sourceComponents['cancelled_orders'] =
                    $sourceComponents['cancellation_rate'] / 100 * $sourceComponents['gross_orders'];
                unset($sourceComponents['cancellation_rate']);
            }

            $platformEvidence = $this->sourceEvidenceRefs(
                $source,
                array_values((array)$definition['components'])
            );
            if ($platformEvidence === []) {
                $platformReasons[] = 'ota_evidence_refs_missing:' . $sourcePlatform;
            }
            foreach ($sourceComponents as $key => $value) {
                $components[$key] = ($components[$key] ?? 0.0) + $value;
            }
            $evidenceRefs = array_merge($evidenceRefs, $platformEvidence);
            $reasonCodes = array_merge($reasonCodes, $platformReasons);
            $sourceReadbacks[] = [
                'source' => $sourceKey,
                'platform' => $sourcePlatform,
                'platform_hotel_id' => trim((string)(
                    $sourceMeta['platform_hotel_id']
                        ?? $sourceMeta['provider_hotel_id']
                        ?? ''
                )),
                'business_date' => $businessDate,
                'captured_at' => trim((string)($sourceMeta['captured_at'] ?? '')),
                'data_status' => (string)($source['data_status'] ?? 'missing'),
                'readback_status' => (string)($sourceMeta['readback_status'] ?? 'not_verified'),
                'evidence_refs' => $platformEvidence,
            ];
        }

        $evidenceRefs = array_values(array_unique($evidenceRefs));
        sort($evidenceRefs, SORT_STRING);
        return [
            'ready' => $reasonCodes === [],
            'components' => $components,
            'evidence_refs' => $evidenceRefs,
            'reason_codes' => array_values(array_unique($reasonCodes)),
            'source_readbacks' => $sourceReadbacks,
        ];
    }

    /**
     * Longitudinal learning needs a stable, source-derived identity in addition
     * to the numeric value. Never manufacture a provider hotel id or capture
     * time: when the fact layer cannot prove them, leave them empty so the
     * downstream verdict remains indeterminate.
     *
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function snapshotIdentity(
        array $result,
        string $metricScope,
        string $platform,
        int $hotelId
    ): array {
        $platformHotelIds = [];
        $capturedTimes = [];
        $sourceKeys = [];
        foreach ((array)($result['daily_readbacks'] ?? []) as $dailyReadback) {
            if (!is_array($dailyReadback)) {
                continue;
            }
            foreach ((array)($dailyReadback['source_readbacks'] ?? []) as $sourceReadback) {
                if (!is_array($sourceReadback)) {
                    continue;
                }
                $sourceKey = trim((string)($sourceReadback['source'] ?? ''));
                if ($sourceKey !== '') {
                    $sourceKeys[] = $sourceKey;
                }
                $platformHotelId = trim((string)($sourceReadback['platform_hotel_id'] ?? ''));
                if ($platformHotelId !== '') {
                    $platformHotelIds[] = $platformHotelId;
                }
                $capturedAt = trim((string)($sourceReadback['captured_at'] ?? ''));
                if ($capturedAt !== '' && strtotime($capturedAt) !== false) {
                    $capturedTimes[] = $capturedAt;
                }
            }
        }
        $platformHotelIds = array_values(array_unique($platformHotelIds));
        $sourceKeys = array_values(array_unique($sourceKeys));
        $capturedTimes = array_values(array_unique($capturedTimes));
        usort(
            $capturedTimes,
            static fn(string $left, string $right): int =>
                ((int)strtotime($left)) <=> ((int)strtotime($right))
        );

        $wholeHotel = $metricScope === self::SCOPE_WHOLE_HOTEL;
        return [
            'system_hotel_id' => $hotelId,
            'platform' => $wholeHotel
                ? (count($sourceKeys) === 1 ? $sourceKeys[0] : 'pms')
                : $platform,
            'platform_hotel_id' => count($platformHotelIds) === 1
                ? $platformHotelIds[0]
                : '',
            'business_module' => $wholeHotel
                ? 'accommodation_operating'
                : 'ota_channel_operating',
            'subject' => $wholeHotel
                ? 'whole_hotel_accommodation'
                : ($platform === 'combined' ? 'combined_ota_channel' : $platform . '_ota_channel'),
            'date_role' => 'business_date',
            'captured_at' => $capturedTimes === []
                ? ''
                : $capturedTimes[count($capturedTimes) - 1],
        ];
    }

    /** @return array<int,string> */
    private function sourceIdentityReasons(
        array $envelope,
        int $hotelId,
        int $tenantId,
        string $businessDate,
        string $metricScope,
        ?string $platform
    ): array {
        $reasons = [];
        if ((string)($envelope['metric_scope'] ?? '') !== $metricScope) {
            $reasons[] = 'source_metric_scope_mismatch';
        }
        if ((string)($envelope['business_date'] ?? '') !== $businessDate
            || (string)($envelope['actual_business_date'] ?? '') !== $businessDate
        ) {
            $reasons[] = 'source_business_date_mismatch';
        }
        if ($platform !== null && (string)($envelope['platform'] ?? '') !== $platform) {
            $reasons[] = 'source_platform_mismatch:' . $platform;
        }
        $source = is_array($envelope['source'] ?? null) ? $envelope['source'] : [];
        if ((int)($source['tenant_id'] ?? 0) !== $tenantId) {
            $reasons[] = 'source_tenant_mismatch';
        }
        if ((int)($source['system_hotel_id'] ?? 0) !== $hotelId) {
            $reasons[] = 'source_hotel_mismatch';
        }
        $sourceDate = (string)($source['data_date'] ?? '');
        if ($sourceDate !== $businessDate) {
            $reasons[] = 'source_data_date_mismatch';
        }
        if ($platform !== null && (string)($source['platform'] ?? '') !== $platform) {
            $reasons[] = 'source_provenance_platform_mismatch:' . $platform;
        }
        return $reasons;
    }

    /** @return array<int,string> */
    private function sourceEvidenceRefs(array $envelope, array $metricKeys): array
    {
        $source = is_array($envelope['source'] ?? null) ? $envelope['source'] : [];
        $refs = $this->stringList($source['evidence_refs'] ?? []);
        $table = trim((string)($source['table'] ?? ''));
        $recordId = (int)($source['record_id'] ?? 0);
        if ($table !== '' && $recordId > 0) {
            $refs[] = $table . '#' . $recordId;
        }
        foreach ($this->positiveIntList($source['row_ids'] ?? []) as $rowId) {
            if ($table !== '') {
                $refs[] = $table . '#' . $rowId;
            }
        }

        $statuses = is_array($envelope['fact_statuses'] ?? null) ? $envelope['fact_statuses'] : [];
        $operationalProvenance = is_array($source['operational_metric_provenance'] ?? null)
            ? $source['operational_metric_provenance']
            : [];
        foreach (array_values(array_unique($metricKeys)) as $metricKey) {
            $status = is_array($statuses[$metricKey] ?? null) ? $statuses[$metricKey] : [];
            $provenances = [];
            if (is_array($status['source_provenance'] ?? null)) {
                $provenances[] = $status['source_provenance'];
            }
            if (is_array($operationalProvenance[$metricKey] ?? null)) {
                $provenances[] = $operationalProvenance[$metricKey];
            }
            foreach ($provenances as $provenance) {
                $provenanceTable = trim((string)($provenance['table'] ?? $table));
                foreach ($this->positiveIntList($provenance['row_ids'] ?? []) as $rowId) {
                    if ($provenanceTable !== '') {
                        $refs[] = $provenanceTable . '#' . $rowId;
                    }
                }
                foreach ($this->stringList($provenance['evidence_refs'] ?? []) as $ref) {
                    $refs[] = $ref;
                }
            }
        }
        $refs = array_values(array_unique($refs));
        sort($refs, SORT_STRING);
        return $refs;
    }

    /** @return array{0:int|float|null,1:string} */
    private function aggregateValue(array $definition, array $totals): array
    {
        $kind = (string)$definition['aggregation'];
        if ($kind === 'sum') {
            if (!array_key_exists('value', $totals)) {
                return [null, 'metric_sum_input_missing'];
            }
            return [$this->displayNumber((float)$totals['value'], (int)$definition['precision']), ''];
        }
        $numeratorKey = (string)$definition['numerator'];
        $denominatorKey = (string)$definition['denominator'];
        if (!isset($totals[$numeratorKey], $totals[$denominatorKey])) {
            return [null, 'metric_aggregation_input_missing'];
        }
        $denominator = (float)$totals[$denominatorKey];
        if ($denominator <= 0) {
            return [null, 'metric_denominator_zero'];
        }
        $multiplier = (float)($definition['multiplier'] ?? 1.0);
        $value = (float)$totals[$numeratorKey] / $denominator * $multiplier;
        return [$this->displayNumber($value, (int)$definition['precision']), ''];
    }

    /** @return array<string,mixed>|null */
    private function metricDefinition(string $scope, string $metricKey): ?array
    {
        $wholeRevenue = [
            'canonical_key' => 'revenue',
            'unit' => 'CNY',
            'aggregation' => 'sum',
            'components' => ['value' => 'room_revenue'],
            'precision' => 2,
            'formula' => 'sum(verified_daily_room_revenue)',
        ];
        $definitions = [
            self::SCOPE_WHOLE_HOTEL => [
                'revenue' => $wholeRevenue,
                'room_revenue' => $wholeRevenue,
                'adr' => [
                    'canonical_key' => 'adr',
                    'unit' => 'CNY/room_night',
                    'aggregation' => 'ratio',
                    'components' => ['revenue' => 'room_revenue', 'sold_room_nights' => 'sold_room_nights'],
                    'numerator' => 'revenue',
                    'denominator' => 'sold_room_nights',
                    'precision' => 2,
                    'formula' => 'sum(verified_daily_room_revenue) / sum(verified_daily_sold_room_nights)',
                ],
                'occupancy' => $this->wholeOccupancyDefinition(),
                'occupancy_rate' => $this->wholeOccupancyDefinition(),
                'occupancy_rate_percent' => $this->wholeOccupancyDefinition(),
                'revpar' => [
                    'canonical_key' => 'revpar',
                    'unit' => 'CNY/sellable_room_night',
                    'aggregation' => 'ratio',
                    'components' => ['revenue' => 'room_revenue', 'sellable_room_nights' => 'sellable_room_nights'],
                    'numerator' => 'revenue',
                    'denominator' => 'sellable_room_nights',
                    'precision' => 2,
                    'formula' => 'sum(verified_daily_room_revenue) / sum(verified_daily_sellable_room_nights)',
                ],
            ],
            self::SCOPE_OTA => [
                'revenue' => $this->otaRevenueDefinition(),
                'ota_revenue' => $this->otaRevenueDefinition(),
                'orders' => $this->otaSumDefinition('orders', 'count', 0),
                'ota_orders' => $this->otaSumDefinition('orders', 'count', 0),
                'room_nights' => $this->otaSumDefinition('room_nights', 'room_night', 0),
                'ota_room_nights' => $this->otaSumDefinition('room_nights', 'room_night', 0),
                'adr' => $this->otaAdrDefinition(),
                'ota_adr' => $this->otaAdrDefinition(),
                'cancellation_rate' => $this->otaCancellationDefinition(),
                'cancellation_rate_percent' => $this->otaCancellationDefinition(),
                'ota_cancellation_rate_percent' => $this->otaCancellationDefinition(),
            ],
        ];
        return $definitions[$scope][$metricKey] ?? null;
    }

    /** @return array{0:string,1:string} */
    private function scopeContext(string $metricKey, array $context): array
    {
        $baseline = is_array($context['baseline'] ?? null)
            ? $context['baseline']
            : (
                is_array($context['baseline_snapshot'] ?? null)
                    ? $context['baseline_snapshot']
                    : []
            );
        $scope = $this->firstContextText([
            $baseline['fact_scope'] ?? null,
            $baseline['metric_scope'] ?? null,
            $context['fact_scope'] ?? null,
            $context['metric_scope'] ?? null,
        ]);
        $platform = $this->firstContextText([
            $baseline['platform'] ?? null,
            $context['platform'] ?? null,
        ]);
        $scope = strtolower($scope);
        $platform = strtolower($platform);

        if ($scope === '') {
            $otaOnlyMetrics = [
                'ota_revenue',
                'orders',
                'ota_orders',
                'room_nights',
                'ota_room_nights',
                'ota_adr',
                'cancellation_rate',
                'cancellation_rate_percent',
                'ota_cancellation_rate_percent',
            ];
            $scope = in_array($metricKey, $otaOnlyMetrics, true)
                || in_array($platform, ['ctrip', 'meituan', 'combined'], true)
                ? self::SCOPE_OTA
                : self::SCOPE_WHOLE_HOTEL;
        }
        $scope = match ($scope) {
            'whole_hotel', 'pms', self::SCOPE_WHOLE_HOTEL => self::SCOPE_WHOLE_HOTEL,
            'ota', self::SCOPE_OTA => self::SCOPE_OTA,
            default => throw new InvalidArgumentException('operating_goal_snapshot_scope_invalid'),
        };
        if ($platform === '') {
            $platform = $scope === self::SCOPE_OTA ? 'combined' : 'whole_hotel';
        }
        return [$scope, $this->platform($scope, $platform)];
    }

    private function firstContextText(array $values): string
    {
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $value = trim((string)$value);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    /** @return array<string,mixed> */
    private function wholeOccupancyDefinition(): array
    {
        return [
            'canonical_key' => 'occupancy_rate_percent',
            'unit' => 'percent',
            'aggregation' => 'ratio',
            'components' => [
                'sold_room_nights' => 'sold_room_nights',
                'sellable_room_nights' => 'sellable_room_nights',
            ],
            'numerator' => 'sold_room_nights',
            'denominator' => 'sellable_room_nights',
            'multiplier' => 100,
            'precision' => 2,
            'formula' => 'sum(verified_daily_sold_room_nights) / sum(verified_daily_sellable_room_nights) * 100',
        ];
    }

    /** @return array<string,mixed> */
    private function otaRevenueDefinition(): array
    {
        return [
            'canonical_key' => 'revenue',
            'unit' => 'CNY',
            'aggregation' => 'sum',
            'components' => ['value' => 'revenue'],
            'precision' => 2,
            'formula' => 'sum(verified_daily_ota_channel_revenue)',
            'requires_revenue_analysis' => true,
        ];
    }

    /** @return array<string,mixed> */
    private function otaSumDefinition(string $metric, string $unit, int $precision): array
    {
        return [
            'canonical_key' => $metric,
            'unit' => $unit,
            'aggregation' => 'sum',
            'components' => ['value' => $metric],
            'precision' => $precision,
            'formula' => 'sum(verified_daily_ota_channel_' . $metric . ')',
        ];
    }

    /** @return array<string,mixed> */
    private function otaAdrDefinition(): array
    {
        return [
            'canonical_key' => 'adr',
            'unit' => 'CNY/room_night',
            'aggregation' => 'ratio',
            'components' => ['revenue' => 'revenue', 'room_nights' => 'room_nights'],
            'numerator' => 'revenue',
            'denominator' => 'room_nights',
            'precision' => 2,
            'formula' => 'sum(verified_daily_ota_revenue) / sum(verified_daily_ota_room_nights)',
            'requires_revenue_analysis' => true,
        ];
    }

    /** @return array<string,mixed> */
    private function otaCancellationDefinition(): array
    {
        return [
            'canonical_key' => 'cancellation_rate_percent',
            'unit' => 'percent',
            'aggregation' => 'ratio',
            'components' => [
                'cancellation_rate' => 'cancellation_rate_percent',
                'gross_orders' => 'cancellation_gross_order_count',
            ],
            'numerator' => 'cancelled_orders',
            'denominator' => 'gross_orders',
            'multiplier' => 100,
            'precision' => 2,
            'formula' => 'sum(platform_cancel_rate * platform_gross_orders) / sum(platform_gross_orders)',
        ];
    }

    private function platform(string $scope, string $platform): string
    {
        $platform = strtolower(trim($platform));
        if ($scope === self::SCOPE_WHOLE_HOTEL) {
            if (!in_array($platform, ['', 'combined', 'whole_hotel'], true)) {
                throw new InvalidArgumentException('operating_goal_snapshot_platform_scope_conflict');
            }
            return 'whole_hotel';
        }
        if (!in_array($platform, ['combined', 'ctrip', 'meituan'], true)) {
            throw new InvalidArgumentException('operating_goal_snapshot_platform_invalid');
        }
        return $platform;
    }

    /** @return array<int,string> */
    private function dateRange(string $from, string $to): array
    {
        $current = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($to);
        $dates = [];
        while ($current <= $end) {
            $dates[] = $current->format('Y-m-d');
            $current = $current->add(new DateInterval('P1D'));
        }
        return $dates;
    }

    private function date(string $value, string $field): string
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
            || $date->format('Y-m-d') !== $value
        ) {
            throw new InvalidArgumentException('operating_goal_snapshot_' . $field . '_invalid');
        }
        return $value;
    }

    private function metricKey(string $metricKey): string
    {
        $metricKey = strtolower(trim($metricKey));
        if ($metricKey === ''
            || strlen($metricKey) > 80
            || preg_match('/^[a-z0-9][a-z0-9_.:-]*$/D', $metricKey) !== 1
        ) {
            throw new InvalidArgumentException('operating_goal_snapshot_metric_key_invalid');
        }
        return $metricKey;
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float)$value) ? (float)$value : null;
    }

    private function displayNumber(float $value, int $precision): int|float
    {
        $rounded = round($value, $precision);
        if ($precision === 0 && floor($rounded) === $rounded) {
            return (int)$rounded;
        }
        return $rounded;
    }

    /** @return array<int,int> */
    private function positiveIntList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = array_values(array_unique(array_filter(
            array_map('intval', $value),
            static fn(int $item): bool => $item > 0
        )));
        sort($result, SORT_NUMERIC);
        return $result;
    }

    /** @return array<int,string> */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $item = trim((string)$item);
            if ($item !== '') {
                $result[] = $item;
            }
        }
        $result = array_values(array_unique($result));
        sort($result, SORT_STRING);
        return $result;
    }

    /** @return array<string,mixed> */
    private function dayGap(string $businessDate, string $platform, array $reasonCodes): array
    {
        return [
            'business_date' => $businessDate,
            'platform' => $platform === 'whole_hotel' ? null : $platform,
            'reason_codes' => $this->stringList($reasonCodes),
        ];
    }
}
