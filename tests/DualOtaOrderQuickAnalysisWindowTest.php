<?php
declare(strict_types=1);

namespace Tests;

use app\service\DualOtaOrderQuickAnalysisService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class DualOtaOrderQuickAnalysisWindowTest extends TestCase
{
    private static array $originalDatabaseConfig = [];

    public static function setUpBeforeClass(): void
    {
        (new App(dirname(__DIR__)))->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        $database = self::$originalDatabaseConfig;
        $connection = 'dual_ota_window_' . getmypid();
        $database['default'] = $connection;
        $database['connections'][$connection] = [
            'type' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($database, 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE online_daily_data (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            hotel_id TEXT,
            hotel_name TEXT,
            platform TEXT,
            source TEXT,
            data_type TEXT,
            data_date TEXT,
            dimension TEXT,
            amount REAL,
            room_revenue REAL,
            quantity REAL,
            book_order_num INTEGER,
            raw_data TEXT
        )');
    }

    public static function tearDownAfterClass(): void
    {
        Db::connect()->close();
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
    }

    protected function setUp(): void
    {
        Db::name('online_daily_data')->delete(true);
    }

    #[DataProvider('loaders')]
    public function testNewerExplicitFixturesCannotDisplaceRealScopedOrders(string $loader): void
    {
        $rows = [
            $this->row(1),
            $this->row(2, [
                'data_date' => '2026-09-01',
                'raw_data' => json_encode(['row' => ['raw_data' => json_encode([
                    'fixture_status' => 'explicit_test_fixture',
                ])]]),
            ]),
            $this->row(3, [
                'source' => 'meituan',
                'data_type' => 'order_flow',
                'data_date' => '2026-09-01',
                'raw_data' => json_encode(['detail' => ['fixture_status' => 'explicit_test_fixture']]),
            ]),
            $this->row(4, ['tenant_id' => 99, 'data_date' => '2026-12-31']),
            $this->row(5, ['system_hotel_id' => 99, 'data_date' => '2026-12-31']),
            $this->row(6, ['source' => 'agoda', 'data_date' => '2026-12-31']),
            $this->row(7, ['platform' => 'agoda', 'data_date' => '2026-12-31']),
            $this->row(8, ['data_type' => 'traffic', 'data_date' => '2026-12-31']),
        ];
        $analysis = $this->analyze($rows, $loader);
        self::assertSame('2026-06-16', $analysis['date_range']['from']);
        self::assertSame('2026-07-15', $analysis['date_range']['to']);
        self::assertSame(4, $analysis['platforms']['ctrip']['metrics']['orders']['value']);
        self::assertSame(1000, $analysis['platforms']['ctrip']['metrics']['revenue']['value']);
    }

    #[DataProvider('loaders')]
    public function testFixtureOnlyHistoryHasNoDefaultBusinessDate(string $loader): void
    {
        $rows = [$this->row(1, [
            'raw_data' => json_encode(['fixture_status' => 'explicit_test_fixture']),
        ])];
        $analysis = $this->analyze($rows, $loader);
        self::assertNull($analysis['date_range']['from']);
        self::assertNull($analysis['date_range']['to']);
        self::assertSame('latest_scoped_order_or_order_flow_date_missing', $analysis['date_range']['selection_reason']);
        self::assertNull($analysis['platforms']['ctrip']['metrics']['orders']['value']);
    }

    #[DataProvider('loaders')]
    public function testUnknownMarkersAndTestNamesDoNotExcludeManualRows(string $loader): void
    {
        $rows = [
            $this->row(1, [
                'platform' => 'ctrip',
                'source' => 'manual_import',
                'hotel_name' => '测试酒店',
                'raw_data' => json_encode([
                    'fixture_status' => 'unknown',
                    'note' => 'explicit_test_fixture',
                ]),
            ]),
            $this->row(2, [
                'data_date' => '2026-09-01',
                'raw_data' => json_encode(['fixture_status' => 'explicit_test_fixture']),
            ]),
        ];
        $analysis = $this->analyze($rows, $loader);
        self::assertSame('2026-07-15', $analysis['date_range']['to']);
        self::assertSame(4, $analysis['platforms']['ctrip']['metrics']['orders']['value']);
    }

    #[DataProvider('loaders')]
    public function testDefaultWindowCanFindRealHistoryBeyondABatchOfNewerFixtures(string $loader): void
    {
        $rows = [$this->row(1)];
        for ($id = 2; $id <= 202; $id++) {
            $rows[] = $this->row($id, [
                'data_date' => '2026-09-01',
                'raw_data' => json_encode(['fixture_status' => 'explicit_test_fixture']),
            ]);
        }
        $analysis = $this->analyze($rows, $loader);
        self::assertSame('2026-07-15', $analysis['date_range']['to']);
        self::assertSame(4, $analysis['platforms']['ctrip']['metrics']['orders']['value']);
    }

    public static function loaders(): array
    {
        return ['memory' => ['memory'], 'database' => ['database']];
    }

    private function analyze(array $rows, string $loader): array
    {
        Db::name('online_daily_data')->insertAll($rows);
        $deepAnalysis = static fn(): array => ['status' => 'missing'];
        $analysis = (new DualOtaOrderQuickAnalysisService(
            rowProvider: $loader === 'memory' ? static fn(): array => $rows : null,
            ctripAnalysisProvider: $deepAnalysis
        ))->analyze(80, 9);
        self::assertSame(count($rows), Db::name('online_daily_data')->count(), 'Analysis must not rewrite saved rows.');
        return $analysis;
    }

    private function row(int $id, array $overrides = []): array
    {
        return array_replace([
            'id' => $id,
            'tenant_id' => 9,
            'system_hotel_id' => 80,
            'hotel_id' => 'ctrip-hotel-80',
            'hotel_name' => '测试酒店',
            'platform' => '',
            'source' => 'ctrip',
            'data_type' => 'order',
            'data_date' => '2026-07-15',
            'dimension' => 'order_daily',
            'amount' => 1000,
            'room_revenue' => 1000,
            'quantity' => 5,
            'book_order_num' => 4,
            'raw_data' => json_encode(['record_kind' => 'order_daily_aggregate']),
        ], $overrides);
    }
}
