<?php
declare(strict_types=1);

namespace app\service;

use app\model\OperationLog;
use app\model\User;
use DomainException;
use RuntimeException;
use think\facade\Db;

final class UserDefaultHotelService
{
    /** @return array<string, mixed> */
    public function setDefaultHotel(User $user, int $hotelId): array
    {
        $userId = (int)($user->id ?? 0);
        if ($userId <= 0 || $hotelId <= 0) {
            throw new DomainException('请选择有效的主门店');
        }
        if (!$this->tableHasColumn('users', 'default_hotel_id')) {
            throw new RuntimeException('默认主门店字段尚未迁移，请先完成数据库升级');
        }

        $result = Db::transaction(function () use ($userId, $hotelId): array {
            // Match hotel lifecycle mutations: hotel -> user -> tenant -> grant.
            $lockedHotel = Db::name('hotels')->where('id', $hotelId)->lock(true)->find();
            $lockedUserRow = Db::name('users')->where('id', $userId)->lock(true)->find();
            if (!is_array($lockedUserRow)) {
                throw new RuntimeException('当前用户不存在，请重新登录');
            }

            $lockedUser = User::with(['role'])->find($userId);
            if (!$lockedUser instanceof User) {
                throw new RuntimeException('当前用户不存在，请重新登录');
            }

            if ((int)($lockedUserRow['status'] ?? User::STATUS_DISABLED) !== User::STATUS_ENABLED) {
                throw new DomainException('当前账号已停用，不能设置主门店');
            }

            $userTenantId = (int)($lockedUserRow['tenant_id'] ?? 0);
            if (!$lockedUser->isSuperAdmin()) {
                if ($userTenantId <= 0) {
                    throw new DomainException('当前账号缺少有效租户，不能设置主门店');
                }
                $tenant = Db::name('tenants')->where('id', $userTenantId)->lock(true)->find();
                if (!is_array($tenant) || (int)($tenant['status'] ?? 0) !== 1) {
                    throw new DomainException('当前租户不存在或已停用，不能设置主门店');
                }
            }

            $hotel = $lockedHotel;
            if (!is_array($hotel) || (int)($hotel['status'] ?? 0) !== 1) {
                throw new DomainException('只能选择当前账号有权限且营业中的门店');
            }

            if (!$lockedUser->isSuperAdmin()) {
                $hotelTenantId = (int)($hotel['tenant_id'] ?? 0);
                if ($userTenantId <= 0 || $hotelTenantId <= 0 || $userTenantId !== $hotelTenantId) {
                    throw new DomainException('主门店必须属于当前账号租户');
                }
                if (!$this->lockedUserCanAccessHotel($lockedUserRow, $hotel, $userId, $hotelId, $userTenantId)) {
                    throw new DomainException('只能选择当前账号有权限且营业中的门店');
                }
            }

            $previousHotelId = (int)($lockedUserRow['default_hotel_id'] ?? 0);
            if ($previousHotelId === $hotelId) {
                return $this->result($hotel, $hotelId, $previousHotelId, false);
            }

            $updated = Db::name('users')->where('id', $userId)->update([
                'default_hotel_id' => $hotelId,
            ]);
            if ($updated !== 1) {
                throw new RuntimeException('默认主门店保存失败，事务已回滚');
            }

            OperationLog::record(
                'auth',
                'set_default_hotel',
                '设置默认主门店: ' . (string)($hotel['name'] ?? $hotelId),
                $userId,
                $hotelId,
                null,
                [
                    'previous_hotel_id' => $previousHotelId > 0 ? $previousHotelId : null,
                    'default_hotel_id' => $hotelId,
                ]
            );

            return $this->result($hotel, $hotelId, $previousHotelId, true);
        });

        $user->default_hotel_id = $hotelId;
        return $result;
    }

    /** @param array<string, mixed> $lockedUserRow @param array<string, mixed> $hotel */
    private function lockedUserCanAccessHotel(
        array $lockedUserRow,
        array $hotel,
        int $userId,
        int $hotelId,
        int $tenantId
    ): bool {
        if ((int)($lockedUserRow['hotel_id'] ?? 0) === $hotelId) {
            return true;
        }
        if ((int)($hotel['owner_user_id'] ?? 0) === $userId || (int)($hotel['created_by'] ?? 0) === $userId) {
            return true;
        }
        if (!$this->tableHasColumn('user_hotel_permissions', 'hotel_id')) {
            return false;
        }

        $query = Db::name('user_hotel_permissions')
            ->where('user_id', $userId)
            ->where('hotel_id', $hotelId)
            ->lock(true);
        $rows = $query->select()->toArray();
        foreach ($rows as $row) {
            if ($this->isActiveGrant((array)$row, $tenantId)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $row */
    private function isActiveGrant(array $row, int $tenantId): bool
    {
        if ($this->tableHasColumn('user_hotel_permissions', 'tenant_id')
            && (int)($row['tenant_id'] ?? 0) !== $tenantId) {
            return false;
        }
        if ($this->tableHasColumn('user_hotel_permissions', 'status')) {
            $status = strtolower(trim((string)($row['status'] ?? '')));
            if (!in_array($status, ['active', '1'], true)) {
                return false;
            }
        }
        if ($this->tableHasColumn('user_hotel_permissions', 'expires_at')) {
            $expiresAt = trim((string)($row['expires_at'] ?? ''));
            if ($expiresAt !== '') {
                $expiry = strtotime($expiresAt);
                if ($expiry === false || $expiry <= time()) {
                    return false;
                }
            }
        }

        foreach (['can_view', 'can_view_online_data'] as $column) {
            if ($this->tableHasColumn('user_hotel_permissions', $column)) {
                return (int)($row[$column] ?? 0) === 1;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $hotel @return array<string, mixed> */
    private function result(array $hotel, int $hotelId, int $previousHotelId, bool $changed): array
    {
        return [
            'default_hotel_id' => $hotelId,
            'previous_default_hotel_id' => $previousHotelId > 0 ? $previousHotelId : null,
            'changed' => $changed,
            'hotel' => [
                'id' => $hotelId,
                'name' => (string)($hotel['name'] ?? ''),
                'status' => (int)($hotel['status'] ?? 0),
                'tenant_id' => isset($hotel['tenant_id']) ? (int)$hotel['tenant_id'] : null,
            ],
        ];
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $table) || !preg_match('/^[A-Za-z0-9_]+$/D', $column)) {
            return false;
        }
        try {
            Db::query("SELECT `{$column}` FROM `{$table}` LIMIT 1");
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
