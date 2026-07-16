<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperationManagementService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class DailyWorkbenchOperationSyncTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();

        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'daily_workbench_operation_sync_' . getmypid() . '.sqlite';
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
        Db::connect()->close();
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        if (is_file(self::$sqlitePath) && !unlink(self::$sqlitePath)) {
            throw new RuntimeException('Unable to remove daily workbench operation SQLite fixture.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Db::name('operation_execution_evidence')->delete(true);
        Db::name('operation_execution_tasks')->delete(true);
        Db::name('operation_execution_intents')->delete(true);
        Db::name('hotels')->delete(true);
        Db::name('hotels')->insert(['id' => 7, 'tenant_id' => 42]);
    }

    public function testDoneCreatesNoApprovalTaskOrExecutionEvidence(): void
    {
        $this->insertIntent('pending_approval');

        $result = (new OperationManagementService())->syncDailyWorkbenchPatrolAction(
            [7],
            $this->doneInput(),
            3
        );

        self::assertSame('synced_pending_execution_evidence', $result['status']);
        self::assertSame('done', $result['workbench_status']);
        self::assertSame('pending_approval', $result['intent_status']);
        self::assertFalse($result['execution_claimed']);
        self::assertSame(0, $result['task_id']);
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->count());
        self::assertSame(0, (int)Db::name('operation_execution_evidence')->count());

        $second = (new OperationManagementService())->syncDailyWorkbenchPatrolAction([7], $this->doneInput(), 3);
        self::assertSame($result['intent_id'], $second['intent_id']);
        self::assertSame(1, (int)Db::name('operation_execution_intents')->count());
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->count());
    }

    public function testDoneDoesNotExecuteAnApprovedPendingTask(): void
    {
        $intentId = $this->insertIntent('approved');
        $taskId = (int)Db::name('operation_execution_tasks')->insertGetId([
            'intent_id' => $intentId,
            'hotel_id' => 7,
            'status' => 'pending_execute',
            'created_at' => '2026-07-17 10:00:00',
            'updated_at' => '2026-07-17 10:00:00',
        ]);

        $result = (new OperationManagementService())->syncDailyWorkbenchPatrolAction([7], $this->doneInput(), 3);

        self::assertSame('synced_pending_execution_evidence', $result['status']);
        self::assertSame($taskId, $result['task_id']);
        self::assertSame('pending_execute', $result['task_status']);
        self::assertFalse($result['execution_claimed']);
        self::assertSame('execute_task_and_attach_external_or_business_evidence', $result['required_next_action']);
        self::assertSame('pending_execute', Db::name('operation_execution_tasks')->where('id', $taskId)->value('status'));
        self::assertSame(0, (int)Db::name('operation_execution_evidence')->count());
    }

    public function testHotelTenantResolverUsesHotelTenantInsteadOfHotelId(): void
    {
        $method = new ReflectionMethod(OperationManagementService::class, 'tenantIdForHotel');
        self::assertSame(42, $method->invoke(new OperationManagementService(), 7));

        $migration = file_get_contents(__DIR__ . '/../database/migrations/20260717_repair_operation_tenant_scope.sql');
        self::assertIsString($migration);
        self::assertStringContainsString('INNER JOIN `hotels` hotel', $migration);
        foreach ([
            'operation_alerts',
            'operation_action_tracks',
            'operation_execution_intents',
            'operation_execution_tasks',
            'operation_execution_evidence',
        ] as $table) {
            self::assertStringContainsString('`' . $table . '`', $migration);
        }
    }

    /** @return array<string, mixed> */
    private function doneInput(): array
    {
        return [
            'hotel_id' => 7,
            'run_id' => 'patrol-run-20260717',
            'action_code' => 'refresh_ota_inventory',
            'question_key' => '',
            'status' => 'done',
            'target_date' => '2026-07-17',
        ];
    }

    private function insertIntent(string $status): int
    {
        $sourceRecordId = (int)sprintf('%u', crc32('patrol-run-20260717|7|refresh_ota_inventory|'));
        return (int)Db::name('operation_execution_intents')->insertGetId([
            'source_module' => 'ota_diagnosis',
            'source_record_id' => $sourceRecordId,
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'object_type' => 'data_collection',
            'action_type' => 'refresh_ota_inventory',
            'date_start' => '2026-07-17',
            'date_end' => '2026-07-17',
            'current_value_json' => '{}',
            'target_value_json' => '{}',
            'evidence_json' => '{}',
            'expected_metric' => 'ota_operation_closure',
            'expected_delta' => 0,
            'risk_level' => 'medium',
            'blocked_reason' => '',
            'status' => $status,
            'created_by' => 3,
            'created_at' => '2026-07-17 10:00:00',
            'updated_at' => '2026-07-17 10:00:00',
        ]);
    }

    private static function createSchema(): void
    {
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)');
        Db::execute(<<<'SQL'
CREATE TABLE operation_execution_intents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_module TEXT NOT NULL,
    source_record_id INTEGER NOT NULL,
    hotel_id INTEGER NOT NULL,
    platform TEXT NOT NULL DEFAULT '',
    object_type TEXT NOT NULL DEFAULT '',
    action_type TEXT NOT NULL DEFAULT '',
    date_start TEXT,
    date_end TEXT,
    current_value_json TEXT,
    target_value_json TEXT,
    evidence_json TEXT,
    expected_metric TEXT NOT NULL DEFAULT '',
    expected_delta REAL NOT NULL DEFAULT 0,
    risk_level TEXT NOT NULL DEFAULT 'medium',
    blocked_reason TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL,
    created_by INTEGER NOT NULL DEFAULT 0,
    approved_by INTEGER NOT NULL DEFAULT 0,
    approved_at TEXT,
    review_remark TEXT NOT NULL DEFAULT '',
    created_at TEXT,
    updated_at TEXT,
    deleted_at TEXT
)
SQL);
        Db::execute(<<<'SQL'
CREATE TABLE operation_execution_tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    intent_id INTEGER NOT NULL,
    hotel_id INTEGER NOT NULL,
    execution_mode TEXT NOT NULL DEFAULT 'manual',
    operator_id INTEGER NOT NULL DEFAULT 0,
    target_value_json TEXT,
    current_value_json TEXT,
    blocked_reason TEXT NOT NULL DEFAULT '',
    action_track_id INTEGER NOT NULL DEFAULT 0,
    result_status TEXT NOT NULL DEFAULT 'observing',
    result_summary TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL,
    executed_at TEXT,
    created_at TEXT,
    updated_at TEXT,
    deleted_at TEXT
)
SQL);
        Db::execute(<<<'SQL'
CREATE TABLE operation_execution_evidence (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id INTEGER NOT NULL,
    evidence_type TEXT NOT NULL DEFAULT 'manual',
    before_json TEXT,
    after_json TEXT,
    attachment_path TEXT NOT NULL DEFAULT '',
    platform_response_json TEXT,
    remark TEXT NOT NULL DEFAULT '',
    created_by INTEGER NOT NULL DEFAULT 0,
    created_at TEXT,
    updated_at TEXT,
    deleted_at TEXT
)
SQL);
    }
}
