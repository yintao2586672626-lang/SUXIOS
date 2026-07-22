import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const checks = [];

function read(file) {
  const target = path.join(root, file);
  return fs.existsSync(target) ? fs.readFileSync(target, 'utf8') : '';
}

function check(label, ok, detail = '') {
  checks.push({ label, ok: Boolean(ok), detail });
}

function includesAll(label, source, needles) {
  const missing = needles.filter((needle) => !source.includes(needle));
  check(label, missing.length === 0, missing.join(', '));
}

function excludesAll(label, source, needles) {
  const present = needles.filter((needle) => source.includes(needle));
  check(label, present.length === 0, present.join(', '));
}

const report = read('scripts/report_business_chain_status.php');
const runtimeTest = read('tests/automation/business_chain_status_report.test.mjs');
const revenueAi = read('app/service/RevenueAiOverviewService.php');
const pkg = read('package.json');
const workflow = read('.github/workflows/php.yml');

includesAll('business-chain report is registered', pkg, [
  '"report:business-chain": "node scripts/run_php.mjs scripts/report_business_chain_status.php"',
  '"verify:business-chain-report": "node scripts/verify_business_chain_report_contract.mjs && node --test tests/automation/business_chain_status_report.test.mjs"',
]);

includesAll('business-chain report wires the requested chain services', report, [
  'OtaStandardEtlService',
  'RevenueAiOverviewService',
  'BusinessClosureOverviewService',
  'business_chain_stage_rows',
  'ota_data',
  'revenue_analysis',
  'ai_decision_advice',
  'operation_closure',
]);

includesAll('business-chain report exposes a machine-readable database blocker', report, [
  'business_chain_failure_payload',
  "'error_code' => $databaseUnavailable ? 'database_unavailable' : 'report_generation_failed'",
  "'claim_allowed' => false",
  "'database_ready' => $databaseUnavailable ? false : null",
]);

includesAll('business-chain runtime tests use portable PHP and fail closed when runtime is required', runtimeTest, [
  "process.env.PHP_BINARY || 'php'",
  'function isRuntimeRequired(env = process.env)',
  'env.CI',
  'env.SUXI_REQUIRE_BUSINESS_CHAIN_RUNTIME',
  'isRuntimeRequired',
  'failOrSkipRuntime',
  'skipWhenRuntimeUnavailable',
  "spawnErrorCode === 'ENOENT'",
  "spawnErrorCode === 'EPERM'",
  "payload?.error_code !== 'database_unavailable'",
  'business-chain runtime assertions are required when CI=true or SUXI_REQUIRE_BUSINESS_CHAIN_RUNTIME=1',
  'project database is unavailable; runtime business-chain assertions were not evaluated',
  'assert.fail',
]);

excludesAll('business-chain runtime tests do not hard-code a workstation PHP path or pre-skip PATH commands', runtimeTest, [
  'C:\\\\xampp\\\\php\\\\php.exe',
  'existsSync(php)',
]);

includesAll('CI requires the business-chain PHP and database runtime', workflow, [
  "PHP_BINARY: php",
  "SUXI_REQUIRE_BUSINESS_CHAIN_RUNTIME: '1'",
  "SUXI_E2E_DB_OVERRIDE: '1'",
  'DB_NAME: hotelx_ci_test',
  'node scripts/verify_business_chain_report_contract.mjs',
]);

includesAll('business-chain report supports explicit skip-P0 reference mode', report, [
  '--skip-p0',
  'platforms',
  'platform',
  'skip_platforms',
  'skip-platform',
  'skip_p0_reference_only',
  'read_existing_latest_available_ota_rows_reference_only',
  'target_date_p0_rows_missing_but_latest_real_ota_rows_exist',
  'forbidden_claims',
  'target_date_closure',
]);

includesAll('business-chain report keeps P0 gate and downstream blocking explicit', report, [
  'blocked_by_p0_ota_gate',
  'p0_skipped_by_operator',
  'p0_field_loop_verifier_ready',
  'target_date_ota_rows',
  'target_date_traffic_rows',
  'platform_scoped_ota_channel_gate_before_downstream_claims',
  'scope_platforms',
  'claim_allowed',
  'required_gate_command',
  'operator_skip_platforms',
]);

includesAll('business-chain report embeds executable P0 next steps from verifier metadata', report, [
  'verify_p0_ota_field_loop_closure.php',
  'p0_execution_plan',
  'read_p0_verifier_metadata_only_no_ota_collection',
  'operator_sequence',
  'authorization_options',
  'browser_profile_tiancheng_account',
  'authorized_cookie_api_temporary',
  'cookie_api_as_default_mainline',
  'manual_login_state_verified',
  'login_trigger_entry',
  'after_login_sync_entry',
  'business_chain_p0_platform_ready',
  'already_ready_no_login',
  'already_ready',
  'operator_skip',
  'login_verified_reference_only',
  'skipped_by_operator_no_capture',
  'skipped_by_operator_no_sync',
  'p0_skipped_by_operator_reference_only_no_collection',
  'single_scope_verifier',
  'P0 Execution Plan',
]);

includesAll('business-chain report exposes skip-P0 downstream reference workflow', report, [
  'downstream_reference_workflow',
  'business_chain_downstream_reference_scope',
  'partial_reference_workflow_not_claimable',
  'use_target_date_ready_platform_rows_for_diagnosis_only',
  'ready_platform_rows_are_read_only_reference_until_all_required_p0_platforms_ready',
  'reference_workflow_ready_not_claimable',
  'use_reference_ota_rows_for_diagnosis_only',
  'revenue_diagnosis',
  'partial_reference_only',
  'ai_advice_draft',
  'draft_reference_only',
  'revenue_to_ai_handoff',
  'business_chain_revenue_to_ai_handoff',
  'business_chain_manual_review_packet',
  'manual_review_packet',
  'manual_review_packet:',
  'manual_review_next_blockers',
  'manual_review_forbidden_actions',
  'business_chain_ai_decision_review_contract',
  'ai_decision_review_contract',
  'blocked_by_review_inputs',
  'required_input_items',
  'required_input_count',
  'business_chain_ai_decision_resolution_plan',
  'business_chain_ai_decision_resolution_spec',
  'resolution_plan',
  'has_pending_evidence',
  'post_resolution_gate',
  'post_resolution_verifier',
  'provide_available_room_nights_or_mark_metric_unusable',
  'persist_or_attach_manual_review_record',
  'verify_zero_room_nights_or_correct_ota_room_nights',
  'fill_missing_evidence_with_defaults',
  'approve_ai_advice_without_resolving_inputs',
  'allowed_decision_outputs',
  'approval_allowed',
  'operation_intake_allowed',
  'request_revenue_metric_evidence',
  'record_manual_review_note',
  'reject_ai_advice',
  'approve_ai_advice_for_operation_intake',
  'manual_review_requires_explicit_evidence_no_auto_apply',
  'business_chain_ai_to_operation_handoff',
  'business_chain_operation_intake_preflight_contract',
  'ai_to_operation_handoff',
  'operation_intake_packet',
  'operation_intake_preflight_contract',
  'blocked_by_ai_review_contract',
  'OperationManagementService::buildExecutionIntentPayload',
  'OperationManagement/createExecutionIntent',
  'would_call_create_endpoint',
  'create_allowed',
  'projected_payload_template',
  'target_value_required_fields',
  'call_create_execution_intent_before_ai_review_approval',
  'operation_intake_requires_approved_ai_review_and_price_target_no_auto_create',
  'operation_intake_blocked_by_manual_review',
  'blocked_by_manual_review_packet',
  'read_only_candidate_from_ctrip_ota_revenue_ai_manual_review',
  'ota_revenue_ai_manual_review',
  '/api/operation/execution-intents',
  'auto_create_operation_execution_intent',
  'mark_operation_executed_without_evidence',
  'claim_roi_ready_without_review',
  'business_chain_ctrip_chain_action_queue',
  'ctrip_chain_action_queue',
  'ctrip_chain_next_action',
  'ctrip_chain_forbidden_actions',
  'ota_channel_action_queue_no_auto_write_no_whole_hotel_truth',
  'resolve_revenue_metric_gap',
  'approve_ai_manual_review',
  'create_operation_intent_after_review',
  'attach_operation_execution_evidence',
  'claim_operation_roi_ready',
  'primary_blocker',
  'blocked_ready_for_manual_review',
  'handoff_reference_only',
  'handoff_ready_for_manual_review',
  'scoped_workflow_ready_for_manual_review',
  'use_scoped_target_date_ota_rows_for_ai_review',
  'ready_for_manual_review',
  'scoped_ai_review_ready',
  'business_chain_focused_ota_revenue_ai_chain',
  'read_only_ai_draft_no_execution_until_p0_ready_and_manual_review',
  'scoped_ai_review_packet_no_auto_write_or_execution_without_human_approval',
  'can_create_operation_execution',
  'create_operation_execution_without_human_approval',
  'claim_ai_decision_final',
  'promote_ota_scope_to_whole_hotel_truth',
  'all_required_p0_platforms_ready',
  'operation_execution_draft',
  'draft_not_written',
  'auto_apply_ai_advice',
  'whole_hotel_truth_from_ota_only',
  'Downstream Reference Workflow',
]);

includesAll('business-chain report runtime test guards operator skip output', runtimeTest, [
  'report_business_chain_status.php',
  '--skip-platform=meituan',
  'p0_skipped_by_operator',
  'ctrip:already_ready',
  'meituan:operator_skip',
  'target_ready_platforms',
  'operator_skip_platforms',
  'partial_reference_workflow_not_claimable',
  'draft_reference_only',
  'revenue_to_ai_handoff',
  'handoff_reference_only',
  'ctrip_target_date_ota_channel_reference',
  '--platform=ctrip',
  'scoped_ai_review_ready',
  'handoff_ready_for_manual_review',
  'use_scoped_target_date_ota_rows_for_ai_review',
  'ctrip_target_date_ota_channel',
  'manual_review_packet',
  'format=markdown',
  'manual_review_packet: `blocked_ready_for_manual_review`',
  'manual_review_next_blockers',
  'manual_review_forbidden_actions',
  'ai_decision_review_contract',
  'blocked_by_review_inputs',
  'ai_decision_required_inputs',
  'ai_decision_allowed_outputs',
  'ai_decision_resolution_plan',
  'ai_decision_resolution_items',
  'has_pending_evidence',
  'provide_available_room_nights_or_mark_metric_unusable',
  'persist_or_attach_manual_review_record',
  'fill_missing_evidence_with_defaults',
  'approve_ai_advice_for_operation_intake',
  'manual_review_requires_explicit_evidence_no_auto_apply',
  'ai_to_operation_handoff',
  'operation_intake_blocked_by_manual_review',
  'operation_intake_packet',
  'operation_intake_preflight_contract',
  'blocked_by_ai_review_contract',
  'operation_intake_missing_fields',
  'call_create_execution_intent_before_ai_review_approval',
  'operation_intake_requires_approved_ai_review_and_price_target_no_auto_create',
  'blocked_by_manual_review_packet',
  'read_only_candidate_from_ctrip_ota_revenue_ai_manual_review',
  'ota_revenue_ai_manual_review',
  'auto_create_operation_execution_intent',
  'mark_operation_executed_without_evidence',
  'ctrip_chain_action_queue',
  'has_blocking_actions',
  'ctrip_chain_next_action',
  'resolve_revenue_metric_gap',
  'approve_ai_manual_review',
  'create_operation_intent_after_review',
  'attach_operation_execution_evidence',
  'ctrip_chain_forbidden_actions',
  'claim_operation_roi_ready',
  'blocked_ready_for_manual_review',
  'primary_blocker',
  'create_operation_execution_without_human_approval',
  'can_create_operation_execution',
  'all_required_p0_platforms_ready',
  'capture-meituan-browser',
  'profile-login-trigger\\/meituan',
  'data-sources\\/18\\/sync',
  'doesNotMatch',
]);

includesAll('business-chain report reads latest reference dates as complete date rows', report, [
  "field('data_date')->order('data_date', 'desc')",
  "preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date)",
]);

includesAll('business-chain report scopes Revenue AI channels for Ctrip-only runs', report, [
  'enabled_channels',
  'business_chain_filter_dataset_platforms',
  'business_chain_platform_scope_arg',
]);

includesAll('Revenue AI keeps Ctrip data-hit metric gaps distinct from empty data', revenueAi, [
  'otaMetricsPricingGate',
  'ota_revenue_metrics_missing',
  'ota_room_nights_zero',
]);

includesAll('business-chain report runtime test guards Ctrip metric-gap reason', runtimeTest, [
  'available_room_nights_missing',
  'online_daily_data_empty',
  'blocking_reasons',
]);

excludesAll('business-chain report does not trigger OTA collection or writes', report, [
  'captureCtripBrowserData',
  'captureMeituanBrowserData',
  'triggerPlatformProfileLogin',
  'syncDataSource(',
  'importRows(',
  'fetchCtrip(',
  'fetchMeituan(',
  '->insert(',
  '->update(',
  '->delete(',
  'save(',
  "max('data_date')",
]);

const failures = checks.filter((item) => !item.ok);
if (failures.length > 0) {
  console.error('Business-chain report contract failed:');
  for (const failure of failures) {
    console.error(`- ${failure.label}${failure.detail ? ` (${failure.detail})` : ''}`);
  }
  process.exit(1);
}

console.log('Business-chain report contract passed.');
