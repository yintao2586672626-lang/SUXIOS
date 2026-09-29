import assert from 'node:assert/strict';
import fs from 'node:fs';
import { createRequire } from 'node:module';
import nodeTest from 'node:test';
import vm from 'node:vm';

const test = (name, body) => nodeTest(name, { timeout: 5000 }, body);
const { ref, computed } = createRequire(new URL('../../package.json', import.meta.url))('vue');
const source = fs.readFileSync(
  process.env.SUXIOS_CTRIP_BUNDLE_SOURCE || new URL('../../public/app-main.js', import.meta.url),
  'utf8',
);
const staticContext = vm.createContext({ window: {}, console });
vm.runInContext(fs.readFileSync(new URL('../../public/ctrip-static.js', import.meta.url), 'utf8'), staticContext);
const section = (start, end) => {
  const a = source.indexOf(start);
  const b = source.indexOf(end, a + start.length);
  assert.ok(a >= 0 && b > a, `${start} / ${end}`);
  return source.slice(a, b);
};
const production = [
  section('const currentPlatformHotelId =', 'const ctripSearchOpportunityPayload ='),
  section('const clearCtripOverviewDisplayState =', 'const applyCtripHotelConfig ='),
  section('const ctripTrafficFetchFailureMessage =', 'const ctripCompetitionBatchRowMeta ='),
  section('const prepareCtripOverviewFetchAction =', 'const handleCtripTrafficHotelChange ='),
].join('\n');
const plain = value => JSON.parse(JSON.stringify(value));
const flush = async () => { for (let i = 0; i < 30; i++) await Promise.resolve(); };
const deferred = () => {
  let resolve, reject;
  const row = { settled: false };
  row.promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  row.resolve = value => { row.settled = true; resolve(value); };
  row.reject = error => { row.settled = true; reject(error); };
  return row;
};
const captureSuccess = () => ({
  status: 'success',
  response: { code: 200, data: { saved_count: 1, is_ready: true, responses: [] } },
});
const captureFailure = () => ({ status: 'not_ready', message: '合成接口未完成，请重试' });
const stages = ['history', 'capture', 'future', 'realtime'];

function fixture({ hold = [], selected = '7' } = {}) {
  const calls = { history: [], capture: [], future: [], realtime: [], prepare: [], notices: [], health: [] };
  const controls = { hold: new Set(hold), captureOutcome: null };
  const refs = {};
  for (const match of production.matchAll(/\b([A-Za-z_$][\w$]*)\.value\b/g)) {
    refs[match[1]] ??= ref(null);
  }
  Object.assign(refs, {
    selectedCtripHotelId: ref(selected),
    meituanForm: ref({ hotelId: '' }),
    user: ref({ id: 11, tenant_id: 70, hotel_id: '7' }),
    authContext: ref({ tenantId: 70 }),
    currentPage: ref('ctrip-ebooking'),
    onlineDataTab: ref('ctrip-traffic'),
    ctripTargetHotelManuallySelected: ref(false),
    ctripConfigList: ref([{ id: 'synthetic-7', hotel_id: '7' }, { id: 'synthetic-8', hotel_id: '8' }]),
    ctripTrafficBundleLoading: ref(false),
    ctripOverviewFetchActionLoading: ref(''),
    ctripOverviewCoreFetchRunning: ref(false),
    ctripCookieApiRunning: ref(false),
    fetchingData: ref(false),
    ctripRealtimeTrafficRecord: ref(null),
    ctripSearchOpportunityPayload: ref(null),
    ctripSearchOpportunityError: ref(''),
  });
  const session = ref(1);
  refs.ctripSearchOpportunityView = computed(() => refs.ctripSearchOpportunityPayload.value || { status: 'not_collected' });
  const defaults = kind => kind === 'capture'
    ? (controls.captureOutcome || captureSuccess())
    : { status: 'success' };
  const dependency = kind => (...args) => {
    const row = Object.assign(deferred(), { hotel: String(refs.selectedCtripHotelId.value || ''), args });
    calls[kind].push(row);
    if (kind === 'future') refs.ctripSearchOpportunityError.value = '';
    if (!controls.hold.has(kind)) row.resolve(defaults(kind));
    return row.promise;
  };
  const reviewBindings = { invalidateCtripReviewMatch: () => {} };
  for (const match of production.matchAll(/ctripReviewMatchControllerBindings\.([\w$]+)\.value/g)) {
    reviewBindings[match[1]] = ref(null);
  }
  const context = vm.createContext({
    ...refs, ref, computed, console, Date, JSON,
    requireCtripStatic: key => staticContext.window.SUXI_CTRIP_STATIC[key],
    session,
    captureAuthSession: () => ({ epoch: session.value }),
    isAuthSessionCurrent: owner => owner?.epoch === session.value,
    showToast: (message, type = 'success') => calls.notices.push({ message, type }),
    formatDate: () => '2026-09-27',
    ctripManualFetchActive: false,
    ctripPlatformHotelContextEpoch: 0,
    meituanPlatformHotelContextEpoch: 0,
    ctripTrafficBundleRequestSeq: 0,
    ctripOverviewFetchActionRequestSeq: 0,
    ctripCookieApiCaptureRequestSeq: 0,
    ctripReviewMatchControllerBindings: reviewBindings,
    readPlatformHotelContext: () => '',
    ctripTargetHotelOptionExists: value => ['7', '8'].includes(String(value || '')),
    MANUAL_CONFIG_LIST_TAB_CACHE_TTL_MS: 1000,
    loadCtripConfigList: async () => refs.ctripConfigList.value,
    applyCtripHotelConfig: dependency('prepare'),
    fetchCtripTrafficData: dependency('history'),
    runCtripOverviewCookieApiCapture: dependency('capture'),
    extractCtripRealtimeTrafficSnapshot: () => ({ status: 'available', pv: 0, uv: 0, conversion_rate: 0 }),
    loadCtripSearchOpportunity: dependency('future'),
    loadLatestCtripData: dependency('realtime'),
    scheduleDataHealthPanelRefresh: (...args) => calls.health.push(args),
  });
  // The production reset increments several independent read sequences. Supply
  // isolated counters, while executing its actual assignments and invalidation.
  for (const match of production.matchAll(/\b([A-Za-z_$][\w$]*)\s*\+=\s*1/g)) {
    if (!(match[1] in context)) context[match[1]] = 0;
  }
  vm.runInContext(`${production}\n;globalThis.api = {
    bundle: fetchCtripTrafficAndSearchData,
    manual: runCtripTrafficManualCapture,
    step: runCtripTrafficBundleStep,
    overview: runCtripOverviewFetchActionInternal,
    clear: clearCtripOverviewDisplayState,
  };`, context, { filename: 'ctrip-traffic-bundle-scope-production.js' });
  const settle = (kind, value = defaults(kind), reject = false, index = 0) => {
    const pending = calls[kind].filter(row => !row.settled)[index];
    assert.ok(pending, `pending ${kind} #${index}`);
    if (reject) pending.reject(value); else pending.resolve(value);
  };
  const changeScope = kind => {
    if (kind === 'auth') {
      session.value += 1;
      refs.user.value = { id: 12, tenant_id: 71, hotel_id: '7' };
      refs.authContext.value = { tenantId: 71 };
      context.api.clear();
    } else {
      refs.selectedCtripHotelId.value = '8';
      context.api.clear();
      if (kind === 'aba') {
        refs.selectedCtripHotelId.value = '7';
        context.api.clear();
      }
    }
  };
  const marker = () => {
    refs.ctripRealtimeTrafficRecord.value = { status: 'available', marker: 'new scope', uv: 0 };
    refs.ctripSearchOpportunityPayload.value = { status: 'not_collected', marker: 'new scope' };
    refs.ctripSearchOpportunityError.value = '新范围提示';
    return {
      realtime: plain(refs.ctripRealtimeTrafficRecord.value),
      payload: plain(refs.ctripSearchOpportunityPayload.value),
      error: refs.ctripSearchOpportunityError.value,
      notices: calls.notices.length,
      requests: stages.map(kind => calls[kind].length),
      health: calls.health.length,
    };
  };
  const assertUntouched = before => {
    assert.deepEqual(plain(refs.ctripRealtimeTrafficRecord.value), before.realtime);
    assert.deepEqual(plain(refs.ctripSearchOpportunityPayload.value), before.payload);
    assert.equal(refs.ctripSearchOpportunityError.value, before.error);
    assert.equal(calls.notices.length, before.notices, 'old owner must not notify');
    assert.deepEqual(stages.map(kind => calls[kind].length), before.requests, 'old owner must not start another step');
    assert.equal(calls.health.length, before.health, 'old owner must not schedule a health refresh');
  };
  return { ...refs, context, calls, controls, session, api: context.api, settle, changeScope, marker, assertUntouched };
}

test('the current bundle performs its four steps and preserves a real zero realtime response', async () => {
  const f = fixture();
  const result = await f.api.bundle();
  assert.deepEqual(stages.map(kind => f.calls[kind].length), [1, 1, 1, 1]);
  assert.equal(result.captureResult.status, 'success');
  assert.equal(f.ctripRealtimeTrafficRecord.value.status, 'available');
  assert.equal(f.ctripRealtimeTrafficRecord.value.uv, 0);
  assert.equal(f.ctripRealtimeTrafficRecord.value.conversion_rate, 0);
  assert.equal(f.calls.realtime[0].args[0].hotelId, '7');
  assert.equal(f.calls.realtime[0].args[0].hydrateRealtime, true);
  assert.equal(f.ctripTrafficBundleLoading.value, false);
  assert.equal(f.ctripOverviewFetchActionLoading.value, '');
});

test('a current incomplete capture remains an explicit failure and a later retry can recover', async () => {
  const f = fixture();
  f.controls.captureOutcome = captureFailure();
  const first = await f.api.bundle();
  assert.equal(first.captureResult.status, 'not_ready');
  assert.equal(f.ctripRealtimeTrafficRecord.value.status, 'error');
  assert.equal(f.ctripSearchOpportunityError.value, captureFailure().message);
  assert.ok(f.calls.notices.some(row => row.type === 'error' && row.message === captureFailure().message));
  assert.equal(f.ctripTrafficBundleLoading.value, false);
  f.controls.captureOutcome = null;
  const next = await f.api.bundle();
  assert.equal(next.captureResult.status, 'success');
  assert.equal(f.ctripRealtimeTrafficRecord.value.status, 'available');
  assert.equal(f.ctripSearchOpportunityError.value, '');
  assert.deepEqual(stages.map(kind => f.calls[kind].length), [2, 2, 2, 2]);
});

test('a second bundle click cannot duplicate the pending history operation', async () => {
  const f = fixture({ hold: ['history'] });
  const first = f.api.bundle();
  await flush();
  assert.equal(f.ctripTrafficBundleLoading.value, true);
  await f.api.bundle();
  assert.equal(f.calls.history.length, 1);
  f.settle('history');
  await first;
  assert.equal(f.calls.capture.length, 1);
  assert.equal(f.ctripTrafficBundleLoading.value, false);
});

for (const stage of stages) {
  for (const scope of ['auth', 'hotel', 'aba']) {
    test(`${scope} invalidation at ${stage} prevents old writes, notices and subsequent requests`, async () => {
      const f = fixture({ hold: [stage] });
      const work = f.api.bundle();
      await flush();
      assert.equal(f.calls[stage].length, 1);
      f.changeScope(scope);
      const before = f.marker();
      f.settle(stage);
      const result = await work;
      assert.equal(result?.status, 'stale');
      f.assertUntouched(before);
    });
  }
}

for (const stage of stages) {
  test(`an old ${stage} rejection after authentication reset remains silent`, async () => {
    const f = fixture({ hold: [stage] });
    const work = f.api.bundle();
    await flush();
    assert.equal(f.calls[stage].length, 1);
    f.changeScope('auth');
    const before = f.marker();
    f.settle(stage, new Error(`synthetic old ${stage} failure`), true);
    const result = await work;
    assert.equal(result?.status, 'stale');
    f.assertUntouched(before);
  });
}

test('actual clear releases the old bundle and action locks, and the old finally cannot unlock the new capture', async () => {
  const f = fixture({ hold: ['capture'] });
  const oldWork = f.api.bundle();
  await flush();
  assert.equal(f.calls.capture.length, 1);
  f.changeScope('auth');
  assert.equal(f.ctripTrafficBundleLoading.value, false, 'reset must make a new operation reachable');
  assert.equal(f.ctripOverviewFetchActionLoading.value, '');
  const nextWork = f.api.bundle();
  await flush();
  assert.equal(f.calls.capture.length, 2);
  assert.equal(f.ctripTrafficBundleLoading.value, true);
  assert.equal(f.ctripOverviewFetchActionLoading.value, 'ctrip-traffic');
  const before = f.marker();
  f.settle('capture', { status: 'stale' });
  assert.equal((await oldWork)?.status, 'stale');
  f.assertUntouched(before);
  assert.equal(f.ctripTrafficBundleLoading.value, true);
  assert.equal(f.ctripOverviewFetchActionLoading.value, 'ctrip-traffic');
  f.settle('capture', captureSuccess());
  assert.equal((await nextWork).captureResult.status, 'success');
  assert.equal(f.ctripTrafficBundleLoading.value, false);
  assert.equal(f.ctripOverviewFetchActionLoading.value, '');
});

test('manual capture treats an explicit lower-level stale result as cancellation rather than failure', async () => {
  const f = fixture({ hold: ['capture'] });
  const work = f.api.manual();
  await flush();
  f.settle('capture', { status: 'stale', platform: 'ctrip', hotelId: '7' });
  const result = await work;
  assert.equal(result.status, 'stale');
  assert.equal(f.calls.notices.length, 0);
  assert.notEqual(f.ctripRealtimeTrafficRecord.value?.status, 'error');
});

test('the step helper reports a current exception but suppresses the same exception for an obsolete owner', async () => {
  const f = fixture();
  const current = await f.api.step('历史数据', async () => { throw new Error('合成当前失败'); }, () => true);
  assert.equal(current.status, 'exception');
  assert.ok(f.calls.notices.some(row => row.type === 'error' && row.message.includes('合成当前失败')));
  const noticeCount = f.calls.notices.length;
  const old = await f.api.step('历史数据', async () => { throw new Error('合成旧失败'); }, () => false);
  assert.equal(old.status, 'stale');
  assert.equal(f.calls.notices.length, noticeCount);
});

test('overview preparation may synchronously align an empty selection to the allowed primary hotel', async () => {
  const f = fixture({ selected: '' });
  const result = await f.api.overview('ctrip-traffic');
  assert.equal(f.selectedCtripHotelId.value, '7');
  assert.equal(f.calls.prepare.length, 1);
  assert.equal(f.calls.capture.length, 1);
  assert.equal(f.calls.capture[0].hotel, '7');
  assert.equal(result.status, 'success');
  assert.equal(f.ctripOverviewFetchActionLoading.value, '');
});

test('authentication replacement while overview preparation awaits prevents its action and follow-up refresh', async () => {
  const f = fixture({ selected: '', hold: ['prepare'] });
  const work = f.api.overview('ctrip-traffic');
  await flush();
  assert.equal(f.selectedCtripHotelId.value, '7');
  assert.equal(f.calls.prepare.length, 1);
  assert.equal(f.calls.capture.length, 0);
  f.changeScope('auth');
  const before = f.marker();
  f.settle('prepare');
  assert.equal((await work)?.status, 'stale');
  f.assertUntouched(before);
});

test('the overview action busy lock prevents another action while preparation is pending', async () => {
  const f = fixture({ hold: ['prepare'] });
  const work = f.api.overview('ctrip-traffic');
  await flush();
  assert.equal(f.calls.prepare.length, 1);
  await f.api.overview('ctrip-quality');
  assert.equal(f.calls.prepare.length, 1);
  assert.equal(f.calls.capture.length, 0);
  f.settle('prepare');
  await work;
  assert.equal(f.calls.capture.length, 1);
});
