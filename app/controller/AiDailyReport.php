<?php
declare(strict_types=1);

namespace app\controller;

use app\service\AiDailyReportService;
use app\service\AiReportGenerationTaskService;
use think\Response;
use Throwable;

class AiDailyReport extends Base
{
    private AiDailyReportService $service;
    private AiReportGenerationTaskService $taskService;

    public function __construct(\think\App $app)
    {
        parent::__construct($app);
        $this->service = new AiDailyReportService();
        $this->taskService = new AiReportGenerationTaskService();
    }

    public function index(): Response
    {
        try {
            [$hotelIds, $hotelId] = $this->resolveHotelScope((int)$this->request->param('hotel_id', 0));
            return $this->success($this->service->list($hotelIds, $hotelId, $this->request->get()));
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, 'AI daily reports query failed'), $this->statusCode($e));
        }
    }

    public function latest(): Response
    {
        try {
            [$hotelIds, $hotelId] = $this->resolveHotelScope((int)$this->request->param('hotel_id', 0));
            return $this->success($this->service->latest($hotelIds, $hotelId));
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, 'AI daily report query failed'), $this->statusCode($e));
        }
    }

    public function read(int $id): Response
    {
        try {
            [$hotelIds] = $this->resolveHotelScope();
            $report = $this->service->read($id, $hotelIds);
            if (!$report) {
                return $this->error('AI daily report not found', 404);
            }

            return $this->success($report);
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, 'AI daily report read failed'), $this->statusCode($e));
        }
    }

    public function generate(): Response
    {
        try {
            $input = $this->requestData();
            [$hotelIds, $hotelId] = $this->resolveHotelScope((int)($input['hotel_id'] ?? 0));
            $date = trim((string)($input['report_date'] ?? $input['date'] ?? ''));
            if ($date === '') {
                $date = date('Y-m-d', strtotime('-1 day'));
            }
            $userId = (int)($this->currentUser->id ?? 0);
            $options = [
                'model_key' => (string)($input['model_key'] ?? ''),
                'use_llm' => array_key_exists('use_llm', $input) ? $input['use_llm'] : true,
            ];
            $background = array_key_exists('background', $input)
                && filter_var($input['background'], FILTER_VALIDATE_BOOL);
            if ($background) {
                return $this->success($this->taskService->enqueue(
                    $hotelIds,
                    (int)$hotelId,
                    $date,
                    $userId,
                    $options
                ));
            }

            return $this->success($this->service->generate($hotelIds, $hotelId, $date, $userId, $options));
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, 'AI daily report generate failed'), $this->statusCode($e));
        }
    }

    public function generationTask(string $taskId): Response
    {
        try {
            [$hotelIds] = $this->resolveHotelScope();
            $task = $this->taskService->readPublicTask($taskId, $hotelIds);
            if (!is_array($task)) {
                return $this->error('AI report generation task not found', 404);
            }
            return $this->success($task);
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, 'AI report task query failed'), $this->statusCode($e));
        }
    }

    public function recordHumanJudgment(int $id): Response
    {
        try {
            if ($id <= 0) {
                return $this->error('AI daily report is invalid', 422);
            }
            [$hotelIds] = $this->resolveHotelScope();
            $userId = (int)($this->currentUser->id ?? 0);
            $userLabel = (string)($this->currentUser->username ?? $this->currentUser->name ?? '');

            return $this->success($this->service->recordHumanJudgment(
                $id,
                $hotelIds,
                $userId,
                $this->requestData(),
                $userLabel
            ));
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, 'AI daily report judgment save failed'), $this->statusCode($e));
        }
    }

    public function createExecutionIntent(int $id, int $actionIndex): Response
    {
        try {
            if ($id <= 0 || $actionIndex < 0) {
                return $this->error('AI daily report action is invalid', 422);
            }

            [$hotelIds] = $this->resolveHotelScope();
            $userId = (int)($this->currentUser->id ?? 0);

            return $this->success($this->service->createExecutionIntentFromAction($id, $actionIndex, $hotelIds, $userId));
        } catch (Throwable $e) {
            return $this->error($this->safeErrorMessage($e, 'AI daily report action create failed'), $this->statusCode($e));
        }
    }

    private function resolveHotelScope(int $inputHotelId = 0): array
    {
        if (!$this->currentUser) {
            throw new \RuntimeException('not logged in');
        }

        $hotelId = $inputHotelId > 0 ? $inputHotelId : (int)$this->request->param('hotel_id', 0);
        $permitted = array_values(array_map('intval', $this->currentUser->getPermittedHotelIds()));
        if (empty($permitted)) {
            throw new \RuntimeException('no permitted hotel');
        }

        if ($hotelId > 0) {
            if (!in_array($hotelId, $permitted, true)) {
                throw new \RuntimeException('hotel_id is not permitted');
            }
            return [[$hotelId], $hotelId];
        }

        return [$permitted, count($permitted) === 1 ? $permitted[0] : null];
    }

    private function statusCode(Throwable $e): int
    {
        if ($e instanceof \InvalidArgumentException) {
            return 422;
        }

        $message = $e->getMessage();
        if (str_contains($message, 'not logged in')) {
            return 401;
        }
        if (str_contains($message, 'permitted') || str_contains($message, 'no permitted hotel')) {
            return 403;
        }
        if (str_contains($message, 'not found')) {
            return 404;
        }
        if (str_contains($message, 'table does not exist')) {
            return 500;
        }

        return 500;
    }

    private function safeErrorMessage(Throwable $e, string $fallback): string
    {
        $message = trim($e->getMessage());
        return $message !== '' ? $message : $fallback;
    }
}
