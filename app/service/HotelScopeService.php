<?php
declare(strict_types=1);

namespace app\service;

use app\model\Hotel;
use app\model\User;
use think\facade\Db;

class HotelScopeService
{
    /**
     * @return array<int, int>
     */
    public function accessibleHotelIds(User $user, ?string $capability = null): array
    {
        if ($user->isSuperAdmin()) {
            return $this->enabledHotelIds();
        }

        return $this->ownedOrGrantedHotelIds($user, $capability);
    }

    public function canAccessHotel(User $user, int $hotelId, ?string $capability = null): bool
    {
        if ($hotelId <= 0) {
            return false;
        }

        return in_array($hotelId, $this->accessibleHotelIds($user, $capability), true);
    }

    public function hotelPermissionAllows(User $user, int $hotelId, string $capability): bool
    {
        if ($hotelId <= 0) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return $this->isHotelEnabled($hotelId);
        }

        if (!$this->canAccessHotel($user, $hotelId, $capability)) {
            return false;
        }

        $record = $this->hotelPermissionRecord((int)$user->id, $hotelId);
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
        if ($user->isSuperAdmin()) {
            return [
                'type' => 'all',
                'hotel_ids' => $this->enabledHotelIds(),
                'source_field' => 'admin',
            ];
        }

        return [
            'type' => 'owned_or_granted',
            'hotel_ids' => $this->ownedOrGrantedHotelIds($user),
            'source_field' => trim($this->hotelOwnershipColumn() . '+user_hotel_permissions', '+'),
        ];
    }

    /**
     * @return array<int, int>
     */
    private function enabledHotelIds(): array
    {
        return array_values(array_map('intval', Hotel::where('status', Hotel::STATUS_ENABLED)->column('id')));
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
        return array_values(array_unique(array_filter(array_merge(
            $this->primaryHotelIds($user),
            $this->ownedHotelIds($user),
            $this->grantedHotelIds($user, $capability)
        ), static fn(int $hotelId): bool => $hotelId > 0)));
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

        if ($this->tableColumnExists('user_hotel_permissions', 'status')) {
            $query->whereIn('uhp.status', ['active', '1', 1]);
        }

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
        return (bool)Hotel::where('id', $hotelId)
            ->where('status', Hotel::STATUS_ENABLED)
            ->find();
    }

    private function isOwnedHotel(User $user, int $hotelId): bool
    {
        $column = $this->hotelOwnershipColumn();
        if ($column === '') {
            return false;
        }

        return (bool)Hotel::where('id', $hotelId)
            ->where('status', Hotel::STATUS_ENABLED)
            ->where($column, (int)$user->id)
            ->find();
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
    private function hotelPermissionRecord(int $userId, int $hotelId): ?array
    {
        if (!$this->tableColumnExists('user_hotel_permissions', 'hotel_id')) {
            return null;
        }

        $query = Db::name('user_hotel_permissions')
            ->where('user_id', $userId)
            ->where('hotel_id', $hotelId);

        if ($this->tableColumnExists('user_hotel_permissions', 'status')) {
            $query->whereIn('status', ['active', '1', 1]);
        }

        $record = $query->find();
        return is_array($record) ? $record : null;
    }

    /**
     * @return array<int, string>
     */
    private function permissionColumns(string $capability): array
    {
        return match ($capability) {
            'hotel.view', 'can_view_online_data' => ['can_view', 'can_view_online_data'],
            'hotel.update' => ['can_edit'],
            'hotel.delete' => ['can_edit'],
            'ota.view' => ['can_view', 'can_view_online_data'],
            'ota.collect' => ['can_fetch_ota', 'can_fetch_online_data'],
            'ota.delete' => ['can_delete_ota', 'can_delete_online_data'],
            'ota.export' => ['can_export'],
            'report.view', 'can_view_report' => ['can_report', 'can_view_report'],
            'report.fill', 'can_fill_daily_report', 'can_fill_monthly_task' => ['can_fill', 'can_fill_daily_report', 'can_fill_monthly_task'],
            'report.update', 'can_edit_report' => ['can_edit', 'can_edit_report'],
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

        try {
            return !empty(Db::query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'"));
        } catch (\Throwable $e) {
            try {
                $rows = Db::query("PRAGMA table_info(`{$table}`)");
            } catch (\Throwable $ignored) {
                return false;
            }

            foreach ($rows as $row) {
                if (($row['name'] ?? '') === $column) {
                    return true;
                }
            }

            return false;
        }
    }
}
