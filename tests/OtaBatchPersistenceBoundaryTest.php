<?php
declare(strict_types=1);

namespace Tests;

use app\service\PlatformNormalizedRowPersistenceService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OtaBatchPersistenceBoundaryTest extends TestCase
{
    private static array $originalConfig;
    private static string $fixturePath;
    private array $columns;

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$originalConfig = Config::get('database');
        self::$fixturePath = sys_get_temp_dir() . '/ota_batch_boundary_' . bin2hex(random_bytes(8)) . '.sqlite';
        $config = self::$originalConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = ['type' => 'sqlite', 'database' => self::$fixturePath, 'prefix' => '', 'fields_strict' => false];
        Config::set($config, 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)');
        Db::execute('INSERT INTO hotels VALUES (80, 1), (81, 2)');
        Db::execute('CREATE TABLE online_daily_data (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, system_hotel_id INTEGER,
            source TEXT, platform TEXT, hotel_id TEXT, data_type TEXT, data_date TEXT,
            dimension TEXT, compare_type TEXT, data_period TEXT, sync_task_id INTEGER,
            source_trace_id TEXT, persistence_identity_hash TEXT NOT NULL UNIQUE,
            amount REAL, raw_data TEXT, readback_verified INTEGER DEFAULT 0,
            readback_verified_at TEXT, create_time TEXT, update_time TEXT
        )');
    }

    public static function tearDownAfterClass(): void
    {
        Db::connect()->close();
        Config::set(self::$originalConfig, 'database');
        Db::connect(null, true);
        if (is_file(self::$fixturePath)) unlink(self::$fixturePath);
    }

    protected function setUp(): void
    {
        Db::execute('DROP TRIGGER IF EXISTS inject_failure');
        Db::name('online_daily_data')->delete(true);
        $this->columns = array_fill_keys(array_column(Db::query('PRAGMA table_info(online_daily_data)'), 'name'), true);
    }

    private function row(string $date = '2026-09-01', string $platform = 'meituan'): array
    {
        return [
            'tenant_id' => 999, 'system_hotel_id' => 80, 'source' => $platform,
            'platform' => $platform, 'hotel_id' => 'test-store', 'data_type' => 'business',
            'data_date' => $date, 'dimension' => 'daily', 'compare_type' => 'self',
            'data_period' => 'historical_daily', 'sync_task_id' => 1,
            'source_trace_id' => 'test-trace', 'amount' => 120.5, 'raw_data' => '{}',
        ];
    }

    public function testDuplicateDeliveryUpdatesOneScopedRowAndKeepsObservedZero(): void
    {
        $service = new PlatformNormalizedRowPersistenceService();
        foreach (['ctrip', 'meituan'] as $platform) {
            $row = $this->row(platform: $platform);
            $first = $service->save([$row, $row], $this->columns);
            self::assertTrue($first['readback_verified']);
            self::assertSame(1, $first['saved_count']);
            self::assertSame(1, $first['deduplicated_count']);
            $row['amount'] = 0.0;
            $second = $service->save([$row], $this->columns);
            self::assertSame($first['row_ids'], $second['row_ids']);
            self::assertSame(1, $second['updated_count']);
            $stored = Db::name('online_daily_data')->where('id', $second['row_ids'][0])->find();
            self::assertSame(0.0, (float)$stored['amount']);
            self::assertSame(1, (int)$stored['tenant_id']);
            self::assertSame(1, (int)$stored['readback_verified']);
        }
        self::assertSame(2, Db::name('online_daily_data')->count());
    }

    public function testFailureOnSecondInsertRollsBackTheWholeBatch(): void
    {
        Db::execute("CREATE TRIGGER inject_failure BEFORE INSERT ON online_daily_data
            WHEN NEW.data_date = '2026-09-02' BEGIN SELECT RAISE(FAIL, 'injected_fixture_failure'); END");
        foreach (['ctrip', 'meituan'] as $platform) {
            $failed = false;
            try {
                (new PlatformNormalizedRowPersistenceService())->save([
                    $this->row('2026-09-01', $platform), $this->row('2026-09-02', $platform),
                ], $this->columns);
            } catch (\Throwable) {
                $failed = true;
            }
            self::assertTrue($failed, 'A database failure must not be presented as a successful receipt.');
            self::assertSame(0, Db::name('online_daily_data')->count());
        }
    }

    public function testReadbackMutationReturnsRollbackReceiptAndNoPartialRows(): void
    {
        Db::execute("CREATE TRIGGER inject_failure AFTER INSERT ON online_daily_data
            WHEN NEW.data_date = '2026-09-02' BEGIN UPDATE online_daily_data SET amount = 999 WHERE id = NEW.id; END");
        $receipt = (new PlatformNormalizedRowPersistenceService())->save([
            $this->row('2026-09-01'), $this->row('2026-09-02'),
        ], $this->columns);
        self::assertTrue($receipt['rolled_back']);
        self::assertFalse($receipt['readback_verified']);
        self::assertSame(0, $receipt['saved_count']);
        self::assertSame('amount', $receipt['mismatch_field']);
        self::assertSame(0, Db::name('online_daily_data')->count());
    }
}
