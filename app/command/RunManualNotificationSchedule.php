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
use think\facade\Db;

final class RunManualNotificationSchedule extends Command
{
    protected function configure()
    {
        $this->setName('manual-notification:schedule')
            ->addOption('preview', null, Option::VALUE_NONE, 'Preview due notifications without sending (default)')
            ->addOption('dispatch', null, Option::VALUE_NONE, 'Send due notifications; preview is the default')
            ->addOption('mode', null, Option::VALUE_REQUIRED, 'test|formal; identities never cross modes', 'test')
            ->addOption('hotel-id', null, Option::VALUE_REQUIRED, 'Optional exact hotel scope for a test-only deployment')
            ->addOption('robot-id', null, Option::VALUE_REQUIRED, 'Optional exact robot scope for a test-only deployment')
            ->addOption('at', null, Option::VALUE_REQUIRED, 'Asia/Shanghai time, YYYY-MM-DD HH:ii:ss')
            ->addOption('limit', null, Option::VALUE_REQUIRED, 'Maximum saved records to inspect, 1-500', '100')
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
        $scopeHotelId = max(0, (int)$input->getOption('hotel-id'));
        $scopeRobotId = max(0, (int)$input->getOption('robot-id'));
        if (($scopeHotelId > 0) !== ($scopeRobotId > 0)) {
            $output->writeln('--hotel-id and --robot-id must be provided together.');
            return 1;
        }
        if ($scopeHotelId > 0) {
            if ($mode !== ManualNotificationScheduleService::MODE_TEST
                || $scopeHotelId !== 80
                || $scopeRobotId !== 1
            ) {
                $output->writeln('Scoped dispatch is restricted to --mode=test --hotel-id=80 --robot-id=1.');
                return 1;
            }
            try {
                $this->assertTestOnlyScope($scopeHotelId, $scopeRobotId);
            } catch (\Throwable $exception) {
                $output->writeln('Test-only scope check failed: ' . mb_substr($exception->getMessage(), 0, 180, 'UTF-8'));
                return 1;
            }
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
                        'error' => 'test_only_target_scope_mismatch',
                    ];
                }
                return $delivery->deliverToHotel($targetHotelId, $payload, [$targetRobotId]);
            };
        }

        try {
            $result = (new ManualNotificationScheduleService($sender))->runDue(
                $observedAt,
                $dispatch,
                $mode,
                (int)$input->getOption('limit'),
                $scopeHotelId
            );
            $json = json_encode(
                $result,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            );
            $output->writeln(is_string($json) ? $json : '{"status":"serialization_failed"}');
            if ($dispatch && (int)($result['failed_count'] ?? 0) > 0) {
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

    private function assertTestOnlyScope(int $hotelId, int $robotId): void
    {
        $robot = Db::name('competitor_wechat_robot')
            ->where('id', $robotId)
            ->where('store_id', $hotelId)
            ->where('name', \app\service\ManualNotificationService::TEST_ROBOT_NAME)
            ->where('status', 1)
            ->field('id,store_id,name,status')
            ->find();
        if (!is_array($robot)) {
            throw new \RuntimeException('hotel80_test_robot1_identity_missing');
        }

        $records = Db::name('manual_notifications')
            ->where('enabled', 1)
            ->where('schedule_status', 'schedule_enabled')
            ->whereIn('trigger_type', ['daily_fixed_time', 'hourly_on_the_hour'])
            ->where('send_method', 'wecom_test')
            ->field('id,hotel_id,template_type,test_robot_id,test_robot_name')
            ->select()
            ->toArray();
        foreach ($records as $record) {
            if ((int)($record['hotel_id'] ?? 0) !== $hotelId
                || (int)($record['test_robot_id'] ?? 0) !== $robotId
                || trim((string)($record['test_robot_name'] ?? ''))
                    !== \app\service\ManualNotificationService::TEST_ROBOT_NAME
                || trim((string)($record['template_type'] ?? ''))
                    !== \app\service\ManualNotificationService::DYNAMIC_REPORT_TYPE
            ) {
                throw new \RuntimeException(
                    'enabled_test_schedule_outside_hotel80_robot1 notification_id='
                    . (int)($record['id'] ?? 0)
                );
            }
        }
    }
}
