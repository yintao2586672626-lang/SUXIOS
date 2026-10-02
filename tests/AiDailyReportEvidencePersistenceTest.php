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
        self::assertContains('ota_readback_verification_schema_missing', array_column($first['data_gaps'], 'code'));
        $retry = $service->generate([904], 904, '2026-09-08', 4, ['use_llm' => false]);
        $list = $service->list([904], 904, ['report_date' => '2026-09-08']);
        $read = $service->read($first['id'], [904]);
        $latest = $service->latest([904], 904)['report'];
        self::assertSame(1, Db::name('ai_daily_reports')->count());
        foreach ([$retry, $read, $list['list'][0], $latest] as $result) {
            self::assertSame($first['id'], $result['id']);
            self::assertSame($first['evidence_snapshot'], $result['evidence_snapshot']);
            self::assertSame($first['final_text'], $result['final_text']);
            self::assertSame($first['data_gaps'], $result['data_gaps']);
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

    public function testLatestCanReadAnExactBusinessDateWithoutFallingBack(): void
    {
        $service = $this->service();
        $older = $service->generate([904], 904, '2026-09-07', 4, ['use_llm' => false]);
        $newer = $service->generate([904], 904, '2026-09-08', 4, ['use_llm' => false]);
        self::assertSame($older['id'], $service->latest([904], 904, '2026-09-07')['report']['id']);
        self::assertSame($newer['id'], $service->latest([904], 904)['report']['id']);
        self::assertNull($service->latest([904], 904, '2026-09-06')['report']);
        self::assertNull($service->latest([905], 905, '2026-09-07')['report']);
    }

    public function testReportFromPreviousTenantIsNotReadableAfterHotelTransfer(): void
    {
        $service = $this->service();
        $saved = $service->generate([904], 904, '2026-09-08', 4, ['use_llm' => false]);
        Db::name('hotels')->where('id', 904)->update(['tenant_id' => 9005]);

        self::assertNull($service->read((int)$saved['id'], [904]));
        self::assertSame([], $service->list([904], 904)['list']);
        self::assertNull($service->latest([904], 904)['report']);
        try {
            $service->createExecutionIntentFromAction((int)$saved['id'], 0, [904], 4);
            self::fail('previous tenant report created an execution intent');
        } catch (\RuntimeException $error) {
            self::assertSame('AI daily report not found', $error->getMessage());
        }

        Db::name('hotels')->where('id', 904)->update(['tenant_id' => 9004]);
        self::assertSame($saved['id'], $service->read((int)$saved['id'], [904])['id']);
    }

    public function testRegenerationCannotOverwritePreviousTenantReport(): void
    {
        $id = Db::name('ai_daily_reports')->insertGetId([
            'hotel_id' => 904, 'tenant_id' => 9003, 'report_date' => '2026-09-08',
            'summary' => 'Previous tenant report', 'snapshot_json' => '{}',
        ]);
        try {
            $this->service()->generate([904], 904, '2026-09-08', 4, ['use_llm' => false]);
            self::fail('regeneration overwrote the previous tenant report');
        } catch (\RuntimeException $error) {
            self::assertSame('AI daily report belongs to another tenant', $error->getMessage());
        }
        $stored = Db::name('ai_daily_reports')->where('id', $id)->find();
        self::assertSame(9003, (int)$stored['tenant_id']);
        self::assertSame('Previous tenant report', $stored['summary']);
    }

    public function testHumanJudgmentCannotWritePreviousTenantReport(): void
    {
        $service = $this->service();
        $saved = $service->generate([904], 904, '2026-09-08', 4, ['use_llm' => false]);
        Db::name('hotels')->where('id', 904)->update(['tenant_id' => 9005]);
        try {
            $service->recordHumanJudgment((int)$saved['id'], [904], 4, ['decision' => 'accepted']);
            self::fail('previous tenant report accepted a human judgment');
        } catch (\RuntimeException $error) {
            self::assertSame('AI daily report not found', $error->getMessage());
        }
        self::assertSame(
            $saved['snapshot']['human_judgments'],
            json_decode((string)Db::name('ai_daily_reports')->where('id', $saved['id'])->value('snapshot_json'), true)['human_judgments']
        );
    }

    public function testNewTenantTrialDoesNotComparePreviousTenantReport(): void
    {
        $service = $this->service();
        $previous = $service->generate([904], 904, '2026-09-07', 4, ['use_llm' => false]);
        Db::name('ai_daily_reports')->where('id', $previous['id'])->update(['tenant_id' => 9003]);

        $current = $service->generate([904], 904, '2026-09-08', 4, ['use_llm' => false]);
        self::assertSame(
            'pending_first_follow_up',
            $current['trial_validation']['second_same_scope_comparison']['status']
        );
    }

    public function testDailyReportExecutionReadbackExcludesPreviousTenantIntent(): void
    {
        Db::execute('CREATE TABLE operation_execution_intents (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, hotel_id INTEGER,
            source_module TEXT, source_record_id INTEGER, deleted_at TEXT NULL)');
        foreach ([[51, 9003], [52, 9004]] as [$id, $tenantId]) {
            Db::name('operation_execution_intents')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'hotel_id' => 904,
                'source_module' => 'ai_daily_report', 'source_record_id' => 77,
            ]);
        }

        $method = new \ReflectionMethod($this->service(), 'executionItemsByReportId');
        $result = $method->invoke($this->service(), [904], 904, [77]);
        self::assertSame([], $result['read_state']);
        self::assertCount(1, $result['items_by_report_id'][77] ?? []);
    }

    public function testDailyReportActionReuseIgnoresPreviousTenantLinkedIntent(): void
    {
        $operation = new OperationManagementService();
        $action = ['execution_intent_id' => 51, 'execution_time' => '2026-09-29', 'title' => '复核转化'];
        $storedAction = $action;
        unset($storedAction['execution_intent_id']);
        $evidence = json_encode([
            'action_idempotency_key' => 'same-action',
            'decision_recommendation' => $storedAction,
            'decision_recommendation_digest' => $operation->decisionRecommendationDigest($action),
        ], JSON_UNESCAPED_UNICODE);
        Db::execute('CREATE TABLE operation_execution_intents (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, hotel_id INTEGER,
            source_module TEXT, source_record_id INTEGER, status TEXT,
            date_start TEXT, date_end TEXT, current_value_json TEXT, target_value_json TEXT,
            expected_delta REAL, risk_level TEXT, evidence_json TEXT, deleted_at TEXT NULL)');
        foreach ([[51, 9003], [52, 9004]] as [$id, $tenantId]) {
            Db::name('operation_execution_intents')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'hotel_id' => 904,
                'source_module' => 'ai_daily_report', 'source_record_id' => 77,
                'status' => 'pending_approval',
                'date_start' => '2026-09-29', 'date_end' => '2026-09-29',
                'current_value_json' => '[]', 'target_value_json' => '[]',
                'expected_delta' => 0, 'risk_level' => 'medium',
                'evidence_json' => $evidence,
            ]);
        }

        $service = new AiDailyReportService(operationService: $operation);
        $method = new \ReflectionMethod($service, 'findDailyReportActionIntent');
        $match = $method->invoke($service, 77, 904, 0, 'same-action', $action, '2026-09-28');
        self::assertSame(52, (int)($match['id'] ?? 0));
    }

    public function testDailyReportActionDoesNotReuseAnOldExecutionWindowAfterRegeneration(): void
    {
        $operation = new OperationManagementService();
        $action = ['execution_intent_id' => 61, 'execution_time' => '2026-09-30', 'title' => '复核转化'];
        $storedAction = $action;
        unset($storedAction['execution_intent_id']);
        $evidence = json_encode([
            'action_idempotency_key' => 'same-action',
            'decision_recommendation' => $storedAction,
            'decision_recommendation_digest' => $operation->decisionRecommendationDigest($action),
        ], JSON_UNESCAPED_UNICODE);
        Db::execute('CREATE TABLE operation_execution_intents (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, hotel_id INTEGER,
            source_module TEXT, source_record_id INTEGER, status TEXT,
            date_start TEXT, date_end TEXT, current_value_json TEXT, target_value_json TEXT,
            expected_delta REAL, risk_level TEXT, evidence_json TEXT, deleted_at TEXT NULL)');
        Db::name('operation_execution_intents')->insert([
            'id' => 61, 'tenant_id' => 9004, 'hotel_id' => 904,
            'source_module' => 'ai_daily_report', 'source_record_id' => 77,
            'status' => 'pending_approval', 'date_start' => '2026-09-29', 'date_end' => '2026-09-29',
            'current_value_json' => '[]', 'target_value_json' => '[]',
            'expected_delta' => 0, 'risk_level' => 'medium',
            'evidence_json' => $evidence,
        ]);
        $service = new AiDailyReportService(operationService: $operation);
        $find = new \ReflectionMethod($service, 'findDailyReportActionIntent');

        self::assertNull($find->invoke($service, 77, 904, 0, 'same-action', $action, '2026-09-28'));
        Db::name('operation_execution_intents')->insert([
            'id' => 62, 'tenant_id' => 9004, 'hotel_id' => 904,
            'source_module' => 'ai_daily_report', 'source_record_id' => 77,
            'status' => 'pending_approval', 'date_start' => '2026-09-30', 'date_end' => '2026-09-30',
            'current_value_json' => '[]', 'target_value_json' => '[]',
            'expected_delta' => 0, 'risk_level' => 'medium',
            'evidence_json' => $evidence,
        ]);
        self::assertSame(62, (int)($find->invoke($service, 77, 904, 0, 'same-action', $action, '2026-09-28')['id'] ?? 0));
    }

    public function testDailyReportActionDoesNotReuseOldRecommendationWithSameDateAndKey(): void
    {
        Db::execute('CREATE TABLE operation_execution_intents (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, hotel_id INTEGER,
            source_module TEXT, source_record_id INTEGER, status TEXT,
            date_start TEXT, date_end TEXT, current_value_json TEXT, target_value_json TEXT,
            expected_delta REAL, risk_level TEXT,
            evidence_json TEXT, deleted_at TEXT NULL)');
        $operation = new OperationManagementService();
        $digest = new \ReflectionMethod($operation, 'decisionRecommendationDigest');
        $oldAction = ['title' => '复核转化', 'action' => '核查旧活动入口', 'target_value' => ['target_metric' => 'conversion']];
        $newAction = ['title' => '复核转化', 'action' => '核查新活动入口', 'target_value' => ['target_metric' => 'conversion']];
        $storedEvidence = static function (array $action) use ($digest, $operation): string {
            unset($action['execution_intent_id']);
            return json_encode([
                'action_idempotency_key' => 'same-action',
                'decision_recommendation' => $action,
                'decision_recommendation_digest' => $digest->invoke($operation, $action),
            ], JSON_UNESCAPED_UNICODE);
        };
        Db::name('operation_execution_intents')->insert([
            'id' => 71, 'tenant_id' => 9004, 'hotel_id' => 904,
            'source_module' => 'ai_daily_report', 'source_record_id' => 77,
            'status' => 'pending_approval', 'date_start' => '2026-09-30', 'date_end' => '2026-09-30',
            'current_value_json' => '[]', 'expected_delta' => 0, 'risk_level' => 'medium',
            'target_value_json' => json_encode($oldAction['target_value']),
            'evidence_json' => $storedEvidence($oldAction),
        ]);
        $service = new AiDailyReportService(operationService: $operation);
        $find = new \ReflectionMethod($service, 'findDailyReportActionIntent');
        $newAction['execution_intent_id'] = 71;
        $newAction['execution_time'] = '2026-09-30';

        self::assertNull($find->invoke($service, 77, 904, 0, 'same-action', $newAction, '2026-09-28'));
        Db::name('operation_execution_intents')->insert([
            'id' => 72, 'tenant_id' => 9004, 'hotel_id' => 904,
            'source_module' => 'ai_daily_report', 'source_record_id' => 77,
            'status' => 'pending_approval', 'date_start' => '2026-09-30', 'date_end' => '2026-09-30',
            'current_value_json' => '[]', 'expected_delta' => 0, 'risk_level' => 'medium',
            'target_value_json' => json_encode($newAction['target_value']),
            'evidence_json' => $storedEvidence($newAction),
        ]);
        self::assertSame(72, (int)($find->invoke($service, 77, 904, 0, 'same-action', $newAction, '2026-09-28')['id'] ?? 0));
    }

    public function testDailyReportActionDoesNotReuseOldTargetValueWithSameRecommendationDigest(): void
    {
        Db::execute('CREATE TABLE operation_execution_intents (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, hotel_id INTEGER,
            source_module TEXT, source_record_id INTEGER, status TEXT,
            date_start TEXT, date_end TEXT, current_value_json TEXT, target_value_json TEXT,
            expected_delta REAL, risk_level TEXT,
            evidence_json TEXT, deleted_at TEXT NULL)');
        $operation = new OperationManagementService();
        $action = [
            'title' => '复核订单', 'action' => '核查渠道订单转化',
            'execution_time' => '2026-09-30',
            'target_value' => ['campaign_type' => 'conversion_review', 'target_metric' => 'orders', 'target_orders' => 20],
        ];
        $evidence = json_encode([
            'action_idempotency_key' => 'same-action',
            'decision_recommendation' => $action,
            'decision_recommendation_digest' => $operation->decisionRecommendationDigest($action),
        ], JSON_UNESCAPED_UNICODE);
        Db::name('operation_execution_intents')->insert([
            'id' => 81, 'tenant_id' => 9004, 'hotel_id' => 904,
            'source_module' => 'ai_daily_report', 'source_record_id' => 77,
            'status' => 'pending_approval', 'date_start' => '2026-09-30', 'date_end' => '2026-09-30',
            'current_value_json' => '[]', 'expected_delta' => 0, 'risk_level' => 'medium',
            'target_value_json' => json_encode(['campaign_type' => 'conversion_review', 'target_metric' => 'orders', 'target_orders' => 10]),
            'evidence_json' => $evidence,
        ]);
        $service = new AiDailyReportService(operationService: $operation);
        $find = new \ReflectionMethod($service, 'findDailyReportActionIntent');

        self::assertNull($find->invoke($service, 77, 904, 0, 'same-action', $action, '2026-09-28'));
        Db::name('operation_execution_intents')->insert([
            'id' => 82, 'tenant_id' => 9004, 'hotel_id' => 904,
            'source_module' => 'ai_daily_report', 'source_record_id' => 77,
            'status' => 'pending_approval', 'date_start' => '2026-09-30', 'date_end' => '2026-09-30',
            'current_value_json' => '[]', 'expected_delta' => 0, 'risk_level' => 'medium',
            'target_value_json' => json_encode($action['target_value']),
            'evidence_json' => $evidence,
        ]);
        self::assertSame(82, (int)($find->invoke($service, 77, 904, 0, 'same-action', $action, '2026-09-28')['id'] ?? 0));
    }

    public function testDailyReportActionDoesNotReuseChangedExecutionBasis(): void
    {
        Db::execute('CREATE TABLE operation_execution_intents (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, hotel_id INTEGER,
            source_module TEXT, source_record_id INTEGER, status TEXT,
            date_start TEXT, date_end TEXT, current_value_json TEXT,
            target_value_json TEXT, expected_delta REAL, risk_level TEXT,
            evidence_json TEXT, deleted_at TEXT NULL)');
        $operation = new OperationManagementService();
        $action = [
            'title' => '复核订单', 'action' => '核查渠道订单转化', 'execution_time' => '2026-09-30',
            'current_value' => ['orders' => 12],
            'target_value' => ['campaign_type' => 'conversion_review', 'target_metric' => 'orders', 'target_orders' => 20],
            'expected_delta' => 8, 'risk_level' => 'high',
        ];
        $evidence = json_encode([
            'action_idempotency_key' => 'same-action',
            'decision_recommendation' => $action,
            'decision_recommendation_digest' => $operation->decisionRecommendationDigest($action),
        ], JSON_UNESCAPED_UNICODE);
        $base = [
            'tenant_id' => 9004, 'hotel_id' => 904,
            'source_module' => 'ai_daily_report', 'source_record_id' => 77,
            'status' => 'pending_approval', 'date_start' => '2026-09-30', 'date_end' => '2026-09-30',
            'current_value_json' => json_encode($action['current_value']),
            'target_value_json' => json_encode($action['target_value']),
            'expected_delta' => 8, 'risk_level' => 'high', 'evidence_json' => $evidence,
        ];
        $service = new AiDailyReportService(operationService: $operation);
        $find = new \ReflectionMethod($service, 'findDailyReportActionIntent');
        $differences = [
            91 => ['current_value_json' => json_encode(['orders' => 10])],
            92 => ['expected_delta' => 6],
            93 => ['risk_level' => 'low'],
        ];
        foreach ($differences as $id => $difference) {
            Db::name('operation_execution_intents')->insert(array_merge($base, ['id' => $id], $difference));
            $linked = $action;
            $linked['execution_intent_id'] = $id;
            self::assertNull($find->invoke($service, 77, 904, 0, 'same-action', $linked, '2026-09-28'));
        }
        Db::name('operation_execution_intents')->insert(array_merge($base, ['id' => 94]));
        self::assertSame(94, (int)($find->invoke($service, 77, 904, 0, 'same-action', $action, '2026-09-28')['id'] ?? 0));
        $preciseAction = $action;
        $preciseAction['expected_delta'] = 8.004;
        $preciseEvidence = json_encode([
            'action_idempotency_key' => 'same-action',
            'decision_recommendation' => $preciseAction,
            'decision_recommendation_digest' => $operation->decisionRecommendationDigest($preciseAction),
        ], JSON_UNESCAPED_UNICODE);
        Db::name('operation_execution_intents')->insert(array_merge($base, [
            'id' => 95, 'evidence_json' => $preciseEvidence,
        ]));
        self::assertSame(95, (int)($find->invoke($service, 77, 904, 0, 'same-action', $preciseAction, '2026-09-28')['id'] ?? 0));
    }

    public function testDailyReportActionDoesNotReuseOldReasonWithUnchangedApprovalDigest(): void
    {
        Db::execute('CREATE TABLE operation_execution_intents (
            id INTEGER PRIMARY KEY, tenant_id INTEGER, hotel_id INTEGER,
            source_module TEXT, source_record_id INTEGER, status TEXT,
            date_start TEXT, date_end TEXT, current_value_json TEXT,
            target_value_json TEXT, expected_delta REAL, risk_level TEXT,
            evidence_json TEXT, deleted_at TEXT NULL)');
        $operation = new OperationManagementService();
        $oldAction = [
            'title' => '复核转化', 'action' => '核查渠道入口',
            'reason' => '旧采集样本提示入口失效', 'execution_time' => '2026-09-30',
            'target_value' => ['campaign_type' => 'conversion_review', 'target_metric' => 'orders'],
        ];
        $currentAction = $oldAction;
        $currentAction['reason'] = '最新已读回订单提示转化下降';
        self::assertSame(
            $operation->decisionRecommendationDigest($oldAction),
            $operation->decisionRecommendationDigest($currentAction)
        );
        $row = [
            'tenant_id' => 9004, 'hotel_id' => 904,
            'source_module' => 'ai_daily_report', 'source_record_id' => 77,
            'status' => 'pending_approval', 'date_start' => '2026-09-30', 'date_end' => '2026-09-30',
            'current_value_json' => '[]', 'target_value_json' => json_encode($oldAction['target_value']),
            'expected_delta' => 0, 'risk_level' => 'medium',
        ];
        $evidence = static fn(array $action): string => json_encode([
            'action_idempotency_key' => 'same-action',
            'decision_recommendation' => $action,
            'decision_recommendation_digest' => $operation->decisionRecommendationDigest($action),
        ], JSON_UNESCAPED_UNICODE);
        Db::name('operation_execution_intents')->insert(array_merge($row, [
            'id' => 101, 'evidence_json' => $evidence($oldAction),
        ]));
        $service = new AiDailyReportService(operationService: $operation);
        $find = new \ReflectionMethod($service, 'findDailyReportActionIntent');
        $currentAction['execution_intent_id'] = 101;
        $currentAction['execution_status'] = 'pending_approval';
        $currentAction['execution_flow'] = ['stage' => 'pending_approval'];
        $currentAction['action_readiness'] = ['status' => 'pending_approval'];
        self::assertNull($find->invoke($service, 77, 904, 0, 'same-action', $currentAction, '2026-09-28'));
        $storedCurrent = $currentAction;
        unset($storedCurrent['execution_intent_id'], $storedCurrent['execution_status'],
            $storedCurrent['execution_flow'], $storedCurrent['action_readiness']);
        $storedCurrent['action_readiness'] = ['status' => 'pending_transfer'];
        Db::name('operation_execution_intents')->insert(array_merge($row, [
            'id' => 102, 'evidence_json' => $evidence($storedCurrent),
        ]));
        self::assertSame(102, (int)($find->invoke($service, 77, 904, 0, 'same-action', $currentAction, '2026-09-28')['id'] ?? 0));
    }

    public function testHistoricalActionExecutionDateCannotCreateAnExpiredOrInvertedIntent(): void
    {
        $service = $this->service();
        $method = new \ReflectionMethod($service, 'dailyReportActionExecutionDates');
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Asia/Shanghai'));
        $past = $today->modify('-1 day')->format('Y-m-d');
        $future = $today->modify('+1 day')->format('Y-m-d');

        self::assertSame([$future, $future], $method->invoke($service, ['execution_time' => $future], $past));
        self::assertSame([$future, $future], $method->invoke($service, ['date_start' => $future], $past));
        foreach ([
            [[], $past],
            [['execution_time' => $past], $past],
            [['execution_time' => $future, 'date_end' => $past], $past],
        ] as [$action, $reportDate]) {
            try {
                $method->invoke($service, $action, $reportDate);
                self::fail('expired or inverted action date should not create an execution intent');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('执行日期', $error->getMessage());
            }
        }
    }

    public function testPendingDailyReportIntentCannotBeApprovedAfterItsExecutionWindowExpires(): void
    {
        $operation = new OperationManagementService();
        $recommendation = [
            'can_create_execution_intent' => true,
            'decision_quality' => [
                'contract_version' => \app\service\AiDecisionQualityService::CONTRACT_VERSION,
                'execution_ready' => true,
            ],
        ];
        $digest = (new \ReflectionMethod($operation, 'decisionRecommendationDigest'))
            ->invoke($operation, $recommendation);
        $past = (new \DateTimeImmutable('yesterday', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        $intent = [
            'source_module' => 'ai_daily_report', 'source_record_id' => 77,
            'hotel_id' => 904, 'date_start' => $past, 'date_end' => $past,
            'evidence' => [
                'decision_recommendation' => $recommendation,
                'decision_recommendation_digest' => $digest,
                'action_index' => 0,
            ],
        ];

        try {
            (new \ReflectionMethod($operation, 'assertAiDecisionIntentReadyForApproval'))
                ->invoke($operation, $intent);
            self::fail('expired AI daily report intent should not be approved');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('执行日期已过期', $error->getMessage());
        }
    }

    public function testApprovalRejectsDailyReportReasonDriftOutsideStableDigest(): void
    {
        $operation = new OperationManagementService();
        $stored = [
            'title' => '复核转化', 'action' => '核查渠道入口',
            'reason' => '旧采集样本提示入口失效',
            'target_value' => ['target_metric' => 'orders'],
        ];
        $current = $stored;
        $current['reason'] = '最新已读回订单提示转化下降';
        $digest = $operation->decisionRecommendationDigest($stored);
        self::assertSame($digest, $operation->decisionRecommendationDigest($current));
        $check = new \ReflectionMethod($operation, 'assertAiDailyReportRecommendationCurrent');
        $intent = [
            'target_value' => $stored['target_value'], 'current_value' => [],
            'expected_delta' => 0, 'risk_level' => 'medium',
        ];
        try {
            $check->invoke($operation, $stored, $current, $digest, $intent);
            self::fail('approval reused an old business reason');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('provenance changed', $error->getMessage());
        }
        $current = $stored;
        $current['execution_flow'] = ['stage' => 'pending_approval'];
        $current['action_readiness'] = ['status' => 'pending_approval'];
        $check->invoke($operation, $stored, $current, $digest, $intent);
    }

    public function testApprovalRejectsDailyReportIntentWithChangedPersistedExecutionBasis(): void
    {
        $operation = new OperationManagementService();
        $recommendation = [
            'title' => '复核订单', 'action' => '核查渠道订单转化',
            'target_value' => ['campaign_type' => 'conversion_review', 'target_metric' => 'orders', 'target_orders' => 20],
            'current_value' => ['orders' => 12], 'expected_delta' => 8, 'risk_level' => 'high',
        ];
        $digest = $operation->decisionRecommendationDigest($recommendation);
        $intent = [
            'target_value' => $recommendation['target_value'],
            'current_value' => $recommendation['current_value'],
            'expected_delta' => 8, 'risk_level' => 'high',
        ];
        $check = new \ReflectionMethod($operation, 'assertAiDailyReportRecommendationCurrent');
        foreach ([
            ['target_value' => ['campaign_type' => 'conversion_review', 'target_metric' => 'orders', 'target_orders' => 10]],
            ['current_value' => ['orders' => 10]],
            ['expected_delta' => 6],
            ['risk_level' => 'low'],
        ] as $difference) {
            try {
                $check->invoke($operation, $recommendation, $recommendation, $digest, array_merge($intent, $difference));
                self::fail('approval accepted execution values that differ from the saved recommendation');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('provenance changed', $error->getMessage());
            }
        }
        $check->invoke($operation, $recommendation, $recommendation, $digest, $intent);
    }

    public function testReadbackExecutionFlowDoesNotChangeBusinessActionIdentity(): void
    {
        $service = $this->service();
        $saved = $service->generate([904], 904, '2026-09-08', 4, ['use_llm' => false]);
        $row = Db::name('ai_daily_reports')->where('id', $saved['id'])->find();
        $normalize = new \ReflectionMethod($service, 'normalizeReportRow');
        $before = $normalize->invoke($service, $row, [], [], []);
        $executionItem = [
            'id' => 901, 'stage' => 'pending_approval',
            'approval' => ['status' => 'pending_approval', 'blocked_reason' => ''],
            'recommendation' => ['evidence' => ['action_index' => 0]],
        ];
        $after = $normalize->invoke($service, $row, [$executionItem], [], []);
        self::assertNotEmpty($before['recommended_actions']);
        self::assertTrue(AiDailyReportService::sameBusinessAction(
            $before['recommended_actions'][0], $after['recommended_actions'][0]
        ));
    }

    public function testPendingDailyReportIntentCannotApproveAnInvertedLegacyWindow(): void
    {
        $operation = new OperationManagementService();
        $recommendation = [
            'can_create_execution_intent' => true,
            'decision_quality' => [
                'contract_version' => \app\service\AiDecisionQualityService::CONTRACT_VERSION,
                'execution_ready' => true,
            ],
        ];
        $digest = (new \ReflectionMethod($operation, 'decisionRecommendationDigest'))
            ->invoke($operation, $recommendation);
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Asia/Shanghai'));
        $intent = [
            'source_module' => 'ai_daily_report', 'source_record_id' => 77,
            'hotel_id' => 904,
            'date_start' => $today->modify('+1 day')->format('Y-m-d'),
            'date_end' => $today->format('Y-m-d'),
            'evidence' => [
                'decision_recommendation' => $recommendation,
                'decision_recommendation_digest' => $digest,
                'action_index' => 0,
            ],
        ];

        try {
            (new \ReflectionMethod($operation, 'assertAiDecisionIntentReadyForApproval'))
                ->invoke($operation, $intent);
            self::fail('inverted legacy window should not be approved');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('执行日期区间倒置', $error->getMessage());
        }
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

    public static function judgmentStorageModes(): array
    {
        return [[false], [true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('judgmentStorageModes')]
    public function testDelayedRegenerationPreservesNewJudgmentAndRetryReceipt(bool $appendOnly): void
    {
        if ($appendOnly) {
            Db::execute('CREATE TABLE ai_report_human_reviews (
                id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, report_id INTEGER,
                subject_type TEXT, subject_key TEXT, decision TEXT, before_json TEXT, correction_json TEXT,
                reason TEXT, result_version TEXT, created_by INTEGER, created_at TEXT)');
        }
        $service = $this->service();
        $report = $service->generate([904], 904, '2026-09-08', 4, ['use_llm' => false]);
        $stale = Db::name('ai_daily_reports')->where('id', $report['id'])->find();
        $next = $stale;
        $nextSnapshot = json_decode($next['snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
        // Model the new machine result independently of the human result saved during generation.
        $nextSnapshot['trial_validation']['first_trusted_collection']['passed'] = true;
        $nextSnapshot['trial_validation']['second_same_scope_comparison']['passed'] = true;
        $next['snapshot_json'] = json_encode($nextSnapshot, JSON_THROW_ON_ERROR);
        $input = ['target_type' => 'report_usefulness', 'decision' => 'accepted', 'comment' => 'synthetic owner review',
            'request_id' => 'e5f1c348-5285-499b-85d3-a33003062717'];
        $saved = $service->recordHumanJudgment($report['id'], [904], 4, $input);
        $receipt = $saved['human_judgments'][0];

        AiDailyCompetitionBundlePersistenceService::persistReport($stale, $next, '2026-09-08 12:00:00', 904, '2026-09-08');

        $row = Db::name('ai_daily_reports')->where('id', $report['id'])->find();
        $snapshot = json_decode($row['snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $snapshot['human_judgments'], 'A stale generation snapshot must not erase the newer manual judgment.');
        self::assertSame($receipt['request_id'], $snapshot['human_judgments'][0]['request_id']);
        self::assertSame('confirmed_useful', $snapshot['trial_validation']['user_confirmed_useful']['status']);
        self::assertTrue($snapshot['trial_validation']['passed']);
        self::assertSame(1, $snapshot['result_contract']['layers']['human_judgments']['count']);
        self::assertSame($nextSnapshot['evidence_snapshot'], $snapshot['evidence_snapshot']);
        self::assertSame($nextSnapshot['evidence_projection_digest'], $snapshot['evidence_projection_digest']);
        self::assertSame('exact_readback_verified', $service->read($report['id'], [904])['evidence_readback_status']);

        $retried = $service->recordHumanJudgment($report['id'], [904], 4, $input);
        self::assertCount(1, $retried['human_judgments']);
        self::assertSame($receipt['id'], $retried['human_judgments'][0]['id']);
        // Machine readiness must remain new: an old useful vote alone cannot make incomplete input pass.
        $nextSnapshot['trial_validation']['first_trusted_collection']['passed'] = false;
        $next['snapshot_json'] = json_encode($nextSnapshot, JSON_THROW_ON_ERROR);
        AiDailyCompetitionBundlePersistenceService::persistReport($stale, $next, '2026-09-08 12:01:00', 904, '2026-09-08');
        $latest = json_decode(Db::name('ai_daily_reports')->where('id', $report['id'])->value('snapshot_json'), true);
        self::assertFalse($latest['trial_validation']['passed']);
        self::assertSame('confirmed_useful', $latest['trial_validation']['user_confirmed_useful']['status']);
    }

    public function testDelayedRegenerationCannotOverwriteAReportTransferredToAnotherTenant(): void
    {
        $report = $this->service()->generate([904], 904, '2026-09-08', 4, ['use_llm' => false]);
        $stale = Db::name('ai_daily_reports')->where('id', $report['id'])->find();
        Db::name('ai_daily_reports')->where('id', $report['id'])->update(['tenant_id' => 9005, 'summary' => 'synthetic new owner']);
        try {
            AiDailyCompetitionBundlePersistenceService::persistReport($stale, $stale, '2026-09-08 12:00:00', 904, '2026-09-08');
            self::fail('Stale generation must not write or copy judgments across tenant ownership.');
        } catch (\RuntimeException $error) {
            self::assertSame('competition_report_scope_mismatch', $error->getMessage());
        }
        $after = Db::name('ai_daily_reports')->where('id', $report['id'])->find();
        self::assertSame(9005, (int)$after['tenant_id']);
        self::assertSame('synthetic new owner', $after['summary']);
    }

    public static function changedGenerationScopes(): array
    {
        return [
            [['hotel_id' => 905], false], [['report_date' => '2026-09-09'], false],
            [['deleted_at' => '2026-09-08 12:00:00'], false], [[], true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('changedGenerationScopes')]
    public function testDelayedRegenerationRejectsChangedCurrentScope(array $changed, bool $transferHotel): void
    {
        $report = $this->service()->generate([904], 904, '2026-09-08', 4, ['use_llm' => false]);
        $stale = Db::name('ai_daily_reports')->where('id', $report['id'])->find();
        if ($changed !== []) Db::name('ai_daily_reports')->where('id', $report['id'])->update($changed);
        if ($transferHotel) Db::name('hotels')->where('id', 904)->update(['tenant_id' => 9005]);
        $before = Db::name('ai_daily_reports')->where('id', $report['id'])->find();
        try {
            AiDailyCompetitionBundlePersistenceService::persistReport($stale, $stale, '2026-09-08 12:00:00', 904, '2026-09-08');
            self::fail('The prepared result no longer owns this current row.');
        } catch (\RuntimeException $error) {
            self::assertSame('competition_report_scope_mismatch', $error->getMessage());
        }
        self::assertSame($before, Db::name('ai_daily_reports')->where('id', $report['id'])->find());
    }

    public function testRegenerationPreservesLongLegacyHistoryAndLatestRejectedUsefulness(): void
    {
        $service = $this->service();
        $report = $service->generate([904], 904, '2026-09-08', 4, ['use_llm' => false]);
        $stale = Db::name('ai_daily_reports')->where('id', $report['id'])->find();
        for ($index = 0; $index < 105; $index++) {
            $saved = $service->recordHumanJudgment($report['id'], [904], 4, [
                'target_type' => 'report_usefulness', 'decision' => $index === 104 ? 'rejected' : 'accepted',
                'comment' => 'synthetic-' . $index,
                'request_id' => sprintf('e5f1c348-5285-499b-85d3-%012d', $index),
            ]);
        }
        AiDailyCompetitionBundlePersistenceService::persistReport($stale, $stale, '2026-09-08 12:00:00', 904, '2026-09-08');
        $after = $service->read($report['id'], [904]);
        self::assertSame($saved['human_judgments'], $after['human_judgments']);
        self::assertCount(105, $after['human_judgments']);
        self::assertSame('confirmed_not_useful', $after['trial_validation']['user_confirmed_useful']['status']);
        $retried = $service->recordHumanJudgment($report['id'], [904], 4, [
            'target_type' => 'report_usefulness', 'decision' => 'accepted', 'comment' => 'synthetic-0',
            'request_id' => 'e5f1c348-5285-499b-85d3-000000000000',
        ]);
        self::assertCount(105, $retried['human_judgments']);
        self::assertSame($after['human_judgments'][0]['id'], $retried['human_judgments'][0]['id']);
    }

    public function testRegenerationDoesNotEraseUnreadableCurrentSnapshot(): void
    {
        $report = $this->service()->generate([904], 904, '2026-09-08', 4, ['use_llm' => false]);
        $stale = Db::name('ai_daily_reports')->where('id', $report['id'])->find();
        Db::name('ai_daily_reports')->where('id', $report['id'])->update(['snapshot_json' => '{broken']);
        try {
            AiDailyCompetitionBundlePersistenceService::persistReport($stale, $stale, '2026-09-08 12:00:00', 904, '2026-09-08');
            self::fail('Unreadable manual history cannot be treated as empty.');
        } catch (\RuntimeException $error) {
            self::assertSame('competition_bundle_readback_json_invalid', $error->getMessage());
        }
        self::assertSame('{broken', Db::name('ai_daily_reports')->where('id', $report['id'])->value('snapshot_json'));
    }

    public function testRegenerationRecoversLegacyJudgmentWithoutTrialMetadata(): void
    {
        $service = $this->service();
        $report = $service->generate([904], 904, '2026-09-08', 4, ['use_llm' => false]);
        $stale = Db::name('ai_daily_reports')->where('id', $report['id'])->find();
        $legacy = ['human_judgments' => [['id' => 'legacy-review', 'target_type' => 'report_usefulness', 'decision' => 'rejected']]];
        Db::name('ai_daily_reports')->where('id', $report['id'])->update(['snapshot_json' => json_encode($legacy)]);
        AiDailyCompetitionBundlePersistenceService::persistReport($stale, $stale, '2026-09-08 12:00:00', 904, '2026-09-08');
        $after = $service->read($report['id'], [904]);
        self::assertSame($legacy['human_judgments'], $after['human_judgments']);
        self::assertSame('confirmed_not_useful', $after['trial_validation']['user_confirmed_useful']['status']);
        self::assertFalse($after['trial_validation']['passed']);
    }

    public function testRegenerationReadbackFailureRollsBackMergedSnapshot(): void
    {
        $service = $this->service();
        $report = $service->generate([904], 904, '2026-09-08', 4, ['use_llm' => false]);
        $stale = Db::name('ai_daily_reports')->where('id', $report['id'])->find();
        $service->recordHumanJudgment($report['id'], [904], 4, ['decision' => 'accepted', 'comment' => 'saved before generation']);
        $before = Db::name('ai_daily_reports')->where('id', $report['id'])->find();
        Db::execute("CREATE TRIGGER corrupt_regeneration AFTER UPDATE ON ai_daily_reports BEGIN
            UPDATE ai_daily_reports SET snapshot_json = '{broken' WHERE id = NEW.id; END");
        try {
            AiDailyCompetitionBundlePersistenceService::persistReport($stale, $stale, '2026-09-08 12:00:00', 904, '2026-09-08');
            self::fail('Readback corruption must roll back the generation write.');
        } catch (\RuntimeException $error) {
            self::assertSame('competition_bundle_readback_json_invalid', $error->getMessage());
        }
        self::assertSame($before, Db::name('ai_daily_reports')->where('id', $report['id'])->find());
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
