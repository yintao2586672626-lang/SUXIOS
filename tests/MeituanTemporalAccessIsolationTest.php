<?php
declare(strict_types=1);

namespace Tests;

use app\model\User;
use app\service\MeituanTemporalService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class MeituanTemporalAccessIsolationTest extends TestCase
{
    private static App $app;
    private static array $originalDatabaseConfig = [];
    private static string $connection = '';
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        self::$app = new App(dirname(__DIR__));
        self::$app->initialize();
        self::$connection = 'meituan_temporal_access_' . getmypid() . '_' . bin2hex(random_bytes(4));
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::$connection . '.sqlite';
        self::$originalDatabaseConfig = Config::get('database');

        $database = self::$originalDatabaseConfig;
        $database['default'] = self::$connection;
        $database['connections'][self::$connection] = [
            'type' => 'sqlite',
            'database' => self::$sqlitePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($database, 'database');
        Db::connect(null, true);

        Db::execute(
            'CREATE TABLE platform_data_sources ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'tenant_id INTEGER NOT NULL, '
            . 'system_hotel_id INTEGER NOT NULL, '
            . 'platform TEXT NOT NULL, '
            . 'data_type TEXT NOT NULL, '
            . 'ingestion_method TEXT NOT NULL, '
            . 'enabled INTEGER NOT NULL DEFAULT 1, '
            . 'secret_json TEXT NULL'
            . ')'
        );
        Db::execute(
            'CREATE TABLE online_daily_data ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'system_hotel_id INTEGER NOT NULL, '
            . 'source TEXT NOT NULL, '
            . 'data_type TEXT NOT NULL, '
            . 'data_date TEXT NOT NULL, '
            . 'compare_type TEXT NULL, '
            . 'data_source_id INTEGER NULL, '
            . 'sync_task_id INTEGER NULL, '
            . 'source_trace_id TEXT NULL, '
            . 'snapshot_time TEXT NULL, '
            . 'readback_verified INTEGER NOT NULL DEFAULT 0, '
            . 'amount REAL NULL, '
            . 'quantity INTEGER NULL, '
            . 'book_order_num INTEGER NULL, '
            . 'data_value REAL NULL, '
            . 'list_exposure INTEGER NULL, '
            . 'detail_exposure INTEGER NULL, '
            . 'flow_rate REAL NULL, '
            . 'order_filling_num INTEGER NULL, '
            . 'order_submit_num INTEGER NULL, '
            . 'raw_data TEXT NULL'
            . ')'
        );
        Db::name('online_daily_data')->insert([
            'system_hotel_id' => 80,
            'source' => 'meituan',
            'data_type' => 'business',
            'data_date' => '2026-07-29',
            'compare_type' => 'self',
            'data_source_id' => 18,
            'sync_task_id' => 701,
            'source_trace_id' => 'isolated-trace',
            'snapshot_time' => '2026-07-29 18:00:00',
            'readback_verified' => 1,
            'amount' => 2026.78,
            'quantity' => 2,
            'book_order_num' => 1,
            'data_value' => 1013.39,
            'list_exposure' => 81,
            'detail_exposure' => 77,
            'flow_rate' => 1.3,
            'order_submit_num' => 1,
            'raw_data' => json_encode([
                'row' => [
                    'lead_price' => 868,
                    'sales_amount' => 2026.78,
                    'sales_room_nights' => 2,
                    'sales_avg_price' => 1013.39,
                    'exposure_users' => 81,
                    'detail_visitors' => 77,
                    'paid_order_count' => 1,
                    'browse_to_pay_rate' => 1.3,
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        Db::connect()->close();
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$sqlitePath);
    }

    public function testSummaryRejectsUnauthorizedHotelBeforeReadingStoredMetrics(): void
    {
        $user = new class(['tenant_id' => 9]) extends User {
            public function isSuperAdmin(): bool
            {
                return false;
            }

            public function getPermittedHotelIds(): array
            {
                return [];
            }

            public function hasHotelPermission(int $hotelId, string $permission): bool
            {
                return false;
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(403);

        (new MeituanTemporalService())->summary($user, 80, '2026-07-29');
    }
}
