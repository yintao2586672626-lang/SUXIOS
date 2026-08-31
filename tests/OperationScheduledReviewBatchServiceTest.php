<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperationScheduledReviewBatchService;
use PHPUnit\Framework\TestCase;

final class OperationScheduledReviewBatchServiceTest extends TestCase
{
    public function testPreviewClassifiesDueNotDueReviewedAndIneligibleWithoutMutation(): void
    {
        $reconcileCalls = 0;
        $service = new OperationScheduledReviewBatchService(
            static fn(): array => [1, 2, 3, 4, 4],
            static function (int $taskId): array {
                return match ($taskId) {
                    1 => self::task(1, 'executed', 'observing', true),
                    2 => self::task(2, 'executed', 'observing', false),
                    3 => self::task(3, 'executed', 'success', true),
                    default => self::task(4, 'pending', '', false),
                };
            },
            static function () use (&$reconcileCalls): array {
                $reconcileCalls++;
                return [];
            }
        );

        $result = $service->run(80, 50, false);

        self::assertSame('completed', $result['status']);
        self::assertSame('preview', $result['mode']);
        self::assertSame(4, $result['candidate_count']);
        self::assertSame(1, $result['counts']['due_preview']);
        self::assertSame(1, $result['counts']['not_due']);
        self::assertSame(1, $result['counts']['already_reviewed']);
        self::assertSame(1, $result['counts']['not_eligible']);
        self::assertSame(0, $reconcileCalls);
        self::assertTrue($result['human_outcome_confirmation_required']);
        self::assertFalse($result['automatic_outcome_decision']);
        self::assertFalse($result['automatic_sop_publish']);
        self::assertSame(0, $result['external_write_count']);
        self::assertFalse($result['message_sent']);
    }

    public function testExecuteReconcilesOnlyDueTasksAndKeepsHumanOutcomePending(): void
    {
        $calls = [];
        $service = new OperationScheduledReviewBatchService(
            static fn(): array => [11, 12, 13],
            static fn(int $taskId): array => self::task(
                $taskId,
                'executed',
                'observing',
                $taskId !== 13
            ),
            static function (int $taskId, int $hotelId) use (&$calls): array {
                $calls[] = [$taskId, $hotelId];
                return [
                    'status' => $taskId === 11
                        ? 'source_readback_verified'
                        : 'source_readback_missing',
                    'review_at' => '2026-08-29 02:00:00',
                    'source_verified' => $taskId === 11,
                    'outcome_status' => 'unverified',
                    'result_status' => 'observing',
                    'next_action' => $taskId === 11
                        ? 'human_confirm_review_result'
                        : 'collect_same_hotel_platform_metric_readback',
                ];
            }
        );

        $result = $service->run(80, 50, true);

        self::assertSame('completed', $result['status']);
        self::assertSame('execute_source_readback', $result['mode']);
        self::assertSame([[11, 80], [12, 80]], $calls);
        self::assertSame(1, $result['counts']['source_readback_verified']);
        self::assertSame(1, $result['counts']['source_readback_missing']);
        self::assertSame(1, $result['counts']['not_due']);
        self::assertSame('unverified', $result['rows'][0]['outcome_status']);
        self::assertSame('observing', $result['rows'][0]['result_status']);
        self::assertSame('human_confirm_review_result', $result['rows'][0]['next_action']);
    }

    public function testCrossHotelCandidateFailsClosedWithoutReconciling(): void
    {
        $calls = 0;
        $service = new OperationScheduledReviewBatchService(
            static fn(): array => [21],
            static fn(): array => self::task(21, 'executed', 'observing', true, 81),
            static function () use (&$calls): array {
                $calls++;
                return [];
            }
        );

        $result = $service->run(80, 50, true);

        self::assertSame('partial', $result['status']);
        self::assertSame(1, $result['counts']['failed']);
        self::assertSame('scheduled_review_task_scope_mismatch', $result['rows'][0]['reason_code']);
        self::assertSame(0, $calls);
    }

    public function testCandidateReadbackFailureIsVisibleAndMakesBatchPartial(): void
    {
        $service = new OperationScheduledReviewBatchService(
            static fn(): array => [
                'ids' => [],
                'scanned_count' => 1,
                'failures' => [[
                    'status' => 'failed',
                    'task_id' => 31,
                    'hotel_id' => 80,
                    'reason_code' => 'scheduled_review_task_read_failed',
                    'stage' => 'candidate_readback',
                ]],
            ],
            static fn(): array => [],
            static fn(): array => []
        );

        $result = $service->run(80, 50, false);
        self::assertSame('partial', $result['status']);
        self::assertSame(1, $result['counts']['failed']);
        self::assertSame(1, $result['scanned_candidate_count']);
        self::assertFalse($result['scan_truncated']);
        self::assertFalse($result['cursor_advanced']);
        self::assertSame('candidate_readback', $result['rows'][0]['stage']);
    }

    public function testCommandAndTimerPreservePreviewAndHumanDecisionBoundaries(): void
    {
        $root = dirname(__DIR__);
        $command = (string)file_get_contents($root . '/app/command/ReviewScheduledExecutions.php');
        $config = (string)file_get_contents($root . '/config/console.php');
        $service = (string)file_get_contents(
            $root . '/deploy/systemd/suxios-operation-scheduled-reviews@.service'
        );
        $timer = (string)file_get_contents(
            $root . '/deploy/systemd/suxios-operation-scheduled-reviews@.timer'
        );
        $releaseInstaller = (string)file_get_contents($root . '/deploy/cloud/install_release.sh');
        $batch = (string)file_get_contents($root . '/app/service/OperationScheduledReviewBatchService.php');
        $migration = (string)file_get_contents(
            $root . '/database/migrations/20260829_create_operation_scheduled_review_scan_cursors.sql'
        );

        self::assertStringContainsString("setName('operation:scheduled-reviews')", $command);
        self::assertStringContainsString("addOption('execute'", $command);
        self::assertStringContainsString("'operation:scheduled-reviews'", $config);
        self::assertStringContainsString('--hotel-id=%i', $service);
        self::assertStringContainsString('--execute', $service);
        self::assertStringContainsString('OnCalendar=*-*-* *:15:00 Asia/Shanghai', $timer);
        self::assertStringContainsString('DefaultInstance=all-active', $timer);
        self::assertStringContainsString("hotelScope === 'all-active'", $command);
        self::assertStringContainsString('suxios-operation-scheduled-reviews@all-active.timer', $releaseInstaller);
        self::assertStringContainsString(
            'systemctl enable "$INTERNAL_DAILY_TIMER" "$INTERNAL_REVIEW_TIMER"',
            $releaseInstaller
        );
        self::assertStringNotContainsString('wechat', strtolower($service . $timer));
        self::assertStringNotContainsString('success/near_success/failed', $service . $timer);
        self::assertStringContainsString('SCAN_BUDGET = 500', $batch);
        self::assertStringContainsString("where('task.id', '>', \$cursor)", $batch);
        self::assertStringContainsString('last_task_id', $batch);
        self::assertStringContainsString('uk_operation_review_scan_hotel', $migration);
        self::assertGreaterThan(
            strpos($batch, 'foreach ($candidateIds as $taskId)'),
            strpos($batch, '$this->writeScanCursor($hotelId')
        );
    }

    /** @return array<string,mixed> */
    private static function task(
        int $id,
        string $status,
        string $resultStatus,
        bool $reviewAvailable,
        int $hotelId = 80
    ): array {
        return [
            'id' => $id,
            'hotel_id' => $hotelId,
            'status' => $status,
            'result_status' => $resultStatus,
            'review_available_at' => '2026-08-29 02:00:00',
            'review_is_available' => $reviewAvailable,
        ];
    }
}
