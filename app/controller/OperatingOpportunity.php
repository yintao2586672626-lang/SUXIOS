<?php
declare(strict_types=1);

namespace app\controller;

use app\service\OperatingOpportunityLabService;
use InvalidArgumentException;
use RuntimeException;
use think\Response;
use Throwable;

final class OperatingOpportunity extends Base
{
    private OperatingOpportunityLabService $service;

    public function __construct(\think\App $app)
    {
        parent::__construct($app);
        $this->service = new OperatingOpportunityLabService();
    }

    public function overview(): Response
    {
        try {
            [$tenantId, $hotelId] = $this->resolveSingleHotelScope('operation.view');
            $businessDate = trim((string)$this->request->param('business_date', date('Y-m-d')));
            return $this->success($this->service->overview($tenantId, $hotelId, $businessDate));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '读取经营机会失败'), $this->statusCode($e));
        }
    }

    public function evaluate(): Response
    {
        try {
            $input = $this->requestData();
            [$tenantId, $hotelId] = $this->resolveSingleHotelScope(
                'operation.execute',
                (int)($input['hotel_id'] ?? $input['system_hotel_id'] ?? 0)
            );
            return $this->success($this->service->evaluateAndSave(
                $tenantId,
                $hotelId,
                (int)($this->currentUser->id ?? 0),
                $input
            ), '计算结果已保存并完成精确回读');
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '经营机会计算失败'), $this->statusCode($e));
        }
    }

    public function priority(): Response
    {
        try {
            $input = $this->requestData();
            [$tenantId, $hotelId] = $this->resolveSingleHotelScope(
                'operation.execute',
                (int)($input['hotel_id'] ?? $input['system_hotel_id'] ?? 0)
            );
            return $this->success($this->service->saveDailyPriority(
                $tenantId,
                $hotelId,
                (int)($this->currentUser->id ?? 0),
                (string)($input['business_date'] ?? ''),
                (string)($input['idempotency_key'] ?? '')
            ), '今日一件事已保存并完成精确回读');
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '生成今日一件事失败'), $this->statusCode($e));
        }
    }

    public function read(int $id): Response
    {
        try {
            [$tenantId, $hotelId] = $this->resolveSingleHotelScope('operation.view');
            return $this->success($this->service->readRun($tenantId, $hotelId, $id));
        } catch (Throwable $e) {
            return $this->error($this->safeMessage($e, '读取经营机会记录失败'), $this->statusCode($e));
        }
    }

    /** @return array{0:int,1:int} */
    private function resolveSingleHotelScope(string $capability, int $inputHotelId = 0): array
    {
        if (!$this->currentUser) throw new RuntimeException('未登录');
        $hotelId = $inputHotelId > 0
            ? $inputHotelId
            : (int)$this->request->param('hotel_id', $this->request->param('system_hotel_id', 0));
        if ($hotelId <= 0) throw new InvalidArgumentException('请选择单个酒店');
        $permitted = array_values(array_filter(array_map('intval', $this->currentUser->getPermittedHotelIds()), static fn(int $id): bool => $id > 0));
        if (!in_array($hotelId, $permitted, true) || !$this->currentUser->hasHotelPermission($hotelId, $capability)) {
            throw new RuntimeException($capability === 'operation.execute' ? '无权限保存该酒店经营机会结果' : '无权查看该酒店经营机会结果');
        }
        return [$this->service->hotelTenantId($hotelId), $hotelId];
    }

    private function statusCode(Throwable $e): int
    {
        if ($e instanceof InvalidArgumentException) return 422;
        $message = trim($e->getMessage());
        if ($message === '未登录') return 401;
        if (str_contains($message, '无权限') || str_contains($message, '无权')) return 403;
        if (str_contains($message, '不存在') || str_contains($message, '已停用')) return 404;
        if (str_contains($message, '数据表未就绪') || str_contains($message, '租户边界未就绪')) return 503;
        return 500;
    }

    private function safeMessage(Throwable $e, string $fallback): string
    {
        $message = trim($e->getMessage());
        return $message !== '' && preg_match('/[\x{4e00}-\x{9fff}]/u', $message) === 1 ? $message : $fallback;
    }
}
