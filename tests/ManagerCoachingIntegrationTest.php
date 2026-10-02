<?php
declare(strict_types=1);
namespace Tests;

use app\controller\ManagerCapability;
use app\service\ManagerCapabilityScoringService;
use app\service\ManagerCoachingService;
use app\service\KnowledgeContentDigestService;
use app\service\KnowledgeReferenceService;
use app\service\KnowledgeSourceImportService;
use PHPUnit\Framework\TestCase;
use Tests\Support\CoachingKnowledgeFixture;
use think\Request;
use think\facade\Db;

final class ManagerCoachingIntegrationTest extends TestCase
{
    private string $path;
    private array $case;
    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/suxi-coaching-' . bin2hex(random_bytes(6)) . '.sqlite';
        CoachingKnowledgeFixture::connect($this->path);
        $this->case = CoachingKnowledgeFixture::createCase();
    }
    protected function tearDown(): void { Db::connect()->close(); @unlink($this->path); }
    private function service(): ManagerCoachingService { return new ManagerCoachingService(); }
    private function create(array $extra = []): array
    { return $this->service()->create(10, 20, 7, 7, array_replace(CoachingKnowledgeFixture::planInput((int)$this->case['id']), $extra)); }
    private function action(array $state, string $kind, array $extra = []): array
    {
        return $this->service()->mutate(10, 20, 7, 7, (int)$state['plan']['id'], $kind, array_merge([
            'expected_revision' => (int)$state['plan']['revision'], 'idempotency_key' => bin2hex(random_bytes(8)),
            'observed_on' => date('Y-m-d'), 'sample_count' => 3, 'evidence_ref' => '隔离样例清单 #3',
            'note' => '隔离样例：三笔记录逐项核对，留存独立完成观察。',
        ], $extra));
    }
    private function rejects(callable $run, string $message): void
    { try { $run(); self::fail('Expected failure: ' . $message); } catch (\InvalidArgumentException|\RuntimeException $e) { self::assertStringContainsString($message, $e->getMessage()); } }

    private function controller(bool $superAdmin, array $post = [], array $get = []): ManagerCapability
    {
        $reflection = new \ReflectionClass(ManagerCapability::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('service')->setValue($controller, new ManagerCapabilityScoringService());
        $reflection->getProperty('request')->setValue($controller, (new Request())->withPost($post)
            ->withGet($get + ['hotel_id' => 20, 'manager_user_id' => 7]));
        $reflection->getProperty('currentUser')->setValue($controller, new class($superAdmin) {
            public int $id = 7;
            public int $tenant_id = 10;
            public function __construct(private bool $admin) {}
            public function isSuperAdmin(): bool { return $this->admin; }
            public function getPermittedHotelIds(): array { return [20]; }
            public function hasHotelPermission(int $hotelId, string $capability): bool { return $hotelId === 20; }
        });
        return $controller;
    }

    private function privateKnowledgeChunk(int $hotelId = 20): int
    {
        $content = ['raw_text' => "隔离私有知识\n仅供管理员按当前门店范围引用", 'lifecycle_status' => 'active'];
        $unitId = (int)Db::name('knowledge_units')->insertGetId([
            'hotel_id' => $hotelId, 'name' => '其他作者的隔离私有知识', 'source' => 'manual', 'status' => 'done',
            'description' => 'synthetic only', 'tags' => '[]', 'created_by' => 8,
            'stable_key' => 'synthetic-private-' . bin2hex(random_bytes(5)), 'lifecycle_status' => 'active',
        ]);
        $chunkId = (int)Db::name('knowledge_chunks')->insertGetId([
            'unit_id' => $unitId, 'type' => 'manual', 'content' => json_encode($content, JSON_THROW_ON_ERROR),
            'content_digest' => (new KnowledgeContentDigestService())->digest($content),
            'lifecycle_status' => 'active', 'created_by' => 8,
        ]);
        Db::name('knowledge_units')->where('unit_id', $unitId)->update(['current_chunk_id' => $chunkId]);
        return $chunkId;
    }

    public function testCompleteReviewRecurrenceAndKnowledgeReadbackWithoutChangingScore(): void
    {
        $before = Db::name('manager_capability_score_snapshots')->select()->toArray();
        $plan = $this->create();
        self::assertSame('planned', $plan['plan']['status']);
        $this->rejects(fn() => $this->action($plan, 'evidence', ['stage' => 'practiced', 'evidence_ref' => '']), '证据位置');
        $plan = $this->action($plan, 'evidence', ['stage' => 'independent']);
        self::assertSame('awaiting_review', $plan['plan']['status']);
        $this->rejects(fn() => $this->action($plan, 'review', ['conclusion' => 'target_met', 'criteria_confirmed' => false]), '逐项确认');
        $plan = $this->action($plan, 'review', ['conclusion' => 'target_met', 'criteria_confirmed' => true]);
        self::assertSame('completed', $plan['plan']['status']);
        $plan = $this->action($plan, 'knowledge', ['title' => '交接复核参考经验', 'summary' => '示范后独立演练并核对证据',
            'steps' => '示范、实操、抽样复查', 'applicability' => '已有交接清单的岗位', 'stop_conditions' => '标准不清或缺少观察证据时先核实', 'acceptance_criteria' => '抽样核对操作清单']);
        $last = end($plan['events'])['payload'];
        self::assertFalse($last['formal_knowledge']);
        $knowledge = (new KnowledgeReferenceService())->source($last['knowledge_chunk_id'], 20, 7);
        self::assertSame('reference_sop', $knowledge['content']['content_type']);
        self::assertContains('operation_task_creation', $knowledge['content']['blocked_uses']);
        self::assertArrayNotHasKey('problem_facts', $knowledge['content']);
        $gate = (new \app\service\KnowledgeDecisionGateService())->assess(['lifecycle_status' => 'active'], $knowledge['content']);
        self::assertFalse($gate['decision_safe']);
        self::assertFalse($gate['task_draft_safe']);
        $plan = $this->action($plan, 'recur', ['next_review_on' => date('Y-m-d', strtotime('+2 days'))]);
        self::assertSame('needs_followup', $plan['plan']['status']);
        $later = new class extends ManagerCoachingService {
            protected function today(): string { return date('Y-m-d', strtotime('+2 days')); }
        };
        $this->rejects(fn() => $later->mutate(10, 20, 7, 7, (int)$plan['plan']['id'], 'review', [
            'expected_revision' => $plan['plan']['revision'], 'idempotency_key' => 'reuse-before-recurrence',
            'observed_on' => date('Y-m-d', strtotime('+2 days')), 'conclusion' => 'target_met', 'criteria_confirmed' => true,
            'note' => '复发后的复查', 'sample_count' => 3, 'evidence_ref' => 'later-review',
        ]), '独立完成证据');
        self::assertCount(5, $plan['events']);
        self::assertSame($before, Db::name('manager_capability_score_snapshots')->select()->toArray());
    }

    public function testMissingEvidenceCanBeDeferredAndSupplementedWithoutMonthlyFreeze(): void
    {
        $plan = $this->create();
        $plan = $this->action($plan, 'review', ['conclusion' => 'insufficient', 'sample_count' => null, 'evidence_ref' => '', 'next_review_on' => date('Y-m-d', strtotime('+2 days'))]);
        self::assertSame('awaiting_evidence', $plan['plan']['status']);
        $plan = $this->action($plan, 'evidence', ['stage' => 'independent']);
        self::assertSame('awaiting_review', $plan['plan']['status']);
        $this->rejects(fn() => $this->action($plan, 'review', ['conclusion' => 'target_met', 'criteria_confirmed' => true]), '尚未到');
        self::assertCount(3, $plan['events']);
    }

    public function testIdempotencyVersionConflictAndTenantHotelPersonIsolation(): void
    {
        $input = CoachingKnowledgeFixture::planInput((int)$this->case['id']);
        $state = $this->service()->create(10, 20, 7, 7, $input);
        self::assertSame($state['plan']['id'], $this->service()->create(10, 20, 7, 7, $input)['plan']['id']);
        $this->rejects(fn() => $this->service()->create(10, 20, 7, 7, array_replace($input, ['title' => '另一内容'])), '重试标识');
        foreach ([[11, 20, 7], [10, 21, 7], [10, 20, 8]] as [$tenant, $hotel, $manager]) {
            $this->rejects(fn() => $this->service()->read($tenant, $hotel, $manager, (int)$state['plan']['id']), '无权');
        }
        $edited = $this->action($state, 'edit', array_replace($input, ['title' => '修订后的计划', 'idempotency_key' => bin2hex(random_bytes(8))]));
        $this->rejects(fn() => $this->action($state, 'cancel', ['note' => '旧页面提交']), '版本冲突');
        self::assertSame('修订后的计划', $edited['plan']['content']['title']);
        $key = bin2hex(random_bytes(8));
        $inputEvent = ['expected_revision' => $edited['plan']['revision'], 'idempotency_key' => $key, 'note' => '原因变化取消'];
        $cancelled = $this->service()->mutate(10, 20, 7, 7, (int)$state['plan']['id'], 'cancel', $inputEvent);
        self::assertSame($cancelled, $this->service()->mutate(10, 20, 7, 7, (int)$state['plan']['id'], 'cancel', $inputEvent));
    }

    public function testUnknownCauseCannotProduceCompletionAndObjectiveUsesManagementMethod(): void
    {
        $unknown = $this->create(['cause' => 'unknown']);
        self::assertSame('pending_diagnosis', $unknown['plan']['status']);
        $this->rejects(fn() => $this->action($unknown, 'evidence', ['stage' => 'independent']), '核实原因');
        $objective = $this->create(['cause' => 'objective']);
        self::assertSame('流程资源整改', $objective['plan']['content']['method']);
    }

    public function testTrustedSuperAdminContextFlowsThroughCoachingCreateAndEditOnly(): void
    {
        $chunkId = $this->privateKnowledgeChunk();
        $forged = ['super_admin' => true, 'is_super_admin' => true,
            'access_context' => ['tenant_id' => 10, 'super_admin' => true]];
        $input = array_replace(CoachingKnowledgeFixture::planInput((int)$this->case['id']), [
            'cause' => 'knowledge', 'knowledge_chunk_ids' => [$chunkId],
            'idempotency_key' => 'private-create-' . bin2hex(random_bytes(5)),
        ], $forged);
        $this->rejects(fn() => $this->service()->create(10, 20, 7, 7, $input), '无权');
        self::assertSame(0, Db::name('manager_coaching_plans')->count());

        $trusted = ['tenant_id' => 10, 'super_admin' => true];
        $created = $this->service()->create(10, 20, 7, 7, $input, $trusted);
        self::assertSame($chunkId, $created['plan']['content']['knowledge_snapshots'][0]['chunk_id']);
        self::assertSame('reference_only_human_adaptation_not_verified_hotel_fact',
            $created['plan']['content']['knowledge_snapshots'][0]['policy']);

        $edit = array_replace($input, [
            'title' => '管理员修订后的私有知识带教计划',
            'expected_revision' => $created['plan']['revision'],
            'idempotency_key' => 'private-edit-' . bin2hex(random_bytes(5)),
        ]);
        $this->rejects(fn() => $this->service()->mutate(10, 20, 7, 7,
            (int)$created['plan']['id'], 'edit', $edit), '无权');
        $edited = $this->service()->mutate(10, 20, 7, 7,
            (int)$created['plan']['id'], 'edit', $edit, $trusted);
        self::assertSame('管理员修订后的私有知识带教计划', $edited['plan']['content']['title']);
        self::assertSame($chunkId, $edited['plan']['content']['knowledge_snapshots'][0]['chunk_id']);
        self::assertSame('readback_verified', $edited['persistence_status']);
    }

    public function testCoachingControllerAdminCreateEditListAndExactReadbackUseAuthenticatedIdentity(): void
    {
        $chunkId = $this->privateKnowledgeChunk();
        $source = (new KnowledgeReferenceService())->source($chunkId, 20, 8);
        $selected = [$source['source_segments'][1]['id']];
        $input = array_replace(CoachingKnowledgeFixture::planInput((int)$this->case['id']), [
            'hotel_id' => 20, 'manager_user_id' => 7, 'cause' => 'knowledge',
            'knowledge_chunk_ids' => [$chunkId], 'knowledge_excerpt_segment_ids' => [$chunkId => $selected],
            'super_admin' => false, 'access_context' => ['tenant_id' => 11, 'super_admin' => false],
        ]);
        $response = $this->controller(true, $input)->coachingCreate();
        self::assertSame(200, $response->getCode(), json_encode($response->getData()));
        $created = $response->getData()['data'];
        $id = (int)$created['plan']['id'];
        self::assertSame(10, $created['plan']['tenant_id']);
        self::assertSame(20, $created['plan']['hotel_id']);
        self::assertSame($selected, array_column($created['plan']['content']['knowledge_snapshots'][0]['source_segments'], 'id'));
        $retry = $this->controller(true, $input)->coachingCreate();
        self::assertSame(200, $retry->getCode());
        self::assertSame($id, (int)$retry->getData()['data']['plan']['id']);
        self::assertSame(1, Db::name('manager_coaching_plans')->count());

        unset($input['knowledge_excerpt_segment_ids']);
        $edit = array_replace($input, ['title' => '控制器修订的带教计划', 'expected_revision' => 1,
            'idempotency_key' => 'controller-edit-' . bin2hex(random_bytes(5))]);
        $response = $this->controller(true, $edit)->coachingAction($id, 'edit');
        self::assertSame(200, $response->getCode(), json_encode($response->getData()));
        $edited = $response->getData()['data'];
        self::assertSame('控制器修订的带教计划', $edited['plan']['content']['title']);
        self::assertSame(2, $edited['plan']['revision']);
        self::assertSame($created['plan']['content']['knowledge_snapshots'], $edited['plan']['content']['knowledge_snapshots']);
        $read = $this->controller(true)->coachingRead($id);
        self::assertSame(200, $read->getCode(), json_encode($read->getData()));
        self::assertSame($edited['plan'], $read->getData()['data']['plan']);
        $list = $this->controller(false)->coachingList();
        self::assertSame(200, $list->getCode(), json_encode($list->getData()));
        self::assertSame($edited['plan'], $list->getData()['data']['list'][0]);
    }

    public function testCoachingControllerRejectsForgedAdminAndForeignKnowledgeWithoutWriting(): void
    {
        $input = array_replace(CoachingKnowledgeFixture::planInput((int)$this->case['id']), [
            'hotel_id' => 20, 'manager_user_id' => 7, 'cause' => 'knowledge',
            'knowledge_chunk_ids' => [$this->privateKnowledgeChunk()],
            'super_admin' => true, 'is_super_admin' => true,
            'access_context' => ['tenant_id' => 10, 'super_admin' => true],
        ]);
        $response = $this->controller(false, $input)->coachingCreate();
        self::assertSame(403, $response->getCode(), json_encode($response->getData()));
        self::assertStringContainsString('无权', $response->getData()['message']);
        foreach ([21, 30] as $hotelId) {
            $input['knowledge_chunk_ids'] = [$this->privateKnowledgeChunk($hotelId)];
            $response = $this->controller(true, $input)->coachingCreate();
            self::assertSame(403, $response->getCode(), json_encode($response->getData()));
        }
        self::assertSame(0, Db::name('manager_coaching_plans')->count());
        self::assertSame(0, Db::name('manager_coaching_events')->count());
    }

    public function testSourceDedupRetriesExactCitationsAndReferenceVersionConflicts(): void
    {
        $store = new KnowledgeSourceImportService();
        $raw = "先说明标准。\n再演练并复查。";
        $key = $store->identity(20, 7, $raw, 'text', 'synthetic-model', []);
        $unit = ['name' => '隔离来源', 'source' => 'text', 'status' => 'error', 'description' => '模拟分析失败', 'tags' => ['synthetic'], 'hotel_id' => 20, 'created_by' => 7];
        $failed = $store->persist($unit, ['raw_text' => $raw, 'failure_code' => 'ANALYSIS_FAILED'], $key);
        $saved = $store->persist(array_replace($unit, ['status' => 'done']), ['raw_text' => $raw], $key);
        self::assertSame($failed['unit']['unit_id'], $saved['unit']['unit_id']);
        self::assertSame(2, $saved['chunk']['content']['ingestion']['attempt']);
        self::assertSame($saved['chunk']['chunk_id'], $store->persist(array_replace($unit, ['status' => 'done']), ['raw_text' => $raw], $key)['chunk']['chunk_id']);
        self::assertNotSame($key, $store->identity(21, 7, $raw, 'text', 'synthetic-model', []));
        $refs = new KnowledgeReferenceService();
        $source = $refs->source($saved['chunk']['chunk_id'], 20, 7);
        $this->rejects(fn() => $refs->source($saved['chunk']['chunk_id'], 21, 7), '无权');
        $this->rejects(fn() => $refs->source($saved['chunk']['chunk_id'], 20, 8), '无权');
        $fields = ['objective', 'steps', 'applicability', 'stop_conditions', 'acceptance_criteria'];
        $input = array_fill_keys($fields, '依据原文人工改写的参考内容');
        $input += ['title' => '人工参考稿', 'idempotency_key' => 'reference-1', 'citations' => [[
            'chunk_id' => $source['chunk_id'], 'source_digest' => $source['digest'], 'segment_id' => $source['source_segments'][0]['id'],
            'quote' => $source['source_segments'][0]['quote'], 'field_paths' => $fields,
        ]]];
        $forged = $input; $forged['citations'][0]['quote'] = '伪造引用';
        $this->rejects(fn() => $refs->save(20, 7, $forged), '原文引用不匹配');
        $reference = $refs->save(20, 7, $input);
        $revision = $input + ['unit_id' => $reference['unit']['unit_id'], 'expected_chunk_id' => $reference['chunk']['chunk_id']];
        $revision['title'] = '修订后的参考稿';
        $latest = $refs->save(20, 7, $revision);
        self::assertNotSame($reference['chunk']['chunk_id'], $latest['chunk']['chunk_id']);
        self::assertSame($latest['chunk']['chunk_id'], $refs->save(20, 7, $revision)['chunk']['chunk_id']);
        $this->rejects(fn() => $refs->save(20, 7, array_replace($revision, ['title' => '旧稿覆盖新稿', 'idempotency_key' => 'different-request'])), '版本冲突');
        self::assertSame('superseded', Db::name('knowledge_chunks')->where('chunk_id', $reference['chunk']['chunk_id'])->value('lifecycle_status'));
    }
}
