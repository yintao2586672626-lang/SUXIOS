<?php
declare(strict_types=1);

namespace app\command;

use app\service\HotelAutopilotKickQueueService;
use app\service\HotelAutopilotLifecycleService;
use Throwable;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;

final class ReconcileHotelAutopilotLifecycle extends Command
{
    protected function configure(): void
    {
        $this->setName('hotel:autopilot-reconcile')
            ->addOption('hotel-limit', null, Option::VALUE_REQUIRED, 'Maximum hotels in this bounded pass', 500)
            ->addOption('after-hotel-id', null, Option::VALUE_REQUIRED, 'Only process hotel ids greater than this cursor', 0)
            ->addOption('all-pages', null, Option::VALUE_NONE, 'Continue bounded pages until the current hotel catalog tail')
            ->addOption('provision-dispatchers', null, Option::VALUE_NONE, 'Provision exact per-hotel tasks and request the first run once')
            ->setDescription('Advance secret-free hotel collection, analysis and profile lifecycle projections');
    }

    protected function execute(Input $input, Output $output): int
    {
        $limit = (int)$input->getOption('hotel-limit');
        $afterHotelId = (int)$input->getOption('after-hotel-id');
        if ($limit < 1 || $limit > 500 || $afterHotelId < 0) {
            $output->writeln($this->json([
                'status' => 'invalid_arguments',
                'failure_code' => 'hotel_autopilot_reconcile_arguments_invalid',
                'external_ota_write_enabled' => false,
            ]));
            return 1;
        }

        try {
            $service = new HotelAutopilotLifecycleService();
            $provisionDispatchers = (bool)$input->getOption('provision-dispatchers');
            $kickQueue = [
                'status' => 'skipped',
                'processed_count' => 0,
                'failure_count' => 0,
                'results' => [],
                'external_action_triggered' => false,
                'auto_write_ota' => false,
                'sensitive_values_exposed' => false,
            ];
            if ($provisionDispatchers) {
                try {
                    // A small bounded drain keeps the four-minute Windows task
                    // below its limit even when dispatcher provisioning is slow.
                    $kickQueue = (new HotelAutopilotKickQueueService())->consumeDue(3);
                } catch (Throwable $error) {
                    $kickQueue = [
                        'status' => 'unavailable',
                        'processed_count' => 0,
                        'failure_count' => 1,
                        'failure_code' => $this->safeCode($error->getMessage())
                            ?: 'hotel_autopilot_kick_queue_unavailable',
                        'results' => [],
                        'external_action_triggered' => false,
                        'auto_write_ota' => false,
                        'sensitive_values_exposed' => false,
                    ];
                }
            }
            $allPages = (bool)$input->getOption('all-pages');
            $cursor = $afterHotelId;
            $pageCount = 0;
            $scannedHotelCount = 0;
            $failureCount = (int)($kickQueue['failure_count'] ?? 0);
            $results = [];
            do {
                $page = $service->reconcileDue($limit, $provisionDispatchers, $cursor);
                $pageCount++;
                $scannedHotelCount += (int)($page['scanned_hotel_count'] ?? 0);
                $failureCount += (int)($page['failure_count'] ?? 0);
                $results = array_merge($results, is_array($page['results'] ?? null) ? $page['results'] : []);
                $nextCursor = (int)($page['next_after_hotel_id'] ?? $cursor);
                $hasNextPage = (int)($page['scanned_hotel_count'] ?? 0) >= $limit
                    && $nextCursor > $cursor;
                $cursor = $nextCursor;
            } while ($allPages && $hasNextPage);

            $result = [
                'status' => $failureCount === 0 ? 'completed' : 'partial',
                'hotel_count' => count($results),
                'scanned_hotel_count' => $scannedHotelCount,
                'failure_count' => $failureCount,
                'page_count' => $pageCount,
                'next_after_hotel_id' => $cursor,
                'all_pages' => $allPages,
                'provision_dispatchers' => $provisionDispatchers,
                'kick_queue' => $kickQueue,
                'external_ota_write_enabled' => false,
                'results' => $results,
            ];
            $output->writeln($this->json($result));
            return (string)($result['status'] ?? '') === 'completed' ? 0 : 2;
        } catch (Throwable $error) {
            $output->writeln($this->json([
                'status' => 'blocked',
                'failure_code' => $this->safeCode($error->getMessage()) ?: 'hotel_autopilot_reconcile_failed',
                'external_ota_write_enabled' => false,
                'sensitive_values_exposed' => false,
            ]));
            return 2;
        }
    }

    /** @param array<string,mixed> $value */
    private function json(array $value): string
    {
        return (string)json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    private function safeCode(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_.:-]+/', '_', $value) ?? '';
        return trim(substr($value, 0, 120), '_');
    }
}
