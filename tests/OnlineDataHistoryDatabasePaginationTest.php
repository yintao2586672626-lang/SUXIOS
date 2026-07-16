<?php
declare(strict_types=1);

namespace Tests;

use app\controller\concern\OnlineDataHistoryConcern;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OnlineDataHistoryDatabasePaginationTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'online_history_database_pagination_' . getmypid() . '.sqlite';
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
            throw new RuntimeException('Unable to remove online history pagination SQLite fixture.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Db::name('online_daily_data')->delete(true);
        Db::name('online_daily_data')->insertAll([
            $this->row(10, '2026-07-16 10:00:00', 'comment', '', '{}'),
            $this->row(9, '2026-07-16 09:59:00', 'comments', '', '{}'),
            $this->row(8, '2026-07-16 09:00:00', 'traffic', 'traffic', '{"error":"capture failed"}', 'abnormal', 0),
            $this->row(7, '2026-07-15 08:00:00', 'competitor', 'competition_circle_hotel', '{}'),
            $this->row(6, '2026-07-15 07:00:00', 'competitor', 'competition_circle_hotel', '{}'),
        ]);
    }

    public function testDatabasePaginationKeepsMergedGroupsIntact(): void
    {
        $subject = new class {
            use OnlineDataHistoryConcern;
        };
        $columns = self::columns();
        self::assertSame(5, (int)Db::name('online_daily_data')->count());
        $method = new ReflectionMethod($subject, 'buildOnlineHistoryDatabasePagination');
        $expressionMethod = new ReflectionMethod($subject, 'onlineHistorySqlGroupKeyExpression');
        $groupExpression = $expressionMethod->invoke($subject, $columns);
        $firstPage = $method->invoke($subject, Db::name('online_daily_data'), $columns, 1, 2);
        $secondPage = $method->invoke($subject, Db::name('online_daily_data'), $columns, 2, 2);

        self::assertSame(4, $firstPage['total'], var_export($firstPage, true));
        self::assertCount(2, $firstPage['group_keys']);
        self::assertCount(2, $secondPage['group_keys']);
        self::assertSame([], array_values(array_intersect($firstPage['group_keys'], $secondPage['group_keys'])));
        self::assertSame(4, $firstPage['summary']['total_records']);
        self::assertSame('2026-07-16 10:00:00', $firstPage['summary']['latest_fetch_time']);
        self::assertSame(1, $firstPage['summary']['failed_records']);

        $scopeMethod = new ReflectionMethod($subject, 'applyOnlineHistoryGroupKeyScope');
        $pageQuery = $scopeMethod->invoke(
            $subject,
            Db::name('online_daily_data'),
            $firstPage['group_key_expression'],
            $firstPage['group_keys']
        );
        $ids = array_map('intval', $pageQuery->order('id', 'desc')->column('id'));
        self::assertSame([10, 9, 8], $ids);
    }

    public function testHistoryEndpointNoLongerLoadsEveryLightweightRowBeforePaging(): void
    {
        $source = (string)file_get_contents(__DIR__ . '/../app/controller/concern/OnlineDataHistoryConcern.php');
        self::assertStringContainsString('buildOnlineHistoryDatabasePagination(', $source);
        self::assertStringNotContainsString('$lightweightRows = $lightweightQuery->select()->toArray();', $source);
        self::assertStringContainsString("->group('history_group_key')", $source);
        self::assertStringContainsString('->limit(($page - 1) * $pageSize, $pageSize)', $source);
    }

    public function testDatabaseSummaryCountsTodayByFetchTimeInsteadOfBusinessDate(): void
    {
        Db::name('online_daily_data')->delete(true);
        $today = date('Y-m-d');
        $fetchedToday = $this->row(2, $today . ' 09:00:00', 'traffic', 'traffic', '{}');
        $fetchedToday['data_date'] = '2026-01-01';
        $oldFetchWithTodayBusinessDate = $this->row(1, '2026-01-01 09:00:00', 'business', '', '{}');
        $oldFetchWithTodayBusinessDate['data_date'] = $today;
        Db::name('online_daily_data')->insertAll([$fetchedToday, $oldFetchWithTodayBusinessDate]);

        $subject = new class {
            use OnlineDataHistoryConcern;
        };
        $method = new ReflectionMethod($subject, 'buildOnlineHistoryDatabasePagination');
        $result = $method->invoke(
            $subject,
            Db::name('online_daily_data'),
            self::columns(),
            1,
            20
        );

        self::assertSame(1, $result['summary']['today_records'], var_export($result, true));
    }

    private static function createSchema(): void
    {
        Db::execute(<<<'SQL'
CREATE TABLE online_daily_data (
    id INTEGER PRIMARY KEY,
    data_date TEXT NOT NULL,
    source TEXT NOT NULL DEFAULT '',
    platform TEXT DEFAULT NULL,
    data_type TEXT NOT NULL DEFAULT '',
    system_hotel_id INTEGER DEFAULT NULL,
    dimension TEXT NOT NULL DEFAULT '',
    compare_type TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT '',
    validation_status TEXT NOT NULL DEFAULT 'normal',
    readback_verified INTEGER NOT NULL DEFAULT 0,
    raw_data TEXT DEFAULT NULL,
    amount REAL DEFAULT 0,
    quantity REAL DEFAULT 0,
    book_order_num INTEGER DEFAULT 0,
    data_value REAL DEFAULT 0,
    list_exposure INTEGER DEFAULT 0,
    detail_exposure INTEGER DEFAULT 0,
    order_submit_num INTEGER DEFAULT 0,
    create_time TEXT DEFAULT NULL,
    update_time TEXT DEFAULT NULL
)
SQL);
    }

    private static function columns(): array
    {
        $columns = [];
        foreach (Db::query('PRAGMA table_info(online_daily_data)') as $column) {
            $columns[(string)$column['name']] = true;
        }
        return $columns;
    }

    private function row(
        int $id,
        string $fetchTime,
        string $dataType,
        string $dimension,
        string $rawData,
        string $validationStatus = 'normal',
        int $readbackVerified = 1
    ): array
    {
        return [
            'id' => $id,
            'data_date' => substr($fetchTime, 0, 10),
            'source' => 'ctrip',
            'platform' => 'Ctrip',
            'data_type' => $dataType,
            'system_hotel_id' => 7,
            'dimension' => $dimension,
            'compare_type' => '',
            'status' => '',
            'validation_status' => $validationStatus,
            'readback_verified' => $readbackVerified,
            'raw_data' => $rawData,
            'amount' => 0,
            'quantity' => 0,
            'book_order_num' => 0,
            'data_value' => 0,
            'list_exposure' => 0,
            'detail_exposure' => 0,
            'order_submit_num' => 0,
            'create_time' => $fetchTime,
            'update_time' => $fetchTime,
        ];
    }
}
