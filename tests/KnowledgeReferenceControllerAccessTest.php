<?php
declare(strict_types=1);

namespace Tests;

use app\controller\Knowledge;
use app\service\KnowledgeContentDigestService;
use app\service\KnowledgeReferenceService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\CoachingKnowledgeFixture;
use think\facade\Db;

/** Real controllers and ORM with an in-memory, synthetic-only database. */
final class KnowledgeReferenceControllerAccessTest extends TestCase
{
    protected function setUp(): void
    {
        CoachingKnowledgeFixture::connect(':memory:');
        Db::execute('ALTER TABLE knowledge_units ADD COLUMN tenant_id INTEGER DEFAULT 0');
    }

    protected function tearDown(): void
    {
        Db::connect()->close();
    }

    public function testPrivateStableKeysDoNotGrantOtherAuthorsDetailAccess(): void
    {
        $controller = $this->controller();
        foreach (['material:20:8:private', 'reference:20:8:private', 'formal-operating-sop:forged'] as $key) {
            [$unitId, $chunkId] = $this->source(8, 'text', $key);
            $detail = $controller->detail($unitId)->getData();
            self::assertSame(404, $detail['code']);
            self::assertArrayNotHasKey('chunks', $detail['data'] ?? []);
            self::assertSame(422, $controller->referenceSource($chunkId)->getData()['code']);
        }

        [$ownUnitId] = $this->source(7, 'text', 'material:20:7:owned');
        self::assertSame(0, $controller->detail($ownUnitId)->getData()['code']);
        $reflection = new ReflectionClass($controller);
        // Read access does not permit overwriting a versioned source through ordinary editing.
        self::assertFalse($reflection->getMethod('canModifyOwnedRow')->invoke(
            $controller, Db::name('knowledge_units')->where('unit_id', $ownUnitId)->find()
        ));
    }

    public function testAdministratorCanReadAndSaveAnotherAuthorsReference(): void
    {
        [$unitId, $chunkId] = $this->source(8, 'text', 'material:20:8:admin');
        $input = $this->referenceInput($chunkId);
        // Contradictory submitted identity must not replace authenticated identity.
        $input['super_admin'] = false;
        $input['access_context'] = ['tenant_id' => 11, 'super_admin' => false];
        $controller = $this->controller(true, $input, ['super_admin' => false, 'tenant_id' => 11]);
        self::assertSame(0, $controller->detail($unitId)->getData()['code']);
        $source = $controller->referenceSource($chunkId)->getData();
        self::assertSame(200, $source['code']);
        self::assertSame($chunkId, $source['data']['chunk_id']);

        $saved = $controller->saveReference()->getData();
        self::assertSame(200, $saved['code'], $saved['message'] ?? 'Reference save failed');
        self::assertSame('readback_verified', $saved['data']['persistence_status']);
        self::assertSame(7, (int)$saved['data']['unit']['created_by']);
        self::assertSame('reference_only', $saved['data']['chunk']['content']['scope']);
        $savedUnitId = (int)$saved['data']['unit']['unit_id'];
        self::assertSame(0, $controller->detail($savedUnitId)->getData()['code']);
    }

    public function testForgedAdministratorRequestCannotReadOrSavePrivateSource(): void
    {
        [$unitId, $chunkId] = $this->source(8, 'text', 'material:20:8:forgery');
        $input = $this->referenceInput($chunkId);
        $input['super_admin'] = true;
        $input['access_context'] = ['tenant_id' => 10, 'super_admin' => true];
        $controller = $this->controller(false, $input, ['super_admin' => true, 'tenant_id' => 10]);
        self::assertSame(404, $controller->detail($unitId)->getData()['code']);
        self::assertSame(422, $controller->referenceSource($chunkId)->getData()['code']);
        self::assertSame(422, $controller->saveReference()->getData()['code']);
        self::assertSame(1, Db::name('knowledge_units')->count());
    }

    public function testCompletedFormalSourceRemainsSharedAndUsableForReference(): void
    {
        [$unitId, $chunkId] = $this->source(8, 'formal_operating_sop', 'formal-operating-sop:shared');
        $controller = $this->controller(false, $this->referenceInput($chunkId));
        self::assertSame(0, $controller->detail($unitId)->getData()['code']);
        self::assertSame(200, $controller->referenceSource($chunkId)->getData()['code']);
        self::assertSame(200, $controller->saveReference()->getData()['code']);

        $reflection = new ReflectionClass($controller);
        self::assertFalse($reflection->getMethod('canModifyOwnedRow')->invoke(
            $controller, Db::name('knowledge_units')->where('unit_id', $unitId)->find()
        ));
    }

    public function testIncompleteFormalAndForeignScopeSourcesStayDenied(): void
    {
        $controller = $this->controller();
        foreach (['pending', 'error'] as $status) {
            [$unitId, $chunkId] = $this->source(8, 'formal_operating_sop', 'formal-operating-sop:' . $status, 20, 10, $status);
            self::assertSame(404, $controller->detail($unitId)->getData()['code']);
            self::assertSame(422, $controller->referenceSource($chunkId)->getData()['code']);
        }
        foreach (['FORMAL_OPERATING_SOP', ' formal_operating_sop '] as $source) {
            [$unitId, $chunkId] = $this->source(8, $source, 'material:20:8:' . hash('sha256', $source));
            self::assertSame(404, $controller->detail($unitId)->getData()['code']);
            self::assertSame(422, $controller->referenceSource($chunkId)->getData()['code']);
        }
        foreach ([[21, 10], [30, 11], [20, 11]] as [$hotelId, $tenantId]) {
            [$unitId, $chunkId] = $this->source(8, 'formal_operating_sop', 'formal-operating-sop:hotel-' . $hotelId, $hotelId, $tenantId);
            self::assertSame(404, $controller->detail($unitId)->getData()['code']);
            self::assertSame(422, $controller->referenceSource($chunkId)->getData()['code']);
        }
    }

    private function source(int $ownerId, string $source, string $key, int $hotelId = 20, int $tenantId = 10, string $status = 'done'): array
    {
        $content = ['raw_text' => "Synthetic source statement.\nSynthetic source acceptance.", 'lifecycle_status' => 'active'];
        $unitId = (int)Db::name('knowledge_units')->insertGetId([
            'hotel_id' => $hotelId, 'tenant_id' => $tenantId, 'name' => 'Synthetic knowledge',
            'source' => $source, 'status' => $status, 'tags' => '[]', 'created_by' => $ownerId,
            'stable_key' => $key, 'lifecycle_status' => 'active',
        ]);
        $chunkId = (int)Db::name('knowledge_chunks')->insertGetId([
            'unit_id' => $unitId, 'type' => 'manual', 'content' => json_encode($content, JSON_THROW_ON_ERROR),
            'content_digest' => (new KnowledgeContentDigestService())->digest($content),
            'created_by' => $ownerId, 'lifecycle_status' => 'active',
        ]);
        Db::name('knowledge_units')->where('unit_id', $unitId)->update(['current_chunk_id' => $chunkId]);
        return [$unitId, $chunkId];
    }

    private function referenceInput(int $chunkId): array
    {
        $source = (new KnowledgeReferenceService())->source($chunkId, 20, 8, ['tenant_id' => 10]);
        $fields = ['objective', 'steps', 'applicability', 'stop_conditions', 'acceptance_criteria'];
        return array_fill_keys($fields, 'Synthetic human adaptation') + [
            'hotel_id' => 20, 'title' => 'Synthetic reference', 'idempotency_key' => 'synthetic-reference-' . $chunkId,
            'citations' => [[
                'chunk_id' => $chunkId, 'source_digest' => $source['digest'],
                'segment_id' => $source['source_segments'][0]['id'], 'quote' => $source['source_segments'][0]['quote'],
                'field_paths' => $fields,
            ]],
        ];
    }

    private function controller(bool $superAdmin = false, array $input = [], array $query = []): Knowledge
    {
        $reflection = new ReflectionClass(Knowledge::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('request')->setValue($controller, new class($input, $query) {
            public function __construct(private array $input, private array $query) {}
            public function param($key, $default = null): mixed { return $this->query[$key] ?? ($key === 'hotel_id' ? 20 : $default); }
            public function post(): array { return $this->input; }
            public function method(): string { return 'POST'; }
            public function getContent(): string { return ''; }
        });
        $reflection->getProperty('currentUser')->setValue($controller, new class($superAdmin) {
            public int $id = 7;
            public int $tenant_id = 10;
            public function __construct(private bool $superAdmin) {}
            public function isSuperAdmin(): bool { return $this->superAdmin; }
            public function getPermittedHotelIds(): array { return [20]; }
        });
        return $controller;
    }
}
