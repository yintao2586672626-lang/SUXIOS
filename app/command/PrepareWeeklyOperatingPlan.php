<?php
declare(strict_types=1);

namespace app\command;

use app\service\WeeklyOperatingPlanSnapshotService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use think\facade\Db;

final class PrepareWeeklyOperatingPlan extends Command
{
    protected function configure(): void
    {
        $this->setName('operation:prepare-weekly')
            ->addOption('hotel-id', null, Option::VALUE_REQUIRED, 'Exact system hotel id')
            ->addOption('week-end', null, Option::VALUE_REQUIRED, 'Inclusive week end YYYY-MM-DD; defaults to yesterday')
            ->setDescription('Persist one source-backed weekly operating plan without messaging or execution');
    }

    protected function execute(Input $input, Output $output): int
    {
        $hotelId = (int)$input->getOption('hotel-id');
        $weekEnd = trim((string)$input->getOption('week-end'));
        if ($weekEnd === '') {
            $weekEnd = (new \DateTimeImmutable(
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
            if ($tenantId <= 0) throw new \RuntimeException('weekly_plan_hotel_scope_unavailable');
            $result = (new WeeklyOperatingPlanSnapshotService())->generateAndReadback(
                $tenantId,
                $hotelId,
                $weekEnd,
                0,
                'background'
            );
            $output->writeln((string)json_encode(
                $result,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ));
            return ($result['readback_verified'] ?? false) === true ? 0 : 2;
        } catch (\Throwable $error) {
            $reason = strtolower(trim($error->getMessage()));
            $reason = preg_replace('/[^a-z0-9_-]+/', '_', $reason) ?: 'weekly_plan_preparation_failed';
            $output->writeln((string)json_encode([
                'status' => 'failed',
                'hotel_id' => $hotelId,
                'week_end' => $weekEnd,
                'reason' => substr(trim($reason, '_'), 0, 120),
            ], JSON_UNESCAPED_SLASHES));
            return 1;
        }
    }
}
