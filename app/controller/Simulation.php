<?php
declare(strict_types=1);

namespace app\controller;

use app\service\QuantSimulationService;
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

    private function ensureLogin(): void
    {
        if (!$this->currentUser) {
            throw new \RuntimeException('请先登录');
        }
    }
}
