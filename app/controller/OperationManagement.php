<?php
declare(strict_types=1);

namespace app\controller;

use app\service\BusinessClosureOverviewService;
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
            return $this->error($this->safeErrorMessage($e, '根因定位失败'), $this->operationThrowableStatus($e));
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
            $validationError = $this->validateStrategySimulationInput($input, $strategyType);
            if ($validationError !== '') {
                return $this->error($validationError, 422);
            }

            $result = $this->service->strategySimulation($hotelIds, $hotelId, $input);
            if (!empty($input['create_execution_order'])) {
                $result['execution_intent'] = $this->service->createExecutionIntent(
                    $hotelIds,
                    $hotelId ?: ($hotelIds[0] ?? null),
                    $this->buildStrategyExecutionIntentInput($input, $result, $strategyType, $hotelId ?: ($hotelIds[0] ?? 0)),
                    (int)($this->currentUser->id ?? 0)
                );
            }

            return $this->success($result);
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, '策略模拟失败'), $this->operationThrowableStatus($e));
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
            return $this->error($this->safeErrorMessage($e, '创建策略动作失败'), $this->operationThrowableStatus($e));
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

    public function executionIntents(): Response
    {
        try {
            [$hotelIds, $hotelId] = $this->resolveHotelScope((int)$this->request->param('hotel_id', 0));
            return $this->success($this->service->executionIntents($hotelIds, $hotelId, $this->request->get()));
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, 'execution intents query failed'), $this->operationThrowableStatus($e));
        }
    }

    public function executionFlow(): Response
    {
        try {
            [$hotelIds, $hotelId] = $this->resolveHotelScope((int)$this->request->param('hotel_id', 0));
            return $this->success($this->service->executionFlow($hotelIds, $hotelId, $this->request->get()));
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, 'execution flow query failed'), $this->operationThrowableStatus($e));
        }
    }

    public function closureOverview(): Response
    {
        try {
            [$hotelIds, $hotelId] = $this->resolveHotelScope((int)$this->request->param('hotel_id', 0));
            $service = new BusinessClosureOverviewService();

            return $this->success($service->overview(
                $hotelIds,
                $hotelId,
                (int)($this->currentUser->id ?? 0),
                $this->currentUser ? $this->currentUser->isSuperAdmin() : false
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, 'business closure overview query failed'), $this->operationThrowableStatus($e));
        }
    }

    public function createExecutionIntent(): Response
    {
        try {
            $input = $this->requestData();
            [$hotelIds, $hotelId] = $this->resolveHotelScope((int)($input['hotel_id'] ?? 0));
            $userId = (int)($this->currentUser->id ?? 0);

            return $this->success($this->service->createExecutionIntent($hotelIds, $hotelId, $input, $userId));
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, 'execution intent create failed'), $this->operationThrowableStatus($e));
        }
    }

    public function approveExecutionIntent(int $id): Response
    {
        try {
            if ($id <= 0) {
                return $this->error('execution intent id is invalid', 422);
            }

            $input = $this->requestData();
            [$hotelIds] = $this->resolveHotelScope();
            $approved = !array_key_exists('approved', $input) || filter_var($input['approved'], FILTER_VALIDATE_BOOL);
            $remark = trim((string)($input['remark'] ?? ''));
            $userId = (int)($this->currentUser->id ?? 0);

            return $this->success($this->service->approveExecutionIntent($id, $approved, $remark, $userId, $hotelIds));
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, 'execution intent approval failed'), $this->operationThrowableStatus($e));
        }
    }

    public function executeExecutionTask(int $id): Response
    {
        try {
            if ($id <= 0) {
                return $this->error('execution task id is invalid', 422);
            }

            [$hotelIds] = $this->resolveHotelScope();
            $userId = (int)($this->currentUser->id ?? 0);

            return $this->success($this->service->executeExecutionTask($id, $hotelIds, $this->requestData(), $userId));
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, 'execution task update failed'), $this->operationThrowableStatus($e));
        }
    }

    public function executionTaskEvidence(int $id): Response
    {
        try {
            if ($id <= 0) {
                return $this->error('execution task id is invalid', 422);
            }

            [$hotelIds] = $this->resolveHotelScope();
            $userId = (int)($this->currentUser->id ?? 0);

            return $this->success($this->service->addExecutionEvidence($id, $hotelIds, $this->requestData(), $userId));
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, 'execution evidence save failed'), $this->operationThrowableStatus($e));
        }
    }

    public function reviewExecutionTask(int $id): Response
    {
        try {
            if ($id <= 0) {
                return $this->error('execution task id is invalid', 422);
            }

            [$hotelIds] = $this->resolveHotelScope();
            return $this->success($this->service->reviewExecutionTask($id, $hotelIds, $this->requestData()));
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, 'execution task review failed'), $this->operationThrowableStatus($e));
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

    private function validateStrategySimulationInput(array &$input, string $strategyType): string
    {
        foreach (['start_date' => '开始日期', 'end_date' => '结束日期'] as $field => $label) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            $value = trim((string)$input[$field]);
            if ($value === '') {
                return $label . '不能为空';
            }
            $input[$field] = $this->normalizeDate($value);
        }

        if (!empty($input['start_date']) && !empty($input['end_date']) && $input['start_date'] > $input['end_date']) {
            return '结束日期不能早于开始日期';
        }

        if ($strategyType === 'price_adjust' && array_key_exists('adjust_amount', $input)) {
            $value = trim((string)$input['adjust_amount']);
            if ($value === '' || !is_numeric($value) || (float)$value === 0.0) {
                return '调价金额必填且不能为 0';
            }
            $input['adjust_amount'] = (float)$value;
        }

        if ($strategyType === 'promotion' && array_key_exists('discount_rate', $input)) {
            $value = trim((string)$input['discount_rate']);
            if ($value === '' || !is_numeric($value) || (float)$value <= 0.0 || (float)$value > 100.0) {
                return '折扣比例必填，范围为 0-100';
            }
            $input['discount_rate'] = (float)$value;
        }

        return '';
    }

    private function buildStrategyExecutionIntentInput(array $input, array $result, string $strategyType, int $hotelId): array
    {
        $objectType = match ($strategyType) {
            'price_adjust', 'competitor_follow', 'holiday_strategy' => 'price',
            'room_inventory' => 'inventory',
            'promotion' => 'campaign',
            default => 'price',
        };

        $targetValue = [
            'target_price' => (float)($input['target_price'] ?? $input['suggested_price'] ?? 0),
            'adjust_amount' => (float)($input['adjust_amount'] ?? 0),
            'room_type_key' => (string)($input['room_type_key'] ?? ''),
            'rate_plan_key' => (string)($input['rate_plan_key'] ?? ''),
            'target_inventory' => (int)($input['target_inventory'] ?? 0),
            'sell_status' => (string)($input['sell_status'] ?? ''),
            'campaign_type' => (string)($input['campaign_type'] ?? $strategyType),
            'discount_rate' => (float)($input['discount_rate'] ?? 0),
            'budget' => (float)($input['budget'] ?? 0),
            'target_metric' => (string)($input['target_metric'] ?? 'orders'),
        ];

        return [
            'source_module' => 'strategy_simulation',
            'source_record_id' => (int)($input['source_record_id'] ?? 0),
            'hotel_id' => $hotelId,
            'platform' => (string)($input['platform'] ?? $input['channel'] ?? ''),
            'object_type' => $objectType,
            'action_type' => $strategyType,
            'date_start' => (string)($input['start_date'] ?? date('Y-m-d')),
            'date_end' => (string)($input['end_date'] ?? $input['start_date'] ?? date('Y-m-d')),
            'current_value' => $result['baseline'] ?? [],
            'target_value' => $targetValue,
            'evidence' => [
                'strategy_result' => $result,
                'input' => $input,
            ],
            'expected_metric' => (string)($input['target_metric'] ?? 'orders'),
            'expected_delta' => (float)($input['target_change_rate'] ?? 0),
            'risk_level' => (string)($result['risk']['level'] ?? 'medium'),
        ];
    }

    private function operationThrowableStatus(Throwable $e): int
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
