<?php
declare(strict_types=1);

namespace app\controller;

use app\service\ManagerCapabilityScoringService;
use InvalidArgumentException;
use RuntimeException;
use think\Response;
use Throwable;

final class ManagerCapability extends Base
{
    private ManagerCapabilityScoringService $service;

    public function __construct(\think\App $app)
    {
        parent::__construct($app);
        $this->service = new ManagerCapabilityScoringService();
    }

    public function managers(): Response
    {
        try {
            [$tenantId, $hotelId] = $this->resolveSingleHotelScope('operation.view');
            return $this->success([
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'list' => $this->service->listManagers(
                    $tenantId,
                    $hotelId,
                    (int)($this->currentUser->id ?? 0)
                ),
                'permissions' => [
                    'can_manage_evidence' => $this->currentUser->hasHotelPermission($hotelId, 'operation.execute'),
                    'detail_policy' => '本人可看证据明细；具备运营执行权限者可管理当前门店案例；其他查看者只看汇总',
                ],
            ]);
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, '获取店长列表失败'), $this->statusCode($e));
        }
    }

    public function profile(): Response
    {
        try {
            [$tenantId, $hotelId] = $this->resolveSingleHotelScope('operation.view');
            $managerUserId = (int)$this->request->param('manager_user_id', 0);
            if ($managerUserId <= 0) {
                throw new InvalidArgumentException('请选择店长或负责人');
            }

            $permissions = $this->profilePermissions($hotelId, $managerUserId);
            $profile = $this->service->profile(
                $tenantId,
                $hotelId,
                $managerUserId,
                $this->nullableStringParam('date_from'),
                $this->nullableStringParam('date_to'),
                $permissions['can_view_evidence_detail']
            );
            $profile['permissions'] = $permissions;
            return $this->success($profile);
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, '获取店长能力档案失败'), $this->statusCode($e));
        }
    }

    public function readCase(int $id): Response
    {
        try {
            [$tenantId, $hotelId] = $this->resolveSingleHotelScope('operation.view');
            $managerUserId = (int)$this->request->param('manager_user_id', 0);
            if ($managerUserId <= 0) {
                throw new InvalidArgumentException('请选择店长或负责人');
            }
            $permissions = $this->profilePermissions($hotelId, $managerUserId);
            if (!$permissions['can_view_evidence_detail']) {
                throw new RuntimeException('无权查看该店长案例证据明细');
            }
            return $this->success($this->service->readCase(
                $tenantId,
                $hotelId,
                $managerUserId,
                $id
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, '读取店长评分案例失败'), $this->statusCode($e));
        }
    }

    public function createCase(): Response
    {
        try {
            $input = $this->requestData();
            [$tenantId, $hotelId] = $this->resolveSingleHotelScope(
                'operation.execute',
                (int)($input['hotel_id'] ?? $input['system_hotel_id'] ?? 0)
            );

            $result = $this->service->createCase(
                $tenantId,
                $hotelId,
                (int)($this->currentUser->id ?? 0),
                $input
            );
            $result['profile']['permissions'] = $this->profilePermissions(
                $hotelId,
                (int)($result['case']['manager_user_id'] ?? 0)
            );
            return $this->success($result, '店长评分案例已保存并完成回读');
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, '保存店长评分案例失败'), $this->statusCode($e));
        }
    }

    public function createFollowup(int $id): Response
    {
        try {
            $input = $this->requestData();
            [$tenantId, $hotelId] = $this->resolveSingleHotelScope(
                'operation.execute',
                (int)($input['hotel_id'] ?? $input['system_hotel_id'] ?? 0)
            );

            $result = $this->service->createFollowup(
                $tenantId,
                $hotelId,
                (int)($this->currentUser->id ?? 0),
                $id,
                $input
            );
            $result['profile']['permissions'] = $this->profilePermissions(
                $hotelId,
                (int)($result['case']['manager_user_id'] ?? 0)
            );
            return $this->success($result, '店长能力复查已追加并完成回读');
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, '追加店长能力复查失败'), $this->statusCode($e));
        }
    }

    public function followupQueue(): Response
    {
        try {
            [$tenantId, $hotelId] = $this->resolveSingleHotelScope('operation.execute');
            $managerUserId = (int)$this->request->param('manager_user_id', 0);
            return $this->success($this->service->followupQueue(
                $tenantId,
                $hotelId,
                $managerUserId
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, '获取待复查工作台失败'), $this->statusCode($e));
        }
    }

    public function createAdjustment(int $id): Response
    {
        try {
            $input = $this->requestData();
            [$tenantId, $hotelId] = $this->resolveSingleHotelScope(
                'operation.execute',
                (int)($input['hotel_id'] ?? $input['system_hotel_id'] ?? 0)
            );
            $result = $this->service->createAdjustment(
                $tenantId,
                $hotelId,
                (int)($this->currentUser->id ?? 0),
                $id,
                $input
            );
            $result['profile']['permissions'] = $this->profilePermissions(
                $hotelId,
                (int)($result['case']['manager_user_id'] ?? 0)
            );
            return $this->success($result, '店长能力案例修正已追加并完成回读');
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, '追加店长能力案例修正失败'), $this->statusCode($e));
        }
    }

    public function createScoreReview(int $id): Response
    {
        try {
            $input = $this->requestData();
            [$tenantId, $hotelId] = $this->resolveSingleHotelScope(
                'operation.execute',
                (int)($input['hotel_id'] ?? $input['system_hotel_id'] ?? 0)
            );
            $result = $this->service->createScoreReview(
                $tenantId,
                $hotelId,
                (int)($this->currentUser->id ?? 0),
                $id,
                $input
            );
            $result['profile']['permissions'] = $this->profilePermissions(
                $hotelId,
                (int)($result['case']['manager_user_id'] ?? 0)
            );
            return $this->success($result, '店长评分人工复核已追加并完成回读');
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, '追加店长评分人工复核失败'), $this->statusCode($e));
        }
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function resolveSingleHotelScope(string $capability, int $inputHotelId = 0): array
    {
        if (!$this->currentUser) {
            throw new RuntimeException('未登录');
        }

        $hotelId = $inputHotelId > 0
            ? $inputHotelId
            : (int)$this->request->param('hotel_id', $this->request->param('system_hotel_id', 0));
        if ($hotelId <= 0) {
            throw new InvalidArgumentException('请选择单个酒店');
        }

        $permittedHotelIds = array_values(array_filter(
            array_map('intval', $this->currentUser->getPermittedHotelIds()),
            static fn(int $id): bool => $id > 0
        ));
        if (!in_array($hotelId, $permittedHotelIds, true)
            || !$this->currentUser->hasHotelPermission($hotelId, $capability)
        ) {
            throw new RuntimeException(
                $capability === 'operation.execute'
                    ? '无权限保存该酒店店长评分案例'
                    : '无权查看该酒店店长能力档案'
            );
        }

        return [$this->service->hotelTenantId($hotelId), $hotelId];
    }

    private function nullableStringParam(string $key): ?string
    {
        $value = trim((string)$this->request->param($key, ''));
        return $value === '' ? null : $value;
    }

    /** @return array<string, bool|string> */
    private function profilePermissions(int $hotelId, int $managerUserId): array
    {
        $actorUserId = (int)($this->currentUser->id ?? 0);
        $canManage = $this->currentUser->hasHotelPermission($hotelId, 'operation.execute');
        $isSelf = $actorUserId > 0 && $actorUserId === $managerUserId;
        return [
            'is_self' => $isSelf,
            'can_view_evidence_detail' => $isSelf || $canManage,
            'can_manage_evidence' => $canManage,
            'privacy_scope' => ($isSelf || $canManage) ? 'evidence_detail' : 'aggregate_only',
            'policy' => '本人可看自己的证据明细；具备运营执行权限者可管理当前门店案例；其余查看者只看汇总',
        ];
    }

    private function statusCode(Throwable $e): int
    {
        if ($e instanceof InvalidArgumentException) {
            return 422;
        }

        $message = trim($e->getMessage());
        if ($message === '未登录') {
            return 401;
        }
        if (str_contains($message, '无权限')
            || str_contains($message, '无权')
            || str_contains($message, '不属于当前租户和酒店')
        ) {
            return 403;
        }
        if (str_contains($message, '不存在') || str_contains($message, '已停用')) {
            return 404;
        }
        if (str_contains($message, '数据表未就绪') || str_contains($message, '租户边界未就绪')) {
            return 503;
        }

        return 500;
    }

    private function safeErrorMessage(Throwable $e, string $fallback): string
    {
        $message = trim($e->getMessage());
        if ($message !== '' && preg_match('/[\x{4e00}-\x{9fff}]/u', $message) === 1) {
            return $message;
        }
        return $fallback;
    }
}
