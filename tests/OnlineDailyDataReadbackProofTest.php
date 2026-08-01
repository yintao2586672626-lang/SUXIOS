<?php
declare(strict_types=1);

namespace Tests;

use app\service\OnlineDailyDataPersistenceService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OnlineDailyDataReadbackProofTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'online_daily_readback_proof_' . getmypid() . '.sqlite';
        @unlink(self::$sqlitePath);

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
        self::createSchema();
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
            throw new RuntimeException('Unable to remove online daily readback SQLite fixture.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Db::name('online_daily_data')->delete(true);
        Db::name('hotels')->delete(true);
        Db::name('hotels')->insertAll([
            ['id' => 7, 'tenant_id' => 44],
            ['id' => 8, 'tenant_id' => null],
            ['id' => 9, 'tenant_id' => 0],
        ]);
    }

    public function testTenantScopeAlwaysComesFromHotelsTenantId(): void
    {
        self::assertSame(44, OnlineDailyDataPersistenceService::resolveTenantIdForSystemHotel(7));

        $scoped = OnlineDailyDataPersistenceService::applyTenantScope(
            ['system_hotel_id' => 7, 'tenant_id' => 999, 'amount' => 120.5],
            ['system_hotel_id' => true, 'tenant_id' => true, 'amount' => true]
        );

        self::assertSame(44, $scoped['tenant_id']);
    }

    public function testMissingOrInvalidHotelTenantScopeFailsClosed(): void
    {
        foreach ([8, 9, 404] as $systemHotelId) {
            try {
                OnlineDailyDataPersistenceService::resolveTenantIdForSystemHotel($systemHotelId);
                self::fail('An invalid hotel tenant scope must not be accepted.');
            } catch (RuntimeException $exception) {
                self::assertSame('hotel_tenant_id_missing_or_invalid', $exception->getMessage());
            }
        }
    }

    public function testReadbackProofUsesFullSnapshotCompareAndSwap(): void
    {
        $columns = self::onlineDailyColumns();
        $rowId = (int)Db::name('online_daily_data')->insertGetId([
            'tenant_id' => 44,
            'system_hotel_id' => 7,
            'source' => 'ctrip',
            'data_type' => 'business',
            'data_date' => '2026-07-16',
            'dimension' => '',
            'amount' => 120.5,
            'raw_data' => '{"amount":120.5}',
            'readback_verified' => 0,
            'readback_verified_at' => null,
            'update_time' => null,
        ]);
        $snapshot = Db::name('online_daily_data')->where('id', $rowId)->find();
        self::assertIsArray($snapshot);

        self::assertTrue(OnlineDailyDataPersistenceService::markRowsReadbackVerified([$snapshot], $columns));
        self::assertSame(1, (int)Db::name('online_daily_data')->where('id', $rowId)->value('readback_verified'));
        self::assertFalse(OnlineDailyDataPersistenceService::markRowsReadbackVerified([$snapshot], $columns));

        Db::name('online_daily_data')->where('id', $rowId)->update([
            'amount' => 120.5,
            'readback_verified' => 0,
            'readback_verified_at' => null,
        ]);
        $staleSnapshot = Db::name('online_daily_data')->where('id', $rowId)->find();
        self::assertIsArray($staleSnapshot);
        Db::name('online_daily_data')->where('id', $rowId)->update(['amount' => 121.0]);

        self::assertFalse(OnlineDailyDataPersistenceService::markRowsReadbackVerified([$staleSnapshot], $columns));
        self::assertSame(0, (int)Db::name('online_daily_data')->where('id', $rowId)->value('readback_verified'));

        $currentSnapshot = Db::name('online_daily_data')->where('id', $rowId)->find();
        self::assertIsArray($currentSnapshot);
        $partialSnapshot = array_intersect_key($currentSnapshot, array_flip([
            'id', 'tenant_id', 'system_hotel_id', 'readback_verified',
        ]));
        self::assertFalse(OnlineDailyDataPersistenceService::markRowsReadbackVerified([$partialSnapshot], $columns));

        self::assertFalse(OnlineDailyDataPersistenceService::markRowsReadbackVerified([$rowId], $columns));
        self::assertSame(0, (int)Db::name('online_daily_data')->where('id', $rowId)->value('readback_verified'));
    }

    public function testReadbackProofRejectsWrongTenantSnapshot(): void
    {
        $columns = self::onlineDailyColumns();
        $rowId = (int)Db::name('online_daily_data')->insertGetId([
            'tenant_id' => 44,
            'system_hotel_id' => 7,
            'source' => 'meituan',
            'data_type' => 'peer_rank',
            'data_date' => '2026-07-16',
            'dimension' => 'rank',
            'amount' => 9,
            'raw_data' => '{"rank":9}',
            'readback_verified' => 0,
            'readback_verified_at' => null,
            'update_time' => null,
        ]);
        $snapshot = Db::name('online_daily_data')->where('id', $rowId)->find();
        self::assertIsArray($snapshot);
        $snapshot['tenant_id'] = 45;

        self::assertFalse(OnlineDailyDataPersistenceService::markRowsReadbackVerified([$snapshot], $columns));
        self::assertSame(0, (int)Db::name('online_daily_data')->where('id', $rowId)->value('readback_verified'));
    }

    private static function onlineDailyColumns(): array
    {
        return array_fill_keys([
            'id',
            'tenant_id',
            'system_hotel_id',
            'source',
            'data_type',
            'data_date',
            'dimension',
            'amount',
            'raw_data',
            'readback_verified',
            'readback_verified_at',
            'update_time',
        ], true);
    }

    private static function createSchema(): void
    {
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NULL)');
        Db::execute(
            'CREATE TABLE online_daily_data ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'tenant_id INTEGER NOT NULL, '
            . 'system_hotel_id INTEGER NOT NULL, '
            . 'source TEXT NOT NULL, '
            . 'data_type TEXT NOT NULL, '
            . 'data_date TEXT NOT NULL, '
            . 'dimension TEXT NOT NULL, '
            . 'amount REAL NULL, '
            . 'raw_data TEXT NULL, '
            . 'readback_verified INTEGER NOT NULL DEFAULT 0, '
            . 'readback_verified_at TEXT NULL, '
            . 'update_time TEXT NULL'
            . ')'
        );
    }
}
