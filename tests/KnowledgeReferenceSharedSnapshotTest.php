<?php
declare(strict_types=1);
namespace Tests;

use app\service\KnowledgeContentDigestService;
use app\service\KnowledgeReferenceService;
use app\service\ManagerCoachingService;
use PHPUnit\Framework\TestCase;
use Tests\Support\CoachingKnowledgeFixture;
use think\facade\Db;

final class KnowledgeReferenceSharedSnapshotTest extends TestCase
{
    private string $path;
    private array $case;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/suxi-reference-review-' . bin2hex(random_bytes(6)) . '.sqlite';
        CoachingKnowledgeFixture::connect($this->path);
        $this->case = CoachingKnowledgeFixture::createCase();
    }

    protected function tearDown(): void { Db::connect()->close(); @unlink($this->path); }

    private function seed(string $source = 'manual', int $hotel = 20, int $owner = 7, int $author = 7, ?array $content = null): int
    {
        $content ??= ['raw_text' => "Synthetic approved procedure\nVerify the local handover record", 'lifecycle_status' => 'active'];
        $unit = (int)Db::name('knowledge_units')->insertGetId(['hotel_id' => $hotel, 'created_by' => $owner,
            'name' => 'Synthetic shared procedure', 'source' => $source, 'status' => 'done', 'lifecycle_status' => 'active']);
        if ($source === 'formal_operating_sop') $content += ['formal_record_type' => 'operating_sop',
            'validation_status' => 'human_verified', 'knowledge_unit_id' => $unit, 'operating_sop_version_id' => $unit + 100];
        $chunk = (int)Db::name('knowledge_chunks')->insertGetId(['unit_id' => $unit, 'created_by' => $author,
            'type' => $source, 'content' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'content_digest' => (new KnowledgeContentDigestService())->digest($content), 'lifecycle_status' => 'active',
            'operating_sop_version_id' => $source === 'formal_operating_sop' ? $unit + 100 : null]);
        Db::name('knowledge_units')->where('unit_id', $unit)->update(['current_chunk_id' => $chunk]);
        return $chunk;
    }

    private function rejects(callable $run, string $message): void
    {
        try { $run(); self::fail('Expected source rejection'); }
        catch (\InvalidArgumentException|\RuntimeException $e) { self::assertStringContainsString($message, $e->getMessage()); }
    }

    public function testSameHotelFormalSourceWithDifferentPublisherCanBeSavedAndReadBackAsPrivateReference(): void
    {
        $id = $this->seed('formal_operating_sop', 20, 98, 99);
        $service = new KnowledgeReferenceService();
        $source = $service->source($id, 20, 7);
        $fields = ['objective', 'steps', 'applicability', 'stop_conditions', 'acceptance_criteria'];
        $input = array_fill_keys($fields, 'Synthetic human adaptation from the approved procedure');
        $input += ['title' => 'Synthetic adaptation', 'idempotency_key' => 'shared-procedure-reference', 'citations' => [[
            'chunk_id' => $id, 'source_digest' => $source['digest'], 'segment_id' => $source['source_segments'][0]['id'],
            'quote' => $source['source_segments'][0]['quote'], 'field_paths' => $fields]]];
        $saved = $service->save(20, 7, $input);
        $readback = $service->source((int)$saved['chunk']['chunk_id'], 20, 7);
        self::assertSame($input['citations'][0]['quote'], $readback['content']['citations'][0]['quote']);
        self::assertSame($source['digest'], $readback['content']['citations'][0]['source_digest']);
        self::assertContains('operation_execution', $readback['content']['blocked_uses']);
        $this->rejects(fn() => $service->source((int)$saved['chunk']['chunk_id'], 20, 8), '无权');
        $this->rejects(fn() => $service->persist(20, 7, $service->referenceContent('Cannot edit formal', ['objective' => 'x'], []),
            ['unit_id' => $source['unit_id'], 'expected_chunk_id' => $id, 'idempotency_key' => 'must-not-edit-formal']), '无权');
    }

    public function testPrivateForeignCreatorAndCrossHotelSourcesRemainRejected(): void
    {
        $service = new KnowledgeReferenceService();
        $private = $this->seed();
        $this->rejects(fn() => $service->source($private, 20, 8), '无权');
        $mismatchedAuthor = $this->seed('manual', 20, 7, 98);
        $this->rejects(fn() => $service->source($mismatchedAuthor, 20, 7), '已失效');
        $formal = $this->seed('formal_operating_sop', 21, 98, 99);
        $this->rejects(fn() => $service->source($formal, 20, 7), '无权');
        $this->rejects(fn() => $service->source($private, 21, 7), '无权');
    }

    public function testLegacyApprovedFormalSourceDoesNotRequireNewProjectionMetadataAndRejectsAnotherTenant(): void
    {
        $id = $this->seed('formal_operating_sop', 20, 98, 99);
        $content = ['raw_text' => 'Synthetic legacy approved procedure', 'lifecycle_status' => 'active'];
        Db::name('knowledge_chunks')->where('chunk_id', $id)->update(['content' => json_encode($content),
            'content_digest' => (new KnowledgeContentDigestService())->digest($content), 'type' => 'sop_card', 'operating_sop_version_id' => null]);
        $source = (new KnowledgeReferenceService())->source($id, 20, 7);
        self::assertSame('Synthetic legacy approved procedure', $source['source_segments'][0]['quote']);
        Db::execute('ALTER TABLE knowledge_units ADD COLUMN tenant_id INTEGER');
        Db::execute('UPDATE knowledge_units SET tenant_id = 11 WHERE unit_id = ?', [$source['unit_id']]);
        $this->rejects(fn() => (new KnowledgeReferenceService())->source($id, 20, 7), '无权');
        Db::execute('UPDATE knowledge_units SET tenant_id = 10 WHERE unit_id = ?', [$source['unit_id']]);
        self::assertSame($source['digest'], (new KnowledgeReferenceService())->source($id, 20, 7)['digest']);
    }

    public function testFormalSharingDoesNotAcceptOldRetiredQuarantinedOrTamperedVersions(): void
    {
        $service = new KnowledgeReferenceService();
        foreach (['pointer', 'chunk_lifecycle', 'unit_lifecycle', 'quarantine', 'digest'] as $failure) {
            $id = $this->seed('formal_operating_sop', 20, 98, 99);
            $unit = (int)Db::name('knowledge_chunks')->where('chunk_id', $id)->value('unit_id');
            if ($failure === 'pointer') Db::name('knowledge_units')->where('unit_id', $unit)->update(['current_chunk_id' => $id + 1000]);
            if ($failure === 'chunk_lifecycle') Db::name('knowledge_chunks')->where('chunk_id', $id)->update(['lifecycle_status' => 'superseded']);
            if ($failure === 'unit_lifecycle') Db::name('knowledge_units')->where('unit_id', $unit)->update(['lifecycle_status' => 'stale']);
            if ($failure === 'quarantine') {
                $content = ['raw_text' => 'Synthetic quarantined source', 'entry' => ['disposition' => 'reject_or_quarantine']];
                Db::name('knowledge_chunks')->where('chunk_id', $id)->update(['content' => json_encode($content),
                    'content_digest' => (new KnowledgeContentDigestService())->digest($content)]);
            }
            if ($failure === 'digest') Db::name('knowledge_chunks')->where('chunk_id', $id)->update(['content' => '{"raw_text":"Synthetic tampering"}']);
            $this->rejects(fn() => $service->source($id, 20, 7), match ($failure) {
                'quarantine' => '已隔离', 'digest' => '校验失败', default => '已失效'});
        }
    }

    public function testEightFiveMegabyteSourcesPersistOnlyBoundedVersionedExcerptsOnCreateEditEventsAndList(): void
    {
        $text = str_repeat('原', intdiv(5 * 1024 * 1024, 3));
        $content = ['raw_text' => $text, 'source_document' => ['text_sha256' => hash('sha256', $text)], 'lifecycle_status' => 'active'];
        $ids = [];
        for ($i = 0; $i < 8; $i++) $ids[] = $this->seed(content: $content);
        $service = new ManagerCoachingService();
        $input = array_replace(CoachingKnowledgeFixture::planInput((int)$this->case['id']), ['knowledge_chunk_ids' => $ids]);
        $saved = $service->create(10, 20, 7, 7, $input);
        $snapshots = $saved['plan']['content']['knowledge_snapshots'];
        self::assertCount(8, $snapshots);
        foreach ($snapshots as $index => $snapshot) {
            self::assertSame($ids[$index], $snapshot['chunk_id']);
            self::assertSame((new KnowledgeContentDigestService())->digest($content), $snapshot['digest']);
            self::assertSame(hash('sha256', $text), $snapshot['source_text_sha256']);
            self::assertArrayNotHasKey('content', $snapshot);
            self::assertTrue($snapshot['excerpt_truncated']);
            self::assertLessThanOrEqual(4096, strlen(implode('', array_column($snapshot['source_segments'], 'quote'))));
            self::assertSame(mb_strcut($text, 0, 4096, 'UTF-8'), $snapshot['source_segments'][0]['quote']);
            self::assertSame(hash('sha256', $snapshot['source_segments'][0]['quote']), $snapshot['source_segments'][0]['excerpt_sha256']);
            self::assertStringContainsString('节选', $snapshot['source_segments'][0]['locator']);
        }
        $id = (int)$saved['plan']['id'];
        self::assertLessThan(100000, strlen((string)Db::name('manager_coaching_plans')->where('id', $id)->value('plan_json')));
        self::assertLessThan(100000, strlen((string)Db::name('manager_coaching_events')->where('plan_id', $id)->value('payload_json')));
        self::assertSame($snapshots, $service->read(10, 20, 7, $id)['plan']['content']['knowledge_snapshots']);
        self::assertSame($snapshots, $service->listing(10, 20, 7)['list'][0]['content']['knowledge_snapshots']);
        self::assertSame($snapshots, $saved['events'][0]['payload']['plan']['knowledge_snapshots']);
        $edit = $service->mutate(10, 20, 7, 7, $id, 'edit', array_replace($input,
            ['expected_revision' => 1, 'idempotency_key' => 'bounded-edit', 'title' => 'Synthetic revised bounded plan']));
        self::assertSame($snapshots, $edit['plan']['content']['knowledge_snapshots']);
        self::assertSame($snapshots, $edit['events'][1]['payload']['plan']['knowledge_snapshots']);
        Db::name('knowledge_units')->where('unit_id', $snapshots[0]['unit_id'])->update(['current_chunk_id' => 0]);
        self::assertSame($snapshots, $service->read(10, 20, 7, $id)['plan']['content']['knowledge_snapshots']);
        $this->rejects(fn() => $service->mutate(10, 20, 7, 7, $id, 'edit', array_replace($input,
            ['expected_revision' => 2, 'idempotency_key' => 'reject-obsolete-reference'])), '已失效');
    }

    public function testLegacySavedPlanReadsWithoutRewritingItsStoredSourceSnapshot(): void
    {
        $id = $this->seed();
        $source = (new KnowledgeReferenceService())->source($id, 20, 7);
        $service = new ManagerCoachingService();
        $saved = $service->create(10, 20, 7, 7, CoachingKnowledgeFixture::planInput((int)$this->case['id']));
        $planId = (int)$saved['plan']['id'];
        $legacy = $saved['plan']['content'];
        $legacy['knowledge_snapshots'] = [$source];
        $json = json_encode($legacy, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        Db::name('manager_coaching_plans')->where('id', $planId)->update(['plan_json' => $json,
            'content_digest' => (new KnowledgeContentDigestService())->digest($legacy)]);
        self::assertSame([$source], $service->read(10, 20, 7, $planId)['plan']['content']['knowledge_snapshots']);
        self::assertSame([$source], $service->listing(10, 20, 7)['list'][0]['content']['knowledge_snapshots']);
        self::assertSame($json, Db::name('manager_coaching_plans')->where('id', $planId)->value('plan_json'));
    }

    public function testSnapshotStoresRequestedSegmentsAndRejectsFabricatedSelection(): void
    {
        $id = $this->seed(content: ['raw_text' => "First synthetic line\nSecond synthetic selected line\nThird synthetic line"]);
        $reference = new KnowledgeReferenceService();
        $source = $reference->source($id, 20, 7);
        $selectedId = $source['source_segments'][1]['id'];
        $service = new ManagerCoachingService();
        $input = array_replace(CoachingKnowledgeFixture::planInput((int)$this->case['id']), ['knowledge_chunk_ids' => [$id],
            'knowledge_excerpt_segment_ids' => [$id => [$selectedId]]]);
        $saved = $service->create(10, 20, 7, 7, $input);
        $snapshot = $saved['plan']['content']['knowledge_snapshots'][0];
        self::assertSame($selectedId, $snapshot['source_segments'][0]['id']);
        self::assertSame('Second synthetic selected line', $snapshot['source_segments'][0]['quote']);
        self::assertSame('explicit_segment_selection', $snapshot['excerpt_selection']);
        self::assertSame($snapshot, $saved['events'][0]['payload']['plan']['knowledge_snapshots'][0]);
        $editInput = $input;
        unset($editInput['knowledge_excerpt_segment_ids']);
        $edited = $service->mutate(10, 20, 7, 7, (int)$saved['plan']['id'], 'edit', array_replace($editInput,
            ['expected_revision' => 1, 'idempotency_key' => 'selected-excerpt-edit', 'title' => 'Synthetic title-only edit']));
        self::assertSame($snapshot, $edited['plan']['content']['knowledge_snapshots'][0]);
        self::assertSame($snapshot, $service->read(10, 20, 7, (int)$saved['plan']['id'])['plan']['content']['knowledge_snapshots'][0]);
        self::assertSame($snapshot, $edited['events'][1]['payload']['plan']['knowledge_snapshots'][0]);
        $input['idempotency_key'] = 'fabricated-selection';
        $input['knowledge_excerpt_segment_ids'][$id] = ['s999-fabricated'];
        $this->rejects(fn() => $service->create(10, 20, 7, 7, $input), '摘录片段不存在');
        self::assertSame(1, Db::name('manager_coaching_plans')->count());
    }

    public function testTitleOnlyEditPreservesExplicitSnapshotWhenBudgetOmitsASelectedSegment(): void
    {
        $first = str_repeat('原', 1365) . 'a';
        $id = $this->seed(content: ['raw_text' => $first . "\nSecond selected synthetic line", 'lifecycle_status' => 'active']);
        $source = (new KnowledgeReferenceService())->source($id, 20, 7);
        $selected = array_column($source['source_segments'], 'id');
        $service = new ManagerCoachingService();
        $input = array_replace(CoachingKnowledgeFixture::planInput((int)$this->case['id']), [
            'knowledge_chunk_ids' => [$id], 'knowledge_excerpt_segment_ids' => [$id => $selected],
        ]);
        $saved = $service->create(10, 20, 7, 7, $input);
        $snapshot = $saved['plan']['content']['knowledge_snapshots'][0];
        self::assertCount(2, $selected);
        self::assertCount(1, $snapshot['source_segments']);
        self::assertTrue($snapshot['excerpt_truncated']);
        self::assertSame(4096, strlen($snapshot['source_segments'][0]['quote']));

        unset($input['knowledge_excerpt_segment_ids']);
        $edited = $service->mutate(10, 20, 7, 7, (int)$saved['plan']['id'], 'edit', array_replace($input, [
            'expected_revision' => 1, 'idempotency_key' => 'truncated-title-edit', 'title' => 'Synthetic title-only edit',
        ]));
        self::assertSame($snapshot, $edited['plan']['content']['knowledge_snapshots'][0]);
        self::assertSame($snapshot, $edited['events'][1]['payload']['plan']['knowledge_snapshots'][0]);
        self::assertSame($snapshot, $service->read(10, 20, 7, (int)$saved['plan']['id'])['plan']['content']['knowledge_snapshots'][0]);

        $reselected = $service->mutate(10, 20, 7, 7, (int)$saved['plan']['id'], 'edit', array_replace($input, [
            'expected_revision' => 2, 'idempotency_key' => 'explicit-reselect',
            'knowledge_excerpt_segment_ids' => [$id => [$selected[1]]],
        ]));
        $newSnapshot = $reselected['plan']['content']['knowledge_snapshots'][0];
        self::assertFalse($newSnapshot['excerpt_truncated']);
        self::assertSame([$selected[1]], array_column($newSnapshot['source_segments'], 'id'));
    }
}
