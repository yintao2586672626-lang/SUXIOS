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
            'raw_data' => $this->encodeRaw($this->trustedRawData($fixtureRow)),
            'readback_verified' => 1,
        ]);

        $result = (new TrustedOtaFactRepository())->pricingHistory(80, '2026-07-01', '2026-07-01');

        self::assertSame('partial', $result['data_status']);
        self::assertCount(1, $result['rows']);
        self::assertNull($result['rows'][0]['amount']);
        self::assertNull($result['rows'][0]['quantity']);
        self::assertNull($result['rows'][0]['book_order_num']);
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
        $raw = [
            'data_type' => (string)($row['data_type'] ?? ''),
            'source_trace_id' => $traceId,
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
                'status' => 'captured',
                'stored_value_present' => true,
                'capture_evidence' => ['source_trace_id' => $traceId],
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
