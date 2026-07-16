<?php
declare(strict_types=1);

namespace Tests;

use app\contract\DataSourceAdapter;
use app\service\platform\ManualImportDataSourceAdapter;
use app\service\PlatformDataSyncService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class Tc200ImportAtomicityL8Test extends TestCase
{
    private const SYSTEM_HOTEL_ID = 200;
    private const TENANT_ID = 20;
    private const AUTHORIZED_USER_ID = 2001;
    private const BATCH_SIZE = 64;
    private const FAILURE_ROW_NUMBER = 33;
    private const FRESH_DATA_DATE = '2026-07-15';
    private const STALE_DATA_DATE = '2026-06-15';
    private const FAILURE_TRIGGER = 'tc200_fail_mid_batch_insert';

    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'tc200_import_atomicity_l8_'
            . getmypid()
            . '_'
            . bin2hex(random_bytes(4))
            . '.sqlite';
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
    }

    public static function tearDownAfterClass(): void
    {
        Db::execute('DROP TRIGGER IF EXISTS ' . self::FAILURE_TRIGGER);
        Db::connect()->close();
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        if (is_file(self::$sqlitePath) && !unlink(self::$sqlitePath)) {
            throw new RuntimeException('Unable to remove TC-200 SQLite fixture.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Db::execute('DROP TRIGGER IF EXISTS ' . self::FAILURE_TRIGGER);
        foreach ([
            'online_daily_data',
            'platform_data_raw_records',
            'platform_data_sync_logs',
            'platform_data_sync_tasks',
            'platform_data_sources',
        ] as $table) {
            Db::name($table)->delete(true);
        }
    }

    protected function tearDown(): void
    {
        Db::execute('DROP TRIGGER IF EXISTS ' . self::FAILURE_TRIGGER);
        parent::tearDown();
    }

    /**
     * Access denial and upstream failure intentionally short-circuit the
     * persistence fault. Authorized successful upstream variants exercise the
     * complete importRows -> syncDataSource -> saveNormalizedRows path, fail at
     * row 33/64, and then retry the same deterministic batch.
     *
     * @param array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string} $factors
     */
    #[DataProvider('l8VariantProvider')]
    public function testTc200L8ImportAtomicityAndRecovery(string $caseId, array $factors): void
    {
        $sourceId = $this->createManualSource($caseId);
        $rows = $this->batchRows($caseId, $factors);
        $adapter = new Tc200ManualImportAdapter($factors['upstream_state']);
        $service = $this->service($adapter);
        $message = $caseId . ' factors=' . json_encode($factors, JSON_UNESCAPED_SLASHES);
        $payload = [
            'data_source_id' => $sourceId,
            'rows' => $rows,
        ];

        $this->assertFixtureRepresentsFactors($rows, $factors, $message);

        if ($factors['actor_scope'] === 'restricted') {
            try {
                $service->importRows($this->restrictedUser(), $payload);
                self::fail($message . ' restricted actor unexpectedly reached import persistence');
            } catch (RuntimeException $exception) {
                self::assertSame(403, $exception->getCode(), $message);
                self::assertSame('Forbidden.', $exception->getMessage(), $message);
            }

            self::assertSame(0, $adapter->calls, $message);
            self::assertSame(0, Db::name('platform_data_sync_tasks')->count(), $message);
            self::assertSame(0, Db::name('platform_data_raw_records')->count(), $message);
            self::assertSame(0, Db::name('online_daily_data')->count(), $message);
            self::assertSame('ready', Db::name('platform_data_sources')->where('id', $sourceId)->value('status'), $message);
            return;
        }

        if ($factors['upstream_state'] === 'failure') {
            $result = $service->importRows($this->authorizedUser(), $payload);

            self::assertSame(1, $adapter->calls, $message);
            self::assertSame('failed', $result['status'] ?? null, $message);
            self::assertSame(0, (int)($result['saved_count'] ?? -1), $message);
            self::assertSame('failed', Db::name('platform_data_sync_tasks')->where('id', (int)$result['task_id'])->value('status'), $message);
            self::assertSame(0, Db::name('platform_data_raw_records')->count(), $message);
            self::assertSame(0, Db::name('online_daily_data')->count(), $message);
            self::assertSame('failed', Db::name('platform_data_sources')->where('id', $sourceId)->value('last_sync_status'), $message);
            return;
        }

        $failureDimension = sprintf('room-type-%03d', self::FAILURE_ROW_NUMBER);
        $this->installMidBatchFailureTrigger($failureDimension);
        $failed = $service->importRows($this->authorizedUser(), $payload);

        self::assertSame('failed', $failed['status'] ?? null, $message);
        self::assertSame(0, (int)($failed['saved_count'] ?? -1), $message);
        self::assertSame(1, $adapter->calls, $message);
        self::assertSame(1, Db::name('platform_data_raw_records')->count(), $message);
        self::assertSame('failed', Db::name('platform_data_sync_tasks')->where('id', (int)$failed['task_id'])->value('status'), $message);

        $residualRows = Db::name('online_daily_data')
            ->where('sync_task_id', (int)$failed['task_id'])
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $residualTraceIds = array_values(array_map(
            static fn(array $row): string => (string)($row['source_trace_id'] ?? ''),
            $residualRows
        ));

        Db::execute('DROP TRIGGER IF EXISTS ' . self::FAILURE_TRIGGER);
        $retry = $service->importRows($this->authorizedUser(), $payload);

        self::assertSame('success', $retry['status'] ?? null, $message);
        self::assertSame(self::BATCH_SIZE, (int)($retry['normalized_count'] ?? -1), $message);
        self::assertSame(self::BATCH_SIZE, (int)($retry['saved_count'] ?? -1), $message);
        self::assertTrue(($retry['readback_verified'] ?? false) === true, $message);
        self::assertSame(self::BATCH_SIZE, (int)($retry['readback_count'] ?? -1), $message);
        self::assertSame(2, $adapter->calls, $message);

        $stored = Db::name('online_daily_data')->order('source_trace_id', 'asc')->select()->toArray();
        $storedTraceIds = array_values(array_map(
            static fn(array $row): string => (string)($row['source_trace_id'] ?? ''),
            $stored
        ));
        self::assertCount(self::BATCH_SIZE, $stored, $message);
        self::assertSame(
            [1],
            array_values(array_unique(array_map(static fn(array $row): int => (int)$row['readback_verified'], $stored))),
            $message
        );
        self::assertNotContains('', array_map(
            static fn(array $row): string => trim((string)$row['readback_verified_at']),
            $stored
        ), $message);
        self::assertCount(self::BATCH_SIZE, array_unique($storedTraceIds), $message . ' retry created duplicate identities');
        self::assertSame(
            [self::TENANT_ID],
            array_values(array_unique(array_map(static fn(array $row): int => (int)$row['tenant_id'], $stored))),
            $message
        );
        self::assertSame(
            [self::SYSTEM_HOTEL_ID],
            array_values(array_unique(array_map(static fn(array $row): int => (int)$row['system_hotel_id'], $stored))),
            $message
        );
        self::assertSame(
            ['failed', 'success'],
            array_values(Db::name('platform_data_sync_tasks')->order('id', 'asc')->column('status')),
            $message
        );
        self::assertSame(2, Db::name('platform_data_raw_records')->count(), $message);
        self::assertSame(2, Db::name('platform_data_sync_logs')->count(), $message);

        // Deliberately last: the retry assertions above remain observable even
        // while the current non-transactional implementation leaves rows from
        // the failed task. A correct implementation makes this list empty.
        self::assertSame([], $residualTraceIds, $message . ' failed batch left normalized rows behind');
    }

    /**
     * @return array<string, array{0:string,1:array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string}}>
     */
    public static function l8VariantProvider(): array
    {
        return [
            'DX-1593 authorized complete fresh success' => ['DX-1593', self::factors('authorized', 'complete', 'fresh', 'success')],
            'DX-1594 authorized complete stale failure' => ['DX-1594', self::factors('authorized', 'complete', 'stale', 'failure')],
            'DX-1595 authorized missing fresh failure' => ['DX-1595', self::factors('authorized', 'missing_required', 'fresh', 'failure')],
            'DX-1596 authorized missing stale success' => ['DX-1596', self::factors('authorized', 'missing_required', 'stale', 'success')],
            'DX-1597 restricted complete fresh failure' => ['DX-1597', self::factors('restricted', 'complete', 'fresh', 'failure')],
            'DX-1598 restricted complete stale success' => ['DX-1598', self::factors('restricted', 'complete', 'stale', 'success')],
            'DX-1599 restricted missing fresh success' => ['DX-1599', self::factors('restricted', 'missing_required', 'fresh', 'success')],
            'DX-1600 restricted missing stale failure' => ['DX-1600', self::factors('restricted', 'missing_required', 'stale', 'failure')],
        ];
    }

    private static function createSchema(): void
    {
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)');
        Db::name('hotels')->insert([
            'id' => self::SYSTEM_HOTEL_ID,
            'tenant_id' => self::TENANT_ID,
        ]);
        Db::execute('CREATE TABLE platform_data_sources (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, system_hotel_id INTEGER, user_id INTEGER, name VARCHAR(120) NOT NULL, platform VARCHAR(50) NOT NULL, data_type VARCHAR(50) NOT NULL, ingestion_method VARCHAR(30) NOT NULL, status VARCHAR(30) NOT NULL, enabled INTEGER NOT NULL, config_json TEXT, secret_json TEXT, last_sync_time DATETIME, last_sync_status VARCHAR(30), last_error TEXT, created_by INTEGER, updated_by INTEGER, create_time DATETIME, update_time DATETIME)');
        Db::execute('CREATE TABLE platform_data_sync_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, data_source_id INTEGER, system_hotel_id INTEGER, platform VARCHAR(50) NOT NULL, data_type VARCHAR(50) NOT NULL, ingestion_method VARCHAR(30) NOT NULL, trigger_type VARCHAR(30) NOT NULL, status VARCHAR(30) NOT NULL, attempt_count INTEGER NOT NULL, max_attempts INTEGER NOT NULL, started_at DATETIME, finished_at DATETIME, next_retry_at DATETIME, requested_by INTEGER, message TEXT, stats_json TEXT, create_time DATETIME, update_time DATETIME)');
        Db::execute('CREATE TABLE platform_data_sync_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, sync_task_id INTEGER, data_source_id INTEGER, system_hotel_id INTEGER, level VARCHAR(20), event VARCHAR(80), message TEXT, context_json TEXT, create_time DATETIME)');
        Db::execute('CREATE TABLE platform_data_raw_records (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, data_source_id INTEGER, sync_task_id INTEGER, system_hotel_id INTEGER, platform VARCHAR(50), data_type VARCHAR(50), ingestion_method VARCHAR(30), payload_hash VARCHAR(64), raw_payload TEXT, http_status INTEGER, received_at DATETIME, create_time DATETIME)');
        Db::execute('CREATE TABLE online_daily_data (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id VARCHAR(50), hotel_name VARCHAR(100), system_hotel_id INTEGER, data_date DATE NOT NULL, amount DECIMAL(12,2), quantity INTEGER, book_order_num INTEGER, comment_score DECIMAL(3,1), qunar_comment_score DECIMAL(3,1), data_value DECIMAL(12,2), source VARCHAR(50), dimension VARCHAR(100), data_type VARCHAR(50), platform VARCHAR(50), compare_type VARCHAR(50), list_exposure INTEGER, detail_exposure INTEGER, flow_rate DECIMAL(12,4), order_filling_num INTEGER, order_submit_num INTEGER, validation_status VARCHAR(60), validation_flags TEXT, readback_verified INTEGER NOT NULL DEFAULT 0, readback_verified_at DATETIME, data_source_id INTEGER, sync_task_id INTEGER, ingestion_method VARCHAR(30), source_trace_id VARCHAR(100), data_period VARCHAR(30), snapshot_time DATETIME, snapshot_bucket VARCHAR(20), is_final INTEGER, raw_data TEXT, create_time DATETIME, update_time DATETIME)');
    }

    private function createManualSource(string $caseId): int
    {
        return (int)Db::name('platform_data_sources')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'system_hotel_id' => self::SYSTEM_HOTEL_ID,
            'user_id' => self::AUTHORIZED_USER_ID,
            'name' => 'TC-200 isolated manual import ' . $caseId,
            'platform' => 'custom',
            'data_type' => 'business',
            'ingestion_method' => 'manual',
            'status' => 'ready',
            'enabled' => 1,
            'config_json' => '{}',
            'secret_json' => '{}',
            'created_by' => self::AUTHORIZED_USER_ID,
            'updated_by' => self::AUTHORIZED_USER_ID,
            'create_time' => '2026-07-15 10:00:00',
            'update_time' => '2026-07-15 10:00:00',
        ]);
    }

    /**
     * @param array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string} $factors
     * @return array<int, array<string, mixed>>
     */
    private function batchRows(string $caseId, array $factors): array
    {
        $rows = [];
        $dataDate = $factors['freshness'] === 'fresh' ? self::FRESH_DATA_DATE : self::STALE_DATA_DATE;
        for ($rowNumber = 1; $rowNumber <= self::BATCH_SIZE; $rowNumber++) {
            $row = [
                'hotel_id' => 'TC200-HOTEL-200',
                'hotel_name' => 'TC-200 Isolated Hotel',
                'data_date' => $dataDate,
                'amount' => 1000 + $rowNumber,
                'quantity' => 10 + $rowNumber,
                'book_order_num' => 5 + $rowNumber,
                'dimension' => sprintf('room-type-%03d', $rowNumber),
                'source_trace_id' => $this->traceId($caseId, $rowNumber),
            ];
            if ($factors['data_completeness'] === 'missing_required' && $rowNumber === 5) {
                unset($row['amount']);
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string} $factors
     */
    private function assertFixtureRepresentsFactors(array $rows, array $factors, string $message): void
    {
        self::assertCount(self::BATCH_SIZE, $rows, $message);
        self::assertSame(
            $factors['freshness'] === 'fresh' ? self::FRESH_DATA_DATE : self::STALE_DATA_DATE,
            $rows[0]['data_date'] ?? null,
            $message
        );
        if ($factors['data_completeness'] === 'complete') {
            self::assertArrayHasKey('amount', $rows[4], $message);
        } else {
            self::assertArrayNotHasKey('amount', $rows[4], $message);
        }
    }

    private function installMidBatchFailureTrigger(string $dimension): void
    {
        $quotedDimension = str_replace("'", "''", $dimension);
        Db::execute(
            'CREATE TRIGGER ' . self::FAILURE_TRIGGER
            . ' BEFORE INSERT ON online_daily_data'
            . " WHEN NEW.dimension = '" . $quotedDimension . "'"
            . " BEGIN SELECT RAISE(ABORT, 'tc200_forced_mid_batch_failure'); END"
        );
    }

    private function traceId(string $caseId, int $rowNumber): string
    {
        return strtolower($caseId) . '-row-' . sprintf('%03d', $rowNumber);
    }

    private function service(Tc200ManualImportAdapter $adapter): PlatformDataSyncService
    {
        $service = new PlatformDataSyncService([$adapter]);
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

    private function authorizedUser(): object
    {
        return new class {
            public int $id = 2001;

            public function isSuperAdmin(): bool
            {
                return false;
            }

            public function hasHotelPermission(int $hotelId, string $permission): bool
            {
                return $hotelId === 200 && $permission === 'can_fetch_online_data';
            }

            public function getPermittedHotelIds(): array
            {
                return [200];
            }
        };
    }

    private function restrictedUser(): object
    {
        return new class {
            public int $id = 2002;

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
     * @return array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string}
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

final class Tc200ManualImportAdapter implements DataSourceAdapter
{
    public int $calls = 0;
    public array $seenOptions = [];

    public function __construct(private readonly string $upstreamState)
    {
    }

    public function supports(array $source): bool
    {
        return ($source['ingestion_method'] ?? '') === 'manual';
    }

    public function fetch(array $source, array $options = []): array
    {
        $this->calls++;
        $this->seenOptions = $options;
        if ($this->upstreamState === 'failure') {
            return [
                'status' => 'failed',
                'message' => 'tc200_fixture_upstream_failed',
                'payload' => is_array($options['payload'] ?? null) ? $options['payload'] : [],
                'http_status' => 503,
            ];
        }

        return (new ManualImportDataSourceAdapter())->fetch($source, $options);
    }
}
