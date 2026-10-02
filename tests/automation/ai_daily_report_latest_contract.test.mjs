import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync('public/app-main.js', 'utf8');
const section = source.slice(source.indexOf('let aiDailyReportRequestSeq = 0;'),
  source.indexOf('const validateAiDailyReportReadback ='));
assert.match(section, /const loadAiDailyReport = async/);

const makeRead = (data, options = {}) => {
  const ref = value => ({ value });
  const report = ref(null);
  const error = ref({ aiDailyReport: '' });
  const form = ref({ hotel_id: '80', report_date: '2026-09-28' });
  let sessionEpoch = 1;
  const sandbox = vm.createContext({
    URLSearchParams, Promise,
    currentPage: ref('ai-daily-report'), pageRequestGeneration: 1, aiDailyReportTaskReturn: ref(null),
    aiDailyReportGenerationTaskPolling: ref(false), operationLoading: ref({ aiDailyReport: false }),
    operationError: error, aiDailyReport: report, aiDailyReportForm: form,
    operationFilters: ref({ hotel_id: '80' }),
    ensureOperationStaticReady: async () => {}, ensureRevenueAiStaticReady: async () => {},
    normalizeOperationHotelSelection: () => form.value.hotel_id, loadAiDailyFactGate: async () => {},
    apiRequest: options.request || (async () => ({ code: 200, data })), operationErrorMessage: err => err.message,
    captureAuthSession: () => sessionEpoch,
    isAuthSessionCurrent: epoch => epoch === sessionEpoch,
  });
  const validatorStart = source.indexOf('const validateAiDailyReportReadback =');
  const validatorEnd = source.indexOf('const readAiDailyReportById =', validatorStart);
  const component = readFileSync('public/components/system/app-main-components.js', 'utf8');
  const helpers = component.slice(component.indexOf('// AI_DAILY_REPORT_TASK_HELPERS_START'), component.indexOf('// AI_DAILY_REPORT_TASK_HELPERS_END'));
  vm.runInContext(`${helpers}\n${source.slice(validatorStart, validatorEnd)}\n${section}\nglobalThis.readLatest = loadAiDailyReport;`, sandbox);
  return { read: sandbox.readLatest, report, error, form, switchSession: () => { sessionEpoch++; } };
};

test('latest AI report accepts only complete success for the selected hotel and business date', async () => {
  const valid = { id: 19, hotel_id: 80, report_date: '2026-09-28', summary: 'selected date' };
  const accepted = makeRead({ data_status: 'ok', report: valid, data_gaps: [] });
  await accepted.read();
  assert.equal(accepted.report.value?.id, 19);
  assert.equal(accepted.error.value.aiDailyReport, '');
  const pending = makeRead({ data_status: 'pending', report: null,
    data_gaps: [{ code: 'ai_daily_report_not_generated' }] });
  await pending.read();
  assert.equal(pending.report.value, null);
  assert.equal(pending.error.value.aiDailyReport, '');

  for (const [label, data] of [
    ['wrong hotel', { data_status: 'ok', report: { ...valid, hotel_id: 81 }, data_gaps: [] }],
    ['wrong business date', { data_status: 'ok', report: { ...valid, report_date: '2026-09-26' }, data_gaps: [] }],
    ['missing status', { report: valid, data_gaps: [] }],
    ['pending with report', { data_status: 'pending', report: valid, data_gaps: [] }],
    ['success without report', { data_status: 'ok', report: null, data_gaps: [] }],
  ]) {
    const candidate = makeRead(data);
    await candidate.read();
    assert.equal(candidate.report.value, null, label);
    assert.notEqual(candidate.error.value.aiDailyReport, '', label);
  }
});

for (const scope of ['hotel', 'session']) {
  for (const oldOutcome of ['success', 'failure']) {
    test(`late ${oldOutcome} latest read from an old ${scope} cannot replace the current view`, async () => {
    let finishOld;
    let reads = 0;
    const current = { id: 20, hotel_id: 80, report_date: '2026-09-28', summary: 'current' };
    const view = makeRead(null, { request: () => {
      reads++;
      return reads === 1 ? new Promise(resolve => { finishOld = resolve; })
        : Promise.resolve({ code: 200, data: { data_status: 'ok', report: {
          ...current, hotel_id: scope === 'hotel' ? 81 : 80,
        }, data_gaps: [] } });
    } });
    const old = view.read();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(typeof finishOld, 'function');
    if (scope === 'hotel') view.form.value.hotel_id = '81';
    else view.switchSession();
    finishOld(oldOutcome === 'success'
      ? { code: 200, data: { data_status: 'ok', report: {
        id: 19, hotel_id: 80, report_date: '2026-09-26', summary: 'old session or hotel',
      }, data_gaps: [] } }
      : { code: 503, message: 'old read failed' });
    await old;
    assert.equal(view.report.value, null);
    assert.equal(view.error.value.aiDailyReport, '');
    await view.read();
    assert.equal(view.report.value?.id, 20, 'same-scope new read recovers');
    });
  }
}
