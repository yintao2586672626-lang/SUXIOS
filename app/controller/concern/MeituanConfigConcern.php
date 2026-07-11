<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\model\OperationLog;
use think\exception\HttpException;
use think\facade\Db;
use think\Response;

/**
 * Meituan configuration endpoints.
 *
 * Configuration metadata stays in system_configs; credential material is
 * delegated to OtaConfigConcern's protected vault boundary. This trait must
 * be used together with OtaConfigConcern by OnlineData.
 */
trait MeituanConfigConcern
{
    public function saveMeituanConfig(): Response
    {
        try {
            $this->checkPermission();
            $saved = $this->saveMeituanConfigPayload($this->requestData(), true, '');
            $this->clearAutoFetchLightConfigListCache('meituan');
            return $this->success($saved, '配置保存成功');
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 400);
        } catch (\Throwable) {
            return $this->error('保存失败', 500);
        }
    }

    public function getMeituanConfig(): Response
    {
        try {
            $this->checkPermission();
            $hotelId = $this->resolveMeituanConfigHotelIdFromRequest();
            foreach ($this->meituanConfigList() as $item) {
                if (!is_array($item)
                    || $this->isMeituanCommentConfigMetadata($item)
                    || !$this->isOtaConfigVisibleToCurrentUser($item)) {
                    continue;
                }
                if ($hotelId > 0 && $this->strictOtaConfigBoundHotelId($item, 'Meituan') !== $hotelId) {
                    continue;
                }
                return $this->success($this->sanitizeSecretConfig($item));
            }

            return $this->success([]);
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        } catch (\Throwable) {
            return $this->error('获取美团配置失败', 500);
        }
    }

    public function saveMeituanConfigItem(): Response
    {
        try {
            $this->checkPermission();
            $saved = $this->saveMeituanConfigPayload($this->requestData(), false, '');
            $this->clearAutoFetchLightConfigListCache('meituan');
            return $this->success($saved, '配置保存成功');
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 400);
        } catch (\Throwable) {
            return $this->error('保存失败', 500);
        }
    }

    /**
     * @param array<string, mixed> $requestData
     * @return array<string, mixed>
     */
    private function saveMeituanConfigPayload(
        array $requestData,
        bool $allowCreateWithProvidedId = false,
        string $defaultScope = ''
    ): array
    {
        $id = trim((string)($requestData['id'] ?? ''));
        $list = $this->meituanConfigList();
        $originalConfig = $id !== '' && is_array($list[$id] ?? null) ? $list[$id] : [];
        $isUpdate = $originalConfig !== [];

        if ($id !== '' && !$isUpdate && !$allowCreateWithProvidedId) {
            throw new \InvalidArgumentException('美团配置不存在');
        }
        if ($id === '') {
            $id = 'meituan_' . date('YmdHis') . '_' . substr(hash('sha256', random_bytes(16)), 0, 8);
        }

        $requestedHotelId = $this->meituanRequestedHotelId($requestData);
        if ($isUpdate) {
            $systemHotelId = $this->strictOtaConfigBoundHotelId($originalConfig, 'Meituan');
            if ($requestedHotelId !== null && $requestedHotelId !== $systemHotelId) {
                throw new \InvalidArgumentException('不允许变更已有凭据的系统酒店绑定');
            }
            if (!$this->isOtaConfigVisibleToCurrentUser($originalConfig)
                || !$this->currentUserCanMaintainOtaConfigItem($originalConfig, $systemHotelId)) {
                throw new HttpException(403, '无权修改此配置');
            }
        } else {
            $systemHotelId = $this->resolveOnlineDataSystemHotelId($requestedHotelId);
            if ($systemHotelId === null || $systemHotelId <= 0) {
                throw new \InvalidArgumentException('请选择系统酒店');
            }
            $this->checkOtaConfigMaintenancePermission($systemHotelId);
        }

        $safeOriginal = $this->sanitizeSecretConfig($originalConfig);
        [$requestMetadata, $requestSecrets] = $this->splitOtaConfigSecrets($requestData);
        $originalScope = trim((string)($safeOriginal['scope'] ?? ''));
        $hasRequestedScope = array_key_exists('scope', $requestMetadata);
        $requestedScope = trim((string)($requestMetadata['scope'] ?? $defaultScope));
        if ($this->isMeituanCommentConfigMetadata($requestMetadata)
            || ($isUpdate && $this->isMeituanCommentConfigMetadata($safeOriginal))) {
            throw new \InvalidArgumentException('Meituan review scope is disabled for this endpoint.');
        }
        $allowedScopes = ['', 'ota_channel_config', 'meituan_ota_config'];
        if ($isUpdate) {
            if ($hasRequestedScope && !hash_equals($originalScope, $requestedScope)) {
                throw new \InvalidArgumentException('Meituan config scope cannot change.');
            }
            $scope = $originalScope;
        } else {
            $scope = $requestedScope;
        }
        if (!in_array($scope, $allowedScopes, true)) {
            throw new \InvalidArgumentException('Meituan config scope is not allowed.');
        }
        unset($requestMetadata['scope']);
        $name = trim((string)($requestMetadata['name'] ?? $safeOriginal['name'] ?? ''));
        if ($name === '') {
            $poi = trim((string)($requestMetadata['poi_id'] ?? $requestMetadata['poiId'] ?? $safeOriginal['poi_id'] ?? $safeOriginal['poiId'] ?? ''));
            $name = $poi === '' ? '美团配置 ' . date('Y-m-d') : '美团' . $poi . '配置';
        }

        $config = array_merge($safeOriginal, $requestMetadata, [
            'id' => $id,
            'config_id' => $id,
            'name' => $name,
            'hotel_id' => (string)$systemHotelId,
            'system_hotel_id' => $systemHotelId,
            'partner_id' => trim((string)($requestMetadata['partner_id'] ?? $requestMetadata['partnerId'] ?? $safeOriginal['partner_id'] ?? $safeOriginal['partnerId'] ?? '')),
            'poi_id' => trim((string)($requestMetadata['poi_id'] ?? $requestMetadata['poiId'] ?? $safeOriginal['poi_id'] ?? $safeOriginal['poiId'] ?? '')),
            'scope' => $scope,
            'update_time' => date('Y-m-d H:i:s'),
            'created_at' => $safeOriginal['created_at'] ?? date('Y-m-d H:i:s'),
            'user_id' => $this->currentUser->isSuperAdmin()
                ? ($safeOriginal['user_id'] ?? null)
                : (int)($this->currentUser->id ?? 0),
        ], $requestSecrets);

        $saved = $this->persistMeituanConfigMetadata(
            $config,
            (int)($this->currentUser->id ?? 0),
            $isUpdate,
            $scope
        );
        OperationLog::record('online_data', 'save_meituan_config', '保存美团配置元数据', (int)($this->currentUser->id ?? 0));

        return $saved;
    }

    public function getMeituanConfigList(): Response
    {
        if (!$this->currentUser || empty($this->currentUser->id)) {
            return $this->error('未登录', 401);
        }

        try {
            $list = array_filter(
                $this->meituanConfigList(),
                fn($item): bool => is_array($item) && !$this->isMeituanCommentConfigMetadata($item)
            );
            $list = $this->filterOtaConfigListForCurrentUser($list);
            $list = $this->sanitizeStoredOtaConfigListForRuntime($list);
            usort($list, static fn(array $left, array $right): int => strcmp((string)($right['update_time'] ?? ''), (string)($left['update_time'] ?? '')));
            return $this->success(array_values($list));
        } catch (\Throwable) {
            return $this->error('获取美团配置列表失败', 500);
        }
    }

    public function getMeituanConfigDetail(): Response
    {
        try {
            $this->checkPermission();
            $id = trim((string)$this->request->get('id', ''));
            if ($id === '') {
                return $this->error('Config id is required.', 400);
            }
            $list = $this->meituanConfigList();
            if (!isset($list[$id]) || !is_array($list[$id])) {
                return $this->error('Config not found.', 404);
            }
            if ($this->isMeituanCommentConfigMetadata($list[$id])) {
                return $this->error('Forbidden', 403);
            }
            if (!$this->isOtaConfigVisibleToCurrentUser($list[$id])
                || !$this->currentUserCanMaintainOtaConfigItem($list[$id])) {
                return $this->error('Forbidden', 403);
            }

            $safeList = $this->sanitizeStoredOtaConfigListForRuntime([$id => $list[$id]]);
            return $this->success($safeList[$id] ?? []);
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        } catch (\Throwable) {
            return $this->error('获取美团配置详情失败', 500);
        }
    }

    public function deleteMeituanConfig(): Response
    {
        try {
            $this->checkPermission();
            $id = trim((string)$this->request->param('id', ''));
            if ($id === '') {
                return $this->error('请提供配置ID', 400);
            }
            $list = $this->meituanConfigList();
            if (!isset($list[$id]) || !is_array($list[$id])) {
                return $this->error('配置不存在', 404);
            }
            if ($this->isMeituanCommentConfigMetadata($list[$id])) {
                return $this->error('Forbidden', 403);
            }
            if (!$this->isOtaConfigVisibleToCurrentUser($list[$id])) {
                throw new HttpException(403, '无权删除此配置');
            }

            $systemHotelId = $this->strictOtaConfigBoundHotelId($list[$id], 'Meituan');
            if (!$this->currentUserCanMaintainOtaConfigItem($list[$id], $systemHotelId)) {
                $this->checkActionPermission('can_delete_online_data');
            }

            $expectedScope = trim((string)($list[$id]['scope'] ?? ''));
            $deleted = $this->deleteMeituanConfigMetadata($id, $systemHotelId, $expectedScope);
            $this->clearAutoFetchLightConfigListCache('meituan');
            OperationLog::record('online_data', 'delete_meituan_config', '删除美团配置元数据', (int)($this->currentUser->id ?? 0));
            return $this->success($deleted, '删除成功');
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        } catch (\Throwable) {
            return $this->error('删除失败', 500);
        }
    }

    public function saveMeituanCommentConfig(): Response
    {
        $this->checkPermission();
        return $this->error('美团点评聚合仅支持受控浏览器 Profile 采集，不接受 Cookie/API 配置。', 422);
    }

    public function getMeituanCommentConfigList(): Response
    {
        $this->checkPermission();
        return $this->success([]);
    }

    public function generateMeituanBookmarklet(): Response
    {
        $this->checkPermission();
        return $this->success([
            'status' => 'disabled_by_policy',
            'message' => '旧版美团 Cookie 书签已禁用。',
        ], '旧版美团 Cookie 书签已禁用');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function meituanConfigList(): array
    {
        $raw = Db::name('system_configs')->where('config_key', 'meituan_config_list')->value('config_value');
        $list = $raw === null || $raw === '' ? [] : json_decode((string)$raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($list)) {
            throw new \RuntimeException('Stored Meituan config list is invalid.');
        }

        return $list;
    }

    private function resolveMeituanConfigHotelIdFromRequest(): int
    {
        $requested = $this->request->get('hotel_id', $this->request->get('system_hotel_id', null));
        if (!$this->currentUser->isSuperAdmin()) {
            $requested = $this->currentUser->hotel_id ?? $requested;
        }

        return $requested === null || $requested === '' ? 0 : $this->strictPositiveOtaConfigHotelId($requested);
    }

    /**
     * @param array<string, mixed> $requestData
     */
    private function meituanRequestedHotelId(array $requestData): ?int
    {
        $values = [];
        foreach (['system_hotel_id', 'systemHotelId', 'hotel_id', 'hotelId'] as $key) {
            if (!array_key_exists($key, $requestData) || $requestData[$key] === null || $requestData[$key] === '') {
                continue;
            }
            $values[] = $this->strictPositiveOtaConfigHotelId($requestData[$key]);
        }
        $values = array_values(array_unique($values));
        if (count($values) > 1) {
            throw new \InvalidArgumentException('系统酒店绑定冲突');
        }

        return $values[0] ?? null;
    }
}
