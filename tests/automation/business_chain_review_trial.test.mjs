import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { resolvePhpBinary, buildPhpBinaryCandidates } from '../../scripts/run_node_automation_tests.mjs';

const php = resolvePhpBinary(buildPhpBinaryCandidates());
function reportProbe(body) {
  assert.ok(php, 'PHP is required for the actual report trial');
  const preamble = String.raw`
    require_once 'scripts/lib/business_chain_review_scope.php';
    require_once 'scripts/lib/business_chain_p0_scope.php';
    $source = file_get_contents('scripts/report_business_chain_status.php');
    $start = strpos($source, 'function parse_business_chain_args(');
    $end = strrpos($source, 'if (realpath(');
    if ($start === false || $end === false) throw new RuntimeException('report definition boundary missing');
    eval(substr($source, $start, $end - $start));
    $row = ['source'=>'ctrip','target_status'=>'ready','reference_status'=>'ready',
      'target_date'=>'2026-10-02','system_hotel_id'=>80,
      'source_date_quality'=>['contract_version'=>'ota-source-date-quality-v1','source'=>'ctrip',
        'target_date'=>'2026-10-02','system_hotel_id'=>80,'metric_scope'=>'ota_channel',
        'status'=>'ready','quality_status'=>'available','claim_allowed'=>true]];
    $dataset=['status'=>'ready','fact_ota_daily'=>[['hotel_key'=>'system:80',
      'platform_key'=>'ctrip','date_key'=>'2026-10-02']]];
    $action = ['key'=>'pricing_review','status'=>'ready','reason'=>'','decision_basis_summary'=>[]];
    $revenue = ['hotel_id'=>80,'business_date'=>'2026-10-02','data_status'=>'ready',
      'source_channels'=>['ctrip'],'actual_source_channels'=>['ctrip'],'actions'=>[$action]];
    $handoff = ['status'=>'handoff_ready_for_manual_review','source_scope'=>'ctrip_target_date_ota_channel','source_platforms'=>['ctrip'],
      'system_hotel_id'=>80,'business_date'=>'2026-10-02'];
    $diagnosis = ['status'=>'ready','source_channels'=>['ctrip'],
      'system_hotel_id'=>80,'business_date'=>'2026-10-02'];
    $draft = ['status'=>'ready_for_manual_review','actions'=>[$action]];
    function trial_scope(array $rows, array $dataset): array {
      $scope=business_chain_downstream_reference_scope($rows,[]);
      $input=business_chain_diagnosis_input($scope,['ctrip'=>$dataset],[],false);
      $scope['diagnosis_input']=$input['scope'];
      return $scope;
    }
  `;
  const result = spawnSync(php, ['-r', preamble + body], {
    cwd: process.cwd(), encoding: 'utf8', windowsHide: true,
  });
  assert.equal(result.status, 0, String(result.stderr).slice(0, 1500));
  return JSON.parse(result.stdout);
}

function assertBlocked(packet) {
  assert.notEqual(packet.status, 'ready_for_manual_review');
  const contract = packet.ai_decision_review_contract;
  assert.equal(contract.approval_allowed, false);
  assert.equal(contract.resolution_plan.approval_allowed_after_resolution, false);
  assert.equal(contract.operation_intake_allowed, false);
  assert.equal(contract.auto_apply_ai_advice, false);
}

test('actual source-to-workflow builders preserve identity and permit the valid manual review path', () => {
  const workflow = reportProbe(String.raw`
    $scope = trial_scope([$row], $dataset);
    echo json_encode(business_chain_downstream_reference_workflow($revenue, [], false, $scope, true), JSON_THROW_ON_ERROR);
  `);
  assert.equal(workflow.revenue_diagnosis.system_hotel_id, 80);
  assert.equal(workflow.revenue_diagnosis.business_date, '2026-10-02');
  assert.deepEqual(workflow.revenue_diagnosis.source_channels, ['ctrip']);
  const handoff = workflow.revenue_to_ai_handoff;
  assert.equal(handoff.system_hotel_id, 80);
  assert.equal(handoff.business_date, '2026-10-02');
  assert.equal(handoff.manual_review_packet.status, 'ready_for_manual_review');
  assert.equal(handoff.manual_review_packet.ai_decision_review_contract.approval_allowed, true);
  assert.equal(handoff.can_create_operation_execution, false);
  assert.equal(handoff.can_auto_write_ota, false);
});

for (const status of ['', 'unknown', 'failed', 'reference_only', 'partial_reference_only']) {
  test(`diagnosis status ${status || 'missing'} never grants approval with otherwise matching identity`, () => {
    const packet = reportProbe(`$diagnosis['status'] = ${JSON.stringify(status)};
      echo json_encode(business_chain_manual_review_packet($handoff,$diagnosis,$draft), JSON_THROW_ON_ERROR);`);
    assertBlocked(packet);
  });
}

for (const status of ['', 'unknown', 'failed', 'blocked']) {
  test(`action status ${status || 'missing'} never grants approval without a blocking reason`, () => {
    const packet = reportProbe(`$draft['actions'][0]['status'] = ${JSON.stringify(status)};
      echo json_encode(business_chain_manual_review_packet($handoff,$diagnosis,$draft), JSON_THROW_ON_ERROR);`);
    assertBlocked(packet);
  });
}

test('a non-ready packet cannot expose approval through an empty required-input list', () => {
  const packet = reportProbe(String.raw`
    $draft['actions'][0]['reason']='floor_price_missing';
    echo json_encode(business_chain_manual_review_packet($handoff,$diagnosis,$draft), JSON_THROW_ON_ERROR);
  `);
  assertBlocked(packet);
});

test('explicit ok diagnosis and pending manual review action keep the valid compatibility path', () => {
  const packet = reportProbe(String.raw`
    $diagnosis['status']='ok'; $draft['actions'][0]['status']='pending_review';
    echo json_encode(business_chain_manual_review_packet($handoff,$diagnosis,$draft),JSON_THROW_ON_ERROR);
  `);
  assert.equal(packet.status, 'ready_for_manual_review');
  assert.equal(packet.ai_decision_review_contract.approval_allowed, true);
  assert.equal(packet.ai_decision_review_contract.operation_intake_allowed, false);
});

test('a legacy display source declaration without actual-source metadata remains unverified', () => {
  const workflow = reportProbe(String.raw`
    unset($revenue['actual_source_channels']);
    $scope=trial_scope([$row],$dataset);
    echo json_encode(business_chain_downstream_reference_workflow($revenue,[],false,$scope,true),JSON_THROW_ON_ERROR);
  `);
  assert.deepEqual(workflow.revenue_diagnosis.source_channels, []);
  assertBlocked(workflow.revenue_to_ai_handoff.manual_review_packet);
});

test('the actual revenue action builder keeps an eligible pending human-review queue usable', () => {
  const workflow = reportProbe(String.raw`
    require_once 'vendor/autoload.php';
    $service=(new ReflectionClass(\app\service\RevenueAiOverviewService::class))->newInstanceWithoutConstructor();
    $revenue['actions']=(new ReflectionMethod($service,'actions'))->invoke($service,[],[],[
      'overall_status'=>'ready','blocking_reasons'=>[],
      'gates'=>[['key'=>'floor_price','label'=>'synthetic floor evidence','status'=>'ok']],
    ],['pending_count'=>1]);
    $scope=trial_scope([$row],$dataset);
    echo json_encode(business_chain_downstream_reference_workflow($revenue,[],false,$scope,true),JSON_THROW_ON_ERROR);
  `);
  assert.equal(workflow.ai_advice_draft.actions[0].reason, 'price_suggestions_pending_review');
  const packet = workflow.revenue_to_ai_handoff.manual_review_packet;
  assert.equal(packet.status, 'ready_for_manual_review');
  assert.equal(packet.ai_decision_review_contract.approval_allowed, true);
  assert.equal(packet.ai_decision_review_contract.operation_intake_allowed, false);
});

const sourceCases = {
  other_hotel: "$revenue['hotel_id']=81;",
  missing_hotel: "unset($revenue['hotel_id']);",
  other_date: "$revenue['business_date']='2026-10-01';",
  missing_date: "unset($revenue['business_date']);",
  other_channel: "$revenue['source_channels']=['meituan']; $revenue['actual_source_channels']=['meituan'];",
  no_actual_facts: "$revenue['actual_source_channels']=[];",
  missing_source_hotel: "unset($row['system_hotel_id']);",
  mixed_source_hotels: "$extra=array_replace($row,['source'=>'meituan','system_hotel_id'=>81]); $rows[]=$extra;",
  mixed_source_dates: "$extra=array_replace($row,['source'=>'meituan','target_date'=>'2026-10-01']); $rows[]=$extra;",
};
for (const [name, mutation] of Object.entries(sourceCases)) {
  test(`actual workflow refuses ${name} instead of stamping expected scope onto the diagnosis`, () => {
    const workflow = reportProbe(`$rows=[$row]; ${mutation} $rows[0]=$row;
      $scope=trial_scope($rows,$dataset);
      echo json_encode(business_chain_downstream_reference_workflow($revenue,[],false,$scope,true),JSON_THROW_ON_ERROR);`);
    if (name === 'other_channel') assert.deepEqual(workflow.revenue_diagnosis.source_channels, []);
    if (name === 'no_actual_facts') assert.deepEqual(workflow.revenue_diagnosis.source_channels, []);
    assertBlocked(workflow.revenue_to_ai_handoff.manual_review_packet);
  });
}
