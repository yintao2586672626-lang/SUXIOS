<?php
declare(strict_types=1);

namespace Tests;

use app\service\DailyOneThingInputService;
use app\service\DailyOneThingPersonalizationService;
use app\service\DualOtaFieldClosureService;
use app\service\OperatingOpportunityLabService;
use app\service\OperatingOutcomeLearningRuntimeService;
use app\service\OperationManagementService;
use PHPUnit\Framework\TestCase;
use think\facade\Config;
use think\facade\Db;

final class DailyOneThingBlockedReadbackTest extends TestCase
{
    private array $originalConfig;
    private array $originalCacheConfig;
    private array $originalLogConfig;
    private string $connection;

    protected function setUp(): void
    {
        $this->originalConfig = (array)Config::get('database');
        $this->originalCacheConfig = (array)Config::get('cache');
        $this->originalLogConfig = (array)Config::get('log');
        Config::set(['default' => 'file', 'stores' => ['file' => [
            'type' => 'File', 'path' => (string)getenv('SUXIOS_CACHE_PATH'),
        ]]], 'cache');
        Config::set(['default' => 'file', 'channels' => ['file' => [
            'type' => 'File', 'path' => (string)getenv('SUXIOS_CACHE_PATH') . '/logs/',
        ]]], 'log');
        $this->connection = 'daily_blocked_fixture_' . bin2hex(random_bytes(6));
        Config::set(['default' => $this->connection, 'connections' => [$this->connection => [
            'type' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'fields_strict' => false,
        ]]], 'database');
        Db::connect(null, true);
        $this->createSchema();
        Db::name('hotels')->insert(['id' => 80, 'tenant_id' => 80, 'name' => 'Synthetic hotel', 'status' => 1, 'owner_user_id' => 7, 'created_by' => 7]);
        Db::name('roles')->insert(['id' => 1, 'name' => 'admin', 'level' => 1, 'permissions' => '["all"]', 'status' => 1]);
        Db::name('users')->insert(['id' => 7, 'tenant_id' => 80, 'role_id' => 1, 'status' => 1]);
    }

    protected function tearDown(): void
    {
        Db::connect($this->connection)->close();
        Config::set($this->originalConfig, 'database');
        Config::set($this->originalCacheConfig, 'cache');
        Config::set($this->originalLogConfig, 'log');
    }

    public function testHumanRejectionRemainsTheSameBlockedActionInOverviewAndReplay(): void
    {
        $lab = $this->lab();
        $saved = $lab->saveDailyPriority(80, 80, 7, '2026-09-27', 'synthetic-first-save');
        $intentId = $saved['execution_intent_id'];
        $runId = $saved['run']['id'];
        self::assertSame('pending_approval', $saved['lifecycle_status']);
        $rejected = (new OperationManagementService())->approveExecutionIntent($intentId, false, 'Synthetic human rejection', 7, [80]);
        self::assertSame('blocked', $rejected['status']);
        self::assertSame([], $rejected['tasks']);
        $overview = $lab->overview(80, 80, '2026-09-27', 7);
        self::assertSame('saved_current', $overview['today_state']);
        self::assertSame('blocked', $overview['today_lifecycle_status']);
        self::assertSame($intentId, $overview['today_execution_intent_id']);
        self::assertSame((int)$runId, (int)$overview['today_saved_run']['id']);
        self::assertSame('blocked', $overview['today']['status']);
        $replay = $lab->saveDailyPriority(80, 80, 7, '2026-09-27', 'synthetic-second-save');
        self::assertTrue($replay['replayed']);
        self::assertTrue($replay['readback_verified']);
        self::assertSame('blocked', $replay['lifecycle_status']);
        self::assertSame($intentId, $replay['execution_intent_id']);
        self::assertSame(0, $replay['execution_task_count']);
        self::assertSame(0, $replay['external_write_count']);
        self::assertSame(1, Db::name('operating_opportunity_runs')->count());
        self::assertSame(1, Db::name('operation_execution_intents')->count());
        self::assertSame(0, Db::name('operation_execution_tasks')->count());
        self::assertSame('Synthetic human rejection', Db::name('operation_execution_intents')->where('id', $intentId)->value('review_remark'));
    }

    private function lab(): OperatingOpportunityLabService
    {
        $closure = DualOtaFieldClosureService::evaluate(['id' => 80, 'tenant_id' => 80], '2026-09-27', []);
        self::assertSame('partial', $closure['status']);
        $input = new DailyOneThingInputService(
            static fn(): array => $closure,
            static fn(): array => ['data_status' => 'ok', 'list' => []],
            static fn(): ?array => null,
            static fn(): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')),
            static fn(): array => ['contract_version' => 'ota_reputation_daily_signal.v1', 'tenant_id' => 80, 'hotel_id' => 80,
                'business_date' => '2026-09-27', 'signals' => [], 'boundary' => ['external_write_count' => 0]]
        );
        return new OperatingOpportunityLabService(null, $input,
            new DailyOneThingPersonalizationService(null, null, static fn(): array => [], static fn(): array => []),
            new OperatingOutcomeLearningRuntimeService(static fn(): array => ['list' => [], 'truncated' => false]));
    }

    public function testBlockedReadbackKeepsScopeDigestAndTaskCardinalityGuards(): void
    {
        $service = new OperatingOpportunityLabService();
        $assertReadback = new \ReflectionMethod($service, 'assertDailyIntentReadback');
        $run = ['id' => 23, 'tenant_id' => 80, 'system_hotel_id' => 80];
        $selected = ['content_digest' => str_repeat('a', 64)];
        $intent = ['tenant_id' => 80, 'hotel_id' => 80, 'source_module' => 'daily_one_thing',
            'source_record_id' => 23, 'status' => 'blocked', 'tasks' => [],
            'action_management' => ['action_card' => [
                'contract_version' => \app\service\OperationActionLifecycleService::DAILY_CARD_CONTRACT_VERSION,
                'trace' => ['daily_selection_digest' => $selected['content_digest']],
            ]]];
        foreach ([['blocked', []], ['pending_approval', []], ['approved', [['id' => 1]]]] as [$status, $tasks]) {
            $valid = array_replace($intent, ['status' => $status, 'tasks' => $tasks]);
            self::assertNull($assertReadback->invoke($service, $valid, $run, $selected));
        }
        $invalid = [];
        foreach (['tenant_id' => 81, 'hotel_id' => 81, 'source_record_id' => 24, 'source_module' => 'other'] as $key => $value) {
            $invalid[] = array_replace($intent, [$key => $value]);
        }
        $badDigest = $intent;
        $badDigest['action_management']['action_card']['trace']['daily_selection_digest'] = str_repeat('b', 64);
        $invalid[] = $badDigest;
        foreach ([['blocked', [['id' => 1]]], ['pending_approval', [['id' => 1]]],
            ['approved', []], ['approved', [['id' => 1], ['id' => 2]]], ['cancelled', []]] as [$status, $tasks]) {
            $invalid[] = array_replace($intent, ['status' => $status, 'tasks' => $tasks]);
        }
        foreach ($invalid as $case) {
            try {
                $assertReadback->invoke($service, $case, $run, $selected);
                self::fail('Mismatched daily intent must not pass exact readback');
            } catch (\RuntimeException $error) {
                self::assertSame('每日一件事保存后生命周期精确回读失败', $error->getMessage());
            }
        }
    }

    private function createSchema(): void
    {
        // Dedicated in-memory fixtures only; no application initialization or real database configuration.
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT, status INTEGER, owner_user_id INTEGER, created_by INTEGER)');
        Db::execute('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT, level INTEGER, permissions TEXT, status INTEGER)');
        Db::execute('CREATE TABLE users (id INTEGER PRIMARY KEY, tenant_id INTEGER, role_id INTEGER, status INTEGER)');
        Db::execute('CREATE TABLE user_hotel_permissions (id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, hotel_id INTEGER, status TEXT, can_view INTEGER, can_operation INTEGER, expires_at TEXT)');
        Db::execute('CREATE TABLE operating_opportunity_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, system_hotel_id INTEGER,
            feature_key TEXT, business_date TEXT, source_quality_status TEXT, source_reference TEXT,
            input_json TEXT, result_json TEXT, input_digest TEXT, result_digest TEXT, idempotency_key TEXT UNIQUE,
            created_by INTEGER, created_at TEXT)');
        Db::execute('CREATE TABLE operation_execution_intents (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, idempotency_key TEXT UNIQUE,
            source_module TEXT, source_record_id INTEGER, hotel_id INTEGER, platform TEXT, object_type TEXT,
            action_type TEXT, date_start TEXT, date_end TEXT, current_value_json TEXT, target_value_json TEXT,
            evidence_json TEXT, expected_metric TEXT, expected_delta REAL, risk_level TEXT, status TEXT,
            blocked_reason TEXT DEFAULT "", review_remark TEXT DEFAULT "", created_by INTEGER,
            approved_by INTEGER DEFAULT 0, approved_at TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        Db::execute('CREATE TABLE operation_execution_tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, intent_id INTEGER, hotel_id INTEGER,
            execution_mode TEXT DEFAULT "manual", operator_id INTEGER DEFAULT 0, target_value_json TEXT,
            current_value_json TEXT, blocked_reason TEXT DEFAULT "", action_track_id INTEGER DEFAULT 0,
            result_status TEXT DEFAULT "observing", result_summary TEXT DEFAULT "", status TEXT DEFAULT "pending_execute",
            executed_at TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        Db::execute('CREATE TABLE operation_execution_evidence (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER DEFAULT 0, task_id INTEGER DEFAULT 0,
            hotel_id INTEGER DEFAULT 0, evidence_type TEXT DEFAULT "", evidence_json TEXT, created_by INTEGER DEFAULT 0,
            created_at TEXT, deleted_at TEXT)');
        Db::execute('CREATE TABLE operation_action_lifecycle_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, intent_id INTEGER,
            task_id INTEGER, sequence_no INTEGER, event_type TEXT, from_status TEXT, to_status TEXT,
            actor_id INTEGER, event_payload_json TEXT, previous_digest TEXT, content_digest TEXT, created_at TEXT)');
        Db::execute('CREATE TABLE operation_action_reviews (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, intent_id INTEGER,
            task_id INTEGER, effect_review_id INTEGER, contract_version TEXT, metric_key TEXT, metric_unit TEXT,
            baseline_window_json TEXT, followup_window_json TEXT, before_value REAL, after_value REAL,
            delta_value REAL, metric_change_status TEXT, evidence_sufficiency TEXT, evidence_refs_json TEXT,
            non_attribution_reasons_json TEXT, recommendation TEXT, result_status TEXT, result_summary TEXT,
            causality_claimed INTEGER, reviewed_by INTEGER, reviewed_at TEXT, previous_review_id INTEGER,
            previous_digest TEXT, content_digest TEXT, created_at TEXT)');
    }
}
