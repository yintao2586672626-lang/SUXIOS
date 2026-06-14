import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const checks = [];

function read(file) {
  const target = path.join(root, file);
  return fs.existsSync(target) ? fs.readFileSync(target, 'utf8') : '';
}

function add(file, label, ok, detail = '') {
  checks.push({ file, label, ok: Boolean(ok), detail });
}

function requireFile(file) {
  add(file, 'file exists', fs.existsSync(path.join(root, file)), file);
}

function requireIncludes(file, label, needles) {
  const source = read(file);
  const missing = needles.filter((needle) => !source.includes(needle));
  add(file, label, missing.length === 0, missing.join(', '));
}

function requirePackageScript(name, command) {
  let ok = false;
  try {
    ok = JSON.parse(read('package.json')).scripts?.[name] === command;
  } catch {
    ok = false;
  }
  add('package.json', `package script ${name}`, ok, `${name}: ${command}`);
}

requireFile('docs/phase1_ota_trusted_loop_goal.md');
requireFile('scripts/verify_phase1_ota_trusted_loop_contract.mjs');

requireIncludes('docs/phase1_ota_trusted_loop_goal.md', 'phase-one objective is explicit', [
  '第一阶段目标',
  '不是让 AI 全自动管酒店',
  '携程、美团 OTA 经营数据',
  '经营诊断闭环',
]);

requireIncludes('docs/phase1_ota_trusted_loop_goal.md', 'OTA source and scope boundaries stay explicit', [
  '不改变携程和美团手动获取、自动获取逻辑',
  '不改变现有获取字段、字段映射和历史入库兼容口径',
  '不把 OTA 渠道数据包装成全酒店经营事实',
  '不用兜底值、假成功、空数据默认值或宽泛 catch 掩盖采集失败、字段缺失和授权失败',
  '点评明文必须显式授权',
]);

requireIncludes('docs/phase1_ota_trusted_loop_goal.md', 'trusted loop chain is documented', [
  '原始响应证据',
  'source path',
  'metric key',
  'online_daily_data',
  'UI 字段状态',
  'ota-standard 收益指标',
  'AI 经营诊断',
  '运营执行意图',
  '审批、执行证据、复盘',
]);

requireIncludes('docs/phase1_ota_trusted_loop_goal.md', 'priority resources and statuses are documented', [
  'businessData',
  'flowData',
  'tradeData',
  'peerRank',
  'searchKeywords',
  'roomTypes',
  'advertising',
  'reviewData',
  'partial_success',
  'waiting_auth',
  'auth_failed',
  'api_not_hit',
  'field_missing',
  'parse_failed',
  'not_collected',
  'manual_intervention_required',
]);

requirePackageScript('verify:phase1-ota-loop', 'node scripts/verify_phase1_ota_trusted_loop_contract.mjs');
requirePackageScript('verify:phase1-ota-audit', 'node scripts/verify_phase1_ota_trusted_loop_audit.mjs');
requirePackageScript('verify:phase1-employee-console', 'node scripts/verify_phase1_ota_employee_console_contract.mjs');
requirePackageScript('verify:phase1-gap-explanations', 'node scripts/verify_phase1_ota_gap_explanations.mjs');
requirePackageScript('verify:phase1-live-closure-contract', 'node scripts/verify_phase1_ota_live_closure_contract.mjs');
requirePackageScript('verify:phase1-live-action-queue', 'node scripts/verify_phase1_live_action_queue_runtime.mjs');
requirePackageScript('inspect:phase1-live-closure', 'C:\\xampp\\php\\php.exe scripts\\inspect_phase1_ota_live_closure.php');
requirePackageScript('verify:phase1-live-closure', 'C:\\xampp\\php\\php.exe scripts\\inspect_phase1_ota_live_closure.php --strict');
requirePackageScript('verify:online-data-field-fact-status', 'C:\\xampp\\php\\php.exe scripts\\verify_online_data_field_fact_status.php');
requirePackageScript('verify:p0-ota-field-loop', 'C:\\xampp\\php\\php.exe scripts\\verify_p0_ota_field_loop_closure.php');
requirePackageScript('verify:p0-ota-traffic-importer', 'node scripts/verify_p0_ota_traffic_payload_importer.mjs');
requirePackageScript('import:p0-ota-traffic-payload', 'C:\\xampp\\php\\php.exe scripts\\import_p0_ota_traffic_payload.php');
requirePackageScript('import:p0-ota-traffic-payload:execute', 'C:\\xampp\\php\\php.exe scripts\\import_p0_ota_traffic_payload.php --execute=1');
requirePackageScript('register:p0-ota-traffic-sources', 'C:\\xampp\\php\\php.exe scripts\\register_p0_ota_traffic_data_sources.php');
requirePackageScript('register:p0-ota-traffic-sources:execute', 'C:\\xampp\\php\\php.exe scripts\\register_p0_ota_traffic_data_sources.php --execute');

requireFile('scripts/import_p0_ota_traffic_payload.php');
requireIncludes('scripts/import_p0_ota_traffic_payload.php', 'P0 payload importer stays explicit, dry-run first, and non-sensitive', [
  'p0_import_sensitive_hits',
  'ready_to_import',
  'explicit_execute_only',
  'sensitive_payload_keys_detected',
  'target_date_traffic_rows_missing',
  'required_traffic_metric_keys_missing',
  'desensitized_capture_evidence_missing',
  'rows_with_desensitized_capture_evidence',
  'source_trace_id',
  'source_url_hash',
  'Import is only accepted as P0 closure after verify:p0-ota-field-loop',
  'OnlineDailyDataPersistenceService',
  'OnlineDataFieldFactService',
  'OnlineTrafficDataExtractionService',
  'scope_policy',
  'ota_channel_only',
  'target_storage_table',
  'online_daily_data',
  'target_data_type',
  'traffic',
  'next_verifier_command',
]);

requireFile('scripts/verify_p0_ota_traffic_payload_importer.mjs');
requireIncludes('scripts/verify_p0_ota_traffic_payload_importer.mjs', 'P0 traffic importer verifier covers capture-output contracts', [
  'meituan_top_level_traffic_ready',
  'ctrip_top_level_traffic_ready',
  'ctrip_standard_rows_ready',
  'meituan_browser_capture_envelope_ready',
  'payload_level_evidence_propagates_to_rows',
  'missing_desensitized_evidence_blocked',
  'raw_source_url_blocked',
  'verify:p0-ota-traffic-importer',
  'desensitized_capture_evidence_missing',
  'sensitive_payload_keys_detected',
  'ready_to_import',
  'blocked',
]);

requireIncludes('scripts/lib/ota_capture_standard.mjs', 'P0 browser capture rows carry desensitized evidence', [
  'buildOtaCaptureEvidence',
  'attachOtaCaptureEvidence',
  'source_trace_id',
  'source_url_hash',
  'delete next._source_url;',
]);

requireIncludes('scripts/meituan_browser_capture.mjs', 'Meituan browser capture uses desensitized evidence for importable rows', [
  'attachOtaCaptureEvidence(row, \'meituan\'',
  'buildOtaCaptureEvidence(\'meituan\'',
  'url_hash',
  'source_trace_id',
]);

requireIncludes('scripts/ctrip_browser_capture.mjs', 'Ctrip browser capture uses desensitized evidence for importable rows', [
  'attachCtripCaptureEvidence',
  'attachOtaCaptureEvidence(row, \'ctrip\'',
  'buildOtaCaptureEvidence(\'ctrip\'',
  'url_hash',
  'source_trace_id',
]);

requireIncludes('scripts/verify_p0_ota_field_loop_closure.php', 'P0 verifier exposes traffic evidence availability without sensitive values', [
  'traffic_evidence_availability',
  'p0_traffic_evidence_availability',
  'p0_traffic_field_fact_closure',
  'traffic_field_fact_closure',
  'traffic-evidence',
  'p0_external_traffic_evidence',
  'External Traffic Evidence',
  'validated_desensitized_evidence_present',
  'source_url_hash',
  'missing_metric_keys',
  'required_storage_fields',
  'capture_evidence_count',
  'with_traffic_url_count',
  'default_traffic_url_available',
  'traffic_payload_or_query_params',
  'registered_traffic_data_source',
  'traffic_template_count',
  'traffic_catalog_endpoint_count',
  'ctrip_browser_capture_method.md',
  'profile_capture_doc_present',
  'profile_capture_sections_include_traffic',
  'closure_path_options',
  'Traffic Closure Path Options',
  'payload_import_command',
  'import:p0-ota-traffic-payload',
  'recommended',
  'can run now',
  'selection policy',
  'input_contract',
  'acceptance_contract',
  'required_metric_keys',
  'required_storage_fields',
  'required_field_fact_keys',
  'target_data_type',
  'sensitive_values_allowed',
  'completion_policy',
  'p0_recommended_traffic_action',
  'p0_traffic_input_contract',
  'p0_traffic_acceptance_contract',
  'recommended_action',
  'prefer_ready_then_fewest_missing_inputs_then_platform_default',
  'manual_cookie_api',
  'browser_profile',
  'sensitive_values_exposed',
  'traffic_enabled_count',
  'traffic_ready_count',
  'traffic_waiting_config_count',
  'traffic_managed_count',
  'traffic_last_sync_status_counts',
  'traffic_source_samples',
  'managed_by_p0',
  'capture_sections_has_traffic',
  'traffic_data_source_registered_waiting_config_without_target_date_rows',
]);

requireFile('scripts/register_p0_ota_traffic_data_sources.php');
requireIncludes('scripts/register_p0_ota_traffic_data_sources.php', 'P0 traffic source registration stays explicit and non-sensitive', [
  'P0_TRAFFIC_SOURCE_MARKER',
  '--execute',
  "'status' => 'waiting_config'",
  "'secret_json' => '{}'",
  "'source_scope' => 'ota_channel_only'",
  'filter_platform_data_source_columns',
  'registered sources stay waiting_config until authorized profile and target-date traffic rows exist',
]);

requireIncludes('docs/release_functional_acceptance_matrix.md', 'functional acceptance includes phase-one gate', [
  'verify:phase1-ota-loop',
  'verify:phase1-ota-audit',
  'verify:phase1-employee-console',
  'verify:phase1-gap-explanations',
  'verify:phase1-live-closure-contract',
  'verify:phase1-live-action-queue',
  'OTA trusted loop',
]);

requireIncludes('docs/phase1_ota_trusted_loop_goal.md', 'phase-one goal includes audit, employee console, and gap verifiers', [
  'npm.cmd run verify:phase1-ota-audit',
  'npm.cmd run verify:phase1-employee-console',
  'npm.cmd run verify:phase1-gap-explanations',
  'npm.cmd run verify:phase1-live-closure-contract',
  'npm.cmd run verify:phase1-live-action-queue',
]);

requireIncludes('docs/phase1_ota_live_closure_evidence.md', 'phase-one live closure evidence gate is documented', [
  'capture -> persistence -> UI display -> revenue metrics -> AI evidence -> operation execution',
  'npm.cmd run inspect:phase1-live-closure',
  'npm.cmd run verify:phase1-live-closure',
  'npm.cmd run verify:phase1-live-action-queue',
]);

requireIncludes('route/app.php', 'OTA acquisition, diagnosis, revenue, AI, and execution routes exist', [
  "Route::get('/collection-resources', 'OnlineData/collectionResourceCatalog');",
  "Route::get('/collection-reliability', 'OnlineData/collectionReliability');",
  "Route::get('/data-analysis', 'OnlineData/dataAnalysis');",
  "Route::post('/ai-analysis', 'OnlineData/aiAnalysis');",
  "Route::get('/revenue-metrics', 'OtaStandard/revenueMetrics');",
  "Route::post('/ota-diagnosis', 'Agent/otaDiagnosis');",
  "Route::post('/execution-intents', 'OperationManagement/createExecutionIntent');",
  "Route::get('/execution-flow', 'OperationManagement/executionFlow');",
]);

requireIncludes('app/service/PlatformDataSyncService.php', 'platform data sync keeps raw evidence, normalized rows, and explicit states', [
  'final class PlatformDataSyncService',
  'storeRawRecord($source, $taskId, $payload',
  'saveNormalizedRows($rows)',
  "'last_sync_status' => $status",
  'partial_success',
  'manual_intervention_required',
]);

requireIncludes('app/service/OtaRevenueMetricService.php', 'revenue metric layer exposes gaps and trust', [
  "'data_gaps' => $dataGaps",
  "'metric_trust' => $metricTrust",
]);

requireIncludes('app/service/OtaInsightAnalysisService.php', 'AI insight layer remains gap-aware', [
  "'data_gaps' => $metrics['data_gaps'] ?? []",
  'traffic_conversion',
  'competitor_price_gap',
]);

requireIncludes('app/controller/Agent.php', 'Agent OTA diagnosis keeps evidence policy and gaps visible', [
  'public function otaDiagnosis()',
  'source_policy',
  'data_gaps',
  'database_only_no_synthetic_conclusion',
  'database_only_latest_available_reference_not_execution_ready',
  'blocked_by_missing_ota_data',
  'blocked_by_non_target_date_data',
  'ota_latest_available_not_target_date',
]);

requireIncludes('app/service/OperationManagementService.php', 'operation loop requires execution evidence', [
  'public function executionFlow',
  'execution evidence is required',
  'data_gaps',
  'data_collection',
  'evidence_refs',
  'source_policy',
  'protected_boundary',
]);

requireIncludes('docs/revenue_agent_api.md', 'revenue recommendations remain advisory', [
  'advisory-only',
  'It does not write OTA rates.',
  'manual review',
]);

const failures = checks.filter((check) => !check.ok);

if (failures.length > 0) {
  console.error('Phase 1 OTA trusted loop contract failed:');
  for (const failure of failures) {
    console.error(`- ${failure.file}: ${failure.label}`);
    if (failure.detail) {
      console.error(`  missing/expected: ${failure.detail}`);
    }
  }
  process.exit(1);
}

console.log(`[verify:phase1-ota-loop] ${checks.length} checks passed`);
