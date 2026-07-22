<?php
declare(strict_types=1);

namespace Tests;

use app\service\PlatformDataSyncService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class PlatformDataSyncCaptureTimestampTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $databaseConnection = '';
    private static string $databasePath = '';

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$databaseConnection = 'platform_capture_timestamp_' . getmypid();
        self::$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::$databaseConnection . '.sqlite';
        @unlink(self::$databasePath);

        $database = self::$originalDatabaseConfig;
        $database['default'] = self::$databaseConnection;
        $database['connections'][self::$databaseConnection] = [
            'type' => 'sqlite',
            'database' => self::$databasePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($database, 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)');
        Db::name('hotels')->insert(['id' => 80, 'tenant_id' => 1]);
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Db::connect(self::$databaseConnection)->close();
        } catch (\Throwable) {
        }
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$databasePath);
    }

    public function testExplicitNonTrafficSectionOverridesProfileLoginTrafficGate(): void
    {
        $service = new PlatformDataSyncService();
        $method = new \ReflectionMethod($service, 'syncRequiresTargetDateTrafficEvidence');
        $method->setAccessible(true);
        $source = [
            'platform' => 'meituan',
            'data_type' => 'business',
            'ingestion_method' => 'browser_profile',
        ];
        $trigger = ['trigger_type' => 'profile_login_verified_sync'];

        self::assertFalse($method->invoke($service, $source, $trigger + ['capture_sections' => 'orders'], []));
        self::assertFalse($method->invoke($service, $source, $trigger + ['capture_sections' => 'reviews'], []));
        self::assertTrue($method->invoke($service, $source, $trigger + ['capture_sections' => 'traffic'], []));
    }

    public function testPayloadCaptureTimestampSurvivesOrderSanitizationAsTruthEvidence(): void
    {
        $rows = (new PlatformDataSyncService())->normalizeRowsFromPayload([
            'captured_at' => '2026-07-20T01:31:14+08:00',
            'rows' => [[
                'data_date' => '2026-07-19',
                'data_type' => 'order',
                'hotel_id' => 'meituan-poi-80',
                'amount' => 2543.53,
                'quantity' => 3,
                'book_order_num' => 3,
                'data_value' => 847.84,
            ]],
        ], [
            'id' => 101,
            'name' => 'Meituan Profile',
            'platform' => 'meituan',
            'data_type' => 'business',
            'ingestion_method' => 'browser_profile',
            'system_hotel_id' => 80,
            'tenant_id' => 1,
            'external_hotel_id' => 'meituan-poi-80',
        ], 753);

        self::assertCount(1, $rows);
        $raw = json_decode((string)$rows[0]['raw_data'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('2026-07-20 01:31:14', $raw['captured_at'] ?? null);
    }
}
