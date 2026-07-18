<?php
declare(strict_types=1);

namespace app\service;

final class OnlineDataTrustStatusService
{
    public const FAILED_VALIDATION_STATUSES = [
        'abnormal', 'invalid', 'failed', 'fail', 'error',
        'collection_failed', 'capture_failed', 'permission_denied',
        'binding_missing', 'mismatched', 'mismatch', 'login_required',
    ];

    public const UNVERIFIED_VALIDATION_STATUSES = ['unverified', 'stale'];

    public const PARTIAL_VALIDATION_STATUSES = ['warning', 'partial', 'partial_success'];

    public const FAILED_ROW_STATUSES = [
        'failed', 'fail', 'error', 'collection_failed', 'capture_failed',
        'permission_denied', 'binding_missing', 'blocked', 'rejected', 'login_required',
    ];

    public const UNVERIFIED_ROW_STATUSES = ['unverified', 'stale'];

    public static function classifyValidationStatus(mixed $status): string
    {
        $normalized = strtolower(trim((string)$status));
        if (in_array($normalized, self::FAILED_VALIDATION_STATUSES, true)) {
            return 'failed';
        }
        if (in_array($normalized, self::UNVERIFIED_VALIDATION_STATUSES, true)) {
            return 'unverified';
        }
        if (in_array($normalized, self::PARTIAL_VALIDATION_STATUSES, true)) {
            return 'partial';
        }
        return 'usable';
    }

    public static function classifyRowStatus(mixed $status): string
    {
        $normalized = strtolower(trim((string)$status));
        if (in_array($normalized, self::FAILED_ROW_STATUSES, true)) {
            return 'failed';
        }
        if (in_array($normalized, self::UNVERIFIED_ROW_STATUSES, true)) {
            return 'unverified';
        }
        if (in_array($normalized, self::PARTIAL_VALIDATION_STATUSES, true)) {
            return 'partial';
        }
        return 'usable';
    }

    /** @return array<int, string> */
    public static function blockingValidationStatuses(): array
    {
        return array_values(array_unique(array_merge(
            self::FAILED_VALIDATION_STATUSES,
            self::UNVERIFIED_VALIDATION_STATUSES
        )));
    }

    /** @return array<int, string> */
    public static function blockingRowStatuses(): array
    {
        return array_values(array_unique(array_merge(
            self::FAILED_ROW_STATUSES,
            self::UNVERIFIED_ROW_STATUSES
        )));
    }

    /** @param array<int, string> $statuses */
    public static function quotedSqlList(array $statuses): string
    {
        return "'" . implode("','", array_map(
            static fn(string $status): string => str_replace("'", "''", strtolower(trim($status))),
            $statuses
        )) . "'";
    }

    /** @return array{code: string, label: string} */
    public static function storageStatus(array $row): array
    {
        $rowClass = self::classifyRowStatus($row['status'] ?? $row['save_status'] ?? '');
        $validationClass = self::classifyValidationStatus($row['validation_status'] ?? '');
        if ($rowClass === 'failed' || $validationClass === 'failed') {
            return ['code' => 'failed', 'label' => '入库校验失败'];
        }
        if ($rowClass === 'unverified' || $validationClass === 'unverified') {
            return ['code' => 'unverified', 'label' => '未回读验证'];
        }
        if ($rowClass === 'partial' || $validationClass === 'partial') {
            return ['code' => 'partial', 'label' => '部分入库'];
        }
        if ((int)($row['readback_verified'] ?? 0) === 1) {
            return ['code' => 'success', 'label' => '已入库并回读'];
        }
        return ['code' => 'unverified', 'label' => '未回读验证'];
    }
}
