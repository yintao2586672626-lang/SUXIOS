<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperatingTargetAutomationService;
use app\service\OperatingTargetExecutionProvenanceService;
use app\service\OperatingTargetService;
use app\service\OperationManagementService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OperatingTargetAutomationServiceTest extends TestCase
{
    private static array $databaseConfig;
    private static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . '/target_automation_' . getmypid() . '.sqlite';
        @unlink(self::$databasePath);
        $config = self::$databaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$databasePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($config, 'database');
        Db::connect(null, true);
        self::createSchema();
    }

    public static function tearDownAfterClass(): void
    {
        Config::set(self::$databaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$databasePath);
    }

    protected function setUp(): void
    {
        Db::name('operation_execution_evidence')->delete(true);
        Db::name('operation_execution_tasks')->delete(true);
        Db::name('operation_execution_intents')->delete(true);
        Db::name('operating_target_daily_snapshots')->delete(true);
        Db::name('operating_target_daily_records')->delete(true);
        Db::name('hotels')->delete(true);
        Db::name('hotels')->insert(['id' => 80, 'tenant_id' => 9, 'name' => '审查酒店']);
    }

    public function testVerifiedDeviationCreatesNonOtaHumanApprovalIntent(): void
    {
        $targets = new OperatingTargetService();
        $targets->save(9, 80, 7, [
            'target_date' => '2026-07-28',
            'target_revenue' => 10000,
            'target_occupancy_rate_percent' => 80,
            'target_revpar' => 300,
            'actual_revenue' => 8000,
            'sold_room_nights' => 30,
            'sellable_room_nights' => 40,
            'fact_scope' => 'accommodation_room_fee',
            'source_type' => 'pms',
            'source_reference' => '订单来了 / capture:1',
            'quality_status' => 'verified',
            'fact_captured_at' => '2026-07-28 08:10:00',
        ]);
        $operations = new CapturingOperationManagementService();

        $result = (new OperatingTargetAutomationService($targets, $operations))
            ->createTaskDraft(9, 80, 7, '2026-07-28', [
                'assignee_id' => 12,
                'due_at' => '2026-07-28 18:00:00',
            ]);

        self::assertSame('task_draft_ready', $result['status']);
        self::assertSame('operating_target', $operations->input['source_module']);
        self::assertSame('', $operations->input['platform']);
        self::assertSame('pending_approval', $operations->input['status']);
        self::assertFalse($operations->input['evidence']['auto_write_ota']);
        self::assertSame(
            OperatingTargetExecutionProvenanceService::CONTRACT_VERSION,
            $operations->input['evidence']['operating_target_provenance_contract']
        );
        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            $operations->input['evidence']['operating_target_source_digest']
        );
        self::assertSame(12, $operations->input['target_value']['assignee_id']);
        self::assertMatchesRegularExpression('/^operating_target_[a-f0-9]{32}$/', $operations->idempotencyKey);
        self::assertContains(
            'occupancy_rate',
            array_column($result['analysis']['deviations'], 'metric')
        );
        self::assertContains(
            'revpar',
            array_column($result['analysis']['deviations'], 'metric')
        );
    }

    public function testApprovalProvenanceRejectsAChangedOperatingTargetRevision(): void
    {
        $targets = new OperatingTargetService();
        $targets->save(9, 80, 7, [
            'target_date' => '2026-07-28',
            'target_revenue' => 10000,
            'actual_revenue' => 8000,
            'sold_room_nights' => 30,
            'sellable_room_nights' => 40,
            'fact_scope' => 'whole_hotel',
            'source_type' => 'manual',
            'quality_status' => 'manual_confirmed',
        ]);
        $record = $targets->current(9, 80, '2026-07-28')['record'];
        $provenance = new OperatingTargetExecutionProvenanceService();
        $intent = [
            'hotel_id' => 80,
            'source_record_id' => (int)$record['id'],
            'date_start' => '2026-07-28',
            'evidence' => [
                'operating_target_provenance_contract' =>
                    OperatingTargetExecutionProvenanceService::CONTRACT_VERSION,
                'operating_target_source_digest' => $provenance->digest($record),
            ],
        ];
        $method = new \ReflectionMethod(
            OperationManagementService::class,
            'assertOperatingTargetIntentSourceIsCurrent'
        );
        $service = new OperationManagementService();
        $method->invoke($service, $intent);

        $targets->save(9, 80, 7, [
            'target_date' => '2026-07-28',
            'target_revenue' => 10000,
            'actual_revenue' => 8500,
            'sold_room_nights' => 31,
            'sellable_room_nights' => 40,
            'fact_scope' => 'whole_hotel',
            'source_type' => 'manual',
            'quality_status' => 'manual_confirmed',
            'change_reason' => 'PMS 事实更新',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('operating target source changed');
        $method->invoke($service, $intent);
    }

    public function testApprovalLocksIntentAndTargetSourceInOneTransactionBeforeProvenanceCheck(): void
    {
        $targets = new OperatingTargetService();
        $targets->save(9, 80, 7, [
            'target_date' => '2026-07-28',
            'target_revenue' => 10000,
            'actual_revenue' => 8000,
            'sold_room_nights' => 30,
            'sellable_room_nights' => 40,
            'fact_scope' => 'whole_hotel',
            'source_type' => 'manual',
            'quality_status' => 'manual_confirmed',
        ]);
        $record = $targets->current(9, 80, '2026-07-28')['record'];
        $provenance = new OperatingTargetExecutionProvenanceService();
        $intentId = (int)Db::name('operation_execution_intents')->insertGetId([
            'source_module' => 'operating_target',
            'source_record_id' => (int)$record['id'],
            'hotel_id' => 80,
            'platform' => '',
            'object_type' => 'revenue',
            'action_type' => 'close_target_gap',
            'date_start' => '2026-07-28',
            'date_end' => '2026-07-28',
            'current_value_json' => '{}',
            'target_value_json' => '{}',
            'evidence_json' => json_encode([
                'operating_target_provenance_contract' =>
                    OperatingTargetExecutionProvenanceService::CONTRACT_VERSION,
                'operating_target_source_digest' => $provenance->digest($record),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'expected_metric' => 'revenue',
            'expected_delta' => 0,
            'risk_level' => 'medium',
            'status' => 'pending_approval',
            'blocked_reason' => '',
            'review_remark' => '',
            'created_by' => 7,
            'approved_by' => 0,
            'created_at' => '2026-07-28 09:00:00',
            'updated_at' => '2026-07-28 09:00:00',
        ]);
        $service = new TargetApprovalInterleavingService();

        try {
            $service->approveExecutionIntent($intentId, true, 'approve fixture', 11, [80]);
            self::fail('Approval must reject a source mutation observed after the source lock boundary.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('operating target source changed', $e->getMessage());
        }

        self::assertTrue($service->sourceLockObservedInsideTransaction);
        self::assertSame(
            'pending_approval',
            (string)Db::name('operation_execution_intents')->where('id', $intentId)->value('status')
        );
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->count());
        self::assertSame(
            8000.0,
            (float)Db::name('operating_target_daily_records')
                ->where('id', (int)$record['id'])
                ->value('actual_revenue'),
            'The callback mutation belongs to the rejected approval transaction and must roll back.'
        );
    }

    private static function createSchema(): void
    {
        Db::execute(
            'CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT NOT NULL)'
        );
        Db::execute(
            'CREATE TABLE operating_target_daily_records ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, target_date TEXT, '
            . 'target_revenue REAL NULL, target_occupancy_rate_percent REAL NULL, target_revpar REAL NULL, '
            . 'actual_revenue REAL NULL, sold_room_nights INTEGER NULL, sellable_room_nights INTEGER NULL, '
            . 'fact_scope TEXT, source_type TEXT, source_reference TEXT NULL, quality_status TEXT, '
            . 'quality_reason TEXT NULL, fact_captured_at TEXT NULL, calculation_status TEXT, '
            . 'gap_codes_json TEXT NULL, calculation_json TEXT NULL, report_status TEXT, created_by INTEGER NULL, '
            . 'updated_by INTEGER NULL, create_time TEXT, update_time TEXT)'
        );
        Db::execute(
            'CREATE UNIQUE INDEX uq_operating_target_automation '
            . 'ON operating_target_daily_records (tenant_id, hotel_id, target_date)'
        );
        Db::execute(
            'CREATE TABLE operating_target_daily_snapshots ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, record_id INTEGER, tenant_id INTEGER, hotel_id INTEGER, '
            . 'target_date TEXT, revision_no INTEGER, change_reason TEXT NULL, snapshot_json TEXT, '
            . 'created_by INTEGER NULL, create_time TEXT)'
        );
        Db::execute(
            'CREATE TABLE operation_execution_intents ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, source_module TEXT, source_record_id INTEGER, '
            . 'hotel_id INTEGER, platform TEXT, object_type TEXT, action_type TEXT, date_start TEXT, date_end TEXT, '
            . 'current_value_json TEXT NULL, target_value_json TEXT NULL, evidence_json TEXT NULL, '
            . 'expected_metric TEXT, expected_delta REAL, risk_level TEXT, status TEXT, blocked_reason TEXT, '
            . 'review_remark TEXT, created_by INTEGER, approved_by INTEGER, approved_at TEXT NULL, '
            . 'created_at TEXT, updated_at TEXT, deleted_at TEXT NULL)'
        );
        Db::execute(
            'CREATE TABLE operation_execution_tasks ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, intent_id INTEGER, hotel_id INTEGER, execution_mode TEXT, '
            . 'operator_id INTEGER DEFAULT 0, target_value_json TEXT NULL, current_value_json TEXT NULL, '
            . 'blocked_reason TEXT DEFAULT \'\', action_track_id INTEGER DEFAULT 0, result_status TEXT DEFAULT \'observing\', '
            . 'result_summary TEXT DEFAULT \'\', status TEXT, executed_at TEXT NULL, created_at TEXT, updated_at TEXT, '
            . 'deleted_at TEXT NULL)'
        );
        Db::execute(
            'CREATE TABLE operation_execution_evidence ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, task_id INTEGER, evidence_type TEXT, before_json TEXT NULL, '
            . 'after_json TEXT NULL, attachment_path TEXT, platform_response_json TEXT NULL, remark TEXT, '
            . 'created_by INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT NULL)'
        );
    }
}

final class CapturingOperationManagementService extends OperationManagementService
{
    /** @var array<string,mixed> */
    public array $input = [];
    public string $idempotencyKey = '';

    public function __construct()
    {
    }

    public function createExecutionIntent(
        array $hotelIds,
        ?int $hotelId,
        array $input,
        int $createdBy,
        bool $trustedExpansionSource = false,
        ?string $trustedIdempotencyKey = null,
        bool $trustedReservedSource = false
    ): array {
        $this->input = $input;
        $this->idempotencyKey = (string)$trustedIdempotencyKey;
        return [
            'id' => 501,
            'hotel_id' => $hotelId,
            'status' => 'pending_approval',
            'source_module' => $input['source_module'],
            'source_record_id' => $input['source_record_id'],
            'idempotent_replay' => false,
        ];
    }
}

final class TargetApprovalInterleavingService extends OperationManagementService
{
    public bool $sourceLockObservedInsideTransaction = false;

    protected function afterOperatingTargetSourceLockedForApproval(array $intent, array $sourceRow): void
    {
        $this->sourceLockObservedInsideTransaction = Db::connect()->getPdo()->inTransaction();
        Db::name('operating_target_daily_records')
            ->where('id', (int)$sourceRow['id'])
            ->update([
                'actual_revenue' => 8500,
                'update_time' => '2026-07-28 09:01:00',
            ]);
    }
}
