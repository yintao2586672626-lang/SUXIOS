import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';
import vm from 'node:vm';

const sourceRoot = process.argv.find(value => value.startsWith('--source-root='))?.slice(14);
const readSource = path => {
  const mapped = sourceRoot && resolve(sourceRoot, path);
  return readFileSync(mapped && existsSync(mapped) ? mapped : path, 'utf8');
};
const component = readSource('public/components/system/app-main-components.js');
const app = readSource('public/app-main.js');
const extract = (source, from, to) => {
  const start = source.indexOf(from), end = source.indexOf(to, start);
  assert.ok(start >= 0 && end > start);
  return source.slice(start, end);
};
const date = '2026-09-14';
const report = overrides => ({ id: 91, hotel_id: 80, report_date: date, model_status: 'not_requested', ...overrides });
const task = overrides => ({ task_id: 'synthetic-daily-80', hotel_id: 80, report_date: date,
  status: 'succeeded', stage: 'completed', result_report_id: 91, done: true, ...overrides });
const response = data => ({ code: 200, data });
function harness(transport) {
  const sandbox = vm.createContext({ transport, setTimeout: callback => callback() });
  return vm.runInContext(`(() => {
    ${extract(component, '// AI_DAILY_REPORT_TASK_HELPERS_START', '// AI_DAILY_REPORT_TASK_HELPERS_END')}
    const ref = value => ({ value });
    const operationLoading = ref({ aiDailyReport: false }), operationError = ref({ aiDailyReport: '' });
    const aiDailyReportGenerationTaskPolling = ref(false), aiDailyReportGenerationTask = ref(null);
    const aiDailyReportForm = ref({ hotel_id: 80, report_date: '${date}', use_llm: false });
    const operationFilters = ref({}), aiDailyReport = ref(null), operationYesterday = '${date}', aiDailyReportYesterday = '${date}';
    let aiDailyReportGenerationRequestSeq = 0, aiDailyReportRequestSeq = 0;
    const aiDailyReportTaskReturn = ref({ reportId: 77, hotelId: 80, reportDate: '2026-09-14' });
    let sessionEpoch = 1;
    const captureAuthSession = () => sessionEpoch;
    const isAuthSessionCurrent = epoch => epoch === sessionEpoch;
    const ensureOperationStaticReady = async () => true, ensureRevenueAiStaticReady = async () => true;
    const normalizeOperationHotelSelection = form => form.value.hotel_id;
    const loadAiDailyFactGate = async () => {}, loadOperationActions = async () => {};
    const apiRequest = transport;
    const notices = [], showToast = (message, kind) => notices.push({ message, kind });
    const operationErrorMessage = error => error.message;
    ${['validateAiDailyReportReadback', 'readAiDailyReportById', 'generateAiDailyReport'].map(name =>
      extract(app, `const ${name} =`, '\n            };') + '\n            };').join('\n')}
    return { generateAiDailyReport, aiDailyReport, aiDailyReportTaskReturn, aiDailyReportForm, aiDailyReportGenerationTask, operationLoading, operationError, notices,
      advanceSession: () => { sessionEpoch += 1; } };
  })()`, sandbox);
}

test('cleared report date cannot silently generate a report for yesterday', async () => {
  const calls = [];
  const view = harness(path => {
    calls.push(path);
    return response(task());
  });
  view.aiDailyReportForm.value.report_date = '';
  assert.equal(await view.generateAiDailyReport(), null);
  assert.equal(calls.length, 0);
  assert.match(view.operationError.value.aiDailyReport, /日期/);
  assert.equal(view.aiDailyReport.value, null);
});

for (const wrongDate of ['2026-09-13', '']) {
  test(`direct report for a different or missing date is rejected (${wrongDate || 'missing'})`, async () => {
    const view = harness(() => response(report({ report_date: wrongDate })));
    assert.equal(await view.generateAiDailyReport(), null);
    assert.equal(view.aiDailyReport.value, null);
    assert.match(view.operationError.value.aiDailyReport, /日期/);
    assert.equal(view.operationLoading.value.aiDailyReport, false);
    assert.equal(view.notices.some(item => item.kind === 'success'), false);
  });
}

test('wrong-date initial task is rejected before polling or report readback', async () => {
  const calls = [];
  const view = harness((path, options) => {
    calls.push(path);
    return response(options ? task({ report_date: '2026-09-13' }) : report());
  });
  assert.equal(await view.generateAiDailyReport(), null);
  assert.match(view.operationError.value.aiDailyReport, /日期/);
  assert.equal(calls.length, 1);
  assert.equal(view.aiDailyReport.value, null);
});

test('wrong-date terminal polling result cannot trigger final report readback', async () => {
  const calls = [];
  const view = harness((path, options) => {
    calls.push(path);
    if (options) return response(task({ status: 'queued', stage: 'queued', result_report_id: null, done: false }));
    if (path.includes('/tasks/')) return response(task({ report_date: '2026-09-13' }));
    return response(report());
  });
  assert.equal(await view.generateAiDailyReport(), null);
  assert.match(view.operationError.value.aiDailyReport, /日期/);
  assert.equal(calls.some(path => path === '/ai-daily-reports/91'), false);
  assert.equal(view.aiDailyReport.value, null);
});

test('exact ID readback still requires the requested business date', async () => {
  const view = harness((path, options) => response(options ? task() : report({ report_date: '2026-09-13' })));
  assert.equal(await view.generateAiDailyReport(), null);
  assert.match(view.operationError.value.aiDailyReport, /日期/);
  assert.equal(view.aiDailyReportGenerationTask.value.readbackStatus, 'failed');
  assert.equal(view.aiDailyReport.value, null);
});

test('matching queued task and limited report retain exact readback and the data-gap warning', async () => {
  const view = harness((path, options) => {
    if (options) return response(task({ status: 'queued', stage: 'queued', result_report_id: null, done: false }));
    if (path.includes('/tasks/')) return response(task({ status: 'blocked', stage: 'completed_with_data_gap', model_status: 'blocked_by_data_quality' }));
    return response(report({ model_status: 'blocked_by_data_quality' }));
  });
  assert.equal((await view.generateAiDailyReport()).report_date, date);
  assert.equal(view.aiDailyReportGenerationTask.value.readbackStatus, 'verified');
  assert.equal(view.operationError.value.aiDailyReport, '');
  assert.equal(view.notices.at(-1).kind, 'warning');
});

for (const queued of [false, true]) {
  test(`successful regeneration releases the old report return anchor (${queued ? 'queued' : 'direct'})`, async () => {
    const view = harness((path, options) => response(options && queued ? task() : report()));
    assert.equal((await view.generateAiDailyReport()).id, 91);
    assert.equal(view.aiDailyReportTaskReturn.value, null);
  });
}

test('failed regeneration retains the original return anchor for recovery', async () => {
  const view = harness(() => { throw new Error('synthetic failure'); });
  view.aiDailyReportTaskReturn.value = { reportId: 90, hotelId: 80, reportDate: date };
  assert.equal(await view.generateAiDailyReport(), null);
  assert.equal(view.aiDailyReportTaskReturn.value.reportId, 90);
});

test('editing the business date while generation is pending cannot publish the old report', async () => {
  let finish;
  const view = harness(() => new Promise(resolve => { finish = resolve; }));
  const generating = view.generateAiDailyReport();
  for (let n = 0; n < 8 && !finish; n += 1) await Promise.resolve();
  assert.equal(typeof finish, 'function');
  view.aiDailyReportForm.value.report_date = '2026-09-15';
  finish(response(report({ report_date: date })));
  assert.equal(await generating, null);
  assert.equal(view.aiDailyReport.value, null);
  assert.equal(view.notices.some(item => item.kind === 'success'), false);
});

test('a response from the previous login session cannot publish a report or error', async () => {
  let finish;
  const view = harness(() => new Promise(resolve => { finish = resolve; }));
  const generating = view.generateAiDailyReport();
  for (let n = 0; n < 8 && !finish; n += 1) await Promise.resolve();
  assert.equal(typeof finish, 'function');
  view.advanceSession();
  finish(response(report({ report_date: date })));
  assert.equal(await generating, null);
  assert.equal(view.aiDailyReport.value, null);
  assert.equal(view.operationError.value.aiDailyReport, '');
  assert.equal(view.notices.length, 0);
  assert.equal(view.operationLoading.value.aiDailyReport, false);
});

test('a changed date cancels queued polling without leaving the old task on the page', async () => {
  let finishTask;
  const view = harness((path, options) => {
    if (options) return response(task({ status: 'queued', stage: 'queued', result_report_id: null, done: false }));
    if (path.includes('/tasks/')) return new Promise(resolve => { finishTask = resolve; });
    return response(report());
  });
  const generating = view.generateAiDailyReport();
  for (let n = 0; n < 20 && !finishTask; n += 1) await Promise.resolve();
  assert.equal(typeof finishTask, 'function');
  assert.equal(view.aiDailyReportGenerationTask.value.status, 'queued');
  view.aiDailyReportForm.value.report_date = '2026-09-15';
  finishTask(response(task()));
  assert.equal(await generating, null);
  assert.equal(view.aiDailyReportGenerationTask.value, null);
  assert.equal(view.aiDailyReport.value, null);
  assert.equal(view.operationLoading.value.aiDailyReport, false);
});

test('an obsolete generation failure does not replace the new date with an error', async () => {
  let fail;
  const view = harness(() => new Promise((_, reject) => { fail = reject; }));
  const generating = view.generateAiDailyReport();
  for (let n = 0; n < 8 && !fail; n += 1) await Promise.resolve();
  assert.equal(typeof fail, 'function');
  view.aiDailyReportForm.value.report_date = '2026-09-15';
  fail(new Error('old request failed'));
  assert.equal(await generating, null);
  assert.equal(view.operationError.value.aiDailyReport, '');
  assert.equal(view.notices.length, 0);
});
