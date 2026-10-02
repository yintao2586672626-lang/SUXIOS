<?php
declare(strict_types=1);
namespace Tests;

use app\service\KnowledgeContentDigestService;
use app\service\KnowledgeReferenceService;
use PHPUnit\Framework\TestCase;
use Tests\Support\CoachingKnowledgeFixture;
use think\facade\Db;

final class MasterPerspectivesReferenceVersionRepairTest extends TestCase
{
    private string $path;
    private string $migration;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/suxi-seed-version-review-' . bin2hex(random_bytes(6)) . '.sqlite';
        CoachingKnowledgeFixture::connect($this->path);
        $this->migration = (string)file_get_contents(dirname(__DIR__) . '/database/migrations/20260930_repair_master_perspectives_reference_current_version.sql');
    }

    protected function tearDown(): void { Db::connect()->close(); @unlink($this->path); }

    private function unit(array $override = []): int
    {
        return (int)Db::name('knowledge_units')->insertGetId(array_replace(['hotel_id' => 0, 'created_by' => 0,
            'name' => '酒店经营多视角审视与反证方法', 'source' => 'revenue_operations_decision_support',
            'status' => 'done', 'lifecycle_status' => 'active', 'current_chunk_id' => 0], $override));
    }

    private function chunk(int $unitId, array $contentOverrides = [], array $rowOverrides = []): int
    {
        $content = array_replace_recursive(['summary' => 'Synthetic reviewed multi-lens reference',
            'seed_owner' => 'suxios.master_perspectives_multi_lens_knowledge', 'seed_version' => '2026-08-20.1',
            'seed_key' => 'hotel_operating_multi_lens_review:workflow', 'lifecycle_status' => 'active',
            'source_manifest' => ['sha256' => '32C06DE45983119EFD6F7CFA9B1E8CA5CE59F8A4E5339267DC383A5FC0EE3970']], $contentOverrides);
        return (int)Db::name('knowledge_chunks')->insertGetId(array_replace(['unit_id' => $unitId, 'type' => 'workflow',
            'created_by' => 0, 'lifecycle_status' => 'active', 'content' => json_encode($content),
            'content_digest' => (new KnowledgeContentDigestService())->digest($content)], $rowOverrides));
    }

    private function migrate(): void
    {
        $pdo = new \PDO('sqlite:' . $this->path);
        // Execute the exact corrective SQL with only MySQL scalar-function spelling adapted.
        $pdo->sqliteCreateFunction('JSON_UNQUOTE', static fn($value) => $value);
        $pdo->sqliteCreateFunction('CONCAT', static fn(...$parts) => implode('', $parts));
        $pdo->exec($this->migration);
    }

    public function testRepairChoosesLatestOriginalActiveSeedThenReadsItsFixedVersionAndIsIdempotent(): void
    {
        $unit = $this->unit();
        $first = $this->chunk($unit);
        $latest = $this->chunk($unit);
        $this->chunk($unit, [], ['lifecycle_status' => 'superseded']);
        $this->chunk($unit, ['seed_version' => 'unreviewed-later-version']);
        $before = Db::name('knowledge_chunks')->select()->toArray();
        try { (new KnowledgeReferenceService())->source($latest, 20, 7); self::fail('Missing current pointer must reject reference'); }
        catch (\InvalidArgumentException $e) { self::assertStringContainsString('已失效', $e->getMessage()); }
        $this->migrate();
        self::assertSame($latest, (int)Db::name('knowledge_units')->where('unit_id', $unit)->value('current_chunk_id'));
        $source = (new KnowledgeReferenceService())->source($latest, 20, 7);
        self::assertSame('Synthetic reviewed multi-lens reference', $source['source_segments'][0]['quote']);
        self::assertSame('reference_only_human_adaptation_not_verified_hotel_fact', $source['policy']);
        self::assertSame($source['digest'], (new KnowledgeReferenceService())->coachingSnapshot($latest, 20, 7)['digest']);
        self::assertSame($before, Db::name('knowledge_chunks')->select()->toArray());
        $after = Db::name('knowledge_units')->select()->toArray();
        $this->migrate();
        self::assertSame($after, Db::name('knowledge_units')->select()->toArray());
        try { (new KnowledgeReferenceService())->source($first, 20, 7); self::fail('Older chunk must remain unavailable'); }
        catch (\InvalidArgumentException $e) { self::assertStringContainsString('已失效', $e->getMessage()); }
    }

    public function testExistingPointerPrivateForeignSeedVersionDigestOriginAndLifecycleAreNeverPromoted(): void
    {
        foreach ([['current_chunk_id' => 912], ['hotel_id' => 20], ['created_by' => 7], ['status' => 'pending'],
            ['lifecycle_status' => 'stale'], ['source' => 'manual'], ['name' => 'Different knowledge']] as $override) {
            $unit = $this->unit($override);
            $this->chunk($unit);
        }
        foreach ([['seed_owner' => 'unrelated'], ['seed_version' => '2026-08-21.1'], ['seed_key' => 'other:workflow'],
            ['source_manifest' => ['sha256' => str_repeat('0', 64)]], ['lifecycle_status' => 'stale'],
            ['entry' => ['disposition' => 'reject_or_quarantine']]] as $override) $this->chunk($this->unit(), $override);
        $this->chunk($this->unit(), [], ['created_by' => 7]);
        $this->chunk($this->unit(), [], ['lifecycle_status' => 'retired']);
        $this->chunk($this->unit(), [], ['content' => '{invalid-json']);
        $this->unit();
        $beforeUnits = Db::name('knowledge_units')->select()->toArray();
        $beforeChunks = Db::name('knowledge_chunks')->select()->toArray();
        $this->migrate();
        self::assertSame($beforeUnits, Db::name('knowledge_units')->select()->toArray());
        self::assertSame($beforeChunks, Db::name('knowledge_chunks')->select()->toArray());
        self::assertStringNotContainsString('UPDATE `knowledge_chunks`', $this->migration);
        self::assertStringNotContainsString('INSERT INTO', $this->migration);
    }

    public function testRepairNeverRehashesOrMakesTamperedSourceTrusted(): void
    {
        $unit = $this->unit();
        $chunk = $this->chunk($unit);
        $row = Db::name('knowledge_chunks')->where('chunk_id', $chunk)->find();
        $content = json_decode($row['content'], true, 512, JSON_THROW_ON_ERROR);
        $content['summary'] = 'Synthetic unauthorized change after storage';
        Db::name('knowledge_chunks')->where('chunk_id', $chunk)->update(['content' => json_encode($content)]);
        $this->migrate();
        self::assertSame($row['content_digest'], Db::name('knowledge_chunks')->where('chunk_id', $chunk)->value('content_digest'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('校验失败');
        (new KnowledgeReferenceService())->source($chunk, 20, 7);
    }
}
