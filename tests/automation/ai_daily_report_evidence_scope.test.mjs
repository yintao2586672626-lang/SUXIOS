import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const main = readFileSync(
  process.env.SUXIOS_AI_DAILY_EVIDENCE_SOURCE || new URL('../../public/app-main.js', import.meta.url),
  'utf8',
);
const staticSource = readFileSync(new URL('../../public/revenue-ai-static.js', import.meta.url), 'utf8');
const dateContractSource = readFileSync(new URL('../../public/revenue-overview-contract-static.js', import.meta.url), 'utf8');
const extract = (source, from, to) => {
  const start = source.indexOf(from);
  const end = source.indexOf(to, start);
  assert.ok(start >= 0 && end > start, `actual source block: ${from}`);
  return source.slice(start, end);
};
const readbackGap = { code: 'ota_evidence_readback_unverified', source_ref: 'online_daily_data#42' };

function harness(options = {}) {
  const calls = [];
  const sandbox = {
    window: {},
    aiDailyReport: { value: Object.hasOwn(options, 'report') ? options.report : { hotel_id: 81, report_date: '2026-09-12' } },
    aiDailyReportForm: { value: { hotel_id: '81', report_date: '2026-09-23' } },
    coreOperationsHotelId: { value: options.hotel ?? '81' },
    coreOperationsTargetDate: { value: options.date ?? '2026-09-20' },
    coreOperationsMaxDate: '2026-09-26',
    coreOperationsRequestSeq: 0,
    dailyWorkbenchRequestSeq: 0,
    dailyWorkbenchPatrolRequestSeq: 0,
    phase3OperationEffectLoopRequestSeq: 0,
    competitorSummaryRequestSeq: 0,
    dailyWorkbenchLoading: { value: false },
    dailyWorkbenchPatrolLoading: { value: false },
    phase3OperationEffectLoopLoading: { value: false },
    competitorSummaryLoading: { value: false },
    currentPage: { value: 'ai-daily-report' },
    onlineDataTab: { value: 'ctrip' },
    onlineDataFilter: { value: { hotel_id: '81', source: options.source ?? 'all' } },
    dataHealthSecondaryPanelsReady: { value: true },
    dataHealthDetailPanelsReady: { value: true },
    dataHealthEmployeePanelsReady: { value: true },
    showToast: (message, type) => calls.push({ kind: 'toast', message, type }),
    resetCoreOperationsScopedState: () => calls.push({ kind: 'reset', page: sandbox.currentPage.value }),
    scheduleDataHealthSecondaryPanelsReady: () => calls.push({ kind: 'secondary' }),
    scheduleDataHealthDetailPanelsReady: () => calls.push({ kind: 'detail' }),
    scheduleDataHealthEmployeePanelsReady: () => calls.push({ kind: 'employee' }),
    scheduleDataHealthPanelRefresh: (mode, refreshOptions) => calls.push({
      kind: 'refresh', mode,
      options: refreshOptions == null ? refreshOptions : JSON.parse(JSON.stringify(refreshOptions)),
      hotel: sandbox.coreOperationsHotelId.value,
      date: sandbox.coreOperationsTargetDate.value,
      page: sandbox.currentPage.value,
      tab: sandbox.onlineDataTab.value,
    }),
    openOnlineDataEntryTab: async (tab, entryOptions) => {
      sandbox.onlineDataTab.value = tab;
      calls.push({ kind: 'entry', tab, options: JSON.parse(JSON.stringify(entryOptions)) });
    },
    loadOperationActions: async () => calls.push({ kind: 'operations' }),
  };
  vm.runInNewContext(`
    ${dateContractSource}
    ${extract(staticSource, 'const aiDailyReportActionSources =', 'const aiDailyReportActionIsInvestigationOnly =')}
    ${extract(main, 'const invalidateCoreOperationsScopedState =', 'const refreshCoreOperationsLoop =')}
    ${extract(main, 'const openAiDailyReportDataHealthTarget =', 'const aiDailyReportBlockingRows =')}
    ${extract(main, 'const aiDailyReportGapActionText =', 'const operationExecutionItems =')}
    globalThis.handlers = { aiDailyReportEvidenceTarget, openAiDailyReportEvidenceTarget, goAiDailyReportDataGap };
  `, sandbox);
  return { ...sandbox, calls, state: sandbox };
}

function assertHealthRefresh(h, expectedDate, resetCount = 1) {
  assert.equal(h.coreOperationsTargetDate.value, expectedDate);
  assert.equal(h.currentPage.value, 'online-data');
  assert.equal(h.onlineDataTab.value, 'data-health');
  assert.equal(h.calls.filter(call => call.kind === 'reset').length, resetCount);
  assert.deepEqual(h.calls.filter(call => call.kind === 'refresh'), [{
    kind: 'refresh', mode: 'light', options: { force: true },
    hotel: h.coreOperationsHotelId.value, date: expectedDate, page: 'online-data', tab: 'data-health',
  }]);
  assert.equal(h.calls.some(call => call.kind === 'toast'), false);
  assert.equal(h.dataHealthSecondaryPanelsReady.value, false);
  assert.equal(h.dataHealthDetailPanelsReady.value, false);
  assert.equal(h.dataHealthEmployeePanelsReady.value, false);
  if (resetCount) assert.equal(h.calls[0].kind, 'reset', 'old scoped facts are invalidated before navigation or refresh');
}

test('historical report gap carries the loaded report date and forces fresh data-health reads', async () => {
  const h = harness();
  await h.handlers.goAiDailyReportDataGap(readbackGap);
  assertHealthRefresh(h, '2026-09-12');
  assert.equal(h.aiDailyReportForm.value.report_date, '2026-09-23', 'the editing form is not the historical evidence owner');
  assert.equal(h.onlineDataFilter.value.source, 'all', 'a source record ref cannot imply a platform');
});

test('same-day report evidence avoids resetting facts but bypasses the light cache', async () => {
  const h = harness({ date: '2026-09-12' });
  await h.handlers.goAiDailyReportDataGap(readbackGap);
  assertHealthRefresh(h, '2026-09-12', 0);
});

test('legacy persisted report hotel/date remain sufficient and numeric hotel identities are compatible', async () => {
  const h = harness({ report: { hotel_id: '81', report_date: '2026-09-26' }, hotel: 81 });
  await h.handlers.goAiDailyReportDataGap(readbackGap);
  assertHealthRefresh(h, '2026-09-26');
});

test('a valid leap-day report remains navigable', async () => {
  const h = harness({ report: { hotel_id: 81, report_date: '2024-02-29' } });
  await h.handlers.goAiDailyReportDataGap(readbackGap);
  assertHealthRefresh(h, '2024-02-29');
});

test('prepared health targets use the same date guard without changing the existing channel filter', async () => {
  const h = harness({ source: 'meituan' });
  await h.handlers.openAiDailyReportEvidenceTarget({
    platform: 'ctrip', target: { page: 'online-data', tab: 'data-health' },
  });
  assertHealthRefresh(h, '2026-09-12');
  assert.equal(h.onlineDataFilter.value.source, 'meituan');
});

const invalidScopes = [
  ['missing report', { report: null }],
  ['missing persisted report date', { report: { hotel_id: 81 } }],
  ['empty persisted report date', { report: { hotel_id: 81, report_date: '' } }],
  ['malformed report date', { report: { hotel_id: 81, report_date: '2026/09/12' } }],
  ['calendar-overflow report date', { report: { hotel_id: 81, report_date: '2026-02-30' } }],
  ['invalid non-leap date', { report: { hotel_id: 81, report_date: '2025-02-29' } }],
  ['date newer than the available business-date limit', { report: { hotel_id: 81, report_date: '2026-09-27' } }],
  ['different report hotel', { report: { hotel_id: 82, report_date: '2026-09-12' } }],
  ['zero report hotel', { report: { hotel_id: 0, report_date: '2026-09-12' } }],
  ['missing report hotel', { report: { report_date: '2026-09-12' } }],
  ['zero selected core hotel', { hotel: '0' }],
  ['missing selected core hotel', { hotel: '' }],
];

for (const [label, options] of invalidScopes) {
  test(`${label} cannot navigate or refresh using the editor or stale health scope`, async () => {
    const h = harness(options);
    await h.handlers.goAiDailyReportDataGap(readbackGap);
    assert.equal(h.currentPage.value, 'ai-daily-report');
    assert.equal(h.onlineDataTab.value, 'ctrip');
    assert.equal(h.coreOperationsTargetDate.value, '2026-09-20');
    assert.equal(h.onlineDataFilter.value.source, 'all');
    assert.equal(h.dataHealthSecondaryPanelsReady.value, true);
    assert.equal(h.dataHealthDetailPanelsReady.value, true);
    assert.equal(h.dataHealthEmployeePanelsReady.value, true);
    assert.equal(h.calls.length, 1, 'only an explanatory toast is allowed');
    assert.equal(h.calls[0].kind, 'toast');
    assert.ok(String(h.calls[0].message || '').trim(), 'invalid scope has a visible reason');
  });
}

test('platform-source routing retains its force option without changing the independent health date', async () => {
  const h = harness({ report: null });
  await h.handlers.goAiDailyReportDataGap({
    code: 'ota_evidence_data_source_binding_missing', source_ref: 'online_daily_data#42', platform: 'meituan',
  });
  assert.equal(h.currentPage.value, 'online-data');
  assert.equal(h.onlineDataTab.value, 'platform-sources');
  assert.equal(h.coreOperationsTargetDate.value, '2026-09-20');
  assert.equal(h.onlineDataFilter.value.source, 'all');
  assert.deepEqual(h.calls, [{ kind: 'entry', tab: 'platform-sources', options: { force: true } }]);
});

test('execution-evidence routing does not inherit the new data-health validation or date mutation', async () => {
  const h = harness({ report: null });
  await h.handlers.goAiDailyReportDataGap({ code: 'execution_flow_missing' });
  assert.equal(h.currentPage.value, 'ops-track');
  assert.equal(h.coreOperationsTargetDate.value, '2026-09-20');
  assert.equal(h.onlineDataFilter.value.source, 'all');
  assert.deepEqual(h.calls, [{ kind: 'operations' }]);
});
