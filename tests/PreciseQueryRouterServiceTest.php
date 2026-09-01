<?php
declare(strict_types=1);

namespace Tests;

use app\service\PreciseQueryLexicon;
use app\service\PreciseQueryRouterService;
use app\service\SystemUsageAssistantService;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class PreciseQueryRouterServiceTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App(dirname(__DIR__));
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'precise_query_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';
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
            'hotel_operating_questions',
            'online_daily_data',
            'hotels',
        ] as $table) {
            Db::execute('DROP TABLE IF EXISTS ' . $table);
        }
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT, status INTEGER NOT NULL)');
        Db::execute("INSERT INTO hotels (id,tenant_id,name,status) VALUES (80,10,'Hotel 80',1),(81,10,'Hotel 81',1),(90,11,'Other tenant',1)");
        Db::execute(
            'CREATE TABLE hotel_operating_questions ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, request_key TEXT, question_text TEXT, '
            . 'platform TEXT, date_start TEXT, date_end TEXT, answer_status TEXT, answer_summary TEXT, answer_json TEXT, '
            . 'fact_refs_json TEXT, memory_refs_json TEXT, knowledge_refs_json TEXT, execution_refs_json TEXT, data_gaps_json TEXT, '
            . 'content_digest TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT, '
            . 'UNIQUE(tenant_id,hotel_id,request_key))'
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

        Db::name('online_daily_data')->insertAll([
            $this->fact('meituan', '2026-08-23', [
                'list_exposure' => 1422,
                'detail_exposure' => 206,
                'flow_rate' => 3.88,
            ], '2026-08-23 10:31:00', 'meituan-h80-20260823'),
            $this->fact('meituan', '2026-08-23', [
                'book_order_num' => 12,
            ], '2026-08-23 10:32:00', 'meituan-orders-h80-20260823'),
            $this->fact('meituan', '2026-08-23', [
                'quantity' => 9,
            ], '2026-08-23 10:33:00', 'meituan-roomnights-h80-20260823'),
            $this->fact('ctrip', '2026-08-23', [
                'detail_exposure' => 160,
                'book_order_num' => 8,
            ], '2026-08-23 11:02:00', 'ctrip-h80-20260823'),
            $this->fact('meituan', '2026-08-24', [
                'list_exposure' => 1500,
                'detail_exposure' => 210,
            ], '2026-08-24 10:30:00', 'meituan-h80-20260824'),
            $this->fact('ctrip', '2026-08-22', [
                'list_exposure' => 900,
                'detail_exposure' => 100,
            ], '2026-08-22 10:30:00', 'ctrip-h80-20260822'),
        ]);
    }

    public function testRuntimeLexiconIsA112TermProjectionOfThe2990TermSource(): void
    {
        $metadata = PreciseQueryLexicon::metadata();
        self::assertSame(2990, $metadata['source_total_terms']);
        self::assertSame(112, $metadata['runtime_extracted_term_count']);
        self::assertCount(112, PreciseQueryLexicon::extractedTerms());
        self::assertSame(
            'e6fb5e15e711fc1c1e29202dfabe08c7f69daa5ca3cbe9df9ef9a528e6032e53',
            $metadata['source_sha256']
        );
        self::assertFalse($metadata['business_fact_eligible']);
    }

    /**
     * @param array<string,mixed> $scope
     */
    #[DataProvider('fixedQuestionProvider')]
    public function testFixedQuestionMatrix(
        string $question,
        array $scope,
        string $expectedRoute,
        string $expectedStatus,
        ?string $expectedMetric = null,
        int|float|null $expectedValue = null,
        ?string $expectedTopic = null
    ): void {
        $result = $this->router()->route(10, [80, 81], 7, [
            'query' => $question,
            'requested_mode' => 'auto',
            'current_page' => 'compass',
            'current_scope' => $scope,
            'visible_topic_keys' => [
                'ai-daily-report', 'typeless-dictionary', 'knowledge-search', 'codex-collaboration',
                'daily-workbench', 'data-health', 'auto-collect', 'automation-monitor', 'notifications',
                'revenue-report', 'operation-optimizer', 'operations',
            ],
        ]);

        self::assertGreaterThan(0, $result['id']);
        self::assertSame('readback_verified', $result['persistence_status']);
        self::assertSame($expectedRoute, $result['route_type']);
        self::assertSame(
            $expectedStatus,
            $result['status'],
            json_encode([
                'answer' => $result['answer'] ?? null,
                'data_gaps' => $result['data_gaps'] ?? null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        self::assertSame(
            PreciseQueryLexicon::metadata()['runtime_extracted_term_count'],
            $result['lexicon']['runtime_extracted_term_count']
        );
        self::assertFalse($result['lexicon']['business_fact_eligible']);

        if ($expectedMetric !== null) {
            self::assertSame($expectedMetric, $result['answer']['metric']['key'] ?? null);
        }
        if ($expectedValue !== null) {
            self::assertEquals($expectedValue, $result['answer']['value'] ?? null);
            self::assertNotEmpty($result['answer']['source_record'] ?? null);
            self::assertSame('verified', $result['answer']['verification_status'] ?? null);
            self::assertSame('readback_verified', $result['answer']['readback_status'] ?? null);
        }
        if ($expectedTopic !== null) {
            self::assertSame($expectedTopic, $result['answer']['topic_key'] ?? null);
        }
    }

    /** @return array<string,array{string,array<string,mixed>,string,string,?string,int|float|null,?string}> */
    public static function fixedQuestionProvider(): array
    {
        $h80 = ['hotel_id' => 80, 'hotel_name' => 'Hotel 80'];
        $meituanDay = $h80 + ['platform' => 'meituan', 'date_start' => '2026-08-23', 'date_end' => '2026-08-23'];
        $ctripDay = $h80 + ['platform' => 'ctrip', 'date_start' => '2026-08-23', 'date_end' => '2026-08-23'];
        return [
            '01 exact Meituan exposure users' => ['Hotel 80 8月23日美团曝光人数多少？', [], 'operating_query', 'answered_deterministically', 'list_exposure', 1422, null],
            '02 conversational same-day visitors' => ['当天美团来了多少访客？', $meituanDay, 'operating_query', 'answered_deterministically', 'detail_exposure', 206, null],
            '03 deterministic exposure-to-visit formula' => ['曝光到访率是多少，怎么算的？', $meituanDay, 'operating_query', 'answered_deterministically_partial_metadata', 'exposure_to_visit_rate', 14.49, null],
            '04 Ctrip missing conversion denominator' => ['携程为什么没有曝光转化率？', $ctripDay, 'operating_query', 'blocked_by_missing_metric', 'exposure_to_visit_rate', null, null],
            '05 revenue semantic gap' => ['收入为什么没有出来？', $meituanDay, 'operating_query', 'blocked_by_missing_metric', 'room_revenue', null, null],
            '06 refuse vague platform comparison' => ['昨天哪个平台表现更好？', $h80, 'operating_query', 'blocked_by_incomparable_scope', null, null, null],
            '07 AI daily report navigation' => ['AI经营日报在哪？', [], 'system_navigation', 'navigation_ready', null, null, 'ai-daily-report'],
            '08 trusted broadcast copy navigation' => ['可信播报怎么复制？', [], 'system_navigation', 'navigation_ready', null, null, 'ai-daily-report'],
            '09 personal-context term' => ['Openness 是酒店指标吗？', [], 'term_definition', 'reference_only', null, null, null],
            '10 Typeless maintenance navigation' => ['Typeless 总词库怎么更新？', [], 'system_navigation', 'navigation_ready', null, null, 'typeless-dictionary'],
            '11 visitor synonym' => ['Hotel 80 8月23日美团浏览人数是多少？', [], 'operating_query', 'answered_deterministically', 'detail_exposure', 206, null],
            '12 order synonym' => ['Hotel 80 8月23日美团订单量多少？', [], 'operating_query', 'answered_deterministically', 'book_order_num', 12, null],
            '13 Asia Shanghai day-before-yesterday' => ['Hotel 80 前天美团曝光人数多少？', [], 'operating_query', 'answered_deterministically', 'list_exposure', 1422, null],
            '14 latest strict readback date' => ['Hotel 80 最近一次美团曝光人数多少？', [], 'operating_query', 'answered_deterministically', 'list_exposure', 1500, null],
            '15 exact Ctrip exposure users gap' => ['Hotel 80 8月23日携程曝光人数多少？', [], 'operating_query', 'blocked_by_missing_metric', 'list_exposure', null, null],
            '16 exact Ctrip visitor' => ['Hotel 80 8月23日携程详情访客多少？', [], 'operating_query', 'answered_deterministically', 'detail_exposure', 160, null],
            '17 one hotel clarification' => ['8月23日美团曝光多少？', [], 'clarification', 'clarification_required', null, null, null],
            '18 one platform clarification' => ['Hotel 80 8月23日曝光多少？', [], 'clarification', 'clarification_required', null, null, null],
            '19 one date clarification' => ['Hotel 80 美团曝光多少？', [], 'clarification', 'clarification_required', null, null, null],
            '20 hotel metric definition' => ['ADR 是什么？', [], 'term_definition', 'reference_only', null, null, null],
            '21 refuse cross-platform partial facts' => ['Hotel 80 8月23日携程和美团曝光量哪个高？', [], 'operating_query', 'blocked_by_cross_platform_evidence', 'ota_exposure_volume', null, null],
            '22 unknown intent asks one route clarification' => ['帮我看看。', [], 'clarification', 'clarification_required', null, null, null],
            '23 intent-payment rate stays blocked without aligned inputs' => ['Hotel 80 8月23日美团意向支付转化率多少？', [], 'operating_query', 'blocked_by_missing_metric', 'intent_payment_conversion_rate', null, null],
            '24 Codex collaboration navigation' => ['我想让 Codex 帮我检查或完善宿析OS，应该怎么说？', [], 'system_navigation', 'navigation_ready', null, null, 'codex-collaboration'],
        ];
    }

    public function testTaskBluebookV1RoutesThroughTheDeterministicSystemGuideWithoutExternalLlm(): void
    {
        $router = $this->router(null, true);
        $visibleTopicKeys = [
            'daily-workbench', 'data-health', 'revenue-report', 'auto-collect',
            'automation-monitor', 'ai-daily-report', 'notifications', 'task-navigation',
        ];
        $cases = [
            [
                '按任务蓝皮书带我完成今天经营工作：进入经营机会，基于可信事实或明确缺口，选出并保存唯一优先事项。',
                'daily-workbench',
                ['daily-workbench'],
            ],
            [
                '按任务蓝皮书查清数据为什么没进来：检查数据健康、自动采集和运行监控，缺失就明确阻塞。',
                'data-health',
                ['data-health', 'auto-collect', 'automation-monitor'],
            ],
            [
                '按任务蓝皮书先检查数据健康，再生成和预览 AI 经营日报；外发仍需人工确认。',
                'data-health',
                ['data-health', 'ai-daily-report'],
            ],
        ];

        foreach ($cases as [$query, $expectedTopic, $expectedJourney]) {
            $result = $router->route(10, [80, 81], 7, [
                'query' => $query,
                'requested_mode' => 'guide',
                'current_page' => 'compass',
                'current_scope' => [],
                'visible_topic_keys' => $visibleTopicKeys,
            ]);
            self::assertSame('system_navigation', $result['route_type']);
            self::assertSame('navigation_ready', $result['status']);
            self::assertSame($expectedTopic, $result['answer']['topic_key']);
            self::assertSame(
                $expectedJourney,
                array_column($result['answer']['journey'], 'key')
            );
            self::assertFalse($result['boundaries']['external_llm_called']);
        }
    }

    public function testFormulaUsesOneSourceRowAndMarksLegacyRateConflict(): void
    {
        $result = $this->ask('Hotel 80 8月23日美团曝光到访率是多少，怎么算？');
        self::assertSame(14.49, $result['answer']['value']);
        self::assertSame('stored_rate_semantic_mismatch', $result['answer']['conflict_status']);
        self::assertSame([
            ['metric_key' => 'detail_visitors', 'storage_field' => 'detail_exposure', 'value' => 206.0, 'unit' => '人'],
            ['metric_key' => 'exposure_users', 'storage_field' => 'list_exposure', 'value' => 1422.0, 'unit' => '人'],
        ], $result['answer']['calculation_inputs']);
        self::assertStringContainsString('206 ÷ 1,422 × 100% = 14.49%', $result['answer']['formula']);
        self::assertCount(1, $result['fact_refs']);
    }

    public function testProductionRouterCopiesCanonicalClosureFactsAndBlocksConflictsAndComparison(): void
    {
        $closure = $this->canonicalClosure();
        $router = $this->router(static fn(int $hotelId, string $date): array => $closure);

        $exposure = $router->route(10, [80, 81], 7, [
            'query' => 'Hotel 80 8月23日美团曝光人数多少？',
            'current_scope' => [],
        ]);
        self::assertSame('answered_from_canonical_closure', $exposure['status']);
        self::assertSame(1422, $exposure['answer']['value']);
        self::assertSame('exposure', $exposure['answer']['canonical_field_key']);
        self::assertSame('dual_ota_field_closure#canonical-test', $exposure['answer']['closure_identity']);
        self::assertSame(['online_daily_data#102476'], $exposure['fact_refs']);

        $volume = $router->route(10, [80, 81], 7, [
            'query' => 'Hotel 80 8月23日美团曝光量多少？',
            'current_scope' => [],
        ]);
        self::assertSame('blocked_by_canonical_fact_status', $volume['status']);
        self::assertNull($volume['answer']['value']);
        self::assertStringContainsString('只证明曝光人数', $volume['answer_summary']);

        $visits = $router->route(10, [80, 81], 7, [
            'query' => '当天美团来了多少访客？',
            'current_scope' => [
                'hotel_id' => 80, 'hotel_name' => 'Hotel 80',
                'platform' => 'meituan',
                'date_start' => '2026-08-23', 'date_end' => '2026-08-23',
            ],
        ]);
        self::assertSame(206, $visits['answer']['value']);

        $conversion = $router->route(10, [80, 81], 7, [
            'query' => '曝光到访率是多少，怎么算的？',
            'current_scope' => [
                'hotel_id' => 80, 'hotel_name' => 'Hotel 80',
                'platform' => 'meituan',
                'date_start' => '2026-08-23', 'date_end' => '2026-08-23',
            ],
        ]);
        self::assertSame(14.49, $conversion['answer']['value']);
        self::assertSame('detail_exposure / list_exposure', $conversion['answer']['formula']);
        self::assertSame('derived_verified', $conversion['answer']['verification_status']);
        self::assertSame([
            ['metric_key' => 'detail_visitors', 'storage_field' => 'detail_exposure', 'unit' => 'people', 'value' => 206],
            ['metric_key' => 'exposure_users', 'storage_field' => 'list_exposure', 'unit' => 'people', 'value' => 1422],
        ], $conversion['answer']['calculation_inputs']);

        $ctripGap = $router->route(10, [80, 81], 7, [
            'query' => '携程为什么没有曝光转化率？',
            'current_scope' => [
                'hotel_id' => 80, 'hotel_name' => 'Hotel 80',
                'platform' => 'ctrip',
                'date_start' => '2026-08-23', 'date_end' => '2026-08-23',
            ],
        ]);
        self::assertSame('blocked_by_canonical_fact_status', $ctripGap['status']);
        self::assertNull($ctripGap['answer']['value']);
        self::assertStringContainsString('曝光事实缺失', $ctripGap['answer_summary']);

        $revenueGap = $router->route(10, [80, 81], 7, [
            'query' => 'Hotel 80 8月23日美团成交金额多少？',
            'current_scope' => [],
        ]);
        self::assertSame('blocked_by_canonical_fact_status', $revenueGap['status']);
        self::assertNull($revenueGap['answer']['value']);
        self::assertSame('caliber_uncertain', $revenueGap['answer']['conflict_status']);
        self::assertCount(2, $revenueGap['answer']['conflict_candidates']);
        self::assertSame(
            ['online_daily_data#101920', 'online_daily_data#101926'],
            $revenueGap['fact_refs']
        );

        $intentPaymentGap = $router->route(10, [80, 81], 7, [
            'query' => 'Hotel 80 8月23日美团意向支付转化率多少？',
            'current_scope' => [],
        ]);
        self::assertSame('blocked_by_canonical_fact_status', $intentPaymentGap['status']);
        self::assertSame('intent_payment_conversion_rate', $intentPaymentGap['answer']['metric']['key']);
        self::assertNull($intentPaymentGap['answer']['value']);
        self::assertSame('intent_payment_inputs_missing', $intentPaymentGap['data_gaps'][0]['code']);
        self::assertSame([], $intentPaymentGap['fact_refs']);

        $comparison = $router->route(10, [80, 81], 7, [
            'query' => 'Hotel 80 8月23日携程和美团曝光量哪个高？',
            'current_scope' => [],
        ]);
        self::assertSame('blocked_by_cross_platform_comparison', $comparison['status']);
        self::assertNull($comparison['answer']['value']);
        self::assertNull($comparison['answer']['comparison_winner']);
        self::assertSame([], $comparison['fact_refs']);
    }

    public function testExactReadbackKeepsAnswerScopeAndReferencesByteStable(): void
    {
        $router = $this->router();
        $saved = $router->route(10, [80, 81], 7, [
            'query' => 'Hotel 80 8月23日美团曝光人数多少？',
            'current_scope' => [],
        ]);
        $readback = $router->read((int)$saved['id'], 10, [80, 81]);
        self::assertSame($saved, $readback);
        self::assertSame($saved['answer'], $readback['answer']);
        self::assertSame($saved['parsed_scope'], $readback['parsed_scope']);
        self::assertSame($saved['fact_refs'], $readback['fact_refs']);
        self::assertSame($saved['content_digest'], $readback['content_digest']);
        self::assertSame(
            'passed',
            $saved['analysis_quality_receipt']['quality_status'],
            json_encode($saved['analysis_quality_receipt'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''
        );
        self::assertSame('supported', $saved['analysis_quality_receipt']['claim_status']);
        self::assertSame(
            $saved['analysis_quality_receipt']['receipt_digest'],
            $saved['operating_question']['analysis_quality_receipt']['receipt_digest']
        );
    }

    public function testMultiMetricQueryKeepsOrderPartialStatusAndExactReadback(): void
    {
        $router = $this->router(fn(int $hotelId, string $date): array => $this->canonicalClosure());
        $saved = $router->route(10, [80, 81], 7, [
            'query' => 'Hotel 80 8月23日美团曝光量、商详访客数、曝光到访率分别是多少？',
            'current_scope' => [],
        ]);

        self::assertSame('answered_deterministically_partial', $saved['status']);
        self::assertSame('suxios.precise_metric_set.v1', $saved['answer']['contract_version']);
        self::assertSame('operating_metric_set', $saved['answer']['kind']);
        self::assertSame(3, $saved['answer']['result_count']);
        self::assertSame(2, $saved['answer']['ready_count']);
        self::assertSame(1, $saved['answer']['blocked_count']);
        self::assertSame(
            ['ota_exposure_volume', 'detail_exposure', 'exposure_to_visit_rate'],
            array_column(array_column($saved['answer']['items'], 'metric'), 'key')
        );
        self::assertNull($saved['answer']['items'][0]['value']);
        self::assertStringContainsString('只证明曝光人数', $saved['answer']['items'][0]['blocked_reason']);
        self::assertSame(206, $saved['answer']['items'][1]['value']);
        self::assertSame('people', $saved['answer']['items'][1]['unit']);
        self::assertSame(14.49, $saved['answer']['items'][2]['value']);
        self::assertSame('percent', $saved['answer']['items'][2]['unit']);
        self::assertSame([
            ['metric_key' => 'detail_visitors', 'storage_field' => 'detail_exposure', 'unit' => 'people', 'value' => 206],
            ['metric_key' => 'exposure_users', 'storage_field' => 'list_exposure', 'unit' => 'people', 'value' => 1422],
        ], $saved['answer']['items'][2]['calculation_inputs']);
        self::assertSame(['online_daily_data#102476'], $saved['fact_refs']);
        self::assertSame('exposure_volume_semantic_missing', $saved['data_gaps'][0]['code']);
        self::assertSame('ota_exposure_volume', $saved['data_gaps'][0]['metric_key']);
        self::assertSame(
            ['ota_exposure_volume', 'detail_exposure', 'exposure_to_visit_rate'],
            $saved['operating_question']['answer']['query_router']['metric_keys']
        );
        self::assertSame(
            'passed',
            $saved['analysis_quality_receipt']['quality_status'],
            json_encode($saved['analysis_quality_receipt'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''
        );
        self::assertSame('limited', $saved['analysis_quality_receipt']['claim_status']);
        self::assertSame('partial', $saved['analysis_quality_receipt']['status']);
        self::assertSame($saved, $router->read((int)$saved['id'], 10, [80, 81]));
    }

    public function testExplicitQuestionAndCurrentScopePlatformConflictFailsClosed(): void
    {
        $result = $this->router()->route(10, [80, 81], 7, [
            'query' => 'Hotel 80 8月23日美团曝光人数多少？',
            'current_scope' => [
                'hotel_id' => 80,
                'hotel_name' => 'Hotel 80',
                'platform' => 'ctrip',
                'date_start' => '2026-08-23',
                'date_end' => '2026-08-23',
            ],
        ]);

        self::assertSame('clarification', $result['route_type']);
        self::assertSame('clarification_required', $result['status']);
        self::assertStringContainsString('问题写的是美团', $result['answer_summary']);
        self::assertSame([], $result['fact_refs']);

        $expandedScope = $this->router()->route(10, [80, 81], 7, [
            'query' => 'Hotel 80 8月23日携程和美团曝光人数分别是多少？',
            'current_scope' => [
                'hotel_id' => 80,
                'hotel_name' => 'Hotel 80',
                'platform' => 'ctrip',
                'date_start' => '2026-08-23',
                'date_end' => '2026-08-23',
            ],
        ]);
        self::assertSame('clarification', $expandedScope['route_type']);
        self::assertSame('clarification_required', $expandedScope['status']);
        self::assertStringContainsString('当前范围选择的是携程', $expandedScope['answer_summary']);
        self::assertSame([], $expandedScope['fact_refs']);
    }

    public function testCanonicalAdrRejectsOrderAmountBasisWithoutRoomRevenue(): void
    {
        $closure = $this->canonicalClosure();
        $closure['platforms']['meituan']['fields'][] = [
            'key' => 'adr',
            'metric_key' => 'adr',
            'label' => 'ADR',
            'status' => 'verified_calculation',
            'value' => 99.5,
            'unit' => 'CNY',
            'basis' => 'order_summary_amount / room_nights',
            'source_paths' => ['online_daily_data.amount', 'online_daily_data.quantity'],
            'source_record_refs' => ['online_daily_data#102476'],
            'revenue_analysis_consumable' => true,
            'strict_final_gate' => true,
            'readback_status' => 'readback_verified',
            'validation_status' => 'derived_verified',
            'history_statuses' => ['success'],
            'collected_at' => '2026-08-24 23:17:33',
            'capture_ref' => 'platform_data_sync_task#4427',
        ];
        $result = $this->router(
            static fn(int $hotelId, string $date): array => $closure
        )->route(10, [80, 81], 7, [
            'query' => 'Hotel 80 8月23日美团ADR是多少？',
            'current_scope' => [],
        ]);

        self::assertSame('blocked_by_canonical_fact_status', $result['status']);
        self::assertSame('adr', $result['answer']['metric']['key']);
        self::assertNull($result['answer']['value']);
        self::assertSame('adr_room_revenue_semantic_missing', $result['data_gaps'][0]['code']);
        self::assertStringContainsString('没有显式 room_revenue 口径', $result['answer_summary']);
    }

    public function testSuperAdminContextUsesTheAccessibleHotelsOwnTenantForSaveAndReadback(): void
    {
        $router = $this->router();
        $saved = $router->route(0, [80, 81], 1, [
            'query' => 'Hotel 80 8月23日美团曝光人数多少？',
            'current_scope' => [],
        ]);
        self::assertSame(10, $saved['operating_question']['tenant_id']);
        self::assertSame(80, $saved['operating_question']['hotel_id']);
        self::assertSame($saved, $router->read((int)$saved['id'], 0, [80, 81]));
    }

    public function testOpennessNeverBecomesAnOperatingFact(): void
    {
        $result = $this->ask('Openness 是酒店指标吗？');
        self::assertSame('term_definition', $result['route_type']);
        self::assertSame('reference_only', $result['status']);
        self::assertFalse($result['answer']['business_fact_eligible']);
        self::assertSame([], $result['fact_refs']);
        self::assertStringContainsString('不是宿析OS酒店经营指标', $result['answer']['definition']);
    }

    public function testCtripMissingReasonNamesTheExactMissingDenominator(): void
    {
        $result = $this->router()->route(10, [80, 81], 7, [
            'query' => '携程为什么没有曝光转化率？',
            'current_scope' => [
                'hotel_id' => 80,
                'platform' => 'ctrip',
                'date_start' => '2026-08-23',
                'date_end' => '2026-08-23',
            ],
        ]);
        self::assertSame('blocked_by_missing_metric', $result['status']);
        self::assertStringContainsString('没有可信曝光字段', $result['answer_summary']);
        self::assertStringNotContainsString('暂无数据', $result['answer_summary']);
        self::assertNull($result['answer']['value']);
    }

    /** @return array<string,mixed> */
    private function ask(string $question): array
    {
        return $this->router()->route(10, [80, 81], 7, [
            'query' => $question,
            'current_scope' => [],
        ]);
    }

    private function router(?Closure $fieldClosureReader = null, bool $useActualSystemGuide = false): PreciseQueryRouterService
    {
        $systemGuideResolver = $useActualSystemGuide
            ? static fn(array $payload): array => (new SystemUsageAssistantService())->guide($payload)
            : static function (array $payload): array {
                $topic = (string)(($payload['visible_topic_keys'] ?? [])[0] ?? 'task-navigation');
                return [
                    'status' => 'ready',
                    'mode' => 'fallback',
                    'assistant_mode' => 'guide',
                    'assistant_message' => $topic === 'typeless-dictionary'
                        ? '打开词库维护说明，按词源、去重、CSV格式和导入后总数完成核对。'
                        : '已找到对应的真实系统入口。',
                    'intent_summary' => $topic,
                    'goal' => (string)($payload['query'] ?? ''),
                    'topic_key' => $topic,
                    'topic' => ['key' => $topic, 'title' => $topic, 'category' => '测试导航'],
                    'journey' => [],
                    'steps' => [],
                    'clarifying_question' => '',
                    'follow_up_questions' => [],
                    'confidence' => 'high',
                    'boundary' => '只导航，不写业务数据。',
                    'runtime' => [
                        'status' => 'fallback',
                        'model_attempted' => false,
                        'llm_client_invoked' => false,
                        'external_llm_called' => false,
                    ],
                ];
            };
        return new PreciseQueryRouterService(
            $systemGuideResolver,
            static fn(int $hotelId, int $userId, string $platform, string $term): array => [
                'status' => 'no_match',
                'method' => 'test_knowledge_lookup',
                'matched_count' => 0,
                'returned_count' => 0,
                'excluded_count' => 0,
                'reason' => 'fixture_no_match',
                'items' => [],
            ],
            static fn(): DateTimeImmutable => new DateTimeImmutable(
                '2026-08-25 12:00:00',
                new DateTimeZone('Asia/Shanghai')
            ),
            $fieldClosureReader,
            fn(int $hotelId, string $businessDate): array =>
                $this->scopeClosure($hotelId, $businessDate)
        );
    }

    /** @return array<string,mixed> */
    private function scopeClosure(int $hotelId, string $businessDate): array
    {
        $field = static function (string $platform, int $value) use (
            $hotelId,
            $businessDate
        ): array {
            return [
                'key' => 'exposure',
                'status' => 'strict_readback',
                'value' => $value,
                'strict_final_gate' => true,
                'revenue_analysis_consumable' => true,
                'readback_status' => 'readback_verified',
                'tenant_id' => 10,
                'system_hotel_id' => $hotelId,
                'platform' => $platform,
                'business_date' => $businessDate,
            ];
        };
        $identity = 'dual_ota_field_closure#scope-' . str_replace('-', '', $businessDate);
        $ctripFields = in_array($businessDate, ['2026-08-22', '2026-08-23'], true)
            ? [$field('ctrip', $businessDate === '2026-08-22' ? 900 : 160)]
            : [];
        $meituanFields = in_array($businessDate, ['2026-08-23', '2026-08-24'], true)
            ? [$field('meituan', $businessDate === '2026-08-24' ? 1500 : 1422)]
            : [];
        return [
            'contract_version' => 'dual_ota_field_closure.v1',
            'tenant_id' => 10,
            'hotel_id' => $hotelId,
            'business_date' => $businessDate,
            'page_identity' => $identity,
            'consumer_contract' => [
                'contract_version' => 'trusted_ota_daily_fact_consumer.v1',
                'closure_identity' => $identity,
                'field_source_path' => 'platforms.{platform}.fields',
                'metric_values_duplicated' => false,
                'allowed_fact_statuses' => ['strict_readback', 'verified_calculation'],
            ],
            'platforms' => [
                'ctrip' => ['fields' => $ctripFields],
                'meituan' => ['fields' => $meituanFields],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function canonicalClosure(): array
    {
        $field = static function (
            string $key,
            string $status,
            int|float|null $value,
            array $refs,
            bool $consumable,
            array $extra = []
        ): array {
            return array_replace([
                'key' => $key,
                'metric_key' => $key,
                'status' => $status,
                'value' => $value,
                'unit' => match ($key) {
                    'conversion' => 'percent',
                    'revenue', 'adr' => 'CNY',
                    default => 'people',
                },
                'label' => match ($key) {
                    'exposure' => '曝光人数',
                    'visits' => '商详访客数',
                    'conversion' => '曝光到访率',
                    default => $key,
                },
                'semantic_metric_key' => match ($key) {
                    'exposure' => 'meituan_exposure_users',
                    'visits' => 'meituan_detail_visitors',
                    'conversion' => 'exposure_to_visit_rate',
                    default => $key,
                },
                'semantic_metric_status' => $key === 'conversion' ? 'derived_same_snapshot' : 'source_defined',
                'semantic_contract_version' => 'ota_field_semantics.v1',
                'source_record_refs' => $refs,
                'revenue_analysis_consumable' => $consumable,
                'strict_final_gate' => $consumable,
                'readback_status' => $refs === [] ? 'not_attempted' : 'readback_verified',
                'validation_status' => $status === 'verified_calculation'
                    ? 'derived_verified'
                    : ($consumable ? 'verified' : $status),
                'history_statuses' => $refs === [] ? [] : ['success'],
                'collected_at' => $refs === [] ? null : '2026-08-24 23:17:33',
                'source_paths' => [],
                'capture_ref' => $refs === [] ? null : 'platform_data_sync_task#4427',
            ], $extra);
        };
        return [
            'contract_version' => 'dual_ota_field_closure.v1',
            'tenant_id' => 10,
            'hotel_id' => 80,
            'business_date' => '2026-08-23',
            'metric_scope' => 'ota_channel_only',
            'closure_digest' => str_repeat('a', 64),
            'page_identity' => 'dual_ota_field_closure#canonical-test',
            'consumer_contract' => [
                'contract_version' => 'trusted_ota_daily_fact_consumer.v1',
                'allowed_fact_statuses' => ['strict_readback', 'verified_calculation'],
            ],
            'platforms' => [
                'ctrip' => [
                    'fields' => [
                        $field('exposure', 'field_unavailable', null, [], false, [
                            'note' => '本次曝光事实缺失，因此不计算转化率。',
                            'next_action' => '补采携程曝光端点。',
                        ]),
                        $field('conversion', 'field_unavailable', null, [], false, [
                            'note' => '本次曝光事实缺失，因此不计算转化率。',
                            'next_action' => '先取得同一快照曝光。',
                        ]),
                    ],
                ],
                'meituan' => [
                    'fields' => [
                        $field('revenue', 'caliber_uncertain', null, [
                            'online_daily_data#101920', 'online_daily_data#101926',
                        ], false, [
                            'note' => '业务卡片金额与订单汇总金额口径冲突。',
                            'observed_values' => [
                                ['value' => 6461.43, 'basis' => 'business_card_amount'],
                                ['value' => 7025.14, 'basis' => 'order_summary_amount'],
                            ],
                        ]),
                        $field('exposure', 'strict_readback', 1422, ['online_daily_data#102476'], true),
                        $field('visits', 'strict_readback', 206, ['online_daily_data#102476'], true),
                        $field('conversion', 'verified_calculation', 14.49, ['online_daily_data#102476'], true, [
                            'basis' => 'detail_exposure / list_exposure',
                        ]),
                    ],
                ],
            ],
        ];
    }

    /** @param array<string,int|float> $metrics @return array<string,mixed> */
    private function fact(
        string $platform,
        string $date,
        array $metrics,
        string $capturedAt,
        string $traceId
    ): array {
        $fieldFacts = [];
        foreach (array_keys($metrics) as $field) {
            [$dataType, $metricKey, $sourceKey] = match ($field) {
                'list_exposure' => ['traffic', 'list_exposure', 'exposureUV'],
                'detail_exposure' => ['traffic', 'detail_exposure', 'intentionUV'],
                'flow_rate' => ['traffic', 'browse_to_pay_rate', 'flow_rate'],
                'book_order_num' => ['order', 'order_count', 'order_count'],
                'quantity' => ['business', 'sales_room_nights', 'quantity'],
                'amount' => ['business', 'sales_amount', 'amount'],
                default => ['traffic', $field, $field],
            };
            $fieldFact = [
                'status' => 'captured',
                'stored_value_present' => true,
                'source_path' => 'fixture.' . $field,
                'storage_field' => $field,
                'data_type' => $dataType,
                'metric_key' => $metricKey,
                'source_key' => $sourceKey,
            ];
            if ($field === 'flow_rate') {
                $fieldFact['stored_unit'] = 'percent';
            } elseif ($field === 'amount') {
                $fieldFact['currency_code'] = 'CNY';
            }
            $fieldFacts[] = $fieldFact;
        }
        return array_replace([
            'tenant_id' => 10,
            'system_hotel_id' => 80,
            'data_date' => $date,
            'platform' => $platform,
            'source' => $platform,
            'data_type' => array_keys($metrics) === ['book_order_num']
                ? 'order'
                : (array_keys($metrics) === ['quantity'] ? 'business' : 'traffic'),
            'dimension' => 'self_total',
            'readback_verified' => 1,
            'readback_verified_at' => $capturedAt,
            'validation_status' => 'verified',
            'history_status' => 'success',
            'ingestion_method' => 'structured_fixture',
            'source_trace_id' => $traceId,
            'amount' => 0,
            'quantity' => 0,
            'book_order_num' => 0,
            'comment_score' => 0,
            'data_value' => 0,
            'list_exposure' => 0,
            'detail_exposure' => 0,
            'flow_rate' => 0,
            'order_filling_num' => 0,
            'order_submit_num' => 0,
            'raw_data' => json_encode([
                'captured_at' => $capturedAt,
                'field_facts' => $fieldFacts,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ], $metrics);
    }
}
