<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperatingTargetService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OperatingTargetServiceTest extends TestCase
{
    private static array $databaseConfig;
    private static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . '/operating_target_' . getmypid() . '.sqlite';
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
        Config::set(self::$databaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$databasePath);
    }

    protected function setUp(): void
    {
        Db::execute('CREATE TABLE IF NOT EXISTS operating_target_daily_records (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, target_date VARCHAR(10) NOT NULL, target_revenue NUMERIC NULL, actual_revenue NUMERIC NULL, sold_room_nights INTEGER NULL, sellable_room_nights INTEGER NULL, fact_scope VARCHAR(32) NOT NULL, source_type VARCHAR(32) NOT NULL, source_reference VARCHAR(255) NULL, quality_status VARCHAR(32) NOT NULL, quality_reason VARCHAR(255) NULL, fact_captured_at DATETIME NULL, calculation_status VARCHAR(32) NOT NULL, gap_codes_json TEXT NULL, calculation_json TEXT NULL, report_status VARCHAR(32) NOT NULL, created_by INTEGER NULL, updated_by INTEGER NULL, create_time DATETIME NOT NULL, update_time DATETIME NOT NULL)');
        Db::execute('CREATE UNIQUE INDEX IF NOT EXISTS uq_operating_target_test ON operating_target_daily_records (tenant_id, hotel_id, target_date)');
        Db::execute('CREATE TABLE IF NOT EXISTS operating_target_daily_snapshots (id INTEGER PRIMARY KEY AUTOINCREMENT, record_id INTEGER NOT NULL, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, target_date VARCHAR(10) NOT NULL, revision_no INTEGER NOT NULL, change_reason VARCHAR(500) NULL, snapshot_json TEXT NOT NULL, created_by INTEGER NULL, create_time DATETIME NOT NULL)');
        Db::execute('CREATE UNIQUE INDEX IF NOT EXISTS uq_operating_target_snapshot_test ON operating_target_daily_snapshots (record_id, revision_no)');
        Db::execute('CREATE TABLE IF NOT EXISTS daily_reports (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NULL, hotel_id INTEGER NOT NULL, report_date VARCHAR(10) NOT NULL, report_data TEXT NULL, revenue NUMERIC NULL)');
        Db::name('operating_target_daily_snapshots')->delete(true);
        Db::name('operating_target_daily_records')->delete(true);
        Db::name('daily_reports')->delete(true);
    }

    public function testSaveCalculatesAndReadsBackWholeHotelTarget(): void
    {
        $service = new OperatingTargetService();
        $saved = $service->save(9, 80, 7, [
            'target_date' => '2026-07-26',
            'target_revenue' => 10000,
            'actual_revenue' => 4000,
            'sold_room_nights' => 20,
            'sellable_room_nights' => 40,
            'source_type' => 'manual',
            'quality_status' => 'manual_confirmed',
            'change_reason' => '首次录入',
        ]);

        self::assertSame('ready', $saved['status']);
        self::assertSame(40.0, $saved['record']['calculation']['metrics']['completion_rate_percent']);
        self::assertSame(6000.0, $saved['record']['calculation']['metrics']['remaining_revenue']);
        self::assertSame(20, $saved['record']['calculation']['metrics']['remaining_sellable_room_nights']);
        self::assertSame(300.0, $saved['record']['calculation']['metrics']['required_average_rate']);
        self::assertSame(50.0, $saved['record']['calculation']['metrics']['selling_progress_percent']);
        self::assertSame(1, $saved['revision_no']);

        $readback = $service->current(9, 80, '2026-07-26');
        self::assertSame('ready', $readback['status']);
        self::assertSame(4000.0, $readback['record']['facts']['actual_revenue']);
        self::assertSame('preview_only', $readback['report_preview']['delivery_status']);

        $updated = $service->save(9, 80, 7, [
            'target_date' => '2026-07-26',
            'target_revenue' => 10000,
            'actual_revenue' => 6000,
            'sold_room_nights' => 25,
            'sellable_room_nights' => 40,
            'source_type' => 'manual',
            'quality_status' => 'manual_confirmed',
            'change_reason' => '更新实绩',
        ]);
        self::assertSame(2, $updated['revision_no']);
        self::assertSame(60.0, $updated['record']['calculation']['metrics']['completion_rate_percent']);
        self::assertSame(2, (int)Db::name('operating_target_daily_snapshots')->count());
    }

    public function testMissingAndUnverifiedFactsDoNotBecomeZeroResults(): void
    {
        $service = new OperatingTargetService();
        $missing = $service->save(9, 80, 7, [
            'target_date' => '2026-07-27',
            'target_revenue' => 10000,
            'actual_revenue' => null,
            'source_type' => 'pms',
            'quality_status' => 'collection_failed',
        ]);
        self::assertSame('partial', $missing['status']);
        self::assertNull($missing['record']['calculation']['metrics']['completion_rate_percent']);
        self::assertNull($missing['record']['calculation']['metrics']['remaining_revenue']);
        self::assertContains('actual_revenue_missing', array_column($missing['record']['calculation']['gaps'], 'code'));

        $unverifiedZero = $service->save(9, 80, 7, [
            'target_date' => '2026-07-28',
            'target_revenue' => 10000,
            'actual_revenue' => 0,
            'sold_room_nights' => 0,
            'sellable_room_nights' => 40,
            'source_type' => 'daily_report',
            'quality_status' => 'unverified',
        ]);
        self::assertSame('partial', $unverifiedZero['status']);
        self::assertNull($unverifiedZero['record']['calculation']['metrics']['completion_rate_percent']);
        self::assertNull($unverifiedZero['record']['calculation']['metrics']['selling_progress_percent']);
        self::assertContains('fact_quality_unverified', array_column($unverifiedZero['record']['calculation']['gaps'], 'code'));
    }

    public function testInconsistentRoomNightsBlockDerivedMetrics(): void
    {
        $saved = (new OperatingTargetService())->save(9, 80, 7, [
            'target_date' => '2026-07-29',
            'target_revenue' => 10000,
            'actual_revenue' => 5000,
            'sold_room_nights' => 41,
            'sellable_room_nights' => 40,
            'source_type' => 'manual',
            'quality_status' => 'manual_confirmed',
        ]);
        self::assertSame('blocked', $saved['status']);
        self::assertNull($saved['record']['calculation']['metrics']['selling_progress_percent']);
        self::assertContains('input_inconsistent', array_column($saved['record']['calculation']['gaps'], 'code'));
    }

    public function testPrefillReadsOnlySameHotelSameDateDailyReportAsUnverified(): void
    {
        Db::name('daily_reports')->insert([
            'tenant_id' => 9,
            'hotel_id' => 80,
            'report_date' => '2026-07-30',
            'report_data' => json_encode([
                'revenue' => 3200,
                'total_rooms' => 8,
                'salable_rooms' => 20,
            ], JSON_UNESCAPED_UNICODE),
            'revenue' => 3200,
        ]);
        Db::name('daily_reports')->insert([
            'tenant_id' => 9,
            'hotel_id' => 81,
            'report_date' => '2026-07-30',
            'report_data' => json_encode(['revenue' => 9999]),
            'revenue' => 9999,
        ]);

        $prefill = (new OperatingTargetService())->prefillFromDailyReport(9, 80, '2026-07-30');
        self::assertSame('unverified', $prefill['status']);
        self::assertSame(3200.0, $prefill['prefill']['actual_revenue']);
        self::assertSame(8, $prefill['prefill']['sold_room_nights']);
        self::assertSame(20, $prefill['prefill']['sellable_room_nights']);
        self::assertSame('daily_report', $prefill['prefill']['source_type']);
        self::assertSame('unverified', $prefill['prefill']['quality_status']);
    }
}
