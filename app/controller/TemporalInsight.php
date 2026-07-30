<?php
declare(strict_types=1);

namespace app\controller;

use app\service\TemporalInsightService;
use InvalidArgumentException;
use RuntimeException;
use think\App;
use think\Response;

final class TemporalInsight extends Base
{
    private TemporalInsightService $service;

    public function __construct(App $app, ?TemporalInsightService $service = null)
    {
        parent::__construct($app);
        $this->service = $service ?: new TemporalInsightService();
    }

    public function overview(): Response
    {
        try {
            $historyDays = (int)$this->request->get('history_days', 30);
            $futureDays = (int)$this->request->get('future_days', 7);
            $asOfDate = trim((string)$this->request->get('as_of_date', ''));
            $data = $this->service->overview(
                $this->resolveHotelIds(),
                $historyDays,
                $futureDays,
                $asOfDate !== '' ? $asOfDate : null
            );
            return $this->success($data, '统一时间视角获取成功');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('统一时间视角获取失败：' . $e->getMessage(), 500);
        }
    }

    public function generateForecast(): Response
    {
        try {
            $payload = $this->requestData();
            $hotelId = (int)($payload['hotel_id'] ?? $this->request->param('hotel_id', 0));
            if (!$this->canAccessHotel($hotelId)) {
                return $this->error('无权为该酒店生成预测版本。', 403);
            }
            $futureDays = (int)($payload['future_days'] ?? 7);
            $asOfDate = trim((string)($payload['as_of_date'] ?? ''));
            $userId = (int)($this->currentUser->id ?? 0);
            $data = $this->service->generateForecast(
                $hotelId,
                $userId,
                $asOfDate !== '' ? $asOfDate : null,
                $futureDays
            );
            $message = ($data['status'] ?? '') === 'generated'
                ? '预测版本已保存并回读'
                : '历史样本不足，未生成预测版本';
            return $this->success($data, $message);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (RuntimeException $e) {
            $code = (int)$e->getCode();
            return $this->error($e->getMessage(), $code >= 400 && $code <= 499 ? $code : 500);
        } catch (\Throwable $e) {
            return $this->error('预测版本生成失败：' . $e->getMessage(), 500);
        }
    }

    /** @return array<int, int> */
    private function resolveHotelIds(): array
    {
        if (!$this->currentUser) {
            return [0];
        }

        $hotelId = (int)$this->request->get('hotel_id', 0);
        $permittedIds = array_values(array_unique(array_filter(
            array_map('intval', $this->currentUser->getPermittedHotelIds()),
            static fn(int $id): bool => $id > 0
        )));
        if ($hotelId > 0) {
            if ($this->currentUser->isSuperAdmin() || in_array($hotelId, $permittedIds, true)) {
                return [$hotelId];
            }
            return [0];
        }
        if ($this->currentUser->isSuperAdmin()) {
            return [];
        }
        return $permittedIds !== [] ? $permittedIds : [0];
    }

    private function canAccessHotel(int $hotelId): bool
    {
        if ($hotelId <= 0 || !$this->currentUser) {
            return false;
        }
        if ($this->currentUser->isSuperAdmin()) {
            return true;
        }
        return in_array($hotelId, array_map('intval', $this->currentUser->getPermittedHotelIds()), true);
    }
}
