<?php
declare(strict_types=1);

namespace app\service;

final class PlatformProfileBindingReadinessService
{
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
            $explicitProfileId = trim((string)($config['profile_id'] ?? $config['profileId'] ?? ''));
            $otaHotelId = trim((string)($config['ota_hotel_id'] ?? $config['ctrip_hotel_id'] ?? $config['ctripHotelId'] ?? ''));
            $nodeId = trim((string)($config['node_id'] ?? $config['nodeId'] ?? ''));
            if ($explicitProfileId !== '' || $otaHotelId !== '') {
                $identityStatus = 'ok';
                $identityDetail = 'Profile/OTA酒店标识已配置' . ($nodeId !== '' ? '，Node已配置' : '');
                $identityAction = '可执行携程采集';
                $identityActionMeta = ['run_ctrip_trial_capture', '执行携程试采集', 'platform-auto'];
            } elseif ($profileExists) {
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
            } elseif ($partnerConfigured) {
                $identityStatus = 'ok';
                $identityDetail = 'POI/Store 与 Partner ID 已配置';
                $identityAction = '可执行美团榜单/API采集';
                $identityActionMeta = ['run_meituan_ranking_capture', '执行美团榜单采集', 'meituan-ranking'];
            } elseif ($profileExists) {
                $identityStatus = 'warning';
                $identityDetail = 'Profile可用，但API榜单缺少 Partner ID';
                $identityAction = '补充 Partner ID 以稳定采集榜单';
                $identityActionMeta = ['complete_meituan_partner', '补 Partner ID', 'platform-sources'];
            } else {
                $identityStatus = 'warning';
                $identityDetail = '已有POI/Store，但 Partner ID 未配置';
                $identityAction = '补充 Partner ID 或使用已登录Profile采集';
                $identityActionMeta = ['complete_meituan_identity_or_login', '补 Partner ID 或登录Profile', 'platform-sources'];
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
            'login_expired' => 'error',
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
            $trialDetail = '最近采集部分成功';
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

    private static function statusText(string $statusCode): string
    {
        return match ($statusCode) {
            'logged_in' => '已登录',
            'login_expired' => '登录失效',
            'capture_failed' => '采集失败',
            'waiting_login' => '待登录',
            default => '未配置',
        };
    }

    private static function nextAction(string $statusCode, string $platform): string
    {
        $name = $platform === 'meituan' ? '美团' : '携程';
        return match ($statusCode) {
            'logged_in' => '可执行 Profile 自动采集',
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
            'logged_in' => ['run_profile_capture', '执行试采集', 'platform-auto'],
            'login_expired' => ['login_platform_profile', '重新登录' . $name, 'profile-login'],
            'capture_failed' => ['open_sync_logs', '查看日志并检测登录', 'sync-logs'],
            'waiting_login' => ['login_platform_profile', '登录' . $name, 'profile-login'],
            default => ['configure_platform_profile', '配置账号/Profile', 'platform-sources'],
        };
    }
}
