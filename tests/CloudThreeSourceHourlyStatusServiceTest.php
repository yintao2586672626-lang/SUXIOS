<?php
declare(strict_types=1);

namespace Tests;

use app\service\CloudThreeSourceHourlyStatusService;
use app\service\ManualNotificationService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class CloudThreeSourceHourlyStatusServiceTest extends TestCase
{
    private static array $databaseConfig;
    private static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir()
            . '/cloud_three_source_hourly_status_' . getmypid() . '.sqlite';
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
        foreach ([
            'manual_notifications',
            'manual_notification_schedule_dispatches',
            'manual_notification_rule_states',
            'online_daily_data',
            'platform_data_sync_tasks',
            'platform_data_sources',
            'cloud_browser_profiles',
            'dingdandao_operating_target_captures',
        ] as $table) {
            Db::execute('DROP TABLE IF EXISTS `' . $table . '`');
        }
    }

    public function testExactHotelRecentReadbacksAreReady(): void
    {
        $this->createStatusTables();
        $this->insertReadyFixture();

        $status = $this->service()->status(8, 80, '2026-08-14');

        self::assertSame('ready', $status['status']);
        self::assertTrue($status['ready']);
        self::assertSame(
            ['ready', 'ready', 'ready'],
            array_column($status['sources'], 'status')
        );
        self::assertSame(
            '2026-08-14 10:50:00',
            $status['sources']['ctrip']['last_success_at']
        );
        self::assertSame(
            'ready_to_collect',
            $status['sources']['ctrip']['profile']['authorization_status']
        );
        self::assertSame(
            '2026-08-16 11:00:00',
            $status['sources']['ctrip']['profile']['session_expires_at']
        );
        self::assertSame(48, $status['sources']['ctrip']['profile']['hours_remaining']);
        self::assertFalse($status['sources']['ctrip']['profile']['expiring_soon']);
        self::assertSame(23, $status['sources']['meituan']['profile']['hours_remaining']);
        self::assertTrue($status['sources']['meituan']['profile']['expiring_soon']);
        self::assertSame('collect_now', $status['sources']['meituan']['action_key']);
    }

    public function testExpiredProfileAndOldCaptureAreNeverReady(): void
    {
        $this->createStatusTables();
        $this->insertReadyFixture();
        Db::name('cloud_browser_profiles')
            ->where('tenant_id', 8)
            ->where('system_hotel_id', 80)
            ->where('platform', 'ctrip')
            ->update([
                'authorization_status' => 'session_expired',
                'session_expires_at' => '2026-08-14 10:59:00',
            ]);
        Db::name('dingdandao_operating_target_captures')
            ->where('tenant_id', 8)
            ->where('hotel_id', 80)
            ->update(['captured_at' => '2026-08-14 09:00:00']);

        $status = $this->service()->status(8, 80, '2026-08-14');

        self::assertSame('login_required', $status['sources']['ctrip']['status']);
        self::assertSame('request_login', $status['sources']['ctrip']['action_key']);
        self::assertSame(0, $status['sources']['ctrip']['profile']['hours_remaining']);
        self::assertFalse($status['sources']['ctrip']['profile']['expiring_soon']);
        self::assertSame('stale', $status['sources']['dingdandao_pms']['status']);
        self::assertSame(
            '2026-08-14 09:00:00',
            $status['sources']['dingdandao_pms']['last_success_at']
        );
    }

    public function testOtherTenantOrHotelSourceDoesNotSatisfyBinding(): void
    {
        $this->createStatusTables();
        Db::name('platform_data_sources')->insertAll([
            $this->sourceRow(1, 9, 80, 'meituan'),
            $this->sourceRow(2, 8, 81, 'meituan'),
        ]);
        Db::name('cloud_browser_profiles')->insert(
            $this->profileRow(1, 8, 80, 'meituan')
        );

        $status = $this->service()->status(8, 80, '2026-08-14');

        self::assertSame('binding_missing', $status['sources']['meituan']['status']);
        self::assertSame('check_binding', $status['sources']['meituan']['action_key']);
        self::assertNull(
            $status['sources']['meituan']['profile']['authorization_status'],
            'an unrelated Profile must not be exposed as the source binding'
        );
    }

    public function testProfileMustMatchSourceOwnerAndHavePastReadyEvidence(): void
    {
        $this->createStatusTables();
        $this->insertReadyFixture();
        Db::name('platform_data_sources')
            ->where('id', 1)
            ->update(['user_id' => 9]);
        Db::name('cloud_browser_profiles')
            ->where('id', 2)
            ->update(['ready_at' => '2026-08-14 11:30:00']);

        $status = $this->service()->status(8, 80, '2026-08-14');

        self::assertSame('login_required', $status['sources']['ctrip']['status']);
        self::assertSame('request_login', $status['sources']['ctrip']['action_key']);
        self::assertSame('login_required', $status['sources']['meituan']['status']);
        self::assertFalse($status['ready']);
    }

    public function testMissingStatusTablesDegradeMetadataAndReadToUnknown(): void
    {
        $this->createManualNotificationsTable();
        Db::name('manual_notifications')->insert([
            'id' => 11,
            'tenant_id' => 8,
            'hotel_id' => 80,
            'notification_type' => 'operating_daily_report',
            'template_type' => 'operating_daily_report',
            'source_scope' => 'combined',
            'content_sections' =>
                'pms_summary,pms_efficiency,ctrip_traffic,meituan_traffic',
            'business_date' => '2026-08-14',
            'business_date_rule' => 'today',
            'title' => '三源整点快报',
            'body' => '',
            'send_method' => 'wecom_formal',
            'trigger_type' => 'hourly_on_the_hour',
            'hourly_start_time' => '01:00:00',
            'hourly_end_time' => '23:00:00',
            'enabled' => 0,
            'schedule_status' => 'saved_only',
            'last_test_status' => 'never_tested',
            'created_by' => 7,
            'create_time' => '2026-08-14 10:00:00',
            'update_time' => '2026-08-14 10:00:00',
        ]);
        $statusService = $this->service();
        $notifications = new ManualNotificationService(
            cloudThreeSourceStatus: $statusService
        );

        $direct = $statusService->status(8, 80, '2026-08-14');
        $metadata = $notifications->metadata('2026-08-14', 8, 80);
        $record = $notifications->read(8, 80, 11);

        self::assertSame(
            ['unknown', 'unknown', 'unknown'],
            array_column($direct['sources'], 'status')
        );
        self::assertSame(
            CloudThreeSourceHourlyStatusService::CONTRACT_VERSION,
            $metadata['three_source_hourly_status']['contract_version']
        );
        self::assertSame(80, $metadata['three_source_hourly_status']['hotel_id']);
        self::assertSame('unknown', $metadata['three_source_hourly_status']['status']);
        self::assertSame(
            'unknown',
            $record['three_source_hourly_status']['sources']['ctrip']['status']
        );
        self::assertSame(8, $record['three_source_hourly_status']['tenant_id']);
        self::assertSame(80, $record['three_source_hourly_status']['hotel_id']);
    }

    private function service(): CloudThreeSourceHourlyStatusService
    {
        return new CloudThreeSourceHourlyStatusService(
            static fn(): DateTimeImmutable => new DateTimeImmutable(
                '2026-08-14 11:00:00',
                new DateTimeZone('Asia/Shanghai')
            )
        );
    }

    private function createStatusTables(): void
    {
        Db::execute('CREATE TABLE dingdandao_operating_target_captures (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            hotel_id INTEGER NOT NULL,
            business_date TEXT NOT NULL,
            identity_status TEXT NOT NULL,
            reconciliation_status TEXT NOT NULL,
            capture_status TEXT NOT NULL,
            quality_status TEXT NOT NULL,
            quality_reason TEXT NULL,
            gap_codes_json TEXT NULL,
            readback_status TEXT NOT NULL,
            total_room_fee REAL NULL,
            adr REAL NULL,
            occupancy_rate_percent REAL NULL,
            revpar REAL NULL,
            sold_room_nights INTEGER NULL,
            derived_sellable_room_nights INTEGER NULL,
            captured_at TEXT NOT NULL
        )');
        Db::execute('CREATE TABLE platform_data_sources (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            platform TEXT NOT NULL,
            ingestion_method TEXT NOT NULL,
            enabled INTEGER NOT NULL,
            config_json TEXT NULL
        )');
        Db::execute('CREATE TABLE cloud_browser_profiles (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            owner_user_id INTEGER NOT NULL,
            platform TEXT NOT NULL,
            authorization_status TEXT NOT NULL,
            ready_at TEXT NULL,
            session_expires_at TEXT NULL
        )');
        Db::execute('CREATE TABLE platform_data_sync_tasks (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            data_source_id INTEGER NOT NULL,
            platform TEXT NOT NULL,
            status TEXT NOT NULL,
            message TEXT NULL,
            stats_json TEXT NULL,
            finished_at TEXT NULL
        )');
        Db::execute('CREATE TABLE online_daily_data (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            data_source_id INTEGER NOT NULL,
            sync_task_id INTEGER NOT NULL,
            source TEXT NOT NULL,
            platform TEXT NOT NULL,
            data_date TEXT NOT NULL,
            validation_status TEXT NOT NULL,
            readback_verified INTEGER NOT NULL,
            source_trace_id TEXT NULL,
            snapshot_time TEXT NULL,
            raw_data TEXT NULL
        )');
    }

    private function insertReadyFixture(): void
    {
        Db::name('dingdandao_operating_target_captures')->insert([
            'id' => 1,
            'tenant_id' => 8,
            'hotel_id' => 80,
            'business_date' => '2026-08-14',
            'identity_status' => 'matched',
            'reconciliation_status' => 'matched',
            'capture_status' => 'verified',
            'quality_status' => 'verified',
            'quality_reason' => '',
            'gap_codes_json' => '[]',
            'readback_status' => 'readback_verified',
            'total_room_fee' => 9295.38,
            'adr' => 580.96,
            'occupancy_rate_percent' => 100,
            'revpar' => 580.96,
            'sold_room_nights' => 16,
            'derived_sellable_room_nights' => 16,
            'captured_at' => '2026-08-14 10:45:00',
        ]);
        Db::name('platform_data_sources')->insertAll([
            $this->sourceRow(1, 8, 80, 'ctrip'),
            $this->sourceRow(2, 8, 80, 'meituan'),
            $this->sourceRow(3, 9, 80, 'ctrip'),
        ]);
        Db::name('cloud_browser_profiles')->insertAll([
            $this->profileRow(1, 8, 80, 'ctrip'),
            [
                ...$this->profileRow(2, 8, 80, 'meituan'),
                'session_expires_at' => '2026-08-15 10:00:00',
            ],
            [
                ...$this->profileRow(3, 9, 80, 'ctrip'),
                'authorization_status' => 'session_expired',
                'session_expires_at' => '2026-08-13 00:00:00',
            ],
        ]);
        Db::name('platform_data_sync_tasks')->insertAll([
            $this->taskRow(101, 8, 80, 1, 'ctrip'),
            $this->taskRow(102, 8, 80, 2, 'meituan'),
            $this->taskRow(103, 9, 80, 3, 'ctrip'),
        ]);
        Db::name('online_daily_data')->insertAll([
            $this->onlineRow(201, 8, 80, 1, 101, 'ctrip'),
            $this->onlineRow(202, 8, 80, 2, 102, 'meituan'),
            $this->onlineRow(203, 9, 80, 3, 103, 'ctrip'),
        ]);
    }

    /** @return array<string, mixed> */
    private function sourceRow(
        int $id,
        int $tenantId,
        int $hotelId,
        string $platform
    ): array {
        return [
            'id' => $id,
            'tenant_id' => $tenantId,
            'user_id' => 7,
            'system_hotel_id' => $hotelId,
            'platform' => $platform,
            'ingestion_method' => 'browser_profile',
            'enabled' => 1,
            'config_json' => '{}',
        ];
    }

    /** @return array<string, mixed> */
    private function profileRow(
        int $id,
        int $tenantId,
        int $hotelId,
        string $platform
    ): array {
        return [
            'id' => $id,
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'owner_user_id' => 7,
            'platform' => $platform,
            'authorization_status' => 'ready_to_collect',
            'ready_at' => '2026-08-14 10:30:00',
            'session_expires_at' => '2026-08-16 11:00:00',
        ];
    }

    /** @return array<string, mixed> */
    private function taskRow(
        int $id,
        int $tenantId,
        int $hotelId,
        int $sourceId,
        string $platform
    ): array {
        return [
            'id' => $id,
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'data_source_id' => $sourceId,
            'platform' => $platform,
            'status' => 'success',
            'message' => 'saved_and_readback_verified',
            'stats_json' => '{}',
            'finished_at' => '2026-08-14 10:52:00',
        ];
    }

    /** @return array<string, mixed> */
    private function onlineRow(
        int $id,
        int $tenantId,
        int $hotelId,
        int $sourceId,
        int $taskId,
        string $platform
    ): array {
        return [
            'id' => $id,
            'tenant_id' => $tenantId,
            'system_hotel_id' => $hotelId,
            'data_source_id' => $sourceId,
            'sync_task_id' => $taskId,
            'source' => $platform,
            'platform' => $platform,
            'data_date' => '2026-08-14',
            'validation_status' => 'normal',
            'readback_verified' => 1,
            'source_trace_id' => $platform . '-trace-' . $id,
            'snapshot_time' => '2026-08-14 10:50:00',
            'raw_data' => '{}',
        ];
    }

    private function createManualNotificationsTable(): void
    {
        Db::execute('CREATE TABLE manual_notifications (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            hotel_id INTEGER NOT NULL,
            notification_type TEXT NOT NULL,
            template_type TEXT NOT NULL,
            source_scope TEXT NOT NULL,
            content_sections TEXT NOT NULL,
            business_date TEXT NOT NULL,
            business_date_rule TEXT NOT NULL,
            title TEXT NOT NULL,
            body TEXT NOT NULL,
            send_method TEXT NOT NULL,
            trigger_type TEXT NOT NULL,
            hourly_start_time TEXT NOT NULL,
            hourly_end_time TEXT NOT NULL,
            enabled INTEGER NOT NULL,
            schedule_status TEXT NOT NULL,
            last_test_status TEXT NOT NULL,
            created_by INTEGER NOT NULL,
            create_time TEXT NOT NULL,
            update_time TEXT NOT NULL
        )');
    }
}
