<?php
declare(strict_types=1);

namespace app\command;

use app\service\OperationScheduledReviewBatchService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;

final class ReviewScheduledExecutions extends Command
{
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
        $hotelId = (int)$input->getOption('hotel-id');
        $limit = max(1, min(100, (int)$input->getOption('limit')));
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
}
