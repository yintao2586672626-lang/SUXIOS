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
        self::assertSame('full_diagnostic', $first['collection_mode']);
        self::assertSame('pms', $first['capture_evidence']['source_kind']);
        self::assertSame(
            'authorized_browser_endpoint',
            $first['capture_evidence']['source_method']
        );
        self::assertSame(
            $first['capture_evidence']['source_trace_id'],
            $first['source_trace_id']
        );
        self::assertSame(
            $first['capture_evidence']['source_url_hash'],
            $first['source_url_hash']
        );
        self::assertMatchesRegularExpression(
            '/^dingdandao:[a-f0-9]{64}$/',
            $first['source_trace_id']
        );
        self::assertSame(
            '1937f09f551ebadbe32c6a097bcd890616af689f1c4e51fe61f928650e719d92',
            $first['source_url_hash']
        );
        self::assertSame(
            'verified_endpoint_recipe',
            $first['capture_evidence']['capture_strategy']
        );
        self::assertSame(
            'structured_json',
            $first['capture_evidence']['response_evidence_type']
        );
        self::assertSame(23, $first['capture_evidence']['recipe_count']);
        self::assertNull($first['capture_evidence']['fallback_from']);
        self::assertNull($first['capture_evidence']['fallback_reason']);
        self::assertSame(
            'suxios.collection_result.v1',
            $first['collection_result']['contract_version']
        );
        self::assertSame(
            'verified_endpoint_recipe',
            $first['collection_result']['run']['strategy']['selected']
        );
        self::assertTrue($first['collection_result']['claim']['allowed']);
        self::assertSame(18, $first['collection_result']['saved_count']);
        self::assertStringNotContainsString(
            'provider-hotel-5',
            json_encode($first['capture_evidence'], JSON_THROW_ON_ERROR)
        );
        self::assertSame(
            'readable_separate',
            $first['county_context']['data_status']
        );
        self::assertSame(
            '甘肃省/酒泉市/敦煌市',
            $first['county_context']['region_name']
        );
        self::assertSame(5, count($first['trend']));
        self::assertSame(5, count($first['county_context']['trend']));
        self::assertSame(1, count($first['room_fee_summary_rows']));
        self::assertSame(
            10135.29,
            $first['room_fee_summary_rows'][0]['room_fee']
        );
        self::assertSame(
            'dingdandao_operating_target_capture.v4',
            $first['capture_contract_version']
        );
        self::assertSame(
            10135.09,
            $first['revenue_overview']['total_accommodation_turnover']
        );
        self::assertNotSame(
            $first['summary']['total_room_fee'],
            $first['revenue_overview']['total_accommodation_turnover']
        );
        self::assertSame(
            -0.2,
            $first['revenue_overview']['subjects'][2]['single_day_total']
        );
        self::assertSame(
            'readback_verified',
            $first['revenue_overview']['readback_status']
        );
        self::assertSame(
            633.46,
            $first['trend']['adr'][1]['value']
        );
        self::assertSame('verified', $first['forward_room_status']['data_status']);
        self::assertSame(
            'readback_verified',
            $first['forward_room_status']['readback_status']
        );
        self::assertSame(31, $first['forward_room_status']['source_day_count']);
        self::assertSame(
            63,
            $first['forward_room_status']['horizons'][1]['booked_room_nights']
        );
        self::assertSame(
            'verified',
            $first['component_coverage']['components']['operating_core']['status']
        );
        self::assertSame(
            'verified',
            $first['component_coverage']['components']
                ['accommodation_revenue_overview']['status']
        );
        self::assertSame(
            'partial',
            $first['component_coverage']['components']['hotel_trend']['status']
        );
        self::assertSame(
            'verified',
            $first['component_coverage']['components']['forward_room_status']['status']
        );
        self::assertSame(
            'readable_unmodeled',
            $first['component_coverage']['components']['auxiliary_details']['status']
        );
        self::assertSame(
            'partial',
            $first['component_coverage']['components']['full_diagnostic']['status']
        );
        self::assertSame(
            'observation_date_window',
            $first['component_coverage']['components']['hotel_trend']['date_role']
        );
        self::assertSame(
            'stay_date_window',
            $first['component_coverage']['components']['forward_room_status']['date_role']
        );
        self::assertSame(
            'realtime_snapshot',
            $first['component_coverage']['temporal_context']['current']['snapshot_role']
        );
        self::assertSame(
            'forward_snapshot',
            $first['component_coverage']['temporal_context']['future']['snapshot_role']
        );
        self::assertSame(
            DingdandaoOperatingTargetCaptureService::FORWARD_SOURCE_URL,
            $first['component_coverage']['source_surfaces']
                ['forward_room_calendar']['source_page_url']
        );
        self::assertSame(
            'room_fee / sellable_room_nights',
            $first['forward_room_status']['metric_definitions']['revpar']['formula']
        );
        self::assertSame($first['id'], $second['id']);
        self::assertSame(1, (int)Db::name('dingdandao_operating_target_captures')->count());
        self::assertSame(17, (int)Db::name('dingdandao_room_fee_capture_details')->count());
    }

    public function testTrustedHistoricalSingleDateCaptureSavesAndReadsBack(): void
    {
        $service = new DingdandaoOperatingTargetCaptureService(
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-07-27 08:10:00')
        );
        $input = $this->historicalInput();

        $first = $service->save(
            8,
            5,
            7,
            '敦煌漠蓝新',
            $input,
            true,
            'provider-hotel-5'
        );
        $second = $service->save(
            8,
            5,
            7,
            '敦煌漠蓝新',
            $input,
            true,
            'provider-hotel-5'
        );

        self::assertSame('2026-07-26', $first['business_date']);
        self::assertSame(
            DingdandaoOperatingTargetCaptureService::HISTORICAL_SOURCE_SCOPE,
            $first['source_scope']
        );
        self::assertSame('operating_indicators', $first['collection_mode']);
        self::assertSame('verified', $first['quality_status']);
        self::assertSame('readback_verified', $first['readback_status']);
        self::assertTrue($first['collection_result']['claim']['allowed']);
        self::assertSame(
            'historical_daily_snapshot',
            $first['component_coverage']['temporal_context']['current']['snapshot_role']
        );
        self::assertSame(
            'not_requested',
            $first['component_coverage']['temporal_context']['future']['status']
        );
        self::assertSame($first['id'], $second['id']);
        self::assertSame(
            1,
            (int)Db::name('dingdandao_operating_target_captures')->count()
        );
    }

    public function testHistoricalCaptureRejectsFullDiagnosticAndWrongScopeBeforeWriting(): void
    {
        $service = new DingdandaoOperatingTargetCaptureService(
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-07-27 08:10:00')
        );
        $fullDiagnostic = $this->historicalInput();
        $fullDiagnostic['collection_mode'] = 'full_diagnostic';
        $fullDiagnostic['capture_evidence'] = $this->validCaptureEvidence(
            (string)$fullDiagnostic['source_api_path'],
            (string)$fullDiagnostic['business_date'],
            (string)$fullDiagnostic['provider_hotel_id'],
            'full_diagnostic'
        );
        try {
            $service->save(
                8,
                5,
                7,
                '敦煌漠蓝新',
                $fullDiagnostic,
                true,
                'provider-hotel-5'
            );
            self::fail('historical full diagnostic must be rejected');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('dingdandao_capture_not_verified', $error->getMessage());
        }

        $wrongScope = $this->historicalInput();
        $wrongScope['source_scope'] = DingdandaoOperatingTargetCaptureService::SOURCE_SCOPE;
        try {
            $service->save(
                8,
                5,
                7,
                '敦煌漠蓝新',
                $wrongScope,
                true,
                'provider-hotel-5'
            );
            self::fail('historical facts with today-only scope must be rejected');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('dingdandao_capture_not_verified', $error->getMessage());
        }

        self::assertSame(
            0,
            (int)Db::name('dingdandao_operating_target_captures')->count()
        );
    }

    public function testManualHistoricalInputCannotSelfAttestAsVerified(): void
    {
        $service = new DingdandaoOperatingTargetCaptureService(
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-07-27 08:10:00')
        );

        $capture = $service->save(
            8,
            5,
            7,
            '敦煌漠蓝新',
            $this->historicalInput()
        );

        self::assertSame('unverified', $capture['quality_status']);
        self::assertFalse($capture['collection_result']['claim']['allowed']);
        self::assertContains(
            'dingdandao_trusted_collection_required',
            array_column($capture['gaps'], 'code')
        );
    }

    public function testTrustedCaptureRequiresNetworkResponseMethod(): void
    {
        $service = new DingdandaoOperatingTargetCaptureService(
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-07-27 08:10:00')
        );
        $input = $this->validInput();
        $input['capture_method'] = 'browser_assist_dom';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('dingdandao_capture_not_verified');
        try {
            $service->save(
                8,
                5,
                7,
                '敦煌漠蓝新',
                $input,
                true,
                'provider-hotel-5'
            );
        } finally {
            self::assertSame(
                0,
                (int)Db::name('dingdandao_operating_target_captures')->count()
            );
        }
    }

    public function testLegacyAuxiliaryRowsRemainReadableButDiagnosticCoverageIsPartial(): void
    {
        $service = new DingdandaoOperatingTargetCaptureService(
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-07-27 08:10:00')
        );
        $capture = $service->save(
            8,
            5,
            7,
            '敦煌漠蓝新',
            $this->validInput(),
            true,
            'provider-hotel-5'
        );
        $row = Db::name('dingdandao_operating_target_captures')
            ->where('id', (int)$capture['id'])
            ->find();
        $snapshot = json_decode(
            (string)$row['snapshot_json'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        foreach ($snapshot['auxiliary_query_status'] as &$status) {
            unset($status['observed_row_count']);
        }
        unset($status);
        Db::name('dingdandao_operating_target_captures')
            ->where('id', (int)$capture['id'])
            ->update([
                'snapshot_json' => json_encode(
                    $snapshot,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ),
            ]);

        $readback = $service->latest(8, 5, '2026-07-27');

        self::assertSame($capture['id'], $readback['id']);
        self::assertSame(
            'verified',
            $readback['component_coverage']['components']['operating_core']['status']
        );
        self::assertSame(
            'partial',
            $readback['component_coverage']['components']['auxiliary_details']['status']
        );
        self::assertContains(
            'dingdandao_auxiliary_rows_unverified',
            $readback['component_coverage']['components']['auxiliary_details']['gap_codes']
        );
    }

    public function testMissingPersistedRoomFeeSummaryBlocksCoreWithoutReadFailure(): void
    {
        $service = new DingdandaoOperatingTargetCaptureService(
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-07-27 08:10:00')
        );
        $capture = $service->save(
            8,
            5,
            7,
            '敦煌漠蓝新',
            $this->validInput(),
            true,
            'provider-hotel-5'
        );
        $row = Db::name('dingdandao_operating_target_captures')
            ->where('id', (int)$capture['id'])
            ->find();
        $snapshot = json_decode(
            (string)$row['snapshot_json'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        unset($snapshot['room_fee_summary_rows']);
        Db::name('dingdandao_operating_target_captures')
            ->where('id', (int)$capture['id'])
            ->update([
                'snapshot_json' => json_encode(
                    $snapshot,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ),
            ]);

        $readback = $service->latest(8, 5, '2026-07-27');

        self::assertSame($capture['id'], $readback['id']);
        self::assertSame(
            'partial',
            $readback['component_coverage']['components']['operating_core']['status']
        );
        self::assertFalse($readback['collection_result']['claim']['allowed']);
        self::assertContains(
            'pms_operating_core_not_verified',
            $readback['collection_result']['claim']['reason_codes']
        );
        self::assertContains(
            'dingdandao_operating_core_sum_detail_missing',
            $readback['collection_result']['claim']['reason_codes']
        );
    }

    public function testTrustedCaptureRejectsSumDetailMismatchBeforeWriting(): void
    {
        $service = new DingdandaoOperatingTargetCaptureService(
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-07-27 08:10:00')
        );
        $input = $this->validInput();
        $input['room_fee_summary_rows'][0]['room_fee'] = 1;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('dingdandao_capture_not_verified');
        try {
            $service->save(
                8,
                5,
                7,
                '敦煌漠蓝新',
                $input,
                true,
                'provider-hotel-5'
            );
        } finally {
            self::assertSame(
                0,
                (int)Db::name('dingdandao_operating_target_captures')->count()
            );
        }
    }

    public function testStructuredCountyFactsDoNotRequireOptionalRegionLabel(): void
    {
        $service = new DingdandaoOperatingTargetCaptureService(
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-07-27 08:10:00')
        );
        $input = $this->validInput();
        $input['county_context']['region_name'] = null;
        unset($input['county_context']['field_trace']['region_name']);

        $capture = $service->save(
            8,
            5,
            7,
            '敦煌漠蓝新',
            $input,
            true,
            'provider-hotel-5'
        );

        self::assertSame('readable_separate', $capture['county_context']['data_status']);
        self::assertNull($capture['county_context']['region_name']);
        self::assertSame(
            'missing_optional',
            $capture['county_context']['region_name_status']
        );
        self::assertSame(6053.86, $capture['county_context']['summary']['total_room_fee']);
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

    public function testTrustedCaptureRejectsTamperedEvidenceBeforeWriting(): void
    {
        $service = new DingdandaoOperatingTargetCaptureService(
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-07-27 08:10:00')
        );
        $input = $this->validInput();
        $input['capture_evidence']['source_trace_id'] =
            'dingdandao:' . str_repeat('0', 64);

        try {
            $service->save(
                8,
                5,
                7,
                '敦煌漠蓝新',
                $input,
                true,
                'provider-hotel-5'
            );
            self::fail('tampered capture evidence must be rejected');
        } catch (\InvalidArgumentException $error) {
            self::assertSame(
                'dingdandao_capture_evidence_invalid',
                $error->getMessage()
            );
        }
        self::assertSame(0, (int)Db::name('dingdandao_operating_target_captures')->count());
    }

    public function testPartialForwardFactsDoNotInvalidateVerifiedCurrentDayCapture(): void
    {
        $service = new DingdandaoOperatingTargetCaptureService(
            static fn(): DateTimeImmutable => new DateTimeImmutable('2026-07-27 08:10:00')
        );
        $input = $this->validInput();
        $input['forward_room_status'] = [
            'data_status' => 'partial',
            'gap_codes' => ['dingdandao_forward_coverage_partial'],
        ];
        $capture = $service->save(
            8,
            5,
            7,
            (string)$input['provider_hotel_name'],
            $input,
            true,
            'provider-hotel-5'
        );

        self::assertSame('verified', $capture['quality_status']);
        self::assertSame('readback_verified', $capture['readback_status']);
        self::assertSame('partial', $capture['forward_room_status']['data_status']);
        self::assertSame('not_verified', $capture['forward_room_status']['readback_status']);
        self::assertSame(
            ['dingdandao_forward_coverage_partial'],
            $capture['forward_room_status']['gap_codes']
        );
    }

    public function testMissingRevenueOverviewStaysNullAndMarksCurrentContextPartial(): void
    {
        $service = new DingdandaoOperatingTargetCaptureService(
            static fn(): DateTimeImmutable => new DateTimeImmutable(
                '2026-07-27 08:10:00'
            )
        );
        $input = $this->validInput();
        $input['revenue_overview'] = [
            'data_status' => 'partial',
            'gap_codes' => [
                'dingdandao_revenue_overview_request_failed',
            ],
        ];

        $capture = $service->save(
            8,
            5,
            7,
            (string)$input['provider_hotel_name'],
            $input,
            true,
            'provider-hotel-5'
        );

        self::assertSame('verified', $capture['quality_status']);
        self::assertNull(
            $capture['revenue_overview']['total_accommodation_turnover']
        );
        self::assertSame(
            'partial',
            $capture['component_coverage']['components']
                ['accommodation_revenue_overview']['status']
        );
        self::assertSame(
            'partial',
            $capture['component_coverage']['temporal_context']['current']['status']
        );
        self::assertContains(
            'dingdandao_revenue_overview_request_failed',
            $capture['component_coverage']['temporal_context']['current']['gap_codes']
        );
    }

    public function testVerifiedDisplayWindowsSurviveMissingTrailingDays(): void
    {
        $service = new DingdandaoOperatingTargetCaptureService(
            static fn(): DateTimeImmutable => new DateTimeImmutable(
                '2026-07-27 08:10:00'
            )
        );
        $input = $this->validInput();
        $forward = $input['forward_room_status'];
        $forward['range_end_date'] = '2026-08-17';
        $forward['source_day_count'] = 22;
        $forward['source_coverage_status'] = 'partial';
        $forward['source_gap_codes'] = [
            'dingdandao_forward_trailing_coverage_partial',
        ];
        $forward['daily_rows'] = array_slice($forward['daily_rows'], 0, 22);
        foreach ($forward['room_types'] as &$roomType) {
            $roomType['daily_rows'] = array_slice(
                $roomType['daily_rows'],
                0,
                22
            );
        }
        unset($roomType);
        $input['forward_room_status'] = $forward;

        $capture = $service->save(
            8,
            5,
            7,
            (string)$input['provider_hotel_name'],
            $input,
            true,
            'provider-hotel-5'
        );

        self::assertSame(
            'verified',
            $capture['forward_room_status']['data_status']
        );
        self::assertSame(
            'readback_verified',
            $capture['forward_room_status']['readback_status']
        );
        self::assertSame(22, $capture['forward_room_status']['source_day_count']);
        self::assertSame(
            'partial',
            $capture['forward_room_status']['source_coverage_status']
        );
        self::assertSame(
            [3, 7, 14, 21],
            array_column(
                $capture['forward_room_status']['horizons'],
                'horizon_days'
            )
        );
    }

    public function testOversoldForwardRowsAreSavedAsWarningsInsteadOfDiscarded(): void
    {
        $service = new DingdandaoOperatingTargetCaptureService(
            static fn(): DateTimeImmutable => new DateTimeImmutable(
                '2026-07-27 08:10:00'
            )
        );
        $input = $this->validInput();
        $forward = $input['forward_room_status'];
        $forward['data_status'] = 'verified_with_anomalies';
        $forward['daily_rows'][5]['oversold_rooms'] = 1;
        $forward['room_types'][0]['daily_rows'][5]['oversold_rooms'] = 1;
        $forward['gap_codes'] = ['dingdandao_forward_oversold_present'];
        $forward['anomalies'] = [[
            'anomaly_type' => 'oversold',
            'stay_date' => '2026-08-01',
            'provider_room_type_id' => 'room-type-1',
            'room_type_name' => '景观大床房',
            'oversold_rooms' => 1,
        ]];
        foreach ($forward['horizons'] as &$horizon) {
            if ((int)$horizon['horizon_days'] < 7) {
                continue;
            }
            $horizon['oversold_room_nights'] = 1;
            $horizon['quality_status'] = 'warning';
            $horizon['gap_codes'] = [
                'dingdandao_forward_oversold_present',
            ];
        }
        unset($horizon);
        $input['forward_room_status'] = $forward;

        $capture = $service->save(
            8,
            5,
            7,
            (string)$input['provider_hotel_name'],
            $input,
            true,
            'provider-hotel-5'
        );

        self::assertSame(
            'verified_with_anomalies',
            $capture['forward_room_status']['data_status']
        );
        self::assertSame(
            'readback_verified',
            $capture['forward_room_status']['readback_status']
        );
        self::assertCount(31, $capture['forward_room_status']['daily_rows']);
        self::assertSame(
            '2026-08-01',
            $capture['forward_room_status']['anomalies'][0]['stay_date']
        );
        self::assertSame(
            'warning',
            $capture['component_coverage']['components']['forward_room_status']['status']
        );
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
        $sourceApiPath =
            '/v2/um-b/web/pro/data/businessIndicatorsTotal';
        $businessDate = '2026-07-27';
        $providerHotelId = 'provider-hotel-5';
        $collectionMode = 'full_diagnostic';
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
            'source_api_path' => $sourceApiPath,
            'source_scope' => DingdandaoOperatingTargetCaptureService::SOURCE_SCOPE,
            'capture_method' => 'network_response',
            'collection_mode' => $collectionMode,
            'capture_evidence' => $this->validCaptureEvidence(
                $sourceApiPath,
                $businessDate,
                $providerHotelId,
                $collectionMode
            ),
            'captured_at' => '2026-07-27 08:05:00',
            'business_date' => $businessDate,
            'provider_hotel_id' => $providerHotelId,
            'provider_hotel_name' => '敦煌漠蓝新',
            'identity_evidence_type' => 'verified_api_store_identity',
            'summary' => $summary,
            'room_fee_summary_rows' => [[
                'provider_room_type_id' => 'room-type-1',
                'room_type' => '大床房',
                'source_row_index' => 1,
                'room_fee' => 10135.29,
            ]],
            'revenue_overview' => $this->validRevenueOverview($businessDate),
            'room_fee_details' => $details,
            'trend' => [
                'total_room_fee' => [
                    ['date' => '2026-07-26', 'value' => 9000],
                    ['date' => '2026-07-27', 'value' => 10135.29],
                ],
                'adr' => [
                    ['date' => '2026-07-26', 'value' => 600],
                    ['date' => '2026-07-27', 'value' => 633.46],
                ],
                'occupancy_rate_percent' => [
                    ['date' => '2026-07-26', 'value' => 95],
                    ['date' => '2026-07-27', 'value' => 100],
                ],
                'revpar' => [
                    ['date' => '2026-07-26', 'value' => 570],
                    ['date' => '2026-07-27', 'value' => 633.46],
                ],
                'sold_room_nights' => [
                    ['date' => '2026-07-26', 'value' => 15],
                    ['date' => '2026-07-27', 'value' => 16],
                ],
            ],
            'auxiliary_query_status' => [
                [
                    'api_path' => '/v2/um-b/web/pro/data/businessIndicatorsSumDetail',
                    'type' => 1,
                    'fact_scope' => 'auxiliary_metric_only',
                    'status' => 'readable_not_promoted',
                    'observed_row_count' => 1,
                ],
                [
                    'api_path' => '/v2/um-b/web/pro/data/businessIndicatorsDailyDetail',
                    'type' => 1,
                    'fact_scope' => 'auxiliary_metric_only',
                    'status' => 'readable_not_promoted',
                    'observed_row_count' => 1,
                ],
                [
                    'api_path' => '/v2/um-b/web/pro/data/businessIndicatorsSumDetail',
                    'type' => 2,
                    'fact_scope' => 'auxiliary_metric_only',
                    'status' => 'readable_not_promoted',
                    'observed_row_count' => 1,
                ],
                [
                    'api_path' => '/v2/um-b/web/pro/data/businessIndicatorsDailyDetail',
                    'type' => 2,
                    'fact_scope' => 'auxiliary_metric_only',
                    'status' => 'readable_not_promoted',
                    'observed_row_count' => 1,
                ],
                [
                    'api_path' => '/v2/um-b/web/pro/data/businessIndicatorsSumDetail',
                    'type' => 3,
                    'fact_scope' => 'auxiliary_metric_only',
                    'status' => 'readable_not_promoted',
                    'observed_row_count' => 1,
                ],
                [
                    'api_path' => '/v2/um-b/web/pro/data/businessIndicatorsDailyDetail',
                    'type' => 3,
                    'fact_scope' => 'auxiliary_metric_only',
                    'status' => 'readable_not_promoted',
                    'observed_row_count' => 1,
                ],
            ],
            'county_context' => [
                'fact_scope' => 'county_diagnostic_only',
                'data_status' => 'readable_separate',
                'region_name' => '甘肃省/酒泉市/敦煌市',
                'bool_city' => false,
                'summary' => [
                    'total_room_fee' => 6053.86,
                    'adr' => 396.87,
                    'occupancy_rate_percent' => 60.96,
                    'revpar' => 241.93,
                    'sold_room_nights' => 15.25,
                    'average_daily_room_nights' => 15.25,
                ],
                'trend' => [
                    'total_room_fee' => [
                        ['date' => '2026-07-26', 'value' => 5887.06],
                        ['date' => '2026-07-27', 'value' => 6053.86],
                    ],
                    'adr' => [
                        ['date' => '2026-07-26', 'value' => 366.18],
                        ['date' => '2026-07-27', 'value' => 396.87],
                    ],
                    'occupancy_rate_percent' => [
                        ['date' => '2026-07-26', 'value' => 66.5],
                        ['date' => '2026-07-27', 'value' => 60.96],
                    ],
                    'revpar' => [
                        ['date' => '2026-07-26', 'value' => 243.5],
                        ['date' => '2026-07-27', 'value' => 241.93],
                    ],
                    'sold_room_nights' => [
                        ['date' => '2026-07-26', 'value' => 16.08],
                        ['date' => '2026-07-27', 'value' => 15.25],
                    ],
                ],
                'field_trace' => [
                    'summary' =>
                        'API:/v2/um-b/web/pro/data/businessIndicatorsTotal/county#data',
                    'region_name' => 'DOM:当前区域指标',
                    'total_room_fee' =>
                        'API:/v2/um-b/web/pro/data/businessIndicatorsTrend/county?type=5#data.list[]',
                    'adr' =>
                        'API:/v2/um-b/web/pro/data/businessIndicatorsTrend/county?type=0#data.list[]',
                    'occupancy_rate_percent' =>
                        'API:/v2/um-b/web/pro/data/businessIndicatorsTrend/county?type=1#data.list[]',
                    'revpar' =>
                        'API:/v2/um-b/web/pro/data/businessIndicatorsTrend/county?type=2#data.list[]',
                    'sold_room_nights' =>
                        'API:/v2/um-b/web/pro/data/businessIndicatorsTrend/county?type=3#data.list[]',
                ],
            ],
            'forward_room_status' => $this->validForwardInput(),
            'field_trace' => array_fill_keys(array_keys($summary), 'API:/api/verified-read'),
        ];
    }

    /** @return array<string,mixed> */
    private function validRevenueOverview(string $businessDate): array
    {
        $totalTrend = [[
            'observation_date' => $businessDate,
            'amount' => 10135.09,
        ]];
        return [
            'contract_version' =>
                'dingdandao_accommodation_revenue_overview.v1',
            'fact_scope' => 'whole_hotel_accommodation_turnover',
            'source_page_url' =>
                DingdandaoOperatingTargetCaptureService::SOURCE_URL,
            'source_api_path' => '/v2/um-b/web/pro/data/sumAccBusiness',
            'data_status' => 'verified',
            'business_date_from' => $businessDate,
            'business_date_to' => $businessDate,
            'total_accommodation_turnover' => 10135.09,
            'subjects' => [
                [
                    'provider_subject_type' => -1,
                    'subject_name' => 'total',
                    'source_row_index' => 1,
                    'single_day_total' => 10135.09,
                    'period_total' => 10135.09,
                    'percent' => 100,
                    'daily_points' => $totalTrend,
                ],
                [
                    'provider_subject_type' => 1,
                    'subject_name' => 'room_fee',
                    'source_row_index' => 2,
                    'single_day_total' => 10135.29,
                    'period_total' => 10135.29,
                    'percent' => 100,
                    'daily_points' => [[
                        'observation_date' => $businessDate,
                        'amount' => 10135.29,
                    ]],
                ],
                [
                    'provider_subject_type' => 7,
                    'subject_name' => 'breakfast_guest_consumption',
                    'source_row_index' => 3,
                    'single_day_total' => -0.2,
                    'period_total' => -0.2,
                    'percent' => 0,
                    'daily_points' => [[
                        'observation_date' => $businessDate,
                        'amount' => -0.2,
                    ]],
                ],
            ],
            'total_trend' => $totalTrend,
            'reconciliation_status' => 'source_total_preserved',
            'metric_boundaries' => [],
            'gap_codes' => [],
            'field_trace' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function historicalInput(): array
    {
        $input = $this->validInput();
        $businessDate = '2026-07-26';
        $collectionMode = 'operating_indicators';
        $input['source_scope'] =
            DingdandaoOperatingTargetCaptureService::HISTORICAL_SOURCE_SCOPE;
        $input['collection_mode'] = $collectionMode;
        $input['business_date'] = $businessDate;
        $input['captured_at'] = '2026-07-27 08:05:00';
        $input['capture_evidence'] = $this->validCaptureEvidence(
            (string)$input['source_api_path'],
            $businessDate,
            (string)$input['provider_hotel_id'],
            $collectionMode
        );
        $input['revenue_overview'] = $this->validRevenueOverview($businessDate);
        $input['trend'] = [
            'total_room_fee' => [[
                'date' => $businessDate,
                'value' => (float)$input['summary']['total_room_fee'],
            ]],
        ];
        $input['auxiliary_query_status'] = [];
        unset($input['county_context'], $input['forward_room_status']);
        return $input;
    }

    /**
     * @return array<string,mixed>
     */
    private function validCaptureEvidence(
        string $sourceApiPath,
        string $businessDate,
        string $providerHotelId,
        string $collectionMode
    ): array {
        $section = $collectionMode === 'full_diagnostic'
            ? 'pms_full_diagnostic'
            : 'pms_operating';
        $sourceUrlHash = hash(
            'sha256',
            DingdandaoOperatingTargetCaptureService::SOURCE_URL
        );
        $providerHotelIdHash = hash('sha256', $providerHotelId);
        $recipeIds = $collectionMode === 'full_diagnostic'
            ? [
                'store_identity',
                'operating_total',
                'accommodation_revenue_overview',
                'sum_detail_room_fee',
                'daily_detail_room_fee',
                'sum_detail_room_nights',
                'daily_detail_room_nights',
                'sum_detail_occupancy_rate',
                'daily_detail_occupancy_rate',
                'sum_detail_revpar',
                'daily_detail_revpar',
                'trend_adr',
                'trend_occupancy_rate',
                'trend_revpar',
                'trend_sold_room_nights',
                'trend_total_room_fee',
                'county_total',
                'county_trend_adr',
                'county_trend_occupancy_rate',
                'county_trend_revpar',
                'county_trend_sold_room_nights',
                'county_trend_total_room_fee',
                'forward_room_status',
            ]
            : [
                'store_identity',
                'operating_total',
                'accommodation_revenue_overview',
                'sum_detail_room_fee',
                'daily_detail_room_fee',
                'trend_total_room_fee',
            ];
        $recipeJson = (string)json_encode(
            $recipeIds,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_INVALID_UTF8_SUBSTITUTE
        );
        $recipePlanHash = hash('sha256', $recipeJson);
        $traceBasis = [
            'platform' => 'dingdandao',
            'section' => $section,
            'source_path' => $sourceApiPath . '#data',
            'capture_source' => 'existing_session_direct_post',
            'source_url_hash' => $sourceUrlHash,
            'source_kind' => 'pms',
            'business_module' => 'accommodation_operating',
            'source_method' => 'authorized_browser_endpoint',
            'collection_mode' => $collectionMode,
            'data_date' => $businessDate,
            'provider_hotel_id_hash' => $providerHotelIdHash,
            'capture_strategy' => 'verified_endpoint_recipe',
            'fallback_from' => null,
            'fallback_reason' => null,
            'response_evidence_type' => 'structured_json',
            'recipe_plan_hash' => $recipePlanHash,
            'recipe_count' => count($recipeIds),
        ];
        $traceJson = (string)json_encode(
            $traceBasis,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_INVALID_UTF8_SUBSTITUTE
        );
        return [
            'source_path' => $sourceApiPath . '#data',
            'capture_source' => 'existing_session_direct_post',
            'section' => $section,
            'source_kind' => 'pms',
            'business_module' => 'accommodation_operating',
            'source_method' => 'authorized_browser_endpoint',
            'collection_mode' => $collectionMode,
            'data_date' => $businessDate,
            'provider_hotel_id_hash' => $providerHotelIdHash,
            'source_url_hash' => $sourceUrlHash,
            'capture_strategy' => 'verified_endpoint_recipe',
            'fallback_from' => null,
            'fallback_reason' => null,
            'response_evidence_type' => 'structured_json',
            'recipe_plan_hash' => $recipePlanHash,
            'recipe_count' => count($recipeIds),
            'source_trace_id' => 'dingdandao:' . hash('sha256', $traceJson),
        ];
    }

    /** @return array<string,mixed> */
    private function validForwardInput(): array
    {
        $asOfDate = '2026-07-27';
        $dates = [];
        for ($index = 0; $index < 31; $index++) {
            $dates[] = (new DateTimeImmutable($asOfDate))
                ->modify('+' . $index . ' days')
                ->format('Y-m-d');
        }
        $roomTypeA = [];
        $roomTypeB = [];
        $total = [];
        foreach ($dates as $date) {
            $roomTypeA[] = [
                'stay_date' => $date,
                'remaining_sellable_rooms' => 3,
                'booked_rooms' => 5,
                'unavailable_rooms' => 0,
                'oversold_rooms' => 0,
                'room_fee' => 2500,
                'sold_room_nights' => 5,
                'sellable_room_nights' => 8,
                'occupancy_rate_percent' => 62.5,
                'adr' => 500,
                'revpar' => 312.5,
            ];
            $roomTypeB[] = [
                'stay_date' => $date,
                'remaining_sellable_rooms' => 3,
                'booked_rooms' => 4,
                'unavailable_rooms' => 1,
                'oversold_rooms' => 0,
                'room_fee' => 2000,
                'sold_room_nights' => 4,
                'sellable_room_nights' => 7,
                'occupancy_rate_percent' => 57.14,
                'adr' => 500,
                'revpar' => 285.71,
            ];
            $total[] = [
                'stay_date' => $date,
                'remaining_sellable_rooms' => 6,
                'booked_rooms' => 9,
                'unavailable_rooms' => 1,
                'oversold_rooms' => 0,
                'room_fee' => 4500,
                'sold_room_nights' => 9,
                'sellable_room_nights' => 15,
                'occupancy_rate_percent' => 60,
                'adr' => 500,
                'revpar' => 300,
            ];
        }
        $horizons = [];
        foreach ([3, 7, 14, 21] as $days) {
            $horizons[] = [
                'horizon_days' => $days,
                'date_from' => '2026-07-28',
                'date_to' => (new DateTimeImmutable($asOfDate))
                    ->modify('+' . $days . ' days')
                    ->format('Y-m-d'),
                'expected_days' => $days,
                'covered_days' => $days,
                'sellable_room_nights' => 15 * $days,
                'booked_room_nights' => 9 * $days,
                'remaining_sellable_room_nights' => 6 * $days,
                'unavailable_room_nights' => $days,
                'oversold_room_nights' => 0,
                'room_fee' => 4500 * $days,
                'occupancy_rate_percent' => 60,
                'adr' => 500,
                'revpar' => 300,
                'quality_status' => 'verified',
                'gap_codes' => [],
            ];
        }
        return [
            'contract_version' => 'dingdandao_forward_room_status.v1',
            'fact_scope' => 'whole_hotel_forward_room_status',
            'source_page_url' =>
                DingdandaoOperatingTargetCaptureService::FORWARD_SOURCE_URL,
            'source_api_path' => '/v2/hm-b/pro/web/accom/roomStat/forward/v2',
            'data_status' => 'verified',
            'as_of_date' => $asOfDate,
            'range_start_date' => $asOfDate,
            'range_end_date' => '2026-08-26',
            'requested_range_start_date' => $asOfDate,
            'requested_range_end_date' => '2026-08-26',
            'source_day_count' => 31,
            'display_day_count' => 21,
            'source_room_type_count' => 2,
            'total_room_count' => 16,
            'display_horizons' => [3, 7, 14, 21],
            'display_semantics' => 'future_days_after_as_of_date',
            'source_coverage_status' => 'complete',
            'source_gap_codes' => [],
            'daily_rows' => $total,
            'room_types' => [
                [
                    'provider_room_type_id' => 'room-type-1',
                    'room_type_name' => '景观大床房',
                    'room_count' => 8,
                    'daily_rows' => $roomTypeA,
                ],
                [
                    'provider_room_type_id' => 'room-type-2',
                    'room_type_name' => '庭院大床房',
                    'room_count' => 8,
                    'daily_rows' => $roomTypeB,
                ],
            ],
            'horizons' => $horizons,
            'reconciliation_status' => 'matched',
            'gap_codes' => [],
            'anomalies' => [],
            'metric_definitions' => $this->forwardMetricDefinitions(),
            'field_trace' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function forwardMetricDefinitions(): array
    {
        return [
            'remaining_sellable_rooms' => [
                'provider_field' => 'availableSale',
                'definition' =>
                    'remaining rooms that can still be sold for the stay date',
            ],
            'booked_rooms' => [
                'provider_field' => 'occupy',
                'definition' => 'rooms already sold for the stay date',
            ],
            'unavailable_rooms' => [
                'provider_field' => 'unavailableSale',
                'definition' =>
                    'rooms unavailable because of stop, maintenance, hold, or linked closure',
                'components' => [
                    'stopped',
                    'maintenance',
                    'held',
                    'linked_closed',
                ],
            ],
            'room_fee' => [
                'provider_field' => 'roomFee',
                'definition' => 'room fee only',
                'material_exclusions' => [
                    'guest_room_consumption',
                    'penalties',
                    'other_non_room_fee_revenue',
                ],
            ],
            'sellable_room_nights' => [
                'provider_field' => 'avaRoom',
                'formula' => 'remaining_sellable_rooms + booked_rooms',
            ],
            'occupancy_rate_percent' => [
                'provider_field' => 'occ',
                'formula' => 'sold_room_nights / sellable_room_nights * 100',
            ],
            'adr' => [
                'provider_field' => 'adr',
                'formula' => 'room_fee / sold_room_nights',
            ],
            'revpar' => [
                'provider_field' => 'revPar',
                'formula' => 'room_fee / sellable_room_nights',
                'equivalent_formula' => 'occupancy_rate_decimal * adr',
            ],
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
