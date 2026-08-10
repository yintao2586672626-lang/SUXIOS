<?php
declare(strict_types=1);

namespace Tests;

use app\command\AutoFetchOnlineData;
use PHPUnit\Framework\TestCase;
use Tests\Support\SourceAggregate;
use think\console\Input;
use think\console\Output;

final class AutoFetchOnlineDataScopeTest extends TestCase
{
    public function testAuthenticatedManualAutoFetchKeepsTheExplicitBusinessDateFailClosed(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__) . '/app/controller/concern/AutoFetchConcern.php'
        );

        self::assertStringContainsString("\$requestData['data_date']", $source);
        self::assertStringContainsString("\$requestData['target_date']", $source);
        self::assertStringContainsString('data_date 与 target_date 不一致，已拒绝静默改写业务日期。', $source);
        self::assertStringContainsString('data_date 必须是有效的 YYYY-MM-DD 业务日期。', $source);
        self::assertStringContainsString('if ($requestedDataDate !== $targetDataDate)', $source);
        self::assertStringContainsString('$targetDataDate = $requestedDataDate;', $source);
    }

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

    public function testExplicitHistoricalAndRealtimeRunsBindCacheKeysToSourceAndPlatformScope(): void
    {
        $command = new AutoFetchOnlineData();
        $historical = new \ReflectionMethod($command, 'explicitHistoricalRun');
        $bind = new \ReflectionMethod($command, 'bindRunCacheScope');
        $run = $historical->invoke($command, 80, '2026-07-21');
        $run['target_platforms'] = ['ctrip'];
        $scoped = $bind->invoke($command, $run, [68, 25, 68]);
        $sameScope = $bind->invoke($command, $run, [25, 68]);
        $otherSource = $bind->invoke($command, $run, [26]);
        $otherPlatformRun = $run;
        $otherPlatformRun['target_platforms'] = ['meituan'];
        $otherPlatform = $bind->invoke($command, $otherPlatformRun, [25, 68]);

        self::assertSame('historical:2026-07-21', $run['slot_id']);
        self::assertSame('historical_daily', $run['period']);
        self::assertSame('online_data_historical_executed_80_2026-07-21', $run['executed_key']);
        self::assertSame('online_data_historical_retry_80_2026-07-21', $run['retry_key']);
        self::assertMatchesRegularExpression('/_scope_[a-f0-9]{16}$/D', $scoped['executed_key']);
        self::assertSame($scoped['executed_key'], $sameScope['executed_key']);
        self::assertSame($scoped['retry_key'], $sameScope['retry_key']);
        self::assertNotSame($scoped['executed_key'], $otherSource['executed_key']);
        self::assertNotSame($scoped['executed_key'], $otherPlatform['executed_key']);
        self::assertSame([25, 68], $scoped['cache_scope_source_ids']);
        self::assertSame(['ctrip'], $scoped['cache_scope_platforms']);
        self::assertNotSame($run['executed_key'], $scoped['executed_key']);

        $realtime = new \ReflectionMethod($command, 'explicitRealtimeRun');
        $run = $realtime->invoke($command, 80, new \DateTimeImmutable('2026-07-25 14:23:00', new \DateTimeZone('Asia/Shanghai')));

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

        $validHistoricalDate = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))
            ->modify('-1 day')
            ->format('Y-m-d');
        $input = new Input(['--hotel-id=80', '--target-date=' . $validHistoricalDate, '--realtime-only']);
        $input->setInteractive(false);
        $output = new Output('buffer');
        self::assertSame(1, $command->run($input, $output));
        self::assertStringContainsString('realtime-only cannot be combined with target-date.', $output->fetch());
    }

    public function testNaturalDispatcherStopsBeforeCollectionWhenHotelPlanGateIsBlocked(): void
    {
        $command = new AutoFetchBlockedPlanGateCommand();
        $targetDate = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))
            ->modify('-1 day')
            ->format('Y-m-d');
        $input = new Input([
            '--daily-only',
            '--hotel-id=80',
            '--target-date=' . $targetDate,
            '--source-ids=25,68',
            '--platforms=ctrip,meituan',
            '--dispatcher-run-id=12345678-1234-4234-8234-123456789abc',
        ]);
        $input->setInteractive(false);
        $output = new Output('buffer');

        self::assertSame(78, $command->run($input, $output));
        $text = $output->fetch();
        self::assertStringContainsString('SUXIOS_COLLECTION_PLAN_GATE=', $text);
        self::assertStringContainsString('hotel_collection_plan_not_active', $text);
        self::assertStringNotContainsString('Start online data auto-fetch schedule check.', $text);
        self::assertSame([
            'hotel_id' => 80,
            'business_date' => $targetDate,
            'source_ids' => [25, 68],
            'platforms' => ['ctrip', 'meituan'],
            'run_mode' => 'daily',
        ], $command->capturedScope);
    }

    public function testDispatcherRunIdRequiresCanonicalDailyExplicitScopeBeforeDatabaseWork(): void
    {
        $cases = [
            [
                ['--dispatcher-run-id=forged'],
                'dispatcher-run-id must be a canonical UUID.',
            ],
            [
                ['--dispatcher-run-id=12345678-1234-4234-8234-123456789abc'],
                'dispatcher-run-id requires daily-only with explicit hotel, target-date, source-ids, and platforms.',
            ],
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

    public function testCloudCollectorFailsClosedBeforeDatabaseOrPlatformWorkWhenExplicitScopeIsMissing(): void
    {
        $previousCollector = getenv('SUXIOS_OTA_CLOUD_COLLECTOR');
        putenv('SUXIOS_OTA_CLOUD_COLLECTOR=1');

        try {
            $command = new AutoFetchOnlineData();
            $input = new Input(['--daily-only']);
            $input->setInteractive(false);
            $output = new Output('buffer');

            self::assertSame(78, $command->run($input, $output));
            self::assertStringContainsString(
                'explicit single_user_local mode, collector-user-id, collector-device-id, hotel-id, source-ids, and platforms are required',
                $output->fetch()
            );
        } finally {
            putenv('SUXIOS_OTA_CLOUD_COLLECTOR' . ($previousCollector === false ? '' : '=' . $previousCollector));
        }
    }

    public function testCloudScopeValidationReceiptIsExplicitlyNonCollectingAndNonSensitive(): void
    {
        $command = new AutoFetchOnlineData();
        $scope = [
            'mode' => 'single_user_local',
            'authorization_mode' => 'cross_tenant_super_admin_explicit_hotel_grant',
            'tenant_id' => 80,
            'user_id' => 1,
            'device_id' => 'server-owner-device',
            'device_id_hash' => hash('sha256', 'server-owner-device'),
            'hotel_id' => 80,
            'source_ids' => [25, 68],
            'platforms' => ['ctrip', 'meituan'],
        ];
        $property = new \ReflectionProperty($command, 'cloudCollectorScope');
        $property->setValue($command, $scope);
        $method = new \ReflectionMethod($command, 'cloudCollectorScopeValidationReceipt');

        $receipt = $method->invoke($command);

        self::assertSame('blocked', $receipt['status']);
        self::assertFalse($receipt['collection_allowed']);
        self::assertSame('采集范围尚未完成预检，已阻止采集。', $receipt['message']);
        self::assertSame('cross_tenant_super_admin_explicit_hotel_grant', $receipt['authorization_mode']);
        self::assertSame('server-owner-device', $receipt['collector_device_id']);
        self::assertSame([25, 68], $receipt['source_ids']);
        self::assertFalse($receipt['current_session_probe_performed']);
        self::assertFalse($receipt['collection_performed']);
        self::assertFalse($receipt['persistence_performed']);
        self::assertFalse($receipt['sensitive_values_exposed']);
        self::assertArrayNotHasKey('device_id_hash', $receipt);
    }

    public function testCloudTenantAuthorizationAllowsOnlySameTenantOrControlledSuperAdminWithExplicitGrant(): void
    {
        $command = new AutoFetchOnlineData();
        $method = new \ReflectionMethod($command, 'collectorTenantAuthorizationMode');

        self::assertSame(
            'same_tenant_explicit_hotel_grant',
            $method->invoke($command, 80, false, 80, true)
        );

        self::assertSame(
            'cross_tenant_super_admin_explicit_hotel_grant',
            $method->invoke($command, 7, true, 80, true)
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no active, unexpired, tenant-bound hotel fetch grant');
        $method->invoke($command, 7, true, 80, false);
    }

    public function testCloudScopeValidationRequiresCloudCollectorModeBeforeDatabaseWork(): void
    {
        $previousCollector = getenv('SUXIOS_OTA_CLOUD_COLLECTOR');
        putenv('SUXIOS_OTA_CLOUD_COLLECTOR=0');

        try {
            $command = new AutoFetchOnlineData();
            $input = new Input(['--validate-cloud-scope']);
            $input->setInteractive(false);
            $output = new Output('buffer');

            self::assertSame(1, $command->run($input, $output));
            self::assertStringContainsString(
                'Cloud scope validation, binding or unbinding requires SUXIOS_OTA_CLOUD_COLLECTOR=1.',
                $output->fetch()
            );
        } finally {
            putenv('SUXIOS_OTA_CLOUD_COLLECTOR' . ($previousCollector === false ? '' : '=' . $previousCollector));
        }
    }

    public function testCloudBindingConfirmationAndRotationCannotRunWithoutTheirParentModes(): void
    {
        $cases = [
            [
                ['--confirm-cloud-scope-binding'],
                'confirm-cloud-scope-binding requires bind-cloud-scope.',
            ],
            [
                ['--rotate-cloud-device-binding'],
                'rotate-cloud-device-binding requires bind-cloud-scope and confirm-cloud-scope-binding.',
            ],
            [
                ['--confirm-cloud-scope-unbind'],
                'confirm-cloud-scope-unbind requires unbind-cloud-scope.',
            ],
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

    public function testCanonicalPlatformHotelAnchorParserRequiresExactSourcePairs(): void
    {
        $command = new AutoFetchOnlineData();
        $method = new \ReflectionMethod($command, 'normalizePlatformHotelAnchors');

        self::assertSame([
            25 => 'ctrip-h80',
            68 => 'meituan-h80',
        ], $method->invoke($command, '68=meituan-h80,25=ctrip-h80'));
        foreach ([
            '',
            '25',
            '25=',
            'bad=hotel',
            '25=hotel,25=other',
            '25=hotel id',
        ] as $invalid) {
            self::assertSame([], $method->invoke($command, $invalid), $invalid);
        }
    }

    public function testMissingExplicitSourceIsReportedWithoutShortCircuitingHealthyPlatforms(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/command/AutoFetchOnlineData.php');

        self::assertStringContainsString("\$sourceQuery->whereIn('id', \$sourceIds)", $source);
        $missingScopeStart = strpos($source, '$missingSourceIds = array_values(array_diff($sourceIds, $foundSourceIds));');
        $platformIsolationStart = strpos($source, '$presentPlatforms = array_values', (int)$missingScopeStart);
        self::assertNotFalse($missingScopeStart);
        self::assertNotFalse($platformIsolationStart);
        self::assertStringNotContainsString(
            "'message' => 'scheduled_profile_source_scope_missing:'",
            substr($source, (int)$missingScopeStart, (int)$platformIsolationStart - (int)$missingScopeStart)
        );
        self::assertStringContainsString("'missing_source_ids' => \$missingSourceIds", $source);
        self::assertStringContainsString("'platform' => 'source_scope'", $source);
        self::assertStringContainsString("'message' => 'scheduled_profile_source_scope_missing'", $source);
        self::assertStringContainsString('profileSourcesForRun($sources, $sourceIds)', $source);
        self::assertStringContainsString('SUXIOS_AUTO_FETCH_RECEIPT=', $source);
    }

    public function testOrderedPlannerAndCaptureShareThePerPlatformFailureBoundary(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/command/AutoFetchOnlineData.php');
        $loop = strpos($source, 'foreach ($sources as $source)');
        $platformTry = strpos($source, 'try {', (int)$loop);
        $planner = strpos($source, '$orderedExecution = $this->orderedBrowserProfileExecution(', (int)$loop);
        $sync = strpos($source, '$result = $this->syncBrowserProfileSource(', (int)$planner);
        $platformCatch = strpos($source, '} catch (\Throwable $e) {', (int)$sync);

        self::assertNotFalse($loop);
        self::assertNotFalse($platformTry);
        self::assertNotFalse($planner);
        self::assertNotFalse($sync);
        self::assertNotFalse($platformCatch);
        self::assertLessThan($planner, $platformTry);
        self::assertLessThan($sync, $planner);
        self::assertLessThan($platformCatch, $sync);
        self::assertStringContainsString("'message' => 'ordered_profile_capture_failed'", $source);
    }

    public function testProfileSourceOperationalReceiptPreservesExactFailureWithoutRelaxingReadbackGate(): void
    {
        $command = new AutoFetchOnlineData();
        $method = new \ReflectionMethod($command, 'profileSourceOperationalReceipt');

        $failed = $method->invoke($command, [
            'task_id' => 3271,
            'status' => 'failed',
            'message' => 'credential_execution_failed',
            'failure_reason' => '',
            'readback_count' => 0,
            'readback_verified' => false,
        ], false, false);

        self::assertSame(3271, $failed['task_id']);
        self::assertSame('failed', $failed['status']);
        self::assertSame('failed', $failed['source_task_status']);
        self::assertSame('credential_execution_failed', $failed['message']);
        self::assertSame('credential_execution_failed', $failed['failure_reason']);
        self::assertSame(0, $failed['readback_count']);
        self::assertFalse($failed['readback_verified']);

        $coreBlocked = $method->invoke($command, [
            'task_id' => 3273,
            'status' => 'success',
            'message' => 'platform_data_synchronized',
            'readback_count' => 3,
            'readback_verified' => true,
        ], false, false);

        self::assertSame('failed', $coreBlocked['status']);
        self::assertSame('success', $coreBlocked['source_task_status']);
        self::assertSame('historical_core_contract_incomplete', $coreBlocked['message']);
        self::assertSame('historical_core_contract_incomplete', $coreBlocked['failure_reason']);
    }

    public function testPostSyncReadbackFailureRemainsInsideTheCurrentPlatform(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/command/AutoFetchOnlineData.php');
        $savedCountInit = strpos($source, '$sourceSavedCount = 0;');
        $postSyncTry = strpos($source, 'try {', (int)$savedCountInit);
        $readback = strpos($source, '$compositeReadbackVerified = $this->orderedCompositeReadbackVerified(', (int)$postSyncTry);
        $readbackCatch = strpos($source, '} catch (\Throwable $e) {', (int)$readback);
        $nextSource = strpos($source, "'message' => 'ordered_profile_readback_failed'", (int)$readbackCatch);

        self::assertNotFalse($savedCountInit);
        self::assertNotFalse($postSyncTry);
        self::assertNotFalse($readback);
        self::assertNotFalse($readbackCatch);
        self::assertNotFalse($nextSource);
        self::assertLessThan($postSyncTry, $savedCountInit);
        self::assertLessThan($readback, $postSyncTry);
        self::assertLessThan($readbackCatch, $readback);
        self::assertLessThan($nextSource, $readbackCatch);
        self::assertStringContainsString("'success' => false", substr($source, (int)$readbackCatch, 1400));
        self::assertStringContainsString("'run_readback' => []", substr($source, (int)$readbackCatch, 1400));
        self::assertStringContainsString('continue;', substr($source, (int)$readbackCatch, 1800));
    }

    public function testCloudSourceScopeRequiresExactUserTenantHotelPlatformDeviceAndMode(): void
    {
        $command = new AutoFetchOnlineData();
        $method = new \ReflectionMethod($command, 'assertCloudCollectorSourceRow');
        $scope = [
            'mode' => 'single_user_local',
            'tenant_id' => 9,
            'user_id' => 17,
            'device_id' => 'cloud-owner-device',
            'device_id_hash' => hash('sha256', 'cloud-owner-device'),
            'hotel_id' => 80,
            'source_ids' => [25],
            'platforms' => ['ctrip'],
        ];
        $source = [
            'id' => 25,
            'tenant_id' => 9,
            'user_id' => 17,
            'system_hotel_id' => 80,
            'platform' => 'ctrip',
            'ingestion_method' => 'browser_profile',
            'enabled' => 1,
            'status' => 'ready',
            'config_json' => json_encode([
                'source_method' => 'single_user_local',
                'collector_binding_mode' => 'single_user_local',
                'collector_device_id' => 'cloud-owner-device',
                'collector_device_id_hash' => hash('sha256', 'cloud-owner-device'),
                'collector_user_id' => 17,
                'collector_tenant_id' => 9,
                'collector_hotel_id' => 80,
                'collector_platform' => 'ctrip',
                'collector_bound_at' => '2026-07-25 22:30:00',
            ], JSON_THROW_ON_ERROR),
        ];

        $method->invoke($command, $source, $scope);
        self::assertTrue(true);

        foreach ([
            ['user_id', 18],
            ['system_hotel_id', 81],
            ['platform', 'meituan'],
        ] as [$field, $value]) {
            $invalid = $source;
            $invalid[$field] = $value;
            try {
                $method->invoke($command, $invalid, $scope);
                self::fail("Expected {$field} scope mismatch.");
            } catch (\ReflectionException $e) {
                throw $e;
            } catch (\Throwable $e) {
                self::assertStringContainsString('outside the collector user/tenant/hotel/platform whitelist', $e->getMessage());
            }
        }

        $wrongDevice = $source;
        $wrongDevice['config_json'] = json_encode([
            'source_method' => 'single_user_local',
            'collector_binding_mode' => 'single_user_local',
            'collector_device_id' => 'cloud-owner-device',
            'collector_device_id_hash' => hash('sha256', 'other-device'),
            'collector_user_id' => 17,
            'collector_tenant_id' => 9,
            'collector_hotel_id' => 80,
            'collector_platform' => 'ctrip',
            'collector_bound_at' => '2026-07-25 22:30:00',
        ], JSON_THROW_ON_ERROR);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not bound to this collector device');
        $method->invoke($command, $wrongDevice, $scope);
    }

    public function testCloudScopeBindingMetadataIsAuditableAndPreservesProfileConfiguration(): void
    {
        $command = new AutoFetchOnlineData();
        $method = new \ReflectionMethod($command, 'cloudCollectorBoundSourceConfig');
        $scope = [
            'device_id' => 'server-owner-device',
            'device_id_hash' => hash('sha256', 'server-owner-device'),
            'user_id' => 1,
            'tenant_id' => 80,
            'hotel_id' => 80,
        ];
        $config = [
            'stable_profile_id' => 'existing-profile',
            'platform_hotel_id' => 'hotel-80',
            'manual_login_state_verified' => true,
        ];

        $bound = $method->invoke(
            $command,
            ['id' => 25, 'platform' => 'ctrip'],
            $config,
            $scope,
            '2026-07-25 22:30:00'
        );

        self::assertSame('existing-profile', $bound['stable_profile_id']);
        self::assertSame('hotel-80', $bound['platform_hotel_id']);
        self::assertTrue($bound['manual_login_state_verified']);
        self::assertSame('single_user_local', $bound['source_method']);
        self::assertSame('single_user_local', $bound['collector_binding_mode']);
        self::assertSame('server-owner-device', $bound['collector_device_id']);
        self::assertSame(hash('sha256', 'server-owner-device'), $bound['collector_device_id_hash']);
        self::assertSame(1, $bound['collector_user_id']);
        self::assertSame(80, $bound['collector_tenant_id']);
        self::assertSame(80, $bound['collector_hotel_id']);
        self::assertSame('ctrip', $bound['collector_platform']);
        self::assertSame('2026-07-25 22:30:00', $bound['collector_bound_at']);
    }

    public function testCloudBindingReceiptNeverClaimsCollectionOrSessionProof(): void
    {
        $command = new AutoFetchOnlineData();
        $scope = [
            'mode' => 'single_user_local',
            'authorization_mode' => 'cross_tenant_super_admin_explicit_hotel_grant',
            'tenant_id' => 80,
            'user_id' => 1,
            'device_id' => 'server-owner-device',
            'device_id_hash' => hash('sha256', 'server-owner-device'),
            'hotel_id' => 80,
            'source_ids' => [25, 68],
            'platforms' => ['ctrip', 'meituan'],
        ];
        (new \ReflectionProperty($command, 'cloudCollectorScope'))->setValue($command, $scope);
        $receipt = (new \ReflectionMethod($command, 'cloudCollectorBindingReceipt'))
            ->invoke($command, 'confirmation_required', false);

        self::assertSame('confirmation_required', $receipt['status']);
        self::assertFalse($receipt['database_write_performed']);
        self::assertFalse($receipt['current_session_probe_performed']);
        self::assertFalse($receipt['collection_performed']);
        self::assertFalse($receipt['persistence_performed']);
        self::assertFalse($receipt['sensitive_values_exposed']);
        self::assertArrayNotHasKey('device_id_hash', $receipt);
    }

    public function testCloudSourceScopeRejectsDuplicateRowsForOneBrowserProfileAccount(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/command/AutoFetchOnlineData.php');

        self::assertStringContainsString(
            'count(OtaOrderedCollectionPlanner::oneSourcePerBrowserProfileAccount($sources)) !== count($sources)',
            $source
        );
        self::assertStringContainsString(
            'select one source id per platform account',
            $source
        );
    }

    public function testCloudCollectionRequiresCurrentRunSessionAndPlatformHotelProofBeforePersistence(): void
    {
        $commandSource = (string)file_get_contents(dirname(__DIR__) . '/app/command/AutoFetchOnlineData.php');
        $syncSource = SourceAggregate::read(dirname(__DIR__), 'app/service/PlatformDataSyncService.php');

        self::assertStringContainsString(
            "'require_collector_binding' => \$this->cloudCollectorScope !== []",
            $commandSource
        );
        self::assertStringContainsString("'require_current_run_session_probe' =>", $commandSource);
        self::assertStringContainsString("\$dataPeriod === 'historical_daily'", $commandSource);
        self::assertStringContainsString("'required_platform_hotel_id' =>", $commandSource);
        self::assertStringContainsString("'required_collector_binding' =>", $commandSource);
        self::assertStringContainsString('assertRequiredCollectorBinding', $syncSource);
        self::assertStringContainsString('assertRequiredCurrentRunProfileSessionProbe', $syncSource);
        self::assertStringContainsString("\$identityStatus === 'matched'", $syncSource);
        self::assertStringContainsString('Current session proof from this execution is missing', $syncSource);
    }

    public function testHistoricalProfileRunUsesOrderedStoredGapPlanWithoutLocalAgentRegistration(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/command/AutoFetchOnlineData.php');

        self::assertStringContainsString('OtaOrderedCollectionPlanner::requestPlanFromStoredRows', $source);
        self::assertStringContainsString("'ordered_collection' => \$orderedPlan", $source);
        self::assertStringContainsString("\$plan['execution_mode'] = 'multi_section_single_task'", $source);
        self::assertStringNotContainsString("\$plan['sections'] = [\$plannedSections[0]]", $source);
        self::assertStringContainsString(
            '$plannedSections = OtaOrderedCollectionPlanner::defaultSections($platform)',
            $source
        );
        self::assertStringContainsString("'natural_exact_task_core_recollection'", $source);
        self::assertStringContainsString("'capture_sections' => implode(',', (array)(\$orderedPlan['sections'] ?? []))", $source);
        self::assertStringContainsString("\$dataPeriod === 'historical_daily' && \$platform === 'ctrip'", $source);
        self::assertStringContainsString('? 1', $source);
        self::assertStringContainsString('target_date_core_already_verified_no_capture', $source);
        self::assertStringContainsString("'reused_verified_count' => \$reusedVerifiedCount", $source);
        self::assertStringNotContainsString('ota_local_collector_accounts', $source);
    }

    public function testRealtimeProfileScheduleUsesLightweightCtripBroadcastPlan(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__) . '/app/command/AutoFetchOnlineData.php'
        );

        self::assertStringContainsString(
            "\$platform === 'ctrip' && \$dataPeriod === 'realtime_snapshot'",
            $source
        );
        self::assertStringContainsString(
            "'collector_flow' => 'realtime'",
            $source
        );
        self::assertStringContainsString(
            "new CtripCollectorWorkflowService()",
            $source
        );
    }

    public function testRealtimeTrafficReadbackUsesTrafficFactsInsteadOfDailyRevenueTriple(): void
    {
        $command = new AutoFetchOnlineData();
        $method = new \ReflectionMethod($command, 'runReadbackCoreVerified');
        $receipt = [
            'readback_verified' => true,
            'p0_status' => 'ready',
            'sync_task_id' => 3028,
            'data_source_id' => 25,
            'started_at' => '2026-08-09 04:10:09',
            'row_ids' => [81368, 81369],
            'source_trace_ids' => ['ctrip:traffic:2026-08-09'],
            'verified_metric_keys' => [],
            'data_period' => 'realtime_snapshot',
            'field_fact_status' => 'ready',
            'required_traffic_metric_keys' => [
                'list_exposure',
                'detail_exposure',
                'flow_rate',
                'order_filling_num',
                'order_submit_num',
            ],
            'complete_traffic_metric_keys' => [
                'list_exposure',
                'detail_exposure',
                'flow_rate',
                'order_filling_num',
                'order_submit_num',
            ],
            'missing_traffic_metric_keys' => [],
        ];

        self::assertTrue($method->invoke($command, $receipt));

        $receipt['missing_traffic_metric_keys'] = ['flow_rate'];
        self::assertFalse($method->invoke($command, $receipt));

        $receipt['missing_traffic_metric_keys'] = [];
        $receipt['data_period'] = 'historical_daily';
        self::assertFalse($method->invoke($command, $receipt));
        $receipt['verified_metric_keys'] = ['revenue', 'room_nights', 'adr'];
        self::assertTrue($method->invoke($command, $receipt));
    }

    public function testExplicitCtripTemporalFlowSurvivesBackgroundAndDataSourceSyncBoundaries(): void
    {
        $source = SourceAggregate::read(
            dirname(__DIR__),
            'app/controller/concern/AutoFetchConcern.php'
        );

        self::assertStringContainsString(
            "\$fetchOptions['ctrip_collector_flow']",
            $source
        );
        self::assertStringContainsString(
            "\$body['ctrip_collector_flow']",
            $source
        );
        self::assertStringContainsString(
            "\$fetchOptions['target_platforms'] = ['ctrip']",
            $source
        );
        self::assertStringContainsString(
            "foreach (['collector_flow', 'capture_plan', 'profile_sections'] as \$key)",
            $source
        );
        self::assertStringContainsString(
            "'capture_sections' => \$periodOptions['capture_sections']",
            $source
        );
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
        self::assertGreaterThanOrEqual(3, substr_count($source, 'bool $forceRerun = false'));
        self::assertStringContainsString("'force_rerun' => \$forceRerun", $source);
        self::assertStringContainsString("'trigger_type' => 'daily_profile_reuse'", $source);
        self::assertMatchesRegularExpression(
            '/if \(!\$forceRerun\s*&& \$dataPeriod === \'historical_daily\'/s',
            $source
        );
        self::assertMatchesRegularExpression('/\$sourceIds,\s*\$forceRerun\s*\);/', $source);
    }

    public function testForceRerunIgnoresOldReadbackAndBuildsExactCtripTrafficAuthorityPlan(): void
    {
        $command = new AutoFetchOnlineData();
        $resolve = new \ReflectionMethod($command, 'resolveVerifiedCompleteHistoricalExecution');
        $rows = [
            [
                'id' => 81818,
                'platform' => 'ctrip',
                'source' => 'ctrip',
                'data_type' => 'business',
                'data_period' => 'historical_daily',
                'readback_verified' => 1,
                'order_amount' => 100,
                'room_nights' => 2,
                'order_count' => 1,
            ],
            [
                'id' => 81819,
                'platform' => 'ctrip',
                'source' => 'ctrip',
                'data_type' => 'traffic',
                'data_period' => 'historical_daily',
                'readback_verified' => 1,
                'dimension' => 'catalog:traffic_report:traffic_flow_transform:list_exposure',
                'list_exposure' => 0,
                'detail_exposure' => 0,
                'flow_rate' => 0,
                'order_filling_num' => 0,
                'order_submit_num' => 0,
            ],
        ];
        $verifiedCompletePlan = [
            'platform' => 'ctrip',
            'target_date' => '2026-08-08',
            'stage' => 'verified_complete',
            'sections' => [],
        ];
        $oldReadback = [
            'sync_task_id' => 3084,
            'row_ids' => [81818, 81819],
        ];

        $forced = $resolve->invoke(
            $command,
            'ctrip',
            '2026-08-08',
            $rows,
            $verifiedCompletePlan,
            $oldReadback,
            true
        );

        self::assertSame([], $forced['reused_run_readback']);
        self::assertSame(['traffic_report'], $forced['plan']['sections']);
        self::assertSame(['traffic_report'], $forced['plan']['planned_sections']);
        self::assertSame([], $forced['plan']['pending_sections']);
        self::assertSame('ctrip', $forced['plan']['platform']);
        self::assertSame('2026-08-08', $forced['plan']['target_date']);
        self::assertSame('authority_recollection', $forced['plan']['stage']);
        self::assertSame('single_section_bounded', $forced['plan']['execution_mode']);
        self::assertSame('explicit_force_rerun_authority_recollection', $forced['plan']['reason']);
        self::assertSame('exact_target_date_no_replay_or_rewrite', $forced['plan']['date_policy']);
        self::assertTrue($forced['plan']['force_rerun']);
        self::assertFalse($forced['plan']['reuse_existing_run_readback']);
        self::assertNotContains('meituan', $forced['plan']);

        $ordinary = $resolve->invoke(
            $command,
            'ctrip',
            '2026-08-08',
            $rows,
            $verifiedCompletePlan,
            $oldReadback,
            false
        );
        self::assertSame($oldReadback, $ordinary['reused_run_readback']);
        self::assertSame([], $ordinary['plan']['sections']);

        $meituan = $resolve->invoke(
            $command,
            'meituan',
            '2026-08-08',
            [],
            ['platform' => 'meituan', 'target_date' => '2026-08-08', 'sections' => []],
            $oldReadback,
            true
        );
        self::assertSame(['orders', 'traffic'], $meituan['plan']['sections']);
        self::assertSame(['orders', 'traffic'], $meituan['plan']['planned_sections']);
        self::assertSame([], $meituan['plan']['pending_sections']);
        self::assertSame('multi_section_single_task', $meituan['plan']['execution_mode']);

        (new \ReflectionProperty($command, 'dispatcherRunId'))->setValue(
            $command,
            '12345678-1234-4234-8234-123456789abc'
        );
        $naturalCtrip = $resolve->invoke(
            $command,
            'ctrip',
            '2026-08-08',
            $rows,
            $verifiedCompletePlan,
            [],
            false
        );
        self::assertSame(
            ['business_overview', 'traffic_report'],
            $naturalCtrip['plan']['sections']
        );
        self::assertSame([], $naturalCtrip['plan']['pending_sections']);
        self::assertSame('multi_section_single_task', $naturalCtrip['plan']['execution_mode']);
        self::assertSame(
            \app\service\OtaOrderedCollectionPlanner::requiredFieldKeys('ctrip'),
            $naturalCtrip['plan']['recollection_field_keys']
        );
    }

    public function testStaleRunReadbackCannotReuseRowsNowOwnedByAnotherTask(): void
    {
        $command = new AutoFetchOnlineData();
        $stillCurrent = new \ReflectionMethod($command, 'profileRunReadbackRowsStillCurrent');
        $resolve = new \ReflectionMethod($command, 'resolveVerifiedCompleteHistoricalExecution');
        $readback = [
            'sync_task_id' => 3084,
            'row_ids' => [81818, 81819],
        ];
        $base = [
            'system_hotel_id' => 80,
            'data_source_id' => 25,
            'sync_task_id' => 3084,
            'platform' => 'ctrip',
            'source' => 'ctrip',
            'data_date' => '2026-08-08',
            'data_period' => 'historical_daily',
            'readback_verified' => 1,
        ];
        $currentRows = [
            array_replace($base, [
                'id' => 81818,
                'data_type' => 'business',
                'order_amount' => 100,
                'room_nights' => 2,
                'order_count' => 1,
                'raw_data' => '{}',
            ]),
            array_replace($base, [
                'id' => 81819,
                'data_type' => 'traffic',
                'dimension' => 'catalog:traffic_report:traffic_flow_transform:list_exposure',
                'list_exposure' => 0,
                'detail_exposure' => 0,
                'flow_rate' => 0,
                'order_filling_num' => 0,
                'order_submit_num' => 0,
                'raw_data' => json_encode([
                    'row' => [
                        'endpoint_id' => 'traffic_flow_transform',
                        '_observed_traffic_metric_keys' => [
                            'list_exposure',
                            'detail_exposure',
                            'flow_rate',
                            'order_filling_num',
                            'order_submit_num',
                        ],
                    ],
                ], JSON_THROW_ON_ERROR),
            ]),
        ];

        self::assertTrue($stillCurrent->invoke(
            $command,
            $readback,
            $currentRows,
            80,
            25,
            'ctrip',
            '2026-08-08'
        ));

        $rowsNowOwnedByTask3085 = array_map(
            static fn(array $row): array => array_replace($row, ['sync_task_id' => 3085]),
            $currentRows
        );
        self::assertFalse($stillCurrent->invoke(
            $command,
            $readback,
            $rowsNowOwnedByTask3085,
            80,
            25,
            'ctrip',
            '2026-08-08'
        ));

        foreach ([
            ' flow_rate',
            'Flow_Rate',
            123,
        ] as $invalidMarkerValue) {
            $invalidMarkerRows = $currentRows;
            $marker = [
                'list_exposure',
                'detail_exposure',
                'flow_rate',
                'order_filling_num',
                'order_submit_num',
            ];
            $marker[2] = $invalidMarkerValue;
            $invalidMarkerRows[1]['raw_data'] = json_encode([
                'row' => [
                    'endpoint_id' => 'traffic_flow_transform',
                    '_observed_traffic_metric_keys' => $marker,
                ],
            ], JSON_THROW_ON_ERROR);
            self::assertFalse($stillCurrent->invoke(
                $command,
                $readback,
                $invalidMarkerRows,
                80,
                25,
                'ctrip',
                '2026-08-08'
            ));
        }

        foreach ([
            ['data_source_id' => 26],
            ['system_hotel_id' => 81],
            ['data_date' => '2026-08-07'],
            ['data_period' => 'realtime_snapshot'],
        ] as $scopeMismatch) {
            $mismatchedRows = $currentRows;
            $mismatchedRows[0] = array_replace($mismatchedRows[0], $scopeMismatch);
            self::assertFalse($stillCurrent->invoke(
                $command,
                $readback,
                $mismatchedRows,
                80,
                25,
                'ctrip',
                '2026-08-08'
            ));
        }

        $withoutMarker = $currentRows;
        $withoutMarker[1]['raw_data'] = json_encode([
            'row' => ['endpoint_id' => 'traffic_flow_transform'],
        ], JSON_THROW_ON_ERROR);
        self::assertFalse($stillCurrent->invoke(
            $command,
            $readback,
            $withoutMarker,
            80,
            25,
            'ctrip',
            '2026-08-08'
        ));

        $auxiliaryMarker = $currentRows;
        $auxiliaryMarker[1]['dimension'] = '';
        $auxiliaryMarker[1]['raw_data'] = json_encode([
            'row' => [
                'endpoint_id' => 'traffic_hotel_seq',
                '_observed_traffic_metric_keys' => [
                    'list_exposure',
                    'detail_exposure',
                    'flow_rate',
                    'order_filling_num',
                    'order_submit_num',
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        self::assertFalse($stillCurrent->invoke(
            $command,
            $readback,
            $auxiliaryMarker,
            80,
            25,
            'ctrip',
            '2026-08-08'
        ));

        $recollection = $resolve->invoke(
            $command,
            'ctrip',
            '2026-08-08',
            $rowsNowOwnedByTask3085,
            ['platform' => 'ctrip', 'target_date' => '2026-08-08', 'sections' => []],
            [],
            false
        );
        self::assertSame([], $recollection['reused_run_readback']);
        self::assertSame(['traffic_report'], $recollection['plan']['sections']);
        self::assertSame(
            'verified_rows_without_current_bound_run_readback',
            $recollection['plan']['reason']
        );
        self::assertFalse($recollection['plan']['force_rerun']);

        $source = (string)file_get_contents(dirname(__DIR__) . '/app/command/AutoFetchOnlineData.php');
        self::assertStringContainsString(
            "(int)(\$readback['sync_task_id'] ?? 0) === (int)(\$task['id'] ?? 0)",
            $source
        );
    }

    public function testHistoricalExecutedCacheRequiresCurrentExactTaskAndRowMembership(): void
    {
        $command = new AutoFetchOnlineData();
        $matches = new \ReflectionMethod($command, 'cachedHistoricalDailyReceiptRowsMatch');
        $receipt = [
            'hotel_id' => 80,
            'target_date' => '2026-08-08',
            'data_period' => 'historical_daily',
            'source_ids' => [25, 68],
            'required_platforms' => ['ctrip', 'meituan'],
            'source_tasks' => [
                [
                    'data_source_id' => 25,
                    'sync_task_id' => 901,
                    'platform' => 'ctrip',
                    'collection_status' => 'success',
                    'p0_status' => 'ready',
                    'row_ids' => [91001, 91002],
                ],
                [
                    'data_source_id' => 68,
                    'sync_task_id' => 902,
                    'platform' => 'meituan',
                    'collection_status' => 'success',
                    'p0_status' => 'ready',
                    'row_ids' => [92001, 92002],
                ],
            ],
        ];
        $readback = static fn(
            int $sourceId,
            int $taskId,
            string $platform,
            array $rowIds
        ): array => [
            'readback_verified' => true,
            'sync_task_id' => $taskId,
            'data_source_id' => $sourceId,
            'system_hotel_id' => 80,
            'platform' => $platform,
            'target_date' => '2026-08-08',
            'data_period' => 'historical_daily',
            'started_at' => '2026-08-09 08:30:00',
            'row_ids' => $rowIds,
            'source_trace_ids' => ["trace-{$taskId}"],
        ];
        $baseRow = [
            'tenant_id' => 80,
            'system_hotel_id' => 80,
            'data_date' => '2026-08-08',
            'data_period' => 'historical_daily',
            'readback_verified' => 1,
            'validation_status' => 'verified',
            'compare_type' => 'self',
        ];
        $ctripRow = array_replace($baseRow, [
            'id' => 91001,
            'data_source_id' => 25,
            'sync_task_id' => 901,
            'platform' => 'ctrip',
            'source' => 'ctrip',
            'data_type' => 'traffic',
            'dimension' => 'catalog:traffic_report:traffic_flow_transform:list_exposure',
            'list_exposure' => 12,
            'detail_exposure' => 8,
            'flow_rate' => 30,
            'order_filling_num' => 3,
            'order_submit_num' => 2,
            'raw_data' => json_encode([
                'row' => [
                    'endpoint_id' => 'traffic_flow_transform',
                    '_observed_traffic_metric_keys' => [
                        'list_exposure',
                        'detail_exposure',
                        'flow_rate',
                        'order_filling_num',
                        'order_submit_num',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);
        $meituanRow = array_replace($baseRow, [
            'id' => 92001,
            'data_source_id' => 68,
            'sync_task_id' => 902,
            'platform' => 'meituan',
            'source' => 'meituan',
            'data_type' => 'traffic',
            'list_exposure' => 20,
            'detail_exposure' => 10,
            'flow_rate' => 50,
            'raw_data' => json_encode([
                'row' => [
                    '_capture_source' => 'xhr:traffic:traffic',
                    '_observed_traffic_metric_keys' => [
                        'list_exposure',
                        'detail_exposure',
                        'flow_rate',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);
        $ctripBusinessRow = array_replace($baseRow, [
            'id' => 91002,
            'data_source_id' => 25,
            'sync_task_id' => 901,
            'platform' => 'ctrip',
            'source' => 'ctrip',
            'data_type' => 'business',
            'order_amount' => 100,
            'room_nights' => 2,
            'order_count' => 1,
            'raw_data' => '{}',
        ]);
        $meituanOrderRow = array_replace($baseRow, [
            'id' => 92002,
            'data_source_id' => 68,
            'sync_task_id' => 902,
            'platform' => 'meituan',
            'source' => 'meituan',
            'data_type' => 'order',
            'order_amount' => 200,
            'room_nights' => 3,
            'order_count' => 2,
            'raw_data' => '{}',
        ]);
        $taskReadbacks = [
            901 => $readback(25, 901, 'ctrip', [91001, 91002]),
            902 => $readback(68, 902, 'meituan', [92001, 92002]),
        ];
        $rowsBySource = [
            25 => [$ctripRow, $ctripBusinessRow],
            68 => [$meituanRow, $meituanOrderRow],
        ];

        self::assertTrue($matches->invoke(
            $command,
            $receipt,
            80,
            $taskReadbacks,
            $rowsBySource
        ));

        $dispatcherRunId = '12345678-1234-4234-8234-123456789abc';
        $dispatcherProperty = new \ReflectionProperty($command, 'dispatcherRunId');
        $dispatcherProperty->setValue($command, $dispatcherRunId);
        self::assertFalse($matches->invoke(
            $command,
            $receipt,
            80,
            $taskReadbacks,
            $rowsBySource
        ));
        foreach ($receipt['source_tasks'] as &$sourceTask) {
            $sourceTask['dispatcher_run_id'] = $dispatcherRunId;
            $sourceTask['trigger_type'] = 'daily_profile_reuse';
        }
        unset($sourceTask);
        foreach ($taskReadbacks as &$taskReadback) {
            $taskReadback['dispatcher_run_id'] = $dispatcherRunId;
            $taskReadback['trigger_type'] = 'daily_profile_reuse';
        }
        unset($taskReadback);
        self::assertTrue($matches->invoke(
            $command,
            $receipt,
            80,
            $taskReadbacks,
            $rowsBySource
        ));

        $mismatchedNaturalReceipt = $receipt;
        $mismatchedNaturalReceipt['source_tasks'][0]['dispatcher_run_id'] =
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        self::assertFalse($matches->invoke(
            $command,
            $mismatchedNaturalReceipt,
            80,
            $taskReadbacks,
            $rowsBySource
        ));

        $stolenRows = $rowsBySource;
        $stolenRows[25][0]['sync_task_id'] = 903;
        self::assertFalse($matches->invoke($command, $receipt, 80, $taskReadbacks, $stolenRows));

        $deletedRows = $rowsBySource;
        $deletedRows[68] = [];
        self::assertFalse($matches->invoke($command, $receipt, 80, $taskReadbacks, $deletedRows));

        $crossTenantRows = $rowsBySource;
        $crossTenantRows[25][0]['tenant_id'] = 81;
        self::assertFalse($matches->invoke($command, $receipt, 80, $taskReadbacks, $crossTenantRows));

        $staleTaskReceipt = $taskReadbacks;
        $staleTaskReceipt[901]['row_ids'] = [91001, 91002, 91003];
        self::assertFalse($matches->invoke(
            $command,
            $receipt,
            80,
            $staleTaskReceipt,
            $rowsBySource
        ));

        $missingPlatformReceipt = $receipt;
        array_pop($missingPlatformReceipt['source_tasks']);
        self::assertFalse($matches->invoke(
            $command,
            $missingPlatformReceipt,
            80,
            $taskReadbacks,
            $rowsBySource
        ));

        $unlistedMetricRow = $rowsBySource;
        $unlistedMetricRow[25][0]['list_exposure'] = null;
        $unlistedMetricRow[25][] = array_replace($ctripRow, [
            'id' => 91003,
            'list_exposure' => 99,
        ]);
        self::assertFalse($matches->invoke(
            $command,
            $receipt,
            80,
            $taskReadbacks,
            $unlistedMetricRow
        ));
    }

    public function testHistoricalTaskCannotBorrowP0MetricsFromAnotherTaskOrPeriod(): void
    {
        $command = new AutoFetchOnlineData();
        $complete = new \ReflectionMethod($command, 'exactTaskP0RowsComplete');
        $coreComplete = new \ReflectionMethod($command, 'exactTaskOrderedCoreRowsComplete');
        $readback = ['sync_task_id' => 3086, 'row_ids' => [91001, 91002]];
        $base = [
            'system_hotel_id' => 80,
            'data_source_id' => 25,
            'sync_task_id' => 3086,
            'platform' => 'ctrip',
            'source' => 'ctrip',
            'data_date' => '2026-08-08',
            'data_period' => 'historical_daily',
            'readback_verified' => 1,
        ];
        $businessRow = array_replace($base, [
            'id' => 91001,
            'data_type' => 'business',
            'order_amount' => 100,
            'room_nights' => 2,
            'order_count' => 1,
            'raw_data' => '{}',
        ]);
        $trafficRow = array_replace($base, [
            'id' => 91002,
            'data_type' => 'traffic',
            'dimension' => 'catalog:traffic_report:traffic_flow_transform:list_exposure',
            'list_exposure' => 10,
            'detail_exposure' => 4,
            'flow_rate' => 40,
            'order_filling_num' => 2,
            'order_submit_num' => 1,
            'raw_data' => json_encode([
                'row' => [
                    'endpoint_id' => 'traffic_flow_transform',
                    '_observed_traffic_metric_keys' => [
                        'list_exposure',
                        'detail_exposure',
                        'flow_rate',
                        'order_filling_num',
                        'order_submit_num',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        self::assertTrue($complete->invoke(
            $command,
            $readback,
            [$businessRow, $trafficRow],
            80,
            25,
            'ctrip',
            '2026-08-08'
        ));
        self::assertTrue($coreComplete->invoke(
            $command,
            $readback,
            [$businessRow, $trafficRow],
            80,
            25,
            'ctrip',
            '2026-08-08'
        ));
        self::assertTrue($complete->invoke(
            $command,
            ['sync_task_id' => 3086, 'row_ids' => [91002]],
            [$trafficRow],
            80,
            25,
            'ctrip',
            '2026-08-08'
        ));
        self::assertFalse($coreComplete->invoke(
            $command,
            ['sync_task_id' => 3086, 'row_ids' => [91002]],
            [$businessRow, $trafficRow],
            80,
            25,
            'ctrip',
            '2026-08-08'
        ));
        $businessWithTrafficShapedFields = array_replace($businessRow, [
            'list_exposure' => 10,
            'detail_exposure' => 4,
            'flow_rate' => 40,
            'order_filling_num' => 2,
            'order_submit_num' => 1,
        ]);
        self::assertFalse($complete->invoke(
            $command,
            ['sync_task_id' => 3086, 'row_ids' => [91001]],
            [$businessWithTrafficShapedFields],
            80,
            25,
            'ctrip',
            '2026-08-08'
        ));
        self::assertFalse($complete->invoke(
            $command,
            $readback,
            [$businessRow, array_replace($trafficRow, ['sync_task_id' => 3084])],
            80,
            25,
            'ctrip',
            '2026-08-08'
        ));
        self::assertFalse($complete->invoke(
            $command,
            $readback,
            [$businessRow, array_replace($trafficRow, ['data_period' => 'realtime_snapshot'])],
            80,
            25,
            'ctrip',
            '2026-08-08'
        ));

        $source = (string)file_get_contents(dirname(__DIR__) . '/app/command/AutoFetchOnlineData.php');
        self::assertStringContainsString(
            "\$historicalCoreContractVerified = \$dataPeriod === 'historical_daily'",
            $source
        );
        self::assertStringContainsString('&& $historicalCoreContractVerified', $source);
        self::assertStringContainsString('exactTaskOrderedCoreRowsComplete', $source);
        self::assertStringNotContainsString(
            "\$this->runReadbackCoreVerified(\$runReadback)\n                || \$compositeReadbackVerified",
            $source
        );
    }

    public function testMachineReceiptRequiresEveryExplicitSourceTaskIdentity(): void
    {
        $command = new AutoFetchOnlineData();
        $method = new \ReflectionMethod($command, 'buildMachineReceipt');
        $sourceResult = static fn(int $sourceId, int $taskId, string $platform, bool $success = true): array => [
            'success' => $success,
            'data_source_id' => $sourceId,
            'platform' => $platform,
            'historical_core_contract_status' => 'ready',
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

        $dispatcherRunId = '12345678-1234-4234-8234-123456789abc';
        $dispatcherProperty = new \ReflectionProperty($command, 'dispatcherRunId');
        $dispatcherProperty->setValue($command, $dispatcherRunId);
        $decoratedResults = [
            $sourceResult(68, 902, 'meituan'),
            $sourceResult(25, 901, 'ctrip'),
        ];
        foreach ($decoratedResults as &$decoratedResult) {
            $decoratedResult['run_readback']['dispatcher_run_id'] = $dispatcherRunId;
            $decoratedResult['run_readback']['trigger_type'] = 'daily_profile_reuse';
        }
        unset($decoratedResult);
        $decorated = $method->invoke(
            $command,
            80,
            '2026-07-22',
            [25, 68],
            ['complete' => true, 'status' => 'success'],
            ['platform_results' => $decoratedResults]
        );
        self::assertSame($dispatcherRunId, $decorated['dispatcher_run_id']);
        self::assertSame(
            [$dispatcherRunId, $dispatcherRunId],
            array_column($decorated['source_tasks'], 'dispatcher_run_id')
        );

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

    public function testCanonicalDailyAnalysisIsANonBlockingPostPromotionSidecarWithLocalOnlyCachedReplay(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/command/AutoFetchOnlineData.php');

        self::assertStringContainsString('use app\\service\\CanonicalOtaDailyOperationFinalizer;', $source);
        self::assertStringContainsString("\$receipt['canonical_operation_finalization'] = \$analysis;", $source);
        self::assertStringContainsString("\$receipt['canonical_operation_contract_version']", $source);
        self::assertStringContainsString("\$status['canonical_daily_analysis_authorization']", $source);
        self::assertStringNotContainsString(
            "(string)(\$executedReceipt['canonical_operation_contract_version'] ?? '')",
            $source
        );
        self::assertStringContainsString(
            '$this->cachedHistoricalDailyReceiptRowsStillCurrent(',
            $source
        );
        self::assertStringNotContainsString(
            "is_array(\$executedReceipt['canonical_operation_finalization'] ?? null)",
            $source
        );
        self::assertStringContainsString(
            'Cache::set($run[\'executed_key\'], $executedReceipt, 86400);',
            $source
        );
        self::assertStringContainsString(
            '$this->persistCachedCanonicalDailyOperationStatus(',
            $source
        );

        $promotionAt = strpos($source, '(new OtaCanonicalHistoryPromotionCoordinator())');
        $cachedReplayAt = strpos($source, '$executedReceipt = $this->attachCanonicalDailyOperationFinalization(');
        $cachedStatusAt = strpos($source, '$this->persistCachedCanonicalDailyOperationStatus(');
        $cachedSkipAt = strpos($source, 'already executed with requested-scope P0 proof, skipped.');
        $cachedMembershipAt = strpos($source, '$this->cachedHistoricalDailyReceiptRowsStillCurrent(');
        $analysisAt = strpos($source, '$receipt = $this->attachCanonicalDailyOperationFinalization(');
        $trustedAt = strpos($source, '$trustedReady = $this->machineReceiptDailyTrustReady(');
        self::assertIsInt($promotionAt);
        self::assertIsInt($cachedReplayAt);
        self::assertIsInt($cachedStatusAt);
        self::assertIsInt($cachedSkipAt);
        self::assertIsInt($cachedMembershipAt);
        self::assertIsInt($analysisAt);
        self::assertIsInt($trustedAt);
        self::assertLessThan($cachedStatusAt, $cachedReplayAt);
        self::assertLessThan($cachedSkipAt, $cachedStatusAt);
        self::assertLessThan($cachedReplayAt, $cachedMembershipAt);
        self::assertStringNotContainsString(
            'updateStatus(',
            substr($source, $cachedReplayAt, $cachedSkipAt - $cachedReplayAt)
        );
        self::assertLessThan($analysisAt, $promotionAt);
        self::assertLessThan($trustedAt, $analysisAt);

        $trustedSlice = substr($source, $trustedAt, 900);
        self::assertStringNotContainsString('canonical_operation_complete', $trustedSlice);
        self::assertStringNotContainsString('canonical_operation_finalization', $trustedSlice);
    }

    public function testCachedCanonicalSidecarRecoveryPatchesOnlyExactExistingStatusReceipt(): void
    {
        $command = new AutoFetchOnlineData();
        $method = new \ReflectionMethod($command, 'mergeCachedCanonicalDailyOperationStatus');
        $anchor = str_repeat('a', 64);
        $blockedReceipt = [
            'hotel_id' => 80,
            'target_date' => '2026-08-08',
            'data_period' => 'historical_daily',
            'collection_anchor_hash' => $anchor,
            'canonical_history_complete' => true,
            'canonical_operation_complete' => false,
            'canonical_operation_contract_version' => 'canonical_ota_daily_operation_finalization.v2',
            'canonical_operation_finalization' => [
                'status' => 'blocked',
                'trusted_operational_check_count' => 0,
            ],
        ];
        $verifiedReceipt = $blockedReceipt;
        $verifiedReceipt['canonical_operation_complete'] = true;
        $verifiedReceipt['canonical_operation_finalization'] = [
            'status' => 'verified',
            'trusted_operational_check_count' => 4,
            'external_action_triggered' => false,
        ];
        $exactRecord = [
            'data_date' => '2026-08-08',
            'data_period' => 'historical_daily',
            'slot_id' => 'historical:2026-08-08',
            'success' => false,
            'status' => 'partial_success',
            'message' => 'collection truth stays unchanged',
            'failed_platforms' => ['meituan'],
            'trust_receipt' => $blockedReceipt,
        ];
        $otherSlotRecord = $exactRecord;
        $otherSlotRecord['slot_id'] = 'historical:2026-08-07';
        $status = [
            'enabled' => true,
            'last_data_date' => '2026-08-08',
            'last_result' => $exactRecord,
            'recent_runs' => [$exactRecord, $otherSlotRecord],
        ];

        $updated = $method->invoke(
            $command,
            $status,
            $verifiedReceipt,
            80,
            '2026-08-08',
            'historical:2026-08-08'
        );

        self::assertFalse($updated['last_result']['success']);
        self::assertSame('partial_success', $updated['last_result']['status']);
        self::assertSame(['meituan'], $updated['last_result']['failed_platforms']);
        self::assertSame('collection truth stays unchanged', $updated['last_result']['message']);
        self::assertSame(
            4,
            $updated['last_result']['trust_receipt']['canonical_operation_finalization']['trusted_operational_check_count']
        );
        self::assertSame('verified', $updated['recent_runs'][0]['trust_receipt']['canonical_operation_finalization']['status']);
        self::assertSame($otherSlotRecord, $updated['recent_runs'][1]);
        self::assertCount(2, $updated['recent_runs']);
        self::assertSame(
            $status,
            $method->invoke(
                $command,
                $status,
                $verifiedReceipt,
                81,
                '2026-08-08',
                'historical:2026-08-08'
            )
        );
        self::assertSame(
            $status,
            $method->invoke(
                $command,
                $status,
                $verifiedReceipt,
                80,
                '2026-08-07',
                'historical:2026-08-07'
            )
        );
        $missingRunsStatus = [
            'last_data_date' => '2026-08-08',
            'last_result' => $otherSlotRecord,
        ];
        self::assertSame(
            $missingRunsStatus,
            $method->invoke(
                $command,
                $missingRunsStatus,
                $verifiedReceipt,
                80,
                '2026-08-08',
                'historical:2026-08-08'
            )
        );
    }

    public function testExactPlanRunReceiptSurroundsGateCollectionAndTrustFinalization(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__) . '/app/command/AutoFetchOnlineData.php'
        );

        self::assertStringContainsString(
            'use app\\service\\HotelCollectionRunReceiptService;',
            $source
        );
        $beginAt = strpos(
            $source,
            '(new HotelCollectionRunReceiptService())->begin($planGate)'
        );
        $gateOutputAt = strpos($source, "'SUXIOS_COLLECTION_PLAN_GATE='");
        $blockedReturnAt = strpos($source, 'return 78;', (int)$gateOutputAt);
        $scopeResultsAt = strpos($source, '$this->scopedScheduledPlatformResults(');
        $recordResultsAt = strpos($source, '$this->recordScheduledPlatformResults(');
        $classifyAt = strpos($source, '$outcome = $this->classifyScheduledRunOutcome(');
        $trustedAt = strpos($source, '$trustedReady = $this->machineReceiptDailyTrustReady(');
        $finalizeAt = strpos($source, '$this->finalizeScheduledCollectionReceipt(');
        $downgradeAt = strpos($source, 'if (!$trustedReady && $outcome[\'complete\'])');

        foreach ([
            $beginAt,
            $gateOutputAt,
            $blockedReturnAt,
            $scopeResultsAt,
            $recordResultsAt,
            $classifyAt,
            $trustedAt,
            $finalizeAt,
            $downgradeAt,
        ] as $position) {
            self::assertIsInt($position);
        }
        self::assertTrue($beginAt < $gateOutputAt && $gateOutputAt < $blockedReturnAt);
        self::assertTrue(
            $scopeResultsAt < $recordResultsAt && $recordResultsAt < $classifyAt
        );
        self::assertTrue($trustedAt < $finalizeAt && $finalizeAt < $downgradeAt);
        self::assertStringContainsString(
            "'hotel_collection_run_receipt_write_failed'",
            $source
        );
        self::assertStringContainsString(
            'hotel_collection_run_final_receipt_write_failed',
            $source
        );
    }
}

final class AutoFetchBlockedPlanGateCommand extends AutoFetchOnlineData
{
    /** @var array<string,mixed> */
    public array $capturedScope = [];

    protected function scheduledCollectionPlanGate(
        int $hotelId,
        string $businessDate,
        array $sourceIds,
        array $platforms,
        string $runMode
    ): array {
        $this->capturedScope = [
            'hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'source_ids' => $sourceIds,
            'platforms' => $platforms,
            'run_mode' => $runMode,
        ];
        return [
            'schema_version' => 1,
            'status' => 'blocked',
            'collection_allowed' => false,
            'failure_reasons' => [[
                'code' => 'hotel_collection_plan_not_active',
                'platform' => '',
                'message' => 'This plan is not active.',
            ]],
            'automatic_device_substitution' => false,
            'sensitive_values_exposed' => false,
        ];
    }
}
