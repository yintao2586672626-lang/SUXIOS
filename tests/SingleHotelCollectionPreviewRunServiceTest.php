<?php
declare(strict_types=1);

namespace Tests;

use app\service\SingleHotelCollectionPreviewRunService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class SingleHotelCollectionPreviewRunServiceTest extends TestCase
{
    private static array $databaseConfig;
    private static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'single_hotel_collection_run_' . getmypid() . '.sqlite';
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
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Db::connect()->close();
        } catch (\Throwable) {
        }
        Config::set(self::$databaseConfig, 'database');
        Db::connect(null, true);
        if (is_file(self::$databasePath) && !unlink(self::$databasePath)) {
            throw new RuntimeException('Unable to remove collection run fixture.');
        }
    }

    protected function setUp(): void
    {
        Db::execute('DROP TABLE IF EXISTS manual_notification_schedule_runs');
        Db::execute('CREATE TABLE manual_notification_schedule_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            runner_mode VARCHAR(16) NOT NULL,
            dispatch_requested INTEGER NOT NULL,
            scope_hotel_id INTEGER NULL,
            scope_robot_id INTEGER NULL,
            observed_at TEXT NOT NULL,
            status VARCHAR(32) NOT NULL,
            candidate_count INTEGER NOT NULL,
            due_count INTEGER NOT NULL,
            sent_count INTEGER NOT NULL,
            failed_count INTEGER NOT NULL,
            blocked_count INTEGER NOT NULL,
            result_summary_json TEXT NULL,
            started_at TEXT NOT NULL,
            finished_at TEXT NULL,
            create_time TEXT NOT NULL,
            update_time TEXT NOT NULL
        )');
    }

    public function testCompletedPartialPreviewIsReadBackWithoutDispatchOrRobot(): void
    {
        $service = new SingleHotelCollectionPreviewRunService();
        $observedAt = new DateTimeImmutable(
            '2026-07-28 01:35:00',
            new DateTimeZone('Asia/Shanghai')
        );
        $runId = $service->start(5, $observedAt);
        $result = $service->finish($runId, 'completed', 'partial', [
            'stage' => 'three_source_preview',
            'business_date' => '2026-07-28',
            'capture_id' => 12,
            'preview_fingerprint' => str_repeat('a', 64),
            'pms_status' => 'ready',
            'ctrip_status' => 'ready',
            'meituan_status' => 'partial',
            'pms_evidence_ready' => true,
            'ctrip_evidence_ready' => true,
            'meituan_evidence_ready' => true,
            'pms_capture_ids' => [12],
            'pms_captured_at' => '2026-07-28 01:35:10',
            'ctrip_row_ids' => [701],
            'ctrip_data_source_ids' => [5],
            'ctrip_source_trace_ids' => ['ctrip-trace-701'],
            'ctrip_collected_at' => '2026-07-28 01:35:12',
            'meituan_row_ids' => [823, 824],
            'meituan_data_source_ids' => [6],
            'meituan_source_trace_ids' => [
                'meituan-traffic-823',
                'meituan-order-824',
            ],
            'meituan_traffic_collected_at' => '2026-07-28 01:35:15',
            'meituan_order_collected_at' => '2026-07-28 01:35:16',
            'gap_codes' => ['meituan_room_revenue_missing'],
        ], $observedAt->modify('+30 seconds'));

        self::assertSame('collection_only', $result['runner_mode']);
        self::assertSame('completed', $result['status']);
        self::assertSame('partial', $result['preview_status']);
        self::assertFalse($result['dispatch_requested']);
        self::assertNull($result['scope_robot_id']);
        self::assertSame(0, $result['sent_count']);
        self::assertFalse($result['message_sent']);
        self::assertFalse($result['webhook_read']);
        $row = Db::name('manual_notification_schedule_runs')->find($runId);
        self::assertSame(0, (int)$row['dispatch_requested']);
        self::assertSame(0, (int)$row['sent_count']);
        self::assertNull($row['scope_robot_id']);
        $summary = json_decode(
            (string)$row['result_summary_json'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertTrue($summary['pms_evidence_ready']);
        self::assertTrue($summary['ctrip_evidence_ready']);
        self::assertTrue($summary['meituan_evidence_ready']);
        self::assertSame([12], $summary['pms_capture_ids']);
        self::assertSame([701], $summary['ctrip_row_ids']);
        self::assertSame([5], $summary['ctrip_data_source_ids']);
        self::assertSame(['ctrip-trace-701'], $summary['ctrip_source_trace_ids']);
        self::assertSame([823, 824], $summary['meituan_row_ids']);
        self::assertSame([6], $summary['meituan_data_source_ids']);
        self::assertSame(
            ['meituan-traffic-823', 'meituan-order-824'],
            $summary['meituan_source_trace_ids']
        );
    }

    public function testBlockedPreviewIsNotMisreportedAsTechnicalFailure(): void
    {
        $service = new SingleHotelCollectionPreviewRunService();
        $observedAt = new DateTimeImmutable(
            '2026-07-28 02:35:00',
            new DateTimeZone('Asia/Shanghai')
        );
        $runId = $service->start(5, $observedAt);
        $result = $service->finish($runId, 'completed', 'blocked', [
            'stage' => 'three_source_preview',
            'blocker_codes' => ['pms_delivery_evidence_missing'],
        ], $observedAt->modify('+20 seconds'));

        self::assertSame('completed', $result['status']);
        self::assertSame('blocked', $result['preview_status']);
        self::assertSame(0, $result['failed_count']);
        self::assertSame(1, $result['blocked_count']);
    }
}
