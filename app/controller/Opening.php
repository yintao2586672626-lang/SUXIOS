<?php
declare(strict_types=1);

namespace app\controller;

use app\service\OperationManagementService;
use app\service\OpeningService;
use think\Response;
use think\facade\Db;
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
            foreach (['project_name' => '项目名称', 'hotel_name' => '开业门店名称', 'opening_date' => '开业日期'] as $field => $label) {
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
            return $this->success(['list' => $this->service->projects($this->hotelScope(), $this->currentUserId(), $this->isSuperAdmin())]);
        } catch (Throwable $e) {
            return $this->error('获取开业项目列表失败：' . $e->getMessage(), 400);
        }
    }

    public function updateProject(int $id): Response
    {
        try {
            $this->ensureReady();
            if ($id <= 0) {
                return $this->error('开业项目ID无效', 422);
            }

            $input = $this->request->put();
            if (empty($input)) {
                $input = $this->request->post();
            }
            foreach (['project_name' => '项目名称', 'hotel_name' => '开业门店名称', 'opening_date' => '开业日期'] as $field => $label) {
                if (array_key_exists($field, $input) && trim((string)$input[$field]) === '') {
                    return $this->error($label . '不能为空', 422);
                }
            }

            $service = $this->service->forActor($this->currentUserId(), $this->isSuperAdmin());
            return $this->success($service->updateProject($id, $input, $this->hotelScope()), '开业项目已更新');
        } catch (Throwable $e) {
            return $this->error('更新开业项目失败：' . $e->getMessage(), 400);
        }
    }

    public function archiveProject(int $id): Response
    {
        try {
            $this->ensureReady();
            if ($id <= 0) {
                return $this->error('开业项目ID无效', 422);
            }

            $service = $this->service->forActor($this->currentUserId(), $this->isSuperAdmin());
            if (!$service->archiveProject($id, $this->hotelScope())) {
                return $this->error('开业项目不存在或无权操作', 404);
            }

            return $this->success(['id' => $id], '开业项目已归档');
        } catch (Throwable $e) {
            return $this->error('归档开业项目失败：' . $e->getMessage(), 400);
        }
    }

    public function overview(int $id): Response
    {
        try {
            $this->ensureReady();
            return $this->success($this->service->overview($id, $this->hotelScope(), $this->currentUserId(), $this->isSuperAdmin()));
        } catch (Throwable $e) {
            return $this->error('获取开业准备总览失败：' . $e->getMessage(), 400);
        }
    }

    public function generateTasks(int $id): Response
    {
        try {
            $this->ensureReady();
            $result = $this->service->generateTasks($id, $this->hotelScope(), $this->currentUserId(), $this->isSuperAdmin());
            return $this->success($result, $result['generated'] ? '开业检查清单已生成' : '已回显最近一次开业检查清单');
        } catch (Throwable $e) {
            return $this->error('生成开业检查清单失败：' . $e->getMessage(), 400);
        }
    }

    public function tasks(int $id): Response
    {
        try {
            $this->ensureReady();
            return $this->success(['list' => $this->service->tasks($id, $this->hotelScope(), $this->currentUserId(), $this->isSuperAdmin())]);
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
            $task = $this->service->updateTask($id, $input, $this->hotelScope(), $this->currentUserId(), $this->isSuperAdmin());
            return $this->success($task, '检查项已更新');
        } catch (Throwable $e) {
            return $this->error('更新检查项失败：' . $e->getMessage(), 400);
        }
    }

    public function createExecutionIntent(int $id): Response
    {
        try {
            $this->ensureReady();
            if ($id <= 0) {
                return $this->error('opening project id is invalid', 422);
            }

            $hotelIds = $this->hotelScope();
            $overview = $this->service->overview($id, $hotelIds, $this->currentUserId(), $this->isSuperAdmin());
            $project = is_array($overview['project'] ?? null) ? $overview['project'] : [];
            $hotelId = (int)($project['hotel_id'] ?? 0);
            if ($hotelId <= 0) {
                return $this->error('opening project must bind a hotel before execution tracking', 422);
            }
            if (!in_array($hotelId, array_values(array_map('intval', $hotelIds)), true)) {
                return $this->error('hotel_id is not permitted', 403);
            }
            if ((int)($project['execution_intent_id'] ?? 0) > 0) {
                return $this->error('opening project already linked to execution intent', 409);
            }

            $userId = $this->currentUserId();
            $result = Db::transaction(function () use ($project, $overview, $hotelIds, $hotelId, $userId): array {
                $operationService = new OperationManagementService();
                $input = $this->service->buildExecutionIntentInput($project, $overview, [
                    'date_start' => (string)$this->request->param('date_start', ''),
                    'date_end' => (string)$this->request->param('date_end', ''),
                ]);
                $intent = $operationService->createExecutionIntent($hotelIds, $hotelId, $input, $userId);

                return [
                    'execution_intent' => $intent,
                    'project' => array_merge($project, ['execution_intent_id' => (int)($intent['id'] ?? 0)]),
                ];
            });

            return $this->success($result, 'execution intent created');
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            return $this->error('create opening execution intent failed: ' . $e->getMessage(), 400);
        }
    }

    public function recalculate(int $id): Response
    {
        try {
            $this->ensureReady();
            return $this->success($this->service->recalculate($id, $this->hotelScope(), $this->currentUserId(), $this->isSuperAdmin()), '开业准备评分已刷新');
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
        if (!$this->currentUser) {
            return [];
        }

        return array_values(array_map('intval', $this->currentUser->getPermittedHotelIds()));
    }

    private function currentUserId(): int
    {
        return (int)($this->currentUser->id ?? 0);
    }

    private function isSuperAdmin(): bool
    {
        return $this->currentUser && $this->currentUser->isSuperAdmin();
    }
}
