<?php
declare(strict_types=1);

namespace Tests;

use app\service\StoredOtaHistoryLocator;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class StoredOtaHistoryLocatorTest extends TestCase
{
    private static array $originalConfig;
    private static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        (new App(dirname(__DIR__)))->initialize();
        self::$originalConfig = Config::get('database');
        $connection = 'stored_history_test_' . bin2hex(random_bytes(6));
        self::$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $connection . '.sqlite';
        $config = self::$originalConfig;
        $config['default'] = $connection;
        $config['connections'][$connection] = ['type' => 'sqlite', 'database' => self::$databasePath, 'prefix' => '', 'fields_strict' => false];
        Config::set($config, 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER)');
        Db::execute('CREATE TABLE online_daily_data (id INTEGER PRIMARY KEY, tenant_id INTEGER, system_hotel_id INTEGER, source TEXT, data_type TEXT, data_period TEXT, data_date TEXT)');
        Db::name('hotels')->insertAll([['id' => 1, 'tenant_id' => 10], ['id' => 2, 'tenant_id' => 20], ['id' => 3, 'tenant_id' => 10]]);
    }

    public static function tearDownAfterClass(): void
    {
        Db::connect()->close();
        Config::set(self::$originalConfig, 'database');
        Db::connect(null, true);
        unlink(self::$databasePath);
    }

    protected function setUp(): void
    {
        Db::execute('DELETE FROM online_daily_data');
    }

    public function testHistoryIsScopedAndExcludesFutureSnapshotsAndCompetitors(): void
    {
        $base = ['tenant_id' => 10, 'system_hotel_id' => 1, 'source' => 'ctrip', 'data_type' => 'business', 'data_period' => 'historical_daily', 'data_date' => '2020-01-02'];
        foreach ([
            [], ['source' => 'meituan', 'data_date' => '2020-01-03'],
            ['system_hotel_id' => 2, 'tenant_id' => 20, 'data_date' => '2020-01-09'],
            ['tenant_id' => 20, 'data_date' => '2020-01-09'],
            ['system_hotel_id' => 3, 'data_date' => '2020-01-09'],
            ['data_period' => 'realtime_snapshot', 'data_date' => '2020-01-08'],
            ['data_period' => 'future_on_books', 'data_date' => '2020-01-08'],
            ['data_type' => 'competitor', 'data_date' => '2020-01-07'],
            ['data_date' => '2020-02-01'],
        ] as $override) {
            Db::name('online_daily_data')->insert(array_replace($base, $override));
        }
        $before = Db::name('online_daily_data')->count();
        $result = (new StoredOtaHistoryLocator())->locate(10, 1, '2020-01-10');
        self::assertSame(['2020-01-02', '2020-01-03'], array_column($result['platforms'], 'latest_stored_date'));
        self::assertSame('stored_only_not_business_validation', $result['evidence_status']);
        self::assertSame('2020-01-10', $result['requested_business_date']);
        self::assertSame($before, Db::name('online_daily_data')->count());
    }

    public function testMismatchedTenantCannotDiscoverAnotherHotelsDates(): void
    {
        $result = (new StoredOtaHistoryLocator())->locate(10, 2, '2020-01-10');
        self::assertSame('blocked', $result['status']);
        self::assertSame([], $result['platforms']);
    }

    public function testEmptyAndReadFailureRemainDifferent(): void
    {
        $service = new StoredOtaHistoryLocator();
        $result = $service->locate(10, 1, '2020-01-10');
        self::assertSame('empty', $result['status']);
        self::assertSame([null, null], array_column($result['platforms'], 'latest_stored_date'));
        Db::execute('ALTER TABLE online_daily_data RENAME TO history_test_unavailable');
        try {
            $result = $service->locate(10, 1, '2020-01-10');
            self::assertSame('error', $result['status']);
            self::assertSame(['error', 'error'], array_column($result['platforms'], 'status'));
        } finally {
            Db::execute('ALTER TABLE history_test_unavailable RENAME TO online_daily_data');
        }
    }

    public function testInvalidCalendarDateIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new StoredOtaHistoryLocator())->locate(10, 1, '2020-02-30');
    }
}
