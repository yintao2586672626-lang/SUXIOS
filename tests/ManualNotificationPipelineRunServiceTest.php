<?php
declare(strict_types=1);

namespace tests;

use app\service\ManualNotificationPipelineRunService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class ManualNotificationPipelineRunServiceTest extends TestCase
{
    private static array $databaseConfig;
    private static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir()
            . '/manual_notification_pipeline_' . getmypid() . '.sqlite';
        @unlink(self::$databasePath);
        $config = self::$databaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$databasePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($config, 'database');
        Db::connect(null, true);
        Db::execute(
            'CREATE TABLE manual_notification_schedule_runs ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, runner_mode TEXT, '
            . 'dispatch_requested INTEGER, scope_hotel_id INTEGER NULL, '
            . 'scope_robot_id INTEGER NULL, observed_at TEXT, status TEXT, '
            . 'candidate_count INTEGER, due_count INTEGER, sent_count INTEGER, '
            . 'failed_count INTEGER, blocked_count INTEGER, result_summary_json TEXT NULL, '
            . 'started_at TEXT, finished_at TEXT NULL, create_time TEXT, update_time TEXT)'
        );
    }

    public static function tearDownAfterClass(): void
    {
        Config::set(self::$databaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$databasePath);
    }

    protected function setUp(): void
    {
        Db::name('manual_notification_schedule_runs')->delete(true);
    }

    public function testBlockedCollectionRunIsSavedAndReadBack(): void
    {
        $service = new ManualNotificationPipelineRunService();
        $runId = $service->start(
            5,
            2,
            new DateTimeImmutable('2026-07-27 08:00:00')
        );
        $finished = $service->finish($runId, 'blocked', true, [
            'stage' => 'report_gate',
            'reason_code' => 'target_revenue_missing',
            'business_date' => '2026-07-27',
            'candidate_count' => 1,
            'due_count' => 1,
            'blocked_count' => 1,
            'capture_id' => 19,
            'operating_target_record_id' => 7,
        ], new DateTimeImmutable('2026-07-27 08:00:12'));

        self::assertSame('blocked', $finished['status']);
        self::assertTrue($finished['dispatch_requested']);
        self::assertSame(5, $finished['scope_hotel_id']);
        self::assertSame(2, $finished['scope_robot_id']);
        self::assertSame(1, $finished['due_count']);
        self::assertSame(1, $finished['blocked_count']);
        $stored = Db::name('manual_notification_schedule_runs')->where('id', $runId)->find();
        $summary = json_decode((string)$stored['result_summary_json'], true);
        self::assertSame('report_gate', $summary['stage']);
        self::assertSame('target_revenue_missing', $summary['reason_code']);
        self::assertFalse($summary['sensitive_values_exposed']);
    }

    public function testRunSummaryRejectsSensitiveFreeFormMaterial(): void
    {
        $service = new ManualNotificationPipelineRunService();
        $runId = $service->start(
            5,
            2,
            new DateTimeImmutable('2026-07-27 09:00:00')
        );
        $service->finish($runId, 'failed', false, [
            'stage' => 'pipeline_exception',
            'reason_code' => 'webhook=https://secret.example/token-value',
            'unknown_key' => 'must not persist',
            'failed_count' => 1,
        ], new DateTimeImmutable('2026-07-27 09:00:02'));

        $stored = Db::name('manual_notification_schedule_runs')->where('id', $runId)->find();
        $summary = json_decode((string)$stored['result_summary_json'], true);
        self::assertSame('webhook=<redacted>', $summary['reason_code']);
        self::assertArrayNotHasKey('unknown_key', $summary);
        self::assertFalse($summary['sensitive_values_exposed']);
        self::assertSame(1, (int)$stored['failed_count']);
    }
}
