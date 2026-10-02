<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperationManagementService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

/** Prefixed in-memory operations only; approval is synthetic and performs no OTA action. */
final class OperationExecutionTenantPrefixTest extends TestCase
{
    private array $originalConfig;

    public static function setUpBeforeClass(): void
    {
        (new App(dirname(__DIR__)))->initialize();
    }

    protected function setUp(): void
    {
        $this->originalConfig = Config::get('database', []);
        $connection = 'operations_prefix_' . bin2hex(random_bytes(6));
        Config::set(['default' => $connection, 'connections' => [$connection => [
            'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'sux_',
            'fields_strict' => false, 'debug' => false,
        ]]], 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE sux_hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER)');
        Db::name('hotels')->insert(['id' => 904, 'tenant_id' => 9004]);
        Db::execute('CREATE TABLE sux_operation_execution_intents (id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER, hotel_id INTEGER, source_module TEXT, source_record_id INTEGER,
            platform TEXT, object_type TEXT, action_type TEXT, date_start TEXT, date_end TEXT,
            current_value_json TEXT, target_value_json TEXT, evidence_json TEXT, expected_metric TEXT,
            expected_delta REAL, risk_level TEXT, status TEXT, blocked_reason TEXT, created_by INTEGER,
            approved_by INTEGER, approved_at TEXT, review_remark TEXT, idempotency_key TEXT UNIQUE,
            created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        Db::execute('CREATE TABLE sux_operation_execution_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER, hotel_id INTEGER, intent_id INTEGER UNIQUE, execution_mode TEXT,
            operator_id INTEGER, target_value_json TEXT, current_value_json TEXT, status TEXT,
            blocked_reason TEXT, result_status TEXT, result_summary TEXT, executed_at TEXT,
            created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        Db::execute('CREATE TABLE sux_operation_execution_evidence (id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER, task_id INTEGER, evidence_type TEXT, before_json TEXT, after_json TEXT,
            attachment_path TEXT, platform_response_json TEXT, remark TEXT, created_by INTEGER,
            created_at TEXT, updated_at TEXT, deleted_at TEXT)');
    }

    protected function tearDown(): void
    {
        Db::connect()->close();
        Config::set($this->originalConfig, 'database');
        Db::connect(null, true);
    }

    public function testPrefixedIntentAndApprovalSaveExactlyOneSameTenantTask(): void
    {
        $service = new OperationManagementService();
        $intent = $this->createIntent($service);
        self::assertSame(9004, (int)$intent['tenant_id']);
        self::assertSame($intent['id'], $service->readExecutionIntent($intent['id'], [904])['id']);
        self::assertSame($intent['id'], $service->executionIntents([904], 904)['list'][0]['id']);
        $approved = $service->approveExecutionIntent($intent['id'], true, 'synthetic human review', 4, [904]);
        self::assertSame('approved', $approved['status']);
        self::assertCount(1, $approved['tasks']);
        $task = $service->readExecutionTask($approved['tasks'][0]['id'], [904]);
        self::assertSame(9004, (int)$task['tenant_id']);
        self::assertSame($intent['id'], (int)$task['intent_id']);
        self::assertSame(['orders' => 2], $task['current_value']);
        self::assertSame($intent['target_value'], $task['target_value']);
        try {
            $service->approveExecutionIntent($intent['id'], true, 'synthetic retry', 4, [904]);
            self::fail('Repeated approval must not create a second task');
        } catch (\InvalidArgumentException) {
            self::assertSame(1, Db::name('operation_execution_tasks')->where('intent_id', $intent['id'])->count());
        }
    }

    public function testPrefixedIntentListExcludesZeroAndForeignTenantRows(): void
    {
        $service = new OperationManagementService();
        $valid = $this->createIntent($service);
        $stored = Db::name('operation_execution_intents')->where('id', $valid['id'])->find();
        unset($stored['id']);
        foreach ([0, 9005] as $tenantId) {
            Db::name('operation_execution_intents')->insert(array_merge($stored, ['tenant_id' => $tenantId]));
        }
        $list = $service->executionIntents([904], 904);
        self::assertSame('ok', $list['data_status']);
        self::assertSame(1, $list['matched_total']);
        self::assertSame([$valid['id']], array_column($list['list'], 'id'));
    }

    public function testUnreadableTenantSchemaIsReadFailureAndCanRecover(): void
    {
        Db::execute('CREATE VIEW sux_broken_probe AS SELECT * FROM absent_synthetic_source');
        $service = new OperationManagementService();
        $probe = new \ReflectionMethod($service, 'executionTenantSchemaHasColumn');
        try {
            $probe->invoke($service, 'broken_probe', 'tenant_id');
            self::fail('Unreadable columns must not be labeled migration-required');
        } catch (\RuntimeException $exception) {
            self::assertSame('database_table_columns_probe_failed:broken_probe', $exception->getMessage());
            self::assertSame(503, $exception->getCode());
        }
        Db::execute('DROP VIEW sux_broken_probe');
        Db::execute('CREATE TABLE sux_broken_probe (tenant_id INTEGER)');
        self::assertTrue($probe->invoke($service, 'broken_probe', 'tenant_id'));
        self::assertFalse($probe->invoke($service, 'missing_execution_table', 'tenant_id'));
    }

    #[DataProvider('persistedTaskDrift')]
    public function testApprovalReadbackDriftRollsBackAndAllowsCleanRetry(string $assignment): void
    {
        $service = new OperationManagementService();
        $intent = $this->createIntent($service);
        Db::execute('CREATE TRIGGER corrupt_synthetic_task AFTER INSERT ON sux_operation_execution_tasks '
            . 'BEGIN UPDATE sux_operation_execution_tasks SET ' . $assignment . ' WHERE id=NEW.id; END');
        try {
            $service->approveExecutionIntent($intent['id'], true, 'synthetic readback failure', 4, [904]);
            self::fail('A task with drifted persistence must reject and roll back approval');
        } catch (\RuntimeException $exception) {
            self::assertSame('human approval task save/readback cardinality check failed', $exception->getMessage());
        }
        self::assertSame('pending_approval', Db::name('operation_execution_intents')->where('id', $intent['id'])->value('status'));
        self::assertSame(0, Db::name('operation_execution_tasks')->count());
        Db::execute('DROP TRIGGER corrupt_synthetic_task');
        $retry = $service->approveExecutionIntent($intent['id'], true, 'synthetic clean retry', 4, [904]);
        self::assertSame('approved', $retry['status']);
        self::assertCount(1, $retry['tasks']);
        self::assertSame(9004, (int)$retry['tasks'][0]['tenant_id']);
    }

    public static function persistedTaskDrift(): array
    {
        return [
            'foreign tenant' => ['tenant_id=9005'],
            'zero tenant' => ['tenant_id=0'],
            'current value' => ["current_value_json='{}'"],
        ];
    }

    private function createIntent(OperationManagementService $service): array
    {
        return $service->createExecutionIntent([904], 904, [
            'source_module' => 'manual', 'hotel_id' => 904, 'platform' => 'ctrip',
            'object_type' => 'campaign', 'action_type' => 'promotion',
            'date_start' => '2026-10-02', 'date_end' => '2026-10-02',
            'current_value' => ['orders' => 2],
            'target_value' => ['campaign_type' => 'synthetic_manual_check', 'target_metric' => 'orders'],
            'evidence' => ['reason' => 'synthetic fixture only'],
            'expected_metric' => 'orders', 'expected_delta' => null, 'risk_level' => 'medium',
        ], 4);
    }
}
