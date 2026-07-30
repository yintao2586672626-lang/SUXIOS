<?php
declare(strict_types=1);

namespace Tests;

use app\service\ManualNotificationDispatchLedgerService;
use app\service\ManualNotificationBusinessPayloadService;
use app\service\ManualNotificationScheduleService;
use app\service\ManualNotificationService;
use app\service\OperatingDailyReportPayloadService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class ManualNotificationScheduleServiceTest extends TestCase
{
    private static array $databaseConfig;
    private static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . '/manual_notification_schedule_' . getmypid() . '.sqlite';
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
        Db::execute('CREATE TABLE IF NOT EXISTS manual_notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            hotel_id INTEGER NOT NULL,
            notification_type VARCHAR(40) NOT NULL,
            template_type VARCHAR(40) NOT NULL,
            source_scope VARCHAR(32) NOT NULL DEFAULT "combined",
            content_sections VARCHAR(512) NOT NULL DEFAULT "",
            business_date VARCHAR(10) NOT NULL,
            business_date_rule VARCHAR(24) NOT NULL DEFAULT "today",
            title VARCHAR(120) NOT NULL,
            body TEXT NOT NULL,
            send_method VARCHAR(32) NOT NULL,
            trigger_type VARCHAR(32) NOT NULL,
            interval_minutes INTEGER NULL,
            planned_send_at DATETIME NULL,
            active_weekdays VARCHAR(20) NOT NULL DEFAULT "1,2,3,4,5,6,7",
            effective_from VARCHAR(10) NULL,
            effective_to VARCHAR(10) NULL,
            hourly_start_time VARCHAR(8) NOT NULL DEFAULT "09:00:00",
            hourly_end_time VARCHAR(8) NOT NULL DEFAULT "22:00:00",
            enabled INTEGER NOT NULL,
            schedule_status VARCHAR(32) NOT NULL,
            last_test_status VARCHAR(32) NOT NULL,
            last_tested_at DATETIME NULL,
            test_robot_id INTEGER NULL,
            test_robot_name VARCHAR(120) NULL,
            created_by INTEGER NOT NULL,
            create_time DATETIME NOT NULL,
            update_time DATETIME NOT NULL
        )');
        Db::execute('CREATE TABLE IF NOT EXISTS manual_notification_schedule_dispatches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            schedule_run_id INTEGER NULL,
            notification_id INTEGER NOT NULL,
            tenant_id INTEGER NOT NULL,
            hotel_id INTEGER NOT NULL,
            dispatch_window VARCHAR(32) NOT NULL,
            delivery_mode VARCHAR(16) NOT NULL,
            trigger_type VARCHAR(32) NOT NULL,
            request_kind VARCHAR(32) NOT NULL,
            business_date VARCHAR(10) NULL,
            payload_fingerprint VARCHAR(64) NULL,
            tested_plan_fingerprint VARCHAR(64) NULL,
            source_snapshot_refs_json TEXT NULL,
            source_snapshot_fingerprint VARCHAR(64) NULL,
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
        Db::execute('CREATE TABLE IF NOT EXISTS manual_notification_dispatch_attempts (
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
        Db::execute('CREATE TABLE IF NOT EXISTS manual_notification_schedule_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            scope_tenant_id INTEGER NULL,
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
        Db::execute('CREATE TABLE IF NOT EXISTS manual_notification_schedule_run_scopes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            schedule_run_id INTEGER NOT NULL,
            tenant_id INTEGER NOT NULL,
            hotel_id INTEGER NOT NULL,
            robot_id INTEGER NOT NULL,
            runner_mode VARCHAR(16) NOT NULL,
            dispatch_requested INTEGER NOT NULL,
            observed_at DATETIME NOT NULL,
            status VARCHAR(32) NOT NULL,
            candidate_count INTEGER NOT NULL,
            due_count INTEGER NOT NULL,
            sent_count INTEGER NOT NULL,
            failed_count INTEGER NOT NULL,
            blocked_count INTEGER NOT NULL,
            create_time DATETIME NOT NULL,
            update_time DATETIME NOT NULL,
            UNIQUE(schedule_run_id, tenant_id, hotel_id, robot_id)
        )');
        Db::execute('CREATE TABLE IF NOT EXISTS competitor_wechat_robot (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            store_id INTEGER NOT NULL,
            name VARCHAR(120) NOT NULL,
            status INTEGER NOT NULL,
            owner_user_id INTEGER NULL,
            notification_scope VARCHAR(40) NULL
        )');
        Db::execute('CREATE TABLE IF NOT EXISTS hotels (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            name VARCHAR(120) NOT NULL
        )');
        Db::name('manual_notification_dispatch_attempts')->delete(true);
        Db::name('manual_notification_schedule_dispatches')->delete(true);
        Db::name('manual_notification_schedule_run_scopes')->delete(true);
        Db::name('manual_notification_schedule_runs')->delete(true);
        Db::name('manual_notifications')->delete(true);
        Db::name('competitor_wechat_robot')->delete(true);
        Db::name('hotels')->delete(true);
        Db::name('hotels')->insert(['id' => 80, 'tenant_id' => 9, 'name' => '敦煌漠蓝新']);
        Db::name('competitor_wechat_robot')->insert([
            'id' => ManualNotificationService::TEST_ROBOT_ID,
            'store_id' => 80,
            'name' => ManualNotificationService::TEST_ROBOT_NAME,
            'status' => 1,
            'owner_user_id' => null,
            'notification_scope' => 'admin_shared',
        ]);
    }

    public function testPreviewSelectsOnlyEnabledPendingDueRecordsWithoutSending(): void
    {
        $this->insertRecord(['trigger_type' => 'daily_fixed_time', 'planned_send_at' => '2026-07-01 18:00:00']);
        $this->insertRecord(['trigger_type' => 'hourly_on_the_hour', 'planned_send_at' => null]);
        $this->insertRecord(['enabled' => 0]);
        $this->insertRecord(['schedule_status' => 'saved_only']);
        $calls = [];
        $service = new ManualNotificationScheduleService(
            static function () use (&$calls): array {
                $calls[] = true;
                return ['delivery_status' => 'sent'];
            }
        );

        $result = $service->runDue($this->time('2026-07-26 18:03:00'));

        self::assertSame('preview', $result['status']);
        self::assertFalse($result['dispatch_requested']);
        self::assertSame(2, $result['candidate_count']);
        self::assertSame(2, $result['due_count']);
        self::assertCount(2, $result['results']);
        self::assertSame([], $calls);
        self::assertSame(0, Db::name('manual_notification_schedule_dispatches')->count());
        self::assertStringContainsString('未取得的数据未使用0或旧日数据补齐', $result['results'][0]['payload']['markdown']['content']);
    }

    public function testMeituanDispatchRefreshesAndReadsBackBeforeSending(): void
    {
        $this->insertRecord([
            'source_scope' => 'meituan',
            'content_sections' => 'meituan_traffic,meituan_conversion',
        ]);
        $events = [];
        $service = new ManualNotificationScheduleService(
            static function () use (&$events): array {
                $events[] = 'send';
                return ['delivery_status' => 'sent'];
            },
            null,
            null,
            null,
            null,
            null,
            null,
            static function (
                array $row,
                string $businessDate
            ) use (&$events): array {
                $events[] = 'refresh';
                return [
                    'status' => 'ready',
                    'reason_code' => 'meituan_current_capture_saved_and_read_back',
                    'target_date' => $businessDate,
                    'sync_task_id' => 1977,
                    'saved_count' => 2,
                    'readback_verified' => true,
                ];
            }
        );

        $result = $service->runDue(
            $this->time('2026-07-26 18:01:00'),
            true
        );

        self::assertSame('dispatch_checked', $result['status']);
        self::assertSame(1, $result['sent_count']);
        self::assertSame(['refresh', 'send'], $events);
        self::assertSame(
            'ready',
            $result['results'][0]['source_preparation']['status']
        );
        self::assertTrue(
            $result['results'][0]['source_preparation']['readback_verified']
        );
        self::assertSame(
            1977,
            $result['results'][0]['source_preparation']['sync_task_id']
        );
    }

    public function testMeituanDispatchStopsWhenCurrentReadbackIsMissing(): void
    {
        $this->insertRecord([
            'source_scope' => 'meituan',
            'content_sections' => 'meituan_traffic,meituan_conversion',
        ]);
        $sendCalls = 0;
        $service = new ManualNotificationScheduleService(
            static function () use (&$sendCalls): array {
                $sendCalls++;
                return ['delivery_status' => 'sent'];
            },
            null,
            null,
            null,
            null,
            null,
            null,
            static fn(): array => [
                'status' => 'ready',
                'reason_code' => 'capture_completed_without_readback',
                'saved_count' => 2,
                'readback_verified' => false,
            ]
        );

        $result = $service->runDue(
            $this->time('2026-07-26 18:01:00'),
            true
        );

        self::assertSame('dispatch_blocked', $result['status']);
        self::assertSame(1, $result['blocked_count']);
        self::assertSame(0, $sendCalls);
        self::assertSame(
            'meituan_current_capture_readback_missing',
            $result['results'][0]['reason_code']
        );
        self::assertFalse(
            (bool)($result['results'][0]['delivery_attempted'] ?? false)
        );
    }

    public function testCombinedOperatingDailyPreparesAllThreeSourcesInOneWindow(): void
    {
        $notificationId = $this->insertRecord([
            'notification_type' =>
                ManualNotificationService::OPERATING_DAILY_REPORT_TYPE,
            'template_type' =>
                ManualNotificationService::OPERATING_DAILY_REPORT_TYPE,
            'source_scope' => 'combined',
            'content_sections' =>
                'pms_summary,ctrip_traffic,meituan_traffic',
        ]);
        $this->seedBusinessContractTest(
            $notificationId,
            OperatingDailyReportPayloadService::RENDER_CONTRACT_VERSION
        );
        $events = [];
        $sendCalls = 0;
        $service = new ManualNotificationScheduleService(
            sender: static function () use (&$events, &$sendCalls): array {
                $events[] = 'send';
                $sendCalls++;
                return ['delivery_status' => 'sent'];
            },
            operatingDailyPayloads: $this->operatingDailyThreeSourcePayloads(),
            meituanTemporalRefresher: static function (
                array $row,
                string $businessDate
            ) use (&$events): array {
                $events[] = 'meituan';
                return [
                    'status' => 'ready',
                    'reason_code' =>
                        'meituan_current_capture_saved_and_read_back',
                    'target_date' => $businessDate,
                    'sync_task_id' => 22,
                    'saved_count' => 2,
                    'readback_verified' => true,
                ];
            },
            ctripTemporalRefresher: static function (
                array $row,
                string $businessDate
            ) use (&$events): array {
                $events[] = 'ctrip';
                return [
                    'status' => 'ready',
                    'reason_code' =>
                        'ctrip_current_capture_saved_and_read_back',
                    'target_date' => $businessDate,
                    'sync_task_id' => 21,
                    'saved_count' => 3,
                    'readback_verified' => true,
                ];
            },
            pmsSourceRefresher: static function (
                array $row,
                string $businessDate
            ) use (&$events): array {
                $events[] = 'pms';
                return [
                    'status' => 'ready',
                    'reason_code' =>
                        'pms_current_capture_saved_and_read_back',
                    'target_date' => $businessDate,
                    'capture_id' => 301,
                    'saved_count' => 1,
                    'readback_verified' => true,
                ];
            }
        );

        $result = $service->runDue(
            $this->time('2026-07-26 18:01:00'),
            true
        );

        self::assertSame(
            ['pms', 'meituan', 'ctrip', 'send'],
            $events,
            json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        self::assertSame(
            'ready',
            $result['results'][0]['pms_source_preparation']['status']
        );
        self::assertSame(
            'ready',
            $result['results'][0]['source_preparation']['status']
        );
        self::assertSame(
            'ready',
            $result['results'][0]['ctrip_source_preparation']['status']
        );
        self::assertSame(1, $sendCalls);
        self::assertSame(1, $result['sent_count']);
        self::assertSame('sent', $result['results'][0]['status']);
        self::assertSame(
            'wecom_business_success',
            $result['results'][0]['reason_code']
        );
        $dispatch = Db::name('manual_notification_schedule_dispatches')
            ->where('notification_id', $notificationId)
            ->where('request_kind', 'scheduled')
            ->find();
        self::assertIsArray($dispatch);
        $sourceRefs = json_decode(
            (string)$dispatch['source_snapshot_refs_json'],
            true
        );
        self::assertSame(
            ['ctrip_traffic', 'meituan_traffic', 'pms'],
            array_keys($sourceRefs)
        );
        self::assertSame(
            'provider-hotel-80',
            $sourceRefs['pms']['bound_provider_hotel_id']
        );
        self::assertSame(
            'ctrip:trace-101',
            $sourceRefs['ctrip_traffic']['source_trace_id']
        );
        self::assertSame(
            'meituan:trace-64381',
            $sourceRefs['meituan_traffic']['source_trace_id']
        );
    }

    public function testPreparedSnapshotMismatchBlocksScheduledDelivery(): void
    {
        $notificationId = $this->insertRecord([
            'notification_type' =>
                ManualNotificationService::OPERATING_DAILY_REPORT_TYPE,
            'template_type' =>
                ManualNotificationService::OPERATING_DAILY_REPORT_TYPE,
            'source_scope' => 'combined',
            'content_sections' =>
                'pms_summary,ctrip_traffic,meituan_traffic',
        ]);
        $this->seedBusinessContractTest(
            $notificationId,
            OperatingDailyReportPayloadService::RENDER_CONTRACT_VERSION
        );
        $sendCalls = 0;
        $service = new ManualNotificationScheduleService(
            sender: static function () use (&$sendCalls): array {
                $sendCalls++;
                return ['delivery_status' => 'sent'];
            },
            operatingDailyPayloads: $this->operatingDailyThreeSourcePayloads(),
            meituanTemporalRefresher: static fn(
                array $row,
                string $businessDate
            ): array => [
                'status' => 'ready',
                'target_date' => $businessDate,
                'sync_task_id' => 22,
                'saved_count' => 2,
                'readback_verified' => true,
            ],
            ctripTemporalRefresher: static fn(
                array $row,
                string $businessDate
            ): array => [
                'status' => 'ready',
                'target_date' => $businessDate,
                'sync_task_id' => 21,
                'saved_count' => 3,
                'readback_verified' => true,
            ],
            pmsSourceRefresher: static fn(
                array $row,
                string $businessDate
            ): array => [
                'status' => 'ready',
                'target_date' => $businessDate,
                'capture_id' => 999,
                'saved_count' => 1,
                'readback_verified' => true,
            ]
        );

        $result = $service->runDue(
            $this->time('2026-07-26 18:01:00'),
            true
        );

        self::assertSame(0, $sendCalls);
        self::assertSame('dispatch_blocked', $result['status']);
        self::assertSame('blocked', $result['results'][0]['status']);
        self::assertSame(
            'operating_daily_pms_prepared_snapshot_mismatch',
            $result['results'][0]['reason_code']
        );
        self::assertFalse(
            $result['results'][0]['prepared_snapshot_gate']['allowed']
        );
    }

    public function testSchedulerBuildsFutureRoomStatusFromDynamicBusinessFacts(): void
    {
        $notificationId = $this->insertRecord([
            'notification_type' => 'future_room_status',
            'template_type' => 'future_room_status',
            'title' => '保存时远期占位标题',
            'body' => '保存时远期占位正文。',
        ]);
        $this->seedBusinessContractTest($notificationId);
        $service = new ManualNotificationScheduleService(
            null,
            null,
            null,
            null,
            null,
            null,
            $this->businessPayloads()
        );

        $result = $service->runDue($this->time('2026-07-26 18:01:00'));

        self::assertSame('preview', $result['status']);
        self::assertSame(1, $result['due_count']);
        self::assertSame(
            'dispatch_not_requested',
            $result['results'][0]['reason_code']
        );
        $content = $result['results'][0]['payload']['markdown']['content'];
        foreach (['3天｜', '7天｜', '14天｜', '21天｜'] as $needle) {
            self::assertStringContainsString($needle, $content);
        }
        self::assertSame(0, substr_count($content, '｜订'));
        self::assertStringContainsString(
            '逐日/房型明细｜已保存21天',
            $content
        );
        self::assertStringNotContainsString('保存时远期占位正文', $content);
    }

    public function testLegacyStaticTestDoesNotAuthorizeNewBusinessPayloadContract(): void
    {
        $notificationId = $this->insertRecord([
            'notification_type' => 'future_room_status',
            'template_type' => 'future_room_status',
        ]);
        $this->seedBusinessContractTest(
            $notificationId,
            'operating_target_wecom.v1'
        );
        $service = new ManualNotificationScheduleService(
            null,
            null,
            null,
            null,
            null,
            null,
            $this->businessPayloads()
        );

        $result = $service->runDue($this->time('2026-07-26 18:01:00'));

        self::assertSame('preview', $result['status']);
        self::assertSame('blocked', $result['results'][0]['status']);
        self::assertSame(
            'business_message_retest_required',
            $result['results'][0]['reason_code']
        );
        self::assertNull($result['results'][0]['payload']);
    }

    public function testLegacyOperatingDailyContractRequiresRetestBeforeSchedule(): void
    {
        $notificationId = $this->insertRecord([
            'notification_type' => ManualNotificationService::OPERATING_DAILY_REPORT_TYPE,
            'template_type' => ManualNotificationService::OPERATING_DAILY_REPORT_TYPE,
            'source_scope' => 'dingdandao_pms',
            'content_sections' => 'pms_summary,pms_efficiency',
        ]);
        $this->seedBusinessContractTest(
            $notificationId,
            'operating_daily_pms_ota_wecom.v1'
        );
        $dailyPayloads = new OperatingDailyReportPayloadService(
            null,
            static function (int $tenantId, int $hotelId, string $date): array {
                $sourceUrl = \app\service\DingdandaoOperatingTargetCaptureService::SOURCE_URL;
                $sourceApiPath = '/api/verified';
                $providerHotelId = 'provider-hotel-' . $hotelId;
                $captureEvidence =
                    \app\service\DingdandaoOperatingTargetCaptureService::
                    expectedCaptureEvidence(
                        $sourceApiPath,
                        $date,
                        $providerHotelId,
                        'full_diagnostic'
                    );
                if (!is_array($captureEvidence)) {
                    throw new \RuntimeException(
                        'dingdandao_test_capture_evidence_invalid'
                    );
                }
                $sourceTraceId = (string)$captureEvidence['source_trace_id'];
                return [
                    'id' => 3,
                    'tenant_id' => $tenantId,
                    'hotel_id' => $hotelId,
                    'provider' => 'dingdandao_pms',
                    'provider_hotel_id' => $providerHotelId,
                    'provider_hotel_name' => '敦煌漠蓝新',
                    'business_date' => $date,
                    'source_url' => $sourceUrl,
                    'source_api_path' => $sourceApiPath,
                    'source_scope' => 'today_only',
                    'collection_mode' => 'full_diagnostic',
                    'capture_method' => 'network_response',
                    'capture_strategy' => 'verified_endpoint_recipe',
                    'capture_status' => 'verified',
                    'quality_status' => 'verified',
                    'readback_status' => 'readback_verified',
                    'identity_status' => 'matched',
                    'reconciliation_status' => 'matched',
                    'source_trace_id' => $sourceTraceId,
                    'source_fingerprint' => str_repeat('b', 64),
                    'detail_row_count' => 25,
                    'captured_at' => $date . ' 17:30:00',
                    'summary' => [
                        'total_room_fee' => 8745.66,
                        'sold_room_nights' => 15,
                        'average_daily_room_nights' => 15.0,
                        'derived_sellable_room_nights' => 15,
                        'occupancy_rate_percent' => 100,
                        'adr' => 583.04,
                        'revpar' => 583.04,
                    ],
                    'capture_evidence' => $captureEvidence,
                    'gaps' => [],
                ];
            },
            null,
            null,
            static fn(): array => [
                'configured' => true,
                'expected_provider_hotel_id' => 'provider-hotel-80',
                'expected_provider_hotel_name' => '敦煌漠蓝新',
            ]
        );
        $service = new ManualNotificationScheduleService(
            null,
            null,
            null,
            null,
            null,
            $dailyPayloads
        );

        $result = $service->runDue($this->time('2026-07-26 18:01:00'));

        self::assertSame('blocked', $result['results'][0]['status']);
        self::assertSame(
            'business_message_retest_required',
            $result['results'][0]['reason_code']
        );
        self::assertNull($result['results'][0]['payload']);
    }

    public function testDifferentBusinessTemplateTestDoesNotAuthorizeCurrentTemplate(): void
    {
        $notificationId = $this->insertRecord([
            'notification_type' => 'future_room_status',
            'template_type' => 'future_room_status',
        ]);
        $this->seedBusinessContractTest(
            $notificationId,
            ManualNotificationBusinessPayloadService::RENDER_CONTRACT_VERSIONS[
                'today_revenue_management'
            ]
        );
        $service = new ManualNotificationScheduleService(
            null,
            null,
            null,
            null,
            null,
            null,
            $this->businessPayloads()
        );

        $result = $service->runDue($this->time('2026-07-26 18:01:00'));

        self::assertSame('preview', $result['status']);
        self::assertSame('blocked', $result['results'][0]['status']);
        self::assertSame(
            'business_message_retest_required',
            $result['results'][0]['reason_code']
        );
    }

    public function testBusinessTemplateChangeAfterTestRequiresRetest(): void
    {
        $notificationId = $this->insertRecord([
            'notification_type' => 'future_room_status',
            'template_type' => 'future_room_status',
            'update_time' => '2026-07-26 17:00:02',
        ]);
        $this->seedBusinessContractTest($notificationId);
        $service = new ManualNotificationScheduleService(
            null,
            null,
            null,
            null,
            null,
            null,
            $this->businessPayloads()
        );

        $result = $service->runDue($this->time('2026-07-26 18:01:00'));

        self::assertSame('preview', $result['status']);
        self::assertSame('blocked', $result['results'][0]['status']);
        self::assertSame(
            'manual_notification_schedule_test_evidence_invalid',
            $result['results'][0]['reason_code']
        );
    }

    public function testSchedulerEnforcesDateRulesWeekdaysAndHourlyWindow(): void
    {
        $this->insertRecord([
            'trigger_type' => 'hourly_on_the_hour',
            'planned_send_at' => null,
            'business_date_rule' => 'yesterday',
            'active_weekdays' => '7',
            'effective_from' => '2026-07-26',
            'effective_to' => '2026-07-26',
            'hourly_start_time' => '09:00:00',
            'hourly_end_time' => '22:00:00',
        ]);
        $service = new ManualNotificationScheduleService();

        $outside = $service->runDue($this->time('2026-07-26 23:00:20'));
        self::assertSame(0, $outside['due_count']);

        $inside = $service->runDue($this->time('2026-07-26 18:00:20'));
        self::assertSame(1, $inside['due_count']);
        self::assertSame('2026-07-25', $inside['results'][0]['business_date']);
        self::assertStringContainsString(
            '业务日期：2026-07-25',
            $inside['results'][0]['payload']['markdown']['content']
        );
    }

    public function testSchedulerSelectsMinuteIntervalPlansAtTheirExactBucket(): void
    {
        $this->insertRecord([
            'trigger_type' => 'interval_minutes',
            'interval_minutes' => 30,
            'planned_send_at' => null,
            'hourly_start_time' => '09:15:00',
            'hourly_end_time' => '11:45:00',
        ]);

        $result = (new ManualNotificationScheduleService())
            ->runDue($this->time('2026-07-26 10:45:20'));

        self::assertSame(1, $result['due_count']);
        self::assertSame('2026-07-26 10:45', $result['results'][0]['dispatch_window']);
        self::assertSame('interval_minutes', $result['results'][0]['trigger_type']);
    }

    public function testLegacyOperatingDailyLoopsAreBlockedAndReportedBeforeDelivery(): void
    {
        $this->insertRecord([
            'notification_type' =>
                ManualNotificationService::OPERATING_DAILY_REPORT_TYPE,
            'template_type' =>
                ManualNotificationService::OPERATING_DAILY_REPORT_TYPE,
            'trigger_type' => 'interval_minutes',
            'interval_minutes' => 30,
            'planned_send_at' => null,
            'hourly_start_time' => '09:00:00',
            'hourly_end_time' => '23:59:00',
        ]);
        $this->insertRecord([
            'notification_type' =>
                ManualNotificationService::OPERATING_DAILY_REPORT_TYPE,
            'template_type' =>
                ManualNotificationService::OPERATING_DAILY_CUSTOM_REPORT_TYPE,
            'trigger_type' => 'hourly_on_the_hour',
            'interval_minutes' => null,
            'planned_send_at' => null,
            'hourly_start_time' => '09:00:00',
            'hourly_end_time' => '22:00:00',
        ]);
        $freshId = $this->insertRecord([
            'planned_send_at' => '2026-07-26 10:00:00',
        ]);
        $sendCalls = 0;
        $sourceRefreshCalls = 0;
        $service = new ManualNotificationScheduleService(
            sender: static function () use (&$sendCalls): array {
                $sendCalls++;
                return ['delivery_status' => 'sent'];
            },
            meituanTemporalRefresher: static function () use (
                &$sourceRefreshCalls
            ): array {
                $sourceRefreshCalls++;
                return ['status' => 'ready', 'readback_verified' => true];
            },
            ctripTemporalRefresher: static function () use (
                &$sourceRefreshCalls
            ): array {
                $sourceRefreshCalls++;
                return ['status' => 'ready', 'readback_verified' => true];
            }
        );

        $result = $service->runDue(
            $this->time('2026-07-26 10:00:20'),
            true,
            ManualNotificationScheduleService::MODE_TEST,
            1
        );

        self::assertSame('dispatch_blocked', $result['status']);
        self::assertSame(3, $result['due_count']);
        self::assertSame(2, $result['blocked_count']);
        self::assertSame(1, $result['sent_count']);
        self::assertSame(1, $sendCalls);
        self::assertSame(0, $sourceRefreshCalls);
        foreach (array_slice($result['results'], 0, 2) as $blocked) {
            self::assertSame('blocked', $blocked['status']);
            self::assertSame(
                'operating_daily_fixed_time_required',
                $blocked['reason_code']
            );
            self::assertFalse($blocked['delivery_attempted']);
        }
        self::assertSame($freshId, $result['results'][2]['notification_id']);
        self::assertSame('sent', $result['results'][2]['status']);
        self::assertSame(
            1,
            Db::name('manual_notification_schedule_dispatches')->count()
        );
        $run = Db::name('manual_notification_schedule_runs')
            ->where('id', (int)$result['schedule_run_id'])
            ->find();
        self::assertSame('blocked', $run['status']);
        self::assertSame(2, (int)$run['blocked_count']);
        self::assertSame(1, (int)$run['sent_count']);
        $scope = Db::name('manual_notification_schedule_run_scopes')
            ->where('schedule_run_id', (int)$result['schedule_run_id'])
            ->find();
        self::assertSame('blocked', $scope['status']);
        self::assertSame(2, (int)$scope['blocked_count']);
        self::assertSame(1, (int)$scope['sent_count']);
    }

    public function testExplicitDispatchUsesFakeSenderAndIsIdempotentPerWindowAndMode(): void
    {
        $notificationId = $this->insertRecord();
        $calls = [];
        $service = new ManualNotificationScheduleService(
            static function (int $hotelId, int $robotId, array $payload, array $context) use (&$calls): array {
                $calls[] = [$hotelId, $robotId, $payload, $context];
                return ['delivery_status' => 'sent'];
            }
        );
        $now = $this->time('2026-07-26 18:02:00');

        $first = $service->runDue($now, true);
        $second = $service->runDue($now, true);

        self::assertSame('sent', $first['results'][0]['status']);
        self::assertSame('skipped', $second['results'][0]['status']);
        self::assertSame('dispatch_window_already_claimed', $second['results'][0]['reason_code']);
        self::assertCount(1, $calls);
        self::assertSame([80, 1], array_slice($calls[0], 0, 2));
        self::assertSame($notificationId, $calls[0][3]['notification_id']);
        self::assertSame(1, Db::name('manual_notification_schedule_dispatches')->count());
        self::assertSame(
            $first['schedule_run_id'],
            (int)Db::name('manual_notification_schedule_dispatches')->value('schedule_run_id')
        );
        self::assertSame(
            $first['schedule_run_id'],
            $first['results'][0]['schedule_run_id']
        );
    }

    public function testClaimedWindowDoesNotConsumeTheNewDispatchLimit(): void
    {
        $claimedId = $this->insertRecord();
        $freshId = $this->insertRecord();
        $ledger = new ManualNotificationDispatchLedgerService();
        $ledger->claim(
            $claimedId,
            9,
            80,
            '2026-07-26 18:00',
            ManualNotificationScheduleService::MODE_TEST,
            'daily_fixed_time',
            'scheduled',
            ManualNotificationService::TEST_ROBOT_ID,
            ManualNotificationService::TEST_ROBOT_NAME,
            '2026-07-26',
            [
                'payload' => [
                    'msgtype' => 'text',
                    'text' => ['content' => 'already claimed'],
                ],
            ],
            $this->time('2026-07-26 18:01:00')
        );
        $calls = [];
        $service = new ManualNotificationScheduleService(
            static function (
                int $hotelId,
                int $robotId,
                array $payload,
                array $context
            ) use (&$calls): array {
                $calls[] = $context['notification_id'] ?? 0;
                return ['delivery_status' => 'sent'];
            },
            ledger: $ledger
        );

        $result = $service->runDue(
            $this->time('2026-07-26 18:02:00'),
            true,
            ManualNotificationScheduleService::MODE_TEST,
            1
        );

        self::assertSame(2, $result['due_count']);
        self::assertCount(2, $result['results']);
        self::assertSame($claimedId, $result['results'][0]['notification_id']);
        self::assertSame('skipped', $result['results'][0]['status']);
        self::assertSame(
            'dispatch_window_already_claimed',
            $result['results'][0]['reason_code']
        );
        self::assertSame($freshId, $result['results'][1]['notification_id']);
        self::assertSame('sent', $result['results'][1]['status']);
        self::assertSame([$freshId], $calls);
        self::assertSame(
            2,
            Db::name('manual_notification_schedule_dispatches')->count()
        );
    }

    public function testEnabledRowWithoutCurrentSuccessfulTestIsBlockedAtRuntime(): void
    {
        $neverTestedId = $this->insertRecord([
            'last_test_status' => 'never_tested',
            'last_tested_at' => null,
        ]);
        $staleTestId = $this->insertRecord([
            'last_test_status' => 'sent',
            'last_tested_at' => '2026-07-26 11:59:59',
            'update_time' => '2026-07-26 12:00:00',
        ]);
        $calls = 0;
        $service = new ManualNotificationScheduleService(
            static function () use (&$calls): array {
                $calls++;
                return ['delivery_status' => 'sent'];
            }
        );

        $result = $service->runDue(
            $this->time('2026-07-26 18:02:00'),
            true
        );

        self::assertSame('dispatch_blocked', $result['status']);
        self::assertSame(2, $result['blocked_count']);
        self::assertSame(0, $calls);
        self::assertSame(
            [$neverTestedId, $staleTestId],
            array_column($result['results'], 'notification_id')
        );
        foreach ($result['results'] as $blocked) {
            self::assertSame('blocked', $blocked['status']);
            self::assertSame(
                'manual_notification_schedule_test_evidence_invalid',
                $blocked['reason_code']
            );
            self::assertFalse($blocked['delivery_attempted']);
        }
    }

    public function testClaimedCtripWindowSkipsRefreshBeforeAnyBrowserWork(): void
    {
        $notificationId = $this->insertRecord([
            'notification_type' =>
                ManualNotificationService::CTRIP_TEMPORAL_REPORT_TYPE,
            'template_type' =>
                ManualNotificationService::CTRIP_TEMPORAL_REPORT_TYPE,
            'source_scope' => 'ctrip',
        ]);
        $ledger = new ManualNotificationDispatchLedgerService();
        $ledger->claim(
            $notificationId,
            9,
            80,
            '2026-07-26 18:00',
            ManualNotificationScheduleService::MODE_TEST,
            'daily_fixed_time',
            'scheduled',
            ManualNotificationService::TEST_ROBOT_ID,
            ManualNotificationService::TEST_ROBOT_NAME,
            '2026-07-26',
            [
                'payload' => [
                    'msgtype' => 'text',
                    'text' => ['content' => 'already claimed'],
                ],
            ],
            $this->time('2026-07-26 18:01:00')
        );
        $refreshCalls = 0;
        $service = new ManualNotificationScheduleService(
            null,
            null,
            $ledger,
            null,
            null,
            null,
            null,
            null,
            null,
            static function () use (&$refreshCalls): array {
                $refreshCalls++;
                return [];
            }
        );

        $result = $service->runDue(
            $this->time('2026-07-26 18:02:00'),
            true
        );

        self::assertSame('skipped', $result['results'][0]['status']);
        self::assertSame(
            'dispatch_window_already_claimed',
            $result['results'][0]['reason_code']
        );
        self::assertSame(0, $refreshCalls);
    }

    public function testScheduleRunEvidenceIsReadBackOnlyForTheExactScope(): void
    {
        $this->insertRecord();
        $service = new ManualNotificationScheduleService();
        $run = $service->runDue(
            $this->time('2026-07-26 18:02:00'),
            false,
            ManualNotificationScheduleService::MODE_TEST,
            100,
            80,
            1
        );
        Db::name('manual_notification_schedule_runs')->insert([
            'scope_tenant_id' => 10,
            'runner_mode' => 'formal',
            'dispatch_requested' => 1,
            'scope_hotel_id' => 81,
            'scope_robot_id' => 27,
            'observed_at' => '2026-07-26 18:03:00',
            'status' => 'completed',
            'candidate_count' => 1,
            'due_count' => 1,
            'sent_count' => 1,
            'failed_count' => 0,
            'blocked_count' => 0,
            'result_summary_json' => '{}',
            'started_at' => '2026-07-26 18:03:00',
            'finished_at' => '2026-07-26 18:03:01',
            'create_time' => '2026-07-26 18:03:00',
            'update_time' => '2026-07-26 18:03:01',
        ]);

        $ledger = new ManualNotificationDispatchLedgerService();
        $scoped = $ledger->latestScheduleRun(9, 80, 1);
        self::assertSame($run['schedule_run_id'], $scoped['run_id']);
        self::assertSame(9, $scoped['scope_tenant_id']);
        self::assertSame(80, $scoped['scope_hotel_id']);
        self::assertSame(1, $scoped['scope_robot_id']);
        self::assertSame(
            $run['schedule_run_id'],
            $ledger->latestScheduleRun(9, 80, 1, 'test')['run_id']
        );
        self::assertSame(
            'not_run',
            $ledger->latestScheduleRun(9, 80, 1, 'formal')['status']
        );

        $wrongRobot = $ledger->latestScheduleRun(9, 80, 27);
        self::assertSame('not_run', $wrongRobot['status']);
    }

    public function testGlobalRunIsScopedByItsExactDispatchLink(): void
    {
        $this->insertRecord();
        $service = new ManualNotificationScheduleService(
            static fn(): array => ['delivery_status' => 'sent']
        );
        $run = $service->runDue(
            $this->time('2026-07-26 18:02:00'),
            true,
            ManualNotificationScheduleService::MODE_TEST
        );

        $scoped = (new ManualNotificationDispatchLedgerService())->latestScheduleRun(9, 80, 1);
        self::assertSame($run['schedule_run_id'], $scoped['run_id']);
        self::assertSame('dispatch_link', $scoped['scope_source']);
        self::assertSame(9, $scoped['scope_tenant_id']);
        self::assertSame(80, $scoped['scope_hotel_id']);
        self::assertSame(1, $scoped['scope_robot_id']);

        $otherHotel = (new ManualNotificationDispatchLedgerService())->latestScheduleRun(9, 81, 1);
        self::assertSame('not_run', $otherHotel['status']);
    }

    public function testGlobalRunReadbackAggregatesOnlyTheRequestedDispatchScope(): void
    {
        Db::name('hotels')->insert(['id' => 81, 'tenant_id' => 10, 'name' => 'Other hotel']);
        Db::name('competitor_wechat_robot')->insert([
            'id' => 27,
            'store_id' => 81,
            'name' => 'Other formal robot',
            'status' => 1,
            'owner_user_id' => null,
            'notification_scope' => 'admin_shared',
        ]);
        $runId = (int)Db::name('manual_notification_schedule_runs')->insertGetId([
            'scope_tenant_id' => null,
            'runner_mode' => 'formal',
            'dispatch_requested' => 1,
            'scope_hotel_id' => null,
            'scope_robot_id' => null,
            'observed_at' => date('Y-m-d H:i:s'),
            'status' => 'failed',
            'candidate_count' => 2,
            'due_count' => 2,
            'sent_count' => 1,
            'failed_count' => 1,
            'blocked_count' => 0,
            'result_summary_json' => '{}',
            'started_at' => date('Y-m-d H:i:s'),
            'finished_at' => date('Y-m-d H:i:s'),
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ]);
        $notificationA = $this->insertRecord([
            'send_method' => 'wecom_formal',
            'test_robot_id' => 1,
        ]);
        $notificationB = $this->insertRecord([
            'tenant_id' => 10,
            'hotel_id' => 81,
            'send_method' => 'wecom_formal',
            'test_robot_id' => 27,
        ]);
        $base = [
            'schedule_run_id' => $runId,
            'dispatch_window' => '2026-07-28 03:00',
            'delivery_mode' => 'formal',
            'trigger_type' => 'daily_fixed_time',
            'request_kind' => 'scheduled',
            'business_date' => '2026-07-28',
            'payload_fingerprint' => str_repeat('a', 64),
            'attempt_count' => 1,
            'max_attempts' => 3,
            'next_retry_at' => null,
            'last_attempt_at' => date('Y-m-d H:i:s'),
            'response_reference' => null,
            'claimed_at' => date('Y-m-d H:i:s'),
            'dispatched_at' => date('Y-m-d H:i:s'),
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ];
        Db::name('manual_notification_schedule_dispatches')->insert(array_replace($base, [
            'notification_id' => $notificationA,
            'tenant_id' => 9,
            'hotel_id' => 80,
            'robot_id' => 1,
            'robot_name' => ManualNotificationService::TEST_ROBOT_NAME,
            'status' => 'sent',
            'result_code' => 'sent',
            'result_message' => null,
        ]));
        Db::name('manual_notification_schedule_dispatches')->insert(array_replace($base, [
            'notification_id' => $notificationB,
            'tenant_id' => 10,
            'hotel_id' => 81,
            'robot_id' => 27,
            'robot_name' => 'Other formal robot',
            'status' => 'failed',
            'result_code' => 'delivery_failed',
            'result_message' => 'network failure',
        ]));

        $ledger = new ManualNotificationDispatchLedgerService();
        $hotelA = $ledger->latestScheduleRun(9, 80, 1);
        $hotelB = $ledger->latestScheduleRun(10, 81, 27);

        self::assertSame('dispatch_link', $hotelA['scope_source']);
        self::assertSame('formal_scope_ready', $hotelA['status']);
        self::assertSame('completed', $hotelA['run_status']);
        self::assertSame(1, $hotelA['candidate_count']);
        self::assertSame(1, $hotelA['due_count']);
        self::assertSame(1, $hotelA['sent_count']);
        self::assertSame(0, $hotelA['failed_count']);
        self::assertSame('failed', $hotelB['status']);
        self::assertSame(0, $hotelB['sent_count']);
        self::assertSame(1, $hotelB['failed_count']);
    }

    public function testEveryGlobalRunPersistsAnExactPlanScopeHeartbeatWhenNothingIsDue(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
        $this->insertRecord([
            'planned_send_at' => $now->modify('-30 minutes')->format('Y-m-d H:i:s'),
        ]);

        $run = (new ManualNotificationScheduleService())->runDue(
            $now,
            true,
            ManualNotificationScheduleService::MODE_TEST
        );
        $scoped = (new ManualNotificationDispatchLedgerService())->latestScheduleRun(9, 80, 1);

        self::assertSame(0, $run['due_count']);
        self::assertSame($run['schedule_run_id'], $scoped['run_id']);
        self::assertSame('plan_observation', $scoped['scope_source']);
        self::assertSame('test_scope_ready', $scoped['status']);
        self::assertSame(1, $scoped['candidate_count']);
        self::assertSame(0, $scoped['due_count']);
        self::assertSame(0, $scoped['sent_count']);
        self::assertSame(0, $scoped['failed_count']);
        self::assertSame(0, $scoped['blocked_count']);
    }

    public function testTestAndFormalPlansUseSeparatePersistedRobotScopes(): void
    {
        $testNotificationId = $this->insertRecord();
        Db::name('competitor_wechat_robot')->insertAll([
            [
                'id' => 27,
                'store_id' => 80,
                'name' => '正式经营群',
                'status' => 1,
                'owner_user_id' => null,
                'notification_scope' => 'admin_shared',
            ],
            [
                'id' => 28,
                'store_id' => 80,
                'name' => '其他账号群',
                'status' => 1,
                'owner_user_id' => 99,
                'notification_scope' => 'account_onboarding',
            ],
        ]);
        $formalNotificationId = $this->insertRecord([
            'notification_type' => 'anomaly_alert',
            'template_type' => 'anomaly_alert',
            'body' => "业务服务异常编号：A-17\n异常正文：价格规则未回读",
            'send_method' => 'wecom_formal',
            'test_robot_id' => 27,
            'test_robot_name' => '正式经营群',
        ]);
        $this->insertRecord([
            'notification_type' => 'task_notification',
            'template_type' => 'task_notification',
            'send_method' => 'wecom_formal',
            'test_robot_id' => 28,
            'test_robot_name' => '其他账号群',
        ]);
        $calls = [];
        $service = new ManualNotificationScheduleService(
            static function (int $hotelId, int $robotId, array $payload, array $context) use (&$calls): array {
                $calls[] = [$hotelId, $robotId, $payload, $context];
                return ['delivery_status' => 'sent'];
            }
        );

        $formal = $service->runDue($this->time('2026-07-26 18:01:00'), true, 'formal');
        self::assertSame('dispatch_blocked', $formal['status']);
        self::assertSame(1, $formal['sent_count']);
        self::assertSame(1, $formal['blocked_count']);
        self::assertSame('sent', $formal['results'][0]['status']);
        self::assertSame($formalNotificationId, $formal['results'][0]['notification_id']);
        self::assertSame('target_robot_scope_mismatch', $formal['results'][1]['reason_code']);
        self::assertSame([80, 27], array_slice($calls[0], 0, 2));
        self::assertSame('formal', $calls[0][3]['mode']);
        self::assertStringContainsString('企业微信正式群定时真实投递', $calls[0][2]['markdown']['content']);
        self::assertStringContainsString('异常正文：价格规则未回读', $calls[0][2]['markdown']['content']);

        $test = $service->runDue($this->time('2026-07-26 18:01:00'), true, 'test');
        self::assertSame('sent', $test['results'][0]['status']);
        self::assertSame($testNotificationId, $test['results'][0]['notification_id']);
        self::assertSame([80, 1], array_slice($calls[1], 0, 2));
        self::assertSame('test', $calls[1][3]['mode']);
        self::assertSame(3, Db::name('manual_notification_schedule_dispatches')->count());
    }

    public function testFailedClaimIsNotAutomaticallyResentAndMissingFactsStayExplicit(): void
    {
        $this->insertRecord([
            'body' => "房量：未取得\n建议：待配置",
        ]);
        $calls = [];
        $service = new ManualNotificationScheduleService(
            static function (int $hotelId, int $robotId, array $payload) use (&$calls): array {
                $calls[] = $payload;
                return ['delivery_status' => 'failed'];
            }
        );
        $now = $this->time('2026-07-26 18:04:00');

        $first = $service->runDue($now, true);
        $second = $service->runDue($now, true);

        self::assertSame('failed', $first['results'][0]['status']);
        self::assertSame('failed', $second['results'][0]['status']);
        self::assertFalse($second['results'][0]['delivery_attempted']);
        self::assertCount(1, $calls);
        self::assertStringContainsString('房量：未取得', $calls[0]['markdown']['content']);
        self::assertStringNotContainsString('房量：0', $calls[0]['markdown']['content']);
        self::assertSame(
            'failed',
            Db::name('manual_notification_schedule_dispatches')->value('status')
        );
    }

    public function testExpiredSendingLeaseBecomesOutcomeUnknownWithoutAutomaticResend(): void
    {
        $notificationId = $this->insertRecord();
        $ledger = new ManualNotificationDispatchLedgerService();
        $startedAt = $this->time('2026-07-26 18:00:00');
        $claim = $ledger->claim(
            $notificationId,
            9,
            80,
            '2026-07-26 18:00',
            ManualNotificationScheduleService::MODE_TEST,
            'daily_fixed_time',
            'scheduled',
            ManualNotificationService::TEST_ROBOT_ID,
            ManualNotificationService::TEST_ROBOT_NAME,
            '2026-07-26',
            [
                'payload' => [
                    'msgtype' => 'markdown',
                    'markdown' => ['content' => 'lease recovery test'],
                ],
            ],
            $startedAt
        );
        $attempt = $ledger->beginAttempt((int)$claim['dispatch']['id'], $startedAt);
        self::assertTrue($attempt['allowed']);

        $calls = [];
        $service = new ManualNotificationScheduleService(
            static function () use (&$calls): array {
                $calls[] = true;
                return ['delivery_status' => 'sent'];
            },
            null,
            $ledger
        );
        $result = $service->runDue($this->time('2026-07-26 18:10:00'), true);

        self::assertSame('dispatch_failed', $result['status']);
        self::assertSame(1, $result['recovered_unknown_count']);
        self::assertSame(0, $result['due_count']);
        self::assertSame([], $result['results']);
        self::assertSame(
            'outcome_unknown',
            Db::name('manual_notification_schedule_dispatches')
                ->where('id', (int)$claim['dispatch']['id'])
                ->value('status')
        );
        self::assertSame(
            'delivery_attempt_lease_expired_outcome_unknown',
            Db::name('manual_notification_schedule_dispatches')
                ->where('id', (int)$claim['dispatch']['id'])
                ->value('result_code')
        );
        self::assertSame([], $calls, 'An expired in-flight result must never be resent automatically.');
        self::assertSame(
            'outcome_unknown',
            Db::name('manual_notification_dispatch_attempts')
                ->where('id', (int)$attempt['attempt_id'])
                ->value('status')
        );

        $late = $ledger->finishAttempt(
            (int)$claim['dispatch']['id'],
            (int)$attempt['attempt_id'],
            ['delivery_status' => 'sent', 'response_reference' => 'late:receipt'],
            $this->time('2026-07-26 18:04:00')
        );
        self::assertSame(
            'outcome_unknown',
            $late['status'],
            'A late result from the expired process must not overwrite the reconciled state.'
        );
        self::assertTrue($late['retryable']);
        self::assertTrue($late['retry_requires_confirmation']);
        self::assertTrue($late['retry_may_duplicate']);
        self::assertFalse($late['automatic_retry_allowed']);
    }

    public function testContradictorySuccessFlagCannotOverrideExplicitFailedDeliveryStatus(): void
    {
        $notificationId = $this->insertRecord();
        $ledger = new ManualNotificationDispatchLedgerService();
        $startedAt = $this->time('2026-07-26 18:00:00');
        $claim = $ledger->claim(
            $notificationId,
            9,
            80,
            '2026-07-26 18:00',
            ManualNotificationScheduleService::MODE_TEST,
            'daily_fixed_time',
            'scheduled',
            ManualNotificationService::TEST_ROBOT_ID,
            ManualNotificationService::TEST_ROBOT_NAME,
            '2026-07-26',
            [
                'payload' => [
                    'msgtype' => 'markdown',
                    'markdown' => ['content' => 'contradictory receipt test'],
                ],
                'source_snapshot_refs' => [
                    'pms' => [
                        'source' => 'dingdandao_pms',
                        'record_id' => 31,
                        'business_date' => '2026-07-26',
                        'source_scope' => 'historical_single_date',
                        'capture_method' => 'network_response',
                        'source_trace_id' => 'dingdandao:trace-31',
                    ],
                    'meituan_traffic' => [
                        'source' => 'meituan',
                        'record_id' => 41,
                        'business_date' => '2026-07-26',
                        'data_source_id' => 8,
                        'sync_task_id' => 12,
                        'source_trace_id' => 'meituan:trace-41',
                    ],
                ],
            ],
            $startedAt
        );
        self::assertSame(
            31,
            $claim['dispatch']['source_snapshot_refs']['pms']['record_id']
        );
        self::assertSame(
            12,
            $claim['dispatch']['source_snapshot_refs']['meituan_traffic']['sync_task_id']
        );
        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            (string)$claim['dispatch']['source_snapshot_fingerprint']
        );
        $attempt = $ledger->beginAttempt((int)$claim['dispatch']['id'], $startedAt);

        $finished = $ledger->finishAttempt(
            (int)$claim['dispatch']['id'],
            (int)$attempt['attempt_id'],
            [
                'delivery_status' => 'failed',
                'success' => true,
                'reason' => 'provider status contradicted the generic success flag',
            ],
            $startedAt->modify('+1 second')
        );

        self::assertSame('outcome_unknown', $finished['status']);
        self::assertSame('wecom_delivery_outcome_unknown', $finished['result_code']);
        self::assertNotSame('wecom_business_success', $finished['result_code']);
        self::assertFalse($finished['automatic_retry_allowed']);
    }

    public function testSentStatusWithFailureSignalsIsOutcomeUnknown(): void
    {
        $notificationId = $this->insertRecord();
        $ledger = new ManualNotificationDispatchLedgerService();
        $startedAt = $this->time('2026-07-26 18:00:00');
        $claim = $ledger->claim(
            $notificationId,
            9,
            80,
            'i:contradictory-sent',
            ManualNotificationScheduleService::MODE_TEST,
            'manual_test',
            'immediate_test',
            ManualNotificationService::TEST_ROBOT_ID,
            ManualNotificationService::TEST_ROBOT_NAME,
            '2026-07-26',
            [
                'payload' => [
                    'msgtype' => 'text',
                    'text' => ['content' => 'receipt contract'],
                ],
            ],
            $startedAt
        );
        $attempt = $ledger->beginAttempt(
            (int)$claim['dispatch']['id'],
            $startedAt
        );
        $finished = $ledger->finishAttempt(
            (int)$claim['dispatch']['id'],
            (int)$attempt['attempt_id'],
            [
                'delivery_status' => 'sent',
                'success' => false,
                'failed_count' => 1,
                'sent_count' => 0,
                'robot_count' => 1,
            ],
            $startedAt->modify('+1 second')
        );

        self::assertSame('outcome_unknown', $finished['status']);
        self::assertSame(
            'wecom_delivery_success_contradictory',
            $finished['result_code']
        );
        self::assertFalse($finished['automatic_retry_allowed']);
    }

    public function testSourceSnapshotTamperingBlocksFirstAttemptAndRetry(): void
    {
        $notificationId = $this->insertRecord();
        $ledger = new ManualNotificationDispatchLedgerService();
        $startedAt = $this->time('2026-07-26 18:00:00');
        $candidate = [
            'payload' => [
                'msgtype' => 'text',
                'text' => ['content' => 'source integrity'],
            ],
            'source_snapshot_refs' => [
                'pms' => [
                    'source' => 'dingdandao_pms',
                    'record_id' => 31,
                    'business_date' => '2026-07-26',
                    'source_trace_id' => 'dingdandao:trace-31',
                ],
            ],
        ];
        $first = $ledger->claim(
            $notificationId,
            9,
            80,
            'i:source-integrity-first',
            ManualNotificationScheduleService::MODE_TEST,
            'manual_test',
            'immediate_test',
            ManualNotificationService::TEST_ROBOT_ID,
            ManualNotificationService::TEST_ROBOT_NAME,
            '2026-07-26',
            $candidate,
            $startedAt
        );
        Db::name('manual_notification_schedule_dispatches')
            ->where('id', (int)$first['dispatch']['id'])
            ->update([
                'source_snapshot_refs_json' => json_encode([
                    'pms' => [
                        'source' => 'dingdandao_pms',
                        'record_id' => 999,
                        'business_date' => '2026-07-26',
                        'source_trace_id' => 'dingdandao:trace-31',
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        $blockedAttempt = $ledger->beginAttempt(
            (int)$first['dispatch']['id'],
            $startedAt
        );
        self::assertFalse($blockedAttempt['allowed']);
        self::assertSame(
            'dispatch_source_snapshot_integrity_mismatch',
            $blockedAttempt['reason_code']
        );
        self::assertSame(
            0,
            Db::name('manual_notification_dispatch_attempts')
                ->where('dispatch_id', (int)$first['dispatch']['id'])
                ->count()
        );

        $retry = $ledger->claim(
            $notificationId,
            9,
            80,
            'i:source-integrity-retry',
            ManualNotificationScheduleService::MODE_TEST,
            'manual_test',
            'immediate_test',
            ManualNotificationService::TEST_ROBOT_ID,
            ManualNotificationService::TEST_ROBOT_NAME,
            '2026-07-26',
            $candidate,
            $startedAt
        );
        $attempt = $ledger->beginAttempt(
            (int)$retry['dispatch']['id'],
            $startedAt
        );
        $failed = $ledger->finishAttempt(
            (int)$retry['dispatch']['id'],
            (int)$attempt['attempt_id'],
            [
                'delivery_status' => 'failed',
                'error' => 'provider_rejected',
            ],
            $startedAt->modify('+1 second')
        );
        self::assertTrue($failed['retryable']);
        Db::name('manual_notification_schedule_dispatches')
            ->where('id', (int)$retry['dispatch']['id'])
            ->update(['source_snapshot_refs_json' => '{"pms":']);
        $corrupt = $ledger->existingDispatch(
            $notificationId,
            'i:source-integrity-retry',
            ManualNotificationScheduleService::MODE_TEST
        );
        self::assertSame(
            'invalid_json',
            $corrupt['source_snapshot_integrity_status']
        );
        self::assertFalse($corrupt['retryable']);
        try {
            $ledger->dispatchForRetry(
                9,
                80,
                (int)$retry['dispatch']['id'],
                $startedAt->modify('+2 minutes')
            );
            self::fail('Tampered source evidence must block retry.');
        } catch (\RuntimeException $error) {
            self::assertSame(
                'manual_notification_retry_source_snapshot_integrity_failed',
                $error->getMessage()
            );
        }
    }

    public function testOperatingDailyReadyCandidateRequiresSourceReferences(): void
    {
        $notificationId = $this->insertRecord();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'manual_notification_dispatch_source_snapshot_refs_required'
        );
        (new ManualNotificationDispatchLedgerService())->claim(
            $notificationId,
            9,
            80,
            'i:missing-source-refs',
            ManualNotificationScheduleService::MODE_TEST,
            'manual_test',
            'immediate_test',
            ManualNotificationService::TEST_ROBOT_ID,
            ManualNotificationService::TEST_ROBOT_NAME,
            '2026-07-26',
            [
                'payload' => [
                    'msgtype' => 'text',
                    'text' => ['content' => 'missing refs'],
                ],
                'render_contract_version' =>
                    OperatingDailyReportPayloadService::
                    RENDER_CONTRACT_VERSION,
            ],
            $this->time('2026-07-26 18:00:00')
        );
    }

    public function testFreshPreparationClaimPreventsDuplicateWork(): void
    {
        $notificationId = $this->insertRecord();
        $ledger = new ManualNotificationDispatchLedgerService();
        $now = $this->time('2026-07-26 18:01:00');
        $claim = $ledger->claim(
            $notificationId,
            9,
            80,
            '2026-07-26 18:00',
            ManualNotificationScheduleService::MODE_TEST,
            'daily_fixed_time',
            'scheduled',
            ManualNotificationService::TEST_ROBOT_ID,
            ManualNotificationService::TEST_ROBOT_NAME,
            '2026-07-26',
            [],
            $now,
            'claimed',
            'dispatch_source_preparation_claimed'
        );
        self::assertTrue($claim['claimed']);
        $sendCalls = 0;
        $result = (new ManualNotificationScheduleService(
            sender: static function () use (&$sendCalls): array {
                $sendCalls++;
                return ['delivery_status' => 'sent'];
            },
            ledger: $ledger
        ))->runDue($now, true);

        self::assertSame(0, $sendCalls);
        self::assertSame(1, $result['due_count']);
        self::assertSame('skipped', $result['results'][0]['status']);
        self::assertSame(
            'claimed',
            $result['results'][0]['existing_status']
        );
        self::assertFalse($result['results'][0]['delivery_attempted']);
        self::assertSame(
            (int)$claim['dispatch']['id'],
            (int)$result['results'][0]['dispatch_id']
        );
    }

    public function testExpiredPreparationClaimIsReclaimedBeforeSending(): void
    {
        $notificationId = $this->insertRecord();
        $ledger = new ManualNotificationDispatchLedgerService();
        $claim = $ledger->claim(
            $notificationId,
            9,
            80,
            '2026-07-26 18:00',
            ManualNotificationScheduleService::MODE_TEST,
            'daily_fixed_time',
            'scheduled',
            ManualNotificationService::TEST_ROBOT_ID,
            ManualNotificationService::TEST_ROBOT_NAME,
            '2026-07-26',
            [],
            $this->time('2026-07-26 17:55:00'),
            'claimed',
            'dispatch_source_preparation_claimed'
        );
        $sendCalls = 0;
        $result = (new ManualNotificationScheduleService(
            sender: static function () use (&$sendCalls): array {
                $sendCalls++;
                return [
                    'delivery_status' => 'sent',
                    'response_reference' => 'wecom:errcode=0',
                ];
            },
            ledger: $ledger
        ))->runDue($this->time('2026-07-26 18:01:00'), true);

        self::assertSame(1, $sendCalls);
        self::assertSame('sent', $result['results'][0]['status']);
        self::assertSame(
            (int)$claim['dispatch']['id'],
            (int)$result['results'][0]['dispatch_id']
        );
        self::assertSame(
            1,
            Db::name('manual_notification_dispatch_attempts')
                ->where('dispatch_id', (int)$claim['dispatch']['id'])
                ->count()
        );
    }

    public function testPreparationFailureRetriesInsideTheSameDueWindow(): void
    {
        $notificationId = $this->insertRecord();
        $ledger = new ManualNotificationDispatchLedgerService();
        $failedAt = $this->time('2026-07-26 18:00:00');
        $claim = $ledger->claim(
            $notificationId,
            9,
            80,
            '2026-07-26 18:00',
            ManualNotificationScheduleService::MODE_TEST,
            'daily_fixed_time',
            'scheduled',
            ManualNotificationService::TEST_ROBOT_ID,
            ManualNotificationService::TEST_ROBOT_NAME,
            '2026-07-26',
            [],
            $failedAt,
            'claimed',
            'dispatch_source_preparation_claimed'
        );
        self::assertTrue($claim['claimed']);
        $attached = $ledger->attachCandidateToClaim(
            (int)$claim['dispatch']['id'],
            (string)$claim['dispatch']['claimed_at'],
            ['business_date' => '2026-07-26'],
            $failedAt,
            'preparation_failed',
            'source_preparation_failed'
        );
        self::assertTrue($attached['allowed']);
        self::assertSame(
            '2026-07-26 18:01:00',
            $attached['dispatch']['next_retry_at']
        );

        $sendCalls = 0;
        $result = (new ManualNotificationScheduleService(
            sender: static function () use (&$sendCalls): array {
                $sendCalls++;
                return [
                    'delivery_status' => 'sent',
                    'response_reference' => 'wecom:errcode=0',
                ];
            },
            ledger: $ledger
        ))->runDue($this->time('2026-07-26 18:02:00'), true);

        self::assertSame(1, $sendCalls);
        self::assertSame('sent', $result['results'][0]['status']);
        self::assertSame(
            (int)$claim['dispatch']['id'],
            (int)$result['results'][0]['dispatch_id']
        );
        self::assertSame(
            1,
            Db::name('manual_notification_dispatch_attempts')
                ->where('dispatch_id', (int)$claim['dispatch']['id'])
                ->count()
        );
    }

    public function testLimitAppliesAfterDueFilteringSoLaterDuePlansAreNotStarved(): void
    {
        $notDueId = $this->insertRecord([
            'planned_send_at' => '2026-07-26 17:30:00',
        ]);
        $dueId = $this->insertRecord([
            'planned_send_at' => '2026-07-26 18:00:00',
        ]);

        $result = (new ManualNotificationScheduleService())
            ->runDue($this->time('2026-07-26 18:02:00'), false, 'test', 1);

        self::assertSame(2, $result['candidate_count']);
        self::assertSame(1, $result['due_count']);
        self::assertCount(1, $result['results']);
        self::assertSame($dueId, $result['results'][0]['notification_id']);
        self::assertNotSame($notDueId, $result['results'][0]['notification_id']);
    }

    /** @param array<string, mixed> $overrides */
    private function insertRecord(array $overrides = []): int
    {
        return (int)Db::name('manual_notifications')->insertGetId(array_replace([
            'tenant_id' => 9,
            'hotel_id' => 80,
            'notification_type' => 'blank_custom',
            'template_type' => 'blank_custom',
            'source_scope' => 'combined',
            'content_sections' => '',
            'business_date' => '2026-07-26',
            'business_date_rule' => 'today',
            'title' => '{经营日期} 自定义播报',
            'body' => "酒店：{酒店名称}\n经营日期：{经营日期}\n房量：未取得",
            'send_method' => 'wecom_test',
            'trigger_type' => 'daily_fixed_time',
            'interval_minutes' => null,
            'planned_send_at' => '2026-07-26 18:00:00',
            'active_weekdays' => '1,2,3,4,5,6,7',
            'effective_from' => null,
            'effective_to' => null,
            'hourly_start_time' => '09:00:00',
            'hourly_end_time' => '22:00:00',
            'enabled' => 1,
            'schedule_status' => 'schedule_enabled',
            'last_test_status' => 'sent',
            'last_tested_at' => '2026-07-26 12:00:00',
            'test_robot_id' => ManualNotificationService::TEST_ROBOT_ID,
            'test_robot_name' => ManualNotificationService::TEST_ROBOT_NAME,
            'created_by' => 7,
            'create_time' => '2026-07-26 12:00:00',
            'update_time' => '2026-07-26 12:00:00',
        ], $overrides));
    }

    private function time(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('Asia/Shanghai'));
    }

    private function seedBusinessContractTest(
        int $notificationId,
        string $contractVersion =
            ManualNotificationBusinessPayloadService::RENDER_CONTRACT_VERSIONS[
                'future_room_status'
            ]
    ): void {
        $plan = Db::name('manual_notifications')
            ->where('id', $notificationId)
            ->find();
        if (!is_array($plan)) {
            throw new \RuntimeException('test_notification_missing');
        }
        $sourceRefs = $contractVersion
            === OperatingDailyReportPayloadService::RENDER_CONTRACT_VERSION
            ? [
                'pms' => [
                    'source' => 'dingdandao_pms',
                    'record_id' => 301,
                    'business_date' => '2026-07-26',
                    'source_trace_id' => 'pms:test-301',
                ],
                'ctrip_traffic' => [
                    'source' => 'ctrip',
                    'record_id' => 401,
                    'business_date' => '2026-07-26',
                    'data_source_id' => 11,
                    'sync_task_id' => 21,
                    'source_trace_id' => 'ctrip:test-401',
                ],
                'meituan_traffic' => [
                    'source' => 'meituan',
                    'record_id' => 501,
                    'business_date' => '2026-07-26',
                    'data_source_id' => 12,
                    'sync_task_id' => 22,
                    'source_trace_id' => 'meituan:test-501',
                ],
            ]
            : [];
        $testedAt = $this->time('2026-07-26 17:00:00');
        $claim = (new ManualNotificationDispatchLedgerService())->claim(
            $notificationId,
            9,
            80,
            'i:business-contract-test-' . $notificationId,
            ManualNotificationScheduleService::MODE_TEST,
            'manual_test',
            'immediate_test',
            ManualNotificationService::TEST_ROBOT_ID,
            ManualNotificationService::TEST_ROBOT_NAME,
            '2026-07-26',
            [
                'payload' => [
                    'msgtype' => 'text',
                    'text' => ['content' => 'verified contract'],
                ],
                'tested_plan_fingerprint' =>
                    ManualNotificationService::planFingerprint($plan),
                'source_snapshot_refs' => $sourceRefs,
                'render_contract_version' => $contractVersion,
            ],
            $testedAt
        );
        Db::name('manual_notification_schedule_dispatches')
            ->where('id', (int)$claim['dispatch']['id'])
            ->update([
                'attempt_count' => 1,
                'last_attempt_at' => '2026-07-26 17:00:00',
                'response_reference' => 'test:verified',
                'status' => 'sent',
                'result_code' => 'wecom_business_success',
                'dispatched_at' => '2026-07-26 17:00:01',
                'update_time' => '2026-07-26 17:00:01',
            ]);
    }

    private function operatingDailyThreeSourcePayloads(): OperatingDailyReportPayloadService
    {
        $pmsResolver = static function (
            int $tenantId,
            int $hotelId,
            string $date
        ): array {
            if ($tenantId !== 9 || $hotelId !== 80 || $date !== '2026-07-26') {
                return [];
            }
            $sourceApiPath = '/api/verified';
            $providerHotelId = 'provider-hotel-80';
            $captureEvidence =
                \app\service\DingdandaoOperatingTargetCaptureService::
                expectedCaptureEvidence(
                    $sourceApiPath,
                    $date,
                    $providerHotelId,
                    'full_diagnostic'
                );
            if (!is_array($captureEvidence)) {
                throw new \RuntimeException(
                    'dingdandao_test_capture_evidence_invalid'
                );
            }
            return [
                'id' => 301,
                'tenant_id' => $tenantId,
                'hotel_id' => $hotelId,
                'provider' => 'dingdandao_pms',
                'provider_hotel_id' => $providerHotelId,
                'provider_hotel_name' => '敦煌漠蓝新',
                'business_date' => $date,
                'source_url' =>
                    \app\service\DingdandaoOperatingTargetCaptureService::
                    SOURCE_URL,
                'source_api_path' => $sourceApiPath,
                'source_scope' => 'today_only',
                'collection_mode' => 'full_diagnostic',
                'capture_method' => 'network_response',
                'capture_strategy' => 'verified_endpoint_recipe',
                'capture_status' => 'verified',
                'quality_status' => 'verified',
                'readback_status' => 'readback_verified',
                'identity_status' => 'matched',
                'reconciliation_status' => 'matched',
                'source_trace_id' =>
                    (string)$captureEvidence['source_trace_id'],
                'source_fingerprint' => str_repeat('b', 64),
                'detail_row_count' => 25,
                'captured_at' => $date . ' 17:30:00',
                'summary' => [
                    'total_room_fee' => 8745.66,
                    'sold_room_nights' => 15,
                    'average_daily_room_nights' => 15.0,
                    'derived_sellable_room_nights' => 15,
                    'occupancy_rate_percent' => 100.0,
                    'adr' => 583.04,
                    'revpar' => 583.04,
                ],
                'capture_evidence' => $captureEvidence,
                'gaps' => [],
            ];
        };
        $rowResolver = static function (
            int $tenantId,
            int $hotelId,
            string $date,
            string $source,
            string $dataType,
            ?string $dimension
        ): ?array {
            if ($tenantId !== 9 || $hotelId !== 80 || $date !== '2026-07-26') {
                return null;
            }
            if ($source === 'ctrip'
                && $dataType === 'traffic'
                && $dimension === 'realtime:ctrip'
            ) {
                return self::trustedOtaFixture([
                    'id' => 101,
                    'source' => 'ctrip',
                    'data_source_id' => 7,
                    'sync_task_id' => 21,
                    'source_trace_id' => 'ctrip:trace-101',
                    'snapshot_time' => $date . ' 17:40:00',
                    'readback_verified' => 1,
                    'data_period' => 'realtime_snapshot',
                    'is_final' => 0,
                    'detail_exposure' => 58,
                    'book_order_num' => 0,
                    'quantity' => 4,
                    'raw_data' => [
                        'metrics' => ['last_week_visitors' => 195],
                    ],
                ], $date, 'ctrip', 'traffic', $dimension, [
                    'realtime_visitors',
                    'last_week_visitors',
                    'booking_order_count',
                    'in_house_room_nights',
                ]);
            }
            if ($source === 'meituan'
                && $dataType === 'traffic'
                && $dimension === null
            ) {
                return self::trustedOtaFixture([
                    'id' => 64381,
                    'platform' => 'meituan',
                    'data_source_id' => 8,
                    'sync_task_id' => 22,
                    'source_trace_id' => 'meituan:trace-64381',
                    'validation_status' => 'verified',
                    'snapshot_time' => $date . ' 17:45:00',
                    'readback_verified' => 1,
                    'data_period' => 'realtime_snapshot',
                    'is_final' => 0,
                    'list_exposure' => 471,
                    'detail_exposure' => 77,
                    'raw_data' => [
                        'exposure_to_browse_rate' => 16.35,
                    ],
                ], $date, 'meituan', 'traffic', $dimension, [
                    'list_exposure',
                    'detail_exposure',
                ]);
            }
            return null;
        };

        return new OperatingDailyReportPayloadService(
            null,
            $pmsResolver,
            $rowResolver,
            null,
            static fn(): array => [
                'configured' => true,
                'expected_provider_hotel_id' => 'provider-hotel-80',
                'expected_provider_hotel_name' => '敦煌漠蓝新',
            ]
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $metricKeys
     * @return array<string, mixed>
     */
    private static function trustedOtaFixture(
        array $row,
        string $date,
        string $source,
        string $dataType,
        ?string $dimension,
        array $metricKeys
    ): array {
        $trace = trim((string)($row['source_trace_id'] ?? ''));
        $captureEvidence = [
            'source_trace_id' => $trace,
            'source_url_hash' => hash(
                'sha256',
                'https://fixture.suxios.test/' . $source . '/' . $dataType
            ),
        ];
        $raw = is_array($row['raw_data'] ?? null)
            ? $row['raw_data']
            : [];
        $raw['source_trace_id'] = $trace;
        $raw['hotel_id'] = 'provider-hotel-80';
        $raw['capture_evidence'] = $captureEvidence;
        $raw['field_facts'] = array_map(
            static fn(string $metricKey): array => [
                'metric_key' => $metricKey,
                'status' => 'captured',
                'source_path' => 'fixture.metrics.' . $metricKey,
                'storage_field' =>
                    'online_daily_data.raw_data.facts.metric_key='
                    . $metricKey,
                'stored_value_present' => true,
                'value' => 1,
                'capture_evidence' => $captureEvidence,
            ],
            $metricKeys
        );
        return array_replace($row, [
            'tenant_id' => 9,
            'system_hotel_id' => 80,
            'hotel_id' => 'provider-hotel-80',
            'source' => $source,
            'platform' => $source,
            'data_date' => $date,
            'data_type' => $dataType,
            'dimension' => $dimension ?? '',
            'validation_status' => 'verified',
            'validation_flags' => '[]',
            'ingestion_method' => 'browser_profile',
            'raw_data' => $raw,
        ]);
    }

    private function businessPayloads(): ManualNotificationBusinessPayloadService
    {
        return new ManualNotificationBusinessPayloadService(
            static function (string $type, int $hotelId, string $date): array {
                $dailyRows = [];
                for ($offset = 1; $offset <= 21; $offset++) {
                    $dailyRows[] = [
                        'stay_date' => (new DateTimeImmutable($date))
                            ->modify('+' . $offset . ' days')
                            ->format('Y-m-d'),
                        'booked_rooms' => 9,
                        'remaining_sellable_rooms' => 6,
                        'occupancy_rate_percent' => 60,
                        'adr' => 500,
                    ];
                }
                $horizons = [];
                foreach ([3, 7, 14, 21] as $days) {
                    $horizons[] = [
                        'horizon_days' => $days,
                        'booked_room_nights' => 9 * $days,
                        'remaining_sellable_room_nights' => 6 * $days,
                        'occupancy_rate_percent' => 60,
                        'adr' => 500,
                    ];
                }
                return [
                    'contract_version' => 'manual_notification_business_preview.v1',
                    'hotel' => [
                        'id' => $hotelId,
                        'tenant_id' => 9,
                        'name' => '敦煌漠蓝新',
                    ],
                    'business_date' => $date,
                    'section' => [
                        'key' => $type,
                        'status' => 'ready',
                        'facts' => [],
                        'forecasts' => [],
                        'gaps' => [],
                        'message_data' => [
                            'contract_version' => 'dingdandao_forward_message_facts.v1',
                            'data_status' => 'readback_verified',
                            'display_horizons' => [3, 7, 14, 21],
                            'source_day_count' => 31,
                            'display_day_count' => 21,
                            'source_coverage_status' => 'complete',
                            'source_gap_codes' => [],
                            'horizons' => $horizons,
                            'daily_rows' => $dailyRows,
                            'room_types' => [],
                            'sources' => [
                                'dingdandao_pms' => [
                                    'data_status' => 'readback_verified',
                                    'business_scope' => 'whole_hotel_forward_room_status',
                                ],
                                'ctrip_ota' => ['data_status' => 'pending_collection'],
                                'meituan_ota' => ['data_status' => 'pending_collection'],
                            ],
                            'aggregation_policy' => [
                                'pms_plus_ota_revenue_addition_allowed' => false,
                                'missing_source_value' => null,
                            ],
                        ],
                    ],
                ];
            }
        );
    }
}
