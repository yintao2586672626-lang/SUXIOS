import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import { createSSRApp, computed, ref } from 'vue';
import { parse } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';
import { readFrontendContractSource } from '../tests/automation/helpers/frontend_source.mjs';

const root = process.cwd();
const checks = [];

function read(file) {
  const target = path.join(root, file);
  return fs.existsSync(target) ? fs.readFileSync(target, 'utf8') : '';
}

function check(file, label, ok, detail = '') {
  checks.push({ file, label, ok: Boolean(ok), detail });
}

function includesAll(file, label, source, needles) {
  const missing = needles.filter((needle) => !source.includes(needle));
  check(file, label, missing.length === 0, missing.join(', '));
}

function excludesAll(file, label, source, needles) {
  const present = needles.filter((needle) => source.includes(needle));
  check(file, label, present.length === 0, present.join(', '));
}

const service = read('app/service/Phase3OperationEffectLoopService.php');
const controller = read('app/controller/OnlineData.php');
const otaActionHandler = read('app/service/Ota/OtaActionHandler.php');
const operationWorkbenchConcern = read('app/controller/concern/OperationWorkbenchConcern.php');
const route = read('route/app.php');
const docs = read('docs/phase3_operation_effect_loop_acceptance.md');
const runtimeVerifier = read('scripts/verify_phase3_operation_effect_loop_runtime.php');
const packageJson = read('package.json');
const frontend = readFrontendContractSource();
const onlineDataControllerSurface = `${controller}\n${otaActionHandler}\n${operationWorkbenchConcern}`;
const frontendSurface = frontend;
const frontendOnlineDataStart = frontend.indexOf("currentPage === 'online-data'");
const frontendDataHealthStart = frontend.indexOf('data-testid="online-data-health-panel"', frontendOnlineDataStart);
const frontendDataHealthEnd = frontend.indexOf("onlineDataTab === 'analysis'", frontendDataHealthStart);
const frontendDataHealthSlice = frontendDataHealthStart >= 0 && frontendDataHealthEnd > frontendDataHealthStart
  ? frontend.slice(frontendDataHealthStart, frontendDataHealthEnd)
  : '';

includesAll('app/service/Phase3OperationEffectLoopService.php', 'phase3 service exposes six-stage loop contract', service, [
  'final class Phase3OperationEffectLoopService',
  "public function build(array $options = []): array",
  'public function ledger(int $limit = 50): array',
  'public function publishSop(array $input, ?int $userId = null): array',
  'public function createReplicationPlan(array $input, ?int $userId = null): array',
  'public function buildFromSnapshot(array $snapshot, array $options = []): array',
  "'phase' => 'phase3_operation_effect_loop'",
  "'anomaly' => $anomaly",
  "'operation_action' => $operationAction",
  "'execution_evidence' => $executionEvidence",
  "'effect_review' => $effectReview",
  "'sop' => $sop",
  "'replication' => $replication",
]);

includesAll('app/service/Phase3OperationEffectLoopService.php', 'phase3 service is explicit about OTA scope and protected boundaries', service, [
  "'metric_scope' => 'ota_channel'",
  "'source_policy' => 'read_existing_daily_workbench_patrol_snapshot_and_online_daily_data_only'",
  "'collection_logic_changed' => false",
  "'collection_fields_changed' => false",
  "'manual_collection_logic_changed' => false",
  "'automatic_collection_logic_changed' => false",
  "'raw_data_exposed' => false",
  "'auto_decision_enabled' => false",
  'it does not change Ctrip or Meituan acquisition logic, fields, routes, or storage mappings',
  "'causality_claimed' => false",
  "'auto_publish_enabled' => false",
  "'auto_apply_enabled' => false",
  'runtime_phase3_sop_ledger_from_reviewed_patrol_action',
  'runtime_phase3_replication_plan_from_reviewed_sop_candidate',
]);

includesAll('app/service/Phase3OperationEffectLoopService.php', 'phase3 service keeps missing states visible', service, [
  "'execution_missing'",
  "'operation_execution_missing'",
  "'execution_evidence_missing'",
  "'execution_evidence_source_unverified'",
  "'execution_outcome_unverified'",
  "'execution_positive_outcome_unverified'",
  "'executed_evidence_unverified'",
  "'review_missing'",
  "'metric_window_missing'",
  "'sop_candidate_missing'",
  "'similar_hotel_missing_in_snapshot'",
]);

includesAll('app/service/Phase3OperationEffectLoopService.php', 'phase3 service reads existing patrol snapshots and online_daily_data only', service, [
  'new DailyWorkbenchPatrolService()',
  '->findByRunId($runId)',
  '->latest()',
  "Db::name('online_daily_data')",
  "'read_existing_online_daily_data_only_without_raw_data'",
]);

excludesAll('app/service/Phase3OperationEffectLoopService.php', 'phase3 service does not call OTA acquisition or mutation paths', service, [
  'executeAutoFetch(',
  'executeCtripAutoFetch(',
  'executeMeituanAutoFetch(',
  'fetchCtripData(',
  'fetchMeituanData(',
  'captureCtrip',
  'captureMeituan',
  'saveDailyData(',
  'importDataSourceRows(',
  'updateData(',
  'deleteData(',
  'saveCookies',
  "'raw_data' =>",
  '"raw_data"',
  'cookie',
  'usertoken',
  'usersign',
  'spidertoken',
]);

includesAll('app/controller/OnlineData.php + app/service/Ota/OtaActionHandler.php + app/controller/concern/OperationWorkbenchConcern.php', 'phase3 endpoint remains available through the legacy controller compatibility handler', onlineDataControllerSurface, [
  'use app\\controller\\concern\\OperationWorkbenchConcern;',
  'use OperationWorkbenchConcern;',
  'use app\\service\\Phase3OperationEffectLoopService;',
  'public function phase3OperationEffectLoop(): Response',
  'public function phase3OperationEffectLoopLedger(): Response',
  'public function publishPhase3OperationSop(): Response',
  'public function createPhase3ReplicationPlan(): Response',
  '$this->checkPermission();',
  'new Phase3OperationEffectLoopService()',
  "'run_id' => (string)$this->request->get('run_id', '')",
  "'target_date' => (string)$this->request->get('target_date', '')",
  "'limit' => $this->request->get('limit', 100)",
]);

includesAll('route/app.php', 'phase3 route exists under online-data group', route, [
  "Route::get('/phase3-operation-effect-loop', 'OnlineData/phase3OperationEffectLoop');",
  "Route::get('/phase3-operation-effect-loop/ledger', 'OnlineData/phase3OperationEffectLoopLedger');",
  "Route::post('/phase3-operation-effect-loop/sops/publish', 'OnlineData/publishPhase3OperationSop');",
  "Route::post('/phase3-operation-effect-loop/replications/create', 'OnlineData/createPhase3ReplicationPlan');",
]);

includesAll('docs/phase3_operation_effect_loop_acceptance.md', 'phase3 acceptance doc describes goal, boundaries, statuses, and verification', docs, [
  '巡检异常 -> 运营动作 -> 执行证据 -> 效果复盘 -> SOP沉淀 -> 多店复制',
  'GET /api/online-data/phase3-operation-effect-loop',
  'POST /api/online-data/phase3-operation-effect-loop/sops/publish',
  'POST /api/online-data/phase3-operation-effect-loop/replications/create',
  '不改变携程、美团手动或自动数据获取逻辑',
  '`scope.collection_logic_changed`',
  '`execution_missing`',
  '`review_missing`',
  '`metric_window_missing`',
  '`sop.status=candidate`',
  '`replication.status=candidate`',
  'npm.cmd run verify:phase3-operation-effect-loop',
]);

includesAll('scripts/verify_phase3_operation_effect_loop_runtime.php', 'phase3 runtime verifier covers candidate and missing states', runtimeVerifier, [
  'new Phase3OperationEffectLoopService()',
  'buildFromSnapshot(',
  'phase3_fixture_snapshot(',
  'metric_window',
  'publishSopFromLoopRow(',
  'createReplicationPlanFromLoopRow(',
  'ledger(',
  'executed_evidence_recorded',
  'execution_evidence_count',
  'source_verified',
  'outcome_verified',
  'positive_outcome_verified',
  'reviewed',
  'candidate',
  'execution_missing',
  'raw_data',
]);

includesAll('package.json', 'phase3 verifier is exposed through npm', packageJson, [
  '"verify:phase3-operation-effect-loop": "node scripts/verify_phase3_operation_effect_loop_contract.mjs && C:\\\\xampp\\\\php\\\\php.exe scripts\\\\verify_phase3_operation_effect_loop_runtime.php"',
]);

includesAll('resources/frontend/app-template.html', 'phase3 operation effect loop is rendered in the focused one-page operating surface', frontendDataHealthSlice, [
  'data-testid="phase3-operation-effect-loop"',
  '执行留证与次日复盘',
]);

includesAll('resources/frontend/app-template.html + public/app-main.js', 'phase3 implementation remains available behind its backend boundary', frontendSurface, [
  'phase3OperationEffectLoop',
  'phase3OperationEffectLoopLedger',
  'phase3OperationEffectLoopLoading',
  'phase3OperationEffectLoopError',
  'phase3OperationEffectLoopActionUpdating',
  'loadPhase3OperationEffectLoop',
  'loadPhase3OperationEffectLoopLedger',
  'publishPhase3OperationSop',
  'createPhase3ReplicationPlan',
  "request(`/online-data/phase3-operation-effect-loop?",
  "request('/online-data/phase3-operation-effect-loop/ledger?limit=50')",
  "request('/online-data/phase3-operation-effect-loop/sops/publish'",
  "request('/online-data/phase3-operation-effect-loop/replications/create'",
  'phase3OperationEffectLoopRows',
  'phase3OperationEffectLoopCards',
  'phase3OperationEffectLoopLedgerText',
  'phase3OperationEffectLoopStatusText',
  'phase3OperationEffectLoopStatusClass',
  'phase3OperationEffectLoopBoundaryText',
]);

/** Exercise the retained adapter separately from the currently mounted UI. */
export async function verifyPhase3FrontendSemantics({ appMain, onlineTemplate, knowledgeTemplate }) {
  const results = [];
  const require = (ok, code) => { if (!ok) throw new Error(code); };
  const extract = (start, end) => {
    const a = appMain.indexOf(start), b = appMain.indexOf(end, a);
    require(a >= 0 && b > a, `function_boundary_missing:${start}`);
    return appMain.slice(a, b);
  };
  const find = (node, predicate) => {
    if (predicate(node)) return node;
    for (const child of node.children || []) { const found = find(child, predicate); if (found) return found; }
    return null;
  };
  const attribute = (node, name) => (node.props || []).find(prop => prop.type === 6 && prop.name === name);
  const binding = (node, name, token) => (node.props || []).some(prop => prop.type === 7 && prop.name === name && prop.exp?.content.includes(token));
  const tagged = id => node => node.type === 1 && attribute(node, 'data-testid')?.value?.content === id;
  const text = html => html.replace(/<!--[\s\S]*?-->/g, '').replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim();
  const hasGapMeaning = value => /没有|暂无|尚无|缺少|缺失|不足|未|不全|不能|待|继续.*(?:积累|补)/.test(value);
  const claimsReplication = value => {
    const unnegated = String(value).replace(/(?:尚|暂|并)?不(?:代表|意味着|表示)?(?:可以|可|允许|能够)(?:直接|立即|自动)?复制/g, '');
    return /(?:可|可以|允许|能够)(?:直接|立即|自动)?复制|已(?:满足|具备)复制(?:条件|资格)/.test(unnegated);
  };
  const run = async (key, label, verify) => {
    try { await verify(); results.push({ key, label, ok: true, detail: '' }); }
    catch (error) { results.push({ key, label, ok: false, detail: String(error.message || error) }); }
  };

  await run('adapter', 'retained unmounted Phase3 row adapter preserves missing and screening-only states', async () => {
    const context = vm.createContext({});
    vm.runInContext(extract('const normalizePhase3OperationEffectLoopRow = (row)', 'const phase3OperationEffectLoopRows = computed')
      + '\nglobalThis.normalize = normalizePhase3OperationEffectLoopRow;', context, { timeout: 1000 });
    const empty = context.normalize({ hotel_id: 7, stages: {} });
    require(empty.executionStatus === 'execution_missing' && empty.reviewStatus === 'review_missing'
      && empty.sopStatus === 'not_ready' && empty.replicationStatus === 'not_ready', 'adapter_missing_state_lost');
    for (const field of ['executionText', 'reviewText', 'sopText', 'replicationText']) {
      require(hasGapMeaning(String(empty[field] || '')), `adapter_missing_explanation:${field}`);
    }
    const screening = context.normalize({ hotel_id: 7, stages: { replication: {
      status: 'screening_only', target_hotels: [{ hotel_id: 8, hotel_name: 'Synthetic peer' }],
    } } });
    require(screening.replicationStatus === 'screening_only' && !claimsReplication(screening.replicationText), 'screening_promoted_to_replication');
  });

  await run('visible', 'current SOP gate and knowledge-center replication entry visibly retain evidence gaps and disabled actions', async () => {
    const context = vm.createContext({ computed, ref, coreOperationsHotelId: ref('7'), operatingMemories: ref(null),
      phase3OperationEffectLoop: ref(null), dailyWorkbench: ref({ scope: { target_date: '2026-09-13' } }) });
    vm.runInContext(extract('const buildCoreOperationsSopProgress = ', 'const buildPlatformAccountCenterRows = ')
      + '\nglobalThis.appSystemStatic = { buildCoreOperationsSopProgress };', context, { timeout: 1000 });
    vm.runInContext(extract('const operatingMemoryItems = computed', 'const operatingMemoryDataGapText = computed')
      + extract('const phase3OperationEffectLoopBoundaryText = computed', 'const phase3OperationEffectLoopLedgerText = computed')
      + '\nglobalThis.progress = coreOperationsSopProgress; globalThis.boundary = phase3OperationEffectLoopBoundaryText;', context, { timeout: 1000 });
    const onlineTree = parse(onlineTemplate), knowledgeTree = parse(knowledgeTemplate);
    const sop = find(onlineTree, tagged('core-loop-sop-progress'));
    const boundary = find(onlineTree, tagged('phase3-operation-effect-loop'));
    const draft = find(knowledgeTree, tagged('operating-network-replication-draft'));
    const comparable = find(knowledgeTree, node => node.type === 1 && node.tag === 'div'
      && node.children.some(child => child.type === 1 && binding(child, 'if', 'comparable_hotels')));
    require(sop && boundary && draft && comparable, 'active_readiness_panel_missing');
    const render = async (template, values) => renderToString(createSSRApp({ template, setup: () => values }));
    const boundaryHtml = await render(boundary.loc.source, { phase3OperationEffectLoopBoundaryText: context.boundary.value });
    require(/OTA/.test(text(boundaryHtml)) && /只读|仅读/.test(text(boundaryHtml))
      && /不触发|不会触发/.test(text(boundaryHtml)), 'visible_ota_readonly_boundary_missing');
    const memory = (id, verified) => ({ hotel_id: 7, memory_layer: 'execution_review', quality_status: verified ? 'verified' : 'unverified',
      usage_level: 'decision_support', source_record_id: id, business_date: id === 3 ? '2026-09-14' : '2026-09-13',
      platform: 'ctrip', source_scope: 'ota_channel', context: { source_verified: verified, outcome_verified: verified, positive_outcome_verified: verified } });
    for (const [rows, ready] of [[[], false], [[1, 2, 3].map(id => memory(id, false)), false], [[1, 2, 3].map(id => memory(id, true)), true]]) {
      context.operatingMemories.value = { list: rows };
      const progress = context.progress.value;
      require(progress.ready === ready, 'unverified_review_ready_state_mismatch');
      const visible = text(await render(sop.loc.source, { coreOperationsSopProgress: progress }));
      require(visible.length > 0 && !claimsReplication(visible), 'sop_gate_promoted_to_replication');
      if (!ready) require(hasGapMeaning(visible) && /复盘|证据|核验/.test(visible), 'sop_gap_explanation_missing');
      else require(/候选|验证|核验/.test(visible), 'sop_candidate_boundary_missing');
    }
    // Keep the original entry controls and notices, stopping before its separate
    // saved-plan component; no component registration or external action is needed.
    const savedPlansIndex = draft.children.findIndex(node => node.type === 1 && node.tag === 'operating-network-replication-list');
    require(savedPlansIndex >= 0, 'replication_entry_boundary_missing');
    const comparableGap = find(comparable, node => node.type === 1 && binding(node, 'if', 'comparable_hotels'));
    const comparableGapText = text(await render(comparableGap.loc.source, { operatingNetworkData: { comparable_hotels: [] } }));
    require(hasGapMeaning(comparableGapText) && /候选|门店|酒店|前置|接入/.test(comparableGapText), 'comparable_gap_explanation_missing');
    const entry = '<div>' + comparable.loc.source + draft.children.slice(0, savedPlansIndex).map(node => node.loc.source).join('') + '</div>';
    for (const scenario of ['missing', 'incomplete', 'eligible']) {
      const eligible = scenario === 'eligible';
      const html = await render(entry, {
        operatingNetworkData: { data_status: 'ok', comparable_hotels: [], verified_sops: scenario === 'missing' ? []
          : [{ id: 1, hotel_name: 'Synthetic', title: 'Synthetic SOP', profile_dimension_count: eligible ? 8 : 0,
            replication_eligibility: eligible ? 'eligible_for_validation_draft' : 'incomplete' }] },
        operatingNetworkReplicationForm: { source_sop_version_id: eligible ? '1' : '', target_date_start: '2026-09-13', target_date_end: '2026-09-14' },
        operatingNetworkAction: '', generateOperatingNetworkReplicationDraft() {},
      });
      const rendered = parse(html);
      const button = find(rendered, node => node.type === 1 && node.tag === 'button');
      if (eligible) {
        require(button && !attribute(button, 'disabled'), 'eligible_sop_generation_disabled');
        require(/待.*验证|验证.*草稿|候选.*核验/.test(text(button.loc.source))
          && !claimsReplication(text(button.loc.source)), 'eligible_sop_exceeds_validation_draft');
      } else {
        require(button && attribute(button, 'disabled'), 'missing_sop_generation_enabled');
        const notice = find(rendered, node => node.type === 1 && node.tag === 'p'
          && /SOP/.test(text(node.loc.source)) && hasGapMeaning(text(node.loc.source)));
        require(notice, 'replication_gap_explanation_missing');
      }
      if (scenario !== 'missing') {
        const option = find(rendered, node => node.type === 1 && node.tag === 'option' && attribute(node, 'value')?.value?.content === '1');
        if (eligible) require(option && !attribute(option, 'disabled') && attribute(option, 'selected'), 'eligible_sop_not_selected_or_selectable');
        else require(option && attribute(option, 'disabled'), 'incomplete_sop_selectable');
      }
    }
  });
  return results;
}

if (fileURLToPath(import.meta.url) === path.resolve(process.argv[1] || '')) {
  const semanticChecks = await verifyPhase3FrontendSemantics({ appMain: read('public/app-main.js')
    + '\n' + read('public/system-page-projections.js'),
    onlineTemplate: read('resources/frontend/templates/fragments/35-page-online-data.html'),
    knowledgeTemplate: read('resources/frontend/templates/fragments/20-page-knowledge-center.html') });
  for (const item of semanticChecks) check('public/app-main.js + mounted frontend fragments', item.label, item.ok, item.detail);
  const failed = checks.filter((item) => !item.ok);
  for (const item of checks) {
    const status = item.ok ? 'PASS' : 'FAIL';
    const detail = item.detail ? ` (${item.detail})` : '';
    console.log(`${status} ${item.file} - ${item.label}${detail}`);
  }

  if (failed.length > 0) {
    console.error(`Phase 3 operation effect loop contract failed ${failed.length}/${checks.length} checks.`);
    process.exitCode = 1;
  } else {
    console.log(`Phase 3 operation effect loop contract passed ${checks.length} checks.`);
  }
}
