<?php
declare(strict_types=1);

namespace app\controller;

use app\service\WechatNotificationBindingService;
use think\Response;

final class WechatNotificationOnboarding extends Base
{
    public function status(): Response
    {
        [$hotelId, $userId] = $this->authorizedScope();
        return $this->success((new WechatNotificationBindingService())->status($hotelId, $userId));
    }

    public function bind(): Response
    {
        [$hotelId, $userId] = $this->authorizedScope();
        $input = $this->requestData();
        try {
            $binding = (new WechatNotificationBindingService())->bind(
                $hotelId,
                $userId,
                (string)($input['name'] ?? '宿析通知群'),
                (string)($input['webhook'] ?? '')
            );
        } catch (\InvalidArgumentException) {
            return $this->error('请输入有效的企业微信群机器人 Webhook', 422);
        }
        return $this->success(['binding' => $binding], '企业微信通知已绑定，请发送测试消息确认');
    }

    public function test(): Response
    {
        [$hotelId, $userId] = $this->authorizedScope();
        try {
            $result = (new WechatNotificationBindingService())->test($hotelId, $userId);
        } catch (\RuntimeException) {
            return $this->error('当前账户尚未绑定可用的企业微信通知群', 404);
        }
        $status = (string)($result['delivery']['delivery_status'] ?? 'failed');
        return $status === 'sent'
            ? $this->success($result, '测试消息已发送')
            : $this->error('测试消息未成功送达，请检查机器人配置', 502, $result);
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
            abort(422, '请选择要绑定的门店');
        }
        if (!$this->currentUser->isSuperAdmin()
            && !$this->currentUser->hasHotelPermission($hotelId, 'can_fetch_online_data')) {
            abort(403, '无权绑定该门店的企业微信通知');
        }
        return [$hotelId, (int)$this->currentUser->id];
    }
}
