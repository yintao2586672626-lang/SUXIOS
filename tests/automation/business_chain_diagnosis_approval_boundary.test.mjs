import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { resolvePhpBinary, buildPhpBinaryCandidates } from '../../scripts/run_node_automation_tests.mjs';

test('actual report requires a diagnosis and action from the same hotel, date and channel before approval', () => {
  const php = resolvePhpBinary(buildPhpBinaryCandidates());
  assert.ok(php, 'PHP is required for the report regression');
  const source = String.raw`
    require_once 'scripts/lib/business_chain_review_scope.php';
    require_once 'scripts/lib/business_chain_p0_scope.php';
    $source = file_get_contents('scripts/report_business_chain_status.php');
    $start = strpos($source, 'function parse_business_chain_args(');
    $end = strrpos($source, 'if (realpath(');
    if ($start === false || $end === false) throw new RuntimeException('report definition boundary missing');
    // Load actual function definitions without booting the application, Composer or a database.
    eval(substr($source, $start, $end - $start));
    $handoff = ['status'=>'handoff_ready_for_manual_review','source_scope'=>'ctrip_target_date_ota_channel','source_platforms'=>['ctrip'],
      'business_date'=>'2026-10-02','system_hotel_id'=>80];
    $diagnosis = ['status'=>'ready','source_channels'=>['ctrip'],
      'business_date'=>'2026-10-02','system_hotel_id'=>80,'metrics'=>[]];
    $draft = ['status'=>'ready_for_manual_review','actions'=>[
      ['key'=>'review_rate','status'=>'ready','reason'=>'','decision_basis_summary'=>[]]]];
    $cases = [
      'ready' => [$handoff,$diagnosis,$draft],
      'missing_diagnosis' => [$handoff,[],$draft],
      'blocked_diagnosis' => [$handoff,array_replace($diagnosis,['status'=>'blocked']),$draft],
      'other_hotel' => [$handoff,array_replace($diagnosis,['system_hotel_id'=>81]),$draft],
      'missing_hotel' => [$handoff,array_diff_key($diagnosis,['system_hotel_id'=>true]),$draft],
      'other_date' => [$handoff,array_replace($diagnosis,['business_date'=>'2026-10-01']),$draft],
      'invalid_date' => [array_replace($handoff,['business_date'=>'2026-02-30']),array_replace($diagnosis,['business_date'=>'2026-02-30']),$draft],
      'other_channel' => [$handoff,array_replace($diagnosis,['source_channels'=>['meituan']]),$draft],
      'no_action' => [$handoff,$diagnosis,array_replace($draft,['actions'=>[]])],
    ];
    $result=[];
    foreach($cases as $name=>$args) $result[$name]=business_chain_manual_review_packet(...$args);
    $result['empty_action_handoff']=business_chain_revenue_to_ai_handoff(
      ['target_ready_platforms'=>['ctrip'],'target_blocked_platforms'=>[]],$diagnosis,['status'=>'ready_for_manual_review','actions'=>[]],true);
    echo json_encode($result, JSON_THROW_ON_ERROR);
  `;
  const result = spawnSync(php, ['-r', source], {cwd:process.cwd(),encoding:'utf8',windowsHide:true});
  assert.equal(result.status, 0, String(result.stderr).slice(0,1500));
  const packets = JSON.parse(result.stdout);
  assert.equal(packets.ready.status, 'ready_for_manual_review');
  assert.equal(packets.ready.ai_decision_review_contract.approval_allowed, true);
  for (const [name,packet] of Object.entries(packets)) {
    if (name === 'empty_action_handoff') continue;
    const review = packet.ai_decision_review_contract;
    assert.equal(review.operation_intake_allowed, false, name);
    assert.equal(review.auto_apply_ai_advice, false, name);
    if (name === 'ready') continue;
    assert.equal(packet.status, 'blocked_by_diagnosis_scope', name);
    assert.ok(packet.blockers.some(item=>item.key==='diagnosis_scope'), name);
    assert.equal(review.approval_allowed, false, name);
    assert.equal(review.resolution_plan.approval_allowed_after_resolution, false, name);
  }
  assert.equal(packets.empty_action_handoff.status, 'handoff_blocked');
  assert.equal(packets.empty_action_handoff.can_create_operation_execution, false);
  assert.equal(packets.empty_action_handoff.can_auto_write_ota, false);
});
