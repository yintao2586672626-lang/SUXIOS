import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const panelSource = fs.readFileSync(
  path.join(repoRoot, 'public/components/online-data/ctrip-order-analysis-panel.js'),
  'utf8',
);

const createHarness = ({ analysis = null } = {}) => {
  const requests = [];
  const window = { SUXI_SYSTEM_COMPONENTS: {} };
  const context = vm.createContext({
    window,
    URLSearchParams,
    sessionStorage: { getItem: () => '' },
    fetch: url => new Promise(resolve => requests.push({ url, resolve })),
  });
  vm.runInContext(panelSource, context);
  const component = window.SUXI_SYSTEM_COMPONENTS.CtripOrderAnalysisPanelBody;
  assert.ok(component, 'the current lazy panel source registers its component');
  const instance = {
    ctx: { platformHotelSelectedId: 80 },
    dateFrom: '',
    dateTo: '',
    requestSequence: 0,
    loading: false,
    error: '',
    analysis,
    get systemHotelId() {
      return Number(this.ctx?.platformHotelSelectedId || 0);
    },
  };
  return { component, instance, requests };
};

const resolveAnalysis = (request, dateRange) => request.resolve({
  ok: true,
  json: async () => ({ code: 200, data: {
    status: 'available_unverified',
    metric_scope: 'ota_channel',
    hotel: { id: 80, name: 'Synthetic hotel' },
    date_range: {
      ...dateRange,
      requested_from: dateRange.requested_from ?? dateRange.from,
      requested_to: dateRange.requested_to ?? dateRange.to,
    },
  } }),
});

test('invalid date submission releases a pending request, clears mismatched analysis, and can recover', async () => {
  const { component, instance, requests } = createHarness({
    analysis: { date_range: { from: '2026-07-01', to: '2026-07-31' } },
  });
  const load = component.methods.loadAnalysis;

  const staleRequest = load.call(instance);
  assert.equal(instance.loading, true);
  assert.equal(requests.length, 1);

  instance.dateFrom = '2026-08-01';
  instance.dateTo = '';
  await load.call(instance);

  assert.equal(instance.error, '开始日期和结束日期需要同时填写。');
  assert.equal(instance.loading, false, 'invalid input must release the query button while invalidating the prior request');
  assert.equal(instance.analysis, null, 'old results must not remain under the newly selected invalid range');

  resolveAnalysis(requests[0], { from: '2026-07-01', to: '2026-07-31' });
  await staleRequest;
  assert.equal(instance.loading, false);
  assert.equal(instance.analysis, null, 'a stale pre-validation response must not restore old results');

  instance.dateTo = '2026-08-31';
  const recoveryRequest = load.call(instance);
  assert.equal(requests.length, 2, 'corrected input must be queryable without remounting the panel');
  resolveAnalysis(requests[1], { from: '2026-08-01', to: '2026-08-31' });
  await recoveryRequest;
  assert.equal(instance.error, '');
  assert.equal(instance.loading, false);
  assert.deepEqual(JSON.parse(JSON.stringify({
    from: instance.analysis.date_range.from,
    to: instance.analysis.date_range.to,
  })), { from: '2026-08-01', to: '2026-08-31' });
});

test('reversed date submission does not retain a previous range as current', async () => {
  const { component, instance, requests } = createHarness({
    analysis: { date_range: { from: '2026-07-01', to: '2026-07-31' } },
  });
  instance.dateFrom = '2026-09-30';
  instance.dateTo = '2026-09-01';

  await component.methods.loadAnalysis.call(instance);

  assert.equal(instance.error, '开始日期不能晚于结束日期。');
  assert.equal(instance.loading, false);
  assert.equal(instance.analysis, null);
  assert.equal(requests.length, 0, 'invalid range must not be sent to the API');
});
