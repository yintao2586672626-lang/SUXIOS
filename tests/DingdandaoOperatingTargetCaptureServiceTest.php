<?php
declare(strict_types=1);

namespace tests;

use app\service\DingdandaoOperatingTargetCaptureService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class DingdandaoOperatingTargetCaptureServiceTest extends TestCase
{
    private static array $databaseConfig;
    private static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . '/dingdandao_capture_' . getmypid() . '.sqlite';
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
        Db::name('dingdandao_room_fee_capture_details')->delete(true);
        Db::name('dingdandao_operating_target_captures')->delete(true);
    }

    public function testUnverifiedIdentityIsNotReportedAsMismatch(): void
    {
        $service = new DingdandaoOperatingTargetCaptureService(
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-07-26 17:45:00')
        );

        $identity = $this->invoke($service, 'identityStatus', [
            '敦煌漠蓝',
            '敦煌漠蓝新',
            'unverified',
        ]);
        self::assertSame('unverified', $identity);

        $summary = [
            'total_room_fee' => 10135.29,
            'adr' => 633.46,
            'occupancy_rate_percent' => 100.0,
            'revpar' => 633.46,
            'sold_room_nights' => 16,
            'average_daily_room_nights' => 16.0,
        ];
        $details = [];
        for ($index = 0; $index < 16; $index++) {
            $details[] = [
                'row_kind' => 'room',
                'room_type' => '测试房型',
                'room_number' => 'R' . ($index + 1),
                'room_fee' => $index === 15 ? 633.44 : 633.46,
            ];
        }
        $trace = array_fill_keys(array_keys($summary), 'DOM:经营指标');

        $assessment = $this->invoke($service, 'assess', [
            $summary,
            $details,
            $identity,
            true,
            $trace,
        ]);

        self::assertSame('identity_unverified', $assessment['capture_status']);
        self::assertSame('unverified', $assessment['quality_status']);
        self::assertContains(
            'dingdandao_hotel_identity_unverified',
            array_column($assessment['gaps'], 'code')
        );
    }

    public function testAuthoritativeDifferentIdentityRemainsMismatch(): void
    {
        $service = new DingdandaoOperatingTargetCaptureService();

        $identity = $this->invoke($service, 'identityStatus', [
            '其他酒店',
            '敦煌漠蓝新',
            'platform_store_selector',
        ]);

        self::assertSame('identity_mismatch', $identity);
    }

    public function testManualUploadCannotSelfAttestAsVerified(): void
    {
        $service = new DingdandaoOperatingTargetCaptureService(
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-07-27 08:10:00')
        );
        $capture = $service->save(8, 5, 7, '敦煌漠蓝新', $this->validInput());

        self::assertSame('identity_unverified', $capture['capture_status']);
        self::assertSame('unverified', $capture['quality_status']);
        self::assertContains(
            'dingdandao_trusted_collection_required',
            array_column($capture['gaps'], 'code')
        );
        self::assertSame(1, (int)Db::name('dingdandao_operating_target_captures')->count());
    }

    public function testTrustedCaptureSavesReadsBackAndReusesExactFacts(): void
    {
        $service = new DingdandaoOperatingTargetCaptureService(
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-07-27 08:10:00')
        );
        $first = $service->save(
            8,
            5,
            7,
            '敦煌漠蓝新',
            $this->validInput(),
            true,
            'provider-hotel-5'
        );
        $secondInput = $this->validInput();
        $secondInput['captured_at'] = '2026-07-27 08:09:00';
        $second = $service->save(
            8,
            5,
            7,
            '敦煌漠蓝新',
            $secondInput,
            true,
            'provider-hotel-5'
        );

        self::assertSame('verified', $first['capture_status']);
        self::assertSame('verified', $first['quality_status']);
        self::assertSame('readback_verified', $first['readback_status']);
        self::assertSame($first['id'], $second['id']);
        self::assertSame(1, (int)Db::name('dingdandao_operating_target_captures')->count());
        self::assertSame(17, (int)Db::name('dingdandao_room_fee_capture_details')->count());
    }

    public function testTrustedCaptureRejectsWrongProviderAnchorBeforeWriting(): void
    {
        $service = new DingdandaoOperatingTargetCaptureService(
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-07-27 08:10:00')
        );
        try {
            $service->save(
                8,
                5,
                7,
                '敦煌漠蓝新',
                $this->validInput(),
                true,
                'different-provider-hotel'
            );
            self::fail('wrong provider anchor must be rejected');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('dingdandao_capture_not_verified', $error->getMessage());
        }
        self::assertSame(0, (int)Db::name('dingdandao_operating_target_captures')->count());
    }

    /**
     * @param array<int, mixed> $arguments
     */
    private function invoke(object $target, string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod($target, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($target, $arguments);
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
    }
}
