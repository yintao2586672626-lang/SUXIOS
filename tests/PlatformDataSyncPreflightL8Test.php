<?php
declare(strict_types=1);

namespace Tests;

use app\contract\DataSourceAdapter;
use app\service\OtaCredentialEnvelope;
use app\service\OtaCredentialVault;
use app\service\PlatformDataSyncService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class PlatformDataSyncPreflightL8Test extends TestCase
{
    private const SYSTEM_HOTEL_ID = 101;
    private const TENANT_ID = 7;
    private const TARGET_DATE = '2026-07-14';
    private const STALE_DATA_DATE = '2026-07-13';

    private const REQUIRED_TRAFFIC_METRICS = [
        'list_exposure',
        'detail_exposure',
        'flow_rate',
        'order_filling_num',
        'order_submit_num',
    ];

    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . '/platform_data_sync_preflight_l8_' . getmypid() . '.sqlite';
        @unlink(self::$sqlitePath);

        $config = self::$originalDatabaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$sqlitePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($config, 'database');
        Db::connect(null, true);

        self::createSchema();
        Db::name('hotels')->insertAll([
            ['id' => self::SYSTEM_HOTEL_ID, 'tenant_id' => self::TENANT_ID, 'name' => 'TC-145 Hotel'],
            ['id' => 102, 'tenant_id' => 8, 'name' => 'Out-of-scope Hotel'],
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        Db::connect()->close();
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        if (is_file(self::$sqlitePath) && !unlink(self::$sqlitePath)) {
            throw new RuntimeException('Unable to remove TC-145 SQLite fixture.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'online_daily_data',
            'platform_data_raw_records',
            'platform_data_sync_logs',
            'platform_data_sync_tasks',
            'platform_data_sources',
            'ota_credentials',
            'ota_credential_audit_logs',
            'ota_profile_bindings',
        ] as $table) {
            Db::name($table)->delete(true);
        }
    }

    /**
     * Access denial and upstream failure intentionally short-circuit later
     * factors. Those variants verify the real precedence boundary and do not
     * claim that completeness/freshness reached downstream persistence.
     *
     * @param array{actor_scope: string, data_completeness: string, freshness: string, upstream_state: string} $factors
     */
    #[DataProvider('l8VariantProvider')]
    public function testTc145L8PreflightAndSyncBoundaries(string $caseId, array $factors): void
    {
        $sourceId = $this->createBrowserProfileSource($caseId);
        $adapter = $this->adapterFor($caseId, $factors);
        $service = $this->service($adapter);
        $message = $caseId . ' factors=' . json_encode($factors, JSON_UNESCAPED_SLASHES);
        $options = [
            'interactive_browser' => true,
            'target_date' => self::TARGET_DATE,
            'data_period' => 'historical_daily',
            'capture_sections' => 'traffic',
            'trigger_type' => 'manual',
        ];

        if ($factors['actor_scope'] === 'restricted') {
            try {
                $service->syncDataSource($this->restrictedUser(), $sourceId, $options);
                self::fail($message . ' restricted actor unexpectedly reached synchronization');
            } catch (RuntimeException $exception) {
                self::assertSame(403, $exception->getCode(), $message);
                self::assertSame('Forbidden.', $exception->getMessage(), $message);
            }

            self::assertSame(0, $adapter->calls, $message);
            self::assertSame(0, Db::name('platform_data_sync_tasks')->count(), $message);
            self::assertSame(0, Db::name('platform_data_raw_records')->count(), $message);
            self::assertSame(0, Db::name('online_daily_data')->count(), $message);
            $this->assertAdapterFixtureRepresentsFactors($adapter, $factors, $message);
            return;
        }

        $result = $service->syncDataSource($this->authorizedUser(), $sourceId, $options);

        self::assertSame(1, $adapter->calls, $message);
        self::assertSame(self::TARGET_DATE, $adapter->seenOptions['target_date'] ?? null, $message);
        self::assertSame('traffic', $adapter->seenOptions['capture_sections'] ?? null, $message);
        $this->assertAdapterFixtureRepresentsFactors($adapter, $factors, $message);

        $task = Db::name('platform_data_sync_tasks')->where('id', (int)$result['task_id'])->find();
        self::assertIsArray($task, $message);
        self::assertSame(self::SYSTEM_HOTEL_ID, (int)$task['system_hotel_id'], $message);
        self::assertSame(self::TENANT_ID, (int)$task['tenant_id'], $message);
        self::assertSame($sourceId, (int)$task['data_source_id'], $message);

        if ($factors['upstream_state'] === 'failure') {
            self::assertSame('failed', $result['status'], $message);
            self::assertSame('failed', $task['status'], $message);
            self::assertNotEmpty($task['finished_at'], $message);
            self::assertSame(0, (int)$result['saved_count'], $message);
            self::assertSame(0, Db::name('platform_data_raw_records')->count(), $message);
            self::assertSame(0, Db::name('online_daily_data')->count(), $message);
            self::assertSame('failed', $result['sync_diagnostics']['adapter_status'] ?? null, $message);
            return;
        }

        self::assertSame(1, Db::name('platform_data_raw_records')->count(), $message);
        self::assertSame(1, (int)$result['saved_count'], $message);
        self::assertSame(1, Db::name('online_daily_data')->count(), $message);

        $stored = Db::name('online_daily_data')->where('sync_task_id', (int)$result['task_id'])->find();
        self::assertIsArray($stored, $message);
        self::assertSame(1, (int)$stored['readback_verified'], $message);
        self::assertNotEmpty($stored['readback_verified_at'], $message);
        self::assertSame(self::SYSTEM_HOTEL_ID, (int)$stored['system_hotel_id'], $message);
        self::assertSame(self::TENANT_ID, (int)$stored['tenant_id'], $message);
        self::assertSame('CTRIP-TC145-101', $stored['hotel_id'], $message);
        self::assertSame(
            $factors['freshness'] === 'stale' ? self::STALE_DATA_DATE : self::TARGET_DATE,
            $stored['data_date'],
            $message
        );
        $runReadback = is_array($result['run_readback'] ?? null) ? $result['run_readback'] : [];
        if ($factors['freshness'] === 'fresh') {
            self::assertTrue($runReadback['readback_verified'] ?? false, $message);
            self::assertSame((int)$result['task_id'], (int)($runReadback['sync_task_id'] ?? 0), $message);
            self::assertSame($sourceId, (int)($runReadback['data_source_id'] ?? 0), $message);
            self::assertSame(self::TARGET_DATE, $runReadback['target_date'] ?? null, $message);
            self::assertSame('historical_daily', $runReadback['data_period'] ?? null, $message);
            self::assertContains((int)$stored['id'], $runReadback['row_ids'] ?? [], $message);
            self::assertNotEmpty($runReadback['source_trace_ids'] ?? [], $message);
        } else {
            self::assertFalse($runReadback['readback_verified'] ?? true, $message);
        }

        $diagnostics = $result['sync_diagnostics'];
        self::assertIsArray($diagnostics, $message);
        if ($factors['freshness'] === 'stale') {
            self::assertSame('partial_success', $result['status'], $message);
            self::assertSame('blocked', $diagnostics['p0_status'] ?? null, $message);
            self::assertSame(0, $diagnostics['target_date_traffic_rows'] ?? null, $message);
            self::assertContains('target_date_traffic_rows', $diagnostics['missing_inputs'] ?? [], $message);
        } else {
            self::assertSame('success', $result['status'], $message);
            self::assertSame('ready', $diagnostics['p0_status'] ?? null, $message);
            self::assertSame(1, $diagnostics['target_date_traffic_rows'] ?? null, $message);
        }

        $rawData = json_decode((string)$stored['raw_data'], true, 512, JSON_THROW_ON_ERROR);
        $fieldFacts = $rawData['field_fact_summary'] ?? [];
        if ($factors['data_completeness'] === 'missing_required') {
            self::assertSame(0, $fieldFacts['captured_count'] ?? null, $message);
            foreach (self::REQUIRED_TRAFFIC_METRICS as $metric) {
                self::assertContains($metric, $fieldFacts['missing_metric_keys'] ?? [], $message);
            }
            if ($factors['freshness'] === 'fresh') {
                self::assertContains('traffic_field_facts', $diagnostics['missing_inputs'] ?? [], $message);
            }
        } else {
            foreach (self::REQUIRED_TRAFFIC_METRICS as $metric) {
                self::assertContains($metric, $fieldFacts['captured_metric_keys'] ?? [], $message);
            }
        }
    }

    public function testRunReadbackVerifiesTargetDateSubsetOfMultiPeriodReceipt(): void
    {
        $sourceId = $this->createBrowserProfileSource('DX-1161');
        $source = Db::name('platform_data_sources')->where('id', $sourceId)->find();
        self::assertIsArray($source);

        $taskId = (int)Db::name('platform_data_sync_tasks')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'data_source_id' => $sourceId,
            'system_hotel_id' => self::SYSTEM_HOTEL_ID,
            'platform' => 'ctrip',
            'data_type' => 'business',
            'ingestion_method' => 'browser_profile',
            'trigger_type' => 'auto_fetch',
            'status' => 'success',
            'attempt_count' => 1,
            'max_attempts' => 3,
            'started_at' => '2026-07-15 08:00:00',
            'finished_at' => '2026-07-15 08:01:00',
            'create_time' => '2026-07-15 08:00:00',
            'update_time' => '2026-07-15 08:01:00',
        ]);
        $facts = [
            ['metric_key' => 'order_amount', 'status' => 'captured', 'stored_value_present' => true, 'source_key' => 'orderAmount'],
            ['metric_key' => 'room_nights', 'status' => 'captured', 'stored_value_present' => true, 'source_key' => 'roomNights'],
        ];
        $common = [
            'tenant_id' => self::TENANT_ID,
            'hotel_id' => 'CTRIP-TC145-101',
            'hotel_name' => 'TC-145 Hotel',
            'system_hotel_id' => self::SYSTEM_HOTEL_ID,
            'source' => 'ctrip',
            'platform' => 'ctrip',
            'compare_type' => 'self',
            'readback_verified' => 1,
            'readback_verified_at' => '2026-07-15 08:01:00',
            'data_source_id' => $sourceId,
            'sync_task_id' => $taskId,
            'ingestion_method' => 'browser_profile',
            'create_time' => '2026-07-15 08:00:30',
            'update_time' => '2026-07-15 08:00:30',
        ];
        $targetRowId = (int)Db::name('online_daily_data')->insertGetId(array_merge($common, [
            'data_date' => self::TARGET_DATE,
            'data_period' => 'historical_daily',
            'data_type' => 'business',
            'amount' => 1200,
            'quantity' => 3,
            'source_trace_id' => 'target-run-trace',
            'raw_data' => json_encode(['field_facts' => $facts], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]));
        $forecastRowId = (int)Db::name('online_daily_data')->insertGetId(array_merge($common, [
            'data_date' => '2026-07-15',
            'data_period' => 'next_30_days',
            'data_type' => 'traffic_forecast',
            'source_trace_id' => 'forecast-run-trace',
            'raw_data' => '{}',
        ]));
        self::assertCount(1, Db::name('online_daily_data')
            ->field('id,sync_task_id,data_source_id,system_hotel_id,data_date,data_period,readback_verified,source_trace_id,platform,source,hotel_id,hotel_name,data_type,dimension,compare_type,amount,quantity,data_value,raw_data')
            ->where('sync_task_id', $taskId)
            ->where('data_source_id', $sourceId)
            ->where('system_hotel_id', self::SYSTEM_HOTEL_ID)
            ->where('data_date', self::TARGET_DATE)
            ->where('data_period', 'historical_daily')
            ->whereIn('id', [$targetRowId, $forecastRowId])
            ->where('platform', 'ctrip')
            ->where('source', 'ctrip')
            ->order('id', 'asc')
            ->select()
            ->toArray());

        $service = $this->service($this->adapterFor(
            'DX-1161',
            self::factors('authorized', 'complete', 'fresh', 'success')
        ));
        $method = new \ReflectionMethod($service, 'buildRunReadbackReceipt');
        $method->setAccessible(true);
        $receipt = $method->invoke(
            $service,
            $taskId,
            $source,
            [
                'readback_verified' => true,
                'readback_count' => 2,
                'row_ids' => [$targetRowId, $forecastRowId],
            ],
            ['data_date' => self::TARGET_DATE, 'data_period' => 'historical_daily'],
            ['started_at' => '2026-07-15 08:00:00']
        );

        self::assertTrue(
            $receipt['readback_verified'],
            json_encode($receipt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        self::assertSame([$targetRowId], $receipt['row_ids']);
        self::assertSame(1, $receipt['readback_count']);
        self::assertSame(['revenue', 'room_nights', 'adr'], $receipt['verified_metric_keys']);

        $missingTargetReceipt = $method->invoke(
            $service,
            $taskId,
            $source,
            ['readback_verified' => true, 'readback_count' => 1, 'row_ids' => [$forecastRowId]],
            ['data_date' => self::TARGET_DATE, 'data_period' => 'historical_daily'],
            ['started_at' => '2026-07-15 08:00:00']
        );
        self::assertFalse($missingTargetReceipt['readback_verified']);
        self::assertSame('run_readback_receipt_mismatch', $missingTargetReceipt['failure_reason']);
    }

    /**
     * @return array<string, array{0: string, 1: array{actor_scope: string, data_completeness: string, freshness: string, upstream_state: string}}>
     */
    public static function l8VariantProvider(): array
    {
        return [
            'DX-1153 authorized complete fresh success' => ['DX-1153', self::factors('authorized', 'complete', 'fresh', 'success')],
            'DX-1154 authorized complete stale failure' => ['DX-1154', self::factors('authorized', 'complete', 'stale', 'failure')],
            'DX-1155 authorized missing fresh failure' => ['DX-1155', self::factors('authorized', 'missing_required', 'fresh', 'failure')],
            'DX-1156 authorized missing stale success' => ['DX-1156', self::factors('authorized', 'missing_required', 'stale', 'success')],
            'DX-1157 restricted complete fresh failure' => ['DX-1157', self::factors('restricted', 'complete', 'fresh', 'failure')],
            'DX-1158 restricted complete stale success' => ['DX-1158', self::factors('restricted', 'complete', 'stale', 'success')],
            'DX-1159 restricted missing fresh success' => ['DX-1159', self::factors('restricted', 'missing_required', 'fresh', 'success')],
            'DX-1160 restricted missing stale failure' => ['DX-1160', self::factors('restricted', 'missing_required', 'stale', 'failure')],
        ];
    }

    private static function createSchema(): void
    {
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name VARCHAR(100))');
        Db::execute('CREATE TABLE ota_credentials (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, platform VARCHAR(20) NOT NULL, config_id VARCHAR(100) NOT NULL, encrypted_payload TEXT NOT NULL, payload_version INTEGER NOT NULL, key_id VARCHAR(100) NOT NULL, secret_mask VARCHAR(255) NOT NULL, credential_status VARCHAR(20) NOT NULL, created_by INTEGER NOT NULL, rotated_at DATETIME, last_used_at DATETIME, revoked_at DATETIME, create_time DATETIME, update_time DATETIME, UNIQUE(tenant_id,system_hotel_id,platform,config_id))');
        Db::execute('CREATE TABLE ota_credential_audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, credential_id INTEGER NOT NULL DEFAULT 0, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, platform VARCHAR(20) NOT NULL, config_id_hash VARCHAR(64) NOT NULL, event_sequence INTEGER NOT NULL, credential_version INTEGER NOT NULL DEFAULT 0, event_type VARCHAR(40) NOT NULL, outcome VARCHAR(20) NOT NULL, failure_code VARCHAR(80) NOT NULL DEFAULT \'\', actor_id INTEGER NOT NULL DEFAULT 0, payload_digest VARCHAR(64) NOT NULL DEFAULT \'\', previous_entry_hash VARCHAR(64) NOT NULL DEFAULT \'\', entry_hash VARCHAR(64) NOT NULL, occurred_at DATETIME NOT NULL, UNIQUE(tenant_id,system_hotel_id,platform,config_id_hash,event_sequence), UNIQUE(entry_hash))');
        Db::execute('CREATE TABLE ota_profile_bindings (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, platform VARCHAR(20) NOT NULL, profile_key_hash VARCHAR(64) NOT NULL, binding_status VARCHAR(20) NOT NULL, bound_by INTEGER, revoked_by INTEGER, create_time DATETIME, update_time DATETIME, UNIQUE(platform,profile_key_hash))');
        Db::execute('CREATE TABLE platform_data_sources (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, system_hotel_id INTEGER, user_id INTEGER, name VARCHAR(120) NOT NULL, platform VARCHAR(50) NOT NULL, data_type VARCHAR(50) NOT NULL, ingestion_method VARCHAR(30) NOT NULL, status VARCHAR(30) NOT NULL, enabled INTEGER NOT NULL, config_json TEXT, secret_json TEXT, last_sync_time DATETIME, last_sync_status VARCHAR(30), last_error TEXT, created_by INTEGER, updated_by INTEGER, create_time DATETIME, update_time DATETIME)');
        Db::execute('CREATE TABLE platform_data_sync_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, data_source_id INTEGER, system_hotel_id INTEGER, platform VARCHAR(50) NOT NULL, data_type VARCHAR(50) NOT NULL, ingestion_method VARCHAR(30) NOT NULL, trigger_type VARCHAR(30) NOT NULL, status VARCHAR(30) NOT NULL, attempt_count INTEGER NOT NULL, max_attempts INTEGER NOT NULL, started_at DATETIME, finished_at DATETIME, next_retry_at DATETIME, requested_by INTEGER, message TEXT, stats_json TEXT, create_time DATETIME, update_time DATETIME)');
        Db::execute('CREATE TABLE platform_data_sync_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, sync_task_id INTEGER, data_source_id INTEGER, system_hotel_id INTEGER, level VARCHAR(20), event VARCHAR(80), message TEXT, context_json TEXT, create_time DATETIME)');
        Db::execute('CREATE TABLE platform_data_raw_records (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, data_source_id INTEGER, sync_task_id INTEGER, system_hotel_id INTEGER, platform VARCHAR(50), data_type VARCHAR(50), ingestion_method VARCHAR(30), payload_hash VARCHAR(64), raw_payload TEXT, http_status INTEGER, received_at DATETIME, create_time DATETIME)');
        Db::execute('CREATE TABLE online_daily_data (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id VARCHAR(50), hotel_name VARCHAR(100), system_hotel_id INTEGER, data_date DATE NOT NULL, amount DECIMAL(12,2), quantity INTEGER, book_order_num INTEGER, comment_score DECIMAL(3,1), qunar_comment_score DECIMAL(3,1), data_value DECIMAL(12,2), source VARCHAR(50), dimension VARCHAR(100), data_type VARCHAR(50), platform VARCHAR(50), compare_type VARCHAR(50), list_exposure INTEGER, detail_exposure INTEGER, flow_rate DECIMAL(12,4), order_filling_num INTEGER, order_submit_num INTEGER, validation_status VARCHAR(60), validation_flags TEXT, readback_verified INTEGER NOT NULL DEFAULT 0, readback_verified_at DATETIME, data_source_id INTEGER, sync_task_id INTEGER, ingestion_method VARCHAR(30), source_trace_id VARCHAR(100), data_period VARCHAR(30), snapshot_time DATETIME, snapshot_bucket VARCHAR(20), is_final INTEGER, raw_data TEXT, create_time DATETIME, update_time DATETIME)');
    }

    private function createBrowserProfileSource(string $caseId): int
    {
        $profileId = 'tc145-profile-' . strtolower($caseId);
        $sourceId = (int)Db::name('platform_data_sources')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'system_hotel_id' => self::SYSTEM_HOTEL_ID,
            'user_id' => 9,
            'name' => 'TC-145 Ctrip Profile ' . $caseId,
            'platform' => 'ctrip',
            'data_type' => 'traffic',
            'ingestion_method' => 'browser_profile',
            'status' => 'ready',
            'enabled' => 1,
            'config_json' => json_encode([
                'profile_id' => $profileId,
                'hotel_id' => 'CTRIP-TC145-101',
                'manual_login_state_verified' => true,
                'profile_status' => 'logged_in',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'secret_json' => '{}',
            'created_by' => 9,
            'updated_by' => 9,
            'create_time' => '2026-07-15 09:00:00',
            'update_time' => '2026-07-15 09:00:00',
        ]);

        Db::name('ota_profile_bindings')->insert([
            'tenant_id' => self::TENANT_ID,
            'system_hotel_id' => self::SYSTEM_HOTEL_ID,
            'platform' => 'ctrip',
            'profile_key_hash' => hash('sha256', $profileId),
            'binding_status' => 'active',
            'bound_by' => 9,
            'create_time' => '2026-07-15 09:00:00',
            'update_time' => '2026-07-15 09:00:00',
        ]);

        return $sourceId;
    }

    /**
     * @param array{actor_scope: string, data_completeness: string, freshness: string, upstream_state: string} $factors
     */
    private function adapterFor(string $caseId, array $factors): object
    {
        $row = [
            'hotel_id' => 'CTRIP-TC145-101',
            'hotel_name' => 'TC-145 Hotel',
            'data_date' => $factors['freshness'] === 'stale' ? self::STALE_DATA_DATE : self::TARGET_DATE,
            'data_type' => 'traffic',
            'source_trace_id' => strtolower($caseId) . '-traffic-row',
        ];
        if ($factors['data_completeness'] === 'complete') {
            $row = array_merge($row, [
                'list_exposure' => 1000,
                'detail_exposure' => 250,
                'flow_rate' => 25.0,
                'order_filling_num' => 20,
                'order_submit_num' => 8,
            ]);
        }

        $response = [
            'status' => $factors['upstream_state'] === 'failure' ? 'failed' : 'success',
            'message' => $factors['upstream_state'] === 'failure' ? 'fixture_upstream_failed' : 'fixture_success',
            'http_status' => $factors['upstream_state'] === 'failure' ? 503 : 200,
            'payload' => [
                'auth_status' => ['ok' => true, 'status' => 'logged_in'],
                'rows' => [$row],
            ],
        ];
        if ($factors['upstream_state'] === 'success') {
            $response['payload']['platform_identity_validation'] = [
                'status' => 'matched',
                'source_validation' => true,
                'validated_identifier' => 'CTRIP-TC145-101',
                'sensitive_values_exposed' => false,
            ];
        }

        return new class($response) implements DataSourceAdapter {
            public int $calls = 0;
            public array $seenOptions = [];
            public array $response;

            public function __construct(array $response)
            {
                $this->response = $response;
            }

            public function supports(array $source): bool
            {
                return ($source['platform'] ?? '') === 'ctrip'
                    && ($source['ingestion_method'] ?? '') === 'browser_profile';
            }

            public function fetch(array $source, array $options = []): array
            {
                $this->calls++;
                $this->seenOptions = $options;
                return $this->response;
            }
        };
    }

    /**
     * @param array{actor_scope: string, data_completeness: string, freshness: string, upstream_state: string} $factors
     */
    private function assertAdapterFixtureRepresentsFactors(object $adapter, array $factors, string $message): void
    {
        $row = $adapter->response['payload']['rows'][0] ?? [];
        self::assertSame(
            $factors['upstream_state'] === 'failure' ? 'failed' : 'success',
            $adapter->response['status'] ?? null,
            $message
        );
        self::assertSame(
            $factors['freshness'] === 'stale' ? self::STALE_DATA_DATE : self::TARGET_DATE,
            $row['data_date'] ?? null,
            $message
        );
        if ($factors['upstream_state'] === 'success') {
            self::assertSame(
                'matched',
                $adapter->response['payload']['platform_identity_validation']['status'] ?? null,
                $message
            );
            self::assertTrue(
                $adapter->response['payload']['platform_identity_validation']['source_validation'] ?? false,
                $message
            );
        }
        foreach (self::REQUIRED_TRAFFIC_METRICS as $metric) {
            if ($factors['data_completeness'] === 'complete') {
                self::assertArrayHasKey($metric, $row, $message);
            } else {
                self::assertArrayNotHasKey($metric, $row, $message);
            }
        }
    }

    private function service(DataSourceAdapter $adapter): PlatformDataSyncService
    {
        $service = new PlatformDataSyncService([$adapter], $this->vault());
        $columns = new \ReflectionProperty($service, 'columns');
        $columns->setAccessible(true);
        $columns->setValue($service, [
            'platform_data_sources' => array_fill_keys([
                'id', 'tenant_id', 'system_hotel_id', 'user_id', 'name', 'platform', 'data_type', 'ingestion_method',
                'status', 'enabled', 'config_json', 'secret_json', 'last_sync_time', 'last_sync_status', 'last_error',
                'created_by', 'updated_by', 'create_time', 'update_time',
            ], true),
            'platform_data_sync_tasks' => array_fill_keys([
                'id', 'tenant_id', 'data_source_id', 'system_hotel_id', 'platform', 'data_type', 'ingestion_method',
                'trigger_type', 'status', 'attempt_count', 'max_attempts', 'started_at', 'finished_at', 'next_retry_at',
                'requested_by', 'message', 'stats_json', 'create_time', 'update_time',
            ], true),
            'platform_data_sync_logs' => array_fill_keys([
                'id', 'tenant_id', 'sync_task_id', 'data_source_id', 'system_hotel_id', 'level', 'event', 'message',
                'context_json', 'create_time',
            ], true),
            'platform_data_raw_records' => array_fill_keys([
                'id', 'tenant_id', 'data_source_id', 'sync_task_id', 'system_hotel_id', 'platform', 'data_type',
                'ingestion_method', 'payload_hash', 'raw_payload', 'http_status', 'received_at', 'create_time',
            ], true),
            'online_daily_data' => array_fill_keys([
                'id', 'tenant_id', 'hotel_id', 'hotel_name', 'system_hotel_id', 'data_date', 'amount', 'quantity',
                'book_order_num', 'comment_score', 'qunar_comment_score', 'data_value', 'source', 'dimension',
                'data_type', 'platform', 'compare_type', 'list_exposure', 'detail_exposure', 'flow_rate',
                'order_filling_num', 'order_submit_num', 'validation_status', 'validation_flags',
                'readback_verified', 'readback_verified_at', 'data_source_id',
                'sync_task_id', 'ingestion_method', 'source_trace_id', 'data_period', 'snapshot_time',
                'snapshot_bucket', 'is_final', 'raw_data', 'create_time', 'update_time',
            ], true),
        ]);
        return $service;
    }

    private function vault(): OtaCredentialVault
    {
        return new OtaCredentialVault(
            new OtaCredentialEnvelope(base64_encode(str_repeat('l', 32)), 'platform-sync-l8-key'),
            'platform-sync-l8-key'
        );
    }

    private function authorizedUser(): object
    {
        return new class {
            public int $id = 9;
            public int $tenant_id = 7;

            public function isSuperAdmin(): bool
            {
                return false;
            }

            public function hasHotelPermission(int $hotelId, string $permission): bool
            {
                return $hotelId === 101 && $permission === 'can_fetch_online_data';
            }

            public function getPermittedHotelIds(): array
            {
                return [101];
            }
        };
    }

    private function restrictedUser(): object
    {
        return new class {
            public int $id = 10;
            public int $tenant_id = 7;

            public function isSuperAdmin(): bool
            {
                return false;
            }

            public function hasHotelPermission(int $hotelId, string $permission): bool
            {
                return false;
            }

            public function getPermittedHotelIds(): array
            {
                return [];
            }
        };
    }

    /**
     * @return array{actor_scope: string, data_completeness: string, freshness: string, upstream_state: string}
     */
    private static function factors(
        string $actorScope,
        string $dataCompleteness,
        string $freshness,
        string $upstreamState
    ): array {
        return [
            'actor_scope' => $actorScope,
            'data_completeness' => $dataCompleteness,
            'freshness' => $freshness,
            'upstream_state' => $upstreamState,
        ];
    }
}
