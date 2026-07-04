import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const checks = [];

function check(file, label, ok, detail = '') {
  checks.push({ file, label, ok: Boolean(ok), detail });
}

function includesAll(file, label, needles) {
  const source = read(file);
  for (const needle of needles) {
    check(file, `${label}: ${needle}`, source.includes(needle), needle);
  }
}

function excludesAll(file, label, needles) {
  const source = read(file);
  for (const needle of needles) {
    check(file, `${label}: excludes ${needle}`, !source.includes(needle), needle);
  }
}

const packageJson = JSON.parse(read('package.json'));
check(
  'package.json',
  'package exposes Revenue AI closure verifier',
  packageJson.scripts?.['verify:revenue-ai-closure'] === 'node scripts/verify_revenue_ai_closure_contract.mjs',
  'verify:revenue-ai-closure'
);
check(
  'package.json',
  'p0 guards include Revenue AI closure verifier',
  String(packageJson.scripts?.['verify:p0-guards'] || '').includes('npm run verify:revenue-ai-closure'),
  'verify:p0-guards'
);
check(
  'package.json',
  'package exposes Ctrip-only Revenue AI runtime verifier',
  packageJson.scripts?.['verify:revenue-ai-ctrip-scope'] === 'C:\\xampp\\php\\php.exe scripts\\verify_revenue_ai_ctrip_scope.php',
  'verify:revenue-ai-ctrip-scope'
);
check(
  'package.json',
  'package exposes Ctrip-only Revenue AI generation smoke verifier',
  packageJson.scripts?.['verify:revenue-ai-ctrip-generation'] === 'C:\\xampp\\php\\php.exe scripts\\verify_revenue_ai_ctrip_generation_smoke.php',
  'verify:revenue-ai-ctrip-generation'
);
check(
  'package.json',
  'package exposes Ctrip-only Revenue AI operation ROI smoke verifier',
  packageJson.scripts?.['verify:revenue-ai-ctrip-operation-roi'] === 'C:\\xampp\\php\\php.exe scripts\\verify_revenue_ai_ctrip_generation_smoke.php --complete-operation-roi=1',
  'verify:revenue-ai-ctrip-operation-roi'
);
check(
  'package.json',
  'package exposes Ctrip pricing input pipeline runtime verifier',
  packageJson.scripts?.['verify:revenue-ai-ctrip-pricing-inputs'] === 'C:\\xampp\\php\\php.exe scripts\\verify_revenue_ai_ctrip_pricing_input_pipeline.php',
  'verify:revenue-ai-ctrip-pricing-inputs'
);
check(
  'package.json',
  'package exposes operator-file Ctrip pricing pre-execute gate',
  packageJson.scripts?.['verify:revenue-ai-ctrip-pricing-file'] === 'C:\\xampp\\php\\php.exe scripts\\verify_revenue_ai_ctrip_pricing_input_pipeline.php',
  'verify:revenue-ai-ctrip-pricing-file'
);
check(
  'package.json',
  'package exposes transaction verifier for Ctrip pending review packet',
  packageJson.scripts?.['verify:revenue-ai-ctrip-pending-review-packet'] === 'C:\\xampp\\php\\php.exe scripts\\verify_revenue_ai_ctrip_pending_review_packet.php',
  'verify:revenue-ai-ctrip-pending-review-packet'
);
check(
  'package.json',
  'package exposes transaction verifier for Ctrip review-decision runner',
  packageJson.scripts?.['verify:revenue-ai-ctrip-review-decision'] === 'C:\\xampp\\php\\php.exe scripts\\verify_revenue_ai_ctrip_review_decision.php',
  'verify:revenue-ai-ctrip-review-decision'
);
check(
  'package.json',
  'package exposes read-only Ctrip pricing source-field audit',
  packageJson.scripts?.['inspect:revenue-ai-ctrip-pricing-sources'] === 'C:\\xampp\\php\\php.exe scripts\\inspect_revenue_ai_ctrip_pricing_source_fields.php',
  'inspect:revenue-ai-ctrip-pricing-sources'
);
check(
  'package.json',
  'package exposes Ctrip pricing operator packet report',
  packageJson.scripts?.['report:revenue-ai-ctrip-pricing-operator-packet'] === 'C:\\xampp\\php\\php.exe scripts\\report_revenue_ai_ctrip_pricing_operator_packet.php',
  'report:revenue-ai-ctrip-pricing-operator-packet'
);
check(
  'package.json',
  'package exposes Ctrip real-input gap pack report',
  packageJson.scripts?.['report:revenue-ai-ctrip-gap-pack'] === 'C:\\xampp\\php\\php.exe scripts\\report_revenue_ai_ctrip_gap_pack.php',
  'report:revenue-ai-ctrip-gap-pack'
);
check(
  'package.json',
  'package exposes Ctrip pending review packet report',
  packageJson.scripts?.['report:revenue-ai-ctrip-pending-review-packet'] === 'C:\\xampp\\php\\php.exe scripts\\report_revenue_ai_ctrip_pending_review_packet.php',
  'report:revenue-ai-ctrip-pending-review-packet'
);
check(
  'package.json',
  'package exposes Ctrip operator handoff bundle exporter',
  packageJson.scripts?.['export:revenue-ai-ctrip-operator-bundle'] === 'C:\\xampp\\php\\php.exe scripts\\export_revenue_ai_ctrip_operator_bundle.php',
  'export:revenue-ai-ctrip-operator-bundle'
);
check(
  'package.json',
  'package exposes Ctrip operator handoff bundle verifier',
  packageJson.scripts?.['verify:revenue-ai-ctrip-operator-bundle'] === 'node scripts/verify_revenue_ai_ctrip_operator_bundle.mjs',
  'verify:revenue-ai-ctrip-operator-bundle'
);
check(
  'package.json',
  'package exposes no-execute Ctrip operator handoff bundle preflight',
  packageJson.scripts?.['verify:revenue-ai-ctrip-operator-bundle-preflight'] === 'node scripts/verify_revenue_ai_ctrip_operator_bundle_preflight.mjs',
  'verify:revenue-ai-ctrip-operator-bundle-preflight'
);
check(
  'package.json',
  'package exposes Ctrip review-decision template exporter',
  packageJson.scripts?.['export:revenue-ai-ctrip-review-template'] === 'C:\\xampp\\php\\php.exe scripts\\execute_revenue_ai_ctrip_review_decision.php --print-template=1',
  'export:revenue-ai-ctrip-review-template'
);
check(
  'package.json',
  'package exposes dry-run Ctrip pricing input importer',
  packageJson.scripts?.['import:revenue-ai-ctrip-pricing-inputs'] === 'C:\\xampp\\php\\php.exe scripts\\import_revenue_ai_ctrip_pricing_inputs.php',
  'import:revenue-ai-ctrip-pricing-inputs'
);
check(
  'package.json',
  'package exposes validate-only Ctrip pricing input verifier',
  packageJson.scripts?.['validate:revenue-ai-ctrip-pricing-inputs'] === 'C:\\xampp\\php\\php.exe scripts\\import_revenue_ai_ctrip_pricing_inputs.php --validate-only=1',
  'validate:revenue-ai-ctrip-pricing-inputs'
);
check(
  'package.json',
  'package exposes lint-only Ctrip pricing input verifier',
  packageJson.scripts?.['lint:revenue-ai-ctrip-pricing-inputs'] === 'C:\\xampp\\php\\php.exe scripts\\import_revenue_ai_ctrip_pricing_inputs.php --lint-only=1',
  'lint:revenue-ai-ctrip-pricing-inputs'
);
check(
  'package.json',
  'package exposes current Ctrip pricing input template exporter',
  packageJson.scripts?.['export:revenue-ai-ctrip-pricing-template'] === 'C:\\xampp\\php\\php.exe scripts\\import_revenue_ai_ctrip_pricing_inputs.php --print-current-template',
  'export:revenue-ai-ctrip-pricing-template'
);
check(
  'package.json',
  'package exposes explicit execute Ctrip pricing input importer',
  packageJson.scripts?.['import:revenue-ai-ctrip-pricing-inputs:execute'] === 'C:\\xampp\\php\\php.exe scripts\\import_revenue_ai_ctrip_pricing_inputs.php --execute=1',
  'import:revenue-ai-ctrip-pricing-inputs:execute'
);
check(
  'package.json',
  'package exposes gated Ctrip pricing file to pending review runner',
  packageJson.scripts?.['run:revenue-ai-ctrip-pricing-file-to-pending-review'] === 'C:\\xampp\\php\\php.exe scripts\\execute_revenue_ai_ctrip_pricing_file.php',
  'run:revenue-ai-ctrip-pricing-file-to-pending-review'
);
check(
  'package.json',
  'package exposes Ctrip review-decision runner',
  packageJson.scripts?.['run:revenue-ai-ctrip-review-decision'] === 'C:\\xampp\\php\\php.exe scripts\\execute_revenue_ai_ctrip_review_decision.php',
  'run:revenue-ai-ctrip-review-decision'
);

includesAll('scripts/verify_revenue_ai_ctrip_scope.php', 'Ctrip-only Revenue AI runtime verifier keeps current DB scope explicit', [
  'RevenueAiOverviewService',
  "'platform' => 'ctrip'",
  "'enabled_channels' => ['ctrip']",
  "'source_channels_exact_ctrip'",
  "'channel_statuses_scoped_ctrip'",
  "'p0_authority_verifier_passed'",
  "'service_p0_gate_scoped_ctrip'",
  "'manual_review_queue_loaded'",
  "'manual_review_queue_navigation_target'",
  "'agent_and_execution_state_loaded'",
  'verify_p0_ota_field_loop_closure.php',
  "'ctrip_ota_channel'",
  "'ctrip_ota_channel_to_operation_roi'",
  "'operation_execution.roi_ready'",
  "'decision_allowed'",
  "'can_create_investment_decision'",
  "'meituan_not_present'",
  "'source_policy' => 'read_current_database_revenue_ai_overview_only'",
]);

includesAll('scripts/verify_revenue_ai_ctrip_generation_smoke.php', 'Ctrip generation smoke verifier proves pending AI decision path without persistence', [
  'Db::startTrans()',
  'Db::rollback()',
  'transactional_fixture_rollback_no_ota_write',
  'ctrip_generation_insert_fixture_inputs',
  'RevenueAiOverviewService',
  'OperationManagementService',
  'InvestmentDecisionSupportService',
  'new Agent($app)',
  'generatePriceSuggestions()',
  'buildOverviewFromEvidence',
  'ctrip_generation_investment_closure_overview',
  '--complete-operation-roi',
  'approveExecutionIntent(',
  'executeExecutionTask(',
  'reviewExecutionTask(',
  "'platform' => 'ctrip'",
  "'enabled_channels' => ['ctrip']",
  "'source_scope' => 'ctrip_ota_channel'",
  'local_manual_roi_evidence_no_ota_write',
  'CompetitorAnalysis::PLATFORM_CTRIP',
  'RoomType::STATUS_ENABLED',
  'PriceSuggestion::STATUS_PENDING',
  "'agent_generation_created_pending_suggestion'",
  "'generation_result_keeps_ctrip_manual_review_gate'",
  "'created_suggestion_uses_real_pricing_signals'",
  "'overview_queue_reads_pending_suggestion'",
  "'overview_preflight_moves_to_pending_review'",
  "'ai_to_operation_stays_blocked_before_review'",
  "'manual_review_approves_without_ota_write'",
  "'approved_suggestion_creates_operation_execution_intent'",
  "'execution_intent_keeps_manual_execution_boundary'",
  "'overview_reads_operation_execution_intent_after_review'",
  "'operation_intake_waiting_human_approval'",
  "'operation_intent_approval_creates_manual_task'",
  "'operation_execution_records_local_roi_evidence'",
  "'operation_review_marks_roi_ready'",
  "'overview_reads_operation_roi_ready_after_review'",
  "'investment_support_reads_closed_operation_roi_without_decision'",
  "'investment_support_action_queue_requires_decision_readiness'",
  "'investment_precheck_waiting_decision_record'",
  "'closed_operating_data_only'",
  "'operation_execution.roi_ready + decision_record.readiness_ready'",
  "'can_use_for_investment_judgement'",
  "'investment_overview_business_scope'",
  "'operation_execution_tasks'",
  "'operation_execution_evidence'",
  "'operation_execution.roi_ready'",
  "'operation_execution_intents'",
  "'transaction_rolled_back'",
  "'meituan_not_present'",
]);

includesAll('scripts/verify_revenue_ai_ctrip_pricing_input_pipeline.php', 'Ctrip pricing input pipeline runtime verifier protects operator evidence and rollback boundaries', [
  'runtime_operator_input_pipeline_rollback_only',
  'operator_input_file_pre_execute_gate_rollback_only',
  'scripts/import_revenue_ai_ctrip_pricing_inputs.php',
  'scripts/verify_revenue_ai_ctrip_scope.php',
  "'file' =>",
  '--print-current-template',
  '--lint-only=1',
  '--validate-only=1',
  '--force=1',
  'real_input_file_loaded',
  'real_input_file_lint_passes_without_db',
  'real_input_file_validate_only_rolls_back_without_generation',
  'real_input_file_dry_run_generates_pending_review_and_rolls_back',
  'real_input_file_dry_run_keeps_manual_review_gate',
  'real_input_file_scope_unchanged_after_rollback',
  'real_input_file_meituan_not_present',
  'operator-verified Ctrip pricing inputs',
  'current_real_db_gap_is_explicit',
  'room_types_empty',
  'placeholder_template_lint_fails_without_db',
  'filled_input_lint_passes_without_db',
  'validate_only_rolls_back_without_generation',
  'dry_run_generates_pending_review_and_rolls_back',
  'dry_run_generation_keeps_ctrip_manual_review_gate',
  'current_scope_unchanged_after_dry_run_rollback',
  "'platform' => 'ctrip'",
  "'enabled_channels' => ['ctrip']",
  "'source_scope' => 'ctrip_ota_channel'",
  "'auto_write_ota' => false",
  "'source_policy' => 'runtime_operator_input_pipeline_rollback_only'",
  'PHP_BINARY',
  'proc_open',
  'unlink',
  'meituan_not_present',
]);

includesAll('scripts/inspect_revenue_ai_ctrip_pricing_source_fields.php', 'Ctrip pricing source audit stays read-only and refuses to fabricate importable pricing inputs', [
  'RevenueAiOverviewService',
  'online_daily_data',
  'read_existing_online_daily_data_metadata_and_field_keys_only',
  'raw_values_exposed',
  'database_written',
  'ctrip_pricing_sources_field_facts',
  'can_prefill_import_file',
  'read_only_field_audit_no_values_no_import',
  'operator_verified_pricing_inputs_required',
  'room_types_enabled',
  'floor_price_or_min_rate_guard',
  'demand_forecast',
  'competitor_price_samples',
  'fact_metric_keys',
  'raw_key_samples_matching_pricing_terms',
  'sample_rows_without_raw_values',
  'candidate_source_audit',
  'metadata_counts_only_no_raw_values_no_import',
  'required_input_table_counts',
  'room_types_with_price_guards',
  'demand_forecasts_target_date',
  'competitor_analysis_ctrip_recent_7d',
  'ctrip_room_pricing_evidence_counts',
  'roomTypeNeedles',
  'priceGuardNeedles',
  'online_daily_data_room_type_key_target_date',
  'online_daily_data_price_guard_key_target_date',
  'ota_ctrip_metric_facts_room_type_key_all_dates',
  'ota_ctrip_entity_snapshots_price_guard_key_all_dates',
  'ota_ctrip_metric_facts_room_like_all_dates',
  'ota_ctrip_entity_snapshots_room_like_all_dates',
  'can_generate_operator_file_from_existing_db',
  'candidate_sources_metadata_only',
  'inspect_current_ota_evidence',
  'pre_execute_gate',
  'meituan',
]);

includesAll('scripts/report_revenue_ai_ctrip_pricing_operator_packet.php', 'Ctrip operator packet stays read-only and keeps real input gates explicit', [
  'read_only_operator_decision_packet_no_values_no_import',
  'raw_values_exposed',
  'database_written',
  'auto_write_ota',
  'meituan_scope_included',
  'inspect_revenue_ai_ctrip_pricing_source_fields.php',
  'import_revenue_ai_ctrip_pricing_inputs.php',
  'verify_revenue_ai_ctrip_scope.php',
  'operator_required_fields',
  'candidate_source_audit',
  'Candidate Source Audit',
  'required_input_table_counts',
  'ctrip_room_pricing_evidence_counts',
  'prefill_eligibility.status',
  'can_generate_operator_file_from_existing_db',
  'candidate_source_audit_counts_only',
  'fillable_template',
  'allowed_commands',
  'forbidden_shortcuts',
  'stop_conditions',
  'operator_packet',
  'export_operator_bundle',
  'export:revenue-ai-ctrip-operator-bundle',
  '--output-dir=<operator-bundle-dir>',
  'pre_execute_gate',
  'gate_then_execute_and_generate_pending_review',
  'pending_review_packet',
  'report:revenue-ai-ctrip-pending-review-packet',
  'verify_pending_review_packet',
  'verify:revenue-ai-ctrip-pending-review-packet',
  'export_review_decision_template',
  'export:revenue-ai-ctrip-review-template',
  '--suggestion-id=<pending-suggestion-id>',
  '--output=<review-decision-json-path>',
  'validate_review_decision',
  'execute_review_decision_and_create_operation_intent',
  'verify_review_decision',
  'verify:revenue-ai-ctrip-review-decision',
  'run:revenue-ai-ctrip-review-decision',
  'run:revenue-ai-ctrip-pricing-file-to-pending-review',
  'verify:revenue-ai-ctrip-pricing-file',
  'source_audit_passed',
  'template_export_passed',
  'runtime_scope_passed',
  'no_meituan_source_summary',
  'operator_input_still_required',
  'can_prefill_import_file',
  'room_types',
  'demand_forecasts',
  'competitor_price_samples',
  'ota_platform=ctrip',
  'Do not use Meituan rows or whole-hotel operating values',
  'Do not convert traffic, business, quality, or peer-rank rows',
  'Do not fill missing prices with sample, guessed, fallback, or verifier-only values',
  'Do not set auto_write_ota=true',
  'manual review and ROI evidence',
]);

includesAll('scripts/report_revenue_ai_ctrip_gap_pack.php', 'Ctrip real-input gap pack reports the whole Revenue AI closure without reading raw capture or writing data', [
  'read_current_revenue_ai_overview_and_operator_bundle_only',
  'raw_capture_read',
  'does_not_run_p0_verifier_or_read_raw_capture',
  'database_written',
  'auto_write_ota',
  'meituan_scope_included',
  'ctrip_ota_channel',
  'ctrip_p0_target_day',
  'pricing_generation_inputs',
  'ai_pending_review_suggestion',
  'human_review_to_operation_intent',
  'execution_evidence_and_roi_window',
  'investment_manual_review',
  'pricing-input-fillable.json',
  'placeholder_details',
  'expected_real_input',
  'forbidden_fill',
  'Fillable Placeholder Checklist',
  'previous_day',
  'next_day',
  'revenue',
  'room_nights',
  'orders',
  'conversion',
  'traffic',
  'operation_execution.roi_ready',
  'Ctrip OTA channel evidence must not be promoted to whole-hotel operating truth.',
  'Do not fill missing room, price, demand, competitor, execution, or ROI values with samples, guesses, fallbacks, or verifier-only fixtures.',
]);

includesAll('scripts/export_revenue_ai_ctrip_operator_bundle.php', 'Ctrip operator bundle exports handoff files without data writes', [
  'operator_handoff_bundle_no_values_no_import',
  'pricing-input-template.json',
  'pricing-input-fillable.json',
  'operator-packet.md',
  'pending-review-packet.json',
  'current-scope.json',
  'manifest.json',
  'raw_values_exposed',
  'database_written',
  'auto_write_ota',
  'meituan_scope_included',
  'Edit pricing-input-fillable.json with operator-verified Ctrip OTA channel values',
  'Use pricing-input-template.json as read-only context',
  'Do not use Meituan rows, whole-hotel values, sample values, guessed values, fallback values, or verifier-only values.',
  'Do not create OTA price writes from this bundle',
  'report_revenue_ai_ctrip_pricing_operator_packet.php',
  'import_revenue_ai_ctrip_pricing_inputs.php',
  'report_revenue_ai_ctrip_pending_review_packet.php',
  'verify_revenue_ai_ctrip_scope.php',
  'next_commands_after_filling_template',
  'preflight_no_execute',
  'verify:revenue-ai-ctrip-operator-bundle-preflight',
  'lint:revenue-ai-ctrip-pricing-inputs',
  'validate:revenue-ai-ctrip-pricing-inputs',
  'verify:revenue-ai-ctrip-pricing-file',
  'run:revenue-ai-ctrip-pricing-file-to-pending-review',
  'report:revenue-ai-ctrip-pending-review-packet',
  'verify:revenue-ai-ctrip-scope',
  'Bundle file already exists. Pass --force=1 to overwrite',
  "'source_scope' => 'ctrip_ota_channel'",
  "'enabled_channels' => ['ctrip']",
  'ctrip_operator_bundle_fillable_payload',
  "'pricing_input_fillable_json'",
  '$fillableFile',
]);

{
  const file = 'scripts/export_revenue_ai_ctrip_operator_bundle.php';
  const source = read(file);
  const commandStart = source.indexOf("'next_commands_after_filling_template' => [");
  const commandEnd = commandStart >= 0 ? source.indexOf('        ],\n        ', commandStart) : -1;
  const commandBlock = commandStart >= 0 && commandEnd > commandStart
    ? source.slice(commandStart, commandEnd)
    : '';
  const inputFileCommands = [
    'lint_only',
    'validate_only',
    'dry_run',
    'pre_execute_gate',
    'execute_to_pending_review',
  ];
  const commandsUsingFillable = inputFileCommands.filter((name) => commandBlock.includes(`'${name}' => ctrip_operator_bundle_command('${name}', $date, $resolvedHotelId, $fillableFile)`));
  const commandsUsingTemplate = inputFileCommands.filter((name) => commandBlock.includes(`'${name}' => ctrip_operator_bundle_command('${name}', $date, $resolvedHotelId, $templateFile)`));
  check(
    file,
    'Ctrip operator bundle follow-up commands use the fillable input file, not the read-only template',
    commandsUsingFillable.length === inputFileCommands.length && commandsUsingTemplate.length === 0,
    JSON.stringify({ commandsUsingFillable, commandsUsingTemplate })
  );
}

includesAll('scripts/verify_revenue_ai_ctrip_operator_bundle.mjs', 'Ctrip operator bundle verifier stays structure-only and fillable-file scoped', [
  'operator_bundle_structure_only_no_db_no_ota_write',
  'database_touched: false',
  'auto_write_ota: false',
  'pricing-input-fillable.json',
  'pricing-input-template.json',
  'input_commands_use_fillable_file',
  'input_commands_are_hotel_scoped',
  'commands_do_not_write_ota',
  'pending_operator_real_values',
  'operator_verified_values_required',
  'can_generate_pending_review: false',
  'fillable_contains_only_real_input_fields',
  'verification_commands',
  'operator_fill_required',
  'current_preflight',
  '--hotel-id',
  '--date',
]);

includesAll('scripts/verify_revenue_ai_ctrip_operator_bundle_preflight.mjs', 'Ctrip operator bundle preflight runs the full no-execute gate sequence', [
  'operator_bundle_preflight_no_execute_no_ota_write',
  'database_commit_allowed: false',
  'execute_allowed: false',
  'auto_write_ota: false',
  'verify:revenue-ai-ctrip-operator-bundle',
  'lint:revenue-ai-ctrip-pricing-inputs',
  'validate:revenue-ai-ctrip-pricing-inputs',
  'import:revenue-ai-ctrip-pricing-inputs',
  'verify:revenue-ai-ctrip-pricing-file',
  'pricing-input-fillable.json',
  'ready_for_execute_to_pending_review',
  'next_execute_command_allowed_only_after_pass',
  'validate_only_rollback',
  'dry_run_rollback',
  'pre_execute_gate_rollback',
]);

includesAll('scripts/report_revenue_ai_ctrip_pending_review_packet.php', 'Ctrip pending review packet stays read-only and manual-review gated', [
  'RevenueAiOverviewService',
  'PriceSuggestion::STATUS_PENDING',
  "'platform' => 'ctrip'",
  "'enabled_channels' => ['ctrip']",
  'read_only_pending_review_packet_no_write',
  'blocked_empty_queue',
  'pending_review',
  'price_suggestions',
  'Ctrip Revenue AI Pending Review Packet',
  'ctrip_pending_review_build_packet',
  'database_written',
  'auto_write_ota',
  'meituan_scope_included',
  'manual_review_required',
  'review_endpoint_base',
  '/api/revenue-ai/price-suggestions/{id}/review',
  'execution_intent_endpoint_base',
  '/api/revenue-ai/price-suggestions/{id}/execution-intent',
  'allowed_manual_actions',
  'approve_with_changes',
  'operation_execution_before_review',
  'investment_decision_before_operation_roi',
  'upstream_next_commands',
  'report:revenue-ai-ctrip-pricing-operator-packet',
  'export_operator_bundle',
  'export:revenue-ai-ctrip-operator-bundle',
  '--output-dir=<operator-bundle-dir>',
  'run:revenue-ai-ctrip-pricing-file-to-pending-review',
  'verify:revenue-ai-ctrip-pending-review-packet',
  'export_review_decision_template',
  '--suggestion-id=<pending-suggestion-id>',
  '--output=<review-decision-json-path>',
  'validate_review_decision',
  'execute_review_decision_and_create_operation_intent',
  'verify_review_decision',
  'run:revenue-ai-ctrip-review-decision',
  'pending_items_have_no_meituan',
  'manual_review_gate_no_auto_apply',
]);

includesAll('scripts/verify_revenue_ai_ctrip_pending_review_packet.php', 'Ctrip pending review packet verifier proves positive review queue path with rollback', [
  'Db::startTrans()',
  'Db::rollback()',
  'transactional_pending_review_packet_fixture_rollback_no_ota_write',
  'ctrip_pending_review_build_packet',
  'ctrip_pending_review_verify_insert_fixture',
  'RoomType::STATUS_ENABLED',
  'PriceSuggestion::STATUS_PENDING',
  'PriceSuggestion::TYPE_COMPETITOR',
  "'source_scope' => 'ctrip_ota_channel'",
  "'source_channels' => ['ctrip']",
  'manual_review_required_no_auto_rate_write',
  'packet_reads_pending_review_item',
  'created_item_review_contract_complete',
  'packet_contract_blocks_downstream_shortcuts',
  'operation_execution_before_review',
  'investment_decision_before_operation_roi',
  'transaction_rolled_back',
  'meituan_not_present',
]);

includesAll('scripts/import_revenue_ai_ctrip_pricing_inputs.php', 'Ctrip pricing input importer requires operator evidence and explicit persistence', [
  'Db::startTrans()',
  'Db::rollback()',
  'Db::commit()',
  "'output' =>",
  "'force' =>",
  '--execute',
  '--generate',
  '--lint-only',
  '--validate-only',
  '--print-template',
  '--print-current-template',
  'ctrip_pricing_import_write_json_output',
  'ctrip_pricing_import_current_template',
  'Output file already exists. Pass --force=1 to overwrite',
  "'output_file' =>",
  'ctrip_pricing_import_lint_payload',
  'operator_provided_ctrip_pricing_inputs_lint_only_no_db',
  'database_touched',
  'placeholder_value',
  'room_type_key_unmatched',
  'competitor_platform_invalid',
  '--lint-only cannot be combined with --execute, --generate, or --validate-only.',
  'operator_provided_ctrip_pricing_input_template_from_current_preflight',
  'current_preflight',
  'operator_fill_required',
  'required_input_codes',
  'verification_commands',
  'operator_packet',
  'report:revenue-ai-ctrip-pricing-operator-packet',
  'export_operator_bundle',
  'export:revenue-ai-ctrip-operator-bundle',
  '--output-dir=<operator-bundle-dir>',
  'inspect_current_ota_evidence',
  'inspect:revenue-ai-ctrip-pricing-sources',
  'export_to_file',
  'lint_only',
  'validate_only',
  'pre_execute_gate',
  'gate_then_execute_and_generate_pending_review',
  'pending_review_packet',
  'report:revenue-ai-ctrip-pending-review-packet',
  'verify_pending_review_packet',
  'verify:revenue-ai-ctrip-pending-review-packet',
  'export_review_decision_template',
  'export:revenue-ai-ctrip-review-template',
  '--suggestion-id=<pending-suggestion-id>',
  '--output=<review-decision-json-path>',
  'validate_review_decision',
  'execute_review_decision_and_create_operation_intent',
  'verify_review_decision',
  'verify:revenue-ai-ctrip-review-decision',
  'run:revenue-ai-ctrip-review-decision',
  'run:revenue-ai-ctrip-pricing-file-to-pending-review',
  'verify:revenue-ai-ctrip-pricing-file',
  'Replace every <...> placeholder',
  '--validate-only cannot be combined with --execute or --generate.',
  'operator_provided_ctrip_pricing_inputs_validate_only_rollback',
  'validate_only_keeps_generation_disabled',
  'operator_provided_ctrip_pricing_inputs_dry_run_rollback',
  'operator_provided_ctrip_pricing_inputs_execute',
  "'source_scope' => 'ctrip_ota_channel'",
  "'enabled_channels' => ['ctrip']",
  'source_scope must be ctrip_ota_channel',
  'platform must be ctrip',
  'competitor_price_samples.ota_platform must be ctrip',
  'ctrip_pricing_import_date',
  'analysis_date_invalid',
  'analysis_date_outside_recent_7d',
  'analysis_date must be within the 7-day window ending at business_date',
  'earliest_allowed_date',
  'latest_allowed_date',
  'new Agent($app)',
  'saveRoomType()',
  'createForecast()',
  'recordCompetitorPrice()',
  'generatePriceSuggestions()',
  'RevenueAiOverviewService',
  'pricing_preflight_has_candidate',
  'generation_keeps_manual_review_gate',
  "'auto_write_ota' => false",
  'manual_pricing_configuration',
  'operator_provided',
  'source_file_sha256',
  'import_mode',
  'RoomType::where',
  'DemandForecast::where',
  'CompetitorAnalysis::PLATFORM_CTRIP',
]);

{
  const file = 'scripts/import_revenue_ai_ctrip_pricing_inputs.php';
  const source = read(file);
  const commandStart = source.indexOf("'verification_commands' => [");
  const commandEnd = commandStart >= 0 ? source.indexOf('        ],\n    ];', commandStart) : -1;
  const commandBlock = commandStart >= 0 && commandEnd > commandStart
    ? source.slice(commandStart, commandEnd)
    : '';
  const commandLines = commandBlock
    .split('\n')
    .filter((line) => line.includes('npm.cmd run'));
  const unscopedCommands = commandLines.filter((line) => !line.includes('$hotelArg'));
  check(
    file,
    'Ctrip pricing input template keeps every verification command scoped to the resolved hotel',
    commandLines.length >= 19 && unscopedCommands.length === 0,
    unscopedCommands.join('\n')
  );
  const inputPlaceholderScope = "foreach (['room_types', 'demand_forecasts', 'competitor_price_samples'] as $inputKey)";
  const placeholderScopeCount = source.split(inputPlaceholderScope).length - 1;
  check(
    file,
    'Ctrip pricing input placeholder checks scan only real import input arrays at root',
    placeholderScopeCount >= 2
      && source.includes("ctrip_pricing_import_assert_no_placeholder($value[$inputKey], '$.' . $inputKey)")
      && source.includes("ctrip_pricing_import_placeholder_paths($value[$inputKey], '$.' . $inputKey)"),
    `placeholderScopeCount=${placeholderScopeCount}`
  );
}

includesAll('scripts/execute_revenue_ai_ctrip_pricing_file.php', 'Gated Ctrip pricing file runner enforces pre-execute gate before persistence', [
  'verify_revenue_ai_ctrip_pricing_input_pipeline.php',
  'operator_input_file_pre_execute_gate_rollback_only',
  'gate_rolled_back_without_ota_write',
  'pre_execute_gate_passed_no_persistence',
  'gate_failed_no_execute',
  'pre_execute_gate_then_explicit_execute',
  'import_revenue_ai_ctrip_pricing_inputs.php',
  '--execute=1',
  '--generate=1',
  'execute_committed_after_gate',
  'execute_keeps_ctrip_manual_review_gate',
  'verify_revenue_ai_ctrip_scope.php',
  'post_execute_scope_verifier_passes',
  'post_execute_pending_review_visible',
  'pending_review',
  'source_scope',
  'ctrip_ota_channel',
  'source_channels',
  'auto_write_ota',
  'operation_intake_allowed',
  'database_written',
  'If these are operator-verified real Ctrip values',
  'Review pending Ctrip AI price suggestions before creating any operation execution',
]);

includesAll('scripts/execute_revenue_ai_ctrip_review_decision.php', 'Ctrip review-decision runner gates manual review before operation intent', [
  'ctrip_review_decision_template',
  'ctrip_review_decision_template_for_pending',
  'ctrip_review_decision_pending_template_context',
  'ctrip_review_decision_write_json_output',
  'ctrip_review_decision_run',
  'ctrip_review_decision_build_manual_review',
  'review_decision_template_from_pending_suggestion',
  'review_decision_template_without_pending_suggestion',
  'Output file already exists. Pass --force=1 to overwrite',
  'pending_suggestion',
  'output_file',
  'suggestion_id',
  'operator_review_decision_validate_only_rollback',
  'operator_review_decision_explicit_execute',
  'review_decision_lint_no_write',
  'review_decision_gate_no_write',
  'source_scope',
  'ctrip_ota_channel',
  "'platform' => 'ctrip'",
  "'auto_write_ota' => false",
  'PriceSuggestion::STATUS_PENDING',
  'PriceSuggestion::STATUS_APPROVED',
  'PriceSuggestion::STATUS_REJECTED',
  'approve_with_changes',
  'reject_does_not_create_intent',
  'operation_intent_keys_present',
  'suggestion_source_ctrip_only',
  'approved_price_within_guard',
  'manual_review_written_locally',
  'review_keeps_no_ota_write',
  'execution_intent_policy_respected',
  'ctrip_review_decision_operation_evidence_handoff',
  'ctrip_review_decision_roi_window',
  'operation_evidence_handoff',
  'previous_day',
  'next_day',
  'ctrip_ota_channel_execution_evidence',
  'do_not_promote_ctrip_ota_scope_to_whole_hotel_truth',
  'local_manual_roi_evidence_no_ota_write',
  'OperationManagementService',
  'buildPriceSuggestionExecutionIntentInput',
  'createExecutionIntent',
  'operation_execution_intents',
  'price_suggestions.factors.manual_review_versions',
  'Use approve_with_changes when the operator supplies an approved_price',
]);

includesAll('scripts/verify_revenue_ai_ctrip_review_decision.php', 'Ctrip review-decision verifier proves post-review operation-intent path with rollback', [
  'Db::startTrans()',
  'Db::rollback()',
  'transactional_review_decision_fixture_rollback_no_ota_write',
  'ctrip_review_decision_run',
  'ctrip_review_decision_verify_insert_fixture',
  'ctrip_review_decision_verify_payload',
  'review_template_output_written',
  'review_template_prefills_pending_suggestion',
  'review_template_output_overwrite_guarded',
  'review_decision_template_from_pending_suggestion',
  'Output file already exists',
  'force_overwrite',
  'output_file_written',
  'RoomType::STATUS_ENABLED',
  'PriceSuggestion::STATUS_PENDING',
  'PriceSuggestion::TYPE_COMPETITOR',
  "'source_scope' => 'ctrip_ota_channel'",
  "'source_channels' => ['ctrip']",
  'approve_with_changes',
  "'approved_price' => 335",
  "'create_execution_intent_after_approval' => true",
  "'manage_transaction' => false",
  'review_decision_runner_passed',
  'review_decision_scope_ctrip_only',
  'approve_with_changes_records_manual_review',
  'post_approval_execution_intent_created',
  'operation_evidence_handoff_includes_roi_window',
  'runner_checks_include_boundaries',
  'transaction_rolled_back',
  'meituan_not_present',
  'operation_execution_intents',
]);

includesAll('route/app.php', 'Revenue AI and operation routes are authenticated and complete', [
  "Route::group('api/revenue-ai'",
  "Route::get('/overview', 'RevenueAi/overview')",
  "Route::post('/price-suggestions/:id/review', 'RevenueAi/reviewPriceSuggestion')",
  "Route::post('/price-suggestions/:id/execution-intent', 'RevenueAi/createPriceSuggestionExecutionIntent')",
  "Route::post('/execution-intents/:id/approve', 'OperationManagement/approveExecutionIntent')",
  "Route::post('/execution-tasks/:id/execute', 'OperationManagement/executeExecutionTask')",
  "Route::post('/execution-tasks/:id/evidence', 'OperationManagement/executionTaskEvidence')",
  "Route::post('/execution-tasks/:id/review', 'OperationManagement/reviewExecutionTask')",
  '->middleware(\\app\\middleware\\Auth::class)',
]);

includesAll('app/controller/RevenueAi.php', 'Revenue AI review keeps versioned audit and no OTA write boundary', [
  'requestedEnabledChannels',
  "'enabled_channels'",
  "'platform'",
  'revenue_ai_channel_invalid',
  'recordPriceSuggestionManualReview(',
  'buildManualReviewState(',
  "'manual_review_versions'",
  "'manual_review'",
  "'status_after'",
  "'reviewed_by'",
  "'reviewed_at'",
  "'auto_write_ota' => false",
  "'local_price_updated' => false",
  "'ota_write' => false",
  "'forbidden_actions' => ['apply_price', 'ota_write', 'update_room_type_base_price']",
  'PriceSuggestion::STATUS_PENDING',
  'PriceSuggestion::STATUS_APPROVED',
  'OperationManagementService',
  'createExecutionIntent(',
]);

includesAll('app/controller/Agent.php', 'Agent price suggestion generation exposes blocked preconditions without fake success', [
  'buildPriceSuggestionGenerationBlockedResult',
  'buildPriceSuggestionGenerationRuntimeResult',
  'roomTypes(): Response',
  'saveRoomType(): Response',
  'normalizeRoomTypePayload',
  'normalizeDemandForecastPayload',
  'normalizeCtripCompetitorPricePayload',
  'manualCtripPricingInputMetadata',
  'parsePositiveRoomTypeMoney',
  "'status' => 'blocked'",
  "'reason' => $reason",
  "'source_scope' => 'ctrip_ota_channel'",
  "'source_channels' => ['ctrip']",
  "'review_endpoint_base' => '/api/revenue-ai/price-suggestions/{id}/review'",
  "'execution_intent_endpoint_base' => '/api/revenue-ai/price-suggestions/{id}/execution-intent'",
  "'ai_review_gate' => [",
  "'pending_manual_review'",
  "'auto_apply_ai_advice' => false",
  "'operation_intake_allowed' => false",
  "'can_generate_pending_suggestions' => false",
  "'manual_review_required' => true",
  "'auto_write_ota' => false",
  "'required_inputs' => $this->buildPriceSuggestionGenerationRequiredInputs($reason)",
  "'room_types_empty'",
  "'room_types_enabled'",
  "'floor_price_or_min_rate_guard'",
  "'demand_forecast'",
  "'competitor_price_samples'",
  "'pricing_candidate_signal'",
  "'price suggestion generation blocked'",
  "'input_scope' => 'manual_pricing_configuration'",
  "'target_workflow' => 'ctrip_revenue_ai_pricing_generation'",
  "'evidence_status' => 'operator_provided'",
  "'auto_write_ota' => false",
  "'source_scope' => 'ctrip_ota_channel'",
  "$result['input_type'] = $inputType;",
  "'manual_demand_forecast'",
  "'manual_ctrip_competitor_price_sample'",
  'CompetitorAnalysis::PLATFORM_CTRIP',
]);

includesAll('route/app.php', 'Agent exposes manual room type pricing guard routes for Ctrip Revenue AI preflight', [
  "Route::get('/room-types', 'Agent/roomTypes')",
  "Route::post('/room-types', 'Agent/saveRoomType')",
]);

includesAll('app/model/CompetitorAnalysis.php', 'competitor price matrix can display operator-provided Ctrip sample name', [
  '$operatorCompetitorName',
  "$competitorData['competitor_name']",
  "'competitor_data' => $competitorData",
]);

excludesAll('app/controller/RevenueAi.php', 'Revenue AI endpoints do not directly apply prices', [
  '->apply(',
  "Db::name('room_types')",
  '$roomType->base_price',
  'update_room_type_base_price(',
]);

includesAll('app/service/OperationManagementService.php', 'operation bridge carries approved suggestion into execution and ROI closure', [
  'buildPriceSuggestionExecutionIntentInput',
  'latestManualReviewFromFactors',
  'manualApprovedPriceFromReview',
  "'source_module' => 'price_suggestion'",
  "'manual_review_storage' =>",
  'createExecutionIntent(',
  'approveExecutionIntent(',
  'executeExecutionTask(',
  'addExecutionEvidence(',
  'reviewExecutionTask(',
  'buildExecutionRoi(',
  'after_revenue - before_revenue',
]);

includesAll('app/service/RevenueAiOverviewService.php', 'Revenue AI overview separates process progress, review input, and ROI truth', [
  'buildPriceSuggestionReviewQueue',
  'buildExecutionSummaryFromFlow',
  '$channels = $enabledChannels !== [] ? $enabledChannels : self::CHANNELS;',
  'foreach ($channels as $channel)',
  "'enabled_channels' => $enabledChannels",
  '$reviewQueue = $this->priceSuggestionReviewQueue($businessDate, $hotelId);',
  '$pricingGenerationPreflight = $this->pricingGenerationPreflight($businessDate, $hotelId, $hotelIds, $dataset, $channels);',
  '$agentActivity = $this->agentActivity($businessDate, $hotelId);',
  '$executionSummary = $this->executionSummary($businessDate, $hotelId, $hotelIds);',
  "'review_queue' => $reviewQueue",
  "'pricing_generation_preflight' => $pricingGenerationPreflight",
  "'agent_activity' => $agentActivity",
  "'execution_summary' => $executionSummary",
  'sortExecutionEffectReviewInputs',
  'executionEffectReviewInputAction',
  "'input_action_key' =>",
  "'record_execution_evidence'",
  "'record_roi_evidence'",
  "'record_effect_review'",
  'operationFeedbackInputGate',
  "'operation_feedback_input'",
  "'next_day_input_ready'",
  'priceSuggestionExpectedRevparImpact',
  'expected_revpar_impact_missing',
  'pricingGenerationPreflight',
  'room_types_empty',
  'pricing_candidate_signals_missing',
  'configure_enabled_room_types_and_floor_price_guards',
  'pricingAiDecisionResolutionPlan',
  'pricingAiDecisionReviewContract',
  'pricingAiToOperationHandoff',
  'pricingOperationToInvestmentHandoff',
  'pricingOperationRoiGate',
  'pricingOperationIntakePreflightContract',
  "'ai_decision_review_contract'",
  "'ai_decision_resolution_plan'",
  "'ai_to_operation_handoff'",
  "'operation_to_investment_handoff'",
  "'operation_intake_preflight_contract'",
  "'investment_precheck_packet'",
  'OperationManagementService::buildExecutionIntentPayload',
  '/api/operation/execution-intents',
  'InvestmentDecisionSupportService::buildOverviewFromEvidence',
  '/api/investment-decision/overview',
  'operation_execution.roi_ready',
  'manual_review_requires_explicit_evidence_no_auto_apply',
  'operation_intake_requires_approved_ai_review_and_price_target_no_auto_create',
  'investment_decision_requires_closed_operation_roi_not_ota_channel_only',
  'fill_missing_evidence_with_defaults',
  'provide_available_room_nights_or_mark_metric_unusable',
  'persist_or_attach_manual_review_record',
  'open_agent_pricing_suggestions_and_generate_pending_review_items',
  "'target_agent_tab'",
  "'target_revenue_tab'",
  'call_create_execution_intent_before_ai_review_approval',
  'auto_create_operation_execution_intent',
  'create_investment_decision_from_ota_channel_only',
  'create_investment_record_without_closed_operation_roi',
]);

includesAll('public/revenue-ai-static.js', 'Revenue AI helper exposes manual review and effect review actions without fake closure', [
  'buildRevenueAiOverviewQuery',
  "platform = 'ctrip'",
  "params.set('platform', platformText)",
  'buildRevenueAiReviewQueueItems',
  'canApproveWithChanges',
  'canCreateExecutionIntent',
  'actionEntry',
  'autoWriteOta',
  'buildRevenueAiInvestmentPrecheckSummary',
  'buildRevenueAiResolutionPlanSummary',
  'buildRevenueAiPricingGenerationPreflightSummary',
  'buildRevenueAiPriceSuggestionGenerateResult',
  'targetAgentTab',
  'targetRevenueTab',
  'reviewQueueTarget',
  'reviewQueueCanOpenTarget',
  'investmentPrecheckSummary',
  'investmentPrecheckVisible',
  'pricingGenerationPreflightSummary',
  'pricingGenerationPreflightVisible',
  'resolutionPlanSummary',
  'resolutionPlanVisible',
  'operation_to_investment_handoff',
  'investment_precheck_packet',
  'investment_precheck_blocked_by_operation_roi',
  'operation_execution.roi_ready',
  'buildRevenueAiExecutionRows',
  'buildRevenueAiEffectReviewRows',
  'inputActionKey: item.input_action_key ||',
  'nextActionKey: item.input_action_key || item.target_action ||',
  'revparImpactReason',
  'expected_revpar_impact_missing',
  'resolveRevenueAiReviewActionDraft',
  'const endpoint = revenueAiReviewEndpoint(item, normalizedAction);',
  "endpoint.startsWith('/revenue-ai/price-suggestions/')",
]);

includesAll('public/index.html', 'Revenue AI homepage can execute the manual closure path only through local evidence routes', [
  '@click="submitRevenueAiReviewAction(item, \'approve\')"',
  '@click="submitRevenueAiReviewAction(item, \'approve_with_changes\')"',
  '@click="submitRevenueAiReviewAction(item, \'reject\')"',
  '@click="submitRevenueAiReviewAction(item, \'execution_intent\')"',
  "if (normalizedAction === 'execution_intent') {",
  "const revenueAiResolveReviewActionDraft = requireRevenueAiStatic('resolveRevenueAiReviewActionDraft');",
  'const draft = revenueAiResolveReviewActionDraft({ item, action });',
  'const body = revenueAiBuildReviewRequestBody({',
  'openRevenueAiExecutionItem',
  "if (navigation.targetPage === 'agent-center') {",
  'navigation.targetAgentTab',
  'navigation.targetRevenueTab',
  'navigation.targetFilter',
  'loadPriceSuggestions()',
  'recordOperationExecutionEvidence(taskItem)',
  'recordOperationRoiEvidence(taskItem)',
  'reviewOperationExecutionTask(taskItem)',
  "`/operation/execution-tasks/${taskId}/execute`",
  "`/operation/execution-tasks/${taskId}/evidence`",
  "`/operation/execution-tasks/${taskId}/review`",
  "evidence_type: 'manual_price_execution'",
  "evidence_type: 'manual_roi_evidence'",
  "evidence_boundary: 'local_manual_evidence_no_ota_write'",
  "evidence_boundary: 'local_manual_roi_evidence_no_ota_write'",
  '{{ item.impactLine }}',
  'data-testid="revenue-ai-investment-precheck"',
  'data-testid="revenue-ai-resolution-plan"',
  'data-testid="revenue-ai-pricing-generation-preflight"',
  'data-testid="agent-pricing-generation-preflight-summary"',
  'data-testid="agent-pricing-generation-preflight-gaps"',
  'data-testid="agent-pricing-generation-hotel-checks"',
  'data-testid="agent-price-suggestion-generate-result"',
  'data-testid="agent-price-suggestion-skipped-items"',
  'data-testid="agent-room-type-pricing-guard"',
  'data-testid="agent-price-suggestion-ctrip-preflight-inputs"',
  'data-testid="agent-suggestion-demand-forecast-manual-input"',
  'data-testid="agent-suggestion-ctrip-competitor-price-manual-input"',
  'data-testid="agent-demand-forecast-manual-input"',
  'data-testid="agent-ctrip-competitor-price-manual-input"',
  "const revenueAiBuildPricingGenerationPreflightSummary = requireRevenueAiStatic('buildRevenueAiPricingGenerationPreflightSummary');",
  "const revenueAiBuildPriceSuggestionGenerateResult = requireRevenueAiStatic('buildRevenueAiPriceSuggestionGenerateResult');",
  'agentPricingGenerationPreflightSummary',
  'agentPricingGenerationPreflightSummary.autoWriteOta',
  'agentPricingGenerationPreflightSummary.requiredInputs',
  'agentPricingGenerationPreflightSummary.candidateSkipReasons',
  'agentPricingGenerationPreflightSummary.candidateDataGaps',
  'agentPricingGenerationPreflightSummary.hotelChecks',
  "const loadPriceSuggestionWorkbench = async () => {",
  'handlePriceSuggestionDateChange',
  'demandForecastForm.value.forecast_date = date',
  'competitorPriceForm.value.analysis_date = date',
  'loadDemandForecasts()',
  'loadCompetitorAnalysis()',
  "request(`/agent/room-types?${params}`)",
  "request('/agent/room-types'",
  "request('/agent/demand-forecasts'",
  "request('/agent/competitor-analysis'",
  'manualCtripPricingInputMeta',
  "source_scope: 'ctrip_ota_channel'",
  "target_workflow: 'ctrip_revenue_ai_pricing_generation'",
  "evidence_status: 'operator_provided'",
  'auto_write_ota: false',
  "input_type: 'manual_demand_forecast'",
  "input_type: 'manual_ctrip_competitor_price_sample'",
  'ota_platform: 1',
  "人工配置项，仅用于携程调价预检；不是 OTA 自动采集事实，不写 OTA。",
  'priceSuggestionGenerateResult.value = result',
  'priceSuggestionGenerateResult.autoWriteOta',
  'priceSuggestionGenerateResult.requiredInputs',
  'priceSuggestionGenerateResult.skippedItems',
  'item.primarySignalCount',
  'item.dataGaps',
  'item.reviewChecklist',
  "request(`/revenue-ai/price-suggestions/${id}/review`",
  'Agent 定价建议工作台人工',
  "request(`/revenue-ai/price-suggestions/${id}/execution-intent`",
  "source: 'agent_pricing_suggestions'",
  'action.reviewQueueCanOpenTarget',
  'action.pricingGenerationPreflightVisible',
  'openRevenueAiDecisionBasis(action.reviewQueueTarget)',
  'action.investmentPrecheckVisible',
  'action.resolutionPlanVisible',
  'action.investmentPrecheckSummary?.targetEntry',
  '只读',
]);

excludesAll('public/index.html', 'Agent pricing suggestion workbench no longer bypasses Revenue AI review bridge', [
  '/agent/price-suggestions/${id}/approve?action=${action}',
  '/agent/price-suggestions/${id}/execution-intent`',
]);

includesAll('tests/RevenueAiControllerTest.php', 'controller tests prove versioned manual review and no OTA write', [
  'testRequestedEnabledChannelsKeepsCtripScopeExplicit',
  'testRequestedEnabledChannelsRejectsUnknownScope',
  'revenue_ai_channel_invalid',
  'testManualReviewStateVersionsPlainApproveWithoutOtaWrite',
  'testManualReviewStateVersionsRejectWithoutApprovedPrice',
  'testExecutionIntentPayloadDoesNotClaimPriceApplication',
  "self::assertFalse($review['auto_write_ota']);",
  "self::assertFalse($payload['ota_write']);",
]);

includesAll('tests/AgentTest.php', 'Agent generation tests prove created suggestions keep Ctrip review gate', [
  'testPriceSuggestionGenerationCreatedResultKeepsCtripReviewGate',
  "self::assertSame('ctrip_ota_channel', $result['source_scope']);",
  "self::assertSame(['ctrip'], $result['source_channels']);",
  "self::assertSame('/api/revenue-ai/price-suggestions/{id}/review', $result['review_endpoint_base']);",
  "self::assertSame('pending_manual_review', $result['ai_review_gate']['status']);",
  "self::assertFalse($result['ai_review_gate']['auto_apply_ai_advice']);",
  "self::assertFalse($result['ai_review_gate']['operation_intake_allowed']);",
]);

includesAll('tests/RevenueAiOverviewServiceTest.php', 'overview tests prove review queue, RevPAR impact, and ROI input truth', [
  'testPriceSuggestionReviewQueueSummarizesManualReviewState',
  'testPriceSuggestionReviewQueueExposesExplicitExpectedRevparImpactOnly',
  'testExecutionSummarySeparatesProcessProgressFromEffectReview',
  'testExecutionSummaryPrioritizesEffectReviewInputsWithDataGaps',
  'testExecutionSummaryFiltersByBusinessDateAndMarksReviewedEffectReady',
  'ai_decision_review_contract',
  'ai_decision_resolution_plan',
  'ai_to_operation_handoff',
  'operation_to_investment_handoff',
  'operation_intake_preflight_contract',
  'investment_precheck_packet',
  'manual_review_requires_explicit_evidence_no_auto_apply',
  'operation_intake_requires_approved_ai_review_and_price_target_no_auto_create',
  'investment_decision_requires_closed_operation_roi_not_ota_channel_only',
  'fill_missing_evidence_with_defaults',
  'provide_floor_price_or_min_rate_guard',
  'persist_or_attach_manual_review_record',
  'OperationManagementService::buildExecutionIntentPayload',
  'InvestmentDecisionSupportService::buildOverviewFromEvidence',
  '/api/investment-decision/overview',
  'operation_execution.roi_ready',
  'create_investment_decision_from_ota_channel_only',
  'create_investment_record_without_closed_operation_roi',
  'ctrip_ota_channel',
  "self::assertSame('record_roi_evidence'",
  "self::assertSame('record_execution_evidence'",
  "self::assertSame('use_next_day_input'",
]);

includesAll('tests/automation/revenue_ai_static.test.mjs', 'static helper tests prove homepage action contract', [
  'Revenue AI overview endpoint builder keeps query scope explicit',
  'platform=ctrip',
  "platform: ''",
  'Revenue AI action rows expose readonly price suggestion review queue',
  'Revenue AI action rows expose AI decision resolution plan as operator evidence checklist',
  'Revenue AI action rows expose readonly operation to investment precheck',
  'Revenue AI execution helpers keep process and effect review separate',
  'Revenue AI effect review rows expose next-day inputs without fake ROI',
  'buildRevenueAiResolutionPlanSummary',
  'buildRevenueAiInvestmentPrecheckSummary',
  "assert.equal(summary.decisionAllowed, false)",
  "assert.equal(summary.canCreateInvestmentDecision, false)",
  "assert.equal(summary.autoWriteOta, false)",
  "assert.equal(rows[0].reviewQueueItems[0].canApproveWithChanges, true)",
  "assert.equal(partialRows[0].inputActionKey, 'record_roi_evidence')",
]);

try {
  const context = { window: {} };
  vm.runInNewContext(read('public/revenue-ai-static.js'), context, {
    filename: 'public/revenue-ai-static.js',
  });
  const helpers = context.window.SUXI_REVENUE_AI_STATIC || {};
  const actionRows = helpers.buildRevenueAiActionRows({
    overview: {
      actions: [{
        key: 'pricing_review',
        title: '待人工审核调价建议',
        status: 'pending_review',
        review_queue: {
          status: 'pending_review',
          display: '待审核 1 / 已批准 1 / 已拒绝 0 / 已应用 0',
          pending_count: 1,
          pending_items: [{
            id: 11,
            room_type_id: 3,
            suggestion_type_label: '竞对跟价',
            status: 'pending_review',
            status_label: '待审核',
            suggestion_date: '2026-06-25',
            current_price: 280,
            current_price_display: '280元',
            suggested_price: 318,
            suggested_price_display: '318元',
            min_price: 220,
            min_price_display: '220元',
            expected_revpar_impact_display: '+12.5元',
            manual_review_required: true,
            auto_write_ota: false,
            can_review: true,
            action_entry: {
              allowed_endpoints: {
                review: '/api/revenue-ai/price-suggestions/11/review',
                execution_intent: '/api/revenue-ai/price-suggestions/11/execution-intent',
              },
              manual_actions: ['approve', 'approve_with_changes', 'reject'],
              forbidden_actions: ['apply_price', 'ota_write'],
            },
          }],
          recent_items: [{
            id: 12,
            room_type_id: 3,
            suggestion_type_label: '竞对跟价',
            status: 'approved',
            status_label: '已批准',
            current_price_display: '280元',
            suggested_price_display: '318元',
            min_price_display: '220元',
            manual_review_required: true,
            auto_write_ota: false,
            action_entry: {
              allowed_endpoint: '/api/revenue-ai/price-suggestions/12/execution-intent',
              allowed_endpoints: {
                execution_intent: '/api/revenue-ai/price-suggestions/12/execution-intent',
              },
              manual_actions: ['create_execution_intent'],
              forbidden_actions: ['apply_price', 'ota_write'],
            },
          }],
        },
      }],
    },
  });
  const pending = actionRows[0]?.reviewQueueItems?.[0] || {};
  const approved = actionRows[0]?.reviewQueueItems?.[1] || {};
  check(
    'public/revenue-ai-static.js',
    'runtime helper exposes approve/approve_with_changes/reject but no OTA write',
    pending.canApprove === true
      && pending.canApproveWithChanges === true
      && pending.canReject === true
      && pending.autoWriteOta === false
      && pending.allowedEndpoints.review === '/api/revenue-ai/price-suggestions/11/review'
      && pending.impactLine === '预计RevPAR影响 +12.5元',
    JSON.stringify(pending)
  );
  check(
    'public/revenue-ai-static.js',
    'runtime helper exposes approved suggestion only as execution intent',
    approved.canCreateExecutionIntent === true
      && approved.canApprove === false
      && approved.allowedEndpoint === '/api/revenue-ai/price-suggestions/12/execution-intent',
    JSON.stringify(approved)
  );

  const investmentSummary = helpers.buildRevenueAiInvestmentPrecheckSummary({
    overview: {
      operation_to_investment_handoff: {
        status: 'investment_precheck_blocked_by_operation_roi',
        target_service: 'InvestmentDecisionSupportService::buildOverviewFromEvidence',
        target_entry: '/api/investment-decision/overview',
        source_scope: 'ctrip_ota_channel_to_operation_roi',
        source_channels: ['ctrip'],
        upstream_operation_intake_status: 'operation_intake_blocked_by_manual_review',
        operation_roi_ready: 0,
        decision_allowed: false,
        can_create_investment_decision: false,
        blocked_reasons: ['closed_operating_roi_missing'],
        forbidden_actions: ['create_investment_decision_from_ota_channel_only'],
        investment_precheck_packet: {
          status: 'blocked_by_operation_roi',
          required_gate: 'operation_execution.roi_ready',
          missing_evidence_codes: ['operation_execution.roi_ready'],
          protected_boundary: 'investment_decision_requires_closed_operation_roi_not_ota_channel_only',
        },
      },
    },
  });
  check(
    'public/revenue-ai-static.js',
    'runtime helper exposes investment precheck as readonly blocked state',
    investmentSummary.visible === true
      && investmentSummary.targetEntry === '/api/investment-decision/overview'
      && investmentSummary.requiredGate === 'operation_execution.roi_ready'
      && investmentSummary.decisionAllowed === false
      && investmentSummary.canCreateInvestmentDecision === false
      && investmentSummary.autoWriteOta === false
      && investmentSummary.missingEvidenceCodes.includes('operation_execution.roi_ready')
      && investmentSummary.forbiddenActions.includes('create_investment_decision_from_ota_channel_only'),
    JSON.stringify(investmentSummary)
  );

  const effectRows = helpers.buildRevenueAiEffectReviewRows({
    overview: {
      hotel_id: 7,
      execution_summary: {
        business_date: '2026-06-25',
        effect_review: {
          input_status: 'partial',
          input_reason: 'operation_roi_missing',
          inputs: [{
            id: 72,
            intent_id: 72,
            hotel_id: 7,
            task_id: 92,
            input_status: 'partial',
            input_reason: 'operation_roi_missing',
            input_action_key: 'record_roi_evidence',
            input_action_label: '补录ROI证据',
            input_next_action: '补齐执行前后收入、成本或平台回执后再判断效果。',
            platform: 'meituan',
            platform_label: '美团',
            action_type: 'price_adjust',
            date_start: '2026-06-25',
            date_end: '2026-06-25',
            roi_status: 'data_gap',
            roi_display: '--',
            target_page: 'ops-track',
            target_action: 'review_effect',
            target_id: 92,
            target_kind: 'task',
          }],
        },
      },
    },
  });
  check(
    'public/revenue-ai-static.js',
    'runtime helper routes missing ROI to local evidence capture',
    effectRows[0]?.nextActionKey === 'record_roi_evidence'
      && effectRows[0]?.canOpenExecution === true
      && effectRows[0]?.roiDisplay === '--',
    JSON.stringify(effectRows[0])
  );
} catch (error) {
  check('public/revenue-ai-static.js', 'runtime helper validation failed', false, error.message);
}

const failures = checks.filter((item) => !item.ok);
if (failures.length) {
  console.error('Revenue AI closure contract failed:');
  for (const failure of failures) {
    console.error(`- ${failure.file}: ${failure.label} (${failure.detail})`);
  }
  process.exit(1);
}

console.log(`Revenue AI closure contract passed (${checks.length} checks).`);
