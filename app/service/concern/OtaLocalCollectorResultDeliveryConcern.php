<?php
declare(strict_types=1);

namespace app\service\concern;

use app\service\OtaLocalCollectorEvidenceStore;
use app\service\PlatformDataSyncService;
use RuntimeException;
use think\facade\Db;

/** Result transport identity is independent from capture and fact quality. */
trait OtaLocalCollectorResultDeliveryConcern
{
    /** A delivered capture failure needs a durable receipt too, or its Outbox never drains. */
    private function finishFailedResultDelivery(array $task, array $account, array $device, array $delivery, string $code, string $message): array
    {
        if ($delivery === []) return $this->handleTaskFailure($task, $account, $device, $code, $message);
        $delivery = $this->retainResultDelivery($task, $delivery);
        return Db::transaction(function () use ($task, $account, $device, $delivery, $code, $message): array {
            $task = $this->lockLeasedTaskForImport($device, $task);
            $mapping = $this->mappingForAccountHotel((int)$task['tenant_id'], (int)$task['account_id'], (int)$task['system_hotel_id'], (string)$task['platform']);
            $receipt = $delivery + $this->recoveryScope($task, $mapping) + [
                'status' => 'accepted', 'accepted_at' => date('c'), 'business_status' => 'capture_failed',
                'saved_count' => null, 'readback_verified' => false, 'data_source_id' => null, 'sync_task_id' => null,
                'error_code' => $code,
            ];
            $request = $this->decodeJson($task['request_json'] ?? null);
            $request['result_delivery'] = array_intersect_key($receipt, array_flip(['result_id', 'result_hash', 'attempt', 'status']));
            $request['result_delivery_receipts'][$this->resultReceiptKey($delivery)] = $receipt;
            unset($request['recovery_check']);
            $task = $this->requireLeasedTaskWrite($task, [
                'request_json' => json_encode($request, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'result_summary_json' => json_encode(['scope_identity' => $this->recoveryScope($task, $mapping),
                    'result_delivery' => $receipt, 'capture_failed' => true], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
            $failure = $this->handleTaskFailure($task, $account, $device, $code, $message);
            $failure['delivery'] = $receipt;
            return $failure;
        });
    }

    public function resultEvidence(mixed $user, int $taskId, string $hash, string $resultId = '', int $attempt = 0): array
    {
        $actor = $this->actorContext($user);
        $task = Db::name('ota_local_collector_tasks')->where('id', $taskId)
            ->where('tenant_id', $actor['tenant_id'])->where('user_id', $actor['user_id'])->find();
        if (!is_array($task)) {
            throw new RuntimeException('本机采集任务不存在。', 404);
        }
        $this->assertTaskIdentity($task);
        $this->assertHotelPermission($actor, (int)$task['system_hotel_id']);
        $request = $this->decodeJson($task['request_json'] ?? null);
        $receipts = is_array($request['result_delivery_receipts'] ?? null) ? $request['result_delivery_receipts'] : [];
        $matches = array_values(array_filter($receipts, static fn($row): bool => is_array($row)
            && ($row['result_hash'] ?? '') === $hash
            && ($resultId === '' || ($row['result_id'] ?? '') === $resultId)
            && ($attempt === 0 || (int)($row['attempt'] ?? 0) === $attempt)));
        if (count($matches) > 1) {
            throw new RuntimeException('同内容存在多次回传，请指定结果编号与采集次数。', 409);
        }
        $receipt = $matches[0] ?? null;
        if (!is_array($receipt) || ($receipt['status'] ?? '') !== 'accepted') {
            throw new RuntimeException('该次回传尚无已保存的完整业务证据；旧记录不补造证据。', 404);
        }
        $this->assertDeliveryReceiptScope($task, $receipt);
        $evidenceHash = (string)($receipt['evidence']['result_hash'] ?? '');
        $business = $this->resultEvidenceStore()->read($receipt, $evidenceHash);
        // Apply current redaction on read as well, including older retained inputs.
        $business = (new PlatformDataSyncService())->sanitizeCollectorBusinessEvidence($business);
        $this->assertNoSensitiveMaterial($business);
        return [
            'receipt' => $receipt,
            'scope' => [
                'tenant_id' => (int)$task['tenant_id'], 'system_hotel_id' => (int)$task['system_hotel_id'],
                'platform' => (string)$task['platform'], 'business_date' => (string)$task['data_date'],
                'source_method' => 'local_account_profile',
            ],
            'evidence' => $receipt['evidence'], 'business_result' => $business,
            'data_quality' => 'reference_only', 'raw_data_exposed' => false,
            'message' => '这是本次回传的脱敏业务输入，供复查；正式经营事实以标准行与核验结果为准。',
        ];
    }

    public function resumeResultUpload(string $publicId, string $token, int $taskId, array $input): array
    {
        $identity = $this->resultDeliveryIdentity($input);
        $device = $this->authenticateDevice($publicId, $token);
        return Db::transaction(function () use ($device, $taskId, $identity): array {
            $task = $this->resultDeliveryTask($device, $taskId, true);
            $accepted = $this->acceptedResultDelivery($task, $identity);
            if ($accepted !== null) {
                return $accepted;
            }
            $this->assertResultAttempt($task, $identity);
            $status = (string)$task['status'];
            if (!in_array($status, ['leased', 'running', 'retry_wait', 'failed', 'result_unknown'], true)
                || (in_array($status, ['retry_wait', 'failed'], true)
                    && !in_array((string)$task['error_code'], ['lease_expired', 'upload_failed', 'network_error'], true))) {
                throw new RuntimeException('当前任务不可恢复结果上传，未重新执行采集。', 409);
            }
            $request = $this->decodeJson($task['request_json'] ?? null);
            $current = is_array($request['result_delivery'] ?? null) ? $request['result_delivery'] : [];
            if ($current !== [] && (int)($current['attempt'] ?? 0) === $identity['attempt']) {
                $this->assertResultIdentityMatches($current, $identity);
            }
            $request['result_delivery'] = $identity + ['status' => 'upload_pending', 'purpose' => 'upload_only'];
            $lease = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + self::LEASE_SECONDS);
            $task = $this->requireScopedTaskWrite($task, [
                'status' => 'running', 'lease_token_hash' => hash('sha256', $lease),
                'lease_expires_at' => $expires, 'finished_at' => null,
                'request_json' => json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'error_code' => '', 'error_summary' => '', 'update_time' => date('Y-m-d H:i:s'),
            ], true);
            // Reuse all current account, hotel mapping and permission checks.
            $this->leasedTask($device, $taskId, $lease);
            return $identity + [
                'status' => 'upload_ready', 'task_id' => $taskId,
                'lease_token' => $lease, 'lease_expires_at' => $expires,
            ];
        });
    }

    /** Decode exact JSON bytes; transport credentials never enter evidence. */
    private function decodeResultDeliveryEnvelope(array &$input): array
    {
        if (!array_key_exists('result_json', $input)) {
            return [];
        }
        $identity = $this->resultDeliveryIdentity($input);
        $json = $input['result_json'];
        if (!is_string($json) || strlen($json) > self::MAX_RESULT_BYTES
            || !hash_equals($identity['result_hash'], hash('sha256', $json))) {
            throw new RuntimeException('回传结果内容与指纹不一致。', 422);
        }
        try {
            $decoded = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('回传结果不是有效业务 JSON。', 422);
        }
        $allowedKeys = ($decoded['success'] ?? null) === false
            ? ['success', 'error_code', 'error_summary', 'capture_summary'] : ['success', 'rows', 'capture_summary'];
        if (!is_array($decoded) || !is_bool($decoded['success'] ?? null)
            || array_diff(array_keys($decoded), $allowedKeys) !== []) {
            throw new RuntimeException('可靠回传仅接受脱敏采集业务结果。', 422);
        }
        $this->assertNoSensitiveMaterial($decoded);
        $lease = (string)($input['lease_token'] ?? '');
        $input = $decoded;
        $input['lease_token'] = $lease;
        return $identity + ['result_json' => $json];
    }

    private function resultDeliveryIdentity(array $input): array
    {
        $id = $input['result_id'] ?? null;
        $hash = $input['result_hash'] ?? null;
        $attempt = $input['attempt'] ?? null;
        if (!is_string($id) || preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $id) !== 1
            || !is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1
            || !is_int($attempt) || $attempt <= 0) {
            throw new RuntimeException('回传结果标识、指纹或采集次数无效。', 422);
        }
        return ['result_id' => $id, 'result_hash' => $hash, 'attempt' => $attempt];
    }

    private function resultDeliveryTask(array $device, int $taskId, bool $lock = false): array
    {
        if ($lock && !is_array($this->activeDeviceQuery($device)->lock(true)->find())) {
            throw new RuntimeException('采集设备已不可用。', 403);
        }
        $query = Db::name('ota_local_collector_tasks')->where('id', $taskId)
            ->where('device_id', (int)$device['id'])->where('tenant_id', (int)$device['tenant_id'])
            ->where('user_id', (int)$device['user_id']);
        $task = $query->lock($lock)->find();
        if (!is_array($task)) {
            throw new RuntimeException('本机采集任务不存在。', 404);
        }
        $this->assertTaskIdentity($task, $device);
        $this->assertDeviceTaskPermission($device, $task);
        if (!in_array((string)$task['task_type'], ['collect', 'backfill'], true)) {
            throw new RuntimeException('登录任务不能使用业务结果补传。', 409);
        }
        $account = $this->scopedAccountQuery($task)->where('device_id', (int)$device['id'])->find();
        if (!is_array($account) || (string)$account['status'] === 'revoked') {
            throw new RuntimeException('本机采集账户已撤销或不匹配。', 403);
        }
        $mapping = $this->mappingForAccountHotel((int)$task['tenant_id'], (int)$task['account_id'], (int)$task['system_hotel_id'], (string)$task['platform']);
        $scope = $this->recoveryScope($task, $mapping);
        if ($scope['platform_hotel_id'] !== (string)$mapping['platform_hotel_id']) {
            throw new RuntimeException('原采集平台门店已变化，停止结果上传。', 409);
        }
        return $task;
    }

    private function assertResultAttempt(array $task, array $identity): void
    {
        if ((int)$task['attempt'] !== $identity['attempt']) {
            throw new RuntimeException('采集次数已变化，旧结果不能覆盖新任务。', 409);
        }
    }

    private function assertResultIdentityMatches(array $expected, array $actual): void
    {
        if ((string)($expected['result_id'] ?? '') !== $actual['result_id']
            || (int)($expected['attempt'] ?? 0) !== $actual['attempt']
            || !hash_equals((string)($expected['result_hash'] ?? ''), $actual['result_hash'])) {
            throw new RuntimeException('同一次采集已有不同的回传结果，已拒绝覆盖。', 409);
        }
    }

    private function acceptedResultDelivery(array $task, array $identity): ?array
    {
        $request = $this->decodeJson($task['request_json'] ?? null);
        $receipt = $request['result_delivery_receipts'][$this->resultReceiptKey($identity)]
            ?? $request['result_delivery_receipts'][$identity['result_hash']] ?? null;
        if (!is_array($receipt)) {
            return null;
        }
        // Older installations used the content hash alone. Identical content
        // from a new capture is not a replay of that older capture attempt.
        if (($receipt['result_id'] ?? '') !== $identity['result_id']
            || (int)($receipt['attempt'] ?? 0) !== $identity['attempt']) {
            return null;
        }
        $this->assertResultIdentityMatches($receipt, $identity);
        if (($receipt['status'] ?? '') !== 'accepted' || (int)($receipt['task_id'] ?? 0) !== (int)$task['id']) {
            throw new RuntimeException('回传保存回执不完整。', 409);
        }
        $this->assertDeliveryReceiptScope($task, $receipt);
        $reconciliation = $this->reconcileCollectionResult($task, $receipt);
        return [
            'status' => $reconciliation['status'] === 'unknown' ? 'result_unknown' : (string)$receipt['business_status'], 'task_id' => (int)$task['id'],
            'delivery' => $receipt, 'replayed' => true,
            'reconciliation' => $reconciliation,
            'next_action' => '原结果已接收；这是同一结果的保存回执，未重新采集或入库。',
        ];
    }

    private function validateResultDelivery(array $task, array $delivery): void
    {
        $request = $this->decodeJson($task['request_json'] ?? null);
        if ($delivery === []) {
            if (($request['result_delivery']['purpose'] ?? '') === 'upload_only') {
                throw new RuntimeException('结果上传租约必须提交绑定的完整结果指纹。', 409);
            }
            return;
        }
        $this->assertResultAttempt($task, $delivery);
        $expected = is_array($request['result_delivery'] ?? null) ? $request['result_delivery'] : [];
        $this->assertResultIdentityMatches($expected, $delivery);
        if (($expected['purpose'] ?? '') !== 'upload_only') {
            throw new RuntimeException('回传上传授权缺失。', 409);
        }
    }

    private function retainResultDelivery(array $task, array $delivery): array
    {
        if ($delivery === []) {
            return [];
        }
        $scope = ['tenant_id' => (int)$task['tenant_id'], 'device_id' => (int)$task['device_id'],
            'task_id' => (int)$task['id'], 'attempt' => $delivery['attempt']];
        $business = json_decode($delivery['result_json'], true, 128, JSON_THROW_ON_ERROR);
        $business = (new PlatformDataSyncService())->sanitizeCollectorBusinessEvidence($business);
        $json = json_encode($business, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        // Receipt identity covers exact received bytes; evidence identity covers
        // the complete redacted business input, not credentials or guest PII.
        $evidence = $this->resultEvidenceStore()->put($scope, $json, hash('sha256', $json));
        unset($delivery['result_json']);
        return $delivery + $scope + ['evidence' => $evidence];
    }

    /** Called inside the existing import transaction, before lease release. */
    private function acceptResultDelivery(array $task, array $delivery, array $summary, bool $fieldGap): array
    {
        if ($delivery === []) {
            return [];
        }
        $receipt = $delivery + [
            'status' => 'accepted', 'accepted_at' => date('Y-m-d H:i:s'),
            'system_hotel_id' => (int)$task['system_hotel_id'], 'platform' => (string)$task['platform'],
            'business_date' => (string)$task['data_date'], 'source_method' => 'local_account_profile',
            'business_status' => $fieldGap ? 'field_gap' : 'success',
            'saved_count' => (int)$summary['saved_count'], 'readback_verified' => $summary['readback_verified'] === true,
            'data_source_id' => (int)$summary['data_source_id'], 'sync_task_id' => (int)$summary['sync_task_id'],
            'row_ids' => $summary['deterministic_readback']['row_ids'] ?? [],
            'platform_hotel_id' => (string)($summary['scope_identity']['platform_hotel_id'] ?? ''),
            'data_type' => (string)$task['data_type'],
        ];
        $receipt['rows_fingerprint'] = $this->collectionRowsFingerprint($task, $receipt);
        $receipt['content_fingerprint'] = (new \app\service\OtaLocalCollectorReadbackProofService($this->resultEvidenceStore()))
            ->contentFingerprint($task, $receipt);
        $request = $this->decodeJson($task['request_json'] ?? null);
        $request['result_delivery'] = array_intersect_key($receipt, array_flip(['result_id', 'result_hash', 'attempt', 'status']));
        unset($request['recovery_check']);
        if ($fieldGap && !empty($summary['ordered_collection']['missing_field_keys'])) {
            $plan = \app\service\OtaOrderedCollectionPlanner::requestPlan((string)$task['platform'], (string)$task['data_date'],
                $summary['ordered_collection']['missing_field_keys'], 'saved_partial_gap_recovery');
            $request['sections'] = $plan['sections'];
            $request['ordered_collection'] = $plan;
        }
        $request['result_delivery_receipts'][$this->resultReceiptKey($delivery)] = $receipt;
        $this->requireLeasedTaskWrite($task, [
            'request_json' => json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        return $receipt;
    }

    private function resultEvidenceStore(): OtaLocalCollectorEvidenceStore
    {
        return $this->deliveryEvidenceStore ?? new OtaLocalCollectorEvidenceStore();
    }

    private function finalizeLocalHistory(array $receipt, int $tenantId, int $hotelId): array
    {
        $date = (string)($receipt['target_date'] ?? '');
        try {
            // A substituted importer must provide its matching finalizer; it
            // cannot silently validate synthetic/custom data using live DBs.
            if ($this->collectionImporter !== null && $this->canonicalHistoryFinalizer === null) {
                return $this->unavailableLocalHistory($tenantId, $hotelId, $date, 'custom_importer_finalizer_missing');
            }
            $result = $this->canonicalHistoryFinalizer !== null
                ? ($this->canonicalHistoryFinalizer)($receipt, $tenantId, $hotelId, 60)
                : (new \app\service\OtaCanonicalHistoryPromotionCoordinator())->finalize($receipt, $tenantId, $hotelId, 60);
            if (!is_array($result) || (int)($result['tenant_id'] ?? 0) !== $tenantId
                || (int)($result['hotel_id'] ?? 0) !== $hotelId
                || (string)($result['target_date'] ?? '') !== $date
                || ($result['sensitive_values_exposed'] ?? true) !== false) {
                return $this->unavailableLocalHistory($tenantId, $hotelId, $date, 'canonical_finalization_scope_invalid');
            }
            return $result;
        } catch (\Throwable) {
            return $this->unavailableLocalHistory($tenantId, $hotelId, $date, 'canonical_finalization_failed');
        }
    }

    private function unavailableLocalHistory(int $tenantId, int $hotelId, string $date, string $reason, string $status = 'blocked'): array
    {
        return [
            'status' => $status, 'reason' => $reason, 'tenant_id' => $tenantId,
            'hotel_id' => $hotelId, 'target_date' => $date, 'canonical_history_complete' => false,
            'promoted_platforms' => [], 'blocked_platforms' => self::PLATFORMS,
            'platform_results' => [], 'sensitive_values_exposed' => false,
        ];
    }

    private function resultReceiptKey(array $identity): string
    {
        return $identity['attempt'] . ':' . $identity['result_id'] . ':' . $identity['result_hash'];
    }

    private function assertDeliveryReceiptScope(array $task, array $receipt): void
    {
        foreach (['tenant_id', 'device_id', 'system_hotel_id'] as $key) {
            if ((int)($receipt[$key] ?? 0) !== (int)$task[$key]) {
                throw new RuntimeException('回传证据与任务范围不一致。', 409);
            }
        }
        if ((int)($receipt['task_id'] ?? 0) !== (int)$task['id']
            || (string)($receipt['platform'] ?? '') !== (string)$task['platform']
            || (string)($receipt['business_date'] ?? '') !== (string)$task['data_date']) {
            throw new RuntimeException('回传证据与任务日期或平台不一致。', 409);
        }
    }
}
