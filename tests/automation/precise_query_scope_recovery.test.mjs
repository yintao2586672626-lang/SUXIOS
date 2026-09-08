import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

// Execute the product request and recovery functions with synthetic transport/storage.
const source = readFileSync('public/components/system/operating-intelligence-components.js', 'utf8');
const start = source.indexOf('const operatingQuestionConsultant =');
const slice = (a, b) => source.slice(source.indexOf(a, start), source.indexOf(b, source.indexOf(a, start)));
const deferred = () => { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b; }); return { promise, resolve, reject }; };
const storage = () => { const values = new Map(); return { getItem: key => values.get(key), setItem: (key, value) => values.set(key, value), removeItem: key => values.delete(key) }; };
function harness(transport, stores = {}) {
  const ctx = {
    currentPage: 'compass', filterReportHotel: '7', homeRevenueFactBusinessDate: '2026-09-07',
    user: { id: 901, tenant_id: 1, hotel_id: 7 }, authContext: { tenantId: 1 },
    operatingQuestionForm: { hotel_id: '7', platform: 'ctrip', date_start: '2026-09-07', date_end: '2026-09-07' },
    managerCapabilityRequest: transport,
  };
  const state = { value: { query: '查询曝光人数', turns: [], selected_mode: 'auto', loading: false, error: '' } };
  const sandbox = vm.createContext({
    props: { ctx }, state, console, Date, Map, JSON, Math,
    localStorage: stores.local || storage(), sessionStorage: stores.session || storage(), preciseQueryStorageVersion: 1,
    latestPreciseOperatingScope: () => state.value.turns.at(-1)?.result?.precise_query_scope,
    currentPageText: () => '经营工作台', visibleTopicKeys: () => [], activeJourneyContext: () => null,
    conversationHistory: () => [], saveActiveJourney: () => {},
    normalizePreciseQueryResult: exact => ({ ...exact, precise_query_id: exact.id }),
    normalizeResult: value => value, runOperatingWorkflow: () => { throw new Error('Failure must not run another workflow'); },
    revealPreciseQueryFeedback: async () => {},
  });
  vm.runInContext(slice('let preciseRequestGeneration =', 'const widgetRoot =')
    + slice('const preciseQueryStorageKey =', 'const savePendingCoach =')
    + slice('const ask = async () =>', 'const openTopic = async')
    + '\nglobalThis.api = { ask, restorePreciseQueryReadback, resetPreciseQueryScope, savePreciseQueryPointer, preciseQueryStorageKey };', sandbox);
  return { ctx, state: state.value, api: sandbox.api, sandbox };
}
const record = (id, question = '查询曝光人数') => ({ id, question, content_digest: 'a'.repeat(64), persistence_status: 'readback_verified', route_type: 'operating_query', parsed_scope: { hotel_id: 7, platform: 'ctrip', business_date: '2026-09-07' } });
const response = data => ({ code: 200, data });

test('late hotel A success and finally cannot overwrite B or clear B loading', async () => {
  const a = deferred(), b = deferred(); let post = 0;
  const h = harness((path, options) => options ? (++post === 1 ? a.promise : b.promise) : response(record(2)));
  const old = h.api.ask();
  h.ctx.filterReportHotel = '8'; h.api.resetPreciseQueryScope(); h.state.query = '查询曝光人数';
  const current = h.api.ask();
  a.resolve(response(record(1))); await old;
  assert.equal(h.state.loading, true); assert.equal(h.state.turns.length, 0);
  b.resolve(response(record(2))); await current;
  assert.equal(h.state.turns[0].result.id, 2); assert.equal(h.state.loading, false);
});
test('late failure after hotel/date/platform/session changes produces no current error or fallback', async () => {
  for (const change of [h => h.ctx.filterReportHotel = '8', h => h.ctx.homeRevenueFactBusinessDate = '2026-09-06', h => h.ctx.operatingQuestionForm.platform = 'meituan', h => h.ctx.user = { ...h.ctx.user }]) {
    const wait = deferred(); const h = harness(() => wait.promise); const old = h.api.ask();
    change(h); h.api.resetPreciseQueryScope(); h.state.loading = true;
    wait.reject(new Error('synthetic late network failure')); await old;
    assert.equal(h.state.error, ''); assert.equal(h.state.loading, true); assert.equal(h.state.turns.length, 0);
  }
});
test('POST timeout retry keeps the same client key and payload; a new question has a new key', async () => {
  const bodies = []; let fail = true;
  const h = harness((path, options) => {
    if (!options) return response(record(3, bodies.at(-1).query));
    bodies.push(JSON.parse(options.body));
    if (fail) { fail = false; throw new Error('synthetic timeout'); }
    return response(record(3, bodies.at(-1).query));
  });
  assert.equal(await h.api.ask(), false); assert.equal(h.state.turns.length, 0); assert.match(h.state.error, /保存结果尚未确认/);
  assert.equal(await h.api.ask(), true); assert.deepEqual(bodies[0], bodies[1]);
  h.state.query = '查询间夜'; await h.api.ask();
  assert.notEqual(bodies[1].client_request_key, bodies[2].client_request_key);
  assert.equal(bodies[2].parent_question_id, 3);
});
test('GET failure after save retries only GET of the same ID, including refresh recovery', async () => {
  const stores = { local: storage(), session: storage() }; const calls = []; let fail = true;
  const transport = (path, options) => {
    calls.push([path, options?.method || 'GET']);
    if (options) return response(record(41));
    if (fail) throw new Error('synthetic read timeout');
    return response(record(41));
  };
  const h = harness(transport, stores); await h.api.ask(); assert.match(h.state.error, /#41 已保存/);
  const refreshed = harness(transport, stores); refreshed.state.query = '';
  await refreshed.api.restorePreciseQueryReadback(); assert.equal(refreshed.state.query, '查询曝光人数');
  fail = false; await refreshed.api.ask();
  assert.equal(calls.filter(call => call[1] === 'POST').length, 1);
  assert.equal(refreshed.state.turns[0].result.id, 41);
  const again = harness(transport, stores); await again.api.restorePreciseQueryReadback();
  assert.equal(again.state.turns[0].result.id, 41);
});
test('refresh and hotel change cannot replay another scope pointer or pending request', async () => {
  const stores = { local: storage(), session: storage() }; let reads = 0;
  const transport = () => { reads++; return response(record(7)); };
  const h = harness(transport, stores); h.api.savePreciseQueryPointer(record(7));
  h.ctx.filterReportHotel = '8'; await h.api.restorePreciseQueryReadback();
  assert.equal(reads, 0); assert.equal(h.state.turns.length, 0);
});
test('legacy same-scope pointer is readable but different scope stays visibly blocked', async () => {
  for (const hotel of ['7', '8']) {
    const local = storage(); local.setItem('suxios_precise_query_last_v1:901', JSON.stringify({ version: 1, id: 7, content_digest: 'a'.repeat(64) }));
    const h = harness(() => response(record(7)), { local }); h.ctx.filterReportHotel = hotel;
    const restored = await h.api.restorePreciseQueryReadback();
    assert.equal(restored, hotel === '7');
    if (hotel === '8') assert.match(h.state.error, /范围与当前/);
  }
});
test('new query supersedes delayed restore and old finally does not clear the new loading flag', async () => {
  const restore = deferred(), save = deferred(); const h = harness((path, options) => options ? save.promise : restore.promise);
  h.api.savePreciseQueryPointer(record(1)); const old = h.api.restorePreciseQueryReadback();
  const current = h.api.ask(); restore.resolve(response(record(1))); await old;
  assert.equal(h.state.turns.length, 0); assert.equal(h.state.loading, true);
  save.reject(new Error('synthetic timeout')); await current;
});
test('duplicate submit during a pending request sends one POST', async () => {
  const wait = deferred(); let calls = 0; const h = harness(() => { calls++; return wait.promise; });
  const first = h.api.ask(); assert.equal(await h.api.ask(), false); assert.equal(calls, 1);
  wait.reject(new Error('synthetic timeout')); await first;
});
test('saving-in-progress 429 preserves the entire payload and key until the same object is read back', async () => {
  const payloads = [];
  const h = harness((path, options) => {
    if (!options) return response(record(55));
    payloads.push(JSON.parse(options.body));
    return payloads.length === 1 ? { code: 429, message: 'synthetic request still saving' } : response(record(55));
  });
  await h.api.ask(); assert.match(h.state.error, /still saving/);
  h.ctx.currentPage = 'online-data';
  await h.api.ask();
  assert.deepEqual(payloads[0], payloads[1], 'retry must not regenerate current_page, history, parent or key');
  assert.equal(h.state.turns[0].result.id, 55);
});
test('cleared homepage hotel/date stay missing rather than falling back to the user or another form', async () => {
  let payload;
  const h = harness((path, options) => { payload = JSON.parse(options.body); return { code: 422, message: 'synthetic missing scope' }; });
  h.ctx.filterReportHotel = ''; h.ctx.homeRevenueFactBusinessDate = '';
  await h.api.ask();
  assert.equal(payload.current_scope.hotel_id, 0);
  assert.equal(payload.current_scope.date_start, ''); assert.equal(payload.current_scope.date_end, '');
  assert.equal(h.state.turns.length, 0);
});
