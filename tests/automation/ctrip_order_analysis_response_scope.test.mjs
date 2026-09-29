import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync('public/components/online-data/ctrip-order-analysis-panel.js', 'utf8');

function harness({ dateFrom = '2026-09-01', dateTo = '2026-09-30' } = {}) {
  const requests = [];
  let nextPayload = null;
  const window = { SUXI_SYSTEM_COMPONENTS: {} };
  const context = vm.createContext({
    window,
    URLSearchParams,
    sessionStorage: { getItem: () => '' },
    fetch: async (url, options) => {
      requests.push({ url: String(url), options });
      return { ok: true, json: async () => nextPayload };
    },
  });
  vm.runInContext(source, context);
  const component = window.SUXI_SYSTEM_COMPONENTS.CtripOrderAnalysisPanelBody;
  const instance = {
    ctx: { platformHotelSelectedId: 80 },
    dateFrom,
    dateTo,
    requestSequence: 0,
    loading: false,
    error: '',
    analysis: null,
    get systemHotelId() { return Number(this.ctx?.platformHotelSelectedId || 0); },
  };
  const respond = async payload => {
    nextPayload = payload;
    await component.methods.loadAnalysis.call(instance);
  };
  return { component, instance, requests, respond };
}

const analysis = (overrides = {}) => ({
  status: 'available_unverified',
  metric_scope: 'ota_channel',
  hotel: { id: 80, name: 'Synthetic hotel A' },
  source: { platform: 'ctrip', method: 'user_provided_unverified' },
  date_range: {
    from: '2026-09-06',
    to: '2026-09-24',
    requested_from: '2026-09-01',
    requested_to: '2026-09-30',
  },
  summary: { gross_orders: 3 },
  ...overrides,
});
const payload = data => ({ code: 200, data });

test('a successful response for another hotel is rejected and does not render', async () => {
  const h = harness();
  await h.respond(payload(analysis({ hotel: { id: 81, name: 'Synthetic hotel B' } })));
  assert.equal(h.instance.analysis, null);
  assert.match(h.instance.error, /返回.*酒店.*范围/);
  assert.equal(h.instance.loading, false);
  await h.respond(payload(analysis()));
  assert.equal(h.instance.analysis.status, 'available_unverified', 'a fresh response for the selected hotel can recover');
  assert.equal(h.instance.error, '');
  assert.equal(h.requests.length, 2);
});

test('a successful response for another requested date range is rejected', async () => {
  const h = harness();
  await h.respond(payload(analysis({ date_range: {
    from: '2026-08-10',
    to: '2026-08-20',
    requested_from: '2026-08-01',
    requested_to: '2026-08-31',
  } })));
  assert.equal(h.instance.analysis, null);
  assert.match(h.instance.error, /返回.*日期.*范围/);
  assert.equal(h.instance.loading, false);
});

test('a missing range identity fails closed but a sparse exact requested range is accepted', async () => {
  const missing = harness();
  await missing.respond(payload(analysis({ date_range: undefined })));
  assert.equal(missing.instance.analysis, null);
  assert.match(missing.instance.error, /返回.*日期.*范围/);

  const valid = harness();
  await valid.respond(payload(analysis({
    date_range: {
      from: '2026-09-06',
      to: '2026-09-24',
      requested_from: '2026-09-01',
      requested_to: '2026-09-30',
    },
  })));
  assert.equal(valid.instance.analysis.status, 'available_unverified');
  assert.equal(valid.instance.error, '');
  assert.equal(valid.instance.loading, false);
  assert.deepEqual(JSON.parse(JSON.stringify({
    from: new URL(valid.requests[0].url, 'http://local').searchParams.get('date_from'),
    to: new URL(valid.requests[0].url, 'http://local').searchParams.get('date_to'),
    hotel: new URL(valid.requests[0].url, 'http://local').searchParams.get('system_hotel_id'),
  })), { from: '2026-09-01', to: '2026-09-30', hotel: '80' });
});

test('a mismatched OTA metric or platform identity is rejected', async () => {
  for (const wrongIdentity of [
    { metric_scope: 'hotel_wide' },
    { source: { platform: 'meituan' } },
  ]) {
    const h = harness();
    await h.respond(payload(analysis(wrongIdentity)));
    assert.equal(h.instance.analysis, null);
    assert.match(h.instance.error, /返回.*酒店.*范围/);
    assert.equal(h.instance.loading, false);
  }
});

test('a scoped no-data response is still accepted and stays distinct from mismatch', async () => {
  const h = harness();
  await h.respond(payload({
    status: 'no_data',
    metric_scope: 'ota_channel',
    hotel: { id: 80, name: '' },
    date_range: {
      from: null,
      to: null,
      requested_from: '2026-09-01',
      requested_to: '2026-09-30',
    },
  }));
  assert.equal(h.instance.analysis.status, 'no_data');
  assert.equal(h.instance.error, '');
});
