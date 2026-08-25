<?php
declare(strict_types=1);

namespace tests;

use app\service\HotelManagerInterviewKnowledgeSyncService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class HotelManagerInterviewKnowledgeSyncServiceTest extends TestCase
{
    private static array $databaseConfig;
    private static string $databasePath;

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$databaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir()
            . '/hotel_manager_interview_knowledge_' . getmypid() . '.sqlite';
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
            throw new RuntimeException('hotel_manager_interview_test_database_cleanup_failed');
        }
    }

    public function testPackValidatesWithCompleteQuestionSetAndGuardedBoundary(): void
    {
        $result = (new HotelManagerInterviewKnowledgeSyncService())->sync(false);

        self::assertSame('validated', $result['status']);
        self::assertFalse($result['persisted']);
        self::assertSame('suxios.hotel_manager_interview_distillation.v1', $result['pack_key']);
        self::assertSame('f17378dd2ac94d546a444a7dabb64d45bb150e8eccb3ea39dba1c229cb61206f', $result['pack_sha256']);
        self::assertSame(15, $result['entry_count']);
        self::assertSame(42, $result['interview_question_count']);
        self::assertSame(4, $result['golden_case_count']);
        self::assertTrue($result['boundary']['reference_only']);
        self::assertFalse($result['boundary']['decision_safe']);
        self::assertFalse($result['boundary']['task_draft_safe']);
        self::assertFalse($result['boundary']['external_write_authorized']);
        self::assertFalse($result['source_file_verification']['manager_interview_questions']['verified']);
        self::assertFalse($result['source_file_verification']['distillation_controller_prompt']['verified']);
    }

    public function testUnsafeBoundaryAndMissingQuestionAreRejected(): void
    {
        $pack = $this->loadPack();
        $pack['boundary']['external_write_authorized'] = true;
        $service = new HotelManagerInterviewKnowledgeSyncService();

        try {
            $this->invokePrivate($service, 'validate', [$pack]);
            self::fail('unsafe boundary should be rejected');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'unsafe_hotel_manager_interview_boundary:external_write_authorized',
                $exception->getMessage()
            );
        }

        $pack = $this->loadPack();
        array_pop($pack['entries'][0]['question_ids']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('hotel_manager_interview_question_alignment_invalid:manager_role');
        $this->invokePrivate($service, 'validate', [$pack]);
    }

    public function testMismatchedSourceFingerprintIsRejected(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'suxios-manager-interview-source-');
        self::assertIsString($source);
        file_put_contents($source, 'not the supplied source');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage(
                'hotel_manager_interview_source_fingerprint_mismatch:manager_interview_questions'
            );
            (new HotelManagerInterviewKnowledgeSyncService(null, [
                'manager_interview_questions' => $source,
            ]))->sync(false);
        } finally {
            @unlink($source);
        }
    }

    public function testValidatedPackPersistsIdempotentlyWithExactReadback(): void
    {
        $this->createPersistenceTables();
        $service = new HotelManagerInterviewKnowledgeSyncService();
        $pack = $this->loadPack();
        $validation = $this->invokePrivate($service, 'validate', [$pack]);

        $first = $this->invokePrivate($service, 'persistValidatedPack', [$pack, $validation]);
        self::assertTrue($first['readback_verified']);
        self::assertSame(15, $first['readback_active_chunk_count']);
        self::assertCount(15, $first['chunk_readback']);
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
        self::assertSame(15, Db::name('knowledge_chunks')->where('lifecycle_status', 'active')->count());

        $unit = Db::name('knowledge_units')->where('unit_id', $second['unit_id'])->find();
        self::assertSame(0, (int)$unit['hotel_id']);
        self::assertSame(
            'global:user_reference:hotel_manager_interview_distillation',
            $unit['stable_key']
        );
        self::assertSame((int)$second['current_chunk_id'], (int)$unit['current_chunk_id']);
    }

    public function testUnknownInactiveLifecycleFailsClosed(): void
    {
        $this->createPersistenceTables();
        $service = new HotelManagerInterviewKnowledgeSyncService();
        $pack = $this->loadPack();
        $validation = $this->invokePrivate($service, 'validate', [$pack]);
        $first = $this->invokePrivate($service, 'persistValidatedPack', [$pack, $validation]);
        $content = [
            'seed_owner' => 'suxios.hotel_manager_interview_distillation',
            'seed_key' => 'tampered',
            'lifecycle_status' => 'unknown',
        ];
        Db::name('knowledge_chunks')->insert([
            'unit_id' => $first['unit_id'],
            'version_no' => 1,
            'lifecycle_status' => 'unknown',
            'content_digest' => $this->invokePrivate($service, 'canonicalHash', [$content]),
            'superseded_by_chunk_id' => null,
            'published_at' => '2026-08-16 00:00:00',
            'retired_at' => '2026-08-16 00:00:00',
            'type' => 'hotel_manager_interview_reference',
            'content' => json_encode($content, JSON_THROW_ON_ERROR),
            'created_by' => 0,
            'created_at' => '2026-08-16 00:00:00',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('hotel_manager_interview_inactive_chunk_invalid');
        $this->invokePrivate($service, 'persistValidatedPack', [$pack, $validation]);
    }

    /** @return array<string, mixed> */
    private function loadPack(): array
    {
        $path = dirname(__DIR__)
            . '/docs/knowledge/hotel-manager-interview-distillation/knowledge-pack.json';
        $pack = json_decode((string)file_get_contents($path), true);
        self::assertIsArray($pack);
        return $pack;
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
