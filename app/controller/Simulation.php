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
            $data = $this->service->calculateAndSave($this->request->post(), (int)($this->currentUser->id ?? 0));
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
            [$hotelIds, $hotelId] = $this->resolveExecutionHotelScope((int)($data['hotel_id'] ?? $this->request->param('hotel_id', 0)));
            $existingId = $this->existingExecutionIntentId('quant_simulation', $id, $hotelIds);
            if ($existingId > 0) {
                return $this->error('quant simulation record already linked to execution intent', 409);
            }

            $record = $this->service->detail($id, (int)($this->currentUser->id ?? 0), $this->currentUser->isSuperAdmin());
            $input = (new SimulationExecutionReadinessService())->buildQuantExecutionIntentInput($record, [
                'hotel_id' => $hotelId,
                'date_start' => (string)($data['date_start'] ?? $this->request->param('date_start', '')),
                'date_end' => (string)($data['date_end'] ?? $this->request->param('date_end', '')),
            ]);
            $intent = (new OperationManagementService())->createExecutionIntent(
                $hotelIds,
                $hotelId,
                $input,
                (int)($this->currentUser->id ?? 0)
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

    private function existingExecutionIntentId(string $sourceModule, int $sourceRecordId, array $hotelIds): int
    {
        try {
            return (int)(Db::name('operation_execution_intents')
                ->where('source_module', $sourceModule)
                ->where('source_record_id', $sourceRecordId)
                ->whereIn('hotel_id', $hotelIds)
                ->whereNull('deleted_at')
                ->order('id', 'desc')
                ->value('id') ?: 0);
        } catch (Throwable $e) {
            return 0;
        }
    }
}
