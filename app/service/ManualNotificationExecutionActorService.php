<?php
declare(strict_types=1);

namespace app\service;

use app\model\User;
use think\facade\Db;

/**
 * Resolves the tenant-local hotel account that may collect source data for a
 * saved notification. The plan creator remains audit metadata and must not be
 * reused as a collector identity when it belongs to another tenant.
 */
final class ManualNotificationExecutionActorService
{
    /** @var callable|null */
    private $userLoader;

    /** @var callable|null */
    private $candidateIdLoader;

    /** @var callable|null */
    private $hotelTenantLoader;

    /** @var callable|null */
    private $collectionPlanOwnerLoader;

    /** @var callable|null */
    private $collectionPermissionLoader;

    public function __construct(
        ?callable $userLoader = null,
        ?callable $candidateIdLoader = null,
        ?callable $hotelTenantLoader = null,
        ?callable $collectionPlanOwnerLoader = null,
        ?callable $collectionPermissionLoader = null
    ) {
        $this->userLoader = $userLoader;
        $this->candidateIdLoader = $candidateIdLoader;
        $this->hotelTenantLoader = $hotelTenantLoader;
        $this->collectionPlanOwnerLoader = $collectionPlanOwnerLoader;
        $this->collectionPermissionLoader = $collectionPermissionLoader;
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    public function resolve(array $plan): array
    {
        $tenantId = (int)($plan['tenant_id'] ?? 0);
        $hotelId = (int)($plan['hotel_id'] ?? 0);
        $creatorId = (int)($plan['created_by'] ?? 0);
        if ($tenantId <= 0 || $hotelId <= 0) {
            return $this->blocked(
                'manual_notification_execution_scope_missing',
                $tenantId,
                $hotelId,
                $creatorId
            );
        }

        $hotelTenantId = $this->hotelTenantId($hotelId);
        if ($hotelTenantId <= 0 || $hotelTenantId !== $tenantId) {
            return $this->blocked(
                'manual_notification_execution_hotel_tenant_mismatch',
                $tenantId,
                $hotelId,
                $creatorId
            );
        }

        $collectionPlanOwner = $this->collectionPlanOwner($tenantId, $hotelId);
        if ($collectionPlanOwner['found']) {
            $ownerId = $collectionPlanOwner['actor_id'];
            $owner = $ownerId > 0 ? $this->loadUser($ownerId) : null;
            if (!$this->eligible($owner, $tenantId, $hotelId)) {
                return $this->blocked(
                    'manual_notification_collection_plan_execution_actor_invalid',
                    $tenantId,
                    $hotelId,
                    $creatorId
                );
            }
            return $this->ready(
                $owner,
                'active_collection_plan_owner',
                $tenantId,
                $hotelId,
                $creatorId
            );
        }

        $creator = $creatorId > 0 ? $this->loadUser($creatorId) : null;
        if ($this->eligible($creator, $tenantId, $hotelId)) {
            return $this->ready(
                $creator,
                'plan_creator',
                $tenantId,
                $hotelId,
                $creatorId
            );
        }

        foreach ($this->candidateIds($tenantId, $hotelId) as $candidateId) {
            if ($candidateId <= 0 || $candidateId === $creatorId) {
                continue;
            }
            $candidate = $this->loadUser($candidateId);
            if (!$this->eligible($candidate, $tenantId, $hotelId)) {
                continue;
            }
            return $this->ready(
                $candidate,
                'tenant_hotel_authorized',
                $tenantId,
                $hotelId,
                $creatorId
            );
        }

        return $this->blocked(
            'manual_notification_execution_actor_missing',
            $tenantId,
            $hotelId,
            $creatorId
        );
    }

    private function hotelTenantId(int $hotelId): int
    {
        try {
            if ($this->hotelTenantLoader !== null) {
                return (int)call_user_func($this->hotelTenantLoader, $hotelId);
            }
            return (int)Db::name('hotels')
                ->where('id', $hotelId)
                ->value('tenant_id');
        } catch (\Throwable) {
            return 0;
        }
    }

    private function loadUser(int $userId): mixed
    {
        try {
            if ($this->userLoader !== null) {
                return call_user_func($this->userLoader, $userId);
            }
            return User::where('id', $userId)->where('status', 1)->find();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{found:bool,actor_id:int} */
    private function collectionPlanOwner(int $tenantId, int $hotelId): array
    {
        try {
            if ($this->collectionPlanOwnerLoader !== null) {
                $loaded = call_user_func(
                    $this->collectionPlanOwnerLoader,
                    $tenantId,
                    $hotelId
                );
                if ($loaded === null || $loaded === false) {
                    return ['found' => false, 'actor_id' => 0];
                }
                if (is_array($loaded)) {
                    return [
                        'found' => (bool)($loaded['found'] ?? true),
                        'actor_id' => (int)(
                            $loaded['execution_owner_user_id']
                                ?? $loaded['actor_id']
                                ?? 0
                        ),
                    ];
                }
                return ['found' => true, 'actor_id' => (int)$loaded];
            }

            $row = Db::name('hotel_collection_plans')
                ->field('id,execution_owner_user_id')
                ->where('tenant_id', $tenantId)
                ->where('system_hotel_id', $hotelId)
                ->where('enabled', 1)
                ->where('active_slot', 1)
                ->where('plan_status', 'active')
                ->where('validation_status', 'ready')
                ->order('id', 'desc')
                ->find();
            if (!is_array($row) || $row === []) {
                return ['found' => false, 'actor_id' => 0];
            }
            return [
                'found' => true,
                'actor_id' => (int)($row['execution_owner_user_id'] ?? 0),
            ];
        } catch (\Throwable) {
            return ['found' => false, 'actor_id' => 0];
        }
    }

    /** @return list<int> */
    private function candidateIds(int $tenantId, int $hotelId): array
    {
        try {
            if ($this->candidateIdLoader !== null) {
                $loaded = call_user_func(
                    $this->candidateIdLoader,
                    $tenantId,
                    $hotelId
                );
                return $this->normalizeIds(is_array($loaded) ? $loaded : []);
            }
            $rows = Db::name('user_hotel_permissions')
                ->alias('permission')
                ->join('users account', 'account.id = permission.user_id')
                ->field('permission.user_id')
                ->where('permission.tenant_id', $tenantId)
                ->where('permission.hotel_id', $hotelId)
                ->where('permission.status', 'active')
                ->where('permission.can_fetch_online_data', 1)
                ->where('account.tenant_id', $tenantId)
                ->where('account.status', 1)
                ->order('permission.is_primary', 'desc')
                ->order('permission.update_time', 'desc')
                ->order('permission.user_id', 'asc')
                ->select()
                ->toArray();
            return $this->normalizeIds(array_column($rows, 'user_id'));
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<int, mixed> $values @return list<int> */
    private function normalizeIds(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            $id = (int)$value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    private function eligible(mixed $actor, int $tenantId, int $hotelId): bool
    {
        if (!is_object($actor)
            || (int)($actor->id ?? 0) <= 0
            || (int)($actor->status ?? 0) !== 1
            || !method_exists($actor, 'hasHotelPermission')
        ) {
            return false;
        }
        try {
            $actorTenantId = $actor->tenant_id ?? null;
            $tenantMatches = (int)$actorTenantId === $tenantId;
            $globalSuperAdmin = $actorTenantId === null
                && method_exists($actor, 'isSuperAdmin')
                && $actor->isSuperAdmin() === true;
            if (!$tenantMatches && !$globalSuperAdmin) {
                return false;
            }
            if ($globalSuperAdmin) {
                return $this->hasDirectCollectionPermission(
                    (int)$actor->id,
                    $tenantId,
                    $hotelId
                );
            }
            return $actor->hasHotelPermission(
                $hotelId,
                'can_fetch_online_data'
            ) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function hasDirectCollectionPermission(
        int $userId,
        int $tenantId,
        int $hotelId
    ): bool {
        try {
            if ($this->collectionPermissionLoader !== null) {
                return call_user_func(
                    $this->collectionPermissionLoader,
                    $userId,
                    $tenantId,
                    $hotelId
                ) === true;
            }
            return Db::name('user_hotel_permissions')
                ->where('user_id', $userId)
                ->where('tenant_id', $tenantId)
                ->where('hotel_id', $hotelId)
                ->where('status', 'active')
                ->where('can_fetch_online_data', 1)
                ->count() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    private function ready(
        object $actor,
        string $resolution,
        int $tenantId,
        int $hotelId,
        int $creatorId
    ): array {
        return [
            'status' => 'ready',
            'reason_code' => 'manual_notification_execution_actor_ready',
            'actor' => $actor,
            'actor_id' => (int)$actor->id,
            'resolution' => $resolution,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'plan_creator_id' => $creatorId,
            'creator_replaced' => (int)$actor->id !== $creatorId,
        ];
    }

    /** @return array<string, mixed> */
    private function blocked(
        string $reasonCode,
        int $tenantId,
        int $hotelId,
        int $creatorId
    ): array {
        return [
            'status' => 'blocked',
            'reason_code' => $reasonCode,
            'actor' => null,
            'actor_id' => 0,
            'resolution' => 'unresolved',
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'plan_creator_id' => $creatorId,
            'creator_replaced' => false,
        ];
    }
}
