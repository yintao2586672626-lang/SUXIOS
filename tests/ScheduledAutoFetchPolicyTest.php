<?php
declare(strict_types=1);

namespace tests;

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

        $empty = $this->policy->classifyOutcome(['success' => true, 'saved_count' => 0]);
        self::assertFalse($empty['complete']);
        self::assertSame('failed', $empty['status']);
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

    public function testCliAndHttpDispatchersSharePolicyAndOnlyMarkCompleteRunsExecuted(): void
    {
        $command = (string)file_get_contents(dirname(__DIR__) . '/app/command/AutoFetchOnlineData.php');
        $controller = (string)file_get_contents(dirname(__DIR__) . '/app/controller/concern/AutoFetchConcern.php');

        self::assertStringContainsString('ScheduledAutoFetchPolicy', $command);
        self::assertStringContainsString('ScheduledAutoFetchPolicy', $controller);
        self::assertSame(1, substr_count($command, "Cache::set(\$run['executed_key']"));
        self::assertSame(1, substr_count($controller, "cache(\$run['executed_key'], true"));
        self::assertStringContainsString("if (\$outcome['complete'])", $command);
        self::assertStringContainsString("if (\$outcome['complete'])", $controller);
        self::assertStringContainsString('return $hasIncompleteDueRun ? 1 : 0;', $command);
        self::assertStringContainsString('$responseCode = $hasIncompleteDueRun ? 503 : 200;', $controller);
        self::assertStringNotContainsString('$ranLockedTask', $command);
        self::assertStringNotContainsString('$ranLockedTask', $controller);
        self::assertStringContainsString("'status' => \$outcome['status']", $command);
        self::assertStringContainsString("'status' => \$outcome['status']", $controller);
    }

    private function time(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('Asia/Shanghai'));
    }
}
