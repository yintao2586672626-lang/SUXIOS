<?php
declare(strict_types=1);

namespace app\command;

use app\service\CloudCollectionDispatchService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;

final class RunCloudCollectionDispatch extends Command
{
    protected function configure(): void
    {
        $this->setName('cloud-collection:dispatch')
            ->addOption('mode', null, Option::VALUE_REQUIRED, 'yesterday_final or today_realtime')
            ->addOption('target-date', null, Option::VALUE_OPTIONAL, 'YYYY-MM-DD')
            ->addOption('enqueue', null, Option::VALUE_NONE, 'Persist gateway tasks; without this option only preview')
            ->setDescription('Plan or queue cloud OTA collection work for verified cloud Profiles');
    }

    protected function execute(Input $input, Output $output): int
    {
        $mode = (string)$input->getOption('mode');
        $targetDate = (string)$input->getOption('target-date');
        try {
            $service = new CloudCollectionDispatchService();
            $result = $input->getOption('enqueue')
                ? $service->enqueue($mode, $targetDate)
                : $service->preview($mode, $targetDate);
            $output->writeln((string)json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return 0;
        } catch (\Throwable $e) {
            $output->writeln(json_encode(['status' => 'blocked', 'reason' => $e->getMessage()], JSON_UNESCAPED_UNICODE));
            return 1;
        }
    }
}
