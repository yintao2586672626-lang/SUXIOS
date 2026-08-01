<?php
declare(strict_types=1);

namespace app\controller\concern;

use app\service\CtripCollectorWorkflowService;
use app\service\CtripTemporalNotificationPayloadService;
use think\facade\Db;
use think\Response;

trait CtripCollectorWorkflowConcern
{
    public function ctripCollectorContract(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_view_online_data');

        $query = $this->request->get();
        $sourceId = (int)($query['source_id'] ?? $query['sourceId'] ?? 0);
        $source = [];
        if ($sourceId > 0) {
            $row = Db::name('platform_data_sources')
                ->field('id,system_hotel_id,platform,status,enabled,config_json')
                ->where('id', $sourceId)
                ->where('platform', 'ctrip')
                ->find();
            if (!$row || !$this->canAccessCtripCollectorSource($row)) {
                return $this->error('Ctrip data source not found.', 404);
            }
            $config = json_decode((string)($row['config_json'] ?? ''), true);
            $row['config'] = is_array($config) ? $config : [];
            $source = $row;
        }

        $contract = (new CtripCollectorWorkflowService())->buildContract($source, $query);
        return $this->success($contract, 'Ctrip collector contract loaded.');
    }

    public function ctripTemporalBroadcastPreview(): Response
    {
        $this->checkPermission();
        $this->checkActionPermission('can_view_online_data');

        $requestData = array_merge($this->request->get(), $this->requestData());
        $hotelId = (int)(
            $requestData['system_hotel_id']
            ?? $requestData['systemHotelId']
            ?? 0
        );
        if ($hotelId <= 0) {
            return $this->error('system_hotel_id is required.', 422);
        }
        if (!$this->currentUserCanViewOnlineDataHotel($hotelId)) {
            return $this->error('Forbidden.', 403);
        }

        try {
            $hotel = Db::name('hotels')
                ->field('id,tenant_id,name,status')
                ->where('id', $hotelId)
                ->where('status', 1)
                ->find();
            $hotelName = trim((string)($hotel['name'] ?? ''));
            if ($hotelName === '') {
                return $this->error('Target hotel not found.', 404);
            }
            $asOfDate = trim((string)(
                $requestData['as_of_date']
                ?? $requestData['asOfDate']
                ?? date('Y-m-d')
            ));
            $tenantId = (int)($hotel['tenant_id'] ?? 0);
            $baselineOnly = filter_var(
                $requestData['baseline_only'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            );
            return $this->success(
                (new CtripTemporalNotificationPayloadService())->broadcastPreview(
                    $tenantId,
                    $hotelId,
                    $hotelName,
                    $asOfDate,
                    (string)($requestData['message_mode'] ?? 'daily'),
                    (string)($requestData['previous_fingerprint'] ?? ''),
                    $baselineOnly
                ),
                'Ctrip temporal broadcast preview generated.'
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error(
                'Ctrip temporal broadcast preview failed: ' . $e->getMessage(),
                500
            );
        }
    }

    /** @param array<string, mixed> $source */
    private function canAccessCtripCollectorSource(array $source): bool
    {
        $hotelId = (int)($source['system_hotel_id'] ?? 0);
        return strtolower(trim((string)($source['platform'] ?? ''))) === 'ctrip'
            && $hotelId > 0
            && $this->currentUserCanViewOnlineDataHotel($hotelId);
    }
}
