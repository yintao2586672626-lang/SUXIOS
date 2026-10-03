import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';

const source = readFileSync('public/app-main.js', 'utf8');
const fragment = readFileSync('resources/frontend/templates/fragments/33-page-ai-governance.html', 'utf8');
function implementation(name) {
  const start = source.indexOf(`            const ${name} = `);
  assert.ok(start >= 0, `missing ${name}`);
  const end = source.indexOf('\n            const ', start + 1);
  return source.slice(start, end);
}
function logReply(list, { total = list.length, page = 1, page_size = 30 } = {}) {
  return { code: 200, data: { list, total, page, page_size } };
}
function harness(request = async () => logReply([])) {
  const context = vm.createContext({ URLSearchParams, request,
    user: { value: { id: 1, is_super_admin: true } },
    hotels: { value: [{ id: 17, name: '测试酒店甲' }, { id: 18, name: '测试酒店乙' }] },
    aiGovernanceFilter: { value: { hotel_id: '', module: '', scenario: '', status: '' } },
    aiGovernanceLogs: { value: [] }, aiGovernanceLogScope: { value: null },
    aiGovernanceSelectedLog: { value: null }, aiGovernanceError: { value: '' },
    aiGovernanceLogPage: { value: 1 }, aiGovernanceLogPagination: { value: null }, aiGovernanceLogLoading: { value: false },
    aiGovernanceLoading: { value: false }, authSessionEpoch: 0, hotelListLoadFailed: { value: false },
    loadHotels: async () => {}, loadAiGovernanceSummary: async () => {}, loadAiGovernancePromptVersions: async () => {},
    loadAiGovernanceEvaluationCases: async () => {}, loadAiGovernanceEvaluationRuns: async () => {},
  });
  vm.runInContext(`let aiGovernanceLogRequestSeq = 0; let aiGovernanceLogDetailSeq = 0;
    let aiGovernanceLogRequestQuery = ''; let aiGovernanceLogLoadedUser = null; let aiGovernanceLogLoadedEpoch = null; let aiGovernanceLoadRequestSeq = 0;
    ${['buildAiGovernanceLogParams', 'aiGovernanceHotelText', 'loadAiGovernanceLogs', 'queryAiGovernanceLogs',
      'changeAiGovernanceLogPage', 'closeAiGovernanceLogDetail', 'loadAiGovernance', 'openAiGovernanceLogDetail'].map(implementation).join('\n')}
    this.calls = { buildAiGovernanceLogParams, aiGovernanceHotelText, loadAiGovernanceLogs, queryAiGovernanceLogs,
      changeAiGovernanceLogPage, closeAiGovernanceLogDetail, loadAiGovernance, openAiGovernanceLogDetail };`, context);
  return context;
}
function element(testId, tag) {
  const marker = fragment.indexOf(`data-testid="${testId}"`);
  assert.ok(marker >= 0, `missing ${testId}`);
  const start = fragment.lastIndexOf(`<${tag}`, marker);
  return fragment.slice(start, fragment.indexOf(`</${tag}>`, marker) + tag.length + 3);
}
async function render(template, data) {
  return renderToString(createSSRApp({ template, data: () => data }));
}
function deferred() { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b; }); return { promise, resolve, reject }; }

test('hotel query is explicit, global query omits a blank hotel filter', async () => {
  let url;
  const h = harness(async (value) => { url = value; return logReply([{ id: 1, hotel_id: 17 }]); });
  assert.equal(h.calls.buildAiGovernanceLogParams().has('hotel_id'), false);
  h.aiGovernanceFilter.value.hotel_id = '17';
  await h.calls.loadAiGovernanceLogs();
  assert.equal(new URL(url, 'http://fixture.invalid').searchParams.get('hotel_id'), '17');
  assert.equal(h.aiGovernanceLogScope.value.hotel_id, '17');
  assert.equal(h.aiGovernanceLogs.value[0].hotel_id, 17);
});

test('list rejects a mismatched hotel and malformed success without manufacturing empty success', async () => {
  const h = harness(async () => logReply([{ id: 2, hotel_id: 18 }]));
  h.aiGovernanceFilter.value.hotel_id = '17';
  h.aiGovernanceLogs.value = [{ id: 9, hotel_id: 17 }];
  await assert.rejects(h.calls.loadAiGovernanceLogs(), /酒店范围不匹配/);
  assert.equal(h.aiGovernanceLogs.value[0].id, 9);
  h.request = async () => ({ code: 200, data: {} });
  await assert.rejects(h.calls.loadAiGovernanceLogs(), /响应不完整/);
  assert.equal(h.aiGovernanceLogScope.value, null);
});

test('older success and failure cannot replace a later hotel query or session', async () => {
  const first = deferred();
  const h = harness(async (url) => url.includes('hotel_id=17') ? first.promise : logReply([{ id: 2, hotel_id: 18 }]));
  h.aiGovernanceFilter.value.hotel_id = '17';
  const older = h.calls.loadAiGovernanceLogs();
  h.aiGovernanceFilter.value.hotel_id = '18';
  await h.calls.loadAiGovernanceLogs();
  first.resolve(logReply([{ id: 1, hotel_id: 17 }]));
  await older;
  assert.equal(h.aiGovernanceLogs.value[0].hotel_id, 18);
  assert.equal(h.aiGovernanceLogScope.value.hotel_id, '18');
  const stale = deferred(); h.request = () => stale.promise;
  const pending = h.calls.loadAiGovernanceLogs();
  h.user.value = { id: 2, is_super_admin: true };
  stale.reject(new Error('old session failure'));
  await pending;
  assert.equal(h.aiGovernanceLogs.value[0].hotel_id, 18);
});

test('pending old detail cannot reopen after another hotel list has loaded', async () => {
  const detail = deferred();
  const h = harness(async (url) => url.includes('/logs/1') ? detail.promise : logReply([{ id: 2, hotel_id: 18 }]));
  h.aiGovernanceLogScope.value = { hotel_id: '17' };
  const older = h.calls.openAiGovernanceLogDetail({ id: 1, hotel_id: 17 });
  h.aiGovernanceFilter.value.hotel_id = '18';
  await h.calls.loadAiGovernanceLogs();
  detail.resolve({ code: 200, data: { id: 1, hotel_id: 17 } });
  await older;
  assert.equal(h.aiGovernanceSelectedLog.value, null);
  h.request = async () => ({ code: 200, data: { id: 2, hotel_id: 17 } });
  await h.calls.openAiGovernanceLogDetail({ id: 2, hotel_id: 18 });
  assert.match(h.aiGovernanceError.value, /酒店范围不匹配/);
});

test('actual hotel selector and loaded-scope caption distinguish draft filters and global overview', async () => {
  const h = harness();
  const filter = await render(element('ai-governance-hotel-filter', 'select'), {
    aiGovernanceFilter: { hotel_id: '17' }, hotels: h.hotels.value,
    hotelSelectOptionText: (hotel) => hotel.name,
  });
  assert.match(filter, /调用日志酒店范围/);
  assert.match(filter, /value="17" selected/);
  assert.match(filter, /测试酒店甲/);
  const caption = await render(element('ai-governance-log-scope', 'p'), {
    aiGovernanceLogScope: { hotel_id: '17' }, aiGovernanceHotelText: h.calls.aiGovernanceHotelText,
  });
  assert.match(caption, /已加载列表范围：测试酒店甲/);
  assert.match(caption, /上方概览为全部酒店/);
});

test('list and detail render the same known and genuinely unknown hotel identity', async () => {
  const h = harness();
  for (const hotelId of [17, 18, null, 0, undefined]) {
    const expected = hotelId === 17 ? '测试酒店甲' : hotelId === 18 ? '测试酒店乙' : '酒店未记录';
    const data = { log: { hotel_id: hotelId }, aiGovernanceSelectedLog: { hotel_id: hotelId }, aiGovernanceHotelText: h.calls.aiGovernanceHotelText };
    const list = await render(`<table><tbody><tr>${element('ai-governance-log-hotel', 'td')}</tr></tbody></table>`, data);
    const detail = await render(element('ai-governance-detail-hotel', 'p'), data);
    assert.ok(list.includes(expected)); assert.ok(detail.includes(expected));
    assert.doesNotMatch(list + detail, /undefined|NaN|酒店 ID 0/);
  }
});

test('page two reaches the older log while preserving loaded hotel and other filters; querying resets page one', async () => {
  const urls = [];
  const h = harness(async (url) => {
    urls.push(url);
    const params = new URL(url, 'http://fixture.invalid').searchParams;
    const page = Number(params.get('page'));
    const hotel = Number(params.get('hotel_id'));
    const list = page === 1 ? Array.from({ length: 30 }, (_, index) => ({ id: 31 - index, hotel_id: hotel })) : [{ id: 1, hotel_id: hotel }];
    return logReply(list, { total: 31, page });
  });
  h.aiGovernanceFilter.value = { hotel_id: '17', module: 'revenue', scenario: 'daily', status: 'success' };
  await h.calls.queryAiGovernanceLogs();
  assert.equal(h.aiGovernanceLogPagination.value.total, 31);
  assert.equal(h.aiGovernanceLogPagination.value.page, 1);
  h.aiGovernanceFilter.value.hotel_id = '18';
  await h.calls.changeAiGovernanceLogPage(2);
  const second = new URL(urls[1], 'http://fixture.invalid').searchParams;
  assert.equal(second.get('hotel_id'), '17');
  assert.equal(second.get('module'), 'revenue');
  assert.equal(second.get('scenario'), 'daily');
  assert.equal(second.get('status'), 'success');
  assert.equal(second.get('page'), '2');
  assert.equal(h.aiGovernanceLogs.value[0].id, 1);
  assert.equal(h.aiGovernanceLogPagination.value.page, 2);
  assert.equal(h.aiGovernanceLogScope.value.hotel_id, '17');
  await h.calls.queryAiGovernanceLogs();
  assert.equal(new URL(urls[2], 'http://fixture.invalid').searchParams.get('page'), '1');
  assert.equal(h.aiGovernanceLogPagination.value.page, 1);
  assert.equal(h.aiGovernanceLogScope.value.hotel_id, '18');
});

test('mismatched pagination and unknown totals fail visibly while keeping the last successful page and scope', async () => {
  const h = harness(async () => logReply([{ id: 31, hotel_id: 17 }], { total: 31 }));
  h.aiGovernanceFilter.value.hotel_id = '17';
  await h.calls.loadAiGovernanceLogs();
  const scope = h.aiGovernanceLogScope.value;
  const pagination = h.aiGovernanceLogPagination.value;
  for (const data of [
    { list: [{ id: 1, hotel_id: 17 }], total: 31, page: 1, page_size: 30 },
    { list: [{ id: 1, hotel_id: 17 }], total: 31, page: 2, page_size: 10 },
    { list: [{ id: 1, hotel_id: 17 }], total: null, page: 2, page_size: 30 },
    { list: [{ id: 1, hotel_id: 17 }], total: -1, page: 2, page_size: 30 },
    { list: [{ id: 1, hotel_id: 17 }], total: 0, page: 2, page_size: 30 },
  ]) {
    h.request = async () => ({ code: 200, data });
    await assert.rejects(h.calls.loadAiGovernanceLogs({ page: 2 }), /分页|总量|响应不完整/);
    assert.equal(h.aiGovernanceLogs.value[0].id, 31);
    assert.equal(h.aiGovernanceLogScope.value, scope);
    assert.equal(h.aiGovernanceLogPagination.value, pagination);
    assert.match(h.aiGovernanceError.value, /分页|总量|响应不完整/);
    assert.equal(h.aiGovernanceLogLoading.value, false);
  }
});

test('old page success, old page failure and old session completion cannot replace a newer page', async () => {
  const h = harness(async () => logReply([{ id: 31, hotel_id: 17 }], { total: 31 }));
  h.aiGovernanceFilter.value.hotel_id = '17';
  await h.calls.loadAiGovernanceLogs();
  for (const rejectOld of [false, true]) {
    const old = deferred();
    h.request = (url) => new URL(url, 'http://fixture.invalid').searchParams.get('page') === '2'
      ? old.promise : logReply([{ id: 31, hotel_id: 17 }], { total: 31 });
    const pending = h.calls.loadAiGovernanceLogs({ page: 2 });
    await h.calls.loadAiGovernanceLogs({ page: 1 });
    if (rejectOld) old.reject(new Error('stale page error'));
    else old.resolve(logReply([{ id: 1, hotel_id: 17 }], { total: 31, page: 2 }));
    await pending;
    assert.equal(h.aiGovernanceLogs.value[0].id, 31);
    assert.equal(h.aiGovernanceLogPagination.value.page, 1);
    assert.equal(h.aiGovernanceError.value, '');
  }
  const oldSession = deferred();
  h.request = () => oldSession.promise;
  const pending = h.calls.loadAiGovernanceLogs({ page: 2 });
  h.authSessionEpoch += 1;
  oldSession.resolve(logReply([{ id: 1, hotel_id: 17 }], { total: 31, page: 2 }));
  await pending;
  assert.equal(h.aiGovernanceLogPagination.value.page, 1);
});

test('same-page refresh keeps the shown detail but invalidates pending details; page changes and close cancel old details', async () => {
  const h = harness(async () => logReply([{ id: 31, hotel_id: 17 }], { total: 31 }));
  h.aiGovernanceFilter.value.hotel_id = '17';
  await h.calls.loadAiGovernanceLogs();
  h.aiGovernanceSelectedLog.value = { id: 31, hotel_id: 17, human_confirmation_status: 'pending' };
  const stale = deferred();
  h.request = (url) => url.includes('/logs/30') ? stale.promise : logReply([{ id: 31, hotel_id: 17 }], { total: 31 });
  const pending = h.calls.openAiGovernanceLogDetail({ id: 30, hotel_id: 17 });
  await h.calls.loadAiGovernanceLogs();
  assert.equal(h.aiGovernanceSelectedLog.value.id, 31, 'confirmation readback relies on preserving the selected id');
  stale.resolve({ code: 200, data: { id: 30, hotel_id: 17 } });
  await pending;
  assert.equal(h.aiGovernanceSelectedLog.value.id, 31);
  h.request = async () => logReply([{ id: 1, hotel_id: 17 }], { total: 31, page: 2 });
  await h.calls.changeAiGovernanceLogPage(2);
  assert.equal(h.aiGovernanceSelectedLog.value, null);
  h.aiGovernanceSelectedLog.value = { id: 1, hotel_id: 17 };
  const closing = deferred(); h.request = () => closing.promise;
  const open = h.calls.openAiGovernanceLogDetail({ id: 2, hotel_id: 17 });
  h.calls.closeAiGovernanceLogDetail();
  closing.resolve({ code: 200, data: { id: 2, hotel_id: 17 } });
  await open;
  assert.equal(h.aiGovernanceSelectedLog.value, null);
  assert.match(fragment, /@click="closeAiGovernanceLogDetail"/);
});

test('pagination renders unknown, first, last and genuinely empty result states with safe controls', async () => {
  const template = element('ai-governance-log-pagination', 'nav');
  const renderPage = (pagination, loading = false) => render(template, {
    aiGovernanceLogPagination: pagination, aiGovernanceLogLoading: loading, aiGovernanceLoading: false,
    changeAiGovernanceLogPage: () => {},
  });
  const unknown = await renderPage(null);
  assert.match(unknown, /总量和页码尚未核实/);
  assert.doesNotMatch(unknown, /共 0 条|第 0 页/);
  const first = await renderPage({ total: 31, page: 1, page_size: 30 });
  assert.match(first, /共 31 条/); assert.match(first, /第 1 页/);
  assert.match(first, /data-testid="ai-governance-log-prev"[^>]*\sdisabled(?:\s|>)/);
  assert.doesNotMatch(first, /data-testid="ai-governance-log-next"[^>]*\sdisabled(?:\s|>)/);
  const last = await renderPage({ total: 31, page: 2, page_size: 30 });
  assert.match(last, /第 2 页/);
  assert.doesNotMatch(last, /data-testid="ai-governance-log-prev"[^>]*\sdisabled(?:\s|>)/);
  assert.match(last, /data-testid="ai-governance-log-next"[^>]*\sdisabled(?:\s|>)/);
  const empty = await renderPage({ total: 0, page: 1, page_size: 30 });
  assert.match(empty, /共 0 条/);
  assert.match(empty, /data-testid="ai-governance-log-next"[^>]*\sdisabled(?:\s|>)/);
  const busy = await renderPage({ total: 31, page: 1, page_size: 30 }, true);
  assert.match(busy, /data-testid="ai-governance-log-next"[^>]*\sdisabled(?:\s|>)/);
});

test('direct governance entry loads uncached hotel options once and exposes hotel loading failures', async () => {
  const h = harness();
  h.hotels.value = [];
  let count = 0;
  h.loadHotels = async () => { count += 1; h.hotels.value = [{ id: 17, name: '测试酒店甲' }]; };
  await h.calls.loadAiGovernance();
  assert.equal(count, 1);
  assert.equal(h.hotels.value[0].id, 17);
  await h.calls.loadAiGovernance();
  assert.equal(count, 1, 'existing hotel options must be reused');
  h.hotels.value = [];
  h.loadHotels = async () => { h.hotelListLoadFailed.value = true; };
  await h.calls.loadAiGovernance();
  assert.match(h.aiGovernanceError.value, /酒店.*失败/);
  assert.equal(h.hotels.value.length, 0);
});
