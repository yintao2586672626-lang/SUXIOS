<?php
declare(strict_types=1);

namespace Tests;

use app\service\TrustedOtaFactRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class TrustedOtaFactRepositoryTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'trusted_ota_pricing_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';

        $config = self::$originalDatabaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$sqlitePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($config, 'database');
        Db::connect(null, true);
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Db::connect()->close();
        } catch (\Throwable) {
        }
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        if (is_file(self::$sqlitePath) && !unlink(self::$sqlitePath)) {
            throw new RuntimeException('Unable to remove trusted OTA pricing SQLite fixture.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->recreateTable(
            'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'system_hotel_id INTEGER NOT NULL, '
            . 'hotel_id TEXT NULL, '
            . 'data_date TEXT NOT NULL, '
            . 'amount REAL NULL, '
            . 'quantity REAL NULL, '
            . 'book_order_num REAL NULL, '
            . 'source TEXT NULL, '
            . 'platform TEXT NULL, '
            . 'data_type TEXT NULL, '
            . 'dimension TEXT NULL, '
            . 'compare_type TEXT NULL, '
            . 'validation_status TEXT NULL, '
            . 'validation_flags TEXT NULL, '
            . 'status TEXT NULL, '
            . 'save_status TEXT NULL, '
            . 'data_period TEXT NULL, '
            . 'snapshot_time TEXT NULL, '
            . 'snapshot_bucket TEXT NULL, '
            . 'is_final INTEGER NULL, '
            . 'update_time TEXT NULL, '
            . 'ingestion_method TEXT NULL, '
            . 'source_trace_id TEXT NULL, '
            . 'data_source_id INTEGER NULL, '
            . 'sync_task_id INTEGER NULL, '
            . 'raw_data TEXT NULL, '
            . 'readback_verified INTEGER NOT NULL DEFAULT 0'
        );
    }

    public function testReturnsOnlyCanonicalVerifiedSelfFactsForExactSystemHotel(): void
    {
        $this->insertRow(['amount' => 100, 'quantity' => 2, 'book_order_num' => 1]);
        $this->insertRow([
            'amount' => 999,
            'quantity' => 9,
            'book_order_num' => 9,
            'data_period' => 'realtime_snapshot',
            'is_final' => 0,
            'snapshot_time' => '2026-07-01 23:00:00',
        ]);
        $this->insertRow([
            'system_hotel_id' => 81,
            'hotel_id' => '80',
            'amount' => 810,
        ]);
        $this->insertRow(['readback_verified' => 0, 'amount' => 700]);
        $this->insertRow(['data_type' => 'traffic', 'amount' => 600]);
        $this->insertRow(['data_type' => 'competitor', 'amount' => 500]);
        $this->insertRow(['compare_type' => 'competitor_avg', 'amount' => 400]);
        $this->insertRow(['dimension' => 'peer_hotel', 'amount' => 300]);
        $this->insertRow(['validation_status' => 'abnormal', 'amount' => 200]);
        $this->insertRow([
            'compare_type' => 'self',
            'raw_data' => $this->encodeRaw($this->trustedRawData(
                $this->defaultRow(),
                ['compare_type' => 'competitor_avg']
            )),
            'amount' => 175,
        ]);
        $this->insertRow([
            'validation_status' => 'warning',
            'validation_flags' => '[{"code":"hotel_binding_mismatch"}]',
            'amount' => 150,
        ]);
        $this->insertRow([
            'source' => 'meituan_business',
            'platform' => 'meituan',
            'hotel_id' => 'mt-80',
            'ingestion_method' => 'profile_browser',
            'amount' => 50,
            'quantity' => 1,
            'book_order_num' => 1,
        ]);

        $result = (new TrustedOtaFactRepository())->pricingHistory(80, '2026-07-01', '2026-07-01');

        self::assertSame('ready', $result['data_status']);
        self::assertSame([100.0, 50.0], array_column($result['rows'], 'amount'));
        self::assertSame([2.0, 1.0], array_column($result['rows'], 'quantity'));
        self::assertSame(['ctrip', 'meituan'], array_column($result['rows'], 'source'));
        self::assertSame([1, 12], array_column($result['rows'], 'row_id'));
        self::assertSame([80, 80], array_column($result['rows'], 'system_hotel_id'));
        self::assertSame([true, true], array_column($result['rows'], 'readback_verified'));
        self::assertSame(
            ['trace-80', 'trace-80'],
            array_column($result['rows'], 'source_trace_id')
        );
        self::assertSame(2, $result['data_quality']['trusted_rows']);
        self::assertSame(1, $result['data_quality']['superseded_period_rows']);
        self::assertGreaterThanOrEqual(6, $result['data_quality']['rejected_rows']);
        self::assertSame('system_hotel_id_strict_exact_only', $result['source_policy']['hotel_scope']);
        self::assertSame('readback_verified_required_equals_1', $result['source_policy']['readback_policy']);
        self::assertSame('browser_profile_or_profile_browser_only', $result['source_policy']['ingestion_policy']);
        self::assertSame(
            'each_non_null_pricing_metric_requires_captured_field_fact',
            $result['source_policy']['metric_fact_policy']
        );
        self::assertSame('preserve_null_never_default_zero', $result['source_policy']['missing_metric_policy']);
        self::assertSame([], $result['data_gaps']);
    }

    public function testFailsClosedWhenSystemHotelScopeColumnIsMissing(): void
    {
        $this->recreateTable(
            'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'data_date TEXT NOT NULL, '
            . 'data_type TEXT NOT NULL, '
            . 'readback_verified INTEGER NOT NULL DEFAULT 0'
        );

        $result = (new TrustedOtaFactRepository())->pricingHistory(80, '2026-07-01', '2026-07-01');

        self::assertSame('blocked', $result['data_status']);
        self::assertSame([], $result['rows']);
        self::assertContains('pricing_history_system_hotel_scope_column_missing', $result['data_gaps']);
    }

    public function testPlatformHotelResolverAcceptsExactHotelIdButNeverProfileId(): void
    {
        Db::execute('DROP TABLE IF EXISTS platform_data_sources');
        Db::execute(
            'CREATE TABLE platform_data_sources ('
            . 'id INTEGER PRIMARY KEY, '
            . 'system_hotel_id INTEGER NOT NULL, '
            . 'platform TEXT NOT NULL, '
            . 'ingestion_method TEXT NOT NULL, '
            . 'enabled INTEGER NOT NULL, '
            . 'config_json TEXT NULL)'
        );
        try {
            $sourceRows = [
                [
                    'id' => 91,
                    'system_hotel_id' => 80,
                    'platform' => 'ctrip',
                    'ingestion_method' => 'browser_profile',
                    'enabled' => 1,
                    'config_json' => $this->encodeRaw([
                        'hotel_id' => '130079194',
                        'stable_profile_id' => 'ctrip-profile-80',
                    ]),
                ],
                [
                    'id' => 92,
                    'system_hotel_id' => 80,
                    'platform' => 'ctrip',
                    'ingestion_method' => 'browser_profile',
                    'enabled' => 1,
                    'config_json' => $this->encodeRaw([
                        'stable_profile_id' => '130079194',
                    ]),
                ],
            ];
            foreach ($sourceRows as $sourceRow) {
                Db::name('platform_data_sources')->insert($sourceRow);
            }
            $method = new \ReflectionMethod(
                TrustedOtaFactRepository::class,
                'platformHotelIdsBySource'
            );
            $method->setAccessible(true);
            $resolved = $method->invoke(
                new TrustedOtaFactRepository(),
                [
                    ['data_source_id' => 91],
                    ['data_source_id' => 92],
                ],
                80
            );

            self::assertSame('130079194', $resolved[91] ?? null);
            self::assertArrayNotHasKey(92, $resolved);
        } finally {
            Db::execute('DROP TABLE IF EXISTS platform_data_sources');
        }
    }

    public function testDailySummarySuppressesUnderlyingOrderRowsForTheSameSourceDate(): void
    {
        $this->insertRow([
            'amount' => 100,
            'quantity' => 2,
            'book_order_num' => 2,
            'data_type' => 'business',
        ]);
        $orderA = array_merge($this->defaultRow(), [
            'amount' => 60,
            'quantity' => 1,
            'book_order_num' => 1,
            'data_type' => 'order',
        ]);
        $this->insertRow([
            'amount' => 60,
            'quantity' => 1,
            'book_order_num' => 1,
            'data_type' => 'order',
            'raw_data' => $this->encodeRaw($this->trustedRawData($orderA, ['order_id_hash' => 'order-a'])),
        ]);
        $orderB = array_merge($this->defaultRow(), [
            'amount' => 40,
            'quantity' => 1,
            'book_order_num' => 1,
            'data_type' => 'order',
        ]);
        $this->insertRow([
            'amount' => 40,
            'quantity' => 1,
            'book_order_num' => 1,
            'data_type' => 'order',
            'raw_data' => $this->encodeRaw($this->trustedRawData($orderB, ['order_id_hash' => 'order-b'])),
        ]);

        $result = (new TrustedOtaFactRepository())->pricingHistory(80, '2026-07-01', '2026-07-01');

        self::assertCount(1, $result['rows']);
        self::assertSame(100.0, $result['rows'][0]['amount']);
        self::assertSame(2, $result['data_quality']['suppressed_mixed_type_rows']);
    }

    public function testTrustedOrderRowsDeduplicateOnlyOnTheExactFourPartIdentity(): void
    {
        $hashA = str_repeat('a', 64);
        $hashB = str_repeat('b', 64);
        $insertOrder = function (array $overrides, ?string $hash): void {
            $row = array_merge($this->defaultRow(), [
                'data_type' => 'order',
                'dimension' => 'order:confirmed',
            ], $overrides);
            $extra = $hash === null ? [] : ['order_id_hash' => $hash];
            $payload = array_merge([
                'data_type' => 'order',
                'dimension' => 'order:confirmed',
            ], $overrides);
            $payload['raw_data'] = $this->encodeRaw(
                $this->trustedRawData($row, $extra)
            );
            $this->insertRow($payload);
        };

        $insertOrder([
            'amount' => 100,
            'snapshot_time' => '2026-07-01 09:00:00',
            'update_time' => '2026-07-01 09:00:00',
        ], $hashA);
        $insertOrder([
            'amount' => 150,
            'snapshot_time' => '2026-07-01 10:00:00',
            'update_time' => '2026-07-01 10:00:00',
        ], $hashA);
        $insertOrder([
            'amount' => 80,
            'snapshot_time' => '2026-07-01 10:30:00',
            'update_time' => '2026-07-01 10:30:00',
        ], $hashB);
        $insertOrder([
            'amount' => 40,
            'snapshot_time' => '2026-07-01 11:00:00',
            'update_time' => '2026-07-01 11:00:00',
        ], null);
        $insertOrder([
            'amount' => 999,
            'data_type' => ' ORDER ',
            'snapshot_time' => '2026-07-01 12:00:00',
            'update_time' => '2026-07-01 12:00:00',
            'readback_verified' => 0,
        ], $hashA);

        $result = (new TrustedOtaFactRepository())->pricingHistory(
            80,
            '2026-07-01',
            '2026-07-01'
        );

        self::assertSame([150.0, 80.0, 40.0], array_column($result['rows'], 'amount'));
        $quality = $result['data_quality']['order_dedup'];
        self::assertSame('complete', $quality['evidence_status']);
        self::assertSame(5, $quality['order_identity_candidate_rows']);
        self::assertSame(3, $quality['order_identity_covered_rows']);
        self::assertSame(2, $quality['order_identity_unverifiable_rows']);
        self::assertSame(60.0, $quality['order_identity_coverage_percent']);
        self::assertSame(2, $quality['distinct_verified_order_grains']);
        self::assertSame(2, $quality['suppressed_duplicate_order_rows']);
        self::assertSame(1, $quality['suppressed_untrusted_duplicate_order_rows']);
        self::assertSame(1, $quality['newer_untrusted_duplicate_order_rows']);

        $repository = new TrustedOtaFactRepository();
        $method = new \ReflectionMethod($repository, 'deduplicateTrustedOrderRows');
        $sameHashRows = [[
            'id' => 10,
            'system_hotel_id' => 80,
            'platform' => 'ctrip',
            'source' => 'ctrip',
            'data_date' => '2026-07-01',
            'data_type' => 'order',
            'raw_data' => json_encode(['order_id_hash' => $hashA]),
        ], [
            'id' => 11,
            'system_hotel_id' => 81,
            'platform' => 'ctrip',
            'source' => 'ctrip',
            'data_date' => '2026-07-01',
            'data_type' => 'order',
            'raw_data' => json_encode(['order_id_hash' => $hashA]),
        ], [
            'id' => 12,
            'system_hotel_id' => 80,
            'platform' => 'meituan',
            'source' => 'meituan',
            'data_date' => '2026-07-01',
            'data_type' => 'order',
            'raw_data' => json_encode(['order_id_hash' => $hashA]),
        ], [
            'id' => 13,
            'system_hotel_id' => 80,
            'platform' => 'ctrip',
            'source' => 'ctrip',
            'data_date' => '2026-07-02',
            'data_type' => 'order',
            'raw_data' => json_encode(['order_id_hash' => $hashA]),
        ]];
        [$isolatedRows, $isolatedQuality] = $method->invoke(
            $repository,
            $sameHashRows
        );
        self::assertCount(4, $isolatedRows);
        self::assertSame(0, $isolatedQuality['suppressed_duplicate_order_rows']);
        self::assertSame(4, $isolatedQuality['distinct_verified_order_grains']);
    }

    public function testDailySummaryDimensionsNeverBlendAcrossSyncTasks(): void
    {
        $this->insertRow([
            'dimension' => 'room-a',
            'amount' => 100,
            'quantity' => 1,
            'book_order_num' => 1,
            'sync_task_id' => 1001,
            'snapshot_time' => '2026-07-01 10:00:00',
            'update_time' => '2026-07-01 10:00:00',
        ]);
        $this->insertRow([
            'dimension' => 'room-b',
            'amount' => 50,
            'quantity' => 1,
            'book_order_num' => 1,
            'sync_task_id' => 1001,
            'snapshot_time' => '2026-07-01 10:00:00',
            'update_time' => '2026-07-01 10:00:00',
        ]);
        $this->insertRow([
            'dimension' => 'room-a',
            'amount' => 120,
            'quantity' => 1,
            'book_order_num' => 1,
            'sync_task_id' => 1002,
            'snapshot_time' => '2026-07-01 11:00:00',
            'update_time' => '2026-07-01 11:00:00',
        ]);

        $result = (new TrustedOtaFactRepository())->pricingHistory(
            80,
            '2026-07-01',
            '2026-07-01'
        );

        self::assertSame('ready', $result['data_status']);
        self::assertCount(1, $result['rows']);
        self::assertSame(120.0, $result['rows'][0]['amount']);
        self::assertSame(1002, $result['rows'][0]['sync_task_id']);
        self::assertSame('business', $result['rows'][0]['data_type']);
        self::assertSame(1, $result['data_quality']['superseded_period_rows']);
        self::assertSame(1, $result['data_quality']['superseded_snapshot_rows']);
    }

    public function testCanonicalHistoryKeepsCtripAndQunarSeparateWhenStorageSourceMatches(): void
    {
        $rows = [[
            'id' => 1,
            'system_hotel_id' => 80,
            'hotel_id' => 'shared-ota-hotel',
            'data_date' => '2026-07-01',
            'source' => 'ctrip',
            'platform' => 'ctrip',
            'data_type' => 'business',
            'dimension' => '',
            'compare_type' => 'self',
            'data_period' => 'historical_daily',
            'is_final' => 1,
            'sync_task_id' => 1001,
            'snapshot_time' => '2026-07-01 10:00:00',
            'update_time' => '2026-07-01 10:00:00',
            'raw_data' => '{}',
        ], [
            'id' => 2,
            'system_hotel_id' => 80,
            'hotel_id' => 'shared-ota-hotel',
            'data_date' => '2026-07-01',
            'source' => 'ctrip',
            'platform' => 'qunar',
            'data_type' => 'business',
            'dimension' => '',
            'compare_type' => 'self',
            'data_period' => 'historical_daily',
            'is_final' => 1,
            'sync_task_id' => 1002,
            'snapshot_time' => '2026-07-01 11:00:00',
            'update_time' => '2026-07-01 11:00:00',
            'raw_data' => '{}',
        ]];

        $repository = new TrustedOtaFactRepository();
        $canonicalMethod = new \ReflectionMethod($repository, 'selectCanonicalRows');
        $summaryMethod = new \ReflectionMethod($repository, 'preferSummaryFactsPerSourceDate');
        $snapshotMethod = new \ReflectionMethod($repository, 'selectLatestSummarySnapshotRows');

        [$canonicalRows, $supersededPeriodRows] = $canonicalMethod->invoke($repository, $rows);
        [$summaryRows, $suppressedMixedTypeRows] = $summaryMethod->invoke($repository, $canonicalRows);
        [$snapshotRows, $supersededSnapshotRows] = $snapshotMethod->invoke($repository, $summaryRows);

        self::assertSame([1, 2], array_column($snapshotRows, 'id'));
        self::assertSame(['ctrip', 'qunar'], array_column($snapshotRows, 'platform'));
        self::assertSame(0, $supersededPeriodRows);
        self::assertSame(0, $suppressedMixedTypeRows);
        self::assertSame(0, $supersededSnapshotRows);
    }

    public function testFailsClosedWhenReadbackProofColumnIsMissing(): void
    {
        $this->recreateTable(
            'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'system_hotel_id INTEGER NOT NULL, '
            . 'data_date TEXT NOT NULL, '
            . 'data_type TEXT NOT NULL'
        );

        $result = (new TrustedOtaFactRepository())->pricingHistory(80, '2026-07-01', '2026-07-01');

        self::assertSame('blocked', $result['data_status']);
        self::assertSame([], $result['rows']);
        self::assertContains('pricing_history_readback_verified_column_missing', $result['data_gaps']);
    }

    public function testMissingMetricAndOptionalColumnsStayNullAndProduceExplicitGaps(): void
    {
        $this->recreateTable(
            'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'system_hotel_id INTEGER NOT NULL, '
            . 'data_date TEXT NOT NULL, '
            . 'source TEXT NULL, '
            . 'data_type TEXT NOT NULL, '
            . 'validation_status TEXT NOT NULL, '
            . 'validation_flags TEXT NOT NULL, '
            . 'ingestion_method TEXT NOT NULL, '
            . 'source_trace_id TEXT NOT NULL, '
            . 'data_source_id INTEGER NOT NULL, '
            . 'sync_task_id INTEGER NOT NULL, '
            . 'raw_data TEXT NOT NULL, '
            . 'readback_verified INTEGER NOT NULL DEFAULT 0'
        );
        $fixtureRow = [
            'data_type' => 'business',
            'source_trace_id' => 'trace-metrics-missing',
        ];
        Db::name('online_daily_data')->insert([
            'system_hotel_id' => 80,
            'data_date' => '2026-07-01',
            'source' => 'ctrip',
            'data_type' => 'business',
            'validation_status' => 'normal',
            'validation_flags' => '',
            'ingestion_method' => 'browser_profile',
            'source_trace_id' => 'trace-metrics-missing',
            'data_source_id' => 18,
            'sync_task_id' => 1000,
            'raw_data' => $this->encodeRaw($this->trustedRawData($fixtureRow)),
            'readback_verified' => 1,
        ]);

        $result = (new TrustedOtaFactRepository())->pricingHistory(80, '2026-07-01', '2026-07-01');

        self::assertSame('partial', $result['data_status']);
        self::assertCount(1, $result['rows']);
        self::assertNull($result['rows'][0]['amount']);
        self::assertNull($result['rows'][0]['quantity']);
        self::assertNull($result['rows'][0]['book_order_num']);
        self::assertSame('ctrip', $result['rows'][0]['source']);
        self::assertContains('pricing_history_amount_column_missing', $result['data_gaps']);
        self::assertContains('pricing_history_quantity_column_missing', $result['data_gaps']);
        self::assertContains('pricing_history_book_order_num_column_missing', $result['data_gaps']);
        self::assertContains('pricing_history_period_evidence_columns_missing', $result['data_gaps']);
    }

    public function testRejectsNonProfileIngestionAndEveryNonAllowlistedValidationStatus(): void
    {
        $this->insertRow(['source_trace_id' => 'trace-trusted', 'amount' => 100]);
        $this->insertRow(['source_trace_id' => 'trace-manual', 'ingestion_method' => 'manual', 'amount' => 200]);
        $this->insertRow(['source_trace_id' => 'trace-partial', 'validation_status' => 'partial', 'amount' => 300]);
        $this->insertRow(['source_trace_id' => 'trace-quarantined', 'validation_status' => 'quarantined', 'amount' => 400]);
        $this->insertRow(['source_trace_id' => 'trace-unverified', 'validation_status' => 'unverified', 'amount' => 500]);

        $result = (new TrustedOtaFactRepository())->pricingHistory(80, '2026-07-01', '2026-07-01');

        self::assertSame('ready', $result['data_status']);
        self::assertSame([100.0], array_column($result['rows'], 'amount'));
        self::assertSame(1, $result['data_quality']['rejected_reasons']['ingestion_method_untrusted'] ?? 0);
        self::assertSame(3, $result['data_quality']['rejected_reasons']['validation_status_untrusted'] ?? 0);
    }

    public function testEstablishedTrustedValidationAliasesRemainCompatibleForProfileRows(): void
    {
        $service = new TrustedOtaFactRepository();
        $method = new \ReflectionMethod($service, 'rejectionReason');
        $method->setAccessible(true);

        foreach (['normal', 'available', 'verified', 'valid', 'confirmed', 'approved', 'passed', 'ok', 'success', 'complete', 'completed'] as $status) {
            $row = array_merge($this->defaultRow(), [
                'validation_status' => $status,
                'source_trace_id' => 'trace-' . $status,
            ]);
            $row['raw_data'] = $this->encodeRaw($this->trustedRawData($row));
            self::assertSame('', $method->invoke($service, $row), $status);
        }
    }

    public function testDomOnlyProfileRowCannotBecomeATrustedOtaFact(): void
    {
        $service = new TrustedOtaFactRepository();
        $method = new \ReflectionMethod($service, 'rejectionReason');
        $method->setAccessible(true);
        $row = $this->defaultRow();
        $raw = $this->trustedRawData($row);
        $raw['row']['_capture_source'] = 'dom:traffic:home_summary';
        $raw['row']['_source_path'] = 'dom.traffic.home_summary';
        $raw['row']['capture_evidence']['capture_source']
            = 'dom:traffic:home_summary';
        $raw['row']['capture_evidence']['source_path']
            = 'dom.traffic.home_summary';
        $row['raw_data'] = $this->encodeRaw($raw);

        self::assertSame(
            'structured_capture_evidence_dom_only',
            $method->invoke($service, $row)
        );
    }

    public function testRejectsMissingTraceBindingAndEachUnprovenMetricFact(): void
    {
        $this->insertRow(['source_trace_id' => 'trace-trusted', 'amount' => 100]);
        $this->insertRow(['source_trace_id' => '', 'amount' => 200]);

        $bindingRow = array_merge($this->defaultRow(), ['source_trace_id' => 'trace-binding']);
        $bindingRaw = $this->trustedRawData($bindingRow);
        unset(
            $bindingRaw['platform_hotel_identifier_present'],
            $bindingRaw['platform_hotel_identifier_source'],
            $bindingRaw['platform_hotel_identifier_proof']
        );
        $this->insertRow([
            'source_trace_id' => 'trace-binding',
            'amount' => 210,
            'raw_data' => $this->encodeRaw($bindingRaw),
        ]);

        $metricCases = [
            ['order_amount', [], 'trace-no-amount-fact'],
            ['room_nights', ['amount' => null], 'trace-no-room-fact'],
            ['order_count', ['amount' => null, 'quantity' => null], 'trace-no-order-fact'],
        ];
        foreach ($metricCases as [$metricKey, $overrides, $traceId]) {
            $row = array_merge($this->defaultRow(), $overrides, ['source_trace_id' => $traceId]);
            $raw = $this->trustedRawData($row);
            $raw['field_facts'] = array_values(array_filter(
                $raw['field_facts'],
                static fn(array $fact): bool => ($fact['metric_key'] ?? '') !== $metricKey
            ));
            $this->insertRow(array_merge($overrides, [
                'source_trace_id' => $traceId,
                'raw_data' => $this->encodeRaw($raw),
            ]));
        }

        $traceRow = array_merge($this->defaultRow(), [
            'source_trace_id' => 'trace-field-fact',
            'quantity' => null,
            'book_order_num' => null,
        ]);
        $traceRaw = $this->trustedRawData($traceRow);
        $traceRaw['field_facts'][0]['capture_evidence']['source_trace_id'] = 'trace-other-row';
        $this->insertRow([
            'source_trace_id' => 'trace-field-fact',
            'quantity' => null,
            'book_order_num' => null,
            'raw_data' => $this->encodeRaw($traceRaw),
        ]);

        $result = (new TrustedOtaFactRepository())->pricingHistory(80, '2026-07-01', '2026-07-01');
        $reasons = $result['data_quality']['rejected_reasons'];

        self::assertSame([100.0], array_column($result['rows'], 'amount'));
        self::assertSame(1, $reasons['source_trace_id_missing'] ?? 0);
        self::assertSame(1, $reasons['raw_hotel_binding_evidence_missing'] ?? 0);
        self::assertSame(1, $reasons['field_fact_missing_order_amount'] ?? 0);
        self::assertSame(1, $reasons['field_fact_missing_room_nights'] ?? 0);
        self::assertSame(1, $reasons['field_fact_missing_order_count'] ?? 0);
        self::assertSame(1, $reasons['field_fact_trace_mismatch_order_amount'] ?? 0);
    }

    public function testFailsClosedWithoutAnyDataTypeEvidence(): void
    {
        $this->recreateTable(
            'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'system_hotel_id INTEGER NOT NULL, '
            . 'data_date TEXT NOT NULL, '
            . 'amount REAL NULL, '
            . 'quantity REAL NULL, '
            . 'readback_verified INTEGER NOT NULL DEFAULT 0'
        );

        $result = (new TrustedOtaFactRepository())->pricingHistory(80, '2026-07-01', '2026-07-01');

        self::assertSame('blocked', $result['data_status']);
        self::assertContains('pricing_history_data_type_evidence_missing', $result['data_gaps']);
    }

    /** @param array<string, mixed> $overrides */
    private function insertRow(array $overrides = []): void
    {
        $row = array_merge($this->defaultRow(), $overrides);
        if (!array_key_exists('raw_data', $overrides)) {
            $row['raw_data'] = $this->encodeRaw($this->trustedRawData($row));
        }
        Db::name('online_daily_data')->insert($row);
    }

    /** @return array<string, mixed> */
    private function defaultRow(): array
    {
        return [
            'system_hotel_id' => 80,
            'hotel_id' => 'ctrip-80',
            'data_date' => '2026-07-01',
            'amount' => 120,
            'quantity' => 2,
            'book_order_num' => 1,
            'source' => 'ctrip_business',
            'platform' => 'ctrip',
            'data_type' => 'business',
            'dimension' => '',
            'compare_type' => 'self',
            'validation_status' => 'normal',
            'validation_flags' => '[]',
            'status' => 'success',
            'save_status' => 'success',
            'data_period' => 'historical_daily',
            'snapshot_time' => '2026-07-02 01:00:00',
            'snapshot_bucket' => '2026-07-01',
            'is_final' => 1,
            'update_time' => '2026-07-02 01:00:00',
            'ingestion_method' => 'browser_profile',
            'source_trace_id' => 'trace-80',
            'data_source_id' => 18,
            'sync_task_id' => 1000,
            'readback_verified' => 1,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function trustedRawData(array $row, array $extra = []): array
    {
        $traceId = trim((string)($row['source_trace_id'] ?? ''));
        $urlHash = hash('sha256', 'trusted-fixture:' . $traceId);
        $platform = strtolower(trim((string)($row['platform'] ?? 'ctrip')));
        $captureSource = $platform === 'meituan'
            ? 'xhr:traffic:business_data'
            : 'xhr:business';
        $sourcePath = 'data';
        $raw = [
            'data_type' => (string)($row['data_type'] ?? ''),
            'source_trace_id' => $traceId,
            'source_url_hash' => $urlHash,
            'capture_evidence' => [
                'source_trace_id' => $traceId,
                'source_url_hash' => $urlHash,
            ],
            'row' => [
                '_capture_source' => $captureSource,
                '_source_path' => $sourcePath,
                'capture_evidence' => [
                    'capture_source' => $captureSource,
                    'source_path' => $sourcePath,
                    'source_trace_id' => $traceId,
                    'source_url_hash' => $urlHash,
                ],
            ],
            'platform_hotel_identifier_present' => true,
            'platform_hotel_identifier_source' => ($row['platform'] ?? 'ctrip') === 'meituan'
                ? 'poi_id_family'
                : 'hotel_id_family',
            'platform_hotel_identifier_proof' => 'row_field_present',
            'field_facts' => [],
        ];
        foreach ([
            'amount' => 'order_amount',
            'quantity' => 'room_nights',
            'book_order_num' => 'order_count',
        ] as $field => $metricKey) {
            if (!array_key_exists($field, $row) || $row[$field] === null || trim((string)$row[$field]) === '') {
                continue;
            }
            $raw['field_facts'][] = [
                'metric_key' => $metricKey,
                'normalized_field' => $field,
                'storage_field' => 'online_daily_data.' . $field,
                'source_path' => $sourcePath . '.' . $field,
                'status' => 'captured',
                'stored_value_present' => true,
                'capture_evidence' => [
                    'capture_source' => $captureSource,
                    'source_path' => $sourcePath,
                    'source_trace_id' => $traceId,
                    'source_url_hash' => $urlHash,
                ],
            ];
        }

        return array_replace($raw, $extra);
    }

    /** @param array<string, mixed> $raw */
    private function encodeRaw(array $raw): string
    {
        return (string)json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function recreateTable(string $columns): void
    {
        Db::execute('DROP TABLE IF EXISTS online_daily_data');
        Db::execute('CREATE TABLE online_daily_data (' . $columns . ')');
    }
}
