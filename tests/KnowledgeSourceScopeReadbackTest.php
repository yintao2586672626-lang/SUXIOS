<?php
declare(strict_types=1);
namespace Tests;

use app\service\KnowledgeReferenceService;
use app\service\KnowledgeSourceImportService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\CoachingKnowledgeFixture;
use think\facade\Db;

final class KnowledgeSourceScopeReadbackTest extends TestCase
{
    private string $path;
    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/suxi-knowledge-scope-' . bin2hex(random_bytes(6)) . '.sqlite';
        CoachingKnowledgeFixture::connect($this->path);
    }
    protected function tearDown(): void { Db::connect()->close(); @unlink($this->path); }
    private function source(string $status = 'done'): array
    {
        $store = new KnowledgeSourceImportService();
        $content = ['raw_text' => '隔离来源：说明标准，再演练复查。'];
        $key = $store->identity(20, 7, $content['raw_text'], 'text', 'synthetic-model', []);
        $unit = ['name' => '隔离来源', 'source' => 'text', 'status' => $status, 'description' => '隔离测试',
            'tags' => ['synthetic'], 'hotel_id' => 20, 'created_by' => 7];
        return [$store, $unit, $content, $key, $store->persist($unit, $content, $key)];
    }
    public function testMovingSourceToAnotherHotelCannotReuseTheFormerHotelIdentity(): void
    {
        [$store, , , $key, $saved] = $this->source();
        // The existing knowledge editor allows changing the authorized hotel.
        Db::name('knowledge_units')->where('unit_id', $saved['unit']['unit_id'])->update(['hotel_id' => 21]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('来源范围冲突');
        $store->completed($key);
    }
    public function testFailedImportRetryCannotOverwriteAMovedSource(): void
    {
        [$store, $unit, $content, $key, $saved] = $this->source('error');
        Db::name('knowledge_units')->where('unit_id', $saved['unit']['unit_id'])->update(['hotel_id' => 21]);
        try {
            $store->persist(array_replace($unit, ['status' => 'done']), $content, $key);
            self::fail('A retry must reject the moved source');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('来源范围冲突', $error->getMessage());
        }
        self::assertSame(21, (int)Db::name('knowledge_units')->value('hotel_id'));
        self::assertSame(1, Db::name('knowledge_chunks')->count());
    }
    #[DataProvider('invalidSourceMetadata')]
    public function testCompletedImportRejectsChangedOwnershipOrVersionState(string $table, string $field, mixed $value): void
    {
        [$store, , , $key, $saved] = $this->source();
        $idField = $table === 'knowledge_units' ? 'unit_id' : 'chunk_id';
        Db::name($table)->where($idField, $saved[$table === 'knowledge_units' ? 'unit' : 'chunk'][$idField])->update([$field => $value]);
        $this->expectException(RuntimeException::class);
        $store->completed($key);
    }
    public static function invalidSourceMetadata(): array
    {
        return [
            'unit owner changed' => ['knowledge_units', 'created_by', 8],
            'unit archived' => ['knowledge_units', 'lifecycle_status', 'archived'],
            'chunk superseded' => ['knowledge_chunks', 'lifecycle_status', 'superseded'],
            'chunk owner changed' => ['knowledge_chunks', 'created_by', 8],
        ];
    }
    public function testSourceSaveRollsBackWhenCurrentVersionPointerDoesNotReadBack(): void
    {
        Db::execute('CREATE TRIGGER drift_source_pointer AFTER UPDATE OF current_chunk_id ON knowledge_units
            BEGIN UPDATE knowledge_units SET current_chunk_id = 0 WHERE unit_id = NEW.unit_id; END');
        try { $this->source(); self::fail('Current source version must independently read back'); }
        catch (RuntimeException $error) { self::assertStringContainsString('保存独立回读不一致', $error->getMessage()); }
        self::assertSame(0, Db::name('knowledge_units')->count());
        self::assertSame(0, Db::name('knowledge_chunks')->count());
    }
    public function testSourceSaveRollsBackWhenStoredDigestWasChanged(): void
    {
        Db::execute("CREATE TRIGGER drift_source_digest AFTER INSERT ON knowledge_chunks
            BEGIN UPDATE knowledge_chunks SET content_digest = 'corrupt' WHERE chunk_id = NEW.chunk_id; END");
        try { $this->source(); self::fail('The stored source digest must read back'); }
        catch (RuntimeException $error) { self::assertStringContainsString('保存独立回读不一致', $error->getMessage()); }
        self::assertSame(0, Db::name('knowledge_units')->count());
        self::assertSame(0, Db::name('knowledge_chunks')->count());
    }
    public function testReferenceCannotCiteAnActiveChunkOutsideTheCurrentVersion(): void
    {
        [, , , , $saved] = $this->source();
        Db::name('knowledge_units')->where('unit_id', $saved['unit']['unit_id'])->update(['current_chunk_id' => 999]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('已失效');
        (new KnowledgeReferenceService())->source((int)$saved['chunk']['chunk_id'], 20, 7);
    }
    public function testReferenceRevisionCannotSupersedeAnotherUnitsChunk(): void
    {
        $service = new KnowledgeReferenceService();
        $content = $service->referenceContent('隔离参考', ['objective' => '原始内容'], []);
        $first = $service->persist(20, 7, $content, ['idempotency_key' => 'first-unit']);
        $second = $service->persist(20, 7, $content, ['idempotency_key' => 'second-unit']);
        $other = Db::name('knowledge_chunks')->where('chunk_id', $second['chunk']['chunk_id'])->find();
        Db::name('knowledge_units')->where('unit_id', $first['unit']['unit_id'])
            ->update(['current_chunk_id' => $second['chunk']['chunk_id']]);
        try {
            $service->persist(20, 7, $service->referenceContent('隔离参考', ['objective' => '修改内容'], []),
                ['idempotency_key' => 'revision', 'unit_id' => $first['unit']['unit_id'],
                    'expected_chunk_id' => $second['chunk']['chunk_id']]);
            self::fail('A revision must reject a current chunk owned by another unit');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('范围', $error->getMessage());
        }
        self::assertSame($other, Db::name('knowledge_chunks')->where('chunk_id', $second['chunk']['chunk_id'])->find());
        self::assertSame(2, Db::name('knowledge_chunks')->count());
    }
    public function testFailedImportCannotSupersedeAChunkWithChangedOwner(): void
    {
        [$store, $unit, $content, $key, $saved] = $this->source('error');
        Db::name('knowledge_chunks')->where('chunk_id', $saved['chunk']['chunk_id'])->update(['created_by' => 8]);
        $before = Db::name('knowledge_chunks')->where('chunk_id', $saved['chunk']['chunk_id'])->find();
        try { $store->persist(array_replace($unit, ['status' => 'done']), $content, $key); self::fail('Retry must reject changed chunk owner'); }
        catch (RuntimeException $error) { self::assertStringContainsString('范围', $error->getMessage()); }
        self::assertSame($before, Db::name('knowledge_chunks')->where('chunk_id', $saved['chunk']['chunk_id'])->find());
        self::assertSame(1, Db::name('knowledge_chunks')->count());
    }
    #[DataProvider('referenceWriteDrift')]
    public function testReferenceIndependentReadbackRejectsMetadataAndDigestDrift(string $table, string $field, string $sqlValue): void
    {
        $idField = $table === 'knowledge_units' ? 'unit_id' : 'chunk_id';
        Db::execute('CREATE TRIGGER drift_reference_write AFTER INSERT ON ' . $table .
            ' BEGIN UPDATE ' . $table . ' SET ' . $field . ' = ' . $sqlValue . ' WHERE ' . $idField . ' = NEW.' . $idField . '; END');
        $service = new KnowledgeReferenceService();
        $content = $service->referenceContent('隔离参考', ['objective' => '隔离测试'], []);
        try { $service->persist(20, 7, $content, ['idempotency_key' => 'scope-proof']); self::fail('Reference metadata must read back'); }
        catch (RuntimeException $error) { self::assertStringContainsString('保存回读不一致', $error->getMessage()); }
        self::assertSame(0, Db::name('knowledge_units')->count());
        self::assertSame(0, Db::name('knowledge_chunks')->count());
    }
    public static function referenceWriteDrift(): array
    {
        return [
            'hotel changed' => ['knowledge_units', 'hotel_id', '21'],
            'owner changed' => ['knowledge_units', 'created_by', '8'],
            'unit not done' => ['knowledge_units', 'status', "'error'"],
            'stored digest changed' => ['knowledge_chunks', 'content_digest', "'corrupt'"],
            'chunk owner changed' => ['knowledge_chunks', 'created_by', '8'],
        ];
    }
}
