<?php
declare(strict_types=1);

namespace Tests;

use app\service\LocalAiRuntimeService;
use app\service\MasterPerspectiveAdvisoryCatalog;
use app\service\OperatingQuestionCouncilService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class MasterPerspectiveAdvisoryCouncilTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App(dirname(__DIR__));
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'master_perspective_council_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';
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
        Db::execute('DROP TABLE IF EXISTS hotel_operating_question_council_runs');
        Db::execute('DROP TABLE IF EXISTS online_daily_data');
        Db::execute('DROP TABLE IF EXISTS hotels');
        Db::execute(
            'CREATE TABLE hotel_operating_question_council_runs ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, question_id INTEGER, '
            . 'request_key TEXT, mode TEXT, status TEXT, members_json TEXT, synthesis_json TEXT, '
            . 'evidence_refs_json TEXT, model_meta_json TEXT, decision_effect TEXT, content_digest TEXT, '
            . 'created_by INTEGER, created_at TEXT, updated_at TEXT, '
            . 'UNIQUE(tenant_id,hotel_id,question_id,request_key))'
        );
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER, status INTEGER)');
        Db::execute(
            'CREATE TABLE online_daily_data ('
            . 'id INTEGER PRIMARY KEY, tenant_id INTEGER, system_hotel_id INTEGER, data_date TEXT, '
            . 'platform TEXT, source TEXT, data_type TEXT, dimension TEXT, validation_status TEXT, '
            . 'history_status TEXT, readback_verified INTEGER, readback_verified_at TEXT, '
            . 'ingestion_method TEXT, source_trace_id TEXT, list_exposure INTEGER)'
        );
        Db::name('hotels')->insert(['id' => 20, 'tenant_id' => 10, 'status' => 1]);
        Db::name('hotels')->insert(['id' => 21, 'tenant_id' => 10, 'status' => 1]);
    }

    public function testCatalogSelectsBoundedProblemRelevantLensesWithoutClaimingHumanAuthority(): void
    {
        $catalog = new MasterPerspectiveAdvisoryCatalog();
        $panel = $catalog->select('店长团队处理客诉时涉及员工评分和隐私权限，怎么形成公平流程？');
        $keys = array_column($panel['selected_lenses'], 'key');

        self::assertSame(MasterPerspectiveAdvisoryCatalog::SOURCE_OUTER_ZIP_SHA256, $panel['source']['outer_zip_sha256']);
        self::assertSame(165, $panel['source']['source_entry_count']);
        self::assertSame('hash_verified_binary_duplicate', $panel['source']['attachment_status']);
        self::assertContains('evidence_and_uncertainty', $keys);
        self::assertContains('customer_and_value', $keys);
        self::assertContains('communication_and_alignment', $keys);
        self::assertContains('ethics_and_fairness', $keys);
        self::assertLessThanOrEqual(5, count($keys));
        self::assertCount(count(array_unique($keys)), $keys);
        self::assertTrue($panel['boundaries']['reference_lens_only']);
        self::assertFalse($panel['boundaries']['personality_impersonation']);
        self::assertFalse($panel['boundaries']['real_human_opinion']);
        self::assertFalse($panel['boundaries']['automatic_action']);

        $generic = $catalog->select('今天这组已验证数据应该怎么看？');
        self::assertSame(
            ['evidence_and_uncertainty', 'customer_and_value', 'risk_and_resilience'],
            array_column($generic['selected_lenses'], 'key')
        );
    }

    public function testCouncilSavesSelectedLensAdviceAndReturnsExactIdempotentReadback(): void
    {
        $fakeClient = $this->fakeClient();
        $question = $this->readyQuestion();
        $service = new OperatingQuestionCouncilService(
            $fakeClient,
            static fn(): array => ['text' => ['ready' => true]],
            static fn(int $questionId, int $tenantId, array $hotelIds): array => $question,
            null,
            $this->strictFactReader($question)
        );

        $saved = $service->runShadow(41, 10, [20], 7, 'advisory1234');

        self::assertSame(OperatingQuestionCouncilService::CONTRACT_VERSION, $saved['contract_version']);
        self::assertSame('completed', $saved['status']);
        self::assertSame('readback_verified', $saved['persistence_status']);
        self::assertTrue($saved['created']);
        self::assertSame('none', $saved['decision_effect']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $saved['content_digest']);
        self::assertCount(5, $saved['members']);
        self::assertSame(6, $fakeClient->calls);
        self::assertSame(165, $saved['synthesis']['advisory_source']['source_entry_count']);
        self::assertSame(
            'primary_action_draft_requires_user_trigger',
            $saved['synthesis']['execution_handoff']['status']
        );
        self::assertTrue($saved['synthesis']['execution_handoff']['user_trigger_required']);
        self::assertFalse($saved['synthesis']['execution_handoff']['automatic_execution']);
        self::assertFalse($saved['boundaries']['action_creation_allowed']);
        self::assertFalse($saved['boundaries']['real_human_consensus']);
        self::assertFalse($saved['members'][0]['real_human_opinion']);
        self::assertNotEmpty($saved['members'][0]['source_lenses']);
        self::assertSame('verified_scope_guard_passed', $saved['members'][0]['grounding_status']);
        self::assertFalse($saved['members'][0]['causality_claimed']);
        self::assertFalse($saved['members'][0]['outcome_claimed']);
        self::assertSame(['online_daily_data#9001'], $saved['evidence_refs']);

        $replayed = $service->runShadow(41, 10, [20], 7, 'advisory1234');
        self::assertFalse($replayed['created']);
        self::assertSame('readback_verified', $replayed['persistence_status']);
        self::assertSame($saved['id'], $replayed['id']);
        self::assertSame($saved['content_digest'], $replayed['content_digest']);
        self::assertSame(6, $fakeClient->calls, '幂等回读不得再次调用模型');

        $latest = $service->latest(41, 10, [20]);
        self::assertSame($saved['id'], $latest['id']);
        self::assertSame($saved['content_digest'], $latest['content_digest']);
    }

    public function testCouncilRejectsUnsupportedBenchmarkPercentUnitAndOutcomeClaimsBeforeSaving(): void
    {
        $question = $this->readyQuestion();
        $question['answer']['fact_samples'][0]['metric_values']['flow_rate'] = 1.99;
        $question['answer']['fact_samples'][0]['metric_units']['flow_rate'] = 'source_defined_rate';
        $cases = [
            'percent' => ['流量转化率1.99%。', 'ungrounded_percent_unit'],
            'benchmark' => ['流量转化率低于行业平均。', 'ungrounded_benchmark_claim'],
            'outcome' => ['当前存在可优化空间。', 'ungrounded_outcome_claim'],
            'causal' => ['该调整导致订单增加。', 'ungrounded_causal_claim'],
        ];

        foreach ($cases as $name => [$claim, $expectedCode]) {
            $ungroundedClient = new class($claim) {
                public int $calls = 0;

                public function __construct(private string $claim)
                {
                }

                public function createJsonResponseEnvelope(array $messages, array $schema, string $modelKey): array
                {
                    $this->calls++;
                    return [
                        'data' => [
                            'assessment' => $this->claim,
                            'supported_points' => ['已取得严格回读事实。'],
                            'conflicting_points' => [],
                            'risks' => [],
                            'missing_information' => [],
                            'falsification_check' => '观察后续同口径事实。',
                            'supporting_evidence_refs' => ['online_daily_data#9001'],
                            'conflicting_evidence_refs' => [],
                            'evidence_refs' => ['online_daily_data#9001'],
                            'confidence' => 'high',
                        ],
                        'meta' => [
                            'provider' => 'ollama',
                            'model_key' => $modelKey,
                            'model' => LocalAiRuntimeService::TEXT_MODEL,
                            'finish_reason' => 'stop',
                            'fallback_used' => false,
                            'cache_hit' => false,
                            'degraded' => false,
                        ],
                    ];
                }
            };
            $service = new OperatingQuestionCouncilService(
                $ungroundedClient,
                static fn(): array => ['text' => ['ready' => true]],
                static fn(int $questionId, int $tenantId, array $hotelIds): array => $question,
                null,
                $this->strictFactReader($question)
            );

            $saved = $service->runShadow(41, 10, [20], 7, 'grounding-' . $name . '-1234');

            self::assertSame('failed', $saved['status'], $name);
            self::assertSame(5, $ungroundedClient->calls, $name);
            self::assertSame('all_persona_calls_failed', $saved['synthesis']['error_code'], $name);
            self::assertSame(
                [$expectedCode],
                array_values(array_unique(array_column($saved['members'], 'error_code'))),
                $name
            );
            self::assertStringNotContainsString(
                $claim,
                json_encode($saved['members'], JSON_UNESCAPED_UNICODE),
                $name
            );
            self::assertSame('readback_verified', $saved['persistence_status'], $name);
        }
    }

    public function testCouncilFailsClosedWithoutVerifiedFactAndStillExplainsSelectedFrameworks(): void
    {
        $fakeClient = $this->fakeClient();
        $question = $this->readyQuestion();
        $question['answer_status'] = 'blocked_by_missing_facts';
        $question['answer_summary'] = '缺少严格回读事实。';
        $question['fact_refs'] = [];
        $question['answer']['fact_samples'] = [];
        $question['answer']['action_drafts'] = [];
        $service = new OperatingQuestionCouncilService(
            $fakeClient,
            static fn(): array => ['text' => ['ready' => true]],
            static fn(int $questionId, int $tenantId, array $hotelIds): array => $question,
            null,
            static fn(): array => []
        );

        $saved = $service->runShadow(42, 10, [20], 7, 'blocked1234');

        self::assertSame('blocked_by_missing_facts', $saved['status']);
        self::assertSame('verified_fact_reference_missing', $saved['synthesis']['error_code']);
        self::assertSame([], $saved['members']);
        self::assertNotEmpty($saved['synthesis']['selected_lenses']);
        self::assertSame('advisory_only_no_action_draft', $saved['synthesis']['execution_handoff']['status']);
        self::assertSame([], $saved['evidence_refs']);
        self::assertSame(0, $fakeClient->calls);
        self::assertSame('readback_verified', $saved['persistence_status']);
    }

    public function testCouncilFailsClosedForInvalidMissingOutOfScopeOrDriftedFactReadback(): void
    {
        $cases = [
            'invalid' => [
                'mutate_question' => static function (array $question): array {
                    $question['fact_refs'] = ['online_daily_data#garbage'];
                    $question['answer']['fact_samples'][0]['ref'] = 'online_daily_data#garbage';
                    return $question;
                },
                'reader' => static fn(): array => [],
                'code' => 'verified_fact_reference_invalid',
            ],
            'missing' => [
                'mutate_question' => static fn(array $question): array => $question,
                'reader' => static fn(): array => [],
                'code' => 'verified_fact_readback_mismatch',
            ],
            'scope' => [
                'mutate_question' => static fn(array $question): array => $question,
                'reader' => static function (
                    int $tenantId,
                    int $hotelId,
                    string $platform,
                    string $dateStart,
                    string $dateEnd,
                    array $refs
                ): array {
                    $fact = self::factSample();
                    $fact['platform'] = 'meituan';
                    return [$fact];
                },
                'code' => 'verified_fact_scope_mismatch',
            ],
            'drift' => [
                'mutate_question' => static fn(array $question): array => $question,
                'reader' => static function (
                    int $tenantId,
                    int $hotelId,
                    string $platform,
                    string $dateStart,
                    string $dateEnd,
                    array $refs
                ): array {
                    $fact = self::factSample();
                    $fact['metric_values']['list_exposure'] = 1300;
                    return [$fact];
                },
                'code' => 'verified_fact_source_drift_detected',
            ],
        ];

        foreach ($cases as $name => $case) {
            $fakeClient = $this->fakeClient();
            $question = $case['mutate_question']($this->readyQuestion());
            $service = new OperatingQuestionCouncilService(
                $fakeClient,
                static fn(): array => ['text' => ['ready' => true]],
                static fn(int $questionId, int $tenantId, array $hotelIds): array => $question,
                null,
                $case['reader']
            );

            $saved = $service->runShadow(41, 10, [20], 7, 'strict-' . $name . '-1234');

            self::assertSame('blocked_by_missing_facts', $saved['status'], $name);
            self::assertSame($case['code'], $saved['synthesis']['error_code'], $name);
            self::assertSame([], $saved['members'], $name);
            self::assertSame(0, $fakeClient->calls, $name);
            self::assertSame('readback_verified', $saved['persistence_status'], $name);
        }
    }

    public function testCouncilExactReadRejectsTamperedSavedContent(): void
    {
        $question = $this->readyQuestion();
        $service = new OperatingQuestionCouncilService(
            $this->fakeClient(),
            static fn(): array => ['text' => ['ready' => true]],
            static fn(int $questionId, int $tenantId, array $hotelIds): array => $question,
            null,
            $this->strictFactReader($question)
        );
        $saved = $service->runShadow(41, 10, [20], 7, 'tamper-check-1234');
        Db::name(OperatingQuestionCouncilService::TABLE)
            ->where('id', (int)$saved['id'])
            ->update(['members_json' => '[]']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('摘要不一致');
        $service->read((int)$saved['id'], 10, [20]);
    }

    public function testCouncilProductionStrictReaderAcceptsOnlyCurrentSameHotelPlatformAndDateFact(): void
    {
        Db::name('online_daily_data')->insert([
            'id' => 9001,
            'tenant_id' => 10,
            'system_hotel_id' => 20,
            'data_date' => '2026-08-22',
            'platform' => 'ctrip',
            'source' => 'ctrip',
            'data_type' => 'traffic',
            'dimension' => 'hotel_daily',
            'validation_status' => 'verified',
            'history_status' => 'success',
            'readback_verified' => 1,
            'readback_verified_at' => '2026-08-22 10:00:00',
            'ingestion_method' => 'local_browser_profile',
            'source_trace_id' => 'trace-9001',
            'list_exposure' => 1200,
        ]);
        $question = $this->readyQuestion();
        $readyClient = $this->fakeClient();
        $service = new OperatingQuestionCouncilService(
            $readyClient,
            static fn(): array => ['text' => ['ready' => true]],
            static fn(int $questionId, int $tenantId, array $hotelIds): array => $question
        );

        $saved = $service->runShadow(41, 10, [20], 7, 'strict-real-1234');
        self::assertSame('completed', $saved['status']);
        self::assertSame(['online_daily_data#9001'], $saved['evidence_refs']);
        self::assertSame(6, $readyClient->calls);

        Db::name('online_daily_data')->where('id', 9001)->update(['system_hotel_id' => 21]);
        $blockedClient = $this->fakeClient();
        $blockedService = new OperatingQuestionCouncilService(
            $blockedClient,
            static fn(): array => ['text' => ['ready' => true]],
            static fn(int $questionId, int $tenantId, array $hotelIds): array => $question
        );
        $blocked = $blockedService->runShadow(41, 10, [20], 7, 'strict-cross-hotel-1234');
        self::assertSame('blocked_by_missing_facts', $blocked['status']);
        self::assertSame('verified_fact_readback_mismatch', $blocked['synthesis']['error_code']);
        self::assertSame(0, $blockedClient->calls);
    }

    private function fakeClient(): object
    {
        return new class {
            public int $calls = 0;

            /** @param list<array<string,string>> $messages @param array<string,mixed> $schema */
            public function createJsonResponseEnvelope(
                array $messages,
                array $schema,
                string $modelKey = 'local_second_brain'
            ): array {
                $this->calls++;
                $scenario = (string)($schema['x-governance']['scenario'] ?? '');
                $data = $scenario === 'synthesis_chair'
                    ? [
                        'summary' => '证据支持先做小范围人工核对，不支持直接归因。',
                        'agreements' => ['先核对同口径曝光和价格事实。'],
                        'conflicts' => ['客人价值解释仍缺少行为证据。'],
                        'missing_information' => ['缺少竞品与活动成本。'],
                        'falsification_checks' => ['若同口径曝光没有变化，则推翻当前流量假设。'],
                        'recommended_next_step' => '人工复核目标日页面展示与同口径曝光。',
                        'evidence_refs' => ['online_daily_data#9001'],
                    ]
                    : [
                        'assessment' => '当前事实只支持渠道范围内的待验证解释。',
                        'supported_points' => ['已保存携程曝光事实。'],
                        'conflicting_points' => ['缺少竞品与活动成本。'],
                        'risks' => ['不能把相关性写成因果。'],
                        'missing_information' => ['客群与竞品事实。'],
                        'falsification_check' => '复核同口径曝光是否在下一观察期重复出现。',
                        'supporting_evidence_refs' => ['online_daily_data#9001'],
                        'conflicting_evidence_refs' => [],
                        'evidence_refs' => ['online_daily_data#9001'],
                        'confidence' => 'medium',
                    ];
                return [
                    'data' => $data,
                    'meta' => [
                        'provider' => 'ollama',
                        'model_key' => $modelKey,
                        'model' => LocalAiRuntimeService::TEXT_MODEL,
                        'finish_reason' => 'stop',
                        'fallback_used' => false,
                        'cache_hit' => false,
                        'degraded' => false,
                    ],
                ];
            }
        };
    }

    private function strictFactReader(array $question): callable
    {
        return static function (
            int $tenantId,
            int $hotelId,
            string $platform,
            string $dateStart,
            string $dateEnd,
            array $refs
        ) use ($question): array {
            return array_values(array_filter(
                (array)($question['answer']['fact_samples'] ?? []),
                static fn(mixed $fact): bool => is_array($fact)
                    && in_array((string)($fact['ref'] ?? ''), $refs, true)
            ));
        };
    }

    /** @return array<string,mixed> */
    private static function factSample(): array
    {
        return [
            'ref' => 'online_daily_data#9001',
            'platform' => 'ctrip',
            'data_date' => '2026-08-22',
            'data_type' => 'traffic',
            'dimension' => 'hotel_daily',
            'quality_status' => 'verified',
            'history_status' => 'success',
            'readback_status' => 'readback_verified',
            'ingestion_method' => 'local_browser_profile',
            'source_trace_id' => 'trace-9001',
            'metric_values' => ['list_exposure' => 1200],
            'metric_units' => ['list_exposure' => 'exposure_count'],
        ];
    }

    /** @return array<string,mixed> */
    private function readyQuestion(): array
    {
        return [
            'id' => 41,
            'tenant_id' => 10,
            'hotel_id' => 20,
            'question_text' => '携程价格下降后曝光变高，是否应执行降价行动？',
            'platform' => 'ctrip',
            'date_start' => '2026-08-22',
            'date_end' => '2026-08-22',
            'answer_status' => 'answered_by_grounded_ai',
            'answer_summary' => '只确认同酒店携程渠道曝光事实，不确认降价因果。',
            'fact_refs' => ['online_daily_data#9001'],
            'knowledge_refs' => [],
            'memory_refs' => [],
            'execution_refs' => [],
            'data_gaps' => [],
            'answer' => [
                'fact_samples' => [self::factSample()],
                'data_gaps' => [],
                'action_drafts' => [[
                    'title' => '人工复核携程页面展示',
                    'status' => 'ready_for_ai_review',
                ]],
            ],
        ];
    }
}
