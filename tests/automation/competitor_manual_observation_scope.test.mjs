import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const source = fs.readFileSync('public/app-main.js', 'utf8');
const slice = (start, end) => {
  const a = source.indexOf(start), b = source.indexOf(end, a);
  assert.ok(a >= 0 && b > a, `${start} / ${end}`);
  return source.slice(a, b);
};
const scopeHelpers = slice('const captureOnlineAnalysisRequestOwner =', 'const resetOnlineAnalysisSessionState =');
const observation = slice('const competitorObservationOffsetDate =', 'const refreshOnlineAnalysis =');
const deferred = () => { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b; }); return { promise, resolve, reject }; };
const drain = async () => { for (let i = 0; i < 10; i++) await Promise.resolve(); };
const values = { competitor_hotel_id: '71', collected_at: '2026-09-27T09:00', availability: 'bookable', price: '318', source_surface: 'public_hotel_page', source_ref: 'https://hotels.ctrip.com/hotels/123456.html' };

function fixture() {
  const targets = [], dialogs = [], posts = [], notices = [], refreshes = [];
  const state = {
    onlineDataFilter: { value: { hotel_id: '7', source: 'ctrip', end_date: '2026-09-28' } },
    authContext: { value: { tenantId: 42 } }, user: { value: { id: 11 } },
    competitorManualObservationSaving: { value: false }, session: 1, permission: true,
  };
  const sandbox = { ...state, Date, URLSearchParams,
    competitorEventFeedStayDate: { get value() { return state.onlineDataFilter.value.end_date; } },
    captureAuthSession: () => state.session,
    isAuthSessionCurrent: session => session === state.session,
    canCollectCompetitorObservations: () => state.permission,
    competitorEventPlatformText: value => value,
    showToast: (message, type = 'success') => notices.push({ message, type }),
    openWorkflowFormDialog: options => { const d = deferred(); dialogs.push({ options, ...d }); return d.promise; },
    loadCompetitorEventFeed: async () => refreshes.push({ ...state.onlineDataFilter.value }),
    request: (url, options) => {
      const d = deferred();
      if (options?.method === 'POST') posts.push({ url, body: JSON.parse(options.body), ...d });
      else targets.push({ url, ...d });
      return d.promise;
    },
  };
  vm.createContext(sandbox);
  vm.runInContext(`${scopeHelpers}\n${observation}\nglobalThis.open = openCompetitorManualObservation;`, sandbox);
  const answerTargets = index => targets[index].resolve({ code: 200, data: { targets: [{ id: 71, platform: 'ctrip', hotel_name: '合成竞品', ota_hotel_id: '123456' }] } });
  return { state, targets, dialogs, posts, notices, refreshes, open: sandbox.open, answerTargets };
}

const changes = {
  hotel: p => { p.state.onlineDataFilter.value.hotel_id = '8'; },
  date: p => { p.state.onlineDataFilter.value.end_date = '2026-09-29'; },
  platform: p => { p.state.onlineDataFilter.value.source = 'meituan'; },
  account: p => { p.state.session++; },
  tenant: p => { p.state.authContext.value.tenantId = 43; },
};

for (const [label, change] of Object.entries(changes)) {
  test(`target lookup cannot open the old observation dialog after ${label} changes`, async () => {
    const p = fixture(), pending = p.open();
    change(p); p.answerTargets(0); await drain();
    const opened = p.dialogs.length;
    p.dialogs.forEach(dialog => dialog.resolve(null)); await pending;
    assert.equal(opened, 0); assert.equal(p.posts.length, 0);
    assert.equal(p.state.competitorManualObservationSaving.value, false);
  });
  test(`submitting a dialog after ${label} changes cannot write the old scope`, async () => {
    const p = fixture(), pending = p.open(); p.answerTargets(0); await drain();
    change(p); p.dialogs[0].resolve(values); await drain();
    const count = p.posts.length; p.posts.forEach(post => post.resolve({ code: 200, data: { readback_verified: true } })); await pending;
    assert.equal(count, 0); assert.equal(p.refreshes.length, 0);
  });
}

test('only one observation operation owns lookup, editor and save until it finishes', async () => {
  const p = fixture(), first = p.open(), duplicateLookup = p.open();
  const lookupCount = p.targets.length;
  p.targets.forEach((_, i) => p.answerTargets(i)); await drain();
  const duplicateDialog = p.open();
  p.targets.slice(lookupCount).forEach((_, i) => p.answerTargets(lookupCount + i)); await drain();
  const dialogCount = p.dialogs.length;
  p.dialogs.forEach(dialog => dialog.resolve(null));
  await Promise.all([first, duplicateLookup, duplicateDialog]);
  assert.equal(lookupCount, 1); assert.equal(dialogCount, 1);
  assert.equal(p.state.competitorManualObservationSaving.value, false);
});

test('successful readback in an obsolete scope cannot refresh or claim success in the new scope', async () => {
  const p = fixture(), pending = p.open(); p.answerTargets(0); await drain();
  p.dialogs[0].resolve(values); await drain(); changes.hotel(p);
  p.posts[0].resolve({ code: 200, data: { readback_verified: true } }); await pending;
  assert.equal(p.notices.length, 0); assert.equal(p.refreshes.length, 0);
  assert.equal(p.state.competitorManualObservationSaving.value, false);
});

test('lookup failure releases the operation, and the next current form saves exact dates and a sold-out null price', async () => {
  const p = fixture(), failed = p.open(); p.targets[0].reject(new Error('合成读取失败')); await failed;
  assert.equal(p.state.competitorManualObservationSaving.value, false);
  assert.match(p.notices[0].message, /读取失败/);
  const pending = p.open(); p.answerTargets(1); await drain();
  p.dialogs[0].resolve({ ...values, availability: 'sold_out', price: '0' }); await drain();
  assert.equal(p.posts[0].body.system_hotel_id, 7);
  assert.equal(p.posts[0].body.check_in_date, '2026-09-28');
  assert.equal(p.posts[0].body.check_out_date, '2026-09-29');
  assert.equal(p.posts[0].body.platform, 'ctrip'); assert.equal(p.posts[0].body.price, null);
  p.posts[0].resolve({ code: 200, data: { readback_verified: true, idempotent_replay: true } }); await pending;
  assert.equal(p.refreshes.length, 1); assert.match(p.notices.at(-1).message, /相同观测已存在/);
  assert.equal(p.state.competitorManualObservationSaving.value, false);
});

test('missing readback is an explicit failure, and permission loss prevents submitting the form', async () => {
  const p = fixture(), pending = p.open(); p.answerTargets(0); await drain();
  p.dialogs[0].resolve(values); await drain(); p.posts[0].resolve({ code: 200, data: { readback_verified: false } }); await pending;
  assert.equal(p.refreshes.length, 0); assert.match(p.notices.at(-1).message, /回读失败/);
  const retry = p.open(); p.answerTargets(1); await drain(); p.state.permission = false;
  p.dialogs[1].resolve(values); await drain();
  const count = p.posts.length; p.posts.slice(1).forEach(post => post.resolve({ code: 403, message: '权限已变更' })); await retry;
  assert.equal(count, 1); assert.equal(p.state.competitorManualObservationSaving.value, false);
});

for (const [source, platform] of [['ctrip','ctrip'], ['xc','ctrip'], ['meituan','meituan'], ['mt','meituan'], ['','all']]) {
  test(`current empty ${source || 'all'} targets retain the platform-specific message and allow a retry`, async () => {
    const p = fixture(); p.state.onlineDataFilter.value.source = source;
    const pending = p.open();
    assert.equal(new URL(p.targets[0].url,'http://fixture.invalid').searchParams.get('platform'),platform);
    p.targets[0].resolve({code:200,data:{targets:[]}}); await pending;
    assert.match(p.notices[0].message, platform==='meituan' ? /无美团竞品目标.*binding_missing/ : /没有已配置竞品目标/);
    assert.equal(p.notices[0].type,'error'); assert.equal(p.dialogs.length,0);
    assert.equal(p.state.competitorManualObservationSaving.value,false);
  });
}
