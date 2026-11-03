<?php
declare(strict_types=1);

// Aggregate-only local audit: no credentials, payloads, guest data or writes.
require dirname(__DIR__) . '/vendor/autoload.php';

use think\App;
use think\facade\Db;

(new App(dirname(__DIR__)))->initialize();
date_default_timezone_set('Asia/Shanghai');
$tables = Db::query('SELECT TABLE_NAME AS table_name, TABLE_ROWS AS estimated_rows, DATA_LENGTH + INDEX_LENGTH AS bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME');
$columns = Db::query('SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION');
$fields = [];
foreach ($columns as $column) {
    $fields[$column['table_name']][] = $column['column_name'];
}
$groups = [
    'identity' => ['hotels', 'tenants', 'users', 'user_hotel_permissions'],
    'ota' => ['online_daily_data', 'platform_data_sources', 'platform_data_sync_tasks', 'platform_data_raw_records', 'ota_ctrip_orders', 'ota_meituan_orders', 'ota_ctrip_metric_facts', 'hotel_collection_plans', 'hotel_collection_plan_runs'],
    'pms' => ['daily_reports', 'dingdandao_pms_integrations', 'meituan_cloud_pms_integrations', 'dingdandao_operating_target_captures', 'meituan_cloud_pms_captures', 'operating_target_daily_records', 'operating_target_daily_snapshots', 'hotel_on_books_snapshots'],
    'operations' => ['operation_action_tracks', 'operation_action_reviews', 'operation_execution_intents', 'operation_execution_tasks', 'operation_execution_evidence', 'operation_effect_reviews', 'hotel_operating_cycles', 'operating_opportunity_runs', 'monthly_tasks', 'hotel_operating_questions', 'hotel_operating_memories', 'hotel_operating_sop_versions', 'hotel_operating_sop_replications'],
    'analysis' => ['agent_logs', 'ai_daily_reports', 'demand_forecasts', 'price_suggestions', 'competitor_price_log', 'hotel_operating_goal_contracts', 'weekly_operating_plan_snapshots'],
    'optional' => ['opening_projects', 'opening_tasks', 'quant_simulation_records', 'strategy_simulation_records', 'strategy_data_snapshots', 'feasibility_reports', 'expansion_records', 'transfer_records'],
    'reference' => ['knowledge_units', 'knowledge_chunks', 'knowledge_base'],
];
$report = ['generated_at' => date(DATE_ATOM), 'mode' => 'read_only_aggregate', 'table_count' => count($tables), 'tables' => $tables, 'modules' => [], 'fact_groups' => [], 'scope_integrity' => []];
foreach ($groups as $group => $names) {
    foreach ($names as $table) {
        if (!isset($fields[$table])) {
            $report['modules'][$group][$table] = ['state' => 'table_absent'];
            continue;
        }
        $query = Db::name($table);
        $summary = ['state' => 'present', 'rows' => (int)(clone $query)->count()];
        foreach (['created_at', 'updated_at', 'data_date', 'business_date', 'report_date', 'captured_at', 'create_time', 'update_time', 'fetch_time'] as $dateField) {
            if (in_array($dateField, $fields[$table], true)) {
                $dateSummary = Db::query('SELECT MAX(`' . $dateField . '`) AS latest_value FROM `' . $table . '`');
                $summary[$dateField . '_latest'] = $dateSummary[0]['latest_value'] ?? null;
            }
        }
        foreach (['status', 'lifecycle_status', 'source_module', 'ingestion_method', 'plan_status', 'validation_status', 'enabled', 'execution_mode', 'review_status'] as $stateField) {
            if (in_array($stateField, $fields[$table], true)) {
                $summary[$stateField . '_counts'] = (clone $query)->field($stateField . ',COUNT(*) AS rows_count')->group($stateField)->select()->toArray();
            }
        }
        foreach (['tenant_id', 'system_hotel_id', 'hotel_id'] as $scopeField) {
            if (in_array($scopeField, $fields[$table], true)) {
                $summary[$scopeField . '_distinct'] = (int)(clone $query)->count('DISTINCT ' . $scopeField);
                if ($table === 'online_daily_data' && $scopeField === 'hotel_id') {
                    // OTA hotel_id is an external string identifier, not an internal hotel FK.
                    $summary['hotel_id_semantics'] = 'external_platform_id_not_internal_scope';
                    $summary['hotel_id_blank'] = (int)(clone $query)->whereRaw("hotel_id IS NULL OR hotel_id = ''")->count();
                    continue;
                }
                $summary[$scopeField . '_missing'] = (int)(clone $query)->whereRaw('COALESCE(`' . $scopeField . '`, 0) <= 0')->count();
            }
        }
        $report['modules'][$group][$table] = $summary;
    }
}
if (isset($fields['online_daily_data'])) {
    $groupFields = array_values(array_intersect(['source', 'platform', 'data_type', 'data_period', 'validation_status', 'readback_verified', 'ingestion_method'], $fields['online_daily_data']));
    $report['fact_groups'] = Db::name('online_daily_data')->field(implode(',', $groupFields) . ',COUNT(*) AS rows_count,MIN(data_date) AS first_date,MAX(data_date) AS last_date')
        ->group(implode(',', $groupFields))->select()->toArray();
    $report['scope_integrity'] = Db::query('SELECT COUNT(*) AS total_rows, SUM(h.id IS NULL) AS missing_hotel_rows, SUM(h.id IS NOT NULL AND (o.tenant_id IS NULL OR o.tenant_id <> h.tenant_id)) AS mismatched_tenant_rows FROM online_daily_data o LEFT JOIN hotels h ON h.id = o.system_hotel_id');
    $report['ota_indexes'] = Db::query('SHOW INDEX FROM online_daily_data');
    $report['ota_table_definition'] = Db::query('SHOW CREATE TABLE online_daily_data')[0]['Create Table'];
}
$report['business_table_columns'] = array_filter($fields, static fn(string $name): bool => (bool)preg_match('/^(pms|operation|operating|opening|quant|expansion|transfer|investment|hotel_operating)/', $name), ARRAY_FILTER_USE_KEY);
echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
