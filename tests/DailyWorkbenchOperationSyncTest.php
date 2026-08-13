<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Agent;
use app\service\DailyWorkbenchPatrolService;
use app\service\OperationManagementService;
use app\service\QuantSimulationService;
use app\service\SimulationExecutionBridgeService;
use app\service\SimulationExecutionReadinessService;
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
    private string $patrolBaseDir = '';
    private string $patrolLatestPath = '';
    private bool $patrolLatestExisted = false;
    private string $patrolLatestContents = '';
    /** @var array<int, string> */
    private array $createdPatrolPaths = [];

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
        $this->patrolBaseDir = rtrim(runtime_path(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'phase2_daily_workbench_patrol';
        $this->patrolLatestPath = $this->patrolBaseDir . DIRECTORY_SEPARATOR . 'latest.json';
        $this->patrolLatestExisted = is_file($this->patrolLatestPath);
        $this->patrolLatestContents = $this->patrolLatestExisted
            ? (string)file_get_contents($this->patrolLatestPath)
            : '';
        $this->createdPatrolPaths = [];
        Db::name('online_daily_data')->delete(true);
        Db::name('agent_logs')->delete(true);
        Db::name('operation_effect_reviews')->delete(true);
        Db::name('operation_execution_evidence')->delete(true);
        Db::name('operation_execution_tasks')->delete(true);
        Db::name('operation_execution_intents')->delete(true);
        Db::name('strategy_simulation_records')->delete(true);
        Db::name('quant_simulation_records')->delete(true);
        Db::name('hotels')->delete(true);
        Db::name('hotels')->insert(['id' => 7, 'tenant_id' => 42]);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdPatrolPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
            $dir = dirname($path);
            if (is_dir($dir) && (glob($dir . DIRECTORY_SEPARATOR . '*') ?: []) === []) {
                rmdir($dir);
            }
        }
        if ($this->patrolLatestExisted) {
            if (!is_dir($this->patrolBaseDir)) {
                mkdir($this->patrolBaseDir, 0775, true);
            }
            file_put_contents($this->patrolLatestPath, $this->patrolLatestContents, LOCK_EX);
        } elseif (is_file($this->patrolLatestPath)) {
            unlink($this->patrolLatestPath);
        }
        parent::tearDown();
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
        self::assertSame('daily_workbench_patrol', $result['source_module']);
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
        self::assertSame('execute_task_and_attach_source_verified_business_metric_readback', $result['required_next_action']);
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

    public function testPositivePatrolReviewGeneratesAndReadsBackSystemVerifiedEvidence(): void
    {
        $snapshot = $this->writePatrolSnapshot();
        $runId = (string)$snapshot['run_id'];
        $intentId = $this->insertIntent('approved', $runId);
        $executedAt = date('Y-m-d H:i:s', time() - 3600);
        $taskId = (int)Db::name('operation_execution_tasks')->insertGetId([
            'intent_id' => $intentId,
            'hotel_id' => 7,
            'status' => 'executed',
            'result_status' => 'observing',
            'result_summary' => '',
            'executed_at' => $executedAt,
            'created_at' => $executedAt,
            'updated_at' => $executedAt,
        ]);
        Db::name('operation_execution_evidence')->insert([
            'task_id' => $taskId,
            'evidence_type' => 'manual_operation_execution',
            'before_json' => '{}',
            'after_json' => '{}',
            'platform_response_json' => json_encode([
                'mode' => 'manual_operation_execution',
                'completed_action' => 'Refreshed the target-date OTA evidence.',
            ], JSON_UNESCAPED_UNICODE),
            'remark' => 'operator execution receipt',
            'created_by' => 3,
            'created_at' => $executedAt,
            'updated_at' => $executedAt,
        ]);
        $sourceRecordId = (int)sprintf('%u', crc32($runId . '|7|refresh_ota_inventory|'));
        (new DailyWorkbenchPatrolService())->updateActionStatusForHotel([
            'run_id' => $runId,
            'hotel_id' => 7,
            'action_code' => 'refresh_ota_inventory',
            'question_key' => '',
            'status' => 'in_progress',
            'operation_execution' => [
                'source_record_id' => $sourceRecordId,
                'intent_id' => $intentId,
                'task_id' => $taskId,
                'task_status' => 'executed',
            ],
        ], 7, 3);
        Db::name('online_daily_data')->insert([
            'tenant_id' => 42,
            'system_hotel_id' => 7,
            'data_source_id' => 11,
            'hotel_id' => '130079194',
            'source' => 'ctrip',
            'platform' => 'ctrip',
            'compare_type' => 'self',
            'data_date' => '2026-07-17',
            'data_type' => 'business',
            'dimension' => '',
            'validation_status' => 'verified',
            'readback_verified' => 1,
            'ingestion_method' => 'browser_profile',
            'data_period' => 'historical_daily',
            'is_final' => 1,
            'snapshot_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ]);

        $reviewed = (new OperationManagementService())->reviewExecutionTask($taskId, [7], [
            'result_status' => 'success',
            'result_summary' => 'Target-date OTA evidence is now persisted and strictly readable.',
            'readback_evidence' => [
                'operator_attested' => true,
                'operator_attested_at' => date('Y-m-d H:i:s'),
                'source_ref' => 'screenshot#patrol-review',
            ],
        ], 3);

        self::assertSame('success', $reviewed['result_status']);
        self::assertTrue($reviewed['evidence_truth']['source_verified']);
        self::assertSame('verified', $reviewed['truth_context']['status']);
        self::assertContains('source_verified_metric_readback', $reviewed['evidence_summary']['types']);
        self::assertSame(1, (int)Db::name('operation_execution_evidence')
            ->where('task_id', $taskId)
            ->where('evidence_type', 'source_verified_metric_readback')
            ->where('created_by', 0)
            ->count());

        $evidenceCount = (int)Db::name('operation_execution_evidence')
            ->where('task_id', $taskId)
            ->count();
        $replayed = (new OperationManagementService())->reviewExecutionTask($taskId, [7], [
            'result_status' => 'success',
            'result_summary' => 'Target-date OTA evidence is now persisted and strictly readable.',
            'readback_evidence' => [
                'operator_attested' => true,
                'operator_attested_at' => date('Y-m-d H:i:s'),
                'source_ref' => 'screenshot#retry-after-runtime-write-failure',
            ],
        ], 3);
        self::assertSame('success', $replayed['result_status']);
        self::assertSame($evidenceCount, (int)Db::name('operation_execution_evidence')
            ->where('task_id', $taskId)
            ->count(), 'Exact terminal replay must not duplicate database evidence.');

        foreach ([
            ['result_status' => 'success', 'result_summary' => 'Conflicting summary.'],
            ['result_status' => 'failed', 'result_summary' => 'Target-date OTA evidence is now persisted and strictly readable.'],
        ] as $conflict) {
            try {
                (new OperationManagementService())->reviewExecutionTask($taskId, [7], $conflict, 3);
                self::fail('A conflicting terminal replay must remain rejected.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('cannot transition', $e->getMessage());
            }
        }
    }

    public function testFreshUpdateTimeCannotPromoteStaleCapturedFact(): void
    {
        $snapshot = $this->writePatrolSnapshot();
        $runId = (string)$snapshot['run_id'];
        $intentId = $this->insertIntent('approved', $runId);
        $executedAt = date('Y-m-d H:i:s', time() - 3600);
        $taskId = (int)Db::name('operation_execution_tasks')->insertGetId([
            'intent_id' => $intentId,
            'hotel_id' => 7,
            'status' => 'executed',
            'result_status' => 'observing',
            'result_summary' => '',
            'executed_at' => $executedAt,
            'created_at' => $executedAt,
            'updated_at' => $executedAt,
        ]);
        Db::name('operation_execution_evidence')->insert([
            'task_id' => $taskId,
            'evidence_type' => 'manual_operation_execution',
            'before_json' => '{}',
            'after_json' => '{}',
            'platform_response_json' => json_encode([
                'mode' => 'manual_operation_execution',
                'completed_action' => 'Refreshed the target-date OTA evidence.',
            ], JSON_UNESCAPED_UNICODE),
            'remark' => 'operator execution receipt',
            'created_by' => 3,
            'created_at' => $executedAt,
            'updated_at' => $executedAt,
        ]);
        $sourceRecordId = (int)sprintf('%u', crc32($runId . '|7|refresh_ota_inventory|'));
        (new DailyWorkbenchPatrolService())->updateActionStatusForHotel([
            'run_id' => $runId,
            'hotel_id' => 7,
            'action_code' => 'refresh_ota_inventory',
            'question_key' => '',
            'status' => 'in_progress',
            'operation_execution' => [
                'source_record_id' => $sourceRecordId,
                'intent_id' => $intentId,
                'task_id' => $taskId,
                'task_status' => 'executed',
            ],
        ], 7, 3);
        Db::name('online_daily_data')->insert([
            'tenant_id' => 42,
            'system_hotel_id' => 7,
            'data_source_id' => 11,
            'hotel_id' => '130079194',
            'source' => 'ctrip',
            'platform' => 'ctrip',
            'compare_type' => 'self',
            'data_date' => '2026-07-17',
            'data_type' => 'business',
            'dimension' => '',
            'validation_status' => 'verified',
            'readback_verified' => 1,
            'ingestion_method' => 'browser_profile',
            'data_period' => 'historical_daily',
            'is_final' => 1,
            'raw_data' => json_encode([
                'capture_evidence' => ['captured_at' => date('Y-m-d H:i:s', time() - 10800)],
            ], JSON_UNESCAPED_UNICODE),
            'update_time' => date('Y-m-d H:i:s'),
        ]);

        try {
            (new OperationManagementService())->reviewExecutionTask($taskId, [7], [
                'result_status' => 'success',
                'result_summary' => 'This stale source must not pass.',
            ], 3);
            self::fail('A fresh database update timestamp must not promote a stale captured fact.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('source-verified', strtolower($e->getMessage()));
        }
        self::assertSame(0, (int)Db::name('operation_execution_evidence')
            ->where('task_id', $taskId)
            ->where('evidence_type', 'source_verified_metric_readback')
            ->where('created_by', 0)
            ->count());
        self::assertSame('observing', Db::name('operation_execution_tasks')->where('id', $taskId)->value('result_status'));
    }

    public function testGenericIntentCannotClaimReservedProducerSource(): void
    {
        $service = new OperationManagementService();
        foreach ([
            'ai_daily_report',
            'revenue_research',
            'price_suggestion',
            'ota_diagnosis_saved',
            'ota_diagnosis',
            'strategy_simulation',
            'quant_simulation',
            'daily_workbench_patrol',
            'operation_alert',
        ] as $sourceModule) {
            try {
                $service->createExecutionIntent([7], 7, [
                    'source_module' => $sourceModule,
                    'source_record_id' => 99,
                    'hotel_id' => 7,
                    'platform' => 'ctrip',
                    'object_type' => 'data_collection',
                    'action_type' => 'refresh_ota_inventory',
                    'date_start' => '2026-07-17',
                    'date_end' => '2026-07-17',
                    'current_value' => [],
                    'target_value' => ['action_text' => 'refresh'],
                    'evidence' => ['evidence_refs' => ['source#99']],
                    'expected_metric' => 'ota_operation_closure',
                    'risk_level' => 'medium',
                    'status' => 'pending_approval',
                ], 3);
                self::fail($sourceModule . ' must be rejected by the generic intent entrypoint.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('reserved execution source', $e->getMessage());
            }
        }
        self::assertSame(0, (int)Db::name('operation_execution_intents')->count());

        $producer = new ReflectionMethod(OperationManagementService::class, 'buildSourceVerifiedMetricReadbackPayload');
        $payload = $producer->invoke($service, [
            'id' => 1,
            'intent_id' => 1,
            'executed_at' => date('Y-m-d H:i:s'),
        ], [
            'id' => 1,
            'source_module' => 'manual',
            'source_record_id' => 0,
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'object_type' => 'data_collection',
            'action_type' => 'refresh_ota_inventory',
            'date_start' => '2026-07-17',
            'date_end' => '2026-07-17',
            'current_value' => ['source' => 'daily_workbench_patrol'],
            'expected_metric' => 'ota_operation_closure',
        ]);
        self::assertNull($payload, 'current_value.source must never elevate a manual intent into a trusted patrol source.');
    }

    public function testAiReservedIntentApprovalRequiresPersistedDecisionQualityV2(): void
    {
        $legacyId = (int)Db::name('operation_execution_intents')->insertGetId([
            'source_module' => 'ai_daily_report',
            'source_record_id' => 91,
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'object_type' => 'campaign',
            'action_type' => 'promotion',
            'date_start' => '2026-07-17',
            'date_end' => '2026-07-17',
            'current_value_json' => '{}',
            'target_value_json' => json_encode(['campaign_type' => 'discount', 'target_metric' => 'orders'], JSON_UNESCAPED_UNICODE),
            'evidence_json' => '{}',
            'expected_metric' => 'orders',
            'expected_delta' => 0,
            'risk_level' => 'medium',
            'blocked_reason' => '',
            'status' => 'pending_approval',
            'created_by' => 3,
            'created_at' => '2026-07-17 10:00:00',
            'updated_at' => '2026-07-17 10:00:00',
        ]);

        $service = new OperationManagementService();
        try {
            $service->approveExecutionIntent($legacyId, true, 'approve legacy AI intent', 3, [7]);
            self::fail('Legacy AI intent without exact v2 provenance must not be approved.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('AI decision quality v2 provenance', $e->getMessage());
        }
        self::assertSame('pending_approval', Db::name('operation_execution_intents')->where('id', $legacyId)->value('status'));
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->where('intent_id', $legacyId)->count());

        $recommendation = [
            'title' => '复核携程收益研究动作',
            'action' => '在2026-07-17复核携程目标房型价格，并于7天后按渠道收入记录前后结果',
            'expected_metric' => 'ota_revenue',
            'can_create_execution_intent' => true,
            'decision_quality' => [
                'contract_version' => \app\service\AiDecisionQualityService::CONTRACT_VERSION,
                'execution_ready' => true,
            ],
        ];
        $current = $service->createExecutionIntent([7], 7, [
            'source_module' => 'revenue_research',
            'source_record_id' => 92,
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'object_type' => 'revenue_research',
            'action_type' => 'pricing_review',
            'date_start' => '2026-07-17',
            'date_end' => '2026-07-17',
            'target_value' => [
                'research_product' => 'pricing_review',
                'action_text' => $recommendation['action'],
                'target_metric' => 'revenue_research_closure',
            ],
            'evidence' => [
                'evidence_refs' => ['revenue_research#pricing_review#92'],
                'data_gaps' => [],
                'research_readiness_stage' => 'research_ready_for_execution',
                'execution_ready' => true,
                'metric_scope' => 'ota_channel',
                'decision_recommendation' => $recommendation,
            ],
            'expected_metric' => 'revenue_research_closure',
            'risk_level' => 'medium',
            'status' => 'pending_approval',
        ], 3, false, null, true);
        $approved = $service->approveExecutionIntent((int)$current['id'], true, 'approve current v2 intent', 3, [7]);
        self::assertSame('approved', $approved['status']);
        self::assertSame(1, (int)Db::name('operation_execution_tasks')->where('intent_id', (int)$current['id'])->count());
    }

    public function testSimulationAndPublicDiagnosisApprovalRevalidateScopedSource(): void
    {
        $service = new OperationManagementService();
        foreach (['strategy_simulation', 'quant_simulation', 'ota_diagnosis'] as $sourceModule) {
            $legacyId = (int)Db::name('operation_execution_intents')->insertGetId([
                'source_module' => $sourceModule,
                'source_record_id' => 999,
                'hotel_id' => 7,
                'platform' => $sourceModule === 'ota_diagnosis' ? 'ctrip' : 'investment',
                'object_type' => $sourceModule === 'ota_diagnosis' ? 'data_collection' : 'investment',
                'action_type' => $sourceModule === 'ota_diagnosis' ? 'complete_public_page_evidence' : 'strategy_review',
                'date_start' => '2026-07-17',
                'date_end' => '2026-07-17',
                'current_value_json' => '{}',
                'target_value_json' => '{}',
                'evidence_json' => '{}',
                'expected_metric' => $sourceModule === 'ota_diagnosis' ? 'public_page_verified_field_count' : 'strategy_simulation_closure',
                'expected_delta' => 0,
                'risk_level' => 'medium',
                'blocked_reason' => '',
                'status' => 'pending_approval',
                'created_by' => 3,
                'created_at' => '2026-07-17 10:00:00',
                'updated_at' => '2026-07-17 10:00:00',
            ]);
            try {
                $service->approveExecutionIntent($legacyId, true, 'must revalidate source', 3, [7]);
                self::fail($sourceModule . ' must not approve without its scoped source provenance.');
            } catch (\InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
            self::assertSame('pending_approval', Db::name('operation_execution_intents')->where('id', $legacyId)->value('status'));
            self::assertSame(0, (int)Db::name('operation_execution_tasks')->where('intent_id', $legacyId)->count());
        }

        $recordId = (int)Db::name('strategy_simulation_records')->insertGetId([
            'tenant_id' => 42,
            'project_name' => 'Scoped strategy source',
            'input_json' => json_encode([
                'project_name' => 'Scoped strategy source',
                'hotel_id' => 7,
                'source_evidence' => ['site_visit' => 'verified'],
            ], JSON_UNESCAPED_UNICODE),
            'data_snapshot_json' => json_encode([
                'local_data_used' => true,
                'source_summary' => ['daily_reports'],
            ], JSON_UNESCAPED_UNICODE),
            'score_json' => json_encode(['total_score' => 82, 'items' => []], JSON_UNESCAPED_UNICODE),
            'recommendation_json' => json_encode([
                'decision' => 'proceed to review',
                'decision_direction' => 'verify lease and competitor evidence',
            ], JSON_UNESCAPED_UNICODE),
            'risk_json' => json_encode(['risk_level' => 'medium'], JSON_UNESCAPED_UNICODE),
            'created_by' => 3,
            'created_at' => '2026-07-17 09:00:00',
            'updated_at' => '2026-07-17 09:00:00',
        ]);
        $record = [
            'id' => $recordId,
            'project_name' => 'Scoped strategy source',
            'total_score' => 82,
            'input' => ['project_name' => 'Scoped strategy source', 'hotel_id' => 7, 'source_evidence' => ['site_visit' => 'verified']],
            'scores' => [],
            'recommendation' => ['decision' => 'proceed to review', 'decision_direction' => 'verify lease and competitor evidence'],
            'risk' => ['risk_level' => 'medium'],
            'data_snapshot' => ['local_data_used' => true, 'source_summary' => ['daily_reports']],
        ];
        $input = (new SimulationExecutionReadinessService())->buildStrategyExecutionIntentInput($record, [
            'hotel_id' => 7,
            'date_start' => '2026-07-17',
            'date_end' => '2026-07-17',
        ]);
        $current = $service->createExecutionIntent([7], 7, $input, 3, false, null, true);
        $approved = $service->approveExecutionIntent((int)$current['id'], true, 'source unchanged', 3, [7]);
        self::assertSame('approved', $approved['status']);

        $replay = $service->createExecutionIntent([7], 7, $input, 3, false, null, true);
        self::assertSame((int)$current['id'], (int)$replay['id']);
        self::assertSame('approved', $replay['status']);
        self::assertTrue($replay['idempotent_replay']);

        Db::name('strategy_simulation_records')->where('id', $recordId)->update([
            'recommendation_json' => json_encode([
                'decision' => 'pause',
                'decision_direction' => 'review a changed source snapshot',
            ], JSON_UNESCAPED_UNICODE),
        ]);
        $record['recommendation'] = [
            'decision' => 'pause',
            'decision_direction' => 'review a changed source snapshot',
        ];
        $staleInput = (new SimulationExecutionReadinessService())->buildStrategyExecutionIntentInput($record, [
            'hotel_id' => 7,
            'date_start' => '2026-07-17',
            'date_end' => '2026-07-17',
        ]);
        $second = $service->createExecutionIntent([7], 7, $staleInput, 3, false, null, true);
        Db::name('strategy_simulation_records')->where('id', $recordId)->update([
            'recommendation_json' => json_encode([
                'decision' => 'stop',
                'decision_direction' => 'source changed after intent creation',
            ], JSON_UNESCAPED_UNICODE),
        ]);
        try {
            $service->approveExecutionIntent((int)$second['id'], true, 'stale source must fail', 3, [7]);
            self::fail('A changed simulation source must require a new intent.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('changed', strtolower($exception->getMessage()));
        }
        self::assertSame('pending_approval', Db::name('operation_execution_intents')->where('id', (int)$second['id'])->value('status'));
    }

    public function testStrategyAndQuantSimulationRejectOldTenantIntentsAndCreateFreshTenantIdentity(): void
    {
        foreach (['strategy_simulation', 'quant_simulation'] as $sourceModule) {
            $source = $this->insertReadySimulationSource($sourceModule, 42, 7);
            $service = new OperationManagementService();
            $input = $this->simulationExecutionInput($sourceModule, $source, 7);
            $old = $service->createExecutionIntent([7], 7, $input, 3, false, null, true);
            $oldKey = (string)Db::name('operation_execution_intents')
                ->where('id', (int)$old['id'])
                ->value('idempotency_key');

            Db::name('hotels')->where('id', 7)->update(['tenant_id' => 43]);
            Db::name($sourceModule . '_records')->where('id', (int)$source['id'])->update(['tenant_id' => 43]);
            try {
                $service->approveExecutionIntent((int)$old['id'], true, 'old tenant must fail', 3, [7]);
                self::fail($sourceModule . ' old-tenant intent must not be approved.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('tenant scope', $e->getMessage(), $sourceModule);
            }
            self::assertSame(
                0,
                (int)Db::name('operation_execution_tasks')->where('intent_id', (int)$old['id'])->count(),
                $sourceModule
            );
            self::assertSame([], $service->executionIntents([7], 7)['list'], $sourceModule . ' list scope');
            try {
                $service->readExecutionIntent((int)$old['id'], [7]);
                self::fail($sourceModule . ' old-tenant intent detail must be hidden.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('tenant scope', $e->getMessage(), $sourceModule);
            }

            $fresh = $service->createExecutionIntent([7], 7, $input, 3, false, null, true);
            self::assertNotSame((int)$old['id'], (int)$fresh['id'], $sourceModule);
            self::assertSame(43, (int)$fresh['tenant_id'], $sourceModule);
            self::assertNotSame(
                $oldKey,
                (string)Db::name('operation_execution_intents')
                    ->where('id', (int)$fresh['id'])
                    ->value('idempotency_key'),
                $sourceModule
            );
            self::assertNull($service->readExecutionIntentByIdempotencyKey($oldKey, [7]), $sourceModule);

            $approved = $service->approveExecutionIntent((int)$fresh['id'], true, 'current tenant', 3, [7]);
            self::assertSame('approved', $approved['status'], $sourceModule);
            self::assertSame(1, count($approved['tasks']), $sourceModule);

            Db::name('operation_execution_tasks')->delete(true);
            Db::name('operation_execution_intents')->delete(true);
            Db::name($sourceModule . '_records')->delete(true);
            Db::name('hotels')->where('id', 7)->update(['tenant_id' => 42]);
        }
    }

    public function testQuantTaskWritesRejectTenantTransferCommittedBySecondConnection(): void
    {
        $source = $this->insertReadySimulationSource('quant_simulation', 42, 7);
        $service = new OperationManagementService();
        $input = $this->simulationExecutionInput('quant_simulation', $source, 7);
        $intent = $service->createExecutionIntent([7], 7, $input, 3, false, null, true);
        $approved = $service->approveExecutionIntent((int)$intent['id'], true, 'current tenant', 3, [7]);
        $taskId = (int)($approved['tasks'][0]['id'] ?? 0);
        self::assertGreaterThan(0, $taskId);
        $beforeTask = Db::name('operation_execution_tasks')->where('id', $taskId)->find();
        self::assertIsArray($beforeTask);

        $transferConnection = new \PDO('sqlite:' . self::$sqlitePath);
        $transferConnection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $transferConnection->beginTransaction();
        $transferConnection->exec('UPDATE hotels SET tenant_id = 43 WHERE id = 7');
        $transferConnection->exec(
            'UPDATE quant_simulation_records SET tenant_id = 43 WHERE id = ' . (int)$source['id']
        );
        $transferConnection->commit();

        foreach ([
            fn(): array => $service->executeExecutionTask($taskId, [7], ['status' => 'executing'], 3),
            fn(): array => $service->addExecutionEvidence($taskId, [7], [
                'evidence_type' => 'manual_screenshot',
                'attachment_path' => '/runtime/evidence/quant-after-transfer.png',
            ], 3),
        ] as $write) {
            try {
                $write();
                self::fail('The transferred quant task must reject every old-tenant write.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('current tenant scope', $exception->getMessage());
            }
        }

        self::assertSame($beforeTask, Db::name('operation_execution_tasks')->where('id', $taskId)->find());
        self::assertSame(0, (int)Db::name('operation_execution_evidence')->where('task_id', $taskId)->count());
    }

    public function testSimulationDetailBridgeReplaysIntentButPersistedBusinessChangeStillRejectsApproval(): void
    {
        $operation = new OperationManagementService();
        $bridge = new SimulationExecutionBridgeService();

        foreach (['strategy_simulation', 'quant_simulation'] as $sourceModule) {
            $source = $this->insertReadySimulationSource($sourceModule, 42, 7);
            $initialInput = $this->simulationExecutionInput($sourceModule, $source, 7);
            $first = $operation->createExecutionIntent([7], 7, $initialInput, 3, false, null, true);

            $bridgedDetail = $bridge->attachToRecord($source, $sourceModule, [7]);
            self::assertSame((int)$first['id'], (int)$bridgedDetail['execution_intent_id'], $sourceModule);
            $bridgedInput = $this->simulationExecutionInput($sourceModule, $bridgedDetail, 7);
            self::assertSame(
                $initialInput['evidence']['source_record_digest'],
                $bridgedInput['evidence']['source_record_digest'],
                $sourceModule . ' source digest must ignore bridge tracking'
            );
            self::assertSame(
                $initialInput['evidence']['simulation_payload_digest'],
                $bridgedInput['evidence']['simulation_payload_digest'],
                $sourceModule . ' payload digest must ignore tracking-derived readiness'
            );

            $replay = $operation->createExecutionIntent([7], 7, $bridgedInput, 3, false, null, true);
            self::assertSame((int)$first['id'], (int)$replay['id'], $sourceModule);
            self::assertTrue($replay['idempotent_replay'], $sourceModule);
            self::assertSame(1, (int)Db::name('operation_execution_intents')
                ->where('source_module', $sourceModule)
                ->where('source_record_id', (int)$source['id'])
                ->count(), $sourceModule);

            if ($sourceModule === 'strategy_simulation') {
                $changedRecommendation = $source['recommendation'];
                $changedRecommendation['decision_direction'] = 'persisted business decision changed';
                Db::name('strategy_simulation_records')->where('id', (int)$source['id'])->update([
                    'recommendation_json' => json_encode($changedRecommendation, JSON_UNESCAPED_UNICODE),
                ]);
            } else {
                $changedResult = $source['result'];
                $changedResult['monthlyNetCashflow'] = (float)$changedResult['monthlyNetCashflow'] + 10000;
                Db::name('quant_simulation_records')->where('id', (int)$source['id'])->update([
                    'result_json' => json_encode($changedResult, JSON_UNESCAPED_UNICODE),
                ]);
            }

            try {
                $operation->approveExecutionIntent((int)$first['id'], true, 'stale simulation source', 3, [7]);
                self::fail($sourceModule . ' changed persisted business input must reject the old intent.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('changed', strtolower($exception->getMessage()), $sourceModule);
            }
        }
    }

    public function testQuantRealDetailReplaysAndApprovesWithoutDigestingTruthProjections(): void
    {
        $source = $this->insertReadySimulationSource('quant_simulation', 42, 7);
        $quant = new QuantSimulationService();
        $readiness = new SimulationExecutionReadinessService();
        $operation = new OperationManagementService();

        $detail = $quant->detail((int)$source['id'], 3, true);
        self::assertArrayHasKey('metric_truth', $detail['scenarios'][0]);
        $initialInput = $readiness->buildQuantExecutionIntentInput($detail, [
            'hotel_id' => 7,
            'date_start' => '2026-08-13',
            'date_end' => '2026-08-13',
        ]);
        $first = $operation->createExecutionIntent([7], 7, $initialInput, 3, false, null, true);

        $bridgedDetail = $quant->detail((int)$source['id'], 3, true);
        self::assertSame((int)$first['id'], (int)$bridgedDetail['execution_intent_id']);
        $projectionOnlyChange = $bridgedDetail;
        $projectionOnlyChange['scenarios'][0]['metric_truth']['monthlyNetCashflow']['display_value'] = 'projection changed';
        $retryInput = $readiness->buildQuantExecutionIntentInput($projectionOnlyChange, [
            'hotel_id' => 7,
            'date_start' => '2026-08-13',
            'date_end' => '2026-08-13',
        ]);
        self::assertSame(
            $initialInput['evidence']['source_record_digest'],
            $retryInput['evidence']['source_record_digest']
        );
        self::assertSame(
            $initialInput['evidence']['simulation_payload_digest'],
            $retryInput['evidence']['simulation_payload_digest']
        );

        $replay = $operation->createExecutionIntent([7], 7, $retryInput, 3, false, null, true);
        self::assertSame((int)$first['id'], (int)$replay['id']);
        self::assertTrue($replay['idempotent_replay']);
        self::assertSame(1, (int)Db::name('operation_execution_intents')->count());

        $approved = $operation->approveExecutionIntent((int)$first['id'], true, 'real detail unchanged', 3, [7]);
        self::assertSame('approved', $approved['status']);
    }

    public function testQuantPersistedScenarioBusinessChangeInvalidatesIntent(): void
    {
        $source = $this->insertReadySimulationSource('quant_simulation', 42, 7);
        $quant = new QuantSimulationService();
        $readiness = new SimulationExecutionReadinessService();
        $operation = new OperationManagementService();
        $detail = $quant->detail((int)$source['id'], 3, true);
        $input = $readiness->buildQuantExecutionIntentInput($detail, [
            'hotel_id' => 7,
            'date_start' => '2026-08-13',
            'date_end' => '2026-08-13',
        ]);
        $intent = $operation->createExecutionIntent([7], 7, $input, 3, false, null, true);

        $scenarios = $source['scenarios'];
        $scenarios[0]['adr'] = 288;
        $scenarios[0]['occupancyRate'] = 66;
        $scenarios[0]['monthlyNetCashflow'] = 88000;
        Db::name('quant_simulation_records')->where('id', (int)$source['id'])->update([
            'scenarios_json' => json_encode($scenarios, JSON_UNESCAPED_UNICODE),
        ]);

        try {
            $operation->approveExecutionIntent((int)$intent['id'], true, 'changed scenario', 3, [7]);
            self::fail('Changed persisted scenario ADR/OCC/cashflow must invalidate the old intent.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('changed', strtolower($exception->getMessage()));
        }
        self::assertSame('pending_approval', Db::name('operation_execution_intents')->where('id', (int)$intent['id'])->value('status'));
    }

    public function testQuantSimulationRejectsSameTenantRequestHotelAtCreationAndApproval(): void
    {
        Db::name('hotels')->insert(['id' => 8, 'tenant_id' => 42]);
        $source = $this->insertReadySimulationSource('quant_simulation', 42, 7);
        $input = $this->simulationExecutionInput('quant_simulation', $source, 7);
        $service = new OperationManagementService();

        $tampered = $input;
        $tampered['hotel_id'] = 8;
        try {
            $service->createExecutionIntent([8], 8, $tampered, 3, false, null, true);
            self::fail('The request hotel must not override the persisted quant source hotel.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('hotel scope', $e->getMessage());
        }

        $intent = $service->createExecutionIntent([7], 7, $input, 3, false, null, true);
        Db::name('operation_execution_intents')->where('id', (int)$intent['id'])->update(['hotel_id' => 8]);
        try {
            $service->approveExecutionIntent((int)$intent['id'], true, 'malicious same-tenant hotel', 3, [8]);
            self::fail('Approval must independently recheck the persisted quant source hotel.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('hotel scope', $e->getMessage());
        }
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->where('intent_id', (int)$intent['id'])->count());
    }

    public function testRefreshingDiagnosisRetainsAnyReferencedProvenance(): void
    {
        $context = [
            'platform' => 'ctrip',
            'record_status' => 'active',
            'requested_date_range' => [
                'start_date' => '2026-07-17',
                'end_date' => '2026-07-17',
            ],
            'diagnosis_result' => [
                'platform' => 'ctrip',
                'record_status' => 'active',
                'requested_date_range' => [
                    'start_date' => '2026-07-17',
                    'end_date' => '2026-07-17',
                ],
                'saved_record' => ['status' => 'active'],
            ],
        ];
        $oldLogId = (int)Db::name('agent_logs')->insertGetId([
            'hotel_id' => 7,
            'agent_type' => 2,
            'action' => 'ota_diagnosis',
            'context_data' => json_encode($context, JSON_UNESCAPED_UNICODE),
        ]);
        $newLogId = (int)Db::name('agent_logs')->insertGetId([
            'hotel_id' => 7,
            'agent_type' => 2,
            'action' => 'ota_diagnosis',
            'context_data' => json_encode($context, JSON_UNESCAPED_UNICODE),
        ]);
        $intentId = (int)Db::name('operation_execution_intents')->insertGetId([
            'source_module' => 'ota_diagnosis_saved',
            'source_record_id' => $oldLogId,
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'object_type' => 'campaign',
            'action_type' => 'listing_conversion_optimization',
            'current_value_json' => '{}',
            'target_value_json' => '{}',
            'evidence_json' => '{}',
            'expected_metric' => 'orders',
            'expected_delta' => 1,
            'risk_level' => 'medium',
            'blocked_reason' => '',
            'status' => 'executed',
            'created_by' => 3,
            'created_at' => '2026-07-17 10:00:00',
            'updated_at' => '2026-07-17 10:00:00',
        ]);

        $controller = (new \ReflectionClass(Agent::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(Agent::class, 'supersedePriorOtaDiagnosisRecords');
        $superseded = $method->invoke($controller, 7, 'ctrip', [
            'start_date' => '2026-07-17',
            'end_date' => '2026-07-17',
        ], $newLogId);
        self::assertSame(0, $superseded);
        $retained = json_decode((string)Db::name('agent_logs')->where('id', $oldLogId)->value('context_data'), true);
        self::assertSame('active', $retained['record_status'] ?? null);
        self::assertTrue((new OperationManagementService())->hasOtaDiagnosisExecutionReference(7, $oldLogId));

        Db::name('operation_execution_intents')->where('id', $intentId)->update([
            'deleted_at' => '2026-07-18 10:00:00',
        ]);
        $superseded = $method->invoke($controller, 7, 'ctrip', [
            'start_date' => '2026-07-17',
            'end_date' => '2026-07-17',
        ], $newLogId);
        self::assertSame(1, $superseded);
        $released = json_decode((string)Db::name('agent_logs')->where('id', $oldLogId)->value('context_data'), true);
        self::assertSame('superseded', $released['record_status'] ?? null);
    }

    public function testSavedOtaDiagnosisReviewUsesBusinessDateScheduledWindowAndCanonicalTrafficSnapshot(): void
    {
        $businessDate = date('Y-m-d', strtotime('-2 days'));
        $executedDate = $businessDate;
        $reviewDate = date('Y-m-d', strtotime('-1 day'));
        $executedAt = $executedDate . ' 23:30:00';
        $reviewAt = $reviewDate . ' 10:00:00';
        $actionId = 'action-orders-1';
        $actionText = 'Optimize the Ctrip campaign and observe order lift.';
        $idempotencyKey = 'ota-diagnosis-test-key';
        $approvalService = new OperationManagementService();
        $metricDefinition = (new ReflectionMethod(
            OperationManagementService::class,
            'savedOtaDiagnosisMetricDefinition'
        ))->invoke($approvalService, 'order_rate');
        $metricDefinitionDigest = (new ReflectionMethod(
            OperationManagementService::class,
            'savedOtaDiagnosisMetricDefinitionDigest'
        ))->invoke($approvalService, 'order_rate', $metricDefinition);
        $approvedAt = $businessDate . ' 11:00:00';

        $oldRealtimeId = $this->insertOnlineTrafficSnapshot(
            $businessDate,
            1098,
            194,
            1,
            $businessDate . ' 10:00:00',
            'realtime_snapshot',
            0
        );
        $newRealtimeId = $this->insertOnlineTrafficSnapshot(
            $businessDate,
            700,
            100,
            2,
            $businessDate . ' 23:00:00',
            'realtime_snapshot',
            0
        );
        $finalBaselineId = $this->insertOnlineTrafficSnapshot(
            $businessDate,
            338,
            64,
            1,
            $businessDate . ' 20:00:00',
            'historical_daily',
            1
        );
        $competitorId = $this->insertOnlineTrafficSnapshot(
            $businessDate,
            601,
            118,
            3,
            $businessDate . ' 21:00:00',
            'historical_daily',
            1,
            'competitor_avg'
        );
        $reviewRowId = $this->insertOnlineTrafficSnapshot(
            $reviewDate,
            300,
            60,
            3,
            $reviewDate . ' 12:00:00',
            'historical_daily',
            1
        );
        $sourceRefs = [
            'online_daily_data#' . $oldRealtimeId,
            'online_daily_data#' . $newRealtimeId,
            'online_daily_data#' . $finalBaselineId,
            'online_daily_data#' . $competitorId,
        ];
        $recommendation = [
            'id' => $actionId,
            'action' => $actionText,
            'action_type' => 'campaign_optimization',
            'expected_metric' => 'order_rate',
            'execution_ready' => true,
            'can_request_execution_intent' => true,
            'can_create_execution_intent' => true,
            'evidence_refs' => $sourceRefs,
            'decision_quality' => [
                'contract_version' => \app\service\AiDecisionQualityService::CONTRACT_VERSION,
                'execution_ready' => true,
            ],
        ];
        $recommendationDigest = (new ReflectionMethod(
            OperationManagementService::class,
            'decisionRecommendationDigest'
        ))->invoke($approvalService, $recommendation);

        $logId = (int)Db::name('agent_logs')->insertGetId([
            'hotel_id' => 7,
            'agent_type' => 2,
            'action' => 'ota_diagnosis',
            'context_data' => '{}',
        ]);
        $intentId = (int)Db::name('operation_execution_intents')->insertGetId([
            'source_module' => 'ota_diagnosis_saved',
            'source_record_id' => $logId,
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'object_type' => 'campaign',
            'action_type' => 'campaign_optimization',
            'date_start' => $businessDate,
            'date_end' => $businessDate,
            'current_value_json' => json_encode([
                'order_rate' => 0.52,
                'list_exposure' => 1098,
                'detail_exposure' => 194,
                'order_submit_num' => 1,
            ], JSON_UNESCAPED_UNICODE),
            'target_value_json' => json_encode([
                'target_metric' => 'order_rate',
                'action_text' => $actionText,
                'review_at' => $reviewAt,
                'workflow_schedule' => ['review_at' => $reviewAt],
                'target_type' => 'delta',
                'expected_direction' => 'increase',
                'expected_delta_status' => 'manual_confirmed',
                'metric_definition' => $metricDefinition,
                'metric_definition_digest' => $metricDefinitionDigest,
            ], JSON_UNESCAPED_UNICODE),
            'evidence_json' => json_encode([
                'action_index' => 0,
                'action_item_id' => $actionId,
                'action_idempotency_key' => $idempotencyKey,
                'evidence_refs' => $sourceRefs,
                'expected_delta_status' => 'manual_confirmed',
                'target_type' => 'delta',
                'expected_direction' => 'increase',
                'metric_definition' => $metricDefinition,
                'metric_definition_digest' => $metricDefinitionDigest,
                'decision_recommendation' => $recommendation,
                'decision_recommendation_digest' => $recommendationDigest,
            ], JSON_UNESCAPED_UNICODE),
            'expected_metric' => 'order_rate',
            'expected_delta' => 2,
            'risk_level' => 'medium',
            'blocked_reason' => '',
            'status' => 'approved',
            'created_by' => 3,
            'approved_by' => 3,
            'approved_at' => $approvedAt,
            'created_at' => $executedAt,
            'updated_at' => $executedAt,
        ]);
        $approvalContract = [
            'version' => 'ota_execution_approval_target.v1',
            'intent_id' => $intentId,
            'tenant_id' => 42,
            'hotel_id' => 7,
            'source_module' => 'ota_diagnosis_saved',
            'source_record_id' => $logId,
            'platform' => 'ctrip',
            'baseline_business_date' => $businessDate,
            'review_business_date' => $reviewDate,
            'expected_metric' => 'order_rate',
            'metric_definition' => $metricDefinition,
            'metric_definition_digest' => $metricDefinitionDigest,
            'expected_direction' => 'increase',
            'target_type' => 'delta',
            'target_value' => null,
            'expected_delta' => '2.000000',
            'expected_delta_status' => 'manual_confirmed',
            'approved_by' => 3,
            'approved_at' => $approvedAt,
            'diagnosis_recommendation_digest' => $recommendationDigest,
            'source_policy' => 'saved_diagnosis_metric_and_human_target_frozen_before_task_creation',
        ];
        $approvalTargetDigest = (new ReflectionMethod(
            OperationManagementService::class,
            'savedOtaDiagnosisApprovalTargetDigest'
        ))->invoke($approvalService, $approvalContract);
        $approvalContract['content_digest'] = $approvalTargetDigest;
        $targetValue = json_decode((string)Db::name('operation_execution_intents')
            ->where('id', $intentId)->value('target_value_json'), true);
        $intentEvidence = json_decode((string)Db::name('operation_execution_intents')
            ->where('id', $intentId)->value('evidence_json'), true);
        $targetValue['expected_delta'] = 2;
        $targetValue['review_business_date'] = $reviewDate;
        $targetValue['approval_target_digest'] = $approvalTargetDigest;
        $intentEvidence['target_type'] = 'delta';
        $intentEvidence['expected_direction'] = 'increase';
        $intentEvidence['expected_delta'] = 2;
        $intentEvidence['target_value'] = null;
        $intentEvidence['review_business_date'] = $reviewDate;
        $intentEvidence['approval_target'] = $approvalContract;
        $intentEvidence['approval_target_digest'] = $approvalTargetDigest;
        Db::name('operation_execution_intents')->where('id', $intentId)->update([
            'target_value_json' => json_encode($targetValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'evidence_json' => json_encode($intentEvidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $legacyAction = $recommendation;
        unset($legacyAction['can_create_execution_intent'], $legacyAction['decision_quality']);
        $legacyAction['execution_intent_id'] = $intentId;
        $legacyAction['execution_idempotency_key'] = $idempotencyKey;
        $readbackIdentityDigest = str_repeat('e', 64);
        $diagnosisResult = [
            'record_status' => 'active',
            'hotel' => ['id' => 7],
            'platform' => 'ctrip',
            'date_range' => ['start_date' => $businessDate, 'end_date' => $businessDate],
            'requested_date_range' => ['start_date' => $businessDate, 'end_date' => $businessDate],
            'decision_status' => 'action_required',
            'metrics' => ['order_rate' => 0.52],
            'saved_record' => [
                'saved' => true,
                'readback_verified' => true,
                'id' => $logId,
                'readback_identity_digest' => $readbackIdentityDigest,
            ],
            'action_items' => [$legacyAction],
        ];
        Db::name('agent_logs')->where('id', $logId)->update([
            'context_data' => json_encode([
                'record_status' => 'active',
                'readback_identity_digest' => $readbackIdentityDigest,
                'diagnosis_result' => $diagnosisResult,
            ], JSON_UNESCAPED_UNICODE),
        ]);
        $taskId = (int)Db::name('operation_execution_tasks')->insertGetId([
            'intent_id' => $intentId,
            'hotel_id' => 7,
            'status' => 'executed',
            'result_status' => 'observing',
            'result_summary' => '',
            'executed_at' => $executedAt,
            'created_at' => $executedAt,
            'updated_at' => $executedAt,
        ]);
        Db::name('operation_execution_evidence')->insert([
            'task_id' => $taskId,
            'evidence_type' => 'manual_operation_execution',
            'before_json' => '{}',
            'after_json' => '{}',
            'platform_response_json' => json_encode(['completed_action' => $actionText], JSON_UNESCAPED_UNICODE),
            'remark' => 'operator execution receipt',
            'created_by' => 3,
            'created_at' => $executedAt,
            'updated_at' => $executedAt,
        ]);
        $originalCurrentValueJson = (string)Db::name('operation_execution_intents')
            ->where('id', $intentId)
            ->value('current_value_json');

        $service = new OperationManagementService();
        $normalizeIntent = new ReflectionMethod(OperationManagementService::class, 'normalizeExecutionIntentRow');
        $normalizedIntent = $normalizeIntent->invoke(
            $service,
            Db::name('operation_execution_intents')->where('id', $intentId)->find()
        );
        $verifyProvenance = new ReflectionMethod(OperationManagementService::class, 'hasVerifiedOtaDiagnosisProvenance');
        self::assertFalse(
            $verifyProvenance->invoke($service, $normalizedIntent),
            'Legacy execution flags must not establish verified OTA diagnosis provenance.'
        );
        $diagnosisResult['action_items'][0] = $recommendation + [
            'execution_intent_id' => $intentId,
            'execution_idempotency_key' => $idempotencyKey,
        ];
        Db::name('agent_logs')->where('id', $logId)->update([
            'context_data' => json_encode([
                'record_status' => 'active',
                'readback_identity_digest' => $readbackIdentityDigest,
                'diagnosis_result' => $diagnosisResult,
            ], JSON_UNESCAPED_UNICODE),
        ]);
        self::assertTrue(
            $verifyProvenance->invoke($service, $normalizedIntent),
            json_encode(['intent' => $normalizedIntent, 'context' => Db::name('agent_logs')->where('id', $logId)->value('context_data')], JSON_UNESCAPED_UNICODE)
        );
        $normalizeTask = new ReflectionMethod(OperationManagementService::class, 'normalizeExecutionTaskRow');
        $normalizedTask = $normalizeTask->invoke(
            $service,
            Db::name('operation_execution_tasks')->where('id', $taskId)->find()
        );
        $trustedRows = new ReflectionMethod(OperationManagementService::class, 'trustedExecutionReadbackRows');
        $baselineRows = $trustedRows->invoke(
            $service,
            Db::name('online_daily_data')->where('data_date', $businessDate)->select()->toArray(),
            'ctrip'
        );
        $reviewRows = $trustedRows->invoke(
            $service,
            Db::name('online_daily_data')->where('data_date', $reviewDate)->select()->toArray(),
            'ctrip',
            strtotime($executedAt)
        );
        self::assertCount(1, $baselineRows);
        self::assertSame($finalBaselineId, (int)$baselineRows[0]['id']);
        $readbackTimestamp = new ReflectionMethod(OperationManagementService::class, 'executionReadbackRowTimestamp');
        $rawReviewRow = Db::name('online_daily_data')->where('data_date', $reviewDate)->find();
        self::assertCount(1, $reviewRows, json_encode([
            'readback_timestamp' => $readbackTimestamp->invoke($service, $rawReviewRow),
            'minimum_timestamp' => strtotime($executedAt),
            'row' => $rawReviewRow,
        ], JSON_UNESCAPED_UNICODE));
        $metricValue = new ReflectionMethod(OperationManagementService::class, 'executionReadbackMetricValue');
        self::assertSame(1.56, $metricValue->invoke($service, 'order_rate', $baselineRows, 7, $businessDate));
        self::assertSame(5.0, $metricValue->invoke($service, 'order_rate', $reviewRows, 7, $reviewDate));

        $canonicalRows = new ReflectionMethod(OperationManagementService::class, 'canonicalExecutionReadbackRows');
        $canonicalBaseline = $canonicalRows->invoke(
            $service,
            Db::name('online_daily_data')
                ->where('data_date', $businessDate)
                ->where('compare_type', 'self')
                ->select()
                ->toArray(),
            'order_rate'
        );
        self::assertCount(1, $canonicalBaseline);
        self::assertSame($finalBaselineId, (int)$canonicalBaseline[0]['id']);

        $buildReadback = new ReflectionMethod(OperationManagementService::class, 'buildSourceVerifiedMetricReadbackPayload');
        $payload = $buildReadback->invoke($service, $normalizedTask, $normalizedIntent);
        self::assertNotNull($payload, json_encode(
            Db::name('online_daily_data')->order('id')->select()->toArray(),
            JSON_UNESCAPED_UNICODE
        ));
        self::assertSame(['order_rate' => 1.56], $payload['before']);
        self::assertSame(['order_rate' => 5.0], $payload['after']);
        self::assertSame($businessDate, $payload['platform_response']['baseline_date']);
        self::assertSame($reviewDate, $payload['platform_response']['review_date']);
        self::assertSame($reviewAt, $payload['platform_response']['scheduled_review_at']);
        self::assertSame(
            'online_daily_data#' . $finalBaselineId,
            $payload['platform_response']['baseline_source_ref']
        );
        self::assertSame(
            'online_daily_data#' . $reviewRowId,
            $payload['platform_response']['followup_source_ref']
        );
        self::assertSame(
            'online_daily_data#' . $finalBaselineId . ',' . $reviewRowId,
            $payload['platform_response']['source_ref']
        );
        self::assertSame(
            'source_readback_supersedes_intent_snapshot',
            $payload['platform_response']['baseline_reconciliation_status']
        );
        self::assertSame(0.52, $payload['platform_response']['intent_snapshot_value']);
        self::assertSame(1.56, $payload['platform_response']['source_readback_value']);
        self::assertSame('declared_refs_match', $payload['platform_response']['baseline_source_reference_status']);
        self::assertSame([], $payload['platform_response']['newly_verified_baseline_source_refs']);
        self::assertSame(
            ['online_daily_data#' . $finalBaselineId],
            $payload['platform_response']['reconciled_baseline_source_refs']
        );
        self::assertTrue($payload['platform_response']['original_intent_evidence_preserved']);
        self::assertFalse($payload['platform_response']['historical_intent_mutated']);
        self::assertFalse($payload['platform_response']['causality_claimed']);
        self::assertNotContains(
            'online_daily_data#' . $finalBaselineId,
            $payload['platform_response']['excluded_declared_source_refs']
        );
        self::assertContains(
            'online_daily_data#' . $oldRealtimeId,
            $payload['platform_response']['excluded_declared_source_refs']
        );
        self::assertContains(
            'online_daily_data#' . $competitorId,
            $payload['platform_response']['excluded_declared_source_refs']
        );

        $reconciledRefs = [
            'online_daily_data#' . $oldRealtimeId,
            'online_daily_data#' . $newRealtimeId,
            'online_daily_data#' . $competitorId,
        ];
        $reconciledIntent = $normalizedIntent;
        $reconciledIntent['evidence']['evidence_refs'] = $reconciledRefs;
        $reconciledRecommendation = $recommendation;
        $reconciledRecommendation['evidence_refs'] = $reconciledRefs;
        $reconciledRecommendationDigest = (new ReflectionMethod(
            OperationManagementService::class,
            'decisionRecommendationDigest'
        ))->invoke($approvalService, $reconciledRecommendation);
        $reconciledIntent['evidence']['decision_recommendation'] = $reconciledRecommendation;
        $reconciledIntent['evidence']['decision_recommendation_digest'] = $reconciledRecommendationDigest;
        $diagnosisResult['action_items'][0] = $reconciledRecommendation + [
            'execution_intent_id' => $intentId,
            'execution_idempotency_key' => $idempotencyKey,
        ];
        Db::name('agent_logs')->where('id', $logId)->update([
            'context_data' => json_encode([
                'record_status' => 'active',
                'readback_identity_digest' => $readbackIdentityDigest,
                'diagnosis_result' => $diagnosisResult,
            ], JSON_UNESCAPED_UNICODE),
        ]);
        $reconciledPayload = $buildReadback->invoke($service, $normalizedTask, $reconciledIntent);
        self::assertNotNull($reconciledPayload);
        self::assertSame(['order_rate' => 1.56], $reconciledPayload['before']);
        self::assertSame(
            'trusted_same_scope_reconciliation',
            $reconciledPayload['platform_response']['baseline_source_reference_status']
        );
        self::assertSame(
            ['online_daily_data#' . $finalBaselineId],
            $reconciledPayload['platform_response']['newly_verified_baseline_source_refs']
        );
        self::assertTrue($reconciledPayload['platform_response']['original_intent_evidence_preserved']);
        self::assertFalse($reconciledPayload['platform_response']['historical_intent_mutated']);

        $diagnosisResult['action_items'][0] = $recommendation + [
            'execution_intent_id' => $intentId,
            'execution_idempotency_key' => $idempotencyKey,
        ];
        Db::name('agent_logs')->where('id', $logId)->update([
            'context_data' => json_encode([
                'record_status' => 'active',
                'readback_identity_digest' => $readbackIdentityDigest,
                'diagnosis_result' => $diagnosisResult,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $fallbackIntent = $normalizedIntent;
        unset($fallbackIntent['target_value']['workflow_schedule']);
        $fallbackPayload = $buildReadback->invoke($service, $normalizedTask, $fallbackIntent);
        self::assertSame($reviewDate, $fallbackPayload['platform_response']['review_date']);

        $reconciled = $service->reconcileScheduledExecutionTask($taskId, [7]);
        self::assertSame('source_readback_verified', $reconciled['status']);
        self::assertTrue($reconciled['source_verified']);
        self::assertSame('observing', $reconciled['result_status']);
        self::assertSame('human_confirm_review_result', $reconciled['next_action']);
        self::assertSame(1, (int)Db::name('operation_execution_evidence')
            ->where('task_id', $taskId)
            ->where('evidence_type', 'source_verified_metric_readback')
            ->where('created_by', 0)
            ->count());

        $reviewed = $service->reviewExecutionTask($taskId, [7], [
            'result_status' => 'success',
            'result_summary' => 'Scheduled Ctrip order rate was read back from the same persisted fact scope.',
        ], 3);

        self::assertSame('success', $reviewed['result_status']);
        self::assertTrue($reviewed['evidence_truth']['source_verified']);
        self::assertCount(1, $reviewed['execution_evidence']);
        self::assertCount(1, $reviewed['effect_source_evidence']);
        self::assertCount(1, $reviewed['effect_reviews']);
        self::assertSame(1, $reviewed['effect_review_summary']['verified_count']);
        self::assertSame('readback_verified', $reviewed['effect_review_summary']['persistence_status']);
        self::assertSame(1, (int)Db::name('operation_effect_reviews')->where('task_id', $taskId)->count());
        self::assertSame('candidate', $reviewed['sop_candidate']['status']);
        self::assertSame('pending_approval', $reviewed['sop_candidate']['approval_status']);
        self::assertFalse($reviewed['sop_candidate']['boundaries']['automatic_publish_enabled']);
        self::assertFalse($reviewed['sop_candidate']['boundaries']['cross_hotel_replication_allowed']);
        $sourceEvidence = Db::name('operation_execution_evidence')
            ->where('task_id', $taskId)
            ->where('evidence_type', 'source_verified_metric_readback')
            ->where('created_by', 0)
            ->find();
        self::assertIsArray($sourceEvidence);
        self::assertSame(['order_rate' => 1.56], json_decode((string)$sourceEvidence['before_json'], true));
        self::assertSame(['order_rate' => 5], json_decode((string)$sourceEvidence['after_json'], true));
        $savedResponse = json_decode((string)$sourceEvidence['platform_response_json'], true);
        self::assertSame(
            'source_readback_supersedes_intent_snapshot',
            $savedResponse['baseline_reconciliation_status'] ?? null
        );
        self::assertSame($originalCurrentValueJson, (string)Db::name('operation_execution_intents')
            ->where('id', $intentId)
            ->value('current_value_json'));
    }

    public function testSavedOtaDiagnosisFailedReviewCannotCloseWithoutScheduledSourceReadback(): void
    {
        $businessDate = date('Y-m-d', strtotime('-3 days'));
        $executedAt = date('Y-m-d', strtotime('-2 days')) . ' 12:00:00';
        $intentId = (int)Db::name('operation_execution_intents')->insertGetId([
            'source_module' => 'ota_diagnosis_saved',
            'source_record_id' => 999,
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'object_type' => 'campaign',
            'action_type' => 'booking_conversion_optimization',
            'date_start' => $businessDate,
            'date_end' => $businessDate,
            'current_value_json' => json_encode(['order_rate' => 1.56], JSON_UNESCAPED_UNICODE),
            'target_value_json' => json_encode([
                'review_at' => date('Y-m-d', strtotime('-1 day')) . ' 10:00:00',
            ], JSON_UNESCAPED_UNICODE),
            'evidence_json' => json_encode([
                'evidence_refs' => ['online_daily_data#999'],
                'expected_delta_status' => 'quantified',
            ], JSON_UNESCAPED_UNICODE),
            'expected_metric' => 'order_rate',
            'expected_delta' => 1,
            'risk_level' => 'medium',
            'blocked_reason' => '',
            'status' => 'approved',
            'created_by' => 3,
            'created_at' => $executedAt,
            'updated_at' => $executedAt,
        ]);
        $taskId = (int)Db::name('operation_execution_tasks')->insertGetId([
            'intent_id' => $intentId,
            'hotel_id' => 7,
            'status' => 'executed',
            'result_status' => 'observing',
            'result_summary' => '',
            'executed_at' => $executedAt,
            'created_at' => $executedAt,
            'updated_at' => $executedAt,
        ]);
        Db::name('operation_execution_evidence')->insert([
            'task_id' => $taskId,
            'evidence_type' => 'manual_operation_execution',
            'before_json' => '{}',
            'after_json' => '{}',
            'platform_response_json' => json_encode([
                'mode' => 'manual_operation_execution',
                'completed_action' => 'Manual conversion check completed.',
            ], JSON_UNESCAPED_UNICODE),
            'remark' => 'operator execution receipt only',
            'created_by' => 3,
            'created_at' => $executedAt,
            'updated_at' => $executedAt,
        ]);

        $reconciled = (new OperationManagementService())->reconcileScheduledExecutionTask($taskId, [7]);
        self::assertSame('source_readback_missing', $reconciled['status']);
        self::assertFalse($reconciled['source_verified']);
        self::assertSame('observing', $reconciled['result_status']);

        try {
            (new OperationManagementService())->reviewExecutionTask($taskId, [7], [
                'result_status' => 'failed',
                'result_summary' => 'Conversion did not improve.',
            ], 3);
            self::fail('A failed diagnosis review must still require scheduled source readback.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('same-metric scheduled OTA readback', $exception->getMessage());
        }

        self::assertSame('observing', (string)Db::name('operation_execution_tasks')
            ->where('id', $taskId)
            ->value('result_status'));
        self::assertSame(0, (int)Db::name('operation_execution_evidence')
            ->where('task_id', $taskId)
            ->where('evidence_type', 'source_verified_metric_readback')
            ->where('created_by', 0)
            ->count());
    }

    public function testSavedOtaDiagnosisReviewLockUsesExactApprovedTime(): void
    {
        $businessDate = date('Y-m-d', strtotime('-2 days'));
        $executedAt = date('Y-m-d', strtotime('-1 day')) . ' 12:00:00';
        $reviewDate = date('Y-m-d', strtotime('+1 day'));
        $reviewAt = $reviewDate . ' 10:00:00';
        $intentId = (int)Db::name('operation_execution_intents')->insertGetId([
            'source_module' => 'ota_diagnosis_saved',
            'source_record_id' => 1001,
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'object_type' => 'campaign',
            'action_type' => 'booking_conversion_optimization',
            'date_start' => $businessDate,
            'date_end' => $businessDate,
            'current_value_json' => json_encode(['order_rate' => 1.56], JSON_UNESCAPED_UNICODE),
            'target_value_json' => json_encode([
                'review_at' => $reviewAt,
                'workflow_schedule' => ['review_at' => $reviewAt],
            ], JSON_UNESCAPED_UNICODE),
            'evidence_json' => json_encode([
                'evidence_refs' => ['online_daily_data#1001'],
                'expected_delta_status' => 'quantified',
            ], JSON_UNESCAPED_UNICODE),
            'expected_metric' => 'order_rate',
            'expected_delta' => 1,
            'risk_level' => 'medium',
            'blocked_reason' => '',
            'status' => 'approved',
            'created_by' => 3,
            'created_at' => $executedAt,
            'updated_at' => $executedAt,
        ]);
        $taskId = (int)Db::name('operation_execution_tasks')->insertGetId([
            'intent_id' => $intentId,
            'hotel_id' => 7,
            'status' => 'executed',
            'result_status' => 'observing',
            'result_summary' => '',
            'executed_at' => $executedAt,
            'created_at' => $executedAt,
            'updated_at' => $executedAt,
        ]);
        Db::name('operation_execution_evidence')->insert([
            'task_id' => $taskId,
            'evidence_type' => 'manual_operation_execution',
            'before_json' => '{}',
            'after_json' => '{}',
            'platform_response_json' => json_encode([
                'mode' => 'manual_operation_execution',
                'completed_action' => 'Manual conversion check completed.',
                'next_review_date' => $reviewDate,
            ], JSON_UNESCAPED_UNICODE),
            'remark' => 'operator execution receipt only',
            'created_by' => 3,
            'created_at' => $executedAt,
            'updated_at' => $executedAt,
        ]);

        $service = new OperationManagementService();
        $task = $service->readExecutionTask($taskId, [7]);
        self::assertSame($reviewAt, $task['review_available_at']);
        self::assertSame($reviewDate, $task['review_available_on']);
        self::assertFalse($task['review_is_available']);
        self::assertSame('not_ready', $task['sop_candidate']['status']);
        self::assertContains('operator_review_pending', $task['sop_candidate']['reason_codes']);
        self::assertContains('source_verified_metric_readback_missing', $task['sop_candidate']['reason_codes']);
        self::assertNotContains('operator_execution_evidence_missing', $task['sop_candidate']['reason_codes']);

        try {
            $service->reconcileScheduledExecutionTask($taskId, [7]);
            self::fail('Scheduled readback must remain locked until the exact approved time.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString($reviewAt, $exception->getMessage());
        }

        try {
            $service->reviewExecutionTask($taskId, [7], [
                'result_status' => 'observing',
                'result_summary' => 'Too early for the scheduled review window.',
            ], 3);
            self::fail('Review must remain locked until the exact approved time.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString($reviewAt, $exception->getMessage());
        }
        self::assertSame('observing', (string)Db::name('operation_execution_tasks')
            ->where('id', $taskId)
            ->value('result_status'));
    }

    public function testMixedCaseReservedSourcesCannotBypassGenericCreationAndScopedCreationStoresCanonicalSource(): void
    {
        $service = new OperationManagementService();
        foreach ([' Opening ', 'TRANSFER_DECISION', 'Feasibility_Report', 'Strategy_Simulation', 'Quant_Simulation'] as $sourceModule) {
            try {
                $service->createExecutionIntent([7], 7, [
                    'source_module' => $sourceModule, 'source_record_id' => 91, 'hotel_id' => 7,
                    'platform' => 'internal', 'object_type' => 'test', 'action_type' => 'track',
                    'date_start' => '2026-08-13', 'date_end' => '2026-08-13',
                    'current_value' => [], 'target_value' => [], 'evidence' => [],
                    'expected_metric' => 'closure', 'status' => 'pending_approval',
                ], 3);
                self::fail($sourceModule . ' must not bypass its scoped source endpoint.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('reserved execution source', $exception->getMessage());
            }
        }

        $source = $this->insertReadySimulationSource('strategy_simulation', 42, 7);
        $input = $this->simulationExecutionInput('strategy_simulation', $source, 7);
        $input['source_module'] = ' Strategy_Simulation ';
        $intent = $service->createExecutionIntent([7], 7, $input, 3, false, null, true);

        self::assertSame('strategy_simulation', $intent['source_module']);
        self::assertSame('strategy_simulation', Db::name('operation_execution_intents')->where('id', (int)$intent['id'])->value('source_module'));
    }

    public function testMixedCaseLegacySimulationIntentRevalidatesSourceBeforeApprovalAndTaskMutation(): void
    {
        $service = new OperationManagementService();
        $staleApprovalSource = $this->insertReadySimulationSource('strategy_simulation', 42, 7);
        $approvalIntent = $service->createExecutionIntent(
            [7], 7, $this->simulationExecutionInput('strategy_simulation', $staleApprovalSource, 7), 3, false, null, true
        );
        Db::name('operation_execution_intents')->where('id', (int)$approvalIntent['id'])->update(['source_module' => ' Strategy_Simulation ']);
        $changedRecommendation = $staleApprovalSource['recommendation'];
        $changedRecommendation['decision_direction'] = 'changed after legacy write';
        Db::name('strategy_simulation_records')->where('id', (int)$staleApprovalSource['id'])->update([
            'recommendation_json' => json_encode($changedRecommendation, JSON_UNESCAPED_UNICODE),
        ]);
        try {
            $service->approveExecutionIntent((int)$approvalIntent['id'], true, 'must reject drift', 3, [7]);
            self::fail('Mixed-case legacy source must still reject approval after source drift.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('changed', strtolower($exception->getMessage()));
        }
        self::assertSame('pending_approval', Db::name('operation_execution_intents')->where('id', (int)$approvalIntent['id'])->value('status'));

        $mutationSource = $this->insertReadySimulationSource('quant_simulation', 42, 7);
        $mutationIntent = $service->createExecutionIntent(
            [7], 7, $this->simulationExecutionInput('quant_simulation', $mutationSource, 7), 3, false, null, true
        );
        Db::name('operation_execution_intents')->where('id', (int)$mutationIntent['id'])->update(['source_module' => ' QUANT_SIMULATION ']);
        $approved = $service->approveExecutionIntent((int)$mutationIntent['id'], true, 'legacy source unchanged', 3, [7]);
        $taskId = (int)($approved['tasks'][0]['id'] ?? 0);
        self::assertGreaterThan(0, $taskId);
        $beforeTask = Db::name('operation_execution_tasks')->where('id', $taskId)->find();
        $changedResult = $mutationSource['result'];
        $changedResult['monthlyNetCashflow'] = (float)$changedResult['monthlyNetCashflow'] + 1;
        Db::name('quant_simulation_records')->where('id', (int)$mutationSource['id'])->update([
            'result_json' => json_encode($changedResult, JSON_UNESCAPED_UNICODE),
        ]);
        try {
            $service->executeExecutionTask($taskId, [7], [], 3);
            self::fail('Mixed-case legacy source must still reject task mutation after source drift.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('changed', strtolower($exception->getMessage()));
        }
        self::assertSame($beforeTask, Db::name('operation_execution_tasks')->where('id', $taskId)->find());
    }

    public function testLatestOtaAttemptScopesCurrentTenantBeforeOrderAndLimit(): void
    {
        $service = new OperationManagementService();
        $baseKey = 'ota_diagnosis_action_' . str_repeat('b', 32);
        $currentIntentId = $this->insertRawExecutionIntent(42, $baseKey . ':attempt:1000');
        for ($attempt = 1; $attempt <= 105; $attempt++) {
            $this->insertRawExecutionIntent(42, $baseKey . ':attempt:' . $attempt);
        }
        Db::name('hotels')->where('id', 7)->update(['tenant_id' => 99]);
        $this->insertRawExecutionIntent(99, $baseKey . ':attempt:2000');
        Db::name('hotels')->where('id', 7)->update(['tenant_id' => 42]);

        $latest = $service->readLatestOtaDiagnosisExecutionIntentAttempt($baseKey, [7]);

        self::assertIsArray($latest);
        self::assertSame(1000, $latest['attempt']);
        self::assertSame($currentIntentId, (int)$latest['intent']['id']);
        self::assertSame(42, (int)$latest['intent']['tenant_id']);
    }

    public function testExecutionIntentListReportsCurrentTenantTruncation(): void
    {
        for ($row = 1; $row <= 105; $row++) {
            $this->insertRawExecutionIntent(42, null, 'manual', 2000 + $row);
        }

        $result = (new OperationManagementService())->executionIntents([7], 7);

        self::assertSame(105, $result['matched_total']);
        self::assertSame(100, $result['returned_count']);
        self::assertTrue($result['truncated']);
        self::assertSame('partial', $result['data_status']);
        self::assertFalse($result['statistics']['execution_total_loaded']);
        self::assertSame('operation_execution_intents_truncated', $result['data_gaps'][0]['code']);
    }

    public function testExecutionReadsFailClosedWhenTenantScopeSchemaIsMissing(): void
    {
        $service = new OperationManagementService();
        Db::execute('ALTER TABLE operation_execution_intents DROP COLUMN tenant_id');
        try {
            $list = $service->executionIntents([7], 7);
            $flow = $service->executionFlow([7], 7);
            foreach ([$list, $flow] as $result) {
                self::assertSame([], $result['list']);
                self::assertSame('migration_required', $result['data_status']);
                self::assertSame(0, $result['matched_total']);
                self::assertSame(0, $result['returned_count']);
                self::assertFalse($result['truncated']);
                self::assertFalse($result['statistics']['execution_total_loaded']);
                self::assertSame('operation_execution_intents_tenant_id_missing', $result['data_gaps'][0]['code']);
            }
            self::assertFalse($flow['statistics']['task_status_loaded']);
            self::assertFalse($flow['statistics']['evidence_loaded']);
            self::assertFalse($flow['statistics']['roi_loaded']);
        } finally {
            Db::execute('ALTER TABLE operation_execution_intents ADD COLUMN tenant_id INTEGER NOT NULL DEFAULT 42');
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

    private function insertIntent(string $status, string $runId = 'patrol-run-20260717'): int
    {
        $sourceRecordId = (int)sprintf('%u', crc32($runId . '|7|refresh_ota_inventory|'));
        return (int)Db::name('operation_execution_intents')->insertGetId([
            'source_module' => 'daily_workbench_patrol',
            'source_record_id' => $sourceRecordId,
            'hotel_id' => 7,
            'platform' => 'ctrip',
            'object_type' => 'data_collection',
            'action_type' => 'refresh_ota_inventory',
            'date_start' => '2026-07-17',
            'date_end' => '2026-07-17',
            'current_value_json' => '{}',
            'target_value_json' => json_encode(['question_key' => '', 'action_text' => 'Refresh OTA evidence.'], JSON_UNESCAPED_UNICODE),
            'evidence_json' => json_encode([
                'evidence_refs' => ['daily_workbench_patrol#' . $runId],
                'source_policy' => 'read_existing_daily_workbench_patrol_snapshot_only',
            ], JSON_UNESCAPED_UNICODE),
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

    private function insertRawExecutionIntent(
        int $tenantId,
        ?string $idempotencyKey,
        string $sourceModule = 'ota_diagnosis',
        int $sourceRecordId = 91
    ): int {
        return (int)Db::name('operation_execution_intents')->insertGetId([
            'tenant_id' => $tenantId, 'idempotency_key' => $idempotencyKey,
            'source_module' => $sourceModule, 'source_record_id' => $sourceRecordId, 'hotel_id' => 7,
            'platform' => 'ctrip', 'object_type' => 'data_collection', 'action_type' => 'collect',
            'date_start' => '2026-08-13', 'date_end' => '2026-08-13',
            'current_value_json' => '{}', 'target_value_json' => '{}', 'evidence_json' => '{}',
            'expected_metric' => 'closure', 'expected_delta' => 0, 'risk_level' => 'medium',
            'blocked_reason' => '', 'status' => 'pending_approval', 'created_by' => 3,
            'created_at' => '2026-08-13 09:00:00', 'updated_at' => '2026-08-13 09:00:00',
        ]);
    }

    /** @return array<string, mixed> */
    private function writePatrolSnapshot(): array
    {
        $snapshot = (new DailyWorkbenchPatrolService())->write([
            'scope' => [
                'target_date' => '2026-07-17',
                'hotel_id' => 7,
                'requested_hotel_limit' => 1,
            ],
            'summary' => ['next_action_count' => 1],
            'rows' => [['hotel_id' => 7]],
            'next_actions' => [[
                'hotel_id' => 7,
                'action_code' => 'refresh_ota_inventory',
                'question_key' => '',
                'action' => 'Refresh OTA evidence.',
            ]],
        ], ['trigger_type' => 'test', 'user_id' => 3]);
        $path = $this->patrolBaseDir
            . DIRECTORY_SEPARATOR . '20260717'
            . DIRECTORY_SEPARATOR . (string)$snapshot['run_id'] . '.json';
        $this->createdPatrolPaths[] = $path;
        return $snapshot;
    }

    private function insertTrustedOnlineOrders(
        string $date,
        int $orders,
        string $capturedAt,
        string $dataPeriod = 'historical_daily',
        int $isFinal = 1
    ): void
    {
        Db::name('online_daily_data')->insert([
            'tenant_id' => 42,
            'system_hotel_id' => 7,
            'data_source_id' => 11,
            'hotel_id' => '130079194',
            'source' => 'ctrip',
            'platform' => 'ctrip',
            'compare_type' => 'self',
            'data_date' => $date,
            'data_type' => 'business',
            'dimension' => '',
            'validation_status' => 'verified',
            'readback_verified' => 1,
            'ingestion_method' => 'browser_profile',
            'data_period' => $dataPeriod,
            'is_final' => $isFinal,
            'raw_data' => json_encode([
                'orders' => $orders,
                'capture_evidence' => ['captured_at' => $capturedAt],
            ], JSON_UNESCAPED_UNICODE),
            'update_time' => $capturedAt,
        ]);
    }

    private function insertOnlineTrafficSnapshot(
        string $date,
        int $exposure,
        int $visitors,
        int $orders,
        string $capturedAt,
        string $dataPeriod,
        int $isFinal,
        string $compareType = 'self'
    ): int {
        return (int)Db::name('online_daily_data')->insertGetId([
            'tenant_id' => 42,
            'system_hotel_id' => 7,
            'data_source_id' => 11,
            'hotel_id' => $compareType === 'self' ? '130079194' : 'competitor-average',
            'source' => 'ctrip',
            'platform' => 'ctrip',
            'compare_type' => $compareType,
            'data_date' => $date,
            'data_type' => 'traffic',
            'dimension' => 'catalog:ctrip:business_flow_transform',
            'validation_status' => 'verified',
            'readback_verified' => 1,
            'ingestion_method' => 'browser_profile',
            'data_period' => $dataPeriod,
            'is_final' => $isFinal,
            'snapshot_time' => $capturedAt,
            'raw_data' => json_encode([
                'list_exposure' => $exposure,
                'detail_exposure' => $visitors,
                'order_filling_num' => $orders,
                'order_submit_num' => $orders,
                'capture_evidence' => ['captured_at' => $capturedAt],
            ], JSON_UNESCAPED_UNICODE),
            'update_time' => $capturedAt,
        ]);
    }

    /** @return array<string, mixed> */
    private function insertReadySimulationSource(string $sourceModule, int $tenantId, int $hotelId): array
    {
        if ($sourceModule === 'strategy_simulation') {
            $id = (int)Db::name('strategy_simulation_records')->insertGetId([
                'tenant_id' => $tenantId,
                'project_name' => 'Tenant strategy source',
                'input_json' => json_encode([
                    'project_name' => 'Tenant strategy source',
                    'hotel_id' => $hotelId,
                    'system_hotel_id' => $hotelId,
                    'source_evidence' => ['site_visit' => 'verified'],
                    'review_status' => 'approved',
                ], JSON_UNESCAPED_UNICODE),
                'data_snapshot_json' => json_encode([
                    'target_hotel_id' => $hotelId,
                    'local_data_used' => true,
                    'source_summary' => ['daily_reports'],
                ], JSON_UNESCAPED_UNICODE),
                'score_json' => json_encode(['total_score' => 82, 'items' => []], JSON_UNESCAPED_UNICODE),
                'recommendation_json' => json_encode([
                    'decision' => 'proceed',
                    'decision_direction' => 'verify current source',
                ], JSON_UNESCAPED_UNICODE),
                'risk_json' => json_encode(['risk_level' => 'medium'], JSON_UNESCAPED_UNICODE),
                'created_by' => 3,
                'created_at' => '2026-08-13 09:00:00',
                'updated_at' => '2026-08-13 09:00:00',
            ]);

            return [
                'id' => $id,
                'project_name' => 'Tenant strategy source',
                'input' => [
                    'project_name' => 'Tenant strategy source',
                    'hotel_id' => $hotelId,
                    'system_hotel_id' => $hotelId,
                    'source_evidence' => ['site_visit' => 'verified'],
                    'review_status' => 'approved',
                ],
                'scores' => ['total_score' => 82, 'items' => []],
                'recommendation' => ['decision' => 'proceed', 'decision_direction' => 'verify current source'],
                'risk' => ['risk_level' => 'medium'],
                'data_snapshot' => [
                    'target_hotel_id' => $hotelId,
                    'local_data_used' => true,
                    'source_summary' => ['daily_reports'],
                ],
            ];
        }

        $input = [
            'hotel_id' => $hotelId,
            'system_hotel_id' => $hotelId,
            'roomCount' => 80,
            'adr' => 320,
            'occupancyRate' => 78,
            'monthlyRent' => 120000,
            'laborCost' => 45000,
            'utilityCost' => 12000,
            'otaCommissionRate' => 12,
            'consumableCost' => 8000,
            'maintenanceCost' => 6000,
            'otherFixedCost' => 5000,
            'decorationInvestment' => 3200000,
            'furnitureInvestment' => 800000,
            'openingCost' => 300000,
            'otherInvestment' => 200000,
            'source_evidence' => ['daily_report' => 'verified'],
            'review_status' => 'approved',
        ];
        $result = [
            'monthlyRevenue' => 620000,
            'monthlyNetCashflow' => 210000,
            'paybackMonths' => 22.1,
            'riskLevel' => 'medium',
        ];
        $scenarios = [
            ['name' => 'conservative'],
            ['name' => 'base'],
            ['name' => 'optimistic'],
        ];
        $id = (int)Db::name('quant_simulation_records')->insertGetId([
            'tenant_id' => $tenantId,
            'project_name' => 'Tenant quant source',
            'input_json' => json_encode($input, JSON_UNESCAPED_UNICODE),
            'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE),
            'scenarios_json' => json_encode($scenarios, JSON_UNESCAPED_UNICODE),
            'risk_hints_json' => '[]',
            'monthly_net_cashflow' => 210000,
            'payback_months' => 22.1,
            'risk_level' => 'medium',
            'created_by' => 3,
            'created_at' => '2026-08-13 09:00:00',
            'updated_at' => '2026-08-13 09:00:00',
        ]);

        return [
            'id' => $id,
            'project_name' => 'Tenant quant source',
            'input' => $input,
            'result' => $result,
            'scenarios' => $scenarios,
            'risk_hints' => [],
        ];
    }

    /** @param array<string, mixed> $source @return array<string, mixed> */
    private function simulationExecutionInput(string $sourceModule, array $source, int $hotelId): array
    {
        $service = new SimulationExecutionReadinessService();
        $overrides = [
            'hotel_id' => $hotelId,
            'date_start' => '2026-08-13',
            'date_end' => '2026-08-13',
        ];

        return $sourceModule === 'strategy_simulation'
            ? $service->buildStrategyExecutionIntentInput($source, $overrides)
            : $service->buildQuantExecutionIntentInput($source, $overrides);
    }

    private static function createSchema(): void
    {
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)');
        Db::execute(<<<'SQL'
CREATE TABLE agent_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL DEFAULT 42,
    hotel_id INTEGER NOT NULL,
    agent_type INTEGER NOT NULL,
    action TEXT NOT NULL,
    context_data TEXT
)
SQL);
        Db::execute(<<<'SQL'
CREATE TABLE operation_execution_intents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL DEFAULT 42,
    source_module TEXT NOT NULL,
    source_record_id INTEGER NOT NULL,
    idempotency_key TEXT UNIQUE,
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
    expected_delta REAL DEFAULT NULL,
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
    tenant_id INTEGER NOT NULL DEFAULT 42,
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
    tenant_id INTEGER NOT NULL DEFAULT 42,
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
CREATE TABLE operation_effect_reviews (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    hotel_id INTEGER NOT NULL,
    intent_id INTEGER NOT NULL,
    task_id INTEGER NOT NULL,
    platform TEXT NOT NULL,
    baseline_business_date TEXT NOT NULL,
    review_business_date TEXT NOT NULL,
    metric_key TEXT NOT NULL,
    metric_definition_json TEXT NOT NULL,
    metric_definition_digest TEXT NOT NULL,
    approval_target_digest TEXT NOT NULL,
    before_value REAL NOT NULL,
    after_value REAL NOT NULL,
    expected_direction TEXT NOT NULL,
    target_type TEXT NOT NULL,
    target_value REAL,
    expected_delta REAL,
    expected_delta_status TEXT NOT NULL,
    target_confirmed_by INTEGER NOT NULL,
    target_confirmed_at TEXT NOT NULL,
    baseline_refs_json TEXT NOT NULL,
    followup_refs_json TEXT NOT NULL,
    source_readback_evidence_id INTEGER NOT NULL,
    outcome_status TEXT NOT NULL,
    outcome_json TEXT NOT NULL,
    result_status TEXT NOT NULL,
    result_summary TEXT NOT NULL,
    causality_claimed INTEGER NOT NULL,
    reviewed_by INTEGER NOT NULL,
    reviewed_at TEXT NOT NULL,
    content_digest TEXT NOT NULL,
    created_at TEXT NOT NULL,
    UNIQUE (tenant_id, hotel_id, task_id, content_digest)
)
SQL);
        Db::execute(<<<'SQL'
CREATE TABLE online_daily_data (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    system_hotel_id INTEGER NOT NULL,
    data_source_id INTEGER,
    hotel_id TEXT,
    source TEXT,
    platform TEXT,
    compare_type TEXT,
    data_date TEXT,
    data_type TEXT,
    dimension TEXT,
    validation_status TEXT,
    readback_verified INTEGER NOT NULL DEFAULT 0,
    ingestion_method TEXT,
    data_period TEXT,
    is_final INTEGER NOT NULL DEFAULT 0,
    snapshot_time TEXT,
    collected_at TEXT,
    received_at TEXT,
    raw_data TEXT,
    update_time TEXT,
    status TEXT,
    save_status TEXT
)
SQL);
        Db::execute(<<<'SQL'
CREATE TABLE strategy_simulation_records (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER,
    project_name TEXT NOT NULL,
    input_json TEXT,
    data_snapshot_json TEXT,
    score_json TEXT,
    recommendation_json TEXT,
    risk_json TEXT,
    created_by INTEGER NOT NULL DEFAULT 0,
    created_at TEXT,
    updated_at TEXT,
    deleted_at TEXT
)
SQL);
        Db::execute(<<<'SQL'
CREATE TABLE quant_simulation_records (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER,
    project_name TEXT NOT NULL,
    input_json TEXT,
    result_json TEXT,
    scenarios_json TEXT,
    risk_hints_json TEXT,
    monthly_net_cashflow REAL,
    payback_months REAL,
    risk_level TEXT,
    created_by INTEGER NOT NULL DEFAULT 0,
    created_at TEXT,
    updated_at TEXT,
    deleted_at TEXT
)
SQL);
    }
}
