<?php
declare(strict_types=1);
namespace Tests;

use app\controller\Knowledge;
use app\service\KnowledgeContentDigestService;
use app\service\KnowledgeReferenceService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use think\App;
use think\Request;
use think\facade\Config;
use think\facade\Db;

/** Schema inspection is translated; list, source and save use the real controller/ORM. */
final class KnowledgeReferenceAccessSqlite extends \think\db\connector\Sqlite
{
    public function query(string $sql, array $bind = [], bool $master = false): array
    {
        if (preg_match("/^SHOW TABLES LIKE '([a-z_]+)'$/i", $sql, $match)) {
            return parent::query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", [$match[1]], $master);
        }
        if (preg_match('/^SHOW COLUMNS FROM `([a-z_]+)`$/i', $sql, $match)) {
            return array_map(static fn(array $row): array => ['Field' => $row['name']],
                parent::query('PRAGMA table_info(' . $match[1] . ')', [], $master));
        }
        return parent::query($sql, $bind, $master);
    }
}

final class KnowledgeReferenceAccessContextTest extends TestCase
{
    private array $database;
    private string $connection;
    private static array $evidence = [];

    protected function setUp(): void
    {
        (new App(dirname(__DIR__)))->initialize();
        restore_error_handler();
        restore_exception_handler();
        $this->database = Config::get('database', []);
        $this->connection = 'knowledge_reference_access_' . bin2hex(random_bytes(6));
        Config::set(['default' => $this->connection, 'connections' => [$this->connection => [
            'type' => KnowledgeReferenceAccessSqlite::class, 'builder' => \think\db\builder\Sqlite::class,
            'database' => ':memory:', 'prefix' => '', 'fields_strict' => false, 'debug' => false,
        ]]], 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER)');
        Db::execute('INSERT INTO hotels VALUES (20,10),(21,10),(30,11)');
        Db::execute('CREATE TABLE knowledge_units (unit_id INTEGER PRIMARY KEY AUTOINCREMENT, hotel_id INTEGER, tenant_id INTEGER, created_by INTEGER, name TEXT, source TEXT, status TEXT, description TEXT, tags TEXT, stable_key TEXT UNIQUE, current_chunk_id INTEGER, lifecycle_status TEXT, created_at TEXT, updated_at TEXT)');
        Db::execute('CREATE TABLE knowledge_chunks (chunk_id INTEGER PRIMARY KEY AUTOINCREMENT, unit_id INTEGER, type TEXT, content TEXT, content_digest TEXT, lifecycle_status TEXT, created_by INTEGER, created_at TEXT, promotion_candidate_id INTEGER, operating_sop_version_id INTEGER, version_no INTEGER, superseded_by_chunk_id INTEGER, published_at TEXT, retired_at TEXT)');
    }

    protected function tearDown(): void
    {
        Db::connect($this->connection)->close();
        Config::set($this->database, 'database');
        Db::connect(null, true);
    }

    public static function tearDownAfterClass(): void
    {
        $path = (string)getenv('SUXIOS_KNOWLEDGE_REVIEW_ACCEPTANCE_PATH');
        if ($path !== '') file_put_contents($path, json_encode(['environment' => 'isolated_sqlite_synthetic',
            'production_data_touched' => false, 'evidence' => self::$evidence], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function seed(string $source = 'manual', int $hotel = 20, int $owner = 8, int $tenant = 10): int
    {
        $unit = (int)Db::name('knowledge_units')->insertGetId(['hotel_id' => $hotel, 'tenant_id' => $tenant,
            'created_by' => $owner, 'name' => 'Synthetic review source', 'source' => $source, 'status' => 'done',
            'lifecycle_status' => 'active', 'tags' => '[]']);
        $content = ['raw_text' => "Synthetic reference source\nVerify synthetic handover evidence", 'lifecycle_status' => 'active'];
        if ($source === 'formal_operating_sop') $content['formal_record_type'] = 'operating_sop';
        $id = (int)Db::name('knowledge_chunks')->insertGetId(['unit_id' => $unit, 'type' => $source,
            'created_by' => $source === 'formal_operating_sop' ? 99 : $owner, 'content' => json_encode($content),
            'content_digest' => (new KnowledgeContentDigestService())->digest($content), 'lifecycle_status' => 'active', 'version_no' => 1]);
        Db::name('knowledge_units')->where('unit_id', $unit)->update(['current_chunk_id' => $id]);
        return $id;
    }

    private function controller(bool $superAdmin = false, array $post = [], array $get = [], int $tenant = 10): Knowledge
    {
        $reflection = new ReflectionClass(Knowledge::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('request')->setValue($controller, (new Request())->withPost($post)->withGet($get + ['hotel_id' => 20]));
        $reflection->getProperty('currentUser')->setValue($controller, new class($superAdmin, $tenant) {
            public int $id = 7;
            public function __construct(private bool $admin, public int $tenant_id) {}
            public function isSuperAdmin(): bool { return $this->admin; }
            public function getPermittedHotelIds(): array { return [20]; }
        });
        return $controller;
    }

    private function listed(bool $superAdmin = false, int $tenant = 10): array
    {
        $result = $this->controller($superAdmin, tenant: $tenant)->unitList()->getData();
        self::assertSame(0, $result['code'], $result['msg'] ?? 'List failed');
        return $result['data']['list'];
    }

    private function input(array $source): array
    {
        $fields = ['objective', 'steps', 'applicability', 'stop_conditions', 'acceptance_criteria'];
        return array_fill_keys($fields, 'Synthetic human adaptation') + ['hotel_id' => 20,
            'title' => 'Synthetic review reference', 'idempotency_key' => 'synthetic-review-reference', 'citations' => [[
                'chunk_id' => $source['chunk_id'], 'source_digest' => $source['digest'],
                'segment_id' => $source['source_segments'][0]['id'], 'quote' => $source['source_segments'][0]['quote'], 'field_paths' => $fields]]];
    }

    private function assertReadback(array $saved, array $source, bool $superAdmin): void
    {
        self::assertSame('readback_verified', $saved['persistence_status']);
        self::assertFalse($saved['formal_knowledge']);
        self::assertSame(20, (int)$saved['unit']['hotel_id']);
        self::assertSame(7, (int)$saved['unit']['created_by']);
        $read = $this->controller($superAdmin)->referenceSource((int)$saved['chunk']['chunk_id']);
        self::assertSame(200, $read->getCode());
        $readback = $read->getData()['data'];
        self::assertSame($saved['chunk']['content'], $readback['content']);
        self::assertSame($saved['chunk']['content_digest'], $readback['digest']);
        self::assertSame($source['digest'], $readback['content']['citations'][0]['source_digest']);
        self::assertSame($source['source_segments'][0]['quote'], $readback['content']['citations'][0]['quote']);
        self::assertSame(['objective', 'steps', 'applicability', 'stop_conditions', 'acceptance_criteria'], $readback['content']['citations'][0]['field_paths']);
        self::assertSame('reference_only', $readback['content']['scope']);
        self::$evidence[$superAdmin ? 'super_admin_reference' : 'same_hotel_formal_reference'] = ['source' => $source,
            'saved' => $saved, 'readback' => $readback];
    }

    public function testSharedFormalCheckboxIsEnabledWithoutEditingAndReferenceSavesExactReadback(): void
    {
        $id = $this->seed('formal_operating_sop');
        $row = $this->listed()[0];
        self::assertFalse($row['can_edit']);
        self::assertTrue($row['can_select_reference'], 'Shared current formal SOP must be selectable by a non-author');
        $template = file_get_contents(dirname(__DIR__) . '/resources/frontend/templates/fragments/20-page-knowledge-center.html');
        self::assertStringContainsString(':disabled="unit.can_edit === false && !unit.can_select_reference"', $template);
        self::assertFalse($row['can_edit'] === false && !$row['can_select_reference'], 'Actual checkbox disabled expression');
        $source = $this->controller()->referenceSource($id)->getData()['data'];
        $saved = $this->controller(post: $this->input($source))->saveReference();
        self::assertSame(200, $saved->getCode(), json_encode($saved->getData()));
        $this->assertReadback($saved->getData()['data'], $source, false);
    }

    public function testSuperAdminCanReadOtherAuthorsPrivateSourceThroughController(): void
    {
        $id = $this->seed();
        self::assertTrue($this->listed(true)[0]['can_edit']);
        $response = $this->controller(true)->referenceSource($id);
        self::assertSame(200, $response->getCode(), json_encode($response->getData()));
        self::assertTrue($this->listed(true)[0]['can_select_reference']);
        self::assertSame($id, $response->getData()['data']['chunk_id']);
    }

    public function testSuperAdminSaveUsesTrustedIdentityAndReadsBackExactPrivateReference(): void
    {
        $id = $this->seed();
        $source = (new KnowledgeReferenceService())->source($id, 20, 8);
        $saved = $this->controller(true, $this->input($source))->saveReference();
        self::assertSame(200, $saved->getCode(), json_encode($saved->getData()));
        $this->assertReadback($saved->getData()['data'], $source, true);
    }

    public function testSubmittedSuperAdminFlagsCannotGrantSourceOrSaveAccess(): void
    {
        $id = $this->seed();
        $source = (new KnowledgeReferenceService())->source($id, 20, 8);
        $forged = ['super_admin' => true, 'is_super_admin' => true, 'access_context' => ['super_admin' => true, 'tenant_id' => 10]];
        self::assertSame([], $this->listed());
        self::assertSame(422, $this->controller(get: $forged)->referenceSource($id)->getCode());
        self::assertSame(422, $this->controller(post: $this->input($source) + $forged)->saveReference()->getCode());
        self::assertSame(1, Db::name('knowledge_units')->count());
        self::assertSame(1, Db::name('knowledge_chunks')->count());
        self::$evidence['forged_request_flags'] = 'source_and_save_rejected_without_write';
    }

    public function testDefaultServiceAndExistingCoachingCallKeepOrdinaryPermissions(): void
    {
        $id = $this->seed();
        $service = new KnowledgeReferenceService();
        $input = $this->input($service->source($id, 20, 8));
        foreach ([fn() => $service->source($id, 20, 7), fn() => $service->coachingSnapshot($id, 20, 7),
            fn() => $service->save(20, 7, $input + ['super_admin' => true]),
            fn() => $service->source($id, 20, 7, ['super_admin' => 'true', 'tenant_id' => 10])] as $run) {
            try { $run(); self::fail('Ordinary service call must not gain administrator authority'); }
            catch (\RuntimeException $e) { self::assertStringContainsString('无权', $e->getMessage()); }
        }
        $source = $service->source($id, 20, 7, ['super_admin' => true, 'tenant_id' => 10]);
        self::assertSame($id, $source['chunk_id']);
        self::assertSame('reference_only_human_adaptation_not_verified_hotel_fact', $source['policy']);
        $saved = $service->save(20, 7, $input, ['super_admin' => true, 'tenant_id' => 10]);
        $this->assertReadback($saved, $source, true);
        $formal = $this->seed('formal_operating_sop');
        self::assertSame($formal, $service->coachingSnapshot($formal, 20, 7)['chunk_id']);
    }

    public function testHotelTenantAndAuthenticatedTenantBoundariesApplyToSourceAndSave(): void
    {
        foreach ([[21, 10], [30, 11], [20, 11]] as [$hotel, $tenant]) {
            $id = $this->seed('formal_operating_sop', $hotel, 8, $tenant);
            $chunk = Db::name('knowledge_chunks')->where('chunk_id', $id)->find();
            $source = ['chunk_id' => $id, 'digest' => $chunk['content_digest'],
                'source_segments' => (new KnowledgeReferenceService())->segments(json_decode($chunk['content'], true)['raw_text'])];
            foreach ([false, true] as $admin) {
                $response = $this->controller($admin)->referenceSource($id);
                self::assertSame(422, $response->getCode(), 'Source must remain in the selected hotel/tenant');
                self::assertSame(422, $this->controller($admin, $this->input($source))->saveReference()->getCode(),
                    'Citations must remain in the selected hotel/tenant');
            }
            try { (new KnowledgeReferenceService())->source($id, 20, 7, ['super_admin' => true, 'tenant_id' => 10]); self::fail('Direct trusted context cannot bypass source scope'); }
            catch (\RuntimeException $e) { self::assertStringContainsString('无权', $e->getMessage()); }
        }
        $sameHotel = $this->seed('formal_operating_sop');
        $source = (new KnowledgeReferenceService())->source($sameHotel, 20, 7);
        self::assertSame(422, $this->controller(tenant: 11)->referenceSource($sameHotel)->getCode());
        self::assertSame(422, $this->controller(post: $this->input($source), tenant: 11)->saveReference()->getCode());
        self::assertSame(4, Db::name('knowledge_units')->count());
        self::assertSame(4, Db::name('knowledge_chunks')->count());
        self::$evidence['scope_boundaries'] = 'other_hotel_other_tenant_inconsistent_unit_tenant_and_authenticated_tenant_rejected';
    }

    public function testInvalidFormalVersionsRemainUnselectableAndUnreadableForEveryRole(): void
    {
        foreach (['pointer', 'foreign_unit_pointer', 'unit_status', 'unit_lifecycle', 'chunk_lifecycle', 'content_lifecycle', 'quarantine', 'digest'] as $failure) {
            $id = $this->seed('formal_operating_sop');
            $unitId = (int)Db::name('knowledge_chunks')->where('chunk_id', $id)->value('unit_id');
            if ($failure === 'pointer') Db::name('knowledge_units')->where('unit_id', $unitId)->update(['current_chunk_id' => $id + 1000]);
            if ($failure === 'foreign_unit_pointer') {
                $foreign = $this->seed('formal_operating_sop');
                Db::name('knowledge_units')->where('unit_id', $unitId)->update(['current_chunk_id' => $foreign]);
                self::assertFalse((new KnowledgeReferenceService())->canReferenceSource($foreign, 20, 7, [], $unitId));
            }
            if ($failure === 'unit_status') Db::name('knowledge_units')->where('unit_id', $unitId)->update(['status' => 'error']);
            if ($failure === 'unit_lifecycle') Db::name('knowledge_units')->where('unit_id', $unitId)->update(['lifecycle_status' => 'retired']);
            if ($failure === 'chunk_lifecycle') Db::name('knowledge_chunks')->where('chunk_id', $id)->update(['lifecycle_status' => 'superseded']);
            if (in_array($failure, ['content_lifecycle', 'quarantine', 'digest'], true)) {
                $content = ['raw_text' => 'Synthetic invalid source', 'lifecycle_status' => $failure === 'content_lifecycle' ? 'retired' : 'active'];
                if ($failure === 'quarantine') $content['entry'] = ['disposition' => 'reject_or_quarantine'];
                Db::name('knowledge_chunks')->where('chunk_id', $id)->update(['content' => json_encode($content),
                    'content_digest' => $failure === 'digest' ? str_repeat('0', 64) : (new KnowledgeContentDigestService())->digest($content)]);
            }
            foreach ([false, true] as $admin) {
                self::assertSame(422, $this->controller($admin)->referenceSource($id)->getCode());
                $rows = $this->listed($admin);
                $matched = array_values(array_filter($rows, static fn(array $row): bool => $row['unit_id'] === $unitId));
                if ($matched) {
                    self::assertFalse($matched[0]['can_edit']);
                    self::assertFalse($matched[0]['can_select_reference'], $failure);
                    self::assertTrue($matched[0]['can_edit'] === false && !$matched[0]['can_select_reference']);
                }
            }
        }
        self::$evidence['invalid_source_variants'] = ['pointer', 'foreign_unit_pointer', 'unit_status', 'unit_lifecycle', 'chunk_lifecycle', 'content_lifecycle', 'quarantine', 'digest'];
    }

    public function testCurrentGlobalAndLegacyOwnedSourcesRemainSelectable(): void
    {
        $global = $this->seed('manual', 0, 0, 0);
        $owned = $this->seed('manual', 20, 7);
        Db::name('knowledge_chunks')->where('chunk_id', $owned)->update(['content_digest' => null]);
        foreach ($this->listed() as $row) self::assertTrue($row['can_select_reference']);
        self::assertSame(200, $this->controller()->referenceSource($global)->getCode());
        self::assertSame(200, $this->controller()->referenceSource($owned)->getCode());
        self::$evidence['compatibility'] = 'current_global_legacy_owned_and_three_argument_coaching_calls_retained';
    }
}
