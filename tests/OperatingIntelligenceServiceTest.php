<?php
declare(strict_types=1);

namespace Tests;

use app\service\LlmClient;
use app\service\OperatingQuestionAiAnswerService;
use app\service\OperatingQuestionService;
use app\service\OperatingSopService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OperatingIntelligenceServiceTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App(dirname(__DIR__));
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'operating_intelligence_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';
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
            'hotel_operating_sop_replications',
            'hotel_operating_sop_versions',
            'hotel_operating_questions',
            'hotel_operating_memories',
            'online_daily_data',
            'hotels',
        ] as $table) {
            Db::execute('DROP TABLE IF EXISTS ' . $table);
        }
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT, status INTEGER NOT NULL)');
        Db::execute("INSERT INTO hotels (id,tenant_id,name,status) VALUES (20,10,'source',1),(21,10,'target',1),(22,10,'empty target',1),(30,11,'other tenant',1)");
        Db::execute(
            'CREATE TABLE hotel_operating_questions ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, request_key TEXT, question_text TEXT, '
            . 'platform TEXT, date_start TEXT, date_end TEXT, answer_status TEXT, answer_summary TEXT, answer_json TEXT, '
            . 'fact_refs_json TEXT, memory_refs_json TEXT, knowledge_refs_json TEXT, execution_refs_json TEXT, data_gaps_json TEXT, '
            . 'content_digest TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT, '
            . 'UNIQUE(tenant_id,hotel_id,request_key))'
        );
        Db::execute(
            'CREATE TABLE hotel_operating_memories ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, memory_layer TEXT, '
            . 'platform TEXT, source_scope TEXT, source_record_id INTEGER, business_date TEXT, title TEXT, summary TEXT, context_json TEXT, '
            . 'quality_status TEXT, usage_level TEXT, lifecycle_status TEXT, deleted_at TEXT)'
        );
        Db::execute(
            'CREATE TABLE hotel_operating_sop_versions ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, sop_key TEXT, version_no INTEGER, '
            . 'previous_version_id INTEGER, title TEXT, objective TEXT, steps_json TEXT, stop_conditions_json TEXT, scope_json TEXT, '
            . 'source_memory_ids_json TEXT, evidence_refs_json TEXT, validation_status TEXT, validation_note TEXT, content_digest TEXT, '
            . 'lifecycle_status TEXT, created_by INTEGER, validated_by INTEGER, validated_at TEXT, created_at TEXT, updated_at TEXT, '
            . 'deleted_at TEXT, UNIQUE(tenant_id,hotel_id,sop_key,version_no))'
        );
        Db::execute(
            'CREATE TABLE hotel_operating_sop_replications ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, source_sop_version_id INTEGER, source_hotel_id INTEGER, '
            . 'target_hotel_id INTEGER, status TEXT, target_validation_status TEXT, draft_json TEXT, target_fact_refs_json TEXT, '
            . 'data_gaps_json TEXT, content_digest TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT, '
            . 'UNIQUE(tenant_id,source_sop_version_id,target_hotel_id))'
        );
        Db::execute(
            'CREATE TABLE online_daily_data ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, system_hotel_id INTEGER, data_date TEXT, '
            . 'platform TEXT, source TEXT, data_type TEXT, dimension TEXT, readback_verified INTEGER, '
            . 'readback_verified_at TEXT, validation_status TEXT, history_status TEXT, ingestion_method TEXT, source_trace_id TEXT, '
            . 'raw_data TEXT, amount REAL DEFAULT 0, quantity INTEGER DEFAULT 0, book_order_num INTEGER DEFAULT 0, '
            . 'comment_score REAL DEFAULT 0, data_value REAL DEFAULT 0, list_exposure INTEGER DEFAULT 0, '
            . 'detail_exposure INTEGER DEFAULT 0, flow_rate REAL DEFAULT 0, order_filling_num INTEGER DEFAULT 0, '
            . 'order_submit_num INTEGER DEFAULT 0)'
        );
    }

    public function testOperatingQuestionRejectsPartialReadbackAndAcceptsOnlySuccessfulHistoryTruth(): void
    {
        Db::name('online_daily_data')->insertAll([
            [
                'tenant_id' => 10,
                'system_hotel_id' => 20,
                'data_date' => '2026-08-01',
                'platform' => 'ctrip',
                'source' => 'ctrip',
                'data_type' => 'traffic',
                'dimension' => '',
                'readback_verified' => 1,
                'readback_verified_at' => '2026-08-01 10:00:00',
                'validation_status' => 'normal',
                'history_status' => 'partial',
                'ingestion_method' => 'legacy',
                'source_trace_id' => '',
            ],
        ]);

        $factReader = new OperatingQuestionService();
        $loadFacts = new \ReflectionMethod($factReader, 'loadFacts');
        $loadFacts->setAccessible(true);
        $partialFacts = $loadFacts->invoke($factReader, 10, 20, 'ctrip', '2026-08-01', '2026-08-01');
        self::assertSame([], $partialFacts);

        $blocked = (new OperatingQuestionService(static fn(): array => [
            'facts' => $partialFacts,
            'fact_count' => count($partialFacts),
        ]))->create(
            10,
            20,
            '旧来源回读能否形成结论？',
            'ctrip',
            '2026-08-01',
            '2026-08-01',
            7
        );
        self::assertSame('blocked_by_missing_facts', $blocked['question']['answer_status']);
        self::assertSame([], $blocked['question']['fact_refs']);
        self::assertSame('waiting_for_verified_fact', $blocked['question']['answer']['recovery_plan']['status']);
        self::assertSame([
            [
                'platform' => 'ctrip',
                'date' => '2026-08-01',
                'required_gate' => 'history_success+validation_verified+readback_verified',
            ],
        ], $blocked['question']['answer']['recovery_plan']['missing_items']);
        self::assertSame(
            ['open_data_health', 'open_platform_collection_status', 'recheck'],
            array_column($blocked['question']['answer']['recovery_plan']['actions'], 'key')
        );
        self::assertTrue($blocked['question']['answer']['recovery_plan']['actions'][0]['read_only']);
        self::assertFalse($blocked['question']['answer']['recovery_plan']['boundaries']['automatic_collection']);

        Db::name('online_daily_data')->insert([
            'tenant_id' => 10,
            'system_hotel_id' => 20,
            'data_date' => '2026-08-01',
            'platform' => 'ctrip',
            'source' => 'ctrip',
            'data_type' => 'traffic',
            'dimension' => '',
            'readback_verified' => 1,
            'readback_verified_at' => '2026-08-01 10:05:00',
            'validation_status' => 'verified',
            'history_status' => 'success',
            'ingestion_method' => 'browser_profile',
            'source_trace_id' => 'trace-ctrip-20260801',
            'list_exposure' => 1200,
            'detail_exposure' => 240,
            'flow_rate' => 20.0,
        ]);

        $trustedFacts = $loadFacts->invoke($factReader, 10, 20, 'ctrip', '2026-08-01', '2026-08-01');
        self::assertCount(1, $trustedFacts);
        $ready = (new OperatingQuestionService(static fn(): array => [
            'facts' => $trustedFacts,
            'fact_count' => count($trustedFacts),
        ]))->create(
            10,
            20,
            '可信来源回读能否形成证据摘要？',
            'ctrip',
            '2026-08-01',
            '2026-08-01',
            7
        );
        self::assertSame('evidence_ready', $ready['question']['answer_status']);
        self::assertSame('not_required', $ready['question']['answer']['recovery_plan']['status']);
        self::assertSame([], $ready['question']['answer']['recovery_plan']['actions']);
        self::assertCount(1, $ready['question']['fact_refs']);
        self::assertSame('success', $ready['question']['answer']['fact_samples'][0]['history_status']);
        self::assertSame([
            'list_exposure' => 1200,
            'detail_exposure' => 240,
            'flow_rate' => 20.0,
        ], $ready['question']['answer']['fact_samples'][0]['metric_values']);
        self::assertSame([
            'list_exposure' => 'exposure_count',
            'detail_exposure' => 'exposure_count',
            'flow_rate' => 'source_defined_rate',
        ], $ready['question']['answer']['fact_samples'][0]['metric_units']);

        Db::name('online_daily_data')->insertAll([
            [
                'tenant_id' => 10,
                'system_hotel_id' => 20,
                'data_date' => '2026-08-05',
                'platform' => 'ctrip',
                'source' => 'ctrip',
                'data_type' => 'traffic',
                'dimension' => '',
                'readback_verified' => 1,
                'readback_verified_at' => '2026-08-05 10:00:00',
                'validation_status' => 'verified',
                'history_status' => 'success',
                'ingestion_method' => 'browser_profile',
                'source_trace_id' => 'trace-default-zero',
                'list_exposure' => 0,
                'raw_data' => null,
            ],
            [
                'tenant_id' => 10,
                'system_hotel_id' => 20,
                'data_date' => '2026-08-06',
                'platform' => 'ctrip',
                'source' => 'ctrip',
                'data_type' => 'traffic',
                'dimension' => '',
                'readback_verified' => 1,
                'readback_verified_at' => '2026-08-06 10:00:00',
                'validation_status' => 'verified',
                'history_status' => 'success',
                'ingestion_method' => 'browser_profile',
                'source_trace_id' => 'trace-observed-zero',
                'list_exposure' => 0,
                'raw_data' => json_encode([
                    'field_facts' => [[
                        'metric_key' => 'list_exposure',
                        'source_path' => 'payload.listExposure',
                        'storage_field' => 'online_daily_data.list_exposure',
                        'status' => 'captured',
                        'stored_value_present' => true,
                    ]],
                ], JSON_UNESCAPED_SLASHES),
            ],
        ]);
        $defaultZeroFacts = $loadFacts->invoke($factReader, 10, 20, 'ctrip', '2026-08-05', '2026-08-05');
        self::assertSame([], $defaultZeroFacts[0]['metric_values']);
        self::assertSame([], $defaultZeroFacts[0]['metric_units']);
        $observedZeroFacts = $loadFacts->invoke($factReader, 10, 20, 'ctrip', '2026-08-06', '2026-08-06');
        self::assertSame(['list_exposure' => 0], $observedZeroFacts[0]['metric_values']);
        self::assertSame(['list_exposure' => 'exposure_count'], $observedZeroFacts[0]['metric_units']);

        $observedMetricFields = new \ReflectionMethod($factReader, 'observedMetricFields');
        $observedMetricFields->setAccessible(true);
        $fieldFact = [
            'source_path' => 'payload.listExposure',
            'storage_field' => 'online_daily_data.list_exposure',
            'stored_value_present' => true,
        ];
        self::assertSame(
            ['list_exposure' => true],
            $observedMetricFields->invoke(null, ['field_facts' => [[...$fieldFact, 'status' => 'captured']]])
        );
        foreach (['unverified', 'failed', ''] as $status) {
            self::assertSame(
                [],
                $observedMetricFields->invoke(null, ['field_facts' => [[...$fieldFact, 'status' => $status]]])
            );
        }
        self::assertSame(
            [],
            $observedMetricFields->invoke(null, ['field_facts' => [[
                ...$fieldFact,
                'status' => 'captured',
                'stored_value_present' => 'true',
            ]]])
        );
    }

    public function testOperatingQuestionSavesExactEvidenceReadbackAndVisibleMissingState(): void
    {
        $ready = new OperatingQuestionService(static fn(): array => [
            'facts' => [[
                'ref' => 'online_daily_data#701',
                'data_date' => '2026-08-01',
                'platform' => 'ctrip',
                'data_type' => 'traffic',
                'quality_status' => 'verified',
                'readback_status' => 'readback_verified',
            ]],
            'fact_count' => 1,
            'memories' => [['ref' => 'hotel_operating_memories#11']],
            'diagnoses' => [[
                'ref' => 'agent_logs#31',
                'platform' => 'ctrip',
                'record_status' => 'active',
                'saved' => true,
                'readback_verified' => true,
                'readback_identity_digest' => 'all-ota-readback-33',
                'saved_readback_identity_digest' => 'all-ota-readback-33',
                'requested_date_range' => ['start_date' => '2026-08-01', 'end_date' => '2026-08-01'],
                'effective_date_range' => ['start_date' => '2026-08-01', 'end_date' => '2026-08-01'],
                'used_latest_available_data' => false,
                'summary' => 'Saved diagnosis conclusion.',
            ]],
            'knowledge' => [['ref' => 'knowledge_units#40']],
            'executions' => [['ref' => 'operation_execution_task#51']],
        ]);
        $saved = $ready->create(10, 20, 'What should this hotel review?', 'ctrip', '2026-08-01', '2026-08-01', 7);
        self::assertTrue($saved['created']);
        self::assertSame('readback_verified', $saved['persistence_status']);
        self::assertSame('answered_from_saved_diagnosis', $saved['question']['answer_status']);
        self::assertSame('Saved diagnosis conclusion.', $saved['question']['answer_summary']);
        self::assertSame(['online_daily_data#701'], $saved['question']['fact_refs']);
        self::assertSame(['hotel_operating_memories#11'], $saved['question']['memory_refs']);
        self::assertFalse($saved['write_boundaries']['external_llm_called']);
        self::assertFalse($saved['write_boundaries']['ota_write']);
        self::assertFalse($saved['write_boundaries']['external_message']);

        $same = $ready->create(10, 20, 'What should this hotel review?', 'ctrip', '2026-08-01', '2026-08-01', 7);
        self::assertFalse($same['created']);
        self::assertSame($saved['question']['id'], $same['question']['id']);

        $missing = new OperatingQuestionService(static fn(): array => []);
        $blocked = $missing->create(10, 20, 'Is there evidence?', 'ctrip', '2099-01-01', '2099-01-01', 7);
        self::assertSame('blocked_by_missing_facts', $blocked['question']['answer_status']);
        self::assertSame('saved_verified_fact_missing', $blocked['question']['data_gaps'][0]['code']);
        self::assertSame('readback_verified', $blocked['persistence_status']);

        $this->expectException(\RuntimeException::class);
        $ready->create(11, 20, 'Cross tenant?', 'ctrip', '2026-08-01', '2026-08-01', 7);
    }

    public function testOperatingQuestionCanPersistOneGroundedAiAnswerWithoutChangingWriteBoundaries(): void
    {
        $fakeClient = new class extends LlmClient {
            public int $calls = 0;
            public string $lastPrompt = '';

            public function createJsonResponseEnvelope(
                array $messages,
                array $schema,
                string $modelKey = 'deepseek_v4_default'
            ): array {
                $this->calls++;
                $this->lastPrompt = json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                return [
                    'data' => [
                        'answer_summary' => '已读取目标日携程严格回读事实；当前应先复核已保存诊断中的流量问题，不能据此推断全酒店收入。',
                        'key_points' => ['目标日携程事实已完成严格回读。'],
                        'missing_information' => ['缺少PMS全酒店经营事实。'],
                        'follow_up_questions' => ['目标日已保存诊断的具体缺口是什么？'],
                        'confidence' => 'medium',
                        'used_evidence_refs' => ['online_daily_data#801', 'knowledge_chunks#91', 'invented#999'],
                        'action_drafts' => [[
                            'title' => '复核携程曝光到详情访问链路',
                            'action' => '人工复核目标日携程列表曝光、详情曝光及页面展示配置，并保存核对记录。',
                            'action_object' => '携程曝光到详情访问链路',
                            'execution_steps' => ['核对目标日列表曝光与详情曝光', '人工检查页面展示配置并记录差异'],
                            'priority' => 'P1',
                            'expected_metric' => 'list_exposure',
                            'review_window' => '完成复核后按同酒店同渠道同业务日口径再次回读',
                            'risk_level' => 'medium',
                            'risk_summary' => '单日流量波动可能受外部因素影响，不能直接归因为页面配置。',
                            'risk_controls' => ['人工确认对象和日期，不在本流程修改 OTA 配置'],
                            'stop_conditions' => ['发现酒店、渠道或业务日身份不一致时停止'],
                            'evidence_refs' => ['online_daily_data#801'],
                        ]],
                    ],
                    'meta' => [
                        'provider' => 'deepseek',
                        'model_key' => 'deepseek_v4_pro',
                        'model' => 'deepseek-v4-pro',
                        'finish_reason' => 'stop',
                        'fallback_used' => false,
                        'cache_hit' => false,
                        'degraded' => false,
                    ],
                ];
            }
        };
        $ai = new OperatingQuestionAiAnswerService($fakeClient);
        $service = new OperatingQuestionService(
            static fn(): array => [
                'facts' => [[
                    'ref' => 'online_daily_data#801',
                    'data_date' => '2026-08-02',
                    'platform' => 'ctrip',
                    'data_type' => 'traffic',
                    'quality_status' => 'verified',
                    'readback_status' => 'readback_verified',
                    'metric_values' => ['list_exposure' => 1800, 'detail_exposure' => 360],
                    'metric_units' => ['list_exposure' => 'exposure_count', 'detail_exposure' => 'exposure_count'],
                ]],
                'fact_count' => 1,
                'diagnoses' => [[
                    'ref' => 'agent_logs#81',
                    'platform' => 'ctrip',
                    'record_status' => 'active',
                    'saved' => true,
                    'readback_verified' => true,
                    'readback_identity_digest' => 'grounded-ai-readback',
                    'saved_readback_identity_digest' => 'grounded-ai-readback',
                    'requested_date_range' => ['start_date' => '2026-08-02', 'end_date' => '2026-08-02'],
                    'effective_date_range' => ['start_date' => '2026-08-02', 'end_date' => '2026-08-02'],
                    'used_latest_available_data' => false,
                    'summary' => '目标日流量问题需要复核。',
                ]],
                'knowledge' => [[
                    'ref' => 'knowledge_chunks#91',
                    'unit_ref' => 'knowledge_units#40',
                    'name' => '携程曝光复核SOP',
                    'source' => 'formal_operating_sop',
                    'authority' => 'hotel_scoped',
                    'knowledge_type' => '运营SOP',
                    'scope' => 'generic_methodology',
                    'platforms' => ['ctrip'],
                    'evidence_grade' => 'A',
                    'gate_status' => 'approved',
                    'usage_policy' => 'decision_support',
                    'source_refs' => ['formal-sop-v1'],
                    'retrieval_score' => 18,
                    'retrieval_method' => 'metadata_filtered_lexical_v1',
                    'excerpt' => '曝光下降时先复核列表曝光与详情曝光的采集状态。',
                ]],
                'knowledge_retrieval' => [
                    'status' => 'matched',
                    'method' => 'metadata_filtered_lexical_v1',
                    'matched_count' => 1,
                    'returned_count' => 1,
                    'excluded_count' => 0,
                    'reason' => '',
                ],
            ],
            static fn(array $payload): array => $ai->generate($payload)
        );

        $saved = $service->create(
            10,
            20,
            '今天最需要复核什么？',
            'ctrip',
            '2026-08-02',
            '2026-08-02',
            7,
            'deepseek_v4_default'
        );

        self::assertSame(1, $fakeClient->calls);
        $promptMessages = json_decode($fakeClient->lastPrompt, true, 512, JSON_THROW_ON_ERROR);
        $promptPayload = json_decode((string)($promptMessages[1]['content'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            ['list_exposure' => 1800, 'detail_exposure' => 360],
            $promptPayload['untrusted_saved_evidence']['verified_facts'][0]['metric_values']
        );
        self::assertSame(
            ['list_exposure' => 'exposure_count', 'detail_exposure' => 'exposure_count'],
            $promptPayload['untrusted_saved_evidence']['verified_facts'][0]['metric_units']
        );
        self::assertSame('knowledge_chunks#91', $promptPayload['untrusted_saved_evidence']['knowledge_context'][0]['ref']);
        self::assertStringContainsString('曝光下降', $promptPayload['untrusted_saved_evidence']['knowledge_context'][0]['excerpt']);
        self::assertSame('answered_by_grounded_ai', $saved['question']['answer_status']);
        self::assertSame('grounded_ai_saved_evidence', $saved['question']['answer']['mode']);
        self::assertSame(['online_daily_data#801', 'knowledge_chunks#91'], $saved['question']['answer']['used_evidence_refs']);
        self::assertSame(['knowledge_chunks#91'], $saved['question']['knowledge_refs']);
        self::assertSame('matched', $saved['question']['answer']['knowledge_retrieval']['status']);
        self::assertSame('deepseek', $saved['question']['answer']['ai_runtime']['provider']);
        self::assertSame('deepseek-v4-pro', $saved['question']['answer']['ai_runtime']['model']);
        self::assertSame('stop', $saved['question']['answer']['ai_runtime']['finish_reason']);
        self::assertCount(1, $saved['question']['answer']['action_drafts']);
        self::assertSame('ready_for_human_review', $saved['question']['answer']['action_drafts'][0]['status']);
        self::assertTrue($saved['question']['answer']['action_drafts'][0]['can_create_execution_intent']);
        self::assertSame(
            'ai_recommendation_quality.v2',
            $saved['question']['answer']['action_drafts'][0]['decision_quality']['contract_version']
        );
        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/D',
            $saved['question']['answer']['action_drafts'][0]['action_digest']
        );
        self::assertTrue($saved['write_boundaries']['llm_attempted']);
        self::assertTrue($saved['write_boundaries']['external_llm_called']);
        self::assertFalse($saved['write_boundaries']['ota_write']);
        self::assertFalse($saved['write_boundaries']['external_message']);
        self::assertFalse($saved['write_boundaries']['automatic_execution']);

        $same = $service->create(
            10,
            20,
            '今天最需要复核什么？',
            'ctrip',
            '2026-08-02',
            '2026-08-02',
            7,
            'deepseek_v4_default'
        );
        self::assertFalse($same['created']);
        self::assertSame(1, $fakeClient->calls);
        self::assertSame($saved['question']['id'], $same['question']['id']);
        self::assertSame($saved['question']['content_digest'], $same['question']['content_digest']);

        $blockedService = new OperatingQuestionService(
            static fn(): array => [],
            static fn(array $payload): array => $ai->generate($payload)
        );
        $blocked = $blockedService->create(
            10,
            20,
            '没有可信事实时会调用模型吗？',
            'ctrip',
            '2099-08-02',
            '2099-08-02',
            7
        );
        self::assertSame(1, $fakeClient->calls);
        self::assertSame('blocked_by_missing_facts', $blocked['question']['answer_status']);
        self::assertSame('not_called_missing_facts', $blocked['question']['answer']['ai_runtime']['status']);
        self::assertFalse($blocked['write_boundaries']['external_llm_called']);

        $metadataOnlyService = new OperatingQuestionService(
            static fn(): array => [
                'facts' => [[
                    'ref' => 'online_daily_data#804',
                    'data_date' => '2026-08-04',
                    'platform' => 'ctrip',
                    'data_type' => 'traffic',
                    'quality_status' => 'verified',
                    'readback_status' => 'readback_verified',
                ]],
                'fact_count' => 1,
            ],
            static fn(array $payload): array => $ai->generate($payload)
        );
        $metadataOnly = $metadataOnlyService->create(
            10,
            20,
            '只有元数据时能否生成AI结论？',
            'ctrip',
            '2026-08-04',
            '2026-08-04',
            7
        );
        self::assertSame(1, $fakeClient->calls);
        self::assertSame('evidence_ready', $metadataOnly['question']['answer_status']);
        self::assertSame('not_called', $metadataOnly['question']['answer']['ai_runtime']['status']);
        self::assertFalse($metadataOnly['write_boundaries']['external_llm_called']);

        $missingUnitService = new OperatingQuestionService(
            static fn(): array => [
                'facts' => [[
                    'ref' => 'online_daily_data#805',
                    'data_date' => '2026-08-05',
                    'platform' => 'ctrip',
                    'data_type' => 'traffic',
                    'quality_status' => 'verified',
                    'readback_status' => 'readback_verified',
                    'metric_values' => ['list_exposure' => 500],
                ]],
                'fact_count' => 1,
            ],
            static fn(array $payload): array => $ai->generate($payload)
        );
        $missingUnit = $missingUnitService->create(
            10,
            20,
            '指标缺少单位时能否生成AI结论？',
            'ctrip',
            '2026-08-05',
            '2026-08-05',
            7
        );
        self::assertSame(1, $fakeClient->calls);
        self::assertSame('not_called', $missingUnit['question']['answer']['ai_runtime']['status']);
        self::assertSame('missing_substantive_fact_coverage', $missingUnit['question']['answer']['ai_runtime']['reason']);

        $mixedRangeService = new OperatingQuestionService(
            static fn(): array => [
                'facts' => [
                    [
                        'ref' => 'online_daily_data#806',
                        'data_date' => '2026-08-06',
                        'platform' => 'ctrip',
                        'data_type' => 'traffic',
                        'quality_status' => 'verified',
                        'readback_status' => 'readback_verified',
                        'metric_values' => ['list_exposure' => 600],
                        'metric_units' => ['list_exposure' => 'exposure_count'],
                    ],
                    [
                        'ref' => 'online_daily_data#807',
                        'data_date' => '2026-08-07',
                        'platform' => 'ctrip',
                        'data_type' => 'traffic',
                        'quality_status' => 'verified',
                        'readback_status' => 'readback_verified',
                    ],
                ],
                'fact_count' => 2,
            ],
            static fn(array $payload): array => $ai->generate($payload)
        );
        $mixedRange = $mixedRangeService->create(
            10,
            20,
            '两天都要有实质指标才能生成AI结论吗？',
            'ctrip',
            '2026-08-06',
            '2026-08-07',
            7
        );
        self::assertSame(1, $fakeClient->calls);
        self::assertSame('evidence_ready', $mixedRange['question']['answer_status']);
        self::assertSame('not_called', $mixedRange['question']['answer']['ai_runtime']['status']);
        self::assertSame('missing_substantive_fact_coverage', $mixedRange['question']['answer']['ai_runtime']['reason']);

        $allOtaFacts = [];
        $factId = 810;
        foreach (['2026-08-08', '2026-08-09'] as $date) {
            foreach (['ctrip', 'meituan'] as $platform) {
                $fact = [
                    'ref' => 'online_daily_data#' . $factId++,
                    'data_date' => $date,
                    'platform' => $platform,
                    'data_type' => 'traffic',
                    'quality_status' => 'verified',
                    'readback_status' => 'readback_verified',
                ];
                if (!($date === '2026-08-09' && $platform === 'meituan')) {
                    $fact['metric_values'] = ['list_exposure' => 700];
                    $fact['metric_units'] = ['list_exposure' => 'exposure_count'];
                }
                $allOtaFacts[] = $fact;
            }
        }
        $allOtaService = new OperatingQuestionService(
            static fn(): array => ['facts' => $allOtaFacts, 'fact_count' => count($allOtaFacts)],
            static fn(array $payload): array => $ai->generate($payload)
        );
        $allOta = $allOtaService->create(
            10,
            20,
            '携程和美团每天都要有实质指标吗？',
            'all_ota',
            '2026-08-08',
            '2026-08-09',
            7
        );
        self::assertSame(1, $fakeClient->calls);
        self::assertSame('evidence_ready', $allOta['question']['answer_status']);
        self::assertSame('not_called', $allOta['question']['answer']['ai_runtime']['status']);
        self::assertSame('missing_substantive_fact_coverage', $allOta['question']['answer']['ai_runtime']['reason']);
    }

    public function testOperatingQuestionAiPacketContainsEverySubstantiveDateBeyondLegacyTwelveRowSample(): void
    {
        $facts = [];
        $cursor = new \DateTimeImmutable('2026-07-01');
        for ($index = 0; $index < 13; $index++) {
            $facts[] = [
                'ref' => 'online_daily_data#' . (9001 + $index),
                'data_date' => $cursor->modify('+' . $index . ' days')->format('Y-m-d'),
                'platform' => 'ctrip',
                'data_type' => 'traffic',
                'quality_status' => 'verified',
                'readback_status' => 'readback_verified',
                'metric_values' => ['list_exposure' => 1000 + $index],
                'metric_units' => ['list_exposure' => 'exposure_count'],
            ];
        }
        $fakeClient = new class extends LlmClient {
            public int $calls = 0;
            public array $messages = [];

            public function createJsonResponseEnvelope(
                array $messages,
                array $schema,
                string $modelKey = 'deepseek_v4_default'
            ): array {
                $this->calls++;
                $this->messages = $messages;
                return [
                    'data' => [
                        'answer_summary' => '十三天的实质事实均已进入本次只读回答。',
                        'key_points' => [],
                        'missing_information' => [],
                        'follow_up_questions' => [],
                        'confidence' => 'medium',
                        'used_evidence_refs' => ['online_daily_data#9001'],
                        'action_drafts' => [],
                    ],
                    'meta' => [
                        'provider' => 'deepseek',
                        'model_key' => 'deepseek_v4_pro',
                        'model' => 'deepseek-v4-pro',
                        'finish_reason' => 'stop',
                        'fallback_used' => false,
                        'cache_hit' => false,
                        'degraded' => false,
                    ],
                ];
            }
        };
        $ai = new OperatingQuestionAiAnswerService($fakeClient);
        $service = new OperatingQuestionService(
            static fn(): array => ['facts' => $facts, 'fact_count' => count($facts)],
            static fn(array $payload): array => $ai->generate($payload)
        );

        $saved = $service->create(
            10,
            20,
            '这十三天携程流量事实是否完整？',
            'ctrip',
            '2026-07-01',
            '2026-07-13',
            7
        );

        self::assertSame(1, $fakeClient->calls);
        self::assertSame('answered_by_grounded_ai', $saved['question']['answer_status']);
        self::assertCount(13, $saved['question']['answer']['fact_samples']);
        $promptPayload = json_decode((string)($fakeClient->messages[1]['content'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(13, $promptPayload['untrusted_saved_evidence']['verified_facts']);
        self::assertCount(13, array_unique(array_column(
            $promptPayload['untrusted_saved_evidence']['verified_facts'],
            'data_date'
        )));
    }

    public function testOperatingQuestionRejectsFallbackProviderInsteadOfMisreportingDeepSeekSuccess(): void
    {
        $fakeClient = new class extends LlmClient {
            public function createJsonResponseEnvelope(
                array $messages,
                array $schema,
                string $modelKey = 'deepseek_v4_default'
            ): array {
                return [
                    'data' => [
                        'answer_summary' => '备用模型回答',
                        'key_points' => [],
                        'missing_information' => [],
                        'follow_up_questions' => [],
                        'confidence' => 'medium',
                        'used_evidence_refs' => ['online_daily_data#9101'],
                    ],
                    'meta' => [
                        'provider' => 'xiaomi_mimo',
                        'model_key' => 'xiaomi_mimo_pro',
                        'model' => 'mimo-v2.5-pro',
                        'finish_reason' => 'stop',
                        'fallback_used' => true,
                        'cache_hit' => false,
                        'degraded' => false,
                    ],
                ];
            }
        };

        $result = (new OperatingQuestionAiAnswerService($fakeClient))->generate([
            'question' => '携程曝光如何？',
            'scope' => [
                'tenant_id' => 10,
                'hotel_id' => 20,
                'platform' => 'ctrip',
                'date_start' => '2026-08-10',
                'date_end' => '2026-08-10',
            ],
            'answer' => [
                'status' => 'evidence_ready',
                'summary' => '严格证据摘要',
                'evidence_counts' => ['facts' => 1],
                'fact_samples' => [[
                    'ref' => 'online_daily_data#9101',
                    'data_date' => '2026-08-10',
                    'platform' => 'ctrip',
                    'metric_values' => ['list_exposure' => 100],
                    'metric_units' => ['list_exposure' => 'exposure_count'],
                ]],
            ],
            'evidence' => [],
            'model_key' => 'deepseek_v4_default',
            'user_id' => 7,
        ]);

        self::assertFalse($result['ok']);
        self::assertSame('model_unavailable', $result['status']);
        self::assertSame('deepseek_provider_not_confirmed', $result['reason']);
        self::assertSame('xiaomi_mimo', $result['provider']);
        self::assertSame('mimo-v2.5-pro', $result['model']);
        self::assertTrue($result['fallback_used']);
        self::assertTrue($result['external_llm_called']);
        self::assertSame('confirmed_non_deepseek_rejected', $result['external_llm_call_status']);
    }

    public function testKnowledgeContextAloneNeverBypassesMissingVerifiedFacts(): void
    {
        $fakeClient = new class extends LlmClient {
            public int $calls = 0;

            public function createJsonResponseEnvelope(
                array $messages,
                array $schema,
                string $modelKey = 'deepseek_v4_default'
            ): array {
                $this->calls++;
                return ['data' => [], 'meta' => []];
            }
        };
        $result = (new OperatingQuestionAiAnswerService($fakeClient))->generate([
            'question' => '知识库能否代替今天的经营事实？',
            'scope' => [
                'tenant_id' => 10,
                'hotel_id' => 20,
                'platform' => 'ctrip',
                'date_start' => '2026-08-10',
                'date_end' => '2026-08-10',
            ],
            'answer' => [
                'status' => 'blocked_by_missing_facts',
                'evidence_counts' => ['facts' => 0, 'knowledge_chunks' => 1],
            ],
            'evidence' => [
                'knowledge' => [[
                    'ref' => 'knowledge_chunks#91',
                    'unit_ref' => 'knowledge_units#40',
                    'excerpt' => '曝光下降时先复核采集状态。',
                ]],
            ],
        ]);

        self::assertSame('not_called', $result['status']);
        self::assertSame('missing_verified_facts', $result['reason']);
        self::assertSame(0, $fakeClient->calls);
        self::assertFalse($result['external_llm_called']);
    }

    public function testOperatingQuestionKeepsVerifiedEvidenceAnswerWhenModelIsUnavailable(): void
    {
        $fakeClient = new class extends LlmClient {
            public int $calls = 0;

            public function createJsonResponseEnvelope(
                array $messages,
                array $schema,
                string $modelKey = 'deepseek_v4_default'
            ): array {
                $this->calls++;
                throw new \RuntimeException('provider timeout with sensitive detail');
            }
        };
        $ai = new OperatingQuestionAiAnswerService($fakeClient);
        $service = new OperatingQuestionService(
            static fn(): array => [
                'facts' => [[
                    'ref' => 'online_daily_data#802',
                    'data_date' => '2026-08-03',
                    'platform' => 'ctrip',
                    'data_type' => 'traffic',
                    'quality_status' => 'verified',
                    'readback_status' => 'readback_verified',
                    'metric_values' => ['list_exposure' => 900, 'detail_exposure' => 180],
                    'metric_units' => ['list_exposure' => 'exposure_count', 'detail_exposure' => 'exposure_count'],
                ]],
                'fact_count' => 1,
            ],
            static fn(array $payload): array => $ai->generate($payload)
        );

        $saved = $service->create(
            10,
            20,
            '模型不可用时还能否读取事实？',
            'ctrip',
            '2026-08-03',
            '2026-08-03',
            7
        );

        self::assertSame(1, $fakeClient->calls);
        self::assertSame('evidence_ready', $saved['question']['answer_status']);
        self::assertSame('deterministic_saved_evidence', $saved['question']['answer']['mode']);
        self::assertSame('model_unavailable', $saved['question']['answer']['ai_runtime']['status']);
        self::assertTrue($saved['write_boundaries']['llm_attempted']);
        self::assertTrue($saved['write_boundaries']['llm_client_invoked']);
        self::assertNull($saved['write_boundaries']['external_llm_called']);
        self::assertSame('unknown_after_client_attempt', $saved['write_boundaries']['external_llm_call_status']);
        self::assertStringNotContainsString('sensitive detail', $saved['question']['answer']['ai_runtime']['message']);
        self::assertSame('readback_verified', $saved['persistence_status']);

        $retry = $service->create(
            10,
            20,
            '模型不可用时还能否读取事实？',
            'ctrip',
            '2026-08-03',
            '2026-08-03',
            7
        );
        self::assertSame(2, $fakeClient->calls);
        self::assertTrue($retry['created']);
        self::assertNotSame($saved['question']['id'], $retry['question']['id']);
    }

    public function testSinglePlatformQuestionRequiresVerifiedFactsForEveryRequestedDate(): void
    {
        $service = new OperatingQuestionService(static fn(): array => [
            'facts' => [[
                'ref' => 'online_daily_data#803',
                'data_date' => '2026-08-01',
                'platform' => 'ctrip',
                'data_type' => 'business',
                'quality_status' => 'verified',
                'readback_status' => 'readback_verified',
                'metric_values' => ['amount' => 1288.5, 'quantity' => 6],
            ]],
            'fact_count' => 1,
        ]);

        $saved = $service->create(
            10,
            20,
            '这三天携程表现如何？',
            'ctrip',
            '2026-08-01',
            '2026-08-03',
            7
        );

        self::assertSame('blocked_by_missing_facts', $saved['question']['answer_status']);
        self::assertSame('platform_date_fact_coverage_missing', $saved['question']['data_gaps'][0]['code']);
        self::assertSame(['2026-08-02', '2026-08-03'], $saved['question']['data_gaps'][0]['missing_dates']);
        self::assertSame([
            [
                'platform' => 'ctrip',
                'date' => '2026-08-02',
                'required_gate' => 'history_success+validation_verified+readback_verified',
            ],
            [
                'platform' => 'ctrip',
                'date' => '2026-08-03',
                'required_gate' => 'history_success+validation_verified+readback_verified',
            ],
        ], $saved['question']['answer']['recovery_plan']['missing_items']);
        self::assertFalse($saved['write_boundaries']['llm_attempted']);
    }

    public function testAllOtaMemoryContextExcludesOtherPlatformsAndUntrustedUsage(): void
    {
        $base = [
            'tenant_id' => 10,
            'hotel_id' => 20,
            'memory_layer' => 'analysis',
            'source_scope' => 'ota_channel',
            'source_record_id' => 1,
            'business_date' => '2026-08-05',
            'title' => '范围测试',
            'summary' => '仅受信记忆可进入问答。',
            'context_json' => '{}',
            'quality_status' => 'verified',
            'usage_level' => 'decision_support',
            'lifecycle_status' => 'active',
            'deleted_at' => null,
        ];
        Db::name('hotel_operating_memories')->insertAll([
            array_merge($base, ['platform' => 'ctrip', 'source_record_id' => 1]),
            array_merge($base, ['platform' => 'meituan', 'source_record_id' => 2, 'usage_level' => 'reference']),
            array_merge($base, ['platform' => 'qunar', 'source_record_id' => 3]),
            array_merge($base, ['platform' => 'ctrip', 'source_record_id' => 4, 'quality_status' => 'unverified']),
            array_merge($base, ['platform' => 'meituan', 'source_record_id' => 5, 'usage_level' => 'archive_only']),
            array_merge($base, ['platform' => 'ctrip', 'source_record_id' => 6, 'source_scope' => 'whole_hotel']),
        ]);

        $service = new OperatingQuestionService();
        $loadMemories = new \ReflectionMethod($service, 'loadMemories');
        $loadMemories->setAccessible(true);
        $rows = $loadMemories->invoke($service, 10, 20, 'all_ota', '2026-08-05', '2026-08-05');

        self::assertCount(2, $rows);
        self::assertSame(['meituan', 'ctrip'], array_column($rows, 'platform'));
        self::assertSame(['verified'], array_values(array_unique(array_column($rows, 'quality_status'))));
        self::assertEqualsCanonicalizing(['reference', 'decision_support'], array_column($rows, 'usage_level'));
    }

    public function testAllOtaQuestionRequiresBothPlatformFactsAndExplicitAllOtaDiagnosis(): void
    {
        $ctripOnly = new OperatingQuestionService(static fn(): array => [
            'facts' => [[
                'ref' => 'online_daily_data#701',
                'data_date' => '2026-08-01',
                'platform' => 'ctrip',
                'data_type' => 'traffic',
            ]],
            'fact_count' => 1,
            'diagnoses' => [[
                'ref' => 'agent_logs#31',
                'platform' => 'ctrip',
                'summary' => '携程诊断。',
            ]],
        ]);
        $missingFacts = $ctripOnly->create(
            10,
            20,
            '双平台事实是否齐全？',
            'all_ota',
            '2026-08-01',
            '2026-08-01',
            7
        );
        self::assertSame('blocked_by_missing_facts', $missingFacts['question']['answer_status']);
        self::assertSame('all_ota_platform_fact_coverage_missing', $missingFacts['question']['data_gaps'][0]['code']);
        self::assertSame(['meituan'], $missingFacts['question']['data_gaps'][0]['missing_platforms']);
        self::assertSame([
            [
                'platform' => 'meituan',
                'date' => '2026-08-01',
                'required_gate' => 'history_success+validation_verified+readback_verified',
            ],
        ], $missingFacts['question']['answer']['recovery_plan']['missing_items']);
        self::assertSame(
            ['open_data_health', 'open_platform_collection_status', 'recheck'],
            array_column($missingFacts['question']['answer']['recovery_plan']['actions'], 'key')
        );
        self::assertSame(
            'meituan-ebooking',
            $missingFacts['question']['answer']['recovery_plan']['actions'][1]['target_page']
        );

        $bothFactsOneDiagnosis = new OperatingQuestionService(static fn(): array => [
            'facts' => [
                ['ref' => 'online_daily_data#702', 'data_date' => '2026-08-01', 'platform' => 'ctrip', 'data_type' => 'traffic'],
                ['ref' => 'online_daily_data#703', 'data_date' => '2026-08-01', 'platform' => 'meituan', 'data_type' => 'business'],
            ],
            'fact_count' => 2,
            'diagnoses' => [[
                'ref' => 'agent_logs#32',
                'platform' => 'ctrip',
                'summary' => '携程诊断。',
            ]],
        ]);
        $missingDiagnosis = $bothFactsOneDiagnosis->create(
            10,
            20,
            '双平台诊断是否齐全？',
            'all_ota',
            '2026-08-01',
            '2026-08-01',
            7
        );
        self::assertSame('evidence_ready', $missingDiagnosis['question']['answer_status']);
        self::assertSame('all_ota_saved_diagnosis_missing', $missingDiagnosis['question']['data_gaps'][0]['code']);
        self::assertStringNotContainsString('携程诊断。', $missingDiagnosis['question']['answer_summary']);

        $complete = new OperatingQuestionService(static fn(): array => [
            'facts' => [
                ['ref' => 'online_daily_data#704', 'data_date' => '2026-08-01', 'platform' => 'ctrip', 'data_type' => 'traffic'],
                ['ref' => 'online_daily_data#705', 'data_date' => '2026-08-01', 'platform' => 'meituan', 'data_type' => 'business'],
            ],
            'fact_count' => 2,
            'diagnoses' => [[
                'ref' => 'agent_logs#33',
                'platform' => 'all_ota',
                'record_status' => 'active',
                'saved' => true,
                'readback_verified' => true,
                'readback_identity_digest' => 'all-ota-readback-33',
                'saved_readback_identity_digest' => 'all-ota-readback-33',
                'requested_date_range' => ['start_date' => '2026-08-01', 'end_date' => '2026-08-01'],
                'effective_date_range' => ['start_date' => '2026-08-01', 'end_date' => '2026-08-01'],
                'used_latest_available_data' => false,
                'coverage' => [
                    'complete' => true,
                    'required_platforms' => ['ctrip', 'meituan'],
                    'covered_platforms' => ['ctrip', 'meituan'],
                    'missing_platforms' => [],
                    'per_platform' => [
                        'ctrip' => [
                            'status' => 'ready', 'tenant_id' => 10, 'hotel_id' => 20,
                            'requested_date_range' => ['start_date' => '2026-08-01', 'end_date' => '2026-08-01'],
                            'effective_date_range' => ['start_date' => '2026-08-01', 'end_date' => '2026-08-01'],
                            'used_latest_available_data' => false,
                            'evidence_refs' => ['online_daily_data#704'],
                        ],
                        'meituan' => [
                            'status' => 'ready', 'tenant_id' => 10, 'hotel_id' => 20,
                            'requested_date_range' => ['start_date' => '2026-08-01', 'end_date' => '2026-08-01'],
                            'effective_date_range' => ['start_date' => '2026-08-01', 'end_date' => '2026-08-01'],
                            'used_latest_available_data' => false,
                            'evidence_refs' => ['online_daily_data#705'],
                        ],
                    ],
                ],
                'evidence_refs' => [
                    'ctrip' => ['online_daily_data#704'],
                    'meituan' => ['online_daily_data#705'],
                ],
                'summary' => '明确保存并回读的跨渠道诊断。',
            ]],
        ]);
        $answered = $complete->create(
            10,
            20,
            '双平台结论是否可用？',
            'all_ota',
            '2026-08-01',
            '2026-08-01',
            7
        );
        self::assertSame('answered_from_saved_diagnosis', $answered['question']['answer_status']);
        self::assertSame('明确保存并回读的跨渠道诊断。', $answered['question']['answer_summary']);
        self::assertSame(['ctrip' => 1, 'meituan' => 1], $answered['question']['answer']['evidence_counts']['fact_platforms']);

        $latestFallback = new OperatingQuestionService(static fn(): array => [
            'facts' => [
                ['ref' => 'online_daily_data#706', 'data_date' => '2026-08-01', 'platform' => 'ctrip', 'data_type' => 'traffic'],
                ['ref' => 'online_daily_data#707', 'data_date' => '2026-08-01', 'platform' => 'meituan', 'data_type' => 'traffic'],
            ],
            'diagnoses' => [[
                'ref' => 'agent_logs#34',
                'platform' => 'all_ota',
                'record_status' => 'active',
                'saved' => true,
                'readback_verified' => true,
                'requested_date_range' => ['start_date' => '2026-08-01', 'end_date' => '2026-08-01'],
                'effective_date_range' => ['start_date' => '2026-08-01', 'end_date' => '2026-08-01'],
                'used_latest_available_data' => true,
                'summary' => '最近可用日期诊断不得回答目标日问题。',
            ]],
        ]);
        $rejected = $latestFallback->create(
            10, 20, '最近可用诊断可否回答？', 'all_ota', '2026-08-01', '2026-08-01', 7
        );
        self::assertSame('evidence_ready', $rejected['question']['answer_status']);
        self::assertSame('all_ota_saved_diagnosis_not_current', $rejected['question']['data_gaps'][0]['code']);
        self::assertContains('diagnosis_used_latest_available_data', $rejected['question']['data_gaps'][0]['reason_codes']);
    }

    public function testSopCandidateNeedsRepeatedPositiveMemoriesAndCreatesImmutableVerifiedVersion(): void
    {
        $memoryIds = $this->insertVerifiedMemories();
        $service = new OperatingSopService();
        $candidateInput = [
            'title' => 'Traffic review SOP',
            'objective' => 'Review saved traffic facts before deciding.',
            'steps' => ['Read exact facts', 'Review the decision', 'Record the outcome'],
            'stop_conditions' => ['Stop when source facts are missing'],
            'applicable_data_types' => ['traffic'],
            'metric_definitions' => ['traffic facts from the exact readback scope'],
        ];
        $candidate = $service->createCandidate(10, 20, [$memoryIds[0]], $candidateInput, 7);
        self::assertSame('candidate', $candidate['version']['validation_status']);
        self::assertSame(1, $candidate['version']['version_no']);
        self::assertSame('readback_verified', $candidate['persistence_status']);
        self::assertSame(['Read exact facts', 'Review the decision', 'Record the outcome'], $candidate['version']['steps']);
        self::assertSame([$memoryIds[0]], $candidate['version']['source_memory_ids']);

        try {
            $service->validateVersion((int)$candidate['version']['id'], 10, [20], [
                'decision' => 'verify',
                'validation_note' => 'Too little evidence.',
                'evidence_memory_ids' => [$memoryIds[0]],
            ], 8);
            self::fail('One observation must not verify an SOP.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('至少需要3条', $e->getMessage());
        }

        $verified = $service->validateVersion((int)$candidate['version']['id'], 10, [20], [
            'decision' => 'verify',
            'validation_note' => 'Three independent positive reviews were checked by a human.',
            'evidence_memory_ids' => $memoryIds,
        ], 8);
        self::assertSame('verified', $verified['version']['validation_status']);
        self::assertSame(2, $verified['version']['version_no']);
        self::assertSame((int)$candidate['version']['id'], $verified['version']['previous_version_id']);
        self::assertSame('superseded', Db::name(OperatingSopService::VERSION_TABLE)
            ->where('id', (int)$candidate['version']['id'])->value('lifecycle_status'));
        self::assertCount(3, $verified['version']['source_memory_ids']);

        $nextCandidate = $service->createCandidate(10, 20, [$memoryIds[1]], $candidateInput, 9);
        self::assertSame(3, $nextCandidate['version']['version_no']);
        self::assertSame($verified['version']['sop_key'], $nextCandidate['version']['sop_key']);
        self::assertSame('2026-07-30', $nextCandidate['version']['scope']['evidence_date_start']);
        self::assertSame('active', Db::name(OperatingSopService::VERSION_TABLE)
            ->where('id', (int)$verified['version']['id'])->value('lifecycle_status'));
        $rejected = $service->validateVersion((int)$nextCandidate['version']['id'], 10, [20], [
            'decision' => 'reject',
            'validation_note' => 'The revised candidate is not ready.',
        ], 8);
        self::assertSame('rejected', $rejected['version']['validation_status']);
        self::assertSame('closed', $rejected['version']['lifecycle_status']);
        self::assertSame('active', Db::name(OperatingSopService::VERSION_TABLE)
            ->where('id', (int)$verified['version']['id'])->value('lifecycle_status'));
        self::assertSame('superseded', Db::name(OperatingSopService::VERSION_TABLE)
            ->where('id', (int)$nextCandidate['version']['id'])->value('lifecycle_status'));

        $this->expectException(InvalidArgumentException::class);
        $service->validateVersion((int)$nextCandidate['version']['id'], 10, [20], [
            'decision' => 'verify',
            'validation_note' => 'A stale retry must not create a conflicting version.',
            'evidence_memory_ids' => $memoryIds,
        ], 8);
    }

    public function testCrossHotelReplicationIsSameTenantDraftAndNeverReusesSourceFacts(): void
    {
        $memoryIds = $this->insertVerifiedMemories();
        $service = new OperatingSopService();
        $candidate = $service->createCandidate(10, 20, [$memoryIds[0]], [
            'title' => 'Traffic review SOP',
            'objective' => 'Review saved traffic facts before deciding.',
            'steps' => ['Read exact facts', 'Record the outcome'],
            'stop_conditions' => ['Stop on missing facts'],
            'applicable_data_types' => ['traffic'],
        ], 7);
        $verified = $service->validateVersion((int)$candidate['version']['id'], 10, [20], [
            'decision' => 'verify',
            'validation_note' => 'Human verified three independent reviews.',
            'evidence_memory_ids' => $memoryIds,
        ], 8);
        $versionId = (int)$verified['version']['id'];
        Db::name('online_daily_data')->insert([
            'tenant_id' => 10,
            'system_hotel_id' => 21,
            'data_date' => '2026-07-30',
            'platform' => 'ctrip',
            'source' => 'ctrip',
            'data_type' => 'traffic',
            'readback_verified' => 1,
            'validation_status' => 'normal',
        ]);
        Db::name('online_daily_data')->insertAll([
            [
                'tenant_id' => 10,
                'system_hotel_id' => 22,
                'data_date' => '2026-08-01',
                'platform' => 'ctrip',
                'source' => 'ctrip',
                'data_type' => 'traffic',
                'readback_verified' => 1,
                'validation_status' => 'normal',
            ],
            [
                'tenant_id' => 10,
                'system_hotel_id' => 22,
                'data_date' => '2026-07-30',
                'platform' => 'ctrip',
                'source' => 'ctrip',
                'data_type' => 'business',
                'readback_verified' => 1,
                'validation_status' => 'normal',
            ],
        ]);

        $replicated = $service->replicate($versionId, 10, [20, 21], 21, 8);
        self::assertSame('readback_verified', $replicated['persistence_status']);
        self::assertSame('blocked_applicability_evidence_incomplete', $replicated['replication']['status']);
        self::assertSame('blocked_applicability_evidence_incomplete', $replicated['replication']['target_validation_status']);
        self::assertContains(
            'target_operating_profile_missing',
            array_column($replicated['replication']['data_gaps'], 'code')
        );
        self::assertFalse($replicated['replication']['draft']['boundaries']['target_verified']);
        self::assertFalse($replicated['replication']['draft']['boundaries']['automatic_execution']);
        self::assertSame('reference_only_not_reused_as_target_fact', $replicated['replication']['draft']['source_evidence_policy']);
        self::assertSame(['online_daily_data#1'], $replicated['replication']['target_fact_refs']);
        self::assertArrayNotHasKey('evidence_refs', $replicated['replication']['draft']);
        self::assertSame('2026-07-29', $replicated['replication']['draft']['target_fact_comparison_contract']['date_start']);
        self::assertSame(['traffic'], $replicated['replication']['draft']['target_fact_comparison_contract']['data_types']);

        $same = $service->replicate($versionId, 10, [20, 21], 21, 8);
        self::assertFalse($same['created']);
        self::assertSame($replicated['replication']['id'], $same['replication']['id']);

        $blocked = $service->replicate($versionId, 10, [20, 22], 22, 8);
        self::assertSame('blocked_missing_target_facts', $blocked['replication']['status']);
        self::assertSame('target_hotel_comparable_fact_missing', $blocked['replication']['data_gaps'][0]['code']);
        self::assertSame([], $blocked['replication']['target_fact_refs']);

        $this->expectException(\RuntimeException::class);
        $service->replicate($versionId, 10, [20, 30], 30, 8);
    }

    /** @return list<int> */
    private function insertVerifiedMemories(): array
    {
        $ids = [];
        foreach ([
            [101, '2026-07-29'],
            [102, '2026-07-30'],
            [103, '2026-07-30'],
        ] as [$taskId, $businessDate]) {
            $ids[] = (int)Db::name('hotel_operating_memories')->insertGetId([
                'tenant_id' => 10,
                'hotel_id' => 20,
                'memory_layer' => 'execution_review',
                'platform' => 'ctrip',
                'source_scope' => 'ota_channel',
                'source_record_id' => $taskId,
                'business_date' => $businessDate,
                'context_json' => json_encode([
                    'outcome_verified' => true,
                    'positive_outcome_verified' => true,
                    'sop_candidate_ready' => true,
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'quality_status' => 'verified',
                'usage_level' => 'decision_support',
                'lifecycle_status' => 'active',
                'deleted_at' => null,
            ]);
        }
        return $ids;
    }
}
