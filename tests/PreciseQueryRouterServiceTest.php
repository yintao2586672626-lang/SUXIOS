<?php
declare(strict_types=1);

namespace Tests;

use app\service\PreciseQueryLexicon;
use app\service\PreciseQueryRouterService;
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
        Db::execute("INSERT INTO hotels (id,tenant_id,name,status) VALUES (80,10,'Hotel 80',1),(81,10,'Hotel 81',1),(82,10,'杭州望月酒店',1),(83,10,'杭州望月酒店',1),(90,11,'Other tenant',1)");
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
                'book_order_num' => 12,
                'quantity' => 9,
            ], '2026-08-23 10:31:00', 'meituan-h80-20260823'),
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

    public function testRuntimeLexiconIsA110TermProjectionOfThe2990TermSource(): void
    {
        $metadata = PreciseQueryLexicon::metadata();
        self::assertSame(2990, $metadata['source_total_terms']);
        self::assertSame(110, $metadata['runtime_extracted_term_count']);
        self::assertCount(110, PreciseQueryLexicon::extractedTerms());
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
                'ai-daily-report', 'typeless-dictionary', 'knowledge-search',
                'data-health', 'revenue-report', 'operation-optimizer', 'operations',
            ],
        ]);

        self::assertGreaterThan(0, $result['id']);
        self::assertSame('readback_verified', $result['persistence_status']);
        self::assertSame($expectedRoute, $result['route_type']);
        self::assertSame($expectedStatus, $result['status']);
        self::assertSame(110, $result['lexicon']['runtime_extracted_term_count']);
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
            '01 exact Meituan exposure' => ['Hotel 80 8月23日美团曝光多少？', [], 'operating_query', 'answered_deterministically', 'list_exposure', 1422, null],
            '02 conversational same-day visitors' => ['当天美团来了多少访客？', $meituanDay, 'operating_query', 'answered_deterministically', 'detail_exposure', 206, null],
            '03 deterministic exposure-to-visit formula' => ['曝光到访率是多少，怎么算的？', $meituanDay, 'operating_query', 'answered_deterministically', 'exposure_to_visit_rate', 14.49, null],
            '04 Ctrip missing conversion denominator' => ['携程为什么没有曝光转化率？', $ctripDay, 'operating_query', 'blocked_by_missing_metric', 'exposure_to_visit_rate', null, null],
            '05 revenue semantic gap' => ['收入为什么没有出来？', $meituanDay, 'operating_query', 'blocked_by_missing_metric', 'room_revenue', null, null],
            '06 refuse vague platform comparison' => ['昨天哪个平台表现更好？', $h80, 'operating_query', 'blocked_by_incomparable_scope', null, null, null],
            '07 AI daily report navigation' => ['AI经营日报在哪？', [], 'system_navigation', 'navigation_ready', null, null, 'ai-daily-report'],
            '08 trusted broadcast copy navigation' => ['可信播报怎么复制？', [], 'system_navigation', 'navigation_ready', null, null, 'ai-daily-report'],
            '09 personal-context term' => ['Openness 是酒店指标吗？', [], 'term_definition', 'reference_only', null, null, null],
            '10 Typeless maintenance navigation' => ['Typeless 总词库怎么更新？', [], 'system_navigation', 'navigation_ready', null, null, 'typeless-dictionary'],
            '11 visitor synonym' => ['Hotel 80 8月23日美团浏览人数是多少？', [], 'operating_query', 'answered_deterministically', 'detail_exposure', 206, null],
            '12 ambiguous mixed-row order synonym' => ['Hotel 80 8月23日美团订单量多少？', [], 'operating_query', 'blocked_by_missing_metric', 'book_order_num', null, null],
            '13 Asia Shanghai day-before-yesterday' => ['Hotel 80 前天美团曝光量多少？', [], 'operating_query', 'answered_deterministically', 'list_exposure', 1422, null],
            '14 latest strict readback date' => ['Hotel 80 最近一次美团曝光量多少？', [], 'operating_query', 'answered_deterministically', 'list_exposure', 1500, null],
            '15 exact Ctrip exposure gap' => ['Hotel 80 8月23日携程曝光量多少？', [], 'operating_query', 'blocked_by_missing_metric', 'list_exposure', null, null],
            '16 exact Ctrip visitor' => ['Hotel 80 8月23日携程详情访客多少？', [], 'operating_query', 'answered_deterministically', 'detail_exposure', 160, null],
            '17 one hotel clarification' => ['8月23日美团曝光多少？', [], 'clarification', 'clarification_required', null, null, null],
            '18 one platform clarification' => ['Hotel 80 8月23日曝光多少？', [], 'clarification', 'clarification_required', null, null, null],
            '19 one date clarification' => ['Hotel 80 美团曝光多少？', [], 'clarification', 'clarification_required', null, null, null],
            '20 hotel metric definition' => ['ADR 是什么？', [], 'term_definition', 'reference_only', null, null, null],
            '21 refuse cross-platform partial facts' => ['Hotel 80 8月23日携程和美团曝光量哪个高？', [], 'operating_query', 'blocked_by_cross_platform_evidence', 'list_exposure', null, null],
            '22 unknown intent asks one route clarification' => ['帮我看看。', [], 'clarification', 'clarification_required', null, null, null],
        ];
    }

    public function testFormulaUsesOneSourceRowWithoutMixingBrowseToPayRate(): void
    {
        $result = $this->ask('Hotel 80 8月23日美团曝光到访率是多少，怎么算？');
        self::assertSame(14.49, $result['answer']['value']);
        self::assertSame('none', $result['answer']['conflict_status']);
        self::assertSame([
            ['metric_key' => 'detail_exposure', 'value' => 206.0, 'unit' => '人'],
            ['metric_key' => 'list_exposure', 'value' => 1422.0, 'unit' => '次'],
        ], $result['answer']['calculation_inputs']);
        self::assertStringContainsString('206 ÷ 1,422 × 100% = 14.49%', $result['answer']['formula']);
        self::assertCount(1, $result['fact_refs']);
    }

    public function testProductionRouterCopiesCanonicalClosureFactsAndBlocksConflictsAndComparison(): void
    {
        $closure = $this->canonicalClosure();
        $router = $this->router(static fn(int $hotelId, string $date): array => $closure);

        $exposure = $router->route(10, [80, 81], 7, [
            'query' => 'Hotel 80 8月23日美团曝光多少？',
            'current_scope' => [],
        ]);
        self::assertSame('answered_from_canonical_closure', $exposure['status']);
        self::assertSame(1422, $exposure['answer']['value']);
        self::assertSame('exposure', $exposure['answer']['canonical_field_key']);
        self::assertSame('dual_ota_field_closure#canonical-test', $exposure['answer']['closure_identity']);
        self::assertSame(['online_daily_data#102476'], $exposure['fact_refs']);

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
            ['metric_key' => 'detail_exposure', 'unit' => 'users', 'value' => 206],
            ['metric_key' => 'list_exposure', 'unit' => 'users', 'value' => 1422],
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
            'query' => 'Hotel 80 8月23日美团曝光多少？',
            'current_scope' => [],
        ]);
        $readback = $router->read((int)$saved['id'], 10, [80, 81]);
        self::assertSame($saved, $readback);
        self::assertSame($saved['answer'], $readback['answer']);
        self::assertSame($saved['parsed_scope'], $readback['parsed_scope']);
        self::assertSame($saved['fact_refs'], $readback['fact_refs']);
        self::assertSame($saved['content_digest'], $readback['content_digest']);
    }

    public function testSuperAdminContextUsesTheAccessibleHotelsOwnTenantForSaveAndReadback(): void
    {
        $router = $this->router();
        $saved = $router->route(0, [80, 81], 1, [
            'query' => 'Hotel 80 8月23日美团曝光多少？',
            'current_scope' => [],
        ]);
        self::assertSame(10, $saved['operating_question']['tenant_id']);
        self::assertSame(80, $saved['operating_question']['hotel_id']);
        self::assertSame($saved, $router->read((int)$saved['id'], 0, [80, 81]));
    }

    public function testReferenceQueryReplayReturnsTheSameSavedRecord(): void
    {
        $router = $this->router();
        $first = $router->route(10, [80, 81], 7, [
            'query' => 'Openness 是酒店指标吗？',
            'current_scope' => [],
        ]);
        $second = $router->route(10, [80, 81], 7, [
            'query' => 'Openness 是酒店指标吗？',
            'current_scope' => [],
        ]);

        self::assertSame($first['id'], $second['id']);
        self::assertSame(1, Db::name('hotel_operating_questions')->count());
        self::assertSame($first['content_digest'], $second['content_digest']);
    }

    public function testDirectPersistenceConflictClassifierAcceptsOnlyUniqueRequestConflicts(): void
    {
        $method = new \ReflectionMethod(PreciseQueryRouterService::class, 'isDuplicateRequestConflict');
        $router = $this->router();

        self::assertTrue($method->invoke($router, new \RuntimeException('Duplicate entry for uniq request', 1062)));
        self::assertTrue($method->invoke($router, new \RuntimeException('UNIQUE constraint failed: request_key')));
        self::assertFalse($method->invoke($router, new \RuntimeException('foreign key constraint failed', 23000)));
        self::assertFalse($method->invoke($router, new \RuntimeException('database connection lost')));
    }

    public function testHotelTenantLookupDoesNotTurnDatabaseFailureIntoMissingTenant(): void
    {
        Db::execute('DROP TABLE hotels');
        $method = new \ReflectionMethod(PreciseQueryRouterService::class, 'hotelTenantId');

        try {
            $method->invoke($this->router(), 80);
            self::fail('Database failures must remain database failures.');
        } catch (\Throwable $error) {
            self::assertNotSame('', trim($error->getMessage()));
            self::assertStringNotContainsString('缺少可核对的租户归属', $error->getMessage());
        }
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

    public function testChineseHotelNameRequiresOneUniqueAccessibleMatch(): void
    {
        $router = $this->router();
        $method = new \ReflectionMethod(PreciseQueryRouterService::class, 'resolveHotel');
        $method->setAccessible(true);

        $unique = $method->invoke($router, '查一下杭州望月酒店昨天的美团曝光', [], [80, 81, 82]);
        self::assertSame(82, $unique['id']);
        self::assertSame('杭州望月酒店', $unique['name']);
        self::assertSame('question_hotel_name', $unique['source']);
        self::assertSame('', $unique['error']);

        $ambiguous = $method->invoke($router, '查一下杭州望月酒店昨天的美团曝光', [], [82, 83]);
        self::assertSame(0, $ambiguous['id']);
        self::assertStringContainsString('匹配到多个可访问门店', $ambiguous['error']);

        $inaccessible = $method->invoke($router, '查一下杭州望月酒店昨天的美团曝光', [], [80, 81]);
        self::assertSame(0, $inaccessible['id']);
        self::assertSame('', $inaccessible['name']);
    }

    public function testExplicitDateRangeReturnsDailyTrendWithoutPeriodAggregation(): void
    {
        $result = $this->router()->route(10, [80, 81], 7, [
            'query' => 'Hotel 80 美团 2026-08-23至2026-08-24 曝光量趋势',
            'current_scope' => [],
        ]);

        self::assertSame('operating_query', $result['route_type'], json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertSame('answered_deterministically_range', $result['status']);
        self::assertSame('operating_metric_range', $result['answer']['kind']);
        self::assertSame(
            ['start_date' => '2026-08-23', 'end_date' => '2026-08-24'],
            $result['answer']['date_range']
        );
        self::assertSame([1422, 1500], array_column($result['answer']['points'], 'value'));
        self::assertSame(['available', 'available'], array_column($result['answer']['points'], 'status'));
        self::assertSame(2, $result['answer']['available_day_count']);
        self::assertSame(0, $result['answer']['missing_day_count']);
        self::assertFalse($result['answer']['aggregation_performed']);
        self::assertSame('2026-08-23', $result['operating_question']['date_start']);
        self::assertSame('2026-08-24', $result['operating_question']['date_end']);
        self::assertCount(2, $result['fact_refs']);
        self::assertSame('passed', $result['analysis_quality_receipt']['quality_status']);
        self::assertSame('supported', $result['analysis_quality_receipt']['claim_status']);
        self::assertSame('ready', $result['analysis_quality_receipt']['status']);
    }

    public function testDateRangeKeepsMissingDaysAndRejectsOverlargeOrCrossPlatformRanges(): void
    {
        $partial = $this->router()->route(10, [80, 81], 7, [
            'query' => 'Hotel 80 美团 2026-08-22至2026-08-24 曝光量趋势',
            'current_scope' => [],
        ]);
        self::assertSame('answered_deterministically_range_partial', $partial['status'], json_encode($partial, JSON_UNESCAPED_UNICODE));
        self::assertSame(['missing', 'available', 'available'], array_column($partial['answer']['points'], 'status'));
        self::assertNull($partial['answer']['points'][0]['value']);
        self::assertSame(1, $partial['answer']['missing_day_count']);
        self::assertFalse($partial['answer']['aggregation_performed']);
        self::assertSame('passed', $partial['analysis_quality_receipt']['quality_status']);
        self::assertSame('limited', $partial['analysis_quality_receipt']['claim_status']);
        self::assertSame('partial', $partial['analysis_quality_receipt']['status']);

        $tooLarge = $this->router()->route(10, [80, 81], 7, [
            'query' => 'Hotel 80 美团 2026-07-01至2026-08-23 曝光量趋势',
            'current_scope' => [],
        ]);
        self::assertSame('clarification', $tooLarge['route_type']);
        self::assertStringContainsString('最多31天', $tooLarge['answer_summary']);

        $comparison = $this->router()->route(10, [80, 81], 7, [
            'query' => 'Hotel 80 2026-08-23至2026-08-24 携程和美团曝光量哪个高？',
            'current_scope' => [],
        ]);
        self::assertSame('clarification', $comparison['route_type']);
        self::assertStringContainsString('只支持单平台逐日趋势', $comparison['answer_summary']);
    }

    public function testExposureEstimationAppearsAsBlockedEstimateOnlyReference(): void
    {
        $closures = $this->exposureEstimationClosures(7);
        $router = $this->router(static fn(int $hotelId, string $date): array => $closures[$date] ?? []);
        $result = $router->route(10, [80, 81], 7, [
            'query' => 'Hotel 80 2026-08-15 美团曝光人数反推估算',
            'current_scope' => [],
        ]);

        self::assertSame('operating_query', $result['route_type']);
        self::assertSame('blocked_by_estimate_only_reference', $result['status']);
        self::assertSame('exposure_estimation_reference', $result['answer']['kind']);
        self::assertSame(1000, $result['answer']['value']);
        self::assertSame('users', $result['answer']['unit']);
        self::assertSame('derived_estimate', $result['answer']['verification_status']);
        self::assertSame('estimate_only_not_platform_fact', $result['answer']['readback_status']);
        self::assertFalse($result['answer']['decision_eligible']);
        self::assertFalse($result['answer']['writeback_allowed']);
        self::assertSame('unchanged', $result['answer']['platform_fact_status']);
        self::assertSame(7, $result['answer']['estimate_receipt']['accepted_verified_pairs']);
        self::assertSame('blocked', $result['analysis_quality_receipt']['claim_status']);
        self::assertFalse($result['analysis_quality_receipt']['usage_policy']['analysis_claim_allowed']);
    }

    public function testExposureEstimationInsufficientBaselineReturnsNoNumberAndExactReadback(): void
    {
        $closures = $this->exposureEstimationClosures(6);
        $router = $this->router(static fn(int $hotelId, string $date): array => $closures[$date] ?? []);
        $result = $router->route(10, [80, 81], 7, [
            'query' => 'Hotel 80 2026-08-15 美团漏抓曝光人数，帮我估算',
            'current_scope' => [],
        ]);

        self::assertSame('blocked_by_exposure_estimation_insufficient_baseline', $result['status']);
        self::assertNull($result['answer']['value']);
        self::assertSame(6, $result['answer']['estimate_receipt']['accepted_verified_pairs']);
        self::assertStringContainsString('至少需要 7 天', $result['answer_summary']);
        self::assertSame('readback_verified', $result['persistence_status']);
        self::assertFalse($result['answer']['writeback_allowed']);
    }

    /** @return array<string,mixed> */
    private function ask(string $question): array
    {
        return $this->router()->route(10, [80, 81], 7, [
            'query' => $question,
            'current_scope' => [],
        ]);
    }

    private function router(?Closure $fieldClosureReader = null): PreciseQueryRouterService
    {
        return new PreciseQueryRouterService(
            static function (array $payload): array {
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
            },
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

    /** @return array<string,array<string,mixed>> */
    private function exposureEstimationClosures(int $pairCount): array
    {
        $closures = [];
        $target = '2026-08-15';
        $closures[$target] = $this->exposureEstimationClosure($target, false, 100, 0, 900);
        for ($offset = 1; $offset <= $pairCount; $offset++) {
            $date = (new DateTimeImmutable($target))->modify('-' . $offset . ' days')->format('Y-m-d');
            $visits = 100 + $offset;
            $closures[$date] = $this->exposureEstimationClosure(
                $date,
                true,
                $visits,
                $visits * 10,
                900 + $offset
            );
        }
        return $closures;
    }

    /** @return array<string,mixed> */
    private function exposureEstimationClosure(
        string $date,
        bool $withExposure,
        int $visits,
        int $exposure,
        int $sourceId
    ): array {
        $ref = 'online_daily_data#' . $sourceId;
        $field = static fn(string $key, int $value): array => [
            'key' => $key,
            'metric_key' => $key,
            'value' => $value,
            'unit' => 'users',
            'status' => 'strict_readback',
            'validation_status' => 'verified',
            'history_statuses' => ['success'],
            'readback_status' => 'readback_verified',
            'strict_final_gate' => true,
            'revenue_analysis_consumable' => true,
            'source_record_refs' => [$ref],
            'source_paths' => ['fixture.same_snapshot'],
            'cumulative_cutoff' => '23:00',
            'metric_definition_version' => 'fixture-exposure-users-detail-visitors.v1',
        ];
        $fields = [$field('visits', $visits)];
        if ($withExposure) {
            $fields[] = $field('exposure', $exposure);
        }
        return [
            'contract_version' => 'dual_ota_field_closure.v1',
            'tenant_id' => 10,
            'hotel_id' => 80,
            'business_date' => $date,
            'page_identity' => 'dual_ota_field_closure#estimate-' . str_replace('-', '', $date),
            'consumer_contract' => [
                'contract_version' => 'trusted_ota_daily_fact_consumer.v1',
                'allowed_fact_statuses' => ['strict_readback'],
            ],
            'platforms' => ['meituan' => ['fields' => $fields], 'ctrip' => ['fields' => []]],
        ];
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
                    default => 'users',
                },
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
            $identity = match ($field) {
                'list_exposure' => $platform === 'meituan'
                    ? ['metric_key' => 'ota_exposure_volume', 'source_key' => 'mt_exposure', 'data_type' => 'traffic']
                    : ['metric_key' => 'list_exposure', 'source_key' => 'list_exposure', 'data_type' => 'traffic'],
                'detail_exposure' => $platform === 'meituan'
                    ? ['metric_key' => 'meituan_detail_visitors', 'source_key' => 'mt_intention_uv', 'data_type' => 'traffic']
                    : ['metric_key' => 'ctrip_detail_visitors', 'source_key' => 'detail_exposure', 'data_type' => 'traffic'],
                'book_order_num' => ['metric_key' => 'order_count', 'source_key' => 'order_count', 'data_type' => 'order'],
                'quantity' => $platform === 'meituan'
                    ? ['metric_key' => 'mt_pay_rooms', 'source_key' => 'mt_pay_rooms', 'data_type' => 'traffic']
                    : ['metric_key' => 'room_nights', 'source_key' => 'room_nights', 'data_type' => 'order'],
                'flow_rate' => ['metric_key' => 'browse_to_pay_rate', 'source_key' => 'flow_rate', 'data_type' => 'traffic'],
                default => ['metric_key' => $field, 'source_key' => $field, 'data_type' => 'traffic'],
            };
            $fieldFacts[] = [
                'status' => 'captured',
                'stored_value_present' => true,
                'source_path' => 'fixture.' . $field,
                'storage_field' => $field,
            ] + $identity;
        }
        return array_replace([
            'tenant_id' => 10,
            'system_hotel_id' => 80,
            'data_date' => $date,
            'platform' => $platform,
            'source' => $platform,
            'data_type' => 'traffic',
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
