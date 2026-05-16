<?php
declare(strict_types=1);

namespace app\controller;

use app\service\TransferDecisionService;
use InvalidArgumentException;
use think\App;
use think\Response;

class TransferDecision extends Base
{
    private TransferDecisionService $service;

    public function __construct(App $app, ?TransferDecisionService $service = null)
    {
        parent::__construct($app);
        $this->service = $service ?: new TransferDecisionService();
    }

    public function pricing(): Response
    {
        try {
            $result = $this->service->calculateAssetPricing($this->request->post());
            return $this->success($result, '资产定价计算成功');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('资产定价计算失败: ' . $e->getMessage(), 500);
        }
    }

    public function timing(): Response
    {
        try {
            $result = $this->service->calculateTransferTiming($this->request->post());
            return $this->success($result, '时机推演计算成功');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('时机推演计算失败: ' . $e->getMessage(), 500);
        }
    }

    public function dashboard(): Response
    {
        try {
            $input = $this->request->post();
            $result = $this->service->buildTransferDashboard(
                is_array($input['pricing'] ?? null) ? $input['pricing'] : [],
                is_array($input['timing'] ?? null) ? $input['timing'] : [],
                is_array($input['metrics'] ?? null) ? $input['metrics'] : []
            );
            return $this->success($result, '数据看板生成成功');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('数据看板生成失败: ' . $e->getMessage(), 500);
        }
    }
}
