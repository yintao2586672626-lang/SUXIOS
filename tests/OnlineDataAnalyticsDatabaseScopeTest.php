<?php
declare(strict_types=1);

namespace Tests;

use app\controller\concern\OnlineDataAnalyticsConcern;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OnlineDataAnalyticsDatabaseScopeTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'online_data_analytics_scope_' . getmypid() . '.sqlite';
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
        Db::execute(<<<'SQL'
CREATE TABLE online_daily_data (
    id INTEGER PRIMARY KEY,
    data_type TEXT DEFAULT NULL,
    amount REAL NOT NULL DEFAULT 0
)
SQL);
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
            throw new RuntimeException('Unable to remove online analytics scope SQLite fixture.');
        }
    }

    public function testDefaultBusinessAggregateExcludesUntypedAndNonBusinessRows(): void
    {
        Db::name('online_daily_data')->insertAll([
            ['id' => 1, 'data_type' => 'business', 'amount' => 100],
            ['id' => 2, 'data_type' => null, 'amount' => 200],
            ['id' => 3, 'data_type' => '', 'amount' => 400],
            ['id' => 4, 'data_type' => 'advertising', 'amount' => 800],
            ['id' => 5, 'data_type' => 'peer_rank', 'amount' => 1600],
            ['id' => 6, 'data_type' => 'ranking', 'amount' => 3200],
            ['id' => 7, 'data_type' => 'traffic', 'amount' => 6400],
        ]);

        $subject = new class {
            use OnlineDataAnalyticsConcern;
        };
        $normalize = new ReflectionMethod($subject, 'normalizeOnlineDataAnalysisType');
        $filter = new ReflectionMethod($subject, 'applyDataTypeFilter');
        $dataType = $normalize->invoke($subject, '');
        $query = Db::name('online_daily_data');
        $filter->invoke($subject, $query, $dataType);
        $rows = $query->order('id', 'asc')->select()->toArray();

        self::assertSame('business', $dataType);
        self::assertCount(1, $rows);
        self::assertSame(['business'], array_column($rows, 'data_type'));
        self::assertSame(100.0, array_sum(array_map('floatval', array_column($rows, 'amount'))));
    }
}
