<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use think\facade\Db;

/**
 * Durable idempotency and attempt ledger for manual notification delivery.
 *
 * The ledger stores only sanitized payload snapshots and provider summaries.
 * Webhooks, credentials, headers, Cookies and raw provider responses are never
 * accepted by this boundary.
 */
final class ManualNotificationDispatchLedgerService
{
    public const RENDER_CONTRACT_VERSION = 'operating_target_wecom.v1';

    /**
     * @param array<string, mixed> $candidate
     * @return array{claimed: bool, dispatch: array<string, mixed>}
     */
    public function claim(
        int $notificationId,
        int $tenantId,
        int $hotelId,
        string $dispatchWindow,
        string $deliveryMode,
        string $triggerType,
        string $requestKind,
        int $robotId,
        string $robotName,
        string $businessDate,
        array $candidate,
        DateTimeImmutable $now,
        string $initialStatus = 'claimed',
        string $resultCode = 'dispatch_claimed',
        ?string $resultMessage = null
    ): array {
        $this->assertTables();
        if ($notificationId <= 0 || $tenantId <= 0 || $hotelId <= 0 || $robotId <= 0) {
            throw new \InvalidArgumentException('manual_notification_dispatch_scope_invalid');
        }
        $dispatchWindow = $this->dispatchWindow($dispatchWindow);
        $deliveryMode = $this->token($deliveryMode, 16, 'manual_notification_delivery_mode_invalid');
        $requestKind = $this->token($requestKind, 32, 'manual_notification_request_kind_invalid');
        $timestamp = $now->format('Y-m-d H:i:s');
        $payload = is_array($candidate['payload'] ?? null) ? $candidate['payload'] : null;
        $payloadFingerprint = trim((string)($candidate['preview_fingerprint'] ?? ''));
        if ($payload !== null && preg_match('/^[a-f0-9]{64}$/', $payloadFingerprint) !== 1) {
            $payloadFingerprint = hash('sha256', $this->json($payload));
        }
        if ($payload === null) {
            $payloadFingerprint = preg_match('/^[a-f0-9]{64}$/', $payloadFingerprint) === 1
                ? $payloadFingerprint
                : null;
        }

        $data = [
            'notification_id' => $notificationId,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'dispatch_window' => $dispatchWindow,
            'delivery_mode' => $deliveryMode,
            'trigger_type' => $this->safeText($triggerType, 32),
            'request_kind' => $requestKind,
            'business_date' => $this->dateOrNull($businessDate),
            'payload_fingerprint' => $payloadFingerprint,
            'operating_target_record_id' => $this->positiveOrNull(
                $candidate['operating_target_record_id'] ?? null
            ),
            'snapshot_revision_no' => $this->positiveOrNull(
                $candidate['snapshot_revision_no'] ?? null
            ),
            'render_contract_version' => self::RENDER_CONTRACT_VERSION,
            'payload_snapshot_json' => $payload === null ? null : $this->json($payload),
            'attempt_count' => 0,
            'max_attempts' => 3,
            'next_retry_at' => null,
            'last_attempt_at' => null,
            'response_reference' => null,
            'robot_id' => $robotId,
            'robot_name' => $this->safeText($robotName, 120),
            'status' => $this->safeText($initialStatus, 24),
            'result_code' => $this->safeText($resultCode, 64),
            'result_message' => $resultMessage === null ? null : $this->safeText($resultMessage, 255),
            'claimed_at' => $timestamp,
            'dispatched_at' => null,
            'create_time' => $timestamp,
            'update_time' => $timestamp,
        ];

        try {
            $dispatchId = (int)Db::name('manual_notification_schedule_dispatches')
                ->insertGetId($data);
            if ($dispatchId <= 0) {
                throw new \RuntimeException('manual_notification_dispatch_claim_failed');
            }
            $row = $this->findDispatch($dispatchId);
            return ['claimed' => true, 'dispatch' => $this->present($row)];
        } catch (\Throwable $exception) {
            if (!$this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }
            $existing = Db::name('manual_notification_schedule_dispatches')
                ->where('notification_id', $notificationId)
                ->where('dispatch_window', $dispatchWindow)
                ->where('delivery_mode', $deliveryMode)
                ->find();
            if (is_array($existing)) {
                if ($initialStatus === 'claimed') {
                    $reopened = $this->reopenBlockedDispatch((int)$existing['id'], $data);
                    if ($reopened !== null) {
                        return ['claimed' => true, 'dispatch' => $this->present($reopened)];
                    }
                }
                return ['claimed' => false, 'dispatch' => $this->present($existing)];
            }
            throw $exception;
        }
    }

    /**
     * Persist the external side-effect boundary before calling the sender.
     *
     * @return array{allowed: bool, reason_code: string, attempt_id?: int, attempt_no?: int}
     */
    public function beginAttempt(int $dispatchId, DateTimeImmutable $now): array
    {
        $this->assertTables();
        return Db::transaction(function () use ($dispatchId, $now): array {
            $row = Db::name('manual_notification_schedule_dispatches')
                ->where('id', $dispatchId)
                ->lock(true)
                ->find();
            if (!is_array($row)) {
                throw new \RuntimeException('manual_notification_dispatch_not_found');
            }
            $status = strtolower(trim((string)($row['status'] ?? '')));
            if (in_array($status, ['sent', 'sending', 'outcome_unknown', 'blocked'], true)) {
                return ['allowed' => false, 'reason_code' => 'dispatch_status_' . $status];
            }
            $attemptNo = (int)($row['attempt_count'] ?? 0) + 1;
            $maxAttempts = max(1, (int)($row['max_attempts'] ?? 3));
            if ($attemptNo > $maxAttempts) {
                return ['allowed' => false, 'reason_code' => 'dispatch_attempt_limit_reached'];
            }

            $timestamp = $now->format('Y-m-d H:i:s');
            Db::name('manual_notification_schedule_dispatches')
                ->where('id', $dispatchId)
                ->update([
                    'status' => 'sending',
                    'result_code' => 'delivery_attempt_started',
                    'result_message' => null,
                    'attempt_count' => $attemptNo,
                    'last_attempt_at' => $timestamp,
                    'next_retry_at' => null,
                    'update_time' => $timestamp,
                ]);
            $attemptId = (int)Db::name('manual_notification_dispatch_attempts')->insertGetId([
                'dispatch_id' => $dispatchId,
                'notification_id' => (int)$row['notification_id'],
                'tenant_id' => (int)$row['tenant_id'],
                'hotel_id' => (int)$row['hotel_id'],
                'attempt_no' => $attemptNo,
                'request_kind' => (string)($row['request_kind'] ?? 'scheduled'),
                'status' => 'sending',
                'result_code' => 'delivery_attempt_started',
                'result_message' => null,
                'payload_fingerprint' => $row['payload_fingerprint'] ?? null,
                'response_reference' => null,
                'attempted_at' => $timestamp,
                'create_time' => $timestamp,
            ]);
            if ($attemptId <= 0) {
                throw new \RuntimeException('manual_notification_dispatch_attempt_save_failed');
            }
            return [
                'allowed' => true,
                'reason_code' => 'delivery_attempt_started',
                'attempt_id' => $attemptId,
                'attempt_no' => $attemptNo,
            ];
        });
    }

    /**
     * @param array<string, mixed> $delivery
     * @return array<string, mixed>
     */
    public function finishAttempt(
        int $dispatchId,
        int $attemptId,
        array $delivery,
        DateTimeImmutable $now,
        ?\Throwable $exception = null
    ): array {
        $this->assertTables();
        $outcome = $this->deliveryOutcome($delivery, $exception);
        $timestamp = $now->format('Y-m-d H:i:s');
        Db::transaction(function () use ($dispatchId, $attemptId, $outcome, $timestamp): void {
            Db::name('manual_notification_dispatch_attempts')
                ->where('id', $attemptId)
                ->where('dispatch_id', $dispatchId)
                ->update([
                    'status' => $outcome['status'],
                    'result_code' => $outcome['result_code'],
                    'result_message' => $outcome['result_message'],
                    'response_reference' => $outcome['response_reference'],
                ]);
            Db::name('manual_notification_schedule_dispatches')
                ->where('id', $dispatchId)
                ->update([
                    'status' => $outcome['status'],
                    'result_code' => $outcome['result_code'],
                    'result_message' => $outcome['result_message'],
                    'response_reference' => $outcome['response_reference'],
                    'next_retry_at' => null,
                    'dispatched_at' => $timestamp,
                    'update_time' => $timestamp,
                ]);
        });

        return $this->present($this->findDispatch($dispatchId));
    }

    /** @return array<string, mixed> */
    public function dispatchForRetry(int $tenantId, int $hotelId, int $dispatchId): array
    {
        $this->assertTables();
        $row = Db::name('manual_notification_schedule_dispatches')
            ->where('id', $dispatchId)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->find();
        if (!is_array($row)) {
            throw new \RuntimeException('manual_notification_dispatch_not_found');
        }
        if ((string)($row['status'] ?? '') !== 'failed') {
            throw new \InvalidArgumentException('manual_notification_retry_status_forbidden');
        }
        if ((int)($row['attempt_count'] ?? 0) >= max(1, (int)($row['max_attempts'] ?? 3))) {
            throw new \InvalidArgumentException('manual_notification_retry_limit_reached');
        }
        $payload = $this->decodePayload($row['payload_snapshot_json'] ?? null);
        if ($payload === null) {
            throw new \RuntimeException('manual_notification_retry_payload_missing');
        }
        return [
            'dispatch' => $this->present($row),
            'payload' => $payload,
            'robot_id' => (int)$row['robot_id'],
            'robot_name' => (string)$row['robot_name'],
            'notification_id' => (int)$row['notification_id'],
        ];
    }

    public function markStaleSendingAsOutcomeUnknown(
        int $hotelId,
        int $robotId,
        DateTimeImmutable $now,
        int $staleAfterSeconds = 300
    ): int {
        $this->assertTables();
        if ($hotelId <= 0 || $robotId <= 0) {
            throw new \InvalidArgumentException('manual_notification_dispatch_scope_invalid');
        }
        $staleAfterSeconds = max(180, min(3600, $staleAfterSeconds));
        $cutoff = $now->modify('-' . $staleAfterSeconds . ' seconds')->format('Y-m-d H:i:s');
        $ids = Db::name('manual_notification_schedule_dispatches')
            ->where('hotel_id', $hotelId)
            ->where('robot_id', $robotId)
            ->where('status', 'sending')
            ->where('last_attempt_at', '<=', $cutoff)
            ->order('id', 'asc')
            ->column('id');
        $marked = 0;
        foreach ($ids as $id) {
            $marked += Db::transaction(
                function () use ($id, $hotelId, $robotId, $cutoff, $now): int {
                    $row = Db::name('manual_notification_schedule_dispatches')
                        ->where('id', (int)$id)
                        ->where('hotel_id', $hotelId)
                        ->where('robot_id', $robotId)
                        ->lock(true)
                        ->find();
                    if (!is_array($row)
                        || (string)($row['status'] ?? '') !== 'sending'
                        || trim((string)($row['last_attempt_at'] ?? '')) === ''
                        || (string)$row['last_attempt_at'] > $cutoff
                    ) {
                        return 0;
                    }
                    $timestamp = $now->format('Y-m-d H:i:s');
                    $message = '发送进程中断且未取得企业微信业务回执；结果标记为未知，禁止自动重发。';
                    Db::name('manual_notification_dispatch_attempts')
                        ->where('dispatch_id', (int)$id)
                        ->where('status', 'sending')
                        ->update([
                            'status' => 'outcome_unknown',
                            'result_code' => 'delivery_process_interrupted_outcome_unknown',
                            'result_message' => $message,
                        ]);
                    $updated = Db::name('manual_notification_schedule_dispatches')
                        ->where('id', (int)$id)
                        ->where('status', 'sending')
                        ->update([
                            'status' => 'outcome_unknown',
                            'result_code' => 'delivery_process_interrupted_outcome_unknown',
                            'result_message' => $message,
                            'next_retry_at' => null,
                            'update_time' => $timestamp,
                        ]);
                    return $updated > 0 ? 1 : 0;
                }
            );
        }
        return $marked;
    }

    /** @return array<string, mixed> */
    public function history(int $tenantId, int $hotelId, int $limit = 50): array
    {
        $this->assertTables();
        $limit = max(1, min(100, $limit));
        $rows = Db::name('manual_notification_schedule_dispatches')
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->order('id', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();

        $list = [];
        foreach ($rows as $row) {
            $item = $this->present($row);
            $item['attempts'] = array_map(
                fn(array $attempt): array => $this->presentAttempt($attempt),
                Db::name('manual_notification_dispatch_attempts')
                    ->where('dispatch_id', (int)$row['id'])
                    ->order('attempt_no', 'asc')
                    ->select()
                    ->toArray()
            );
            $list[] = $item;
        }
        return [
            'list' => $list,
            'total' => (int)Db::name('manual_notification_schedule_dispatches')
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->count(),
        ];
    }

    /** @return array<string, mixed> */
    public function latestScheduleRun(int $hotelId, int $robotId): array
    {
        if ($hotelId <= 0 || $robotId <= 0) {
            return [
                'status' => 'scope_missing',
                'message' => '缺少已验证的酒店和测试群机器人范围，未读取云端调度运行记录。',
            ];
        }
        if (!$this->tableExists('manual_notification_schedule_runs')) {
            return [
                'status' => 'not_deployed',
                'message' => '尚未取得云端调度运行记录。',
            ];
        }
        $row = Db::name('manual_notification_schedule_runs')
            ->where('scope_hotel_id', $hotelId)
            ->where('scope_robot_id', $robotId)
            ->order('id', 'desc')
            ->find();
        if (!is_array($row)) {
            return [
                'status' => 'not_run',
                'message' => '调度表已安装，但尚无运行记录。',
            ];
        }
        $observedAt = strtotime((string)$row['observed_at']);
        $ageSeconds = $observedAt === false ? null : max(0, time() - $observedAt);
        $runStatus = (string)$row['status'];
        $dispatchRequested = (int)$row['dispatch_requested'] === 1;
        $status = $runStatus === 'completed' && $ageSeconds !== null && $ageSeconds <= 300
            ? ($dispatchRequested ? 'test_scope_ready' : 'preview_only')
            : match ($runStatus) {
                'failed' => 'failed',
                'blocked' => 'blocked',
                default => 'stale',
            };
        return [
            'status' => $status,
            'run_status' => $runStatus,
            'run_id' => (int)$row['id'],
            'runner_mode' => (string)$row['runner_mode'],
            'dispatch_requested' => $dispatchRequested,
            'scope_hotel_id' => (int)$row['scope_hotel_id'],
            'scope_robot_id' => (int)$row['scope_robot_id'],
            'observed_at' => (string)$row['observed_at'],
            'age_seconds' => $ageSeconds,
            'candidate_count' => (int)$row['candidate_count'],
            'due_count' => (int)$row['due_count'],
            'sent_count' => (int)$row['sent_count'],
            'failed_count' => (int)$row['failed_count'],
            'blocked_count' => (int)$row['blocked_count'],
            'finished_at' => $row['finished_at'] ?? null,
            'message' => match ($status) {
                'test_scope_ready' => '云端测试群调度最近5分钟内运行，实际发送仍以每条回执为准。',
                'preview_only' => '云端最近仅运行预览调度，未请求企业微信发送。',
                'failed' => '云端最近一次调度执行失败，请查看调度运行与发送历史。',
                'blocked' => '云端最近一次调度被数据、身份或发送门禁阻断，未冒充成功。',
                default => '最近一次云端调度记录已过期，当前运行状态待验证。',
            },
        ];
    }

    /** @return array<string, mixed> */
    private function findDispatch(int $dispatchId): array
    {
        $row = Db::name('manual_notification_schedule_dispatches')
            ->where('id', $dispatchId)
            ->find();
        if (!is_array($row)) {
            throw new \RuntimeException('manual_notification_dispatch_not_found');
        }
        return $row;
    }

    /**
     * A data or identity gate can recover inside the same due window because a
     * blocked claim has no delivery attempt. Sending or unknown outcomes are
     * never reopened automatically.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function reopenBlockedDispatch(int $dispatchId, array $data): ?array
    {
        return Db::transaction(function () use ($dispatchId, $data): ?array {
            $row = Db::name('manual_notification_schedule_dispatches')
                ->where('id', $dispatchId)
                ->lock(true)
                ->find();
            if (!is_array($row)
                || (string)($row['status'] ?? '') !== 'blocked'
                || (int)($row['attempt_count'] ?? 0) !== 0
            ) {
                return null;
            }
            $allowed = [
                'business_date',
                'payload_fingerprint',
                'operating_target_record_id',
                'snapshot_revision_no',
                'render_contract_version',
                'payload_snapshot_json',
                'robot_id',
                'robot_name',
                'status',
                'result_code',
                'result_message',
                'claimed_at',
                'update_time',
            ];
            $update = array_intersect_key($data, array_fill_keys($allowed, true));
            $update['status'] = 'claimed';
            $update['result_code'] = 'dispatch_claimed_after_gate_recovery';
            $update['result_message'] = null;
            Db::name('manual_notification_schedule_dispatches')
                ->where('id', $dispatchId)
                ->where('status', 'blocked')
                ->where('attempt_count', 0)
                ->update($update);
            $reopened = Db::name('manual_notification_schedule_dispatches')
                ->where('id', $dispatchId)
                ->find();
            return is_array($reopened) && (string)$reopened['status'] === 'claimed'
                ? $reopened
                : null;
        });
    }

    private function isUniqueConstraintViolation(\Throwable $exception): bool
    {
        $code = (string)$exception->getCode();
        if (in_array($code, ['1062', '23505'], true)) {
            return true;
        }
        $message = strtolower($exception->getMessage());
        return str_contains($message, 'duplicate entry')
            || str_contains($message, 'unique constraint failed')
            || str_contains($message, 'duplicate key value');
    }

    /**
     * @param array<string, mixed> $delivery
     * @return array{status:string,result_code:string,result_message:?string,response_reference:?string}
     */
    private function deliveryOutcome(array $delivery, ?\Throwable $exception): array
    {
        if ($exception !== null) {
            return [
                'status' => 'outcome_unknown',
                'result_code' => 'sender_exception_outcome_unknown',
                'result_message' => $this->safeText($exception->getMessage(), 255),
                'response_reference' => null,
            ];
        }
        $deliveryStatus = strtolower(trim((string)($delivery['delivery_status'] ?? '')));
        $sent = $deliveryStatus === 'sent' || ($delivery['success'] ?? false) === true;
        if ($sent) {
            return [
                'status' => 'sent',
                'result_code' => 'wecom_business_success',
                'result_message' => '企业微信返回业务成功。',
                'response_reference' => $this->safeText(
                    (string)($delivery['response_reference'] ?? 'wecom:errcode=0'),
                    120
                ),
            ];
        }

        $ambiguous = $deliveryStatus === 'partial';
        $messages = [];
        foreach ((array)($delivery['failures'] ?? []) as $failure) {
            if (!is_array($failure)) {
                continue;
            }
            $ambiguous = $ambiguous || (($failure['ambiguous'] ?? false) === true);
            $reason = trim((string)($failure['reason'] ?? ''));
            if ($reason !== '') {
                $messages[] = $reason;
            }
        }
        $message = $messages !== []
            ? implode('；', array_slice($messages, 0, 3))
            : (string)($delivery['error'] ?? $deliveryStatus ?: '企业微信发送失败');
        return [
            'status' => $ambiguous ? 'outcome_unknown' : 'failed',
            'result_code' => $ambiguous
                ? 'wecom_delivery_outcome_unknown'
                : 'wecom_delivery_failed',
            'result_message' => $this->safeText($message, 255),
            'response_reference' => $this->safeText(
                (string)($delivery['response_reference'] ?? ''),
                120
            ) ?: null,
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function present(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'notification_id' => (int)$row['notification_id'],
            'tenant_id' => (int)$row['tenant_id'],
            'hotel_id' => (int)$row['hotel_id'],
            'dispatch_window' => (string)$row['dispatch_window'],
            'delivery_mode' => (string)$row['delivery_mode'],
            'trigger_type' => (string)$row['trigger_type'],
            'request_kind' => (string)($row['request_kind'] ?? 'scheduled'),
            'business_date' => $row['business_date'] ?? null,
            'payload_fingerprint' => $row['payload_fingerprint'] ?? null,
            'operating_target_record_id' => $this->positiveOrNull(
                $row['operating_target_record_id'] ?? null
            ),
            'snapshot_revision_no' => $this->positiveOrNull($row['snapshot_revision_no'] ?? null),
            'render_contract_version' => $row['render_contract_version'] ?? null,
            'robot_id' => (int)$row['robot_id'],
            'robot_name' => (string)$row['robot_name'],
            'status' => (string)$row['status'],
            'result_code' => (string)$row['result_code'],
            'result_message' => $row['result_message'] ?? null,
            'attempt_count' => (int)($row['attempt_count'] ?? 0),
            'max_attempts' => (int)($row['max_attempts'] ?? 3),
            'next_retry_at' => $row['next_retry_at'] ?? null,
            'last_attempt_at' => $row['last_attempt_at'] ?? null,
            'response_reference' => $row['response_reference'] ?? null,
            'claimed_at' => (string)$row['claimed_at'],
            'dispatched_at' => $row['dispatched_at'] ?? null,
            'created_at' => (string)$row['create_time'],
            'updated_at' => (string)$row['update_time'],
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function presentAttempt(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'attempt_no' => (int)$row['attempt_no'],
            'request_kind' => (string)$row['request_kind'],
            'status' => (string)$row['status'],
            'result_code' => (string)$row['result_code'],
            'result_message' => $row['result_message'] ?? null,
            'payload_fingerprint' => $row['payload_fingerprint'] ?? null,
            'response_reference' => $row['response_reference'] ?? null,
            'attempted_at' => (string)$row['attempted_at'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function decodePayload(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function assertTables(): void
    {
        foreach ([
            'manual_notification_schedule_dispatches',
            'manual_notification_dispatch_attempts',
        ] as $table) {
            if (!$this->tableExists($table)) {
                throw new \RuntimeException($table . '_table_missing');
            }
        }
    }

    private function tableExists(string $table): bool
    {
        if (preg_match('/^[a-z0-9_]+$/', $table) !== 1) {
            return false;
        }
        try {
            Db::query('SELECT 1 FROM `' . $table . '` WHERE 1 = 0');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function positiveOrNull(mixed $value): ?int
    {
        $number = (int)$value;
        return $number > 0 ? $number : null;
    }

    private function dateOrNull(string $value): ?string
    {
        $value = trim($value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    private function token(string $value, int $limit, string $errorCode): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > $limit || preg_match('/^[A-Za-z0-9:_-]+$/', $value) !== 1) {
            throw new \InvalidArgumentException($errorCode);
        }
        return $value;
    }

    private function dispatchWindow(string $value): string
    {
        $value = trim($value);
        if ($value === ''
            || mb_strlen($value, 'UTF-8') > 32
            || preg_match('/^[A-Za-z0-9:_ -]+$/', $value) !== 1
        ) {
            throw new \InvalidArgumentException('manual_notification_dispatch_window_invalid');
        }
        return $value;
    }

    private function safeText(string $value, int $limit): string
    {
        $value = preg_replace(
            '/(key|token|secret|cookie|password|authorization|webhook)\s*[=:]\s*[^\s,;]+/iu',
            '$1=<redacted>',
            trim($value)
        ) ?? '';
        return mb_substr($value, 0, $limit, 'UTF-8');
    }

    /** @param array<string, mixed> $value */
    private function json(array $value): string
    {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        if (!is_string($json)) {
            throw new \RuntimeException('manual_notification_dispatch_json_failed');
        }
        return $json;
    }
}
