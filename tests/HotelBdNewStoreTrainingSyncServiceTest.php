<?php
declare(strict_types=1);

namespace tests;

use app\service\HotelBdNewStoreTrainingSyncService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class HotelBdNewStoreTrainingSyncServiceTest extends TestCase
{
    private static array $databaseConfig;
    private static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir()
            . '/hotel_bd_training_sync_' . getmypid() . '.sqlite';
        @unlink(self::$databasePath);
        $config = self::$databaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$databasePath,
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
        Config::set(self::$databaseConfig, 'database');
        Db::connect(null, true);
        if (is_file(self::$databasePath) && !@unlink(self::$databasePath)) {
            throw new RuntimeException('hotel_bd_training_test_database_cleanup_failed');
        }
    }

    public function testGeneratedPackValidatesWithoutDatabaseWrite(): void
    {
        $result = (new HotelBdNewStoreTrainingSyncService())->sync(false);

        self::assertSame('validated', $result['status']);
        self::assertFalse($result['persisted']);
        self::assertSame('suxios.hotel_bd_new_store_training.v1', $result['pack_key']);
        self::assertSame('d1a935d4d1bcfa025819836afa2c8eaaff4104b44b1168737f8d670b324ec2a1', $result['pack_sha256']);
        self::assertSame(6, $result['entry_count']);
        self::assertSame(3, $result['golden_case_count']);
        self::assertFalse($result['source_file_verification']['verified']);
        self::assertTrue($result['boundary']['reference_only']);
        self::assertFalse($result['boundary']['decision_safe']);
        self::assertFalse($result['boundary']['task_draft_safe']);
        self::assertFalse($result['boundary']['external_write_authorized']);
    }

    public function testUnsafeBoundaryIsRejected(): void
    {
        $pack = $this->loadPack();
        $pack['boundary']['task_draft_safe'] = true;
        $temporary = $this->writeTemporaryJson($pack);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('unsafe_hotel_bd_training_boundary:task_draft_safe');
            (new HotelBdNewStoreTrainingSyncService($temporary))->sync(false);
        } finally {
            @unlink($temporary);
        }
    }

    public function testSignedRemoteAssetRetentionIsRejected(): void
    {
        $pack = $this->loadPack();
        $pack['source_document']['signed_remote_asset_url_retained'] = true;
        $temporary = $this->writeTemporaryJson($pack);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('unsafe_hotel_bd_training_source_retention:signed_remote_asset_url_retained');
            (new HotelBdNewStoreTrainingSyncService($temporary))->sync(false);
        } finally {
            @unlink($temporary);
        }
    }

    public function testMismatchedSourceFingerprintIsRejected(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'suxios-bd-training-source-');
        self::assertIsString($source);
        file_put_contents($source, 'not the supplied training document');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('hotel_bd_training_source_fingerprint_mismatch');
            (new HotelBdNewStoreTrainingSyncService(null, $source))->sync(false);
        } finally {
            @unlink($source);
        }
    }

    public function testLegacyContentKeyRemainsIdempotentlyAddressable(): void
    {
        $method = new ReflectionMethod(HotelBdNewStoreTrainingSyncService::class, 'contentSeedKey');
        $service = new HotelBdNewStoreTrainingSyncService();

        self::assertSame('preferred', $method->invoke($service, [
            'seed_key' => 'preferred',
            'content_key' => 'legacy-content',
            'key' => 'legacy-key',
        ]));
        self::assertSame('legacy-content', $method->invoke($service, [
            'content_key' => 'legacy-content',
            'key' => 'legacy-key',
        ]));
        self::assertSame('legacy-key', $method->invoke($service, ['key' => 'legacy-key']));
        self::assertSame('', $method->invoke($service, []));
    }

    public function testPersistRequiresExactSourceBeforeDatabaseAccess(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('hotel_bd_training_source_verification_required');

        (new HotelBdNewStoreTrainingSyncService())->sync(true);
    }

    public function testValidatedPackPersistsIdempotentlyAndSupersedesRemovedEntries(): void
    {
        $this->createPersistenceTables();
        $service = new HotelBdNewStoreTrainingSyncService();
        $pack = $this->loadPack();
        $validation = $this->invokePrivate($service, 'validate', [$pack]);

        $first = $this->invokePrivate($service, 'persistValidatedPack', [$pack, $validation]);
        self::assertTrue($first['readback_verified']);
        self::assertSame(6, $first['readback_active_chunk_count']);
        self::assertCount(6, $first['chunk_readback']);
        foreach ($first['chunk_readback'] as $chunk) {
            self::assertTrue($chunk['content_match']);
            self::assertFalse($chunk['decision_safe']);
            self::assertFalse($chunk['task_draft_safe']);
            self::assertFalse($chunk['external_write_authorized']);
        }

        $firstIds = array_column($first['chunk_readback'], 'chunk_id');
        sort($firstIds);
        $second = $this->invokePrivate($service, 'persistValidatedPack', [$pack, $validation]);
        $secondIds = array_column($second['chunk_readback'], 'chunk_id');
        sort($secondIds);
        self::assertTrue($second['readback_verified']);
        self::assertSame($first['unit_id'], $second['unit_id']);
        self::assertSame($firstIds, $secondIds);
        self::assertSame(6, Db::name('knowledge_chunks')->where('lifecycle_status', 'active')->count());

        $removedContent = [
            'schema_version' => '1.0',
            'seed_owner' => 'suxios.hotel_bd_new_store_training',
            'seed_key' => 'removed_training_entry',
            'seed_version' => 'legacy',
            'lifecycle_status' => 'active',
            'scope' => 'industry_training_reference_only',
            'decision_safe' => false,
            'task_draft_safe' => false,
            'external_write_authorized' => false,
        ];
        $removedId = (int)Db::name('knowledge_chunks')->insertGetId([
            'unit_id' => $first['unit_id'],
            'version_no' => 1,
            'lifecycle_status' => 'active',
            'content_digest' => $this->invokePrivate($service, 'canonicalHash', [$removedContent]),
            'superseded_by_chunk_id' => null,
            'published_at' => '2026-07-01 00:00:00',
            'retired_at' => null,
            'type' => 'hotel_bd_new_store_training_reference',
            'content' => json_encode($removedContent, JSON_THROW_ON_ERROR),
            'created_by' => 0,
            'created_at' => '2026-07-01 00:00:00',
        ]);

        $third = $this->invokePrivate($service, 'persistValidatedPack', [$pack, $validation]);
        $removed = Db::name('knowledge_chunks')->where('chunk_id', $removedId)->find();
        $removedReadback = json_decode((string)($removed['content'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($third['readback_verified']);
        self::assertSame(6, $third['readback_active_chunk_count']);
        self::assertSame('superseded', $removed['lifecycle_status']);
        self::assertNotEmpty($removed['retired_at']);
        self::assertSame('superseded', $removedReadback['lifecycle_status']);
        self::assertFalse($removedReadback['decision_safe']);
        self::assertFalse($removedReadback['task_draft_safe']);
        self::assertFalse($removedReadback['external_write_authorized']);

        $activeRows = Db::name('knowledge_chunks')->where('lifecycle_status', 'active')->select()->toArray();
        self::assertCount(6, $activeRows);
        foreach ($activeRows as $row) {
            $content = json_decode((string)$row['content'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('industry_training_reference_only', $content['scope']);
            self::assertFalse($content['decision_safe']);
            self::assertFalse($content['task_draft_safe']);
            self::assertFalse($content['external_write_authorized']);
        }
    }

    public function testCustomPackCannotBePersisted(): void
    {
        $temporary = $this->writeTemporaryJson($this->loadPack());

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('hotel_bd_training_untrusted_pack_persist_forbidden');
            (new HotelBdNewStoreTrainingSyncService($temporary))->sync(true);
        } finally {
            @unlink($temporary);
        }
    }

    public function testUnknownEntryFieldIsRejectedBeforePersistence(): void
    {
        $pack = $this->loadPack();
        $pack['entries'][0]['unexpected_note'] = 'untrusted payload';
        $temporary = $this->writeTemporaryJson($pack);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('hotel_bd_training_schema_keys_invalid:entry:bd_diagnosis_before_selling');
            (new HotelBdNewStoreTrainingSyncService($temporary))->sync(false);
        } finally {
            @unlink($temporary);
        }
    }

    public function testCredentialLikePayloadIsRejectedCaseInsensitively(): void
    {
        $pack = $this->loadPack();
        $pack['entries'][0]['body_markdown'] .= "\naUtHoRiZaTiOn: Bearer abcdefghijklmnop";
        $temporary = $this->writeTemporaryJson($pack);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('hotel_bd_training_sensitive_value:root.entries.0.body_markdown');
            (new HotelBdNewStoreTrainingSyncService($temporary))->sync(false);
        } finally {
            @unlink($temporary);
        }
    }

    public function testSensitiveNestedKeyIsRejected(): void
    {
        $pack = $this->loadPack();
        $pack['golden_cases'][0]['input']['access_token'] = 'abcdefghijklmnop';
        $temporary = $this->writeTemporaryJson($pack);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('hotel_bd_training_sensitive_key:root.golden_cases.0.input');
            (new HotelBdNewStoreTrainingSyncService($temporary))->sync(false);
        } finally {
            @unlink($temporary);
        }
    }

    public function testRemoteUrlPayloadIsRejected(): void
    {
        $pack = $this->loadPack();
        $pack['entries'][0]['body_markdown'] .= "\nhttps://example.invalid/private";
        $temporary = $this->writeTemporaryJson($pack);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('hotel_bd_training_sensitive_value:root.entries.0.body_markdown');
            (new HotelBdNewStoreTrainingSyncService($temporary))->sync(false);
        } finally {
            @unlink($temporary);
        }
    }

    public function testCanonicalHashDetectsContentDriftButIgnoresMapOrder(): void
    {
        $method = new ReflectionMethod(HotelBdNewStoreTrainingSyncService::class, 'canonicalHash');
        $service = new HotelBdNewStoreTrainingSyncService();

        $baseline = $method->invoke($service, ['title' => '受控参考', 'decision_safe' => false]);
        $reordered = $method->invoke($service, ['decision_safe' => false, 'title' => '受控参考']);
        $drifted = $method->invoke($service, ['title' => '被篡改', 'decision_safe' => false]);

        self::assertSame($baseline, $reordered);
        self::assertNotSame($baseline, $drifted);
    }

    public function testOnlyExplicitInactiveLifecycleStatesCanBeSkipped(): void
    {
        $method = new ReflectionMethod(HotelBdNewStoreTrainingSyncService::class, 'isSupportedInactiveLifecycle');
        $service = new HotelBdNewStoreTrainingSyncService();

        self::assertTrue($method->invoke($service, 'superseded'));
        self::assertTrue($method->invoke($service, 'retired'));
        self::assertTrue($method->invoke($service, 'stale'));
        self::assertFalse($method->invoke($service, ''));
        self::assertFalse($method->invoke($service, 'unknown'));
        self::assertFalse($method->invoke($service, 'ACTIVE'));
    }

    /** @return array<string, mixed> */
    private function loadPack(): array
    {
        $path = dirname(__DIR__) . '/docs/knowledge/hotel-bd-new-store-training/knowledge-pack.json';
        $pack = json_decode((string)file_get_contents($path), true);
        self::assertIsArray($pack);
        return $pack;
    }

    /** @param array<string, mixed> $pack */
    private function writeTemporaryJson(array $pack): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'suxios-bd-training-pack-');
        self::assertIsString($temporary);
        file_put_contents(
            $temporary,
            json_encode($pack, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
        return $temporary;
    }

    private function createPersistenceTables(): void
    {
        Db::execute('DROP TABLE IF EXISTS knowledge_chunks');
        Db::execute('DROP TABLE IF EXISTS knowledge_units');
        Db::execute('CREATE TABLE knowledge_units (
            unit_id INTEGER PRIMARY KEY AUTOINCREMENT,
            hotel_id INTEGER NOT NULL,
            stable_key TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            source TEXT NOT NULL,
            status TEXT NOT NULL,
            description TEXT NOT NULL,
            tags TEXT NOT NULL,
            created_by INTEGER NOT NULL,
            lifecycle_status TEXT NOT NULL,
            lifecycle_reason TEXT NOT NULL,
            known_knowns TEXT NOT NULL,
            known_unknowns TEXT NOT NULL,
            truth_profile_version TEXT NOT NULL,
            reviewed_at TEXT NOT NULL,
            review_due_at TEXT NOT NULL,
            current_chunk_id INTEGER,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');
        Db::execute('CREATE TABLE knowledge_chunks (
            chunk_id INTEGER PRIMARY KEY AUTOINCREMENT,
            unit_id INTEGER NOT NULL,
            version_no INTEGER NOT NULL,
            lifecycle_status TEXT NOT NULL,
            content_digest TEXT NOT NULL,
            superseded_by_chunk_id INTEGER,
            published_at TEXT NOT NULL,
            retired_at TEXT,
            type TEXT NOT NULL,
            content TEXT NOT NULL,
            created_by INTEGER NOT NULL,
            created_at TEXT NOT NULL
        )');
    }

    private function invokePrivate(object $target, string $method, array $arguments): mixed
    {
        return (new ReflectionMethod($target, $method))->invokeArgs($target, $arguments);
    }
}
