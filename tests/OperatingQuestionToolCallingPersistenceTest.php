<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperatingQuestionService;
use app\service\OperatingQuestionToolCallingService;
use app\service\OperatingQuestionUnifiedEvidenceService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OperatingQuestionToolCallingPersistenceTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App(dirname(__DIR__));
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'operating_question_tool_receipt_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';
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
        foreach (['hotel_operating_questions', 'hotels'] as $table) {
            Db::execute('DROP TABLE IF EXISTS ' . $table);
        }
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT, status INTEGER NOT NULL)');
        Db::execute("INSERT INTO hotels (id,tenant_id,name,status) VALUES (80,10,'Hotel 80',1)");
        Db::execute(
            'CREATE TABLE hotel_operating_questions ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, request_key TEXT, question_text TEXT, '
            . 'platform TEXT, date_start TEXT, date_end TEXT, answer_status TEXT, answer_summary TEXT, answer_json TEXT, '
            . 'fact_refs_json TEXT, memory_refs_json TEXT, knowledge_refs_json TEXT, execution_refs_json TEXT, data_gaps_json TEXT, '
            . 'content_digest TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT, '
            . 'UNIQUE(tenant_id,hotel_id,request_key))'
        );
    }

    public function testToolReceiptsAndUnifiedMediaEvidenceAreSavedAndExactlyReadBack(): void
    {
        $toolService = $this->toolService();
        $toolRuns = 0;
        $service = new OperatingQuestionService(
            static fn(): array => [
                'facts' => [[
                    'ref' => 'online_daily_data#102476',
                    'data_date' => '2026-08-31',
                    'platform' => 'ctrip',
                    'data_type' => 'traffic',
                    'history_status' => 'success',
                    'validation_status' => 'verified',
                    'readback_status' => 'readback_verified',
                    'readback_verified' => true,
                    'metric_values' => ['list_exposure' => 1422],
                    'metric_units' => ['list_exposure' => 'count'],
                ]],
                'fact_count' => 1,
                'fact_platform_counts' => ['ctrip' => 1],
                'fact_platform_dates' => ['ctrip' => ['2026-08-31']],
                'memories' => [],
                'diagnoses' => [],
                'knowledge' => [],
                'executions' => [],
            ],
            null,
            null,
            null,
            static function (array $payload) use ($toolService, &$toolRuns): array {
                $toolRuns++;
                return $toolService->run(
                    $payload['scope'],
                    $payload['question'],
                    $payload['model_key'],
                    $payload['media_evidence_ids'],
                    $payload['model_selection_allowed']
                );
            }
        );

        $first = $service->create(
            10,
            80,
            '结合我选择的截图，携程曝光应该如何复核？',
            'ctrip',
            '2026-08-31',
            '2026-08-31',
            7,
            'ollama_qwen3_8b',
            '',
            [31]
        );
        $second = $service->create(
            10,
            80,
            '结合我选择的截图，携程曝光应该如何复核？',
            'ctrip',
            '2026-08-31',
            '2026-08-31',
            7,
            'ollama_qwen3_8b',
            '',
            [31]
        );
        $readback = $service->read((int)$first['question']['id'], 10, [80]);
        $answer = $first['question']['answer'];

        self::assertTrue($first['created']);
        self::assertFalse($second['created']);
        self::assertSame(1, $toolRuns);
        self::assertSame('readback_verified', $first['persistence_status']);
        self::assertSame($first['question']['id'], $second['question']['id']);
        self::assertSame($first['question']['content_digest'], $readback['content_digest']);
        self::assertSame($answer, $readback['answer']);
        self::assertSame(3, count($answer['tool_calling']['tool_call_receipts']));
        self::assertSame(1, $answer['evidence_counts']['local_media']);
        self::assertSame(['local_media_extractions#31'], $answer['media_evidence_refs']);
        self::assertSame(
            ['knowledge_chunks#11', 'hotel_operating_memories#21', 'local_media_extractions#31'],
            $answer['evidence_plane']['evidence_refs']
        );
        self::assertTrue($answer['evidence_plane']['boundaries']['read_only']);
        self::assertFalse($answer['evidence_plane']['boundaries']['external_write_authorized']);
        self::assertFalse($answer['boundaries']['ota_write']);
        self::assertFalse($answer['boundaries']['automatic_execution']);
    }

    public function testTamperingWithPersistedReceiptBreaksReadbackDigest(): void
    {
        $toolService = $this->toolService();
        $service = new OperatingQuestionService(
            static fn(): array => [
                'facts' => [[
                    'ref' => 'online_daily_data#102476',
                    'data_date' => '2026-08-31',
                    'platform' => 'ctrip',
                    'data_type' => 'traffic',
                    'history_status' => 'success',
                    'validation_status' => 'verified',
                    'readback_status' => 'readback_verified',
                    'readback_verified' => true,
                    'metric_values' => ['list_exposure' => 1422],
                    'metric_units' => ['list_exposure' => 'count'],
                ]],
                'fact_count' => 1,
                'fact_platform_counts' => ['ctrip' => 1],
                'fact_platform_dates' => ['ctrip' => ['2026-08-31']],
                'memories' => [],
                'diagnoses' => [],
                'knowledge' => [],
                'executions' => [],
            ],
            null,
            null,
            null,
            static fn(array $payload): array => $toolService->run(
                $payload['scope'],
                $payload['question'],
                $payload['model_key'],
                $payload['media_evidence_ids'],
                $payload['model_selection_allowed']
            )
        );
        $created = $service->create(
            10, 80, '结合截图复核曝光', 'ctrip', '2026-08-31', '2026-08-31', 7,
            'ollama_qwen3_8b', '', [31]
        );
        $answer = $created['question']['answer'];
        $answer['tool_calling']['tool_call_receipts'][0]['status'] = 'tampered';
        Db::name(OperatingQuestionService::TABLE)->where('id', $created['question']['id'])->update([
            'answer_json' => json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('operating_question_readback_digest_drift');
        $service->read((int)$created['question']['id'], 10, [80]);
    }

    private function toolService(): OperatingQuestionToolCallingService
    {
        $evidence = new OperatingQuestionUnifiedEvidenceService(
            static fn(array $scope, string $question): array => [
                'status' => 'matched',
                'method' => 'test',
                'items' => [[
                    'ref' => 'knowledge_chunks#11',
                    'unit_ref' => 'knowledge_units#1',
                    'name' => '曝光复核SOP',
                    'scope' => 'generic_methodology',
                    'platforms' => ['ctrip'],
                    'gate_status' => 'formal',
                    'usage_policy' => 'decision_support',
                    'excerpt' => '先核验来源与日期。',
                    'source_refs' => ['knowledge_units#1'],
                ]],
            ],
            static fn(array $scope, string $question): array => [
                'status' => 'matched',
                'method' => 'test',
                'items' => [[
                    'ref' => 'hotel_operating_memories#21',
                    'title' => '历史曝光复核',
                    'summary' => '同口径复核过曝光。',
                    'quality_status' => 'verified',
                    'usage_level' => 'decision_support',
                    'business_date' => '2026-08-30',
                    'platform' => 'ctrip',
                ]],
            ],
            static fn(int $id, array $scope): array => [
                'id' => $id,
                'tenant_id' => $scope['tenant_id'],
                'hotel_id' => $scope['hotel_id'],
                'created_by' => $scope['user_id'],
                'extraction_status' => 'ready',
                'persistence_status' => 'readback_verified',
                'source_sha256' => str_repeat('a', 64),
                'original_name' => '携程截图.png',
                'extracted_text' => '截图中包含曝光字段。',
                'extraction_method' => 'ocr',
                'media_kind' => 'image',
                'mime_type' => 'image/png',
                'source_retention' => 'digest_only',
                'extractor_version' => 'test.v1',
                'confidence' => 0.9,
                'content_digest' => str_repeat('b', 64),
            ]
        );
        return new OperatingQuestionToolCallingService(
            static fn(array $payload): array => [
                'tool_calls' => [
                    ['name' => 'retrieve_knowledge', 'reason' => '需要SOP'],
                    ['name' => 'retrieve_operating_memory', 'reason' => '需要历史'],
                    ['name' => 'retrieve_media_evidence', 'reason' => '用户选择媒体'],
                ],
                'meta' => [
                    'provider' => 'ollama',
                    'model_key' => 'ollama_qwen3_8b',
                    'model' => 'qwen3:8b',
                    'finish_reason' => 'stop',
                    'model_attempted' => true,
                    'llm_client_invoked' => true,
                    'external_llm_called' => false,
                    'external_llm_call_status' => 'local_model_confirmed',
                ],
            ],
            $evidence
        );
    }
}
