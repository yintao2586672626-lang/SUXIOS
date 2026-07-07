<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\model\SystemConfig;
use app\service\BrowserProfileCaptureRequestService;
use think\facade\Db;

trait OtaConfigConcern
{
    private function sanitizeSecretConfig(array $item): array
    {
        $isMeituanConfig = array_key_exists('partner_id', $item)
            || array_key_exists('partnerId', $item)
            || array_key_exists('poi_id', $item)
            || array_key_exists('poiId', $item)
            || array_key_exists('hotel_room_count', $item)
            || array_key_exists('competitor_room_count', $item);
        if ($isMeituanConfig) {
            $credentialStatus = $this->meituanAutoFetchConfigStatus($item);
            $item['credential_requirement'] = $credentialStatus;
            $item['credential_status'] = $credentialStatus['credential_status'];
            $item['credential_status_label'] = $credentialStatus['credential_status_label'];
            $item['credential_level'] = $credentialStatus['credential_level'];
            $item['credential_level_label'] = $credentialStatus['credential_level_label'];
            $item['missing_fields'] = $credentialStatus['missing_fields'];
            $item['missing_text'] = $credentialStatus['missing_text'];
        }

        foreach (['cookies', 'cookie'] as $field) {
            if (array_key_exists($field, $item)) {
                $value = (string)$item[$field];
                $item['has_cookies'] = trim($value) !== '';
                $item['cookies_preview'] = $this->maskSecretValue($value);
                unset($item[$field]);
            }
        }

        foreach (['token', 'spidertoken', 'mtgsig'] as $field) {
            if (array_key_exists($field, $item)) {
                $value = (string)$item[$field];
                $item["has_{$field}"] = trim($value) !== '';
                $item["{$field}_preview"] = $this->maskSecretValue($value);
                unset($item[$field]);
            }
        }

        return $item;
    }

    private function maskSecretValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $length = strlen($value);
        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($value, 0, 4) . '...' . substr($value, -4);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function saveOtaDataConfigValue(string $type, array $config, string $description): array
    {
        $key = 'data_config_' . str_replace('-', '_', $type);
        $config['update_time'] = date('Y-m-d H:i:s');
        SystemConfig::setValue($key, json_encode($config, JSON_UNESCAPED_UNICODE), $description);
        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    private function readOtaDataConfigValue(string $type): array
    {
        $key = 'data_config_' . str_replace('-', '_', $type);
        $raw = SystemConfig::getValue($key, '');
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function getConfigList(string $key): array
    {
        $raw = SystemConfig::getValue($key, '[]');
        $data = json_decode((string)$raw, true);
        return is_array($data) ? $data : [];
    }

    private function getStoredCtripConfigList(): array
    {
        try {
            $raw = Db::name('system_configs')->where('config_key', 'ctrip_config_list')->value('config_value');
            $list = $raw ? json_decode((string)$raw, true) : [];
            if (!is_array($list)) {
                return [];
            }
            $list = $this->normalizeStoredOtaConfigList('system_configs', 'ctrip_config_list', $list, 'ctrip');
            return array_values($list);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function getStoredMeituanConfigList(): array
    {
        try {
            $list = $this->getConfigList('meituan_config_list');
            if (!is_array($list)) {
                return [];
            }
            $list = $this->normalizeStoredOtaConfigList('system_config', 'meituan_config_list', $list, 'meituan');
            return array_values($list);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function filterOtaConfigListForCurrentUser(array $list): array
    {
        return $this->filterOtaConfigListForUser($list, $this->currentUser);
    }

    private function filterOtaConfigListForUser(array $list, $user): array
    {
        if (!$user || !isset($user->id) || !$user->id) {
            return [];
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return array_values($list);
        }

        $permittedHotelIdSet = $this->getPermittedHotelIdSetForUser($user);
        $visibleList = [];

        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }

            if ($this->isOtaConfigVisibleToUser($item, $user, $permittedHotelIdSet)) {
                $visibleList[] = $item;
            }
        }

        return $visibleList;
    }

    private function isOtaConfigVisibleToCurrentUser(array $item, ?array $permittedHotelIdSet = null): bool
    {
        return $this->isOtaConfigVisibleToUser($item, $this->currentUser, $permittedHotelIdSet);
    }

    private function isOtaConfigVisibleToUser(array $item, $user, ?array $permittedHotelIdSet = null): bool
    {
        if (!$user || !isset($user->id) || !$user->id) {
            return false;
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        $itemUserId = $item['user_id'] ?? null;
        if ($itemUserId !== null && $itemUserId !== '' && (string)$itemUserId === (string)$user->id) {
            return true;
        }

        $permittedHotelIdSet = $permittedHotelIdSet ?? $this->getPermittedHotelIdSetForUser($user);
        $systemHotelId = trim((string)($item['system_hotel_id'] ?? ''));
        if ($systemHotelId !== '' && isset($permittedHotelIdSet[$systemHotelId])) {
            return true;
        }

        return false;
    }

    private function getCurrentUserPermittedHotelIdSet(): array
    {
        return $this->getPermittedHotelIdSetForUser($this->currentUser);
    }

    private function getPermittedHotelIdSetForUser($user): array
    {
        if (!$user || !method_exists($user, 'getPermittedHotelIds')) {
            return [];
        }

        $hotelIds = array_map('strval', $user->getPermittedHotelIds());
        return array_fill_keys($hotelIds, true);
    }

    private function resolveCtripFetchConfigForHotel(int $hotelId): array
    {
        $resolvedConfig = [];
        foreach ($this->getStoredCtripConfigList() as $config) {
            $configHotelId = (string)($config['hotel_id'] ?? $config['system_hotel_id'] ?? '');
            if ($configHotelId !== '' && (string)$hotelId === $configHotelId) {
                $resolvedConfig = $config;
                break;
            }
        }

        if (empty($resolvedConfig)) {
            $cookiesList = $this->getConfigList("online_data_cookies_hotel_{$hotelId}");

            foreach ($cookiesList as $item) {
                if (!empty($item['cookies'])) {
                    $resolvedConfig = [
                        'cookies' => $item['cookies'],
                        'url' => 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportCompeteHotelReport',
                        'node_id' => '24588',
                    ];
                    break;
                }
            }
        }

        $ctripCookieApiConfig = $this->readSavedOtaDataConfig('ctrip-cookie-api');
        if (is_array($ctripCookieApiConfig) && $this->isAutoFetchDataConfigUsable($ctripCookieApiConfig, $hotelId)) {
            $resolvedConfig = $resolvedConfig === []
                ? $ctripCookieApiConfig
                : array_merge($resolvedConfig, $ctripCookieApiConfig);
        }

        if (!empty($resolvedConfig)) {
            return $resolvedConfig;
        }

        return [];
    }

    private function resolveMeituanFetchConfigForHotel(int $hotelId): array
    {
        foreach ($this->getStoredMeituanConfigList() as $config) {
            $configHotelId = (string)($config['hotel_id'] ?? $config['system_hotel_id'] ?? '');
            if ($configHotelId !== '' && (string)$hotelId === $configHotelId) {
                return $config;
            }
        }

        return [];
    }

    private function resolveCtripFetchConfigForHotelLight(int $hotelId): array
    {
        $resolvedConfig = [];
        foreach ($this->getStoredCtripConfigListRaw() as $config) {
            $configHotelId = (string)($config['hotel_id'] ?? $config['system_hotel_id'] ?? '');
            if ($configHotelId !== '' && (string)$hotelId === $configHotelId) {
                $resolvedConfig = $config;
                break;
            }
        }

        if (empty($resolvedConfig)) {
            $cookiesList = $this->getConfigList("online_data_cookies_hotel_{$hotelId}");
            foreach ($cookiesList as $item) {
                if (is_array($item) && !empty($item['cookies'])) {
                    $resolvedConfig = [
                        'cookies' => $item['cookies'],
                        'url' => 'https://ebooking.ctrip.com/datacenter/api/dataCenter/report/getDayReportCompeteHotelReport',
                        'node_id' => '24588',
                    ];
                    break;
                }
            }
        }

        $ctripCookieApiConfig = $this->readSavedOtaDataConfig('ctrip-cookie-api');
        if (is_array($ctripCookieApiConfig) && $this->isAutoFetchDataConfigUsable($ctripCookieApiConfig, $hotelId)) {
            $resolvedConfig = $resolvedConfig === []
                ? $ctripCookieApiConfig
                : array_merge($resolvedConfig, $ctripCookieApiConfig);
        }

        return $resolvedConfig;
    }

    private function resolveMeituanFetchConfigForHotelLight(int $hotelId): array
    {
        foreach ($this->getStoredMeituanConfigListRaw() as $config) {
            $configHotelId = (string)($config['hotel_id'] ?? $config['system_hotel_id'] ?? '');
            if ($configHotelId !== '' && (string)$hotelId === $configHotelId) {
                return $config;
            }
        }

        return [];
    }

    private function getStoredCtripConfigListRaw(): array
    {
        $cacheKey = $this->autoFetchLightConfigListCacheKey('ctrip');
        $cached = $this->readAutoFetchLightReadCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $raw = Db::name('system_configs')->where('config_key', 'ctrip_config_list')->value('config_value');
            $list = $raw ? json_decode((string)$raw, true) : [];
            $list = is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
            return $this->writeAutoFetchLightReadCache($cacheKey, $list);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function getStoredMeituanConfigListRaw(): array
    {
        $cacheKey = $this->autoFetchLightConfigListCacheKey('meituan');
        $cached = $this->readAutoFetchLightReadCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $list = $this->getConfigList('meituan_config_list');
            $list = is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
            return $this->writeAutoFetchLightReadCache($cacheKey, $list);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function ctripProfileStoreIdFromConfig(array $config, int $hotelId = 0): string
    {
        foreach (['profile_id', 'profileId'] as $key) {
            $value = trim((string)($config[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        foreach ([(string)$hotelId, (string)($config['system_hotel_id'] ?? ''), (string)($config['hotel_id'] ?? '')] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && $this->ctripProfileDirExists($candidate)) {
                return $candidate;
            }
        }

        foreach (['ota_hotel_id', 'ctrip_hotel_id', 'ctripHotelId', 'hotel_code', 'hotelCode', 'hotel_id', 'system_hotel_id'] as $key) {
            $value = trim((string)($config[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return $hotelId > 0 ? (string)$hotelId : '';
    }

    private function ctripProfileExistsForConfig(array $config, int $hotelId = 0): bool
    {
        $profileId = $this->ctripProfileStoreIdFromConfig($config, $hotelId);
        if ($profileId === '') {
            return false;
        }

        return $this->ctripProfileDirExists($profileId);
    }

    private function ctripProfileDirExists(string $profileId): bool
    {
        $profileId = trim($profileId);
        if ($profileId === '') {
            return false;
        }

        $projectRoot = dirname(__DIR__, 3);
        $profileDir = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'ctrip_profile_' . BrowserProfileCaptureRequestService::safeFilePart($profileId);
        return is_dir($profileDir);
    }

    private function meituanProfileStoreIdFromConfig(array $config): string
    {
        return trim((string)($config['store_id'] ?? $config['storeId'] ?? $config['poi_id'] ?? $config['poiId'] ?? ''));
    }

    private function meituanProfileExistsForConfig(array $config): bool
    {
        $storeId = $this->meituanProfileStoreIdFromConfig($config);
        if ($storeId === '') {
            return false;
        }

        $projectRoot = dirname(__DIR__, 3);
        $profileDir = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'meituan_profile_' . BrowserProfileCaptureRequestService::safeFilePart($storeId);
        return is_dir($profileDir);
    }

    private function ctripLatestFetchStatusKey(?int $hotelId): string
    {
        return $hotelId ? "online_data_ctrip_latest_fetch_{$hotelId}" : 'online_data_ctrip_latest_fetch';
    }

    private function updateCtripLatestFetchStatus(?int $hotelId, string $fetchedAt, string $dataDate, int $savedCount): void
    {
        cache($this->ctripLatestFetchStatusKey($hotelId), [
            'fetched_at' => $fetchedAt,
            'data_date' => $dataDate,
            'saved_count' => $savedCount,
        ], 86400 * 30);
    }

    private function getCtripLatestFetchStatus(string $hotelId): array
    {
        $statusKeyHotelId = is_numeric($hotelId) && (int)$hotelId > 0 ? (int)$hotelId : null;
        $status = cache($this->ctripLatestFetchStatusKey($statusKeyHotelId)) ?: [];
        return is_array($status) ? $status : [];
    }


    private function getHotelsForOtaConfigMatching(): array
    {
        try {
            $rows = Db::name('hotels')
                ->field('id,name,code,status')
                ->order('status', 'desc')
                ->order('id', 'asc')
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }

        $rows = array_values(array_filter($rows, static function ($row): bool {
            return is_array($row) && trim((string)($row['name'] ?? '')) !== '';
        }));

        usort($rows, static function (array $a, array $b): int {
            $statusCompare = (int)($b['status'] ?? 0) <=> (int)($a['status'] ?? 0);
            if ($statusCompare !== 0) {
                return $statusCompare;
            }
            return mb_strlen((string)($b['name'] ?? '')) <=> mb_strlen((string)($a['name'] ?? ''));
        });

        return $rows;
    }

    private function normalizeOtaConfigMatchText(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/(携程|美团|ebooking|e-booking|ebk|数据源|配置|主账号|账号|cookie|cookies)/iu', '', $value) ?? $value;
        $value = preg_replace('/[^\p{Han}a-z0-9]+/iu', '', $value) ?? $value;

        return mb_strtolower($value, 'UTF-8');
    }

    private function findOtaConfigHotelMatch(array $config, array $hotels): ?array
    {
        $currentHotelId = trim((string)($config['hotel_id'] ?? $config['system_hotel_id'] ?? ''));
        if ($currentHotelId !== '') {
            foreach ($hotels as $hotel) {
                if ((string)($hotel['id'] ?? '') === $currentHotelId) {
                    return $hotel;
                }
            }
        }

        $sourceParts = [
            $config['hotel_name'] ?? '',
            $config['name'] ?? '',
            $config['config_name'] ?? '',
            $config['remark'] ?? '',
        ];
        $source = trim(implode(' ', array_filter(array_map(static fn($part): string => trim((string)$part), $sourceParts))));
        if ($source === '') {
            return null;
        }

        foreach ($hotels as $hotel) {
            $hotelName = trim((string)($hotel['name'] ?? ''));
            if ($hotelName !== '' && mb_strpos($source, $hotelName, 0, 'UTF-8') !== false) {
                return $hotel;
            }
        }

        $normalizedSource = $this->normalizeOtaConfigMatchText($source);
        if ($normalizedSource === '') {
            return null;
        }

        foreach ($hotels as $hotel) {
            $hotelName = $this->normalizeOtaConfigMatchText((string)($hotel['name'] ?? ''));
            $hotelCode = $this->normalizeOtaConfigMatchText((string)($hotel['code'] ?? ''));
            if ($hotelName !== '' && mb_strpos($normalizedSource, $hotelName, 0, 'UTF-8') !== false) {
                return $hotel;
            }
            if ($hotelCode !== '' && mb_strpos($normalizedSource, $hotelCode, 0, 'UTF-8') !== false) {
                return $hotel;
            }
        }

        return null;
    }

    private function normalizeOtaConfigHotelBinding(array $config, string $platform, ?array $hotels = null): array
    {
        $hotels = $hotels ?? $this->getHotelsForOtaConfigMatching();
        $match = $this->findOtaConfigHotelMatch($config, $hotels);

        if (!$match) {
            $config['hotel_id'] = $config['hotel_id'] ?? '';
            $config['hotel_name'] = $config['hotel_name'] ?? '';
            return $config;
        }

        $hotelId = (string)($match['id'] ?? '');
        if ($hotelId === '') {
            return $config;
        }

        $config['hotel_id'] = $hotelId;
        $config['system_hotel_id'] = $hotelId;
        $config['hotel_name'] = (string)($match['name'] ?? $config['hotel_name'] ?? '');
        $config['platform'] = $config['platform'] ?? $platform;

        return $config;
    }

    private function normalizeStoredOtaConfigList(string $table, string $key, array $list, string $platform): array
    {
        if (empty($list)) {
            return $list;
        }

        $hotels = $this->getHotelsForOtaConfigMatching();
        if (empty($hotels)) {
            return $list;
        }

        $changed = false;
        $normalizedList = [];

        foreach ($list as $index => $item) {
            if (!is_array($item)) {
                $normalizedList[$index] = $item;
                continue;
            }

            $normalized = $this->normalizeOtaConfigHotelBinding($item, $platform, $hotels);
            if ($normalized != $item) {
                $changed = true;
            }
            $normalizedList[$index] = $normalized;
        }

        if ($changed) {
            Db::name($table)->where('config_key', $key)->update([
                'config_value' => json_encode($normalizedList, JSON_UNESCAPED_UNICODE),
                'update_time' => date('Y-m-d H:i:s'),
            ]);
        }

        return $normalizedList;
    }

    /**
     * 保存列表到系统配置
     */
    private function setConfigList(string $key, array $value): void
    {
        SystemConfig::setValue($key, json_encode($value, JSON_UNESCAPED_UNICODE), '在线数据Cookies配置');
    }

}
