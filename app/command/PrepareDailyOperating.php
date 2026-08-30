<?php
declare(strict_types=1);

namespace app\command;

use app\service\DailyOperatingPreparationService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use think\facade\Db;

final class PrepareDailyOperating extends Command
{
    protected function configure(): void
    {
        $this->setName('operation:prepare-daily')
            ->addOption('hotel-id', null, Option::VALUE_REQUIRED, 'Exact system hotel id')
            ->addOption('target-date', null, Option::VALUE_REQUIRED, 'Business date YYYY-MM-DD; defaults to yesterday')
            ->setDescription('Prepare one pending daily priority and trusted broadcast without messages or execution');
    }

    protected function execute(Input $input, Output $output): int
    {
        $hotelId = (int)$input->getOption('hotel-id');
        $targetDate = trim((string)$input->getOption('target-date'));
        if ($targetDate === '') {
            $targetDate = (new \DateTimeImmutable(
                'now',
                new \DateTimeZone('Asia/Shanghai')
            ))->modify('-1 day')->format('Y-m-d');
        }
        if ($hotelId <= 0) {
            $output->writeln('{"status":"failed","reason":"hotel_id_invalid"}');
            return 1;
        }
        try {
            $tenantId = (int)Db::name('hotels')
                ->where('id', $hotelId)
                ->where('status', 1)
                ->value('tenant_id');
            if ($tenantId <= 0) {
                throw new \RuntimeException('daily_operating_hotel_scope_unavailable');
            }
            $result = (new DailyOperatingPreparationService())->prepare(
                $tenantId,
                $hotelId,
                $targetDate
            );
            $output->writeln((string)json_encode(
                $result,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ));
            return (string)($result['status'] ?? '') === 'prepared' ? 0 : 2;
        } catch (\Throwable $error) {
            $reason = strtolower(trim($error->getMessage()));
            $reason = preg_replace('/[^a-z0-9_-]+/', '_', $reason) ?: 'daily_operating_preparation_failed';
            $output->writeln((string)json_encode([
                'status' => 'failed',
                'hotel_id' => $hotelId,
                'target_date' => $targetDate,
                'reason' => substr(trim($reason, '_'), 0, 120),
            ], JSON_UNESCAPED_SLASHES));
            return 1;
        }
    }
}
