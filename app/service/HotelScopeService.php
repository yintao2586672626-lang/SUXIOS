<?php
declare(strict_types=1);

namespace app\service;

use app\model\Hotel;
use app\model\User;
use RuntimeException;
use think\facade\Db;

class HotelScopeService
{
    private TenantContext $tenantContext;

    /** @var array<string, bool> */
    private array $tableColumnCache = [];

    /**
     * Request-local authorization snapshots are keyed by the actual User
     * object. WeakMap avoids spl_object_id reuse in long-lived workers while
     * allowing released model instances to be collected.
     *
     * @var \WeakMap<User, array{
     *     accessible: array<string, array<int, int>>,
     *     permissions: array<string, array<string, mixed>|null>,
     *     owned: array<string, bool>
     * }>|null
     */
    private ?\WeakMap $userCache = null;

    /** @var array<string, bool> */
    private array $hotelEnabledCache = [];

    public function __construct(?TenantContext $tenantContext = null)
    {
        $this->tenantContext = $tenantContext ?? new TenantContext();
    }

    /**
     * @return array<int, int>
     */
    public function accessibleHotelIds(User $user, ?string $capability = null): array
    {
        $cacheKey = $this->userScopeCacheKey($user, $capability);
        $cache = $this->userCacheBucket($user);
        if (array_key_exists($cacheKey, $cache['accessible'])) {
            return $cache['accessible'][$cacheKey];
        }

        $hotelIds = $user->isSuperAdmin()
            ? $this->enabledHotelIds()
            : $this->ownedOrGrantedHotelIds($user, $capability);

        $cache['accessible'][$cacheKey] = $hotelIds;
        $this->storeUserCacheBucket($user, $cache);
        return $hotelIds;
    }

    public function canAccessHotel(User $user, int $hotelId, ?string $capability = null): bool
    {
        if ($hotelId <= 0) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return $this->isHotelEnabledAcrossTenants($hotelId);
        }

        return in_array($hotelId, $this->accessibleHotelIds($user, $capability), true);
    }

    public function invalidateUser(User $user): void
    {
        if ($this->userCache !== null && isset($this->userCache[$user])) {
            unset($this->userCache[$user]);
        }
    }

    public function invalidateHotel(int $hotelId): void
    {
        if ($hotelId > 0) {
            unset($this->hotelEnabledCache[(string)$hotelId]);
        }

        // Hotel status/ownership can affect every user bucket. Invalidation is
        // rare and request-local, so a full user-snapshot reset is safer than
        // trying to remove only arrays that happen to contain this hotel.
        $this->userCache = null;
    }

    public function hotelPermissionAllows(User $user, int $hotelId, string $capability): bool
    {
        if ($hotelId <= 0) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return $this->isHotelEnabledAcrossTenants($hotelId);
        }

        if (!$this->canAccessHotel($user, $hotelId, $capability)) {
            return false;
        }

        $record = $this->hotelPermissionRecord($user, $hotelId);
        if ($record === null) {
            return ($this->isOwnedHotel($user, $hotelId) || $this->isPrimaryHotel($user, $hotelId))
                && $this->ownerDefaultAllows($capability);
        }

        $hasPermissionColumn = false;
        foreach ($this->permissionColumns($capability) as $column) {
            if (array_key_exists($column, $record)) {
                $hasPermissionColumn = true;
                return (int)$record[$column] === 1;
            }
        }

        if (!$hasPermissionColumn && ($this->isOwnedHotel($user, $hotelId) || $this->isPrimaryHotel($user, $hotelId))) {
            return $this->ownerDefaultAllows($capability);
        }

        return false;
    }

    /**
     * @return array{type: string, hotel_ids: array<int, int>, source_field: string}
     */
    public function scopeContext(User $user): array
    {
        $hotelIds = $this->accessibleHotelIds($user);
        if ($user->isSuperAdmin()) {
            return [
                'type' => 'all',
                'hotel_ids' => $hotelIds,
                'source_field' => 'admin',
            ];
        }

        return [
            'type' => 'owned_or_granted',
            'hotel_ids' => $hotelIds,
            'source_field' => trim($this->hotelOwnershipColumn() . '+user_hotel_permissions', '+'),
        ];
    }

    /**
     * @return array<int, int>
     */
    private function enabledHotelIds(): array
    {
        return array_values(array_map(
            'intval',
            Hotel::withoutTenantScope()->where('status', Hotel::STATUS_ENABLED)->column('id')
        ));
    }

    /**
     * @return array<int, int>
     */
    private function ownedHotelIds(User $user): array
    {
        $userId = (int)($user->id ?? 0);
        if ($userId <= 0) {
            return [];
        }

        $column = $this->hotelOwnershipColumn();
        if ($column === '') {
            return [];
        }

        return array_values(array_map('intval', Hotel::where('status', Hotel::STATUS_ENABLED)
            ->where($column, $userId)
            ->column('id')));
    }

    /**
     * @return array<int, int>
     */
    private function ownedOrGrantedHotelIds(User $user, ?string $capability = null): array
    {
        $hotelIds = array_values(array_unique(array_filter(array_merge(
            $this->primaryHotelIds($user),
            $this->ownedHotelIds($user),
            $this->grantedHotelIds($user, $capability)
        ), static fn(int $hotelId): bool => $hotelId > 0)));

        return $this->filterHotelIdsByTenant($user, $hotelIds);
    }

    /**
     * @param array<int, int> $hotelIds
     * @return array<int, int>
     */
    private function filterHotelIdsByTenant(User $user, array $hotelIds): array
    {
        if ($hotelIds === [] || !$this->tableColumnExists('hotels', 'tenant_id')) {
            return $hotelIds;
        }

        $tenantId = $this->tenantContext->currentUserTenantId($user);
        if ($tenantId <= 0) {
            return [];
        }

        $tenantHotelIds = array_map('intval', Hotel::whereIn('id', $hotelIds)
            ->where('tenant_id', $tenantId)
            ->where('status', Hotel::STATUS_ENABLED)
            ->column('id'));

        return array_values(array_filter(
            $hotelIds,
            static fn(int $hotelId): bool => in_array($hotelId, $tenantHotelIds, true)
        ));
    }

    /**
     * @return array<int, int>
     */
    private function primaryHotelIds(User $user): array
    {
        $hotelId = (int)($user->hotel_id ?? 0);
        if ($hotelId <= 0 || !$this->isHotelEnabled($hotelId)) {
            return [];
        }

        return [$hotelId];
    }

    /**
     * @return array<int, int>
     */
    private function grantedHotelIds(User $user, ?string $capability = null): array
    {
        $userId = (int)($user->id ?? 0);
        if ($userId <= 0 || !$this->tableColumnExists('user_hotel_permissions', 'hotel_id')) {
            return [];
        }

        $query = Db::name('user_hotel_permissions')
            ->alias('uhp')
            ->join('hotels h', 'h.id = uhp.hotel_id')
            ->where('uhp.user_id', $userId)
            ->where('h.status', Hotel::STATUS_ENABLED);

        if (
            $this->tableColumnExists('user_hotel_permissions', 'tenant_id')
            && $this->tableColumnExists('hotels', 'tenant_id')
        ) {
            $tenantId = $this->tenantContext->currentUserTenantId($user);
            if ($tenantId <= 0) {
                return [];
            }
            $query->where('uhp.tenant_id', $tenantId)
                ->where('h.tenant_id', $tenantId)
                ->whereColumn('uhp.tenant_id', 'h.tenant_id');
        }

        if ($this->tableColumnExists('user_hotel_permissions', 'status')) {
            $query->whereIn('uhp.status', ['active', '1', 1]);
        }
        $this->applyPermissionExpiryScope($query, 'uhp');

        $viewColumn = $this->firstExistingPermissionColumn(['can_view', 'can_view_online_data']);
        if ($viewColumn !== '') {
            $query->where('uhp.' . $viewColumn, 1);
        }

        if ($capability !== null) {
            $capabilityColumn = $this->firstExistingPermissionColumn($this->permissionColumns($capability));
            if ($capabilityColumn !== '') {
                $query->where('uhp.' . $capabilityColumn, 1);
            }
        }

        return array_values(array_map('intval', $query->column('uhp.hotel_id')));
    }

    private function isHotelEnabled(int $hotelId): bool
    {
        $cacheKey = 'tenant:' . $hotelId;
        if (array_key_exists($cacheKey, $this->hotelEnabledCache)) {
            return $this->hotelEnabledCache[$cacheKey];
        }

        return $this->hotelEnabledCache[$cacheKey] = (bool)Hotel::where('id', $hotelId)
            ->where('status', Hotel::STATUS_ENABLED)
            ->find();
    }

    private function isHotelEnabledAcrossTenants(int $hotelId): bool
    {
        $cacheKey = 'all:' . $hotelId;
        if (array_key_exists($cacheKey, $this->hotelEnabledCache)) {
            return $this->hotelEnabledCache[$cacheKey];
        }

        return $this->hotelEnabledCache[$cacheKey] = (bool)Hotel::withoutTenantScope()
            ->where('id', $hotelId)
            ->where('status', Hotel::STATUS_ENABLED)
            ->find();
    }

    private function isOwnedHotel(User $user, int $hotelId): bool
    {
        $cacheKey = $this->userScopeCacheKey($user, 'owned:' . $hotelId);
        $cache = $this->userCacheBucket($user);
        if (array_key_exists($cacheKey, $cache['owned'])) {
            return $cache['owned'][$cacheKey];
        }

        $column = $this->hotelOwnershipColumn();
        if ($column === '') {
            $owned = false;
        } else {
            $owned = (bool)Hotel::where('id', $hotelId)
                ->where('status', Hotel::STATUS_ENABLED)
                ->where($column, (int)$user->id)
                ->find();
        }

        $cache['owned'][$cacheKey] = $owned;
        $this->storeUserCacheBucket($user, $cache);
        return $owned;
    }

    private function isPrimaryHotel(User $user, int $hotelId): bool
    {
        return $hotelId > 0 && (int)($user->hotel_id ?? 0) === $hotelId && $this->isHotelEnabled($hotelId);
    }

    private function hotelOwnershipColumn(): string
    {
        if ($this->tableColumnExists('hotels', 'owner_user_id')) {
            return 'owner_user_id';
        }

        if ($this->tableColumnExists('hotels', 'created_by')) {
            return 'created_by';
        }

        return '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function hotelPermissionRecord(User $user, int $hotelId): ?array
    {
        $userId = (int)($user->id ?? 0);
        $cacheKey = $this->userScopeCacheKey($user, 'permission-record:' . $hotelId);
        $cache = $this->userCacheBucket($user);
        if (array_key_exists($cacheKey, $cache['permissions'])) {
            return $cache['permissions'][$cacheKey];
        }

        if (!$this->tableColumnExists('user_hotel_permissions', 'hotel_id')) {
            $cache['permissions'][$cacheKey] = null;
            $this->storeUserCacheBucket($user, $cache);
            return null;
        }

        $query = Db::name('user_hotel_permissions')
            ->alias('uhp')
            ->join('hotels h', 'h.id = uhp.hotel_id')
            ->field('uhp.*')
            ->where('uhp.user_id', $userId)
            ->where('uhp.hotel_id', $hotelId);

        if (
            $this->tableColumnExists('user_hotel_permissions', 'tenant_id')
            && $this->tableColumnExists('hotels', 'tenant_id')
        ) {
            $tenantId = $this->tenantContext->currentUserTenantId($user);
            if ($tenantId <= 0) {
                $cache['permissions'][$cacheKey] = null;
                $this->storeUserCacheBucket($user, $cache);
                return null;
            }
            $query->where('uhp.tenant_id', $tenantId)
                ->where('h.tenant_id', $tenantId)
                ->whereColumn('uhp.tenant_id', 'h.tenant_id');
        }

        if ($this->tableColumnExists('user_hotel_permissions', 'status')) {
            $query->whereIn('uhp.status', ['active', '1', 1]);
        }
        $this->applyPermissionExpiryScope($query, 'uhp');

        $record = $query->find();
        $record = is_array($record) ? $record : null;
        $cache['permissions'][$cacheKey] = $record;
        $this->storeUserCacheBucket($user, $cache);
        return $record;
    }

    private function applyPermissionExpiryScope($query, string $alias = ''): void
    {
        if (!$this->tableColumnExists('user_hotel_permissions', 'expires_at')) {
            return;
        }

        $field = ($alias !== '' ? $alias . '.' : '') . 'expires_at';
        $now = date('Y-m-d H:i:s');
        $query->where(static function ($expiryQuery) use ($field, $now): void {
            $expiryQuery->whereNull($field)->whereOr($field, '>', $now);
        });
    }

    /**
     * @return array<int, string>
     */
    private function permissionColumns(string $capability): array
    {
        return match ($capability) {
            'hotel.view' => ['can_view'],
            'can_view_online_data', 'ota.view' => ['can_view_online_data', 'can_view'],
            'hotel.update' => ['can_edit'],
            'hotel.delete' => ['can_edit'],
            'ota.collect' => ['can_fetch_ota', 'can_fetch_online_data'],
            'ota.delete' => ['can_delete_ota', 'can_delete_online_data'],
            'ota.export' => ['can_export'],
            'report.view', 'can_view_report' => ['can_view_report', 'can_report'],
            'report.fill', 'can_fill_daily_report', 'can_fill_monthly_task' => ['can_fill_daily_report', 'can_fill_monthly_task', 'can_fill'],
            'report.update', 'can_edit_report' => ['can_edit_report', 'can_edit'],
            'report.delete', 'can_delete_report' => ['can_delete_report'],
            'report.export' => ['can_export'],
            'ai.view', 'ai.execute', 'can_use_ai_decision' => ['can_ai'],
            'operation.view', 'operation.execute' => ['can_operation'],
            'investment.view', 'investment.simulate', 'can_use_investment' => ['can_investment'],
            default => [$capability],
        };
    }

    private function ownerDefaultAllows(string $capability): bool
    {
        return in_array($capability, [
            'hotel.view',
            'hotel.update',
            'ota.view',
            'report.view',
            'can_view_online_data',
            'can_view_report',
        ], true);
    }

    /**
     * @param array<int, string> $columns
     */
    private function firstExistingPermissionColumn(array $columns): string
    {
        foreach ($columns as $column) {
            if ($this->tableColumnExists('user_hotel_permissions', $column)) {
                return $column;
            }
        }

        return '';
    }

    private function isVipUser(User $user): bool
    {
        return method_exists($user, 'isBetaUser') && $user->isBetaUser();
    }

    private function tableColumnExists(string $table, string $column): bool
    {
        $table = str_replace('`', '', $table);
        $column = str_replace(['`', "'"], '', $column);
        $cacheKey = $table . '.' . $column;

        if (array_key_exists($cacheKey, $this->tableColumnCache)) {
            return $this->tableColumnCache[$cacheKey];
        }

        try {
            $exists = $this->probeTableColumn($table, $column);
        } catch (\Throwable $e) {
            if ($this->isRequiredTenantColumn($table, $column)) {
                throw new RuntimeException(
                    "Required tenant column metadata unavailable: {$table}.{$column}",
                    0,
                    $e
                );
            }

            return $this->tableColumnCache[$cacheKey] = false;
        }

        if (!$exists && $this->isRequiredTenantColumn($table, $column)) {
            throw new RuntimeException("Required tenant column is missing: {$table}.{$column}");
        }

        return $this->tableColumnCache[$cacheKey] = $exists;
    }

    protected function probeTableColumn(string $table, string $column): bool
    {
        try {
            return !empty(Db::query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'"));
        } catch (\Throwable $showError) {
            try {
                $rows = Db::query("PRAGMA table_info(`{$table}`)");
            } catch (\Throwable $pragmaError) {
                throw new RuntimeException(
                    "Unable to inspect {$table}.{$column}",
                    0,
                    $pragmaError
                );
            }

            foreach ($rows as $row) {
                if (($row['name'] ?? '') === $column) {
                    return true;
                }
            }

            return false;
        }
    }

    private function isRequiredTenantColumn(string $table, string $column): bool
    {
        return $column === 'tenant_id'
            && in_array($table, ['hotels', 'user_hotel_permissions'], true);
    }

    private function userScopeCacheKey(User $user, ?string $capability): string
    {
        return implode(':', [
            (int)($user->id ?? 0),
            (int)($user->tenant_id ?? 0),
            (int)($user->hotel_id ?? 0),
            (int)($user->role_id ?? 0),
            $capability ?? '*',
        ]);
    }

    /**
     * @return array{
     *     accessible: array<string, array<int, int>>,
     *     permissions: array<string, array<string, mixed>|null>,
     *     owned: array<string, bool>
     * }
     */
    private function userCacheBucket(User $user): array
    {
        $this->userCache ??= new \WeakMap();
        return $this->userCache[$user] ?? [
            'accessible' => [],
            'permissions' => [],
            'owned' => [],
        ];
    }

    /**
     * @param array{
     *     accessible: array<string, array<int, int>>,
     *     permissions: array<string, array<string, mixed>|null>,
     *     owned: array<string, bool>
     * } $cache
     */
    private function storeUserCacheBucket(User $user, array $cache): void
    {
        $this->userCache ??= new \WeakMap();
        $this->userCache[$user] = $cache;
    }
}
