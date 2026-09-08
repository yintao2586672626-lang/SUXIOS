import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync('public/app-main.js', 'utf8');
function method(start, end) {
  const from = source.indexOf(start);
  const to = source.indexOf(end, from);
  assert.ok(from >= 0 && to > from, `Missing product method: ${start}`);
  return source.slice(from, to);
}
function navigationHarness(phase) {
  const reads = [];
  const notices = [];
  const context = vm.createContext({
    document: { documentElement: { dataset: { suxiRenderPhase: phase } } },
    pendingOperationNavigation: null,
    suppressNextOpsTrackAutoLoad: false,
    operationFilters: { value: { hotel_id: '7' } },
    operationExecutionStageFilter: { value: '' },
    currentPage: { value: 'compass' },
    operationExecutionItems: { value: [{ id: 41, hotel_id: 7 }] },
    nextTick: async () => {},
    loadOperationActions: async options => { reads.push(options); },
    showToast: (...args) => notices.push(args),
    openOnlineDataEntryTab: () => {},
  });
  vm.runInContext(method('const openHomeOperatingScheduleItem = async', 'const openHomeOperatingScheduleAll = async')
    + '\nglobalThis.openItem = openHomeOperatingScheduleItem;', context);
  return { context, reads, notices };
}

test('home intent survives deferred full-page mounting without reading from the discarded app', async () => {
  const { context, reads } = navigationHarness('startup');
  await context.openItem({ intentId: 41, hotelId: 7 });
  assert.equal(context.currentPage.value, 'ops-track');
  assert.equal(context.operationFilters.value.hotel_id, '7');
  assert.equal(reads.length, 0, 'the replacement app must read after its own authenticated bootstrap');
  assert.equal(context.pendingOperationNavigation.intentId, 41);
  assert.equal(context.pendingOperationNavigation.hotelId, 7);
});

test('an already-mounted operations page opens the exact intent once', async () => {
  const { context, reads } = navigationHarness('full');
  await context.openItem({ intentId: 41, hotelId: 7 });
  assert.equal(reads.length, 1);
  assert.equal(reads[0].focusIntentId, 41);
  assert.equal(context.suppressNextOpsTrackAutoLoad, true, 'page watcher must not compete with exact readback');
  assert.equal(context.pendingOperationNavigation, null);
});

test('missing intent identity cannot navigate or start a request', async () => {
  const { context, reads } = navigationHarness('startup');
  await context.openItem({ hotelId: 7 });
  assert.equal(context.currentPage.value, 'compass');
  assert.equal(reads.length, 0);
  assert.equal(context.pendingOperationNavigation, null);
});

test('my tasks selector uses the loaded operation library and requests the selected view', async () => {
  const reads = [];
  const context = vm.createContext({
    window: {},
    operationExecutionViewMode: { value: 'all' },
    operationExecutionStageFilter: { value: 'evidence' },
    operationLoading: { value: { actions: false } },
    ensureOperationStaticReady: async () => context.window.SUXI_OPERATION_STATIC,
    requireOperationStatic: (library, key) => library[key],
    loadOperationActions: async () => { reads.push(context.operationExecutionViewMode.value); },
  });
  vm.runInContext(fs.readFileSync('public/operation-static.js', 'utf8'), context);
  vm.runInContext(method('const setOperationExecutionViewMode = ', 'const operationExecutionPhase1Evidence =')
    + '\nglobalThis.setView = setOperationExecutionViewMode;', context);
  await context.setView('mine');
  assert.equal(context.operationExecutionViewMode.value, 'mine');
  assert.equal(context.operationExecutionStageFilter.value, '');
  assert.deepEqual(reads, ['mine']);
  await context.setView('all');
  assert.deepEqual(reads, ['mine', 'all']);
});

test('authenticated remount restores the requested revenue tab after session state was reset', () => {
  const context = vm.createContext({
    currentPage: { value: 'agent-center' },
    authContext: { value: { permissionStatus: 'allowed' } },
    initialAgentTabOverride: 'revenue',
    initialRevenueAgentTabOverride: 'analysis',
    agentTab: { value: 'overview' },
    revenueAgentTab: { value: 'analysis' },
  });
  vm.runInContext(method('const restoreInitialAgentNavigation = ', 'const SUPER_ADMIN_ONLY_PAGES =')
    + '\nglobalThis.restore = restoreInitialAgentNavigation;', context);
  context.restore();
  assert.equal(context.agentTab.value, 'revenue');
  assert.equal(context.revenueAgentTab.value, 'analysis');
  context.agentTab.value = 'overview';
  context.authContext.value.permissionStatus = 'denied';
  context.restore();
  assert.equal(context.agentTab.value, 'overview');
  context.authContext.value.permissionStatus = 'allowed';
  context.currentPage.value = 'compass';
  context.restore();
  assert.equal(context.agentTab.value, 'overview');
});

function activationHarness({ permission = 'allowed', tick, ready, dedup = false } = {}) {
  const reads = [];
  const context = vm.createContext({
    authContext: { value: { permissionStatus: permission } },
    currentPage: { value: 'ops-track' },
    operationFilters: { value: { hotel_id: '7' } },
    pendingOperationNavigation: { hotelId: 7, intentId: 41 },
    operationLoading: { value: { actions: false } },
    operationError: { value: { actions: '' } },
    sessionEpoch: 1,
    captureAuthSession: () => context.sessionEpoch,
    isAuthSessionCurrent: epoch => epoch === context.sessionEpoch,
    nextTick: async () => { await tick?.(context); },
    ensureOperationStaticReady: async () => { await ready?.(context); },
    runPageLoadOnce: (_page, _key, task) => dedup ? Promise.resolve() : task(),
    loadOperationActions: async options => { reads.push(options); return true; },
    loadAiDailyReport: async () => {},
    operationErrorMessage: error => error.message,
  });
  vm.runInContext(method('const activateOpsTrackPage = ', 'watch(currentPage, (newPage) => {')
    + '\nglobalThis.activate = activateOpsTrackPage;', context);
  return { context, reads };
}

test('operations bootstrap waits for permission and then resumes the exact pending intent', async () => {
  const { context, reads } = activationHarness({ permission: 'unknown' });
  assert.equal(await context.activate(), false);
  assert.equal(reads.length, 0);
  assert.equal(context.pendingOperationNavigation.intentId, 41);
  context.authContext.value.permissionStatus = 'allowed';
  assert.equal(await context.activate(), true);
  assert.equal(reads.length, 1);
  assert.equal(reads[0].focusIntentId, 41);
  assert.equal(context.pendingOperationNavigation, null);
  assert.equal(context.operationLoading.value.actions, false);
});

test('a denied scope cannot read an operations task', async () => {
  const { context, reads } = activationHarness({ permission: 'denied' });
  assert.equal(await context.activate(), false);
  assert.equal(reads.length, 0);
});

test('scope and session changes during page preparation cannot read the previous task', async () => {
  for (const ready of [
    context => { context.operationFilters.value.hotel_id = '8'; },
    context => { context.sessionEpoch += 1; },
  ]) {
    const { context, reads } = activationHarness({ ready });
    assert.equal(await context.activate(), false);
    assert.equal(reads.length, 0);
  }
});

test('a queued hotel change cannot turn an exact task link into a different hotel list', async () => {
  const { context, reads } = activationHarness({ tick: context => { context.operationFilters.value.hotel_id = '8'; } });
  assert.equal(await context.activate(), false);
  assert.equal(reads.length, 0);
  assert.match(context.operationError.value.actions, /门店不一致/);
});

test('a deduplicated page load cannot leave a fresh loading flag stuck', async () => {
  const { context, reads } = activationHarness({ dedup: true });
  await context.activate();
  assert.equal(reads.length, 0);
  assert.equal(context.operationLoading.value.actions, false);
});

function flowHarness(flowData) {
  const oldFlow = { data_status: 'ok', list: [{ id: 9, hotel_id: 7 }] };
  const ref = value => ({ value });
  const context = vm.createContext({
    URLSearchParams,
    operationActionsRequestSeq: 0,
    currentPage: ref('ops-track'),
    captureAuthSession: () => 1,
    isAuthSessionCurrent: session => session === 1,
    currentPageReadPolicy: () => ({ scope: 'page', pageKey: 'ops-track', pageGeneration: 1, sessionEpoch: 1, tenantId: '70', systemHotelId: '7', businessDate: '2026-09-07' }),
    operationFilters: ref({ hotel_id: '7' }),
    filterReportHotel: ref('7'),
    operationYesterday: '2026-09-07',
    operationLoading: ref({ actions: false }),
    operationError: ref({ actions: '' }),
    operatingGoalInterventionLoading: ref(false),
    operatingGoalInterventionError: ref(''),
    operationExecutionViewMode: ref('all'),
    operationExecutionFlow: ref(oldFlow),
    operationActions: ref([]),
    operationEffectValidation: ref({}),
    operationClosureOverview: ref({}),
    operatingGoalInterventionOverview: ref({}),
    homeOperatingScheduleError: ref(''),
    homeOperatingScheduleFlow: ref(null),
    homeOperatingScheduleScopeHotelId: ref('7'),
    homeOperatingScheduleLastReadAt: ref(''),
    ensureOperationStaticReady: async () => {},
    normalizeOperationHotelSelection: () => '7',
    loadOperatingMemories: async () => {},
    operationErrorMessage: error => error.message,
    showToast: () => {},
    apiRequest: async path => ({ code: 200, data: /^\/operation\/(execution-flow|my-tasks)/.test(path) ? flowData : {} }),
  });
  vm.runInContext(method('const applyHomeOperatingScheduleFlow = ', 'const loadHomeOperatingSchedule = async'), context);
  vm.runInContext(method('const loadOperationActions = async', 'const parseOperationEvidenceNumber =')
    + '\nglobalThis.loadFlow = loadOperationActions;', context);
  return context;
}

test('exact readback accepts only the requested task in the requested hotel', async () => {
  const context = flowHarness({ capabilities: { hotel_id: 7 }, list: [{ id: 41, hotel_id: 7 }] });
  assert.equal(await context.loadFlow({ focusIntentId: 41 }), true);
  assert.equal(context.operationExecutionFlow.value.list[0].id, 41);
  assert.equal(context.operationError.value.actions, '');
});

test('a late operations response cannot replace the homepage state after navigation', async () => {
  const context = flowHarness({ capabilities: { hotel_id: 7 }, list: [{ id: 41, hotel_id: 7 }] });
  let finish;
  const pending = new Promise(resolve => { finish = resolve; });
  const originalRequest = context.apiRequest;
  context.apiRequest = async path => {
    if (path.startsWith('/operation/execution-flow')) await pending;
    return originalRequest(path);
  };
  const reading = context.loadFlow();
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(context.operationExecutionFlow.value.list.length, 0);
  const clearedFlow = context.operationExecutionFlow.value;
  context.currentPage.value = 'compass';
  context.homeOperatingScheduleError.value = 'new homepage state';
  finish();
  await reading;
  assert.equal(context.operationExecutionFlow.value, clearedFlow);
  assert.equal(context.homeOperatingScheduleError.value, 'new homepage state');
  assert.equal(context.homeOperatingScheduleFlow.value, null);
});

test('my tasks uses its server-issued scope identity without requiring manager capabilities', async () => {
  const context = flowHarness({ scope: { hotel_id: 7, user_id: 3 }, list: [{ id: 41, hotel_id: 7 }] });
  context.operationExecutionViewMode.value = 'mine';
  assert.equal(await context.loadFlow(), true);
  assert.equal(context.operationExecutionFlow.value.list[0].id, 41);
  assert.equal(context.homeOperatingScheduleFlow.value, null, 'personal tasks must not replace the hotel-wide home schedule');
  const wrong = flowHarness({ scope: { hotel_id: 8, user_id: 3 }, list: [{ id: 41, hotel_id: 7 }] });
  wrong.operationExecutionViewMode.value = 'mine';
  wrong.homeOperatingScheduleError.value = 'original home status';
  assert.equal(await wrong.loadFlow(), false);
  assert.equal(wrong.operationExecutionFlow.value.list.length, 0);
  assert.equal(wrong.homeOperatingScheduleError.value, 'original home status');
});

test('wrong hotel or missing exact intent clears old rows and exposes readback failure', async () => {
  for (const data of [
    { capabilities: { hotel_id: 8 }, list: [{ id: 41, hotel_id: 8 }] },
    { capabilities: { hotel_id: 7 }, list: [{ id: 41, hotel_id: 8 }] },
    { capabilities: { hotel_id: 7 }, list: [{ id: 42, hotel_id: 7 }] },
  ]) {
    const context = flowHarness(data);
    assert.equal(await context.loadFlow({ focusIntentId: 41 }), false);
    assert.equal(context.operationExecutionFlow.value.list.length, 0);
    assert.equal(context.operationExecutionFlow.value.data_status, 'load_failed');
    assert.ok(context.operationError.value.actions);
    assert.equal(context.operationLoading.value.actions, false);
  }
});
