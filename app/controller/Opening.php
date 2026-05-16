<?php
declare(strict_types=1);

namespace app\controller;

use app\service\OpeningService;
use think\Response;
use Throwable;

class Opening extends Base
{
    private OpeningService $service;

    public function __construct(\think\App $app)
    {
        parent::__construct($app);
        $this->service = new OpeningService();
    }

    public function createProject(): Response
    {
        try {
            $this->ensureReady();
            $input = $this->request->post();
            foreach (['project_name' => '项目名称', 'hotel_name' => '酒店名称', 'opening_date' => '开业日期'] as $field => $label) {
                if (trim((string)($input[$field] ?? '')) === '') {
                    return $this->error($label . '不能为空', 422);
                }
            }

            $id = $this->service->createProject($input, (int)($this->currentUser->id ?? 0), $this->hotelScope());
            return $this->success(['id' => $id], '开业项目创建成功');
        } catch (Throwable $e) {
            return $this->error('创建开业项目失败：' . $e->getMessage(), 400);
        }
    }

    public function projects(): Response
    {
        try {
            $this->ensureReady();
            return $this->success(['list' => $this->service->projects($this->hotelScope())]);
        } catch (Throwable $e) {
            return $this->error('获取开业项目列表失败：' . $e->getMessage(), 400);
        }
    }

    public function overview(int $id): Response
    {
        try {
            $this->ensureReady();
            return $this->success($this->service->overview($id, $this->hotelScope()));
        } catch (Throwable $e) {
            return $this->error('获取开业准备总览失败：' . $e->getMessage(), 400);
        }
    }

    public function generateTasks(int $id): Response
    {
        try {
            $this->ensureReady();
            $result = $this->service->generateTasks($id, $this->hotelScope());
            return $this->success($result, $result['generated'] ? '开业检查清单已生成' : '已回显最近一次开业检查清单');
        } catch (Throwable $e) {
            return $this->error('生成开业检查清单失败：' . $e->getMessage(), 400);
        }
    }

    public function tasks(int $id): Response
    {
        try {
            $this->ensureReady();
            return $this->success(['list' => $this->service->tasks($id, $this->hotelScope())]);
        } catch (Throwable $e) {
            return $this->error('获取开业检查清单失败：' . $e->getMessage(), 400);
        }
    }

    public function updateTask(int $id): Response
    {
        try {
            $this->ensureReady();
            $input = $this->request->put();
            if (empty($input)) {
                $input = $this->request->post();
            }
            $task = $this->service->updateTask($id, $input, $this->hotelScope());
            return $this->success($task, '检查项已更新');
        } catch (Throwable $e) {
            return $this->error('更新检查项失败：' . $e->getMessage(), 400);
        }
    }

    public function recalculate(int $id): Response
    {
        try {
            $this->ensureReady();
            return $this->success($this->service->recalculate($id, $this->hotelScope()), '开业准备评分已刷新');
        } catch (Throwable $e) {
            return $this->error('重新计算开业准备评分失败：' . $e->getMessage(), 400);
        }
    }

    private function ensureReady(): void
    {
        if (!$this->currentUser) {
            throw new \RuntimeException('请先登录');
        }
        if (!$this->service->tableExists('opening_projects') || !$this->service->tableExists('opening_tasks')) {
            throw new \RuntimeException('开业管理数据表不存在，请先执行数据库迁移');
        }
    }

    private function hotelScope(): array
    {
        if ($this->currentUser && $this->currentUser->isSuperAdmin()) {
            return [];
        }

        $hotelIds = $this->currentUser ? array_values(array_map('intval', $this->currentUser->getPermittedHotelIds())) : [];
        if (empty($hotelIds)) {
            throw new \RuntimeException('暂无可访问酒店');
        }

        return $hotelIds;
    }
}
