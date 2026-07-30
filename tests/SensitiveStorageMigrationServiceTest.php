<?php
declare(strict_types=1);

namespace Tests;

use app\model\SystemConfig;
use app\service\SensitiveStorageMigrationService;
use app\service\SensitiveValueCipher;
use app\service\WechatRobotWebhookSecret;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use think\App;
use think\facade\Config;
use think\facade\Db;

final class SensitiveStorageMigrationServiceTest extends TestCase
{
    private static array $originalDatabaseConfig = [];
    private static string $databasePath = '';
    private SensitiveValueCipher $cipher;

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();
        self::$originalDatabaseConfig = Config::get('database');
        self::$databasePath = sys_get_temp_dir() . '/sensitive_storage_' . getmypid() . '.sqlite';
        @unlink(self::$databasePath);

        $database = self::$originalDatabaseConfig;
        $database['default'] = 'sqlite';
        $database['connections']['sqlite'] = [
            'type' => 'sqlite',
            'database' => self::$databasePath,
            'prefix' => '',
            'fields_strict' => false,
        ];
        Config::set($database, 'database');
        Db::connect(null, true);
    }

    public static function tearDownAfterClass(): void
    {
        Config::set(self::$originalDatabaseConfig, 'database');
        Db::connect(null, true);
        @unlink(self::$databasePath);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->cipher = new SensitiveValueCipher(base64_encode(str_repeat('m', 32)), 'migration-key');
        Db::execute('CREATE TABLE IF NOT EXISTS system_config (id INTEGER PRIMARY KEY AUTOINCREMENT, config_key VARCHAR(100) NOT NULL UNIQUE, config_value TEXT, description VARCHAR(255), create_time DATETIME, update_time DATETIME)');
        Db::execute('CREATE TABLE IF NOT EXISTS competitor_wechat_robot (id INTEGER PRIMARY KEY AUTOINCREMENT, webhook VARCHAR(512))');
        Db::name('system_config')->delete(true);
        Db::name('competitor_wechat_robot')->delete(true);
        $reflection = new ReflectionClass(SystemConfig::class);
        $cache = $reflection->getProperty('valueCache');
        $cache->setAccessible(true);
        $cache->setValue(null, []);
    }

    public function testDryRunReportsPendingRowsWithoutWritingOrLeakingSecrets(): void
    {
        $this->seedPlaintext();
        $before = $this->snapshot();

        $summary = $this->service()->run(false);

        self::assertSame('dry-run', $summary['mode']);
        self::assertSame('migration_required', $summary['status']);
        self::assertSame(2, $summary['pending_count']);
        self::assertSame($before, $this->snapshot());
        $encoded = json_encode($summary, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('legacy-mail-secret', $encoded);
        self::assertStringNotContainsString('legacy-webhook-key', $encoded);
    }

    public function testExecuteEncryptsBothStoresAndIsIdempotent(): void
    {
        $this->seedPlaintext();

        $summary = $this->service()->run(true);
        self::assertSame('completed', $summary['status']);
        self::assertSame(2, $summary['migrated_count']);

        $configStored = (string)Db::name('system_config')->where('config_key', SystemConfig::KEY_NOTIFY_EMAIL_PASS)->value('config_value');
        $webhookStored = (string)Db::name('competitor_wechat_robot')->value('webhook');
        self::assertStringNotContainsString('legacy-mail-secret', $configStored);
        self::assertStringNotContainsString('legacy-webhook-key', $webhookStored);
        self::assertSame(
            'legacy-mail-secret',
            SystemConfig::decodeValueFromStorage(SystemConfig::KEY_NOTIFY_EMAIL_PASS, $configStored, $this->cipher)
        );
        self::assertStringContainsString(
            'legacy-webhook-key',
            (new WechatRobotWebhookSecret($this->cipher))->reveal(
                $webhookStored,
                (int)Db::name('competitor_wechat_robot')->value('id')
            )
        );

        $second = $this->service()->run(true);
        self::assertSame('completed', $second['status']);
        self::assertSame(0, $second['migrated_count']);
        self::assertSame(2, $second['already_protected_count']);
    }

    public function testExecuteStopsBeforeWritingWhenPreflightFindsInvalidCiphertext(): void
    {
        Db::name('system_config')->insert([
            'config_key' => SystemConfig::KEY_NOTIFY_EMAIL_PASS,
            'config_value' => 'legacy-mail-secret',
        ]);
        Db::name('competitor_wechat_robot')->insert([
            'webhook' => 'suxi-secret:v1:not-valid-ciphertext',
        ]);
        $before = $this->snapshot();

        $summary = $this->service()->run(true);

        self::assertSame('execute', $summary['mode']);
        self::assertSame('blocked', $summary['status']);
        self::assertSame('preflight_failed', $summary['reason_code']);
        self::assertSame(0, $summary['migrated_count']);
        self::assertGreaterThan(0, $summary['failed_count']);
        self::assertSame($before, $this->snapshot());
    }

    public function testExecuteRollsBackEarlierWritesWhenAStorageUpdateFails(): void
    {
        $this->seedPlaintext();
        $before = $this->snapshot();
        Db::execute(<<<'SQL'
CREATE TRIGGER fail_sensitive_webhook_update
BEFORE UPDATE OF webhook ON competitor_wechat_robot
BEGIN
    SELECT RAISE(ABORT, 'forced sensitive migration rollback');
END
SQL);

        try {
            $summary = $this->service()->run(true);
        } finally {
            Db::execute('DROP TRIGGER IF EXISTS fail_sensitive_webhook_update');
        }

        self::assertSame('execute', $summary['mode']);
        self::assertSame('rolled_back', $summary['status']);
        self::assertSame('transaction_rolled_back', $summary['reason_code']);
        self::assertSame(0, $summary['migrated_count']);
        self::assertGreaterThan(0, $summary['failed_count']);
        self::assertSame(0, $summary['sources']['system_config']['migrated_count']);
        self::assertSame('rolled_back', $summary['sources']['system_config']['status']);
        self::assertSame($before, $this->snapshot());
    }

    public function testDryRunBlocksWhenCoreSystemConfigTableCannotBeRead(): void
    {
        Db::execute('DROP TABLE system_config');

        $summary = $this->service()->run(false);

        self::assertSame('dry-run', $summary['mode']);
        self::assertSame('blocked', $summary['status']);
        self::assertSame(1, $summary['failed_count']);
        self::assertFalse($summary['sources']['system_config']['installed']);
        self::assertSame('scan_failed', $summary['sources']['system_config']['status']);
    }

    public function testDryRunBlocksWhenWechatRobotStorageCannotBeRead(): void
    {
        Db::execute('DROP TABLE competitor_wechat_robot');

        $summary = $this->service()->run(false);

        self::assertSame('blocked', $summary['status']);
        self::assertSame(1, $summary['failed_count']);
        self::assertFalse($summary['sources']['competitor_wechat_robot']['installed']);
        self::assertSame('scan_failed', $summary['sources']['competitor_wechat_robot']['status']);
    }

    public function testSystemConfigSetValueEncryptsNewSensitiveWritesAtRest(): void
    {
        $originalKey = getenv('SUXI_SECRET_KEY_B64');
        $originalKeyId = getenv('SUXI_SECRET_KEY_ID');
        putenv('SUXI_SECRET_KEY_B64=' . base64_encode(str_repeat('n', 32)));
        putenv('SUXI_SECRET_KEY_ID=system-config-write-test');

        try {
            self::assertTrue(SystemConfig::setValue(SystemConfig::KEY_AMAP_WEB_API_KEY, 'amap-secret'));
            $stored = (string)Db::name('system_config')
                ->where('config_key', SystemConfig::KEY_AMAP_WEB_API_KEY)
                ->value('config_value');
            self::assertStringNotContainsString('amap-secret', $stored);
            self::assertStringStartsWith('suxi-secret:v1:', $stored);
            self::assertSame('amap-secret', SystemConfig::getValue(SystemConfig::KEY_AMAP_WEB_API_KEY));
            self::assertTrue(SystemConfig::setValue(SystemConfig::KEY_AMAP_WEB_API_KEY, ''));
            self::assertSame('', (string)Db::name('system_config')
                ->where('config_key', SystemConfig::KEY_AMAP_WEB_API_KEY)
                ->value('config_value'));
        } finally {
            $this->restoreEnvironment('SUXI_SECRET_KEY_B64', $originalKey);
            $this->restoreEnvironment('SUXI_SECRET_KEY_ID', $originalKeyId);
        }
    }

    private function seedPlaintext(): void
    {
        Db::name('system_config')->insert([
            'config_key' => SystemConfig::KEY_NOTIFY_EMAIL_PASS,
            'config_value' => 'legacy-mail-secret',
        ]);
        Db::name('competitor_wechat_robot')->insert([
            'webhook' => 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=legacy-webhook-key',
        ]);
    }

    private function service(): SensitiveStorageMigrationService
    {
        return new SensitiveStorageMigrationService($this->cipher);
    }

    private function snapshot(): array
    {
        return [
            'system_config' => Db::name('system_config')->order('id')->select()->toArray(),
            'competitor_wechat_robot' => Db::name('competitor_wechat_robot')->order('id')->select()->toArray(),
        ];
    }

    private function restoreEnvironment(string $key, string|false $value): void
    {
        if ($value === false) {
            putenv($key);
            return;
        }
        putenv($key . '=' . $value);
    }
}
