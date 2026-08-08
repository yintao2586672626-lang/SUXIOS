<?php
declare(strict_types=1);

namespace tests;

use app\service\AiDailyReportService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class AiDailyReportReadFailureTest extends TestCase
{
    private static App $app;
    private static array $originalDatabaseConfig = [];
    private static string $connection = '';
    private static string $databasePath = '';

    public static function setUpBeforeClass(): void
    {
        self::$app = new App(dirname(__DIR__));
        self::$app->initialize();
        self::$connection = 'ai_daily_read_failure_' . getmypid() . '_' . bin2hex(random_bytes(4));
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
        Db::execute('CREATE TABLE ai_daily_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            hotel_id INTEGER NOT NULL,
            report_date TEXT NOT NULL,
            deleted_at TEXT NULL
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

    public function testActualMissingReportTableRemainsMissingTable(): void
    {
        Db::execute('ALTER TABLE "ai_daily_reports" RENAME TO "ai_daily_reports_missing"');
        try {
            $result = (new AiDailyReportService())->list([80], 80);
        } finally {
            Db::execute('ALTER TABLE "ai_daily_reports_missing" RENAME TO "ai_daily_reports"');
        }

        self::assertSame('missing_table', $result['data_status']);
        self::assertSame('ai_daily_reports_table_missing', $result['data_gaps'][0]['code']);
        self::assertArrayNotHasKey('status', $result);
    }

    public function testBrokenReportViewReturnsBlockedReadFailureForListLatestAndRead(): void
    {
        Db::execute('ALTER TABLE "ai_daily_reports" RENAME TO "ai_daily_reports_healthy"');
        Db::execute('CREATE VIEW "ai_daily_reports" AS SELECT * FROM "ai_daily_reports_missing_dependency"');
        try {
            $service = new AiDailyReportService();
            $list = $service->list([80], 80);
            $latest = $service->latest([80], 80);
            $read = $service->read(1, [80]);
        } finally {
            Db::execute('DROP VIEW "ai_daily_reports"');
            Db::execute('ALTER TABLE "ai_daily_reports_healthy" RENAME TO "ai_daily_reports"');
        }

        foreach (['list' => $list, 'latest' => $latest, 'read' => $read] as $stage => $result) {
            self::assertIsArray($result);
            self::assertSame('blocked', $result['status']);
            self::assertSame('read_failed', $result['data_status']);
            self::assertSame('ai_daily_reports_read_failed', $result['reason_code']);
            self::assertSame($stage, $result['stage']);
            self::assertSame('read_failed', $result['data_gaps'][0]['data_status']);
        }
        self::assertNull($list['pagination']['total']);
        self::assertNull($latest['report']);
    }
}
