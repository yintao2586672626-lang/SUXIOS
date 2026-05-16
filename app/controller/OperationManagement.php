<?php
declare(strict_types=1);

namespace app\controller;

use app\service\OperationManagementService;
use think\Response;
use Throwable;

class OperationManagement extends Base
{
    private OperationManagementService $service;

    public function __construct(\think\App $app)
    {
        parent::__construct($app);
        $this->service = new OperationManagementService();
    }

    public function fullData(): Response
    {
        try {
            [$hotelIds, $hotelId] = $this->resolveHotelScope();
            $date = $this->normalizeDate((string)$this->request->param('date', date('Y-m-d')));

            return $this->success($this->service->fullData($hotelIds, $hotelId, $date));
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, '获取全维数据失败'), 400);
        }
    }

    public function rootCause(): Response
    {
        try {
            $input = $this->request->post();
            [$hotelIds, $hotelId] = $this->resolveHotelScope((int)($input['hotel_id'] ?? 0));
            $date = $this->normalizeDate((string)($input['date'] ?? date('Y-m-d')));
            $problemType = trim((string)($input['problem_type'] ?? ''));

            return $this->success($this->service->rootCause($hotelIds, $hotelId, $date, $problemType));
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, '根因定位失败'), 400);
        }
    }

    public function alerts(): Response
    {
        try {
            [$hotelIds, $hotelId] = $this->resolveHotelScope();

            return $this->success($this->service->alerts($hotelIds, $hotelId));
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, '获取预警失败'), 400);
        }
    }

    public function alertsRead(): Response
    {
        try {
            $ids = $this->request->post('ids', []);
            if (!is_array($ids)) {
                return $this->error('预警ID必须是数组', 422);
            }

            $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0));
            if (empty($ids)) {
                return $this->error('请选择需要标记已读的预警', 422);
            }

            [$hotelIds] = $this->resolveHotelScope();
            return $this->success(['updated' => $this->service->markAlertsRead($ids, $hotelIds)]);
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, '标记预警已读失败'), 500);
        }
    }

    public function strategySimulation(): Response
    {
        try {
            $input = $this->request->post();
            [$hotelIds, $hotelId] = $this->resolveHotelScope((int)($input['hotel_id'] ?? 0));
            $strategyType = (string)($input['strategy_type'] ?? '');
            $allowed = ['price_adjust', 'promotion', 'room_inventory', 'competitor_follow', 'holiday_strategy'];
            if (!in_array($strategyType, $allowed, true)) {
                return $this->error('策略类型不支持', 422);
            }

            return $this->success($this->service->strategySimulation($hotelIds, $hotelId, $input));
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, '策略模拟失败'), 500);
        }
    }

    public function actions(): Response
    {
        try {
            if (!$this->service->tableExists('operation_action_tracks')) {
                return $this->error('策略动作表不存在，请先执行数据库迁移', 500);
            }

            $input = $this->request->post();
            foreach (['action_title' => '动作标题', 'action_type' => '动作类型', 'start_date' => '开始日期'] as $field => $label) {
                if (trim((string)($input[$field] ?? '')) === '') {
                    return $this->error($label . '不能为空', 422);
                }
            }

            [$hotelIds, $hotelId] = $this->resolveHotelScope((int)($input['hotel_id'] ?? 0));
            $input['start_date'] = $this->normalizeDate((string)$input['start_date']);
            if (!empty($input['end_date'])) {
                $input['end_date'] = $this->normalizeDate((string)$input['end_date']);
            }

            return $this->success(['id' => $this->service->createAction($hotelIds, $hotelId, $input)]);
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, '创建策略动作失败'), 500);
        }
    }

    public function actionTracking(): Response
    {
        try {
            [$hotelIds, $hotelId] = $this->resolveHotelScope();

            return $this->success($this->service->actionTracking($hotelIds, $hotelId));
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, '查询策略追踪失败'), 500);
        }
    }

    public function finishAction(int $id): Response
    {
        try {
            if ($id <= 0) {
                return $this->error('策略动作ID无效', 422);
            }

            [$hotelIds] = $this->resolveHotelScope();
            if (!$this->service->finishAction($id, $hotelIds)) {
                return $this->error('策略动作不存在或无权限操作', 404);
            }

            return $this->success(['id' => $id]);
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, '结束策略动作失败'), 500);
        }
    }

    private function resolveHotelScope(int $inputHotelId = 0): array
    {
        if (!$this->currentUser) {
            throw new \RuntimeException('未登录');
        }

        $hotelId = $inputHotelId > 0 ? $inputHotelId : (int)$this->request->param('hotel_id', 0);
        $permitted = array_values(array_map('intval', $this->currentUser->getPermittedHotelIds()));
        if (empty($permitted)) {
            throw new \RuntimeException('暂无可访问酒店');
        }

        if ($hotelId > 0) {
            if (!in_array($hotelId, $permitted, true)) {
                throw new \RuntimeException('无权查看该酒店数据');
            }
            return [[$hotelId], $hotelId];
        }

        return [$permitted, count($permitted) === 1 ? $permitted[0] : null];
    }

    private function normalizeDate(string $date): string
    {
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            throw new \InvalidArgumentException('日期格式不正确');
        }

        return date('Y-m-d', $timestamp);
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
