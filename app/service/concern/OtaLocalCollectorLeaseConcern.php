<?php
declare(strict_types=1);

namespace app\service\concern;

use app\exception\OtaLocalCollectorLeaseConflict;
use RuntimeException;
use think\facade\Db;

trait OtaLocalCollectorLeaseConcern
{
    /** @return array<int, string> */
    private function leasedTaskStatuses(): array
    {
        return ['leased', 'running', 'waiting_user_login', 'verification_required'];
    }

    private function exactWriteReadbackMatches(array $readback, array $values): bool
    {
        foreach ($values as $field => $expected) {
            if (!array_key_exists($field, $readback)) {
                return false;
            }
            $actual = $readback[$field];
            if ($expected === null || $actual === null) {
                if ($actual !== $expected) {
                    return false;
                }
                continue;
            }
            if (is_scalar($expected) && is_scalar($actual)) {
                if ((string)$actual !== (string)$expected) {
                    return false;
                }
                continue;
            }
            if ($actual !== $expected) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string, mixed> */
    private function requireLeasedTaskWrite(
        array $task,
        array $values,
        ?array $allowedStatuses = null,
        ?string $now = null
    ): array {
        $leaseHash = (string)($task['lease_token_hash'] ?? '');
        $attempt = (int)($task['attempt'] ?? 0);
        $now = $now ?: date('Y-m-d H:i:s');
        if ($leaseHash === '' || $attempt <= 0) {
            throw new OtaLocalCollectorLeaseConflict('local_collector_lease_fence_missing', 409);
        }
        $updated = $this->scopedTaskQuery($task, true)
            ->where('lease_token_hash', $leaseHash)
            ->where('attempt', $attempt)
            ->whereIn('status', $allowedStatuses ?: $this->leasedTaskStatuses())
            ->where('lease_expires_at', '>=', $now)
            ->update($values);
        if ($updated !== 1) {
            throw new OtaLocalCollectorLeaseConflict('local_collector_lease_lost', 409);
        }
        $readback = $this->scopedTaskQuery($task, true)->find();
        if (!is_array($readback) || !$this->exactWriteReadbackMatches($readback, $values)) {
            throw new OtaLocalCollectorLeaseConflict('local_collector_lease_writeback_mismatch', 409);
        }
        return $readback;
    }

    /** @return array<string, mixed> */
    private function lockLeasedTaskForImport(array $device, array $task): array
    {
        $now = date('Y-m-d H:i:s');
        $leaseHash = (string)($task['lease_token_hash'] ?? '');
        $attempt = (int)($task['attempt'] ?? 0);
        $locked = $this->scopedTaskQuery($task, true)
            ->where('lease_token_hash', $leaseHash)
            ->where('attempt', $attempt)
            ->whereIn('status', $this->leasedTaskStatuses())
            ->where('lease_expires_at', '>=', $now)
            ->lock(true)
            ->find();
        if (!is_array($locked) || $leaseHash === '' || $attempt <= 0) {
            throw new OtaLocalCollectorLeaseConflict('local_collector_lease_lost_before_import', 409);
        }
        $this->assertTaskIdentity($locked, $device);
        $this->assertDeviceTaskPermission($device, $locked);
        $account = $this->scopedAccountQuery($locked)
            ->where('device_id', (int)$device['id'])
            ->find();
        if (!is_array($account) || (string)($account['status'] ?? '') === 'revoked') {
            throw new OtaLocalCollectorLeaseConflict('local_collector_account_scope_lost_before_import', 409);
        }
        $mapping = $this->mappingForAccountHotel(
            (int)$locked['tenant_id'],
            (int)$locked['account_id'],
            (int)$locked['system_hotel_id'],
            (string)$locked['platform']
        );
        if ((int)($mapping['tenant_id'] ?? 0) !== (int)$locked['tenant_id']
            || (int)($mapping['account_id'] ?? 0) !== (int)$locked['account_id']
            || (int)($mapping['system_hotel_id'] ?? 0) !== (int)$locked['system_hotel_id']
            || strtolower(trim((string)($mapping['platform'] ?? ''))) !== strtolower(trim((string)$locked['platform']))
        ) {
            throw new OtaLocalCollectorLeaseConflict('local_collector_mapping_scope_lost_before_import', 409);
        }

        $submissionExpiry = date('Y-m-d H:i:s', time() + 3600);
        $extensionValues = [
            'lease_expires_at' => $submissionExpiry,
            'update_time' => $now,
        ];
        $updated = $this->scopedTaskQuery($locked, true)
            ->where('lease_token_hash', $leaseHash)
            ->where('attempt', $attempt)
            ->whereIn('status', $this->leasedTaskStatuses())
            ->where('lease_expires_at', '>=', $now)
            ->update($extensionValues);
        $readback = $this->scopedTaskQuery($locked, true)
            ->where('lease_token_hash', $leaseHash)
            ->where('attempt', $attempt)
            ->whereIn('status', $this->leasedTaskStatuses())
            ->where('lease_expires_at', '>=', $now)
            ->find();
        if ($updated !== 1 && (!is_array($readback) || !$this->exactWriteReadbackMatches($readback, $extensionValues))) {
            throw new OtaLocalCollectorLeaseConflict('local_collector_lease_lost_before_import', 409);
        }
        if (!is_array($readback) || !$this->exactWriteReadbackMatches($readback, $extensionValues)) {
            throw new OtaLocalCollectorLeaseConflict('local_collector_lease_extension_readback_mismatch', 409);
        }
        return $readback;
    }

    /** @return array<string, mixed> */
    private function requireCompletedTaskWrite(
        array $task,
        string $status,
        string $finishedAt,
        array $values
    ): array {
        $updated = $this->scopedTaskQuery($task, true)
            ->where('status', $status)
            ->where('finished_at', $finishedAt)
            ->where('lease_token_hash', '')
            ->update($values);
        if ($updated !== 1) {
            throw new OtaLocalCollectorLeaseConflict('local_collector_completed_task_fence_lost', 409);
        }
        $readback = $this->scopedTaskQuery($task, true)
            ->where('status', $status)
            ->where('finished_at', $finishedAt)
            ->find();
        if (!is_array($readback) || !$this->exactWriteReadbackMatches($readback, $values)) {
            throw new OtaLocalCollectorLeaseConflict('local_collector_completed_task_readback_mismatch', 409);
        }
        return $readback;
    }

    private function recoverExpiredLeases(array $device): void
    {
        $terminalNotifications = [];
        $now = date('Y-m-d H:i:s');
        Db::transaction(function () use ($device, $now, &$terminalNotifications): void {
            $rows = Db::name('ota_local_collector_tasks')
                ->where('device_id', (int)$device['id'])
                ->where('tenant_id', (int)$device['tenant_id'])
                ->where('user_id', (int)$device['user_id'])
                ->where('account_id', '>', 0)
                ->where('system_hotel_id', '>', 0)
                ->whereIn('platform', self::PLATFORMS)
                ->whereIn('status', $this->leasedTaskStatuses())
                ->where('lease_expires_at', '<', $now)
                ->limit(20)
                ->lock(true)
                ->select()
                ->toArray();
            foreach ($rows as $row) {
                if ($this->taskIdentity($row) === null) {
                    throw new RuntimeException('租约恢复任务身份不完整，已停止回收。', 409);
                }
                $attempt = (int)($row['attempt'] ?? 0);
                $maxAttempts = max(1, (int)($row['max_attempts'] ?? 3));
                $previousStatus = (string)($row['status'] ?? '');
                $previousLeaseHash = (string)($row['lease_token_hash'] ?? '');
                $previousExpiry = (string)($row['lease_expires_at'] ?? '');
                $requiresUser = in_array($previousStatus, ['waiting_user_login', 'verification_required'], true);
                $retry = !$requiresUser && $attempt < $maxAttempts;
                $terminalStatus = $previousStatus === 'waiting_user_login'
                    ? 'login_required'
                    : ($previousStatus === 'verification_required' ? 'verification_required' : 'failed');
                $nextRetryAt = $retry ? date('Y-m-d H:i:s', strtotime($now) + 60) : null;
                $errorCode = $previousStatus === 'waiting_user_login'
                    ? 'login_required'
                    : ($previousStatus === 'verification_required' ? 'verification_required' : 'lease_expired');
                $errorSummary = $requiresUser
                    ? '本机人工登录或验证未在任务租约内完成，请重新发起登录任务。'
                    : '本机采集器执行中断，任务租约已过期。';
                $taskValues = [
                    'status' => $retry ? 'retry_wait' : $terminalStatus,
                    'available_at' => $nextRetryAt ?: $now,
                    'lease_token_hash' => '',
                    'lease_expires_at' => null,
                    'error_code' => $errorCode,
                    'error_summary' => $errorSummary,
                    'finished_at' => $retry ? null : $now,
                    'update_time' => $now,
                ];
                $updated = $this->scopedTaskQuery($row, true)
                    ->where('status', $previousStatus)
                    ->where('attempt', $attempt)
                    ->where('lease_token_hash', $previousLeaseHash)
                    ->where('lease_expires_at', $previousExpiry)
                    ->where('lease_expires_at', '<', $now)
                    ->update($taskValues);
                if ($updated !== 1) {
                    continue;
                }
                $taskReadback = $this->scopedTaskQuery($row, true)
                    ->where('status', $retry ? 'retry_wait' : $terminalStatus)
                    ->find();
                if (!is_array($taskReadback) || !$this->exactWriteReadbackMatches($taskReadback, $taskValues)) {
                    throw new RuntimeException('lease_recovery_task_readback_mismatch', 409);
                }
                $account = $this->scopedAccountQuery($row)
                    ->where('device_id', (int)$device['id'])
                    ->find();
                if (!is_array($account)) {
                    throw new RuntimeException('租约恢复账户不存在，已停止回收。', 409);
                }
                $accountReadback = $this->requireScopedAccountWrite(
                    $row,
                    (int)$device['id'],
                    [
                        'status' => $retry ? 'retry_wait' : $terminalStatus,
                        'session_status' => $requiresUser ? $terminalStatus : (string)($account['session_status'] ?? 'unverified'),
                        'last_error_code' => $errorCode,
                        'last_error_summary' => $errorSummary,
                        'retry_count' => $attempt,
                        'next_retry_at' => $nextRetryAt,
                        'update_time' => $now,
                    ],
                    'lease_recovery_account_readback_mismatch'
                );
                if (!$retry) {
                    $terminalNotifications[] = [$row, $accountReadback, $errorCode, $errorSummary];
                }
            }
        });
        foreach ($terminalNotifications as $notification) {
            $this->notifyTerminalFailure($notification[0], $notification[1], $notification[2], $notification[3]);
        }
    }

    private function failLeasedTask(array $task, string $code, string $message): bool
    {
        $now = date('Y-m-d H:i:s');
        try {
            $readback = $this->requireLeasedTaskWrite($task, [
                'status' => 'failed',
                'error_code' => $code,
                'error_summary' => $this->safeText($message, 500),
                'lease_token_hash' => '',
                'lease_expires_at' => null,
                'finished_at' => $now,
                'update_time' => $now,
            ], null, $now);
        } catch (OtaLocalCollectorLeaseConflict) {
            return false;
        }
        return (string)($readback['status'] ?? '') === 'failed'
            && (string)($readback['error_code'] ?? '') === $code;
    }
}
