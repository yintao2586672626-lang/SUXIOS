import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../../public/components/online-data/ctrip-order-analysis-panel.js', import.meta.url), 'utf8');

function createHarness() {
  const requests = [];
  const window = {};
  const h = (tag, props, children) => ({ tag, props: props || {}, children });
  vm.runInNewContext(source, {
    window,
    Vue: { h, nextTick: async () => {} },
    URLSearchParams,
    sessionStorage: { getItem: () => '' },
    fetch: url => new Promise((resolve, reject) => requests.push({ url, resolve, reject })),
  }, { filename: 'ctrip-order-analysis-panel.js' });
  const component = window.SUXI_SYSTEM_COMPONENTS.CtripOrderAnalysisPanelBody;
  const state = {
    ...component.data(),
    ctx: { platformHotelSelectedId: 901, platformHotelSelectedName: 'Fixture hotel A' },
    detailMode: 'ctrip',
  };
  for (const [key, method] of Object.entries(component.methods)) state[key] = method.bind(state);
  for (const [key, getter] of Object.entries(component.computed)) {
    Object.defineProperty(state, key, { get: getter.bind(state) });
  }
  // This suite owns the detail query only, not the separate dual-platform request.
  state.loadQuickAnalysis = () => {};
  state.renderQuickAnalysis = () => null;
  return { component, state, requests, render: () => component.render.call(state) };
}

function* nodes(node) {
  if (!node || typeof node !== 'object') return;
  yield node;
  for (const child of Array.isArray(node.children) ? node.children : []) yield* nodes(child);
}

const dateInputs = render => [...nodes(render())].filter(node => node.tag === 'input' && node.props.type === 'date');
const hasText = (render, text) => [...nodes(render())].some(node => node.children === text);
const flush = () => new Promise(resolve => setImmediate(resolve));
const fixture = (name = 'Fixture hotel A', from = '2026-07-01', to = '2026-07-31', hotelId = 901) => ({
  status: 'available_unverified',
  hotel: { id: hotelId, name },
  metric_scope: 'ota_channel',
  source: { platform: 'ctrip' },
  date_range: { from, to, requested_from: from, requested_to: to },
  summary: {},
});
const respond = (request, data = fixture()) => request.resolve({
  ok: true, status: 200, json: async () => ({ code: 200, data }),
});

test('saved legacy adapter displays existing metrics and the explicit fixture boundary', () => {
  const { state, render, component } = createHarness();
  state.analysis = {
    ...fixture(), status: 'available_partial', quality_status: 'test_fixture',
    quality_label: '测试样例（非真实经营数据）',
    batch: { import_contract: null, read_adapter: 'ctrip_order_legacy_saved_aggregate', row_count: 2 },
    summary: { active_orders: 2, room_nights: 4 },
  };
  assert.equal(state.isLegacyAggregate, true);
  assert.equal(state.contractLabel, '旧版已存汇总');
  assert.equal(hasText(render, '旧版已存汇总'), true);
  assert.equal(hasText(render, state.sourceBoundaryText), true);
  assert.match(state.sourceBoundaryText, /测试样例（非真实经营数据）/);
  assert.match(component.template, /\{\{ sourceBoundaryText \}\}/);
  assert.equal(hasText(render, '暂无订单数据'), false);
  assert.match(state.metricCards.find(card => card.key === 'active').note, /旧聚合保存/);
});

test('detail loading disables both rendered date inputs and does not show a no-data badge', async () => {
  const { state, requests, render, component } = createHarness();
  const pending = state.loadAnalysis();
  assert.equal(state.statusLabel, '读取中');
  assert.notEqual(state.statusClass, 'border-slate-200 bg-slate-50 text-slate-600');
  assert.equal(dateInputs(render).length, 2);
  assert.ok(dateInputs(render).every(node => node.props.disabled === true));
  assert.equal(hasText(render, '暂无订单数据'), false);
  for (const field of ['dateFrom', 'dateTo']) {
    const input = component.template.match(new RegExp(`<input\\s+v-model="${field}"[^>]*>`))?.[0];
    assert.ok(input, `${field} must exist in the template fallback`);
    assert.match(input, /:disabled="loading(?: \|\| !systemHotelId)?"/);
  }
  respond(requests.shift());
  await pending;
  assert.ok(dateInputs(render).every(node => node.props.disabled === false));
  assert.equal(state.statusLabel, '已保存 · 来源待核验');
});

test('detail query failure has an error badge and retry returns to a normal result', async () => {
  const { state, requests, render } = createHarness();
  const first = state.loadAnalysis();
  requests.shift().reject(new Error('Fixture query unavailable'));
  await first;
  assert.equal(state.statusLabel, '读取失败');
  assert.match(state.statusClass, /red/);
  assert.equal(hasText(render, '暂无订单数据'), false);
  assert.equal(hasText(render, 'Fixture query unavailable'), true);
  assert.ok(dateInputs(render).every(node => node.props.disabled === false));

  const retry = state.loadAnalysis();
  assert.equal(state.statusLabel, '读取中');
  assert.equal(state.error, '');
  respond(requests.shift());
  await retry;
  assert.equal(state.statusLabel, '已保存 · 来源待核验');
  assert.equal(state.loading, false);
});

for (const outcome of ['success', 'failure']) {
  for (const changedScope of ['dateFrom', 'dateTo', 'hotel']) {
    test(`detail ignores ${outcome} when ${changedScope} changes without starting a new request`, async () => {
      const { state, requests } = createHarness();
      state.dateFrom = '2026-07-01';
      state.dateTo = '2026-07-31';
      const pending = state.loadAnalysis();
      const request = requests.shift();
      if (changedScope === 'hotel') state.ctx.platformHotelSelectedId = 902;
      else state[changedScope] = changedScope === 'dateFrom' ? '2026-07-02' : '2026-07-30';
      if (outcome === 'success') respond(request);
      else request.reject(new Error('Obsolete fixture failure'));
      await pending;
      assert.equal(state.analysis, null, 'obsolete results must not become the current scope result');
      assert.equal(state.error, '', 'obsolete failures must not overwrite the current scope status');
      assert.equal(state.loading, false, 'discarding a response must release its own loading state');
    });
  }
}

test('detail hotel watcher keeps the newer hotel result when the old request finishes last', async () => {
  const { component, state, requests } = createHarness();
  const oldPending = state.loadAnalysis();
  const oldRequest = requests.shift();
  state.ctx.platformHotelSelectedId = 902;
  state.ctx.platformHotelSelectedName = 'Fixture hotel B';
  component.watch.systemHotelId.handler.call(state);
  const currentRequest = requests.shift();
  assert.equal(new URL(currentRequest.url, 'https://fixture.invalid').searchParams.get('system_hotel_id'), '902');
  respond(currentRequest, fixture('Fixture hotel B', '2026-07-01', '2026-07-31', 902));
  await flush();
  respond(oldRequest);
  await oldPending;
  assert.equal(state.analysis.hotel.name, 'Fixture hotel B');
  assert.equal(state.loading, false);
});

test('detail reset requests all saved dates and restores the older returned range', async () => {
  const { state, requests } = createHarness();
  state.dateFrom = '2026-08-01';
  state.dateTo = '2026-08-31';
  state.resetRange();
  const request = requests.shift();
  const params = new URL(request.url, 'https://fixture.invalid').searchParams;
  assert.equal(params.get('system_hotel_id'), '901');
  assert.equal(params.has('date_from'), false);
  assert.equal(params.has('date_to'), false);
  respond(request, fixture('Fixture hotel A', '2025-01-01', '2026-08-31'));
  await flush();
  assert.equal(state.dateFrom, '2025-01-01');
  assert.equal(state.dateTo, '2026-08-31');
  assert.equal(state.loading, false);
});

test('detail invalid-date query cancels a pending read without leaving controls disabled', async () => {
  const { state, requests, render } = createHarness();
  const pending = state.loadAnalysis();
  const obsoleteRequest = requests.shift();
  state.dateFrom = '2026-07-01';
  await state.loadAnalysis();
  assert.equal(requests.length, 0, 'invalid input must not send another query');
  assert.equal(state.loading, false);
  assert.equal(state.statusLabel, '读取失败');
  assert.ok(dateInputs(render).every(node => node.props.disabled === false));
  respond(obsoleteRequest);
  await pending;
  assert.equal(state.analysis, null);
  assert.equal(state.error, '开始日期和结束日期需要同时填写。');
});
