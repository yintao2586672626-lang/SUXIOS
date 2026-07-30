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
    public const SENDING_LEASE_SECONDS = 180;
    public const PREPARATION_LEASE_SECONDS = 180;
    public const PREPARATION_RETRY_SECONDS = 60;

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
        ?string $resultMessage = null,
        ?int $scheduleRunId = null
    ): array {
        $this->assertTables();
        if ($notificationId <= 0 || $tenantId <= 0 || $hotelId <= 0) {
            throw new \InvalidArgumentException('manual_notification_dispatch_scope_invalid');
        }
        if ($robotId <= 0 && $initialStatus !== 'blocked') {
            throw new \InvalidArgumentException('manual_notification_dispatch_robot_invalid');
        }
        $dispatchWindow = $this->dispatchWindow($dispatchWindow);
        $deliveryMode = $this->token($deliveryMode, 16, 'manual_notification_delivery_mode_invalid');
        $requestKind = $this->token($requestKind, 32, 'manual_notification_request_kind_invalid');
        $timestamp = $now->format('Y-m-d H:i:s');
        $payload = is_array($candidate['payload'] ?? null) ? $candidate['payload'] : null;
        $payloadFingerprint = $payload === null
            ? trim((string)($candidate['payload_fingerprint'] ?? $candidate['preview_fingerprint'] ?? ''))
            : hash('sha256', $this->json($payload));
        if ($payload === null) {
            $payloadFingerprint = preg_match('/^[a-f0-9]{64}$/', $payloadFingerprint) === 1
                ? $payloadFingerprint
                : null;
        }
        $sourceSnapshotRefs = $this->strictSourceSnapshotRefs(
            $candidate['source_snapshot_refs'] ?? []
        );
        $this->assertSourceSnapshotSchema($sourceSnapshotRefs);
        $this->assertRequiredSourceSnapshots(
            $candidate,
            $sourceSnapshotRefs,
            $payload !== null && $initialStatus === 'claimed'
        );
        $sourceSnapshotFingerprint = $sourceSnapshotRefs === []
            ? null
            : hash('sha256', $this->json($sourceSnapshotRefs));
        $testedPlanFingerprint = $this->testedPlanFingerprint(
            $candidate['tested_plan_fingerprint'] ?? null
        );

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
            'render_contract_version' => $this->renderContractVersion(
                $candidate['render_contract_version'] ?? null
            ),
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
        if ($this->tableHasColumn(
            'manual_notification_schedule_dispatches',
            'schedule_run_id'
        )) {
            $data['schedule_run_id'] = $this->positiveOrNull($scheduleRunId);
        }
        if ($this->tableHasColumn(
            'manual_notification_schedule_dispatches',
            'source_snapshot_refs_json'
        )) {
            $data['source_snapshot_refs_json'] = $sourceSnapshotRefs === []
                ? null
                : $this->json($sourceSnapshotRefs);
        }
        if ($this->tableHasColumn(
            'manual_notification_schedule_dispatches',
            'source_snapshot_fingerprint'
        )) {
            $data['source_snapshot_fingerprint'] = $sourceSnapshotFingerprint;
        }
        if ($testedPlanFingerprint !== null) {
            if (!$this->tableHasColumn(
                'manual_notification_schedule_dispatches',
                'tested_plan_fingerprint'
            )) {
                throw new \RuntimeException(
                    'manual_notification_dispatch_tested_plan_schema_missing'
                );
            }
            $data['tested_plan_fingerprint'] =
                $testedPlanFingerprint;
        } elseif ($this->tableHasColumn(
            'manual_notification_schedule_dispatches',
            'tested_plan_fingerprint'
        )) {
            $data['tested_plan_fingerprint'] = null;
        }

        try {
            $dispatchId = (int)Db::name('manual_notification_schedule_dispatches')
                ->insertGetId($data);
            if ($dispatchId <= 0) {
                throw new \RuntimeException('manual_notification_dispatch_claim_failed');
            }
            $row = $this->findDispatch($dispatchId);
            return ['claimed' => true, 'dispatch' => $this->present($row)];
        } catch (\Throwable $exception) {
            $existing = Db::name('manual_notification_schedule_dispatches')
                ->where('notification_id', $notificationId)
                ->where('dispatch_window', $dispatchWindow)
                ->where('delivery_mode', $deliveryMode)
                ->find();
            if (is_array($existing)) {
                if ($this->reclaimExpiredPreparationDispatch(
                    (int)$existing['id'],
                    $now,
                    $data
                )) {
                    $reclaimed = $this->findDispatch((int)$existing['id']);
                    return [
                        'claimed' => true,
                        'dispatch' => $this->present($reclaimed),
                    ];
                }
                $this->recoverExpiredSendingDispatch((int)$existing['id'], $now);
                $existing = $this->findDispatch((int)$existing['id']);
                return ['claimed' => false, 'dispatch' => $this->present($existing)];
            }
            throw $exception;
        }
    }

    /**
     * Replace the empty reservation with the exact saved candidate before any
     * sender call. The claimed-at lease token prevents an expired worker from
     * attaching after another worker has reclaimed the window.
     *
     * @param array<string, mixed> $candidate
     * @return array{allowed:bool,reason_code:string,dispatch:array<string,mixed>}
     */
    public function attachCandidateToClaim(
        int $dispatchId,
        string $expectedClaimedAt,
        array $candidate,
        DateTimeImmutable $now,
        string $status = 'claimed',
        string $resultCode = 'dispatch_candidate_attached',
        ?string $resultMessage = null
    ): array {
        $this->assertTables();
        $status = $this->token(
            $status,
            24,
            'manual_notification_dispatch_status_invalid'
        );
        if (!in_array(
            $status,
            ['claimed', 'preparation_failed', 'blocked'],
            true
        )) {
            throw new \InvalidArgumentException(
                'manual_notification_dispatch_status_invalid'
            );
        }
        $expectedClaimedAt = trim($expectedClaimedAt);
        if ($dispatchId <= 0 || $expectedClaimedAt === '') {
            throw new \InvalidArgumentException(
                'manual_notification_dispatch_claim_lease_invalid'
            );
        }

        return Db::transaction(function () use (
            $dispatchId,
            $expectedClaimedAt,
            $candidate,
            $now,
            $status,
            $resultCode,
            $resultMessage
        ): array {
            $row = Db::name('manual_notification_schedule_dispatches')
                ->where('id', $dispatchId)
                ->lock(true)
                ->find();
            if (!is_array($row)) {
                throw new \RuntimeException(
                    'manual_notification_dispatch_not_found'
                );
            }
            $currentClaimedAt = trim((string)($row['claimed_at'] ?? ''));
            if (strtolower(trim((string)($row['status'] ?? ''))) !== 'claimed'
                || (int)($row['attempt_count'] ?? 0) !== 0
                || !hash_equals($currentClaimedAt, $expectedClaimedAt)
            ) {
                return [
                    'allowed' => false,
                    'reason_code' => 'dispatch_claim_lease_lost',
                    'dispatch' => $this->present($row),
                ];
            }

            $payload = is_array($candidate['payload'] ?? null)
                ? $candidate['payload']
                : null;
            if ($status === 'claimed' && $payload === null) {
                return [
                    'allowed' => false,
                    'reason_code' => 'dispatch_candidate_payload_missing',
                    'dispatch' => $this->present($row),
                ];
            }
            $payloadFingerprint = $payload === null
                ? trim((string)(
                    $candidate['payload_fingerprint']
                    ?? $candidate['preview_fingerprint']
                    ?? ''
                ))
                : hash('sha256', $this->json($payload));
            if ($payload === null
                && preg_match('/^[a-f0-9]{64}$/D', $payloadFingerprint) !== 1
            ) {
                $payloadFingerprint = null;
            }
            $sourceSnapshotRefs = $this->strictSourceSnapshotRefs(
                $candidate['source_snapshot_refs'] ?? []
            );
            $this->assertSourceSnapshotSchema($sourceSnapshotRefs);
            $this->assertRequiredSourceSnapshots(
                $candidate,
                $sourceSnapshotRefs,
                $payload !== null && $status === 'claimed'
            );
            $sourceSnapshotFingerprint = $sourceSnapshotRefs === []
                ? null
                : hash('sha256', $this->json($sourceSnapshotRefs));
            $testedPlanFingerprint = $this->testedPlanFingerprint(
                $candidate['tested_plan_fingerprint'] ?? null
            );
            $timestamp = $now->format('Y-m-d H:i:s');
            $update = [
                'business_date' => $this->dateOrNull(
                    (string)($candidate['business_date']
                        ?? $row['business_date']
                        ?? '')
                ),
                'payload_fingerprint' => $payloadFingerprint,
                'operating_target_record_id' => $this->positiveOrNull(
                    $candidate['operating_target_record_id'] ?? null
                ),
                'snapshot_revision_no' => $this->positiveOrNull(
                    $candidate['snapshot_revision_no'] ?? null
                ),
                'render_contract_version' => $this->renderContractVersion(
                    $candidate['render_contract_version'] ?? null
                ),
                'payload_snapshot_json' => $payload === null
                    ? null
                    : $this->json($payload),
                'status' => $status,
                'result_code' => $this->safeText($resultCode, 64),
                'result_message' => $resultMessage === null
                    ? null
                    : $this->safeText($resultMessage, 255),
                'next_retry_at' => $status === 'preparation_failed'
                    ? $now->modify(
                        '+' . self::PREPARATION_RETRY_SECONDS . ' seconds'
                    )->format('Y-m-d H:i:s')
                    : null,
                'update_time' => $timestamp,
            ];
            if ($this->tableHasColumn(
                'manual_notification_schedule_dispatches',
                'source_snapshot_refs_json'
            )) {
                $update['source_snapshot_refs_json'] =
                    $sourceSnapshotRefs === []
                        ? null
                        : $this->json($sourceSnapshotRefs);
            }
            if ($this->tableHasColumn(
                'manual_notification_schedule_dispatches',
                'source_snapshot_fingerprint'
            )) {
                $update['source_snapshot_fingerprint'] =
                    $sourceSnapshotFingerprint;
            }
            if ($testedPlanFingerprint !== null) {
                if (!$this->tableHasColumn(
                    'manual_notification_schedule_dispatches',
                    'tested_plan_fingerprint'
                )) {
                    throw new \RuntimeException(
                        'manual_notification_dispatch_tested_plan_schema_missing'
                    );
                }
                $update['tested_plan_fingerprint'] =
                    $testedPlanFingerprint;
            } elseif ($this->tableHasColumn(
                'manual_notification_schedule_dispatches',
                'tested_plan_fingerprint'
            )) {
                $update['tested_plan_fingerprint'] = null;
            }
            Db::name('manual_notification_schedule_dispatches')
                ->where('id', $dispatchId)
                ->update($update);
            return [
                'allowed' => true,
                'reason_code' => (string)$update['result_code'],
                'dispatch' => $this->present($this->findDispatch($dispatchId)),
            ];
        });
    }

    /**
     * Read the exact idempotency slot before any source refresh is started.
     *
     * @return array<string, mixed>|null
     */
    public function existingDispatch(
        int $notificationId,
        string $dispatchWindow,
        string $deliveryMode
    ): ?array {
        $this->assertTables();
        if ($notificationId <= 0) {
            throw new \InvalidArgumentException('manual_notification_dispatch_scope_invalid');
        }
        $dispatchWindow = $this->dispatchWindow($dispatchWindow);
        $deliveryMode = $this->token(
            $deliveryMode,
            16,
            'manual_notification_delivery_mode_invalid'
        );
        $row = Db::name('manual_notification_schedule_dispatches')
            ->where('notification_id', $notificationId)
            ->where('dispatch_window', $dispatchWindow)
            ->where('delivery_mode', $deliveryMode)
            ->find();
        return is_array($row) ? $this->present($row) : null;
    }

    /**
     * Persist the external side-effect boundary before calling the sender.
     *
     * @return array{allowed: bool, reason_code: string, attempt_id?: int, attempt_no?: int}
     */
    public function beginAttempt(
        int $dispatchId,
        DateTimeImmutable $now,
        ?string $requestKindOverride = null,
        bool $allowOutcomeUnknownRetry = false
    ): array {
        $this->assertTables();
        if ($allowOutcomeUnknownRetry && $requestKindOverride !== 'explicit_retry') {
            throw new \InvalidArgumentException(
                'manual_notification_outcome_unknown_retry_context_invalid'
            );
        }
        return Db::transaction(function () use (
            $dispatchId,
            $now,
            $requestKindOverride,
            $allowOutcomeUnknownRetry
        ): array {
            $row = Db::name('manual_notification_schedule_dispatches')
                ->where('id', $dispatchId)
                ->lock(true)
                ->find();
            if (!is_array($row)) {
                throw new \RuntimeException('manual_notification_dispatch_not_found');
            }
            $status = strtolower(trim((string)($row['status'] ?? '')));
            if ($status === 'outcome_unknown' && !$allowOutcomeUnknownRetry) {
                return ['allowed' => false, 'reason_code' => 'dispatch_status_outcome_unknown'];
            }
            if (in_array(
                $status,
                ['sent', 'sending', 'blocked', 'preparation_failed'],
                true
            )) {
                return ['allowed' => false, 'reason_code' => 'dispatch_status_' . $status];
            }
            if (!in_array($status, ['claimed', 'failed', 'outcome_unknown'], true)) {
                return [
                    'allowed' => false,
                    'reason_code' => 'dispatch_status_invalid',
                ];
            }
            $payload = $this->decodePayload(
                $row['payload_snapshot_json'] ?? null
            );
            $payloadFingerprint = strtolower(trim((string)(
                $row['payload_fingerprint'] ?? ''
            )));
            if ($payload === null
                || preg_match(
                    '/^[a-f0-9]{64}$/D',
                    $payloadFingerprint
                ) !== 1
                || !hash_equals(
                    $payloadFingerprint,
                    hash('sha256', $this->json($payload))
                )
            ) {
                return [
                    'allowed' => false,
                    'reason_code' => $payload === null
                        ? 'dispatch_candidate_not_attached'
                        : 'dispatch_payload_integrity_failed',
                ];
            }
            $sourceIntegrity = $this->sourceSnapshotIntegrity($row);
            if (!in_array(
                $sourceIntegrity['status'],
                ['verified', 'not_applicable'],
                true
            )) {
                return [
                    'allowed' => false,
                    'reason_code' =>
                        'dispatch_source_snapshot_integrity_'
                        . $sourceIntegrity['status'],
                ];
            }
            $requestKind = $requestKindOverride === null
                ? (string)($row['request_kind'] ?? 'scheduled')
                : $this->token(
                    $requestKindOverride,
                    32,
                    'manual_notification_request_kind_invalid'
                );
            $attemptNo = (int)($row['attempt_count'] ?? 0) + 1;
            $maxAttempts = max(1, (int)($row['max_attempts'] ?? 3));
            if ($attemptNo > $maxAttempts) {
                return ['allowed' => false, 'reason_code' => 'dispatch_attempt_limit_reached'];
            }

            $timestamp = $now->format('Y-m-d H:i:s');
            $startCode = $status === 'outcome_unknown'
                ? 'explicit_retry_after_outcome_unknown_started'
                : 'delivery_attempt_started';
            Db::name('manual_notification_schedule_dispatches')
                ->where('id', $dispatchId)
                ->update([
                    'status' => 'sending',
                    'result_code' => $startCode,
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
                'request_kind' => $requestKind,
                'status' => 'sending',
                'result_code' => $startCode,
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
                'reason_code' => $startCode,
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
        Db::transaction(function () use ($dispatchId, $attemptId, $outcome, $timestamp, $now): void {
            $dispatch = Db::name('manual_notification_schedule_dispatches')
                ->where('id', $dispatchId)
                ->lock(true)
                ->find();
            if (!is_array($dispatch)) {
                throw new \RuntimeException('manual_notification_dispatch_not_found');
            }
            $attempt = Db::name('manual_notification_dispatch_attempts')
                ->where('id', $attemptId)
                ->where('dispatch_id', $dispatchId)
                ->lock(true)
                ->find();
            if (!is_array($attempt)) {
                throw new \RuntimeException('manual_notification_dispatch_attempt_not_found');
            }
            $isCurrentAttempt = strtolower(trim((string)($dispatch['status'] ?? ''))) === 'sending'
                && strtolower(trim((string)($attempt['status'] ?? ''))) === 'sending'
                && (int)($attempt['attempt_no'] ?? 0) === (int)($dispatch['attempt_count'] ?? 0);
            if (!$isCurrentAttempt) {
                return;
            }
            $attemptCount = max(0, (int)($dispatch['attempt_count'] ?? 0));
            $maxAttempts = max(1, (int)($dispatch['max_attempts'] ?? 3));
            $nextRetryAt = $outcome['status'] === 'failed' && $attemptCount < $maxAttempts
                ? $now->modify('+5 minutes')->format('Y-m-d H:i:s')
                : null;
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
                    'next_retry_at' => $nextRetryAt,
                    'dispatched_at' => $timestamp,
                    'update_time' => $timestamp,
                ]);
        });

        return $this->present($this->findDispatch($dispatchId));
    }

    /** @return array<string, mixed> */
    public function dispatchForRetry(
        int $tenantId,
        int $hotelId,
        int $dispatchId,
        DateTimeImmutable $now
    ): array {
        $this->assertTables();
        $row = Db::name('manual_notification_schedule_dispatches')
            ->where('id', $dispatchId)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->find();
        if (!is_array($row)) {
            throw new \RuntimeException('manual_notification_dispatch_not_found');
        }
        $this->recoverExpiredSendingDispatch($dispatchId, $now);
        $row = Db::name('manual_notification_schedule_dispatches')
            ->where('id', $dispatchId)
            ->where('tenant_id', $tenantId)
            ->where('hotel_id', $hotelId)
            ->find();
        if (!is_array($row)) {
            throw new \RuntimeException('manual_notification_dispatch_not_found');
        }
        $status = strtolower(trim((string)($row['status'] ?? '')));
        if (!in_array($status, ['failed', 'outcome_unknown'], true)) {
            throw new \InvalidArgumentException('manual_notification_retry_status_forbidden');
        }
        if ((int)($row['attempt_count'] ?? 0) >= max(1, (int)($row['max_attempts'] ?? 3))) {
            throw new \InvalidArgumentException('manual_notification_retry_limit_reached');
        }
        $payload = $this->decodePayload($row['payload_snapshot_json'] ?? null);
        if ($payload === null) {
            throw new \RuntimeException('manual_notification_retry_payload_missing');
        }
        $expectedFingerprint = trim((string)($row['payload_fingerprint'] ?? ''));
        $actualFingerprint = hash('sha256', $this->json($payload));
        if (preg_match('/^[a-f0-9]{64}$/', $expectedFingerprint) !== 1
            || !hash_equals($expectedFingerprint, $actualFingerprint)
        ) {
            throw new \RuntimeException('manual_notification_retry_payload_integrity_failed');
        }
        $sourceIntegrity = $this->sourceSnapshotIntegrity($row);
        if (!in_array(
            $sourceIntegrity['status'],
            ['verified', 'not_applicable'],
            true
        )) {
            throw new \RuntimeException(
                'manual_notification_retry_source_snapshot_integrity_failed'
            );
        }
        return [
            'dispatch' => $this->present($row),
            'payload' => $payload,
            'robot_id' => (int)$row['robot_id'],
            'robot_name' => (string)$row['robot_name'],
            'notification_id' => (int)$row['notification_id'],
            'delivery_mode' => (string)$row['delivery_mode'],
            'tenant_id' => (int)$row['tenant_id'],
            'hotel_id' => (int)$row['hotel_id'],
            'previous_status' => $status,
        ];
    }

    /** @return array{status:string,refs:array<string,array<string,int|string>>} */
    public function sourceSnapshotIntegrityStatus(int $dispatchId): array
    {
        $this->assertTables();
        if ($dispatchId <= 0) {
            throw new \InvalidArgumentException(
                'manual_notification_dispatch_scope_invalid'
            );
        }
        $row = $this->findDispatch($dispatchId);
        return $this->sourceSnapshotIntegrity($row);
    }

    /**
     * Convert expired in-flight attempts to an explicit ambiguous outcome.
     *
     * This reconciliation never calls the sender and never creates a new
     * attempt. A retry remains an operator-confirmed action.
     */
    public function recoverExpiredSending(
        DateTimeImmutable $now,
        string $deliveryMode = '',
        int $hotelId = 0,
        int $limit = 500
    ): int {
        $this->assertTables();
        $deliveryMode = strtolower(trim($deliveryMode));
        if ($deliveryMode !== '') {
            $deliveryMode = $this->token(
                $deliveryMode,
                16,
                'manual_notification_delivery_mode_invalid'
            );
        }
        $hotelId = max(0, $hotelId);
        $limit = max(1, min(500, $limit));
        $query = Db::name('manual_notification_schedule_dispatches')
            ->where('status', 'sending')
            ->order('id', 'asc');
        if ($deliveryMode !== '') {
            $query->where('delivery_mode', $deliveryMode);
        }
        if ($hotelId > 0) {
            $query->where('hotel_id', $hotelId);
        }
        $rows = $query->limit($limit)->select()->toArray();
        $recovered = 0;
        foreach ($rows as $row) {
            if ($this->recoverExpiredSendingDispatch((int)($row['id'] ?? 0), $now)) {
                $recovered++;
            }
        }
        return $recovered;
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
    public function latestScheduleRun(
        int $tenantId = 0,
        int $hotelId = 0,
        int $robotId = 0,
        string $mode = ''
    ): array
    {
        $mode = strtolower(trim($mode));
        if ($mode !== '' && !in_array($mode, ['test', 'formal'], true)) {
            throw new \InvalidArgumentException(
                'manual_notification_schedule_mode_invalid'
            );
        }
        if (!$this->tableExists('manual_notification_schedule_runs')) {
            return [
                'status' => 'not_deployed',
                'message' => '尚未取得云端调度运行记录。',
            ];
        }
        $query = Db::name('manual_notification_schedule_runs');
        if ($mode !== '') {
            $query->where('runner_mode', $mode);
        }
        $dispatchLinkedScope = null;
        $planObservation = null;
        if ($hotelId > 0) {
            $explicitScope = Db::name('manual_notification_schedule_runs')
                ->where('scope_hotel_id', $hotelId);
            if ($mode !== '') {
                $explicitScope->where('runner_mode', $mode);
            }
            if ($tenantId > 0 && $this->tableHasColumn(
                'manual_notification_schedule_runs',
                'scope_tenant_id'
            )) {
                $explicitScope->where('scope_tenant_id', $tenantId);
            }
            if ($robotId > 0 && $this->tableHasColumn(
                'manual_notification_schedule_runs',
                'scope_robot_id'
            )) {
                $explicitScope->where('scope_robot_id', $robotId);
            }
            $explicitRunId = (int)($explicitScope->order('id', 'desc')->value('id') ?? 0);

            $dispatchRunId = 0;
            if ($this->tableExists('manual_notification_schedule_dispatches')
                && $this->tableHasColumn(
                    'manual_notification_schedule_dispatches',
                    'schedule_run_id'
                )
            ) {
                $dispatchScope = Db::name('manual_notification_schedule_dispatches')
                    ->where('hotel_id', $hotelId)
                    ->where('schedule_run_id', '>', 0);
                if ($mode !== '') {
                    $dispatchScope->where('delivery_mode', $mode);
                }
                if ($tenantId > 0) {
                    $dispatchScope->where('tenant_id', $tenantId);
                }
                if ($robotId > 0) {
                    $dispatchScope->where('robot_id', $robotId);
                }
                $dispatchLinkedScope = $dispatchScope
                    ->order('schedule_run_id', 'desc')
                    ->order('id', 'desc')
                    ->field('schedule_run_id,tenant_id,hotel_id,robot_id')
                    ->find();
                $dispatchRunId = is_array($dispatchLinkedScope)
                    ? (int)($dispatchLinkedScope['schedule_run_id'] ?? 0)
                    : 0;
            }
            $observationRunId = 0;
            if ($this->tableExists('manual_notification_schedule_run_scopes')) {
                $observationScope = Db::name('manual_notification_schedule_run_scopes')
                    ->where('hotel_id', $hotelId);
                if ($mode !== '') {
                    $observationScope->where('runner_mode', $mode);
                }
                if ($tenantId > 0) {
                    $observationScope->where('tenant_id', $tenantId);
                }
                if ($robotId > 0) {
                    $observationScope->where('robot_id', $robotId);
                }
                $planObservation = $observationScope
                    ->order('schedule_run_id', 'desc')
                    ->order('id', 'desc')
                    ->find();
                $observationRunId = is_array($planObservation)
                    ? (int)($planObservation['schedule_run_id'] ?? 0)
                    : 0;
            }
            $latestScopedRunId = max($explicitRunId, $dispatchRunId, $observationRunId);
            if ($latestScopedRunId > 0) {
                $query->where('id', $latestScopedRunId);
            } else {
                $query->where('id', -1);
            }
        }
        $row = $query->order('id', 'desc')->find();
        if (!is_array($row)) {
            return [
                'status' => 'not_run',
                'message' => $hotelId > 0
                    ? '尚未取得当前门店作用域的云端调度运行记录。'
                    : '调度表已安装，但尚无运行记录。',
            ];
        }
        $usesDispatchLink = $hotelId > 0
            && is_array($dispatchLinkedScope)
            && (int)($dispatchLinkedScope['schedule_run_id'] ?? 0) === (int)$row['id']
            && (int)($row['scope_hotel_id'] ?? 0) !== $hotelId;
        $usesPlanObservation = !$usesDispatchLink
            && $hotelId > 0
            && is_array($planObservation)
            && (int)($planObservation['schedule_run_id'] ?? 0) === (int)$row['id']
            && (int)($row['scope_hotel_id'] ?? 0) !== $hotelId;
        $scopeEvidence = $usesPlanObservation ? $planObservation : $row;
        $observedAtText = (string)($scopeEvidence['observed_at'] ?? $row['observed_at']);
        $observedAt = strtotime($observedAtText);
        $ageSeconds = $observedAt === false ? null : max(0, time() - $observedAt);
        $dispatchRequested = (int)($scopeEvidence['dispatch_requested'] ?? $row['dispatch_requested']) === 1;
        $runnerMode = (string)($scopeEvidence['runner_mode'] ?? $row['runner_mode']);
        $runStatus = (string)($scopeEvidence['status'] ?? $row['status']);
        $candidateCount = (int)($scopeEvidence['candidate_count'] ?? $row['candidate_count']);
        $dueCount = (int)($scopeEvidence['due_count'] ?? $row['due_count']);
        $sentCount = (int)($scopeEvidence['sent_count'] ?? $row['sent_count']);
        $failedCount = (int)($scopeEvidence['failed_count'] ?? $row['failed_count']);
        $blockedCount = (int)($scopeEvidence['blocked_count'] ?? $row['blocked_count']);
        if ($usesDispatchLink) {
            $scopeQuery = Db::name('manual_notification_schedule_dispatches')
                ->where('schedule_run_id', (int)$row['id'])
                ->where('hotel_id', $hotelId);
            if ($tenantId > 0) {
                $scopeQuery->where('tenant_id', $tenantId);
            }
            if ($robotId > 0) {
                $scopeQuery->where('robot_id', $robotId);
            }
            $scopeDispatches = $scopeQuery->field('status,result_code')->select()->toArray();
            $candidateCount = count($scopeDispatches);
            $dueCount = $candidateCount;
            $sentCount = 0;
            $failedCount = 0;
            $blockedCount = 0;
            $inProgressCount = 0;
            foreach ($scopeDispatches as $scopeDispatch) {
                $dispatchStatus = strtolower(trim((string)($scopeDispatch['status'] ?? '')));
                if ($dispatchStatus === 'sent') {
                    $sentCount++;
                } elseif (in_array($dispatchStatus, ['blocked', 'binding_missing'], true)) {
                    $blockedCount++;
                } elseif (in_array($dispatchStatus, ['sending', 'claimed', 'queued', 'retry_pending'], true)) {
                    $inProgressCount++;
                } else {
                    $failedCount++;
                }
            }
            $runStatus = $failedCount > 0
                ? 'failed'
                : ($blockedCount > 0
                    ? 'blocked'
                    : ($candidateCount > 0 && $sentCount === $candidateCount
                        ? 'completed'
                        : ($inProgressCount > 0 ? 'running' : 'completed')));
        }
        $status = $runStatus === 'completed' && $ageSeconds !== null && $ageSeconds <= 300
            ? ($dispatchRequested
                ? ($runnerMode === 'formal' ? 'formal_scope_ready' : 'test_scope_ready')
                : 'preview_only')
            : ($runStatus === 'failed'
                ? 'failed'
                : ($runStatus === 'blocked' ? 'blocked' : 'stale'));
        return [
            'status' => $status,
            'run_status' => $runStatus,
            'run_id' => (int)$row['id'],
            'scope_source' => $usesDispatchLink
                ? 'dispatch_link'
                : ($usesPlanObservation ? 'plan_observation' : 'explicit_runner_scope'),
            'scope_tenant_id' => $this->positiveOrNull(
                $usesDispatchLink
                    ? ($dispatchLinkedScope['tenant_id'] ?? null)
                    : ($usesPlanObservation
                        ? ($planObservation['tenant_id'] ?? null)
                        : ($row['scope_tenant_id'] ?? null))
            ),
            'scope_hotel_id' => $this->positiveOrNull(
                $usesDispatchLink
                    ? ($dispatchLinkedScope['hotel_id'] ?? null)
                    : ($usesPlanObservation
                        ? ($planObservation['hotel_id'] ?? null)
                        : ($row['scope_hotel_id'] ?? null))
            ),
            'scope_robot_id' => $this->positiveOrNull(
                $usesDispatchLink
                    ? ($dispatchLinkedScope['robot_id'] ?? null)
                    : ($usesPlanObservation
                        ? ($planObservation['robot_id'] ?? null)
                        : ($row['scope_robot_id'] ?? null))
            ),
            'runner_mode' => $runnerMode,
            'dispatch_requested' => $dispatchRequested,
            'observed_at' => $observedAtText,
            'age_seconds' => $ageSeconds,
            'candidate_count' => $candidateCount,
            'due_count' => $dueCount,
            'sent_count' => $sentCount,
            'failed_count' => $failedCount,
            'blocked_count' => $blockedCount,
            'finished_at' => $row['finished_at'] ?? null,
            'message' => match ($status) {
                'test_scope_ready' => '云端测试群调度最近5分钟内运行，实际发送仍以每条回执为准。',
                'formal_scope_ready' => '云端正式群调度最近5分钟内运行，实际发送以每条企业微信回执为准。',
                'preview_only' => '云端最近仅运行预览调度，未请求企业微信发送。',
                'failed' => '云端最近一次调度执行失败，请查看调度运行与发送历史。',
                'blocked' => '云端最近一次调度被数据或机器人作用域门禁阻断。',
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

    private function recoverExpiredSendingDispatch(
        int $dispatchId,
        DateTimeImmutable $now
    ): bool {
        if ($dispatchId <= 0) {
            return false;
        }
        return Db::transaction(function () use ($dispatchId, $now): bool {
            $dispatch = Db::name('manual_notification_schedule_dispatches')
                ->where('id', $dispatchId)
                ->lock(true)
                ->find();
            if (!is_array($dispatch)
                || strtolower(trim((string)($dispatch['status'] ?? ''))) !== 'sending'
            ) {
                return false;
            }
            $leaseStartedAt = trim((string)(
                $dispatch['last_attempt_at']
                ?? $dispatch['update_time']
                ?? $dispatch['claimed_at']
                ?? ''
            ));
            if ($leaseStartedAt === '') {
                return false;
            }
            try {
                $leaseStarted = new DateTimeImmutable($leaseStartedAt, $now->getTimezone());
            } catch (\Throwable) {
                return false;
            }
            if (($now->getTimestamp() - $leaseStarted->getTimestamp()) < self::SENDING_LEASE_SECONDS) {
                return false;
            }

            $attemptNo = max(0, (int)($dispatch['attempt_count'] ?? 0));
            $timestamp = $now->format('Y-m-d H:i:s');
            $resultCode = 'delivery_attempt_lease_expired_outcome_unknown';
            $resultMessage = $this->safeText(
                'Delivery receipt was not recorded before the sending lease expired. '
                . 'Automatic retry is blocked; an operator must confirm any retry.',
                255
            );
            if ($attemptNo > 0) {
                Db::name('manual_notification_dispatch_attempts')
                    ->where('dispatch_id', $dispatchId)
                    ->where('attempt_no', $attemptNo)
                    ->where('status', 'sending')
                    ->update([
                        'status' => 'outcome_unknown',
                        'result_code' => $resultCode,
                        'result_message' => $resultMessage,
                        'response_reference' => null,
                    ]);
            }
            $updated = Db::name('manual_notification_schedule_dispatches')
                ->where('id', $dispatchId)
                ->where('status', 'sending')
                ->update([
                    'status' => 'outcome_unknown',
                    'result_code' => $resultCode,
                    'result_message' => $resultMessage,
                    'response_reference' => null,
                    'next_retry_at' => null,
                    'update_time' => $timestamp,
                ]);
            return $updated > 0;
        });
    }

    /**
     * Recover only reservations that provably have not crossed the sender
     * boundary. Sending attempts remain outcome-unknown and are never reclaimed
     * automatically.
     *
     * @param array<string, mixed> $freshReservation
     */
    private function reclaimExpiredPreparationDispatch(
        int $dispatchId,
        DateTimeImmutable $now,
        array $freshReservation
    ): bool {
        if ($dispatchId <= 0) {
            return false;
        }
        return Db::transaction(function () use (
            $dispatchId,
            $now,
            $freshReservation
        ): bool {
            $dispatch = Db::name('manual_notification_schedule_dispatches')
                ->where('id', $dispatchId)
                ->lock(true)
                ->find();
            if (!is_array($dispatch)
                || (int)($dispatch['attempt_count'] ?? 0) !== 0
            ) {
                return false;
            }
            $status = strtolower(trim((string)($dispatch['status'] ?? '')));
            $retryDue = false;
            if ($status === 'claimed') {
                $leaseStartedAt = trim((string)(
                    $dispatch['claimed_at']
                    ?? $dispatch['update_time']
                    ?? ''
                ));
                try {
                    $leaseStarted = new DateTimeImmutable(
                        $leaseStartedAt,
                        $now->getTimezone()
                    );
                    $retryDue = ($now->getTimestamp()
                        - $leaseStarted->getTimestamp())
                        >= self::PREPARATION_LEASE_SECONDS;
                } catch (\Throwable) {
                    $retryDue = false;
                }
            } elseif ($status === 'preparation_failed') {
                $nextRetryAt = trim((string)(
                    $dispatch['next_retry_at'] ?? ''
                ));
                try {
                    $retryDue = $nextRetryAt !== ''
                        && new DateTimeImmutable(
                            $nextRetryAt,
                            $now->getTimezone()
                        ) <= $now;
                } catch (\Throwable) {
                    $retryDue = false;
                }
            }
            if (!$retryDue) {
                return false;
            }

            unset(
                $freshReservation['create_time'],
                $freshReservation['dispatched_at']
            );
            $freshReservation['status'] = 'claimed';
            $freshReservation['result_code'] =
                'dispatch_preparation_reclaimed';
            $freshReservation['result_message'] = null;
            $freshReservation['next_retry_at'] = null;
            $freshReservation['last_attempt_at'] = null;
            $freshReservation['response_reference'] = null;
            $freshReservation['dispatched_at'] = null;
            $freshReservation['claimed_at'] = $now->format(
                'Y-m-d H:i:s'
            );
            $freshReservation['update_time'] = $freshReservation['claimed_at'];
            $updated = Db::name('manual_notification_schedule_dispatches')
                ->where('id', $dispatchId)
                ->update($freshReservation);
            return $updated > 0;
        });
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
        if ($deliveryStatus === 'sent') {
            $failures = array_values(array_filter(
                (array)($delivery['failures'] ?? []),
                static fn(mixed $failure): bool => is_array($failure)
                    ? $failure !== []
                    : trim((string)$failure) !== ''
            ));
            $failedCount = max(0, (int)($delivery['failed_count'] ?? 0));
            $robotCount = max(0, (int)(
                $delivery['robot_count']
                ?? $delivery['target_count']
                ?? 0
            ));
            $sentCount = max(0, (int)(
                $delivery['sent_count']
                ?? $delivery['success_count']
                ?? 0
            ));
            $contradictory = ($delivery['success'] ?? null) === false
                || $failures !== []
                || $failedCount > 0
                || ($robotCount > 0 && $sentCount !== $robotCount);
            if ($contradictory) {
                return [
                    'status' => 'outcome_unknown',
                    'result_code' =>
                        'wecom_delivery_success_contradictory',
                    'result_message' => $this->safeText(
                        '发送器同时返回成功与失败信号，未将本次记录为已送达；需人工核对回执。',
                        255
                    ),
                    'response_reference' => $this->safeText(
                        (string)($delivery['response_reference'] ?? ''),
                        120
                    ) ?: null,
                ];
            }
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

        $ambiguous = $deliveryStatus === 'partial'
            || ($delivery['success'] ?? false) === true;
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
            : (string)($delivery['error'] ?? $delivery['reason'] ?? $deliveryStatus ?: '企业微信发送失败');
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
        $status = strtolower(trim((string)($row['status'] ?? '')));
        $sourceIntegrity = $this->sourceSnapshotIntegrity($row);
        $sourceIntegrityValid = in_array(
            $sourceIntegrity['status'],
            ['verified', 'not_applicable'],
            true
        );
        $retryable = in_array($status, ['failed', 'outcome_unknown'], true)
            && (int)($row['attempt_count'] ?? 0)
                < max(1, (int)($row['max_attempts'] ?? 3))
            && $sourceIntegrityValid;
        return [
            'id' => (int)$row['id'],
            'schedule_run_id' => $this->positiveOrNull($row['schedule_run_id'] ?? null),
            'notification_id' => (int)$row['notification_id'],
            'tenant_id' => (int)$row['tenant_id'],
            'hotel_id' => (int)$row['hotel_id'],
            'dispatch_window' => (string)$row['dispatch_window'],
            'delivery_mode' => (string)$row['delivery_mode'],
            'trigger_type' => (string)$row['trigger_type'],
            'request_kind' => (string)($row['request_kind'] ?? 'scheduled'),
            'business_date' => $row['business_date'] ?? null,
            'payload_fingerprint' => $row['payload_fingerprint'] ?? null,
            'tested_plan_fingerprint' =>
                $row['tested_plan_fingerprint'] ?? null,
            'source_snapshot_refs' => $sourceIntegrity['refs'],
            'source_snapshot_fingerprint' => $row['source_snapshot_fingerprint'] ?? null,
            'source_snapshot_integrity_status' =>
                $sourceIntegrity['status'],
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
            'retryable' => $retryable,
            'retry_requires_confirmation' => $retryable,
            'retry_may_duplicate' => $status === 'outcome_unknown',
            'automatic_retry_allowed' => false,
            'last_attempt_at' => $row['last_attempt_at'] ?? null,
            'response_reference' => $row['response_reference'] ?? null,
            'claimed_at' => (string)$row['claimed_at'],
            'dispatched_at' => $row['dispatched_at'] ?? null,
            'created_at' => (string)$row['create_time'],
            'updated_at' => (string)$row['update_time'],
        ];
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        try {
            return in_array($column, Db::getTableInfo($table, 'fields'), true);
        } catch (\Throwable) {
            return false;
        }
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

    /** @return array<string, array<string, int|string>> */
    private function strictSourceSnapshotRefs(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException(
                'manual_notification_source_snapshot_refs_invalid'
            );
        }
        $normalized = $this->sourceSnapshotRefs($value);
        if (count($normalized) !== count($value)) {
            throw new \InvalidArgumentException(
                'manual_notification_source_snapshot_refs_invalid'
            );
        }
        return $normalized;
    }

    /** @param array<string, array<string, int|string>> $sourceSnapshotRefs */
    private function assertSourceSnapshotSchema(array $sourceSnapshotRefs): void
    {
        if ($sourceSnapshotRefs === []) {
            return;
        }
        if (!$this->tableHasColumn(
            'manual_notification_schedule_dispatches',
            'source_snapshot_refs_json'
        ) || !$this->tableHasColumn(
            'manual_notification_schedule_dispatches',
            'source_snapshot_fingerprint'
        )) {
            throw new \RuntimeException(
                'manual_notification_dispatch_source_snapshot_schema_missing'
            );
        }
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, array<string, int|string>> $sourceSnapshotRefs
     */
    private function assertRequiredSourceSnapshots(
        array $candidate,
        array $sourceSnapshotRefs,
        bool $readyForAttempt
    ): void {
        if (!$readyForAttempt
            || trim((string)($candidate['render_contract_version'] ?? ''))
                !== OperatingDailyReportPayloadService::RENDER_CONTRACT_VERSION
        ) {
            return;
        }
        if ($sourceSnapshotRefs === []) {
            throw new \RuntimeException(
                'manual_notification_dispatch_source_snapshot_refs_required'
            );
        }
    }

    private function testedPlanFingerprint(mixed $value): ?string
    {
        $fingerprint = strtolower(trim((string)$value));
        if ($fingerprint === '') {
            return null;
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            throw new \InvalidArgumentException(
                'manual_notification_tested_plan_fingerprint_invalid'
            );
        }
        return $fingerprint;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{status:string,refs:array<string,array<string,int|string>>}
     */
    private function sourceSnapshotIntegrity(array $row): array
    {
        $required = trim((string)($row['render_contract_version'] ?? ''))
            === OperatingDailyReportPayloadService::RENDER_CONTRACT_VERSION;
        $hasRefsColumn = $this->tableHasColumn(
            'manual_notification_schedule_dispatches',
            'source_snapshot_refs_json'
        );
        $hasFingerprintColumn = $this->tableHasColumn(
            'manual_notification_schedule_dispatches',
            'source_snapshot_fingerprint'
        );
        if (!$hasRefsColumn || !$hasFingerprintColumn) {
            return [
                'status' => $required ? 'schema_missing' : 'not_applicable',
                'refs' => [],
            ];
        }

        $raw = $row['source_snapshot_refs_json'] ?? null;
        $fingerprint = strtolower(trim((string)(
            $row['source_snapshot_fingerprint'] ?? ''
        )));
        $hasRaw = is_array($raw)
            ? $raw !== []
            : is_string($raw) && trim($raw) !== '';
        if (!$hasRaw) {
            return [
                'status' => $required || $fingerprint !== ''
                    ? 'missing_refs'
                    : 'not_applicable',
                'refs' => [],
            ];
        }
        $decoded = $this->decodePayload($raw);
        if ($decoded === null) {
            return ['status' => 'invalid_json', 'refs' => []];
        }
        $refs = $this->sourceSnapshotRefs($decoded);
        if (count($refs) !== count($decoded)) {
            return ['status' => 'invalid_refs', 'refs' => $refs];
        }
        if ($refs === []) {
            return ['status' => 'missing_refs', 'refs' => []];
        }
        if ($fingerprint === '') {
            return ['status' => 'missing_fingerprint', 'refs' => $refs];
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            return ['status' => 'mismatch', 'refs' => $refs];
        }
        $actual = hash('sha256', $this->json($refs));
        return [
            'status' => hash_equals($fingerprint, $actual)
                ? 'verified'
                : 'mismatch',
            'refs' => $refs,
        ];
    }

    /**
     * Keep only identifiers needed to trace a sent message back to its saved,
     * exact-date source rows. Raw source payloads and credentials are excluded.
     *
     * @return array<string, array<string, int|string>>
     */
    private function sourceSnapshotRefs(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach (array_slice($value, 0, 12, true) as $key => $reference) {
            if (!is_array($reference)) {
                continue;
            }
            $name = strtolower(trim((string)$key));
            if (preg_match('/^[a-z0-9_]{1,40}$/D', $name) !== 1) {
                continue;
            }
            $recordId = (int)($reference['record_id'] ?? 0);
            if ($recordId <= 0) {
                continue;
            }
            $clean = ['record_id' => $recordId];
            foreach (['data_source_id', 'sync_task_id'] as $field) {
                $number = (int)($reference[$field] ?? 0);
                if ($number > 0) {
                    $clean[$field] = $number;
                }
            }
            foreach ([
                'source' => 32,
                'business_date' => 10,
                'source_scope' => 32,
                'capture_method' => 48,
                'data_type' => 40,
                'dimension' => 160,
                'source_trace_id' => 120,
                'provider_hotel_id' => 120,
                'provider_hotel_name' => 120,
                'bound_provider_hotel_id' => 120,
                'bound_provider_hotel_name' => 120,
            ] as $field => $limit) {
                $text = $this->safeText((string)($reference[$field] ?? ''), $limit);
                if ($text !== '') {
                    $clean[$field] = $text;
                }
            }
            $result[$name] = $clean;
        }
        ksort($result);
        return $result;
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

    private function renderContractVersion(mixed $value): string
    {
        $value = strtolower(trim((string)$value));
        return preg_match('/^[a-z0-9._-]{1,64}$/D', $value) === 1
            ? $value
            : self::RENDER_CONTRACT_VERSION;
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
