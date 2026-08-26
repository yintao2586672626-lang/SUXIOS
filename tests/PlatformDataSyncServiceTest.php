<?php
declare(strict_types=1);

namespace Tests;

use app\service\PlatformDataSyncService;
use app\service\PlatformNormalizedRowPersistenceService;
use app\service\OnlineDataFieldFactService;
use app\service\platform\CtripBrowserProfileDataSourceAdapter;
use app\service\platform\MeituanBrowserProfileDataSourceAdapter;
use PHPUnit\Framework\TestCase;
use Tests\Support\PlatformDataSyncBrowserProfileFixture;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class PlatformDataSyncServiceTest extends TestCase
{
    use PlatformDataSyncBrowserProfileFixture;

    private static array $originalDatabaseConfig = [];
    private static string $databaseConnection = '';
    private static string $databasePath = '';

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$databaseConnection = 'platform_data_sync_unit_' . getmypid();
        self::$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::$databaseConnection . '.sqlite';
        @unlink(self::$databasePath);

        $database = self::$originalDatabaseConfig;
        $database['default'] = self::$databaseConnection;
        $database['connections'][self::$databaseConnection] = [
            'type' => 'sqlite',
            'database' => self::$databasePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($database, 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)');
        Db::name('hotels')->insertAll([
            ['id' => 7, 'tenant_id' => 1],
            ['id' => 58, 'tenant_id' => 1],
            ['id' => 80, 'tenant_id' => 1],
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Db::connect(self::$databaseConnection)->close();
        } catch (\Throwable) {
        }
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$databasePath);
    }

    public function testCtripTaskStatsKeepOnlyBoundedFlowMetadata(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'syncTaskFlowStatsFromOptions');
        $method->setAccessible(true);

        self::assertSame([
            'collector_flow' => 'future_demand',
            'capture_plan' => 'future_demand',
            'data_date' => '2026-07-30',
        ], $method->invoke($service, [
            'collector_flow' => 'future_demand',
            'capture_plan' => 'future_demand',
            'data_date' => '2026-07-30',
            'payload' => ['must_not_persist' => true],
        ]));
        self::assertSame([], $method->invoke($service, [
            'collector_flow' => 'unknown',
            'capture_plan' => '../../unsafe',
            'data_date' => 'not-a-date',
        ]));
        self::assertSame([
            'collector_flow' => 'future_demand',
            'dispatcher_run_id' => '12345678-1234-4234-8234-123456789abc',
        ], $method->invoke($service, [
            'collector_flow' => 'future_demand',
            'dispatcher_run_id' => '12345678-1234-4234-8234-123456789ABC',
        ]));
        self::assertSame([], $method->invoke($service, [
            'dispatcher_run_id' => 'manual-or-malformed-run-id',
        ]));
    }

    public function testExplicitReviewOnlyCaptureDoesNotRequireTrafficEvidence(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'syncRequiresTargetDateTrafficEvidence');
        $method->setAccessible(true);
        $source = [
            'platform' => 'meituan',
            'data_type' => 'business',
            'ingestion_method' => 'browser_profile',
        ];
        $payload = [
            'sync_summary' => [
                'traffic_count' => 0,
                'traffic_forecast_count' => 0,
                'review_count' => 1,
            ],
        ];

        self::assertFalse($method->invoke($service, $source, ['capture_sections' => 'reviews'], $payload));
        self::assertTrue($method->invoke($service, $source, ['capture_sections' => 'traffic'], $payload));
    }

    public function testRunReadbackSanitizerKeepsOnlyCanonicalDispatcherEvidence(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'sanitizeRunReadbackReceipt');
        $method->setAccessible(true);

        $safe = $method->invoke($service, [
            'dispatcher_run_id' => '12345678-1234-4234-8234-123456789ABC',
            'trigger_type' => 'daily_profile_reuse',
        ]);
        self::assertSame('12345678-1234-4234-8234-123456789abc', $safe['dispatcher_run_id']);
        self::assertSame('daily_profile_reuse', $safe['trigger_type']);

        $unsafe = $method->invoke($service, [
            'dispatcher_run_id' => 'forged',
            'trigger_type' => '../../manual',
        ]);
        self::assertArrayNotHasKey('dispatcher_run_id', $unsafe);
        self::assertArrayNotHasKey('trigger_type', $unsafe);
    }

    public function testAuthoritativeEmptySyncPayloadIsRecognized(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'isAuthoritativeEmptySyncPayload');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($service, ['sync_summary' => ['confirmed_empty' => true]]));
        self::assertFalse($method->invoke($service, ['sync_summary' => ['confirmed_empty' => false]]));
        self::assertFalse($method->invoke($service, []));
    }

    public function testCurrentRunMetricReceiptUsesVerifiedSelfRowsOnly(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'verifiedCoreMetricKeysFromRunRows');
        $method->setAccessible(true);
        $facts = [
            ['metric_key' => 'order_amount', 'status' => 'captured', 'stored_value_present' => true, 'source_key' => 'orderAmount'],
            ['metric_key' => 'room_nights', 'status' => 'captured', 'stored_value_present' => true, 'source_key' => 'roomNights'],
        ];
        $rows = [[
            'hotel_id' => 'MT-SELF-80',
            'hotel_name' => '本店',
            'data_type' => 'business',
            'amount' => 1200.0,
            'quantity' => 3,
            'raw_data' => json_encode([
                'row' => ['is_self' => true, 'poi_id' => 'MT-SELF-80'],
                'field_facts' => $facts,
            ], JSON_UNESCAPED_UNICODE),
        ], [
            'hotel_id' => 'MT-PEER-1',
            'hotel_name' => '竞店',
            'data_type' => 'competitor_avg',
            'compare_type' => 'competitor',
            'amount' => 99999.0,
            'quantity' => 1,
            'raw_data' => json_encode([
                'row' => ['is_self' => false, 'poi_id' => 'MT-PEER-1'],
                'field_facts' => $facts,
            ], JSON_UNESCAPED_UNICODE),
        ]];
        $source = [
            'name' => '本店',
            'platform' => 'meituan',
            'config_json' => json_encode(['poi_id' => 'MT-SELF-80'], JSON_UNESCAPED_UNICODE),
        ];

        self::assertSame(
            ['revenue', 'room_nights', 'adr'],
            $method->invoke($service, $rows, $source)
        );

        $rows[0]['raw_data'] = json_encode([
            'row' => ['is_self' => true, 'poi_id' => 'MT-SELF-80'],
            'field_facts' => [$facts[0]],
        ], JSON_UNESCAPED_UNICODE);
        self::assertSame(['revenue'], $method->invoke($service, $rows, $source));

        $rows[0]['raw_data'] = json_encode([
            'row' => ['poi_id' => 'MT-SELF-80'],
            'field_facts' => $facts,
        ], JSON_UNESCAPED_UNICODE);
        self::assertSame([], $method->invoke($service, $rows, $source));
    }

    public function testAuthoritativeEmptyOrderCaptureVerifiesOnlyOrderCapability(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'applyConfirmedEmptyCapabilityStates');
        $method->setAccessible(true);
        $states = ['business' => 'unverified', 'orders' => 'unverified', 'reviews' => 'unverified'];

        $result = $method->invoke($service, $states, ['capture_sections' => 'orders'], []);

        self::assertSame('verified', $result['orders']);
        self::assertSame('unverified', $result['reviews']);
        self::assertSame('unverified', $result['business']);
    }

    public function testMixedCaptureDiagnosticsPreserveEmptyAndNotApplicableSectionEvidence(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'buildSyncDiagnostics');
        $method->setAccessible(true);
        $diagnostics = $method->invoke(
            $service,
            [
                ['data_date' => '2026-07-19', 'data_type' => 'traffic'],
                ['data_date' => '2026-07-19', 'data_type' => 'order'],
            ],
            2,
            ['platform' => 'custom', 'data_type' => 'business', 'ingestion_method' => 'manual'],
            [
                'data_date' => '2026-07-19',
                'capture_sections' => 'traffic,orders,reviews',
                'skipped_sections_no_entry' => ['ads'],
            ],
            [
                'capture_gate' => [
                    'status' => 'pass',
                    'requested_sections' => ['traffic', 'orders', 'reviews'],
                    'section_statuses' => [
                        'traffic' => 'captured',
                        'orders' => 'captured',
                        'reviews' => 'empty_confirmed',
                    ],
                ],
            ],
            'success',
            'Platform data synchronized.'
        );

        self::assertSame([
            'traffic' => 'captured',
            'orders' => 'captured',
            'reviews' => 'empty_confirmed',
            'ads' => 'not_applicable',
        ], $diagnostics['capture_section_statuses']);
        self::assertSame('verified', $diagnostics['capability_states']['reviews']);

        $sanitize = new \ReflectionMethod($service, 'sanitizeSyncDiagnosticsForResponse');
        $sanitize->setAccessible(true);
        $safe = $sanitize->invoke($service, $diagnostics, 'success');
        self::assertSame($diagnostics['capture_section_statuses'], $safe['capture_section_statuses']);
        self::assertSame('verified', $safe['capability_states']['reviews']);
    }

    public function testBrowserProfileSyncDiagnosticsRequiresTargetDateTrafficFieldFacts(): void
    {
        $service = new PlatformDataSyncService();
        $source = [
            'id' => 77,
            'name' => 'Ctrip Profile Traffic',
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'ingestion_method' => 'browser_profile',
            'system_hotel_id' => 58,
            'tenant_id' => 1,
            'config' => [
                'manual_login_state_verified' => true,
                'profile_status' => 'logged_in',
                'last_login_verified_at' => '2026-07-04 10:00:00',
            ],
        ];
        $options = [
            'trigger_type' => 'daily_profile_reuse',
            'data_date' => '2026-06-29',
            'capture_sections' => 'traffic',
        ];
        $rows = $service->normalizeRowsFromPayload([
            'rows' => [[
                'hotel_id' => '24588',
                'data_date' => '2026-06-29',
                'data_type' => 'traffic',
                'list_exposure' => 100,
                'detail_exposure' => 20,
                'flow_rate' => 0.2,
                'order_filling_num' => 8,
                'order_submit_num' => 4,
                'source_trace_id' => 'ctrip:traffic-ready-20260629',
                'source_url_hash' => str_repeat('a', 64),
            ]],
        ], $source, 88);
        $method = new \ReflectionMethod($service, 'buildSyncDiagnostics');
        $method->setAccessible(true);
        $ready = $method->invoke($service, $rows, 1, $source, $options, ['data_date' => '2026-06-29'], 'success', 'ok');

        self::assertSame('blocked', $ready['p0_status']);
        self::assertSame(1, $ready['target_date_traffic_rows']);
        self::assertSame('ready', $ready['field_fact_status']);
        self::assertGreaterThan(0, $ready['target_date_traffic_field_fact_ready_count']);
        self::assertContains('current_session_verified', $ready['missing_inputs']);
        self::assertSame('current_session_not_verified', $ready['operator_message']);

        $blocked = $method->invoke($service, [], 0, $source, $options, ['data_date' => '2026-06-29'], 'success', 'ok');
        self::assertSame('blocked', $blocked['p0_status']);
        self::assertContains('target_date_traffic_rows', $blocked['missing_inputs']);
        self::assertContains('current_session_verified', $blocked['missing_inputs']);
        self::assertSame('current_session_not_verified', $blocked['operator_message']);
    }

    public function testCtripTrafficP0AcceptsRequiredMetricEvidenceAcrossEndpointRows(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'targetTrafficP0Closure');
        $method->setAccessible(true);
        $targetDate = '2026-07-30';
        $rows = [];
        foreach ([
            'list_exposure' => 296,
            'detail_exposure' => 74,
            'flow_rate' => 25,
            'order_filling_num' => 2,
            'order_submit_num' => 1,
        ] as $metricKey => $value) {
            $traceId = 'ctrip:traffic:' . $metricKey;
            $sourceUrlHash = hash('sha256', $traceId);
            $rows[] = [
                'data_date' => $targetDate,
                'data_type' => 'traffic',
                'platform' => 'ctrip',
                'compare_type' => 'self',
                'dimension' => 'catalog:traffic_report:' . $metricKey,
                $metricKey => $value,
                'source_trace_id' => $traceId,
                'raw_data' => [
                    'source_trace_id' => $traceId,
                    'source_url_hash' => $sourceUrlHash,
                    'platform_hotel_identifier_present' => true,
                    'platform_hotel_identifier_source' => 'hotel_id_family',
                    'platform_hotel_identifier_proof' => 'row_field_present',
                    'field_facts' => [[
                        'metric_key' => $metricKey,
                        'source_path' => 'data.' . $metricKey,
                        'storage_field' => 'online_daily_data.' . $metricKey,
                        'stored_value_present' => true,
                        'capture_evidence' => [
                            'source_trace_id' => $traceId,
                            'source_url_hash' => $sourceUrlHash,
                        ],
                    ]],
                ],
            ];
        }
        $rows[] = [
            'data_date' => $targetDate,
            'data_type' => 'traffic',
            'platform' => 'ctrip',
            'compare_type' => 'self',
            'dimension' => 'catalog:traffic_report:future_search_detail',
            'source_trace_id' => 'ctrip:traffic:future-search',
            'raw_data' => [
                'platform_hotel_identifier_present' => true,
                'platform_hotel_identifier_source' => 'hotel_id_family',
                'platform_hotel_identifier_proof' => 'row_field_present',
                'field_facts' => [],
            ],
        ];
        $rows[] = [
            'data_date' => $targetDate,
            'data_type' => 'traffic',
            'platform' => 'ctrip',
            'compare_type' => 'self',
            'validation_status' => 'quarantined',
            'list_exposure' => 999999,
            'source_trace_id' => 'ctrip:traffic:quarantined',
            'raw_data' => [
                'platform_hotel_identifier_present' => true,
                'platform_hotel_identifier_source' => 'hotel_id_family',
                'platform_hotel_identifier_proof' => 'row_field_present',
                'field_facts' => [],
            ],
        ];

        $closure = $method->invoke($service, $rows, 'ctrip', $targetDate);

        self::assertSame(6, $closure['traffic_row_count']);
        self::assertSame([
            'list_exposure',
            'detail_exposure',
            'flow_rate',
            'order_filling_num',
            'order_submit_num',
        ], $closure['complete_metric_keys']);
        self::assertSame([], $closure['missing_metric_keys']);
        self::assertSame(5, $closure['field_fact_ready_count']);
        self::assertSame(0, $closure['field_fact_missing_count']);
        self::assertTrue($closure['platform_hotel_identifier_ready']);
        self::assertTrue($closure['ui_status_ready']);
    }

    public function testCtripRealtimeP0RequiresOnlyFactsAvailableFromSameDayEndpoints(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'targetTrafficP0Closure');
        $method->setAccessible(true);
        $targetDate = '2026-08-16';
        $rows = [];
        foreach ([
            ['visitor_count', 'detail_exposure', 12],
            ['order_submit_user', 'order_submit_num', 3],
        ] as [$factMetricKey, $storageMetricKey, $value]) {
            $traceId = 'ctrip:realtime:' . $storageMetricKey;
            $sourceUrlHash = hash('sha256', $traceId);
            $rows[] = [
                'data_date' => $targetDate,
                'data_period' => 'realtime_snapshot',
                'data_type' => 'traffic',
                'platform' => 'ctrip',
                'compare_type' => 'self',
                $storageMetricKey => $value,
                'source_trace_id' => $traceId,
                'raw_data' => [
                    'source_trace_id' => $traceId,
                    'source_url_hash' => $sourceUrlHash,
                    'platform_hotel_identifier_present' => true,
                    'platform_hotel_identifier_source' => 'hotel_id_family',
                    'platform_hotel_identifier_proof' => 'row_field_present',
                    'field_facts' => [[
                        'metric_key' => $factMetricKey,
                        'source_path' => 'data.' . $factMetricKey,
                        'storage_field' => 'online_daily_data.' . $storageMetricKey,
                        'stored_value_present' => true,
                        'capture_evidence' => [
                            'source_trace_id' => $traceId,
                            'source_url_hash' => $sourceUrlHash,
                        ],
                    ]],
                ],
            ];
        }

        $closure = $method->invoke($service, $rows, 'ctrip', $targetDate);

        self::assertSame(['detail_exposure', 'order_submit_num'], $closure['required_metric_keys']);
        self::assertSame(['detail_exposure', 'order_submit_num'], $closure['complete_metric_keys']);
        self::assertSame([], $closure['missing_metric_keys']);
        self::assertSame(2, $closure['field_fact_ready_count']);
        self::assertTrue($closure['platform_hotel_identifier_ready']);
        self::assertTrue($closure['ui_status_ready']);
    }

    public function testCtripDailyBusinessOverviewKeepsCheckoutFieldsSeparateFromBookingFields(): void
    {
        $service = new PlatformDataSyncService();
        $rows = $service->normalizeRowsFromPayload([
            'rows' => [[
                'hotel_id' => '130079194',
                'data_date' => '2026-07-24',
                'data_type' => 'business',
                'endpoint_id' => 'business_market_overview',
                'section' => 'business_overview',
                'amount' => 429,
                'quantity' => 6,
                'bookAmount' => 678,
                'bookQuantity' => 1,
                'bookOrderNum' => 0,
                '_source_path' => 'data.data',
                'source_trace_id' => 'ctrip:daily-business-overview',
                'source_url_hash' => str_repeat('b', 64),
            ]],
        ], [
            'id' => 25,
            'platform' => 'ctrip',
            'data_type' => 'business',
            'ingestion_method' => 'browser_profile',
            'system_hotel_id' => 80,
            'tenant_id' => 1,
        ], 1576);

        self::assertCount(2, $rows);
        $checkout = $rows[0];
        $booking = $rows[1];
        self::assertSame(429.0, $checkout['amount']);
        self::assertSame(6, $checkout['quantity']);
        self::assertNull($checkout['book_order_num']);
        self::assertNull($checkout['flow_rate']);
        self::assertSame('normal', $checkout['validation_status']);
        $facts = json_decode((string)$checkout['raw_data'], true)['field_facts'] ?? [];
        $byMetric = [];
        foreach ($facts as $fact) {
            $byMetric[(string)$fact['metric_key']] = $fact;
        }
        self::assertSame('amount', $byMetric['order_amount']['source_key']);
        self::assertSame('quantity', $byMetric['room_nights']['source_key']);
        self::assertSame('missing', $byMetric['order_count']['status']);
        self::assertSame('optional_missing', $byMetric['order_count']['missing_state']);

        self::assertNull($booking['amount']);
        self::assertNull($booking['quantity']);
        self::assertSame(0, $booking['book_order_num']);
        self::assertSame('normal', $booking['validation_status']);
        self::assertSame('semantic:ctrip_business_market_overview:booking_order_count', $booking['dimension']);
        self::assertNotSame($checkout['persistence_identity_hash'], $booking['persistence_identity_hash']);
        $bookingRaw = json_decode((string)$booking['raw_data'], true, 512, JSON_THROW_ON_ERROR);
        $bookingFacts = array_column($bookingRaw['field_facts'] ?? [], null, 'metric_key');
        self::assertSame('captured', $bookingFacts['order_count']['status'] ?? '');
        self::assertTrue($bookingFacts['order_count']['stored_value_present'] ?? false);
        self::assertSame('bookOrderNum', $bookingFacts['order_count']['source_key'] ?? '');
        self::assertSame('data.data.bookOrderNum', $bookingFacts['order_count']['source_path'] ?? '');
        self::assertSame('ctrip_market_overview_booking_order_count', $bookingFacts['order_count']['semantic_key'] ?? '');
        self::assertSame('booking', $bookingRaw['metric_projection']['metric_family'] ?? '');
        self::assertSame('2026-07-24', $bookingRaw['metric_projection']['business_date'] ?? '');
        self::assertSame(0, $bookingRaw['row']['bookOrderNum'] ?? null);
        self::assertArrayNotHasKey('amount', $bookingRaw['row'] ?? []);
        self::assertArrayNotHasKey('quantity', $bookingRaw['row'] ?? []);
        self::assertArrayNotHasKey('flowRate', $bookingRaw['row'] ?? []);
    }

    public function testCtripMarketOverviewRejectsNonIntegerBookingCountProjection(): void
    {
        $rows = (new PlatformDataSyncService())->normalizeRowsFromPayload([
            'rows' => [[
                'hotel_id' => '130079194',
                'data_date' => '2026-07-24',
                'data_type' => 'business',
                'endpoint_id' => 'business_market_overview',
                'section' => 'business_overview',
                'amount' => 429,
                'quantity' => 6,
                'bookOrderNum' => '0.5',
                'source_trace_id' => 'ctrip:daily-business-overview-invalid-order-count',
                'source_url_hash' => str_repeat('c', 64),
            ]],
        ], [
            'id' => 25,
            'platform' => 'ctrip',
            'data_type' => 'business',
            'ingestion_method' => 'browser_profile',
            'system_hotel_id' => 80,
            'tenant_id' => 1,
        ], 1577);

        self::assertCount(1, $rows);
        self::assertSame(429.0, $rows[0]['amount']);
        self::assertSame(6, $rows[0]['quantity']);
        self::assertNull($rows[0]['book_order_num']);
        self::assertSame('normal', $rows[0]['validation_status']);
    }

    public function testNormalizedFieldFactsDoNotCrossLabelCtripAsMeituan(): void
    {
        $service = new PlatformDataSyncService();
        $metricKeysByPlatform = [];
        foreach (['ctrip', 'meituan'] as $platform) {
            $traceId = $platform . ':' . str_repeat('a', 64);
            $identifier = $platform === 'ctrip'
                ? '130079194'
                : '1029642156589279';
            $identifierField = $platform === 'ctrip'
                ? 'hotel_id'
                : 'poi_id';
            $rows = $service->normalizeRowsFromPayload(['rows' => [[
                $identifierField => $identifier,
                'data_date' => '2026-07-29',
                'data_type' => 'traffic',
                'listExposure' => 58,
                'intentionUV' => 4,
                'payOrderCnt' => 1,
                '_capture_source' => 'xhr:traffic',
                '_source_path' => 'data',
                'source_trace_id' => $traceId,
                'capture_evidence' => [
                    'source_trace_id' => $traceId,
                    'source_url_hash' => str_repeat('b', 64),
                    'capture_source' => 'xhr:traffic',
                    'source_path' => 'data',
                ],
            ]]], [
                'id' => $platform === 'ctrip' ? 25 : 68,
                'platform' => $platform,
                'data_type' => 'traffic',
                'system_hotel_id' => 80,
                'tenant_id' => 1,
                'ingestion_method' => 'browser_profile',
                'config' => [$identifierField => $identifier],
            ], 2125);

            self::assertCount(1, $rows);
            $raw = json_decode((string)$rows[0]['raw_data'], true);
            $metricKeysByPlatform[$platform] = array_column(
                $raw['field_facts'] ?? [],
                'metric_key'
            );
        }

        self::assertContains('list_exposure', $metricKeysByPlatform['ctrip']);
        self::assertNotContains('mt_exposure', $metricKeysByPlatform['ctrip']);
        self::assertNotContains('mt_intention_uv', $metricKeysByPlatform['ctrip']);
        self::assertContains('mt_exposure', $metricKeysByPlatform['meituan']);
        self::assertContains('mt_intention_uv', $metricKeysByPlatform['meituan']);
    }

    public function testProfileLoginAfterLoginTriggerRequiresTargetDateTrafficRows(): void
    {
        $service = new PlatformDataSyncService();
        $source = [
            'id' => 78,
            'name' => 'Ctrip Profile Business',
            'platform' => 'ctrip',
            'data_type' => 'business',
            'ingestion_method' => 'browser_profile',
            'system_hotel_id' => 58,
            'config' => [
                'manual_login_state_verified' => true,
                'profile_status' => 'logged_in',
                'last_login_verified_at' => '2026-07-04 10:00:00',
            ],
        ];
        $options = [
            'trigger_type' => 'profile_login_after_login',
            'data_date' => '2026-06-29',
        ];
        $method = new \ReflectionMethod($service, 'buildSyncDiagnostics');
        $method->setAccessible(true);

        $diagnostics = $method->invoke($service, [], 0, $source, $options, ['data_date' => '2026-06-29'], 'success', 'ok');

        self::assertTrue($diagnostics['requires_target_date_traffic']);
        self::assertSame('blocked', $diagnostics['p0_status']);
        self::assertContains('target_date_traffic_rows', $diagnostics['missing_inputs']);
    }

    public function testOtaPlatformDataSourceRejectsPasswordCustody(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'normalizeSourcePayload');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OTA account password custody is not supported');

        $method->invoke($service, [
            'name' => 'Ctrip profile source',
            'system_hotel_id' => 58,
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'ingestion_method' => 'browser_profile',
            'profile_id' => 'ctrip_58',
            'password' => 'user-password',
        ]);
    }

    public function testBrowserProfileBackgroundSyncRequiresReusableSessionProof(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'assertBrowserProfileBackgroundSyncLoginVerified');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('profile_session_unverified');

        $method->invoke($service, [
            'id' => 79,
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'ingestion_method' => 'browser_profile',
            'system_hotel_id' => 58,
            'config' => [
                'profile_id' => 'hotel_001',
                'profile_status' => 'logged_in',
                'last_login_verified_at' => '2026-07-04 10:00:00',
                'profile_daily_reuse_enabled' => true,
            ],
        ], [
            'trigger_type' => 'daily_profile_reuse',
            'interactive_browser' => false,
        ]);
    }

    public function testBrowserProfileBackgroundSyncReportsUnverifiedHistoricalStatus(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'browserProfileBackgroundSyncLoginMissingRequirements');
        $method->setAccessible(true);

        $missing = $method->invoke($service, [
            'id' => 80,
            'platform' => 'meituan',
            'data_type' => 'traffic',
            'ingestion_method' => 'browser_profile',
            'system_hotel_id' => 58,
            'config' => [
                'store_id' => 'store_001',
                'manual_login_state_verified' => true,
                'profile_status' => 'profile_found_login_unverified',
            ],
        ], [
            'trigger_type' => 'daily_profile_reuse',
            'interactive_browser' => false,
        ]);

        self::assertSame(['profile_session_unverified'], $missing);
    }

    public function testBrowserProfileBackgroundSyncStopsAfterRiskControlUntilManualReview(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'browserProfileBackgroundSyncLoginMissingRequirements');
        $method->setAccessible(true);

        $missing = $method->invoke($service, [
            'id' => 801,
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'ingestion_method' => 'browser_profile',
            'system_hotel_id' => 58,
            'config' => [
                'profile_id' => 'hotel_001',
                'current_session_status' => 'anti_bot',
                'current_session_backoff_until' => '2099-01-01 00:00:00',
            ],
        ], [
            'trigger_type' => 'daily_profile_reuse',
            'interactive_browser' => false,
        ]);

        self::assertSame(['profile_risk_control_manual_review_required'], $missing);
    }

    public function testBrowserProfileInteractiveSynchronizationDoesNotRequireCurrentSessionProof(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'assertBrowserProfileBackgroundSyncLoginVerified');
        $method->setAccessible(true);

        self::assertNull($method->invoke($service, [
            'id' => 81,
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'ingestion_method' => 'browser_profile',
            'system_hotel_id' => 58,
            'config' => [
                'profile_id' => 'hotel_001',
            ],
        ], [
            'trigger_type' => 'manual',
            'interactive_browser' => true,
        ]));

    }

    public function testRequiredCurrentRunProfileSessionProbeAcceptsOnlyThisRunAuthAndMatchedHotelIdentity(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'assertRequiredCurrentRunProfileSessionProbe');
        $method->setAccessible(true);
        $source = [
            'platform' => 'ctrip',
            'ingestion_method' => 'browser_profile',
        ];
        $options = [
            'require_current_run_session_probe' => true,
            // Production OTA hotel identifiers are commonly numeric strings;
            // PHP must not turn the dedupe key into an int before hash_equals.
            'required_platform_hotel_id' => '130079194',
        ];

        self::assertNull($method->invoke($service, $source, $options, [
            'status' => 'success',
            'payload' => [
                'network_freshness' => $this->readyNetworkFreshness(),
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                'platform_identity_validation' => [
                    'schema_version' => 1,
                    'status' => 'matched',
                    'source_validation' => true,
                    'validated_identifier' => '130079194',
                    'sensitive_values_exposed' => false,
                ],
            ],
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Current session proof from this execution is missing');
        $method->invoke($service, $source, $options, [
            'status' => 'success',
            'payload' => [
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                'platform_identity_validation' => [
                    'schema_version' => 1,
                    'status' => 'mismatch',
                    'source_validation' => false,
                    'validated_identifier' => 'hotel-81',
                    'sensitive_values_exposed' => false,
                ],
            ],
        ]);
    }

    public function testRequiredCurrentRunProfileSessionProbeKeepsNumericPlatformHotelIdsAsStrings(): void
    {
        $service = new PlatformDataSyncService();
        $identifiersMethod = new \ReflectionMethod($service, 'requiredCurrentRunPlatformHotelIds');
        $probeMethod = new \ReflectionMethod($service, 'assertRequiredCurrentRunProfileSessionProbe');
        $source = [
            'platform' => 'ctrip',
            'ingestion_method' => 'browser_profile',
            'config' => [
                'platform_hotel_id' => '130079194',
            ],
        ];
        $options = [
            'require_current_run_session_probe' => true,
            'required_platform_hotel_id' => '130079194',
        ];

        self::assertSame(
            ['130079194'],
            $identifiersMethod->invoke($service, $source, $options)
        );
        self::assertNull($probeMethod->invoke($service, $source, $options, [
            'status' => 'success',
            'payload' => [
                'network_freshness' => $this->readyNetworkFreshness(),
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                'platform_identity_validation' => [
                    'schema_version' => 1,
                    'status' => 'matched',
                    'source_validation' => true,
                    'evidence_source' => 'trusted_ota_page_state',
                    'validated_identifier' => '130079194',
                    'sensitive_values_exposed' => false,
                ],
            ],
        ]));
    }

    public function testRequiredCurrentRunProfileSessionProbeRejectsEveryMissingOrDriftedFact(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'assertRequiredCurrentRunProfileSessionProbe');
        $source = [
            'platform' => 'meituan',
            'ingestion_method' => 'browser_profile',
        ];
        $options = [
            'require_current_run_session_probe' => true,
            'required_platform_hotel_id' => 'hotel-80',
        ];
        $auth = ['ok' => true, 'status' => 'authorized'];
        $networkFreshness = $this->readyNetworkFreshness();
        $identity = [
            'schema_version' => 1,
            'status' => 'matched',
            'source_validation' => true,
            'validated_identifier' => 'hotel-80',
            'sensitive_values_exposed' => false,
        ];
        $cases = [
            'network freshness missing' => ['auth_status' => $auth, 'platform_identity_validation' => $identity],
            'network freshness blocked' => ['network_freshness' => [...$networkFreshness, 'status' => 'blocked'], 'auth_status' => $auth, 'platform_identity_validation' => $identity],
            'http cache enabled' => ['network_freshness' => [...$networkFreshness, 'http_cache_disabled' => false], 'auth_status' => $auth, 'platform_identity_validation' => $identity],
            'service worker active' => ['network_freshness' => [...$networkFreshness, 'service_worker_bypassed' => false], 'auth_status' => $auth, 'platform_identity_validation' => $identity],
            'network sensitive marker missing' => ['network_freshness' => array_diff_key($networkFreshness, ['sensitive_values_exposed' => true]), 'auth_status' => $auth, 'platform_identity_validation' => $identity],
            'network sensitive marker set' => ['network_freshness' => [...$networkFreshness, 'sensitive_values_exposed' => true], 'auth_status' => $auth, 'platform_identity_validation' => $identity],
            'auth missing' => ['network_freshness' => $networkFreshness, 'platform_identity_validation' => $identity],
            'auth false' => ['network_freshness' => $networkFreshness, 'auth_status' => ['ok' => false, 'status' => 'login_required'], 'platform_identity_validation' => $identity],
            'auth status drift' => ['network_freshness' => $networkFreshness, 'auth_status' => ['ok' => true, 'status' => 'profile_reused'], 'platform_identity_validation' => $identity],
            'identity missing' => ['network_freshness' => $networkFreshness, 'auth_status' => $auth],
            'identity schema missing' => ['network_freshness' => $networkFreshness, 'auth_status' => $auth, 'platform_identity_validation' => array_diff_key($identity, ['schema_version' => true])],
            'source validation missing' => ['network_freshness' => $networkFreshness, 'auth_status' => $auth, 'platform_identity_validation' => array_diff_key($identity, ['source_validation' => true])],
            'identity mismatch' => ['network_freshness' => $networkFreshness, 'auth_status' => $auth, 'platform_identity_validation' => [...$identity, 'status' => 'mismatch', 'source_validation' => false]],
            'wrong platform hotel' => ['network_freshness' => $networkFreshness, 'auth_status' => $auth, 'platform_identity_validation' => [...$identity, 'validated_identifier' => 'hotel-81']],
            'sensitive marker' => ['network_freshness' => $networkFreshness, 'auth_status' => $auth, 'platform_identity_validation' => [...$identity, 'sensitive_values_exposed' => true]],
        ];

        foreach ($cases as $label => $payload) {
            try {
                $method->invoke($service, $source, $options, [
                    'status' => 'success',
                    'payload' => $payload,
                ]);
                self::fail($label . ' must not reach raw or normalized persistence.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString(
                    'Current session proof from this execution is missing',
                    $exception->getMessage(),
                    $label
                );
            }
        }
    }

    public function testCtripResponseIdentityEvidenceSatisfiesCurrentRunProofWithoutCloudBinding(): void
    {
        $service = new PlatformDataSyncService();
        $sessionGuard = new \ReflectionMethod($service, 'assertRequiredCurrentRunProfileSessionProbe');
        $bindingGuard = new \ReflectionMethod($service, 'assertRequiredCollectorBinding');
        $source = [
            'platform' => 'ctrip',
            'ingestion_method' => 'browser_profile',
        ];
        $options = [
            'require_collector_binding' => false,
            'require_current_run_session_probe' => true,
            'required_platform_hotel_id' => 'hotel-80',
            'required_collector_binding' => [],
        ];

        self::assertNull($bindingGuard->invoke($service, $source, $options));
        self::assertNull($sessionGuard->invoke($service, $source, $options, [
            'status' => 'success',
            'payload' => [
                'network_freshness' => $this->readyNetworkFreshness(),
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                'platform_identity_validation' => [
                    'schema_version' => 1,
                    'status' => 'matched',
                    'evidence_source' => 'ota_request',
                    'validated_identifier' => 'hotel-80',
                    'sensitive_values_exposed' => false,
                ],
            ],
        ]));
    }

    public function testMeituanCurrentRunProofAcceptsOnlyConfiguredStoreOrPoiIdentityAliases(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'assertRequiredCurrentRunProfileSessionProbe');
        $source = [
            'platform' => 'meituan',
            'ingestion_method' => 'browser_profile',
            'config' => [
                'store_id' => 'store-h80',
                'poi_id' => 'poi-h80',
            ],
        ];
        $options = ['require_current_run_session_probe' => true];
        foreach (['store-h80', 'poi-h80'] as $validatedIdentifier) {
            self::assertNull($method->invoke($service, $source, $options, [
                'status' => 'success',
                'payload' => [
                    'network_freshness' => $this->readyNetworkFreshness(),
                    'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                    'platform_identity_validation' => [
                        'schema_version' => 1,
                        'status' => 'matched',
                        'source_validation' => true,
                        'validated_identifier' => $validatedIdentifier,
                    ],
                ],
            ]));
        }

        try {
            $method->invoke($service, $source, $options, [
                'status' => 'success',
                'payload' => [
                    'network_freshness' => $this->readyNetworkFreshness(),
                    'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                    'platform_identity_validation' => [
                        'schema_version' => 1,
                        'status' => 'matched',
                        'source_validation' => true,
                        'validated_identifier' => 'another-store',
                    ],
                ],
            ]);
            self::fail('An identifier outside the exact source store/POI aliases must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('outside the bound platform hotel', $exception->getMessage());
        }
    }

    public function testFreshPostLoginCollectionMayRunOnceToVerifyHotelIdentity(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'browserProfileBackgroundSyncLoginMissingRequirements');
        $method->setAccessible(true);

        $missing = $method->invoke($service, [
            'id' => 811,
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'ingestion_method' => 'browser_profile',
            'system_hotel_id' => 58,
            'config' => [
                'profile_id' => 'hotel_001',
                'current_session_probe_performed' => true,
                'current_session_verified' => false,
                'current_session_status' => 'identity_unverified',
            ],
        ], [
            'trigger_type' => 'profile_login_after_login',
            'interactive_browser' => false,
        ]);

        self::assertSame([], $missing);
    }

    public function testDailyProfileReuseMayProbeOldIdentityStateOnlyWhenCurrentPageProofMatches(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'browserProfileBackgroundSyncLoginMissingRequirements');
        $method->setAccessible(true);
        $source = [
            'id' => 812,
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'ingestion_method' => 'browser_profile',
            'system_hotel_id' => 58,
            'config' => [
                'profile_id' => 'hotel_001',
                'current_session_probe_performed' => true,
                'current_session_verified' => false,
                'current_session_status' => 'identity_unverified',
            ],
        ];

        self::assertSame([], $method->invoke($service, $source, [
            'trigger_type' => 'daily_profile_reuse',
            'interactive_browser' => false,
        ]));

        $source['config']['current_session_status'] = 'identity_mismatch';
        self::assertSame(['profile_hotel_identity_mismatch'], $method->invoke($service, $source, [
            'trigger_type' => 'daily_profile_reuse',
            'interactive_browser' => false,
        ]));

        $today = date('Y-m-d');
        $source['tenant_id'] = 80;
        $source['config'] = array_replace($source['config'], [
            'profile_status' => 'logged_in',
            'login_status' => 'logged_in',
            'current_session_probe_at' => $today . ' 08:40:00',
            'current_session_probe_date' => $today,
            'current_session_probe_data_source_id' => 812,
            'current_session_probe_tenant_id' => 80,
            'current_session_probe_system_hotel_id' => 58,
            'current_session_probe_platform' => 'ctrip',
            'current_session_probe_scope' => 'same_data_source_profile_session',
            'current_session_probe_evidence_level' => 'strong',
            'current_session_probe_evidence_type' => 'successful_collection_preflight_identity_matched',
            'current_session_probe_identity_status' => 'matched',
        ]);
        self::assertSame([], $method->invoke($service, $source, [
            'trigger_type' => 'daily_profile_reuse',
            'interactive_browser' => false,
        ]));
    }

    public function testOrderedCollectionTaskPlanSanitizesToNonSecretScopeOnly(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'sanitizeOrderedCollectionTaskPlan');
        $method->setAccessible(true);
        $safe = $method->invoke($service, [
            'contract_version' => 'ota_ordered_collection.v1',
            'mode' => 'ordered_yesterday_gap_only',
            'scope' => 'ota_yesterday_core',
            'platform' => 'ctrip',
            'target_date' => '2026-07-24',
            'stage' => 'targeted_gap',
            'reason' => 'target_date_field_gap',
            'sections' => ['traffic_report', '../cookie'],
            'interface_ids' => ['traffic_flow_transform'],
            'required_field_keys' => ['list_exposure'],
            'captured_field_keys' => [],
            'missing_field_keys' => ['list_exposure'],
            'excluded_example_capabilities' => ['comments', 'ads'],
            'cookie' => 'must-not-survive',
        ], [
            'id' => 25,
            'system_hotel_id' => 80,
            'platform' => 'ctrip',
        ]);

        self::assertSame(25, $safe['data_source_id']);
        self::assertSame(80, $safe['system_hotel_id']);
        self::assertSame(['traffic_report'], $safe['sections']);
        self::assertArrayNotHasKey('cookie', $safe);
        self::assertStringNotContainsString('must-not-survive', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    public function testSyncTaskStatsPreserveOrderedPlanAndP0ReadbackFields(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'sanitizeSyncTaskStats');
        $method->setAccessible(true);
        $stats = $method->invoke($service, [
            'ordered_collection' => [
                'contract_version' => 'ota_ordered_collection.v1',
                'mode' => 'ordered_yesterday_gap_only',
                'scope' => 'ota_yesterday_core',
                'platform' => 'meituan',
                'system_hotel_id' => 80,
                'data_source_id' => 101,
                'target_date' => '2026-07-24',
                'stage' => 'targeted_gap',
                'reason' => 'target_date_field_gap',
                'sections' => ['traffic'],
                'interface_ids' => ['traffic_cards', 'flow_conversion'],
                'required_field_keys' => ['list_exposure', 'detail_exposure', 'flow_rate'],
                'missing_field_keys' => ['list_exposure'],
            ],
            'run_readback' => [
                'readback_verified' => true,
                'sync_task_id' => 1529,
                'data_source_id' => 101,
                'system_hotel_id' => 80,
                'platform' => 'meituan',
                'target_date' => '2026-07-24',
                'data_period' => 'historical_daily',
                'started_at' => '2026-07-25 08:31:00',
                'row_ids' => [2001],
                'source_trace_ids' => ['trace-2001'],
                'observed_platform_hotel_id' => 'meituan-hotel-80',
                'verified_metric_keys' => ['revenue', 'room_nights', 'adr'],
                'capture_strategy' => 'browser_response',
                'fallback_from' => null,
                'fallback_reason' => null,
                'response_evidence_type' => 'structured_json',
                'recipe_plan_hash' => null,
                'recipe_count' => null,
                'p0_status' => 'ready',
                'field_fact_status' => 'ready',
                'required_traffic_metric_keys' => ['list_exposure', 'detail_exposure', 'flow_rate'],
                'complete_traffic_metric_keys' => ['list_exposure', 'detail_exposure', 'flow_rate'],
                'missing_traffic_metric_keys' => [],
                'nonzero_required_metric_rows' => 1,
                'platform_hotel_identifier_status' => 'ready',
                'page_field_fact_status' => 'ready',
                'readback_count' => 1,
            ],
        ], 'success');

        self::assertSame(['traffic'], $stats['ordered_collection']['sections']);
        self::assertSame('ready', $stats['run_readback']['p0_status']);
        self::assertSame('ready', $stats['run_readback']['field_fact_status']);
        self::assertSame(
            ['list_exposure', 'detail_exposure', 'flow_rate'],
            $stats['run_readback']['complete_traffic_metric_keys']
        );
        self::assertSame(
            'browser_response',
            $stats['run_readback']['capture_strategy']
        );
        self::assertSame(
            'structured_json',
            $stats['run_readback']['response_evidence_type']
        );
        self::assertSame(
            'meituan-hotel-80',
            $stats['run_readback']['observed_platform_hotel_id']
        );
    }

    public function testOtaSyncResultAddsCommonCollectionEnvelopeFromExactRunReadback(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'otaCollectionResult');
        $method->setAccessible(true);
        $result = $method->invoke($service, [
            'id' => 101,
            'tenant_id' => 1,
            'system_hotel_id' => 80,
            'platform' => 'meituan',
            'data_type' => 'traffic',
            'ingestion_method' => 'browser_profile',
            'config' => ['store_id' => 'meituan-hotel-80'],
        ], [
            'task_id' => 1529,
            'data_source_id' => 101,
            'status' => 'success',
            'normalized_count' => 1,
            'saved_count' => 1,
            'run_readback' => [
                'readback_verified' => true,
                'sync_task_id' => 1529,
                'data_source_id' => 101,
                'system_hotel_id' => 80,
                'platform' => 'meituan',
                'target_date' => '2026-07-24',
                'data_period' => 'historical_daily',
                'started_at' => '2026-07-25 08:31:00',
                'row_ids' => [2001],
                'source_trace_ids' => ['trace-2001'],
                'observed_platform_hotel_id' => 'meituan-hotel-80',
                'verified_metric_keys' => ['revenue', 'room_nights', 'adr'],
                'capture_strategy' => 'browser_response',
                'fallback_from' => null,
                'fallback_reason' => null,
                'response_evidence_type' => 'structured_json',
                'recipe_plan_hash' => null,
                'recipe_count' => null,
                'p0_status' => 'ready',
                'field_fact_status' => 'ready',
                'required_traffic_metric_keys' => [
                    'list_exposure',
                    'detail_exposure',
                    'flow_rate',
                ],
                'complete_traffic_metric_keys' => [
                    'list_exposure',
                    'detail_exposure',
                    'flow_rate',
                ],
                'missing_traffic_metric_keys' => [],
                'nonzero_required_metric_rows' => 1,
                'platform_hotel_identifier_status' => 'ready',
                'page_field_fact_status' => 'ready',
                'readback_count' => 1,
                'failure_reason' => '',
            ],
        ]);

        self::assertSame('suxios.collection_result.v1', $result['contract_version']);
        self::assertSame('meituan', $result['scope']['platform']);
        self::assertSame('browser_response', $result['run']['strategy']['selected']);
        self::assertSame('verified', $result['collection_status']);
        self::assertTrue($result['claim']['allowed']);
        self::assertSame([2001], $result['references']['row_ids']);
    }

    public function testOtaRunStrategyRequiresEvidenceFromExactReadbackRows(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod(
            $service,
            'collectionStrategyEvidenceFromRunRows'
        );
        $method->setAccessible(true);
        $source = [
            'platform' => 'meituan',
            'ingestion_method' => 'browser_profile',
        ];
        $traceId = 'meituan:' . str_repeat('a', 64);
        $urlHash = str_repeat('b', 64);
        $base = [
            'system_hotel_id' => 80,
            'hotel_id' => 'meituan-hotel-80',
            'platform' => 'meituan',
            'source' => 'meituan',
            'data_date' => '2026-07-29',
            'data_source_id' => 101,
            'sync_task_id' => 2001,
            'readback_verified' => 1,
            'source_trace_id' => $traceId,
        ];
        $raw = [
            'source_trace_id' => $traceId,
            'source_url_hash' => $urlHash,
            'capture_evidence' => [
                'source_trace_id' => $traceId,
                'source_url_hash' => $urlHash,
            ],
            'row' => [
                '_capture_source' => 'xhr:traffic:business_data',
                '_source_path' => 'data',
                'capture_evidence' => [
                    'capture_source' => 'xhr:traffic:business_data',
                    'source_path' => 'data',
                    'source_trace_id' => $traceId,
                    'source_url_hash' => $urlHash,
                ],
            ],
            'field_facts' => [[
                'metric_key' => 'order_amount',
                'source_path' => 'data.amount',
                'status' => 'captured',
                'stored_value_present' => true,
                'capture_evidence' => [
                    'capture_source' => 'xhr:traffic:business_data',
                    'source_path' => 'data',
                    'source_trace_id' => $traceId,
                    'source_url_hash' => $urlHash,
                ],
            ]],
        ];
        $structuredRaw = $raw;
        $traceOnlyRaw = [
            'source_trace_id' => $traceId,
            'source_url_hash' => $urlHash,
            'capture_evidence' => [
                'source_trace_id' => $traceId,
                'source_url_hash' => $urlHash,
            ],
        ];
        $verified = $method->invoke($service, [[
            ...$base,
            'raw_data' => json_encode($raw, JSON_THROW_ON_ERROR),
        ]], $source);
        $traceOnly = $method->invoke($service, [[
            ...$base,
            'raw_data' => json_encode($traceOnlyRaw, JSON_THROW_ON_ERROR),
        ]], $source);
        $raw['row']['_capture_source'] = 'dom:traffic:home_summary';
        $raw['row']['_source_path'] = 'dom.traffic.home_summary';
        $raw['row']['capture_evidence']['capture_source']
            = 'dom:traffic:home_summary';
        $raw['row']['capture_evidence']['source_path']
            = 'dom.traffic.home_summary';
        $dom = $method->invoke($service, [[
            ...$base,
            'raw_data' => json_encode($raw, JSON_THROW_ON_ERROR),
        ]], $source);

        self::assertSame('browser_response', $verified['capture_strategy']);
        self::assertSame(
            'structured_json',
            $verified['response_evidence_type']
        );
        self::assertSame('not_recorded', $traceOnly['capture_strategy']);
        self::assertNull($traceOnly['response_evidence_type']);
        self::assertSame('dom_fallback', $dom['capture_strategy']);
        self::assertSame('dom_fields', $dom['response_evidence_type']);

        $ctripSource = [
            'platform' => 'ctrip',
            'ingestion_method' => 'browser_profile',
        ];
        $mixedTrafficRun = $method->invoke($service, [
            [
                ...$base,
                'platform' => 'ctrip',
                'source' => 'ctrip',
                'data_type' => 'traffic',
                'dimension' => '',
                'compare_type' => 'self',
                'raw_data' => json_encode($structuredRaw, JSON_THROW_ON_ERROR),
            ],
            [
                ...$base,
                'platform' => 'ctrip',
                'source' => 'ctrip',
                'data_type' => 'business',
                'dimension' => 'catalog:traffic_report:traffic_order_overview:order_count',
                'compare_type' => 'self',
                'raw_data' => json_encode($traceOnlyRaw, JSON_THROW_ON_ERROR),
            ],
        ], $ctripSource);
        self::assertSame('browser_response', $mixedTrafficRun['capture_strategy']);
        self::assertSame('structured_json', $mixedTrafficRun['response_evidence_type']);
    }

    public function testObservedRunHotelIgnoresCtripCompetitorSentinelButNotConflicts(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod(
            $service,
            'observedPlatformHotelIdFromRunRows'
        );
        $method->setAccessible(true);
        $self = [
            'hotel_id' => '130079194',
            'compare_type' => 'self',
            'raw_data' => json_encode([
                'platform_hotel_identifier_proof' => 'row_field_present',
            ], JSON_THROW_ON_ERROR),
        ];
        $competitorAverage = [
            'hotel_id' => '-1',
            'compare_type' => 'competitor_avg',
            'raw_data' => json_encode([
                'platform_hotel_identifier_proof' => 'row_field_present',
            ], JSON_THROW_ON_ERROR),
        ];

        self::assertSame(
            '130079194',
            $method->invoke($service, [$self, $competitorAverage])
        );
        self::assertSame('', $method->invoke($service, [
            $self,
            [
                'hotel_id' => '130079195',
                'compare_type' => 'self',
                'raw_data' => json_encode([
                    'platform_hotel_identifier_proof'
                        => 'row_field_present',
                ], JSON_THROW_ON_ERROR),
            ],
        ]));
    }

    public function testBrowserProfileBackgroundSyncRejectsHistoricalVerifiedManualLoginWithoutReusableProof(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'assertBrowserProfileBackgroundSyncLoginVerified');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('profile_session_unverified');

        $method->invoke($service, [
            'id' => 82,
            'platform' => 'meituan',
            'data_type' => 'traffic',
            'ingestion_method' => 'browser_profile',
            'system_hotel_id' => 58,
            'config' => [
                'store_id' => 'store_001',
                'manual_login_state_verified' => true,
                'profile_status' => 'logged_in',
                'last_login_verified_at' => '2026-07-04 10:00:00',
            ],
        ], [
            'trigger_type' => 'daily_profile_reuse',
            'interactive_browser' => false,
        ]);

    }

    public function testBrowserProfileSyncDiagnosticsExposeCurrentSessionGate(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'buildSyncDiagnostics');
        $method->setAccessible(true);

        $diagnostics = $method->invoke($service, [], 0, [
            'id' => 83,
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'ingestion_method' => 'browser_profile',
            'system_hotel_id' => 58,
            'config' => [
                'profile_id' => 'hotel_001',
                'profile_daily_reuse_enabled' => true,
            ],
        ], [
            'trigger_type' => 'daily_profile_reuse',
            'data_date' => '2026-06-29',
            'interactive_browser' => false,
        ], [], 'failed', 'blocked');

        self::assertSame('blocked', $diagnostics['p0_status']);
        self::assertSame('current_session_not_verified', $diagnostics['operator_message']);
        self::assertSame([
            'target_date_traffic_rows',
            'required_traffic_metric_keys',
            'target_date_required_traffic_metrics_zero_unverified',
            'platform_hotel_identifier',
            'page_field_fact_status',
            'current_session_verified',
        ], $diagnostics['missing_inputs']);
    }

    public function testSyncDiagnosticsKeepMatchedPayloadIdentityWhenTrafficRowsAreMissing(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'buildSyncDiagnostics');
        $method->setAccessible(true);

        $diagnostics = $method->invoke($service, [], 0, [
            'id' => 101,
            'platform' => 'meituan',
            'data_type' => 'business',
            'ingestion_method' => 'browser_profile',
            'system_hotel_id' => 80,
            'config' => [
                'store_id' => '68471',
            ],
        ], [
            'data_date' => '2026-08-23',
            'capture_sections' => 'orders,traffic',
        ], [
            'platform_identity_validation' => [
                'status' => 'matched',
                'source_validation' => true,
                'validated_identifier' => '68471',
                'sensitive_values_exposed' => false,
            ],
        ], 'partial_success', 'target traffic missing');

        self::assertSame('ready', $diagnostics['platform_hotel_identifier_status']);
        self::assertNotContains('platform_hotel_identifier', $diagnostics['missing_inputs']);
        self::assertContains('target_date_traffic_rows', $diagnostics['missing_inputs']);
        self::assertSame('blocked', $diagnostics['p0_status']);
    }

    public function testSyncDiagnosticsDoNotRetainAdapterErrorText(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'buildSyncDiagnostics');
        $method->setAccessible(true);

        $diagnostics = $method->invoke($service, [], 0, [
            'id' => 84,
            'platform' => 'meituan',
            'data_type' => 'traffic',
            'ingestion_method' => 'manual',
            'system_hotel_id' => 58,
        ], [
            'data_date' => '2026-07-09',
        ], [], 'failed', 'Authorization: Bearer test-only-secret');

        self::assertSame('collection_failed', $diagnostics['operator_message']);
        self::assertArrayNotHasKey('adapter_message', $diagnostics);
        self::assertStringNotContainsString('test-only-secret', (string)json_encode($diagnostics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function testSyncDiagnosticsPersistTaskCapabilityStatesFromSavedTargetDateRows(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'buildSyncDiagnostics');
        $method->setAccessible(true);

        $diagnostics = $method->invoke($service, [
            ['data_date' => '2026-07-09', 'data_type' => 'business'],
            ['data_date' => '2026-07-09', 'data_type' => 'order'],
        ], 2, [
            'id' => 85,
            'platform' => 'meituan',
            'data_type' => 'business',
            'ingestion_method' => 'browser_profile',
            'system_hotel_id' => 58,
            'config' => [
                'store_id' => 'store_001',
                'profile_binding_key' => 'store_001',
                'manual_login_state_verified' => true,
                'profile_status' => 'logged_in',
                'last_login_verified_at' => '2026-07-10 08:20:00',
            ],
        ], [
            'trigger_type' => 'daily_profile_reuse',
            'data_date' => '2026-07-09',
            'interactive_browser' => false,
        ], [], 'success', 'Platform data synchronized.');

        self::assertSame([
            'business' => 'verified',
            'orders' => 'verified',
            'reviews' => 'unverified',
        ], $diagnostics['capability_states']);
        self::assertStringNotContainsString('store_001', (string)json_encode($diagnostics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function testSyncDiagnosticsSanitizerKeepsOnlyKnownCapabilityStates(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'sanitizeSyncDiagnosticsForResponse');
        $method->setAccessible(true);

        $diagnostics = $method->invoke($service, [
            'target_date' => '2026-07-09',
            'field_fact_status' => 'ready',
            'p0_status' => 'ready',
            'adapter_status' => 'success',
            'capability_states' => [
                'business' => 'verified',
                'orders' => 'permission_denied',
                'reviews' => 'collection_failed',
                'raw_response' => 'test-only-secret',
            ],
        ], 'success');

        self::assertSame([
            'business' => 'verified',
            'orders' => 'permission_denied',
            'reviews' => 'collection_failed',
        ], $diagnostics['capability_states']);
        self::assertStringNotContainsString('test-only-secret', (string)json_encode($diagnostics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $unknown = $method->invoke($service, [
            'capability_states' => [
                'business' => 'capability_unavailable',
                'orders' => 'unexpected-value',
                'raw_response' => 'test-only-secret',
            ],
        ], 'success');
        self::assertSame([
            'business' => 'capability_unavailable',
            'orders' => 'unverified',
            'reviews' => 'unverified',
        ], $unknown['capability_states']);
    }

    public function testSyncDiagnosticsMarkAllCapabilitiesDeniedWhenAdapterPermissionIsDenied(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'buildSyncDiagnostics');
        $method->setAccessible(true);

        $diagnostics = $method->invoke($service, [], 0, [
            'id' => 86,
            'platform' => 'ctrip',
            'data_type' => 'business',
            'ingestion_method' => 'browser_profile',
            'system_hotel_id' => 58,
            'config' => [
                'profile_id' => 'profile_001',
                'ota_hotel_id' => 'ctrip_001',
                'manual_login_state_verified' => true,
                'profile_status' => 'logged_in',
                'last_login_verified_at' => '2026-07-10 08:20:00',
            ],
        ], [
            'trigger_type' => 'daily_profile_reuse',
            'data_date' => '2026-07-09',
            'interactive_browser' => false,
        ], [], 'permission_denied', 'permission_denied');

        self::assertSame([
            'business' => 'permission_denied',
            'orders' => 'permission_denied',
            'reviews' => 'permission_denied',
        ], $diagnostics['capability_states']);
    }

    public function testManualPayloadNormalizesRowsForOnlineDailyDataWithTraceability(): void
    {
        $service = new PlatformDataSyncService();

        $rows = $service->normalizeRowsFromPayload([
            'review_detail_collection' => true,
            'rows' => [
                [
                    'hotel_id' => 'ctrip-1001',
                    'hotel_name' => 'Demo Hotel',
                    'data_date' => '2026/05/27',
                    'amount' => '1288.50',
                    'room_nights' => '6',
                    'orders' => '4',
                    'rating' => '4.7',
                    'list_exposure' => '1000',
                    'detail_exposure' => '250',
                    'flow_rate' => '25%',
                ],
            ],
        ], [
            'id' => 12,
            'name' => '携程手工导入',
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'system_hotel_id' => 7,
            'tenant_id' => 1,
            'ingestion_method' => 'manual',
        ], 34);

        self::assertCount(1, $rows);
        self::assertSame('ctrip-1001', $rows[0]['hotel_id']);
        self::assertSame('2026-05-27', $rows[0]['data_date']);
        self::assertSame(1288.5, $rows[0]['amount']);
        self::assertSame(6, $rows[0]['quantity']);
        self::assertSame(4, $rows[0]['book_order_num']);
        self::assertSame(25.0, $rows[0]['flow_rate']);
        self::assertSame('ctrip', $rows[0]['source']);
        self::assertSame('traffic', $rows[0]['data_type']);
        self::assertSame(7, $rows[0]['system_hotel_id']);
        self::assertSame(12, $rows[0]['data_source_id']);
        self::assertSame(34, $rows[0]['sync_task_id']);
        self::assertSame('manual', $rows[0]['ingestion_method']);
        self::assertStringContainsString('"data_source_name":"携程手工导入"', $rows[0]['raw_data']);
    }

    public function testCtripAverageIdentityCannotPersistAsOrdinaryCompetitor(): void
    {
        $rows = (new PlatformDataSyncService())->normalizeRowsFromPayload([
            'rows' => [[
                'hotel_id' => '-1',
                'hotel_name' => '竞争圈平均',
                'data_date' => '2026-07-12',
                'data_type' => 'traffic',
                'compare_type' => 'competitor',
                'list_exposure' => 799,
            ]],
        ], [
            'id' => 25,
            'name' => 'Ctrip Profile Traffic',
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'system_hotel_id' => 80,
            'tenant_id' => 1,
            'ingestion_method' => 'browser_profile',
        ], 318);

        self::assertCount(1, $rows);
        self::assertSame('-1', $rows[0]['hotel_id']);
        self::assertSame('competitor_avg', $rows[0]['compare_type']);
    }

    public function testManualPayloadRejectsMissingBusinessRows(): void
    {
        $service = new PlatformDataSyncService();

        self::assertSame([], $service->normalizeRowsFromPayload(['rows' => []], [
            'id' => 12,
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'system_hotel_id' => 7,
        ], 34));
    }

    public function testGenericOtaBindingMismatchFailsClosed(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'assertGenericOtaPayloadBinding');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('binding_mismatch');
        $method->invoke($service, [
            'platform' => 'ctrip',
            'ingestion_method' => 'api',
            'config' => ['external_hotel_id' => 'ctrip-1001'],
        ], [
            'rows' => [[
                'hotel_id' => 'ctrip-2002',
                'data_date' => '2026-07-22',
                'data_type' => 'business',
            ]],
        ]);
    }

    public function testGenericOtaBindingMissingFailsClosed(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'assertGenericOtaPayloadBinding');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('binding_missing');
        $method->invoke($service, [
            'platform' => 'meituan',
            'ingestion_method' => 'manual',
            'config' => [],
        ], [
            'rows' => [[
                'poi_id' => 'mt-1001',
                'data_date' => '2026-07-22',
            ]],
        ]);
    }

    public function testResponseLevelMatchedBindingAllowsCompetitorRows(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'assertGenericOtaPayloadBinding');
        $method->setAccessible(true);

        $evidence = $method->invoke($service, [
            'platform' => 'ctrip',
            'ingestion_method' => 'api',
            'config' => ['external_hotel_id' => 'ctrip-1001'],
        ], [
            'platform_identity_validation' => [
                'status' => 'matched',
                'source_validation' => true,
                'validated_identifier' => 'ctrip-1001',
            ],
            'rows' => [[
                'hotel_id' => 'ctrip-competitor-9',
                'compare_type' => 'competitor',
                'data_date' => '2026-07-22',
            ]],
        ]);

        self::assertSame(['status' => 'matched', 'proof' => 'platform_identity_validation'], $evidence);
    }

    public function testGenericOtaMissingMetricsStayNullAndExplicitZeroRemainsObserved(): void
    {
        $service = new PlatformDataSyncService();
        $source = [
            'id' => 12,
            'name' => 'Meituan manual fixture',
            'platform' => 'meituan',
            'data_type' => 'traffic',
            'system_hotel_id' => 7,
            'tenant_id' => 1,
            'ingestion_method' => 'manual',
            'config' => ['poi_id' => 'mt-1001'],
        ];
        $rows = $service->normalizeRowsFromPayload([
            '_ota_binding_evidence' => ['status' => 'matched', 'proof' => 'test_fixture'],
            'rows' => [
                ['poi_id' => 'mt-1001', 'data_date' => '2026-07-22', 'dimension' => 'missing'],
                [
                    'poi_id' => 'mt-1001',
                    'data_date' => '2026-07-22',
                    'dimension' => 'observed-zero',
                    'list_exposure' => 0,
                    'detail_exposure' => 0,
                    'flow_rate' => 0,
                ],
            ],
        ], $source, 34);

        self::assertCount(2, $rows);
        self::assertNull($rows[0]['list_exposure']);
        self::assertNull($rows[0]['detail_exposure']);
        self::assertNull($rows[0]['flow_rate']);
        self::assertSame('unverified', $rows[0]['validation_status']);
        self::assertContains('field_missing:list_exposure', json_decode($rows[0]['validation_flags'], true));
        self::assertSame(0, $rows[1]['list_exposure']);
        self::assertSame(0, $rows[1]['detail_exposure']);
        self::assertSame(0.0, $rows[1]['flow_rate']);
        $facts = array_column(json_decode($rows[1]['raw_data'], true)['field_facts'] ?? [], null, 'metric_key');
        self::assertSame('captured', $facts['list_exposure']['status'] ?? '');
        self::assertTrue($facts['list_exposure']['stored_value_present'] ?? false);
    }

    public function testOperatorConfirmedBrowserAssistBindingIsNotMarkedGenericUnverified(): void
    {
        $service = new PlatformDataSyncService();
        $rows = $service->normalizeRowsFromPayload([
            '_ota_binding_evidence' => [
                'status' => 'operator_confirmed',
                'proof' => 'authenticated_page_header',
            ],
            'rows' => [[
                'data_date' => '2026-07-29',
                'data_type' => 'business',
                'amount' => 2026.78,
                'quantity' => 2,
                'book_order_num' => 1,
                'dimension' => 'intraday:business',
                'compare_type' => 'self',
                'is_self' => true,
            ]],
        ], [
            'id' => 68,
            'name' => 'Meituan browser assist fixture',
            'platform' => 'meituan',
            'data_type' => 'business',
            'system_hotel_id' => 80,
            'tenant_id' => 1,
            'ingestion_method' => 'browser_assist_dom',
        ], 340);

        self::assertCount(1, $rows);
        self::assertSame('normal', $rows[0]['validation_status']);
        self::assertNotContains(
            'source_ingestion_method_unverified',
            json_decode($rows[0]['validation_flags'], true)
        );
        self::assertNotContains(
            'hotel_binding_unverified',
            json_decode($rows[0]['validation_flags'], true)
        );
    }

    public function testMeituanRequestedPeriodMismatchAndContradictoryZeroRowsAreQuarantined(): void
    {
        $rows = (new PlatformDataSyncService())->normalizeRowsFromPayload([
            'data_date' => '2026-08-23',
            'data_period' => 'historical_daily',
            'rows' => [
                [
                    'poi_id' => '68471',
                    'data_date' => '2026-08-23',
                    'data_period' => 'historical_daily',
                    'data_type' => 'business',
                    'compare_type' => 'self',
                    'amount' => 0,
                    'quantity' => 0,
                    'book_order_num' => 0,
                ],
                [
                    'poi_id' => '68471',
                    'data_date' => '2026-08-23',
                    'data_period' => 'realtime_snapshot',
                    'data_type' => 'traffic',
                    'compare_type' => 'self',
                    'list_exposure' => 15,
                    'detail_exposure' => 2,
                    'flow_rate' => 13.33,
                ],
                [
                    'poi_id' => '68471',
                    'data_date' => '2026-08-23',
                    'data_period' => 'historical_daily',
                    'data_type' => 'traffic',
                    'compare_type' => '',
                    'list_exposure' => 0,
                    'detail_exposure' => 0,
                    'flow_rate' => 0,
                ],
                [
                    'poi_id' => '68471',
                    'data_date' => '2026-08-23',
                    'data_period' => 'historical_daily',
                    'data_type' => 'order',
                    'compare_type' => 'self',
                    'amount' => 7025.14,
                    'room_nights' => 12,
                    'orders' => 8,
                ],
            ],
        ], $this->meituanBrowserProfileSource(), 4353);

        self::assertCount(4, $rows);
        $byTypeAndPeriod = [];
        foreach ($rows as $row) {
            $byTypeAndPeriod[$row['data_type'] . ':' . $row['data_period']] = $row;
        }

        $business = $byTypeAndPeriod['business:historical_daily'];
        self::assertSame('quarantined', $business['validation_status']);
        self::assertContains(
            'same_run_zero_business_conflicts_with_nonzero_orders',
            json_decode($business['validation_flags'], true, 512, JSON_THROW_ON_ERROR)
        );

        $realtimeTraffic = $byTypeAndPeriod['traffic:realtime_snapshot'];
        self::assertSame('quarantined', $realtimeTraffic['validation_status']);
        self::assertContains(
            'requested_data_period_mismatch',
            json_decode($realtimeTraffic['validation_flags'], true, 512, JSON_THROW_ON_ERROR)
        );

        $historicalTraffic = $byTypeAndPeriod['traffic:historical_daily'];
        self::assertSame('quarantined', $historicalTraffic['validation_status']);
        self::assertContains(
            'same_run_zero_traffic_conflicts_with_nonzero_orders',
            json_decode($historicalTraffic['validation_flags'], true, 512, JSON_THROW_ON_ERROR)
        );

        $order = $byTypeAndPeriod['order:historical_daily'];
        self::assertNotSame('quarantined', $order['validation_status']);
    }

    public function testOrderPersistenceIdentityIsDistinctPerOrderAndStableAcrossRetries(): void
    {
        $service = new PlatformDataSyncService();
        $source = $this->meituanBrowserProfileSource();
        $payload = ['rows' => [
            [
                'poi_id' => '68471',
                'data_date' => '2026-07-22',
                'data_type' => 'order',
                'orderId' => 'ORDER-SECRET-A',
                'amount' => 100,
            ],
            [
                'poi_id' => '68471',
                'data_date' => '2026-07-22',
                'data_type' => 'order',
                'orderId' => 'ORDER-SECRET-B',
                'amount' => 200,
            ],
        ]];

        $first = $service->normalizeRowsFromPayload($payload, $source, 1001);
        $retry = $service->normalizeRowsFromPayload($payload, $source, 1002);

        self::assertCount(2, $first);
        self::assertNotSame($first[0]['persistence_identity_hash'], $first[1]['persistence_identity_hash']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first[0]['persistence_identity_hash']);
        self::assertSame(
            array_column($first, 'persistence_identity_hash'),
            array_column($retry, 'persistence_identity_hash')
        );
        self::assertNotSame($first[0]['source_trace_id'], $retry[0]['source_trace_id']);
        self::assertStringNotContainsString('ORDER-SECRET-A', $first[0]['raw_data']);
        self::assertStringNotContainsString('ORDER-SECRET-A', $first[0]['persistence_identity_hash']);
    }

    public function testPersistenceIdentityMigrationIsAdditiveAndDoesNotTouchUsersOrHotels(): void
    {
        $migration = (string)file_get_contents(dirname(__DIR__) . '/database/migrations/20260723_add_online_daily_data_persistence_identity.sql');
        self::assertStringContainsString('persistence_identity_hash', $migration);
        self::assertStringContainsString('UNIQUE INDEX', strtoupper($migration));
        self::assertDoesNotMatchRegularExpression('/\b(?:DELETE|TRUNCATE|DROP\s+TABLE)\b/i', $migration);
        self::assertDoesNotMatchRegularExpression('/`(?:users|hotels|platform_data_sources|ota_profile_bindings)`/i', $migration);
    }

    public function testCtripCatalogFieldFactsDoNotPromoteMissingZeroPlaceholders(): void
    {
        $service = new PlatformDataSyncService();
        $source = [
            'id' => 91,
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'system_hotel_id' => 80,
            'ingestion_method' => 'browser_profile',
        ];
        $baseRow = [
            'hotel_id' => '6866634',
            'data_date' => '2026-07-21',
            'data_type' => 'traffic',
            'list_exposure' => 120,
            'order_submit_num' => 0,
            'source_trace_id' => 'ctrip:' . str_repeat('a', 64),
            'capture_evidence' => [
                'source_trace_id' => 'ctrip:' . str_repeat('a', 64),
                'source_url_hash' => str_repeat('b', 64),
            ],
        ];
        $fieldFact = [
            'metric_key' => 'order_submit_user',
            'source_path' => 'data.0.orderSubmitNum',
            'storage_field' => 'online_daily_data.order_submit_num',
            'status' => 'missing',
            'missing_state' => 'field_missing',
            'stored_value_present' => false,
        ];

        $missingRow = $baseRow;
        $missingRow['raw_data'] = [
            'source' => 'ctrip_catalog_facts',
            'field_facts' => [$fieldFact],
        ];
        $missing = $service->normalizeRowsFromPayload(['rows' => [$missingRow]], $source, 101);
        self::assertCount(1, $missing);
        self::assertNull($missing[0]['order_submit_num']);
        $missingRaw = json_decode((string)$missing[0]['raw_data'], true);
        $missingFacts = array_column($missingRaw['field_facts'] ?? [], null, 'metric_key');
        self::assertSame('missing', $missingFacts['order_submit_num']['status'] ?? '');

        $capturedRow = $baseRow;
        $capturedRow['raw_data'] = [
            'source' => 'ctrip_catalog_facts',
            'field_facts' => [array_replace($fieldFact, [
                'status' => 'captured',
                'missing_state' => '',
                'stored_value_present' => true,
            ])],
        ];
        $captured = $service->normalizeRowsFromPayload(['rows' => [$capturedRow]], $source, 102);
        self::assertCount(1, $captured);
        self::assertSame(0, $captured[0]['order_submit_num']);
        $capturedRaw = json_decode((string)$captured[0]['raw_data'], true);
        $capturedFacts = array_column($capturedRaw['field_facts'] ?? [], null, 'metric_key');
        self::assertSame('captured', $capturedFacts['order_submit_num']['status'] ?? '');
    }

    public function testCollectionResourceDefinitionsExposeUnifiedContract(): void
    {
        $service = new PlatformDataSyncService();

        $resources = array_column($service->collectionResourceDefinitions(), null, 'resource');

        foreach (['businessData', 'peerRank', 'flowData', 'trafficForecast', 'flowAnalysis', 'searchKeywords', 'orderData', 'orderFlowData', 'reviewData', 'advertisingData', 'roomTypes', 'platformIdentity'] as $resource) {
            self::assertArrayHasKey($resource, $resources);
            self::assertNotEmpty($resources[$resource]['fields']);
            self::assertNotEmpty($resources[$resource]['aliases']);
        }

        self::assertSame('business', $resources['businessData']['data_type']);
        self::assertSame('peer_rank', $resources['peerRank']['data_type']);
        self::assertSame('traffic', $resources['flowData']['data_type']);
        self::assertSame('traffic_forecast', $resources['trafficForecast']['data_type']);
        self::assertSame('traffic_analysis', $resources['flowAnalysis']['data_type']);
        self::assertSame('search_keyword', $resources['searchKeywords']['data_type']);
        self::assertSame('order', $resources['orderData']['data_type']);
        self::assertSame('order_flow', $resources['orderFlowData']['data_type']);
        self::assertSame('review', $resources['reviewData']['data_type']);
        self::assertSame('advertising', $resources['advertisingData']['data_type']);
        self::assertSame('room_type', $resources['roomTypes']['data_type']);
        self::assertSame('platform_identity', $resources['platformIdentity']['data_type']);
        self::assertFalse($resources['trafficForecast']['default_enabled']);
        self::assertFalse($resources['flowAnalysis']['default_enabled']);
        self::assertFalse($resources['orderData']['default_enabled']);
        self::assertFalse($resources['orderFlowData']['default_enabled']);
        self::assertFalse($resources['reviewData']['default_enabled']);
        self::assertFalse($resources['advertisingData']['default_enabled']);
        self::assertFalse($resources['platformIdentity']['default_enabled']);
        self::assertTrue($resources['reviewData']['requires_explicit_authorization']);
        self::assertTrue($resources['orderData']['requires_explicit_authorization']);
        self::assertTrue($resources['platformIdentity']['requires_explicit_authorization']);
        self::assertSame('room_type_catalog_only_no_room_status_or_mapping', $resources['roomTypes']['privacy_boundary']);
        self::assertSame('aggregate_campaign_metrics_only', $resources['advertisingData']['privacy_boundary']);
        self::assertSame('aggregate_demand_flow_only_no_order_pii', $resources['orderFlowData']['privacy_boundary']);
        self::assertSame('platform_identifier_only_no_cookie_no_token', $resources['platformIdentity']['privacy_boundary']);
    }

    public function testEffectiveSyncTaskStatusMarksOldRunningTasksStale(): void
    {
        $freshTask = [
            'status' => 'running',
            'update_time' => date('Y-m-d H:i:s', time() - 120),
        ];
        $oldTask = [
            'status' => 'running',
            'update_time' => date('Y-m-d H:i:s', time() - 7200),
        ];
        $oldSyncingTask = [
            'status' => 'syncing',
            'update_time' => date('Y-m-d H:i:s', time() - 7200),
        ];

        self::assertFalse(PlatformDataSyncService::isStaleRunningSyncTask($freshTask));
        self::assertSame('running', PlatformDataSyncService::effectiveSyncTaskStatus($freshTask));
        self::assertFalse(PlatformDataSyncService::isStaleRunningSyncTask([
            'status' => 'running',
            'update_time' => date('Y-m-d H:i:s', time() - 1199),
        ]));
        self::assertTrue(PlatformDataSyncService::isStaleRunningSyncTask([
            'status' => 'running',
            'update_time' => date('Y-m-d H:i:s', time() - 1201),
        ]));
        self::assertTrue(PlatformDataSyncService::isStaleRunningSyncTask($oldTask));
        self::assertSame('stale_running', PlatformDataSyncService::effectiveSyncTaskStatus($oldTask));
        self::assertTrue(PlatformDataSyncService::isStaleRunningSyncTask($oldSyncingTask));
        self::assertSame('stale_running', PlatformDataSyncService::effectiveSyncTaskStatus($oldSyncingTask));
        self::assertGreaterThanOrEqual(7200, PlatformDataSyncService::syncTaskAgeSeconds($oldTask));
    }

    public function testCatalogStatusKeepsStaleRunningTaskExplicit(): void
    {
        $service = new PlatformDataSyncService();

        $collection = new \ReflectionMethod($service, 'catalogCollectionStatus');
        $collection->setAccessible(true);
        self::assertSame(
            'stale_running',
            $collection->invoke($service, 'bound', 'task_stale_running', 'stale_running', 'missing', false)
        );

        $etl = new \ReflectionMethod($service, 'catalogEtlStatus');
        $etl->setAccessible(true);
        self::assertSame('stale_running', $etl->invoke($service, [
            'status' => 'running',
            'update_time' => date('Y-m-d H:i:s', time() - 7200),
        ], null, 0, 0));

        $reason = new \ReflectionMethod($service, 'catalogMissingReason');
        $reason->setAccessible(true);
        self::assertSame(
            'stale_running_task',
            $reason->invoke($service, 'bound', 'task_stale_running', 'stale_running', 'stale_running', 'missing', '')
        );
    }

    public function testUnifiedResourceAliasesNormalizeIntoCanonicalDataTypes(): void
    {
        $service = new PlatformDataSyncService();
        $cases = [
            'businessData' => 'business',
            'peerRank' => 'peer_rank',
            'flowData' => 'traffic',
            'trafficForecast' => 'traffic_forecast',
            'flowAnalysis' => 'traffic_analysis',
            'searchKeywords' => 'search_keyword',
            'roomTypes' => 'room_type',
            'reviewData' => 'review',
            'platformIdentity' => 'platform_identity',
        ];

        foreach ($cases as $alias => $expected) {
            $source = [
                'id' => 90,
                'name' => 'Meituan unified resource source',
                'platform' => 'meituan',
                'data_type' => $alias,
                'system_hotel_id' => 7,
                'tenant_id' => 1,
                'ingestion_method' => 'manual',
            ];
            if ($expected === 'review') {
                $source['config'] = ['allow_review' => true];
            }

            $rows = $service->normalizeRowsFromPayload([
                'rows' => [
                    [
                        'hotel_id' => 'mt-001',
                        'hotel_name' => 'Meituan Demo Hotel',
                        'data_date' => '2026-06-06',
                        'amount' => '100',
                        'room_nights' => '2',
                        'orders' => '1',
                        'rank' => '3',
                        'keyword' => 'nearby hotel',
                        'score' => '4.5',
                    ],
                ],
            ], $source, 91);

            self::assertCount(1, $rows, $alias);
            self::assertSame($expected, $rows[0]['data_type'], $alias);
        }
    }

    public function testRealtimePayloadNormalizesWithSnapshotMetadata(): void
    {
        $service = new PlatformDataSyncService();

        $rows = $service->normalizeRowsFromPayload([
            'data_period' => 'realtime_snapshot',
            'snapshot_time' => '2026-06-06 13:15:00',
            'rows' => [
                [
                    'hotel_id' => 'ctrip-1001',
                    'hotel_name' => 'Demo Hotel',
                    'data_date' => '2026-06-06',
                    'data_type' => 'traffic',
                    'list_exposure' => 100,
                    'detail_exposure' => 20,
                ],
            ],
        ], [
            'id' => 12,
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'system_hotel_id' => 7,
            'tenant_id' => 1,
            'ingestion_method' => 'browser_profile',
        ], 35);

        self::assertCount(1, $rows);
        self::assertSame('realtime_snapshot', $rows[0]['data_period']);
        self::assertSame('2026-06-06 13:15:00', $rows[0]['snapshot_time']);
        self::assertSame('202606061315', $rows[0]['snapshot_bucket']);
        self::assertSame(0, $rows[0]['is_final']);
        self::assertStringContainsString('"data_period":"realtime_snapshot"', $rows[0]['raw_data']);
        self::assertStringContainsString('"snapshot_bucket":"202606061315"', $rows[0]['raw_data']);
    }

    public function testHistoricalPayloadNormalizesAsFinalDailyData(): void
    {
        $service = new PlatformDataSyncService();

        $rows = $service->normalizeRowsFromPayload([
            'rows' => [
                [
                    'hotel_id' => 'ctrip-1001',
                    'hotel_name' => 'Demo Hotel',
                    'data_date' => '2026-06-05',
                    'amount' => 1288,
                    'room_nights' => 6,
                ],
            ],
        ], [
            'id' => 12,
            'platform' => 'ctrip',
            'data_type' => 'business',
            'system_hotel_id' => 7,
            'tenant_id' => 1,
            'ingestion_method' => 'browser_profile',
        ], 36);

        self::assertCount(1, $rows);
        self::assertSame('historical_daily', $rows[0]['data_period']);
        self::assertNull($rows[0]['snapshot_time']);
        self::assertSame('', $rows[0]['snapshot_bucket']);
        self::assertSame(1, $rows[0]['is_final']);
        self::assertStringContainsString('"data_period":"historical_daily"', $rows[0]['raw_data']);
    }

    public function testHistoricalPayloadPreservesSuppliedCaptureTimeWithoutRealtimeBucket(): void
    {
        $service = new PlatformDataSyncService();

        $rows = $service->normalizeRowsFromPayload([
            'captured_at' => '2026-06-06 13:15:00',
            'rows' => [[
                'hotel_id' => 'ctrip-1001',
                'hotel_name' => 'Demo Hotel',
                'data_date' => '2026-06-05',
                'data_type' => 'traffic',
                'list_exposure' => 100,
            ]],
        ], [
            'id' => 12,
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'system_hotel_id' => 7,
            'tenant_id' => 1,
            'ingestion_method' => 'browser_profile',
        ], 36);

        self::assertCount(1, $rows);
        self::assertSame('historical_daily', $rows[0]['data_period']);
        self::assertSame('2026-06-06 13:15:00', $rows[0]['snapshot_time']);
        self::assertSame('', $rows[0]['snapshot_bucket']);
        self::assertSame(1, $rows[0]['is_final']);
    }

    public function testCaptureTimeRejectsInvalidCalendarAndRelativeValues(): void
    {
        $service = new PlatformDataSyncService();
        foreach (['2026-02-30 12:00:00', '2025-02-29 12:00:00', 'now', 'tomorrow'] as $capturedAt) {
            $rows = $service->normalizeRowsFromPayload([
                'captured_at' => $capturedAt,
                'rows' => [[
                    'hotel_id' => 'ctrip-1001',
                    'data_date' => '2026-02-28',
                    'data_type' => 'traffic',
                    'list_exposure' => 1,
                ]],
            ], [
                'id' => 12,
                'platform' => 'ctrip',
                'data_type' => 'traffic',
                'system_hotel_id' => 7,
                'tenant_id' => 1,
                'ingestion_method' => 'browser_profile',
            ], 36);

            self::assertCount(1, $rows, $capturedAt);
            self::assertNull($rows[0]['snapshot_time'], $capturedAt);
            self::assertSame('', $rows[0]['snapshot_bucket'], $capturedAt);
            $raw = json_decode((string)$rows[0]['raw_data'], true, 512, JSON_THROW_ON_ERROR);
            self::assertArrayNotHasKey('captured_at', $raw, $capturedAt);
        }
    }

    public function testCaptureTimeAcceptsTimezoneAndMicroseconds(): void
    {
        $service = new PlatformDataSyncService();
        $rows = $service->normalizeRowsFromPayload([
            'captured_at' => '2026-06-06T05:15:00.123456Z',
            'rows' => [[
                'hotel_id' => 'ctrip-1001',
                'data_date' => '2026-06-05',
                'data_type' => 'traffic',
                'list_exposure' => 1,
            ]],
        ], [
            'id' => 12,
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'system_hotel_id' => 7,
            'tenant_id' => 1,
            'ingestion_method' => 'browser_profile',
        ], 36);

        self::assertSame('2026-06-06 13:15:00', $rows[0]['snapshot_time']);
        self::assertSame('', $rows[0]['snapshot_bucket']);
        $raw = json_decode((string)$rows[0]['raw_data'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($rows[0]['snapshot_time'], $raw['captured_at'] ?? null);
    }

    public function testInvalidRealtimeSyncOptionDoesNotFallBackToPersistenceClock(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'applySyncOptionPeriodMetadata');
        $method->setAccessible(true);
        $payload = $method->invoke($service, [
            'rows' => [[
                'hotel_id' => 'ctrip-1001',
                'data_date' => date('Y-m-d'),
                'data_type' => 'traffic',
                'list_exposure' => 1,
            ]],
        ], [
            'data_period' => 'realtime_snapshot',
            'snapshot_time' => 'now',
        ]);
        self::assertSame('now', $payload['snapshot_time']);

        $rows = $service->normalizeRowsFromPayload($payload, [
            'id' => 12,
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'system_hotel_id' => 7,
            'tenant_id' => 1,
            'ingestion_method' => 'browser_profile',
        ], 36);

        self::assertSame('realtime_snapshot', $rows[0]['data_period']);
        self::assertNull($rows[0]['snapshot_time']);
        self::assertSame('', $rows[0]['snapshot_bucket']);
        $raw = json_decode((string)$rows[0]['raw_data'], true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('captured_at', $raw);
    }

    public function testTrafficForecastPayloadPreservesFutureForecastPeriod(): void
    {
        $service = new PlatformDataSyncService();

        $rows = $service->normalizeRowsFromPayload([
            'rows' => [
                [
                    'hotel_id' => 'meituan-1001',
                    'hotel_name' => 'Demo Hotel',
                    'data_date' => '2026-07-25',
                    'data_type' => 'traffic_forecast',
                    'data_period' => 'next_30_days',
                    'data_value' => 88,
                ],
            ],
        ], [
            'id' => 18,
            'platform' => 'meituan',
            'data_type' => 'traffic_forecast',
            'system_hotel_id' => 7,
            'tenant_id' => 1,
            'ingestion_method' => 'browser_profile',
        ], 37);

        self::assertCount(1, $rows);
        self::assertSame('next_30_days', $rows[0]['data_period']);
        self::assertNull($rows[0]['snapshot_time']);
        self::assertSame('', $rows[0]['snapshot_bucket']);
        self::assertSame(0, $rows[0]['is_final']);
        self::assertStringContainsString('"data_period":"next_30_days"', $rows[0]['raw_data']);
    }

    public function testReviewAndCommentPayloadsNormalizeAggregateFieldsByDefault(): void
    {
        $service = new PlatformDataSyncService();

        foreach (['review', 'reviews', 'comment', 'comments'] as $dataType) {
            $rows = $service->normalizeRowsFromPayload([
                'rows' => [
                    [
                        'hotel_id' => 'ctrip-1001',
                        'data_date' => '2026-05-28',
                        'score' => '3.0',
                        'review_count' => 2,
                        'bad_review_count' => 1,
                        'content' => 'This review text must be redacted.',
                    ],
                ],
            ], [
                'id' => 12,
                'platform' => 'ctrip',
                'data_type' => $dataType,
                'system_hotel_id' => 7,
                'tenant_id' => 1,
            ], 34);

            self::assertCount(1, $rows, $dataType . ' payload should normalize aggregate fields');
            self::assertSame('review', $rows[0]['data_type']);
            self::assertSame(3.0, $rows[0]['comment_score']);
            self::assertSame(2, $rows[0]['quantity']);
            self::assertSame(1.0, $rows[0]['data_value']);
            self::assertStringNotContainsString('This review text must be redacted.', $rows[0]['raw_data']);
        }
    }

    public function testBrowserProfileReviewRowsStayAggregateWhenSourceIsBusiness(): void
    {
        $service = new PlatformDataSyncService();

        $rows = $service->normalizeRowsFromPayload([
            'rows' => [
                [
                    'data_type' => 'review',
                    'hotel_id' => 'mt-001',
                    'data_date' => '2026-05-28',
                    'score' => '4.2',
                    'comment_count' => 8,
                    'commentId' => 'COMMENT-SECRET-001',
                    'orderId' => 'ORDER-SECRET-001',
                    'userName' => 'Private User',
                    'roomType' => 'Private Room',
                    'content' => 'This Meituan review text must be redacted.',
                    'replyContent' => 'This reply text must be redacted.',
                ],
            ],
        ], [
            'id' => 78,
            'name' => 'Meituan Profile Source',
            'platform' => 'meituan',
            'data_type' => 'business',
            'system_hotel_id' => 7,
            'tenant_id' => 1,
            'ingestion_method' => 'browser_profile',
        ], 34);

        self::assertCount(1, $rows);
        self::assertSame('review', $rows[0]['data_type']);
        self::assertSame(4.2, $rows[0]['comment_score']);
        self::assertSame(8, $rows[0]['quantity']);
        self::assertStringNotContainsString('COMMENT-SECRET-001', $rows[0]['raw_data']);
        self::assertStringNotContainsString('ORDER-SECRET-001', $rows[0]['raw_data']);
        self::assertStringNotContainsString('Private User', $rows[0]['raw_data']);
        self::assertStringNotContainsString('Private Room', $rows[0]['raw_data']);
        self::assertStringNotContainsString('This Meituan review text must be redacted.', $rows[0]['raw_data']);
        self::assertStringNotContainsString('This reply text must be redacted.', $rows[0]['raw_data']);
    }

    public function testBrowserProfileReviewMapsCapturedCamelCaseSummaryFields(): void
    {
        $service = new PlatformDataSyncService();

        $rows = $service->normalizeRowsFromPayload([
            'rows' => [[
                'data_type' => 'review',
                'poi_id' => 'mt-001',
                'data_date' => '2026-07-11',
                'commentCount' => 5,
                'commentScore' => 5,
                'badReviewCount' => 0,
            ]],
        ], [
            'id' => 78,
            'name' => 'Meituan Profile Source',
            'platform' => 'meituan',
            'data_type' => 'business',
            'system_hotel_id' => 7,
            'tenant_id' => 1,
            'ingestion_method' => 'browser_profile',
        ], 34);

        self::assertCount(1, $rows);
        self::assertSame(5.0, $rows[0]['comment_score']);
        self::assertSame(5, $rows[0]['quantity']);
        self::assertSame(0.0, $rows[0]['data_value']);
    }

    public function testBrowserProfileMissingReviewAndOrderMetricsRemainNull(): void
    {
        $service = new PlatformDataSyncService();

        $rows = $service->normalizeRowsFromPayload([
            'rows' => [
                [
                    'data_type' => 'review',
                    'poi_id' => 'mt-001',
                    'data_date' => '2026-07-11',
                    'score' => '4.6',
                ],
                [
                    'data_type' => 'order',
                    'poi_id' => 'mt-001',
                    'data_date' => '2026-07-11',
                    'orderId' => 'MT-ORDER-001',
                    'amount' => '588.00',
                ],
            ],
        ], [
            'id' => 78,
            'name' => 'Meituan Profile Source',
            'platform' => 'meituan',
            'data_type' => 'business',
            'system_hotel_id' => 7,
            'tenant_id' => 1,
            'ingestion_method' => 'browser_profile',
        ], 34);

        self::assertCount(2, $rows);
        $reviewRow = $rows[0]['data_type'] === 'review' ? $rows[0] : $rows[1];
        $orderRow = $rows[0]['data_type'] === 'order' ? $rows[0] : $rows[1];

        self::assertSame(4.6, $reviewRow['comment_score']);
        self::assertNull($reviewRow['amount']);
        self::assertNull($reviewRow['quantity']);
        self::assertNull($reviewRow['book_order_num']);
        self::assertNull($reviewRow['qunar_comment_score']);
        self::assertNull($reviewRow['data_value']);

        self::assertSame(588.0, $orderRow['amount']);
        self::assertNull($orderRow['quantity']);
        self::assertNull($orderRow['book_order_num']);
        self::assertNull($orderRow['comment_score']);
        self::assertNull($orderRow['data_value']);
    }

    public function testBrowserProfilePeerRankStaysRawInsteadOfBecomingGenericDataValue(): void
    {
        $service = new PlatformDataSyncService();

        $rows = $service->normalizeRowsFromPayload([
            'rows' => [[
                'data_type' => 'peer_rank',
                'poi_id' => 'mt-001',
                'data_date' => '2026-07-11',
                'rank' => '2',
                'rank_type' => 'P_RZ',
            ]],
        ], [
            'id' => 78,
            'name' => 'Meituan Profile Source',
            'platform' => 'meituan',
            'data_type' => 'business',
            'system_hotel_id' => 7,
            'tenant_id' => 1,
            'ingestion_method' => 'browser_profile',
        ], 34);

        self::assertCount(1, $rows);
        self::assertNull($rows[0]['amount']);
        self::assertNull($rows[0]['quantity']);
        self::assertNull($rows[0]['book_order_num']);
        self::assertNull($rows[0]['data_value']);
        self::assertStringContainsString('"rank":"2"', $rows[0]['raw_data']);
    }

    public function testBrowserProfileCommonMetricsRemainNullWhenAbsent(): void
    {
        $service = new PlatformDataSyncService();

        $rows = $service->normalizeRowsFromPayload([
            'rows' => [
                ['data_type' => 'business', 'poi_id' => 'mt-001', 'data_date' => '2026-07-11'],
                ['data_type' => 'traffic', 'poi_id' => 'mt-001', 'data_date' => '2026-07-11'],
                ['data_type' => 'advertising', 'poi_id' => 'mt-001', 'data_date' => '2026-07-11'],
            ],
        ], [
            'id' => 78,
            'name' => 'Meituan Profile Source',
            'platform' => 'meituan',
            'data_type' => 'business',
            'system_hotel_id' => 7,
            'tenant_id' => 1,
            'ingestion_method' => 'browser_profile',
        ], 34);

        self::assertCount(3, $rows);
        foreach ($rows as $row) {
            foreach ([
                'amount',
                'quantity',
                'book_order_num',
                'list_exposure',
                'detail_exposure',
                'order_filling_num',
                'order_submit_num',
                'data_value',
            ] as $field) {
                self::assertNull($row[$field], $row['data_type'] . '.' . $field);
            }
        }
    }

    public function testBrowserProfileCommonMetricsPreserveExplicitZero(): void
    {
        $service = new PlatformDataSyncService();

        $rows = $service->normalizeRowsFromPayload([
            'rows' => [[
                'data_type' => 'traffic',
                'poi_id' => 'mt-001',
                'data_date' => '2026-07-11',
                'amount' => 0,
                'quantity' => 0,
                'book_order_num' => 0,
                'list_exposure' => 0,
                'detail_exposure' => 0,
                'order_filling_num' => 0,
                'order_submit_num' => 0,
                'data_value' => 0,
            ]],
        ], [
            'id' => 78,
            'name' => 'Meituan Profile Source',
            'platform' => 'meituan',
            'data_type' => 'business',
            'system_hotel_id' => 7,
            'tenant_id' => 1,
            'ingestion_method' => 'browser_profile',
        ], 34);

        self::assertCount(1, $rows);
        self::assertSame(0.0, $rows[0]['amount']);
        self::assertSame(0, $rows[0]['quantity']);
        self::assertSame(0, $rows[0]['book_order_num']);
        self::assertSame(0, $rows[0]['list_exposure']);
        self::assertSame(0, $rows[0]['detail_exposure']);
        self::assertSame(0, $rows[0]['order_filling_num']);
        self::assertSame(0, $rows[0]['order_submit_num']);
        self::assertSame(0.0, $rows[0]['data_value']);
    }

    public function testReviewDetailStorageStillRequiresExplicitAuthorization(): void
    {
        $service = new PlatformDataSyncService();

        $rows = $service->normalizeRowsFromPayload([
            'review_detail_collection' => true,
            'rows' => [
                [
                    'hotel_id' => 'ctrip-1001',
                    'data_date' => '2026-05-28',
                    'score' => '3.0',
                    'content' => 'This detail text must not be collected.',
                ],
            ],
        ], [
            'id' => 12,
            'platform' => 'ctrip',
            'data_type' => 'review',
            'system_hotel_id' => 7,
            'tenant_id' => 1,
        ], 34);

        self::assertSame([], $rows);
    }

    public function testBrowserProfileReviewDetailFlagRequiresAuthorizationForReviewRows(): void
    {
        $service = new PlatformDataSyncService();

        $rows = $service->normalizeRowsFromPayload([
            'review_detail_collection' => true,
            'rows' => [
                [
                    'data_type' => 'review',
                    'hotel_id' => 'mt-001',
                    'data_date' => '2026-05-28',
                    'score' => '3.8',
                    'content' => 'Detail text must not be stored.',
                ],
            ],
        ], [
            'id' => 78,
            'platform' => 'meituan',
            'data_type' => 'business',
            'system_hotel_id' => 7,
            'tenant_id' => 1,
            'ingestion_method' => 'browser_profile',
        ], 34);

        self::assertSame([], $rows);
    }

    public function testExplicitManualReviewPayloadKeepsScoresAndRedactedSummary(): void
    {
        $service = new PlatformDataSyncService();

        $rows = $service->normalizeRowsFromPayload([
            'review_detail_collection' => true,
            'rows' => [
                [
                    'hotel_id' => 'ctrip-1001',
                    'hotel_name' => 'Demo Hotel',
                    'data_date' => '2026-05-28',
                    'score' => '3.0',
                    'review_count' => 2,
                    'tags' => ['clean', 'service'],
                    'content' => 'Room was clean. Phone 13800138000 should not be stored.',
                ],
            ],
        ], [
            'id' => 12,
            'name' => 'Ctrip reviews',
            'platform' => 'ctrip',
            'data_type' => 'review',
            'system_hotel_id' => 7,
            'tenant_id' => 1,
            'ingestion_method' => 'manual',
            'config' => ['allow_review' => true],
        ], 34);

        self::assertCount(1, $rows);
        self::assertSame('review', $rows[0]['data_type']);
        self::assertSame(3.0, $rows[0]['comment_score']);
        self::assertSame(2, $rows[0]['quantity']);
        self::assertSame('manual', $rows[0]['ingestion_method']);

        $rawData = $rows[0]['raw_data'];
        self::assertStringContainsString('"review_summary"', $rawData);
        self::assertStringContainsString('"tags":["clean","service"]', $rawData);
        self::assertStringNotContainsString('13800138000', $rawData);
        self::assertStringNotContainsString('Phone 13800138000', $rawData);
    }

    public function testOrderPayloadIsRedactedBeforeNormalizedRawDataIsStored(): void
    {
        $service = new PlatformDataSyncService();

        $rows = $service->normalizeRowsFromPayload([
            'rows' => [
                [
                    'hotel_id' => 'ctrip-1001',
                    'hotel_name' => 'Demo Hotel',
                    'data_date' => '2026-05-28',
                    'orderId' => 'CTRIP-ORDER-202605280001',
                    'guestName' => 'Alice Zhang',
                    'guestPhone' => '13812345678',
                    'mobile' => '13987654321',
                    'certificateNo' => 'ID-SECRET-001',
                    'remark' => 'late arrival, call guest directly',
                    'amount' => '588.00',
                    'nights' => '2',
                    'orderStatus' => 'confirmed',
                ],
            ],
        ], [
            'id' => 13,
            'name' => 'Ctrip orders',
            'platform' => 'ctrip',
            'data_type' => 'order',
            'system_hotel_id' => 7,
            'tenant_id' => 1,
            'ingestion_method' => 'manual',
        ], 35);

        self::assertCount(1, $rows);
        self::assertSame('order', $rows[0]['data_type']);
        self::assertSame(588.0, $rows[0]['amount']);
        self::assertSame(2, $rows[0]['quantity']);

        $rawData = $rows[0]['raw_data'];
        self::assertStringNotContainsString('CTRIP-ORDER-202605280001', $rawData);
        self::assertStringNotContainsString('Alice Zhang', $rawData);
        self::assertStringNotContainsString('13812345678', $rawData);
        self::assertStringNotContainsString('13987654321', $rawData);
        self::assertStringNotContainsString('ID-SECRET-001', $rawData);
        self::assertStringNotContainsString('late arrival', $rawData);

        $decoded = json_decode($rawData, true);
        self::assertIsArray($decoded);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string)($decoded['row']['order_id_hash'] ?? ''));
        self::assertSame('A***', $decoded['row']['guest_name_masked'] ?? null);
        self::assertSame('*******5678', $decoded['row']['guest_phone_masked'] ?? null);
        self::assertArrayNotHasKey('guestName', $decoded['row']);
        self::assertArrayNotHasKey('certificateNo', $decoded['row']);
        self::assertArrayNotHasKey('remark', $decoded['row']);
    }

    public function testCtripBusinessAndQualityFieldsMapIntoExistingDailyColumns(): void
    {
        $service = new PlatformDataSyncService();

        $rows = $service->normalizeRowsFromPayload([
            'data' => [
                [
                    'hotelId' => 'ctrip-2001',
                    'hotelName' => 'Business Hotel',
                    'statDate' => '2026-05-27',
                    'checkoutRevenue' => '2888.80',
                    'checkoutRoomNights' => '18',
                    'orderQuantity' => '12',
                    'averagePrice' => '160.49',
                    'visitorTotal' => '320',
                    'serviceScore' => '92.5',
                    'psiScore' => '88.6',
                    'hotelCollect' => '17',
                ],
                [
                    'hotelId' => 'ctrip-2001',
                    'hotelName' => 'Business Hotel',
                    'statDate' => '2026-05-27',
                    'data_type' => 'quality',
                    'dimension' => 'psi_score',
                    'psiScore' => '88.6',
                    'compare_type' => 'self',
                    'source_trace_id' => 'quality-trace',
                    'url_hash' => str_repeat('e', 64),
                ],
            ],
        ], [
            'id' => 21,
            'name' => 'Ctrip business report',
            'platform' => 'ctrip',
            'data_type' => 'business',
            'system_hotel_id' => 7,
            'tenant_id' => 1,
            'ingestion_method' => 'browser_profile',
        ], 41);

        self::assertCount(2, $rows);
        $businessRow = $rows[0]['data_type'] === 'business' ? $rows[0] : $rows[1];
        $qualityRow = $rows[0]['data_type'] === 'quality' ? $rows[0] : $rows[1];

        self::assertSame('ctrip-2001', $businessRow['hotel_id']);
        self::assertSame('Business Hotel', $businessRow['hotel_name']);
        self::assertSame('2026-05-27', $businessRow['data_date']);
        self::assertSame(2888.8, $businessRow['amount']);
        self::assertSame(18, $businessRow['quantity']);
        self::assertSame(12, $businessRow['book_order_num']);
        self::assertSame(160.49, $businessRow['data_value']);
        self::assertStringContainsString('"serviceScore":"92.5"', $businessRow['raw_data']);
        self::assertStringContainsString('"psiScore":"88.6"', $businessRow['raw_data']);

        self::assertSame('quality', $qualityRow['data_type']);
        self::assertSame(88.6, $qualityRow['data_value']);
        $qualityRaw = json_decode((string)$qualityRow['raw_data'], true);
        self::assertIsArray($qualityRaw);
        $qualityFactsByKey = array_column($qualityRaw['field_facts'] ?? [], null, 'metric_key');
        self::assertSame('online_daily_data.data_value', $qualityFactsByKey['quality_score']['storage_field'] ?? '');
        self::assertSame('$.psiScore', $qualityFactsByKey['quality_score']['source_path'] ?? '');
        self::assertSame(str_repeat('e', 64), $qualityFactsByKey['quality_score']['capture_evidence']['source_url_hash'] ?? '');
    }

    public function testAdvertisingRecordsMapCostTrafficConversionAndBookings(): void
    {
        $service = new PlatformDataSyncService();

        $rows = $service->normalizeRowsFromPayload([
            'data' => [
                'records' => [
                    [
                        'hotelId' => 'ctrip-3001',
                        'hotelName' => 'Ad Hotel',
                        'date' => '2026-05-27',
                        'campaignId' => 'campaign-1',
                        'impressions' => '10000',
                        'clicks' => '320',
                        'ctr' => '3.2%',
                        'cvr' => '8.5%',
                        'todayCost' => '256.75',
                        'bookings' => '16',
                        'nights' => '23',
                        'orderAmount' => '1888.00',
                        'roas' => '7.35',
                    ],
                ],
            ],
        ], [
            'id' => 22,
            'name' => 'Ctrip ad report',
            'platform' => 'ctrip',
            'data_type' => 'ads',
            'system_hotel_id' => 7,
            'tenant_id' => 1,
            'ingestion_method' => 'browser_profile',
        ], 42);

        self::assertCount(1, $rows);
        self::assertSame('advertising', $rows[0]['data_type']);
        self::assertSame(256.75, $rows[0]['amount']);
        self::assertSame(23, $rows[0]['quantity']);
        self::assertSame(16, $rows[0]['book_order_num']);
        self::assertSame(10000, $rows[0]['list_exposure']);
        self::assertSame(320, $rows[0]['detail_exposure']);
        self::assertSame(3.2, $rows[0]['flow_rate']);
        self::assertSame(16, $rows[0]['order_submit_num']);
        self::assertSame(7.35, $rows[0]['data_value']);
        self::assertStringContainsString('"orderAmount":"1888.00"', $rows[0]['raw_data']);
    }

    public function testAdvertisingRatesDoNotFallbackIntoRoasOrMixCtrWithCvr(): void
    {
        $service = new PlatformDataSyncService();

        $rows = $service->normalizeRowsFromPayload([
            'rows' => [[
                'hotelId' => 'ctrip-3001',
                'date' => '2026-05-27',
                'data_type' => 'advertising',
                'ctr' => '3.2%',
                'cvr' => '8.5%',
                'ecpc' => '0.80',
            ]],
        ], [
            'id' => 22,
            'name' => 'Ctrip ad report',
            'platform' => 'ctrip',
            'data_type' => 'business',
            'system_hotel_id' => 7,
            'tenant_id' => 1,
            'ingestion_method' => 'browser_profile',
        ], 42);

        self::assertCount(1, $rows);
        self::assertSame('advertising', $rows[0]['data_type']);
        self::assertSame(3.2, $rows[0]['flow_rate']);
        self::assertNull($rows[0]['data_value']);
        self::assertStringContainsString('"cvr":"8.5%"', $rows[0]['raw_data']);
        self::assertStringContainsString('"ecpc":"0.80"', $rows[0]['raw_data']);
    }

    public function testOrderListPayloadMapsAmountRoomNightsAndAveragePriceWithoutPii(): void
    {
        $service = new PlatformDataSyncService();

        $rows = $service->normalizeRowsFromPayload([
            'data' => [
                'orderList' => [
                    [
                        'hotelId' => 'ctrip-4001',
                        'hotelName' => 'Order Hotel',
                        'orderDate' => '2026-05-27 10:30:00',
                        'orderId' => 'ORDER-4001',
                        'guestName' => 'Chen Ming',
                        'mobile' => '13800009999',
                        'totalAmount' => '1200.00',
                        'roomCount' => '2',
                        'nights' => '3',
                        'orderStatusDesc' => 'confirmed',
                    ],
                ],
            ],
        ], [
            'id' => 23,
            'name' => 'Ctrip orders',
            'platform' => 'ctrip',
            'data_type' => 'orders',
            'system_hotel_id' => 7,
            'tenant_id' => 1,
            'ingestion_method' => 'browser_profile',
        ], 43);

        self::assertCount(1, $rows);
        self::assertSame('order', $rows[0]['data_type']);
        self::assertSame(1200.0, $rows[0]['amount']);
        self::assertSame(6, $rows[0]['quantity']);
        self::assertNull($rows[0]['book_order_num']);
        self::assertSame(200.0, $rows[0]['data_value']);

        $rawData = $rows[0]['raw_data'];
        self::assertStringNotContainsString('ORDER-4001', $rawData);
        self::assertStringNotContainsString('Chen Ming', $rawData);
        self::assertStringNotContainsString('13800009999', $rawData);
        self::assertStringContainsString('"order_id_hash"', $rawData);
        self::assertStringContainsString('"mobile_masked":"*******9999"', $rawData);
    }

    public function testRawPayloadStorageSanitizerRedactsNestedOrderPiiAndSecrets(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'sanitizePayloadForStorage');
        $method->setAccessible(true);

        $payload = $method->invoke($service, [
            'headers' => [
                'Cookie' => 'session=secret-cookie',
                'Authorization' => 'Bearer secret-token',
            ],
            'data' => [
                'orderList' => [
                    [
                        'orderId' => 'MT-ORDER-0001',
                        'guestName' => 'Bob Lee',
                        'phone' => '13700001111',
                        'contactMobile' => '13600002222',
                        'idCardNo' => 'IDCARD-SECRET',
                        'customerRemark' => 'do not store this remark',
                        'amount' => 388,
                    ],
                ],
            ],
        ]);

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString('secret-cookie', $encoded);
        self::assertStringNotContainsString('secret-token', $encoded);
        self::assertStringNotContainsString('MT-ORDER-0001', $encoded);
        self::assertStringNotContainsString('Bob Lee', $encoded);
        self::assertStringNotContainsString('13700001111', $encoded);
        self::assertStringNotContainsString('13600002222', $encoded);
        self::assertStringNotContainsString('IDCARD-SECRET', $encoded);
        self::assertStringNotContainsString('do not store this remark', $encoded);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string)($payload['data']['orderList'][0]['order_id_hash'] ?? ''));
        self::assertSame('B***', $payload['data']['orderList'][0]['guest_name_masked'] ?? null);
        self::assertSame('*******1111', $payload['data']['orderList'][0]['phone_masked'] ?? null);
    }

    public function testRawPayloadStorageSanitizerRecursivelyRemovesUrlCredentialsQueryAndFragment(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'sanitizePayloadForStorage');
        $method->setAccessible(true);

        $payload = $method->invoke($service, [
            'source_url' => 'https://user:pass@example.test/data?token=top-secret#fragment',
            'facts' => [[
                'value' => 'https://example.test/course?id=1&token=nested-secret#section',
                'nested' => [
                    'targetUrl' => '/jump?token=relative-secret#fragment',
                ],
            ]],
        ]);

        self::assertSame('https://example.test/data', $payload['source_url']);
        self::assertSame('https://example.test/course', $payload['facts'][0]['value']);
        self::assertSame('/jump', $payload['facts'][0]['nested']['targetUrl']);
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString('top-secret', $encoded);
        self::assertStringNotContainsString('nested-secret', $encoded);
        self::assertStringNotContainsString('relative-secret', $encoded);
        self::assertStringNotContainsString('user:pass@', $encoded);
    }

    public function testNormalizedFieldFactSummaryRejectsMalformedSourceUrlHash(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'summarizeNormalizedFieldFacts');
        $method->setAccessible(true);

        $summary = $method->invoke($service, [[
            'metric_key' => 'list_exposure',
            'status' => 'captured',
            'capture_evidence' => [
                'source_trace_id' => 'ctrip:' . str_repeat('a', 64),
                'source_url_hash' => 'x',
            ],
        ]]);

        self::assertSame(1, $summary['capture_evidence_count']);
        self::assertSame(0, $summary['desensitized_capture_evidence_count']);
    }

    public function testLargeRawPayloadStorageKeepsBoundedTraceableSummary(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'buildRawRecordPayload');
        $method->setAccessible(true);

        $payload = [
            'profile_id' => '6866634',
            'hotel_id' => '6866634',
            'hotel_name' => '西安天诚',
            'source' => 'ctrip_browser_profile',
            'captured_at' => '2026-06-06 15:15:34',
            'output' => 'runtime/platform_data_sources/ctrip_browser_source_6866634_20260606151116.json',
            'rows' => array_fill(0, 1200, [
                'data_date' => '2026-06-06',
                'data_type' => 'traffic',
                'dimension' => 'search',
                'blob' => str_repeat('x', 800),
            ]),
            'responses' => array_fill(0, 200, ['url' => 'https://ebooking.ctrip.com/restapi/example']),
            'sync_summary' => [
                'row_count' => 1200,
                'standard_row_count' => 1200,
            ],
        ];

        $result = $method->invoke($service, $payload);

        self::assertIsArray($result);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string)$result['payload_hash']);
        self::assertLessThan(262144, strlen((string)$result['raw_payload']));

        $decoded = json_decode((string)$result['raw_payload'], true);
        self::assertIsArray($decoded);
        self::assertTrue($decoded['_raw_payload_truncated'] ?? false);
        self::assertSame('raw_payload_exceeds_db_packet_safe_limit', $decoded['reason'] ?? null);
        self::assertSame(1200, $decoded['payload_counts']['rows'] ?? null);
        self::assertSame(200, $decoded['payload_counts']['responses'] ?? null);
        self::assertSame($payload['output'], $decoded['trace']['output'] ?? null);
        self::assertSame($result['payload_hash'], $decoded['payload_hash'] ?? null);
        self::assertSame(1200, $decoded['meta']['sync_summary']['row_count'] ?? null);
    }

    public function testCsvImportFileParsesRowsWithHeaders(): void
    {
        $service = new PlatformDataSyncService();
        $path = tempnam(sys_get_temp_dir(), 'platform_csv_');
        file_put_contents($path, "data_date,hotel_id,amount\n2026-05-28,ctrip-1,328.5\n");

        try {
            $rows = $service->parseImportFile($path, 'ota.csv');
        } finally {
            @unlink($path);
        }

        self::assertSame([
            [
                'data_date' => '2026-05-28',
                'hotel_id' => 'ctrip-1',
                'amount' => '328.5',
            ],
        ], $rows);
    }

    public function testJsonImportFileParsesRowsEnvelope(): void
    {
        $service = new PlatformDataSyncService();
        $path = tempnam(sys_get_temp_dir(), 'platform_json_');
        file_put_contents($path, json_encode([
            'rows' => [
                ['data_date' => '2026-05-28', 'hotel_id' => 'meituan-1', 'orders' => 3],
            ],
        ], JSON_UNESCAPED_UNICODE));

        try {
            $rows = $service->parseImportFile($path, 'ota.json');
        } finally {
            @unlink($path);
        }

        self::assertSame('meituan-1', $rows[0]['hotel_id']);
        self::assertSame(3, $rows[0]['orders']);
    }

    public function testImportFileRejectsUnsupportedExtension(): void
    {
        $service = new PlatformDataSyncService();
        $path = tempnam(sys_get_temp_dir(), 'platform_txt_');
        file_put_contents($path, 'data');

        try {
            $this->expectException(\RuntimeException::class);
            $service->parseImportFile($path, 'ota.txt');
        } finally {
            @unlink($path);
        }
    }

    public function testDataSourceSanitizerUsesOpaqueCredentialIndicators(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'sanitizeSourceRow');
        $method->setAccessible(true);

        $row = $method->invoke($service, [
            'id' => 1,
            'config_json' => json_encode([
                'url' => 'https://example.com/data',
                'headers' => [
                    'Authorization' => 'Bearer secret-token',
                    'Content-Type' => 'application/json',
                ],
            ], JSON_UNESCAPED_UNICODE),
            'secret_json' => json_encode(['cookies' => 'abcdef123456'], JSON_UNESCAPED_UNICODE),
        ]);

        self::assertSame('https://example.com/data', $row['config']['url']);
        self::assertSame('application/json', $row['config']['headers']['Content-Type']);
        self::assertStringNotContainsString('secret-token', json_encode($row, JSON_UNESCAPED_UNICODE));
        self::assertTrue($row['has_secret']);
        self::assertTrue($row['has_cookies']);
        self::assertArrayNotHasKey('cookies_preview', $row);
    }

    public function testDataSourceSanitizerOmitsInternalProfileKeyHashesRecursively(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'sanitizeSourceRow');
        $method->setAccessible(true);

        $row = $method->invoke($service, [
            'id' => 73,
            'platform' => 'ctrip',
            'config_json' => json_encode([
                'profile_id' => 'profile-73',
                'profile_key_hash' => 'top-level-hash',
                'current_session_probe_profile_key_hash' => 'current-session-hash',
                'nested' => [
                    'profile_key_hash' => 'nested-hash',
                    'current_session_probe_profile_key_hash' => 'nested-current-session-hash',
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        $encoded = json_encode($row['config'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('profile_key_hash', $row['config']);
        self::assertArrayNotHasKey('current_session_probe_profile_key_hash', $row['config']);
        self::assertArrayNotHasKey('profile_key_hash', $row['config']['nested']);
        self::assertArrayNotHasKey('current_session_probe_profile_key_hash', $row['config']['nested']);
        self::assertStringNotContainsString('top-level-hash', $encoded);
        self::assertStringNotContainsString('current-session-hash', $encoded);
    }

    public function testCtripBrowserProfileAdapterSupportsOnlyCtripBrowserProfileSources(): void
    {
        $adapter = new CtripBrowserProfileDataSourceAdapter(sys_get_temp_dir(), 'node', static fn() => []);

        self::assertTrue($adapter->supports([
            'platform' => 'ctrip',
            'ingestion_method' => 'browser_profile',
        ]));
        self::assertTrue($adapter->supports([
            'platform' => 'ctrip',
            'ingestion_method' => 'profile_browser',
        ]));
        self::assertFalse($adapter->supports([
            'platform' => 'meituan',
            'ingestion_method' => 'browser_profile',
        ]));
    }

    public function testCtripBrowserProfileIdentityUsesObservedHotelFactsInsteadOfConfiguredFallback(): void
    {
        $adapter = new CtripBrowserProfileDataSourceAdapter(sys_get_temp_dir(), 'node', static fn() => []);
        $method = new \ReflectionMethod($adapter, 'evaluatePlatformIdentity');
        $method->setAccessible(true);
        $payload = [
            'catalog_facts' => [[
                'metric_key' => 'hotel_id',
                'source_key' => 'masterHotelId',
                'value' => '6866634',
            ]],
        ];

        self::assertSame('matched', $method->invoke($adapter, $payload, '6866634')['status']);
        self::assertSame('mismatch', $method->invoke($adapter, $payload, '9999999')['status']);
        $mixedPayload = $payload;
        $mixedPayload['catalog_facts'][] = [
            'metric_key' => 'hotel_id',
            'source_key' => 'hotelId',
            'value' => '9999999',
        ];
        self::assertSame('mismatch', $method->invoke($adapter, $mixedPayload, '6866634')['status']);
        self::assertSame('unverified', $method->invoke($adapter, ['catalog_facts' => []], '6866634')['status']);
        self::assertSame('not_configured', $method->invoke($adapter, $payload, '')['status']);

        $requestEvidence = [
            'platform_identity_validation' => [
                'schema_version' => 1,
                'status' => 'matched',
                'source_validation' => true,
                'evidence_source' => 'ota_request',
                'expected_identifier_count' => 1,
                'observed_identifier_count' => 1,
                'matched_identifier_count' => 1,
                'mismatched_identifier_count' => 0,
                'validated_identifier' => '130079194',
                'sensitive_values_exposed' => false,
            ],
        ];
        self::assertSame('matched', $method->invoke($adapter, $requestEvidence, '130079194')['status']);
        self::assertSame('mismatch', $method->invoke($adapter, $requestEvidence, '9999999')['status']);

        $ambiguousRequestEvidence = $requestEvidence;
        $ambiguousRequestEvidence['platform_identity_validation'] = array_replace(
            $ambiguousRequestEvidence['platform_identity_validation'],
            [
                'status' => 'ambiguous',
                'source_validation' => false,
                'observed_identifier_count' => 2,
                'mismatched_identifier_count' => 1,
                'validated_identifier' => '',
            ]
        );
        self::assertSame('mismatch', $method->invoke($adapter, $ambiguousRequestEvidence, '130079194')['status']);

        $platformName = 'Dunhuang Molan Club Wild Luxury Homestay (Mingsha Mountain & Crescent Spring Branch)';
        $headerEvidence = [
            'platform_identity_validation' => [
                'schema_version' => 1,
                'status' => 'matched',
                'source_validation' => true,
                'evidence_source' => 'trusted_ota_page_header',
                'expected_identifier_count' => 1,
                'observed_identifier_count' => 0,
                'matched_identifier_count' => 0,
                'mismatched_identifier_count' => 0,
                'expected_name_count' => 1,
                'observed_name_count' => 1,
                'matched_name_count' => 1,
                'mismatched_name_count' => 0,
                'validated_identifier' => '130079194',
                'validated_name' => $platformName,
                'sensitive_values_exposed' => false,
            ],
        ];
        $headerResult = $method->invoke($adapter, $headerEvidence, '130079194', $platformName, true);
        self::assertSame('unverified', $headerResult['status']);
        self::assertSame('trusted_ota_page_header', $headerResult['evidence_source']);
        self::assertSame($platformName, $headerResult['validated_name']);
        self::assertSame('', $headerResult['validated_identifier']);

        $pageStateEvidence = $headerEvidence;
        $pageStateEvidence['platform_identity_validation'] = array_replace(
            $pageStateEvidence['platform_identity_validation'],
            [
                'status' => 'matched',
                'source_validation' => true,
                'evidence_source' => 'trusted_ota_page_state',
                'observed_page_state_identifier_count' => 1,
                'matched_page_state_identifier_count' => 1,
                'mismatched_page_state_identifier_count' => 0,
            ]
        );
        $pageStateResult = $method->invoke($adapter, $pageStateEvidence, '130079194', $platformName, true);
        self::assertSame('matched', $pageStateResult['status']);
        self::assertSame('trusted_ota_page_state', $pageStateResult['evidence_source']);
        self::assertSame('130079194', $pageStateResult['validated_identifier']);
        $pageStateIdOnlyEvidence = $pageStateEvidence;
        $pageStateIdOnlyEvidence['platform_identity_validation'] = array_replace(
            $pageStateIdOnlyEvidence['platform_identity_validation'],
            [
                'expected_name_count' => 0,
                'observed_name_count' => 0,
                'matched_name_count' => 0,
                'mismatched_name_count' => 0,
                'validated_name' => '',
            ]
        );
        $pageStateIdOnlyResult = $method->invoke($adapter, $pageStateIdOnlyEvidence, '130079194');
        self::assertSame('matched', $pageStateIdOnlyResult['status']);
        self::assertSame('130079194', $pageStateIdOnlyResult['validated_identifier']);
        self::assertSame('unverified', $method->invoke(
            $adapter,
            $headerEvidence,
            '130079194',
            $platformName,
            false
        )['status']);

        $wrongHeaderEvidence = $headerEvidence;
        $wrongHeaderEvidence['platform_identity_validation']['validated_name'] = 'Different Ctrip Hotel';
        self::assertSame('mismatch', $method->invoke(
            $adapter,
            $wrongHeaderEvidence,
            '130079194',
            $platformName,
            true
        )['status']);

        $referenceMethod = new \ReflectionMethod($adapter, 'platformNameIdentityReference');
        $referenceMethod->setAccessible(true);
        $reference = $referenceMethod->invoke($adapter, [
            'config' => [
                'platform_hotel_name' => $platformName,
                'platform_hotel_identity_source' => 'trip_public_profile',
                'platform_hotel_public_url' => 'https://uk.trip.com/hotels/dunhuang-hotel-detail-130079194/dunhuang-molan-club-wild-luxury-homestay/',
                'platform_hotel_identity_checked_at' => '2026-07-22 18:00:00',
            ],
        ], '130079194');
        self::assertTrue($reference['valid']);
        self::assertFalse($referenceMethod->invoke($adapter, [
            'config' => [
                'platform_hotel_name' => $platformName,
                'platform_hotel_identity_source' => 'trip_public_profile',
                'platform_hotel_public_url' => 'https://example.com/hotel-detail-130079194/',
                'platform_hotel_identity_checked_at' => '2026-07-22 18:00:00',
            ],
        ], '130079194')['valid']);
    }

    public function testCtripBrowserProfileAdapterPassesTrustedPlatformNameButRejectsNameOnlyIdentityProof(): void
    {
        $root = $this->createCtripBrowserProfileTestRoot('hotel_001');
        $capturedArgs = [];
        $platformName = 'Dunhuang Molan Club Wild Luxury Homestay (Mingsha Mountain & Crescent Spring Branch)';

        try {
            $adapter = new CtripBrowserProfileDataSourceAdapter($root, 'node', static function (array $args) use (&$capturedArgs, $platformName): array {
                $capturedArgs[] = $args;
                $outputPath = '';
                foreach ($args as $arg) {
                    if (str_starts_with((string)$arg, '--output=')) {
                        $outputPath = substr((string)$arg, strlen('--output='));
                        break;
                    }
                }
                file_put_contents($outputPath, json_encode([
                    'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                    'capture_gate' => ['status' => 'pass'],
                    'platform_identity_validation' => [
                        'schema_version' => 1,
                        'status' => 'matched',
                        'source_validation' => true,
                        'evidence_source' => 'trusted_ota_page_header',
                        'expected_identifier_count' => 1,
                        'observed_identifier_count' => 0,
                        'matched_identifier_count' => 0,
                        'mismatched_identifier_count' => 0,
                        'expected_name_count' => 1,
                        'observed_name_count' => 1,
                        'matched_name_count' => 1,
                        'mismatched_name_count' => 0,
                        'validated_identifier' => '130079194',
                        'validated_name' => $platformName,
                        'sensitive_values_exposed' => false,
                    ],
                    'standard_rows' => [[
                        'hotel_id' => '130079194',
                        'hotel_name' => '敦煌漠蓝新',
                        'data_date' => '2026-07-21',
                        'data_type' => 'business',
                        'amount' => '1888',
                        'source_trace_id' => 'trusted-header-proof-row',
                    ]],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return ['success' => true, 'message' => 'ok', 'stdout' => '', 'stderr' => ''];
            });
            $source = $this->ctripBrowserProfileSource();
            $source['config'] = array_replace($source['config'], [
                'hotel_id' => '130079194',
                'hotel_name' => '敦煌漠蓝新',
                'capture_sections' => 'business_overview',
                'platform_hotel_name' => $platformName,
                'platform_hotel_identity_source' => 'trip_public_profile',
                'platform_hotel_public_url' => 'https://uk.trip.com/hotels/dunhuang-hotel-detail-130079194/dunhuang-molan-club-wild-luxury-homestay/',
                'platform_hotel_identity_checked_at' => '2026-07-22 18:00:00',
            ]);

            $result = $adapter->fetch($source, [
                'interactive_browser' => false,
                'data_date' => '2026-07-21',
            ]);

            self::assertSame('failed', $result['status']);
            self::assertSame('ctrip_platform_identity_unverified', $result['status_code']);
            self::assertSame('unverified', $result['payload']['platform_identity_validation']['status']);
            self::assertSame('', $result['payload']['platform_identity_validation']['validated_identifier']);
            self::assertTrue((bool)array_filter(
                $capturedArgs,
                static fn(array $args): bool => in_array('--platform-hotel-name=' . $platformName, $args, true)
            ));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testCtripBrowserProfileAdapterRejectsRowsWithoutVerifiedHotelIdentity(): void
    {
        $root = $this->createCtripBrowserProfileTestRoot('hotel_001');

        try {
            $adapter = new CtripBrowserProfileDataSourceAdapter($root, 'node', $this->captureRunner([
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                'capture_gate' => ['status' => 'pass'],
                'catalog_facts' => [],
                'standard_rows' => [[
                    'hotel_id' => '24588',
                    'data_date' => '2026-05-31',
                    'data_type' => 'business',
                    'amount' => 100,
                ]],
            ]));

            $result = $adapter->fetch($this->ctripBrowserProfileSource(), ['interactive_browser' => false]);

            self::assertSame('failed', $result['status']);
            self::assertSame('ctrip_platform_identity_unverified', $result['status_code']);
            self::assertArrayNotHasKey('rows', $result['payload']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testCtripBrowserProfileAdapterReturnsWaitingConfigWhenProfileIsMissing(): void
    {
        $root = $this->createCtripBrowserProfileTestRoot();

        try {
            $adapter = new CtripBrowserProfileDataSourceAdapter($root, 'node', static fn() => []);
            $result = $adapter->fetch([
                'platform' => 'ctrip',
                'ingestion_method' => 'browser_profile',
                'system_hotel_id' => 7,
                'config' => [
                    'profile_id' => 'hotel_001',
                ],
            ], ['interactive_browser' => false]);

            self::assertSame('waiting_config', $result['status']);
            self::assertStringContainsString('storage/ctrip_profile_hotel_001', $result['message']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testCtripBrowserProfileAdapterUsesProtectedCdpWithoutLocalProfileDirectory(): void
    {
        $root = $this->createCtripBrowserProfileTestRoot();
        $capturedArgs = [];

        try {
            $adapter = new CtripBrowserProfileDataSourceAdapter($root, 'node', static function (array $args) use (&$capturedArgs): array {
                $capturedArgs = $args;
                $outputPath = '';
                foreach ($args as $arg) {
                    if (str_starts_with((string)$arg, '--output=')) {
                        $outputPath = substr((string)$arg, strlen('--output='));
                    }
                }
                file_put_contents($outputPath, json_encode([
                    'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                    'capture_gate' => ['status' => 'pass'],
                    'platform_identity_validation' => [
                        'status' => 'matched',
                        'source_validation' => true,
                        'validated_identifier' => '24588',
                    ],
                    'catalog_facts' => [[
                        'metric_key' => 'hotel_id',
                        'source_key' => 'masterHotelId',
                        'value' => '24588',
                    ]],
                    'standard_rows' => [[
                        'hotel_id' => '24588',
                        'hotel_name' => 'Ctrip Demo Hotel',
                        'data_date' => '2026-07-29',
                        'data_type' => 'traffic',
                        'list_exposure' => 120,
                        'source_trace_id' => 'ctrip-cloud-profile-cdp',
                    ]],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return ['success' => true, 'message' => 'ok', 'stdout' => '', 'stderr' => ''];
            });

            $result = $adapter->fetch($this->ctripBrowserProfileSource(), [
                'interactive_browser' => false,
                'data_date' => '2026-07-29',
                'cdp_url' => 'http://127.0.0.1:9223',
                'ctrip_section_concurrency' => 4,
            ]);

            self::assertSame(
                'success',
                $result['status'],
                json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            self::assertContains('--cdp-url=http://127.0.0.1:9223', $capturedArgs);
            self::assertContains('--section-concurrency=1', $capturedArgs);
            self::assertDirectoryDoesNotExist($root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'ctrip_profile_hotel_001');
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testCtripBrowserProfileAdapterReturnsWaitingConfigWhenLoginExpired(): void
    {
        $root = $this->createCtripBrowserProfileTestRoot('hotel_001');

        try {
            $adapter = new CtripBrowserProfileDataSourceAdapter($root, 'node', $this->captureRunner([
                'auth_status' => [
                    'ok' => false,
                    'status' => 'login_required',
                    'message' => 'Ctrip login expired.',
                ],
                'capture_gate' => ['status' => 'not_run'],
            ]));
            $result = $adapter->fetch($this->ctripBrowserProfileSource(), ['interactive_browser' => false]);

            self::assertSame('waiting_config', $result['status']);
            self::assertSame('Ctrip login expired.', $result['message']);
            self::assertArrayNotHasKey('rows', $result['payload']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testCtripBrowserProfileAdapterKeepsWaitingConfigReasonWhenSequentialCaptureIsUsed(): void
    {
        $root = $this->createCtripBrowserProfileTestRoot('hotel_001');

        try {
            $adapter = new CtripBrowserProfileDataSourceAdapter($root, 'node', $this->captureRunner([
                'auth_status' => [
                    'ok' => false,
                    'status' => 'login_required',
                    'message' => 'Ctrip login expired.',
                ],
                'capture_gate' => ['status' => 'not_run'],
            ]));
            $result = $adapter->fetch($this->ctripBrowserProfileSource(), [
                'interactive_browser' => false,
                'sequential_sections' => true,
                'profile_field_config' => [
                    'fields' => [
                        [
                            'field_key' => 'weekly_self_list_exposure',
                            'section' => 'business_weekly_overview',
                            'enabled' => true,
                        ],
                        [
                            'field_key' => 'detail_visitor',
                            'section' => 'traffic_report',
                            'enabled' => true,
                        ],
                    ],
                ],
            ]);

            self::assertSame('waiting_config', $result['status']);
            self::assertSame('Ctrip login expired.', $result['message']);
            self::assertCount(1, $result['payload']['capture_module_results']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testCtripBrowserProfileAdapterDoesNotInjectStoredCookiesWhenProfileExists(): void
    {
        $root = $this->createCtripBrowserProfileTestRoot('hotel_001');
        $capturedArgs = [];

        try {
            $adapter = new CtripBrowserProfileDataSourceAdapter($root, 'node', static function (array $args) use (&$capturedArgs): array {
                $capturedArgs = $args;
                $outputPath = '';
                foreach ($args as $arg) {
                    if (str_starts_with((string)$arg, '--output=')) {
                        $outputPath = substr((string)$arg, strlen('--output='));
                        break;
                    }
                }
                if ($outputPath !== '') {
                    file_put_contents($outputPath, json_encode([
                        'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                        'capture_gate' => ['status' => 'pass'],
                        'catalog_facts' => [['metric_key' => 'hotel_id', 'source_key' => 'masterHotelId', 'value' => '24588']],
                        'standard_rows' => [
                            [
                                'hotel_id' => '24588',
                                'hotel_name' => 'Ctrip Demo Hotel',
                                'data_date' => '2026-05-31',
                                'data_type' => 'business',
                                'amount' => '1888',
                                'source_trace_id' => 'profile-cookie-skip-row',
                            ],
                        ],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
                return ['success' => true, 'message' => 'ok', 'stdout' => '', 'stderr' => ''];
            });
            $source = $this->ctripBrowserProfileSource();
            $source['secret'] = ['cookies' => 'old_session=expired'];
            $result = $adapter->fetch($source, ['interactive_browser' => false]);

            self::assertSame('success', $result['status']);
            self::assertSame([], array_values(array_filter(
                $capturedArgs,
                static fn($arg): bool => str_starts_with((string)$arg, '--cookies-file=')
            )));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testCtripBrowserProfileAdapterNeverInjectsStoredCookiesForInteractiveProfileSetup(): void
    {
        $root = $this->createCtripBrowserProfileTestRoot();
        $capturedArgs = [];

        try {
            $adapter = new CtripBrowserProfileDataSourceAdapter($root, 'node', static function (array $args) use (&$capturedArgs): array {
                $capturedArgs = $args;
                $outputPath = '';
                foreach ($args as $arg) {
                    if (str_starts_with((string)$arg, '--output=')) {
                        $outputPath = substr((string)$arg, strlen('--output='));
                        break;
                    }
                }
                if ($outputPath !== '') {
                    file_put_contents($outputPath, json_encode([
                        'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                        'capture_gate' => ['status' => 'pass'],
                        'catalog_facts' => [['metric_key' => 'hotel_id', 'source_key' => 'masterHotelId', 'value' => '24588']],
                        'standard_rows' => [[
                            'hotel_id' => '24588',
                            'hotel_name' => 'Ctrip Demo Hotel',
                            'data_date' => '2026-05-31',
                            'data_type' => 'business',
                            'amount' => '1888',
                            'source_trace_id' => 'profile-interactive-cookie-skip-row',
                        ]],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
                return ['success' => true, 'message' => 'ok', 'stdout' => '', 'stderr' => ''];
            });
            $source = $this->ctripBrowserProfileSource();
            $source['secret'] = ['cookies' => 'legacy_session=must_not_be_injected'];
            $result = $adapter->fetch($source, ['interactive_browser' => true]);

            self::assertSame('success', $result['status']);
            self::assertSame([], array_values(array_filter(
                $capturedArgs,
                static fn($arg): bool => str_starts_with((string)$arg, '--cookies-file=')
            )));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testCtripBrowserProfileAdapterFallsBackToSequentialWhenParallelCaptureFails(): void
    {
        $root = $this->createCtripBrowserProfileTestRoot('hotel_001');
        $capturedSections = [];

        try {
            $adapter = new CtripBrowserProfileDataSourceAdapter($root, 'node', static function (array $args) use (&$capturedSections): array {
                $outputPath = '';
                $sections = '';
                foreach ($args as $arg) {
                    if (str_starts_with((string)$arg, '--output=')) {
                        $outputPath = substr((string)$arg, strlen('--output='));
                    }
                    if (str_starts_with((string)$arg, '--sections=')) {
                        $sections = substr((string)$arg, strlen('--sections='));
                    }
                }
                $capturedSections[] = $sections;
                if ($outputPath === '') {
                    return ['success' => false, 'message' => 'missing output path', 'stdout' => '', 'stderr' => ''];
                }

                $payload = [
                    'network_freshness' => self::READY_NETWORK_FRESHNESS,
                    'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                    'capture_gate' => ['status' => 'pass'],
                    'catalog_facts' => [['metric_key' => 'hotel_id', 'source_key' => 'masterHotelId', 'value' => '24588']],
                    'standard_rows' => [],
                    'business' => [],
                    'traffic' => [],
                ];
                if (!str_contains($sections, ',')) {
                    $payload['standard_rows'][] = [
                        'hotel_id' => '24588',
                        'hotel_name' => 'Ctrip Demo Hotel',
                        'data_date' => '2026-05-31',
                        'data_type' => $sections === 'traffic_report' ? 'traffic' : 'business',
                        'amount' => $sections === 'traffic_report' ? 0 : 100,
                        'source_trace_id' => 'fallback-' . $sections,
                    ];
                }
                file_put_contents($outputPath, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                return ['success' => true, 'message' => 'ok', 'stdout' => '', 'stderr' => ''];
            });

            $source = $this->ctripBrowserProfileSource();
            $source['config']['capture_sections'] = 'business_overview,traffic_report';
            $result = $adapter->fetch($source, [
                'interactive_browser' => false,
                'ctrip_section_concurrency' => 2,
            ]);

            self::assertSame('success', $result['status']);
            self::assertCount(2, $result['payload']['rows']);
            self::assertSame([
                'business_overview,traffic_report',
                'business_overview',
                'traffic_report',
            ], $capturedSections);
            self::assertSame('failed', $result['payload']['parallel_capture_fallback']['original_status']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testCtripBrowserProfileAdapterDoesNotRepeatSectionsAfterBrowserTimeout(): void
    {
        $root = $this->createCtripBrowserProfileTestRoot('hotel_001');
        $capturedSections = [];

        try {
            $adapter = new CtripBrowserProfileDataSourceAdapter($root, 'node', static function (array $args) use (&$capturedSections): array {
                foreach ($args as $arg) {
                    if (str_starts_with((string)$arg, '--sections=')) {
                        $capturedSections[] = substr((string)$arg, strlen('--sections='));
                    }
                }
                return ['success' => false, 'message' => 'Ctrip browser capture timed out.', 'stdout' => '', 'stderr' => ''];
            });

            $source = $this->ctripBrowserProfileSource();
            $source['config']['capture_sections'] = 'business_overview,traffic_report';
            $result = $adapter->fetch($source, ['interactive_browser' => false, 'ctrip_section_concurrency' => 2]);

            self::assertSame('failed', $result['status']);
            self::assertSame(['business_overview,traffic_report'], $capturedSections);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testCtripBrowserProfileAdapterRejectsConcurrentCaptureForSameProfile(): void
    {
        $root = $this->createCtripBrowserProfileTestRoot('hotel_001');
        $lockDir = $root . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'locks';
        mkdir($lockDir, 0775, true);
        $lockHandle = fopen($lockDir . DIRECTORY_SEPARATOR . 'profile_capture_ctrip_hotel_001.lock', 'c+');
        self::assertIsResource($lockHandle);
        self::assertTrue(flock($lockHandle, LOCK_EX | LOCK_NB));

        try {
            $adapter = new CtripBrowserProfileDataSourceAdapter($root, 'node', $this->captureRunner([
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                'capture_gate' => ['status' => 'pass'],
                'standard_rows' => [
                    ['hotel_id' => '24588', 'data_date' => '2026-05-31', 'amount' => 100],
                ],
            ]));
            $result = $adapter->fetch($this->ctripBrowserProfileSource(), ['interactive_browser' => false]);

            self::assertSame('failed', $result['status']);
            self::assertStringContainsString('already running', $result['message']);
            self::assertSame('ctrip:hotel_001', $result['payload']['lock_key']);
        } finally {
            if (is_resource($lockHandle)) {
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
            }
            $this->removeDirectory($root);
        }
    }

    public function testCtripBrowserProfileAdapterFailsWhenNoBusinessRowsAreParsed(): void
    {
        $root = $this->createCtripBrowserProfileTestRoot('hotel_001');

        try {
            $adapter = new CtripBrowserProfileDataSourceAdapter($root, 'node', $this->captureRunner([
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                'capture_gate' => ['status' => 'pass'],
                'catalog_facts' => [['metric_key' => 'hotel_id', 'source_key' => 'masterHotelId', 'value' => '24588']],
                'standard_rows' => [],
                'business' => [],
                'traffic' => [],
            ]));
            $result = $adapter->fetch($this->ctripBrowserProfileSource(), ['interactive_browser' => false]);

            self::assertSame('failed', $result['status']);
            self::assertStringContainsString('no business rows', $result['message']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testCtripBrowserProfileAdapterAllowsFieldCoverageWarningWhenRowsExist(): void
    {
        $root = $this->createCtripBrowserProfileTestRoot('hotel_001');

        try {
            $adapter = new CtripBrowserProfileDataSourceAdapter($root, 'node', $this->captureRunner([
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                'capture_gate' => [
                    'status' => 'fail',
                    'failed_check_ids' => ['field_coverage'],
                    'checks' => [
                        ['id' => 'field_coverage', 'status' => 'fail', 'actual' => '69.84%', 'expected' => '>=80%'],
                    ],
                ],
                'responses' => [['url' => 'https://ebooking.ctrip.com/restapi/test']],
                'standard_rows' => [
                    [
                        'hotel_id' => '24588',
                        'hotel_name' => 'Ctrip Demo Hotel',
                        'data_date' => '2026-05-31',
                        'data_type' => 'business',
                        'amount' => '1288.50',
                        'room_nights' => '6',
                        'orders' => '4',
                        'source_trace_id' => 'trace-soft-gate-row',
                    ],
                ],
                'business' => [],
                'traffic' => [],
            ]));
            $source = $this->ctripBrowserProfileSource();
            $result = $adapter->fetch($source, ['interactive_browser' => false]);

            self::assertSame('success', $result['status']);
            self::assertStringContainsString('Capture gate warning', $result['message']);
            self::assertCount(1, $result['payload']['rows']);
            self::assertSame(['field_coverage'], $result['payload']['capture_gate_warning']['failed_check_ids']);
            self::assertSame([], $result['payload']['capture_gate_warning']['blocking_failed_check_ids']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testCtripBrowserProfileAdapterAllowsEndpointCoverageWarningWhenRowsExist(): void
    {
        $root = $this->createCtripBrowserProfileTestRoot('hotel_001');

        try {
            $adapter = new CtripBrowserProfileDataSourceAdapter($root, 'node', $this->captureRunner([
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                'capture_gate' => [
                    'status' => 'fail',
                    'failed_check_ids' => ['endpoint_coverage'],
                    'checks' => [
                        ['id' => 'endpoint_coverage', 'status' => 'fail', 'actual' => '13/14', 'expected' => 'missing<=0'],
                    ],
                ],
                'responses' => [['url' => 'https://ebooking.ctrip.com/restapi/test']],
                'standard_rows' => [
                    [
                        'hotel_id' => '24588',
                        'hotel_name' => 'Ctrip Demo Hotel',
                        'data_date' => '2026-05-31',
                        'data_type' => 'business',
                        'amount' => '1288.50',
                        'source_trace_id' => 'trace-endpoint-soft-gate-row',
                    ],
                ],
                'business' => [],
                'traffic' => [],
            ]));
            $source = $this->ctripBrowserProfileSource();
            $result = $adapter->fetch($source, ['interactive_browser' => false]);

            self::assertSame('success', $result['status']);
            self::assertStringContainsString('Capture gate warning', $result['message']);
            self::assertCount(1, $result['payload']['rows']);
            self::assertSame(['endpoint_coverage'], $result['payload']['capture_gate_warning']['failed_check_ids']);
            self::assertSame([], $result['payload']['capture_gate_warning']['blocking_failed_check_ids']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testCtripBrowserProfileAdapterInfersSectionsFromEnabledFieldConfig(): void
    {
        $root = $this->createCtripBrowserProfileTestRoot('hotel_001');
        $capturedArgs = [];
        $capturedFieldConfigs = [];

        try {
            $adapter = new CtripBrowserProfileDataSourceAdapter($root, 'node', function (array $args) use (&$capturedArgs, &$capturedFieldConfigs, $root): array {
                $capturedArgs[] = $args;
                $outputPath = '';
                $section = '';
                foreach ($args as $arg) {
                    if (str_starts_with((string)$arg, '--output=')) {
                        $outputPath = substr((string)$arg, strlen('--output='));
                    }
                    if (str_starts_with((string)$arg, '--sections=')) {
                        $section = substr((string)$arg, strlen('--sections='));
                    }
                    if (str_starts_with((string)$arg, '--field-config=')) {
                        $fieldConfigPath = substr((string)$arg, strlen('--field-config='));
                        $capturedFieldConfigs[] = json_decode((string)file_get_contents($fieldConfigPath), true);
                    }
                }
                if ($outputPath !== '') {
                    file_put_contents($outputPath, json_encode([
                        'network_freshness' => self::READY_NETWORK_FRESHNESS,
                        'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                        'capture_gate' => ['status' => 'pass'],
                        'catalog_facts' => [['metric_key' => 'hotel_id', 'source_key' => 'masterHotelId', 'value' => '24588']],
                        'by_section' => [
                            $section => [['metric_key' => 'field-config-row-' . $section]],
                        ],
                        'standard_rows' => [
                            [
                                'hotel_id' => '24588',
                                'hotel_name' => 'Ctrip Demo Hotel',
                                'data_date' => '2026-05-31',
                                'data_type' => $section === 'traffic_report' ? 'traffic' : 'business',
                                'list_exposure' => '100',
                                'source_trace_id' => 'field-config-row-' . $section,
                            ],
                        ],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
                return ['success' => true, 'message' => 'ok', 'stdout' => '', 'stderr' => ''];
            });

            $source = $this->ctripBrowserProfileSource();
            $source['config']['capture_sections'] = 'core';
            $result = $adapter->fetch($source, [
                'interactive_browser' => false,
                'sequential_sections' => true,
                'profile_field_config' => [
                    'fields' => [
                        [
                            'id' => 'weekly_self_list_exposure',
                            'field_key' => 'weekly_self_list_exposure',
                            'field_name' => 'Weekly self exposure',
                            'section' => 'business_weekly_overview',
                            'enabled' => true,
                        ],
                        [
                            'id' => 'detail_visitor',
                            'field_key' => 'detail_visitor',
                            'field_name' => 'Detail visitor',
                            'section' => 'traffic_report',
                            'enabled' => true,
                        ],
                        [
                            'id' => 'order_count',
                            'field_key' => 'order_count',
                            'field_name' => 'Order count',
                            'section' => 'business_overview',
                            'enabled' => false,
                        ],
                    ],
                ],
            ]);

            self::assertSame('success', $result['status']);
            self::assertCount(2, $capturedArgs);
            $sectionArgs = array_map(static function (array $args): string {
                $sectionArg = current(array_filter($args, static fn($arg): bool => str_starts_with((string)$arg, '--sections=')));
                return is_string($sectionArg) ? substr($sectionArg, strlen('--sections=')) : '';
            }, $capturedArgs);
            self::assertSame(['business_weekly_overview', 'traffic_report'], $sectionArgs);
            self::assertSame([['business_weekly_overview'], ['traffic_report']], array_map(
                static fn(array $config): array => $config['allowed_sections'] ?? [],
                $capturedFieldConfigs
            ));
            self::assertSame('business_weekly_overview,traffic_report', $result['payload']['data_source_capture']['capture_sections']);
            self::assertSame('sequential_sections', $result['payload']['data_source_capture']['capture_mode']);
            self::assertCount(2, $result['payload']['capture_module_results']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testCtripBrowserProfileAdapterRejectsCredentialMaterialInFieldMetadata(): void
    {
        $adapter = new CtripBrowserProfileDataSourceAdapter(sys_get_temp_dir(), 'node', static fn(): array => []);
        $method = new \ReflectionMethod($adapter, 'buildProfileFieldConfigPayload');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('field metadata contains credential material');
        $method->invoke($adapter, [
            'profile_field_config' => [
                'fields' => [[
                    'id' => 'unsafe-field',
                    'field_key' => 'unsafe_field',
                    'field_name' => 'Unsafe field',
                    'section' => 'traffic_report',
                    'source_interface' => 'Authorization: Bearer adapter-field-secret',
                    'source_keys' => 'metric.value',
                    'enabled' => true,
                ]],
            ],
        ]);
    }

    public function testCtripBrowserProfileAdapterHonorsConfiguredSectionsWithWideFieldConfig(): void
    {
        $root = $this->createCtripBrowserProfileTestRoot('hotel_001');
        $capturedArgs = [];

        try {
            $adapter = new CtripBrowserProfileDataSourceAdapter($root, 'node', static function (array $args) use (&$capturedArgs): array {
                $capturedArgs[] = $args;
                $outputPath = '';
                foreach ($args as $arg) {
                    if (str_starts_with((string)$arg, '--output=')) {
                        $outputPath = substr((string)$arg, strlen('--output='));
                    }
                }
                if ($outputPath !== '') {
                    file_put_contents($outputPath, json_encode([
                        'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                        'capture_gate' => ['status' => 'pass'],
                        'catalog_facts' => [['metric_key' => 'hotel_id', 'source_key' => 'masterHotelId', 'value' => '24588']],
                        'standard_rows' => [
                            [
                                'hotel_id' => '24588',
                                'hotel_name' => 'Ctrip Demo Hotel',
                                'data_date' => '2026-05-31',
                                'data_type' => 'business',
                                'amount' => 100,
                                'source_trace_id' => 'configured-sections-row',
                            ],
                        ],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
                return ['success' => true, 'message' => 'ok', 'stdout' => '', 'stderr' => ''];
            });

            $source = $this->ctripBrowserProfileSource();
            $source['config']['capture_sections'] = 'default';

            $result = $adapter->fetch($source, [
                'interactive_browser' => false,
                'capture_plan' => 'future_demand',
                'profile_field_config' => [
                    'fields' => [
                        ['id' => 'business_amount', 'field_key' => 'amount', 'section' => 'business_overview', 'enabled' => true],
                        ['id' => 'traffic_detail', 'field_key' => 'detail_visitor', 'section' => 'traffic_report', 'enabled' => true],
                        ['id' => 'ads_cost', 'field_key' => 'cost', 'section' => 'ads_pyramid', 'enabled' => true],
                    ],
                ],
            ]);

            self::assertSame('success', $result['status']);
            self::assertCount(1, $capturedArgs);
            $sectionArg = current(array_filter($capturedArgs[0], static fn($arg): bool => str_starts_with((string)$arg, '--sections=')));
            self::assertSame('business_overview,traffic_report', substr((string)$sectionArg, strlen('--sections=')));
            self::assertContains('--capture-plan=future_demand', $capturedArgs[0]);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testCtripBrowserProfileAdapterKeepsBoundedDailySectionDespiteWideFieldConfig(): void
    {
        $adapter = new CtripBrowserProfileDataSourceAdapter(sys_get_temp_dir(), 'node', static fn(): array => []);
        $method = new \ReflectionMethod($adapter, 'resolveCaptureSections');
        $method->setAccessible(true);

        self::assertSame('business_overview', $method->invoke($adapter, [
            'bounded_capture_sections' => 'business_overview',
        ], [
            'capture_sections' => 'default',
        ], [
            'configured' => true,
            'allowed_sections' => ['business_overview', 'traffic_report'],
        ]));
    }

    public function testCtripBrowserProfileAdapterRunsEnabledSectionsInParallelByDefault(): void
    {
        $root = $this->createCtripBrowserProfileTestRoot('hotel_001');
        $capturedArgs = [];

        try {
            $adapter = new CtripBrowserProfileDataSourceAdapter($root, 'node', static function (array $args) use (&$capturedArgs): array {
                $capturedArgs[] = $args;
                $outputPath = '';
                foreach ($args as $arg) {
                    if (str_starts_with((string)$arg, '--output=')) {
                        $outputPath = substr((string)$arg, strlen('--output='));
                        break;
                    }
                }
                if ($outputPath !== '') {
                    file_put_contents($outputPath, json_encode([
                        'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                        'capture_gate' => ['status' => 'pass'],
                        'catalog_facts' => [['metric_key' => 'hotel_id', 'source_key' => 'masterHotelId', 'value' => '24588']],
                        'capture_execution' => [
                            'mode' => 'parallel_pages',
                            'section_concurrency' => 3,
                            'fallback_sections' => [],
                        ],
                        'standard_rows' => [
                            [
                                'hotel_id' => '24588',
                                'hotel_name' => 'Ctrip Demo Hotel',
                                'data_date' => '2026-05-31',
                                'data_type' => 'business',
                                'amount' => '1888',
                                'source_trace_id' => 'parallel-row',
                            ],
                        ],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
                return ['success' => true, 'message' => 'ok', 'stdout' => '', 'stderr' => ''];
            });

            $source = $this->ctripBrowserProfileSource();
            $source['config']['capture_sections'] = 'core';
            $result = $adapter->fetch($source, [
                'interactive_browser' => false,
                'profile_field_config' => [
                    'fields' => [
                        [
                            'id' => 'weekly_self_list_exposure',
                            'field_key' => 'weekly_self_list_exposure',
                            'section' => 'business_weekly_overview',
                            'enabled' => true,
                        ],
                        [
                            'id' => 'detail_visitor',
                            'field_key' => 'detail_visitor',
                            'section' => 'traffic_report',
                            'enabled' => true,
                        ],
                    ],
                ],
            ]);

            self::assertSame('success', $result['status']);
            self::assertCount(1, $capturedArgs);
            $sectionArg = current(array_filter($capturedArgs[0], static fn($arg): bool => str_starts_with((string)$arg, '--sections=')));
            self::assertSame('business_weekly_overview,traffic_report', substr((string)$sectionArg, strlen('--sections=')));
            self::assertContains('--section-concurrency=3', $capturedArgs[0]);
            self::assertContains('--capture-plan=full', $capturedArgs[0]);
            self::assertSame('parallel_pages', $result['payload']['capture_execution']['mode']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testCtripBrowserProfileAdapterPassesNotApplicableSectionsAndSkipsCapture(): void
    {
        $root = $this->createCtripBrowserProfileTestRoot('hotel_001');
        $capturedArgs = [];

        try {
            $adapter = new CtripBrowserProfileDataSourceAdapter($root, 'node', static function (array $args) use (&$capturedArgs): array {
                $capturedArgs[] = $args;
                $outputPath = '';
                $sections = '';
                $notApplicableSections = [];
                foreach ($args as $arg) {
                    if (str_starts_with((string)$arg, '--output=')) {
                        $outputPath = substr((string)$arg, strlen('--output='));
                    }
                    if (str_starts_with((string)$arg, '--sections=')) {
                        $sections = substr((string)$arg, strlen('--sections='));
                    }
                    if (str_starts_with((string)$arg, '--not-applicable-sections=')) {
                        $notApplicableSections = array_values(array_filter(explode(',', substr((string)$arg, strlen('--not-applicable-sections=')))));
                    }
                }
                if ($outputPath !== '') {
                    file_put_contents($outputPath, json_encode([
                        'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                        'capture_gate' => ['status' => 'pass'],
                        'catalog_facts' => [['metric_key' => 'hotel_id', 'source_key' => 'masterHotelId', 'value' => '24588']],
                        'not_applicable_sections' => $notApplicableSections,
                        'standard_rows' => [
                            [
                                'hotel_id' => '24588',
                                'hotel_name' => 'Ctrip Demo Hotel',
                                'data_date' => '2026-05-31',
                                'data_type' => 'business',
                                'amount' => 100,
                                'source_trace_id' => 'not-applicable-row',
                            ],
                        ],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
                return ['success' => true, 'message' => 'ok', 'stdout' => $sections, 'stderr' => ''];
            });

            $source = $this->ctripBrowserProfileSource();
            $source['config']['capture_sections'] = 'business_overview,ads_pyramid,traffic_report';
            $source['config']['not_applicable_sections'] = 'ads';

            $result = $adapter->fetch($source, ['interactive_browser' => false]);

            self::assertSame('success', $result['status']);
            self::assertCount(1, $capturedArgs);
            $sectionArg = current(array_filter($capturedArgs[0], static fn($arg): bool => str_starts_with((string)$arg, '--sections=')));
            $notApplicableArg = current(array_filter($capturedArgs[0], static fn($arg): bool => str_starts_with((string)$arg, '--not-applicable-sections=')));
            self::assertSame('business_overview,traffic_report', substr((string)$sectionArg, strlen('--sections=')));
            self::assertSame('ads_pyramid', substr((string)$notApplicableArg, strlen('--not-applicable-sections=')));
            self::assertSame(['ads_pyramid'], $result['payload']['data_source_capture']['not_applicable_sections']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testCtripBrowserProfileAdapterKeepsSuccessfulSectionRowsWhenLaterSectionFails(): void
    {
        $root = $this->createCtripBrowserProfileTestRoot('hotel_001');

        try {
            $adapter = new CtripBrowserProfileDataSourceAdapter($root, 'node', static function (array $args): array {
                $outputPath = '';
                $section = '';
                foreach ($args as $arg) {
                    if (str_starts_with((string)$arg, '--output=')) {
                        $outputPath = substr((string)$arg, strlen('--output='));
                    }
                    if (str_starts_with((string)$arg, '--sections=')) {
                        $section = substr((string)$arg, strlen('--sections='));
                    }
                }

                if ($section === 'ads_pyramid') {
                    return ['success' => false, 'message' => 'Ctrip browser capture timed out.', 'stdout' => '', 'stderr' => ''];
                }
                file_put_contents($outputPath, json_encode([
                    'network_freshness' => self::READY_NETWORK_FRESHNESS,
                    'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                    'capture_gate' => ['status' => 'pass'],
                    'catalog_facts' => [['metric_key' => 'hotel_id', 'source_key' => 'masterHotelId', 'value' => '24588']],
                    'standard_rows' => [
                        [
                            'hotel_id' => '24588',
                            'hotel_name' => 'Ctrip Demo Hotel',
                            'data_date' => '2026-05-31',
                            'data_type' => 'traffic',
                            'list_exposure' => '100',
                            'source_trace_id' => 'traffic-section-row',
                        ],
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                return ['success' => true, 'message' => 'ok', 'stdout' => '', 'stderr' => ''];
            });

            $result = $adapter->fetch($this->ctripBrowserProfileSource(), [
                'interactive_browser' => false,
                'sequential_sections' => true,
                'profile_field_config' => [
                    'fields' => [
                        [
                            'id' => 'detail_visitor',
                            'field_key' => 'detail_visitor',
                            'section' => 'traffic_report',
                            'enabled' => true,
                        ],
                        [
                            'id' => 'ad_exposure',
                            'field_key' => 'ad_exposure',
                            'section' => 'ads_pyramid',
                            'enabled' => true,
                        ],
                    ],
                ],
            ]);

            self::assertSame('success', $result['status']);
            self::assertStringContainsString('Some sections failed', $result['message']);
            self::assertCount(1, $result['payload']['rows']);
            self::assertSame(['ads_pyramid'], $result['payload']['capture_module_warning']['failed_sections']);
            self::assertSame(['success', 'failed'], array_column($result['payload']['capture_module_results'], 'status'));
            self::assertSame(1, $result['payload']['sync_summary']['module_failure_count']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testCtripBrowserProfileAdapterRowsNormalizeWithTraceability(): void
    {
        $root = $this->createCtripBrowserProfileTestRoot('hotel_001');

        try {
            $adapter = new CtripBrowserProfileDataSourceAdapter($root, 'node', $this->captureRunner([
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                'capture_gate' => ['status' => 'pass'],
                'standard_rows' => [
                    [
                        'hotel_id' => '24588',
                        'hotel_name' => 'Ctrip Demo Hotel',
                        'data_date' => '2026-05-31',
                        'data_type' => 'business',
                        'amount' => '1288.50',
                        'room_nights' => '6',
                        'orders' => '4',
                        'source_trace_id' => 'trace-business-row',
                    ],
                    [
                        'hotel_id' => '24588',
                        'hotel_name' => 'Ctrip Demo Hotel',
                        'data_date' => '2026-05-31',
                        'data_type' => 'traffic',
                        'list_exposure' => '1000',
                        'detail_exposure' => '250',
                        'flow_rate' => '25%',
                        'source_trace_id' => 'trace-traffic-row',
                        'url_hash' => str_repeat('c', 64),
                    ],
                ],
            ]));
            $source = $this->ctripBrowserProfileSource();
            $result = $adapter->fetch($source, ['interactive_browser' => false]);

            self::assertSame('success', $result['status']);
            self::assertCount(2, $result['payload']['rows']);
            self::assertSame('browser_profile', $result['payload']['rows'][0]['acquisition_method']);

            $rows = (new PlatformDataSyncService())->normalizeRowsFromPayload($result['payload'], $source, 88);
            self::assertCount(2, $rows);

            $businessRow = $rows[0]['data_type'] === 'business' ? $rows[0] : $rows[1];
            $trafficRow = $rows[0]['data_type'] === 'traffic' ? $rows[0] : $rows[1];

            self::assertSame('business', $businessRow['data_type']);
            self::assertSame(1288.5, $businessRow['amount']);
            self::assertSame(6, $businessRow['quantity']);
            self::assertSame(4, $businessRow['book_order_num']);
            self::assertSame('browser_profile', $businessRow['ingestion_method']);
            self::assertSame('trace-business-row', $businessRow['source_trace_id']);

            self::assertSame('traffic', $trafficRow['data_type']);
            self::assertSame(1000, $trafficRow['list_exposure']);
            self::assertSame(250, $trafficRow['detail_exposure']);
            self::assertSame(25.0, $trafficRow['flow_rate']);
            self::assertSame('trace-traffic-row', $trafficRow['source_trace_id']);
            $trafficRaw = json_decode((string)$trafficRow['raw_data'], true);
            self::assertIsArray($trafficRaw);
            self::assertTrue($trafficRaw['platform_hotel_identifier_present'] ?? false);
            self::assertSame('hotel_id_family', $trafficRaw['platform_hotel_identifier_source'] ?? '');
            $trafficFactsByKey = array_column($trafficRaw['field_facts'] ?? [], null, 'metric_key');
            self::assertSame('$.list_exposure', $trafficFactsByKey['list_exposure']['source_path'] ?? '');
            self::assertSame('online_daily_data.list_exposure', $trafficFactsByKey['list_exposure']['storage_field'] ?? '');
            self::assertSame('online_daily_data.detail_exposure', $trafficFactsByKey['detail_exposure']['storage_field'] ?? '');
            self::assertSame('online_daily_data.flow_rate', $trafficFactsByKey['flow_rate']['storage_field'] ?? '');
            self::assertSame(
                str_repeat('c', 64),
                $trafficRaw['field_facts'][0]['capture_evidence']['source_url_hash'] ?? ''
            );
            self::assertSame(str_repeat('c', 64), $trafficRaw['source_url_hash'] ?? '');
            $trafficFactStatus = OnlineDataFieldFactService::buildStatus($trafficRow, $trafficRaw);
            self::assertGreaterThanOrEqual(
                3,
                (int)($trafficFactStatus['matching_desensitized_capture_evidence_count'] ?? 0)
            );
            self::assertGreaterThanOrEqual(
                3,
                (int)($trafficRaw['field_fact_summary']['desensitized_capture_evidence_count'] ?? 0)
            );
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testCtripScheduledProfileCaptureRejectsRequestedDateUsedAsDefaultEvidence(): void
    {
        $root = $this->createCtripBrowserProfileTestRoot('hotel_001');
        try {
            $adapter = new CtripBrowserProfileDataSourceAdapter($root, 'node', $this->captureRunner([
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                'capture_gate' => ['status' => 'pass'],
                'standard_rows' => [[
                    'hotel_id' => '24588',
                    'data_date' => '2026-08-16',
                    'date_source' => 'capture_context.default_data_date',
                    'data_type' => 'business',
                    'amount' => 100,
                    'source_trace_id' => 'default-date-is-not-proof',
                ]],
            ]));

            $result = $adapter->fetch($this->ctripBrowserProfileSource(), [
                'interactive_browser' => false,
                'data_date' => '2026-08-16',
                'require_current_run_session_probe' => true,
            ]);

            self::assertSame('failed', $result['status']);
            self::assertSame('ctrip_target_date_unverified', $result['status_code']);
            self::assertArrayNotHasKey('rows', $result['payload']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testCtripScheduledProfileCaptureRejectsAuthoritativeWrongDate(): void
    {
        $root = $this->createCtripBrowserProfileTestRoot('hotel_001');
        try {
            $adapter = new CtripBrowserProfileDataSourceAdapter($root, 'node', $this->captureRunner([
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                'capture_gate' => ['status' => 'pass'],
                'standard_rows' => [[
                    'hotel_id' => '24588',
                    'data_date' => '2026-08-15',
                    'date_source' => 'response.businessDate',
                    'data_type' => 'business',
                    'amount' => 100,
                    'source_trace_id' => 'wrong-date-response-row',
                ]],
            ]));

            $result = $adapter->fetch($this->ctripBrowserProfileSource(), [
                'interactive_browser' => false,
                'data_date' => '2026-08-16',
                'require_current_run_session_probe' => true,
            ]);

            self::assertSame('failed', $result['status']);
            self::assertSame('ctrip_target_date_mismatch', $result['status_code']);
            self::assertArrayNotHasKey('rows', $result['payload']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testCtripSequentialCaptureNeverMergesRowsWithoutFreshNetworkProof(): void
    {
        foreach ([
            'missing' => null,
            'blocked' => [
                'status' => 'blocked',
                'http_cache_disabled' => false,
                'service_worker_bypassed' => false,
                'sensitive_values_exposed' => false,
            ],
        ] as $label => $invalidFreshness) {
            foreach (['traffic_report', 'business_overview'] as $invalidSection) {
            $root = $this->createCtripBrowserProfileTestRoot('hotel_001');
            try {
                $adapter = new CtripBrowserProfileDataSourceAdapter(
                    $root,
                    'node',
                    static function (array $args) use ($invalidFreshness, $invalidSection): array {
                        $outputPath = '';
                        $section = '';
                        foreach ($args as $arg) {
                            if (str_starts_with((string)$arg, '--output=')) {
                                $outputPath = substr((string)$arg, strlen('--output='));
                            } elseif (str_starts_with((string)$arg, '--sections=')) {
                                $section = substr((string)$arg, strlen('--sections='));
                            }
                        }
                        $payload = [
                            'network_freshness' => $section === $invalidSection
                                ? $invalidFreshness
                                : self::READY_NETWORK_FRESHNESS,
                            'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                            'capture_gate' => ['status' => 'pass'],
                            'catalog_facts' => [[
                                'metric_key' => 'hotel_id',
                                'source_key' => 'masterHotelId',
                                'value' => '24588',
                            ]],
                            'standard_rows' => [[
                                'hotel_id' => '24588',
                                'hotel_name' => 'Ctrip Demo Hotel',
                                'data_date' => '2026-05-31',
                                'data_type' => $section === 'traffic_report' ? 'traffic' : 'business',
                                'amount' => 100,
                                'source_trace_id' => 'fresh-' . $section,
                            ]],
                        ];
                        if ($invalidFreshness === null && $section === $invalidSection) {
                            unset($payload['network_freshness']);
                        }
                        file_put_contents(
                            $outputPath,
                            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                        );
                        return ['success' => true, 'message' => 'ok', 'stdout' => '', 'stderr' => ''];
                    }
                );
                $source = $this->ctripBrowserProfileSource();
                $source['config']['capture_sections'] = 'business_overview,traffic_report';

                $result = $adapter->fetch($source, [
                    'interactive_browser' => false,
                    'sequential_sections' => true,
                ]);

                $case = $label . ':' . $invalidSection;
                $validSection = $invalidSection === 'traffic_report' ? 'business_overview' : 'traffic_report';
                self::assertSame('success', $result['status'], $case);
                self::assertCount(1, $result['payload']['rows'], $case);
                self::assertSame(
                    ['fresh-' . $validSection],
                    array_column($result['payload']['rows'], 'source_trace_id'),
                    $case
                );
                $expectedStatuses = $invalidSection === 'traffic_report'
                    ? ['success', 'failed']
                    : ['failed', 'success'];
                self::assertSame($expectedStatuses, array_column(
                    $result['payload']['capture_module_results'],
                    'status'
                ), $case);
                self::assertSame([$invalidSection], $result['payload']['capture_module_warning']['failed_sections'], $case);
            } finally {
                $this->removeDirectory($root);
            }
            }
        }
    }

    public function testBrowserProfileOrderRowPromotesUrlHashForReadyFieldFacts(): void
    {
        $sourceUrlHash = str_repeat('d', 64);
        $rows = (new PlatformDataSyncService())->normalizeRowsFromPayload([
            'rows' => [[
                'hotel_id' => '24588',
                'hotel_name' => 'Ctrip Demo Hotel',
                'data_date' => '2026-07-21',
                'data_type' => 'order',
                'totalAmount' => 388.5,
                'quantity' => 2,
                'orderCount' => 1,
                'source_trace_id' => 'trace-order-row',
                'source_url_hash' => $sourceUrlHash,
            ]],
        ], $this->ctripBrowserProfileSource(), 123);

        self::assertCount(1, $rows);
        self::assertSame('order', $rows[0]['data_type']);

        $raw = json_decode((string)$rows[0]['raw_data'], true);
        self::assertIsArray($raw);
        self::assertSame($sourceUrlHash, $raw['source_url_hash'] ?? '');
        self::assertSame($sourceUrlHash, $raw['capture_evidence']['source_url_hash'] ?? '');

        $status = OnlineDataFieldFactService::buildStatus($rows[0], $raw);
        self::assertSame('ready', $status['status'] ?? '');
        self::assertSame(3, $status['captured_count'] ?? 0);
        self::assertSame(3, $status['matching_desensitized_capture_evidence_count'] ?? 0);
    }

    public function testMeituanBrowserProfileAdapterSupportsOnlyMeituanBrowserProfileSources(): void
    {
        $adapter = new MeituanBrowserProfileDataSourceAdapter(sys_get_temp_dir(), 'node', static fn() => []);

        self::assertTrue($adapter->supports([
            'platform' => 'meituan',
            'ingestion_method' => 'browser_profile',
        ]));
        self::assertTrue($adapter->supports([
            'platform' => 'meituan',
            'ingestion_method' => 'profile_browser',
        ]));
        self::assertFalse($adapter->supports([
            'platform' => 'ctrip',
            'ingestion_method' => 'browser_profile',
        ]));
    }

    public function testMeituanBrowserProfileAdapterReturnsWaitingConfigWhenProfileIsMissing(): void
    {
        $root = $this->createMeituanBrowserProfileTestRoot();

        try {
            $adapter = new MeituanBrowserProfileDataSourceAdapter($root, 'node', static fn() => []);
            $result = $adapter->fetch([
                'platform' => 'meituan',
                'ingestion_method' => 'browser_profile',
                'system_hotel_id' => 7,
                'config' => [
                    'store_id' => 'store_001',
                ],
            ], ['interactive_browser' => false]);

            self::assertSame('waiting_config', $result['status']);
            self::assertStringContainsString('storage/meituan_profile_store_001', $result['message']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testMeituanBrowserProfileAdapterUsesProtectedCdpWithoutLocalProfileDirectory(): void
    {
        $root = $this->createMeituanBrowserProfileTestRoot();
        $capturedArgs = [];

        try {
            $adapter = new MeituanBrowserProfileDataSourceAdapter($root, 'node', static function (array $args) use (&$capturedArgs): array {
                $capturedArgs = $args;
                $outputPath = '';
                foreach ($args as $arg) {
                    if (str_starts_with((string)$arg, '--output=')) {
                        $outputPath = substr((string)$arg, strlen('--output='));
                    }
                }
                file_put_contents($outputPath, json_encode([
                    'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                    'capture_gate' => ['status' => 'pass'],
                    'platform_identity_validation' => [
                        'status' => 'matched',
                        'source_validation' => true,
                        'validated_identifier' => '68471',
                    ],
                    'traffic' => [[
                        'poi_id' => '68471',
                        'poi_name' => 'Meituan Demo Hotel',
                        'data_date' => '2026-07-29',
                        'date_source' => 'row',
                        'list_exposure' => 120,
                        'source_trace_id' => 'meituan-cloud-profile-cdp',
                    ]],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return ['success' => true, 'message' => 'ok', 'stdout' => '', 'stderr' => ''];
            });

            $result = $adapter->fetch($this->meituanBrowserProfileSource(), [
                'interactive_browser' => false,
                'capture_sections' => 'traffic',
                'data_date' => '2026-07-29',
                'cdp_url' => 'http://127.0.0.1:9223',
            ]);

            self::assertSame('success', $result['status']);
            self::assertContains('--cdp-url=http://127.0.0.1:9223', $capturedArgs);
            self::assertDirectoryDoesNotExist($root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'meituan_profile_store_001');
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testMeituanBrowserProfileAdapterReturnsWaitingConfigWhenLoginExpired(): void
    {
        $root = $this->createMeituanBrowserProfileTestRoot('store_001');

        try {
            $adapter = new MeituanBrowserProfileDataSourceAdapter($root, 'node', $this->captureRunner([
                'auth_status' => [
                    'ok' => false,
                    'status' => 'login_required',
                    'message' => 'Meituan login expired.',
                ],
                'capture_gate' => ['status' => 'not_run'],
            ]));
            $result = $adapter->fetch($this->meituanBrowserProfileSource(), ['interactive_browser' => false]);

            self::assertSame('waiting_config', $result['status']);
            self::assertSame('Meituan login expired.', $result['message']);
            self::assertArrayNotHasKey('rows', $result['payload']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testMeituanBrowserProfileAdapterFailsClosedWhenCurrentAuthContractIsMissing(): void
    {
        $root = $this->createMeituanBrowserProfileTestRoot('store_001');

        try {
            $adapter = new MeituanBrowserProfileDataSourceAdapter($root, 'node', $this->captureRunner([
                'capture_gate' => ['status' => 'pass'],
                'platform_identity_validation' => [
                    'schema_version' => 1,
                    'status' => 'matched',
                    'source_validation' => true,
                    'validated_identifier' => 'store_001',
                ],
            ]));
            $result = $adapter->fetch($this->meituanBrowserProfileSource(), [
                'interactive_browser' => false,
                'data_date' => '2026-07-11',
            ]);

            self::assertSame('waiting_config', $result['status']);
            self::assertStringContainsString('login session is not ready', $result['message']);
            self::assertArrayNotHasKey('rows', $result['payload']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testMeituanAdsLoginFailureBlocksOnlyTheAdsModule(): void
    {
        $root = $this->createMeituanBrowserProfileTestRoot('store_001');

        try {
            $adapter = new MeituanBrowserProfileDataSourceAdapter($root, 'node', $this->captureRunner([
                'auth_status' => [
                    'ok' => false,
                    'status' => 'login_required',
                    'message' => 'Meituan login expired.',
                ],
                'capture_gate' => ['status' => 'not_run'],
            ]));
            $result = $adapter->fetch($this->meituanBrowserProfileSource(), [
                'interactive_browser' => false,
                'capture_sections' => 'ads',
            ]);

            self::assertSame('waiting_config', $result['status']);
            self::assertSame('profile_session_unverified', $result['status_code']);
            self::assertSame('ads', $result['payload']['module_status']['module']);
            self::assertSame('blocked', $result['payload']['module_status']['status']);
            $service = new PlatformDataSyncService();
            $safeMessage = new \ReflectionMethod($service, 'safeSyncTaskMessage');
            $safeMessage->setAccessible(true);
            self::assertSame(
                'profile_session_unverified',
                $safeMessage->invoke($service, $result['status'], $result['message'])
            );
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testMeituanBrowserProfileAdapterRejectsUnverifiedOrWrongMerchantIdentity(): void
    {
        $root = $this->createMeituanBrowserProfileTestRoot('store_001');

        try {
            $basePayload = [
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                'capture_gate' => ['status' => 'pass'],
                'traffic' => [[
                    'poi_id' => '68471',
                    'data_date' => '2026-07-11',
                    'date_source' => 'row',
                    'list_exposure' => 100,
                ]],
            ];

            $unverified = new MeituanBrowserProfileDataSourceAdapter($root, 'node', $this->captureRunner([
                ...$basePayload,
                'platform_identity_validation' => [],
            ]));
            $unverifiedResult = $unverified->fetch($this->meituanBrowserProfileSource(), [
                'interactive_browser' => false,
                'data_date' => '2026-07-11',
            ]);
            self::assertSame('failed', $unverifiedResult['status']);
            self::assertSame('meituan_platform_identity_unverified', $unverifiedResult['status_code']);

            $wrongMerchant = new MeituanBrowserProfileDataSourceAdapter($root, 'node', $this->captureRunner([
                ...$basePayload,
                'platform_identity_validation' => [
                    'status' => 'matched',
                    'source_validation' => true,
                    'validated_identifier' => 'wrong-poi',
                ],
            ]));
            $wrongMerchantResult = $wrongMerchant->fetch($this->meituanBrowserProfileSource(), [
                'interactive_browser' => false,
                'data_date' => '2026-07-11',
            ]);
            self::assertSame('failed', $wrongMerchantResult['status']);
            self::assertSame('meituan_platform_identity_mismatch', $wrongMerchantResult['status_code']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testMeituanAdsAgreementPageIsNotApplicableInsteadOfAWholeSourceFailure(): void
    {
        $root = $this->createMeituanBrowserProfileTestRoot('store_001');

        try {
            $adapter = new MeituanBrowserProfileDataSourceAdapter($root, 'node', $this->captureRunner([
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                'capture_gate' => [
                    'status' => 'fail',
                    'failed_check_ids' => ['business_rows_present'],
                ],
                'pages' => [[
                    'name' => 'ads',
                    'url' => 'https://ebmidas.dianping.com/app/peon-promo-finance/promopoiid/-1/html/online-sign.html',
                    'ok' => true,
                ]],
                'responses' => [],
                'ads' => [],
            ]));

            $result = $adapter->fetch($this->meituanBrowserProfileSource(), [
                'interactive_browser' => false,
                'capture_sections' => 'ads',
                'data_date' => '2026-07-11',
            ]);

            self::assertSame('not_applicable', $result['status']);
            self::assertSame('ads_service_not_opened', $result['status_code']);
            self::assertSame('ads_service_not_opened', $result['message']);
            self::assertSame('ads', $result['payload']['module_status']['module']);
            self::assertSame('not_applicable', $result['payload']['module_status']['status']);

            $service = new PlatformDataSyncService();
            $method = new \ReflectionMethod($service, 'shouldPreserveSourceStateForModuleResult');
            $method->setAccessible(true);
            self::assertTrue($method->invoke($service, $result['status'], $result['payload']));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testMeituanBrowserProfileAdapterPassesTargetDateToCaptureScript(): void
    {
        $root = $this->createMeituanBrowserProfileTestRoot('store_001');
        $capturedArgs = [];

        try {
            $adapter = new MeituanBrowserProfileDataSourceAdapter($root, 'node', static function (array $args) use (&$capturedArgs): array {
                $capturedArgs = $args;
                $outputPath = '';
                foreach ($args as $arg) {
                    if (str_starts_with((string)$arg, '--output=')) {
                        $outputPath = substr((string)$arg, strlen('--output='));
                        break;
                    }
                }
                if ($outputPath === '') {
                    return ['success' => false, 'message' => 'missing output path', 'stdout' => '', 'stderr' => ''];
                }
                file_put_contents($outputPath, json_encode([
                    'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                    'platform_identity_validation' => [
                        'status' => 'matched',
                        'source_validation' => true,
                        'validated_identifier' => '68471',
                    ],
                    'capture_gate' => ['status' => 'pass'],
                    'traffic' => [
                        [
                            'poi_name' => 'Meituan Demo Hotel',
                            'data_date' => '2026-07-04',
                            'date_source' => 'row',
                            'list_exposure' => '1200',
                            'detail_exposure' => '240',
                            'flow_rate' => '20%',
                            'source_trace_id' => 'mt-target-date-row',
                        ],
                    ],
                    'orders' => [],
                    'ads' => [],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                return ['success' => true, 'message' => 'ok', 'stdout' => '', 'stderr' => ''];
            });

            $result = $adapter->fetch($this->meituanBrowserProfileSource(), [
                'interactive_browser' => false,
                'capture_sections' => 'traffic',
                'data_date' => '2026-07-04',
            ]);

            self::assertSame('success', $result['status']);
            self::assertContains('--data-date=2026-07-04', $capturedArgs);
            self::assertSame('2026-07-04', $result['payload']['data_source_capture']['data_date']);
            self::assertSame('2026-07-04', $result['payload']['rows'][0]['data_date']);
            self::assertSame('68471', $result['payload']['rows'][0]['poi_id']);
            self::assertArrayNotHasKey('store_id', $result['payload']['data_source_capture']);
            self::assertArrayNotHasKey('poi_id', $result['payload']['data_source_capture']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testMeituanBrowserProfileAdapterRejectsRowsOutsideRequestedTargetDate(): void
    {
        $root = $this->createMeituanBrowserProfileTestRoot('store_001');

        try {
            $adapter = new MeituanBrowserProfileDataSourceAdapter($root, 'node', $this->captureRunner([
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                'capture_gate' => ['status' => 'pass'],
                'traffic' => [[
                    'poi_id' => '68471',
                    'poi_name' => 'Meituan Demo Hotel',
                    'data_date' => '2026-07-10',
                    'list_exposure' => 1200,
                    'detail_exposure' => 240,
                ]],
                'orders' => [],
                'ads' => [],
            ]));

            $result = $adapter->fetch($this->meituanBrowserProfileSource(), [
                'interactive_browser' => false,
                'capture_sections' => 'traffic',
                'data_date' => '2026-07-11',
            ]);

            self::assertSame('failed', $result['status']);
            self::assertSame('meituan_target_date_mismatch', $result['status_code']);
            self::assertArrayNotHasKey('rows', $result['payload']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testMeituanBrowserProfileAdapterAllowsFutureForecastDate(): void
    {
        $root = $this->createMeituanBrowserProfileTestRoot('store_001');

        try {
            $adapter = new MeituanBrowserProfileDataSourceAdapter($root, 'node', $this->captureRunner([
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                'capture_gate' => ['status' => 'pass'],
                'trafficForecast' => [[
                    'poi_id' => '68471',
                    'poi_name' => 'Meituan Demo Hotel',
                    'data_date' => '2026-07-20',
                    'forecast_value' => 120,
                ]],
            ]));

            $result = $adapter->fetch($this->meituanBrowserProfileSource(), [
                'interactive_browser' => false,
                'capture_sections' => 'traffic',
                'data_date' => '2026-07-11',
            ]);

            self::assertSame('success', $result['status']);
            self::assertSame('traffic_forecast', $result['payload']['rows'][0]['data_type']);
            self::assertSame('2026-07-20', $result['payload']['rows'][0]['data_date']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testMeituanBrowserProfileAdapterDropsUnverifiedForecastButKeepsVerifiedCoreRows(): void
    {
        $root = $this->createMeituanBrowserProfileTestRoot('store_001');

        try {
            $adapter = new MeituanBrowserProfileDataSourceAdapter($root, 'node', $this->captureRunner([
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                'capture_gate' => ['status' => 'pass'],
                'traffic' => [[
                    'poi_id' => '68471',
                    'dataDate' => '2026-07-18',
                    'date_source' => 'request.query.startDate',
                    'list_exposure' => 1200,
                ]],
                'trafficForecast' => [[
                    'poi_id' => '68471',
                    'dataDate' => '2026-07-18',
                    'date_source' => 'capture_context.default_data_date',
                    'forecast_type' => '1',
                ]],
            ]));

            $result = $adapter->fetch($this->meituanBrowserProfileSource(), [
                'interactive_browser' => false,
                'capture_sections' => 'traffic',
                'data_date' => '2026-07-18',
            ]);

            self::assertSame('success', $result['status']);
            self::assertSame(['traffic'], array_column($result['payload']['rows'], 'data_type'));
            self::assertSame(1, $result['payload']['sync_summary']['dropped_unverified_supplemental_count']);
            self::assertSame('unverified_supplemental_rows_dropped', $result['payload']['collection_warnings'][0]['status_code']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testMeituanBrowserProfileAdapterNeverInjectsStoredCookies(): void
    {
        $root = $this->createMeituanBrowserProfileTestRoot('store_001');
        $capturedArgs = [];

        try {
            $adapter = new MeituanBrowserProfileDataSourceAdapter($root, 'node', static function (array $args) use (&$capturedArgs): array {
                $capturedArgs = $args;
                $outputPath = '';
                foreach ($args as $arg) {
                    if (str_starts_with((string)$arg, '--output=')) {
                        $outputPath = substr((string)$arg, strlen('--output='));
                        break;
                    }
                }
                if ($outputPath !== '') {
                    file_put_contents($outputPath, json_encode([
                        'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                        'platform_identity_validation' => [
                            'status' => 'matched',
                            'source_validation' => true,
                            'validated_identifier' => '68471',
                        ],
                        'capture_gate' => ['status' => 'pass'],
                        'traffic' => [[
                            'poi_id' => '68471',
                            'poi_name' => 'Meituan Demo Hotel',
                            'data_date' => '2026-07-04',
                            'date_source' => 'row',
                            'list_exposure' => '1200',
                            'detail_exposure' => '240',
                            'flow_rate' => '20%',
                            'source_trace_id' => 'mt-profile-cookie-skip-row',
                        ]],
                        'orders' => [],
                        'ads' => [],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
                return ['success' => true, 'message' => 'ok', 'stdout' => '', 'stderr' => ''];
            });
            $source = $this->meituanBrowserProfileSource();
            $source['secret'] = ['cookies' => 'legacy_session=must_not_be_injected'];
            $result = $adapter->fetch($source, [
                'interactive_browser' => false,
                'capture_sections' => 'traffic',
                'data_date' => '2026-07-04',
            ]);

            self::assertSame('success', $result['status']);
            self::assertSame([], array_values(array_filter(
                $capturedArgs,
                static fn($arg): bool => str_starts_with((string)$arg, '--cookies-file=')
            )));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testMeituanBrowserProfileAdapterExpandsFullSectionsAndMapsRealtimeReviewRows(): void
    {
        $root = $this->createMeituanBrowserProfileTestRoot('store_001');
        $capturedArgs = [];

        try {
            $adapter = new MeituanBrowserProfileDataSourceAdapter($root, 'node', static function (array $args) use (&$capturedArgs): array {
                $capturedArgs = $args;
                $outputPath = '';
                foreach ($args as $arg) {
                    if (str_starts_with((string)$arg, '--output=')) {
                        $outputPath = substr((string)$arg, strlen('--output='));
                        break;
                    }
                }
                if ($outputPath === '') {
                    return ['success' => false, 'message' => 'missing output path', 'stdout' => '', 'stderr' => ''];
                }
                file_put_contents($outputPath, json_encode([
                    'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                    'platform_identity_validation' => [
                        'status' => 'matched',
                        'source_validation' => true,
                        'validated_identifier' => '68471',
                    ],
                    'capture_gate' => ['status' => 'pass'],
                    'traffic' => [[
                        'poi_id' => '68471',
                        'poi_name' => 'Meituan Demo Hotel',
                        'data_date' => '2026-07-08',
                        'mt_exposure' => 1200,
                        'mt_intention_uv' => 180,
                        'mt_pay_orders' => 12,
                        'mt_pay_rooms' => 9,
                    ]],
                    'ads' => [[
                        'poi_id' => '68471',
                        'poi_name' => 'Meituan Demo Hotel',
                        'data_date' => '2026-07-08',
                        'spend' => 88.5,
                        'orderAmount' => 300,
                        'orderNum' => 2,
                    ]],
                    'reviews' => [[
                        'poi_id' => '68471',
                        'poi_name' => 'Meituan Demo Hotel',
                        'data_date' => '2026-07-08',
                        'score' => 4.6,
                        'comment_count' => 20,
                    ]],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                return ['success' => true, 'message' => 'ok', 'stdout' => '', 'stderr' => ''];
            });

            $source = $this->meituanBrowserProfileSource();
            $source['config']['ads_url'] = 'https://ads.example.test/full';
            $result = $adapter->fetch($source, [
                'interactive_browser' => false,
                'capture_sections' => 'full',
                'data_period' => 'realtime_snapshot',
                'snapshot_time' => '2026-07-08 13:15:00',
                'data_date' => '2026-07-08',
            ]);

            self::assertSame('success', $result['status']);
            self::assertContains('--sections=traffic,orders,ads,reviews', $capturedArgs);
            self::assertContains('--ads-url=https://ads.example.test/full', $capturedArgs);
            self::assertContains('--data-period=realtime_snapshot', $capturedArgs);
            self::assertContains('--snapshot-time=2026-07-08 13:15:00', $capturedArgs);
            self::assertSame(1, $result['payload']['sync_summary']['review_count']);
            self::assertSame('realtime_snapshot', $result['payload']['data_period']);

            $rows = (new PlatformDataSyncService())->normalizeRowsFromPayload($result['payload'], $source, 89);
            self::assertCount(3, $rows);

            $trafficRow = array_values(array_filter($rows, static fn(array $row): bool => $row['data_type'] === 'traffic'))[0] ?? null;
            $adRow = array_values(array_filter($rows, static fn(array $row): bool => $row['data_type'] === 'advertising'))[0] ?? null;
            $reviewRow = array_values(array_filter($rows, static fn(array $row): bool => $row['data_type'] === 'review'))[0] ?? null;

            self::assertIsArray($trafficRow);
            self::assertSame(1200, $trafficRow['list_exposure']);
            self::assertSame(180, $trafficRow['detail_exposure']);
            self::assertSame(12, $trafficRow['order_submit_num']);
            self::assertSame(9, $trafficRow['quantity']);
            self::assertSame('realtime_snapshot', $trafficRow['data_period']);
            $trafficRaw = json_decode((string)$trafficRow['raw_data'], true);
            self::assertIsArray($trafficRaw);
            $trafficFactsByKey = array_column($trafficRaw['field_facts'] ?? [], null, 'metric_key');
            self::assertSame('online_daily_data.list_exposure', $trafficFactsByKey['mt_exposure']['storage_field'] ?? '');
            self::assertSame('online_daily_data.order_submit_num', $trafficFactsByKey['mt_pay_orders']['storage_field'] ?? '');
            self::assertSame('online_daily_data.quantity', $trafficFactsByKey['mt_pay_rooms']['storage_field'] ?? '');

            self::assertIsArray($adRow);
            self::assertSame(88.5, $adRow['amount']);
            self::assertSame(2, $adRow['book_order_num']);
            $adRaw = json_decode((string)$adRow['raw_data'], true);
            self::assertIsArray($adRaw);
            $adFactsByKey = array_column($adRaw['field_facts'] ?? [], null, 'metric_key');
            self::assertSame('captured', $adFactsByKey['advertising_spend']['status'] ?? '');
            self::assertSame('online_daily_data.amount', $adFactsByKey['advertising_spend']['storage_field'] ?? '');
            self::assertSame('captured', $adFactsByKey['advertising_order_count']['status'] ?? '');
            self::assertSame('online_daily_data.book_order_num', $adFactsByKey['advertising_order_count']['storage_field'] ?? '');
            self::assertIsArray($reviewRow);
            self::assertSame(4.6, $reviewRow['comment_score']);
            self::assertSame(20, $reviewRow['quantity']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testMeituanBrowserProfileAdapterFailsWhenNoBusinessRowsAreParsed(): void
    {
        $root = $this->createMeituanBrowserProfileTestRoot('store_001');

        try {
            $adapter = new MeituanBrowserProfileDataSourceAdapter($root, 'node', $this->captureRunner([
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                'capture_gate' => ['status' => 'pass'],
                'traffic' => [],
                'orders' => [],
                'ads' => [],
            ]));
            $result = $adapter->fetch($this->meituanBrowserProfileSource(), ['interactive_browser' => false]);

            self::assertSame('failed', $result['status']);
            self::assertStringContainsString('no business rows', $result['message']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testMeituanBrowserProfileAdapterRowsNormalizeWithTraceability(): void
    {
        $root = $this->createMeituanBrowserProfileTestRoot('store_001');

        try {
            $adapter = new MeituanBrowserProfileDataSourceAdapter($root, 'node', $this->captureRunner([
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                'capture_gate' => ['status' => 'pass'],
                'traffic' => [
                    [
                        'poi_id' => '68471',
                        'poi_name' => 'Meituan Demo Hotel',
                        'data_date' => '2026-05-31',
                        'list_exposure' => '900',
                        'detail_exposure' => '180',
                        'flow_rate' => '20%',
                        'source_trace_id' => 'mt-traffic-row',
                        'url_hash' => str_repeat('d', 64),
                    ],
                ],
                'orders' => [
                    [
                        'poi_id' => '68471',
                        'poi_name' => 'Meituan Demo Hotel',
                        'data_date' => '2026-05-31',
                        'amount' => '988.00',
                        'room_nights' => '5',
                        'orders' => '3',
                        'source_trace_id' => 'mt-order-row',
                    ],
                ],
            ]));
            $source = $this->meituanBrowserProfileSource();
            $result = $adapter->fetch($source, [
                'interactive_browser' => false,
                'data_date' => '2026-05-31',
            ]);

            self::assertSame('success', $result['status']);
            self::assertCount(2, $result['payload']['rows']);
            self::assertSame('browser_profile', $result['payload']['rows'][0]['acquisition_method']);

            $rows = (new PlatformDataSyncService())->normalizeRowsFromPayload($result['payload'], $source, 89);
            self::assertCount(2, $rows);

            $trafficRow = $rows[0]['data_type'] === 'traffic' ? $rows[0] : $rows[1];
            $orderRow = $rows[0]['data_type'] === 'order' ? $rows[0] : $rows[1];

            self::assertSame('traffic', $trafficRow['data_type']);
            self::assertSame('meituan', $trafficRow['source']);
            self::assertSame('self', $trafficRow['compare_type']);
            self::assertSame(900, $trafficRow['list_exposure']);
            self::assertSame(180, $trafficRow['detail_exposure']);
            self::assertSame(20.0, $trafficRow['flow_rate']);
            self::assertSame('mt-traffic-row', $trafficRow['source_trace_id']);
            $trafficRaw = json_decode((string)$trafficRow['raw_data'], true);
            self::assertIsArray($trafficRaw);
            self::assertTrue($trafficRaw['platform_hotel_identifier_present'] ?? false);
            self::assertSame('poi_id_family', $trafficRaw['platform_hotel_identifier_source'] ?? '');
            $trafficFactsByKey = array_column($trafficRaw['field_facts'] ?? [], null, 'metric_key');
            self::assertSame('$.list_exposure', $trafficFactsByKey['list_exposure']['source_path'] ?? '');
            self::assertSame('online_daily_data.list_exposure', $trafficFactsByKey['list_exposure']['storage_field'] ?? '');
            self::assertSame('online_daily_data.detail_exposure', $trafficFactsByKey['detail_exposure']['storage_field'] ?? '');
            self::assertSame('online_daily_data.flow_rate', $trafficFactsByKey['flow_rate']['storage_field'] ?? '');
            self::assertSame(
                str_repeat('d', 64),
                $trafficRaw['field_facts'][0]['capture_evidence']['source_url_hash'] ?? ''
            );
            self::assertGreaterThanOrEqual(
                3,
                (int)($trafficRaw['field_fact_summary']['desensitized_capture_evidence_count'] ?? 0)
            );

            self::assertSame('order', $orderRow['data_type']);
            self::assertSame('self', $orderRow['compare_type']);
            self::assertSame(988.0, $orderRow['amount']);
            self::assertSame(5, $orderRow['quantity']);
            self::assertSame(3, $orderRow['book_order_num']);
            self::assertSame('mt-order-row', $orderRow['source_trace_id']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testMeituanMyHotelFunnelAliasesPersistAsCoreTrafficFacts(): void
    {
        $source = $this->meituanBrowserProfileSource();
        $rows = (new PlatformDataSyncService())->normalizeRowsFromPayload([
            'rows' => [[
                'poi_id' => '68471',
                'poi_name' => 'Meituan Demo Hotel',
                'data_date' => '2026-07-18',
                'data_type' => 'traffic',
                'exposureUV' => 81,
                'intentionUV' => 14,
                'payOrderCnt' => 2,
                'intentionPerExposure' => '17.28%',
                'payOrderPerIntention' => '14.29%',
                '_observed_traffic_metric_keys' => [
                    'list_exposure',
                    'detail_exposure',
                    'flow_rate',
                ],
                '_source_path' => 'data.myHotel',
                'source_trace_id' => 'meituan:flow-analysis-trace',
                'source_url_hash' => str_repeat('f', 64),
            ]],
        ], $source, 90);

        self::assertCount(1, $rows);
        self::assertSame('traffic', $rows[0]['data_type']);
        self::assertSame(81, $rows[0]['list_exposure']);
        self::assertSame(14, $rows[0]['detail_exposure']);
        self::assertSame(2, $rows[0]['order_submit_num']);
        self::assertSame(17.28, $rows[0]['flow_rate']);
        self::assertNull($rows[0]['order_filling_num']);

        $raw = json_decode((string)$rows[0]['raw_data'], true);
        self::assertIsArray($raw);
        $facts = array_column($raw['field_facts'] ?? [], null, 'metric_key');
        self::assertSame('data.myHotel.exposureUV', $facts['list_exposure']['source_path'] ?? '');
        self::assertSame('data.myHotel.intentionUV', $facts['detail_exposure']['source_path'] ?? '');
        self::assertSame('data.myHotel.intentionPerExposure', $facts['flow_rate']['source_path'] ?? '');
        self::assertSame('data.myHotel.payOrderPerIntention', $facts['browse_to_pay_rate']['source_path'] ?? '');
        self::assertSame('raw_data.browse_pay_rate', $facts['browse_to_pay_rate']['storage_field'] ?? '');
        self::assertSame('data.myHotel.payOrderCnt', $facts['order_submit_num']['source_path'] ?? '');
        self::assertSame('missing', $facts['order_filling_num']['status'] ?? '');
        self::assertSame([
            'list_exposure',
            'detail_exposure',
            'flow_rate',
        ], $raw['row']['_observed_traffic_metric_keys'] ?? null);
    }

    public function testMeituanPhpNormalizationDoesNotInventObservedTrafficMetricMarker(): void
    {
        $rows = (new PlatformDataSyncService())->normalizeRowsFromPayload([
            'rows' => [[
                'poi_id' => '68471',
                'data_date' => '2026-07-18',
                'data_type' => 'traffic',
                'exposureUV' => 81,
                'intentionUV' => 14,
                'payOrderPerIntention' => '14.29%',
            ]],
        ], $this->meituanBrowserProfileSource(), 90);

        self::assertCount(1, $rows);
        self::assertSame(17.28, $rows[0]['flow_rate']);
        $raw = json_decode((string)$rows[0]['raw_data'], true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('_observed_traffic_metric_keys', $raw['row'] ?? []);
    }

    public function testMeituanBrowserProfileAdapterMapsUnifiedResourcePayloads(): void
    {
        $root = $this->createMeituanBrowserProfileTestRoot('store_001');

        try {
            $adapter = new MeituanBrowserProfileDataSourceAdapter($root, 'node', $this->captureRunner([
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                'capture_gate' => ['status' => 'pass'],
                'businessData' => [
                    [
                        'poi_id' => '68471',
                        'poi_name' => 'Meituan Demo Hotel',
                        'data_date' => '2026-06-06',
                        'amount' => '1288.00',
                        'room_nights' => '6',
                        'orders' => '4',
                    ],
                ],
                'peerRank' => [
                    [
                        'poi_id' => '68471',
                        'poi_name' => 'Meituan Demo Hotel',
                        'data_date' => '2026-06-06',
                        'rank' => '2',
                        'rank_type' => 'P_RZ',
                        'vip_status' => true,
                    ],
                ],
                'searchKeywords' => [
                    [
                        'poi_id' => '68471',
                        'poi_name' => 'Meituan Demo Hotel',
                        'data_date' => '2026-06-06',
                        'keyword' => 'nearby hotel',
                        'exposure_count' => '300',
                    ],
                ],
                'order_flow' => [
                    [
                        'poi_id' => '68471',
                        'poi_name' => 'Meituan Demo Hotel',
                        'dataDate' => '2026-06-06',
                        'date_source' => 'request.query.endDate',
                        'order_flow_row_type' => 'summary',
                        'order_flow_direction' => 'loss',
                        'order_flow_period' => 'last_30_days',
                        'dimension' => 'order_flow:last_30_days:loss:summary',
                        'order_count' => '12',
                        'room_nights' => '18',
                        'amount' => '3988.00',
                    ],
                ],
                'roomTypes' => [
                    [
                        'poi_id' => '68471',
                        'poi_name' => 'Meituan Demo Hotel',
                        'data_date' => '2026-06-06',
                        'room_type_name' => 'Business King',
                        'price' => '268',
                    ],
                ],
            ]));
            $source = $this->meituanBrowserProfileSource();
            $result = $adapter->fetch($source, [
                'interactive_browser' => false,
                'capture_sections' => 'businessData,peerRank,order_flow,searchKeywords,roomTypes',
                'data_date' => '2026-06-06',
            ]);

            self::assertSame('success', $result['status']);
            self::assertCount(5, $result['payload']['rows']);
            self::assertSame(1, $result['payload']['sync_summary']['peer_rank_count']);
            self::assertSame(1, $result['payload']['sync_summary']['order_flow_count']);
            self::assertSame(1, $result['payload']['sync_summary']['search_keyword_count']);
            self::assertSame(1, $result['payload']['sync_summary']['room_type_count']);

            $rows = (new PlatformDataSyncService())->normalizeRowsFromPayload($result['payload'], $source, 90);
            $types = array_values(array_unique(array_column($rows, 'data_type')));
            sort($types);

            self::assertSame(['business', 'order_flow', 'peer_rank', 'room_type', 'search_keyword'], $types);
            $orderFlowRow = array_values(array_filter($rows, static fn(array $row): bool => $row['data_type'] === 'order_flow'))[0] ?? null;
            self::assertIsArray($orderFlowRow);
            self::assertSame(3988.0, $orderFlowRow['amount']);
            self::assertSame(18, $orderFlowRow['quantity']);
            self::assertSame(12, $orderFlowRow['book_order_num']);
            self::assertSame('order_flow:last_30_days:loss:summary', $orderFlowRow['dimension']);
            $orderFlowRaw = json_decode((string)$orderFlowRow['raw_data'], true);
            self::assertIsArray($orderFlowRaw);
            $orderFlowFactsByKey = array_column($orderFlowRaw['field_facts'] ?? [], null, 'metric_key');
            self::assertSame('captured', $orderFlowFactsByKey['order_flow_direction']['status'] ?? '');
            self::assertSame('raw_data.order_flow_direction', $orderFlowFactsByKey['order_flow_direction']['storage_field'] ?? '');
            self::assertSame('captured', $orderFlowFactsByKey['order_flow_order_count']['status'] ?? '');
            self::assertSame('raw_data.order_count', $orderFlowFactsByKey['order_flow_order_count']['storage_field'] ?? '');
            $peerRow = array_values(array_filter($rows, static fn(array $row): bool => $row['data_type'] === 'peer_rank'))[0] ?? null;
            self::assertIsArray($peerRow);
            self::assertNull($peerRow['data_value']);
            self::assertSame('P_RZ', $peerRow['compare_type']);
            $peerRaw = json_decode((string)$peerRow['raw_data'], true);
            self::assertIsArray($peerRaw);
            $peerFactsByKey = array_column($peerRaw['field_facts'] ?? [], null, 'metric_key');
            self::assertSame('raw_data.rank', $peerFactsByKey['peer_rank_value']['storage_field'] ?? '');
            self::assertSame('$.rank', $peerFactsByKey['peer_rank_value']['source_path'] ?? '');
            self::assertTrue($peerFactsByKey['peer_rank_compare_type']['stored_value_present'] ?? false);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testMeituanTemporalCaptureKeepsActualPayloadTypesAndPassesInternalMode(): void
    {
        $root = $this->createMeituanBrowserProfileTestRoot('store_001');
        $capturedArgs = [];
        try {
            $adapter = new MeituanBrowserProfileDataSourceAdapter(
                $root,
                'node',
                static function (array $args) use (&$capturedArgs): array {
                    $capturedArgs = $args;
                    $output = '';
                    foreach ($args as $arg) {
                        if (str_starts_with((string)$arg, '--output=')) {
                            $output = substr((string)$arg, strlen('--output='));
                        }
                    }
                    file_put_contents($output, json_encode([
                        'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                        'capture_gate' => ['status' => 'pass'],
                        'platform_identity_validation' => [
                            'status' => 'matched',
                            'source_validation' => true,
                            'validated_identifier' => '68471',
                        ],
                        'businessData' => [[
                            'poi_id' => '68471',
                            'data_date' => '2026-07-29',
                            'date_source' => 'page.business_period_selection.readback',
                            'sales_amount' => 100,
                        ]],
                        'peerRank' => [[
                            'poi_id' => '68471',
                            'data_date' => '2026-07-29',
                            'date_source' => 'row.dataDate',
                            'rank' => 2,
                        ]],
                        'traffic' => [[
                            'poi_id' => '68471',
                            'data_date' => '2026-07-29',
                            'date_source' => 'page.traffic_period_selection.readback',
                            'listExposure' => 10,
                        ]],
                    ], JSON_UNESCAPED_UNICODE));
                    return ['success' => true, 'message' => 'ok', 'stdout' => '', 'stderr' => ''];
                }
            );
            $result = $adapter->fetch($this->meituanBrowserProfileSource(), [
                'capture_sections' => 'traffic',
                'capture_mode' => 'temporal_summary',
                'temporal_scope' => 'today_future',
                'data_date' => '2026-07-29',
            ]);

            self::assertSame('success', $result['status']);
            $types = array_values(array_unique(array_column($result['payload']['rows'], 'data_type')));
            sort($types);
            self::assertSame(['business', 'peer_rank', 'traffic'], $types);
            self::assertContains('--capture-mode=temporal_summary', $capturedArgs);
            self::assertContains('--temporal-scope=today_future', $capturedArgs);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testSyncTaskCollectionQualitySnapshotRejectsHistoricalBrowserProfileEvidence(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'buildSyncTaskCollectionQualitySnapshot');
        $method->setAccessible(true);

        $quality = $method->invoke($service, 'success', [
            'id' => 91,
            'platform' => 'ctrip',
            'system_hotel_id' => 58,
            'ingestion_method' => 'browser_profile',
            'config' => [
                'ota_hotel_id' => 'ctrip-hotel-58',
                'profile_id' => 'profile-58',
                'manual_login_state_verified' => true,
                'profile_status' => 'logged_in',
                'last_login_verified_at' => '2026-07-10 08:00:00',
            ],
        ], [
            'target_date' => '2026-07-09',
            'p0_status' => 'ready',
            'target_date_rows' => 2,
            'target_date_traffic_rows' => 1,
            'field_fact_status' => 'ready',
            'missing_inputs' => [],
            'adapter_message' => 'token=must-not-be-persisted-in-quality-snapshot',
        ], 2, 2, '2026-07-10 08:01:00');

        self::assertSame('unverified', $quality['primary_quality_state']);
        self::assertContains('platform_session_not_verified', $quality['quality_flags']);
        self::assertSame('ota_channel', $quality['metric_scope']);
        self::assertSame('sync_task', $quality['evidence_scope']);
        self::assertSame('2026-07-09', $quality['target_date']);
        self::assertSame('2026-07-09', $quality['data_as_of']);
        self::assertSame(2, $quality['evidence']['saved_count']);
        self::assertArrayNotHasKey('adapter_message', $quality['evidence']);
        self::assertStringNotContainsString('token=', (string)json_encode($quality, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function testSyncTaskCollectionQualitySnapshotKeepsPartialFailureAndManualImportHonest(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'buildSyncTaskCollectionQualitySnapshot');
        $method->setAccessible(true);
        $verifiedSource = [
            'id' => 92,
            'platform' => 'meituan',
            'system_hotel_id' => 58,
            'ingestion_method' => 'browser_profile',
            'config' => [
                'store_id' => 'meituan-store-58',
                'profile_id' => 'profile-58',
                'manual_login_state_verified' => true,
                'profile_status' => 'logged_in',
                'last_login_verified_at' => '2026-07-10 08:00:00',
            ],
        ];

        $partial = $method->invoke($service, 'success', $verifiedSource, [
            'target_date' => '2026-07-09',
            'p0_status' => 'ready',
            'target_date_rows' => 2,
            'target_date_traffic_rows' => 1,
            'field_fact_status' => 'partial',
            'missing_inputs' => [],
        ], 2, 2, '2026-07-10 08:01:00');
        self::assertSame('unverified', $partial['primary_quality_state']);
        self::assertContains('platform_session_not_verified', $partial['quality_flags']);

        $failed = $method->invoke($service, 'failed', $verifiedSource, [
            'target_date' => '2026-07-09',
            'p0_status' => 'blocked',
            'target_date_rows' => 0,
            'target_date_traffic_rows' => 0,
            'field_fact_status' => 'not_loaded',
            'missing_inputs' => ['target_date_traffic_rows'],
        ], 0, 0, '2026-07-10 08:01:00');
        self::assertSame('collection_failed', $failed['primary_quality_state']);
        self::assertContains('task_status_failed', $failed['quality_flags']);

        $manualImport = $method->invoke($service, 'success', [
            'id' => 93,
            'platform' => 'ctrip',
            'system_hotel_id' => 58,
            'ingestion_method' => 'manual',
            'config' => ['hotel_id' => 'ctrip-hotel-58'],
        ], [
            'target_date' => '2026-07-09',
            'p0_status' => 'not_required',
            'target_date_rows' => 1,
            'target_date_traffic_rows' => 1,
            'field_fact_status' => 'ready',
            'missing_inputs' => [],
        ], 1, 1, '2026-07-10 08:01:00');
        self::assertSame('unverified', $manualImport['primary_quality_state']);
        self::assertContains('manual_import_provenance_unverified', $manualImport['quality_flags']);
    }

    public function testNormalizedPersistenceReceiptAndValueReadbackStayTruthful(): void
    {
        $service = new PlatformNormalizedRowPersistenceService();
        $receiptMethod = new \ReflectionMethod($service, 'normalizedRowsRollbackReceipt');
        $receiptMethod->setAccessible(true);
        $matchMethod = new \ReflectionMethod($service, 'normalizedStoredValueMatches');
        $matchMethod->setAccessible(true);
        $identityMethod = new \ReflectionMethod($service, 'normalizedRowIdentityKey');
        $identityMethod->setAccessible(true);

        $receipt = $receiptMethod->invoke($service, 2, 'readback_mismatch', 'raw_data');
        self::assertSame(2, $receipt['attempted_count']);
        self::assertSame(0, $receipt['saved_count']);
        self::assertFalse($receipt['readback_verified']);
        self::assertTrue($receipt['rolled_back']);
        self::assertSame('readback_mismatch', $receipt['failure_reason']);
        self::assertSame('raw_data', $receipt['mismatch_field']);

        self::assertTrue($matchMethod->invoke($service, '123.500', 123.5));
        self::assertFalse($matchMethod->invoke($service, '120.000', 123.5));
        self::assertTrue($matchMethod->invoke($service, '4.9', 4.85, 'comment_score'));
        self::assertFalse($matchMethod->invoke($service, '4.8', 4.85, 'comment_score'));
        self::assertFalse($matchMethod->invoke($service, '4.9', 4.85, 'data_value'));
        self::assertTrue($matchMethod->invoke($service, '{"source":"ctrip","count":2}', '{"count":2,"source":"ctrip"}'));
        self::assertFalse($matchMethod->invoke($service, '{"source":"meituan"}', '{"source":"ctrip"}'));

        $columns = array_fill_keys(['tenant_id', 'system_hotel_id', 'source', 'platform', 'hotel_id', 'data_type', 'data_date', 'dimension', 'compare_type'], true);
        $firstIdentity = $identityMethod->invoke($service, [
            'tenant_id' => 1,
            'system_hotel_id' => 80,
            'source' => 'ctrip',
            'platform' => 'ctrip',
            'hotel_id' => 'platform-hotel',
            'data_type' => 'traffic',
            'data_date' => '2026-07-16',
            'dimension' => 'summary',
            'compare_type' => '',
            'source_trace_id' => 'trace-a',
            'list_exposure' => 10,
        ], $columns);
        $duplicateIdentity = $identityMethod->invoke($service, [
            'tenant_id' => 1,
            'system_hotel_id' => 80,
            'source' => 'ctrip',
            'platform' => 'ctrip',
            'hotel_id' => 'platform-hotel',
            'data_type' => 'traffic',
            'data_date' => '2026-07-16',
            'dimension' => 'summary',
            'compare_type' => '',
            'source_trace_id' => 'trace-b',
            'list_exposure' => 20,
        ], $columns);
        self::assertSame($firstIdentity, $duplicateIdentity);
    }

    public function testXlsxImportRejectsArchiveWithTooManyEntriesBeforeXmlParsing(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not installed.');
        }

        $path = tempnam(sys_get_temp_dir(), 'platform_xlsx_many_');
        self::assertIsString($path);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        for ($index = 0; $index < 257; $index++) {
            self::assertTrue($zip->addFromString('xl/custom/entry-' . $index . '.xml', ''));
        }
        self::assertTrue($zip->close());

        try {
            $method = new \ReflectionMethod(new PlatformDataSyncService(), 'parseXlsxImportFile');
            $method->setAccessible(true);
            $method->invoke(new PlatformDataSyncService(), $path);
            self::fail('Oversized XLSX archive entry count must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertSame(422, $exception->getCode());
            self::assertSame('XLSX import archive contains too many entries.', $exception->getMessage());
        } finally {
            @unlink($path);
        }
    }

    public function testXlsxImportStillParsesAValidBoundedWorksheet(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ZipArchive is not installed.');
        }

        $path = tempnam(sys_get_temp_dir(), 'platform_xlsx_valid_');
        self::assertIsString($path);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        self::assertTrue($zip->addFromString(
            'xl/worksheets/sheet1.xml',
            '<worksheet><sheetData>'
            . '<row r="1"><c r="A1" t="inlineStr"><is><t>hotel_name</t></is></c></row>'
            . '<row r="2"><c r="A2" t="inlineStr"><is><t>Bounded Hotel</t></is></c></row>'
            . '</sheetData></worksheet>'
        ));
        self::assertTrue($zip->close());

        try {
            $service = new PlatformDataSyncService();
            $method = new \ReflectionMethod($service, 'parseXlsxImportFile');
            $method->setAccessible(true);
            $rows = $method->invoke($service, $path);
            self::assertSame([['hotel_name' => 'Bounded Hotel']], $rows);
        } finally {
            @unlink($path);
        }
    }

    public function testFinishTaskFailSafeTerminalizesExactRunningTaskWithoutLeakingAuxiliaryException(): void
    {
        Db::execute('DROP TABLE IF EXISTS platform_data_sync_tasks');
        Db::execute('DROP TABLE IF EXISTS platform_data_sources');
        Db::execute('CREATE TABLE platform_data_sources (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, system_hotel_id INTEGER, user_id INTEGER, name VARCHAR(120) NOT NULL, platform VARCHAR(50) NOT NULL, data_type VARCHAR(50) NOT NULL, ingestion_method VARCHAR(30) NOT NULL, status VARCHAR(30) NOT NULL, enabled INTEGER NOT NULL, config_json TEXT, secret_json TEXT, last_sync_time DATETIME, last_sync_status VARCHAR(30), last_error TEXT, created_by INTEGER, updated_by INTEGER, create_time DATETIME, update_time DATETIME)');
        Db::execute('CREATE TABLE platform_data_sync_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, data_source_id INTEGER, system_hotel_id INTEGER, platform VARCHAR(50) NOT NULL, data_type VARCHAR(50) NOT NULL, ingestion_method VARCHAR(30) NOT NULL, trigger_type VARCHAR(30) NOT NULL, status VARCHAR(30) NOT NULL, attempt_count INTEGER NOT NULL, max_attempts INTEGER NOT NULL, started_at DATETIME, finished_at DATETIME, next_retry_at DATETIME, requested_by INTEGER, message TEXT, stats_json TEXT, create_time DATETIME, update_time DATETIME)');

        $source = [
            'id' => 9901,
            'tenant_id' => 1,
            'system_hotel_id' => 7,
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'ingestion_method' => 'browser_profile',
        ];
        Db::name('platform_data_sources')->insert([
            ...$source,
            'user_id' => 91,
            'name' => 'Fail-safe source',
            'status' => 'ready',
            'enabled' => 1,
            'config_json' => '{}',
            'secret_json' => '{}',
            'created_by' => 91,
            'updated_by' => 91,
            'create_time' => '2026-08-09 07:00:00',
            'update_time' => '2026-08-09 07:00:00',
        ]);
        $insertTask = static function (array $overrides = []) use ($source): int {
            return (int)Db::name('platform_data_sync_tasks')->insertGetId(array_merge([
                'tenant_id' => $source['tenant_id'],
                'data_source_id' => $source['id'],
                'system_hotel_id' => $source['system_hotel_id'],
                'platform' => $source['platform'],
                'data_type' => $source['data_type'],
                'ingestion_method' => $source['ingestion_method'],
                'trigger_type' => 'manual',
                'status' => 'running',
                'attempt_count' => 1,
                'max_attempts' => 3,
                'started_at' => '2026-08-09 07:10:04',
                'requested_by' => 91,
                'message' => '',
                'stats_json' => '{}',
                'create_time' => '2026-08-09 07:10:04',
                'update_time' => '2026-08-09 07:10:04',
            ], $overrides));
        };

        try {
            $service = new PlatformDataSyncService();
            $finishTask = new \ReflectionMethod($service, 'finishTask');
            $finishTask->setAccessible(true);
            $acquireTask = new \ReflectionMethod($service, 'acquireSyncTask');
            $acquireTask->setAccessible(true);
            $throwingDate = new class {
                public function __toString(): string
                {
                    throw new \RuntimeException('sensitive-finalizer-detail-must-not-leak');
                }
            };
            $payload = [
                'data_date' => $throwingDate,
                'data_period' => 'historical_daily',
                '_save_receipt' => [
                    'attempted_count' => 8,
                    'inserted_count' => 8,
                    'updated_count' => 0,
                    'deduplicated_count' => 0,
                    'readback_count' => 8,
                    'readback_verified' => true,
                    'rolled_back' => false,
                    'row_ids' => [81871, 81872, 81873, 81874, 81875, 81876, 81877, 81878],
                ],
            ];

            $taskId = $insertTask();
            $result = $finishTask->invoke(
                $service,
                $taskId,
                $source,
                'success',
                'platform_data_synchronized',
                8,
                8,
                $payload,
                [],
                microtime(true)
            );

            $stored = Db::name('platform_data_sync_tasks')->where('id', $taskId)->find();
            self::assertIsArray($stored);
            self::assertSame('failed', $stored['status']);
            self::assertSame('collection_failed', $stored['message']);
            self::assertNotEmpty($stored['finished_at']);
            self::assertNotEmpty($stored['update_time']);
            self::assertNotEmpty($stored['next_retry_at']);
            self::assertSame(1, (int)$stored['tenant_id']);
            self::assertSame(7, (int)$stored['system_hotel_id']);
            self::assertSame(9901, (int)$stored['data_source_id']);
            $stats = json_decode((string)$stored['stats_json'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(8, $stats['normalized_count']);
            self::assertSame(8, $stats['saved_count']);
            self::assertSame(8, $stats['attempted_count']);
            self::assertSame(8, $stats['inserted_count']);
            self::assertSame(8, $stats['readback_count']);
            self::assertTrue($stats['readback_verified']);
            self::assertSame('verified', $stats['readback_status']);
            self::assertSame('preserved_from_save_receipt', $stats['persistence_fact_status']);
            self::assertTrue($stats['saved_rows_may_exist']);
            self::assertSame('unavailable_due_to_finalization_failure', $stats['run_readback_status']);
            self::assertSame(
                [81871, 81872, 81873, 81874, 81875, 81876, 81877, 81878],
                $stats['row_ids']
            );
            self::assertSame('sync_task_finalization_failed', $stats['failure_reason']);
            self::assertArrayNotHasKey('run_readback', $stats);
            self::assertSame('failed', $result['status']);
            self::assertSame($taskId, $result['task_id']);
            self::assertSame(8, $result['normalized_count']);
            self::assertSame(8, $result['saved_count']);
            self::assertSame(8, $result['inserted_count']);
            self::assertSame(8, $result['readback_count']);
            self::assertTrue($result['readback_verified']);
            self::assertTrue($result['saved_rows_may_exist']);
            self::assertSame('failed_before_task_terminalization', $result['finalization_status']);
            self::assertFalse($result['post_finalize_warning']);
            self::assertSame([], $result['run_readback']);
            self::assertSame(
                0,
                Db::name('platform_data_sync_tasks')
                    ->where('id', $taskId)
                    ->where('status', 'running')
                    ->count()
            );
            self::assertStringNotContainsString(
                'sensitive-finalizer-detail-must-not-leak',
                json_encode([$stored, $result], JSON_THROW_ON_ERROR)
            );

            // The production helper uses MySQL SHOW COLUMNS. Seed its private
            // schema cache so this isolated SQLite test exercises acquisition
            // without changing the real database-specific implementation.
            $columnCache = new \ReflectionProperty($service, 'columns');
            $columnCache->setAccessible(true);
            $columnCache->setValue($service, [
                'platform_data_sync_tasks' => array_fill_keys([
                    'id', 'tenant_id', 'data_source_id', 'system_hotel_id', 'platform',
                    'data_type', 'ingestion_method', 'trigger_type', 'status',
                    'attempt_count', 'max_attempts', 'started_at', 'finished_at',
                    'next_retry_at', 'requested_by', 'message', 'stats_json',
                    'create_time', 'update_time',
                ], true),
                'platform_data_sources' => array_fill_keys([
                    'id', 'tenant_id', 'system_hotel_id', 'user_id', 'name',
                    'platform', 'data_type', 'ingestion_method', 'status', 'enabled',
                    'config_json', 'secret_json', 'last_sync_time', 'last_sync_status',
                    'last_error', 'created_by', 'updated_by', 'create_time', 'update_time',
                ], true),
            ]);
            $retry = $acquireTask->invoke(
                $service,
                $source,
                new class {
                    public int $id = 91;
                },
                'manual',
                []
            );
            self::assertTrue($retry['created']);
            self::assertFalse($retry['reused_active_task']);
            self::assertGreaterThan($taskId, $retry['task_id']);
            self::assertSame(
                'running',
                Db::name('platform_data_sync_tasks')->where('id', (int)$retry['task_id'])->value('status')
            );

            Db::name('platform_data_sources')->insert([
                'id' => 9902,
                'tenant_id' => 1,
                'system_hotel_id' => 7,
                'user_id' => 91,
                'name' => 'Cloud Profile failure receipt source',
                'platform' => 'meituan',
                'data_type' => 'traffic',
                'ingestion_method' => 'browser_profile',
                'status' => 'ready',
                'enabled' => 1,
                'config_json' => '{}',
                'secret_json' => '{}',
                'created_by' => 91,
                'updated_by' => 91,
                'create_time' => '2026-08-09 07:00:00',
                'update_time' => '2026-08-09 07:00:00',
            ]);
            $failureReceipt = $service->recordCloudProfileCollectionFailure(
                new class {
                    public int $id = 91;
                    public function isSuperAdmin(): bool { return true; }
                },
                9902,
                'cloud_ota_target_date_mismatch',
                ['target_date' => '2026-08-09']
            );
            self::assertSame('failed', $failureReceipt['status']);
            self::assertSame(0, $failureReceipt['saved_count']);
            self::assertFalse($failureReceipt['readback_verified']);
            $failureTask = Db::name('platform_data_sync_tasks')
                ->where('id', (int)$failureReceipt['task_id'])
                ->find();
            self::assertIsArray($failureTask);
            self::assertSame('failed', $failureTask['status']);
            self::assertSame('cloud_ota_target_date_mismatch', $failureTask['message']);
            self::assertSame('failed', Db::name('platform_data_sources')->where('id', 9902)->value('last_sync_status'));
            self::assertSame(
                'cloud_ota_target_date_mismatch',
                Db::name('platform_data_sources')->where('id', 9902)->value('last_error')
            );

            $dispatcherRunId = '9d000000-0000-4000-8000-000000000001';
            $scheduledFailure = $service->recordCloudProfileCollectionFailure(
                new class {
                    public int $id = 91;
                    public function isSuperAdmin(): bool { return true; }
                },
                9902,
                'cloud_ota_collection_preflight_unverified',
                [
                    'target_date' => '2026-08-09',
                    'dispatcher_run_id' => $dispatcherRunId,
                ]
            );
            $scheduledFailureTask = Db::name('platform_data_sync_tasks')
                ->where('id', (int)$scheduledFailure['task_id'])
                ->find();
            self::assertIsArray($scheduledFailureTask);
            self::assertSame('daily_profile_reuse', $scheduledFailureTask['trigger_type']);
            $scheduledFailureStats = json_decode(
                (string)$scheduledFailureTask['stats_json'],
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            self::assertSame($dispatcherRunId, $scheduledFailureStats['dispatcher_run_id']);

            Db::execute(
                "CREATE TRIGGER platform_source_update_fail "
                . "BEFORE UPDATE ON platform_data_sources "
                . "BEGIN SELECT RAISE(ABORT, 'sensitive-post-finalize-detail-must-not-leak'); END"
            );
            $postFinalizeTaskId = $insertTask();
            $postFinalizePayload = [
                'data_date' => '2026-08-09',
                'data_period' => 'realtime_snapshot',
                '_save_receipt' => $payload['_save_receipt'],
            ];
            $postFinalizeResult = $finishTask->invoke(
                $service,
                $postFinalizeTaskId,
                $source,
                'success',
                'platform_data_synchronized',
                8,
                8,
                $postFinalizePayload
            );
            $postFinalizeStored = Db::name('platform_data_sync_tasks')
                ->where('id', $postFinalizeTaskId)
                ->find();
            self::assertIsArray($postFinalizeStored);
            self::assertSame('success', $postFinalizeStored['status']);
            $postFinalizeStats = json_decode(
                (string)$postFinalizeStored['stats_json'],
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            self::assertSame(8, $postFinalizeStats['normalized_count']);
            self::assertSame(8, $postFinalizeStats['saved_count']);
            self::assertSame(8, $postFinalizeStats['readback_count']);
            self::assertTrue($postFinalizeStats['readback_verified']);
            self::assertSame('ready', Db::name('platform_data_sources')->where('id', 9901)->value('status'));
            self::assertSame('success', $postFinalizeResult['status']);
            self::assertSame('sync_task_post_finalize_warning', $postFinalizeResult['message']);
            self::assertSame(8, $postFinalizeResult['normalized_count']);
            self::assertSame(8, $postFinalizeResult['saved_count']);
            self::assertSame(8, $postFinalizeResult['readback_count']);
            self::assertTrue($postFinalizeResult['readback_verified']);
            self::assertSame('post_finalize_warning', $postFinalizeResult['finalization_status']);
            self::assertTrue($postFinalizeResult['post_finalize_warning']);
            self::assertSame(
                'sync_task_post_finalize_failed',
                $postFinalizeResult['post_finalize_warning_code']
            );
            self::assertStringNotContainsString(
                'sensitive-post-finalize-detail-must-not-leak',
                json_encode([$postFinalizeStored, $postFinalizeResult], JSON_THROW_ON_ERROR)
            );
            Db::execute('DROP TRIGGER IF EXISTS platform_source_update_fail');

            $terminalTaskId = $insertTask([
                'status' => 'success',
                'finished_at' => '2026-08-09 07:20:00',
                'message' => 'platform_data_synchronized',
                'stats_json' => '{"preserved":true}',
                'update_time' => '2026-08-09 07:20:00',
            ]);
            $terminalBefore = Db::name('platform_data_sync_tasks')->where('id', $terminalTaskId)->find();
            $terminalResult = $finishTask->invoke(
                $service,
                $terminalTaskId,
                $source,
                'failed',
                'collection_failed',
                0,
                0,
                $payload
            );
            self::assertSame(
                $terminalBefore,
                Db::name('platform_data_sync_tasks')->where('id', $terminalTaskId)->find()
            );
            self::assertSame('success', $terminalResult['status']);
            self::assertSame('sync_task_post_finalize_warning', $terminalResult['message']);
            self::assertSame('post_finalize_warning', $terminalResult['finalization_status']);
            self::assertTrue($terminalResult['post_finalize_warning']);
            self::assertSame('unknown', $terminalResult['fact_status']['saved_count']);

            $mismatchedTaskId = $insertTask();
            $mismatchedSource = [...$source, 'tenant_id' => 999];
            try {
                $finishTask->invoke(
                    $service,
                    $mismatchedTaskId,
                    $mismatchedSource,
                    'failed',
                    'collection_failed',
                    0,
                    0,
                    $payload
                );
                self::fail('Cross-tenant task finalization must be rejected.');
            } catch (\RuntimeException $exception) {
                self::assertSame(409, $exception->getCode());
            }
            self::assertSame(
                'running',
                Db::name('platform_data_sync_tasks')->where('id', $mismatchedTaskId)->value('status')
            );
        } finally {
            Db::execute('DROP TABLE IF EXISTS platform_data_sync_tasks');
            Db::execute('DROP TABLE IF EXISTS platform_data_sources');
        }
    }


}
