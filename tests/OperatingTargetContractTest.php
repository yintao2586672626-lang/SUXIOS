<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperatingTargetService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

/**
 * API-facing contract for the whole-hotel operating-target service.
 *
 * This suite deliberately uses an isolated SQLite database. It proves the
 * persistence and source-scope contracts without writing a business database
 * or calling an OTA/PMS/Webhook integration.
 */
final class OperatingTargetContractTest extends TestCase
{
    /** @var array<string, mixed> */
    private static array $databaseConfig;
    private static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . '/operating_target_contract_' . getmypid() . '.sqlite';
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
        Db::execute('CREATE UNIQUE INDEX IF NOT EXISTS uq_operating_target_contract ON operating_target_daily_records (tenant_id, hotel_id, target_date)');
        Db::execute('CREATE TABLE IF NOT EXISTS operating_target_daily_snapshots (id INTEGER PRIMARY KEY AUTOINCREMENT, record_id INTEGER NOT NULL, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, target_date VARCHAR(10) NOT NULL, revision_no INTEGER NOT NULL, change_reason VARCHAR(500) NULL, snapshot_json TEXT NOT NULL, created_by INTEGER NULL, create_time DATETIME NOT NULL)');
        Db::execute('CREATE UNIQUE INDEX IF NOT EXISTS uq_operating_target_snapshot_contract ON operating_target_daily_snapshots (record_id, revision_no)');
        Db::execute('CREATE TABLE IF NOT EXISTS daily_reports (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NULL, hotel_id INTEGER NOT NULL, report_date VARCHAR(10) NOT NULL, report_data TEXT NULL, revenue NUMERIC NULL)');

        Db::name('operating_target_daily_snapshots')->delete(true);
        Db::name('operating_target_daily_records')->delete(true);
        Db::name('daily_reports')->delete(true);
    }

    public function testSameHotelDateSaveReadbackUsesOneCurrentRecordAndAppendOnlySnapshots(): void
    {
        $service = new OperatingTargetService();
        $first = $service->save(9, 80, 7, [
            'target_date' => '2026-07-26',
            'target_revenue' => 10000,
            'actual_revenue' => 4000,
            'sold_room_nights' => 20,
            'sellable_room_nights' => 40,
            'source_type' => 'manual',
            'quality_status' => 'manual_confirmed',
        ]);
        $second = $service->save(9, 80, 7, [
            'target_date' => '2026-07-26',
            'target_revenue' => 10000,
            'actual_revenue' => 6500,
            'sold_room_nights' => 26,
            'sellable_room_nights' => 40,
            'source_type' => 'manual',
            'quality_status' => 'manual_confirmed',
        ]);

        self::assertSame($first['record']['id'], $second['record']['id']);
        self::assertSame(2, $second['revision_no']);
        self::assertSame(1, (int)Db::name('operating_target_daily_records')->count());
        self::assertSame(2, (int)Db::name('operating_target_daily_snapshots')->count());

        $readback = $service->current(9, 80, '2026-07-26');
        self::assertSame('ready', $readback['status']);
        self::assertSame(6500.0, $readback['record']['facts']['actual_revenue']);
        self::assertSame(65.0, $readback['record']['calculation']['metrics']['completion_rate_percent']);
        self::assertSame('preview_only', $readback['report_preview']['delivery_status']);
    }

    public function testMissingRecordHasNoFactOrMetricZeroFallback(): void
    {
        $result = (new OperatingTargetService())->current(9, 80, '2026-07-27');

        self::assertSame('missing', $result['status']);
        self::assertNull($result['record']);
        self::assertNull($result['report_preview']['facts']);
        self::assertNull($result['report_preview']['metrics']);
        self::assertContains('operating_target_record_missing', array_column($result['report_preview']['gaps'], 'code'));
    }

    public function testDailyReportPrefillRequiresExactTenantHotelAndDateAndStaysUnverified(): void
    {
        Db::name('daily_reports')->insertAll([
            [
                'tenant_id' => 10,
                'hotel_id' => 80,
                'report_date' => '2026-07-28',
                'report_data' => json_encode(['revenue' => 8000, 'total_rooms' => 30, 'salable_rooms' => 40]),
                'revenue' => 8000,
            ],
            [
                'tenant_id' => 9,
                'hotel_id' => 81,
                'report_date' => '2026-07-28',
                'report_data' => json_encode(['revenue' => 9000, 'total_rooms' => 31, 'salable_rooms' => 40]),
                'revenue' => 9000,
            ],
        ]);

        $service = new OperatingTargetService();
        $missing = $service->prefillFromDailyReport(9, 80, '2026-07-28');
        self::assertSame('missing', $missing['status']);
        self::assertContains('daily_report_missing', array_column($missing['gaps'], 'code'));

        Db::name('daily_reports')->insert([
            'tenant_id' => 9,
            'hotel_id' => 80,
            'report_date' => '2026-07-28',
            'report_data' => json_encode(['revenue' => 3200, 'total_rooms' => 8, 'salable_rooms' => 20]),
            'revenue' => 3200,
        ]);
        $prefill = $service->prefillFromDailyReport(9, 80, '2026-07-28');

        self::assertSame('unverified', $prefill['status']);
        self::assertSame(3200.0, $prefill['prefill']['actual_revenue']);
        self::assertSame(8, $prefill['prefill']['sold_room_nights']);
        self::assertSame(20, $prefill['prefill']['sellable_room_nights']);
        self::assertSame('whole_hotel', $prefill['prefill']['fact_scope']);
        self::assertSame('unverified', $prefill['prefill']['quality_status']);
    }

    public function testOtaScopeCannotBeSavedAsWholeHotelOperatingFact(): void
    {
        $service = new OperatingTargetService();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('operating_target_scope_invalid');

        try {
            $service->save(9, 80, 7, [
                'target_date' => '2026-07-29',
                'target_revenue' => 10000,
                'actual_revenue' => 2000,
                'sold_room_nights' => 5,
                'sellable_room_nights' => 20,
                'fact_scope' => 'ota_channel',
                'source_type' => 'manual',
                'quality_status' => 'manual_confirmed',
            ]);
        } finally {
            self::assertSame(0, (int)Db::name('operating_target_daily_records')->count());
            self::assertSame(0, (int)Db::name('operating_target_daily_snapshots')->count());
        }
    }

    public function testRouteContractKeepsAllOperatingTargetEndpointsBehindAuth(): void
    {
        $routes = (string)file_get_contents(dirname(__DIR__) . '/route/app.php');
        self::assertMatchesRegularExpression(
            "/Route::group\\('api\\/operating-targets'.*?Route::get\\('\\/history', 'OperatingTarget\\/history'\\);.*?Route::get\\('\\/prefill\\/daily-report', 'OperatingTarget\\/prefillDailyReport'\\);.*?Route::get\\('\\/preview', 'OperatingTarget\\/preview'\\);.*?Route::get\\('\\/current', 'OperatingTarget\\/current'\\);.*?Route::post\\('\\/', 'OperatingTarget\\/save'\\);.*?middleware\\(\\\\app\\\\middleware\\\\Auth::class\\);/s",
            $routes
        );
    }

    public function testLegacyDailyReportWithoutTenantScopeCannotBePrefilled(): void
    {
        Db::execute('DROP TABLE daily_reports');
        Db::execute('CREATE TABLE daily_reports (id INTEGER PRIMARY KEY AUTOINCREMENT, hotel_id INTEGER NOT NULL, report_date VARCHAR(10) NOT NULL, report_data TEXT NULL, revenue NUMERIC NULL)');
        Db::name('daily_reports')->insert([
            'hotel_id' => 80,
            'report_date' => '2026-07-30',
            'report_data' => json_encode(['revenue' => 3200, 'total_rooms' => 8, 'salable_rooms' => 20]),
            'revenue' => 3200,
        ]);
        Db::connect(null, true);

        $prefill = (new OperatingTargetService())->prefillFromDailyReport(9, 80, '2026-07-30');

        self::assertSame('missing', $prefill['status']);
        self::assertNull($prefill['prefill']);
        self::assertContains('daily_report_tenant_scope_unverifiable', array_column($prefill['gaps'], 'code'));
    }
}
