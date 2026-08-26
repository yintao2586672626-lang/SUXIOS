<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

/**
 * Adapts the existing operating-question strict readback contract into the
 * minimal field shape required by a trusted broadcast. Candidate IDs come from
 * the same hotel/platform/date ledger, but values are accepted only when
 * OperatingQuestionService re-reads and returns the exact refs as verified.
 */
final class AiDailyReportBroadcastFactService
{
    private const PLATFORMS = ['ctrip', 'meituan'];
    private const BROADCAST_METRICS = [
        'exposure' => ['list_exposure'],
        'visits' => ['detail_exposure'],
        'conversion' => ['flow_rate'],
    ];
    private const ALL_FIELDS = [
        'revenue',
        'order_count',
        'room_nights',
        'adr',
        'exposure',
        'visits',
        'conversion',
        'cancellation',
        'sellable',
        'bookable',
        'data_date',
        'collected_at',
        'source_record_id',
    ];

    /** @var Closure(int):array<string,mixed>|null */
    private Closure $hotelReader;

    /** @var Closure(int,int,string,string):list<string> */
    private Closure $candidateRefReader;

    /** @var Closure(int,int,string,string,string,list<string>):list<array<string,mixed>> */
    private Closure $strictFactReader;

    /** @var Closure(int,string):array<string,mixed>|null */
    private ?Closure $closureReader;

    public function __construct(
        ?callable $hotelReader = null,
        ?callable $candidateRefReader = null,
        ?callable $strictFactReader = null,
        ?callable $closureReader = null
    ) {
        $this->hotelReader = $hotelReader !== null
            ? Closure::fromCallable($hotelReader)
            : static fn(int $hotelId): ?array => Db::name('hotels')
                ->where('id', $hotelId)
                ->field('id,tenant_id,name')
                ->find();
        $this->candidateRefReader = $candidateRefReader !== null
            ? Closure::fromCallable($candidateRefReader)
            : static function (
                int $tenantId,
                int $hotelId,
                string $platform,
                string $businessDate
            ): array {
                $ids = Db::name('online_daily_data')
                    ->where('tenant_id', $tenantId)
                    ->where('system_hotel_id', $hotelId)
                    ->where('platform', $platform)
                    ->where('data_date', $businessDate)
                    ->where('history_status', 'success')
                    ->where('validation_status', 'verified')
                    ->where('readback_verified', 1)
                    ->order('id', 'asc')
                    ->column('id');
                return array_map(
                    static fn(mixed $id): string => 'online_daily_data#' . (int)$id,
                    array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0))
                );
            };
        $this->strictFactReader = $strictFactReader !== null
            ? Closure::fromCallable($strictFactReader)
            : static fn(
                int $tenantId,
                int $hotelId,
                string $platform,
                string $dateStart,
                string $dateEnd,
                array $refs
            ): array => (new OperatingQuestionService())->readCurrentVerifiedFactsForRefs(
                $tenantId,
                $hotelId,
                $platform,
                $dateStart,
                $dateEnd,
                $refs
            );
        $this->closureReader = $closureReader !== null
            ? Closure::fromCallable($closureReader)
            : ($hotelReader === null && $candidateRefReader === null && $strictFactReader === null
                ? static fn(int $hotelId, string $businessDate): array =>
                    (new DualOtaFieldClosureService())->build($hotelId, $businessDate)
                : null);
    }

    /** @return array<string,mixed> */
    public function build(int $hotelId, string $businessDate): array
    {
        $businessDate = $this->date($businessDate);
        if ($hotelId <= 0) {
            throw new InvalidArgumentException('AI daily report broadcast hotel is invalid');
        }
        $hotel = ($this->hotelReader)($hotelId);
        $tenantId = (int)($hotel['tenant_id'] ?? 0);
        if (!is_array($hotel)
            || (int)($hotel['id'] ?? 0) !== $hotelId
            || $tenantId <= 0
        ) {
            throw new RuntimeException('AI daily report broadcast hotel scope not found', 404);
        }
        if ($this->closureReader !== null) {
            return $this->projectCanonicalClosure(
                ($this->closureReader)($hotelId, $businessDate),
                $tenantId,
                $hotelId,
                $businessDate
            );
        }

        $platforms = [];
        foreach (self::PLATFORMS as $platform) {
            $candidateRefs = ($this->candidateRefReader)(
                $tenantId,
                $hotelId,
                $platform,
                $businessDate
            );
            $candidateRefs = $this->refs($candidateRefs);
            $strictFacts = $candidateRefs === []
                ? []
                : ($this->strictFactReader)(
                    $tenantId,
                    $hotelId,
                    $platform,
                    $businessDate,
                    $businessDate,
                    $candidateRefs
                );
            $platforms[$platform] = $this->platformContract(
                $platform,
                $businessDate,
                $candidateRefs,
                is_array($strictFacts) ? $strictFacts : []
            );
        }

        return [
            'contract_version' => 'ai_daily_report_broadcast_strict_facts.v1',
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'status' => 'partial',
            'strict_gate' => 'OperatingQuestionService.readCurrentVerifiedFactsForRefs',
            'analysis_status' => 'analysis_blocked',
            'metric_values_recalculated' => false,
            'platforms' => $platforms,
        ];
    }

    /** @param array<string,mixed> $closure @return array<string,mixed> */
    private function projectCanonicalClosure(
        array $closure,
        int $tenantId,
        int $hotelId,
        string $businessDate
    ): array {
        if ((string)($closure['contract_version'] ?? '') !== 'dual_ota_field_closure.v1'
            || (int)($closure['tenant_id'] ?? 0) !== $tenantId
            || (int)($closure['hotel_id'] ?? 0) !== $hotelId
            || (string)($closure['business_date'] ?? '') !== $businessDate
            || (string)($closure['consumer_contract']['contract_version'] ?? '')
                !== 'trusted_ota_daily_fact_consumer.v1'
        ) {
            throw new RuntimeException('AI daily report broadcast field closure scope mismatch', 422);
        }

        $platforms = [];
        foreach (self::PLATFORMS as $platform) {
            $source = is_array($closure['platforms'][$platform] ?? null)
                ? $closure['platforms'][$platform]
                : [];
            $fieldMap = [];
            foreach ((array)($source['fields'] ?? []) as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $key = trim((string)($field['metric_key'] ?? $field['key'] ?? ''));
                if ($key !== '') {
                    $fieldMap[$key] = $field;
                }
            }
            foreach (self::ALL_FIELDS as $fieldKey) {
                if (!isset($fieldMap[$fieldKey])) {
                    $fieldMap[$fieldKey] = [
                        'key' => $fieldKey,
                        'metric_key' => $fieldKey,
                        'status' => 'source_missing',
                        'value' => null,
                        'source_record_refs' => [],
                        'revenue_analysis_consumable' => false,
                    ];
                }
            }
            $acceptedRefs = [];
            foreach ($fieldMap as $field) {
                if (($field['revenue_analysis_consumable'] ?? false) === true) {
                    $acceptedRefs = array_merge(
                        $acceptedRefs,
                        (array)($field['source_record_refs'] ?? [])
                    );
                }
            }
            $latest = is_array($source['latest_collection'] ?? null)
                ? $source['latest_collection']
                : [];
            $platforms[$platform] = array_replace($source, [
                'platform_status' => (string)($latest['platform_status'] ?? 'unverified'),
                'target_date_status' => (string)($latest['target_date_status'] ?? 'unverified'),
                'exact_run_readback_status' => (string)($latest['exact_run_readback_status'] ?? 'unverified'),
                'candidate_record_refs' => $this->refs($source['current_receipt_all_record_refs'] ?? []),
                'accepted_record_refs' => $this->refs($acceptedRefs),
                'fields' => $fieldMap,
                'broadcast_metric_count' => count(array_filter(
                    array_keys(self::BROADCAST_METRICS),
                    static fn(string $fieldKey): bool =>
                        ($fieldMap[$fieldKey]['revenue_analysis_consumable'] ?? false) === true
                )),
            ]);
        }

        return [
            'contract_version' => 'ai_daily_report_broadcast_strict_facts.v2',
            'source_contract_version' => 'dual_ota_field_closure.v1',
            'consumer_contract_version' => 'trusted_ota_daily_fact_consumer.v1',
            'source_closure_identity' => (string)($closure['page_identity'] ?? ''),
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'status' => (string)($closure['status'] ?? 'partial'),
            'strict_gate' => 'dual_ota_field_closure.revenue_analysis_consumable=true',
            'analysis_status' => (string)($closure['status'] ?? '') === 'ready'
                ? 'analysis_ready'
                : 'analysis_blocked',
            'metric_values_recalculated' => false,
            'platforms' => $platforms,
        ];
    }

    /**
     * @param list<string> $candidateRefs
     * @param list<array<string,mixed>> $strictFacts
     * @return array<string,mixed>
     */
    private function platformContract(
        string $platform,
        string $businessDate,
        array $candidateRefs,
        array $strictFacts
    ): array {
        $accepted = [];
        foreach ($strictFacts as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            $ref = trim((string)($fact['ref'] ?? ''));
            if (!in_array($ref, $candidateRefs, true)
                || (string)($fact['platform'] ?? '') !== $platform
                || substr((string)($fact['data_date'] ?? ''), 0, 10) !== $businessDate
                || (string)($fact['quality_status'] ?? '') !== 'verified'
                || (string)($fact['history_status'] ?? '') !== 'success'
                || (string)($fact['readback_status'] ?? '') !== 'readback_verified'
            ) {
                continue;
            }
            $accepted[] = $fact;
        }

        $fields = [];
        foreach (self::ALL_FIELDS as $field) {
            $fields[$field] = [
                'status' => 'missing',
                'value' => null,
                'source_record_refs' => [],
                'revenue_analysis_consumable' => false,
            ];
        }
        foreach (self::BROADCAST_METRICS as $field => $metricKeys) {
            $resolved = $this->resolveMetric($accepted, $metricKeys);
            if ($resolved !== null) {
                $fields[$field] = [
                    'status' => 'strict_readback',
                    'value' => $resolved['value'],
                    'source_record_refs' => $resolved['refs'],
                    'revenue_analysis_consumable' => true,
                    'source_metric_keys' => $resolved['metric_keys'],
                    'strict_gate' => 'history_success+validation_verified+readback_verified',
                ];
            }
        }
        $acceptedRefs = $this->refs(array_column($accepted, 'ref'));
        $collectedAt = $this->latestCollectedAt($accepted);
        if ($accepted !== []) {
            $fields['data_date'] = [
                'status' => 'strict_readback',
                'value' => $businessDate,
                'source_record_refs' => $acceptedRefs,
                'revenue_analysis_consumable' => false,
            ];
            $fields['source_record_id'] = [
                'status' => 'strict_readback',
                'value' => $acceptedRefs,
                'source_record_refs' => $acceptedRefs,
                'revenue_analysis_consumable' => false,
            ];
        }
        if ($collectedAt !== null) {
            $fields['collected_at'] = [
                'status' => 'strict_readback',
                'value' => $collectedAt,
                'source_record_refs' => $acceptedRefs,
                'revenue_analysis_consumable' => false,
            ];
        }

        $coreReady = count(array_filter(
            self::BROADCAST_METRICS,
            static fn(array $metricKeys, string $field): bool =>
                (string)($fields[$field]['status'] ?? '') === 'strict_readback',
            ARRAY_FILTER_USE_BOTH
        ));

        return [
            'identity_status' => $accepted === [] ? 'missing' : 'verified',
            'status' => $accepted === [] ? 'partial' : 'partial',
            'platform_status' => $accepted === [] ? 'missing' : 'verified',
            'target_date_status' => $accepted === [] ? 'missing' : 'matched',
            'exact_run_readback_status' => $accepted === [] ? 'missing' : 'verified',
            'candidate_record_refs' => $candidateRefs,
            'accepted_record_refs' => $acceptedRefs,
            'revenue_analysis' => [
                'status' => 'blocked',
                'consumable_fields' => array_keys(array_filter(
                    $fields,
                    static fn(array $field): bool => ($field['revenue_analysis_consumable'] ?? false) === true
                )),
                'blocked_reason' => 'dual_platform_same_caliber_revenue_and_competition_facts_incomplete',
            ],
            'fields' => $fields,
            'broadcast_metric_count' => $coreReady,
        ];
    }

    /**
     * @param list<array<string,mixed>> $facts
     * @param list<string> $metricKeys
     * @return array{value:int|float,refs:list<string>,metric_keys:list<string>}|null
     */
    private function resolveMetric(array $facts, array $metricKeys): ?array
    {
        $values = [];
        foreach ($facts as $fact) {
            $metrics = is_array($fact['metric_values'] ?? null) ? $fact['metric_values'] : [];
            foreach ($metricKeys as $metricKey) {
                $value = $this->number($metrics[$metricKey] ?? null);
                if ($value === null) {
                    continue;
                }
                $values[] = [
                    'value' => $value,
                    'ref' => (string)$fact['ref'],
                    'metric_key' => $metricKey,
                ];
            }
        }
        if ($values === []) {
            return null;
        }
        $uniqueValues = array_values(array_unique(array_map(
            static fn(array $row): string => (string)$row['value'],
            $values
        )));
        if (count($uniqueValues) !== 1) {
            return null;
        }
        return [
            'value' => $values[0]['value'],
            'refs' => $this->refs(array_column($values, 'ref')),
            'metric_keys' => array_values(array_unique(array_column($values, 'metric_key'))),
        ];
    }

    /** @param list<array<string,mixed>> $facts */
    private function latestCollectedAt(array $facts): ?string
    {
        $values = [];
        foreach ($facts as $fact) {
            $value = trim((string)($fact['collected_at'] ?? ''));
            $dateTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
            if ($dateTime instanceof DateTimeImmutable
                && $dateTime->format('Y-m-d H:i:s') === $value
            ) {
                $values[] = $value;
            }
        }
        sort($values, SORT_STRING);
        return $values === [] ? null : end($values);
    }

    private function number(mixed $value): int|float|null
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return null;
        }
        $number = (float)$value;
        if (!is_finite($number)) {
            return null;
        }
        return floor($number) === $number ? (int)$number : $number;
    }

    /** @return list<string> */
    private function refs(mixed $values): array
    {
        $values = is_array($values) ? $values : [$values];
        $refs = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            $values
        ), static fn(string $ref): bool => preg_match('/^online_daily_data#[1-9][0-9]*$/D', $ref) === 1)));
        sort($refs, SORT_NATURAL);
        return $refs;
    }

    private function date(string $value): string
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('AI daily report broadcast business date is invalid');
        }
        return $value;
    }
}
