<?php
declare(strict_types=1);

namespace app\controller;

use app\service\TransferDecisionService;
use InvalidArgumentException;
use RuntimeException;
use think\App;
use think\Response;
use Throwable;

class TransferDecision extends Base
{
    private TransferDecisionService $service;

    public function __construct(App $app, ?TransferDecisionService $service = null)
    {
        parent::__construct($app);
        $this->service = $service ?: new TransferDecisionService();
    }

    public function source(): Response
    {
        try {
            [$hotelIds, $hotelId] = $this->resolveHotelScope((int)$this->request->param('hotel_id', 0));
            $date = $this->normalizeDate((string)$this->request->param('date', date('Y-m-d')));

            return $this->success($this->service->buildSourcePayload($hotelIds, $hotelId, $date));
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, '获取转让真实数据失败'), 400);
        }
    }

    public function pricing(): Response
    {
        try {
            $input = $this->request->post();
            [$hotelIds, $hotelId] = $this->resolveHotelScope((int)($input['hotel_id'] ?? 0));
            $snapshot = $this->payloadSnapshot($input);
            $recordHotelId = $this->recordHotelId($input, $snapshot, $hotelIds, $hotelId);
            unset($input['snapshot']);

            $result = $this->service->calculateAssetPricing($input);
            $result['record_id'] = $this->service->saveRecord('pricing', $input, $result, $snapshot, $recordHotelId, (int)($this->currentUser->id ?? 0));
            return $this->success($result, '资产定价计算成功');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            return $this->error('资产定价计算失败: ' . $e->getMessage(), 500);
        }
    }

    public function timing(): Response
    {
        try {
            $input = $this->request->post();
            [$hotelIds, $hotelId] = $this->resolveHotelScope((int)($input['hotel_id'] ?? 0));
            $snapshot = $this->payloadSnapshot($input);
            $recordHotelId = $this->recordHotelId($input, $snapshot, $hotelIds, $hotelId);
            unset($input['snapshot']);

            $result = $this->service->calculateTransferTiming($input);
            $result['record_id'] = $this->service->saveRecord('timing', $input, $result, $snapshot, $recordHotelId, (int)($this->currentUser->id ?? 0));
            return $this->success($result, '时机推演计算成功');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            return $this->error('时机推演计算失败: ' . $e->getMessage(), 500);
        }
    }

    public function dashboard(): Response
    {
        try {
            $input = $this->request->post();
            [$hotelIds, $hotelId] = $this->resolveHotelScope((int)($input['hotel_id'] ?? 0));
            $snapshot = $this->payloadSnapshot($input);
            $recordHotelId = $this->recordHotelId($input, $snapshot, $hotelIds, $hotelId);
            $dashboardInput = [
                'pricing' => is_array($input['pricing'] ?? null) ? $input['pricing'] : [],
                'timing' => is_array($input['timing'] ?? null) ? $input['timing'] : [],
                'metrics' => is_array($input['metrics'] ?? null) ? $input['metrics'] : [],
            ];

            $result = $this->service->buildTransferDashboard($dashboardInput['pricing'], $dashboardInput['timing'], $dashboardInput['metrics']);
            $result['record_id'] = $this->service->saveRecord('dashboard', $dashboardInput, $result, $snapshot, $recordHotelId, (int)($this->currentUser->id ?? 0));
            return $this->success($result, '数据看板生成成功');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            return $this->error('数据看板生成失败: ' . $e->getMessage(), 500);
        }
    }

    public function records(): Response
    {
        try {
            [$hotelIds] = $this->resolveHotelScope((int)$this->request->param('hotel_id', 0));
            $list = $this->service->records($hotelIds, (int)($this->currentUser->id ?? 0), $this->currentUser->isSuperAdmin());
            return $this->success(['list' => $list]);
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, '获取转让记录失败'), 400);
        }
    }

    public function detail(int $id): Response
    {
        try {
            if ($id <= 0) {
                return $this->error('转让记录ID无效', 422);
            }

            [$hotelIds] = $this->resolveHotelScope();
            return $this->success($this->service->detail($id, $hotelIds, (int)($this->currentUser->id ?? 0), $this->currentUser->isSuperAdmin()));
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, '获取转让记录详情失败'), 400);
        }
    }

    public function archive(int $id): Response
    {
        try {
            if ($id <= 0) {
                return $this->error('转让记录ID无效', 422);
            }

            [$hotelIds] = $this->resolveHotelScope();
            $archived = $this->service->archive($id, $hotelIds, (int)($this->currentUser->id ?? 0), $this->currentUser->isSuperAdmin());
            if (!$archived) {
                return $this->error('转让记录不存在或无权归档', 404);
            }

            return $this->success(['id' => $id], '转让记录已归档');
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, '转让记录归档失败'), 400);
        }
    }

    private function resolveHotelScope(int $inputHotelId = 0): array
    {
        if (!$this->currentUser) {
            throw new RuntimeException('未登录');
        }

        $hotelId = $inputHotelId > 0 ? $inputHotelId : (int)$this->request->param('hotel_id', 0);
        $permitted = array_values(array_map('intval', $this->currentUser->getPermittedHotelIds()));
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

    private function payloadSnapshot(array $input): array
    {
        $snapshot = $input['snapshot'] ?? $input['data_snapshot'] ?? [];
        return is_array($snapshot) ? $snapshot : [];
    }

    private function recordHotelId(array $input, array $snapshot, array $hotelIds, ?int $hotelId): int
    {
        $candidate = (int)($input['hotel_id'] ?? $snapshot['hotel_id'] ?? 0);
        if ($candidate > 0 && in_array($candidate, $hotelIds, true)) {
            return $candidate;
        }

        if ($hotelId !== null && $hotelId > 0) {
            return $hotelId;
        }

        throw new InvalidArgumentException('请先选择酒店');
    }

    private function normalizeDate(string $date): string
    {
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            throw new InvalidArgumentException('日期格式不正确');
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
