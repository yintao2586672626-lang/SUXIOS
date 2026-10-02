import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { ref } from 'vue';

const source = readFileSync('public/app-main.js', 'utf8');
const extract = (start, end) => {
  const from = source.indexOf(start);
  const to = source.indexOf(end, from + start.length);
  assert.ok(from >= 0 && to > from, `missing source slice: ${start}`);
  return source.slice(from, to);
};
const refreshSource = extract('const refreshMeituanTemporal = async', 'const meituanTemporalMetricText =');
const hotelClearSource = extract('const clearMeituanPlatformHotelScopedState =', 'const selectMeituanRankingDateRange =');
const scopeInvalidation = hotelClearSource.match(/meituanTemporalLoadSeq \+= 1;\s*meituanTemporalScopeEpoch \+= 1;/)?.[0];
assert.ok(scopeInvalidation, 'the original hotel-change handler must invalidate the refresh scope');

const harness = () => {
  let hotelId = '80';
  let session = 1;
  const posts = [];
  const notices = [];
  const reloads = [];
  const busy = ref(false);
  const error = ref('');
  let state;
  const context = {
    meituanTemporalRefreshing: busy,
    meituanTemporalError: error,
    ensureMeituanTemporalHotelId: () => hotelId,
    resolveMeituanTemporalHotelId: () => hotelId,
    meituanTemporalAsOfDate: () => '2026-09-26',
    captureAuthSession: () => session,
    isAuthSessionCurrent: captured => captured === session,
    URLSearchParams,
    showToast: (...args) => notices.push(args),
    loadMeituanTemporalSummary: async () => {
      state.advanceLoadSeq();
      reloads.push(['summary', hotelId]);
    },
    loadOnlineDataList: async () => { reloads.push(['list', hotelId]); },
    request: (url, options) => new Promise((resolve, reject) => {
      assert.equal(url, '/online-data/meituan-temporal-refresh');
      posts.push({ body: JSON.parse(options.body), resolve, reject });
    }),
  };
  state = vm.runInNewContext(`(() => {
    let meituanTemporalLoadSeq = 0;
    let meituanTemporalScopeEpoch = 0;
    ${refreshSource}
    return { refresh: refreshMeituanTemporal, advanceLoadSeq: () => { meituanTemporalLoadSeq += 1; }, invalidateScope: () => {
      ${scopeInvalidation}
    } };
  })()`, context);
  return {
    ...state, posts, notices, reloads, busy, error,
    switchHotel(nextHotelId) {
      hotelId = nextHotelId;
      state.invalidateScope();
      if (/meituanTemporalRefreshing\.value\s*=\s*false;/.test(hotelClearSource)) busy.value = false;
    },
    changeSession: () => { session += 1; },
  };
};

test('hotel switch keeps one capture in flight and ignores the old hotel receipt', async () => {
  const view = harness();
  const old = view.refresh();
  assert.equal(view.posts[0].body.system_hotel_id, 80);
  view.switchHotel('81');
  assert.equal(view.busy.value, true, 'a hotel switch must not release an active capture');
  await view.refresh();
  assert.equal(view.posts.length, 1, 'another hotel cannot start a concurrent capture');

  view.posts[0].resolve({ code: 200, data: { status: 'completed' } });
  await old;
  assert.equal(view.busy.value, false);
  assert.equal(view.notices.length, 0);
  assert.equal(view.reloads.length, 0);
  assert.equal(view.error.value, '');

  const current = view.refresh();
  assert.equal(view.posts[1].body.system_hotel_id, 81);
  view.posts[1].resolve({ code: 200, data: { status: 'completed' } });
  await current;
  assert.deepEqual(view.reloads, [['summary', '81'], ['list', '81']]);
  assert.equal(view.notices.length, 1);
});

test('A to B to A still rejects the old receipt and old failure', async () => {
  for (const outcome of ['success', 'failure']) {
    const view = harness();
    const pending = view.refresh();
    view.switchHotel('81');
    view.switchHotel('80');
    if (outcome === 'success') view.posts[0].resolve({ code: 200, data: { status: 'completed' } });
    else view.posts[0].reject(new Error('Old hotel request failed'));
    await pending;
    assert.equal(view.notices.length, 0);
    assert.equal(view.reloads.length, 0);
    assert.equal(view.error.value, '');
    assert.equal(view.busy.value, false);
  }
});

test('current hotel failure is visible and retryable; repeated click makes one request', async () => {
  const view = harness();
  const pending = view.refresh();
  const duplicate = view.refresh();
  assert.equal(view.posts.length, 1);
  view.posts[0].reject(new Error('Synthetic capture failure'));
  await Promise.all([pending, duplicate]);
  assert.equal(view.error.value, 'Synthetic capture failure');
  assert.equal(view.notices.length, 1);
  assert.equal(view.busy.value, false);

  const retry = view.refresh();
  assert.equal(view.posts.length, 2);
  view.posts[1].resolve({ code: 200, data: { status: 'completed' } });
  await retry;
  assert.equal(view.error.value, '');
  assert.deepEqual(view.reloads, [['summary', '80'], ['list', '80']]);
});

test('a new login session suppresses the old capture feedback', async () => {
  const view = harness();
  const pending = view.refresh();
  view.changeSession();
  view.posts[0].resolve({ code: 200, data: { status: 'completed' } });
  await pending;
  assert.equal(view.notices.length, 0);
  assert.equal(view.reloads.length, 0);
  assert.equal(view.error.value, '');
  assert.equal(view.busy.value, false);
});

test('current hotel Profile block remains visible and refreshes its read view', async () => {
  const view = harness();
  const pending = view.refresh();
  view.posts[0].resolve({ code: 200, data: { status: 'blocked', message: 'Profile login required' } });
  await pending;
  assert.deepEqual(view.notices, [['Profile login required', 'error']]);
  assert.deepEqual(view.reloads, [['summary', '80'], ['list', '80']]);
  assert.equal(view.busy.value, false);
});

test('partial refresh warns about the incomplete result and still reads saved sections', async () => {
  const view = harness();
  const pending = view.refresh();
  view.posts[0].resolve({ code: 200, data: {
    status: 'partial', reason_code: 'before_future_platform_update_window',
  } });
  await pending;
  assert.equal(view.notices.length, 1);
  assert.equal(view.notices[0][1], 'warning');
  assert.match(view.notices[0][0], /部分/);
  assert.doesNotMatch(view.notices[0][0], /已完成刷新与回读/);
  assert.deepEqual(view.reloads, [['summary', '80'], ['list', '80']]);
  assert.equal(view.busy.value, false);
});

test('unrecognized refresh status never claims a complete readback', async () => {
  const view = harness();
  const pending = view.refresh();
  view.posts[0].resolve({ code: 200, data: { status: 'unexpected' } });
  await pending;
  assert.equal(view.notices.length, 1);
  assert.equal(view.notices[0][1], 'warning');
  assert.doesNotMatch(view.notices[0][0], /已完成刷新与回读/);
  assert.deepEqual(view.reloads, [['summary', '80'], ['list', '80']]);
});
