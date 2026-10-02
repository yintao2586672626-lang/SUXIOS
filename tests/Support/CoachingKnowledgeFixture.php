<?php
declare(strict_types=1);
namespace Tests\Support;

use app\service\ManagerCapabilityScoringService;
use think\App;
use think\facade\Config;
use think\facade\Db;

/** Synthetic data only; this helper always replaces the connection with SQLite. */
final class CoachingKnowledgeFixture
{
    public static function connect(string $path, bool $initialize = true): void
    {
        (new App(dirname(__DIR__, 2)))->initialize();
        restore_error_handler();
        restore_exception_handler();
        Config::set(['default' => 'sqlite', 'connections' => ['sqlite' => ['type' => 'sqlite', 'database' => $path,
            'prefix' => '', 'fields_strict' => false, 'debug' => false]]], 'database');
        Db::connect(null, true);
        if ($initialize) self::schema();
    }

    private static function schema(): void
    {
        Db::execute('CREATE TABLE hotels (id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT, status INTEGER)');
        Db::execute("INSERT INTO hotels VALUES (20,10,'隔离样例门店',1),(21,10,'另一门店',1),(30,11,'另一租户',1)");
        Db::execute('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT, display_name TEXT)');
        Db::execute("INSERT INTO roles VALUES (2,'manager','负责人')");
        Db::execute('CREATE TABLE users (id INTEGER PRIMARY KEY, tenant_id INTEGER, hotel_id INTEGER, role_id INTEGER, status INTEGER, username TEXT, realname TEXT)');
        Db::execute("INSERT INTO users VALUES (7,10,20,2,1,'synthetic-manager','隔离样例负责人'),(8,10,21,2,1,'synthetic-other','另一负责人'),(9,11,30,2,1,'synthetic-tenant','另一租户负责人')");
        Db::execute('CREATE TABLE user_hotel_permissions (user_id INTEGER, tenant_id INTEGER, hotel_id INTEGER, status TEXT, can_view INTEGER, expires_at TEXT)');
        foreach (['20260822_z_create_manager_capability_scoring.sql', '20260822_zz_create_manager_capability_followups.sql',
            '20260822_zzz_optimize_manager_capability.sql', '20260926_create_manager_coaching.sql'] as $file) self::tablesFromMigration($file);
        Db::execute('CREATE TABLE knowledge_units (unit_id INTEGER PRIMARY KEY AUTOINCREMENT, hotel_id INTEGER, name TEXT, source TEXT, status TEXT,
            description TEXT, tags TEXT, created_by INTEGER, stable_key TEXT UNIQUE, current_chunk_id INTEGER, lifecycle_status TEXT DEFAULT \'active\',
            lifecycle_reason TEXT, known_knowns TEXT, known_unknowns TEXT, created_at TEXT, updated_at TEXT)');
        Db::execute('CREATE TABLE knowledge_chunks (chunk_id INTEGER PRIMARY KEY AUTOINCREMENT, unit_id INTEGER, type TEXT, content TEXT,
            content_digest TEXT, lifecycle_status TEXT DEFAULT \'active\', created_by INTEGER, created_at TEXT,
            promotion_candidate_id INTEGER, operating_sop_version_id INTEGER)');
    }

    private static function column(string $name, string $definition): string
    {
        if (str_contains($definition, 'AUTO_INCREMENT')) return '`' . $name . '` INTEGER PRIMARY KEY AUTOINCREMENT';
        $type = preg_match('/^(BIGINT|INT|TINYINT)/i', $definition) ? 'INTEGER' : (preg_match('/^DECIMAL/i', $definition) ? 'REAL' : 'TEXT');
        preg_match('/\bDEFAULT\s+(NULL|CURRENT_TIMESTAMP|\d+|\x27[^\x27]*\x27)/i', $definition, $default);
        return '`' . $name . '` ' . $type . (str_contains($definition, 'NOT NULL') ? ' NOT NULL' : '') . (isset($default[1]) ? ' DEFAULT ' . $default[1] : '');
    }

    private static function tablesFromMigration(string $file): void
    {
        $sql = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/' . $file);
        preg_match_all('/CREATE TABLE IF NOT EXISTS `([^`]+)`\s*\((.*?)\) ENGINE=/s', $sql, $tables, PREG_SET_ORDER);
        foreach ($tables as $table) {
            $columns = [];
            foreach (explode("\n", $table[2]) as $line) {
                if (preg_match('/^\s*`([^`]+)`\s+(.*)/', $line, $match)) $columns[] = self::column($match[1], $match[2]);
                if (preg_match('/^\s*UNIQUE KEY `[^`]+`\s*(\([^\n]+\))/', $line, $match)) $columns[] = 'UNIQUE ' . $match[1];
            }
            Db::execute('CREATE TABLE `' . $table[1] . '` (' . implode(',', $columns) . ')');
        }
        preg_match_all('/ALTER TABLE `([^`]+)`\s+ADD COLUMN IF NOT EXISTS `([^`]+)`\s+([^;]+);/s', $sql, $additions, PREG_SET_ORDER);
        foreach ($additions as $add) Db::execute('ALTER TABLE `' . $add[1] . '` ADD COLUMN ' . self::column($add[2], $add[3]));
    }

    public static function createCase(): array
    {
        $date = date('Y-m-d');
        return (new ManagerCapabilityScoringService())->createCase(10, 20, 7, [
            'manager_user_id' => 7, 'business_date' => $date,
            'problem_facts' => '隔离样例：两笔交接记录缺少复核签字，需核实流程责任和操作标准。',
            'action_taken' => '隔离样例：负责人核对交接清单，安排示范和实操后复查。',
            'verification_status' => 'planned_verification', 'verification_text' => '隔离样例：计划抽查三笔记录并核对独立完成情况。',
            'followup_due_date' => date('Y-m-d', strtotime('+1 day')), 'evidence_type' => 'onsite_observation',
            'evidence_reference' => 'synthetic-fixture-only', 'evidence_date' => $date, 'idempotency_key' => 'fixture-case-' . bin2hex(random_bytes(5)),
        ])['case'];
    }

    public static function planInput(int $caseId): array
    {
        return ['case_id' => $caseId, 'title' => '交接记录的示范与复查', 'cause' => 'skill', 'cause_basis' => '隔离样例：实操时遗漏复核步骤',
            'objective' => '按清单独立完成交接并保留复核记录', 'steps' => '负责人示范，然后独立练习，记录抽查结果',
            'acceptance_criteria' => '抽查三笔，逐笔确认复核签字完整且可追溯', 'responsible_name' => '隔离样例负责人',
            'business_date' => date('Y-m-d'), 'due_on' => date('Y-m-d'), 'review_on' => date('Y-m-d'),
            'minimum_samples' => 3, 'knowledge_chunk_ids' => [], 'idempotency_key' => 'plan-' . bin2hex(random_bytes(5))];
    }
}
