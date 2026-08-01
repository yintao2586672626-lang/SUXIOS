<?php
declare(strict_types=1);

namespace app\command;

use app\service\OtaFailureNotificationService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;

final class NotifyOtaFailure extends Command
{
    protected function configure(): void
    {
        $this->setName('online-data:notify-failure')
            ->addOption('hotel-id', null, Option::VALUE_REQUIRED, 'System hotel id')
            ->addOption('platform', null, Option::VALUE_REQUIRED, 'ctrip or meituan')
            ->addOption('reason', null, Option::VALUE_REQUIRED, 'Verified failure reason code')
            ->addOption('data-date', null, Option::VALUE_REQUIRED, 'Target data date, YYYY-MM-DD')
            ->addOption('actor-id', null, Option::VALUE_OPTIONAL, 'Auditing actor user id', '0')
            ->addOption('execute', null, Option::VALUE_NONE, 'Write or update the notification')
            ->setDescription('Notify the actual OTA/hotel submitter about a verified collection failure');
    }

    protected function execute(Input $input, Output $output): int
    {
        $hotelId = (int)$input->getOption('hotel-id');
        $platform = strtolower(trim((string)$input->getOption('platform')));
        $reason = strtolower(trim((string)$input->getOption('reason')));
        $dataDate = trim((string)$input->getOption('data-date'));
        $actorId = (int)$input->getOption('actor-id');

        if ($hotelId <= 0 || !in_array($platform, ['ctrip', 'meituan'], true)) {
            $output->writeln('A positive --hotel-id and --platform=ctrip|meituan are required.');
            return 1;
        }
        if ($reason === '' || preg_match('/^[a-z0-9_-]{1,64}$/D', $reason) !== 1) {
            $output->writeln('A safe verified --reason code is required.');
            return 1;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dataDate) !== 1 || strtotime($dataDate) === false) {
            $output->writeln('--data-date must use YYYY-MM-DD.');
            return 1;
        }

        $event = [
            'hotel_id' => $hotelId,
            'platform' => $platform,
            'reason_code' => $reason,
            'data_date' => $dataDate,
            'success' => false,
            'saved_count' => 0,
            'actor_user_id' => $actorId,
        ];
        if (!$input->getOption('execute')) {
            $output->writeln(json_encode([
                'status' => 'dry_run',
                'event' => $event,
                'next_action' => 'Run again with --execute after verifying the failure fact.',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return 0;
        }

        $result = (new OtaFailureNotificationService())->recordCollectionOutcome($event);
        $output->writeln(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return in_array((string)($result['status'] ?? ''), ['notified', 'no_failure'], true) ? 0 : 2;
    }
}
