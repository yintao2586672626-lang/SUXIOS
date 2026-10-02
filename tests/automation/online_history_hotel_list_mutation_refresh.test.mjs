import assert from 'node:assert/strict';
import {test} from 'node:test';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync('public/app-main.js', 'utf8');
const loaderStart = source.indexOf('let onlineHistoryHotelListLoadingPromise = null;');
const loaderEnd = source.indexOf('const loadOnlineHistory = async', loaderStart);
const refreshStart = source.indexOf('const refreshOnlineHistory = async', loaderEnd);
const refreshEnd = source.indexOf('const resetOnlineHistoryFilter =', refreshStart);
assert.ok(loaderStart >= 0 && loaderEnd > loaderStart && refreshStart > loaderEnd && refreshEnd > refreshStart);

const script = `${source.slice(loaderStart, loaderEnd)}
${source.slice(refreshStart, refreshEnd)}
globalThis.invalidate = invalidateOnlineHistoryHotelList;
globalThis.load = loadOnlineHistoryHotelList;
globalThis.refresh = refreshOnlineHistory;
globalThis.loaded = onlineHistoryHotelListLoaded;`;

function createHarness(respond) {
  const ref = value => ({value});
  let historyRefreshes = 0;
  const requests = [];
  const env = {
    ref,
    onlineHistoryHotelList: ref([]),
    onlineHistoryHotelListError: ref(''),
    onlineHistoryFilter: ref({hotel_scope: 'all'}),
    onlineHistoryPage: ref(1),
    captureAuthSession: () => 1,
    isAuthSessionCurrent: session => session === 1,
    formatOnlineHistoryHotelOption: item => item,
    loadOnlineHistory: async () => { historyRefreshes += 1; return true; },
    request: async url => {
      requests.push(url);
      return respond(requests.length);
    },
    console: {error() {}},
  };
  const context = vm.createContext(env);
  vm.runInContext(script, context);
  return {
    env,
    context,
    requests,
    loaded: () => context.loaded.value,
    historyRefreshes: () => historyRefreshes,
    setScope: value => { env.onlineHistoryFilter.value.hotel_scope = value; },
  };
}

test('hotel save refreshes cached history options on the next history read', async () => {
  const h = createHarness(call => ({
    code: 200,
    data: call === 1
      ? [{value: '80', label: 'Original name'}]
      : [{value: '80', label: 'Renamed hotel'}, {value: '81', label: 'New hotel'}],
  }));
  await h.context.load();
  assert.equal(h.env.onlineHistoryHotelList.value[0]?.label, 'Original name');
  assert.equal(h.loaded(), true);

  h.context.invalidate();
  assert.equal(h.loaded(), false);
  await h.context.load();

  assert.equal(h.requests.length, 2);
  assert.deepEqual(h.env.onlineHistoryHotelList.value.map(item => item.label), ['Renamed hotel', 'New hotel']);
  assert.equal(h.env.onlineHistoryFilter.value.hotel_scope, 'all');
  assert.equal(h.historyRefreshes(), 0);
});

test('removed selected hotel falls back to all history after list readback', async () => {
  const h = createHarness(call => ({
    code: 200,
    data: call === 1
      ? [{value: '80', label: 'Removed hotel'}]
      : [{value: '81', label: 'Remaining hotel'}],
  }));
  await h.context.load();
  h.setScope('80');
  h.context.invalidate();
  await h.context.refresh();

  assert.equal(h.env.onlineHistoryFilter.value.hotel_scope, 'all');
  assert.deepEqual(h.env.onlineHistoryHotelList.value.map(item => item.value), ['81']);
  assert.equal(h.historyRefreshes(), 2);
});

test('late pre-mutation response cannot overwrite refreshed hotel options', async () => {
  let resolveFirst;
  const h = createHarness(call => call === 1
    ? new Promise(resolve => { resolveFirst = resolve; })
    : Promise.resolve({code: 200, data: [{value: '81', label: 'Current list'}]}));
  const oldRead = h.context.load();
  h.context.invalidate();
  await h.context.load();
  resolveFirst({code: 200, data: [{value: '80', label: 'Stale list'}]});
  await oldRead;

  assert.deepEqual(h.env.onlineHistoryHotelList.value.map(item => item.value), ['81']);
  assert.equal(h.loaded(), true);
});

test('failed post-mutation option read is visible and can recover', async () => {
  const h = createHarness(call => call === 1
    ? {code: 200, data: [{value: '80', label: 'Cached hotel'}]}
    : call === 2
      ? {code: 503, message: 'hotel list unavailable'}
      : {code: 200, data: [{value: '81', label: 'Recovered hotel'}]});
  await h.context.load();
  h.context.invalidate();
  await h.context.load();

  assert.equal(h.loaded(), false);
  assert.match(h.env.onlineHistoryHotelListError.value, /hotel list unavailable/);
  assert.deepEqual(h.env.onlineHistoryHotelList.value.map(item => item.value), ['80']);

  await h.context.load({force: true});
  assert.equal(h.env.onlineHistoryHotelListError.value, '');
  assert.equal(h.loaded(), true);
  assert.deepEqual(h.env.onlineHistoryHotelList.value.map(item => item.value), ['81']);
});

test('authoritative hotel mutation success paths invalidate the history selector cache', () => {
  const mutations = [
    ['const saveHotel = async () => {', 'const toggleHotelStatus = async (hotel) => {'],
    ['const toggleHotelStatus = async (hotel) => {', 'const userDeleteName = computed(() => {'],
    ['const confirmDeleteHotel = async () => {', 'const deactivateHotelDeleteTarget = async () => {'],
    ['const deactivateHotelDeleteTarget = async () => {', 'const resetHotelMergeState = () => {'],
    ['const executeHotelMerge = async () => {', 'const deleteHotel = openHotelDeleteModal;'],
  ];

  for (const [startMarker, endMarker] of mutations) {
    const start = source.indexOf(startMarker);
    const end = source.indexOf(endMarker, start);
    assert.ok(start >= 0 && end > start, `mutation block exists: ${startMarker}`);
    const block = source.slice(start, end);
    const success = block.indexOf('if (res.code === 200) {');
    const invalidation = block.indexOf('invalidateOnlineHistoryHotelList();', success);
    assert.ok(success >= 0 && invalidation > success, `successful mutation invalidates options: ${startMarker}`);
  }
});

test('ordinary OTA post-fetch refresh still avoids a hotel option reread', async () => {
  const h = createHarness(() => ({code: 200, data: [{value: '80', label: 'Current hotel'}]}));
  await h.context.load();
  const requestCount = h.requests.length;
  await h.context.refresh({refreshHotels: false});

  assert.equal(h.requests.length, requestCount);
  assert.equal(h.historyRefreshes(), 1);
  assert.equal(h.loaded(), true);
});
