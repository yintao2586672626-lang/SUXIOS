<?php
declare(strict_types=1);

namespace app\controller;

use app\service\CloudBrowserProfileService;
use RuntimeException;
use think\Response;

/** Authenticated customer entry points only. Browser gateway callbacks are not public routes. */
final class CloudBrowserAuthorization extends Base
{
    public function status(): Response
    {
        [$hotelId, $userId] = $this->authorizedScope();
        $platform = trim((string)$this->request->get('platform', ''));
        try {
            return $this->success((new CloudBrowserProfileService())->status($hotelId, $userId, $platform !== '' ? $platform : null));
        } catch (RuntimeException $e) {
            return $this->error('无法读取云端授权状态', 422, ['reason' => $e->getMessage()]);
        }
    }

    public function requestLogin(): Response
    {
        [$hotelId, $userId] = $this->authorizedScope();
        $input = $this->requestData();
        try {
            $entry = (new CloudBrowserProfileService())->requestLoginEntry($hotelId, $userId, (string)($input['platform'] ?? ''));
            return $this->success($entry, '云端授权入口已创建，请在15分钟内通过受保护登录窗口完成登录。');
        } catch (RuntimeException $e) {
            return $this->error('无法创建云端授权入口', 422, ['reason' => $e->getMessage()]);
        }
    }

    /** @return array{0:int,1:int} */
    private function authorizedScope(): array
    {
        if (!$this->currentUser) {
            abort(401, '请先登录');
        }
        $input = $this->requestData();
        $hotelId = (int)($this->request->get('hotel_id', 0) ?: ($input['hotel_id'] ?? 0));
        if ($hotelId <= 0) {
            abort(422, '请选择酒店');
        }
        if (!$this->currentUser->isSuperAdmin()
            && !$this->currentUser->hasHotelPermission($hotelId, 'can_fetch_online_data')) {
            abort(403, '无权管理该酒店的云端 OTA 授权');
        }
        return [$hotelId, (int)$this->currentUser->id];
    }
}
