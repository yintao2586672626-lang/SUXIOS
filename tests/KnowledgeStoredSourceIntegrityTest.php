<?php
declare(strict_types=1);

namespace Tests;

use app\service\KnowledgeContentDigestService;
use app\service\KnowledgeReferenceService;
use app\service\KnowledgeSourceImportService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\facade\Config;
use think\facade\Db;

final class KnowledgeStoredSourceIntegrityTest extends TestCase
{
    private array $database;
    private string $connection;

    protected function setUp(): void
    {
        (new \think\App(dirname(__DIR__)))->initialize();
        restore_error_handler();
        restore_exception_handler();
        $this->database = Config::get('database', []);
        $this->connection = 'knowledge_source_synthetic_' . bin2hex(random_bytes(6));
        Config::set(['default' => $this->connection, 'connections' => [$this->connection => [
            'type' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'fields_strict' => false,
        ]]], 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER)');
        Db::execute('INSERT INTO hotels VALUES (80, 1)');
        Db::execute('CREATE TABLE knowledge_units (unit_id INTEGER PRIMARY KEY AUTOINCREMENT, hotel_id INTEGER, created_by INTEGER, name TEXT, source TEXT, status TEXT, description TEXT, tags TEXT, stable_key TEXT, current_chunk_id INTEGER, lifecycle_status TEXT, created_at TEXT, updated_at TEXT)');
        Db::execute('CREATE TABLE knowledge_chunks (chunk_id INTEGER PRIMARY KEY AUTOINCREMENT, unit_id INTEGER, type TEXT, content TEXT, content_digest TEXT, lifecycle_status TEXT, created_by INTEGER, created_at TEXT)');
    }

    protected function tearDown(): void
    {
        Db::connect($this->connection)->close();
        Config::set($this->database, 'database');
        Db::connect(null, true);
    }

    public function testReferenceRejectsExplicitStoredDigestMismatch(): void
    {
        $this->seedReference();
        Db::name('knowledge_chunks')->where('chunk_id', 1)->update(['content' => json_encode(['raw_text' => 'Changed source after storage'])]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('知识来源内容校验失败');
        (new KnowledgeReferenceService())->source(1, 80, 7);
    }

    public function testReferenceRetainsLegacySourceWithoutStoredDigestAndOwnerIsolation(): void
    {
        $this->seedReference();
        Db::name('knowledge_chunks')->where('chunk_id', 1)->update(['content_digest' => null]);
        $source = (new KnowledgeReferenceService())->source(1, 80, 7);
        self::assertSame('Original synthetic source', $source['source_segments'][0]['quote']);
        self::assertSame('reference_only_human_adaptation_not_verified_hotel_fact', $source['policy']);
        $this->expectException(RuntimeException::class);
        (new KnowledgeReferenceService())->source(1, 80, 8);
    }

    public function testReferenceRejectsQuarantinedSourceEvenWithValidDigest(): void
    {
        $this->seedReference();
        $content = ['raw_text' => 'Quarantined synthetic source', 'entry' => ['disposition' => 'reject_or_quarantine']];
        Db::name('knowledge_chunks')->where('chunk_id', 1)->update([
            'content' => json_encode($content), 'content_digest' => (new KnowledgeContentDigestService())->digest($content),
        ]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('该来源已隔离');
        (new KnowledgeReferenceService())->source(1, 80, 7);
    }

    public function testReferenceRevisionRetryReadsSameVersionButDifferentRequestStillConflicts(): void
    {
        $service = new KnowledgeReferenceService();
        $content = $service->referenceContent('Synthetic reference', ['objective' => 'Original objective'], []);
        $first = $service->persist(80, 7, $content, ['idempotency_key' => 'create-reference']);
        $revisedContent = $service->referenceContent('Synthetic reference', ['objective' => 'Revised objective'], []);
        $request = ['idempotency_key' => 'revise-reference', 'unit_id' => $first['unit']['unit_id'],
            'expected_chunk_id' => $first['chunk']['chunk_id']];
        $revised = $service->persist(80, 7, $revisedContent, $request);
        self::assertSame($revised, $service->persist(80, 7, $revisedContent, $request));
        self::assertSame(2, Db::name('knowledge_chunks')->count());
        self::assertSame('superseded', Db::name('knowledge_chunks')->where('chunk_id', $first['chunk']['chunk_id'])->value('lifecycle_status'));
        self::assertSame('目标', $revised['chunk']['content']['fields'][0]['label']);
        self::assertSame('Revised objective', $revised['chunk']['content']['reference_fields']['objective']);
        foreach ([
            [$revisedContent, array_replace($request, ['idempotency_key' => 'different-request'])],
            [$service->referenceContent('Synthetic reference', ['objective' => 'Different content'], []), $request],
        ] as [$conflictingContent, $conflictingRequest]) {
            $error = null;
            try { $service->persist(80, 7, $conflictingContent, $conflictingRequest); } catch (RuntimeException $failure) { $error = $failure; }
            self::assertInstanceOf(RuntimeException::class, $error);
            self::assertStringContainsString('版本冲突', $error->getMessage());
        }
        self::assertSame(2, Db::name('knowledge_chunks')->count());
        self::assertSame($revised['chunk']['chunk_id'], (int)Db::name('knowledge_units')->where('unit_id', $first['unit']['unit_id'])->value('current_chunk_id'));
    }

    public function testLegacyReferenceCreateRetryPreservesStoredFieldLabelsAndDigest(): void
    {
        $service = new KnowledgeReferenceService();
        $fields = ['objective' => 'Legacy synthetic objective'];
        $legacyContent = $service->referenceContent('Legacy reference', $fields, []);
        $legacyContent['fields'][0]['label'] = 'objective';
        $request = ['idempotency_key' => 'legacy-create-reference'];
        $saved = $service->persist(80, 7, $legacyContent, $request);
        self::assertSame($saved, $service->persist(80, 7, $service->referenceContent('Legacy reference', $fields, []), $request));
        self::assertSame(1, Db::name('knowledge_chunks')->count());
    }

    public function testSourceImportRollsBackWhenCurrentVersionPointerWasNotSaved(): void
    {
        Db::execute('CREATE TRIGGER corrupt_current_version AFTER UPDATE OF current_chunk_id ON knowledge_units BEGIN UPDATE knowledge_units SET current_chunk_id = 9999 WHERE unit_id = NEW.unit_id; END');
        $error = null;
        try {
            $this->persistSource();
        } catch (RuntimeException $failure) {
            $error = $failure;
        }
        self::assertInstanceOf(RuntimeException::class, $error);
        self::assertSame(0, Db::name('knowledge_units')->count());
        self::assertSame(0, Db::name('knowledge_chunks')->count());
    }

    public function testSourceImportAndRetryReadBackTheSameCurrentContent(): void
    {
        $first = $this->persistSource();
        $replay = $this->persistSource();
        self::assertFalse($first['reused']);
        self::assertTrue($replay['reused']);
        self::assertSame($first['unit'], $replay['unit']);
        self::assertSame($first['chunk'], $replay['chunk']);
        self::assertSame((int)$first['unit']['current_chunk_id'], $first['chunk']['chunk_id']);
        self::assertSame(1, Db::name('knowledge_chunks')->count());
    }

    public function testFailedImportCannotSupersedeAnotherUnitsCurrentChunk(): void
    {
        $this->seedReference();
        $before = Db::name('knowledge_chunks')->where('chunk_id', 1)->find();
        $service = new KnowledgeSourceImportService();
        $key = $service->identity(80, 7, 'Synthetic imported source', 'text', 'synthetic', []);
        Db::name('knowledge_units')->insert(['unit_id' => 2, 'hotel_id' => 80, 'created_by' => 7,
            'status' => 'error', 'stable_key' => $key, 'current_chunk_id' => 1]);
        $error = null;
        try { $this->persistSource(); } catch (RuntimeException $failure) { $error = $failure; }
        self::assertInstanceOf(RuntimeException::class, $error);
        self::assertSame($before, Db::name('knowledge_chunks')->where('chunk_id', 1)->find());
        self::assertSame('error', Db::name('knowledge_units')->where('unit_id', 2)->value('status'));
        self::assertSame(1, Db::name('knowledge_chunks')->count());
    }

    private function seedReference(): void
    {
        $content = ['raw_text' => 'Original synthetic source'];
        Db::name('knowledge_units')->insert(['unit_id' => 1, 'hotel_id' => 80, 'created_by' => 7,
            'name' => 'Synthetic source', 'status' => 'done', 'lifecycle_status' => 'active', 'current_chunk_id' => 1]);
        Db::name('knowledge_chunks')->insert(['chunk_id' => 1, 'unit_id' => 1, 'content' => json_encode($content),
            'content_digest' => (new KnowledgeContentDigestService())->digest($content), 'lifecycle_status' => 'active', 'created_by' => 7]);
    }

    private function persistSource(): array
    {
        $service = new KnowledgeSourceImportService();
        $key = $service->identity(80, 7, 'Synthetic imported source', 'text', 'synthetic', []);
        return $service->persist(['hotel_id' => 80, 'created_by' => 7, 'name' => 'Synthetic document',
            'source' => 'manual', 'status' => 'done', 'description' => 'Synthetic description', 'tags' => ['fixture']],
            ['raw_text' => 'Synthetic imported source', 'scope' => 'reference_only'], $key);
    }
}
