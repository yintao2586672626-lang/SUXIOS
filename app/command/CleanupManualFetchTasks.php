<?php
declare(strict_types=1);

namespace app\command;

use app\service\ManualOnlineFetchTaskService;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

final class CleanupManualFetchTasks extends Command
{
    protected function configure()
    {
        $this->setName('online-data:cleanup-manual-fetch-tasks')
            ->addOption('retention-seconds', null, Option::VALUE_REQUIRED, 'Override task retention seconds')
            ->addOption('dry-run', null, Option::VALUE_NONE, 'Report expired tasks without deleting them')
            ->setDescription('Remove expired local manual OTA fetch task artifacts');
    }

    protected function execute(Input $input, Output $output)
    {
        $retentionInput = trim((string)($input->getOption('retention-seconds') ?? ''));
        if ($retentionInput !== ''
            && (preg_match('/^\d+$/D', $retentionInput) !== 1 || (int)$retentionInput <= 0)
        ) {
            $output->writeln('retention-seconds must be a positive integer.');
            return 2;
        }
        $result = (new ManualOnlineFetchTaskService())->cleanupExpiredTasks(
            $retentionInput === '' ? null : (int)$retentionInput,
            null,
            (bool)$input->getOption('dry-run')
        );
        $output->writeln((string)json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return ($result['errors'] ?? 0) === 0 ? 0 : 1;
    }
}
