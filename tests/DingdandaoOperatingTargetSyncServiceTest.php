<?php
declare(strict_types=1);

namespace Tests;

use app\service\DingdandaoOperatingTargetSyncService;
use app\service\DingdandaoPmsIntegrationService;
use app\service\OperatingTargetService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class DingdandaoOperatingTargetSyncServiceTest extends TestCase
{
    private static array $databaseConfig;
    private static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . '/pms_target_sync_' . getmypid() . '.sqlite';
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
        self::createSchema();
    }

    public static function tearDownAfterClass(): void
    {
        Config::set(self::$databaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$databasePath);
    }

    protected function setUp(): void
    {
        Db::name('operating_target_daily_snapshots')->delete(true);
        Db::name('operating_target_daily_records')->delete(true);
        Db::name('dingdandao_room_fee_capture_details')->delete(true);
        Db::name('dingdandao_operating_target_captures')->delete(true);
        Db::name('dingdandao_pms_integrations')->delete(true);
        Db::name('dingdandao_pms_integrations')->insert([
            'tenant_id' => 8,
            'hotel_id' => 5,
            'provider' => 'dingdandao_pms',
            'provider_hotel_id' => 'provider-hotel-5',
            'provider_hotel_name' => 'provider-hotel-name',
            'status' => 1,
            'auto_push_enabled' => 0,
            'create_time' => '2026-07-28 08:00:00',
            'update_time' => '2026-07-28 08:00:00',
        ]);
    }

    public function testVerifiedCapturePreservesGoalsAndSyncsIdempotently(): void
    {
        (new OperatingTargetService())->save(8, 5, 7, [
            'target_date' => '2026-07-28',
            'target_revenue' => 12000,
            'target_occupancy_rate_percent' => 85,
            'target_revpar' => 650,
            'fact_scope' => 'accommodation_room_fee',
            'source_type' => 'manual',
            'quality_status' => 'manual_confirmed',
        ]);
        $captureId = $this->insertVerifiedCapture();

        $service = new DingdandaoOperatingTargetSyncService();
        $first = $service->syncVerifiedCapture(8, 5, 7, $captureId);
        $second = $service->syncVerifiedCapture(8, 5, 7, $captureId);

        self::assertSame('updated', $first['sync_status']);
        self::assertSame('idempotent', $second['sync_status']);
        self::assertSame(2, (int)Db::name('operating_target_daily_snapshots')->count());
        $current = (new OperatingTargetService())->current(8, 5, '2026-07-28');
        self::assertSame(12000.0, $current['record']['facts']['target_revenue']);
        self::assertSame(85.0, $current['record']['facts']['target_occupancy_rate_percent']);
        self::assertSame(650.0, $current['record']['facts']['target_revpar']);
        self::assertSame(10135.29, $current['record']['facts']['actual_revenue']);
        self::assertSame('pms', $current['record']['facts']['source_type']);
        self::assertSame('verified', $current['record']['facts']['quality_status']);
    }

    public function testSyncReadsGoalsOnlyAfterTheTargetLockInsideItsTransaction(): void
    {
        $targets = new OperatingTargetService();
        $targets->save(8, 5, 7, [
            'target_date' => '2026-07-28',
            'target_revenue' => 12000,
            'target_occupancy_rate_percent' => 80,
            'target_revpar' => 600,
            'fact_scope' => 'accommodation_room_fee',
            'source_type' => 'manual',
            'quality_status' => 'manual_confirmed',
        ]);
        $captureId = $this->insertVerifiedCapture();
        $lockObservedInsideTransaction = false;

        $service = new DingdandaoOperatingTargetSyncService(
            null,
            $targets,
            static function (int $tenantId, int $hotelId, string $targetDate) use (
                $targets,
                &$lockObservedInsideTransaction
            ): void {
                self::assertSame(8, $tenantId);
                self::assertSame(5, $hotelId);
                self::assertSame('2026-07-28', $targetDate);
                $lockObservedInsideTransaction = Db::connect()->getPdo()->inTransaction();

                // Simulates a user save that became visible at the lock boundary.
                $targets->save(8, 5, 9, [
                    'target_date' => '2026-07-28',
                    'target_revenue' => 15000,
                    'target_occupancy_rate_percent' => 88,
                    'target_revpar' => 720,
                    'fact_scope' => 'accommodation_room_fee',
                    'source_type' => 'manual',
                    'quality_status' => 'manual_confirmed',
                    'change_reason' => 'user goal update at sync lock boundary',
                ]);
            }
        );

        $result = $service->syncVerifiedCapture(8, 5, 7, $captureId);

        self::assertTrue($lockObservedInsideTransaction);
        self::assertSame('updated', $result['sync_status']);
        $current = $targets->current(8, 5, '2026-07-28');
        self::assertSame(15000.0, $current['record']['facts']['target_revenue']);
        self::assertSame(88.0, $current['record']['facts']['target_occupancy_rate_percent']);
        self::assertSame(720.0, $current['record']['facts']['target_revpar']);
        self::assertSame(10135.29, $current['record']['facts']['actual_revenue']);
        self::assertSame(3, (int)Db::name('operating_target_daily_snapshots')->count());
    }

    public function testCurrentBindingMismatchBlocksBeforeTargetMutation(): void
    {
        $targets = new OperatingTargetService();
        $targets->save(8, 5, 7, [
            'target_date' => '2026-07-28',
            'target_revenue' => 12000,
            'fact_scope' => 'accommodation_room_fee',
            'source_type' => 'manual',
            'quality_status' => 'manual_confirmed',
        ]);
        $captureId = $this->insertVerifiedCapture();
        Db::name('dingdandao_pms_integrations')->where('tenant_id', 8)->update([
            'provider_hotel_id' => 'provider-hotel-b',
            'provider_hotel_name' => 'hotel-b',
            'update_time' => '2026-07-28 08:20:00',
        ]);

        $result = (new DingdandaoPmsIntegrationService())
            ->syncVerifiedCapture(8, 5, 7, $captureId);

        self::assertSame('blocked', $result['sync_status']);
        self::assertContains(
            'pms_provider_hotel_id_mismatch',
            array_column($result['gaps'], 'code')
        );
        $current = $targets->current(8, 5, '2026-07-28');
        self::assertSame(12000.0, $current['record']['facts']['target_revenue']);
        self::assertNull($current['record']['facts']['actual_revenue']);
        self::assertSame(1, (int)Db::name('operating_target_daily_snapshots')->count());
    }

    private function insertVerifiedCapture(): int
    {
        $now = '2026-07-28 08:10:00';
        return (int)Db::name('dingdandao_operating_target_captures')->insertGetId([
            'tenant_id' => 8,
            'hotel_id' => 5,
            'provider' => 'dingdandao_pms',
            'provider_hotel_id' => 'provider-hotel-5',
            'provider_hotel_name' => 'provider-hotel-name',
            'expected_hotel_name' => 'provider-hotel-name',
            'identity_evidence_type' => 'verified_api_store_identity',
            'identity_status' => 'matched',
            'source_url' => 'https://www.dingdandao.com/pmsManage/report/pro/dataCenter/accommodationData',
            'source_api_path' => '/api/verified-read',
            'source_scope' => 'today_only',
            'capture_method' => 'network_response',
            'business_date' => '2026-07-28',
            'total_room_fee' => 10135.29,
            'adr' => 633.46,
            'occupancy_rate_percent' => 100,
            'revpar' => 633.46,
            'sold_room_nights' => 16,
            'average_daily_room_nights' => 16,
            'derived_sellable_room_nights' => 16,
            'detail_room_fee_total' => 10135.29,
            'detail_row_count' => 0,
            'reconciliation_status' => 'matched',
            'capture_status' => 'verified',
            'quality_status' => 'verified',
            'quality_reason' => 'verified fixture',
            'gap_codes_json' => '[]',
            'trend_json' => '[]',
            'field_trace_json' => '{}',
            'snapshot_json' => '{}',
            'source_fingerprint' => str_repeat('a', 64),
            'captured_at' => $now,
            'captured_by' => 7,
            'readback_status' => 'readback_verified',
            'readback_verified_at' => $now,
            'create_time' => $now,
            'update_time' => $now,
        ]);
    }

    private static function createSchema(): void
    {
        Db::execute(
            'CREATE TABLE dingdandao_operating_target_captures ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, provider TEXT, '
            . 'provider_hotel_id TEXT NULL, provider_hotel_name TEXT NULL, expected_hotel_name TEXT, '
            . 'identity_evidence_type TEXT, identity_status TEXT, source_url TEXT, source_api_path TEXT NULL, '
            . 'source_scope TEXT, capture_method TEXT, business_date TEXT, total_room_fee REAL NULL, adr REAL NULL, '
            . 'occupancy_rate_percent REAL NULL, revpar REAL NULL, sold_room_nights INTEGER NULL, '
            . 'average_daily_room_nights REAL NULL, derived_sellable_room_nights INTEGER NULL, '
            . 'detail_room_fee_total REAL NULL, detail_row_count INTEGER, reconciliation_status TEXT, '
            . 'capture_status TEXT, quality_status TEXT, quality_reason TEXT NULL, gap_codes_json TEXT NULL, '
            . 'trend_json TEXT NULL, field_trace_json TEXT NULL, snapshot_json TEXT, source_fingerprint TEXT, '
            . 'captured_at TEXT, captured_by INTEGER NULL, readback_status TEXT, readback_verified_at TEXT NULL, '
            . 'create_time TEXT, update_time TEXT)'
        );
        Db::execute(
            'CREATE TABLE dingdandao_room_fee_capture_details ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, capture_id INTEGER, tenant_id INTEGER, hotel_id INTEGER, '
            . 'business_date TEXT, row_kind TEXT, room_type TEXT NULL, room_number TEXT NULL, room_fee REAL, '
            . 'source_row_index INTEGER, create_time TEXT)'
        );
        Db::execute(
            'CREATE TABLE dingdandao_pms_integrations ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, provider TEXT, '
            . 'provider_hotel_id TEXT NULL, provider_hotel_name TEXT NULL, status INTEGER, '
            . 'auto_push_enabled INTEGER, create_time TEXT, update_time TEXT)'
        );
        Db::execute(
            'CREATE TABLE operating_target_daily_records ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, target_date TEXT, '
            . 'target_revenue REAL NULL, target_occupancy_rate_percent REAL NULL, target_revpar REAL NULL, '
            . 'actual_revenue REAL NULL, sold_room_nights INTEGER NULL, sellable_room_nights INTEGER NULL, '
            . 'fact_scope TEXT, source_type TEXT, source_reference TEXT NULL, quality_status TEXT, '
            . 'quality_reason TEXT NULL, fact_captured_at TEXT NULL, calculation_status TEXT, '
            . 'gap_codes_json TEXT NULL, calculation_json TEXT NULL, report_status TEXT, created_by INTEGER NULL, '
            . 'updated_by INTEGER NULL, create_time TEXT, update_time TEXT)'
        );
        Db::execute(
            'CREATE UNIQUE INDEX uq_operating_target_sync '
            . 'ON operating_target_daily_records (tenant_id, hotel_id, target_date)'
        );
        Db::execute(
            'CREATE TABLE operating_target_daily_snapshots ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, record_id INTEGER, tenant_id INTEGER, hotel_id INTEGER, '
            . 'target_date TEXT, revision_no INTEGER, change_reason TEXT NULL, snapshot_json TEXT, '
            . 'created_by INTEGER NULL, create_time TEXT)'
        );
    }
}
