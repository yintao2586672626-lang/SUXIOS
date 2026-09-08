import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
const source = readFileSync('public/app-main.js', 'utf8');
const code = source.slice(source.indexOf('const homeDailyWorkflowError ='), source.indexOf('const operatingLearningList ='));
const ref = value => ({ value });
function harness() {
  const calls = [];
  const context = vm.createContext({
    ref, Date, captureAuthSession: () => 1, isAuthSessionCurrent: () => true,
    authContext: ref({ permissionStatus: 'allowed' }),
    filterReportHotel: ref('7'), homeRevenueFactBusinessDate: ref('2026-09-07'),
    operatingQuestionForm: ref({ hotel_id: '8', platform: 'ctrip', date_start: '2026-09-01', date_end: '2026-09-01' }),
    reportHotelOptionExists: id => id === '7', visibleMenuItems: ref(['compass', 'online-data', 'ops-track']),
    findMenuItemByPath: (items, target) => items.includes(target), nextTick: async () => {},
    document: { querySelector: () => ({ getAttribute: () => 'false', click: () => calls.push('query') }) },
    coreOperationsHotelId: ref(''), coreOperationsTargetDate: ref(''), localCollectorBackfillDate: ref(''),
    operationFilters: ref({ hotel_id: '8', date: '' }),
    openOnlineDataEntryTab: async (tab, options) => { calls.push(tab); assert.equal(options.force, true, 'selected business date must bypass the hotel-only light cache'); },
    openHomeOperatingScheduleAll: async () => calls.push('tasks'),
    operationExecutionStages: ref([{ key: 'effect_review', label: '待复盘' }]), operationExecutionStageFilter: ref(''),
    refreshCompassDashboard: async () => calls.push('facts'),
  });
  vm.runInContext(code + '\nglobalThis.open = openHomeDailyWorkflow; globalThis.error = homeDailyWorkflowError;', context);
  return { context, calls };
}
test('all five entries invoke existing operations while preserving the selected hotel and date', async () => {
  const { context: c, calls } = harness();
  for (const flow of ['facts', 'query', 'health', 'tasks', 'review']) assert.equal(await c.open(flow), true);
  assert.deepEqual(calls, ['facts', 'query', 'data-health', 'tasks', 'tasks']);
  assert.equal(c.operatingQuestionForm.value.hotel_id, '7');
  assert.equal(c.operatingQuestionForm.value.date_start, '2026-09-07');
  assert.equal(c.operatingQuestionForm.value.date_end, '2026-09-07');
  assert.equal(c.coreOperationsHotelId.value, '7');
  assert.equal(c.coreOperationsTargetDate.value, '2026-09-07');
  assert.equal(c.localCollectorBackfillDate.value, '2026-09-07');
  assert.equal(c.operationFilters.value.hotel_id, '7');
  assert.equal(c.operationFilters.value.date, '', 'homepage date must not pretend to filter the all-task list');
  assert.equal(c.operationExecutionStageFilter.value, 'effect_review');
});
test('missing hotel, pending/denied permission and unavailable destinations stay visible without opening', async () => {
  for (const change of [c => c.filterReportHotel.value = '', c => c.authContext.value.permissionStatus = 'denied', c => c.authContext.value.permissionStatus = 'unknown', c => c.visibleMenuItems.value = []]) {
    const { context: c, calls } = harness(); change(c);
    assert.equal(await c.open('health'), false); assert.equal(calls.length, 0); assert.ok(c.error.value);
  }
});
test('empty and invalid calendar dates do not silently select today', async () => {
  for (const date of ['', '2026-02-31']) {
    const { context: c, calls } = harness(); c.homeRevenueFactBusinessDate.value = date;
    assert.equal(await c.open('query'), false); assert.match(c.error.value, /有效/); assert.equal(calls.length, 0);
  }
});
test('lazy query entrance failure is visible and can be retried', async () => {
  const { context: c, calls } = harness(); c.document.querySelector = () => null;
  assert.equal(await c.open('query'), false); assert.match(c.error.value, /尚未加载/);
  c.document.querySelector = () => ({ getAttribute: () => 'false', click: () => calls.push('query') });
  assert.equal(await c.open('query'), true); assert.equal(c.error.value, '');
});
test('query entry never closes an already opened assistant', async () => {
  const { context: c, calls } = harness(); c.document.querySelector = () => ({ getAttribute: () => 'true', click: () => calls.push('closed') });
  assert.equal(await c.open('query'), true); assert.equal(calls.length, 0);
});
test('hotel switch while query entrance is preparing cannot open the old query', async () => {
  const { context: c, calls } = harness(); c.nextTick = async () => { c.filterReportHotel.value = '8'; };
  assert.equal(await c.open('query'), false); assert.equal(calls.length, 0); assert.equal(c.error.value, '');
});
