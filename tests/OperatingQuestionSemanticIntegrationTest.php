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
        foreach (['hotel_operating_questions', 'online_daily_data', 'hotels'] as $table) {
            Db::execute('DROP TABLE IF EXISTS ' . $table);
        }
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT, status INTEGER NOT NULL)');
        Db::execute("INSERT INTO hotels (id,tenant_id,name,status) VALUES (80,10,'Hotel 80',1)");
        Db::execute(
            'CREATE TABLE online_daily_data ('
            . 'id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, system_hotel_id INTEGER NOT NULL, '
            . 'data_date TEXT NOT NULL, platform TEXT, source TEXT, data_type TEXT, dimension TEXT, '
            . 'validation_status TEXT, history_status TEXT, readback_verified INTEGER, readback_verified_at TEXT, '
            . 'ingestion_method TEXT, source_trace_id TEXT, snapshot_time TEXT, '
            . 'list_exposure INTEGER, detail_exposure INTEGER, raw_data TEXT)'
        );
        Db::execute(
            'CREATE TABLE hotel_operating_questions ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, request_key TEXT, question_text TEXT, '
            . 'platform TEXT, date_start TEXT, date_end TEXT, answer_status TEXT, answer_summary TEXT, answer_json TEXT, '
            . 'fact_refs_json TEXT, memory_refs_json TEXT, knowledge_refs_json TEXT, execution_refs_json TEXT, data_gaps_json TEXT, '
            . 'content_digest TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT, '
            . 'UNIQUE(tenant_id,hotel_id,request_key))'
        );
    }

    public function testProductionFactAdapterMetricDefinitionsFeedExposureToVisitReadback(): void
    {
        $rawData = json_encode([
            'field_facts' => [[
                'data_type' => 'traffic',
                'metric_key' => 'list_exposure',
                'source_key' => 'exposureUV',
                'source_path' => 'data.myHotel.exposureUV',
                'storage_field' => 'online_daily_data.list_exposure',
                'status' => 'captured',
                'stored_value_present' => true,
            ], [
                'data_type' => 'traffic',
                'metric_key' => 'detail_exposure',
                'source_key' => 'intentionUV',
                'source_path' => 'data.myHotel.intentionUV',
                'storage_field' => 'online_daily_data.detail_exposure',
                'status' => 'captured',
                'stored_value_present' => true,
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        Db::name('online_daily_data')->insert([
            'id' => 102476,
            'tenant_id' => 10,
            'system_hotel_id' => 80,
            'data_date' => '2026-08-23',
            'platform' => 'meituan',
            'source' => 'meituan',
            'data_type' => 'traffic',
            'dimension' => 'hotel',
            'validation_status' => 'verified',
            'history_status' => 'success',
            'readback_verified' => 1,
            'readback_verified_at' => '2026-08-24 23:18:36',
            'ingestion_method' => 'browser_capture',
            'source_trace_id' => 'trace-meituan-20260823',
            'snapshot_time' => '2026-08-24 23:18:30',
            'list_exposure' => 1422,
            'detail_exposure' => 206,
            'raw_data' => $rawData,
        ]);

        $facts = (new OperatingQuestionService())->readCurrentVerifiedFactsForRefs(
            10,
            80,
            'meituan',
            '2026-08-23',
            '2026-08-23',
            ['online_daily_data#102476']
        );

        self::assertCount(1, $facts);
        self::assertArrayHasKey('metric_definitions', $facts[0]);
        self::assertArrayNotHasKey('metric_provenance', $facts[0]);
        self::assertTrue($facts[0]['metric_definitions']['list_exposure']['claimable']);
        self::assertTrue($facts[0]['metric_definitions']['detail_exposure']['claimable']);

        $result = (new OperatingQuestionPreciseQueryService())->finalize([
            'question' => '美团曝光到访率是多少',
            'scope' => [
                'platform' => 'meituan',
                'date_start' => '2026-08-23',
                'date_end' => '2026-08-23',
                'source_scope' => 'ota_channel',
            ],
            'facts' => $facts,
        ]);

        self::assertSame('answered_by_precise_query', $result['status']);
        self::assertSame(14.49, $result['precise_result']['metric_readback']['values'][0]['value']);
        self::assertSame(
            ['data.myHotel.exposureUV', 'data.myHotel.intentionUV'],
            $result['precise_result']['metric_readback']['values'][0]['source_paths']
        );
        self::assertSame(['online_daily_data#102476'], $result['used_evidence_refs']);
    }

    public function testPreciseMetricIsSavedReadBackIdempotentlyAndSkipsAi(): void
    {
        $aiCalls = 0;
        $precise = new OperatingQuestionPreciseQueryService();
        $facts = $this->hotel80MeituanTrafficFacts();
        $service = new OperatingQuestionService(
            static fn(): array => [
                'facts' => $facts,
                'fact_count' => 1,
                'fact_platform_counts' => ['meituan' => 1],
                'fact_platform_dates' => ['meituan' => ['2026-08-23']],
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

        $first = $service->create(10, 80, '美团曝光人数是多少', 'meituan', '2026-08-23', '2026-08-23', 7);
        $second = $service->create(10, 80, '美团曝光人数是多少', 'meituan', '2026-08-23', '2026-08-23', 7);
        $exactReadback = $service->read((int)$first['question']['id'], 10, [80]);

        self::assertTrue($first['created']);
        self::assertFalse($second['created']);
        self::assertSame($first['question']['id'], $second['question']['id']);
        self::assertSame('readback_verified', $first['persistence_status']);
        self::assertSame('answered_by_precise_query', $first['question']['answer_status']);
        self::assertSame('deterministic_precise_query', $first['question']['answer']['mode']);
        self::assertSame('not_called_deterministic', $first['question']['answer']['ai_runtime']['status']);
        self::assertSame(0, $aiCalls);
        self::assertSame(1422, $first['question']['answer']['precise_result']['metric_readback']['values'][0]['value']);
        self::assertSame('people', $first['question']['answer']['precise_result']['metric_readback']['values'][0]['unit']);
        self::assertSame(
            ['data.myHotel.exposureUV'],
            $first['question']['answer']['precise_result']['metric_readback']['values'][0]['source_paths']
        );
        self::assertSame('meituan_exposure_users', $first['question']['answer']['query_router']['metric_key']);
        self::assertSame('meituan-ebooking', $first['question']['answer']['query_router']['target_page']);
        self::assertSame(['online_daily_data#102476'], $first['question']['answer']['used_evidence_refs']);
        self::assertSame([], $first['question']['answer']['action_drafts']);
        self::assertFalse($first['question']['answer']['boundaries']['external_llm_called']);
        self::assertFalse($first['question']['answer']['boundaries']['llm_attempted']);
        self::assertFalse($first['question']['answer']['boundaries']['ota_write']);
        self::assertFalse($first['question']['answer']['boundaries']['external_message']);
        self::assertFalse($first['question']['answer']['boundaries']['automatic_execution']);
        self::assertSame($first['question']['content_digest'], $exactReadback['content_digest']);
        self::assertSame($first['question']['answer_status'], $exactReadback['answer_status']);
        self::assertSame($first['question']['answer'], $exactReadback['answer']);
        self::assertSame($first['question']['fact_refs'], $exactReadback['fact_refs']);
        self::assertSame('passed', $first['question']['analysis_quality_receipt']['quality_status']);
        self::assertSame('supported', $first['question']['analysis_quality_receipt']['claim_status']);
        self::assertSame('ready', $first['question']['analysis_quality_receipt']['status']);
        self::assertSame(
            $first['question']['analysis_quality_receipt']['receipt_digest'],
            $exactReadback['analysis_quality_receipt']['receipt_digest']
        );
        self::assertSame(1, (int)Db::name('hotel_operating_questions')->count());
    }

    public function testThreeMetricPartialAnswerIsSavedAndExactlyReadBackWithoutAi(): void
    {
        $aiCalls = 0;
        $precise = new OperatingQuestionPreciseQueryService();
        $facts = $this->hotel80MeituanTrafficFacts();
        $service = new OperatingQuestionService(
            static fn(): array => [
                'facts' => $facts,
                'fact_count' => 1,
                'fact_platform_counts' => ['meituan' => 1],
                'fact_platform_dates' => ['meituan' => ['2026-08-23']],
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

        $created = $service->create(
            10,
            80,
            '美团曝光人数、商详访客数和意向支付转化率分别是多少',
            'meituan',
            '2026-08-23',
            '2026-08-23',
            7
        );
        $exactReadback = $service->read((int)$created['question']['id'], 10, [80]);
        $metricSet = $created['question']['answer']['precise_result']['metric_set'];
        $byMetric = [];
        foreach ($metricSet['items'] as $item) {
            $byMetric[(string)($item['metric']['key'] ?? '')] = $item;
        }

        self::assertTrue($created['created']);
        self::assertSame('readback_verified', $created['persistence_status']);
        self::assertSame('answered_by_precise_query_partial', $created['question']['answer_status']);
        self::assertSame('partial', $created['question']['answer']['precise_result']['status']);
        self::assertSame(3, $metricSet['result_count']);
        self::assertSame(2, $metricSet['ready_count']);
        self::assertSame(1, $metricSet['blocked_count']);
        self::assertSame(1422, $byMetric['meituan_exposure_users']['value']);
        self::assertSame('people', $byMetric['meituan_exposure_users']['unit']);
        self::assertSame(206, $byMetric['meituan_detail_visitors']['value']);
        self::assertSame('people', $byMetric['meituan_detail_visitors']['unit']);
        self::assertNull($byMetric['intent_payment_conversion_rate']['value']);
        self::assertSame('blocked_by_source_contract', $byMetric['intent_payment_conversion_rate']['status']);
        self::assertSame(['online_daily_data#102476'], $created['question']['answer']['used_evidence_refs']);
        self::assertSame(0, $aiCalls);
        self::assertSame($created['question']['content_digest'], $exactReadback['content_digest']);
        self::assertSame($created['question']['answer_status'], $exactReadback['answer_status']);
        self::assertSame($created['question']['answer'], $exactReadback['answer']);
        self::assertSame($created['question']['fact_refs'], $exactReadback['fact_refs']);
        self::assertSame('passed', $created['question']['analysis_quality_receipt']['quality_status']);
        self::assertSame('limited', $created['question']['analysis_quality_receipt']['claim_status']);
        self::assertSame('partial', $created['question']['analysis_quality_receipt']['status']);
        self::assertSame(
            $created['question']['analysis_quality_receipt']['receipt_digest'],
            $exactReadback['analysis_quality_receipt']['receipt_digest']
        );
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
                    'quality_status' => 'verified',
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
        self::assertSame('blocked_by_missing_metric', $result['question']['answer_status']);
        self::assertSame('not_computable', $result['question']['answer']['precise_result']['metric_readback']['status']);
        self::assertSame([], $result['question']['answer']['precise_result']['metric_readback']['values']);
        self::assertStringContainsString('ADR未返回', $result['question']['answer_summary']);
        self::assertStringContainsString(
            'adr_aligned_room_revenue_or_room_nights_missing',
            $result['question']['answer_summary']
        );
        self::assertSame('passed', $result['question']['analysis_quality_receipt']['quality_status']);
        self::assertSame('blocked', $result['question']['analysis_quality_receipt']['claim_status']);
    }

    /** @return list<array<string,mixed>> */
    private function hotel80MeituanTrafficFacts(): array
    {
        return [[
            'ref' => 'online_daily_data#102476',
            'data_date' => '2026-08-23',
            'platform' => 'meituan',
            'data_type' => 'traffic',
            'history_status' => 'success',
            'quality_status' => 'verified',
            'readback_status' => 'readback_verified',
            'readback_verified_at' => '2026-08-24 23:18:36',
            'source_trace_id' => 'trace-meituan-20260823',
            'metric_values' => [
                'list_exposure' => 1422,
                'detail_exposure' => 206,
                'flow_rate' => 14.49,
            ],
            'metric_units' => [
                'list_exposure' => 'exposure_count',
                'detail_exposure' => 'exposure_count',
                'flow_rate' => 'source_defined_rate',
            ],
            'metric_provenance' => [
                'list_exposure' => [[
                    'metric_key' => 'list_exposure',
                    'source_key' => 'exposureUV',
                    'source_path' => 'data.myHotel.exposureUV',
                    'storage_field' => 'list_exposure',
                    'status' => 'captured',
                    'stored_value_present' => true,
                ]],
                'detail_exposure' => [[
                    'metric_key' => 'detail_exposure',
                    'source_key' => 'intentionUV',
                    'source_path' => 'data.myHotel.intentionUV',
                    'storage_field' => 'detail_exposure',
                    'status' => 'captured',
                    'stored_value_present' => true,
                ]],
                'flow_rate' => [[
                    'metric_key' => 'flow_rate',
                    'source_key' => 'exposure_to_browse_rate',
                    'source_path' => 'data.myHotel.exposure_to_browse_rate',
                    'storage_field' => 'flow_rate',
                    'status' => 'captured',
                    'stored_value_present' => true,
                ]],
            ],
        ]];
    }
}
