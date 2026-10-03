<?php
declare(strict_types=1);

namespace Tests;

use app\service\CanonicalOtaHistoryReceiptVerifier;
use app\service\RevenueAiOverviewService;
use app\service\RevenuePricingRecommendationService;
use app\service\StrictCtripTrafficHistoryReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

/** Translate schema inspection only; preflight and strict history queries use the real ORM. */
final class RevenuePreflightScopeSqlite extends \think\db\connector\Sqlite
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

final class RevenueAiPricingPreflightChannelScopeTest extends TestCase
{
    private array $originalConfig;
    private string $fixturePath;
    private const TARGET_DATE = '2026-06-15';

    public static function setUpBeforeClass(): void
    {
        (new App(dirname(__DIR__)))->initialize();
    }

    protected function setUp(): void
    {
        $this->originalConfig = Config::get('database');
        $this->fixturePath = sys_get_temp_dir() . '/suxi-preflight-scope-' . bin2hex(random_bytes(6)) . '.sqlite';
        Config::set(['default' => 'preflight_fixture', 'connections' => ['preflight_fixture' => [
            'type' => RevenuePreflightScopeSqlite::class, 'database' => $this->fixturePath,
            'builder' => \think\db\builder\Sqlite::class,
            'prefix' => '', 'fields_strict' => false, 'debug' => false,
        ]]], 'database');
        Db::connect(null, true);
        foreach ([
            'CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)',
            'CREATE TABLE room_types (id INTEGER PRIMARY KEY, hotel_id INTEGER, name TEXT, base_price REAL, '
                . 'min_price REAL, max_price REAL, room_count INTEGER, is_enabled INTEGER, sort_order INTEGER)',
            'CREATE TABLE price_suggestions (id INTEGER PRIMARY KEY, hotel_id INTEGER, suggestion_date TEXT, status INTEGER)',
            'CREATE TABLE demand_forecasts (id INTEGER PRIMARY KEY, hotel_id INTEGER, room_type_id INTEGER, forecast_date TEXT, '
                . 'historical_data TEXT, update_time TEXT, create_time TEXT)',
            'CREATE TABLE competitor_analysis (id INTEGER PRIMARY KEY, hotel_id INTEGER, room_type_id INTEGER, '
                . 'ota_platform TEXT, analysis_date TEXT, competitor_price REAL)',
            'CREATE TABLE online_daily_data (id INTEGER PRIMARY KEY, tenant_id INTEGER, system_hotel_id INTEGER, '
                . 'hotel_id TEXT, data_source_id INTEGER, sync_task_id INTEGER, source TEXT, platform TEXT, data_date TEXT, '
                . 'data_period TEXT, data_type TEXT, dimension TEXT, compare_type TEXT, readback_verified INTEGER, '
                . 'history_status TEXT, validation_status TEXT, ingestion_method TEXT, source_trace_id TEXT, '
                . 'snapshot_time TEXT, raw_data TEXT, list_exposure REAL, detail_exposure REAL, flow_rate REAL, '
                . 'order_filling_num REAL, order_submit_num REAL, book_order_num REAL, quantity REAL)',
        ] as $sql) {
            Db::execute($sql);
        }
        Db::name('hotels')->insert(['id' => 80, 'tenant_id' => 1]);
        Db::name('room_types')->insert([
            'id' => 1, 'hotel_id' => 80, 'name' => '隔离测试房型', 'base_price' => 200,
            'min_price' => 160, 'max_price' => 250, 'room_count' => 20, 'is_enabled' => 1, 'sort_order' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        Db::connect()->close();
        Config::set($this->originalConfig, 'database');
        Db::connect(null, true);
        if (is_file($this->fixturePath) && !unlink($this->fixturePath)) {
            throw new RuntimeException('Unable to remove preflight SQLite fixture.');
        }
    }

    public function testDualChannelObservationUsesTheSameStrictCtripForecastAsSingleChannel(): void
    {
        $this->insertHistory();
        $single = $this->preflight(['ctrip']);
        $dual = $this->preflight(['ctrip', 'meituan']);
        $reversed = $this->preflight(['meituan', 'ctrip']);
        foreach ([$single, $dual, $reversed] as $result) {
            self::assertSame(0, $result['demand_forecast_count']);
            self::assertSame(1, $result['ctrip_traffic_demand_forecast_count']);
            self::assertSame('ctrip_historical_traffic_trend', $result['hotel_checks'][0]['demand_forecast_source']);
            self::assertSame('ok', $result['hotel_checks'][0]['ctrip_traffic_demand_forecast_status']);
            self::assertNotContains('demand_forecast', array_column($result['required_inputs'], 'code'));
            self::assertFalse($result['auto_write_ota']);
            self::assertTrue($result['read_only']);
            self::assertTrue($result['manual_review_required']);
        }
        self::assertSame(['ctrip', 'meituan'], $dual['source_channels']);
        self::assertSame('ota_channel', $dual['source_scope']);
        self::assertSame(0, Db::name('price_suggestions')->count());
        self::assertSame(0, Db::name('demand_forecasts')->count());
        self::assertSame(3, Db::name('online_daily_data')->count());
    }

    #[DataProvider('invalidHistory')]
    public function testOutOfScopeOrUnverifiedHistoryCannotFillTheForecastGap(array $changes): void
    {
        $this->insertHistory($changes);
        foreach ([['ctrip'], ['ctrip', 'meituan']] as $channels) {
            $result = $this->preflight($channels);
            self::assertSame(0, $result['ctrip_traffic_demand_forecast_count']);
            self::assertSame('missing', $result['hotel_checks'][0]['demand_forecast_source']);
            self::assertContains('demand_forecast', array_column($result['required_inputs'], 'code'));
            self::assertFalse($result['can_generate_pending_suggestions']);
            self::assertFalse($result['auto_write_ota']);
        }
    }

    public static function invalidHistory(): array
    {
        return [
            'other platform' => [['platform' => 'meituan']],
            'other hotel' => [['system_hotel_id' => 81]],
            'other tenant' => [['tenant_id' => 2]],
            'outside history window' => [['data_date' => '2026-01-01']],
            'not readback verified' => [['readback_verified' => 0]],
        ];
    }

    public function testMissingHistoryStillRequiresDemandForecastInBothViews(): void
    {
        foreach ([['ctrip'], ['ctrip', 'meituan']] as $channels) {
            $result = $this->preflight($channels);
            self::assertSame(0, $result['ctrip_traffic_demand_forecast_count']);
            self::assertContains('demand_forecast', array_column($result['required_inputs'], 'code'));
            self::assertFalse($result['can_generate_pending_suggestions']);
        }
    }

    public function testExistingForecastRecordKeepsItsSourceAndIsNotCountedAsTrafficForecast(): void
    {
        $this->insertHistory();
        Db::name('demand_forecasts')->insert([
            'id' => 1, 'hotel_id' => 80, 'room_type_id' => 1, 'forecast_date' => self::TARGET_DATE,
            'historical_data' => '{}', 'update_time' => self::TARGET_DATE, 'create_time' => self::TARGET_DATE,
        ]);
        foreach ([['ctrip'], ['ctrip', 'meituan']] as $channels) {
            $result = $this->preflight($channels);
            self::assertSame(1, $result['demand_forecast_count']);
            self::assertSame(0, $result['ctrip_traffic_demand_forecast_count']);
            self::assertSame('demand_forecasts', $result['hotel_checks'][0]['demand_forecast_source']);
            self::assertNotContains('demand_forecast', array_column($result['required_inputs'], 'code'));
        }
    }

    private function insertHistory(array $changes = []): void
    {
        for ($i = 1; $i <= 3; $i++) {
            Db::name('online_daily_data')->insert(array_replace([
                'id' => $i, 'tenant_id' => 1, 'system_hotel_id' => 80, 'hotel_id' => 'fixture-80',
                'data_source_id' => 10, 'sync_task_id' => 20, 'source' => 'ctrip', 'platform' => 'ctrip',
                'data_date' => '2026-06-' . (11 + $i), 'data_period' => 'historical_daily', 'data_type' => 'traffic',
                'readback_verified' => 1, 'history_status' => 'success', 'validation_status' => 'verified',
                'ingestion_method' => 'browser_profile', 'source_trace_id' => 'fixture-' . $i,
                'raw_data' => '{}', 'order_submit_num' => 100 + $i * 10,
            ], $changes));
        }
    }

    private function preflight(array $channels): array
    {
        // Canonical authority is a fixture, not live proof. Exact row readback,
        // hotel/tenant/platform/date/status filtering and traffic calculation are real.
        $authority = new class extends CanonicalOtaHistoryReceiptVerifier {
            public function verifyWindows(int $systemHotelId, array $windows): array
            {
                $results = [];
                foreach ($windows as $key => $window) {
                    $ids = Db::name('online_daily_data')->whereBetween('data_date', [$window['start'], $window['end']])
                        ->order('id')->column('id');
                    $results[$key] = [
                        'status' => $ids === [] ? 'empty' : 'ready', 'authoritative_row_ids' => $ids,
                        'candidate_row_count' => count($ids), 'authoritative_row_count' => count($ids), 'data_gaps' => [],
                    ];
                }
                return $results;
            }
        };
        $pricing = new RevenuePricingRecommendationService(null, null, new StrictCtripTrafficHistoryReader($authority));
        $service = new RevenueAiOverviewService(null, $pricing);
        return (new ReflectionMethod($service, 'pricingGenerationPreflight'))->invoke(
            $service, self::TARGET_DATE, 80, [80], [], $channels
        );
    }
}
