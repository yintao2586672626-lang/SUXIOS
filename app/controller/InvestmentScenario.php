<?php
declare(strict_types=1);

namespace app\controller;

use app\model\User;
use app\service\InvestmentScenarioService;
use InvalidArgumentException;
use RuntimeException;
use think\Response;
use Throwable;

class InvestmentScenario extends Base
{
    public function referenceExample(): Response
    {
        return $this->respond(fn(InvestmentScenarioService $service): array => $service->referenceExample());
    }

    public function detail(int $id): Response
    {
        return $this->respond(fn(InvestmentScenarioService $service): array => $service->detail($id));
    }

    public function preview(int $id): Response
    {
        return $this->respond(fn(InvestmentScenarioService $service): array => $service->preview($id, $this->requestData()));
    }

    public function save(int $id): Response
    {
        return $this->respond(fn(InvestmentScenarioService $service): array => $service->save($id, $this->requestData()));
    }

    private function respond(callable $action): Response
    {
        if (!$this->currentUser instanceof User) {
            return $this->error('未登录', 401);
        }
        try {
            return $this->success($action(new InvestmentScenarioService($this->currentUser)));
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (RuntimeException $exception) {
            return $this->error(in_array($exception->getCode(), [401, 403, 404, 409], true) ? $exception->getMessage() : '经营测算读取或保存失败',
                in_array($exception->getCode(), [401, 403, 404, 409], true) ? $exception->getCode() : 500);
        } catch (Throwable $exception) {
            return $this->error('经营测算读取或保存失败', 500);
        }
    }
}
