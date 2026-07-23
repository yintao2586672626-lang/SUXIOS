<?php
declare(strict_types=1);

namespace tests;

use app\command\AutoFetchOnlineData;
use app\service\ScheduledAutoFetchPolicy;
use PHPUnit\Framework\TestCase;

final class ScheduledAutoFetchPolicyTest extends TestCase
{
    private ScheduledAutoFetchPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new ScheduledAutoFetchPolicy();
    }

    public function testDueRunsCatchUpAfterConfiguredMinuteWithinBoundedWindows(): void
    {
        $status = [
            'historical_enabled' => true,
            'historical_schedule_time' => '10:00',
            'realtime_enabled' => true,
            'realtime_schedule_minute' => 5,
            'realtime_schedule_interval_hours' => 2,
        ];

        self::assertSame([], $this->policy->dueRuns(58, $status, $this->time('2026-07-16 09:59:00')));

        $runs = $this->policy->dueRuns(58, $status, $this->time('2026-07-16 10:37:00'));
        self::assertCount(2, $runs);
        self::assertSame('historical:2026-07-15', $runs[0]['slot_id']);
        self::assertSame('online_data_historical_executed_58_2026-07-15', $runs[0]['executed_key']);
        self::assertSame('online_data_historical_retry_58_2026-07-15', $runs[0]['retry_key']);
        self::assertSame('realtime:2026-07-16:10', $runs[1]['slot_id']);
        self::assertSame('online_data_realtime_executed_58_2026-07-16_10', $runs[1]['executed_key']);

        $laterHour = $this->policy->dueRuns(58, $status, $this->time('2026-07-16 11:37:00'));
        self::assertCount(1, $laterHour);
        self::assertSame('historical_daily', $laterHour[0]['period']);

        $status['failed_records'] = [[
            'slot_id' => 'realtime:2026-07-16:10',
            'data_period' => 'realtime_snapshot',
            'data_date' => '2026-07-16',
            'retry_exhausted' => false,
        ]];
        $withRetry = $this->policy->dueRuns(58, $status, $this->time('2026-07-16 11:37:00'));
        self::assertCount(2, $withRetry);
        self::assertSame('realtime-retry', $withRetry[0]['label']);
        self::assertSame('realtime:2026-07-16:10', $withRetry[0]['slot_id']);
        self::assertSame([], $withRetry[0]['target_platforms'] ?? []);

        $status['failed_records'][0]['failed_platforms'] = ['meituan'];
        $targetedRetry = $this->policy->dueRuns(58, $status, $this->time('2026-07-16 10:37:00'));
        $targetedRealtime = array_values(array_filter(
            $targetedRetry,
            static fn(array $run): bool => $run['slot_id'] === 'realtime:2026-07-16:10'
        ));
        self::assertCount(1, $targetedRealtime);
        self::assertSame(['meituan'], $targetedRealtime[0]['target_platforms'] ?? []);
    }

    public function testOutcomeRequiresRowsAndNoFailedConfiguredPlatform(): void
    {
        $complete = $this->policy->classifyOutcome([
            'success' => true,
            'saved_count' => 3,
            'platform_results' => [
                ['platform' => 'ctrip', 'success' => true, 'saved_count' => 3],
                ['platform' => 'meituan', 'success' => false, 'skipped' => true, 'saved_count' => 0],
            ],
        ]);
        self::assertTrue($complete['complete']);
        self::assertSame('success', $complete['status']);

        $partial = $this->policy->classifyOutcome([
            'success' => true,
            'saved_count' => 3,
            'platform_results' => [
                ['platform' => 'ctrip', 'success' => true, 'saved_count' => 3],
                ['platform' => 'meituan', 'success' => false, 'saved_count' => 0],
            ],
        ]);
        self::assertFalse($partial['complete']);
        self::assertSame('partial_success', $partial['status']);
        self::assertSame(['meituan'], $partial['failed_platforms']);
        self::assertSame(['ctrip'], $partial['successful_platforms']);

        $producerPartial = $this->policy->classifyOutcome([
            'success' => true,
            'saved_count' => 8,
            'failed_platforms' => ['meituan'],
            'successful_platforms' => ['ctrip', 'meituan'],
        ]);
        self::assertFalse($producerPartial['complete']);
        self::assertSame('partial_success', $producerPartial['status']);
        self::assertSame(['meituan'], $producerPartial['failed_platforms']);
        self::assertSame(['ctrip'], $producerPartial['successful_platforms']);

        $empty = $this->policy->classifyOutcome(['success' => true, 'saved_count' => 0]);
        self::assertFalse($empty['complete']);
        self::assertSame('failed', $empty['status']);

        $failedPlatformRetry = $this->policy->classifyOutcome([
            'success' => true,
            'saved_count' => 2,
            'platform_results' => [
                ['platform' => 'meituan', 'success' => true, 'saved_count' => 2],
            ],
        ]);
        self::assertTrue($failedPlatformRetry['complete']);
        self::assertSame(['meituan'], $failedPlatformRetry['successful_platforms']);
        self::assertSame([], $failedPlatformRetry['failed_platforms']);
    }

    public function testCliReceiptKeepsExportablePartialRunsOutOfExecutedState(): void
    {
        $partialResult = [
            'success' => true,
            'saved_count' => 2,
            'platform_results' => [
                $this->verifiedPlatformResult('ctrip', 25, 1001, true),
                $this->verifiedPlatformResult('meituan', 68, 1002, false),
            ],
        ];
        $partialOutcome = $this->policy->classifyOutcome($partialResult);
        $partialReceipt = $this->buildMachineReceipt($partialOutcome, $partialResult);

        self::assertFalse($partialOutcome['complete']);
        self::assertSame('partial_success', $partialOutcome['status']);
        self::assertTrue($partialReceipt['exportable_snapshot_complete']);
        self::assertFalse($partialReceipt['collection_complete']);
        self::assertSame(['success', 'partial'], array_column($partialReceipt['source_tasks'], 'collection_status'));

        $completeResult = $partialResult;
        $completeResult['platform_results'][1]['success'] = true;
        $completeOutcome = $this->policy->classifyOutcome($completeResult);
        $completeReceipt = $this->buildMachineReceipt($completeOutcome, $completeResult);

        self::assertTrue($completeOutcome['complete']);
        self::assertTrue($completeReceipt['exportable_snapshot_complete']);
        self::assertTrue($completeReceipt['collection_complete']);

        $unverifiedResult = $completeResult;
        $unverifiedResult['platform_results'][1]['run_readback']['readback_verified'] = false;
        $unverifiedOutcome = $this->policy->classifyOutcome($unverifiedResult);
        $unverifiedReceipt = $this->buildMachineReceipt($unverifiedOutcome, $unverifiedResult);

        self::assertTrue($unverifiedOutcome['complete']);
        self::assertFalse($unverifiedReceipt['exportable_snapshot_complete']);
        self::assertFalse($unverifiedReceipt['collection_complete']);
    }

    public function testRetryStateUsesBoundedBackoffAndFailsClosedWhenExhausted(): void
    {
        $now = $this->time('2026-07-16 10:00:00');
        $first = $this->policy->nextRetryState([], 3, 5, $now, 'failed', 'login expired');
        self::assertSame(1, $first['attempts']);
        self::assertSame('2026-07-16 10:05:00', $first['next_retry_at']);
        self::assertFalse($first['retry_exhausted']);
        self::assertFalse($this->policy->retryDue($first, 3, $this->time('2026-07-16 10:04:59')));
        self::assertTrue($this->policy->retryDue($first, 3, $this->time('2026-07-16 10:05:00')));

        $second = $this->policy->nextRetryState($first, 3, 5, $this->time('2026-07-16 10:05:00'), 'partial_success', 'one platform failed');
        self::assertSame(2, $second['attempts']);
        self::assertSame('2026-07-16 10:15:00', $second['next_retry_at']);

        $third = $this->policy->nextRetryState($second, 3, 5, $this->time('2026-07-16 10:15:00'), 'failed', 'still failed');
        self::assertSame(3, $third['attempts']);
        self::assertNull($third['next_retry_at']);
        self::assertTrue($third['retry_exhausted']);
        self::assertFalse($this->policy->retryDue($third, 3, $this->time('2026-07-16 11:30:00')));
    }

    public function testDegradedProfileSourceRemainsRetryableWithoutDuplicatingPlatform(): void
    {
        $sources = [
            ['id' => 25, 'platform' => 'ctrip', 'status' => 'failed', 'last_sync_time' => '2026-07-17 17:38:06'],
            ['id' => 68, 'platform' => 'meituan', 'status' => 'waiting_config', 'last_sync_time' => '2026-07-17 17:38:11'],
            ['id' => 101, 'platform' => 'meituan', 'status' => 'waiting_config', 'last_sync_time' => '2026-07-15 09:28:44'],
        ];

        $retryable = $this->policy->retryableProfileSources($sources);

        self::assertSame([25, 68], array_column($retryable, 'id'));
    }

    public function testUsableProfileSourcesTakePriorityOverDegradedDuplicates(): void
    {
        $sources = [
            ['id' => 68, 'platform' => 'meituan', 'status' => 'partial_success', 'last_sync_time' => '2026-07-15 19:47:14'],
            ['id' => 101, 'platform' => 'meituan', 'status' => 'waiting_config', 'last_sync_time' => '2026-07-17 09:28:44'],
        ];

        $retryable = $this->policy->retryableProfileSources($sources);

        self::assertSame([68], array_column($retryable, 'id'));
    }

    public function testExplicitSourceScopePreservesEverySelectedDegradedSourceInStableOrder(): void
    {
        $sources = [
            ['id' => 101, 'platform' => 'meituan', 'status' => 'waiting_config', 'last_sync_time' => '2026-07-15 09:28:44'],
            ['id' => 68, 'platform' => 'meituan', 'status' => 'waiting_config', 'last_sync_time' => '2026-07-17 17:38:11'],
            ['id' => 25, 'platform' => 'ctrip', 'status' => 'failed', 'last_sync_time' => '2026-07-17 17:38:06'],
        ];

        self::assertSame([68, 101], array_column(
            $this->policy->profileSourcesForRun($sources, [101, 68, 101]),
            'id'
        ));
        $unscopedIds = array_column($this->policy->profileSourcesForRun($sources), 'id');
        sort($unscopedIds, SORT_NUMERIC);
        self::assertSame([25, 68], $unscopedIds);
    }

    public function testCliAndHttpDispatchersSharePolicyAndOnlyMarkCompleteRunsExecuted(): void
    {
        $command = (string)file_get_contents(dirname(__DIR__) . '/app/command/AutoFetchOnlineData.php');
        $controller = (string)file_get_contents(dirname(__DIR__) . '/app/controller/concern/AutoFetchConcern.php');

        self::assertStringContainsString('ScheduledAutoFetchPolicy', $command);
        self::assertStringContainsString('ScheduledAutoFetchPolicy', $controller);
        self::assertSame(1, substr_count($command, "Cache::set(\$run['executed_key']"));
        self::assertSame(1, substr_count($controller, "cache(\$run['executed_key'], true"));
        self::assertStringContainsString("if (\$outcome['complete'] && !empty(\$receipt['collection_complete']))", $command);
        self::assertStringContainsString("if (\$outcome['complete'])", $controller);
        self::assertStringContainsString('return $hasIncompleteDueRun ? 1 : 0;', $command);
        self::assertStringContainsString('$responseCode = $hasIncompleteDueRun ? 503 : 200;', $controller);
        self::assertStringNotContainsString('$ranLockedTask', $command);
        self::assertStringNotContainsString('$ranLockedTask', $controller);
        self::assertStringContainsString("'status' => \$outcome['status']", $command);
        self::assertStringContainsString("'status' => \$outcome['status']", $controller);
        self::assertStringContainsString("\$run['target_platforms'] ?? []", $command);
        self::assertStringContainsString("'target_platforms' => \$schedulePolicy->normalizePlatforms(\$run['target_platforms'] ?? [])", $controller);
        self::assertStringContainsString("in_array('ctrip', \$targetPlatforms, true)", $controller);
        self::assertStringContainsString("in_array('meituan', \$targetPlatforms, true)", $controller);
        self::assertStringContainsString("\$coreReadbackVerified = \$this->runReadbackCoreVerified(\$runReadback)", $command);
        self::assertStringContainsString("'platform_results' => \$platformResults", $command);
        self::assertStringContainsString("!isset(\$failedPlatforms[\$platform])", $command);
        self::assertStringContainsString("return \$savedCount > 0 ? 'partial_success' : 'failed';", $controller);
    }

    /** @return array<string, mixed> */
    private function verifiedPlatformResult(string $platform, int $sourceId, int $syncTaskId, bool $success): array
    {
        return [
            'platform' => $platform,
            'success' => $success,
            'saved_count' => 1,
            'run_readback' => [
                'readback_verified' => true,
                'data_source_id' => $sourceId,
                'sync_task_id' => $syncTaskId,
                'system_hotel_id' => 58,
                'target_date' => '2026-07-16',
                'platform' => $platform,
                'row_ids' => [$syncTaskId + 1000],
            ],
        ];
    }

    /** @param array<string, mixed> $outcome @param array<string, mixed> $result */
    private function buildMachineReceipt(array $outcome, array $result): array
    {
        $reflection = new \ReflectionClass(AutoFetchOnlineData::class);
        $command = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('buildMachineReceipt');
        $method->setAccessible(true);

        /** @var array<string, mixed> $receipt */
        $receipt = $method->invoke($command, 58, '2026-07-16', [25, 68], $outcome, $result);
        return $receipt;
    }

    private function time(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('Asia/Shanghai'));
    }
}
