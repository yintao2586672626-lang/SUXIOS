<?php
declare(strict_types=1);

namespace app\controller;

use app\service\ExpansionService;
use InvalidArgumentException;
use RuntimeException;
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
            $this->ensureLogin();
            $input = $this->request->post();
            $result = $this->service->evaluateMarket($input);
            $result['record_id'] = $this->service->saveRecord('market', $input, $result, (int)($this->currentUser->id ?? 0));

            return $this->success($result, '市场评估已生成');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('市场评估生成失败: ' . $e->getMessage(), 500);
        }
    }

    public function benchmarkModel(): Response
    {
        try {
            $this->ensureLogin();
            $input = $this->request->post();
            $result = $this->service->buildBenchmarkModel($input);
            $result['record_id'] = $this->service->saveRecord('benchmark', $input, $result, (int)($this->currentUser->id ?? 0));

            return $this->success($result, '标杆选模已生成');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('标杆选模生成失败: ' . $e->getMessage(), 500);
        }
    }

    public function collaborationEfficiency(): Response
    {
        try {
            $this->ensureLogin();
            $input = $this->request->post();
            $result = $this->service->improveCollaboration($input);
            $result['record_id'] = $this->service->saveRecord('collaboration', $input, $result, (int)($this->currentUser->id ?? 0));

            return $this->success($result, '协同提效看板已生成');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->error('协同提效看板生成失败: ' . $e->getMessage(), 500);
        }
    }

    public function records(): Response
    {
        try {
            $this->ensureLogin();
            $list = $this->service->records((int)($this->currentUser->id ?? 0), $this->currentUser->isSuperAdmin());
            return $this->success(['list' => $list]);
        } catch (\Throwable $e) {
            return $this->error('获取扩张记录失败: ' . $e->getMessage(), 400);
        }
    }

    public function detail(int $id): Response
    {
        try {
            $this->ensureLogin();
            if ($id <= 0) {
                return $this->error('扩张记录ID无效', 422);
            }

            return $this->success($this->service->detail($id, (int)($this->currentUser->id ?? 0), $this->currentUser->isSuperAdmin()));
        } catch (\Throwable $e) {
            return $this->error('获取扩张记录详情失败: ' . $e->getMessage(), 400);
        }
    }

    public function archive(int $id): Response
    {
        try {
            $this->ensureLogin();
            if ($id <= 0) {
                return $this->error('扩张记录ID无效', 422);
            }

            $archived = $this->service->archive($id, (int)($this->currentUser->id ?? 0), $this->currentUser->isSuperAdmin());
            if (!$archived) {
                return $this->error('扩张记录不存在或无权归档', 404);
            }

            return $this->success(['id' => $id], '扩张记录已归档');
        } catch (\Throwable $e) {
            return $this->error('扩张记录归档失败: ' . $e->getMessage(), 400);
        }
    }

    private function ensureLogin(): void
    {
        if (!$this->currentUser) {
            throw new RuntimeException('请先登录');
        }
    }
}
