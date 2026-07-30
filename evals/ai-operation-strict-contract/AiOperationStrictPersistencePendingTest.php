<?php
declare(strict_types=1);

namespace Tests\Pending;

use app\service\OperationManagementService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

/** Persistent guard: invalid price requests must never create blocked rows. */
final class AiOperationStrictPersistencePendingTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . '/ai_operation_strict_pending_' . getmypid() . '.sqlite';
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
            throw new RuntimeException('Unable to remove AI operation pending SQLite fixture.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Db::name('operation_execution_evidence')->delete(true);
        Db::name('operation_execution_tasks')->delete(true);
        Db::name('operation_execution_intents')->delete(true);
    }

    public function testMissingPriceMappingThrowsAndLeavesIntentTableUnchanged(): void
    {
        $exception = null;
        try {
            (new OperationManagementService())->createExecutionIntent([7], 7, [
                'source_module' => 'ai_daily_report',
                'source_record_id' => 15,
                'hotel_id' => 7,
                'platform' => 'ctrip',
                'object_type' => 'price',
                'action_type' => 'price_adjust',
                'date_start' => '2026-07-20',
                'date_end' => '2026-07-20',
                'target_value' => ['target_price' => 318],
                'evidence' => ['reason' => 'verified OTA price opportunity'],
            ], 3);
        } catch (InvalidArgumentException $caught) {
            $exception = $caught;
        }

        self::assertSame(
            0,
            (int)Db::name('operation_execution_intents')->count(),
            'invalid price input must be rejected before insertGetId; a blocked row is not an acceptable substitute'
        );
        self::assertInstanceOf(InvalidArgumentException::class, $exception);
        self::assertStringContainsString('room_type_key', $exception->getMessage());
    }

    public function testOnlyPendingApprovalIntentCanBeApproved(): void
    {
        $intentId = $this->seedIntent('approved');
        $this->seedTask($intentId, 'pending_execute');
        $exception = null;

        try {
            (new OperationManagementService())->approveExecutionIntent($intentId, true, '', 3, [7]);
        } catch (InvalidArgumentException $caught) {
            $exception = $caught;
        }

        self::assertInstanceOf(
            InvalidArgumentException::class,
            $exception,
            'approved/rejected/blocked intents must not be approved a second time'
        );
    }

    public function testExpansionIntentCannotBypassScopedSourceEndpoint(): void
    {
        $exception = null;
        try {
            (new OperationManagementService())->createExecutionIntent([7], 7, [
                'source_module' => 'expansion',
                'source_record_id' => 999999,
                'hotel_id' => 7,
                'platform' => 'investment',
                'object_type' => 'expansion',
                'action_type' => 'expansion_post_decision_tracking',
                'target_value' => [
                    'project_name' => 'forged expansion record',
                    'tracking_status' => 'pending_expansion_post_decision_tracking',
                    'target_metric' => 'expansion_project_closure',
                ],
                'evidence' => ['readiness_stage' => 'review_ready'],
            ], 3);
        } catch (InvalidArgumentException $caught) {
            $exception = $caught;
        }

        self::assertInstanceOf(InvalidArgumentException::class, $exception);
        self::assertStringContainsString('scoped expansion record endpoint', $exception->getMessage());
        self::assertSame(0, (int)Db::name('operation_execution_intents')->count());
    }

    public function testPendingTaskCannotReceiveExecutionEvidence(): void
    {
        $intentId = $this->seedIntent('approved');
        $taskId = $this->seedTask($intentId, 'pending_execute');
        $exception = null;

        try {
            (new OperationManagementService())->addExecutionEvidence($taskId, [7], [
                'evidence_type' => 'manual',
                'evidence' => [
                    'after' => ['price' => 318],
                    'remark' => 'not executed yet',
                ],
            ], 3);
        } catch (InvalidArgumentException $caught) {
            $exception = $caught;
        }

        self::assertSame(
            0,
            (int)Db::name('operation_execution_evidence')->where('task_id', $taskId)->count(),
            'evidence cannot be attached before the task is executed'
        );
        self::assertInstanceOf(InvalidArgumentException::class, $exception);
    }

    public function testTaskCannotBeReviewedBeforeExecutionAndEvidence(): void
    {
        $intentId = $this->seedIntent('approved');
        $taskId = $this->seedTask($intentId, 'pending_execute');
        $exception = null;

        try {
            (new OperationManagementService())->reviewExecutionTask($taskId, [7], [
                'result_status' => 'success',
                'result_summary' => 'must not be accepted before execution',
            ]);
        } catch (InvalidArgumentException $caught) {
            $exception = $caught;
        }

        $persisted = Db::name('operation_execution_tasks')->where('id', $taskId)->find();
        self::assertSame('observing', $persisted['result_status']);
        self::assertInstanceOf(
            InvalidArgumentException::class,
            $exception,
            'review requires an executed task with persisted evidence'
        );
    }

    public function testTerminalReviewCannotBeOverwrittenOrRolledBack(): void
    {
        $intentId = $this->seedIntent('approved');
        $taskId = $this->seedTask($intentId, 'executed', 'success');
        $this->seedEvidence($taskId);
        $exception = null;

        try {
            (new OperationManagementService())->reviewExecutionTask($taskId, [7], [
                'result_status' => 'observing',
                'result_summary' => 'stale client retry',
            ]);
        } catch (InvalidArgumentException $caught) {
            $exception = $caught;
        }

        $persisted = Db::name('operation_execution_tasks')->where('id', $taskId)->find();
        self::assertSame('success', $persisted['result_status'], 'terminal review status must be immutable');
        self::assertInstanceOf(InvalidArgumentException::class, $exception);
    }

    public function testIntentResourceReadIsHiddenOutsideAuthorizedHotelScope(): void
    {
        $intentId = $this->seedIntent('pending_approval');
        $service = new OperationManagementService();

        self::assertSame($intentId, (int)$service->readExecutionIntent($intentId, [7])['id']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('execution intent not found');
        $service->readExecutionIntent($intentId, [8]);
    }

    public function testTaskResourceReadIsHiddenOutsideAuthorizedHotelScope(): void
    {
        $intentId = $this->seedIntent('approved');
        $taskId = $this->seedTask($intentId, 'pending_execute');
        $service = new OperationManagementService();

        self::assertSame($taskId, (int)$service->readExecutionTask($taskId, [7])['id']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('execution task not found');
        $service->readExecutionTask($taskId, [8]);
    }

    private function seedIntent(string $status): int
    {
        return (int)Db::name('operation_execution_intents')->insertGetId([
            'tenant_id' => 1,
            'source_module' => 'ai_daily_report',
            'source_record_id' => 15,
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'object_type' => 'price',
            'action_type' => 'price_adjust',
            'date_start' => '2026-07-20',
            'date_end' => '2026-07-20',
            'current_value_json' => json_encode(['current_price' => 280], JSON_THROW_ON_ERROR),
            'target_value_json' => json_encode([
                'room_type_key' => 'RT-1001',
                'rate_plan_key' => 'BAR',
                'target_price' => 318,
            ], JSON_THROW_ON_ERROR),
            'evidence_json' => json_encode(['reason' => 'verified OTA price opportunity'], JSON_THROW_ON_ERROR),
            'expected_metric' => 'ota_revenue',
            'expected_delta' => 8,
            'risk_level' => 'medium',
            'blocked_reason' => '',
            'status' => $status,
            'created_by' => 3,
            'approved_by' => $status === 'approved' ? 3 : 0,
            'approved_at' => $status === 'approved' ? '2026-07-19 12:00:00' : null,
            'created_at' => '2026-07-19 11:00:00',
            'updated_at' => '2026-07-19 12:00:00',
            'deleted_at' => null,
        ]);
    }

    private function seedTask(int $intentId, string $status, string $resultStatus = 'observing'): int
    {
        return (int)Db::name('operation_execution_tasks')->insertGetId([
            'tenant_id' => 1,
            'intent_id' => $intentId,
            'hotel_id' => 7,
            'execution_mode' => 'manual',
            'operator_id' => $status === 'executed' ? 3 : 0,
            'target_value_json' => json_encode(['target_price' => 318], JSON_THROW_ON_ERROR),
            'current_value_json' => json_encode(['current_price' => 280], JSON_THROW_ON_ERROR),
            'blocked_reason' => '',
            'action_track_id' => 0,
            'result_status' => $resultStatus,
            'result_summary' => $resultStatus === 'observing' ? '' : 'terminal result',
            'status' => $status,
            'executed_at' => $status === 'executed' ? '2026-07-20 10:00:00' : null,
            'created_at' => '2026-07-19 12:05:00',
            'updated_at' => '2026-07-20 10:00:00',
            'deleted_at' => null,
        ]);
    }

    private function seedEvidence(int $taskId): void
    {
        Db::name('operation_execution_evidence')->insert([
            'tenant_id' => 1,
            'task_id' => $taskId,
            'evidence_type' => 'manual_price_execution',
            'before_json' => json_encode(['price' => 280], JSON_THROW_ON_ERROR),
            'after_json' => json_encode(['price' => 318], JSON_THROW_ON_ERROR),
            'attachment_path' => 'receipt-15',
            'platform_response_json' => json_encode(['mode' => 'manual'], JSON_THROW_ON_ERROR),
            'remark' => 'executed manually',
            'created_by' => 3,
            'created_at' => '2026-07-20 10:05:00',
            'updated_at' => '2026-07-20 10:05:00',
            'deleted_at' => null,
        ]);
    }

    private static function createSchema(): void
    {
        Db::execute(<<<'SQL'
CREATE TABLE operation_execution_intents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER,
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
    tenant_id INTEGER,
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
