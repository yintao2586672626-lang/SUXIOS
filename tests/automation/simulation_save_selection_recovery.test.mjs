import assert from 'node:assert/strict';
import test from 'node:test';
import path from 'node:path';
import fs from 'node:fs';
import vm from 'node:vm';
import { createHash, webcrypto } from 'node:crypto';
import * as Vue from 'vue';
import { compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';

let app = fs.readFileSync('public/app-main.js', 'utf8');
const candidateDir = process.argv.find(value => value.startsWith('--candidate-dir='))?.slice('--candidate-dir='.length);
if (candidateDir) {
  for (const [name, endMarker] of [['loadSimulationDetail', '            const reuseSimulationRecord ='], ['handleSimulation', '            const operatingScenarioFields =']]) {
    const start = app.indexOf('            const ' + name + ' =');
    const end = app.indexOf(endMarker, start);
    assert.ok(start >= 0 && end > start);
    app = app.slice(0, start) + fs.readFileSync(path.join(candidateDir, name + '.js'), 'utf8') + app.slice(end);
  }
}
const staticSource = fs.readFileSync('public/simulation-static.js', 'utf8');
const components = fs.readFileSync('public/components/system/app-main-components.js', 'utf8');
const template = fs.readFileSync('resources/frontend/templates/fragments/02-page-ai-simulation.html', 'utf8');
const slice = (source, a, b) => {
  const start = source.indexOf(a), end = source.indexOf(b, start);
  assert.ok(start >= 0 && end > start, `${a} / ${b}`); return source.slice(start, end);
};
const staticSandbox = { window: {} };
vm.runInNewContext(staticSource, staticSandbox);
const api = staticSandbox.window.SUXI_SIMULATION_STATIC;
const componentSandbox = { h: Vue.h };
vm.runInNewContext(slice(components, 'const SimulationHeroActions =', '\n        return Object.freeze({') + '\nglobalThis.hero = SimulationHeroActions;', componentSandbox);
const hero = componentSandbox.hero;
const nameInput = template.match(/<input v-model="aiSimulationParams\.operatingScenario\.case_name"[^>]*>/)?.[0];
const roomInput = template.match(/<input type="number" v-model.number="aiSimulationParams\.roomCount"[^>]*>/)?.[0];
const investmentInput = template.match(/<input type="number" v-model.number="aiSimulationParams\[field.key\]"[^>]*>/)?.[0];
const viewButton = template.match(/<button @click="loadSimulationDetail\(record.id\)"[^>]*>[\s\S]*?<\/button>/)?.[0];
const reuseButton = template.match(/<button @click="reuseSimulationRecord\(record\)"[^>]*>[\s\S]*?<\/button>/)?.[0];
assert.ok(nameInput && roomInput && investmentInput && viewButton && reuseButton);
const renderControls = new Function('Vue', compile(`<div>${nameInput}${roomInput}${investmentInput}${viewButton}${reuseButton}</div>`, { mode: 'function', prefixIdentifiers: true }).code)(Vue);
const clone = value => JSON.parse(JSON.stringify(value));
const deferred = () => { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b; }); return { promise, resolve, reject }; };
const flush = async () => { await new Promise(resolve => setTimeout(resolve, 0)); await Vue.nextTick(); };
let assertions = 0;
const equal = (actual, expected, message) => { assert.equal(actual, expected, message); assertions++; };
const matches = (actual, expected) => { assert.match(actual, expected); assertions++; };

function input(name = '合成方案 A', amount = 12345.67) {
  const value = clone(api.defaultSimulationInput);
  value.hotel_id = 7; value.weekdayDays = 21; value.decorationHardCost = amount;
  value.input_source_status = 'manual_unverified';
  value.operatingScenario = { ...api.createOperatingScenario(), case_name: name, start_month: '2024-02', source_note: '纯内存合成测试，无经营事实', opening_cash: 0 };
  assert.equal(api.validateSimulationInput(value), ''); return value;
}
function record(value, id) {
  // The service saves canonical group totals, rather than the stale summary
  // still present in an edited request. Decimal/zero and selection oracles stay.
  const canonicalInput = clone(value);
  for (const group of [...api.buildSimulationInvestmentGroups(value), ...api.buildSimulationCostGroups(value)]) {
    canonicalInput[group.totalKey] = Math.round(group.total * 10000) / 10000;
  }
  return { id, project_name: value.operatingScenario.case_name,
    input: { ...canonicalInput, operatingScenario: clone(api.normalizedOperatingScenario(value.operatingScenario)) },
    result: { operatingScenario: { case_name: value.operatingScenario.case_name, additional_cash_gap: 0 }, monthlyNetCashflow: 0 },
    scenarios: [], risk_hints: [],
    truth_context: { hotel_id: Number(value.hotel_id), tenant_id: 1, persistence: { readback_verified: true } },
  };
}
function flatten(node) {
  if (!node || typeof node !== 'object') return [];
  return [node, ...(Array.isArray(node.children) ? node.children.flatMap(flatten) : [])];
}
function nodeText(node) {
  return typeof node?.children === 'string' ? node.children : (Array.isArray(node?.children) ? node.children.map(nodeText).join('') : '');
}
function fixture() {
  const historical = record(input('合成历史方案 B', 23456.78), 202);
  const state = Object.fromEntries(Object.entries({
    token: 'synthetic-auth-only', currentPage: 'ai-simulation', aiSimulationParams: input(), aiSimulationLoading: false,
    aiSimulationRecordId: null, aiSimulationResult: null, aiSimulationScenarios: [], simulationRiskHints: [], simulationModelAnalysis: null,
    aiSimulationRecords: [historical], aiProject: { project_name: '合成项目' }, operationHotelOptions: [{ id: 7, name: '合成门店' }, { id: 8, name: '合成门店二' }],
  }).map(([key, value]) => [key, Vue.ref(value)]));
  const posts = [], gets = [], detailRequests = [], notices = [], inputSaves = [], fullSaves = [], saved = new Map([[202, historical]]);
  let holdDetail = false;
  const scope = Vue.effectScope();
  const sandbox = {
    ...state, JSON, crypto: webcrypto, setTimeout, watch: Vue.watch, ref: Vue.ref, computed: Vue.computed, window: staticSandbox.window, authSessionEpoch: 0, suppressSimulationAutoRefresh: false,
    ensureSimulationStaticReady: async () => {},
    isStillOnRequestPage: page => page === state.currentPage.value,
    normalizeSimulationInput: api.normalizeSimulationInput, normalizeSimulationModelAnalysis: api.normalizeSimulationModelAnalysis,
    generateRiskHints: () => [], saveSimulationInputOnly: value => inputSaves.push(clone(value)),
    saveSimulationState: (...values) => fullSaves.push(clone(values)),
    showToast: (message, type = 'success') => notices.push({ message, type }),
    request: async (url, options) => {
      if (url === '/simulation/calculate' && options?.method === 'POST') {
        const pending = deferred(); posts.push({ url, body: JSON.parse(options.body), ...pending }); return pending.promise;
      }
      gets.push(url);
      if (url === '/simulation/records') return { code: 200, data: { list: [...saved.values()] } };
      const match = url.match(/^\/simulation\/records\/(\d+)$/); assert.ok(match, `Unexpected synthetic request: ${url}`);
      if (holdDetail) {
        holdDetail = false; const pending = deferred(); detailRequests.push({ url, ...pending }); return pending.promise;
      }
      return { code: 200, data: clone(saved.get(Number(match[1]))) };
    },
  };
  const staticLoader = slice(app, '            const simulationStaticScript =', '            const ensureSimulationStaticReady =')
    + slice(app, '            const hasSimulationStatic =', '            const requireSimulationStaticFunction =');
  const historyState = app.match(/^\s*const simulationHistoryState = ref\([^\n]+\);/m)?.[0];
  assert.ok(historyState, 'Original simulation history state declaration is present');
  const auth = slice(app, 'const captureAuthSession =', 'const createDefaultAuthContext =');
  const detail = slice(app, 'function refreshSimulationState(shouldPersist = true)', 'const archiveSimulationRecord =');
  const calculation = slice(app, 'let simulationCalculationRequestId = 0;', 'const operatingScenarioFields =');
  const inputWatch = slice(app, app.includes('watch([aiSimulationParams, () => simulationDraft.value.name]') ? 'watch([aiSimulationParams, () => simulationDraft.value.name]' : 'watch(aiSimulationParams, () => {', 'const baseSimulation = computed');
  scope.run(() => vm.runInNewContext(`${staticLoader}\n${historyState}\n${app.match(/^\s*const simulationDraft = ref\([^\n]+\);/m)?.[0] || ''}\n${auth}\n${detail}\n${calculation}\n${inputWatch}\nglobalThis.methods = { handleSimulation, loadSimulationDetail, reuseSimulationRecord, loadSimulationRecords };`, sandbox));
  const methods = sandbox.methods;
  let tree;
  async function render(selectedRecord = historical) {
    const ssr = Vue.createSSRApp({
      setup: () => ({ ...state, ...methods, field: { key: 'decorationHardCost' }, record: selectedRecord }),
      render() {
        const heroTree = hero.render.call({ hotelId: state.aiSimulationParams.value.hotel_id, hotels: state.operationHotelOptions.value,
          loading: state.aiSimulationLoading.value, hotelValid: api.simulationHotelSelectionIsPermitted(state.aiSimulationParams.value, state.operationHotelOptions.value),
          commissionCalculatorOpen: false, commissionCalculatorMounted: false,
          $emit: (event, value) => event === 'run' ? methods.handleSimulation() : event === 'refresh' ? methods.loadSimulationRecords() : event === 'update:hotelId' ? (state.aiSimulationParams.value.hotel_id = value) : undefined,
        });
        tree = Vue.h('div', [heroTree, renderControls(this, [])]); return tree;
      },
    });
    return renderToString(ssr);
  }
  const find = predicate => { const node = flatten(tree).find(predicate); assert.ok(node, 'Actual VNode control is present'); return node; };
  const click = (key, id = 202) => {
    const node = key === 'run' ? find(node => node.type === 'button' && /运行三情景模拟|生成中/.test(nodeText(node)))
      : key === 'refresh' ? find(node => node.type === 'button' && nodeText(node) === '刷新历史')
      : find(node => node.props?.['data-testid'] === `history-simulation-${key}-${id}`);
    if (node.props?.disabled) return null;
    return node.props.onClick();
  };
  const editName = value => find(node => node.props?.['data-testid'] === 'scenario-name').props['onUpdate:modelValue'](value);
  const complete = index => {
    const result = record(posts[index].body.input, 101 + index); saved.set(result.id, result);
    posts[index].resolve({ code: 200, data: result }); return result;
  };
  return { state, historical, posts, gets, detailRequests, notices, inputSaves, fullSaves, render, click, editName, complete, find,
    holdNextDetail: () => { holdDetail = true; }, stop: () => scope.stop() };
}


test('normal calculation and subsequent exact history edit preserve decimal amounts and zero', async t => {
  const f = fixture(); t.after(f.stop); await f.render(); const pending = f.click('run'); await flush();
  assert.equal(f.posts[0].body.input.decorationHardCost, 12345.67);
  assert.equal(f.posts[0].body.input.operatingScenario.opening_cash, 0);
  await f.render(); assert.equal(f.click('run'), null); assert.equal(f.posts.length, 1);
  f.complete(0); await pending; await flush();
  assert.equal(f.state.aiSimulationRecordId.value, 101); assert.equal(f.state.aiSimulationLoading.value, false);
  await f.render(); await f.click('view'); await flush(); await f.render(); f.editName('B 另存'); await flush();
  const next = f.click('run'); await flush(); assert.equal(f.posts[1].body.input.operatingScenario.case_name, 'B 另存');
  assert.notEqual(f.posts[1].body.client_request_id, f.posts[0].body.client_request_id);
  f.complete(1); await next; assert.equal(f.state.aiSimulationRecordId.value, 102);
  assert.equal(f.state.aiSimulationParams.value.decorationHardCost, 23456.78);
  assert.equal(f.state.aiSimulationLoading.value, false);
});

for (const button of ['view', 'reuse']) {
  test(`successful history ${button} stays selected after the older save completes, with old save recoverable`, async t => {
    const f = fixture(); t.after(f.stop); await f.render(); const oldSave = f.click('run'); await flush(); await f.render();
    assert.equal(Boolean(f.find(node => node.props?.['data-testid'] === `history-simulation-${button}-202`).props.disabled), false);
    await f.click(button); await flush();
    assert.equal(f.state.aiSimulationRecordId.value, 202); assert.equal(f.state.aiSimulationLoading.value, false);
    f.complete(0); await oldSave; await flush();
    assert.equal(f.state.aiSimulationRecordId.value, 202);
    assert.equal(f.state.aiSimulationParams.value.operatingScenario.case_name, '合成历史方案 B');
    assert.equal(f.state.aiSimulationParams.value.decorationHardCost, 23456.78);
    assert.equal(f.notices.some(item => /取消/.test(item.message)), false);
    // Original A was committed in the synthetic store; use the actual existing history refresh/view controls.
    await f.render(); await f.click('refresh');
    assert.equal(f.state.aiSimulationRecordId.value, 202);
    const savedA = f.state.aiSimulationRecords.value.find(item => item.id === 101);
    assert.ok(savedA); await f.render(savedA); await f.click('view', 101); await flush();
    assert.equal(f.state.aiSimulationRecordId.value, 101); assert.equal(f.posts.length, 1);
    assert.ok(f.gets.includes('/simulation/records/101'));
  });
}

test('save returning before the later requested history still lets history selection finish', async t => {
  const f = fixture(); t.after(f.stop); await f.render(); const saving = f.click('run'); await flush();
  f.holdNextDetail(); await f.render(); const opening = f.click('view'); await flush();
  f.complete(0); await saving; await flush(); assert.equal(f.state.aiSimulationRecordId.value, 101);
  f.detailRequests[0].resolve({ code: 200, data: clone(f.historical) }); await opening; await flush();
  assert.equal(f.state.aiSimulationRecordId.value, 202); assert.equal(f.state.aiSimulationLoading.value, false);
});

test('history read failure retains the valid current state and allows the current save and history retry', async t => {
  const f = fixture(); t.after(f.stop);
  f.state.aiSimulationResult.value = { marker: 'previous-valid-result' };
  await f.render(); const saving = f.click('run'); await flush();
  f.holdNextDetail(); await f.render(); const opening = f.click('reuse'); await flush();
  f.detailRequests[0].reject(new Error('合成详情中断')); await opening;
  assert.equal(f.state.aiSimulationResult.value.marker, 'previous-valid-result');
  assert.equal(f.state.aiSimulationParams.value.operatingScenario.case_name, '合成方案 A');
  assert.equal(f.state.aiSimulationLoading.value, true); assert.match(f.notices[0].message, /合成详情中断/);
  f.complete(0); await saving; await flush();
  assert.equal(f.state.aiSimulationRecordId.value, 101); assert.equal(f.state.aiSimulationLoading.value, false);
  await f.render(); await f.click('reuse'); await flush(); assert.equal(f.state.aiSimulationRecordId.value, 202);
});

for (const order of ['history-first', 'save-first']) {
  test(`newer run owns the input when an older detail read returns: ${order}`, async t => {
    const f = fixture(); t.after(f.stop); f.holdNextDetail(); await f.render(); const opening = f.click('view'); await flush();
    await f.render(); const saving = f.click('run'); await flush(); assert.equal(f.posts.length, 1);
    if (order === 'history-first') {
      f.detailRequests[0].resolve({ code: 200, data: clone(f.historical) }); await opening;
      assert.equal(f.state.aiSimulationLoading.value, true);
      assert.equal(f.state.aiSimulationParams.value.operatingScenario.case_name, '合成方案 A');
    }
    f.complete(0); await saving; await flush(); assert.equal(f.state.aiSimulationRecordId.value, 101);
    if (order === 'save-first') { f.detailRequests[0].resolve({ code: 200, data: clone(f.historical) }); await opening; }
    assert.equal(f.state.aiSimulationRecordId.value, 101); assert.equal(f.state.aiSimulationLoading.value, false);
    assert.equal(f.state.aiSimulationParams.value.operatingScenario.case_name, '合成方案 A');
  });
}

test('a failed older detail cannot display an unrelated error over the newer calculation', async t => {
  const f = fixture(); t.after(f.stop); f.holdNextDetail(); await f.render(); const opening = f.click('view'); await flush();
  await f.render(); const saving = f.click('run'); await flush();
  f.detailRequests[0].reject(new Error('旧详情中断')); await opening;
  assert.equal(f.notices.length, 0); assert.equal(f.state.aiSimulationLoading.value, true);
  f.complete(0); await saving; assert.equal(f.state.aiSimulationRecordId.value, 101); assert.equal(f.state.aiSimulationLoading.value, false);
});

test('old save finally cannot clear loading for a new save started from the selected historical input', async t => {
  const f = fixture(); t.after(f.stop); await f.render(); const first = f.click('run'); await flush();
  await f.render(); await f.click('reuse'); await flush(); await f.render(); const second = f.click('run'); await flush();
  assert.equal(f.posts.length, 2); assert.equal(f.posts[1].body.input.operatingScenario.case_name, '合成历史方案 B');
  f.complete(0); await first;
  assert.equal(f.state.aiSimulationLoading.value, true); assert.equal(f.state.aiSimulationRecordId.value, 202);
  await f.render(); assert.equal(f.click('run'), null);
  f.complete(1); await second; assert.equal(f.state.aiSimulationRecordId.value, 102); assert.equal(f.state.aiSimulationLoading.value, false);
});

test('old save rejection after history selection leaves the new result and new request untouched', async t => {
  const f = fixture(); t.after(f.stop); await f.render(); const first = f.click('run'); await flush();
  await f.render(); await f.click('view'); await flush(); await f.render(); const second = f.click('run'); await flush();
  f.posts[0].reject(new Error('旧保存合成中断')); await first;
  assert.equal(f.state.aiSimulationLoading.value, true); assert.equal(f.state.aiSimulationRecordId.value, 202);
  assert.equal(f.notices.some(item => /旧保存合成中断/.test(item.message)), false);
  f.complete(1); await second; assert.equal(f.state.aiSimulationRecordId.value, 102);
});

test('new hotel selection and calculation retain the existing old-finally isolation', async t => {
  const f = fixture(); t.after(f.stop); await f.render(); const first = f.click('run'); await flush(); await f.render();
  f.find(node => node.props?.['data-testid'] === 'simulation-hotel-selector').props.onChange({ target: { value: '8' } });
  await flush(); await f.render(); const second = f.click('run'); await flush(); assert.equal(f.posts[1].body.hotel_id, 8);
  f.complete(0); await first; assert.equal(f.state.aiSimulationLoading.value, true);
  f.complete(1); await second; assert.equal(f.state.aiSimulationParams.value.hotel_id, 8); assert.equal(f.state.aiSimulationLoading.value, false);
});

test('current network failure keeps inputs and same-payload retry identity', async t => {
  const f = fixture(); t.after(f.stop); await f.render(); const first = f.click('run'); await flush();
  f.posts[0].reject(new Error('合成网络失败')); await first;
  assert.equal(f.state.aiSimulationParams.value.operatingScenario.case_name, '合成方案 A'); assert.equal(f.state.aiSimulationLoading.value, false);
  await f.render(); const retry = f.click('run'); await flush(); assert.equal(f.posts[1].body.client_request_id, f.posts[0].body.client_request_id);
  f.complete(1); await retry; assert.equal(f.state.aiSimulationRecordId.value, 102);
});
