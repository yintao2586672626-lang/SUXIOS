import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync('public/app-main.js', 'utf8');
const blockStart = source.indexOf('const operationDataRequestSeq =');
const start = blockStart >= 0 ? blockStart : source.indexOf('const loadOperationFullData =');
const block = source.slice(start, source.indexOf('const loadOperationAlerts =', start));
const deferred = () => {
  let resolve, reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
};
function harness({ ready } = {}) {
  const calls = [], watchers = [], toasts = [];
  const context = vm.createContext({
    URLSearchParams,
    currentPage: { value: 'ops-source' },
    pageRequestGeneration: 1,
    epoch: 1,
    operationFilters: { value: { hotel_id: '7', date: '2026-09-07' } },
    operationLoading: { value: { fullData: false, rootCause: false } },
    operationError: { value: { fullData: '', rootCause: '' } },
    operationFullData: { value: null },
    operationRootCause: { value: null },
    captureAuthSession: () => context.epoch,
    isAuthSessionCurrent: epoch => epoch === context.epoch,
    ensureOperationStaticReady: async () => { await ready?.(); },
    operationErrorMessage: error => error.message,
    showToast: text => toasts.push(text),
    watch: (_source, callback) => watchers.push(callback),
    normalizeOperationHotelSelection: form => form.value.hotel_id,
    operationParams: () => new URLSearchParams(context.operationFilters.value).toString(),
    apiRequest: (url, options) => {
      const pending = deferred();
      calls.push({ url, options, ...pending });
      return pending.promise;
    },
  });
  vm.runInContext(block + '\nglobalThis.readFacts = loadOperationFullData; globalThis.diagnose = analyzeOperationRootCause;', context);
  const settle = async () => { for (let i = 0; i < 8; i++) await Promise.resolve(); };
  const scope = (hotel = 7, date = '2026-09-07') => ({ hotel_id: hotel, business_date: date });
  const response = (marker, query_scope = scope()) => ({ code: 200, data: { marker, query_scope } });
  const change = (hotel, date = '2026-09-07') => {
    context.operationFilters.value = { hotel_id: String(hotel), date };
    watchers.forEach(fn => fn());
  };
  return { context, calls, toasts, settle, response, scope, change };
}
test('old hotel response cannot overwrite the new hotel or clear its loading', async () => {
  const h = harness();
  const old = h.context.readFacts(); await h.settle();
  h.change(8);
  const current = h.context.readFacts(); await h.settle();
  h.calls[0].resolve(h.response('old'));
  await old;
  assert.equal(h.context.operationFullData.value, null);
  assert.equal(h.context.operationLoading.value.fullData, true);
  h.calls[1].resolve(h.response('current', h.scope(8)));
  await current;
  assert.equal(h.context.operationFullData.value.marker, 'current');
  assert.equal(h.context.operationLoading.value.fullData, false);
});
test('same-scope refresh keeps only the latest response', async () => {
  const h = harness();
  const first = h.context.readFacts(); await h.settle();
  const second = h.context.readFacts(); await h.settle();
  h.calls[1].resolve(h.response('latest')); await second;
  h.calls[0].resolve(h.response('older')); await first;
  assert.equal(h.context.operationFullData.value.marker, 'latest');
});
test('scope change clears already displayed facts and diagnosis', async () => {
  const h = harness();
  h.context.operationFullData.value = { marker: 'old' };
  h.context.operationRootCause.value = { marker: 'old' };
  h.change(8);
  assert.equal(h.context.operationFullData.value, null);
  assert.equal(h.context.operationRootCause.value, null);
});
test('helper loading failure becomes a visible recoverable error', async () => {
  const h = harness({ ready: async () => { throw new Error('helper failed'); } });
  await h.context.readFacts();
  assert.match(h.context.operationError.value.fullData, /helper failed/);
  assert.equal(h.context.operationLoading.value.fullData, false);
  assert.equal(h.calls.length, 0);
});
test('changing date during helper loading prevents the old request from being sent', async () => {
  const gate = deferred();
  const h = harness({ ready: () => gate.promise });
  const pending = h.context.readFacts(); await h.settle();
  h.change(7, '2026-09-06'); gate.resolve(); await h.settle();
  h.calls.forEach(call => call.resolve(h.response('obsolete')));
  await pending;
  assert.equal(h.calls.length, 0);
});
test('wrong or missing response identity cannot be displayed', async () => {
  for (const identity of [undefined, { hotel_id: 8, business_date: '2026-09-07' }, { hotel_id: 7, business_date: '2026-09-06' }]) {
    const h = harness();
    const pending = h.context.readFacts(); await h.settle();
    h.calls[0].resolve({ code: 200, data: { query_scope: identity } }); await pending;
    assert.equal(h.context.operationFullData.value, null);
    assert.match(h.context.operationError.value.fullData, /酒店|日期|范围/);
  }
});
test('logout suppresses a pending response and its toast', async () => {
  const h = harness();
  const pending = h.context.readFacts(); await h.settle();
  h.context.epoch++;
  h.calls[0].reject(new Error('old session error')); await pending;
  assert.equal(h.context.operationFullData.value, null);
  assert.equal(h.context.operationError.value.fullData, '');
  assert.equal(h.toasts.length, 0);
});
test('diagnosis also rejects stale hotel responses', async () => {
  const h = harness();
  const old = h.context.diagnose(); await h.settle();
  h.change(8);
  const current = h.context.diagnose(); await h.settle();
  h.calls[0].resolve(h.response('old')); await old;
  assert.equal(h.context.operationRootCause.value, null);
  assert.equal(h.context.operationLoading.value.rootCause, true);
  h.calls[1].resolve(h.response('current', h.scope(8))); await current;
  assert.equal(h.context.operationRootCause.value.marker, 'current');
});
test('a failed reread clears previously displayed data', async () => {
  const h = harness();
  h.context.operationFullData.value = h.response('old').data;
  const pending = h.context.readFacts(); await h.settle();
  h.calls[0].reject(new Error('read failed')); await pending;
  assert.equal(h.context.operationFullData.value, null);
  assert.match(h.context.operationError.value.fullData, /read failed/);
});
test('an all-visible-hotels response remains explicitly scoped as aggregate', async () => {
  const h = harness(); h.change('');
  const pending = h.context.readFacts(); await h.settle();
  h.calls[0].resolve(h.response('aggregate', h.scope(0))); await pending;
  assert.equal(h.context.operationFullData.value.marker, 'aggregate');
});
