<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * Resolves the one explicitly marked WeCom test-group binding for a hotel.
 *
 * The resolver never selects webhook material. A regular active robot is not
 * enough: every binding must carry the dedicated test scope.
 */
final class ManualNotificationTestTargetService
{
    public const TEST_SCOPE = 'operating_target_test';

    /** @return array<string, mixed>|null */
    public function resolve(int $hotelId, int $robotId = 0, string $robotName = ''): ?array
    {
        $hotelId = max(0, $hotelId);
        $robotId = max(0, $robotId);
        $robotName = trim($robotName);
        if ($hotelId <= 0 || !$this->tableExists()) {
            return null;
        }

        $scopeColumnExists = $this->scopeColumnExists();
        if (!$scopeColumnExists) {
            return null;
        }

        $query = Db::name('competitor_wechat_robot')
            ->where('store_id', $hotelId)
            ->where('notification_scope', self::TEST_SCOPE)
            ->where('status', 1);
        if ($robotId > 0) {
            $query->where('id', $robotId);
        }
        if ($robotName !== '') {
            $query->where('name', $robotName);
        }
        $rows = $query
            ->field('id,store_id,notification_scope,name,status')
            ->order('id', 'asc')
            ->limit(2)
            ->select()
            ->toArray();
        return count($rows) === 1
            ? $this->present($rows[0], 'verified_test_binding')
            : null;
    }

    private function tableExists(): bool
    {
        try {
            Db::name('competitor_wechat_robot')->field('id')->limit(1)->select();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function scopeColumnExists(): bool
    {
        try {
            Db::name('competitor_wechat_robot')
                ->field('notification_scope')
                ->limit(1)
                ->select();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function present(array $row, string $bindingStatus): array
    {
        return [
            'hotel_id' => (int)($row['store_id'] ?? 0),
            'robot_id' => (int)($row['id'] ?? 0),
            'robot_name' => trim((string)($row['name'] ?? '')),
            'binding_status' => $bindingStatus,
            'notification_scope' => (string)($row['notification_scope'] ?? ''),
            'formal_group_delivery_allowed' => false,
        ];
    }
}
