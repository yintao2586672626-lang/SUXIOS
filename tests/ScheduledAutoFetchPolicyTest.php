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
            'historical_schedule_time' => '08:30',
            'realtime_enabled' => true,
            'realtime_schedule_minute' => 5,
            'realtime_schedule_interval_hours' => 2,
        ];

        self::assertSame([], $this->policy->dueRuns(58, $status, $this->time('2026-07-16 07:59:00')));

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
        self::assertSame(['ctrip', 'meituan'], $withRetry[0]['target_platforms'] ?? []);

        $status['failed_records'][0]['failed_platforms'] = ['meituan'];
        $targetedRetry = $this->policy->dueRuns(58, $status, $this->time('2026-07-16 10:37:00'));
        $targetedRealtime = array_values(array_filter(
            $targetedRetry,
            static fn(array $run): bool => $run['slot_id'] === 'realtime:2026-07-16:10'
        ));
        self::assertCount(1, $targetedRealtime);
        self::assertSame([], $targetedRealtime[0]['target_platforms'] ?? []);
    }

    public function testYesterdayDefaultWindowStartsAt0830AndCutsOffAt0900(): void
    {
        $status = [
            'historical_enabled' => true,
            'realtime_enabled' => false,
        ];

        self::assertSame([], $this->policy->dueRuns(
            58,
            $status,
            $this->time('2026-07-24 08:29:59')
        ));
        $runs = $this->policy->dueRuns(58, $status, $this->time('2026-07-24 08:30:00'));
        self::assertCount(1, $runs);
        self::assertSame('historical:2026-07-23', $runs[0]['slot_id']);

        $legacyLateDefault = $status;
        $legacyLateDefault['historical_schedule_time'] = '10:00';
        self::assertCount(1, $this->policy->dueRuns(
            58,
            $legacyLateDefault,
            $this->time('2026-07-24 08:30:00')
        ));
    }

    public function testOutcomeRequiresRowsAndNoFailedConfiguredPlatform(): void
    {
        $skippedPlatform = $this->policy->classifyOutcome([
            'success' => true,
            'saved_count' => 3,
            'platform_results' => [
                ['platform' => 'ctrip', 'success' => true, 'saved_count' => 3],
                ['platform' => 'meituan', 'success' => false, 'skipped' => true, 'saved_count' => 0],
            ],
        ]);
        self::assertFalse($skippedPlatform['complete']);
        self::assertSame('partial_success', $skippedPlatform['status']);
        self::assertSame(['meituan'], $skippedPlatform['failed_platforms']);

        $complete = $this->policy->classifyOutcome([
            'success' => true,
            'saved_count' => 6,
            'platform_results' => [
                ['platform' => 'ctrip', 'success' => true, 'saved_count' => 3],
                ['platform' => 'meituan', 'success' => true, 'saved_count' => 3],
            ],
        ]);
        self::assertTrue($complete['complete']);
        self::assertSame('success', $complete['status']);
        self::assertSame(['ctrip', 'meituan'], $complete['required_platforms']);

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

        $inProgress = $this->policy->classifyOutcome([
            'success' => false,
            'saved_count' => 0,
            'platform_results' => [
                [
                    'platform' => 'ctrip',
                    'status' => 'in_progress',
                    'reused_active_task' => true,
                    'success' => false,
                    'saved_count' => 0,
                ],
                [
                    'platform' => 'meituan',
                    'status' => 'in_progress',
                    'reused_active_task' => true,
                    'success' => false,
                    'saved_count' => 0,
                ],
            ],
        ]);
        self::assertFalse($inProgress['complete']);
        self::assertSame('in_progress', $inProgress['status']);
        self::assertSame([], $inProgress['failed_platforms']);
        self::assertSame(['ctrip', 'meituan'], $inProgress['in_progress_platforms']);

        $verifiedReuse = $this->policy->classifyOutcome([
            'success' => true,
            'saved_count' => 0,
            'reused_verified_count' => 6,
            'platform_results' => [
                ['platform' => 'ctrip', 'success' => true, 'saved_count' => 0, 'reused_verified_count' => 3],
                ['platform' => 'meituan', 'success' => true, 'saved_count' => 0, 'reused_verified_count' => 3],
            ],
        ]);
        self::assertTrue($verifiedReuse['complete']);
        self::assertSame(0, $verifiedReuse['saved_count']);
        self::assertSame(6, $verifiedReuse['reused_verified_count']);
        self::assertSame(['ctrip', 'meituan'], $verifiedReuse['successful_platforms']);

        $failedPlatformRetry = $this->policy->classifyOutcome([
            'success' => true,
            'saved_count' => 2,
            'platform_results' => [
                ['platform' => 'meituan', 'success' => true, 'saved_count' => 2],
            ],
        ]);
        self::assertFalse($failedPlatformRetry['complete']);
        self::assertSame('partial_success', $failedPlatformRetry['status']);
        self::assertSame(['meituan'], $failedPlatformRetry['successful_platforms']);
        self::assertSame(['ctrip'], $failedPlatformRetry['failed_platforms']);
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
        self::assertFalse($completeReceipt['dual_ota_p0_complete']);
        self::assertFalse($this->machineReceiptDailyTrustReady($completeReceipt));

        $completeReceipt = $this->policy->attachAuthorityVerifier(
            $completeReceipt,
            $this->mockAuthorityVerifier($completeReceipt)
        );
        self::assertTrue($completeReceipt['dual_ota_p0_complete']);
        self::assertTrue($this->machineReceiptDailyTrustReady($completeReceipt));
        self::assertTrue($this->policy->dailyTrustReceiptReady($completeReceipt, '2026-07-16', 58));
        self::assertFalse($this->policy->dailyTrustReceiptReady($completeReceipt, '2026-07-15', 58));
        self::assertFalse($this->policy->dailyTrustReceiptReady($completeReceipt, '2026-07-16', 59));

        $legacyReceipt = $completeReceipt;
        unset($legacyReceipt['source_tasks'][1]['p0_status']);
        self::assertFalse($this->machineReceiptDailyTrustReady($legacyReceipt));

        $targetedGapResult = $completeResult;
        $targetedGapResult['platform_results'][1]['run_readback']['p0_status'] = 'not_required';
        $targetedGapOutcome = $this->policy->classifyOutcome($targetedGapResult);
        $targetedGapReceipt = $this->buildMachineReceipt($targetedGapOutcome, $targetedGapResult);
        self::assertTrue($targetedGapReceipt['collection_complete']);
        self::assertSame('not_required', $targetedGapReceipt['source_tasks'][1]['p0_status']);
        self::assertFalse($this->machineReceiptDailyTrustReady($targetedGapReceipt));
        $targetedGapReceipt = $this->policy->attachAuthorityVerifier(
            $targetedGapReceipt,
            $this->mockAuthorityVerifier($targetedGapReceipt)
        );
        self::assertTrue($this->machineReceiptDailyTrustReady($targetedGapReceipt));

        $unverifiedResult = $completeResult;
        $unverifiedResult['platform_results'][1]['run_readback']['readback_verified'] = false;
        $unverifiedOutcome = $this->policy->classifyOutcome($unverifiedResult);
        $unverifiedReceipt = $this->buildMachineReceipt($unverifiedOutcome, $unverifiedResult);

        self::assertTrue($unverifiedOutcome['complete']);
        self::assertFalse($unverifiedReceipt['exportable_snapshot_complete']);
        self::assertFalse($unverifiedReceipt['collection_complete']);
    }

    public function testAuthorityVerifierMustMatchHotelDatePlatformsAndPersistedTrust(): void
    {
        $result = [
            'success' => true,
            'saved_count' => 2,
            'platform_results' => [
                $this->verifiedPlatformResult('ctrip', 25, 1001, true),
                $this->verifiedPlatformResult('meituan', 68, 1002, true),
            ],
        ];
        $receipt = $this->buildMachineReceipt($this->policy->classifyOutcome($result), $result);

        $wrongHotel = $this->mockAuthorityVerifier($receipt);
        $wrongHotel['hotel_id'] = 59;
        $wrongHotelReceipt = $this->policy->attachAuthorityVerifier($receipt, $wrongHotel);
        self::assertFalse($this->policy->dailyTrustReceiptReady($wrongHotelReceipt, '2026-07-16', 58));

        $missingRaw = $this->mockAuthorityVerifier($receipt);
        $missingRaw['authority_ready'] = false;
        $missingRaw['status'] = 'incomplete';
        $missingRaw['continuous_trust_status'] = 'partial';
        $missingRaw['continuous_trust_missing_steps'] = ['meituan_raw_save_not_ready'];
        $missingRawReceipt = $this->policy->attachAuthorityVerifier($receipt, $missingRaw);
        self::assertFalse($this->policy->dailyTrustReceiptReady($missingRawReceipt, '2026-07-16', 58));
        self::assertContains(
            'meituan_raw_save_not_ready',
            $missingRawReceipt['authority_verifier']['continuous_trust_missing_steps']
        );

        $ready = $this->policy->attachAuthorityVerifier(
            $receipt,
            $this->mockAuthorityVerifier($receipt)
        );
        self::assertTrue($this->policy->dailyTrustReceiptReady($ready, '2026-07-16', 58));
        self::assertSame('external_p0_verifier', $ready['authority_verifier']['verification_source']);
    }

    public function testAuthorityCanSettleStaleTaskP0OnlyWhenBothAnchoredRowsArePresent(): void
    {
        $result = [
            'success' => true,
            'saved_count' => 2,
            'platform_results' => [
                $this->verifiedPlatformResult('ctrip', 25, 1001, false),
                $this->verifiedPlatformResult('meituan', 68, 1002, false),
            ],
        ];
        $result['platform_results'][0]['run_readback']['p0_status'] = 'blocked';
        $result['platform_results'][1]['run_readback']['p0_status'] = 'partial';

        $receipt = $this->buildMachineReceipt($this->policy->classifyOutcome($result), $result);
        self::assertTrue($receipt['exportable_snapshot_complete']);
        self::assertFalse($receipt['collection_complete']);
        self::assertSame(['blocked', 'partial'], array_column($receipt['source_tasks'], 'p0_status'));

        $ready = $this->policy->attachAuthorityVerifier($receipt, $this->mockAuthorityVerifier($receipt));
        self::assertTrue($ready['collection_complete']);
        self::assertTrue($ready['dual_ota_p0_complete']);
        self::assertTrue($this->policy->dailyTrustReceiptReady($ready, '2026-07-16', 58));
        self::assertSame(['ready', 'ready'], array_column($ready['source_tasks'], 'p0_status'));

        array_pop($receipt['source_tasks']);
        $incompleteAnchor = $this->policy->attachAuthorityVerifier(
            $receipt,
            $this->mockAuthorityVerifier($receipt)
        );
        self::assertFalse($incompleteAnchor['collection_complete']);
        self::assertFalse($this->policy->dailyTrustReceiptReady($incompleteAnchor, '2026-07-16', 58));
    }

    public function testRealtimeTrustReceiptAcceptsTheExplicitSinglePlatformScope(): void
    {
        $result = [
            'success' => true,
            'saved_count' => 1,
            'required_platforms' => ['ctrip'],
            'platform_results' => [
                $this->verifiedPlatformResult('ctrip', 25, 1001, true),
            ],
        ];
        $outcome = $this->policy->classifyOutcome($result);
        $receipt = $this->policy->buildDailyTrustReceipt(
            58,
            '2026-07-16',
            [25],
            $outcome,
            $result,
            'realtime_snapshot'
        );

        self::assertTrue($outcome['complete']);
        self::assertSame(['ctrip'], $receipt['required_platforms']);
        self::assertFalse($receipt['authority_verifier_required']);
        self::assertTrue($this->policy->dailyTrustReceiptReady($receipt, '2026-07-16', 58));

        $receipt['source_tasks'][0]['p0_status'] = 'blocked';
        self::assertFalse($this->policy->dailyTrustReceiptReady($receipt, '2026-07-16', 58));
    }

    public function testNineOClockGapReportListsRecollectionScopeAndBlocksFormalReport(): void
    {
        $partialResult = [
            'success' => true,
            'saved_count' => 1,
            'platform_results' => [
                $this->verifiedPlatformResult('ctrip', 25, 1001, true),
                $this->verifiedPlatformResult('meituan', 68, 1002, false),
            ],
        ];
        $receipt = $this->buildMachineReceipt(
            $this->policy->classifyOutcome($partialResult),
            $partialResult
        );
        $verifier = $this->mockAuthorityVerifier($receipt);
        $verifier['authority_ready'] = false;
        $verifier['status'] = 'incomplete';
        $verifier['exit_code'] = 2;
        $verifier['verified_platforms'] = ['ctrip'];
        $verifier['platform_statuses']['meituan'] = 'binding_conflict';
        $verifier['p0_platforms_ready'] = 1;
        $verifier['traffic_gates_ready'] = 1;
        $verifier['continuous_trust_status'] = 'partial';
        $verifier['continuous_trust_missing_steps'] = ['meituan_account_binding_not_ready'];
        $verifier['issue_codes'] = ['meituan_profile_binding_conflict'];
        $receipt = $this->policy->attachAuthorityVerifier($receipt, $verifier);

        $beforeCutoff = $this->policy->buildYesterdayGapReport(
            $receipt,
            ['attempts' => 2, 'max_attempts' => 3, 'retry_exhausted' => false],
            $this->time('2026-07-16 08:59:59')
        );
        self::assertSame('awaiting_completeness', $beforeCutoff['status']);
        self::assertFalse($beforeCutoff['formal_report_allowed']);

        $gap = $this->policy->buildYesterdayGapReport(
            $receipt,
            ['attempts' => 3, 'max_attempts' => 3, 'retry_exhausted' => true],
            $this->time('2026-07-16 09:00:00')
        );
        self::assertSame('gap', $gap['status']);
        self::assertSame('explicit_gap_report', $gap['report_kind']);
        self::assertSame(['meituan'], $gap['missing_platforms']);
        self::assertSame(['meituan'], $gap['recollection_platforms']);
        self::assertContains('meituan_profile_binding_conflict', $gap['gap_codes']);
        self::assertContains('meituan_account_binding_not_ready', $gap['gap_codes']);
        self::assertTrue($gap['retry']['retry_exhausted']);
        self::assertFalse($gap['sensitive_values_exposed']);
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
        $policy = (string)file_get_contents(dirname(__DIR__) . '/app/service/ScheduledAutoFetchPolicy.php');

        self::assertStringContainsString('ScheduledAutoFetchPolicy', $command);
        self::assertStringContainsString('ScheduledAutoFetchPolicy', $controller);
        self::assertSame(1, substr_count($command, "Cache::set(\$run['executed_key']"));
        self::assertSame(1, substr_count($controller, "cache(\$run['executed_key'], \$executionReceipt"));
        self::assertStringContainsString('P0OtaFieldLoopVerifierRunner', $command);
        self::assertStringNotContainsString('new P0OtaFieldLoopVerifierRunner', $controller);
        self::assertStringContainsString('online_data_p0_authority_receipt_', $controller);
        self::assertStringContainsString('online_data_p0_authority_receipt_', $command);
        self::assertStringContainsString('autoFetchExecutedReceiptReady', $controller);
        self::assertStringContainsString('buildDailyTrustReceipt', $controller);
        self::assertStringContainsString('dailyTrustReceiptReady', $controller);
        self::assertStringContainsString('attachAuthorityVerifier', $policy);
        self::assertStringContainsString('buildYesterdayGapReport', $policy);
        self::assertStringContainsString(
            "'dual_ota_p0_complete' => \$collectionComplete && !\$authorityRequired",
            $policy
        );
        self::assertStringContainsString("\\think\\facade\\Cache::delete(\$run['executed_key'])", $controller);
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
                'p0_status' => 'ready',
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

    /** @param array<string, mixed> $receipt */
    private function machineReceiptDailyTrustReady(array $receipt): bool
    {
        $reflection = new \ReflectionClass(AutoFetchOnlineData::class);
        $command = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('machineReceiptDailyTrustReady');
        $method->setAccessible(true);
        return (bool)$method->invoke($command, $receipt);
    }

    /** @return array<string, mixed> */
    private function mockAuthorityVerifier(array $receipt): array
    {
        return [
            'verification_source' => 'external_p0_verifier',
            'status' => 'passed',
            'exit_code' => 0,
            'authority_ready' => true,
            'target_date' => '2026-07-16',
            'hotel_id' => 58,
            'required_platforms' => ['ctrip', 'meituan'],
            'verified_platforms' => ['ctrip', 'meituan'],
            'collection_anchor_hash' => (string)($receipt['collection_anchor_hash'] ?? ''),
            'platform_statuses' => ['ctrip' => 'ready', 'meituan' => 'ready'],
            'p0_platforms_ready' => 2,
            'traffic_gates_ready' => 2,
            'continuous_trust_status' => 'verified',
            'continuous_trust_missing_steps' => [],
            'issue_codes' => [],
            'verifier_report_hash' => str_repeat('a', 64),
            'checked_at' => '2026-07-16 08:45:00',
            'sensitive_values_exposed' => false,
        ];
    }

    private function time(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('Asia/Shanghai'));
    }
}
