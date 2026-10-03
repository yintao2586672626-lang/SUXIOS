<?php
declare(strict_types=1);

namespace app\controller;

use app\service\BookingMonitoringService;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;
use think\Response;
use Throwable;

final class BookingMonitoring extends Base
{
    public function overview(): Response
    {
        try {
            $ids = $this->hotelIds($this->request->param('hotel_ids', ''));
            [$tenantId, $permitted] = $this->scope($ids, 'operation.view');
            $overview = (new BookingMonitoringService())->overview($tenantId, $permitted, $ids, [
                'platform' => $this->request->param('platform', 'ctrip'),
                'business_date' => $this->request->param('business_date', date('Y-m-d')),
                'fixed_time' => $this->request->param('fixed_time', '09:00'),
                'horizon_days' => $this->request->param('horizon_days', 7),
            ]);
            foreach ($overview['selectable_hotels'] as &$hotel) {
                $hotel['can_execute'] = $this->currentUser->hasHotelPermission((int)$hotel['id'], 'operation.execute');
            }
            unset($hotel);
            return $this->success($overview);
        } catch (Throwable $error) {
            return $this->failure($error, '固定基线预订读取失败');
        }
    }

    public function saveSnapshots(): Response
    {
        try {
            $input = $this->requestData();
            $rows = $input['rows'] ?? null;
            if (!is_array($rows) || !array_is_list($rows) || $rows === [] || count($rows) > 200) throw new InvalidArgumentException('booking_monitor_import_requires_1_to_200_rows');
            foreach ($rows as $row) if (!is_array($row)) throw new InvalidArgumentException('booking_monitor_row_invalid');
            $ids = array_values(array_unique(array_map(static fn(array $row): int => (int)($row['hotel_id'] ?? 0), $rows)));
            [$tenantId, $permitted] = $this->scope($ids, 'operation.execute');
            $saved = (new BookingMonitoringService())->saveSnapshots($tenantId, $permitted, $rows, (int)$this->currentUser->id);
            return $this->success($saved, '快照已保存并精确回读；人工确认与平台身份核验分别保留');
        } catch (Throwable $error) {
            return $this->failure($error, '预订快照保存失败，整批未完成');
        }
    }

    public function readSnapshot(): Response
    {
        try {
            $hotelId = (int)$this->request->param('hotel_id', 0);
            [$tenantId, $permitted] = $this->scope([$hotelId], 'operation.view');
            return $this->success((new BookingMonitoringService())->readSnapshot($tenantId, $permitted, $hotelId, (int)$this->request->param('id', 0)));
        } catch (Throwable $error) {
            return $this->failure($error, '预订快照回读失败');
        }
    }

    private function hotelIds(mixed $value): array
    {
        $values = is_array($value) ? $value : explode(',', (string)$value);
        if ($values === [] || count($values) > 20) throw new InvalidArgumentException('booking_monitor_requires_1_to_20_same_tenant_hotels');
        $ids = [];
        foreach ($values as $id) {
            $parsed = filter_var($id, FILTER_VALIDATE_INT);
            if ($parsed === false || $parsed <= 0) throw new InvalidArgumentException('booking_monitor_hotel_scope_required');
            $ids[] = $parsed;
        }
        return array_values(array_unique($ids));
    }

    private function scope(array $ids, string $capability): array
    {
        if (!$this->currentUser) throw new RuntimeException('booking_monitor_login_required', 401);
        if ($ids === [] || count($ids) > 20 || min($ids) <= 0) throw new InvalidArgumentException('booking_monitor_hotel_scope_required');
        $permitted = array_map('intval', (array)$this->currentUser->getPermittedHotelIds());
        foreach ($ids as $hotelId) {
            if (!in_array($hotelId, $permitted, true) || !$this->currentUser->hasHotelPermission($hotelId, $capability)) throw new RuntimeException('booking_monitor_hotel_outside_permitted_scope', 403);
        }
        $hotels = Db::name('hotels')->whereIn('id', $ids)->field('id,tenant_id')->select()->toArray();
        $tenants = array_values(array_unique(array_map(static fn(array $hotel): int => (int)$hotel['tenant_id'], $hotels)));
        if (count($hotels) !== count($ids) || count($tenants) !== 1 || $tenants[0] <= 0) throw new RuntimeException('booking_monitor_hotel_tenant_scope_mismatch', 403);
        $permitted = array_values(array_filter($permitted, fn(int $hotelId): bool => $this->currentUser->hasHotelPermission($hotelId,$capability)));
        return [$tenants[0], $permitted];
    }

    private function failure(Throwable $error, string $fallback): Response
    {
        $code = (int)$error->getCode();
        if (!in_array($code, [401, 403, 404, 409, 422], true)) $code = $error instanceof InvalidArgumentException ? 422 : 500;
        $reason = $error->getMessage();
        $messages = [
            'booking_monitor_login_required' => '请先登录宿析OS',
            'booking_monitor_hotel_outside_permitted_scope' => '选择或导入的酒店超出当前账号权限',
            'booking_monitor_hotel_tenant_scope_mismatch' => '请选择同一租户内的授权酒店',
            'booking_monitor_room_type_outside_hotel' => '房型不属于当前酒店，请检查房型ID',
            'booking_monitor_snapshot_not_found' => '当前酒店没有这条快照',
            'booking_monitor_idempotency_conflict' => '导入标识已用于不同内容，请更正标识或追加更正快照',
            'booking_monitor_snapshot_limit_narrow_scope' => '快照量超出本次读取上限，请缩小酒店或日期范围',
            'booking_monitor_cell_limit_narrow_scope' => '展示规模超过1,000格，请减少酒店或展示天数后重试',
            'booking_monitor_import_requires_1_to_200_rows' => '每次须导入1至200条快照',
            'booking_monitor_correction_scope_mismatch' => '更正快照须保留原酒店、平台、入住日、捕获时点和指标范围',
            'booking_monitor_correction_room_type_mismatch' => '更正快照须保留原房型',
            'on_books_snapshot_captured_at_future' => '捕获时间不能晚于当前上海时间',
            'on_books_snapshot_after_stay_date' => '快照不能晚于目标入住日',
            'on_books_room_nights_required' => '在手间夜缺失，不能按0保存',
            'on_books_room_nights_out_of_range' => '在手间夜超出可精确保存上限（9,999,999,999.9999），请检查单位和数量',
            'on_books_room_revenue_out_of_range' => '在手房费超出可精确保存上限（9,999,999,999.9999元），请检查金额单位',
            'cumulative_cancel_room_nights_out_of_range' => '累计取消间夜超出可精确保存上限（9,999,999,999.9999），请检查单位和数量',
            'gross_booking_room_nights_out_of_range' => '累计毛预订间夜超出可精确保存上限（9,999,999,999.9999），请检查单位和数量',
            'on_books_snapshot_source_ref_invalid' => '请填写来源引用或文件指纹',
        ];
        $safeReason = preg_match('/^[a-z][a-z0-9_]{3,100}$/D', $reason) ? $reason : 'booking_monitor_request_failed';
        return $this->error($messages[$reason] ?? $fallback, $code, ['contract_version' => BookingMonitoringService::CONTRACT,
            'status' => 'error', 'reason_code' => $safeReason, 'readback_verified' => false, 'external_write_count' => 0]);
    }
}
