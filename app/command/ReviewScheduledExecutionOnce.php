<?php
declare(strict_types=1);

namespace app\command;

use app\service\OperationManagementService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;

final class ReviewScheduledExecutionOnce extends Command
{
    protected function configure(): void
    {
        $this->setName('operation:scheduled-review-once')
            ->addOption('hotel-id', null, Option::VALUE_REQUIRED, 'Exact system hotel id')
            ->addOption('task-id', null, Option::VALUE_REQUIRED, 'Exact execution task id')
            ->addOption('execute', null, Option::VALUE_NONE, 'Append trusted same-scope readback; omitted means preview')
            ->setDescription('Preview or reconcile one due saved-OTA execution review without deciding its outcome');
    }

    protected function execute(Input $input, Output $output): int
    {
        $hotelId = (int)$input->getOption('hotel-id');
        $taskId = (int)$input->getOption('task-id');
        if ($hotelId <= 0 || $taskId <= 0) {
            $output->writeln('Positive --hotel-id and --task-id values are required.');
            return 1;
        }

        $service = new OperationManagementService();
        try {
            $task = $service->readExecutionTask($taskId, [$hotelId]);
            $preview = [
                'status' => 'preview',
                'hotel_id' => $hotelId,
                'task_id' => $taskId,
                'task_status' => (string)($task['status'] ?? ''),
                'result_status' => (string)($task['result_status'] ?? ''),
                'review_at' => (string)($task['review_available_at'] ?? ''),
                'review_is_available' => (bool)($task['review_is_available'] ?? false),
                'source_verified' => (bool)($task['evidence_truth']['source_verified'] ?? false),
                'timer_enabled' => null,
                'timer_status' => 'external_scheduler_unverified',
                'next_action' => 'Run with --execute only after the saved review window; scheduler enablement is separate.',
            ];
            if (!$input->getOption('execute')) {
                $output->writeln($this->encode($preview));
                return 0;
            }

            $result = $service->reconcileScheduledExecutionTask($taskId, [$hotelId]);
            $result['timer_enabled'] = null;
            $result['timer_status'] = 'external_scheduler_unverified';
            $output->writeln($this->encode($result));

            return in_array((string)($result['status'] ?? ''), ['source_readback_verified', 'already_reviewed'], true)
                ? 0
                : 2;
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            $output->writeln($this->encode([
                'status' => 'not_executed',
                'hotel_id' => $hotelId,
                'task_id' => $taskId,
                'reason' => $exception->getMessage(),
                'timer_enabled' => null,
                'timer_status' => 'external_scheduler_unverified',
            ]));
            return 2;
        }
    }

    private function encode(array $value): string
    {
        return (string)json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }
}
