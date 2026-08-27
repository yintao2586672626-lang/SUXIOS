<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use think\facade\Db;

/**
 * Converts ready cloud authorizations into gateway work.  It deliberately
 * queues work only: a trusted browser gateway owns collection and reports a
 * compact receipt back before downstream reporting can be unlocked.
 */
final class CloudCollectionDispatchService
{
    public const YESTERDAY_FINAL = 'yesterday_final';
    public const TODAY_REALTIME = 'today_realtime';
    private const MAX_ATTEMPTS = 3;
    private const RETRY_BACKOFF_SECONDS = 60;
    /** @var list<string> */
    private const RETRYABLE_GAPS = ['missing_saved', 'missing_readback'];

    /** @return array<string,mixed> */
    public function preview(string $mode, ?string $targetDate = null): array
    {
        return $this->dispatch($mode, $targetDate, false);
    }

    /** @return array<string,mixed> */
    public function enqueue(string $mode, ?string $targetDate = null): array
    {
        return $this->dispatch($mode, $targetDate, true);
    }

    /** @return array<string,mixed> */
    public function dispatch(string $mode, ?string $targetDate, bool $persist): array
    {
        $mode = $this->mode($mode);
        $targetDate = $this->targetDate($mode, $targetDate);
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
        $windowKey = $mode === self::TODAY_REALTIME
            ? $targetDate . '-' . $now->format('H')
            : $targetDate . '-final';
        $rows = Db::name('cloud_browser_profiles')
            ->where('authorization_status', CloudBrowserProfileService::READY_TO_COLLECT)
            ->order('tenant_id', 'asc')->order('system_hotel_id', 'asc')->order('platform', 'asc')
            ->select()->toArray();

        $queued = [];
        $skipped = [];
        foreach ($rows as $profile) {
            $profileGap = $this->profileReadyGap($profile, $now);
            if ($profileGap !== null) {
                $skipped[] = $this->publicSkip($profile, $profileGap);
                continue;
            }
            $task = $this->taskShape($profile, $mode, $targetDate, $windowKey);
            if (!$persist) {
                $queued[] = $this->publicTask($task) + ['dispatch_status' => 'preview'];
                continue;
            }
            $queued[] = $this->persistTask($task);
        }

        return [
            'collection_mode' => $mode,
            'target_date' => $targetDate,
            'window_key' => $windowKey,
            'dispatch_status' => $persist ? 'enqueued' : 'preview',
            'task_count' => count($queued),
            'skipped_count' => count($skipped),
            'tasks' => $queued,
            'skipped' => $skipped,
        ];
    }

    /**
     * The browser gateway supplies only compact evidence flags.  A delivery
     * can become ready only after identity, exact target date, fields,
     * persistence and readback all pass; no old value or zero is substituted.
     *
     * @param array<string,mixed> $receipt
     * @return array<string,mixed>
     */
    public function recordReceipt(string $taskPublicId, array $receipt): array
    {
        return Db::transaction(function () use ($taskPublicId, $receipt): array {
            $task = Db::name('cloud_collection_tasks')
                ->where('task_public_id', trim($taskPublicId))->lock(true)->find();
            if (!is_array($task)) {
                throw new RuntimeException('cloud_collection_task_not_found');
            }
            if ((string)($task['task_status'] ?? '') === 'blocked' && $this->hasNewerAttempt($task)) {
                return $this->receiptResult(
                    $task,
                    $this->storedGaps($task),
                    false,
                    'superseded_attempt'
                );
            }
            $requiredFields = $this->taskFields($task);
            $collectedFields = $this->fieldNames($receipt['collected_fields'] ?? null);
            $readbackFields = $this->fieldNames($receipt['readback_fields'] ?? null);
            $missingCollectedFields = array_values(array_diff($requiredFields, $collectedFields));
            $missingReadbackFields = array_values(array_diff($requiredFields, $readbackFields));
            $savedCount = $this->positiveCount($receipt['saved_count'] ?? null);
            $readbackCount = $this->positiveCount($receipt['readback_count'] ?? null);
            $identity = [
                'profile_id' => trim((string)($receipt['profile_id'] ?? '')),
                'tenant_id' => $this->positiveCount($receipt['tenant_id'] ?? null),
                'hotel_id' => $this->positiveCount($receipt['hotel_id'] ?? null),
                'owner_user_id' => $this->positiveCount($receipt['owner_user_id'] ?? null),
                'platform' => strtolower(trim((string)($receipt['platform'] ?? ''))),
                'platform_hotel_id' => trim((string)($receipt['platform_hotel_id'] ?? '')),
            ];
            $checks = [
                'identity' => ($receipt['identity_verified'] ?? null) === true
                    && $identity['profile_id'] === (string)$task['profile_public_id']
                    && $identity['tenant_id'] === (int)$task['tenant_id']
                    && $identity['hotel_id'] === (int)$task['system_hotel_id']
                    && $identity['owner_user_id'] === (int)$task['owner_user_id']
                    && $identity['platform'] === (string)$task['platform']
                    && $identity['platform_hotel_id'] !== '',
                'target_date' => (string)($receipt['target_date'] ?? '') === (string)$task['target_date'],
                'fields' => ($receipt['required_fields_present'] ?? null) === true
                    && $missingCollectedFields === [],
                'saved' => ($receipt['saved'] ?? null) === true && $savedCount !== null,
                'readback' => ($receipt['readback_verified'] ?? null) === true
                    && $readbackCount !== null
                    && $savedCount !== null
                    && $readbackCount === $savedCount
                    && $missingReadbackFields === [],
            ];
            $gaps = [];
            foreach ($checks as $name => $passed) {
                if (!$passed) {
                    $gaps[] = 'missing_' . $name;
                }
            }
            $receiptPassed = $gaps === [];
            $businessEvidence = [
                'identity' => $identity,
                'target_date' => trim((string)($receipt['target_date'] ?? '')),
                'required_fields' => $requiredFields,
                'collected_fields' => $collectedFields,
                'missing_collected_fields' => $missingCollectedFields,
                'saved_count' => $savedCount,
                'readback_count' => $readbackCount,
                'readback_fields' => $readbackFields,
                'missing_readback_fields' => $missingReadbackFields,
                'checks' => $checks,
            ];
            $storedEvidence = json_decode((string)($task['receipt_evidence_json'] ?? ''), true);
            $storedDispatch = is_array($storedEvidence['dispatch'] ?? null)
                ? $storedEvidence['dispatch']
                : [];
            $attemptNo = max(1, (int)($storedDispatch['attempt_no'] ?? 1));
            $now = date('Y-m-d H:i:s');
            $retryAllowed = !$receiptPassed && $this->gapsAreRetryable($gaps);
            $terminalAt = trim((string)($storedDispatch['terminal_at'] ?? '')) ?: $now;
            $retryNotBefore = trim((string)($storedDispatch['retry_not_before'] ?? ''));
            if ($retryAllowed && $retryNotBefore === '') {
                $retryNotBefore = date('Y-m-d H:i:s', strtotime($terminalAt) + self::RETRY_BACKOFF_SECONDS);
            }
            $dispatchEvidence = [
                'attempt_no' => $attemptNo,
                'retry_of_task_id' => trim((string)($storedDispatch['retry_of_task_id'] ?? '')) ?: null,
                'max_attempts' => self::MAX_ATTEMPTS,
                'terminal_at' => $terminalAt,
                'retry_allowed' => $retryAllowed,
                'retry_not_before' => $retryAllowed ? $retryNotBefore : null,
            ];
            $evidence = ['dispatch' => $dispatchEvidence] + $businessEvidence;
            $fingerprint = hash('sha256', (string)json_encode(
                $evidence,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ));
            $legacyFingerprint = hash('sha256', (string)json_encode(
                $businessEvidence,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ));
            $storedFingerprint = trim((string)($task['receipt_fingerprint'] ?? ''));
            if ($storedFingerprint !== '' && (
                hash_equals($storedFingerprint, $fingerprint)
                || hash_equals($storedFingerprint, $legacyFingerprint)
            )) {
                return $this->receiptResult(
                    $task,
                    $this->storedGaps($task),
                    (string)$task['truth_gate_status'] === 'passed',
                    'reused'
                );
            }
            if ((string)$task['truth_gate_status'] === 'passed' && $storedFingerprint !== '') {
                $gaps = array_values(array_unique([...$gaps, 'receipt_conflict']));
                $dispatchEvidence['retry_allowed'] = false;
                $dispatchEvidence['retry_not_before'] = null;
                $evidence['dispatch'] = $dispatchEvidence;
                $fingerprint = hash('sha256', (string)json_encode(
                    $evidence,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ));
            }
            $passed = $gaps === [];
            $status = $passed ? 'truth_ready' : 'blocked';
            $gate = $passed ? 'passed' : 'blocked_by_data_gap';
            Db::name('cloud_collection_tasks')->where('id', (int)$task['id'])->update([
                'task_status' => $status,
                'truth_gate_status' => $gate,
                'gap_codes_json' => json_encode($gaps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'receipt_evidence_json' => json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'receipt_fingerprint' => $fingerprint,
                'formal_message_allowed' => $passed ? 1 : 0,
                'started_at' => $task['started_at'] ?: $now,
                'finished_at' => $now,
                'update_time' => $now,
            ]);
            $task['truth_gate_status'] = $gate;
            return $this->receiptResult($task, $gaps, $passed, $storedFingerprint === '' ? 'recorded' : 'updated');
        });
    }

    private function mode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, [self::YESTERDAY_FINAL, self::TODAY_REALTIME], true)) {
            throw new RuntimeException('cloud_collection_mode_invalid');
        }
        return $mode;
    }

    private function targetDate(string $mode, ?string $targetDate): string
    {
        $value = trim((string)$targetDate);
        if ($value === '') {
            $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
            $value = $mode === self::YESTERDAY_FINAL
                ? $now->modify('-1 day')->format('Y-m-d')
                : $now->format('Y-m-d');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new RuntimeException('cloud_collection_target_date_invalid');
        }
        return $value;
    }

    /** @param array<string,mixed> $profile @return array<string,mixed> */
    private function taskShape(array $profile, string $mode, string $targetDate, string $windowKey): array
    {
        $platform = strtolower((string)$profile['platform']);
        $priority = $this->fieldPriority($platform, $mode);
        $idempotency = hash('sha256', implode('|', [
            (string)$profile['profile_public_id'], $mode, $targetDate, $windowKey,
        ]));
        return [
            'profile_id' => (int)$profile['id'],
            'profile_public_id' => (string)$profile['profile_public_id'],
            'tenant_id' => (int)$profile['tenant_id'],
            'system_hotel_id' => (int)$profile['system_hotel_id'],
            'owner_user_id' => (int)$profile['owner_user_id'],
            'platform' => $platform,
            'collection_mode' => $mode,
            'target_date' => $targetDate,
            'window_key' => $windowKey,
            'field_priority' => $priority,
            'task_status' => 'queued',
            'truth_gate_status' => 'waiting_for_identity_date_fields_save_readback',
            'idempotency_key' => $idempotency,
        ];
    }

    /** @param array<string,mixed> $task @return array<string,mixed> */
    private function persistTask(array $task): array
    {
        return Db::transaction(function () use ($task): array {
            $existing = Db::name('cloud_collection_tasks')
                ->where('profile_public_id', (string)$task['profile_public_id'])
                ->where('collection_mode', (string)$task['collection_mode'])
                ->where('target_date', (string)$task['target_date'])
                ->where('window_key', (string)$task['window_key'])
                ->order('id', 'desc')
                ->lock(true)
                ->find();
            $attemptNo = 1;
            $retryOfTaskId = null;
            if (is_array($existing)) {
                if ((string)($existing['task_status'] ?? '') !== 'blocked') {
                    return $this->publicTask($existing) + ['dispatch_status' => 'reused'];
                }
                $previousEvidence = json_decode((string)($existing['receipt_evidence_json'] ?? ''), true);
                $previousDispatch = is_array($previousEvidence['dispatch'] ?? null)
                    ? $previousEvidence['dispatch']
                    : [];
                $attemptNo = max(1, (int)($previousDispatch['attempt_no'] ?? 1));
                $gaps = $this->storedGaps($existing);
                $retryAllowed = array_key_exists('retry_allowed', $previousDispatch)
                    ? ($previousDispatch['retry_allowed'] === true)
                    : $this->gapsAreRetryable($gaps);
                if (!$retryAllowed) {
                    return $this->publicTask($existing) + ['dispatch_status' => 'blocked_requires_review'];
                }
                if ($attemptNo >= self::MAX_ATTEMPTS) {
                    return $this->publicTask($existing) + ['dispatch_status' => 'retry_exhausted'];
                }
                $retryNotBefore = trim((string)($previousDispatch['retry_not_before'] ?? ''));
                $retryTimestamp = $retryNotBefore === '' ? false : strtotime($retryNotBefore);
                if ($retryTimestamp !== false && $retryTimestamp > time()) {
                    return $this->publicTask($existing) + [
                        'dispatch_status' => 'retry_backoff',
                        'retry_after_seconds' => max(1, $retryTimestamp - time()),
                    ];
                }
                // A blocked receipt is terminal evidence for that attempt, not
                // for the logical hotel/platform/date collection. Derive the
                // next deterministic attempt key from the preserved prior task
                // so concurrent retries collapse to one new row without
                // deleting or rewriting the failed receipt.
                $task['idempotency_key'] = hash('sha256', implode('|', [
                    (string)$task['idempotency_key'],
                    'retry_after_blocked',
                    (string)($existing['task_public_id'] ?? ''),
                ]));
                $attemptNo++;
                $retryOfTaskId = (string)($existing['task_public_id'] ?? '');
            }
            $now = date('Y-m-d H:i:s');
            $dispatchEvidence = [
                'attempt_no' => $attemptNo,
                'retry_of_task_id' => $retryOfTaskId !== '' ? $retryOfTaskId : null,
                'max_attempts' => self::MAX_ATTEMPTS,
                'terminal_at' => null,
                'retry_allowed' => false,
                'retry_not_before' => null,
            ];
            $row = [
                'task_public_id' => $this->publicId('cct'),
                'profile_id' => $task['profile_id'],
                'profile_public_id' => $task['profile_public_id'],
                'tenant_id' => $task['tenant_id'],
                'system_hotel_id' => $task['system_hotel_id'],
                'owner_user_id' => $task['owner_user_id'],
                'platform' => $task['platform'],
                'collection_mode' => $task['collection_mode'],
                'target_date' => $task['target_date'],
                'window_key' => $task['window_key'],
                'field_priority_json' => json_encode($task['field_priority'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'task_status' => $task['task_status'],
                'truth_gate_status' => $task['truth_gate_status'],
                'gap_codes_json' => null,
                'receipt_evidence_json' => json_encode(
                    ['dispatch' => $dispatchEvidence],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
                'receipt_fingerprint' => null,
                'formal_message_allowed' => 0,
                'idempotency_key' => $task['idempotency_key'],
                'create_time' => $now,
                'update_time' => $now,
            ];
            try {
                $id = (int)Db::name('cloud_collection_tasks')->insertGetId($row);
                $row['id'] = $id;
            } catch (\Throwable $e) {
                $raced = Db::name('cloud_collection_tasks')->where('idempotency_key', (string)$task['idempotency_key'])->find();
                if (!is_array($raced)) {
                    throw $e;
                }
                return $this->publicTask($raced) + ['dispatch_status' => 'reused'];
            }
            return $this->publicTask($row) + [
                'dispatch_status' => is_array($existing) ? 'requeued' : 'queued',
            ];
        });
    }

    /** @param array<string,mixed> $task @return array<string,mixed> */
    private function publicTask(array $task): array
    {
        $fields = $task['field_priority'] ?? json_decode((string)($task['field_priority_json'] ?? '[]'), true);
        $receiptEvidence = json_decode((string)($task['receipt_evidence_json'] ?? ''), true);
        $dispatchEvidence = is_array($receiptEvidence['dispatch'] ?? null)
            ? $receiptEvidence['dispatch']
            : [];
        return [
            'task_id' => (string)($task['task_public_id'] ?? ''),
            'profile_id' => (string)($task['profile_public_id'] ?? ''),
            'tenant_id' => (int)($task['tenant_id'] ?? 0),
            'hotel_id' => (int)($task['system_hotel_id'] ?? 0),
            'owner_user_id' => (int)($task['owner_user_id'] ?? 0),
            'platform' => (string)($task['platform'] ?? ''),
            'collection_mode' => (string)($task['collection_mode'] ?? ''),
            'target_date' => (string)($task['target_date'] ?? ''),
            'field_priority' => is_array($fields) ? array_values($fields) : [],
            'task_status' => (string)($task['task_status'] ?? ''),
            'truth_gate_status' => (string)($task['truth_gate_status'] ?? ''),
            'formal_message_allowed' => (int)($task['formal_message_allowed'] ?? 0) === 1,
            'attempt_no' => max(1, (int)($dispatchEvidence['attempt_no'] ?? 1)),
            'retry_of_task_id' => trim((string)($dispatchEvidence['retry_of_task_id'] ?? '')) ?: null,
            'max_attempts' => max(1, (int)($dispatchEvidence['max_attempts'] ?? self::MAX_ATTEMPTS)),
            'retry_allowed' => ($dispatchEvidence['retry_allowed'] ?? false) === true,
            'retry_not_before' => trim((string)($dispatchEvidence['retry_not_before'] ?? '')) ?: null,
        ];
    }

    /** @param array<string,mixed> $profile @return array<string,mixed> */
    private function publicSkip(array $profile, string $reason): array
    {
        return [
            'hotel_id' => (int)($profile['system_hotel_id'] ?? 0),
            'platform' => (string)($profile['platform'] ?? ''),
            'reason' => $reason,
        ];
    }

    /** @param array<string,mixed> $profile */
    private function profileReadyGap(array $profile, DateTimeImmutable $now): ?string
    {
        if (trim((string)($profile['profile_public_id'] ?? '')) === ''
            || (int)($profile['tenant_id'] ?? 0) <= 0
            || (int)($profile['system_hotel_id'] ?? 0) <= 0
            || (int)($profile['owner_user_id'] ?? 0) <= 0
            || !in_array(strtolower((string)($profile['platform'] ?? '')), ['ctrip', 'meituan'], true)) {
            return 'profile_scope_invalid';
        }
        $readyAt = trim((string)($profile['ready_at'] ?? ''));
        $readyTimestamp = $readyAt === '' ? false : strtotime($readyAt);
        if ($readyTimestamp === false || $readyTimestamp > $now->getTimestamp()) {
            return 'ready_evidence_missing';
        }
        $expiry = trim((string)($profile['session_expires_at'] ?? ''));
        if ($expiry === '') {
            return null;
        }
        $expiryTimestamp = strtotime($expiry);
        if ($expiryTimestamp === false) {
            return 'session_expiry_invalid';
        }
        return $expiryTimestamp <= $now->getTimestamp() ? 'session_expired' : null;
    }

    /** @return array<int,string> */
    private function fieldPriority(string $platform, string $mode): array
    {
        $required = OtaOrderedCollectionPlanner::requiredFieldKeys($platform);
        if ($mode === self::YESTERDAY_FINAL) {
            return $required;
        }
        $todayOrder = [
            'list_exposure',
            'detail_exposure',
            'flow_rate',
            'order_filling_num',
            'order_submit_num',
            'order_amount',
            'room_nights',
            'order_count',
        ];
        return array_values(array_intersect($todayOrder, $required));
    }

    /** @param array<string,mixed> $task @return array<int,string> */
    private function taskFields(array $task): array
    {
        $fields = json_decode((string)($task['field_priority_json'] ?? '[]'), true);
        return $this->fieldNames($fields);
    }

    /** @return array<int,string> */
    private function fieldNames(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $fields = [];
        foreach ($value as $field) {
            if (!is_string($field)) {
                continue;
            }
            $field = strtolower(trim($field));
            if ($field !== '' && preg_match('/^[a-z][a-z0-9_]{0,63}$/', $field) === 1) {
                $fields[$field] = true;
            }
        }
        $fields = array_keys($fields);
        sort($fields, SORT_STRING);
        return $fields;
    }

    private function positiveCount(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }

    /** @param array<string,mixed> $task @return array<int,string> */
    private function storedGaps(array $task): array
    {
        $gaps = json_decode((string)($task['gap_codes_json'] ?? '[]'), true);
        return is_array($gaps)
            ? array_values(array_filter($gaps, static fn(mixed $gap): bool => is_string($gap) && $gap !== ''))
            : [];
    }

    /** @param list<string> $gaps */
    private function gapsAreRetryable(array $gaps): bool
    {
        return $gaps !== [] && array_diff($gaps, self::RETRYABLE_GAPS) === [];
    }

    /** @param array<string,mixed> $task */
    private function hasNewerAttempt(array $task): bool
    {
        return (int)Db::name('cloud_collection_tasks')
            ->where('profile_public_id', (string)($task['profile_public_id'] ?? ''))
            ->where('collection_mode', (string)($task['collection_mode'] ?? ''))
            ->where('target_date', (string)($task['target_date'] ?? ''))
            ->where('window_key', (string)($task['window_key'] ?? ''))
            ->where('id', '>', (int)($task['id'] ?? 0))
            ->count() > 0;
    }

    /**
     * @param array<string,mixed> $task
     * @param array<int,string> $gaps
     * @return array<string,mixed>
     */
    private function receiptResult(array $task, array $gaps, bool $allowed, string $receiptStatus): array
    {
        return [
            'task_id' => (string)$task['task_public_id'],
            'collection_mode' => (string)$task['collection_mode'],
            'target_date' => (string)$task['target_date'],
            'truth_gate_status' => $allowed ? 'passed' : 'blocked_by_data_gap',
            'formal_message_allowed' => $allowed,
            'receipt_status' => $receiptStatus,
            'gaps' => $gaps,
        ];
    }

    private function publicId(string $prefix): string
    {
        return $prefix . '_' . rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }
}
