<?php
declare(strict_types=1);

namespace Tests;

use app\service\RevenueAiOverviewService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use think\App;
use think\facade\Config;
use think\facade\Db;

/** Use the real ORM against a disposable fixture; translate schema inspection only. */
final class ManualOrderReadbackSqlite extends \think\db\connector\Sqlite
{
    public function query(string $sql, array $bind = [], bool $master = false): array
    {
        if (preg_match("/^SHOW TABLES LIKE '([a-z_]+)'$/i", $sql, $match)) {
            return parent::query("SELECT name FROM sqlite_master WHERE type='table' AND name=?", [$match[1]], $master);
        }
        if (preg_match('/^SHOW COLUMNS FROM `([a-z_]+)`$/i', $sql, $match)) {
            return array_map(static fn(array $row): array => ['Field' => $row['name']],
                parent::query('PRAGMA table_info(' . $match[1] . ')', [], $master));
        }
        return parent::query($sql, $bind, $master);
    }
}

final class RevenueAiManualOrderReadbackCompletenessTest extends TestCase
{
    private array $originalConfig;
    private string $fixturePath;

    public static function setUpBeforeClass(): void
    {
        \Tests\Support\IsolatedSqliteAppFixture::create('manual_order_readback_app_');
    }

    protected function setUp(): void
    {
        $this->originalConfig = Config::get('database');
        $this->fixturePath = sys_get_temp_dir() . '/suxi-manual-order-readback-' . bin2hex(random_bytes(6)) . '.sqlite';
        Config::set(['default' => 'manual_order_readback_fixture', 'connections' => [
            'manual_order_readback_fixture' => [
                'type' => ManualOrderReadbackSqlite::class,
                'database' => $this->fixturePath,
                'builder' => \think\db\builder\Sqlite::class,
                'prefix' => '', 'fields_strict' => false, 'debug' => false,
            ],
        ]], 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE online_daily_data (id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'system_hotel_id INTEGER, source TEXT, data_date TEXT, data_type TEXT, ingestion_method TEXT, '
            . 'validation_status TEXT, readback_verified INTEGER, source_trace_id TEXT, raw_data TEXT)');
    }

    protected function tearDown(): void
    {
        Db::connect()->close();
        Config::set($this->originalConfig, 'database');
        Db::connect(null, true);
        @unlink($this->fixturePath);
    }

    public function testMissingManualMetricsStayUnknownAfterExactDatabaseReadback(): void
    {
        $this->insertAggregate(['book_order_num' => 3, 'cancel_order_num' => 0, 'quantity' => 4]);
        $this->insertAggregate(['cancel_order_num' => 0]);
        $summary = $this->readSummary();

        self::assertSame('available_unverified', $summary['status']);
        self::assertCount(2, $summary['rows']);
        self::assertNull($summary['rows'][1]['active_orders']);
        self::assertNull($summary['rows'][1]['room_nights']);
        self::assertNull($summary['summary']['active_orders'], 'An incomplete channel must not become an exact known total.');
        self::assertNull($summary['summary']['room_nights']);
        self::assertSame(0.0, $summary['summary']['cancelled_orders']);
        self::assertTrue($summary['summary']['readback_verified']);
        self::assertSame('user_provided_unverified', $summary['quality_status']);
    }

    public function testExplicitZeroAndHotelPlatformDateReadbackBoundariesAreRetained(): void
    {
        $zero = ['book_order_num' => 0, 'cancel_order_num' => 0, 'quantity' => 0];
        $this->insertAggregate($zero);
        $other = ['book_order_num' => 999, 'cancel_order_num' => 999, 'quantity' => 999];
        $this->insertAggregate($other, ['system_hotel_id' => 81]);
        $this->insertAggregate($other, ['source' => 'meituan']);
        $this->insertAggregate($other, ['data_date' => '2026-09-30']);
        $this->insertAggregate($other, ['readback_verified' => 0]);
        $summary = $this->readSummary();

        self::assertCount(1, $summary['rows']);
        self::assertSame(1, $summary['summary']['row_count']);
        foreach (['active_orders', 'cancelled_orders', 'room_nights'] as $key) {
            self::assertSame(0.0, $summary['summary'][$key]);
        }
        self::assertSame(80, $summary['hotel_id']);
        self::assertSame('2026-10-01', $summary['business_date']);
    }

    private function readSummary(): array
    {
        return (new ReflectionMethod(RevenueAiOverviewService::class, 'manualOrderImportSummary'))
            ->invoke(new RevenueAiOverviewService(), '2026-10-01', 80);
    }

    private function insertAggregate(array $metrics, array $overrides = []): void
    {
        $detail = [
            'import_contract' => 'ctrip_order_aggregate_v1',
            'amount_semantics' => 'reference_bottom_price_not_confirmed_revenue',
            'pii_policy' => 'aggregate_only_no_guest_staff_reservation_notes',
            'channel_key' => 'ctrip', 'fixture_status' => 'explicit_test_fixture',
        ];
        Db::name('online_daily_data')->insert(array_replace([
            'system_hotel_id' => 80, 'source' => 'ctrip', 'data_date' => '2026-10-01',
            'data_type' => 'order', 'ingestion_method' => 'import_csv',
            'validation_status' => 'user_provided_unverified', 'readback_verified' => 1,
            'source_trace_id' => 'isolated_fixture',
            'raw_data' => json_encode(['row' => array_merge([
                'platform' => 'ctrip', 'source' => 'ctrip', 'raw_data' => $detail,
            ], $metrics)], JSON_THROW_ON_ERROR),
        ], $overrides));
    }
}
