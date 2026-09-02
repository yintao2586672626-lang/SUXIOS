<?php
declare(strict_types=1);

namespace app\service\concern;

use app\service\CloudOtaBundleCodec;
use think\facade\Db;

/**
 * Exact target-date coverage derived from a complete persistence receipt.
 */
trait PlatformSyncTaskReadbackCoverageConcern
{
    /** @return array<int, int> */
    private function readbackCoverageRowIds(mixed $value): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn($item): int => max(0, (int)$item),
            is_array($value) ? $value : []
        ))));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** @param array<string, mixed> $row */
    private function isOwnPlatformTargetReadbackRow(
        array $row, string $platform, string $targetDate, string $dataPeriod): bool {
        $rowPlatform = strtolower(trim((string)($row['platform'] ?? $row['source'] ?? '')));
        $rowSource = strtolower(trim((string)($row['source'] ?? $row['platform'] ?? '')));
        return (string)($row['data_date'] ?? '') === $targetDate
            && (string)($row['data_period'] ?? '') === $dataPeriod
            && $rowPlatform === $platform
            && $rowSource === $platform;
    }

    /** @param array<int, array<string, mixed>> $rows @param array<string, mixed> $source @param array<string, mixed> $payload */
    private function saveNormalizedRowsWithTargetDateExpectation(
        array $rows,
        array $source,
        array $payload
    ): array {
        $profileRun = $this->isOtaBrowserProfileSource($source);
        $sourcePlatform = strtolower(trim((string)($source['platform'] ?? '')));
        $targetDate = $this->normalizeDate($payload['data_date'] ?? $payload['dataDate'] ?? null) ?? '';
        $dataPeriod = $this->normalizeDataPeriod($payload['data_period'] ?? $payload['dataPeriod'] ?? '');
        $expectedIdentities = [];
        if ($profileRun && in_array($sourcePlatform, ['ctrip', 'meituan'], true)
            && $targetDate !== '' && $dataPeriod !== ''
        ) {
            foreach ($rows as $row) {
                if (!$this->isOwnPlatformTargetReadbackRow(
                    $row, $sourcePlatform, $targetDate, $dataPeriod
                )) {
                    continue;
                }
                $identity = strtolower(trim((string)($row['persistence_identity_hash'] ?? '')));
                if (preg_match('/^[a-f0-9]{64}$/D', $identity) !== 1) {
                    $identity = $this->persistenceIdentityHash($row);
                }
                $expectedIdentities[] = $identity;
            }
        }
        $expectedIdentities = array_values(array_unique($expectedIdentities));
        sort($expectedIdentities, SORT_STRING);

        $receipt = $this->saveNormalizedRows($rows);
        $identityRowIds = is_array($receipt['persistence_identity_row_ids'] ?? null)
            ? $receipt['persistence_identity_row_ids']
            : [];
        unset($receipt['persistence_identity_row_ids']);
        if (!$profileRun) {
            return $receipt;
        }
        $expectedRowIds = [];
        foreach ($expectedIdentities as $identity) {
            $rowId = max(0, (int)($identityRowIds[$identity] ?? 0));
            if ($rowId > 0) {
                $expectedRowIds[] = $rowId;
            }
        }
        $receipt['target_date_expected_row_ids'] = $this->readbackCoverageRowIds($expectedRowIds);
        $receipt['target_date_expected_row_count'] = count($expectedIdentities);
        if ($expectedIdentities !== [] && count($expectedRowIds) !== count($expectedIdentities)) {
            $receipt['readback_verified'] = false;
            $receipt['failure_reason'] = 'target_date_expected_row_identity_mapping_mismatch';
        }
        return $receipt;
    }

    /**
     * @param array<string, mixed> $saveReceipt
     * @param array<string, bool> $columns
     * @return array<string, mixed>
     */
    private function resolveTargetDateReadbackExpectation(
        array $saveReceipt,
        array $columns,
        int $taskId,
        int $sourceId,
        int $hotelId,
        string $platform,
        string $targetDate,
        string $dataPeriod,
        bool $targetDeclarationRequired = false
    ): array {
        $expectedRowIds = $this->readbackCoverageRowIds($saveReceipt['row_ids'] ?? []);
        $expectedReadbackCount = max(0, (int)(
            $saveReceipt['readback_count'] ?? $saveReceipt['saved_count'] ?? 0
        ));
        $declaredTargetRowIds = $this->readbackCoverageRowIds(
            $saveReceipt['target_date_expected_row_ids'] ?? []
        );
        $declaredTargetRowCount = array_key_exists('target_date_expected_row_count', $saveReceipt)
            ? max(0, (int)$saveReceipt['target_date_expected_row_count'])
            : count($declaredTargetRowIds);
        $targetDeclarationPresent = array_key_exists('target_date_expected_row_ids', $saveReceipt)
            && array_key_exists('target_date_expected_row_count', $saveReceipt);
        $initialExpectedIds = $declaredTargetRowIds !== []
            ? $declaredTargetRowIds
            : $expectedRowIds;

        $result = [
            'ok' => false,
            'failure_reason' => '',
            'target_date_expected_row_ids' => $declaredTargetRowIds,
            'target_date_expected_row_count' => $declaredTargetRowCount,
            'exact_coverage' => $this->targetDateExactCoverage($initialExpectedIds, []),
        ];
        if (($saveReceipt['readback_verified'] ?? false) !== true
            || $expectedReadbackCount <= 0
            || $expectedRowIds === []
            || $expectedReadbackCount !== count($expectedRowIds)
            || ($targetDeclarationRequired
                && (!$targetDeclarationPresent || $declaredTargetRowIds === []))
            || ($declaredTargetRowIds !== []
                && $declaredTargetRowCount !== count($declaredTargetRowIds))
        ) {
            $result['failure_reason'] = 'run_identity_or_persistence_readback_missing';
            return $result;
        }
        if (count($expectedRowIds) > CloudOtaBundleCodec::MAX_ROWS) {
            $result['failure_reason'] = 'run_readback_row_limit_exceeded';
            return $result;
        }

        $fields = array_values(array_filter([
            'id', 'sync_task_id', 'data_source_id', 'system_hotel_id',
            'data_date', 'data_period', 'platform', 'source',
        ], static fn(string $field): bool => isset($columns[$field])));
        try {
            $savedRows = Db::name('online_daily_data')
                ->field(implode(',', $fields))
                ->whereIn('id', $expectedRowIds)
                ->limit(CloudOtaBundleCodec::MAX_ROWS + 1)
                ->order('id', 'asc')
                ->select()
                ->toArray();
        } catch (\Throwable) {
            $result['failure_reason'] = 'run_readback_query_failed';
            return $result;
        }
        $savedRows = array_values(array_filter($savedRows, 'is_array'));
        if (count($savedRows) > CloudOtaBundleCodec::MAX_ROWS) {
            $result['failure_reason'] = 'run_readback_row_limit_exceeded';
            return $result;
        }

        $savedRowIds = $this->readbackCoverageRowIds(array_column($savedRows, 'id'));
        $missingSavedRowIds = array_values(array_diff($expectedRowIds, $savedRowIds));
        $unexpectedSavedRowIds = array_values(array_diff($savedRowIds, $expectedRowIds));
        if (count($savedRows) !== $expectedReadbackCount
            || count($savedRowIds) !== count($savedRows)
            || $missingSavedRowIds !== []
            || $unexpectedSavedRowIds !== []
        ) {
            $coverageObservedIds = array_values(array_intersect($initialExpectedIds, $savedRowIds));
            $result['exact_coverage'] = $this->targetDateExactCoverage(
                $initialExpectedIds,
                $coverageObservedIds
            );
            $result['failure_reason'] = 'run_save_receipt_row_coverage_mismatch';
            return $result;
        }

        $derivedTargetRowIds = [];
        foreach ($savedRows as $savedRow) {
            $savedPlatform = strtolower(trim((string)($savedRow['platform'] ?? $savedRow['source'] ?? '')));
            $savedSource = strtolower(trim((string)($savedRow['source'] ?? $savedRow['platform'] ?? '')));
            if ((int)($savedRow['sync_task_id'] ?? 0) !== $taskId
                || (int)($savedRow['data_source_id'] ?? 0) !== $sourceId
                || (int)($savedRow['system_hotel_id'] ?? 0) !== $hotelId
                || $savedPlatform === ''
                || $savedSource !== $platform
            ) {
                $result['failure_reason'] = 'run_save_receipt_scope_mismatch';
                return $result;
            }
            if ($this->isOwnPlatformTargetReadbackRow(
                $savedRow, $platform, $targetDate, $dataPeriod
            )) {
                $derivedTargetRowIds[] = max(0, (int)($savedRow['id'] ?? 0));
            }
        }
        $derivedTargetRowIds = $this->readbackCoverageRowIds($derivedTargetRowIds);
        $expectedTargetRowIds = $declaredTargetRowIds !== []
            ? $declaredTargetRowIds
            : $derivedTargetRowIds;
        $result['target_date_expected_row_ids'] = $expectedTargetRowIds;
        $result['target_date_expected_row_count'] = count($expectedTargetRowIds);
        $result['exact_coverage'] = $this->targetDateExactCoverage($expectedTargetRowIds, []);
        if ($expectedTargetRowIds === []
            || ($declaredTargetRowIds !== []
                && (array_diff($declaredTargetRowIds, $derivedTargetRowIds) !== []
                    || array_diff($derivedTargetRowIds, $declaredTargetRowIds) !== []))
        ) {
            $result['exact_coverage'] = $this->targetDateExactCoverage(
                $expectedTargetRowIds,
                $derivedTargetRowIds
            );
            $result['failure_reason'] = 'run_readback_receipt_mismatch';
            return $result;
        }

        $result['ok'] = true;
        return $result;
    }

    /**
     * @param array<int, int> $expectedRowIds
     * @param array<int, int> $readbackRowIds
     * @return array<string, mixed>
     */
    private function targetDateExactCoverage(array $expectedRowIds, array $readbackRowIds): array
    {
        $expectedRowIds = $this->readbackCoverageRowIds($expectedRowIds);
        $readbackRowIds = $this->readbackCoverageRowIds($readbackRowIds);
        $missingRowIds = array_values(array_diff($expectedRowIds, $readbackRowIds));
        $unexpectedRowIds = array_values(array_diff($readbackRowIds, $expectedRowIds));
        return [
            'complete' => $expectedRowIds !== []
                && count($expectedRowIds) === count($readbackRowIds)
                && $missingRowIds === []
                && $unexpectedRowIds === [],
            'expected_count' => count($expectedRowIds),
            'readback_count' => count($readbackRowIds),
            'missing_row_ids' => $missingRowIds,
            'unexpected_row_ids' => $unexpectedRowIds,
        ];
    }
}
