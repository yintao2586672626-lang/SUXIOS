<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/**
 * Read-only exposure-user reference estimation from canonical strict facts.
 * The result never changes the platform fact state and is never decision-safe.
 */
final class OtaExposureEstimationReferenceService
{
    public const CONTRACT_VERSION = 'ota_exposure_estimation_reference.v1';
    private const MIN_VERIFIED_PAIRS = 7;
    private const WINDOW_DAYS = 14;

    /** @var Closure(int,string):array<string,mixed> */
    private Closure $closureReader;

    /** @param null|Closure(int,string):array<string,mixed> $closureReader */
    public function __construct(?Closure $closureReader = null)
    {
        $this->closureReader = $closureReader
            ?? static fn(int $hotelId, string $businessDate): array =>
                (new DualOtaFieldClosureService())->build($hotelId, $businessDate);
    }

    /** @return array<string,mixed> */
    public function estimate(int $tenantId, int $hotelId, string $platform, string $targetDate): array
    {
        $platform = strtolower(trim($platform));
        if ($tenantId <= 0 || $hotelId <= 0 || !in_array($platform, ['ctrip', 'meituan'], true)) {
            throw new InvalidArgumentException('ota_exposure_estimation_scope_invalid');
        }
        $target = $this->date($targetDate);
        $targetClosure = $this->readClosure($hotelId, $targetDate);
        $targetVisit = $this->strictField($targetClosure, $tenantId, $hotelId, $platform, $targetDate, 'visits');
        $targetExposure = $this->strictField($targetClosure, $tenantId, $hotelId, $platform, $targetDate, 'exposure');
        if ($targetExposure !== null) {
            return $this->result('fact_already_available', $tenantId, $hotelId, $platform, $targetDate, [
                'estimate' => null,
                'accepted_verified_pairs' => 0,
                'source_refs' => $targetExposure['refs'],
                'reason_code' => 'target_exposure_already_available',
                'reason' => '目标日已经存在严格回读的曝光人数事实，不允许用估算覆盖。',
            ]);
        }
        if ($targetVisit === null) {
            return $this->result('not_calculable', $tenantId, $hotelId, $platform, $targetDate, [
                'estimate' => null,
                'accepted_verified_pairs' => 0,
                'source_refs' => [],
                'reason_code' => 'target_detail_visitors_missing',
                'reason' => '目标日缺少同口径、单位为 users 的严格详情访客事实。',
            ]);
        }

        $pairs = [];
        for ($offset = self::WINDOW_DAYS; $offset >= 1; $offset--) {
            $date = $target->modify('-' . $offset . ' days')->format('Y-m-d');
            $closure = $this->readClosure($hotelId, $date);
            $visits = $this->strictField($closure, $tenantId, $hotelId, $platform, $date, 'visits');
            $exposure = $this->strictField($closure, $tenantId, $hotelId, $platform, $date, 'exposure');
            if ($visits === null || $exposure === null || (int)$visits['value'] <= 0 || (int)$exposure['value'] <= 0) {
                continue;
            }
            $sharedRefs = array_values(array_intersect($visits['refs'], $exposure['refs']));
            if ($sharedRefs === [] || $visits['scope_key'] !== $exposure['scope_key']) {
                continue;
            }
            $pairs[] = [
                'business_date' => $date,
                'detail_visitors' => (int)$visits['value'],
                'exposure_users' => (int)$exposure['value'],
                'multiplier' => (int)$exposure['value'] / (int)$visits['value'],
                'source_refs' => $sharedRefs,
            ];
        }
        $sourceRefs = array_values(array_unique(array_merge(
            $targetVisit['refs'],
            ...array_map(static fn(array $pair): array => $pair['source_refs'], $pairs)
        )));
        if (count($pairs) < self::MIN_VERIFIED_PAIRS) {
            return $this->result('insufficient_baseline', $tenantId, $hotelId, $platform, $targetDate, [
                'estimate' => null,
                'accepted_verified_pairs' => count($pairs),
                'source_refs' => $sourceRefs,
                'reason_code' => 'verified_pair_baseline_insufficient',
                'reason' => sprintf(
                    '同酒店、同平台、同口径严格配对只有 %d 天，至少需要 %d 天；没有套用默认倍数。',
                    count($pairs),
                    self::MIN_VERIFIED_PAIRS
                ),
            ]);
        }
        $multipliers = array_column($pairs, 'multiplier');
        sort($multipliers, SORT_NUMERIC);
        $middle = intdiv(count($multipliers), 2);
        $median = count($multipliers) % 2 === 1
            ? (float)$multipliers[$middle]
            : ((float)$multipliers[$middle - 1] + (float)$multipliers[$middle]) / 2;
        $estimate = (int)round((int)$targetVisit['value'] * $median);
        return $this->result('estimated', $tenantId, $hotelId, $platform, $targetDate, [
            'estimate' => [
                'value' => $estimate,
                'unit' => 'users',
                'formula' => 'round(detail_visitors × median(exposure_users / detail_visitors))',
                'target_detail_visitors' => (int)$targetVisit['value'],
                'median_multiplier' => round($median, 12),
                'interval' => null,
                'interval_reason' => 'rolling_error_observations_not_calculated_in_runtime_reference',
            ],
            'accepted_verified_pairs' => count($pairs),
            'baseline_dates' => array_column($pairs, 'business_date'),
            'source_refs' => $sourceRefs,
            'reason_code' => 'reference_estimate_only',
            'reason' => '该值是严格事实驱动的参考估算，不是平台曝光事实，也不是统计置信区间。',
        ]);
    }

    /** @return array<string,mixed> */
    private function readClosure(int $hotelId, string $businessDate): array
    {
        try {
            $closure = ($this->closureReader)($hotelId, $businessDate);
        } catch (\Throwable $error) {
            throw new RuntimeException(
                'ota_exposure_estimation_closure_read_failed',
                0,
                $error
            );
        }
        if (!is_array($closure)) {
            throw new RuntimeException('ota_exposure_estimation_closure_read_invalid');
        }
        return $closure;
    }

    /** @return null|array{value:int|float,refs:list<string>,scope_key:string} */
    private function strictField(
        array $closure,
        int $tenantId,
        int $hotelId,
        string $platform,
        string $businessDate,
        string $kind
    ): ?array {
        if ((string)($closure['contract_version'] ?? '') !== 'dual_ota_field_closure.v1'
            || (string)($closure['consumer_contract']['contract_version'] ?? '') !== 'trusted_ota_daily_fact_consumer.v1'
            || (int)($closure['tenant_id'] ?? 0) !== $tenantId
            || (int)($closure['hotel_id'] ?? 0) !== $hotelId
            || (string)($closure['business_date'] ?? '') !== $businessDate
        ) {
            return null;
        }
        $platformRow = is_array($closure['platforms'][$platform] ?? null)
            ? $closure['platforms'][$platform]
            : [];
        $allowedKeys = $kind === 'exposure' ? ['exposure_users', 'exposure'] : ['detail_visitors', 'visits'];
        foreach ((array)($platformRow['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }
            $key = strtolower(trim((string)($field['metric_key'] ?? $field['key'] ?? '')));
            $unit = strtolower(trim((string)($field['unit'] ?? '')));
            $refs = array_values(array_filter(array_map('strval', (array)($field['source_record_refs'] ?? []))));
            $history = array_map('strval', (array)($field['history_statuses'] ?? []));
            $sourcePaths = array_values(array_filter(array_map('strval', (array)($field['source_paths'] ?? []))));
            if (!in_array($key, $allowedKeys, true)
                || !in_array($unit, ['users', 'people'], true)
                || !is_numeric($field['value'] ?? null)
                || (string)($field['status'] ?? '') !== 'strict_readback'
                || (string)($field['validation_status'] ?? '') !== 'verified'
                || !in_array('success', $history, true)
                || (string)($field['readback_status'] ?? '') !== 'readback_verified'
                || ($field['strict_final_gate'] ?? false) !== true
                || ($field['revenue_analysis_consumable'] ?? false) !== true
                || $refs === []
                || $sourcePaths === []
            ) {
                continue;
            }
            sort($refs, SORT_STRING);
            return [
                'value' => 0 + $field['value'],
                'refs' => $refs,
                'scope_key' => hash('sha256', json_encode([
                    $platform,
                    $businessDate,
                    $refs,
                    (string)($field['capture_ref'] ?? ''),
                    (int)($field['data_source_id'] ?? 0),
                    array_values(array_map('intval', (array)($field['sync_task_ids'] ?? []))),
                    (string)($field['collected_at'] ?? ''),
                    trim((string)($field['metric_definition_version'] ?? ''))
                        ?: 'dual_ota_field_closure.v1',
                    trim((string)($field['cumulative_cutoff'] ?? ''))
                        ?: 'current_receipt_snapshot',
                    'Asia/Shanghai',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            ];
        }
        return null;
    }

    private function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value), new DateTimeZone('Asia/Shanghai'));
        if (!$date || $date->format('Y-m-d') !== trim($value)) {
            throw new InvalidArgumentException('ota_exposure_estimation_date_invalid');
        }
        return $date;
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function result(
        string $status,
        int $tenantId,
        int $hotelId,
        string $platform,
        string $targetDate,
        array $extra
    ): array {
        return array_replace([
            'contract_version' => self::CONTRACT_VERSION,
            'status' => $status,
            'scope' => [
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'platform' => $platform,
                'business_date' => $targetDate,
                'window_days' => self::WINDOW_DAYS,
                'min_verified_pairs' => self::MIN_VERIFIED_PAIRS,
            ],
            'evidence_type' => $status === 'estimated' ? 'derived_estimate' : 'none',
            'quality_status' => $status === 'estimated' ? 'estimate_only' : $status,
            'decision_eligible' => false,
            'writeback_allowed' => false,
            'platform_fact_status' => 'unchanged',
            'external_write_count' => 0,
        ], $extra);
    }
}
