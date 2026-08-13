<?php
declare(strict_types=1);

namespace app\controller;

use app\service\HotelScopeService;
use app\service\OperatingLoopCoordinatorService;
use app\service\OperatingLoopKernelService;
use app\service\PermissionService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use think\facade\Db;
use think\Response;

/**
 * Authenticated HTTP boundary for the single authoritative operating loop.
 *
 * Tenant, actor, actor kind and source module are always derived server-side.
 * A client can select only a hotel/date inside its granted scope and provide
 * the stage payload/evidence required by the kernel.
 */
final class OperatingLoop extends Base
{
    public function current(): Response
    {
        return $this->respond(function (): array {
            $hotelId = $this->positiveInt($this->request->param('hotel_id'));
            $businessDate = trim((string)$this->request->param('business_date', ''));
            $scope = $this->authorizedScope($hotelId, 'operation.view');

            return (new OperatingLoopKernelService())->currentForHotelDate(
                $scope['tenant_id'],
                $scope['hotel_id'],
                $businessDate
            );
        });
    }

    public function open(): Response
    {
        return $this->respond(function (): array {
            $input = $this->requestData();
            $hotelId = $this->positiveInt($input['hotel_id'] ?? null);
            $scope = $this->authorizedScope($hotelId, 'operation.execute');

            return (new OperatingLoopCoordinatorService())->reconcile(
                $scope['tenant_id'],
                $scope['hotel_id'],
                trim((string)($input['business_date'] ?? '')),
                $scope['actor_id'],
                (int)($input['max_transitions'] ?? 8)
            );
        });
    }

    public function reconcile(): Response
    {
        return $this->respond(function (): array {
            $input = $this->requestData();
            $hotelId = $this->positiveInt($input['hotel_id'] ?? null);
            $scope = $this->authorizedScope($hotelId, 'operation.execute');

            return (new OperatingLoopCoordinatorService())->reconcile(
                $scope['tenant_id'],
                $scope['hotel_id'],
                trim((string)($input['business_date'] ?? '')),
                $scope['actor_id'],
                (int)($input['max_transitions'] ?? 8)
            );
        });
    }

    public function read(int $id): Response
    {
        return $this->respond(function () use ($id): array {
            $hotelId = $this->positiveInt($this->request->param('hotel_id'));
            $scope = $this->authorizedScope($hotelId, 'operation.view');

            return (new OperatingLoopKernelService())->readVerified(
                $id,
                $scope['tenant_id'],
                [$scope['hotel_id']]
            );
        });
    }

    public function transition(int $id): Response
    {
        return $this->respond(function () use ($id): array {
            $input = $this->requestData();
            $hotelId = $this->positiveInt($input['hotel_id'] ?? null);
            $scope = $this->authorizedScope($hotelId, 'operation.execute');
            $cycle = (new OperatingLoopKernelService())->readVerified(
                $id,
                $scope['tenant_id'],
                [$scope['hotel_id']]
            );

            return (new OperatingLoopCoordinatorService())->reconcile(
                $scope['tenant_id'],
                $scope['hotel_id'],
                trim((string)($cycle['business_date'] ?? '')),
                $scope['actor_id'],
                (int)($input['max_transitions'] ?? 8)
            );
        });
    }

    /** @return array{tenant_id:int,hotel_id:int,actor_id:int} */
    private function authorizedScope(int $hotelId, string $capability): array
    {
        if (!$this->currentUser) {
            throw new RuntimeException('未登录');
        }
        if ($hotelId <= 0) {
            throw new InvalidArgumentException('hotel_id 必须是有效酒店');
        }

        $hotelScope = new HotelScopeService();
        $authorization = (new PermissionService($hotelScope))->authorize(
            $this->currentUser,
            $capability,
            $hotelId
        );
        if (($authorization['allowed'] ?? false) !== true) {
            throw new RuntimeException('无权访问该酒店经营闭环：' . (string)($authorization['reason'] ?? 'denied'));
        }

        $hotel = Db::name('hotels')
            ->where('id', $hotelId)
            ->where('status', 1)
            ->field(['id', 'tenant_id'])
            ->find();
        if (!is_array($hotel) || (int)($hotel['tenant_id'] ?? 0) <= 0) {
            throw new RuntimeException('指定酒店不存在、已停用或租户身份缺失');
        }
        $tenantId = (int)$hotel['tenant_id'];
        if (!$this->currentUser->isSuperAdmin()
            && (int)($this->currentUser->tenant_id ?? 0) !== $tenantId
        ) {
            throw new RuntimeException('无权跨租户访问经营闭环');
        }

        return [
            'tenant_id' => $tenantId,
            'hotel_id' => (int)$hotel['id'],
            'actor_id' => (int)($this->currentUser->id ?? 0),
        ];
    }

    private function respond(callable $callback): Response
    {
        try {
            return $this->success($callback());
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
            $status = preg_match('/冲突|跳级|已完成|command_key|并行闭环|另一份权威口径/u', $message) === 1 ? 409 : 422;
            return $this->error($message, $status);
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            $status = match (true) {
                str_contains($message, '未登录') => 401,
                str_contains($message, '无权') || str_contains($message, '跨租户') => 403,
                str_contains($message, '不存在') => 404,
                str_contains($message, '表未就绪') || str_contains($message, 'schema') => 503,
                str_contains($message, '冲突'), str_contains($message, '漂移'), str_contains($message, '摘要校验') => 409,
                default => 500,
            };
            return $this->error($message, $status);
        } catch (Throwable $e) {
            return $this->error('经营闭环服务暂不可用', 500);
        }
    }

    private function positiveInt($value): int
    {
        return is_numeric($value) && (int)$value > 0 ? (int)$value : 0;
    }
}
