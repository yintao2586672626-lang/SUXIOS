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
            'raw_data' => '{"data_type":"business","compare_type":"competitor_avg"}',
            'amount' => 175,
        ]);
        $this->insertRow([
            'validation_status' => 'warning',
            'validation_flags' => '[{"code":"hotel_binding_mismatch"}]',
            'amount' => 150,
        ]);
        $this->insertRow([
            'source' => 'meituan_business',
            'hotel_id' => 'mt-80',
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
            'raw_data' => '{"data_type":"business"}',
        ]);
        $this->insertRow([
            'amount' => 60,
            'quantity' => 1,
            'book_order_num' => 1,
            'data_type' => 'order',
            'raw_data' => '{"data_type":"order","order_id_hash":"order-a"}',
        ]);
        $this->insertRow([
            'amount' => 40,
            'quantity' => 1,
            'book_order_num' => 1,
            'data_type' => 'order',
            'raw_data' => '{"data_type":"order","order_id_hash":"order-b"}',
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
            . 'readback_verified INTEGER NOT NULL DEFAULT 0'
        );
        Db::name('online_daily_data')->insert([
            'system_hotel_id' => 80,
            'data_date' => '2026-07-01',
            'source' => 'ctrip',
            'data_type' => 'business',
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
        self::assertContains('pricing_history_validation_status_column_missing', $result['data_gaps']);
        self::assertContains('pricing_history_period_evidence_columns_missing', $result['data_gaps']);
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
        Db::name('online_daily_data')->insert(array_merge([
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
            'source_trace_id' => 'trace-80',
            'raw_data' => '{"data_type":"business"}',
            'readback_verified' => 1,
        ], $overrides));
    }

    private function recreateTable(string $columns): void
    {
        Db::execute('DROP TABLE IF EXISTS online_daily_data');
        Db::execute('CREATE TABLE online_daily_data (' . $columns . ')');
    }
}
