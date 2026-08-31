<?php
declare(strict_types=1);

namespace Tests;

use app\service\AiDecisionQualityService;
use app\service\LlmClient;
use app\service\OperationActionAiReviewService;
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
                    'source_key' => 'clicks',
                    'status' => 'captured',
                    'stored_value_present' => true,
                    'source_path' => 'traffic.clicks',
                    'storage_field' => 'online_daily_data.detail_exposure',
                    'capture_evidence' => [
                        'source_trace_id' => 'execution-bridge-test',
                        'source_url_hash' => str_repeat('a', 64),
                    ],
                ]],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function testVerifiedActionWithExternalReviewerStillRequiresHumanConfirmation(): void
    {
        $questionService = $this->readyQuestionService('medium', true);
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
            new OperationManagementService(),
            $this->aiReviewer('approve')
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
        self::assertNull($created['ai_review'] ?? null);
        self::assertFalse($intent['evidence']['boundaries']['automatic_execution']);
        self::assertFalse($intent['evidence']['boundaries']['ota_write']);
        self::assertSame('operation_action_card.v1', $intent['action_management']['contract_version']);
        self::assertSame('pending_approval', $intent['action_management']['lifecycle']['status']);
        self::assertSame('verified', $intent['action_management']['lifecycle']['integrity_status']);
        self::assertSame('human_confirmation', $intent['action_management']['action_card']['approval']['mode']);
        self::assertSame(
            'explicit_user_second_confirmation_after_fact_reread',
            $intent['action_management']['action_card']['approval']['trigger_policy']
        );
        self::assertSame(
            OperationActionLifecycleService::APPROVAL_CONFIRMATION_VERSION,
            $intent['action_management']['action_card']['approval']['confirmation_version']
        );
        self::assertTrue($intent['action_management']['action_card']['boundaries']['human_confirmation_required']);
        self::assertFalse($intent['action_management']['action_card']['boundaries']['independent_ai_review_required']);
        self::assertSame(
            $intent['action_management']['action_card']['action']['steps'],
            $intent['target_value']['steps']
        );
        self::assertSame(
            'human_assigned_schedule_requires_manual_approval_and_readback_review',
            $intent['target_value']['workflow_schedule']['source_policy']
        );
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->count());

        $replayed = $bridge->createIntent($questionId, 0, 10, [20], 7);
        self::assertTrue($replayed['reused_existing_intent']);
        self::assertSame($intent['id'], $replayed['execution_intent']['id']);
        self::assertSame('pending_approval', $replayed['execution_intent']['status']);
        self::assertSame([], $replayed['execution_intent']['tasks']);
        self::assertSame(1, (int)Db::name('operation_execution_intents')->count());
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->count());

        $readback = $bridge->readExistingIntents($questionId, 10, [20]);
        self::assertSame('ok', $readback['data_status']);
        self::assertCount(1, $readback['list']);
        self::assertSame(0, $readback['list'][0]['action_index']);
        self::assertSame($intent['id'], $readback['list'][0]['execution_intent']['id']);
        self::assertSame('pending_approval', $readback['list'][0]['execution_intent']['status']);
        self::assertSame([], $readback['list'][0]['execution_intent']['tasks']);
    }

    public function testExternalReviewerDecisionNeverApprovesRejectsOrCreatesTask(): void
    {
        foreach (['approve', 'reject', 'unavailable'] as $index => $decision) {
            if ($index > 0) {
                $this->setUp();
            }
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
                new OperationManagementService(),
                $this->aiReviewer($decision)
            ))->createIntent((int)$saved['question']['id'], 0, 10, [20], 7);
            self::assertNull($created['ai_review'] ?? null);
            self::assertSame('pending_approval', $created['execution_intent']['status']);
            self::assertSame([], $created['execution_intent']['tasks']);
            self::assertSame(
                'human_confirmation',
                $created['execution_intent']['action_management']['action_card']['approval']['mode']
            );
            self::assertTrue(
                $created['execution_intent']['action_management']['action_card']['boundaries']['human_confirmation_required']
            );
        }
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->count());
    }

    public function testHumanApprovalRequiresMatchingSecondConfirmationAndCreatesExactlyOneTask(): void
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
        $management = new OperationManagementService();
        $intent = (new OperatingQuestionExecutionBridgeService(
            $questionService,
            $management
        ))->createIntent((int)$saved['question']['id'], 0, 10, [20], 7)['execution_intent'];
        $intentId = (int)$intent['id'];
        self::assertSame('pending_approval', $intent['status']);
        self::assertSame([], $intent['tasks']);
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->count());

        $schedule = $intent['target_value']['workflow_schedule'];
        $approvalTarget = [
            'expected_metric' => 'list_exposure',
            'expected_direction' => 'increase',
            'target_type' => 'delta',
            'expected_delta' => 100,
            'review_business_date' => substr((string)$schedule['review_at'], 0, 10),
            'assignee_id' => 8,
            'due_at' => (string)$schedule['due_at'],
            'review_at' => (string)$schedule['review_at'],
        ];
        try {
            // Deliberately omit the second-confirmation fields for this negative gate.
            $management->approveExecutionIntent(
                $intentId,
                true,
                '未二次确认不应创建任务',
                8,
                [20],
                $approvalTarget
            );
            self::fail('missing second confirmation must block approval');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('二次确认', $exception->getMessage());
        }
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->count());
        self::assertSame(
            'pending_approval',
            (string)Db::name('operation_execution_intents')->where('id', $intentId)->value('status')
        );

        try {
            $management->approveExecutionIntent(
                $intentId,
                true,
                'AI 建议不能成为审批权威',
                0,
                [20],
                $approvalTarget,
                ['decision' => 'approve']
            );
            self::fail('AI review must remain advisory and cannot approve an operation action');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('advisory only', $exception->getMessage());
        }
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->count());
        self::assertSame(
            'pending_approval',
            (string)Db::name('operation_execution_intents')->where('id', $intentId)->value('status')
        );

        $confirmation = $this->humanApprovalInput($intent, $approvalTarget);
        $approved = $management->approveExecutionIntent(
            $intentId,
            true,
            '当前用户已二次确认同一行动',
            8,
            [20],
            $confirmation
        );
        self::assertSame('approved', $approved['status']);
        self::assertCount(1, $approved['tasks']);
        self::assertSame('manual', $approved['tasks'][0]['execution_mode']);
        self::assertSame('pending_execute', $approved['tasks'][0]['status']);
        self::assertSame(20, (int)$approved['tasks'][0]['target_value']['action_card']['hotel']['hotel_id']);
        self::assertSame('ctrip', $approved['tasks'][0]['target_value']['action_card']['source']['platform']);
        self::assertSame('list_exposure', $approved['tasks'][0]['target_value']['action_card']['metric_contract']['metric_key']);
        self::assertSame('increase', $approved['tasks'][0]['target_value']['action_card']['metric_contract']['expected_direction']);
        self::assertSame('delta', $approved['tasks'][0]['target_value']['action_card']['metric_contract']['target_type']);
        self::assertSame(8, (int)$approved['tasks'][0]['target_value']['assignee_id']);
        self::assertSame($confirmation['due_at'], $approved['tasks'][0]['target_value']['execution_window']['end_at']);
        self::assertSame('Asia/Shanghai', $approved['tasks'][0]['target_value']['execution_window']['timezone']);
        self::assertNotEmpty($approved['tasks'][0]['target_value']['stop_conditions']);
        $taskId = (int)$approved['tasks'][0]['id'];
        self::assertSame(1, (int)Db::name('operation_execution_tasks')->count());

        try {
            $management->approveExecutionIntent(
                $intentId,
                true,
                '重复提交同一二次确认',
                8,
                [20],
                $confirmation
            );
            self::fail('replayed approval must not create a second task');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('pending_approval', $exception->getMessage());
        }
        $readback = $management->readExecutionIntent($intentId, [20]);
        self::assertSame('approved', $readback['status']);
        self::assertCount(1, $readback['tasks']);
        self::assertSame($taskId, (int)$readback['tasks'][0]['id']);
        self::assertSame(1, (int)Db::name('operation_execution_tasks')->count());
    }

    public function testLegacyPendingActionKeepsItsIdWhenExternalReviewerIsSupplied(): void
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
        $question = $saved['question'];
        $action = $question['answer']['action_drafts'][0];
        $pendingBridge = new OperatingQuestionExecutionBridgeService(
            $questionService,
            new OperationManagementService()
        );
        $pending = $pendingBridge->createIntent((int)$question['id'], 0, 10, [20], 7)['execution_intent'];
        $keyMethod = new \ReflectionMethod(OperatingQuestionExecutionBridgeService::class, 'idempotencyKey');
        $legacyKey = (string)$keyMethod->invoke(
            $pendingBridge,
            $question,
            $action,
            0
        );
        $legacyEvidence = $pending['evidence'];
        Db::name('operation_execution_intents')->where('id', (int)$pending['id'])->update([
            'idempotency_key' => $legacyKey,
            'action_type' => 'human_reviewed_operating_check',
            'evidence_json' => json_encode(
                $legacyEvidence,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
        ]);

        $resumed = (new OperatingQuestionExecutionBridgeService(
            $questionService,
            new OperationManagementService(),
            $this->aiReviewer('approve')
        ))->createIntent((int)$question['id'], 0, 10, [20], 7);

        self::assertTrue($resumed['reused_existing_intent']);
        self::assertSame((int)$pending['id'], (int)$resumed['execution_intent']['id']);
        self::assertNull($resumed['ai_review'] ?? null);
        self::assertSame('pending_approval', $resumed['execution_intent']['status']);
        self::assertSame([], $resumed['execution_intent']['tasks']);
        self::assertSame(1, (int)Db::name('operation_execution_intents')->count());
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->count());
    }

    public function testLegacyAiModePendingCardCannotBypassHumanDigestConfirmation(): void
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
        $management = new OperationManagementService();
        $intent = (new OperatingQuestionExecutionBridgeService(
            $questionService,
            $management
        ))->createIntent((int)$saved['question']['id'], 0, 10, [20], 7)['execution_intent'];
        $target = $intent['target_value'];
        $evidence = $intent['evidence'];
        $card = $target['action_card'];
        $card['approval']['mode'] = 'ai_independent_review';
        $card['boundaries']['human_confirmation_required'] = false;
        $card['boundaries']['independent_ai_review_required'] = true;
        unset($card['content_digest']);
        $digestMethod = new \ReflectionMethod(OperationActionLifecycleService::class, 'cardDigest');
        $digestMethod->setAccessible(true);
        $card['content_digest'] = (string)$digestMethod->invoke(new OperationActionLifecycleService(), $card);
        $target['action_card'] = $card;
        $evidence['action_card'] = $card;
        Db::name('operation_execution_intents')->where('id', (int)$intent['id'])->update([
            'target_value_json' => json_encode($target, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'evidence_json' => json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        $legacyPending = $management->readExecutionIntent((int)$intent['id'], [20]);

        try {
            $management->approveExecutionIntent(
                (int)$intent['id'],
                true,
                '旧 AI 模式必须失败关闭',
                8,
                [20],
                $this->humanApprovalInput($legacyPending)
            );
            self::fail('legacy AI-mode pending action must require a current human card reissue');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('重新生成当前人工确认行动卡', $exception->getMessage());
        }
        self::assertSame('pending_approval', (string)Db::name('operation_execution_intents')
            ->where('id', (int)$intent['id'])->value('status'));
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->count());
    }

    public function testCorruptedLifecycleEventChainFailsClosedBeforeReadAndAppend(): void
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
        $intent = $created['execution_intent'];
        $lifecycle = new OperationActionLifecycleService();
        $events = $lifecycle->eventsForIntent(10, 20, (int)$intent['id']);
        self::assertCount(2, $events);

        $invalidEvents = $events;
        $invalidEvents[0]['content_digest'] = str_repeat('0', 64);
        try {
            $lifecycle->currentStatus($intent, $invalidEvents);
            self::fail('a corrupted event chain must never project a lifecycle status');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('状态不可用', $exception->getMessage());
        }

        Db::name(OperationActionLifecycleService::EVENT_TABLE)
            ->where('intent_id', (int)$intent['id'])
            ->where('sequence_no', 1)
            ->update(['content_digest' => str_repeat('0', 64)]);
        try {
            $lifecycle->eventsForIntent(10, 20, (int)$intent['id']);
            self::fail('a corrupted event chain must fail closed on read');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('事件链损坏', $exception->getMessage());
        }
        try {
            $lifecycle->appendEvent(
                $intent,
                0,
                'pending_approval',
                'cancelled',
                'cancelled',
                8,
                ['reason' => 'must not append after corruption']
            );
            self::fail('a corrupted event chain must fail closed before append');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('事件链损坏', $exception->getMessage());
        }
        self::assertSame(
            2,
            (int)Db::name(OperationActionLifecycleService::EVENT_TABLE)
                ->where('intent_id', (int)$intent['id'])
                ->count()
        );
    }

    public function testCorruptedUnifiedReviewChainFailsClosedBeforeReadAndAppend(): void
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
        $intent = $created['execution_intent'];
        $schedule = $intent['target_value']['workflow_schedule'];
        $approved = (new OperationManagementService())->approveExecutionIntent(
            (int)$intent['id'],
            true,
            '人工批准后建立复盘链',
            8,
            [20],
            $this->humanApprovalInput($intent, [
                'expected_metric' => 'list_exposure',
                'expected_direction' => 'increase',
                'target_type' => 'delta',
                'expected_delta' => 100,
                'review_business_date' => substr((string)$schedule['review_at'], 0, 10),
                'assignee_id' => 8,
                'due_at' => (string)$schedule['due_at'],
                'review_at' => (string)$schedule['review_at'],
            ])
        );
        $task = $approved['tasks'][0];
        $lifecycle = new OperationActionLifecycleService();
        $reviewedAt = date('Y-m-d H:i:s');
        $review = $lifecycle->appendReview($approved, $task, [], 'observing', '首次人工复盘', 8, $reviewedAt);
        self::assertGreaterThan(0, (int)$review['id']);
        self::assertSame('verified', $approved['action_management']['integrity']['status']);

        $forgedReview = $review;
        $forgedReview['task_id'] = 999999;
        $reviewDigest = new \ReflectionMethod(OperationActionLifecycleService::class, 'reviewDigest');
        $forgedDigest = (string)$reviewDigest->invoke($lifecycle, $forgedReview);
        Db::name(OperationActionLifecycleService::REVIEW_TABLE)
            ->where('id', (int)$review['id'])
            ->update([
                'task_id' => 999999,
                'content_digest' => $forgedDigest,
            ]);
        try {
            $lifecycle->reviewsForIntent(10, 20, (int)$intent['id']);
            self::fail('a corrupted review chain must fail closed on read');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('复盘链损坏', $exception->getMessage());
        }
        try {
            $lifecycle->appendReview($approved, $task, [], 'observing', '不得追加', 8, $reviewedAt);
            self::fail('a corrupted review chain must fail closed before append');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('复盘链损坏', $exception->getMessage());
        }
        self::assertSame(1, (int)Db::name(OperationActionLifecycleService::REVIEW_TABLE)->count());
    }

    public function testAggregateLifecycleReadReusesLoadedTaskScopeWhileIncompleteAndDirectReadsStayFailClosed(): void
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
        $management = new OperationManagementService();
        $intent = (new OperatingQuestionExecutionBridgeService(
            $questionService,
            $management
        ))->createIntent((int)$saved['question']['id'], 0, 10, [20], 7)['execution_intent'];
        $schedule = $intent['target_value']['workflow_schedule'];
        $approved = $management->approveExecutionIntent(
            (int)$intent['id'],
            true,
            '人工批准后验证聚合读取查询数',
            8,
            [20],
            $this->humanApprovalInput($intent, [
                'expected_metric' => 'list_exposure',
                'expected_direction' => 'increase',
                'target_type' => 'delta',
                'expected_delta' => 100,
                'review_business_date' => substr((string)$schedule['review_at'], 0, 10),
                'assignee_id' => 8,
                'due_at' => (string)$schedule['due_at'],
                'review_at' => (string)$schedule['review_at'],
            ])
        );
        $task = $approved['tasks'][0];
        $lifecycle = new OperationActionLifecycleService();
        $lifecycle->appendReview(
            $approved,
            $task,
            [],
            'observing',
            '查询数回归夹具',
            8,
            date('Y-m-d H:i:s')
        );

        $queries = [];
        Db::listen(static function ($sql) use (&$queries): void {
            if (!str_starts_with((string)$sql, 'CONNECT:')) {
                $queries[] = (string)$sql;
            }
        });
        $taskPointSelects = static fn(array $logged): array => array_values(array_filter(
            $logged,
            static fn(string $sql): bool => preg_match(
                '/\bfrom\s+[`"]?operation_execution_tasks[`"]?\b/i',
                $sql
            ) === 1 && preg_match(
                '/(?:^|[^a-z0-9_])[`"]?id[`"]?\s*=\s*\d+/i',
                $sql
            ) === 1
        ));

        $readback = $management->readExecutionIntent((int)$intent['id'], [20]);
        $aggregateTaskPointSelects = $taskPointSelects($queries);
        self::assertCount(
            0,
            $aggregateTaskPointSelects,
            "complete aggregate read must not point-query already loaded task scope:\n"
                . implode("\n", $aggregateTaskPointSelects)
        );
        self::assertSame('verified', $readback['action_management']['integrity']['status']);
        self::assertSame('verified', $readback['action_management']['review_integrity_status']);

        $foreignTask = array_replace($task, ['id' => 999999, 'tenant_id' => 11]);
        $foreignExcluded = $lifecycle->decorateIntent(array_replace($readback, [
            'tasks' => [$task, $foreignTask],
        ]));
        self::assertSame(1, $foreignExcluded['action_management']['task_count']);
        self::assertNotContains(
            'operation_execution_tasks#999999',
            $foreignExcluded['action_management']['traceability']['task_refs']
        );

        $beforeIncomplete = count($queries);
        $incomplete = $lifecycle->decorateIntent(array_replace($readback, ['tasks' => []]));
        $incompleteTaskPointSelects = $taskPointSelects(array_slice($queries, $beforeIncomplete));
        self::assertCount(
            2,
            $incompleteTaskPointSelects,
            "an incomplete intent must verify event and review task scope in the database:\n"
                . implode("\n", $incompleteTaskPointSelects)
        );
        self::assertSame('verified', $incomplete['action_management']['integrity']['status']);

        $beforeDirect = count($queries);
        self::assertNotEmpty($lifecycle->eventsForIntent(10, 20, (int)$intent['id']));
        self::assertNotEmpty($lifecycle->reviewsForIntent(10, 20, (int)$intent['id']));
        $directTaskPointSelects = $taskPointSelects(array_slice($queries, $beforeDirect));
        self::assertCount(
            2,
            $directTaskPointSelects,
            "direct chain reads must retain database task-scope verification:\n"
                . implode("\n", $directTaskPointSelects)
        );

        Db::name('operation_execution_tasks')->where('id', (int)$task['id'])->update(['tenant_id' => 11]);
        try {
            $lifecycle->decorateIntent($readback);
            self::fail('a non-empty stale task snapshot must be re-verified and rejected');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('事件链损坏', $exception->getMessage());
        }
        try {
            $lifecycle->decorateIntent(array_replace($readback, ['tasks' => []]));
            self::fail('an incomplete aggregate must fail closed after task scope drifts');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('事件链损坏', $exception->getMessage());
        }
        try {
            $lifecycle->eventsForIntent(10, 20, (int)$intent['id']);
            self::fail('a direct event read must fail closed after task scope drifts');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('事件链损坏', $exception->getMessage());
        }
    }

    public function testLifecycleWriteControllerAndMigrationKeepExactHotelAndDuplicateFailureContracts(): void
    {
        $controller = (string)file_get_contents(dirname(__DIR__) . '/app/controller/OperationManagement.php');
        self::assertSame(6, substr_count($controller, 'resolveRequiredWriteHotelScope($input)'));
        self::assertStringContainsString('hotel_id 与 system_hotel_id 不一致', $controller);
        self::assertStringContainsString('运营写入必须明确指定 hotel_id', $controller);
        self::assertMatchesRegularExpression(
            '/if \(\$e instanceof \\\\InvalidArgumentException\) \{\s*return 422;/s',
            $controller
        );

        $migration = (string)file_get_contents(
            dirname(__DIR__) . '/database/migrations/20260824_enforce_one_operation_execution_task_per_intent.sql'
        );
        self::assertStringContainsString('HAVING COUNT(*) > 1', $migration);
        self::assertStringContainsString("SIGNAL SQLSTATE '45000'", $migration);
        $usesAtomicBlock = str_contains($migration, 'BEGIN NOT ATOMIC')
            && !str_contains($migration, 'CREATE PROCEDURE')
            && !str_contains($migration, 'DROP PROCEDURE');
        $usesValidatedProcedure = str_contains($migration, 'CREATE PROCEDURE')
            && str_contains($migration, 'CALL `suxios_validate_operation_task_uniqueness`()')
            && str_contains($migration, 'DROP PROCEDURE');
        self::assertTrue(
            $usesAtomicBlock || $usesValidatedProcedure,
            'migration must fail closed on duplicates using one supported SQL control-flow form'
        );
        self::assertStringContainsString('ADD COLUMN IF NOT EXISTS `unique_intent_id`', $migration);
        self::assertStringContainsString('ADD UNIQUE INDEX IF NOT EXISTS `uq_operation_execution_tasks_intent_once`', $migration);
        self::assertDoesNotMatchRegularExpression('/\b(?:DELETE|UPDATE)\s+`?operation_execution_tasks`?/i', $migration);
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

        try {
            (new OperationManagementService())->approveExecutionIntent(
                (int)$created['execution_intent']['id'],
                true,
                '来源漂移后不应批准',
                8,
                [20],
                $this->humanApprovalInput($created['execution_intent'])
            );
            self::fail('tampered question digest must block approval');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('question_readback_digest_mismatch', $exception->getMessage());
        }
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->count());
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
            'risk_controls' => ['仅做人工复核，不自动修改 OTA'],
            'stop_conditions' => ['酒店、平台、日期或指标单位不一致时停止'],
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

    public function testEquivalentApprovedActionIsRejectedAndNeverCreatesSecondTask(): void
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
            $this->humanApprovalInput($firstIntent, [
                'expected_metric' => 'list_exposure',
                'expected_direction' => 'observe',
                'target_type' => 'observation',
                'review_business_date' => substr((string)$schedule['review_at'], 0, 10),
                'assignee_id' => 8,
                'due_at' => (string)$schedule['due_at'],
                'review_at' => (string)$schedule['review_at'],
            ])
        );

        $secondQuestion = $questionService->create(
            10,
            20,
            '2026-08-12 携程列表曝光用户数是多少？',
            'ctrip',
            '2026-08-12',
            '2026-08-12',
            7
        );
        try {
            $bridge->createIntent((int)$secondQuestion['question']['id'], 0, 10, [20], 7);
            self::fail('an equivalent action with a completed approval must be rejected before another intent is created');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('已经结束审批', $exception->getMessage());
        }
        self::assertSame(1, (int)Db::name('operation_execution_tasks')->count());
        self::assertSame(1, (int)Db::name('operation_execution_intents')->count());
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
            $this->humanApprovalInput($intent, [
                'expected_metric' => 'list_exposure',
                'expected_direction' => 'increase',
                'target_type' => 'delta',
                'expected_delta' => 100,
                'review_business_date' => substr($reviewAt, 0, 10),
                'assignee_id' => 8,
                'due_at' => $dueAt,
                'review_at' => $reviewAt,
            ])
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
                    'executed_by' => 'user#8',
                    'executed_at' => date('Y-m-d H:i:s'),
                    'execution_status' => 'executed',
                    'completed_action' => '负责人已人工核对携程列表入口与展示配置，未由系统修改 OTA。',
                    'platform_receipt_id' => 'ctrip-manual-receipt-20260812',
                    'formal_record_ref' => 'operation-log#list-exposure-20260812',
                    'screenshot_ref' => '/evidence/operation-question-list-exposure-check.png',
                    'automatic_execution' => false,
                    'automatic_ota_write' => false,
                ],
                'attachment_path' => '/evidence/operation-question-list-exposure-check.png',
                'remark' => '执行动作及人工边界已记录',
            ],
        ], 8);
        self::assertSame('executed', $completed['status']);
        self::assertSame('completed', $completed['action_management']['lifecycle']['status']);
        self::assertCount(1, $completed['evidence']);
        $executionEvidence = $completed['evidence'][0];
        self::assertSame('manual_operation_execution', $executionEvidence['evidence_type']);
        self::assertSame('/evidence/operation-question-list-exposure-check.png', $executionEvidence['attachment_path']);
        self::assertSame('ctrip-manual-receipt-20260812', $executionEvidence['platform_response']['platform_receipt_id']);
        self::assertSame('operation-log#list-exposure-20260812', $executionEvidence['platform_response']['formal_record_ref']);
        self::assertSame('/evidence/operation-question-list-exposure-check.png', $executionEvidence['platform_response']['screenshot_ref']);
        self::assertSame('user#8', $executionEvidence['platform_response']['executed_by']);
        self::assertNotEmpty($executionEvidence['platform_response']['executed_at']);

        $withAttachment = $management->addExecutionEvidence($taskId, [20], [
            'evidence_type' => 'manual_operation_execution',
            'evidence' => [
                'platform_response' => [
                    'mode' => 'manual_operation_execution',
                    'executed_by' => 'user#8',
                    'executed_at' => date('Y-m-d H:i:s'),
                    'execution_status' => 'executed',
                    'completed_action' => '负责人已补充人工执行截图引用。',
                    'automatic_execution' => false,
                    'automatic_ota_write' => false,
                ],
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
        try {
            $management->reviewExecutionTask($taskId, [20], [
                'result_status' => 'observing',
                'result_summary' => '执行证据已保存，但新的同口径来源回读尚未到达。',
            ], 8);
            self::fail('managed effect review without same-scope source readback must fail closed');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('same-hotel, same-platform and same-metric', $exception->getMessage());
        }
        $waitingForReadback = $management->readExecutionTask($taskId, [20]);
        self::assertSame('completed', $waitingForReadback['action_management']['lifecycle']['status']);
        self::assertNull($waitingForReadback['action_management']['latest_review']);
        self::assertSame(0, (int)Db::name('operation_action_reviews')->count());

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
                    'source_path' => '$.data.listExposure',
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
        $managedReview = $reviewed['action_management']['latest_review'];
        $strictReview = $reviewed['active_effect_review'];
        self::assertSame('list_exposure', $managedReview['metric_key']);
        self::assertSame('visitor_count', $managedReview['metric_unit']);
        self::assertSame(1800.0, $managedReview['before_value']);
        self::assertSame(1950.0, $managedReview['after_value']);
        self::assertSame(150.0, $managedReview['delta_value']);
        self::assertSame((int)$strictReview['id'], (int)$managedReview['effect_review_id']);
        self::assertSame('met', $strictReview['outcome_status']);
        self::assertSame(150.0, (float)$strictReview['outcome']['actual_delta']);
        self::assertFalse($strictReview['causality_claimed']);
        self::assertFalse($strictReview['outcome']['causality_claimed']);
        self::assertContains(
            'operation_effect_reviews#' . (int)$strictReview['id'],
            $managedReview['evidence_refs']
        );
        self::assertContains(
            'observational_before_after_no_control_group',
            $managedReview['non_attribution_reasons']
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
        self::assertSame($managedReview['content_digest'], $exact['action_management']['latest_review']['content_digest']);
        self::assertSame($strictReview['content_digest'], $exact['active_effect_review']['content_digest']);
        self::assertFalse($exact['action_management']['historical_records_mutated']);
        self::assertFalse($exact['action_management']['external_action_performed']);

        $reconciledAgain = $management->reconcileScheduledExecutionTask($taskId, [20]);
        self::assertSame('already_reviewed', $reconciledAgain['status']);
        $reviewedAgain = $management->reviewExecutionTask($taskId, [20], [
            'result_status' => 'success',
            'result_summary' => '同酒店、同携程、同指标的新回读窗口显示列表曝光增加 150。',
        ], 8);
        self::assertSame($managedReview['content_digest'], $reviewedAgain['action_management']['latest_review']['content_digest']);
        self::assertSame(1, (int)Db::name('operation_effect_reviews')->count());
        self::assertSame(1, (int)Db::name('operation_action_reviews')->count());
    }

    public function testVerificationOnlyMeituanActionUsesObservationApprovalAndRealDetailExposureReview(): void
    {
        Db::name('online_daily_data')->insert([
            'id' => 1301,
            'tenant_id' => 10,
            'system_hotel_id' => 20,
            'data_date' => '2026-08-12',
            'platform' => 'meituan',
            'source' => 'meituan',
            'data_type' => 'traffic',
            'dimension' => 'catalog:traffic_report:meituan_traffic_overview:detail_exposure',
            'validation_status' => 'verified',
            'history_status' => 'success',
            'readback_verified' => 1,
            'readback_verified_at' => '2026-08-12 10:00:00',
            'ingestion_method' => 'browser_profile',
            'source_trace_id' => 'meituan-observation-baseline',
            'data_source_id' => 68,
            'data_period' => 'historical_daily',
            'is_final' => 1,
            'compare_type' => 'self',
            'collected_at' => '2026-08-12 10:00:00',
            'list_exposure' => null,
            'detail_exposure' => 201,
            'raw_data' => json_encode([
                'endpoint_id' => 'meituan_traffic_overview',
                'source_trace_id' => 'meituan-observation-baseline',
                'source_url_hash' => str_repeat('c', 64),
                'field_facts' => [[
                    'metric_key' => 'detail_exposure',
                    'source_key' => 'clicks',
                    'status' => 'captured',
                    'stored_value_present' => true,
                    'source_path' => 'traffic.clicks',
                    'storage_field' => 'online_daily_data.detail_exposure',
                    'capture_evidence' => [
                        'source_trace_id' => 'meituan-observation-baseline',
                        'source_url_hash' => str_repeat('c', 64),
                    ],
                ]],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $questionService = $this->readyDetailExposureQuestionService();
        $saved = $questionService->create(
            10,
            20,
            '2026-08-12 美团详情曝光应复核什么？',
            'meituan',
            '2026-08-12',
            '2026-08-12',
            7
        );
        $management = new OperationManagementService();
        $created = (new OperatingQuestionExecutionBridgeService(
            $questionService,
            $management
        ))->createIntent((int)$saved['question']['id'], 0, 10, [20], 7);
        $intent = $created['execution_intent'];
        self::assertSame('pending_approval', $intent['status']);
        self::assertSame('detail_exposure', $intent['expected_metric']);
        self::assertSame('verification_target', $intent['evidence']['decision_recommendation']['expected_effect']['status']);
        self::assertSame('verify', $intent['evidence']['decision_recommendation']['expected_effect']['direction']);

        $timezone = new \DateTimeZone('Asia/Shanghai');
        $now = new \DateTimeImmutable('now', $timezone);
        $dueAt = $now->modify('+2 seconds')->format('Y-m-d H:i:s');
        $reviewAt = $now->modify('+4 seconds')->format('Y-m-d H:i:s');
        $approved = $management->approveExecutionIntent(
            (int)$intent['id'],
            true,
            '人工确认仅观察同口径变化，不承诺提升幅度',
            8,
            [20],
            $this->humanApprovalInput($intent, [
                'expected_metric' => 'detail_exposure',
                'expected_direction' => 'observe',
                'target_type' => 'observation',
                'review_business_date' => substr($reviewAt, 0, 10),
                'assignee_id' => 8,
                'due_at' => $dueAt,
                'review_at' => $reviewAt,
            ])
        );
        $contract = $approved['evidence']['approval_target'];
        self::assertSame('operation_observation_approval_target.v1', $contract['version']);
        self::assertSame('observe', $contract['expected_direction']);
        self::assertSame('observation', $contract['target_type']);
        self::assertSame('observation_only', $contract['expected_delta_status']);
        self::assertNull($contract['target_value']);
        self::assertNull($contract['expected_delta']);
        self::assertNull($approved['expected_delta']);
        self::assertSame('exposure_count', $contract['metric_definition']['unit']);
        self::assertSame('approved', $approved['action_management']['lifecycle']['status']);
        self::assertSame('observe', $approved['action_management']['action_card']['metric_contract']['expected_direction']);
        self::assertSame('observation', $approved['action_management']['action_card']['metric_contract']['target_type']);
        self::assertCount(1, $approved['tasks']);

        $taskId = (int)$approved['tasks'][0]['id'];
        $started = $management->executeExecutionTask($taskId, [20], ['status' => 'executing'], 8);
        self::assertSame('in_progress', $started['action_management']['lifecycle']['status']);
        $completed = $management->executeExecutionTask($taskId, [20], [
            'status' => 'executed',
            'evidence_type' => 'manual_operation_execution',
            'evidence' => [
                'platform_response' => [
                    'mode' => 'manual_operation_execution',
                    'executed_by' => 'user#8',
                    'executed_at' => date('Y-m-d H:i:s'),
                    'execution_status' => 'executed',
                    'completed_action' => '负责人已人工复核美团详情曝光入口并保存核对结果。',
                    'automatic_execution' => false,
                    'automatic_ota_write' => false,
                ],
                'remark' => '仅记录人工核验动作；系统未修改 OTA、房价或房态。',
            ],
        ], 8);
        self::assertSame('completed', $completed['action_management']['lifecycle']['status']);

        $reviewTimestamp = strtotime($reviewAt);
        self::assertNotFalse($reviewTimestamp);
        while (time() < $reviewTimestamp) {
            usleep(100000);
        }
        $readbackAt = date('Y-m-d H:i:s', max(time(), $reviewTimestamp));
        $reviewDate = substr($reviewAt, 0, 10);
        Db::name('online_daily_data')->insert([
            'id' => 1302,
            'tenant_id' => 10,
            'system_hotel_id' => 20,
            'data_date' => $reviewDate,
            'platform' => 'meituan',
            'source' => 'meituan',
            'data_type' => 'traffic',
            'dimension' => 'catalog:traffic_report:meituan_traffic_overview:detail_exposure',
            'validation_status' => 'verified',
            'history_status' => 'success',
            'readback_verified' => 1,
            'readback_verified_at' => $readbackAt,
            'ingestion_method' => 'browser_profile',
            'source_trace_id' => 'meituan-observation-followup',
            'data_source_id' => 68,
            'data_period' => 'realtime_snapshot',
            'is_final' => 0,
            'compare_type' => 'self',
            'collected_at' => $readbackAt,
            'list_exposure' => null,
            'detail_exposure' => 245,
            'raw_data' => json_encode([
                'endpoint_id' => 'meituan_traffic_overview',
                'source_trace_id' => 'meituan-observation-followup',
                'source_url_hash' => str_repeat('d', 64),
                'field_facts' => [[
                    'metric_key' => 'detail_exposure',
                    'source_key' => 'clicks',
                    'status' => 'captured',
                    'stored_value_present' => true,
                    'source_path' => 'traffic.clicks',
                    'storage_field' => 'online_daily_data.detail_exposure',
                    'capture_evidence' => [
                        'source_trace_id' => 'meituan-observation-followup',
                        'source_url_hash' => str_repeat('d', 64),
                    ],
                ]],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $reconciled = $management->reconcileScheduledExecutionTask($taskId, [20]);
        self::assertSame('source_readback_verified', $reconciled['status']);
        self::assertTrue($reconciled['source_verified']);
        try {
            $management->reviewExecutionTask($taskId, [20], [
                'result_status' => 'success',
                'result_summary' => '观察目标不能被改写为成功结论。',
            ], 8);
            self::fail('observation contract must not accept a terminal success claim');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('must remain observing', $exception->getMessage());
        }
        $reviewed = $management->reviewExecutionTask($taskId, [20], [
            'result_status' => 'observing',
            'result_summary' => '同酒店、同美团、同指标回读显示详情曝光由 201 变为 245；仅确认变化，不主张归因。',
        ], 8);
        self::assertSame('observing', $reviewed['result_status']);
        self::assertSame('reviewed', $reviewed['action_management']['lifecycle']['status']);
        self::assertSame('sufficient', $reviewed['action_management']['latest_review']['evidence_sufficiency']);
        self::assertSame('increased', $reviewed['action_management']['latest_review']['metric_change_status']);
        self::assertSame(44.0, $reviewed['action_management']['latest_review']['delta_value']);
        self::assertSame('adjust', $reviewed['action_management']['latest_review']['recommendation']);
        self::assertFalse($reviewed['action_management']['latest_review']['causality_claimed']);
        self::assertContains(
            'observational_before_after_no_control_group',
            $reviewed['action_management']['latest_review']['non_attribution_reasons']
        );
        self::assertSame(0, (int)Db::name('operation_effect_reviews')->count());

        $exact = $management->readExecutionTask($taskId, [20]);
        self::assertSame($taskId, (int)$exact['id']);
        self::assertSame(
            'hotel_operating_questions#' . (int)$saved['question']['id'],
            $exact['action_management']['traceability']['question_ref']
        );
        self::assertCount(2, $exact['action_management']['traceability']['evidence_refs']);
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
            '低置信度回答能直接创建任务吗？',
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
                [20],
                $this->humanApprovalInput($firstIntent['execution_intent'])
            );
            self::fail('revoked fact status must block approval');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('来源', $exception->getMessage());
        }
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->count());

        Db::name('online_daily_data')->where('id', 1201)->update(['validation_status' => 'verified']);
        // Reuse the same still-pending lifecycle. The stable business identity
        // deliberately prevents a second intent from being created merely to
        // test another source drift mode.
        $secondIntent = $firstIntent;
        Db::name('online_daily_data')->where('id', 1201)->update(['list_exposure' => 1900]);
        try {
            (new OperationManagementService())->approveExecutionIntent(
                (int)$secondIntent['execution_intent']['id'],
                true,
                '漂移指标不应获批',
                8,
                [20],
                $this->humanApprovalInput($secondIntent['execution_intent'])
            );
            self::fail('changed metric value must block approval');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('来源', $exception->getMessage());
        }
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->count());

        Db::name('online_daily_data')->where('id', 1201)->update([
            'list_exposure' => 1800,
            'data_date' => '2026-08-11',
        ]);
        try {
            (new OperationManagementService())->approveExecutionIntent(
                (int)$secondIntent['execution_intent']['id'],
                true,
                '营业日漂移不应获批',
                8,
                [20],
                $this->humanApprovalInput($secondIntent['execution_intent'])
            );
            self::fail('changed source business date must block approval');
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
        bool $includeLegacyHumanApprovalText = false
    ): OperatingQuestionService
    {
        $fakeClient = new class($confidence, $includeLegacyHumanApprovalText) extends LlmClient {
            private int $calls = 0;

            public function __construct(
                private readonly string $confidence,
                private readonly bool $includeLegacyHumanApprovalText
            )
            {
            }

            public function createJsonResponseEnvelope(
                array $messages,
                array $schema,
                string $modelKey = OperatingQuestionAiAnswerService::DIRECT_MODEL_KEY
            ): array {
                $this->calls++;
                $draft = [
                    'expected_metric' => 'list_exposure',
                    'expected_metric_definition_id' => 'ota_list_exposure_users.v1',
                    'evidence_refs' => ['online_daily_data#1201'],
                ];
                if ($this->includeLegacyHumanApprovalText) {
                    $draft['execution_steps'] = ['由用户审批本草案，确认负责人和复核窗口。'];
                    $draft['risk_controls'] = ['所有步骤需用户审批后执行'];
                    $draft['stop_conditions'] = ['负责人未获得用户书面审批前不得执行'];
                }
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
                        'action_drafts' => [$draft],
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

    /**
     * @param array<string,mixed> $intent
     * @param array<string,mixed> $approvalInput
     * @return array<string,mixed>
     */
    private function humanApprovalInput(array $intent, array $approvalInput = []): array
    {
        $actionCard = is_array($intent['target_value']['action_card'] ?? null)
            ? $intent['target_value']['action_card']
            : [];

        return array_merge($approvalInput, [
            'confirmed' => true,
            'confirmation_version' => OperationActionLifecycleService::APPROVAL_CONFIRMATION_VERSION,
            'confirmed_intent_id' => (int)($intent['id'] ?? 0),
            'confirmed_action_digest' => (string)($actionCard['content_digest'] ?? ''),
        ]);
    }

    private function aiReviewer(string $decision): OperationActionAiReviewService
    {
        $fakeClient = new class($decision) extends LlmClient {
            public function __construct(private readonly string $decision)
            {
            }

            public function createJsonResponseEnvelope(
                array $messages,
                array $schema,
                string $modelKey = 'deepseek_v4_flash'
            ): array {
                if ($this->decision === 'unavailable') {
                    throw new \RuntimeException('review provider unavailable');
                }
                $approved = $this->decision === 'approve';
                return [
                    'data' => [
                        'decision' => $approved ? 'approve' : 'reject',
                        'summary' => $approved
                            ? '事实范围和单位一致，可以创建本地人工执行任务。'
                            : '风险控制不足，当前不应创建运营任务。',
                        'evidence_refs' => ['online_daily_data#1201'],
                        'risk_findings' => $approved ? [] : ['风险控制不足'],
                        'blocking_reasons' => $approved ? [] : ['risk_control_insufficient'],
                    ],
                    'meta' => [
                        'provider' => 'deepseek',
                        'model_key' => 'deepseek_v4_flash',
                        'model' => 'deepseek-v4-flash',
                        'finish_reason' => 'stop',
                        'fallback_used' => false,
                        'cache_hit' => false,
                        'degraded' => false,
                    ],
                ];
            }
        };
        return new OperationActionAiReviewService($fakeClient, 'deepseek_v4_flash');
    }

    /** @return array<string,mixed> */
    private static function detailExposureDefinition(): array
    {
        $identity = [
            'data_type' => 'traffic',
            'metric_key' => 'detail_exposure',
            'source_metric_key' => 'detail_exposure',
            'source_key' => 'clicks',
            'source_path' => 'traffic.clicks',
            'storage_field' => 'online_daily_data.detail_exposure',
            'status' => 'captured',
            'stored_value_present' => true,
            'unit' => 'exposure_count',
        ];
        ksort($identity, SORT_STRING);
        return [
            'claimable' => true,
            'definition_id' => 'ota_detail_exposure.v1',
            'source_metric_key' => 'detail_exposure',
            'source_data_type' => 'traffic',
            'source_key' => 'clicks',
            'storage_field' => 'online_daily_data.detail_exposure',
            'source_path_digest' => hash('sha256', 'traffic.clicks'),
            'field_fact_digest' => hash('sha256', json_encode(
                $identity,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
            )),
            'unit' => 'exposure_count',
            'unit_status' => 'verified',
            'unit_source' => 'operating_question_metric_semantics.v1',
            'label' => '详情曝光',
        ];
    }

    private function readyDetailExposureQuestionService(): OperatingQuestionService
    {
        $fakeClient = new class extends LlmClient {
            public function createJsonResponseEnvelope(
                array $messages,
                array $schema,
                string $modelKey = OperatingQuestionAiAnswerService::DIRECT_MODEL_KEY
            ): array {
                return [
                    'data' => [
                        'fact_claims' => [[
                            'evidence_ref' => 'online_daily_data#1301',
                            'metric_key' => 'detail_exposure',
                            'metric_definition_id' => 'ota_detail_exposure.v1',
                            'value' => 201,
                            'unit' => 'exposure_count',
                        ]],
                        'follow_up_questions' => [],
                        'confidence' => 'medium',
                        'action_drafts' => [[
                            'expected_metric' => 'detail_exposure',
                            'expected_metric_definition_id' => 'ota_detail_exposure.v1',
                            'evidence_refs' => ['online_daily_data#1301'],
                        ]],
                    ],
                    'meta' => OperatingQuestionExecutionBridgeServiceTest::directMeta('resp-bridge-1301-0001'),
                ];
            }
        };
        $ai = new OperatingQuestionAiAnswerService($fakeClient);
        return new OperatingQuestionService(
            static fn(): array => [
                'facts' => [[
                    'ref' => 'online_daily_data#1301',
                    'data_date' => '2026-08-12',
                    'platform' => 'meituan',
                    'data_type' => 'traffic',
                    'quality_status' => 'verified',
                    'history_status' => 'success',
                    'readback_status' => 'readback_verified',
                    'readback_verified_at' => '2026-08-12 10:00:00',
                    'ingestion_method' => 'browser_profile',
                    'source_trace_id' => 'meituan-observation-baseline',
                    'metric_values' => ['detail_exposure' => 201],
                    'metric_units' => ['detail_exposure' => 'exposure_count'],
                    'metric_definitions' => [
                        'detail_exposure' => self::detailExposureDefinition(),
                    ],
                ]],
                'fact_count' => 1,
            ],
            static fn(array $payload): array => $ai->generate($payload)
        );
    }

    public function testAcceptedDirectProofDoesNotExpireWhileNewReceiptFreshnessStillDoes(): void
    {
        $persisted = self::directMeta('resp-bridge-persisted-0001');
        $persisted['provider_created_at'] = time() - 3600;

        self::assertTrue(OperatingQuestionAiAnswerService::directCallProofReady($persisted));
        self::assertFalse(OperatingQuestionAiAnswerService::directCallReceiptFreshNow($persisted));
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
            $this->humanApprovalInput($intent, [
                'expected_metric' => 'list_exposure',
                'expected_direction' => 'increase',
                'target_type' => 'delta',
                'expected_delta' => 100,
                'review_business_date' => substr((string)$schedule['review_at'], 0, 10),
                'assignee_id' => 8,
                'due_at' => (string)$schedule['due_at'],
                'review_at' => (string)$schedule['review_at'],
            ])
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

    public function testLifecycleEventContentRetryKeepsOneStableSequenceMarker(): void
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
        $intent = $created['execution_intent'];
        $lifecycle = new OperationActionLifecycleService();
        $payload = [
            'marker' => 'manual_review_opened',
            'external_action_performed' => false,
        ];

        $first = $lifecycle->appendEvent(
            $intent,
            0,
            'pending_approval',
            'pending_approval',
            'manual_review_opened',
            7,
            $payload
        );
        $retry = $lifecycle->appendEvent(
            $intent,
            0,
            'pending_approval',
            'pending_approval',
            'manual_review_opened',
            7,
            $payload
        );

        self::assertSame($first['id'], $retry['id']);
        self::assertSame($first['sequence_no'], $retry['sequence_no']);
        self::assertSame($first['content_digest'], $retry['content_digest']);
        self::assertSame(3, $retry['sequence_no']);

        $events = $lifecycle->eventsForIntent(10, 20, (int)$intent['id']);
        self::assertCount(3, $events);
        self::assertSame([1, 2, 3], array_column($events, 'sequence_no'));
        self::assertSame(
            1,
            count(array_filter(
                $events,
                static fn(array $event): bool => (string)$event['event_type'] === 'manual_review_opened'
            ))
        );
        self::assertSame('pending_approval', $lifecycle->currentStatus($intent, $events));

        $readback = (new OperationManagementService())->readExecutionIntent((int)$intent['id'], [20]);
        self::assertSame('pending_approval', $readback['action_management']['lifecycle']['status']);
        self::assertSame('verified', $readback['action_management']['lifecycle']['integrity_status']);
        self::assertSame(3, $readback['action_management']['lifecycle']['event_count']);
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
            $this->humanApprovalInput($firstIntent, [
                'expected_metric' => 'list_exposure',
                'expected_direction' => 'increase',
                'target_type' => 'delta',
                'expected_delta' => 100,
                'review_business_date' => substr((string)$schedule['review_at'], 0, 10),
                'assignee_id' => 8,
                'due_at' => (string)$schedule['due_at'],
                'review_at' => (string)$schedule['review_at'],
            ])
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
        try {
            $bridge->createIntent((int)$secondQuestion['question']['id'], 0, 10, [20], 7);
            self::fail('equivalent active action must be blocked before a second approval lifecycle is created');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('已经结束审批', $exception->getMessage());
        }
        self::assertSame(1, (int)Db::name('operation_execution_tasks')->count());
        self::assertSame(
            'approved',
            (string)Db::name('operation_execution_intents')->where('id', (int)$firstIntent['id'])->value('status')
        );
    }
}
