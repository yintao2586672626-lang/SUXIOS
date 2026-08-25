<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AiGovernance;
use app\controller\Base;
use app\service\AiEvaluationRunService;
use app\service\LocalMediaExtractionService;
use app\service\OperatingMemoryRetrievalService;
use app\service\OperatingMemoryService;
use app\service\OperatingQuestionCouncilService;
use app\service\OperatingQuestionService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;
use think\Request;

final class LocalSecondBrainContractServiceTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App(dirname(__DIR__));
        $app->initialize();

        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'local_second_brain_contract_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';
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
            'ai_evaluation_runs',
            'hotel_operating_question_council_runs',
            'hotel_operating_questions',
            'hotel_operating_memories',
            'local_media_extractions',
        ] as $table) {
            Db::execute('DROP TABLE IF EXISTS ' . $table);
        }

        $this->createAiEvaluationSchema();
        $this->createOperatingMemorySchema();
        $this->createLocalMediaSchema();
        $this->createOperatingQuestionCouncilSchema();
    }

    public function testEvaluationRunExactReadbackCoversRunKeyCreatorAndMarkerRepair(): void
    {
        $service = new AiEvaluationRunService();
        $result = $this->evaluationResult();

        $saved = $service->save(
            'eval-local-run-0001',
            'ota_local_second_brain_v1',
            'local_second_brain',
            ['status' => 'active', 'scenario' => 'ota_diagnosis'],
            $result,
            17
        );

        self::assertTrue($saved['created']);
        self::assertTrue($saved['readback_verified']);
        self::assertSame('readback_verified', $saved['persistence_status']);
        self::assertSame(17, $saved['created_by']);
        self::assertSame(1, (int)Db::name(AiEvaluationRunService::TABLE)
            ->where('id', (int)$saved['id'])
            ->value('readback_verified'));

        Db::name(AiEvaluationRunService::TABLE)
            ->where('id', (int)$saved['id'])
            ->update(['readback_verified' => 0]);

        $replayed = $service->save(
            'eval-local-run-0001',
            'ota_local_second_brain_v1',
            'local_second_brain',
            ['status' => 'active', 'scenario' => 'ota_diagnosis'],
            $result,
            17
        );
        self::assertFalse($replayed['created']);
        self::assertTrue($replayed['readback_verified']);
        self::assertSame(1, (int)Db::name(AiEvaluationRunService::TABLE)->count());

        try {
            $service->save(
                'eval-local-run-0001',
                'ota_local_second_brain_v1',
                'local_second_brain',
                ['status' => 'active', 'scenario' => 'ota_diagnosis'],
                $result,
                18
            );
            self::fail('The same client run key must not be reusable by a different creator.');
        } catch (RuntimeException $exception) {
            self::assertSame(409, $exception->getCode());
            self::assertStringContainsString('client_run_key', $exception->getMessage());
        }
    }

    public function testEvaluationRunReadRejectsRunKeyAndPayloadTampering(): void
    {
        $service = new AiEvaluationRunService();
        $saved = $service->save(
            'eval-local-run-0002',
            'ota_local_second_brain_v1',
            'local_second_brain',
            ['status' => 'active'],
            $this->evaluationResult(),
            17
        );

        Db::name(AiEvaluationRunService::TABLE)
            ->where('id', (int)$saved['id'])
            ->update(['client_run_key' => 'eval-tampered-run-0002']);
        $this->assertRuntimeFailure(
            static fn(): array => $service->read((int)$saved['id']),
            '评测批次回读摘要不一致'
        );

        Db::name(AiEvaluationRunService::TABLE)
            ->where('id', (int)$saved['id'])
            ->update([
                'client_run_key' => 'eval-local-run-0002',
                'result_json' => json_encode(['dry_run' => true, 'summary' => ['total' => 999]], JSON_THROW_ON_ERROR),
            ]);
        $this->assertRuntimeFailure(
            static fn(): array => $service->read((int)$saved['id']),
            '评测批次回读摘要不一致'
        );
    }

    public function testGovernanceReplayRepairsInterruptedReadbackMarkerBeforeClaimingReplay(): void
    {
        $service = new AiEvaluationRunService();
        $saved = $service->save(
            'eval-local-run-repair-0003',
            'ota_local_second_brain_v1',
            'local_second_brain',
            ['scenario' => '', 'prompt_version' => '', 'case_keys' => [], 'limit' => 50],
            $this->evaluationResult(),
            17
        );
        Db::name(AiEvaluationRunService::TABLE)
            ->where('id', (int)$saved['id'])
            ->update(['readback_verified' => 0]);

        $reflection = new ReflectionClass(AiGovernance::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $base = new ReflectionClass(Base::class);
        $currentUser = $base->getProperty('currentUser');
        $currentUser->setValue($controller, new class {
            public int $id = 17;

            public function isSuperAdmin(): bool
            {
                return true;
            }
        });
        $request = $base->getProperty('request');
        $request->setValue($controller, (new Request())->withPost([
            'evaluation_set' => 'ota_local_second_brain_v1',
            'client_run_key' => 'eval-local-run-repair-0003',
            'model_key' => 'local_second_brain',
            'dry_run' => true,
            'allow_external_model_call' => false,
            'limit' => 50,
        ]));

        $payload = json_decode((string)$controller->replayEvaluationCases()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(200, $payload['code']);
        self::assertTrue($payload['data']['replayed']);
        self::assertSame('readback_verified', $payload['data']['persistence_status']);
        self::assertTrue($payload['data']['run']['readback_verified']);
        self::assertSame(1, (int)Db::name(AiEvaluationRunService::TABLE)
            ->where('id', (int)$saved['id'])
            ->value('readback_verified'));
        self::assertSame(1, (int)Db::name(AiEvaluationRunService::TABLE)->count());
    }

    public function testAllOtaRetrievalIncludesAllOtaKeepsHotelScopeAndFallsBackToLexical(): void
    {
        $ctripId = $this->insertMemory(10, 20, 'ctrip', '携程曝光下降复核');
        $meituanId = $this->insertMemory(10, 20, 'meituan', '美团曝光下降复核');
        $allOtaId = $this->insertMemory(10, 20, 'all_ota', '全渠道曝光下降复核');
        $otherHotelId = $this->insertMemory(10, 21, 'all_ota', '其他酒店曝光下降复核');
        $embedderCalls = 0;
        $service = new OperatingMemoryRetrievalService(
            static function (array $texts) use (&$embedderCalls): array {
                $embedderCalls++;
                throw new RuntimeException('local embedding temporarily unavailable');
            }
        );

        $result = $service->retrieve(
            10,
            20,
            'all_ota',
            '曝光下降怎么复核',
            '2026-08-01',
            '2026-08-31',
            10
        );

        self::assertSame(1, $embedderCalls);
        self::assertSame('matched', $result['status']);
        self::assertSame(OperatingMemoryRetrievalService::METHOD, $result['method']);
        self::assertSame('fallback_lexical', $result['embedding']['status']);
        self::assertEqualsCanonicalizing(
            ['ctrip', 'meituan', 'all_ota'],
            array_column($result['items'], 'platform')
        );
        self::assertEqualsCanonicalizing(
            [
                'hotel_operating_memories#' . $ctripId,
                'hotel_operating_memories#' . $meituanId,
                'hotel_operating_memories#' . $allOtaId,
            ],
            array_column($result['items'], 'ref')
        );
        self::assertNotContains(
            'hotel_operating_memories#' . $otherHotelId,
            array_column($result['items'], 'ref')
        );
        foreach ($result['items'] as $item) {
            self::assertNull($item['semantic_score']);
            self::assertSame(OperatingMemoryRetrievalService::METHOD, $item['retrieval_method']);
        }
    }

    public function testCouncilRunsOnlyOnExplicitRequestAndSavesExactShadowReadback(): void
    {
        $questionId = $this->insertGroundedQuestion();
        $questionDigest = (string)Db::name(OperatingQuestionService::TABLE)
            ->where('id', $questionId)
            ->value('content_digest');
        $client = new class {
            public int $calls = 0;

            public function createJsonResponseEnvelope(array $messages, array $schema, string $modelKey): array
            {
                $this->calls++;
                $data = $this->calls <= 3
                    ? [
                        'assessment' => '已保存事实支持继续人工复核。',
                        'supported_points' => ['携程列表曝光为100次。'],
                        'risks' => ['单一渠道事实不能外推为全酒店结论。'],
                        'missing_information' => [],
                        'evidence_refs' => ['online_daily_data#501'],
                        'confidence' => 'medium',
                    ]
                    : [
                        'summary' => '三角色均同意只基于当前携程事实继续人工复核。',
                        'agreements' => ['现有证据只覆盖携程。'],
                        'conflicts' => [],
                        'missing_information' => [],
                        'recommended_next_step' => '人工核对同日详情曝光。',
                        'evidence_refs' => ['online_daily_data#501'],
                    ];
                return [
                    'data' => $data,
                    'meta' => [
                        'provider' => 'ollama',
                        'model_key' => 'local_second_brain',
                        'model' => 'qwen3:4b',
                        'configured_model' => 'qwen3:4b',
                        'response_model' => 'qwen3:4b',
                        'provider_endpoint_origin' => 'http://127.0.0.1:11434',
                        'provider_endpoint_host' => '127.0.0.1',
                        'provider_endpoint_official' => false,
                        'provider_config_digest' => hash('sha256', 'local-council-test-config'),
                        'direct_call_nonce' => 'local-council-test-nonce-' . $this->calls,
                        'transport_request_id' => 'local-council-test-nonce-' . $this->calls,
                        'transport_retry_attempts' => 0,
                        'upstream_idempotency_key_sent' => false,
                        'http_status' => 200,
                        'provider_attempt_count' => 1,
                        'idempotent_replay' => false,
                        'finish_reason' => 'stop',
                        'fallback_used' => false,
                        'cache_hit' => false,
                        'degraded' => false,
                    ],
                ];
            }
        };
        $service = new OperatingQuestionCouncilService(
            $client,
            static fn(): array => [
                'text' => ['ready' => true, 'model' => 'qwen3:4b'],
                'boundaries' => ['local_only' => true],
            ]
        );

        self::assertSame(0, (int)Db::name(OperatingQuestionCouncilService::TABLE)->count());
        self::assertSame(0, $client->calls);
        $saved = $service->runShadow($questionId, 10, [20], 17, 'council-local-0001');

        self::assertTrue($saved['created']);
        self::assertSame('readback_verified', $saved['persistence_status']);
        self::assertSame('completed', $saved['status']);
        self::assertSame('none', $saved['decision_effect']);
        self::assertCount(3, $saved['members']);
        self::assertCount(4, $saved['model_meta']);
        self::assertSame(['online_daily_data#501'], $saved['evidence_refs']);
        self::assertSame(4, $client->calls);
        self::assertFalse($saved['boundaries']['action_creation_allowed']);
        self::assertFalse($saved['boundaries']['external_message']);
        self::assertFalse($saved['boundaries']['automatic_execution']);
        self::assertFalse($saved['boundaries']['ota_write']);
        self::assertFalse($saved['boundaries']['primary_answer_mutated']);
        self::assertSame(
            $questionDigest,
            (string)Db::name(OperatingQuestionService::TABLE)->where('id', $questionId)->value('content_digest')
        );

        $replayed = $service->runShadow($questionId, 10, [20], 17, 'council-local-0001');
        self::assertFalse($replayed['created']);
        self::assertSame((int)$saved['id'], (int)$replayed['id']);
        self::assertSame(4, $client->calls);
        self::assertSame(1, (int)Db::name(OperatingQuestionCouncilService::TABLE)->count());
    }


    public function testLocalMediaRejectsUnsupportedFileWithoutPersistence(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'suxios_media_invalid_');
        self::assertIsString($path);
        file_put_contents($path, 'this is not an image audio or video file');

        try {
            try {
                (new LocalMediaExtractionService())->extract(10, 20, 17, $path, 'notes.txt', 'text/plain');
                self::fail('Unsupported media must be rejected before persistence.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('仅支持图片、音频或视频文件', $exception->getMessage());
            }
        } finally {
            @unlink($path);
        }

        self::assertSame(0, (int)Db::name(LocalMediaExtractionService::TABLE)->count());
    }

    public function testLocalMediaReadRejectsPersistedContentTampering(): void
    {
        $service = new LocalMediaExtractionService();
        $record = [
            'contract_version' => LocalMediaExtractionService::CONTRACT_VERSION,
            'tenant_id' => 10,
            'hotel_id' => 20,
            'created_by' => 17,
            'media_kind' => 'audio',
            'mime_type' => 'audio/wav',
            'original_name' => 'front-desk-note.wav',
            'size_bytes' => 2048,
            'source_sha256' => hash('sha256', 'media-source-fixture'),
            'extraction_status' => 'ready',
            'extraction_method' => 'faster_whisper_local',
            'extractor_version' => 'faster-whisper/1.2.1:small:cpu-int8',
            'extracted_text' => '前台反馈携程曝光下降，需要复核。',
            'structured' => ['language' => 'zh', 'source_retained' => false],
            'confidence' => 0.88,
            'error_code' => null,
            'source_retention' => 'discarded_after_extraction',
        ];
        $digest = $this->invokePrivate($service, 'digest', [$record]);
        $id = (int)Db::name(LocalMediaExtractionService::TABLE)->insertGetId([
            'tenant_id' => $record['tenant_id'],
            'hotel_id' => $record['hotel_id'],
            'created_by' => $record['created_by'],
            'media_kind' => $record['media_kind'],
            'mime_type' => $record['mime_type'],
            'original_name' => $record['original_name'],
            'size_bytes' => $record['size_bytes'],
            'source_sha256' => $record['source_sha256'],
            'extraction_status' => $record['extraction_status'],
            'extraction_method' => $record['extraction_method'],
            'extractor_version' => $record['extractor_version'],
            'extracted_text' => $record['extracted_text'],
            'structured_json' => json_encode($record['structured'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'confidence' => $record['confidence'],
            'error_code' => null,
            'content_digest' => $digest,
            'source_retention' => $record['source_retention'],
            'created_at' => '2026-08-23 10:00:00',
            'updated_at' => '2026-08-23 10:00:00',
        ]);

        $readback = $service->read($id, 10, [20]);
        self::assertSame($digest, $readback['content_digest']);
        self::assertTrue($readback['boundaries']['local_only']);
        self::assertFalse($readback['boundaries']['source_file_retained']);

        Db::name(LocalMediaExtractionService::TABLE)
            ->where('id', $id)
            ->update(['extracted_text' => '被篡改的转写结果']);
        $this->assertRuntimeFailure(
            static fn(): array => $service->read($id, 10, [20]),
            '本地媒体提取结果保存后摘要不一致'
        );
    }

    /** @return array<string,mixed> */
    private function evaluationResult(): array
    {
        return [
            'contract_version' => 'ai_evaluation_batch_replay.v1',
            'evaluation_set' => 'ota_local_second_brain_v1',
            'model_key' => 'local_second_brain',
            'dry_run' => true,
            'allow_external_model_call' => false,
            'summary' => [
                'total' => 1,
                'ready' => 1,
                'blocked' => 0,
                'executed' => 0,
                'passed' => 0,
                'failed' => 0,
            ],
            'cases' => [[
                'case_id' => 31,
                'case_key' => 'ota-diagnosis-local-001',
                'status' => 'ready',
                'input_sha256' => hash('sha256', 'input-v1'),
                'expected_sha256' => hash('sha256', 'expected-v1'),
                'metric_sha256' => hash('sha256', 'metric-v1'),
            ]],
        ];
    }

    private function insertMemory(int $tenantId, int $hotelId, string $platform, string $title): int
    {
        $memoryKey = strtolower($platform) . ':' . $tenantId . ':' . $hotelId . ':' . bin2hex(random_bytes(4));
        return (int)Db::name(OperatingMemoryService::TABLE)->insertGetId([
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'memory_key' => $memoryKey,
            'memory_layer' => 'fact',
            'title' => $title,
            'summary' => '曝光下降时先复核同口径来源和日期，再形成渠道判断。',
            'business_date' => '2026-08-22',
            'platform' => $platform,
            'source_scope' => 'ota_channel',
            'source_module' => 'local_second_brain_test',
            'source_record_type' => 'verified_memory',
            'source_record_id' => 1,
            'evidence_refs_json' => '["test-source#1"]',
            'context_json' => '{}',
            'quality_status' => 'verified',
            'usage_level' => 'decision_support',
            'lifecycle_status' => 'active',
            'content_digest' => hash('sha256', $memoryKey),
            'previous_memory_id' => null,
            'recorded_by' => 17,
            'occurred_at' => '2026-08-22 10:00:00',
            'created_at' => '2026-08-22 10:00:00',
            'updated_at' => '2026-08-22 10:00:00',
            'deleted_at' => null,
        ]);
    }


    private function invokePrivate(object $object, string $methodName, array $arguments): mixed
    {
        $method = new ReflectionMethod($object, $methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $arguments);
    }

    private function assertRuntimeFailure(callable $callback, string $message): void
    {
        try {
            $callback();
            self::fail('Expected a persisted digest mismatch to be rejected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function createAiEvaluationSchema(): void
    {
        Db::execute(
            'CREATE TABLE ai_evaluation_runs ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, client_run_key TEXT NOT NULL UNIQUE, '
            . 'evaluation_set TEXT NOT NULL, model_key TEXT NOT NULL, filters_json TEXT NULL, '
            . 'dry_run INTEGER NOT NULL DEFAULT 1, status TEXT NOT NULL, summary_json TEXT NULL, '
            . 'cases_json TEXT NULL, result_json TEXT NOT NULL, result_digest TEXT NOT NULL, '
            . 'created_by INTEGER NOT NULL DEFAULT 0, readback_verified INTEGER NOT NULL DEFAULT 0, '
            . 'created_at TEXT NOT NULL, completed_at TEXT NULL)'
        );
    }

    private function createOperatingMemorySchema(): void
    {
        Db::execute(
            'CREATE TABLE hotel_operating_memories ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, '
            . 'memory_key TEXT NOT NULL, memory_layer TEXT NOT NULL, title TEXT NOT NULL, summary TEXT NOT NULL, '
            . 'business_date TEXT NULL, platform TEXT NOT NULL, source_scope TEXT NOT NULL, source_module TEXT NOT NULL, '
            . 'source_record_type TEXT NOT NULL, source_record_id INTEGER NOT NULL, evidence_refs_json TEXT NULL, '
            . 'context_json TEXT NULL, quality_status TEXT NOT NULL, usage_level TEXT NOT NULL, lifecycle_status TEXT NOT NULL, '
            . 'content_digest TEXT NOT NULL, previous_memory_id INTEGER NULL, recorded_by INTEGER NOT NULL, occurred_at TEXT NULL, '
            . 'created_at TEXT NOT NULL, updated_at TEXT NOT NULL, deleted_at TEXT NULL, '
            . 'UNIQUE(tenant_id, hotel_id, memory_key))'
        );
    }

    private function createLocalMediaSchema(): void
    {
        Db::execute(
            'CREATE TABLE local_media_extractions ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, '
            . 'created_by INTEGER NOT NULL, media_kind TEXT NOT NULL, mime_type TEXT NOT NULL, original_name TEXT NOT NULL, '
            . 'size_bytes INTEGER NOT NULL, source_sha256 TEXT NOT NULL, extraction_status TEXT NOT NULL, '
            . 'extraction_method TEXT NOT NULL, extractor_version TEXT NOT NULL, extracted_text TEXT NULL, '
            . 'structured_json TEXT NULL, confidence REAL NULL, error_code TEXT NULL, content_digest TEXT NOT NULL, '
            . 'source_retention TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, '
            . 'UNIQUE(tenant_id, hotel_id, created_by, source_sha256))'
        );
    }

    private function createOperatingQuestionCouncilSchema(): void
    {
        Db::execute(
            'CREATE TABLE hotel_operating_questions ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, '
            . 'request_key TEXT NOT NULL, question_text TEXT NOT NULL, platform TEXT NOT NULL, date_start TEXT NOT NULL, '
            . 'date_end TEXT NOT NULL, answer_status TEXT NOT NULL, answer_summary TEXT NOT NULL, answer_json TEXT NOT NULL, '
            . 'fact_refs_json TEXT NULL, memory_refs_json TEXT NULL, knowledge_refs_json TEXT NULL, execution_refs_json TEXT NULL, '
            . 'data_gaps_json TEXT NULL, content_digest TEXT NOT NULL, created_by INTEGER NOT NULL, created_at TEXT NOT NULL, '
            . 'updated_at TEXT NOT NULL, deleted_at TEXT NULL)'
        );
        Db::execute(
            'CREATE TABLE hotel_operating_question_council_runs ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, '
            . 'question_id INTEGER NOT NULL, request_key TEXT NOT NULL, mode TEXT NOT NULL, status TEXT NOT NULL, '
            . 'members_json TEXT NULL, synthesis_json TEXT NULL, evidence_refs_json TEXT NULL, model_meta_json TEXT NULL, '
            . 'decision_effect TEXT NOT NULL, content_digest TEXT NOT NULL, created_by INTEGER NOT NULL, '
            . 'created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE(tenant_id, hotel_id, question_id, request_key))'
        );
    }

    private function insertGroundedQuestion(): int
    {
        $answer = [
            'status' => 'answered_by_grounded_ai',
            'summary' => '携程列表曝光为100次。',
            'scope' => [
                'tenant_id' => 10,
                'hotel_id' => 20,
                'platform' => 'ctrip',
                'date_start' => '2026-08-10',
                'date_end' => '2026-08-10',
            ],
            'fact_samples' => [[
                'ref' => 'online_daily_data#501',
                'platform' => 'ctrip',
                'data_date' => '2026-08-10',
                'metric_values' => ['list_exposure' => 100],
                'metric_units' => ['list_exposure' => 'exposure_count'],
            ]],
            'data_gaps' => [],
        ];
        $factRefs = ['online_daily_data#501'];
        $digest = $this->invokePrivate(new OperatingQuestionService(), 'digest', [[
            'question' => '携程列表曝光是多少？',
            'answer' => $answer,
            'fact_refs' => $factRefs,
            'memory_refs' => [],
            'knowledge_refs' => [],
            'execution_refs' => [],
        ]]);
        return (int)Db::name(OperatingQuestionService::TABLE)->insertGetId([
            'tenant_id' => 10,
            'hotel_id' => 20,
            'request_key' => 'question-local-council-0001',
            'question_text' => '携程列表曝光是多少？',
            'platform' => 'ctrip',
            'date_start' => '2026-08-10',
            'date_end' => '2026-08-10',
            'answer_status' => $answer['status'],
            'answer_summary' => $answer['summary'],
            'answer_json' => json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'fact_refs_json' => json_encode($factRefs, JSON_THROW_ON_ERROR),
            'memory_refs_json' => '[]',
            'knowledge_refs_json' => '[]',
            'execution_refs_json' => '[]',
            'data_gaps_json' => '[]',
            'content_digest' => $digest,
            'created_by' => 17,
            'created_at' => '2026-08-10 10:00:00',
            'updated_at' => '2026-08-10 10:00:00',
            'deleted_at' => null,
        ]);
    }


    public function testWecomConfirmedDeliveryCannotBeDowngraded(): void
    {
        $service = new WecomAibotService();
        $bindingId = $this->insertWecomBinding();
        $eventId = $this->insertWecomEvent($service, $bindingId);

        $sent = $service->recordDelivery($eventId, 'sent', 'wecom_aibot:errcode=0');
        self::assertSame('sent', $sent['delivery_status']);
        self::assertSame('wecom_aibot:errcode=0', $sent['delivery_reference']);
        self::assertSame('readback_verified', $sent['persistence_status']);
        $sentDigest = (string)$sent['content_digest'];

        try {
            $service->recordDelivery($eventId, 'failed', 'wecom_aibot:reply_failed');
            self::fail('A confirmed WeCom delivery must not be downgraded to failed.');
        } catch (RuntimeException $exception) {
            self::assertSame(409, $exception->getCode());
            self::assertStringContainsString('不能降级', $exception->getMessage());
        }

        $row = Db::name('wecom_inbound_events')->where('id', $eventId)->find();
        self::assertIsArray($row);
        self::assertSame('sent', $row['delivery_status']);
        self::assertSame('wecom_aibot:errcode=0', $row['delivery_reference']);
        self::assertSame($sentDigest, $row['content_digest']);

        $idempotent = $service->recordDelivery($eventId, 'sent', 'wecom_aibot:errcode=0');
        self::assertSame($sentDigest, $idempotent['content_digest']);
    }

    public function testWecomDisableBindingReleasesConversationAndRetainsEvents(): void
    {
        $service = new WecomAibotService();
        $bindingId = $this->insertWecomBinding();
        $this->insertWecomEvent($service, $bindingId);
        $originalHash = (string)Db::name('wecom_inbound_bindings')
            ->where('id', $bindingId)
            ->value('conversation_id_hash');

        $disabled = $service->disableBinding($bindingId, 10, [20]);
        self::assertSame('disabled', $disabled['status']);
        self::assertFalse($disabled['reply_enabled']);
        self::assertTrue($disabled['conversation_reference_released']);
        self::assertTrue($disabled['historical_events_retained']);
        self::assertSame('readback_verified', $disabled['persistence_status']);

        $row = Db::name('wecom_inbound_bindings')->where('id', $bindingId)->find();
        self::assertIsArray($row);
        self::assertSame('disabled', $row['status']);
        self::assertSame(0, (int)$row['reply_enabled']);
        self::assertNotSame($originalHash, $row['conversation_id_hash']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', (string)$row['conversation_id_hash']);
        self::assertSame(1, (int)Db::name('wecom_inbound_events')->where('binding_id', $bindingId)->count());

        $replayed = $service->disableBinding($bindingId, 10, [20]);
        self::assertSame('disabled', $replayed['status']);
        self::assertSame($row['conversation_id_hash'], Db::name('wecom_inbound_bindings')
            ->where('id', $bindingId)
            ->value('conversation_id_hash'));
    }
}
