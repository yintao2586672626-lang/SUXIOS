<?php
declare(strict_types=1);

namespace Tests;

use app\controller\OnlineData;
use app\controller\concern\OnlineDataQualityConcern;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use think\Response;
use think\facade\Config;
use think\facade\Db;

final class MeituanAdvertisingReadbackProjectionTest extends TestCase
{
    private array $originalDatabase;

    protected function setUp(): void
    {
        $this->originalDatabase = Config::get('database', []);
        Config::set(['default' => 'file', 'stores' => ['file' => ['type' => 'File', 'path' => (string)getenv('SUXIOS_CACHE_PATH')]]], 'cache');
        Config::set(['default' => 'file', 'channels' => ['file' => ['type' => 'File', 'path' => (string)getenv('SUXIOS_CACHE_PATH') . '/logs/']]], 'log');
        Config::set(['default' => 'ad_readback_test', 'connections' => ['ad_readback_test' => [
            'type' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'fields_strict' => false,
        ]]], 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT)');
        Db::name('hotels')->insertAll([
            ['id' => 81, 'tenant_id' => 7, 'name' => 'Synthetic Hotel 81'],
            ['id' => 82, 'tenant_id' => 8, 'name' => 'Synthetic Hotel 82'],
        ]);
        Db::execute('CREATE TABLE online_daily_data (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, system_hotel_id INTEGER,
            hotel_id TEXT, hotel_name TEXT, source TEXT, platform TEXT, data_type TEXT, dimension TEXT, compare_type TEXT, data_date TEXT,
            amount REAL, quantity REAL, book_order_num REAL, comment_score REAL, qunar_comment_score REAL,
            list_exposure REAL, detail_exposure REAL, order_filling_num REAL, order_submit_num REAL, data_value REAL, flow_rate REAL,
            raw_data TEXT, validation_status TEXT, validation_flags TEXT, readback_verified INTEGER DEFAULT 0, readback_verified_at TEXT,
            ingestion_method TEXT, source_trace_id TEXT, data_period TEXT, is_final INTEGER, snapshot_time TEXT, snapshot_bucket TEXT,
            update_time TEXT, create_time TEXT)');
    }

    protected function tearDown(): void
    {
        Db::connect()->close();
        Config::set($this->originalDatabase, 'database');
    }

    private function read(array $params = []): array
    {
        $params += ['system_hotel_id' => '81', 'start_date' => '2026-09-26', 'end_date' => '2026-09-26'];
        $controller = new class($params) {
            use OnlineDataQualityConcern;
            public object $request;
            public function __construct(array $params)
            {
                $user = new class {
                    public function isSuperAdmin(): bool { return false; }
                    public function getPermittedHotelIds(): array { return [81]; }
                    public function hasHotelPermission(int $id, string $capability): bool { return $id === 81 && $capability === 'can_view_online_data'; }
                };
                $this->request = new class($user, $params) {
                    public function __construct(public object $user, private array $params) {}
                    public function get(string $key, mixed $default = null): mixed { return $this->params[$key] ?? $default; }
                };
            }
            private function getOnlineDailyDataColumns(): array
            {
                return array_fill_keys(array_column(Db::query('PRAGMA table_info(online_daily_data)'), 'name'), true);
            }
            protected function success(mixed $data = null, string $message = 'ok'): Response { return json(['code' => 200, 'data' => $data, 'message' => $message]); }
            protected function error(string $message = 'error', int $code = 400, mixed $data = null): Response { return json(['code' => $code, 'message' => $message, 'data' => $data], $code); }
        };
        $response = $controller->dailyDataList();
        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(200, $body['code'], $body['message']);
        return $body['data'];
    }

    private function insert(array|string $raw, array $overrides = []): int
    {
        return (int)Db::name('online_daily_data')->insertGetId(array_merge([
            'tenant_id' => 7, 'system_hotel_id' => 81, 'hotel_id' => 'synthetic-81',
            'hotel_name' => 'Synthetic Hotel 81', 'source' => 'meituan', 'platform' => 'Meituan',
            'data_type' => 'advertising', 'data_date' => '2026-09-26', 'dimension' => 'ads:synthetic',
            'amount' => 30, 'data_value' => 2, 'validation_status' => 'partial', 'readback_verified' => 1,
            'raw_data' => is_array($raw) ? json_encode($raw, JSON_THROW_ON_ERROR) : $raw,
        ], $overrides));
    }

    public static function amounts(): array
    {
        $cases = [];
        foreach (['order_amount', 'orderAmount', 'saleAmount', 'salesAmount', 'revenue', 'gmv'] as $key) {
            $cases[$key] = [[$key => 120], 120.0];
        }
        $cases['zero'] = [['order_amount' => 0], 0.0];
        $cases['legacy wrapped zero'] = [['trace' => 'synthetic', 'row' => ['orderAmount' => '0']], 0.0];
        $cases['formatted'] = [['order_amount' => '1,200.50'], 1200.5];
        $cases['missing is not spend'] = [['amount' => 30, 'spend' => 30], null];
        $cases['placeholder'] = [['orderAmount' => '--'], null];
        $cases['whitespace'] = [['orderAmount' => '   '], null];
        $cases['non scalar'] = [['orderAmount' => ['value' => 90]], null];
        $cases['non finite'] = [['orderAmount' => '1e9999'], null];
        $cases['negative source is not clamped'] = [['order_amount' => -25], -25.0];
        $cases['wrapped canonical zero wins'] = [['order_amount' => 100, 'row' => ['order_amount' => 0]], 0.0];
        return $cases;
    }

    #[DataProvider('amounts')]
    public function testReadbackProjectsOnlyTheAdvertisingAttributionAmount(array $raw, ?float $expected): void
    {
        $id = $this->insert($raw);
        $storedBefore = Db::name('online_daily_data')->find($id);
        $row = $this->read()['list'][0];
        $actual = $row['order_amount'] ?? null;
        self::assertSame($expected, $actual === null ? null : (float)$actual);
        self::assertSame(30, $row['amount'], 'Spend remains a different metric');
        self::assertSame('partial', $row['validation_status']);
        self::assertSame(1, $row['readback_verified']);
        self::assertSame($storedBefore, Db::name('online_daily_data')->find($id), 'Read projection must not rewrite stored evidence');
        self::assertNotSame('verified', $row['truth']['status']);
    }

    public function testOtherPlatformsTypesDatesAndTenantsDoNotGainOrLeakThisProjection(): void
    {
        $ctrip = $this->insert(['order_amount' => 900], ['source' => 'ctrip', 'platform' => 'Ctrip']);
        $order = $this->insert(['order_amount' => 400], ['data_type' => 'order']);
        $ad = $this->insert(['order_amount' => 90]);
        $this->insert(['order_amount' => 888], ['tenant_id' => 8]);
        $this->insert(['order_amount' => 777], ['system_hotel_id' => 82, 'tenant_id' => 8]);
        $this->insert(['order_amount' => 666], ['data_date' => '2026-09-25']);
        $result = $this->read();
        self::assertSame(3, $result['pagination']['total']);
        $rows = array_column($result['list'], null, 'id');
        self::assertArrayNotHasKey('order_amount', $rows[$ctrip]);
        self::assertArrayNotHasKey('order_amount', $rows[$order]);
        self::assertSame(90, $rows[$ad]['order_amount'] ?? null);
    }

    public function testMalformedOrAbsentRawDataDoesNotTurnSpendIntoRevenue(): void
    {
        foreach (['{broken', '', '{}'] as $raw) $this->insert($raw);
        foreach ($this->read()['list'] as $row) {
            self::assertNull($row['order_amount'] ?? null);
            self::assertSame(30, $row['amount']);
        }
    }

    public function testProjectedAmountLeavesQualityAndTruthBoundToOriginalStoredEvidence(): void
    {
        $this->insert(['row' => ['orderAmount' => 0]], ['data_type' => 'ads']);
        $row = $this->read()['list'][0];
        self::assertSame(0, $row['order_amount'] ?? null);
        $controller = (new ReflectionClass(OnlineData::class))->newInstanceWithoutConstructor();
        $unprojected = $row;
        unset($unprojected['order_amount']);
        foreach (['buildOnlineDataQuality', 'buildOnlineDataStorageStatus'] as $name) {
            $method = new ReflectionMethod($controller, $name);
            $method->setAccessible(true);
            self::assertSame($method->invoke($controller, $unprojected), $method->invoke($controller, $row));
        }
        $raw = json_decode($row['raw_data'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            \app\service\OnlineDataFieldFactService::buildStatus($unprojected, $raw),
            \app\service\OnlineDataFieldFactService::buildStatus($row, $raw)
        );
        self::assertSame(
            \app\service\OnlineDataTrustStatusService::truthEnvelope($unprojected, $row['field_fact_status']),
            \app\service\OnlineDataTrustStatusService::truthEnvelope($row, $row['field_fact_status'])
        );
    }

    public function testActualCaptureSaveAndListRefreshKeepAttributionZeroMissingAndRecoverySeparate(): void
    {
        $writer = (new ReflectionClass(OnlineData::class))->newInstanceWithoutConstructor();
        $invoke = static function(string $method, array $args) use ($writer) {
            $reflection = new ReflectionMethod($writer, $method);
            $reflection->setAccessible(true);
            return $reflection->invokeArgs($writer, $args);
        };
        $id = null;
        $observed = [];
        foreach ([['positive', 60], ['zero', 0], ['missing', null], ['recovered', 90]] as [$name, $value]) {
            $item = ['adId' => 'synthetic-ad-81', 'date' => '2026-09-26', 'cost' => 30];
            if ($value !== null) $item['orderAmount'] = $value;
            $rows = $invoke('buildMeituanCapturedDailyRows', [[
                'storeId' => 'synthetic-81', 'poiId' => 'synthetic-81', 'poiName' => 'Synthetic Hotel 81',
                'defaultDataDate' => '2026-09-26', 'ads' => [$item],
            ], 81]);
            self::assertSame(1, $invoke('saveMeituanCapturedDailyRows', [$rows]));
            $row = $this->read(['source' => 'meituan', 'data_type' => 'advertising'])['list'][0];
            if ($id !== null) self::assertSame($id, $row['id']);
            $id = $row['id'];
            $observed[] = ['name' => $name, 'expected' => $value, 'row' => $row];
        }
        $evidence = getenv('SUXIOS_AD_READBACK_EVIDENCE');
        if ($evidence !== false && $evidence !== '') file_put_contents($evidence, json_encode($observed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        foreach ($observed as $entry) self::assertSame($entry['expected'], $entry['row']['order_amount'] ?? null, $entry['name']);
    }

    public function testSameIdentityRecordsRetainTheirDistinctStoredAttributionAmounts(): void
    {
        $this->insert(['order_amount' => 60]);
        $this->insert(['order_amount' => 90]);
        $result = $this->read(['source' => 'meituan', 'data_type' => 'advertising']);
        $evidence = getenv('SUXIOS_AD_READBACK_EVIDENCE');
        if ($evidence !== false && $evidence !== '') file_put_contents($evidence . '.duplicates.json', json_encode($result['list'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        self::assertSame(2, $result['pagination']['total']);
        self::assertSame([90, 60], array_column($result['list'], 'order_amount'));
    }
}
