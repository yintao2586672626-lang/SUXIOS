import assert from 'node:assert/strict';
import test, { after } from 'node:test';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import { createHash, webcrypto } from 'node:crypto';
import * as Vue from 'vue';
import { baseParse } from '@vue/compiler-core';
import { compile, parserOptions } from '@vue/compiler-dom';

// Original simulation controls/handlers with a synthetic request port and memory
// storage. This is not HTTP, PHP calculation, database persistence or a browser.
const option = name => process.argv.find(arg => arg.startsWith(`--${name}=`))?.slice(name.length + 3);
const repository = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const root = path.resolve(option('source-root') || repository);
const readers = [], sections = [], ancestorPaths = [], attempts = [];
const hash = value => createHash('sha256').update(value).digest('hex').toUpperCase();
const read = relative => {
  const resolved = path.join(root, relative), bytes = fs.readFileSync(resolved);
  readers.push({ path: relative, resolved_path: resolved, sha256: hash(bytes) });
  return bytes.toString('utf8');
};
const main = read('public/app-main.js'), staticSource = read('public/simulation-static.js');
const template = read('resources/frontend/templates/fragments/02-page-ai-simulation.html');
const components = read('public/components/system/app-main-components.js');
function cut(source, start, end) {
  const a = source.indexOf(start), b = source.indexOf(end, a + start.length);
  assert.ok(a >= 0 && b > a, start);
  const value = source.slice(a, b); sections.push({ start, end, sha256: hash(value) }); return value;
}
function statement(start) {
  const lines = main.split('\n').filter(line => line.trim().startsWith(start));
  assert.equal(lines.length, 1, start); sections.push({ start, sha256: hash(lines[0]) }); return lines[0];
}
const inputWatchStart = main.includes('watch([aiSimulationParams, () => simulationDraft.value.name]')
  ? '            watch([aiSimulationParams, () => simulationDraft.value.name]' : '            watch(aiSimulationParams, () => {';
const methods = [
  statement('let pageRequestGeneration ='),
  cut(main, '            const captureAuthSession =', '            const createDefaultAuthContext ='),
  cut(main, '            const simulationStaticScript =', '            const ensureSimulationStaticReady ='),
  cut(main, '            const simulationStaticOption =', '            const requireSimulationStaticFunction ='),
  cut(main, '            const defaultSimulationInput =', '            const simulationCostFields ='),
  cut(main, '            function saveSimulationState(', '            const simulationExportLoadingId ='),
  cut(main, '            const reuseSimulationRecord =', '            const operatingScenarioFields ='),
  statement('const hydrateSimulationStateFromStorage ='),
  cut(main, inputWatchStart, '            const baseSimulation = computed'),
].join('\n');
const heroSource = cut(components, '    const SimulationHeroActions = {', '        return Object.freeze({');
const attr = (node, key) => node.props?.find(p => p.type === 6 && p.name === key)?.value?.content;
const ast = baseParse(template, parserOptions);
function retain(node, parents = []) {
  if (node.type !== 1) return null;
  const src = node.loc.source;
  const target = node.tag === 'simulation-hero-actions'
    || ['simulation-record-context', 'simulation-legacy-name', 'scenario-name', 'field-simulation-room-count'].includes(attr(node, 'data-testid'))
    || (src.startsWith('<div v-else') || src.startsWith('<p v-else')) && src.includes('当前为旧版单月测算')
    || node.tag === 'button' && /@click="(?:loadSimulationDetail\(record.id\)|reuseSimulationRecord\(record\)|archiveSim\(record\))"/.test(src)
    || src.startsWith('<div class="font-medium text-gray-900">{{ record.project_name')
    || src.startsWith('<div class="text-xs text-gray-500 mt-1">{{ simulationRecordSummary(record) }}')
    || src.startsWith('<div v-else="" class="py-8 text-center text-gray-400">暂无历史记录');
  if (target) { ancestorPaths.push(parents.concat(node).map(n => n.loc.source.slice(0, n.loc.source.indexOf('>') + 1))); return node; }
  const children = (node.children || []).map(n => retain(n, parents.concat(node))).filter(Boolean);
  return children.length ? { ...node, children } : null;
}
ast.children = ast.children.map(node => retain(node)).filter(Boolean);
assert.ok(ancestorPaths.some(p => p.some(s => s.includes("currentPage === 'ai-simulation'"))));
assert.ok(ancestorPaths.some(p => p.some(s => s.includes('v-for="record in aiSimulationRecords"'))));
const renderCode = compile(ast, { mode: 'function', prefixIdentifiers: true }).code;
if (option('prepare-only') === 'true') {
  compile(template, { mode: 'function', prefixIdentifiers: true });
  console.log(JSON.stringify({ prepare_only: true, readers, sections: sections.length, retained_ancestors: ancestorPaths.length, behavior: 0 }));
  process.exit(0);
}
const render = new Function('Vue', renderCode)(Vue);
const clone = value => JSON.parse(JSON.stringify(value));
const tick = async () => { await Vue.nextTick(); await new Promise(resolve => setTimeout(resolve, 0)); await Vue.nextTick(); };
const documentBefore = globalThis.Document, shadowBefore = globalThis.ShadowRoot;
globalThis.Document ??= class MemoryDocument {};
globalThis.ShadowRoot ??= class MemoryShadowRoot {};
after(() => {
  if (documentBefore === undefined) delete globalThis.Document; else globalThis.Document = documentBefore;
  if (shadowBefore === undefined) delete globalThis.ShadowRoot; else globalThis.ShadowRoot = shadowBefore;
  if (option('evidence')) fs.writeFileSync(option('evidence'), JSON.stringify({ readers, sections, ancestorPaths, attempts,
    boundary: 'Original handlers/native v-model in a memory renderer; synthetic request port and storage, no HTTP/PHP/DB/browser or host storage.' }, null, 2) + '\n');
});
function memoryHost() {
  const node = (type, text = '') => ({ type, text, props: {}, children: [], parent: null, style: {}, value: '', listeners: {},
    addEventListener(name, fn) { (this.listeners[name] ||= []).push(fn); },
    removeEventListener(name, fn) { this.listeners[name] = (this.listeners[name] || []).filter(value => value !== fn); },
    dispatchEvent(event) { for (const listener of this.listeners[event.type] || []) listener({ ...event, target: this }); },
    getRootNode() { let root = this; while (root.parent) root = root.parent; return root; },
  });
  const remove = n => { if (n.parent) n.parent.children.splice(n.parent.children.indexOf(n), 1); n.parent = null; };
  return { root: node('root'), options: {
    createElement: tag => node(tag), createText: text => node('text', text), createComment: text => node('comment', text),
    setText: (n, text) => { n.text = text; }, setElementText: (n, text) => { n.children = []; n.text = text; },
    parentNode: n => n.parent, nextSibling: n => n.parent?.children[n.parent.children.indexOf(n) + 1] || null,
    insert(n, parent, anchor = null) { remove(n); n.parent = parent; const i = anchor ? parent.children.indexOf(anchor) : -1; if (i < 0) parent.children.push(n); else parent.children.splice(i, 0, n); },
    remove, patchProp(n, key, old, value) { n.props[key] = value; if (key === 'value') { n.value = value; n._value = value; } },
  } };
}
function harness() {
  const requests = [], notices = [], errors = [], warnings = [], actions = [], memory = new Map(), checkpoints = [];
  const sandbox = {
    window: {}, JSON, setTimeout, clearTimeout, crypto: webcrypto, ref: Vue.ref, computed: Vue.computed, watch: Vue.watch, h: Vue.h,
    token: Vue.ref('synthetic-session-round134'), user: Vue.ref({ id: 77 }), authSessionEpoch: 1,
    currentPage: Vue.ref('ai-simulation'), operationHotelOptions: Vue.ref([{ id: 7, name: '合成门店七' }, { id: 8, name: '合成门店八' }]),
    aiProject: Vue.ref({ project_name: '另一个全局项目，不能拿来命名旧方案' }),
    localStorage: { getItem: key => memory.get(key) ?? null, setItem: (key, value) => memory.set(key, String(value)), removeItem: key => memory.delete(key) },
    confirm: () => true, showToast: (message, type = 'success') => notices.push({ message, type }),
    getHotelNameById: id => ({ 7: '合成门店七', 8: '合成门店八' }[id] || ''), formatCurrency: value => value == null ? '--' : String(value),
    isStillOnRequestPage: page => page === sandbox.currentPage.value,
    fetch: () => { throw new Error('Real HTTP forbidden'); },
  };
  vm.runInNewContext(staticSource, sandbox);
  const api = sandbox.window.SUXI_SIMULATION_STATIC;
  sandbox.simulationStatic = Vue.ref(api); sandbox.hasSimulationStatic = Vue.computed(() => true);
  sandbox.ensureSimulationStaticReady = async () => api; // Module already loaded, no loader/network evidence claimed.
  sandbox.normalizeSimulationInput = api.normalizeSimulationInput;
  sandbox.normalizeSimulationModelAnalysis = api.normalizeSimulationModelAnalysis;
  sandbox.generateRiskHints = api.generateRiskHints;
  function input(hotelId = 7, modern = false) {
    const value = clone(api.defaultSimulationInput);
    for (const key of Object.keys(value)) if (typeof value[key] === 'number') value[key] = 0;
    Object.assign(value, { hotel_id: hotelId, input_source_status: 'manual_unverified', roomCount: 10, adr: 100,
      weekdayAdr: 100, weekdayDays: 29, decorationInvestment: 12345.67, decorationHardCost: 12345.67 });
    value.operatingScenario = modern ? { ...api.createOperatingScenario(), case_name: '已存现代方案', start_month: '2024-02',
      horizon_months: 1, target_payback_months: 1, ramp_months: 0, ramp_start_occupancy: 0, loan_amount: 0,
      annual_interest_rate: 0, loan_term_months: 0, source_note: '纯合成假设，未核验' } : null;
    if (hotelId) assert.equal(api.validateSimulationInput(value), '');
    else assert.match(api.validateSimulationInput(value), /请选择当前账号有权限的酒店/);
    return value;
  }
  function record(id, name, value, createdAt = '2026-09-10 09:00:00') {
    const normalized = api.normalizeSimulationInput(value);
    if (normalized.operatingScenario) normalized.operatingScenario = clone(api.normalizedOperatingScenario(normalized.operatingScenario));
    return { id, project_name: name, created_at: createdAt, input: clone(normalized),
      result: { monthlyRevenue: 0, monthlyCost: 0, monthlyNetCashflow: 0, paybackMonths: null },
      scenarios: [{ scenarioType: '基准情景', monthlyRevenue: 0, monthlyNetCashflow: 0 }], risk_hints: [], model_analysis: null,
      truth_context: { hotel_id: Number(value.hotel_id) || null, tenant_id: 70, status: 'unverified', persistence: { readback_verified: true } },
      access_policy: { mode: value.hotel_id ? 'hotel_scoped' : 'legacy_read_only', mutation_allowed: value.hotel_id ? null : false } };
  }
  const saved = new Map([[202, record(202, '历史方案 R', input())], [303, record(303, '已存现代方案', input(7, true))], [204, record(204, '未绑定旧记录', input(null), '')]]);
  let holdDetail = false;
  sandbox.request = (url, options = {}) => {
    const method = options.method || 'GET', body = options.body ? JSON.parse(options.body) : null;
    assert.ok((method === 'POST' && url === '/simulation/calculate') || (['GET', 'DELETE'].includes(method) && /^\/simulation\/records(?:\/\d+)?$/.test(url)));
    const entry = { url, method, body, settled: false }; requests.push(entry);
    return new Promise((resolve, reject) => {
      entry.reply = data => { assert.equal(entry.settled, false); entry.settled = true; resolve({ code: 200, message: 'success', data }); };
      entry.fail = message => { assert.equal(entry.settled, false); entry.settled = true; entry.failure = message; reject(new Error(message)); };
      if (method === 'POST') return;
      const id = Number(url.match(/\/(\d+)$/)?.[1]);
      if (method === 'DELETE') { saved.delete(id); entry.reply({ id }); }
      else if (url === '/simulation/records') entry.reply({ list: [...saved.values()].sort((a, b) => b.id - a.id).map(clone) });
      else if (holdDetail) holdDetail = false;
      else { assert.ok(saved.has(id), 'Synthetic saved detail exists'); entry.reply(clone(saved.get(id))); }
    });
  };
  // The untouched optional commission panel receives this port but is never
  // mounted or used; the original hero still renders its full native controls.
  sandbox.managerCapabilityRequest = sandbox.request;
  const scope = Vue.effectScope();
  scope.run(() => vm.runInNewContext(methods + `\nglobalThis.ui = {
    aiSimulationParams, aiSimulationResult, aiSimulationScenarios, aiSimulationRecords, aiSimulationRecordId, aiSimulationLoading,
    simulationHotelSelectionValid, handleSimulation, loadSimulationDetail, reuseSimulationRecord, loadSimulationRecords,
    archiveSim, canArchiveSim, simulationArchivePending, simulationRecordSummary, hydrateSimulationStateFromStorage,
    ...(typeof simulationDraft === 'undefined' ? {} : { simulationDraft }),
    ...(typeof simulationHistoryState === 'undefined' ? {} : { simulationHistoryState }) };`, sandbox));
  vm.runInNewContext(heroSource + '\nglobalThis.hero = SimulationHeroActions;', sandbox);
  const ui = sandbox.ui;
  const exposed = { ...sandbox, ...ui };
  for (const name of ['handleSimulation', 'loadSimulationDetail', 'reuseSimulationRecord', 'loadSimulationRecords', 'archiveSim']) {
    exposed[name] = (...args) => { const promise = ui[name](...args); actions.push({ name, promise }); return promise; };
  }
  const host = memoryHost(), renderer = Vue.createRenderer(host.options);
  const app = renderer.createApp({ setup: () => exposed, render });
  app.component('SimulationHeroActions', sandbox.hero);
  app.config.warnHandler = message => warnings.push(message); app.config.errorHandler = error => errors.push(error?.stack || String(error));
  app.mount(host.root);
  const walk = n => [n, ...n.children.flatMap(walk)];
  const text = n => n.type === 'comment' ? '' : n.text + n.children.map(text).join('');
  const nodes = () => walk(host.root);
  const find = id => nodes().find(node => node.props['data-testid'] === id);
  const available = node => { assert.ok(node, 'original visible control exists'); for (let p = node; p; p = p.parent) assert.ok(!p.props.disabled && p.style.display !== 'none'); return node; };
  const button = name => nodes().find(node => node.type === 'button' && (node.props['data-testid'] === name || text(node) === name));
  return { ui, saved, requests, notices, warnings, errors, checkpoints, memory, globalProject: sandbox.aiProject,
    find, text: () => text(host.root), contextText: () => text(available(find('simulation-record-context'))),
    async click(name) { const n = available(button(name)), before = actions.length; n.props.onClick({ target: n }); await tick(); assert.equal(actions.length, before + 1); return { completion: actions.at(-1).promise }; },
    async edit(id, value) { const n = available(find(id)); n.value = String(value); n.dispatchEvent({ type: 'input' }); await tick(); },
    button, holdNextDetail: () => { holdDetail = true; },
    completeSave(entry, id) { assert.equal(entry.method, 'POST'); const item = record(id, entry.body.project_name, entry.body.input, '2026-09-20 11:22:33'); saved.set(id, item); entry.reply(clone(item)); return item; },
    snapshot: () => ({ requests: requests.map(({ reply, fail, ...entry }) => entry), notices, errors, warnings, checkpoints,
      text: text(host.root), record_id: ui.aiSimulationRecordId.value, input: clone(ui.aiSimulationParams.value),
      draft: ui.simulationDraft ? clone(ui.simulationDraft.value) : null, pending: requests.filter(entry => !entry.settled).length }),
    async stop() { app.unmount(); scope.stop(); for (const entry of requests.filter(entry => !entry.settled)) { entry.teardown_only = true; entry.fail('synthetic teardown'); } await tick(); },
  };
}
function scenario(name, body) {
  test(name, async () => {
    let h; const attempt = { name }; attempts.push(attempt);
    try {
      h = harness(); await body(h); await tick();
      assert.deepEqual(h.errors, []); assert.deepEqual(h.warnings, []);
      assert.ok(h.requests.every(entry => entry.settled && !entry.teardown_only)); attempt.passed = true;
    } catch (error) { attempt.failure = error.stack; throw error; }
    finally { if (h) { attempt.before_teardown = h.snapshot(); await h.stop(); attempt.after_teardown = h.snapshot(); } }
  });
}
async function open(h, id, reuse = false) {
  const action = await h.click(`history-simulation-${reuse ? 'reuse' : 'view'}-${id}`); await action.completion; await tick();
}
async function refresh(h) { const action = await h.click('刷新历史'); await action.completion; await tick(); }
scenario('saved legacy name/context -> native edits -> failed retry and new saved identity; modern case_name stays independent', async h => {
  await refresh(h); await open(h, 202);
  assert.equal(h.find('simulation-legacy-name').value, '历史方案 R');
  assert.match(h.contextText(), /当前已读取记录 #202.*历史方案 R/);
  assert.match(h.contextText(), /合成门店七.*2026-09-10 09:00:00/);
  assert.equal(h.ui.aiSimulationResult.value.monthlyNetCashflow, 0);
  await h.edit('field-simulation-room-count', 61); await h.edit('simulation-legacy-name', 'R 调整稿');
  assert.match(h.contextText(), /基于记录 #202 的未保存修改/);
  const first = await h.click('运行三情景模拟'), post = h.requests.at(-1);
  assert.equal(post.method, 'POST'); assert.equal(post.body.project_name, 'R 调整稿'); assert.equal(post.body.hotel_id, 7);
  assert.equal(post.body.input.roomCount, 61); assert.equal(post.body.input.operatingScenario, null);
  assert.equal(post.body.input.decorationHardCost, 12345.67); assert.equal(post.body.input.weekendDays, 0);
  post.fail('合成保存连接中断'); await first.completion; await tick();
  assert.equal(h.ui.aiSimulationRecordId.value, 202); assert.match(h.contextText(), /未保存修改/);
  const retry = await h.click('运行三情景模拟'), retryPost = h.requests.at(-1);
  assert.equal(retryPost.body.client_request_id, post.body.client_request_id);
  h.completeSave(retryPost, 301); await retry.completion; await tick();
  assert.match(h.contextText(), /当前已读取记录 #301.*R 调整稿/); assert.match(h.contextText(), /2026-09-20 11:22:33/);
  assert.equal(h.saved.get(202).project_name, '历史方案 R'); assert.equal(h.ui.aiSimulationRecords.value.some(r => r.id === 301), true);
  await open(h, 303, true); assert.equal(h.find('simulation-legacy-name'), undefined);
  assert.match(h.contextText(), /2024-02.*1 个月/); assert.match(h.contextText(), /2026-09-10 09:00:00/);
  await h.edit('scenario-name', '现代方案另存');
  const modern = await h.click('运行三情景模拟'), modernPost = h.requests.at(-1);
  assert.equal(modernPost.body.project_name, '现代方案另存'); assert.equal(modernPost.body.input.operatingScenario.start_month, '2024-02');
  h.completeSave(modernPost, 302); await modern.completion; await tick();
  assert.match(h.contextText(), /当前已读取记录 #302/);
  assert.equal(h.globalProject.value.project_name, '另一个全局项目，不能拿来命名旧方案');
});
scenario('legacy name edits invalidate an older detail and save; current retry keeps its own request identity', async h => {
  await refresh(h); await open(h, 202); h.holdNextDetail();
  const opening = await h.click('history-simulation-reuse-303'), detail = h.requests.at(-1);
  await h.edit('simulation-legacy-name', 'B 新稿'); detail.reply(clone(h.saved.get(303))); await opening.completion; await tick();
  assert.equal(h.ui.aiSimulationRecordId.value, 202); assert.equal(h.find('simulation-legacy-name').value, 'B 新稿');
  const savingB = await h.click('运行三情景模拟'), postB = h.requests.at(-1);
  await h.edit('simulation-legacy-name', 'C 后稿');
  const savingC = await h.click('运行三情景模拟'), postC = h.requests.at(-1);
  assert.notEqual(postB.body.client_request_id, postC.body.client_request_id);
  h.completeSave(postB, 401); await savingB.completion; await tick();
  assert.equal(h.ui.aiSimulationLoading.value, true); assert.equal(h.ui.aiSimulationRecordId.value, 202);
  assert.equal(h.find('simulation-legacy-name').value, 'C 后稿');
  postC.fail('合成当前保存失败'); await savingC.completion; await tick();
  const retry = await h.click('运行三情景模拟'), retryPost = h.requests.at(-1);
  assert.equal(retryPost.body.client_request_id, postC.body.client_request_id); assert.equal(retryPost.body.project_name, 'C 后稿');
  h.completeSave(retryPost, 402); await retry.completion; await tick();
  assert.match(h.contextText(), /当前已读取记录 #402.*C 后稿/);
  h.holdNextDetail(); const failedRead = await h.click('history-simulation-view-202'); h.requests.at(-1).fail('合成详情失败');
  await failedRead.completion; await tick(); assert.match(h.contextText(), /当前已读取记录 #402/);
  assert.equal(h.notices.at(-1).type, 'error');
});
scenario('missing hotel/date stay explicit; original archive and storage hydration remove current-record association', async h => {
  assert.match(h.contextText(), /未关联已保存记录/);
  await refresh(h); await open(h, 204);
  assert.match(h.contextText(), /未绑定酒店.*保存时间：未记录/);
  assert.ok(h.button('运行三情景模拟').props.disabled); assert.equal(h.button('history-simulation-archive-204'), undefined);
  assert.equal(h.requests.some(entry => entry.method === 'POST'), false);
  await open(h, 202);
  const archived = await h.click('history-simulation-archive-202'); await archived.completion; await tick();
  assert.equal(h.ui.aiSimulationRecordId.value, null); assert.match(h.contextText(), /未关联已保存记录/);
  assert.equal(h.find('simulation-legacy-name').value, '历史方案 R'); assert.equal(h.saved.has(202), false);
  await open(h, 303);
  // Direct original activation boundary; only in-memory storage is read. No
  // saved identity is present in the original storage format or invented here.
  h.ui.hydrateSimulationStateFromStorage(); await tick();
  assert.equal(h.ui.aiSimulationRecordId.value, null); assert.equal(h.ui.simulationDraft.value.record, null);
  assert.match(h.contextText(), /未关联已保存记录/); assert.equal(h.ui.simulationDraft.value.name, '');
  assert.equal(h.ui.aiSimulationResult.value.monthlyNetCashflow, 0);
});
