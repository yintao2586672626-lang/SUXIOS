<?php
declare(strict_types=1);

namespace Tests;

use app\service\CtripMetricFactProjectionService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class CtripMetricFactProjectionServiceTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $connection = '';
    private static string $databasePath = '';

    public static function setUpBeforeClass(): void
    {
        (new App(dirname(__DIR__)))->initialize();
        self::$connection = 'ctrip_fact_projection_' . getmypid() . '_' . bin2hex(random_bytes(4));
        self::$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::$connection . '.sqlite';
        self::$originalDatabaseConfig = Config::get('database');
        $database = self::$originalDatabaseConfig;
        $database['default'] = self::$connection;
        $database['connections'][self::$connection] = [
            'type' => 'sqlite',
            'database' => self::$databasePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($database, 'database');
        Db::connect(null, true);
        Db::execute(<<<'SQL'
            CREATE TABLE ota_ctrip_metric_facts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                run_id INTEGER NULL, tenant_id INTEGER NULL, system_hotel_id INTEGER NULL,
                ota_hotel_id TEXT NOT NULL DEFAULT '', hotel_name TEXT NOT NULL DEFAULT '',
                data_date TEXT NULL, source TEXT NOT NULL DEFAULT 'ctrip',
                capture_section TEXT NOT NULL DEFAULT '', endpoint_id TEXT NOT NULL DEFAULT '',
                metric_key TEXT NOT NULL DEFAULT '', metric_label TEXT NOT NULL DEFAULT '',
                category TEXT NOT NULL DEFAULT '', data_type TEXT NOT NULL DEFAULT '',
                metric_scope TEXT NOT NULL DEFAULT 'ota_channel', value_type TEXT NOT NULL DEFAULT '',
                value_decimal REAL NULL, value_text TEXT NULL, source_key TEXT NOT NULL DEFAULT '',
                source_path TEXT NOT NULL DEFAULT '', source_hash TEXT NOT NULL DEFAULT '', raw_data TEXT NULL,
                capture_status TEXT NOT NULL DEFAULT '', captured_at TEXT NULL
            )
        SQL);
        Db::execute('CREATE UNIQUE INDEX ctrip_fact_identity ON ota_ctrip_metric_facts (system_hotel_id, data_date, capture_section, endpoint_id, metric_key, source_hash)');
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Db::connect()->close();
        } catch (\Throwable) {
        }
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$databasePath);
    }

    protected function setUp(): void
    {
        Db::name('ota_ctrip_metric_facts')->delete(true);
    }

    public function testProjectsOnlyCapturedReadbackFactsAndKeepsIdentityIdempotent(): void
    {
        $row = [
            'id' => 901,
            'sync_task_id' => 51,
            'tenant_id' => 7,
            'system_hotel_id' => 80,
            'hotel_id' => 'ctrip-hotel-80',
            'hotel_name' => '敦煌漠蓝新',
            'data_date' => '2026-07-24',
            'source' => 'ctrip',
            'data_type' => 'business',
            'amount' => 7395.31,
            'source_trace_id' => 'trace-verified-80',
            'update_time' => '2026-07-25 19:44:00',
            'raw_data' => json_encode([
                'row' => ['section' => 'business_overview', 'endpoint_id' => 'business_market_overview'],
                'captured_at' => '2026-07-25 19:43:56',
                'field_facts' => [
                    [
                        'metric_key' => 'order_amount',
                        'metric_label' => '成交金额',
                        'data_type' => 'business',
                        'status' => 'captured',
                        'stored_value_present' => true,
                        'storage_field' => 'online_daily_data.amount',
                        'source_key' => 'bookAmount',
                        'source_path' => 'data.bookAmount',
                    ],
                    [
                        'metric_key' => 'room_nights',
                        'status' => 'field_missing',
                        'stored_value_present' => false,
                        'storage_field' => 'online_daily_data.quantity',
                        'source_key' => '',
                        'source_path' => '',
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        $service = new CtripMetricFactProjectionService();
        self::assertSame(['projected' => 1, 'skipped' => 0, 'available' => true], $service->project([$row]));
        self::assertSame(['projected' => 1, 'skipped' => 0, 'available' => true], $service->project([$row]));
        self::assertSame(1, Db::name('ota_ctrip_metric_facts')->count());

        $fact = Db::name('ota_ctrip_metric_facts')
            ->where('system_hotel_id', 80)
            ->where('data_date', '2026-07-24')
            ->where('metric_key', 'order_amount')
            ->find();
        self::assertIsArray($fact);
        self::assertSame('order_amount', $fact['metric_key']);
        self::assertSame(7395.31, (float)$fact['value_decimal']);
        self::assertSame('projected_from_verified_daily_fact', $fact['capture_status']);
    }
}
