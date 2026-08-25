import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(path, 'utf8');
const catalog = read('app/service/MasterPerspectiveAdvisoryCatalog.php');
const council = read('app/service/OperatingQuestionCouncilService.php');
const controller = read('app/controller/OperatingIntelligence.php');
const routes = read('route/app.php');
const component = read('public/components/system/operating-intelligence-components.js');
const appMain = read('public/app-main.js');
const index = read('public/index.html');
const manifest = JSON.parse(read('docs/knowledge/master-perspectives/source-manifest.json'));

test('165 package is traceable, deduplicated and never installed as human authority', () => {
  assert.equal(
    manifest.source.sha256,
    '32c06de45983119efd6f7cfa9b1e8ca5ce59f8a4e5339267dc383a5fc0ee3970',
  );
  assert.equal(manifest.source.source_entry_count, 165);
  assert.equal(manifest.source.binary_duplicate_of_prior_reviewed_package, true);
  assert.equal(manifest.disposition.duplicate_archive, 'reject_or_quarantine_no_reprocessing');
  assert.equal(manifest.disposition.seven_domain_advisory_method, 'absorb');
  assert.equal(manifest.disposition.installation_status, 'none_of_165_skills_installed');
  assert.equal(manifest.truth_boundaries.real_human_consultants, false);
  assert.match(catalog, /SOURCE_ENTRY_COUNT = 165/);
  assert.match(catalog, /hash_verified_binary_duplicate/);
  assert.match(catalog, /source_package_execution' => 'not_installed_not_executed'/);
});

test('catalog implements seven bounded domains and selects no more than five', () => {
  for (const lens of [
    'evidence_and_uncertainty',
    'customer_and_value',
    'competition_and_strategy',
    'operations_and_execution',
    'risk_and_resilience',
    'communication_and_alignment',
    'ethics_and_fairness',
  ]) {
    assert.match(catalog, new RegExp(`'${lens}'`));
  }
  assert.match(catalog, /'maximum_lenses' => 5/);
  assert.match(catalog, /'minimum_lenses' => 2/);
  assert.match(catalog, /'preserve_disagreement' => true/);
  assert.match(catalog, /'votes_are_not_evidence' => true/);
  assert.doesNotMatch(council, /private function personas\(/);
});

test('real operating-question entry runs, persists and reads back the advisory council', () => {
  assert.match(routes, /operating-questions\/:id\/council-runs\/latest/);
  assert.match(routes, /operating-questions\/:id\/council-runs\/:runId/);
  assert.match(routes, /operating-questions\/:id\/council-runs/);
  assert.ok(
    routes.indexOf("Route::get('/operating-questions/:id/council-runs/latest'")
      < routes.indexOf("Route::get('/operating-questions/:id/council-runs/:runId'"),
    'literal latest route must precede the exact run-id route',
  );
  assert.ok(
    routes.indexOf("Route::get('/operating-questions/:id/council-runs/:runId'")
      < routes.indexOf("Route::get('/operating-questions/:id',"),
    'nested exact council readback route must precede the generic question route',
  );
  assert.match(controller, /runQuestionCouncil/);
  assert.match(controller, /latestQuestionCouncil/);
  assert.match(controller, /readQuestionCouncil/);
  assert.match(council, /operating_question_council\.v3/);
  assert.match(council, /operating_question_council\.v2/);
  assert.match(council, /persistence_status'\] = 'readback_verified'/);
  assert.match(council, /verified_fact_reference_missing/);
  assert.match(council, /readCurrentVerifiedFactsForRefs/);
  assert.match(council, /verified_fact_reference_invalid/);
  assert.match(council, /verified_fact_readback_mismatch/);
  assert.match(council, /verified_fact_scope_mismatch/);
  assert.match(council, /verified_fact_source_drift_detected/);
  assert.match(council, /content_digest/);
  assert.match(council, /primary_action_draft_requires_user_trigger/);
  assert.match(council, /'automatic_execution' => false/);
  assert.match(council, /'real_human_consensus' => false/);
});

test('UI tells the truth about source, disagreement, falsification and execution handoff', () => {
  assert.match(component, /165视角经营顾问团/);
  assert.match(component, /来源包共165个条目/);
  assert.match(component, /静态审查后只吸纳七域方法框架/);
  assert.match(component, /按问题选2–5个领域视角/);
  assert.match(component, /不等于165位真人在线或独立专家共识/);
  assert.match(component, /框架来源/);
  assert.match(component, /支持证据引用/);
  assert.match(component, /冲突观点/);
  assert.match(component, /冲突证据引用/);
  assert.match(component, /本次证据引用/);
  assert.doesNotMatch(component, /`冲突证据：/);
  assert.match(component, /可证伪检查/);
  assert.match(component, /operating-question-council-execution-handoff/);
  assert.match(component, /不会自动创建或执行经营动作/);
  assert.doesNotMatch(component, /三种角色视角/);
  assert.match(appMain, /经营顾问会诊身份、来源或边界回读不一致/);
  assert.match(appMain, /real_human_consensus === false/);
  assert.match(appMain, /source_skills_installed === false/);
  assert.match(appMain, /council-runs\/\$\{Number\(saved\.id \|\| 0\)\}/);
  assert.doesNotMatch(appMain, /影子复核(?:回读|运行|身份|没有|保存)/);
});

test('deferred council component uses an exact content fingerprint', () => {
  const hash = crypto.createHash('sha256').update(component).digest('hex').slice(0, 10);
  assert.match(
    index,
    new RegExp(`components/system/operating-intelligence-components\\.js\\?v=[^'"]*-h${hash}`),
  );
});
