<?php
declare(strict_types=1);

namespace app\controller;

use app\model\SystemNotification;
use app\model\SystemNotificationUserState;
use think\Response;

class SystemNotificationController extends Base
{
    private const MAX_PAGE_SIZE = 50;

    public function index(): Response
    {
        if (!SystemNotification::tableReady()) {
            return $this->success([
                'list' => [],
                'strong_reminders' => [],
                'strong_reminder_count' => 0,
                'total' => 0,
                'unread_count' => 0,
                'poll_interval_ms' => 120000,
                'data_status' => 'missing_table',
                'data_gaps' => [[
                    'code' => 'system_notifications_table_missing',
                    'message' => 'system_notifications table does not exist, run database migration first',
                ]],
            ]);
        }
        if (!SystemNotificationUserState::tableReady()) {
            return $this->success([
                'list' => [],
                'strong_reminders' => [],
                'strong_reminder_count' => 0,
                'total' => 0,
                'unread_count' => 0,
                'poll_interval_ms' => 120000,
                'data_status' => 'missing_table',
                'data_gaps' => [[
                    'code' => 'system_notification_user_states_table_missing',
                    'message' => 'system_notification_user_states table does not exist, run database migration first',
                ]],
            ]);
        }

        $page = max(1, (int)$this->request->get('page', 1));
        $pageSize = min(self::MAX_PAGE_SIZE, max(1, (int)$this->request->get('page_size', 20)));
        $category = trim((string)$this->request->get('category', ''));
        $hotelId = (int)$this->request->get('hotel_id', 0);

        $userId = (int)($this->currentUser->id ?? 0);
        $query = SystemNotification::with(['hotel', 'actor'])
            ->alias('notification')
            ->field('notification.*')
            ->leftJoin(
                'system_notification_user_states notification_state',
                'notification_state.notification_id = notification.id AND notification_state.user_id = ' . $userId
            )
            ->where('notification.is_cleared', 0)
            ->whereRaw('(notification_state.is_cleared IS NULL OR notification_state.is_cleared <> 1)');
        $this->applyVisibleScope($query, 'notification.');
        if ($category !== '') {
            $query->where('notification.category', $category);
        }
        if ($hotelId > 0 && $this->currentUser && $this->currentUser->isSuperAdmin()) {
            $query->where('notification.hotel_id', $hotelId);
        }

        $total = (int)(clone $query)->count('DISTINCT notification.id');
        $unreadCount = (int)(clone $query)
            ->whereRaw('(notification_state.is_read IS NULL OR notification_state.is_read <> 1)')
            ->count('DISTINCT notification.id');
        if (SystemNotification::recipientTargetingReady()) {
            $query->orderRaw(
                "CASE WHEN notification.category = 'ota_auth_required'"
                . " AND notification.recipient_user_id = {$userId} THEN 0"
                . " WHEN notification.category = 'ota_auth_required' THEN 1 ELSE 2 END"
            );
        } else {
            $query->orderRaw("CASE WHEN notification.category = 'ota_auth_required' THEN 0 ELSE 1 END");
        }
        $pageRows = $query
            ->order('notification.update_time', 'desc')
            ->order('notification.create_time', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();
        $strongRows = $this->directStrongReminderRows($userId);
        $ids = array_values(array_unique(array_merge(
            array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $pageRows),
            array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $strongRows)
        )));
        $states = SystemNotificationUserState::statesByNotificationId($ids, $userId);

        return $this->success([
            'list' => array_map(fn(array $row): array => $this->serializeNotification($row, $states), $pageRows),
            'strong_reminders' => array_map(
                fn(array $row): array => $this->serializeNotification($row, $states),
                $strongRows
            ),
            'strong_reminder_count' => count($strongRows),
            'total' => $total,
            'unread_count' => $unreadCount,
            'poll_interval_ms' => 120000,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function directStrongReminderRows(int $userId): array
    {
        if ($userId <= 0 || !SystemNotification::recipientTargetingReady()) {
            return [];
        }

        $query = SystemNotification::with(['hotel', 'actor'])
            ->alias('notification')
            ->field('notification.*')
            ->leftJoin(
                'system_notification_user_states notification_state',
                'notification_state.notification_id = notification.id AND notification_state.user_id = ' . $userId
            )
            ->where('notification.is_cleared', 0)
            ->where('notification.recipient_user_id', $userId)
            ->where('notification.category', 'ota_auth_required')
            ->where('notification.source_module', 'ota_failure_notifier')
            ->whereRaw('(notification_state.is_cleared IS NULL OR notification_state.is_cleared <> 1)');
        $this->applyVisibleScope($query, 'notification.');

        return $query
            ->order('notification.update_time', 'desc')
            ->order('notification.create_time', 'desc')
            ->select()
            ->toArray();
    }

    public function markRead(): Response
    {
        if (!SystemNotification::tableReady()) {
            return $this->error('system_notifications table does not exist, run database migration first', 500, [
                'data_status' => 'missing_table',
                'data_gaps' => [[
                    'code' => 'system_notifications_table_missing',
                    'message' => 'system_notifications table does not exist, run database migration first',
                ]],
            ]);
        }
        if (!SystemNotificationUserState::tableReady()) {
            return $this->error('system_notification_user_states table does not exist, run database migration first', 500, [
                'data_status' => 'missing_table',
                'data_gaps' => [[
                    'code' => 'system_notification_user_states_table_missing',
                    'message' => 'system_notification_user_states table does not exist, run database migration first',
                ]],
            ]);
        }

        $ids = $this->inputIds();
        if (empty($ids)) {
            return $this->success(['updated_count' => 0], '已读状态已更新');
        }

        $query = SystemNotification::whereIn('id', $ids);
        $this->applyVisibleScope($query);
        $visibleIds = array_map('intval', $query->column('id'));
        if (empty($visibleIds)) {
            return $this->success(['updated_count' => 0], '已读状态已更新');
        }

        $count = SystemNotificationUserState::markReadForUser($visibleIds, (int)$this->currentUser->id);

        return $this->success(['updated_count' => (int)$count], '已读状态已更新');
    }

    public function markAllRead(): Response
    {
        if (!SystemNotification::tableReady()) {
            return $this->error('system_notifications table does not exist, run database migration first', 500, [
                'data_status' => 'missing_table',
                'data_gaps' => [[
                    'code' => 'system_notifications_table_missing',
                    'message' => 'system_notifications table does not exist, run database migration first',
                ]],
            ]);
        }
        if (!SystemNotificationUserState::tableReady()) {
            return $this->error('system_notification_user_states table does not exist, run database migration first', 500, [
                'data_status' => 'missing_table',
                'data_gaps' => [[
                    'code' => 'system_notification_user_states_table_missing',
                    'message' => 'system_notification_user_states table does not exist, run database migration first',
                ]],
            ]);
        }

        $visibleIds = $this->visibleNotificationIdsForCurrentUser([
            '(notification_state.is_read IS NULL OR notification_state.is_read <> 1)',
        ]);
        $count = SystemNotificationUserState::markReadForUser($visibleIds, (int)$this->currentUser->id);

        return $this->success(['updated_count' => (int)$count], '全部已读');
    }

    public function clear(): Response
    {
        if (!SystemNotification::tableReady()) {
            return $this->error('system_notifications table does not exist, run database migration first', 500, [
                'data_status' => 'missing_table',
                'data_gaps' => [[
                    'code' => 'system_notifications_table_missing',
                    'message' => 'system_notifications table does not exist, run database migration first',
                ]],
            ]);
        }
        if (!SystemNotificationUserState::tableReady()) {
            return $this->error('system_notification_user_states table does not exist, run database migration first', 500, [
                'data_status' => 'missing_table',
                'data_gaps' => [[
                    'code' => 'system_notification_user_states_table_missing',
                    'message' => 'system_notification_user_states table does not exist, run database migration first',
                ]],
            ]);
        }

        $ids = $this->inputIds();
        $visibleIds = $this->visibleNotificationIdsForCurrentUser([], $ids);
        $clearableIds = [];
        $blockedCount = 0;
        if (!empty($visibleIds)) {
            $rows = SystemNotification::whereIn('id', $visibleIds)
                ->field('id,category,source_module')
                ->select()
                ->toArray();
            foreach ($rows as $row) {
                if ($this->requiresResolution($row)) {
                    $blockedCount++;
                    continue;
                }
                $clearableIds[] = (int)($row['id'] ?? 0);
            }
        }
        $count = SystemNotificationUserState::markClearedForUser($clearableIds, (int)$this->currentUser->id);

        return $this->success([
            'updated_count' => (int)$count,
            'blocked_count' => $blockedCount,
        ], $blockedCount > 0 ? '登录失效强提醒将在重新登录验证成功后自动解除' : '通知已清空');
    }

    /**
     * @param array<int, string> $extraStateWhere
     * @param array<int, int> $ids
     * @return array<int, int>
     */
    private function visibleNotificationIdsForCurrentUser(array $extraStateWhere = [], array $ids = []): array
    {
        $userId = (int)($this->currentUser->id ?? 0);
        if ($userId <= 0) {
            return [];
        }

        $query = SystemNotification::alias('notification')
            ->leftJoin(
                'system_notification_user_states notification_state',
                'notification_state.notification_id = notification.id AND notification_state.user_id = ' . $userId
            )
            ->where('notification.is_cleared', 0)
            ->whereRaw('(notification_state.is_cleared IS NULL OR notification_state.is_cleared <> 1)');
        $this->applyVisibleScope($query, 'notification.');
        if (!empty($ids)) {
            $query->whereIn('notification.id', $ids);
        }
        foreach ($extraStateWhere as $where) {
            $query->whereRaw($where);
        }

        return array_values(array_unique(array_map('intval', $query->column('notification.id'))));
    }

    private function applyVisibleScope($query, string $tablePrefix = ''): void
    {
        if (!$this->currentUser) {
            $query->whereRaw('1 = 0');
            return;
        }

        if ($this->currentUser->isSuperAdmin()) {
            return;
        }

        $userId = (int)$this->currentUser->id;
        if (SystemNotification::recipientTargetingReady()) {
            $query->whereRaw(
                '(' . $this->qualifiedNotificationField('recipient_user_id', $tablePrefix) . ' IS NULL'
                . ' OR ' . $this->qualifiedNotificationField('recipient_user_id', $tablePrefix) . ' = ' . $userId . ')'
            );
        }
        $permittedHotelIds = array_values(array_filter(
            array_map('intval', $this->currentUser->getPermittedHotelIds()),
            static fn(int $id): bool => $id > 0
        ));

        $hotelClause = '';
        if (!empty($permittedHotelIds)) {
            $hotelClause = $this->qualifiedNotificationField('hotel_id', $tablePrefix) . ' IN (' . implode(',', $permittedHotelIds) . ') OR ';
        }

        $query->whereRaw(
            '(' . $hotelClause
            . '(' . $this->qualifiedNotificationField('hotel_id', $tablePrefix) . ' IS NULL AND ('
            . $this->qualifiedNotificationField('user_id', $tablePrefix) . ' = ' . $userId
            . ' OR ' . $this->qualifiedNotificationField('user_id', $tablePrefix) . ' IS NULL)))'
        );
    }

    private function qualifiedNotificationField(string $field, string $tablePrefix = ''): string
    {
        $field = trim($field, '`');
        $alias = trim($tablePrefix, '.`');
        if ($alias === '') {
            return '`' . $field . '`';
        }

        return '`' . $alias . '`.`' . $field . '`';
    }

    private function inputIds(): array
    {
        $input = $this->requestData();
        $raw = $input['ids'] ?? $input['id'] ?? [];
        if (is_string($raw)) {
            $raw = preg_split('/[,\s]+/', $raw) ?: [];
        }
        if (!is_array($raw)) {
            $raw = [$raw];
        }

        return array_values(array_unique(array_filter(
            array_map('intval', $raw),
            static fn(int $id): bool => $id > 0
        )));
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $states
     */
    private function isNotificationReadForCurrentUser(array $row, array $states): bool
    {
        $state = $states[(int)($row['id'] ?? 0)] ?? null;
        if ($state === null) {
            return false;
        }

        return (int)($state['is_read'] ?? 0) === 1;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $states
     */
    private function serializeNotification(array $row, array $states = []): array
    {
        $payload = [];
        if (!empty($row['action_payload'])) {
            $decoded = json_decode((string)$row['action_payload'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $updatedAt = (string)($row['update_time'] ?? '');
        $createdAt = (string)($row['create_time'] ?? '');
        $requiresResolution = $this->requiresResolution($row);
        $recipientUserId = (int)($row['recipient_user_id'] ?? 0);
        $currentUserId = (int)($this->currentUser->id ?? 0);

        return [
            'id' => (int)($row['id'] ?? 0),
            'notification_id' => 'system-notification-' . (int)($row['id'] ?? 0),
            'hotel_id' => $row['hotel_id'] ?? null,
            'hotel_name' => $row['hotel']['name'] ?? '',
            'platform' => $row['platform'] ?? 'ota',
            'category' => $row['category'] ?? 'general',
            'category_label' => $this->categoryLabel((string)($row['category'] ?? 'general')),
            'severity' => $row['severity'] ?? 'info',
            'title' => $row['title'] ?? '系统通知',
            'detail' => $row['message'] ?? '',
            'message' => $row['message'] ?? '',
            'is_read' => $this->isNotificationReadForCurrentUser($row, $states),
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
            'time_label' => $updatedAt !== '' ? $updatedAt : $createdAt,
            'action_type' => $row['action_type'] ?? '',
            'action_label' => $payload['action_label'] ?? $this->defaultActionLabel((string)($row['category'] ?? 'general')),
            'target_page' => $payload['target_page'] ?? 'online-data',
            'target_tab' => $payload['target_tab'] ?? 'data-health',
            'action_payload' => $payload,
            'source_module' => $row['source_module'] ?? '',
            'reason_code' => $payload['reason_code'] ?? '',
            'requires_resolution' => $requiresResolution,
            'reminder_level' => $requiresResolution ? 'strong' : ($payload['reminder_level'] ?? 'normal'),
            'is_direct_recipient' => $recipientUserId > 0 && $recipientUserId === $currentUserId,
            'resolution_rule' => $requiresResolution ? 'verified_same_platform_session_or_capture' : '',
        ];
    }

    /** @param array<string, mixed> $row */
    private function requiresResolution(array $row): bool
    {
        return (string)($row['category'] ?? '') === 'ota_auth_required'
            && (string)($row['source_module'] ?? '') === 'ota_failure_notifier';
    }

    private function categoryLabel(string $category): string
    {
        return [
            'capture_success' => '采集完成',
            'capture_failed' => '采集失败',
            'ota_auth_required' => '登录失效强提醒',
            'cookie_alert' => 'Cookie 告警',
            'data_quality' => '数据健康',
            'risk_action' => '风险动作',
        ][$category] ?? '系统通知';
    }

    private function defaultActionLabel(string $category): string
    {
        return [
            'capture_success' => '查看数据',
            'capture_failed' => '查看原因',
            'ota_auth_required' => '立即重新登录',
            'cookie_alert' => '更新授权',
        ][$category] ?? '查看处理';
    }
}
