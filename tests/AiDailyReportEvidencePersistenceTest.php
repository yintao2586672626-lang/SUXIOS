<?php
declare(strict_types=1);
namespace Tests;

use app\service\AiDailyReportService;
use app\service\AiDailyCompetitionBundlePersistenceService;
use app\service\OperationManagementService;
use app\service\OtaCompetitionAnalysisBundleService;
use app\service\AiDailyReportEvidenceService;
use app\service\LlmClient;
use PHPUnit\Framework\TestCase;
use Tests\Support\AiEvidenceFixture;
use think\facade\Config;
use think\facade\Db;

final class AiDailyReportEvidencePersistenceTest extends TestCase
{
    private string $databasePath;
    private array $originalConfig;

    protected function setUp(): void
    {
        $this->originalConfig = Config::get('database', []);
        $connection = 'l04_synthetic_' . bin2hex(random_bytes(8));
        $this->databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $connection . '.sqlite';
        $config = ['default' => $connection, 'connections' => [$connection => ['type' => 'sqlite',
            'database' => $this->databasePath, 'prefix' => '', 'fields_strict' => true]]];
        Config::set($config, 'database');
        Config::set(['default' => 'file', 'stores' => ['file' => ['type' => 'File', 'path' => (string)getenv('SUXIOS_CACHE_PATH')]]], 'cache');
        Config::set(['default' => 'file', 'channels' => ['file' => ['type' => 'File', 'path' => (string)getenv('SUXIOS_CACHE_PATH') . '/logs']]], 'log');
        Db::connect(null, true);
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT)');
        Db::name('hotels')->insert(['id' => 904, 'tenant_id' => 9004, 'name' => 'SYNTHETIC L04']);
        Db::execute('CREATE TABLE ai_daily_reports (id INTEGER PRIMARY KEY AUTOINCREMENT,
            hotel_id INTEGER, tenant_id INTEGER, report_date TEXT, status TEXT, generation_mode TEXT,
            model_key TEXT, model_status TEXT, model_message TEXT, summary TEXT,
            yesterday_result_json TEXT, abnormal_metrics_json TEXT, competitor_changes_json TEXT,
            data_gaps_json TEXT, recommended_actions_json TEXT, source_refs_json TEXT, snapshot_json TEXT,
            created_by INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT NULL,
            input_fingerprint TEXT, prompt_version TEXT, cache_hit_count INTEGER,
            UNIQUE(hotel_id, report_date))');
    }

    protected function tearDown(): void
    {
        Db::connect()->close();
        Config::set($this->originalConfig, 'database');
        @unlink($this->databasePath);
    }

    private function service(?callable $loader = null, ?LlmClient $llm = null): AiDailyReportService
    {
        $operation = $this->createMock(OperationManagementService::class);
        $operation->method('fullData')->willReturn(['summary' => ['data_status' => 'ok', 'revenue' => 999999],
            'ota' => ['data_status' => 'missing'], 'competitors' => ['data_status' => 'missing']]);
        $operation->method('rootCause')->willReturn([]);
        $operation->method('executionFlow')->willReturn([]);
        return new AiDailyReportService(operationService: $operation,
            llmClient: $llm,
            competitionBundleService: new OtaCompetitionAnalysisBundleService(ctripReader: static fn() => []),
            temporalOverviewLoader: static fn() => [],
            evidenceFactLoader: $loader ?? static fn($id, $date) => AiEvidenceFixture::closure($date),
            evidenceKnowledgeLoader: static fn() => ['hotel_id' => 904, 'status' => 'empty', 'entries' => []]);
    }

    public function testGenerateSaveRetryListLatestAndExactIdUseSameContent(): void
    {
        $service = $this->service();
        $first = $service->generate([904], 904, '2026-09-08', 4, ['use_llm' => false]);
        self::assertGreaterThan(0, $first['id']);
        self::assertSame('exact_readback_verified', $first['evidence_readback_status']);
        self::assertStringNotContainsString('999999', $first['final_text']);
        self::assertNotContains(999999, array_column($first['yesterday_result']['metrics'], 'value'));
        $retry = $service->generate([904], 904, '2026-09-08', 4, ['use_llm' => false]);
        $list = $service->list([904], 904, ['report_date' => '2026-09-08']);
        $read = $service->read($first['id'], [904]);
        $latest = $service->latest([904], 904)['report'];
        self::assertSame(1, Db::name('ai_daily_reports')->count());
        foreach ([$retry, $read, $list['list'][0], $latest] as $result) {
            self::assertSame($first['id'], $result['id']);
            self::assertSame($first['evidence_snapshot'], $result['evidence_snapshot']);
            self::assertSame($first['final_text'], $result['final_text']);
        }
        self::assertNull($service->read($first['id'], [905]));
        self::assertSame([], $service->list([905], 905)['list']);
        self::assertSame('proposed', $read['evidence_recommendations'][0]['status']);
        self::assertSame($read['evidence_recommendations'][0], $read['recommended_actions'][0]['evidence_recommendation']);
        $output = dirname(__DIR__) . '/output/long-goal';
        if (!is_dir($output)) mkdir($output, 0777, true);
        file_put_contents($output . '/synthetic-report.json', json_encode($first, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function testHistoricalComparisonUsesSavedSnapshotAndScope(): void
    {
        $service = $this->service(static function ($id, $date) {
            $closure = AiEvidenceFixture::closure($date, false);
            if ($date === '2026-09-07') $closure['platforms']['ctrip']['fields']['exposure']['value'] = 200;
            return $closure;
        });
        $service->generate([904], 904, '2026-09-07', 4, ['use_llm' => false]);
        $result = $service->generate([904], 904, '2026-09-08', 4, ['use_llm' => false]);
        self::assertSame('saved_previous_day', $result['evidence_snapshot']['diagnosis']['comparison_status']);
        self::assertSame('下降', $result['evidence_snapshot']['diagnosis']['observations'][0]['direction']);
        self::assertStringContainsString('200 → 100 people', $result['final_text']);
    }

    public function testDatabaseFailureRollsBackAndCanRetry(): void
    {
        Db::execute("CREATE TRIGGER reject_l04 BEFORE INSERT ON ai_daily_reports BEGIN SELECT RAISE(ABORT, 'synthetic save failure'); END");
        try { $this->service()->generate([904], 904, '2026-09-08', 4, ['use_llm' => false]); self::fail('save should fail'); }
        catch (\Throwable $e) { self::assertStringContainsString('synthetic save failure', $e->getMessage()); }
        self::assertSame(0, Db::name('ai_daily_reports')->count());
        Db::execute('DROP TRIGGER reject_l04');
        $result = $this->service()->generate([904], 904, '2026-09-08', 4, ['use_llm' => false]);
        self::assertSame('exact_readback_verified', $result['evidence_readback_status']);
    }

    public function testTamperedStoredMetricBlocksListAndExactRead(): void
    {
        $service = $this->service();
        $result = $service->generate([904], 904, '2026-09-08', 4, ['use_llm' => false]);
        $metrics = $result['yesterday_result']; $metrics['metrics'][0]['value'] = 999999;
        Db::name('ai_daily_reports')->where('id', $result['id'])->update(['yesterday_result_json' => json_encode($metrics)]);
        self::assertSame('read_failed', $service->read($result['id'], [904])['data_status']);
        self::assertSame('read_failed', $service->list([904], 904)['data_status']);
        self::assertSame('read_failed', $service->latest([904], 904)['data_status']);
    }

    public function testInvalidReturnedScopeCannotCreateOrOverwriteReport(): void
    {
        $good = $this->service()->generate([904], 904, '2026-09-08', 4, ['use_llm' => false]);
        $service = $this->service(static function ($id, $date) { $closure = AiEvidenceFixture::closure($date); $closure['hotel_id'] = 905; return $closure; });
        try { $service->generate([904], 904, '2026-09-08', 4, ['use_llm' => false]); self::fail('cross-hotel response accepted'); }
        catch (\RuntimeException $e) { self::assertSame('diagnosis_fact_scope_mismatch:hotel_id', $e->getMessage()); }
        self::assertSame($good['final_text'], $service->read($good['id'], [904])['final_text']);
    }

    public function testMissingFactsPersistBlockedStateAndRecoverWithoutInventingValues(): void
    {
        $service = $this->service(static function () { throw new \RuntimeException('synthetic unavailable'); });
        $missing = $service->generate([904], 904, '2026-09-08', 4, ['use_llm' => false]);
        self::assertSame('blocked', $missing['evidence_snapshot']['fact_pack']['status']);
        self::assertSame([], $missing['yesterday_result']['metrics']);
        self::assertSame('blocked_by_missing_facts', $missing['evidence_recommendations'][0]['handoff_status']);
        $restored = $this->service()->generate([904], 904, '2026-09-08', 4, ['use_llm' => false]);
        self::assertSame($missing['id'], $restored['id']);
        self::assertSame('ready', $restored['evidence_snapshot']['fact_pack']['status']);
    }

    public function testLegacyRowRemainsReadableWithoutInventingVerification(): void
    {
        $id = Db::name('ai_daily_reports')->insertGetId(['hotel_id' => 904, 'tenant_id' => 9004,
            'report_date' => '2026-09-06', 'summary' => 'Legacy synthetic report', 'snapshot_json' => '{}']);
        $report = $this->service()->read((int)$id, [904]);
        self::assertSame('legacy_unverified', $report['evidence_readback_status']);
        self::assertArrayNotHasKey('final_text', $report);
    }

    public function testProjectionTamperingIsRejectedBeforeDatabaseWrite(): void
    {
        $result = $this->service()->generate([904], 904, '2026-09-08', 4, ['use_llm' => false]);
        $payload = Db::name('ai_daily_reports')->where('id', $result['id'])->find();
        $payload['summary'] = 'forged revenue';
        $this->expectExceptionMessage('diagnosis_projection_unbound_claim');
        AiDailyCompetitionBundlePersistenceService::persistReport($payload, $payload, '2026-09-08 00:00:00', 904, '2026-09-08');
    }

    public function testEvenResignedProjectionCannotBindWrongValue(): void
    {
        $result = $this->service()->generate([904], 904, '2026-09-08', 4, ['use_llm' => false]);
        $payload = Db::name('ai_daily_reports')->where('id', $result['id'])->find();
        $metrics = json_decode($payload['yesterday_result_json'], true);
        $metrics['metrics'][0]['value'] = 999999;
        $payload['yesterday_result_json'] = json_encode($metrics);
        $snapshot = json_decode($payload['snapshot_json'], true);
        $snapshot['evidence_projection_digest'] = AiDailyReportEvidenceService::projectionDigest($payload);
        $payload['snapshot_json'] = json_encode($snapshot);
        $this->expectExceptionMessage('diagnosis_projection_value_mismatch');
        AiDailyCompetitionBundlePersistenceService::persistReport($payload, $payload, '2026-09-08 00:00:00', 904, '2026-09-08');
    }

    public function testControllerJsonEnvelopeUsesExactReadbackAndFailsClosed(): void
    {
        $service = $this->service();
        $report = $service->generate([904], 904, '2026-09-08', 4, ['use_llm' => false]);
        $controller = (new \ReflectionClass(\app\controller\AiDailyReport::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($controller, 'service'))->setValue($controller, $service);
        (new \ReflectionProperty($controller, 'request'))->setValue($controller, new \think\Request());
        (new \ReflectionProperty($controller, 'currentUser'))->setValue($controller, new class {
            public function getPermittedHotelIds(): array { return [904]; }
        });
        $response = $controller->read($report['id']);
        self::assertSame(200, $response->getCode());
        self::assertSame(200, $response->getData()['code']);
        self::assertSame($report['final_text'], $response->getData()['data']['final_text']);
        self::assertSame($report['final_text'], $controller->index()->getData()['data']['list'][0]['final_text']);
        self::assertSame($report['final_text'], $controller->latest()->getData()['data']['report']['final_text']);
        Db::name('ai_daily_reports')->where('id', $report['id'])->update(['summary' => 'tampered']);
        foreach ([$controller->read($report['id']), $controller->index(), $controller->latest()] as $failed) {
            self::assertSame(503, $failed->getCode());
            self::assertSame(503, $failed->getData()['code']);
            self::assertSame('read_failed', $failed->getData()['data']['data_status']);
        }
        (new \ReflectionProperty($controller, 'currentUser'))->setValue($controller, new class {
            public function getPermittedHotelIds(): array { return [905]; }
        });
        self::assertSame(404, $controller->read($report['id'])->getCode());
    }

    public function testModelSuccessAndTimeoutAreSavedAndReadBackWithoutModelNumbers(): void
    {
        $client = $this->createMock(LlmClient::class);
        $client->expects(self::once())->method('createJsonResponse')->willReturnCallback(static function ($messages): array {
            $input = json_decode($messages[1]['content'], true);
            return ['scope' => $input['scope'], 'facts_fingerprint' => $input['facts_fingerprint'],
                'claims' => [$input['facts'][0]], 'hypothesis_ids' => ['ctrip.visibility'], 'summary' => 'untrusted 999999'];
        });
        $result = $this->service(llm: $client)->generate([904], 904, '2026-09-08', 4,
            ['use_llm' => true, 'model_key' => 'synthetic-model']);
        self::assertSame('ok', $result['model_status']);
        self::assertSame(['ctrip.visibility'], $result['evidence_snapshot']['model']['selected_hypothesis_ids']);
        self::assertStringNotContainsString('999999', $result['final_text']);
        $same = $this->service(llm: $client)->generate([904], 904, '2026-09-08', 4,
            ['use_llm' => true, 'model_key' => 'synthetic-model']);
        self::assertTrue($same['cache_hit']);
        self::assertSame($result['evidence_snapshot'], $same['evidence_snapshot']);
        $failed = $this->createMock(LlmClient::class);
        $failed->method('createJsonResponse')->willThrowException(new \RuntimeException('timeout synthetic'));
        $timeout = $this->service(llm: $failed)->generate([904], 904, '2026-09-08', 4,
            ['use_llm' => true, 'model_key' => 'synthetic-timeout-model']);
        self::assertSame('timeout', $timeout['model_status']);
        self::assertSame('timeout', $timeout['ai_interpretation']['status']);
        self::assertSame('rule', $timeout['generation_mode']);
        self::assertSame($result['id'], $timeout['id']);
    }
}
