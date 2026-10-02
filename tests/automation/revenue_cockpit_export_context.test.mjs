import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';
import * as Vue from 'vue';
import { parse, compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';

// This export test starts from an already loaded synthetic canonical model.
// No HTTP, backend attestation, persistence, browser or customer download occurs.
const repository = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const option = name => process.argv.find(value => value.startsWith(`--${name}=`))?.slice(name.length + 3);
const sourceRoot = path.resolve(option('source-root') || repository);
const supportRoot = path.resolve(option('support-root') || repository);
const readers = [];
const read = relative => {
  const preferred = path.join(sourceRoot, relative);
  const resolved = fs.existsSync(preferred) ? preferred : path.join(supportRoot, relative);
  const bytes = fs.readFileSync(resolved);
  readers.push({ path: relative, resolved_path: resolved, sha256: createHash('sha256').update(bytes).digest('hex').toUpperCase() });
  return bytes.toString('utf8');
};
const main = read('public/app-main.js');
const template = read('resources/frontend/templates/fragments/27-page-agent-center.html');
const statics = [
  'public/revenue-overview-contract-static.js',
  'public/revenue-cockpit-static.js',
  'public/revenue-ai-static.js',
].map(relative => [relative, read(relative)]);
const priorTests = read('tests/automation/revenue_ai_static.test.mjs');
const cut = (text, start, end) => {
  const a = text.indexOf(start), b = text.indexOf(end, a);
  assert.ok(a >= 0 && b > a, start);
  return text.slice(a, b);
};
const modelMethods = cut(main, '            const revenueCockpitLiveModel = computed', '            const setRevenueLoadState =');
const downloadHandler = cut(main, '            const downloadRevenueCockpit =', '            const openRevenueCockpitOperatingQuestion =');
const blobHandler = cut(main, '            const downloadBlob =', '            const buildCtripBusinessCanvas =');
const helperBridge = main.split(/\r?\n/).filter(line => line.includes('const revenueAiRunCockpitHelper ='));
assert.equal(helperBridge.length, 1);

// Prune siblings only: every original ancestor and its v-if/disabled contract survives.
const ast = parse(template);
let buttons = 0;
const keepDownload = node => {
  if (node.type === 1 && node.props.some(prop => prop.type === 6
    && prop.name === 'data-testid' && prop.value?.content === 'revenue-cockpit-download')) {
    buttons++;
    return node;
  }
  const children = (node.children || []).map(keepDownload).filter(Boolean);
  return children.length ? { ...node, children } : null;
};
const pruned = keepDownload(ast);
assert.equal(buttons, 1);
const renderDownload = new Function('Vue', compile(pruned, { mode: 'function', prefixIdentifiers: true }).code)(Vue);
const flatten = node => !node || typeof node !== 'object' ? []
  : [node, ...(Array.isArray(node.children) ? node.children.flatMap(flatten) : [])];

// Reuse the existing public-contract synthetic fixture; no application/PHP fixture runner.
const priorCase = priorTests.indexOf("test('daily revenue cockpit accepts only the exact server-issued canonical model and digest'");
assert.ok(priorCase >= 0);
const fixtureText = cut(priorTests.slice(priorCase), '  const clone =', '  const expected =');
const originalOverview = new Function(`${fixtureText}\nreturn overview;`)();
const clone = value => JSON.parse(JSON.stringify(value));
function exportOverview() {
  const overview = clone(originalOverview), model = overview.canonical_view_model;
  model.hotelName = '合成酒店, "东楼"';
  model.scopeBoundary = '所选 OTA 渠道, 不外推"全酒店"\n保留来源边界';
  model.dateNotice = '业务日比固定数据基准日早 1 天。';
  const zero = { ...model.visibleSections[0].cards[0], label: '已核验零值', display: '0', evidenceLines: ['synthetic, "原引用"\n第二行'] };
  const missing = { ...zero, key: 'missing', label: '缺失指标', display: '—', statusLabel: '缺失/未验证', missingState: 'missing_readback', reasonText: '缺少严格回读，不能填0。' };
  model.sections = [{ key: 'core_metrics', title: '核心指标', cards: [zero, missing] }];
  model.visibleSections = model.sections;
  const metric = (key, value) => ({
    key, label: key === 'zero' ? '账目真实零' : '账目缺失', platform: 'ctrip', value,
    partial_value: null, status: value === null ? 'missing' : 'ready', scope: 'ota_channel',
    definition: '合成渠道金额', formula: '原可用日期汇总', covered_dates: value === null ? [] : [model.businessDate],
    missing_dates: value === null ? [model.businessDate] : [], days: [], source_refs: ['synthetic#101'],
  });
  model.operatingLedger = { version: 'synthetic-ledger-v1', scope: { start_date: model.businessDate,
    end_date: model.businessDate, evidence_mode: 'synthetic' }, metrics: [metric('zero', 0), metric('missing', null)], differences: [] };
  return overview;
}

function harness(t) {
  const downloads = [], toasts = [], timers = [], urls = new Map(), errors = [];
  let tree, attemptedRequests = 0;
  const overview = Vue.ref(exportOverview());
  const sandbox = {
    ...Vue, Blob, URL: class extends URL {}, URLSearchParams, window: {},
    filterReportHotel: Vue.ref('80'), revenueCockpitOverview: overview,
    revenueCockpitPlatform: Vue.ref('all_ota'), revenueCockpitBusinessDate: Vue.ref('2026-08-20'),
    revenueCockpitLoading: Vue.ref(false), revenueLoadState: Vue.ref({ cockpit: { status: 'ready', error: '' } }),
    currentPage: Vue.ref('agent-center'), agentTab: Vue.ref('revenue'), revenueAgentTab: Vue.ref('analysis'),
    getHotelName: id => `合成酒店${id}`,
    showToast: (message, type = 'success') => toasts.push({ message, type }),
    fetch: () => { attemptedRequests++; throw new Error('HTTP is forbidden in this export test'); },
    request: () => { attemptedRequests++; throw new Error('Backend reads/writes are forbidden in this export test'); },
    document: {
      createElement(tag) {
        assert.equal(tag, 'a');
        return { style: {}, isConnected: false, click() {
          assert.ok(urls.has(this.href));
          downloads.push({ blob: urls.get(this.href), fileName: this.download });
        }, remove() { this.isConnected = false; } };
      },
      body: { appendChild(link) { link.isConnected = true; } },
    },
  };
  sandbox.URL.createObjectURL = blob => { const url = `blob:synthetic/${urls.size}`; urls.set(url, blob); return url; };
  sandbox.URL.revokeObjectURL = url => urls.delete(url);
  sandbox.window.setTimeout = (fn, ms) => { timers.push({ fn, ms }); return timers.length; };
  for (const [filename, source] of statics) vm.runInNewContext(source, sandbox, { filename });
  const helpers = sandbox.window.SUXI_REVENUE_AI_STATIC;
  Object.assign(sandbox, {
    revenueAiBuildCockpitModel: helpers.buildRevenueCockpitModel,
    revenueAiResolveCockpitCanonicalViewModel: helpers.resolveRevenueCockpitCanonicalViewModel,
  });
  vm.runInNewContext(`${helperBridge[0]}\n${modelMethods}\n${blobHandler}\n${downloadHandler}
    globalThis.ui = { revenueCockpitLiveModel, revenueCockpitModel, downloadRevenueCockpit };`, sandbox);
  const ui = sandbox.ui;
  async function button() {
    const app = Vue.createSSRApp({
      setup: () => ({ ...ui, currentPage: sandbox.currentPage, agentTab: sandbox.agentTab,
        revenueAgentTab: sandbox.revenueAgentTab }),
      render(...args) { tree = renderDownload.apply(this, args); return tree; },
    });
    app.config.warnHandler = message => { errors.push(message); };
    app.config.errorHandler = error => { errors.push(error.message); };
    const html = await renderToString(app);
    assert.match(html, /按当前页面下载/);
    assert.deepEqual(errors, []);
    const nodes = flatten(tree).filter(node => node.props?.['data-testid'] === 'revenue-cockpit-download');
    assert.equal(nodes.length, 1, 'Original ancestor conditions render one visible download button');
    return nodes[0];
  }
  t.after(() => {
    // Run captured resource cleanup only; no claim about elapsed native browser time.
    for (const timer of timers) { assert.equal(timer.ms, 60000); timer.fn(); }
    assert.equal(urls.size, 0);
    assert.equal(attemptedRequests, 0);
    assert.deepEqual(errors, []);
    t.diagnostic(JSON.stringify({ sourceReaders: readers, blobs: downloads.length, networkRequests: attemptedRequests,
      pendingObjectUrls: urls.size, vueErrors: errors.length, evidence: 'synthetic existing model -> original visible button -> original Blob helper; no server attestation or browser delivery' }));
  });
  return { sandbox, ui, overview, downloads, toasts, button };
}

function parseCsv(text) {
  const rows = []; let row = [], cell = '', quoted = false;
  text = text.replace(/^\uFEFF/, '');
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (c === '"') {
      if (quoted && text[i + 1] === '"') { cell += '"'; i++; } else quoted = !quoted;
    } else if (c === ',' && !quoted) { row.push(cell); cell = ''; }
    else if (c === '\r' && !quoted && text[i + 1] === '\n') {
      row.push(cell); rows.push(row); row = []; cell = ''; i++;
    } else cell += c;
  }
  assert.equal(quoted, false);
  row.push(cell); rows.push(row);
  const headers = rows.shift();
  for (const values of rows) assert.equal(values.length, headers.length);
  return { headers, rows: rows.map(values => Object.fromEntries(headers.map((name, i) => [name, values[i]]))) };
}

const originalHeaders = ['顺序', '分区', '卡片', '页面显示', '单位', '来源', '业务日期', '验证状态', '口径',
  '缺失状态', '机会键', '机会顺序', '证据等级', '关系类型', '是否因果结论', '说明', '证据'];

test('original cockpit download appends current report context without changing existing cells', async t => {
  const h = harness(t), model = h.ui.revenueCockpitModel.value;
  assert.equal(model.hotelId, 80);
  assert.equal(model.selectedPlatform, 'all_ota');
  assert.equal(model.businessDate, '2026-08-20');
  assert.equal(model.asOfDate, '2026-08-21');
  assert.equal(model.status, 'partial');
  const before = JSON.stringify(model);
  const button = await h.button();
  assert.equal(Boolean(button.props.disabled), false);
  assert.equal(button.props.onClick(), true);
  assert.equal(h.downloads.length, 1);
  const download = h.downloads[0];
  assert.equal(download.blob.type, 'text/csv;charset=utf-8');
  assert.match(download.fileName, /_2026-08-20_all_ota\.csv$/);
  const { headers, rows } = parseCsv(await download.blob.text());
  assert.deepEqual(headers.slice(0, 17), originalHeaders);
  assert.equal(rows.length, 4);
  assert.deepEqual(rows.map(row => row['卡片']), ['已核验零值', '缺失指标', '账目真实零', '账目缺失']);
  assert.deepEqual(rows.map(row => row['页面显示']), ['0', '—', '0', '未形成完整可信金额']);
  assert.equal(rows[1]['缺失状态'], 'missing_readback');
  assert.equal(rows[1]['验证状态'], '缺失/未验证');
  assert.equal(rows[3]['缺失状态'], model.businessDate);
  assert.equal(rows[0]['证据'], 'synthetic, "原引用"\n第二行');
  assert.equal(JSON.stringify(model), before, 'Export does not mutate the current model');
  assert.deepEqual(headers.slice(17), ['酒店ID', '酒店名称', '所选平台', '报告业务日期', '数据基准日', '报告状态', '范围说明']);
  for (const row of rows) {
    assert.equal(row['酒店ID'], '80');
    assert.equal(row['酒店名称'], model.hotelName);
    assert.equal(row['所选平台'], model.selectedPlatformLabel);
    assert.equal(row['报告业务日期'], model.businessDate);
    assert.equal(row['数据基准日'], model.asOfDate);
    assert.equal(row['报告状态'], model.statusLabel);
    assert.equal(row['范围说明'], `${model.scopeBoundary}；${model.dateNotice}`);
    assert.equal(row['是否因果结论'], 'false');
  }
  assert.equal(h.toasts.length, 1);
  assert.match(h.toasts[0].message, /4/);
});

test('original loading and blocked models keep the visible download control disabled', async t => {
  const h = harness(t);
  h.sandbox.revenueCockpitLoading.value = true;
  await Vue.nextTick();
  assert.equal(h.ui.revenueCockpitModel.value.status, 'loading');
  assert.equal(Boolean((await h.button()).props.disabled), true);
  h.sandbox.revenueCockpitLoading.value = false;
  h.overview.value = null;
  h.sandbox.revenueLoadState.value = { cockpit: { status: 'failed', error: 'synthetic source unavailable' } };
  await Vue.nextTick();
  assert.equal(h.ui.revenueCockpitModel.value.status, 'blocked');
  assert.equal(h.ui.revenueCockpitModel.value.visibleSections.length, 0);
  assert.equal(Boolean((await h.button()).props.disabled), true);
  // Never invoke a disabled control, and never attempt a backend save.
  assert.equal(h.downloads.length, 0);
  assert.equal(h.toasts.length, 0);
});
