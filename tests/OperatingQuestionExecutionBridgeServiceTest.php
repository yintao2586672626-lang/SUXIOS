<?php
declare(strict_types=1);

namespace Tests;

use app\service\LlmClient;
use app\service\OperatingQuestionAiAnswerService;
use app\service\OperatingQuestionExecutionBridgeService;
use app\service\OperatingQuestionService;
use app\service\OperationManagementService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OperatingQuestionExecutionBridgeServiceTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App(dirname(__DIR__));
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'operating_question_execution_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';
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
            'hotel_operating_question_model_responses',
            'hotel_operating_questions',
            'online_daily_data',
            'hotels',
        ] as $table) {
            Db::execute('DROP TABLE IF EXISTS ' . $table);
        }
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT, status INTEGER NOT NULL)');
        Db::execute("INSERT INTO hotels (id,tenant_id,name,status) VALUES (20,10,'target',1),(30,11,'other tenant',1)");
        Db::execute(
            'CREATE TABLE hotel_operating_questions ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, request_key TEXT, question_text TEXT, '
            . 'platform TEXT, date_start TEXT, date_end TEXT, answer_status TEXT, answer_summary TEXT, answer_json TEXT, '
            . 'fact_refs_json TEXT, memory_refs_json TEXT, knowledge_refs_json TEXT, execution_refs_json TEXT, data_gaps_json TEXT, '
            . 'content_digest TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT, '
            . 'UNIQUE(tenant_id,hotel_id,request_key))'
        );
        Db::execute(
            'CREATE TABLE hotel_operating_question_model_responses ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, provider_response_id TEXT COLLATE BINARY NOT NULL UNIQUE, '
            . 'provider TEXT NOT NULL, question_id INTEGER NOT NULL UNIQUE, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, '
            . 'question_content_digest TEXT NOT NULL, created_at TEXT NOT NULL)'
        );
        Db::execute(
            'CREATE TABLE operation_execution_intents ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, idempotency_key TEXT UNIQUE, source_module TEXT, '
            . 'source_record_id INTEGER, hotel_id INTEGER, platform TEXT, object_type TEXT, action_type TEXT, date_start TEXT, '
            . 'date_end TEXT, current_value_json TEXT, target_value_json TEXT, evidence_json TEXT, expected_metric TEXT, '
            . 'expected_delta REAL, risk_level TEXT, status TEXT, blocked_reason TEXT, review_remark TEXT, created_by INTEGER, '
            . 'approved_by INTEGER DEFAULT 0, approved_at TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)'
        );
        Db::execute(
            'CREATE TABLE operation_execution_tasks ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, intent_id INTEGER, hotel_id INTEGER, execution_mode TEXT, '
            . 'operator_id INTEGER DEFAULT 0, target_value_json TEXT, current_value_json TEXT, blocked_reason TEXT DEFAULT "", '
            . 'action_track_id INTEGER DEFAULT 0, result_status TEXT DEFAULT "observing", result_summary TEXT DEFAULT "", '
            . 'status TEXT, executed_at TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)'
        );
        Db::execute(
            'CREATE TABLE operation_execution_evidence ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, task_id INTEGER, evidence_type TEXT, before_json TEXT, '
            . 'after_json TEXT, attachment_path TEXT, platform_response_json TEXT, remark TEXT, created_by INTEGER, '
            . 'created_at TEXT, updated_at TEXT, deleted_at TEXT)'
        );
        Db::execute(
            'CREATE TABLE online_daily_data ('
            . 'id INTEGER PRIMARY KEY, tenant_id INTEGER, system_hotel_id INTEGER, data_date TEXT, platform TEXT, source TEXT, '
            . 'data_type TEXT, dimension TEXT, validation_status TEXT, history_status TEXT, readback_verified INTEGER, '
            . 'readback_verified_at TEXT, ingestion_method TEXT, source_trace_id TEXT, list_exposure REAL, '
            . 'detail_exposure REAL, raw_data TEXT)'
        );
        Db::name('online_daily_data')->insert([
            'id' => 1201,
            'tenant_id' => 10,
            'system_hotel_id' => 20,
            'data_date' => '2026-08-12',
            'platform' => 'ctrip',
            'source' => 'ctrip',
            'data_type' => 'traffic',
            'dimension' => '',
            'validation_status' => 'verified',
            'history_status' => 'success',
            'readback_verified' => 1,
            'readback_verified_at' => '2026-08-12 10:00:00',
            'ingestion_method' => 'browser_profile',
            'source_trace_id' => 'execution-bridge-test',
            'list_exposure' => 1800,
            'detail_exposure' => 360,
            'raw_data' => json_encode([
                'field_facts' => [[
                    'metric_key' => 'list_exposure',
                    'data_type' => 'traffic',
                    'source_key' => 'listExposure',
                    'status' => 'captured',
                    'stored_value_present' => true,
                    'source_path' => 'traffic.listExposure',
                    'storage_field' => 'online_daily_data.list_exposure',
                ], [
                    'metric_key' => 'detail_exposure',
                    'data_type' => 'traffic',
                    'source_key' => 'detailExposure',
                    'status' => 'captured',
                    'stored_value_present' => true,
                    'source_path' => 'traffic.detailExposure',
                    'storage_field' => 'online_daily_data.detail_exposure',
                ]],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function testVerifiedActionCreatesOnePendingApprovalAndApprovalCreatesOnlyManualTask(): void
    {
        $questionService = $this->readyQuestionService();
        $saved = $questionService->create(
            10,
            20,
            '2026-08-12 携程列表曝光用户数应复核什么？',
            'ctrip',
            '2026-08-12',
            '2026-08-12',
            7
        );
        $questionId = (int)$saved['question']['id'];
        $bridge = new OperatingQuestionExecutionBridgeService(
            $questionService,
            new OperationManagementService()
        );

        $created = $bridge->createIntent($questionId, 0, 10, [20], 7);
        $intent = $created['execution_intent'];
        self::assertSame('pending_approval', $intent['status']);
        self::assertSame('operating_question', $intent['source_module']);
        self::assertSame($questionId, $intent['source_record_id']);
        self::assertSame(20, $intent['hotel_id']);
        self::assertSame('ctrip', $intent['platform']);
        self::assertSame('operation_checklist', $intent['object_type']);
        self::assertSame('human_reviewed_operating_check', $intent['action_type']);
        self::assertSame('list_exposure', $intent['expected_metric']);
        self::assertSame([], $intent['tasks']);
        self::assertFalse($created['reused_existing_intent']);
        self::assertFalse($intent['evidence']['boundaries']['automatic_execution']);
        self::assertFalse($intent['evidence']['boundaries']['ota_write']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $intent['evidence']['claims_digest']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $intent['evidence']['basis_claims_digest']);
        self::assertCount(1, $intent['evidence']['basis_claim_ids']);
        self::assertStringStartsWith('claim-', $intent['evidence']['basis_claim_ids'][0]);

        $replayed = $bridge->createIntent($questionId, 0, 10, [20], 7);
        self::assertTrue($replayed['reused_existing_intent']);
        self::assertSame($intent['id'], $replayed['execution_intent']['id']);
        self::assertSame(1, (int)Db::name('operation_execution_intents')->count());

        $approved = (new OperationManagementService())->approveExecutionIntent(
            (int)$intent['id'],
            true,
            '人工确认后进入本地执行池',
            8,
            [20]
        );
        self::assertSame('approved', $approved['status']);
        self::assertCount(1, $approved['tasks']);
        self::assertSame('manual', $approved['tasks'][0]['execution_mode']);
        self::assertSame('pending_execute', $approved['tasks'][0]['status']);
        self::assertSame(1, (int)Db::name('operation_execution_tasks')->count());

        try {
            $bridge->createIntent($questionId, 0, 10, [20], 7);
            self::fail('an approved intent must not replay as a new pending-approval draft');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('已经结束审批', $exception->getMessage());
        }

        $rejectedQuestion = $questionService->create(
            10,
            20,
            '2026-08-12 携程列表曝光用户数还应复核什么？',
            'ctrip',
            '2026-08-12',
            '2026-08-12',
            7
        );
        $rejectedIntent = $bridge->createIntent((int)$rejectedQuestion['question']['id'], 0, 10, [20], 7);
        $rejected = (new OperationManagementService())->approveExecutionIntent(
            (int)$rejectedIntent['execution_intent']['id'],
            false,
            '人工驳回测试草案',
            8,
            [20]
        );
        self::assertSame('rejected', $rejected['status']);
        try {
            $bridge->createIntent((int)$rejectedQuestion['question']['id'], 0, 10, [20], 7);
            self::fail('a rejected intent must not replay as a new pending-approval draft');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('已经结束审批', $exception->getMessage());
        }
    }

    public function testMissingFactsAndSourceDriftNeverCreateOrApproveAnIntent(): void
    {
        $missingService = new OperatingQuestionService(static fn(): array => []);
        $blocked = $missingService->create(
            10,
            20,
            '缺事实时能创建任务吗？',
            'ctrip',
            '2026-08-12',
            '2026-08-12',
            7
        );
        $bridge = new OperatingQuestionExecutionBridgeService(
            $missingService,
            new OperationManagementService()
        );
        try {
            $bridge->createIntent((int)$blocked['question']['id'], 0, 10, [20], 7);
            self::fail('missing facts must not create an execution intent');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('行动草案', $exception->getMessage());
        }
        self::assertSame(0, (int)Db::name('operation_execution_intents')->count());

        $questionService = $this->readyQuestionService();
        $saved = $questionService->create(
            10,
            20,
            '2026-08-12 携程列表曝光用户数应复核什么？',
            'ctrip',
            '2026-08-12',
            '2026-08-12',
            7
        );
        $created = (new OperatingQuestionExecutionBridgeService(
            $questionService,
            new OperationManagementService()
        ))->createIntent((int)$saved['question']['id'], 0, 10, [20], 7);
        Db::name('hotel_operating_questions')
            ->where('id', (int)$saved['question']['id'])
            ->update(['content_digest' => str_repeat('0', 64)]);

        $this->expectException(\InvalidArgumentException::class);
        (new OperationManagementService())->approveExecutionIntent(
            (int)$created['execution_intent']['id'],
            true,
            '来源漂移后不应批准',
            8,
            [20]
        );
    }

    public function testReservedOperatingQuestionSourceCannotUseGenericCreate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reserved execution source');
        (new OperationManagementService())->createExecutionIntent([20], 20, [
            'source_module' => 'operating_question',
            'source_record_id' => 99,
            'hotel_id' => 20,
            'platform' => 'ctrip',
            'object_type' => 'operation_checklist',
            'action_type' => 'human_reviewed_operating_check',
            'date_start' => '2026-08-12',
            'date_end' => '2026-08-12',
            'target_value' => [
                'title' => '伪造来源',
                'action_text' => '不应创建',
                'steps' => ['停止'],
                'acceptance_criteria' => ['不落库'],
            ],
            'evidence' => ['evidence_refs' => ['online_daily_data#1']],
        ], 7);
    }

    public function testLowConfidenceAnswerCannotCreateAnIntentEvenWithACompleteAction(): void
    {
        $questionService = $this->readyQuestionService('low');
        $saved = $questionService->create(
            10,
            20,
            '2026-08-12 携程列表曝光用户数应复核什么？',
            'ctrip',
            '2026-08-12',
            '2026-08-12',
            7
        );
        self::assertSame('answered_by_grounded_ai', $saved['question']['answer_status']);
        self::assertSame('low', $saved['question']['answer']['confidence']);
        self::assertSame([], $saved['question']['answer']['action_drafts']);

        try {
            (new OperatingQuestionExecutionBridgeService(
                $questionService,
                new OperationManagementService()
            ))->createIntent((int)$saved['question']['id'], 0, 10, [20], 7);
            self::fail('low-confidence answers must not create an execution intent');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('行动草案', $exception->getMessage());
        }
        self::assertSame(0, (int)Db::name('operation_execution_intents')->count());
    }

    public function testApprovalRevalidatesCurrentFactStatusAndMetricValue(): void
    {
        $questionService = $this->readyQuestionService();
        $bridge = new OperatingQuestionExecutionBridgeService(
            $questionService,
            new OperationManagementService()
        );
        $first = $questionService->create(
            10,
            20,
            '2026-08-12 携程列表曝光用户数应复核什么？',
            'ctrip',
            '2026-08-12',
            '2026-08-12',
            7
        );
        $firstIntent = $bridge->createIntent((int)$first['question']['id'], 0, 10, [20], 7);
        Db::name('online_daily_data')->where('id', 1201)->update(['validation_status' => 'unverified']);
        try {
            (new OperationManagementService())->approveExecutionIntent(
                (int)$firstIntent['execution_intent']['id'],
                true,
                '失效来源不应获批',
                8,
                [20]
            );
            self::fail('revoked fact status must block approval');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('来源', $exception->getMessage());
        }
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->count());

        Db::name('online_daily_data')->where('id', 1201)->update(['validation_status' => 'verified']);
        $second = $questionService->create(
            10,
            20,
            '2026-08-12 携程列表曝光用户数还应复核什么？',
            'ctrip',
            '2026-08-12',
            '2026-08-12',
            7
        );
        $secondIntent = $bridge->createIntent((int)$second['question']['id'], 0, 10, [20], 7);
        Db::name('online_daily_data')->where('id', 1201)->update(['list_exposure' => 1900]);
        try {
            (new OperationManagementService())->approveExecutionIntent(
                (int)$secondIntent['execution_intent']['id'],
                true,
                '漂移指标不应获批',
                8,
                [20]
            );
            self::fail('changed metric value must block approval');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('来源', $exception->getMessage());
        }
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->count());
    }

    public function testActionMetricMustBelongToValidatedClaims(): void
    {
        $questionService = $this->readyQuestionService('medium', 'detail_exposure');
        $saved = $questionService->create(
            10,
            20,
            '2026-08-12 携程列表曝光用户数应复核什么？',
            'ctrip',
            '2026-08-12',
            '2026-08-12',
            7
        );
        self::assertSame('answered_by_grounded_ai', $saved['question']['answer_status']);
        self::assertSame('list_exposure', $saved['question']['answer']['fact_claims'][0]['metric_key']);
        self::assertSame([], $saved['question']['answer']['action_drafts']);
        try {
            (new OperatingQuestionExecutionBridgeService(
                $questionService,
                new OperationManagementService()
            ))->createIntent((int)$saved['question']['id'], 0, 10, [20], 7);
            self::fail('an action metric outside validated claims must not create an intent');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('行动草案', $exception->getMessage());
        }
        self::assertSame(0, (int)Db::name('operation_execution_intents')->count());
    }

    public function testApprovalRevalidatesClaimMetricDefinitionAndUnit(): void
    {
        $questionService = $this->readyQuestionService();
        $bridge = new OperatingQuestionExecutionBridgeService(
            $questionService,
            new OperationManagementService()
        );
        $saved = $questionService->create(
            10,
            20,
            '2026-08-12 携程列表曝光用户数应复核什么？',
            'ctrip',
            '2026-08-12',
            '2026-08-12',
            7
        );
        $intent = $bridge->createIntent((int)$saved['question']['id'], 0, 10, [20], 7);
        $raw = json_decode((string)Db::name('online_daily_data')->where('id', 1201)->value('raw_data'), true);
        $raw['field_facts'][0]['source_key'] = 'exposureUV';
        Db::name('online_daily_data')->where('id', 1201)->update([
            'raw_data' => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);

        try {
            (new OperationManagementService())->approveExecutionIntent(
                (int)$intent['execution_intent']['id'],
                true,
                '定义或单位漂移后不应获批',
                8,
                [20]
            );
            self::fail('changed metric definition or unit must block approval');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('来源', $exception->getMessage());
        }
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->count());
    }

    public function testLegacyRequestKeyCannotReplayTheCurrentActionContract(): void
    {
        $questionService = $this->readyQuestionService();
        $first = $questionService->create(
            10,
            20,
            '2026-08-12 携程列表曝光用户数应复核什么？',
            'ctrip',
            '2026-08-12',
            '2026-08-12',
            7
        );
        $firstId = (int)$first['question']['id'];
        $currentKey = (string)$first['question']['request_key'];
        self::assertStringStartsWith('operating-question:v4:', $currentKey);
        Db::name('hotel_operating_questions')->where('id', $firstId)->update([
            'request_key' => str_replace('operating-question:v4:', 'operating-question:v3:', $currentKey),
        ]);
        try {
            (new OperatingQuestionExecutionBridgeService(
                $questionService,
                new OperationManagementService()
            ))->createIntent($firstId, 0, 10, [20], 7);
            self::fail('a v3 question must never reuse the current action contract');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('当前', $exception->getMessage());
        }

        $second = $questionService->create(
            10,
            20,
            '2026-08-12 携程列表曝光用户数应复核什么？',
            'ctrip',
            '2026-08-12',
            '2026-08-12',
            7
        );
        self::assertTrue($second['created']);
        self::assertNotSame($firstId, (int)$second['question']['id']);
        self::assertStringStartsWith('operating-question:v4:', (string)$second['question']['request_key']);
        self::assertSame(2, (int)Db::name('hotel_operating_questions')->count());
    }

    public function testMissingOrMismatchedResponseRegistryBlocksIntentCreationAndApproval(): void
    {
        $questionService = $this->readyQuestionService();
        $bridge = new OperatingQuestionExecutionBridgeService(
            $questionService,
            new OperationManagementService()
        );
        $missing = $questionService->create(
            10, 20, '2026-08-12 携程列表曝光用户数应复核什么？', 'ctrip', '2026-08-12', '2026-08-12', 7
        );
        $missingId = (int)$missing['question']['id'];
        Db::name('hotel_operating_question_model_responses')->where('question_id', $missingId)->delete();
        try {
            $bridge->createIntent($missingId, 0, 10, [20], 7);
            self::fail('missing provider receipt registry must block intent creation');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('当前', $exception->getMessage());
        }

        $mismatched = $questionService->create(
            10, 20, '2026-08-12 携程列表曝光用户数还应复核什么？', 'ctrip', '2026-08-12', '2026-08-12', 7
        );
        $mismatchedId = (int)$mismatched['question']['id'];
        $intent = $bridge->createIntent($mismatchedId, 0, 10, [20], 7);
        Db::name('hotel_operating_question_model_responses')->where('question_id', $mismatchedId)->update([
            'question_content_digest' => str_repeat('0', 64),
        ]);
        try {
            (new OperationManagementService())->approveExecutionIntent(
                (int)$intent['execution_intent']['id'],
                true,
                'registry 错绑时不得审批',
                8,
                [20]
            );
            self::fail('mismatched provider receipt registry must block approval');
        } catch (\InvalidArgumentException $exception) {
            self::assertNotSame('', trim($exception->getMessage()));
        }
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->count());
    }

    /** @return array<string,mixed> */
    private static function listExposureDefinition(): array
    {
        $identity = [
            'data_type' => 'traffic',
            'metric_key' => 'list_exposure',
            'source_metric_key' => 'exposure_users',
            'source_key' => 'listexposure',
            'source_path' => 'traffic.listExposure',
            'storage_field' => 'online_daily_data.list_exposure',
            'status' => 'captured',
            'stored_value_present' => true,
            'unit' => 'visitor_count',
        ];
        ksort($identity, SORT_STRING);
        return [
            'claimable' => true,
            'definition_id' => 'ota_list_exposure_users.v1',
            'source_metric_key' => 'exposure_users',
            'source_data_type' => 'traffic',
            'source_key' => 'listexposure',
            'storage_field' => 'online_daily_data.list_exposure',
            'source_path_digest' => hash('sha256', 'traffic.listExposure'),
            'field_fact_digest' => hash('sha256', json_encode(
                $identity,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
            )),
            'unit' => 'visitor_count',
            'unit_status' => 'verified',
            'unit_source' => 'operating_question_metric_semantics.v1',
            'label' => '曝光用户数',
        ];
    }

    private function readyQuestionService(
        string $confidence = 'medium',
        string $actionMetric = 'list_exposure'
    ): OperatingQuestionService
    {
        $fakeClient = new class($confidence, $actionMetric) extends LlmClient {
            private int $calls = 0;

            public function __construct(
                private readonly string $confidence,
                private readonly string $actionMetric
            )
            {
            }

            public function createJsonResponseEnvelope(
                array $messages,
                array $schema,
                string $modelKey = 'deepseek_v4_default'
            ): array {
                $this->calls++;
                return [
                    'data' => [
                        'fact_claims' => [[
                            'evidence_ref' => 'online_daily_data#1201',
                            'metric_key' => 'list_exposure',
                            'metric_definition_id' => 'ota_list_exposure_users.v1',
                            'value' => 1800,
                            'unit' => 'visitor_count',
                        ]],
                        'follow_up_questions' => [],
                        'confidence' => $this->confidence,
                        'action_drafts' => [[
                            'expected_metric' => $this->actionMetric,
                            'expected_metric_definition_id' => $this->actionMetric === 'list_exposure'
                                ? 'ota_list_exposure_users.v1'
                                : 'ota_detail_exposure.v1',
                            'evidence_refs' => ['online_daily_data#1201'],
                        ]],
                    ],
                    'meta' => OperatingIntelligenceServiceTest::trustedDeepSeekMeta(
                        'resp-bridge-1201-' . str_pad((string)$this->calls, 4, '0', STR_PAD_LEFT)
                    ),
                ];
            }
        };
        $ai = new OperatingQuestionAiAnswerService($fakeClient);
        return new OperatingQuestionService(
            static fn(): array => [
                'facts' => [[
                    'ref' => 'online_daily_data#1201',
                    'data_date' => '2026-08-12',
                    'platform' => 'ctrip',
                    'data_type' => 'traffic',
                    'quality_status' => 'verified',
                    'history_status' => 'success',
                    'readback_status' => 'readback_verified',
                    'readback_verified_at' => '2026-08-12 10:00:00',
                    'ingestion_method' => 'browser_profile',
                    'source_trace_id' => 'execution-bridge-test',
                    'metric_values' => ['list_exposure' => 1800],
                    'metric_units' => ['list_exposure' => 'visitor_count'],
                    'metric_definitions' => [
                        'list_exposure' => self::listExposureDefinition(),
                    ],
                ]],
                'fact_count' => 1,
            ],
            static fn(array $payload): array => $ai->generate($payload)
        );
    }
}
