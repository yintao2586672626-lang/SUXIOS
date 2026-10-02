import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const appMain = readFileSync('public/app-main.js', 'utf8');
const start = appMain.indexOf('const loadMeituanOrderFlowData = async (options = {}) => {');
const end = appMain.indexOf('\n\n            const selectMeituanOrderFlowPeriod', start);
assert.ok(start >= 0 && end > start);
const loaderSource = appMain.slice(start, end);

function harness(responses) {
  const calls = [];
  const requestOptions = [];
  const sandbox = {
    URLSearchParams,
    meituanOrderFlowRequestSeq: 0,
    meituanForm: { value: { hotelId: '80' } },
    meituanOrderFlowPeriod: { value: 'last_7_days' },
    meituanOrderFlowRows: { value: [] },
    meituanOrderFlowError: { value: '' },
    meituanOrderFlowLoading: { value: false },
    captureAuthSession: () => ({ epoch: 1 }),
    isAuthSessionCurrent: () => true,
    currentPageReadPolicy: () => ({ scope: 'page', pageKey: 'meituan-ebooking' }),
    resolveMeituanOrderFlowDateRange: () => ({ startDate: '2026-09-23', endDate: '2026-09-29' }),
    request: async (url, options = {}) => {
      calls.push(String(url));
      requestOptions.push(options);
      return responses[calls.length - 1];
    },
  };
  vm.runInNewContext(`${loaderSource}\nthis.load = loadMeituanOrderFlowData;`, sandbox);
  return { sandbox, calls, requestOptions };
}

const row = (id) => ({
  id, system_hotel_id: 80, source: 'meituan', data_type: 'order_flow', data_date: '2026-09-29',
  raw_data: JSON.stringify({ order_flow_period: 'last_7_days', order_flow_direction: id === 201 ? 'inflow' : 'loss' }),
});

test('order-flow exact readback loads the second page before declaring the saved result verified', async () => {
  const first = Array.from({ length: 200 }, (_, index) => row(index + 1));
  const h = harness([
    { code: 200, data: { list: first, pagination: { total: 201, page: 1, page_size: 200 } } },
    { code: 200, data: { list: [row(201)], pagination: { total: 201, page: 2, page_size: 200 } } },
  ]);
  const result = await h.sandbox.load();
  assert.equal(result.status, 'verified');
  assert.equal(h.calls.length, 2, 'a 201-row result must read page 2');
  assert.equal(new URL(h.calls[1], 'http://local').searchParams.get('page'), '2');
  assert.equal(h.sandbox.meituanOrderFlowRows.value.length, 201);
  assert.equal(h.sandbox.meituanOrderFlowRows.value.at(-1).id, 201);
});

test('an incomplete or cross-hotel page cannot be reported as verified', async () => {
  const first = Array.from({ length: 200 }, (_, index) => row(index + 1));
  const h = harness([
    { code: 200, data: { list: first, pagination: { total: 201, page: 1, page_size: 200 } } },
    { code: 200, data: { list: [{ ...row(201), system_hotel_id: 81 }], pagination: { total: 201, page: 2, page_size: 200 } } },
  ]);
  const result = await h.sandbox.load();
  assert.equal(result.status, 'readback_failed');
  assert.deepEqual(Array.from(h.sandbox.meituanOrderFlowRows.value), []);
  assert.match(h.sandbox.meituanOrderFlowError.value, /酒店|范围/);
});

test('a later page failure clears the old result and an exact retry can recover', async () => {
  const first = Array.from({ length: 200 }, (_, index) => row(index + 1));
  const h = harness([
    { code: 200, data: { list: first, pagination: { total: 201, page: 1, page_size: 200 } } },
    { code: 503, message: 'synthetic page 2 failure' },
    { code: 200, data: { list: [row(201)], pagination: { total: 1, page: 1, page_size: 200 } } },
  ]);
  h.sandbox.meituanOrderFlowRows.value = [row(999)];
  const failed = await h.sandbox.load();
  assert.equal(failed.status, 'readback_failed');
  assert.deepEqual(Array.from(h.sandbox.meituanOrderFlowRows.value), []);
  assert.match(h.sandbox.meituanOrderFlowError.value, /page 2 failure/);
  const recovered = await h.sandbox.load();
  assert.equal(recovered.status, 'verified');
  assert.equal(h.sandbox.meituanOrderFlowRows.value.length, 1);
  assert.equal(h.sandbox.meituanOrderFlowRows.value[0].id, 201);
  assert.equal(h.sandbox.meituanOrderFlowError.value, '');
});

test('post-save readback bypasses an older coordinated GET on every page', async () => {
  const first = Array.from({ length: 200 }, (_, index) => row(index + 1));
  const h = harness([
    { code: 200, data: { list: first, pagination: { total: 201, page: 1, page_size: 200 } } },
    { code: 200, data: { list: [row(201)], pagination: { total: 201, page: 2, page_size: 200 } } },
  ]);
  const result = await h.sandbox.load({ fresh: true });
  assert.equal(result.status, 'verified');
  assert.equal(h.requestOptions.length, 2);
  for (const options of h.requestOptions) {
    assert.equal(options.cache, 'no-store');
    assert.equal(options.requestPolicy.direct, true);
    assert.equal(options.requestPolicy.pageKey, 'meituan-ebooking');
  }
});
