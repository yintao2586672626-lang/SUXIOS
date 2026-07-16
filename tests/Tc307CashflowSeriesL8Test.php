<?php
declare(strict_types=1);

namespace Tests;

use app\service\LlmClient;
use app\service\QuantSimulationService;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use think\App;
use think\facade\Config;
use think\facade\Db;
use Throwable;

final class Tc307CashflowSeriesL8Test extends TestCase
{
    private const OWNER_USER_ID = 30701;
    private const OUTSIDER_USER_ID = 30702;
    private const OWNER_TENANT_ID = 3071;
    private const OUTSIDER_TENANT_ID = 3072;
    private const CURRENCY = 'CNY';
    private const TERMINAL_VALUE = 50000.0;

    /** @var list<float> */
    private const CONSTRUCTION_CASHFLOWS = [-120000.0, -80000.0];

    /** @var list<float> */
    private const OPERATION_CASHFLOWS = [30000.0, 35000.0, 40000.0];

    /** @var array<string,mixed> */
    private static array $originalDatabaseConfig = [];
    private static string $sqlitePath = '';

    public static function setUpBeforeClass(): void
    {
        $app = new App();
        $app->initialize();

        self::$originalDatabaseConfig = Config::get('database');
        self::$sqlitePath = sys_get_temp_dir() . '/tc307_cashflow_series_l8_' . getmypid() . '.sqlite';
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

        self::createSchema();
    }

    public static function tearDownAfterClass(): void
    {
        try {
            Db::connect()->close();
        } finally {
            Config::set(self::$originalDatabaseConfig, 'database');
            Db::connect(null, true);
        }

        if (is_file(self::$sqlitePath) && !unlink(self::$sqlitePath)) {
            throw new RuntimeException('Unable to remove TC-307 SQLite fixture.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Db::name('quant_simulation_records')->delete(true);
    }

    /**
     * This is direct local service/SQLite evidence only. It intentionally does
     * not claim browser, HTTP-controller, real OTA, or real investment evidence.
     * Restricted actor scope takes precedence over input-quality processing.
     *
     * @param array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string} $factors
     */
    #[DataProvider('l8VariantProvider')]
    public function testTc307PersistsAuditablePeriodicCashflowSeries(
        string $caseId,
        array $factors
    ): void {
        $service = new Tc307SqliteQuantSimulationService(new Tc307LocalLlmClient());
        $payload = $this->payloadForFactors($caseId, $factors);
        $message = $caseId . ' factors=' . json_encode($factors, JSON_UNESCAPED_SLASHES);

        $this->assertFixtureRepresentsFactors($payload, $factors, $message);

        if ($factors['upstream_state'] === 'failure') {
            $this->assertInjectedUpstreamFailureLeavesNoSnapshot($service, $caseId, $message);
        }

        if ($factors['actor_scope'] === 'restricted') {
            $this->assertTenantIsolationOnPersistedDetail($service, $caseId, $message);
            return;
        }

        if ($factors['data_completeness'] === 'missing_required') {
            $this->assertMissingCurrencyIsRejectedBeforePersistence($service, $payload, $message);
            return;
        }

        $normalized = $this->invokePrivate($service, 'normalizeInput', [$payload['input']]);
        $calculated = $this->invokePrivate($service, 'calculateSimulation', [$normalized]);
        $expectedFreshness = $factors['freshness'] === 'fresh' ? 'fresh' : 'stale';

        $this->assertCashflowContract(
            $calculated,
            (string)$payload['input']['valuation_date'],
            $expectedFreshness,
            $message . ' direct calculation'
        );

        if ($factors['upstream_state'] === 'failure') {
            $before = $this->recordCount();
            try {
                $service->calculateAndSave($payload, self::OWNER_USER_ID);
                self::fail($message . ' injected upstream failure unexpectedly persisted a formal snapshot');
            } catch (Throwable $exception) {
                self::assertStringContainsString('tc307 synthetic upstream write failure', $exception->getMessage(), $message);
            }
            self::assertSame($before, $this->recordCount(), $message . ' failed upstream must be atomic');
            return;
        }

        $saved = $service->calculateAndSave($payload, self::OWNER_USER_ID);
        $id = (int)($saved['id'] ?? 0);
        self::assertGreaterThan(0, $id, $message);

        $detail = $service->detail($id, self::OWNER_USER_ID, false);
        self::assertSame((string)$payload['project_name'], $detail['project_name'], $message);
        $this->assertCashflowContract(
            $detail['result'],
            (string)$payload['input']['valuation_date'],
            'fresh',
            $message . ' saved detail'
        );
        $this->assertPersistedJsonMatchesDetail($id, $detail, $message);
    }

    /**
     * @return array<string,array{0:string,1:array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string}}>
     */
    public static function l8VariantProvider(): array
    {
        return [
            'DX-2449 authorized complete fresh success' => ['DX-2449', self::factors('authorized', 'complete', 'fresh', 'success')],
            'DX-2450 authorized complete stale failure' => ['DX-2450', self::factors('authorized', 'complete', 'stale', 'failure')],
            'DX-2451 authorized missing fresh failure' => ['DX-2451', self::factors('authorized', 'missing_required', 'fresh', 'failure')],
            'DX-2452 authorized missing stale success' => ['DX-2452', self::factors('authorized', 'missing_required', 'stale', 'success')],
            'DX-2453 restricted complete fresh failure' => ['DX-2453', self::factors('restricted', 'complete', 'fresh', 'failure')],
            'DX-2454 restricted complete stale success' => ['DX-2454', self::factors('restricted', 'complete', 'stale', 'success')],
            'DX-2455 restricted missing fresh success' => ['DX-2455', self::factors('restricted', 'missing_required', 'fresh', 'success')],
            'DX-2456 restricted missing stale failure' => ['DX-2456', self::factors('restricted', 'missing_required', 'stale', 'failure')],
        ];
    }

    /**
     * @param array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string} $factors
     * @return array<string,mixed>
     */
    private function payloadForFactors(string $caseId, array $factors): array
    {
        $input = $this->completeInput(
            $factors['freshness'] === 'fresh' ? $this->freshValuationDate() : $this->staleValuationDate()
        );
        if ($factors['data_completeness'] === 'missing_required') {
            unset($input['currency']);
        }

        $projectPrefix = $factors['upstream_state'] === 'failure'
            ? 'TC307_TRIGGER_FAILURE_'
            : 'TC307_SUCCESS_';

        return [
            'project_name' => $projectPrefix . $caseId,
            'model_key' => 'tc307_local_no_network',
            'input' => $input,
        ];
    }

    /** @return array<string,mixed> */
    private function completeInput(string $valuationDate): array
    {
        return [
            'room_count' => 20,
            'decoration_investment' => 120000,
            'equipment_investment' => 80000,
            'pre_opening_cost' => 0,
            'other_investment' => 0,
            'adr' => 300,
            'occupancy_rate' => 70,
            'other_income' => 0,
            'monthly_rent' => 30000,
            'labor_cost' => 40000,
            'utility_cost' => 5000,
            'ota_commission_rate' => 10,
            'consumable_cost' => 5000,
            'maintenance_cost' => 3000,
            'other_fixed_cost' => 2000,
            'valuation_date' => $valuationDate,
            'currency' => self::CURRENCY,
            'construction_cashflows' => self::CONSTRUCTION_CASHFLOWS,
            'operation_cashflows' => self::OPERATION_CASHFLOWS,
            'terminal_value' => self::TERMINAL_VALUE,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string} $factors
     */
    private function assertFixtureRepresentsFactors(
        array $payload,
        array $factors,
        string $message
    ): void {
        $input = $payload['input'];
        self::assertSame(
            $factors['data_completeness'] === 'complete',
            array_key_exists('currency', $input),
            $message . ' completeness'
        );
        self::assertSame(
            $factors['freshness'] === 'fresh' ? $this->freshValuationDate() : $this->staleValuationDate(),
            $input['valuation_date'],
            $message . ' valuation freshness'
        );
        self::assertSame(2, count($input['construction_cashflows']), $message . ' construction periods');
        self::assertSame(3, count($input['operation_cashflows']), $message . ' operation periods');
        self::assertSame(
            $factors['upstream_state'] === 'failure',
            str_starts_with((string)$payload['project_name'], 'TC307_TRIGGER_FAILURE_'),
            $message . ' upstream trigger selector'
        );
    }

    private function assertMissingCurrencyIsRejectedBeforePersistence(
        QuantSimulationService $service,
        array $payload,
        string $message
    ): void {
        $before = $this->recordCount();
        $exception = null;
        try {
            $service->calculateAndSave($payload, self::OWNER_USER_ID);
        } catch (Throwable $caught) {
            $exception = $caught;
        }

        $violations = [];
        if (!$exception instanceof InvalidArgumentException) {
            $violations['validation_exception'] = $exception === null
                ? 'none; incomplete input was accepted'
                : get_class($exception) . ': ' . $exception->getMessage();
        } elseif (!preg_match('/currency|币种|required|missing/i', $exception->getMessage())) {
            $violations['validation_message'] = $exception->getMessage();
        }
        if ($this->recordCount() !== $before) {
            $violations['partial_write_count'] = $this->recordCount() - $before;
        }

        self::assertSame(
            [],
            $violations,
            $message . ' missing-currency violations=' . json_encode($violations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function assertInjectedUpstreamFailureLeavesNoSnapshot(
        QuantSimulationService $service,
        string $caseId,
        string $message
    ): void {
        $trigger = Db::query(
            "SELECT name FROM sqlite_master WHERE type = 'trigger' AND name = 'tc307_fail_upstream_insert'"
        );
        self::assertCount(1, $trigger, $message . ' failure injection trigger');

        $before = $this->recordCount();
        $probe = [
            'project_name' => 'TC307_TRIGGER_FAILURE_PROBE_' . $caseId,
            'model_key' => 'tc307_local_no_network',
            'input' => $this->completeInput($this->freshValuationDate()),
        ];

        try {
            $service->calculateAndSave($probe, self::OWNER_USER_ID);
            self::fail($message . ' upstream-failure probe unexpectedly persisted');
        } catch (Throwable $exception) {
            self::assertStringContainsString('tc307 synthetic upstream write failure', $exception->getMessage(), $message);
        }

        self::assertSame($before, $this->recordCount(), $message . ' trigger failure must leave zero partial snapshot');
    }

    private function assertTenantIsolationOnPersistedDetail(
        QuantSimulationService $service,
        string $caseId,
        string $message
    ): void {
        $ownerPayload = [
            'project_name' => 'TC307_OWNER_SCOPE_' . $caseId,
            'model_key' => 'tc307_local_no_network',
            'input' => $this->completeInput($this->freshValuationDate()),
        ];
        $saved = $service->calculateAndSave($ownerPayload, self::OWNER_USER_ID);
        $id = (int)($saved['id'] ?? 0);
        self::assertGreaterThan(0, $id, $message);
        self::assertSame(
            self::OWNER_TENANT_ID,
            (int)Db::name('quant_simulation_records')->where('id', $id)->value('tenant_id'),
            $message
        );

        $ownerDetail = $service->detail($id, self::OWNER_USER_ID, false);
        self::assertSame($id, (int)$ownerDetail['id'], $message);

        try {
            $service->detail($id, self::OUTSIDER_USER_ID, false);
            self::fail($message . ' outsider unexpectedly read another tenant cashflow record');
        } catch (RuntimeException $exception) {
            self::assertMatchesRegularExpression('/not found|access|存在|访问|权限|无权/i', $exception->getMessage(), $message);
        }
        self::assertSame(1, $this->recordCount(), $message . ' denied read must not mutate records');
    }

    /** @param array<string,mixed> $result */
    private function assertCashflowContract(
        array $result,
        string $valuationDate,
        string $expectedFreshness,
        string $message
    ): void {
        foreach (['valuation_date', 'freshness_status', 'currency', 'terminal_value', 'construction_periods', 'operation_periods', 'cashflow_series'] as $field) {
            self::assertArrayHasKey($field, $result, $message . ' missing ' . $field);
        }

        self::assertSame($valuationDate, $result['valuation_date'], $message);
        self::assertSame($expectedFreshness, $result['freshness_status'], $message);
        self::assertSame(self::CURRENCY, $result['currency'], $message);
        self::assertSame(self::TERMINAL_VALUE, (float)$result['terminal_value'], $message);
        self::assertSame(2, (int)$result['construction_periods'], $message);
        self::assertSame(3, (int)$result['operation_periods'], $message);

        $series = $result['cashflow_series'];
        self::assertIsArray($series, $message);
        self::assertCount(5, $series, $message);
        $expectedValues = [-120000.0, -80000.0, 30000.0, 35000.0, 90000.0];
        $baseDate = new DateTimeImmutable($valuationDate);

        foreach ($series as $period => $row) {
            self::assertIsArray($row, $message . ' period=' . $period);
            self::assertArrayHasKey('period', $row, $message);
            self::assertArrayHasKey('date', $row, $message);
            self::assertArrayHasKey('value', $row, $message);
            self::assertSame($period, (int)$row['period'], $message);
            self::assertSame($baseDate->modify('+' . $period . ' months')->format('Y-m-d'), $row['date'], $message);
            self::assertSame($expectedValues[$period], (float)$row['value'], $message);
        }

        self::assertSame(-80000.0, (float)$series[1]['value'], $message . ' construction values must not be cumulative');
        self::assertSame(35000.0, (float)$series[3]['value'], $message . ' operation values must not be cumulative');
        self::assertSame(
            self::OPERATION_CASHFLOWS[2] + self::TERMINAL_VALUE,
            (float)$series[4]['value'],
            $message . ' terminal value must be included exactly once in the final period'
        );
    }

    /** @param array<string,mixed> $detail */
    private function assertPersistedJsonMatchesDetail(int $id, array $detail, string $message): void
    {
        $row = Db::name('quant_simulation_records')->where('id', $id)->find();
        self::assertIsArray($row, $message);
        self::assertSame(self::OWNER_TENANT_ID, (int)$row['tenant_id'], $message);

        $storedInput = json_decode((string)$row['input_json'], true, 512, JSON_THROW_ON_ERROR);
        $storedResult = json_decode((string)$row['result_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(self::CURRENCY, $storedInput['currency'], $message);
        self::assertSame(self::CONSTRUCTION_CASHFLOWS, array_map('floatval', $storedInput['construction_cashflows']), $message);
        self::assertSame(self::OPERATION_CASHFLOWS, array_map('floatval', $storedInput['operation_cashflows']), $message);
        self::assertSame($detail['result']['cashflow_series'], $storedResult['cashflow_series'], $message);
    }

    private function invokePrivate(object $service, string $methodName, array $arguments): array
    {
        $method = new ReflectionMethod($service, $methodName);
        $method->setAccessible(true);
        $result = $method->invokeArgs($service, $arguments);
        self::assertIsArray($result);

        return $result;
    }

    private function recordCount(): int
    {
        return (int)Db::name('quant_simulation_records')->count();
    }

    private function freshValuationDate(): string
    {
        return date('Y-m-01');
    }

    private function staleValuationDate(): string
    {
        return date('Y-m-01', strtotime('-13 months'));
    }

    /** @return array{actor_scope:string,data_completeness:string,freshness:string,upstream_state:string} */
    private static function factors(
        string $actorScope,
        string $dataCompleteness,
        string $freshness,
        string $upstreamState
    ): array {
        return [
            'actor_scope' => $actorScope,
            'data_completeness' => $dataCompleteness,
            'freshness' => $freshness,
            'upstream_state' => $upstreamState,
        ];
    }

    private static function createSchema(): void
    {
        Db::execute(<<<'SQL'
CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    tenant_id INTEGER,
    hotel_id INTEGER
)
SQL);
        Db::name('users')->insertAll([
            ['id' => self::OWNER_USER_ID, 'tenant_id' => self::OWNER_TENANT_ID, 'hotel_id' => self::OWNER_TENANT_ID],
            ['id' => self::OUTSIDER_USER_ID, 'tenant_id' => self::OUTSIDER_TENANT_ID, 'hotel_id' => self::OUTSIDER_TENANT_ID],
        ]);

        Db::execute(<<<'SQL'
CREATE TABLE quant_simulation_records (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER,
    project_name TEXT NOT NULL DEFAULT '',
    input_json TEXT,
    result_json TEXT,
    scenarios_json TEXT,
    risk_hints_json TEXT,
    monthly_net_cashflow REAL NOT NULL DEFAULT 0,
    payback_months REAL,
    risk_level TEXT NOT NULL DEFAULT '',
    created_by INTEGER NOT NULL DEFAULT 0,
    created_at TEXT,
    updated_at TEXT,
    deleted_at TEXT
)
SQL);
        Db::execute(<<<'SQL'
CREATE TRIGGER tc307_fail_upstream_insert
BEFORE INSERT ON quant_simulation_records
WHEN NEW.project_name LIKE 'TC307_TRIGGER_FAILURE_%'
BEGIN
    SELECT RAISE(ABORT, 'tc307 synthetic upstream write failure');
END
SQL);
    }
}

final class Tc307SqliteQuantSimulationService extends QuantSimulationService
{
    public function ensureTable(): void
    {
        // The isolated SQLite schema above mirrors only this production path.
    }
}

final class Tc307LocalLlmClient extends LlmClient
{
    public function __construct()
    {
    }

    public function createJsonResponse(
        array $messages,
        array $schema,
        string $modelKey = 'deepseek_v4_default'
    ): array {
        return [
            'summary' => 'TC-307 deterministic local investment interpretation.',
            'decision' => 'Requires human investment review.',
            'recommendations' => [],
            'watch_points' => [],
            'assumptions' => ['Synthetic isolated cashflow fixture; not a real investment conclusion.'],
        ];
    }
}
