<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;
use think\facade\Db;

/**
 * Persists already-normalized OTA rows as one verified database batch.
 *
 * Raw capture evidence, adapter execution, hotel binding, and sync-task state
 * stay in PlatformDataSyncService. This service owns only the primary
 * online_daily_data transaction: identity, idempotent write, readback proof,
 * rollback receipt, and the derived Ctrip fact projection.
 */
final class PlatformNormalizedRowPersistenceService
{
    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, bool>|null $columns
     * @return array{
     *   attempted_count:int,
     *   saved_count:int,
     *   inserted_count:int,
     *   updated_count:int,
     *   deduplicated_count:int,
     *   readback_count:int,
     *   readback_verified:bool,
     *   row_ids:array<int, int>,
     *   persistence_identity_row_ids:array<string, int>,
     *   rolled_back?:bool,
     *   failure_reason?:string,
     *   mismatch_field?:string
     * }
     */
    public function save(array $rows, ?array $columns = null): array
    {
        if ($rows === []) {
            return [
                'attempted_count' => 0,
                'saved_count' => 0,
                'inserted_count' => 0,
                'updated_count' => 0,
                'deduplicated_count' => 0,
                'readback_count' => 0,
                'readback_verified' => true,
                'row_ids' => [],
                'persistence_identity_row_ids' => [],
            ];
        }

        $columns ??= OnlineDailyDataPersistenceService::getColumns();
        $failureReceipt = null;
        try {
            return Db::transaction(function () use ($rows, $columns, &$failureReceipt): array {
                $attempted = count($rows);
                $inserted = 0;
                $updated = 0;
                $readback = 0;
                $rowIds = [];
                $identityRowIds = [];
                $readbackRows = [];
                $preparedRows = [];

                foreach ($rows as $row) {
                    $data = array_intersect_key($row, $columns);
                    if ($data === []) {
                        $failureReceipt = $this->normalizedRowsRollbackReceipt(
                            $attempted,
                            'normalized_row_has_no_persistable_columns'
                        );
                        throw new RuntimeException('normalized_row_has_no_persistable_columns');
                    }

                    $data = OnlineDailyDataPersistenceService::applyTenantScope($data, $columns);
                    $data = OnlineDailyDataPersistenceService::resetReadbackVerification($data, $columns);
                    if (isset($columns['persistence_identity_hash'])
                        && trim((string)($data['persistence_identity_hash'] ?? '')) === ''
                    ) {
                        $data['persistence_identity_hash'] = $this->identityHash($data);
                    }
                    $preparedRows[$this->normalizedRowIdentityKey($data, $columns)] = $data;
                }
                $deduplicatedCount = max(0, $attempted - count($preparedRows));

                foreach ($preparedRows as $data) {
                    $persistenceIdentity = trim((string)($data['persistence_identity_hash'] ?? ''));
                    if (preg_match('/^[a-f0-9]{64}$/D', $persistenceIdentity) !== 1) {
                        $persistenceIdentity = $this->identityHash($data);
                    }
                    $existing = $this->findNormalizedRowByCompleteIdentity($data, $columns);
                    if (is_array($existing)) {
                        $rowId = (int)($existing['id'] ?? 0);
                        if (isset($columns['update_time'])) {
                            $data['update_time'] = date('Y-m-d H:i:s');
                        }
                        Db::name('online_daily_data')->where('id', $rowId)->update($data);
                        $updated++;
                    } else {
                        if (isset($columns['create_time'])) {
                            $data['create_time'] = date('Y-m-d H:i:s');
                        }
                        if (isset($columns['update_time'])) {
                            $data['update_time'] = date('Y-m-d H:i:s');
                        }
                        try {
                            $rowId = (int)Db::name('online_daily_data')->insertGetId($data);
                            if ($rowId > 0) {
                                $inserted++;
                            }
                        } catch (\Throwable $insertError) {
                            $persistenceHash = trim((string)($data['persistence_identity_hash'] ?? ''));
                            $concurrent = $persistenceHash !== '' && isset($columns['persistence_identity_hash'])
                                ? Db::name('online_daily_data')
                                    ->where('persistence_identity_hash', $persistenceHash)
                                    ->lock(true)
                                    ->find()
                                : null;
                            if (!is_array($concurrent) || (int)($concurrent['id'] ?? 0) <= 0) {
                                throw $insertError;
                            }
                            $rowId = (int)$concurrent['id'];
                            unset($data['create_time']);
                            if (isset($columns['update_time'])) {
                                $data['update_time'] = date('Y-m-d H:i:s');
                            }
                            Db::name('online_daily_data')->where('id', $rowId)->update($data);
                            $updated++;
                        }
                    }

                    $mismatchField = null;
                    $readbackRow = $rowId > 0
                        ? $this->normalizedRowReadback($rowId, $data, $columns, $mismatchField)
                        : null;
                    if (!is_array($readbackRow)) {
                        $failureReceipt = $this->normalizedRowsRollbackReceipt(
                            $attempted,
                            'normalized_rows_readback_mismatch_rolled_back',
                            $mismatchField
                        );
                        throw new RuntimeException('normalized_rows_readback_mismatch_rolled_back');
                    }
                    $readback++;
                    $rowIds[] = $rowId;
                    $identityRowIds[$persistenceIdentity] = $rowId;
                    $readbackRows[] = $readbackRow;
                }

                if (count($preparedRows) !== $readback
                    || !OnlineDailyDataPersistenceService::markRowsReadbackVerified($readbackRows, $columns)
                ) {
                    $failureReceipt = $this->normalizedRowsRollbackReceipt(
                        $attempted,
                        'normalized_rows_readback_proof_not_persisted_rolled_back'
                    );
                    throw new RuntimeException('normalized_rows_readback_proof_not_persisted_rolled_back');
                }

                // Ctrip's historic metric table is a derived query projection.
                // It receives only rows which passed the primary save/readback
                // proof and remains inside the same transaction.
                (new CtripMetricFactProjectionService())->project($readbackRows);

                return [
                    'attempted_count' => $attempted,
                    'saved_count' => $readback,
                    'inserted_count' => $inserted,
                    'updated_count' => $updated,
                    'deduplicated_count' => $deduplicatedCount,
                    'readback_count' => $readback,
                    'readback_verified' => count($preparedRows) === $readback,
                    'rolled_back' => false,
                    'failure_reason' => '',
                    // This receipt is consumed immediately to select the exact
                    // target-date subset. The persisted receipt is bounded by
                    // CloudOtaBundleCodec::MAX_ROWS after that filtering step.
                    'row_ids' => $rowIds,
                    'persistence_identity_row_ids' => $identityRowIds,
                ];
            });
        } catch (RuntimeException $e) {
            if (is_array($failureReceipt)) {
                return $failureReceipt;
            }
            throw $e;
        }
    }

    /**
     * Stable identity used both while normalizing and while persisting rows.
     *
     * @param array<string, mixed> $row
     */
    public function identityHash(array $row): string
    {
        $eventIdentity = $this->normalizedRowEventIdentityHash($row);
        $identity = [];
        foreach ([
            'tenant_id', 'system_hotel_id', 'data_source_id', 'source', 'platform',
            'hotel_id', 'data_type', 'data_date', 'data_period', 'snapshot_bucket',
            'dimension', 'compare_type',
        ] as $field) {
            $identity[$field] = array_key_exists($field, $row) && $row[$field] !== null
                ? (string)$row[$field]
                : '';
        }
        $runScopedTaskId = $this->normalizedRowRunScopedSyncTaskId($row);
        if ($runScopedTaskId > 0) {
            $identity['sync_task_id'] = (string)$runScopedTaskId;
        }
        $identity['identity_kind'] = $eventIdentity !== '' ? 'event' : 'summary';
        $identity['event_identity_hash'] = $eventIdentity;
        return hash(
            'sha256',
            json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, bool> $columns
     * @return array<string, mixed>|null
     */
    private function findNormalizedRowByCompleteIdentity(array $row, array $columns): ?array
    {
        $identityFields = [
            'tenant_id', 'system_hotel_id', 'data_source_id', 'source', 'platform',
            'hotel_id', 'data_type', 'data_date', 'data_period', 'snapshot_bucket',
            'dimension', 'compare_type',
        ];
        if ($this->normalizedRowRunScopedSyncTaskId($row) > 0) {
            $identityFields[] = 'sync_task_id';
        }
        $applyIdentity = static function ($query) use ($row, $columns, $identityFields): void {
            foreach ($identityFields as $field) {
                if (!isset($columns[$field]) || !array_key_exists($field, $row)) {
                    continue;
                }
                if ($row[$field] === null) {
                    $query->whereNull($field);
                } else {
                    $query->where($field, $row[$field]);
                }
            }
        };

        $persistenceHash = trim((string)($row['persistence_identity_hash'] ?? ''));
        if ($persistenceHash !== '' && isset($columns['persistence_identity_hash'])) {
            $existing = Db::name('online_daily_data')
                ->where('persistence_identity_hash', $persistenceHash)
                ->lock(true)
                ->find();
            if (is_array($existing)) {
                return $existing;
            }
        }

        $traceId = trim((string)($row['source_trace_id'] ?? ''));
        if ($traceId !== '' && isset($columns['source_trace_id'])) {
            $traceQuery = Db::name('online_daily_data')->where('source_trace_id', $traceId);
            $applyIdentity($traceQuery);
            $existing = $traceQuery->find();
            if (is_array($existing)) {
                return $existing;
            }
        }

        if ($persistenceHash !== '' && $this->normalizedRowHasStableEventIdentity($row)) {
            return null;
        }

        $query = Db::name('online_daily_data');
        $applyIdentity($query);
        $existing = $query->find();
        return is_array($existing) ? $existing : null;
    }

    /** @param array<string, mixed> $row @param array<string, bool> $columns */
    private function normalizedRowIdentityKey(array $row, array $columns): string
    {
        $persistenceHash = trim((string)($row['persistence_identity_hash'] ?? ''));
        if ($persistenceHash !== '' && isset($columns['persistence_identity_hash'])) {
            return 'persistence:' . $persistenceHash;
        }

        $identity = [];
        foreach ([
            'tenant_id', 'system_hotel_id', 'data_source_id', 'sync_task_id', 'source', 'platform',
            'hotel_id', 'data_type', 'data_date', 'data_period', 'snapshot_bucket',
            'dimension', 'compare_type',
        ] as $field) {
            if ($field === 'sync_task_id') {
                $runScopedTaskId = $this->normalizedRowRunScopedSyncTaskId($row);
                if ($runScopedTaskId > 0 && isset($columns[$field])) {
                    $identity[$field] = (string)$runScopedTaskId;
                }
                continue;
            }
            if (!isset($columns[$field]) || !array_key_exists($field, $row)) {
                continue;
            }
            $identity[$field] = $row[$field] === null ? null : (string)$row[$field];
        }
        if ($identity === []) {
            throw new RuntimeException('normalized_row_identity_missing');
        }
        return json_encode(
            $identity,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Automatic P0 traffic snapshots are immutable per sync task so a later
     * run cannot steal an earlier task's exact readback rows. Other summaries,
     * explicit imports, and stable event facts retain the historical
     * cross-task idempotency contract and consumer aggregation semantics.
     *
     * @param array<string, mixed> $row
     */
    private function normalizedRowRunScopedSyncTaskId(array $row): int
    {
        $taskId = max(0, (int)($row['sync_task_id'] ?? 0));
        $ingestionMethod = strtolower(trim((string)($row['ingestion_method'] ?? '')));
        $dataType = strtolower(trim((string)($row['data_type'] ?? '')));
        return $taskId > 0
            && in_array($dataType, ['traffic', 'flow', 'conversion'], true)
            && !in_array($ingestionMethod, ['manual', 'import_json', 'import_csv', 'import_excel'], true)
            && !$this->normalizedRowHasStableEventIdentity($row)
            ? $taskId
            : 0;
    }

    /** @param array<string, mixed> $row */
    private function normalizedRowHasStableEventIdentity(array $row): bool
    {
        return $this->normalizedRowEventIdentityHash($row) !== '';
    }

    /** @param array<string, mixed> $row */
    private function normalizedRowEventIdentityHash(array $row): string
    {
        if ($this->normalizeOrderDataType((string)($row['data_type'] ?? '')) !== 'order') {
            return '';
        }
        $raw = $this->decodeArray($row['raw_data'] ?? []);
        $candidate = is_array($raw['row'] ?? null) ? $raw['row'] : $raw;
        return $this->firstNestedTextByKey($candidate, ['order_id_hash', 'booking_id_hash']);
    }

    private function normalizeOrderDataType(string $value): string
    {
        $value = trim($value);
        $value = (string)preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $value);
        $value = strtolower((string)preg_replace('/[\s\-.]+/', '_', $value));
        $value = trim((string)preg_replace('/_+/', '_', $value), '_');
        return in_array($value, ['order', 'orders', 'order_list'], true) ? 'order' : $value;
    }

    private function decodeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<mixed> $value @param array<int, string> $keys */
    private function firstNestedTextByKey(array $value, array $keys, int $depth = 0): string
    {
        if ($depth > 6) {
            return '';
        }
        foreach ($keys as $key) {
            if (array_key_exists($key, $value)
                && is_scalar($value[$key])
                && trim((string)$value[$key]) !== ''
            ) {
                return trim((string)$value[$key]);
            }
        }
        foreach ($value as $item) {
            if (is_array($item)) {
                $found = $this->firstNestedTextByKey($item, $keys, $depth + 1);
                if ($found !== '') {
                    return $found;
                }
            }
        }
        return '';
    }

    /** @return array<string, mixed> */
    private function normalizedRowsRollbackReceipt(
        int $attempted,
        string $reason,
        ?string $mismatchField = null
    ): array {
        return [
            'attempted_count' => max(0, $attempted),
            'saved_count' => 0,
            'inserted_count' => 0,
            'updated_count' => 0,
            'deduplicated_count' => 0,
            'readback_count' => 0,
            'readback_verified' => false,
            'rolled_back' => true,
            'failure_reason' => $reason,
            'mismatch_field' => trim((string)$mismatchField),
            'row_ids' => [],
            'persistence_identity_row_ids' => [],
        ];
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, bool> $columns
     */
    private function normalizedRowReadback(
        int $rowId,
        array $expected,
        array $columns,
        ?string &$mismatchField = null
    ): ?array {
        $mismatchField = null;
        $stored = Db::name('online_daily_data')->where('id', $rowId)->find();
        if (!is_array($stored)) {
            $mismatchField = '__row_missing__';
            return null;
        }
        foreach ($expected as $field => $expectedValue) {
            if (!isset($columns[$field]) || $field === 'id') {
                continue;
            }
            $storedValue = $stored[$field] ?? null;
            if (!$this->normalizedStoredValueMatches($storedValue, $expectedValue, (string)$field)) {
                $mismatchField = (string)$field;
                return null;
            }
        }
        return $stored;
    }

    private function normalizedStoredValueMatches(
        mixed $stored,
        mixed $expected,
        string $field = ''
    ): bool {
        if ($expected === null) {
            return $stored === null;
        }
        if (is_int($expected)) {
            return is_numeric($stored) && (int)$stored === $expected;
        }
        if (is_float($expected)) {
            if (!is_numeric($stored)) {
                return false;
            }
            if (in_array($field, ['comment_score', 'qunar_comment_score'], true)) {
                return abs((float)$stored - round($expected, 1, PHP_ROUND_HALF_UP)) <= 0.000001;
            }
            return abs((float)$stored - $expected) <= 0.005001;
        }
        if (is_bool($expected)) {
            return (bool)$stored === $expected;
        }
        if (is_string($expected) && $expected !== '' && in_array($expected[0], ['{', '['], true)) {
            $expectedJson = json_decode($expected, true);
            $storedJson = is_string($stored) ? json_decode($stored, true) : null;
            if (is_array($expectedJson) && is_array($storedJson)) {
                return $storedJson == $expectedJson;
            }
        }
        return (string)$stored === (string)$expected;
    }
}
