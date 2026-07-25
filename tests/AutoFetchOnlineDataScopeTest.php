<?php
declare(strict_types=1);

namespace Tests;

use app\command\AutoFetchOnlineData;
use PHPUnit\Framework\TestCase;
use think\console\Input;
use think\console\Output;

final class AutoFetchOnlineDataScopeTest extends TestCase
{
    public function testExplicitInvalidHotelIdFailsBeforeDatabaseOrCollectionWork(): void
    {
        foreach (['abc', '0', '-1', '1.5', ''] as $invalidHotelId) {
            $command = new AutoFetchOnlineData();
            $input = new Input(['--hotel-id=' . $invalidHotelId]);
            $input->setInteractive(false);
            $output = new Output('buffer');

            $exitCode = $command->run($input, $output);

            self::assertSame(1, $exitCode, 'hotel-id=' . $invalidHotelId);
            self::assertStringContainsString(
                'hotel-id must be a positive integer.',
                $output->fetch(),
                'hotel-id=' . $invalidHotelId
            );
        }
    }

    public function testPositiveHotelIdIsAppliedToTheHotelQueryWithoutAFullScanFallback(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/command/AutoFetchOnlineData.php');

        self::assertStringContainsString("->addOption('hotel-id'", $source);
        self::assertStringContainsString("\$hotelsQuery->where('id', \$hotelIdFilter)", $source);
        self::assertStringContainsString('hotel-id was not found or is disabled.', $source);
        self::assertStringNotContainsString("\$hotelIdFilter ?? 0", $source);
    }

    public function testExplicitTargetDateRequiresHotelScopeAndRejectsInvalidDatesBeforeDatabaseWork(): void
    {
        $cases = [
            [['--target-date=2026-07-21'], 'target-date requires an explicit hotel-id scope.'],
            [['--hotel-id=80', '--target-date=not-a-date'], 'target-date must be a valid date within the previous 7 days.'],
            [['--hotel-id=80', '--target-date=2026-02-30'], 'target-date must be a valid date within the previous 7 days.'],
        ];

        foreach ($cases as [$arguments, $expectedMessage]) {
            $command = new AutoFetchOnlineData();
            $input = new Input($arguments);
            $input->setInteractive(false);
            $output = new Output('buffer');

            self::assertSame(1, $command->run($input, $output));
            self::assertStringContainsString($expectedMessage, $output->fetch());
        }
    }

    public function testExplicitHistoricalRunUsesTheNormalIdempotencyAndRetryKeys(): void
    {
        $command = new AutoFetchOnlineData();
        $method = new \ReflectionMethod($command, 'explicitHistoricalRun');
        $run = $method->invoke($command, 80, '2026-07-21');

        self::assertSame('historical:2026-07-21', $run['slot_id']);
        self::assertSame('historical_daily', $run['period']);
        self::assertSame('online_data_historical_executed_80_2026-07-21', $run['executed_key']);
        self::assertSame('online_data_historical_retry_80_2026-07-21', $run['retry_key']);

        $scoped = $method->invoke($command, 80, '2026-07-21', [68, 25, 68]);
        self::assertSame('online_data_historical_executed_80_2026-07-21_sources_25-68', $scoped['executed_key']);
        self::assertSame('online_data_historical_retry_80_2026-07-21_sources_25-68', $scoped['retry_key']);
        self::assertNotSame($run['executed_key'], $scoped['executed_key']);
    }

    public function testExplicitRealtimeRunUsesOneIndependentCurrentHourSlot(): void
    {
        $command = new AutoFetchOnlineData();
        $method = new \ReflectionMethod($command, 'explicitRealtimeRun');
        $run = $method->invoke($command, 80, new \DateTimeImmutable('2026-07-25 14:23:00', new \DateTimeZone('Asia/Shanghai')));

        self::assertSame('realtime:2026-07-25:14', $run['slot_id']);
        self::assertSame('realtime_snapshot', $run['period']);
        self::assertSame('2026-07-25', $run['data_date']);
        self::assertSame('online_data_realtime_executed_80_2026-07-25_14', $run['executed_key']);
        self::assertSame('online_data_realtime_retry_80_2026-07-25_14', $run['retry_key']);
    }

    public function testDailyAndRealtimeOnlyModesAreMutuallyExclusiveAndRealtimeRejectsHistoricalDate(): void
    {
        $command = new AutoFetchOnlineData();
        $input = new Input(['--daily-only', '--realtime-only']);
        $input->setInteractive(false);
        $output = new Output('buffer');
        self::assertSame(1, $command->run($input, $output));
        self::assertStringContainsString('daily-only and realtime-only cannot be used together.', $output->fetch());

        $input = new Input(['--hotel-id=80', '--target-date=2026-07-24', '--realtime-only']);
        $input->setInteractive(false);
        $output = new Output('buffer');
        self::assertSame(1, $command->run($input, $output));
        self::assertStringContainsString('realtime-only cannot be combined with target-date.', $output->fetch());
    }

    public function testExplicitSourceScopeRequiresHotelAndRejectsInvalidIdsBeforeDatabaseWork(): void
    {
        $cases = [
            [['--source-ids=25,68'], 'source-ids requires an explicit hotel-id scope.'],
            [['--hotel-id=80', '--source-ids=25,bad'], 'source-ids must contain positive integer ids.'],
            [['--hotel-id=80', '--source-ids='], 'source-ids must contain positive integer ids.'],
        ];

        foreach ($cases as [$arguments, $expectedMessage]) {
            $command = new AutoFetchOnlineData();
            $input = new Input($arguments);
            $input->setInteractive(false);
            $output = new Output('buffer');

            self::assertSame(1, $command->run($input, $output));
            self::assertStringContainsString($expectedMessage, $output->fetch());
        }
    }

    public function testSourceScopeIsAppliedInsideTheHotelAndProfileQuery(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/command/AutoFetchOnlineData.php');

        self::assertStringContainsString("\$sourceQuery->whereIn('id', \$sourceIds)", $source);
        self::assertStringContainsString('scheduled_profile_source_scope_missing:', $source);
        self::assertStringContainsString('profileSourcesForRun($sources, $sourceIds)', $source);
        self::assertStringContainsString('SUXIOS_AUTO_FETCH_RECEIPT=', $source);
    }

    public function testHistoricalProfileRunUsesOrderedStoredGapPlanWithoutLocalAgentRegistration(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/command/AutoFetchOnlineData.php');

        self::assertStringContainsString('OtaOrderedCollectionPlanner::requestPlanFromStoredRows', $source);
        self::assertStringContainsString("'ordered_collection' => \$orderedPlan", $source);
        self::assertStringContainsString("\$plan['execution_mode'] = 'single_section_bounded'", $source);
        self::assertStringContainsString("'capture_sections' => implode(',', (array)(\$orderedPlan['sections'] ?? []))", $source);
        self::assertStringContainsString("\$dataPeriod === 'historical_daily' && \$platform === 'ctrip'", $source);
        self::assertStringContainsString('? 1', $source);
        self::assertStringContainsString('target_date_core_already_verified_no_capture', $source);
        self::assertStringContainsString("'reused_verified_count' => \$reusedVerifiedCount", $source);
        self::assertStringNotContainsString('ota_local_collector_accounts', $source);
    }

    public function testUnscopedScheduleDeduplicatesLegacyDataTypesWithinOneProfileAccount(): void
    {
        $command = new AutoFetchOnlineData();
        $method = new \ReflectionMethod($command, 'oneSourcePerBrowserProfileAccount');
        $sources = [
            [
                'id' => 68,
                'platform' => 'meituan',
                'data_type' => 'business',
                'status' => 'partial_success',
                'last_sync_time' => '2026-07-24 08:31:00',
                'config_json' => json_encode(['store_id' => 'store-80'], JSON_THROW_ON_ERROR),
            ],
            [
                'id' => 101,
                'platform' => 'meituan',
                'data_type' => 'traffic',
                'status' => 'partial_success',
                'last_sync_time' => '2026-07-24 08:32:00',
                'config_json' => json_encode([
                    'poi_id' => 'store-80',
                    'source_projection_ids' => [68],
                ], JSON_THROW_ON_ERROR),
            ],
            [
                'id' => 25,
                'platform' => 'ctrip',
                'data_type' => 'traffic',
                'status' => 'failed',
                'last_sync_time' => '2026-07-24 08:30:00',
                'config_json' => json_encode(['profile_id' => 'ctrip-80'], JSON_THROW_ON_ERROR),
            ],
        ];

        $selected = $method->invoke($command, $sources);

        self::assertSame([68, 25], array_column($selected, 'id'));
    }

    public function testStaleProfileLockDoesNotBlockBoundedRetry(): void
    {
        $command = new AutoFetchOnlineData();
        $method = new \ReflectionMethod($command, 'profileLockIsStale');

        self::assertFalse($method->invoke($command, [
            'started_at' => date('Y-m-d H:i:s', time() - 299),
        ]));
        self::assertTrue($method->invoke($command, [
            'started_at' => date('Y-m-d H:i:s', time() - 301),
        ]));
        self::assertTrue($method->invoke($command, []));
    }

    public function testForceRerunIsRestrictedToOneExplicitHotelDateAndSourceScope(): void
    {
        $command = new AutoFetchOnlineData();
        $input = new Input(['--force-rerun']);
        $input->setInteractive(false);
        $output = new Output('buffer');

        self::assertSame(1, $command->run($input, $output));
        self::assertStringContainsString(
            'force-rerun requires explicit hotel-id, target-date, and source-ids.',
            $output->fetch()
        );

        $source = (string)file_get_contents(dirname(__DIR__) . '/app/command/AutoFetchOnlineData.php');
        self::assertStringContainsString("->addOption('force-rerun'", $source);
        self::assertStringContainsString('if ($executedReceipt && !$forceRerun)', $source);
        self::assertStringContainsString('$retryState = $forceRerun ? [] : Cache::get', $source);
        self::assertStringContainsString('if (!$forceRerun && !$this->isScheduleRetryDue', $source);
    }

    public function testMachineReceiptRequiresEveryExplicitSourceTaskIdentity(): void
    {
        $command = new AutoFetchOnlineData();
        $method = new \ReflectionMethod($command, 'buildMachineReceipt');
        $sourceResult = static fn(int $sourceId, int $taskId, string $platform, bool $success = true): array => [
            'success' => $success,
            'data_source_id' => $sourceId,
            'platform' => $platform,
            'run_readback' => [
                'readback_verified' => true,
                'p0_status' => 'ready',
                'data_source_id' => $sourceId,
                'sync_task_id' => $taskId,
                'system_hotel_id' => 80,
                'target_date' => '2026-07-22',
                'platform' => $platform,
                'row_ids' => [$sourceId + 1000],
            ],
        ];

        $complete = $method->invoke($command, 80, '2026-07-22', [68, 25], ['complete' => true, 'status' => 'success'], [
            'platform_results' => [$sourceResult(68, 902, 'meituan'), $sourceResult(25, 901, 'ctrip')],
        ]);
        self::assertTrue($complete['collection_complete']);
        self::assertTrue($complete['exportable_snapshot_complete']);
        self::assertSame([25, 68], $complete['source_ids']);
        self::assertSame([25, 68], array_column($complete['source_tasks'], 'data_source_id'));
        self::assertSame([901, 902], array_column($complete['source_tasks'], 'sync_task_id'));

        $incomplete = $method->invoke($command, 80, '2026-07-22', [25, 68], ['complete' => true, 'status' => 'success'], [
            'platform_results' => [$sourceResult(25, 901, 'ctrip')],
        ]);
        self::assertFalse($incomplete['collection_complete']);
        self::assertFalse($incomplete['exportable_snapshot_complete']);

        $partial = $method->invoke($command, 80, '2026-07-22', [25, 68], ['complete' => false, 'status' => 'partial_success'], [
            'platform_results' => [
                $sourceResult(25, 901, 'ctrip'),
                $sourceResult(68, 902, 'meituan', false),
            ],
        ]);
        self::assertFalse($partial['collection_complete']);
        self::assertTrue($partial['exportable_snapshot_complete']);
        self::assertSame(['success', 'partial'], array_column($partial['source_tasks'], 'collection_status'));
    }
}
