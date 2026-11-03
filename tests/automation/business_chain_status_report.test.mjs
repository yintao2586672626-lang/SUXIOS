import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { readSourceAggregate } from '../../scripts/lib/source_aggregate.mjs';
import { spawnSync } from 'node:child_process';
import {
  buildPhpBinaryCandidates,
  isBusinessChainRuntimeRequired,
  resolvePhpBinary,
} from '../../scripts/run_node_automation_tests.mjs';

const phpCandidates = buildPhpBinaryCandidates();
const php = resolvePhpBinary(phpCandidates) || phpCandidates[0] || 'php';

const runtimeRequired = isBusinessChainRuntimeRequired();

function extractJson(text) {
  const source = String(text || '');
  const start = source.indexOf('{');
  const end = source.lastIndexOf('}');
  assert(start >= 0 && end > start, `Expected JSON report output, got:\n${source.slice(0, 1000)}`);
  return JSON.parse(source.slice(start, end + 1));
}

function tryExtractJson(text) {
  try {
    return extractJson(text);
  } catch {
    return null;
  }
}

function failOrSkipRuntime(t, reason, required = runtimeRequired) {
  if (required) {
    assert.fail(`${reason}; business-chain runtime assertions are required when CI=true or SUXI_REQUIRE_BUSINESS_CHAIN_RUNTIME=1`);
  }
  t.skip(reason);
  return true;
}

function skipWhenRuntimeUnavailable(t, result, output, required = runtimeRequired) {
  const spawnErrorCode = result.error?.code;
  if (spawnErrorCode === 'ENOENT') {
    return failOrSkipRuntime(t, `PHP executable is unavailable: ${php}`, required);
  }
  if (spawnErrorCode === 'EPERM') {
    return failOrSkipRuntime(
      t,
      'process spawning is unavailable in this sandbox; runtime business-chain assertions were not evaluated',
      required,
    );
  }
  if (result.error) {
    assert.fail(`business-chain runtime process failed to start: ${result.error.message || spawnErrorCode || 'unknown error'}`);
  }
  const payload = tryExtractJson(output);
  if (result.status !== 1 || payload?.error_code !== 'database_unavailable') {
    return false;
  }
  assert.equal(payload.status, 'blocked');
  assert.equal(payload.claim_allowed, false);
  assert.equal(payload.database_ready, false);
  assert.equal(payload.runtime_data_ready, false);
  assert.equal(payload.business_loop_ready, false);
  return failOrSkipRuntime(
    t,
    'project database is unavailable; runtime business-chain assertions were not evaluated',
    required,
  );
}

function assertReadOnlyWorkflow(workflow) {
  const handoff = workflow.revenue_to_ai_handoff;
  const packet = handoff.manual_review_packet;
  const review = packet.ai_decision_review_contract;
  const operation = workflow.ai_to_operation_handoff;
  const preflight = operation.operation_intake_packet.operation_intake_preflight_contract;
  assert.equal(workflow.claim_allowed, false);
  assert.equal(workflow.ai_advice_draft.auto_write_ota, false);
  assert.equal(handoff.ai_action_count, handoff.ai_actions.length);
  assert.equal(handoff.ai_action_count, workflow.ai_advice_draft.action_count);
  assert.equal(packet.primary_action.auto_write_ota, false);
  assert.equal(handoff.can_auto_write_ota, false);
  assert.equal(handoff.can_create_operation_execution, false);
  assert.equal(review.persisted, false);
  assert.equal(review.operation_intake_allowed, false);
  assert.equal(review.auto_apply_ai_advice, false);
  assert.equal(operation.persisted, false);
  assert.equal(operation.can_create_operation_execution, false);
  assert.equal(preflight.persisted, false);
  assert.equal(preflight.dry_run_only, true);
  assert.equal(preflight.would_call_create_endpoint, false);
  assert.equal(preflight.create_allowed, false);
  assert.equal(workflow.operation_execution_draft.persisted, false);
  assert.equal(workflow.operation_execution_draft.can_create_operation_execution, false);
  assert(packet.forbidden_actions.includes('create_operation_execution_without_human_approval'));
  assert(review.forbidden_actions.includes('auto_write_ota'));
  assert(preflight.forbidden_actions.includes('call_create_execution_intent_before_ai_review_approval'));
}

function assertBlockedDiagnosis(workflow, stages) {
  assertReadOnlyWorkflow(workflow);
  const diagnosis = workflow.revenue_diagnosis;
  const handoff = workflow.revenue_to_ai_handoff;
  const packet = handoff.manual_review_packet;
  const review = packet.ai_decision_review_contract;
  assert.equal(diagnosis.status, 'blocked');
  assert.deepEqual(diagnosis.source_channels, []);
  assert.notEqual(diagnosis.reason, '');
  for (const metric of Object.values(diagnosis.metrics)) {
    assert.equal(metric.value, null);
    assert.equal(metric.status, 'blocked');
    assert.equal(metric.reason, diagnosis.reason);
  }
  assert.equal(workflow.ai_advice_draft.action_count, 0);
  assert.deepEqual(workflow.ai_advice_draft.actions, []);
  assert.equal(handoff.status, 'handoff_blocked');
  assert.equal(handoff.source_scope, 'ota_channel_blocked_unverified');
  assert.deepEqual(handoff.source_platforms, []);
  assert.equal(handoff.ai_action_count, 0);
  assert.deepEqual(handoff.ai_actions, []);
  assert.deepEqual(handoff.revenue_metric_keys, []);
  assert.equal(packet.status, 'blocked_by_diagnosis_scope');
  assert.equal(packet.primary_action.key, '');
  assert.equal(packet.primary_blocker.key, 'diagnosis_scope');
  assert.equal(packet.primary_blocker.reason, diagnosis.reason);
  assert.equal(review.status, 'blocked_by_review_inputs');
  assert.equal(review.approval_allowed, false);
  assert.equal(review.resolution_plan.approval_allowed_after_resolution, false);
  assert.equal(review.allowed_decision_outputs.find((item) => item.code === 'approve_ai_advice_for_operation_intake').allowed, false);
  for (const decision of review.allowed_decision_outputs.filter((item) => item.code.startsWith('approve_'))) {
    assert.equal(decision.allowed, false);
  }
  assert(review.required_input_items.some((item) => item.code === 'diagnosis_scope' && item.evidence_code === diagnosis.reason));
  assert.equal(stages[0].status, 'blocked_by_diagnosis_scope');
  for (const stage of stages) assert.equal(stage.claim_allowed, false);
}

function syntheticDiagnosisCases(t) {
  // Load the actual report and revenue calculator, with every database call trapped.
  // These fixed OTA rows are test inputs; no local or external business data is written.
  const code = String.raw`
namespace think\facade {
    class Db {
        public static array $calls = [];
        public static function __callStatic(string $method, array $args): mixed {
            self::$calls[] = $method;
            throw new \RuntimeException('Synthetic report must not access the database: ' . $method);
        }
    }
}
namespace {
    $loader = require getcwd() . '/vendor/autoload.php';
    $loader->setPsr4('app\\', getcwd() . '/app');
    if (realpath((new \ReflectionClass(\app\service\RevenueAiOverviewService::class))->getFileName())
        !== realpath(getcwd() . '/app/service/RevenueAiOverviewService.php')) {
        throw new \RuntimeException('Synthetic report loaded a different worktree');
    }
    require getcwd() . '/scripts/report_business_chain_status.php';
    $row = static function (string $source): array {
        return ['source' => $source, 'target_date' => '2026-06-28', 'system_hotel_id' => 80,
            'target_status' => 'ready', 'target_dataset_status' => 'ready',
            'source_date_quality' => ['contract_version' => 'ota-source-date-quality-v1',
                'source' => $source, 'target_date' => '2026-06-28', 'system_hotel_id' => 80,
                'metric_scope' => 'ota_channel', 'status' => 'ready', 'quality_status' => 'available', 'claim_allowed' => true]];
    };
    $dataset = static function (string $source, string $date = '2026-06-28', int $hotel = 80): array {
        return ['status' => 'ready', 'dim_hotel' => [['hotel_key' => 'system:' . $hotel, 'tenant_id' => 10]],
            'dim_platform' => [['platform_key' => $source]], 'fact_ota_daily' => [[
                'date_key' => $date, 'hotel_key' => 'system:' . $hotel, 'tenant_id' => 10, 'platform_key' => $source,
                'data_type' => 'business', 'metric_scope' => 'ota_channel', 'calculation_basis' => 'ota_daily_standard_fact',
                'room_revenue' => 100, 'revenue' => 100, 'gross_revenue' => 100, 'net_revenue' => 100,
                'room_nights' => 1, 'order_count' => 1]],
            'data_quality' => ['input_rows' => 1, 'accepted_rows' => 1, 'rejected_rows' => []]];
    };
    $cases = [];
    foreach (['ready', 'p0_blocked', 'no_action', 'missing_hotel', 'missing_dataset', 'wrong_date', 'wrong_hotel', 'wrong_platform', 'operator_skip'] as $name) {
        $requested = ['target_date' => '2026-06-28', 'system_hotel_id' => $name === 'missing_hotel' ? null : 80,
            'platforms' => $name === 'operator_skip' ? ['ctrip', 'meituan'] : ['ctrip']];
        $rows = $name === 'operator_skip' ? [$row('ctrip'), $row('meituan')] : [$row('ctrip')];
        if ($name === 'missing_hotel') $rows[0]['system_hotel_id'] = null;
        $scope = business_chain_downstream_reference_scope($rows, $name === 'operator_skip' ? ['meituan'] : [], $requested);
        $current = match ($name) {
            'missing_dataset' => [],
            'wrong_date' => ['ctrip' => $dataset('ctrip', '2026-06-27')],
            'wrong_hotel' => ['ctrip' => $dataset('ctrip', '2026-06-28', 81)],
            'wrong_platform' => ['ctrip' => $dataset('meituan')],
            default => ['ctrip' => $dataset('ctrip'), 'meituan' => $dataset('meituan')],
        };
        $input = business_chain_diagnosis_input($scope, $current, [], false);
        $scope['diagnosis_input'] = $input['scope'];
        $revenue = business_chain_build_revenue_diagnosis($input);
        if ($name === 'no_action') $revenue['actions'] = [];
        $p0Ready = !in_array($name, ['p0_blocked', 'operator_skip'], true);
        $gate = ['status' => $p0Ready ? 'ready' : 'blocked_by_p0_ota_gate'];
        $workflow = business_chain_downstream_reference_workflow($revenue, [], false, $scope, $p0Ready);
        $stages = business_chain_stage_rows($input['dataset'], $revenue, [], false, $gate, $workflow['revenue_diagnosis']);
        $report = ['status' => 'incomplete', 'claim_allowed' => false, 'scope' => $requested,
            'downstream_reference_workflow' => $workflow, 'stages' => $stages];
        $cases[$name] = ['input' => $input, 'workflow' => $workflow, 'stages' => $stages,
            'focused' => business_chain_focused_ota_revenue_ai_chain($gate, $workflow, $requested['platforms']),
            'markdown' => business_chain_markdown($report)];
    }
    echo json_encode(['cases' => $cases, 'database_calls' => \think\facade\Db::$calls]);
}`;
  const result = spawnSync(php, ['-r', code], { cwd: process.cwd(), encoding: 'utf8', windowsHide: true });
  const output = String(result.stdout || result.stderr || '');
  if (skipWhenRuntimeUnavailable(t, result, output)) return null;
  assert.equal(result.status, 0, output.slice(0, 2000));
  const payload = extractJson(output);
  assert.deepEqual(payload.database_calls, []);
  return payload.cases;
}

test('Synthetic diagnosis keeps same hotel/date/platform evidence and manual approval barriers', (t) => {
  const cases = syntheticDiagnosisCases(t);
  if (!cases) return;
  for (const name of ['ready', 'p0_blocked', 'operator_skip']) {
    const item = cases[name];
    const workflow = item.workflow;
    const handoff = workflow.revenue_to_ai_handoff;
    const packet = handoff.manual_review_packet;
    assertReadOnlyWorkflow(workflow);
    assert.deepEqual(item.input.scope.platforms, ['ctrip']);
    assert.deepEqual(Object.keys(item.input.channel_datasets), ['ctrip']);
    assert.equal(item.input.dataset.fact_ota_daily[0].tenant_id, 10);
    assert.equal(item.input.scope.business_date, '2026-06-28');
    assert.equal(item.input.scope.system_hotel_id, 80);
    assert.deepEqual(workflow.revenue_diagnosis.source_channels, ['ctrip']);
    assert.equal(workflow.revenue_diagnosis.metrics.ota_room_revenue.value, 100);
    assert.equal(workflow.revenue_diagnosis.metrics.ota_room_nights.value, 1);
    assert.equal(workflow.revenue_diagnosis.metrics.ota_adr.value, 100);
    assert.equal(handoff.ai_action_count, 1);
    assert.deepEqual(handoff.source_platforms, ['ctrip']);
    assert.equal(packet.status, 'blocked_ready_for_manual_review');
    assert.equal(packet.primary_action.reason, 'metric_truth_unverified');
    assert.equal(workflow.revenue_diagnosis.metrics.ota_adr.status, 'unverified');
    assert.equal(packet.primary_blocker.reason, 'available_room_nights_missing');
    assert.equal(packet.ai_decision_review_contract.approval_allowed, false);
    assert(packet.ai_decision_review_contract.required_input_items.some((item) => item.code === 'revpar_denominator'));
    assert(packet.ai_decision_review_contract.required_input_items.some((item) => item.code === 'manual_review_workflow'));
    assert.match(item.markdown, /manual_review_packet: `blocked_ready_for_manual_review`/);
    if (name === 'ready') {
      assert.equal(handoff.status, 'handoff_ready_for_manual_review');
      assert.equal(item.focused.status, 'scoped_ai_review_ready');
      assert.equal(item.focused.claim_allowed, true);
    } else if (name === 'operator_skip') {
      assert.equal(handoff.status, 'handoff_reference_only');
      assert.equal(workflow.revenue_diagnosis.status, 'partial_reference_only');
      assert.deepEqual(handoff.operator_skip_platforms, ['meituan']);
      assert.deepEqual(handoff.target_blocked_platforms, ['meituan']);
      assert.equal(item.focused.claim_allowed, false);
    } else {
      assert.equal(handoff.status, 'handoff_blocked');
      assert.equal(item.focused.claim_allowed, false);
      assert(handoff.required_before_execution.includes('all_required_p0_platforms_ready'));
      assert.equal(item.stages[0].status, 'blocked_by_p0_ota_gate');
    }
  }
});

test('Synthetic missing or different diagnosis scope produces no actions, claims, approval or writes', (t) => {
  const cases = syntheticDiagnosisCases(t);
  if (!cases) return;
  for (const name of ['missing_hotel', 'missing_dataset', 'wrong_date', 'wrong_hotel', 'wrong_platform']) {
    const item = cases[name];
    assertBlockedDiagnosis(item.workflow, item.stages);
    assert.equal(item.focused.claim_allowed, false);
    assert.match(item.markdown, /manual_review_packet: `blocked_by_diagnosis_scope`/);
    assert.match(item.markdown, /approval_allowed=`false`, operation_intake_allowed=`false`/);
    assert.match(item.markdown, /ai_advice_draft: `requires_p0`, action_count=`0`/);
    assert.match(item.markdown, /can_create=`false`/);
    assert.match(item.markdown, /would_call_create=`false`/);
  }
  const noAction = cases.no_action;
  assertReadOnlyWorkflow(noAction.workflow);
  assert.deepEqual(noAction.workflow.revenue_diagnosis.source_channels, ['ctrip']);
  assert.equal(noAction.workflow.revenue_to_ai_handoff.ai_action_count, 0);
  assert.equal(noAction.workflow.revenue_to_ai_handoff.status, 'handoff_blocked');
  assert.equal(noAction.focused.claim_allowed, false);
  const packet = noAction.workflow.revenue_to_ai_handoff.manual_review_packet;
  assert.equal(packet.status, 'blocked_by_diagnosis_scope');
  assert.equal(packet.ai_decision_review_contract.approval_allowed, false);
  assert.equal(noAction.stages[2].claim_allowed, false);
  assert.equal(noAction.stages[3].claim_allowed, false);
  for (const decision of packet.ai_decision_review_contract.allowed_decision_outputs.filter((item) => item.code.startsWith('approve_'))) {
    assert.equal(decision.allowed, false);
  }
  assert.equal(packet.ai_decision_review_contract.resolution_plan.approval_allowed_after_resolution, false);
  assert.equal(packet.primary_blocker.reason, 'same_scope_diagnosis_action_unavailable');
});

test('Business-chain runtime requirement recognizes CI and explicit enforcement', () => {
  assert.equal(isBusinessChainRuntimeRequired({}), false);
  assert.equal(isBusinessChainRuntimeRequired({ CI: 'true' }), true);
  assert.equal(isBusinessChainRuntimeRequired({ CI: '1' }), true);
  assert.equal(isBusinessChainRuntimeRequired({ SUXI_REQUIRE_BUSINESS_CHAIN_RUNTIME: '1' }), true);
});

test('Business-chain runtime unavailability skips locally but fails closed when required', () => {
  const localSkipReasons = [];
  const localContext = { skip: (reason) => localSkipReasons.push(reason) };
  const databaseUnavailable = JSON.stringify({
    status: 'blocked',
    error_code: 'database_unavailable',
    claim_allowed: false,
    database_ready: false,
    runtime_data_ready: false,
    business_loop_ready: false,
  });

  assert.equal(skipWhenRuntimeUnavailable(localContext, { error: { code: 'ENOENT' } }, '', false), true);
  assert.equal(skipWhenRuntimeUnavailable(localContext, { error: { code: 'EPERM' } }, '', false), true);
  assert.equal(skipWhenRuntimeUnavailable(localContext, { status: 1 }, databaseUnavailable, false), true);
  assert.equal(localSkipReasons.length, 3);

  assert.throws(
    () => skipWhenRuntimeUnavailable({ skip() {} }, { error: { code: 'ENOENT' } }, '', true),
    /business-chain runtime assertions are required/,
  );
  assert.throws(
    () => skipWhenRuntimeUnavailable({ skip() {} }, { error: { code: 'EPERM' } }, '', true),
    /business-chain runtime assertions are required/,
  );
  assert.throws(
    () => skipWhenRuntimeUnavailable({ skip() {} }, { status: 1 }, databaseUnavailable, true),
    /business-chain runtime assertions are required/,
  );
});

test('Business-chain source rows never label accepted non-traffic evidence as ready', (t) => {
  const source = readSourceAggregate('scripts/report_business_chain_status.php');
  assert.match(source, /function business_chain_source_evidence_status/);
  assert.match(source, /reference_only_non_traffic/);

  const result = spawnSync(php, ['scripts/report_business_chain_status.php'], {
    cwd: process.cwd(),
    encoding: 'utf8',
    windowsHide: true,
  });
  const output = String(result.stdout || result.stderr || '');
  if (skipWhenRuntimeUnavailable(t, result, output)) return;
  assert.ok([0, 2].includes(result.status), `unexpected report exit ${result.status}: ${output.slice(0, 1000)}`);
  const payload = extractJson(output);
  for (const row of payload.source_rows) {
    const accepted = Number(row.target_counts?.accepted || 0);
    const traffic = Number(row.target_counts?.traffic || 0);
    const expected = accepted <= 0 ? 'empty' : (traffic > 0 ? 'ready' : 'reference_only_non_traffic');
    assert.equal(row.target_status, expected, JSON.stringify(row));
  }
});

test('Business-chain report keeps operator-skipped Meituan read-only and action-free', (t) => {
  const result = spawnSync(php, [
    'scripts/report_business_chain_status.php',
    '--date=2026-06-28',
    '--skip-platform=meituan',
  ], {
    cwd: process.cwd(),
    encoding: 'utf8',
    windowsHide: true,
  });
  const output = String(result.stdout || result.stderr || '');
  if (skipWhenRuntimeUnavailable(t, result, output)) return;
  assert.ok([0, 2].includes(result.status), `unexpected report exit ${result.status}: ${output.slice(0, 1000)}`);
  const payload = extractJson(output);
  const sequence = payload.p0_execution_plan.operator_sequence.map((item) => `${item.platform}:${item.type}`);
  const ctripSummary = payload.p0_execution_plan.platform_summaries
    .find((item) => item.platform === 'ctrip');
  const readyCtripHotelIds = (ctripSummary?.next_steps || [])
    .filter((item) => item.hotel_ready === true)
    .map((item) => Number(item.system_hotel_id))
    .sort((left, right) => left - right);
  const readyCtripSequenceHotelIds = payload.p0_execution_plan.operator_sequence
    .filter((item) => item.platform === 'ctrip' && item.type === 'already_ready')
    .map((item) => Number(item.system_hotel_id))
    .sort((left, right) => left - right);
  const workflow = payload.downstream_reference_workflow;
  const targetReadyPlatforms = workflow.evidence_scope.target_ready_platforms;
  const hasTargetDateCtrip = targetReadyPlatforms.includes('ctrip');

  assert.equal(payload.status, 'incomplete');
  assert.deepEqual(payload.operator_skip_platforms, ['meituan']);
  assert(payload.p0_downstream_gate.blocking_missing_inputs.includes('p0_skipped_by_operator'));
  assert.deepEqual(workflow.evidence_scope.operator_skip_platforms, ['meituan']);
  assert.equal(workflow.ai_advice_draft.auto_write_ota, false);
  assert.equal(workflow.revenue_to_ai_handoff.manual_review_packet.review_mode, 'manual_review_only');
  assert.equal(workflow.revenue_to_ai_handoff.manual_review_packet.primary_action.auto_write_ota, false);
  assertReadOnlyWorkflow(workflow);
  // This invocation intentionally has no hotel scope; do not invent a usable diagnosis.
  assert.equal(workflow.revenue_to_ai_handoff.ai_action_count, 0);
  assert.equal(workflow.revenue_to_ai_handoff.can_auto_write_ota, false);
  assert.equal(workflow.revenue_to_ai_handoff.can_create_operation_execution, false);
  assert.deepEqual(workflow.revenue_to_ai_handoff.revenue_metric_keys, []);
  assert.equal(workflow.revenue_to_ai_handoff.manual_review_packet.ai_decision_review_contract.approval_allowed, false);
  assert(workflow.revenue_to_ai_handoff.required_before_execution.includes('all_required_p0_platforms_ready'));
  assert.deepEqual(
    readyCtripSequenceHotelIds,
    readyCtripHotelIds,
    'already_ready must be emitted only for hotel scopes with ready P0 evidence',
  );

  if (hasTargetDateCtrip) {
    assert.equal(workflow.revenue_to_ai_handoff.ai_action_count, 1);
    assert(workflow.revenue_to_ai_handoff.revenue_metric_keys.includes('ota_adr'));
    assert.equal(workflow.status, 'partial_reference_workflow_not_claimable');
    assert.equal(workflow.source_policy, 'use_target_date_ready_platform_rows_for_diagnosis_only');
    assert.deepEqual(targetReadyPlatforms, ['ctrip']);
    assert.equal(workflow.revenue_diagnosis.status, 'partial_reference_only');
    assert.deepEqual(workflow.revenue_diagnosis.source_channels, ['ctrip']);
    assert.equal(workflow.ai_advice_draft.status, 'draft_reference_only');
    assert.equal(workflow.revenue_to_ai_handoff.status, 'handoff_reference_only');
    assert.equal(workflow.revenue_to_ai_handoff.source_scope, 'ctrip_target_date_ota_channel_reference');
    assert.deepEqual(workflow.revenue_to_ai_handoff.source_platforms, ['ctrip']);
    assert.deepEqual(workflow.revenue_to_ai_handoff.target_blocked_platforms, ['meituan']);
    assert.equal(workflow.revenue_to_ai_handoff.ai_draft_status, 'draft_reference_only');
    assert(sequence.includes('meituan:operator_skip'));
  } else {
    assert.equal(workflow.status, 'p0_required');
    assert.equal(workflow.source_policy, 'requires_target_date_p0_ota_rows');
    assert.deepEqual(targetReadyPlatforms, []);
    assert.deepEqual([...workflow.evidence_scope.target_blocked_platforms].sort(), ['ctrip', 'meituan']);
    assert.notEqual(workflow.revenue_diagnosis.status, 'partial_reference_only');
    assert.equal(workflow.ai_advice_draft.status, 'requires_p0');
    assert.equal(workflow.revenue_to_ai_handoff.status, 'handoff_blocked');
    assert.equal(workflow.revenue_to_ai_handoff.source_scope, 'ota_channel_blocked_unverified');
    assert.deepEqual([...workflow.revenue_to_ai_handoff.target_blocked_platforms].sort(), ['ctrip', 'meituan']);
    assert.equal(workflow.revenue_to_ai_handoff.ai_draft_status, 'requires_p0');
    const skippedMeituan = payload.p0_execution_plan.operator_sequence.filter((item) => item.platform === 'meituan');
    const skippedHotels = [...new Set(skippedMeituan.map((item) => Number(item.system_hotel_id)))];
    for (const hotel of skippedHotels) {
      const steps = skippedMeituan.filter((item) => Number(item.system_hotel_id) === hotel);
      assert.deepEqual(steps.map((item) => item.type), ['operator_skip', 'single_scope_verifier']);
      assert.equal(steps[0].status, 'p0_skipped_by_operator');
      assert.equal(steps[1].command, `npm.cmd run verify:p0-ota-field-loop -- --date=2026-06-28 --platform=meituan --system-hotel-id=${hotel}`);
      assert.equal(steps[1].required_result, 'ready');
    }
  }
  assert.doesNotMatch(output, /\/api\/online-data\/capture-meituan-browser/);
  assert.doesNotMatch(output, /\/api\/online-data\/profile-login-trigger\/meituan/);
  assert.doesNotMatch(output, /\/api\/online-data\/data-sources\/18\/sync/);
  const stages = Object.fromEntries(payload.stages.map((stage) => [stage.key, stage]));
  assert.equal(stages.ota_data.status, hasTargetDateCtrip ? 'blocked_by_p0_ota_gate' : 'blocked_by_diagnosis_scope');
  if (!hasTargetDateCtrip) assertBlockedDiagnosis(workflow, payload.stages);
  assert.equal(stages.ota_data.claim_allowed, false);
  assert.equal(stages.revenue_analysis.claim_allowed, false);
  assert.equal(stages.ai_decision_advice.claim_allowed, false);
  assert.equal(stages.operation_closure.claim_allowed, false);
  assert.equal(payload.readiness.code_contract_ready, null);
  assert.equal(payload.readiness.runtime_data_ready, false);
  assert.equal(payload.readiness.business_loop_ready, false);
  assert.equal(payload.readiness.release_ready, null);
  assert.equal(stages.operation_closure.evidence.operation_execution_total, payload.operation_summary.operation_execution_total);
  assert.equal(payload.operation_summary.scope.target_date, '2026-06-28');
  assert.deepEqual(payload.operation_summary.scope.platforms, ['ctrip', 'meituan']);
  assert.equal(payload.operation_summary.scope.system_hotel_id, null);
  assert.equal(payload.operation_summary.scope.policy, 'single_system_hotel_scope_required');
  assert.equal(payload.operation_summary.scope.query_applied_before_limit, false);
  assert.equal(payload.operation_summary.scope.scope_source, 'operation_query_not_run_without_hotel_scope');
  assert.equal(payload.operation_summary.matched_total, null);
  assert.equal(payload.operation_summary.operation_execution_total, null);
  assert.equal(payload.operation_summary.operation_roi_ready, null);
  assert.equal(typeof payload.operation_summary.truncated, 'boolean');
});

test('Operation execution statistics apply date/platform scope before limit and expose partial state', () => {
  const serviceSource = readFileSync('app/service/OperationManagementService.php', 'utf8');
  const assigneeConcern = readFileSync('app/service/operation/OperationExecutionAssigneeConcern.php', 'utf8');
  const source = serviceSource + assigneeConcern;
  const reportSource = readSourceAggregate('scripts/report_business_chain_status.php');
  const targetFilter = serviceSource.indexOf("$targetDate = substr(trim((string)($filters['target_date'] ?? '')), 0, 10);");
  const scopedQuery = serviceSource.indexOf('$this->prepareExecutionFlowQuery($query, $filters)');
  const matchedCount = assigneeConcern.indexOf('$matchedTotal = (int)(clone $query)->count();');
  const limitedSelect = assigneeConcern.indexOf("$intentRows = $query->order('id', 'desc')->limit($limit)->select()->toArray();");

  assert(targetFilter >= 0, 'target-date scope filter is missing');
  assert(scopedQuery > targetFilter, 'scoped query must be prepared after target-date/platform scope');
  assert(matchedCount >= 0, 'matched total must be computed inside the extracted query concern');
  assert(limitedSelect > matchedCount, 'row limit must be applied after scoped matched count');
  assert.match(source, /'data_status' => \$dataGaps === \[\] \? self::DATA_OK : 'partial'/);
  assert.match(source, /'execution_total_loaded' => true/);
  assert.match(source, /'roi_loaded' => \$taskTableLoaded && \$evidenceTableLoaded && !\$truncated/);

  const helperStart = reportSource.indexOf('function business_chain_scope_execution_flow');
  const helperEnd = reportSource.indexOf('function business_chain_focused_ota_revenue_ai_chain', helperStart);
  const helper = reportSource.slice(helperStart, helperEnd);
  assert(helperStart >= 0 && helperEnd > helperStart, 'execution-flow scope helper is missing');
  assert.doesNotMatch(helper, /array_filter|buildExecutionFlowSummary/, 'report must not re-filter or rebuild the DB-scoped execution flow');
});

test('Business-chain report keeps the Ctrip path truthful at either ready or blocked P0 state', (t) => {
  const result = spawnSync(php, [
    'scripts/report_business_chain_status.php',
    '--date=2026-06-28',
    '--platform=ctrip',
  ], {
    cwd: process.cwd(),
    encoding: 'utf8',
    windowsHide: true,
  });
  const output = String(result.stdout || result.stderr || '');
  if (skipWhenRuntimeUnavailable(t, result, output)) return;
  const payload = extractJson(output);
  const handoff = payload.downstream_reference_workflow.revenue_to_ai_handoff;
  const packet = handoff.manual_review_packet;
  const reviewContract = packet.ai_decision_review_contract;
  const resolutionPlan = reviewContract.resolution_plan;
  const operationHandoff = payload.downstream_reference_workflow.ai_to_operation_handoff;
  const operationIntake = operationHandoff.operation_intake_packet;
  const operationPreflight = operationIntake.operation_intake_preflight_contract;
  const ctripActionQueue = payload.downstream_reference_workflow.ctrip_chain_action_queue;
  const ctripActionsByCode = Object.fromEntries(ctripActionQueue.items.map((item) => [item.code, item]));
  const action = payload.downstream_reference_workflow.ai_advice_draft.actions[0];
  const expectedReviewInputCount = reviewContract.required_input_items.length;
  const expectedResolutionItemCount = resolutionPlan.items.length;

  assertReadOnlyWorkflow(payload.downstream_reference_workflow);
  assert.equal(payload.scope.target_date, '2026-06-28');

  assert.ok([0, 2].includes(result.status), `unexpected report exit ${result.status}: ${output.slice(0, 1000)}`);
  assert.deepEqual(payload.scope.platforms, ['ctrip']);
  if (result.status === 2) {
    assert.equal(payload.status, 'incomplete');
    assert.equal(payload.claim_allowed, false);
    assert.equal(payload.p0_downstream_gate.status, 'blocked_by_p0_ota_gate');
    assert.equal(payload.p0_execution_plan.status, 'incomplete');
    assert.equal(handoff.can_auto_write_ota, false);
    assert.equal(packet.status, 'blocked_ready_for_manual_review');
    assert.equal(reviewContract.approval_allowed, false);
    assert.equal(reviewContract.operation_intake_allowed, false);
    assert.equal(resolutionPlan.approval_allowed_after_resolution, false);
    assert(packet.blockers.some((item) => item.reason === 'diagnosis_scope_unverified'));
    assert.deepEqual(packet.revenue_metrics, []);
    assert.equal(operationHandoff.operation_intake_packet.operation_intake_preflight_contract.would_call_create_endpoint, false);
    const stages = Object.fromEntries(payload.stages.map((stage) => [stage.key, stage]));
    const diagnosisBlocked = payload.downstream_reference_workflow.revenue_diagnosis.status === 'blocked';
    assert.equal(stages.ota_data.status, diagnosisBlocked ? 'blocked_by_diagnosis_scope' : 'blocked_by_p0_ota_gate');
    if (diagnosisBlocked) assertBlockedDiagnosis(payload.downstream_reference_workflow, payload.stages);
    assert.equal(stages.ota_data.claim_allowed, false);
    assert.equal(stages.revenue_analysis.claim_allowed, false);
    assert.equal(stages.ai_decision_advice.claim_allowed, false);
    assert.equal(stages.operation_closure.claim_allowed, false);
    assert.equal(payload.readiness.runtime_data_ready, false);
    assert.equal(payload.readiness.business_loop_ready, false);
    assert.equal(payload.operation_summary.scope.target_date, '2026-06-28');
    assert.deepEqual(payload.operation_summary.scope.platforms, ['ctrip']);
    return;
  }
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
  assert.equal(packet.primary_action.reason, 'available_room_nights_missing');
  assert.equal(packet.primary_action.auto_write_ota, false);
  assert.equal(packet.primary_blocker.reason, 'available_room_nights_missing');
  assert(packet.blockers.some((item) => item.reason === 'available_room_nights_missing'));
  assert(packet.revenue_metrics.some((item) => item.key === 'ota_room_nights' && item.value > 0 && item.status === 'ok'));
  assert(packet.revenue_metrics.some((item) => item.key === 'ota_contribution_revpar' && item.status === 'not_calculable' && item.reason === 'available_room_nights_missing'));
  assert(packet.forbidden_actions.includes('auto_write_ota'));
  assert(packet.forbidden_actions.includes('create_operation_execution_without_human_approval'));
  assert.equal(reviewContract.status, 'blocked_by_review_inputs');
  assert.equal(reviewContract.review_entry, 'agent-center');
  assert.equal(reviewContract.persisted, false);
  assert.equal(reviewContract.source_scope, 'ctrip_target_date_ota_channel');
  assert.deepEqual(reviewContract.source_platforms, ['ctrip']);
  assert.equal(reviewContract.manual_review_packet_status, 'blocked_ready_for_manual_review');
  assert.equal(reviewContract.candidate_action_reason, 'available_room_nights_missing');
  assert.equal(reviewContract.approval_allowed, false);
  assert.equal(reviewContract.operation_intake_allowed, false);
  assert.equal(reviewContract.auto_apply_ai_advice, false);
  assert.equal(reviewContract.required_input_count, expectedReviewInputCount);
  assert(reviewContract.required_input_items.some((item) => item.code === 'revpar_denominator' && item.input_type === 'revenue_metric_evidence' && item.evidence_code === 'available_room_nights_missing'));
  assert(reviewContract.required_input_items.some((item) => item.code === 'floor_price' && item.input_type === 'revenue_metric_evidence' && item.evidence_code === 'floor_price_missing'));
  assert(reviewContract.required_input_items.some((item) => item.code === 'manual_review_workflow' && item.input_type === 'manual_review_process_gate' && item.evidence_code === 'manual_review_workflow_not_connected'));
  assert(reviewContract.required_input_items.some((item) => item.code === 'operation_feedback_input' && item.input_type === 'operation_feedback_evidence' && item.evidence_code === 'operation_execution_not_loaded'));
  assert(reviewContract.metric_snapshot.some((item) => item.key === 'ota_adr' && item.status === 'ok'));
  assert(reviewContract.metric_snapshot.some((item) => item.key === 'ota_contribution_revpar' && item.status === 'not_calculable' && item.reason === 'available_room_nights_missing'));
  assert(reviewContract.required_output_fields.includes('decision_status'));
  assert(reviewContract.required_output_fields.includes('evidence_links'));
  assert(reviewContract.allowed_decision_outputs.some((item) => item.code === 'request_revenue_metric_evidence' && item.allowed === true));
  assert(reviewContract.allowed_decision_outputs.some((item) => item.code === 'approve_ai_advice_for_operation_intake' && item.allowed === false));
  assert(reviewContract.forbidden_actions.includes('auto_apply_ai_advice'));
  assert.equal(reviewContract.protected_boundary, 'manual_review_requires_explicit_evidence_no_auto_apply');
  assert.equal(resolutionPlan.status, 'has_pending_evidence');
  assert.equal(resolutionPlan.source_scope, 'ctrip_target_date_ota_channel');
  assert.equal(resolutionPlan.metric_scope, 'ota_channel');
  assert.equal(resolutionPlan.item_count, expectedResolutionItemCount);
  assert.equal(resolutionPlan.pending_count, expectedResolutionItemCount);
  assert.equal(resolutionPlan.approval_allowed_after_resolution, false);
  assert.equal(resolutionPlan.post_resolution_gate, 'ai_decision_review_contract.approval_allowed');
  assert.equal(resolutionPlan.post_resolution_verifier, 'npm.cmd run verify:business-chain-report');
  assert(resolutionPlan.forbidden_actions.includes('fill_missing_evidence_with_defaults'));
  assert(resolutionPlan.forbidden_actions.includes('approve_ai_advice_without_resolving_inputs'));
  assert(resolutionPlan.items.some((item) => item.code === 'revpar_denominator' && item.resolution_action === 'provide_available_room_nights_or_mark_metric_unusable' && item.forbidden_shortcut === 'default_available_room_nights'));
  assert(resolutionPlan.items.some((item) => item.code === 'manual_review_workflow' && item.resolution_action === 'persist_or_attach_manual_review_record' && item.acceptance_check.includes('manual review record has reviewer')));
  if (resolutionPlan.items.some((item) => item.code === 'ota_metrics')) {
    assert(resolutionPlan.items.some((item) => item.code === 'ota_metrics' && item.resolution_action === 'verify_zero_room_nights_or_correct_ota_room_nights'));
  } else {
    assert(!action.blocking_reasons.includes('ota_room_nights_zero'));
  }
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
  assert.equal(operationPreflight.required_review_input_count, expectedReviewInputCount);
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
  assert.equal(ctripActionQueue.status, 'has_blocking_actions');
  assert.equal(ctripActionQueue.item_count, 4);
  assert.equal(ctripActionQueue.blocking_count, 4);
  assert.equal(ctripActionQueue.source_scope, 'ctrip_target_date_ota_channel');
  assert.deepEqual(ctripActionQueue.source_platforms, ['ctrip']);
  assert.equal(ctripActionQueue.protected_boundary, 'ota_channel_action_queue_no_auto_write_no_whole_hotel_truth');
  assert(ctripActionQueue.forbidden_actions.includes('auto_write_ota'));
  assert(ctripActionQueue.forbidden_actions.includes('auto_create_operation_execution_intent'));
  assert(ctripActionQueue.forbidden_actions.includes('claim_operation_roi_ready'));
  assert.equal(ctripActionsByCode.resolve_revenue_metric_gap.stage, 'revenue_analysis');
  assert.equal(ctripActionsByCode.resolve_revenue_metric_gap.evidence_code, 'available_room_nights_missing');
  assert.equal(ctripActionsByCode.approve_ai_manual_review.stage, 'ai_decision');
  assert.equal(ctripActionsByCode.approve_ai_manual_review.evidence_code, 'manual_review_workflow_not_connected');
  assert.equal(ctripActionsByCode.create_operation_intent_after_review.target_entry, '/api/operation/execution-intents');
  assert.equal(ctripActionsByCode.create_operation_intent_after_review.evidence_code, 'operation_intake_not_approved');
  assert.equal(ctripActionsByCode.attach_operation_execution_evidence.target_entry, 'ops-track');
  assert.equal(ctripActionsByCode.attach_operation_execution_evidence.evidence_code, 'operation_execution.evidence_and_effect_review');
  assert.equal(action.reason, 'available_room_nights_missing');
  assert(action.blocking_reasons.includes('available_room_nights_missing'));
  assert(!action.blocking_reasons.includes('online_daily_data_empty'));
  assert.doesNotMatch(output, /meituan/);
});

test('Business-chain markdown exposes Ctrip manual review packet without hiding a blocked P0 gate', (t) => {
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
  const output = String(result.stdout || result.stderr || '');

  if (skipWhenRuntimeUnavailable(t, result, output)) return;

  assert.ok([0, 2].includes(result.status), `unexpected report exit ${result.status}: ${output.slice(0, 1000)}`);
  if (result.status === 2) {
    assert.match(output, /status: `incomplete`/);
    assert.match(output, /AI decision advice \| `blocked_by_p0_ota_gate`/);
    assert.match(output, /can_create=`false`/);
  }
  assert.match(output, /code_contract_ready: `not_evaluated`/);
  assert.match(output, /runtime_data_ready: `(true|false)`/);
  assert.match(output, /business_loop_ready: `(true|false)`/);
  assert.match(output, /release_ready: `not_evaluated`/);
  if (output.includes('manual_review_packet: `blocked_by_diagnosis_scope`')) {
    assert.match(output, /OTA data \| `blocked_by_diagnosis_scope` \| `false`/);
    assert.match(output, /runtime_data_ready: `false`/);
    assert.match(output, /business_loop_ready: `false`/);
    assert.match(output, /ai_advice_draft: `requires_p0`, action_count=`0`/);
    assert.match(output, /source_scope=`ota_channel_blocked_unverified`/);
    assert.match(output, /primary_action=``, primary_blocker=`diagnosis_quality_scope_missing_or_invalid`/);
    assert.match(output, /ai_decision_review_contract: `blocked_by_review_inputs`, approval_allowed=`false`, operation_intake_allowed=`false`, required_inputs=`1`/);
    assert.match(output, /ai_decision_required_inputs: `diagnosis_scope:diagnosis_quality_scope_missing_or_invalid`/);
    assert.match(output, /approve_ai_advice_for_operation_intake:blocked/);
    assert.match(output, /persisted=`false`/);
    assert.match(output, /can_create=`false`/);
    assert.match(output, /would_call_create=`false`/);
    assert.doesNotMatch(output, /meituan/);
    return;
  }
  assert.match(output, /manual_review_packet: `blocked_ready_for_manual_review`/);
  assert.match(output, /mode=`manual_review_only`/);
  // Without --system-hotel-id, the upstream scope must be repaired before metric advice exists.
  assert.match(output, /primary_action=``/);
  assert.match(output, /primary_blocker=`diagnosis_scope_unverified`/);
  assert.match(output, /manual_review_next_blockers: `diagnosis_scope_unverified,upstream_manual_review_not_ready,scoped_ai_action_missing`/);
  assert.match(output, /manual_review_forbidden_actions: `auto_write_ota/);
  assert.match(output, /create_operation_execution_without_human_approval/);
  const reviewCount = output.match(/ai_decision_review_contract: `blocked_by_review_inputs`, approval_allowed=`false`, operation_intake_allowed=`false`, required_inputs=`(\d+)`/);
  const resolutionCount = output.match(/ai_decision_resolution_plan: `has_pending_evidence`, items=`(\d+)`, pending=`(\d+)`, gate=`ai_decision_review_contract\.approval_allowed`/);
  assert(reviewCount, 'AI decision review contract count is missing');
  assert(resolutionCount, 'AI decision resolution-plan counts are missing');
  assert(Number(reviewCount[1]) > 0, 'blocked AI review must expose at least one required input');
  assert.equal(Number(resolutionCount[1]), Number(reviewCount[1]));
  assert.equal(Number(resolutionCount[2]), Number(reviewCount[1]));
  assert.match(output, /ai_decision_required_inputs: `diagnosis_scope:diagnosis_scope_unverified,upstream_gate:upstream_manual_review_not_ready,ai_action:scoped_ai_action_missing`/);
  assert.match(output, /ai_decision_allowed_outputs: `request_revenue_metric_evidence:allowed,record_manual_review_note:allowed,reject_ai_advice:allowed,approve_ai_advice_for_operation_intake:blocked`/);
  assert.match(output, /ai_decision_resolution_items: `diagnosis_scope:resolve_diagnosis_scope,upstream_gate:resolve_upstream_gate,ai_action:resolve_ai_action`/);
  assert.match(output, /ai_to_operation_handoff: `operation_intake_blocked_by_manual_review`/);
  assert.match(output, /target=`\/api\/operation\/execution-intents`/);
  assert.match(output, /persisted=`false`/);
  assert.match(output, /can_create=`false`/);
  assert.match(output, /operation_intake_packet: `blocked_by_manual_review_packet`/);
  assert.match(output, /source_module=`ota_revenue_ai_manual_review`/);
  assert.match(output, /object_type=`ota_pricing`/);
  assert.match(output, /blocked_reason=`diagnosis_scope_unverified`/);
  assert.match(output, /operation_intake_preflight_contract: `blocked_by_ai_review_contract`, create_allowed=`false`, would_call_create=`false`, missing_fields=`10`/);
  assert.match(output, /operation_intake_missing_fields: `approved_ai_advice:ai_decision_review_inputs_pending,operation_intake_allowed:operation_intake_gate_closed,hotel_id:operator_selected_hotel_missing/);
  assert.match(output, /ctrip_chain_action_queue: `has_blocking_actions`, items=`4`, blocking=`4`/);
  assert.match(output, /ctrip_chain_next_action: action=`resolve_revenue_metric_gap`, stage=`revenue_analysis`, evidence=`diagnosis_scope_unverified`/);
  assert.match(output, /ctrip_chain_next_action: action=`approve_ai_manual_review`, stage=`ai_decision`, evidence=`blocked_ready_for_manual_review`/);
  assert.match(output, /ctrip_chain_next_action: action=`create_operation_intent_after_review`, stage=`operation_management`, evidence=`operation_intake_blocked_by_manual_review`, target=`\/api\/operation\/execution-intents`/);
  assert.match(output, /ctrip_chain_next_action: action=`attach_operation_execution_evidence`, stage=`operation_management`, evidence=`operation_execution\.evidence_and_effect_review`, target=`ops-track`/);
  assert.match(output, /ctrip_chain_forbidden_actions: `auto_write_ota,auto_create_operation_execution_intent,claim_ai_decision_final,claim_operation_roi_ready,promote_ota_scope_to_whole_hotel_truth`/);
  assert.doesNotMatch(output, /meituan/);
});
