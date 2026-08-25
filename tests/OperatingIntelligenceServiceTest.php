<?php
declare(strict_types=1);

namespace Tests;

use app\exception\LlmDirectRequestException;
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

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    public static function directMeta(string $responseId, array $overrides = []): array
    {
        $nonce = 'oq_test_' . substr(hash('sha256', $responseId), 0, 24);
        return array_replace([
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
            'provider_config_digest' => str_repeat('a', 64),
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
        ], $overrides);
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    public static function localMeta(array $overrides = []): array
    {
        $nonce = 'oq_local_' . substr(hash('sha256', 'local-second-brain'), 0, 24);
        return array_replace([
            'provider' => 'ollama',
            'model_key' => 'local_second_brain',
            'model' => 'qwen3:4b',
            'configured_model' => 'qwen3:4b',
            'response_model' => 'qwen3:4b',
            'provider_response_id' => '',
            'provider_created_at' => 0,
            'provider_response_fresh' => false,
            'provider_endpoint_origin' => 'http://127.0.0.1:11434',
            'provider_endpoint_host' => '127.0.0.1',
            'provider_endpoint_official' => false,
            'provider_config_digest' => str_repeat('b', 64),
            'direct_call_nonce' => $nonce,
            'transport_request_id' => $nonce,
            'transport_retry_attempts' => 0,
            'upstream_idempotency_key_sent' => false,
            'http_status' => 200,
            'provider_attempt_count' => 1,
            'idempotent_replay' => false,
            'direct_request_proof' => false,
            'thinking_mode' => 'disabled',
            'reasoning_effort' => '',
            'finish_reason' => 'stop',
            'fallback_used' => false,
            'cache_hit' => false,
            'degraded' => false,
        ], $overrides);
    }

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
            'hotel_operating_question_model_responses',
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
            'CREATE TABLE hotel_operating_question_model_responses ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, provider_response_id TEXT COLLATE BINARY NOT NULL UNIQUE, '
            . 'provider TEXT NOT NULL, question_id INTEGER NOT NULL UNIQUE, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, '
            . 'question_content_digest TEXT NOT NULL, created_at TEXT NOT NULL)'
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
            . 'comment_score REAL DEFAULT 0, qunar_comment_score REAL DEFAULT 0, data_value REAL DEFAULT 0, list_exposure INTEGER DEFAULT 0, '
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
            'raw_data' => json_encode(['field_facts' => [
                [
                    'metric_key' => 'list_exposure',
                    'data_type' => 'traffic',
                    'source_key' => 'listExposure',
                    'source_path' => 'payload.listExposure',
                    'storage_field' => 'online_daily_data.list_exposure',
                    'status' => 'captured',
                    'stored_value_present' => true,
                ],
                [
                    'metric_key' => 'detail_exposure',
                    'data_type' => 'traffic',
                    'source_key' => 'detailExposure',
                    'source_path' => 'payload.detailExposure',
                    'storage_field' => 'online_daily_data.detail_exposure',
                    'status' => 'captured',
                    'stored_value_present' => true,
                ],
                [
                    'metric_key' => 'browse_to_pay_rate',
                    'data_type' => 'traffic',
                    'source_key' => 'browseToPayRate',
                    'source_path' => 'payload.browseToPayRate',
                    'storage_field' => 'online_daily_data.flow_rate',
                    'stored_unit' => 'percent',
                    'status' => 'captured',
                    'stored_value_present' => true,
                ],
            ]], JSON_UNESCAPED_SLASHES),
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
            'list_exposure' => 'visitor_count',
            'detail_exposure' => 'visitor_count',
            'flow_rate' => 'percent',
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
                        'data_type' => 'traffic',
                        'source_key' => 'listExposure',
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
        self::assertSame(['list_exposure' => 'visitor_count'], $observedZeroFacts[0]['metric_units']);

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

    public function testScopeOptionsRecommendLatestStrictReadbackWithoutPromotingPartialRows(): void
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Asia/Shanghai'));
        $latestMeituan = $today->modify('-1 day')->format('Y-m-d');
        $sharedDate = $today->modify('-2 days')->format('Y-m-d');
        $partialDate = $today->format('Y-m-d');
        $rows = [];
        foreach ([
            ['platform' => 'ctrip', 'date' => $sharedDate, 'history' => 'success', 'validation' => 'verified'],
            ['platform' => 'meituan', 'date' => $sharedDate, 'history' => 'success', 'validation' => 'verified'],
            ['platform' => 'meituan', 'date' => $latestMeituan, 'history' => 'success', 'validation' => 'verified'],
            ['platform' => 'ctrip', 'date' => $partialDate, 'history' => 'partial', 'validation' => 'normal'],
        ] as $index => $scope) {
            $rows[] = [
                'tenant_id' => 10,
                'system_hotel_id' => 20,
                'data_date' => $scope['date'],
                'platform' => $scope['platform'],
                'source' => $scope['platform'],
                'data_type' => 'traffic',
                'dimension' => '',
                'readback_verified' => 1,
                'readback_verified_at' => $scope['date'] . ' 10:00:00',
                'validation_status' => $scope['validation'],
                'history_status' => $scope['history'],
                'ingestion_method' => 'browser_profile',
                'source_trace_id' => 'scope-option-' . $index,
                'list_exposure' => 100 + $index,
            ];
        }
        Db::name('online_daily_data')->insertAll($rows);

        $result = (new OperatingQuestionService())->scopeOptions(10, 20);

        self::assertSame('operating_question_scope_options.v1', $result['contract_version']);
        self::assertSame('ready', $result['data_status']);
        self::assertSame('meituan', $result['recommended']['platform']);
        self::assertSame($latestMeituan, $result['recommended']['date_start']);
        self::assertFalse($result['boundary']['silent_date_fallback']);
        self::assertSame(
            $sharedDate,
            $result['platforms'][array_search('all_ota', array_column($result['platforms'], 'platform'), true)]['latest_verified_date']
        );
        self::assertNotContains($partialDate, $result['platforms'][array_search('ctrip', array_column($result['platforms'], 'platform'), true)]['available_dates']);
    }

    public function testOperatingQuestionSavesExactEvidenceReadbackAndVisibleMissingState(): void
    {
        $ready = new OperatingQuestionService(static fn(): array => [
            'facts' => [self::substantiveFact(701, '2026-08-01')],
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
        self::assertSame('evidence_ready', $saved['question']['answer_status']);
        self::assertSame('saved_diagnosis_claim_contract_missing', $saved['question']['data_gaps'][0]['code']);
        self::assertStringNotContainsString('Saved diagnosis conclusion.', $saved['question']['answer_summary']);
        self::assertSame(['online_daily_data#701'], $saved['question']['fact_refs']);
        self::assertSame(['hotel_operating_memories#11'], $saved['question']['memory_refs']);
        self::assertFalse($saved['write_boundaries']['external_llm_called']);
        self::assertFalse($saved['write_boundaries']['ota_write']);
        self::assertFalse($saved['write_boundaries']['external_message']);

        $same = $ready->create(10, 20, 'What should this hotel review?', 'ctrip', '2026-08-01', '2026-08-01', 7);
        self::assertTrue($same['created']);
        self::assertNotSame($saved['question']['id'], $same['question']['id']);
        self::assertNotSame($saved['question']['request_key'], $same['question']['request_key']);

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
                string $modelKey = 'deepseek_v4_pro'
            ): array {
                $this->calls++;
                $this->lastPrompt = json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                return [
                    'data' => [
                        'fact_claims' => [[
                            'evidence_ref' => 'online_daily_data#801',
                            'metric_key' => 'list_exposure',
                            'metric_definition_id' => 'ota_list_exposure_users.v1',
                            'value' => 1800,
                            'unit' => 'visitor_count',
                        ]],
                        'follow_up_questions' => ['目标日已保存诊断的具体缺口是什么？'],
                        'confidence' => 'medium',
                        'action_drafts' => [[
                            'expected_metric' => 'list_exposure',
                            'expected_metric_definition_id' => 'ota_list_exposure_users.v1',
                            'evidence_refs' => ['online_daily_data#801'],
                        ]],
                    ],
                    'meta' => OperatingIntelligenceServiceTest::directMeta(
                        'resp-operating-801-' . str_pad((string)$this->calls, 4, '0', STR_PAD_LEFT)
                    ),
                ];
            }
        };
        $ai = new OperatingQuestionAiAnswerService($fakeClient);
        $groundedFact = self::substantiveFact(
            801,
            '2026-08-02',
            'ctrip',
            ['list_exposure' => 1800, 'detail_exposure' => 360]
        );
        foreach ([
            'list_exposure' => ['ota_list_exposure_users.v1', 'exposure_users', 'visitor_count', '曝光用户数'],
            'detail_exposure' => ['ota_detail_visitors.v1', 'detail_visitors', 'visitor_count', '详情访问用户数'],
        ] as $metricKey => [$definitionId, $sourceMetricKey, $unit, $label]) {
            $groundedFact['metric_units'][$metricKey] = $unit;
            $groundedFact['metric_definitions'][$metricKey]['definition_id'] = $definitionId;
            $groundedFact['metric_definitions'][$metricKey]['source_metric_key'] = $sourceMetricKey;
            $groundedFact['metric_definitions'][$metricKey]['unit'] = $unit;
            $groundedFact['metric_definitions'][$metricKey]['label'] = $label;
        }
        $service = new OperatingQuestionService(
            static fn(): array => [
                'facts' => [$groundedFact],
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
            '2026-08-02 携程列表曝光用户数最需要复核什么？',
            'ctrip',
            '2026-08-02',
            '2026-08-02',
            7,
            'deepseek_v4_pro'
        );

        self::assertSame(1, $fakeClient->calls);
        $promptMessages = json_decode($fakeClient->lastPrompt, true, 512, JSON_THROW_ON_ERROR);
        $promptPayload = json_decode((string)($promptMessages[1]['content'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            ['list_exposure' => 1800, 'detail_exposure' => 360],
            $promptPayload['untrusted_saved_evidence']['verified_facts'][0]['metric_values']
        );
        self::assertSame(
            ['list_exposure' => 'visitor_count', 'detail_exposure' => 'visitor_count'],
            $promptPayload['untrusted_saved_evidence']['verified_facts'][0]['metric_units']
        );
        self::assertSame('knowledge_chunks#91', $promptPayload['untrusted_saved_evidence']['knowledge_context'][0]['ref']);
        self::assertStringContainsString('曝光下降', $promptPayload['untrusted_saved_evidence']['knowledge_context'][0]['excerpt']);
        self::assertSame('answered_by_grounded_ai', $saved['question']['answer_status']);
        self::assertSame('grounded_ai_saved_evidence', $saved['question']['answer']['mode']);
        self::assertSame(['online_daily_data#801'], $saved['question']['answer']['used_evidence_refs']);
        self::assertSame(['knowledge_chunks#91'], $saved['question']['knowledge_refs']);
        self::assertSame('matched', $saved['question']['answer']['knowledge_retrieval']['status']);
        self::assertSame('deepseek', $saved['question']['answer']['ai_runtime']['provider']);
        self::assertSame('deepseek-v4-pro', $saved['question']['answer']['ai_runtime']['model']);
        self::assertTrue($saved['question']['answer']['ai_runtime']['direct_request_proof']);
        self::assertSame(
            OperatingQuestionAiAnswerService::DIRECT_CALL_STATUS,
            $saved['question']['answer']['ai_runtime']['external_llm_call_status']
        );
        self::assertSame('stop', $saved['question']['answer']['ai_runtime']['finish_reason']);
        self::assertSame('resp-operating-801-0001', $saved['question']['answer']['ai_runtime']['provider_response_id']);
        self::assertStringContainsString('曝光用户数为1800人', $saved['question']['answer_summary']);
        self::assertStringNotContainsString('已读取目标日携程', $saved['question']['answer_summary']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $saved['question']['answer']['claims_digest']);
        self::assertSame('ota_list_exposure_users.v1', $saved['question']['answer']['fact_claims'][0]['metric_definition_id']);
        self::assertCount(1, $saved['question']['answer']['action_drafts']);
        self::assertSame('ready_for_human_review', $saved['question']['answer']['action_drafts'][0]['status']);
        self::assertTrue($saved['question']['answer']['action_drafts'][0]['boundaries']['human_confirmation_required']);
        self::assertFalse($saved['question']['answer']['action_drafts'][0]['boundaries']['automatic_execution']);
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
            '2026-08-02 携程列表曝光用户数最需要复核什么？',
            'ctrip',
            '2026-08-02',
            '2026-08-02',
            7,
            'deepseek_v4_pro'
        );
        self::assertTrue($same['created']);
        self::assertSame(2, $fakeClient->calls);
        self::assertNotSame($saved['question']['id'], $same['question']['id']);
        self::assertNotSame($saved['question']['content_digest'], $same['question']['content_digest']);

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
        self::assertSame(2, $fakeClient->calls);
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
        self::assertSame(2, $fakeClient->calls);
        self::assertSame('blocked_by_missing_facts', $metadataOnly['question']['answer_status']);
        self::assertSame('not_called_missing_facts', $metadataOnly['question']['answer']['ai_runtime']['status']);
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
        self::assertSame(2, $fakeClient->calls);
        self::assertSame('blocked_by_missing_facts', $missingUnit['question']['answer_status']);
        self::assertSame('not_called_missing_facts', $missingUnit['question']['answer']['ai_runtime']['status']);
        self::assertContains('substantive_fact_coverage_missing', array_column($missingUnit['question']['data_gaps'], 'code'));

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
        self::assertSame(2, $fakeClient->calls);
        self::assertSame('blocked_by_missing_facts', $mixedRange['question']['answer_status']);
        self::assertSame('not_called_missing_facts', $mixedRange['question']['answer']['ai_runtime']['status']);
        self::assertContains('substantive_fact_coverage_missing', array_column($mixedRange['question']['data_gaps'], 'code'));

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
        self::assertSame(2, $fakeClient->calls);
        self::assertSame('blocked_by_missing_facts', $allOta['question']['answer_status']);
        self::assertSame('not_called_missing_facts', $allOta['question']['answer']['ai_runtime']['status']);
        self::assertContains('substantive_fact_coverage_missing', array_column($allOta['question']['data_gaps'], 'code'));
    }

    public function testOperatingQuestionAcceptsPinnedLocalSecondBrainAndPersistsExternalBoundary(): void
    {
        $fakeClient = new class extends LlmClient {
            public int $calls = 0;

            public function createJsonResponseEnvelope(
                array $messages,
                array $schema,
                string $modelKey = 'deepseek_v4_pro'
            ): array {
                $this->calls++;
                return [
                    'data' => [
                        'fact_claims' => [[
                            'evidence_ref' => 'online_daily_data#9201',
                            'metric_key' => 'list_exposure',
                            'metric_definition_id' => 'ota_list_exposure_users.v1',
                            'value' => 100,
                            'unit' => 'visitor_count',
                        ]],
                        'follow_up_questions' => [],
                        'confidence' => 'medium',
                        'action_drafts' => [],
                    ],
                    'meta' => OperatingIntelligenceServiceTest::localMeta(),
                ];
            }
        };
        $ai = new OperatingQuestionAiAnswerService($fakeClient);
        $groundedFact = self::substantiveFact(9201, '2026-08-10');
        $groundedFact['metric_units']['list_exposure'] = 'visitor_count';
        $groundedFact['metric_definitions']['list_exposure']['definition_id'] = 'ota_list_exposure_users.v1';
        $groundedFact['metric_definitions']['list_exposure']['source_metric_key'] = 'exposure_users';
        $groundedFact['metric_definitions']['list_exposure']['unit'] = 'visitor_count';
        $groundedFact['metric_definitions']['list_exposure']['label'] = '曝光用户数';
        $service = new OperatingQuestionService(
            static fn(): array => [
                'facts' => [$groundedFact],
                'fact_count' => 1,
            ],
            static fn(array $payload): array => $ai->generate($payload)
        );

        $saved = $service->create(
            10,
            20,
            '2026-08-10 携程列表曝光用户数是多少？',
            'ctrip',
            '2026-08-10',
            '2026-08-10',
            7,
            'local_second_brain'
        );

        self::assertSame(1, $fakeClient->calls);
        self::assertSame('answered_by_grounded_ai', $saved['question']['answer_status']);
        self::assertSame('ollama', $saved['question']['answer']['ai_runtime']['provider']);
        self::assertSame('qwen3:4b', $saved['question']['answer']['ai_runtime']['model']);
        self::assertTrue(OperatingQuestionAiAnswerService::localCallProofReady(
            $saved['question']['answer']['ai_runtime']
        ));
        self::assertTrue($saved['question']['answer']['ai_runtime']['local_llm_called']);
        self::assertSame('confirmed_local_response', $saved['question']['answer']['ai_runtime']['local_transport_status']);
        self::assertFalse($saved['question']['answer']['ai_runtime']['external_llm_called']);
        self::assertSame(
            OperatingQuestionAiAnswerService::LOCAL_CALL_STATUS,
            $saved['question']['answer']['ai_runtime']['external_llm_call_status']
        );
        self::assertTrue($saved['write_boundaries']['local_llm_called']);
        self::assertSame('confirmed_local_response', $saved['write_boundaries']['local_transport_status']);
        self::assertFalse($saved['write_boundaries']['external_llm_called']);
        self::assertFalse($saved['write_boundaries']['ota_write']);
        self::assertFalse($saved['write_boundaries']['automatic_execution']);
    }

    public function testOperatingQuestionAiPacketContainsEverySubstantiveDateBeyondLegacyTwelveRowSample(): void
    {
        $facts = [];
        $cursor = new \DateTimeImmutable('2026-07-01');
        for ($index = 0; $index < 13; $index++) {
            $facts[] = self::substantiveFact(
                9001 + $index,
                $cursor->modify('+' . $index . ' days')->format('Y-m-d'),
                'ctrip',
                ['amount' => 1000 + $index]
            );
        }
        $fakeClient = new class extends LlmClient {
            public int $calls = 0;
            public array $messages = [];

            public function createJsonResponseEnvelope(
                array $messages,
                array $schema,
                string $modelKey = 'deepseek_v4_pro'
            ): array {
                $this->calls++;
                $this->messages = $messages;
                return [
                    'data' => [
                        'fact_claims' => [[
                            'evidence_ref' => 'online_daily_data#9001',
                            'metric_key' => 'amount',
                            'metric_definition_id' => 'ota_paid_order_amount.v1',
                            'value' => 1000,
                            'unit' => 'CNY',
                        ]],
                        'follow_up_questions' => [],
                        'confidence' => 'medium',
                        'action_drafts' => [],
                    ],
                    'meta' => OperatingIntelligenceServiceTest::directMeta('resp-thirteen-days-0001'),
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
            '携程已回读事实是否完整？',
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
                string $modelKey = 'deepseek_v4_pro'
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
                    'meta' => OperatingIntelligenceServiceTest::directMeta('resp-fallback-provider-0001', [
                        'provider' => 'xiaomi_mimo',
                        'model' => 'mimo-v2.5-pro',
                        'fallback_used' => true,
                        'direct_request_proof' => false,
                    ]),
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
                'fact_samples' => [self::substantiveFact(9101, '2026-08-10')],
                'question_metric_contract' => [
                    'contract_version' => OperatingQuestionService::METRIC_INTENT_CONTRACT_VERSION,
                    'mode' => 'metric_lookup',
                    'requested_metrics' => [[
                        'metric_key' => 'list_exposure',
                        'definition_ids' => ['ota_list_exposure.v1'],
                    ]],
                    'required_platforms' => ['ctrip'],
                    'required_dates' => ['2026-08-10'],
                    'action_draft_allowed' => true,
                ],
            ],
            'evidence' => [],
            'model_key' => 'deepseek_v4_pro',
            'user_id' => 7,
        ]);

        self::assertFalse($result['ok']);
        self::assertSame('model_unavailable', $result['status']);
        self::assertSame('fallback_or_degraded_response_rejected', $result['reason']);
        self::assertSame('xiaomi_mimo', $result['provider']);
        self::assertSame('mimo-v2.5-pro', $result['model']);
        self::assertTrue($result['fallback_used']);
        self::assertTrue($result['external_llm_called']);
        self::assertSame('direct_deepseek_v4_pro_proof_rejected', $result['external_llm_call_status']);
    }

    public function testOperatingQuestionRejectsEveryUntrustedDirectResponseReceipt(): void
    {
        $variants = [
            'flash' => ['response_model' => 'deepseek-v4-flash', 'direct_request_proof' => false],
            'cache' => ['cache_hit' => true, 'direct_request_proof' => false],
            'fallback' => ['fallback_used' => true, 'direct_request_proof' => false],
            'fake_model' => ['response_model' => 'deepseek-v4-pro-compatible', 'direct_request_proof' => false],
            'gateway' => [
                'provider_endpoint_origin' => 'https://gateway.example.com',
                'provider_endpoint_host' => 'gateway.example.com',
                'provider_endpoint_official' => false,
                'direct_request_proof' => false,
            ],
            'stale' => [
                'provider_created_at' => time() - 3600,
                'provider_response_fresh' => false,
                'direct_request_proof' => false,
            ],
            'retry' => ['transport_retry_attempts' => 1, 'direct_request_proof' => false],
            'idempotency' => ['upstream_idempotency_key_sent' => true, 'direct_request_proof' => false],
        ];
        foreach ($variants as $label => $overrides) {
            $meta = self::directMeta('resp-negative-' . $label . '-0001', $overrides);
            $fakeClient = new class($meta) extends LlmClient {
                public function __construct(private readonly array $meta)
                {
                }

                public function createJsonResponseEnvelope(
                    array $messages,
                    array $schema,
                    string $modelKey = 'deepseek_v4_pro'
                ): array {
                    return [
                        'data' => [
                            'fact_claims' => [],
                            'follow_up_questions' => [],
                            'confidence' => 'medium',
                            'action_drafts' => [[
                                'expected_metric' => 'list_exposure',
                                'expected_metric_definition_id' => 'ota_list_exposure_users.v1',
                                'evidence_refs' => ['online_daily_data#9102'],
                            ]],
                        ],
                        'meta' => $this->meta,
                    ];
                }
            };
            $result = (new OperatingQuestionAiAnswerService($fakeClient))->generate([
                'question' => '2026-08-10 携程曝光是多少？',
                'scope' => [
                    'tenant_id' => 10,
                    'hotel_id' => 20,
                    'platform' => 'ctrip',
                    'date_start' => '2026-08-10',
                    'date_end' => '2026-08-10',
                ],
                'answer' => [
                    'status' => 'evidence_ready',
                    'evidence_counts' => ['facts' => 1],
                    'fact_samples' => [self::substantiveFact(9102, '2026-08-10')],
                    'question_metric_contract' => [
                        'contract_version' => OperatingQuestionService::METRIC_INTENT_CONTRACT_VERSION,
                        'mode' => 'metric_lookup',
                        'requested_metrics' => [[
                            'metric_key' => 'list_exposure',
                            'definition_ids' => ['ota_list_exposure_users.v1'],
                        ]],
                        'required_platforms' => ['ctrip'],
                        'required_dates' => ['2026-08-10'],
                        'action_draft_allowed' => true,
                    ],
                ],
                'evidence' => [],
                'model_key' => 'deepseek_v4_pro',
                'user_id' => 7,
            ]);
            self::assertFalse($result['ok'], $label);
            self::assertSame([], $result['action_drafts'] ?? [], $label);
            self::assertNotSame(OperatingQuestionAiAnswerService::DIRECT_CALL_STATUS, $result['external_llm_call_status'], $label);
        }
    }

    public function testOperatingQuestionRejectsFlashDefaultAndBackupModelKeysBeforeClientInvocation(): void
    {
        foreach (['deepseek_v4_default', 'deepseek_v4_flash', 'deepseek_chat', 'backup_model'] as $modelKey) {
            try {
                (new OperatingQuestionAiAnswerService())->generate(['model_key' => $modelKey]);
                self::fail('untrusted model key was accepted: ' . $modelKey);
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('DeepSeek V4 Pro', $exception->getMessage());
            }
        }
    }

    public function testKnowledgeContextAloneNeverBypassesMissingVerifiedFacts(): void
    {
        $fakeClient = new class extends LlmClient {
            public int $calls = 0;

            public function createJsonResponseEnvelope(
                array $messages,
                array $schema,
                string $modelKey = 'deepseek_v4_pro'
            ): array {
                $this->calls++;
                return ['data' => [], 'meta' => []];
            }
        };
        $result = (new OperatingQuestionAiAnswerService($fakeClient))->generate([
            'question' => '知识库能否代替指定业务日的经营事实？',
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
                string $modelKey = 'deepseek_v4_pro'
            ): array {
                $this->calls++;
                throw new \RuntimeException('provider timeout with sensitive detail');
            }
        };
        $ai = new OperatingQuestionAiAnswerService($fakeClient);
        $service = new OperatingQuestionService(
            static fn(): array => [
                'facts' => [self::substantiveFact(
                    802,
                    '2026-08-03',
                    'ctrip',
                    ['list_exposure' => 900, 'detail_exposure' => 180]
                )],
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

    public function testOperatingQuestionPersistsClassifiedDirectFailureReceiptInsteadOfUnknown(): void
    {
        $nonce = 'oq_' . str_repeat('a', 32);
        $fakeClient = new class($nonce) extends LlmClient {
            public function __construct(private readonly string $nonce)
            {
            }

            public function createJsonResponseEnvelope(
                array $messages,
                array $schema,
                string $modelKey = 'deepseek_v4_pro'
            ): array {
                throw new LlmDirectRequestException('provider detail must not persist', 502, [
                    'failure_reason' => 'empty_content',
                    'model_key' => 'deepseek_v4_pro',
                    'provider' => 'deepseek',
                    'model' => 'deepseek-v4-pro',
                    'configured_model' => 'deepseek-v4-pro',
                    'response_model' => 'deepseek-v4-pro',
                    'provider_response_id' => 'resp-rejected-direct-v4-pro-0001',
                    'provider_created_at' => time(),
                    'provider_response_fresh' => true,
                    'provider_endpoint_origin' => 'https://api.deepseek.com',
                    'provider_endpoint_host' => 'api.deepseek.com',
                    'provider_endpoint_official' => true,
                    'provider_config_digest' => str_repeat('b', 64),
                    'direct_call_nonce' => $this->nonce,
                    'transport_request_id' => $this->nonce,
                    'transport_retry_attempts' => 0,
                    'upstream_idempotency_key_sent' => false,
                    'http_status' => 200,
                    'provider_attempt_count' => 1,
                    'idempotent_replay' => false,
                    'direct_request_proof' => false,
                    'thinking_mode' => 'enabled',
                    'reasoning_effort' => 'high',
                    'finish_reason' => 'length',
                    'fallback_used' => false,
                    'cache_hit' => false,
                    'degraded' => true,
                    'model_attempted' => true,
                    'llm_client_invoked' => true,
                    'external_llm_called' => true,
                    'external_llm_call_status' => 'response_rejected_after_direct_call',
                ]);
            }
        };
        $ai = new OperatingQuestionAiAnswerService($fakeClient);
        $service = new OperatingQuestionService(
            static fn(): array => [
                'facts' => [self::substantiveFact(
                    812,
                    '2026-08-03',
                    'ctrip',
                    ['list_exposure' => 900, 'detail_exposure' => 180]
                )],
                'fact_count' => 1,
            ],
            static fn(array $payload): array => $ai->generate($payload)
        );

        $saved = $service->create(
            10,
            20,
            '模型响应被拒时能否保留直接回执？',
            'ctrip',
            '2026-08-03',
            '2026-08-03',
            7
        );

        $runtime = $saved['question']['answer']['ai_runtime'];
        self::assertSame('evidence_ready', $saved['question']['answer_status']);
        self::assertSame('model_unavailable', $runtime['status']);
        self::assertSame('empty_content', $runtime['reason']);
        self::assertTrue($runtime['external_llm_called']);
        self::assertSame('response_rejected_after_direct_call', $runtime['external_llm_call_status']);
        self::assertSame('resp-rejected-direct-v4-pro-0001', $runtime['provider_response_id']);
        self::assertSame('deepseek-v4-pro', $runtime['response_model']);
        self::assertSame(200, $runtime['http_status']);
        self::assertSame('length', $runtime['finish_reason']);
        self::assertStringNotContainsString('provider detail', $runtime['message']);
        self::assertSame('readback_verified', $saved['persistence_status']);
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
                self::substantiveFact(702, '2026-08-01', 'ctrip'),
                self::substantiveFact(703, '2026-08-01', 'meituan'),
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
                self::substantiveFact(704, '2026-08-01', 'ctrip'),
                self::substantiveFact(705, '2026-08-01', 'meituan'),
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
        self::assertSame('evidence_ready', $answered['question']['answer_status']);
        self::assertSame('saved_diagnosis_claim_contract_missing', $answered['question']['data_gaps'][0]['code']);
        self::assertStringNotContainsString('明确保存并回读的跨渠道诊断。', $answered['question']['answer_summary']);
        self::assertSame(['ctrip' => 1, 'meituan' => 1], $answered['question']['answer']['evidence_counts']['fact_platforms']);

        $latestFallback = new OperatingQuestionService(static fn(): array => [
            'facts' => [
                self::substantiveFact(706, '2026-08-01', 'ctrip'),
                self::substantiveFact(707, '2026-08-01', 'meituan'),
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
            10, 20, '2026-08-01 双平台诊断可否回答？', 'all_ota', '2026-08-01', '2026-08-01', 7
        );
        self::assertSame('evidence_ready', $rejected['question']['answer_status']);
        self::assertSame('all_ota_saved_diagnosis_not_current', $rejected['question']['data_gaps'][0]['code']);
        self::assertContains('diagnosis_used_latest_available_data', $rejected['question']['data_gaps'][0]['reason_codes']);
    }

    public function testGroundedAiRejectsEveryMismatchedClaimWithoutRenderingModelProse(): void
    {
        $fact = self::substantiveFact(
            9501,
            '2026-08-15',
            'ctrip',
            ['list_exposure' => 1800, 'detail_exposure' => 360]
        );
        $base = [
            'evidence_ref' => 'online_daily_data#9501',
            'metric_key' => 'list_exposure',
            'metric_definition_id' => 'ota_list_exposure.v1',
            'value' => 1800,
            'unit' => 'exposure_count',
        ];
        $cases = [
            [[...$base, 'evidence_ref' => 'online_daily_data#9999']],
            [[...$base, 'metric_definition_id' => 'ota_detail_exposure.v1']],
            [[...$base, 'value' => 1801]],
            [[...$base, 'value' => '1800']],
            [[...$base, 'unit' => 'count']],
            [$base, $base],
            [$base, [...$base, 'evidence_ref' => 'online_daily_data#9999']],
            [[
                'evidence_ref' => 'online_daily_data#9501',
                'metric_key' => 'detail_exposure',
                'metric_definition_id' => 'ota_detail_exposure.v1',
                'value' => 360,
                'unit' => 'exposure_count',
            ]],
            [$base, [
                'evidence_ref' => 'online_daily_data#9501',
                'metric_key' => 'detail_exposure',
                'metric_definition_id' => 'ota_detail_exposure.v1',
                'value' => 360,
                'unit' => 'exposure_count',
            ]],
        ];
        foreach ($cases as $index => $claims) {
            $fakeClient = new class($claims, $index) extends LlmClient {
                public function __construct(private array $claims, private int $index)
                {
                }

                public function createJsonResponseEnvelope(
                    array $messages,
                    array $schema,
                    string $modelKey = 'deepseek_v4_pro'
                ): array {
                    return [
                        'data' => [
                            'fact_claims' => $this->claims,
                            'follow_up_questions' => [],
                            'confidence' => 'medium',
                            'action_drafts' => [],
                        ],
                        'meta' => OperatingIntelligenceServiceTest::directMeta(
                            'resp-claim-case-' . str_pad((string)$this->index, 4, '0', STR_PAD_LEFT)
                        ),
                    ];
                }
            };
            $result = (new OperatingQuestionAiAnswerService($fakeClient))->generate([
                'question' => '携程列表曝光是多少？',
                'scope' => [
                    'tenant_id' => 10,
                    'hotel_id' => 20,
                    'platform' => 'ctrip',
                    'date_start' => '2026-08-15',
                    'date_end' => '2026-08-15',
                ],
                'answer' => [
                    'status' => 'evidence_ready',
                    'summary' => '确定性摘要',
                    'evidence_counts' => ['facts' => 1],
                    'fact_samples' => [$fact],
                    'question_metric_contract' => [
                        'contract_version' => OperatingQuestionService::METRIC_INTENT_CONTRACT_VERSION,
                        'mode' => 'metric_lookup',
                        'requested_metrics' => [[
                            'metric_key' => 'list_exposure',
                            'definition_ids' => ['ota_list_exposure.v1'],
                        ]],
                        'required_platforms' => ['ctrip'],
                        'required_dates' => ['2026-08-15'],
                        'action_draft_allowed' => true,
                    ],
                    'data_gaps' => [],
                ],
                'evidence' => [],
            ]);

            self::assertFalse($result['ok'], 'case ' . $index);
            self::assertSame('claim_validation_failed', $result['status'], 'case ' . $index);
            self::assertSame([], $result['action_drafts'] ?? [], 'case ' . $index);
            self::assertStringNotContainsString('模型自写事实', (string)($result['summary'] ?? ''), 'case ' . $index);
        }
    }

    public function testQuestionMetricContractBlocksWrongMetricAmbiguityScopeAndMissingUnit(): void
    {
        $calls = 0;
        $generator = static function () use (&$calls): array {
            $calls++;
            return ['ok' => false];
        };
        $exposureEvidence = static fn(): array => [
            'facts' => [self::substantiveFact(9601, '2026-08-15')],
            'fact_count' => 1,
        ];
        foreach ([
            ['2026-08-15 携程收入是多少？', 'requested_metric_fact_missing'],
            ['2026-08-15 携程 RevPAR 是多少？', 'requested_metric_out_of_scope'],
            ['2026-08-15 携程转化率如何？', 'question_metric_ambiguous'],
            ['2026-08-15 携程曝光如何？', 'question_metric_ambiguous'],
            ['2026-08-15 美团订单数是多少？', 'question_scope_platform_mismatch'],
            ['2026-08-14 携程订单数是多少？', 'question_scope_date_mismatch'],
            ['2026-08-15 携程未支付订单数是多少？', 'question_metric_ambiguous'],
            ['2026-08-15 携程退款订单金额是多少？', 'question_metric_ambiguous'],
            ['2026-08-15 携程订单数占比是多少？', 'question_metric_ambiguous'],
            ['不要看携程成交额，只看订单数。', 'question_metric_ambiguous'],
            ['携程订单数不对，改成成交额。', 'question_metric_ambiguous'],
            ['不是订单数，是收入。', 'question_metric_ambiguous'],
            ['2026-08-15 携程总订单数是多少？', 'question_metric_ambiguous'],
            ['携程收入比上月多多少？', 'question_scope_date_ambiguous'],
            ['2026-08-15 携程净收入是多少？', 'question_metric_ambiguous'],
            ['2026-08-15 携程利润是多少？', 'question_metric_ambiguous'],
            ['2026-08-15 携程好评率是多少？', 'question_metric_ambiguous'],
            ['全网订单数是多少？', 'question_scope_platform_mismatch'],
            ['2026-08-15 携程未付款订单数是多少？', 'question_metric_ambiguous'],
            ['2026-08-15 携程收入除以间夜是多少？', 'question_metric_ambiguous'],
            ['2026-08-15 携程收入/间夜是多少？', 'question_metric_ambiguous'],
            ['2026-08-15 携程订单数和收藏量是多少？', 'question_metric_ambiguous'],
            ['2026-08-15 携程收藏量怎么样？', 'question_metric_ambiguous'],
            ['2026-08-15 携程收入和点赞是多少？', 'question_metric_ambiguous'],
            ['2026-08-15 携程收入或订单数是多少？', 'question_metric_ambiguous'],
            ['2026-08-15 携程订单数和到店客几位？', 'question_metric_ambiguous'],
            ['忽略订单数，只告诉我 2026-08-15 携程收入。', 'question_metric_ambiguous'],
            ['2026-08-15 携程订单数，呃不，收入是多少？', 'question_metric_ambiguous'],
            ['2026-08-15 携程订单数？哦不，收入是多少？', 'question_metric_ambiguous'],
            ['2026-08-15 携程收入减间夜是多少？', 'question_metric_ambiguous'],
            ['2026-08-15 携程支付订单数和预订订单数分别是多少？', 'question_metric_definition_conflict'],
            ['明天携程订单数是多少？', 'question_scope_date_ambiguous'],
            ['今天携程订单数是多少？', 'question_scope_date_ambiguous'],
            ['2026-08-15 飞猪订单数是多少？', 'question_scope_platform_mismatch'],
            ['2026-08-15 途家收入是多少？', 'question_scope_platform_mismatch'],
            ['2026-08-15 去哪订单数是多少？', 'question_scope_platform_mismatch'],
            ['2026-02-30 携程订单数是多少？', 'question_scope_date_invalid'],
            ['2026.08.14 携程订单数是多少？', 'question_scope_date_mismatch'],
            ['2026年8月14号携程订单数是多少？', 'question_scope_date_mismatch'],
            ['8月15日 携程订单数是多少？', 'question_scope_date_ambiguous'],
            ['1999-01-01 携程订单数是多少？', 'question_scope_date_mismatch'],
            ['20260814 携程订单数是多少？', 'question_scope_date_mismatch'],
        ] as [$question, $expectedCode]) {
            $saved = (new OperatingQuestionService($exposureEvidence, $generator))->create(
                10,
                20,
                $question,
                'ctrip',
                '2026-08-15',
                '2026-08-15',
                7
            );
            self::assertSame('blocked_by_missing_facts', $saved['question']['answer_status']);
            self::assertContains($expectedCode, array_column($saved['question']['data_gaps'], 'code'));
            self::assertSame([], $saved['question']['answer']['action_drafts']);
        }

        $derivedAllOta = (new OperatingQuestionService($exposureEvidence, $generator))->create(
            10,
            20,
            '2026-08-15 携程订单数减去美团订单数是多少？',
            'all_ota',
            '2026-08-15',
            '2026-08-15',
            7
        );
        self::assertSame('blocked_by_missing_facts', $derivedAllOta['question']['answer_status']);
        self::assertContains('question_metric_ambiguous', array_column($derivedAllOta['question']['data_gaps'], 'code'));
        self::assertSame([], $derivedAllOta['question']['answer']['action_drafts']);

        $derivedDateRange = (new OperatingQuestionService($exposureEvidence, $generator))->create(
            10,
            20,
            '2026-08-14 和 2026-08-15 携程订单数加和是多少？',
            'ctrip',
            '2026-08-14',
            '2026-08-15',
            7
        );
        self::assertSame('blocked_by_missing_facts', $derivedDateRange['question']['answer_status']);
        self::assertContains('question_metric_ambiguous', array_column($derivedDateRange['question']['data_gaps'], 'code'));
        self::assertSame([], $derivedDateRange['question']['answer']['action_drafts']);

        $missingCurrency = self::substantiveFact(
            9602,
            '2026-08-15',
            'ctrip',
            ['amount' => 1288.5],
            ['amount' => 'currency_amount_currency_unspecified']
        );
        $unitBlocked = (new OperatingQuestionService(
            static fn(): array => ['facts' => [$missingCurrency], 'fact_count' => 1],
            $generator
        ))->create(10, 20, '2026-08-15 携程收入是多少？', 'ctrip', '2026-08-15', '2026-08-15', 7);
        self::assertContains('requested_metric_unit_missing', array_column($unitBlocked['question']['data_gaps'], 'code'));

        foreach (['ZZZ', 'XXX'] as $index => $unsupportedCurrency) {
            $invalidCurrency = self::substantiveFact(
                9630 + $index,
                '2026-08-15',
                'ctrip',
                ['amount' => 1288.5],
                ['amount' => $unsupportedCurrency]
            );
            $currencyBlocked = (new OperatingQuestionService(
                static fn(): array => ['facts' => [$invalidCurrency], 'fact_count' => 1],
                $generator
            ))->create(10, 20, '2026-08-15 携程收入是多少？', 'ctrip', '2026-08-15', '2026-08-15', 7);
            self::assertContains(
                'requested_metric_unit_missing',
                array_column($currencyBlocked['question']['data_gaps'], 'code')
            );
            self::assertSame([], $currencyBlocked['question']['answer']['action_drafts']);
        }

        $wrongDefinition = self::substantiveFact(9603, '2026-08-15', 'ctrip', ['amount' => 1288.5]);
        $wrongDefinition['metric_definitions']['amount']['definition_id'] = 'ota_ad_spend.v1';
        $definitionBlocked = (new OperatingQuestionService(
            static fn(): array => ['facts' => [$wrongDefinition], 'fact_count' => 1],
            $generator
        ))->create(10, 20, '2026-08-15 携程收入是多少？', 'ctrip', '2026-08-15', '2026-08-15', 7);
        self::assertContains('requested_metric_definition_mismatch', array_column($definitionBlocked['question']['data_gaps'], 'code'));

        $invalidPercent = self::substantiveFact(
            9604,
            '2026-08-15',
            'ctrip',
            ['flow_rate' => 150.0],
            ['flow_rate' => 'percent']
        );
        $scaleBlocked = (new OperatingQuestionService(
            static fn(): array => ['facts' => [$invalidPercent], 'fact_count' => 1],
            $generator
        ))->create(10, 20, '2026-08-15 携程浏览到支付转化率是多少？', 'ctrip', '2026-08-15', '2026-08-15', 7);
        self::assertContains(
            'requested_metric_value_scale_mismatch',
            array_column($scaleBlocked['question']['data_gaps'], 'code')
        );
        self::assertSame(0, $calls);

        $submitCalls = 0;
        $submitFact = self::substantiveFact(
            9605,
            '2026-08-15',
            'ctrip',
            ['order_submit_num' => 18]
        );
        $submitReady = (new OperatingQuestionService(
            static fn(): array => ['facts' => [$submitFact], 'fact_count' => 1],
            static function () use (&$submitCalls): array {
                $submitCalls++;
                return ['ok' => false];
            }
        ))->create(10, 20, '2026-08-15 携程提交订单数是多少？', 'ctrip', '2026-08-15', '2026-08-15', 7);
        self::assertSame(1, $submitCalls);
        self::assertSame(
            OperatingQuestionService::METRIC_INTENT_CONTRACT_VERSION,
            $submitReady['question']['answer']['question_metric_contract']['contract_version']
        );
        self::assertSame(
            ['order_submit_num'],
            array_column($submitReady['question']['answer']['question_metric_contract']['requested_metrics'], 'metric_key')
        );

        foreach ([
            [
                '2026-08-15 携程提交订单数是多少？',
                'order_submit_num',
                'ota_paid_order_count.v1',
                'paid_order_count',
            ],
            [
                '2026-08-15 携程曝光量是多少？',
                'list_exposure',
                'ota_list_exposure.v1',
                'list_exposure',
            ],
            [
                '2026-08-15 携程详情曝光是多少？',
                'detail_exposure',
                'ota_detail_exposure.v1',
                'detail_exposure',
            ],
        ] as $index => [$question, $metricKey, $wrongDefinitionId, $wrongSourceMetricKey]) {
            $wrongSemanticFact = self::substantiveFact(
                9610 + $index,
                '2026-08-15',
                'ctrip',
                [$metricKey => 18]
            );
            $wrongSemanticFact['metric_definitions'][$metricKey]['definition_id'] = $wrongDefinitionId;
            $wrongSemanticFact['metric_definitions'][$metricKey]['source_metric_key'] = $wrongSourceMetricKey;
            $blocked = (new OperatingQuestionService(
                static fn(): array => ['facts' => [$wrongSemanticFact], 'fact_count' => 1],
                $generator
            ))->create(10, 20, $question, 'ctrip', '2026-08-15', '2026-08-15', 7);
            self::assertSame('blocked_by_missing_facts', $blocked['question']['answer_status']);
            self::assertContains(
                'requested_metric_definition_mismatch',
                array_column($blocked['question']['data_gaps'], 'code')
            );
            self::assertSame([], $blocked['question']['answer']['action_drafts']);
        }
        self::assertSame(0, $calls);

        foreach ([
            ['2026-08-15 携程列表曝光用户数是多少？', 'list_exposure', 'requested_metric_definition_mismatch'],
            ['2026-08-15 携程详情曝光用户数是多少？', 'detail_exposure', 'requested_metric_definition_mismatch'],
            ['2026-08-15 携程曝光用户数是多少？', 'list_exposure', 'question_metric_ambiguous'],
            ['2026-08-15 携程广告曝光量是多少？', 'list_exposure', 'question_metric_ambiguous'],
        ] as $index => [$question, $metricKey, $expectedCode]) {
            $countFact = self::substantiveFact(
                9620 + $index,
                '2026-08-15',
                'ctrip',
                [$metricKey => 100]
            );
            $blocked = (new OperatingQuestionService(
                static fn(): array => ['facts' => [$countFact], 'fact_count' => 1],
                $generator
            ))->create(10, 20, $question, 'ctrip', '2026-08-15', '2026-08-15', 7);
            self::assertSame('blocked_by_missing_facts', $blocked['question']['answer_status']);
            self::assertContains($expectedCode, array_column($blocked['question']['data_gaps'], 'code'));
            self::assertSame([], $blocked['question']['answer']['action_drafts']);
        }
        self::assertSame(0, $calls);
    }

    public function testProductionSourceKeysCannotTurnVisitorOrAdvertisingFieldsIntoExposureCounts(): void
    {
        $calls = 0;
        $generator = static function () use (&$calls): array {
            $calls++;
            return ['ok' => false];
        };
        $reader = new OperatingQuestionService();
        $loadFacts = new \ReflectionMethod($reader, 'loadFacts');
        $loadFacts->setAccessible(true);
        $cases = [
            ['2026-08-20', 'list_exposure', 'exposureUV', '2026-08-20 携程列表曝光是多少？', 'ota_list_exposure_users.v1', null],
            ['2026-08-21', 'detail_exposure', 'intentionUV', '2026-08-21 携程详情曝光是多少？', 'ota_detail_visitors.v1', null],
            ['2026-08-22', 'detail_exposure', 'uv', '2026-08-22 携程详情曝光是多少？', 'ota_detail_visitors.v1', null],
            ['2026-08-23', 'detail_exposure', 'visitors', '2026-08-23 携程详情曝光是多少？', 'ota_detail_visitors.v1', null],
            ['2026-08-24', 'list_exposure', 'adExposure', '2026-08-24 携程列表曝光是多少？', '', 'requested_metric_fact_missing'],
            ['2026-08-25', 'list_exposure', 'listExposure', '2026-08-25 携程列表页曝光量是多少？', 'ota_list_exposure_users.v1', null],
            ['2026-08-26', 'detail_exposure', 'detailExposure', '2026-08-26 携程详情曝光是多少？', 'ota_detail_visitors.v1', null],
            ['2026-08-27', 'list_exposure', 'impressions', '2026-08-27 携程列表曝光是多少？', '', 'requested_metric_fact_missing'],
        ];
        foreach ($cases as $index => [$date, $metric, $sourceKey, $question, $definitionId, $gapCode]) {
            Db::name('online_daily_data')->insert([
                'tenant_id' => 10,
                'system_hotel_id' => 20,
                'data_date' => $date,
                'platform' => 'ctrip',
                'source' => 'ctrip',
                'data_type' => 'traffic',
                'dimension' => '',
                'readback_verified' => 1,
                'readback_verified_at' => $date . ' 10:00:00',
                'validation_status' => 'verified',
                'history_status' => 'success',
                'ingestion_method' => 'browser_profile',
                'source_trace_id' => 'source-semantic-' . $index,
                $metric => 100 + $index,
                'raw_data' => json_encode(['field_facts' => [[
                    'metric_key' => $metric,
                    'data_type' => 'traffic',
                    'source_key' => $sourceKey,
                    'source_path' => 'payload.' . $sourceKey,
                    'storage_field' => 'online_daily_data.' . $metric,
                    'status' => 'captured',
                    'stored_value_present' => true,
                ]]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
            $facts = $loadFacts->invoke($reader, 10, 20, 'ctrip', $date, $date);
            self::assertCount(1, $facts);
            if ($definitionId !== '') {
                self::assertSame($definitionId, $facts[0]['metric_definitions'][$metric]['definition_id']);
                self::assertSame('visitor_count', $facts[0]['metric_units'][$metric]);
            } else {
                self::assertSame([], $facts[0]['metric_values']);
            }
            $saved = (new OperatingQuestionService(
                static fn(): array => ['facts' => $facts, 'fact_count' => 1],
                $generator
            ))->create(10, 20, $question, 'ctrip', $date, $date, 7);
            if ($gapCode === null) {
                self::assertSame('evidence_ready', $saved['question']['answer_status']);
                self::assertSame(
                    [$definitionId],
                    $saved['question']['answer']['question_metric_contract']['requested_metrics'][0]['definition_ids']
                );
            } else {
                self::assertSame('blocked_by_missing_facts', $saved['question']['answer_status']);
                self::assertContains($gapCode, array_column($saved['question']['data_gaps'], 'code'));
            }
            self::assertSame([], $saved['question']['answer']['action_drafts']);
        }
        self::assertSame(6, $calls);
    }

    public function testConflictingSourceDefinitionsForSameStorageFailClosedRegardlessOfOrder(): void
    {
        $visitorFact = [
            'metric_key' => 'list_exposure',
            'data_type' => 'traffic',
            'source_key' => 'exposureUV',
            'source_path' => 'payload.exposureUV',
            'storage_field' => 'online_daily_data.list_exposure',
            'status' => 'captured',
            'stored_value_present' => true,
        ];
        $countFact = [
            'metric_key' => 'mt_exposure',
            'data_type' => 'traffic',
            'source_key' => 'mt_exposure',
            'source_path' => 'payload.mt_exposure',
            'storage_field' => 'online_daily_data.list_exposure',
            'status' => 'captured',
            'stored_value_present' => true,
        ];
        $reader = new OperatingQuestionService();
        $loadFacts = new \ReflectionMethod($reader, 'loadFacts');
        $loadFacts->setAccessible(true);
        $calls = 0;
        $generator = static function () use (&$calls): array {
            $calls++;
            return ['ok' => false];
        };

        foreach ([
            ['2026-08-31', [$visitorFact, $countFact]],
            ['2026-09-01', [$countFact, $visitorFact]],
        ] as $index => [$date, $fieldFacts]) {
            Db::name('online_daily_data')->insert([
                'tenant_id' => 10,
                'system_hotel_id' => 20,
                'data_date' => $date,
                'platform' => 'meituan',
                'source' => 'meituan',
                'data_type' => 'traffic',
                'dimension' => '',
                'readback_verified' => 1,
                'readback_verified_at' => $date . ' 10:00:00',
                'validation_status' => 'verified',
                'history_status' => 'success',
                'ingestion_method' => 'browser_profile',
                'source_trace_id' => 'source-definition-conflict-' . $index,
                'list_exposure' => 100,
                'raw_data' => json_encode(
                    ['field_facts' => $fieldFacts],
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ),
            ]);
            $facts = $loadFacts->invoke($reader, 10, 20, 'meituan', $date, $date);
            self::assertSame([], $facts[0]['metric_values']);
            self::assertContains(
                'metric_source_definition_conflict',
                array_column($facts[0]['metric_gaps'], 'reason')
            );
            $blocked = (new OperatingQuestionService(
                static fn(): array => ['facts' => $facts, 'fact_count' => 1],
                $generator
            ))->create(10, 20, $date . ' 美团列表曝光是多少？', 'meituan', $date, $date, 7);
            self::assertSame('blocked_by_missing_facts', $blocked['question']['answer_status']);
            self::assertContains('requested_metric_fact_missing', array_column($blocked['question']['data_gaps'], 'code'));
            self::assertSame([], $blocked['question']['answer']['action_drafts']);
        }
        self::assertSame(0, $calls);
    }

    public function testOrdinaryBookingOrdersCannotSatisfyAPaidOrderQuestion(): void
    {
        Db::name('online_daily_data')->insert([
            'tenant_id' => 10,
            'system_hotel_id' => 20,
            'data_date' => '2026-08-28',
            'platform' => 'ctrip',
            'source' => 'ctrip',
            'data_type' => 'order',
            'dimension' => '',
            'readback_verified' => 1,
            'readback_verified_at' => '2026-08-28 10:00:00',
            'validation_status' => 'verified',
            'history_status' => 'success',
            'ingestion_method' => 'browser_profile',
            'source_trace_id' => 'booking-order-semantic',
            'book_order_num' => 12,
            'raw_data' => json_encode(['field_facts' => [[
                'metric_key' => 'order_count',
                'data_type' => 'order',
                'source_key' => 'orderCount',
                'source_path' => 'payload.orderCount',
                'storage_field' => 'online_daily_data.book_order_num',
                'status' => 'captured',
                'stored_value_present' => true,
            ]]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);

        $reader = new OperatingQuestionService();
        $loadFacts = new \ReflectionMethod($reader, 'loadFacts');
        $loadFacts->setAccessible(true);
        $facts = $loadFacts->invoke($reader, 10, 20, 'ctrip', '2026-08-28', '2026-08-28');
        self::assertSame('ota_booking_order_count.v1', $facts[0]['metric_definitions']['book_order_num']['definition_id']);
        self::assertSame('order_count', $facts[0]['metric_units']['book_order_num']);

        $calls = 0;
        $generator = static function () use (&$calls): array {
            $calls++;
            return ['ok' => false];
        };
        $blocked = (new OperatingQuestionService(
            static fn(): array => ['facts' => $facts, 'fact_count' => 1],
            $generator
        ))->create(
            10,
            20,
            '2026-08-28 携程支付订单数是多少？',
            'ctrip',
            '2026-08-28',
            '2026-08-28',
            7
        );
        self::assertSame('blocked_by_missing_facts', $blocked['question']['answer_status']);
        self::assertContains('requested_metric_definition_mismatch', array_column($blocked['question']['data_gaps'], 'code'));
        self::assertSame([], $blocked['question']['answer']['action_drafts']);
        self::assertSame(0, $calls);

        $booking = (new OperatingQuestionService(
            static fn(): array => ['facts' => $facts, 'fact_count' => 1],
            $generator
        ))->create(
            10,
            20,
            '2026-08-28 携程预订订单数是多少？',
            'ctrip',
            '2026-08-28',
            '2026-08-28',
            7
        );
        self::assertSame('evidence_ready', $booking['question']['answer_status']);
        self::assertSame(
            ['ota_booking_order_count.v1'],
            $booking['question']['answer']['question_metric_contract']['requested_metrics'][0]['definition_ids']
        );
        self::assertSame(1, $calls);
    }

    public function testQunarRatingUsesItsRealSourceTupleAndPollutedAmountTupleFailsClosed(): void
    {
        Db::name('online_daily_data')->insert([
            'tenant_id' => 10,
            'system_hotel_id' => 20,
            'data_date' => '2026-08-29',
            'platform' => 'qunar',
            'source' => 'qunar',
            'data_type' => 'quality',
            'dimension' => '',
            'readback_verified' => 1,
            'readback_verified_at' => '2026-08-29 10:00:00',
            'validation_status' => 'verified',
            'history_status' => 'success',
            'ingestion_method' => 'browser_profile',
            'source_trace_id' => 'qunar-rating-semantic',
            'qunar_comment_score' => 4.7,
            'raw_data' => json_encode(['field_facts' => [[
                'metric_key' => 'qunar_rating',
                'data_type' => 'quality',
                'source_key' => 'qunarRatingall',
                'source_path' => 'data.qunarRatingall',
                'storage_field' => 'online_daily_data.qunar_comment_score',
                'status' => 'captured',
                'stored_value_present' => true,
            ]]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        Db::name('online_daily_data')->insert([
            'tenant_id' => 10,
            'system_hotel_id' => 20,
            'data_date' => '2026-08-30',
            'platform' => 'qunar',
            'source' => 'qunar',
            'data_type' => 'advertising',
            'dimension' => '',
            'readback_verified' => 1,
            'readback_verified_at' => '2026-08-30 10:00:00',
            'validation_status' => 'verified',
            'history_status' => 'success',
            'ingestion_method' => 'browser_profile',
            'source_trace_id' => 'polluted-amount-semantic',
            'amount' => 88,
            'raw_data' => json_encode(['field_facts' => [[
                'metric_key' => 'paid_order_amount',
                'data_type' => 'advertising',
                'source_key' => 'todayCost',
                'source_path' => 'payload.todayCost',
                'storage_field' => 'online_daily_data.amount',
                'currency_code' => 'CNY',
                'status' => 'captured',
                'stored_value_present' => true,
            ]]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);

        $reader = new OperatingQuestionService();
        $loadFacts = new \ReflectionMethod($reader, 'loadFacts');
        $loadFacts->setAccessible(true);
        $ratingFacts = $loadFacts->invoke($reader, 10, 20, 'qunar', '2026-08-29', '2026-08-29');
        self::assertSame(4.7, $ratingFacts[0]['metric_values']['qunar_comment_score']);
        self::assertSame('score_5_point', $ratingFacts[0]['metric_units']['qunar_comment_score']);
        self::assertSame(
            'ota_comment_score_5_point.v1',
            $ratingFacts[0]['metric_definitions']['qunar_comment_score']['definition_id']
        );

        $calls = 0;
        $generator = static function () use (&$calls): array {
            $calls++;
            return ['ok' => false];
        };
        $rating = (new OperatingQuestionService(
            static fn(): array => ['facts' => $ratingFacts, 'fact_count' => 1],
            $generator
        ))->create(10, 20, '2026-08-29 去哪儿评分是多少？', 'qunar', '2026-08-29', '2026-08-29', 7);
        self::assertSame('evidence_ready', $rating['question']['answer_status']);
        self::assertSame(1, $calls);

        $pollutedFacts = $loadFacts->invoke($reader, 10, 20, 'qunar', '2026-08-30', '2026-08-30');
        self::assertSame([], $pollutedFacts[0]['metric_values']);
        $blocked = (new OperatingQuestionService(
            static fn(): array => ['facts' => $pollutedFacts, 'fact_count' => 1],
            $generator
        ))->create(10, 20, '2026-08-30 去哪儿收入是多少？', 'qunar', '2026-08-30', '2026-08-30', 7);
        self::assertSame('blocked_by_missing_facts', $blocked['question']['answer_status']);
        self::assertContains('requested_metric_fact_missing', array_column($blocked['question']['data_gaps'], 'code'));
        self::assertSame([], $blocked['question']['answer']['action_drafts']);
        self::assertSame(1, $calls);
    }

    public function testEveryRequestedClaimRemainsVisibleThroughTheEighthClaim(): void
    {
        $metrics = [
            'amount' => 1288.5,
            'quantity' => 12,
            'book_order_num' => 6,
            'comment_score' => 4.8,
            'list_exposure' => 1800,
            'detail_exposure' => 360,
            'flow_rate' => 20.0,
            'order_filling_num' => 90,
        ];
        $fact = self::substantiveFact(9660, '2026-08-15', 'ctrip', $metrics);
        foreach ([
            'list_exposure' => ['ota_list_exposure_users.v1', 'exposure_users', 'visitor_count', '曝光用户数'],
            'detail_exposure' => ['ota_detail_visitors.v1', 'detail_visitors', 'visitor_count', '详情访问用户数'],
        ] as $metricKey => [$definitionId, $sourceMetricKey, $unit, $label]) {
            $fact['metric_units'][$metricKey] = $unit;
            $fact['metric_definitions'][$metricKey]['definition_id'] = $definitionId;
            $fact['metric_definitions'][$metricKey]['source_metric_key'] = $sourceMetricKey;
            $fact['metric_definitions'][$metricKey]['unit'] = $unit;
            $fact['metric_definitions'][$metricKey]['label'] = $label;
        }
        $definitions = [
            'amount' => 'ota_paid_order_amount.v1',
            'quantity' => 'ota_paid_room_nights.v1',
            'book_order_num' => 'ota_paid_order_count.v1',
            'comment_score' => 'ota_comment_score_5_point.v1',
            'list_exposure' => 'ota_list_exposure_users.v1',
            'detail_exposure' => 'ota_detail_visitors.v1',
            'flow_rate' => 'ota_browse_to_pay_rate.v1',
            'order_filling_num' => 'ota_order_filling_count.v1',
        ];
        $claims = [];
        foreach ($definitions as $metric => $definitionId) {
            $claims[] = [
                'evidence_ref' => 'online_daily_data#9660',
                'metric_key' => $metric,
                'metric_definition_id' => $definitionId,
                'value' => $metrics[$metric],
                'unit' => $fact['metric_units'][$metric],
            ];
        }
        $client = new class($claims) extends LlmClient {
            public function __construct(private readonly array $claims)
            {
            }

            public function createJsonResponseEnvelope(
                array $messages,
                array $schema,
                string $modelKey = 'deepseek_v4_pro'
            ): array {
                return [
                    'data' => [
                        'fact_claims' => $this->claims,
                        'follow_up_questions' => [],
                        'confidence' => 'medium',
                        'action_drafts' => [],
                    ],
                    'meta' => OperatingIntelligenceServiceTest::directMeta('resp-all-visible-claims-0001'),
                ];
            }
        };
        $ai = new OperatingQuestionAiAnswerService($client);
        $saved = (new OperatingQuestionService(
            static fn(): array => ['facts' => [$fact], 'fact_count' => 1],
            static fn(array $payload): array => $ai->generate($payload)
        ))->create(
            10,
            20,
            '2026-08-15 携程收入、间夜、支付订单数、点评分、列表曝光用户数、详情曝光用户数、浏览到支付转化率和填单数是多少？',
            'ctrip',
            '2026-08-15',
            '2026-08-15',
            7
        );
        self::assertSame('answered_by_grounded_ai', $saved['question']['answer_status']);
        self::assertCount(8, $saved['question']['answer']['fact_claims']);
        self::assertCount(8, $saved['question']['answer']['key_points']);
        self::assertStringContainsString('详情访问用户数为360人', $saved['question']['answer_summary']);
        self::assertStringContainsString('详情访问用户数为360人', $saved['question']['answer']['key_points'][5]);
        self::assertStringContainsString('填单数为90单', $saved['question']['answer_summary']);
        self::assertStringContainsString('填单数为90单', $saved['question']['answer']['key_points'][7]);
    }

    public function testProviderResponseRegistryRejectsGlobalReplayAndRollsBackQuestion(): void
    {
        $fixedReceiptClient = new class extends LlmClient {
            public function createJsonResponseEnvelope(
                array $messages,
                array $schema,
                string $modelKey = 'deepseek_v4_pro'
            ): array {
                return [
                    'data' => [
                        'fact_claims' => [[
                            'evidence_ref' => 'online_daily_data#9701',
                            'metric_key' => 'amount',
                            'metric_definition_id' => 'ota_paid_order_amount.v1',
                            'value' => 321,
                            'unit' => 'CNY',
                        ]],
                        'follow_up_questions' => [],
                        'confidence' => 'medium',
                        'action_drafts' => [],
                    ],
                    'meta' => OperatingIntelligenceServiceTest::directMeta('resp-global-replay-0001'),
                ];
            }
        };
        $ai = new OperatingQuestionAiAnswerService($fixedReceiptClient);
        $service = new OperatingQuestionService(
            static fn(): array => [
                'facts' => [self::substantiveFact(9701, '2026-08-15', 'ctrip', ['amount' => 321])],
                'fact_count' => 1,
            ],
            static fn(array $payload): array => $ai->generate($payload)
        );

        $first = $service->create(
            10,
            20,
            '2026-08-15 携程收入是多少？',
            'ctrip',
            '2026-08-15',
            '2026-08-15',
            7
        );
        self::assertSame('answered_by_grounded_ai', $first['question']['answer_status']);
        self::assertSame(1, (int)Db::name('hotel_operating_questions')->count());
        self::assertSame(1, (int)Db::name('hotel_operating_question_model_responses')->count());

        try {
            $service->create(
                11,
                30,
                '2026-08-15 携程收入是多少？',
                'ctrip',
                '2026-08-15',
                '2026-08-15',
                9
            );
            self::fail('the same upstream receipt must be globally single-use');
        } catch (\RuntimeException $exception) {
            self::assertSame('provider_response_replay_rejected', $exception->getMessage());
        }
        self::assertSame(1, (int)Db::name('hotel_operating_questions')->count());
        self::assertSame(1, (int)Db::name('hotel_operating_question_model_responses')->count());
        self::assertSame(0, (int)Db::name('hotel_operating_questions')->where('tenant_id', 11)->count());
    }

    public function testProviderReceiptRequiresRegistryBeforeQuestionInsert(): void
    {
        Db::execute('DROP TABLE hotel_operating_question_model_responses');
        $service = new OperatingQuestionService(
            static fn(): array => [
                'facts' => [self::substantiveFact(9801, '2026-08-15', 'ctrip', ['amount' => 321])],
                'fact_count' => 1,
            ],
            static fn(): array => [
                'ok' => true,
                'status' => 'ready',
                'summary' => '不得被采用的自由文本',
                'fact_claims' => [],
                'claims_digest' => str_repeat('0', 64),
                'confidence' => 'medium',
                'provider' => 'deepseek',
                'provider_response_id' => 'resp-missing-registry-0001',
                'prompt_version' => OperatingQuestionAiAnswerService::PROMPT_VERSION,
                'finish_reason' => 'stop',
                'model_attempted' => true,
                'llm_client_invoked' => true,
                'external_llm_called' => true,
                'external_llm_call_status' => OperatingQuestionAiAnswerService::DIRECT_CALL_STATUS,
                'fallback_used' => false,
                'cache_hit' => false,
                'degraded' => false,
                ...self::directMeta('resp-missing-registry-0001'),
            ]
        );
        try {
            $service->create(10, 20, '2026-08-15 携程收入是多少？', 'ctrip', '2026-08-15', '2026-08-15', 7);
            self::fail('a provider receipt cannot be saved without the global registry');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('回放登记表缺失', $exception->getMessage());
        }
        self::assertSame(0, (int)Db::name('hotel_operating_questions')->count());
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

    /**
     * @param array<string,int|float> $metrics
     * @param array<string,string> $units
     * @return array<string,mixed>
     */
    private static function substantiveFact(
        int $id,
        string $date,
        string $platform = 'ctrip',
        array $metrics = ['list_exposure' => 100],
        array $units = []
    ): array {
        $semantics = [
            'list_exposure' => ['ota_list_exposure.v1', 'list_exposure', 'exposure_count', '列表曝光'],
            'detail_exposure' => ['ota_detail_exposure.v1', 'detail_exposure', 'exposure_count', '详情曝光'],
            'flow_rate' => ['ota_browse_to_pay_rate.v1', 'browse_to_pay_rate', 'percent', '浏览到支付转化率'],
            'amount' => ['ota_paid_order_amount.v1', 'paid_order_amount', 'CNY', '渠道支付订单金额'],
            'quantity' => ['ota_paid_room_nights.v1', 'room_nights', 'room_night_count', '渠道间夜'],
            'book_order_num' => ['ota_paid_order_count.v1', 'paid_order_count', 'order_count', '渠道支付订单数'],
            'comment_score' => ['ota_comment_score_5_point.v1', 'comment_score', 'score_5_point', '渠道点评分'],
            'order_filling_num' => ['ota_order_filling_count.v1', 'order_filling_num', 'order_count', '填单数'],
            'order_submit_num' => ['ota_order_submit_count.v1', 'order_submit_num', 'order_count', '提交订单数'],
        ];
        $metricUnits = [];
        $definitions = [];
        foreach ($metrics as $metric => $_value) {
            [$definitionId, $sourceMetricKey, $defaultUnit, $label] = $semantics[$metric]
                ?? ['ota_test_metric.v1', $metric, 'count', $metric];
            $unit = (string)($units[$metric] ?? $defaultUnit);
            $metricUnits[$metric] = $unit;
            $definitions[$metric] = [
                'claimable' => true,
                'definition_id' => $definitionId,
                'source_metric_key' => $sourceMetricKey,
                'source_data_type' => 'traffic',
                'source_key' => $sourceMetricKey,
                'storage_field' => 'online_daily_data.' . $metric,
                'source_path_digest' => hash('sha256', 'payload.' . $metric),
                'field_fact_digest' => hash('sha256', 'field-fact-' . $metric . '-' . $unit),
                'unit' => $unit,
                'unit_status' => 'verified',
                'unit_source' => in_array($unit, ['CNY', 'percent', 'ratio_0_1'], true)
                    ? 'field_fact'
                    : 'operating_question_metric_semantics.v1',
                'label' => $label,
            ];
        }
        return [
            'ref' => 'online_daily_data#' . $id,
            'data_date' => $date,
            'platform' => $platform,
            'data_type' => 'traffic',
            'quality_status' => 'verified',
            'history_status' => 'success',
            'readback_status' => 'readback_verified',
            'readback_verified_at' => $date . ' 10:00:00',
            'ingestion_method' => 'browser_profile',
            'source_trace_id' => 'trace-' . $id,
            'metric_values' => $metrics,
            'metric_units' => $metricUnits,
            'metric_definitions' => $definitions,
        ];
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

    public function testOperatingQuestionAcceptsPinnedLocalSecondBrainWithoutReportingExternalCall(): void
    {
        $fakeClient = new class extends LlmClient {
            public function createJsonResponseEnvelope(
                array $messages,
                array $schema,
                string $modelKey = 'deepseek_v4_default'
            ): array {
                return [
                    'data' => [
                        'answer_summary' => '本机第二大脑仅根据已回读的携程曝光事实给出只读判断。',
                        'key_points' => ['当前事实只覆盖携程渠道。'],
                        'missing_information' => [],
                        'follow_up_questions' => [],
                        'confidence' => 'medium',
                        'used_evidence_refs' => ['online_daily_data#9201'],
                        'action_drafts' => [],
                    ],
                    'meta' => [
                        'provider' => 'ollama',
                        'model_key' => 'local_second_brain',
                        'model' => 'qwen3:8b',
                        'finish_reason' => 'stop',
                        'fallback_used' => false,
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
                    'ref' => 'online_daily_data#9201',
                    'data_date' => '2026-08-10',
                    'platform' => 'ctrip',
                    'metric_values' => ['list_exposure' => 100],
                    'metric_units' => ['list_exposure' => 'exposure_count'],
                ]],
            ],
            'evidence' => [],
            'model_key' => 'local_second_brain',
            'user_id' => 7,
        ]);

        self::assertTrue($result['ok']);
        self::assertSame('ready', $result['status']);
        self::assertSame('ollama', $result['provider']);
        self::assertSame('qwen3:8b', $result['model']);
        self::assertFalse($result['external_llm_called']);
        self::assertSame('confirmed_local_success', $result['external_llm_call_status']);
    }
}
