import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/components/online-data/ctrip-order-analysis-panel.js', import.meta.url), 'utf8');
const fixture = (hotel = 80, from = '2026-09-01', to = '2026-09-30') => ({
  contract_version: 'dual_ota_order_quick_analysis.v1', status: 'partial',
  metric_scope: 'ota_channel',
  hotel: { id: hotel, name: `Synthetic hotel ${hotel}` }, date_range: { from, to, requested_from: from, requested_to: to },
  platforms: { ctrip: { platform: 'ctrip', metric_scope: 'ota_channel', status: 'missing', metrics: {} }, meituan: { platform: 'meituan', metric_scope: 'ota_channel', status: 'missing', metrics: {} } },
  comparison: { can_compare: false }, actions: [],
});
const harness = () => {
  const window = {}, requests = [];
  vm.runInNewContext(source, {
    window, URLSearchParams, sessionStorage: { getItem: () => '' },
    Vue: { h: (type, props, children) => ({ type, props: props || {}, children }), nextTick: () => Promise.resolve() },
    fetch: url => new Promise((resolve, reject) => requests.push({ url, resolve, reject })),
  });
  const component = window.SUXI_SYSTEM_COMPONENTS.CtripOrderAnalysisPanelBody;
  const state = { ...component.data(), ctx: { platformHotelSelectedId: 80, platformHotelSelectedName: 'Synthetic hotel 80' }, detailMode: 'summary' };
  for (const [key, getter] of Object.entries(component.computed)) Object.defineProperty(state, key, { get: () => getter.call(state) });
  for (const [key, method] of Object.entries(component.methods)) state[key] = method.bind(state);
  state.quickRangePreset = 'custom';
  state.quickDateFrom = '2026-09-01';
  state.quickDateTo = '2026-09-30';
  return { component, state, requests };
};
const respond = (request, data = fixture()) => request.resolve({ ok: true, status: 200, json: async () => ({ code: 200, data }) });
const descendants = node => Array.isArray(node) ? node.flatMap(descendants)
  : node && typeof node === 'object' ? [node, ...descendants(node.children)] : [];

for (const outcome of ['success', 'failure']) {
  for (const scope of ['quickDateFrom', 'quickDateTo', 'hotel']) {
    test(`quick read discards ${outcome} after ${scope} changes without another request`, async () => {
      const { state, requests } = harness();
      const pending = state.loadQuickAnalysis();
      const request = requests.shift();
      if (scope === 'hotel') state.ctx.platformHotelSelectedId = 81;
      else state[scope] = scope === 'quickDateFrom' ? '2026-09-02' : '2026-09-29';
      if (outcome === 'success') respond(request);
      else request.reject(new Error('Obsolete synthetic failure'));
      await pending;
      assert.equal(state.quickAnalysis, null);
      assert.equal(state.quickError, '');
      assert.equal(state.quickLoading, false);
    });
  }
}

test('invalid refresh cancels pending read, releases loading and permits a valid retry', async () => {
  const { state, requests } = harness();
  const pending = state.loadQuickAnalysis();
  const obsolete = requests.shift();
  state.quickDateTo = '';
  await state.loadQuickAnalysis();
  assert.equal(requests.length, 0);
  assert.equal(state.quickLoading, false);
  assert.equal(state.quickError, '开始日期和结束日期需要同时填写。');
  respond(obsolete);
  await pending;
  assert.equal(state.quickAnalysis, null);
  state.quickDateTo = '2026-09-30';
  const retry = state.loadQuickAnalysis();
  respond(requests.shift());
  await retry;
  assert.equal(state.quickAnalysis.hotel.id, 80);
  assert.equal(state.quickError, '');
  assert.equal(state.quickLoading, false);
});

test('invalid dates preserve the last success with its stale label and returned dates', async () => {
  const { state } = harness();
  state.quickAnalysis = fixture();
  state.quickDateFrom = '2026-10-01';
  await state.loadQuickAnalysis();
  assert.equal(state.quickStale, true);
  assert.equal(state.quickStatusLabel, '上次结果');
  assert.equal(state.quickDateRangeLabel, '2026-09-01 至 2026-09-30');
  assert.equal(state.quickLoading, false);
});

for (const data of [null, [], { ...fixture(), hotel: { id: 81 } }, { ...fixture(), date_range: { from: '2026-08-01', to: '2026-08-31' } }]) {
  test(`incomplete or mismatched success cannot replace saved quick result: ${JSON.stringify(data)}`, async () => {
    const { state, requests } = harness();
    const previous = fixture();
    state.quickAnalysis = previous;
    const pending = state.loadQuickAnalysis();
    respond(requests.shift(), data);
    await pending;
    assert.equal(state.quickAnalysis, previous);
    assert.equal(state.quickStale, true);
    assert.match(state.quickError, /响应|范围/);
    assert.equal(state.quickLoading, false);
  });
}

for (const oldOutcome of ['success', 'failure']) {
  test(`old ${oldOutcome} cannot replace a newer accepted date query`, async () => {
    const { state, requests } = harness();
    const older = state.loadQuickAnalysis();
    const oldRequest = requests.shift();
    state.quickDateFrom = '2026-09-02';
    const newer = state.loadQuickAnalysis();
    const latest = fixture(80, '2026-09-02', '2026-09-30');
    respond(requests.shift(), latest);
    await newer;
    if (oldOutcome === 'success') respond(oldRequest);
    else oldRequest.reject(new Error('Obsolete synthetic failure'));
    await older;
    assert.equal(state.quickAnalysis, latest);
    assert.equal(state.quickError, '');
    assert.equal(state.quickStale, false);
    assert.equal(state.quickLoading, false);
  });
}

test('custom dates are locked during read and unlocked when the owned read finishes', async () => {
  const { component, state, requests } = harness();
  const pending = state.loadQuickAnalysis();
  const dateInputs = () => descendants(component.render.call(state)).filter(node => node.type === 'input' && node.props.type === 'date');
  assert.equal(dateInputs().length, 2);
  assert.ok(dateInputs().every(node => node.props.disabled === true));
  respond(requests.shift());
  await pending;
  assert.ok(dateInputs().every(node => node.props.disabled === false));
});

test('default saved window accepts explicit missing dates without inventing queried dates', async () => {
  const { state, requests } = harness();
  state.quickDateFrom = '';
  state.quickDateTo = '';
  const pending = state.loadQuickAnalysis();
  const request = requests.shift();
  assert.equal(new URL(request.url, 'https://synthetic.invalid').searchParams.has('date_from'), false);
  respond(request, fixture(80, null, null));
  await pending;
  assert.equal(state.quickAnalysis.date_range.from, null);
  assert.equal(state.quickStale, false);
  assert.equal(state.quickError, '');
});
