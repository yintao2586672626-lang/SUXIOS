<?php
declare(strict_types=1);

namespace Tests;

use app\service\DingdandaoOperatingTargetCaptureService;
use app\service\DingdandaoOperatingTargetSyncService;
use app\service\ManualNotificationService;
use app\service\ManualNotificationTestTargetService;
use app\service\OperatingTargetNotificationPayloadService;
use app\service\OperatingTargetService;
use app\service\SingleHotelOperatingDigestService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class MolanxinLocalEndToEndAcceptanceTest extends TestCase
{
    private const TENANT_ID = 80;
    private const HOTEL_ID = 80;
    private const USER_ID = 7;
    private const ROBOT_ID = 2;
    private const HOTEL_NAME = '敦煌漠蓝新';
    private const PROVIDER_HOTEL_NAME = '敦煌漠蓝';
    private const TARGET_REVENUE = 20000.0;

    private static array $databaseConfig;
    private static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir()
            . '/molanxin_local_e2e_' . getmypid() . '.sqlite';
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
        foreach ([
            'manual_notification_dispatch_attempts',
            'manual_notification_schedule_dispatches',
            'manual_notification_schedule_runs',
            'manual_notifications',
            'operating_target_daily_snapshots',
            'operating_target_daily_records',
            'dingdandao_room_fee_capture_details',
            'dingdandao_operating_target_captures',
            'competitor_wechat_robot',
            'hotels',
        ] as $table) {
            Db::name($table)->delete(true);
        }
        Db::name('hotels')->insert([
            'id' => self::HOTEL_ID,
            'tenant_id' => self::TENANT_ID,
            'name' => self::HOTEL_NAME,
            'status' => 1,
        ]);
        Db::name('competitor_wechat_robot')->insert([
            'id' => self::ROBOT_ID,
            'store_id' => self::HOTEL_ID,
            'notification_scope' => ManualNotificationTestTargetService::TEST_SCOPE,
            'name' => '宿析OS云端日报',
            'status' => 1,
        ]);
    }

    public function testTodayCaptureTargetPreviewAndBlockedTestHistoryAreTruthful(): void
    {
        $envelope = $this->captureEnvelope();
        self::assertSame('captured_unverified', $envelope['status'] ?? null);
        self::assertFalse($envelope['raw_response_exposed'] ?? true);
        self::assertFalse($envelope['session_material_exposed'] ?? true);
        self::assertFalse($envelope['browser_opened'] ?? true);
        self::assertFalse($envelope['browser_closed'] ?? true);

        $captureInput = is_array($envelope['capture'] ?? null)
            ? $envelope['capture']
            : [];
        $businessDate = (string)($captureInput['business_date'] ?? '');
        $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))
            ->format('Y-m-d');
        self::assertSame($today, $businessDate);

        $scope = (array)Config::get('single_hotel_operating_digest', []);
        self::assertSame(self::TENANT_ID, (int)($scope['tenant_id'] ?? 0));
        self::assertSame(self::HOTEL_ID, (int)($scope['hotel_id'] ?? 0));
        self::assertSame(self::HOTEL_NAME, (string)($scope['hotel_name'] ?? ''));
        self::assertSame(
            self::PROVIDER_HOTEL_NAME,
            (string)($scope['pms']['provider_hotel_name'] ?? '')
        );
        self::assertSame(
            self::PROVIDER_HOTEL_NAME,
            (string)($captureInput['provider_hotel_name'] ?? '')
        );
        self::assertSame(
            'verified_api_store_identity',
            (string)($captureInput['identity_evidence_type'] ?? '')
        );

        $providerHotelId = trim((string)($captureInput['provider_hotel_id'] ?? ''));
        self::assertNotSame('', $providerHotelId);
        $targetService = new OperatingTargetService();
        $manualTarget = $targetService->save(
            self::TENANT_ID,
            self::HOTEL_ID,
            self::USER_ID,
            [
                'target_date' => $businessDate,
                'target_revenue' => self::TARGET_REVENUE,
                'actual_revenue' => null,
                'sold_room_nights' => null,
                'sellable_room_nights' => null,
                'fact_scope' => 'accommodation_room_fee',
                'source_type' => 'manual',
                'source_reference' => '用户人工确认住宿营收目标',
                'quality_status' => 'manual_confirmed',
                'quality_reason' => '用户明确确认敦煌漠蓝新当日住宿营收目标为20000元。',
                'fact_captured_at' => $businessDate . ' 00:00:00',
                'change_reason' => '用户人工确认住宿营收目标20000元',
            ]
        );
        self::assertSame('partial', $manualTarget['status']);
        self::assertSame(1, $manualTarget['revision_no']);

        $captureService = new DingdandaoOperatingTargetCaptureService();
        $savedCapture = $captureService->save(
            self::TENANT_ID,
            self::HOTEL_ID,
            self::USER_ID,
            self::PROVIDER_HOTEL_NAME,
            $captureInput,
            true,
            $providerHotelId
        );
        $capture = $captureService->read(
            self::TENANT_ID,
            self::HOTEL_ID,
            (int)$savedCapture['id']
        );
        self::assertSame('matched', $capture['identity_status']);
        self::assertSame('verified', $capture['capture_status']);
        self::assertSame('verified', $capture['quality_status']);
        self::assertSame('matched', $capture['reconciliation_status']);
        self::assertSame('readback_verified', $capture['readback_status']);
        self::assertSame($businessDate, $capture['business_date']);
        self::assertSame(count($captureInput['room_fee_details']), $capture['detail_row_count']);
        self::assertEqualsWithDelta(
            (float)$captureInput['summary']['total_room_fee'],
            (float)$capture['detail_room_fee_total'],
            0.01
        );
        $todayTrend = array_values(array_filter(
            (array)($capture['trend']['total_room_fee'] ?? []),
            static fn(array $point): bool => ($point['date'] ?? '') === $businessDate
        ));
        self::assertCount(1, $todayTrend);
        self::assertEqualsWithDelta(
            (float)$capture['summary']['total_room_fee'],
            (float)$todayTrend[0]['value'],
            0.01
        );

        $sync = (new DingdandaoOperatingTargetSyncService(
            $captureService,
            $targetService
        ))->syncVerifiedCapture(
            self::TENANT_ID,
            self::HOTEL_ID,
            self::USER_ID,
            (int)$capture['id']
        );
        self::assertSame('updated', $sync['sync_status']);
        self::assertTrue($sync['send_eligible']);

        $current = $targetService->current(
            self::TENANT_ID,
            self::HOTEL_ID,
            $businessDate
        );
        self::assertSame('ready', $current['status']);
        self::assertSame(self::TARGET_REVENUE, $current['record']['facts']['target_revenue']);
        self::assertEqualsWithDelta(
            (float)$capture['summary']['total_room_fee'],
            (float)$current['record']['facts']['actual_revenue'],
            0.01
        );
        self::assertSame('pms', $current['record']['facts']['source_type']);
        self::assertSame('verified', $current['record']['facts']['quality_status']);
        self::assertSame(2, $current['record']['revision_no']);
        $metrics = $current['record']['calculation']['metrics'];
        self::assertEqualsWithDelta(43.73, (float)$metrics['completion_rate_percent'], 0.01);
        self::assertEqualsWithDelta(11254.34, (float)$metrics['remaining_revenue'], 0.01);
        self::assertEqualsWithDelta(100.0, (float)$metrics['selling_progress_percent'], 0.01);
        self::assertSame(0, $metrics['remaining_sellable_room_nights']);
        self::assertNull($metrics['required_average_rate']);
        self::assertSame([], $current['record']['calculation']['gaps']);
        self::assertContains(
            'target_unmet_inventory_exhausted',
            array_column($current['record']['calculation']['reminders'], 'code')
        );
        $snapshots = $targetService->snapshotHistory(
            self::TENANT_ID,
            self::HOTEL_ID,
            $businessDate
        );
        self::assertSame(2, count($snapshots['list']));
        self::assertSame(
            ['readback_verified', 'readback_verified'],
            array_column($snapshots['list'], 'readback_status')
        );

        $digest = $this->controlledThreeSourceDigest(
            $scope,
            $businessDate,
            $captureService
        );
        $payloads = new OperatingTargetNotificationPayloadService(
            $targetService,
            null,
            $digest
        );
        $page = $payloads->pagePreview(
            self::TENANT_ID,
            self::HOTEL_ID,
            self::HOTEL_NAME,
            $businessDate
        );
        $integrated = $page['report_preview']['integrated_sources'];
        $brief = $page['report_preview']['integrated_message_preview'];
        self::assertTrue($integrated['delivery_allowed']);
        self::assertSame('preview_ready', $brief['status']);
        self::assertTrue($brief['preview_only']);
        self::assertFalse($brief['message_sent']);
        self::assertFalse($brief['external_delivery_authorized']);
        self::assertStringContainsString('订单来了PMS', $brief['content']);
        self::assertStringContainsString('携程｜可选渠道事实', $brief['content']);
        self::assertStringContainsString('美团｜可选流量与订单事实', $brief['content']);
        self::assertStringContainsString('¥8,745.66', $brief['content']);
        self::assertTrue($page['formal_send_gate']['allowed']);
        self::assertSame([], $page['formal_send_gate']['blockers']);

        $notifications = new ManualNotificationService(
            null,
            $payloads
        );
        $notification = $notifications->save(
            self::TENANT_ID,
            self::HOTEL_ID,
            self::USER_ID,
            self::HOTEL_NAME,
            $this->notificationInput($businessDate)
        );
        $testPush = $notifications->testPush(
            self::TENANT_ID,
            self::HOTEL_ID,
            (int)$notification['record']['id'],
            self::USER_ID,
            true,
            self::ROBOT_ID,
            '宿析OS云端日报',
            self::HOTEL_NAME,
            'molanxin-local-e2e-' . $businessDate
        );
        self::assertSame('test_dispatcher_missing', $testPush['delivery_status']);
        self::assertSame('blocked', $testPush['dispatch']['status']);
        self::assertSame(0, $testPush['dispatch']['attempt_count']);
        self::assertSame(self::ROBOT_ID, $testPush['dispatch']['robot_id']);
        self::assertSame(0, Db::name('manual_notification_dispatch_attempts')->count());

        $history = $notifications->dispatchHistory(
            self::TENANT_ID,
            self::HOTEL_ID
        );
        self::assertSame(1, $history['total']);
        self::assertSame('blocked', $history['list'][0]['status']);
        self::assertSame('immediate_test', $history['list'][0]['request_kind']);
        self::assertSame(0, $history['list'][0]['attempt_count']);
    }

    /** @return array<string,mixed> */
    private function captureEnvelope(): array
    {
        $path = trim((string)getenv('MOLANXIN_DINGDANDAO_CAPTURE_FILE'));
        if ($path === '' || !is_file($path)) {
            self::markTestSkipped(
                'Set MOLANXIN_DINGDANDAO_CAPTURE_FILE to a sanitized same-day collector result.'
            );
        }
        $decoded = json_decode(
            (string)file_get_contents($path),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * Ctrip and Meituan values are explicit local contract fixtures. They
     * prove rendering and gate behavior only and are never promoted as live
     * platform facts or used by an external sender.
     */
    private function controlledThreeSourceDigest(
        array $scope,
        string $businessDate,
        DingdandaoOperatingTargetCaptureService $captureService
    ): SingleHotelOperatingDigestService {
        return new SingleHotelOperatingDigestService(
            static fn(): array => [
                'id' => self::HOTEL_ID,
                'tenant_id' => self::TENANT_ID,
                'name' => self::HOTEL_NAME,
                'status' => 1,
            ],
            static fn(int $tenantId, int $hotelId, string $date): array =>
                $captureService->latest($tenantId, $hotelId, $date),
            static fn(): array => [
                'source_policy' => [
                    'hotel_scope' => 'system_hotel_id_strict_exact_only',
                    'readback_policy' => 'readback_verified_required_equals_1',
                    'platform_hotel_identity_policy' =>
                        'platform_data_source_config_exact_required',
                    'metric_scope' => 'ota_channel',
                ],
                'rows' => [[
                    'data_date' => $businessDate,
                    'source' => 'ctrip',
                    'data_source_id' => 501,
                    'platform_hotel_id' =>
                        (string)$scope['platforms']['ctrip']['platform_hotel_id'],
                    'amount' => 1280.50,
                    'book_order_num' => 2,
                    'quantity' => 2,
                    'collected_at' => $businessDate . ' 12:05:00',
                ]],
            ],
            static fn(): array => [
                'business_date' => $businessDate,
                'row_id' => 502,
                'order_row_id' => 503,
                'data_source_id' => 6,
                'identity_matched' => true,
                'readback_verified' => true,
                'field_facts_verified' => true,
                'order_fact_verified' => true,
                'collected_at' => $businessDate . ' 12:10:00',
                'order_collected_at' => $businessDate . ' 12:10:00',
                'facts' => [
                    'list_exposure' => 200,
                    'detail_exposure' => 40,
                    'flow_rate_percent' => 20,
                    'paid_orders' => 3,
                    'target_date_order_count' => 1,
                ],
            ],
            $scope
        );
    }

    /** @return array<string,mixed> */
    private function notificationInput(string $businessDate): array
    {
        return [
            'notification_type' => ManualNotificationService::DYNAMIC_REPORT_TYPE,
            'business_date' => $businessDate,
            'title' => $businessDate . ' 敦煌漠蓝新三源经营测试预览',
            'body' => "【三源经营测试预览】\n正文由同店同日事实动态生成，门禁未通过时不得外发。",
            'send_method' => 'wecom_test',
            'trigger_type' => 'daily_fixed_time',
            'planned_send_at' => $businessDate . 'T18:00',
            'enabled' => true,
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
        Db::execute('CREATE TABLE operating_target_daily_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            hotel_id INTEGER NOT NULL,
            target_date VARCHAR(10) NOT NULL,
            target_revenue NUMERIC NULL,
            actual_revenue NUMERIC NULL,
            sold_room_nights INTEGER NULL,
            sellable_room_nights INTEGER NULL,
            fact_scope VARCHAR(32) NOT NULL,
            source_type VARCHAR(32) NOT NULL,
            source_reference VARCHAR(255) NULL,
            quality_status VARCHAR(32) NOT NULL,
            quality_reason VARCHAR(255) NULL,
            fact_captured_at DATETIME NULL,
            calculation_status VARCHAR(32) NOT NULL,
            gap_codes_json TEXT NULL,
            calculation_json TEXT NULL,
            report_status VARCHAR(32) NOT NULL,
            created_by INTEGER NULL,
            updated_by INTEGER NULL,
            create_time DATETIME NOT NULL,
            update_time DATETIME NOT NULL,
            UNIQUE(tenant_id, hotel_id, target_date)
        )');
        Db::execute('CREATE TABLE operating_target_daily_snapshots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            record_id INTEGER NOT NULL,
            tenant_id INTEGER NOT NULL,
            hotel_id INTEGER NOT NULL,
            target_date VARCHAR(10) NOT NULL,
            revision_no INTEGER NOT NULL,
            change_reason VARCHAR(500) NULL,
            snapshot_json TEXT NOT NULL,
            created_by INTEGER NULL,
            create_time DATETIME NOT NULL,
            UNIQUE(record_id, revision_no)
        )');
        Db::execute('CREATE TABLE manual_notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            hotel_id INTEGER NOT NULL,
            notification_type VARCHAR(40) NOT NULL,
            template_type VARCHAR(40) NOT NULL,
            business_date VARCHAR(10) NOT NULL,
            title VARCHAR(120) NOT NULL,
            body TEXT NOT NULL,
            send_method VARCHAR(32) NOT NULL,
            trigger_type VARCHAR(32) NOT NULL,
            planned_send_at DATETIME NULL,
            enabled INTEGER NOT NULL,
            schedule_status VARCHAR(32) NOT NULL,
            last_test_status VARCHAR(32) NOT NULL,
            last_test_message VARCHAR(255) NULL,
            last_tested_at DATETIME NULL,
            last_tested_by INTEGER NULL,
            test_robot_id INTEGER NULL,
            test_robot_name VARCHAR(120) NULL,
            created_by INTEGER NOT NULL,
            create_time DATETIME NOT NULL,
            update_time DATETIME NOT NULL
        )');
        Db::execute('CREATE TABLE manual_notification_schedule_dispatches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            notification_id INTEGER NOT NULL,
            tenant_id INTEGER NOT NULL,
            hotel_id INTEGER NOT NULL,
            dispatch_window VARCHAR(32) NOT NULL,
            delivery_mode VARCHAR(16) NOT NULL,
            trigger_type VARCHAR(32) NOT NULL,
            request_kind VARCHAR(32) NOT NULL,
            business_date VARCHAR(10) NULL,
            payload_fingerprint VARCHAR(64) NULL,
            operating_target_record_id INTEGER NULL,
            snapshot_revision_no INTEGER NULL,
            render_contract_version VARCHAR(48) NULL,
            payload_snapshot_json TEXT NULL,
            attempt_count INTEGER NOT NULL,
            max_attempts INTEGER NOT NULL,
            next_retry_at DATETIME NULL,
            last_attempt_at DATETIME NULL,
            response_reference VARCHAR(120) NULL,
            robot_id INTEGER NOT NULL,
            robot_name VARCHAR(120) NOT NULL,
            status VARCHAR(24) NOT NULL,
            result_code VARCHAR(64) NOT NULL,
            result_message VARCHAR(255) NULL,
            claimed_at DATETIME NOT NULL,
            dispatched_at DATETIME NULL,
            create_time DATETIME NOT NULL,
            update_time DATETIME NOT NULL,
            UNIQUE(notification_id, dispatch_window, delivery_mode)
        )');
        Db::execute('CREATE TABLE manual_notification_dispatch_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            dispatch_id INTEGER NOT NULL,
            notification_id INTEGER NOT NULL,
            tenant_id INTEGER NOT NULL,
            hotel_id INTEGER NOT NULL,
            attempt_no INTEGER NOT NULL,
            request_kind VARCHAR(32) NOT NULL,
            status VARCHAR(32) NOT NULL,
            result_code VARCHAR(64) NOT NULL,
            result_message VARCHAR(255) NULL,
            payload_fingerprint VARCHAR(64) NULL,
            response_reference VARCHAR(120) NULL,
            attempted_at DATETIME NOT NULL,
            create_time DATETIME NOT NULL,
            UNIQUE(dispatch_id, attempt_no)
        )');
        Db::execute('CREATE TABLE manual_notification_schedule_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            runner_mode VARCHAR(16) NOT NULL,
            dispatch_requested INTEGER NOT NULL,
            scope_hotel_id INTEGER NULL,
            scope_robot_id INTEGER NULL,
            observed_at DATETIME NOT NULL,
            status VARCHAR(32) NOT NULL,
            candidate_count INTEGER NOT NULL,
            due_count INTEGER NOT NULL,
            sent_count INTEGER NOT NULL,
            failed_count INTEGER NOT NULL,
            blocked_count INTEGER NOT NULL,
            result_summary_json TEXT NULL,
            started_at DATETIME NOT NULL,
            finished_at DATETIME NULL,
            create_time DATETIME NOT NULL,
            update_time DATETIME NOT NULL
        )');
        Db::execute('CREATE TABLE competitor_wechat_robot (
            id INTEGER PRIMARY KEY,
            store_id INTEGER NOT NULL,
            notification_scope VARCHAR(40) NULL,
            name VARCHAR(120) NOT NULL,
            status INTEGER NOT NULL
        )');
        Db::execute('CREATE TABLE hotels (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            name VARCHAR(120) NOT NULL,
            status INTEGER NOT NULL
        )');
    }
}
