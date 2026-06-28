import test from 'node:test';
import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { spawnSync } from 'node:child_process';

const php = 'C:\\xampp\\php\\php.exe';

function extractJson(text) {
  const source = String(text || '');
  const start = source.indexOf('{');
  const end = source.lastIndexOf('}');
  assert(start >= 0 && end > start, `Expected JSON report output, got:\n${source.slice(0, 1000)}`);
  return JSON.parse(source.slice(start, end + 1));
}

test('Business-chain report keeps operator-skipped Meituan read-only and action-free', (t) => {
  if (!existsSync(php)) {
    t.skip(`${php} is not available`);
    return;
  }

  const result = spawnSync(php, [
    'scripts/report_business_chain_status.php',
    '--date=2026-06-28',
    '--skip-platform=meituan',
  ], {
    cwd: process.cwd(),
    encoding: 'utf8',
    windowsHide: true,
  });
  const output = result.stdout || result.stderr;
  const payload = extractJson(output);
  const sequence = payload.p0_execution_plan.operator_sequence.map((item) => `${item.platform}:${item.type}`);

  assert.equal(payload.status, 'incomplete');
  assert.deepEqual(payload.operator_skip_platforms, ['meituan']);
  assert(payload.p0_downstream_gate.blocking_missing_inputs.includes('p0_skipped_by_operator'));
  assert.equal(payload.downstream_reference_workflow.status, 'partial_reference_workflow_not_claimable');
  assert.equal(payload.downstream_reference_workflow.source_policy, 'use_target_date_ready_platform_rows_for_diagnosis_only');
  assert.deepEqual(payload.downstream_reference_workflow.evidence_scope.target_ready_platforms, ['ctrip']);
  assert.deepEqual(payload.downstream_reference_workflow.evidence_scope.operator_skip_platforms, ['meituan']);
  assert.equal(payload.downstream_reference_workflow.revenue_diagnosis.status, 'partial_reference_only');
  assert.deepEqual(payload.downstream_reference_workflow.revenue_diagnosis.source_channels, ['ctrip']);
  assert.equal(payload.downstream_reference_workflow.ai_advice_draft.status, 'draft_reference_only');
  assert.equal(payload.downstream_reference_workflow.ai_advice_draft.auto_write_ota, false);
  assert.equal(payload.downstream_reference_workflow.revenue_to_ai_handoff.status, 'handoff_reference_only');
  assert.equal(payload.downstream_reference_workflow.revenue_to_ai_handoff.source_scope, 'ctrip_target_date_ota_channel_reference');
  assert.equal(payload.downstream_reference_workflow.revenue_to_ai_handoff.manual_review_packet.review_mode, 'manual_review_only');
  assert.equal(payload.downstream_reference_workflow.revenue_to_ai_handoff.manual_review_packet.primary_action.auto_write_ota, false);
  assert.deepEqual(payload.downstream_reference_workflow.revenue_to_ai_handoff.source_platforms, ['ctrip']);
  assert.deepEqual(payload.downstream_reference_workflow.revenue_to_ai_handoff.target_blocked_platforms, ['meituan']);
  assert.equal(payload.downstream_reference_workflow.revenue_to_ai_handoff.ai_draft_status, 'draft_reference_only');
  assert.equal(payload.downstream_reference_workflow.revenue_to_ai_handoff.ai_action_count, 1);
  assert.equal(payload.downstream_reference_workflow.revenue_to_ai_handoff.can_auto_write_ota, false);
  assert.equal(payload.downstream_reference_workflow.revenue_to_ai_handoff.can_create_operation_execution, false);
  assert(payload.downstream_reference_workflow.revenue_to_ai_handoff.revenue_metric_keys.includes('ota_adr'));
  assert(payload.downstream_reference_workflow.revenue_to_ai_handoff.required_before_execution.includes('all_required_p0_platforms_ready'));
  assert(sequence.includes('ctrip:already_ready'));
  assert(sequence.includes('meituan:operator_skip'));
  assert.doesNotMatch(output, /\/api\/online-data\/capture-meituan-browser/);
  assert.doesNotMatch(output, /\/api\/online-data\/profile-login-trigger\/meituan/);
  assert.doesNotMatch(output, /\/api\/online-data\/data-sources\/18\/sync/);
});

test('Business-chain report can run Ctrip-only OTA to revenue to AI review path', (t) => {
  if (!existsSync(php)) {
    t.skip(`${php} is not available`);
    return;
  }

  const result = spawnSync(php, [
    'scripts/report_business_chain_status.php',
    '--date=2026-06-28',
    '--platform=ctrip',
  ], {
    cwd: process.cwd(),
    encoding: 'utf8',
    windowsHide: true,
  });
  const output = result.stdout || result.stderr;
  const payload = extractJson(output);
  const handoff = payload.downstream_reference_workflow.revenue_to_ai_handoff;
  const packet = handoff.manual_review_packet;
  const reviewContract = packet.ai_decision_review_contract;
  const resolutionPlan = reviewContract.resolution_plan;
  const operationHandoff = payload.downstream_reference_workflow.ai_to_operation_handoff;
  const operationIntake = operationHandoff.operation_intake_packet;
  const operationPreflight = operationIntake.operation_intake_preflight_contract;
  const investmentHandoff = payload.downstream_reference_workflow.operation_to_investment_handoff;
  const investmentPrecheck = investmentHandoff.investment_precheck_packet;
  const ctripActionQueue = payload.downstream_reference_workflow.ctrip_chain_action_queue;
  const ctripActionsByCode = Object.fromEntries(ctripActionQueue.items.map((item) => [item.code, item]));
  const action = payload.downstream_reference_workflow.ai_advice_draft.actions[0];

  assert.equal(result.status, 0);
  assert.deepEqual(payload.scope.platforms, ['ctrip']);
  assert.equal(payload.p0_downstream_gate.status, 'ready');
  assert.equal(payload.p0_execution_plan.status, 'passed');
  assert.equal(payload.focused_chain.status, 'scoped_ai_review_ready');
  assert.equal(payload.focused_chain.claim_allowed, true);
  assert.equal(payload.downstream_reference_workflow.status, 'scoped_workflow_ready_for_manual_review');
  assert.equal(payload.downstream_reference_workflow.source_policy, 'use_scoped_target_date_ota_rows_for_ai_review');
  assert.deepEqual(payload.downstream_reference_workflow.revenue_diagnosis.source_channels, ['ctrip']);
  assert.equal(handoff.status, 'handoff_ready_for_manual_review');
  assert.equal(handoff.source_scope, 'ctrip_target_date_ota_channel');
  assert.deepEqual(handoff.source_platforms, ['ctrip']);
  assert.deepEqual(handoff.target_blocked_platforms, []);
  assert.equal(handoff.ai_draft_status, 'ready_for_manual_review');
  assert.equal(handoff.can_auto_write_ota, false);
  assert.equal(handoff.can_create_operation_execution, false);
  assert.equal(packet.status, 'blocked_ready_for_manual_review');
  assert.equal(packet.review_mode, 'manual_review_only');
  assert.equal(packet.source_scope, 'ctrip_target_date_ota_channel');
  assert.deepEqual(packet.source_platforms, ['ctrip']);
  assert.equal(packet.primary_action.reason, 'ota_room_nights_zero');
  assert.equal(packet.primary_action.auto_write_ota, false);
  assert.equal(packet.primary_blocker.reason, 'available_room_nights_missing');
  assert(packet.blockers.some((item) => item.reason === 'ota_room_nights_zero'));
  assert(packet.revenue_metrics.some((item) => item.key === 'ota_room_nights' && item.value === 0));
  assert(packet.forbidden_actions.includes('auto_write_ota'));
  assert(packet.forbidden_actions.includes('create_operation_execution_without_human_approval'));
  assert.equal(reviewContract.status, 'blocked_by_review_inputs');
  assert.equal(reviewContract.review_entry, 'agent-center');
  assert.equal(reviewContract.persisted, false);
  assert.equal(reviewContract.source_scope, 'ctrip_target_date_ota_channel');
  assert.deepEqual(reviewContract.source_platforms, ['ctrip']);
  assert.equal(reviewContract.manual_review_packet_status, 'blocked_ready_for_manual_review');
  assert.equal(reviewContract.candidate_action_reason, 'ota_room_nights_zero');
  assert.equal(reviewContract.approval_allowed, false);
  assert.equal(reviewContract.operation_intake_allowed, false);
  assert.equal(reviewContract.auto_apply_ai_advice, false);
  assert.equal(reviewContract.required_input_count, 7);
  assert(reviewContract.required_input_items.some((item) => item.code === 'revpar_denominator' && item.input_type === 'revenue_metric_evidence' && item.evidence_code === 'available_room_nights_missing'));
  assert(reviewContract.required_input_items.some((item) => item.code === 'manual_review_workflow' && item.input_type === 'manual_review_process_gate' && item.evidence_code === 'manual_review_workflow_not_connected'));
  assert(reviewContract.required_input_items.some((item) => item.code === 'operation_feedback_input' && item.input_type === 'operation_feedback_evidence' && item.evidence_code === 'operation_execution_not_loaded'));
  assert(reviewContract.metric_snapshot.some((item) => item.key === 'ota_adr' && item.status === 'not_calculable' && item.reason === 'adr_denominator_zero'));
  assert(reviewContract.required_output_fields.includes('decision_status'));
  assert(reviewContract.required_output_fields.includes('evidence_links'));
  assert(reviewContract.allowed_decision_outputs.some((item) => item.code === 'request_revenue_metric_evidence' && item.allowed === true));
  assert(reviewContract.allowed_decision_outputs.some((item) => item.code === 'approve_ai_advice_for_operation_intake' && item.allowed === false));
  assert(reviewContract.forbidden_actions.includes('auto_apply_ai_advice'));
  assert.equal(reviewContract.protected_boundary, 'manual_review_requires_explicit_evidence_no_auto_apply');
  assert.equal(resolutionPlan.status, 'has_pending_evidence');
  assert.equal(resolutionPlan.source_scope, 'ctrip_target_date_ota_channel');
  assert.equal(resolutionPlan.metric_scope, 'ota_channel');
  assert.equal(resolutionPlan.item_count, 7);
  assert.equal(resolutionPlan.pending_count, 7);
  assert.equal(resolutionPlan.approval_allowed_after_resolution, false);
  assert.equal(resolutionPlan.post_resolution_gate, 'ai_decision_review_contract.approval_allowed');
  assert.equal(resolutionPlan.post_resolution_verifier, 'npm.cmd run verify:business-chain-report');
  assert(resolutionPlan.forbidden_actions.includes('fill_missing_evidence_with_defaults'));
  assert(resolutionPlan.forbidden_actions.includes('approve_ai_advice_without_resolving_inputs'));
  assert(resolutionPlan.items.some((item) => item.code === 'revpar_denominator' && item.resolution_action === 'provide_available_room_nights_or_mark_metric_unusable' && item.forbidden_shortcut === 'default_available_room_nights'));
  assert(resolutionPlan.items.some((item) => item.code === 'manual_review_workflow' && item.acceptance_check.includes('manual review record has reviewer')));
  assert(resolutionPlan.items.some((item) => item.code === 'ota_metrics' && item.resolution_action === 'verify_zero_room_nights_or_correct_ota_room_nights'));
  assert(resolutionPlan.items.some((item) => item.code === 'competitor_price' && item.forbidden_shortcut === 'invent_competitor_price'));
  assert(resolutionPlan.items.some((item) => item.code === 'operation_feedback_input' && item.unblocks === 'operation_feedback_review'));
  assert.equal(operationHandoff.status, 'operation_intake_blocked_by_manual_review');
  assert.equal(operationHandoff.persisted, false);
  assert.equal(operationHandoff.target_module, 'operation_execution');
  assert.equal(operationHandoff.target_entry, '/api/operation/execution-intents');
  assert.equal(operationHandoff.source_scope, 'ctrip_target_date_ota_channel');
  assert.equal(operationHandoff.target_date, '2026-06-28');
  assert.deepEqual(operationHandoff.source_platforms, ['ctrip']);
  assert.equal(operationHandoff.can_create_operation_execution, false);
  assert(operationHandoff.blocked_reasons.includes('available_room_nights_missing'));
  assert(operationHandoff.required_before_create.includes('resolve_manual_review_blockers'));
  assert(operationHandoff.forbidden_actions.includes('auto_create_operation_execution_intent'));
  assert(operationHandoff.forbidden_actions.includes('mark_operation_executed_without_evidence'));
  assert.equal(operationIntake.status, 'blocked_by_manual_review_packet');
  assert.equal(operationIntake.source_policy, 'read_only_candidate_from_ctrip_ota_revenue_ai_manual_review');
  assert.equal(operationIntake.candidate_source_module, 'ota_revenue_ai_manual_review');
  assert.equal(operationIntake.candidate_source_record_id, 0);
  assert.equal(operationIntake.candidate_source_record_policy, 'requires_persisted_manual_review_or_operator_selected_action');
  assert.deepEqual(operationIntake.candidate_platforms, ['ctrip']);
  assert.equal(operationIntake.candidate_object_type, 'ota_pricing');
  assert.equal(operationIntake.candidate_action_type, 'manual_review_revenue_pricing');
  assert.equal(operationIntake.candidate_status, 'blocked');
  assert.equal(operationIntake.candidate_blocked_reason, 'available_room_nights_missing');
  assert.equal(operationIntake.candidate_evidence.protected_boundary, 'ctrip_ota_channel_only_no_whole_hotel_truth');
  assert.equal(operationPreflight.status, 'blocked_by_ai_review_contract');
  assert.equal(operationPreflight.target_entry, '/api/operation/execution-intents');
  assert.equal(operationPreflight.service_contract, 'OperationManagementService::buildExecutionIntentPayload');
  assert.equal(operationPreflight.controller_action, 'OperationManagement/createExecutionIntent');
  assert.equal(operationPreflight.persisted, false);
  assert.equal(operationPreflight.dry_run_only, true);
  assert.equal(operationPreflight.would_call_create_endpoint, false);
  assert.equal(operationPreflight.create_allowed, false);
  assert.equal(operationPreflight.source_scope, 'ctrip_target_date_ota_channel');
  assert.deepEqual(operationPreflight.source_platforms, ['ctrip']);
  assert.equal(operationPreflight.review_contract_status, 'blocked_by_review_inputs');
  assert.equal(operationPreflight.approval_allowed, false);
  assert.equal(operationPreflight.operation_intake_allowed, false);
  assert.equal(operationPreflight.required_review_input_count, 7);
  assert.equal(operationPreflight.missing_required_field_count, 9);
  assert(operationPreflight.missing_required_fields.some((item) => item.field === 'approved_ai_advice' && item.reason === 'ai_decision_review_inputs_pending'));
  assert(operationPreflight.missing_required_fields.some((item) => item.field === 'target_value.target_price' && item.reason === 'approved_target_price_missing'));
  assert(operationPreflight.blocked_reasons.includes('available_room_nights_missing'));
  assert(operationPreflight.blocked_reasons.includes('operation_intake_gate_closed'));
  assert.equal(operationPreflight.projected_payload_template.source_module, 'ota_revenue_ai_manual_review');
  assert.equal(operationPreflight.projected_payload_template.object_type, 'price');
  assert.equal(operationPreflight.projected_payload_template.action_type, 'price_adjust');
  assert.equal(operationPreflight.projected_payload_template.platform, 'ctrip');
  assert.equal(operationPreflight.projected_payload_template.date_start, '2026-06-28');
  assert(operationPreflight.required_before_create.includes('operator_confirmed_price_target'));
  assert(operationPreflight.forbidden_actions.includes('call_create_execution_intent_before_ai_review_approval'));
  assert.equal(operationPreflight.protected_boundary, 'operation_intake_requires_approved_ai_review_and_price_target_no_auto_create');
  assert.equal(investmentHandoff.status, 'investment_precheck_blocked_by_operation_roi');
  assert.equal(investmentHandoff.persisted, false);
  assert.equal(investmentHandoff.target_module, 'investment_decision');
  assert.equal(investmentHandoff.target_entry, '/api/investment-decision/overview');
  assert.equal(investmentHandoff.source_scope, 'ctrip_target_date_ota_channel');
  assert.deepEqual(investmentHandoff.source_platforms, ['ctrip']);
  assert.equal(investmentHandoff.upstream_operation_intake_status, 'operation_intake_blocked_by_manual_review');
  assert.equal(investmentHandoff.operation_roi_ready, 0);
  assert.equal(investmentHandoff.operating_gate_status, 'not_ready');
  assert.equal(investmentHandoff.business_closure_chain_status, 'not_closed');
  assert.equal(investmentHandoff.decision_allowed, false);
  assert.equal(investmentHandoff.can_create_investment_decision, false);
  assert(investmentHandoff.blocked_reasons.includes('closed_operating_roi_missing'));
  assert(investmentHandoff.blocked_reasons.includes('operation_process_closure_missing'));
  assert(investmentHandoff.blocked_reasons.includes('operation_intake_not_approved'));
  assert(investmentHandoff.required_before_investment.includes('operation_execution.roi_ready'));
  assert(investmentHandoff.required_before_investment.includes('decision_record.readiness_ready'));
  assert(investmentHandoff.forbidden_actions.includes('create_investment_decision_from_ota_channel_only'));
  assert(investmentHandoff.forbidden_actions.includes('create_investment_record_without_closed_operation_roi'));
  assert.equal(investmentPrecheck.status, 'blocked_by_operation_roi');
  assert.equal(investmentPrecheck.source_policy, 'read_only_precheck_from_closed_operation_gate');
  assert.equal(investmentPrecheck.required_gate, 'operation_execution.roi_ready');
  assert.equal(investmentPrecheck.protected_boundary, 'investment_decision_requires_closed_operation_roi_not_ota_channel_only');
  assert.equal(ctripActionQueue.status, 'has_blocking_actions');
  assert.equal(ctripActionQueue.item_count, 5);
  assert.equal(ctripActionQueue.blocking_count, 5);
  assert.equal(ctripActionQueue.source_scope, 'ctrip_target_date_ota_channel');
  assert.deepEqual(ctripActionQueue.source_platforms, ['ctrip']);
  assert.equal(ctripActionQueue.protected_boundary, 'ctrip_ota_channel_action_queue_no_auto_write_no_whole_hotel_truth');
  assert(ctripActionQueue.forbidden_actions.includes('auto_write_ota'));
  assert(ctripActionQueue.forbidden_actions.includes('auto_create_operation_execution_intent'));
  assert(ctripActionQueue.forbidden_actions.includes('claim_operation_roi_ready'));
  assert(ctripActionQueue.forbidden_actions.includes('claim_investment_decision_allowed'));
  assert.equal(ctripActionsByCode.resolve_revenue_metric_gap.stage, 'revenue_analysis');
  assert.equal(ctripActionsByCode.resolve_revenue_metric_gap.evidence_code, 'available_room_nights_missing');
  assert.equal(ctripActionsByCode.approve_ai_manual_review.stage, 'ai_decision');
  assert.equal(ctripActionsByCode.approve_ai_manual_review.evidence_code, 'manual_review_workflow_not_connected');
  assert.equal(ctripActionsByCode.create_operation_intent_after_review.target_entry, '/api/operation/execution-intents');
  assert.equal(ctripActionsByCode.create_operation_intent_after_review.evidence_code, 'operation_intake_not_approved');
  assert.equal(ctripActionsByCode.attach_operation_execution_evidence.target_entry, 'ops-track');
  assert.equal(ctripActionsByCode.attach_operation_execution_evidence.evidence_code, 'operation_execution.roi_ready');
  assert.equal(ctripActionsByCode.keep_investment_blocked_until_roi.target_entry, '/api/investment-decision/overview');
  assert.equal(ctripActionsByCode.keep_investment_blocked_until_roi.evidence_code, 'operation_execution.roi_ready');
  assert.equal(action.reason, 'ota_room_nights_zero');
  assert(action.blocking_reasons.includes('ota_room_nights_zero'));
  assert(!action.blocking_reasons.includes('online_daily_data_empty'));
  assert.doesNotMatch(output, /meituan/);
});

test('Business-chain markdown exposes Ctrip manual review packet', (t) => {
  if (!existsSync(php)) {
    t.skip(`${php} is not available`);
    return;
  }

  const result = spawnSync(php, [
    'scripts/report_business_chain_status.php',
    '--date=2026-06-28',
    '--platform=ctrip',
    '--format=markdown',
  ], {
    cwd: process.cwd(),
    encoding: 'utf8',
    windowsHide: true,
  });
  const output = result.stdout || result.stderr;

  assert.equal(result.status, 0);
  assert.match(output, /manual_review_packet: `blocked_ready_for_manual_review`/);
  assert.match(output, /mode=`manual_review_only`/);
  assert.match(output, /primary_action=`ota_room_nights_zero`/);
  assert.match(output, /primary_blocker=`available_room_nights_missing`/);
  assert.match(output, /manual_review_next_blockers: `available_room_nights_missing/);
  assert.match(output, /manual_review_forbidden_actions: `auto_write_ota/);
  assert.match(output, /create_operation_execution_without_human_approval/);
  assert.match(output, /ai_decision_review_contract: `blocked_by_review_inputs`, approval_allowed=`false`, operation_intake_allowed=`false`, required_inputs=`7`/);
  assert.match(output, /ai_decision_required_inputs: `revpar_denominator:available_room_nights_missing,floor_price:floor_price_missing,manual_review_workflow:manual_review_workflow_not_connected/);
  assert.match(output, /ai_decision_allowed_outputs: `request_revenue_metric_evidence:allowed,record_manual_review_note:allowed,reject_ai_advice:allowed,approve_ai_advice_for_operation_intake:blocked`/);
  assert.match(output, /ai_decision_resolution_plan: `has_pending_evidence`, items=`7`, pending=`7`, gate=`ai_decision_review_contract\.approval_allowed`/);
  assert.match(output, /ai_decision_resolution_items: `revpar_denominator:provide_available_room_nights_or_mark_metric_unusable,floor_price:provide_floor_price_or_min_rate_guard,manual_review_workflow:persist_or_attach_manual_review_record/);
  assert.match(output, /ai_to_operation_handoff: `operation_intake_blocked_by_manual_review`/);
  assert.match(output, /target=`\/api\/operation\/execution-intents`/);
  assert.match(output, /persisted=`false`/);
  assert.match(output, /can_create=`false`/);
  assert.match(output, /operation_intake_packet: `blocked_by_manual_review_packet`/);
  assert.match(output, /source_module=`ota_revenue_ai_manual_review`/);
  assert.match(output, /object_type=`ota_pricing`/);
  assert.match(output, /blocked_reason=`available_room_nights_missing`/);
  assert.match(output, /operation_intake_preflight_contract: `blocked_by_ai_review_contract`, create_allowed=`false`, would_call_create=`false`, missing_fields=`9`/);
  assert.match(output, /operation_intake_missing_fields: `approved_ai_advice:ai_decision_review_inputs_pending,operation_intake_allowed:operation_intake_gate_closed,hotel_id:operator_selected_hotel_missing/);
  assert.match(output, /operation_to_investment_handoff: `investment_precheck_blocked_by_operation_roi`/);
  assert.match(output, /target=`\/api\/investment-decision\/overview`/);
  assert.match(output, /decision_allowed=`false`/);
  assert.match(output, /investment_precheck_packet: `blocked_by_operation_roi`/);
  assert.match(output, /required_gate=`operation_execution\.roi_ready`/);
  assert.match(output, /operating_gate=`not_ready`/);
  assert.match(output, /ctrip_chain_action_queue: `has_blocking_actions`, items=`5`, blocking=`5`/);
  assert.match(output, /ctrip_chain_next_action: action=`resolve_revenue_metric_gap`, stage=`revenue_analysis`, evidence=`available_room_nights_missing`/);
  assert.match(output, /ctrip_chain_next_action: action=`approve_ai_manual_review`, stage=`ai_decision`, evidence=`manual_review_workflow_not_connected`/);
  assert.match(output, /ctrip_chain_next_action: action=`create_operation_intent_after_review`, stage=`operation_management`, evidence=`operation_intake_not_approved`, target=`\/api\/operation\/execution-intents`/);
  assert.match(output, /ctrip_chain_next_action: action=`keep_investment_blocked_until_roi`, stage=`investment_decision`, evidence=`operation_execution\.roi_ready`, target=`\/api\/investment-decision\/overview`/);
  assert.match(output, /ctrip_chain_forbidden_actions: `auto_write_ota,auto_create_operation_execution_intent,claim_ai_decision_final,claim_operation_roi_ready,claim_investment_decision_allowed,promote_ota_scope_to_whole_hotel_truth`/);
  assert.doesNotMatch(output, /meituan/);
});
