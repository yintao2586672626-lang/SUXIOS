<?php
declare(strict_types=1);

namespace app\service;

use app\model\User;
use think\facade\Db;

/**
 * Prepares one hotel's internal daily operating artifacts without approving,
 * executing, messaging, or writing to an external system.
 */
final class DailyOperatingPreparationService
{
    public const CONTRACT_VERSION = 'daily_operating_preparation.v1';

    /** @var callable|null */
    private $actorResolver;

    /** @var callable|null */
    private $priorityEnsurer;

    /** @var callable|null */
    private $broadcastGenerator;

    /** @var callable|null */
    private $scopeVerifier;

    /** @var callable|null */
    private $collectionStateReader;

    /** @var callable|null */
    private $collectionRunReader;

    /** @var callable|null */
    private $collectionReceiptReader;

    public function __construct(
        ?callable $actorResolver = null,
        ?callable $priorityEnsurer = null,
        ?callable $broadcastGenerator = null,
        ?callable $scopeVerifier = null,
        ?callable $collectionStateReader = null,
        ?callable $collectionRunReader = null,
        ?callable $collectionReceiptReader = null
    ) {
        $this->actorResolver = $actorResolver;
        $this->priorityEnsurer = $priorityEnsurer;
        $this->broadcastGenerator = $broadcastGenerator;
        $this->scopeVerifier = $scopeVerifier;
        $this->collectionStateReader = $collectionStateReader;
        $this->collectionRunReader = $collectionRunReader;
        $this->collectionReceiptReader = $collectionReceiptReader;
    }

    /** @return array<string,mixed> */
    public function prepare(int $tenantId, int $hotelId, string $businessDate): array
    {
        if ($tenantId <= 0 || $hotelId <= 0 || !$this->validDate($businessDate)) {
            throw new \InvalidArgumentException('daily_operating_preparation_scope_invalid');
        }
        if (!$this->scopeVerified($tenantId, $hotelId)) {
            throw new \RuntimeException('daily_operating_hotel_scope_unavailable');
        }
        $collectionState = $this->collectionState($tenantId, $hotelId, $businessDate);
        if (($collectionState['status'] ?? '') !== 'ready') {
            return $this->waitingResult(
                $tenantId,
                $hotelId,
                $businessDate,
                (string)($collectionState['reason_code'] ?? 'daily_collection_state_unavailable')
            );
        }

        $actor = $this->resolveActor($tenantId, $hotelId);
        $broadcast = $this->prepareBroadcast($hotelId, $businessDate);
        $priority = ($actor['status'] ?? '') === 'ready'
            ? $this->preparePriority(
                $tenantId,
                $hotelId,
                (int)$actor['actor_id'],
                $businessDate
            )
            : [
                'status' => 'blocked',
                'reason_code' => (string)($actor['reason_code'] ?? 'daily_operating_actor_unavailable'),
                'run_id' => null,
                'execution_intent_id' => null,
                'lifecycle_status' => 'not_created',
                'execution_task_count' => 0,
                'readback_verified' => false,
                'existing_item_preserved' => false,
                'source_changed' => false,
            ];

        $preparedCount = (int)(($priority['readback_verified'] ?? false) === true)
            + (int)(($broadcast['readback_verified'] ?? false) === true);
        $status = $preparedCount === 2
            ? 'prepared'
            : ($preparedCount > 0 ? 'partial' : 'blocked');

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'status' => $status,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'actor' => [
                'status' => (string)($actor['status'] ?? 'blocked'),
                'actor_id' => (int)($actor['actor_id'] ?? 0) ?: null,
                'resolution' => (string)($actor['resolution'] ?? 'unresolved'),
                'reason_code' => (string)($actor['reason_code'] ?? ''),
            ],
            'daily_priority' => $priority,
            'trusted_broadcast' => $broadcast,
            'automatic_approval' => false,
            'automatic_execution' => false,
            'external_write_count' => 0,
            'external_message_count' => 0,
            'message_sent' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function prepareBroadcast(int $hotelId, string $businessDate): array
    {
        try {
            $snapshot = $this->broadcastGenerator !== null
                ? call_user_func($this->broadcastGenerator, $hotelId, $businessDate)
                : (new AiDailyReportBroadcastSnapshotService())->generateAndReadback(
                    $hotelId,
                    $businessDate,
                    0,
                    'background'
                );
            $persisted = ($snapshot['persisted'] ?? false) === true;
            $readback = ($snapshot['readback_verified'] ?? false) === true;
            return [
                'status' => $persisted && $readback
                    ? 'saved_and_readback_verified'
                    : (string)($snapshot['facts_broadcast_status'] ?? 'blocked'),
                'facts_broadcast_status' => (string)($snapshot['facts_broadcast_status'] ?? ''),
                'analysis_status' => (string)($snapshot['analysis_status'] ?? ''),
                'snapshot_id' => (int)($snapshot['snapshot_id'] ?? 0) ?: null,
                'version_no' => (int)($snapshot['version_no'] ?? 0) ?: null,
                'generation_trigger' => (string)($snapshot['generation_trigger'] ?? 'background'),
                'view_status' => (string)($snapshot['view_status'] ?? ''),
                'persisted' => $persisted,
                'readback_verified' => $readback,
            ];
        } catch (\Throwable $error) {
            return [
                'status' => 'failed',
                'reason_code' => $this->safeReason($error),
                'snapshot_id' => null,
                'persisted' => false,
                'readback_verified' => false,
            ];
        }
    }

    /** @return array<string,mixed> */
    private function preparePriority(
        int $tenantId,
        int $hotelId,
        int $actorId,
        string $businessDate
    ): array {
        try {
            $saved = $this->priorityEnsurer !== null
                ? call_user_func(
                    $this->priorityEnsurer,
                    $tenantId,
                    $hotelId,
                    $actorId,
                    $businessDate
                )
                : (new OperatingOpportunityLabService())->ensureDailyPriorityForAutomation(
                    $tenantId,
                    $hotelId,
                    $actorId,
                    $businessDate
                );
            return [
                'status' => (string)($saved['automation_status'] ?? 'prepared'),
                'run_id' => (int)($saved['run']['id'] ?? 0) ?: null,
                'execution_intent_id' => (int)($saved['execution_intent_id'] ?? 0) ?: null,
                'lifecycle_status' => (string)($saved['lifecycle_status'] ?? 'pending_approval'),
                'execution_task_count' => max(0, (int)($saved['execution_task_count'] ?? 0)),
                'readback_verified' => ($saved['readback_verified'] ?? false) === true,
                'existing_item_preserved' => ($saved['existing_item_preserved'] ?? false) === true,
                'source_changed' => ($saved['source_changed'] ?? false) === true,
            ];
        } catch (\Throwable $error) {
            return [
                'status' => 'blocked',
                'reason_code' => $this->safeReason($error),
                'run_id' => null,
                'execution_intent_id' => null,
                'lifecycle_status' => 'not_created',
                'execution_task_count' => 0,
                'readback_verified' => false,
                'existing_item_preserved' => false,
                'source_changed' => false,
            ];
        }
    }

    /** @return array<string,mixed> */
    private function resolveActor(int $tenantId, int $hotelId): array
    {
        if ($this->actorResolver !== null) {
            $resolved = call_user_func($this->actorResolver, $tenantId, $hotelId);
            return is_array($resolved) ? $resolved : [];
        }
        try {
            $hotel = Db::name('hotels')
                ->field('id,tenant_id,status,owner_user_id,created_by')
                ->where('id', $hotelId)
                ->where('tenant_id', $tenantId)
                ->where('status', 1)
                ->find();
            if (!is_array($hotel)) {
                return $this->blockedActor('daily_operating_hotel_scope_unavailable');
            }
        } catch (\Throwable) {
            return $this->blockedActor('daily_operating_actor_resolution_failed');
        }
        $planOwnerId = 0;
        try {
            $planOwnerId = (int)Db::name('hotel_collection_plans')
                ->where('tenant_id', $tenantId)
                ->where('system_hotel_id', $hotelId)
                ->where('enabled', 1)
                ->where('active_slot', 1)
                ->where('plan_status', 'active')
                ->where('validation_status', 'ready')
                ->order('id', 'desc')
                ->value('execution_owner_user_id');
        } catch (\Throwable) {
            $planOwnerId = 0;
        }
        $candidates = array_values(array_unique(array_filter([
            $planOwnerId,
            (int)($hotel['owner_user_id'] ?? 0),
            (int)($hotel['created_by'] ?? 0),
        ], static fn(int $id): bool => $id > 0)));
        foreach ($candidates as $candidateId) {
            try {
                $actor = User::where('id', $candidateId)->where('status', 1)->find();
                $tenantMatches = $actor instanceof User
                    && (int)($actor->tenant_id ?? 0) === $tenantId;
                $directlyGrantedSuperAdmin = $actor instanceof User
                    && $actor->isSuperAdmin()
                    && $this->hasDirectOperationPermission(
                        $candidateId,
                        $tenantId,
                        $hotelId
                    );
                $operationAllowed = $directlyGrantedSuperAdmin
                    || ($tenantMatches
                        && $actor instanceof User
                        && $actor->hasHotelPermission($hotelId, 'operation.execute'));
                if ($actor instanceof User
                    && ($tenantMatches || $directlyGrantedSuperAdmin)
                    && $operationAllowed
                ) {
                    return [
                        'status' => 'ready',
                        'actor_id' => $candidateId,
                        'resolution' => $candidateId === $planOwnerId
                            ? 'active_collection_plan_owner'
                            : 'hotel_owner',
                        'reason_code' => '',
                    ];
                }
            } catch (\Throwable) {
                continue;
            }
        }
        return $this->blockedActor('daily_operating_actor_unavailable');
    }

    private function scopeVerified(int $tenantId, int $hotelId): bool
    {
        if ($this->scopeVerifier !== null) {
            return call_user_func($this->scopeVerifier, $tenantId, $hotelId) === true;
        }
        try {
            return Db::name('hotels')
                ->where('id', $hotelId)
                ->where('tenant_id', $tenantId)
                ->where('status', 1)
                ->count() === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{status:string,reason_code:string} */
    private function collectionState(int $tenantId, int $hotelId, string $businessDate): array
    {
        if ($this->collectionStateReader !== null) {
            $state = call_user_func($this->collectionStateReader, $tenantId, $hotelId, $businessDate);
            return is_array($state) ? $state : ['status' => 'blocked', 'reason_code' => 'daily_collection_state_invalid'];
        }
        try {
            $row = $this->collectionRunReader !== null
                ? call_user_func($this->collectionRunReader, $tenantId, $hotelId, $businessDate)
                : Db::name('hotel_collection_plan_runs')
                    ->field('dispatcher_run_id,status')
                    ->where('tenant_id', $tenantId)
                    ->where('system_hotel_id', $hotelId)
                    ->where('business_date', $businessDate)
                    ->where('run_mode', 'daily')
                    ->order('id', 'desc')
                    ->find();
            $row = is_array($row) ? $row : [];
        } catch (\Throwable) {
            return ['status' => 'blocked', 'reason_code' => 'daily_collection_state_unavailable'];
        }
        $status = strtolower(trim((string)($row['status'] ?? '')));
        if (in_array($status, ['started', 'in_progress', 'collected'], true)) {
            return ['status' => 'waiting', 'reason_code' => 'daily_collection_still_running'];
        }
        if ($status === '') {
            return ['status' => 'blocked', 'reason_code' => 'daily_collection_run_missing'];
        }
        if ($status !== 'succeeded') {
            return ['status' => 'blocked', 'reason_code' => 'daily_collection_not_succeeded'];
        }
        $dispatcherRunId = trim((string)($row['dispatcher_run_id'] ?? ''));
        if ($dispatcherRunId === '') {
            return ['status' => 'blocked', 'reason_code' => 'daily_collection_receipt_unverified'];
        }
        try {
            $receipt = $this->collectionReceiptReader !== null
                ? call_user_func(
                    $this->collectionReceiptReader,
                    $dispatcherRunId,
                    $hotelId,
                    $businessDate
                )
                : (new HotelCollectionRunReceiptService())->readExact(
                    $dispatcherRunId,
                    $hotelId,
                    $businessDate
                );
            $receipt = is_array($receipt) ? $receipt : [];
        } catch (\Throwable) {
            return ['status' => 'blocked', 'reason_code' => 'daily_collection_receipt_unverified'];
        }
        if ((string)($receipt['status'] ?? '') !== 'succeeded'
            || ($receipt['readback_verified'] ?? false) !== true
            || (int)($receipt['tenant_id'] ?? 0) !== $tenantId
            || (int)($receipt['system_hotel_id'] ?? 0) !== $hotelId
            || (string)($receipt['business_date'] ?? '') !== $businessDate
        ) {
            return ['status' => 'blocked', 'reason_code' => 'daily_collection_receipt_unverified'];
        }
        return ['status' => 'ready', 'reason_code' => ''];
    }

    /** @return array<string,mixed> */
    private function waitingResult(int $tenantId, int $hotelId, string $businessDate, string $reason): array
    {
        $blocked = [
            'status' => 'blocked',
            'reason_code' => $reason,
            'readback_verified' => false,
        ];
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'status' => $reason === 'daily_collection_still_running' ? 'waiting_for_collection' : 'blocked',
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'actor' => ['status' => 'not_resolved', 'actor_id' => null, 'resolution' => 'deferred', 'reason_code' => $reason],
            'daily_priority' => $blocked + ['run_id' => null, 'execution_intent_id' => null],
            'trusted_broadcast' => $blocked + ['snapshot_id' => null, 'persisted' => false],
            'automatic_approval' => false,
            'automatic_execution' => false,
            'external_write_count' => 0,
            'external_message_count' => 0,
            'message_sent' => false,
        ];
    }

    private function hasDirectOperationPermission(
        int $userId,
        int $tenantId,
        int $hotelId
    ): bool {
        try {
            return Db::name('user_hotel_permissions')
                ->where('user_id', $userId)
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->where('status', 'active')
                ->where('can_operation', 1)
                ->count() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    private function blockedActor(string $reason): array
    {
        return [
            'status' => 'blocked',
            'actor_id' => 0,
            'resolution' => 'unresolved',
            'reason_code' => $reason,
        ];
    }

    private function validDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        return $date instanceof \DateTimeImmutable
            && $date->format('Y-m-d') === trim($value);
    }

    private function safeReason(\Throwable $error): string
    {
        $reason = strtolower(trim($error->getMessage()));
        $reason = preg_replace('/[^a-z0-9_-]+/', '_', $reason) ?: '';
        return substr(trim($reason, '_'), 0, 120) ?: 'daily_operating_preparation_failed';
    }
}
