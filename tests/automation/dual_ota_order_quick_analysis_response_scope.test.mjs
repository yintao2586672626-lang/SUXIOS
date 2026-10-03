import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync('public/components/online-data/ctrip-order-analysis-panel.js', 'utf8');
const valid = (overrides = {}) => ({
  contract_version: 'dual_ota_order_quick_analysis.v1',
  status: 'data_missing',
  metric_scope: 'ota_channel',
  hotel: { id: 80, name: 'Synthetic hotel' },
  date_range: { from: '2026-09-01', to: '2026-09-30', requested_from: '2026-09-01', requested_to: '2026-09-30' },
  platforms: {
    ctrip: { platform: 'ctrip', metric_scope: 'ota_channel', status: 'missing' },
    meituan: { platform: 'meituan', metric_scope: 'ota_channel', status: 'missing' },
  },
  ...overrides,
});

function harness({ dateFrom = '2026-09-01', dateTo = '2026-09-30' } = {}) {
  let nextData;
  const window = { SUXI_SYSTEM_COMPONENTS: {} };
  vm.runInNewContext(source, {
    window, URLSearchParams,
    sessionStorage: { getItem: () => '' },
    fetch: async () => ({ ok: true, status: 200, json: async () => ({ code: 200, data: nextData }) }),
  });
  const component = window.SUXI_SYSTEM_COMPONENTS.CtripOrderAnalysisPanelBody;
  const instance = {
    ctx: { platformHotelSelectedId: 80, token: 'synthetic' },
    quickDateFrom: dateFrom, quickDateTo: dateTo,
    quickRequestSequence: 0, quickAnalysis: null, quickLoading: false, quickError: '', quickStale: false,
    get systemHotelId() { return component.computed.systemHotelId.call(this); },
  };
  const respond = async data => { nextData = data; await component.methods.loadQuickAnalysis.call(instance); };
  return { instance, respond };
}

test('dual-OTA quick read rejects wrong hotel, metric scope, platform identity and requested range, then recovers', async () => {
  const h = harness();
  for (const invalid of [
    valid({ hotel: { id: 81 } }),
    valid({ metric_scope: 'hotel_wide' }),
    valid({ platforms: { ctrip: { platform: 'meituan', metric_scope: 'ota_channel' }, meituan: valid().platforms.meituan } }),
    valid({ date_range: { from: '2026-08-01', to: '2026-08-31', requested_from: '2026-08-01', requested_to: '2026-08-31' } }),
  ]) {
    await h.respond(invalid);
    assert.equal(h.instance.quickAnalysis, null);
    assert.match(h.instance.quickError, /返回.*范围.*不一致/);
    assert.equal(h.instance.quickLoading, false);
  }
  await h.respond(valid());
  assert.equal(h.instance.quickAnalysis.status, 'data_missing');
  assert.equal(h.instance.quickError, '');
  assert.equal(h.instance.quickStale, false);
  const previous = h.instance.quickAnalysis;
  await h.respond(valid({ hotel: { id: 81 } }));
  assert.equal(h.instance.quickAnalysis, previous, 'a foreign receipt cannot replace the retained same-scope result');
  assert.equal(h.instance.quickStale, true, 'the retained result must be marked as previous rather than current');
  await h.respond(valid());
  assert.equal(h.instance.quickAnalysis.status, 'data_missing');
});

test('latest saved range accepts a null requested range but rejects an unexpected explicit range', async () => {
  const h = harness({ dateFrom: '', dateTo: '' });
  const latest = valid({ date_range: { from: '2026-09-01', to: '2026-09-30', requested_from: null, requested_to: null } });
  await h.respond(latest);
  assert.equal(h.instance.quickAnalysis.status, 'data_missing');
  const previous = h.instance.quickAnalysis;
  await h.respond(valid());
  assert.equal(h.instance.quickAnalysis, previous);
  assert.equal(h.instance.quickStale, true);
  assert.match(h.instance.quickError, /日期范围.*不一致/);
});
