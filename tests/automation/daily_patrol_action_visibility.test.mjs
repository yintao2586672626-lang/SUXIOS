import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
import { compile, computed, createSSRApp, ref, watch } from 'vue';
import { renderToString } from '@vue/server-renderer';

const source = readFileSync('public/app-main.js', 'utf8');
const staticSandbox = { window: {} };
vm.runInNewContext(readFileSync('public/system-static.js', 'utf8'), staticSandbox);
vm.runInNewContext(readFileSync('public/system-page-projections.js', 'utf8'), staticSandbox);
const template = readFileSync('resources/frontend/templates/fragments/35-page-online-data.html', 'utf8');
const extract = (start, end) => {
  const from = source.indexOf(start), to = source.indexOf(end, from);
  assert.ok(from >= 0 && to > from, start);
  return source.slice(from, to);
};
const sectionStart = template.indexOf('<section data-testid="core-loop-operation-tasks"');
const sectionEnd = template.indexOf('</section>', sectionStart);
assert.ok(sectionStart >= 0 && sectionEnd > sectionStart);
const renderSection = compile(template.slice(sectionStart, sectionEnd + '</section>'.length));
const currentAction = {
  hotel_id: 7, hotel_name: 'Synthetic Hotel', target_date: '2026-09-14',
  action_code: 'new_action', question_key: 'new_question', action: 'Synthetic unsaved action',
  platform: 'ctrip', priority: 'medium', entry: 'operations',
};
const savedAction = { ...currentAction, action_code: 'saved_action', question_key: 'saved_question', action: 'Synthetic saved action' };
const snapshot = actions => ({
  run_id: 'daily_workbench_20260914_120000_11111111111111111111111111111111',
  scope: { hotel_id: 7, target_date: '2026-09-14' }, next_actions: actions,
  rows: [{ hotel_id: 7, target_date: '2026-09-14' }],
  action_tracking: { items: {} },
});
const absentActions = () => { const saved = snapshot([]); delete saved.next_actions; return saved; };

function harness(saved = null, current = [currentAction]) {
  const requests = [], notices = [], refreshes = [];
  let receipt = { code: 400, message: 'Synthetic generation failure' };
  const context = {
    appSystemStatic: staticSandbox.window.SUXI_SYSTEM_STATIC,
    dailyWorkbenchStatusText: value => String(value), dailyWorkbenchStatusClass: value => String(value),
    ref, computed, watch, Map,
    dataHealthPriorityText: value => String(value), dataHealthPriorityClass: value => String(value),
    employeeOtaChecklistCategoryClass: value => String(value), employeeOtaChecklistCategoryText: value => String(value),
    employeeOtaChecklistPriorityRank: value => ({ high: 0, medium: 1, low: 2 }[value] ?? 3),
    showToast: (...args) => notices.push(args), console: { error() {} },
    loadPhase3OperationEffectLoop: options => refreshes.push(options),
    request: async (url, options) => { requests.push({ url, body: JSON.parse(options.body) }); return receipt; },
  };
  const state = vm.runInNewContext(`(() => {
    ${extract('const dailyWorkbenchPatrol = ref(null);', 'const phase3OperationEffectLoop =')}
    const dailyWorkbench = ref(null), dailyWorkbenchRows = ref([]);
    const dailyWorkbenchLoading = ref(false), dailyWorkbenchError = ref('');
    const collectionReliabilityLoading = ref(false);
    const coreOperationsHotelId = ref('7'), coreOperationsTargetDate = ref('2026-09-14');
    const coreOperationsMaxDate = '2026-09-14';
    const otaTodayCollectionReminderRows = ref([]), dataAcquisitionWorkbenchRows = ref([]), dataHealthTodayWorkOrders = ref([]);
    ${extract('const normalizeDailyWorkbenchAction =', 'const normalizeDailyWorkbenchWorkflowStage =')}
    ${extract('const dailyWorkbenchPatrolLatest = computed(', 'const dailyWorkbenchPatrolHealth =')}
    ${extract('const dailyWorkbenchNextActions = computed(', 'const dailyWorkbenchEmptyText =')}
    ${extract('const dailyWorkbenchPatrolActionKey =', 'const dailyWorkbenchPatrolPositiveId =')}
    ${extract('const dailyWorkbenchPatrolActionItems = computed(', 'const dailyWorkbenchPatrolReviewStatus =')}
    ${extract('const dailyWorkbenchPatrolActionUpdatingKey =', 'const dailyWorkbenchPatrolReviewUpdatingKey =')}
    ${extract('const coreOperationsActionRows = computed(', 'const coreOperationsExecutionItems =')}
    ${extract('const employeeOtaChecklistRows = computed(', 'const runEmployeeOtaChecklistAction =')}
    ${extract('const openDailyWorkbenchPatrolConfirmation =', 'const exportDailyWorkbenchPatrolReport =')}
    return {
      dailyWorkbench, dailyWorkbenchLoading, dailyWorkbenchPatrol, dailyWorkbenchPatrolLatest,
      dailyWorkbenchPatrolVisibleActions, coreOperationsActionRows, employeeOtaChecklistRows, employeeOtaChecklistEmptyText,
      dailyWorkbenchPatrolRunning, dailyWorkbenchPatrolConfirming, dailyWorkbenchPatrolError,
      dailyWorkbenchPatrolActionUpdating, dailyWorkbenchPatrolActionUpdatingKey,
      runDailyWorkbenchPatrol, cancelDailyWorkbenchPatrolConfirmation,
      coreOperationsHotelId, coreOperationsTargetDate, dataHealthPriorityText,
      get dailyWorkbenchPatrolActionDataIncomplete() {
        return typeof dailyWorkbenchPatrolActionDataIncomplete === 'undefined' ? false : dailyWorkbenchPatrolActionDataIncomplete.value;
      },
    };
  })()`, context);
  state.dailyWorkbench.value = { scope: { hotel_id: 7, target_date: '2026-09-14', requested_hotel_limit: 1 }, next_actions: current };
  state.dailyWorkbenchPatrol.value = saved ? { latest: saved, health: { status: 'synthetic' } } : null;
  const render = () => renderToString(createSSRApp({
    setup: () => ({
      ...state, coreOperationsCanExecute: true,
      dailyWorkbenchWriteBoundary: { run: { confirmText: 'Synthetic existing explicit confirmation' } },
      dailyWorkbenchPatrolBoundaryText: 'Synthetic read boundary',
      exportDailyWorkbenchPatrolReport() {}, updateDailyWorkbenchPatrolAction() {},
    }),
    render: renderSection,
  }));
  return {
    ...state, requests, notices, refreshes, render, respond: value => { receipt = value; },
    get dailyWorkbenchPatrolActionDataIncomplete() { return state.dailyWorkbenchPatrolActionDataIncomplete; },
  };
}
const actionRows = view => view.employeeOtaChecklistRows.value.filter(row => row.actionSource);
const incompleteText = /不完整|缺失|无效/;

test('without a snapshot current actions remain a preview with generation available', async () => {
  const view = harness();
  assert.equal(view.coreOperationsActionRows.value[0].actionCode, 'new_action');
  assert.equal(view.coreOperationsActionRows.value[0].trackedStatus, 'not_snapshotted');
  assert.equal(actionRows(view)[0].canTrackAction, false);
  assert.equal(actionRows(view)[0].actionType, 'patrol');
  const html = await view.render();
  assert.match(html, /data-testid="core-loop-generate-action-list"/);
  assert.doesNotMatch(html, /转补证任务<\/button>|>转补证任务\s*</);
  assert.doesNotMatch(html, /data-testid="core-loop-export-patrol-report"/);
});

test('saved nonempty actions retain their identity and tracking instead of current recommendations', async () => {
  const saved = snapshot([savedAction]);
  saved.action_tracking.items['7|saved_action'] = { hotel_id: 7, status: 'in_progress', operation_execution: { intent_id: 81, task_id: 91 } };
  const view = harness(saved);
  assert.equal(view.coreOperationsActionRows.value[0].actionCode, 'saved_action');
  assert.equal(view.coreOperationsActionRows.value[0].trackedStatus, 'in_progress');
  assert.match(view.coreOperationsActionRows.value[0].executionText, /81.*91/);
  assert.equal(actionRows(view)[0].actionSource.actionCode, 'saved_action');
  assert.equal(actionRows(view)[0].canTrackAction, true);
  assert.match(await view.render(), /data-testid="core-loop-export-patrol-report"/);
});

test('saved empty actions remain empty when the current workbench has new recommendations', () => {
  const view = harness(snapshot([]));
  assert.equal(view.dailyWorkbenchPatrolVisibleActions.value.length, 0);
  assert.equal(view.coreOperationsActionRows.value.length, 0);
});

test('employee checklist does not reintroduce current actions after a saved empty result', () => {
  const view = harness(snapshot([]));
  assert.equal(actionRows(view).length, 0);
});

for (const [name, saved] of [
  ['missing', absentActions()], ['null', snapshot(null)],
  ['object', snapshot({ action_code: 'bad' })], ['string', snapshot('bad')],
]) {
  test(`saved ${name} action field cannot inherit executable workbench actions`, () => {
    const view = harness(saved);
    assert.equal(view.dailyWorkbenchPatrolVisibleActions.value.length, 0);
    assert.equal(view.coreOperationsActionRows.value.length, 0);
    assert.equal(actionRows(view).length, 0);
    assert.equal(view.dailyWorkbenchPatrolLatest.value.run_id, saved.run_id);
  });
}

test('missing saved actions render an incomplete state in both relevant empty views', async () => {
  const view = harness(absentActions(), []);
  const html = await view.render();
  assert.match(html, incompleteText);
  assert.doesNotMatch(html, /该目标日没有待处理的补证行动/);
  assert.match(view.employeeOtaChecklistEmptyText.value, incompleteText);
});

test('a malformed snapshot keeps report identity and offers the existing generation recovery', async () => {
  const saved = absentActions();
  const view = harness(saved, []);
  const html = await view.render();
  assert.match(html, /data-testid="core-loop-generate-action-list"/);
  assert.match(html, /data-testid="core-loop-export-patrol-report"/);
  assert.equal(view.dailyWorkbenchPatrolLatest.value.run_id, saved.run_id);
});

test('a genuine saved empty result keeps its truthful empty message and report', async () => {
  const view = harness(snapshot([]), []);
  const html = await view.render();
  assert.match(html, /该目标日没有待处理的补证行动/);
  assert.doesNotMatch(html, incompleteText);
  assert.match(html, /data-testid="core-loop-export-patrol-report"/);
});

test('preview, saved-empty and saved-actions transitions recompute without retaining phantom rows', async () => {
  const view = harness();
  assert.equal(view.dailyWorkbenchPatrolActionDataIncomplete, false);
  assert.equal(view.coreOperationsActionRows.value[0].actionCode, 'new_action');
  view.dailyWorkbenchPatrol.value = { latest: snapshot([]) };
  assert.equal(view.coreOperationsActionRows.value.length, 0);
  assert.equal(actionRows(view).length, 0);
  view.dailyWorkbenchPatrol.value.latest.next_actions = [savedAction];
  assert.equal(view.coreOperationsActionRows.value[0].actionCode, 'saved_action');
  view.dailyWorkbenchPatrol.value.latest.next_actions = null;
  assert.equal(view.dailyWorkbenchPatrolActionDataIncomplete, true);
  assert.equal(view.coreOperationsActionRows.value.length, 0);
  assert.equal(actionRows(view).length, 0);
  assert.match(await view.render(), incompleteText);
  view.dailyWorkbenchPatrol.value = null;
  assert.equal(view.dailyWorkbenchPatrolActionDataIncomplete, false);
  assert.equal(view.coreOperationsActionRows.value[0].trackedStatus, 'not_snapshotted');
});

test('generation recovery retains confirmation, failure preservation and current success refresh', async () => {
  const saved = absentActions();
  const view = harness(saved);
  await view.runDailyWorkbenchPatrol();
  assert.equal(view.dailyWorkbenchPatrolConfirming.value, true);
  assert.equal(view.requests.length, 0);
  assert.equal(view.dailyWorkbenchPatrolLatest.value.run_id, saved.run_id);
  view.cancelDailyWorkbenchPatrolConfirmation();
  assert.equal(view.dailyWorkbenchPatrolConfirming.value, false);
  await view.runDailyWorkbenchPatrol();
  await view.runDailyWorkbenchPatrol();
  assert.equal(view.requests.length, 1);
  assert.equal(view.dailyWorkbenchPatrolLatest.value.run_id, saved.run_id);
  assert.match(view.dailyWorkbenchPatrolError.value, /Synthetic generation failure/);
  const replacement = { ...snapshot([savedAction]), run_id: 'daily_workbench_20260914_130000_22222222222222222222222222222222' };
  view.respond({ code: 200, data: { latest: replacement, health: { status: 'manual_ready' } } });
  await view.runDailyWorkbenchPatrol();
  assert.equal(view.requests.length, 1);
  await view.runDailyWorkbenchPatrol();
  assert.equal(view.requests.length, 2);
  assert.equal(view.requests[1].body.hotel_id, 7);
  assert.equal(view.requests[1].body.target_date, '2026-09-14');
  assert.equal(view.dailyWorkbenchPatrolLatest.value.run_id, replacement.run_id);
  assert.equal(view.coreOperationsActionRows.value[0].actionCode, 'saved_action');
  assert.equal(view.refreshes[0].runId, replacement.run_id);
});

test('saved action rows retain existing hotel and target-date filtering', () => {
  const view = harness(snapshot([savedAction]));
  view.coreOperationsHotelId.value = '8';
  assert.equal(view.coreOperationsActionRows.value.length, 0);
  view.coreOperationsHotelId.value = '7';
  view.coreOperationsTargetDate.value = '2026-09-13';
  assert.equal(view.coreOperationsActionRows.value.length, 0);
});
