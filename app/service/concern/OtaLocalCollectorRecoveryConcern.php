<?php
declare(strict_types=1);

namespace app\service\concern;

use RuntimeException;
use think\facade\Db;

/** Recovery decisions refer to one original capture, never to a latest hotel total. */
trait OtaLocalCollectorRecoveryConcern
{
    /**
     * @param array<string,mixed> $task
     * @param array<string,mixed> $source
     * @param array<string,mixed> $device
     * @return array<string,mixed>
     */
    private function scheduledPlanTaskReceipt(
        array $task,
        array $source,
        array $device,
        string $dispatcherRunId,
        string $businessDate,
        bool $sessionPreflight
    ): array {
        $taskId = (int)($task['id'] ?? 0);
        $taskStatus = strtolower(trim((string)($task['status'] ?? '')));
        $platform = strtolower(trim((string)($source['platform'] ?? '')));
        $sourceId = (int)($source['id'] ?? 0);
        $deviceStatus = $this->effectiveDeviceStatus($device);
        $base = [
            'dispatcher_run_id' => $dispatcherRunId,
            'system_hotel_id' => (int)($source['system_hotel_id'] ?? 0),
            'target_date' => $businessDate,
            'platform' => $platform,
            'data_source_id' => $sourceId,
            'local_collector_task_id' => $taskId > 0 ? $taskId : null,
            'task_id' => $taskId > 0 ? $taskId : null,
            'success' => false,
            'saved_count' => 0,
            'readback_count' => 0,
            'readback_verified' => false,
            'run_readback' => [],
            'historical_core_contract_status' => 'blocked',
            'automatic_device_substitution' => false,
            'sensitive_values_exposed' => false,
        ];
        if ($sessionPreflight) {
            return [
                ...$base,
                'status' => $deviceStatus === 'device_offline'
                    ? 'device_offline'
                    : 'waiting_user_login',
                'reused_active_task' => true,
                'failure_reason' => $deviceStatus === 'device_offline'
                    ? 'device_offline'
                    : 'waiting_user_login',
                'message' => 'local_collector_session_recovery_queued',
            ];
        }

        $request = $this->decodeJson($task['request_json'] ?? null);
        if ($this->normalizeDispatcherRunId((string)(
            $request['dispatcher_run_id'] ?? ''
        )) !== $dispatcherRunId) {
            return [
                ...$base,
                'local_collector_task_id' => null,
                'task_id' => null,
                'status' => 'blocked',
                'failure_reason' => 'local_collector_plan_dispatcher_scope_mismatch',
                'message' => 'local_collector_plan_dispatcher_scope_mismatch',
            ];
        }

        $summary = $this->decodeJson($task['result_summary_json'] ?? null);
        $runReadback = is_array($summary['run_readback'] ?? null)
            ? $summary['run_readback']
            : [];
        $summaryDispatcher = $this->normalizeDispatcherRunId((string)(
            $summary['dispatcher_run_id']
            ?? $runReadback['dispatcher_run_id']
            ?? ''
        ));
        $syncTaskId = (int)(
            $summary['sync_task_id']
            ?? $runReadback['sync_task_id']
            ?? 0
        );
        $rowIds = array_values(array_unique(array_filter(array_map(
            'intval',
            is_array($runReadback['row_ids'] ?? null) ? $runReadback['row_ids'] : []
        ), static fn(int $id): bool => $id > 0)));
        $strictSuccess = $taskStatus === 'success'
            && $summaryDispatcher === $dispatcherRunId
            && (int)($summary['local_collector_task_id'] ?? 0) === $taskId
            && (int)($summary['data_source_id'] ?? 0) === $sourceId
            && $syncTaskId > 0
            && ($summary['readback_verified'] ?? false) === true
            && ($runReadback['readback_verified'] ?? false) === true
            && (int)($runReadback['data_source_id'] ?? 0) === $sourceId
            && (int)($runReadback['sync_task_id'] ?? 0) === $syncTaskId
            && (int)($runReadback['system_hotel_id'] ?? 0)
                === (int)($source['system_hotel_id'] ?? 0)
            && strtolower(trim((string)($runReadback['platform'] ?? ''))) === $platform
            && $this->normalizeDate((string)($runReadback['target_date'] ?? ''))
                === $businessDate
            && $this->normalizeDispatcherRunId((string)(
                $runReadback['dispatcher_run_id'] ?? ''
            )) === $dispatcherRunId
            && strtolower(trim((string)($runReadback['trigger_type'] ?? '')))
                === 'local_collector_upload'
            && $rowIds !== [];
        if ($strictSuccess) {
            $proof = $this->reconcileCollectionResult($task);
            $delivery = is_array($summary['result_delivery'] ?? null) ? $summary['result_delivery'] : [];
            $identity = is_array($request['result_delivery'] ?? null) ? $request['result_delivery'] : [];
            $originalIds = $rowIds;
            sort($originalIds, SORT_NUMERIC);
            $sameReceipt = (int)($delivery['attempt'] ?? 0) === (int)($task['attempt'] ?? 0)
                && (int)($delivery['attempt'] ?? 0) === (int)($identity['attempt'] ?? -1)
                && (string)($delivery['result_id'] ?? '') !== ''
                && ($delivery['result_id'] ?? '') === ($identity['result_id'] ?? null)
                && ($delivery['result_hash'] ?? '') === ($identity['result_hash'] ?? null)
                && (int)($delivery['data_source_id'] ?? 0) === $sourceId
                && (int)($delivery['sync_task_id'] ?? 0) === $syncTaskId;
            if (!$sameReceipt || ($proof['readback_verified'] ?? false) !== true
                || ($proof['content_readback_verified'] ?? false) !== true || ($proof['row_ids'] ?? []) !== $originalIds) {
                $reason = !$sameReceipt ? ($delivery === [] ? 'original_receipt_missing' : 'original_receipt_identity_mismatch')
                    : (($proof['readback_verified'] ?? false) !== true ? (string)($proof['reason_code'] ?? 'original_readback_unavailable')
                        : (($proof['content_readback_verified'] ?? false) !== true ? 'original_content_proof_missing' : 'original_row_readback_mismatch'));
                return [...$base, 'status' => 'result_unknown', 'saved_count' => max(0, (int)($summary['saved_count'] ?? 0)),
                    'readback_count' => null, 'platform_sync_task_id' => $syncTaskId,
                    'current_readback' => $proof, 'failure_reason' => $reason, 'message' => $reason];
            }
            return [
                ...$base,
                'status' => 'success',
                'success' => true,
                'platform_sync_task_id' => $syncTaskId,
                'saved_count' => max(0, (int)($summary['saved_count'] ?? 0)),
                'readback_count' => count($rowIds),
                'readback_verified' => true,
                'run_readback' => $runReadback,
                'historical_core_contract_status' => 'ready',
                'failure_reason' => '',
                'message' => 'local_collector_save_readback_verified',
            ];
        }

        $active = in_array($taskStatus, self::ACTIVE_TASK_STATUSES, true);
        $failureCode = $this->normalizeFailureCode(
            $task['error_code']
                ?? ($active ? 'collection_in_progress' : 'local_collector_result_not_verified')
        );
        return [
            ...$base,
            'status' => $deviceStatus === 'device_offline' && $active
                ? 'device_offline'
                : ($active ? ($taskStatus === 'queued' ? 'queued' : 'in_progress') : 'failed'),
            'reused_active_task' => $active,
            'failure_reason' => $deviceStatus === 'device_offline' && $active
                ? 'device_offline'
                : $failureCode,
            'message' => $active
                ? 'local_collector_task_in_progress'
                : 'local_collector_result_not_verified',
        ];
    }

    private function pendingResultDelivery(array $task): bool
    {
        $request = $this->decodeJson($task['request_json'] ?? null);
        $delivery = $request['result_delivery'] ?? [];
        return is_array($delivery) && ($delivery['status'] ?? '') === 'upload_pending'
            && (int)($delivery['attempt'] ?? 0) === (int)$task['attempt'];
    }

    private function recoveryScope(array $task, array $mapping): array
    {
        $request = $this->decodeJson($task['request_json'] ?? null);
        $summary = $this->decodeJson($task['result_summary_json'] ?? null);
        return [
            'tenant_id' => (int)$task['tenant_id'], 'system_hotel_id' => (int)$task['system_hotel_id'],
            'account_id' => (int)$task['account_id'], 'platform' => (string)$task['platform'],
            'platform_hotel_id' => (string)($request['collection_scope']['platform_hotel_id']
                ?? $summary['scope_identity']['platform_hotel_id'] ?? $mapping['platform_hotel_id'] ?? ''),
            'business_date' => (string)$task['data_date'], 'data_type' => (string)$task['data_type'],
            'source_method' => 'local_account_profile',
        ];
    }

    private function collectionRecoveryItem(array $task, array $mapping): array
    {
        $request = $this->decodeJson($task['request_json'] ?? null);
        $summary = $this->decodeJson($task['result_summary_json'] ?? null);
        $delivery = $request['result_delivery'] ?? [];
        $receipt = $summary['result_delivery'] ?? [];
        $check = $request['recovery_check'] ?? [];
        $scope = $this->recoveryScope($task, $mapping);
        $sameAttempt = (int)($receipt['attempt'] ?? 0) === (int)$task['attempt'];
        $saved = $sameAttempt && ($receipt['status'] ?? '') === 'accepted'
            && (int)($summary['saved_count'] ?? 0) > 0 && ($summary['readback_verified'] ?? false) === true;
        $unknown = $this->pendingResultDelivery($task) || (string)$task['status'] === 'result_unknown'
            || (($check['status'] ?? '') === 'unknown' && (int)($check['attempt'] ?? 0) === (int)$task['attempt']);
        $state = 'pending';
        $reason = '等待原任务执行并回传；无需重复提交。';
        $actions = ['reconcile'];
        $error = (string)($task['error_code'] ?? '');
        $missing = $this->sanitizeFieldKeys($summary['ordered_collection']['missing_field_keys'] ?? []);
        if ($scope['platform_hotel_id'] === '' || $scope['platform_hotel_id'] !== (string)($mapping['platform_hotel_id'] ?? '')) {
            $state = 'blocked';
            $reason = '原任务平台门店与当前绑定不一致，停止补采，请核对门店绑定。';
            $actions = [];
        } elseif ($unknown) {
            $state = 'unknown';
            $reason = '请求中断，保存结果尚未明确。先核对原回执与精确记录；保持原设备运行，仅补传同一结果，不重新采集。';
        } elseif ($saved) {
            $partial = ($receipt['business_status'] ?? '') === 'field_gap' || $missing !== [];
            $recovered = (int)$task['attempt'] > 1 || (int)($request['retry_of_task_id'] ?? 0) > 0
                || (int)($request['recovery_source_task_id'] ?? 0) > 0 || (int)($request['recovery_of_task_id'] ?? 0) > 0;
            $state = $partial ? 'partial' : ($recovered ? 'recovered_success' : 'success');
            $reason = $partial ? '本次已有记录保存并回读，仍有字段或核验缺口；仅补采原业务日的缺失模块。'
                : '本次采集、保存与精确回读已完成。历史事实门禁和另一平台状态另行显示。';
            if ($partial) $actions[] = 'backfill';
        } elseif (in_array($error, array_merge(self::LOGIN_ERRORS, self::VERIFICATION_ERRORS, ['redirect_unverified']), true)) {
            $state = 'blocked';
            $reason = $error === 'redirect_unverified'
                ? '平台返回重定向，不能据此判定登录失效。先在原设备验证会话和门店，必要时由用户完成登录。'
                : '当前缺少可用登录或人工验证证据；在原设备验证会话，用户完成必要登录后恢复原业务日。';
            $actions[] = 'verify_session';
        } elseif (in_array($error, ['identity_mismatch', 'permission_denied', 'device_revoked'], true)) {
            $state = 'blocked';
            $reason = '身份或权限不匹配，已阻止保存；请先核对酒店与平台门店绑定。';
        } elseif (in_array((string)$task['status'], ['failed', 'cancelled'], true)) {
            $state = 'failed';
            $reason = '采集失败且未确认业务保存；核对原记录后可重新补采同一业务日。';
            $actions[] = 'backfill';
        } elseif ((string)$task['status'] === 'retry_wait' && $error !== '') {
            $state = 'failed';
            $reason = '本次采集失败，已安排有限次数重试；保持原设备运行，原日期不变。';
        } elseif ((string)$task['status'] === 'success') {
            $state = 'unverified';
            $reason = '旧任务缺少本次完整证据，保留原记录，不补造回执或宣称已核验。';
        }
        return [
            'schema_version' => 'ota_collection_recovery.v1', 'task_id' => (int)$task['id'],
            'attempt' => (int)$task['attempt'], 'scope' => $scope, 'state' => $state,
            'reason' => $reason, 'actions' => $actions, 'missing_field_keys' => $missing,
            'missing_evidence' => $this->sanitizeFieldKeys($summary['sync_diagnostics']['missing_inputs'] ?? []),
            'requested_sections' => $this->sanitizeSections($request['sections'] ?? []),
            'source_receipt' => [
                'task_id' => (int)$task['id'], 'attempt' => (int)($delivery['attempt'] ?? $task['attempt']),
                'result_id' => (string)($delivery['result_id'] ?? ''), 'result_hash' => (string)($delivery['result_hash'] ?? ''),
                'data_source_id' => $saved ? (int)($summary['data_source_id'] ?? 0) : null,
                'sync_task_id' => $saved ? (int)($summary['sync_task_id'] ?? 0) : null,
            ],
            'stages' => [
                'capture' => $saved || $this->pendingResultDelivery($task) ? 'received' : ($state === 'failed' ? 'failed' : 'unconfirmed'),
                'save' => $saved ? 'saved' : ($unknown ? 'unknown' : 'unconfirmed'),
                'exact_readback' => $unknown ? 'unknown' : ($saved ? 'verified_at_receipt' : 'unconfirmed'),
            ],
            'last_check' => $check === [] ? null : $check,
            'recovery_task_id' => (int)($request['recovery_task_id'] ?? 0) ?: null,
        ];
    }

    /** Read original row IDs and scope again. Never import in a reconciliation. */
    private function reconcileCollectionResult(array $task, ?array $originalReceipt = null): array
    {
        $summary = $this->decodeJson($task['result_summary_json'] ?? null);
        $request = $this->decodeJson($task['request_json'] ?? null);
        $identity = $request['result_delivery'] ?? [];
        $receipt = $originalReceipt ?? $request['result_delivery_receipts'][$this->resultReceiptKey([
            'attempt' => (int)($identity['attempt'] ?? 0), 'result_id' => (string)($identity['result_id'] ?? ''),
            'result_hash' => (string)($identity['result_hash'] ?? ''),
        ])] ?? $request['result_delivery_receipts'][$identity['result_hash'] ?? ''] ?? null;
        $result = ['status' => 'unknown', 'attempt' => (int)$task['attempt'], 'checked_at' => date('c'),
            'reason_code' => 'original_receipt_missing', 'row_ids' => [], 'readback_verified' => false];
        if (!is_array($receipt) || ($receipt['status'] ?? '') !== 'accepted'
            || ($originalReceipt === null && (int)($receipt['attempt'] ?? 0) !== (int)$task['attempt'])) {
            // A completed failure before any upload is not an ambiguous save.
            if ($identity === [] && $summary === [] && in_array((string)$task['status'], ['failed', 'cancelled', 'login_required', 'verification_required'], true)) {
                $result['status'] = 'not_saved';
                $result['reason_code'] = 'capture_failed_before_upload';
            }
            return $result;
        }
        try {
            $this->assertDeliveryReceiptScope($task, $receipt);
            if ($originalReceipt === null) $this->assertResultIdentityMatches($receipt, $identity);
            return (new \app\service\OtaLocalCollectorReadbackProofService($this->resultEvidenceStore()))
                ->verifyReceipt($task, $receipt);
        } catch (\Throwable) {
            $result['reason_code'] = 'original_evidence_unavailable';
            return $result;
        }
    }

    private function collectionRowsFingerprint(array $task, array $receipt): ?string
    {
        return (new \app\service\OtaLocalCollectorReadbackProofService($this->resultEvidenceStore()))
            ->fingerprint($task, $receipt);
    }

    public function recoverCollectionTask(mixed $user, int $taskId, array $input): array
    {
        $actor = $this->actorContext($user);
        $initial = Db::name('ota_local_collector_tasks')->where('id', $taskId)
            ->where('tenant_id', $actor['tenant_id'])->where('user_id', $actor['user_id'])->find();
        if (!is_array($initial)) throw new RuntimeException('采集恢复任务不存在。', 404);
        $this->assertTaskIdentity($initial);
        $this->assertHotelPermission($actor, (int)$initial['system_hotel_id']);
        return Db::transaction(function () use ($actor, $initial, $input, $user): array {
            $device = $this->ownedDevice($actor, (int)$initial['device_id']);
            if (!is_array($this->activeDeviceQuery($device)->lock(true)->find())) throw new RuntimeException('设备不可用。', 403);
            $task = $this->scopedTaskQuery($initial, true)->lock(true)->find();
            if (!is_array($task) || !in_array((string)$task['task_type'], ['collect', 'backfill'], true)) throw new RuntimeException('不是业务采集恢复任务。', 409);
            $account = $this->ownedAccount($actor, (int)$task['account_id']);
            if ((int)$account['device_id'] !== (int)$task['device_id'] || (string)$account['platform'] !== (string)$task['platform']) {
                throw new RuntimeException('原任务账号和设备范围不一致。', 409);
            }
            $mapping = $this->mappingForAccountHotel((int)$task['tenant_id'], (int)$task['account_id'], (int)$task['system_hotel_id'], (string)$task['platform']);
            $item = $this->collectionRecoveryItem($task, $mapping);
            foreach ($item['scope'] as $key => $value) {
                if ((string)($input['scope'][$key] ?? '') !== (string)$value) throw new RuntimeException('恢复范围已变化，请刷新原任务。', 409);
            }
            if ((int)($input['attempt'] ?? -1) !== (int)$task['attempt']
                || (string)($input['result_hash'] ?? '') !== $item['source_receipt']['result_hash']
                || (string)($input['result_id'] ?? '') !== $item['source_receipt']['result_id']) throw new RuntimeException('采集次数或原结果已变化，请刷新任务。', 409);
            $action = (string)($input['action'] ?? 'reconcile');
            if (!in_array($action, $item['actions'], true)) throw new RuntimeException('当前状态不允许此恢复动作。', 409);
            if ($action !== 'reconcile') {
                $owner = \app\model\User::where('id', (int)$task['user_id'])->where('tenant_id', (int)$task['tenant_id'])->where('status', 1)->find();
                if (!$owner || !$owner->hasHotelPermission((int)$task['system_hotel_id'], 'can_fetch_online_data')) {
                    throw new RuntimeException('当前账号没有原酒店的采集权限。', 403);
                }
            }
            $request = $this->decodeJson($task['request_json'] ?? null);
            $replayed = (int)($request['recovery_task_id'] ?? 0) > 0;
            $request['recovery_check'] = $this->reconcileCollectionResult($task);
            $task['request_json'] = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $item = $this->collectionRecoveryItem($task, $mapping);
            if ($action !== 'reconcile' && $request['recovery_check']['status'] !== 'unknown') {
                // One persisted child per original attempt, even after response loss.
                $childId = (int)($request['recovery_task_id'] ?? 0);
                if ($childId <= 0) {
                    $plan = \app\service\OtaOrderedCollectionPlanner::requestPlan((string)$task['platform'], (string)$task['data_date'], $item['missing_field_keys'], 'original_task_recovery');
                    $child = $action === 'verify_session' ? ['task' => $this->enqueueTask(
                        $actor, $account, $mapping, 'session_probe', null, 'session', [
                            'sections' => [], 'reason' => 'original_task_session_verification',
                            'resume_collections' => [[
                                'task_type' => 'backfill', 'system_hotel_id' => (int)$task['system_hotel_id'],
                                'data_date' => (string)$task['data_date'], 'data_type' => (string)$task['data_type'],
                                'missing_field_keys' => $item['missing_field_keys'], 'reason' => 'original_task_recovery',
                                'request' => ['sections' => $plan['sections'], 'ordered_collection' => $plan,
                                    'recovery_source_task_id' => (int)$task['id'], 'recovery_source_receipt' => $item['source_receipt']],
                            ]],
                        ], false, 95, true
                    )] : $this->createTask($user, [
                        'account_id' => (int)$task['account_id'], 'system_hotel_id' => (int)$task['system_hotel_id'],
                        'task_type' => 'backfill',
                        'data_date' => (string)$task['data_date'], 'data_type' => (string)$task['data_type'],
                        'missing_field_keys' => $item['missing_field_keys'], 'reason' => 'original_task_recovery',
                    ]);
                    $childId = (int)($child['task']['id'] ?? $child['id'] ?? 0);
                    if ($childId <= 0) throw new RuntimeException('恢复任务回读失败。', 409);
                    if ($childId !== (int)$task['id']) {
                        // Query by child ID with independently asserted original ownership.
                        $childTask = Db::name('ota_local_collector_tasks')->where('id', $childId)
                            ->where('tenant_id', $task['tenant_id'])->where('user_id', $task['user_id'])
                            ->where('account_id', $task['account_id'])->where('system_hotel_id', $task['system_hotel_id'])->find();
                        if (!is_array($childTask)) throw new RuntimeException('恢复任务范围回读失败。', 409);
                        $childRequest = $this->decodeJson($childTask['request_json'] ?? null);
                        $childRequest['recovery_source_task_id'] = (int)$task['id'];
                        $childRequest['recovery_source_receipt'] = $item['source_receipt'];
                        $this->requireScopedTaskWrite($childTask, ['request_json' => json_encode($childRequest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)], true);
                    }
                    if ($childId !== (int)$task['id']) $request['recovery_task_id'] = $childId;
                }
                if ($childId > 0 && $childId !== (int)$task['id']) {
                    $childReadback = Db::name('ota_local_collector_tasks')->where('id', $childId)
                        ->where('tenant_id', $task['tenant_id'])->where('user_id', $task['user_id'])
                        ->where('account_id', $task['account_id'])->where('device_id', $task['device_id'])
                        ->where('system_hotel_id', $task['system_hotel_id'])->where('platform', $task['platform'])->find();
                    $childRequest = is_array($childReadback) ? $this->decodeJson($childReadback['request_json'] ?? null) : [];
                    if (!is_array($childReadback) || (int)($childRequest['recovery_source_task_id'] ?? 0) !== (int)$task['id']
                        || (in_array($childReadback['task_type'], ['collect', 'backfill'], true) && $childReadback['data_date'] !== $task['data_date'])) {
                        throw new RuntimeException('已关联恢复任务的范围或原回执关系不一致。', 409);
                    }
                }
            }
            $task = $this->requireScopedTaskWrite($task, ['request_json' => json_encode($request, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)], true);
            return ['recovery' => $this->collectionRecoveryItem($task, $mapping), 'replayed' => $replayed,
                'business_writes' => false, 'message' => $request['recovery_check']['status'] === 'unknown'
                    ? '原结果仍未知，未重新采集或写入；请保持原设备运行以恢复同一结果。' : '已核对原任务，恢复状态已保存并精确回读。'];
        });
    }
}
