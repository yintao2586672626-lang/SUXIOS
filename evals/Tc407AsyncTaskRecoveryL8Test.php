<?php
declare(strict_types=1);

namespace Tests;

use app\contract\DataSourceAdapter;
use app\service\PlatformDataSyncService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

/** Recovery regression eval included in the SecurityRecoveryEvals PHPUnit suite. */
final class Tc407AsyncTaskRecoveryL8Test extends TestCase
{
    private const SYSTEM_HOTEL_ID = 407;
    private const TENANT_ID = 40;
    private const AUTHORIZED_USER_ID = 40701;
    private const RESTRICTED_USER_ID = 40702;
    private const ATOMICITY_TRIGGER = 'tc407_require_predecessor_terminal';

    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'tc407_async_task_recovery_l8_'
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
        Db::execute('DROP TRIGGER IF EXISTS ' . self::ATOMICITY_TRIGGER);
        Db::connect()->close();
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        if (is_file(self::$sqlitePath) && !unlink(self::$sqlitePath)) {
            throw new RuntimeException('Unable to remove TC-407 SQLite fixture.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Db::execute('DROP TRIGGER IF EXISTS ' . self::ATOMICITY_TRIGGER);
        foreach ([
            'online_daily_data',
            'platform_data_raw_records',
            'platform_data_sync_logs',
            'platform_data_sync_tasks',
            'platform_data_sources',
            'hotels',
        ] as $table) {
            Db::name($table)->delete(true);
        }
        Db::name('hotels')->insert([
            'id' => self::SYSTEM_HOTEL_ID,
            'tenant_id' => self::TENANT_ID,
        ]);
    }

    protected function tearDown(): void
    {
        Db::execute('DROP TRIGGER IF EXISTS ' . self::ATOMICITY_TRIGGER);
        parent::tearDown();
    }

    /**
     * The predecessor-terminal trigger proves ordering, not just the final
     * shape: a retry insert is rejected unless the old active row was made
     * terminal first. The fake adapter and SQLite database are local only.
     *
     * @param array{actor_scope:string,recovery_context:string,heartbeat:string,upstream_state:string} $factors
     */
    #[DataProvider('l8VariantProvider')]
    public function testTc407RecoversOrBlocksInterruptedSyncTasks(string $caseId, array $factors): void
    {
        $sourceId = $this->createManualSource($caseId);
        $predecessorId = $this->seedActivePredecessor($sourceId, $factors);
        $adapter = new Tc407RecoveryDataSourceAdapter($factors['upstream_state'], $caseId);
        $service = $this->service($adapter);
        $message = $caseId . ' factors=' . json_encode($factors, JSON_UNESCAPED_SLASHES);

        $this->assertFixtureRepresentsFactors($predecessorId, $adapter, $factors, $message);
        $this->installPredecessorTerminalTrigger();
        $before = $this->databaseSnapshot();

        if ($factors['actor_scope'] === 'restricted') {
            $this->assertRestrictedActorMakesNoWrites(
                $service,
                $adapter,
                $sourceId,
                $before,
                $message
            );
            return;
        }

        $visibleBefore = $service->listSyncTasks($this->authorizedUser(), ['data_source_id' => $sourceId]);
        self::assertCount(1, $visibleBefore, $message);
        self::assertSame(
            $factors['heartbeat'] === 'stale' ? 'stale_running' : 'running',
            $visibleBefore[0]['effective_status'] ?? null,
            $message
        );

        if ($factors['heartbeat'] === 'fresh') {
            $this->assertFreshTaskBlocksDuplicate(
                $service,
                $adapter,
                $sourceId,
                $predecessorId,
                $factors,
                $message
            );
            return;
        }

        $this->assertStaleTaskIsRecoveredBeforeRetry(
            $service,
            $adapter,
            $sourceId,
            $predecessorId,
            $factors,
            $message
        );
    }

    public function testLateWorkerCannotReviveRecoveredPredecessorOrOverwriteSource(): void
    {
        $sourceId = $this->createManualSource('DX-3257 late worker fencing');
        $factors = self::factors('authorized', 'complete', 'stale', 'failure');
        $predecessorId = $this->seedActivePredecessor($sourceId, $factors);
        $service = $this->service(new Tc407RecoveryDataSourceAdapter('failure', 'DX-3257'));

        $retryResult = $service->syncDataSource($this->authorizedUser(), $sourceId, ['trigger_type' => 'manual_retry']);
        self::assertSame('failed', $retryResult['status'] ?? null);

        $taskBefore = Db::name('platform_data_sync_tasks')->where('id', $predecessorId)->find();
        $sourceBefore = Db::name('platform_data_sources')->where('id', $sourceId)->find();
        $logsBefore = (int)Db::name('platform_data_sync_logs')->count();
        self::assertSame('failed', $taskBefore['status'] ?? null);
        self::assertSame('failed', $sourceBefore['last_sync_status'] ?? null);

        $finishTask = new \ReflectionMethod($service, 'finishTask');
        $finishTask->setAccessible(true);
        $lateResult = $finishTask->invoke(
            $service,
            $predecessorId,
            $sourceBefore,
            'success',
            'late worker success must be fenced',
            1,
            1,
            [],
            [],
            microtime(true)
        );

        self::assertSame('failed', $lateResult['status'] ?? null);
        self::assertSame($taskBefore, Db::name('platform_data_sync_tasks')->where('id', $predecessorId)->find());
        self::assertSame($sourceBefore, Db::name('platform_data_sources')->where('id', $sourceId)->find());
        self::assertSame($logsBefore, (int)Db::name('platform_data_sync_logs')->count());
    }

    /**
     * @return array<string, array{0:string,1:array{actor_scope:string,recovery_context:string,heartbeat:string,upstream_state:string}}>
     */
    public static function l8VariantProvider(): array
    {
        return [
            'DX-3249 authorized complete fresh success' => ['DX-3249', self::factors('authorized', 'complete', 'fresh', 'success')],
            'DX-3250 authorized complete stale failure' => ['DX-3250', self::factors('authorized', 'complete', 'stale', 'failure')],
            'DX-3251 authorized missing fresh failure' => ['DX-3251', self::factors('authorized', 'missing_required', 'fresh', 'failure')],
            'DX-3252 authorized missing stale success' => ['DX-3252', self::factors('authorized', 'missing_required', 'stale', 'success')],
            'DX-3253 restricted complete fresh failure' => ['DX-3253', self::factors('restricted', 'complete', 'fresh', 'failure')],
            'DX-3254 restricted complete stale success' => ['DX-3254', self::factors('restricted', 'complete', 'stale', 'success')],
            'DX-3255 restricted missing fresh success' => ['DX-3255', self::factors('restricted', 'missing_required', 'fresh', 'success')],
            'DX-3256 restricted missing stale failure' => ['DX-3256', self::factors('restricted', 'missing_required', 'stale', 'failure')],
        ];
    }

    private static function createSchema(): void
    {
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)');
        Db::execute('CREATE TABLE platform_data_sources (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, system_hotel_id INTEGER, user_id INTEGER, name VARCHAR(120) NOT NULL, platform VARCHAR(50) NOT NULL, data_type VARCHAR(50) NOT NULL, ingestion_method VARCHAR(30) NOT NULL, status VARCHAR(30) NOT NULL, enabled INTEGER NOT NULL, config_json TEXT, secret_json TEXT, last_sync_time DATETIME, last_sync_status VARCHAR(30), last_error TEXT, created_by INTEGER, updated_by INTEGER, create_time DATETIME, update_time DATETIME)');
        Db::execute('CREATE TABLE platform_data_sync_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, data_source_id INTEGER, system_hotel_id INTEGER, platform VARCHAR(50) NOT NULL, data_type VARCHAR(50) NOT NULL, ingestion_method VARCHAR(30) NOT NULL, trigger_type VARCHAR(30) NOT NULL, status VARCHAR(30) NOT NULL, attempt_count INTEGER NOT NULL, max_attempts INTEGER NOT NULL, started_at DATETIME, finished_at DATETIME, next_retry_at DATETIME, requested_by INTEGER, message TEXT, stats_json TEXT, create_time DATETIME, update_time DATETIME)');
        Db::execute('CREATE TABLE platform_data_sync_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, sync_task_id INTEGER, data_source_id INTEGER, system_hotel_id INTEGER, level VARCHAR(20), event VARCHAR(80), message TEXT, context_json TEXT, create_time DATETIME)');
        Db::execute('CREATE TABLE platform_data_raw_records (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, data_source_id INTEGER, sync_task_id INTEGER, system_hotel_id INTEGER, platform VARCHAR(50), data_type VARCHAR(50), ingestion_method VARCHAR(30), payload_hash VARCHAR(64), raw_payload TEXT, http_status INTEGER, received_at DATETIME, create_time DATETIME)');
        Db::execute('CREATE TABLE online_daily_data (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id VARCHAR(50), hotel_name VARCHAR(100), system_hotel_id INTEGER, data_date DATE NOT NULL, amount DECIMAL(12,2), quantity INTEGER, book_order_num INTEGER, comment_score DECIMAL(3,1), qunar_comment_score DECIMAL(3,1), data_value DECIMAL(12,2), source VARCHAR(50), dimension VARCHAR(100), data_type VARCHAR(50), platform VARCHAR(50), compare_type VARCHAR(50), list_exposure INTEGER, detail_exposure INTEGER, flow_rate DECIMAL(12,4), order_filling_num INTEGER, order_submit_num INTEGER, validation_status VARCHAR(60), validation_flags TEXT, data_source_id INTEGER, sync_task_id INTEGER, ingestion_method VARCHAR(30), source_trace_id VARCHAR(100), data_period VARCHAR(30), snapshot_time DATETIME, snapshot_bucket VARCHAR(20), is_final INTEGER, readback_verified INTEGER NOT NULL DEFAULT 0, readback_verified_at DATETIME, raw_data TEXT, create_time DATETIME, update_time DATETIME)');
    }

    private function createManualSource(string $caseId): int
    {
        $now = date('Y-m-d H:i:s');
        return (int)Db::name('platform_data_sources')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'system_hotel_id' => self::SYSTEM_HOTEL_ID,
            'user_id' => self::AUTHORIZED_USER_ID,
            'name' => 'TC-407 isolated source ' . $caseId,
            'platform' => 'custom',
            'data_type' => 'business',
            'ingestion_method' => 'manual',
            'status' => 'ready',
            'enabled' => 1,
            'config_json' => '{}',
            'secret_json' => '{}',
            'created_by' => self::AUTHORIZED_USER_ID,
            'updated_by' => self::AUTHORIZED_USER_ID,
            'create_time' => $now,
            'update_time' => $now,
        ]);
    }

    /**
     * @param array{actor_scope:string,recovery_context:string,heartbeat:string,upstream_state:string} $factors
     */
    private function seedActivePredecessor(int $sourceId, array $factors): int
    {
        $ageSeconds = $factors['heartbeat'] === 'stale' ? 7200 : 120;
        $heartbeatAt = date('Y-m-d H:i:s', time() - $ageSeconds);
        $stats = [];
        if ($factors['recovery_context'] === 'complete') {
            $stats['recovery_context'] = [
                'data_source_id' => $sourceId,
                'system_hotel_id' => self::SYSTEM_HOTEL_ID,
                'checkpoint' => 'adapter_fetch_started',
                'trigger_type' => 'manual',
            ];
        }

        return (int)Db::name('platform_data_sync_tasks')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'data_source_id' => $sourceId,
            'system_hotel_id' => self::SYSTEM_HOTEL_ID,
            'platform' => 'custom',
            'data_type' => 'business',
            'ingestion_method' => 'manual',
            'trigger_type' => 'manual',
            'status' => 'running',
            'attempt_count' => 1,
            'max_attempts' => 3,
            'started_at' => $heartbeatAt,
            'finished_at' => null,
            'next_retry_at' => null,
            'requested_by' => self::AUTHORIZED_USER_ID,
            'message' => 'TC-407 predecessor is active.',
            'stats_json' => json_encode($stats, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'create_time' => $heartbeatAt,
            'update_time' => $heartbeatAt,
        ]);
    }

    private function installPredecessorTerminalTrigger(): void
    {
        Db::execute(
            'CREATE TRIGGER ' . self::ATOMICITY_TRIGGER
            . ' BEFORE INSERT ON platform_data_sync_tasks'
            . " WHEN EXISTS (SELECT 1 FROM platform_data_sync_tasks WHERE data_source_id = NEW.data_source_id AND status IN ('pending','queued','running','browser_opened','syncing','syncing_after_login'))"
            . " BEGIN SELECT RAISE(ABORT, 'tc407_predecessor_not_terminal_before_retry'); END"
        );
    }

    /**
     * @param array<string,array<int,array<string,mixed>>> $before
     */
    private function assertRestrictedActorMakesNoWrites(
        PlatformDataSyncService $service,
        Tc407RecoveryDataSourceAdapter $adapter,
        int $sourceId,
        array $before,
        string $message
    ): void {
        self::assertSame([], $service->listSyncTasks($this->restrictedUser(), ['data_source_id' => $sourceId]), $message);

        $thrown = null;
        try {
            $service->syncDataSource($this->restrictedUser(), $sourceId, ['trigger_type' => 'manual_retry']);
        } catch (\Throwable $exception) {
            $thrown = $exception;
        }

        self::assertInstanceOf(RuntimeException::class, $thrown, $message);
        self::assertSame(403, $thrown?->getCode(), $message);
        self::assertSame('Forbidden.', $thrown?->getMessage(), $message);
        self::assertSame(0, $adapter->calls, $message);
        self::assertSame($before, $this->databaseSnapshot(), $message . ' restricted actor changed persisted state');
    }

    /**
     * @param array{actor_scope:string,recovery_context:string,heartbeat:string,upstream_state:string} $factors
     */
    private function assertFreshTaskBlocksDuplicate(
        PlatformDataSyncService $service,
        Tc407RecoveryDataSourceAdapter $adapter,
        int $sourceId,
        int $predecessorId,
        array $factors,
        string $message
    ): void {
        [$result, $thrown] = $this->invokeSync($service, $sourceId);
        $violations = [];

        $blockedWith409 = $thrown instanceof RuntimeException
            && (int)$thrown->getCode() === 409
            && preg_match('/already|active|duplicate|running/i', $thrown->getMessage()) === 1;
        $resultTaskId = is_array($result)
            ? (int)($result['reused_task_id'] ?? $result['task_id'] ?? 0)
            : 0;
        $resultStatus = strtolower(trim((string)($result['status'] ?? '')));
        $explicitReuse = is_array($result)
            && $resultTaskId === $predecessorId
            && (($result['reused'] ?? false) === true
                || in_array($resultStatus, ['running', 'active', 'reused', 'already_running'], true));
        if (!$blockedWith409 && !$explicitReuse) {
            $violations['duplicate_launch_not_blocked_or_reused'] = $this->outcomeSummary($result, $thrown);
        }
        if ($adapter->calls !== 0) {
            $violations['fresh_predecessor_reached_upstream'] = $adapter->calls;
        }

        $tasks = Db::name('platform_data_sync_tasks')->where('data_source_id', $sourceId)->order('id', 'asc')->select()->toArray();
        if (count($tasks) !== 1) {
            $violations['fresh_predecessor_spawned_duplicate_task'] = array_column($tasks, 'id');
        }
        $predecessor = Db::name('platform_data_sync_tasks')->where('id', $predecessorId)->find();
        if (!is_array($predecessor) || ($predecessor['status'] ?? null) !== 'running') {
            $violations['fresh_predecessor_not_running'] = $predecessor['status'] ?? null;
        }
        if (!empty($predecessor['finished_at'] ?? null)) {
            $violations['fresh_predecessor_was_terminalized'] = $predecessor['finished_at'];
        }
        if (Db::name('platform_data_raw_records')->count() !== 0
            || Db::name('online_daily_data')->count() !== 0
            || Db::name('platform_data_sync_logs')->count() !== 0
        ) {
            $violations['fresh_duplicate_created_side_effects'] = $this->sideEffectCounts();
        }

        $visibleAfter = $service->listSyncTasks($this->authorizedUser(), ['data_source_id' => $sourceId]);
        if (count($visibleAfter) !== 1 || ($visibleAfter[0]['effective_status'] ?? null) !== 'running') {
            $violations['fresh_task_visibility_changed'] = $visibleAfter;
        }
        if ($factors['recovery_context'] === 'missing_required'
            && !$this->missingContextWasSurfaced($result, $thrown, $visibleAfter)
        ) {
            $violations['missing_recovery_context_not_surfaced'] = $this->outcomeSummary($result, $thrown);
        }

        self::assertSame(
            [],
            $violations,
            $message . ' recovery violations=' . json_encode($violations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * @param array{actor_scope:string,recovery_context:string,heartbeat:string,upstream_state:string} $factors
     */
    private function assertStaleTaskIsRecoveredBeforeRetry(
        PlatformDataSyncService $service,
        Tc407RecoveryDataSourceAdapter $adapter,
        int $sourceId,
        int $predecessorId,
        array $factors,
        string $message
    ): void {
        [$result, $thrown] = $this->invokeSync($service, $sourceId);
        $violations = [];
        if ($thrown !== null) {
            $violations['stale_retry_threw'] = $this->throwableSummary($thrown);
        }

        $tasks = Db::name('platform_data_sync_tasks')->where('data_source_id', $sourceId)->order('id', 'asc')->select()->toArray();
        if (count($tasks) !== 2) {
            $violations['retry_task_count'] = count($tasks);
        }
        $predecessor = Db::name('platform_data_sync_tasks')->where('id', $predecessorId)->find();
        $newTasks = array_values(array_filter(
            $tasks,
            static fn(array $task): bool => (int)($task['id'] ?? 0) !== $predecessorId
        ));
        $retry = count($newTasks) === 1 ? $newTasks[0] : null;

        $predecessorStatus = strtolower(trim((string)($predecessor['status'] ?? '')));
        if (!in_array($predecessorStatus, ['failed', 'interrupted'], true)) {
            $violations['stale_predecessor_not_terminal'] = $predecessorStatus;
        }
        if (empty($predecessor['finished_at'] ?? null)) {
            $violations['stale_predecessor_missing_finished_at'] = $predecessor['finished_at'] ?? null;
        }
        $predecessorReason = (string)($predecessor['message'] ?? '') . ' ' . (string)($predecessor['stats_json'] ?? '');
        if (preg_match('/stale|interrupt|recover|restart|supersed|abandon/i', $predecessorReason) !== 1) {
            $violations['stale_predecessor_missing_reason'] = trim($predecessorReason);
        }

        if (!is_array($retry)) {
            $violations['retry_task_missing'] = array_column($tasks, 'id');
        } else {
            $retryStatus = strtolower(trim((string)($retry['status'] ?? '')));
            $expectedRetryStatus = $factors['upstream_state'] === 'failure' ? 'failed' : 'success';
            if ($retryStatus !== $expectedRetryStatus) {
                $violations['retry_status_mismatch'] = $retryStatus;
            }
            if (empty($retry['finished_at'] ?? null)) {
                $violations['retry_missing_finished_at'] = $retry['finished_at'] ?? null;
            }
            if ((int)($retry['attempt_count'] ?? 0) < 2) {
                $violations['retry_attempt_not_incremented'] = $retry['attempt_count'] ?? null;
            }

            $predecessorStats = $this->decodeJsonObject($predecessor['stats_json'] ?? null);
            $retryStats = $this->decodeJsonObject($retry['stats_json'] ?? null);
            $linked = $this->containsTaskReference(is_array($result) ? $result : [], $predecessorId)
                || $this->containsTaskReference($retryStats, $predecessorId)
                || $this->containsTaskReference($predecessorStats, (int)$retry['id']);
            if (!$linked) {
                $violations['retry_missing_predecessor_linkage'] = [
                    'predecessor_id' => $predecessorId,
                    'retry_id' => (int)$retry['id'],
                ];
            }
        }

        if ($adapter->calls !== 1) {
            $violations['retry_upstream_call_count'] = $adapter->calls;
        }
        $expectedSideEffects = $factors['upstream_state'] === 'success'
            ? ['raw' => 1, 'daily' => 1]
            : ['raw' => 0, 'daily' => 0];
        $actualSideEffects = $this->sideEffectCounts();
        if ($actualSideEffects['raw'] !== $expectedSideEffects['raw']
            || $actualSideEffects['daily'] !== $expectedSideEffects['daily']
        ) {
            $violations['retry_side_effect_counts'] = $actualSideEffects;
        }
        if ($factors['upstream_state'] === 'failure' && is_array($retry) && empty($retry['next_retry_at'] ?? null)) {
            $violations['failed_retry_missing_next_retry_at'] = $retry['next_retry_at'] ?? null;
        }

        $visibleAfter = $service->listSyncTasks($this->authorizedUser(), ['data_source_id' => $sourceId]);
        $activeStatuses = array_values(array_filter(array_map(
            static fn(array $task): string => (string)($task['effective_status'] ?? ''),
            $visibleAfter
        ), static fn(string $status): bool => in_array($status, ['pending', 'queued', 'running', 'browser_opened', 'syncing', 'syncing_after_login', 'stale_running'], true)));
        if ($activeStatuses !== []) {
            $violations['active_task_remains_after_retry'] = $activeStatuses;
        }
        if ($factors['recovery_context'] === 'missing_required'
            && !$this->missingContextWasSurfaced($result, $thrown, [$predecessor, $retry, $visibleAfter])
        ) {
            $violations['missing_recovery_context_not_surfaced'] = $this->outcomeSummary($result, $thrown);
        }

        self::assertSame(
            [],
            $violations,
            $message . ' recovery violations=' . json_encode($violations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /** @return array{0:?array,1:?\Throwable} */
    private function invokeSync(PlatformDataSyncService $service, int $sourceId): array
    {
        try {
            return [
                $service->syncDataSource($this->authorizedUser(), $sourceId, ['trigger_type' => 'manual_retry']),
                null,
            ];
        } catch (\Throwable $exception) {
            return [null, $exception];
        }
    }

    /**
     * @param array{actor_scope:string,recovery_context:string,heartbeat:string,upstream_state:string} $factors
     */
    private function assertFixtureRepresentsFactors(
        int $predecessorId,
        Tc407RecoveryDataSourceAdapter $adapter,
        array $factors,
        string $message
    ): void {
        self::assertSame($factors['upstream_state'], $adapter->configuredResponseStatus(), $message);
        $predecessor = Db::name('platform_data_sync_tasks')->where('id', $predecessorId)->find();
        self::assertIsArray($predecessor, $message);
        self::assertSame(
            $factors['heartbeat'] === 'stale' ? 'stale_running' : 'running',
            PlatformDataSyncService::effectiveSyncTaskStatus($predecessor),
            $message
        );
        $stats = $this->decodeJsonObject($predecessor['stats_json'] ?? null);
        if ($factors['recovery_context'] === 'complete') {
            self::assertSame('adapter_fetch_started', $stats['recovery_context']['checkpoint'] ?? null, $message);
            self::assertSame(self::SYSTEM_HOTEL_ID, $stats['recovery_context']['system_hotel_id'] ?? null, $message);
        } else {
            self::assertArrayNotHasKey('recovery_context', $stats, $message);
        }
    }

    private function service(Tc407RecoveryDataSourceAdapter $adapter): PlatformDataSyncService
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
                'order_filling_num', 'order_submit_num', 'validation_status', 'validation_flags', 'data_source_id',
                'sync_task_id', 'ingestion_method', 'source_trace_id', 'data_period', 'snapshot_time',
                'snapshot_bucket', 'is_final', 'readback_verified', 'readback_verified_at', 'raw_data', 'create_time',
                'update_time',
            ], true),
        ]);
        return $service;
    }

    private function authorizedUser(): object
    {
        return new class {
            public int $id = 40701;
            public int $tenant_id = 40;

            public function isSuperAdmin(): bool
            {
                return false;
            }

            public function hasHotelPermission(int $hotelId, string $permission): bool
            {
                return $hotelId === 407 && $permission === 'can_fetch_online_data';
            }

            public function getPermittedHotelIds(): array
            {
                return [407];
            }
        };
    }

    private function restrictedUser(): object
    {
        return new class {
            public int $id = 40702;
            public int $tenant_id = 40;

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

    /** @return array<string,array<int,array<string,mixed>>> */
    private function databaseSnapshot(): array
    {
        $snapshot = [];
        foreach ([
            'platform_data_sources',
            'platform_data_sync_tasks',
            'platform_data_sync_logs',
            'platform_data_raw_records',
            'online_daily_data',
        ] as $table) {
            $snapshot[$table] = Db::name($table)->order('id', 'asc')->select()->toArray();
        }
        return $snapshot;
    }

    /** @return array{raw:int,daily:int,logs:int} */
    private function sideEffectCounts(): array
    {
        return [
            'raw' => (int)Db::name('platform_data_raw_records')->count(),
            'daily' => (int)Db::name('online_daily_data')->count(),
            'logs' => (int)Db::name('platform_data_sync_logs')->count(),
        ];
    }

    /** @return array<string,mixed> */
    private function decodeJsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $value */
    private function containsTaskReference(array $value, int $taskId): bool
    {
        $linkKeys = [
            'previous_task_id',
            'predecessor_task_id',
            'retry_of_task_id',
            'recovery_from_task_id',
            'retry_task_id',
            'replacement_task_id',
            'restarted_as_task_id',
        ];
        foreach ($value as $key => $item) {
            if (in_array(strtolower((string)$key), $linkKeys, true) && (int)$item === $taskId) {
                return true;
            }
            if (is_array($item) && $this->containsTaskReference($item, $taskId)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<int,mixed> $surface */
    private function missingContextWasSurfaced(?array $result, ?\Throwable $thrown, array $surface): bool
    {
        $evidence = json_encode([
            'result' => $result,
            'exception' => $this->throwableSummary($thrown),
            'surface' => $surface,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($evidence)
            && preg_match('/(missing.{0,100}(recovery[_ -]?context|checkpoint)|(recovery[_ -]?context|checkpoint).{0,100}missing)/i', $evidence) === 1;
    }

    /** @return array<string,mixed> */
    private function outcomeSummary(?array $result, ?\Throwable $thrown): array
    {
        return [
            'result' => $result,
            'exception' => $this->throwableSummary($thrown),
        ];
    }

    /** @return array{class:string,code:int|string,message:string}|null */
    private function throwableSummary(?\Throwable $thrown): ?array
    {
        if ($thrown === null) {
            return null;
        }
        return [
            'class' => $thrown::class,
            'code' => $thrown->getCode(),
            'message' => $thrown->getMessage(),
        ];
    }

    /**
     * @return array{actor_scope:string,recovery_context:string,heartbeat:string,upstream_state:string}
     */
    private static function factors(
        string $actorScope,
        string $recoveryContext,
        string $heartbeat,
        string $upstreamState
    ): array {
        return [
            'actor_scope' => $actorScope,
            'recovery_context' => $recoveryContext,
            'heartbeat' => $heartbeat,
            'upstream_state' => $upstreamState,
        ];
    }
}

final class Tc407RecoveryDataSourceAdapter implements DataSourceAdapter
{
    public int $calls = 0;

    public function __construct(
        private readonly string $upstreamState,
        private readonly string $caseId
    ) {
    }

    public function configuredResponseStatus(): string
    {
        return $this->upstreamState;
    }

    public function supports(array $source): bool
    {
        return ($source['platform'] ?? '') === 'custom'
            && ($source['ingestion_method'] ?? '') === 'manual';
    }

    public function fetch(array $source, array $options = []): array
    {
        $this->calls++;
        if ($this->upstreamState === 'failure') {
            return [
                'status' => 'failed',
                'message' => 'tc407 synthetic upstream timeout',
                'payload' => [],
                'http_status' => 504,
            ];
        }

        return [
            'status' => 'success',
            'message' => 'tc407 synthetic upstream success',
            'http_status' => 200,
            'payload' => [
                'rows' => [[
                    'hotel_id' => 'TC407-HOTEL',
                    'hotel_name' => 'TC-407 Isolated Hotel',
                    'system_hotel_id' => 407,
                    'data_date' => date('Y-m-d'),
                    'amount' => 4070.00,
                    'quantity' => 40,
                    'book_order_num' => 7,
                    'dimension' => 'tc407-async-recovery',
                    'source_trace_id' => strtolower($this->caseId) . '-retry',
                ]],
            ],
        ];
    }
}
