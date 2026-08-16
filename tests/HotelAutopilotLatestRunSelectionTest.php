<?php
declare(strict_types=1);

namespace tests;

use app\service\HotelAutopilotLifecycleService;
use app\service\HotelCollectionRunReceiptService;
use app\service\OtaCollectionAnchorService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class HotelAutopilotLatestRunSelectionTest extends TestCase
{
    private const TENANT_ID = 101;
    private const HOTEL_ID = 80;
    private const PLAN_ID = 9;

    private static array $databaseConfig;
    private static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'hotel_autopilot_latest_run_' . getmypid() . '.sqlite';
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
            'hotel_collection_plan_run_sources',
            'hotel_collection_plan_runs',
            'dingdandao_operating_target_captures',
            'online_daily_data',
            'platform_data_raw_records',
            'platform_data_sync_tasks',
            'platform_data_sources',
        ] as $table) {
            Db::name($table)->delete(true);
        }
    }

    public function testVerifiedCacheReuseForSameDateKeepsTrustedProducer(): void
    {
        $trustedRunId = '11111111-1111-4111-8111-111111111111';
        $this->seedTrustedRun($trustedRunId, '2026-08-10', self::PLAN_ID);
        $this->seedParent(
            '22222222-2222-4222-8222-222222222222',
            '2026-08-11',
            self::PLAN_ID,
            'succeeded',
            'collection_receipt_contract_mismatch',
            true
        );
        $this->seedParent(
            '33333333-3333-4333-8333-333333333333',
            '2026-08-12',
            self::PLAN_ID + 1,
            'succeeded',
            '',
            true
        );
        $this->seedNoCollectionOutcome(
            '44444444-4444-4444-8444-444444444444',
            '2026-08-10',
            'verified_cache_reused'
        );

        $selected = $this->loadLatestRun(self::PLAN_ID);

        self::assertSame('succeeded', $selected['status']);
        self::assertSame($trustedRunId, $selected['dispatcher_run_id']);
        self::assertSame('2026-08-10', $selected['business_date']);
        self::assertSame(self::PLAN_ID, $selected['plan_id']);
        self::assertTrue($selected['ledger_structure_verified']);
        self::assertTrue($selected['readback_verified']);
    }

    public function testLatestLoginFailureBlocksOlderTrustedSuccess(): void
    {
        $this->seedTrustedRun(
            '88888888-8888-4888-8888-888888888888',
            '2026-08-10',
            self::PLAN_ID
        );
        $latestRunId = '99999999-9999-4999-8999-999999999999';
        $this->seedParent(
            $latestRunId,
            '2026-08-11',
            self::PLAN_ID,
            'blocked',
            'login_required'
        );

        $selected = $this->loadLatestRun(self::PLAN_ID);

        self::assertSame('blocked', $selected['status']);
        self::assertSame('login_required', $selected['failure_code']);
        self::assertSame($latestRunId, $selected['dispatcher_run_id']);
        self::assertSame('2026-08-11', $selected['business_date']);
    }

    public function testVerifiedCacheReuseNeverBorrowsAnotherBusinessDate(): void
    {
        $this->seedTrustedRun(
            '12121212-1212-4212-8212-121212121212',
            '2026-08-10',
            self::PLAN_ID
        );
        $latestRunId = '13131313-1313-4313-8313-131313131313';
        $this->seedNoCollectionOutcome(
            $latestRunId,
            '2026-08-11',
            'verified_cache_reused'
        );

        $selected = $this->loadLatestRun(self::PLAN_ID);

        self::assertSame('skipped', $selected['status']);
        self::assertSame('verified_cache_reused', $selected['failure_code']);
        self::assertSame($latestRunId, $selected['dispatcher_run_id']);
        self::assertSame('2026-08-11', $selected['business_date']);
        self::assertTrue($selected['readback_verified']);
    }

    public function testWithoutTrustedSucceededProducerReturnsLatestTerminalFailure(): void
    {
        $this->seedTrustedRun(
            '77777777-7777-4777-8777-777777777777',
            '2026-08-09',
            self::PLAN_ID + 1
        );
        $this->seedParent(
            '55555555-5555-4555-8555-555555555555',
            '2026-08-10',
            self::PLAN_ID,
            'blocked',
            'login_required'
        );
        $latestRunId = '66666666-6666-4666-8666-666666666666';
        $this->seedParent(
            $latestRunId,
            '2026-08-11',
            self::PLAN_ID,
            'skipped',
            'no_changes_detected'
        );

        $selected = $this->loadLatestRun(self::PLAN_ID);

        self::assertSame('skipped', $selected['status']);
        self::assertSame($latestRunId, $selected['dispatcher_run_id']);
        self::assertSame(self::PLAN_ID, $selected['plan_id']);
        self::assertSame('no_changes_detected', $selected['failure_code']);
        self::assertFalse($selected['readback_verified']);
    }

    /** @return array<string,mixed> */
    private function loadLatestRun(int $planId): array
    {
        $method = new ReflectionMethod(HotelAutopilotLifecycleService::class, 'loadLatestRun');
        $method->setAccessible(true);
        $result = $method->invoke(
            new HotelAutopilotLifecycleService(),
            self::TENANT_ID,
            self::HOTEL_ID,
            $planId
        );
        self::assertIsArray($result);
        return $result;
    }

    private function seedTrustedRun(string $dispatcherRunId, string $businessDate, int $planId): void
    {
        $scopeHash = str_repeat('a', 64);
        $anchorHash = str_repeat('b', 64);
        $sourceIds = [25, 68];
        $trustDigest = hash('sha256', self::json([
            'dispatcher_run_id' => $dispatcherRunId,
            'system_hotel_id' => self::HOTEL_ID,
            'business_date' => $businessDate,
            'collection_anchor_contract_version' => OtaCollectionAnchorService::CONTRACT_VERSION,
            'collection_anchor_hash' => $anchorHash,
            'source_ids' => $sourceIds,
        ]));
        $runId = (int)Db::name(HotelCollectionRunReceiptService::RUN_TABLE)->insertGetId([
            'dispatcher_run_id' => $dispatcherRunId,
            'tenant_id' => self::TENANT_ID,
            'system_hotel_id' => self::HOTEL_ID,
            'business_date' => $businessDate,
            'run_mode' => 'scheduled',
            'trigger_type' => 'scheduler',
            'plan_id' => $planId,
            'plan_version' => 1,
            'plan_hash' => str_repeat('c', 64),
            'scope_hash' => $scopeHash,
            'execution_owner_user_id' => 7,
            'status' => 'succeeded',
            'failure_stage' => '',
            'failure_code' => '',
            'collection_anchor_contract_version' => OtaCollectionAnchorService::CONTRACT_VERSION,
            'collection_anchor_hash' => $anchorHash,
            'trust_receipt_digest' => $trustDigest,
            'page_status' => 'not_evaluated',
            'page_receipt_id' => null,
            'page_contract_hash' => null,
            'pms_status' => 'verified',
            'pms_provider' => 'dingdandao_pms',
            'pms_capture_id' => '501',
            'pms_readback_verified' => 1,
            'receipt_json' => self::json([
                'scope_hash' => $scopeHash,
                'collection_verified' => true,
            ]),
            'started_at' => '2026-08-12 08:30:00',
            'finished_at' => '2026-08-12 08:50:00',
            'create_time' => '2026-08-12 08:30:00',
            'update_time' => '2026-08-12 08:50:00',
        ]);

        Db::name('dingdandao_operating_target_captures')->insert([
            'id' => 501,
            'tenant_id' => self::TENANT_ID,
            'hotel_id' => self::HOTEL_ID,
            'provider' => 'dingdandao_pms',
            'business_date' => $businessDate,
            'identity_status' => 'matched',
            'capture_status' => 'verified',
            'quality_status' => 'verified',
            'reconciliation_status' => 'matched',
            'readback_status' => 'readback_verified',
            'detail_row_count' => 1,
        ]);

        foreach ([
            ['platform' => 'ctrip', 'source_id' => 25, 'sync_task_id' => 125, 'row_id' => 1001],
            ['platform' => 'meituan', 'source_id' => 68, 'sync_task_id' => 168, 'row_id' => 1002],
        ] as $source) {
            $platform = (string)$source['platform'];
            $sourceId = (int)$source['source_id'];
            $syncTaskId = (int)$source['sync_task_id'];
            $rowId = (int)$source['row_id'];
            $rowIdsHash = hash('sha256', (string)$rowId);
            $evidenceDigest = hash('sha256', self::json([
                'platform' => $platform,
                'data_source_id' => $sourceId,
                'sync_task_id' => $syncTaskId,
                'row_ids' => [$rowId],
            ]));

            Db::name('platform_data_sources')->insert([
                'id' => $sourceId,
                'tenant_id' => self::TENANT_ID,
                'user_id' => 7,
                'system_hotel_id' => self::HOTEL_ID,
                'platform' => $platform,
                'ingestion_method' => 'browser_profile',
                'config_json' => '{}',
            ]);
            Db::name('platform_data_sync_tasks')->insert([
                'id' => $syncTaskId,
                'tenant_id' => self::TENANT_ID,
                'data_source_id' => $sourceId,
                'system_hotel_id' => self::HOTEL_ID,
                'platform' => $platform,
                'ingestion_method' => 'browser_profile',
                'trigger_type' => 'daily_profile_reuse',
                'status' => 'success',
                'stats_json' => self::json(['dispatcher_run_id' => $dispatcherRunId]),
            ]);
            Db::name('platform_data_raw_records')->insert([
                'id' => $syncTaskId,
                'tenant_id' => self::TENANT_ID,
                'data_source_id' => $sourceId,
                'sync_task_id' => $syncTaskId,
                'system_hotel_id' => self::HOTEL_ID,
                'platform' => $platform,
                'ingestion_method' => 'browser_profile',
            ]);
            Db::name('online_daily_data')->insert([
                'id' => $rowId,
                'tenant_id' => self::TENANT_ID,
                'data_source_id' => $sourceId,
                'sync_task_id' => $syncTaskId,
                'system_hotel_id' => self::HOTEL_ID,
                'platform' => $platform,
                'data_date' => $businessDate,
                'data_period' => 'historical_daily',
                'readback_verified' => 1,
            ]);
            Db::name(HotelCollectionRunReceiptService::SOURCE_TABLE)->insert([
                'run_id' => $runId,
                'platform' => $platform,
                'data_source_id' => $sourceId,
                'ingestion_method' => 'browser_profile',
                'status' => 'success',
                'platform_sync_task_id' => $syncTaskId,
                'local_collector_task_id' => null,
                'saved_row_count' => 1,
                'readback_row_count' => 1,
                'readback_verified' => 1,
                'evidence_digest' => $evidenceDigest,
                'failure_stage' => '',
                'failure_code' => '',
                'page_acceptance_status' => 'not_evaluated',
                'page_acceptance_log_id' => null,
                'receipt_json' => self::json([
                    'dispatcher_run_id' => $dispatcherRunId,
                    'readback_verified' => true,
                    'row_count' => 1,
                    'row_ids_hash' => $rowIdsHash,
                ]),
                'started_at' => '2026-08-12 08:30:00',
                'finished_at' => '2026-08-12 08:50:00',
                'create_time' => '2026-08-12 08:30:00',
                'update_time' => '2026-08-12 08:50:00',
            ]);
        }
    }

    private function seedParent(
        string $dispatcherRunId,
        string $businessDate,
        int $planId,
        string $status,
        string $failureCode,
        bool $claimsSuccess = false
    ): void {
        Db::name(HotelCollectionRunReceiptService::RUN_TABLE)->insert([
            'dispatcher_run_id' => $dispatcherRunId,
            'tenant_id' => self::TENANT_ID,
            'system_hotel_id' => self::HOTEL_ID,
            'business_date' => $businessDate,
            'run_mode' => 'scheduled',
            'trigger_type' => 'scheduler',
            'plan_id' => $planId,
            'plan_version' => 1,
            'plan_hash' => str_repeat('c', 64),
            'scope_hash' => str_repeat('d', 64),
            'execution_owner_user_id' => 7,
            'status' => $status,
            'failure_stage' => $status === 'blocked' ? 'plan_gate' : 'collection',
            'failure_code' => $failureCode,
            'collection_anchor_contract_version' => $claimsSuccess
                ? OtaCollectionAnchorService::CONTRACT_VERSION
                : null,
            'collection_anchor_hash' => $claimsSuccess ? str_repeat('e', 64) : null,
            'trust_receipt_digest' => $claimsSuccess ? str_repeat('f', 64) : null,
            'page_status' => 'not_evaluated',
            'page_receipt_id' => null,
            'page_contract_hash' => null,
            'pms_status' => 'not_run',
            'pms_provider' => 'dingdandao_pms',
            'pms_capture_id' => null,
            'pms_readback_verified' => null,
            'receipt_json' => '{}',
            'started_at' => '2026-08-12 09:00:00',
            'finished_at' => '2026-08-12 09:01:00',
            'create_time' => '2026-08-12 09:00:00',
            'update_time' => '2026-08-12 09:01:00',
        ]);
    }

    private function seedNoCollectionOutcome(
        string $dispatcherRunId,
        string $businessDate,
        string $outcomeCode
    ): void {
        $service = new HotelCollectionRunReceiptService();
        $service->begin([
            'dispatcher_run_id' => $dispatcherRunId,
            'tenant_id' => self::TENANT_ID,
            'system_hotel_id' => self::HOTEL_ID,
            'business_date' => $businessDate,
            'run_mode' => 'daily',
            'plan_id' => self::PLAN_ID,
            'plan_version' => 1,
            'plan_hash' => str_repeat('c', 64),
            'scope_hash' => str_repeat('d', 64),
            'execution_owner_user_id' => 7,
            'collection_allowed' => true,
            'expected_source_ids' => [25, 68],
            'expected_platforms' => ['ctrip', 'meituan'],
            'sources' => [
                'ctrip' => [
                    'data_source_id' => 25,
                    'ingestion_method' => 'browser_profile',
                ],
                'meituan' => [
                    'data_source_id' => 68,
                    'ingestion_method' => 'browser_profile',
                ],
            ],
            'failure_reasons' => [],
        ]);
        $service->markNoCollectionOutcome(
            $dispatcherRunId,
            self::HOTEL_ID,
            $businessDate,
            $outcomeCode
        );
    }

    private static function json(array $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    private static function createSchema(): void
    {
        Db::execute('CREATE TABLE platform_data_sources (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            platform TEXT NOT NULL,
            ingestion_method TEXT NOT NULL,
            config_json TEXT NOT NULL DEFAULT \'{}\'
        )');
        Db::execute('CREATE TABLE platform_data_sync_tasks (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            data_source_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            platform TEXT NOT NULL,
            ingestion_method TEXT NOT NULL,
            trigger_type TEXT NOT NULL,
            status TEXT NOT NULL,
            stats_json TEXT NOT NULL
        )');
        Db::execute('CREATE TABLE platform_data_raw_records (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            data_source_id INTEGER NOT NULL,
            sync_task_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            platform TEXT NOT NULL,
            ingestion_method TEXT NOT NULL
        )');
        Db::execute('CREATE TABLE online_daily_data (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            data_source_id INTEGER NOT NULL,
            sync_task_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            platform TEXT NOT NULL,
            data_date TEXT NOT NULL,
            data_period TEXT NOT NULL,
            readback_verified INTEGER NOT NULL
        )');
        Db::execute('CREATE TABLE dingdandao_operating_target_captures (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            hotel_id INTEGER NOT NULL,
            provider TEXT NOT NULL,
            business_date TEXT NOT NULL,
            identity_status TEXT NOT NULL,
            capture_status TEXT NOT NULL,
            quality_status TEXT NOT NULL,
            reconciliation_status TEXT NOT NULL,
            readback_status TEXT NOT NULL,
            detail_row_count INTEGER NOT NULL
        )');
        Db::execute('CREATE TABLE hotel_collection_plan_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            dispatcher_run_id TEXT NOT NULL UNIQUE,
            tenant_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            business_date TEXT NOT NULL,
            run_mode TEXT NOT NULL,
            trigger_type TEXT NOT NULL,
            plan_id INTEGER NULL,
            plan_version INTEGER NOT NULL,
            plan_hash TEXT NOT NULL,
            scope_hash TEXT NOT NULL,
            execution_owner_user_id INTEGER NULL,
            status TEXT NOT NULL,
            failure_stage TEXT NOT NULL,
            failure_code TEXT NOT NULL,
            collection_anchor_contract_version TEXT NULL,
            collection_anchor_hash TEXT NULL,
            trust_receipt_digest TEXT NULL,
            page_status TEXT NOT NULL,
            page_receipt_id INTEGER NULL,
            page_contract_hash TEXT NULL,
            pms_status TEXT NOT NULL,
            pms_provider TEXT NULL,
            pms_capture_id TEXT NULL,
            pms_readback_verified INTEGER NULL,
            receipt_json TEXT NOT NULL,
            started_at TEXT NOT NULL,
            finished_at TEXT NULL,
            create_time TEXT NOT NULL,
            update_time TEXT NOT NULL
        )');
        Db::execute('CREATE TABLE hotel_collection_plan_run_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            run_id INTEGER NOT NULL,
            platform TEXT NOT NULL,
            data_source_id INTEGER NULL,
            ingestion_method TEXT NOT NULL,
            status TEXT NOT NULL,
            platform_sync_task_id INTEGER NULL,
            local_collector_task_id INTEGER NULL,
            saved_row_count INTEGER NOT NULL,
            readback_row_count INTEGER NOT NULL,
            readback_verified INTEGER NOT NULL,
            evidence_digest TEXT NULL,
            failure_stage TEXT NOT NULL,
            failure_code TEXT NOT NULL,
            page_acceptance_status TEXT NOT NULL,
            page_acceptance_log_id INTEGER NULL,
            receipt_json TEXT NOT NULL,
            started_at TEXT NOT NULL,
            finished_at TEXT NULL,
            create_time TEXT NOT NULL,
            update_time TEXT NOT NULL,
            UNIQUE (run_id, platform)
        )');
    }
}
