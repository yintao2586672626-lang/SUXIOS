<?php
declare(strict_types=1);

namespace Tests;

use app\contract\DataSourceAdapter;
use app\service\platform\ManualImportDataSourceAdapter;
use app\service\PlatformDataSyncService;
use app\service\PlatformNormalizedRowPersistenceService;
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
    private const PROJECTION_FAILURE_TRIGGER = 'tc200_fail_ctrip_projection';

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
        Db::execute('DROP TRIGGER IF EXISTS ' . self::PROJECTION_FAILURE_TRIGGER);
        foreach ([
            'ota_ctrip_metric_facts',
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
        Db::execute('DROP TRIGGER IF EXISTS ' . self::PROJECTION_FAILURE_TRIGGER);
        parent::tearDown();
    }

    public function testNormalizedPersistenceDirectSaveIsIdempotentAndKeepsOrderEventsSeparate(): void
    {
        $service = new PlatformNormalizedRowPersistenceService();
        $columns = $this->normalizedPersistenceColumns();
        $summary = [
            'tenant_id' => self::TENANT_ID,
            'system_hotel_id' => self::SYSTEM_HOTEL_ID,
            'data_source_id' => 701,
            'sync_task_id' => 700,
            'ingestion_method' => 'browser_profile',
            'source' => 'custom',
            'platform' => 'custom',
            'hotel_id' => 'TC200-HOTEL-200',
            'hotel_name' => 'TC-200 Isolated Hotel',
            'data_type' => 'traffic',
            'data_date' => self::FRESH_DATA_DATE,
            'data_period' => 'historical_daily',
            'snapshot_bucket' => '',
            'dimension' => 'summary',
            'compare_type' => 'self',
            'list_exposure' => 10,
            'source_trace_id' => 'summary-attempt-1',
            'raw_data' => '{}',
        ];

        $first = $service->save([$summary], $columns);
        self::assertSame(1, $first['inserted_count']);
        self::assertSame(0, $first['updated_count']);
        self::assertTrue($first['readback_verified']);

        $summary['list_exposure'] = 20;
        $summary['source_trace_id'] = 'summary-attempt-2';
        $retry = $service->save([$summary], $columns);
        self::assertSame(0, $retry['inserted_count']);
        self::assertSame(1, $retry['updated_count']);
        self::assertTrue($retry['readback_verified']);
        self::assertSame(1, Db::name('online_daily_data')->where('data_type', 'traffic')->count());
        self::assertSame(
            20,
            (int)Db::name('online_daily_data')->where('data_type', 'traffic')->value('list_exposure')
        );

        $nextTask = array_replace($summary, [
            'sync_task_id' => 701,
            'list_exposure' => 30,
            'source_trace_id' => 'summary-attempt-3',
        ]);
        $nextTaskReceipt = $service->save([$nextTask], $columns);
        self::assertSame(1, $nextTaskReceipt['inserted_count']);
        self::assertSame(0, $nextTaskReceipt['updated_count']);
        self::assertTrue($nextTaskReceipt['readback_verified']);
        $storedTraffic = Db::name('online_daily_data')
            ->where('data_type', 'traffic')
            ->order('sync_task_id', 'asc')
            ->select()
            ->toArray();
        self::assertCount(2, $storedTraffic);
        self::assertSame([700, 701], array_map('intval', array_column($storedTraffic, 'sync_task_id')));
        self::assertSame([20, 30], array_map('intval', array_column($storedTraffic, 'list_exposure')));
        self::assertCount(2, array_unique(array_column($storedTraffic, 'persistence_identity_hash')));

        $legacyIdentity = [];
        foreach ([
            'tenant_id', 'system_hotel_id', 'data_source_id', 'source', 'platform',
            'hotel_id', 'data_type', 'data_date', 'data_period', 'snapshot_bucket',
            'dimension', 'compare_type',
        ] as $field) {
            $legacyIdentity[$field] = array_key_exists($field, $summary) && $summary[$field] !== null
                ? (string)$summary[$field]
                : '';
        }
        $legacyIdentity['identity_kind'] = 'summary';
        $legacyIdentity['event_identity_hash'] = '';
        $legacyHash = hash('sha256', json_encode(
            $legacyIdentity,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
        foreach (['manual', 'import_json', 'import_csv', 'import_excel'] as $manualMethod) {
            $manualTraffic = array_replace($summary, ['ingestion_method' => $manualMethod]);
            self::assertSame($legacyHash, $service->identityHash($manualTraffic));
            self::assertSame(
                $service->identityHash($manualTraffic),
                $service->identityHash(array_replace($manualTraffic, ['sync_task_id' => 999]))
            );
        }

        $advertising = array_replace($summary, [
            'sync_task_id' => 800,
            'data_type' => 'advertising',
            'dimension' => 'campaign-summary',
            'amount' => 10,
            'list_exposure' => null,
            'source_trace_id' => 'advertising-attempt-1',
        ]);
        self::assertSame(1, $service->save([$advertising], $columns)['inserted_count']);
        $advertisingRetry = array_replace($advertising, [
            'sync_task_id' => 801,
            'amount' => 12,
            'source_trace_id' => 'advertising-attempt-2',
        ]);
        $advertisingReceipt = $service->save([$advertisingRetry], $columns);
        self::assertSame(0, $advertisingReceipt['inserted_count']);
        self::assertSame(1, $advertisingReceipt['updated_count']);
        self::assertSame(1, Db::name('online_daily_data')->where('data_type', 'advertising')->count());
        self::assertSame(801, (int)Db::name('online_daily_data')->where('data_type', 'advertising')->value('sync_task_id'));
        self::assertSame(12.0, (float)Db::name('online_daily_data')->where('data_type', 'advertising')->value('amount'));

        $orders = [];
        foreach (['order-hash-a', 'order-hash-b'] as $index => $orderHash) {
            $orders[] = array_merge($summary, [
                'data_type' => 'order',
                'dimension' => 'booking',
                'list_exposure' => null,
                'amount' => 100 + $index,
                'source_trace_id' => 'order-attempt-' . ($index + 1),
                'raw_data' => json_encode([
                    'row' => ['order_id_hash' => $orderHash],
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
        }

        $orderReceipt = $service->save($orders, $columns);
        self::assertSame(2, $orderReceipt['inserted_count']);
        self::assertSame(0, $orderReceipt['deduplicated_count']);
        self::assertTrue($orderReceipt['readback_verified']);

        $storedOrders = Db::name('online_daily_data')
            ->where('data_type', 'order')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        self::assertCount(2, $storedOrders);
        self::assertCount(2, array_unique(array_column($storedOrders, 'persistence_identity_hash')));
        $eventRetry = array_replace($orders[0], [
            'sync_task_id' => 999,
            'source_trace_id' => 'order-attempt-retry',
        ]);
        self::assertSame($service->identityHash($orders[0]), $service->identityHash($eventRetry));
    }

    public function testNormalizedPersistenceRollsBackPrimaryRowWhenCtripProjectionFails(): void
    {
        Db::execute(
            'CREATE TRIGGER ' . self::PROJECTION_FAILURE_TRIGGER
            . ' BEFORE INSERT ON ota_ctrip_metric_facts'
            . " BEGIN SELECT RAISE(ABORT, 'tc200_forced_projection_failure'); END"
        );
        $row = [
            'tenant_id' => self::TENANT_ID,
            'system_hotel_id' => self::SYSTEM_HOTEL_ID,
            'data_source_id' => 702,
            'sync_task_id' => 703,
            'source' => 'ctrip',
            'platform' => 'ctrip',
            'hotel_id' => 'TC200-HOTEL-200',
            'hotel_name' => 'TC-200 Isolated Hotel',
            'data_type' => 'business',
            'data_date' => self::FRESH_DATA_DATE,
            'data_period' => 'historical_daily',
            'snapshot_bucket' => '',
            'dimension' => 'summary',
            'compare_type' => 'self',
            'amount' => 321.5,
            'source_trace_id' => 'projection-failure-attempt',
            'raw_data' => json_encode([
                'row' => [
                    'section' => 'business',
                    'endpoint_id' => 'test.endpoint',
                ],
                'field_facts' => [[
                    'metric_key' => 'order_amount',
                    'metric_label' => 'Order amount',
                    'data_type' => 'business',
                    'status' => 'captured',
                    'stored_value_present' => true,
                    'storage_field' => 'online_daily_data.amount',
                    'source_key' => 'orderAmount',
                    'source_path' => '$.data.orderAmount',
                ]],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];

        $projectionFailure = null;
        try {
            (new PlatformNormalizedRowPersistenceService())->save(
                [$row],
                $this->normalizedPersistenceColumns()
            );
        } catch (\Throwable $exception) {
            $projectionFailure = $exception;
        }

        self::assertNotNull($projectionFailure);
        self::assertStringContainsString(
            'tc200_forced_projection_failure',
            $projectionFailure->getMessage()
        );
        self::assertSame(0, Db::name('online_daily_data')->count());
        self::assertSame(0, Db::name('ota_ctrip_metric_facts')->count());
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

    public function testTwoSameGrainOrdersPersistSeparatelyAndRetryIsIdempotent(): void
    {
        $sourceId = $this->createManualSource('ORDER-IDEMPOTENCY', 'order');
        $adapter = new Tc200ManualImportAdapter('success');
        $service = $this->service($adapter);
        $payload = [
            'data_source_id' => $sourceId,
            'rows' => [
                [
                    'hotel_id' => 'TC200-HOTEL-200',
                    'data_date' => self::FRESH_DATA_DATE,
                    'orderId' => 'TC200-ORDER-A',
                    'amount' => 100,
                    'quantity' => 1,
                    'book_order_num' => 1,
                ],
                [
                    'hotel_id' => 'TC200-HOTEL-200',
                    'data_date' => self::FRESH_DATA_DATE,
                    'orderId' => 'TC200-ORDER-B',
                    'amount' => 200,
                    'quantity' => 2,
                    'book_order_num' => 1,
                ],
            ],
        ];
        $userCountBefore = (int)Db::name('users')->count();
        $hotelCountBefore = (int)Db::name('hotels')->count();

        $first = $service->importRows($this->authorizedUser(), $payload);
        $retry = $service->importRows($this->authorizedUser(), $payload);
        $stored = Db::name('online_daily_data')->order('amount', 'asc')->select()->toArray();

        self::assertSame('success', $first['status'] ?? null);
        self::assertSame('success', $retry['status'] ?? null);
        self::assertSame(2, (int)($first['saved_count'] ?? -1));
        self::assertSame(2, (int)($retry['saved_count'] ?? -1));
        self::assertCount(2, $stored);
        self::assertSame([100.0, 200.0], array_map(static fn(array $row): float => (float)$row['amount'], $stored));
        self::assertCount(2, array_unique(array_column($stored, 'persistence_identity_hash')));
        foreach ($stored as $row) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string)$row['persistence_identity_hash']);
            self::assertStringNotContainsString('TC200-ORDER-', (string)$row['raw_data']);
            self::assertSame('unverified', $row['validation_status'] ?? null);
            self::assertContains(
                'manual_import_provenance_unverified',
                json_decode((string)($row['validation_flags'] ?? '[]'), true)
            );
        }
        self::assertSame($userCountBefore, (int)Db::name('users')->count());
        self::assertSame($hotelCountBefore, (int)Db::name('hotels')->count());
    }

    public function testExecutableOtaSourceSelectionUsesDedicatedUnverifiedManualSource(): void
    {
        $cases = [
            [
                'platform' => 'ctrip',
                'method' => 'browser_profile',
                'platform_hotel_id' => 'CTRIP-TC200-200',
                'row_identifier_key' => 'hotel_id',
                'trace_id' => 'tc200-browser-source-manual-import',
            ],
            [
                'platform' => 'meituan',
                'method' => 'api',
                'platform_hotel_id' => 'MT-TC200-200',
                'row_identifier_key' => 'poi_id',
                'trace_id' => 'tc200-api-source-manual-import',
            ],
        ];

        foreach ($cases as $case) {
            $sourceId = $this->createExecutableOtaSource(
                $case['platform'],
                $case['method'],
                $case['platform_hotel_id']
            );
            $manualAdapter = new Tc200ManualImportAdapter('success');
            $executableAdapter = new Tc200ExecutableSourceTrapAdapter();
            $service = $this->service($manualAdapter, [$executableAdapter]);
            $sourceBefore = Db::name('platform_data_sources')->where('id', $sourceId)->find();
            $sourceCountBefore = (int)Db::name('platform_data_sources')->count();
            $payload = [
                'data_source_id' => $sourceId,
                'rows' => [[
                    'system_hotel_id' => self::SYSTEM_HOTEL_ID,
                    'platform' => $case['platform'],
                    $case['row_identifier_key'] => $case['platform_hotel_id'],
                    'data_date' => self::FRESH_DATA_DATE,
                    'amount' => 888.5,
                    'quantity' => 8,
                    'book_order_num' => 3,
                    'dimension' => $case['method'] . '-manual-import',
                    'source_trace_id' => $case['trace_id'],
                ]],
            ];

            $first = $service->importRows($this->authorizedUser(), $payload);
            $effectiveSourceId = (int)($first['effective_import_source_id'] ?? 0);

            self::assertSame('success', $first['status'] ?? null);
            self::assertSame($sourceId, (int)($first['selected_data_source_id'] ?? 0));
            self::assertNotSame($sourceId, $effectiveSourceId);
            self::assertSame($effectiveSourceId, (int)($first['data_source_id'] ?? 0));
            self::assertSame('user_provided_unverified', $first['import_provenance_status'] ?? null);
            self::assertSame(0, (int)($first['analysis_eligible_count'] ?? -1));
            self::assertTrue(($first['readback_verified'] ?? false) === true);
            self::assertSame(1, (int)($first['readback_count'] ?? 0));
            self::assertSame(1, $manualAdapter->calls);
            self::assertSame(0, $executableAdapter->calls);
            self::assertSame($sourceCountBefore + 1, (int)Db::name('platform_data_sources')->count());

            $manualSource = Db::name('platform_data_sources')->where('id', $effectiveSourceId)->find();
            self::assertSame(self::TENANT_ID, (int)($manualSource['tenant_id'] ?? 0));
            self::assertSame(self::SYSTEM_HOTEL_ID, (int)($manualSource['system_hotel_id'] ?? 0));
            self::assertSame($case['platform'], $manualSource['platform'] ?? null);
            self::assertSame('business', $manualSource['data_type'] ?? null);
            self::assertSame('manual', $manualSource['ingestion_method'] ?? null);
            self::assertSame('{}', $manualSource['secret_json'] ?? null);
            self::assertSame([
                'manual_import_contract' => 'user_provided_unverified.v1',
                'source_method' => 'manual_import',
                'platform_hotel_id' => $case['platform_hotel_id'],
            ], json_decode((string)($manualSource['config_json'] ?? ''), true));

            $sourceAfter = Db::name('platform_data_sources')->where('id', $sourceId)->find();
            self::assertSame($sourceBefore, $sourceAfter, $case['method'] . ' source state/config changed');

            $stored = Db::name('online_daily_data')
                ->where('data_source_id', $effectiveSourceId)
                ->where('source_trace_id', $case['trace_id'])
                ->find();
            self::assertIsArray($stored);
            self::assertSame('manual', $stored['ingestion_method'] ?? null);
            self::assertSame('unverified', $stored['validation_status'] ?? null);
            self::assertContains(
                'manual_import_provenance_unverified',
                json_decode((string)($stored['validation_flags'] ?? '[]'), true)
            );
            self::assertSame(1, (int)($stored['readback_verified'] ?? 0));
            $readback = $service->readImportedRows($this->authorizedUser(), $first);
            self::assertCount(1, $readback);
            self::assertSame((int)$stored['id'], $readback[0]['_persisted_row_id']);
            self::assertSame(self::SYSTEM_HOTEL_ID, $readback[0]['system_hotel_id']);
            self::assertSame($case['platform'], $readback[0]['platform']);
            self::assertTrue($readback[0]['_readback_verified']);

            $retry = $service->importRows($this->authorizedUser(), $payload);
            self::assertSame('success', $retry['status'] ?? null);
            self::assertSame($effectiveSourceId, (int)($retry['effective_import_source_id'] ?? 0));
            self::assertSame($sourceCountBefore + 1, (int)Db::name('platform_data_sources')->count());
            self::assertSame(2, $manualAdapter->calls);
            self::assertSame(0, $executableAdapter->calls);
            self::assertSame(1, (int)Db::name('online_daily_data')
                ->where('data_source_id', $effectiveSourceId)
                ->where('source_trace_id', $case['trace_id'])
                ->count());
            self::assertSame($sourceBefore, Db::name('platform_data_sources')->where('id', $sourceId)->find());
        }
    }

    #[DataProvider('manualImportConflictingScopeProvider')]
    public function testManualSourceRejectsConflictingRowScope(string $platform, string $scopeField, $scopeValue): void
    {
        $sourceId = $this->createManualSource('conflicting-row-scope');
        Db::name('platform_data_sources')->where('id', $sourceId)->update(['platform' => $platform]);
        $adapter = new Tc200ManualImportAdapter('success');
        $result = $this->service($adapter)->importRows($this->authorizedUser(), [
            'data_source_id' => $sourceId,
            'rows' => [[
                'system_hotel_id' => self::SYSTEM_HOTEL_ID,
                'platform' => $platform,
                'data_date' => self::FRESH_DATA_DATE,
                'amount' => 123,
                $scopeField => $scopeValue,
            ]],
        ]);

        $stored = Db::name('online_daily_data')->where('data_source_id', $sourceId)->find();
        $raw = is_array($stored) ? json_decode((string)($stored['raw_data'] ?? '{}'), true) : [];
        self::assertSame('failed', $result['status'] ?? null, json_encode([
            'saved_count' => $result['saved_count'] ?? null,
            'readback_verified' => $result['readback_verified'] ?? null,
            'stored_system_hotel_id' => $stored['system_hotel_id'] ?? null,
            'raw_system_hotel_id' => $raw['row']['system_hotel_id'] ?? null,
            'stored_source' => $stored['source'] ?? null,
            'stored_platform' => $stored['platform'] ?? null,
            'raw_platform' => $raw['row']['platform'] ?? null,
        ], JSON_UNESCAPED_SLASHES));
        self::assertSame($sourceId, (int)($result['selected_data_source_id'] ?? 0));
        self::assertSame($sourceId, (int)($result['effective_import_source_id'] ?? 0));
        self::assertSame(0, (int)($result['saved_count'] ?? -1));
        self::assertFalse($result['readback_verified'] ?? null);
        self::assertSame(0, (int)Db::name('online_daily_data')->count());
        self::assertSame(0, (int)Db::name('platform_data_raw_records')->count());
    }

    public static function manualImportConflictingScopeProvider(): array
    {
        $cases = [];
        foreach (['custom', 'ctrip', 'meituan'] as $platform) {
            $cases[$platform . ' other hotel'] = [$platform, 'system_hotel_id', 201];
            $cases[$platform . ' other platform'] = [$platform, 'platform', $platform === 'ctrip' ? 'meituan' : 'ctrip'];
        }
        return $cases;
    }

    public function testCustomManualImportReadsBackExactSelectedScopeWithZeroAndPartialFields(): void
    {
        $sourceId = $this->createManualSource('exact-custom-readback');
        $service = $this->service(new Tc200ManualImportAdapter('success'));
        $payload = [
            'data_source_id' => $sourceId,
            'rows' => [
                [
                    'system_hotel_id' => self::SYSTEM_HOTEL_ID,
                    'platform' => 'custom',
                    'data_date' => self::FRESH_DATA_DATE,
                    'dimension' => 'zero',
                    'amount' => 0,
                    'quantity' => 0,
                    'book_order_num' => 0,
                ],
                [
                    'system_hotel_id' => self::SYSTEM_HOTEL_ID,
                    'platform' => 'custom',
                    'data_date' => self::FRESH_DATA_DATE,
                    'dimension' => 'partial',
                    'quantity' => 3,
                ],
            ],
        ];

        $first = $service->importRows($this->authorizedUser(), $payload);
        $readback = $service->readImportedRows($this->authorizedUser(), $first);
        $stored = Db::name('online_daily_data')->order('id', 'asc')->select()->toArray();
        self::assertSame('success', $first['status'] ?? null);
        self::assertSame($sourceId, (int)$first['selected_data_source_id']);
        self::assertSame($sourceId, (int)$first['effective_import_source_id']);
        self::assertSame('user_provided_unverified', $first['import_provenance_status']);
        self::assertSame(0, $first['analysis_eligible_count']);
        self::assertTrue($first['readback_verified']);
        self::assertSame(2, $first['readback_count']);
        self::assertCount(2, $stored);
        self::assertCount(2, $readback);
        $rowIds = array_map('intval', array_column($stored, 'id'));
        self::assertSame($rowIds, array_column($readback, '_persisted_row_id'));
        foreach ($stored as $index => $row) {
            self::assertSame(self::TENANT_ID, (int)$row['tenant_id']);
            self::assertSame(self::SYSTEM_HOTEL_ID, (int)$row['system_hotel_id']);
            self::assertSame($sourceId, (int)$row['data_source_id']);
            self::assertSame((int)$first['task_id'], (int)$row['sync_task_id']);
            self::assertSame('custom', $row['source']);
            self::assertSame('custom', $row['platform']);
            self::assertSame('business', $row['data_type']);
            self::assertSame('manual', $row['ingestion_method']);
            self::assertSame('unverified', $row['validation_status']);
            self::assertSame(self::FRESH_DATA_DATE, $row['data_date']);
            self::assertSame($payload['rows'][$index] + [
                '_persisted_row_id' => (int)$row['id'],
                '_readback_verified' => true,
            ], $readback[$index]);
            $raw = json_decode((string)$row['raw_data'], true);
            self::assertArrayNotHasKey('platform_hotel_binding_status', $raw);
        }
        self::assertSame(0.0, (float)$stored[0]['amount']);
        self::assertSame(0, (int)$stored[0]['quantity']);
        self::assertSame(0, (int)$stored[0]['book_order_num']);
        self::assertNull($stored[1]['amount']);
        self::assertNull($stored[1]['book_order_num']);
        self::assertSame(3, (int)$stored[1]['quantity']);

        $retry = $service->importRows($this->authorizedUser(), $payload);
        self::assertSame('success', $retry['status']);
        self::assertSame(0, $retry['inserted_count']);
        self::assertSame(2, $retry['updated_count']);
        $retryReadback = $service->readImportedRows($this->authorizedUser(), $retry);
        self::assertSame($readback, $retryReadback);
        self::assertSame(2, (int)Db::name('online_daily_data')->count());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('manual_import_exact_readback_count_mismatch');
        $service->readImportedRows($this->authorizedUser(), $first);
    }

    #[DataProvider('changedSourceScopeProvider')]
    public function testSavingChangedSourceScopeClearsSyncConclusionAndPreservesHistoricalRows(
        string $originalPlatform,
        int $targetHotelId,
        string $targetPlatform,
        string $targetDataType
    ): void {
        $sourceId = $this->createManualSource('changed-source-scope');
        Db::name('platform_data_sources')->where('id', $sourceId)->update(['platform' => $originalPlatform]);
        if ($targetHotelId !== self::SYSTEM_HOTEL_ID) {
            Db::name('hotels')->insert(['id' => $targetHotelId, 'tenant_id' => 21]);
        }
        $actor = new class {
            public int $id = 2001;
            public function isSuperAdmin(): bool { return true; }
        };
        $service = $this->service(new Tc200ManualImportAdapter('success'));
        $row = [
            'system_hotel_id' => self::SYSTEM_HOTEL_ID,
            'platform' => $originalPlatform,
            'data_date' => self::FRESH_DATA_DATE,
            'amount' => 0,
            'quantity' => 1,
            'list_exposure' => 0,
        ];
        try {
            $first = $service->importRows($actor, ['data_source_id' => $sourceId, 'rows' => [$row]]);
            self::assertSame('success', $first['status']);
            $oldReadback = $service->readImportedRows($actor, $first);
            $oldRowId = $oldReadback[0]['_persisted_row_id'];
            $oldRow = Db::name('online_daily_data')->where('id', $oldRowId)->find();
            $oldTask = Db::name('platform_data_sync_tasks')->where('id', $first['task_id'])->find();
            $saved = $service->saveDataSource($actor, [
                'id' => $sourceId,
                'system_hotel_id' => $targetHotelId,
                'platform' => $targetPlatform,
                'data_type' => $targetDataType,
                'ingestion_method' => 'manual',
                'name' => 'Synthetic source in new scope',
            ]);
            $listed = $service->listDataSources($actor, [
                'system_hotel_id' => $targetHotelId,
                'platform' => $targetPlatform,
                'data_type' => $targetDataType,
            ]);
            self::assertCount(1, $listed);
            $storedSource = Db::name('platform_data_sources')->withoutField('secret_json')->where('id', $sourceId)->find();
            foreach ([$saved, $listed[0], $storedSource] as $source) {
                self::assertSame($sourceId, (int)$source['id']);
                self::assertSame($targetHotelId, (int)$source['system_hotel_id']);
                self::assertSame($targetHotelId === self::SYSTEM_HOTEL_ID ? self::TENANT_ID : 21, (int)$source['tenant_id']);
                self::assertSame($targetPlatform, $source['platform']);
                self::assertSame($targetDataType, $source['data_type']);
                self::assertSame('ready', $source['status']);
                self::assertNull($source['last_sync_time']);
                self::assertNull($source['last_sync_status']);
                self::assertSame('', (string)$source['last_error']);
            }
            self::assertSame(0, (int)Db::name('online_daily_data')
                ->where('data_source_id', $sourceId)->where('system_hotel_id', $targetHotelId)
                ->where('source', $targetPlatform)->where('data_type', $targetDataType)->count());
            self::assertSame($oldRow, Db::name('online_daily_data')->where('id', $oldRowId)->find());
            self::assertSame($oldTask, Db::name('platform_data_sync_tasks')->where('id', $first['task_id'])->find());

            $row['system_hotel_id'] = $targetHotelId;
            $row['platform'] = $targetPlatform;
            $second = $service->importRows($actor, ['data_source_id' => $sourceId, 'rows' => [$row]]);
            self::assertSame('success', $second['status']);
            self::assertTrue($second['readback_verified']);
            self::assertSame($sourceId, $second['selected_data_source_id']);
            self::assertSame($sourceId, $second['effective_import_source_id']);
            $readback = $service->readImportedRows($actor, $second);
            self::assertCount(1, $readback);
            self::assertNotSame($oldRowId, $readback[0]['_persisted_row_id']);
            self::assertSame($row + ['_persisted_row_id' => $readback[0]['_persisted_row_id'], '_readback_verified' => true], $readback[0]);
            $newRow = Db::name('online_daily_data')->where('id', $readback[0]['_persisted_row_id'])->find();
            self::assertSame($targetHotelId, (int)$newRow['system_hotel_id']);
            self::assertSame($targetHotelId === self::SYSTEM_HOTEL_ID ? self::TENANT_ID : 21, (int)$newRow['tenant_id']);
            self::assertSame($sourceId, (int)$newRow['data_source_id']);
            self::assertSame($targetPlatform, $newRow['source']);
            self::assertSame($targetDataType, $newRow['data_type']);
            self::assertSame($second['task_id'], (int)$newRow['sync_task_id']);
            self::assertSame($oldRow, Db::name('online_daily_data')->where('id', $oldRowId)->find());
            self::assertSame($oldTask, Db::name('platform_data_sync_tasks')->where('id', $first['task_id'])->find());
        } finally {
            if ($targetHotelId !== self::SYSTEM_HOTEL_ID) {
                Db::name('hotels')->where('id', $targetHotelId)->delete();
            }
        }
    }

    public static function changedSourceScopeProvider(): array
    {
        return [
            'hotel and tenant change' => ['custom', 201, 'custom', 'business'],
            'platform change' => ['meituan', self::SYSTEM_HOTEL_ID, 'custom', 'business'],
            'data type change' => ['custom', self::SYSTEM_HOTEL_ID, 'custom', 'traffic'],
        ];
    }

    public function testSameScopeEditsAndFailedScopeSaveKeepSyncHistoryAndConfiguration(): void
    {
        $sourceId = $this->createManualSource('source-save-compatibility');
        $service = $this->service(new Tc200ManualImportAdapter('success'));
        $service->importRows($this->authorizedUser(), ['data_source_id' => $sourceId, 'rows' => [[
            'data_date' => self::FRESH_DATA_DATE, 'amount' => 0,
        ]]]);
        $sourcePayload = [
            'id' => $sourceId, 'system_hotel_id' => self::SYSTEM_HOTEL_ID, 'platform' => 'custom',
            'data_type' => 'business', 'ingestion_method' => 'manual',
            'name' => 'Renamed same-scope source', 'config' => ['import_note' => 'synthetic edited config'],
        ];
        foreach (['success', 'failed'] as $status) {
            $history = [
                'last_sync_time' => '2026-07-15 10:00:00', 'last_sync_status' => $status,
                'last_error' => $status === 'failed' ? 'collection_failed' : null,
            ];
            Db::name('platform_data_sources')->where('id', $sourceId)->update($history);
            $saved = $service->saveDataSource($this->authorizedUser(), $sourcePayload);
            $stored = Db::name('platform_data_sources')->withoutField('secret_json')->where('id', $sourceId)->find();
            self::assertSame($history, array_intersect_key($stored, $history));
            self::assertSame($sourcePayload['config'], json_decode($stored['config_json'], true));
            self::assertSame($sourcePayload['name'], $stored['name']);
            self::assertSame($status === 'failed' ? 'collection_failed' : 'platform_data_synchronized', $saved['last_error']);
        }
        $before = Db::name('platform_data_sources')->where('id', $sourceId)->find();
        Db::execute("CREATE TRIGGER tc200_source_save_failure BEFORE UPDATE ON platform_data_sources BEGIN SELECT RAISE(ABORT, 'synthetic source save failure'); END");
        try {
            $service->saveDataSource($this->authorizedUser(), array_replace($sourcePayload, [
                'data_type' => 'traffic', 'name' => 'Rejected name', 'config' => ['import_note' => 'must not persist'],
            ]));
            self::fail('Synthetic failed source save unexpectedly succeeded');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('synthetic source save failure', $exception->getMessage());
        } finally {
            Db::execute('DROP TRIGGER tc200_source_save_failure');
        }
        self::assertSame($before, Db::name('platform_data_sources')->where('id', $sourceId)->find());
        self::assertSame(1, (int)Db::name('online_daily_data')->count());
    }

    public function testChangedScopeWaitingConfigAndOtaSourceDoNotDisplayOldSyncError(): void
    {
        $adapter = new Tc200ManualImportAdapter('success');
        $service = $this->service($adapter);
        foreach ([['custom', 'api', 'waiting_config'], ['ctrip', 'browser_assist_dom', 'ready']] as [$platform, $method, $expectedStatus]) {
            $payload = [
                'system_hotel_id' => self::SYSTEM_HOTEL_ID, 'platform' => $platform,
                'data_type' => 'business', 'ingestion_method' => $method,
            ];
            if ($platform === 'ctrip') {
                $payload['config'] = ['platform_hotel_id' => 'synthetic-ctrip-200'];
            }
            $created = $service->saveDataSource($this->authorizedUser(), $payload);
            Db::name('platform_data_sources')->where('id', $created['id'])->update([
                'last_sync_time' => '2026-07-15 10:00:00', 'last_sync_status' => 'failed', 'last_error' => 'collection_failed',
            ]);
            $saved = $service->saveDataSource($this->authorizedUser(), $payload + ['id' => $created['id']]);
            self::assertSame('collection_failed', $saved['last_error']);
            $saved = $service->saveDataSource($this->authorizedUser(), array_replace($payload, ['id' => $created['id'], 'data_type' => 'traffic']));
            $actor = new class { public function isSuperAdmin(): bool { return true; } };
            $listed = $service->listDataSources($actor, ['system_hotel_id' => self::SYSTEM_HOTEL_ID, 'platform' => $platform]);
            self::assertCount(1, $listed);
            foreach ([$saved, $listed[0]] as $source) {
                self::assertSame($expectedStatus, $source['status']);
                self::assertNull($source['last_sync_time']);
                self::assertNull($source['last_sync_status']);
                self::assertSame('', $source['last_error']);
            }
        }
        self::assertSame(0, $adapter->calls);
    }

    #[DataProvider('inFlightSourceScopeProvider')]
    public function testInFlightSyncOnlyProjectsItsConclusionToTheSameSourceScope(string $outcome, string $scopeChange): void
    {
        $sourceId = $this->createManualSource('in-flight-source-scope');
        $originalPlatform = $scopeChange === 'platform' ? 'meituan' : 'custom';
        Db::name('platform_data_sources')->where('id', $sourceId)->update(['platform' => $originalPlatform]);
        $service = $this->service(new Tc200ManualImportAdapter('success'));
        $actor = $this->authorizedUser();
        $sourceAfterEdit = [];
        $sourceFields = 'id,platform,data_type,status,last_sync_time,last_sync_status,last_error';
        $onFetch = function (array $source, array $options) use ($service, $actor, $sourceId, $scopeChange, $outcome, $sourceFields, &$sourceAfterEdit): array {
            $service->saveDataSource($actor, [
                'id' => $sourceId, 'system_hotel_id' => self::SYSTEM_HOTEL_ID, 'platform' => 'custom',
                'data_type' => $scopeChange === 'data_type' ? 'traffic' : 'business',
                'ingestion_method' => 'manual', 'name' => 'Edited during synthetic fetch',
            ]);
            $sourceAfterEdit = Db::name('platform_data_sources')->field($sourceFields)->where('id', $sourceId)->find();
            if ($outcome === 'failed') {
                return ['status' => 'failed', 'message' => 'collection_failed', 'payload' => [], 'http_status' => 503];
            }
            return (new ManualImportDataSourceAdapter())->fetch($source, $options);
        };
        $adapter = new class($onFetch) implements DataSourceAdapter {
            public function __construct(private readonly \Closure $onFetch) {}
            public function supports(array $source): bool { return ($source['ingestion_method'] ?? '') === 'manual'; }
            public function fetch(array $source, array $options = []): array { return ($this->onFetch)($source, $options); }
        };
        (new \ReflectionProperty($service, 'adapters'))->setValue($service, [$adapter]);
        $result = $service->importRows($actor, ['data_source_id' => $sourceId, 'rows' => [[
            'system_hotel_id' => self::SYSTEM_HOTEL_ID, 'platform' => $originalPlatform,
            'data_date' => self::FRESH_DATA_DATE, 'amount' => 0,
        ]]]);

        self::assertSame($outcome, $result['status']);
        $task = Db::name('platform_data_sync_tasks')->where('id', $result['task_id'])->find();
        self::assertSame($outcome, $task['status']);
        self::assertSame($originalPlatform, $task['platform']);
        self::assertSame('business', $task['data_type']);
        self::assertSame(self::SYSTEM_HOTEL_ID, (int)$task['system_hotel_id']);
        self::assertSame(self::TENANT_ID, (int)$task['tenant_id']);
        self::assertNotEmpty($task['finished_at']);
        $sourceAfterFinish = Db::name('platform_data_sources')->field($sourceFields)->where('id', $sourceId)->find();
        if ($scopeChange === 'unchanged') {
            self::assertSame($outcome, $sourceAfterFinish['last_sync_status']);
            self::assertSame($outcome, $sourceAfterFinish['status']);
            self::assertNotEmpty($sourceAfterFinish['last_sync_time']);
            self::assertSame($outcome === 'failed' ? 'collection_failed' : null, $sourceAfterFinish['last_error']);
        } else {
            self::assertSame($sourceAfterEdit, $sourceAfterFinish);
        }
        $rows = Db::name('online_daily_data')->where('data_source_id', $sourceId)->select()->toArray();
        self::assertCount($outcome === 'success' ? 1 : 0, $rows);
        if ($outcome === 'success') {
            self::assertTrue($result['readback_verified']);
            self::assertSame($originalPlatform, $rows[0]['source']);
            self::assertSame('business', $rows[0]['data_type']);
            self::assertSame(self::SYSTEM_HOTEL_ID, (int)$rows[0]['system_hotel_id']);
            self::assertSame(self::TENANT_ID, (int)$rows[0]['tenant_id']);
        } else {
            self::assertFalse($result['readback_verified']);
        }
    }

    public static function inFlightSourceScopeProvider(): array
    {
        $cases = [];
        foreach (['success', 'failed'] as $outcome) {
            foreach (['platform', 'data_type', 'unchanged'] as $scopeChange) {
                $cases[$scopeChange . ' old task ' . $outcome] = [$outcome, $scopeChange];
            }
        }
        return $cases;
    }

    #[DataProvider('inFlightAdsScopeProvider')]
    public function testInFlightAdsModuleStateOnlyUpdatesItsOriginalSourceScope(string $outcome, string $scopeChange): void
    {
        $sourceId = $this->createManualSource('in-flight-ads-scope');
        $originalPlatform = $scopeChange === 'platform' ? 'meituan' : 'custom';
        Db::name('platform_data_sources')->where('id', $sourceId)->update([
            'platform' => $originalPlatform, 'config_json' => '{"import_note":"synthetic retained config"}',
        ]);
        $service = $this->service(new Tc200ManualImportAdapter('success'));
        $actor = $this->authorizedUser();
        $mainResult = $service->importRows($actor, ['data_source_id' => $sourceId, 'rows' => [[
            'system_hotel_id' => self::SYSTEM_HOTEL_ID, 'platform' => $originalPlatform,
            'data_date' => self::FRESH_DATA_DATE, 'amount' => 10,
        ]]]);
        self::assertSame('success', $mainResult['status']);
        $mainRow = Db::name('online_daily_data')->where('data_source_id', $sourceId)->find();
        $mainTask = Db::name('platform_data_sync_tasks')->where('id', $mainResult['task_id'])->find();
        $sourceAfterEdit = [];
        $moduleState = $outcome === 'not_applicable' ? 'not_applicable' : 'blocked';
        $moduleReason = $outcome === 'not_applicable' ? 'ads_service_not_opened' : 'ads_collection_failed';
        $onFetch = function () use ($service, $actor, $sourceId, $scopeChange, $outcome, $moduleState, $moduleReason, &$sourceAfterEdit): array {
            if ($scopeChange !== 'unchanged') {
                $service->saveDataSource($actor, [
                    'id' => $sourceId, 'system_hotel_id' => self::SYSTEM_HOTEL_ID,
                    'platform' => 'custom', 'data_type' => $scopeChange === 'data_type' ? 'traffic' : 'business',
                    'ingestion_method' => 'manual', 'config' => ['import_note' => 'synthetic new-scope config'],
                ]);
            }
            $sourceAfterEdit = Db::name('platform_data_sources')->withoutField('secret_json')->where('id', $sourceId)->find();
            return [
                'status' => $outcome, 'message' => $moduleReason, 'http_status' => 200,
                'payload' => ['module_status' => [
                    'module' => 'ads', 'status' => $moduleState, 'reason' => $moduleReason,
                    'external_action_required' => true,
                ]],
            ];
        };
        $adapter = new class($onFetch) implements DataSourceAdapter {
            public function __construct(private readonly \Closure $onFetch) {}
            public function supports(array $source): bool { return ($source['ingestion_method'] ?? '') === 'manual'; }
            public function fetch(array $source, array $options = []): array { return ($this->onFetch)(); }
        };
        (new \ReflectionProperty($service, 'adapters'))->setValue($service, [$adapter]);
        $result = $service->importRows($actor, ['data_source_id' => $sourceId, 'rows' => []]);
        self::assertSame($outcome, $result['status']);
        $task = Db::name('platform_data_sync_tasks')->where('id', $result['task_id'])->find();
        self::assertSame($outcome, $task['status']);
        self::assertSame($originalPlatform, $task['platform']);
        self::assertSame('business', $task['data_type']);
        self::assertSame(self::SYSTEM_HOTEL_ID, (int)$task['system_hotel_id']);
        self::assertSame(self::TENANT_ID, (int)$task['tenant_id']);
        self::assertNotEmpty($task['finished_at']);
        $sourceAfterFinish = Db::name('platform_data_sources')->withoutField('secret_json')->where('id', $sourceId)->find();
        if ($scopeChange !== 'unchanged') {
            self::assertSame($sourceAfterEdit, $sourceAfterFinish);
        } else {
            foreach (['status', 'last_sync_time', 'last_sync_status', 'last_error'] as $field) {
                self::assertSame($sourceAfterEdit[$field], $sourceAfterFinish[$field]);
            }
            self::assertSame('success', $sourceAfterFinish['status']);
            self::assertSame('success', $sourceAfterFinish['last_sync_status']);
            $config = json_decode($sourceAfterFinish['config_json'], true);
            self::assertSame('synthetic retained config', $config['import_note']);
            self::assertSame($moduleState, $config['ads_status']);
            self::assertSame($moduleReason, $config['ads_status_reason']);
            self::assertNotEmpty($config['ads_status_checked_at']);
            self::assertSame([
                'status' => $moduleState, 'reason' => $moduleReason,
                'checked_at' => $config['ads_status_checked_at'], 'external_action_required' => true,
            ], $config['module_states']['ads']);
        }
        self::assertSame($mainRow, Db::name('online_daily_data')->where('id', $mainRow['id'])->find());
        self::assertSame($mainTask, Db::name('platform_data_sync_tasks')->where('id', $mainResult['task_id'])->find());
        self::assertSame(1, (int)Db::name('online_daily_data')->count());
    }

    public static function inFlightAdsScopeProvider(): array
    {
        $cases = [];
        foreach (['failed', 'not_applicable'] as $outcome) {
            foreach (['platform', 'data_type', 'unchanged'] as $scopeChange) {
                $cases[$scopeChange . ' old ads ' . $outcome] = [$outcome, $scopeChange];
            }
        }
        return $cases;
    }

    public function testRestrictedActorCannotCreateDedicatedManualSourceFromExecutableSource(): void
    {
        $sourceId = $this->createExecutableOtaSource('ctrip', 'browser_profile', 'CTRIP-TC200-RESTRICTED');
        $manualAdapter = new Tc200ManualImportAdapter('success');
        $executableAdapter = new Tc200ExecutableSourceTrapAdapter();
        $service = $this->service($manualAdapter, [$executableAdapter]);
        $sourceCountBefore = (int)Db::name('platform_data_sources')->count();

        try {
            $service->importRows($this->restrictedUser(), [
                'data_source_id' => $sourceId,
                'rows' => [[
                    'hotel_id' => 'CTRIP-TC200-RESTRICTED',
                    'data_date' => self::FRESH_DATA_DATE,
                    'amount' => 1,
                ]],
            ]);
            self::fail('restricted actor unexpectedly created/imported a manual source');
        } catch (\RuntimeException $exception) {
            self::assertSame(403, $exception->getCode());
        }

        self::assertSame($sourceCountBefore, (int)Db::name('platform_data_sources')->count());
        self::assertSame(0, $manualAdapter->calls);
        self::assertSame(0, $executableAdapter->calls);
        self::assertSame(0, (int)Db::name('online_daily_data')->count());
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
        Db::execute('CREATE TABLE users (id INTEGER PRIMARY KEY, username VARCHAR(100) NOT NULL)');
        Db::name('users')->insert(['id' => self::AUTHORIZED_USER_ID, 'username' => 'tc200-user']);
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)');
        Db::name('hotels')->insert([
            'id' => self::SYSTEM_HOTEL_ID,
            'tenant_id' => self::TENANT_ID,
        ]);
        Db::execute('CREATE TABLE platform_data_sources (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, system_hotel_id INTEGER, user_id INTEGER, name VARCHAR(120) NOT NULL, platform VARCHAR(50) NOT NULL, data_type VARCHAR(50) NOT NULL, ingestion_method VARCHAR(30) NOT NULL, status VARCHAR(30) NOT NULL, enabled INTEGER NOT NULL, config_json TEXT, secret_json TEXT, last_sync_time DATETIME, last_sync_status VARCHAR(30), last_error TEXT, created_by INTEGER, updated_by INTEGER, create_time DATETIME, update_time DATETIME)');
        Db::execute('CREATE TABLE platform_data_sync_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, data_source_id INTEGER, system_hotel_id INTEGER, platform VARCHAR(50) NOT NULL, data_type VARCHAR(50) NOT NULL, ingestion_method VARCHAR(30) NOT NULL, trigger_type VARCHAR(30) NOT NULL, status VARCHAR(30) NOT NULL, attempt_count INTEGER NOT NULL, max_attempts INTEGER NOT NULL, started_at DATETIME, finished_at DATETIME, next_retry_at DATETIME, requested_by INTEGER, message TEXT, stats_json TEXT, create_time DATETIME, update_time DATETIME)');
        Db::execute('CREATE TABLE platform_data_sync_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, sync_task_id INTEGER, data_source_id INTEGER, system_hotel_id INTEGER, level VARCHAR(20), event VARCHAR(80), message TEXT, context_json TEXT, create_time DATETIME)');
        Db::execute('CREATE TABLE platform_data_raw_records (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, data_source_id INTEGER, sync_task_id INTEGER, system_hotel_id INTEGER, platform VARCHAR(50), data_type VARCHAR(50), ingestion_method VARCHAR(30), payload_hash VARCHAR(64), raw_payload TEXT, http_status INTEGER, received_at DATETIME, create_time DATETIME)');
        Db::execute('CREATE TABLE online_daily_data (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id VARCHAR(50), hotel_name VARCHAR(100), system_hotel_id INTEGER, data_date DATE NOT NULL, amount DECIMAL(12,2), quantity INTEGER, book_order_num INTEGER, comment_score DECIMAL(3,1), qunar_comment_score DECIMAL(3,1), data_value DECIMAL(12,2), source VARCHAR(50), dimension VARCHAR(100), data_type VARCHAR(50), platform VARCHAR(50), compare_type VARCHAR(50), list_exposure INTEGER, detail_exposure INTEGER, flow_rate DECIMAL(12,4), order_filling_num INTEGER, order_submit_num INTEGER, validation_status VARCHAR(60), validation_flags TEXT, readback_verified INTEGER NOT NULL DEFAULT 0, readback_verified_at DATETIME, data_source_id INTEGER, sync_task_id INTEGER, ingestion_method VARCHAR(30), source_trace_id VARCHAR(100), persistence_identity_hash VARCHAR(64) UNIQUE, data_period VARCHAR(30), snapshot_time DATETIME, snapshot_bucket VARCHAR(20), is_final INTEGER, raw_data TEXT, create_time DATETIME, update_time DATETIME)');
        Db::execute('CREATE TABLE ota_ctrip_metric_facts (id INTEGER PRIMARY KEY AUTOINCREMENT, run_id INTEGER, tenant_id INTEGER, system_hotel_id INTEGER, ota_hotel_id VARCHAR(64), hotel_name VARCHAR(160), data_date DATE, source VARCHAR(50), capture_section VARCHAR(80), endpoint_id VARCHAR(120), metric_key VARCHAR(120), metric_label VARCHAR(160), category VARCHAR(60), data_type VARCHAR(50), metric_scope VARCHAR(50), value_type VARCHAR(30), value_decimal DECIMAL(18,4), value_text VARCHAR(1000), source_key VARCHAR(160), source_path VARCHAR(700), source_hash VARCHAR(64), raw_data TEXT, capture_status VARCHAR(80), captured_at DATETIME)');
    }

    /** @return array<string, bool> */
    private function normalizedPersistenceColumns(): array
    {
        return array_fill_keys([
            'id', 'tenant_id', 'hotel_id', 'hotel_name', 'system_hotel_id', 'data_date', 'amount', 'quantity',
            'book_order_num', 'comment_score', 'qunar_comment_score', 'data_value', 'source', 'dimension',
            'data_type', 'platform', 'compare_type', 'list_exposure', 'detail_exposure', 'flow_rate',
            'order_filling_num', 'order_submit_num', 'validation_status', 'validation_flags',
            'readback_verified', 'readback_verified_at', 'data_source_id',
            'sync_task_id', 'ingestion_method', 'source_trace_id', 'data_period', 'snapshot_time',
            'persistence_identity_hash', 'snapshot_bucket', 'is_final', 'raw_data', 'create_time', 'update_time',
        ], true);
    }

    private function createManualSource(string $caseId, string $dataType = 'business'): int
    {
        return (int)Db::name('platform_data_sources')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'system_hotel_id' => self::SYSTEM_HOTEL_ID,
            'user_id' => self::AUTHORIZED_USER_ID,
            'name' => 'TC-200 isolated manual import ' . $caseId,
            'platform' => 'custom',
            'data_type' => $dataType,
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

    private function createExecutableOtaSource(string $platform, string $method, string $platformHotelId): int
    {
        return (int)Db::name('platform_data_sources')->insertGetId([
            'tenant_id' => self::TENANT_ID,
            'system_hotel_id' => self::SYSTEM_HOTEL_ID,
            'user_id' => self::AUTHORIZED_USER_ID,
            'name' => 'TC-200 executable source ' . $method,
            'platform' => $platform,
            'data_type' => 'business',
            'ingestion_method' => $method,
            'status' => 'ready',
            'enabled' => 1,
            'config_json' => json_encode([
                'platform_hotel_id' => $platformHotelId,
                'profile_id' => 'must-not-copy-profile-' . $method,
                'request_url' => 'https://example.invalid/must-not-run-' . $method,
                'headers' => ['Authorization' => 'must-not-copy-authorization'],
                'config_id' => 'must-not-copy-config-' . $method,
                'credential_status' => 'ready',
            ], JSON_UNESCAPED_SLASHES),
            'secret_json' => json_encode(['token' => 'must-not-copy-secret-' . $method]),
            'last_sync_time' => '2026-07-01 08:00:00',
            'last_sync_status' => 'success',
            'last_error' => null,
            'created_by' => self::AUTHORIZED_USER_ID,
            'updated_by' => self::AUTHORIZED_USER_ID,
            'create_time' => '2026-07-01 07:00:00',
            'update_time' => '2026-07-01 08:00:00',
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

    /** @param array<int, DataSourceAdapter> $additionalAdapters */
    private function service(Tc200ManualImportAdapter $adapter, array $additionalAdapters = []): PlatformDataSyncService
    {
        $service = new PlatformDataSyncService(array_merge([$adapter], $additionalAdapters));
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
            'online_daily_data' => $this->normalizedPersistenceColumns(),
        ]);
        return $service;
    }

    private function authorizedUser(): object
    {
        return new class {
            public int $id = 2001;
            public int $tenant_id = 20;

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
            public int $tenant_id = 20;

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

final class Tc200ExecutableSourceTrapAdapter implements DataSourceAdapter
{
    public int $calls = 0;

    public function supports(array $source): bool
    {
        return in_array(
            strtolower((string)($source['ingestion_method'] ?? '')),
            ['browser_profile', 'profile_browser', 'api'],
            true
        );
    }

    public function fetch(array $source, array $options = []): array
    {
        $this->calls++;
        return [
            'status' => 'success',
            'message' => 'executable source trap invoked',
            'payload' => is_array($options['payload'] ?? null) ? $options['payload'] : [],
        ];
    }
}
