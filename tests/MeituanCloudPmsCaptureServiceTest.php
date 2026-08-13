<?php
declare(strict_types=1);

namespace tests;

use app\service\MeituanCloudPmsCaptureService;
use app\service\MeituanCloudPmsIntegrationService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class MeituanCloudPmsCaptureServiceTest extends TestCase
{
    private static array $databaseConfig;
    private static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir()
            . '/meituan_cloud_pms_capture_' . getmypid() . '.sqlite';
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
        Db::name('meituan_cloud_pms_room_type_details')->delete(true);
        Db::name('meituan_cloud_pms_captures')->delete(true);
        Db::name('meituan_cloud_pms_integrations')->delete(true);
    }

    public function testManualSubmissionCannotSelfAttestAsVerified(): void
    {
        $service = $this->service();
        $capture = $service->save(
            8,
            80,
            7,
            '敦煌漠蓝新',
            $this->validInput()
        );

        self::assertSame('identity_unverified', $capture['capture_status']);
        self::assertSame('unverified', $capture['quality_status']);
        self::assertSame('readback_verified', $capture['readback_status']);
        self::assertContains(
            'meituan_cloud_trusted_collection_required',
            array_column($capture['gaps'], 'code')
        );
    }

    public function testTrustedCaptureSavesReadsBackAndPrefillsExactFacts(): void
    {
        $service = $this->service();
        $capture = $service->save(
            8,
            80,
            7,
            '敦煌漠蓝新',
            $this->validInput(),
            true,
            'mt-hotel-80'
        );
        $prefill = $service->prefill(8, 80, '2026-07-28');

        self::assertSame('verified', $capture['capture_status']);
        self::assertSame('verified', $capture['quality_status']);
        self::assertSame('matched', $capture['identity_status']);
        self::assertSame('matched', $capture['date_status']);
        self::assertSame('matched', $capture['reconciliation_status']);
        self::assertSame('readback_verified', $capture['readback_status']);
        self::assertSame(
            'API:/hotelpms/api/v1/property/hotel/getHotelInfo#data.hotelName+data.hotelId',
            $capture['field_trace']['provider_hotel_identity'] ?? null
        );
        self::assertSame(2, $capture['room_type_count']);
        self::assertSame(0, $capture['availability_difference']);
        self::assertSame('verified', $prefill['status']);
        self::assertSame(600.0, $prefill['prefill']['actual_revenue']);
        self::assertSame(6, $prefill['prefill']['sold_room_nights']);
        self::assertSame(12, $prefill['prefill']['sellable_room_nights']);
        self::assertSame(
            '美团云 PMS 当日经营概览 / capture:' . $capture['id'],
            $prefill['prefill']['source_reference']
        );
    }

    public function testTrustedCaptureRejectsAvailabilityDifferenceAboveDynamicTolerance(): void
    {
        $service = $this->service();
        $input = $this->validInput();
        $input['summary']['available_rooms'] = 1;
        $input['field_trace']['available_rooms'] = 'API:businessOverview#data.saleNum';

        try {
            $service->save(
                8,
                80,
                7,
                '敦煌漠蓝新',
                $input,
                true,
                'mt-hotel-80'
            );
            self::fail('availability difference above tolerance must be blocked');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('meituan_cloud_capture_not_verified', $error->getMessage());
        }
        self::assertSame(0, (int)Db::name('meituan_cloud_pms_captures')->count());
    }

    public function testVerifiedCaptureAutoLearnsProviderHotelIdUnderCurrentIdentityGate(): void
    {
        $integration = new MeituanCloudPmsIntegrationService();
        $integration->save(8, 80, 7, [
            'provider_hotel_id' => null,
            'provider_hotel_name' => '敦煌漠蓝新',
            'status' => true,
        ]);
        $capture = $this->service()->save(
            8,
            80,
            7,
            '敦煌漠蓝新',
            $this->validInput(),
            true
        );

        $current = $integration->recordCapture(8, 80, 7, $capture);
        $status = $integration->status(8, 80, 7, '2026-07-28');
        $prefill = $integration->prefill(8, 80, 7, '2026-07-28');

        self::assertSame('mt-hotel-80', $current['provider_hotel_id'] ?? null);
        self::assertTrue($status['fact_gate']['allowed']);
        self::assertSame('verified_fact_ready', $status['fact_gate']['status']);
        self::assertNotNull($prefill['prefill']);
    }

    public function testUnverifiedCaptureCannotSeedProviderHotelId(): void
    {
        $integration = new MeituanCloudPmsIntegrationService();
        $integration->save(8, 80, 7, [
            'provider_hotel_id' => null,
            'provider_hotel_name' => '敦煌漠蓝新',
            'status' => true,
        ]);
        $capture = $this->service()->save(
            8,
            80,
            7,
            '敦煌漠蓝新',
            $this->validInput()
        );

        $current = $integration->recordCapture(8, 80, 7, $capture);
        $prefill = $integration->prefill(8, 80, 7, '2026-07-28');

        self::assertNull($current['provider_hotel_id'] ?? null);
        self::assertSame('blocked', $prefill['status']);
        self::assertNull($prefill['prefill']);
    }

    public function testConcurrentManualBindingWinsAndWrongHotelCaptureCannotPrefill(): void
    {
        $setup = new MeituanCloudPmsIntegrationService();
        $setup->save(8, 80, 7, [
            'provider_hotel_id' => null,
            'provider_hotel_name' => '敦煌漠蓝新',
            'status' => true,
        ]);
        $capture = $this->service()->save(
            8,
            80,
            7,
            '敦煌漠蓝新',
            $this->validInput(),
            true
        );
        $manualBindingApplied = false;
        $racingService = new MeituanCloudPmsIntegrationService(
            static function (array $staleConfig) use (&$manualBindingApplied): void {
                self::assertSame('', trim((string)($staleConfig['provider_hotel_id'] ?? '')));
                Db::name('meituan_cloud_pms_integrations')
                    ->where('id', (int)$staleConfig['id'])
                    ->update([
                        'provider_hotel_id' => 'mt-hotel-manual-b',
                        'updated_by' => 99,
                        'update_time' => '2026-07-28 12:01:00',
                    ]);
                $manualBindingApplied = true;
            }
        );

        $current = $racingService->recordCapture(8, 80, 7, $capture);
        $status = $racingService->status(8, 80, 7, '2026-07-28');
        $prefill = $racingService->prefill(8, 80, 7, '2026-07-28');

        self::assertTrue($manualBindingApplied);
        self::assertSame('mt-hotel-manual-b', $current['provider_hotel_id'] ?? null);
        self::assertSame(
            'mt-hotel-manual-b',
            Db::name('meituan_cloud_pms_integrations')
                ->where('tenant_id', 8)
                ->where('hotel_id', 80)
                ->value('provider_hotel_id')
        );
        self::assertFalse($status['fact_gate']['allowed']);
        self::assertContains(
            'meituan_cloud_provider_hotel_id_mismatch',
            array_column($status['fact_gate']['blockers'], 'code')
        );
        self::assertSame('blocked', $prefill['status']);
        self::assertNull($prefill['prefill']);
        self::assertContains(
            'meituan_cloud_provider_hotel_id_mismatch',
            array_column($prefill['gaps'], 'code')
        );
    }

    private function service(): MeituanCloudPmsCaptureService
    {
        return new MeituanCloudPmsCaptureService(
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-07-28 12:05:00')
        );
    }

    /** @return array<string,mixed> */
    private function validInput(): array
    {
        $summary = [
            'estimated_room_revenue' => 600,
            'adr' => 100,
            'revpar' => 50,
            'sold_room_nights' => 6,
            'total_rooms' => 12,
            'available_rooms' => 6,
            'room_type_available_rooms' => 6,
            'occupancy_rate_percent' => 50,
            'sale_order_count' => 5,
        ];
        return [
            'source_url' => MeituanCloudPmsCaptureService::SOURCE_URL,
            'source_scope' => MeituanCloudPmsCaptureService::SOURCE_SCOPE,
            'capture_method' => 'same_origin_api',
            'captured_at' => '2026-07-28 12:00:00',
            'business_date' => '2026-07-28',
            'provider_hotel_id' => 'mt-hotel-80',
            'provider_hotel_name' => '敦煌漠蓝新',
            'identity_evidence_type' => 'verified_api_hotel_identity',
            'date_evidence_type' => 'trusted_realtime_workbench_capture',
            'summary' => $summary,
            'room_types' => [
                [
                    'room_type' => '大床房',
                    'total_rooms' => 8,
                    'sold_rooms' => 4,
                ],
                [
                    'room_type' => '双床房',
                    'total_rooms' => 4,
                    'sold_rooms' => 2,
                ],
            ],
            'field_trace' => array_fill_keys(
                array_keys($summary),
                'API:reviewed-same-origin-field'
            ) + [
                'provider_hotel_identity' =>
                    'API:/hotelpms/api/v1/property/hotel/getHotelInfo#data.hotelName+data.hotelId',
            ],
            'validation_warnings' => [],
        ];
    }

    private static function createSchema(): void
    {
        Db::execute(
            'CREATE TABLE meituan_cloud_pms_integrations ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, provider TEXT, '
            . 'provider_hotel_id TEXT NULL, provider_hotel_name TEXT NULL, source_url TEXT, status INTEGER, '
            . 'last_capture_id INTEGER NULL, last_capture_business_date TEXT NULL, '
            . 'last_capture_status TEXT NULL, last_readback_status TEXT NULL, '
            . 'created_by INTEGER NULL, updated_by INTEGER NULL, create_time TEXT, update_time TEXT, '
            . 'UNIQUE (tenant_id, hotel_id, provider))'
        );
        Db::execute(
            'CREATE TABLE meituan_cloud_pms_captures ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, provider TEXT, '
            . 'provider_hotel_id TEXT NULL, provider_hotel_name TEXT NULL, expected_hotel_name TEXT, '
            . 'identity_evidence_type TEXT, identity_status TEXT, date_evidence_type TEXT, date_status TEXT, '
            . 'source_url TEXT, source_scope TEXT, capture_method TEXT, business_date TEXT, '
            . 'estimated_room_revenue REAL NULL, adr REAL NULL, revpar REAL NULL, '
            . 'sold_room_nights INTEGER NULL, total_rooms INTEGER NULL, available_rooms INTEGER NULL, '
            . 'room_type_available_rooms INTEGER NULL, occupancy_rate_percent REAL NULL, '
            . 'sale_order_count INTEGER NULL, room_type_count INTEGER, availability_difference INTEGER NULL, '
            . 'availability_tolerance INTEGER NULL, reconciliation_status TEXT, capture_status TEXT, '
            . 'quality_status TEXT, quality_reason TEXT NULL, gap_codes_json TEXT NULL, '
            . 'validation_warnings_json TEXT NULL, field_trace_json TEXT NULL, snapshot_json TEXT, '
            . 'source_fingerprint TEXT, captured_at TEXT, captured_by INTEGER NULL, '
            . 'readback_status TEXT, readback_verified_at TEXT NULL, create_time TEXT, update_time TEXT)'
        );
        Db::execute(
            'CREATE TABLE meituan_cloud_pms_room_type_details ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, capture_id INTEGER, tenant_id INTEGER, hotel_id INTEGER, '
            . 'business_date TEXT, room_type TEXT, total_rooms INTEGER, sold_rooms INTEGER, '
            . 'available_rooms INTEGER, overbooked_rooms INTEGER, source_row_index INTEGER, create_time TEXT)'
        );
    }
}
