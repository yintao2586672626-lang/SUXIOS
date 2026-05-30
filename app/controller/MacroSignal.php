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

    public function trends(): Response
    {
        try {
            $range = (string)$this->request->get('range', '30');
            $startDate = (string)$this->request->get('start_date', '');
            $endDate = (string)$this->request->get('end_date', '');
            return $this->success(
                $this->service->trendOverview($this->resolveHotelIds(), $range, $startDate, $endDate),
                '经营趋势获取成功'
            );
        } catch (\Throwable $e) {
            return $this->error('经营趋势获取失败: ' . $e->getMessage(), 500);
        }
    }


    public function external(): Response
    {
        try {
            $type = (string)$this->request->get('type', 'weather');
            $city = (string)$this->request->get('city', '');
            $keywords = (string)$this->request->get('keywords', 'hotel');
            $external = new \app\service\ExternalSignalService();
            $data = $type === 'poi'
                ? $external->amapPoi($keywords, $city)
                : $external->amapWeather($city);
            return $this->success($data, 'success');
        } catch (\Throwable $e) {
            return $this->error('external signal failed: ' . $e->getMessage(), 500);
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
            if ($this->currentUser->isSuperAdmin()) {
                return [$hotelId];
            }
            return in_array($hotelId, $permittedIds, true) ? [$hotelId] : [0];
        }

        if (!$this->currentUser->isSuperAdmin() && empty($permittedIds)) {
            return [0];
        }

        return $this->currentUser->isSuperAdmin() ? [] : $permittedIds;
    }
}
