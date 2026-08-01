<?php
declare(strict_types=1);

namespace app\command;

use app\service\ManualNotificationScheduleService;
use app\service\WechatRobotDeliveryService;
use DateTimeImmutable;
use DateTimeZone;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;

final class RunManualNotificationSchedule extends Command
{
    protected function configure()
    {
        $this->setName('manual-notification:schedule')
            ->addOption('preview', null, Option::VALUE_NONE, 'Preview due notifications without sending (default)')
            ->addOption('dispatch', null, Option::VALUE_NONE, 'Send due notifications; preview is the default')
            ->addOption('mode', null, Option::VALUE_REQUIRED, 'test|formal; identities never cross modes', 'test')
            ->addOption('hotel-id', null, Option::VALUE_REQUIRED, 'Optional exact persisted-plan hotel scope')
            ->addOption('robot-id', null, Option::VALUE_REQUIRED, 'Optional exact persisted-plan robot scope')
            ->addOption('at', null, Option::VALUE_REQUIRED, 'Asia/Shanghai time, YYYY-MM-DD HH:ii:ss')
            ->addOption('limit', null, Option::VALUE_REQUIRED, 'Maximum due records to process, 1-500', '100')
            ->setDescription('Preview or explicitly dispatch due saved manual WeCom notifications.');
    }

    protected function execute(Input $input, Output $output)
    {
        $timezone = new DateTimeZone('Asia/Shanghai');
        $rawTime = trim((string)$input->getOption('at'));
        try {
            $observedAt = $rawTime === ''
                ? new DateTimeImmutable('now', $timezone)
                : new DateTimeImmutable($rawTime, $timezone);
        } catch (\Throwable) {
            $output->writeln('Invalid --at time.');
            return 1;
        }

        $dispatch = (bool)$input->getOption('dispatch');
        if ($dispatch && (bool)$input->getOption('preview')) {
            $output->writeln('Use either --preview or --dispatch, not both.');
            return 1;
        }
        $mode = strtolower(trim((string)$input->getOption('mode')));
        if (!in_array($mode, [
            ManualNotificationScheduleService::MODE_TEST,
            ManualNotificationScheduleService::MODE_FORMAL,
        ], true)) {
            $output->writeln('--mode must be test or formal.');
            return 1;
        }
        $scopeHotelId = max(0, (int)$input->getOption('hotel-id'));
        $scopeRobotId = max(0, (int)$input->getOption('robot-id'));
        if (($scopeHotelId > 0) !== ($scopeRobotId > 0)) {
            $output->writeln('--hotel-id and --robot-id must be provided together.');
            return 1;
        }
        $sender = null;
        if ($dispatch) {
            $delivery = new WechatRobotDeliveryService();
            $sender = static function (
                int $targetHotelId,
                int $targetRobotId,
                array $payload,
                array $context = []
            ) use ($delivery, $scopeHotelId, $scopeRobotId): array {
                if ($scopeHotelId > 0
                    && ($targetHotelId !== $scopeHotelId || $targetRobotId !== $scopeRobotId)
                ) {
                    return [
                        'delivery_status' => 'failed',
                        'error' => 'persisted_plan_target_scope_mismatch',
                    ];
                }
                return $delivery->deliverToPlanRobot(
                    (int)($context['tenant_id'] ?? 0),
                    $targetHotelId,
                    $targetRobotId,
                    (string)($context['robot_name'] ?? ''),
                    (int)($context['owner_user_id'] ?? 0),
                    (string)($context['mode'] ?? ''),
                    $payload
                );
            };
        }

        try {
            $result = (new ManualNotificationScheduleService($sender))->runDue(
                $observedAt,
                $dispatch,
                $mode,
                (int)$input->getOption('limit'),
                $scopeHotelId,
                $scopeRobotId
            );
            $json = json_encode(
                $result,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            );
            $output->writeln(is_string($json) ? $json : '{"status":"serialization_failed"}');
            if ($dispatch && (string)($result['status'] ?? '') !== 'dispatch_checked') {
                return 2;
            }
            return 0;
        } catch (\Throwable $exception) {
            $message = preg_replace(
                '/(key|token|secret|cookie|password|authorization|webhook)\s*[=:]\s*[^\s,;]+/iu',
                '$1=<redacted>',
                $exception->getMessage()
            ) ?? '';
            $output->writeln('Manual notification schedule failed: ' . mb_substr(trim($message), 0, 240, 'UTF-8'));
            return 1;
        }
    }

}
