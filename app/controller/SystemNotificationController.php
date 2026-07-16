<?php
declare(strict_types=1);

namespace app\controller;

use app\model\SystemNotification;
use app\model\SystemNotificationUserState;
use think\facade\Db;
use think\Response;
use Throwable;

class SystemNotificationController extends Base
{
    private const MAX_PAGE_SIZE = 50;

    /** @var array<string, array<string, mixed>> */
    private array $otaAuthorizationSourceCache = [];

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
        $authorizationSource = $requiresResolution
            ? $this->authorizationSourcePresentation($row, $payload)
            : [];
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
            'authorization_source_label' => $authorizationSource['label'] ?? '',
            'authorization_source_type' => $authorizationSource['type'] ?? 'unknown',
            'authorization_source_state' => $authorizationSource['state'] ?? 'missing',
            'authorization_source_note' => $authorizationSource['note'] ?? '',
            'data_source_id' => $authorizationSource['data_source_id'] ?? null,
        ];
    }

    /**
     * Old strong reminders did not persist the exact Profile/Cookie source. Prefer an explicit
     * safe label from the notification payload; otherwise present current candidates truthfully.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $payload
     * @return array{label:string,type:string,state:string,note:string,data_source_id:?int}
     */
    private function authorizationSourcePresentation(array $row, array $payload): array
    {
        $explicitLabel = $this->safeAuthorizationSourceLabel($payload['authorization_source_label'] ?? null);
        if ($explicitLabel !== '') {
            $explicitState = strtolower(trim((string)($payload['authorization_source_state'] ?? 'exact')));
            if (!in_array($explicitState, ['exact', 'current_binding', 'ambiguous', 'missing'], true)) {
                $explicitState = 'exact';
            }
            return [
                'label' => $explicitLabel,
                'type' => $this->normalizeAuthorizationSourceType($payload['authorization_source_type'] ?? ''),
                'state' => $explicitState,
                'note' => $this->safeAuthorizationSourceLabel($payload['authorization_source_note'] ?? null)
                    ?: '本次失败已记录到该授权来源。',
                'data_source_id' => $this->positiveNotificationId($payload['data_source_id'] ?? null),
            ];
        }

        $hotelId = $this->positiveNotificationId($row['hotel_id'] ?? null);
        $platform = strtolower(trim((string)($row['platform'] ?? '')));
        if ($hotelId === null || !in_array($platform, ['ctrip', 'meituan'], true)) {
            return $this->missingAuthorizationSourcePresentation();
        }

        $cacheKey = $hotelId . ':' . $platform;
        if (isset($this->otaAuthorizationSourceCache[$cacheKey])) {
            return $this->otaAuthorizationSourceCache[$cacheKey];
        }

        try {
            $rows = Db::name('platform_data_sources')
                ->field('id,name,ingestion_method,status,last_sync_status,last_error,config_json,update_time')
                ->where('system_hotel_id', $hotelId)
                ->where('platform', $platform)
                ->where('enabled', 1)
                ->where('status', '<>', 'disabled')
                ->whereIn('ingestion_method', ['browser_profile', 'profile_browser', 'manual_cookie_api', 'cookie_api'])
                ->order('update_time', 'desc')
                ->order('id', 'desc')
                ->select()
                ->toArray();
        } catch (Throwable) {
            return $this->otaAuthorizationSourceCache[$cacheKey] = $this->missingAuthorizationSourcePresentation();
        }

        $candidates = [];
        foreach ($rows as $source) {
            if (!is_array($source)) {
                continue;
            }
            $sourceId = $this->positiveNotificationId($source['id'] ?? null);
            $type = $this->normalizeAuthorizationSourceType($source['ingestion_method'] ?? '');
            $typeLabel = $type === 'profile' ? '浏览器 Profile' : 'Cookie/API 授权';
            $name = $this->safeAuthorizationSourceLabel($source['name'] ?? null);
            $display = $name !== '' ? $name : $typeLabel;
            if ($sourceId !== null) {
                $display .= ' · 数据源 #' . $sourceId;
            }
            $candidates[] = [
                'label' => $display,
                'type' => $type,
                'invalid' => $this->authorizationSourceLooksInvalid($source),
                'data_source_id' => $sourceId,
            ];
        }

        if ($candidates === []) {
            return $this->otaAuthorizationSourceCache[$cacheKey] = $this->missingAuthorizationSourcePresentation();
        }

        $invalidCandidates = array_values(array_filter(
            $candidates,
            static fn(array $candidate): bool => (bool)($candidate['invalid'] ?? false)
        ));
        if (count($invalidCandidates) === 1) {
            $candidate = $invalidCandidates[0];
            return $this->otaAuthorizationSourceCache[$cacheKey] = [
                'label' => (string)$candidate['label'],
                'type' => (string)$candidate['type'],
                'state' => 'exact',
                'note' => '当前数据源状态已定位到该授权来源。',
                'data_source_id' => $candidate['data_source_id'],
            ];
        }

        if (count($candidates) === 1) {
            $candidate = $candidates[0];
            return $this->otaAuthorizationSourceCache[$cacheKey] = [
                'label' => (string)$candidate['label'],
                'type' => (string)$candidate['type'],
                'state' => 'current_binding',
                'note' => '当前仅找到这一条授权来源，请在授权页确认是否为本次失效项。',
                'data_source_id' => $candidate['data_source_id'],
            ];
        }

        $ambiguous = $invalidCandidates !== [] ? $invalidCandidates : $candidates;
        $candidateLabels = array_slice(array_column($ambiguous, 'label'), 0, 2);
        $moreCount = max(0, count($ambiguous) - count($candidateLabels));
        $note = '现有提醒未记录唯一来源；候选：' . implode('、', $candidateLabels);
        if ($moreCount > 0) {
            $note .= '，另有 ' . $moreCount . ' 项';
        }
        return $this->otaAuthorizationSourceCache[$cacheKey] = [
            'label' => count($ambiguous) . ' 个候选授权来源',
            'type' => 'mixed',
            'state' => 'ambiguous',
            'note' => $note . '。',
            'data_source_id' => null,
        ];
    }

    /** @param array<string, mixed> $source */
    private function authorizationSourceLooksInvalid(array $source): bool
    {
        $parts = [
            $source['status'] ?? '',
            $source['last_sync_status'] ?? '',
            $source['last_error'] ?? '',
        ];
        $config = json_decode((string)($source['config_json'] ?? ''), true);
        if (is_array($config)) {
            foreach ([
                'profile_status',
                'login_status',
                'auth_status',
                'current_session_status',
                'login_verification_status',
                'credential_status',
            ] as $field) {
                if (is_scalar($config[$field] ?? null)) {
                    $parts[] = (string)$config[$field];
                }
            }
        }
        $text = strtolower(implode(' ', array_map('strval', $parts)));
        return preg_match(
            '/login[_ -]?(expired|required|invalid)|session[_ -]?(expired|required|invalid|unverified)|credential[_ -]?(expired|revoked|invalid)|authorization[_ -]?(expired|invalid)|待重新登录|登录.{0,8}(失效|过期|异常)|授权.{0,8}(失效|过期|异常)/iu',
            $text
        ) === 1;
    }

    /** @return array{label:string,type:string,state:string,note:string,data_source_id:?int} */
    private function missingAuthorizationSourcePresentation(): array
    {
        return [
            'label' => '暂未识别具体授权来源',
            'type' => 'unknown',
            'state' => 'missing',
            'note' => '请在授权页核对对应 Profile 或 Cookie/API 配置。',
            'data_source_id' => null,
        ];
    }

    private function normalizeAuthorizationSourceType(mixed $value): string
    {
        return match (strtolower(trim((string)$value))) {
            'browser_profile', 'profile_browser', 'profile' => 'profile',
            'manual_cookie_api', 'cookie_api', 'cookie', 'credential' => 'cookie_api',
            'mixed' => 'mixed',
            default => 'unknown',
        };
    }

    private function positiveNotificationId(mixed $value): ?int
    {
        if (!is_scalar($value) || !preg_match('/^\d+$/D', trim((string)$value))) {
            return null;
        }
        $id = (int)$value;
        return $id > 0 ? $id : null;
    }

    private function safeAuthorizationSourceLabel(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $label = trim((string)$value);
        if ($label === ''
            || preg_match('/(?:[A-Za-z]:[\\\\\/]|[\\\\\/](?:Users|home|storage)[\\\\\/])/iu', $label) === 1
        ) {
            return '';
        }
        if (preg_match('/(?:cookie|token|authorization|spidertoken|password|secret)\s*[:=]/iu', $label) === 1
            || preg_match('/\b[A-Za-z0-9_-]{32,}\b/u', $label) === 1
        ) {
            return '授权来源（敏感值已隐藏）';
        }
        $label = preg_replace(
            '/(cookie|token|authorization|spidertoken|password|secret)\s*[:=]\s*[^;\s,]+/iu',
            '$1=****',
            $label
        ) ?? '';
        $label = preg_replace('/(1[3-9]\d)\d{4}(\d{4})/u', '$1****$2', $label) ?? '';
        $label = preg_replace('/\b\d{8,}\b/u', '[编号已隐藏]', $label) ?? '';
        $label = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $label) ?? '';
        return mb_substr(trim($label), 0, 140);
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
