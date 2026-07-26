<?php
declare(strict_types=1);

namespace tests;

use app\service\DingdandaoOperatingTargetCaptureService;
use app\service\DingdandaoOperatingTargetSyncService;
use app\service\OperatingTargetService;
use DateTimeImmutable;
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
        $app = new App();
        $app->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir()
            . '/dingdandao_target_sync_' . getmypid() . '.sqlite';
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
    }

    public function testVerifiedCaptureCreatesPartialTargetAndIsIdempotent(): void
    {
        $capture = $this->verifiedCapture();
        $service = new DingdandaoOperatingTargetSyncService(
            $this->captureService(),
            new OperatingTargetService()
        );

        $first = $service->syncVerifiedCapture(8, 5, 7, (int)$capture['id']);
        $second = $service->syncVerifiedCapture(8, 5, 7, (int)$capture['id']);

        self::assertSame('partial', $first['status']);
        self::assertFalse($first['send_eligible']);
        self::assertSame('created', $first['sync_status']);
        self::assertContains('target_revenue_missing', array_column($first['gaps'], 'code'));
        self::assertSame('idempotent', $second['sync_status']);
        self::assertSame(1, (int)Db::name('operating_target_daily_records')->count());
        self::assertSame(1, (int)Db::name('operating_target_daily_snapshots')->count());

        $current = (new OperatingTargetService())->current(8, 5, '2026-07-27');
        self::assertNull($current['record']['facts']['target_revenue']);
        self::assertSame(10135.29, $current['record']['facts']['actual_revenue']);
        self::assertSame(16, $current['record']['facts']['sold_room_nights']);
        self::assertSame(16, $current['record']['facts']['sellable_room_nights']);
        self::assertSame('accommodation_room_fee', $current['record']['facts']['fact_scope']);
        self::assertSame('pms', $current['record']['facts']['source_type']);
        self::assertSame('verified', $current['record']['facts']['quality_status']);
    }

    public function testVerifiedCapturePreservesAccommodationTargetAndBecomesReady(): void
    {
        (new OperatingTargetService())->save(8, 5, 7, [
            'target_date' => '2026-07-27',
            'target_revenue' => 10000,
            'actual_revenue' => null,
            'sold_room_nights' => null,
            'sellable_room_nights' => null,
            'fact_scope' => 'accommodation_room_fee',
            'source_type' => 'manual',
            'quality_status' => 'manual_confirmed',
            'change_reason' => '人工设置住宿房费目标',
        ]);
        $capture = $this->verifiedCapture();

        $result = (new DingdandaoOperatingTargetSyncService(
            $this->captureService(),
            new OperatingTargetService()
        ))->syncVerifiedCapture(8, 5, 7, (int)$capture['id']);

        self::assertSame('ready', $result['status']);
        self::assertTrue($result['send_eligible']);
        self::assertSame('updated', $result['sync_status']);
        self::assertSame(2, $result['revision_no']);
        $current = (new OperatingTargetService())->current(8, 5, '2026-07-27');
        self::assertSame(10000.0, $current['record']['facts']['target_revenue']);
        self::assertSame(10135.29, $current['record']['facts']['actual_revenue']);
        self::assertSame(101.35, $current['record']['calculation']['metrics']['completion_rate_percent']);
        self::assertSame(2, (int)Db::name('operating_target_daily_snapshots')->count());
    }

    public function testWholeHotelTargetIsNotMixedWithAccommodationRoomFee(): void
    {
        (new OperatingTargetService())->save(8, 5, 7, [
            'target_date' => '2026-07-27',
            'target_revenue' => 15000,
            'actual_revenue' => 12000,
            'sold_room_nights' => 16,
            'sellable_room_nights' => 20,
            'fact_scope' => 'whole_hotel',
            'source_type' => 'manual',
            'quality_status' => 'manual_confirmed',
        ]);
        $capture = $this->verifiedCapture();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('dingdandao_target_scope_mismatch');
        try {
            (new DingdandaoOperatingTargetSyncService(
                $this->captureService(),
                new OperatingTargetService()
            ))->syncVerifiedCapture(8, 5, 7, (int)$capture['id']);
        } finally {
            self::assertSame(1, (int)Db::name('operating_target_daily_snapshots')->count());
            $current = (new OperatingTargetService())->current(8, 5, '2026-07-27');
            self::assertSame('whole_hotel', $current['record']['facts']['fact_scope']);
            self::assertSame(12000.0, $current['record']['facts']['actual_revenue']);
        }
    }

    /** @return array<string,mixed> */
    private function verifiedCapture(): array
    {
        return $this->captureService()->save(
            8,
            5,
            7,
            '敦煌漠蓝新',
            $this->validInput(),
            true,
            'provider-hotel-5'
        );
    }

    private function captureService(): DingdandaoOperatingTargetCaptureService
    {
        return new DingdandaoOperatingTargetCaptureService(
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-07-27 08:10:00')
        );
    }

    /** @return array<string,mixed> */
    private function validInput(): array
    {
        $details = [];
        for ($index = 0; $index < 16; $index++) {
            $details[] = [
                'row_kind' => 'room',
                'room_type' => '大床房',
                'room_number' => 'R' . ($index + 1),
                'room_fee' => $index === 15 ? 633.39 : 633.46,
            ];
        }
        $details[] = [
            'row_kind' => 'grand_total',
            'room_type' => null,
            'room_number' => null,
            'room_fee' => 10135.29,
        ];
        $summary = [
            'total_room_fee' => 10135.29,
            'adr' => 633.46,
            'occupancy_rate_percent' => 100,
            'revpar' => 633.46,
            'sold_room_nights' => 16,
            'average_daily_room_nights' => 16,
        ];
        return [
            'source_url' => DingdandaoOperatingTargetCaptureService::SOURCE_URL,
            'source_api_path' => '/api/verified-read',
            'source_scope' => DingdandaoOperatingTargetCaptureService::SOURCE_SCOPE,
            'capture_method' => 'network_response',
            'captured_at' => '2026-07-27 08:05:00',
            'business_date' => '2026-07-27',
            'provider_hotel_id' => 'provider-hotel-5',
            'provider_hotel_name' => '敦煌漠蓝新',
            'identity_evidence_type' => 'verified_api_store_identity',
            'summary' => $summary,
            'room_fee_details' => $details,
            'trend' => [],
            'field_trace' => array_fill_keys(array_keys($summary), 'API:/api/verified-read'),
        ];
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
            'CREATE TABLE operating_target_daily_records ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, target_date TEXT, '
            . 'target_revenue REAL NULL, actual_revenue REAL NULL, sold_room_nights INTEGER NULL, '
            . 'sellable_room_nights INTEGER NULL, fact_scope TEXT, source_type TEXT, source_reference TEXT NULL, '
            . 'quality_status TEXT, quality_reason TEXT NULL, fact_captured_at TEXT NULL, '
            . 'calculation_status TEXT, gap_codes_json TEXT NULL, calculation_json TEXT NULL, '
            . 'report_status TEXT, created_by INTEGER NULL, updated_by INTEGER NULL, '
            . 'create_time TEXT, update_time TEXT)'
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
