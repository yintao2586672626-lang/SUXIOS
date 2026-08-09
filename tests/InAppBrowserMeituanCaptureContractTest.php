<?php
declare(strict_types=1);

use app\service\PlatformDataSyncService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class InAppBrowserMeituanCaptureContractTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $databaseConnection = '';
    private static string $databasePath = '';

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$databaseConnection = 'iab_meituan_contract_' . getmypid();
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
        Db::name('hotels')->insert(['id' => 80, 'tenant_id' => 1]);
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Db::connect(self::$databaseConnection)->close();
        } catch (Throwable) {
        }
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$databasePath);
    }

    public function testOfflineEntryIsExplicitlyFailClosedWithoutPersistenceCall(): void
    {
        $entry = (string)file_get_contents(dirname(__DIR__) . '/scripts/import_iab_meituan_capture.php');

        self::assertStringContainsString("'status' => 'blocked'", $entry);
        self::assertStringContainsString("'verification_status' => 'user_provided_unverified'", $entry);
        self::assertStringContainsString("'reason' => 'controlled_live_capture_handle_required'", $entry);
        self::assertStringContainsString("'allowed' => false", $entry);
        self::assertStringContainsString("'saved_count' => 0", $entry);
        self::assertStringNotContainsString('syncDataSource(', $entry);
        self::assertStringNotContainsString('PlatformDataSyncService', $entry);
    }

    public function testCaptureEntryDoesNotAcceptCredentialFields(): void
    {
        $entry = strtolower((string)file_get_contents(
            dirname(__DIR__) . '/scripts/import_iab_meituan_capture.php'
        ));

        self::assertStringNotContainsString('cookie', $entry);
        self::assertStringNotContainsString('authorization', $entry);
        self::assertStringNotContainsString('password', $entry);
        self::assertStringNotContainsString('token', $entry);
    }

    public function testArbitraryOfflineJsonCannotBePromotedWithSpoofedEvidence(): void
    {
        $service = new PlatformDataSyncService();
        $options = [
            'interactive_browser' => true,
            'in_app_browser_capture' => [
                'contract_version' => 'suxi_iab_meituan_capture.v2',
                'platform' => 'meituan',
                'data_source_id' => 68,
                'system_hotel_id' => 80,
                'data_date' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d'),
                'captured_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d H:i:s'),
                'response_origin' => 'https://eb.meituan.com',
                'response_statuses' => [
                    '/api/v1/ebooking/common/pois' => 200,
                    '/api/shepherdGw/bizDatacenter/hotel/eb/dataCenter/workbench/simple' => 200,
                    '/api/v1/ebooking/workbench/business/analysis' => 200,
                ],
                'identity' => [
                    'poi_id' => 'poi-test-80',
                    'poi_id_sha256' => hash('sha256', 'poi-test-80'),
                    'partner_id_sha256' => hash('sha256', 'partner-test-80'),
                ],
                'traffic_response' => [
                    'response_path' => '/api/v1/ebooking/workbench/business/analysis',
                    'source_path' => 'data.myHotel',
                    'source_url_hash' => hash(
                        'sha256',
                        'https://eb.meituan.com/api/v1/ebooking/workbench/business/analysis'
                    ),
                    'data' => [
                        'exposureUV' => 81,
                        'intentionUV' => 14,
                        'payOrderPerIntention' => '14.29%',
                    ],
                ],
            ],
        ];

        try {
            $this->invokeIabGuard($service, $options);
            self::fail('Offline JSON must not reach a verified or persistable result.');
        } catch (RuntimeException $exception) {
            self::assertSame(422, $exception->getCode());
            self::assertStringContainsString('user_provided_unverified', $exception->getMessage());
            self::assertStringContainsString('controlled_live_capture_handle_required', $exception->getMessage());
        }
    }

    public function testAbsentOfflineCaptureOptionLeavesBrowserProfileCollectionPathAvailable(): void
    {
        $service = new PlatformDataSyncService();

        self::assertNull($this->invokeIabGuard($service, [
            'capture_sections' => ['traffic'],
            'data_date' => '2026-08-08',
        ]));
    }

    public function testTrafficReceiptUsesTrafficModuleEvenWhenSourceTypeIsAll(): void
    {
        $service = new PlatformDataSyncService();
        $method = new ReflectionMethod($service, 'otaCollectionResult');
        $metricKeys = ['list_exposure', 'detail_exposure', 'flow_rate'];
        $result = $method->invoke($service, $this->source(), [
            'status' => 'success',
            'task_id' => 90,
            'data_source_id' => 68,
            'system_hotel_id' => 80,
            'platform' => 'meituan',
            'normalized_count' => 1,
            'saved_count' => 1,
            'run_readback' => [
                'readback_verified' => true,
                'sync_task_id' => 90,
                'data_source_id' => 68,
                'system_hotel_id' => 80,
                'platform' => 'meituan',
                'target_date' => '2026-08-09',
                'data_period' => 'realtime_snapshot',
                'started_at' => '2026-08-09 06:00:00',
                'row_ids' => [501],
                'source_trace_ids' => ['meituan:task:90'],
                'observed_platform_hotel_id' => 'poi-test-80',
                'capture_strategy' => 'browser_response',
                'response_evidence_type' => 'structured_json',
                'p0_status' => 'ready',
                'field_fact_status' => 'ready',
                'page_field_fact_status' => 'ready',
                'platform_hotel_identifier_status' => 'ready',
                'required_traffic_metric_keys' => $metricKeys,
                'complete_traffic_metric_keys' => $metricKeys,
                'missing_traffic_metric_keys' => [],
                'readback_count' => 1,
                'failure_reason' => '',
            ],
        ]);

        self::assertSame('traffic', $result['scope']['business_module']);
        self::assertSame($metricKeys, array_column($result['metrics'], 'metric_key'));
        self::assertTrue($result['claim']['allowed'], implode(',', $result['claim']['reason_codes']));
    }

    public function testRunReceiptPrefersActualCaptureTimeOverTaskStartTime(): void
    {
        $service = new PlatformDataSyncService();
        $method = new ReflectionMethod($service, 'buildRunReadbackReceipt');
        $receipt = $method->invoke(
            $service,
            90,
            $this->source(),
            [],
            [
                'data_date' => '2026-08-09',
                'data_period' => 'realtime_snapshot',
                'captured_at' => '2026-08-09 06:00:00',
            ],
            ['started_at' => '2026-08-09 05:55:00']
        );

        self::assertSame('2026-08-09 06:00:00', $receipt['started_at']);
    }

    /** @param array<string, mixed> $options */
    private function invokeIabGuard(
        PlatformDataSyncService $service,
        array $options
    ): mixed
    {
        $method = new ReflectionMethod($service, 'assertNoUntrustedInAppBrowserCapture');
        return $method->invoke($service, $options);
    }

    /** @return array<string, mixed> */
    private function source(): array
    {
        return [
            'id' => 68,
            'tenant_id' => 1,
            'system_hotel_id' => 80,
            'platform' => 'meituan',
            'data_type' => 'all',
            'ingestion_method' => 'browser_profile',
            'enabled' => 1,
            'external_hotel_id' => 'poi-test-80',
            'config' => [
                'poi_id' => 'poi-test-80',
                'partner_id' => 'partner-test-80',
                'poi_name' => 'Test Hotel 80',
                'capture_sections' => ['traffic'],
            ],
        ];
    }
}
