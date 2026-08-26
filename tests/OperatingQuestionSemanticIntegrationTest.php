<?php
declare(strict_types=1);

namespace Tests;

use app\service\OperatingQuestionPreciseQueryService;
use app\service\OperatingQuestionService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OperatingQuestionSemanticIntegrationTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App(dirname(__DIR__));
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'operating_question_semantic_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';
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

    public function testPreciseMetricIsSavedReadBackIdempotentlyAndSkipsAi(): void
    {
        $aiCalls = 0;
        $precise = new OperatingQuestionPreciseQueryService();
        $facts = [[
            'ref' => 'online_daily_data#102476',
            'data_date' => '2026-08-25',
            'platform' => 'meituan',
            'data_type' => 'traffic',
            'history_status' => 'success',
            'readback_status' => 'readback_verified',
            'readback_verified_at' => '2026-08-25 23:55:00',
            'source_trace_id' => 'trace-meituan-20260825',
            'metric_values' => ['list_exposure' => 1422],
            'metric_units' => ['list_exposure' => 'exposure_count'],
            'metric_definitions' => ['list_exposure' => [
                'claimable' => true,
                'definition_id' => 'ota_list_exposure.v1',
                'source_metric_key' => 'mt_exposure',
                'source_data_type' => 'traffic',
                'source_key' => 'mt_exposure',
                'storage_field' => 'online_daily_data.list_exposure',
                'field_fact_digest' => str_repeat('a', 64),
                'source_path_digest' => str_repeat('b', 64),
                'unit_status' => 'verified',
                'unit' => 'exposure_count',
            ]],
        ]];
        $service = new OperatingQuestionService(
            static fn(): array => [
                'facts' => $facts,
                'fact_count' => 1,
                'fact_platform_counts' => ['meituan' => 1],
                'fact_platform_dates' => ['meituan' => ['2026-08-25']],
                'memories' => [],
                'diagnoses' => [],
                'knowledge' => [],
                'executions' => [],
            ],
            static function (array $payload) use (&$aiCalls): array {
                $aiCalls++;
                return ['ok' => true, 'summary' => '不应被调用'];
            },
            static fn(array $payload): array => $precise->finalize($payload)
        );

        $first = $service->create(10, 80, '美团曝光量是多少', 'meituan', '2026-08-25', '2026-08-25', 7);
        $second = $service->create(10, 80, '美团曝光量是多少', 'meituan', '2026-08-25', '2026-08-25', 7);

        self::assertTrue($first['created']);
        self::assertFalse($second['created']);
        self::assertSame($first['question']['id'], $second['question']['id']);
        self::assertSame('readback_verified', $first['persistence_status']);
        self::assertSame('answered_by_precise_query', $first['question']['answer_status']);
        self::assertSame('deterministic_precise_query', $first['question']['answer']['mode']);
        self::assertSame('not_called_deterministic', $first['question']['answer']['ai_runtime']['status']);
        self::assertSame(0, $aiCalls);
        self::assertSame(1422, $first['question']['answer']['precise_result']['metric_readback']['values'][0]['value']);
        self::assertSame('ota_exposure_volume', $first['question']['answer']['query_router']['metric_key']);
        self::assertSame('online-data', $first['question']['answer']['query_router']['target_page']);
        self::assertSame(['online_daily_data#102476'], $first['question']['answer']['used_evidence_refs']);
        self::assertSame([], $first['question']['answer']['action_drafts']);
        self::assertFalse($first['question']['answer']['boundaries']['external_llm_called']);
        self::assertFalse($first['question']['answer']['boundaries']['llm_attempted']);
        self::assertFalse($first['question']['answer']['boundaries']['ota_write']);
        self::assertFalse($first['question']['answer']['boundaries']['external_message']);
        self::assertFalse($first['question']['answer']['boundaries']['automatic_execution']);
        self::assertSame(
            $first['question']['content_digest'],
            $service->read((int)$first['question']['id'], 10, [80])['content_digest']
        );
        self::assertSame(1, (int)Db::name('hotel_operating_questions')->count());
    }

    public function testAdrDoesNotUseGenericAmountWhenRoomRevenueIsMissing(): void
    {
        $precise = new OperatingQuestionPreciseQueryService();
        $service = new OperatingQuestionService(
            static fn(): array => [
                'facts' => [[
                    'ref' => 'online_daily_data#500',
                    'data_date' => '2026-08-25',
                    'platform' => 'ctrip',
                    'data_type' => 'business',
                    'history_status' => 'success',
                    'readback_status' => 'readback_verified',
                    'metric_values' => ['amount' => 1200.0, 'quantity' => 8],
                ]],
                'fact_count' => 1,
                'fact_platform_counts' => ['ctrip' => 1],
                'fact_platform_dates' => ['ctrip' => ['2026-08-25']],
                'memories' => [],
                'diagnoses' => [],
                'knowledge' => [],
                'executions' => [],
            ],
            null,
            static fn(array $payload): array => $precise->finalize($payload)
        );

        $result = $service->create(10, 80, '平均房价是多少', 'ctrip', '2026-08-25', '2026-08-25', 7);
        self::assertSame('blocked_by_missing_inputs', $result['question']['answer_status']);
        self::assertSame('not_computable', $result['question']['answer']['precise_result']['metric_readback']['status']);
        self::assertSame([], $result['question']['answer']['precise_result']['metric_readback']['values']);
        self::assertStringContainsString('不可计算', $result['question']['answer_summary']);
    }
}
