import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync('public/app-main.js', 'utf8');
const loader = source.slice(source.indexOf('const loadPriceSuggestions = async'), source.indexOf('const changePriceSuggestionPage = async'));
const validator = source.slice(source.indexOf('const resolvePriceSuggestionListPayload ='), source.indexOf('const loadPriceSuggestions = async'));
const contextBlock = source.slice(source.indexOf('let agentRevenueStateEpoch = 0;'), source.indexOf('const resetAgentCenterClientState ='));
const ref = value => ({ value });
const deferred = () => { let resolve, reject; const promise = new Promise((r, j) => { resolve = r; reject = j; }); return { promise, resolve, reject }; };
const receipt = (marker, page = 1) => ({ code: 200, data: { list: [{ id: page, marker }], pagination: { total: 60, page, page_size: 20, total_page: 3 } } });

function harness() {
  const requests = [], toasts = [];
  const state = { hotel: ref('64'), filter: ref({ date: '2026-09-30', end_date: '2026-09-30', status: 1 }),
    rows: ref([]), review: ref(null), pagination: ref({ total: 60, page: 1, page_size: 20, total_page: 3 }), load: ref({}), session: 0 };
  const context = vm.createContext({ URLSearchParams, console: { error() {} },
    filterReportHotel: state.hotel, priceSuggestionFilter: state.filter,
    priceSuggestions: state.rows, priceSuggestionReview: state.review, priceSuggestionPagination: state.pagination,
    captureAuthSession: () => state.session, isAuthSessionCurrent: session => state.session === session,
    createPriceSuggestionPagination: () => ({ total: 0, page: 1, page_size: 20, total_page: 0 }),
    priceSuggestionRangeError: () => '', setRevenueLoadState: (key, status, error = '') => { state.load.value = { status, error }; },
    showToast: (...args) => toasts.push(args), request: endpoint => { const gate = deferred(); requests.push({ ...gate, endpoint }); return gate.promise; },
  });
  vm.runInContext(`let priceSuggestionsRequestSequence=0;${contextBlock}${validator}${loader};this.load=loadPriceSuggestions;`, context);
  return { state, requests, toasts, load: context.load };
}

test('a late earlier page cannot replace the latest requested page', async () => {
  const h = harness(), earlier = h.load({ page: 2 }), latest = h.load({ page: 3 });
  h.requests[1].resolve(receipt('page3', 3)); await latest;
  h.requests[0].resolve(receipt('page2', 2)); await earlier;
  assert.equal(h.state.rows.value[0].marker, 'page3'); assert.equal(h.state.pagination.value.page, 3);
  assert.equal(h.state.load.value.status, 'ready');
});

test('the latest same-page refresh owns its result', async () => {
  const h = harness(), earlier = h.load(), latest = h.load();
  h.requests[1].resolve(receipt('current')); await latest;
  h.requests[0].resolve(receipt('stale')); await earlier;
  assert.equal(h.state.rows.value[0].marker, 'current');
});

test('an obsolete error cannot clear a newer accepted page or raise a toast', async () => {
  const h = harness(), earlier = h.load({ page: 2 }), latest = h.load({ page: 3 });
  h.requests[1].resolve(receipt('current', 3)); await latest;
  h.requests[0].reject(new Error('obsolete failure')); await earlier;
  assert.equal(h.state.rows.value[0]?.marker, 'current'); assert.equal(h.state.pagination.value.page, 3);
  assert.equal(h.state.load.value.status, 'ready'); assert.equal(h.toasts.length, 0);
});

test('an earlier success cannot hide the current failed read; explicit retry recovers', async () => {
  const h = harness(), earlier = h.load(), latest = h.load({ silent: true });
  h.requests[1].reject(new Error('current failed read')); await latest;
  h.requests[0].resolve(receipt('stale')); await earlier;
  assert.equal(h.state.rows.value.length, 0); assert.equal(h.state.load.value.status, 'failed');
  assert.equal(h.state.load.value.error, 'current failed read');
  const retry = h.load({ silent: true }); h.requests[2].resolve(receipt('recovered')); await retry;
  assert.equal(h.state.rows.value[0].marker, 'recovered'); assert.equal(h.state.load.value.status, 'ready');
});

for (const changed of ['hotel', 'date', 'status', 'session']) test(`a read after ${changed} changes cannot restore the previous scope`, async () => {
  const h = harness(), pending = h.load();
  if (changed === 'hotel') h.state.hotel.value = '65';
  if (changed === 'date') h.state.filter.value.date = '2026-10-01';
  if (changed === 'status') h.state.filter.value.status = 2;
  if (changed === 'session') h.state.session++;
  h.state.rows.value = [{ marker: 'new scope' }]; h.state.load.value = { status: 'ready', error: '' };
  h.requests[0].resolve(receipt('old scope')); await pending;
  assert.equal(h.state.rows.value[0].marker, 'new scope'); assert.equal(h.state.load.value.status, 'ready');
});

for (const list of [undefined, null, {}, '']) test(`a missing or malformed list ${JSON.stringify(list)} is a failed read, not confirmed empty`, async () => {
  const h = harness(), pending = h.load({ silent: true });
  h.requests[0].resolve({ code: 200, data: { list, pagination: receipt('unused').data.pagination } }); await pending;
  assert.equal(h.state.load.value.status, 'failed'); assert.equal(h.state.rows.value.length, 0);
});

for (const pagination of [undefined, {}, { total: null, page: 1, page_size: 20, total_page: 0 }, { total: 60, page: 2, page_size: 20, total_page: 3 }]) test(`unverified pagination ${JSON.stringify(pagination)} cannot become current page`, async () => {
  const h = harness(), pending = h.load({ silent: true });
  h.requests[0].resolve({ code: 200, data: { list: [], pagination } }); await pending;
  assert.equal(h.state.load.value.status, 'failed'); assert.equal(h.state.rows.value.length, 0);
});

test('an explicit receipt from another hotel or date is not accepted', async () => {
  for (const change of [{ hotel_id: 65 }, { start_date: '2026-09-29' }, { platform: 'meituan' }]) {
    const h = harness(), pending = h.load({ silent: true });
    h.requests[0].resolve({ ...receipt('foreign'), data: { ...receipt('foreign').data,
      query_scope: { hotel_id: 64, platform: 'ctrip', start_date: '2026-09-30', end_date: '2026-09-30', ...change } } }); await pending;
    assert.equal(h.state.load.value.status, 'failed'); assert.equal(h.state.rows.value.length, 0);
  }
});

test('a valid zero receipt is confirmed empty and remains retryable', async () => {
  const h = harness(), pending = h.load({ silent: true });
  h.requests[0].resolve({ code: 200, data: { list: [], pagination: { total: 0, page: 1, page_size: 20, total_page: 0 } } }); await pending;
  assert.equal(h.state.load.value.status, 'empty'); assert.equal(h.state.pagination.value.total, 0);
  const retry = h.load({ silent: true }); h.requests[1].resolve(receipt('recovered')); await retry;
  assert.equal(h.state.load.value.status, 'ready'); assert.equal(h.state.rows.value[0].marker, 'recovered');
});

test('legacy numeric pagination text becomes numbers for subsequent page navigation', async () => {
  const h = harness(), pending = h.load({ silent: true });
  h.requests[0].resolve({ ...receipt('legacy'), data: { ...receipt('legacy').data,
    pagination: { total: '60', page: '1', page_size: '20', total_page: '3' } } }); await pending;
  assert.equal(h.state.pagination.value.page, 1); assert.equal(h.state.pagination.value.page + 1, 2);
  assert.equal(h.state.pagination.value.total, 60); assert.equal(h.state.pagination.value.total_page, 3);
});
