<?php
declare(strict_types=1);

namespace Tests;

use app\service\OnlineDailyDataPersistenceService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OnlineDailyDataSchemaRefreshTest extends TestCase
{
    private array $originalDatabase;
    private array $connections = [];

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
    }

    protected function setUp(): void
    {
        $this->originalDatabase = (array)Config::get('database');
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            Db::connect($connection)->close();
        }
        Config::set($this->originalDatabase, 'database');
        Db::connect(null, true);
    }

    private function database(string $columns): void
    {
        $connection = 'daily_schema_refresh_' . bin2hex(random_bytes(6));
        $this->connections[] = $connection;
        $config = (array)Config::get('database');
        $config['default'] = $connection;
        $config['connections'][$connection] = [
            'type' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'fields_strict' => false,
        ];
        Config::set($config, 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE online_daily_data (id INTEGER PRIMARY KEY, ' . $columns . ')');
    }

    public function testConnectionSwitchUsesItsActualColumnsWithoutBorrowingAnotherSchema(): void
    {
        $this->database('amount REAL, readback_verified INTEGER');
        self::assertSame(['id', 'amount', 'readback_verified'], array_keys(OnlineDailyDataPersistenceService::getColumns()));

        $this->database('quantity REAL, data_period TEXT, is_final INTEGER');
        self::assertSame(['id', 'quantity', 'data_period', 'is_final'], array_keys(OnlineDailyDataPersistenceService::getColumns()));
    }

    public function testMigrationOnTheSameConnectionRefreshesPeriodAndIdentityColumns(): void
    {
        $this->database('amount REAL');
        self::assertArrayNotHasKey('data_period', OnlineDailyDataPersistenceService::getColumns());
        Db::execute('ALTER TABLE online_daily_data ADD COLUMN data_period TEXT');
        Db::execute('ALTER TABLE online_daily_data ADD COLUMN persistence_identity_hash TEXT');
        self::assertArrayHasKey('data_period', OnlineDailyDataPersistenceService::getColumns());
        self::assertArrayHasKey('persistence_identity_hash', OnlineDailyDataPersistenceService::getColumns());
    }

    public function testMissingTableAfterAValidProbeFailsInsteadOfReturningPreviousColumns(): void
    {
        $this->database('amount REAL');
        self::assertArrayHasKey('amount', OnlineDailyDataPersistenceService::getColumns());
        Db::execute('DROP TABLE online_daily_data');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('online_daily_data_schema_unavailable:');
        OnlineDailyDataPersistenceService::getColumns();
    }
}
