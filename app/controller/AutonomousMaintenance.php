<?php
declare(strict_types=1);
namespace app\controller;
use app\service\AutonomousMaintenanceService;
use think\Response;

/** This control surface never executes commands or hotel actions. */
final class AutonomousMaintenance extends Base
{
    private function service(): AutonomousMaintenanceService
    {
        if (!$this->currentUser || !$this->currentUser->isSuperAdmin()) {
            abort(403, '只有超级管理员可以查看和控制研发维护');
        }
        return new AutonomousMaintenanceService();
    }
    public function overview(): Response
    {
        try { return $this->success($this->service()->snapshot()); }
        catch (\Throwable $e) { return $this->failure($e); }
    }
    public function control(): Response
    {
        try {
            $service = $this->service();
            $data = $this->requestData();
            if (!isset($data['expected_revision']) || !is_int($data['expected_revision'])) {
                throw new \InvalidArgumentException('expected_control_revision_required');
            }
            $control = $service->control((string)($data['action'] ?? ''), $data['expected_revision'],
                isset($data['deadline_at']) ? (string)$data['deadline_at'] : null, (string)($data['reason'] ?? '管理员页面控制'));
            return $this->success(['control' => $control], '已保存，请核对执行者停止回执');
        } catch (\Throwable $e) { return $this->failure($e); }
    }
    private function failure(\Throwable $e): Response
    {
        if ($e instanceof \think\exception\HttpException) throw $e;
        $code = $e instanceof \DomainException ? 409 : ($e instanceof \InvalidArgumentException ? 422 : 503);
        return $this->error($code === 503 ? '维护状态不可读取或保存；原记录已保留，请查看本地恢复入口' : $e->getMessage(),
            $code, ['state' => 'blocked', 'retry_requires_changed_evidence' => true]);
    }
}
