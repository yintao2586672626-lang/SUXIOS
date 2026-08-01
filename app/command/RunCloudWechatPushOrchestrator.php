<?php
declare(strict_types=1);

namespace app\command;

use app\service\CloudWechatPushOrchestratorService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;

final class RunCloudWechatPushOrchestrator extends Command
{
    protected function configure()
    {
        $this->setName('cloud-wechat-push:run')
            ->addOption('dispatch', null, Option::VALUE_NONE, 'Deliver explicitly enabled account policies; preview is the default')
            ->addOption('at', null, Option::VALUE_REQUIRED, 'Asia/Shanghai time, YYYY-MM-DD HH:ii:ss')
            ->addOption('limit', null, Option::VALUE_REQUIRED, 'Maximum policies, 1-100', '50')
            ->setDescription('Preview or run account-scoped cloud WeCom push policies without triggering OTA collection.');
    }

    protected function execute(Input $input, Output $output)
    {
        $timezone = new \DateTimeZone('Asia/Shanghai');
        $rawTime = trim((string)$input->getOption('at'));
        try {
            $observedAt = $rawTime === ''
                ? new \DateTimeImmutable('now', $timezone)
                : new \DateTimeImmutable($rawTime, $timezone);
        } catch (\Throwable) {
            $output->writeln('Invalid --at time.');
            return 1;
        }
        $limit = max(1, min(100, (int)$input->getOption('limit')));
        try {
            $result = (new CloudWechatPushOrchestratorService())->runDue(
                $observedAt,
                (bool)$input->getOption('dispatch'),
                $limit
            );
            $output->writeln((string)json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            return 0;
        } catch (\Throwable $exception) {
            $output->writeln('Cloud WeCom push orchestration failed: ' . mb_strcut($exception->getMessage(), 0, 240, 'UTF-8'));
            return 1;
        }
    }
}
