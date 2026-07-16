<?php
declare(strict_types=1);

namespace Tests;

use app\service\HotelCascadeDeletionService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class HotelCascadeDeletionServiceTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $databasePath = '';

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hotel_cascade_delete_' . getmypid() . '.sqlite';

        $database = self::$originalDatabaseConfig;
        $database['default'] = 'sqlite';
        $database['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$databasePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($database, 'database');
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$databasePath);
    }

    protected function setUp(): void
    {
        try {
            Db::connect('sqlite')->close();
        } catch (\Throwable) {
        }
        @unlink(self::$databasePath);
        Db::connect(null, true);
        $this->createSchema();
        $this->seedRows();
    }

    public function testPreviewIncludesDependentRowsAndPermanentDeleteRemovesAllHotelData(): void
    {
        $service = new HotelCascadeDeletionService();

        $preview = $service->preview(10);
        self::assertSame(1, $preview['tables']['online_daily_data'] ?? 0);
        self::assertSame(1, $preview['tables']['temporal_forecast_snapshots'] ?? 0);
        self::assertSame(1, $preview['tables']['ota_credentials'] ?? 0);
        self::assertSame(1, $preview['tables']['ota_profile_bindings'] ?? 0);
        self::assertSame(1, $preview['tables']['ota_meituan_reviews'] ?? 0);
        self::assertSame(1, $preview['tables']['opening_tasks'] ?? 0);
        self::assertSame(1, $preview['tables']['operation_execution_evidence'] ?? 0);
        self::assertSame(1, $preview['tables']['competitor_price_log'] ?? 0);
        self::assertSame(2, $preview['config_entries']);
        self::assertArrayNotHasKey('encrypted_payload', $preview);

        $result = $service->delete(10);

        self::assertSame(0, Db::name('hotels')->where('id', 10)->count());
        self::assertSame(2, Db::name('users')->count());
        self::assertNull(Db::name('users')->where('id', 1)->value('hotel_id'));
        self::assertNull(Db::name('users')->where('id', 1)->value('tenant_id'));
        self::assertSame(20, (int)Db::name('users')->where('id', 2)->value('hotel_id'));
        self::assertSame(0, Db::name('user_hotel_permissions')->where('hotel_id', 10)->count());
        self::assertSame(0, Db::name('online_daily_data')->where('system_hotel_id', 10)->count());
        self::assertSame(0, Db::name('temporal_forecast_snapshots')->where('system_hotel_id', 10)->count());
        self::assertSame(0, Db::name('ota_credentials')->where('system_hotel_id', 10)->count());
        self::assertSame(0, Db::name('ota_profile_bindings')->where('system_hotel_id', 10)->count());
        self::assertSame(0, Db::name('platform_data_sources')->where('system_hotel_id', 10)->count());
        self::assertSame(0, Db::name('operation_logs')->where('hotel_id', 10)->count());
        self::assertSame(0, Db::name('ota_meituan_reviews')->where('system_hotel_id', 10)->count());
        self::assertSame(0, Db::name('opening_tasks')->where('project_id', 100)->count());
        self::assertSame(0, Db::name('operation_execution_evidence')->where('task_id', 200)->count());
        self::assertSame(0, Db::name('competitor_price_log')->where('store_id', 10)->count());
        self::assertSame(1, Db::name('competitor_price_log')->where('store_id', 20)->where('hotel_id', 10)->count(), 'A competitor id collision in another store must not be deleted.');
        self::assertSame(2, $result['config_entries_deleted']);
        self::assertGreaterThanOrEqual(9, $result['deleted_rows']);

        foreach (['ctrip_config_list', 'meituan_config_list'] as $key) {
            $list = json_decode((string)Db::name('system_configs')->where('config_key', $key)->value('config_value'), true, 512, JSON_THROW_ON_ERROR);
            self::assertArrayNotHasKey('hotel-10', $list);
            self::assertArrayHasKey('hotel-20', $list);
        }
    }

    public function testDeleteRollsBackWhenHotelDoesNotExist(): void
    {
        $service = new HotelCascadeDeletionService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('酒店不存在');
        $service->delete(999);
    }

    private function createSchema(): void
    {
        foreach ([
            'CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NULL, name TEXT NOT NULL, status INTEGER NOT NULL DEFAULT 1)',
            'CREATE TABLE users (id INTEGER PRIMARY KEY, hotel_id INTEGER NULL, tenant_id INTEGER NULL)',
            'CREATE TABLE user_hotel_permissions (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL)',
            'CREATE TABLE online_daily_data (id INTEGER PRIMARY KEY, system_hotel_id INTEGER NOT NULL, hotel_id TEXT NULL)',
            'CREATE TABLE temporal_forecast_snapshots (id INTEGER PRIMARY KEY, system_hotel_id INTEGER NOT NULL)',
            'CREATE TABLE ota_credentials (id INTEGER PRIMARY KEY, system_hotel_id INTEGER NOT NULL, encrypted_payload TEXT NOT NULL)',
            'CREATE TABLE ota_profile_bindings (id INTEGER PRIMARY KEY, system_hotel_id INTEGER NOT NULL)',
            'CREATE TABLE platform_data_sources (id INTEGER PRIMARY KEY, system_hotel_id INTEGER NOT NULL)',
            'CREATE TABLE operation_logs (id INTEGER PRIMARY KEY, hotel_id INTEGER NULL)',
            'CREATE TABLE ota_meituan_reviews (id INTEGER PRIMARY KEY, system_hotel_id INTEGER NOT NULL)',
            'CREATE TABLE opening_projects (id INTEGER PRIMARY KEY, hotel_id INTEGER NOT NULL)',
            'CREATE TABLE opening_tasks (id INTEGER PRIMARY KEY, project_id INTEGER NOT NULL)',
            'CREATE TABLE operation_execution_tasks (id INTEGER PRIMARY KEY, hotel_id INTEGER NOT NULL)',
            'CREATE TABLE operation_execution_evidence (id INTEGER PRIMARY KEY, task_id INTEGER NOT NULL)',
            'CREATE TABLE competitor_price_log (id INTEGER PRIMARY KEY, store_id INTEGER NOT NULL, hotel_id INTEGER NOT NULL)',
            'CREATE TABLE system_configs (id INTEGER PRIMARY KEY, config_key TEXT UNIQUE, config_value TEXT, update_time TEXT NULL)',
        ] as $sql) {
            Db::execute($sql);
        }
    }

    private function seedRows(): void
    {
        Db::name('hotels')->insertAll([
            ['id' => 10, 'tenant_id' => 10, 'name' => '待归档酒店', 'status' => 1],
            ['id' => 20, 'tenant_id' => 20, 'name' => '保留酒店', 'status' => 1],
        ]);
        Db::name('users')->insertAll([
            ['id' => 1, 'hotel_id' => 10, 'tenant_id' => 10],
            ['id' => 2, 'hotel_id' => 20, 'tenant_id' => 20],
        ]);
        Db::name('user_hotel_permissions')->insertAll([
            ['id' => 1, 'user_id' => 1, 'hotel_id' => 10],
            ['id' => 2, 'user_id' => 2, 'hotel_id' => 20],
        ]);
        Db::name('online_daily_data')->insert(['id' => 1, 'system_hotel_id' => 10, 'hotel_id' => '6866634']);
        Db::name('temporal_forecast_snapshots')->insert(['id' => 1, 'system_hotel_id' => 10]);
        Db::name('ota_credentials')->insert(['id' => 1, 'system_hotel_id' => 10, 'encrypted_payload' => 'secret']);
        Db::name('ota_profile_bindings')->insert(['id' => 1, 'system_hotel_id' => 10]);
        Db::name('platform_data_sources')->insert(['id' => 1, 'system_hotel_id' => 10]);
        Db::name('operation_logs')->insert(['id' => 1, 'hotel_id' => 10]);
        Db::name('ota_meituan_reviews')->insert(['id' => 1, 'system_hotel_id' => 10]);
        Db::name('opening_projects')->insert(['id' => 100, 'hotel_id' => 10]);
        Db::name('opening_tasks')->insert(['id' => 101, 'project_id' => 100]);
        Db::name('operation_execution_tasks')->insert(['id' => 200, 'hotel_id' => 10]);
        Db::name('operation_execution_evidence')->insert(['id' => 201, 'task_id' => 200]);
        Db::name('competitor_price_log')->insertAll([
            ['id' => 301, 'store_id' => 10, 'hotel_id' => 999],
            ['id' => 302, 'store_id' => 20, 'hotel_id' => 10],
        ]);

        foreach (['ctrip_config_list', 'meituan_config_list'] as $index => $key) {
            Db::name('system_configs')->insert([
                'id' => $index + 1,
                'config_key' => $key,
                'config_value' => json_encode([
                    'hotel-10' => ['id' => 'hotel-10', 'hotel_id' => '10', 'system_hotel_id' => 10],
                    'hotel-20' => ['id' => 'hotel-20', 'hotel_id' => '20', 'system_hotel_id' => 20],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'update_time' => '2026-07-11 20:00:00',
            ]);
        }
    }
}
