<?php
declare(strict_types=1);

namespace Tests;

use app\service\KnowledgePromotionService;
use app\service\KnowledgeSopExecutionProvenanceService;
use app\service\OperatingSopService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class KnowledgePromotionServiceTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App(dirname(__DIR__));
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'knowledge_promotion_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';
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
            'knowledge_promotion_events',
            'knowledge_candidate_revisions',
            'knowledge_candidates',
            'knowledge_chunks',
            'knowledge_units',
            'hotel_operating_sop_replications',
            'hotel_operating_sop_versions',
            'hotel_operating_memories',
            'hotels',
        ] as $table) {
            Db::execute('DROP TABLE IF EXISTS ' . $table);
        }
        $this->createSchema();
    }

    public function testCreatesFormalCandidateWithExactSourceReadbackAndIdempotentReplay(): void
    {
        $memoryIds = $this->insertVerifiedMemories(1);
        $source = $this->createSopCandidate([$memoryIds[0]]);
        $service = new KnowledgePromotionService();

        $created = $service->createFromSopCandidate(
            (int)$source['version']['id'],
            10,
            [20],
            7,
            'create-formal-20-1'
        );
        self::assertTrue($created['created']);
        self::assertSame('readback_verified', $created['persistence_status']);
        self::assertSame('draft', $created['candidate']['workflow_status']);
        self::assertSame('candidate_created', $created['event']['event_type']);
        self::assertSame(1, $created['candidate']['current_revision_no']);
        self::assertSame((int)$source['version']['id'], $created['candidate']['current_revision']['source_sop_candidate_version_id']);
        self::assertSame([$memoryIds[0]], $created['candidate']['current_revision']['scope']['source_memory_ids']);
        self::assertSame('ctrip', $created['candidate']['current_revision']['applicability']['platform']);
        self::assertSame('2026-08-01', $created['candidate']['current_revision']['applicability']['evidence_date_start']);
        self::assertSame('2026-08-01', $created['candidate']['current_revision']['applicability']['evidence_date_end']);
        self::assertFalse($created['candidate']['current_revision']['scope']['causality_verified']);
        self::assertFalse($created['write_boundaries']['runtime_json_is_formal_source']);
        self::assertSame(0, Db::name('knowledge_units')->count());

        $replayed = $service->createFromSopCandidate(
            (int)$source['version']['id'],
            10,
            [20],
            7,
            'different-key-still-same-source'
        );
        self::assertFalse($replayed['created']);
        self::assertSame($created['candidate']['id'], $replayed['candidate']['id']);
        self::assertSame(1, Db::name('knowledge_candidates')->count());
        self::assertSame(1, Db::name('knowledge_candidate_revisions')->count());
        self::assertSame(1, Db::name('knowledge_promotion_events')->count());
    }

    public function testRejectsSourceCandidateThatIsMissingFormalIdentityEvidence(): void
    {
        $now = date('Y-m-d H:i:s');
        $sourceId = (int)Db::name('hotel_operating_sop_versions')->insertGetId([
            'tenant_id' => 10,
            'hotel_id' => 20,
            'sop_key' => 'malformed-source',
            'version_no' => 1,
            'previous_version_id' => null,
            'title' => 'Malformed source',
            'objective' => '',
            'steps_json' => '["step"]',
            'stop_conditions_json' => '[]',
            'scope_json' => '{}',
            'source_memory_ids_json' => '[]',
            'evidence_refs_json' => '[]',
            'validation_status' => 'candidate',
            'validation_note' => '',
            'content_digest' => str_repeat('a', 64),
            'lifecycle_status' => 'active',
            'created_by' => 7,
            'validated_by' => 0,
            'validated_at' => null,
            'retired_by' => null,
            'retired_at' => null,
            'replacement_version_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('缺少平台、日期、来源记忆、证据或内容摘要');
        (new KnowledgePromotionService())->createFromSopCandidate($sourceId, 10, [20], 7, 'bad-source');
    }

    public function testSubmitRequestChangesAndNewRevisionAreAuditedWithoutKnowledgeWrite(): void
    {
        $memoryIds = $this->insertVerifiedMemories(1);
        $source = $this->createSopCandidate([$memoryIds[0]]);
        $service = new KnowledgePromotionService();
        $created = $service->createFromSopCandidate((int)$source['version']['id'], 10, [20], 7, 'create');
        $candidateId = (int)$created['candidate']['id'];
        $originalRevision = Db::name('knowledge_candidate_revisions')->where('candidate_id', $candidateId)->find();

        $submitted = $service->submit($candidateId, 10, [20], [
            'note' => 'Please review the persisted source evidence.',
            'assigned_reviewer_id' => 8,
            'review_due_at' => '2026-08-04 10:00:00',
            'idempotency_key' => 'submit-r1',
        ], 7);
        self::assertSame('in_review', $submitted['candidate']['workflow_status']);
        self::assertSame(8, $submitted['candidate']['assigned_reviewer_id']);
        self::assertSame('submitted', $submitted['event']['event_type']);
        self::assertSame(7, $submitted['candidate']['current_revision']['submitted_by']);
        self::assertNotSame('', $submitted['candidate']['current_revision']['submitted_at']);
        self::assertSame(0, Db::name('knowledge_units')->count());
        $submittedRevision = Db::name('knowledge_candidate_revisions')
            ->where('id', (int)$originalRevision['id'])
            ->find();

        $changes = $service->review($candidateId, 10, [20], [
            'decision' => 'request_changes',
            'note' => 'Clarify the final evidence-recording step.',
            'idempotency_key' => 'changes-r1',
        ], 8);
        self::assertSame('changes_requested', $changes['candidate']['workflow_status']);
        self::assertSame('changes_requested', $changes['event']['event_type']);
        self::assertSame(0, Db::name('knowledge_units')->count());

        $revised = $service->createRevision($candidateId, 10, [20], [
            'objective' => 'Review exact Ctrip facts, decide manually, and save the outcome evidence.',
            'steps' => ['Read exact facts', 'Record the human decision', 'Save outcome evidence'],
            'note' => 'Added an explicit evidence save step.',
            'idempotency_key' => 'revision-r2',
        ], 7);
        self::assertTrue($revised['created']);
        self::assertSame('draft', $revised['candidate']['workflow_status']);
        self::assertSame(2, $revised['candidate']['current_revision_no']);
        self::assertSame('revision_created', $revised['event']['event_type']);
        self::assertSame('Save outcome evidence', $revised['candidate']['current_revision']['steps'][2]);
        self::assertNotSame(
            (int)$source['version']['id'],
            $revised['candidate']['current_revision']['source_sop_candidate_version_id']
        );
        self::assertSame(2, Db::name('knowledge_candidate_revisions')->count());
        self::assertSame(4, Db::name('knowledge_promotion_events')->count());
        self::assertSame(0, Db::name('knowledge_units')->count());

        $originalReadback = Db::name('knowledge_candidate_revisions')->where('id', (int)$originalRevision['id'])->find();
        self::assertSame($submittedRevision, $originalReadback, 'A submitted revision must never be rewritten by a later revision.');

        try {
            $service->createRevision($candidateId, 10, [20], [
                'platform' => 'meituan',
                'steps' => ['Changed'],
            ], 7);
            self::fail('A caller must not rewrite platform identity in a content revision.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('不能改写来源身份字段', $e->getMessage());
        }
    }

    public function testInsufficientEvidenceApprovalRollsBackWithoutProjectionOrFalseEvent(): void
    {
        $memoryIds = $this->insertVerifiedMemories(1);
        $source = $this->createSopCandidate([$memoryIds[0]]);
        $service = new KnowledgePromotionService();
        $created = $service->createFromSopCandidate((int)$source['version']['id'], 10, [20], 7, 'create');
        $candidateId = (int)$created['candidate']['id'];
        $service->submit($candidateId, 10, [20], ['idempotency_key' => 'submit'], 7);
        $eventsBefore = (int)Db::name('knowledge_promotion_events')->count();
        $versionsBefore = (int)Db::name('hotel_operating_sop_versions')->count();

        try {
            $service->review($candidateId, 10, [20], [
                'decision' => 'approve',
                'note' => 'Attempt approval with only one source memory.',
                'evidence_memory_ids' => [$memoryIds[0]],
                'idempotency_key' => 'approve-too-early',
            ], 8);
            self::fail('Insufficient evidence must not approve a formal SOP.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('至少需要3条', $e->getMessage());
        }

        $readback = $service->readCandidate($candidateId, 10, [20]);
        self::assertSame('in_review', $readback['workflow_status']);
        self::assertSame($eventsBefore, Db::name('knowledge_promotion_events')->count());
        self::assertSame($versionsBefore, Db::name('hotel_operating_sop_versions')->count());
        self::assertSame(0, Db::name('knowledge_units')->count());
        self::assertSame(0, Db::name('knowledge_chunks')->count());
        self::assertSame(0, Db::name('knowledge_promotion_events')->where('event_type', 'approved')->count());
        self::assertSame(0, Db::name('hotel_operating_sop_versions')->where('validation_status', 'verified')->count());
    }

    public function testSuccessfulApprovalIsAtomicAndProjectsVersionedKnowledgeWithExactDigests(): void
    {
        $memoryIds = $this->insertVerifiedMemories(3);
        $source = $this->createSopCandidate([$memoryIds[0]]);
        $service = new KnowledgePromotionService();
        $created = $service->createFromSopCandidate((int)$source['version']['id'], 10, [20], 7, 'create-success');
        $candidateId = (int)$created['candidate']['id'];
        $service->submit($candidateId, 10, [20], [
            'note' => 'Submit the persisted evidence for human review.',
            'idempotency_key' => 'submit-success',
        ], 7);

        $approved = $service->review($candidateId, 10, [20], [
            'decision' => 'approve',
            'note' => 'Three independent tasks across two business dates were reviewed.',
            'evidence_memory_ids' => $memoryIds,
            'idempotency_key' => 'approve-success',
        ], 8);
        self::assertSame('approved', $approved['candidate']['workflow_status']);
        self::assertSame('approved', $approved['event']['event_type']);
        self::assertSame('verified', $approved['promoted_sop_version']['validation_status']);
        self::assertCount(3, $approved['promoted_sop_version']['source_memory_ids']);
        self::assertSame('readback_verified', $approved['knowledge_projection']['persistence_status']);
        self::assertGreaterThan(0, $approved['candidate']['promoted_sop_version_id']);
        self::assertGreaterThan(0, $approved['candidate']['promoted_knowledge_unit_id']);
        self::assertGreaterThan(0, $approved['candidate']['promoted_knowledge_chunk_id']);

        $unit = $approved['knowledge_projection']['knowledge_unit'];
        $chunk = $approved['knowledge_projection']['knowledge_chunk'];
        self::assertSame(20, $unit['hotel_id']);
        self::assertSame('done', $unit['status']);
        self::assertSame('active', $unit['lifecycle_status']);
        self::assertNotSame('', $unit['reviewed_at']);
        self::assertNotSame('', $unit['review_due_at']);
        self::assertSame($chunk['chunk_id'], $unit['current_chunk_id']);
        self::assertSame('active', $chunk['lifecycle_status']);
        self::assertSame('sop_card', $chunk['content']['content_type']);
        self::assertSame('hotel_specific_verified_execution_review', $chunk['content']['scope']);
        self::assertSame(['ctrip'], $chunk['content']['platforms']);
        self::assertSame('B', $chunk['content']['evidence_grade']);
        self::assertSame('active', $chunk['content']['lifecycle_status']);
        self::assertSame('human_verified', $chunk['content']['validation_status']);
        self::assertFalse($chunk['content']['causality_verified']);
        self::assertFalse($chunk['content']['boundaries']['automatic_execution']);
        self::assertFalse($chunk['content']['boundaries']['ota_write']);
        self::assertFalse($chunk['content']['boundaries']['external_message']);
        self::assertSame('approved', $approved['knowledge_projection']['runtime_authority']['knowledge_gate']['status']);
        self::assertTrue($approved['knowledge_projection']['runtime_authority']['knowledge_gate']['task_draft_safe']);
        self::assertNotSame('', $approved['knowledge_projection']['runtime_authority']['formal_authority_digest']);
        self::assertSame(3, Db::name('knowledge_promotion_events')->count());

        $replayed = $service->review($candidateId, 10, [20], [
            'decision' => 'approve',
            'note' => 'Three independent tasks across two business dates were reviewed.',
            'evidence_memory_ids' => $memoryIds,
            'idempotency_key' => 'approve-success',
        ], 8);
        self::assertFalse($replayed['created']);
        self::assertSame('approval_replayed', $replayed['operation_status']);
        self::assertSame(1, Db::name('knowledge_units')->count());
        self::assertSame(1, Db::name('knowledge_chunks')->count());
        self::assertSame(3, Db::name('knowledge_promotion_events')->count());

        $withdrawn = $service->withdraw($candidateId, 10, [20], [
            'note' => 'Disable this formal version after human review.',
            'idempotency_key' => 'retire-success',
        ], 8);
        self::assertSame('withdrawn', $withdrawn['candidate']['workflow_status']);
        self::assertSame('retired', Db::name('hotel_operating_sop_versions')
            ->where('id', (int)$approved['candidate']['promoted_sop_version_id'])->value('lifecycle_status'));
        self::assertSame('retired', Db::name('knowledge_chunks')
            ->where('chunk_id', (int)$chunk['chunk_id'])->value('lifecycle_status'));
        self::assertNull(Db::name('knowledge_units')->where('unit_id', (int)$unit['unit_id'])->value('current_chunk_id'));
        self::assertSame('stale', Db::name('knowledge_units')
            ->where('unit_id', (int)$unit['unit_id'])->value('lifecycle_status'));
        self::assertSame('formal_version_withdrawn_no_current_chunk', Db::name('knowledge_units')
            ->where('unit_id', (int)$unit['unit_id'])->value('lifecycle_reason'));
        $retiredUnit = Db::name('knowledge_units')->where('unit_id', (int)$unit['unit_id'])->find();
        $retiredChunk = Db::name('knowledge_chunks')->where('chunk_id', (int)$chunk['chunk_id'])->find();
        try {
            (new KnowledgeSopExecutionProvenanceService())->validateSnapshot(
                is_array($retiredUnit) ? $retiredUnit : [],
                is_array($retiredChunk) ? $retiredChunk : [],
                20,
                'ctrip'
            );
            self::fail('A retired formal knowledge version must not create a new operation intent.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('current active', $exception->getMessage());
        }
    }

    public function testListDetailAndEventsRejectCrossHotelReadsAndEventsRemainAppendOnly(): void
    {
        $memoryIds = $this->insertVerifiedMemories(1);
        $source = $this->createSopCandidate([$memoryIds[0]]);
        $service = new KnowledgePromotionService();
        $created = $service->createFromSopCandidate((int)$source['version']['id'], 10, [20], 7, 'create-cross');
        $candidateId = (int)$created['candidate']['id'];
        $service->submit($candidateId, 10, [20], ['idempotency_key' => 'submit-cross'], 7);

        $eventsBefore = Db::name('knowledge_promotion_events')->order('id', 'asc')->select()->toArray();
        $events = $service->listEvents($candidateId, 10, [20]);
        self::assertTrue($events['append_only']);
        self::assertSame(['candidate_created', 'submitted'], array_column($events['list'], 'event_type'));
        self::assertSame($eventsBefore, Db::name('knowledge_promotion_events')->order('id', 'asc')->select()->toArray());

        $otherHotelList = $service->listCandidates(10, [21], 21);
        self::assertSame([], $otherHotelList['list']);
        try {
            $service->readCandidate($candidateId, 10, [21]);
            self::fail('Cross-hotel candidate read must be rejected.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('not found', $e->getMessage());
        }
        try {
            $service->listEvents($candidateId, 10, [21]);
            self::fail('Cross-hotel event read must be rejected.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('not found', $e->getMessage());
        }
    }

    public function testApprovedProjectionTamperIsRejectedOnIndependentReadAndRuntimeUse(): void
    {
        $memoryIds = $this->insertVerifiedMemories(3);
        $source = $this->createSopCandidate([$memoryIds[0]]);
        $service = new KnowledgePromotionService();
        $created = $service->createFromSopCandidate((int)$source['version']['id'], 10, [20], 7, 'tamper-create');
        $candidateId = (int)$created['candidate']['id'];
        $service->submit($candidateId, 10, [20], ['idempotency_key' => 'tamper-submit'], 7);
        $approved = $service->review($candidateId, 10, [20], [
            'decision' => 'approve',
            'note' => 'Approve before the independent tamper check.',
            'evidence_memory_ids' => $memoryIds,
            'idempotency_key' => 'tamper-approve',
        ], 8);
        $chunkId = (int)$approved['candidate']['promoted_knowledge_chunk_id'];
        $chunk = Db::name('knowledge_chunks')->where('chunk_id', $chunkId)->find();
        $content = json_decode((string)($chunk['content'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        $content['title'] = 'tampered after approval';
        Db::name('knowledge_chunks')->where('chunk_id', $chunkId)->update([
            'content' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);

        try {
            $service->readCandidate($candidateId, 10, [20]);
            self::fail('A tampered formal knowledge projection must fail independent readback.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('content digest mismatch', $exception->getMessage());
        }

        $unit = Db::name('knowledge_units')
            ->where('unit_id', (int)$approved['candidate']['promoted_knowledge_unit_id'])
            ->find();
        $tamperedChunk = Db::name('knowledge_chunks')->where('chunk_id', $chunkId)->find();
        try {
            (new KnowledgeSopExecutionProvenanceService())->validateSnapshot(
                is_array($unit) ? $unit : [],
                is_array($tamperedChunk) ? $tamperedChunk : [],
                20,
                'ctrip'
            );
            self::fail('A tampered formal knowledge projection must not create an operation intent.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('current active verified content', $exception->getMessage());
        }
    }

    /** @return list<int> */
    private function insertVerifiedMemories(int $count): array
    {
        $definitions = [
            [101, '2026-08-01'],
            [102, '2026-08-02'],
            [103, '2026-08-02'],
        ];
        $ids = [];
        foreach (array_slice($definitions, 0, $count) as [$taskId, $businessDate]) {
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

    /** @param list<int> $memoryIds @return array<string,mixed> */
    private function createSopCandidate(array $memoryIds): array
    {
        return (new OperatingSopService())->createCandidate(10, 20, $memoryIds, [
            'title' => 'Ctrip traffic review SOP',
            'objective' => 'Review persisted Ctrip traffic facts before a human decision.',
            'steps' => ['Read exact facts', 'Record the human decision'],
            'stop_conditions' => ['Stop when source facts are missing'],
            'applicable_data_types' => ['traffic'],
            'metric_definitions' => ['Traffic facts from the exact saved readback scope'],
        ], 7);
    }

    private function createSchema(): void
    {
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT, status INTEGER NOT NULL)');
        Db::execute("INSERT INTO hotels (id,tenant_id,name,status) VALUES (20,10,'source',1),(21,10,'other',1),(30,11,'other tenant',1)");
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
            . 'lifecycle_status TEXT, created_by INTEGER, validated_by INTEGER, validated_at TEXT, retired_by INTEGER, retired_at TEXT, '
            . 'replacement_version_id INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT, '
            . 'UNIQUE(tenant_id,hotel_id,sop_key,version_no))'
        );
        Db::execute(
            'CREATE TABLE hotel_operating_sop_replications ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, source_sop_version_id INTEGER, source_hotel_id INTEGER, '
            . 'target_hotel_id INTEGER, status TEXT, target_validation_status TEXT, draft_json TEXT, target_fact_refs_json TEXT, '
            . 'data_gaps_json TEXT, content_digest TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT)'
        );
        Db::execute(
            'CREATE TABLE knowledge_units ('
            . 'unit_id INTEGER PRIMARY KEY AUTOINCREMENT, hotel_id INTEGER NOT NULL DEFAULT 0, stable_key TEXT UNIQUE, '
            . 'current_chunk_id INTEGER, name TEXT NOT NULL, source TEXT, status TEXT NOT NULL DEFAULT "pending", '
            . 'lifecycle_status TEXT NOT NULL DEFAULT "active", lifecycle_reason TEXT, reviewed_at TEXT, review_due_at TEXT, '
            . 'description TEXT, tags TEXT, created_by INTEGER NOT NULL DEFAULT 0, created_at TEXT, updated_at TEXT)'
        );
        Db::execute(
            'CREATE TABLE knowledge_chunks ('
            . 'chunk_id INTEGER PRIMARY KEY AUTOINCREMENT, unit_id INTEGER NOT NULL, promotion_candidate_id INTEGER, '
            . 'operating_sop_version_id INTEGER UNIQUE, version_no INTEGER, lifecycle_status TEXT, content_digest TEXT, '
            . 'superseded_by_chunk_id INTEGER, published_at TEXT, retired_at TEXT, type TEXT, content TEXT, '
            . 'created_by INTEGER NOT NULL DEFAULT 0, created_at TEXT)'
        );
        Db::execute(
            'CREATE TABLE knowledge_candidates ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, candidate_key TEXT NOT NULL, '
            . 'candidate_type TEXT NOT NULL, source_record_type TEXT NOT NULL, source_record_id INTEGER NOT NULL, source_stage TEXT NOT NULL, '
            . 'current_revision_id INTEGER, current_revision_no INTEGER NOT NULL DEFAULT 0, workflow_status TEXT NOT NULL, '
            . 'assigned_reviewer_id INTEGER, review_due_at TEXT, promoted_sop_version_id INTEGER, promoted_knowledge_unit_id INTEGER, '
            . 'promoted_knowledge_chunk_id INTEGER, row_version INTEGER NOT NULL DEFAULT 1, created_by INTEGER NOT NULL, '
            . 'created_at TEXT, updated_at TEXT, deleted_at TEXT, UNIQUE(tenant_id,hotel_id,candidate_key), '
            . 'UNIQUE(tenant_id,hotel_id,source_record_type,source_record_id))'
        );
        Db::execute(
            'CREATE TABLE knowledge_candidate_revisions ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, candidate_id INTEGER NOT NULL, revision_no INTEGER NOT NULL, '
            . 'source_sop_candidate_version_id INTEGER NOT NULL, title TEXT NOT NULL, objective TEXT NOT NULL, steps_json TEXT NOT NULL, '
            . 'stop_conditions_json TEXT, applicability_json TEXT NOT NULL, scope_json TEXT NOT NULL, evidence_refs_json TEXT NOT NULL, '
            . 'outcome_refs_json TEXT, conflict_refs_json TEXT, source_digest TEXT NOT NULL, content_digest TEXT NOT NULL, '
            . 'created_by INTEGER NOT NULL, created_at TEXT, submitted_by INTEGER, submitted_at TEXT, '
            . 'UNIQUE(candidate_id,revision_no), UNIQUE(candidate_id,content_digest))'
        );
        Db::execute(
            'CREATE TABLE knowledge_promotion_events ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL, candidate_id INTEGER NOT NULL, '
            . 'revision_id INTEGER, event_type TEXT NOT NULL, from_status TEXT NOT NULL, to_status TEXT NOT NULL, actor_id INTEGER NOT NULL, '
            . 'note TEXT NOT NULL, payload_json TEXT, idempotency_key TEXT NOT NULL UNIQUE, created_at TEXT)'
        );
    }
}
