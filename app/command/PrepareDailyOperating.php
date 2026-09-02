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
    private const ACTIVE_HOTEL_PAGE_SIZE = 500;

    protected function configure(): void
    {
        $this->setName('operation:prepare-daily')
            ->addOption('hotel-id', null, Option::VALUE_REQUIRED, 'Exact system hotel id')
            ->addOption('target-date', null, Option::VALUE_REQUIRED, 'Business date YYYY-MM-DD; defaults to yesterday')
            ->setDescription('Prepare one pending daily priority and trusted broadcast without messages or execution');
    }

    protected function execute(Input $input, Output $output): int
    {
        $hotelScope = strtolower(trim((string)$input->getOption('hotel-id')));
        $targetDate = trim((string)$input->getOption('target-date'));
        if ($targetDate === '') {
            $targetDate = (new \DateTimeImmutable(
                'now',
                new \DateTimeZone('Asia/Shanghai')
            ))->modify('-1 day')->format('Y-m-d');
        }
        if ($hotelScope === 'all-active') {
            return $this->prepareAllActiveHotels($targetDate, $output);
        }
        $hotelId = (int)$hotelScope;
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

    private function prepareAllActiveHotels(string $targetDate, Output $output): int
    {
        try {
            $rows = [];
            $prepared = 0;
            $total = 0;
            $lastHotelId = 0;
            $service = new DailyOperatingPreparationService();
            do {
                $hotels = $this->activeHotelPage($lastHotelId);
                if ($hotels === []) {
                    break;
                }
                $pageLastHotelId = $lastHotelId;
                foreach ($hotels as $hotel) {
                    $hotelId = (int)($hotel['id'] ?? 0);
                    $tenantId = (int)($hotel['tenant_id'] ?? 0);
                    $pageLastHotelId = max($pageLastHotelId, $hotelId);
                    $total++;
                    try {
                        $result = $service->prepare($tenantId, $hotelId, $targetDate);
                        if ((string)($result['status'] ?? '') === 'prepared') {
                            $prepared++;
                        }
                        $rows[] = [
                            'hotel_id' => $hotelId,
                            'status' => (string)($result['status'] ?? 'blocked'),
                            'reason_code' => (string)($result['reason_code']
                                ?? $result['daily_priority']['reason_code']
                                ?? ''),
                        ];
                    } catch (\Throwable $error) {
                        $rows[] = [
                            'hotel_id' => $hotelId,
                            'status' => 'failed',
                            'reason_code' => $this->safeReason($error, 'daily_operating_preparation_failed'),
                        ];
                    }
                }
                if ($pageLastHotelId <= $lastHotelId) {
                    throw new \RuntimeException('daily_operating_active_hotel_pagination_stalled');
                }
                $lastHotelId = $pageLastHotelId;
            } while (count($hotels) === self::ACTIVE_HOTEL_PAGE_SIZE);
            $status = $total === 0 ? 'no_active_hotels' : ($prepared === $total ? 'prepared' : 'partial');
            $output->writeln((string)json_encode([
                'contract_version' => 'daily_operating_preparation_batch.v1',
                'status' => $status,
                'target_date' => $targetDate,
                'hotel_count' => $total,
                'prepared_count' => $prepared,
                'problem_count' => $total - $prepared,
                'rows' => $rows,
                'automatic_approval' => false,
                'automatic_execution' => false,
                'external_write_count' => 0,
                'external_message_count' => 0,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return in_array($status, ['prepared', 'no_active_hotels'], true) ? 0 : 2;
        } catch (\Throwable $error) {
            $output->writeln((string)json_encode([
                'status' => 'failed',
                'target_date' => $targetDate,
                'reason' => $this->safeReason($error, 'daily_operating_preparation_failed'),
            ], JSON_UNESCAPED_SLASHES));
            return 1;
        }
    }

    /** @return list<array<string,mixed>> */
    private function activeHotelPage(int $afterId): array
    {
        return Db::name('hotels')
            ->field('id,tenant_id')
            ->where('status', 1)
            ->where('id', '>', max(0, $afterId))
            ->order('id', 'asc')
            ->limit(self::ACTIVE_HOTEL_PAGE_SIZE)
            ->select()
            ->toArray();
    }

    private function safeReason(\Throwable $error, string $fallback): string
    {
        $reason = strtolower(trim($error->getMessage()));
        $reason = preg_replace('/[^a-z0-9_-]+/', '_', $reason) ?: $fallback;
        return substr(trim($reason, '_'), 0, 120);
    }
}
