<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\model\OperationLog;
use app\model\SystemConfig;
use think\Response;

trait MeituanConfigConcern
{
    public function saveMeituanConfig(): Response
    {
        $this->checkPermission();

        $config = [
            'url' => $this->request->post('url', 'https://eb.meituan.com/api/v1/ebooking/business/peer/rank/data/detail'),
            'partner_id' => $this->request->post('partner_id', ''),
            'poi_id' => $this->request->post('poi_id', ''),
            'rank_type' => $this->request->post('rank_type', 'P_RZ'),
            'rank_types' => $this->request->post('rank_types', ['P_RZ']),
            'date_ranges' => $this->request->post('date_ranges', ['1']),
            'cookies' => $this->request->post('cookies', ''),
            'update_time' => date('Y-m-d H:i:s'),
        ];

        $hotelId = $this->resolveOnlineDataSystemHotelId($this->request->post('hotel_id', null));

        // 非超级管理员只能保存自己酒店的配置
        if (!$this->currentUser->isSuperAdmin()) {
            $hotelId = $this->resolveOnlineDataSystemHotelId($hotelId);
            if (!$this->currentUserCanMaintainOtaConfig($hotelId)) {
                return $this->error('无权维护此门店 OTA 配置', 403);
            }
        }

        // 构建存储key
        $key = $hotelId ? "meituan_config_hotel_{$hotelId}" : "meituan_config_global";
        SystemConfig::setValue($key, json_encode($config, JSON_UNESCAPED_UNICODE), '美团配置');

        return $this->success($config, '保存成功');
    }

    public function getMeituanConfig(): Response
    {
        $this->checkPermission();

        $hotelId = $this->request->get('hotel_id', '');

        // 非超级管理员只能获取自己酒店的配置
        if (!$this->currentUser->isSuperAdmin()) {
            $hotelId = $this->currentUser->hotel_id;
        }

        // 优先查找酒店配置，再查找全局配置
        if ($hotelId) {
            $key = "meituan_config_hotel_{$hotelId}";
            $raw = SystemConfig::getValue($key, '');
            if ($raw) {
                $decoded = json_decode((string)$raw, true);
                if (is_array($decoded)) {
                    return $this->success($this->sanitizeSecretConfig($decoded));
                }
            }
        }

        // 查找全局配置
        $globalRaw = SystemConfig::getValue('meituan_config_global', '');
        $globalConfig = json_decode((string)$globalRaw, true);
        if (!is_array($globalConfig)) {
            $globalConfig = [
                'url' => 'https://eb.meituan.com/api/v1/ebooking/business/peer/rank/data/detail',
                'partner_id' => '',
                'poi_id' => '',
                'rank_type' => 'P_RZ',
                'rank_types' => ['P_RZ'],
                'date_ranges' => ['1'],
                'cookies' => '',
            ];
        }

        return $this->success($this->sanitizeSecretConfig($globalConfig));
    }

    public function saveMeituanConfigItem(): Response
    {
        $this->checkPermission();

        $id = trim((string)$this->request->post('id', ''));
        $name = trim((string)$this->request->post('name', ''));
        $partnerId = trim((string)$this->request->post('partner_id', ''));
        $poiId = trim((string)$this->request->post('poi_id', ''));
        $cookies = trim((string)$this->request->post('cookies', ''));
        $authDataStr = $this->request->post('auth_data', '');
        $hotelRoomCount = $this->request->post('hotel_room_count', '');
        $competitorRoomCount = $this->request->post('competitor_room_count', '');

        if (empty($cookies)) {
            return $this->error('临时 Cookie/API 辅助内容不能为空');
        }
        if ($name === '') {
            $name = '美团Cookie ' . date('Y-m-d');
        }

        // 解析认证数据
        $authData = [];
        if (!empty($authDataStr)) {
            $authData = json_decode($authDataStr, true) ?: [];
        }

        $key = 'meituan_config_list';
        $raw = SystemConfig::getValue($key, '[]');
        $list = $raw ? json_decode($raw, true) : [];
        if (!is_array($list)) {
            $list = [];
        }
        $originalConfig = is_array($list[$id] ?? null) ? $list[$id] : [];

        // 生成唯一ID
        if (empty($id)) {
            $id = 'meituan_' . date('YmdHis') . '_' . substr(md5($name . time()), 0, 8);
            $originalConfig = [];
        }
        $hotelIdInput = $this->request->post('hotel_id', $originalConfig['hotel_id'] ?? ($originalConfig['system_hotel_id'] ?? null));
        $targetHotelId = $this->positiveOtaConfigHotelId($hotelIdInput);
        if (!empty($originalConfig)) {
            if (!$this->currentUserCanMaintainOtaConfigItem($originalConfig, $targetHotelId)) {
                return $this->error('No permission to maintain this OTA config.', 403);
            }
            $hotelId = $targetHotelId ?? $this->otaConfigBoundSystemHotelId($originalConfig);
        } else {
            $hotelId = $this->resolveOnlineDataSystemHotelId($hotelIdInput);
            if (!$this->currentUserCanMaintainOtaConfig($hotelId)) {
                return $this->error('No permission to maintain this OTA config.', 403);
            }
        }
        $hotelIdValue = $hotelId !== null ? (string)$hotelId : '';
        // 非超级管理员可维护本人创建或本人酒店绑定的配置
        if (!empty($id) && isset($list[$id])) {
            if (!$this->isOtaConfigVisibleToCurrentUser($list[$id])) {
                return $this->error('无权修改此配置');
            }
        }

        // 非超级管理员删除时也只能删自己的
        $userId = $this->currentUser->isSuperAdmin() ? ($originalConfig['user_id'] ?? null) : $this->currentUser->id;

        $config = [
            'id' => $id,
            'name' => $name,
            'hotel_id' => $hotelIdValue,
            'system_hotel_id' => $hotelId,
            'partner_id' => $partnerId,
            'poi_id' => $poiId,
            'cookies' => $cookies,
            'auth_data' => $authData,
            'hotel_room_count' => $hotelRoomCount,
            'competitor_room_count' => $competitorRoomCount,
            'user_id' => $userId,
            'update_time' => date('Y-m-d H:i:s'),
            'created_at' => $originalConfig['created_at'] ?? date('Y-m-d H:i:s'),
        ];
        $config = $this->normalizeOtaConfigHotelBinding($config, 'meituan');
        $list[$id] = $config;

        $encoded = json_encode($list, JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            \think\facade\Log::error('Meituan config list JSON encode failed: ' . json_last_error_msg(), [
                'config_id' => $id,
                'system_hotel_id' => $hotelId,
            ]);
            return $this->error('配置保存失败：Cookie/API 辅助内容无法安全写入，请重新获取后保存', 500);
        }
        SystemConfig::setValue($key, $encoded, '美团配置列表');
        $this->clearAutoFetchLightConfigListCache('meituan');

        OperationLog::record('online_data', 'save_meituan_config', "保存美团配置: {$name}", $this->currentUser->id);

        $credentialStatus = $this->meituanAutoFetchConfigStatus($config);
        $message = $credentialStatus['credential_status'] === 'missing_resource_id'
            ? '配置保存成功，缺门店标识，补充 Partner ID / POI ID 后可获取美团榜单'
            : '配置保存成功';

        return $this->success($this->sanitizeSecretConfig($config), $message);
    }

    public function getMeituanConfigList(): Response
    {
        // 仅检查登录状态，不强制要求酒店关联（配置读取不需要绑定酒店）
        if (!$this->currentUser || !$this->currentUser->id) {
            return json(['code' => 401, 'message' => '未登录']);
        }

        try {
            $key = 'meituan_config_list';
            $raw = SystemConfig::getValue($key, '[]');
            $list = $raw ? json_decode($raw, true) : [];
            if (!is_array($list)) {
                $list = [];
            }
            $list = $this->normalizeStoredOtaConfigList('system_config', $key, $list, 'meituan');

            $list = $this->filterOtaConfigListForCurrentUser($list);

            usort($list, function($a, $b) {
                return strcmp($b['update_time'] ?? '', $a['update_time'] ?? '');
            });

            return $this->success(array_map([$this, 'sanitizeSecretConfig'], array_values($list)));
        } catch (\Throwable $e) {
            \think\facade\Log::error('获取美团配置列表失败: ' . $e->getMessage(), ['exception' => $e]);
            return $this->error('获取美团配置列表失败', 500);
        }
    }

    public function getMeituanConfigDetail(): Response
    {
        $this->checkPermission();

        $id = trim((string)$this->request->get('id', ''));
        if ($id === '') {
            return $this->error('Config id is required.');
        }

        $key = 'meituan_config_list';
        $raw = SystemConfig::getValue($key, '[]');
        $list = $raw ? json_decode((string)$raw, true) : [];
        if (!is_array($list)) {
            $list = [];
        }
        $list = $this->normalizeStoredOtaConfigList('system_config', $key, $list, 'meituan');

        if (!isset($list[$id])) {
            return $this->error('Config not found.', 404);
        }
        if (!$this->isOtaConfigVisibleToCurrentUser($list[$id])) {
            return $this->error('Forbidden', 403);
        }
        if (!$this->currentUserCanMaintainOtaConfigItem($list[$id])) {
            return $this->error('Forbidden', 403);
        }

        return $this->success($list[$id]);
    }

    public function deleteMeituanConfig(): Response
    {
        $this->checkPermission();

        $id = $this->request->param('id', '');
        if (empty($id)) {
            return $this->error('请提供配置ID');
        }

        $key = 'meituan_config_list';
        $raw = SystemConfig::getValue($key, '[]');
        $list = $raw ? json_decode($raw, true) : [];
        if (!is_array($list)) {
            $list = [];
        }
        $list = $this->normalizeStoredOtaConfigList('system_config', $key, $list, 'meituan');

        if (!isset($list[$id])) {
            return $this->error('配置不存在');
        }

        if (!$this->isOtaConfigVisibleToCurrentUser($list[$id])) {
            return $this->error('无权删除此配置');
        }

        if (!$this->currentUserCanMaintainOtaConfigItem($list[$id])) {
            $this->checkActionPermission('can_delete_online_data');
        }

        $name = $list[$id]['name'] ?? '';
        unset($list[$id]);
        $encoded = json_encode($list, JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            \think\facade\Log::error('Meituan config list JSON encode failed on delete: ' . json_last_error_msg(), [
                'config_id' => $id,
            ]);
            return $this->error('配置删除失败：配置列表无法安全写入', 500);
        }
        SystemConfig::setValue($key, $encoded, '美团配置列表');
        $this->clearAutoFetchLightConfigListCache('meituan');

        OperationLog::record('online_data', 'delete_meituan_config', "删除美团配置: {$name}", $this->currentUser->id);

        return $this->success(null, '删除成功');
    }

    public function saveMeituanCommentConfig(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $data = $this->requestData();
        $systemHotelId = $this->resolveOnlineDataSystemHotelId(
            $data['system_hotel_id']
            ?? $data['systemHotelId']
            ?? $data['hotel_id']
            ?? $data['hotelId']
            ?? null
        );
        $config = [
            'partner_id' => trim((string)($data['partner_id'] ?? $data['partnerId'] ?? '')),
            'poi_id' => trim((string)($data['poi_id'] ?? $data['poiId'] ?? '')),
            'store_id' => trim((string)($data['store_id'] ?? $data['storeId'] ?? $data['poi_id'] ?? $data['poiId'] ?? '')),
            'cookies' => trim((string)($data['cookies'] ?? $data['cookie'] ?? '')),
            'mtgsig' => trim((string)($data['mtgsig'] ?? '')),
            '_mtsi_eb_u' => trim((string)($data['_mtsi_eb_u'] ?? $data['mtsiEbU'] ?? '')),
            'reply_type' => trim((string)($data['reply_type'] ?? $data['replyType'] ?? '')),
            'tag' => trim((string)($data['tag'] ?? '')),
            'limit' => (int)($data['limit'] ?? 20),
            'offset' => (int)($data['offset'] ?? 0),
            'capture_sections' => 'reviews',
            'profile_sections' => 'reviews',
            'system_hotel_id' => $systemHotelId,
            'scope' => 'ota_channel_review_summary',
            'privacy_boundary' => 'aggregate_metrics_only_no_review_text',
        ];

        $saved = $this->saveOtaDataConfigValue('meituan-comments', $config, '美团点评聚合采集配置');
        OperationLog::record('online_data', 'save_meituan_comment_config', '保存美团点评聚合采集配置', $this->currentUser->id);

        return $this->success($this->sanitizeSecretConfig($saved), '配置保存成功');
    }

    public function getMeituanCommentConfigList(): Response
    {
        $this->checkPermission();

        $config = $this->readOtaDataConfigValue('meituan-comments');
        return $this->success($config === [] ? [] : [$this->sanitizeSecretConfig($config)]);
    }

    public function generateMeituanBookmarklet(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');

        $script = $this->buildDisabledCookieBookmarkletScript('美团');

        return $this->success([
            'script' => $script,
            'bookmarklet' => 'javascript:' . $script,
            'status' => 'disabled_by_policy',
            'message' => '旧版美团 Cookie 书签已禁用，避免把宿析登录 token 暴露到 OTA 页面。',
        ], '旧版美团 Cookie 书签已禁用');
    }

}
