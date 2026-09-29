import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const main = readFileSync(new URL('../../public/app-main.js', import.meta.url), 'utf8');
const staticSource = readFileSync(new URL('../../public/revenue-ai-static.js', import.meta.url), 'utf8');
const template = readFileSync('resources/frontend/templates/fragments/16-page-ai-daily-report.html', 'utf8');
const extract = (source, from, to) => {
  const start = source.indexOf(from);
  const end = source.indexOf(to, start);
  assert.ok(start >= 0 && end > start, `real source block: ${from}`);
  return source.slice(start, end);
};

test('AI daily data-gap buttons follow their displayed evidence destination', async () => {
  assert.match(template, /@click="goAiDailyReportDataGap\(gap\)"/);
  const calls = [];
  const sandbox = {
    window: {},
    aiDailyReport: { value: { hotel_id: 81, report_date: '2026-09-12' } },
    coreOperationsHotelId: { value: '81' },
    coreOperationsTargetDate: { value: '2026-09-12' },
    coreOperationsMaxDate: '2026-09-26',
    resetCoreOperationsScopedState: () => calls.push('reset'),
    showToast: () => calls.push('notice'),
    currentPage: { value: '' }, onlineDataTab: { value: '' },
    dataHealthSecondaryPanelsReady: { value: true },
    dataHealthDetailPanelsReady: { value: true },
    dataHealthEmployeePanelsReady: { value: true },
    scheduleDataHealthSecondaryPanelsReady: () => calls.push('secondary'),
    scheduleDataHealthDetailPanelsReady: () => calls.push('detail'),
    scheduleDataHealthEmployeePanelsReady: () => calls.push('employee'),
    scheduleDataHealthPanelRefresh: (mode, options) => { assert.equal(options?.force, true); calls.push(`health:${mode}`); },
    openOnlineDataEntryTab: async tab => { sandbox.onlineDataTab.value = tab; calls.push(`tab:${tab}`); },
    loadOperationActions: async () => calls.push('operations'),
  };
  vm.runInNewContext(readFileSync('public/revenue-overview-contract-static.js', 'utf8'), sandbox);
  vm.runInNewContext(`
    ${extract(staticSource, 'const aiDailyReportActionSources =', 'const aiDailyReportActionIsInvestigationOnly =')}
    ${extract(main, 'const openAiDailyReportDataHealthTarget =', 'const aiDailyReportBlockingRows =')}
    ${extract(main, 'const aiDailyReportGapActionText =', 'const operationExecutionItems =')}
    globalThis.handlers = { aiDailyReportGapActionText, goAiDailyReportDataGap };
  `, sandbox);

  const platformGap = { code: 'ota_evidence_data_source_binding_missing', source_ref: 'online_daily_data#42' };
  assert.equal(sandbox.handlers.aiDailyReportGapActionText(platformGap), '查看平台数据源');
  await sandbox.handlers.goAiDailyReportDataGap(platformGap);
  assert.equal(sandbox.currentPage.value, 'online-data');
  assert.equal(sandbox.onlineDataTab.value, 'platform-sources');
  assert.ok(calls.includes('tab:platform-sources'));

  calls.length = 0;
  const operationGap = { code: 'execution_flow_missing' };
  assert.equal(sandbox.handlers.aiDailyReportGapActionText(operationGap), '查看执行闭环');
  await sandbox.handlers.goAiDailyReportDataGap(operationGap);
  assert.equal(sandbox.currentPage.value, 'ops-track');
  assert.ok(calls.includes('operations'));

  calls.length = 0;
  const readbackGap = { code: 'ota_evidence_readback_unverified', source_ref: 'online_daily_data#42' };
  assert.equal(sandbox.handlers.aiDailyReportGapActionText(readbackGap), '查看数据健康');
  await sandbox.handlers.goAiDailyReportDataGap(readbackGap);
  assert.equal(sandbox.onlineDataTab.value, 'data-health');
  assert.equal(sandbox.currentPage.value, 'online-data');
  assert.equal(sandbox.dataHealthSecondaryPanelsReady.value, false);
  assert.equal(sandbox.dataHealthDetailPanelsReady.value, false);
  assert.equal(sandbox.dataHealthEmployeePanelsReady.value, false);
  assert.deepEqual(calls, ['secondary', 'detail', 'employee', 'health:light']);
});
