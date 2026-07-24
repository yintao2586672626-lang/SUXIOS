<?php
declare(strict_types=1);

namespace Tests;

use app\service\DualOtaContinuousTrustService;
use PHPUnit\Framework\TestCase;

final class DualOtaContinuousTrustServiceTest extends TestCase
{
    public function testBothPlatformsMustCloseEveryStepForConsecutiveVerifiedDays(): void
    {
        [$hotel, $sources, $rows, $tasks] = $this->fixture(['2026-07-21', '2026-07-22']);

        $result = DualOtaContinuousTrustService::evaluate(
            $hotel,
            '2026-07-21',
            '2026-07-22',
            $rows,
            $sources,
            $tasks,
            true
        );

        self::assertSame('verified', $result['status']);
        self::assertSame(2, $result['verified_days']);
        self::assertSame(2, $result['consecutive_verified_days']);
        self::assertSame(['verified', 'verified'], array_column($result['days'], 'status'));
        self::assertSame(
            ['source', 'hotel', 'date', 'field_facts', 'save', 'readback', 'page_status', 'p0'],
            $result['required_steps']
        );
    }

    public function testOlderReadyRowCannotReplaceLatestDateMissingFieldFact(): void
    {
        [$hotel, $sources, $rows, $tasks] = $this->fixture(['2026-07-21', '2026-07-22']);
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
            true
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
        [$hotel, $sources, $rows, $tasks] = $this->fixture(['2026-07-22']);
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
            true
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
        [$hotel, $sources, $rows, $tasks] = $this->fixture(['2026-07-22']);

        $result = DualOtaContinuousTrustService::evaluate(
            $hotel,
            '2026-07-22',
            '2026-07-22',
            $rows,
            $sources,
            $tasks,
            false
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
        [$hotel, $sources, $rows, $tasks] = $this->fixture(['2026-07-22']);

        $result = DualOtaContinuousTrustService::evaluate(
            $hotel,
            '2026-07-22',
            '2026-07-22',
            $rows,
            $sources,
            $tasks,
            true,
            false
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
        [$hotel, $sources, $rows, $tasks] = $this->fixture(['2026-07-22']);
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
            true
        );

        $ctrip = $this->platform($result['days'][0], 'ctrip');
        self::assertSame('partial', $ctrip['status']);
        self::assertNull($ctrip['failure_reason']);
    }

    public function testManualTaskCannotBorrowProfileSourceIdentityForP0(): void
    {
        [$hotel, $sources, $rows, $tasks] = $this->fixture(['2026-07-22']);
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
            true
        );

        $ctrip = $this->platform($result['days'][0], 'ctrip');
        self::assertSame('partial', $ctrip['status']);
        self::assertFalse($ctrip['steps']['p0']);
        self::assertSame('blocked', $ctrip['p0_status']);
    }

    public function testCloudBridgeKeepsProfileOriginAndRevalidatesDestinationReadback(): void
    {
        [$hotel, $sources, $rows, $tasks] = $this->fixture(['2026-07-22']);
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
            true
        );

        self::assertSame('verified', $result['status']);
        self::assertSame(
            ['cloud_profile_bridge', 'cloud_profile_bridge'],
            array_column($result['days'][0]['platforms'], 'source_method')
        );
    }

    /**
     * @param array<int, string> $dates
     * @return array{0:array<string,mixed>,1:array<int,array<string,mixed>>,2:array<int,array<string,mixed>>,3:array<int,array<string,mixed>>}
     */
    private function fixture(array $dates): array
    {
        $hotel = ['id' => 58, 'tenant_id' => 9, 'name' => '连续可信测试门店'];
        $sources = [
            [
                'id' => 25,
                'tenant_id' => 9,
                'system_hotel_id' => 58,
                'platform' => 'ctrip',
                'ingestion_method' => 'browser_profile',
                'enabled' => 1,
                'status' => 'ready',
                'last_sync_status' => 'success',
            ],
            [
                'id' => 68,
                'tenant_id' => 9,
                'system_hotel_id' => 58,
                'platform' => 'meituan',
                'ingestion_method' => 'browser_profile',
                'enabled' => 1,
                'status' => 'ready',
                'last_sync_status' => 'success',
            ],
        ];
        $rows = [];
        $tasks = [];
        $rowId = 1000;
        $taskId = 2000;
        foreach ($dates as $date) {
            foreach (['ctrip' => 25, 'meituan' => 68] as $platform => $sourceId) {
                $taskId++;
                $rowId++;
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
            }
        }
        return [$hotel, $sources, $rows, $tasks];
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
