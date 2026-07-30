<?php
declare(strict_types=1);

namespace app\controller;

use app\service\InvestmentDecisionSupportService;
use RuntimeException;
use think\Response;
use Throwable;

class InvestmentDecision extends Base
{
    private InvestmentDecisionSupportService $service;

    public function __construct(\think\App $app)
    {
        parent::__construct($app);
        $this->service = new InvestmentDecisionSupportService();
    }

    public function overview(): Response
    {
        try {
            [$hotelIds, $hotelId] = $this->resolveHotelScope((int)$this->request->param('hotel_id', 0));

            return $this->success($this->service->overview(
                $hotelIds,
                $hotelId,
                (int)($this->currentUser->id ?? 0),
                $this->currentUser ? $this->currentUser->isSuperAdmin() : false
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, '投资决策辅助数据读取失败'), $this->statusCode($e));
        }
    }

    private function resolveHotelScope(int $inputHotelId = 0): array
    {
        if (!$this->currentUser) {
            throw new RuntimeException('未登录');
        }

        $hotelId = $inputHotelId > 0 ? $inputHotelId : (int)$this->request->param('hotel_id', 0);
        $permitted = array_values(array_map('intval', $this->currentUser->getPermittedHotelIds()));
        if (!$this->currentUser->isSuperAdmin()) {
            $permitted = array_values(array_filter(
                $permitted,
                fn(int $candidateHotelId): bool => $this->currentUser->hasHotelPermission(
                    $candidateHotelId,
                    'can_use_investment'
                )
            ));
        }
        if (empty($permitted)) {
            throw new RuntimeException('暂无可访问酒店');
        }

        if ($hotelId > 0) {
            if (!in_array($hotelId, $permitted, true)) {
                throw new RuntimeException('无权查看该酒店数据');
            }
            return [[$hotelId], $hotelId];
        }

        return [$permitted, count($permitted) === 1 ? $permitted[0] : null];
    }

    private function statusCode(Throwable $e): int
    {
        $message = trim($e->getMessage());
        if ($message === '未登录') {
            return 401;
        }
        if (in_array($message, ['暂无可访问酒店', '无权查看该酒店数据'], true)) {
            return 403;
        }
        if ($e instanceof \InvalidArgumentException) {
            return 400;
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
