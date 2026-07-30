<?php
declare(strict_types=1);

namespace Tests;

use app\service\OtaStandardEtlService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OtaStandardEtlReadbackQueryTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'ota_standard_readback_' . getmypid() . '.sqlite';
        @unlink(self::$sqlitePath);

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
        Db::execute(<<<'SQL'
CREATE TABLE online_daily_data (
    id INTEGER PRIMARY KEY,
    system_hotel_id INTEGER NOT NULL,
    hotel_id TEXT NOT NULL,
    hotel_name TEXT NOT NULL,
    data_date TEXT NOT NULL,
    source TEXT NOT NULL,
    data_type TEXT NOT NULL,
    dimension TEXT NOT NULL,
    compare_type TEXT NOT NULL,
    amount REAL,
    room_revenue REAL,
    quantity REAL,
    book_order_num INTEGER,
    raw_data TEXT,
    status TEXT,
    validation_status TEXT,
    validation_flags TEXT,
    readback_verified INTEGER NOT NULL DEFAULT 0,
    readback_verified_at TEXT,
    source_trace_id TEXT,
    update_time TEXT
)
SQL);
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Db::connect()->close();
        } catch (\Throwable) {
        }
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        if (is_file(self::$sqlitePath) && !unlink(self::$sqlitePath)) {
            throw new RuntimeException('Unable to remove OTA ETL readback fixture.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Db::name('online_daily_data')->delete(true);
        Db::name('online_daily_data')->insertAll([
            $this->row(1, 100, 1, 'normal'),
            $this->row(2, 900, 0, 'normal'),
            $this->row(3, 800, 1, 'stale'),
        ]);
    }

    public function testDatabaseFetchOnlyFeedsReadbackVerifiedUsableRowsIntoRevenueFacts(): void
    {
        $dataset = (new OtaStandardEtlService())->buildDataset([
            'system_hotel_id' => 80,
            'start_date' => '2026-07-18',
            'end_date' => '2026-07-18',
        ]);

        self::assertSame('ready', $dataset['status']);
        self::assertCount(1, $dataset['fact_ota_daily']);
        self::assertSame(1, $dataset['fact_ota_daily'][0]['source_trace']['row_id']);
        self::assertSame(100.0, $dataset['fact_ota_daily'][0]['revenue']);
    }

    /** @return array<string, mixed> */
    private function row(int $id, float $amount, int $readbackVerified, string $validationStatus): array
    {
        return [
            'id' => $id,
            'system_hotel_id' => 80,
            'hotel_id' => 'hotel-80',
            'hotel_name' => 'Test Hotel',
            'data_date' => '2026-07-18',
            'source' => 'ctrip',
            'data_type' => 'business',
            'dimension' => 'daily_business',
            'compare_type' => 'self',
            'amount' => $amount,
            'room_revenue' => $amount,
            'quantity' => 1,
            'book_order_num' => 1,
            'raw_data' => '{}',
            'status' => 'success',
            'validation_status' => $validationStatus,
            'validation_flags' => '[]',
            'readback_verified' => $readbackVerified,
            'readback_verified_at' => $readbackVerified ? '2026-07-18 12:00:00' : null,
            'source_trace_id' => 'trace-' . $id,
            'update_time' => '2026-07-18 12:00:00',
        ];
    }
}
