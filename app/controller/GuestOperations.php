<?php
declare(strict_types=1);

namespace app\controller;

use app\service\GuestOperationsService;
use app\service\HotelScopeService;
use InvalidArgumentException;
use RuntimeException;
use think\Response;
use Throwable;

/** All routes must remain inside the existing Auth middleware group. */
final class GuestOperations extends Base
{
    public function overview(): Response
    {
        return $this->run(false, fn($service, $tenant, $ids, $hotel): array => $service->overview($tenant, $ids, $hotel,
            (string)$this->request->param('date_start', ''), (string)$this->request->param('date_end', ''), (string)$this->request->param('platform', '')));
    }
    public function importStays(): Response { return $this->save('importStays'); }
    public function saveCoverage(): Response { return $this->save('saveCoverage'); }
    public function saveFeedback(): Response { return $this->save('saveFeedback'); }
    public function saveEntry(): Response { return $this->save('saveEntry'); }
    public function appendFact(string $caseKey): Response
    {
        return $this->run(true, fn($service, $tenant, $ids, $hotel): array => $service->appendFeedbackFact($tenant, $ids, $hotel, (int)$this->currentUser->id, $caseKey, $this->payload()));
    }
    public function read(int $recordId): Response
    {
        return $this->run(false, fn($service, $tenant, $ids, $hotel): array => $service->read($tenant, $ids, $hotel, $recordId));
    }
    public function history(): Response
    {
        return $this->run(false, fn($service, $tenant, $ids, $hotel): array => ['records' => $service->history($tenant, $ids, $hotel, (string)$this->request->param('kind', ''), (string)$this->request->param('record_key', ''))]);
    }
    private function save(string $method): Response
    {
        return $this->run(true, fn($service, $tenant, $ids, $hotel): array => $service->{$method}($tenant, $ids, $hotel, (int)$this->currentUser->id, $this->payload()));
    }
    private function payload(): array
    {
        $input = $this->requestData(); unset($input['hotel_id']);
        return $input;
    }
    private function run(bool $write, callable $operation): Response
    {
        try {
            if (!$this->currentUser) throw new RuntimeException('未登录', 401);
            $payload = $this->requestData();
            $hotel = (int)$this->request->param('hotel_id', $payload['hotel_id'] ?? 0);
            $capability = $write ? 'operation.execute' : 'operation.view';
            $scope = new HotelScopeService();
            $ids = $scope->accessibleHotelIds($this->currentUser, $capability);
            if (!$this->currentUser->hasHotelPermission($hotel, $capability)) throw new RuntimeException('无此酒店运营权限', 403);
            $tenant = $this->currentUser->isSuperAdmin() ? 0 : (int)$this->currentUser->tenant_id;
            if (!$this->currentUser->isSuperAdmin() && $tenant <= 0) throw new RuntimeException('租户上下文缺失', 403);
            return $this->success($operation(new GuestOperationsService(), $tenant, $ids, $hotel), $write ? '已保存并精确回读' : '已读取');
        } catch (Throwable $error) {
            $status = $error instanceof InvalidArgumentException ? 422 : (int)$error->getCode();
            if (!in_array($status, [401, 403, 404, 409, 422, 503], true)) return $this->error('宾客运营请求失败，请检查服务和存储后重试', 500);
            return $this->error($error->getMessage(), $status);
        }
    }
}
