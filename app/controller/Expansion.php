<?php
declare(strict_types=1);

namespace app\controller;

use app\service\ExpansionService;
use InvalidArgumentException;
use think\App;
use think\Response;

class Expansion extends Base
{
    private ExpansionService $service;

    public function __construct(App $app, ?ExpansionService $service = null)
    {
        parent::__construct($app);
        $this->service = $service ?: new ExpansionService();
    }

    public function marketEvaluation(): Response
    {
        try {
            return $this->success(
                $this->service->evaluateMarket($this->request->post()),
                '市场评估已生成'
            );
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('市场评估生成失败: ' . $e->getMessage(), 500);
        }
    }

    public function benchmarkModel(): Response
    {
        try {
            return $this->success(
                $this->service->buildBenchmarkModel($this->request->post()),
                '标杆选模已生成'
            );
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('标杆选模生成失败: ' . $e->getMessage(), 500);
        }
    }

    public function collaborationEfficiency(): Response
    {
        try {
            return $this->success(
                $this->service->improveCollaboration($this->request->post()),
                '协同提效看板已生成'
            );
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('协同提效看板生成失败: ' . $e->getMessage(), 500);
        }
    }
}
