<?php
declare(strict_types=1);

namespace app\controller;

use app\service\BookingMonitoringContextService;
use app\service\OperatingWorkbenchMetricsService;
use app\service\OperatingWorkbenchService;
use app\service\OperatingWorkbenchSnapshotService;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;
use think\Response;
use Throwable;

final class OperatingWorkbench extends Base
{
    public function overview(): Response
    {
        return $this->run(function (): array {
            [$tenant, $permitted, $ids] = $this->scope($this->request->param(), 'operation.view', true);
            return (new OperatingWorkbenchService())->overview($tenant, $permitted, $ids, (string)$this->request->param('period_month', ''), (string)$this->request->param('business_date', ''));
        });
    }
    public function report(): Response
    {
        return $this->run(function (): array {
            [$tenant, $permitted, $ids] = $this->scope($this->request->param(), 'operation.view');
            return (new OperatingWorkbenchService())->report($tenant, $permitted, $ids[0], (string)$this->request->param('period_end', ''));
        });
    }
    public function cases(): Response
    {
        return $this->run(function (): array {
            [$tenant, $permitted, $ids] = $this->scope($this->request->param(), 'operation.view');
            (new OperatingWorkbenchSnapshotService())->scope($tenant, $permitted, $ids[0], 'appeal_scopecheck');
            return ['tenant_id' => $tenant, 'hotel_id' => $ids[0], 'cases' => (new OperatingWorkbenchSnapshotService())->cases($tenant, $ids[0]), 'external_write_count' => 0];
        });
    }
    public function booking(): Response
    {
        return $this->run(function (): array {
            [$tenant, $permitted, $ids] = $this->scope($this->request->param(), 'operation.view', true);
            return (new BookingMonitoringContextService())->overview($tenant, $permitted, $ids, $this->request->param());
        });
    }
    public function save(): Response
    {
        return $this->run(function (): array {
            $input = $this->requestData(); [$tenant, $permitted, $ids] = $this->scope($input, 'operation.execute');
            if (($input['type'] ?? '') === 'booking') {
                $body = (new BookingMonitoringContextService())->normalize((array)($input['inputs'] ?? []), $ids[0]);
                $store = new OperatingWorkbenchSnapshotService();
                return $store->save($store->scope($tenant, $permitted, $ids[0], 'booking_' . $body['business_date']), $body, (int)$this->currentUser->id,
                    (string)($input['idempotency_key'] ?? ''), (int)($input['expected_snapshot_id'] ?? 0));
            }
            return (new OperatingWorkbenchService())->save($tenant, $permitted, $ids[0], (int)$this->currentUser->id, $input);
        });
    }
    public function read(): Response
    {
        return $this->run(function (): array {
            [$tenant, $permitted, $ids] = $this->scope($this->request->param(), 'operation.view');
            $store = new OperatingWorkbenchSnapshotService();
            return $store->read($store->scope($tenant, $permitted, $ids[0], (string)$this->request->param('kind', '')), (int)$this->request->param('id', 0));
        });
    }
    private function scope(array $input, string $capability, bool $multiple = false): array
    {
        if (!$this->currentUser) throw new RuntimeException('workbench_login_required', 401);
        $raw = $multiple ? ($input['hotel_ids'] ?? '') : [$input['hotel_id'] ?? 0];
        if (is_string($raw)) $raw = explode(',', $raw);
        if (!is_array($raw) || $raw === [] || count($raw) > 20) throw new InvalidArgumentException('workbench_hotel_selection_invalid');
        $ids = [];
        foreach ($raw as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT);
            if ($id === false || $id <= 0 || in_array($id, $ids, true)) throw new InvalidArgumentException('workbench_hotel_selection_invalid');
            $ids[] = $id;
        }
        $permitted = array_map('intval', (array)$this->currentUser->getPermittedHotelIds()); $tenant = (int)($this->currentUser->tenant_id ?? 0);
        foreach ($ids as $hotel) {
            if (!in_array($hotel, $permitted, true) || !$this->currentUser->hasHotelPermission($hotel, $capability)) throw new RuntimeException('workbench_forbidden', 403);
            $hotelTenant = (int)Db::name('hotels')->where('id', $hotel)->value('tenant_id');
            if (method_exists($this->currentUser, 'isSuperAdmin') && $this->currentUser->isSuperAdmin() && $tenant <= 0) $tenant = $hotelTenant;
            if ($hotelTenant !== $tenant || $tenant <= 0) throw new RuntimeException('workbench_tenant_mismatch', 403);
        }
        return [$tenant, $permitted, $ids];
    }
    private function run(callable $callback): Response
    {
        try { return $this->success($callback()); }
        catch (Throwable $error) {
            $code = (int)$error->getCode();
            if (!in_array($code, [401, 403, 404, 409, 422], true)) $code = $error instanceof InvalidArgumentException ? 422 : 500;
            $reason = preg_match('/^workbench_[a-z0-9_]+$/D', $error->getMessage()) ? $error->getMessage() : 'workbench_unavailable';
            return $this->error(match ($code) { 401 => '请先登录', 403 => '门店或租户超出权限', 409 => '版本或证据已变化，请重新读取',
                422 => '请检查日期、口径、字段及所需证据', 404 => '当前范围没有该版本', default => '读取或保存失败，请重试并检查数据服务' }, $code,
                ['status' => 'error', 'reason_code' => $reason, 'external_write_count' => 0]);
        }
    }
}
