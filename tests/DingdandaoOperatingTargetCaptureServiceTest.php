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
        Db::execute('DROP TRIGGER IF EXISTS mutate_dingdandao_detail_readback');
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

    public function testObservedDunhuangMolanDoesNotSilentlyMatchDunhuangMolanXin(): void
    {
        $service = new DingdandaoOperatingTargetCaptureService();

        $identity = $this->invoke($service, 'identityStatus', [
            '敦煌漠蓝',
            '敦煌漠蓝新',
            'verified_api_store_identity',
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
        self::assertCount(6, $first['auxiliary_query_status']);
        self::assertSame(
            'readable_not_promoted',
            $first['auxiliary_query_status'][0]['status']
        );
        self::assertSame('county_diagnostic_only', $first['county_context']['fact_scope']);
        self::assertSame('readable_separate', $first['county_context']['data_status']);
        self::assertFalse($first['county_context']['bool_city']);
        self::assertSame(4573.08, $first['county_context']['summary']['total_room_fee']);
        self::assertSame(10135.29, $first['summary']['total_room_fee']);
        $storedSnapshot = json_decode((string)Db::name('dingdandao_operating_target_captures')
            ->where('id', (int)$first['id'])
            ->value('snapshot_json'), true);
        self::assertSame('dingdandao_operating_target_capture.v2', $storedSnapshot['contract_version']);
        self::assertSame(
            $first['county_context'],
            $storedSnapshot['county_context']
        );
        self::assertSame(1, (int)Db::name('dingdandao_operating_target_captures')->count());
        self::assertSame(17, (int)Db::name('dingdandao_room_fee_capture_details')->count());
    }

    public function testCountyContextChangesFingerprintWithoutChangingHotelSummary(): void
    {
        $service = new DingdandaoOperatingTargetCaptureService(
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-07-27 08:10:00')
        );
        $firstInput = $this->validInput();
        $first = $service->save(
            8,
            5,
            7,
            '敦煌漠蓝新',
            $firstInput,
            true,
            'provider-hotel-5'
        );
        $secondInput = $this->validInput();
        $secondInput['county_context']['summary']['total_room_fee'] = 4571.44;
        $secondInput['county_context']['trend']['total_room_fee'][1]['value'] = 4571.44;
        $second = $service->save(
            8,
            5,
            7,
            '敦煌漠蓝新',
            $secondInput,
            true,
            'provider-hotel-5'
        );

        self::assertNotSame($first['source_fingerprint'], $second['source_fingerprint']);
        self::assertNotSame($first['id'], $second['id']);
        self::assertSame(10135.29, $first['summary']['total_room_fee']);
        self::assertSame(10135.29, $second['summary']['total_room_fee']);
        self::assertSame(4571.44, $second['county_context']['summary']['total_room_fee']);
        self::assertSame(
            [10135.29, 10135.29],
            array_map(
                'floatval',
                Db::name('dingdandao_operating_target_captures')
                    ->order('id', 'asc')
                    ->column('total_room_fee')
            )
        );
    }

    public function testMissingCountyContextIsPartialWithoutHotelFallback(): void
    {
        $service = new DingdandaoOperatingTargetCaptureService(
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-07-27 08:10:00')
        );
        $input = $this->validInput();
        unset($input['county_context']);
        $capture = $service->save(
            8,
            5,
            7,
            '敦煌漠蓝新',
            $input,
            true,
            'provider-hotel-5'
        );

        self::assertSame('partial', $capture['county_context']['data_status']);
        self::assertSame('county_diagnostic_only', $capture['county_context']['fact_scope']);
        self::assertSame(
            array_fill_keys([
                'total_room_fee',
                'adr',
                'occupancy_rate_percent',
                'revpar',
                'sold_room_nights',
                'average_daily_room_nights',
            ], null),
            $capture['county_context']['summary']
        );
        self::assertSame(10135.29, $capture['summary']['total_room_fee']);
    }

    public function testUnknownAuxiliaryPathIsRejectedBeforePersistence(): void
    {
        $service = new DingdandaoOperatingTargetCaptureService(
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-07-27 08:10:00')
        );
        $input = $this->validInput();
        $input['auxiliary_query_status'][0]['api_path'] = '/v2/unknown';

        try {
            $service->save(8, 5, 7, '敦煌漠蓝新', $input, true, 'provider-hotel-5');
            self::fail('unknown auxiliary paths must not enter a trusted snapshot');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('dingdandao_capture_auxiliary_invalid', $error->getMessage());
        }
        self::assertSame(0, (int)Db::name('dingdandao_operating_target_captures')->count());
    }

    public function testUncontrolledCountyTraceIsRejectedBeforePersistence(): void
    {
        $service = new DingdandaoOperatingTargetCaptureService(
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-07-27 08:10:00')
        );
        $input = $this->validInput();
        $input['county_context']['field_trace']['summary'] = 'API:/untrusted#data';

        try {
            $service->save(8, 5, 7, '敦煌漠蓝新', $input, true, 'provider-hotel-5');
            self::fail('uncontrolled county traces must not enter a trusted snapshot');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('dingdandao_capture_county_invalid', $error->getMessage());
        }
        self::assertSame(0, (int)Db::name('dingdandao_operating_target_captures')->count());
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

    public function testTrustedCaptureRequiresServerProviderAnchorBeforeWriting(): void
    {
        $service = new DingdandaoOperatingTargetCaptureService(
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-07-27 08:10:00')
        );
        try {
            $input = $this->validInput();
            $service->save(
                8,
                5,
                7,
                (string)$input['provider_hotel_name'],
                $input,
                true,
                null
            );
            self::fail('trusted persistence must require a server provider anchor');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('dingdandao_capture_not_verified', $error->getMessage());
        }
        self::assertSame(0, (int)Db::name('dingdandao_operating_target_captures')->count());
    }

    public function testTrustedCapturePreservesExtraZeroRoomFactsWithoutTreatingThemAsSoldNights(): void
    {
        $service = new DingdandaoOperatingTargetCaptureService(
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-07-27 08:10:00')
        );
        $input = $this->validInput();
        $input['room_fee_details'][] = [
            'row_kind' => 'room',
            'room_type' => '大床房',
            'room_number' => 'R17',
            'room_fee' => 0,
        ];

        $capture = $service->save(
            8,
            5,
            7,
            '敦煌漠蓝新',
            $input,
            true,
            'provider-hotel-5'
        );

        self::assertSame('verified', $capture['capture_status']);
        self::assertSame('verified', $capture['quality_status']);
        self::assertSame('readback_verified', $capture['readback_status']);
        self::assertSame(18, (int)Db::name('dingdandao_room_fee_capture_details')->count());
    }

    public function testTrustedCaptureRollsBackWhenOrderedDetailReadbackWasMutated(): void
    {
        $service = new DingdandaoOperatingTargetCaptureService(
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-07-27 08:10:00')
        );
        Db::execute(
            "CREATE TRIGGER mutate_dingdandao_detail_readback "
            . "AFTER INSERT ON dingdandao_room_fee_capture_details "
            . "WHEN NEW.source_row_index = 1 "
            . "BEGIN UPDATE dingdandao_room_fee_capture_details "
            . "SET room_number = 'tampered' WHERE id = NEW.id; END"
        );
        try {
            $input = $this->validInput();
            $service->save(
                8,
                5,
                7,
                (string)$input['provider_hotel_name'],
                $input,
                true,
                'provider-hotel-5'
            );
            self::fail('mutated detail readback must not become verified');
        } catch (\RuntimeException $error) {
            self::assertSame('dingdandao_capture_readback_failed', $error->getMessage());
        } finally {
            Db::execute('DROP TRIGGER IF EXISTS mutate_dingdandao_detail_readback');
        }
        self::assertSame(0, (int)Db::name('dingdandao_operating_target_captures')->count());
        self::assertSame(0, (int)Db::name('dingdandao_room_fee_capture_details')->count());
    }

    public function testTrustedRetryRechecksPersistedDetailsBeforeReusingSnapshot(): void
    {
        $service = new DingdandaoOperatingTargetCaptureService(
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-07-27 08:10:00')
        );
        $input = $this->validInput();
        $capture = $service->save(
            8,
            5,
            7,
            (string)$input['provider_hotel_name'],
            $input,
            true,
            'provider-hotel-5'
        );
        Db::name('dingdandao_room_fee_capture_details')
            ->where('capture_id', (int)$capture['id'])
            ->where('source_row_index', 1)
            ->update(['room_number' => 'tampered-after-save']);

        try {
            $service->save(
                8,
                5,
                7,
                (string)$input['provider_hotel_name'],
                $input,
                true,
                'provider-hotel-5'
            );
            self::fail('a trusted retry must not reuse a mutated snapshot');
        } catch (\RuntimeException $error) {
            self::assertSame('dingdandao_capture_readback_failed', $error->getMessage());
        }
        self::assertSame(1, (int)Db::name('dingdandao_operating_target_captures')->count());
    }

    public function testTrendPreservesRecentSourcePointsAndRejectsFutureOrStaleDates(): void
    {
        $service = new DingdandaoOperatingTargetCaptureService();

        $trend = $this->invoke($service, 'trend', [[
            'total_room_fee' => [
                ['date' => '2026-06-26', 'value' => 1],
                ['date' => '2026-07-26', 'value' => 10679.29],
                ['date' => '2026-07-27', 'value' => 6450.14],
                ['date' => '2026-07-28', 'value' => 99999],
            ],
        ], '2026-07-27']);

        self::assertSame([
            ['date' => '2026-07-26', 'value' => 10679.29],
            ['date' => '2026-07-27', 'value' => 6450.14],
        ], $trend['total_room_fee']);
    }

    public function testKnownDetailAndIdentitySourceTracesArePreserved(): void
    {
        $service = new DingdandaoOperatingTargetCaptureService();
        $trace = [
            'total_room_fee' => 'API:/v2/um-b/web/pro/data/businessIndicatorsTotal#data.totalRoomFee',
            'provider_hotel_identity' => 'API:/v2/ntw/web/ntw/get#data.id+data.name',
            'room_type_names' => 'API:/v2/um-b/web/pro/data/businessIndicatorsSumDetail#data.list[]',
            'room_fee_details' => 'API:/v2/um-b/web/pro/data/businessIndicatorsDailyDetail#data.list[]',
            'trend' => 'API:/v2/um-b/web/pro/data/businessIndicatorsTrend#data.list[]',
            'unexpected' => 'must-not-be-stored',
        ];

        $normalized = $this->invoke($service, 'fieldTrace', [$trace]);

        self::assertSame($trace['provider_hotel_identity'], $normalized['provider_hotel_identity']);
        self::assertSame($trace['room_type_names'], $normalized['room_type_names']);
        self::assertSame($trace['room_fee_details'], $normalized['room_fee_details']);
        self::assertSame($trace['trend'], $normalized['trend']);
        self::assertArrayNotHasKey('unexpected', $normalized);
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
            'auxiliary_query_status' => [
                [
                    'api_path' => '/v2/um-b/web/pro/data/businessIndicatorsSumDetail',
                    'type' => 1,
                    'fact_scope' => 'auxiliary_metric_only',
                    'status' => 'readable_not_promoted',
                ],
                [
                    'api_path' => '/v2/um-b/web/pro/data/businessIndicatorsDailyDetail',
                    'type' => 1,
                    'fact_scope' => 'auxiliary_metric_only',
                    'status' => 'readable_not_promoted',
                ],
                [
                    'api_path' => '/v2/um-b/web/pro/data/businessIndicatorsSumDetail',
                    'type' => 2,
                    'fact_scope' => 'auxiliary_metric_only',
                    'status' => 'readable_not_promoted',
                ],
                [
                    'api_path' => '/v2/um-b/web/pro/data/businessIndicatorsDailyDetail',
                    'type' => 2,
                    'fact_scope' => 'auxiliary_metric_only',
                    'status' => 'readable_not_promoted',
                ],
                [
                    'api_path' => '/v2/um-b/web/pro/data/businessIndicatorsSumDetail',
                    'type' => 3,
                    'fact_scope' => 'auxiliary_metric_only',
                    'status' => 'readable_not_promoted',
                ],
                [
                    'api_path' => '/v2/um-b/web/pro/data/businessIndicatorsDailyDetail',
                    'type' => 3,
                    'fact_scope' => 'auxiliary_metric_only',
                    'status' => 'readable_not_promoted',
                ],
            ],
            'county_context' => [
                'fact_scope' => 'county_diagnostic_only',
                'data_status' => 'readable_separate',
                'bool_city' => false,
                'summary' => [
                    'total_room_fee' => 4573.08,
                    'adr' => 411.18,
                    'occupancy_rate_percent' => 44.10,
                    'revpar' => 181.33,
                    'sold_room_nights' => 11.12,
                    'average_daily_room_nights' => 11.12,
                ],
                'trend' => [
                    'total_room_fee' => [
                        ['date' => '2026-07-26', 'value' => 5456.66],
                        ['date' => '2026-07-27', 'value' => 4573.08],
                    ],
                ],
                'field_trace' => [
                    'summary' => 'API:/v2/um-b/web/pro/data/businessIndicatorsTotal/county#data',
                    'trend' => 'API:/v2/um-b/web/pro/data/businessIndicatorsTrend/county?type=5#data.list[]',
                ],
            ],
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
