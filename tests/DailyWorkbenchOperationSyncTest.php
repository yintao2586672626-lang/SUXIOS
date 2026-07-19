<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Agent;
use app\service\DailyWorkbenchPatrolService;
use app\service\OperationManagementService;
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
            'system_hotel_id' => 7,
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
            'system_hotel_id' => 7,
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
            'input' => ['project_name' => 'Scoped strategy source', 'source_evidence' => ['site_visit' => 'verified']],
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

        $second = $service->createExecutionIntent([7], 7, $input, 3, false, null, true);
        Db::name('strategy_simulation_records')->where('id', $recordId)->update([
            'recommendation_json' => json_encode([
                'decision' => 'pause',
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

    public function testSavedOtaDiagnosisReviewUsesLinkedNextDaySourceReadback(): void
    {
        $diagnosisDate = date('Y-m-d', time() - 172800);
        $baselineDate = date('Y-m-d', time() - 86400);
        $reviewDate = date('Y-m-d');
        $executedAt = $baselineDate . ' 12:00:00';
        $actionId = 'action-orders-1';
        $actionText = 'Optimize the Ctrip campaign and observe order lift.';
        $sourceRefs = ['online_daily_data#baseline'];
        $idempotencyKey = 'ota-diagnosis-test-key';

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
            'date_start' => $diagnosisDate,
            'date_end' => $diagnosisDate,
            'current_value_json' => '{}',
            'target_value_json' => json_encode(['action_text' => $actionText], JSON_UNESCAPED_UNICODE),
            'evidence_json' => json_encode([
                'action_index' => 0,
                'action_item_id' => $actionId,
                'action_idempotency_key' => $idempotencyKey,
                'evidence_refs' => $sourceRefs,
                'expected_delta_status' => 'quantified',
            ], JSON_UNESCAPED_UNICODE),
            'expected_metric' => 'orders',
            'expected_delta' => 2,
            'risk_level' => 'medium',
            'blocked_reason' => '',
            'status' => 'approved',
            'created_by' => 3,
            'created_at' => $executedAt,
            'updated_at' => $executedAt,
        ]);
        $diagnosisResult = [
            'hotel' => ['id' => 7],
            'platform' => 'ctrip',
            'date_range' => ['start_date' => $diagnosisDate, 'end_date' => $diagnosisDate],
            'decision_status' => 'action_required',
            'action_items' => [[
                'id' => $actionId,
                'action' => $actionText,
                'execution_ready' => true,
                'can_request_execution_intent' => true,
                'evidence_refs' => $sourceRefs,
                'execution_intent_id' => $intentId,
                'execution_idempotency_key' => $idempotencyKey,
            ]],
        ];
        Db::name('agent_logs')->where('id', $logId)->update([
            'context_data' => json_encode(['diagnosis_result' => $diagnosisResult], JSON_UNESCAPED_UNICODE),
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
        $this->insertTrustedOnlineOrders($baselineDate, 10, $baselineDate . ' 10:00:00');
        $this->insertTrustedOnlineOrders($reviewDate, 14, date('Y-m-d H:i:s'), 'realtime_snapshot', 0);

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
        $diagnosisResult['action_items'][0]['can_create_execution_intent'] = true;
        $diagnosisResult['action_items'][0]['decision_quality'] = [
            'contract_version' => \app\service\AiDecisionQualityService::CONTRACT_VERSION,
            'execution_ready' => true,
        ];
        Db::name('agent_logs')->where('id', $logId)->update([
            'context_data' => json_encode(['diagnosis_result' => $diagnosisResult], JSON_UNESCAPED_UNICODE),
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
            Db::name('online_daily_data')->where('data_date', $baselineDate)->select()->toArray(),
            'ctrip'
        );
        $reviewRows = $trustedRows->invoke(
            $service,
            Db::name('online_daily_data')->where('data_date', $reviewDate)->select()->toArray(),
            'ctrip',
            strtotime($executedAt)
        );
        self::assertCount(1, $baselineRows);
        $readbackTimestamp = new ReflectionMethod(OperationManagementService::class, 'executionReadbackRowTimestamp');
        $rawReviewRow = Db::name('online_daily_data')->where('data_date', $reviewDate)->find();
        self::assertCount(1, $reviewRows, json_encode([
            'readback_timestamp' => $readbackTimestamp->invoke($service, $rawReviewRow),
            'minimum_timestamp' => strtotime($executedAt),
            'row' => $rawReviewRow,
        ], JSON_UNESCAPED_UNICODE));
        $metricValue = new ReflectionMethod(OperationManagementService::class, 'executionReadbackMetricValue');
        self::assertSame(10.0, $metricValue->invoke($service, 'orders', $baselineRows, 7, $baselineDate));
        self::assertSame(14.0, $metricValue->invoke($service, 'orders', $reviewRows, 7, $reviewDate));
        $buildReadback = new ReflectionMethod(OperationManagementService::class, 'buildSourceVerifiedMetricReadbackPayload');
        self::assertNotNull(
            $buildReadback->invoke($service, $normalizedTask, $normalizedIntent),
            json_encode(Db::name('online_daily_data')->order('id')->select()->toArray(), JSON_UNESCAPED_UNICODE)
        );

        $reviewed = $service->reviewExecutionTask($taskId, [7], [
            'result_status' => 'success',
            'result_summary' => 'Next-day Ctrip orders were read back from persisted source facts.',
        ], 3);

        self::assertSame('success', $reviewed['result_status']);
        self::assertTrue($reviewed['evidence_truth']['source_verified']);
        $sourceEvidence = Db::name('operation_execution_evidence')
            ->where('task_id', $taskId)
            ->where('evidence_type', 'source_verified_metric_readback')
            ->where('created_by', 0)
            ->find();
        self::assertIsArray($sourceEvidence);
        self::assertSame(['orders' => 10], json_decode((string)$sourceEvidence['before_json'], true));
        self::assertSame(['orders' => 14], json_decode((string)$sourceEvidence['after_json'], true));
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
            'system_hotel_id' => 7,
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

    private static function createSchema(): void
    {
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)');
        Db::execute(<<<'SQL'
CREATE TABLE agent_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    hotel_id INTEGER NOT NULL,
    agent_type INTEGER NOT NULL,
    action TEXT NOT NULL,
    context_data TEXT
)
SQL);
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
        Db::execute(<<<'SQL'
CREATE TABLE online_daily_data (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    system_hotel_id INTEGER NOT NULL,
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
