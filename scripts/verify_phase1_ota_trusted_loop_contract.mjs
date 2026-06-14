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
  'p0_import_is_sensitive_browser_metadata_key',
  'p0_import_normalize_sensitive_key_segment',
  'p0_import_is_raw_url_key',
  'p0_import_is_raw_url_value',
  'ready_to_import',
  'explicit_execute_only',
  'p0_import_post_execute_verification',
  'post_execute_verification',
  'post_execute_verification_incomplete',
  'system_hotel_id',
  'system-hotel-id',
  'sensitive_payload_keys_detected',
  'target_date_traffic_rows_missing',
  'target_date_explicit_row_date_missing',
  'command --date cannot be used as row-date evidence',
  'p0_import_explicit_source_path',
  'target_date_source_path_missing',
  'field names alone are not accepted as source-path evidence',
  'p0_import_payload_is_browser_capture',
  'p0_import_browser_response_evidence',
  'p0_import_capture_evidence_matches_response',
  'p0_import_row_date_source_is_context_default',
  'p0_import_payload_scope_issues',
  'browser_capture_auth_not_verified',
  'browser_capture_gate_not_pass',
  'browser_capture_login_only_not_importable',
  'browser_capture_source_missing',
  'browser_capture_system_hotel_id_missing',
  'browser_capture_row_capture_evidence_missing',
  'browser_capture_response_evidence_missing',
  'system_hotel_id_mismatch',
  'required_traffic_metric_keys_missing',
  'desensitized_capture_evidence_missing',
  'rows_with_desensitized_capture_evidence',
  'rows_with_complete_desensitized_capture_evidence',
  'missing_complete_capture_evidence_rows',
  'row_level_complete_desensitized_capture_evidence_rows',
  'missing_row_level_complete_capture_evidence_rows',
  'complete desensitized capture evidence',
  'incomplete_field_fact_preview_rows',
  'traffic_field_fact_preview_rows_incomplete',
  'cross-row metric coverage is not accepted',
  'traffic_evidence_execute_row_count_mismatch',
  'Traffic evidence rows, target-date rows, and execute payload rows must match before import.',
  '$requiredStorageFields = p0_import_required_traffic_storage_fields()',
  'p0_import_fact_has_desensitized_capture_evidence',
  'p0_import_build_traffic_evidence',
  'p0_import_preview_ui_status',
  'traffic_evidence',
  'traffic_evidence_contract',
  'traffic evidence completion policy',
  'ui_status',
  'field_fact_status',
  'ready_ui_status_rows',
  'structured_source_path_count',
  'source_trace_id',
  'source_url_hash',
  'endpoint',
  "str_ends_with($normalized, '_url')",
  "preg_match('#https?://#i'",
  'Import is only accepted as P0 closure after verify:p0-ota-field-loop',
  'next verifier command',
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
  'browser_capture_raw_metadata_projection_ready',
  'browser_capture_request_date_source_ready',
  'browser_capture_standard_rows_missing_date_source_blocked',
  'browser_capture_non_traffic_response_evidence_blocked',
  'browser_capture_response_row_count_missing_blocked',
  'browser_capture_response_row_count_underflow_blocked',
  'browser_capture_auth_failed_blocked',
  'browser_capture_login_only_blocked',
  'payload_system_hotel_mismatch_blocked',
  'browser_capture_scope_missing_blocked',
  'browser_capture_payload_level_evidence_blocked',
  'browser_capture_response_mismatch_blocked',
  'p0_import_browser_response_is_traffic_evidence',
  'browser_capture_default_data_date_not_row_evidence_blocked',
  'browser_capture_context_date_source_not_row_evidence_blocked',
  'browser_capture_row_date_source_missing',
  'browser_projection_external_evidence_contract',
  'payload_level_evidence_propagates_to_rows',
  'metric_level_evidence_requires_trace_and_source_hash',
  'complete evidence rows',
  'row-level complete evidence rows',
  'missing_desensitized_evidence_blocked',
  'raw_source_url_blocked',
  'raw_request_url_blocked_even_with_hash_evidence',
  'raw_endpoint_blocked_even_with_hash_evidence',
  'raw_url_value_blocked_even_under_generic_key',
  'verify:p0-ota-traffic-importer',
  'desensitized_capture_evidence_missing',
  'sensitive_payload_keys_detected',
  'ready_to_import',
  'blocked',
  'traffic_evidence_contract',
  'P0 verifier accepts importer traffic_evidence as valid',
  'evidence UI status ready',
  'P0 verifier keeps importer UI status ready',
  'P0 verifier accepts matching system hotel scoped evidence',
  'P0 verifier rejects mismatched system hotel scoped evidence',
  'cross_row_metric_coverage_blocked',
  'missing_explicit_row_date_blocked',
  'field_name_source_path_blocked',
  'importer blocks cross-row metric coverage',
  'importer blocks rows without explicit source dates',
  'importer blocks weak source paths',
  'execute performs DB readback verification',
  'post execute incomplete stays explicit',
]);

requireIncludes('scripts/lib/ota_capture_standard.mjs', 'P0 browser capture rows carry desensitized evidence', [
  'buildOtaCaptureEvidence',
  'attachOtaCaptureEvidence',
  'extractOtaRequestDateEvidence',
  'source_trace_id',
  'source_url_hash',
  'delete evidence.source_url;',
  'delete evidence.url;',
  'delete next._source_url;',
  'delete next.url;',
]);

requireIncludes('scripts/meituan_browser_capture.mjs', 'Meituan browser capture uses desensitized evidence for importable rows', [
  'attachOtaCaptureEvidence(row, \'meituan\'',
  'buildOtaCaptureEvidence(\'meituan\'',
  'url_hash',
  'source_trace_id',
  'default_data_date',
  'args.dataDate',
  'request_date_source',
]);

requireIncludes('scripts/ctrip_browser_capture.mjs', 'Ctrip browser capture uses desensitized evidence for importable rows', [
  'attachCtripCaptureEvidence',
  'attachOtaCaptureEvidence(row, \'ctrip\'',
  'buildOtaCaptureEvidence(\'ctrip\'',
  'url_hash',
  'source_trace_id',
  'date_source',
  'request_date_source',
  'delete summary.url;',
]);

requireIncludes('scripts/verify_p0_ota_field_loop_closure.php', 'P0 verifier exposes traffic evidence availability without sensitive values', [
  'traffic_evidence_availability',
  'p0_traffic_evidence_availability',
  'p0_traffic_field_fact_closure',
  'p0_traffic_row_ui_status',
  'traffic_field_fact_closure',
  'p0_traffic_gate',
  'p0_platform_traffic_gate',
  'missing_target_date_traffic_rows',
  'Source field facts are reference evidence only',
  'P0 traffic gate',
  'completion gate: P0 passes only when each platform',
  'traffic_gates_ready',
  'traffic_gates_incomplete',
  'p0_platform_traffic_gate_next_steps',
  'hotel_scoped_next_steps',
  'p0_next_action_mode',
  'p0_next_action_entry',
  'p0_next_step_count',
  'pre_import_evidence_status',
  'valid_external_evidence_not_ingested',
  'pre_import_evidence_policy',
  'pre-import evidence policy',
  'pre-import evidence',
  'next_command_policy',
  'metadata_only_no_sensitive_commands',
  'next_command_policy_detail',
  'p0_platforms_ready',
  'p0_platforms_incomplete',
  'source_platforms_ready',
  'summary_policy',
  'responses[].request_date_source',
  'ui_frontend_p0_traffic_status',
  'ui_frontend_p0_source_evidence_status',
  'traffic_source_readiness',
  'target_date_traffic_rows',
  'p0_source_chain_reference_only',
  'p0_source_chain_scope',
  'p0_source_chain_policy',
  'reference_only_non_traffic_source_rows',
  'sample_trace_id_count',
  'latest_trace_time_reference_only',
  'desensitized source_trace_id plus source_url_hash evidence',
  'Online analysis UI exposes P0 desensitized capture evidence separately from loose field facts.',
  'Employee UI exposes target-date traffic status separately from source field status.',
  'platforms_ready counts only platforms whose source chain and p0_traffic_gate are both ready',
  'source_platforms_ready is reference-only',
  'traffic-evidence',
  'p0_external_traffic_evidence',
  'p0_external_is_sensitive_metadata_key',
  'p0_external_normalize_sensitive_key_segment',
  'p0_external_is_raw_url_key',
  'p0_external_is_raw_url_value',
  'p0_external_desensitized_capture_evidence',
  "'source_url_hash' => ['source_url_hash', '_source_url_hash', 'url_hash', '_url_hash']",
  'field_fact_source_trace_id_missing',
  'field_fact_source_trace_id_mismatch',
  'field_fact_source_url_hash_missing',
  'field_fact_source_url_hash_mismatch',
  'p0_source_path_is_structured',
  'source_path_structured',
  'structured_source_path_count',
  "str_ends_with($normalized, '_url')",
  'External Traffic Evidence',
  'validated_desensitized_evidence_present',
  'source_url_hash',
  'missing_metric_keys',
  'required_storage_fields',
  'capture_evidence_count',
  'desensitized_capture_evidence_count',
  'onlineAnalysisP0CaptureEvidenceStatusText',
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
  'traffic_evidence_output',
  'traffic_evidence_verifier_command',
  'importer-json-output',
  'hotel_scoped_sources',
  'hotel_scoped_commands',
  'hotel_scoped_payload_contracts',
  'hotel_scoped_capture_bridges',
  'p0_hotel_scoped_capture_bridge_contract',
  'p0_hotel_scoped_traffic_payload_contract',
  'Hotel Scoped Traffic Sources',
  'Hotel Scoped Traffic Commands',
  'Hotel Scoped Payload Contracts',
  'Hotel Scoped Capture Bridges',
  'contract_only_not_importable',
  'requires_real_ota_payload',
  'dry_run_acceptance',
  'browser_login_prepare_command',
  'browser_capture_command',
  'bridge_to_importer_command',
  'bridge_importer_acceptance',
  'payload_import_projection.applied',
  'summary.rows_with_complete_desensitized_capture_evidence',
  'summary.row_level_complete_desensitized_capture_evidence_rows',
  'summary.browser_response_evidence_rows',
  'source_trace_id plus source_url_hash/url_hash',
  'importer acceptance',
  '--headless=false',
  '--format=json',
  '--data-date=',
  'traffic[].capture_evidence.source_trace_id',
  'standard_rows[].capture_evidence.source_trace_id',
  'responses[].standard_row_count',
  'responses[].source_trace_id',
  'authorized_profile_matches_selected_hotel',
  'raw_profile_path_in_report',
  'payload_import_execute_command',
  '--system-hotel-id=',
  'system_hotel_id_mismatch',
  'hotel_scope_policy',
  'ui_status_missing',
  'ui_status_not_ready',
  'field_fact_capture_evidence_missing',
  'source_path_not_structured',
  'stored_value_present_not_true',
  'ui_status_ready_rows',
  'ui_status_incomplete_rows',
  'sample_ui_statuses',
  "&& (int)$base['ui_status_incomplete_rows'] === 0",
  'ui_status_incomplete',
  'ui_statuses',
  'import:p0-ota-traffic-payload',
  'External traffic evidence validates desensitized source proof only; it does not complete P0 without ingested target-date traffic rows.',
  'recommended',
  'can run now',
  'evidence verifier',
  'selection policy',
  'input_contract',
  'acceptance_contract',
  'required_metric_keys',
  'required_storage_fields',
  'required_field_fact_keys',
  'target_data_type',
  'sensitive_values_allowed',
  'traffic_evidence_policy',
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
  'traffic_profile_login_verified_count',
  'manual_login_state_verified',
  'login state has been manually verified',
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
  "'manual_login_state_verified' => false",
  "'login_verification_status' => 'not_verified'",
  'Profile directory presence is not login-state evidence.',
  'registered sources stay waiting_config until manual_login_state_verified and target-date traffic rows exist',
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
  'fieldFactHasDesensitizedCaptureEvidence',
  'desensitized_capture_evidence_count',
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
