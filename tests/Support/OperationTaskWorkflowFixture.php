<?php
declare(strict_types=1);
namespace Tests\Support;

use app\service\OperationTaskWorkflowService;
use think\facade\Config;
use think\facade\Db;

final class OperationTaskWorkflowFixture
{
    public static function connect(string $path): void
    {
        Config::set(['default' => 'file', 'stores' => ['file' => ['type' => 'File', 'path' => sys_get_temp_dir() . '/l06-workflow-cache/']]], 'cache');
        Config::set(['default' => 'file', 'channels' => ['file' => ['type' => 'File', 'path' => sys_get_temp_dir() . '/l06-workflow-log/']]], 'log');
        Config::set(['default' => 'workflow_synthetic', 'connections' => ['workflow_synthetic' => [
            'type' => 'sqlite', 'database' => $path, 'prefix' => '', 'fields_strict' => false,
        ]]], 'database');
        Db::connect(null, true);
    }

    public static function schema(): void
    {
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER, deleted_at TEXT)');
        Db::execute('CREATE TABLE operation_execution_intents (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, source_module TEXT, source_record_id INTEGER, platform TEXT, object_type TEXT, action_type TEXT, date_start TEXT, date_end TEXT, current_value_json TEXT, target_value_json TEXT, evidence_json TEXT, expected_metric TEXT, expected_delta REAL, risk_level TEXT, status TEXT, blocked_reason TEXT, created_by INTEGER, approved_by INTEGER, approved_at TEXT, review_remark TEXT, idempotency_key TEXT UNIQUE, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        Db::execute('CREATE TABLE operation_execution_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, intent_id INTEGER UNIQUE, execution_mode TEXT, operator_id INTEGER, target_value_json TEXT, current_value_json TEXT, status TEXT, blocked_reason TEXT, result_status TEXT, result_summary TEXT, executed_at TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        Db::execute('CREATE TABLE operation_execution_evidence (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, task_id INTEGER, evidence_type TEXT, before_json TEXT, after_json TEXT, attachment_path TEXT, platform_response_json TEXT, remark TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        Db::execute('CREATE TABLE operation_task_workflow_events (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, task_id INTEGER, version_no INTEGER, request_id TEXT, payload_json TEXT, content_digest TEXT, created_at TEXT, UNIQUE(tenant_id,hotel_id,task_id,version_no), UNIQUE(tenant_id,hotel_id,task_id,request_id))');
        Db::execute('CREATE TABLE operation_task_workflow_proposals (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, hotel_id INTEGER, recommendation_id TEXT, intent_id INTEGER, request_digest TEXT, proposal_json TEXT, created_at TEXT, UNIQUE(tenant_id,hotel_id,recommendation_id))');
    }

    public static function seed(): void
    {
        Db::name('hotels')->insertAll([['id' => 7, 'tenant_id' => 42], ['id' => 8, 'tenant_id' => 43]]);
        foreach ([1, 2, 3, 4] as $id) {
            $hotel = $id === 4 ? 8 : 7;
            Db::name('operation_execution_intents')->insert([
                'id' => $id, 'tenant_id' => $hotel === 7 ? 42 : 43, 'hotel_id' => $hotel,
                'source_module' => 'manual', 'source_record_id' => 0, 'platform' => $id === 3 ? 'meituan' : 'ctrip',
                'object_type' => 'operation_checklist', 'action_type' => 'price_check', 'date_start' => '2026-09-08', 'date_end' => '2026-09-08',
                'target_value_json' => '{"object_ref":"room:synthetic-101"}', 'current_value_json' => '{}', 'evidence_json' => '{}', 'status' => 'approved',
            ]);
            Db::name('operation_execution_tasks')->insert(['id' => $id, 'tenant_id' => $hotel === 7 ? 42 : 43,
                'hotel_id' => $hotel, 'intent_id' => $id, 'status' => 'pending_execute', 'execution_mode' => 'manual']);
        }
    }

    public static function service(string $now = '2026-09-16 12:00:00'): OperationTaskWorkflowService
    {
        return new OperationTaskWorkflowService(static fn(): string => $now, static fn(int $id, array $scope): bool => $id === 3 && $scope['hotel_id'] === 7);
    }
    public static function scope(): array { return ['tenant_id' => 42, 'hotel_id' => 7, 'platform' => 'ctrip', 'date_start' => '2026-09-08', 'date_end' => '2026-09-08', 'object_ref' => 'room:synthetic-101']; }
    public static function window(): array { return ['baseline_start' => '2026-09-08', 'baseline_end' => '2026-09-08', 'followup_start' => '2026-09-09', 'followup_end' => '2026-09-09']; }
    public static function configure(string $type = 'price_check'): array
    {
        return ['workflow_type' => $type, 'scope' => self::scope(), 'assignee_id' => 3, 'due_date' => '2026-09-08',
            'completion_criteria' => ['核对同房型展示', '记录发现与处理'], 'review_window' => self::window(), 'dependencies' => []];
    }
    public static function record(string $kind = 'manual_check'): array
    {
        return ['scope' => self::scope(), 'kind' => $kind, 'performed_on' => '2026-09-08', 'reference' => 'synthetic:check-1',
            'note' => 'synthetic：逐项人工核查记录', 'checks' => self::configure()['completion_criteria']];
    }
    public static function recommendation(): array
    {
        return ['recommendation_id' => 'synthetic:diagnosis-1', 'problem' => 'synthetic：检查房型展示与转化信息',
            'status' => 'proposed', 'requires_human_confirmation' => true, 'workflow_type' => 'conversion_optimization',
            'evidence_snapshot' => ['fingerprint' => str_repeat('a', 64), 'scope' => self::scope(), 'source_refs' => ['synthetic:report-1'], 'facts' => []],
            'completion_criteria' => self::configure()['completion_criteria'], 'review_window' => self::window()];
    }
}
