<?php
declare(strict_types=1);

namespace app\command;

use app\service\AiDailyReportService;
use app\service\AiReportGenerationTaskService;
use app\service\P0OtaDownstreamGateService;
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
            $reportDate = (string)($task['report_date'] ?? '');
            $p0Gate = (new P0OtaDownstreamGateService())->resolveRuntime(
                $reportDate,
                $hotelId,
                null,
                ['ctrip', 'meituan']
            );
            if (($p0Gate['status'] ?? '') !== 'ready') {
                $taskService->failTask(
                    $taskId,
                    'Exact-date dual-OTA external P0 verifier receipt is not ready.',
                    'blocked_by_p0_ota_gate'
                );
                $taskService->dispatchQueuedTasks();
                $output->writeln('AI report task blocked by exact-date dual-OTA P0 gate.');
                return 2;
            }
            $report = (new AiDailyReportService())->generate(
                [$hotelId],
                $hotelId,
                $reportDate,
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
