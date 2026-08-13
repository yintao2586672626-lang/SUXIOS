<?php
declare(strict_types=1);

namespace app\controller;

use app\service\OperationManagementService;
use app\service\QuantSimulationService;
use app\service\SimulationExecutionReadinessService;
use think\facade\Db;
use think\Response;
use Throwable;

class Simulation extends Base
{
    private QuantSimulationService $service;

    public function __construct(\think\App $app)
    {
        parent::__construct($app);
        $this->service = new QuantSimulationService();
    }

    public function calculate(): Response
    {
        try {
            $this->ensureLogin();
            $payload = $this->request->post();
            $rawInput = is_array($payload['input'] ?? null) ? $payload['input'] : $payload;
            [$hotelIds, $hotelId] = $this->resolveExecutionHotelScope((int)(
                $rawInput['hotel_id'] ?? $rawInput['system_hotel_id'] ?? $payload['hotel_id'] ?? 0
            ));
            $payload['input'] = array_merge($rawInput, [
                'hotel_id' => $hotelId,
                'system_hotel_id' => $hotelId,
            ]);
            $data = $this->service->calculateAndSave(
                $payload,
                (int)($this->currentUser->id ?? 0),
                $hotelIds
            );
            return $this->success($data, '量化模拟已保存');
        } catch (Throwable $e) {
            return $this->error('量化模拟失败：' . $e->getMessage(), 400);
        }
    }

    public function records(): Response
    {
        try {
            $this->ensureLogin();
            $list = $this->service->records((int)($this->currentUser->id ?? 0), $this->currentUser->isSuperAdmin());
            return $this->success(['list' => $list]);
        } catch (Throwable $e) {
            return $this->error('获取量化模拟记录失败：' . $e->getMessage(), 400);
        }
    }

    public function detail(int $id): Response
    {
        try {
            $this->ensureLogin();
            if ($id <= 0) {
                return $this->error('量化模拟记录ID无效', 422);
            }

            return $this->success($this->service->detail($id, (int)($this->currentUser->id ?? 0), $this->currentUser->isSuperAdmin()));
        } catch (Throwable $e) {
            return $this->error('获取量化模拟记录详情失败：' . $e->getMessage(), 400);
        }
    }

    public function archive(int $id): Response
    {
        try {
            $this->ensureLogin();
            if ($id <= 0) {
                return $this->error('量化模拟记录ID无效', 422);
            }

            $archived = $this->service->archive($id, (int)($this->currentUser->id ?? 0), $this->currentUser->isSuperAdmin());
            if (!$archived) {
                return $this->error('量化模拟记录不存在或无权归档', 404);
            }

            return $this->success(['id' => $id], '量化模拟记录已归档');
        } catch (Throwable $e) {
            return $this->error('量化模拟记录归档失败：' . $e->getMessage(), 400);
        }
    }

    public function createExecutionIntent(int $id): Response
    {
        try {
            $this->ensureLogin();
            if ($id <= 0) {
                return $this->error('quant simulation record id is invalid', 422);
            }

            $data = $this->requestData();
            $requestedHotelId = (int)($data['hotel_id'] ?? $this->request->param('hotel_id', 0));
            $record = $this->service->detail($id, (int)($this->currentUser->id ?? 0), $this->currentUser->isSuperAdmin());
            $readinessService = new SimulationExecutionReadinessService();
            $sourceHotelId = $readinessService->quantExecutionHotelId($record);
            if ($requestedHotelId > 0 && $requestedHotelId !== $sourceHotelId) {
                return $this->error('quant simulation hotel scope mismatch', 409);
            }
            [$hotelIds, $hotelId] = $this->resolveExecutionHotelScope($sourceHotelId);
            if (($denied = $this->hotelCapabilityDeniedResponse(
                $hotelId,
                'operation.execute',
                'operation.execute permission is required for this hotel'
            )) !== null) {
                return $denied;
            }
            $input = $readinessService->buildQuantExecutionIntentInput($record, [
                'hotel_id' => $sourceHotelId,
                'date_start' => (string)($data['date_start'] ?? $this->request->param('date_start', '')),
                'date_end' => (string)($data['date_end'] ?? $this->request->param('date_end', '')),
            ]);
            $intent = (new OperationManagementService())->createExecutionIntent(
                $hotelIds,
                $hotelId,
                $input,
                (int)($this->currentUser->id ?? 0),
                false,
                null,
                true
            );

            return $this->success([
                'execution_intent' => $intent,
                'record' => array_merge($record, ['execution_intent_id' => (int)($intent['id'] ?? 0)]),
                'source_module' => 'quant_simulation',
                'metric_scope' => 'investment_decision',
            ], 'execution intent created');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            return $this->error('create quant execution intent failed: ' . $e->getMessage(), 500);
        }
    }

    private function ensureLogin(): void
    {
        if (!$this->currentUser) {
            throw new \RuntimeException('请先登录');
        }
    }

    /**
     * @return array{0:array<int, int>, 1:int}
     */
    private function resolveExecutionHotelScope(int $inputHotelId = 0): array
    {
        if (!$this->currentUser) {
            throw new \RuntimeException('not logged in');
        }

        $permitted = array_values(array_map('intval', $this->currentUser->getPermittedHotelIds()));
        if (empty($permitted)) {
            throw new \RuntimeException('no permitted hotel');
        }

        if ($inputHotelId > 0) {
            if (!in_array($inputHotelId, $permitted, true)) {
                throw new \InvalidArgumentException('hotel_id is not permitted');
            }
            return [[$inputHotelId], $inputHotelId];
        }

        if (count($permitted) === 1) {
            return [$permitted, $permitted[0]];
        }

        throw new \InvalidArgumentException('hotel_id is required for quant simulation execution intent');
    }

}
