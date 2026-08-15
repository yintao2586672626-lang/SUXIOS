<?php
declare(strict_types=1);

namespace tests;

use app\service\CloudThreeSourceHourlyPayloadService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class CloudThreeSourceHourlyPayloadServiceTest extends TestCase
{
    private static array $databaseConfig;
    private static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir()
            . '/cloud_three_source_hourly_payload_' . getmypid() . '.sqlite';
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
            'hotel_collection_plans',
            'platform_data_sources',
            'cloud_browser_profiles',
            'platform_data_sync_tasks',
            'dingdandao_operating_target_captures',
            'online_daily_data',
        ] as $table) {
            Db::execute('DROP TABLE IF EXISTS `' . $table . '`');
        }
    }

    public function testInvalidScopeBlocksBeforeAnySourceRead(): void
    {
        $now = new DateTimeImmutable(
            '2026-08-14 02:00:00',
            new DateTimeZone('Asia/Shanghai')
        );
        $candidate = (new CloudThreeSourceHourlyPayloadService())->build(
            0,
            5,
            '敦煌漠蓝',
            '2026-08-14',
            7,
            $now
        );

        self::assertSame('blocked', $candidate['status']);
        self::assertSame(
            'cloud_three_source_scope_or_date_invalid',
            $candidate['reason_code']
        );
        self::assertFalse($candidate['formal_send_gate']['allowed']);
        self::assertNull($candidate['payload']);
    }

    public function testHistoricalDateCannotBePresentedAsRealtime(): void
    {
        $candidate = (new CloudThreeSourceHourlyPayloadService())->build(
            1,
            5,
            '敦煌漠蓝',
            '2026-08-13',
            7,
            new DateTimeImmutable(
                '2026-08-14 02:00:00',
                new DateTimeZone('Asia/Shanghai')
            )
        );

        self::assertSame('blocked', $candidate['status']);
        self::assertSame(
            CloudThreeSourceHourlyPayloadService::RENDER_CONTRACT_VERSION,
            $candidate['render_contract_version']
        );
    }

    public function testActivePlanAndExactProfileSourceAreActorScoped(): void
    {
        $this->createBindingTables();
        $profileId = 'cbp_1234567890123456';
        Db::name('hotel_collection_plans')->insert([
            'id' => 10,
            'tenant_id' => 8,
            'system_hotel_id' => 80,
            'execution_owner_user_id' => 7,
            'enabled' => 1,
            'active_slot' => 1,
            'plan_status' => 'active',
            'validation_status' => 'ready',
            'source_plan_json' => json_encode([
                'ctrip' => ['data_source_id' => 25],
                'meituan' => ['data_source_id' => 68],
            ], JSON_THROW_ON_ERROR),
        ]);
        Db::name('platform_data_sources')->insert([
            'id' => 25,
            'tenant_id' => 8,
            'system_hotel_id' => 80,
            'user_id' => 7,
            'platform' => 'ctrip',
            'ingestion_method' => 'browser_profile',
            'enabled' => 1,
            'status' => 'ready',
            'config_json' => json_encode([
                'profile_binding_key' => $profileId,
                'stable_profile_id' => $profileId,
            ], JSON_THROW_ON_ERROR),
        ]);
        Db::name('cloud_browser_profiles')->insert([
            'id' => 1,
            'tenant_id' => 8,
            'system_hotel_id' => 80,
            'owner_user_id' => 7,
            'platform' => 'ctrip',
            'profile_public_id' => $profileId,
            'authorization_status' => 'ready_to_collect',
        ]);
        $service = new CloudThreeSourceHourlyPayloadService();

        $plan = $this->invoke($service, 'activeCollectionPlan', [8, 80, 7]);
        self::assertSame(10, (int)($plan['id'] ?? 0));
        self::assertNull($this->invoke($service, 'activeCollectionPlan', [8, 80, 9]));
        $crossActorCandidate = $service->build(
            8,
            80,
            'Actor scoped hotel',
            '2026-08-14',
            9,
            new DateTimeImmutable(
                '2026-08-14 11:00:00',
                new DateTimeZone('Asia/Shanghai')
            )
        );
        self::assertSame('blocked', $crossActorCandidate['status']);
        self::assertSame(
            'cloud_three_source_active_plan_actor_mismatch',
            $crossActorCandidate['reason_code']
        );
        $source = $this->invoke($service, 'profileSource', [8, 80, 7, 25, 'ctrip']);
        self::assertSame(25, (int)($source['id'] ?? 0));

        Db::name('platform_data_sources')->where('id', 25)->update(['user_id' => 9]);
        self::assertNull(
            $this->invoke($service, 'profileSource', [8, 80, 7, 25, 'ctrip']),
            'a source owned by another execution actor must not be read'
        );
        Db::name('platform_data_sources')->where('id', 25)->update(['user_id' => 7]);
        Db::name('cloud_browser_profiles')->where('id', 1)->update(['owner_user_id' => 9]);
        self::assertNull(
            $this->invoke($service, 'profileSource', [8, 80, 7, 25, 'ctrip']),
            'a Profile owned by another execution actor must not be read'
        );
    }

    public function testLatestActorTaskFailureOverridesOlderSuccess(): void
    {
        $this->createTaskTable();
        Db::name('platform_data_sync_tasks')->insertAll([
            $this->taskRow(1, 7, 'success', '2026-08-14 10:35:00'),
            $this->taskRow(2, 7, 'failed', '2026-08-14 10:50:00'),
            $this->taskRow(3, 9, 'success', '2026-08-14 10:55:00'),
        ]);
        $service = new CloudThreeSourceHourlyPayloadService();
        $now = new DateTimeImmutable(
            '2026-08-14 11:00:00',
            new DateTimeZone('Asia/Shanghai')
        );

        $latest = $this->invoke($service, 'recentTask', [8, 80, 7, 25, 'ctrip', $now]);
        self::assertFalse($latest['allowed']);
        self::assertSame(2, (int)$latest['task']['id']);
        self::assertSame(
            'ctrip_latest_terminal_task_not_successful:failed',
            $latest['reason_code']
        );

        Db::name('platform_data_sync_tasks')->where('id', 2)->delete();
        $fallback = $this->invoke($service, 'recentTask', [8, 80, 7, 25, 'ctrip', $now]);
        self::assertTrue($fallback['allowed']);
        self::assertSame(1, (int)$fallback['task']['id']);
        self::assertSame(7, (int)$fallback['task']['requested_by']);
    }

    public function testCtripPartialIsAllowedButMeituanPartialIsBlocked(): void
    {
        $this->createTaskTable();
        Db::name('platform_data_sync_tasks')->insertAll([
            $this->taskRow(1, 7, 'partial_success', '2026-08-14 10:50:00'),
            [
                ...$this->taskRow(2, 7, 'partial_success', '2026-08-14 10:51:00'),
                'data_source_id' => 68,
                'platform' => 'meituan',
            ],
        ]);
        $service = new CloudThreeSourceHourlyPayloadService();
        $now = new DateTimeImmutable(
            '2026-08-14 11:00:00',
            new DateTimeZone('Asia/Shanghai')
        );

        $ctrip = $this->invoke($service, 'recentTask', [8, 80, 7, 25, 'ctrip', $now]);
        $meituan = $this->invoke($service, 'recentTask', [8, 80, 7, 68, 'meituan', $now]);

        self::assertTrue($ctrip['allowed']);
        self::assertFalse($meituan['allowed']);
        self::assertSame(
            'meituan_latest_terminal_task_not_successful:partial_success',
            $meituan['reason_code']
        );
    }

    public function testExactActorPlanSourcesTasksAndRowsBuildReadyPayload(): void
    {
        $this->createBindingTables();
        $this->createTaskTable();
        $this->createPayloadEvidenceTables();
        $this->insertReadyPayloadFixture();

        $candidate = (new CloudThreeSourceHourlyPayloadService())->build(
            8,
            80,
            'Actor scoped hotel',
            '2026-08-14',
            7,
            new DateTimeImmutable(
                '2026-08-14 11:00:00',
                new DateTimeZone('Asia/Shanghai')
            )
        );

        self::assertSame('ready', $candidate['status']);
        self::assertTrue($candidate['formal_send_gate']['allowed']);
        self::assertSame(501, $candidate['source_snapshot_ids']['pms_capture_id']);
        self::assertSame(601, $candidate['source_snapshot_ids']['ctrip_sync_task_id']);
        self::assertSame(602, $candidate['source_snapshot_ids']['meituan_sync_task_id']);
        self::assertNotEmpty($candidate['payload']['markdown']['content']);
    }

    public function testMidnightCanUsePreviousDayFreshCloseoutRows(): void
    {
        $this->createBindingTables();
        $this->createTaskTable();
        $this->createPayloadEvidenceTables();
        $this->insertReadyPayloadFixture();
        Db::name('dingdandao_operating_target_captures')->where('id', 501)->update([
            'captured_at' => '2026-08-14 23:40:00',
            'readback_verified_at' => '2026-08-14 23:40:01',
            'create_time' => '2026-08-14 23:40:01',
        ]);
        Db::name('platform_data_sync_tasks')->where('id', 601)->update([
            'finished_at' => '2026-08-14 23:45:00',
        ]);
        Db::name('platform_data_sync_tasks')->where('id', 602)->update([
            'finished_at' => '2026-08-14 23:46:00',
        ]);
        foreach ([701 => '2026-08-14 23:45:00', 702 => '2026-08-14 23:46:00'] as $id => $capturedAt) {
            $row = Db::name('online_daily_data')->where('id', $id)->find();
            $raw = json_decode((string)($row['raw_data'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
            $raw['captured_at'] = $capturedAt;
            Db::name('online_daily_data')->where('id', $id)->update([
                'snapshot_time' => $capturedAt,
                'raw_data' => json_encode($raw, JSON_THROW_ON_ERROR),
            ]);
        }

        $candidate = (new CloudThreeSourceHourlyPayloadService())->build(
            8,
            80,
            'Actor scoped hotel',
            '2026-08-14',
            7,
            new DateTimeImmutable(
                '2026-08-15 00:00:04',
                new DateTimeZone('Asia/Shanghai')
            )
        );

        self::assertSame('ready', $candidate['status']);
        self::assertTrue($candidate['formal_send_gate']['allowed']);
        self::assertSame('2026-08-14', $candidate['business_date']);
        self::assertStringContainsString(
            '数据日期：2026-08-14',
            $candidate['payload']['markdown']['content']
        );
    }

    private function createBindingTables(): void
    {
        Db::execute('CREATE TABLE hotel_collection_plans (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            execution_owner_user_id INTEGER NOT NULL,
            enabled INTEGER NOT NULL,
            active_slot INTEGER NOT NULL,
            plan_status TEXT NOT NULL,
            validation_status TEXT NOT NULL,
            source_plan_json TEXT NOT NULL
        )');
        Db::execute('CREATE TABLE platform_data_sources (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            platform TEXT NOT NULL,
            ingestion_method TEXT NOT NULL,
            enabled INTEGER NOT NULL,
            status TEXT NOT NULL,
            config_json TEXT NOT NULL
        )');
        Db::execute('CREATE TABLE cloud_browser_profiles (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            owner_user_id INTEGER NOT NULL,
            platform TEXT NOT NULL,
            profile_public_id TEXT NOT NULL,
            authorization_status TEXT NOT NULL
        )');
    }

    private function createTaskTable(): void
    {
        Db::execute('CREATE TABLE platform_data_sync_tasks (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            requested_by INTEGER NOT NULL,
            data_source_id INTEGER NOT NULL,
            platform TEXT NOT NULL,
            status TEXT NOT NULL,
            finished_at TEXT NULL
        )');
    }

    private function createPayloadEvidenceTables(): void
    {
        Db::execute('CREATE TABLE dingdandao_operating_target_captures (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            hotel_id INTEGER NOT NULL,
            provider_hotel_id TEXT NULL,
            provider_hotel_name TEXT NULL,
            expected_hotel_name TEXT NOT NULL,
            identity_evidence_type TEXT NOT NULL,
            identity_status TEXT NOT NULL,
            source_url TEXT NOT NULL,
            source_api_path TEXT NULL,
            source_scope TEXT NOT NULL,
            capture_method TEXT NOT NULL,
            business_date TEXT NOT NULL,
            total_room_fee REAL NULL,
            adr REAL NULL,
            occupancy_rate_percent REAL NULL,
            revpar REAL NULL,
            sold_room_nights INTEGER NULL,
            average_daily_room_nights REAL NULL,
            derived_sellable_room_nights INTEGER NULL,
            detail_room_fee_total REAL NULL,
            detail_row_count INTEGER NOT NULL,
            reconciliation_status TEXT NOT NULL,
            capture_status TEXT NOT NULL,
            quality_status TEXT NOT NULL,
            quality_reason TEXT NULL,
            gap_codes_json TEXT NULL,
            trend_json TEXT NULL,
            field_trace_json TEXT NULL,
            snapshot_json TEXT NOT NULL,
            source_fingerprint TEXT NOT NULL,
            captured_at TEXT NOT NULL,
            captured_by INTEGER NOT NULL,
            readback_status TEXT NOT NULL,
            readback_verified_at TEXT NULL,
            create_time TEXT NULL
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
            data_type TEXT NULL,
            dimension TEXT NULL,
            data_period TEXT NULL,
            compare_type TEXT NULL,
            amount REAL NULL,
            quantity INTEGER NULL,
            book_order_num INTEGER NULL,
            data_value REAL NULL,
            list_exposure INTEGER NULL,
            detail_exposure INTEGER NULL,
            flow_rate REAL NULL,
            ingestion_method TEXT NULL,
            validation_status TEXT NOT NULL,
            readback_verified INTEGER NOT NULL,
            source_trace_id TEXT NOT NULL,
            snapshot_time TEXT NULL,
            is_final INTEGER NULL,
            raw_data TEXT NOT NULL
        )');
    }

    private function insertReadyPayloadFixture(): void
    {
        $ctripProfile = 'cbp_1234567890123456';
        $meituanProfile = 'cbp_abcdefghijklmnop';
        Db::name('hotel_collection_plans')->insert([
            'id' => 100,
            'tenant_id' => 8,
            'system_hotel_id' => 80,
            'execution_owner_user_id' => 7,
            'enabled' => 1,
            'active_slot' => 1,
            'plan_status' => 'active',
            'validation_status' => 'ready',
            'source_plan_json' => json_encode([
                'ctrip' => ['data_source_id' => 25],
                'meituan' => ['data_source_id' => 68],
            ], JSON_THROW_ON_ERROR),
        ]);
        foreach ([
            [25, 'ctrip', $ctripProfile],
            [68, 'meituan', $meituanProfile],
        ] as [$id, $platform, $profileId]) {
            Db::name('platform_data_sources')->insert([
                'id' => $id,
                'tenant_id' => 8,
                'system_hotel_id' => 80,
                'user_id' => 7,
                'platform' => $platform,
                'ingestion_method' => 'browser_profile',
                'enabled' => 1,
                'status' => 'ready',
                'config_json' => json_encode([
                    'profile_binding_key' => $profileId,
                    'stable_profile_id' => $profileId,
                    'profile_id' => $profileId,
                ], JSON_THROW_ON_ERROR),
            ]);
            Db::name('cloud_browser_profiles')->insert([
                'id' => $id,
                'tenant_id' => 8,
                'system_hotel_id' => 80,
                'owner_user_id' => 7,
                'platform' => $platform,
                'profile_public_id' => $profileId,
                'authorization_status' => 'ready_to_collect',
            ]);
        }
        Db::name('dingdandao_operating_target_captures')->insert([
            'id' => 501,
            'tenant_id' => 8,
            'hotel_id' => 80,
            'provider_hotel_id' => 'pms-80',
            'provider_hotel_name' => 'Actor scoped hotel',
            'expected_hotel_name' => 'Actor scoped hotel',
            'identity_evidence_type' => 'platform_hotel_id',
            'identity_status' => 'matched',
            'source_url' => 'https://example.invalid/pms',
            'source_api_path' => '/business',
            'source_scope' => 'today_only',
            'capture_method' => 'cloud_browser_profile',
            'business_date' => '2026-08-14',
            'total_room_fee' => 9295.38,
            'adr' => 580.96,
            'occupancy_rate_percent' => 100,
            'revpar' => 580.96,
            'sold_room_nights' => 16,
            'average_daily_room_nights' => 16,
            'derived_sellable_room_nights' => 16,
            'detail_room_fee_total' => 9295.38,
            'detail_row_count' => 0,
            'reconciliation_status' => 'matched',
            'capture_status' => 'verified',
            'quality_status' => 'verified',
            'quality_reason' => '',
            'gap_codes_json' => '[]',
            'trend_json' => '[]',
            'field_trace_json' => '[]',
            'snapshot_json' => json_encode([
                'contract_version' => 'dingdandao_operating_target_capture.v4',
            ], JSON_THROW_ON_ERROR),
            'source_fingerprint' => str_repeat('a', 64),
            'captured_at' => '2026-08-14 10:40:00',
            'captured_by' => 7,
            'readback_status' => 'readback_verified',
            'readback_verified_at' => '2026-08-14 10:40:01',
            'create_time' => '2026-08-14 10:40:01',
        ]);
        Db::name('platform_data_sync_tasks')->insertAll([
            [
                ...$this->taskRow(601, 7, 'partial_success', '2026-08-14 10:45:00'),
                'data_source_id' => 25,
                'platform' => 'ctrip',
            ],
            [
                ...$this->taskRow(602, 7, 'success', '2026-08-14 10:46:00'),
                'data_source_id' => 68,
                'platform' => 'meituan',
            ],
        ]);
        Db::name('online_daily_data')->insert([
            'id' => 701,
            'tenant_id' => 8,
            'system_hotel_id' => 80,
            'data_source_id' => 25,
            'sync_task_id' => 601,
            'source' => 'ctrip',
            'platform' => 'ctrip',
            'data_date' => '2026-08-14',
            'data_type' => 'traffic',
            'dimension' => '',
            'data_period' => 'realtime_snapshot',
            'compare_type' => 'self',
            'ingestion_method' => 'browser_profile',
            'validation_status' => 'partial',
            'readback_verified' => 1,
            'source_trace_id' => 'ctrip-trace-601',
            'snapshot_time' => '2026-08-14 10:45:00',
            'is_final' => 0,
            'raw_data' => json_encode([
                'endpoint_id' => 'business_visitor_title',
                'captured_at' => '2026-08-14 10:45:00',
                'field_facts' => [[
                    'metric_key' => 'visitor_count',
                    'value' => 6,
                    'source_path' => 'visitorTotal',
                    'fact_status' => 'captured',
                ]],
            ], JSON_THROW_ON_ERROR),
        ]);
        $meituanTrace = 'meituan-trace-602';
        $meituanEvidence = [
            'capture_source' => 'xhr:traffic:business_data',
            'source_path' => 'data',
            'source_trace_id' => $meituanTrace,
            'source_url_hash' => hash('sha256', 'fixture:' . $meituanTrace),
        ];
        Db::name('online_daily_data')->insert([
            'id' => 702,
            'tenant_id' => 8,
            'system_hotel_id' => 80,
            'data_source_id' => 68,
            'sync_task_id' => 602,
            'source' => 'meituan',
            'platform' => 'meituan',
            'data_date' => '2026-08-14',
            'data_type' => 'business',
            'dimension' => '',
            'data_period' => 'realtime_snapshot',
            'compare_type' => 'self',
            'amount' => 2026.78,
            'quantity' => 2,
            'book_order_num' => 1,
            'data_value' => 1013.39,
            'list_exposure' => 81,
            'detail_exposure' => 77,
            'flow_rate' => 1.30,
            'ingestion_method' => 'browser_profile',
            'validation_status' => 'verified',
            'readback_verified' => 1,
            'source_trace_id' => $meituanTrace,
            'snapshot_time' => '2026-08-14 10:46:00',
            'is_final' => 0,
            'raw_data' => json_encode([
                'row' => [
                    'lead_price' => 868,
                    'sales_amount' => 2026.78,
                    'sales_room_nights' => 2,
                    'sales_avg_price' => 1013.39,
                    'dataDate' => '2026-08-14',
                    'date_source' => 'page.business_period_selection.readback',
                    '_capture_source' => 'xhr:traffic:business_data',
                    '_source_path' => 'data',
                    'capture_evidence' => $meituanEvidence,
                ],
                'source_trace_id' => $meituanTrace,
                'source_url_hash' => $meituanEvidence['source_url_hash'],
                'capture_evidence' => $meituanEvidence,
                'date_source' => 'page.business_period_selection.readback',
                'captured_at' => '2026-08-14 10:46:00',
                'platform_hotel_identifier_present' => true,
                'platform_hotel_identifier_source' => 'row.poi_id',
                'platform_hotel_identifier_proof' => 'row_field_present',
                'platform_hotel_binding_status' => 'matched',
                'platform_hotel_binding_proof' => 'source_and_response_match',
                'field_facts' => array_map(
                    static fn(string $key): array => [
                        'metric_key' => $key,
                        'status' => 'captured',
                        'stored_value_present' => true,
                        'source_path' => '$.' . $key,
                        'capture_evidence' => $meituanEvidence,
                    ],
                    ['lead_price', 'sales_amount', 'sales_room_nights', 'sales_avg_price']
                ),
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    /** @return array<string,mixed> */
    private function taskRow(
        int $id,
        int $actorId,
        string $status,
        string $finishedAt
    ): array {
        return [
            'id' => $id,
            'tenant_id' => 8,
            'system_hotel_id' => 80,
            'requested_by' => $actorId,
            'data_source_id' => 25,
            'platform' => 'ctrip',
            'status' => $status,
            'finished_at' => $finishedAt,
        ];
    }

    private function invoke(
        CloudThreeSourceHourlyPayloadService $service,
        string $method,
        array $arguments
    ): mixed {
        $reflection = new \ReflectionMethod($service, $method);
        return $reflection->invokeArgs($service, $arguments);
    }
}
