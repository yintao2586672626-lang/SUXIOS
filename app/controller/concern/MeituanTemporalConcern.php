<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\service\MeituanTemporalService;
use think\Response;

trait MeituanTemporalConcern
{
    public function meituanTemporalSummary(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_view_online_data');
        try {
            [$systemHotelId, $asOfDate] = $this->meituanTemporalRequestContext(
                array_merge($this->request->get(), $this->requestData())
            );
            return $this->success(
                (new MeituanTemporalService())->summary($this->currentUser, $systemHotelId, $asOfDate)
            );
        } catch (\Throwable $e) {
            $code = (int)$e->getCode();
            return $this->error(
                $e->getMessage(),
                in_array($code, [403, 404, 409, 422], true) ? $code : 500,
                ['status_code' => 'meituan_temporal_summary_failed']
            );
        }
    }

    public function meituanTemporalRefresh(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_fetch_online_data');
        try {
            [$systemHotelId, $asOfDate] = $this->meituanTemporalRequestContext(
                array_merge($this->request->get(), $this->requestData())
            );
            return $this->success(
                (new MeituanTemporalService())->refresh($this->currentUser, $systemHotelId, $asOfDate)
            );
        } catch (\Throwable $e) {
            $code = (int)$e->getCode();
            return $this->error(
                $e->getMessage(),
                in_array($code, [403, 404, 409, 422], true) ? $code : 500,
                ['status_code' => 'meituan_temporal_refresh_failed']
            );
        }
    }

    /** @return array{0:int,1:string} */
    private function meituanTemporalRequestContext(array $requestData): array
    {
        $rawHotelId = trim((string)(
            $requestData['system_hotel_id']
            ?? $requestData['systemHotelId']
            ?? ''
        ));
        if ($rawHotelId === '' || !ctype_digit($rawHotelId) || (int)$rawHotelId <= 0) {
            throw new \RuntimeException('system_hotel_id is required.', 422);
        }
        $asOfDate = trim((string)(
            $requestData['as_of_date']
            ?? $requestData['asOfDate']
            ?? date('Y-m-d')
        ));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $asOfDate) !== 1) {
            throw new \RuntimeException('Invalid as_of_date.', 422);
        }
        return [(int)$rawHotelId, $asOfDate];
    }
}
