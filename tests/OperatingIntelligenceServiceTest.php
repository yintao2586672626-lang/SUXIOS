<?php
declare(strict_types=1);

namespace Tests;

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
            . 'platform TEXT, source_scope TEXT, source_record_id INTEGER, business_date TEXT, context_json TEXT, '
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
            . 'readback_verified_at TEXT, validation_status TEXT, history_status TEXT, ingestion_method TEXT, source_trace_id TEXT)'
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
        self::assertCount(1, $ready['question']['fact_refs']);
        self::assertSame('success', $ready['question']['answer']['fact_samples'][0]['history_status']);
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
        self::assertSame('draft_pending_target_validation', $replicated['replication']['status']);
        self::assertSame('facts_available_review_required', $replicated['replication']['target_validation_status']);
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
