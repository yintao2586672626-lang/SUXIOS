<?php
declare(strict_types=1);

namespace app\command;

use app\service\OperatingGoalInterventionMonitorService;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use think\facade\Db;

final class MonitorOperatingGoalInterventions extends Command
{
    protected function configure(): void
    {
        $this->setName('operation:goal-intervention-monitor')
            ->addOption('hotel-id', null, Option::VALUE_REQUIRED, 'Exact system hotel id; omit for a bounded enabled-hotel batch')
            ->addOption('business-date', null, Option::VALUE_REQUIRED, 'Exact business date YYYY-MM-DD; defaults to Asia/Shanghai today')
            ->addOption('hotel-limit', null, Option::VALUE_REQUIRED, 'Maximum enabled hotels in one batch', 50)
            ->addOption('after-hotel-id', null, Option::VALUE_REQUIRED, 'Only scan hotel ids greater than this cursor', 0)
            ->addOption('execute', null, Option::VALUE_NONE, 'Persist local monitor receipts, assessments and alert-only signals')
            ->setDescription('Preview or run verified-data operating-goal monitoring without writing any OTA platform');
    }

    protected function execute(Input $input, Output $output): int
    {
        $hotelId = (int)$input->getOption('hotel-id');
        $hotelLimit = (int)$input->getOption('hotel-limit');
        $afterHotelId = (int)$input->getOption('after-hotel-id');
        $businessDate = trim((string)$input->getOption('business-date'));
        $businessDate = $businessDate !== ''
            ? $businessDate
            : (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        if (!$this->validDate($businessDate)
            || $hotelId < 0
            || $afterHotelId < 0
            || $hotelLimit < 1
            || $hotelLimit > 500
        ) {
            $output->writeln($this->json([
                'status' => 'invalid_arguments',
                'message' => 'Use a valid --business-date and positive hotel ids; --hotel-limit must be 1..500.',
                'auto_write_ota' => false,
            ]));
            return 1;
        }

        try {
            $query = Db::name('hotels')
                ->field('id,tenant_id,name,status')
                ->where('status', 1);
            if ($hotelId > 0) {
                $query->where('id', $hotelId);
            } else {
                $query->where('id', '>', $afterHotelId)
                    ->order('id', 'asc')
                    ->limit($hotelLimit);
            }
            $hotels = $query->select()->toArray();
        } catch (Throwable) {
            $output->writeln($this->json([
                'status' => 'blocked',
                'reason' => 'enabled_hotel_scope_unavailable',
                'business_date' => $businessDate,
                'auto_write_ota' => false,
            ]));
            return 2;
        }

        $hotels = array_values(array_filter($hotels, static fn(mixed $row): bool =>
            is_array($row)
            && (int)($row['id'] ?? 0) > 0
            && (int)($row['tenant_id'] ?? 0) > 0
        ));
        if ($hotels === []) {
            $output->writeln($this->json([
                'status' => 'empty',
                'business_date' => $businessDate,
                'requested_hotel_id' => $hotelId,
                'message' => 'No enabled hotel matched the exact scope.',
                'timer_enabled' => null,
                'timer_status' => 'external_scheduler_unverified',
                'auto_write_ota' => false,
            ]));
            return $hotelId > 0 ? 2 : 0;
        }

        $execute = (bool)$input->getOption('execute');
        $service = new OperatingGoalInterventionMonitorService();
        $results = [];
        $failures = 0;
        foreach ($hotels as $hotel) {
            try {
                $result = $service->monitor(
                    (int)$hotel['tenant_id'],
                    (int)$hotel['id'],
                    $businessDate,
                    $execute
                );
                $results[] = $result;
                if (in_array((string)($result['status'] ?? ''), ['partial', 'migration_required'], true)) {
                    $failures++;
                }
            } catch (Throwable $exception) {
                $failures++;
                $results[] = [
                    'status' => 'blocked',
                    'tenant_id' => (int)$hotel['tenant_id'],
                    'hotel_id' => (int)$hotel['id'],
                    'business_date' => $businessDate,
                    'reason' => $this->safeReason($exception),
                    'external_action_triggered' => false,
                    'auto_write_ota' => false,
                ];
            }
        }

        $output->writeln($this->json([
            'status' => $failures === 0 ? ($execute ? 'completed' : 'preview') : 'partial',
            'business_date' => $businessDate,
            'execute' => $execute,
            'hotel_count' => count($hotels),
            'failure_count' => $failures,
            'next_after_hotel_id' => max(array_map(static fn(array $hotel): int => (int)$hotel['id'], $hotels)),
            'timer_enabled' => null,
            'timer_status' => 'external_scheduler_unverified',
            'external_action_triggered' => false,
            'auto_write_ota' => false,
            'results' => $results,
        ]));
        return $failures === 0 ? 0 : 2;
    }

    private function validDate(string $value): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        return $parsed !== false
            && ($errors === false || ((int)$errors['warning_count'] === 0 && (int)$errors['error_count'] === 0))
            && $parsed->format('Y-m-d') === $value;
    }

    private function safeReason(Throwable $exception): string
    {
        $message = strtolower(trim($exception->getMessage()));
        if ($message === '') {
            return 'goal_monitor_failed';
        }
        $message = preg_replace('/[^a-z0-9_.:-]+/', '_', $message) ?? '';
        return trim(substr($message, 0, 120), '_') ?: 'goal_monitor_failed';
    }

    private function json(array $value): string
    {
        return (string)json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }
}
