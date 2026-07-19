<?php
declare(strict_types=1);

namespace Tests;

use app\model\OperationLog;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class OperationLogRecordIntegrityTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $databasePath = '';

    public static function setUpBeforeClass(): void
    {
        (new App())->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'operation_log_integrity_' . getmypid() . '.sqlite';
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
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT)');
        Db::execute('CREATE TABLE users (id INTEGER PRIMARY KEY, tenant_id INTEGER NULL, hotel_id INTEGER NULL)');
        Db::execute('CREATE TABLE operation_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NULL,
            user_id INTEGER NULL,
            hotel_id INTEGER NULL,
            module TEXT,
            action TEXT,
            description TEXT,
            error_info TEXT NULL,
            extra_data TEXT NULL,
            ip TEXT NULL,
            user_agent TEXT NULL,
            create_time TEXT DEFAULT CURRENT_TIMESTAMP
        )');
        Db::name('hotels')->insert(['id' => 118, 'tenant_id' => 42, 'name' => '测试门店']);
        Db::name('users')->insert(['id' => 7, 'tenant_id' => 42, 'hotel_id' => 118]);
    }

    public function testPersistenceBoundaryRedactsSecretsAndUsesTrustedHotelTenant(): void
    {
        $log = OperationLog::record(
            'online_data',
            'fetch_custom',
            'GET https://ebooking.ctrip.com/api?sessionid=SESSION-SENTINEL&token=TOKEN-SENTINEL',
            7,
            118,
            'sid=SID-SENTINEL jsessionid=JSESSION-SENTINEL',
            [
                'tenant_id' => 999,
                'outcome' => 'failed',
                'authorization' => 'Bearer AUTH-SENTINEL',
                'nested' => ['cookie' => 'COOKIE-SENTINEL'],
            ]
        );

        $row = Db::name('operation_logs')->where('id', (int)$log->id)->find();
        self::assertIsArray($row);
        self::assertSame(42, (int)$row['tenant_id']);
        self::assertSame(118, (int)$row['hotel_id']);
        self::assertSame(7, (int)$row['user_id']);

        $encoded = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach ([
            'SESSION-SENTINEL',
            'TOKEN-SENTINEL',
            'SID-SENTINEL',
            'JSESSION-SENTINEL',
            'AUTH-SENTINEL',
            'COOKIE-SENTINEL',
        ] as $secret) {
            self::assertStringNotContainsString($secret, (string)$encoded);
        }

        $extra = json_decode((string)$row['extra_data'], true);
        self::assertSame(1, $extra['audit_schema_version'] ?? null);
        self::assertSame('failed', $extra['outcome'] ?? null);
        self::assertSame(7, $extra['actor_user_id'] ?? null);
        self::assertSame(42, $extra['tenant_id'] ?? null);
        self::assertSame(118, $extra['hotel_id'] ?? null);
    }
}
