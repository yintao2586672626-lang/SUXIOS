<?php
declare(strict_types=1);

namespace Tests;

use app\controller\AiDailyReport;
use app\service\AiDailyReportService;
use app\service\OperationManagementService;
use app\service\OtaCompetitionAnalysisBundleService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\AiEvidenceFixture;
use think\App;
use think\facade\Config;
use think\facade\Db;

/** Physical SQLite tables only; no application database or authenticated account. */
final class AiDailyReportPrefixStorageTest extends TestCase
{
    private array $originalConfig;
    private array $testConfig;
    private array $databasePaths = [];
    private string $connection;

    public static function setUpBeforeClass(): void
    {
        (new App(dirname(__DIR__)))->initialize();
    }

    protected function setUp(): void
    {
        $this->originalConfig = Config::get('database', []);
        $this->testConfig = ['default' => '', 'connections' => []];
        $this->connection = $this->addConnection('sux_');
        $this->activate($this->connection);
        $this->createSchema('sux_');
    }

    protected function tearDown(): void
    {
        foreach ($this->databasePaths as $connection => $path) {
            Db::connect($connection)->close();
            @unlink($path);
        }
        Config::set($this->originalConfig, 'database');
        Db::connect(null, true);
    }

    private function addConnection(string $prefix): string
    {
        $name = 'ai_prefix_' . getmypid() . '_' . bin2hex(random_bytes(6));
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $name . '.sqlite';
        $this->databasePaths[$name] = $path;
        $this->testConfig['connections'][$name] = [
            'type' => 'sqlite', 'database' => $path, 'prefix' => $prefix, 'fields_strict' => true,
        ];
        return $name;
    }

    private function activate(string $connection): void
    {
        $this->testConfig['default'] = $connection;
        Config::set($this->testConfig, 'database');
        Db::connect(null, true);
    }

    private function createSchema(string $prefix, bool $tenantColumns = true): void
    {
        $hotelTenant = $tenantColumns ? ', tenant_id INTEGER' : '';
        $reportTenant = $tenantColumns ? ', tenant_id INTEGER' : '';
        Db::execute('CREATE TABLE ' . $prefix . 'hotels (id INTEGER PRIMARY KEY, name TEXT' . $hotelTenant . ')');
        Db::execute('CREATE TABLE ' . $prefix . 'ai_daily_reports (id INTEGER PRIMARY KEY AUTOINCREMENT,
            hotel_id INTEGER, report_date TEXT, status TEXT, generation_mode TEXT,
            model_key TEXT, model_status TEXT, model_message TEXT, summary TEXT,
            yesterday_result_json TEXT, abnormal_metrics_json TEXT, competitor_changes_json TEXT,
            data_gaps_json TEXT, recommended_actions_json TEXT, source_refs_json TEXT, snapshot_json TEXT,
            created_by INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT NULL,
            input_fingerprint TEXT, prompt_version TEXT, cache_hit_count INTEGER' . $reportTenant . ',
            UNIQUE(hotel_id, report_date))');
        foreach ([904 => 9004, 905 => 9005] as $hotelId => $tenantId) {
            $row = ['id' => $hotelId, 'name' => 'SYNTHETIC prefix hotel'];
            if ($tenantColumns) $row['tenant_id'] = $tenantId;
            Db::table($prefix . 'hotels')->insert($row);
        }
    }

    private function seed(string $date = '2026-09-08', array $overrides = [], string $prefix = 'sux_'): int
    {
        return (int)Db::table($prefix . 'ai_daily_reports')->insertGetId(array_merge([
            'hotel_id' => 904, 'tenant_id' => 9004, 'report_date' => $date,
            'summary' => 'SYNTHETIC saved prefix report', 'status' => 'generated',
            'generation_mode' => 'rule', 'model_status' => 'not_requested', 'created_by' => 4,
            'yesterday_result_json' => '{}', 'abnormal_metrics_json' => '[]',
            'competitor_changes_json' => '[]', 'recommended_actions_json' => '[]',
            'data_gaps_json' => '[{"code":"synthetic_source_unverified","data_status":"unverified"}]',
            'source_refs_json' => '[{"key":"synthetic#904#ctrip#' . $date . '","source":"synthetic","hotel_id":904,"platform":"ctrip","scope":"ota_channel","data_date":"' . $date . '","readback_verified":false}]',
            'snapshot_json' => '{}',
        ], $overrides));
    }

    private function controller(AiDailyReportService $service, array $params = [], array $permitted = [904]): AiDailyReport
    {
        $reflection = new \ReflectionClass(AiDailyReport::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('service')->setValue($controller, $service);
        $reflection->getProperty('currentUser')->setValue($controller, new class($permitted) {
            public function __construct(private array $hotelIds) {}
            public function getPermittedHotelIds(): array { return $this->hotelIds; }
        });
        $reflection->getProperty('request')->setValue($controller, new class($params) {
            public function __construct(private array $params) {}
            public function param(string $key, mixed $default = null): mixed { return $this->params[$key] ?? $default; }
            public function get(): array { return $this->params; }
        });
        return $controller;
    }

    public function testPrefixedStoredReportListLatestAndDetailKeepScopeAndQuality(): void
    {
        $id = $this->seed();
        $this->seed('2026-09-09');
        $controller = $this->controller(new AiDailyReportService(), ['hotel_id' => 904, 'report_date' => '2026-09-08']);
        $list = $controller->index();
        $latest = $controller->latest();
        $read = $controller->read($id);
        self::assertSame(['list' => 200, 'latest' => 200, 'detail' => 200], [
            'list' => $list->getCode(), 'latest' => $latest->getCode(), 'detail' => $read->getCode(),
        ]);
        $rows = [$list->getData()['data']['list'][0], $latest->getData()['data']['report'], $read->getData()['data']];
        foreach ($rows as $row) {
            self::assertSame($id, $row['id']);
            self::assertSame(904, $row['hotel_id']);
            self::assertSame(9004, (int)$row['tenant_id']);
            self::assertSame('2026-09-08', $row['report_date']);
            self::assertSame('legacy_unverified', $row['evidence_readback_status']);
            self::assertContains('synthetic_source_unverified', array_column($row['data_gaps'], 'code'));
            self::assertSame('ctrip', $row['source_refs'][0]['platform']);
            self::assertSame('2026-09-08', $row['source_refs'][0]['data_date']);
            self::assertFalse($row['source_refs'][0]['readback_verified']);
        }
        self::assertSame(1, $list->getData()['data']['pagination']['total']);
    }

    public function testPrefixedEmptyStatesAreSuccessfulAndDateDoesNotFallBack(): void
    {
        $this->seed();
        $controller = $this->controller(new AiDailyReportService(), ['hotel_id' => 904, 'report_date' => '2026-09-07']);
        $list = $controller->index();
        $latest = $controller->latest();
        self::assertSame(200, $list->getCode());
        self::assertSame('ok', $list->getData()['data']['data_status']);
        self::assertSame([], $list->getData()['data']['list']);
        self::assertSame(0, $list->getData()['data']['pagination']['total']);
        self::assertSame(200, $latest->getCode());
        self::assertSame('pending', $latest->getData()['data']['data_status']);
        self::assertNull($latest->getData()['data']['report']);
        self::assertSame(404, $controller->read(999)->getCode());
    }

    #[DataProvider('invalidDates')]
    public function testPrefixedInvalidDatesRemainCorrectable422(string $date): void
    {
        $controller = $this->controller(new AiDailyReportService(), ['hotel_id' => 904, 'report_date' => $date]);
        foreach ([$controller->index(), $controller->latest()] as $response) {
            self::assertSame(422, $response->getCode());
            self::assertSame('date is invalid', $response->getData()['message']);
        }
    }

    public static function invalidDates(): array
    {
        return [['tomorrow'], ['2026-02-30'], ['2026-9-30']];
    }

    public function testPrefixedTenantAndHotelScopeRejectNullZeroTransferredAndForeignRows(): void
    {
        $valid = $this->seed();
        $denied = [
            $this->seed('2026-09-09', ['tenant_id' => 9005]),
            $this->seed('2026-09-10', ['tenant_id' => null]),
            $this->seed('2026-09-11', ['tenant_id' => 0]),
            $this->seed('2026-09-12', ['hotel_id' => 905, 'tenant_id' => 9005]),
        ];
        $service = new AiDailyReportService();
        self::assertSame([$valid], array_column($service->list([904], 904)['list'], 'id'));
        self::assertSame($valid, $service->latest([904], 904)['report']['id']);
        foreach ($denied as $id) {
            self::assertNull($service->read($id, [904]));
            self::assertSame(404, $this->controller($service)->read($id)->getCode());
        }
        self::assertSame(403, $this->controller($service, ['hotel_id' => 905])->index()->getCode());
        Db::name('hotels')->where('id', 904)->update(['tenant_id' => 9006]);
        self::assertNull($service->read($valid, [904]));
        self::assertSame([], $service->list([904], 904)['list']);
        self::assertNull($service->latest([904], 904)['report']);
    }

    public function testUnprefixedShadowSchemaDoesNotDisablePrefixedTenantIsolation(): void
    {
        $this->createSchema('', false);
        $valid = $this->seed();
        $foreign = $this->seed('2026-09-09', ['tenant_id' => 9005]);
        $service = new AiDailyReportService();
        self::assertSame([$valid], array_column($service->list([904], 904)['list'], 'id'));
        self::assertNull($service->read($foreign, [904]));
        self::assertSame($valid, $service->latest([904], 904)['report']['id']);
    }

    public function testPrefixedZeroAndNullTenantKeepExistingLegacyEqualitySemantics(): void
    {
        Db::name('hotels')->where('id', 904)->update(['tenant_id' => 0]);
        $zero = $this->seed(overrides: ['tenant_id' => 0]);
        $service = new AiDailyReportService();
        self::assertSame($zero, $service->read($zero, [904])['id']);
        self::assertSame([$zero], array_column($service->list([904], 904)['list'], 'id'));
        self::assertSame($zero, $service->latest([904], 904)['report']['id']);
        Db::name('hotels')->where('id', 904)->update(['tenant_id' => null]);
        $null = $this->seed('2026-09-09', ['tenant_id' => null]);
        self::assertNull($service->read($null, [904]));
        self::assertSame([], $service->list([904], 904)['list']);
        self::assertNull($service->latest([904], 904)['report']);
    }

    public function testSameSchemaUnprefixedShadowTablesCannotBreakPrefixedReads(): void
    {
        $this->createSchema('');
        $valid = $this->seed();
        $controller = $this->controller(new AiDailyReportService(), ['hotel_id' => 904]);
        foreach ([$controller->index(), $controller->latest(), $controller->read($valid)] as $response) {
            self::assertSame(200, $response->getCode());
        }
        self::assertSame($valid, $controller->read($valid)->getData()['data']['id']);
    }

    public function testTwoConnectionsAndReconfiguredPrefixUseTheirOwnPhysicalSchema(): void
    {
        $valid = $this->seed();
        $this->seed('2026-09-09', ['tenant_id' => 9005]);
        $service = new AiDailyReportService();
        self::assertSame([$valid], array_column($service->list([904], 904)['list'], 'id'));
        $other = $this->addConnection('');
        $this->activate($other);
        $this->createSchema('');
        $otherId = $this->seed('2026-09-08', ['summary' => 'SYNTHETIC second connection'], '');
        self::assertSame('SYNTHETIC second connection', $service->read($otherId, [904])['summary']);
        $this->activate($this->connection);
        self::assertSame('SYNTHETIC saved prefix report', $service->read($valid, [904])['summary']);
        $this->createSchema('legacy_', false);
        Db::table('legacy_ai_daily_reports')->insert(['hotel_id' => 904, 'report_date' => '2026-09-08',
            'summary' => 'SYNTHETIC legacy schema', 'snapshot_json' => '{}']);
        Db::connect()->close();
        $this->testConfig['connections'][$this->connection]['prefix'] = 'legacy_';
        $this->activate($this->connection);
        self::assertSame('SYNTHETIC legacy schema', $service->latest([904], 904)['report']['summary']);
        self::assertSame(1, $service->list([904], 904)['pagination']['total']);
        self::assertSame('legacy_unverified', $service->read(1, [904])['evidence_readback_status']);
        Db::connect()->close();
        $this->testConfig['connections'][$this->connection]['prefix'] = 'sux_';
        $this->activate($this->connection);
        self::assertSame([$valid], array_column($service->list([904], 904)['list'], 'id'));
    }

    public function testPrefixedGenuineDatabaseFailureRemains503ForAllReadRoutes(): void
    {
        Db::execute('ALTER TABLE sux_ai_daily_reports RENAME TO sux_ai_daily_reports_healthy');
        Db::execute('CREATE VIEW sux_ai_daily_reports AS SELECT * FROM sux_missing_dependency');
        $controller = $this->controller(new AiDailyReportService(), ['hotel_id' => 904, 'report_date' => '2026-09-08']);
        foreach ([$controller->index(), $controller->latest(), $controller->read(1)] as $response) {
            self::assertSame(503, $response->getCode());
            $data = $response->getData()['data'];
            self::assertSame('blocked', $data['status']);
            self::assertSame('read_failed', $data['data_status']);
            self::assertSame('ai_daily_reports_read_failed', $data['reason_code']);
        }
    }

    public function testPrefixedGenerateSaveRetryAndExactReadbackPreserveSyntheticEvidence(): void
    {
        $operation = $this->createMock(OperationManagementService::class);
        $operation->method('fullData')->willReturn(['summary' => ['data_status' => 'missing'],
            'ota' => ['data_status' => 'missing'], 'competitors' => ['data_status' => 'missing']]);
        $operation->method('rootCause')->willReturn([]);
        $operation->method('executionFlow')->willReturn([]);
        $service = new AiDailyReportService(operationService: $operation,
            competitionBundleService: new OtaCompetitionAnalysisBundleService(ctripReader: static fn() => []),
            temporalOverviewLoader: static fn() => [],
            evidenceFactLoader: static fn($id, $date) => AiEvidenceFixture::closure($date),
            evidenceKnowledgeLoader: static fn() => ['hotel_id' => 904, 'status' => 'empty', 'entries' => []]);
        $saved = $service->generate([904], 904, '2026-09-08', 4, ['use_llm' => false]);
        $retried = $service->generate([904], 904, '2026-09-08', 4, ['use_llm' => false]);
        $controller = $this->controller($service, ['hotel_id' => 904, 'report_date' => '2026-09-08']);
        foreach ([$retried, $controller->read($saved['id'])->getData()['data'],
            $controller->latest()->getData()['data']['report'], $controller->index()->getData()['data']['list'][0]] as $row) {
            self::assertSame($saved['id'], $row['id']);
            self::assertSame($saved['evidence_snapshot'], $row['evidence_snapshot']);
            self::assertSame($saved['data_gaps'], $row['data_gaps']);
            self::assertSame($saved['final_text'], $row['final_text']);
            self::assertSame('exact_readback_verified', $row['evidence_readback_status']);
            self::assertTrue($row['competition_bundle_readback']['exact_readback_verified']);
        }
        self::assertSame(1, Db::name('ai_daily_reports')->count());
        self::assertSame(9004, (int)Db::name('ai_daily_reports')->value('tenant_id'));
        self::assertSame('synthetic', $saved['evidence_snapshot']['fact_pack']['dataset_kind']);
    }

    public function testPrefixedExecutionReadbackKeepsOnlySameTenantHotelAndReport(): void
    {
        $actions = array_map(static fn(int $id): array => [
            'title' => 'SYNTHETIC execution ' . $id, 'action_type' => 'promotion', 'execution_intent_id' => $id,
        ], [41, 42, 43, 44]);
        $reportId = $this->seed(overrides: ['recommended_actions_json' => json_encode($actions, JSON_THROW_ON_ERROR)]);
        Db::execute('CREATE TABLE sux_operation_execution_intents (id INTEGER PRIMARY KEY, tenant_id INTEGER,
            hotel_id INTEGER, source_module TEXT, source_record_id INTEGER, deleted_at TEXT NULL)');
        foreach ([[41, 9004, 904, $reportId], [42, 9005, 904, $reportId],
            [43, 9005, 905, $reportId], [44, 9004, 904, 999]] as [$id, $tenant, $hotel, $report]) {
            Db::name('operation_execution_intents')->insert(['id' => $id, 'tenant_id' => $tenant, 'hotel_id' => $hotel,
                'source_module' => 'ai_daily_report', 'source_record_id' => $report]);
        }
        $service = new AiDailyReportService();
        foreach ([$service->read($reportId, [904]), $service->list([904], 904)['list'][0],
            $service->latest([904], 904)['report']] as $row) {
            self::assertSame(41, $row['recommended_actions'][0]['execution_flow']['intent_id']);
            foreach (array_slice($row['recommended_actions'], 1) as $action) {
                self::assertArrayNotHasKey('execution_flow', $action);
            }
            self::assertNotSame('read_failed', $row['execution_evidence']['data_status'] ?? '');
        }
        Db::execute('DROP TABLE sux_operation_execution_intents');
        Db::execute('CREATE VIEW sux_operation_execution_intents AS SELECT * FROM sux_missing_execution_dependency');
        $response = $this->controller($service)->read($reportId);
        self::assertSame(200, $response->getCode());
        self::assertSame($reportId, $response->getData()['data']['id']);
        self::assertSame('read_failed', $response->getData()['data']['execution_evidence']['data_status']);
        self::assertSame('blocked', $response->getData()['data']['workflow_readiness']['stage']);
    }
}
