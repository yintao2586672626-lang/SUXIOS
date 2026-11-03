<?php
declare(strict_types=1);

namespace app\controller;

use app\model\User;
use app\service\InvestmentPaybackImportService;
use app\service\InvestmentPaybackService;
use InvalidArgumentException;
use RuntimeException;
use think\Response;
use Throwable;

class InvestmentPayback extends Base
{
    public function projects(): Response
    {
        return $this->respond(fn(InvestmentPaybackService $service): array => $service->projects($this->request->get()), '回本项目读取失败');
    }

    public function saveProject(): Response
    {
        return $this->respond(fn(InvestmentPaybackService $service): array => $service->saveProject($this->requestData()), '回本项目保存失败');
    }

    public function saveLayout(): Response
    {
        return $this->respond(fn(InvestmentPaybackService $service): array => $service->saveLayout($this->requestData()), '卡片顺序保存失败');
    }

    public function importPreview(): Response
    {
        return $this->respond(fn(InvestmentPaybackService $service): array => (new InvestmentPaybackImportService($this->currentUser, $service))->preview($this->requestData()), '导入文件解析失败');
    }

    public function importConfirm(): Response
    {
        return $this->respond(fn(InvestmentPaybackService $service): array => (new InvestmentPaybackImportService($this->currentUser, $service))->confirm($this->requestData()), '确认导入失败，整批未写入');
    }

    public function detail(int $id): Response
    {
        return $this->respond(fn(InvestmentPaybackService $service): array => $service->detail($id, $this->request->get('as_of') ?: null), '回本项目详情读取失败');
    }

    public function archive(int $id): Response
    {
        return $this->respond(fn(InvestmentPaybackService $service): array => $service->archive($id, $this->requestData()), '回本项目归档失败');
    }

    public function saveEntry(int $id): Response
    {
        return $this->respond(fn(InvestmentPaybackService $service): array => $service->saveEntry($id, $this->requestData()), '资金记录保存失败');
    }

    public function voidEntry(int $id, int $entryId): Response
    {
        return $this->respond(fn(InvestmentPaybackService $service): array => $service->voidEntry($id, $entryId, $this->requestData()), '资金记录作废失败');
    }

    public function deleteEntry(int $id, int $entryId): Response
    {
        return $this->respond(fn(InvestmentPaybackService $service): array => $service->deleteEntry($id, $entryId, $this->requestData()), '资金记录删除失败');
    }

    private function respond(callable $action, string $fallback): Response
    {
        if (!$this->currentUser instanceof User) {
            return $this->error('未登录', 401);
        }
        try {
            return $this->success($action(new InvestmentPaybackService($this->currentUser)));
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (RuntimeException $exception) {
            if (in_array($exception->getCode(), [401, 403, 404, 409, 422, 503], true)) {
                return $this->error($exception->getMessage(), $exception->getCode());
            }
            return $this->error($fallback, 500);
        } catch (Throwable $exception) {
            return $this->error($fallback, 500);
        }
    }
}
