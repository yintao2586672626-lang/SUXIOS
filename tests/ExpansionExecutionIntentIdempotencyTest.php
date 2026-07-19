<?php
declare(strict_types=1);

namespace Tests;

use app\service\ExpansionService;
use app\service\OperationManagementService;
use app\service\OtaPublicPageDiagnosisService;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Throwable;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class ExpansionExecutionIntentIdempotencyTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();

        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'expansion_execution_intent_idempotency_' . getmypid() . '.sqlite';
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
            throw new RuntimeException('Unable to remove expansion idempotency SQLite fixture.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Db::name('operation_execution_evidence')->delete(true);
        Db::name('operation_execution_tasks')->delete(true);
        Db::name('operation_execution_intents')->delete(true);
        Db::name('expansion_records')->delete(true);
        Db::name('users')->delete(true);
    }

    public function testTrustedExpansionIntentCreationReplaysTheExistingIntent(): void
    {
        $service = new OperationManagementService();
        $input = $this->expansionInput(19, 7);

        $first = $service->createExecutionIntent([7], 7, $input, 3, true);
        $second = $service->createExecutionIntent([7], 7, $input, 3, true);

        self::assertSame($first['id'], $second['id']);
        self::assertSame(1, (int)Db::name('operation_execution_intents')->count());

        $row = Db::name('operation_execution_intents')->find((int)$first['id']);
        self::assertIsArray($row);
        self::assertSame('expansion:v1:19', $row['idempotency_key']);
    }

    public function testDifferentRecordGetsANewKeyButDifferentHotelCannotRelinkTheRecord(): void
    {
        $service = new OperationManagementService();

        $first = $service->createExecutionIntent([7, 8], 7, $this->expansionInput(19, 7), 3, true);
        $differentRecord = $service->createExecutionIntent([7, 8], 7, $this->expansionInput(20, 7), 3, true);

        self::assertNotSame($first['id'], $differentRecord['id']);
        try {
            $service->createExecutionIntent([7, 8], 8, $this->expansionInput(19, 8), 3, true);
            self::fail('The same expansion record must not be relinked to a different hotel.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('different hotel', $e->getMessage());
            self::assertSame(409, $e->getCode());
        }

        self::assertSame(2, (int)Db::name('operation_execution_intents')->count());
        self::assertSame(
            ['expansion:v1:19', 'expansion:v1:20'],
            Db::name('operation_execution_intents')->order('idempotency_key', 'asc')->column('idempotency_key')
        );
    }

    public function testTrustedOtaDiagnosisKeyUsesTheDatabaseUniqueConstraint(): void
    {
        $service = new OperationManagementService();
        $input = [
            'source_module' => 'ota_diagnosis_saved',
            'source_record_id' => 91,
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'object_type' => 'campaign',
            'action_type' => 'ota_operation_follow_up',
            'date_start' => '2026-07-17',
            'date_end' => '2026-07-17',
            'target_value' => [
                'target_metric' => 'book_order_num',
                'workflow_schedule' => [
                    'assignee_id' => 3,
                    'due_at' => '2026-07-18 18:00:00',
                    'review_at' => '2026-07-19 10:00:00',
                ],
            ],
            'evidence' => ['evidence_refs' => ['ota-public-profile#91']],
            'expected_metric' => 'book_order_num',
            'status' => 'pending_approval',
        ];
        $key = 'ota_diagnosis_action_' . str_repeat('a', 32) . ':attempt:1';

        $first = $service->createExecutionIntent([7], 7, $input, 3, false, $key, true);
        $second = $service->createExecutionIntent([7], 7, $input, 3, false, $key, true);

        self::assertSame($first['id'], $second['id']);
        self::assertTrue($second['idempotent_replay']);
        self::assertSame(1, (int)Db::name('operation_execution_intents')->count());
        self::assertSame($key, Db::name('operation_execution_intents')->value('idempotency_key'));
    }

    public function testPublicPageDiagnosisDraftIsPersistedReadBackAndReplayed(): void
    {
        $diagnosis = [
            'status' => 'insufficient_evidence',
            'platform' => 'ctrip',
            'system_hotel_id' => 7,
            'business_date' => '2026-07-19',
            'platform_source_status' => 'persisted_public_profile_snapshots',
            'evidence_coverage' => [
                'observed_field_count' => 1,
                'verified_field_count' => 0,
                'expected_field_count' => 36,
                'coverage_rate' => 2.78,
            ],
            'dimensions' => [[
                'key' => 'platform_basics',
                'status' => 'partial',
                'unknown_fields' => ['address'],
                'facts' => [[
                    'field_key' => 'name',
                    'quality_status' => 'partial',
                ]],
            ]],
            'sources' => [[
                'platform_hotel_id' => '3456814',
                'source_url' => 'https://hotels.ctrip.com/hotels/3456814.html',
                'response_ref' => 'ota_ctrip_entity_snapshots#901',
                'persistence_readback_status' => 'readback_verified',
                'source_validation_status' => 'partial',
            ]],
            'next_action' => '补齐未知公开页字段。',
            'score_status' => 'not_calculated_no_validated_scoring_rule',
            'source_policy' => 'persisted_public_page_facts_only_no_default_score_no_ota_write',
            'scope_notice' => '仅为 OTA 公开页证据目录。',
        ];
        $diagnosisService = new OtaPublicPageDiagnosisService();
        $draft = $diagnosisService->buildExecutionIntentDraft($diagnosis, [
            'assignee_id' => 3,
            'due_at' => '2099-07-20T18:00',
            'review_at' => '2099-07-21T10:00',
        ]);
        $rescheduledDraft = $diagnosisService->buildExecutionIntentDraft($diagnosis, [
            'assignee_id' => 3,
            'due_at' => '2099-07-22T18:00',
            'review_at' => '2099-07-23T10:00',
        ]);
        $service = new OperationManagementService();

        $first = $service->createExecutionIntent([7], 7, $draft['input'], 3, false, $draft['idempotency_key'], true);
        $second = $service->createExecutionIntent(
            [7],
            7,
            $rescheduledDraft['input'],
            3,
            false,
            $rescheduledDraft['idempotency_key'],
            true
        );

        self::assertSame($draft['idempotency_key'], $rescheduledDraft['idempotency_key']);
        self::assertSame($first['id'], $second['id']);
        self::assertTrue($second['idempotent_replay']);
        $readback = $service->readExecutionIntentByIdempotencyKey($draft['idempotency_key'], [7]);
        self::assertIsArray($readback);
        self::assertSame($first['id'], $readback['id']);
        self::assertNull($service->readExecutionIntentByIdempotencyKey($draft['idempotency_key'], [8]));
        self::assertSame('ota_diagnosis', $first['source_module']);
        self::assertGreaterThan(4294967295, $first['source_record_id']);
        self::assertSame('data_collection', $first['object_type']);
        self::assertSame('complete_public_page_evidence', $first['action_type']);
        self::assertSame(7, $first['hotel_id']);
        self::assertSame('2026-07-19', $first['date_start']);
        self::assertContains('ota_ctrip_entity_snapshots#901', $first['evidence']['evidence_refs']);
        self::assertContains('platform_basics:address:missing', $first['evidence']['data_gaps']);
        self::assertSame([
            'assignee_id' => 3,
            'due_at' => '2099-07-20 18:00:00',
            'review_at' => '2099-07-21 10:00:00',
            'source_policy' => 'human_assigned_schedule_requires_manual_approval_and_readback_review',
        ], $first['target_value']['workflow_schedule']);
        $latest = $service->readLatestOtaDiagnosisExecutionIntentAttempt($draft['idempotency_base_key'], [7]);
        self::assertIsArray($latest);
        self::assertSame(1, $latest['attempt']);
        self::assertSame($first['id'], $latest['intent']['id']);

        $rescheduled = $service->reschedulePendingExecutionIntent(
            (int)$first['id'],
            [7],
            $rescheduledDraft['input']['target_value']['workflow_schedule'],
            3
        );
        self::assertSame($first['id'], $rescheduled['id']);
        self::assertSame(
            $rescheduledDraft['input']['target_value']['workflow_schedule'],
            $rescheduled['target_value']['workflow_schedule']
        );
        self::assertSame(
            $rescheduledDraft['input']['target_value']['workflow_schedule'],
            $rescheduled['evidence']['workflow_schedule']
        );
        self::assertSame(3, $rescheduled['evidence']['schedule_updated_by']);

        $service->approveExecutionIntent((int)$first['id'], false, 'fixture rejection', 3, [7]);
        try {
            $service->reschedulePendingExecutionIntent(
                (int)$first['id'],
                [7],
                $draft['input']['target_value']['workflow_schedule'],
                3
            );
            self::fail('A terminal execution intent must not be rescheduled.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('draft or pending_approval', $exception->getMessage());
        }
        self::assertSame(1, (int)Db::name('operation_execution_intents')->count());
    }

    public function testLatestPublicPageAttemptUsesNumericAttemptInsteadOfRowWindowOrLexicalOrder(): void
    {
        $diagnosisService = new OtaPublicPageDiagnosisService();
        $diagnosis = $diagnosisService->build(7, 'ctrip', '2026-07-19', []);
        $draft = $diagnosisService->buildExecutionIntentDraft($diagnosis, [
            'assignee_id' => 3,
            'due_at' => '2099-07-20T18:00',
            'review_at' => '2099-07-21T10:00',
        ]);
        $service = new OperationManagementService();
        $created = [];
        foreach ([1, 10, 2] as $attempt) {
            $input = $draft['input'];
            $input['evidence']['intent_attempt'] = $attempt;
            $created[$attempt] = $service->createExecutionIntent(
                [7],
                7,
                $input,
                3,
                false,
                $draft['idempotency_base_key'] . ':attempt:' . $attempt,
                true
            );
        }

        $latest = $service->readLatestOtaDiagnosisExecutionIntentAttempt($draft['idempotency_base_key'], [7]);
        self::assertIsArray($latest);
        self::assertSame(10, $latest['attempt']);
        self::assertSame($created[10]['id'], $latest['intent']['id']);
        self::assertSame($draft['idempotency_base_key'] . ':attempt:10', $latest['idempotency_key']);
    }

    public function testSchemaAndControllerExposeTheConcurrencyContract(): void
    {
        $migration = file_get_contents(__DIR__ . '/../database/migrations/20260716_add_execution_intent_idempotency_key.sql');
        $baseSchema = file_get_contents(__DIR__ . '/../database/migrations/20260526_create_operation_execution_loop_tables.sql');
        $initSchema = file_get_contents(__DIR__ . '/../database/init_full.sql');
        $controller = file_get_contents(__DIR__ . '/../app/controller/Expansion.php');
        $expansionService = file_get_contents(__DIR__ . '/../app/service/ExpansionService.php');

        self::assertIsString($migration);
        self::assertStringContainsString('ADD COLUMN IF NOT EXISTS `idempotency_key`', $migration);
        self::assertStringContainsString("CONCAT('expansion:v1:', `source_record_id`)", $migration);
        self::assertStringContainsString('ADD UNIQUE INDEX IF NOT EXISTS `uniq_operation_exec_intent_idempotency`', $migration);
        self::assertIsString($baseSchema);
        self::assertStringContainsString('`idempotency_key` VARCHAR(191)', $baseSchema);
        self::assertStringContainsString('UNIQUE KEY `uniq_operation_exec_intent_idempotency`', $baseSchema);
        self::assertIsString($initSchema);
        self::assertStringContainsString('20260716_add_execution_intent_idempotency_key.sql', $initSchema);
        self::assertIsString($controller);
        self::assertStringContainsString('$this->service->detail($id, $userId, $isSuperAdmin, true)', $controller);
        self::assertStringContainsString("'idempotent_replay' => true", $controller);
        self::assertIsString($expansionService);
        self::assertStringContainsString('if ($lockForUpdate) {', $expansionService);
        self::assertStringContainsString('$query->lock(true);', $expansionService);

        $methodStart = strpos($controller, 'public function createExecutionIntent');
        $methodEnd = strpos($controller, 'public function archive', $methodStart);
        self::assertNotFalse($methodStart);
        self::assertNotFalse($methodEnd);
        $methodSource = substr($controller, $methodStart, $methodEnd - $methodStart);
        self::assertLessThan(
            strpos($methodSource, 'Db::transaction('),
            strpos($methodSource, '$this->service->ensureTable();'),
            'Schema DDL must run before the transaction so it cannot implicitly commit the row lock.'
        );
    }

    public function testDatabaseUniqueConstraintRejectsDuplicateExpansionKey(): void
    {
        $service = new OperationManagementService();
        $service->createExecutionIntent([7], 7, $this->expansionInput(19, 7), 3, true);
        $row = Db::name('operation_execution_intents')->where('idempotency_key', 'expansion:v1:19')->find();
        self::assertIsArray($row);
        unset($row['id']);

        $this->expectException(Throwable::class);
        Db::name('operation_execution_intents')->insert($row);
    }

    public function testExpansionTrackingReplayDoesNotAppendDuplicateHistory(): void
    {
        Db::name('users')->insert([
            'id' => 3,
            'tenant_id' => 7,
            'hotel_id' => 7,
        ]);
        Db::name('expansion_records')->insert([
            'id' => 19,
            'tenant_id' => 7,
            'record_type' => 'collaboration',
            'project_name' => 'Expansion project 19',
            'city_area' => 'Hangzhou',
            'input_json' => json_encode(['project_name' => 'Expansion project 19'], JSON_THROW_ON_ERROR),
            'result_json' => json_encode([], JSON_THROW_ON_ERROR),
            'decision' => 'review_ready',
            'risk_level' => 'medium',
            'created_by' => 3,
            'created_at' => '2026-07-16 10:00:00',
            'updated_at' => '2026-07-16 10:00:00',
            'deleted_at' => null,
        ]);

        $service = new ExpansionService();
        $prepared = new ReflectionProperty($service, 'tableEnsured');
        $prepared->setValue($service, true);

        $tracking = [
            'execution_intent_id' => 42,
            'hotel_id' => 7,
            'status' => 'pending_approval',
        ];
        $first = $service->attachExecutionTracking(19, 3, false, $tracking);
        $second = $service->attachExecutionTracking(19, 3, false, $tracking);

        self::assertSame(42, $first['execution_intent_id']);
        self::assertSame(42, $second['execution_intent_id']);
        $result = json_decode(
            (string)Db::name('expansion_records')->where('id', 19)->value('result_json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertCount(1, $result['execution_tracking']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('different execution intent');
        try {
            $service->attachExecutionTracking(19, 3, false, [
                'execution_intent_id' => 43,
                'hotel_id' => 7,
                'status' => 'pending_approval',
            ]);
        } catch (RuntimeException $e) {
            self::assertSame(409, $e->getCode());
            throw $e;
        }
    }

    /** @return array<string, mixed> */
    private function expansionInput(int $recordId, int $hotelId): array
    {
        return [
            'source_module' => 'expansion',
            'source_record_id' => $recordId,
            'hotel_id' => $hotelId,
            'platform' => 'investment',
            'object_type' => 'expansion',
            'action_type' => 'expansion_post_decision_tracking',
            'date_start' => '2026-07-16',
            'date_end' => '2026-07-31',
            'current_value' => [
                'project_name' => 'Expansion project ' . $recordId,
                'readiness_stage' => 'review_ready',
            ],
            'target_value' => [
                'project_name' => 'Expansion project ' . $recordId,
                'tracking_status' => 'pending_expansion_post_decision_tracking',
                'target_metric' => 'expansion_project_closure',
            ],
            'evidence' => [
                'readiness_stage' => 'review_ready',
                'source_scope' => 'expansion_screening_and_project_decision',
            ],
            'expected_metric' => 'expansion_project_closure',
            'expected_delta' => 0,
            'risk_level' => 'medium',
            'status' => 'pending_approval',
        ];
    }

    private static function createSchema(): void
    {
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
        Db::execute(<<<'SQL'
CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    tenant_id INTEGER,
    hotel_id INTEGER
)
SQL);
        Db::execute(<<<'SQL'
CREATE TABLE expansion_records (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER,
    record_type TEXT NOT NULL DEFAULT '',
    project_name TEXT NOT NULL DEFAULT '',
    city_area TEXT NOT NULL DEFAULT '',
    input_json TEXT,
    result_json TEXT,
    decision TEXT NOT NULL DEFAULT '',
    risk_level TEXT NOT NULL DEFAULT '',
    created_by INTEGER NOT NULL DEFAULT 0,
    created_at TEXT,
    updated_at TEXT,
    deleted_at TEXT
)
SQL);
    }
}
