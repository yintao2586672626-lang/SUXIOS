<?php
declare(strict_types=1);

namespace Tests;

use app\service\AiDailyReportService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;
use think\facade\Db;

/** Synthetic prefixed SQLite only; verifies intent reuse never escapes tenant scope. */
final class AiDailyReportExecutionIntentPrefixTest extends TestCase
{
    private array $originalConfig;
    private string $path;

    public static function setUpBeforeClass(): void
    {
        (new App(dirname(__DIR__)))->initialize();
    }

    protected function setUp(): void
    {
        $this->originalConfig = Config::get('database', []);
        $this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'suxi-intent-prefix-' . bin2hex(random_bytes(6)) . '.sqlite';
        Config::set([
            'default' => 'sqlite',
            'connections' => ['sqlite' => [
                'type' => 'sqlite', 'database' => $this->path, 'prefix' => 'sux_',
                'fields_strict' => false, 'debug' => false,
            ]],
        ], 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE sux_hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT)');
        Db::execute("INSERT INTO sux_hotels VALUES (904,9004,'SYNTHETIC prefix hotel')");
        Db::execute('CREATE TABLE sux_operation_execution_intents (
            id INTEGER PRIMARY KEY AUTOINCREMENT, source_module TEXT, source_record_id INTEGER,
            hotel_id INTEGER, tenant_id INTEGER, evidence_json TEXT, deleted_at TEXT NULL
        )');
        Db::execute('CREATE TABLE operation_execution_intents (
            id INTEGER PRIMARY KEY AUTOINCREMENT, source_module TEXT, source_record_id INTEGER,
            hotel_id INTEGER, tenant_id INTEGER, evidence_json TEXT, deleted_at TEXT NULL
        )');
    }

    protected function tearDown(): void
    {
        Db::connect()->close();
        @unlink($this->path);
        Config::set($this->originalConfig, 'database');
        Db::connect(null, true);
    }

    public function testPrefixedLookupRejectsZeroAndForeignTenantBeforeReuse(): void
    {
        $zero = $this->insertIntent(0, 'same-key');
        $this->insertIntent(9005, 'same-key');
        $valid = $this->insertIntent(9004, 'same-key');
        Db::table('operation_execution_intents')->insert([
            'source_module' => 'ai_daily_report', 'source_record_id' => 77,
            'hotel_id' => 904, 'tenant_id' => 9004,
            'evidence_json' => json_encode(['action_idempotency_key' => 'same-key'], JSON_THROW_ON_ERROR),
        ]);

        $result = $this->findIntent($zero);
        self::assertIsArray($result);
        self::assertSame($valid, (int)$result['id']);
        self::assertSame(9004, (int)$result['tenant_id']);
    }

    public function testPrefixedLookupReturnsMissingWhenOnlyInvalidTenantRowsExist(): void
    {
        $zero = $this->insertIntent(0, 'same-key');
        $this->insertIntent(9005, 'same-key');
        self::assertNull($this->findIntent($zero));
    }

    public function testLinkedIntentForAnotherActionCannotBypassActionIdentity(): void
    {
        $wrong = $this->insertIntent(9004, 'other-action-key');
        self::assertNull($this->findIntent($wrong));
        $valid = $this->insertIntent(9004, 'same-key');
        self::assertSame($valid, (int)$this->findIntent($wrong)['id']);
    }

    public function testLinkedLegacyIntentRequiresTheSameActionIndex(): void
    {
        $wrong = (int)Db::name('operation_execution_intents')->insertGetId([
            'source_module' => 'ai_daily_report', 'source_record_id' => 77,
            'hotel_id' => 904, 'tenant_id' => 9004,
            'evidence_json' => json_encode(['action_index' => 1], JSON_THROW_ON_ERROR),
        ]);
        self::assertNull($this->findIntent($wrong));
        $valid = (int)Db::name('operation_execution_intents')->insertGetId([
            'source_module' => 'ai_daily_report', 'source_record_id' => 77,
            'hotel_id' => 904, 'tenant_id' => 9004,
            'evidence_json' => json_encode(['action_index' => 0], JSON_THROW_ON_ERROR),
        ]);
        self::assertSame($valid, (int)$this->findIntent($valid)['id']);
        self::assertSame($valid, (int)$this->findIntent($wrong)['id']);
    }

    private function insertIntent(int $tenantId, string $key): int
    {
        return (int)Db::name('operation_execution_intents')->insertGetId([
            'source_module' => 'ai_daily_report', 'source_record_id' => 77,
            'hotel_id' => 904, 'tenant_id' => $tenantId,
            'evidence_json' => json_encode(['action_idempotency_key' => $key], JSON_THROW_ON_ERROR),
        ]);
    }

    private function findIntent(int $linkedId): ?array
    {
        $method = new \ReflectionMethod(AiDailyReportService::class, 'findDailyReportActionIntent');
        return $method->invoke(
            new AiDailyReportService(),
            77,
            904,
            0,
            'same-key',
            ['execution_intent_id' => $linkedId]
        );
    }
}
