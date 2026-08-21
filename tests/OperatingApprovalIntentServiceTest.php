<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperatingApprovalIntentService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OperatingApprovalIntentServiceTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App(dirname(__DIR__));
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'operating_approval_intent_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';
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
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$sqlitePath);
    }

    protected function setUp(): void
    {
        foreach ([
            'operation_execution_tasks',
            'operation_execution_intents',
            'user_hotel_permissions',
            'users',
            'roles',
            'hotels',
        ] as $table) {
            Db::name($table)->delete(true);
        }

        Db::name('roles')->insertAll([
            ['id' => 1, 'name' => 'admin', 'level' => 1, 'permissions' => '["all"]', 'status' => 1],
            ['id' => 3, 'name' => 'normal_user', 'level' => 3, 'permissions' => '[]', 'status' => 1],
        ]);

        Db::name('hotels')->insertAll([
            [
                'id' => 20,
                'tenant_id' => 10,
                'name' => 'Target hotel',
                'status' => 1,
                'owner_user_id' => 7,
                'created_by' => 7,
            ],
            [
                'id' => 30,
                'tenant_id' => 11,
                'name' => 'Other tenant hotel',
                'status' => 1,
                'owner_user_id' => 9,
                'created_by' => 9,
            ],
        ]);
        Db::name('users')->insertAll([
            ['id' => 1, 'tenant_id' => 7, 'role_id' => 1, 'status' => 1],
            ['id' => 7, 'tenant_id' => 10, 'role_id' => 3, 'status' => 1],
            ['id' => 8, 'tenant_id' => 10, 'role_id' => 3, 'status' => 1],
            ['id' => 9, 'tenant_id' => 11, 'role_id' => 3, 'status' => 1],
        ]);
        Db::name('user_hotel_permissions')->insert([
            'tenant_id' => 10,
            'user_id' => 8,
            'hotel_id' => 20,
            'status' => 'active',
            'can_view' => 1,
            'can_operation' => 0,
            'expires_at' => null,
        ]);
    }

    public function testCreatesOneRealPendingApprovalAndReplaysExactReadback(): void
    {
        $service = new OperatingApprovalIntentService();
        $refs = self::evidenceRefs();

        $created = $service->createPendingApproval(10, 20, '2026-08-12', 7, $refs);
        $intent = $created['execution_intent'];

        self::assertSame('pending_approval', $created['status']);
        self::assertSame('readback_verified', $created['persistence_status']);
        self::assertFalse($created['reused_existing_intent']);
        self::assertFalse($created['execution_task_created']);
        self::assertFalse($created['external_action_triggered']);
        self::assertMatchesRegularExpression('/^operating_approval_[a-f0-9]{32}$/D', $created['idempotency_key']);
        self::assertGreaterThan(0, $intent['id']);
        self::assertSame(10, $intent['tenant_id']);
        self::assertSame(20, $intent['hotel_id']);
        self::assertSame(55, $intent['source_record_id']);
        self::assertSame(OperatingApprovalIntentService::SOURCE_MODULE, $intent['source_module']);
        self::assertSame('pending_approval', $intent['status']);
        self::assertSame(7, $intent['created_by']);
        self::assertSame(0, $intent['approved_by']);
        self::assertNull($intent['approved_at']);
        self::assertSame([], $intent['tasks']);
        self::assertFalse($intent['target_value']['auto_write_ota']);
        self::assertTrue($intent['evidence']['boundaries']['human_approval_required']);
        self::assertFalse($intent['evidence']['boundaries']['automatic_approval']);
        self::assertFalse($intent['evidence']['boundaries']['automatic_execution']);
        self::assertFalse($intent['evidence']['boundaries']['ota_write']);
        self::assertFalse($intent['evidence']['boundaries']['external_message']);
        self::assertSame(
            $intent['evidence']['approval_target_digest'],
            $intent['target_value']['approval_target_digest']
        );
        self::assertSame(
            $intent['evidence']['approval_target_digest'],
            $intent['evidence']['approval_target']['content_digest']
        );

        $reordered = array_reverse($refs);
        $reordered[1]['row_ids'] = array_reverse($reordered[1]['row_ids']);
        $replayed = $service->createPendingApproval(10, 20, '2026-08-12', 7, $reordered);

        self::assertTrue($replayed['reused_existing_intent']);
        self::assertSame($created['idempotency_key'], $replayed['idempotency_key']);
        self::assertSame($intent['id'], $replayed['execution_intent']['id']);
        self::assertSame('readback_verified', $replayed['persistence_status']);
        self::assertSame(1, (int)Db::name('operation_execution_intents')->count());
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->count());

        $stored = Db::name('operation_execution_intents')->where('id', $intent['id'])->find();
        self::assertIsArray($stored);
        self::assertSame(10, (int)$stored['tenant_id']);
        self::assertSame('pending_approval', $stored['status']);
        self::assertSame(0, (int)$stored['approved_by']);
        self::assertNull($stored['approved_at']);
    }

    public function testScopeAndEvidenceFailuresCreateNothing(): void
    {
        $service = new OperatingApprovalIntentService();
        $refs = self::evidenceRefs();

        $this->assertRejected(
            static fn() => $service->createPendingApproval(11, 20, '2026-08-12', 7, $refs),
            'operating_approval_hotel_scope_mismatch'
        );
        $this->assertRejected(
            static fn() => $service->createPendingApproval(10, 20, '2026-08-12', 9, $refs),
            'operating_approval_actor_scope_mismatch'
        );
        $this->assertRejected(
            static fn() => $service->createPendingApproval(10, 20, '2026-08-12', 8, $refs),
            'operating_approval_actor_not_permitted'
        );
        $this->assertRejected(
            static fn() => $service->createPendingApproval(10, 20, '2026-08-12', 7, []),
            'operating_approval_evidence_refs_invalid'
        );

        $wrongDate = $refs;
        $wrongDate[0]['business_date'] = '2026-08-11';
        $this->assertRejected(
            static fn() => $service->createPendingApproval(10, 20, '2026-08-12', 7, $wrongDate),
            'operating_approval_evidence_ref_date_mismatch'
        );

        $rawField = $refs;
        $rawField[0]['cookie'] = 'must-not-be-accepted';
        $this->assertRejected(
            static fn() => $service->createPendingApproval(10, 20, '2026-08-12', 7, $rawField),
            'operating_approval_evidence_ref_unknown_field'
        );

        self::assertSame(0, (int)Db::name('operation_execution_intents')->count());
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->count());
    }

    public function testVerifiedSuperAdminCanCreateCrossTenantPendingApprovalWithoutExecution(): void
    {
        $created = (new OperatingApprovalIntentService())->createPendingApproval(
            10,
            20,
            '2026-08-12',
            1,
            self::evidenceRefs()
        );

        self::assertSame('pending_approval', $created['status']);
        self::assertSame('readback_verified', $created['persistence_status']);
        self::assertSame(10, $created['execution_intent']['tenant_id']);
        self::assertSame(20, $created['execution_intent']['hotel_id']);
        self::assertSame(1, $created['execution_intent']['created_by']);
        self::assertSame([], $created['execution_intent']['tasks']);
        self::assertFalse($created['execution_task_created']);
        self::assertFalse($created['external_action_triggered']);
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->count());
    }

    public function testReplayFailsClosedIfApprovalStateOrTaskHasDrifted(): void
    {
        $service = new OperatingApprovalIntentService();
        $created = $service->createPendingApproval(10, 20, '2026-08-12', 7, self::evidenceRefs());
        $intentId = (int)$created['execution_intent']['id'];

        Db::name('operation_execution_intents')->where('id', $intentId)->update([
            'status' => 'approved',
            'approved_by' => 7,
            'approved_at' => '2026-08-12 11:00:00',
        ]);
        try {
            $service->createPendingApproval(10, 20, '2026-08-12', 7, self::evidenceRefs());
            self::fail('An already changed approval state must not be reported as pending.');
        } catch (RuntimeException $exception) {
            self::assertSame(409, $exception->getCode());
            self::assertStringContainsString('exact_readback_drift', $exception->getMessage());
        }
        self::assertSame(1, (int)Db::name('operation_execution_intents')->count());
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->count());
    }

    /** @return list<array<string,mixed>> */
    private static function evidenceRefs(): array
    {
        return [
            [
                'role' => 'saved_rows',
                'source_kind' => 'ota',
                'table' => 'online_daily_data',
                'row_ids' => [102, 101],
                'platform' => 'ctrip',
                'business_date' => '2026-08-12',
                'fact_scope' => 'ota_channel',
                'readback_verified' => true,
            ],
            [
                'role' => 'operating_cycle',
                'source_kind' => 'kernel',
                'table' => 'hotel_operating_cycles',
                'row_ids' => [55],
                'platform' => '',
                'business_date' => '2026-08-12',
                'metric_definition_digest' => str_repeat('a', 64),
                'verification_status' => 'verified',
            ],
        ];
    }

    private function assertRejected(callable $callback, string $message): void
    {
        try {
            $callback();
            self::fail('Expected request to fail closed: ' . $message);
        } catch (InvalidArgumentException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    private static function createSchema(): void
    {
        Db::execute(
            'CREATE TABLE hotels ('
            . 'id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT, status INTEGER NOT NULL, '
            . 'owner_user_id INTEGER NOT NULL DEFAULT 0, created_by INTEGER NOT NULL DEFAULT 0)'
        );
        Db::execute(
            'CREATE TABLE roles ('
            . 'id INTEGER PRIMARY KEY, name TEXT NOT NULL, level INTEGER NOT NULL, '
            . 'permissions TEXT NOT NULL, status INTEGER NOT NULL)'
        );
        Db::execute(
            'CREATE TABLE users ('
            . 'id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, role_id INTEGER NOT NULL, status INTEGER NOT NULL)'
        );
        Db::execute(
            'CREATE TABLE user_hotel_permissions ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, user_id INTEGER NOT NULL, '
            . 'hotel_id INTEGER NOT NULL, status TEXT NOT NULL, can_view INTEGER NOT NULL, '
            . 'can_operation INTEGER NOT NULL, expires_at TEXT NULL)'
        );
        Db::execute(
            'CREATE TABLE operation_execution_intents ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, idempotency_key TEXT UNIQUE, '
            . 'source_module TEXT NOT NULL, source_record_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, '
            . 'platform TEXT NOT NULL, object_type TEXT NOT NULL, action_type TEXT NOT NULL, date_start TEXT NOT NULL, '
            . 'date_end TEXT NULL, current_value_json TEXT NULL, target_value_json TEXT NULL, evidence_json TEXT NULL, '
            . 'expected_metric TEXT NOT NULL, expected_delta REAL NOT NULL DEFAULT 0, risk_level TEXT NOT NULL, '
            . 'status TEXT NOT NULL, blocked_reason TEXT NOT NULL DEFAULT "", review_remark TEXT NOT NULL DEFAULT "", '
            . 'created_by INTEGER NOT NULL, approved_by INTEGER NOT NULL DEFAULT 0, approved_at TEXT NULL, '
            . 'created_at TEXT NOT NULL, updated_at TEXT NOT NULL, deleted_at TEXT NULL)'
        );
        Db::execute(
            'CREATE TABLE operation_execution_tasks ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, intent_id INTEGER NOT NULL, '
            . 'hotel_id INTEGER NOT NULL, execution_mode TEXT NOT NULL DEFAULT "manual", operator_id INTEGER NOT NULL DEFAULT 0, '
            . 'target_value_json TEXT NULL, current_value_json TEXT NULL, blocked_reason TEXT NOT NULL DEFAULT "", '
            . 'action_track_id INTEGER NOT NULL DEFAULT 0, result_status TEXT NOT NULL DEFAULT "observing", '
            . 'result_summary TEXT NOT NULL DEFAULT "", status TEXT NOT NULL DEFAULT "pending_execute", '
            . 'executed_at TEXT NULL, created_at TEXT NULL, updated_at TEXT NULL, deleted_at TEXT NULL)'
        );
    }
}
