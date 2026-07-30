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

    public function testSourceScopeIsAppliedInsideTheHotelAndProfileQuery(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__) . '/app/command/AutoFetchOnlineData.php');

        self::assertStringContainsString("\$sourceQuery->whereIn('id', \$sourceIds)", $source);
        self::assertStringContainsString('scheduled_profile_source_scope_missing:', $source);
        self::assertStringContainsString('profileSourcesForRun($sources, $sourceIds)', $source);
        self::assertStringContainsString('SUXIOS_AUTO_FETCH_RECEIPT=', $source);
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
        $syncSource = (string)file_get_contents(dirname(__DIR__) . '/app/service/PlatformDataSyncService.php');

        self::assertStringContainsString(
            "'require_current_session_probe' => \$this->cloudCollectorScope !== []",
            $commandSource
        );
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
        self::assertStringContainsString("\$plan['execution_mode'] = 'single_section_bounded'", $source);
        self::assertStringContainsString("'capture_sections' => implode(',', (array)(\$orderedPlan['sections'] ?? []))", $source);
        self::assertStringContainsString("\$dataPeriod === 'historical_daily' && \$platform === 'ctrip'", $source);
        self::assertStringContainsString('? 1', $source);
        self::assertStringContainsString('target_date_core_already_verified_no_capture', $source);
        self::assertStringContainsString("'reused_verified_count' => \$reusedVerifiedCount", $source);
        self::assertStringNotContainsString('ota_local_collector_accounts', $source);
    }

    public function testExplicitCtripTemporalFlowSurvivesBackgroundAndDataSourceSyncBoundaries(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__) . '/app/controller/concern/AutoFetchConcern.php'
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
