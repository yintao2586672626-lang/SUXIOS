<?php
declare(strict_types=1);

namespace app\service;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use think\facade\Db;

/**
 * Durable handoff from a committed, verified local OTA login to the background
 * hotel lifecycle coordinator. The queue stores only IDs, status and digests.
 */
final class HotelAutopilotKickQueueService
{
    public const TABLE = 'hotel_autopilot_kick_queue';
    public const TRIGGER = 'verified_login';
    private const MAX_ATTEMPTS = 7;

    /** @var Closure(array<string,mixed>,int):array<string,mixed> */
    private Closure $reconciler;

    /** @var Closure():DateTimeImmutable */
    private Closure $clock;

    public function __construct(?callable $reconciler = null, ?callable $clock = null)
    {
        $this->reconciler = $reconciler !== null
            ? Closure::fromCallable($reconciler)
            : static fn(array $hotel, int $actorId): array =>
                (new HotelAutopilotLifecycleService())->reconcileHotel($hotel, $actorId, true);
        $this->clock = $clock !== null
            ? Closure::fromCallable($clock)
            : static fn(): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
    }

    /** @return array<string,mixed> */
    public function enqueueVerifiedLogin(
        int $tenantId,
        int $hotelId,
        int $actorId,
        int $sourceTaskId
    ): array {
        $scope = $this->verifiedLoginScope($tenantId, $hotelId, $actorId, $sourceTaskId);
        $digest = $this->scopeDigest($scope);
        $now = $this->now()->format('Y-m-d H:i:s');
        try {
            Db::name(self::TABLE)->insert([
                'tenant_id' => $tenantId,
                'system_hotel_id' => $hotelId,
                'source_task_id' => $sourceTaskId,
                'requested_by' => $actorId,
                'trigger_type' => self::TRIGGER,
                'platform' => $scope['platform'],
                'status' => 'pending',
                'request_digest' => $digest,
                'attempt_count' => 0,
                'next_attempt_at' => null,
                'claimed_at' => null,
                'completed_at' => null,
                'lifecycle_status' => null,
                'lifecycle_failure_code' => null,
                'failure_code' => null,
                'create_time' => $now,
                'update_time' => $now,
            ]);
        } catch (Throwable $error) {
            $existing = $this->queueRow($tenantId, $hotelId, $sourceTaskId);
            if (!is_array($existing)) {
                throw new RuntimeException('hotel_autopilot_kick_enqueue_failed', 0, $error);
            }
        }

        $stored = $this->queueRow($tenantId, $hotelId, $sourceTaskId);
        if (!is_array($stored)
            || (string)($stored['trigger_type'] ?? '') !== self::TRIGGER
            || !hash_equals($digest, (string)($stored['request_digest'] ?? ''))
            || (int)($stored['requested_by'] ?? 0) !== $actorId
            || (string)($stored['platform'] ?? '') !== $scope['platform']
        ) {
            throw new RuntimeException('hotel_autopilot_kick_enqueue_readback_failed');
        }

        return [
            'status' => 'queued',
            'queue_status' => $this->safeCode((string)($stored['status'] ?? 'pending')) ?: 'pending',
            'queue_id' => (int)$stored['id'],
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'source_task_id' => $sourceTaskId,
            'request_digest' => $digest,
            'readback_verified' => true,
            'external_action_triggered' => false,
            'auto_write_ota' => false,
            'sensitive_values_exposed' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function consumeDue(int $limit = 3): array
    {
        $limit = max(1, min(20, $limit));
        $now = $this->now();
        $nowText = $now->format('Y-m-d H:i:s');
        $this->recoverInterruptedClaims($now);
        try {
            $rows = Db::name(self::TABLE)
                ->whereIn('status', ['pending', 'retry_wait'])
                ->where(function ($query) use ($nowText): void {
                    $query->whereNull('next_attempt_at')
                        ->whereOr('next_attempt_at', '<=', $nowText);
                })
                ->order('id', 'asc')
                ->limit($limit)
                ->select()
                ->toArray();
        } catch (Throwable $error) {
            throw new RuntimeException('hotel_autopilot_kick_queue_unavailable', 0, $error);
        }

        $results = [];
        $failureCount = 0;
        foreach ($rows as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $claimed = $this->claim($candidate, $nowText);
            if (!is_array($claimed)) {
                continue;
            }
            try {
                $result = $this->processClaim($claimed, $now);
            } catch (Throwable $error) {
                $result = $this->deferClaim($claimed, $error, $now);
            }
            if ((string)($result['status'] ?? '') !== 'completed') {
                $failureCount++;
            }
            $results[] = $result;
        }

        return [
            'status' => $failureCount === 0 ? 'completed' : 'partial',
            'processed_count' => count($results),
            'failure_count' => $failureCount,
            'results' => $results,
            'external_action_triggered' => false,
            'auto_write_ota' => false,
            'sensitive_values_exposed' => false,
        ];
    }

    /** @param array<string,mixed> $candidate @return array<string,mixed>|null */
    private function claim(array $candidate, string $now): ?array
    {
        $id = (int)($candidate['id'] ?? 0);
        $attempt = max(0, (int)($candidate['attempt_count'] ?? 0));
        $status = (string)($candidate['status'] ?? '');
        if ($id <= 0 || !in_array($status, ['pending', 'retry_wait'], true)) {
            return null;
        }
        $updated = Db::name(self::TABLE)
            ->where('id', $id)
            ->where('status', $status)
            ->where('attempt_count', $attempt)
            ->update([
                'status' => 'processing',
                'attempt_count' => $attempt + 1,
                'claimed_at' => $now,
                'next_attempt_at' => null,
                'failure_code' => null,
                'update_time' => $now,
            ]);
        if ($updated !== 1) {
            return null;
        }
        $claimed = Db::name(self::TABLE)->where('id', $id)->find();
        if (!is_array($claimed)
            || (string)($claimed['status'] ?? '') !== 'processing'
            || (int)($claimed['attempt_count'] ?? 0) !== $attempt + 1
        ) {
            throw new RuntimeException('hotel_autopilot_kick_claim_readback_failed');
        }
        return $claimed;
    }

    /** @param array<string,mixed> $claimed @return array<string,mixed> */
    private function processClaim(array $claimed, DateTimeImmutable $now): array
    {
        $tenantId = (int)($claimed['tenant_id'] ?? 0);
        $hotelId = (int)($claimed['system_hotel_id'] ?? 0);
        $actorId = (int)($claimed['requested_by'] ?? 0);
        $sourceTaskId = (int)($claimed['source_task_id'] ?? 0);
        $scope = $this->verifiedLoginScope($tenantId, $hotelId, $actorId, $sourceTaskId);
        if (!hash_equals($this->scopeDigest($scope), (string)($claimed['request_digest'] ?? ''))) {
            throw new RuntimeException('hotel_autopilot_kick_scope_digest_mismatch');
        }
        $lifecycle = ($this->reconciler)($scope['hotel'], $actorId);
        if (!is_array($lifecycle)
            || ($lifecycle['readback_verified'] ?? false) !== true
            || (int)($lifecycle['tenant_id'] ?? 0) !== $tenantId
            || (int)($lifecycle['hotel_id'] ?? 0) !== $hotelId
        ) {
            throw new RuntimeException('hotel_autopilot_kick_lifecycle_readback_failed');
        }

        $nowText = $now->format('Y-m-d H:i:s');
        $lifecycleStatus = $this->safeCode((string)($lifecycle['status'] ?? '')) ?: 'unknown';
        $lifecycleFailure = $this->safeCode((string)($lifecycle['failure_code'] ?? ''));
        $updated = Db::name(self::TABLE)
            ->where('id', (int)$claimed['id'])
            ->where('status', 'processing')
            ->where('attempt_count', (int)$claimed['attempt_count'])
            ->update([
                'status' => 'completed',
                'next_attempt_at' => null,
                'completed_at' => $nowText,
                'lifecycle_status' => $lifecycleStatus,
                'lifecycle_failure_code' => $lifecycleFailure !== '' ? $lifecycleFailure : null,
                'failure_code' => null,
                'update_time' => $nowText,
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('hotel_autopilot_kick_completion_write_failed');
        }
        $stored = Db::name(self::TABLE)->where('id', (int)$claimed['id'])->find();
        if (!is_array($stored)
            || (string)($stored['status'] ?? '') !== 'completed'
            || (string)($stored['lifecycle_status'] ?? '') !== $lifecycleStatus
            || trim((string)($stored['completed_at'] ?? '')) === ''
        ) {
            throw new RuntimeException('hotel_autopilot_kick_completion_readback_failed');
        }
        return $this->publicResult($stored);
    }

    /** @param array<string,mixed> $claimed @return array<string,mixed> */
    private function deferClaim(array $claimed, Throwable $error, DateTimeImmutable $now): array
    {
        $attempt = max(1, (int)($claimed['attempt_count'] ?? 1));
        $retry = $attempt < self::MAX_ATTEMPTS;
        $status = $retry ? 'retry_wait' : 'failed';
        $failureCode = $this->safeFailureCode($error);
        $nowText = $now->format('Y-m-d H:i:s');
        $nextAttempt = $retry ? $now->modify('+5 minutes')->format('Y-m-d H:i:s') : null;
        $updated = Db::name(self::TABLE)
            ->where('id', (int)($claimed['id'] ?? 0))
            ->where('status', 'processing')
            ->where('attempt_count', $attempt)
            ->update([
                'status' => $status,
                'next_attempt_at' => $nextAttempt,
                'failure_code' => $failureCode,
                'update_time' => $nowText,
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('hotel_autopilot_kick_defer_write_failed', 0, $error);
        }
        $stored = Db::name(self::TABLE)->where('id', (int)$claimed['id'])->find();
        if (!is_array($stored)
            || (string)($stored['status'] ?? '') !== $status
            || (string)($stored['failure_code'] ?? '') !== $failureCode
        ) {
            throw new RuntimeException('hotel_autopilot_kick_defer_readback_failed', 0, $error);
        }
        return $this->publicResult($stored);
    }

    private function recoverInterruptedClaims(DateTimeImmutable $now): void
    {
        $nowText = $now->format('Y-m-d H:i:s');
        $cutoff = $now->modify('-10 minutes')->format('Y-m-d H:i:s');
        try {
            Db::name(self::TABLE)
                ->where('status', 'processing')
                ->where('claimed_at', '<=', $cutoff)
                ->update([
                    'status' => 'retry_wait',
                    'next_attempt_at' => $nowText,
                    'failure_code' => 'hotel_autopilot_kick_worker_interrupted',
                    'update_time' => $nowText,
                ]);
        } catch (Throwable $error) {
            throw new RuntimeException('hotel_autopilot_kick_queue_unavailable', 0, $error);
        }
    }

    /** @return array<string,mixed> */
    private function verifiedLoginScope(int $tenantId, int $hotelId, int $actorId, int $sourceTaskId): array
    {
        if ($tenantId <= 0 || $hotelId <= 0 || $actorId <= 0 || $sourceTaskId <= 0) {
            throw new InvalidArgumentException('hotel_autopilot_kick_scope_invalid');
        }
        $task = Db::name('ota_local_collector_tasks')
            ->where('id', $sourceTaskId)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('user_id', $actorId)
            ->find();
        if (!is_array($task)
            || !in_array((string)($task['task_type'] ?? ''), ['login', 'session_probe'], true)
            || (string)($task['status'] ?? '') !== 'success'
            || trim((string)($task['finished_at'] ?? '')) === ''
        ) {
            throw new RuntimeException('hotel_autopilot_kick_login_receipt_unverified');
        }
        $summary = json_decode((string)($task['result_summary_json'] ?? ''), true);
        if (!is_array($summary)
            || (string)($summary['session_status'] ?? '') !== 'current_session_verified'
            || ($summary['sensitive_values_received'] ?? true) !== false
        ) {
            throw new RuntimeException('hotel_autopilot_kick_login_receipt_unverified');
        }
        $platform = $this->safeCode((string)($task['platform'] ?? ''));
        $accountId = (int)($task['account_id'] ?? 0);
        $deviceId = (int)($task['device_id'] ?? 0);
        if (!in_array($platform, ['ctrip', 'meituan'], true) || $accountId <= 0 || $deviceId <= 0) {
            throw new RuntimeException('hotel_autopilot_kick_login_scope_incomplete');
        }
        $user = Db::name('users')
            ->where('id', $actorId)
            ->where('tenant_id', $tenantId)
            ->where('status', 1)
            ->find();
        $hotel = Db::name('hotels')
            ->where('id', $hotelId)
            ->where('tenant_id', $tenantId)
            ->find();
        $account = Db::name('ota_local_collector_accounts')
            ->where('id', $accountId)
            ->where('tenant_id', $tenantId)
            ->where('user_id', $actorId)
            ->where('device_id', $deviceId)
            ->where('platform', $platform)
            ->find();
        $mapping = Db::name('ota_local_collector_account_hotels')
            ->where('tenant_id', $tenantId)
            ->where('account_id', $accountId)
            ->where('system_hotel_id', $hotelId)
            ->where('platform', $platform)
            ->where('status', 'active')
            ->find();
        if (!is_array($user)
            || !is_array($hotel)
            || !is_array($account)
            || !is_array($mapping)
            || (string)($account['status'] ?? '') !== 'active'
            || (string)($account['session_status'] ?? '') !== 'current_session_verified'
        ) {
            throw new RuntimeException('hotel_autopilot_kick_login_scope_readback_failed');
        }
        return [
            'schema_version' => 'hotel_autopilot_kick.v1',
            'trigger_type' => self::TRIGGER,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'actor_id' => $actorId,
            'source_task_id' => $sourceTaskId,
            'task_type' => (string)$task['task_type'],
            'platform' => $platform,
            'account_id' => $accountId,
            'device_id' => $deviceId,
            'hotel' => $hotel,
        ];
    }

    /** @param array<string,mixed> $scope */
    private function scopeDigest(array $scope): string
    {
        $safe = [
            'schema_version' => (string)$scope['schema_version'],
            'trigger_type' => (string)$scope['trigger_type'],
            'tenant_id' => (int)$scope['tenant_id'],
            'hotel_id' => (int)$scope['hotel_id'],
            'actor_id' => (int)$scope['actor_id'],
            'source_task_id' => (int)$scope['source_task_id'],
            'task_type' => (string)$scope['task_type'],
            'platform' => (string)$scope['platform'],
            'account_id' => (int)$scope['account_id'],
            'device_id' => (int)$scope['device_id'],
        ];
        ksort($safe);
        return hash('sha256', (string)json_encode(
            $safe,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    /** @return array<string,mixed>|null */
    private function queueRow(int $tenantId, int $hotelId, int $sourceTaskId): ?array
    {
        $row = Db::name(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('system_hotel_id', $hotelId)
            ->where('source_task_id', $sourceTaskId)
            ->find();
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function publicResult(array $row): array
    {
        return [
            'queue_id' => (int)($row['id'] ?? 0),
            'tenant_id' => (int)($row['tenant_id'] ?? 0),
            'hotel_id' => (int)($row['system_hotel_id'] ?? 0),
            'source_task_id' => (int)($row['source_task_id'] ?? 0),
            'status' => $this->safeCode((string)($row['status'] ?? '')),
            'attempt_count' => max(0, (int)($row['attempt_count'] ?? 0)),
            'lifecycle_status' => $this->safeCode((string)($row['lifecycle_status'] ?? '')),
            'lifecycle_failure_code' => $this->safeCode((string)($row['lifecycle_failure_code'] ?? '')),
            'failure_code' => $this->safeCode((string)($row['failure_code'] ?? '')),
            'readback_verified' => true,
            'external_action_triggered' => false,
            'auto_write_ota' => false,
            'sensitive_values_exposed' => false,
        ];
    }

    private function safeFailureCode(Throwable $error): string
    {
        $code = $this->safeCode($error->getMessage());
        return str_starts_with($code, 'hotel_') ? $code : 'hotel_autopilot_kick_processing_failed';
    }

    private function safeCode(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_.:-]+/', '_', $value) ?? '';
        return trim(substr($value, 0, 120), '_');
    }

    private function now(): DateTimeImmutable
    {
        $value = ($this->clock)();
        if (!$value instanceof DateTimeImmutable) {
            throw new RuntimeException('hotel_autopilot_kick_clock_invalid');
        }
        return $value;
    }
}
