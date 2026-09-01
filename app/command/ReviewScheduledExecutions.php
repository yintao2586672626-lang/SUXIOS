<?php
declare(strict_types=1);

namespace app\command;

use app\service\OperationScheduledReviewBatchService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use think\facade\Db;

final class ReviewScheduledExecutions extends Command
{
    private const ACTIVE_HOTEL_PAGE_SIZE = 500;

    protected function configure(): void
    {
        $this->setName('operation:scheduled-reviews')
            ->addOption('hotel-id', null, Option::VALUE_REQUIRED, 'Exact system hotel id')
            ->addOption('limit', null, Option::VALUE_REQUIRED, 'Maximum due tasks, 1-100', '50')
            ->addOption('execute', null, Option::VALUE_NONE, 'Append same-scope source readback; omitted means preview')
            ->setDescription('Preview or reconcile due observing execution tasks without deciding outcomes');
    }

    protected function execute(Input $input, Output $output): int
    {
        $hotelScope = strtolower(trim((string)$input->getOption('hotel-id')));
        $limit = max(1, min(100, (int)$input->getOption('limit')));
        if ($hotelScope === 'all-active') {
            return $this->reviewAllActiveHotels(
                $limit,
                (bool)$input->getOption('execute'),
                $output
            );
        }
        $hotelId = (int)$hotelScope;
        if ($hotelId <= 0) {
            $output->writeln('{"status":"failed","reason":"hotel_id_invalid"}');
            return 1;
        }
        try {
            $result = (new OperationScheduledReviewBatchService())->run(
                $hotelId,
                $limit,
                (bool)$input->getOption('execute')
            );
            $output->writeln((string)json_encode(
                $result,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ));
            return (string)($result['status'] ?? '') === 'partial' ? 2 : 0;
        } catch (\Throwable $error) {
            $reason = strtolower(trim($error->getMessage()));
            $reason = preg_replace('/[^a-z0-9_-]+/', '_', $reason) ?: 'scheduled_review_failed';
            $output->writeln((string)json_encode([
                'status' => 'failed',
                'hotel_id' => $hotelId,
                'reason' => substr(trim($reason, '_'), 0, 120),
            ], JSON_UNESCAPED_SLASHES));
            return 1;
        }
    }

    private function reviewAllActiveHotels(int $limit, bool $execute, Output $output): int
    {
        try {
            $rows = [];
            $problemCount = 0;
            $hotelCount = 0;
            $lastHotelId = 0;
            $service = new OperationScheduledReviewBatchService();
            do {
                $hotelIds = $this->activeHotelPage($lastHotelId);
                if ($hotelIds === []) {
                    break;
                }
                $pageLastHotelId = $lastHotelId;
                foreach ($hotelIds as $hotelId) {
                    $pageLastHotelId = max($pageLastHotelId, $hotelId);
                    $hotelCount++;
                    try {
                        $result = $service->run($hotelId, $limit, $execute);
                        $status = (string)($result['status'] ?? 'partial');
                        if ($status === 'partial') {
                            $problemCount++;
                        }
                        $rows[] = [
                            'hotel_id' => $hotelId,
                            'status' => $status,
                            'candidate_count' => (int)($result['candidate_count'] ?? 0),
                            'processed_count' => (int)($result['processed_count'] ?? 0),
                        ];
                    } catch (\Throwable $error) {
                        $problemCount++;
                        $rows[] = [
                            'hotel_id' => $hotelId,
                            'status' => 'failed',
                            'reason_code' => $this->safeReason($error, 'scheduled_review_failed'),
                        ];
                    }
                }
                if ($pageLastHotelId <= $lastHotelId) {
                    throw new \RuntimeException('scheduled_review_active_hotel_pagination_stalled');
                }
                $lastHotelId = $pageLastHotelId;
            } while (count($hotelIds) === self::ACTIVE_HOTEL_PAGE_SIZE);
            $status = $hotelCount === 0 ? 'no_active_hotels' : ($problemCount > 0 ? 'partial' : 'completed');
            $output->writeln((string)json_encode([
                'contract_version' => 'operation_scheduled_review_multi_hotel.v1',
                'status' => $status,
                'mode' => $execute ? 'execute_source_readback' : 'preview',
                'hotel_count' => $hotelCount,
                'problem_count' => $problemCount,
                'rows' => $rows,
                'human_outcome_confirmation_required' => true,
                'automatic_outcome_decision' => false,
                'external_write_count' => 0,
                'message_sent' => false,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return $problemCount > 0 ? 2 : 0;
        } catch (\Throwable $error) {
            $output->writeln((string)json_encode([
                'status' => 'failed',
                'reason' => $this->safeReason($error, 'scheduled_review_failed'),
            ], JSON_UNESCAPED_SLASHES));
            return 1;
        }
    }

    /** @return list<int> */
    private function activeHotelPage(int $afterId): array
    {
        return array_values(array_map(
            'intval',
            Db::name('hotels')
                ->where('status', 1)
                ->where('id', '>', max(0, $afterId))
                ->order('id', 'asc')
                ->limit(self::ACTIVE_HOTEL_PAGE_SIZE)
                ->column('id')
        ));
    }

    private function safeReason(\Throwable $error, string $fallback): string
    {
        $reason = strtolower(trim($error->getMessage()));
        $reason = preg_replace('/[^a-z0-9_-]+/', '_', $reason) ?: $fallback;
        return substr(trim($reason, '_'), 0, 120);
    }
}
