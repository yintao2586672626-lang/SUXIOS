<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;
use think\facade\Db;

/** Current, read-only proof of one original collector receipt. No values or trust labels are written. */
final class OtaLocalCollectorReadbackProofService
{
    private const MAX_ROWS = 2000;
    private const MAX_SCOPE_TASKS = 256;
    private const CONTENT_VERSION = 'ota_local_collector_content.v1';
    // Keep the original receipt fingerprint format; changing it would invalidate existing receipts.
    private const FINGERPRINT_FIELDS = [
        'id', 'tenant_id', 'system_hotel_id', 'data_source_id', 'sync_task_id', 'hotel_id',
        'platform', 'source', 'data_date', 'data_type', 'data_period', 'dimension',
        'amount', 'quantity', 'book_order_num', 'data_value', 'list_exposure', 'detail_exposure',
        'flow_rate', 'order_filling_num', 'order_submit_num', 'readback_verified',
    ];

    public function __construct(private readonly ?OtaLocalCollectorEvidenceStore $evidenceStore = null)
    {
    }

    public function fingerprint(array $task, array $receipt): ?string
    {
        try {
            $ids = $this->rowIds($receipt['row_ids'] ?? null);
            if ($ids === []) return null;
            $fields = $this->fingerprintFields();
            $rows = Db::name('online_daily_data')->field(implode(',', $fields))->whereIn('id', $ids)
                ->where('tenant_id', (int)$task['tenant_id'])->where('system_hotel_id', (int)$task['system_hotel_id'])
                ->order('id', 'asc')->select()->toArray();
            return count($rows) === count($ids) ? $this->hashRows($rows) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Separate versioned proof. Never reinterpret or replace an already-issued legacy rows_fingerprint. */
    public function contentFingerprint(array $task, array $receipt): ?array
    {
        try {
            $ids = $this->rowIds($receipt['row_ids'] ?? null);
            if ($ids === []) return null;
            $fields = $this->fingerprintFields(true);
            $rows = Db::name('online_daily_data')->field(implode(',', $fields))->whereIn('id', $ids)
                ->where('tenant_id', (int)$task['tenant_id'])->where('system_hotel_id', (int)$task['system_hotel_id'])
                ->order('id', 'asc')->select()->toArray();
            return count($rows) === count($ids)
                ? ['version' => self::CONTENT_VERSION, 'fields' => $fields, 'sha256' => $this->hashRows($rows)] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** The receipt attempt may be historical; it must never inherit the task's newer attempt. */
    public function verifyReceipt(array $task, array $receipt, bool $includeRowFingerprints = false): array
    {
        $result = ['status' => 'unknown', 'attempt' => (int)($receipt['attempt'] ?? 0), 'checked_at' => date('c'),
            'reason_code' => 'original_receipt_scope_mismatch', 'row_ids' => [], 'readback_verified' => false];
        if (!$this->receiptScopeMatches($task, $receipt)) return $result;
        try {
            ($this->evidenceStore ?? new OtaLocalCollectorEvidenceStore())->read(
                $receipt, (string)($receipt['evidence']['result_hash'] ?? '')
            );
        } catch (\Throwable) {
            $result['reason_code'] = 'original_evidence_unavailable';
            return $result;
        }
        if (($receipt['business_status'] ?? '') === 'capture_failed') {
            return array_replace($result, ['status' => 'not_saved', 'reason_code' => 'capture_failure_receipt_verified']);
        }
        $expected = $this->rowIds($receipt['row_ids'] ?? null);
        if ($expected === [] || count($expected) !== (int)($receipt['saved_count'] ?? 0)
            || ($receipt['readback_verified'] ?? false) !== true
            || (int)($receipt['data_source_id'] ?? 0) <= 0 || (int)($receipt['sync_task_id'] ?? 0) <= 0) {
            $result['reason_code'] = 'original_row_readback_mismatch';
            return $result;
        }
        if (!$this->isHash($receipt['rows_fingerprint'] ?? null)) {
            $result['reason_code'] = 'original_rows_fingerprint_missing';
            return $result;
        }
        try {
            $legacyFields = $this->fingerprintFields();
            $contentProof = $receipt['content_fingerprint'] ?? null;
            $fields = $contentProof === null ? $legacyFields : $this->fingerprintFields(true);
            $query = Db::name('online_daily_data')->field(implode(',', $fields))
                ->where('tenant_id', (int)$task['tenant_id'])->where('system_hotel_id', (int)$task['system_hotel_id'])
                ->where('data_source_id', (int)$receipt['data_source_id'])->where('sync_task_id', (int)$receipt['sync_task_id'])
                ->where('data_date', (string)$receipt['business_date']);
            foreach (['platform', 'source'] as $column) {
                if (in_array($column, $fields, true)) $query->where($column, (string)$receipt['platform']);
            }
            $rows = $query->order('id', 'asc')->limit(self::MAX_ROWS + 1)->select()->toArray();
            if ($this->rowIds(array_column($rows, 'id')) !== $expected) {
                $result['reason_code'] = 'original_row_readback_mismatch';
                return $result;
            }
            foreach ($rows as $row) {
                if ((int)($row['readback_verified'] ?? 0) !== 1) {
                    $result['reason_code'] = 'original_row_readback_mismatch';
                    return $result;
                }
                if ((string)($row['hotel_id'] ?? '') !== (string)$receipt['platform_hotel_id']) {
                    $result['reason_code'] = 'original_row_values_changed';
                    return $result;
                }
            }
            $legacyRows = array_map(fn(array $row): array => $this->projectFields($row, $legacyFields), $rows);
            if (!hash_equals((string)$receipt['rows_fingerprint'], $this->hashRows($legacyRows))) {
                $result['reason_code'] = 'original_row_values_changed';
                return $result;
            }
            if ($contentProof !== null) {
                if (!is_array($contentProof) || ($contentProof['version'] ?? '') !== self::CONTENT_VERSION
                    || ($contentProof['fields'] ?? []) !== $fields || !$this->isHash($contentProof['sha256'] ?? null)) {
                    $result['reason_code'] = 'original_content_proof_invalid';
                    return $result;
                }
                if (!hash_equals($contentProof['sha256'], $this->hashRows($rows))) {
                    $result['reason_code'] = 'original_row_content_changed';
                    return $result;
                }
            }
            $result = array_replace($result, ['status' => 'verified', 'reason_code' => 'original_rows_verified',
                'row_ids' => $expected, 'readback_verified' => true, 'content_readback_verified' => $contentProof !== null,
                'content_reason_code' => $contentProof !== null ? 'original_content_verified' : 'original_content_proof_missing']);
            if ($includeRowFingerprints) {
                $result['row_fingerprints'] = [];
                foreach ($rows as $row) $result['row_fingerprints'][(int)$row['id']] = $this->hashRows([$row]);
                $result['fingerprint_fields'] = $fields;
            }
            return $result;
        } catch (\Throwable) {
            $result['reason_code'] = 'original_readback_unavailable';
            return $result;
        }
    }

    /** Fresh checks per query, grouped by original source/run. Never use the hotel's latest task. */
    public function projectRows(array $rows): array
    {
        $scopeTasks = [];
        $batchChecks = [];
        foreach ($rows as &$row) {
            if (!is_array($row)) continue;
            unset($row['_local_collector_readback_proof']);
            $declaredCollector = strtolower(trim((string)($row['ingestion_method'] ?? ''))) === 'local_collector';
            $scope = [(int)($row['tenant_id'] ?? 0), (int)($row['system_hotel_id'] ?? 0),
                (string)($row['platform'] ?? $row['source'] ?? ''), (string)($row['data_date'] ?? '')];
            $sourceId = (int)($row['data_source_id'] ?? 0);
            $syncId = (int)($row['sync_task_id'] ?? 0);
            $hasLookupScope = $scope[0] > 0 && $scope[1] > 0 && in_array($scope[2], ['ctrip', 'meituan'], true)
                && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $scope[3]) === 1 && $sourceId > 0 && $syncId > 0;
            // Legacy non-collector rows without source/run identity keep their existing ETL rules.
            if (!$declaredCollector && !$hasLookupScope) continue;
            $scopeKey = json_encode($scope, JSON_THROW_ON_ERROR);
            $batchKey = $scopeKey . ':' . $sourceId . ':' . $syncId;
            if (!isset($batchChecks[$batchKey])) {
                $check = ['status' => 'unknown', 'readback_verified' => false, 'reason_code' => 'original_receipt_missing'];
                if (!$hasLookupScope) {
                    $check['reason_code'] = 'original_receipt_scope_missing';
                } else {
                    try {
                        if (!isset($scopeTasks[$scopeKey])) $scopeTasks[$scopeKey] = $this->tasksForScope($scope);
                        $matches = [];
                        $hasCollectorReceipt = false;
                        foreach ($scopeTasks[$scopeKey] as $task) {
                            $request = $this->decode($task['request_json'] ?? null);
                            $receipts = $request['result_delivery_receipts'] ?? [];
                            if (!is_array($receipts)) continue;
                            foreach ($receipts as $key => $receipt) {
                                if (!is_array($receipt) || (int)($receipt['data_source_id'] ?? 0) !== $sourceId
                                    || (int)($receipt['sync_task_id'] ?? 0) !== $syncId) continue;
                                $hasCollectorReceipt = true;
                                $identityKey = ($receipt['attempt'] ?? '') . ':' . ($receipt['result_id'] ?? '') . ':' . ($receipt['result_hash'] ?? '');
                                // Accept only the exact server-retained receipt identity. Legacy proof is unverified.
                                if ((string)$key !== $identityKey) continue;
                                $matches[] = [$task, $receipt];
                            }
                        }
                        if (count($matches) === 1) {
                            [$task, $receipt] = $matches[0];
                            $check = $this->verifyReceipt($task, $receipt, true) + [
                                'task_id' => (int)$task['id'], 'result_id' => (string)$receipt['result_id'],
                                'result_hash' => (string)$receipt['result_hash'],
                                'rows_fingerprint' => (string)($receipt['rows_fingerprint'] ?? ''),
                            ];
                        } elseif (count($matches) > 1) {
                            $check['reason_code'] = 'original_receipt_ambiguous';
                        } elseif (!$hasCollectorReceipt) {
                            $check['status'] = 'not_applicable';
                        } else {
                            $check['reason_code'] = 'original_receipt_identity_mismatch';
                        }
                    } catch (\Throwable $error) {
                        $check['reason_code'] = $error->getMessage() === 'original_proof_lookup_limit_exceeded'
                            ? 'original_proof_lookup_limit_exceeded' : 'original_proof_unavailable';
                    }
                }
                $batchChecks[$batchKey] = $check;
            }
            $check = $batchChecks[$batchKey];
            // Server-held source/run receipts identify collector ownership independently of the mutable row label.
            // Only a completed lookup with no matching receipt permits an independent non-collector batch.
            if ($check['status'] === 'not_applicable') {
                if (!$declaredCollector) continue;
                $check['status'] = 'unknown';
            }
            if (($check['readback_verified'] ?? false) === true && ($check['content_readback_verified'] ?? false) !== true) {
                // ETL trust is per row. A legacy hash cannot certify amounts/units/semantics sourced from uncovered raw content.
                $check['status'] = 'unknown';
                $check['readback_verified'] = false;
                $check['reason_code'] = 'original_content_proof_missing';
            }
            if (($check['readback_verified'] ?? false) === true) {
                // The ETL read and proof read can be separated by a concurrent write. Verify this exact projection too.
                $projection = $this->projectFields($row, $check['fingerprint_fields']);
                $rowHash = $check['row_fingerprints'][(int)($row['id'] ?? 0)] ?? '';
                if ($rowHash === '' || !hash_equals($rowHash, $this->hashRows([$projection]))) {
                    $check['status'] = 'unknown';
                    $check['readback_verified'] = false;
                    $check['reason_code'] = 'original_projection_values_changed';
                }
            }
            unset($check['row_fingerprints'], $check['fingerprint_fields'], $check['row_ids']);
            $row['_local_collector_readback_proof'] = $check;
        }
        unset($row);
        return $rows;
    }

    private function tasksForScope(array $scope): array
    {
        $tasks = Db::name('ota_local_collector_tasks')
            ->field('id,tenant_id,user_id,device_id,account_id,system_hotel_id,platform,data_date,data_type,attempt,request_json')
            ->where('tenant_id', $scope[0])->where('system_hotel_id', $scope[1])
            ->where('platform', $scope[2])->where('data_date', $scope[3])
            ->whereIn('task_type', ['collect', 'backfill'])->order('id', 'asc')
            ->limit(self::MAX_SCOPE_TASKS + 1)->select()->toArray();
        if (count($tasks) > self::MAX_SCOPE_TASKS) throw new RuntimeException('original_proof_lookup_limit_exceeded');
        return $tasks;
    }

    private function receiptScopeMatches(array $task, array $receipt): bool
    {
        foreach (['tenant_id', 'device_id', 'system_hotel_id'] as $key) {
            if ((int)($task[$key] ?? 0) <= 0 || (int)($receipt[$key] ?? 0) !== (int)$task[$key]) return false;
        }
        $request = $this->decode($task['request_json'] ?? null);
        return ($receipt['status'] ?? '') === 'accepted'
            && (int)($receipt['task_id'] ?? 0) === (int)($task['id'] ?? 0) && (int)($task['id'] ?? 0) > 0
            && (int)($receipt['attempt'] ?? 0) > 0 && (int)$receipt['attempt'] <= (int)($task['attempt'] ?? 0)
            && is_string($receipt['result_id'] ?? null) && $receipt['result_id'] !== '' && $this->isHash($receipt['result_hash'] ?? null)
            && in_array($receipt['platform'] ?? '', ['ctrip', 'meituan'], true)
            && ($receipt['platform'] ?? '') === ($task['platform'] ?? '')
            && ($receipt['business_date'] ?? '') === ($task['data_date'] ?? '')
            && ($receipt['source_method'] ?? '') === 'local_account_profile'
            && (($receipt['business_status'] ?? '') === 'capture_failed' || (
                ($receipt['data_type'] ?? '') === ($task['data_type'] ?? '')
                && (string)($receipt['platform_hotel_id'] ?? '') !== ''
                && (string)$receipt['platform_hotel_id'] === (string)($request['collection_scope']['platform_hotel_id'] ?? '')
            ));
    }

    private function fingerprintFields(bool $includeContent = false): array
    {
        $available = Db::getTableInfo('online_daily_data', 'fields');
        if (!is_array($available)) throw new RuntimeException('original_readback_schema_unavailable');
        $fields = array_values(array_intersect($includeContent ? OtaStandardEtlService::SOURCE_ROW_FIELDS : self::FINGERPRINT_FIELDS, $available));
        foreach (['id', 'tenant_id', 'system_hotel_id', 'data_source_id', 'sync_task_id', 'hotel_id', 'data_date', 'readback_verified'] as $required) {
            if (!in_array($required, $fields, true)) throw new RuntimeException('original_readback_schema_incomplete');
        }
        if (!in_array('platform', $fields, true) && !in_array('source', $fields, true)) throw new RuntimeException('original_readback_schema_incomplete');
        if ($includeContent && !in_array('raw_data', $fields, true)) throw new RuntimeException('original_content_schema_incomplete');
        return $fields;
    }

    private function projectFields(array $row, array $fields): array
    {
        $projected = [];
        foreach ($fields as $field) $projected[$field] = $row[$field] ?? null;
        return $projected;
    }

    private function rowIds(mixed $value): array
    {
        if (!is_array($value) || $value === [] || count($value) > self::MAX_ROWS) return [];
        $ids = [];
        foreach ($value as $id) {
            if ((!is_int($id) && (!is_string($id) || preg_match('/^[1-9][0-9]*$/D', $id) !== 1))
                || (int)$id <= 0 || (is_string($id) && (string)(int)$id !== $id) || isset($ids[(int)$id])) return [];
            $ids[(int)$id] = (int)$id;
        }
        sort($ids, SORT_NUMERIC);
        return array_values($ids);
    }

    private function hashRows(array $rows): string
    {
        return hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    private function decode(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        return is_array($decoded) ? $decoded : [];
    }

    private function isHash(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }
}
