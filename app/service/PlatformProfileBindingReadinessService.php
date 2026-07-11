<?php
declare(strict_types=1);

namespace app\service;

final class PlatformProfileBindingReadinessService
{
    public static function buildContract(string $platform, int $hotelId, array $config, ?array $source, string $statusCode, bool $profileExists, string $profileKey): array
    {
        $platform = strtolower(trim($platform));
        [$otaStoreId, $otaStoreIdSource] = self::otaStoreIdFromConfig($platform, $config);
        [$profileId, $profileIdSource] = self::profileIdFromConfig($config, $profileKey);

        $ingestionMethod = strtolower(trim((string)($source['ingestion_method'] ?? '')));
        $dataSourceId = isset($source['id']) ? (int)$source['id'] : null;
        $manualLoginVerified = self::truthy($config['manual_login_state_verified'] ?? null);
        $lastLoginVerifiedAt = self::firstString($config, [
            'last_login_verified_at',
            'lastLoginVerifiedAt',
            'login_verified_at',
            'loginVerifiedAt',
            'last_verified_at',
            'lastVerifiedAt',
        ]);
        $historicalLoginMetadataPresent = $manualLoginVerified || $lastLoginVerifiedAt !== '';
        $currentSessionVerified = $statusCode === 'logged_in';

        $missing = [];
        if ($hotelId <= 0) {
            $missing[] = 'system_hotel_id';
        }
        if (!in_array($platform, ['ctrip', 'meituan'], true)) {
            $missing[] = 'platform';
        }
        if ($dataSourceId === null || $dataSourceId <= 0) {
            $missing[] = 'data_source_id';
        }
        if (!in_array($ingestionMethod, ['browser_profile', 'profile_browser'], true)) {
            $missing[] = 'browser_profile_data_source';
        }
        if ($otaStoreId === '') {
            $missing[] = 'ota_store_id';
        }
        if ($profileId === '') {
            $missing[] = 'profile_id';
        }
        if (!$profileExists) {
            $missing[] = 'profile_exists';
        }
        if (!$currentSessionVerified) {
            $missing[] = 'current_session_verified';
        }

        $isComplete = $missing === [];

        return [
            'status' => $isComplete ? 'complete' : 'incomplete',
            'is_complete' => $isComplete,
            'missing_requirements' => $missing,
            'system_hotel_id' => $hotelId,
            'platform' => $platform,
            'data_source_id' => $dataSourceId,
            'ingestion_method' => $ingestionMethod,
            'is_browser_profile_source' => in_array($ingestionMethod, ['browser_profile', 'profile_browser'], true),
            'ota_store_id' => $otaStoreId,
            'ota_store_id_source' => $otaStoreIdSource,
            'profile_id' => $profileId,
            'profile_id_source' => $profileIdSource,
            'profile_binding_key' => self::firstString($config, ['profile_binding_key', 'profileBindingKey']) ?: $profileKey,
            'profile_reuse_scope' => (string)($config['profile_reuse_scope'] ?? $config['profileReuseScope'] ?? ''),
            'profile_daily_reuse_enabled' => self::truthy($config['profile_daily_reuse_enabled'] ?? $config['profileDailyReuseEnabled'] ?? null),
            'profile_exists' => $profileExists,
            'profile_status' => $statusCode,
            'current_session_verified' => $currentSessionVerified,
            'historical_login_metadata_present' => $historicalLoginMetadataPresent,
            'manual_login_state_verified' => $manualLoginVerified,
            'last_login_verified_at' => $lastLoginVerifiedAt,
            'last_capture_time' => (string)($source['last_sync_time'] ?? $source['update_time'] ?? ''),
        ];
    }

    public static function buildChecks(string $platform, int $hotelId, array $config, ?array $source, string $statusCode, bool $profileExists, string $profileKey): array
    {
        $isCtrip = $platform === 'ctrip';
        $sourceHotelId = (int)($source['system_hotel_id'] ?? $source['hotel_id'] ?? 0);
        $configHotelCandidate = trim((string)($config['system_hotel_id'] ?? ''));
        if ($configHotelCandidate === '') {
            $configHotelCandidate = trim((string)($config['hotel_id'] ?? ''));
        }
        $configHotelId = ctype_digit($configHotelCandidate) ? (int)$configHotelCandidate : 0;

        $hotelBindingStatus = 'ok';
        $hotelBindingDetail = '已绑定到宿析OS酒店 #' . $hotelId;
        $hotelBindingAction = '可进入平台身份校验';
        if ($hotelId <= 0) {
            $hotelBindingStatus = 'missing';
            $hotelBindingDetail = '当前未选择宿析OS酒店';
            $hotelBindingAction = '先选择系统酒店';
            $hotelBindingActionMeta = ['select_system_hotel', '选择系统酒店', 'platform-sources'];
        } elseif ($sourceHotelId > 0 && $sourceHotelId !== $hotelId) {
            $hotelBindingStatus = 'error';
            $hotelBindingDetail = '数据源绑定酒店 #' . $sourceHotelId . '，当前酒店 #' . $hotelId;
            $hotelBindingAction = '重新绑定平台数据源到当前酒店';
            $hotelBindingActionMeta = ['rebind_profile_source', '重新绑定当前酒店', 'platform-sources'];
        } elseif ($configHotelId > 0 && $configHotelId !== $hotelId) {
            $hotelBindingStatus = 'error';
            $hotelBindingDetail = '平台配置绑定酒店 #' . $configHotelId . '，当前酒店 #' . $hotelId;
            $hotelBindingAction = '修正平台配置中的系统酒店ID';
            $hotelBindingActionMeta = ['fix_config_hotel_binding', '修正系统酒店ID', 'platform-sources'];
        } else {
            $hotelBindingActionMeta = ['review_platform_identity', '校验平台身份', 'platform-profile-status'];
        }

        $checks = [
            self::buildCheck(
                'hotel_binding',
                '系统酒店绑定',
                $hotelBindingStatus,
                $hotelBindingDetail,
                $hotelBindingAction,
                $hotelBindingActionMeta[0],
                $hotelBindingActionMeta[1],
                $hotelBindingActionMeta[2]
            ),
        ];

        if ($isCtrip) {
            [$otaHotelId] = self::otaStoreIdFromConfig($platform, $config);
            [$profileId] = self::profileIdFromConfig($config, $profileKey);
            $nodeId = trim((string)($config['node_id'] ?? $config['nodeId'] ?? ''));
            if ($profileId !== '' && $otaHotelId !== '') {
                $identityStatus = 'ok';
                $identityDetail = 'Profile/OTA酒店标识已配置' . ($nodeId !== '' ? '，Node已配置' : '');
                $identityAction = '可执行携程采集';
                $identityActionMeta = ['run_ctrip_trial_capture', '执行携程试采集', 'platform-auto'];
            } elseif ($profileExists || $profileId !== '' || $otaHotelId !== '') {
                $identityStatus = 'warning';
                $identityDetail = '本地Profile存在，但缺少明确OTA酒店标识';
                $identityAction = '补充携程 Profile ID 或 OTA酒店ID';
                $identityActionMeta = ['complete_ctrip_identity', '补 Profile/OTA酒店ID', 'platform-sources'];
            } else {
                $identityStatus = 'missing';
                $identityDetail = '缺少携程 Profile ID / OTA酒店ID';
                $identityAction = '先完成携程账号/Profile绑定';
                $identityActionMeta = ['bind_ctrip_profile', '绑定携程 Profile', 'platform-sources'];
            }
        } else {
            $storeId = trim((string)($config['store_id'] ?? $config['storeId'] ?? $config['poi_id'] ?? $config['poiId'] ?? ''));
            $partnerConfigured = trim((string)($config['partner_id'] ?? $config['partnerId'] ?? '')) !== '';
            if ($storeId === '' && $profileKey === '') {
                $identityStatus = 'missing';
                $identityDetail = '缺少美团 POI/Store 标识';
                $identityAction = '先绑定美团门店 POI/Store';
                $identityActionMeta = ['configure_meituan_poi', '补齐美团 POI/Store', 'platform-sources'];
            } elseif (!$partnerConfigured) {
                $identityStatus = 'ok';
                $identityDetail = 'POI/Store 已配置；Browser Profile 是P0采集主线，Partner ID不是前置条件';
                $identityAction = '可先执行美团 Profile 授权采集';
                $identityActionMeta = ['login_platform_profile', '登录美团', 'profile-login'];
            } else {
                $identityStatus = 'ok';
                $identityDetail = 'POI/Store 与 Partner ID 已配置；仍需已验证的 Browser Profile 会话';
                $identityAction = '可执行美团 Profile 采集';
                $identityActionMeta = ['run_profile_capture', '执行美团 Profile 采集', 'platform-auto'];
            }
        }
        $checks[] = self::buildCheck(
            'platform_identity',
            $isCtrip ? '携程身份标识' : '美团POI/Partner',
            $identityStatus,
            $identityDetail,
            $identityAction,
            $identityActionMeta[0],
            $identityActionMeta[1],
            $identityActionMeta[2]
        );

        $loginStatus = match ($statusCode) {
            'logged_in' => 'ok',
            'login_expired', 'permission_denied', 'hotel_mismatch' => 'error',
            'capture_failed' => 'error',
            'unconfigured' => 'missing',
            default => 'warning',
        };
        $loginActionMeta = self::statusActionMeta($statusCode, $platform);
        $checks[] = self::buildCheck(
            'profile_login',
            '平台登录态',
            $loginStatus,
            self::statusText($statusCode),
            self::nextAction($statusCode, $platform),
            $loginActionMeta[0],
            $loginActionMeta[1],
            $loginActionMeta[2]
        );

        $lastSyncStatus = (string)($source['last_sync_status'] ?? '');
        $lastCaptureTime = (string)($source['last_sync_time'] ?? $source['update_time'] ?? '');
        if (in_array($lastSyncStatus, ['failed'], true)) {
            $trialStatus = 'error';
            $trialDetail = '最近采集失败';
            $trialAction = '查看同步日志后重试';
            $trialActionMeta = ['open_sync_logs', '查看日志并重试采集', 'sync-logs'];
        } elseif (in_array($lastSyncStatus, ['partial_success'], true)) {
            $trialStatus = 'warning';
            $trialDetail = '最近采集部分模块成功';
            $trialAction = '核对缺失模块后补采';
            $trialActionMeta = ['review_partial_capture', '查看缺失模块并补采', 'sync-logs'];
        } elseif ($lastCaptureTime !== '') {
            $trialStatus = 'ok';
            $trialDetail = '最近采集时间：' . $lastCaptureTime;
            $trialAction = '可进入数据质量检查';
            $trialActionMeta = ['open_data_quality', '查看数据质量', 'analysis'];
        } elseif ($statusCode === 'unconfigured') {
            $trialStatus = 'missing';
            $trialDetail = '未配置，暂无采集证据';
            $trialAction = '先完成平台绑定';
            $trialActionMeta = ['configure_platform_profile', '完成平台绑定', 'platform-sources'];
        } else {
            $trialStatus = 'warning';
            $trialDetail = '暂无最近采集时间';
            $trialAction = '完成一次试采集并检查入库';
            $trialActionMeta = ['run_trial_capture', '执行一次试采集', 'platform-auto'];
        }
        $checks[] = self::buildCheck(
            'trial_capture',
            '试采集证据',
            $trialStatus,
            $trialDetail,
            $trialAction,
            $trialActionMeta[0],
            $trialActionMeta[1],
            $trialActionMeta[2]
        );

        return $checks;
    }

    private static function buildCheck(
        string $key,
        string $label,
        string $status,
        string $detail,
        string $nextAction,
        string $actionKey,
        string $actionLabel,
        string $actionTarget
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'detail' => $detail,
            'next_action' => $nextAction,
            'action_key' => $actionKey,
            'action_label' => $actionLabel,
            'action_target' => $actionTarget,
        ];
    }

    private static function otaStoreIdFromConfig(string $platform, array $config): array
    {
        $keys = $platform === 'meituan'
            ? ['store_id', 'storeId', 'poi_id', 'poiId']
            : ['ota_hotel_id', 'otaHotelId', 'ctrip_hotel_id', 'ctripHotelId', 'hotel_code', 'hotelCode', 'hotel_id', 'hotelId'];

        foreach ($keys as $key) {
            $value = trim((string)($config[$key] ?? ''));
            if ($value !== '') {
                return [$value, $key];
            }
        }

        return ['', ''];
    }

    private static function profileIdFromConfig(array $config, string $profileKey): array
    {
        foreach (['profile_id', 'profileId', 'stable_profile_id', 'stableProfileId', 'profile_binding_key', 'profileBindingKey'] as $key) {
            $value = trim((string)($config[$key] ?? ''));
            if ($value !== '') {
                return [$value, $key];
            }
        }

        $profileKey = trim($profileKey);
        return [$profileKey, $profileKey !== '' ? 'resolved_profile_key' : ''];
    }

    private static function firstString(array $config, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string)($config[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value === 1;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on', 'logged_in'], true);
    }

    private static function statusText(string $statusCode): string
    {
        return match ($statusCode) {
            'logged_in' => '登录态已验证',
            'login_expired' => '登录失效',
            'capture_failed' => '采集失败',
            'waiting_login' => '登录待验证',
            default => '未配置',
        };
    }

    private static function nextAction(string $statusCode, string $platform): string
    {
        $name = $platform === 'meituan' ? '美团' : '携程';
        return match ($statusCode) {
            'logged_in' => '登录态已验证；执行目标日同步并检查入库结果',
            'login_expired' => '重新登录' . $name . '平台账号',
            'capture_failed' => '查看最近同步日志后重新检测登录状态',
            'waiting_login' => '点击“登录' . $name . '”完成平台验证',
            default => '先配置酒店与平台账号/Profile 绑定',
        };
    }

    private static function statusActionMeta(string $statusCode, string $platform): array
    {
        $name = $platform === 'meituan' ? '美团' : '携程';
        return match ($statusCode) {
            'logged_in' => ['run_profile_capture', '同步并检查入库', 'platform-auto'],
            'login_expired' => ['login_platform_profile', '重新登录' . $name, 'profile-login'],
            'capture_failed' => ['open_sync_logs', '查看日志并检测登录', 'sync-logs'],
            'waiting_login' => ['login_platform_profile', '登录' . $name, 'profile-login'],
            default => ['configure_platform_profile', '配置账号/Profile', 'platform-sources'],
        };
    }
}
