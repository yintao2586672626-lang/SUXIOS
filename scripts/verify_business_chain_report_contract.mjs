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
const pkg = read('package.json');

includesAll('business-chain report is registered', pkg, [
  '"report:business-chain": "C:\\\\xampp\\\\php\\\\php.exe scripts\\\\report_business_chain_status.php"',
  '"verify:business-chain-report": "node scripts/verify_business_chain_report_contract.mjs"',
]);

includesAll('business-chain report wires the requested chain services', report, [
  'OtaStandardEtlService',
  'RevenueAiOverviewService',
  'BusinessClosureOverviewService',
  'InvestmentDecisionSupportService',
  'business_chain_stage_rows',
  'ota_data',
  'revenue_analysis',
  'ai_decision_advice',
  'operation_closure',
  'investment_judgment',
]);

includesAll('business-chain report supports explicit skip-P0 reference mode', report, [
  '--skip-p0',
  'skip_p0_reference_only',
  'read_existing_latest_available_ota_rows_reference_only',
  'target_date_p0_rows_missing_but_latest_real_ota_rows_exist',
  'forbidden_claims',
  'target_date_closure',
  'investment_judgment_allowed',
]);

includesAll('business-chain report keeps P0 gate and downstream blocking explicit', report, [
  'blocked_by_p0_ota_gate',
  'p0_skipped_by_operator',
  'p0_field_loop_verifier_ready',
  'target_date_ota_rows',
  'target_date_traffic_rows',
  'claim_allowed',
  'required_gate_command',
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
  'single_scope_verifier',
  'P0 Execution Plan',
]);

includesAll('business-chain report exposes skip-P0 downstream reference workflow', report, [
  'downstream_reference_workflow',
  'reference_workflow_ready_not_claimable',
  'use_reference_ota_rows_for_diagnosis_only',
  'revenue_diagnosis',
  'ai_advice_draft',
  'operation_execution_draft',
  'investment_precheck',
  'draft_not_written',
  'auto_apply_ai_advice',
  'whole_hotel_truth_from_ota_only',
  'Downstream Reference Workflow',
]);

includesAll('business-chain report reads latest reference dates as complete date rows', report, [
  "field('data_date')->order('data_date', 'desc')",
  "preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date)",
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
