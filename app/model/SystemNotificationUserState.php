<?php
declare(strict_types=1);

namespace app\model;

use think\Model;
use Throwable;

class SystemNotificationUserState extends Model
{
    protected $name = 'system_notification_user_states';

    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    protected $type = [
        'id' => 'integer',
        'notification_id' => 'integer',
        'user_id' => 'integer',
        'is_read' => 'integer',
        'is_cleared' => 'integer',
    ];

    public static function tableReady(): bool
    {
        try {
            self::where('id', 0)->count();
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @param array<int, int> $notificationIds
     * @return array<int, array<string, mixed>>
     */
    public static function statesByNotificationId(array $notificationIds, int $userId): array
    {
        $notificationIds = self::normalizeIds($notificationIds);
        if ($userId <= 0 || empty($notificationIds) || !self::tableReady()) {
            return [];
        }

        $rows = self::where('user_id', $userId)
            ->whereIn('notification_id', $notificationIds)
            ->select()
            ->toArray();

        $states = [];
        foreach ($rows as $row) {
            $states[(int)($row['notification_id'] ?? 0)] = $row;
        }

        return $states;
    }

    /**
     * @param array<int, int> $notificationIds
     */
    public static function markReadForUser(array $notificationIds, int $userId): int
    {
        return self::upsertForUser($notificationIds, $userId, [
            'is_read' => 1,
            'read_time' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param array<int, int> $notificationIds
     */
    public static function markClearedForUser(array $notificationIds, int $userId): int
    {
        return self::upsertForUser($notificationIds, $userId, [
            'is_read' => 1,
            'is_cleared' => 1,
            'read_time' => date('Y-m-d H:i:s'),
            'clear_time' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param array<int, int> $notificationIds
     * @param array<string, mixed> $fields
     */
    private static function upsertForUser(array $notificationIds, int $userId, array $fields): int
    {
        $notificationIds = self::normalizeIds($notificationIds);
        if ($userId <= 0 || empty($notificationIds) || !self::tableReady()) {
            return 0;
        }

        $updated = 0;
        foreach ($notificationIds as $notificationId) {
            $state = self::where('notification_id', $notificationId)
                ->where('user_id', $userId)
                ->find();
            $payload = array_merge([
                'notification_id' => $notificationId,
                'user_id' => $userId,
                'is_read' => 0,
                'is_cleared' => 0,
            ], $fields);

            if ($state) {
                $state->save($payload);
            } else {
                self::create($payload);
            }
            $updated++;
        }

        return $updated;
    }

    /**
     * @param array<int, int|string> $ids
     * @return array<int, int>
     */
    private static function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $id): bool => $id > 0
        )));
    }
}
