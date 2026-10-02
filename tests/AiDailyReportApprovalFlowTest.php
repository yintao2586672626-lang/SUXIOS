<?php
declare(strict_types=1);

namespace Tests;

use app\controller\RevenueResearch;
use app\service\AiDailyReportService;
use app\service\AiDecisionQualityService;
use app\service\OperationManagementService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use think\facade\Config;
use think\facade\Db;

final class AiDailyReportApprovalFlowTest extends TestCase
{
    private string $databasePath;
    private array $originalConfig;
    private array $originalCacheConfig;
    private array $originalLogConfig;

    protected function setUp(): void
    {
        $this->originalConfig = Config::get('database', []);
        $this->originalCacheConfig = Config::get('cache', []);
        $this->originalLogConfig = Config::get('log', []);
        $connection = 'ai_daily_approval_' . bin2hex(random_bytes(8));
        $this->databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $connection . '.sqlite';
        Config::set(['default' => $connection, 'connections' => [$connection => [
            'type' => 'sqlite', 'database' => $this->databasePath,
            'prefix' => '', 'fields_strict' => true,
        ]]], 'database');
        $cachePath = (string)getenv('SUXIOS_CACHE_PATH');
        Config::set(['default' => 'file', 'stores' => [
            'file' => ['type' => 'File', 'path' => $cachePath],
        ]], 'cache');
        Config::set(['default' => 'file', 'channels' => [
            'file' => ['type' => 'File', 'path' => $cachePath . '/logs'],
        ]], 'log');
        Db::connect(null, true);
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL)');
        Db::name('hotels')->insert(['id' => 904, 'tenant_id' => 9004]);
        Db::execute('CREATE TABLE ai_daily_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER,
            report_date TEXT, status TEXT, generation_mode TEXT, model_key TEXT,
            model_status TEXT, model_message TEXT, summary TEXT,
            yesterday_result_json TEXT, abnormal_metrics_json TEXT,
            competitor_changes_json TEXT, data_gaps_json TEXT,
            recommended_actions_json TEXT, source_refs_json TEXT, snapshot_json TEXT,
            created_by INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT,
            input_fingerprint TEXT, prompt_version TEXT, cache_hit_count INTEGER)');
        Db::execute('CREATE TABLE operation_execution_intents (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            source_module TEXT NOT NULL, source_record_id INTEGER NOT NULL,
            idempotency_key TEXT UNIQUE, hotel_id INTEGER NOT NULL,
            platform TEXT, object_type TEXT, action_type TEXT, date_start TEXT,
            date_end TEXT, current_value_json TEXT, target_value_json TEXT,
            evidence_json TEXT, expected_metric TEXT, expected_delta REAL,
            risk_level TEXT, blocked_reason TEXT, status TEXT,
            created_by INTEGER, approved_by INTEGER, approved_at TEXT,
            review_remark TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        Db::execute('CREATE TABLE operation_execution_tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            intent_id INTEGER, hotel_id INTEGER, execution_mode TEXT,
            operator_id INTEGER, target_value_json TEXT, current_value_json TEXT,
            blocked_reason TEXT, action_track_id INTEGER, result_status TEXT,
            result_summary TEXT, status TEXT, executed_at TEXT,
            created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        Db::execute('CREATE TABLE operation_execution_evidence (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            task_id INTEGER, evidence_type TEXT, before_json TEXT, after_json TEXT,
            attachment_path TEXT, platform_response_json TEXT, remark TEXT,
            created_by INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
    }

    protected function tearDown(): void
    {
        Db::connect()->close();
        Config::set($this->originalConfig, 'database');
        Config::set($this->originalCacheConfig, 'cache');
        Config::set($this->originalLogConfig, 'log');
        @unlink($this->databasePath);
    }

    public function testFullApprovalRejectsDriftAndApprovesUnchangedReadback(): void
    {
        $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        $tomorrow = (new DateTimeImmutable($today, new DateTimeZone('Asia/Shanghai')))
            ->modify('+1 day')->format('Y-m-d');
        $action = [
            'title' => '复核携程订单与房价',
            'action' => '明日核对携程酒店房价与订单，执行后记录同渠道订单前后结果',
            'reason' => '同酒店携程订单依据已回读，需人工复核',
            'platform' => 'ctrip', 'object_type' => 'campaign', 'action_type' => 'promotion_review',
            'execution_time' => $tomorrow, 'expected_metric' => 'orders',
            'target_value' => ['campaign_type' => 'promotion_review',
                'target_metric' => 'orders', 'target_orders' => 20],
            'current_value' => ['orders' => 10], 'expected_delta' => 10,
            'risk_level' => 'medium',
        ];
        $sourceRefs = [[
            'ref' => 'online_daily_data#904', 'source' => 'online_daily_data',
            'system_hotel_id' => 904, 'platform' => 'ctrip',
            'data_date' => $today, 'metric_scope' => 'ota_channel',
            'quality_status' => 'available', 'readback_verified' => true,
        ]];
        $reportId = (int)Db::name('ai_daily_reports')->insertGetId([
            'tenant_id' => 9004, 'hotel_id' => 904, 'report_date' => $today,
            'status' => 'completed', 'summary' => '携程渠道订单复核',
            'yesterday_result_json' => '{}', 'abnormal_metrics_json' => '[]',
            'competitor_changes_json' => '[]', 'data_gaps_json' => '[]',
            'recommended_actions_json' => $this->json([$action]),
            'source_refs_json' => $this->json($sourceRefs),
            'snapshot_json' => $this->json(['input_trust' => ['readback_verified' => true],
                'report_scope' => ['hotel_id' => 904, 'report_date' => $today,
                    'source_scope' => 'ota_channel']]),
            'created_by' => 3, 'created_at' => $today . ' 09:00:00',
            'updated_at' => $today . ' 09:00:00', 'cache_hit_count' => 0,
        ]);
        $reportService = new AiDailyReportService();
        $read = $reportService->enrichReportRows([
            Db::name('ai_daily_reports')->where('id', $reportId)->find(),
        ], [904], 904)[0];
        self::assertTrue($read['recommended_actions'][0]['can_create_execution_intent'] ?? false,
            $read['recommended_actions'][0]['blocked_reason'] ?? 'recommendation not ready');

        $created = $reportService->createExecutionIntentFromAction($reportId, 0, [904], 3);
        $intentId = (int)$created['execution_intent']['id'];
        self::assertSame('pending_approval', $created['execution_intent']['status']);
        $operation = new OperationManagementService();
        Db::name('ai_daily_reports')->where('id', $reportId)->update(['tenant_id' => 9003]);
        try {
            $operation->approveExecutionIntent($intentId, true, 'human review', 3, [904]);
            self::fail('Previous-tenant report must not authorize a current-tenant intent.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('source', $error->getMessage());
        }
        self::assertSame('pending_approval', Db::name('operation_execution_intents')
            ->where('id', $intentId)->value('status'));
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->count());
        Db::name('ai_daily_reports')->where('id', $reportId)->update(['tenant_id' => 9004]);

        $savedActionsJson = (string)Db::name('ai_daily_reports')->where('id', $reportId)
            ->value('recommended_actions_json');
        $changedActions = json_decode($savedActionsJson, true, 512, JSON_THROW_ON_ERROR);
        $changedActions[0]['reason'] = '原判断依据已变化';
        Db::name('ai_daily_reports')->where('id', $reportId)
            ->update(['recommended_actions_json' => $this->json($changedActions)]);
        try {
            $operation->approveExecutionIntent($intentId, true, 'human review', 3, [904]);
            self::fail('Changed report reason must prevent approval.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('provenance changed', $error->getMessage());
        }
        self::assertSame('pending_approval', Db::name('operation_execution_intents')
            ->where('id', $intentId)->value('status'));
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->count());

        Db::name('ai_daily_reports')->where('id', $reportId)
            ->update(['recommended_actions_json' => $savedActionsJson]);
        $savedTargetJson = (string)Db::name('operation_execution_intents')->where('id', $intentId)
            ->value('target_value_json');
        $changedTarget = json_decode($savedTargetJson, true, 512, JSON_THROW_ON_ERROR);
        $changedTarget['target_orders'] = 21;
        Db::name('operation_execution_intents')->where('id', $intentId)
            ->update(['target_value_json' => $this->json($changedTarget)]);
        try {
            $operation->approveExecutionIntent($intentId, true, 'human review', 3, [904]);
            self::fail('Changed persisted target must prevent approval.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('provenance changed', $error->getMessage());
        }
        self::assertSame('pending_approval', Db::name('operation_execution_intents')
            ->where('id', $intentId)->value('status'));
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->count());

        Db::name('operation_execution_intents')->where('id', $intentId)
            ->update(['target_value_json' => $savedTargetJson]);
        $approved = $operation->approveExecutionIntent($intentId, true, 'human review', 3, [904]);
        self::assertSame('approved', $approved['status']);
        self::assertSame(1, (int)Db::name('operation_execution_tasks')
            ->where('intent_id', $intentId)->count());
        $taskId = (int)Db::name('operation_execution_tasks')
            ->where('intent_id', $intentId)->value('id');
        $readback = $reportService->read($reportId, [904]);
        self::assertSame($intentId, $readback['recommended_actions'][0]['execution_intent_id']);
        self::assertSame('approved', $readback['recommended_actions'][0]['execution_status']);
        self::assertSame($taskId, $readback['recommended_actions'][0]['execution_flow']['task_id']);
        self::assertSame('approved_pending_execution',
            $readback['recommended_actions'][0]['action_readiness']['stage']);

        $retry = $reportService->createExecutionIntentFromAction($reportId, 0, [904], 3);
        self::assertSame($intentId, (int)$retry['execution_intent']['id']);
        self::assertSame(1, (int)Db::name('operation_execution_intents')->count());
        self::assertSame(1, (int)Db::name('operation_execution_tasks')->count());
    }

    public function testRevenueResearchCannotApproveAnExpiredExecutionWindow(): void
    {
        $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        $yesterday = (new DateTimeImmutable($today, new DateTimeZone('Asia/Shanghai')))
            ->modify('-1 day')->format('Y-m-d');
        $recommendation = [
            'title' => '复核携程渠道需求预测', 'action' => '按研究结果人工复核渠道订单',
            'platform' => 'ctrip', 'can_create_execution_intent' => true,
            'decision_quality' => [
                'contract_version' => AiDecisionQualityService::CONTRACT_VERSION,
                'execution_ready' => true,
            ],
        ];
        $operation = new OperationManagementService();
        $created = $operation->createExecutionIntent([904], 904, [
            'source_module' => 'revenue_research', 'source_record_id' => 410,
            'hotel_id' => 904, 'platform' => 'ctrip', 'object_type' => 'revenue_research',
            'action_type' => 'demand_forecast', 'date_start' => $today, 'date_end' => $today,
            'target_value' => ['research_product' => 'demand-forecast',
                'action_text' => '按研究结果人工复核渠道订单',
                'target_metric' => 'revenue_research_closure'],
            'evidence' => ['decision_recommendation' => $recommendation,
                'research_readiness_stage' => 'research_ready_for_execution',
                'execution_ready' => true, 'metric_scope' => 'ota_channel'],
            'expected_metric' => 'revenue_research_closure', 'expected_delta' => 0,
            'risk_level' => 'medium', 'status' => 'pending_approval',
        ], 3, false, null, true);
        $intentId = (int)$created['id'];
        Db::name('operation_execution_intents')->where('id', $intentId)
            ->update(['date_start' => $yesterday, 'date_end' => $yesterday]);
        try {
            $operation->approveExecutionIntent($intentId, true, 'human review', 3, [904]);
            self::fail('Expired research action window must not approve.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('过期', $error->getMessage());
        }
        self::assertSame('pending_approval', Db::name('operation_execution_intents')
            ->where('id', $intentId)->value('status'));
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->count());
        Db::name('operation_execution_intents')->where('id', $intentId)
            ->update(['date_start' => $today, 'date_end' => $yesterday]);
        try {
            $operation->approveExecutionIntent($intentId, true, 'human review', 3, [904]);
            self::fail('Inverted research action window must not approve.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('倒置', $error->getMessage());
        }
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->count());
        Db::name('operation_execution_intents')->where('id', $intentId)
            ->update(['date_start' => '', 'date_end' => $today]);
        try {
            $operation->approveExecutionIntent($intentId, true, 'human review', 3, [904]);
            self::fail('Missing research action date must not approve.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('execution date', $error->getMessage());
        }
        self::assertSame(0, (int)Db::name('operation_execution_tasks')->count());
        Db::name('operation_execution_intents')->where('id', $intentId)
            ->update(['date_start' => $today, 'date_end' => $today]);
        $approved = $operation->approveExecutionIntent($intentId, true, 'human review', 3, [904]);
        self::assertSame('approved', $approved['status']);
        self::assertSame(1, (int)Db::name('operation_execution_tasks')->count());
    }

    public function testRevenueResearchDuplicateLookupKeepsCurrentWindowAndSkipsExpiredOne(): void
    {
        $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
        $yesterday = (new DateTimeImmutable($today, new DateTimeZone('Asia/Shanghai')))
            ->modify('-1 day')->format('Y-m-d');
        $currentId = (int)Db::name('operation_execution_intents')->insertGetId([
            'tenant_id' => 9004, 'source_module' => 'revenue_research',
            'source_record_id' => 410, 'hotel_id' => 904, 'date_end' => $today,
            'status' => 'pending_approval',
        ]);
        Db::name('operation_execution_intents')->insert([
            'tenant_id' => 9004, 'source_module' => 'revenue_research',
            'source_record_id' => 410, 'hotel_id' => 904, 'date_end' => $yesterday,
            'status' => 'pending_approval',
        ]);
        Db::name('operation_execution_intents')->insert([
            'tenant_id' => 9003, 'source_module' => 'revenue_research',
            'source_record_id' => 410, 'hotel_id' => 904, 'date_end' => $today,
            'status' => 'pending_approval',
        ]);
        $controller = (new \ReflectionClass(RevenueResearch::class))->newInstanceWithoutConstructor();
        $rows = (new \ReflectionMethod(RevenueResearch::class, 'existingExecutionIntentRows'))
            ->invoke($controller, ['source_module' => 'revenue_research',
                'source_record_id' => 410], 904);
        self::assertCount(1, $rows);
        self::assertSame($currentId, (int)$rows[0]['id']);
        Db::name('operation_execution_intents')->where('id', $currentId)
            ->update(['date_end' => $yesterday]);
        $rows = (new \ReflectionMethod(RevenueResearch::class, 'existingExecutionIntentRows'))
            ->invoke($controller, ['source_module' => 'revenue_research',
                'source_record_id' => 410], 904);
        self::assertSame([], $rows);
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
