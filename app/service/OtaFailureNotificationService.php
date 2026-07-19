<?php
declare(strict_types=1);

namespace app\service;

use app\model\Role;
use app\model\SystemNotification;
use app\model\SystemNotificationUserState;
use app\model\User;
use think\facade\Db;
use think\facade\Log;
use Throwable;

final class OtaFailureNotificationService
{
    private const PLATFORMS = ['ctrip', 'meituan'];

    private const REASONS = [
        'missing_config',
        'source_missing',
        'session_unverified',
        'login_expired',
        'zero_rows',
        'collection_failed',
    ];

    private const AUTH_RESOLUTION_REASONS = [
        'session_unverified',
        'login_expired',
    ];

    /** @var array<string, array<string, bool>> */
    private array $columnCache = [];

    /**
     * Convert an OTA collection result into one targeted, idempotent notification per failed platform.
     *
     * @param array<string, mixed> $event
     * @return array{status:string,deliveries:array<int,array<string,mixed>>,resolutions:array<int,array<string,mixed>>}
     */
    public function recordCollectionOutcome(array $event): array
    {
        $hotelId = $this->positiveInt($event['hotel_id'] ?? $event['system_hotel_id'] ?? null);
        if ($hotelId === null) {
            return ['status' => 'hotel_missing', 'deliveries' => [], 'resolutions' => []];
        }

        $resolutions = [];
        foreach ($this->verifiedSuccessfulPlatforms($event) as $platform) {
            $resolutions[] = $this->resolveAuthReminder($hotelId, $platform);
        }

        $failures = $this->failureItems($event, $hotelId);
        if ($failures === []) {
            return ['status' => 'no_failure', 'deliveries' => [], 'resolutions' => $resolutions];
        }

        $deliveries = [];
        foreach ($failures as $failure) {
            $deliveries[] = $this->recordPlatformFailure($hotelId, $failure, $event);
        }

        $statuses = array_values(array_unique(array_column($deliveries, 'status')));
        return [
            'status' => count($statuses) === 1 ? (string)$statuses[0] : 'partial',
            'deliveries' => $deliveries,
            'resolutions' => $resolutions,
        ];
    }

    /** @param array<string, mixed> $event @return array<int, string> */
    private function verifiedSuccessfulPlatforms(array $event): array
    {
        $platforms = [];
        $platformResults = is_array($event['platform_results'] ?? null) ? $event['platform_results'] : [];
        foreach ($platformResults as $key => $result) {
            if (!is_array($result) || !$this->isVerifiedSuccess($result)) {
                continue;
            }
            $platform = $this->normalizePlatform(is_string($key) ? $key : ($result['platform'] ?? ''));
            if ($platform !== 'ota') {
                $platforms[$platform] = true;
            }
        }

        if ($this->isVerifiedSuccess($event)) {
            foreach ($this->normalizePlatformList($event['successful_platforms'] ?? []) as $platform) {
                $platforms[$platform] = true;
            }
        }

        if ($platforms === [] && $this->isVerifiedSuccess($event)) {
            $platform = $this->normalizePlatform($event['platform'] ?? 'ota');
            if ($platform !== 'ota') {
                $platforms[$platform] = true;
            }
        }

        return array_keys($platforms);
    }

    /** @param array<string, mixed> $event */
    private function isVerifiedSuccess(array $event): bool
    {
        if (!$this->truthy($event['success'] ?? false)) {
            return false;
        }

        return (int)($event['saved_count'] ?? 0) > 0
            || $this->truthy($event['auth_verified'] ?? false)
            || $this->truthy($event['session_verified'] ?? false);
    }

    /** @return array{platform:string,resolved_count:int,status:string} */
    private function resolveAuthReminder(int $hotelId, string $platform): array
    {
        $resolvedCount = 0;
        try {
            if (SystemNotification::tableReady()) {
                $resolvedCount = SystemNotification::where('hotel_id', $hotelId)
                    ->where('platform', $platform)
                    ->where('source_module', 'ota_failure_notifier')
                    ->where('category', 'ota_auth_required')
                    ->where('is_cleared', 0)
                    ->update([
                        'is_cleared' => 1,
                        'clear_time' => date('Y-m-d H:i:s'),
                    ]);
            }
        } catch (Throwable $e) {
            Log::warning('OTA strong reminder resolution failed', [
                'hotel_id' => $hotelId,
                'platform' => $platform,
                'exception_type' => get_debug_type($e),
            ]);
            return [
                'platform' => $platform,
                'resolved_count' => 0,
                'status' => 'resolution_failed',
            ];
        }

        return [
            'platform' => $platform,
            'resolved_count' => (int)$resolvedCount,
            'status' => $resolvedCount > 0 ? 'resolved' : 'nothing_to_resolve',
        ];
    }

    /**
     * @param array<string, mixed> $event
     * @return array<int, array{platform:string,reason_code:string}>
     */
    private function failureItems(array $event, int $hotelId): array
    {
        $items = [];
        $platformResults = is_array($event['platform_results'] ?? null) ? $event['platform_results'] : [];
        foreach ($platformResults as $key => $result) {
            if (!is_array($result)) {
                continue;
            }
            $platform = $this->normalizePlatform(is_string($key) ? $key : ($result['platform'] ?? ''));
            if ($platform === 'ota') {
                continue;
            }
            $success = $this->isVerifiedSuccess($result);
            if ($success || $this->isNonFailureSkip($result)) {
                continue;
            }
            $items[] = array_merge([
                'platform' => $platform,
                'reason_code' => $this->failureReason($result),
            ], $this->authorizationSourceFields($result));
        }

        if ($items === []) {
            $success = $this->isVerifiedSuccess($event);
            $platforms = $this->normalizePlatformList($event['failed_platforms'] ?? []);
            if (($success && $platforms === []) || $this->isNonFailureSkip($event)) {
                return [];
            }

            if ($platforms === []) {
                $platform = $this->normalizePlatform($event['platform'] ?? 'ota');
                $platforms = $platform === 'ota'
                    ? $this->configuredPlatformsForHotel($hotelId)
                    : [$platform];
            }
            foreach ($platforms as $platform) {
                $items[] = array_merge([
                    'platform' => $platform,
                    'reason_code' => $this->failureReason($event),
                ], $this->authorizationSourceFields($event));
            }
        }

        $deduplicated = [];
        foreach ($items as $item) {
            $key = $item['platform'] . ':' . $item['reason_code'];
            $deduplicated[$key] = $item;
        }
        return array_values($deduplicated);
    }

    /** @param array<string, mixed> $failure @param array<string, mixed> $event */
    private function recordPlatformFailure(int $hotelId, array $failure, array $event): array
    {
        $platform = $this->normalizePlatform($failure['platform'] ?? 'ota');
        $reasonCode = $this->normalizeReason($failure['reason_code'] ?? 'collection_failed');
        $requiresResolution = in_array($reasonCode, self::AUTH_RESOLUTION_REASONS, true);
        $actorUserId = $this->positiveInt($event['actor_user_id'] ?? $event['user_id'] ?? null);
        $dataDate = $this->safeDataDate($event['data_date'] ?? null);
        $recipient = $this->resolveRecipient($hotelId, $platform);
        $authorizationSource = array_replace(
            $this->authorizationSourceFields($event),
            $this->authorizationSourceFields($failure)
        );

        if ($recipient === null) {
            $this->auditDeliveryGap(
                $hotelId,
                $platform,
                $reasonCode,
                $dataDate,
                $actorUserId,
                'recipient_missing'
            );
            return [
                'status' => 'recipient_missing',
                'hotel_id' => $hotelId,
                'platform' => $platform,
                'reason_code' => $reasonCode,
            ];
        }

        if (!SystemNotification::recipientTargetingReady()) {
            $this->auditDeliveryGap(
                $hotelId,
                $platform,
                $reasonCode,
                $dataDate,
                $actorUserId,
                'recipient_schema_missing'
            );
            return [
                'status' => 'recipient_schema_missing',
                'hotel_id' => $hotelId,
                'platform' => $platform,
                'reason_code' => $reasonCode,
            ];
        }

        try {
            $notification = SystemNotification::recordEvent([
                'hotel_id' => $hotelId,
                'user_id' => $actorUserId,
                'recipient_user_id' => $recipient['user_id'],
                'platform' => $platform,
                'category' => $requiresResolution ? 'ota_auth_required' : 'capture_failed',
                'severity' => 'error',
                'title' => $this->notificationTitle($platform, $reasonCode),
                'message' => $this->notificationMessage($platform, $reasonCode, $dataDate),
                'action_type' => 'fetch',
                'action_payload' => array_merge([
                    'target_page' => 'online-data',
                    'target_tab' => 'data-health',
                    'action_label' => $this->actionLabel($reasonCode),
                    'data_date' => $dataDate,
                    'reason_code' => $reasonCode,
                    'requires_resolution' => $requiresResolution ? '1' : '0',
                    'reminder_level' => $requiresResolution ? 'strong' : 'normal',
                    'resolution_rule' => $requiresResolution ? 'verified_same_platform_session_or_capture' : '',
                ], $authorizationSource),
                'source_module' => 'ota_failure_notifier',
                'source_key' => implode(':', [
                    'ota_collection_failure',
                    $hotelId,
                    $platform,
                    $reasonCode,
                ]),
            ]);
            SystemNotificationUserState::resetForNotificationUser(
                (int)$notification->id,
                (int)$recipient['user_id']
            );
        } catch (Throwable $e) {
            $this->auditDeliveryGap(
                $hotelId,
                $platform,
                $reasonCode,
                $dataDate,
                $actorUserId,
                'notification_write_failed'
            );
            Log::warning('OTA failure notification write failed', [
                'hotel_id' => $hotelId,
                'platform' => $platform,
                'reason_code' => $reasonCode,
                'exception_type' => get_debug_type($e),
            ]);
            return [
                'status' => 'notification_write_failed',
                'hotel_id' => $hotelId,
                'platform' => $platform,
                'reason_code' => $reasonCode,
            ];
        }

        return [
            'status' => 'notified',
            'hotel_id' => $hotelId,
            'platform' => $platform,
            'reason_code' => $reasonCode,
            'recipient_user_id' => (int)$recipient['user_id'],
            'recipient_source' => (string)$recipient['source'],
            'notification_id' => (int)$notification->id,
        ];
    }

    /** @return array{user_id:int,source:string}|null */
    private function resolveRecipient(int $hotelId, string $platform): ?array
    {
        $hotel = $this->hotelRow($hotelId);
        if ($hotel === null) {
            return null;
        }

        $candidates = [];
        foreach ($this->configSubmitterCandidates($hotelId, $platform) as $candidate) {
            $candidates[] = $candidate;
        }
        foreach ($this->dataSourceSubmitterCandidates($hotelId, $platform) as $candidate) {
            $candidates[] = $candidate;
        }
        foreach ($this->credentialSubmitterCandidates($hotelId, $platform) as $candidate) {
            $candidates[] = $candidate;
        }
        $candidates[] = ['user_id' => $hotel['created_by'] ?? null, 'source' => 'hotel.created_by'];

        $seen = [];
        foreach ($candidates as $candidate) {
            $userId = $this->positiveInt($candidate['user_id'] ?? null);
            if ($userId === null || isset($seen[$userId])) {
                continue;
            }
            $seen[$userId] = true;
            if ($this->isActiveRecipientInHotelScope($userId, $hotel)) {
                return [
                    'user_id' => $userId,
                    'source' => (string)($candidate['source'] ?? 'unknown'),
                ];
            }
        }

        return null;
    }

    /** @return array<int, array{user_id:mixed,source:string}> */
    private function configSubmitterCandidates(int $hotelId, string $platform): array
    {
        if (!in_array($platform, self::PLATFORMS, true) || !$this->tableHasColumn('system_configs', 'config_key')) {
            return [];
        }
        try {
            $raw = Db::name('system_configs')
                ->where('config_key', $platform . '_config_list')
                ->value('config_value');
            $list = json_decode((string)$raw, true);
        } catch (Throwable) {
            return [];
        }
        if (!is_array($list)) {
            return [];
        }

        $rows = [];
        foreach ($list as $item) {
            if (!is_array($item)
                || !$this->isCurrentConfig($item)
                || $this->configHotelId($item) !== $hotelId
            ) {
                continue;
            }
            $rows[] = $item;
        }
        usort($rows, static fn(array $left, array $right): int => strcmp(
            (string)($right['update_time'] ?? $right['updated_at'] ?? $right['created_at'] ?? ''),
            (string)($left['update_time'] ?? $left['updated_at'] ?? $left['created_at'] ?? '')
        ));

        $candidates = [];
        foreach ($rows as $row) {
            foreach (['user_id', 'created_by', 'submitted_by'] as $field) {
                $candidates[] = [
                    'user_id' => $row[$field] ?? null,
                    'source' => 'system_configs.' . $field,
                ];
            }
        }
        return $candidates;
    }

    /** @return array<int, array{user_id:mixed,source:string}> */
    private function dataSourceSubmitterCandidates(int $hotelId, string $platform): array
    {
        if (!in_array($platform, self::PLATFORMS, true)
            || !$this->tableHasColumn('platform_data_sources', 'system_hotel_id')
        ) {
            return [];
        }
        $columns = $this->tableColumns('platform_data_sources');
        $fields = array_values(array_filter(
            ['created_by', 'user_id', 'update_time', 'id'],
            static fn(string $field): bool => isset($columns[$field])
        ));
        if ($fields === []) {
            return [];
        }

        try {
            $query = Db::name('platform_data_sources')
                ->field(implode(',', $fields))
                ->where('system_hotel_id', $hotelId)
                ->where('platform', $platform);
            if (isset($columns['enabled'])) {
                $query->where('enabled', 1);
            }
            if (isset($columns['update_time'])) {
                $query->order('update_time', 'desc');
            }
            $rows = $query->select()->toArray();
        } catch (Throwable) {
            return [];
        }

        $candidates = [];
        foreach ($rows as $row) {
            foreach (['created_by', 'user_id'] as $field) {
                $candidates[] = [
                    'user_id' => $row[$field] ?? null,
                    'source' => 'platform_data_sources.' . $field,
                ];
            }
        }
        return $candidates;
    }

    /** @return array<int, array{user_id:mixed,source:string}> */
    private function credentialSubmitterCandidates(int $hotelId, string $platform): array
    {
        if (!in_array($platform, self::PLATFORMS, true)
            || !$this->tableHasColumn('ota_credentials', 'system_hotel_id')
            || !$this->tableHasColumn('ota_credentials', 'created_by')
        ) {
            return [];
        }
        try {
            $query = Db::name('ota_credentials')
                ->field('created_by')
                ->where('system_hotel_id', $hotelId)
                ->where('platform', $platform);
            if ($this->tableHasColumn('ota_credentials', 'update_time')) {
                $query->order('update_time', 'desc');
            }
            $rows = $query->select()->toArray();
        } catch (Throwable) {
            return [];
        }

        return array_map(static fn(array $row): array => [
            'user_id' => $row['created_by'] ?? null,
            'source' => 'ota_credentials.created_by',
        ], $rows);
    }

    /** @return array<string, mixed>|null */
    private function hotelRow(int $hotelId): ?array
    {
        if (!$this->tableHasColumn('hotels', 'id')) {
            return null;
        }
        $columns = $this->tableColumns('hotels');
        $fields = array_values(array_filter(
            ['id', 'tenant_id', 'created_by'],
            static fn(string $field): bool => isset($columns[$field])
        ));
        try {
            $row = Db::name('hotels')->field(implode(',', $fields))->where('id', $hotelId)->find();
            return is_array($row) ? $row : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $hotel */
    private function isActiveRecipientInHotelScope(int $userId, array $hotel): bool
    {
        if (!$this->tableHasColumn('users', 'id')) {
            return false;
        }
        $columns = $this->tableColumns('users');
        $fields = array_values(array_filter(
            ['id', 'status', 'tenant_id', 'role_id'],
            static fn(string $field): bool => isset($columns[$field])
        ));
        try {
            $user = Db::name('users')->field(implode(',', $fields))->where('id', $userId)->find();
        } catch (Throwable) {
            return false;
        }
        if (!is_array($user) || (isset($columns['status']) && (int)($user['status'] ?? 0) !== 1)) {
            return false;
        }

        $hotelTenantId = $this->positiveInt($hotel['tenant_id'] ?? null);
        $userTenantId = $this->positiveInt($user['tenant_id'] ?? null);
        $isSuperAdmin = isset($columns['role_id']) && (int)($user['role_id'] ?? 0) === Role::SUPER_ADMIN;
        $tenantMatches = $isSuperAdmin
            || $hotelTenantId === null
            || $userTenantId === null
            || $hotelTenantId === $userTenantId;
        if (!$tenantMatches) {
            return false;
        }

        try {
            $userModel = User::find($userId);
            $hotelId = (int)($hotel['id'] ?? 0);
            if (!$userModel || $hotelId <= 0) {
                return false;
            }
            return in_array($hotelId, array_map('intval', $userModel->getPermittedHotelIds()), true);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<int, string> */
    private function configuredPlatformsForHotel(int $hotelId): array
    {
        $platforms = [];
        foreach (self::PLATFORMS as $platform) {
            if ($this->configSubmitterCandidates($hotelId, $platform) !== []
                || $this->dataSourceSubmitterCandidates($hotelId, $platform) !== []
                || $this->credentialSubmitterCandidates($hotelId, $platform) !== []
            ) {
                $platforms[] = $platform;
            }
        }
        return $platforms !== [] ? $platforms : ['ota'];
    }

    /** @param array<string, mixed> $item */
    private function isCurrentConfig(array $item): bool
    {
        if (trim((string)($item['deleted_at'] ?? '')) !== '') {
            return false;
        }
        return !in_array(
            strtolower(trim((string)($item['config_status'] ?? 'active'))),
            ['deleted', 'history', 'superseded', 'archived'],
            true
        );
    }

    /** @param array<string, mixed> $item */
    private function configHotelId(array $item): ?int
    {
        return $this->positiveInt($item['system_hotel_id'] ?? $item['hotel_id'] ?? null);
    }

    /** @param array<string, mixed> $event @return array<string, mixed> */
    private function authorizationSourceFields(array $event): array
    {
        $label = '';
        foreach ([
            'authorization_source_label',
            'data_source_name',
            'source_name',
            'config_name',
            'account_alias',
            'profile_alias',
        ] as $field) {
            $label = $this->safeAuthorizationSourceLabel($event[$field] ?? null);
            if ($label !== '') {
                break;
            }
        }

        $dataSourceId = $this->positiveInt($event['data_source_id'] ?? $event['source_id'] ?? null);
        if ($label === '' && $dataSourceId !== null) {
            $label = '数据源 #' . $dataSourceId;
        }
        if ($label === '') {
            return [];
        }

        $rawType = strtolower(trim((string)(
            $event['authorization_source_type']
            ?? $event['ingestion_method']
            ?? $event['source_method']
            ?? $event['collection_mode']
            ?? ''
        )));
        $type = match ($rawType) {
            'browser_profile', 'profile_browser', 'profile' => 'profile',
            'manual_cookie_api', 'cookie_api', 'cookie', 'credential' => 'cookie_api',
            default => 'authorization',
        };

        $fields = [
            'authorization_source_label' => $label,
            'authorization_source_type' => $type,
            'authorization_source_state' => 'exact',
            'authorization_source_note' => '本次失败已记录到该授权来源。',
        ];
        if ($dataSourceId !== null) {
            $fields['data_source_id'] = $dataSourceId;
        }
        return $fields;
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
        return mb_substr(trim($label), 0, 100);
    }

    /** @param array<string, mixed> $event */
    private function failureReason(array $event): string
    {
        $explicit = strtolower(trim((string)($event['reason_code'] ?? $event['failure_reason'] ?? '')));
        $aliases = [
            'browser_profile_source_missing' => 'source_missing',
            'profile_source_missing' => 'source_missing',
            'migration_required' => 'source_missing',
            'current_session_probe_missing' => 'session_unverified',
            'same_source_profile_flow_ready' => 'session_unverified',
            'session_proof_missing' => 'session_unverified',
            'target_date_rows_missing' => 'zero_rows',
            'not_loaded' => 'zero_rows',
            'no_valid_data' => 'zero_rows',
            'no_rows_parsed' => 'zero_rows',
            'waiting_config' => 'missing_config',
            'credential_not_ready' => 'missing_config',
            'capture_failed' => 'collection_failed',
            'failed' => 'collection_failed',
        ];
        if (isset($aliases[$explicit])) {
            return $aliases[$explicit];
        }
        if (in_array($explicit, self::REASONS, true)) {
            return $explicit;
        }

        $textParts = [
            $event['message'] ?? '',
            $event['status'] ?? '',
            $event['status_code'] ?? '',
            $event['next_action'] ?? '',
        ];
        foreach ((array)($event['modules'] ?? []) as $module) {
            if (is_array($module)) {
                $textParts[] = $module['message'] ?? '';
                $textParts[] = $module['status_code'] ?? '';
            }
        }
        $text = strtolower(implode(' ', array_map(static fn($value): string => is_scalar($value) ? (string)$value : '', $textParts)));

        if (preg_match('/未配置|waiting[_ -]?config|missing[_ -]?(config|credential)|credential[_ -](not[_ -]?ready|unavailable)|partner.{0,12}(missing|缺)|poi.{0,12}(missing|缺)/iu', $text)) {
            return 'missing_config';
        }
        if (preg_match('/browser[_ -]?profile[_ -]?source[_ -]?missing|profile[_ -]?source[_ -]?missing|migration[_ -]?required|采集源.{0,12}(缺失|未建立|迁移)/iu', $text)) {
            return 'source_missing';
        }
        if (preg_match('/current[_ -]?session[_ -]?probe|same[_ -]?source[_ -]?profile[_ -]?flow|session[_ -]?(proof|unverified)|会话.{0,12}(未验证|待验证)/iu', $text)) {
            return 'session_unverified';
        }
        if (preg_match('/session[_ -]?expired|login[_ -]?(expired|invalid|required)|unauthorized|forbidden|cookie|authorization|重新登录|登录.{0,8}(失效|过期|异常)|授权.{0,8}(失效|过期|异常)/iu', $text)) {
            return 'login_expired';
        }
        if (preg_match('/no[_ -]?(valid[_ -]?)?(data|rows)|zero[_ -]?rows|0\s*rows|0\s*入库|未获取到有效数据|未写入有效数据|空数据/iu', $text)) {
            return 'zero_rows';
        }

        return 'collection_failed';
    }

    /** @param array<string, mixed> $event */
    private function isNonFailureSkip(array $event): bool
    {
        if (!$this->truthy($event['skipped'] ?? false)) {
            return false;
        }
        $text = strtolower(trim((string)($event['message'] ?? $event['status'] ?? '')));
        if (preg_match('/未配置|missing|waiting[_ -]?config|login|session|cookie|授权|登录/u', $text)) {
            return false;
        }
        return preg_match('/当前策略|not applicable|不适用|disabled|已关闭|skipped[_ -]?locked|正在运行|already running/iu', $text) === 1;
    }

    private function normalizeReason(mixed $reason): string
    {
        $reason = strtolower(trim((string)$reason));
        return in_array($reason, self::REASONS, true) ? $reason : 'collection_failed';
    }

    private function normalizePlatform(mixed $platform): string
    {
        $platform = strtolower(trim((string)$platform));
        return in_array($platform, self::PLATFORMS, true) ? $platform : 'ota';
    }

    /** @return array<int, string> */
    private function normalizePlatformList(mixed $platforms): array
    {
        if (is_string($platforms)) {
            $platforms = preg_split('/[,\s]+/', $platforms) ?: [];
        }
        if (!is_array($platforms)) {
            return [];
        }
        $normalized = [];
        foreach ($platforms as $platform) {
            $platform = $this->normalizePlatform($platform);
            if ($platform !== 'ota') {
                $normalized[$platform] = true;
            }
        }
        return array_keys($normalized);
    }

    private function safeDataDate(mixed $value): string
    {
        $date = trim((string)$value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1
            || preg_match('/^\d{4}-\d{2}-\d{2}\s+至\s+\d{4}-\d{2}-\d{2}$/uD', $date) === 1
        ) {
            return $date;
        }
        return date('Y-m-d');
    }

    private function platformLabel(string $platform): string
    {
        return ['ctrip' => '携程', 'meituan' => '美团'][$platform] ?? 'OTA';
    }

    private function notificationTitle(string $platform, string $reason): string
    {
        $suffix = [
            'missing_config' => '采集配置待补齐',
            'source_missing' => '采集源待建立',
            'session_unverified' => '登录会话待验证',
            'login_expired' => '登录授权已失效',
            'zero_rows' => '采集未写入有效数据',
            'collection_failed' => '采集失败',
        ][$reason] ?? '采集失败';
        return $this->platformLabel($platform) . $suffix;
    }

    private function notificationMessage(string $platform, string $reason, string $dataDate): string
    {
        $label = $this->platformLabel($platform);
        $detail = [
            'missing_config' => "该门店尚未完成{$label}采集配置，请补齐平台门店绑定与采集配置后重新验证。",
            'source_missing' => "该门店缺少可执行的{$label}浏览器采集源，或现有配置仍需迁移，请完成数据源绑定后重试。",
            'session_unverified' => "该门店的{$label}当前登录会话尚未完成同源验证，请由提交人重新登录并运行会话检测。",
            'login_expired' => "该门店的{$label}登录授权已失效，请由提交人重新登录或更新授权后再采集。",
            'zero_rows' => "该门店本次{$label}采集未写入有效数据，请检查目标日期、登录状态和门店绑定后重试。",
            'collection_failed' => "该门店本次{$label}采集失败，请在数据健康页查看失败阶段并重新采集。",
        ][$reason] ?? "该门店本次{$label}采集失败，请在数据健康页查看失败阶段并重新采集。";
        return "数据日期 {$dataDate}。{$detail}";
    }

    private function actionLabel(string $reason): string
    {
        return [
            'missing_config' => '补齐配置',
            'source_missing' => '建立采集源',
            'session_unverified' => '重新登录并验证',
            'login_expired' => '立即重新登录',
            'zero_rows' => '查看原因',
            'collection_failed' => '查看原因',
        ][$reason] ?? '查看原因';
    }

    private function auditDeliveryGap(
        int $hotelId,
        string $platform,
        string $reasonCode,
        string $dataDate,
        ?int $actorUserId,
        string $deliveryStatus
    ): void {
        $tenantId = $hotelId;
        try {
            $hotelColumns = $this->tableColumns('hotels');
            if (isset($hotelColumns['tenant_id'])) {
                $resolvedTenantId = (int)Db::name('hotels')->where('id', $hotelId)->value('tenant_id');
                if ($resolvedTenantId > 0) {
                    $tenantId = $resolvedTenantId;
                }
            }
        } catch (Throwable) {
            // Keep the system hotel id as a legacy-schema fallback.
        }

        $extra = json_encode([
            'audit_schema_version' => 1,
            'audit_type' => 'acquisition',
            'outcome' => 'failed',
            'actor_user_id' => $actorUserId,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'delivery_status' => $deliveryStatus,
            'platform' => $platform,
            'reason_code' => $reasonCode,
            'data_date' => $dataDate,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $columns = $this->tableColumns('operation_logs');
            if ($columns !== []) {
                $data = [
                    'tenant_id' => $tenantId,
                    'user_id' => $actorUserId,
                    'hotel_id' => $hotelId,
                    'module' => 'online_data',
                    'action' => 'ota_failure_notification_' . $deliveryStatus,
                    'description' => 'OTA采集失败通知未送达，已记录明确投递状态',
                    'error_info' => 'delivery_status:' . $deliveryStatus,
                    'extra_data' => $extra,
                    'create_time' => date('Y-m-d H:i:s'),
                ];
                Db::name('operation_logs')->insert(array_intersect_key($data, $columns));
            }
        } catch (Throwable $e) {
            Log::warning('OTA failure notification audit write failed', [
                'hotel_id' => $hotelId,
                'platform' => $platform,
                'reason_code' => $reasonCode,
                'delivery_status' => $deliveryStatus,
                'exception_type' => get_debug_type($e),
            ]);
            return;
        }

        Log::warning('OTA failure notification delivery gap', [
            'hotel_id' => $hotelId,
            'platform' => $platform,
            'reason_code' => $reasonCode,
            'delivery_status' => $deliveryStatus,
        ]);
    }

    /** @return array<string, bool> */
    private function tableColumns(string $table): array
    {
        $allowed = ['hotels', 'users', 'system_configs', 'platform_data_sources', 'ota_credentials', 'operation_logs'];
        if (!in_array($table, $allowed, true)) {
            return [];
        }
        if (isset($this->columnCache[$table])) {
            return $this->columnCache[$table];
        }

        $columns = [];
        try {
            foreach (Db::query("SHOW COLUMNS FROM `{$table}`") as $row) {
                $name = strtolower((string)($row['Field'] ?? $row['field'] ?? ''));
                if ($name !== '') {
                    $columns[$name] = true;
                }
            }
        } catch (Throwable) {
            try {
                foreach (Db::query("PRAGMA table_info(`{$table}`)") as $row) {
                    $name = strtolower((string)($row['name'] ?? ''));
                    if ($name !== '') {
                        $columns[$name] = true;
                    }
                }
            } catch (Throwable) {
                $columns = [];
            }
        }

        return $this->columnCache[$table] = $columns;
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        return isset($this->tableColumns($table)[strtolower($column)]);
    }

    private function positiveInt(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }
        $value = (int)$value;
        return $value > 0 ? $value : null;
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value !== 0;
        }
        return is_string($value)
            ? in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on', 'success'], true)
            : !empty($value);
    }
}
