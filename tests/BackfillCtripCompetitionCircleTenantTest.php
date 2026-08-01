<?php
declare(strict_types=1);

namespace Tests;

use app\service\CtripCompetitionCirclePersistenceService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\facade\Config;
use think\facade\Db;

final class BackfillCtripCompetitionCircleTenantTest extends TestCase
{
    /** @var array<string, mixed> */
    private static array $originalDatabaseConfig = [];

    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        if (!defined('SUXIOS_BACKFILL_COMPETITION_CIRCLE_FUNCTIONS_ONLY')) {
            define('SUXIOS_BACKFILL_COMPETITION_CIRCLE_FUNCTIONS_ONLY', true);
        }
        require_once dirname(__DIR__) . '/scripts/backfill_ctrip_competition_circle_history.php';

        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'backfill_ctrip_tenant_' . getmypid() . '.sqlite';
        $config = self::$originalDatabaseConfig;
        $config['default'] = 'sqlite';
        $config['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$sqlitePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($config, 'database');
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        if (!empty(self::$originalDatabaseConfig)) {
            Config::set(self::$originalDatabaseConfig, 'database');
            Db::connect()->close();
            Db::connect(null, true);
        }
        if (self::$sqlitePath !== '' && is_file(self::$sqlitePath)) {
            @unlink(self::$sqlitePath);
        }
    }

    protected function setUp(): void
    {
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        if (is_file(self::$sqlitePath)) {
            @unlink(self::$sqlitePath);
        }
        Db::connect(null, true);
        $this->createSchema();
    }

    public function testSourceAndTaskUseAuthoritativeHotelTenantWhenIdsDiffer(): void
    {
        Db::name('hotels')->insert(['id' => 7, 'tenant_id' => 44]);
        $wrongSourceId = Db::name('platform_data_sources')->insertGetId([
            'tenant_id' => 7,
            'system_hotel_id' => 7,
            'platform' => 'ctrip',
            'data_type' => CtripCompetitionCirclePersistenceService::DATA_TYPE,
            'ingestion_method' => CtripCompetitionCirclePersistenceService::BACKFILL_INGESTION_METHOD,
        ]);

        $source = \ensure_backfill_source(7);
        $sourceId = (int)$source['id'];
        $taskId = \start_backfill_task($sourceId, 7);

        self::assertNotSame((int)$wrongSourceId, $sourceId);
        self::assertSame(44, (int)$source['tenant_id']);
        self::assertSame(44, (int)Db::name('platform_data_sources')->where('id', $sourceId)->value('tenant_id'));
        self::assertSame(7, (int)Db::name('platform_data_sources')->where('id', $sourceId)->value('system_hotel_id'));
        self::assertSame(44, (int)Db::name('platform_data_sync_tasks')->where('id', $taskId)->value('tenant_id'));
        self::assertSame(7, (int)Db::name('platform_data_sync_tasks')->where('id', $taskId)->value('system_hotel_id'));
    }

    public function testMissingHotelTenantMappingRejectsSourceAndTaskWithoutWrites(): void
    {
        $sourceError = null;
        try {
            \ensure_backfill_source(999);
        } catch (RuntimeException $exception) {
            $sourceError = $exception;
        }
        self::assertNotNull($sourceError);
        self::assertStringContainsString('Missing authoritative hotels.tenant_id mapping', $sourceError->getMessage());
        self::assertSame(0, Db::name('platform_data_sources')->count());
        self::assertSame(0, Db::name('platform_data_sync_tasks')->count());

        $fixtureSourceId = Db::name('platform_data_sources')->insertGetId([
            'tenant_id' => 999,
            'system_hotel_id' => 999,
            'platform' => 'ctrip',
            'data_type' => CtripCompetitionCirclePersistenceService::DATA_TYPE,
            'ingestion_method' => CtripCompetitionCirclePersistenceService::BACKFILL_INGESTION_METHOD,
        ]);
        $taskError = null;
        try {
            \start_backfill_task((int)$fixtureSourceId, 999);
        } catch (RuntimeException $exception) {
            $taskError = $exception;
        }
        self::assertNotNull($taskError);
        self::assertSame(1, Db::name('platform_data_sources')->count());
        self::assertSame(0, Db::name('platform_data_sync_tasks')->count());
    }

    private function createSchema(): void
    {
        Db::execute('CREATE TABLE hotels (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NULL
        )');
        Db::execute('CREATE TABLE platform_data_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            name TEXT,
            platform TEXT,
            data_type TEXT,
            ingestion_method TEXT,
            status TEXT,
            enabled INTEGER,
            config_json TEXT,
            secret_json TEXT,
            create_time TEXT,
            update_time TEXT
        )');
        Db::execute('CREATE TABLE platform_data_sync_tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            data_source_id INTEGER NOT NULL,
            system_hotel_id INTEGER NOT NULL,
            platform TEXT,
            data_type TEXT,
            ingestion_method TEXT,
            trigger_type TEXT,
            status TEXT,
            attempt_count INTEGER,
            max_attempts INTEGER,
            started_at TEXT,
            message TEXT,
            create_time TEXT,
            update_time TEXT
        )');
    }
}
