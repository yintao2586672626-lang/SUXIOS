<?php
declare(strict_types=1);

namespace Tests;

use app\service\SemanticGlossarySyncService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class SemanticGlossarySyncServiceTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App(dirname(__DIR__));
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'semantic_glossary_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';
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
        foreach (['knowledge_chunks', 'knowledge_units', 'knowledge_base'] as $table) {
            Db::execute('DROP TABLE IF EXISTS ' . $table);
        }
        Db::execute(
            'CREATE TABLE knowledge_units ('
            . 'unit_id INTEGER PRIMARY KEY AUTOINCREMENT, hotel_id INTEGER NOT NULL DEFAULT 0, '
            . 'stable_key TEXT UNIQUE, current_chunk_id INTEGER, name TEXT NOT NULL, source TEXT, status TEXT, '
            . 'description TEXT, tags TEXT, created_by INTEGER NOT NULL DEFAULT 0, lifecycle_status TEXT, '
            . 'lifecycle_reason TEXT, reviewed_at TEXT, review_due_at TEXT, known_knowns TEXT, known_unknowns TEXT, '
            . 'truth_profile_version TEXT, created_at TEXT, updated_at TEXT)'
        );
        Db::execute(
            'CREATE TABLE knowledge_chunks ('
            . 'chunk_id INTEGER PRIMARY KEY AUTOINCREMENT, unit_id INTEGER NOT NULL, version_no INTEGER, '
            . 'lifecycle_status TEXT, content_digest TEXT, superseded_by_chunk_id INTEGER, published_at TEXT, '
            . 'retired_at TEXT, type TEXT, content TEXT, created_by INTEGER NOT NULL DEFAULT 0, created_at TEXT)'
        );
        Db::execute(
            'CREATE TABLE knowledge_base ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL DEFAULT 0, hotel_id INTEGER NOT NULL DEFAULT 0, '
            . 'category_id INTEGER, title TEXT, content TEXT, keywords TEXT, tags TEXT, sort_order INTEGER, '
            . 'is_enabled INTEGER, view_count INTEGER, like_count INTEGER, create_time TEXT, update_time TEXT)'
        );
    }

    public function testTwoIdenticalPersistsKeepExactUnitMirrorAndChunkIds(): void
    {
        $service = new SemanticGlossarySyncService();
        $first = $service->sync(true);
        $second = $service->sync(true);

        self::assertSame('success', $first['status']);
        self::assertTrue($first['persisted']);
        self::assertTrue($first['readback']['readback_verified']);
        self::assertSame(119, $first['readback']['inserted_chunk_count']);
        self::assertSame(0, $first['readback']['reused_chunk_count']);
        self::assertSame(2927, $first['readback']['readback_concept_count']);
        self::assertSame(119, $first['readback']['readback_active_chunk_count']);
        self::assertSame(0, $first['readback']['unsafe_chunk_count']);
        self::assertSame(0, $first['readback']['mismatch_count']);
        self::assertTrue($first['readback']['mirror_match']);
        self::assertTrue($first['readback']['unit_match']);

        self::assertSame('success', $second['status']);
        self::assertSame('unchanged', $second['readback']['operation']);
        self::assertSame(0, $second['readback']['inserted_chunk_count']);
        self::assertSame(119, $second['readback']['reused_chunk_count']);
        self::assertSame(0, $second['readback']['updated_chunk_count']);
        self::assertSame(0, $second['readback']['superseded_chunk_count']);
        self::assertSame($first['readback']['unit_id'], $second['readback']['unit_id']);
        self::assertSame($first['readback']['mirror_id'], $second['readback']['mirror_id']);
        self::assertSame($first['readback']['current_chunk_id'], $second['readback']['current_chunk_id']);
        self::assertSame($first['readback']['chunk_ids'], $second['readback']['chunk_ids']);
        self::assertSame(119, (int)Db::name('knowledge_chunks')->where('lifecycle_status', 'active')->count());
        self::assertSame(0, (int)Db::name('knowledge_chunks')->where('lifecycle_status', 'superseded')->count());
        self::assertSame(1, (int)Db::name('knowledge_units')->count());
        self::assertSame(1, (int)Db::name('knowledge_base')->count());
    }

    public function testNewVersionPreservesAndSupersedesPreviousChunks(): void
    {
        $root = dirname(__DIR__);
        $packPath = $root . '/docs/knowledge/semantic-glossary/semantic-glossary-pack.json';
        $manifestPath = $root . '/docs/knowledge/semantic-glossary/source-manifest.json';
        $exportPath = $root . '/docs/knowledge/semantic-glossary/exports/Typeless_语音简洁词库_2026-08-25.csv';
        $first = (new SemanticGlossarySyncService())->sync(true);

        $pack = json_decode((string)file_get_contents($packPath), true);
        $manifest = json_decode((string)file_get_contents($manifestPath), true);
        self::assertIsArray($pack);
        self::assertIsArray($manifest);
        $pack['glossary_version'] = '2026-08-26.4-test';
        $pack['revision_no'] = 5;
        $pack['updated_at'] = '2026-08-26T01:00:00+08:00';
        $pack['change_summary'] = [
            'added_count' => 0,
            'removed_count' => 0,
            'updated_count' => 1,
            'added' => [],
            'removed' => [],
            'updated' => ['metric.adr'],
            'lists_truncated' => false,
        ];
        $temporaryPack = tempnam(sys_get_temp_dir(), 'semantic-pack-');
        $temporaryManifest = tempnam(sys_get_temp_dir(), 'semantic-manifest-');
        self::assertIsString($temporaryPack);
        self::assertIsString($temporaryManifest);
        $packBytes = json_encode($pack, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        file_put_contents($temporaryPack, $packBytes);
        $manifest['glossary_version'] = $pack['glossary_version'];
        $manifest['semantic_pack']['bytes'] = strlen($packBytes);
        $manifest['semantic_pack']['sha256'] = hash('sha256', $packBytes);
        file_put_contents(
            $temporaryManifest,
            json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n"
        );

        try {
            $second = (new SemanticGlossarySyncService($temporaryPack, $temporaryManifest, $exportPath))->sync(true);
        } finally {
            @unlink($temporaryPack);
            @unlink($temporaryManifest);
        }

        self::assertSame('success', $second['status']);
        self::assertTrue($second['readback']['readback_verified']);
        self::assertSame($first['readback']['unit_id'], $second['readback']['unit_id']);
        self::assertSame($first['readback']['mirror_id'], $second['readback']['mirror_id']);
        self::assertNotSame($first['readback']['current_chunk_id'], $second['readback']['current_chunk_id']);
        self::assertSame(119, $second['readback']['inserted_chunk_count']);
        self::assertSame(119, $second['readback']['superseded_chunk_count']);
        self::assertSame(119, (int)Db::name('knowledge_chunks')->where('lifecycle_status', 'active')->count());
        self::assertSame(119, (int)Db::name('knowledge_chunks')->where('lifecycle_status', 'superseded')->count());
        self::assertSame(238, (int)Db::name('knowledge_chunks')->count());
        self::assertSame(
            ['2026-08-26.3', '2026-08-26.4-test'],
            array_values(array_unique(array_map(static function (mixed $content): string {
                $decoded = json_decode((string)$content, true);
                return is_array($decoded) ? (string)($decoded['seed_version'] ?? '') : '';
            }, Db::name('knowledge_chunks')->order('chunk_id', 'asc')->column('content'))))
        );
    }
}
