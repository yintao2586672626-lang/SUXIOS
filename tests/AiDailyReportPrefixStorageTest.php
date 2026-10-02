<?php
declare(strict_types=1);

namespace Tests;

use app\service\AiDailyReportService;
use app\service\OperationManagementService;
use app\service\OtaCompetitionAnalysisBundleService;
use PHPUnit\Framework\TestCase;
use Tests\Support\AiEvidenceFixture;
use think\App;
use think\facade\Config;
use think\facade\Db;

/** Synthetic in-memory database only; never exports a report or calls OTA/LLM. */
final class AiDailyReportPrefixStorageTest extends TestCase
{
    private array $originalConfig;

    public static function setUpBeforeClass(): void
    {
        (new App(dirname(__DIR__)))->initialize();
    }

    protected function setUp(): void
    {
        $this->originalConfig = Config::get('database', []);
        $connection = 'report_prefix_' . bin2hex(random_bytes(6));
        Config::set(['default' => $connection, 'connections' => [$connection => [
            'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'sux_',
            'fields_strict' => false, 'debug' => false,
        ]]], 'database');
        Db::connect(null, true);
        Db::execute('CREATE TABLE sux_hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT)');
        Db::name('hotels')->insert(['id' => 904, 'tenant_id' => 9004, 'name' => 'SYNTHETIC report prefix']);
        Db::execute('CREATE TABLE sux_ai_daily_reports (id INTEGER PRIMARY KEY AUTOINCREMENT,
            hotel_id INTEGER, tenant_id INTEGER, report_date TEXT, status TEXT, generation_mode TEXT,
            model_key TEXT, model_status TEXT, model_message TEXT, summary TEXT,
            yesterday_result_json TEXT, abnormal_metrics_json TEXT, competitor_changes_json TEXT,
            data_gaps_json TEXT, recommended_actions_json TEXT, source_refs_json TEXT, snapshot_json TEXT,
            created_by INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT NULL,
            input_fingerprint TEXT, prompt_version TEXT, cache_hit_count INTEGER,
            UNIQUE(hotel_id, report_date))');
        Db::execute('CREATE TABLE ai_daily_reports (id INTEGER PRIMARY KEY)');
    }

    protected function tearDown(): void
    {
        Db::connect()->close();
        Config::set($this->originalConfig, 'database');
        Db::connect(null, true);
    }

    public function testPrefixedGenerateRetryAndExactReadbackRetainPositiveTenantAndContent(): void
    {
        $operations = $this->createMock(OperationManagementService::class);
        $operations->method('fullData')->willReturn([]);
        $operations->method('rootCause')->willReturn([]);
        $operations->method('executionFlow')->willReturn([]);
        $service = new AiDailyReportService(
            operationService: $operations,
            competitionBundleService: new OtaCompetitionAnalysisBundleService(ctripReader: static fn() => []),
            temporalOverviewLoader: static fn() => [],
            evidenceFactLoader: static fn($id, $date) => AiEvidenceFixture::closure($date),
            evidenceKnowledgeLoader: static fn() => ['hotel_id' => 904, 'status' => 'empty', 'entries' => []]
        );

        $first = $service->generate([904], 904, '2026-10-02', 4, ['use_llm' => false]);
        $retry = $service->generate([904], 904, '2026-10-02', 4, ['use_llm' => false]);
        $stored = Db::name('ai_daily_reports')->where('id', $first['id'])->find();
        self::assertSame(9004, (int)$stored['tenant_id']);
        self::assertNotEmpty($stored['input_fingerprint']);
        self::assertSame(AiDailyReportService::promptVersion(), $stored['prompt_version']);
        self::assertSame(1, Db::name('ai_daily_reports')->count());
        self::assertSame(0, Db::table('ai_daily_reports')->count());
        foreach ([$retry, $service->read($first['id'], [904]),
            $service->list([904], 904)['list'][0], $service->latest([904], 904)['report']] as $readback) {
            self::assertSame($first['id'], $readback['id']);
            self::assertSame('exact_readback_verified', $readback['evidence_readback_status']);
            self::assertSame($first['evidence_snapshot'], $readback['evidence_snapshot']);
            self::assertSame($first['final_text'], $readback['final_text']);
        }
        self::assertNull($service->read($first['id'], [905]));
    }

    public function testColumnProbeRecoversWithoutCachingMissingSchema(): void
    {
        Db::execute('CREATE TABLE sux_probe_retry (id INTEGER PRIMARY KEY)');
        $service = new AiDailyReportService();
        $probe = new \ReflectionMethod($service, 'tableHasColumn');
        self::assertFalse($probe->invoke($service, 'probe_retry', 'tenant_id'));
        Db::execute('ALTER TABLE sux_probe_retry ADD COLUMN tenant_id INTEGER');
        self::assertTrue($probe->invoke($service, 'probe_retry', 'tenant_id'));
        self::assertFalse($probe->invoke($service, 'missing_report_table', 'tenant_id'));
    }

    public function testUnreadableColumnsRemainReadFailureAndRecoverAfterRepair(): void
    {
        Db::execute('CREATE VIEW sux_broken_probe AS SELECT * FROM absent_synthetic_source');
        $service = new AiDailyReportService();
        $probe = new \ReflectionMethod($service, 'tableHasColumn');
        try {
            $probe->invoke($service, 'broken_probe', 'tenant_id');
            self::fail('Unreadable columns must not be treated as a missing tenant column');
        } catch (\RuntimeException $exception) {
            self::assertSame('database_table_columns_probe_failed:broken_probe', $exception->getMessage());
            self::assertSame(503, $exception->getCode());
        }
        Db::execute('DROP VIEW sux_broken_probe');
        Db::execute('CREATE TABLE sux_broken_probe (tenant_id INTEGER)');
        self::assertTrue($probe->invoke($service, 'broken_probe', 'tenant_id'));
    }
}
