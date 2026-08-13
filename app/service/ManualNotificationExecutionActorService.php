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

    public function __construct(
        ?callable $userLoader = null,
        ?callable $candidateIdLoader = null,
        ?callable $hotelTenantLoader = null
    ) {
        $this->userLoader = $userLoader;
        $this->candidateIdLoader = $candidateIdLoader;
        $this->hotelTenantLoader = $hotelTenantLoader;
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
            || (int)($actor->tenant_id ?? 0) !== $tenantId
            || (int)($actor->status ?? 0) !== 1
            || !method_exists($actor, 'hasHotelPermission')
        ) {
            return false;
        }
        try {
            return $actor->hasHotelPermission(
                $hotelId,
                'can_fetch_online_data'
            ) === true;
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
