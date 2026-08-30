<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperationManagementService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OperationMyTasksServiceTest extends TestCase
{
    private const TABLE_PREFIX = 'omt_';

    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'operation_my_tasks_' . getmypid() . '.sqlite';
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
            throw new RuntimeException('Unable to remove operation my-tasks SQLite fixture.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Db::name('operation_execution_evidence')->delete(true);
        Db::name('operation_execution_tasks')->delete(true);
        Db::name('operation_execution_intents')->delete(true);
    }

    public function testPendingTaskUsesFrozenAssigneeInsteadOfEmptyOperator(): void
    {
        $mine = $this->insertAssignedTask(9, 'pending_execute', 0, 7, 7);
        $other = $this->insertAssignedTask(10, 'pending_execute', 0, 7, 7);

        $result = (new OperationManagementService())->myExecutionTasks([7], 7, 9);

        self::assertSame(1, $result['matched_total']);
        self::assertSame(1, $result['returned_count']);
        self::assertFalse($result['truncated']);
        self::assertSame(9, $result['scope']['user_id']);
        self::assertSame($mine['intent_id'], $result['list'][0]['id']);
        self::assertSame($mine['task_id'], $result['list'][0]['execution']['task_id']);
        self::assertSame(0, $result['list'][0]['execution']['operator_id']);
        self::assertSame(9, $result['list'][0]['assignment']['assignee_id']);
        self::assertSame('record_execution', $result['list'][0]['next_action']['key']);
        self::assertNotSame($other['intent_id'], $result['list'][0]['id']);
    }

    public function testClientCannotOverrideCurrentUserAssigneeScope(): void
    {
        $mine = $this->insertAssignedTask(9, 'pending_execute', 0, 7, 7);
        $this->insertAssignedTask(10, 'pending_execute', 0, 7, 7);

        $result = (new OperationManagementService())->myExecutionTasks([7], 7, 9, [
            'assignee_id' => 10,
            'user_id' => 10,
            '_assignee_id' => 10,
        ]);

        self::assertSame([$mine['intent_id']], array_column($result['list'], 'id'));
    }

    public function testMyTasksReportsFilteredTotalsAndTruncationAfterAssigneeMatching(): void
    {
        $first = $this->insertAssignedTask(9, 'pending_execute', 0, 7, 7);
        $second = $this->insertAssignedTask(9, 'executing', 9, 7, 7);
        $this->insertAssignedTask(10, 'pending_execute', 0, 7, 7);

        $result = (new OperationManagementService())->myExecutionTasks([7], 7, 9, ['limit' => 1]);

        self::assertSame(2, $result['matched_total']);
        self::assertSame(1, $result['returned_count']);
        self::assertTrue($result['truncated']);
        self::assertSame($second['intent_id'], $result['list'][0]['id']);
        self::assertContains('operation_my_tasks_truncated', array_column($result['data_gaps'], 'code'));
        self::assertNotSame($first['intent_id'], $result['list'][0]['id']);
    }

    public function testTaskFromPreviousHotelTenantIsExcluded(): void
    {
        $this->insertAssignedTask(9, 'pending_execute', 0, 7, 8);
        Db::name('hotels')->where('id', 7)->update(['tenant_id' => 7]);

        $result = (new OperationManagementService())->myExecutionTasks([7], 7, 9);

        self::assertSame(0, $result['matched_total']);
        self::assertSame([], $result['list']);
    }

    public function testAssigneeAndActionableFiltersAreAppliedBeforePagination(): void
    {
        $mine = $this->insertAssignedTask(9, 'pending_execute', 0, 7, 7);
        $this->insertAssignedTask(10, 'pending_execute', 0, 7, 7);
        $terminal = $this->insertAssignedTask(9, 'executed', 9, 7, 7);
        Db::name('operation_execution_tasks')->where('id', $terminal['task_id'])->update([
            'result_status' => 'success',
        ]);

        $result = (new OperationManagementService())->myExecutionTasks([7], 7, 9, ['limit' => 1]);

        self::assertSame(1, $result['matched_total']);
        self::assertSame([$mine['intent_id']], array_column($result['list'], 'id'));
        self::assertFalse($result['truncated']);
    }

    public function testPhysicalTablePrefixAndTaskTenantScopeApplyBeforePagination(): void
    {
        $config = Config::get('database');
        $config['connections']['sqlite']['prefix'] = self::TABLE_PREFIX;
        Config::set($config, 'database');
        Db::connect()->close();
        Db::connect(null, true);

        try {
            Db::execute('CREATE TABLE ' . self::TABLE_PREFIX . 'operation_execution_intents ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, '
                . 'target_value_json TEXT, deleted_at TEXT)');
            Db::execute('CREATE TABLE ' . self::TABLE_PREFIX . 'operation_execution_tasks ('
                . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, intent_id INTEGER NOT NULL, '
                . 'hotel_id INTEGER NOT NULL, status TEXT NOT NULL, result_status TEXT, deleted_at TEXT)');
            $target = json_encode(['workflow_schedule' => ['assignee_id' => 9]], JSON_THROW_ON_ERROR);
            $validIntentId = (int)Db::name('operation_execution_intents')->insertGetId([
                'tenant_id' => 7,
                'hotel_id' => 7,
                'target_value_json' => $target,
                'deleted_at' => null,
            ]);
            Db::name('operation_execution_tasks')->insert([
                'tenant_id' => 7,
                'intent_id' => $validIntentId,
                'hotel_id' => 7,
                'status' => 'pending_execute',
                'result_status' => 'observing',
                'deleted_at' => null,
            ]);
            $staleIntentId = (int)Db::name('operation_execution_intents')->insertGetId([
                'tenant_id' => 7,
                'hotel_id' => 7,
                'target_value_json' => $target,
                'deleted_at' => null,
            ]);
            Db::name('operation_execution_tasks')->insert([
                'tenant_id' => 8,
                'intent_id' => $staleIntentId,
                'hotel_id' => 7,
                'status' => 'pending_execute',
                'result_status' => 'observing',
                'deleted_at' => null,
            ]);

            $harness = new class {
                use \app\service\operation\OperationExecutionAssigneeConcern {
                    prepareExecutionFlowQuery as public prepareForTest;
                }
            };
            $query = Db::name('operation_execution_intents')->whereNull('deleted_at');
            self::assertSame(self::TABLE_PREFIX . 'operation_execution_intents', $query->getTable());

            $prepared = $harness->prepareForTest($query, ['_assignee_id' => 9, 'limit' => 1]);

            self::assertSame(1, $prepared['matchedTotal']);
            self::assertSame([$validIntentId], array_map('intval', array_column($prepared['intentRows'], 'id')));
            self::assertFalse($prepared['truncated']);
        } finally {
            Db::connect()->close();
            $config['connections']['sqlite']['prefix'] = '';
            Config::set($config, 'database');
            Db::connect(null, true);
        }
    }

    /** @return array{intent_id:int,task_id:int} */
    private function insertAssignedTask(
        int $assigneeId,
        string $taskStatus,
        int $operatorId,
        int $hotelId,
        int $tenantId
    ): array {
        Db::name('hotels')->where('id', $hotelId)->update(['tenant_id' => $tenantId]);
        $createdAt = sprintf('2026-08-29 10:%02d:00', (int)Db::name('operation_execution_intents')->count());
        $target = [
            'workflow_schedule' => [
                'assignee_id' => $assigneeId,
                'due_at' => '2099-08-30 18:00:00',
                'review_at' => '2099-08-31 10:00:00',
                'source_policy' => 'manual_assignment',
            ],
        ];
        $intentId = (int)Db::name('operation_execution_intents')->insertGetId([
            'tenant_id' => $tenantId,
            'idempotency_key' => hash('sha256', $assigneeId . '|' . $taskStatus . '|' . $createdAt),
            'source_module' => 'manual',
            'source_record_id' => $assigneeId,
            'hotel_id' => $hotelId,
            'platform' => 'internal',
            'object_type' => 'task',
            'action_type' => 'manual_followup',
            'date_start' => '2026-08-29',
            'date_end' => '2026-08-29',
            'current_value_json' => '{}',
            'target_value_json' => json_encode($target, JSON_THROW_ON_ERROR),
            'evidence_json' => '{}',
            'expected_metric' => 'completion',
            'expected_delta' => 0,
            'risk_level' => 'low',
            'blocked_reason' => '',
            'status' => 'approved',
            'created_by' => 3,
            'approved_by' => 3,
            'approved_at' => $createdAt,
            'review_remark' => 'approved fixture',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'deleted_at' => null,
        ]);
        $taskId = (int)Db::name('operation_execution_tasks')->insertGetId([
            'tenant_id' => $tenantId,
            'intent_id' => $intentId,
            'hotel_id' => $hotelId,
            'execution_mode' => 'manual',
            'operator_id' => $operatorId,
            'target_value_json' => json_encode($target, JSON_THROW_ON_ERROR),
            'current_value_json' => '{}',
            'blocked_reason' => '',
            'action_track_id' => 0,
            'result_status' => 'observing',
            'result_summary' => '',
            'status' => $taskStatus,
            'executed_at' => $taskStatus === 'executed' ? $createdAt : null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'deleted_at' => null,
        ]);
        return ['intent_id' => $intentId, 'task_id' => $taskId];
    }

    private static function createSchema(): void
    {
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)');
        Db::name('hotels')->insertAll([
            ['id' => 7, 'tenant_id' => 7],
            ['id' => 8, 'tenant_id' => 7],
        ]);
        Db::execute(<<<'SQL'
CREATE TABLE operation_execution_intents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER,
    idempotency_key TEXT UNIQUE,
    source_module TEXT NOT NULL DEFAULT '',
    source_record_id INTEGER NOT NULL DEFAULT 0,
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
    tenant_id INTEGER,
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
    status TEXT NOT NULL DEFAULT 'pending_execute',
    executed_at TEXT,
    created_at TEXT,
    updated_at TEXT,
    deleted_at TEXT
)
SQL);
        Db::execute(<<<'SQL'
CREATE TABLE operation_execution_evidence (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER,
    task_id INTEGER NOT NULL,
    hotel_id INTEGER NOT NULL,
    evidence_type TEXT NOT NULL DEFAULT '',
    evidence_payload_json TEXT,
    evidence_digest TEXT NOT NULL DEFAULT '',
    operator_id INTEGER NOT NULL DEFAULT 0,
    created_at TEXT,
    deleted_at TEXT
)
SQL);
    }
}
