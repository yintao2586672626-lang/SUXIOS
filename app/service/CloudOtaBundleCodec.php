<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;

/**
 * Versioned, credential-free transport contract for moving already collected
 * OTA facts from the local workstation to the cloud inbox.
 */
final class CloudOtaBundleCodec
{
    public const CONTRACT_VERSION = 'suxios.cloud_ota_bundle.v1';
    public const BINDING_VERSION = 'suxios.cloud_ota_binding.v1';
    public const MAX_FILE_BYTES = 10_485_760;
    public const MAX_ROWS = 5_000;

    private const ALLOWED_PLATFORMS = ['ctrip', 'meituan'];
    private const TRUSTED_VALIDATION_STATUSES = [
        'normal', 'available', 'verified', 'ok', 'success', 'complete', 'completed', 'readback_verified',
    ];
    private const COLLECTION_STATUSES = [
        'success', 'partial', 'target_date_missing', 'login_expired', 'failed', 'not_collected',
    ];
    private const ROW_FIELDS = [
        'tenant_id', 'system_hotel_id', 'data_source_id', 'hotel_id', 'hotel_name',
        'data_date', 'source', 'platform', 'data_type', 'dimension', 'compare_type',
        'amount', 'quantity', 'book_order_num', 'comment_score', 'qunar_comment_score',
        'data_value', 'list_exposure', 'detail_exposure', 'flow_rate',
        'order_filling_num', 'order_submit_num', 'data_period', 'snapshot_time',
        'snapshot_bucket', 'is_final', 'validation_status', 'validation_flags',
        'source_trace_id', 'readback_verified', 'readback_verified_at', 'create_time', 'update_time',
    ];

    /**
     * @param array<string, mixed> $context
     * @param array<int, array<string, mixed>> $packages
     * @return array<string, mixed>
     */
    public static function build(array $context, array $packages, ?string $createdAt = null): array
    {
        $bundle = [
            'contract_version' => self::CONTRACT_VERSION,
            'created_at' => self::normalizeDateTime($createdAt ?? date('Y-m-d H:i:s')),
            'metric_scope' => 'ota_channel',
            'source_system_hotel_id' => self::positiveInt($context['source_system_hotel_id'] ?? 0, 'source_system_hotel_id'),
            'destination_system_hotel_id' => self::positiveInt($context['destination_system_hotel_id'] ?? 0, 'destination_system_hotel_id'),
            'target_date' => self::date((string)($context['target_date'] ?? '')),
            'required_platforms' => self::platforms((array)($context['required_platforms'] ?? self::ALLOWED_PLATFORMS)),
            'packages' => self::normalizePackages($packages, (int)($context['source_system_hotel_id'] ?? 0), (string)($context['target_date'] ?? '')),
            'boundary' => 'Credential-free OTA-channel facts only; missing or unverified data is never converted to zero.',
        ];
        $bundle['payload_sha256'] = hash('sha256', self::canonicalJson($bundle['packages']));
        $bundle['bundle_id'] = hash('sha256', self::canonicalJson(self::identityPayload($bundle)));

        return self::verify($bundle);
    }

    /** @param array<string, mixed> $bundle @return array<string, mixed> */
    public static function verify(array $bundle): array
    {
        if (($bundle['contract_version'] ?? '') !== self::CONTRACT_VERSION) {
            throw new RuntimeException('cloud_bundle_contract_version_invalid');
        }
        if (($bundle['metric_scope'] ?? '') !== 'ota_channel') {
            throw new RuntimeException('cloud_bundle_metric_scope_invalid');
        }

        $sourceHotelId = self::positiveInt($bundle['source_system_hotel_id'] ?? 0, 'source_system_hotel_id');
        $destinationHotelId = self::positiveInt($bundle['destination_system_hotel_id'] ?? 0, 'destination_system_hotel_id');
        $targetDate = self::date((string)($bundle['target_date'] ?? ''));
        $createdAt = self::normalizeDateTime((string)($bundle['created_at'] ?? ''));
        $requiredPlatforms = self::platforms((array)($bundle['required_platforms'] ?? []));
        $packages = self::normalizePackages(
            is_array($bundle['packages'] ?? null) ? $bundle['packages'] : [],
            $sourceHotelId,
            $targetDate
        );
        if ($packages === []) {
            throw new RuntimeException('cloud_bundle_packages_missing');
        }

        $presentPlatforms = array_values(array_unique(array_map(
            static fn(array $package): string => (string)$package['platform'],
            $packages
        )));
        foreach ($requiredPlatforms as $platform) {
            if (!in_array($platform, $presentPlatforms, true)) {
                throw new RuntimeException('cloud_bundle_required_platform_package_missing:' . $platform);
            }
        }

        $normalized = [
            'contract_version' => self::CONTRACT_VERSION,
            'created_at' => $createdAt,
            'metric_scope' => 'ota_channel',
            'source_system_hotel_id' => $sourceHotelId,
            'destination_system_hotel_id' => $destinationHotelId,
            'target_date' => $targetDate,
            'required_platforms' => $requiredPlatforms,
            'packages' => $packages,
            'boundary' => 'Credential-free OTA-channel facts only; missing or unverified data is never converted to zero.',
        ];
        $expectedPayloadHash = hash('sha256', self::canonicalJson($packages));
        $providedPayloadHash = strtolower(trim((string)($bundle['payload_sha256'] ?? '')));
        if (!self::validSha256($providedPayloadHash) || !hash_equals($expectedPayloadHash, $providedPayloadHash)) {
            throw new RuntimeException('cloud_bundle_payload_sha256_mismatch');
        }
        $normalized['payload_sha256'] = $expectedPayloadHash;

        $expectedBundleId = hash('sha256', self::canonicalJson(self::identityPayload($normalized)));
        $providedBundleId = strtolower(trim((string)($bundle['bundle_id'] ?? '')));
        if (!self::validSha256($providedBundleId) || !hash_equals($expectedBundleId, $providedBundleId)) {
            throw new RuntimeException('cloud_bundle_id_mismatch');
        }
        $normalized['bundle_id'] = $expectedBundleId;

        return $normalized;
    }

    /** @param array<string, mixed> $binding @return array<string, mixed> */
    public static function verifyBinding(array $binding): array
    {
        if (($binding['contract_version'] ?? '') !== self::BINDING_VERSION) {
            throw new RuntimeException('cloud_binding_contract_version_invalid');
        }
        $sourceHotelId = self::positiveInt($binding['source_system_hotel_id'] ?? 0, 'source_system_hotel_id');
        $destinationHotelId = self::positiveInt($binding['destination_system_hotel_id'] ?? 0, 'destination_system_hotel_id');
        $rows = is_array($binding['bindings'] ?? null) ? $binding['bindings'] : [];
        if ($rows === []) {
            throw new RuntimeException('cloud_binding_rows_missing');
        }

        $normalized = [];
        $keys = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('cloud_binding_row_invalid');
            }
            $platform = self::platform((string)($row['platform'] ?? ''));
            $sourceDataSourceId = self::positiveInt($row['source_data_source_id'] ?? 0, 'source_data_source_id');
            $destinationDataSourceId = self::positiveInt($row['destination_data_source_id'] ?? 0, 'destination_data_source_id');
            $key = $platform . ':' . $sourceDataSourceId . ':' . $destinationDataSourceId;
            if (isset($keys[$key])) {
                throw new RuntimeException('cloud_binding_duplicate:' . $key);
            }
            $keys[$key] = true;
            $normalized[] = [
                'platform' => $platform,
                'source_data_source_id' => $sourceDataSourceId,
                'destination_data_source_id' => $destinationDataSourceId,
            ];
        }

        usort($normalized, static fn(array $left, array $right): int => strcmp(
            $left['platform'] . ':' . $left['source_data_source_id'],
            $right['platform'] . ':' . $right['source_data_source_id']
        ));
        return [
            'contract_version' => self::BINDING_VERSION,
            'source_system_hotel_id' => $sourceHotelId,
            'destination_system_hotel_id' => $destinationHotelId,
            'bindings' => $normalized,
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public static function allowlistedRow(array $row): array
    {
        $allowed = array_fill_keys(self::ROW_FIELDS, true);
        return array_intersect_key($row, $allowed);
    }

    /** @return array<int, string> */
    public static function rowFields(): array
    {
        return self::ROW_FIELDS;
    }

    public static function canonicalJson(mixed $value): string
    {
        return json_encode(
            self::canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }

    /** @param array<int, array<string, mixed>> $packages @return array<int, array<string, mixed>> */
    private static function normalizePackages(array $packages, int $sourceHotelId, string $targetDate): array
    {
        $sourceHotelId = self::positiveInt($sourceHotelId, 'source_system_hotel_id');
        $targetDate = self::date($targetDate);
        $normalized = [];
        $packageKeys = [];
        $totalRows = 0;
        foreach ($packages as $package) {
            if (!is_array($package)) {
                throw new RuntimeException('cloud_bundle_package_invalid');
            }
            $platform = self::platform((string)($package['platform'] ?? ''));
            $sourceDataSourceId = self::positiveInt($package['source_data_source_id'] ?? 0, 'source_data_source_id');
            $destinationDataSourceId = self::positiveInt($package['destination_data_source_id'] ?? 0, 'destination_data_source_id');
            $packageKey = $platform . ':' . $sourceDataSourceId . ':' . $destinationDataSourceId;
            if (isset($packageKeys[$packageKey])) {
                throw new RuntimeException('cloud_bundle_package_duplicate:' . $packageKey);
            }
            $packageKeys[$packageKey] = true;

            $collection = self::normalizeCollection(is_array($package['collection'] ?? null) ? $package['collection'] : []);
            $rows = is_array($package['rows'] ?? null) ? $package['rows'] : [];
            if ($rows !== [] && array_keys($rows) !== range(0, count($rows) - 1)) {
                throw new RuntimeException('cloud_bundle_rows_must_be_list');
            }
            $normalizedRows = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw new RuntimeException('cloud_bundle_row_invalid');
                }
                $unknownKeys = array_diff(array_keys($row), self::ROW_FIELDS);
                if ($unknownKeys !== []) {
                    throw new RuntimeException('cloud_bundle_row_field_not_allowed:' . (string)reset($unknownKeys));
                }
                $row = self::allowlistedRow($row);
                foreach ($row as $field => $value) {
                    if (!is_scalar($value) && $value !== null) {
                        throw new RuntimeException('cloud_bundle_row_value_not_scalar:' . (string)$field);
                    }
                    if (is_string($value) && self::containsSecretValue($value)) {
                        throw new RuntimeException('cloud_bundle_sensitive_value_rejected');
                    }
                }
                if ((int)($row['system_hotel_id'] ?? 0) !== $sourceHotelId) {
                    throw new RuntimeException('cloud_bundle_source_hotel_mismatch');
                }
                if ((int)($row['data_source_id'] ?? 0) !== $sourceDataSourceId) {
                    throw new RuntimeException('cloud_bundle_source_data_source_mismatch');
                }
                if (self::platform((string)($row['platform'] ?? $row['source'] ?? '')) !== $platform) {
                    throw new RuntimeException('cloud_bundle_row_platform_mismatch');
                }
                if (self::date((string)($row['data_date'] ?? '')) !== $targetDate) {
                    throw new RuntimeException('cloud_bundle_target_date_mismatch');
                }
                if (trim((string)($row['hotel_id'] ?? '')) === '' || trim((string)($row['data_type'] ?? '')) === '') {
                    throw new RuntimeException('cloud_bundle_row_required_field_missing');
                }
                $validationStatus = strtolower(trim((string)($row['validation_status'] ?? '')));
                if (!in_array($validationStatus, self::TRUSTED_VALIDATION_STATUSES, true)) {
                    throw new RuntimeException('cloud_bundle_row_validation_untrusted');
                }
                if ((int)($row['readback_verified'] ?? 0) !== 1) {
                    throw new RuntimeException('cloud_bundle_row_readback_unverified');
                }
                $traceId = trim((string)($row['source_trace_id'] ?? ''));
                if ($traceId === '' || mb_strlen($traceId) > 200 || self::containsSecretValue($traceId)) {
                    throw new RuntimeException('cloud_bundle_row_source_trace_invalid');
                }
                $normalizedRows[] = $row;
            }
            $totalRows += count($normalizedRows);
            if ($totalRows > self::MAX_ROWS) {
                throw new RuntimeException('cloud_bundle_row_limit_exceeded');
            }
            if ($normalizedRows === [] && $collection['status'] === 'success') {
                throw new RuntimeException('cloud_bundle_success_package_has_no_rows');
            }
            if ($normalizedRows !== [] && !in_array($collection['status'], ['success', 'partial'], true)) {
                throw new RuntimeException('cloud_bundle_failed_package_must_not_contain_rows');
            }
            $normalized[] = [
                'platform' => $platform,
                'source_data_source_id' => $sourceDataSourceId,
                'destination_data_source_id' => $destinationDataSourceId,
                'collection' => $collection,
                'row_count' => count($normalizedRows),
                'rows' => $normalizedRows,
            ];
        }
        usort($normalized, static fn(array $left, array $right): int => strcmp(
            $left['platform'] . ':' . $left['source_data_source_id'],
            $right['platform'] . ':' . $right['source_data_source_id']
        ));
        return $normalized;
    }

    /** @param array<string, mixed> $collection @return array<string, string> */
    private static function normalizeCollection(array $collection): array
    {
        $status = strtolower(trim((string)($collection['status'] ?? 'not_collected')));
        if (!in_array($status, self::COLLECTION_STATUSES, true)) {
            throw new RuntimeException('cloud_bundle_collection_status_invalid');
        }
        $message = self::safeText((string)($collection['message'] ?? ''), 240);
        $lastSyncTime = trim((string)($collection['last_sync_time'] ?? ''));
        if ($lastSyncTime !== '') {
            $lastSyncTime = self::normalizeDateTime($lastSyncTime);
        }
        return [
            'status' => $status,
            'message' => $message,
            'last_sync_time' => $lastSyncTime,
        ];
    }

    /** @param array<string, mixed> $bundle @return array<string, mixed> */
    private static function identityPayload(array $bundle): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'metric_scope' => 'ota_channel',
            'source_system_hotel_id' => (int)$bundle['source_system_hotel_id'],
            'destination_system_hotel_id' => (int)$bundle['destination_system_hotel_id'],
            'target_date' => (string)$bundle['target_date'],
            'required_platforms' => (array)$bundle['required_platforms'],
            'packages' => (array)$bundle['packages'],
            'payload_sha256' => (string)$bundle['payload_sha256'],
        ];
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $isList = $value === [] || array_keys($value) === range(0, count($value) - 1);
        if (!$isList) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }
        return $value;
    }

    /** @param array<int, mixed> $values @return array<int, string> */
    private static function platforms(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $normalized[] = self::platform((string)$value);
        }
        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_STRING);
        if ($normalized === []) {
            throw new RuntimeException('cloud_bundle_required_platforms_missing');
        }
        return $normalized;
    }

    private static function platform(string $value): string
    {
        $value = strtolower(trim($value));
        if (!in_array($value, self::ALLOWED_PLATFORMS, true)) {
            throw new RuntimeException('cloud_bundle_platform_invalid:' . $value);
        }
        return $value;
    }

    private static function positiveInt(mixed $value, string $field): int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($value === false) {
            throw new RuntimeException('cloud_bundle_' . $field . '_invalid');
        }
        return (int)$value;
    }

    private static function date(string $value): string
    {
        $value = trim($value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new RuntimeException('cloud_bundle_target_date_invalid');
        }
        return $value;
    }

    private static function normalizeDateTime(string $value): string
    {
        $timestamp = strtotime(trim($value));
        if ($timestamp === false) {
            throw new RuntimeException('cloud_bundle_datetime_invalid');
        }
        return date('Y-m-d H:i:s', $timestamp);
    }

    private static function validSha256(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private static function safeText(string $value, int $limit): string
    {
        $value = trim((string)preg_replace('/[\r\n\t]+/u', ' ', $value));
        if (self::containsSecretValue($value)) {
            throw new RuntimeException('cloud_bundle_sensitive_value_rejected');
        }
        return mb_substr($value, 0, $limit, 'UTF-8');
    }

    private static function containsSecretValue(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        return preg_match('/(?:authorization["\x27]?\s*[:=]|bearer\s+[a-z0-9._~+\/-]{8,}|(?:cookie|token|password|secret|session)["\x27]?\s*[:=]\s*["\x27]?\S{4,})/i', $value) === 1
            || preg_match('#https://qyapi\.weixin\.qq\.com/cgi-bin/webhook/send\?key=#i', $value) === 1;
    }
}
