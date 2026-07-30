<?php
declare(strict_types=1);

namespace Tests;

use app\service\DualOtaContinuousTrustService;
use PHPUnit\Framework\TestCase;

final class DualOtaContinuousTrustServiceTest extends TestCase
{
    public function testBothPlatformsMustCloseEveryStepForConsecutiveVerifiedDays(): void
    {
        [$hotel, $sources, $rows, $tasks, $rawRecords, $bindings] = $this->fixture(['2026-07-21', '2026-07-22']);

        $result = DualOtaContinuousTrustService::evaluate(
            $hotel,
            '2026-07-21',
            '2026-07-22',
            $rows,
            $sources,
            $tasks,
            true,
            true,
            $rawRecords,
            $bindings
        );

        self::assertSame('verified', $result['status']);
        self::assertSame(2, $result['verified_days']);
        self::assertSame(2, $result['consecutive_verified_days']);
        self::assertSame(['verified', 'verified'], array_column($result['days'], 'status'));
        self::assertSame(
            [
                'source', 'account_profile_binding', 'hotel', 'date', 'field_facts',
                'raw_save', 'organized_save', 'save', 'readback', 'conflict_recollect',
                'page_status', 'p0',
            ],
            $result['required_steps']
        );
    }

    public function testOlderReadyRowCannotReplaceLatestDateMissingFieldFact(): void
    {
        [$hotel, $sources, $rows, $tasks, $rawRecords, $bindings] = $this->fixture(['2026-07-21', '2026-07-22']);
        foreach ($rows as &$row) {
            if ($row['platform'] !== 'meituan' || $row['data_date'] !== '2026-07-22') {
                continue;
            }
            $raw = json_decode((string)$row['raw_data'], true, 64, JSON_THROW_ON_ERROR);
            $raw['field_facts'] = array_values(array_filter(
                $raw['field_facts'],
                static fn(array $fact): bool => $fact['metric_key'] !== 'flow_rate'
            ));
            $row['raw_data'] = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        unset($row);

        $result = DualOtaContinuousTrustService::evaluate(
            $hotel,
            '2026-07-21',
            '2026-07-22',
            $rows,
            $sources,
            $tasks,
            true,
            true,
            $rawRecords,
            $bindings
        );

        self::assertSame('partial', $result['status']);
        self::assertSame(0, $result['consecutive_verified_days']);
        self::assertSame('partial', $result['days'][0]['status']);
        $meituan = $this->platform($result['days'][0], 'meituan');
        self::assertSame('partial', $meituan['status']);
        self::assertContains('field_facts', $meituan['missing_steps']);
        self::assertContains('flow_rate', $meituan['missing_metric_keys']);
    }

    public function testExactDateCollectionFailureIsExplicitAtPlatformLevel(): void
    {
        [$hotel, $sources, $rows, $tasks, $rawRecords, $bindings] = $this->fixture(['2026-07-22']);
        $rows = array_values(array_filter(
            $rows,
            static fn(array $row): bool => $row['platform'] !== 'ctrip'
        ));
        foreach ($tasks as &$task) {
            if ($task['platform'] === 'ctrip') {
                $task['status'] = 'failed';
                $task['message'] = 'target_date_profile_collection_failed';
                $task['stats_json']['sync_diagnostics']['p0_status'] = 'blocked';
            }
        }
        unset($task);

        $result = DualOtaContinuousTrustService::evaluate(
            $hotel,
            '2026-07-22',
            '2026-07-22',
            $rows,
            $sources,
            $tasks,
            true,
            true,
            $rawRecords,
            $bindings
        );

        self::assertSame('partial', $result['status']);
        $ctrip = $this->platform($result['days'][0], 'ctrip');
        self::assertSame('collection_failed', $ctrip['status']);
        self::assertSame('target_date_profile_collection_failed', $ctrip['failure_reason']);
        self::assertFalse($ctrip['steps']['date']);
        self::assertSame('verified', $this->platform($result['days'][0], 'meituan')['status']);
    }

    public function testMissingReadbackColumnFailsClosedInsteadOfUsingLegacyRows(): void
    {
        [$hotel, $sources, $rows, $tasks, $rawRecords, $bindings] = $this->fixture(['2026-07-22']);

        $result = DualOtaContinuousTrustService::evaluate(
            $hotel,
            '2026-07-22',
            '2026-07-22',
            $rows,
            $sources,
            $tasks,
            false,
            true,
            $rawRecords,
            $bindings
        );

        self::assertSame('partial', $result['status']);
        foreach ($result['days'][0]['platforms'] as $platform) {
            self::assertFalse($platform['steps']['readback']);
            self::assertFalse($platform['steps']['page_status']);
            self::assertFalse($platform['steps']['p0']);
        }
    }

    public function testMissingValidationColumnFailsClosedInsteadOfAssumingNormal(): void
    {
        [$hotel, $sources, $rows, $tasks, $rawRecords, $bindings] = $this->fixture(['2026-07-22']);

        $result = DualOtaContinuousTrustService::evaluate(
            $hotel,
            '2026-07-22',
            '2026-07-22',
            $rows,
            $sources,
            $tasks,
            true,
            false,
            $rawRecords,
            $bindings
        );

        self::assertSame('partial', $result['status']);
        foreach ($result['days'][0]['platforms'] as $platform) {
            self::assertFalse($platform['steps']['date']);
            self::assertFalse($platform['steps']['field_facts']);
            self::assertFalse($platform['steps']['save']);
            self::assertFalse($platform['steps']['readback']);
            self::assertFalse($platform['steps']['page_status']);
            self::assertFalse($platform['steps']['p0']);
        }
    }

    public function testOlderSourceFailureCannotLabelAnotherDateCollectionFailed(): void
    {
        [$hotel, $sources, $rows, $tasks, $rawRecords, $bindings] = $this->fixture(['2026-07-22']);
        $rows = array_values(array_filter(
            $rows,
            static fn(array $row): bool => $row['platform'] !== 'ctrip'
        ));
        $tasks = array_values(array_filter(
            $tasks,
            static fn(array $task): bool => $task['platform'] !== 'ctrip'
        ));
        foreach ($sources as &$source) {
            if ($source['platform'] === 'ctrip') {
                $source['last_sync_status'] = 'collection_failed';
                $source['last_sync_time'] = '2026-07-21 23:59:59';
            }
        }
        unset($source);

        $result = DualOtaContinuousTrustService::evaluate(
            $hotel,
            '2026-07-22',
            '2026-07-22',
            $rows,
            $sources,
            $tasks,
            true,
            true,
            $rawRecords,
            $bindings
        );

        $ctrip = $this->platform($result['days'][0], 'ctrip');
        self::assertSame('partial', $ctrip['status']);
        self::assertNull($ctrip['failure_reason']);
    }

    public function testManualTaskCannotBorrowProfileSourceIdentityForP0(): void
    {
        [$hotel, $sources, $rows, $tasks, $rawRecords, $bindings] = $this->fixture(['2026-07-22']);
        foreach ($tasks as &$task) {
            if ($task['platform'] === 'ctrip') {
                $task['ingestion_method'] = 'manual';
            }
        }
        unset($task);

        $result = DualOtaContinuousTrustService::evaluate(
            $hotel,
            '2026-07-22',
            '2026-07-22',
            $rows,
            $sources,
            $tasks,
            true,
            true,
            $rawRecords,
            $bindings
        );

        $ctrip = $this->platform($result['days'][0], 'ctrip');
        self::assertSame('partial', $ctrip['status']);
        self::assertFalse($ctrip['steps']['p0']);
        self::assertSame('blocked', $ctrip['p0_status']);
    }

    public function testCloudBridgeKeepsProfileOriginAndRevalidatesDestinationReadback(): void
    {
        [$hotel, $sources, $rows, $tasks, $rawRecords, $bindings] = $this->fixture(['2026-07-22']);
        foreach ($sources as &$source) {
            $source['ingestion_method'] = 'manual';
        }
        unset($source);
        foreach ($rows as &$row) {
            $sourceRow = $row;
            $row['ingestion_method'] = 'cloud_bundle';
            $row['source_trace_id'] = 'bridge:' . hash('sha256', (string)$row['source_trace_id']);
            $row['raw_data'] = json_encode([
                'bundle_id' => 'bundle-fixture',
                'row' => $sourceRow,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        unset($row);
        foreach ($tasks as &$task) {
            $task['ingestion_method'] = 'cloud_bundle';
            $task['stats_json'] = [
                'target_date' => '2026-07-22',
                'collection_status' => 'success',
                'readback_verified' => true,
            ];
        }
        unset($task);

        $result = DualOtaContinuousTrustService::evaluate(
            $hotel,
            '2026-07-22',
            '2026-07-22',
            $rows,
            $sources,
            $tasks,
            true,
            true,
            $rawRecords,
            $bindings
        );

        self::assertSame('verified', $result['status']);
        self::assertSame(
            ['cloud_profile_bridge', 'cloud_profile_bridge'],
            array_column($result['days'][0]['platforms'], 'source_method')
        );
    }

    public function testProfileBindingMustBeActiveAndMatchExactTenantHotelPlatformHashWithoutLeak(): void
    {
        [$hotel, $sources, $rows, $tasks, $rawRecords, $bindings] = $this->fixture(['2026-07-22']);
        $ctripProfileHash = (string)$bindings[0]['profile_key_hash'];
        $bindings[0]['tenant_id'] = 77;

        $result = DualOtaContinuousTrustService::evaluate(
            $hotel,
            '2026-07-22',
            '2026-07-22',
            $rows,
            $sources,
            $tasks,
            true,
            true,
            $rawRecords,
            $bindings
        );

        $ctrip = $this->platform($result['days'][0], 'ctrip');
        self::assertSame('partial', $ctrip['status']);
        self::assertFalse($ctrip['steps']['account_profile_binding']);
        self::assertFalse($ctrip['steps']['conflict_recollect']);
        self::assertSame('conflict_recollect_required', $ctrip['conflict_recollect_status']);
        self::assertContains('account_profile_binding_scope_conflict', $ctrip['gap_codes']);
        self::assertStringNotContainsString(
            $ctripProfileHash,
            json_encode($result, JSON_THROW_ON_ERROR)
        );
    }

    public function testProfileBindingUsesTheSameCanonicalKeysAsTheAuthoritativeVerifier(): void
    {
        [$hotel, $sources, $rows, $tasks, $rawRecords, $bindings] = $this->fixture(['2026-07-22']);
        $sources[0]['config_json'] = json_encode([
            'profile_binding_key' => 'legacy-ctrip-alias',
            'stable_profile_id' => 'legacy-ctrip-stable-id',
            'profile_id' => 'ctrip-profile-58',
        ], JSON_THROW_ON_ERROR);
        $sources[1]['config_json'] = json_encode([
            'profile_binding_key' => 'legacy-meituan-alias',
            'stable_profile_id' => 'legacy-meituan-stable-id',
            'store_id' => 'meituan-store-58',
        ], JSON_THROW_ON_ERROR);

        $result = DualOtaContinuousTrustService::evaluate(
            $hotel,
            '2026-07-22',
            '2026-07-22',
            $rows,
            $sources,
            $tasks,
            true,
            true,
            $rawRecords,
            $bindings
        );

        self::assertSame('verified', $result['status']);
        self::assertSame(
            ['verified', 'verified'],
            array_column($result['days'][0]['platforms'], 'status')
        );
    }

    public function testRawSaveMustMatchExactSourceTaskHotelPlatformAndContainPayloadEvidence(): void
    {
        [$hotel, $sources, $rows, $tasks, $rawRecords, $bindings] = $this->fixture(['2026-07-22']);
        foreach ($rawRecords as &$record) {
            if ($record['platform'] === 'ctrip') {
                $record['system_hotel_id'] = 999;
            }
            if ($record['platform'] === 'meituan') {
                $record['raw_payload'] = '';
            }
        }
        unset($record);

        $result = DualOtaContinuousTrustService::evaluate(
            $hotel,
            '2026-07-22',
            '2026-07-22',
            $rows,
            $sources,
            $tasks,
            true,
            true,
            $rawRecords,
            $bindings
        );

        $ctrip = $this->platform($result['days'][0], 'ctrip');
        self::assertFalse($ctrip['steps']['raw_save']);
        self::assertTrue($ctrip['steps']['organized_save']);
        self::assertContains('raw_save_scope_conflict', $ctrip['gap_codes']);
        self::assertSame('conflict_recollect_required', $ctrip['conflict_recollect_status']);

        $meituan = $this->platform($result['days'][0], 'meituan');
        self::assertFalse($meituan['steps']['raw_save']);
        self::assertContains('raw_save_payload_incomplete', $meituan['gap_codes']);
        self::assertSame('recollect_required', $meituan['conflict_recollect_status']);
    }

    public function testOrganizedSaveCannotBorrowAnOlderTaskRow(): void
    {
        [$hotel, $sources, $rows, $tasks, $rawRecords, $bindings] = $this->fixture(['2026-07-22']);
        foreach ($rows as &$row) {
            if ($row['platform'] === 'ctrip') {
                $row['sync_task_id'] = 1999;
            }
        }
        unset($row);

        $result = DualOtaContinuousTrustService::evaluate(
            $hotel,
            '2026-07-22',
            '2026-07-22',
            $rows,
            $sources,
            $tasks,
            true,
            true,
            $rawRecords,
            $bindings
        );

        $ctrip = $this->platform($result['days'][0], 'ctrip');
        self::assertFalse($ctrip['steps']['date']);
        self::assertFalse($ctrip['steps']['organized_save']);
        self::assertFalse($ctrip['steps']['save']);
        self::assertContains('organized_save_scope_conflict', $ctrip['gap_codes']);
        self::assertSame('conflict_recollect_required', $ctrip['conflict_recollect_status']);
    }

    public function testSuccessfulRetryExposesRecollectedAndVerifiedState(): void
    {
        [$hotel, $sources, $rows, $tasks, $rawRecords, $bindings] = $this->fixture(['2026-07-22']);
        $tasks[] = [
            'id' => 1900,
            'tenant_id' => 9,
            'data_source_id' => 25,
            'system_hotel_id' => 58,
            'platform' => 'ctrip',
            'ingestion_method' => 'browser_profile',
            'status' => 'failed',
            'message' => 'prior_conflict_requires_recollect',
            'finished_at' => '2026-07-22 07:30:00',
            'stats_json' => [
                'sync_diagnostics' => [
                    'target_date' => '2026-07-22',
                    'p0_status' => 'blocked',
                ],
            ],
        ];

        $result = DualOtaContinuousTrustService::evaluate(
            $hotel,
            '2026-07-22',
            '2026-07-22',
            $rows,
            $sources,
            $tasks,
            true,
            true,
            $rawRecords,
            $bindings
        );

        $ctrip = $this->platform($result['days'][0], 'ctrip');
        self::assertSame('verified', $ctrip['status']);
        self::assertTrue($ctrip['steps']['conflict_recollect']);
        self::assertSame([], $ctrip['gap_codes']);
        self::assertSame('recollected_and_verified', $ctrip['conflict_recollect_status']);
    }

    public function testLegacyBooleanArgumentsRemainCallableButNewEvidenceFailsClosed(): void
    {
        [$hotel, $sources, $rows, $tasks] = $this->fixture(['2026-07-22']);

        $result = DualOtaContinuousTrustService::evaluate(
            $hotel,
            '2026-07-22',
            '2026-07-22',
            $rows,
            $sources,
            $tasks,
            true,
            true
        );

        self::assertSame('partial', $result['status']);
        foreach ($result['days'][0]['platforms'] as $platform) {
            self::assertFalse($platform['steps']['account_profile_binding']);
            self::assertFalse($platform['steps']['raw_save']);
            self::assertContains('account_profile_binding_missing', $platform['gap_codes']);
            self::assertContains('raw_save_missing', $platform['gap_codes']);
        }
    }

    public function testLocalCollectorProfileHashRawSaveReadbackAndP0CanOpenTheSameRuntimeGate(): void
    {
        [$hotel, $sources, $rows, $tasks, $rawRecords, $bindings] = $this->fixture(['2026-07-22']);
        $bindingByPlatform = [];
        foreach ($bindings as $binding) {
            $bindingByPlatform[$binding['platform']] = $binding;
        }
        foreach ($sources as &$source) {
            $platform = $source['platform'];
            $source['ingestion_method'] = 'local_collector';
            $source['config_json'] = json_encode([
                'local_collector_account_id' => $platform === 'ctrip' ? 701 : 702,
                'profile_key_hash' => $bindingByPlatform[$platform]['profile_key_hash'],
                'current_session_verified' => true,
            ], JSON_THROW_ON_ERROR);
        }
        unset($source);
        foreach ($rows as &$row) {
            $row['ingestion_method'] = 'local_collector';
        }
        unset($row);
        foreach ($tasks as &$task) {
            $task['ingestion_method'] = 'local_collector';
            $task['stats_json']['readback_verified'] = true;
            $task['stats_json']['run_readback']['readback_verified'] = true;
        }
        unset($task);
        foreach ($rawRecords as &$record) {
            $record['ingestion_method'] = 'local_collector';
        }
        unset($record);

        $result = DualOtaContinuousTrustService::evaluate(
            $hotel,
            '2026-07-22',
            '2026-07-22',
            $rows,
            $sources,
            $tasks,
            true,
            true,
            $rawRecords,
            $bindings
        );

        self::assertSame('verified', $result['status']);
        self::assertSame(
            ['local_account_profile', 'local_account_profile'],
            array_column($result['days'][0]['platforms'], 'source_method')
        );
        foreach ($result['days'][0]['platforms'] as $platform) {
            self::assertTrue($platform['steps']['account_profile_binding']);
            self::assertTrue($platform['steps']['raw_save']);
            self::assertTrue($platform['steps']['organized_save']);
            self::assertTrue($platform['steps']['readback']);
            self::assertTrue($platform['steps']['p0']);
        }
        self::assertStringNotContainsString(
            (string)$bindings[0]['profile_key_hash'],
            json_encode($result, JSON_THROW_ON_ERROR)
        );
    }

    public function testOrderOnlyNotRequiredTaskDoesNotDisplaceLatestTrafficVerifierTask(): void
    {
        [$hotel, $sources, $rows, $tasks, $rawRecords, $bindings] = $this->fixture(['2026-07-22']);
        $tasks[] = [
            'id' => 9999,
            'tenant_id' => 9,
            'data_source_id' => 25,
            'system_hotel_id' => 58,
            'platform' => 'ctrip',
            'ingestion_method' => 'local_collector',
            'status' => 'success',
            'finished_at' => '2026-07-22 09:00:00',
            'stats_json' => [
                'readback_verified' => true,
                'sync_diagnostics' => [
                    'target_date' => '2026-07-22',
                    'requires_target_date_traffic' => false,
                    'p0_status' => 'not_required',
                ],
            ],
        ];

        $result = DualOtaContinuousTrustService::evaluate(
            $hotel,
            '2026-07-22',
            '2026-07-22',
            $rows,
            $sources,
            $tasks,
            true,
            true,
            $rawRecords,
            $bindings
        );

        $ctrip = $this->platform($result['days'][0], 'ctrip');
        self::assertSame('verified', $ctrip['status']);
        self::assertNotSame(9999, $ctrip['sync_task_id']);
    }

    /**
     * @param array<int, string> $dates
     * @return array{
     *   0:array<string,mixed>,
     *   1:array<int,array<string,mixed>>,
     *   2:array<int,array<string,mixed>>,
     *   3:array<int,array<string,mixed>>,
     *   4:array<int,array<string,mixed>>,
     *   5:array<int,array<string,mixed>>
     * }
     */
    public function testVerifiedCoreBrowserSectionCanCloseTrustWhenOptionalSectionsRemain(): void
    {
        [$hotel, $sources, $rows, $tasks, $rawRecords, $bindings] = $this->fixture(['2026-07-22']);
        foreach ($tasks as &$task) {
            $task['status'] = 'partial_success';
            $task['stats_json']['sync_diagnostics']['p0_status'] = 'blocked';
            $task['stats_json']['run_readback']['readback_verified'] = true;
        }
        unset($task);

        $result = DualOtaContinuousTrustService::evaluate(
            $hotel,
            '2026-07-22',
            '2026-07-22',
            $rows,
            $sources,
            $tasks,
            true,
            true,
            $rawRecords,
            $bindings
        );

        self::assertSame('verified', $result['status']);
        foreach ($result['days'][0]['platforms'] as $platform) {
            self::assertTrue($platform['steps']['p0']);
            self::assertTrue($platform['steps']['readback']);
        }
    }

    private function fixture(array $dates): array
    {
        $hotel = ['id' => 58, 'tenant_id' => 9, 'name' => '连续可信测试门店'];
        $profileKeys = [
            'ctrip' => 'ctrip-profile-58',
            'meituan' => 'meituan-store-58',
        ];
        $sources = [
            [
                'id' => 25,
                'tenant_id' => 9,
                'system_hotel_id' => 58,
                'user_id' => 501,
                'created_by' => 501,
                'platform' => 'ctrip',
                'ingestion_method' => 'browser_profile',
                'enabled' => 1,
                'status' => 'ready',
                'last_sync_status' => 'success',
                'config_json' => json_encode(
                    ['profile_id' => $profileKeys['ctrip']],
                    JSON_THROW_ON_ERROR
                ),
            ],
            [
                'id' => 68,
                'tenant_id' => 9,
                'system_hotel_id' => 58,
                'user_id' => 502,
                'created_by' => 502,
                'platform' => 'meituan',
                'ingestion_method' => 'browser_profile',
                'enabled' => 1,
                'status' => 'ready',
                'last_sync_status' => 'success',
                'config_json' => json_encode(
                    ['store_id' => $profileKeys['meituan']],
                    JSON_THROW_ON_ERROR
                ),
            ],
        ];
        $bindings = [];
        foreach ($profileKeys as $platform => $profileKey) {
            $bindings[] = [
                'id' => count($bindings) + 1,
                'tenant_id' => 9,
                'system_hotel_id' => 58,
                'platform' => $platform,
                'profile_key_hash' => hash('sha256', $profileKey),
                'binding_status' => 'active',
                'bound_by' => $platform === 'ctrip' ? 501 : 502,
            ];
        }
        $rows = [];
        $tasks = [];
        $rawRecords = [];
        $rowId = 1000;
        $taskId = 2000;
        $rawId = 3000;
        foreach ($dates as $date) {
            foreach (['ctrip' => 25, 'meituan' => 68] as $platform => $sourceId) {
                $taskId++;
                $rowId++;
                $rawId++;
                $trace = $platform . ':' . $date . ':trace';
                $rows[] = $this->trafficRow($rowId, $taskId, $sourceId, $platform, $date, $trace);
                $tasks[] = [
                    'id' => $taskId,
                    'tenant_id' => 9,
                    'data_source_id' => $sourceId,
                    'system_hotel_id' => 58,
                    'platform' => $platform,
                    'ingestion_method' => 'browser_profile',
                    'status' => 'success',
                    'message' => 'profile_collection_saved_and_read_back',
                    'finished_at' => $date . ' 08:00:00',
                    'stats_json' => [
                        'sync_diagnostics' => [
                            'target_date' => $date,
                            'p0_status' => 'ready',
                        ],
                        'run_readback' => [
                            'target_date' => $date,
                            'readback_verified' => true,
                        ],
                    ],
                ];
                $rawRecords[] = [
                    'id' => $rawId,
                    'tenant_id' => 9,
                    'data_source_id' => $sourceId,
                    'sync_task_id' => $taskId,
                    'system_hotel_id' => 58,
                    'platform' => $platform,
                    'data_type' => 'traffic',
                    'ingestion_method' => 'browser_profile',
                    'payload_hash' => hash('sha256', $platform . ':' . $date . ':payload'),
                    'raw_payload' => json_encode(
                        ['fixture' => true, 'platform' => $platform, 'target_date' => $date],
                        JSON_THROW_ON_ERROR
                    ),
                    'received_at' => $date . ' 08:00:00',
                ];
            }
        }
        return [$hotel, $sources, $rows, $tasks, $rawRecords, $bindings];
    }

    /** @return array<string, mixed> */
    private function trafficRow(
        int $rowId,
        int $taskId,
        int $sourceId,
        string $platform,
        string $date,
        string $trace
    ): array {
        $metricKeys = $platform === 'ctrip'
            ? ['list_exposure', 'detail_exposure', 'flow_rate', 'order_filling_num', 'order_submit_num']
            : ['list_exposure', 'detail_exposure', 'flow_rate'];
        $urlHash = hash('sha256', 'https://example.invalid/' . $platform . '/' . $date);
        $facts = [];
        foreach ($metricKeys as $metricKey) {
            $facts[] = [
                'metric_key' => $metricKey,
                'source_path' => '$.metrics.' . $metricKey,
                'storage_field' => 'online_daily_data.' . $metricKey,
                'stored_value_present' => true,
                'status' => 'captured',
                'capture_evidence' => [
                    'source_trace_id' => $trace,
                    'source_url_hash' => $urlHash,
                ],
            ];
        }
        return [
            'id' => $rowId,
            'tenant_id' => 9,
            'system_hotel_id' => 58,
            'hotel_id' => 'platform-hotel-' . $platform,
            'data_source_id' => $sourceId,
            'sync_task_id' => $taskId,
            'source' => $platform,
            'platform' => $platform,
            'data_date' => $date,
            'data_type' => 'traffic',
            'dimension' => 'traffic_overview',
            'compare_type' => 'self',
            'ingestion_method' => 'browser_profile',
            'validation_status' => 'normal',
            'readback_verified' => 1,
            'source_trace_id' => $trace,
            'list_exposure' => 120,
            'detail_exposure' => 40,
            'flow_rate' => 0.33,
            'order_filling_num' => 9,
            'order_submit_num' => 4,
            'raw_data' => json_encode([
                'source_trace_id' => $trace,
                'capture_evidence' => [
                    'source_trace_id' => $trace,
                    'source_url_hash' => $urlHash,
                ],
                'platform_hotel_identifier_present' => true,
                'platform_hotel_identifier_source' => '$.hotel.id',
                'platform_hotel_identifier_proof' => 'matched_profile_hotel',
                'platform_hotel_binding_status' => 'matched',
                'platform_hotel_binding_proof' => 'tenant_hotel_binding_verified',
                'field_facts' => $facts,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /** @param array<string, mixed> $day @return array<string, mixed> */
    private function platform(array $day, string $platform): array
    {
        foreach ($day['platforms'] as $row) {
            if (($row['platform'] ?? '') === $platform) {
                return $row;
            }
        }
        self::fail('Platform result missing: ' . $platform);
    }
}
