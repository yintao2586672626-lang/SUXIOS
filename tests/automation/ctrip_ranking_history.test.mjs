import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync('public/app-main.js', 'utf8');
const template = readFileSync('resources/frontend/templates/fragments/24-page-ctrip-ebooking.html', 'utf8');
const slice = (start, end) => {
  const at = source.indexOf(start);
  assert.ok(at >= 0, `missing ${start}`);
  const until = source.indexOf(end, at + start.length);
  assert.ok(until > at, `missing ${end}`);
  return source.slice(at, until);
};
const stateSource = () => slice("const ctripRankingStoredDate = ref('');", '\n            const ctripHeaderRecordCount');
const handlerSource = () => slice('const loadCtripRankingStoredData = async', '\n            const loadSelectedCtripStoredBusinessDate');
const payload = (date = '2026-09-02', hotelId = '229') => ({
  metadata: { hotel_id: hotelId, status: 'source_unverified', data_date: date },
  rank: { data_date: date, status: 'success', display_hotels: [{ hotelName: 'test-only historical peer' }] },
});

const harness = (overrides = {}) => {
  const calls = [];
  const applied = [];
  const env = {
    URLSearchParams, Date,
    ref: value => ({ value }),
    selectedCtripHotelId: { value: '229' },
    fetchingData: { value: false },
    currentPage: { value: 'ctrip-ebooking' },
    onlineDataTab: { value: 'ctrip-ranking' },
    ctripLatestMeta: { value: { data_date: 'old' } },
    captureAuthSession: () => 1,
    isAuthSessionCurrent: () => true,
    clearCtripRankingDisplayState: () => calls.push(['clear']),
    request: async (url, options) => {
      calls.push(['history', url, options]);
      return { code: 200, data: { list: [{ system_hotel_id: 229, data_date: '2026-09-02' }] } };
    },
    loadLatestCtripData: async options => {
      calls.push(['snapshot', options]);
      return { payload: payload(options.range) };
    },
    applyLatestCtripSnapshot: (value, options) => applied.push({ value, options }),
    ...overrides,
  };
  const api = vm.runInNewContext(`(() => {
    ${stateSource()}
    ${handlerSource()}
    return { loadCtripRankingStoredData, ctripRankingStoredDate, ctripRankingHistoryRange,
      ctripRankingHistoryLoading, ctripRankingHistoryMessage,
      invalidate: () => { ctripRankingHistoryRequestSeq += 1; ctripRankingHistoryLoading.value = false; } };
  })()`, env);
  api.ctripRankingStoredDate.value = '2026-09-02';
  return { api, env, calls, applied };
};

test('ranking page provides a date picker and storage-only actions without a credential gate', () => {
  const controls = template.slice(template.indexOf('<div data-testid="ctrip-ranking-history-controls"'), template.indexOf('<input type="hidden" v-model="ctripForm.nodeId"'));
  assert.match(controls, /type="date"/);
  assert.match(controls, /data-testid="ctrip-ranking-history-read"/);
  assert.match(controls, /data-testid="ctrip-ranking-history-latest"/);
  assert.doesNotMatch(controls, /canFetchCtripManualData|selectedCtripManualCredentialState|has_cookies/);
  assert.match(template, /ctripFetchSuccess &amp;&amp; !ctripRankingHistoryRange/);
  assert.doesNotMatch(handlerSource(), /fetchCtripData\(|fetch-ctrip|cookies|config_id|loadCtripConfigList|method: 'POST'/);
});

test('an exact historical date is displayed without promoting unverified saved evidence', async () => {
  const { api, calls, applied } = harness();
  assert.equal(await api.loadCtripRankingStoredData(), true);
  assert.deepEqual(calls.map(call => call[0]), ['clear', 'snapshot']);
  assert.equal(calls[1][1].hotelId, '229');
  assert.equal(calls[1][1].range, '2026-09-02');
  assert.equal(calls[1][1].hydrateDisplay, false);
  assert.equal(applied[0].options.hydrateDisplay, true);
  assert.equal(applied[0].value.metadata.status, 'source_unverified');
  assert.equal(api.ctripRankingHistoryRange.value, '2026-09-02');
  assert.match(api.ctripRankingHistoryMessage.value, /2026-09-02.*1 条.*未重新采集/);
  assert.equal(api.ctripRankingHistoryLoading.value, false);
});

test('latest saved action resolves the stored business date before exact readback', async () => {
  const { api, calls, applied } = harness();
  api.ctripRankingStoredDate.value = '';
  assert.equal(await api.loadCtripRankingStoredData({ latest: true }), true);
  const url = new URL(calls[1][1], 'http://test-only');
  assert.equal(url.pathname, '/online-data/ctrip/history');
  assert.equal(url.searchParams.get('hotel_id'), '229');
  assert.equal(url.searchParams.get('data_type'), 'rank');
  assert.equal(calls[1][2].businessContext.platform, 'ctrip');
  assert.equal(calls[2][1].range, '2026-09-02');
  assert.equal(applied.length, 1);
});

test('missing records do not retain the previous table or become zero rows', async () => {
  const { api, calls, applied } = harness({ loadLatestCtripData: async () => ({ payload: {
    metadata: { hotel_id: '229', status: 'empty' }, rank: { data_date: '', display_hotels: [] },
  } }) });
  assert.equal(await api.loadCtripRankingStoredData(), false);
  assert.equal(calls[0][0], 'clear');
  assert.equal(applied.length, 0);
  assert.match(api.ctripRankingHistoryMessage.value, /2026-09-02 没有可展示/);
});

for (const [label, response, message] of [
  ['wrong hotel', payload('2026-09-02', '80'), /酒店不匹配/],
  ['wrong date', payload('2026-09-01'), /日期.*不一致/],
  ['blocked identity', { ...payload(), rank: { ...payload().rank, status: 'identity_mismatch' } }, /身份不匹配/],
]) {
  test(`${label} is never rendered`, async () => {
    const { api, env, applied } = harness({ loadLatestCtripData: async () => ({ payload: response }) });
    assert.equal(await api.loadCtripRankingStoredData(), false);
    assert.equal(applied.length, 0);
    assert.equal(env.ctripLatestMeta.value, null);
    assert.match(api.ctripRankingHistoryMessage.value, message);
  });
}

test('history errors and malformed responses remain errors, not empty success', async () => {
  for (const response of [{ code: 403, message: '无权查看' }, { code: 200, data: {} }]) {
    const { api, applied } = harness({ request: async () => response });
    assert.equal(await api.loadCtripRankingStoredData({ latest: true }), false);
    assert.equal(applied.length, 0);
    assert.doesNotMatch(api.ctripRankingHistoryMessage.value, /没有已保存/);
    assert.equal(api.ctripRankingHistoryLoading.value, false);
  }
});

test('late reads after switching hotel or account do not overwrite the new context', async () => {
  let resolve;
  const { api, env, applied } = harness({ loadLatestCtripData: () => new Promise(done => { resolve = done; }) });
  const pending = api.loadCtripRankingStoredData();
  env.selectedCtripHotelId.value = '80';
  api.invalidate();
  api.ctripRankingHistoryMessage.value = 'new context';
  resolve({ payload: payload() });
  assert.equal(await pending, false);
  assert.equal(applied.length, 0);
  assert.equal(api.ctripRankingHistoryMessage.value, 'new context');
});

test('invalid dates and active live collection do not dispatch reads', async () => {
  const { api, env, calls } = harness();
  api.ctripRankingStoredDate.value = '2026-02-30';
  assert.equal(await api.loadCtripRankingStoredData(), false);
  assert.equal(calls.length, 0);
  env.fetchingData.value = true;
  assert.equal(await api.loadCtripRankingStoredData({ latest: true }), false);
  assert.equal(calls.length, 0);
});

test('history range is isolated to the competition tab and reset on new collection or hotel change', () => {
  assert.match(slice('const resolveCtripLatestRequestRange =', '\n            const shouldHydrateLatestCtripDisplay'), /onlineDataTab.value === 'ctrip-ranking'.*ctripRankingHistoryRange/);
  assert.match(slice('const fetchCtripData =', '\n            const loadLatestCtripData'), /ctripRankingHistoryRange.value = ''/);
  assert.match(slice('const clearCtripOverviewDisplayState =', '\n            const getCtripOverviewTargetHotelId'), /ctripRankingHistoryRequestSeq \+= 1/);
});
