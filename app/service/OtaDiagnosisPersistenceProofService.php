<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use DateTimeImmutable;
use Throwable;
use think\facade\Db;

/**
 * Read-only proof that an OTA diagnosis was persisted and can be read back
 * inside the exact hotel, platform and requested-date scope.
 */
final class OtaDiagnosisPersistenceProofService
{
    private const SUPPORTED_PLATFORMS = ['ctrip', 'meituan'];

    /** @var Closure(int, int): ?array<string, mixed> */
    private Closure $rowLoader;

    /**
     * @param null|callable(int, int): ?array<string, mixed> $rowLoader
     */
    public function __construct(?callable $rowLoader = null)
    {
        $this->rowLoader = $rowLoader === null
            ? static function (int $recordId, int $systemHotelId): ?array {
                $row = Db::name('agent_logs')
                    ->field('id,hotel_id,action,context_data')
                    ->where('id', $recordId)
                    ->where('hotel_id', $systemHotelId)
                    ->find();

                return is_array($row) ? $row : null;
            }
            : Closure::fromCallable($rowLoader);
    }

    /**
     * @param array<string, mixed> $diagnosis
     * @param array<string, mixed> $scope
     * @return array{
     *     proved: bool,
     *     status: string,
     *     reason_codes: list<string>,
     *     evidence: array<string, mixed>
     * }
     */
    public function verify(array $diagnosis, array $scope): array
    {
        $savedRecord = is_array($diagnosis['saved_record'] ?? null)
            ? $diagnosis['saved_record']
            : [];
        $recordId = $this->positiveInt($savedRecord['id'] ?? null);
        $systemHotelId = $this->positiveInt($scope['system_hotel_id'] ?? null);
        $platform = strtolower(trim((string)($scope['platform'] ?? '')));
        $requestedDateRange = $this->scopeDateRange($scope);
        $externalContent = $this->diagnosisContent($diagnosis, 'external');

        $reasonCodes = $externalContent['reason_codes'];
        if ($recordId <= 0) {
            $reasonCodes[] = 'diagnosis_saved_record_id_missing';
        }
        if (($savedRecord['saved'] ?? null) !== true) {
            $reasonCodes[] = 'diagnosis_external_save_unverified';
        }
        if (($savedRecord['readback_verified'] ?? null) !== true) {
            $reasonCodes[] = 'diagnosis_external_readback_unverified';
        }
        if ($systemHotelId <= 0) {
            $reasonCodes[] = 'system_hotel_scope_missing';
        }
        if (!in_array($platform, self::SUPPORTED_PLATFORMS, true)) {
            $reasonCodes[] = 'platform_scope_missing_or_invalid';
        }
        if ($requestedDateRange === null) {
            $reasonCodes[] = 'requested_date_range_missing_or_invalid';
        }
        if ($reasonCodes !== []) {
            return $this->unverified(
                $reasonCodes,
                $recordId,
                $systemHotelId,
                $platform,
                $requestedDateRange
            );
        }

        try {
            $row = ($this->rowLoader)($recordId, $systemHotelId);
        } catch (Throwable) {
            return $this->unverified(
                ['agent_logs_read_failed'],
                $recordId,
                $systemHotelId,
                $platform,
                $requestedDateRange
            );
        }

        if (!is_array($row)) {
            return $this->unverified(
                ['agent_log_not_found'],
                $recordId,
                $systemHotelId,
                $platform,
                $requestedDateRange,
                false
            );
        }

        if ($this->positiveInt($row['id'] ?? null) !== $recordId) {
            $reasonCodes[] = 'agent_log_record_id_mismatch';
        }
        if ($this->positiveInt($row['hotel_id'] ?? null) !== $systemHotelId) {
            $reasonCodes[] = 'agent_log_hotel_scope_mismatch';
        }
        if (strtolower(trim((string)($row['action'] ?? ''))) !== 'ota_diagnosis') {
            $reasonCodes[] = 'agent_log_action_mismatch';
        }

        $context = $this->decodeContext($row['context_data'] ?? null);
        $contentChecks = $externalContent['checks'];
        if ($context === null) {
            $reasonCodes[] = 'agent_log_context_invalid';
        } else {
            if ((int)($context['schema_version'] ?? 0) !== 1) {
                $reasonCodes[] = 'agent_log_schema_mismatch';
            }
            if (strtolower(trim((string)($context['record_type'] ?? ''))) !== 'ota_diagnosis') {
                $reasonCodes[] = 'agent_log_record_type_mismatch';
            }
            if (strtolower(trim((string)($context['record_status'] ?? ''))) !== 'active') {
                $reasonCodes[] = 'agent_log_record_inactive';
            }
            if (strtolower(trim((string)($context['platform'] ?? ''))) !== $platform) {
                $reasonCodes[] = 'agent_log_platform_scope_mismatch';
            }
            if ($this->normalizeDateRange($context['requested_date_range'] ?? null) !== $requestedDateRange) {
                $reasonCodes[] = 'agent_log_requested_date_range_mismatch';
            }

            $snapshot = is_array($context['diagnosis_result'] ?? null)
                ? $context['diagnosis_result']
                : [];
            $snapshotSavedRecord = is_array($snapshot['saved_record'] ?? null)
                ? $snapshot['saved_record']
                : [];
            if ($snapshot === [] || $snapshotSavedRecord === []) {
                $reasonCodes[] = 'diagnosis_snapshot_saved_record_missing';
            } else {
                if ($this->positiveInt($snapshotSavedRecord['id'] ?? null) !== $recordId) {
                    $reasonCodes[] = 'diagnosis_snapshot_record_id_mismatch';
                }
                if (($snapshotSavedRecord['saved'] ?? null) !== true) {
                    $reasonCodes[] = 'diagnosis_snapshot_save_unverified';
                }
                if (($snapshotSavedRecord['readback_verified'] ?? null) !== true) {
                    $reasonCodes[] = 'diagnosis_snapshot_readback_unverified';
                }
            }

            $snapshotContent = $this->diagnosisContent($snapshot, 'snapshot');
            $reasonCodes = array_merge($reasonCodes, $snapshotContent['reason_codes']);
            $contentChecks = array_merge($contentChecks, $snapshotContent['checks']);
            foreach (array_keys($externalContent['values']) as $field) {
                $matched = array_key_exists($field, $snapshotContent['values'])
                    && $this->canonicalize($externalContent['values'][$field])
                        === $this->canonicalize($snapshotContent['values'][$field]);
                $contentChecks[$field . '_matched'] = $matched;
                if (array_key_exists($field, $snapshotContent['values']) && !$matched) {
                    $reasonCodes[] = 'diagnosis_' . $field . '_mismatch';
                }
            }
            $contentChecks['diagnosis_content_matched'] = $this->allContentChecksMatched($contentChecks);
        }

        if ($reasonCodes !== []) {
            return $this->unverified(
                array_values(array_unique($reasonCodes)),
                $recordId,
                $systemHotelId,
                $platform,
                $requestedDateRange,
                true,
                $contentChecks
            );
        }

        $checks = [
            'record_found' => true,
            'identity_matched' => true,
            'context_contract_matched' => true,
            'snapshot_readback_matched' => true,
            'external_content_fields_present' => true,
            'snapshot_content_fields_present' => true,
            'summary_matched' => true,
            'evidence_sources_matched' => true,
            'data_gaps_matched' => true,
            'action_items_matched' => true,
            'decision_status_matched' => true,
            'diagnosis_content_matched' => true,
        ];

        return [
            'proved' => true,
            'status' => 'proved',
            'reason_codes' => ['diagnosis_persistence_readback_proved'],
            'evidence' => array_merge(
                $this->evidenceScope($recordId, $systemHotelId, $platform, $requestedDateRange),
                ['checks' => $checks]
            ),
        ];
    }

    /** @param array<string, mixed> $scope */
    private function scopeDateRange(array $scope): ?array
    {
        foreach (['requested_date_range', 'target_date_range'] as $key) {
            if (array_key_exists($key, $scope)) {
                return $this->normalizeDateRange($scope[$key]);
            }
        }

        $targetDate = trim((string)($scope['target_date'] ?? ''));
        if (!$this->isDate($targetDate)) {
            return null;
        }

        return ['start_date' => $targetDate, 'end_date' => $targetDate];
    }

    /** @return null|array{start_date: string, end_date: string} */
    private function normalizeDateRange(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $startDate = trim((string)($value['start_date'] ?? ''));
        $endDate = trim((string)($value['end_date'] ?? ''));
        if (!$this->isDate($startDate) || !$this->isDate($endDate) || $startDate > $endDate) {
            return null;
        }

        return ['start_date' => $startDate, 'end_date' => $endDate];
    }

    private function isDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
    }

    /** @return null|array<string, mixed> */
    private function decodeContext(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function positiveInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : 0;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            $normalized = (int)$value;
            return (string)$normalized === $value ? $normalized : 0;
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $diagnosis
     * @param 'external'|'snapshot' $side
     * @return array{
     *     values: array<string, mixed>,
     *     reason_codes: list<string>,
     *     checks: array<string, bool>
     * }
     */
    private function diagnosisContent(array $diagnosis, string $side): array
    {
        $paths = [
            'summary' => [
                ['summary'],
                ['diagnosis', 'summary'],
                ['core_conclusion'],
            ],
            'evidence_sources' => [
                ['evidence_sources'],
                ['diagnosis', 'evidence_sources'],
            ],
            'data_gaps' => [
                ['data_gaps'],
                ['diagnosis', 'data_gaps'],
                ['metrics', 'data_gaps'],
            ],
            'action_items' => [
                ['action_items'],
                ['diagnosis', 'action_items'],
                ['recommended_actions'],
            ],
            'decision_status' => [
                ['decision_status'],
                ['diagnosis', 'decision_status'],
            ],
        ];

        $values = [];
        $reasonCodes = [];
        $checks = [];
        foreach ($paths as $field => $candidatePaths) {
            [$found, $value] = $this->firstPresentPath($diagnosis, $candidatePaths);
            $valid = $found && $this->diagnosisContentFieldTypeValid($field, $value);
            $checks[$side . '_' . $field . '_present'] = $valid;
            if (!$valid) {
                $reasonCodes[] = 'diagnosis_' . $side . '_' . $field . '_missing_or_invalid';
                continue;
            }
            $values[$field] = $value;
        }
        $checks[$side . '_content_fields_present'] = count($values) === count($paths);

        return [
            'values' => $values,
            'reason_codes' => $reasonCodes,
            'checks' => $checks,
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @param list<list<string>> $paths
     * @return array{0: bool, 1: mixed}
     */
    private function firstPresentPath(array $source, array $paths): array
    {
        foreach ($paths as $path) {
            $cursor = $source;
            foreach ($path as $segment) {
                if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                    continue 2;
                }
                $cursor = $cursor[$segment];
            }
            return [true, $cursor];
        }

        return [false, null];
    }

    private function diagnosisContentFieldTypeValid(string $field, mixed $value): bool
    {
        if (in_array($field, ['summary', 'decision_status'], true)) {
            return is_string($value);
        }

        return is_array($value);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn(mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    /** @param array<string, bool> $checks */
    private function allContentChecksMatched(array $checks): bool
    {
        foreach ([
            'external_content_fields_present',
            'snapshot_content_fields_present',
            'summary_matched',
            'evidence_sources_matched',
            'data_gaps_matched',
            'action_items_matched',
            'decision_status_matched',
        ] as $key) {
            if (($checks[$key] ?? false) !== true) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $reasonCodes
     * @param null|array{start_date: string, end_date: string} $requestedDateRange
     * @return array{proved: false, status: string, reason_codes: list<string>, evidence: array<string, mixed>}
     */
    private function unverified(
        array $reasonCodes,
        int $recordId,
        int $systemHotelId,
        string $platform,
        ?array $requestedDateRange,
        ?bool $recordFound = null,
        array $checks = []
    ): array {
        $evidence = $this->evidenceScope(
            $recordId,
            $systemHotelId,
            $platform,
            $requestedDateRange
        );
        if ($recordFound !== null) {
            $checks = array_merge(['record_found' => $recordFound], $checks);
        }
        if ($checks !== []) {
            $evidence['checks'] = array_map(static fn(mixed $value): bool => $value === true, $checks);
        }

        return [
            'proved' => false,
            'status' => 'unverified',
            'reason_codes' => array_values(array_unique($reasonCodes)),
            'evidence' => $evidence,
        ];
    }

    /**
     * Never include context_data, message, user_id, or any model response here.
     *
     * @param null|array{start_date: string, end_date: string} $requestedDateRange
     * @return array<string, mixed>
     */
    private function evidenceScope(
        int $recordId,
        int $systemHotelId,
        string $platform,
        ?array $requestedDateRange
    ): array {
        return [
            'source_table' => 'agent_logs',
            'record_id' => $recordId > 0 ? $recordId : null,
            'system_hotel_id' => $systemHotelId > 0 ? $systemHotelId : null,
            'platform' => in_array($platform, self::SUPPORTED_PLATFORMS, true) ? $platform : null,
            'requested_date_range' => $requestedDateRange,
        ];
    }
}
