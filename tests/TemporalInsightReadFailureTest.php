<?php
declare(strict_types=1);

namespace tests;

use app\service\TemporalInsightService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class TemporalInsightReadFailureTest extends TestCase
{
    private static App $app;
    private static array $originalDatabaseConfig = [];
    private static string $connection = '';
    private static string $databasePath = '';

    public static function setUpBeforeClass(): void
    {
        self::$app = new App(dirname(__DIR__));
        self::$app->initialize();
        self::$connection = 'temporal_read_failure_' . getmypid() . '_' . bin2hex(random_bytes(4));
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
        Db::execute('CREATE TABLE online_daily_data (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            system_hotel_id INTEGER NOT NULL,
            data_date TEXT NOT NULL,
            data_period TEXT NOT NULL,
            is_final INTEGER NOT NULL,
            data_type TEXT NOT NULL
        )');
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

    public function testActualMissingFactTableRemainsTableMissing(): void
    {
        Db::execute('ALTER TABLE "online_daily_data" RENAME TO "online_daily_data_missing"');
        try {
            $overview = (new TemporalInsightService())->overview([80], 7, 3, '2026-08-05');
        } finally {
            Db::execute('ALTER TABLE "online_daily_data_missing" RENAME TO "online_daily_data"');
        }

        self::assertSame('empty', $overview['past']['status']);
        self::assertSame('empty', $overview['past']['data_status']);
        self::assertSame('table_missing', $overview['past']['reason_code']);
        self::assertFalse($overview['view_state']['has_past']);
        self::assertFalse($overview['view_state']['has_present']);
    }

    public function testBrokenFactViewIsBlockedReadFailureInsteadOfTableMissingOrEmpty(): void
    {
        Db::execute('ALTER TABLE "online_daily_data" RENAME TO "online_daily_data_healthy"');
        Db::execute('CREATE VIEW "online_daily_data" AS SELECT * FROM "temporal_missing_dependency"');
        try {
            $overview = (new TemporalInsightService())->overview([80], 7, 3, '2026-08-05');
        } finally {
            Db::execute('DROP VIEW "online_daily_data"');
            Db::execute('ALTER TABLE "online_daily_data_healthy" RENAME TO "online_daily_data"');
        }

        foreach (['past', 'present'] as $view) {
            self::assertSame('blocked', $overview[$view]['status']);
            self::assertSame('read_failed', $overview[$view]['data_status']);
            self::assertSame('temporal_fact_schema_check_failed', $overview[$view]['reason_code']);
            self::assertSame('online_daily_data', $overview[$view]['stage']);
            self::assertNull($overview[$view]['source']['source_rows'] ?? null);
        }
        self::assertFalse($overview['view_state']['has_past']);
        self::assertFalse($overview['view_state']['has_present']);
    }
}
