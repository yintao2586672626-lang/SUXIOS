<?php
declare(strict_types=1);

namespace app\command;

use app\service\AiReportGenerationTaskService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

final class CleanupAiReportTasks extends Command
{
    protected function configure()
    {
        $this->setName('ai-daily-report:cleanup')
            ->addOption('task-retention-days', null, Option::VALUE_REQUIRED, 'Terminal task retention days')
            ->addOption('cache-retention-days', null, Option::VALUE_REQUIRED, 'Reusable input cache retention days')
            ->addOption('limit', null, Option::VALUE_REQUIRED, 'Maximum rows removed from each table; default 500')
            ->addOption('dry-run', null, Option::VALUE_NONE, 'Report eligible rows without deleting them')
            ->setDescription('Remove expired AI report task metadata, cache rows and exact task logs');
    }

    protected function execute(Input $input, Output $output)
    {
        $taskDays = $this->positiveIntegerOption($input, 'task-retention-days', 3650);
        $cacheDays = $this->positiveIntegerOption($input, 'cache-retention-days', 3650);
        $limit = $this->positiveIntegerOption($input, 'limit', 5000) ?? 500;
        if ($taskDays === false || $cacheDays === false || $limit === false) {
            $output->writeln('Retention days and limit must be positive integers within their allowed range.');
            return 2;
        }

        $result = (new AiReportGenerationTaskService())->cleanupExpiredRecords(
            $taskDays,
            $cacheDays,
            (bool)$input->getOption('dry-run'),
            $limit
        );
        $output->writeln((string)json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return 0;
    }

    private function positiveIntegerOption(Input $input, string $name, int $maximum): int|false|null
    {
        $value = trim((string)($input->getOption($name) ?? ''));
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d+$/D', $value) !== 1 || (int)$value < 1 || (int)$value > $maximum) {
            return false;
        }
        return (int)$value;
    }
}
