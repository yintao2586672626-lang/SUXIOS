<?php
declare(strict_types=1);

namespace Tests;

use app\command\PrepareDailyOperating;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class PrepareDailyOperatingCommandTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $connection = '';
    private static string $databasePath = '';

    public static function setUpBeforeClass(): void
    {
        (new App(dirname(__DIR__)))->initialize();
        self::$connection = 'prepare_daily_command_' . getmypid() . '_' . bin2hex(random_bytes(4));
        self::$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::$connection . '.sqlite';
        self::$originalDatabaseConfig = Config::get('database');
        $database = self::$originalDatabaseConfig;
        $database['default'] = self::$connection;
        $database['connections'][self::$connection] = [
            'type' => 'sqlite',
            'database' => self::$databasePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($database, 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, status INTEGER NOT NULL)');
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Db::connect()->close();
        } catch (\Throwable) {
        }
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$databasePath);
    }

    protected function setUp(): void
    {
        Db::execute('DELETE FROM hotels');
    }

    public function testAllActiveHotelQueryPaginatesBeyondFiveHundred(): void
    {
        foreach (array_chunk(range(1, 1001), 200) as $ids) {
            Db::name('hotels')->insertAll(array_map(
                static fn(int $id): array => ['id' => $id, 'tenant_id' => $id + 1000, 'status' => 1],
                $ids
            ));
        }
        Db::name('hotels')->insert(['id' => 2000, 'tenant_id' => 3000, 'status' => 0]);

        $command = (new \ReflectionClass(PrepareDailyOperating::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(PrepareDailyOperating::class, 'activeHotelPage');
        $lastId = 0;
        $pageSizes = [];
        $actualIds = [];
        do {
            $page = $method->invoke($command, $lastId);
            if ($page === []) {
                break;
            }
            $pageSizes[] = count($page);
            $ids = array_map('intval', array_column($page, 'id'));
            array_push($actualIds, ...$ids);
            $lastId = $ids[array_key_last($ids)];
        } while (count($page) === 500);

        self::assertSame([500, 500, 1], $pageSizes);
        self::assertSame(range(1, 1001), $actualIds);
        self::assertNotContains(2000, $actualIds);
    }
}
