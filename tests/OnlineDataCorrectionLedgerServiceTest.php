<?php
declare(strict_types=1);

namespace Tests;

use app\service\OnlineDataCorrectionLedgerService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OnlineDataCorrectionLedgerServiceTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $databasePath = '';

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'online_data_correction_' . getmypid() . '.sqlite';
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
        Db::name('online_daily_data')->insertAll([
            $this->row(1, 10, 100.0),
            $this->row(2, 20, 200.0),
        ]);
    }

    public function testUpdateWritesBeforeAfterLedgerAndVerifiesReadback(): void
    {
        $result = (new OnlineDataCorrectionLedgerService())->update(1, [
            'amount' => 120.5,
            'validation_status' => 'unverified',
        ], 9, [10], 'operator correction');

        self::assertSame(120.5, (float)Db::name('online_daily_data')->where('id', 1)->value('amount'));
        self::assertGreaterThan(0, $result['ledger_id']);
        $ledger = Db::name('online_data_correction_ledger')->where('id', $result['ledger_id'])->find();
        self::assertSame('update', $ledger['operation']);
        self::assertSame(100.0, (float)json_decode((string)$ledger['before_json'], true)['amount']);
        self::assertSame(120.5, (float)json_decode((string)$ledger['after_json'], true)['amount']);
        self::assertSame(['amount', 'validation_status'], json_decode((string)$ledger['changed_fields_json'], true));
    }

    public function testDeleteCreatesRestorableTombstoneAndRestoreReplaysSnapshot(): void
    {
        $service = new OnlineDataCorrectionLedgerService();
        $deleted = $service->delete(1, 9, [10], 'duplicate row');

        self::assertSame(0, (int)Db::name('online_daily_data')->where('id', 1)->count());
        $ledger = Db::name('online_data_correction_ledger')->where('id', $deleted['ledger_id'])->find();
        self::assertSame(1, (int)$ledger['restorable']);
        self::assertNull($ledger['restored_at']);

        $restored = $service->restore((int)$deleted['ledger_id'], 11, [10]);

        self::assertSame(1, $restored['id']);
        self::assertSame(100.0, (float)Db::name('online_daily_data')->where('id', 1)->value('amount'));
        self::assertNotSame('', (string)Db::name('online_data_correction_ledger')->where('id', $deleted['ledger_id'])->value('restored_at'));
        self::assertSame(11, (int)Db::name('online_data_correction_ledger')->where('id', $deleted['ledger_id'])->value('restored_by'));
    }

    public function testBatchDeleteRejectsMixedHotelScopeAndRollsBack(): void
    {
        try {
            (new OnlineDataCorrectionLedgerService())->batchDelete([1, 2], 9, [10]);
            self::fail('Expected mixed-scope deletion to fail.');
        } catch (\RuntimeException $e) {
            self::assertSame('online_data_batch_contains_missing_or_forbidden_rows', $e->getMessage());
        }

        self::assertSame(2, (int)Db::name('online_daily_data')->count());
        self::assertSame(0, (int)Db::name('online_data_correction_ledger')->count());
    }

    /** @return array<string, mixed> */
    private function row(int $id, int $hotelId, float $amount): array
    {
        return [
            'id' => $id,
            'tenant_id' => 1,
            'system_hotel_id' => $hotelId,
            'hotel_id' => 'ota-' . $hotelId,
            'source' => 'ctrip',
            'data_date' => '2026-07-14',
            'amount' => $amount,
            'quantity' => 1,
            'book_order_num' => 1,
            'validation_status' => 'verified',
            'raw_data' => '{}',
            'update_time' => '2026-07-15 12:00:00',
        ];
    }

    private function createSchema(): void
    {
        Db::execute('CREATE TABLE online_daily_data (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NULL,
            system_hotel_id INTEGER NOT NULL,
            hotel_id TEXT NULL,
            source TEXT NULL,
            data_date TEXT NULL,
            amount REAL NULL,
            quantity REAL NULL,
            book_order_num INTEGER NULL,
            validation_status TEXT NULL,
            raw_data TEXT NULL,
            update_time TEXT NULL
        )');
        Db::execute('CREATE TABLE online_data_correction_ledger (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            online_data_id INTEGER NOT NULL,
            tenant_id INTEGER NULL,
            system_hotel_id INTEGER NOT NULL,
            operator_id INTEGER NOT NULL,
            operation TEXT NOT NULL,
            changed_fields_json TEXT NULL,
            before_json TEXT NOT NULL,
            after_json TEXT NULL,
            reason TEXT NOT NULL,
            restorable INTEGER NOT NULL DEFAULT 0,
            restored_at TEXT NULL,
            restored_by INTEGER NULL,
            created_at TEXT NOT NULL
        )');
    }
}
