<?php
declare(strict_types=1);

namespace app\command;

use app\service\HourlyHotelMonitorWechatService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;

final class BroadcastHourlyHotelMonitor extends Command
{
    protected function configure()
    {
        $this->setName('hotel-monitor:wechat-broadcast')
            ->addOption('hotel-id', null, Option::VALUE_REQUIRED, 'Hotel ID')
            ->addOption('test-robot-id', null, Option::VALUE_REQUIRED, 'Enabled test-group robot ID')
            ->addOption('robot-id', null, Option::VALUE_REQUIRED, 'Enabled formal operating-group robot ID')
            ->addOption('with-visual-card', null, Option::VALUE_NONE, 'Send the matching image card to the approved test group')
            ->addOption('no-push', null, Option::VALUE_NONE, 'Build the real payload without external delivery')
            ->setDescription('Send one hotel-scoped hourly operating monitor broadcast.');
    }

    protected function execute(Input $input, Output $output)
    {
        $hotelId = (int)$input->getOption('hotel-id');
        $testRobotId = (int)$input->getOption('test-robot-id');
        $formalRobotId = (int)$input->getOption('robot-id');
        if ($hotelId <= 0 || (($testRobotId > 0) === ($formalRobotId > 0))) {
            $output->writeln('hotel-id and exactly one of test-robot-id or robot-id must be positive integers.');
            return 1;
        }
        $robotId = $testRobotId > 0 ? $testRobotId : $formalRobotId;
        $testOnly = $testRobotId > 0;
        try {
            $result = (new HourlyHotelMonitorWechatService())->run(
                $hotelId,
                $robotId,
                !$input->getOption('no-push'),
                null,
                $testOnly
            );
            if ((bool)$input->getOption('with-visual-card')) {
                $result['visual_card'] = (bool)$input->getOption('no-push')
                    ? ['status' => 'skipped_no_push']
                    : $this->sendVisualCard($hotelId, $robotId, $testOnly);
            }
            $output->writeln((string)json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            return in_array((string)($result['status'] ?? ''), ['sent', 'dry_run', 'partial'], true) ? 0 : 2;
        } catch (\Throwable $e) {
            $output->writeln('hotel monitor broadcast failed: ' . mb_substr($e->getMessage(), 0, 200, 'UTF-8'));
            return 1;
        }
    }

    /** @return array<string,mixed> */
    private function sendVisualCard(int $hotelId, int $robotId, bool $testOnly): array
    {
        $root = dirname(__DIR__, 2);
        $script = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'send_test_wechat_visual_card.php';
        if (!is_file($script)) {
            throw new \RuntimeException('hourly_monitor_visual_card_sender_missing');
        }
        $pipes = [];
        $process = proc_open(
            array_merge(
                [PHP_BINARY, $script, '--hotel-id', (string)$hotelId],
                $testOnly ? ['--test-robot-id', (string)$robotId] : ['--robot-id', (string)$robotId]
            ),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('hourly_monitor_visual_card_sender_start_failed');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $decoded = json_decode(is_string($stdout) ? $stdout : '', true);
        $decoded = is_array($decoded) ? $decoded : [];
        return [
            'status' => $exitCode === 0 ? 'sent' : 'not_sent',
            'exit_code' => $exitCode,
            'delivery_status' => (string)($decoded['delivery']['delivery_status'] ?? ''),
            'reused' => (bool)($decoded['delivery']['reused'] ?? false),
            'error_summary' => $exitCode === 0 ? '' : mb_strcut(trim((string)$stderr), 0, 180, 'UTF-8'),
        ];
    }
}
