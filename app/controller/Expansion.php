<?php
declare(strict_types=1);

namespace app\controller;

use app\middleware\RetiredFeatureReadOnly;
use app\service\ExpansionService;
use RuntimeException;
use think\App;
use think\Response;

class Expansion extends Base
{
    private ?ExpansionService $service;

    public function __construct(App $app, ?ExpansionService $service = null)
    {
        parent::__construct($app);
        $this->service = $service;
    }

    public function marketEvaluation(): Response
    {
        return $this->retiredWriteResponse();
    }

    public function benchmarkModel(): Response
    {
        return $this->retiredWriteResponse();
    }

    public function collaborationEfficiency(): Response
    {
        return $this->retiredWriteResponse();
    }

    public function records(): Response
    {
        try {
            $this->ensureLogin();
            $list = $this->service()->records((int)($this->currentUser->id ?? 0), $this->currentUser->isSuperAdmin());
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

            return $this->success($this->service()->detail($id, (int)($this->currentUser->id ?? 0), $this->currentUser->isSuperAdmin()));
        } catch (\Throwable $e) {
            return $this->error('获取扩张记录详情失败: ' . $e->getMessage(), 400);
        }
    }

    public function createExecutionIntent(int $id): Response
    {
        return $this->retiredWriteResponse();
    }

    public function archive(int $id): Response
    {
        return $this->retiredWriteResponse();
    }

    public function clearMarketEvaluation(): Response
    {
        return $this->retiredWriteResponse();
    }

    public function clearRecords(): Response
    {
        return $this->retiredWriteResponse();
    }

    private function retiredWriteResponse(): Response
    {
        if (!$this->currentUser) {
            return $this->error('请先登录', 401);
        }

        return RetiredFeatureReadOnly::response('扩张测算');
    }

    private function service(): ExpansionService
    {
        return $this->service ??= new ExpansionService();
    }

    private function ensureLogin(): void
    {
        if (!$this->currentUser) {
            throw new RuntimeException('请先登录');
        }
    }
}
