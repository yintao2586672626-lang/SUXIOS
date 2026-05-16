<?php
declare(strict_types=1);

namespace app\controller;

use app\service\MacroSignalService;
use InvalidArgumentException;
use think\App;
use think\Response;

class MacroSignal extends Base
{
    private MacroSignalService $service;

    public function __construct(App $app, ?MacroSignalService $service = null)
    {
        parent::__construct($app);
        $this->service = $service ?: new MacroSignalService();
    }

    public function overview(): Response
    {
        try {
            return $this->success($this->service->overview($this->resolveHotelIds()), '宏观经营信号获取成功');
        } catch (\Throwable $e) {
            return $this->error('宏观经营信号获取失败: ' . $e->getMessage(), 500);
        }
    }

    public function detail(): Response
    {
        try {
            $type = (string)$this->request->get('type', '');
            return $this->success($this->service->detail($type, $this->resolveHotelIds()), '宏观经营信号详情获取成功');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('宏观经营信号详情获取失败: ' . $e->getMessage(), 500);
        }
    }

    private function resolveHotelIds(): array
    {
        if (!$this->currentUser) {
            return [];
        }

        $hotelId = (int)$this->request->get('hotel_id', 0);
        $permittedIds = array_map('intval', $this->currentUser->getPermittedHotelIds());

        if ($hotelId > 0) {
            return in_array($hotelId, $permittedIds, true) ? [$hotelId] : [0];
        }

        if (!$this->currentUser->isSuperAdmin() && empty($permittedIds)) {
            return [0];
        }

        return $this->currentUser->isSuperAdmin() ? [] : $permittedIds;
    }
}
