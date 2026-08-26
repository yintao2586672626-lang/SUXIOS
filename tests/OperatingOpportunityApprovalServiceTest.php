<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperatingOpportunityApprovalService;
use app\service\OperatingOpportunityLabService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OperatingOpportunityApprovalServiceTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App(dirname(__DIR__));
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'operating_opportunity_approval_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';
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
            'operation_execution_evidence',
            'operation_execution_tasks',
            'operation_execution_intents',
            'operating_opportunity_runs',
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
                'name' => 'Other hotel',
                'status' => 1,
                'owner_user_id' => 9,
                'created_by' => 9,
            ],
        ]);
        Db::name('users')->insertAll([
            ['id' => 7, 'tenant_id' => 10, 'role_id' => 3, 'status' => 1],
            ['id' => 9, 'tenant_id' => 11, 'role_id' => 3, 'status' => 1],
        ]);
    }

    public function testCreatesOnePendingReviewForManualEstimateAndReplaysItExactly(): void
    {
        $runId = $this->insertRun(
            'service_promise_risk',
            'manual_unverified',
            ['business_date' => '2026-08-23', 'promised_quantity' => 8, 'fulfillable_capacity' => 5],
            [
                'status' => 'blocked_by_missing_facts',
                'calculation_status' => 'provisional_manual_estimate',
                'provisional_metrics' => ['shortage_quantity' => 3, 'risk_amount' => 90.0],
                'decision_eligible' => false,
                'can_execute' => false,
            ]
        );
        $lab = new OperatingOpportunityLabService();
        $run = $lab->readRun(10, 20, $runId);
        $service = new OperatingOpportunityApprovalService($lab);

        $created = $service->createPendingApproval(
            10,
            20,
            $runId,
            7,
            '2026-08-23',
            (string)$run['input_digest'],
            (string)$run['result_digest']
        );
        $intent = $created['execution_intent'];

        self::assertSame(OperatingOpportunityApprovalService::CONTRACT_VERSION, $created['contract_version']);
        self::assertSame('pending_approval', $created['status']);
        self::assertSame('readback_verified', $created['persistence_status']);
        self::assertFalse($created['execution_task_created']);
        self::assertFalse($created['external_action_triggered']);
        self::assertSame('manual_unverified', $created['opportunity_scope']['fact_status']);
        self::assertSame('evidence_review_only', $created['opportunity_scope']['approval_purpose']);
        self::assertSame('shortage_quantity', $created['opportunity_scope']['source_metric']['key']);
        self::assertSame(3, $created['opportunity_scope']['source_metric']['value']);
        self::assertSame('provisional_manual_estimate', $created['opportunity_scope']['source_metric']['status']);
        self::assertSame($runId, $intent['source_record_id']);
        self::assertSame('pending_approval', $intent['status']);
        self::assertSame([], $intent['tasks']);
        self::assertFalse($intent['target_value']['auto_write_ota']);
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->count());

        $replayed = $service->createPendingApproval(
            10,
            20,
            $runId,
            7,
            '2026-08-23',
            (string)$run['input_digest'],
            (string)$run['result_digest']
        );
        self::assertTrue($replayed['reused_existing_intent']);
        self::assertSame($intent['id'], $replayed['execution_intent']['id']);
        self::assertSame(1, (int)Db::name('operation_execution_intents')->count());

        $links = $service->linkedApprovals(10, 20, [$run]);
        self::assertSame((int)$intent['id'], $links[(string)$runId]['intent_id']);
        self::assertSame('pending_approval', $links[(string)$runId]['status']);
        self::assertSame('readback_verified', $links[(string)$runId]['persistence_status']);
        self::assertSame(0, $links[(string)$runId]['task_count']);
        self::assertSame('manual_unverified', $links[(string)$runId]['fact_status']);
    }

    public function testRejectsStaleRunBeforeCreatingIntent(): void
    {
        $oldId = $this->insertRun(
            'bookability_gap',
            'manual_unverified',
            ['business_date' => '2026-08-23', 'platform' => 'ctrip'],
            ['provisional_metrics' => ['affected_condition_count' => 1], 'decision_eligible' => false],
            '2026-08-23 09:00:00'
        );
        $old = (new OperatingOpportunityLabService())->readRun(10, 20, $oldId);
        $this->insertRun(
            'bookability_gap',
            'manual_unverified',
            ['business_date' => '2026-08-23', 'platform' => 'ctrip'],
            ['provisional_metrics' => ['affected_condition_count' => 2], 'decision_eligible' => false],
            '2026-08-23 10:00:00'
        );

        try {
            (new OperatingOpportunityApprovalService())->createPendingApproval(
                10,
                20,
                $oldId,
                7,
                '2026-08-23',
                (string)$old['input_digest'],
                (string)$old['result_digest']
            );
            self::fail('A stale opportunity run must not create a pending approval.');
        } catch (RuntimeException $exception) {
            self::assertSame(409, $exception->getCode());
            self::assertStringContainsString('已陈旧', $exception->getMessage());
        }
        self::assertSame(0, (int)Db::name('operation_execution_intents')->count());
    }

    public function testRejectsTamperedRunDigestBeforeCreatingIntent(): void
    {
        $runId = $this->insertRun(
            'service_promise_risk',
            'manual_unverified',
            ['business_date' => '2026-08-23'],
            ['provisional_metrics' => ['shortage_quantity' => 2], 'decision_eligible' => false]
        );
        $stored = Db::name('operating_opportunity_runs')->where('id', $runId)->find();
        self::assertIsArray($stored);
        Db::name('operating_opportunity_runs')->where('id', $runId)->update([
            'result_json' => self::encode(['provisional_metrics' => ['shortage_quantity' => 99]]),
        ]);

        try {
            (new OperatingOpportunityLabService())->readRun(10, 20, $runId);
            self::fail('A tampered run must fail readback.');
        } catch (RuntimeException $exception) {
            self::assertSame(409, $exception->getCode());
            self::assertStringContainsString('摘要与保存内容不一致', $exception->getMessage());
        }
        self::assertSame(0, (int)Db::name('operation_execution_intents')->count());
    }

    public function testLegacyDailyPriorityCannotCreateASecondApprovalLifecycle(): void
    {
        $runId = $this->insertRun(
            'daily_one_thing',
            'derived_from_saved_runs',
            ['business_date' => '2026-08-23'],
            ['status' => 'action_required', 'selected' => ['run_id' => 91]]
        );
        $run = (new OperatingOpportunityLabService())->readRun(10, 20, $runId);

        try {
            (new OperatingOpportunityApprovalService())->createPendingApproval(
                10,
                20,
                $runId,
                7,
                '2026-08-23',
                (string)$run['input_digest'],
                (string)$run['result_digest']
            );
            self::fail('Legacy daily-one-thing approval must not create a second lifecycle.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('统一优先事项保存链', $exception->getMessage());
        }
        self::assertSame(0, (int)Db::name('operation_execution_intents')->count());
    }

    public function testBlockedDailyPriorityCannotBeSentForApproval(): void
    {
        $sourceRunId = $this->insertRun(
            'service_promise_risk',
            'manual_unverified',
            ['business_date' => '2026-08-23'],
            [
                'status' => 'blocked_by_missing_facts',
                'calculation_status' => 'provisional_manual_estimate',
                'provisional_metrics' => ['shortage_quantity' => 2],
                'decision_eligible' => false,
            ]
        );
        $priorityRunId = $this->insertRun(
            'daily_one_thing',
            'derived_from_saved_runs',
            ['business_date' => '2026-08-23', 'source_run_ids' => [$sourceRunId]],
            [
                'status' => 'blocked_by_missing_facts',
                'selected' => null,
                'feature_key' => 'daily_one_thing',
                'can_execute' => false,
                'requires_human_approval' => true,
            ]
        );
        $run = (new OperatingOpportunityLabService())->readRun(10, 20, $priorityRunId);

        try {
            (new OperatingOpportunityApprovalService())->createPendingApproval(
                10,
                20,
                $priorityRunId,
                7,
                '2026-08-23',
                (string)$run['input_digest'],
                (string)$run['result_digest']
            );
            self::fail('Blocked legacy daily-one-thing run must not create a second lifecycle.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('统一优先事项保存链', $exception->getMessage());
        }
        self::assertSame(0, (int)Db::name('operation_execution_intents')->count());
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $result */
    private function insertRun(
        string $featureKey,
        string $sourceQuality,
        array $input,
        array $result,
        string $createdAt = '2026-08-23 09:00:00'
    ): int {
        return (int)Db::name('operating_opportunity_runs')->insertGetId([
            'tenant_id' => 10,
            'system_hotel_id' => 20,
            'feature_key' => $featureKey,
            'business_date' => '2026-08-23',
            'source_quality_status' => $sourceQuality,
            'source_reference' => null,
            'input_json' => self::encode($input),
            'result_json' => self::encode($result),
            'input_digest' => self::digest($input),
            'result_digest' => self::digest($result),
            'idempotency_key' => 'test-' . $featureKey . '-' . bin2hex(random_bytes(5)),
            'created_by' => 7,
            'created_at' => $createdAt,
        ]);
    }

    /** @param array<int|string,mixed> $value */
    private static function digest(array $value): string
    {
        return hash('sha256', self::encode(self::canonicalize($value)));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map([self::class, 'canonicalize'], $value);
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = self::canonicalize($item);
        return $value;
    }

    private static function encode(mixed $value): string
    {
        return (string)json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }

    private static function createSchema(): void
    {
        Db::execute('CREATE TABLE hotels ('
            . 'id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT, status INTEGER NOT NULL, '
            . 'owner_user_id INTEGER NOT NULL DEFAULT 0, created_by INTEGER NOT NULL DEFAULT 0)');
        Db::execute('CREATE TABLE roles ('
            . 'id INTEGER PRIMARY KEY, name TEXT NOT NULL, level INTEGER NOT NULL, permissions TEXT NOT NULL, status INTEGER NOT NULL)');
        Db::execute('CREATE TABLE users ('
            . 'id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, role_id INTEGER NOT NULL, status INTEGER NOT NULL)');
        Db::execute('CREATE TABLE user_hotel_permissions ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, user_id INTEGER NOT NULL, '
            . 'hotel_id INTEGER NOT NULL, status TEXT NOT NULL, can_view INTEGER NOT NULL, '
            . 'can_operation INTEGER NOT NULL, expires_at TEXT NULL)');
        Db::execute('CREATE TABLE operating_opportunity_runs ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, '
            . 'feature_key TEXT NOT NULL, business_date TEXT NOT NULL, source_quality_status TEXT NOT NULL, '
            . 'source_reference TEXT NULL, input_json TEXT NOT NULL, result_json TEXT NOT NULL, '
            . 'input_digest TEXT NOT NULL, result_digest TEXT NOT NULL, idempotency_key TEXT UNIQUE, '
            . 'created_by INTEGER NOT NULL, created_at TEXT NOT NULL)');
        Db::execute('CREATE TABLE operation_execution_intents ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, idempotency_key TEXT UNIQUE, '
            . 'source_module TEXT NOT NULL, source_record_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, '
            . 'platform TEXT NOT NULL, object_type TEXT NOT NULL, action_type TEXT NOT NULL, date_start TEXT NOT NULL, '
            . 'date_end TEXT NULL, current_value_json TEXT NULL, target_value_json TEXT NULL, evidence_json TEXT NULL, '
            . 'expected_metric TEXT NOT NULL, expected_delta REAL NOT NULL DEFAULT 0, risk_level TEXT NOT NULL, '
            . 'status TEXT NOT NULL, blocked_reason TEXT NOT NULL DEFAULT "", review_remark TEXT NOT NULL DEFAULT "", '
            . 'created_by INTEGER NOT NULL, approved_by INTEGER NOT NULL DEFAULT 0, approved_at TEXT NULL, '
            . 'created_at TEXT NOT NULL, updated_at TEXT NOT NULL, deleted_at TEXT NULL)');
        Db::execute('CREATE TABLE operation_execution_tasks ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, intent_id INTEGER NOT NULL, '
            . 'hotel_id INTEGER NOT NULL, execution_mode TEXT NOT NULL DEFAULT "manual", operator_id INTEGER NOT NULL DEFAULT 0, '
            . 'target_value_json TEXT NULL, current_value_json TEXT NULL, blocked_reason TEXT NOT NULL DEFAULT "", '
            . 'action_track_id INTEGER NOT NULL DEFAULT 0, result_status TEXT NOT NULL DEFAULT "observing", '
            . 'result_summary TEXT NOT NULL DEFAULT "", status TEXT NOT NULL DEFAULT "pending_execute", '
            . 'executed_at TEXT NULL, created_at TEXT NULL, updated_at TEXT NULL, deleted_at TEXT NULL)');
        Db::execute('CREATE TABLE operation_execution_evidence ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL DEFAULT 0, task_id INTEGER NOT NULL DEFAULT 0, '
            . 'hotel_id INTEGER NOT NULL DEFAULT 0, evidence_type TEXT NOT NULL DEFAULT "", evidence_json TEXT NULL, '
            . 'created_by INTEGER NOT NULL DEFAULT 0, created_at TEXT NULL, deleted_at TEXT NULL)');
    }
}
