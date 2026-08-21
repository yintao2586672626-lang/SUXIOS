<?php
declare(strict_types=1);

namespace Tests;

use app\service\AiDecisionQualityService;
use app\service\LlmClient;
use app\service\OperatingQuestionAiAnswerService;
use app\service\OperatingQuestionExecutionBridgeService;
use app\service\OperatingQuestionService;
use app\service\OperationActionLifecycleService;
use app\service\OperationManagementService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OperatingQuestionExecutionBridgeServiceTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    /** @return array<string,mixed> */
    public static function directMeta(string $responseId): array
    {
        $nonce = 'oq_bridge_' . substr(hash('sha256', $responseId), 0, 24);
        return [
            'provider' => 'deepseek',
            'model_key' => OperatingQuestionAiAnswerService::DIRECT_MODEL_KEY,
            'model' => OperatingQuestionAiAnswerService::DIRECT_MODEL_NAME,
            'configured_model' => OperatingQuestionAiAnswerService::DIRECT_MODEL_NAME,
            'response_model' => OperatingQuestionAiAnswerService::DIRECT_MODEL_NAME,
            'provider_response_id' => $responseId,
            'provider_created_at' => time(),
            'provider_response_fresh' => true,
            'provider_endpoint_origin' => 'https://api.deepseek.com',
            'provider_endpoint_host' => 'api.deepseek.com',
            'provider_endpoint_official' => true,
            'provider_config_digest' => str_repeat('b', 64),
            'direct_call_nonce' => $nonce,
            'transport_request_id' => $nonce,
            'transport_retry_attempts' => 0,
            'upstream_idempotency_key_sent' => false,
            'http_status' => 200,
            'provider_attempt_count' => 1,
            'idempotent_replay' => false,
            'direct_request_proof' => true,
            'thinking_mode' => 'enabled',
            'reasoning_effort' => 'high',
            'finish_reason' => 'stop',
            'fallback_used' => false,
            'cache_hit' => false,
            'degraded' => false,
        ];
    }

    public function testAcceptedDirectProofDoesNotExpireWhileNewReceiptFreshnessStillDoes(): void
    {
        $persisted = self::directMeta('resp-bridge-persisted-0001');
        $persisted['provider_created_at'] = time() - 3600;

        self::assertTrue(OperatingQuestionAiAnswerService::directCallProofReady($persisted));
        self::assertFalse(OperatingQuestionAiAnswerService::directCallReceiptFreshNow($persisted));
    }

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
            'operation_action_reviews',
            'operation_action_lifecycle_events',
            'operation_effect_reviews',
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
            'CREATE TABLE operation_action_lifecycle_events ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, intent_id INTEGER, task_id INTEGER, '
            . 'sequence_no INTEGER, event_type TEXT, from_status TEXT, to_status TEXT, actor_id INTEGER, event_payload_json TEXT, '
            . 'previous_digest TEXT, content_digest TEXT, created_at TEXT, UNIQUE(tenant_id,hotel_id,intent_id,sequence_no))'
        );
        Db::execute(
            'CREATE TABLE operation_action_reviews ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, intent_id INTEGER, task_id INTEGER, '
            . 'effect_review_id INTEGER, contract_version TEXT, metric_key TEXT, metric_unit TEXT, baseline_window_json TEXT, '
            . 'followup_window_json TEXT, before_value REAL, after_value REAL, delta_value REAL, metric_change_status TEXT, '
            . 'evidence_sufficiency TEXT, evidence_refs_json TEXT, non_attribution_reasons_json TEXT, recommendation TEXT, '
            . 'result_status TEXT, result_summary TEXT, causality_claimed INTEGER, reviewed_by INTEGER, reviewed_at TEXT, '
            . 'previous_review_id INTEGER, previous_digest TEXT, content_digest TEXT, created_at TEXT)'
        );
        Db::execute(
            'CREATE TABLE operation_effect_reviews ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, '
            . 'intent_id INTEGER NOT NULL, task_id INTEGER NOT NULL, platform TEXT NOT NULL, '
            . 'baseline_business_date TEXT NOT NULL, review_business_date TEXT NOT NULL, metric_key TEXT NOT NULL, '
            . 'metric_definition_json TEXT NOT NULL, metric_definition_digest TEXT NOT NULL, '
            . 'approval_target_digest TEXT NOT NULL, before_value REAL NOT NULL, after_value REAL NOT NULL, '
            . 'expected_direction TEXT NOT NULL, target_type TEXT NOT NULL, target_value REAL, expected_delta REAL, '
            . 'expected_delta_status TEXT NOT NULL, target_confirmed_by INTEGER NOT NULL, target_confirmed_at TEXT NOT NULL, '
            . 'baseline_refs_json TEXT NOT NULL, followup_refs_json TEXT NOT NULL, '
            . 'source_readback_evidence_id INTEGER NOT NULL, outcome_status TEXT NOT NULL, outcome_json TEXT NOT NULL, '
            . 'result_status TEXT NOT NULL, result_summary TEXT NOT NULL, causality_claimed INTEGER NOT NULL, '
            . 'reviewed_by INTEGER NOT NULL, reviewed_at TEXT NOT NULL, content_digest TEXT NOT NULL, created_at TEXT NOT NULL, '
            . 'UNIQUE (tenant_id, hotel_id, task_id, content_digest))'
        );
        Db::execute(
            'CREATE TABLE online_daily_data ('
            . 'id INTEGER PRIMARY KEY, tenant_id INTEGER, system_hotel_id INTEGER, data_date TEXT, platform TEXT, source TEXT, '
            . 'data_type TEXT, dimension TEXT, validation_status TEXT, history_status TEXT, readback_verified INTEGER, '
            . 'readback_verified_at TEXT, ingestion_method TEXT, source_trace_id TEXT, data_source_id INTEGER, '
            . 'data_period TEXT, is_final INTEGER, compare_type TEXT, collected_at TEXT, list_exposure REAL, '
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
            'dimension' => 'catalog:traffic_report:traffic_flow_transform:list_exposure',
            'validation_status' => 'verified',
            'history_status' => 'success',
            'readback_verified' => 1,
            'readback_verified_at' => '2026-08-12 10:00:00',
            'ingestion_method' => 'browser_profile',
            'source_trace_id' => 'execution-bridge-test',
            'data_source_id' => 25,
            'data_period' => 'historical_daily',
            'is_final' => 1,
            'compare_type' => 'self',
            'collected_at' => '2026-08-12 10:00:00',
            'list_exposure' => 1800,
            'detail_exposure' => 360,
            'raw_data' => json_encode([
                'endpoint_id' => 'traffic_flow_transform',
                'source_trace_id' => 'execution-bridge-test',
                'source_url_hash' => str_repeat('a', 64),
                'field_facts' => [[
                    'metric_key' => 'list_exposure',
                    'source_key' => 'listExposure',
                    'status' => 'captured',
                    'stored_value_present' => true,
                    'source_path' => 'traffic.listExposure',
                    'storage_field' => 'online_daily_data.list_exposure',
                    'capture_evidence' => [
                        'source_trace_id' => 'execution-bridge-test',
                        'source_url_hash' => str_repeat('a', 64),
                    ],
                ], [
                    'metric_key' => 'detail_exposure',
                    'source_key' => 'detailExposure',
                    'status' => 'captured',
                    'stored_value_present' => true,
                    'source_path' => 'traffic.detailExposure',
                    'storage_field' => 'online_daily_data.detail_exposure',
                    'capture_evidence' => [
                        'source_trace_id' => 'execution-bridge-test',
                        'source_url_hash' => str_repeat('a', 64),
                    ],
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
        self::assertSame('operation_action_card.v1', $intent['action_management']['contract_version']);
        self::assertSame('pending_approval', $intent['action_management']['lifecycle']['status']);
        self::assertSame('verified', $intent['action_management']['lifecycle']['integrity_status']);
        self::assertCount(2, $intent['action_management']['lifecycle']['events']);

        $replayed = $bridge->createIntent($questionId, 0, 10, [20], 7);
        self::assertTrue($replayed['reused_existing_intent']);
        self::assertSame($intent['id'], $replayed['execution_intent']['id']);
        self::assertSame(1, (int)Db::name('operation_execution_intents')->count());

        $readback = $bridge->readExistingIntents($questionId, 10, [20]);
        self::assertSame('ok', $readback['data_status']);
        self::assertCount(1, $readback['list']);
        self::assertSame(0, $readback['list'][0]['action_index']);
        self::assertSame($intent['id'], $readback['list'][0]['execution_intent']['id']);
        self::assertSame('pending_approval', $readback['list'][0]['execution_intent']['status']);

        $schedule = $intent['target_value']['workflow_schedule'];
        $approved = (new OperationManagementService())->approveExecutionIntent(
            (int)$intent['id'],
            true,
            '人工确认后进入本地执行池',
            8,
            [20],
            [
                'expected_metric' => 'list_exposure',
                'expected_direction' => 'increase',
                'target_type' => 'delta',
                'expected_delta' => 100,
                'review_business_date' => substr((string)$schedule['review_at'], 0, 10),
                'assignee_id' => 8,
                'due_at' => (string)$schedule['due_at'],
                'review_at' => (string)$schedule['review_at'],
            ]
        );
        self::assertSame('approved', $approved['status']);
        self::assertCount(1, $approved['tasks']);
        self::assertSame('manual', $approved['tasks'][0]['execution_mode']);
        self::assertSame('pending_execute', $approved['tasks'][0]['status']);
        self::assertSame('approved', $approved['action_management']['lifecycle']['status']);
        self::assertSame(3, $approved['action_management']['lifecycle']['event_count']);
        self::assertSame(8, $approved['action_management']['action_card']['responsibility']['owner_id']);
        self::assertSame(1, (int)Db::name('operation_execution_tasks')->count());
        self::assertSame(
            'approved',
            $bridge->readExistingIntents($questionId, 10, [20])['list'][0]['execution_intent']['status']
        );
    }

    public function testMissingFactsAndSourceDriftNeverCreateOrApproveAnIntent(): void
    {
        $missingService = new OperatingQuestionService(static fn(): array => []);
        $blocked = $missingService->create(
            10,
            20,
            '缺事实时能创建携程列表曝光用户数任务吗？',
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

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('question_readback_digest_mismatch');
        (new OperationManagementService())->approveExecutionIntent(
            (int)$created['execution_intent']['id'],
            true,
            '来源漂移后不应批准',
            8,
            [20]
        );
    }

    public function testActionCardBlocksExpiredInsufficientAndMismatchedMetricFacts(): void
    {
        $service = new OperationActionLifecycleService();
        $createdAt = new \DateTimeImmutable('2026-08-20 09:00:00', new \DateTimeZone('Asia/Shanghai'));
        $question = [
            'id' => 91,
            'tenant_id' => 10,
            'hotel_id' => 20,
            'platform' => 'ctrip',
            'date_start' => '2026-08-12',
            'date_end' => '2026-08-12',
            'answer_status' => 'answered_by_grounded_ai',
            'content_digest' => str_repeat('c', 64),
            'answer' => [
                'answer_summary' => '同口径指标已回读，等待人工审批。',
                'fact_samples' => [[
                    'ref' => 'online_daily_data#1201',
                    'platform' => 'ctrip',
                    'data_date' => '2026-08-12',
                    'metric_values' => ['list_exposure' => 1800],
                    'metric_units' => ['list_exposure' => 'exposure_count'],
                ]],
            ],
        ];
        $action = [
            'title' => '复核列表曝光链路',
            'action' => '人工复核列表曝光链路并保存证据。',
            'action_object' => '携程列表曝光链路',
            'execution_steps' => ['人工复核'],
            'expected_metric' => 'list_exposure',
            'risk_level' => 'medium',
            'risk_summary' => '单日波动不能直接归因。',
            'evidence_refs' => ['online_daily_data#1201'],
            'action_digest' => str_repeat('d', 64),
        ];
        $card = $service->buildPendingCard($question, $action, 7, $createdAt);
        $intent = [
            'tenant_id' => 10,
            'hotel_id' => 20,
            'source_module' => OperatingQuestionExecutionBridgeService::SOURCE_MODULE,
            'source_record_id' => 91,
            'platform' => 'ctrip',
            'date_start' => '2026-08-12',
            'date_end' => '2026-08-12',
            'expected_metric' => 'list_exposure',
            'status' => 'pending_approval',
            'target_value' => ['action_card' => $card],
            'evidence' => ['action_card' => $card],
        ];
        try {
            $service->assertPendingCardCurrent($intent, $createdAt->modify('+25 hours'));
            self::fail('expired action cards must not enter approval');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('过期', $exception->getMessage());
        }

        $insufficient = $action;
        $insufficient['evidence_refs'][] = 'online_daily_data#9999';
        try {
            $service->buildPendingCard($question, $insufficient, 7, $createdAt);
            self::fail('incomplete fact coverage must not create an action card');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('事实不足', $exception->getMessage());
        }

        $mismatchedQuestion = $question;
        $mismatchedQuestion['answer']['fact_samples'][] = [
            'ref' => 'online_daily_data#1202',
            'platform' => 'ctrip',
            'data_date' => '2026-08-12',
            'metric_values' => ['list_exposure' => 1800],
            'metric_units' => ['list_exposure' => 'percent'],
        ];
        $mismatched = $action;
        $mismatched['evidence_refs'][] = 'online_daily_data#1202';
        try {
            $service->buildPendingCard($mismatchedQuestion, $mismatched, 7, $createdAt);
            self::fail('mixed metric units must not create an action card');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('单位不匹配', $exception->getMessage());
        }
    }

    public function testEquivalentActiveActionIsBlockedBeforeSecondApproval(): void
    {
        $questionService = $this->readyQuestionService();
        $bridge = new OperatingQuestionExecutionBridgeService(
            $questionService,
            new OperationManagementService()
        );
        $firstQuestion = $questionService->create(
            10,
            20,
            '2026-08-12 携程列表曝光用户数应复核什么？',
            'ctrip',
            '2026-08-12',
            '2026-08-12',
            7
        );
        $firstIntent = $bridge->createIntent((int)$firstQuestion['question']['id'], 0, 10, [20], 7)['execution_intent'];
        $schedule = $firstIntent['target_value']['workflow_schedule'];
        (new OperationManagementService())->approveExecutionIntent(
            (int)$firstIntent['id'],
            true,
            '人工批准第一条行动',
            8,
            [20],
            [
                'expected_metric' => 'list_exposure',
                'expected_direction' => 'increase',
                'target_type' => 'delta',
                'expected_delta' => 100,
                'review_business_date' => substr((string)$schedule['review_at'], 0, 10),
                'assignee_id' => 8,
                'due_at' => (string)$schedule['due_at'],
                'review_at' => (string)$schedule['review_at'],
            ]
        );

        $secondQuestion = $questionService->create(
            10,
            20,
            '2026-08-12 携程列表曝光用户数应复核什么？',
            'ctrip',
            '2026-08-12',
            '2026-08-12',
            7
        );
        $secondIntent = $bridge->createIntent((int)$secondQuestion['question']['id'], 0, 10, [20], 7)['execution_intent'];
        try {
            (new OperationManagementService())->approveExecutionIntent(
                (int)$secondIntent['id'],
                true,
                '尝试批准重复行动',
                8,
                [20]
            );
            self::fail('equivalent active action must be blocked before approval');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('重复', $exception->getMessage());
        }
        self::assertSame(1, (int)Db::name('operation_execution_tasks')->count());
        self::assertSame(
            'pending_approval',
            (string)Db::name('operation_execution_intents')->where('id', (int)$secondIntent['id'])->value('status')
        );
    }

    public function testPendingManagedActionCanBeCancelledWithAnAppendOnlyReason(): void
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
        $created = (new OperatingQuestionExecutionBridgeService(
            $questionService,
            new OperationManagementService()
        ))->createIntent((int)$saved['question']['id'], 0, 10, [20], 7);
        $intentId = (int)$created['execution_intent']['id'];

        $cancelled = (new OperationManagementService())->cancelExecutionIntent(
            $intentId,
            '经营窗口已经变化，保留旧行动但停止执行。',
            8,
            [20]
        );
        self::assertSame('cancelled', $cancelled['status']);
        self::assertSame('cancelled', $cancelled['action_management']['lifecycle']['status']);
        self::assertSame(3, $cancelled['action_management']['lifecycle']['event_count']);
        self::assertSame(
            '经营窗口已经变化，保留旧行动但停止执行。',
            $cancelled['action_management']['lifecycle']['events'][2]['event_payload']['reason']
        );
        self::assertSame([], $cancelled['tasks']);
        self::assertFalse($cancelled['action_management']['historical_records_mutated']);
    }

    public function testApprovedActionRunsThroughTaskEvidenceAndSourceBasedReviewWithExactTraceability(): void
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
        $bridge = new OperatingQuestionExecutionBridgeService(
            $questionService,
            new OperationManagementService()
        );
        $created = $bridge->createIntent((int)$saved['question']['id'], 0, 10, [20], 7);
        $intent = $created['execution_intent'];

        $timezone = new \DateTimeZone('Asia/Shanghai');
        $now = new \DateTimeImmutable('now', $timezone);
        $dueAt = $now->modify('+2 seconds')->format('Y-m-d H:i:s');
        $reviewAt = $now->modify('+4 seconds')->format('Y-m-d H:i:s');
        $management = new OperationManagementService();
        $approved = $management->approveExecutionIntent(
            (int)$intent['id'],
            true,
            '人工核对事实后批准执行并按计划复盘',
            8,
            [20],
            [
                'expected_metric' => 'list_exposure',
                'expected_direction' => 'increase',
                'target_type' => 'delta',
                'expected_delta' => 100,
                'review_business_date' => substr($reviewAt, 0, 10),
                'assignee_id' => 8,
                'due_at' => $dueAt,
                'review_at' => $reviewAt,
            ]
        );
        $taskId = (int)$approved['tasks'][0]['id'];

        $started = $management->executeExecutionTask($taskId, [20], ['status' => 'executing'], 8);
        self::assertSame('executing', $started['status']);
        self::assertSame('in_progress', $started['action_management']['lifecycle']['status']);

        $completed = $management->executeExecutionTask($taskId, [20], [
            'status' => 'executed',
            'evidence_type' => 'manual_operation_execution',
            'evidence' => [
                'platform_response' => [
                    'mode' => 'manual_operation_execution',
                    'completed_action' => '负责人已人工核对携程列表入口与展示配置，未由系统修改 OTA。',
                    'automatic_ota_write' => false,
                ],
                'remark' => '执行动作及人工边界已记录',
            ],
        ], 8);
        self::assertSame('executed', $completed['status']);
        self::assertSame('completed', $completed['action_management']['lifecycle']['status']);

        $withAttachment = $management->addExecutionEvidence($taskId, [20], [
            'evidence_type' => 'manual',
            'evidence' => [
                'attachment_path' => '/evidence/operation-question-list-exposure-check.png',
                'remark' => '人工执行截图引用，仅作为执行证据，不作为指标真值。',
            ],
        ], 8);
        self::assertCount(2, $withAttachment['evidence']);
        self::assertSame('completed', $withAttachment['action_management']['lifecycle']['status']);

        $reviewTimestamp = strtotime($reviewAt);
        self::assertNotFalse($reviewTimestamp);
        while (time() < $reviewTimestamp) {
            usleep(100000);
        }
        $readbackAt = date('Y-m-d H:i:s', max(time(), $reviewTimestamp));
        $reviewDate = substr($reviewAt, 0, 10);
        Db::name('online_daily_data')->insert([
            'id' => 1202,
            'tenant_id' => 10,
            'system_hotel_id' => 20,
            'data_date' => $reviewDate,
            'platform' => 'ctrip',
            'source' => 'ctrip',
            'data_type' => 'traffic',
            'dimension' => 'catalog:traffic_report:traffic_flow_transform:list_exposure',
            'validation_status' => 'verified',
            'history_status' => 'success',
            'readback_verified' => 1,
            'readback_verified_at' => $readbackAt,
            'ingestion_method' => 'browser_profile',
            'source_trace_id' => 'execution-bridge-review-test',
            'data_source_id' => 25,
            'data_period' => 'realtime_snapshot',
            'is_final' => 0,
            'compare_type' => 'self',
            'collected_at' => $readbackAt,
            'list_exposure' => 1950,
            'detail_exposure' => 390,
            'raw_data' => json_encode([
                'endpoint_id' => 'traffic_flow_transform',
                'source_trace_id' => 'execution-bridge-review-test',
                'source_url_hash' => str_repeat('b', 64),
                'field_facts' => [[
                    'metric_key' => 'list_exposure',
                    'source_key' => 'listExposure',
                    'status' => 'captured',
                    'stored_value_present' => true,
                    'source_path' => 'traffic.listExposure',
                    'storage_field' => 'online_daily_data.list_exposure',
                    'capture_evidence' => [
                        'source_trace_id' => 'execution-bridge-review-test',
                        'source_url_hash' => str_repeat('b', 64),
                    ],
                ]],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $reconciled = $management->reconcileScheduledExecutionTask($taskId, [20]);
        self::assertSame('source_readback_verified', $reconciled['status']);
        self::assertTrue($reconciled['source_verified']);
        $reconciledTask = $management->readExecutionTask($taskId, [20]);
        self::assertCount(3, $reconciledTask['evidence']);
        self::assertContains(
            'source_verified_metric_readback',
            array_column($reconciledTask['evidence'], 'evidence_type')
        );

        $reviewed = $management->reviewExecutionTask($taskId, [20], [
            'result_status' => 'success',
            'result_summary' => '同酒店、同携程、同指标的新回读窗口显示列表曝光增加 150。',
        ], 8);
        self::assertSame('success', $reviewed['result_status']);
        self::assertSame('reviewed', $reviewed['action_management']['lifecycle']['status']);
        self::assertSame('sufficient', $reviewed['action_management']['latest_review']['evidence_sufficiency']);
        self::assertSame('increased', $reviewed['action_management']['latest_review']['metric_change_status']);
        self::assertSame('continue', $reviewed['action_management']['latest_review']['recommendation']);
        self::assertFalse($reviewed['action_management']['latest_review']['causality_claimed']);
        self::assertContains(
            'observational_before_after_no_control_group',
            $reviewed['action_management']['latest_review']['non_attribution_reasons']
        );
        self::assertSame(1, (int)Db::name('operation_effect_reviews')->count());

        $exact = $management->readExecutionTask($taskId, [20]);
        self::assertSame($taskId, (int)$exact['id']);
        self::assertSame(
            'hotel_operating_questions#' . (int)$saved['question']['id'],
            $exact['action_management']['traceability']['question_ref']
        );
        self::assertSame(
            ['operation_execution_tasks#' . $taskId],
            $exact['action_management']['traceability']['task_refs']
        );
        self::assertCount(3, $exact['action_management']['traceability']['evidence_refs']);
        self::assertCount(1, $exact['action_management']['traceability']['review_refs']);
        self::assertFalse($exact['action_management']['historical_records_mutated']);
        self::assertFalse($exact['action_management']['external_action_performed']);
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
            '2026-08-12 携程列表曝光用户数应复核什么？',
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
            'label' => AiDecisionQualityService::LIST_EXPOSURE_METRIC_LABEL,
        ];
    }

    private function readyQuestionService(
        string $confidence = 'medium',
        string $actionMetric = 'list_exposure'
    ): OperatingQuestionService {
        $fakeClient = new class($confidence, $actionMetric) extends LlmClient {
            private int $calls = 0;

            public function __construct(
                private readonly string $confidence,
                private readonly string $actionMetric
            ) {
            }

            public function createJsonResponseEnvelope(
                array $messages,
                array $schema,
                string $modelKey = OperatingQuestionAiAnswerService::DIRECT_MODEL_KEY
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
                    'meta' => OperatingQuestionExecutionBridgeServiceTest::directMeta(
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
