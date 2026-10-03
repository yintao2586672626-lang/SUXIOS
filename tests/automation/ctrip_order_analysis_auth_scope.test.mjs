import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync('public/components/online-data/ctrip-order-analysis-panel.js', 'utf8');

test('same selected hotel after account switch clears old results and ignores late old-session replies', async () => {
  const pending = [];
  const window = { SUXI_SYSTEM_COMPONENTS: {} };
  vm.runInNewContext(source, {
    window,
    URLSearchParams,
    sessionStorage: { getItem: () => '' },
    fetch: (url) => new Promise(resolve => pending.push({ url: String(url), resolve })),
  });
  const component = window.SUXI_SYSTEM_COMPONENTS.CtripOrderAnalysisPanelBody;
  const ctx = {
    platformHotelSelectedId: 80,
    user: { id: 1, tenant_id: 10 },
    authContext: { tenantId: 10 },
    token: 'synthetic-session-a',
    isLoggedIn: true,
  };
  const instance = {
    ...component.data(), ctx, detailMode: 'ctrip',
    get systemHotelId() { return component.computed.systemHotelId.call(this); },
    get authScopeKey() { return component.computed.authScopeKey.call(this); },
    get showCtripDetail() { return component.computed.showCtripDetail.call(this); },
  };
  for (const [name, method] of Object.entries(component.methods)) instance[name] = method.bind(instance);

  component.watch.systemHotelId.handler.call(instance);
  assert.equal(pending.length, 2);
  const oldRequests = [...pending];
  const oldScopeKey = instance.authScopeKey;
  ctx.user = { id: 2, tenant_id: 20 };
  ctx.authContext = { tenantId: 20 };
  ctx.token = 'synthetic-session-b';
  component.watch.authScopeKey.call(instance, instance.authScopeKey, oldScopeKey);
  assert.equal(instance.quickAnalysis, null);
  assert.equal(instance.analysis, null);
  assert.equal(pending.length, 4, 'same hotel must be freshly read for the new account');

  const respond = (request, data) => request.resolve({ ok: true, status: 200, json: async () => ({ code: 200, data }) });
  for (const request of oldRequests) respond(request, { status: 'ready', metric_scope: 'ota_channel', hotel: { id: 80 }, date_range: {} });
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(instance.quickAnalysis, null);
  assert.equal(instance.analysis, null);

  respond(pending[2], {
    status: 'data_missing', metric_scope: 'ota_channel', hotel: { id: 80 },
    date_range: {
      from: instance.quickDateFrom, to: instance.quickDateTo,
      requested_from: instance.quickDateFrom, requested_to: instance.quickDateTo,
    },
    platforms: {
      ctrip: { platform: 'ctrip', metric_scope: 'ota_channel' },
      meituan: { platform: 'meituan', metric_scope: 'ota_channel' },
    },
  });
  respond(pending[3], {
    status: 'no_data', metric_scope: 'ota_channel', hotel: { id: 80 },
    date_range: { from: null, to: null, requested_from: null, requested_to: null },
  });
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(instance.quickAnalysis.status, 'data_missing');
  assert.equal(instance.analysis.status, 'no_data');
});
