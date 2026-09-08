import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const main = readFileSync('public/app-main.js', 'utf8');
const operationStatic = readFileSync('public/operation-static.js', 'utf8');
function between(source, start, end) {
  const first = source.indexOf(start);
  const last = source.indexOf(end, first + start.length);
  assert.ok(first >= 0 && last > first, `missing source boundary: ${start}`);
  return source.slice(first, last);
}
const evidenceSource = between(main, 'let operatingQuestionEvidenceRequestSeq = 0;', 'const openOperatingQuestionHistory =');
const dailyNavigationSource = between(main, 'const aiDailyReportTaskReturn = ref(null);', 'const aiDailyReportReadinessClass =');
const dailyReadSource = between(main, 'let aiDailyReportRequestSeq = 0;', 'const generateAiDailyReport =');
const intentReadSource = between(main, 'const readOperationExecutionIntent = async', 'const readOperationExecutionTask =');
const ref = (value) => ({ value });
const plain = (value) => JSON.parse(JSON.stringify(value));
const deferred = () => {
  let resolve;
  let reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
};
const question = (id, overrides = {}) => ({
  id, hotel_id: 80, platform: 'ctrip', date_start: '2026-08-23', date_end: '2026-08-23',
  content_digest: String(id % 10).repeat(64), question_text: `保存问题 #${id}`,
  answer: { decision_frame: { requested_object: 'demand' } }, ...overrides,
});
const report = (overrides = {}) => ({ id: 510, hotel_id: 80, report_date: '2026-08-23', ...overrides });

function evidenceHarness() {
  const calls = [];
  const applied = [];
  const s = {
    operatingQuestionForm: ref({ hotel_id: '80', platform: 'ctrip', date_start: '2026-08-25', date_end: '2026-08-25' }),
    operatingQuestionState: ref({ loading: false, history_opening_id: 0, council_generation: 0, result: null }),
    filterReportHotel: ref('80'), currentPage: ref('compass'), agentTab: ref('revenue'),
    pageRequestGeneration: 1, authEpoch: 1, accessibleHotels: new Set(['80', '81']),
    nextTick: async () => {},
    request: async (url, options) => { calls.push({ url, options }); return s.transport(url, options); },
    operatingQuestionPlatformText: (platform) => platform,
    applyOperatingQuestionIntentReadback: (exact) => applied.push(exact.id),
    loadLatestOperatingQuestionCouncil: async () => {},
    transport: async () => { throw new Error('Unexpected request'); },
  };
  s.reportHotelOptionExists = (id) => s.accessibleHotels.has(id);
  s.captureAuthSession = () => s.authEpoch;
  s.isAuthSessionCurrent = (epoch) => epoch === s.authEpoch;
  vm.createContext(s);
  vm.runInContext(`${evidenceSource}\nthis.openEvidence = openOperatingQuestionEvidence;`, s);
  return { s, calls, applied };
}

function dailyHarness() {
  const calls = [];
  const toasts = [];
  const loadedTasks = [];
  const createdTasks = [];
  const watchers = [];
  const s = {
    ref, watch: (getters, callback) => watchers.push(callback), URLSearchParams, window: {},
    aiDailyReport: ref(report()), aiDailyReportForm: ref({ hotel_id: '80', report_date: '2026-08-23' }),
    operationFilters: ref({ hotel_id: '80' }), operationExecutionStageFilter: ref('waiting'),
    revenueAiExecutionFocus: ref(null), currentPage: ref('ai-daily-report'), isLoggedIn: ref(true),
    aiDailyReportGenerationTaskPolling: ref(false), operationLoading: ref({ aiDailyReport: false }),
    operationError: ref({ aiDailyReport: '' }), operationYesterday: '2026-08-23',
    authEpoch: 1, pageRequestGeneration: 1, accessibleHotels: new Set(['80']),
    nextTick: async () => {},
    apiRequest: async (url, options) => { calls.push({ url, options }); return s.transport(url, options); },
    transport: async () => { throw new Error('Unexpected request'); },
    showToast: (message, level) => toasts.push({ message, level }),
    operationErrorMessage: (error, fallback) => error?.message || fallback,
    loadOperationActions: async (options) => loadedTasks.push(options),
    createAiDailyExecutionIntent: async (...args) => createdTasks.push(args),
    revenueAiDailyReportActionExecutionReady: () => true, aiDailyReportActionBlockedText: () => '',
    openAiDailyReportEvidenceTarget: async () => { throw new Error('Existing tasks must not take the evidence path'); },
    ensureOperationStaticReady: async () => {}, ensureRevenueAiStaticReady: async () => {},
    loadAiDailyFactGate: async () => {},
    aiDailyReportTaskPositiveInteger: (value) => Number.isSafeInteger(Number(value)) && Number(value) > 0 ? Number(value) : 0,
  };
  s.reportHotelOptionExists = (id) => s.accessibleHotels.has(id);
  s.normalizeOperationHotelSelection = (form) => s.reportHotelOptionExists(String(form.value.hotel_id)) ? String(form.value.hotel_id) : null;
  s.captureAuthSession = () => s.authEpoch;
  s.isAuthSessionCurrent = (epoch) => epoch === s.authEpoch;
  s.loadOperationStatic = async () => s.window.SUXI_OPERATION_STATIC;
  s.requireOperationStatic = (loaded, name) => loaded[name];
  vm.createContext(s);
  vm.runInContext(operationStatic, s);
  vm.runInContext(`${intentReadSource}\n${dailyNavigationSource}\n${dailyReadSource}\nthis.api = {
    openTask: openAiDailyReportExecutionIntent, primary: handleAiDailyReportActionPrimary,
    returnToReport: returnToAiDailyReport, loadReport: loadAiDailyReport,
    origin: aiDailyReportTaskReturn, opening: aiDailyReportTaskOpeningId,
  };`, s);
  return { s, calls, toasts, loadedTasks, createdTasks, flushWatchers: () => watchers.forEach((callback) => callback()) };
}

test('the bridge opens two saved answers by their own ID with exact hotel, platform, dates and digest', async () => {
  const { s, calls, applied } = evidenceHarness();
  const first = question(41);
  const second = question(42, { hotel_id: 81, platform: 'meituan', date_start: '2026-08-24', date_end: '2026-08-26' });
  for (const exact of [first, second]) {
    s.transport = async () => ({ code: 200, data: exact });
    assert.equal(await s.openEvidence(exact), exact);
    assert.equal(s.operatingQuestionState.value.result, exact);
    assert.equal(s.operatingQuestionForm.value.hotel_id, String(exact.hotel_id));
    assert.equal(s.operatingQuestionForm.value.platform, exact.platform);
    assert.equal(s.operatingQuestionForm.value.date_start, exact.date_start);
    assert.equal(s.operatingQuestionForm.value.date_end, exact.date_end);
    assert.equal(s.currentPage.value, 'agent-center');
  }
  assert.deepEqual(calls.map((call) => call.url), ['/agent/operating-questions/41', '/agent/operating-questions/42']);
  assert.deepEqual(calls.map((call) => plain(call.options.businessContext)), [
    { hotelId: '80', platform: 'ctrip' }, { hotelId: '81', platform: 'meituan' },
  ]);
  assert.deepEqual(applied, [41, 42]);
});

test('an old answer read cannot commit after scope, session, page generation or a competing professional request changes', async () => {
  const changes = [
    (s) => { s.operatingQuestionForm.value.hotel_id = '81'; },
    (s) => { s.operatingQuestionForm.value.date_start = '2026-08-26'; },
    (s) => { s.filterReportHotel.value = '81'; },
    (s) => { s.authEpoch += 1; },
    (s) => { s.currentPage.value = 'online-data'; s.pageRequestGeneration += 1; },
    (s) => { s.pageRequestGeneration += 2; },
    (s) => { s.operatingQuestionState.value.loading = true; },
    (s) => { s.operatingQuestionState.value.history_opening_id = 99; },
    (s) => { s.accessibleHotels.delete('80'); },
  ];
  for (const change of changes) {
    const { s, applied } = evidenceHarness();
    const pending = deferred();
    s.transport = () => pending.promise;
    const result = s.openEvidence(question(41));
    change(s);
    const pageAfterChange = s.currentPage.value;
    pending.resolve({ code: 200, data: question(41) });
    await assert.rejects(result);
    assert.equal(s.operatingQuestionState.value.result, null);
    assert.equal(s.currentPage.value, pageAfterChange);
    assert.deepEqual(applied, []);
  }
});

test('mismatched answer identities and unauthorized hotel reads are rejected before any result is committed', async () => {
  for (const change of [
    { id: 42 }, { hotel_id: 81 }, { platform: 'meituan' }, { date_start: '2026-08-24' },
    { date_end: '2026-08-24' }, { content_digest: 'a'.repeat(64) }, { answer: null },
  ]) {
    const { s } = evidenceHarness();
    s.transport = async () => ({ code: 200, data: question(41, change) });
    await assert.rejects(s.openEvidence(question(41)), /保存身份不一致/);
    assert.equal(s.operatingQuestionState.value.result, null);
    assert.equal(s.currentPage.value, 'compass');
  }
  const { s, calls } = evidenceHarness();
  await assert.rejects(s.openEvidence(question(41, { hotel_id: 99 })), /酒店范围不可访问/);
  assert.equal(calls.length, 0);
});

test('a failed answer read can be retried and a newer exact read supersedes an older pending one', async () => {
  const { s } = evidenceHarness();
  s.transport = async () => { throw new Error('temporary read error'); };
  await assert.rejects(s.openEvidence(question(41)), /temporary read error/);
  const old = deferred();
  s.transport = (url) => url.endsWith('/41') ? old.promise : Promise.resolve({ code: 200, data: question(42) });
  const first = s.openEvidence(question(41));
  await s.openEvidence(question(42));
  old.resolve({ code: 200, data: question(41) });
  await assert.rejects(first);
  assert.equal(s.operatingQuestionState.value.result.id, 42);
});

test('an older evidence read cannot overwrite a newer professional answer that already finished in the same scope', async () => {
  const { s, applied } = evidenceHarness();
  s.currentPage.value = 'agent-center';
  const pending = deferred();
  s.transport = () => pending.promise;
  const oldRead = s.openEvidence(question(41));
  const newerAnswer = question(42);
  s.operatingQuestionState.value.council_generation += 1;
  s.operatingQuestionState.value.loading = false;
  s.operatingQuestionState.value.result = newerAnswer;
  s.operatingQuestionState.value.question = newerAnswer.question_text;
  pending.resolve({ code: 200, data: question(41) });
  await assert.rejects(oldRead);
  assert.equal(s.operatingQuestionState.value.result, newerAnswer);
  assert.equal(s.operatingQuestionState.value.question, newerAnswer.question_text);
  assert.deepEqual(applied, []);
  s.transport = async () => ({ code: 200, data: question(41) });
  await s.openEvidence(question(41));
  assert.equal(s.operatingQuestionState.value.result.id, 41, 'a fresh click can intentionally reopen the earlier answer');
});

test('an already transferred report action reads the existing task with GET and never creates another intent', async () => {
  const { s, calls, loadedTasks, createdTasks } = dailyHarness();
  s.transport = async () => ({ code: 200, data: { id: 701, hotel_id: 80 } });
  await s.api.primary({ execution_intent_id: 701 }, 0);
  assert.equal(calls.length, 1);
  const requested = new URL(calls[0].url, 'http://fixture.test');
  assert.equal(requested.pathname, '/operation/execution-intents/701');
  assert.equal(requested.searchParams.get('hotel_id'), '80');
  assert.equal((calls[0].options?.method || 'GET').toUpperCase(), 'GET');
  assert.deepEqual(createdTasks, []);
  assert.deepEqual(plain(loadedTasks), [{ focusIntentId: 701 }]);
  assert.equal(s.currentPage.value, 'ops-track');
  assert.deepEqual(plain(s.api.origin.value), { reportId: 510, hotelId: 80, reportDate: '2026-08-23', intentId: 701 });
});

test('a task from another hotel or a mismatched task ID cannot be opened; failures remain retryable', async () => {
  for (const wrong of [{ id: 701, hotel_id: 81 }, { id: 702, hotel_id: 80 }]) {
    const { s, toasts, loadedTasks } = dailyHarness();
    s.transport = async () => ({ code: 200, data: wrong });
    await s.api.openTask({ execution_intent_id: 701 });
    assert.equal(s.currentPage.value, 'ai-daily-report');
    assert.equal(s.api.origin.value, null);
    assert.equal(s.api.opening.value, 0);
    assert.equal(toasts.at(-1).level, 'error');
    assert.deepEqual(loadedTasks, []);
    s.transport = async () => ({ code: 200, data: { id: 701, hotel_id: 80 } });
    await s.api.openTask({ execution_intent_id: 701 });
    assert.equal(s.currentPage.value, 'ops-track');
  }
});

test('a stale task read cannot navigate after hotel, date, session or page changes, including leaving and returning', async () => {
  for (const change of [
    (s) => { s.aiDailyReportForm.value.hotel_id = '81'; },
    (s) => { s.aiDailyReportForm.value.report_date = '2026-08-24'; },
    (s) => { s.authEpoch += 1; },
    (s) => { s.currentPage.value = 'compass'; s.pageRequestGeneration += 1; },
    (s) => { s.pageRequestGeneration += 2; },
  ]) {
    const { s, loadedTasks } = dailyHarness();
    const response = deferred();
    const started = deferred();
    s.transport = () => { started.resolve(); return response.promise; };
    const pending = s.api.openTask({ execution_intent_id: 701 });
    await started.promise;
    change(s);
    const pageAfterChange = s.currentPage.value;
    response.resolve({ code: 200, data: { id: 701, hotel_id: 80 } });
    await pending;
    assert.equal(s.currentPage.value, pageAfterChange);
    assert.equal(s.api.origin.value, null);
    assert.deepEqual(loadedTasks, []);
    assert.equal(s.api.opening.value, 0);
  }
});

test('returning from a task reads the original report ID rather than latest and validates its business date', async () => {
  const { s, calls } = dailyHarness();
  s.currentPage.value = 'ops-track';
  s.api.origin.value = { reportId: 510, hotelId: 80, reportDate: '2026-08-23', intentId: 701 };
  s.transport = async () => ({ code: 200, data: report() });
  await s.api.returnToReport();
  assert.equal(s.currentPage.value, 'ai-daily-report');
  assert.deepEqual(calls.map((item) => item.url), ['/ai-daily-reports/510']);
  assert.equal(s.aiDailyReport.value.id, 510);
  assert.equal(s.aiDailyReportForm.value.report_date, '2026-08-23');
  s.transport = async () => ({ code: 200, data: report({ report_date: '2026-08-24' }) });
  await s.api.loadReport();
  assert.equal(s.aiDailyReport.value, null);
  assert.match(s.operationError.value.aiDailyReport, /原日报业务日期不一致/);
  s.transport = async () => ({ code: 200, data: report() });
  await s.api.loadReport();
  assert.equal(s.aiDailyReport.value.id, 510);
  assert.equal(s.operationError.value.aiDailyReport, '');
  assert.ok(calls.every((item) => item.url === '/ai-daily-reports/510'));
});

test('wrong report ID or hotel is rejected on return and cannot fall back to latest', async () => {
  for (const wrong of [{ id: 511 }, { hotel_id: 81 }]) {
    const { s, calls } = dailyHarness();
    s.api.origin.value = { reportId: 510, hotelId: 80, reportDate: '2026-08-23', intentId: 701 };
    s.transport = async () => ({ code: 200, data: report(wrong) });
    await s.api.loadReport();
    assert.equal(s.aiDailyReport.value, null);
    assert.match(s.operationError.value.aiDailyReport, /不一致/);
    assert.deepEqual(calls.map((item) => item.url), ['/ai-daily-reports/510']);
  }
});

test('an old report read cannot commit after hotel, date, session or page changes, including a page round trip', async () => {
  for (const change of [
    (s) => { s.aiDailyReportForm.value.hotel_id = '81'; },
    (s) => { s.aiDailyReportForm.value.report_date = '2026-08-24'; },
    (s) => { s.authEpoch += 1; },
    (s) => { s.currentPage.value = 'compass'; s.pageRequestGeneration += 1; },
    (s) => { s.pageRequestGeneration += 2; },
  ]) {
    const { s } = dailyHarness();
    s.api.origin.value = { reportId: 510, hotelId: 80, reportDate: '2026-08-23', intentId: 701 };
    const response = deferred();
    const started = deferred();
    s.transport = () => { started.resolve(); return response.promise; };
    const pending = s.api.loadReport();
    await started.promise;
    change(s);
    const pageAfterChange = s.currentPage.value;
    response.resolve({ code: 200, data: report() });
    await pending;
    assert.equal(s.aiDailyReport.value, null);
    assert.equal(s.currentPage.value, pageAfterChange);
  }
});

test('report return context is discarded when leaving the task/report flow, changing hotel or signing out', () => {
  for (const change of [
    (s) => { s.currentPage.value = 'compass'; },
    (s) => { s.operationFilters.value.hotel_id = '81'; },
    (s) => { s.aiDailyReportForm.value.report_date = '2026-08-24'; },
    (s) => { s.isLoggedIn.value = false; },
  ]) {
    const { s, flushWatchers } = dailyHarness();
    s.currentPage.value = 'ops-track';
    s.api.origin.value = { reportId: 510, hotelId: 80, reportDate: '2026-08-23', intentId: 701 };
    change(s);
    flushWatchers();
    assert.equal(s.api.origin.value, null);
  }
});
