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

    public function testExecutionEvidenceReadFailureBlocksWorkflowWithoutDiscardingReportRow(): void
    {
        Db::execute('CREATE TABLE operation_execution_intents (id INTEGER PRIMARY KEY)');
        try {
            $rows = (new AiDailyReportService())->enrichReportRows([[
                'id' => 91,
                'hotel_id' => 80,
                'report_date' => '2026-08-30',
                'created_by' => 1,
                'cache_hit_count' => 0,
                'summary' => '已保存且仍可阅读的日报',
                'model_status' => 'not_requested',
                'model_message' => '',
                'yesterday_result_json' => '{"metrics":[{"key":"orders","value":8}]}',
                'abnormal_metrics_json' => '[]',
                'competitor_changes_json' => '[]',
                'data_gaps_json' => '[]',
                'recommended_actions_json' => '[{"title":"Review Ctrip room price","action_type":"promotion","can_create_execution_intent":true}]',
                'source_refs_json' => '[{"key":"online_daily_data#80#ctrip#2026-08-30","source":"online_daily_data","system_hotel_id":80,"platform":"ctrip","scope":"ota_channel","data_date":"2026-08-30","date_role":"target","readback_verified":true}]',
                'snapshot_json' => '{"input_trust":{"readback_verified":true},"report_scope":{"hotel_id":80,"report_date":"2026-08-30","source_scope":"ota_channel"}}',
            ]], [80], 80);
        } finally {
            Db::execute('DROP TABLE operation_execution_intents');
        }

        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertTrue($row['result_readiness']['usable']);
        self::assertSame('blocked', $row['execution_evidence']['status']);
        self::assertSame('read_failed', $row['execution_evidence']['data_status']);
        self::assertSame(
            'ai_daily_report_execution_flow_read_failed',
            $row['execution_evidence']['reason_code']
        );
        self::assertSame('blocked', $row['workflow_readiness']['stage']);
        self::assertSame('read_failed', $row['workflow_readiness']['data_status']);
        self::assertFalse($row['recommended_actions'][0]['can_create_execution_intent']);
        self::assertSame('blocked', $row['recommended_actions'][0]['action_readiness']['stage']);
        self::assertSame(
            'read_failed',
            $row['recommended_actions'][0]['action_readiness']['data_status']
        );
    }
}
