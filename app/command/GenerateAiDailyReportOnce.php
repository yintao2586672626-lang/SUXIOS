<?php
declare(strict_types=1);

namespace app\command;

use app\service\AiDailyReportService;
use app\service\AiReportGenerationTaskService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use Throwable;

final class GenerateAiDailyReportOnce extends Command
{
    protected function configure()
    {
        $this->setName('ai-daily-report:generate-once')
            ->addOption('task-id', null, Option::VALUE_REQUIRED, 'AI report generation task id')
            ->setDescription('Generate one queued AI daily report task');
    }

    protected function execute(Input $input, Output $output)
    {
        $taskId = trim((string)$input->getOption('task-id'));
        $taskService = new AiReportGenerationTaskService();
        $task = $taskService->claimTask($taskId);
        if (!is_array($task)) {
            $taskService->dispatchQueuedTasks();
            $output->writeln('AI report task is missing, invalid, or already claimed.');
            return 1;
        }

        try {
            $hotelId = (int)($task['hotel_id'] ?? 0);
            $report = (new AiDailyReportService())->generate(
                [$hotelId],
                $hotelId,
                (string)($task['report_date'] ?? ''),
                (int)($task['requested_by'] ?? 0),
                [
                    'model_key' => (string)($task['model_key'] ?? ''),
                    'use_llm' => (int)($task['use_llm'] ?? 1) === 1,
                ]
            );
            if ((int)($report['id'] ?? 0) <= 0) {
                throw new \RuntimeException('AI report generation returned no persisted report.');
            }
            $completed = $taskService->completeTask($taskId, $report);
            $taskService->dispatchQueuedTasks();
            $output->writeln('AI report task finished with status: ' . (string)($completed['status'] ?? 'unknown') . '.');
            return in_array(($completed['status'] ?? ''), ['succeeded', 'partial', 'blocked'], true) ? 0 : 1;
        } catch (Throwable $e) {
            $taskService->failTask($taskId, $e->getMessage() ?: 'AI report generation crashed.', 'generation_failed');
            $taskService->dispatchQueuedTasks();
            $output->writeln('AI report task failed: ' . ($e->getMessage() ?: 'unknown error'));
            return 1;
        }
    }
}
